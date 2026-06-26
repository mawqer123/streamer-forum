<?php
$active_bottom = 'notifications';
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    redirect(url('index'));
}

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);

if (isset($_GET['action']) && $_GET['action'] === 'count') {
    header('Content-Type: application/json');
    $count = getUnreadNotificationCount($currentUser['id']);
    list($chatUnread, $chatMention) = getChatUnreadCount($currentUser['id'], $currentUser['username']);
    echo json_encode(['success' => true, 'count' => $count + $chatUnread, 'chat_unread' => $chatUnread, 'chat_mention' => $chatMention]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('CSRF令牌验证失败！');
    }
    
    if ($_POST['action'] === 'mark_read' && isset($_POST['id'])) {
        $notificationId = intval($_POST['id']);
        markNotificationAsRead($notificationId, $currentUser['id']);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['success' => true]);
            exit;
        }
        redirect(url('notifications'));
    }
    
    if ($_POST['action'] === 'mark_all_read') {
        markAllNotificationsAsRead($currentUser['id']);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['success' => true]);
            exit;
        }
        redirect(url('notifications'));
    }
    
    if ($_POST['action'] === 'delete' && isset($_POST['id'])) {
        $notificationId = intval($_POST['id']);
        deleteNotification($notificationId, $currentUser['id']);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['success' => true]);
            exit;
        }
        redirect(url('notifications'));
    }
}

$currentUserForTheme = $currentUser;

// 计算互动消息未读数
$notifUnread = getUnreadNotificationCount($currentUser['id']);

// 计算群聊未读数
$chatUnreadTotal = 0;
$chatUnreadMentions = false;
$chatReadStateFile = __DIR__ . '/data/chat/read_state.json';
$chatGroupsFile = __DIR__ . '/data/chat/groups.json';
if (file_exists($chatReadStateFile) && file_exists($chatGroupsFile)) {
    $chatReadState = json_decode(file_get_contents($chatReadStateFile), true) ?: [];
    $chatGroups = json_decode(file_get_contents($chatGroupsFile), true) ?: [];
    $userId = $currentUser['id'] ?? 0;
    $username = $currentUser['username'] ?? '';
    foreach ($chatGroups as $gid => $group) {
        if (!in_array($username, $group['members'] ?? [])) continue;
        $key = $userId . '_' . $gid;
        $lastRead = $chatReadState[$key]['time'] ?? 0;
        $msgFile = __DIR__ . "/data/chat/group_{$gid}.json";
        if (!file_exists($msgFile)) continue;
        $msgs = json_decode(file_get_contents($msgFile), true) ?: [];
        foreach ($msgs as $m) {
            if (($m['time'] ?? 0) > $lastRead && !($m['deleted'] ?? false) && ($m['username'] ?? '') !== $username) {
                $chatUnreadTotal++;
                if (!empty($m['reply_to'])) {
                    foreach ($msgs as $orig) {
                        if (($orig['id'] ?? '') === $m['reply_to'] && ($orig['username'] ?? '') === $username) {
                            $chatUnreadMentions = true;
                            break 2;
                        }
                    }
                }
            }
        }
    }
}

// 计算私信未读数
$pmUnreadTotal = 0;
$pmConvFile = __DIR__ . '/data/pm/conversations.json';
$pmReadFile = __DIR__ . '/data/pm/read_state.json';
$pmUid = (int)($currentUser['id'] ?? 0);
if ($pmUid > 0 && file_exists($pmConvFile)) {
    $pmConvs = json_decode(file_get_contents($pmConvFile), true) ?: [];
    $pmRS = file_exists($pmReadFile) ? (json_decode(file_get_contents($pmReadFile), true) ?: []) : [];
    foreach ($pmConvs as $pmCid => $pmC) {
        if (($pmC['user1_id'] ?? 0) != $pmUid && ($pmC['user2_id'] ?? 0) != $pmUid) continue;
        $pmKey = "{$pmUid}_{$pmCid}";
        $pmLastRead = $pmRS[$pmKey]['time'] ?? 0;
        $pmMsgFile = __DIR__ . "/data/pm/conv_{$pmCid}.json";
        if (!file_exists($pmMsgFile)) continue;
        $pmMsgs = json_decode(file_get_contents($pmMsgFile), true) ?: [];
        foreach ($pmMsgs as $pmM) {
            if (($pmM['sender_id'] ?? 0) != $pmUid && ($pmM['time'] ?? 0) > $pmLastRead && empty($pmM['deleted'])) $pmUnreadTotal++;
        }
    }
}

