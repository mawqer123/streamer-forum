<?php
/**
 * 主播模拟器论坛 - APP版 API
 *
 * 为APP客户端提供RESTful API接口
 * 认证方式:Bearer Token(Authorization header)
 *
 * 路由规则:
 *   /api/app/* → 本文件处理
 *   需要在nginx rewrite中添加: rewrite ^/api/app(/(.*))?$ /api/index.php last;
 */

// ==================== 初始化 ====================
require_once __DIR__ . '/../functions.php';

// 关闭输出缓冲,确保JSON响应干净
while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(0);
ini_set('display_errors', '0');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 处理OPTIONS预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ==================== 常量 ====================
define('API_TOKEN_EXPIRE_DAYS', 30); // Token 30天过期
define('DEFAULT_PAGE_SIZE', 20);
define('MAX_PAGE_SIZE', 50);

// ==================== 工具函数 ====================

/**
 * 成功响应
 */
function apiSuccess($data = null, $message = 'success', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'code' => $code,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * 错误响应
 */
function apiError($message = '未知错误', $code = 400) {
    http_response_code($code);
    echo json_encode([
        'code' => $code,
        'message' => $message,
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * 检查图片文件是否存在于服务器上
 */
function checkImageFileExists($imageUrl) {
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    
    // 处理绝对URL: https://zbgame.hyperspark.cn/uploads/xxx.jpg
    $parsed = parse_url($imageUrl);
    if (isset($parsed['scheme']) && isset($parsed['host'])) {
        // 如果是本服务器的地址,提取路径
        $requestHost = $_SERVER['HTTP_HOST'] ?? '';
        $parsedHost = $parsed['host'];
        if (strpos($parsedHost, $requestHost) !== false || strpos($parsedHost, 'zbgame.hyperspark.cn') !== false) {
            $filePath = $parsed['path'];
        } else {
            // 外部URL,无法检查,标记为有效让客户端尝试加载
            return ['url' => $imageUrl, 'valid' => true];
        }
    } else {
        // 相对路径: /uploads/posts/xxx.jpg
        $filePath = $imageUrl;
    }
    
    // 清理路径
    $filePath = ltrim($filePath, '/');
    $fullPath = $docRoot . '/' . $filePath;
    
    // 安全检查：只允许 uploads 目录下的文件
    $realUploads = realpath($docRoot . '/uploads');
    $realFullPath = realpath($fullPath);
    
    if ($realFullPath === false || strpos($realFullPath, $realUploads) !== 0) {
        // 文件不存在或不在uploads目录内
        return ['url' => $imageUrl, 'valid' => false];
    }
    
    if (file_exists($realFullPath) && is_file($realFullPath)) {
        return ['url' => $imageUrl, 'valid' => true];
    }
    
    return ['url' => $imageUrl, 'valid' => false];
}

/**
 * 对已格式化的帖子数据，验证图片并替换内容中的失效图片
 */
function validatePostImages(&$formatted) {
    if (!empty($formatted['images'])) {
        $validated = [];
        foreach ($formatted['images'] as $imgUrl) {
            $validated[] = checkImageFileExists($imgUrl);
        }
        $formatted['image_valid'] = array_map(function($v) { return $v['valid']; }, $validated);
        $formatted['images'] = array_map(function($v) { return $v['url']; }, $validated);
    }
    // 替换内容中的失效图片为占位符HTML
    if (!empty($formatted['content'])) {
        $formatted['content'] = replaceExpiredImagesInHtml($formatted['content']);
    }
    return $formatted;
}

/**
 * 替换HTML内容中的失效图片为占位符
 */
function replaceExpiredImagesInHtml($html) {
    if (empty($html)) return $html;
    return preg_replace_callback(
        '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i',
        function($matches) {
            $imgUrl = $matches[1];
            $result = checkImageFileExists($imgUrl);
            if (!$result['valid']) {
                return '<div style="aspect-ratio:16/9;max-width:100%;background:#D0D0D0;display:flex;align-items:center;justify-content:center;border-radius:4px;margin:8px 0;color:#FFFFFF;font-size:13px;">图片已失效</div>';
            }
            // 图片有效，保持原标签不变
            return $matches[0];
        },
        $html
    );
}

/**
 * 解析请求URI,返回路径段数组
 */
function getPathSegments() {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $uri = parse_url($uri, PHP_URL_PATH);
    // 移除 /api/app 前缀
    $path = preg_replace('#^/api/app#', '', $uri);
    $path = trim($path, '/');
    return $path === '' ? [] : explode('/', $path);
}

/**
 * 获取请求体JSON
 */
function getJsonBody() {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * 获取整数参数
 */
function getIntParam($key, $default = 0) {
    return intval($_GET[$key] ?? $default);
}

/**
 * 获取字符串参数
 */
function getStrParam($key, $default = '') {
    return trim($_GET[$key] ?? $default);
}

// ==================== Token 认证系统 ====================

/**
 * 确保api_tokens表存在
 */
function ensureApiTokensTable() {
    try {
        $pdo = getDbConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS `api_tokens` (
            `id` int(11) unsigned AUTO_INCREMENT PRIMARY KEY,
            `user_id` int(11) unsigned NOT NULL,
            `token` varchar(64) NOT NULL,
            `device_name` varchar(100) DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` timestamp NULL DEFAULT NULL,
            `expires_at` timestamp NULL DEFAULT NULL,
            UNIQUE KEY `token` (`token`),
            INDEX `idx_user_id` (`user_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {}
}
ensureApiTokensTable();

/**
 * 生成新Token
 * 单点登录:新Token生成时删除该用户所有旧Token(踢掉其他设备)
 */
function generateToken($userId, $deviceName = null) {
    try {
        $pdo = getDbConnection();

        // 踢掉该用户所有旧登录(单点登录:一个账号只能一个设备在线)
        $pdo->prepare("DELETE FROM api_tokens WHERE user_id = ?")->execute([$userId]);

        $token = bin2hex(random_bytes(32));

        // expires_at 为 NULL = 永久有效
        $stmt = $pdo->prepare("INSERT INTO api_tokens (user_id, token, device_name) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $token, $deviceName]);

        return [
            'token' => $token,
            'expires_at' => null,  // 永久有效
        ];
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * 从Authorization header提取Token
 */
function extractBearerToken() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (empty($header)) return null;
    // 兼容 Bearer <token> 和 ***<token> 两种格式
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/^\*\*\*(.+)$/', $header, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

/**
 * 通过Token验证用户身份,返回用户数组或null
 */
function authenticateByToken() {
    $token = extractBearerToken();
    if (!$token) return null;

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT t.*, u.id, u.username, u.email, u.avatar, u.avatar_text,
                                      u.profile_background, u.is_admin, u.is_founder, u.is_banned,
                                      u.points, u.exp, u.created_at, u.last_login, u.theme, u.public_uid,
                                      u.followers_count, u.following_count, u.likes_received_count
                               FROM api_tokens t
                               LEFT JOIN users u ON t.user_id = u.id
                               WHERE t.token = ?
                               AND (t.expires_at IS NULL OR t.expires_at > NOW())
                               LIMIT 1");
        $stmt->execute([$token]);
        $result = $stmt->fetch();

        if (!$result) return null;

        // 更新最后使用时间
        $stmt = $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?");
        $stmt->execute([$result['id']]);

        return $result;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * 需要登录的接口调用此函数获取当前用户
 */
function requireAuth() {
    $user = authenticateByToken();
    if (!$user) {
        apiError('请先登录', 401);
    }
    if ($user['is_banned']) {
        apiError('账号已被封禁', 403);
    }
    return $user;
}

/**
 * 需要管理员权限
 */
function requireAdmin() {
    $user = requireAuth();
    if (!$user['is_admin'] && !$user['is_founder']) {
        apiError('权限不足', 403);
    }
    return $user;
}

/**
 * 可选认证(如果带Token就解析用户,否则返回null)
 */
function optionalAuth() {
    return authenticateByToken();
}

// ==================== 数据格式化函数 ====================

/**
 * 格式化用户数据
 */
function formatUser($user, $includePrivate = false) {
    if (!$user) return null;

    $data = [
        'id' => intval($user['id']),
        'username' => $user['username'],
        'public_uid' => $user['public_uid'] ?? '',
        'avatar' => $user['avatar'] ?? '',
        'avatar_text' => $user['avatar_text'] ?? '',
        'profile_background' => $user['profile_background'] ?? '',
        'is_admin' => (bool)($user['is_admin'] ?? false),
        'is_founder' => (bool)($user['is_founder'] ?? false),
        'is_banned' => (bool)($user['is_banned'] ?? false),
        'points' => intval($user['points'] ?? 0),
        'exp' => intval($user['exp'] ?? 0),
        'followers_count' => intval($user['followers_count'] ?? 0),
        'following_count' => intval($user['following_count'] ?? 0),
        'likes_received_count' => intval($user['likes_received_count'] ?? 0),
        'created_at' => $user['created_at'] ?? '',
        'last_login' => $user['last_login'] ?? '',
        'level' => getExpProgress(intval($user['exp'] ?? 0)),
    ];

    if ($includePrivate) {
        $data['email'] = $user['email'] ?? '';
        $data['theme'] = $user['theme'] ?? 'light';
        $data['chat_username'] = $user['chat_username'] ?? '';
        $data['last_username_change'] = $user['last_username_change'] ?? '';
    }

    return $data;
}

/**
 * 格式化帖子数据
 */
function formatPost($post, $currentUserId = 0) {
    if (!$post) return null;

    $isLiked = false;
    $isFavorited = false;

    if ($currentUserId > 0) {
        $isLiked = hasUserLikedPost($post['id'], $currentUserId);
        $isFavorited = isPostFavorited($post['id'], $currentUserId);
    }

    $images = getPostAllImages($post['id'], $post['content'] ?? null);

    return [
        'id' => intval($post['id']),
        'title' => $post['title'],
        'content' => $post['content'] ?? '',
        'summary' => $post['summary'] ?? '',
        'category_id' => intval($post['category_id'] ?? 0),
        'category_name' => $post['category_name'] ?? '',
        'category_slug' => $post['category_slug'] ?? '',
        'user' => [
            'id' => intval($post['user_id'] ?? 0),
            'username' => $post['username'] ?? '',
            'avatar' => $post['avatar'] ?? '',
            'avatar_text' => $post['avatar_text'] ?? '',
            'is_admin' => (bool)($post['is_admin'] ?? false),
            'is_founder' => (bool)($post['is_founder'] ?? false),
            'is_banned' => (bool)($post['is_banned'] ?? false),
            'level' => getExpProgress(intval($post['exp'] ?? 0)),
        ],
        'view_count' => intval($post['view_count'] ?? 0),
        'comment_count' => intval($post['comment_count'] ?? 0),
        'like_count' => intval($post['like_count'] ?? 0),
        'favorite_count' => intval($post['favorite_count'] ?? 0),
        'total_tips' => intval($post['total_tips'] ?? 0),
        'is_top' => (bool)($post['is_top'] ?? false),
        'is_approved' => (bool)($post['is_approved'] ?? true),
        'attachment_name' => $post['attachment_name'] ?? null,
        'attachment_path' => $post['attachment_path'] ?? null,
        'attachment_size' => intval($post['attachment_size'] ?? 0),
        'images' => $images,
        'is_liked' => $isLiked,
        'is_favorited' => $isFavorited,
        'created_at' => $post['created_at'] ?? '',
        'updated_at' => $post['updated_at'] ?? '',
    ];
}

/**
 * 格式化评论数据
 */
function formatComment($comment, $currentUserId = 0) {
    if (!$comment) return null;

    $isLiked = false;
    if ($currentUserId > 0) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM comment_likes WHERE comment_id = ? AND user_id = ?");
            $stmt->execute([$comment['id'], $currentUserId]);
            $isLiked = $stmt->fetch()['c'] > 0;
        } catch (PDOException $e) {}
    }

    return [
        'id' => intval($comment['id']),
        'post_id' => intval($comment['post_id'] ?? 0),
        'parent_id' => $comment['parent_id'] ? intval($comment['parent_id']) : null,
        'content' => $comment['content'] ?? '',
                'image_url' => $comment['image_url'] ?? null,
        'image_urls' => getCommentImages(intval($comment['id'])),
        'like_count' => intval($comment['like_count'] ?? 0),
        'reply_count' => intval($comment['reply_count'] ?? ($comment['replies_count'] ?? 0)),
        'is_top' => (bool)($comment['is_top'] ?? false),
        'is_liked' => $isLiked,
        'user' => [
            'id' => intval($comment['user_id'] ?? 0),
            'username' => $comment['username'] ?? '',
            'avatar' => $comment['avatar'] ?? '',
            'avatar_text' => $comment['avatar_text'] ?? '',
            'is_admin' => (bool)($comment['is_admin'] ?? false),
            'is_founder' => (bool)($comment['is_founder'] ?? false),
            'is_banned' => (bool)($comment['is_banned'] ?? false),
            'level' => getExpProgress(intval($comment['exp'] ?? 0)),
        ],
        'replies' => [],
        'created_at' => $comment['created_at'] ?? '',
    ];
}

/**
 * 格式化通知
 */
function formatNotification($n) {
    $content = getNotificationContent($n);
    return [
        'id' => intval($n['id']),
        'type' => $n['type'] ?? '',
        'actor' => [
            'id' => intval($n['actor_id'] ?? 0),
            'username' => $n['actor_username'] ?? '',
            'avatar' => $n['actor_avatar'] ?? '',
            'avatar_text' => $n['actor_avatar_text'] ?? '',
        ],
        'target_id' => intval($n['target_id'] ?? 0),
        'data' => !empty($n['data']) ? json_decode($n['data'], true) : null,
        'post_title' => $content['post_title'],
        'comment_content' => $content['comment_content'],
        'is_read' => (bool)($n['is_read'] ?? false),
        'created_at' => $n['created_at'] ?? '',
    ];
}

function defaultVersionInfo() {
    return [
        'latest_version' => '1.0',
        'update_url' => '',
        'force_update' => true,
        'changelog' => '',
    ];
}

// ==================== 路由解析 ====================
$segments = getPathSegments();
$method = $_SERVER['REQUEST_METHOD'];

// ==================== 路由处理 ====================

// ---- 版本检查 (GET /api/app/version) ----
if ($segments === ['version'] && $method === 'GET') {
    $versionFile = __DIR__ . '/version.json';
    if (file_exists($versionFile)) {
        $data = json_decode(file_get_contents($versionFile), true);
        apiSuccess($data ?: defaultVersionInfo());
    } else {
        apiSuccess(defaultVersionInfo());
    }
}

// ---- 文件列表 (GET /api/app/files) ----
if ($segments === ['files'] && $method === 'GET') {
    $uploadDir = __DIR__ . '/../uploads/';
    $files = [];
    if (is_dir($uploadDir)) {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file->isFile()) {
                $relPath = str_replace(realpath(__DIR__ . '/..'), '', $file->getRealPath());
                $ext = strtolower($file->getExtension());
                $size = $file->getSize();
                if ($size < 1024) $sizeStr = $size . ' B';
                elseif ($size < 1048576) $sizeStr = round($size / 1024, 1) . ' KB';
                else $sizeStr = round($size / 1048576, 1) . ' MB';
                $files[] = [
                    'name' => $file->getFilename(),
                    'ext' => $ext,
                    'size' => $size,
                    'size_str' => $sizeStr,
                    'url' => $relPath
                ];
            }
        }
    }
    usort($files, function($a, $b) { return $b['size'] - $a['size']; });
    apiSuccess($files);
}

// ---- 文件删除 (POST /api/app/files/delete) ----
if ($segments === ['files', 'delete'] && $method === 'POST') {
    $body = getJsonBody();
    $path = trim($body['path'] ?? '');
    if (empty($path)) apiError('缺少文件路径');
    $fullPath = realpath(__DIR__ . '/..' . $path);
    $uploadDir = realpath(__DIR__ . '/../uploads');
    if (!$fullPath || strpos($fullPath, $uploadDir) !== 0) apiError('无效的文件路径');
    if (!file_exists($fullPath)) apiError('文件不存在');
    if (!is_writable($fullPath)) apiError('文件无法删除');
    unlink($fullPath);
    apiSuccess(null, '文件已删除');
}

// ---- 认证 ----
if ($segments === ['auth', 'login'] && $method === 'POST') {
    $body = getJsonBody();
    $identifier = trim($body['username'] ?? $body['email'] ?? '');
    $password = $body['password'] ?? '';
    $deviceName = trim($body['device_name'] ?? 'APP');

    if (empty($identifier) || empty($password)) {
        apiError('请填写用户名/邮箱和密码');
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user || !verifyPassword($password, $user['password'])) {
            apiError('用户名/邮箱或密码错误', 401);
        }

        if ($user['is_banned']) {
            apiError('账号已被封禁', 403);
        }

        // 更新最后登录
        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

        // 生成Token
        $tokenInfo = generateToken($user['id'], $deviceName);
        if (!$tokenInfo) {
            apiError('生成Token失败', 500);
        }

        apiSuccess([
            'user' => formatUser($user, true),
            'token' => $tokenInfo['token'],
            'expires_at' => null  // 永久有效
        ], '登录成功');
    } catch (PDOException $e) {
        apiError('服务器错误: ' . $e->getMessage(), 500);
    }
}

// ---- 图形验证码 (GET /api/app/auth/captcha) ----
if ($segments === ['auth', 'captcha'] && $method === 'GET') {
    $length = mt_rand(4, 6);
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $code = '';
    $maxIdx = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) $code .= $chars[mt_rand(0, $maxIdx)];

    $token = bin2hex(random_bytes(16));
    $expires = date('Y-m-d H:i:s', time() + 300);
    try {
        $pdo = getDbConnection();
        $pdo->prepare("INSERT INTO verification_tokens (token,type,target,code,expires_at) VALUES (?,?,?,?,?)")
            ->execute([$token, 'captcha', '', strtolower($code), $expires]);
    } catch (Exception $e) { apiError('生成验证码失败', 500); }

    // Generate image (simplified version)
    $w=150;$h=50;$img=imagecreatetruecolor($w,$h);
    $bg=imagecolorallocate($img,mt_rand(220,240),mt_rand(220,240),mt_rand(220,240));
    imagefill($img,0,0,$bg);
    for($i=0;$i<6;$i++){imageline($img,mt_rand(0,$w),mt_rand(0,$h),mt_rand(0,$w),mt_rand(0,$h),imagecolorallocate($img,mt_rand(120,200),mt_rand(120,200),mt_rand(120,200)));}
    for($i=0;$i<300;$i++){imagesetpixel($img,mt_rand(0,$w),mt_rand(0,$h),imagecolorallocate($img,mt_rand(0,200),mt_rand(0,200),mt_rand(0,200)));}
    $x=10;foreach(str_split($code) as $c){$col=imagecolorallocate($img,mt_rand(0,80),mt_rand(0,80),mt_rand(0,80));imagestring($img,5,$x,mt_rand(10,25),$c,$col);$x+=22;}
    ob_start();imagepng($img);$data=ob_get_clean();
    apiSuccess(['token'=>$token,'image'=>'data:image/png;base64,'.base64_encode($data),'expires_in'=>300]);
}

// ---- 发送邮箱验证码 (POST /api/app/auth/send-code) ----
if ($segments === ['auth', 'send-code'] && $method === 'POST') {
    $body = getJsonBody();
    $email = trim($body['email'] ?? '');
    $captchaToken = trim($body['captcha_token'] ?? '');
    $captchaCode = trim(strtolower($body['captcha_code'] ?? ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) apiError('邮箱格式不正确');
    if (empty($captchaToken) || empty($captchaCode)) apiError('请输入图形验证码');

    try {
        $pdo = getDbConnection();
        // Verify captcha
        $stmt = $pdo->prepare("SELECT * FROM verification_tokens WHERE token=? AND type='captcha' AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$captchaToken]);
        $cap = $stmt->fetch();
        if (!$cap || $cap['code'] !== $captchaCode) apiError('图形验证码错误或已过期');
        $pdo->prepare("UPDATE verification_tokens SET used=1 WHERE id=?")->execute([$cap['id']]);

        // Rate limit check
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM verification_tokens WHERE target=? AND type='email_code' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() >= 5) apiError('每小时最多发送5次验证码');
        $stmt = $pdo->prepare("SELECT created_at FROM verification_tokens WHERE target=? AND type='email_code' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$email]);
        $last = $stmt->fetch();
        if ($last && (time()-strtotime($last['created_at']))<60) apiError('发送过于频繁,请'.(60-(time()-strtotime($last['created_at']))).'秒后再试');

        // Check email not used
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) apiError('该邮箱已被注册');

        // Generate and store code
        $code = sprintf("%06d", mt_rand(0, 999999));
        $expires = date('Y-m-d H:i:s', time()+300);
        $pdo->prepare("INSERT INTO verification_tokens (token,type,target,code,expires_at) VALUES (?,?,?,?,?)")
            ->execute([bin2hex(random_bytes(16)), 'email_code', $email, $code, $expires]);

        // Send email
        $subject = '主播模拟器论坛 - 邮箱验证码';
        $mailBody = "您的验证码是:{$code}\n\n验证码5分钟内有效,请勿泄露给他人。\n\n主播模拟器论坛";
        sendEmail($email, $subject, $mailBody);

        apiSuccess(null, '验证码已发送至 '.$email);
    } catch (Exception $e) {
        if (strpos($e->getMessage(),'apiError')===0) throw $e;
        apiError('发送失败: '.$e->getMessage(), 500);
    }
}

if ($segments === ['auth', 'register'] && $method === 'POST') {
    // 检查注册开关
    if (!isRegistrationEnabled()) {
        apiError('注册功能已关闭');
    }

    $body = getJsonBody();
    $username = trim($body['username'] ?? '');
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $emailCode = trim($body['email_code'] ?? '');
    $captchaToken = trim($body['captcha_token'] ?? '');
    $captchaCode = trim(strtolower($body['captcha_code'] ?? ''));
    $deviceName = trim($body['device_name'] ?? 'APP');

    if (empty($username) || empty($email) || empty($password)) {
        apiError('请填写所有必填项');
    }
    if (empty($emailCode)) apiError('请输入邮箱验证码');
    if (empty($captchaToken) || empty($captchaCode)) apiError('请输入图形验证码');

    if (strlen($username) < 2 || strlen($username) > 20) {
        apiError('用户名长度2-20个字符');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError('邮箱格式不正确');
    }

    if (strlen($password) < 6) {
        apiError('密码至少6个字符');
    }

    if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
        apiError('用户名只能包含字母、数字、下划线和中文');
    }

    try {
        $pdo = getDbConnection();

        // 验证图形验证码
        $stmt = $pdo->prepare("SELECT * FROM verification_tokens WHERE token=? AND type='captcha' AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$captchaToken]);
        $cap = $stmt->fetch();
        if (!$cap || $cap['code'] !== $captchaCode) apiError('图形验证码错误或已过期');
        $pdo->prepare("UPDATE verification_tokens SET used=1 WHERE id=?")->execute([$cap['id']]);

        // 验证邮箱验证码
        $stmt = $pdo->prepare("SELECT * FROM verification_tokens WHERE target=? AND type='email_code' AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$email]);
        $ec = $stmt->fetch();
        if (!$ec || $ec['code'] !== $emailCode) apiError('邮箱验证码错误或已过期');
        $pdo->prepare("UPDATE verification_tokens SET used=1 WHERE id=?")->execute([$ec['id']]);

        // 检查用户名
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            apiError('用户名已存在');
        }

        // 检查邮箱
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            apiError('邮箱已被注册');
        }

        // 创建用户
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
        $stmt->execute([$username, $email, $hashedPassword]);
        $userId = $pdo->lastInsertId();

        // 自动关注创始人
        if (isAutoFollowEnabled()) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE is_founder = 1 LIMIT 1");
                $stmt->execute();
                $founder = $stmt->fetch();
                if ($founder) {
                    toggleFollow($userId, $founder['id']);
                }
            } catch (PDOException $e) {}
        }

        // 生成Token
        $tokenInfo = generateToken($userId, $deviceName);

        // 获取完整用户信息
        $user = getUserById($userId);

        apiSuccess([
            'user' => formatUser($user, true),
            'token' => $tokenInfo['token'],
            'expires_at' => null  // 永久有效
        ], '注册成功', 201);
    } catch (PDOException $e) {
        apiError('服务器错误: ' . $e->getMessage(), 500);
    }
}

if ($segments === ['auth', 'logout'] && $method === 'POST') {
    $token = extractBearerToken();
    if ($token) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE token = ?");
            $stmt->execute([$token]);
        } catch (PDOException $e) {}
    }
    apiSuccess(null, '已退出登录');
}

// ---- 首页 ----
if ($segments === ['home'] && $method === 'GET') {
    $currentUser = optionalAuth();
    $currentUserId = $currentUser ? $currentUser['id'] : 0;

    try {
        $pdo = getDbConnection();

        // 轮播图
        $slides = getAllSlides();
        $slidesData = array_map(function($s) {
            return [
                'id' => intval($s['id']),
                'title' => $s['title'] ?? '',
                        'image_url' => $s['image_url'] ?? '',
                'link_url' => $s['link_url'] ?? '',
                'sort_order' => intval($s['sort_order'] ?? 0),
            ];
        }, $slides);

        // 快捷链接
        $links = getAllLinks();
        $linksData = array_map(function($l) {
            return [
                'id' => intval($l['id']),
                'name' => $l['name'] ?? '',
                'url' => $l['url'] ?? '',
                'icon' => $l['icon'] ?? '',
                'sort_order' => intval($l['sort_order'] ?? 0),
            ];
        }, $links);

        // 分类
        $categories = getAllCategories();
        $categoriesData = array_map(function($c) {
            return [
                'id' => intval($c['id']),
                'name' => $c['name'],
                'slug' => $c['slug'],
                'description' => $c['description'] ?? '',
                'sort_order' => intval($c['sort_order'] ?? 0),
            ];
        }, $categories);

        // 全局最新帖子(前10条)
        $stmt = $pdo->query("SELECT p.*, u.username, u.avatar, u.avatar_text, u.id as user_id,
                                    u.is_admin, u.is_founder, u.is_banned, u.exp,
                                    c.name as category_name, c.slug as category_slug,
                                    (SELECT COUNT(*) FROM favorites WHERE post_id = p.id) as favorite_count
                             FROM posts p
                             LEFT JOIN users u ON p.user_id = u.id
                             LEFT JOIN categories c ON p.category_id = c.id
                             WHERE p.is_approved = 1
                             ORDER BY p.created_at DESC
                             LIMIT 10");
        $latestPosts = $stmt->fetchAll();

        // 全局热门帖子(按浏览量,前10条)
        $stmt = $pdo->query("SELECT p.*, u.username, u.avatar, u.avatar_text, u.id as user_id,
                                    u.is_admin, u.is_founder, u.is_banned, u.exp,
                                    c.name as category_name, c.slug as category_slug,
                                    (SELECT COUNT(*) FROM favorites WHERE post_id = p.id) as favorite_count
                             FROM posts p
                             LEFT JOIN users u ON p.user_id = u.id
                             LEFT JOIN categories c ON p.category_id = c.id
                             WHERE p.is_approved = 1
                             ORDER BY p.view_count DESC, p.created_at DESC
                             LIMIT 10");
        $hotPosts = $stmt->fetchAll();

        // 今日热门帖子(按今日浏览量,前10条)
        $stmt = $pdo->query("SELECT p.*, u.username, u.avatar, u.avatar_text, u.id as user_id,
                                    u.is_admin, u.is_founder, u.is_banned, u.exp,
                                    c.name as category_name, c.slug as category_slug,
                                    COALESCE(dv.view_count, 0) as today_view_count,
                                    (SELECT COUNT(*) FROM favorites WHERE post_id = p.id) as favorite_count
                             FROM posts p
                             LEFT JOIN users u ON p.user_id = u.id
                             LEFT JOIN categories c ON p.category_id = c.id
                             LEFT JOIN post_daily_views dv ON dv.post_id = p.id AND dv.view_date = CURDATE()
                             WHERE p.is_approved = 1
                             ORDER BY today_view_count DESC, p.created_at DESC
                             LIMIT 10");
        $todayHotPosts = $stmt->fetchAll();

        // 今日签到人数
        $stmt = $pdo->query("SELECT COUNT(*) FROM daily_signins WHERE signin_date = CURDATE()");
        $todaySigninCount = intval($stmt->fetchColumn());

        // 在线用户数
        $online = getOnlineUsersCount();

        // 格式化帖子（含图片失效检测）
        $latestPostsData = array_map(function($p) use ($currentUserId) {
            return validatePostImages(formatPost($p, $currentUserId));
        }, $latestPosts);

        $hotPostsData = array_map(function($p) use ($currentUserId) {
            return validatePostImages(formatPost($p, $currentUserId));
        }, $hotPosts);

        $todayHotPostsData = array_map(function($p) use ($currentUserId) {
            $formatted = formatPost($p, $currentUserId);
            $formatted['today_view_count'] = intval($p['today_view_count'] ?? 0);
            return validatePostImages($formatted);
        }, $todayHotPosts);

        // 置顶帖子
        $topPosts = getTopPosts();
        $topPostsData = array_map(function($p) use ($currentUserId) {
            return validatePostImages(formatPost($p, $currentUserId));
        }, $topPosts);

        apiSuccess([
            'slides' => $slidesData,
            'links' => $linksData,
            'categories' => $categoriesData,
            'top_posts' => $topPostsData,
            'latest_posts' => $latestPostsData,
            'hot_posts' => $hotPostsData,
            'today_hot_posts' => $todayHotPostsData,
            'online' => $online,
            'today_signin_count' => $todaySigninCount,
        ]);
    } catch (PDOException $e) {
        apiError('服务器错误', 500);
    }
}

// ---- 社区分类统计 ----
if ($segments === ['community'] && $method === 'GET') {
    $slug = getStrParam('slug', 'all');
    $validSlugs = ['all', 'mod', 'exchange', 'chat'];
    if (!in_array($slug, $validSlugs)) {
        apiError('分类不存在', 404);
    }

    $categoryId = null;
    if ($slug !== 'all') {
        $cat = getCategoryBySlug($slug);
        if (!$cat) apiError('分类不存在', 404);
        $categoryId = $cat['id'];
    }

    $todayPostCount = getTodayPostCount($categoryId);
    $todayViews = getCategoryTodayViews($categoryId);
    $heat = getCategoryHeat($categoryId);

    apiSuccess([
        'slug' => $slug,
        'stat' => [
            'heat' => $heat,
            'today_posts' => $todayPostCount,
            'today_views' => $todayViews,
        ]
    ]);
}

// ---- 分类 ----
if ($segments === ['categories'] && $method === 'GET') {
    $categories = getAllCategories();
    $data = array_map(function($c) {
        $postCount = getPostCount($c['id'], true);
        return [
            'id' => intval($c['id']),
            'name' => $c['name'],
            'slug' => $c['slug'],
            'description' => $c['description'] ?? '',
            'sort_order' => intval($c['sort_order'] ?? 0),
            'post_count' => intval($postCount),
        ];
    }, $categories);
    apiSuccess($data);
}

// ---- 帖子列表 ----
if ($segments === ['posts'] && $method === 'GET') {
    $currentUser = optionalAuth();
    $currentUserId = $currentUser ? $currentUser['id'] : 0;

    $page = max(1, getIntParam('page', 1));
    $perPage = min(MAX_PAGE_SIZE, max(1, getIntParam('per_page', DEFAULT_PAGE_SIZE)));
    $categoryId = getIntParam('category_id', 0);
    $sort = in_array(getStrParam('sort'), ['latest', 'popular', 'like', 'comment', 'today_hot', 'favorite']) ? getStrParam('sort') : 'latest';

    $offset = ($page - 1) * $perPage;

    try {
        $pdo = getDbConnection();

        // 构建查询
        $where = "p.is_approved = 1 AND p.is_top = 0";
        $params = [];

        if ($categoryId > 0) {
            $where .= " AND p.category_id = ?";
            $params[] = $categoryId;
        }

        $orderBy = 'p.created_at DESC';
        switch ($sort) {
            case 'latest': $orderBy = 'p.created_at DESC'; break;
            case 'popular': $orderBy = 'p.view_count DESC, p.created_at DESC'; break;
            case 'today_hot': $orderBy = 'COALESCE(pdv.view_count, 0) DESC, p.created_at DESC'; break;
            case 'like': $orderBy = 'p.like_count DESC, p.created_at DESC'; break;
            case 'comment': $orderBy = 'p.comment_count DESC, p.created_at DESC'; break;
            case 'favorite': $orderBy = '(SELECT COUNT(*) FROM favorites WHERE post_id = p.id) DESC, p.created_at DESC'; break;
        }

        // 今日浏览join
        $joinExtra = '';
        $selectExtra = '';
        if ($sort === 'today_hot') {
            $selectExtra = ', COALESCE(pdv.view_count, 0) as today_view_count';
            $joinExtra = ' LEFT JOIN post_daily_views pdv ON p.id = pdv.post_id AND pdv.view_date = CURDATE()';
        }

        // 总数
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts p {$joinExtra} WHERE {$where}");
        $countParams = $params;
        $countStmt->execute($countParams);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // 分类下的置顶帖
        $topPosts = [];
        if ($page === 1 && $categoryId > 0) {
            $topPosts = getTopPosts($categoryId);
        }

        // 数据
        $params[] = $offset;
        $params[] = $perPage;
        $stmt = $pdo->prepare("SELECT p.*, u.username, u.avatar, u.avatar_text, u.id as user_id,
                                      u.is_admin, u.is_founder, u.is_banned, u.exp,
                                      c.name as category_name, c.slug as category_slug,
                                      (SELECT COUNT(*) FROM favorites WHERE post_id = p.id) as favorite_count{$selectExtra}
                               FROM posts p
                               LEFT JOIN users u ON p.user_id = u.id
                               LEFT JOIN categories c ON p.category_id = c.id
                               {$joinExtra}
                               WHERE {$where}
                               ORDER BY {$orderBy}
                               LIMIT ?, ?");
        $stmt->execute($params);
        $posts = $stmt->fetchAll();

        $postsData = array_map(function($p) use ($currentUserId) {
            return validatePostImages(formatPost($p, $currentUserId));
        }, $posts);

        $topPostsData = array_map(function($p) use ($currentUserId) {
            return validatePostImages(formatPost($p, $currentUserId));
        }, $topPosts);

        apiSuccess([
            'items' => $postsData,
            'top_items' => $topPostsData,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => intval($total),
                'total_pages' => ceil($total / $perPage),
            ]
        ]);
    } catch (PDOException $e) {
        apiError('服务器错误', 500);
    }
}

// ---- 帖子详情 (GET /api/app/posts/{id}) ----
if (count($segments) >= 2 && $segments[0] === 'posts' && is_numeric($segments[1]) && !isset($segments[2]) && $method === 'GET') {
    $postId = intval($segments[1]);
    $currentUser = optionalAuth();
    $currentUserId = $currentUser ? $currentUser['id'] : 0;

    $post = getPostById($postId);
    if (!$post) {
        apiError('帖子不存在', 404);
    }

    // 浏览量+1
    incrementPostView($postId);
    $post['view_count'] = $post['view_count'] + 1;

    // 补充收藏数(getPostById不包含此字段)
    $post['favorite_count'] = getPostFavoriteCount($postId);
    $post['total_tips'] = getPostTotalTips($postId);

    // 获取评论(第一页)
    $commentPage = max(1, getIntParam('comment_page', 1));
    $commentPerPage = min(MAX_PAGE_SIZE, max(1, getIntParam('comment_per_page', 20)));
    $comments = getPostComments($postId, $commentPage, $commentPerPage);
    $commentCount = getCommentCount($postId);

    // 格式化评论,加载回复
    $commentsData = [];
    foreach ($comments as $comment) {
        $formatted = formatComment($comment, $currentUserId);
        // 加载子回复
        $replies = getCommentReplies($comment['id']);
        $formatted['replies'] = array_map(function($r) use ($currentUserId) {
            return formatComment($r, $currentUserId);
        }, $replies);
        $commentsData[] = $formatted;
    }

    $postData = formatPost($post, $currentUserId);
    // 验证图片是否有效，替换内容中的失效图片为占位
    if (!empty($postData['content'])) {
        $postData['content'] = replaceExpiredImagesInHtml($postData['content']);
    }
    // 也对 images 数组做验证（供其他页面使用）
    if (!empty($postData['images'])) {
        $validated = [];
        foreach ($postData['images'] as $imgUrl) {
            $validated[] = checkImageFileExists($imgUrl);
        }
        $postData['image_valid'] = array_map(function($v) { return $v['valid']; }, $validated);
        $postData['images'] = array_map(function($v) { return $v['url']; }, $validated);
    }

    apiSuccess([
        'post' => $postData,
        'viewer' => $currentUser ? ['points' => intval($currentUser['points'] ?? 0)] : null,
        'comments' => $commentsData,
        'comment_pagination' => [
            'page' => $commentPage,
            'per_page' => $commentPerPage,
            'total' => intval($commentCount),
            'total_pages' => ceil($commentCount / $commentPerPage),
        ]
    ]);
}

// ---- 帖子操作 (POST /api/app/post_actions) ----
if ($segments === ['post_actions'] && $method === 'POST') {
    $user = requireAuth();
    $userId = $user['id'];
    $body = getJsonBody();
    $action = $body['action'] ?? '';

    switch ($action) {
        case 'like_post':
            $postId = intval($body['post_id'] ?? 0);
            if ($postId <= 0) apiError('帖子ID错误');
            $result = togglePostLike($postId, $userId);
            apiSuccess(['liked' => $result['liked'], 'message' => $result['message']]);
            break;

        case 'toggle_favorite':
            $postId = intval($body['post_id'] ?? 0);
            if ($postId <= 0) apiError('帖子ID错误');
            $result = togglePostFavorite($postId, $userId);
            $favCount = getPostFavoriteCount($postId);
            apiSuccess([
                'favorited' => $result['favorited'],
                'message' => $result['message'],
                'favorite_count' => $favCount
            ]);
            break;

        case 'like_comment':
            $commentId = intval($body['comment_id'] ?? 0);
            if ($commentId <= 0) apiError('评论ID错误');
            $result = toggleCommentLike($commentId, $userId);
            apiSuccess(['liked' => $result['liked'], 'message' => $result['message']]);
            break;

        default:
            apiError('未知操作', 400);
    }
}

// ---- 打赏记录 (GET /api/app/posts/{id}/tips) ----
if (count($segments) >= 3 && $segments[0] === 'posts' && is_numeric($segments[1]) && $segments[2] === 'tips' && !isset($segments[3]) && $method === 'GET') {
    $postId = intval($segments[1]);
    $records = getTipRecords($postId, 20);
    $items = array_map(function($r) {
        return [
            'id' => intval($r['id']),
            'from_user_id' => intval($r['from_user_id']),
            'username' => $r['username'],
            'avatar' => $r['avatar'] ?? '',
            'amount' => intval($r['amount']),
            'created_at' => $r['created_at']
        ];
    }, $records);
    apiSuccess(['records' => $items, 'total' => getPostTotalTips($postId)]);
}

// ---- 发送打赏 (POST /api/app/posts/{id}/tip) ----
if (count($segments) >= 3 && $segments[0] === 'posts' && is_numeric($segments[1]) && $segments[2] === 'tip' && !isset($segments[3]) && $method === 'POST') {
    $user = requireAuth();
    $postId = intval($segments[1]);
    $post = getPostById($postId);
    if (!$post) apiError('帖子不存在', 404);
    if ($user['id'] == $post['user_id']) apiError('不能给自己打赏');
    $body = getJsonBody();
    $amount = intval($body['amount'] ?? 0);
    if ($amount <= 0) apiError('打赏积分必须大于0');
    $result = sendTip($user['id'], $post['user_id'], $postId, $amount);
    if ($result['success']) {
        apiSuccess(['total' => getPostTotalTips($postId)], '打赏成功');
    } else {
        apiError($result['message'] ?? '打赏失败');
    }
}

// ---- 创建帖子 (POST /api/app/posts) ----
if ($segments === ['posts'] && $method === 'POST') {
    $user = requireAuth();
    $body = getJsonBody();

    $title = trim($body['title'] ?? '');
    $content = trim($body['content'] ?? '');
    $categorySlug = trim($body['category_slug'] ?? '');

    if (empty($title)) apiError('标题不能为空');
    if (empty($content)) apiError('内容不能为空');
    if (empty($categorySlug)) apiError('请选择分类');
    if (mb_strlen($title, 'UTF-8') > 100) apiError('标题不能超过100个字符');

    $result = createPost([
        'user_id' => $user['id'],
        'title' => $title,
        'content' => $content,
        'category_slug' => $categorySlug
    ]);

    if (!$result['success']) {
        apiError($result['message'] ?? '发布失败');
    }

    // 获取刚创建的帖子
    $postId = $result['post_id'] ?? 0;
    $post = getPostById($postId);

    apiSuccess(formatPost($post, $user['id']), '发布成功', 201);
}

// ---- 更新帖子 (PUT /api/app/posts/{id}) ----
if (count($segments) >= 2 && $segments[0] === 'posts' && is_numeric($segments[1]) && !isset($segments[2]) && $method === 'PUT') {
    $user = requireAuth();
    $postId = intval($segments[1]);
    $body = getJsonBody();

    $post = getPostById($postId);
    if (!$post) apiError('帖子不存在', 404);

    // 权限检查
    if ($post['user_id'] != $user['id'] && !$user['is_admin'] && !$user['is_founder']) {
        apiError('无权修改此帖子', 403);
    }

    // 如果只传了 is_top，单独处理置顶（仅管理员）
    if (isset($body['is_top']) && count((array)$body) === 1) {
        if (!$user['is_admin'] && !$user['is_founder']) apiError('只有管理员可以置顶帖子', 403);
        $isTop = !empty($body['is_top']) ? 1 : 0;
        setPostTop($postId, $isTop);
        $updated = getPostById($postId);
        apiSuccess(formatPost($updated, $user['id']), $isTop ? '帖子已置顶' : '已取消置顶');
    }

    $title = trim($body['title'] ?? $post['title']);
    $content = trim($body['content'] ?? $post['content']);

    if (empty($title)) apiError('标题不能为空');
    if (empty($content)) apiError('内容不能为空');

    $safeContent = safe_html($content);
    $summary = mb_substr(strip_tags($safeContent), 0, 150, 'UTF-8');

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE posts SET title = ?, content = ?, summary = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$title, $safeContent, $summary, $postId]);

        $updated = getPostById($postId);
        apiSuccess(formatPost($updated, $user['id']), '更新成功');
    } catch (PDOException $e) {
        apiError('更新失败: ' . $e->getMessage(), 500);
    }
}

// ---- 删除帖子 (DELETE /api/app/posts/{id}) ----
if (count($segments) >= 2 && $segments[0] === 'posts' && is_numeric($segments[1]) && !isset($segments[2]) && $method === 'DELETE') {
    $user = requireAuth();
    $postId = intval($segments[1]);

    $post = getPostById($postId);
    if (!$post) apiError('帖子不存在', 404);

    if ($post['user_id'] != $user['id'] && !$user['is_admin'] && !$user['is_founder']) {
        apiError('无权删除此帖子', 403);
    }

    if (deletePost($postId)) {
        apiSuccess(null, '删除成功');
    } else {
        apiError('删除失败', 500);
    }
}

// ---- 帖子评论 (GET /api/app/posts/{id}/comments) ----
if (count($segments) >= 3 && $segments[0] === 'posts' && is_numeric($segments[1]) && $segments[2] === 'comments' && $method === 'GET') {
    $postId = intval($segments[1]);
    $currentUser = optionalAuth();
    $currentUserId = $currentUser ? $currentUser['id'] : 0;

    $page = max(1, getIntParam('page', 1));
    $perPage = min(MAX_PAGE_SIZE, max(1, getIntParam('per_page', 20)));

    $comments = getPostComments($postId, $page, $perPage);
    $commentCount = getCommentCount($postId);

    $commentsData = [];
    foreach ($comments as $comment) {
        $formatted = formatComment($comment, $currentUserId);
        $replies = getCommentReplies($comment['id']);
        $formatted['replies'] = array_map(function($r) use ($currentUserId) {
            return formatComment($r, $currentUserId);
        }, $replies);
        $commentsData[] = $formatted;
    }

    apiSuccess([
        'items' => $commentsData,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => intval($commentCount),
            'total_pages' => ceil($commentCount / $perPage),
        ]
    ]);
}

