use redis::aio::ConnectionManager;
use redis::AsyncCommands;
use tokio::sync::Mutex;

pub type RedisPool = Option<Mutex<ConnectionManager>>;

pub const CACHE_PREFIX: &str = "cache:";

pub fn cache_key(prefix: &str, id: impl std::fmt::Display) -> String {
    format!("{}{}:{}", CACHE_PREFIX, prefix, id)
}

pub async fn init_redis(redis_url: &str) -> Result<RedisPool, String> {
    if redis_url.is_empty() {
        return Ok(None);
    }
    let client = redis::Client::open(redis_url).map_err(|e| format!("Invalid Redis URL: {}", e))?;
    let conn = client.get_connection_manager().await.map_err(|e| format!("Redis connection failed: {}", e))?;
    println!("Redis connected: {}", redis_url);
    Ok(Some(Mutex::new(conn)))
}

pub async fn cache_get(pool: &RedisPool, key: &str) -> Option<String> {
    let pool = pool.as_ref()?;
    let mut conn = pool.lock().await;
    conn.get(key).await.ok()
}

pub async fn cache_set(pool: &RedisPool, key: &str, value: &str, ttl_secs: u64) -> Result<(), String> {
    let p = pool.as_ref().ok_or("Redis not available")?;
    let mut conn = p.lock().await;
    let _: () = conn.set_ex(key, value, ttl_secs).await.map_err(|e| format!("Redis set failed: {}", e))?;
    Ok(())
}

pub async fn cache_del(pool: &RedisPool, key: &str) -> Result<(), String> {
    let p = pool.as_ref().ok_or("Redis not available")?;
    let mut conn = p.lock().await;
    let _: () = conn.del(key).await.map_err(|e| format!("Redis del failed: {}", e))?;
    Ok(())
}
