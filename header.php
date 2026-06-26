<?php
// header.php - 顶部标题栏（PHP只传数据，TS渲染DOM）
require_once __DIR__ . '/functions.php';

// 获取当前用户信息
$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);
$isLoggedIn = $currentUser !== null;
$isAdmin = $isLoggedIn && isAdmin();

// 计算未读数量（来自原 bottom_nav.php）
$unreadCount = 0;
$chatUnread = 0;
$pmUnread = 0;
$auditPendingCount = 0;

if ($isLoggedIn) {
    $unreadCount = getUnreadNotificationCount($currentUser['id']);
    list($chatUnread, $chatMention) = getChatUnreadCount($currentUser['id'], $currentUser['username']);
    $unreadCount += $chatUnread;

    $pmUnread = 0;
    $pmFile = __DIR__ . '/data/pm/conversations.json';
    if (file_exists($pmFile)) {
        $pmConvs = json_decode(file_get_contents($pmFile), true) ?: [];
        $pmReadFile = __DIR__ . '/data/pm/read_state.json';
        $pmReadState = file_exists($pmReadFile) ? (json_decode(file_get_contents($pmReadFile), true) ?: []) : [];
        $myId = (int)$currentUser['id'];
        foreach ($pmConvs as $convId => $conv) {
            if (($conv['user1_id'] ?? 0) != $myId && ($conv['user2_id'] ?? 0) != $myId) continue;
            $key = "{$myId}_{$convId}";
            $lastRead = $pmReadState[$key]['time'] ?? 0;
            $msgFile = __DIR__ . "/data/pm/conv_{$convId}.json";
            if (file_exists($msgFile)) {
                $msgs = json_decode(file_get_contents($msgFile), true) ?: [];
                foreach ($msgs as $msg) {
                    if (($msg['sender_id'] ?? 0) != $myId && ($msg['time'] ?? 0) > $lastRead && empty($msg['deleted'])) $pmUnread++;
                }
            }
        }
    }

    if ($isAdmin) {
        try {
            $pdoCount = getDbConnection();
            $stmt = $pdoCount->query("SELECT COUNT(*) FROM audit_items WHERE status=\"pending\"");
            $auditPendingCount = (int)$stmt->fetchColumn();
        } catch (Exception $e) {}
    }
    $unreadCount += $pmUnread;
}

// 预渲染头像HTML
$avatarHtml = '';
if ($isLoggedIn) {
    $avatarHtml = getUserAvatarHtml($currentUser, 'text-avatar');
}
?>
<!-- TS 渲染的顶栏容器 -->
<header id="top-bar" class="header-bar"></header>

<!-- Initial State -->
<script id="__INITIAL_STATE__" type="application/json">{
  "isLoggedIn": <?php echo json_encode((bool)$isLoggedIn); ?>,
  "isAdmin": <?php echo json_encode((bool)$isAdmin); ?>,
  "userId": <?php echo $isLoggedIn ? json_encode((int)$currentUser['id']) : 'null'; ?>,
  "username": <?php echo $isLoggedIn ? json_encode($currentUser['username']) : 'null'; ?>,
  "avatarHtml": <?php echo $isLoggedIn ? json_encode($avatarHtml) : 'null'; ?>,
  "unreadCount": <?php echo (int)$unreadCount; ?>,
  "chatUnread": <?php echo (int)$chatUnread; ?>,
  "pmUnread": <?php echo (int)$pmUnread; ?>,
  "auditPending": <?php echo (int)$auditPendingCount; ?>,
  "currentTab": <?php echo json_encode($currentTab ?? 'home'); ?>,
  "hideTopBar": <?php echo json_encode($hideTopBar ?? false); ?>,
  "hideBottomBar": <?php echo json_encode($hideBottomBar ?? false); ?>,
  "siteUrl": <?php echo json_encode(url('')); ?>,
  "urls": {
    "search": <?php echo json_encode(url('search')); ?>,
    "admin": "/admin",
    "profile": <?php echo json_encode(url('profile')); ?>,
    "level": <?php echo json_encode(url('level')); ?>,
    "notifications": <?php echo json_encode(url('notifications')); ?>
  }
}</script>

<!-- TypeScript Layout Module -->
<script src="/js/layout.js?v=<?php echo filemtime(__DIR__ . '/js/layout.js'); ?>"></script>
<script src="/js/links-cards.js?v=<?php echo filemtime(__DIR__ . '/js/links-cards.js'); ?>"></script>