// ---- 添加评论 (POST /api/app/posts/{id}/comments) ----
if (count($segments) >= 3 && $segments[0] === 'posts' && is_numeric($segments[1]) && $segments[2] === 'comments' && $method === 'POST') {
    $user = requireAuth();
    $postId = intval($segments[1]);
    $body = getJsonBody();

    $content = trim($body['content'] ?? '');
    $parentId = intval($body['parent_id'] ?? 0);
    $imageUrl = trim($body['image_url'] ?? '');
    $imageUrls = $body['image_urls'] ?? null;

    if (empty($content)) apiError('评论内容不能为空');

    $post = getPostById($postId);
    if (!$post) apiError('帖子不存在', 404);

    $result = addComment([
        'post_id' => $postId,
        'user_id' => $user['id'],
        'content' => $content,
        'parent_id' => $parentId > 0 ? $parentId : null,
        'image_url' => !empty($imageUrl) ? $imageUrl : null,
        'image_urls' => $imageUrls,
    ]);

    if (!$result['success']) {
        apiError($result['message'] ?? '评论失败');
    }

    $commentId = $result['comment_id'] ?? 0;
    $comment = getCommentById($commentId);

    apiSuccess(formatComment($comment, $user['id']), '评论成功', 201);
}

// ---- 评论详情 (GET /api/app/comments/{id}) ----
if (count($segments) >= 2 && $segments[0] === 'comments' && is_numeric($segments[1]) && !isset($segments[2]) && $method === 'GET') {
    $commentId = intval($segments[1]);
    $currentUser = optionalAuth();
    $currentUserId = $currentUser ? $currentUser['id'] : 0;

    $comment = getCommentById($commentId);
    if (!$comment) apiError('评论不存在', 404);

    $formatted = formatComment($comment, $currentUserId);
    $allReplies = getCommentRepliesRecursive($commentId);
    $formatted['replies'] = array_map(function($r) use ($currentUserId) {
        $f = formatComment($r, $currentUserId);
        if (!empty($r['replies'])) {
            $f['replies'] = array_map(function($rr) use ($currentUserId) {
                return formatComment($rr, $currentUserId);
            }, $r['replies']);
        }
        return $f;
    }, $allReplies);

    apiSuccess($formatted);
}

