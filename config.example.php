<?php
// config.example.php - 数据库配置文件模板
// 复制为 config.php 并填入实际值

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');

define('SITE_URL', 'https://your-domain.com/');
define('SESSION_NAME', 'streamer_forum_session');

define('MAX_FILE_SIZE', 5242880); // 5MB
define('UPLOAD_DIR', 'uploads/');
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip', 'rar', '7z']);

define('ONLINE_TIMEOUT', 300); // 5分钟在线超时（秒）
define('SIGNIN_POINTS', 10); // 签到基础积分
define('SIGNIN_BONUS_DAYS', 7); // 连续签到奖励天数
define('SIGNIN_BONUS_POINTS', 20); // 连续签到奖励积分

error_reporting(E_ALL);
ini_set('display_errors', 0);
