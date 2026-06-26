<?php
// functions.php - 通用函数文件（已修复上传目录路径为绝对路径，优化Session管理，支持评论置顶，支持打赏积分，支持伪静态URL，彻底修复XSS注入，支持Redis会话存储）

// 确保无输出缓冲问题（避免AJAX响应被干扰）
if (ob_get_level() === 0) {
    ob_start();
}

// ---------- 检查Redis扩展并配置Session存储 ----------
$redisSessionEnabled = false;
if (extension_loaded('redis')) {
    // 从配置文件读取Redis配置（需要在config.php中定义以下常量，可选）
    if (defined('REDIS_HOST') && defined('REDIS_PORT')) {
        $redisHost = REDIS_HOST;
        $redisPort = REDIS_PORT;
        $redisPrefix = defined('REDIS_SESSION_PREFIX') ? REDIS_SESSION_PREFIX : 'session:';
    } else {
        // 默认本地Redis配置
        $redisHost = '127.0.0.1';
        $redisPort = 6379;
        $redisPrefix = 'session:';
    }
    try {
        $testRedis = new Redis();
        if (@$testRedis->connect($redisHost, $redisPort)) {
            $redisSessionEnabled = true;
            // 设置PHP session存储为Redis
            ini_set('session.save_handler', 'redis');
            ini_set('session.save_path', "tcp://{$redisHost}:{$redisPort}?prefix={$redisPrefix}");
        }
        unset($testRedis);
    } catch (Exception $e) {
        // Redis不可用，回退到文件存储
        $redisSessionEnabled = false;
    }
}

// 如果Redis不可用，使用原来的文件存储配置
if (!$redisSessionEnabled) {
    ini_set('session.cookie_lifetime', 31536000);
    ini_set('session.gc_maxlifetime', 31536000);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 1000);

    $sessionPath = __DIR__ . '/sessions';
    if (!file_exists($sessionPath)) {
        @mkdir($sessionPath, 0777, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
}

session_name('streamer_forum_session');
// 注意：此处不再自动调用 session_start()
// Session 仅在有登录 Cookie 或需要登录操作时按需启动

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    die('配置文件不存在！请先运行 <a href="install.php">install.php</a> 进行安装。');
}
require_once $configFile;

// ========== 后台管理文件随机路径（请务必修改为你的实际文件名，不带.php后缀） ==========
define('ADMIN_FILE', 'zbgame_admin_8f3d');  // 改为你重命名后的随机文件名

ensureSettingsTable();
ensureFavoritesTable();
ensureTipsTable(); // 新增：确保打赏记录表存在
ensureAvatarTextColumn(); // 新增：确保 avatar_text 列存在
ensurePrivateMessagesTable(); // 新增：确保私信表存在

// ========== 安全防护：确保聊天室数据目录不可直接访问 ==========
function ensureChatDataSecurity() {
    $dataDir = __DIR__ . '/data/';
    $chatDataDir = __DIR__ . '/data/chat/';
    
    // 确保 data 目录存在且包含 .htaccess
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    $htaccessFile = $dataDir . '.htaccess';
    if (!file_exists($htaccessFile)) {
        file_put_contents($htaccessFile, "Deny from all\n");
    } else {
        $content = file_get_contents($htaccessFile);
        if (strpos($content, 'Deny from all') === false && strpos($content, 'Require all denied') === false) {
            file_put_contents($htaccessFile, "Deny from all\n");
        }
    }
    
    // 确保 chat 子目录存在且包含 .htaccess
    if (!is_dir($chatDataDir)) {
        mkdir($chatDataDir, 0755, true);
    }
    $chatHtaccess = $chatDataDir . '.htaccess';
    if (!file_exists($chatHtaccess)) {
        file_put_contents($chatHtaccess, "Deny from all\n");
    } else {
        $content = file_get_contents($chatHtaccess);
        if (strpos($content, 'Deny from all') === false && strpos($content, 'Require all denied') === false) {
            file_put_contents($chatHtaccess, "Deny from all\n");
        }
    }
    
    // 确保 uploads/chat 目录可访问（用于图片），但添加防止目录遍历的 .htaccess
    $uploadChatDir = __DIR__ . '/uploads/chat/';
    if (!is_dir($uploadChatDir)) {
        mkdir($uploadChatDir, 0755, true);
    }
    $uploadHtaccess = $uploadChatDir . '.htaccess';
    if (!file_exists($uploadHtaccess)) {
        file_put_contents($uploadHtaccess, "Options -Indexes\nAllow from all\n");
    }
}

// 在每次请求时调用安全函数（仅当非CLI模式）
if (PHP_SAPI !== 'cli') {
    ensureChatDataSecurity();
}