// ---- 删除评论 (DELETE /api/app/comments/{id}) ----
if (count($segments) >= 2 && $segments[0] === 'comments' && is_numeric($segments[1]) && !isset($segments[2]) && $method === 'DELETE') {
    $user = requireAuth();
    $commentId = intval($segments[1]);

    $comment = getCommentById($commentId);
    if (!$comment) apiError('评论不存在', 404);

    if ($comment['user_id'] != $user['id'] && !$user['is_admin'] && !$user['is_founder']) {
        apiError('无权删除此评论', 403);
    }

    // For top-level comments with replies, delete replies first
    $pdo2 = getDbConnection();
    $stmt2 = $pdo2->prepare("DELETE FROM comments WHERE parent_id = ?");
    $stmt2->execute([$commentId]);
    
    if (deleteComment($commentId)) {
        apiSuccess(null, '删除成功');
    } else {
        apiError('删除失败', 500);
    }
}

// ---- 点赞帖子 ----
if (count($segments) >= 3 && $segments[0] === 'posts' && is_numeric($segments[1]) && $segments[2] === 'like' && $method === 'POST') {
    $user = requireAuth();
    $postId = intval($segments[1]);

    $result = togglePostLike($postId, $user['id']);
    apiSuccess([
        'liked' => $result['liked'],
        'message' => $result['message']
    ]);
}

