<?php
require_once __DIR__ . '/functions.php';

$currentUserForTheme = getCurrentUser();
checkMaintenanceMode($currentUserForTheme);

if (!isLoggedIn()) {
    showAuthModalOnly();
    exit;
}

// ========== 常量与数据文件 ==========
function showAuthModalOnly() {
    global $currentUserForTheme;
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>私信 - 主播模拟器论坛</title>
        <link rel="stylesheet" href="/css/style.css?v=1782016963"><link rel="stylesheet" href="/theme.css"><script src="/theme.js"></script>
    </head>
    <body>
        <div id="page-content">
        <main class="main-content">
            <div style="text-align:center;margin-top:3rem;padding:2rem;background:var(--bg-primary);max-width:400px;margin-left:auto;margin-right:auto;">
                <h2 style="color:var(--accent-color);margin-bottom:1rem;"> 请先登录</h2>
                <p style="color:var(--text-secondary);margin-bottom:1.5rem;">登录后即可使用私信功能</p>
                <button class="btn-primary" onclick="showAuthModal(true)">立即登录</button>
            </div>
        </main>
        </div><!-- /page-content -->
        <?php include __DIR__ . '/auth_modal.php'; ?>
        <?php include 'spa.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

define('PM_DATA_DIR', __DIR__ . '/data/pm/');
define('PM_CONVERSATIONS_FILE', PM_DATA_DIR . 'conversations.json');
define('PM_READ_STATE_FILE', PM_DATA_DIR . 'read_state.json');
if (!file_exists(PM_DATA_DIR)) mkdir(PM_DATA_DIR, 0755, true);

function pm_getChatUsername() {
    return $_SESSION['chat_username'] ?? $_SESSION['username'] ?? '游客';
}
function pm_isChatLoggedIn() {
    return isset($_SESSION['chat_username']) || isset($_SESSION['user_id']);
}

// ========== 核心函数 ==========
function pm_getConversations() {
    if (!file_exists(PM_CONVERSATIONS_FILE)) return [];
    $content = file_get_contents(PM_CONVERSATIONS_FILE);
    return $content ? (json_decode($content, true) ?: []) : [];
}
function pm_saveConversations($convs) {
    return file_put_contents(PM_CONVERSATIONS_FILE, json_encode($convs, JSON_UNESCAPED_UNICODE));
}
function pm_getConvMessages($convId, $limit = 200) {
    $file = PM_DATA_DIR . "conv_{$convId}.json";
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    if (empty($content)) return [];
    $messages = json_decode($content, true);
    if (!is_array($messages)) $messages = [];
    if (count($messages) > 200) {
        $messages = array_slice($messages, -200);
        file_put_contents($file, json_encode($messages, JSON_UNESCAPED_UNICODE));
    }
    return $messages;
}
function pm_addMessage($convId, $senderId, $senderName, $content, $type = 'text', $fileUrl = '', $replyTo = null) {
    $messages = pm_getConvMessages($convId, 200);
    $avatarText = '';
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT avatar_text, avatar_bg_color FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$senderId]);
        $user = $stmt->fetch();
        $avatarText = $user ? $user['avatar_text'] : '';
        $avatarBg = $user ? ($user['avatar_bg_color'] ?? '') : '';
    } catch (Exception $e) {}
    $message = [
        'id' => uniqid(),
        'sender_id' => $senderId,
        'sender_name' => $senderName,
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
    if (count($messages) > 200) $messages = array_slice($messages, -200);
    $file = PM_DATA_DIR . "conv_{$convId}.json";
    file_put_contents($file, json_encode($messages, JSON_UNESCAPED_UNICODE));
    // 更新 conversation 索引（合并，不覆盖）
    $convs = pm_getConversations();
    if (!isset($convs[$convId])) {
        $convs[$convId] = [
            'user1_id' => 0,
            'user1_name' => '',
            'user2_id' => 0,
            'user2_name' => ''
        ];
    }
    $convs[$convId]['last_time'] = time();
    $convs[$convId]['last_message'] = mb_substr(strip_tags($type === 'text' ? $content : '[图片]'), 0, 50);
    pm_saveConversations($convs);
    return $message;
}
function pm_setMessageDeleted($convId, $messageId, $deleted = true) {
    $file = PM_DATA_DIR . "conv_{$convId}.json";
    if (!file_exists($file)) return false;
    $messages = json_decode(file_get_contents($file), true);
    $found = false;
    foreach ($messages as &$msg) {
        if ($msg['id'] === $messageId) { $msg['deleted'] = $deleted; $found = true; break; }
    }
    unset($msg);
    if ($found) file_put_contents($file, json_encode($messages, JSON_UNESCAPED_UNICODE));
    return $found;
}
function pm_getReadState() {
    if (!file_exists(PM_READ_STATE_FILE)) return [];
    $content = file_get_contents(PM_READ_STATE_FILE);
    return $content ? (json_decode($content, true) ?: []) : [];
}
function pm_saveReadState($state) {
    return file_put_contents(PM_READ_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE));
}
function pm_getUnreadCount($userId) {
    $convs = pm_getConversations();
    $readState = pm_getReadState();
    $total = 0;
    foreach ($convs as $convId => $conv) {
        $key = "{$userId}_{$convId}";
        $lastRead = $readState[$key]['time'] ?? 0;
        $messages = pm_getConvMessages($convId, 200);
        foreach ($messages as $msg) {
            if ($msg['sender_id'] != $userId && $msg['time'] > $lastRead && empty($msg['deleted'])) {
                $total++;
            }
        }
    }
    return $total;
}

