use axum::{
    extract::{Query, State},
    http::StatusCode,
    response::Json,
};
use serde::{Deserialize, Serialize};
use std::io::Read;
use std::sync::Arc;

use crate::AppState;

#[derive(Deserialize)]
pub struct QqImportParams {
    pub qq: String,
    pub user_id: u32,
    /// 是否同时更新用户名（默认 false，仅更新头像）
    pub update_name: Option<bool>,
}

#[derive(Serialize)]
pub struct QqImportResponse {
    pub success: bool,
    pub message: String,
    pub qq_name: Option<String>,
    pub avatar_url: Option<String>,
}

/// ffapi.cn QQ info API response
#[derive(Deserialize)]
struct FfapiResponse {
    code: i32,
    msg: String,
    #[serde(default)]
    qq: Option<serde_json::Value>,
    name: Option<String>,
    avatar: Option<String>,
}

pub async fn import_qq(
    State(state): State<Arc<AppState>>,
    Query(params): Query<QqImportParams>,
) -> Result<Json<QqImportResponse>, (StatusCode, Json<serde_json::Value>)> {
    let qq = params.qq.trim().to_string();
    if qq.is_empty() || !qq.chars().all(|c| c.is_ascii_digit()) || qq.len() < 5 || qq.len() > 11 {
        return Err((StatusCode::BAD_REQUEST, Json(serde_json::json!({"error": "QQ号格式无效（需5-11位数字）"}))));
    }

    let update_name = params.update_name.unwrap_or(false);

    // 1. Call ffapi.cn to get QQ info
    let api_url = format!("http://ffapi.cn/int/v1/qqname?qq={}", qq);
    let qq_clone = qq.clone();

    let ffapi_result = tokio::task::spawn_blocking(move || {
        let resp = ureq::get(&api_url)
            .timeout(std::time::Duration::from_secs(8))
            .call()?;

        let mut body = String::new();
        resp.into_reader().read_to_string(&mut body)?;
        Ok::<_, Box<dyn std::error::Error + Send + Sync>>(body)
    }).await.map_err(|e| {
        (StatusCode::GATEWAY_TIMEOUT, Json(serde_json::json!({"error": format!("请求超时: {}", e)})))
    })?;

    let body = ffapi_result.map_err(|e| {
        (StatusCode::BAD_GATEWAY, Json(serde_json::json!({"error": format!("QQ信息API请求失败: {}", e)})))
    })?;

    let ffapi: FfapiResponse = serde_json::from_str(&body).map_err(|e| {
        (StatusCode::BAD_GATEWAY, Json(serde_json::json!({"error": format!("解析QQ信息失败: {}", e), "raw": &body[..body.len().min(200)]})))
    })?;

    if ffapi.code != 200 {
        return Err((StatusCode::BAD_GATEWAY, Json(serde_json::json!({"error": format!("QQ API返回错误: {} - {}", ffapi.code, ffapi.msg)}))));
    }

    let qq_name = ffapi.name.clone().unwrap_or_default();
    let qq_avatar_url = ffapi.avatar.clone().unwrap_or_default();

    if qq_avatar_url.is_empty() {
        return Err((StatusCode::BAD_GATEWAY, Json(serde_json::json!({"error": "QQ API未返回头像地址"}))));
    }

    // 2. Download the actual avatar image
    let avatar_dl = qq_avatar_url.clone();
    let image_result = tokio::task::spawn_blocking(move || {
        let resp = ureq::get(&avatar_dl)
            .timeout(std::time::Duration::from_secs(10))
            .call()?;

        let mut bytes: Vec<u8> = Vec::new();
        resp.into_reader().read_to_end(&mut bytes)?;
        Ok::<_, Box<dyn std::error::Error + Send + Sync>>(bytes)
    }).await.map_err(|e| {
        (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": format!("头像下载超时: {}", e)})))
    })?;

    let image_bytes = image_result.map_err(|e| {
        (StatusCode::BAD_GATEWAY, Json(serde_json::json!({"error": format!("下载头像失败: {}", e)})))
    })?;

    if image_bytes.len() < 100 {
        return Err((StatusCode::BAD_GATEWAY, Json(serde_json::json!({"error": "下载的头像为空或太小"}))));
    }

    // Check if it's a real image
    let is_jpeg = image_bytes.len() > 2 && image_bytes[0] == 0xFF && image_bytes[1] == 0xD8;
    let is_png = image_bytes.len() > 8 &&
        image_bytes[0] == 0x89 && image_bytes[1] == 0x50 && image_bytes[2] == 0x4E &&
        image_bytes[3] == 0x47;
    let is_gif = image_bytes.len() > 3 && image_bytes[0] == 0x47 && image_bytes[1] == 0x49 && image_bytes[2] == 0x46;

    let ext = if is_jpeg { "jpg" } else if is_png { "png" } else if is_gif { "gif" } else {
        // If we can't determine, save as jpg (most QQ avatars are jpg)
        "jpg"
    };

    // 3. Save avatar to disk
    let timestamp = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs();

    let filename = format!("qq_{}_{}_{}.{}", qq, params.user_id, timestamp, ext);
    let upload_dir = state.config.upload_dir.trim_end_matches('/').to_string();
    let upload_path = format!("{}/avatars/{}", upload_dir, filename);
    let relative_path = format!("uploads/avatars/{}", filename);

    if let Err(e) = std::fs::write(&upload_path, &image_bytes) {
        return Err((StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": format!("保存头像失败: {}", e)}))));
    }

    // 4. Update database
    if update_name && !qq_name.is_empty() {
        // Update both avatar AND username
        let mut tx = state.db.begin().await.map_err(|e| {
            (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": format!("数据库事务失败: {}", e)})))
        })?;

        sqlx::query("UPDATE users SET avatar = ?, avatar_text = '', avatar_bg_color = NULL, avatar_pending = 0 WHERE id = ?")
            .bind(&relative_path)
            .bind(params.user_id)
            .execute(&mut *tx)
            .await
            .map_err(|_| {
                (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": "数据库更新失败"})))
            })?;

        // Truncate QQ name to fit username column (varchar 50)
        let truncated_name: String = qq_name.chars().take(50).collect();
        sqlx::query("UPDATE users SET username = ? WHERE id = ?")
            .bind(&truncated_name)
            .bind(params.user_id)
            .execute(&mut *tx)
            .await
            .map_err(|_| {
                (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": "用户名更新失败"})))
            })?;

        tx.commit().await.map_err(|e| {
            (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": format!("事务提交失败: {}", e)})))
        })?;
    } else {
        // Only update avatar
        let result = sqlx::query(
            "UPDATE users SET avatar = ?, avatar_text = '', avatar_bg_color = NULL, avatar_pending = 0 WHERE id = ?"
        )
        .bind(&relative_path)
        .bind(params.user_id)
        .execute(&*state.db)
        .await
        .map_err(|e| {
            (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": format!("数据库更新失败: {}", e)})))
        })?;

        if result.rows_affected() == 0 {
            return Err((StatusCode::NOT_FOUND, Json(serde_json::json!({"error": "用户不存在"}))));
        }
    }

    let msg = if update_name && !qq_name.is_empty() {
        format!("来自QQ: {} (头像+昵称已更新)", qq_name)
    } else {
        format!("QQ头像已更新 ({}KB)", image_bytes.len() / 1024)
    };

    Ok(Json(QqImportResponse {
        success: true,
        message: msg,
        qq_name: if qq_name.is_empty() { None } else { Some(qq_name) },
        avatar_url: Some(relative_path),
    }))
}