// ---- 点赞评论 ----
if (count($segments) >= 3 && $segments[0] === 'comments' && is_numeric($segments[1]) && $segments[2] === 'like' && $method === 'POST') {
    $user = requireAuth();
    $commentId = intval($segments[1]);

    $result = toggleCommentLike($commentId, $user['id']);
    apiSuccess([
        'liked' => $result['liked'],
        'message' => $result['message']
    ]);
}

// ---- 收藏帖子 ----
if (count($segments) >= 3 && $segments[0] === 'posts' && is_numeric($segments[1]) && $segments[2] === 'favorite' && $method === 'POST') {
    $user = requireAuth();
    $postId = intval($segments[1]);

    $result = togglePostFavorite($postId, $user['id']);
    apiSuccess([
        'favorited' => $result['favorited'],
        'message' => $result['message']
    ]);
}

// ---- 关注/取关用户 ----
if (count($segments) >= 3 && $segments[0] === 'users' && is_numeric($segments[1]) && $segments[2] === 'follow' && $method === 'POST') {
    $user = requireAuth();
    $targetId = intval($segments[1]);

    if ($targetId == $user['id']) {
        apiError('不能关注自己');
    }

    $target = getUserById($targetId);
    if (!$target) apiError('用户不存在', 404);

    $result = toggleFollow($user['id'], $targetId);
    apiSuccess([
        'following' => $result['following'],
        'message' => $result['message']
    ]);
}

// ---- 公开用户主页 (GET /api/app/users/{id}) ----
if ($segments[0] === 'users' && isset($segments[1]) && is_numeric($segments[1]) && !isset($segments[2]) && $method === 'GET') {
    $userId = intval($segments[1]);
    $targetUser = getUserById($userId);
    if (!$targetUser) apiError('用户不存在', 404);
    if ($targetUser['is_banned']) apiError('用户已封禁', 403);

    $currentUser = optionalAuth();
    $isSelf = $currentUser && $currentUser['id'] == $userId;
    $followStats = getUserFollowStats($userId);
    $receivedLikes = getUserReceivedLikes($userId);
    $isFollowing = false;
    if ($currentUser && !$isSelf) {
        $isFollowing = isFollowing($currentUser['id'], $userId);
    }

    // Posts
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = 10;
    $posts = getUserPosts($userId, $page, $perPage);
    $totalPosts = getUserPostsCount($userId);

    $postsData = [];
    if ($posts) {
        $postIds = array_column($posts, 'id');
        $postStats = [];
        if (!empty($postIds)) {
            try {
                $pdo = getDbConnection();
                $likes = $pdo->query("SELECT post_id, COUNT(*) as c FROM post_likes WHERE post_id IN (" . implode(',', array_map('intval',$postIds)) . ") GROUP BY post_id")->fetchAll();
                $comments = $pdo->query("SELECT post_id, COUNT(*) as c FROM comments WHERE post_id IN (" . implode(',', array_map('intval',$postIds)) . ") GROUP BY post_id")->fetchAll();
                $favs = $pdo->query("SELECT post_id, COUNT(*) as c FROM favorites WHERE post_id IN (" . implode(',', array_map('intval',$postIds)) . ") GROUP BY post_id")->fetchAll();
                foreach ($likes as $l) $postStats[$l['post_id']]['like'] = $l['c'];
                foreach ($comments as $c) $postStats[$c['post_id']]['comment'] = $c['c'];
                foreach ($favs as $f) $postStats[$f['post_id']]['fav'] = $f['c'];
            } catch (Exception $e) {}
        }
        // 当前用户的点赞/收藏状态
        $userLikedPosts = [];
        $userFavPosts = [];
        if ($currentUser && $currentUser['id'] > 0 && !empty($postIds)) {
            try {
                $pdo2 = getDbConnection();
                $st = $pdo2->prepare("SELECT post_id FROM post_likes WHERE post_id IN (" . implode(',', array_map('intval',$postIds)) . ") AND user_id = ?");
                $st->execute([$currentUser['id']]);
                $userLikedPosts = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                $st2 = $pdo2->prepare("SELECT post_id FROM favorites WHERE post_id IN (" . implode(',', array_map('intval',$postIds)) . ") AND user_id = ?");
                $st2->execute([$currentUser['id']]);
                $userFavPosts = array_map('intval', $st2->fetchAll(PDO::FETCH_COLUMN));
            } catch (Exception $e) {}
        }
        foreach ($posts as $p) {
            $sid = $p['id'];
            $postsData[] = [
                'id' => $sid, 'title' => $p['title'],
                'summary' => mb_substr(strip_tags($p['content'] ?? $p['summary'] ?? ''), 0, 100, 'UTF-8'),
                'created_at' => $p['created_at'],
                'view_count' => $p['view_count'] ?? 0,
                'like_count' => $postStats[$sid]['like'] ?? 0,
                'comment_count' => $postStats[$sid]['comment'] ?? 0,
                'favorite_count' => $postStats[$sid]['fav'] ?? 0,
                'is_top' => $p['is_top'] ?? 0,
                'is_approved' => $p['is_approved'] ?? 1,
                'is_liked' => in_array($sid, $userLikedPosts),
                'is_favorited' => in_array($sid, $userFavPosts),
                'images' => getPostAllImages($sid, $p['content'] ?? null),
            ];
        }
    }

    apiSuccess([
        'id' => $targetUser['id'],
        'username' => $targetUser['username'],
        'public_uid' => $targetUser['public_uid'] ?? '',
        'avatar' => $targetUser['avatar'] ?? '',
        'avatar_text' => $targetUser['avatar_text'] ?? '',
        'profile_background' => $targetUser['profile_background'] ?? '',
        'is_admin' => (bool)($targetUser['is_admin'] ?? false),
        'is_founder' => (bool)($targetUser['is_founder'] ?? false),
        'points' => intval($targetUser['points'] ?? 0),
        'exp' => intval($targetUser['exp'] ?? 0),
        'level' => getUserLevel(($targetUser['exp'] ?? 0)),
        'level_name' => ($levelData = getExpProgress(($targetUser['exp'] ?? 0))) ? $levelData['name'] : '',
        'followers_count' => $followStats['followers'],
        'following_count' => $followStats['following'],
        'likes_received_count' => $receivedLikes,
        'is_self' => $isSelf,
        'is_following' => $isFollowing,
        'created_at' => $targetUser['created_at'],
        'posts' => $postsData,
        'total_posts' => $totalPosts,
        'page' => $page,
        'total_pages' => ceil($totalPosts / $perPage),
    ]);
}

// ---- 获取粉丝列表 (GET /api/app/users/{id}/followers) ----
if ($segments[0] === 'users' && isset($segments[1]) && is_numeric($segments[1]) && ($segments[2] ?? '') === 'followers' && $method === 'GET') {
    $userId = intval($segments[1]);
    $targetUser = getUserById($userId);
    if (!$targetUser) apiError('用户不存在', 404);
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    try {
        $pdo = getDbConnection();
        $countSt = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE following_id = ?');
        $countSt->execute([$userId]);
        $total = intval($countSt->fetchColumn());
        $st = $pdo->prepare('SELECT u.id, u.username, u.avatar, u.avatar_text, u.exp, u.is_admin, u.is_founder, u.is_banned, f.created_at as followed_at FROM follows f JOIN users u ON f.follower_id = u.id WHERE f.following_id = ? ORDER BY f.created_at DESC LIMIT ? OFFSET ?');
        $st->execute([$userId, $perPage, $offset]);
        $rows = $st->fetchAll();
    } catch (Exception $e) {
        apiError('服务器错误', 500);
    }
    $users = [];
    foreach ($rows as $r) {
        if ($r['is_banned']) continue;
        $users[] = [
            'id' => intval($r['id']),
            'username' => $r['username'],
            'avatar' => $r['avatar'] ?? '',
            'avatar_text' => $r['avatar_text'] ?? '',
            'level' => getExpProgress(intval($r['exp'])),
            'is_admin' => (bool)($r['is_admin'] ?? false),
            'is_founder' => (bool)($r['is_founder'] ?? false),
            'followed_at' => $r['followed_at'],
        ];
    }
    apiSuccess(['users' => $users, 'total' => $total, 'page' => $page, 'total_pages' => ceil($total / $perPage)]);
}

// ---- 获取关注列表 (GET /api/app/users/{id}/following) ----
if ($segments[0] === 'users' && isset($segments[1]) && is_numeric($segments[1]) && ($segments[2] ?? '') === 'following' && $method === 'GET') {
    $userId = intval($segments[1]);
    $targetUser = getUserById($userId);
    if (!$targetUser) apiError('用户不存在', 404);
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    try {
        $pdo = getDbConnection();
        $countSt = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE follower_id = ?');
        $countSt->execute([$userId]);
        $total = intval($countSt->fetchColumn());
        $st = $pdo->prepare('SELECT u.id, u.username, u.avatar, u.avatar_text, u.exp, u.is_admin, u.is_founder, u.is_banned, f.created_at as followed_at FROM follows f JOIN users u ON f.following_id = u.id WHERE f.follower_id = ? ORDER BY f.created_at DESC LIMIT ? OFFSET ?');
        $st->execute([$userId, $perPage, $offset]);
        $rows = $st->fetchAll();
    } catch (Exception $e) {
        apiError('服务器错误', 500);
    }
    $users = [];
    foreach ($rows as $r) {
        if ($r['is_banned']) continue;
        $users[] = [
            'id' => intval($r['id']),
            'username' => $r['username'],
            'avatar' => $r['avatar'] ?? '',
            'avatar_text' => $r['avatar_text'] ?? '',
            'level' => getExpProgress(intval($r['exp'])),
            'is_admin' => (bool)($r['is_admin'] ?? false),
            'is_founder' => (bool)($r['is_founder'] ?? false),
            'followed_at' => $r['followed_at'],
        ];
    }
    apiSuccess(['users' => $users, 'total' => $total, 'page' => $page, 'total_pages' => ceil($total / $perPage)]);
}

// ---- 关注/取消关注 (POST /api/app/users/{id}/follow) ----
if ($segments[0] === 'users' && isset($segments[1]) && is_numeric($segments[1]) && ($segments[2] ?? '') === 'follow' && $method === 'POST') {
    $user = requireAuth();
    $targetId = intval($segments[1]);
    if ($user['id'] == $targetId) apiError('不能关注自己');
    $result = toggleFollow($user['id'], $targetId);
    if (!$result['success']) apiError($result['message']);
    apiSuccess(['following' => $result['following']], $result['following'] ? '已关注' : '已取消关注');
}

// ---- 当前用户信息 ----
if ($segments === ['user', 'profile'] && $method === 'GET') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    $followStats = getUserFollowStats($user['id']);
    $receivedLikes = getUserReceivedLikes($user['id']);
    $formatted = formatUser($fullUser, true);
    $formatted['followers_count'] = $followStats['followers'];
    $formatted['following_count'] = $followStats['following'];
    $formatted['likes_received_count'] = $receivedLikes;
    apiSuccess($formatted);
}

// ---- 文件上传 (POST /api/app/upload) ----
if ($segments === ['upload'] && $method === 'POST') {
    requireAuth();
    if (empty($_FILES['file'])) apiError('请选择文件');
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) apiError('上传失败');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $imgExts = ['jpg','jpeg','png','gif','webp'];
    $modExts = ['zbgamemodpack'];
    $allowed = array_merge($imgExts, $modExts);
    if (!in_array($ext, $allowed)) apiError('不支持的文件类型: .' . $ext);
    $isImg = in_array($ext, $imgExts);
    $maxSize = $isImg ? 10*1024*1024 : 50*1024*1024;
    if ($file['size'] > $maxSize) apiError('文件不能超过' . ($isImg?'10MB':'50MB'));
    $subdir = $isImg ? '/../uploads/' : '/../uploads/attachments/';
    $uploadDir = __DIR__ . $subdir;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $filename = ($isImg ? 'app_' : 'mod_') . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) apiError('保存失败', 500);
    $url = ($isImg ? '/uploads/' : '/uploads/attachments/') . $filename;
    apiSuccess(['url' => $url, 'name' => $file['name'], 'size' => $file['size']], '上传成功');
}

// ---- 修改用户名 (PUT /api/app/user/username) ----
if ($segments === ['user', 'username'] && $method === 'PUT') {
    $user = requireAuth();
    $body = getJsonBody();
    $newName = trim($body['username'] ?? '');
    if (empty($newName)) apiError('用户名不能为空');
    $len = mb_strlen($newName, 'UTF-8');
    if ($len < 2 || $len > 16) apiError('用户名长度需在2-16个字符之间');
    if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $newName)) apiError('用户名只能包含字母、数字、下划线和中文');

    try {
        $pdo = getDbConnection();
        $fullUser = getUserById($user['id']);
        $isAdminOrFounder = ($fullUser['is_admin'] || $fullUser['is_founder']);
        if (!$isAdminOrFounder && !empty($fullUser['last_username_change'])) {
            $nextTs = strtotime($fullUser['last_username_change']) + 30*86400;
            if ($nextTs > time()) {
                $nextDate = date('Y-m-d', $nextTs);
                apiError('用户名每30天只能修改一次，下次可修改日期：'.$nextDate);
            }
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$newName, $user['id']]);
        if ($stmt->fetchColumn() > 0) apiError('用户名已被占用');

        $pdo->prepare("UPDATE users SET username = ?, last_username_change = NOW() WHERE id = ?")
            ->execute([$newName, $user['id']]);
        apiSuccess(['username' => $newName], '用户名修改成功');
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'apiError') === 0) throw $e;
        apiError($e->getMessage(), 500);
    }
}

// ---- 修改密码 (PUT /api/app/user/password) ----
if ($segments === ['user', 'password'] && $method === 'PUT') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (empty($fullUser['email'])) apiError('未绑定邮箱');
    $body = getJsonBody();
    $code = trim($body['code'] ?? '');
    $newPass = $body['new_password'] ?? '';
    if (empty($code)) apiError('请输入邮箱验证码');
    if (strlen($newPass) < 6) apiError('新密码至少6位');

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM verification_tokens WHERE target=? AND type='email_code' AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$fullUser['email']]);
        $ec = $stmt->fetch();
        if (!$ec || $ec['code'] !== $code) apiError('邮箱验证码错误或已过期');
        $pdo->prepare("UPDATE verification_tokens SET used=1 WHERE id=?")->execute([$ec['id']]);

        $hashed = password_hash($newPass, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user['id']]);
        apiSuccess(null, '密码修改成功');
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'apiError') === 0) throw $e;
        apiError($e->getMessage(), 500);
    }
}

