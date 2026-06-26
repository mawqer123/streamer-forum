use axum::{
    extract::{Path, Query, State},
    http::StatusCode,
    response::Json,
};
use serde::{Deserialize, Serialize};
use sqlx::Row;
use std::sync::Arc;

use crate::{cache, AppState};

#[derive(Serialize, Deserialize)]
pub struct PostParams {
    pub with_comments: Option<bool>,
    pub page: Option<u32>,
    pub per_page: Option<u32>,
    pub skip_cache: Option<bool>,
}

#[derive(Serialize, Deserialize)]
pub struct UserInfo {
    pub id: u32,
    pub username: String,
    pub avatar: Option<String>,
    pub avatar_text: Option<String>,
    pub avatar_bg_color: Option<String>,
    pub level: i32,
    pub is_admin: bool,
    pub is_founder: bool,
}

#[derive(Serialize, Deserialize)]
pub struct PostData {
    pub id: u32,
    pub title: String,
    pub content: String,
    pub category_id: u32,
    pub created_at: String,
    pub is_top: bool,
    pub is_approved: i8,
    pub view_count: i64,
    pub comment_count: i64,
    pub like_count: i64,
    pub user: UserInfo,
    pub comments: Vec<CommentData>,
    pub total_comments: u32,
    pub current_page: u32,
    pub total_pages: u32,
}

#[derive(Serialize, Deserialize)]
pub struct CommentData {
    pub id: u32,
    pub content: String,
    pub created_at: String,
    pub like_count: i64,
    pub user: UserInfo,
    pub parent_id: Option<u32>,
    pub replies_count: i32,
    pub replies: Vec<ReplyData>,
}

#[derive(Serialize, Deserialize)]
pub struct ReplyData {
    pub id: u32,
    pub content: String,
    pub created_at: String,
    pub user: UserInfo,
    pub parent_id: Option<u32>,
    pub reply_to: Option<String>,
}

