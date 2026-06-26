use sqlx::mysql::MySqlPoolOptions;
use sqlx::MySqlPool;
use std::sync::Arc;
use crate::config::AppConfig;

pub type DbPool = Arc<MySqlPool>;

pub async fn init_pool(config: &AppConfig) -> DbPool {
    let pool = MySqlPoolOptions::new()
        .max_connections(10)
        .connect(&config.db_dsn)
        .await
        .expect("Failed to connect to MySQL");
    tracing::info!("Database connected");
    Arc::new(pool)
}