<style>
/* 头部背景使用主题渐变，确保跟随主题切换 */
.header-bar {
    background: var(--accent-gradient-from);
    transition: background 0.3s ease;
}
.text-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-gradient-from), var(--accent-gradient-to));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    overflow: hidden;
    line-height: 0;
    transition: all 0.3s;
    border: 2px solid var(--accent-color);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    text-transform: uppercase;
}
.text-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.user-menu {
    position: absolute;
    top: 44px;
    right: 0.75rem;
    background: white;
    border-radius: 0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    min-width: 180px;
    z-index: 1001;
    overflow: hidden;
    border: 1px solid #e0e0e0;
}
.user-menu-item {
    display: flex;
    align-items: center;
    padding: 1rem;
    color: #333;
    text-decoration: none;
    transition: background-color 0.2s;
    font-size: 0.95rem;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
}
.user-menu-item:last-child {
    border-bottom: none;
}
.user-menu-item:hover {
    background-color: #f8f9fa;
}
.user-menu-icon {
    width: 20px;
    height: 20px;
    margin-right: 0.75rem;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    color: #666;
}
.user-menu-icon svg {
    width: 100%;
    height: 100%;
}
.top-icon-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.5rem;
    color: white;
    transition: background-color 0.2s;
    text-decoration: none;
}
.top-icon-btn:hover {
    background-color: rgba(255, 255, 255, 0.1);
}
.top-icon-btn svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}
@media (max-width: 768px) {
    .text-avatar {
        width: 28px;
        height: 28px;
        font-size: 0.8rem;
    }
    .user-menu {
        top: 36px;
        right: 0.5rem;
        min-width: 160px;
    }
    .user-menu-item {
        padding: 0.75rem;
        font-size: 0.85rem;
    }
    .user-menu-icon {
        width: 16px;
        height: 16px;
        margin-right: 0.5rem;
    }
    .top-icon-btn svg {
        width: 18px;
        height: 18px;
    }
}
@media (max-width: 360px) {
    .text-avatar {
        width: 26px;
        height: 26px;
        font-size: 0.75rem;
    }
    .user-menu {
        top: 32px;
        right: 0.4rem;
        min-width: 150px;
    }
    .user-menu-item {
        padding: 0.65rem;
        font-size: 0.8rem;
    }
    .user-menu-icon {
        width: 14px;
        height: 14px;
        margin-right: 0.4rem;
    }
    .top-icon-btn svg {
        width: 16px;
        height: 16px;
    }
}
</style>

<!-- 底部导航样式 -->
<style>
.bottom-nav {
    background-color: var(--bg-primary);
    border-top: 1px solid var(--border-color);
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    margin: 0 auto;
}
.bottom-nav-container {
    display: flex;
    justify-content: space-around;
    align-items: center;
    margin: 0 auto;
    height: 48px;
}
.bottom-nav-item {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-secondary);
    transition: color 0.2s;
    cursor: pointer;
    padding: 0.3rem 0;
}
.bottom-nav-item.active {
    color: var(--accent-color);
}
.bottom-nav-item:hover {
    background-color: var(--link-hover-bg);
}
.bottom-nav-icon {
    width: 20px;
    height: 20px;
    margin-bottom: 2px;
}
.bottom-nav-icon svg {
    width: 100%;
    height: 100%;
}
.bottom-nav-text {
    font-size: 0.65rem;
    line-height: 1;
}
.notification-badge {
    position: absolute;
    top: -3px;
    right: -5px;
    background-color: #e53e3e;
    color: white;
    font-size: 0.6rem;
    font-weight: bold;
    min-width: 14px;
    height: 14px;
    border-radius: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 3px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
@media (max-width: 768px) {
    .bottom-nav-container { height: 44px; }
    .bottom-nav-icon { width: 18px; height: 18px; }
    .bottom-nav-text { font-size: 0.6rem; }
    .notification-badge { top: -2px; right: -4px; min-width: 12px; height: 12px; font-size: 0.55rem; }
}
@media (max-width: 360px) {
    .bottom-nav-container { height: 40px; }
    .bottom-nav-icon { width: 16px; height: 16px; }
    .bottom-nav-text { font-size: 0.55rem; }
    .notification-badge { top: -2px; right: -3px; min-width: 11px; height: 11px; font-size: 0.5rem; }
}
</style>

<script>
<?php if ($isLoggedIn): ?>
(function() {
    var userTheme = '<?php echo $currentUser['theme'] ?? 'light'; ?>';
    var userThemeSettings = <?php echo json_encode($currentUser['theme_settings'] ?? []); ?>;
    localStorage.setItem('forum_theme', userTheme);
    if (userTheme === 'custom') {
        var mode = localStorage.getItem('forum_theme_mode') || 'light';
        document.documentElement.setAttribute('data-theme', mode);
    } else {
        document.documentElement.setAttribute('data-theme', userTheme);
    }
    if (userTheme === 'custom' && userThemeSettings && userThemeSettings.primary) {
        var primary = userThemeSettings.primary;
        document.documentElement.style.setProperty('--accent-color', primary);
        document.documentElement.style.setProperty('--accent-gradient-from', primary);
        document.documentElement.style.setProperty('--accent-gradient-to', primary);
    }
})();
<?php endif; ?>
</script>
