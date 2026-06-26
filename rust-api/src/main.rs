mod config;
mod db;
mod cache;
mod upload;
mod post;
mod notify;
mod qq;

use std::sync::Arc;
use axum::{
    routing::{get, post as axum_post},
    Router,
};
use tower_http::cors::{CorsLayer, Any};

use config::AppConfig;
use db::DbPool;
use cache::RedisPool;

pub struct AppState {
    pub config: AppConfig,
    pub db: DbPool,
    pub redis: Option<RedisPool>,
}

#[tokio::main]
async fn main() {
    tracing_subscriber::fmt::init();

    let config = AppConfig::from_env();
    tracing::info!("Starting forum API server on {}", config.listen_addr);

    let pool = db::init_pool(&config).await;

    // Try Redis (optional - continue without it)
    let redis = match cache::init_redis(&config.redis_url).await {
        Ok(r) => {
            tracing::info!("Redis connected");
            Some(r)
        }
        Err(e) => {
            tracing::warn!("Redis not available (caching disabled): {}", e);
            None
        }
    };

    let state = Arc::new(AppState {
        config: config.clone(),
        db: pool,
        redis,
    });

    let cors = CorsLayer::new()
        .allow_origin(Any)
        .allow_methods(Any)
        .allow_headers(Any);

    let app = Router::new()
        // File upload
        .route("/api/upload", axum_post(upload::handle_upload))
        // Post API
        .route("/api/post/:id", get(post::get_post))
        // Notification API
        .route("/api/notify/unread", get(notify::get_unread_count))
        .route("/api/notify/list", get(notify::get_notifications))
        .route("/api/notify/mark-read", axum_post(notify::mark_as_read))
        .route("/api/notify/create", axum_post(notify::create_notification))
        // Cache control
        .route("/api/cache/flush", axum_post(post::flush_cache))
        // Health check
        .route("/api/qq/import", get(qq::import_qq))
        .route("/api/health", get(|| async { "OK" }))
        .layer(cors)
        .with_state(state);

    let listener = tokio::net::TcpListener::bind(&config.listen_addr)
        .await
        .expect("Failed to bind address");

    tracing::info!("Listening on {}", config.listen_addr);
    axum::serve(listener, app).await.unwrap();
}
