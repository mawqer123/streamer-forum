use axum::{extract::{Query, State}, http::StatusCode, response::Json};
use serde::{Deserialize, Serialize};
use sqlx::Row;
use std::sync::Arc;

use crate::{cache, AppState};

#[derive(Serialize)]
pub struct Notification {
    pub id: u32,
    pub notification_type: String,
    pub is_read: bool,
    pub created_at: String,
    pub actor_id: Option<u32>,
    pub target_id: Option<u32>,
    pub data: Option<String>,
    pub actor_username: Option<String>,
    pub actor_avatar: Option<String>,
}

#[derive(Serialize)]
pub struct UnreadCountResponse {
    pub success: bool,
    pub count: i64,
}

#[derive(Serialize)]
pub struct NotificationListResponse {
    pub success: bool,
    pub notifications: Vec<Notification>,
    pub unread_count: i64,
}

#[derive(Serialize)]
pub struct MarkReadResponse {
    pub success: bool,
    pub message: String,
}

#[derive(Deserialize)]
pub struct MarkReadRequest {
    pub notification_id: u32,
}

#[derive(Deserialize)]
pub struct UnreadCountQuery {
    pub user_id: i64,
}

#[derive(Deserialize)]
pub struct NotificationListQuery {
    pub user_id: i64,
    pub page: Option<u32>,
    pub per_page: Option<u32>,
}

#[derive(Deserialize)]
pub struct CreateNotifyRequest {
    pub user_id: i64,
    #[serde(rename = "type")]
    pub notif_type: String,
    pub actor_id: Option<u32>,
    pub target_id: Option<u32>,
    pub data: Option<String>,
}

pub async fn get_unread_count(
    State(state): State<Arc<AppState>>,
    Query(params): Query<UnreadCountQuery>,
) -> Result<Json<UnreadCountResponse>, (StatusCode, Json<serde_json::Value>)> {
    let cache_key = cache::cache_key("unread", params.user_id);

    if let Some(ref redis_pool) = state.redis {
        if let Some(cached) = cache::cache_get(redis_pool, &cache_key).await {
            if let Ok(count) = cached.parse::<i64>() {
                return Ok(Json(UnreadCountResponse { success: true, count }));
            }
        }
    }

    let count: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
    )
    .bind(params.user_id)
    .fetch_one(&*state.db)
    .await
    .map_err(|e| {
        (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": format!("DB error: {}", e)})))
    })?;

    if let Some(ref redis_pool) = state.redis {
        let _ = cache::cache_set(redis_pool, &cache_key, &count.to_string(), state.config.cache_ttl_notify.try_into().unwrap()).await;
    }

    Ok(Json(UnreadCountResponse { success: true, count }))
}

pub async fn get_notifications(
    State(state): State<Arc<AppState>>,
    Query(params): Query<NotificationListQuery>,
) -> Result<Json<NotificationListResponse>, (StatusCode, Json<serde_json::Value>)> {
    let page = params.page.unwrap_or(1);
    let per_page = params.per_page.unwrap_or(20);
    let offset = ((page - 1) * per_page) as i64;

    let rows = sqlx::query(
        r#"SELECT n.id, n.type, n.is_read, DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i:%s') as created_at_str, n.actor_id, n.target_id, n.data,
                  u.username as actor_username, u.avatar as actor_avatar
           FROM notifications n
           LEFT JOIN users u ON n.actor_id = u.id
           WHERE n.user_id = ?
           ORDER BY n.created_at DESC
           LIMIT ? OFFSET ?"#,
    )
    .bind(params.user_id)
    .bind(per_page)
    .bind(offset)
    .fetch_all(&*state.db)
    .await
    .map_err(|e| {
        (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": format!("DB error: {}", e)})))
    })?;

    let unread_count: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
    )
    .bind(params.user_id)
    .fetch_one(&*state.db)
    .await
    .unwrap_or(0);

    let notifications: Vec<Notification> = rows.iter().map(|r| {
        let created_at: String = r.get("created_at_str");
        Notification {
            id: r.get("id"),
            notification_type: r.get("type"),
            is_read: r.get::<Option<i32>, _>("is_read").unwrap_or(0) == 1,
            created_at: created_at,
            actor_id: r.get("actor_id"),
            target_id: r.get("target_id"),
            data: r.get("data"),
            actor_username: r.get("actor_username"),
            actor_avatar: r.get("actor_avatar"),
        }
    }).collect();

    Ok(Json(NotificationListResponse { success: true, notifications, unread_count }))
}

pub async fn mark_as_read(
    State(state): State<Arc<AppState>>,
    Json(body): Json<MarkReadRequest>,
) -> Result<Json<MarkReadResponse>, (StatusCode, Json<serde_json::Value>)> {
    let result = sqlx::query("UPDATE notifications SET is_read = 1 WHERE id = ?")
        .bind(body.notification_id)
        .execute(&*state.db)
        .await
        .map_err(|e| {
            (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": format!("DB error: {}", e)})))
        })?;

    if result.rows_affected() > 0 {
        let user_id: Option<u32> = sqlx::query_scalar("SELECT user_id FROM notifications WHERE id = ?")
            .bind(body.notification_id)
            .fetch_optional(&*state.db)
            .await
            .unwrap_or(None);
        if let Some(uid) = user_id {
            if let Some(ref redis_pool) = state.redis {
                let _ = cache::cache_del(redis_pool, &cache::cache_key("unread", uid)).await;
            }
        }
    }

    Ok(Json(MarkReadResponse { success: true, message: "已标记为已读".into() }))
}

pub async fn create_notification(
    State(state): State<Arc<AppState>>,
    Json(body): Json<CreateNotifyRequest>,
) -> Result<Json<serde_json::Value>, (StatusCode, Json<serde_json::Value>)> {
    if body.user_id == 0 || body.notif_type.is_empty() {
        return Err((StatusCode::BAD_REQUEST, Json(serde_json::json!({"error": "user_id and type are required"}))));
    }

    sqlx::query(
        r#"INSERT INTO notifications (user_id, type, actor_id, target_id, data, is_read, created_at)
           VALUES (?, ?, ?, ?, ?, 0, NOW())"#,
    )
    .bind(body.user_id)
    .bind(&body.notif_type)
    .bind(body.actor_id)
    .bind(body.target_id)
    .bind(body.data)
    .execute(&*state.db)
    .await
    .map_err(|e| {
        (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": format!("DB error: {}", e)})))
    })?;

    if let Some(ref redis_pool) = state.redis {
        let _ = cache::cache_del(redis_pool, &cache::cache_key("unread", body.user_id)).await;
    }

    Ok(Json(serde_json::json!({"success": true, "message": "通知已创建"})))
}