// ---- 发送密码修改验证码 (POST /api/app/auth/send-pwd-code) ----
if ($segments === ['auth', 'send-pwd-code'] && $method === 'POST') {
    $body = getJsonBody();
    $email = trim($body['email'] ?? '');
    if (empty($email)) apiError('请输入邮箱');

    // 不要求登录 — 通过邮箱查找用户
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, email, username FROM users WHERE email = ? AND is_banned = 0 LIMIT 1");
    $stmt->execute([$email]);
    $fullUser = $stmt->fetch();
    if (!$fullUser) apiError('该邮箱未注册');

    try {
        $pdo = getDbConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Rate limit
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM verification_tokens WHERE target=? AND type='email_code' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute([$fullUser['email']]);
        if ($stmt->fetchColumn() >= 5) apiError('每小时最多发送5次验证码');
        $stmt = $pdo->prepare("SELECT created_at FROM verification_tokens WHERE target=? AND type='email_code' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$fullUser['email']]);
        $last = $stmt->fetch();
        if ($last && (time()-strtotime($last['created_at']))<60) apiError('发送过于频繁，请60秒后再试');

        $code = sprintf("%06d", mt_rand(0, 999999));
        $expires = date('Y-m-d H:i:s', time()+300);
        $pdo->prepare("INSERT INTO verification_tokens (token,type,target,code,expires_at) VALUES (?,?,?,?,?)")
            ->execute([bin2hex(random_bytes(16)), 'email_code', $fullUser['email'], $code, $expires]);

        $subject = '主播模拟器论坛 - 密码修改验证码';
        $mailBody = "您的验证码是：{$code}\n\n验证码5分钟内有效，请勿泄露。\n\n主播模拟器论坛";
        sendEmail($fullUser['email'], $subject, $mailBody);
        apiSuccess(null, '验证码已发送');
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'apiError') === 0) throw $e;
        apiError($e->getMessage(), 500);
    }
}

// ---- 忘记密码重置 (POST /api/app/user/password) — 无需登录，使用邮箱+验证码 ----
if ($segments === ['user', 'password'] && $method === 'POST') {
    $body = getJsonBody();
    $email = trim($body['email'] ?? '');
    $code = trim($body['code'] ?? '');
    $newPass = $body['password'] ?? '';
    if (empty($email)) apiError('请输入邮箱');
    if (empty($code)) apiError('请输入验证码');
    if (strlen($newPass) < 6) apiError('新密码至少6位');

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND is_banned = 0 LIMIT 1");
        $stmt->execute([$email]);
        $fullUser = $stmt->fetch();
        if (!$fullUser) apiError('该邮箱未注册');

        $stmt = $pdo->prepare("SELECT * FROM verification_tokens WHERE target=? AND type='email_code' AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$email]);
        $ec = $stmt->fetch();
        if (!$ec || $ec['code'] !== $code) apiError('验证码错误或已过期');
        $pdo->prepare("UPDATE verification_tokens SET used=1 WHERE id=?")->execute([$ec['id']]);

        $hashed = password_hash($newPass, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $fullUser['id']]);
        apiSuccess(null, '密码重置成功');
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'apiError') === 0) throw $e;
        apiError($e->getMessage(), 500);
    }
}

// ---- 注销账号 (DELETE /api/app/user/account) ----
if ($segments === ['user', 'account'] && $method === 'DELETE') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!empty($fullUser['is_founder'])) apiError('站长账号不能注销');
    $body = getJsonBody();
    $password = $body['password'] ?? '';
    if (empty($password)) apiError('请输入密码');
    if (!verifyUserPassword($user['id'], $password)) apiError('密码错误');
    if (!forceDeleteUser($user['id'])) apiError('注销失败', 500);
    apiSuccess(null, '账号已成功注销');
}

// ---- 更新个人资料 (PUT /api/app/user/profile) ----
if ($segments === ['user', 'profile'] && $method === 'PUT') {
    $user = requireAuth();
    $body = getJsonBody();

    $updates = [];
    $params = [];

    if (isset($body['avatar_text'])) {
        $updates[] = "avatar_text = ?";
        $params[] = trim($body['avatar_text']);
    }
    if (isset($body['avatar'])) {
        $updates[] = "avatar = ?";
        $params[] = trim($body['avatar']);
    }
    if (isset($body['profile_background'])) {
        $updates[] = "profile_background = ?";
        $params[] = trim($body['profile_background']);
    }
    if (isset($body['theme'])) {
        $theme = in_array($body['theme'], ['light', 'dark']) ? $body['theme'] : 'light';
        $updates[] = "theme = ?";
        $params[] = $theme;
    }
    if (isset($body['chat_username'])) {
        $updates[] = "chat_username = ?";
        $params[] = trim($body['chat_username']);
    }

    if (empty($updates)) {
        apiError('没有需要更新的字段');
    }

    try {
        $params[] = $user['id'];
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        $updated = getUserById($user['id']);
        apiSuccess(formatUser($updated, true), '更新成功');
    } catch (PDOException $e) {
        apiError('更新失败: ' . $e->getMessage(), 500);
    }
}

// ---- 用户公开资料 ----
if (count($segments) === 2 && $segments[0] === 'users' && is_numeric($segments[1]) && $method === 'GET') {
    $targetId = intval($segments[1]);
    $currentUser = optionalAuth();
    $currentUserId = $currentUser ? $currentUser['id'] : 0;

    $target = getUserById($targetId);
    if (!$target) apiError('用户不存在', 404);

    $formatted = formatUser($target);

    // 附加关注状态
    $isFollowing = false;
    if ($currentUser && $currentUser['id'] > 0) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = ?");
            $stmt->execute([$currentUserId, $targetId]);
            $isFollowing = $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {}
    }
    $formatted['is_following'] = $isFollowing;

    apiSuccess($formatted);
}

// ---- 用户的帖子列表 ----
if (count($segments) >= 3 && $segments[0] === 'users' && is_numeric($segments[1]) && $segments[2] === 'posts' && $method === 'GET') {
    $targetId = intval($segments[1]);
    $currentUser = optionalAuth();
    $currentUserId = $currentUser ? $currentUser['id'] : 0;

    $page = max(1, getIntParam('page', 1));
    $perPage = min(MAX_PAGE_SIZE, max(1, getIntParam('per_page', 10)));

    $posts = getUserPosts($targetId, $page, $perPage);
    $total = getUserPostsCount($targetId);

    $postsData = array_map(function($p) use ($currentUserId) {
        $formatted = formatPost($p, $currentUserId);
        // 验证图片文件是否存在
        if (!empty($formatted['images'])) {
            $validated = [];
            foreach ($formatted['images'] as $imgUrl) {
                $validated[] = checkImageFileExists($imgUrl);
            }
            // 保持 images 不变供下游使用，增加 image_valid 并行数组
            $formatted['images'] = $validated;
            $formatted['image_valid'] = array_map(function($v) { return $v['valid']; }, $validated);
            $formatted['images'] = array_map(function($v) { return $v['url']; }, $validated);
        }
        return $formatted;
    }, $posts);

    apiSuccess([
        'items' => $postsData,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => intval($total),
            'total_pages' => ceil($total / $perPage),
        ]
    ]);
}

// ---- 用户收藏列表 ----
if (count($segments) >= 3 && $segments[0] === 'users' && is_numeric($segments[1]) && $segments[2] === 'favorites' && $method === 'GET') {
    $targetId = intval($segments[1]);
    $currentUser = optionalAuth();
    $currentUserId = $currentUser ? $currentUser['id'] : 0;

    // 只能看自己的收藏
    if ($currentUserId !== $targetId) {
        apiError('无权查看他人收藏', 403);
    }

    $page = max(1, getIntParam('page', 1));
    $perPage = min(MAX_PAGE_SIZE, max(1, getIntParam('per_page', 10)));

    $favorites = getUserFavorites($targetId, $page, $perPage);
    $total = getUserFavoritesCount($targetId);

    $postsData = array_map(function($p) use ($currentUserId) {
        return validatePostImages(formatPost($p, $currentUserId));
    }, $favorites);

    apiSuccess([
        'items' => $postsData,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => intval($total),
            'total_pages' => ceil($total / $perPage),
        ]
    ]);
}

// ---- 通知列表 ----
if ($segments === ['notifications'] && $method === 'GET') {
    $user = requireAuth();

    $page = max(1, getIntParam('page', 1));
    $perPage = min(MAX_PAGE_SIZE, max(1, getIntParam('per_page', 20)));
    $type = getStrParam('type', '');

    // 需要获取带actor信息的通知
    $offset = ($page - 1) * $perPage;

    try {
        $pdo = getDbConnection();

        $where = "n.user_id = ?";
        $params = [$user['id']];
        if (!empty($type)) {
            // 支持逗号分隔的多类型过滤
            $types = array_filter(array_map('trim', explode(',', $type)));
            if (!empty($types)) {
                $placeholders = implode(',', array_fill(0, count($types), '?'));
                $where .= " AND n.type IN ($placeholders)";
                $params = array_merge($params, $types);
            }
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications n WHERE {$where}");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        $params[] = $offset;
        $params[] = $perPage;
        $stmt = $pdo->prepare("SELECT n.*,
                                      a.username as actor_username, a.avatar as actor_avatar, a.avatar_text as actor_avatar_text, a.avatar_bg_color as actor_avatar_bg
                               FROM notifications n
                               LEFT JOIN users a ON n.actor_id = a.id
                               WHERE {$where}
                               ORDER BY n.created_at DESC
                               LIMIT ?, ?");
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();

        $notificationsData = array_map('formatNotification', $notifications);

        // 计算互动消息未读（仅互动类型）
        $unreadCount = getUnreadInteractionCount($user['id']);

        apiSuccess([
            'items' => $notificationsData,
            'unread_count' => intval($unreadCount),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => intval($total),
                'total_pages' => ceil($total / $perPage),
            ]
        ]);
    } catch (PDOException $e) {
        apiError('服务器错误', 500);
    }
}

// ---- 全部已读 ----
if ($segments === ['notifications', 'read-all'] && $method === 'POST') {
    $user = requireAuth();
    markAllNotificationsAsRead($user['id']);
    apiSuccess(null, '已全部标记为已读');
}

// ---- 搜索 ----
if ($segments === ['search'] && $method === 'GET') {
    $currentUser = optionalAuth();
    $currentUserId = $currentUser ? $currentUser['id'] : 0;

    $keyword = getStrParam('q', '');
    $type = getStrParam('type', 'posts');
    $page = max(1, getIntParam('page', 1));
    $perPage = min(MAX_PAGE_SIZE, max(1, getIntParam('per_page', DEFAULT_PAGE_SIZE)));
    $categoryId = getIntParam('category_id', 0);

    if (empty($keyword)) {
        apiError('请输入搜索关键词');
    }

    if ($type === 'users') {
        $users = searchUsers($keyword, $page, $perPage);
        $total = searchUsersCount($keyword);

        $usersData = array_map(function($u) {
            return [
                'id' => intval($u['id']),
                'username' => $u['username'] ?? '',
                'public_uid' => $u['public_uid'] ?? '',
                'avatar' => $u['avatar'] ?? '',
                'avatar_text' => $u['avatar_text'] ?? '',
                'is_admin' => (bool)($u['is_admin'] ?? false),
                'is_founder' => (bool)($u['is_founder'] ?? false),
                'points' => intval($u['points'] ?? 0),
                'exp' => intval($u['exp'] ?? 0),
                'level' => getExpProgress(intval($u['exp'] ?? 0)),
                'is_banned' => (bool)($u['is_banned'] ?? false),
                'created_at' => $u['created_at'] ?? '',
            ];
        }, $users);

        apiSuccess([
            'items' => $usersData,
            'keyword' => $keyword,
            'type' => 'users',
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => intval($total),
                'total_pages' => ceil($total / $perPage),
            ]
        ]);
    } else {
        $posts = searchPosts($keyword, $categoryId, $page, $perPage);
        $total = searchPostsCount($keyword, $categoryId);

        $postsData = array_map(function($p) use ($currentUserId) {
            return validatePostImages(formatPost($p, $currentUserId));
        }, $posts);

        apiSuccess([
            'items' => $postsData,
            'keyword' => $keyword,
            'type' => 'posts',
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => intval($total),
                'total_pages' => ceil($total / $perPage),
            ]
        ]);
    }
}

// ---- 签到 ----
if ($segments === ['signin'] && $method === 'POST') {
    $user = requireAuth();
    $result = userSignIn($user['id']);

    if ($result['success']) {
        apiSuccess([
            'message' => $result['message'],
            'points_awarded' => $result['points_awarded'] ?? SIGNIN_POINTS,
            'continuous_days' => $result['continuous_days'] ?? 0
        ]);
    } else {
        apiError($result['message']);
    }
}

// ---- 签到状态 ----
if ($segments === ['signin', 'status'] && $method === 'GET') {
    $user = requireAuth();
    $hasSigned = hasSignedInToday($user['id']);
    $continuous = getContinuousSigninDays($user['id']);

    apiSuccess([
        'has_signed_today' => $hasSigned,
        'continuous_days' => $continuous,
        'base_points' => SIGNIN_POINTS,
        'bonus_days' => SIGNIN_BONUS_DAYS,
        'bonus_points' => SIGNIN_BONUS_POINTS,
    ]);
}

// ================== Admin APIs ==================
// All admin endpoints require admin or founder

// ---- Admin: List users (GET /api/app/admin/users) ----
if ($segments === ['admin', 'users'] && $method === 'GET') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = isset($_GET['per_page']) ? min(50, max(1, intval($_GET['per_page']))) : 20;
    $users = getAllUsers($page, $perPage);
    $total = getUserCount();
    $formatted = array_map(function($u) {
        return [
            'id' => intval($u['id']),
            'username' => $u['username'],
            'email' => $u['email'] ?? '',
            'points' => intval($u['points'] ?? 0),
            'exp' => intval($u['exp'] ?? 0),
            'is_admin' => (bool)($u['is_admin'] ?? false),
            'is_founder' => (bool)($u['is_founder'] ?? false),
            'is_banned' => (bool)($u['is_banned'] ?? false),
            'level' => getExpProgress(intval($u['exp'] ?? 0)),
            'created_at' => $u['created_at'] ?? '',
            'last_login' => $u['last_login'] ?? '',
        ];
    }, $users);
    apiSuccess(['items' => $formatted, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => ceil($total/$perPage)]]);
}

// ---- Admin: Update user (PUT /api/app/admin/users/{id}) ----
if (count($segments) >= 3 && $segments[0] === 'admin' && $segments[1] === 'users' && is_numeric($segments[2]) && $method === 'PUT') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    $targetId = intval($segments[2]);
    $target = getUserById($targetId);
    if (!$target) apiError('用户不存在', 404);
    if ($target['is_founder'] && !$fullUser['is_founder']) apiError('无权修改站长', 403);
    $body = getJsonBody();
    if (isset($body['is_banned'])) {
        setUserBan($targetId, $target["is_banned"] ? 0 : 1);
    }
    if (isset($body['is_admin']) && !$target['is_founder']) {
        setUserAdmin($targetId, $body['is_admin'] ? 1 : 0);
    }
    if (isset($body['points'])) {
        setUserPoints($targetId, intval($body['points']));
    }
    if (isset($body['exp'])) {
        updateUserExp($targetId, intval($body['exp']));
    }
    apiSuccess(null, '更新成功');
}