function formatBadge($count) {
    if ($count <= 0) return '';
    return $count > 99 ? '99+' : (string)$count;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>消息 - 主播模拟器论坛</title>
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
    <style data-page-style>
        /* 消息页面 - 全新设计 */
        #top-bar { display: none !important; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { margin: 0 !important; padding: 0 !important; height: 100%; }
        body { background-color: var(--bg-primary); color: var(--text-primary); transition: background-color 0.3s, color 0.3s; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .main-content { margin: 0 !important; padding: 0 !important; min-height: 100%; padding-bottom: 70px !important; }

        .msg-title-bar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--accent-color);
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        .msg-title-bar h1 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #fff;
        }

        /* 消息行 */
        .msg-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            background: var(--bg-primary);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            min-height: 56px;
        }
        .msg-row:active {
            background: var(--link-hover-bg);
        }
        .msg-row-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .msg-row-icon {
            width: 28px;
            height: 28px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .msg-row-icon svg {
            width: 26px;
            height: 26px;
            stroke: var(--text-primary);
            fill: none;
            stroke-width: 1.8;
        }
        .msg-row-text {
            font-size: 1rem;
            font-weight: 500;
            color: var(--text-primary);
        }
        .msg-row-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            background: #ef4444;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0 6px;
            line-height: 1;
            flex-shrink: 0;
        }
        .msg-row-badge.pulse {
            animation: msgBadgePulse 1.5s ease-in-out infinite;
        }
        @keyframes msgBadgePulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.12); }
        }

        /* 分隔线 */
        .msg-divider {
            height: 1px;
            background: var(--border-color);
            margin: 0 16px;
        }

        @media (max-width: 480px) {
            .msg-row { padding: 12px 16px; min-height: 50px; }
            .msg-row-icon { width: 24px; height: 24px; }
            .msg-row-icon svg { width: 22px; height: 22px; }
            .msg-row-text { font-size: 0.95rem; }
            .msg-title-bar { height: 48px; }
            .msg-title-bar h1 { font-size: 1rem; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <?php $currentTab = 'notifications'; $hideTopBar = true; include __DIR__ . '/header.php'; ?>
    <div id="page-content">
    <div class="msg-title-bar">
        <h1>消息</h1>
    </div>
    <main class="main-content">
        <!-- 互动消息 -->
        <a href="#" data-nav-url="<?php echo url('interactive_messages'); ?>" data-tab="notifications" class="msg-row">
            <div class="msg-row-left">
                <div class="msg-row-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                </div>
                <span class="msg-row-text">互动消息</span>
            </div>
            <?php if ($notifUnread > 0): ?>
            <span class="msg-row-badge"><?php echo formatBadge($notifUnread); ?></span>
            <?php endif; ?>
        </a>

        <div class="msg-divider"></div>

        <!-- 群聊 -->
        <a href="#" data-nav-url="<?php echo url('group_list'); ?>" data-tab="notifications" class="msg-row">
            <div class="msg-row-left">
                <div class="msg-row-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <span class="msg-row-text">群聊</span>
            </div>
            <?php if ($chatUnreadTotal > 0): ?>
            <span class="msg-row-badge<?php echo $chatUnreadMentions ? ' pulse' : ''; ?>"><?php echo $chatUnreadMentions ? '@' : formatBadge($chatUnreadTotal); ?></span>
            <?php endif; ?>
        </a>

        <div class="msg-divider"></div>

        <!-- 私信 -->
        <a href="#" data-nav-url="<?php echo url('pm_list'); ?>" data-tab="notifications" class="msg-row">
            <div class="msg-row-left">
                <div class="msg-row-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                </div>
                <span class="msg-row-text">私信</span>
            </div>
            <?php if ($pmUnreadTotal > 0): ?>
            <span class="msg-row-badge"><?php echo formatBadge($pmUnreadTotal); ?></span>
            <?php endif; ?>
        </a>
    </main>
    </div><!-- /page-content -->

    <?php include 'bottom_nav.php'; ?>
    <?php include 'auth_modal.php'; ?>
    <?php include __DIR__ . '/spa.php'; ?>
</body>
</html>