// ========== 图片上传 ==========
function pm_handleUpload() {
    if (!isset($_FILES['image'])) return ['error' => '没有文件'];
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['error' => '上传错误: ' . $file['error']];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) return ['error' => '不支持的文件类型'];
    if ($file['size'] > 5 * 1024 * 1024) return ['error' => '图片不能超过5MB'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (!$ext) $ext = 'jpg';
    $filename = uniqid() . '.' . $ext;
    $uploadDir = __DIR__ . '/st/uploads/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
    $dest = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return ['error' => '保存文件失败'];
    return ['success' => true, 'url' => '/st/uploads/' . $filename];
}

function jsonResponse($data) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 处理图片上传 ==========
if (isset($_FILES['image']) && isset($_POST['ajax']) && $_POST['ajax'] === 'pm_upload') {
    $result = pm_handleUpload();
    if (isset($result['error'])) {
        jsonResponse(['status' => 'error', 'message' => $result['error']]);
    } else {
        jsonResponse(['status' => 'success', 'url' => $result['url']]);
    }
}

// ========== AJAX 处理 ==========
if (isset($_POST['action'])) {
    $response = ['status' => 'error', 'message' => '未知操作'];
    try {
        if (!isLoggedIn()) { jsonResponse(['status' => 'error', 'message' => '未登录']); }
        $myId = (int)$_SESSION['user_id'];
        $myName = pm_getChatUsername();

        switch ($_POST['action']) {
            case 'start_conversation':
                $targetId = (int)($_POST['user_id'] ?? 0);
                if (!$targetId || $targetId === $myId) { $response['message'] = '无效用户'; break; }
                // 获取目标用户名
                $targetName = '';
                try {
                    $pdo = getDbConnection();
                    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$targetId]);
                    $u = $stmt->fetch();
                    $targetName = $u ? $u['username'] : '';
                } catch (Exception $e) {}
                if (!$targetName) { $response['message'] = '用户不存在'; break; }
                $a = min($myId, $targetId); $b = max($myId, $targetId);
                $convId = "{$a}_{$b}";
                $convs = pm_getConversations();
                if (!isset($convs[$convId])) {
                    $convs[$convId] = [
                        'user1_id' => $a,
                        'user1_name' => $a == $myId ? $myName : $targetName,
                        'user2_id' => $b,
                        'user2_name' => $b == $myId ? $myName : $targetName,
                        'last_time' => 0,
                        'last_message' => ''
                    ];
                    pm_saveConversations($convs);
                }
                $response = ['status' => 'success', 'conv_id' => $convId];
                break;

            case 'get_conversations':
                $convs = pm_getConversations();
                // 过滤：只返回当前用户参与的
                $myConvs = [];
                foreach ($convs as $convId => $conv) {
                    if ($conv['user1_id'] == $myId || $conv['user2_id'] == $myId) {
                        $otherId = $conv['user1_id'] == $myId ? $conv['user2_id'] : $conv['user1_id'];
                        $otherName = $conv['user1_id'] == $myId ? $conv['user2_name'] : $conv['user1_name'];
                        $conv['conv_id'] = $convId;
                        $conv['other_id'] = $otherId;
                        $conv['other_name'] = $otherName;
                        // 获取对方头像
                        try {
                            $pdo = getDbConnection();
                            $stmt = $pdo->prepare("SELECT avatar, avatar_text, avatar_bg_color FROM users WHERE id = ? LIMIT 1");
                            $stmt->execute([$otherId]);
                            $ou = $stmt->fetch();
                            $conv['other_avatar'] = $ou ? $ou['avatar'] : '';
                            $conv['other_avatar_text'] = $ou ? $ou['avatar_text'] : '';
                        } catch (Exception $e) {
                            $conv['other_avatar'] = '';
                            $conv['other_avatar_text'] = '';
                        }
                        // 未读数
                        $readState = pm_getReadState();
                        $key = "{$myId}_{$convId}";
                        $lastRead = $readState[$key]['time'] ?? 0;
                        $unread = 0;
                        $messages = pm_getConvMessages($convId, 200);
                        foreach ($messages as $msg) {
                            if ($msg['sender_id'] != $myId && $msg['time'] > $lastRead && empty($msg['deleted'])) $unread++;
                        }
                        $conv['unread'] = $unread;
                        $myConvs[] = $conv;
                    }
                }
                // 按最后消息时间排序
                usort($myConvs, function($a, $b) { return $b['last_time'] - $a['last_time']; });
                $response = ['status' => 'success', 'conversations' => $myConvs];
                break;

            case 'get_messages':
                $convId = $_POST['conv_id'] ?? '';
                if (!$convId) { $response['message'] = '缺少会话ID'; break; }
                $convs = pm_getConversations();
                if (!isset($convs[$convId])) { $response['message'] = '会话不存在'; break; }
                $conv = $convs[$convId];
                if ($conv['user1_id'] != $myId && $conv['user2_id'] != $myId) {
                    $response['message'] = '无权访问'; break;
                }
                $messages = pm_getConvMessages($convId, 200);
                // 解析 reply_to
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
                        $msg['reply_to_username'] = $msgById[$msg['reply_to']]['sender_name'];
                    }
                }
                unset($msg);
                $response = ['status' => 'success', 'messages' => $messages, 'current_user' => $myName, 'current_user_id' => $myId, 'timestamp' => time()];
                break;

            case 'send_message':
                $convId = $_POST['conv_id'] ?? '';
                $message = $_POST['message'] ?? '';
                $fileUrl = $_POST['file_url'] ?? '';
                $replyTo = $_POST['reply_to'] ?? null;
                if (!$convId) { $response['message'] = '缺少会话ID'; break; }
                $hasText = !empty(trim($message));
                $hasImage = !empty($fileUrl);
                if (!$hasText && !$hasImage) { $response['message'] = '消息不能为空'; break; }
                $convs = pm_getConversations();
                if (!isset($convs[$convId])) { $response['message'] = '会话不存在'; break; }
                $conv = $convs[$convId];
                if ($conv['user1_id'] != $myId && $conv['user2_id'] != $myId) {
                    $response['message'] = '无权发送'; break;
                }
                $msgType = $hasImage ? ($hasText ? 'image_text' : 'image') : 'text';
                $content = $hasText ? $message : ($hasImage ? '[图片]' : '');
                $newMsg = pm_addMessage($convId, $myId, $myName, $content, $msgType, $fileUrl, $replyTo);
                if ($newMsg) {
                    // resolve reply_to in response
                    $resp = ['status' => 'success', 'message' => '发送成功', 'message_id' => $newMsg['id']];
                    if ($replyTo) {
                        $msgs = pm_getConvMessages($convId, 200);
                        foreach ($msgs as $m) {
                            if ($m['id'] === $replyTo) {
                                $resp['reply_to_content'] = $m['content'];
                                $resp['reply_to_username'] = $m['sender_name'];
                                break;
                            }
                        }
                    }
                    $response = $resp;
                } else {
                    $response['message'] = '发送失败';
                }
                break;

            case 'check_hash':
                $convId = $_POST['conv_id'] ?? '';
                if (!$convId) { $response['message'] = '缺少会话ID'; break; }
                $messages = pm_getConvMessages($convId, 200);
                $hashes = [];
                foreach ($messages as $msg) {
                    $hashes[] = ($msg['id'] ?? '') . '|' . ($msg['deleted'] ? '1' : '0');
                }
                $hash = implode(',', $hashes);
                $response = ['status' => 'success', 'hash' => $hash, 'count' => count($messages)];
                break;

            case 'delete_message':
                $convId = $_POST['conv_id'] ?? '';
                $messageId = $_POST['message_id'] ?? '';
                if (!$convId || !$messageId) { $response['message'] = '参数不足'; break; }
                $messages = pm_getConvMessages($convId, 200);
                $found = false;
                foreach ($messages as $msg) {
                    if ($msg['id'] === $messageId && $msg['sender_id'] == $myId) { $found = true; break; }
                }
                if (!$found) { $response['message'] = '无权撤回或消息不存在'; break; }
                if (pm_setMessageDeleted($convId, $messageId)) {
                    $response = ['status' => 'success'];
                } else {
                    $response['message'] = '撤回失败';
                }
                break;

            case 'mark_read':
                $convId = $_POST['conv_id'] ?? '';
                if (!$convId) { $response['message'] = '缺少会话ID'; break; }
                $readState = pm_getReadState();
                $key = "{$myId}_{$convId}";
                $readState[$key] = ['time' => time(), 'count' => 0];
                pm_saveReadState($readState);
                $response = ['status' => 'success'];
                break;

            case 'mark_all_read':
                $readState = pm_getReadState();
                $convs = pm_getConversations();
                foreach ($convs as $convId => $conv) {
                    if ($conv['user1_id'] == $myId || $conv['user2_id'] == $myId) {
                        $key = "{$myId}_{$convId}";
                        $readState[$key] = ['time' => time(), 'count' => 0];
                    }
                }
                pm_saveReadState($readState);
                $response = ['status' => 'success'];
                break;

            case 'unread_count':
                $count = pm_getUnreadCount($myId);
                $response = ['status' => 'success', 'count' => $count];
                break;
        }
    } catch (Exception $e) {
        $response['message'] = '服务器错误: ' . $e->getMessage();
    }
    jsonResponse($response);
}

