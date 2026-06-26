<?php
$active_bottom = 'chatroom';
$active_page = 'chatroom';
require_once __DIR__ . '/functions.php';

// 允许 APP Token 认证（兼容 API 调用）
if (!isLoggedIn()) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = null;
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) $token = trim($m[1]);
    elseif (preg_match('/^\*\*\*(.+)$/', $authHeader, $m)) $token = trim($m[1]);
    if ($token) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT u.* FROM user_tokens t JOIN users u ON t.user_id = u.id WHERE t.token_hash = SHA2(?, 256) AND t.expires_at > NOW()");
            $stmt->execute([$token]);
            $row = $stmt->fetch();
            if ($row) {
                if (session_status() === PHP_SESSION_NONE) session_start();
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['is_admin'] = $row['is_admin'];
                $_SESSION['is_founder'] = $row['is_founder'];
                $_SESSION['logged_in'] = true;
            }
        } catch (Exception $e) {}
    }
}

// 获取当前用户信息（用于主题）
$currentUserForTheme = getCurrentUser();
checkMaintenanceMode($currentUserForTheme);

// 检查用户是否登录
if (!isLoggedIn()) {
    // 未登录时显示登录提示页面
    ?>
    <!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>聊天室 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <?php
    if ($currentUserForTheme && isset($currentUserForTheme['theme']) && $currentUserForTheme['theme'] === 'custom' && !empty($currentUserForTheme['theme_settings'])) {
        $settings = $currentUserForTheme['theme_settings'];
        $primary = $settings['primary'] ?? '#2196F3';
        list($r, $g, $b) = sscanf($primary, "#%02x%02x%02x");
        $r = max(0, $r - 20);
        $g = max(0, $g - 20);
        $b = max(0, $b - 20);
        $to = sprintf("#%02x%02x%02x", $r, $g, $b);
        echo "<style data-page-style>:root{--accent-color:$primary;--accent-gradient-from:$primary;--accent-gradient-to:$to;}</style>";
    ?>
    <script>
        document.documentElement.style.setProperty('--accent-color', '<?php echo $primary; ?>');
        document.documentElement.style.setProperty('--accent-gradient-from', '<?php echo $primary; ?>');
        document.documentElement.style.setProperty('--accent-gradient-to', '<?php echo $to; ?>');
    </script>
    <?php } ?>
    <style>
        .login-prompt {
            text-align: center; margin-top: 3rem; padding: 2rem; background: var(--bg-primary);
            border-radius: 0; margin-left: auto; margin-right: auto;
            box-shadow: var(--card-shadow);
        }
        .login-prompt h2 { color: var(--accent-color); margin-bottom: 1rem; }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <main class="main-content">
        <div class="login-prompt">
            <h2> 请先登录</h2>
            <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">登录后即可进入聊天室，与其他玩家实时交流</p>
            <button class="btn-primary" onclick="showAuthModal(true)" style="margin-bottom: 1rem;">立即登录</button>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 1.5rem;">
                还没有账号？<a href="javascript:void(0);" onclick="showAuthModal(false)" style="color: var(--accent-color); text-decoration: none; margin-left: 0.5rem;">立即注册</a>
            </p>
        </div>
    </main>
    <?php include 'bottom_nav.php'; ?>
    <?php include 'auth_modal.php'; ?>
</body>
</html>
    <?php
    exit();
}

// 用户已登录，显示聊天室
define('CHAT_DATA_DIR', __DIR__ . '/data/chat/');
define('CHAT_USERS_FILE', CHAT_DATA_DIR . 'users.json');
define('CHAT_GROUPS_FILE', CHAT_DATA_DIR . 'groups.json');
define('CHAT_ONLINE_USERS_FILE', CHAT_DATA_DIR . 'online.json');
define('CHAT_AVATARS_FILE', CHAT_DATA_DIR . 'avatars.json');
define('CHAT_THEMES_FILE', CHAT_DATA_DIR . 'themes.json');
define('CHAT_UPLOAD_DIR', __DIR__ . '/uploads/chat/');

// 安全防护：确保 data 目录不可直接访问
if (!file_exists(CHAT_DATA_DIR)) {
    mkdir(CHAT_DATA_DIR, 0755, true);
}
$htaccessFile = dirname(CHAT_DATA_DIR) . '/.htaccess';
if (!file_exists($htaccessFile)) {
    file_put_contents($htaccessFile, "Deny from all\n");
}
$chatHtaccess = CHAT_DATA_DIR . '.htaccess';
if (!file_exists($chatHtaccess)) {
    file_put_contents($chatHtaccess, "Deny from all\n");
}

if (!file_exists(CHAT_UPLOAD_DIR)) {
    mkdir(CHAT_UPLOAD_DIR, 0755, true);
}
$uploadHtaccess = CHAT_UPLOAD_DIR . '.htaccess';
if (!file_exists($uploadHtaccess)) {
    file_put_contents($uploadHtaccess, "Options -Indexes\nAllow from all\n");
}