// ---- Admin: Get settings (GET /api/app/admin/settings) ----
if ($segments === ['admin', 'settings'] && $method === 'GET') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    $settings = [
        'registration_enabled' => getSetting('registration_enabled', '1') === '1',
        'captcha_enabled' => getSetting('captcha_enabled', '0') === '1',
        'email_verification_enabled' => getSetting('email_verification_enabled', '0') === '1',
        'auto_follow_founder' => getSetting('auto_follow_founder', '0') === '1',
        'pretty_url_enabled' => getSetting('pretty_url_enabled', '0') === '1',
    ];
    apiSuccess($settings);
}

// ---- Admin: Update settings (PUT /api/app/admin/settings) ----
if ($segments === ['admin', 'settings'] && $method === 'PUT') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    $body = getJsonBody();
    $map = [
        'registration_enabled' => 'registration_enabled',
        'captcha_enabled' => 'captcha_enabled',
        'email_verification_enabled' => 'email_verification_enabled',
        'auto_follow_founder' => 'auto_follow_founder',
        'pretty_url_enabled' => 'pretty_url_enabled',
    ];
    foreach ($map as $key => $dbKey) {
        if (isset($body[$key])) {
            setSetting($dbKey, $body[$key] ? '1' : '0');
        }
    }
    if (isset($body['auto_follow_founder']) && $body['auto_follow_founder']) {
        followAllUsersToFounder();
    }
    apiSuccess(null, '设置已保存');
}

// ---- Public: Get slides (GET /api/app/slides) ----
if ($segments === ['slides'] && $method === 'GET') {
    $slides = getAllSlides();
    apiSuccess($slides ?: []);
}

// ---- Admin: Get slides (GET /api/app/admin/slides) ----
if ($segments === ['admin', 'slides'] && $method === 'GET') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    $slides = getAllSlides();
    apiSuccess(['items' => $slides ?: []]);
}

// ---- Admin: Add slide (POST /api/app/admin/slides) ----
if ($segments === ['admin', 'slides'] && $method === 'POST') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    $body = getJsonBody();
    $imageUrl = $body['image_url'] ?? '';
    $linkUrl = $body['link_url'] ?? '';
    if (empty($imageUrl)) apiError('请上传图片');
    addSlide([        'image_url' => $imageUrl, 'link_url' => $linkUrl]);
    apiSuccess(null, '添加成功', 201);
}

// ---- Admin: Delete slide (DELETE /api/app/admin/slides/{id}) ----
if (count($segments) >= 3 && $segments[0] === 'admin' && $segments[1] === 'slides' && is_numeric($segments[2]) && $method === 'DELETE') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    deleteSlide(intval($segments[2]));
    apiSuccess(null, '删除成功');
}

// ---- Admin: Get links (GET /api/app/admin/links) ----
if ($segments === ['admin', 'links'] && $method === 'GET') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    $links = getAllLinks();
    apiSuccess(['items' => $links ?: []]);
}

// ---- Admin: Add link (POST /api/app/admin/links) ----
if ($segments === ['admin', 'links'] && $method === 'POST') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    $body = getJsonBody();
    $name = trim($body['name'] ?? '');
    $url = trim($body['url'] ?? '');
    if (empty($name) || empty($url)) apiError('名称和链接不能为空');
    addLink(['name' => $name, 'url' => $url]);
    apiSuccess(null, '添加成功', 201);
}

// ---- Admin: Delete link (DELETE /api/app/admin/links/{id}) ----
if (count($segments) >= 3 && $segments[0] === 'admin' && $segments[1] === 'links' && is_numeric($segments[2]) && $method === 'DELETE') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    deleteLink(intval($segments[2]));
    apiSuccess(null, '删除成功');
}

// ---- Admin: Delete user (DELETE /api/app/admin/users/{id}) ----
if (count($segments) >= 3 && $segments[0] === 'admin' && $segments[1] === 'users' && is_numeric($segments[2]) && $method === 'DELETE') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    $targetId = intval($segments[2]);
    if ($targetId == $user['id']) apiError('不能删除自己', 400);
    $target = getUserById($targetId);
    if (!$target) apiError('用户不存在', 404);
    if ($target['is_founder']) apiError('不能删除站长', 403);
    deleteUser($targetId);
    apiSuccess(null, '用户已删除');
}

// ---- Admin: Get email config (GET /api/app/admin/email-config) ----
if ($segments === ['admin', 'email-config'] && $method === 'GET') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    apiSuccess(getSmtpConfig());
}

// ---- Admin: Update email config (PUT /api/app/admin/email-config) ----
if ($segments === ['admin', 'email-config'] && $method === 'PUT') {
    $user = requireAuth();
    $fullUser = getUserById($user['id']);
    if (!$fullUser['is_admin'] && !$fullUser['is_founder']) apiError('权限不足', 403);
    $body = getJsonBody();
    $config = [];
    $fields = ['host', 'port', 'encryption', 'username', 'password', 'from_email', 'from_name'];
    foreach ($fields as $f) {
        if (isset($body[$f])) $config[$f] = $body[$f];
    }
    if (empty($config)) apiError('没有要更新的配置');
    if (!saveSmtpConfig($config)) apiError('保存失败', 500);
    apiSuccess(null, '邮箱配置已保存');
}

// ---- 置顶评论 (PUT /api/app/comments/{id}/top) - Admin/Founder only ----
if (count($segments) >= 3 && $segments[0] === 'comments' && is_numeric($segments[1]) && $segments[2] === 'top' && $method === 'PUT') {
    $user = requireAuth();
    if (!$user['is_admin'] && !$user['is_founder']) apiError('权限不足', 403);
    $commentId = intval($segments[1]);
    $body = getJsonBody();
    $isTop = !empty($body['is_top']) ? 1 : 0;
    $comment = getCommentById($commentId);
    if (!$comment) apiError('评论不存在', 404);
    if ($comment['parent_id'] !== null) apiError('只有顶级评论可以置顶', 400);
    if (setCommentTop($commentId, $isTop)) apiSuccess(['is_top' => (bool)$isTop], '操作成功');
    else apiError('操作失败', 500);
}

// ---- 移动帖子 (PUT /api/app/posts/{id}/move) - Admin/Founder only ----
if (count($segments) >= 3 && $segments[0] === 'posts' && is_numeric($segments[1]) && $segments[2] === 'move' && $method === 'PUT') {
    $user = requireAuth();
    if (!$user['is_admin'] && !$user['is_founder']) apiError('权限不足', 403);
    $postId = intval($segments[1]);
    $body = getJsonBody();
    $targetSlug = trim($body['target_slug'] ?? '');
    if (empty($targetSlug)) apiError('请选择目标分类');
    $validSlugs = ['mod', 'exchange', 'chat'];
    if (!in_array($targetSlug, $validSlugs)) apiError('无效的目标分类');
    $result = movePostToCategory($postId, $targetSlug);
    if ($result['success']) apiSuccess(['redirect' => $result['redirect'] ?? '/app/post.html?id='.$postId], '移动成功');
    else apiError($result['message'] ?? '移动失败');
}

// ==================== 群聊 API ====================
if (!defined('CHAT_DATA_DIR')) define('CHAT_DATA_DIR', __DIR__ . '/../data/chat/');

// ---- 群聊: 我的群组 (GET /api/app/chat/groups) ----
if (count($segments) >= 2 && $segments[0] === 'chat' && $segments[1] === 'groups' && !isset($segments[2]) && $method === 'GET') {
    $user = requireAuth();
    if (!file_exists(CHAT_DATA_DIR . 'groups.json')) apiSuccess([]);
    $groups = json_decode(file_get_contents(CHAT_DATA_DIR . 'groups.json'), true) ?: [];
    $myGroups = [];
    foreach ($groups as $gid => $g) {
        if (in_array($user['username'], $g['members'] ?? [])) {
            $myGroups[$gid] = ['id' => $gid, 'name' => $g['name'], 'creator' => $g['creator'] ?? 'system', 'member_count' => count($g['members'] ?? [])];
        }
    }
    apiSuccess($myGroups);
}

// ---- 群聊: 创建 (POST /api/app/chat/groups) ----
if (count($segments) >= 2 && $segments[0] === 'chat' && $segments[1] === 'groups' && !isset($segments[2]) && $method === 'POST') {
    $user = requireAuth();
    $body = getJsonBody();
    $name = trim($body['name'] ?? '');
    if (empty($name) || mb_strlen($name) < 2) apiError('群组名称至少2个字符');
    @mkdir(CHAT_DATA_DIR, 0755, true);
    $groups = file_exists(CHAT_DATA_DIR . 'groups.json') ? json_decode(file_get_contents(CHAT_DATA_DIR . 'groups.json'), true) : [];
    if (!is_array($groups)) $groups = [];
    $gid = uniqid();
    $groups[$gid] = ['name' => $name, 'creator' => $user['username'], 'created_at' => time(), 'members' => [$user['username']]];
    file_put_contents(CHAT_DATA_DIR . 'groups.json', json_encode($groups, JSON_UNESCAPED_UNICODE));
    file_put_contents(CHAT_DATA_DIR . "group_{$gid}.json", json_encode([]));
    apiSuccess(['id' => $gid, 'name' => $name], '创建成功');
}

// ---- 群聊: 加入 (POST /api/app/chat/groups/join) ----
if (count($segments) >= 3 && $segments[0] === 'chat' && $segments[1] === 'groups' && $segments[2] === 'join' && $method === 'POST') {
    $user = requireAuth();
    $body = getJsonBody();
    $gid = trim($body['group_id'] ?? '');
    if (empty($gid)) apiError('群组ID不能为空');
    if (!file_exists(CHAT_DATA_DIR . 'groups.json')) apiError('群组不存在');
    $groups = json_decode(file_get_contents(CHAT_DATA_DIR . 'groups.json'), true) ?: [];
    if (!isset($groups[$gid])) apiError('群组不存在');
    if (!in_array($user['username'], $groups[$gid]['members'] ?? [])) {
        $groups[$gid]['members'][] = $user['username'];
        file_put_contents(CHAT_DATA_DIR . 'groups.json', json_encode($groups, JSON_UNESCAPED_UNICODE));
    }
    apiSuccess(null, '加入成功');
}

// ---- 群聊: 消息列表 (GET /api/app/chat/groups/{id}/messages) ----
if (count($segments) >= 4 && $segments[0] === 'chat' && $segments[1] === 'groups' && $segments[3] === 'messages' && $method === 'GET') {
    $user = requireAuth();
    $gid = $segments[2];
    @mkdir(CHAT_DATA_DIR, 0755, true);
    $msgFile = CHAT_DATA_DIR . "group_{$gid}.json";
    if (!file_exists($msgFile)) apiSuccess(['messages' => [], 'current_user' => $user['username']]);
    $msgs = json_decode(file_get_contents($msgFile), true) ?: [];
    if (count($msgs) > 200) { $msgs = array_slice($msgs, -200); file_put_contents($msgFile, json_encode($msgs, JSON_UNESCAPED_UNICODE)); }
    // Format messages
    $replyMap = [];
    foreach ($msgs as $m) { $replyMap[$m['id']??''] = ['username' => $m['username']??'', 'content' => $m['content'] ?? '']; }
    $items = array_map(function($m) use ($replyMap) {
        $m['formatted_time'] = date('H:i', $m['time']??time());
        if (date('Y-m-d', $m['time']??time()) !== date('Y-m-d')) $m['formatted_time'] = date('m-d H:i', $m['time']??time());
        $m['deleted'] = $m['deleted'] ?? false;
        if (!empty($m['reply_to']) && isset($replyMap[$m['reply_to']])) {
            $m['reply_to_content'] = $replyMap[$m['reply_to']]['content'];
            $m['reply_to_username'] = $replyMap[$m['reply_to']]['username'];
        }
        return $m;
    }, $msgs);
    apiSuccess(['messages' => $items, 'current_user' => $user['username']]);
}

// ---- 群聊: 发送消息 (POST /api/app/chat/groups/{id}/messages) ----
if (count($segments) >= 4 && $segments[0] === 'chat' && $segments[1] === 'groups' && $segments[3] === 'messages' && !isset($segments[4]) && $method === 'POST') {
    $user = requireAuth();
    $gid = $segments[2];
    @mkdir(CHAT_DATA_DIR, 0755, true);
    $body = getJsonBody();
    $content = trim($body['content'] ?? '');
    $type = $body['type'] ?? 'text';
    $replyTo = $body['reply_to'] ?? null;
    $images = $body['images'] ?? [];
    if (empty($content) && $type === 'text' && empty($images)) apiError('消息不能为空');
    if (!file_exists(CHAT_DATA_DIR . 'groups.json')) apiError('群组不存在');
    $groups = json_decode(file_get_contents(CHAT_DATA_DIR . 'groups.json'), true) ?: [];
    if (!isset($groups[$gid]) || !in_array($user['username'], $groups[$gid]['members'] ?? [])) apiError('你不在这个群组中');
    $msgFile = CHAT_DATA_DIR . "group_{$gid}.json";
    $msgs = file_exists($msgFile) ? json_decode(file_get_contents($msgFile), true) : [];
    if (!is_array($msgs)) $msgs = [];
    $newMsgs = [];
    // Add image messages first
    foreach ($images as $imgUrl) {
        if (empty($imgUrl)) continue;
        $newMsgs[] = [
            'id' => uniqid(),
            'user_id' => $user['id'],
            'username' => $user['username'],
            'avatar' => $user['avatar'] ?? '',
            'avatar_text' => $user['avatar_text'] ?? '',
            'content' => '[图片]',
            'type' => 'image',
            'file_url' => $imgUrl,
            'time' => time(),
            'reply_to' => null,
            'deleted' => false
        ];
    }
    // Add text message if has content
    $msg = null;
    if (!empty($content) || $type !== 'text') {
        $msg = [
            'id' => uniqid(),
            'user_id' => $user['id'],
            'username' => $user['username'],
            'avatar' => $user['avatar'] ?? '',
            'avatar_text' => $user['avatar_text'] ?? '',
            'content' => $type === 'text' ? htmlspecialchars($content, ENT_QUOTES, 'UTF-8') : $content,
            'type' => $type,
            'file_url' => '',
            'time' => time(),
            'reply_to' => $replyTo,
            'deleted' => false
        ];
        $newMsgs[] = $msg;
    } else {
        // No text content - put reply_to on first image
        if (!empty($newMsgs)) $newMsgs[0]['reply_to'] = $replyTo;
        $msg = $newMsgs[0] ?? null;
    }
    if (empty($newMsgs)) apiError('消息不能为空');
    foreach ($newMsgs as $nm) $msgs[] = $nm;
    if (count($msgs) > 200) $msgs = array_slice($msgs, -200);
    file_put_contents($msgFile, json_encode($msgs, JSON_UNESCAPED_UNICODE));
    // Enrich reply info for response
    if (!empty($msg) && !empty($msg['reply_to'])) {
        foreach ($msgs as $m) { if (($m['id']??'') === $msg['reply_to']) { $msg['reply_to_content'] = $m['content'] ?? ''; $msg['reply_to_username'] = $m['username'] ?? ''; break; } }
    }
    // Update online
    $onlineFile = CHAT_DATA_DIR . 'online.json';
    $online = file_exists($onlineFile) ? json_decode(file_get_contents($onlineFile), true) : [];
    if (!is_array($online)) $online = [];
    $online[$user['username']] = time();
    file_put_contents($onlineFile, json_encode($online));
    apiSuccess(count($newMsgs)===1 ? $msg : $newMsgs, '发送成功');
}