// ========== 页面渲染 (GET 请求) ==========
$convId = $_GET['conv_id'] ?? '';
$myId = (int)$_SESSION['user_id'];
$myName = pm_getChatUsername();

// 获取对方信息
$otherUser = null;
if ($convId) {
    $convs = pm_getConversations();
    if (isset($convs[$convId])) {
        $conv = $convs[$convId];
        if ($conv['user1_id'] == $myId || $conv['user2_id'] == $myId) {
            $otherId = $conv['user1_id'] == $myId ? $conv['user2_id'] : $conv['user1_id'];
            $otherName = $conv['user1_id'] == $myId ? $conv['user2_name'] : $conv['user1_name'];
            try {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("SELECT id, username, avatar, avatar_text, avatar_bg_color FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$otherId]);
                $otherUser = $stmt->fetch();
            } catch (Exception $e) {}
        }
    }
}
if (!$convId || !$otherUser) {
    header('Location: /pm_list');
    exit;
}

?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>私信 - <?php echo htmlspecialchars($otherUser['username']); ?></title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <script src="/theme.js"></script>
    <style data-page-style>
        /** PM 专有样式（主题变量由 style.css/theme.css 提供） **/
        :root { --msg-other-bg: #f1f5f9; }
        [data-theme="dark"] { --msg-other-bg: #1e293b; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary); color: var(--text-primary);
            height: 100dvh; width: 100%; overflow: hidden;
            -webkit-tap-highlight-color: transparent;
        }
        /* 标题栏 */
        .pm-header {
            display: flex; align-items: center; padding: 0.75rem 1rem;
            background: var(--accent-color);
            position: fixed; top: 0; left: 0; right: 0; z-index: 10; height: 50px;
        }
        .pm-back {
            background: none; border: none; color: #fff; font-size: 1.5rem;
            cursor: pointer; padding: 0.25rem; line-height: 1; flex-shrink: 0;
            text-decoration: none;
        }
        .pm-title {
            flex: 1; text-align: center; font-weight: 600; font-size: 1.05rem;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: 0 0.5rem;
            color: #fff;
        }
        .pm-header-avatar {
            width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
            margin-right: 0.5rem; flex-shrink: 0;
        }
        .pm-header-avatar-placeholder {
            width: 32px; height: 32px; border-radius: 50%; margin-right: 0.5rem; flex-shrink: 0;
            background: var(--accent-gradient-from)); color: white; display: flex; align-items: center;
            justify-content: center; font-weight: 600; font-size: 0.8rem;
        }
        /* 消息区域 */
        .pm-messages {
            position: absolute; top: 50px; bottom: 56px; left: 0; right: 0;
            overflow-y: auto; padding: 0.75rem; display: flex; flex-direction: column;
            gap: 0.5rem; -webkit-overflow-scrolling: touch;
        }
        .pm-loading { text-align: center; color: var(--text-secondary); padding: 2rem; font-size: 0.9rem; }
        .pm-empty { text-align: center; color: var(--text-secondary); padding: 3rem 1rem; }
        .pm-msg {
            display: flex; flex-direction: column; max-width: 80%; padding: 0.6rem 0.75rem;
            border-radius: 12px; font-size: 0.92rem; line-height: 1.5; position: relative;
            word-break: break-word; user-select: none; -webkit-user-select: none;
            -webkit-touch-callout: none; touch-action: manipulation;
        }
        .pm-msg.own { align-self: flex-end; background: var(--accent-gradient-from); color: white; border-bottom-right-radius: 4px; }
        .pm-msg.other { align-self: flex-start; background: var(--msg-other-bg); color: var(--text-primary); border: 1px solid var(--border-color); border-bottom-left-radius: 4px; }
        .pm-msg-deleted { opacity: 0.4; font-style: italic; }
        .pm-msg-user { font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.15rem; }
        .pm-msg.own .pm-msg-user { color: rgba(255,255,255,0.7); }
        .pm-msg-time { font-size: 0.68rem; opacity: 0.6; text-align: right; margin-top: 0.2rem; }
        .pm-msg.own .pm-msg-time { color: rgba(255,255,255,0.7); }
        .pm-msg-img { max-width: 240px; border-radius: 8px; cursor: pointer; display: block; }
        .pm-msg-img-text { width: 120px; height: 120px; object-fit: cover; border-radius: 8px; margin-bottom: 0.4rem; cursor: pointer; display: block; }
        /* 回复气泡 */
        .pm-reply-bubble {
            margin-bottom: 0.5rem; padding: 0.45rem 0.6rem; font-size: 0.78rem;
            border-radius: 6px; border-left: 3px solid var(--accent-color);
            background: rgba(128,128,128,0.08); line-height: 1.35;
        }
        .pm-msg.own .pm-reply-bubble { border-left-color: rgba(255,255,255,0.5); background: rgba(255,255,255,0.1); }
        .pm-reply-bubble-name { font-weight: 600; color: var(--accent-color); margin-bottom: 0.15rem; }
        .pm-msg.own .pm-reply-bubble-name { color: rgba(255,255,255,0.85); }
        .pm-reply-bubble-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: 0.7; }
        /* 回复栏 */
        .pm-reply-bar {
            display: none; align-items: center; padding: 0.5rem 0.75rem;
            background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
            position: fixed; top: 50px; left: 0; right: 0; z-index: 9;
        }
        .pm-reply-bar.active { display: flex; }
        .pm-messages.reply-active { top: 90px; }
        .pm-reply-bar-content {
            flex: 1; font-size: 0.8rem; color: var(--text-secondary); overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
        }
        .pm-reply-bar-content strong { color: var(--accent-color); }
        .pm-cancel-reply {
            background: none; border: none; color: var(--text-secondary); font-size: 1.2rem;
            cursor: pointer; padding: 0 0.25rem 0 0.5rem;
        }
        /* 输入区域 */
        .pm-input-area {
            display: flex; align-items: flex-end; padding: 0.5rem 0.75rem;
            background: var(--bg-secondary); border-top: 1px solid var(--border-color);
            gap: 0.5rem; position: fixed; bottom: 0; left: 0; right: 0; z-index: 10; height: 56px;
        }
        .pm-input-area textarea {
            flex: 1; border: 1px solid var(--border-color); border-radius: 20px;
            padding: 0.55rem 1rem; background: var(--bg-primary); color: var(--text-primary);
            resize: none; outline: none; font-size: 0.9rem; max-height: 100px; line-height: 1.3;
            font-family: inherit;
        }
        .pm-upload-btn, .pm-send-btn {
            background: none; border: none; cursor: pointer; padding: 0.4rem;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; transition: background 0.2s;
        }
        .pm-upload-btn:hover { background: var(--link-hover-bg); }
        .pm-send-btn { background: var(--accent-gradient-from); color: white; width: 38px; height: 38px; }
        .pm-send-btn:disabled { opacity: 0.5; }
        /* 图片预览 */
        .pm-img-preview {
            display: none; position: relative; width: 60px; height: 60px; flex-shrink: 0;
        }
        .pm-img-preview.active { display: block; }
        .pm-img-preview img { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; }
        .pm-img-preview-close {
            position: absolute; top: -6px; right: -6px; width: 20px; height: 20px;
            background: #e53e3e; color: white; border: none; border-radius: 50%;
            font-size: 0.7rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
        }
        .pm-upload-progress { font-size: 0.7rem; color: var(--text-secondary); }
        /* 图片查看器 */
        .pm-img-viewer {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.92); z-index: 9999;
            align-items: center; justify-content: center; flex-direction: column;
        }
        .pm-img-viewer.active { display: flex; }
        .pm-img-viewer img { max-width: 95vw; max-height: 80vh; object-fit: contain; transition: transform 0.1s; }
        .pm-img-viewer-close {
            position: absolute; top: 1rem; right: 1rem; background: rgba(255,255,255,0.2);
            color: white; border: none; border-radius: 50%; width: 36px; height: 36px;
            font-size: 1.2rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
        }
        /* 上下文菜单 */
        .pm-context-menu {
            display: none; position: fixed; background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.15); z-index: 9998;
            padding: 0.3rem 0; min-width: 100px;
        }
        .pm-context-menu.active { display: block; }
        .pm-context-menu-item {
            padding: 0.5rem 1rem; font-size: 0.85rem; cursor: pointer; color: var(--text-primary);
        }
        .pm-context-menu-item:hover { background: var(--link-hover-bg); }
    </style>