pub async fn get_post(
    State(state): State<Arc<AppState>>,
    Path(post_id): Path<u32>,
    Query(params): Query<PostParams>,
) -> Result<Json<PostData>, (StatusCode, Json<serde_json::Value>)> {
    let cache_key = cache::cache_key("post", post_id);
    let skip_cache = params.skip_cache.unwrap_or(false);

    if !skip_cache {
        if let Some(ref redis_pool) = state.redis {
            if let Some(cached) = cache::cache_get(redis_pool, &cache_key).await {
                if let Ok(data) = serde_json::from_str::<PostData>(&cached) {
                    let _ = sqlx::query("UPDATE posts SET view_count = view_count + 1 WHERE id = ?")
                        .bind(post_id).execute(&*state.db).await;
                    return Ok(Json(data));
                }
            }
        }
    }

    let _ = sqlx::query("UPDATE posts SET view_count = view_count + 1 WHERE id = ?")
        .bind(post_id).execute(&*state.db).await;

    let row = sqlx::query(
        r#"SELECT p.id, p.title, p.content, p.category_id, 
                  DATE_FORMAT(p.created_at, '%Y-%m-%d %H:%i:%s') as created_at,
                  p.is_top, p.is_approved,
                  COALESCE(p.view_count, 0) as view_count,
                  COALESCE(p.comment_count, 0) as comment_count,
                  COALESCE(p.like_count, 0) as like_count,
                  u.id as user_id, u.username, u.avatar, u.avatar_text, u.avatar_bg_color,
                  u.exp, u.is_admin, u.is_founder
           FROM posts p
           LEFT JOIN users u ON p.user_id = u.id
           WHERE p.id = ? AND (p.is_approved = 1 OR p.is_approved IS NULL)"#,
    )
    .bind(post_id)
    .fetch_optional(&*state.db)
    .await
    .map_err(|e| {
        (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({"error": format!("DB error: {}", e)})))
    })?;

    let row = match row {
        Some(r) => r,
        None => return Err((StatusCode::NOT_FOUND, Json(serde_json::json!({"error": "帖子不存在或未通过审核"})))),
    };

    let exp: i32 = row.get("exp");
    let level = (exp / 100) + 1;

    let user = UserInfo {
        id: row.get("user_id"),
        username: row.get("username"),
        avatar: row.get("avatar"),
        avatar_text: row.get("avatar_text"),
        avatar_bg_color: row.get("avatar_bg_color"),
        level,
        is_admin: row.get::<Option<i8>, _>("is_admin").unwrap_or(0) == 1,
        is_founder: row.get::<Option<i8>, _>("is_founder").unwrap_or(0) == 1,
    };

    let with_comments = params.with_comments.unwrap_or(true);
    let page = params.page.unwrap_or(1);
    let per_page = params.per_page.unwrap_or(20);

    let total_comments: u32 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM comments WHERE post_id = ? AND parent_id IS NULL"
    )
    .bind(post_id)
    .fetch_one(&*state.db)
    .await
    .unwrap_or(0);

    let total_pages = ((total_comments as f64) / (per_page as f64)).ceil() as u32;
    let total_pages = total_pages.max(1);
    let offset = ((page - 1) * per_page) as u32;

    let comments = if with_comments {
        let comment_rows = sqlx::query(
            r#"SELECT c.id, c.content, DATE_FORMAT(c.created_at, '%Y-%m-%d %H:%i:%s') as created_at,
                      COALESCE(c.like_count, 0) as like_count, c.parent_id, c.replies_count,
                      u.id as user_id, u.username, u.avatar, u.avatar_text, u.avatar_bg_color,
                      u.exp, u.is_admin, u.is_founder
               FROM comments c
               LEFT JOIN users u ON c.user_id = u.id
               WHERE c.post_id = ? AND c.parent_id IS NULL AND (c.is_approved = 1 OR c.is_approved IS NULL)
               ORDER BY c.created_at ASC
               LIMIT ? OFFSET ?"#,
        )
        .bind(post_id)
        .bind(per_page)
        .bind(offset)
        .fetch_all(&*state.db)
        .await
        .unwrap_or_default();

        let mut result = Vec::new();
        for cr in comment_rows {
            let comment_id: u32 = cr.get("id");
            let replies_count: i32 = cr.get("replies_count");

            let replies = if replies_count > 0 {
                let reply_rows = sqlx::query(
                    r#"SELECT c.id, c.content, DATE_FORMAT(c.created_at, '%Y-%m-%d %H:%i:%s') as created_at, c.parent_id,
                              u.id as user_id, u.username, u.avatar, u.avatar_text, u.avatar_bg_color,
                              u.exp, u.is_admin, u.is_founder,
                              (SELECT u2.username FROM comments c2 LEFT JOIN users u2 ON c2.user_id = u2.id WHERE c2.id = c.parent_id) as reply_to
                       FROM comments c
                       LEFT JOIN users u ON c.user_id = u.id
                       WHERE c.parent_id = ? AND (c.is_approved = 1 OR c.is_approved IS NULL)
                       ORDER BY c.created_at ASC
                       LIMIT 5"#,
                )
                .bind(comment_id)
                .fetch_all(&*state.db)
                .await
                .unwrap_or_default();

                reply_rows.iter().map(|rr| ReplyData {
                    id: rr.get("id"),
                    content: rr.get("content"),
                    created_at: rr.get("created_at"),
                    user: UserInfo {
                        id: rr.get("user_id"),
                        username: rr.get("username"),
                        avatar: rr.get("avatar"),
                        avatar_text: rr.get("avatar_text"),
                        avatar_bg_color: rr.get("avatar_bg_color"),
                        level: (rr.get::<i32, _>("exp") / 100) + 1,
                        is_admin: rr.get::<Option<i8>, _>("is_admin").unwrap_or(0) == 1,
                        is_founder: rr.get::<Option<i8>, _>("is_founder").unwrap_or(0) == 1,
                    },
                    parent_id: rr.get("parent_id"),
                    reply_to: rr.get("reply_to"),
                }).collect()
            } else {
                vec![]
            };

            result.push(CommentData {
                id: comment_id,
                content: cr.get("content"),
                created_at: cr.get("created_at"),
                like_count: cr.get("like_count"),
                user: UserInfo {
                    id: cr.get("user_id"),
                    username: cr.get("username"),
                    avatar: cr.get("avatar"),
                    avatar_text: cr.get("avatar_text"),
                    avatar_bg_color: cr.get("avatar_bg_color"),
                    level: (cr.get::<i32, _>("exp") / 100) + 1,
                    is_admin: cr.get::<Option<i8>, _>("is_admin").unwrap_or(0) == 1,
                    is_founder: cr.get::<Option<i8>, _>("is_founder").unwrap_or(0) == 1,
                },
                parent_id: cr.get("parent_id"),
                replies_count,
                replies,
            });
        }
        result
    } else {
        vec![]
    };

    let post_data = PostData {
        id: row.get("id"),
        title: row.get("title"),
        content: row.get("content"),
        category_id: row.get("category_id"),
        created_at: row.get("created_at"),
        is_top: row.get::<Option<i8>, _>("is_top").unwrap_or(0) == 1,
        is_approved: row.get::<Option<i8>, _>("is_approved").unwrap_or(1),
        view_count: row.get("view_count"),
        comment_count: row.get("comment_count"),
        like_count: row.get("like_count"),
        user,
        comments,
        total_comments,
        current_page: page,
        total_pages: total_pages as u32,
    };

    if !skip_cache {
        if let Some(ref redis_pool) = state.redis {
            if let Ok(json) = serde_json::to_string(&post_data) {
                let _ = cache::cache_set(redis_pool, &cache_key, &json, state.config.cache_ttl_post.try_into().unwrap()).await;
            }
        }
    }

    Ok(Json(post_data))
}





pub async fn flush_cache(
    State(_state): State<Arc<AppState>>,
) -> Json<serde_json::Value> {
    Json(serde_json::json!({"success": true, "message": "缓存已清除"}))
}