function ensureSettingsTable() {
    try {
        $pdo = getDbConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `key` varchar(100) NOT NULL PRIMARY KEY,
            `value` text,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {}
}

function ensureFavoritesTable() {
    try {
        $pdo = getDbConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS `favorites` (
            `id` int(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` int(11) UNSIGNED NOT NULL,
            `post_id` int(11) UNSIGNED NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_favorite` (`user_id`, `post_id`),
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_post_id` (`post_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {}
}

/**
 * 确保打赏记录表存在
 */
function ensureTipsTable() {
    try {
        $pdo = getDbConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS `tips` (
            `id` int(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `from_user_id` int(11) UNSIGNED NOT NULL COMMENT '打赏者',
            `to_user_id` int(11) UNSIGNED NOT NULL COMMENT '被打赏者（帖子作者）',
            `post_id` int(11) UNSIGNED NOT NULL COMMENT '相关帖子',
            `amount` int(11) UNSIGNED NOT NULL COMMENT '打赏积分数量',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_from_user` (`from_user_id`),
            INDEX `idx_to_user` (`to_user_id`),
            INDEX `idx_post_id` (`post_id`),
            FOREIGN KEY (`from_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`to_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {}
}

/**
 * 确保私信表存在
 */
function ensurePrivateMessagesTable() {
    try {
        $pdo = getDbConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS `private_messages` (
            `id` int(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `from_user_id` int(11) UNSIGNED NOT NULL,
            `to_user_id` int(11) UNSIGNED NOT NULL,
            `content` text NOT NULL,
            `is_read` tinyint(1) DEFAULT 0,
            `created_at` datetime NOT NULL,
            INDEX `idx_conversation` (`from_user_id`, `to_user_id`),
            INDEX `idx_to_user` (`to_user_id`, `is_read`),
            FOREIGN KEY (`from_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`to_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {}
}

/**
 * 确保 users 表增加 avatar_text 列
 */
// ========== 维护模式相关函数 ==========
function isMaintenanceMode() {
    return getSetting('maintenance_mode', '0') === '1';
}

function setMaintenanceMode($enable) {
    return setSetting('maintenance_mode', $enable ? '1' : '0');
}

/**
 * 检查用户是否可绕过维护模式
 * 管理员、站长和标记了 bypass 的用户可以直接进入
 */
function canBypassMaintenance($currentUser) {
    if (empty($currentUser)) return false;
    if (!empty($currentUser['is_admin']) || !empty($currentUser['is_founder'])) return true;
    if (!empty($currentUser['maintenance_bypass'])) return true;
    return false;
}

/**
 * 检查并执行维护模式拦截
 * 在每页 getCurrentUser() 之后调用
 */
function checkMaintenanceMode($currentUser) {
    if (!isMaintenanceMode()) return;
    
    $script = basename($_SERVER['SCRIPT_NAME']);
    // 跳过管理后台、维护页本身、登录/登出操作、装饰器文件
    $skipPages = ['zbgame_admin_8f3d.php', 'maintenance.php', 'auth.php', 'post_actions.php', 'privacy.php', 'terms.php'];
    if (in_array($script, $skipPages)) return;
    
    if (canBypassMaintenance($currentUser)) return;
    
    // 重定向到维护页面
    header('Location: /maintenance.php');
    exit;
}
// ====================================

function ensureAvatarTextColumn() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_text'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar_text VARCHAR(100) NULL DEFAULT NULL COMMENT '文字头像内容'");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_bg_color'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar_bg_color VARCHAR(7) NULL DEFAULT NULL COMMENT '文字头像背景色'");
        }
    } catch (PDOException $e) {}
}

/**
 * 获取 API 后端类型
 * @return string 'php' 或 'rust'
 */
function getApiBackend() {
    return getSetting('api_backend', 'php');
}

/**
 * 调用 Rust API
 */
function callRustApi($method, $path, $data = null, $timeout = 5) {
    $url = 'http://127.0.0.1:3001' . $path;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($data) ? $data : json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
    }
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300 && $resp) {
        return json_decode($resp, true);
    }
    return null;
}

function getSetting($key, $default = null) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function setSetting($key, $value) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) 
                               ON DUPLICATE KEY UPDATE `value` = ?");
        return $stmt->execute([$key, $value, $value]);
    } catch (PDOException $e) {
        return false;
    }
}

// ========== 注册开关相关函数 ==========
/**
 * 检查注册功能是否开启
 * @return bool
 */
function isRegistrationEnabled() {
    return getSetting('registration_enabled', '1') === '1';
}

/**
 * 设置注册功能开关
 * @param bool $enable
 * @return bool
 */
function setRegistrationEnabled($enable) {
    return setSetting('registration_enabled', $enable ? '1' : '0');
}
// ====================================

function isAutoFollowEnabled() {
    return getSetting('auto_follow_founder', '0') === '1';
}

function trackOnlineUser() {
    try {
        $pdo = getDbConnection();
        $sessionId = session_id();
        $userId = $_SESSION['user_id'] ?? null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $now = date('Y-m-d H:i:s');
        
        $timeout = time() - ONLINE_TIMEOUT;
        $pdo->exec("DELETE FROM online_users WHERE last_activity < '" . date('Y-m-d H:i:s', $timeout) . "'");
        
        $stmt = $pdo->prepare("SELECT id FROM online_users WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE online_users SET user_id = ?, ip_address = ?, user_agent = ?, last_activity = ? WHERE session_id = ?");
            $stmt->execute([$userId, $ipAddress, $userAgent, $now, $sessionId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO online_users (user_id, session_id, ip_address, user_agent, last_activity) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $sessionId, $ipAddress, $userAgent, $now]);
        }
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function getOnlineUsersCount() {
    try {
        $pdo = getDbConnection();
        $timeout = time() - ONLINE_TIMEOUT;
        $pdo->exec("DELETE FROM online_users WHERE last_activity < '" . date('Y-m-d H:i:s', $timeout) . "'");
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM online_users");
        $total = $stmt->fetch()['total'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as members FROM online_users WHERE user_id IS NOT NULL");
        $members = $stmt->fetch()['members'];
        
        $guests = $total - $members;
        
        return [
            'total' => $total,
            'members' => $members,
            'guests' => $guests
        ];
    } catch (PDOException $e) {
        return ['total' => 0, 'members' => 0, 'guests' => 0];
    }
}

function getUserPoints($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT points FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return $result ? $result['points'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function updateUserPoints($userId, $points) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET points = points + ? WHERE id = ?");
        return $stmt->execute([$points, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function setUserPointsDirect($userId, $points) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET points = ? WHERE id = ?");
        return $stmt->execute([$points, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function setUserExpDirect($userId, $exp) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET exp = ? WHERE id = ?");
        return $stmt->execute([$exp, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function hasSignedInToday($userId) {
    try {
        $pdo = getDbConnection();
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT id FROM daily_signins WHERE user_id = ? AND signin_date = ? LIMIT 1");
        $stmt->execute([$userId, $today]);
        return $stmt->fetch() ? true : false;
    } catch (PDOException $e) {
        return false;
    }
}

function getContinuousSigninDays($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT continuous_days FROM daily_signins WHERE user_id = ? ORDER BY signin_date DESC LIMIT 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return $result ? $result['continuous_days'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function userSignIn($userId) {
    try {
        $pdo = getDbConnection();
        
        if (hasSignedInToday($userId)) {
            return [
                'success' => false,
                'message' => '今日已签到，请明天再来！'
            ];
        }
        
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        $stmt = $pdo->prepare("SELECT continuous_days FROM daily_signins WHERE user_id = ? AND signin_date = ?");
        $stmt->execute([$userId, $yesterday]);
        $yesterdaySignin = $stmt->fetch();
        
        $continuousDays = $yesterdaySignin ? $yesterdaySignin['continuous_days'] + 1 : 1;
        
        $basePoints = SIGNIN_POINTS;
        $bonusPoints = 0;
        
        if ($continuousDays >= SIGNIN_BONUS_DAYS) {
            $bonusPoints = SIGNIN_BONUS_POINTS;
        }
        
        $totalPoints = $basePoints + $bonusPoints;
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO daily_signins (user_id, signin_date, points_awarded, continuous_days) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $today, $totalPoints, $continuousDays]);
        
        $stmt = $pdo->prepare("UPDATE users SET points = points + ?, exp = exp + ? WHERE id = ?");
        $stmt->execute([$totalPoints, EXP_PER_SIGNIN, $userId]);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'points' => $totalPoints,
            'continuous_days' => $continuousDays,
            'base_points' => $basePoints,
            'bonus_points' => $bonusPoints,
            'message' => '签到成功！获得' . $totalPoints . '积分'
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'message' => '签到失败：' . $e->getMessage()
        ];
    }
}

// ===== 等级经验系统 =====
define('EXP_PER_COMMENT', 5);
define('EXP_PER_POST', 15);
define('EXP_PER_SIGNIN', 10);
define('MAX_DAILY_COMMENT_EXP', 10);
define('MAX_DAILY_POST_EXP', 10);
define('MAX_LEVEL', 100);

function getLevelExp($level) {
    if ($level <= 1) return 0;
    return floor(10 * pow($level - 1, 1.8));
}

function getUserLevel($exp) {
    for ($level = 1; $level <= MAX_LEVEL; $level++) {
        if ($exp < getLevelExp($level)) {
            return $level - 1;
        }
    }
    return MAX_LEVEL;
}

function getLevelName($level) {
    $names = getSetting('level_names', '');
    if (!empty($names)) {
        $names = json_decode($names, true);
        if (is_array($names) && isset($names[$level])) return $names[$level];
    }
    $defaultNames = [
        1 => '萌新', 2 => '新手', 3 => '学徒', 4 => '初级',
        5 => '进阶', 6 => '中级', 7 => '高级', 8 => '资深',
        9 => '骨干', 10 => '精英',
    ];
    if ($level <= 10) return $defaultNames[$level] ?? 'Lv.' . $level;
    if ($level <= 20) return '达人';
    if ($level <= 30) return '专家';
    if ($level <= 40) return '大师';
    if ($level <= 50) return '宗师';
    if ($level <= 60) return '传奇';
    if ($level <= 70) return '史诗';
    if ($level <= 80) return '传说';
    if ($level <= 90) return '神话';
    return '至高';
}

function getExpProgress($exp) {
    $level = getUserLevel($exp);
    $currentLevelExp = getLevelExp($level);
    $nextLevelExp = getLevelExp($level + 1);
    if ($level >= MAX_LEVEL) {
        $nextLevelExp = $currentLevelExp;
    }
    $needed = $nextLevelExp - $currentLevelExp;
    $have = $exp - $currentLevelExp;
    $progress = $needed > 0 ? min(100, floor($have / $needed * 100)) : 100;
    return [
        'level' => $level,
        'exp' => $exp,
        'current_level_exp' => $currentLevelExp,
        'next_level_exp' => $nextLevelExp,
        'needed' => $needed,
        'have' => $have,
        'progress' => $progress,
        'name' => getLevelName($level),
        'next_name' => getLevelName(min($level + 1, MAX_LEVEL))
    ];
}

function addUserExp($userId, $amount, $type) {
    try {
        $pdo = getDbConnection();
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT last_daily_reset, daily_comments, daily_posts FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) return 0;
        $dailyComments = $user['daily_comments'];
        $dailyPosts = $user['daily_posts'];
        if ($user['last_daily_reset'] !== $today) {
            $dailyComments = 0;
            $dailyPosts = 0;
            $stmt = $pdo->prepare("UPDATE users SET daily_comments = 0, daily_posts = 0, last_daily_reset = ? WHERE id = ?");
            $stmt->execute([$today, $userId]);
        }
        if ($type === 'comment' && $dailyComments >= MAX_DAILY_COMMENT_EXP) return 0;
        if ($type === 'post' && $dailyPosts >= MAX_DAILY_POST_EXP) return 0;
        if ($type === 'comment') {
            $stmt = $pdo->prepare("UPDATE users SET exp = exp + ?, daily_comments = daily_comments + 1 WHERE id = ?");
        } elseif ($type === 'post') {
            $stmt = $pdo->prepare("UPDATE users SET exp = exp + ?, daily_posts = daily_posts + 1 WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE users SET exp = exp + ? WHERE id = ?");
        }
        $stmt->execute([$amount, $userId]);
        return $amount;
    } catch (PDOException $e) {
        return 0;
    }
}

function getLevelBadgeColors() {
    $colors = getSetting('level_badge_colors', '');
    if (!empty($colors)) {
        $colors = json_decode($colors, true);
        if (is_array($colors) && count($colors) === 10) return $colors;
    }
    return [
        1 => '#6b7280', 2 => '#22c55e', 3 => '#3b82f6', 4 => '#8b5cf6',
        5 => '#f97316', 6 => '#ef4444', 7 => '#ec4899', 8 => '#eab308',
        9 => '#06b6d4', 10 => '#a855f7'
    ];
}

function getLevelBadgeTier($level) {
    return min(10, max(1, ceil($level / 10)));
}

function getLevelBadgeStyle($exp) {
    $info = getExpProgress($exp);
    $colors = getLevelBadgeColors();
    $tier = getLevelBadgeTier($info['level']);
    $color = $colors[$tier] ?? '#6b7280';
    return 'background:' . $color . ';color:#fff;';
}

function getLevelBadgeHtml($exp) {
    $info = getExpProgress($exp);
    $style = getLevelBadgeStyle($exp);
    return '<span class="level-badge" style="' . $style . '">Lv.' . $info['level'] . ' ' . escape($info['name']) . '</span>';
}

function getLevelBadgeSmHtml($exp) {
    $info = getExpProgress($exp);
    $style = getLevelBadgeStyle($exp);
    return '<span class="level-badge-sm" style="' . $style . '">Lv.' . $info['level'] . ' ' . escape($info['name']) . '</span>';
}

function getDefaultLevelNames() {
    $names = [];
    for ($i = 1; $i <= 100; $i++) {
        $names[$i] = getLevelName($i);
    }
    return $names;
}

// ===== 等级经验系统结束 =====

function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // 设置字符集，代替弃用的 MYSQL_ATTR_INIT_COMMAND
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            // 设置 MySQL 时区为北京时间 (UTC+8)
            $pdo->exec("SET time_zone = '+8:00'");
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        }
    }
    
    return $pdo;
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * 按需启动 Session（仅当存在有效的登录 Cookie 时）
 * 如果 Session 已启动或已有登录信息，则返回 true；否则返回 false
 */
function start_session_if_has_cookie() {
    static $started = false;
    if ($started) return true;
    
    // 如果当前已经有活跃的 session，直接返回 true
    if (session_status() === PHP_SESSION_ACTIVE) {
        $started = true;
        return true;
    }
    
    // 检查客户端是否带有 session cookie
    if (isset($_COOKIE[session_name()])) {
        session_start();
        $started = true;
        // 如果启动了 session 但没有登录信息，立即销毁并清除 cookie
        if (empty($_SESSION['user_id'])) {
            session_destroy();
            setcookie(session_name(), '', time() - 3600, '/');
            $started = false;
            return false;
        }
        return true;
    }
    return false;
}

/**
 * 强制启动 Session（用于登录/注册成功后）
 * 注意：调用前不应有输出
 */
function start_session_force() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    return session_start();
}

/**
 * 清理过期的 Session 文件（登录用户一年，未登录用户一小时）
 * 此函数在每次请求中随机调用（1% 概率），且同一请求最多执行一次
 */
function cleanup_expired_sessions() {
    static $cleaned = false;
    if ($cleaned) return;
    // 仅在文件存储模式下执行清理（Redis 模式由 Redis 自身管理过期）
    global $redisSessionEnabled;
    if ($redisSessionEnabled) return;
    
    // 1% 概率执行清理，避免每次请求都扫描目录
    if (mt_rand(1, 100) > 1) return;
    
    $sessionSavePath = session_save_path();
    if (empty($sessionSavePath) || !is_dir($sessionSavePath)) {
        return;
    }
    
    $cleaned = true;
    $now = time();
    $loginExpire = 365 * 24 * 3600;   // 一年
    $guestExpire = 1 * 3600;          // 一小时
    
    $files = glob($sessionSavePath . '/sess_*');
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $mtime = filemtime($file);
        if ($mtime === false) continue;
        
        // 读取文件内容，检查是否包含 user_id
        // 用 @ 压制因权限问题（如 root 创建的文件）产生的警告
        if (!is_readable($file)) continue;
        $content = @file_get_contents($file);
        if ($content === false) continue;
        
        // 判断是否登录用户（session 中存储了 user_id|i:数字）
        $isLoggedIn = preg_match('/user_id\|i:([1-9][0-9]*)/', $content);
        
        if ($isLoggedIn) {
            // 登录用户：超过一年未活动则删除
            if ($now - $mtime > $loginExpire) {
                @unlink($file);
            }
        } else {
            // 未登录用户：超过一小时未活动则删除
            if ($now - $mtime > $guestExpire) {
                @unlink($file);
            }
        }
    }
}

function isLoggedIn() {
    if (!start_session_if_has_cookie()) {
        return false;
    }
    cleanup_expired_sessions(); // 在已登录 session 启动后尝试清理
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    if (!isLoggedIn()) {
        return false;
    }
    
    static $isAdmin = null;
    
    if ($isAdmin === null) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $result = $stmt->fetch();
            $isAdmin = ($result && $result['is_admin'] == 1);
        } catch (PDOException $e) {
            $isAdmin = false;
        }
    }
    
    return $isAdmin;
}

function isFounder($userId = null) {
    if ($userId === null) {
        if (!isLoggedIn()) return false;
        $userId = $_SESSION['user_id'];
    }
    
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT is_founder FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return $result && $result['is_founder'] == 1;
    } catch (PDOException $e) {
        return false;
    }
}

function isCurrentUserBanned() {
    if (!isLoggedIn()) return false;
    $user = getCurrentUser();
    return $user && $user['is_banned'] == 1;
}

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    static $user = null;
    
    if ($user === null) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT id, username, email, public_uid, avatar, avatar_text, avatar_bg_color, avatar_pending, profile_background, is_admin, is_founder, points, exp, theme, theme_settings, chat_username, created_at, last_login, is_banned, maintenance_bypass, github_id, github_username, github_avatar, gitee_id, gitee_username, gitee_avatar, background_pending, last_username_change FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                // 用户已被删除，清理 session
                session_destroy();
                setcookie(session_name(), '', time() - 3600, '/');
                return null;
            }
            
            if (!isset($user['theme'])) {
                $user['theme'] = 'light';
            }
            if (!isset($user['theme_settings']) || empty($user['theme_settings'])) {
                $user['theme_settings'] = [];
            } else {
                $user['theme_settings'] = json_decode($user['theme_settings'], true) ?: [];
            }
        } catch (PDOException $e) {
            return null;
        }
    }
    
    return $user;
}

/**
 * 统一生成用户头像 HTML（支持真实图片、文字头像、用户名首字母）
 * @param array $user 用户数组，必须包含 id, username, avatar, avatar_text 等字段
 * @param string $sizeClass 可选的 CSS 类，默认 'post-avatar'
 * @return string HTML
 */
function getUserAvatarHtml($user, $sizeClass = 'post-avatar') {
    if (empty($user)) {
        return '<div class="' . $sizeClass . '">?</div>';
    }
    
    $avatar = $user['avatar'] ?? '';
    $avatarText = $user['avatar_text'] ?? '';
    $username = $user['username'] ?? '';
    $bgColor = $user['avatar_bg_color'] ?? null;
    $avatarPending = $user['avatar_pending'] ?? null;
    
    // 如果数据中缺少相关字段，尝试单独查询用户表
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    if (!empty($userId)) {
        $needFields = [];
        if ($bgColor === null && !empty($avatarText)) $needFields[] = 'avatar_bg_color';
        if ($avatarPending === null) $needFields[] = 'avatar_pending';
        if (!empty($needFields)) {
            try {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("SELECT " . implode(',', $needFields) . " FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if (isset($row['avatar_bg_color'])) $bgColor = $row['avatar_bg_color'];
                    if (isset($row['avatar_pending'])) $avatarPending = $row['avatar_pending'];
                }
            } catch (PDOException $e) {}
        }
    }
    
    // 审核中：显示待审核图片标记
    if (!empty($avatarPending)) {
        return '<div class="' . $sizeClass . '"><img src="/zbgameshz.png" alt="审核中" style="width:100%;height:100%;object-fit:cover;"></div>';
    }
    
    // 1. 如果有真实图片头像
    if (!empty($avatar)) {
        return '<div class="' . $sizeClass . '"><img src="' . getImageUrl(escape($avatar)) . '" alt="avatar"></div>';
    }
    
    // 2. 如果有自定义文字头像（且未上传真实图片）
    if (!empty($avatarText)) {
        $text = mb_substr($avatarText, 0, 2, 'UTF-8'); // 最多取2个字符
        $bgStyle = $bgColor ? 'background: ' . $bgColor . ' !important' : 'background: var(--accent-gradient-from) !important';
        return '<div class="' . $sizeClass . '" style="' . $bgStyle . '; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; text-transform: uppercase;">' . escape($text) . '</div>';
    }
    
    // 3. 默认显示用户名首字母
    $initial = mb_substr(escape($username), 0, 1, 'UTF-8');
    return '<div class="' . $sizeClass . '" style="background: var(--accent-gradient-from); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold;">' . $initial . '</div>';
}

function getUserCount() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        return $stmt->fetch()['count'];
    } catch (PDOException $e) {
        return 0;
    }
}

function getAllUsers($page = 1, $perPage = 20) {
    $offset = ($page - 1) * $perPage;
    
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id, username, email, public_uid, avatar, avatar_text, avatar_bg_color, profile_background, is_admin, is_founder, points, exp, theme, theme_settings, chat_username, created_at, last_login, is_banned, maintenance_bypass 
                              FROM users ORDER BY created_at DESC LIMIT ?, ?");
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();
        foreach ($users as &$user) {
            $user['theme_settings'] = !empty($user['theme_settings']) ? json_decode($user['theme_settings'], true) : [];
        }
        return $users;
    } catch (PDOException $e) {
        return [];
    }
}

function deleteUser($userId) {
    if ($userId == ($_SESSION['user_id'] ?? 0)) {
        return false;
    }
    
    if (isFounder($userId)) {
        return false;
    }
    
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function getUserById($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id, username, email, public_uid, avatar, avatar_text, avatar_bg_color, avatar_pending, profile_background, is_admin, is_founder, points, exp, theme, theme_settings, chat_username, created_at, last_login, is_banned, background_pending, last_username_change 
                              FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user) {
            if (!isset($user['theme'])) {
                $user['theme'] = 'light';
            }
            $user['theme_settings'] = !empty($user['theme_settings']) ? json_decode($user['theme_settings'], true) : [];
        }
        return $user;
    } catch (PDOException $e) {
        return null;
    }
}

function isUserBanned($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT is_banned FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return $result ? (bool)$result['is_banned'] : false;
    } catch (PDOException $e) {
        return false;
    }
}

function setUserBan($userId, $ban) {
    if ($userId == ($_SESSION['user_id'] ?? 0)) {
        return false;
    }
    
    if (isFounder($userId)) {
        return false;
    }
    
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET is_banned = ? WHERE id = ?");
        return $stmt->execute([$ban ? 1 : 0, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function setUserAdmin($userId, $isAdmin) {
    if ($userId == ($_SESSION['user_id'] ?? 0)) {
        return false;
    }
    
    if (isFounder($userId)) {
        return false;
    }
    
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
        return $stmt->execute([$isAdmin ? 1 : 0, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function redirect($url) {
    header('Location: ' . $url);
    exit();
}

function generateCsrfToken() {
    // 确保 session 已启动（登录后才需要 CSRF）
    if (!isLoggedIn()) {
        // 未登录时也生成临时 token 供登录表单使用，但此时 session 可能未启动，需要强制启动
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
            cleanup_expired_sessions(); // 新启动的 session 也尝试清理
        }
    } else {
        // 已登录时 session 必然已启动
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        cleanup_expired_sessions();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    // 验证 CSRF 时 session 必须已存在（登录或登录表单）
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
        cleanup_expired_sessions();
    }
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function escape($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function showError($message) {
    echo '<div class="alert alert-error">' . escape($message) . '</div>';
}

function showSuccess($message) {
    echo '<div class="alert alert-success">' . escape($message) . '</div>';
}

function getAllSlides() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT * FROM home_slides ORDER BY sort_order ASC, created_at DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getAllLinks() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT * FROM home_links ORDER BY sort_order ASC, created_at DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getSlideById($id) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM home_slides WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

function getLinkById($id) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM home_links WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

function addSlide($data) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO home_slides (title, image_url, description, link_url, link_text, link_target, sort_order, is_active) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['title'],
            $data['image_url'],
            $data['description'] ?? '',
            $data['link_url'] ?? '',
            $data['link_text'] ?? '',
            $data['link_target'] ?? 0,
            $data['sort_order'] ?? 0,
            $data['is_active'] ?? 1
        ]);
        
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        return false;
    }
}

function addLink($data) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO home_links (title, description, link_url, category, link_target, sort_order, is_active) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['title'],
            $data['description'] ?? '',
            $data['link_url'],
            $data['category'] ?? '',
            $data['link_target'] ?? 0,
            $data['sort_order'] ?? 0,
            $data['is_active'] ?? 1
        ]);
        
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        return false;
    }
}

function updateSlide($id, $data) {
    try {
        $pdo = getDbConnection();
        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            $fields[] = "{$key} = ?";
            $values[] = $value;
        }
        
        $values[] = $id;
        $sql = "UPDATE home_slides SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);
    } catch (PDOException $e) {
        return false;
    }
}

function updateLink($id, $data) {
    try {
        $pdo = getDbConnection();
        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            $fields[] = "{$key} = ?";
            $values[] = $value;
        }
        
        $values[] = $id;
        $sql = "UPDATE home_links SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);
    } catch (PDOException $e) {
        return false;
    }
}

function deleteSlide($id) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM home_slides WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (PDOException $e) {
        return false;
    }
}

function deleteLink($id) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM home_links WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (PDOException $e) {
        return false;
    }
}

function getCategoryBySlug($slug) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$slug]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

function getAllCategories() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * 获取分类下的帖子列表（默认排除置顶帖子，因为置顶已在顶部单独显示）
 */
function getPostsByCategory($categoryId, $page = 1, $perPage = 15, $sort = 'latest', $excludeTop = true) {
    $offset = ($page - 1) * $perPage;
    
    try {
        $pdo = getDbConnection();
        
        $orderBy = 'p.created_at DESC';
        switch ($sort) {
            case 'latest':
                $orderBy = 'p.created_at DESC';
                break;
            case 'popular':
                $orderBy = 'p.view_count DESC, p.created_at DESC';
                break;
            case 'like':
                $orderBy = 'p.like_count DESC, p.created_at DESC';
                break;
            case 'comment':
                $orderBy = 'p.comment_count DESC, p.created_at DESC';
                break;
        }
        
        $where = "p.category_id = ? AND p.is_approved = 1";
        $params = [$categoryId];
        
        if ($excludeTop) {
            $where .= " AND p.is_top = 0";
        }
        
        $stmt = $pdo->prepare("SELECT p.*, u.username, u.avatar, u.avatar_text, u.avatar_bg_color, u.id as user_id, u.is_admin, u.is_founder, u.is_banned, u.exp, c.name as category_name,
                                      (SELECT COUNT(*) FROM favorites WHERE post_id = p.id) as favorite_count
                              FROM posts p 
                              LEFT JOIN users u ON p.user_id = u.id 
                              LEFT JOIN categories c ON p.category_id = c.id 
                              WHERE {$where}
                              ORDER BY {$orderBy}
                              LIMIT ?, ?");
        $params[] = $offset;
        $params[] = $perPage;
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getTopPosts($categoryId = null) {
    try {
        $pdo = getDbConnection();
        
        if ($categoryId) {
            $stmt = $pdo->prepare("SELECT p.*, u.username, u.avatar, u.avatar_text, u.avatar_bg_color, u.id as user_id, u.is_admin, u.is_founder, u.is_banned, u.exp, c.name as category_name 
                                  FROM posts p 
                                  LEFT JOIN users u ON p.user_id = u.id 
                                  LEFT JOIN categories c ON p.category_id = c.id 
                                  WHERE p.is_top = 1 AND p.is_approved = 1 AND p.category_id = ? 
                                  ORDER BY p.created_at DESC 
                                  LIMIT 10");
            $stmt->execute([$categoryId]);
        } else {
            $stmt = $pdo->prepare("SELECT p.*, u.username, u.avatar, u.avatar_text, u.avatar_bg_color, u.id as user_id, u.is_admin, u.is_founder, u.is_banned, u.exp, c.name as category_name 
                                  FROM posts p 
                                  LEFT JOIN users u ON p.user_id = u.id 
                                  LEFT JOIN categories c ON p.category_id = c.id 
                                  WHERE p.is_top = 1 AND p.is_approved = 1 
                                  ORDER BY p.created_at DESC 
                                  LIMIT 10");
            $stmt->execute();
        }
        
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * 获取帖子总数（支持排除置顶帖子）
 */
function getPostCount($categoryId = null, $excludeTop = false) {
    try {
        $pdo = getDbConnection();
        
        if ($categoryId) {
            $sql = "SELECT COUNT(*) as count FROM posts WHERE category_id = ? AND is_approved = 1";
            $params = [$categoryId];
            if ($excludeTop) {
                $sql .= " AND is_top = 0";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            $sql = "SELECT COUNT(*) as count FROM posts WHERE is_approved = 1";
            if ($excludeTop) {
                $sql .= " AND is_top = 0";
            }
            $stmt = $pdo->query($sql);
        }
        
        return $stmt->fetch()['count'];
    } catch (PDOException $e) {
        return 0;
    }
}

function getPostById($postId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT p.*, u.username, u.avatar, u.avatar_text, u.avatar_bg_color, u.id as user_id, u.is_admin, u.is_founder, u.is_banned, u.exp, c.name as category_name, c.slug as category_slug 
                              FROM posts p 
                              LEFT JOIN users u ON p.user_id = u.id 
                              LEFT JOIN categories c ON p.category_id = c.id 
                              WHERE p.id = ? LIMIT 1");
        $stmt->execute([$postId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

function incrementPostView($postId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE posts SET view_count = view_count + 1 WHERE id = ?");
        $stmt->execute([$postId]);
        // 今日浏览 +1
        $stmt2 = $pdo->prepare("INSERT INTO post_daily_views (post_id, view_date, view_count) VALUES (?, CURDATE(), 1) ON DUPLICATE KEY UPDATE view_count = view_count + 1");
        $stmt2->execute([$postId]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function getPostImages($postId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM post_images WHERE post_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$postId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getFirstPostImage($postId) {
    $images = getPostImages($postId);
    if (!empty($images)) {
        return $images[0]['image_url'] ?? null;
    }
    return null;
}

/**
 * 获取帖子评论（包含置顶排序）
 */
function getPostComments($postId, $page = 1, $perPage = 20, $currentUserId = null) {
    $offset = ($page - 1) * $perPage;
    
    try {
        $pdo = getDbConnection();
        // 置顶评论优先，然后按创建时间升序
        // 非审核通过的评论仅作者和管理员可见
        $userId = intval($currentUserId ?? 0);
        $stmt = $pdo->prepare("SELECT c.*, u.username, u.avatar, u.avatar_text, u.avatar_bg_color, u.id as user_id, u.is_admin, u.is_founder, u.is_banned, u.exp,
                                      c.replies_count as reply_count
                              FROM comments c 
                              LEFT JOIN users u ON c.user_id = u.id 
                              WHERE c.post_id = ? AND c.parent_id IS NULL 
                                AND (c.is_approved = 1 OR (c.is_approved = 0 AND c.user_id = ?))
                              ORDER BY c.is_top DESC, c.created_at ASC 
                              LIMIT ?, ?");
        $stmt->bindValue(1, $postId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->bindValue(4, $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getCommentReplies($commentId, $currentUserId = null) {
    try {
        $pdo = getDbConnection();
        $userId = intval($currentUserId ?? 0);
        $stmt = $pdo->prepare("SELECT c.*, u.username, u.avatar, u.avatar_text, u.avatar_bg_color, u.id as user_id, u.is_admin, u.is_founder, u.is_banned, u.exp 
                              FROM comments c 
                              LEFT JOIN users u ON c.user_id = u.id 
                              WHERE c.parent_id = ? 
                                AND (c.is_approved = 1 OR (c.is_approved = 0 AND c.user_id = ?))
                              ORDER BY c.created_at ASC");
        $stmt->execute([$commentId, $userId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getCommentById($commentId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT c.*, u.username, u.avatar, u.avatar_text, u.avatar_bg_color, u.id as user_id, u.is_admin, u.is_founder, u.is_banned, u.exp 
                              FROM comments c 
                              LEFT JOIN users u ON c.user_id = u.id 
                              WHERE c.id = ? LIMIT 1");
        $stmt->execute([$commentId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

function getCommentRepliesRecursive($commentId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT c.*, u.username, u.avatar, u.avatar_text, u.avatar_bg_color, u.id as user_id, u.is_admin, u.is_founder, u.is_banned, u.exp FROM comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.parent_id = ? AND c.is_approved = 1 ORDER BY c.created_at ASC");
        $stmt->execute([$commentId]);
        $replies = $stmt->fetchAll();
        foreach ($replies as &$r) {
            $r['replies'] = getCommentRepliesRecursive($r['id']);
        }
        return $replies;
    } catch (PDOException $e) {
        return [];
    }
}

function getCommentCount($postId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM comments WHERE post_id = ? AND is_approved = 1");
        $stmt->execute([$postId]);
        return $stmt->fetch()['count'];
    } catch (PDOException $e) {
        return 0;
    }
}

function hasUserLikedPost($postId, $userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM post_likes WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$postId, $userId]);
        return $stmt->fetch()['count'] > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function togglePostLike($postId, $userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$postId, $userId]);
        $existing = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
        $stmt->execute([$postId]);
        $post = $stmt->fetch();
        $authorId = $post ? $post['user_id'] : 0;
        
        if ($existing) {
            $stmt = $pdo->prepare("DELETE FROM post_likes WHERE id = ?");
            $stmt->execute([$existing['id']]);
            
            $stmt = $pdo->prepare("UPDATE posts SET like_count = like_count - 1 WHERE id = ?");
            $stmt->execute([$postId]);
            
            return ['liked' => false, 'message' => '取消点赞成功'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)");
            $stmt->execute([$postId, $userId]);
            
            $stmt = $pdo->prepare("UPDATE posts SET like_count = like_count + 1 WHERE id = ?");
            $stmt->execute([$postId]);
            
            if ($authorId != $userId) {
                $data = ['post_id' => $postId];
                createNotification($authorId, 'like_post', $userId, $postId, $data);
            }
            
            return ['liked' => true, 'message' => '点赞成功'];
        }
    } catch (PDOException $e) {
        return ['liked' => false, 'message' => '操作失败: ' . $e->getMessage()];
    }
}

function toggleCommentLike($commentId, $userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ?");
        $stmt->execute([$commentId, $userId]);
        $existing = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT user_id, post_id FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        $authorId = $comment ? $comment['user_id'] : 0;
        $postId = $comment ? $comment['post_id'] : 0;
        
        if ($existing) {
            $stmt = $pdo->prepare("DELETE FROM comment_likes WHERE id = ?");
            $stmt->execute([$existing['id']]);
            
            $stmt = $pdo->prepare("UPDATE comments SET like_count = like_count - 1 WHERE id = ?");
            $stmt->execute([$commentId]);
            
            return ['liked' => false, 'message' => '取消点赞成功'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO comment_likes (comment_id, user_id) VALUES (?, ?)");
            $stmt->execute([$commentId, $userId]);
            
            $stmt = $pdo->prepare("UPDATE comments SET like_count = like_count + 1 WHERE id = ?");
            $stmt->execute([$commentId]);
            
            if ($authorId != $userId) {
                $data = ['comment_id' => $commentId, 'post_id' => $postId];
                createNotification($authorId, 'like_comment', $userId, $commentId, $data);
            }
            
            return ['liked' => true, 'message' => '点赞成功'];
        }
    } catch (PDOException $e) {
        return ['liked' => false, 'message' => '操作失败: ' . $e->getMessage()];
    }
}

/**
 * 设置评论置顶状态
 */
function setCommentTop($commentId, $isTop) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE comments SET is_top = ? WHERE id = ?");
        return $stmt->execute([$isTop ? 1 : 0, $commentId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * 安全过滤HTML，移除危险标签和属性（彻底防止XSS）
 */
function safe_html($html) {
    // 1. 定义允许的标签（白名单）
    $allowed_tags = '<p><br><b><i><u><strong><em><ul><ol><li><a><img><span><div><h1><h2><h3><h4><h5><h6><blockquote><pre><code><hr>';
    
    // 2. 第一轮过滤：使用 strip_tags 移除所有不在白名单内的标签
    $html = strip_tags($html, $allowed_tags);
    
    // 3. 显式移除所有危险的完整标签块（script, iframe, object, embed 等）
    // 这是双重保险，即使 strip_tags 失效也能拦截
    $html = preg_replace('/<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>/is', '', $html);
    $html = preg_replace('/<\s*(?:iframe|object|embed|applet|meta|link|style)\b[^>]*>/is', '', $html);
    $html = preg_replace('/<\s*\/\s*(?:iframe|object|embed|applet|meta|link|style)\s*>/is', '', $html);
    // 也处理自闭合的 script 标签，如 <script src="x" />
    $html = preg_replace('/<\s*script\b[^>]*\/>/is', '', $html);
    
    // 4. 移除所有 on* 事件处理器属性（onclick, onerror, onload 等）
    $html = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/is', '', $html);
    
    // 5. 移除包含 javascript: 的链接和图片源
    $html = preg_replace('/(?:href|src)\s*=\s*["\'][\s]*javascript[^"\']*["\']/is', '', $html);
    $html = preg_replace('/(?:href|src)\s*=\s*["\'][\s]*data:text\/html[^"\']*["\']/is', '', $html);
    
    // 6. 清理 style 属性中的危险表达式
    $html = preg_replace_callback('/\s*style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', function($matches) {
        $styleValue = trim($matches[1], "\"'");
        $styleValue = preg_replace('/expression\s*\(/i', '', $styleValue);
        $styleValue = preg_replace('/url\s*\(\s*["\']?\s*javascript\s*:/i', 'url(', $styleValue);
        $styleValue = preg_replace('/behavior\s*:/i', '', $styleValue);
        $styleValue = preg_replace('/\/\*.*?\*\//s', '', $styleValue);
        if (empty(trim($styleValue))) {
            return '';
        }
        return ' style="' . $styleValue . '"';
    }, $html);
    
    // 7. 移除 img 标签 style 中的 width/height/object-fit（App 版发图时嵌入了 120x120 缩略图样式，网页版会显示为小图）
    $html = preg_replace_callback('/<img\s[^>]*>/i', function($m) {
        $tag = $m[0];
        if (preg_match('/\sstyle\s*=\s*(["\'])([^"\']*)\1/i', $tag, $sm)) {
            $delim = $sm[1];
            $styleVal = $sm[2];
            $clean = trim(preg_replace(
                ['/\bwidth\s*:\s*[^;]+;?\s*/i', '/\bheight\s*:\s*[^;]+;?\s*/i', '/\bobject-fit\s*:\s*[^;]+;?\s*/i'],
                '',
                $styleVal
            ));
            $clean = rtrim($clean, ';');
            if ($clean === '') {
                $tag = preg_replace('/\s+style\s*=\s*' . $delim . '\s*' . $delim . '/i', '', $tag);
            } else {
                $tag = str_replace('style=' . $delim . $styleVal . $delim, 'style=' . $delim . $clean . $delim, $tag);
            }
        }
        return $tag;
    }, $html);
    
    return $html;
}

function createPost($data) {
    try {
        $pdo = getDbConnection();
        
        $category = getCategoryBySlug($data['category_slug']);
        if (!$category) {
            return ['success' => false, 'message' => '分类不存在'];
        }
        
        $safe_content = safe_html($data['content']);
        $summary = mb_substr(strip_tags($safe_content), 0, 150, 'UTF-8');
        
        $auditEnabled = getSetting('audit_enabled', '0') === '1';
        
        // 站长和管理员发帖免审
        $isAdmin = false;
        try {
            $uStmt = $pdo->prepare("SELECT is_admin, is_founder FROM users WHERE id = ?");
            $uStmt->execute([$data['user_id']]);
            $u = $uStmt->fetch();
            $isAdmin = $u && (!empty($u['is_founder']) || !empty($u['is_admin']));
        } catch (Exception $e) {}
        
        $isApproved = ($auditEnabled && !$isAdmin) ? 0 : 1;
        $stmt = $pdo->prepare("INSERT INTO posts (user_id, category_id, title, content, summary, is_approved) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['user_id'],
            $category['id'],
            $data['title'],
            $safe_content,
            $summary,
            $isApproved
        ]);
        
        $postId = $pdo->lastInsertId();
        
        // 审核检查（管理员免审）
        if ($auditEnabled && !$isAdmin) {
            $auditResult = checkContentAudit($data['user_id'], 'post', $postId, $data['title'] . ' ' . strip_tags($safe_content));
            if ($auditResult['status'] === 'approved') {
                $pdo->prepare("UPDATE posts SET is_approved=1 WHERE id=?")->execute([$postId]);
            }
            if ($auditResult['status'] === 'rejected') {
                return ['success' => false, 'message' => $auditResult['message'], 'audit' => $auditResult];
            }
            $resultMsg = '帖子已提交审核，请等待管理员审核通过后自动发布';
        } else {
            $resultMsg = '帖子发布成功';
        }
        
        // 发帖经验奖励
        addUserExp($data['user_id'], EXP_PER_POST, 'post');
        
        return ['success' => true, 'post_id' => $postId, 'message' => $resultMsg];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => '发布失败: ' . $e->getMessage()];
    }
}

function addPostImage($postId, $imageUrl) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO post_images (post_id, image_url) VALUES (?, ?)");
        return $stmt->execute([$postId, $imageUrl]);
    } catch (PDOException $e) {
        return false;
    }
}

function getCommentImages($commentId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT image_url FROM comment_images WHERE comment_id = ? ORDER BY id ASC");
        $stmt->execute([$commentId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function addComment($data) {
    try {
        $pdo = getDbConnection();
        
        $imageUrl = $data['image_url'] ?? null;
        $imageUrls = $data['image_urls'] ?? null;
        $parentId = $data['parent_id'] ?? null;
        
        $auditEnabled = getSetting('audit_enabled', '0') === '1';
        
        // 站长和管理员评论免审
        $isAdmin = false;
        try {
            $uStmt = $pdo->prepare("SELECT is_admin, is_founder FROM users WHERE id = ?");
            $uStmt->execute([$data['user_id']]);
            $u = $uStmt->fetch();
            $isAdmin = $u && (!empty($u['is_founder']) || !empty($u['is_admin']));
        } catch (Exception $e) {}
        
        $isApproved = ($auditEnabled && !$isAdmin) ? 0 : 1;
        $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, content, image_url, parent_id, is_approved) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['post_id'],
            $data['user_id'],
            $data['content'],
            $imageUrl,
            $parentId,
            $isApproved
        ]);
        
        $commentId = $pdo->lastInsertId();
        
        // 审核检查
        if ($auditEnabled) {
            $auditResult = checkContentAudit($data['user_id'], 'comment', $commentId, strip_tags($data['content']));
            if ($auditResult['status'] === 'approved') {
                $pdo->prepare("UPDATE comments SET is_approved=1 WHERE id=?")->execute([$commentId]);
            }
            if ($auditResult['status'] === 'rejected') {
                return ['success' => false, 'message' => $auditResult['message']];
            }
            // pending: 保持 is_approved=0，等着通过后才增加计数和显示
        }
        
        // 存储多张图片到 comment_images 表（最多9张）
        $allImageUrls = [];
        if (!empty($imageUrls) && is_array($imageUrls)) {
            $allImageUrls = $imageUrls;
        }
        if (!empty($imageUrl)) {
            $allImageUrls[] = $imageUrl;
        }
        $allImageUrls = array_slice(array_unique($allImageUrls), 0, 9);
        if (!empty($allImageUrls)) {
            $insertStmt = $pdo->prepare("INSERT INTO comment_images (comment_id, image_url, created_at) VALUES (?, ?, NOW())");
            foreach ($allImageUrls as $url) {
                $insertStmt->execute([$commentId, $url]);
            }
        }
        
        // 仅审核通过或审核关闭时才增加计数和通知
        $commentApproved = !$auditEnabled || $auditResult['status'] === 'approved';
        
        if ($commentApproved) {
            // 所有评论（包括回复、回中回）都增加 post 评论计数
            $stmt = $pdo->prepare("UPDATE posts SET comment_count = comment_count + 1 WHERE id = ?");
            $stmt->execute([$data['post_id']]);
            
            // 如果是回复，追溯根评论并递增 replies_count
            if ($parentId) {
                $traceId = $parentId;
                $visited = [];
                while ($traceId) {
                    $visited[] = $traceId;
                    $stmt = $pdo->prepare("SELECT parent_id FROM comments WHERE id = ?");
                    $stmt->execute([$traceId]);
                    $parent = $stmt->fetch();
                    if (!$parent || is_null($parent['parent_id'])) {
                        $stmt = $pdo->prepare("UPDATE comments SET replies_count = replies_count + 1 WHERE id = ?");
                        $stmt->execute([$traceId]);
                        break;
                    }
                    $traceId = $parent['parent_id'];
                    if (count($visited) > 50) break;
                }
            }
            
            if (!$parentId) {
                $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
                $stmt->execute([$data['post_id']]);
                $post = $stmt->fetch();
                $authorId = $post ? $post['user_id'] : 0;
                if ($authorId != $data['user_id']) {
                    $notifData = ['post_id' => $data['post_id'], 'comment_id' => $commentId];
                    createNotification($authorId, 'comment', $data['user_id'], $data['post_id'], $notifData);
                }
            } else {
                $stmt = $pdo->prepare("SELECT user_id, post_id FROM comments WHERE id = ?");
                $stmt->execute([$parentId]);
                $parentComment = $stmt->fetch();
                $authorId = $parentComment ? $parentComment['user_id'] : 0;
                
                if ($authorId != $data['user_id']) {
                    $notifData = [
                        'post_id' => $data['post_id'],
                        'comment_id' => $commentId,
                        'parent_comment_id' => $parentId
                    ];
                    createNotification($authorId, 'reply', $data['user_id'], $parentId, $notifData);
                }
            }
        }
        
        // 评论经验奖励
        addUserExp($data['user_id'], EXP_PER_COMMENT, 'comment');
        
        return ['success' => true, 'comment_id' => $commentId, 'message' => '评论发布成功'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => '评论失败: ' . $e->getMessage()];
    }
}

function deleteComment($commentId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("SELECT post_id, parent_id FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        
        if (!$comment) {
            return false;
        }
        
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ? OR parent_id = ?");
        $stmt->execute([$commentId, $commentId]);
        $deletedCount = $stmt->rowCount();
        
        // 删除任意评论都减少 post 评论计数（含子回复）
        $stmt = $pdo->prepare("UPDATE posts SET comment_count = comment_count - ? WHERE id = ?");
        $stmt->execute([$deletedCount, $comment['post_id']]);
        
        // 如果删除的是回复，减少根评论的 replies_count
        if ($comment['parent_id']) {
            $traceId = $comment['parent_id'];
            $visited = [];
            while ($traceId) {
                $visited[] = $traceId;
                $stmt = $pdo->prepare("SELECT parent_id FROM comments WHERE id = ?");
                $stmt->execute([$traceId]);
                $parent = $stmt->fetch();
                if (!$parent || is_null($parent['parent_id'])) {
                    $stmt = $pdo->prepare("UPDATE comments SET replies_count = replies_count - 1 WHERE id = ?");
                    $stmt->execute([$traceId]);
                    break;
                }
                $traceId = $parent['parent_id'];
                if (count($visited) > 50) break;
            }
        }
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * 删除文件物理文件（支持相对路径或绝对路径）
 * @param string $filePath 文件相对路径（如 /uploads/posts/xxx.jpg）或绝对路径
 * @return bool
 */
function deleteFileIfExists($filePath) {
    if (empty($filePath)) {
        return false;
    }
    // 如果是相对路径（以 / 开头），转换为绝对路径
    if (strpos($filePath, '/') === 0) {
        $absolutePath = __DIR__ . $filePath;
    } elseif (strpos($filePath, 'uploads/') === 0) {
        $absolutePath = __DIR__ . '/' . $filePath;
    } else {
        $absolutePath = $filePath;
    }
    // 确保文件存在且在安全目录内（防止删除系统文件）
    $realPath = realpath($absolutePath);
    if ($realPath === false) {
        return false;
    }
    $baseDir = realpath(__DIR__ . '/uploads/');
    if ($baseDir === false) {
        return false;
    }
    // 只允许删除 uploads 目录下的文件
    if (strpos($realPath, $baseDir) !== 0) {
        return false;
    }
    if (is_file($realPath) && @unlink($realPath)) {
        return true;
    }
    return false;
}

/**
 * 获取帖子关联的所有本地文件路径（用于删除时清理）
 * @param int $postId
 * @return array 文件相对路径列表
 */
function getPostAllFiles($postId) {
    $files = [];
    $pdo = getDbConnection();
    
    // 1. 获取帖子内容中的图片 URL
    $stmt = $pdo->prepare("SELECT content, attachment_path FROM posts WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    if ($post) {
        // 从 HTML 内容中提取所有 src 属性
        if (!empty($post['content'])) {
            preg_match_all('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $post['content'], $matches);
            foreach ($matches[1] as $src) {
                $src = trim($src);
                if (strpos($src, '/uploads/') === 0 || strpos($src, 'uploads/') === 0) {
                    $files[] = $src;
                }
            }
        }
        // 附件路径
        if (!empty($post['attachment_path'])) {
            $files[] = $post['attachment_path'];
        }
    }
    
    // 2. 获取 post_images 表中的图片
    $stmt = $pdo->prepare("SELECT image_url FROM post_images WHERE post_id = ?");
    $stmt->execute([$postId]);
    $images = $stmt->fetchAll();
    foreach ($images as $img) {
        if (!empty($img['image_url'])) {
            $files[] = $img['image_url'];
        }
    }
    
    // 去重并返回
    $files = array_unique($files);
    return $files;
}

/**
 * 删除帖子及其所有关联文件
 * @param int $postId
 * @return bool
 */
function deletePost($postId) {
    try {
        $pdo = getDbConnection();
        
        // 先获取所有关联文件
        $filesToDelete = getPostAllFiles($postId);
        
        // 删除数据库记录（由于外键约束，post_images 等会被自动删除）
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
        $result = $stmt->execute([$postId]);
        
        if ($result) {
            // 删除物理文件
            foreach ($filesToDelete as $file) {
                deleteFileIfExists($file);
            }
        }
        return $result;
    } catch (PDOException $e) {
        return false;
    }
}

function setPostTop($postId, $isTop) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE posts SET is_top = ? WHERE id = ?");
        return $stmt->execute([$isTop ? 1 : 0, $postId]);
    } catch (PDOException $e) {
        return false;
    }
}

function setPostApproval($postId, $isApproved) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE posts SET is_approved = ? WHERE id = ?");
        return $stmt->execute([$isApproved ? 1 : 0, $postId]);
    } catch (PDOException $e) {
        return false;
    }
}

function uploadFile($file, $type = 'image') {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => '文件上传失败，错误码：' . $file['error']];
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => '文件大小不能超过5MB'];
    }
    
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // 构建允许的文件类型列表（兼容原有常量定义，并添加 .zbgamemodpack 支持）
    $allowedTypes = (defined('ALLOWED_FILE_TYPES') && is_array(ALLOWED_FILE_TYPES)) 
        ? ALLOWED_FILE_TYPES 
        : ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'zip', 'rar', '7z'];
    // 添加新后缀 .zbgamemodpack（压缩包类型）
    $allowedTypes[] = 'zbgamemodpack';
    $allowedTypes = array_unique($allowedTypes);
    
    if (!in_array($fileExt, $allowedTypes)) {
        return ['success' => false, 'message' => '不支持的文件类型'];
    }
    
    $baseDir = __DIR__ . '/' . UPLOAD_DIR;
    if ($type === 'image') {
        $uploadDir = $baseDir . 'posts/';
    } elseif ($type === 'comment') {
        $uploadDir = $baseDir . 'comments/';
    } elseif ($type === 'chat') {
        $uploadDir = $baseDir . 'chat/';
    } else {
        $uploadDir = $baseDir . 'attachments/';
    }
    
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['success' => false, 'message' => '无法创建上传目录：' . $uploadDir];
        }
    }
    
    if (!is_writable($uploadDir)) {
        return ['success' => false, 'message' => '上传目录不可写：' . $uploadDir];
    }
    
    $maxAttempts = 10;
    $attempt = 0;
    do {
        $fileName = uniqid(mt_rand(), true) . '.' . $fileExt;
        $filePath = $uploadDir . $fileName;
        $attempt++;
    } while (file_exists($filePath) && $attempt < $maxAttempts);
    
    if ($attempt >= $maxAttempts) {
        return ['success' => false, 'message' => '无法生成唯一的文件名，请稍后重试'];
    }
    
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        // 关键修改：确保返回的 file_url 以 / 开头
        $relativePath = '/' . UPLOAD_DIR . ($type === 'image' ? 'posts/' : ($type === 'comment' ? 'comments/' : ($type === 'chat' ? 'chat/' : 'attachments/'))) . $fileName;
        return [
            'success' => true, 
            'file_name' => $file['name'],
            'file_path' => $filePath,
            'file_url' => $relativePath,
            'file_size' => $file['size']
        ];
    } else {
        return ['success' => false, 'message' => '文件保存失败，无法移动上传文件'];
    }
}

function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' Bytes';
    }
}

/**
 * 审核内容：检查发布的内容是否需要审核
 * @param int $userId 用户ID
 * @param string $contentType post|comment|username|avatar|image
 * @param int|null $contentId 内容ID
 * @param string|null $contentData 内容文字
 * @param string|null $oldValue 旧值（如用户名修改场景）
 * @return array ['status'=>'approved'|'pending'|'rejected', 'message'=>'']
 */
function checkContentAudit($userId, $contentType, $contentId = null, $contentData = null, $oldValue = null) {
    if (getSetting('audit_enabled', '0') !== '1') {
        return ['status' => 'approved', 'message' => ''];
    }
    
    // 站长和管理员不受审核影响
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT is_admin, is_founder FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user && (!empty($user['is_founder']) || !empty($user['is_admin']))) {
            return ['status' => 'approved', 'message' => '管理员免审'];
        }
    } catch (Exception $e) {}

    
    $autoAudit = getSetting('audit_auto', '0') === '1';
    $intercept = getSetting('audit_intercept', '0') === '1';
    
    $textToCheck = is_string($contentData) ? $contentData : '';
    
    // 1. 关键词违规拦截
    if ($intercept && !empty($textToCheck)) {
        $violationKeywords = [
            '杀人','枪杀','砍人','炸死','屠杀','灭门','分尸','肢解','活埋','烧死',
            '成人片','色情片','裸聊','约炮','一夜情','援交','卖淫','嫖娼','裸照','三级片',
            '毒品','海洛因','冰毒','大麻','摇头丸','K粉','罂粟','吸毒','制毒',
            '赌博','赌场','六合彩','赌球','赌马','老虎机','百家乐',
            '枪支','手枪','步枪','冲锋枪','军火','弹药','炸药',
            '法轮功','学运','六四','台独','港独','藏独','疆独',
            '传销','诈骗','电信诈骗','庞氏骗局',
        ];
        
        foreach ($violationKeywords as $keyword) {
            if (mb_strpos($textToCheck, $keyword) !== false) {
                try {
                    $pdo = getDbConnection();
                    $stmt = $pdo->prepare("INSERT INTO audit_items (user_id, content_type, content_id, content_data, old_value, status, reviewed_at, reviewed_by) VALUES (?,?,?,?,?,'rejected',NOW(),0)");
                    $stmt->execute([$userId, $contentType, $contentId, $textToCheck, $oldValue]);
                    // 标记内容：帖子/评论置 is_approved=0
                    if ($contentType === 'post' && $contentId) {
                        $pdo->prepare("UPDATE posts SET is_approved=0 WHERE id=?")->execute([$contentId]);
                    } elseif ($contentType === 'comment' && $contentId) {
                        $pdo->prepare("UPDATE comments SET is_approved=0 WHERE id=?")->execute([$contentId]);
                    }
                } catch (Exception $e) {
                    error_log('Audit keyword check error: ' . $e->getMessage());
                }
                return ['status' => 'rejected', 'message' => '内容包含违规词汇'];
            }
        }
    }
    
    
    // 2. AI 自动审核（通用配置，支持任意 OpenAI 兼容 API）
    if ($autoAudit && !empty($textToCheck)) {
        $apiUrl    = getSetting('audit_api_url', '');
        $apiModel  = getSetting('audit_api_model', '');
        $apiKey    = getSetting('audit_api_key', '');
        $apiPrompt = getSetting('audit_api_prompt', '');
        
        if (!empty($apiUrl) && !empty($apiKey) && !empty($apiModel)) {
            $truncated = mb_substr($textToCheck, 0, 3000, 'UTF-8');
            $prompt = !empty($apiPrompt) ? $apiPrompt : '你是一个严格的论坛内容审核员。请审核以下用户发布的内容，判断是否包含任何违规内容。

违规类别包括：
1. 色情/低俗：露骨性描述、色情内容、交友约炮
2. 仇恨/歧视：针对种族、性别、地域、宗教的辱骂攻击
3. 暴力/血腥：杀人、伤害、虐待、血腥描述
4. 自残/自杀：鼓励自杀、自伤行为
5. 骚扰/霸凌：人身攻击、网络暴力
6. 诈骗/广告：虚假信息、恶意推广、垃圾广告
7. 政治敏感：违反中国法律法规的内容
8. 未成年不宜：不适合未成年人阅读的内容

请严格审核，只要有任何疑似违规，flagged 就必须为 true。

只输出一行纯 JSON，格式：{"flagged":true/false,"reason":"违规类别说明"}';
            $postBody = json_encode(['model' => $apiModel, 'messages' => [['role' => 'user', 'content' => $prompt . "\n\n内容：" . $truncated]], 'temperature' => 0.05, 'max_tokens' => 150]);
            try {
                $ch = curl_init($apiUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postBody,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                if ($httpCode === 200) {
                    $result = json_decode($response, true);
                    $reply = $result['choices'][0]['message']['content'] ?? '';
                    if (preg_match('/\{[^}]+\}/', $reply, $m)) {
                        $decision = json_decode($m[0], true);
                        $flagged = $decision['flagged'] ?? false;
                        $reason = $decision['reason'] ?? '违规';
                        if ($flagged) {
                            try {
                                $pdo = getDbConnection();
                                $stmt = $pdo->prepare("INSERT INTO audit_items (user_id, content_type, content_id, content_data, old_value, status) VALUES (?,?,?,?,?,'pending')");
                                $stmt->execute([$userId, $contentType, $contentId, $textToCheck, $oldValue]);
                                if ($contentType === 'post' && $contentId) {
                                    $pdo->prepare("UPDATE posts SET is_approved=0 WHERE id=?")->execute([$contentId]);
                                } elseif ($contentType === 'comment' && $contentId) {
                                    $pdo->prepare("UPDATE comments SET is_approved=0 WHERE id=?")->execute([$contentId]);
                                }
                            } catch (Exception $e) {
                                error_log('Audit insert error: ' . $e->getMessage());
                            }
                            return ['status' => 'pending', 'message' => 'AI检测到违规内容：' . $reason . '，已移交人工审核'];
                        }
                        return ['status' => 'approved', 'message' => 'AI审核通过'];
                    } else {
                        error_log('AI audit: failed to parse JSON from response: ' . substr($reply, 0, 200));
                    }
                } else {
                    error_log('AI audit: API returned HTTP ' . $httpCode . ' | curl: ' . $curlError . ' | response: ' . substr($response, 0, 200));
                }
            } catch (Exception $e) {
                error_log('AI audit exception: ' . $e->getMessage());
            }
        }
        
        // AI 审核失败或无配置 — 降级到人工审核队列
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("INSERT INTO audit_items (user_id, content_type, content_id, content_data, old_value, status) VALUES (?,?,?,?,?,'pending')");
            $stmt->execute([$userId, $contentType, $contentId, $textToCheck, $oldValue]);
            if ($contentType === 'post' && $contentId) {
                $pdo->prepare("UPDATE posts SET is_approved=0 WHERE id=?")->execute([$contentId]);
            } elseif ($contentType === 'comment' && $contentId) {
                $pdo->prepare("UPDATE comments SET is_approved=0 WHERE id=?")->execute([$contentId]);
            }
        } catch (Exception $e) {
            error_log('Audit insert error: ' . $e->getMessage());
        }
        return ['status' => 'pending', 'message' => '内容已提交审核，等待管理员处理'];
    }
    
    // 3. 审核已开启但未自动通过 — 等待人工审核
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO audit_items (user_id, content_type, content_id, content_data, old_value, status) VALUES (?,?,?,?,?,'pending')");
        $stmt->execute([$userId, $contentType, $contentId, $textToCheck, $oldValue]);
        if ($contentType === 'post' && $contentId) {
            $pdo->prepare("UPDATE posts SET is_approved=0 WHERE id=?")->execute([$contentId]);
        } elseif ($contentType === 'comment' && $contentId) {
            $pdo->prepare("UPDATE comments SET is_approved=0 WHERE id=?")->execute([$contentId]);
        }
    } catch (Exception $e) {
        error_log('Audit insert error: ' . $e->getMessage());
    }
    return ['status' => 'pending', 'message' => '内容正在审核中'];
}

function getFileIcon($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    $icons = [
        'pdf' => '',
        'doc' => '',
        'docx' => '',
        'xls' => '',
        'xlsx' => '',
        'zip' => '',
        'rar' => '',
        '7z' => '',
        'jpg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'jpeg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'png' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'gif' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
    ];
    
    return $icons[$ext] ?? '';
}

function searchPosts($keyword, $categoryId = 0, $page = 1, $perPage = 15) {
    $offset = ($page - 1) * $perPage;
    $keyword = '%' . $keyword . '%';
    
    try {
        $pdo = getDbConnection();
        
        $sql = "SELECT p.*, (SELECT COUNT(*) FROM favorites WHERE post_id = p.id) as favorite_count, u.username, u.avatar, u.avatar_text, u.avatar_bg_color, u.id as user_id, u.is_admin, u.is_founder, u.is_banned, u.exp, c.name as category_name 
                FROM posts p 
                LEFT JOIN users u ON p.user_id = u.id 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE p.title LIKE ? AND p.is_approved = 1";
        
        $params = [$keyword];
        
        if ($categoryId > 0) {
            $sql .= " AND p.category_id = ?";
            $params[] = $categoryId;
        }
        
        $sql .= " ORDER BY p.created_at DESC LIMIT ?, ?";
        $params[] = $offset;
        $params[] = $perPage;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function searchPostsCount($keyword, $categoryId = 0) {
    $keyword = '%' . $keyword . '%';
    
    try {
        $pdo = getDbConnection();
        
        $sql = "SELECT COUNT(*) as count 
                FROM posts p 
                WHERE p.title LIKE ? AND p.is_approved = 1";
        
        $params = [$keyword];
        
        if ($categoryId > 0) {
            $sql .= " AND p.category_id = ?";
            $params[] = $categoryId;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch()['count'];
    } catch (PDOException $e) {
        return 0;
    }
}

function getUserChatInfo($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT chat_username FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return $result ? $result['chat_username'] : null;
    } catch (PDOException $e) {
        return null;
    }
}

function setUserChatUsername($userId, $chatUsername) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET chat_username = ? WHERE id = ?");
        return $stmt->execute([$chatUsername, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function getUserFollowStats($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM follows WHERE follower_id = ?");
        $stmt->execute([$userId]);
        $following = $stmt->fetch()['count'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM follows WHERE following_id = ?");
        $stmt->execute([$userId]);
        $followers = $stmt->fetch()['count'];
        
        return ['following' => $following, 'followers' => $followers];
    } catch (PDOException $e) {
        return ['following' => 0, 'followers' => 0];
    }
}

function getUserReceivedLikes($userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("SELECT SUM(like_count) as total FROM posts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $postLikes = $stmt->fetch()['total'] ?? 0;
        
        $stmt = $pdo->prepare("SELECT SUM(like_count) as total FROM comments WHERE user_id = ?");
        $stmt->execute([$userId]);
        $commentLikes = $stmt->fetch()['total'] ?? 0;
        
        return (int)($postLikes + $commentLikes);
    } catch (PDOException $e) {
        return 0;
    }
}

function getUserPosts($userId, $page = 1, $perPage = 10) {
    $offset = ($page - 1) * $perPage;
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id, title, content, summary, view_count, comment_count, like_count, 
                                      (SELECT COUNT(*) FROM favorites WHERE post_id = p.id) as favorite_count, 
                                      created_at, is_top, is_approved 
                              FROM posts p
                              WHERE user_id = ? AND is_approved = 1 
                              ORDER BY created_at DESC 
                              LIMIT ?, ?");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getUserPostsCount($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM posts WHERE user_id = ? AND is_approved = 1");
        $stmt->execute([$userId]);
        return $stmt->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function isFollowing($followerId, $followingId) {
    if ($followerId <= 0 || $followingId <= 0) return false;
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$followerId, $followingId]);
        return $stmt->fetch() ? true : false;
    } catch (PDOException $e) {
        return false;
    }
}

function toggleFollow($followerId, $followingId) {
    if ($followerId == $followingId) {
        return ['success' => false, 'message' => '不能关注自己'];
    }
    
    if (isUserBanned($followingId)) {
        return ['success' => false, 'message' => '该用户已被封禁，无法关注'];
    }
    
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$followerId, $followingId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("DELETE FROM follows WHERE id = ?");
            $stmt->execute([$existing['id']]);
            $following = false;
        } else {
            $stmt = $pdo->prepare("INSERT INTO follows (follower_id, following_id) VALUES (?, ?)");
            $stmt->execute([$followerId, $followingId]);
            $following = true;
            
            createNotification($followingId, 'follow', $followerId, null, []);
        }

        $pdo->commit();
        return ['success' => true, 'following' => $following, 'message' => $following ? '关注成功' : '取消关注成功'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => '操作失败：' . $e->getMessage()];
    }
}

function getFollowingUsers($userId, $page = 1, $perPage = 20) {
    $offset = ($page - 1) * $perPage;
    try {
        $pdo = getDbConnection();
        $sql = "SELECT u.id, u.username, u.avatar, u.avatar_text, u.avatar_bg_color 
                FROM follows f
                JOIN users u ON f.following_id = u.id
                WHERE f.follower_id = ?
                ORDER BY f.created_at DESC
                LIMIT ?, ?";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

        if (isLoggedIn()) {
            $currentUserId = $_SESSION['user_id'];
            foreach ($users as &$user) {
                $user['is_followed_by_me'] = isFollowing($currentUserId, $user['id']);
            }
        } else {
            foreach ($users as &$user) {
                $user['is_followed_by_me'] = false;
            }
        }
        return $users;
    } catch (PDOException $e) {
        return [];
    }
}

function getFollowingCount($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM follows WHERE follower_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function getFollowerUsers($userId, $page = 1, $perPage = 20) {
    $offset = ($page - 1) * $perPage;
    try {
        $pdo = getDbConnection();
        $sql = "SELECT u.id, u.username, u.avatar, u.avatar_text, u.avatar_bg_color 
                FROM follows f
                JOIN users u ON f.follower_id = u.id
                WHERE f.following_id = ?
                ORDER BY f.created_at DESC
                LIMIT ?, ?";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

        if (isLoggedIn()) {
            $currentUserId = $_SESSION['user_id'];
            foreach ($users as &$user) {
                $user['is_followed_by_me'] = isFollowing($currentUserId, $user['id']);
            }
        } else {
            foreach ($users as &$user) {
                $user['is_followed_by_me'] = false;
            }
        }
        return $users;
    } catch (PDOException $e) {
        return [];
    }
}

function getFollowerCount($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM follows WHERE following_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function getUserThemeSettings($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT theme_settings FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        if ($result && !empty($result['theme_settings'])) {
            return json_decode($result['theme_settings'], true) ?: [];
        }
        return [];
    } catch (PDOException $e) {
        return [];
    }
}

function setUserThemeSettings($userId, $settings) {
    try {
        $pdo = getDbConnection();
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare("UPDATE users SET theme_settings = ?, theme = 'custom' WHERE id = ?");
        return $stmt->execute([$json, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function setUserTheme($userId, $theme) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET theme = ? WHERE id = ?");
        return $stmt->execute([$theme, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function updatePost($postId, $data) {
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        
        // 获取旧帖子关联的文件（用于后续删除）
        $oldFiles = getPostAllFiles($postId);
        
        $safe_content = safe_html($data['content']);
        $summary = mb_substr(strip_tags($safe_content), 0, 150, 'UTF-8');
        
        $stmt = $pdo->prepare("UPDATE posts SET title = ?, content = ?, summary = ? WHERE id = ?");
        $stmt->execute([
            $data['title'],
            $safe_content,
            $summary,
            $postId
        ]);
        
        // 注意：post_images 表不会自动更新，因为编辑帖子时图片是通过内容中的 <img> 标签插入的
        // 如果编辑时删除了某些图片，旧图片不再出现在新内容中，需要清理
        // 但 post_images 表原有的记录不会自动删除，需要手动同步。
        // 更简单的方式：编辑时不保留 post_images 表的记录，因为图片已经嵌入内容中。
        // 但为了保持兼容，我们保留原有 post_images 记录，并尝试清理未被引用的图片。
        // 获取新内容中的图片 URL
        $newFiles = [];
        preg_match_all('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $safe_content, $matches);
        foreach ($matches[1] as $src) {
            $src = trim($src);
            if (strpos($src, '/uploads/') === 0 || strpos($src, 'uploads/') === 0) {
                $newFiles[] = $src;
            }
        }
        // 同时获取附件路径（没有变化，但为了安全也检查）
        $stmt = $pdo->prepare("SELECT attachment_path FROM posts WHERE id = ?");
        $stmt->execute([$postId]);
        $post = $stmt->fetch();
        if ($post && !empty($post['attachment_path'])) {
            $newFiles[] = $post['attachment_path'];
        }
        
        // 找出不再使用的文件并删除
        $filesToDelete = array_diff($oldFiles, $newFiles);
        foreach ($filesToDelete as $file) {
            deleteFileIfExists($file);
        }
        
        // 清理 post_images 表中未被引用的图片（如果图片 URL 不在新内容中，则删除记录和文件）
        $stmt = $pdo->prepare("SELECT id, image_url FROM post_images WHERE post_id = ?");
        $stmt->execute([$postId]);
        $existingImages = $stmt->fetchAll();
        foreach ($existingImages as $img) {
            if (!in_array($img['image_url'], $newFiles)) {
                // 删除记录
                $stmtDel = $pdo->prepare("DELETE FROM post_images WHERE id = ?");
                $stmtDel->execute([$img['id']]);
                // 删除文件
                deleteFileIfExists($img['image_url']);
            }
        }
        
        $pdo->commit();
        return ['success' => true, 'message' => '帖子更新成功'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => '更新失败: ' . $e->getMessage()];
    }
}

function getImageUrl($url) {
    // 如果已经是完整 URL（http/https），直接返回
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        $siteUrlParsed = parse_url(SITE_URL);
        $urlParsed = parse_url($url);
        
        if ($urlParsed && $siteUrlParsed && isset($urlParsed['host']) && $urlParsed['host'] == $siteUrlParsed['host']) {
            $path = $urlParsed['path'] ?? '';
            if (isset($urlParsed['query'])) {
                $path .= '?' . $urlParsed['query'];
            }
            return $path;
        }
        return $url;
    }
    
    // 相对路径处理：确保以 / 开头
    $url = ltrim($url, '/');
    return '/' . $url;
}

function createNotification($userId, $type, $actorId, $targetId = null, $data = []) {
    if ($userId == $actorId) {
        return false;
    }
    
    try {
        $pdo = getDbConnection();
        $jsonData = !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, actor_id, target_id, data) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$userId, $type, $actorId, $targetId, $jsonData]);
    } catch (PDOException $e) {
        error_log('创建通知失败：' . $e->getMessage());
        return false;
    }
}

function getUnreadNotificationCount($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetch()['count'];
    } catch (PDOException $e) {
        return 0;
    }
}

function getChatUnreadCount($userId, $username = null) {
    $count = 0;
    $hasMention = false;
    $chatReadStateFile = __DIR__ . '/data/chat/read_state.json';
    $chatGroupsFile = __DIR__ . '/data/chat/groups.json';
    if (!file_exists($chatGroupsFile)) return [$count, $hasMention];
    $readState = file_exists($chatReadStateFile) ? (json_decode(file_get_contents($chatReadStateFile), true) ?: []) : [];
    $groups = json_decode(file_get_contents($chatGroupsFile), true) ?: [];
    if ($username === null && isset($GLOBALS['currentUser'])) {
        $username = $GLOBALS['currentUser'] ?? $_SESSION['username'] ?? '';
    }
    foreach ($groups as $gid => $group) {
        if (!in_array($username, $group['members'] ?? [])) continue;
        $key = $userId . '_' . $gid;
        $lastRead = $readState[$key]['time'] ?? 0;
        $msgFile = __DIR__ . "/data/chat/group_{$gid}.json";
        if (!file_exists($msgFile)) continue;
        $msgs = json_decode(file_get_contents($msgFile), true) ?: [];
        foreach ($msgs as $m) {
            if (($m['time'] ?? 0) > $lastRead && !($m['deleted'] ?? false) && ($m['username'] ?? '') !== $username) {
                $count++;
                if (!$hasMention && !empty($m['reply_to'])) {
                    foreach ($msgs as $orig) {
                        if (($orig['id'] ?? '') === $m['reply_to'] && ($orig['username'] ?? '') === $username) {
                            $hasMention = true;
                            break;
                        }
                    }
                }
            }
        }
    }
    return [$count, $hasMention];
}

function getUserNotifications($userId, $page = 1, $perPage = 20, $type = null) {
    $offset = ($page - 1) * $perPage;
    try {
        $pdo = getDbConnection();
        $sql = "SELECT n.*, u.username as actor_username, u.avatar as actor_avatar, u.avatar_text as actor_avatar_text, u.avatar_bg_color as actor_avatar_bg
                FROM notifications n
                LEFT JOIN users u ON n.actor_id = u.id
                WHERE n.user_id = ?";
        $params = [$userId];
        
        if ($type !== null && $type !== 'all') {
            $sql .= " AND n.type = ?";
            $params[] = $type;
        }
        
        $sql .= " ORDER BY n.created_at DESC LIMIT ?, ?";
        $params[] = $offset;
        $params[] = $perPage;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();
        
        foreach ($notifications as &$notif) {
            if (!empty($notif['data'])) {
                $notif['data'] = json_decode($notif['data'], true);
            } else {
                $notif['data'] = [];
            }
        }
        return $notifications;
    } catch (PDOException $e) {
        return [];
    }
}

function getUserNotificationsCount($userId, $type = null) {
    try {
        $pdo = getDbConnection();
        $sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ?";
        $params = [$userId];
        if ($type !== null && $type !== 'all') {
            $sql .= " AND type = ?";
            $params[] = $type;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetch()['count'];
    } catch (PDOException $e) {
        return 0;
    }
}

function markNotificationAsRead($notificationId, $userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function markAllNotificationsAsRead($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        return $stmt->execute([$userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function deleteNotification($notificationId, $userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

function followAllUsersToFounder() {
    try {
        $pdo = getDbConnection();

        $stmt = $pdo->prepare("SELECT id FROM users WHERE is_founder = 1 LIMIT 1");
        $stmt->execute();
        $founder = $stmt->fetch();
        if (!$founder) {
            return ['success' => false, 'message' => '未找到站长！'];
        }
        $founderId = $founder['id'];

        $stmt = $pdo->prepare("SELECT id FROM users WHERE id != ?");
        $stmt->execute([$founderId]);
        $users = $stmt->fetchAll();

        if (empty($users)) {
            return ['success' => true, 'message' => '没有其他用户需要关注站长。'];
        }

        $insertCount = 0;
        $pdo->beginTransaction();

        foreach ($users as $user) {
            $stmtCheck = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
            $stmtCheck->execute([$user['id'], $founderId]);
            if (!$stmtCheck->fetch()) {
                $stmtInsert = $pdo->prepare("INSERT INTO follows (follower_id, following_id) VALUES (?, ?)");
                if ($stmtInsert->execute([$user['id'], $founderId])) {
                    $insertCount++;
                }
            }
        }

        $pdo->commit();

        return [
            'success' => true,
            'message' => "操作成功！共为 {$insertCount} 个用户添加了关注站长关系。"
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => '操作失败：' . $e->getMessage()];
    }
}

function followUserToFounder($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE is_founder = 1 LIMIT 1");
        $stmt->execute();
        $founder = $stmt->fetch();
        if (!$founder) {
            return false;
        }
        $founderId = $founder['id'];

        $stmtCheck = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
        $stmtCheck->execute([$userId, $founderId]);
        if (!$stmtCheck->fetch()) {
            $stmtInsert = $pdo->prepare("INSERT INTO follows (follower_id, following_id) VALUES (?, ?)");
            return $stmtInsert->execute([$userId, $founderId]);
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function getPostAllImages($postId, $content = null) {
    $images = [];
    
    if ($content === null) {
        $post = getPostById($postId);
        if ($post) {
            $content = $post['content'];
        }
    }
    if (!empty($content)) {
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $src) {
                $src = trim($src);
                if (!empty($src)) {
                    $images[] = $src;
                }
            }
        }
    }
    
    $attachmentImages = getPostImages($postId);
    foreach ($attachmentImages as $img) {
        if (!empty($img['image_url'])) {
            $images[] = $img['image_url'];
        }
    }
    
    $uniqueImages = [];
    foreach ($images as $img) {
        if (!in_array($img, $uniqueImages)) {
            $uniqueImages[] = $img;
        }
    }
    
    return $uniqueImages;
}

function getPostFavoriteCount($postId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE post_id = ?");
        $stmt->execute([$postId]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function isPostFavorited($postId, $userId) {
    if ($userId <= 0) return false;
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id FROM favorites WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$postId, $userId]);
        return (bool) $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }
}

function togglePostFavorite($postId, $userId) {
    if ($userId <= 0) {
        return ['success' => false, 'message' => '请先登录'];
    }
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT id FROM favorites WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$postId, $userId]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            $stmt = $pdo->prepare("DELETE FROM favorites WHERE id = ?");
            $stmt->execute([$exists['id']]);
            $favorited = false;
            $message = '已取消收藏';
        } else {
            $stmt = $pdo->prepare("INSERT INTO favorites (user_id, post_id) VALUES (?, ?)");
            $stmt->execute([$userId, $postId]);
            $favorited = true;
            $message = '收藏成功';
        }
        
        $favoriteCount = getPostFavoriteCount($postId);
        
        $pdo->commit();
        return [
            'success' => true,
            'favorited' => $favorited,
            'message' => $message,
            'favorite_count' => $favoriteCount
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => '操作失败：' . $e->getMessage()];
    }
}

function getUserFavorites($userId, $page = 1, $perPage = 10) {
    $offset = ($page - 1) * $perPage;
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT p.*, u.username, u.avatar, u.avatar_text, u.avatar_bg_color, u.id as user_id, u.is_admin, u.is_founder, u.is_banned, u.exp, c.name as category_name,
                   (SELECT COUNT(*) FROM favorites WHERE post_id = p.id) as favorite_count
            FROM favorites f
            JOIN posts p ON f.post_id = p.id
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE f.user_id = ? AND p.is_approved = 1
            ORDER BY f.created_at DESC
            LIMIT ?, ?
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getUserFavoritesCount($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function searchUsers($keyword, $page = 1, $perPage = 15) {
    $offset = ($page - 1) * $perPage;
    $keywordLike = "%{$keyword}%";
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id, username, email, public_uid, avatar, avatar_text, avatar_bg_color, is_admin, is_founder, points, exp, is_banned, maintenance_bypass FROM users WHERE username LIKE ? LIMIT ?, ?");
        $stmt->execute([$keywordLike, $offset, $perPage]);
        $users = $stmt->fetchAll();
        if (isLoggedIn()) {
            $currentUserId = $_SESSION['user_id'];
            foreach ($users as &$user) {
                $user['is_followed_by_me'] = isFollowing($currentUserId, $user['id']);
            }
        }
        return $users;
    } catch (PDOException $e) {
        return [];
    }
}

function searchUsersCount($keyword) {
    $keywordLike = "%{$keyword}%";
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username LIKE ?");
        $stmt->execute([$keywordLike]);
        return (int) $stmt->fetch()['count'];
    } catch (PDOException $e) {
        return 0;
    }
}

// ==================== 邮箱验证相关函数 ====================

/**
 * 获取 SMTP 配置
 * @return array
 */
function getSmtpConfig() {
    return [
        'host' => getSetting('smtp_host', ''),
        'port' => (int)getSetting('smtp_port', 587),
        'encryption' => getSetting('smtp_encryption', 'tls'), // tls, ssl, null
        'username' => getSetting('smtp_username', ''),
        'password' => getSetting('smtp_password', ''),
        'from_email' => getSetting('smtp_from_email', ''),
        'from_name' => getSetting('smtp_from_name', '主播模拟器论坛')
    ];
}

/**
 * 保存 SMTP 配置
 * @param array $config
 * @return bool
 */
function saveSmtpConfig($config) {
    foreach ($config as $key => $value) {
        if (!setSetting('smtp_' . $key, $value)) {
            return false;
        }
    }
    return true;
}

/**
 * 是否开启邮箱验证（注册时）
 * @return bool
 */
function isEmailVerificationEnabled() {
    return getSetting('email_verification_enabled', '0') === '1';
}

/**
 * 设置邮箱验证开关
 * @param bool $enable
 * @return bool
 */
function setEmailVerificationEnabled($enable) {
    return setSetting('email_verification_enabled', $enable ? '1' : '0');
}

/**
 * 生成6位数字验证码
 * @return string
 */
function generateEmailVerificationCode() {
    return sprintf("%06d", mt_rand(0, 999999));
}

/**
 * 存储验证码到 session（带过期时间，默认5分钟）
 * @param string $email
 * @param string $code
 * @param int $expireSeconds
 */
function storeEmailVerificationCode($email, $code, $expireSeconds = 300) {
    // 确保 session 已启动
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
        cleanup_expired_sessions();
    }
    $_SESSION['email_verification'] = [
        'email' => $email,
        'code' => $code,
        'expires' => time() + $expireSeconds
    ];
}

/**
 * 校验验证码
 * @param string $email
 * @param string $code
 * @return bool
 */
function verifyEmailVerificationCode($email, $code) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
        cleanup_expired_sessions();
    }
    if (!isset($_SESSION['email_verification'])) {
        return false;
    }
    $data = $_SESSION['email_verification'];
    if ($data['expires'] < time()) {
        unset($_SESSION['email_verification']);
        return false;
    }
    if ($data['email'] !== $email || $data['code'] !== $code) {
        return false;
    }
    // 验证成功，清除验证码
    unset($_SESSION['email_verification']);
    return true;
}

/**
 * 发送邮件（使用 SMTP，适配 PHPMailer 7.x，无 use 语句）
 * @param string $to 收件人
 * @param string $subject 主题
 * @param string $body 正文（HTML）
 * @return array ['success' => bool, 'message' => string]
 */
function sendEmail($to, $subject, $body) {
    $config = getSmtpConfig();
    if (empty($config['host']) || empty($config['username']) || empty($config['password']) || empty($config['from_email'])) {
        return ['success' => false, 'message' => 'SMTP 未配置或配置不完整'];
    }
    
    // 加载 PHPMailer 类（请确保 PHPMailer 目录存在且文件正确）
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';
    require_once __DIR__ . '/PHPMailer/Exception.php';
    
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        if ($config['encryption'] === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($config['encryption'] === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
        }
        $mail->Port       = $config['port'];
        
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        $mail->send();
        return ['success' => true, 'message' => '邮件发送成功'];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return ['success' => false, 'message' => '邮件发送失败: ' . $mail->ErrorInfo];
    }
}

/**
 * 构建统一的赛博科技风格验证码邮件模板
 * @param string $code 验证码
 * @param string $greetingName 用户称呼（用于问候语，如"用户"）
 * @param string $brandTag 品牌标语（如"安全验证系统"）
 * @param string $messageText 补充说明文本
 * @return string 生成的HTML
 */
function buildCyberCodeEmail($code, $greetingName = '用户', $brandTag = '安全验证系统', $messageText = '') {
    $digits = str_split($code);
    $digitBlocks = '';
    foreach ($digits as $d) {
        $digitBlocks .= '<td style="width:48px; height:58px; background: rgba(0,240,255,0.06); border:1.5px solid rgba(0,240,255,0.25); border-radius:0; text-align:center; font-family: \'Courier New\', monospace; font-size:24px; font-weight:700; color: #00f0ff; text-shadow: 0 0 15px rgba(0,240,255,0.4);">' . htmlspecialchars($d) . '</td>';
    }
    
    $template = <<<EMAIL
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background:#0a0e17; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Noto Sans SC', sans-serif;">
<div style="max-width:520px; margin:20px auto; background: rgba(10,14,23,0.9); border:1px solid rgba(0,240,255,0.25); border-radius:0; overflow:hidden; position:relative; box-shadow:0 0 60px rgba(0,240,255,0.06);">
  <!-- 扫描线装饰 -->
  <div style="position:absolute; top:0; left:0; width:100%; height:2px; background: #00f0ff; z-index:2;"></div>
  <!-- 角标装饰 -->
  <div style="position:absolute; top:-1px; left:-1px; width:24px; height:24px; border:2px solid #00f0ff; border-right:none; border-bottom:none; border-radius:0; z-index:2;"></div>
  <div style="position:absolute; top:-1px; right:-1px; width:24px; height:24px; border:2px solid #00f0ff; border-left:none; border-bottom:none; border-radius:0; z-index:2;"></div>
  <div style="position:absolute; bottom:-1px; left:-1px; width:24px; height:24px; border:2px solid #00f0ff; border-right:none; border-top:none; border-radius:0; z-index:2;"></div>
  <div style="position:absolute; bottom:-1px; right:-1px; width:24px; height:24px; border:2px solid #00f0ff; border-left:none; border-top:none; border-radius:0; z-index:2;"></div>

  <!-- 头部：去掉信封图标，压缩上方空间 -->
  <div style="background:rgba(0,240,255,0.08); border-bottom:1px solid rgba(0,240,255,0.1); padding:16px 24px 16px; text-align:center; position:relative; overflow:hidden;">
    <div style="position:absolute; top:-50%; left:50%; transform:translateX(-50%); width:200px; height:200px; background:radial-gradient(circle, rgba(0,240,255,0.15), transparent 70%); pointer-events:none;"></div>
    <div style="font-family: 'Orbitron', 'Courier New', monospace; font-size:20px; font-weight:700; color:#fff; letter-spacing:4px; text-shadow:0 0 20px rgba(0,240,255,0.4);">CYBER TECH</div>
    <div style="font-size:11px; color:rgba(0,240,255,0.5); letter-spacing:3px; margin-top:2px;">$brandTag</div>
  </div>

  <!-- 正文 -->
  <div style="padding:28px 24px;">
    <div style="font-size:15px; color:#fff; margin-bottom:16px; font-weight:500;">您好，<span style="color:#00f0ff; font-weight:700;">$greetingName</span></div>
    <div style="font-size:14px; color:rgba(255,255,255,0.65); line-height:1.8; margin-bottom:24px;">
      $messageText
      该验证码将在 <strong style="color:#00f0ff;">5 分钟</strong> 后失效。
    </div>

    <!-- 验证码展示区 -->
    <div style="background:rgba(0,240,255,0.06); border:1px solid rgba(0,240,255,0.15); border-radius:0; padding:24px 20px; margin-bottom:24px; text-align:center; position:relative; overflow:hidden;">
      <div style="font-size:12px; color:rgba(0,240,255,0.6); letter-spacing:2px; margin-bottom:14px; display:flex; align-items:center; justify-content:center; gap:8px;">
        <span style="width:20px; height:1px; background:#00f0ff;"></span>
        验证码
        <span style="width:20px; height:1px; background:#00f0ff;"></span>
      </div>
      <table align="center" cellspacing="0" cellpadding="0" style="margin:0 auto 14px;">
            <tr>{$digitBlocks}</tr>
        </table>
      <div style="font-size:12px; color:rgba(255,255,255,0.35);">
        有效期剩余 <span style="color:#00f0ff; font-family: 'Courier New', monospace; font-weight:700;">5:00</span>
      </div>
    </div>

    <!-- 安全提示：去掉绿框和盾牌图标 -->
    <div style="font-size:12px; color:rgba(255,255,255,0.5); line-height:1.7; margin-bottom:20px;">
      <strong style="color:#00ff88; font-weight:500;">安全提示：</strong>请勿将验证码透露给他人。主播模拟器论坛工作人员绝不会向您索要验证码。如您未发起此操作，请忽略此邮件。
    </div>

    <div style="height:1px; background:rgba(0,240,255,0.15); margin:20px 0;"></div>
    <div style="font-size:13px; color:rgba(255,255,255,0.4); text-align:center; margin-bottom:0;">
      如果验证码已过期，您可以返回相应页面重新获取。如有任何疑问，请联系我们的技术支持团队。
    </div>
  </div>

  <!-- 底部 -->
  <div style="padding:0 24px 24px; text-align:center;">
    <div style="font-family: 'Orbitron', 'Courier New', monospace; font-size:13px; color:rgba(0,240,255,0.4); letter-spacing:2px; margin-bottom:8px;">主播模拟器论坛</div>
    <div style="font-size:11px; color:rgba(255,255,255,0.25); line-height:1.8;">
      此邮件由系统自动发送，请勿直接回复
    </div>
  </div>
</div>
</body>
</html>
EMAIL;
    return $template;
}



/**
 * 发送注册验证码邮件
 * @param string $email
 * @param string $code
 * @return array
 */
function sendVerificationEmail($email, $code) {
    $subject = '【主播模拟器论坛】邮箱验证码';
    $body = buildCyberCodeEmail(
        $code,
        '用户',
        '安全验证系统',
        '您正在注册主播模拟器论坛账户。为保护您的账户安全，请使用以下验证码完成验证。'
    );
    return sendEmail($email, $subject, $body);
}

/**
 * 彻底删除用户及其所有关联数据（包括帖子、评论、上传文件等）
 * @param int $userId
 * @return bool
 */
function forceDeleteUser($userId) {
    // 禁止删除站长
    if (isFounder($userId)) {
        return false;
    }

    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        
        // 获取用户的所有帖子ID，以便删除关联文件
        $stmt = $pdo->prepare("SELECT id FROM posts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $postIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($postIds as $postId) {
            // 删除帖子的关联文件
            $files = getPostAllFiles($postId);
            foreach ($files as $file) {
                deleteFileIfExists($file);
            }
        }
        
        // 获取用户头像和背景图并删除
        $stmt = $pdo->prepare("SELECT avatar, profile_background FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user) {
            if (!empty($user['avatar'])) {
                deleteFileIfExists($user['avatar']);
            }
            if (!empty($user['profile_background'])) {
                deleteFileIfExists($user['profile_background']);
            }
        }
        
        // 删除用户相关的通知
        $pdo->prepare("DELETE FROM notifications WHERE user_id = ? OR actor_id = ?")->execute([$userId, $userId]);
        // 删除用户相关的收藏
        $pdo->prepare("DELETE FROM favorites WHERE user_id = ?")->execute([$userId]);
        // 删除用户相关的点赞
        $pdo->prepare("DELETE FROM post_likes WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM comment_likes WHERE user_id = ?")->execute([$userId]);
        // 删除用户相关的关注关系
        $pdo->prepare("DELETE FROM follows WHERE follower_id = ? OR following_id = ?")->execute([$userId, $userId]);
        // 删除用户的打赏记录
        $pdo->prepare("DELETE FROM tips WHERE from_user_id = ? OR to_user_id = ?")->execute([$userId, $userId]);
        // 删除用户的每日签到记录
        $pdo->prepare("DELETE FROM daily_signins WHERE user_id = ?")->execute([$userId]);
        // 删除用户的评论
        $pdo->prepare("DELETE FROM comments WHERE user_id = ?")->execute([$userId]);
        // 删除用户的帖子
        $pdo->prepare("DELETE FROM posts WHERE user_id = ?")->execute([$userId]);
        // 最后删除用户
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("删除用户 $userId 失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 验证用户密码（用于敏感操作前确认身份）
 * @param int $userId
 * @param string $password
 * @return bool
 */
function verifyUserPassword($userId, $password) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user && verifyPassword($password, $user['password'])) {
            return true;
        }
        return false;
    } catch (PDOException $e) {
        return false;
    }
}

// ==================== 打赏积分功能 ====================

/**
 * 发送打赏
 * @param int $fromUserId 打赏者ID
 * @param int $toUserId 被打赏者ID（帖子作者）
 * @param int $postId 帖子ID
 * @param int $amount 打赏积分数量
 * @return array
 */
function sendTip($fromUserId, $toUserId, $postId, $amount) {
    if ($fromUserId == $toUserId) {
        return ['success' => false, 'message' => '不能给自己打赏'];
    }
    
    if ($amount <= 0) {
        return ['success' => false, 'message' => '打赏积分必须大于0'];
    }
    
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        
        // 检查打赏者积分是否足够
        $stmt = $pdo->prepare("SELECT points FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$fromUserId]);
        $fromUser = $stmt->fetch();
        if (!$fromUser) {
            $pdo->rollBack();
            return ['success' => false, 'message' => '用户不存在'];
        }
        
        if ($fromUser['points'] < $amount) {
            $pdo->rollBack();
            return ['success' => false, 'message' => '积分不足，当前积分：' . $fromUser['points']];
        }
        
        // 检查被打赏者是否存在
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$toUserId]);
        $toUser = $stmt->fetch();
        if (!$toUser) {
            $pdo->rollBack();
            return ['success' => false, 'message' => '目标用户不存在'];
        }
        
        // 检查帖子是否存在且属于被打赏者
        $stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND user_id = ?");
        $stmt->execute([$postId, $toUserId]);
        $post = $stmt->fetch();
        if (!$post) {
            $pdo->rollBack();
            return ['success' => false, 'message' => '帖子不存在'];
        }
        
        // 扣除打赏者积分
        $stmt = $pdo->prepare("UPDATE users SET points = points - ? WHERE id = ?");
        $stmt->execute([$amount, $fromUserId]);
        
        // 增加被打赏者积分
        $stmt = $pdo->prepare("UPDATE users SET points = points + ? WHERE id = ?");
        $stmt->execute([$amount, $toUserId]);
        
        // 插入打赏记录
        $stmt = $pdo->prepare("INSERT INTO tips (from_user_id, to_user_id, post_id, amount) VALUES (?, ?, ?, ?)");
        $stmt->execute([$fromUserId, $toUserId, $postId, $amount]);
        
        $pdo->commit();
        
        // 发送通知
        createNotification($toUserId, 'tip', $fromUserId, $postId, ['amount' => $amount]);
        
        return [
            'success' => true,
            'message' => '打赏成功！您打赏了 ' . $amount . ' 积分'
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('打赏失败: ' . $e->getMessage());
        return ['success' => false, 'message' => '打赏失败：' . $e->getMessage()];
    }
}

/**
 * 获取帖子的打赏记录
 * @param int $postId
 * @param int $limit
 * @return array
 */
function getTipRecords($postId, $limit = 20) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT t.*, u.username, u.avatar 
            FROM tips t
            JOIN users u ON t.from_user_id = u.id
            WHERE t.post_id = ?
            ORDER BY t.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$postId, $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * 获取帖子收到的总打赏积分
 * @param int $postId
 * @return int
 */
function getPostTotalTips($postId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM tips WHERE post_id = ?");
        $stmt->execute([$postId]);
        $result = $stmt->fetch();
        return (int) $result['total'];
    } catch (PDOException $e) {
        return 0;
    }
}

// ==================== 伪静态 URL 功能 ====================

/**
 * 检查是否启用伪静态
 */
function isPrettyUrlEnabled() {
    return getSetting('pretty_url_enabled', '0') === '1';
}

/**
 * 设置伪静态开关
 */
function setPrettyUrlEnabled($enable) {
    return setSetting('pretty_url_enabled', $enable ? '1' : '0');
}

/**
 * 生成 URL（支持伪静态和动态切换）
 * 
 * 用法：
 * url('category', ['slug' => 'mod'])                          -> /mod 或 category.php?slug=mod
 * url('category', ['slug' => 'mod'], ['page' => 2, 'sort' => 'popular']) -> /mod/page/2?sort=popular 或 category.php?slug=mod&page=2&sort=popular
 * url('post', ['id' => 123])                                  -> /post/123 或 post.php?id=123
 * url('user', ['id' => 5])                                    -> /user/5 或 user.php?id=5
 * url('search', [], ['q' => '关键词', 'tab' => 'users'])       -> /search?q=... 或 search.php?q=...
 * url('follows', ['type' => 'following', 'id' => 5])          -> /follows/following/5 或 follows.php?type=following&id=5
 * url('index')                                                -> / 或 index.php
 * url('favorites')                                            -> /favorites 或 favorites.php
 * url('notifications')                                        -> /notifications 或 notifications.php
 * url('interactive_messages')                                 -> /interactive_messages 或 interactive_messages.php
 * url('create_post', [], ['category' => 'mod'])               -> /create_post?category=mod 或 create_post.php?category=mod
 * url('settings')                                             -> /settings 或 settings.php
 * url('profile')                                              -> /profile 或 profile.php
 * url('admin', [], ['tab' => 'users'])                        -> /zbgame_admin_8f3d?tab=users 或 zbgame_admin_8f3d.php?tab=users
 * url('reply_comment', [], ['post_id' => 1, 'comment_id' => 2]) -> /reply_comment?post_id=1&comment_id=2
 * url('chatroom')                                             -> /chatroom 或 chatroom.php
 * url('cleanup_files')                                        -> /cleanup_files 或 cleanup_files.php
 *
 * @param string $route 路由名称
 * @param array $params 路径参数（用于伪静态的路径部分）
 * @param array $query  查询参数（附加在 ? 后面，静态动态均生效）
 * @return string
 */
function url($route, $params = [], $query = []) {
    static $prettyUrlEnabled = null;
    if ($prettyUrlEnabled === null) {
        $prettyUrlEnabled = isPrettyUrlEnabled();
    }

    // 构建查询字符串
    $queryString = !empty($query) ? '?' . http_build_query($query) : '';

    if (!$prettyUrlEnabled) {
        // 动态 URL
        switch ($route) {
            case 'index':
                return 'index.php' . $queryString;
            case 'category':
                return 'category.php?' . http_build_query(array_merge($params, $query));
            case 'post':
                return 'post.php?' . http_build_query(array_merge($params, $query));
            case 'user':
                return 'user.php?' . http_build_query(array_merge($params, $query));
            case 'search':
                return 'search.php?' . http_build_query(array_merge($params, $query));
            case 'follows':
                return 'follows.php?' . http_build_query(array_merge($params, $query));
            case 'favorites':
                return 'favorites.php' . $queryString;
            case 'notifications':
                return 'notifications.php' . $queryString;
            case 'interactive_messages':
                return 'interactive_messages.php' . $queryString;
            case 'create_post':
                return 'create_post.php?' . http_build_query(array_merge($params, $query));
            case 'settings':
                return 'settings.php' . $queryString;
            case 'profile':
                return 'profile.php' . $queryString;
            case 'admin':
                return ADMIN_FILE . '.php' . $queryString;
            case 'reply_comment':
                return 'reply_comment.php?' . http_build_query(array_merge($params, $query));
            case 'chatroom':
                return 'chatroom.php' . $queryString;
            case 'group_chat':
                return 'group_chat.php' . $queryString;
            case 'group_list':
                return 'group_list.php' . $queryString;
            case 'pm_chat':
                return 'pm_chat.php' . $queryString;
            case 'pm_list':
                return 'pm_list.php' . $queryString;
            case 'cleanup_files':
                return 'cleanup_files.php' . $queryString;
            case 'level':
                return 'level.php' . $queryString;
            default:
                return $route . '.php' . $queryString;
        }
    }

    // 伪静态 URL
    $path = '';
    switch ($route) {
        case 'index':
            $path = '/';
            break;
        case 'category':
            $slug = $params['slug'] ?? '';
            if ($slug) {
                $path = '/' . $slug;
                unset($params['slug']);
            } else {
                $path = '/category';
            }
            if (!empty($params)) {
                $query = array_merge($params, $query);
            }
            break;
        case 'post':
            $id = $params['id'] ?? 0;
            $path = $id ? '/post/' . $id : '/post';
            unset($params['id']);
            if (!empty($params)) {
                $query = array_merge($params, $query);
            }
            break;
        case 'user':
            $id = $params['id'] ?? 0;
            $path = $id ? '/user/' . $id : '/user';
            unset($params['id']);
            if (!empty($params)) {
                $query = array_merge($params, $query);
            }
            break;
        case 'search':
            $path = '/search';
            break;
        case 'follows':
            $type = $params['type'] ?? 'following';
            $id = $params['id'] ?? 0;
            $path = $id ? '/follows/' . $type . '/' . $id : '/follows/' . $type;
            unset($params['type'], $params['id']);
            if (!empty($params)) {
                $query = array_merge($params, $query);
            }
            break;
        case 'favorites':
            $path = '/favorites';
            break;
        case 'notifications':
            $path = '/notifications';
            break;
        case 'interactive_messages':
            $path = '/interactive_messages';
            break;
        case 'create_post':
            $path = '/create_post';
            break;
        case 'settings':
            $path = '/settings';
            break;
        case 'profile':
            $path = '/profile';
            break;
        case 'admin':
            $path = '/' . ADMIN_FILE;
            break;
        case 'reply_comment':
            $path = '/reply_comment';
            break;
        case 'chatroom':
            $path = '/chatroom';
            break;
        case 'group_chat':
            $path = '/group_chat';
            break;
        case 'group_list':
            $path = '/group_list';
            break;
        case 'pm_chat':
            $path = '/pm_chat';
            break;
        case 'pm_list':
            $path = '/pm_list';
            break;
        case 'cleanup_files':
            $path = '/cleanup_files';
            break;
        case 'level':
            $path = '/level';
            break;
        default:
            $path = '/' . $route;
            break;
    }

    return $path . $queryString;
}

/**
 * 显示错误页面（毛玻璃卡片风格，无白框，纯黑按钮，高度自适应不滚动）
 * @param string $title 标题
 * @param string $message 错误信息
 * @param string $backUrl 返回链接
 */
function show_error_page($title, $message, $backUrl = 'index.php') {
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - 主播模拟器论坛</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            background: #dfdfdf;
            overflow: hidden;
        }
        .container {
            position: relative;
            height: 100vh;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 0;
            background-color: #dfdfdf;
            overflow: hidden;
        }
        .container::after {
            content: " ";
            position: absolute;
            height: 150px;
            width: 150px;
            left: 50%;
            top: 25%;
            transform: translateX(-100%);
            background: orange;
            border-radius: 50%;
            z-index: -1;
            border: 2px solid #ffffffa6;
            box-shadow: inset 10px 0px 20px #fff;

        }
        .container::before {
            content: " ";
            position: absolute;
            height: 80px;
            width: 80px;
            left: 46%;
            bottom: 25%;
            transform: translateX(-100%);
            background: orange;
            border-radius: 50%;
            z-index: -1;
            border: 2px solid #ffffffa6;
            box-shadow: inset 10px 0px 20px #fff;
        }
        .card {
            width: 260px;
            padding: 20px;
            border-radius: 0;
            position: relative;
            overflow: hidden;
            z-index: 1;
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: none;
            box-shadow: none;
            text-align: center;
        }
        .card::after {
            z-index: -1;
            content: " ";
            position: absolute;
            width: 150%;
            top: 0;
            left: 0;
            height: 10px;
            background: rgba(255,255,255,0.1);
            transform: rotateZ(50deg);
            filter: blur(30px);

        }
        .innerText {
            color: transparent;
            -webkit-background-clip: text;
            background: rgb(0, 0, 0);
            font-size: 28px;
            font-weight: 800;
            line-height: 1.2em;
            margin: 10px 0px;
            background-clip: text;
        }
        .desc {
            padding: 4px;
            color: #3a3939;
            font-size: 14px;
            line-height: 1.4;
            margin: 10px 0;
        }
        .back-link {
            display: inline-block;
            margin-top: 15px;
            padding: 8px 20px;
            background: #000000;
            color: #ffffff;
            border-radius: 0;
            text-decoration: none;
            font-weight: 600;
            transition: opacity 0.3s;
        }
        .back-link:hover {
            opacity: 0.8;
        }


    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <p class="innerText"><?php echo htmlspecialchars($title); ?></p>
            <p class="desc"><?php echo htmlspecialchars($message); ?></p>
            <a href="<?php echo htmlspecialchars($backUrl); ?>" class="back-link">返回首页</a>
        </div>
    </div>
</body>
</html><?php
    exit;
}

// ==================== 密码重置 / 修改密码验证码管理 ====================

function storeResetPasswordCode($email, $code, $expireSeconds = 300) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    cleanup_expired_sessions();
    $_SESSION['reset_password'] = [
        'email' => $email,
        'code' => $code,
        'expires' => time() + $expireSeconds
    ];
}
function verifyResetPasswordCode($email, $code) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    cleanup_expired_sessions();
    if (!isset($_SESSION['reset_password'])) return false;
    $data = $_SESSION['reset_password'];
    if ($data['expires'] < time()) {
        unset($_SESSION['reset_password']);
        return false;
    }
    if ($data['email'] !== $email || $data['code'] !== $code) return false;
    return true;
}
function clearResetPasswordCode($email) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (isset($_SESSION['reset_password']) && $_SESSION['reset_password']['email'] === $email) {
        unset($_SESSION['reset_password']);
    }
}
function storeChangePasswordCode($email, $code, $expireSeconds = 300) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    cleanup_expired_sessions();
    $_SESSION['change_password'] = [
        'email' => $email,
        'code' => $code,
        'expires' => time() + $expireSeconds
    ];
}
function verifyChangePasswordCode($email, $code) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    cleanup_expired_sessions();
    if (!isset($_SESSION['change_password'])) return false;
    $data = $_SESSION['change_password'];
    if ($data['expires'] < time()) {
        unset($_SESSION['change_password']);
        return false;
    }
    if ($data['email'] !== $email || $data['code'] !== $code) return false;
    return true;
}
function clearChangePasswordCode($email) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (isset($_SESSION['change_password']) && $_SESSION['change_password']['email'] === $email) {
        unset($_SESSION['change_password']);
    }
}

// ==================== 聊天室历史数据同步 ====================

/**
 * 同步聊天室历史消息中的用户名（用户改名后调用）
 * @param string $oldUsername 旧用户名
 * @param string $newUsername 新用户名
 * @return bool
 */
function syncChatUsername($oldUsername, $newUsername) {
    $chatDataDir = __DIR__ . '/data/chat/';
    if (!is_dir($chatDataDir)) {
        return false;
    }
    
    $groupFiles = glob($chatDataDir . 'group_*.json');
    $updated = false;
    
    foreach ($groupFiles as $file) {
        $content = file_get_contents($file);
        if ($content === false) continue;
        $messages = json_decode($content, true);
        if (!is_array($messages)) continue;
        
        $changed = false;
        foreach ($messages as &$msg) {
            if (isset($msg['username']) && $msg['username'] === $oldUsername) {
                $msg['username'] = $newUsername;
                $changed = true;
            }
        }
        if ($changed) {
            file_put_contents($file, json_encode($messages, JSON_UNESCAPED_UNICODE));
            $updated = true;
        }
    }
    
    // 同时更新好友请求文件中的用户名（如果存在）
    $friendRequestsFile = $chatDataDir . 'friend_requests.json';
    if (file_exists($friendRequestsFile)) {
        $requests = json_decode(file_get_contents($friendRequestsFile), true);
        if (is_array($requests)) {
            $changed = false;
            foreach ($requests as &$req) {
                if (isset($req['sender']) && $req['sender'] === $oldUsername) {
                    $req['sender'] = $newUsername;
                    $changed = true;
                }
                if (isset($req['receiver']) && $req['receiver'] === $oldUsername) {
                    $req['receiver'] = $newUsername;
                    $changed = true;
                }
            }
            if ($changed) {
                file_put_contents($friendRequestsFile, json_encode($requests, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    
    // 更新在线用户列表中的用户名
    $onlineFile = $chatDataDir . 'online.json';
    if (file_exists($onlineFile)) {
        $online = json_decode(file_get_contents($onlineFile), true);
        if (is_array($online) && isset($online[$oldUsername])) {
            $online[$newUsername] = $online[$oldUsername];
            unset($online[$oldUsername]);
            file_put_contents($onlineFile, json_encode($online));
        }
    }
    
    return $updated;
}

/**
 * 同步聊天室历史消息中的用户头像（用户更新头像后调用）
 * @param string $username 用户名
 * @param string $newAvatarUrl 新头像URL
 * @return bool
 */
function syncChatAvatar($username, $newAvatarUrl) {
    $chatDataDir = __DIR__ . '/data/chat/';
    if (!is_dir($chatDataDir)) {
        return false;
    }
    
    $groupFiles = glob($chatDataDir . 'group_*.json');
    $updated = false;
    
    foreach ($groupFiles as $file) {
        $content = file_get_contents($file);
        if ($content === false) continue;
        $messages = json_decode($content, true);
        if (!is_array($messages)) continue;
        
        $changed = false;
        foreach ($messages as &$msg) {
            if (isset($msg['username']) && $msg['username'] === $username) {
                $msg['avatar'] = $newAvatarUrl;
                $changed = true;
            }
        }
        if ($changed) {
            file_put_contents($file, json_encode($messages, JSON_UNESCAPED_UNICODE));
            $updated = true;
        }
    }
    
    return $updated;
}

// ==================== 提前启动 Session 并生成全局 CSRF Token ====================
// 在输出任何内容之前，如果 session 未启动则启动，并生成 CSRF token 供后续使用。
if (!defined('STDIN') && PHP_SAPI !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        cleanup_expired_sessions();
    }
    // 生成全局 token
    if (!isset($GLOBALS['_csrf_token_page'])) {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $GLOBALS['_csrf_token_page'] = $_SESSION['csrf_token'];
    }
}

// ==================== 移动帖子到其他分类（管理员/站长） ====================
/**
 * 移动帖子到新的分类（mod/exchange/chat）
 * @param int $postId 帖子ID
 * @param string $targetSlug 目标分类 slug
 * @return array ['success' => bool, 'message' => string]
 */
function movePostToCategory($postId, $targetSlug) {
    try {
        $pdo = getDbConnection();
        
        // 获取目标分类信息
        $targetCategory = getCategoryBySlug($targetSlug);
        if (!$targetCategory) {
            return ['success' => false, 'message' => '目标分类不存在'];
        }
        
        // 获取帖子当前分类
        $stmt = $pdo->prepare("SELECT category_id FROM posts WHERE id = ? LIMIT 1");
        $stmt->execute([$postId]);
        $current = $stmt->fetch();
        if (!$current) {
            return ['success' => false, 'message' => '帖子不存在'];
        }
        
        // 如果已经在目标分类，无需移动
        if ($current['category_id'] == $targetCategory['id']) {
            return ['success' => false, 'message' => '帖子已在目标分类中'];
        }
        
        // 执行移动
        $stmt = $pdo->prepare("UPDATE posts SET category_id = ? WHERE id = ?");
        if ($stmt->execute([$targetCategory['id'], $postId])) {
            return ['success' => true, 'message' => '帖子已成功移动到 ' . $targetCategory['name']];
        } else {
            return ['success' => false, 'message' => '移动失败，请稍后重试'];
        }
    } catch (PDOException $e) {
        error_log('移动帖子失败: ' . $e->getMessage());
        return ['success' => false, 'message' => '数据库错误：' . $e->getMessage()];
    }
}

/**
 * 获取今日发帖数
 */
function getTodayPostCount($categoryId = null) {
    try {
        $pdo = getDbConnection();
        $today = date('Y-m-d');
        if ($categoryId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM posts WHERE category_id = ? AND DATE(created_at) = ? AND is_approved = 1");
            $stmt->execute([$categoryId, $today]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM posts WHERE DATE(created_at) = ? AND is_approved = 1");
            $stmt->execute([$today]);
        }
        return intval($stmt->fetch()['count']);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 获取分类今日浏览数
 */
function getCategoryTodayViews($categoryId = null) {
    try {
        $pdo = getDbConnection();
        if ($categoryId) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(pdv.view_count), 0) as total FROM post_daily_views pdv INNER JOIN posts p ON pdv.post_id = p.id WHERE p.category_id = ? AND pdv.view_date = CURDATE()");
            $stmt->execute([$categoryId]);
        } else {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(view_count), 0) as total FROM post_daily_views WHERE view_date = CURDATE()");
            $stmt->execute();
        }
        return intval($stmt->fetch()['total']);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 获取分类热度
 */
function getCategoryHeat($categoryId = null) {
    try {
        $pdo = getDbConnection();
        $today = date('Y-m-d');
        if ($categoryId) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(pdv.view_count), 0) + COALESCE((SELECT COUNT(*) FROM posts WHERE category_id = ? AND DATE(created_at) = ? AND is_approved = 1), 0) * 10 as heat FROM post_daily_views pdv INNER JOIN posts p ON pdv.post_id = p.id WHERE p.category_id = ? AND pdv.view_date = ?");
            $stmt->execute([$categoryId, $today, $categoryId, $today]);
        } else {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(view_count), 0) + COALESCE((SELECT COUNT(*) FROM posts WHERE DATE(created_at) = ? AND is_approved = 1), 0) * 10 as heat FROM post_daily_views WHERE view_date = ?");
            $stmt->execute([$today, $today]);
        }
        return intval($stmt->fetch()['heat']);
    } catch (Exception $e) {
        return 0;
    }
}


/**
 * 获取通知附带的内容（帖子标题、评论内容）
 */
function getNotificationContent($n) {
    $result = [
        'post_title' => '',
        'comment_content' => '',
    ];
    try {
        $pdo = getDbConnection();
        $data = !empty($n['data']) ? json_decode($n['data'], true) : [];
        
        $postId = intval($data['post_id'] ?? $n['target_id'] ?? 0);
        if ($postId > 0) {
            $stmt = $pdo->prepare("SELECT title FROM posts WHERE id = ?");
            $stmt->execute([$postId]);
            $post = $stmt->fetch();
            if ($post) {
                $result['post_title'] = mb_substr(strip_tags($post['title']), 0, 100);
            }
        }
        
        $commentId = intval($data['comment_id'] ?? 0);
        if ($commentId > 0) {
            $stmt = $pdo->prepare("SELECT content FROM comments WHERE id = ?");
            $stmt->execute([$commentId]);
            $comment = $stmt->fetch();
            if ($comment) {
                $result['comment_content'] = mb_substr(strip_tags($comment['content']), 0, 200);
            }
        }
    } catch (Exception $e) {
        // ignore
    }
    return $result;
}

/**
 * 获取互动消息（非系统通知）的未读数
 */
function getUnreadInteractionCount($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0 AND type IN ('like_post','like_comment','comment','reply','follow','tip')");
        $stmt->execute([$userId]);
        return (int)$stmt->fetch()['count'];
    } catch (PDOException $e) {
        return 0;
    }
}

