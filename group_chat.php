<?php
require_once __DIR__ . '/functions.php';

$currentUserForTheme = getCurrentUser();
checkMaintenanceMode($currentUserForTheme);

if (!isLoggedIn()) {
    showAuthModalOnly();
    exit;
}

// ========== 常量与数据文件 ==========
define('CHAT_DATA_DIR', __DIR__ . '/data/chat/');
define('CHAT_GROUPS_FILE', CHAT_DATA_DIR . 'groups.json');
define('CHAT_ONLINE_USERS_FILE', CHAT_DATA_DIR . 'online.json');
define('CHAT_READ_STATE_FILE', CHAT_DATA_DIR . 'read_state.json');
if (!file_exists(CHAT_DATA_DIR)) mkdir(CHAT_DATA_DIR, 0755, true);

// ========== 核心函数 ==========
function getChatGroups() {
    if (!file_exists(CHAT_GROUPS_FILE)) return [];
    $content = file_get_contents(CHAT_GROUPS_FILE);
    return $content ? (json_decode($content, true) ?: []) : [];
}
function saveChatGroups($groups) {
    return file_put_contents(CHAT_GROUPS_FILE, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function getChatGroup($groupId) {
    $groups = getChatGroups();
    return $groups[$groupId] ?? null;
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
function getCurrentChatUsername() {
    return $_SESSION['chat_username'] ?? $_SESSION['username'] ?? '游客';
}
function isChatLoggedIn() {
    return isset($_SESSION['chat_username']) || isset($_SESSION['user_id']);
}
function updateChatOnlineUser($username) {
    $onlineUsers = [];
    if (file_exists(CHAT_ONLINE_USERS_FILE)) {
        $content = file_get_contents(CHAT_ONLINE_USERS_FILE);
        $onlineUsers = $content ? (json_decode($content, true) ?: []) : [];
    }
    $onlineUsers[$username] = time();
    $count = 0;
    foreach ($onlineUsers as $u => $t) {
        if (time() - $t <= 120) $count++;
    }
    file_put_contents(CHAT_ONLINE_USERS_FILE, json_encode($onlineUsers, JSON_UNESCAPED_UNICODE));
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
            $uname = $msg['username'] ?? '';
            if ($uname && isset($userCache[$uname])) {
                $msg['user_id'] = $userCache[$uname]['id'];
                $msg['avatar_text'] = $userCache[$uname]['avatar_text'] ?? '';
                $msg['avatar_bg'] = $userCache[$uname]['avatar_bg'] ?? '';
            } elseif ($uname) {
                try {
                    $pdo = getDbConnection();
                    $stmt = $pdo->prepare("SELECT id, avatar_text, avatar_bg_color FROM users WHERE username = ? LIMIT 1");
                    $stmt->execute([$uname]);
                    $u = $stmt->fetch();
                    if ($u) {
                        $msg['user_id'] = $u['id'];
                        $msg['avatar_text'] = $u['avatar_text'] ?? '';
                        $msg['avatar_bg'] = $u['avatar_bg_color'] ?? '';
                        $userCache[$uname] = ['id' => $u['id'], 'avatar_text' => $u['avatar_text'] ?? '', 'avatar_bg' => $u['avatar_bg_color'] ?? ''];
                    }
                } catch (Exception $e) {}
            }
        }
        if (!isset($msg['deleted'])) $msg['deleted'] = false;
        if (!isset($msg['type'])) $msg['type'] = 'text';
    }
    return $messages;
}
function addGroupChatMessage($groupId, $username, $content, $type = 'text', $fileUrl = '', $replyTo = null, $avatar = '', $userId = 0) {
    $messages = getGroupChatMessages($groupId, 200);
    $avatarText = '';
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT avatar_text, avatar_bg_color FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $avatarText = $user ? $user['avatar_text'] : '';
        $avatarBg = $user ? ($user['avatar_bg_color'] ?? '') : '';
    } catch (Exception $e) {}
    $message = [
        'id' => uniqid(),
        'user_id' => $userId,
        'username' => $username,
        'avatar' => $avatar,
        'avatar_text' => $avatarText,
        'avatar_bg' => $avatarBg,
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
    if ($found) {
        file_put_contents($groupMessagesFile, json_encode($messages, JSON_UNESCAPED_UNICODE));
    }
    return $found;
}
function jsonResponse($data) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== AJAX 处理 (在所有 HTML 输出之前) ==========
if (isset($_POST['action'])) {
    $response = ['status' => 'error', 'message' => '未知操作'];
    try {
        switch ($_POST['action']) {
            case 'send_message':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupId = $_POST['group_id'] ?? '1';
                $username = getCurrentChatUsername();
                $group = getChatGroup($groupId);
                if (!$group || !in_array($username, $group['members'])) {
                    $response['message'] = '你不在这个群组中'; break;
                }
                $message = $_POST['message'] ?? '';
                $type = $_POST['type'] ?? 'text';
                $fileUrl = $_POST['file_url'] ?? '';
                $replyTo = $_POST['reply_to'] ?? null;
                $hasText = !empty(trim($message));
                $hasImage = !empty($fileUrl);
                if (!$hasText && !$hasImage) {
                    $response['message'] = '消息不能为空'; break;
                }
                if (!$hasImage && $type === 'text') {
                    // 纯文字
                }
                $avatar = '';
                $userId = $_SESSION['user_id'];
                try {
                    $pdo = getDbConnection();
                    $stmt = $pdo->prepare("SELECT avatar FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();
                    $avatar = $user ? $user['avatar'] : '';
                } catch (Exception $e) {}
                $msgType = $hasImage ? 'image_text' : 'text';
                if (addGroupChatMessage($groupId, $username, $hasText ? $message : '[图片]', $msgType, $fileUrl, $replyTo, $avatar, $userId)) {
                    updateChatOnlineUser($username);
                    $resp = ['status' => 'success', 'message' => '消息发送成功', 'message_id' => uniqid()];
                    // resolve reply_to in response so frontend can update bubble directly
                    if ($replyTo) {
                        $msgs = getGroupChatMessages($groupId, 200);
                        foreach ($msgs as $m) {
                            if ($m['id'] === $replyTo) {
                                $resp['reply_to_content'] = $m['content'];
                                $resp['reply_to_username'] = $m['username'];
                                break;
                            }
                        }
                    }
                    $response = $resp;
                } else {
                    $response['message'] = '发送失败';
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
                $messages = getGroupChatMessages($groupId, 200);
                // 构建 ID→消息 查找表
                $msgById = [];
                foreach ($messages as $m) {
                    if (!empty($m['id'])) $msgById[$m['id']] = $m;
                }
                foreach ($messages as &$msg) {
                    $msg['formatted_time'] = date('H:i', $msg['time']);
                    if (date('Y-m-d', $msg['time']) !== date('Y-m-d')) {
                        $msg['formatted_time'] = date('m-d H:i', $msg['time']);
                    }
                    if (!empty($msg['reply_to']) && isset($msgById[$msg['reply_to']])) {
                        $msg['reply_to_content'] = $msgById[$msg['reply_to']]['content'];
                        $msg['reply_to_username'] = $msgById[$msg['reply_to']]['username'];
                    }
                }
                unset($msg);
                updateChatOnlineUser($username);
                $response = ['status' => 'success', 'messages' => $messages, 'current_user' => $username, 'timestamp' => time()];
                break;

            case 'check_hash':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupId = $_POST['group_id'] ?? '1';
                $username = getCurrentChatUsername();
                $group = getChatGroup($groupId);
                if (!$group || !in_array($username, $group['members'])) {
                    $response['message'] = '你不在这个群组中'; break;
                }
                $messages = getGroupChatMessages($groupId, 200);
                $hashes = [];
                foreach ($messages as $msg) {
                    $hashes[] = ($msg['id'] ?? '') . '|' . ($msg['deleted'] ? '1' : '0');
                }
                $hash = implode(',', $hashes);
                $response = ['status' => 'success', 'hash' => $hash, 'count' => count($messages)];
                break;

            case 'mark_read':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupId = $_POST['group_id'] ?? '1';
                $username = getCurrentChatUsername();
                $userId = $_SESSION['user_id'];
                $messages = getGroupChatMessages($groupId, 200);
                $latestTime = 0;
                foreach ($messages as $msg) {
                    if (($msg['time'] ?? 0) > $latestTime) $latestTime = (int)$msg['time'];
                }
                // 保存已读状态
                $readState = [];
                if (file_exists(CHAT_READ_STATE_FILE)) {
                    $readState = json_decode(file_get_contents(CHAT_READ_STATE_FILE), true) ?: [];
                }
                $key = $userId . '_' . $groupId;
                $readState[$key] = ['time' => $latestTime > 0 ? $latestTime : time(), 'mentions' => 0];
                file_put_contents(CHAT_READ_STATE_FILE, json_encode($readState, JSON_UNESCAPED_UNICODE));
                $response = ['status' => 'success'];
                break;

            case 'clear_messages':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupId = $_POST['group_id'] ?? '1';
                $username = getCurrentChatUsername();
                $group = getChatGroup($groupId);
                if (!$group) { $response['message'] = '群组不存在'; break; }
                $isGroupOwner = ($username === ($group['creator'] ?? ''));
                $isAdmin = isAdmin() || isFounder();
                if (!$isGroupOwner && !$isAdmin) { $response['message'] = '无权限'; break; }
                $groupMessagesFile = CHAT_DATA_DIR . "group_{$groupId}.json";
                if (file_exists($groupMessagesFile)) {
                    file_put_contents($groupMessagesFile, json_encode([]));
                }
                $response = ['status' => 'success', 'message' => '聊天记录已清空'];
                break;

            case 'dissolve_group':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupId = $_POST['group_id'] ?? '1';
                $username = getCurrentChatUsername();
                $group = getChatGroup($groupId);
                if (!$group) { $response['message'] = '群组不存在'; break; }
                $isGroupOwner = ($username === ($group['creator'] ?? ''));
                if (!$isGroupOwner) { $response['message'] = '只有群主才能解散群聊'; break; }
                if (($group['creator'] ?? '') === 'system') { $response['message'] = '官方群聊无法解散'; break; }
                $groups = getChatGroups();
                unset($groups[$groupId]);
                saveChatGroups($groups);
                $groupMessagesFile = CHAT_DATA_DIR . "group_{$groupId}.json";
                if (file_exists($groupMessagesFile)) @unlink($groupMessagesFile);
                $response = ['status' => 'success', 'message' => '群聊已解散', 'redirect' => url('group_list')];
                break;

            case 'delete_message':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupId = $_POST['group_id'] ?? '1';
                $username = getCurrentChatUsername();
                $messageId = $_POST['message_id'] ?? '';
                if (empty($messageId)) { $response['message'] = '参数错误'; break; }
                $group = getChatGroup($groupId);
                if (!$group || !in_array($username, $group['members'])) {
                    $response['message'] = '你不在这个群组中'; break;
                }
                $isAdmin = isAdmin();
                $groupMessagesFile = CHAT_DATA_DIR . "group_{$groupId}.json";
                $messages = json_decode(file_get_contents($groupMessagesFile), true);
                $targetMsg = null;
                foreach ($messages as $msg) {
                    if ($msg['id'] === $messageId) { $targetMsg = $msg; break; }
                }
                if (!$targetMsg) { $response['message'] = '消息不存在'; break; }
                if ($targetMsg['username'] !== $username && !$isAdmin) {
                    $response['message'] = '只能撤回自己的消息'; break;
                }
                if ($targetMsg['deleted'] ?? false) { $response['message'] = '消息已被撤回'; break; }
                if (setMessageDeleted($groupId, $messageId, true)) {
                    $response = ['status' => 'success', 'message' => '消息已撤回'];
                } else {
                    $response['message'] = '撤回失败';
                }
                break;

            case 'create_group':
                if (!isChatLoggedIn()) { $response['message'] = '未登录'; break; }
                $groupName = trim($_POST['group_name'] ?? '');
                if (empty($groupName)) { $response['message'] = '群聊名称不能为空'; break; }
                if (mb_strlen($groupName) > 50) { $response['message'] = '群聊名称不能超过50个字符'; break; }
                $username = getCurrentChatUsername();
                $groups = getChatGroups();
                $groupId = uniqid();
                $groups[$groupId] = [
                    'name' => $groupName,
                    'creator' => $username,
                    'created_at' => time(),
                    'members' => [$username]
                ];
                if (saveChatGroups($groups)) {
                    $response = ['status' => 'success', 'message' => '群聊创建成功', 'group_id' => $groupId];
                } else {
                    $response['message'] = '创建失败';
                }
                break;

            default:
                break;
        }
    } catch (Exception $e) {
        $response['message'] = '服务器错误: ' . $e->getMessage();
    }
    jsonResponse($response);
}

// 处理图片上传 (before HTML)
if (isset($_FILES['image']) && isset($_POST['ajax']) && $_POST['ajax'] === 'upload_image') {
    $groupId = $_POST['group_id'] ?? '1';
    $username = getCurrentChatUsername();
    $replyTo = $_POST['reply_to'] ?? null;
    $file = $_FILES['image'];
    $uploadDir = __DIR__ . '/st/uploads/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        jsonResponse(['success' => false, 'message' => '不支持的图片格式']);
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        jsonResponse(['success' => false, 'message' => '图片不能超过5MB']);
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newName = uniqid() . '.' . ($ext ?: 'jpg');
    $dest = $uploadDir . $newName;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        $url = '/st/uploads/' . $newName;
        $userId = $_SESSION['user_id'];
        $avatar = '';
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT avatar FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            $avatar = $user ? $user['avatar'] : '';
        } catch (Exception $e) {}
        addGroupChatMessage($groupId, $username, '', 'image', $url, $replyTo, $avatar, $userId);
        updateChatOnlineUser($username);
        jsonResponse(['success' => true, 'url' => $url]);
    } else {
        jsonResponse(['success' => false, 'message' => '上传失败']);
    }
}

// ========== 页面渲染 (非 AJAX 请求) ==========
$forumUsername = $_SESSION['username'];
$groups = getChatGroups();

// 读取群组 ID
$groupId = $_GET['group_id'] ?? '1';

// 确保目标群组存在，不存在则回退到官方群
$group = getChatGroup($groupId);
if (!$group) {
    $groupId = '1';
    // 确保官方群存在
    if (!isset($groups['1'])) {
        $groups['1'] = [
            'name' => '官方聊天群',
            'creator' => 'system',
            'created_at' => time(),
            'members' => [$forumUsername]
        ];
        file_put_contents(CHAT_GROUPS_FILE, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    $group = $groups['1'];
}

// 自动加入群组
if (!in_array($forumUsername, $group['members'])) {
    $group['members'][] = $forumUsername;
    $groups[$groupId] = $group;
    file_put_contents(CHAT_GROUPS_FILE, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
$groupName = htmlspecialchars($group['name'] ?? '群聊', ENT_QUOTES, 'UTF-8');
$chatUsername = $forumUsername;
$_SESSION['chat_username'] = $forumUsername;

$isGroupOwner = ($forumUsername === ($group['creator'] ?? ''));
$isAdminUser = isAdmin() || isFounder();
$isOfficialGroup = ($group['creator'] ?? '') === 'system';
$canClearChat = $isGroupOwner || $isAdminUser;
$canDissolve = $isGroupOwner && !$isOfficialGroup;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $groupName; ?> - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <?php
    if ($currentUserForTheme && isset($currentUserForTheme['theme']) && $currentUserForTheme['theme'] === 'custom' && !empty($currentUserForTheme['theme_settings'])) {
        $settings = $currentUserForTheme['theme_settings'];
        $primary = htmlspecialchars($settings['primary'] ?? '#2196F3', ENT_QUOTES, 'UTF-8');
        list($r, $g, $b) = sscanf($primary, "#%02x%02x%02x");
        $r = max(0, $r - 20); $g = max(0, $g - 20); $b = max(0, $b - 20);
        $to = sprintf("#%02x%02x%02x", $r, $g, $b);
        echo "<style data-page-style>:root{--accent-color:$primary;--accent-gradient-from:$primary;--accent-gradient-to:$to;}</style>";
    }
    ?>
    <style data-page-style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; margin: 0 !important; padding: 0 !important; overflow: hidden; }
        body { background: var(--bg-secondary); color: var(--text-primary); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .gc-container { display: flex; flex-direction: column; height: 100vh; height: 100dvh; margin: 0 auto; background: var(--bg-primary); }
        .gc-header { background: var(--accent-gradient-from); color: white; padding: 0 1rem; display: flex; align-items: center; justify-content: space-between; height: 56px; flex-shrink: 0; position: relative; z-index: 100; }
        .gc-header-left, .gc-header-right { width: 44px; display: flex; align-items: center; justify-content: center; }
        .gc-back-btn { background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: background 0.2s; text-decoration: none; }
        .gc-back-btn:hover { background: rgba(255,255,255,0.15); }
        .gc-share-btn { background: none; border: none; color: white; cursor: pointer; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: background 0.2s; }
        .gc-share-btn:hover { background: rgba(255,255,255,0.15); }
        .gc-header-title { flex: 1; text-align: center; font-size: 1.1rem; font-weight: 600; }
        .gc-menu-btn { background: none; border: none; color: white; cursor: pointer; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: background 0.2s; }
        .gc-menu-btn:hover { background: rgba(255,255,255,0.15); }
        .gc-menu-dots { display: flex; flex-direction: column; gap: 3px; align-items: center; }
        .gc-menu-dots span { width: 4px; height: 4px; border-radius: 50%; background: white; }
        .gc-dropdown { position: absolute; top: 56px; right: 8px; background: var(--bg-primary); box-shadow: 0 4px 20px rgba(0,0,0,0.2); min-width: 150px; z-index: 200; display: none; overflow: hidden; border: 1px solid var(--border-color); }
        .gc-dropdown.active { display: block; }
        .gc-dropdown-item { padding: 0.85rem 1.2rem; color: var(--text-primary); cursor: pointer; font-size: 0.9rem; border-bottom: 1px solid var(--border-color); transition: background 0.2s; display: flex; align-items: center; gap: 0.5rem; }
        .gc-dropdown-item:last-child { border-bottom: none; }
        .gc-dropdown-item:hover { background: var(--link-hover-bg); }
        .gc-dropdown-danger { color: #ef4444; }
        .gc-dropdown-danger svg { stroke: #ef4444; }
        .gc-dropdown-danger:hover { background: rgba(239,68,68,0.1); }
        .gc-dnd-row { display: flex; align-items: center; justify-content: space-between; padding: 0.6rem 1.2rem; background: var(--bg-primary); border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
        .gc-dnd-label { font-size: 0.9rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem; }
        .gc-dnd-toggle { position: relative; width: 48px; height: 26px; cursor: pointer; }
        .gc-dnd-toggle input { display: none; }
        .gc-dnd-slider { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: var(--border-color); border-radius: 26px; transition: 0.3s; }
        .gc-dnd-slider::before { content: ''; position: absolute; left: 3px; bottom: 3px; width: 20px; height: 20px; background: white; border-radius: 50%; transition: 0.3s; }
        .gc-dnd-toggle input:checked + .gc-dnd-slider { background: var(--accent-color); }
        .gc-dnd-toggle input:checked + .gc-dnd-slider::before { transform: translateX(22px); }
        .gc-chat-area { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .gc-messages { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem; background: var(--bg-secondary); }
        .gc-input-row { display: flex; gap: 0.5rem; align-items: flex-end; padding: 0.75rem 1rem; background: var(--bg-primary); border-top: 1px solid var(--border-color); flex-shrink: 0; }
        .gc-input { flex: 1; padding: 10px 12px; border: none; background: var(--bg-secondary); color: var(--text-primary); font-size: 0.95rem; resize: none; max-height: 100px; min-height: 42px; line-height: 1.4; font-family: inherit; outline: none; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .gc-input:focus { box-shadow: 0 1px 6px rgba(0,0,0,0.15); }
        .gc-send-btn { background: var(--accent-gradient-from); color: white; border: none; border-radius: 50%; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; transition: all 0.2s; }
        .gc-send-btn:hover:not(:disabled) { transform: scale(1.05); }
        .gc-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .gc-msg { max-width: 75%; word-break: break-word; user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; touch-action: manipulation; }
        .gc-msg.own { align-self: flex-end; }
        .gc-msg-content { padding: 0.65rem 0.9rem; font-size: 0.9rem; line-height: 1.4; position: relative; }
        .gc-msg.own .gc-msg-content { background: var(--accent-gradient-from); color: white; border-bottom-right-radius: 4px; }
        .gc-msg.other .gc-msg-content { background: var(--bg-primary); color: var(--text-primary); border: 1px solid var(--border-color); border-bottom-left-radius: 4px; }
        .gc-msg-user { font-weight: 500; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.2rem; }
        .gc-msg-time { font-size: 0.7rem; color: #999; margin-top: 0.2rem; text-align: right; }
        .gc-msg.own .gc-msg-time { color: rgba(255,255,255,0.7); }
        .gc-msg.own .gc-msg-user { text-align: right; }
        .gc-reply-bubble { margin-bottom: 0.5rem; padding: 0.45rem 0.6rem; font-size: 0.78rem; border-radius: 6px; border-left: 3px solid var(--accent-color); background: rgba(128,128,128,0.08); line-height: 1.35; }
        .gc-msg.own .gc-reply-bubble { border-left-color: rgba(255,255,255,0.5); background: rgba(255,255,255,0.1); }
        .gc-reply-bubble-name { font-weight: 600; color: var(--accent-color); margin-bottom: 0.15rem; }
        .gc-msg.own .gc-reply-bubble-name { color: rgba(255,255,255,0.85); }
        .gc-reply-bubble-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: 0.7; }
        .gc-msg-img { max-width: 100%; max-height: 250px; cursor: pointer; margin-top: 0.3rem; border: 1px solid var(--border-color); }
        .gc-msg-deleted { opacity: 0.5; font-style: italic; }
        .gc-msg-deleted .gc-msg-content { background: var(--bg-secondary) !important; color: var(--text-secondary) !important; border: 1px dashed var(--border-color) !important; }
        .gc-reply-bar { display: none; background: var(--bg-secondary); border-left: 3px solid var(--accent-color); padding: 0.5rem 0.75rem; margin: 0 1rem; border-bottom: 1px solid var(--border-color); align-items: center; justify-content: space-between; }
        .gc-reply-bar.active { display: flex; }
        .gc-reply-user { font-weight: 600; font-size: 0.8rem; color: var(--accent-color); }
        .gc-reply-text { font-size: 0.8rem; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 240px; }
        .gc-cancel-reply { background: none; border: none; color: var(--text-secondary); font-size: 1.2rem; cursor: pointer; padding: 0.25rem; }
        .gc-loading { text-align: center; padding: 2rem; color: var(--text-secondary); }
        .gc-empty { text-align: center; padding: 3rem; color: var(--text-secondary); }
        .gc-context-menu { position: fixed; background: var(--bg-primary); box-shadow: 0 4px 20px rgba(0,0,0,0.3); padding: 0.3rem 0; min-width: 110px; z-index: 2000; display: none; border: 1px solid var(--border-color); }
        .gc-context-item { padding: 0.7rem 1.2rem; color: var(--text-primary); cursor: pointer; font-size: 0.85rem; transition: background 0.2s; white-space: nowrap; }
        .gc-context-item:hover { background: var(--link-hover-bg); }
        .gc-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
        .gc-modal.active { display: flex; }
        .gc-modal-box { background: var(--bg-primary); padding: 1.5rem; max-width: 360px; width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.3); border: 1px solid var(--border-color); }
        .gc-modal-box h3 { color: var(--text-primary); margin-bottom: 1rem; }
        .gc-modal-input { width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); font-size: 0.95rem; margin-bottom: 1rem; outline: none; }
        .gc-modal-input:focus { border-color: var(--accent-color); }
        .gc-modal-actions { display: flex; gap: 0.75rem; justify-content: flex-end; }
        .gc-btn { padding: 0.5rem 1.2rem; border: none; font-size: 0.9rem; cursor: pointer; transition: opacity 0.2s; }
        .gc-btn-primary { background: var(--accent-gradient-from); color: white; }
        .gc-btn-secondary { background: var(--bg-secondary); color: var(--text-primary); border: 1px solid var(--border-color); }
        .gc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .gc-img-btn { background: none; border: none; color: var(--text-secondary); cursor: pointer; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: all 0.2s; flex-shrink: 0; }
        .gc-img-btn:hover { background: var(--bg-secondary); color: var(--accent-color); }
        .gc-img-preview-area { display: none; padding: 0.5rem 1rem 0; background: var(--bg-primary); }
        .gc-img-preview-area.active { display: flex; }
        .gc-img-preview { position: relative; width: 56px; height: 56px; border-radius: 4px; overflow: hidden; border: 1px solid var(--border-color); }
        .gc-img-preview img { width: 100%; height: 100%; object-fit: cover; }
        .gc-img-preview-close { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; background: #e74c3c; color: white; border: none; border-radius: 50%; font-size: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; z-index: 2; }
        .gc-msg-img-text { width: 120px; height: 120px; object-fit: cover; cursor: pointer; border-radius: 4px; margin-bottom: 0.5rem; border: 1px solid rgba(255,255,255,0.15); display: block; }
        .gc-msg.own .gc-msg-img-text { border-color: rgba(255,255,255,0.3); }
        .gc-img-viewer { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 3000; align-items: center; justify-content: center; }
        .gc-img-viewer.active { display: flex; }
        .gc-img-viewer img { max-width: 95%; max-height: 95%; object-fit: contain; transition: transform 0.2s; }
        .gc-img-viewer-close { position: absolute; top: 1rem; right: 1rem; width: 40px; height: 40px; background: rgba(255,255,255,0.2); color: white; border: none; border-radius: 50%; font-size: 1.5rem; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .gc-upload-progress { display: none; width: 100%; height: 4px; background: var(--border-color); overflow: hidden; }
        .gc-upload-progress.active { display: block; }
        .gc-upload-progress-bar { height: 100%; background: var(--accent-gradient-from); width: 0%; transition: width 0.15s; }
        @media (max-width: 480px) {
            .gc-header { height: 48px; padding: 0 0.75rem; }
            .gc-header-title { font-size: 1rem; }
            .gc-input-row { padding: 0.5rem 0.75rem; }
            .gc-input { font-size: 0.9rem; min-height: 38px; }
            .gc-send-btn { width: 38px; height: 38px; }
            .gc-msg { max-width: 85%; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <div id="page-content">
    <div class="gc-container">
        <div class="gc-header">
            <div class="gc-header-left">
                <a href="#" data-nav-url="<?php echo url('group_list'); ?>" data-tab="notifications" class="gc-back-btn">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                </a>
            </div>
            <div class="gc-header-title"><?php echo $groupName; ?></div>
            <div class="gc-header-right" style="position:relative;">
                <button class="gc-menu-btn" id="menuBtn" title="更多">
                    <div class="gc-menu-dots"><span></span><span></span><span></span></div>
                </button>
                <div class="gc-dropdown" id="gcDropdown">
                    <div class="gc-dropdown-item" onclick="shareGroup()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                        分享
                    </div>
                    <?php if ($canClearChat): ?>
                    <div class="gc-dropdown-item" onclick="clearMessages()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        清空聊天记录
                    </div>
                    <?php endif; ?>
                    <?php if ($canDissolve): ?>
                    <div class="gc-dropdown-item gc-dropdown-danger" onclick="dissolveGroup()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        解散群聊
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="gc-reply-bar" id="replyBar">
            <div><div class="gc-reply-user" id="replyUser"></div><div class="gc-reply-text" id="replyText"></div></div>
            <button class="gc-cancel-reply" onclick="cancelReply()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="gc-chat-area">
            <div class="gc-messages" id="messagesArea"><div class="gc-loading" id="loadingMsg">加载消息中...</div></div>
            <div class="gc-img-preview-area" id="imgPreviewArea">
                <div class="gc-img-preview" id="imgPreview">
                    <img id="imgPreviewThumb" src="">
                    <button class="gc-img-preview-close" onclick="clearImagePreview()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:16px;height:16px;vertical-align:-2px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                </div>
            </div>
            <div class="gc-upload-progress" id="uploadProgress"><div class="gc-upload-progress-bar" id="uploadProgressBar"></div></div>
            <div class="gc-input-row">
                <button class="gc-img-btn" id="imgBtn" title="上传图片">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </button>
                <textarea class="gc-input" id="msgInput" placeholder="输入消息..." rows="1"></textarea>
                <button class="gc-send-btn" id="sendBtn" onclick="sendMessage()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </div>
        </div>
    </div>
    <!-- 图片查看器 -->
    <div class="gc-img-viewer" id="imgViewer" onclick="closeImageViewer()">
        <button class="gc-img-viewer-close" onclick="closeImageViewer()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:16px;height:16px;vertical-align:-2px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        <img id="imgViewerImg" src="" onclick="event.stopPropagation()">
    </div>
    <input type="file" id="imageInput" accept="image/*" style="display:none;">
    <script>
        var currentUser = <?php echo json_encode($chatUsername, JSON_UNESCAPED_UNICODE); ?>;
        var currentUserId = <?php echo (int)$_SESSION['user_id']; ?>;
        var isAdminUser = <?php echo isAdmin() ? 'true' : 'false'; ?>;
        var groupId = '<?php echo $groupId; ?>';
        var lastHash = '', autoScroll = true, canSend = true, replyingTo = null, longPressTimer = null;
        var pendingImageUrl = '';

        // ===== 三点菜单 =====
        var menuBtn = document.getElementById('menuBtn');
        var dropdown = document.getElementById('gcDropdown');
        menuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.classList.toggle('active');
        });
        document.addEventListener('click', (e) => {
            if (!dropdown.contains(e.target) && e.target !== menuBtn) {
                dropdown.classList.remove('active');
            }
        });

        function shareGroup() {
            dropdown.classList.remove('active');
            var shareUrl = '<?php echo url('group_chat'); ?>?group_id=' + groupId;
            var shareText = '【<?php echo addslashes($groupName); ?>】\n群组ID: ' + groupId + '\n' + shareUrl;
            if (navigator.share) {
                navigator.share({ title: '<?php echo addslashes($groupName); ?>', text: shareText, url: shareUrl }).catch(() => {});
            } else if (navigator.clipboard) {
                navigator.clipboard.writeText(shareText).then(() => alert('已复制分享信息到剪贴板')).catch(() => alert('群组ID: ' + groupId + '\n让好友在群聊列表点击「加入群聊」输入此ID'));
            } else {
                alert('群组ID: ' + groupId + '\n让好友在群聊列表点击「加入群聊」输入此ID');
            }
        }

        function clearMessages() {
            dropdown.classList.remove('active');
            if (!confirm('确定清空所有聊天记录？此操作不可恢复！')) return;
            var formData = new FormData();
            formData.append('action', 'clear_messages');
            formData.append('group_id', groupId);
            fetch('', { method: 'POST', body: new URLSearchParams(formData) })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') {
                        document.getElementById('messagesArea').innerHTML = '<div class="gc-empty">消息已清空</div>';
                        lastHash = '';
                        alert('聊天记录已清空');
                    } else {
                        alert(res.message || '操作失败');
                    }
                })
                .catch(() => alert('网络错误'));
        }

        function dissolveGroup() {
            dropdown.classList.remove('active');
            if (!confirm('确定解散此群聊？所有成员将被移除，此操作不可恢复！')) return;
            var formData = new FormData();
            formData.append('action', 'dissolve_group');
            formData.append('group_id', groupId);
            fetch('', { method: 'POST', body: new URLSearchParams(formData) })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') {
                        alert('群聊已解散');
                        if (res.redirect) navigateTo(res.redirect, 'notifications');
                    } else {
                        alert(res.message || '操作失败');
                    }
                })
                .catch(() => alert('网络错误'));
        }

        // ===== 图片上传 =====
        var imgInput = document.getElementById('imageInput');
        document.getElementById('imgBtn').addEventListener('click', () => imgInput.click());
        imgInput.addEventListener('change', async () => {
            var file = imgInput.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) { alert('图片不能超过5MB'); imgInput.value = ''; return; }
            if (!file.type.startsWith('image/')) { alert('请选择图片文件'); imgInput.value = ''; return; }
            await doUploadImage(file);
            imgInput.value = '';
        });

        async function doUploadImage(file) {
            var progressBar = document.getElementById('uploadProgressBar');
            var progressEl = document.getElementById('uploadProgress');
            return new Promise((resolve, reject) => {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '', true);
                var fd = new FormData(); fd.append('image', file); fd.append('ajax', 'upload_image'); fd.append('group_id', groupId);
                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) {
                        var pct = Math.round(e.loaded / e.total * 100);
                        progressEl.classList.add('active');
                        progressBar.style.width = pct + '%';
                    }
                };
                xhr.onload = function() {
                    progressEl.classList.remove('active');
                    progressBar.style.width = '0%';
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            if (data.success) {
                                pendingImageUrl = data.url;
                                document.getElementById('imgPreviewThumb').src = data.url;
                                document.getElementById('imgPreviewArea').classList.add('active');
                                resolve(data);
                            } else { alert('上传失败: ' + (data.message || '未知错误')); reject(new Error(data.message)); }
                        } catch (e) { alert('服务器响应异常'); reject(e); }
                    } else { alert('上传失败: HTTP ' + xhr.status); reject(new Error('HTTP ' + xhr.status)); }
                };
                xhr.onerror = function() { progressEl.classList.remove('active'); progressBar.style.width = '0%'; alert('网络错误'); reject(new Error('Network error')); };
                xhr.send(fd);
            });
        }
        function clearImagePreview() {
            pendingImageUrl = '';
            document.getElementById('imgPreviewThumb').src = '';
            document.getElementById('imgPreviewArea').classList.remove('active');
        }

        // ===== 图片查看器 =====
        function openImageViewer(url) {
            var v = document.getElementById('imgViewer'), img = document.getElementById('imgViewerImg');
            img.src = url; v.classList.add('active');
            img.style.transform = 'scale(1)';
            // 滚轮缩放
            img.onwheel = function(e) { e.preventDefault(); var s = parseFloat(img.style.transform.replace('scale(', '').replace(')', '') || 1); var ns = Math.min(3, Math.max(0.5, s - e.deltaY * 0.001)); img.style.transform = 'scale(' + ns + ')'; };
        }
        function closeImageViewer() { document.getElementById('imgViewer').classList.remove('active'); }

        // ===== 消息 =====
        function hashMessages(msgs) { return !msgs || !msgs.length ? '' : msgs.map(m => m.id + '|' + (m.deleted ? '1' : '0')).join(','); }
        async function fetchMessages(force) {
            try {
                var fd = new FormData(); fd.append('action', 'get_messages'); fd.append('group_id', groupId);
                var res = await fetch('', { method: 'POST', body: fd }); var data = await res.json();
                if (data.status === 'success') {
                    var h = hashMessages(data.messages);
                    if (force || h !== lastHash) { renderMessages(data.messages); lastHash = h; if (autoScroll) setTimeout(() => { var a = document.getElementById('messagesArea'); a.scrollTop = a.scrollHeight; }, 50); }
                }
            } catch (e) { console.error('fetchMessages error:', e); }
        }
        function createMsgEl(msg) {
            var isOwn = msg.username === currentUser, isDeleted = msg.deleted || false;
            var div = document.createElement('div');
            div.className = 'gc-msg ' + (isOwn ? 'own' : 'other') + (isDeleted ? ' gc-msg-deleted' : '');
            div.dataset.msgId = msg.id || ''; div.dataset.msgUser = msg.username; div.dataset.msgTime = msg.time;
            if (msg.tempId) div.dataset.tempId = msg.tempId;
            var time = new Date(msg.time * 1000).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
            var body = '';
            if (isDeleted) {
                body = '<i>此消息已被撤回</i>';
            } else if (msg.type === 'image') {
                body = '<img src="' + msg.file_url + '" class="gc-msg-img" loading="lazy" onclick="event.stopPropagation();openImageViewer(\'' + msg.file_url + '\')">';
            } else if (msg.type === 'image_text') {
                body = '<img src="' + msg.file_url + '" class="gc-msg-img-text" loading="lazy" onclick="event.stopPropagation();openImageViewer(\'' + msg.file_url + '\')"><div>' + msg.content + '</div>';
            } else {
                body = msg.content || '';
            }
            // reply bubble
            var replyHtml = '';
            if (msg.reply_to && !isDeleted) {
                replyHtml = '<div class="gc-reply-bubble"><div class="gc-reply-bubble-name">' + (msg.reply_to_username || '回复') + '</div><div class="gc-reply-bubble-text">' + (msg.reply_to_content || '引用消息') + '</div></div>';
            }
            div.innerHTML = '<div class="gc-msg-user">' + msg.username + '</div><div class="gc-msg-content">' + replyHtml + body + '<div class="gc-msg-time">' + time + '</div></div>';
            div.addEventListener('touchstart', e => startLongPress(div, e)); div.addEventListener('touchend', cancelLongPress); div.addEventListener('touchmove', cancelLongPress);
            div.addEventListener('mousedown', e => { if (e.button === 0) startLongPress(div, e); }); div.addEventListener('mouseup', cancelLongPress); div.addEventListener('mouseleave', cancelLongPress);
            div.addEventListener('contextmenu', e => e.preventDefault());
            return div;
        }
        function renderMessages(msgs) {
            var area = document.getElementById('messagesArea'); document.getElementById('loadingMsg').style.display = 'none';
            if (!msgs || !msgs.length) { area.innerHTML = '<div class="gc-empty">暂无消息，开始聊天吧！</div>'; return; }
            area.innerHTML = ''; msgs.forEach(m => area.appendChild(createMsgEl(m)));
            if (autoScroll) setTimeout(() => { area.scrollTop = area.scrollHeight; }, 50);
        }
        async function sendMessage() {
            if (!canSend) return;
            var input = document.getElementById('msgInput'), btn = document.getElementById('sendBtn'), msg = input.value.trim();
            var hasImage = !!pendingImageUrl;
            if (!msg && !hasImage) return;
            canSend = false; btn.disabled = true;
            var tempId = 'temp_' + Date.now();
            var tempMsg = { id: tempId, tempId: tempId, username: currentUser, user_id: currentUserId, avatar_text: '', content: msg || '[图片]', type: hasImage ? 'image_text' : 'text', time: Math.floor(Date.now() / 1000), reply_to: replyingTo, deleted: false, file_url: pendingImageUrl };
            var area = document.getElementById('messagesArea'); area.appendChild(createMsgEl(tempMsg));
            if (autoScroll) area.scrollTop = area.scrollHeight;
            var imgUrl = pendingImageUrl;
            var replyId = replyingTo;
            input.value = ''; input.style.height = 'auto';
            clearImagePreview(); cancelReply();
            try {
                var fd = new FormData(); fd.append('action', 'send_message'); fd.append('group_id', groupId); fd.append('message', msg);
                if (imgUrl) fd.append('file_url', imgUrl);
                if (replyId) fd.append('reply_to', replyId);
                var res = await fetch('', { method: 'POST', body: fd });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                var data = await res.json();
                if (data.status === 'success') {
                    // directly patch reply bubble if we have resolved content
                    if (data.reply_to_content && replyId) {
                        var tempEl = document.querySelector('[data-temp-id="' + tempId + '"]');
                        if (tempEl) {
                            var contentEl = tempEl.querySelector('.gc-msg-content');
                            if (contentEl) {
                                var bubble = contentEl.querySelector('.gc-reply-bubble');
                                if (bubble) {
                                    bubble.querySelector('.gc-reply-bubble-name').textContent = data.reply_to_username || '回复';
                                    bubble.querySelector('.gc-reply-bubble-text').textContent = data.reply_to_content;
                                }
                            }
                        }
                    }
                    await fetchMessages(true);
                } else { document.querySelector('[data-temp-id="' + tempId + '"]')?.remove(); alert('发送失败: ' + data.message); }
            } catch (e) { document.querySelector('[data-temp-id="' + tempId + '"]')?.remove(); alert('发送失败: ' + (e.message || '网络错误，请重试')); }
            finally { setTimeout(() => { canSend = true; btn.disabled = false; }, 800); }
        }
        function replyToMessage(id) {
            var el = document.querySelector('[data-msg-id="' + id + '"]'); if (!el || el.classList.contains('gc-msg-deleted')) return;
            var user = el.querySelector('.gc-msg-user').textContent, contentEl = el.querySelector('.gc-msg-content');
            var text = contentEl.textContent.replace(/\d{2}:\d{2}$/, '').trim(); if (text.length > 40) text = text.substring(0, 40) + '...';
            replyingTo = id; document.getElementById('replyUser').textContent = '回复 ' + user; document.getElementById('replyText').textContent = text;
            document.getElementById('replyBar').classList.add('active'); document.getElementById('msgInput').focus();
        }
        function cancelReply() { replyingTo = null; document.getElementById('replyBar').classList.remove('active'); }
        async function deleteMessage(id) {
            try {
                var fd = new FormData(); fd.append('action', 'delete_message'); fd.append('group_id', groupId); fd.append('message_id', id);
                var res = await fetch('', { method: 'POST', body: fd }); var data = await res.json();
                if (data.status === 'success') {
                    // 立即在 UI 中标记为已撤回
                    var el = document.querySelector('[data-msg-id="' + id + '"]');
                    if (el) { el.classList.add('gc-msg-deleted'); el.querySelector('.gc-msg-content').innerHTML = '<i>此消息已被撤回</i><div style="margin-top:0.2rem;font-size:0.7rem;color:#999;text-align:right;">' + new Date().toLocaleTimeString('zh-CN', {hour:'2-digit',minute:'2-digit'}) + '</div>'; }
                    // 刷新消息列表
                    await fetchMessages();
                } else alert('撤回失败: ' + (data.message || '未知错误'));
            } catch (e) { alert('网络错误: ' + e.message); }
        }
        function showContextMenu(msgEl, x, y) {
            var menu = document.getElementById('contextMenu'); if (!menu) { menu = document.createElement('div'); menu.id = 'contextMenu'; menu.className = 'gc-context-menu'; document.body.appendChild(menu); }
            menu.style.display = 'none'; menu.innerHTML = '';
            var msgId = msgEl.dataset.msgId, msgUser = msgEl.dataset.msgUser, isOwn = msgUser === currentUser, isDeleted = msgEl.classList.contains('gc-msg-deleted');
            // 回复
            var rItem = document.createElement('div'); rItem.className = 'gc-context-item'; rItem.textContent = ' 回复';
            rItem.onclick = () => { menu.style.display = 'none'; replyToMessage(msgId); }; menu.appendChild(rItem);
            // 撤回
            if (!isDeleted && (isOwn || isAdminUser)) {
                var dItem = document.createElement('div'); dItem.className = 'gc-context-item'; dItem.textContent = ' 撤回';
                dItem.onclick = () => { menu.style.display = 'none'; if (confirm('确定撤回该消息吗？')) deleteMessage(msgId); }; menu.appendChild(dItem);
            }
            if (menu.children.length === 0) return;
            menu.style.display = 'block'; var r = menu.getBoundingClientRect();
            if (x + r.width > window.innerWidth) x = window.innerWidth - r.width - 10;
            if (y + r.height > window.innerHeight) y = window.innerHeight - r.height - 10;
            menu.style.left = Math.max(5, x) + 'px'; menu.style.top = Math.max(5, y) + 'px';
            var closer = (e) => { if (menu.contains(e.target)) return; menu.style.display = 'none'; document.removeEventListener('click', closer); document.removeEventListener('touchend', closer); };
            setTimeout(() => { document.addEventListener('click', closer); document.addEventListener('touchend', closer); }, 200);
        }
        function startLongPress(el, e) { if (longPressTimer) clearTimeout(longPressTimer); var x = e.touches ? e.touches[0].clientX : e.clientX; var y = e.touches ? e.touches[0].clientY : e.clientY; longPressTimer = setTimeout(() => { longPressTimer = null; showContextMenu(el, x, y); }, 500); }
        function cancelLongPress() { if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; } }

        // ===== 初始化 =====
        (async function() {
            await fetchMessages(); document.getElementById('loadingMsg').style.display = 'none';
            // 标记已读
            markAsRead();
            var input = document.getElementById('msgInput');
            input.addEventListener('input', function() { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 100) + 'px'; });
            input.addEventListener('keydown', function(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } });
            var area = document.getElementById('messagesArea');
            area.addEventListener('scroll', function() { var p = area.scrollHeight - area.scrollTop - area.clientHeight; autoScroll = p < 50; });
            window.addEventListener('focus', async () => { await checkForUpdates(); });
            // 离开页面时也标记已读
            window.addEventListener('beforeunload', () => { markAsRead(true); });
            window.addEventListener('pagehide', () => { markAsRead(true); });
            // 智能轮询：轻量 hash 检查，有变化才拉取全量
            setInterval(checkForUpdates, 5000);
        })();

        async function markAsRead(sync) {
            try {
                var fd = new FormData(); fd.append('action', 'mark_read'); fd.append('group_id', groupId);
                var res = await fetch('', { method: 'POST', body: fd, keepalive: !!sync });
            } catch (e) {}
        }

        async function checkForUpdates() {
            try {
                var fd = new FormData(); fd.append('action', 'check_hash'); fd.append('group_id', groupId);
                var res = await fetch('', { method: 'POST', body: fd });
                var data = await res.json();
                if (data.status === 'success' && data.hash !== lastHash) {
                    await fetchMessages();
                }
            } catch (e) { /* 静默忽略 */ }
        }

        // ===== 粘贴上传 =====
        document.addEventListener('paste', async function(e) {
            if (document.activeElement !== document.getElementById('msgInput')) return;
            var items = e.clipboardData?.items; if (!items) return;
            for (var item of items) { if (item.type.startsWith('image/')) { e.preventDefault(); var file = item.getAsFile(); if (file && file.size <= 5 * 1024 * 1024) await doUploadImage(file); else if (file) alert('图片不能超过5MB'); break; } }
        });
    </script>
    </div><!-- /page-content -->
    <?php include 'spa.php'; ?>