// ---- 群聊: 在线人数 (GET /api/app/chat/online) ----
if (count($segments) >= 2 && $segments[0] === 'chat' && $segments[1] === 'online' && $method === 'GET') {
    $onlineFile = CHAT_DATA_DIR . 'online.json';
    $online = file_exists($onlineFile) ? json_decode(file_get_contents($onlineFile), true) : [];
    if (!is_array($online)) $online = [];
    $cutoff = time() - 120;
    $count = 0;
    foreach ($online as $u => $t) { if ($t > $cutoff) $count++; }
    apiSuccess(['count' => $count]);
}

// ---- 群聊: 上传图片 (POST /api/app/chat/upload) ----
if (count($segments) >= 2 && $segments[0] === 'chat' && $segments[1] === 'upload' && $method === 'POST') {
    $user = requireAuth();
    if (empty($_FILES['image'])) apiError('请选择图片');
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) apiError('上传失败');
    if ($file['size'] > 5 * 1024 * 1024) apiError('图片不能超过5MB');
    $gid = $_POST['group_id'] ?? '';
    $replyTo = $_POST['reply_to'] ?? null;
    if (empty($gid)) apiError('群组ID不能为空');
    // 检查用户在群组中
    $groupsFile = CHAT_DATA_DIR . 'groups.json';
    if (!file_exists($groupsFile)) apiError('群组不存在');
    $groups = json_decode(file_get_contents($groupsFile), true) ?: [];
    if (!isset($groups[$gid]) || !in_array($user['username'], $groups[$gid]['members'] ?? [])) apiError('你不在这个群组中');
    // 上传文件
    require_once __DIR__ . '/../functions.php';
    $uploadResult = uploadFile($file, 'chat');
    if (!$uploadResult['success']) apiError($uploadResult['message']);
    $fileUrl = $uploadResult['file_url'];
    // 保存图片消息
    @mkdir(CHAT_DATA_DIR, 0755, true);
    $msgFile = CHAT_DATA_DIR . "group_{$gid}.json";
    $msgs = file_exists($msgFile) ? json_decode(file_get_contents($msgFile), true) : [];
    if (!is_array($msgs)) $msgs = [];
    $avatar = $user['avatar'] ?? '';
    $avatarText = $user['avatar_text'] ?? '';
    $msg = [
        'id' => uniqid(),
        'user_id' => $user['id'],
        'username' => $user['username'],
        'avatar' => $avatar,
        'avatar_text' => $avatarText,
        'content' => '[图片]',
        'type' => 'image',
        'file_url' => $fileUrl,
        'time' => time(),
        'reply_to' => $replyTo,
        'deleted' => false
    ];
    $msgs[] = $msg;
    if (count($msgs) > 200) $msgs = array_slice($msgs, -200);
    file_put_contents($msgFile, json_encode($msgs, JSON_UNESCAPED_UNICODE));
    // Update online
    $onlineFile = CHAT_DATA_DIR . 'online.json';
    $online = file_exists($onlineFile) ? json_decode(file_get_contents($onlineFile), true) : [];
    if (!is_array($online)) $online = [];
    $online[$user['username']] = time();
    file_put_contents($onlineFile, json_encode($online));
    apiSuccess(['url' => $fileUrl, 'message' => $msg], '上传成功');
}

// ---- 群聊: 撤回消息 (POST /api/app/chat/groups/{id}/messages/delete) ----
if (count($segments) >= 5 && $segments[0] === 'chat' && $segments[1] === 'groups' && $segments[3] === 'messages' && $segments[4] === 'delete' && $method === 'POST') {
    $user = requireAuth();
    $gid = $segments[2];
    $body = getJsonBody();
    $messageId = trim($body['message_id'] ?? '');
    if (empty($messageId)) apiError('消息ID不能为空');
    $msgFile = CHAT_DATA_DIR . "group_{$gid}.json";
    if (!file_exists($msgFile)) apiError('群组不存在');
    $msgs = json_decode(file_get_contents($msgFile), true) ?: [];
    if (!is_array($msgs)) $msgs = [];
    $found = false;
    $updated = false;
    foreach ($msgs as $i => $m) {
        if (($m['id'] ?? '') === $messageId) {
            $found = true;
            if (!empty($m['deleted'])) apiError('消息已被撤回');
            // 仅消息作者或管理员可撤回
            if ($m['username'] !== $user['username'] && !($user['is_admin'] ?? false) && !($user['is_founder'] ?? false)) apiError('只能撤回自己的消息');
            // 24小时限制
            if (time() - ($m['time'] ?? 0) > 86400) apiError('只能撤回24小时内的消息');
            $msgs[$i]['deleted'] = true;
            $msgs[$i]['deleted_at'] = time();
            $updated = true;
            break;
        }
    }
    if (!$found) apiError('消息不存在');
    if ($updated) {
        file_put_contents($msgFile, json_encode($msgs, JSON_UNESCAPED_UNICODE));
        apiSuccess(null, '消息已撤回');
    }
    apiError('撤回失败');
}

// ---- 私信: 会话列表 (GET /api/app/messages/conversations) ----
if (count($segments) >= 2 && $segments[0] === 'messages' && $segments[1] === 'conversations' && $method === 'GET') {
    $user = requireAuth();
    $userId = $user['id'];
    $pdo = getDbConnection();
    // 获取所有跟我有过私信的用户，按最新消息排序
    $stmt = $pdo->prepare("SELECT 
        CASE WHEN m.from_user_id = ? THEN m.to_user_id ELSE m.from_user_id END as other_id,
        u.username, u.avatar, u.avatar_text, u.is_admin, u.is_founder,
        (SELECT COUNT(*) FROM private_messages WHERE to_user_id = ? AND from_user_id = CASE WHEN m.from_user_id = ? THEN m.to_user_id ELSE m.from_user_id END AND is_read = 0) as unread_count,
        MAX(m.created_at) as last_time,
        (SELECT content FROM private_messages pm WHERE (pm.from_user_id = m.from_user_id AND pm.to_user_id = m.to_user_id) OR (pm.from_user_id = m.to_user_id AND pm.to_user_id = m.from_user_id) ORDER BY pm.created_at DESC LIMIT 1) as last_msg
        FROM private_messages m
        JOIN users u ON u.id = CASE WHEN m.from_user_id = ? THEN m.to_user_id ELSE m.from_user_id END
        WHERE m.from_user_id = ? OR m.to_user_id = ?
        GROUP BY other_id, u.username, u.avatar, u.avatar_text, u.is_admin, u.is_founder
        ORDER BY last_time DESC");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Format
    $result = [];
    foreach ($conversations as $conv) {
        $result[] = [
            'user_id' => intval($conv['other_id']),
            'username' => $conv['username'],
            'avatar' => $conv['avatar'],
            'avatar_text' => $conv['avatar_text'],
            'is_admin' => (bool)($conv['is_admin'] ?? false),
            'is_founder' => (bool)($conv['is_founder'] ?? false),
            'unread_count' => intval($conv['unread_count']),
            'last_msg' => $conv['last_msg'] ? mb_substr($conv['last_msg'], 0, 50) : '',
            'last_time' => $conv['last_time']
        ];
    }
    apiSuccess($result);
}

// ---- 私信: 与某用户的对话 (GET /api/app/messages/conversation/{user_id}) ----
if (count($segments) >= 3 && $segments[0] === 'messages' && $segments[1] === 'conversation' && is_numeric($segments[2]) && $method === 'GET') {
    $user = requireAuth();
    $myId = $user['id'];
    $otherId = intval($segments[2]);
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = min(50, max(1, intval($_GET['per_page'] ?? 30)));
    $pdo = getDbConnection();

    // Mark as read
    $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE to_user_id = ? AND from_user_id = ? AND is_read = 0")
        ->execute([$myId, $otherId]);

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE (from_user_id = ? AND to_user_id = ?) OR (from_user_id = ? AND to_user_id = ?)");
    $totalStmt->execute([$myId, $otherId, $otherId, $myId]);
    $total = $totalStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("SELECT m.*, u.username, u.avatar, u.avatar_text FROM private_messages m
        JOIN users u ON u.id = m.from_user_id
        WHERE (m.from_user_id = ? AND m.to_user_id = ?) OR (m.from_user_id = ? AND m.to_user_id = ?)
        ORDER BY m.created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$myId, $otherId, $otherId, $myId, $perPage, $offset]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get other user info
    $uStmt = $pdo->prepare("SELECT id, username, avatar, avatar_text, is_admin, is_founder, exp FROM users WHERE id = ?");
    $uStmt->execute([$otherId]);
    $otherUser = $uStmt->fetch();

    $items = array_map(function($m) use ($myId) {
        return [
            'id' => intval($m['id']),
            'from_user_id' => intval($m['from_user_id']),
            'is_mine' => intval($m['from_user_id']) === $myId,
            'username' => $m['username'],
            'avatar' => $m['avatar'],
            'avatar_text' => $m['avatar_text'],
            'content' => $m['content'],
            'is_read' => (bool)$m['is_read'],
            'created_at' => $m['created_at']
        ];
    }, array_reverse($messages));

    apiSuccess([
        'other_user' => $otherUser ? [
            'id' => intval($otherUser['id']),
            'username' => $otherUser['username'],
            'avatar' => $otherUser['avatar'],
            'avatar_text' => $otherUser['avatar_text'],
            'is_admin' => (bool)($otherUser['is_admin'] ?? false),
            'is_founder' => (bool)($otherUser['is_founder'] ?? false)
        ] : null,
        'messages' => $items,
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => intval($total), 'total_pages' => ceil($total / $perPage)]
    ]);
}