</head>
<body>
    <div id="page-content">
    <!-- 标题栏 -->
    <div class="pm-header">
        <a href="#" data-nav-url="/pm_list" data-tab="notifications" class="pm-back">←</a>
        <?php if ($otherUser['avatar']): ?>
            <img class="pm-header-avatar" src="<?php echo htmlspecialchars($otherUser['avatar']); ?>" alt="">
        <?php else: ?>
            <div class="pm-header-avatar-placeholder"<?php if (!empty($otherUser['avatar_text'])): ?> style="background: <?php echo htmlspecialchars($otherUser['avatar_bg_color'] ?? '#6366f1'); ?>"<?php endif; ?>><?php echo htmlspecialchars(mb_substr($otherUser['avatar_text'] ?: $otherUser['username'], 0, 1)); ?></div>
        <?php endif; ?>
        <div class="pm-title"><?php echo htmlspecialchars($otherUser['username']); ?></div>
        <button class="pm-share-btn" onclick="shareConv()" style="background:none;border:none;cursor:pointer;font-size:1.2rem;padding:0.25rem;"></button>
    </div>

    <!-- 回复栏 -->
    <div class="pm-reply-bar" id="replyBar">
        <div class="pm-reply-bar-content" id="replyContent"><strong id="replyUser"></strong>: <span id="replyText"></span></div>
        <button class="pm-cancel-reply" onclick="cancelReply()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:16px;height:16px;vertical-align:-2px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>

    <!-- 消息区域 -->
    <div class="pm-messages" id="messagesArea">
        <div class="pm-loading" id="loadingMsg">加载消息中...</div>
    </div>

    <!-- 输入区域 -->
    <div class="pm-input-area">
        <div class="pm-img-preview" id="imgPreview">
            <img id="imgPreviewImg" src="" alt="">
            <button class="pm-img-preview-close" onclick="clearImagePreview()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:16px;height:16px;vertical-align:-2px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <button class="pm-upload-btn" onclick="document.getElementById('pmFileInput').click()">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        </button>
        <textarea id="msgInput" rows="1" placeholder="输入消息..." oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,100)+'px'"></textarea>
        <button class="pm-send-btn" id="sendBtn" onclick="sendMessage()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
        <input type="file" id="pmFileInput" accept="image/*" style="display:none" onchange="doUploadImage(this.files[0])">
    </div>
    <div class="pm-upload-progress" id="uploadProgress"></div>
        <!-- 图片查看器 -->
    <div class="pm-img-viewer" id="imgViewer" onclick="closeImageViewer()">
        <button class="pm-img-viewer-close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:16px;height:16px;vertical-align:-2px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        <img id="imgViewerImg" src="" alt="">
    </div>

    <!-- 上下文菜单 -->
    <div class="pm-context-menu" id="contextMenu">
        <div class="pm-context-menu-item" onclick="replyToMessage(contextMsgId)">回复</div>
        <div class="pm-context-menu-item" onclick="deleteMessage(contextMsgId)">撤回</div>
    </div>

