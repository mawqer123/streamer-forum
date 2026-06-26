use axum::{
    extract::{Multipart, State},
    http::StatusCode,
    response::Json,
};
use serde::Serialize;
use std::sync::Arc;
use uuid::Uuid;

use crate::AppState;

#[derive(Serialize)]
pub struct UploadResponse {
    pub success: bool,
    pub url: Option<String>,
    pub message: String,
}

fn is_valid_image_type(mime: &str) -> bool {
    matches!(mime, "image/jpeg" | "image/jpg" | "image/png" | "image/gif" | "image/webp")
}

fn is_valid_image_ext(ext: &str) -> bool {
    ext == "jpg" || ext == "jpeg" || ext == "png" || ext == "gif" || ext == "webp"
}

pub async fn handle_upload(
    State(state): State<Arc<AppState>>,
    mut multipart: Multipart,
) -> Result<Json<UploadResponse>, (StatusCode, Json<UploadResponse>)> {
    while let Some(field) = multipart
        .next_field()
        .await
        .map_err(|e| {
            (
                StatusCode::BAD_REQUEST,
                Json(UploadResponse {
                    success: false,
                    url: None,
                    message: format!("读取上传数据失败: {}", e),
                }),
            )
        })?
    {
        let field_name = field.name().unwrap_or("").to_string();
        let file_name = field.file_name().unwrap_or("unknown").to_string();

        // Only process 'file' or 'image' fields
        if field_name != "file" && field_name != "image" {
            continue;
        }

        let content_type = field.content_type().unwrap_or("").to_string();
        if !is_valid_image_type(&content_type) {
            return Err((
                StatusCode::BAD_REQUEST,
                Json(UploadResponse {
                    success: false,
                    url: None,
                    message: "不支持的文件类型，仅允许 jpg/png/gif/webp".into(),
                }),
            ));
        }

        let data = field.bytes().await.map_err(|e| {
            (
                StatusCode::BAD_REQUEST,
                Json(UploadResponse {
                    success: false,
                    url: None,
                    message: format!("读取文件数据失败: {}", e),
                }),
            )
        })?;

        if data.is_empty() {
            return Err((
                StatusCode::BAD_REQUEST,
                Json(UploadResponse {
                    success: false,
                    url: None,
                    message: "文件为空".into(),
                }),
            ));
        }

        if data.len() as u64 > state.config.max_file_size {
            return Err((
                StatusCode::BAD_REQUEST,
                Json(UploadResponse {
                    success: false,
                    url: None,
                    message: format!("文件过大，最大允许 {}MB", state.config.max_file_size / 1024 / 1024),
                }),
            ));
        }

        // Determine extension from content type
        let ext = match content_type.as_str() {
            "image/jpeg" | "image/jpg" => "jpg".to_string(),
            "image/png" => "png".to_string(),
            "image/gif" => "gif".to_string(),
            "image/webp" => "webp".to_string(),
            _ => {
                // Try from file name
                let parts: Vec<&str> = file_name.split('.').collect();
                if parts.len() > 1 {
                    let e = parts.last().unwrap().to_lowercase();
                    if !is_valid_image_ext(&e) {
                        return Err((
                            StatusCode::BAD_REQUEST,
                            Json(UploadResponse {
                                success: false,
                                url: None,
                                message: "不支持的文件扩展名".into(),
                            }),
                        ));
                    }
                    e
                } else {
                    "jpg".to_string()
                }
            }
        };

        // Generate unique filename
        let unique_name = format!("{}.{}", Uuid::new_v4(), ext);
        let save_path = format!("{}{}", state.config.upload_dir, unique_name);

        // Save file
        tokio::fs::write(&save_path, &data).await.map_err(|e| {
            (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(UploadResponse {
                    success: false,
                    url: None,
                    message: format!("保存文件失败: {}", e),
                }),
            )
        })?;

        // Check if we need to create crop thumbnails (for avatars)
        let url_path = format!("/uploads/{}", unique_name);

        return Ok(Json(UploadResponse {
            success: true,
            url: Some(url_path),
            message: "上传成功".into(),
        }));
    }

    Err((
        StatusCode::BAD_REQUEST,
        Json(UploadResponse {
            success: false,
            url: None,
            message: "未找到上传文件".into(),
        }),
    ))
}