</body>
</html>
<?php
function showAuthModalOnly() {
    global $currentUserForTheme;
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>群聊 - 主播模拟器论坛</title>
        <link rel="stylesheet" href="/css/style.css?v=1782016963"><link rel="stylesheet" href="/theme.css"><script src="/theme.js"></script>
    </head>
    <body>
        <div id="page-content">
        <main class="main-content">
            <div style="text-align:center;margin-top:3rem;padding:2rem;background:var(--bg-primary);max-width:400px;margin-left:auto;margin-right:auto;box-shadow:var(--card-shadow);">
                <h2 style="color:var(--accent-color);margin-bottom:1rem;"> 请先登录</h2>
                <p style="color:var(--text-secondary);margin-bottom:1.5rem;">登录后即可进入群聊</p>
                <button class="btn-primary" onclick="showAuthModal(true)">立即登录</button>
                <p style="color:var(--text-secondary);font-size:0.9rem;margin-top:1.5rem;">还没有账号？<a href="javascript:void(0);" onclick="showAuthModal(false)" style="color:var(--accent-color);text-decoration:none;margin-left:0.5rem;">立即注册</a></p>
            </div>
        </main>
        </div><!-- /page-content -->
        <?php include 'auth_modal.php'; ?>
        <?php include 'spa.php'; ?>
    </body></html><?php } ?>