<script>
    var currentUser = <?php echo json_encode($myName, JSON_UNESCAPED_UNICODE); ?>,
          currentUserId = <?php echo $myId; ?>,
          convId = <?php echo json_encode($convId); ?>;
    var canSend = true, autoScroll = true, lastHash = '', replyingTo = null, pendingImageUrl = '';
    var contextMsgId = null, longPressTimer = null;

    (async function() {
        await fetchMessages();
        document.getElementById('loadingMsg').style.display = 'none';
        // 标记已读
        var fd = new FormData(); fd.append('action','mark_read'); fd.append('conv_id', convId);
        fetch('', {method:'POST',body:fd}).catch(()=>{});
        // 输入框事件
        var input = document.getElementById('msgInput');
        input.addEventListener('input', function() { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 100) + 'px'; });
        input.addEventListener('keydown', function(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } });
        // 滚动监听
        var area = document.getElementById('messagesArea');
        area.addEventListener('scroll', function() { var p = area.scrollHeight - area.scrollTop - area.clientHeight; autoScroll = p < 50; });
        autoScroll = true; area.scrollTop = area.scrollHeight;
        // 焦点恢复时检查更新
        window.addEventListener('focus', async () => { await checkForUpdates(); });
        // 离开时标记已读
        window.addEventListener('beforeunload', () => {
            var fd = new FormData(); fd.append('action','mark_read'); fd.append('conv_id', convId);
            navigator.sendBeacon && navigator.sendBeacon('', fd);
        });
        window.addEventListener('pagehide', () => {
            var fd = new FormData(); fd.append('action','mark_read'); fd.append('conv_id', convId);
            navigator.sendBeacon && navigator.sendBeacon('', fd);
        });
        // 智能轮询
        setInterval(checkForUpdates, 5000);
    })();

    function hashMessages(msgs) { return !msgs||!msgs.length?'':msgs.map(m=>(m.id||'')+'|'+(m.deleted?'1':'0')).join(','); }

    async function fetchMessages(force) {
        try {
            var fd = new FormData(); fd.append('action','get_messages'); fd.append('conv_id',convId);
            var res = await fetch('',{method:'POST',body:fd}); var data = await res.json();
            if (data.status==='success') {
                var h = hashMessages(data.messages);
                if (force||h!==lastHash) { renderMessages(data.messages); lastHash=h; if(autoScroll) setTimeout(()=>{var a=document.getElementById('messagesArea');a.scrollTop=a.scrollHeight;},50); }
            }
        }catch(e){console.error('fetchMessages error:',e);}
    }

    function createMsgEl(msg) {
        var isOwn = msg.sender_id == currentUserId || msg.sender_name === currentUser;
        var isDeleted = msg.deleted||false;
        var div = document.createElement('div');
        div.className = 'pm-msg '+(isOwn?'own':'other')+(isDeleted?' pm-msg-deleted':'');
        div.dataset.msgId = msg.id||''; div.dataset.msgUser = msg.sender_name; div.dataset.msgTime = msg.time;
        if (msg.tempId) div.dataset.tempId = msg.tempId;
        var time = new Date(msg.time*1000).toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit'});
        var body = '';
        if (isDeleted) { body = '<i>此消息已被撤回</i>'; }
        else if (msg.type==='image') { body = '<img src="'+msg.file_url+'" class="pm-msg-img" loading="lazy" onclick="event.stopPropagation();openImageViewer(\''+msg.file_url+'\')">'; }
        else if (msg.type==='image_text') { body = '<img src="'+msg.file_url+'" class="pm-msg-img-text" loading="lazy" onclick="event.stopPropagation();openImageViewer(\''+msg.file_url+'\')"><div>'+msg.content+'</div>'; }
        else { body = msg.content||''; }
        var replyHtml = '';
        if (msg.reply_to&&!isDeleted) {
            replyHtml = '<div class="pm-reply-bubble"><div class="pm-reply-bubble-name">'+(msg.reply_to_username||'回复')+'</div><div class="pm-reply-bubble-text">'+(msg.reply_to_content||'引用消息')+'</div></div>';
        }
        div.innerHTML = (isOwn?'':'<div class="pm-msg-user">'+msg.sender_name+'</div>')+'<div class="pm-msg-content">'+replyHtml+body+'<div class="pm-msg-time">'+time+'</div></div>';
        div.addEventListener('touchstart',e=>startLongPress(div,e)); div.addEventListener('touchend',cancelLongPress); div.addEventListener('touchmove',cancelLongPress);
        div.addEventListener('mousedown',e=>{if(e.button===0)startLongPress(div,e);}); div.addEventListener('mouseup',cancelLongPress); div.addEventListener('mouseleave',cancelLongPress);
        div.addEventListener('contextmenu',e=>e.preventDefault());
        return div;
    }

    function renderMessages(msgs) {
        var area = document.getElementById('messagesArea'); document.getElementById('loadingMsg').style.display='none';
        if (!msgs||!msgs.length) { area.innerHTML='<div class="pm-empty">暂无消息，发送第一条私信吧！</div>'; return; }
        area.innerHTML=''; msgs.forEach(m=>area.appendChild(createMsgEl(m)));
        if(autoScroll) setTimeout(()=>{area.scrollTop=area.scrollHeight;},50);
    }

    async function sendMessage() {
        if(!canSend)return;
        var input=document.getElementById('msgInput'),btn=document.getElementById('sendBtn'),msg=input.value.trim();
        var hasImage=!!pendingImageUrl;
        if(!msg&&!hasImage)return;
        canSend=false;btn.disabled=true;
        var tempId='temp_'+Date.now();
        var tempMsg={id:tempId,tempId:tempId,sender_id:currentUserId,sender_name:currentUser,content:msg||'[图片]',type:hasImage?'image_text':'text',time:Math.floor(Date.now()/1000),reply_to:replyingTo,deleted:false,file_url:pendingImageUrl};
        var area=document.getElementById('messagesArea');area.appendChild(createMsgEl(tempMsg));
        if(autoScroll)area.scrollTop=area.scrollHeight;
        var imgUrl=pendingImageUrl;
        var replyId=replyingTo;
        input.value='';input.style.height='auto';
        clearImagePreview();cancelReply();
        try{
            var fd=new FormData();fd.append('action','send_message');fd.append('conv_id',convId);fd.append('message',msg);
            if(imgUrl)fd.append('file_url',imgUrl);
            if(replyId)fd.append('reply_to',replyId);
            var res=await fetch('',{method:'POST',body:fd});
            if(!res.ok)throw new Error('HTTP '+res.status);
            var data=await res.json();
            if(data.status==='success'){
                if(data.reply_to_content&&replyId){
                    var tempEl=document.querySelector('[data-temp-id="'+tempId+'"]');
                    if(tempEl){
                        var bubble=tempEl.querySelector('.pm-reply-bubble');
                        if(bubble){
                            bubble.querySelector('.pm-reply-bubble-name').textContent=data.reply_to_username||'回复';
                            bubble.querySelector('.pm-reply-bubble-text').textContent=data.reply_to_content;
                        }
                    }
                }
                await fetchMessages(true);
            }else{document.querySelector('[data-temp-id="'+tempId+'"]')?.remove();alert('发送失败: '+data.message);}
        }catch(e){document.querySelector('[data-temp-id="'+tempId+'"]')?.remove();alert('发送失败: '+(e.message||'网络错误'));}
        finally{setTimeout(()=>{canSend=true;btn.disabled=false;},800);}
    }

    function replyToMessage(id){
        var menu = document.getElementById('contextMenu');
        menu.classList.remove('active'); menu.style.display = 'none';
        var el=document.querySelector('[data-msg-id="'+id+'"]');if(!el||el.classList.contains('pm-msg-deleted'))return;
        var user=el.dataset.msgUser;
        var text=el.querySelector('.pm-msg-content').textContent.replace(/\d{2}:\d{2}$/,'').trim();if(text.length>40)text=text.substring(0,40)+'...';
        replyingTo=id;document.getElementById('replyUser').textContent='回复 '+user;document.getElementById('replyText').textContent=text;
        document.getElementById('replyBar').classList.add('active');
        document.getElementById('messagesArea').classList.add('reply-active');
        document.getElementById('msgInput').focus();
    }
    function cancelReply(){
        replyingTo=null;document.getElementById('replyBar').classList.remove('active');
        document.getElementById('messagesArea').classList.remove('reply-active');
    }
    async function deleteMessage(id){
        try{
            var fd=new FormData();fd.append('action','delete_message');fd.append('conv_id',convId);fd.append('message_id',id);
            var res=await fetch('',{method:'POST',body:fd});var data=await res.json();
            if(data.status==='success'){
                // 立即在 UI 中标记为已撤回
                var el=document.querySelector('[data-msg-id="'+id+'"]');
                if(el){el.classList.add('pm-msg-deleted');el.querySelector('.pm-msg-content').innerHTML='<i>此消息已被撤回</i><div style="margin-top:0.2rem;font-size:0.7rem;color:#999;text-align:right;">'+new Date().toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit'})+'</div>';}
                // 关闭菜单、刷新消息列表
                var menu = document.getElementById('contextMenu');
                menu.classList.remove('active'); menu.style.display = 'none';
                await fetchMessages();
            }else alert('撤回失败: '+(data.message||'未知错误'));
        }catch(e){alert('网络错误: '+e.message);}
    }

    // 图片上传
    function clearImagePreview(){pendingImageUrl='';document.getElementById('imgPreview').classList.remove('active');document.getElementById('uploadProgress').textContent='';}
    async function doUploadImage(file){
        if(!file)return;
        if(file.size>5*1024*1024){alert('图片不能超过5MB');return;}
        var fd=new FormData();fd.append('image',file);fd.append('ajax','pm_upload');
        var xhr=new XMLHttpRequest();
        xhr.open('POST','',true);
        xhr.upload.onprogress=function(e){if(e.lengthComputable){var pct=Math.round(e.loaded/e.total*100);document.getElementById('uploadProgress').textContent='上传中 '+pct+'%';}};
        xhr.onload=function(){
            if(xhr.status===200){
                try{
                    var data=JSON.parse(xhr.responseText);
                    if(data.status==='success'){pendingImageUrl=data.url;document.getElementById('imgPreviewImg').src=data.url;document.getElementById('imgPreview').classList.add('active');document.getElementById('uploadProgress').textContent='';}
                    else{alert('上传失败: '+data.message);}
                }catch(e){alert('上传失败');}
            }else{alert('上传失败');}
        };
        xhr.onerror=function(){alert('上传失败');};
        xhr.send(fd);
    }

    // 图片查看器
    function openImageViewer(url){document.getElementById('imgViewerImg').src=url;document.getElementById('imgViewer').classList.add('active');}
    function closeImageViewer(){document.getElementById('imgViewer').classList.remove('active');}

    // 长按菜单
    function startLongPress(el, e) {
        if (longPressTimer) clearTimeout(longPressTimer);
        var x = e.touches ? e.touches[0].clientX : e.clientX;
        var y = e.touches ? e.touches[0].clientY : e.clientY;
        longPressTimer = setTimeout(() => { longPressTimer = null; showContextMenu(el, x, y); }, 500);
    }
    function cancelLongPress() { if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; } }
    function showContextMenu(msgEl, x, y) {
        var msgId = msgEl.dataset.msgId, isDeleted = msgEl.classList.contains('pm-msg-deleted');
        if (!msgId || isDeleted) return;
        contextMsgId = msgId;
        var menu = document.getElementById('contextMenu');
        menu.style.display = 'none';
        // 回复永远显示
        menu.querySelector('.pm-context-menu-item:first-child').style.display = 'block';
        // 只有自己的消息才能撤回
        var isOwn = msgEl.classList.contains('own');
        menu.querySelector('.pm-context-menu-item:last-child').style.display = isOwn ? 'block' : 'none';
        menu.style.display = 'block';
        menu.classList.add('active');
        var r = menu.getBoundingClientRect();
        if (x + r.width > window.innerWidth) x = window.innerWidth - r.width - 10;
        if (y + r.height > window.innerHeight) y = window.innerHeight - r.height - 10;
        menu.style.left = Math.max(5, x) + 'px';
        menu.style.top = Math.max(5, y) + 'px';
        var closer = (e) => {
            if (menu.contains(e.target)) return;
            menu.classList.remove('active');
            menu.style.display = 'none';
            document.removeEventListener('click', closer);
            document.removeEventListener('touchend', closer);
        };
        setTimeout(() => {
            document.addEventListener('click', closer);
            document.addEventListener('touchend', closer);
        }, 200);
    }

    // 轮询
    async function checkForUpdates(){
        try{
            var fd=new FormData();fd.append('action','check_hash');fd.append('conv_id',convId);
            var res=await fetch('',{method:'POST',body:fd});var data=await res.json();
            if(data.status==='success'&&data.hash!==lastHash)await fetchMessages();
        }catch(e){}
    }

    // 分享
    function shareConv(){
        var url=location.href;
        if(navigator.share){navigator.share({title:'私信 - 主播模拟器',url:url}).catch(()=>{});}
        else{navigator.clipboard.writeText(url).then(()=>alert('链接已复制')).catch(()=>alert(url));}
    }
</script>
    </div><!-- /page-content -->
    <?php include 'spa.php'; ?>
</body>
</html>
