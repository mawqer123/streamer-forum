<?php
// cleanup_files.php - 清理无用文件及无效 Session 文件（仅站长和管理员可访问）
require_once __DIR__ . '/functions.php';

// 检查用户是否登录且是管理员（站长也是管理员，is_admin 为 1）
if (!isLoggedIn() || !isAdmin()) {
    die('权限不足！只有管理员可以访问此页面。');
}

// 定义上传目录常量（与 functions.php 保持一致）
define('UPLOAD_DIR_ROOT', __DIR__ . '/uploads/');
$uploadDirs = [
    'posts'      => UPLOAD_DIR_ROOT . 'posts/',
    'comments'   => UPLOAD_DIR_ROOT . 'comments/',
    'attachments'=> UPLOAD_DIR_ROOT . 'attachments/',
    'avatars'    => UPLOAD_DIR_ROOT . 'avatars/',
    'backgrounds'=> UPLOAD_DIR_ROOT . 'backgrounds/',
    'slides'     => __DIR__ . '/uploads/slides/',
    'chat'       => UPLOAD_DIR_ROOT . 'chat/',
    'images'     => UPLOAD_DIR_ROOT . 'images/',
    '_root'      => UPLOAD_DIR_ROOT,
];

// 确保所有目录存在（避免扫描时出错）
foreach ($uploadDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

/**
 * 将数据库中的文件引用路径规范化为统一的相对路径格式：uploads/xxx/yyy.ext
 */
function normalizeReferencedPath($rawPath) {
    $rawPath = trim($rawPath);
    if (empty($rawPath)) {
        return null;
    }

    $decodedPath = urldecode($rawPath);
    $path = $decodedPath;

    if (preg_match('#^https?://#i', $path)) {
        $parsed = parse_url($path);
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        if (empty($path)) {
            return null;
        }
    }

    if (($pos = strpos($path, '?')) !== false) {
        $path = substr($path, 0, $pos);
    }
    if (($pos = strpos($path, '#')) !== false) {
        $path = substr($path, 0, $pos);
    }

    $siteUrl = defined('SITE_URL') ? SITE_URL : '';
    if (!empty($siteUrl)) {
        $parsedSite = parse_url($siteUrl);
        $sitePath = isset($parsedSite['path']) ? rtrim($parsedSite['path'], '/') : '';
        if (!empty($sitePath) && strpos($path, $sitePath . '/') === 0) {
            $path = substr($path, strlen($sitePath));
        }
    }

    $path = ltrim($path, '/');

    if (preg_match('#(uploads/.*)$#i', $path, $matches)) {
        $path = $matches[1];
    } else {
        if (strpos($path, 'uploads/') !== 0) {
            $subdirs = ['posts/', 'comments/', 'attachments/', 'avatars/', 'backgrounds/', 'slides/', 'chat/'];
            $found = false;
            foreach ($subdirs as $sub) {
                if (strpos($path, $sub) === 0) {
                    $path = 'uploads/' . $path;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $fullTestPath = __DIR__ . '/uploads/' . $path;
                if (file_exists($fullTestPath)) {
                    $path = 'uploads/' . $path;
                } else {
                    return null;
                }
            }
        }
    }

    if (strpos($path, 'uploads/') === 0) {
        return $path;
    }

    return null;
}

/**
 * 从 HTML 内容中提取所有上传文件的引用路径
 */
function extractPathsFromHtml($html) {
    $paths = [];
    
    preg_match_all('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/is', $html, $matches);
    foreach ($matches[1] as $src) {
        $normalized = normalizeReferencedPath($src);
        if ($normalized) $paths[] = $normalized;
    }

    preg_match_all('/<a[^>]+href\s*=\s*["\']([^"\']+)["\'][^>]*>/is', $html, $matches);
    foreach ($matches[1] as $href) {
        $normalized = normalizeReferencedPath($href);
        if ($normalized) $paths[] = $normalized;
    }

    return $paths;
}

/**
 * 获取所有引用的文件路径（从数据库中）
 */
function getReferencedFiles($pdo) {
    $refs = [];

    $stmt = $pdo->query("SELECT avatar FROM users WHERE avatar IS NOT NULL AND avatar != ''");
    while ($row = $stmt->fetch()) {
        $normalized = normalizeReferencedPath($row['avatar']);
        if ($normalized) $refs[] = $normalized;
    }

    $stmt = $pdo->query("SELECT profile_background FROM users WHERE profile_background IS NOT NULL AND profile_background != ''");
    while ($row = $stmt->fetch()) {
        $normalized = normalizeReferencedPath($row['profile_background']);
        if ($normalized) $refs[] = $normalized;
    }

    $stmt = $pdo->query("SELECT content FROM posts");
    while ($row = $stmt->fetch()) {
        $refs = array_merge($refs, extractPathsFromHtml($row['content']));
    }

    $stmt = $pdo->query("SELECT attachment_path FROM posts WHERE attachment_path IS NOT NULL AND attachment_path != ''");
    while ($row = $stmt->fetch()) {
        $normalized = normalizeReferencedPath($row['attachment_path']);
        if ($normalized) $refs[] = $normalized;
    }

    $stmt = $pdo->query("SELECT image_url FROM post_images WHERE image_url IS NOT NULL AND image_url != ''");
    while ($row = $stmt->fetch()) {
        $normalized = normalizeReferencedPath($row['image_url']);
        if ($normalized) $refs[] = $normalized;
    }

    $stmt = $pdo->query("SELECT image_url FROM comment_images WHERE image_url IS NOT NULL AND image_url != ''");
    while ($row = $stmt->fetch()) {
        $normalized = normalizeReferencedPath($row['image_url']);
        if ($normalized) $refs[] = $normalized;
    }

    $stmt = $pdo->query("SELECT content, image_url FROM comments");
    while ($row = $stmt->fetch()) {
        if (!empty($row['image_url'])) {
            $normalized = normalizeReferencedPath($row['image_url']);
            if ($normalized) $refs[] = $normalized;
        }
        $refs = array_merge($refs, extractPathsFromHtml($row['content']));
    }

    $stmt = $pdo->query("SELECT image_url FROM home_slides WHERE image_url IS NOT NULL AND image_url != ''");
    while ($row = $stmt->fetch()) {
        $normalized = normalizeReferencedPath($row['image_url']);
        if ($normalized) $refs[] = $normalized;
    }

    $chatDataDir = __DIR__ . '/data/chat/';
    if (is_dir($chatDataDir)) {
        $files = glob($chatDataDir . 'group_*.json');
        foreach ($files as $file) {
            $json = file_get_contents($file);
            $messages = json_decode($json, true);
            if (is_array($messages)) {
                foreach ($messages as $msg) {
                    if (isset($msg['type']) && $msg['type'] === 'image' && !empty($msg['file_url'])) {
                        $normalized = normalizeReferencedPath($msg['file_url']);
                        if ($normalized) $refs[] = $normalized;
                    }
                }
            }
        }
    }

    return array_unique($refs);
}

/**
 * 递归扫描目录，返回所有文件的相对路径列表
 */
function scanAllFiles($dir, $baseDir) {
    $files = [];
    if (!is_dir($dir)) return $files;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() !== '.htaccess') {
            $fullPath = $file->getPathname();
            $relative = str_replace('\\', '/', $fullPath);
            $baseDirNormalized = str_replace('\\', '/', $baseDir);
            if (strpos($relative, $baseDirNormalized) === 0) {
                $relative = substr($relative, strlen($baseDirNormalized));
            }
            $relative = ltrim($relative, '/');
            if (strpos($relative, 'uploads/') !== 0) {
                $relative = 'uploads/' . ltrim($relative, '/');
            }
            $files[] = $relative;
        }
    }
    return $files;
}