// ---- 私信: 发送 (POST /api/app/messages/send) ----
if (count($segments) >= 2 && $segments[0] === 'messages' && $segments[1] === 'send' && $method === 'POST') {
    $user = requireAuth();
    $myId = $user['id'];
    $body = getJsonBody();
    $toUserId = intval($body['to_user_id'] ?? 0);
    $content = trim($body['content'] ?? '');
    $images = $body['images'] ?? [];
    if ($toUserId <= 0) apiError('请指定接收用户');
    if ($toUserId === $myId) apiError('不能给自己发私信');
    if (empty($content) && empty($images)) apiError('消息不能为空');
    if (mb_strlen($content) > 2000) apiError('消息内容过长');

    // Verify recipient exists
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, is_banned FROM users WHERE id = ?");
    $stmt->execute([$toUserId]);
    $recipient = $stmt->fetch();
    if (!$recipient) apiError('用户不存在');
    if ($recipient['is_banned']) apiError('对方账号已被封禁');

    $lastId = null;
    foreach ($images as $imgUrl) {
        if (empty($imgUrl)) continue;
        $stmt = $pdo->prepare("INSERT INTO private_messages (from_user_id, to_user_id, content, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
        $stmt->execute([$myId, $toUserId, '[图片] ' . $imgUrl]);
        $lastId = intval($pdo->lastInsertId());
    }
    if (!empty($content)) {
        $stmt = $pdo->prepare("INSERT INTO private_messages (from_user_id, to_user_id, content, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
        $stmt->execute([$myId, $toUserId, $content]);
        $lastId = intval($pdo->lastInsertId());
    }
    apiSuccess(['id' => $lastId, 'content' => $content ?: '', 'created_at' => date('Y-m-d H:i:s')], '发送成功');
}

// ---- 群聊列表 (GET /api/app/groups) ----
if ($segments === ['groups'] && $method === 'GET') {
    $user = requireAuth();
    $pdo = getDbConnection();    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $u = $stmt->fetch();
    if (!$u) apiError('用户不存在', 404);
    $username = $u['username'];

    $chatGroupsFile = __DIR__ . '/../data/chat/groups.json';
    $chatReadStateFile = __DIR__ . '/../data/chat/read_state.json';

    $groups = file_exists($chatGroupsFile) ? (json_decode(file_get_contents($chatGroupsFile), true) ?: []) : [];

    // 自动加入官方群 (ID=1)
    if (isset($groups['1']) && !in_array($username, $groups['1']['members'] ?? [])) {
        $groups['1']['members'][] = $username;
        file_put_contents($chatGroupsFile, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    $readState = file_exists($chatReadStateFile) ? (json_decode(file_get_contents($chatReadStateFile), true) ?: []) : [];

    $userGroups = [];
    $totalUnread = 0;
    foreach ($groups as $gid => $group) {
        if (!in_array($username, $group['members'] ?? [])) continue;
        $key = $user['id'] . '_' . $gid;
        $lastRead = $readState[$key]['time'] ?? 0;
        $msgFile = __DIR__ . "/../data/chat/group_{$gid}.json";
        $unread = 0;
        $hasMention = false;
        if (file_exists($msgFile)) {
            $msgs = json_decode(file_get_contents($msgFile), true) ?: [];
            foreach ($msgs as $m) {
                if (($m['time'] ?? 0) > $lastRead && !($m['deleted'] ?? false) && ($m['username'] ?? '') !== $username) {
                    $unread++;
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
        $totalUnread += $unread;
        $userGroups[] = [
            'id' => $gid,
            'name' => $group['name'] ?? '',
            'creator' => $group['creator'] ?? '',
            'member_count' => count($group['members'] ?? []),
            'created_at' => ($group['created_at'] ?? 0),
            'unread_count' => $unread,
            'has_mention' => $hasMention,
            'is_creator' => ($group['creator'] ?? '') === $username,
        ];
    }
    apiSuccess(['groups' => $userGroups, 'total_unread' => $totalUnread]);
}

// ---- 创建群聊 (POST /api/app/groups/create) ----
if ($segments === ['groups', 'create'] && $method === 'POST') {
    $user = requireAuth();
    $body = getJsonBody();
    $name = trim($body['name'] ?? '');
    if (empty($name)) apiError('群聊名称不能为空');
    if (mb_strlen($name) > 50) apiError('名称不能超过50字');
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $u = $stmt->fetch();
    if (!$u) apiError('用户不存在', 404);
    $username = $u['username'];

    $chatGroupsFile = __DIR__ . '/../data/chat/groups.json';
    $groups = file_exists($chatGroupsFile) ? (json_decode(file_get_contents($chatGroupsFile), true) ?: []) : [];
    $groupId = uniqid();
    $groups[$groupId] = [
        'name' => $name,
        'creator' => $username,
        'created_at' => time(),
        'members' => [$username],
    ];
    file_put_contents($chatGroupsFile, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    apiSuccess(['group_id' => $groupId, 'name' => $name], '创建成功');
}

// ---- 加入群聊 (POST /api/app/groups/{id}/join) ----
if (count($segments) >= 3 && $segments[0] === 'groups' && $segments[2] === 'join' && $method === 'POST') {
    $user = requireAuth();
    $groupId = $segments[1];
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $u = $stmt->fetch();
    if (!$u) apiError('用户不存在', 404);
    $username = $u['username'];

    $chatGroupsFile = __DIR__ . '/../data/chat/groups.json';
    $groups = file_exists($chatGroupsFile) ? (json_decode(file_get_contents($chatGroupsFile), true) ?: []) : [];
    if (!isset($groups[$groupId])) apiError('群组不存在', 404);
    if (in_array($username, $groups[$groupId]['members'] ?? [])) apiError('你已在该群组中');
    $groups[$groupId]['members'][] = $username;
    file_put_contents($chatGroupsFile, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    apiSuccess(['group_id' => $groupId], '加入成功');
}

// ---- 标记群聊已读 (POST /api/app/groups/{id}/read) ----
if (count($segments) >= 3 && $segments[0] === 'groups' && $segments[2] === 'read' && $method === 'POST') {
    $user = requireAuth();
    $groupId = $segments[1];
    $chatReadStateFile = __DIR__ . '/../data/chat/read_state.json';
    $readState = file_exists($chatReadStateFile) ? (json_decode(file_get_contents($chatReadStateFile), true) ?: []) : [];
    $key = $user['id'] . '_' . $groupId;
    $readState[$key] = ['time' => time(), 'mentions' => 0];
    file_put_contents($chatReadStateFile, json_encode($readState, JSON_UNESCAPED_UNICODE));
    apiSuccess(null, '已读');
}

// ---- WebView会话桥接 (POST /api/app/auth/web-session) ----
if ($segments === ['auth', 'web-session'] && $method === 'POST') {
    $user = requireAuth();
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, username, avatar, avatar_text, is_admin, is_founder FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $u = $stmt->fetch();
    if (!$u) apiError('用户不存在', 404);

    if (!session_id()) session_start();
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['chat_username'] = $u['username'];
    if ($u['is_admin']) $_SESSION['is_admin'] = true;
    if ($u['is_founder']) $_SESSION['is_founder'] = true;
    session_write_close();

    apiSuccess([
        'session_id' => session_id(),
        'session_name' => session_name(),
        'cookie' => session_name() . '=' . session_id(),
    ]);
}

// ==================== 群聊消息 ====================

// ---- 获取群聊消息 (GET /api/app/groups/{id}/messages?since=timestamp) ----
if (count($segments) === 3 && $segments[0] === 'groups' && $segments[2] === 'messages' && $method === 'GET') {
    $user = requireAuth();
    $groupId = $segments[1];
    $since = intval($_GET['since'] ?? 0);

    $chatDataDir = __DIR__ . '/../data/chat/';
    $groupMessagesFile = $chatDataDir . "group_{$groupId}.json";
    if (!file_exists($groupMessagesFile)) apiSuccess(['messages' => [], 'timestamp' => time()]);

    $messages = json_decode(file_get_contents($groupMessagesFile), true) ?: [];
    if ($since > 0) {
        $filtered = [];
        foreach ($messages as $msg) {
            if (isset($msg['time']) && $msg['time'] > $since) $filtered[] = $msg;
        }
        $messages = $filtered;
    }

    // 构建 id→消息 查找表
    $msgById = [];
    foreach ($messages as $m) {
        if (!empty($m['id'])) $msgById[$m['id']] = $m;
    }
    foreach ($messages as &$msg) {
        if (isset($msg['time'])) {
            $msg['formatted_time'] = date('H:i', $msg['time']);
            if (date('Y-m-d', $msg['time']) !== date('Y-m-d')) {
                $msg['formatted_time'] = date('m-d H:i', $msg['time']);
            }
        }
        if (!empty($msg['reply_to']) && isset($msgById[$msg['reply_to']])) {
            $msg['reply_to_content'] = $msgById[$msg['reply_to']]['content'];
            $msg['reply_to_username'] = $msgById[$msg['reply_to']]['username'];
        }
        if (!isset($msg['deleted'])) $msg['deleted'] = false;
    }
    unset($msg);

    apiSuccess(['messages' => $messages, 'timestamp' => time()]);
}

// ---- 发送消息 (POST /api/app/groups/{id}/messages) ----
if (count($segments) === 3 && $segments[0] === 'groups' && $segments[2] === 'messages' && $method === 'POST') {
    $user = requireAuth();
    $groupId = $segments[1];
    $body = getJsonBody();

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $u = $stmt->fetch();
    if (!$u) apiError('用户不存在', 404);
    $username = $u['username'];

    // 检查是否在群内
    $chatGroupsFile = __DIR__ . '/../data/chat/groups.json';
    $groups = file_exists($chatGroupsFile) ? (json_decode(file_get_contents($chatGroupsFile), true) ?: []) : [];
    if (!isset($groups[$groupId]) || !in_array($username, $groups[$groupId]['members'])) {
        apiError('你不在这个群组中', 403);
    }

    $content = $body['content'] ?? '';
    $type = $body['type'] ?? 'text';
    $fileUrl = $body['file_url'] ?? '';
    $replyTo = $body['reply_to'] ?? null;

    $hasText = !empty(trim($content));
    $hasImage = !empty($fileUrl);
    if (!$hasText && !$hasImage) apiError('消息不能为空');

    // 获取头像
    $stmt = $pdo->prepare("SELECT avatar, avatar_text FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $userData = $stmt->fetch();
    $avatar = $userData ? ($userData['avatar'] ?? '') : '';
    $avatarText = $userData ? ($userData['avatar_text'] ?? '') : '';

    $msgType = $hasImage ? 'image_text' : 'text';
    if ($hasImage && !$hasText) $msgType = 'image';

    $message = [
        'id' => uniqid(),
        'user_id' => $user['id'],
        'username' => $username,
        'avatar' => $avatar,
        'avatar_text' => $avatarText,
        'content' => $type === 'text' ? htmlspecialchars(trim($content), ENT_QUOTES, 'UTF-8') : $content,
        'type' => $msgType,
        'file_url' => $fileUrl,
        'time' => time(),
        'reply_to' => $replyTo,
        'deleted' => false,
    ];

    $chatDataDir = __DIR__ . '/../data/chat/';
    $groupMessagesFile = $chatDataDir . "group_{$groupId}.json";
    $messages = file_exists($groupMessagesFile) ? (json_decode(file_get_contents($groupMessagesFile), true) ?: []) : [];
    $messages[] = $message;
    if (count($messages) > 200) $messages = array_slice($messages, -200);
    file_put_contents($groupMessagesFile, json_encode($messages, JSON_UNESCAPED_UNICODE));

    // 更新在线状态
    $onlineFile = $chatDataDir . 'online.json';
    $onlineUsers = file_exists($onlineFile) ? (json_decode(file_get_contents($onlineFile), true) ?: []) : [];
    $onlineUsers[$username] = time();
    file_put_contents($onlineFile, json_encode($onlineUsers, JSON_UNESCAPED_UNICODE));

    $resp = ['message_id' => $message['id']];
    // 解析引用消息
    if ($replyTo) {
        foreach ($messages as $m) {
            if ($m['id'] === $replyTo) {
                $resp['reply_to_content'] = $m['content'];
                $resp['reply_to_username'] = $m['username'];
                break;
            }
        }
    }
    apiSuccess($resp, '发送成功');
}

// ---- 删除消息 (POST /api/app/groups/{id}/messages/{msgId}/delete) ----
if (count($segments) >= 4 && $segments[0] === 'groups' && $segments[2] === 'messages' && $segments[4] === 'delete' && $method === 'POST') {
    $user = requireAuth();
    $groupId = $segments[1];
    $messageId = $segments[3];

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $u = $stmt->fetch();
    if (!$u) apiError('用户不存在', 404);
    $username = $u['username'];

    // 检查权限
    $chatGroupsFile = __DIR__ . '/../data/chat/groups.json';
    $groups = file_exists($chatGroupsFile) ? (json_decode(file_get_contents($chatGroupsFile), true) ?: []) : [];
    $isAdmin = !empty($u['is_admin']) || !empty($u['is_founder']);

    $chatDataDir = __DIR__ . '/../data/chat/';
    $groupMessagesFile = $chatDataDir . "group_{$groupId}.json";
    if (!file_exists($groupMessagesFile)) apiError('消息不存在', 404);

    $messages = json_decode(file_get_contents($groupMessagesFile), true) ?: [];
    $found = false;
    foreach ($messages as &$msg) {
        if ($msg['id'] === $messageId) {
            if ($msg['username'] !== $username && !$isAdmin) apiError('只能撤回自己的消息');
            $msg['deleted'] = true;
            $found = true;
            break;
        }
    }
    unset($msg);
    if (!$found) apiError('消息不存在', 404);
    file_put_contents($groupMessagesFile, json_encode($messages, JSON_UNESCAPED_UNICODE));
    apiSuccess(null, '已撤回');
}

// ---- 标记群聊已读 (POST /api/app/groups/{id}/read) ----
if (count($segments) >= 3 && $segments[0] === 'groups' && $segments[2] === 'read' && $method === 'POST') {
    $user = requireAuth();
    $groupId = $segments[1];
    $chatReadStateFile = __DIR__ . '/../data/chat/read_state.json';
    $readState = file_exists($chatReadStateFile) ? (json_decode(file_get_contents($chatReadStateFile), true) ?: []) : [];
    $key = $user['id'] . '_' . $groupId;
    $readState[$key] = ['time' => time(), 'mentions' => 0];
    file_put_contents($chatReadStateFile, json_encode($readState, JSON_UNESCAPED_UNICODE));
    apiSuccess(null, '已读');
}

// ---- 退出群聊 (POST /api/app/groups/{id}/leave) ----
if (count($segments) >= 3 && $segments[0] === 'groups' && $segments[2] === 'leave' && $method === 'POST') {
    $user = requireAuth();
    $groupId = $segments[1];

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $u = $stmt->fetch();
    if (!$u) apiError('用户不存在', 404);
    $username = $u['username'];

    $chatGroupsFile = __DIR__ . '/../data/chat/groups.json';
    $groups = file_exists($chatGroupsFile) ? (json_decode(file_get_contents($chatGroupsFile), true) ?: []) : [];
    if (!isset($groups[$groupId])) apiError('群组不存在', 404);
    if ($groups[$groupId]['creator'] === $username) apiError('群主不能退出群聊');

    $groups[$groupId]['members'] = array_values(array_filter($groups[$groupId]['members'], fn($m) => $m !== $username));
    file_put_contents($chatGroupsFile, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    apiSuccess(null, '已退出群聊');
}

// ---- 解散群聊 (POST /api/app/groups/{id}/dismiss) ----
if (count($segments) >= 3 && $segments[0] === 'groups' && $segments[2] === 'dismiss' && $method === 'POST') {
    $user = requireAuth();
    $groupId = $segments[1];

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT username, is_admin, is_founder FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $u = $stmt->fetch();
    if (!$u) apiError('用户不存在', 404);
    $username = $u['username'];
    $isAdminUser = !empty($u['is_admin']) || !empty($u['is_founder']);

    if ($groupId === '1') apiError('官方群不能解散');

    $chatGroupsFile = __DIR__ . '/../data/chat/groups.json';
    $groups = file_exists($chatGroupsFile) ? (json_decode(file_get_contents($chatGroupsFile), true) ?: []) : [];
    if (!isset($groups[$groupId])) apiError('群组不存在', 404);
    if ($groups[$groupId]['creator'] !== $username && !$isAdminUser) apiError('只有群主可以解散');

    unset($groups[$groupId]);
    file_put_contents($chatGroupsFile, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // 删除消息文件
    $msgFile = __DIR__ . "/../data/chat/group_{$groupId}.json";
    if (file_exists($msgFile)) @unlink($msgFile);

    apiSuccess(null, '群聊已解散');
}

// ---- 上传群聊图片 (POST /api/app/groups/messages/upload) ----
if ($segments === ['groups', 'messages', 'upload'] && $method === 'POST') {
    $user = requireAuth();

    if (!isset($_FILES['image'])) apiError('请上传图片');
    $file = $_FILES['image'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) apiError('不支持的图片格式');
    if ($file['size'] > 5 * 1024 * 1024) apiError('图片不能超过5MB');

    $uploadDir = __DIR__ . '/../st/uploads/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newName = uniqid() . '.' . ($ext ?: 'jpg');
    $dest = $uploadDir . $newName;

    if (!move_uploaded_file($file['tmp_name'], $dest)) apiError('上传失败', 500);

    apiSuccess(['url' => '/st/uploads/' . $newName], '上传成功');
}

// ---- 私信: 上传图片 (POST /api/app/messages/upload) ----
if (count($segments) >= 2 && $segments[0] === 'messages' && $segments[1] === 'upload' && $method === 'POST') {
    $user = requireAuth();
    if (!isset($_FILES['image'])) apiError('请上传图片');
    $file = $_FILES['image'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) apiError('不支持的图片格式');
    if ($file['size'] > 5 * 1024 * 1024) apiError('图片不能超过5MB');
    $uploadDir = __DIR__ . '/../st/uploads/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newName = uniqid() . '.' . ($ext ?: 'jpg');
    $dest = $uploadDir . $newName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) apiError('上传失败', 500);
    apiSuccess(['url' => '/st/uploads/' . $newName], '上传成功');
}

// ---- 私信: 未读计数 (GET /api/app/messages/unread) ----
if (count($segments) >= 2 && $segments[0] === 'messages' && $segments[1] === 'unread' && $method === 'GET') {
    $user = requireAuth();
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE to_user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    $count = intval($stmt->fetchColumn());
    apiSuccess(['unread_count' => $count]);
}

// ---- 私信: 撤回消息 (POST /api/app/messages/delete) ----
if (count($segments) >= 2 && $segments[0] === 'messages' && $segments[1] === 'delete' && $method === 'POST') {
    $user = requireAuth();
    $body = getJsonBody();
    $msgId = intval($body['message_id'] ?? 0);
    if ($msgId <= 0) apiError('消息ID无效');
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM private_messages WHERE id = ?");
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch();
    if (!$msg) apiError('消息不存在');
    if ($msg['from_user_id'] != $user['id'] && !$user['is_admin'] && !$user['is_founder']) apiError('只能撤回自己的消息');
    // 24小时限制（与网页版一致）
    if (time() - strtotime($msg['created_at']) > 86400) apiError('只能撤回24小时内的消息');
    $stmt = $pdo->prepare("UPDATE private_messages SET content = '[消息已撤回]', is_read = 1 WHERE id = ?");
    $stmt->execute([$msgId]);
    apiSuccess(null, '消息已撤回');
}

if (count($segments) >= 3 && $segments[0] === 'messages' && $segments[1] === 'conversation' && $segments[2] === 'delete' && $method === 'POST') {
    $user = requireAuth();
    $body = getJsonBody();
    $otherUserId = intval($body['user_id'] ?? 0);
    if ($otherUserId <= 0) apiError('用户ID无效');
    try {
        $pdo = getDbConnection();
        $myId = $user['id'];
        $stmt = $pdo->prepare("DELETE FROM private_messages WHERE (from_user_id = ? AND to_user_id = ?) OR (from_user_id = ? AND to_user_id = ?)");
        $stmt->execute([$myId, $otherUserId, $otherUserId, $myId]);
        apiSuccess(['deleted' => $stmt->rowCount()], '对话已删除');
    } catch (PDOException $e) {
        apiError('删除失败', 500);
    }
}
// ---- 404 ----
apiError('接口不存在', 404);

// ---- 标记单条通知已读 (POST /api/app/notifications/{id}/read) ----
if (count($segments) >= 3 && $segments[0] === 'notifications' && is_numeric($segments[1]) && $segments[2] === 'read' && $method === 'POST') {
    $user = requireAuth();
    $notifId = intval($segments[1]);
    markNotificationAsRead($notifId, $user['id']);
    apiSuccess(null, '已标记为已读');
}

// ---- 删除单条通知 (DELETE /api/app/notifications/{id}) ----
if (count($segments) >= 2 && $segments[0] === 'notifications' && is_numeric($segments[1]) && $method === 'DELETE') {
    $user = requireAuth();
    $notifId = intval($segments[1]);
    deleteNotification($notifId, $user['id']);
    apiSuccess(null, '已删除');
}

// ---- 按类型删除多条通知 (POST /api/app/notifications/delete-by-type) ----
if ($segments === ['notifications', 'delete-by-type'] && $method === 'POST') {
    $user = requireAuth();
    $body = getJsonBody();
    $types = $body['types'] ?? [];
    if (!is_array($types) || empty($types)) apiError('请指定通知类型');
    try {
        $pdo = getDbConnection();
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $params = array_merge($types, [$user['id']]);
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE type IN ($placeholders) AND user_id = ?");
        $stmt->execute($params);
        apiSuccess(['deleted' => $stmt->rowCount()], '已删除');
    } catch (PDOException $e) {
        apiError('删除失败', 500);
    }
}

// ---- 私信: 删除整个对话 (POST /api/app/messages/conversation/delete) ----