// ========== AJAX 图片上传，使用统一的 uploadFile 函数 ==========
if (isset($_FILES['image']) && isset($_POST['ajax']) && $_POST['ajax'] === 'upload_image') {
    error_reporting(0);
    header('Content-Type: application/json');
    
    try {
        if (!isLoggedIn()) {
            throw new Exception('请先登录');
        }
        
        $username = syncForumUserToChat();
        if (!$username) {
            throw new Exception('用户同步失败');
        }
        
        $file = $_FILES['image'];
        $groupId = $_POST['group_id'] ?? '1';
        $replyTo = $_POST['reply_to'] ?? null;
        
        $uploadResult = uploadFile($file, 'chat');
        if (!$uploadResult['success']) {
            throw new Exception($uploadResult['message']);
        }
        
        $fileUrl = $uploadResult['file_url'];
        
        $avatar = '';
        $userId = $_SESSION['user_id'];
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT avatar, avatar_text FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            $avatar = $user ? $user['avatar'] : '';
            $avatarText = $user ? $user['avatar_text'] : '';
        } catch (Exception $e) {}
        
        $result = addGroupChatMessage($groupId, $username, '图片消息', 'image', $fileUrl, $replyTo, $avatar, $userId);
        if (!$result) {
            @unlink($uploadResult['file_path']);
            throw new Exception('保存消息失败');
        }
        
        echo json_encode(['success' => true, 'url' => $fileUrl, 'message' => '上传成功']);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// 初始化聊天室文件（已移除敏感文件初始化）
function initChatFiles() {
    $files = [
        CHAT_ONLINE_USERS_FILE => [],
        CHAT_AVATARS_FILE => [],
        CHAT_THEMES_FILE => []
    ];
    foreach ($files as $file => $default) {
        if (!file_exists($file)) {
            file_put_contents($file, json_encode($default));
        }
    }
    if (!file_exists(CHAT_GROUPS_FILE)) {
        $defaultGroups = [
            '1' => [
                'name' => '官方聊天群',
                'creator' => 'system',
                'created_at' => time(),
                'members' => []
            ]
        ];
        file_put_contents(CHAT_GROUPS_FILE, json_encode($defaultGroups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
initChatFiles();

// 注意：以下函数 getChatUsers, saveChatUsers 等仍然存在，但 users.json 不再存储密码
function getChatUsers() {
    return [];
}
function saveChatUsers($users) {
    return false;
}
function validateChatUser($username, $password) {
    return false;
}
function addChatUser($username, $password, $email = '') {
    return false;
}

function syncForumUserToChat() {
    $forumUsername = $_SESSION['username'];
    $forumUid      = $_SESSION['user_id'];
    $groups = getChatGroups();
    if (isset($groups['1']) && !in_array($forumUsername, $groups['1']['members'])) {
        $groups['1']['members'][] = $forumUsername;
        saveChatGroups($groups);
    }
    $_SESSION['chat_username'] = $forumUsername;
    return $forumUsername;
}

function getChatGroups() {
    if (!file_exists(CHAT_GROUPS_FILE)) return [];
    $content = file_get_contents(CHAT_GROUPS_FILE);
    if (empty($content)) return [];
    $groups = json_decode($content, true);
    return is_array($groups) ? $groups : [];
}
function saveChatGroups($groups) {
    return file_put_contents(CHAT_GROUPS_FILE, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function getChatGroup($groupId) {
    $groups = getChatGroups();
    return $groups[$groupId] ?? null;
}
function createChatGroup($groupName, $creator) {
    $groups = getChatGroups();
    $groupId = uniqid();
    while (isset($groups[$groupId])) $groupId = uniqid();
    $groups[$groupId] = [
        'name' => $groupName,
        'creator' => $creator,
        'created_at' => time(),
        'members' => [$creator]
    ];
    $groupMessagesFile = CHAT_DATA_DIR . "group_{$groupId}.json";
    if (!file_exists($groupMessagesFile)) file_put_contents($groupMessagesFile, json_encode([]));
    return saveChatGroups($groups) ? $groupId : false;
}
function joinChatGroup($groupId, $username) {
    $groups = getChatGroups();
    if (!isset($groups[$groupId])) return false;
    if (!in_array($username, $groups[$groupId]['members'])) {
        $groups[$groupId]['members'][] = $username;
    }
    return saveChatGroups($groups);
}
function getUserChatGroups($username) {
    $groups = getChatGroups();
    $userGroups = [];
    foreach ($groups as $groupId => $group) {
        if (in_array($username, $group['members'])) {
            $userGroups[$groupId] = $group;
        }
    }
    return $userGroups;
}

function getGroupChatMessages($groupId, $limit = 100, $since = 0) {
    $groupMessagesFile = CHAT_DATA_DIR . "group_{$groupId}.json";
    if (!file_exists($groupMessagesFile)) return [];
    $content = file_get_contents($groupMessagesFile);
    if (empty($content)) return [];
    $messages = json_decode($content, true);
    if (!is_array($messages)) $messages = [];
    if ($since > 0) {
        $filtered = [];
        foreach ($messages as $msg) {
            if (isset($msg['time']) && $msg['time'] > $since) $filtered[] = $msg;
        }
        $messages = $filtered;
    }
    if (count($messages) > 200) {
        $messages = array_slice($messages, -200);
        file_put_contents($groupMessagesFile, json_encode($messages, JSON_UNESCAPED_UNICODE));
    }
    static $userCache = [];
    foreach ($messages as &$msg) {
        if (!isset($msg['user_id']) || empty($msg['user_id'])) {
            $username = $msg['username'] ?? '';
            if ($username && isset($userCache[$username])) {
                $msg['user_id'] = $userCache[$username]['id'];
                $msg['avatar_text'] = $userCache[$username]['avatar_text'] ?? '';
            } elseif ($username) {
                try {
                    $pdo = getDbConnection();
                    $stmt = $pdo->prepare("SELECT id, avatar_text FROM users WHERE username = ? LIMIT 1");
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();
                    $msg['user_id'] = $user ? $user['id'] : 0;
                    $msg['avatar_text'] = $user ? $user['avatar_text'] : '';
                    $userCache[$username] = ['id' => $msg['user_id'], 'avatar_text' => $msg['avatar_text']];
                } catch (Exception $e) {
                    $msg['user_id'] = 0;
                    $msg['avatar_text'] = '';
                }
            } else {
                $msg['user_id'] = 0;
                $msg['avatar_text'] = '';
            }
        } else {
            if (!isset($msg['avatar_text']) && isset($msg['username'])) {
                try {
                    $pdo = getDbConnection();
                    $stmt = $pdo->prepare("SELECT avatar_text FROM users WHERE username = ? LIMIT 1");
                    $stmt->execute([$msg['username']]);
                    $user = $stmt->fetch();
                    $msg['avatar_text'] = $user ? $user['avatar_text'] : '';
                } catch (Exception $e) {
                    $msg['avatar_text'] = '';
                }
            }
        }
    }
    return $messages;
}

function addGroupChatMessage($groupId, $username, $content, $type = 'text', $fileUrl = '', $replyTo = null, $avatar = '', $userId = 0) {
    $messages = getGroupChatMessages($groupId, 200);
    $avatarText = '';
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT avatar_text FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $avatarText = $user ? $user['avatar_text'] : '';
    } catch (Exception $e) {}
    
    $message = [
        'id' => uniqid(),
        'user_id' => $userId,
        'username' => $username,
        'avatar' => $avatar,
        'avatar_text' => $avatarText,
        'content' => $type === 'text' ? htmlspecialchars(trim($content), ENT_QUOTES, 'UTF-8') : $content,
        'type' => $type,
        'file_url' => $fileUrl,
        'time' => time(),
        'reply_to' => $replyTo,
        'deleted' => false
    ];
    $messages[] = $message;
    if (count($messages) > 300) $messages = array_slice($messages, -300);
    $groupMessagesFile = CHAT_DATA_DIR . "group_{$groupId}.json";
    return file_put_contents($groupMessagesFile, json_encode($messages, JSON_UNESCAPED_UNICODE));
}

/**
 * 设置消息的删除状态
 * @param string $groupId
 * @param string $messageId
 * @param bool $deleted
 * @return bool
 */
function setMessageDeleted($groupId, $messageId, $deleted = true) {
    $groupMessagesFile = CHAT_DATA_DIR . "group_{$groupId}.json";
    if (!file_exists($groupMessagesFile)) return false;
    $messages = json_decode(file_get_contents($groupMessagesFile), true);
    if (!is_array($messages)) return false;
    $found = false;
    foreach ($messages as &$msg) {
        if ($msg['id'] === $messageId) {
            $msg['deleted'] = $deleted;
            $found = true;
            break;
        }
    }
    if (!$found) return false;
    return file_put_contents($groupMessagesFile, json_encode($messages, JSON_UNESCAPED_UNICODE));
}

function updateChatOnlineUser($username) {
    if (!file_exists(CHAT_ONLINE_USERS_FILE)) file_put_contents(CHAT_ONLINE_USERS_FILE, json_encode([]));
    $onlineUsers = json_decode(file_get_contents(CHAT_ONLINE_USERS_FILE), true);
    if (!is_array($onlineUsers)) $onlineUsers = [];
    $onlineUsers[$username] = time();
    foreach ($onlineUsers as $user => $lastSeen) {
        if (time() - $lastSeen > 120) unset($onlineUsers[$user]);
    }
    return file_put_contents(CHAT_ONLINE_USERS_FILE, json_encode($onlineUsers));
}
function getChatOnlineUsers() {
    if (!file_exists(CHAT_ONLINE_USERS_FILE)) return [];
    $content = file_get_contents(CHAT_ONLINE_USERS_FILE);
    if (empty($content)) return [];
    $onlineUsers = json_decode($content, true);
    if (!is_array($onlineUsers)) return [];
    $result = [];
    foreach ($onlineUsers as $user => $lastSeen) {
        if (time() - $lastSeen <= 120) $result[] = $user;
    }
    return $result;
}
function removeChatOnlineUser($username) {
    if (!file_exists(CHAT_ONLINE_USERS_FILE)) return;
    $onlineUsers = json_decode(file_get_contents(CHAT_ONLINE_USERS_FILE), true);
    if (!is_array($onlineUsers)) return;
    if (isset($onlineUsers[$username])) {
        unset($onlineUsers[$username]);
        file_put_contents(CHAT_ONLINE_USERS_FILE, json_encode($onlineUsers));
    }
}

function isChatLoggedIn() {
    return isset($_SESSION['chat_username']) && !empty($_SESSION['chat_username']);
}
function getCurrentChatUsername() {
    return $_SESSION['chat_username'] ?? '';
}
function jsonChatResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$forumUsername = syncForumUserToChat();


if (isset($_POST['action'])) {
    syncForumUserToChat();
    $action = $_POST['action'];
    $response = ['status' => 'error', 'message' => '未知操作'];
    try {
        switch ($action) {
            case 'send_message':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupId = $_POST['group_id'] ?? '1';
                $username = getCurrentChatUsername();
                $message = $_POST['message'] ?? '';
                $type = $_POST['type'] ?? 'text';
                $replyTo = $_POST['reply_to'] ?? null;
                $group = getChatGroup($groupId);
                if (!$group || !in_array($username, $group['members'])) {
                    $response['message'] = '你不在这个群组中'; break;
                }
                if (empty(trim($message)) && $type === 'text') {
                    $response['message'] = '消息不能为空';
                } else {
                    updateChatOnlineUser($username);
                    $avatar = '';
                    $userId = $_SESSION['user_id'];
                    try {
                        $pdo = getDbConnection();
                        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE username = ?");
                        $stmt->execute([$username]);
                        $user = $stmt->fetch();
                        $avatar = $user ? $user['avatar'] : '';
                    } catch (Exception $e) {}
                    if ($type === 'text') {
                        if (addGroupChatMessage($groupId, $username, $message, 'text', '', $replyTo, $avatar, $userId)) {
                            $response = ['status' => 'success', 'message' => '消息发送成功', 'message_id' => uniqid()];
                        } else {
                            $response['message'] = '发送失败';
                        }
                    }
                }
                break;
            case 'get_messages':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupId = $_POST['group_id'] ?? '1';
                $username = getCurrentChatUsername();
                $group = getChatGroup($groupId);
                if (!$group || !in_array($username, $group['members'])) {
                    $response['message'] = '你不在这个群组中'; break;
                }
                // 始终获取最近200条消息（全量，忽略since参数实现实时同步）
                $messages = getGroupChatMessages($groupId, 200);
                foreach ($messages as &$msg) {
                    $msg['formatted_time'] = date('H:i', $msg['time']);
                    if (date('Y-m-d', $msg['time']) !== date('Y-m-d')) {
                        $msg['formatted_time'] = date('m-d H:i', $msg['time']);
                    }
                    if (!empty($msg['reply_to'])) {
                        foreach ($messages as $searchMsg) {
                            if ($searchMsg['id'] === $msg['reply_to']) {
                                $msg['reply_to_content'] = $searchMsg['content'];
                                $msg['reply_to_username'] = $searchMsg['username'];
                                break;
                            }
                        }
                    }
                }
                updateChatOnlineUser($username);
                $response = [
                    'status' => 'success',
                    'messages' => $messages,
                    'current_user' => $username,
                    'timestamp' => time()
                ];
                break;
            // 撤回消息
            case 'delete_message':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupId = $_POST['group_id'] ?? '1';
                $messageId = $_POST['message_id'] ?? '';
                if (empty($messageId)) {
                    $response['message'] = '消息ID无效';
                    break;
                }
                $group = getChatGroup($groupId);
                if (!$group || !in_array(getCurrentChatUsername(), $group['members'])) {
                    $response['message'] = '你不在这个群组中';
                    break;
                }
                // 获取消息内容，检查权限
                $messages = getGroupChatMessages($groupId, 200);
                $targetMsg = null;
                foreach ($messages as $msg) {
                    if ($msg['id'] === $messageId) {
                        $targetMsg = $msg;
                        break;
                    }
                }
                if (!$targetMsg) {
                    $response['message'] = '消息不存在';
                    break;
                }
                // 已撤回的消息不能再次撤回
                if ($targetMsg['deleted'] === true) {
                    $response['message'] = '消息已被撤回';
                    break;
                }
                $currentUserId = $_SESSION['user_id'];
                $isAdmin = isAdmin();
                // 仅消息作者或管理员可撤回
                if ($targetMsg['user_id'] != $currentUserId && !$isAdmin) {
                    $response['message'] = '只能撤回自己的消息';
                    break;
                }
                if (setMessageDeleted($groupId, $messageId, true)) {
                    $response = ['status' => 'success', 'message' => '消息已撤回'];
                } else {
                    $response['message'] = '撤回失败';
                }
                break;
            case 'create_group':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupName = trim($_POST['group_name'] ?? '');
                $username = getCurrentChatUsername();
                if (empty($groupName)) {
                    $response['message'] = '群组名称不能为空';
                } elseif (strlen($groupName) < 2) {
                    $response['message'] = '群组名称至少需要2个字符';
                } elseif ($groupId = createChatGroup($groupName, $username)) {
                    $response = ['status' => 'success', 'message' => '群组创建成功', 'group_id' => $groupId];
                } else {
                    $response['message'] = '创建失败';
                }
                break;
            case 'join_group':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupId = trim($_POST['group_id'] ?? '');
                $username = getCurrentChatUsername();
                if (empty($groupId)) {
                    $response['message'] = '群组ID不能为空';
                } elseif (!getChatGroup($groupId)) {
                    $response['message'] = '群组不存在';
                } elseif (joinChatGroup($groupId, $username)) {
                    $response = ['status' => 'success', 'message' => '加入群组成功'];
                } else {
                    $response['message'] = '加入失败';
                }
                break;
            case 'get_user_groups':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $username = getCurrentChatUsername();
                $userGroups = getUserChatGroups($username);
                $response = ['status' => 'success', 'groups' => $userGroups];
                break;
            case 'check_online':
                if (isChatLoggedIn()) updateChatOnlineUser(getCurrentChatUsername());
                $onlineUsers = getChatOnlineUsers();
                $response = ['status' => 'success', 'online_users' => $onlineUsers, 'count' => count($onlineUsers)];
                break;
            case 'logout':
                if (isChatLoggedIn()) {
                    removeChatOnlineUser(getCurrentChatUsername());
                    unset($_SESSION['chat_username']);
                    $response = ['status' => 'success', 'message' => '已退出聊天室'];
                }
                break;


            default:
                $response['message'] = '未知操作';
        }
    } catch (Exception $e) {
        $response['message'] = '服务器错误: ' . $e->getMessage();
    }
    jsonChatResponse($response);
}

if (isset($_GET['group'])) {
    $groupId = $_GET['group'];
    $group = getChatGroup($groupId);
    $username = getCurrentChatUsername();
    if ($group && in_array($username, $group['members'])) {
        showChatPage($groupId);
    } else {
        showChatBackendOnly();
    }
} else {
    showChatBackendOnly();
}

function showChatBackendOnly() {
    echo 'OK';
}

function showGroupsPage() {
    global $currentUserForTheme;
    $active_bottom = 'chatroom';
    $username = getCurrentChatUsername();
    $userGroups = getUserChatGroups($username);
    ?>
    <!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>聊天室群组 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <?php
    if ($currentUserForTheme && isset($currentUserForTheme['theme']) && $currentUserForTheme['theme'] === 'custom' && !empty($currentUserForTheme['theme_settings'])) {
        $settings = $currentUserForTheme['theme_settings'];
        $primary = $settings['primary'] ?? '#2196F3';
        list($r, $g, $b) = sscanf($primary, "#%02x%02x%02x");
        $r = max(0, $r - 20);
        $g = max(0, $g - 20);
        $b = max(0, $b - 20);
        $to = sprintf("#%02x%02x%02x", $r, $g, $b);
        echo "<style data-page-style>:root{--accent-color:$primary;--accent-gradient-from:$primary;--accent-gradient-to:$to;}</style>";
    ?>
    <script>
        document.documentElement.style.setProperty('--accent-color', '<?php echo $primary; ?>');
        document.documentElement.style.setProperty('--accent-gradient-from', '<?php echo $primary; ?>');
        document.documentElement.style.setProperty('--accent-gradient-to', '<?php echo $to; ?>');
    </script>
    <?php } ?>
    <style>
        body { background: var(--bg-secondary); margin: 0; padding: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif; }
        .chat-container { max-width: 100%; margin: 0; background: var(--bg-primary); border-radius: 0; overflow: hidden; box-shadow: var(--card-shadow); }
        .chat-header {
            background: var(--accent-gradient-from); color: white; padding: 1rem 1.5rem;
            display: flex; justify-content: space-between; align-items: center; position: relative;
        }
        .chat-header h2 { margin: 0; font-size: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .menu-btn {
            background: none; border: none; color: white; font-size: 1.8rem; cursor: pointer;
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; transition: background 0.3s;
        }
        .menu-btn:hover { background: rgba(255,255,255,0.2); }
        .dropdown-menu {
            position: absolute; top: 70px; right: 20px; background: var(--bg-primary);
            border-radius: 0; box-shadow: 0 4px 15px rgba(0,0,0,0.2); min-width: 160px;
            z-index: 1000; display: none; overflow: hidden; border: 1px solid var(--border-color);
        }
        .dropdown-menu.active { display: block; }
        .dropdown-item {
            padding: 0.75rem 1.2rem; color: var(--text-primary); cursor: pointer;
            transition: background 0.2s; border-bottom: 1px solid var(--border-color); font-size: 0.9rem;
        }
        .dropdown-item:last-child { border-bottom: none; }
        .dropdown-item:hover { background: var(--link-hover-bg); }
        .tabs { display: flex; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); }
        .tab {
            flex: 1; text-align: center; padding: 0.8rem; cursor: pointer;
            color: var(--text-secondary); font-weight: 500; transition: all 0.2s;
        }
        .tab.active { color: var(--accent-color); border-bottom: 2px solid var(--accent-color); }
        .tab-content { padding: 1.5rem; }
        .groups-list { margin-top: 0; }
        .group-item {
            background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 0;
            padding: 1rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 1rem;
            cursor: pointer; transition: all 0.3s;
        }
        .group-item:hover { transform: translateY(-2px); box-shadow: var(--card-shadow); border-color: var(--accent-color); }
        .group-icon {
            width: 48px; height: 48px; border-radius: 0; background: var(--accent-gradient-from);
            color: white; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;
        }
        .group-info { flex: 1; }
        .group-name { font-weight: 600; font-size: 1.1rem; margin-bottom: 0.25rem; color: var(--text-primary); }
        .group-meta { font-size: 0.9rem; color: var(--text-secondary); display: flex; gap: 1rem; }
        .group-creator {
            font-size: 0.85rem; color: var(--accent-color); background: rgba(94,114,228,0.1);
            padding: 0.25rem 0.5rem; border-radius: 0;
        }
        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: var(--bg-primary); border-radius: 0; padding: 2rem; max-width: 400px;
            width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .modal-header h3 { margin: 0; color: var(--text-primary); }
        .close-btn { background: none; border: none; font-size: 1.8rem; color: var(--text-secondary); cursor: pointer; }
        .modal-input {
            width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: 0;
            font-size: 1rem; margin-bottom: 1.5rem; background: var(--bg-primary); color: var(--text-primary);
        }
        .modal-actions { display: flex; gap: 1rem; justify-content: flex-end; }
        .modal-btn {
            padding: 0.6rem 1.5rem; border: none; border-radius: 0; font-size: 0.95rem; cursor: pointer;
        }
        .modal-btn.primary { background: var(--accent-gradient-from); color: white; }
        .modal-btn.secondary { background: var(--bg-secondary); color: var(--text-primary); border: 1px solid var(--border-color); }
        .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-secondary); }
        .friend-list { max-height: 400px; overflow-y: auto; }
        .friend-item {
            display: flex; align-items: center; justify-content: space-between; padding: 0.8rem;
            border-bottom: 1px solid var(--border-color); cursor: pointer;
        }
        .friend-item:hover { background: var(--link-hover-bg); }
        .friend-avatar {
            width: 40px; height: 40px; border-radius: 50%; background: var(--accent-gradient-from);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 1rem; overflow: hidden; margin-right: 1rem;
            text-transform: uppercase;
        }
        .friend-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .friend-name { font-weight: 500; color: var(--text-primary); flex: 1; }
        .btn-sm { padding: 0.3rem 0.8rem; font-size: 0.8rem; }
        .search-result-item {
            display: flex; align-items: center; justify-content: space-between; padding: 0.6rem;
            border-bottom: 1px solid var(--border-color);
        }
        .search-result-name { flex: 1; color: var(--text-primary); }
        .icon-svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        @media (max-width: 768px) {
            .chat-header { padding: 0.8rem 1rem; }
            .chat-header h2 { font-size: 1.2rem; }
            .tab-content { padding: 1rem; }
            .group-item { padding: 0.75rem; }
            .group-icon { width: 40px; height: 40px; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <h2>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                聊天室
            </h2>
            <button class="menu-btn" id="menuToggle">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="1" fill="currentColor"></circle>
                    <circle cx="12" cy="5" r="1" fill="currentColor"></circle>
                    <circle cx="12" cy="19" r="1" fill="currentColor"></circle>
                </svg>
            </button>
            <div class="dropdown-menu" id="dropdownMenu">
                <div class="dropdown-item" onclick="openCreateModal()">创建群组</div>
                <div class="dropdown-item" onclick="openJoinModal()">加入群组</div>
            </div>
        </div>

        <div class="modal" id="createGroupModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>创建新群组</h3>
                    <button class="close-btn" onclick="closeCreateModal()">&times;</button>
                </div>
                <input type="text" id="modal-group-name-input" class="modal-input" placeholder="群组名称">
                <div class="modal-actions">
                    <button class="modal-btn secondary" onclick="closeCreateModal()">取消</button>
                    <button class="modal-btn primary" id="modal-create-group-btn" onclick="createGroupFromModal()">创建</button>
                </div>
            </div>
        </div>

        <div class="modal" id="joinGroupModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>加入群组</h3>
                    <button class="close-btn" onclick="closeJoinModal()">&times;</button>
                </div>
                <input type="text" id="modal-group-id-input" class="modal-input" placeholder="群组ID">
                <div class="modal-actions">
                    <button class="modal-btn secondary" onclick="closeJoinModal()">取消</button>
                    <button class="modal-btn primary" id="modal-join-group-btn" onclick="joinGroupFromModal()">加入</button>
                </div>
            </div>
        </div>

        <div class="tabs">
            <div class="tab active" data-tab="groups">群聊</div>
        </div>

        <div id="groups-tab" class="tab-content" style="display: block;">
            <h3>我的群组</h3>
            <div class="groups-list" id="groups-container">
                <?php if (empty($userGroups)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            </svg>
                        </div>
                        <p>暂无群组，点击右上角菜单创建或加入一个吧</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($userGroups as $groupId => $group): ?>
                        <div class="group-item" onclick="openGroup('<?php echo $groupId; ?>')">
                            <div class="group-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                                </svg>
                            </div>
                            <div class="group-info">
                                <div class="group-name"><?php echo htmlspecialchars($group['name']); ?></div>
                                <div class="group-meta">
                                    <span>成员: <?php echo count($group['members']); ?>人</span>
                                    <?php if ($group['creator'] === $username): ?>
                                        <span class="group-creator">创建者</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <div id="chat-message-container" style="padding: 0 1.5rem;"></div>
    <?php include 'bottom_nav.php'; ?>
    <script>
        let canCreateGroup = true, canJoinGroup = true;
        const msgContainer = document.getElementById('chat-message-container');

        function showMsg(msg, type='danger') {
            const alertClass = type==='success' ? 'alert-success' : 'alert-danger';
            msgContainer.innerHTML = `<div class="alert ${alertClass}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:4px;"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M12 16h.01"/></svg> ${msg}</div>`;
            if (type!=='success') setTimeout(()=>msgContainer.innerHTML='', 3000);
        }

        function openCreateModal() { document.getElementById('createGroupModal').classList.add('active'); }
        function closeCreateModal() { document.getElementById('createGroupModal').classList.remove('active'); document.getElementById('modal-group-name-input').value = ''; }
        async function createGroupFromModal() {
            if (!canCreateGroup) return;
            const input = document.getElementById('modal-group-name-input');
            const btn = document.getElementById('modal-create-group-btn');
            const name = input.value.trim();
            if (!name) { showMsg('群组名称不能为空'); return; }
            if (name.length < 2) { showMsg('群组名称至少需要2个字符'); return; }
            canCreateGroup = false; btn.disabled = true;
            const original = btn.innerHTML; btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-svg" style="animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10" stroke-dasharray="30 60"/></svg> 创建中...';
            try {
                const fd = new FormData(); fd.append('action','create_group'); fd.append('group_name',name);
                const res = await fetch('/chatroom.php',{method:'POST',body:fd});
                const data = await res.json();
                if (data.status==='success') { showMsg(data.message,'success'); input.value = ''; closeCreateModal(); setTimeout(()=>window.location.reload(),1500); }
                else { showMsg(data.message); setTimeout(()=>{ canCreateGroup=true; btn.disabled=false; btn.innerHTML=original; },2000); }
            } catch(e) { showMsg('网络错误，请重试'); setTimeout(()=>{ canCreateGroup=true; btn.disabled=false; btn.innerHTML=original; },2000); }
        }

        function openJoinModal() { document.getElementById('joinGroupModal').classList.add('active'); }
        function closeJoinModal() { document.getElementById('joinGroupModal').classList.remove('active'); document.getElementById('modal-group-id-input').value = ''; }
        async function joinGroupFromModal() {
            if (!canJoinGroup) return;
            const input = document.getElementById('modal-group-id-input');
            const btn = document.getElementById('modal-join-group-btn');
            const id = input.value.trim();
            if (!id) { showMsg('群组ID不能为空'); return; }
            canJoinGroup = false; btn.disabled = true;
            const original = btn.innerHTML; btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-svg" style="animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10" stroke-dasharray="30 60"/></svg> 加入中...';
            try {
                const fd = new FormData(); fd.append('action','join_group'); fd.append('group_id',id);
                const res = await fetch('/chatroom.php',{method:'POST',body:fd});
                const data = await res.json();
                if (data.status==='success') { showMsg(data.message,'success'); input.value = ''; closeJoinModal(); setTimeout(()=>window.location.reload(),1500); }
                else { showMsg(data.message); setTimeout(()=>{ canJoinGroup=true; btn.disabled=false; btn.innerHTML=original; },2000); }
            } catch(e) { showMsg('网络错误，请重试'); setTimeout(()=>{ canJoinGroup=true; btn.disabled=false; btn.innerHTML=original; },2000); }
        }

        function openGroup(gid) { window.location.href = 'chatroom.php?group='+gid; }

        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                document.querySelectorAll('.tab-content').forEach(content => content.style.display = 'none');
                document.getElementById(`${target}-tab`).style.display = 'block';
            });
        });

        const menuToggle = document.getElementById('menuToggle');
        const dropdownMenu = document.getElementById('dropdownMenu');
        menuToggle.addEventListener('click', function(e) { e.stopPropagation(); dropdownMenu.classList.toggle('active'); });
        document.addEventListener('click', function() { dropdownMenu.classList.remove('active'); });
        document.querySelectorAll('.modal').forEach(modal => { modal.addEventListener('click', function(e) { if (e.target === modal) modal.classList.remove('active'); }); });

        const style = document.createElement('style');
        style.textContent = `@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }`;
        document.head.appendChild(style);
    </script>
</body>
</html>
    <?php
}

function showChatPage($groupId) {
    global $currentUserForTheme;
    $username = getCurrentChatUsername();
    $group = getChatGroup($groupId);
    if (!$group || !in_array($username, $group['members'])) { showGroupsPage(); return; }
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title><?php echo htmlspecialchars($group['name']); ?> - 主播模拟器论坛</title>
        <link rel="stylesheet" href="/css/style.css?v=1782016963">
        <link rel="stylesheet" href="/theme.css">
        <?php
        if ($currentUserForTheme && isset($currentUserForTheme['theme']) && $currentUserForTheme['theme'] === 'custom' && !empty($currentUserForTheme['theme_settings'])) {
            $settings = $currentUserForTheme['theme_settings'];
            $primary = $settings['primary'] ?? '#2196F3';
            list($r, $g, $b) = sscanf($primary, "#%02x%02x%02x");
            $r = max(0, $r - 20);
            $g = max(0, $g - 20);
            $b = max(0, $b - 20);
            $to = sprintf("#%02x%02x%02x", $r, $g, $b);
            echo "<style data-page-style>:root{--accent-color:$primary;--accent-gradient-from:$primary;--accent-gradient-to:$to;}</style>";
        ?>
        <script>
            document.documentElement.style.setProperty('--accent-color', '<?php echo $primary; ?>');
            document.documentElement.style.setProperty('--accent-gradient-from', '<?php echo $primary; ?>');
            document.documentElement.style.setProperty('--accent-gradient-to', '<?php echo $to; ?>');
        </script>
        <?php } ?>
        <style>
            body { background: var(--bg-secondary); margin: 0; padding: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif; }
            .chat-container { max-width: 1200px; margin: 0 auto; background: var(--bg-primary); border-radius: 0; overflow: hidden; box-shadow: var(--card-shadow); display: flex; flex-direction: column; height: 100vh; }
            .chat-header {
                background: var(--accent-gradient-from); color: white; padding: 1rem 1.5rem;
                display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;
            }
            .chat-header-left { display: flex; align-items: center; gap: 1rem; }
            .back-btn { background: none; border: none; color: white; font-size: 1.25rem; cursor: pointer; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: background 0.3s; }
            .back-btn:hover { background: rgba(255,255,255,0.1); }
            .chat-header-info h2 { margin: 0; font-size: 1.25rem; display: flex; align-items: center; gap: 0.5rem; }
            .current-time {
                font-size: 0.85rem;
                opacity: 0.9;
                margin-top: 0.25rem;
                font-family: monospace;
                letter-spacing: 1px;
            }
            .chat-header-right { display: flex; align-items: center; gap: 0.5rem; }
            .chat-icon-btn { background: none; border: none; color: white; font-size: 1.25rem; cursor: pointer; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: background 0.3s; }
            .chat-icon-btn:hover { background: rgba(255,255,255,0.1); }
            .menu-dropdown {
                position: absolute; top: 70px; right: 20px; background: var(--bg-primary); border-radius: 0;
                box-shadow: 0 4px 15px rgba(0,0,0,0.2); min-width: 160px; z-index: 1000; display: none;
                overflow: hidden; border: 1px solid var(--border-color);
            }
            .menu-dropdown.active { display: block; }
            .menu-dropdown .dropdown-item {
                padding: 0.75rem 1.2rem; color: var(--text-primary); cursor: pointer;
                transition: background 0.2s; border-bottom: 1px solid var(--border-color); font-size: 0.9rem;
            }
            .menu-dropdown .dropdown-item:last-child { border-bottom: none; }
            .menu-dropdown .dropdown-item:hover { background: var(--link-hover-bg); }
            .chat-main { flex: 1; overflow: hidden; display: flex; flex-direction: column; position: relative; }
            .messages-area { flex: 1; overflow-y: auto; padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; background: var(--bg-secondary); }
            .message { max-width: 70%; word-break: break-word; }
            .message.own { align-self: flex-end; }
            .message-content { padding: 0.75rem 1rem; border-radius: 0; font-size: 0.95rem; line-height: 1.4; position: relative; }
            .message.own .message-content { background: var(--accent-gradient-from); color: white; border-bottom-right-radius: 4px; }
            .message.other .message-content { background: var(--bg-primary); color: var(--text-primary); border: 1px solid var(--border-color); border-bottom-left-radius: 4px; }
            .reply-container { background: rgba(0,0,0,0.05); border-radius: 0; padding: 0.5rem; margin-bottom: 0.5rem; border-left: 3px solid var(--accent-color); font-size: 0.85rem; }
            .message.own .reply-container { background: rgba(255,255,255,0.1); }
            .reply-user { font-weight: 600; margin-bottom: 0.25rem; color: var(--accent-color); }
            .reply-content { opacity: 0.8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .message-info { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
            .message-avatar-link {
                display: inline-block;
                text-decoration: none;
                line-height: 0;
            }
            .message-avatar {
                width: 28px;
                height: 28px;
                border-radius: 50%;
                background: var(--accent-gradient-from);
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                font-size: 0.85rem;
                overflow: hidden;
                cursor: pointer;
                flex-shrink: 0;
                text-transform: uppercase;
            }
            .message-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .message-user { font-weight: 500; font-size: 0.9rem; color: var(--text-secondary); }
            .message-time { font-size: 0.75rem; color: #999; margin-top: 0.25rem; text-align: right; }
            .message.own .message-time { color: rgba(255,255,255,0.7); }
            .message-image { max-width: 100%; max-height: 300px; border-radius: 0; cursor: pointer; margin-top: 0.5rem; object-fit: cover; border: 1px solid var(--border-color); }
            .message-deleted { opacity: 0.6; font-style: italic; }
            .message-deleted .message-content { background: var(--bg-secondary) !important; color: var(--text-secondary) !important; border: 1px dashed var(--border-color) !important; }
            .input-area { background: var(--bg-primary); border-top: 1px solid var(--border-color); display: flex; gap: 0.75rem; align-items: flex-end; padding: 1rem 1.5rem; flex-shrink: 0; }

            .message-input {
                flex: 1;
                padding: 12px;
                border: none;
                border-radius: 0;
                box-shadow: 2px 2px 7px 0 rgb(0, 0, 0, 0.2);
                outline: none;
                color: var(--text-primary);
                background-color: var(--bg-secondary);
                font-size: 0.95rem;
                resize: none;
                max-height: 120px;
                min-height: 48px;
                line-height: 1.4;
                font-family: inherit;
                transition: box-shadow 0.3s, background-color 0.2s;
            }
            .message-input:focus {
                box-shadow: 2px 2px 12px 0 rgba(0,0,0,0.3);
                background-color: var(--bg-primary);
            }

            .send-btn { background: var(--accent-gradient-from); color: white; border: none; border-radius: 50%; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.3s; flex-shrink: 0; }
            .send-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(94,114,228,0.3); }
            .send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
            .reply-preview { background: var(--bg-secondary); border-left: 3px solid var(--accent-color); padding: 0.75rem 1rem; margin: 0 1.5rem; border-radius: 0; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); }
            .reply-preview-content { flex: 1; overflow: hidden; }
            .reply-preview-user { font-weight: 600; font-size: 0.85rem; color: var(--accent-color); margin-bottom: 0.25rem; }
            .reply-preview-text { font-size: 0.85rem; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .cancel-reply-btn { background: none; border: none; color: var(--text-secondary); font-size: 1rem; cursor: pointer; padding: 0.25rem; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; }
            .cancel-reply-btn:hover { background: rgba(0,0,0,0.05); }
            .loading { text-align: center; padding: 2rem; color: var(--text-secondary); }
            .loading-spinner { font-size: 1.5rem; margin-bottom: 0.5rem; color: var(--accent-color); }
            .new-message-indicator { position: absolute; bottom: 80px; left: 50%; transform: translateX(-50%); background: var(--accent-gradient-from); color: white; padding: 0.5rem 1rem; border-radius: 0; font-size: 0.85rem; cursor: pointer; z-index: 100; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
            .icon-svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
            .long-press-menu {
                position: fixed;
                background: var(--bg-primary);
                border-radius: 0;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                padding: 0.5rem 0;
                min-width: 120px;
                z-index: 2000;
                overflow: hidden;
                border: 1px solid var(--border-color);
                backdrop-filter: blur(10px);
            }
            .long-press-menu .menu-item {
                padding: 0.75rem 1.2rem;
                color: var(--text-primary);
                cursor: pointer;
                transition: background 0.2s;
                font-size: 0.9rem;
                white-space: nowrap;
            }
            .long-press-menu .menu-item:hover {
                background: var(--link-hover-bg);
            }
            @media (max-width: 768px) {
                .chat-container { margin: 0; border-radius: 0; height: 100vh; }
                .chat-header { padding: 0.75rem 1rem; }
                .back-btn { width: 36px; height: 36px; }
                .chat-header-info h2 { font-size: 1.1rem; }
                .message { max-width: 85%; }
                .messages-area { padding: 1rem; }
                .message-image { max-height: 200px; }
                .input-area { padding: 0.75rem 1rem; }
                .message-input { font-size: 0.9rem; min-height: 44px; }
                .send-btn { width: 44px; height: 44px; }
                .reply-preview { margin: 0 1rem; padding: 0.5rem 0.75rem; }
                .long-press-menu { min-width: 100px; }
            }
        </style>
        <script src="/theme.js"></script>
    </head>
    <body>
        <div class="chat-container">
            <div class="chat-header">
                <div class="chat-header-left">
                    <button class="back-btn" onclick="goBack()">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 12H5M12 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <div class="chat-header-info">
                        <h2>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                            <?php echo htmlspecialchars($group['name']); ?>
                        </h2>
                        <div class="current-time" id="liveClock"></div>
                    </div>
                </div>
                <div class="chat-header-right">
                    <button class="chat-icon-btn" id="uploadImageBtn" title="发送图片">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                            <circle cx="8.5" cy="8.5" r="1.5"/>
                            <polyline points="21 15 16 10 5 21"/>
                        </svg>
                    </button>
                    <button class="chat-icon-btn" id="menuToggle" title="菜单">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="1" fill="currentColor"/>
                            <circle cx="12" cy="5" r="1" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1" fill="currentColor"/>
                        </svg>
                    </button>
                    <div class="menu-dropdown" id="menuDropdown">
                        <div class="dropdown-item" onclick="shareGroupId()">分享群组ID</div>
                    </div>
                </div>
            </div>
            <div class="chat-main">
                <div class="reply-preview" id="reply-preview" style="display: none;">
                    <div class="reply-preview-content">
                        <div class="reply-preview-user" id="reply-preview-user"></div>
                        <div class="reply-preview-text" id="reply-preview-text"></div>
                    </div>
                    <button class="cancel-reply-btn" onclick="cancelReply()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="messages-area" id="messages-area">
                    <div class="loading" id="loading-messages">
                        <div class="loading-spinner">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;">
                                <circle cx="12" cy="12" r="10" stroke-dasharray="30 60"/>
                            </svg>
                        </div>
                        <div>加载消息中...</div>
                    </div>
                </div>
                <div class="new-message-indicator" id="new-message-indicator" style="display: none;">有新消息，点击查看</div>
                <div class="input-area">
                    <textarea class="message-input" id="message-input" placeholder="输入消息..." rows="1"></textarea>
                    <button class="send-btn" id="send-btn" onclick="sendMessage()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        <input type="file" id="imageFileInput" accept="image/*" style="display: none;">

        <script>
            let currentUser = '<?php echo $username; ?>';
            let currentUserId = <?php echo $_SESSION['user_id']; ?>;
            let isAdminUser = <?php echo isAdmin() ? 'true' : 'false'; ?>;
            let currentGroupId = '<?php echo $groupId; ?>';
            let lastMessagesHash = ''; // 用于检测消息是否真正变化
            let isAutoScrolling = true;
            let canSendMessage = true;
            let replyingTo = null;
            let uploadingImage = false;
            
            let longPressTimer = null;
            let currentPressMessage = null;
            let longPressMenu = null;
            
            function createLongPressMenu() {
                if (document.getElementById('longPressMenu')) return;
                const menu = document.createElement('div');
                menu.id = 'longPressMenu';
                menu.className = 'long-press-menu';
                menu.style.display = 'none';
                document.body.appendChild(menu);
                longPressMenu = menu;
            }
            
            function showLongPressMenu(messageElement, event) {
                if (longPressMenu) longPressMenu.style.display = 'none';
                createLongPressMenu();
                longPressMenu.innerHTML = '';
                
                const messageId = messageElement.dataset.messageId;
                const messageUsername = messageElement.dataset.messageUsername;
                const messageTime = parseInt(messageElement.dataset.messageTime || 0);
                const isOwn = messageUsername === currentUser;
                const isDeleted = messageElement.classList.contains('message-deleted');
                
                const replyItem = document.createElement('div');
                replyItem.className = 'menu-item';
                replyItem.innerHTML = ' 回复';
                replyItem.onclick = (e) => {
                    e.stopPropagation();
                    longPressMenu.style.display = 'none';
                    replyToMessage(messageId);
                };
                longPressMenu.appendChild(replyItem);
                
                if (!isDeleted && (isOwn || isAdminUser)) {
                    const deleteItem = document.createElement('div');
                    deleteItem.className = 'menu-item';
                    deleteItem.innerHTML = ' 撤回';
                    deleteItem.onclick = (e) => {
                        e.stopPropagation();
                        longPressMenu.style.display = 'none';
                        deleteMessage(messageId);
                    };
                    longPressMenu.appendChild(deleteItem);
                }
                
                let clientX, clientY;
                if (event.touches) {
                    clientX = event.touches[0].clientX;
                    clientY = event.touches[0].clientY;
                } else {
                    clientX = event.clientX;
                    clientY = event.clientY;
                }
                
                longPressMenu.style.display = 'block';
                const menuRect = longPressMenu.getBoundingClientRect();
                let left = clientX;
                let top = clientY;
                if (left + menuRect.width > window.innerWidth) {
                    left = window.innerWidth - menuRect.width - 10;
                }
                if (top + menuRect.height > window.innerHeight) {
                    top = window.innerHeight - menuRect.height - 10;
                }
                longPressMenu.style.left = left + 'px';
                longPressMenu.style.top = top + 'px';
                
                const closeMenuHandler = (e) => {
                    if (!longPressMenu.contains(e.target)) {
                        longPressMenu.style.display = 'none';
                        document.removeEventListener('click', closeMenuHandler);
                        document.removeEventListener('touchstart', closeMenuHandler);
                    }
                };
                setTimeout(() => {
                    document.addEventListener('click', closeMenuHandler);
                    document.addEventListener('touchstart', closeMenuHandler);
                }, 0);
            }
            
            function startLongPress(messageElement, event) {
                if (longPressTimer) clearTimeout(longPressTimer);
                longPressTimer = setTimeout(() => {
                    longPressTimer = null;
                    showLongPressMenu(messageElement, event);
                }, 500);
            }
            
            function cancelLongPress() {
                if (longPressTimer) {
                    clearTimeout(longPressTimer);
                    longPressTimer = null;
                }
            }
            
            function createMessageElement(msg) {
                const isOwn = msg.username === currentUser;
                const isDeleted = msg.deleted || false;
                
                let avatarHtml = '';
                if (msg.avatar && msg.avatar.trim() !== '') {
                    avatarHtml = `<img src="${msg.avatar}" alt="avatar">`;
                } else if (msg.avatar_text && msg.avatar_text.trim() !== '') {
                    avatarHtml = msg.avatar_text.substring(0, 2);
                } else {
                    const firstChar = msg.username ? msg.username.charAt(0).toUpperCase() : '?';
                    avatarHtml = firstChar;
                }
                const userProfileUrl = msg.user_id ? 'user.php?id=' + msg.user_id : '#';
                const avatarLink = `<a href="${userProfileUrl}" class="message-avatar-link" onclick="event.stopPropagation()"><div class="message-avatar">${avatarHtml}</div></a>`;
                
                const time = new Date(msg.time * 1000).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
                let content = msg.type === 'image' ? `<img src="${msg.file_url}" class="message-image" loading="lazy">` : (isDeleted ? '<i>此消息已被撤回</i>' : msg.content);
                let replySection = '';
                if (msg.reply_to && msg.reply_to_content && !isDeleted) {
                    replySection = `<div class="reply-container"><div class="reply-user">${msg.reply_to_username || '用户'}</div><div class="reply-content">${msg.reply_to_content}</div></div>`;
                }
                
                const div = document.createElement('div');
                div.className = `message ${isOwn ? 'own' : 'other'} ${isDeleted ? 'message-deleted' : ''}`;
                div.dataset.messageId = msg.id || 'temp';
                div.dataset.messageUsername = msg.username;
                div.dataset.messageTime = msg.time;
                if (msg.tempId) div.dataset.tempId = msg.tempId;
                div.innerHTML = `<div class="message-info">${avatarLink}<div class="message-user">${msg.username}</div></div><div class="message-content">${replySection}${content}<div class="message-time">${time}</div></div>`;
                
                div.addEventListener('touchstart', (e) => { startLongPress(div, e); });
                div.addEventListener('touchend', cancelLongPress);
                div.addEventListener('touchmove', cancelLongPress);
                div.addEventListener('touchcancel', cancelLongPress);
                div.addEventListener('mousedown', (e) => {
                    if (e.button !== 0) return;
                    startLongPress(div, e);
                });
                div.addEventListener('mouseup', cancelLongPress);
                div.addEventListener('mouseleave', cancelLongPress);
                div.addEventListener('contextmenu', (e) => { e.preventDefault(); });
                
                return div;
            }
            
            async function deleteMessage(messageId) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete_message');
                    formData.append('group_id', currentGroupId);
                    formData.append('message_id', messageId);
                    const response = await fetch('/chatroom.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });
                    const data = await response.json();
                    if (data.status === 'success') {
                        // 立即更新本地消息显示
                        const msgDiv = document.querySelector(`.message[data-message-id="${messageId}"]`);
                        if (msgDiv && !msgDiv.classList.contains('message-deleted')) {
                            msgDiv.classList.add('message-deleted');
                            const contentDiv = msgDiv.querySelector('.message-content');
                            if (contentDiv) {
                                const replyContainer = contentDiv.querySelector('.reply-container');
                                if (replyContainer) {
                                    contentDiv.innerHTML = replyContainer.outerHTML + '<i>此消息已被撤回</i>';
                                } else {
                                    contentDiv.innerHTML = '<i>此消息已被撤回</i>';
                                }
                            }
                            msgDiv.dataset.messageDeleted = 'true';
                        }
                        // 撤回后主动更新一次
                        await fetchMessagesAndUpdate();
                    } else {
                        alert('撤回失败：' + (data.message || '未知错误'));
                    }
                } catch (err) {
                    console.error(err);
                    alert('撤回失败，请检查网络连接');
                }
            }
            
            // 更新实时时钟
            function updateLiveClock() {
                const now = new Date();
                const hours = now.getHours().toString().padStart(2, '0');
                const minutes = now.getMinutes().toString().padStart(2, '0');
                const seconds = now.getSeconds().toString().padStart(2, '0');
                const clockElem = document.getElementById('liveClock');
                if (clockElem) {
                    clockElem.textContent = `${hours}:${minutes}:${seconds}`;
                }
            }
            
            // 计算消息列表的唯一标识（用于判断是否有实质变化）
            function computeMessagesHash(messages) {
                if (!messages || messages.length === 0) return '';
                let hashStr = '';
                for (let msg of messages) {
                    hashStr += msg.id + '|' + (msg.deleted ? '1' : '0') + '|' + msg.content + '|';
                }
                return hashStr.length + '_' + messages.length + '_' + (messages.length > 0 ? messages[messages.length-1].id : '');
            }
            
            async function fetchMessagesAndUpdate() {
                try {
                    const fd = new FormData(); fd.append('action', 'get_messages'); fd.append('group_id', currentGroupId);
                    const res = await fetch('/chatroom.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.status === 'success') {
                        const currentMessages = data.messages;
                        const newHash = computeMessagesHash(currentMessages);
                        if (newHash !== lastMessagesHash) {
                            displayMessages(currentMessages);
                            lastMessagesHash = newHash;
                            if (isAutoScrolling) {
                                const container = document.getElementById('messages-area');
                                setTimeout(() => { container.scrollTop = container.scrollHeight; }, 50);
                            } else {
                                showNewMessageIndicator(1);
                            }
                        }
                    }
                } catch (e) {
                    console.error('获取消息失败:', e);
                }
            }
            
            function displayMessages(msgs) {
                const container = document.getElementById('messages-area');
                if (!msgs || msgs.length === 0) {
                    container.innerHTML = '<div class="loading">还没有消息，开始聊天吧！</div>';
                    return;
                }
                container.innerHTML = '';
                msgs.forEach(msg => {
                    container.appendChild(createMessageElement(msg));
                });
                if (isAutoScrolling) {
                    setTimeout(() => { container.scrollTop = container.scrollHeight; }, 100);
                }
            }
            
            async function sendMessage() {
                if (!canSendMessage) return; const input = document.getElementById('message-input'); const sendBtn = document.getElementById('send-btn');
                const msg = input.value.trim(); if (!msg) return;
                canSendMessage = false; sendBtn.disabled = true;
                const tempId = 'temp_' + Date.now();
                const tempMsg = {
                    id: tempId, tempId: tempId, username: currentUser,
                    user_id: <?php echo $_SESSION['user_id']; ?>,
                    avatar: '<?php echo addslashes($_SESSION['avatar'] ?? ''); ?>',
                    avatar_text: '',
                    content: msg, type: 'text', time: Math.floor(Date.now() / 1000),
                    reply_to: replyingTo,
                    deleted: false
                };
                const container = document.getElementById('messages-area');
                container.appendChild(createMessageElement(tempMsg));
                if (isAutoScrolling) container.scrollTop = container.scrollHeight;
                input.value = ''; input.style.height = 'auto'; cancelReply();
                try {
                    const fd = new FormData(); fd.append('action', 'send_message'); fd.append('group_id', currentGroupId); fd.append('message', msg); if (replyingTo) fd.append('reply_to', replyingTo);
                    const res = await fetch('/chatroom.php', { method: 'POST', body: fd }); const data = await res.json();
                    if (data.status === 'success') {
                        await fetchMessagesAndUpdate();
                    } else {
                        document.querySelector(`[data-temp-id="${tempId}"]`)?.remove();
                        alert('发送失败: ' + data.message);
                    }
                } catch (e) {
                    document.querySelector(`[data-temp-id="${tempId}"]`)?.remove();
                    alert('网络错误，请重试');
                } finally {
                    setTimeout(() => { canSendMessage = true; sendBtn.disabled = false; }, 1000);
                }
            }
            
            function replyToMessage(id) {
                const el = document.querySelector(`[data-message-id="${id}"]`); if (!el) return;
                if (el.classList.contains('message-deleted')) {
                    alert('消息已撤回，不能回复');
                    return;
                }
                const username = el.querySelector('.message-user').textContent;
                const contentEl = el.querySelector('.message-content'); let content = '';
                const clone = contentEl.cloneNode(true);
                const replyContainer = clone.querySelector('.reply-container'); if (replyContainer) clone.removeChild(replyContainer);
                const msgTime = clone.querySelector('.message-time'); if (msgTime) clone.removeChild(msgTime);
                content = clone.textContent.trim(); if (content.length > 50) content = content.substring(0, 50) + '...';
                replyingTo = id;
                document.getElementById('reply-preview-user').textContent = `回复 ${username}`;
                document.getElementById('reply-preview-text').textContent = content;
                document.getElementById('reply-preview').style.display = 'flex'; document.getElementById('message-input').focus();
            }
            
            function cancelReply() { replyingTo = null; document.getElementById('reply-preview').style.display = 'none'; }
            
            function showNewMessageIndicator(cnt) {
                const ind = document.getElementById('new-message-indicator');
                ind.textContent = cnt > 1 ? `有${cnt}条新消息，点击查看` : '有新消息，点击查看'; ind.style.display = 'block';
                ind.onclick = function() { document.getElementById('messages-area').scrollTop = document.getElementById('messages-area').scrollHeight; ind.style.display = 'none'; isAutoScrolling = true; };
            }
            
            function previewImage(url) { window.open(url, '_blank'); }
            
            document.addEventListener('DOMContentLoaded', async function() {
                // 加载初始消息
                const fd = new FormData(); fd.append('action', 'get_messages'); fd.append('group_id', currentGroupId);
                try {
                    const res = await fetch('/chatroom.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.status === 'success') {
                        displayMessages(data.messages);
                        lastMessagesHash = computeMessagesHash(data.messages);
                        document.getElementById('loading-messages').style.display = 'none';
                    }
                } catch (e) {
                    console.error('加载消息失败:', e);
                }
                // 启动实时时钟（每秒更新）
                updateLiveClock();
                setInterval(updateLiveClock, 1000);
                
                const messagesArea = document.getElementById('messages-area');
                messagesArea.addEventListener('click', function(e) {
                    if (e.target.classList.contains('message-image')) {
                        previewImage(e.target.src);
                    }
                });
                const input = document.getElementById('message-input');
                input.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                });
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
                });
                messagesArea.addEventListener('scroll', function() {
                    const scrollPos = messagesArea.scrollHeight - messagesArea.scrollTop - messagesArea.clientHeight;
                    isAutoScrolling = scrollPos < 50;
                    const indicator = document.getElementById('new-message-indicator');
                    if (scrollPos > 100 && indicator.style.display !== 'none') { indicator.style.display = 'none'; }
                });
                // 页面获得焦点时主动更新一次（用户切回聊天室时同步最新消息）
                window.addEventListener('focus', function() {
                    fetchMessagesAndUpdate();
                });
                
                document.getElementById('uploadImageBtn').addEventListener('click', function() {
                    if (uploadingImage) { alert('正在上传图片，请稍后'); return; }
                    document.getElementById('imageFileInput').click();
                });
                
                document.getElementById('imageFileInput').addEventListener('change', async function(e) {
                    const file = e.target.files[0];
                    if (!file) return;
                    if (!file.type.startsWith('image/')) { alert('请选择图片文件'); return; }
                    if (file.size > 5 * 1024 * 1024) { alert('图片大小不能超过5MB'); return; }
                    uploadingImage = true; canSendMessage = false;
                    const formData = new FormData();
                    formData.append('image', file); formData.append('ajax', 'upload_image');
                    formData.append('group_id', currentGroupId); if (replyingTo) formData.append('reply_to', replyingTo);
                    try {
                        const response = await fetch('/chatroom.php', { method: 'POST', body: formData });
                        const data = await response.json();
                        if (data.success) { cancelReply(); await fetchMessagesAndUpdate(); }
                        else { alert('上传失败：' + data.message); }
                    } catch (err) { alert('网络错误，请重试'); }
                    finally { uploadingImage = false; canSendMessage = true; e.target.value = ''; }
                });
            });
            
            function goBack() { window.location.href = '<?php echo url('chatroom'); ?>'; }
            
            const menuToggleBtn = document.getElementById('menuToggle'); const menuDropdown = document.getElementById('menuDropdown');
            menuToggleBtn.addEventListener('click', function(e) { e.stopPropagation(); menuDropdown.classList.toggle('active'); });
            document.addEventListener('click', function() { menuDropdown.classList.remove('active'); });
            
            function shareGroupId() {
                const groupId = '<?php echo $groupId; ?>';
                if (navigator.share) {
                    navigator.share({
                        title: '分享群组ID',
                        text: '加入聊天室群组，群组ID：' + groupId,
                        url: window.location.href
                    }).catch(() => {});
                } else if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(groupId).then(() => {
                        alert('群组ID已复制：' + groupId);
                    }).catch(() => {
                        prompt('请手动复制群组ID', groupId);
                    });
                } else {
                    prompt('请手动复制群组ID', groupId);
                }
            }
            
            const style = document.createElement('style');
            style.textContent = `@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }`;
            document.head.appendChild(style);
        </script>
    </body>
    </html>
    <?php
}
?>