// ---------- 清理无效 Session 文件 ----------
$sessionDir = __DIR__ . '/sessions';
$sessionMessage = '';

function isValidSessionFile($filePath, $currentSessionId) {
    if (basename($filePath) === 'sess_' . $currentSessionId) {
        return true;
    }
    $content = file_get_contents($filePath);
    if ($content === false) {
        return false;
    }
    if (preg_match('/user_id\|i:(\d+)/', $content, $matches)) {
        $userId = (int)$matches[1];
        if ($userId > 0) {
            return true;
        }
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clean_sessions'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('CSRF令牌验证失败！');
    }
    $currentSessionId = session_id();
    if (!is_dir($sessionDir)) {
        $sessionMessage = "Session 目录不存在，无需清理。";
    } else {
        $sessionFiles = glob($sessionDir . '/sess_*');
        $deleted = 0;
        foreach ($sessionFiles as $file) {
            if (!isValidSessionFile($file, $currentSessionId)) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        $sessionMessage = "已删除 {$deleted} 个无效 Session 文件。";
    }
    header('Location: cleanup_files.php?session_cleaned=1&msg=' . urlencode($sessionMessage));
    exit();
}

if (isset($_GET['session_cleaned']) && isset($_GET['msg'])) {
    $sessionMessage = htmlspecialchars($_GET['msg']);
}

// ---------- 文件清理逻辑 ----------
$message = '';
$deletedCount = 0;
$deletedFiles = [];
$totalScanned = 0;
$referenced = [];
$unusedCount = 0;
$unusedList = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('CSRF令牌验证失败！');
    }

    try {
        $pdo = getDbConnection();
        $referenced = getReferencedFiles($pdo);
        
        $baseDir = __DIR__ . '/';
        $allFiles = [];
        foreach ($uploadDirs as $type => $dir) {
            $files = scanAllFiles($dir, $baseDir);
            $allFiles = array_merge($allFiles, $files);
        }
        $allFiles = array_unique($allFiles);
        $totalScanned = count($allFiles);

        $unused = array_diff($allFiles, $referenced);
        
        foreach ($unused as $file) {
            $fullPath = __DIR__ . '/' . $file;
            if (file_exists($fullPath) && is_file($fullPath)) {
                if (unlink($fullPath)) {
                    $deletedCount++;
                    $deletedFiles[] = $file;
                } else {
                    $message .= "无法删除: $file<br>";
                }
            }
        }
        
        if ($deletedCount > 0) {
            $message .= "<div style='color:green'>成功删除 {$deletedCount} 个无用文件。</div>";
        } else {
            $message .= "<div>没有找到可删除的无用文件。</div>";
        }
    } catch (Exception $e) {
        $message = "<div style='color:red'>错误：" . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    try {
        $pdo = getDbConnection();
        $referenced = getReferencedFiles($pdo);
        
        $baseDir = __DIR__ . '/';
        $allFiles = [];
        foreach ($uploadDirs as $type => $dir) {
            $files = scanAllFiles($dir, $baseDir);
            $allFiles = array_merge($allFiles, $files);
        }
        $allFiles = array_unique($allFiles);
        $totalScanned = count($allFiles);
        
        $unused = array_diff($allFiles, $referenced);
        $unusedCount = count($unused);
        $unusedList = $unused;
    } catch (Exception $e) {
        $errorMsg = htmlspecialchars($e->getMessage());
    }
}

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>清理无用文件 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <?php
    if ($currentUser && isset($currentUser['theme']) && $currentUser['theme'] === 'custom' && !empty($currentUser['theme_settings'])) {
        $settings = $currentUser['theme_settings'];
        $primary = $settings['primary'] ?? '#2196F3';
        list($r, $g, $b) = sscanf($primary, "#%02x%02x%02x");
        $r = max(0, $r - 20);
        $g = max(0, $g - 20);
        $b = max(0, $b - 20);
        $to = sprintf("#%02x%02x%02x", $r, $g, $b);
        echo "<style data-page-style>:root{--accent-color:$primary;--accent-gradient-from:$primary;--accent-gradient-to:$to;}</style>";
    ?>
    <?php } ?>
    <style data-page-style>
        /* cleanup_files.php 特有样式 */
        body { background: var(--bg-secondary); margin: 0; padding: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        .cleanup-header {
            background-color: var(--accent-color);
            color: white;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .cleanup-header a {
            color: white;
            text-decoration: none;
            font-size: 1.8rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }
        .cleanup-header a:hover { background: rgba(255,255,255,0.2); }
        .cleanup-header h1 { font-size: 1.2rem; margin: 0; flex: 1; text-align: center; }
        .container { margin: 0 auto; padding: 1.5rem; }
        .card {
            background: var(--bg-primary);
            border-radius: 0;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
        }
        .card h2 {
            margin-top: 0;
            color: var(--text-primary);
            font-size: 1.2rem;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        .stats {
            background: var(--bg-secondary);
            border-radius: 0;
            padding: 1rem;
            margin: 1rem 0;
        }
        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--border-color);
        }
        .stat-row:last-child { border-bottom: none; }
        .stat-label { font-weight: 500; color: var(--text-secondary); }
        .stat-value { font-weight: 600; color: var(--accent-color); }
        .file-list {
            max-height: 400px;
            overflow-y: auto;
            background: var(--bg-secondary);
            border-radius: 0;
            padding: 0.5rem;
            font-family: monospace;
            font-size: 0.85rem;
            margin-top: 1rem;
        }
        .file-item {
            padding: 0.3rem 0.5rem;
            border-bottom: 1px solid var(--border-color);
            word-break: break-all;
        }
        .file-item:last-child { border-bottom: none; }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 1rem;
            border-radius: 0;
            margin: 1rem 0;
            border-left: 4px solid #ffc107;
        }
        .danger {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 0;
            margin: 1rem 0;
            border-left: 4px solid #dc3545;
        }
        .flex-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }
        hr { margin: 1.5rem 0; border-color: var(--border-color); }
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .card { padding: 1rem; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <div id="page-content">
    <div class="cleanup-header">
        <a href="#" data-nav-url="<?php echo url('profile'); ?>" data-tab="profile">←</a>
        <h1>清理无用文件</h1>
        <span style="width:40px;"></span>
    </div>
    <div class="container">
        <?php if ($sessionMessage): ?>
            <div class="alert alert-success"><?php echo $sessionMessage; ?></div>
        <?php endif; ?>

        <?php if (isset($errorMsg)): ?>
            <div class="alert alert-error"><?php echo $errorMsg; ?></div>
        <?php endif; ?>

        <?php if (isset($message) && !empty($message)): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- 清理无效 Session 文件卡片 -->
        <div class="card">
            <h2> 清理无效 Session 文件</h2>
            <p>扫描 sessions 目录，删除未登录用户产生的无效 Session 文件（不包含当前登录用户的 Session）。</p>
            <form method="POST" onsubmit="return confirm(' 确定要删除所有无效的 Session 文件吗？此操作不会影响已登录用户。');">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="clean_sessions" value="1">
                <div class="flex-buttons">
                    <button type="submit" class="btn-primary">立即清理无效 Session</button>
                </div>
            </form>
        </div>

        <hr>

        <!-- 清理上传文件卡片 -->
        <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' || isset($unusedCount)): ?>
            <div class="card">
                <h2> 扫描上传文件统计</h2>
                <div class="stats">
                    <div class="stat-row">
                        <span class="stat-label">已扫描文件总数：</span>
                        <span class="stat-value"><?php echo isset($totalScanned) ? $totalScanned : 0; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">数据库引用文件数：</span>
                        <span class="stat-value"><?php echo isset($referenced) ? count($referenced) : 0; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">疑似无用文件数：</span>
                        <span class="stat-value" style="color: #dc3545;"><?php echo isset($unusedCount) ? $unusedCount : 0; ?></span>
                    </div>
                </div>

                <?php if (isset($unusedCount) && $unusedCount > 0): ?>
                    <div class="warning">
                         以下文件未被任何帖子、评论、用户资料、幻灯片或聊天记录引用。请仔细确认后删除。
                    </div>
                    <div class="file-list">
                        <?php foreach ($unusedList as $file): ?>
                            <div class="file-item"><?php echo htmlspecialchars($file); ?></div>
                        <?php endforeach; ?>
                    </div>

                    <form method="POST" onsubmit="return confirm(' 警告：此操作将永久删除以上所有无用文件，不可恢复！确定要继续吗？');">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="confirm" value="1">
                        <div class="flex-buttons">
                            <a href="#" data-nav-url="<?php echo url('profile'); ?>" data-tab="profile" class="btn-secondary">取消</a>
                            <button type="submit" class="btn-danger">确认删除 (<?php echo $unusedCount; ?> 个文件)</button>
                        </div>
                    </form>
                <?php elseif (isset($unusedCount) && $unusedCount == 0): ?>
                    <div class="alert alert-success"> 所有文件均被引用，没有无用文件需要清理。</div>
                    <div class="flex-buttons">
                        <a href="#" data-nav-url="<?php echo url('profile'); ?>" data-tab="profile" class="btn-secondary">返回个人中心</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    </div><!-- /page-content -->
    <?php include 'auth_modal.php'; ?>
    <?php include 'spa.php'; ?>
</body>
</html>