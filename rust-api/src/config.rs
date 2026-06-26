use std::env;

#[derive(Clone)]
pub struct AppConfig {
    pub db_dsn: String,
    pub upload_dir: String,
    pub site_url: String,
    pub max_file_size: u64,
    pub listen_addr: String,
    pub redis_url: String,
    pub cache_ttl_post: usize,
    pub cache_ttl_notify: usize,
}

impl AppConfig {
    pub fn from_env() -> Self {
        let db_host = env::var("DB_HOST").unwrap_or_else(|_| "127.0.0.1".into());
        let db_name = env::var("DB_NAME").unwrap_or_else(|_| "zbgame_hyper99_s".into());
        let db_user = env::var("DB_USER").unwrap_or_else(|_| "zbgame_hyper99_s".into());
        let db_pass = env::var("DB_PASS").unwrap_or_else(|_| "YC6j5k4ccKsanMNS".into());

        // URL encode special chars in password
        let encoded_pass: String = db_pass.chars().map(|c| match c {
            ':' | '/' | '?' | '#' | '[' | ']' | '@' | '!' | '$' | '&' | '\'' | '(' | ')' | '*' | '+' | ',' | ';' | '=' | '%' | ' ' => {
                format!("%{:02X}", c as u8)
            }
            _ => c.to_string()
        }).collect();

        let dsn = format!(
            "mysql://{}:{}@{}/{}?charset=utf8mb4",
            db_user, encoded_pass, db_host, db_name
        );

        Self {
            db_dsn: dsn,
            upload_dir: env::var("UPLOAD_DIR")
                .unwrap_or_else(|_| "/www/wwwroot/7890liuliu/uploads/".into()),
            site_url: env::var("SITE_URL").unwrap_or_else(|_| "https://zbgame.hyperspark.cn".into()),
            max_file_size: env::var("MAX_FILE_SIZE")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(5 * 1024 * 1024),
            listen_addr: env::var("LISTEN_ADDR").unwrap_or_else(|_| "127.0.0.1:3001".into()),
            redis_url: env::var("REDIS_URL").unwrap_or_else(|_| "redis://127.0.0.1:6379/0".into()),
            cache_ttl_post: env::var("CACHE_TTL_POST")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(300),
            cache_ttl_notify: env::var("CACHE_TTL_NOTIFY")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(60),
        }
    }
}
