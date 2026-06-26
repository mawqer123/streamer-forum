<?php
$active_bottom = 'notifications';
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    redirect(url('index'));
}

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);

// 进入互动消息页面自动将所有消息标记为已读，底栏红点立即消失
markAllNotificationsAsRead($currentUser['id']);

$filterType = isset($_GET['type']) && in_array($_GET['type'], ['all', 'like_post', 'like_comment', 'comment', 'reply', 'follow', 'tip']) ? $_GET['type'] : 'all';

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;

$typeForSql = ($filterType === 'all') ? null : $filterType;
$notifications = getUserNotifications($currentUser['id'], $page, $perPage, $typeForSql);
$total = getUserNotificationsCount($currentUser['id'], $typeForSql);
$totalPages = ceil($total / $perPage);

$currentUserForTheme = $currentUser;

// 计算页面主色（硬编码到 CSS，不依赖 CSS 变量解析）
$pageAccentColor = '#2196F3';
$pageAccentTo = '#1565C0';
if ($currentUserForTheme && isset($currentUserForTheme['theme']) && $currentUserForTheme['theme'] === 'custom' && !empty($currentUserForTheme['theme_settings'])) {
    $settings = $currentUserForTheme['theme_settings'];
    $primary = $settings['primary'] ?? '#2196F3';
    if (!empty($primary)) {
        list($r, $g, $b) = sscanf($primary, "#%02x%02x%02x");
        if ($r !== null) {
            $r = max(0, $r - 20); $g = max(0, $g - 20); $b = max(0, $b - 20);
            $pageAccentColor = $primary;
            $pageAccentTo = sprintf("#%02x%02x%02x", $r, $g, $b);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF令牌验证失败']);
        exit;
    }
    $result = markAllNotificationsAsRead($currentUser['id']);
    echo json_encode(['success' => $result]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_delete') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF令牌验证失败']);
        exit;
    }
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    if (is_array($ids) && count($ids) > 0) {
        $success = true;
        foreach ($ids as $id) {
            if (!deleteNotification(intval($id), $currentUser['id'])) {
                $success = false;
            }
        }
        echo json_encode(['success' => $success]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>互动消息 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <?php if ($currentUserForTheme && isset($currentUserForTheme['theme']) && $currentUserForTheme['theme'] === 'custom' && !empty($currentUserForTheme['theme_settings'])): ?>
    <style data-page-style>:root{--accent-color:<?php echo $pageAccentColor; ?>;--accent-gradient-from:<?php echo $pageAccentColor; ?>;--accent-gradient-to:<?php echo $pageAccentTo; ?>;}</style>
    <?php endif; ?>
    <style data-page-style>
        /* interactive_messages.php 特有样式 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { margin: 0 !important; padding: 0 !important; height: 100%; }
        body { background-color: var(--bg-secondary); color: var(--text-primary); transition: background-color 0.3s, color 0.3s; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .main-content

        .interactive-header {
            background-color: <?php echo $pageAccentColor; ?>;
            color: white;
            padding: 0.5rem 0.8rem;
            position: sticky;
            top: 0;
            z-index: 100;
            margin: 0 auto;
        }
        .header-container {
            margin: 0 auto;
            display: flex;
            align-items: center;
        }
        .back-link {
            font-size: 1.8rem; line-height: 1; color: white; text-decoration: none;
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; transition: background-color 0.2s; flex-shrink: 0;
        }
        .back-link:hover { background-color: rgba(255,255,255,0.2); }
        .header-center { flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.25rem; }
        .header-title { font-size: 1.2rem; font-weight: 600; }

        .filter-dropdown {
            position: relative; display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; padding: 2px 5px; border-radius: 4px; transition: background 0.2s;
        }
        .filter-dropdown:hover { background-color: rgba(255,255,255,0.2); }
        .filter-triangle { font-size: 0.55rem; color: white; line-height: 1; user-select: none; }
        .dropdown-menu {
            position: absolute; top: 100%; right: 0; background: var(--bg-primary);
            border-radius: 0; box-shadow: 0 4px 15px rgba(0,0,0,0.15); min-width: 120px;
            display: none; z-index: 1000; overflow: hidden; border: 1px solid var(--border-color);
            margin-top: 0.5rem;
        }
        .dropdown-menu.show { display: block; }
        .dropdown-item {
            display: block; padding: 0.75rem 1rem; color: var(--text-primary);
            text-decoration: none; font-size: 0.9rem; border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s; cursor: pointer; text-align: left;
            width: 100%; background: none; border: none;
        }
        .dropdown-item:last-child { border-bottom: none; }
        .dropdown-item:hover { background-color: var(--link-hover-bg); }
        .dropdown-item.active { color: var(--accent-color); font-weight: 600; }

        /* 管理按钮 */
        .header-action-btn {
            background: none; border: none; color: white; cursor: pointer;
            width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; transition: background 0.2s; flex-shrink: 0; padding: 0;
        }
        .header-action-btn:hover { background-color: rgba(255,255,255,0.2); }
        .header-action-btn.active { background-color: rgba(255,255,255,0.25); }

        /* 消息复选框 */
        .msg-checkbox {
            display: none; align-items: flex-start; justify-content: center;
            width: 20px; flex-shrink: 0; padding-top: 14px;
        }
        .msg-checkbox.show { display: flex; }
        .msg-checkbox input[type="checkbox"] {
            width: 18px; height: 18px; cursor: pointer; accent-color: var(--accent-color);
        }

        /* 删除操作栏 */
        .delete-bar {
            display: none; position: fixed; bottom: 0; left: 0; right: 0;
            background: var(--bg-primary); border-top: 1px solid var(--border-color);
            padding: 0.8rem 1rem; z-index: 999; text-align: center;
            margin: 0 auto;
        }
        .delete-bar.show { display: block; }
        .delete-bar-inner {
            display: flex; align-items: center; justify-content: center; gap: 0.6rem;
        }
        .select-all-label {
            display: flex; align-items: center; gap: 0.35rem;
            font-size: 0.9rem; color: var(--text-primary); cursor: pointer;
            user-select: none; white-space: nowrap;
        }
        .select-all-label input[type="checkbox"] {
            width: 16px; height: 16px; cursor: pointer; accent-color: var(--accent-color);
        }
        .delete-bar-cancel {
            background: none; border: 1px solid var(--border-color); color: var(--text-primary);
            padding: 0.6rem 1.2rem; border-radius: 8px; font-size: 0.95rem;
            cursor: pointer; transition: background 0.2s;
        }
        .delete-bar-cancel:hover { background: var(--link-hover-bg); }
        .delete-btn {
            background: #e74c3c; color: white; border: none; padding: 0.6rem 2rem;
            border-radius: 8px; font-size: 0.95rem; cursor: pointer; font-weight: 500;
            transition: opacity 0.2s;
        }
        .delete-btn.is-disabled { opacity: 0.4; cursor: default; pointer-events: none; }
        .delete-btn:not(.is-disabled):hover { opacity: 0.85; }

        /* 底部退出的间距 */
        body.has-delete-bar .messages-container { padding-bottom: 5rem; }

        .messages-container { margin: 0 auto; }
        .message-item {
            background: var(--bg-primary); border-radius: 0; padding: 0.8rem 1rem;
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: flex-start; gap: 0.4rem;
        }
        .message-item.unread { border-left: 4px solid var(--accent-color); }
        .message-item:hover { background-color: var(--link-hover-bg); }
        .message-avatar {
            width: 48px; height: 48px; border-radius: 50%; background: var(--accent-gradient-from);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 1rem; flex-shrink: 0; overflow: hidden;
            text-transform: uppercase; text-decoration: none;
        }
        .message-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .message-content { flex: 1; min-width: 0; }
        .message-header { display: flex; align-items: baseline; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.3rem; }
        .actor-name { font-weight: 600; color: var(--text-primary); text-decoration: none; }
        .actor-name:hover { color: var(--accent-color); }

        .message-time { font-size: 0.8rem; color: var(--text-secondary); flex-shrink: 0; }
        .message-body { color: var(--text-primary); line-height: 1.5; word-break: break-word; margin-bottom: 0.5rem; }
        .message-body a { color: var(--accent-color); text-decoration: none; font-weight: 500; }
        .comment-preview {
            margin-top: 0.6rem; padding: 0.5rem 0.8rem; background: var(--bg-secondary);
            border-left: 3px solid var(--accent-color); border-radius: 0; font-size: 0.85rem;
            color: var(--text-secondary); word-break: break-word;
        }

        @media (max-width: 768px) {
            .message-item { padding: 0.7rem 0.8rem; gap: 0.6rem; }
            .message-avatar { width: 40px; height: 40px; font-size: 0.9rem; }
            .back-link { font-size: 1.6rem; width: 36px; height: 36px; }
            .header-title { font-size: 1.1rem; }
            .header-action-btn { width: 32px; height: 32px; }
            .header-action-btn svg { width: 20px; height: 20px; }
            .delete-bar { padding: 0.6rem 0.8rem; }
            .delete-bar-cancel { padding: 0.5rem 1rem; font-size: 0.9rem; }
            .delete-btn { padding: 0.5rem 1.5rem; font-size: 0.9rem; }
        }
        @media (max-width: 480px) {
            .message-header { flex-wrap: wrap; }
            .message-time { font-size: 0.75rem; }
            .back-link { font-size: 1.5rem; width: 32px; height: 32px; }
            .header-title { font-size: 1rem; }
            .header-action-btn { width: 28px; height: 28px; }
            .header-action-btn svg { width: 18px; height: 18px; }
            .delete-bar-cancel { padding: 0.5rem 0.8rem; font-size: 0.85rem; }
            .delete-btn { padding: 0.5rem 1.2rem; font-size: 0.85rem; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <div id="page-content">
    <div class="interactive-header">
        <div class="header-container">
            <a href="#" data-nav-url="<?php echo url('notifications'); ?>" data-tab="notifications" class="back-link">←</a>
            <div class="header-center">
                <span class="header-title">互动消息</span>
                <div class="filter-dropdown" id="filterDropdown">
                    <span class="filter-triangle">▼</span>
                    <div class="dropdown-menu" id="filterMenu">
                        <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => 'all']); ?>" data-tab="notifications" class="dropdown-item <?php echo $filterType === 'all' ? 'active' : ''; ?>">全部</a>
                        <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => 'like_post']); ?>" data-tab="notifications" class="dropdown-item <?php echo $filterType === 'like_post' ? 'active' : ''; ?>">点赞帖子</a>
                        <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => 'like_comment']); ?>" data-tab="notifications" class="dropdown-item <?php echo $filterType === 'like_comment' ? 'active' : ''; ?>">点赞评论</a>
                        <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => 'comment']); ?>" data-tab="notifications" class="dropdown-item <?php echo $filterType === 'comment' ? 'active' : ''; ?>">评论</a>
                        <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => 'reply']); ?>" data-tab="notifications" class="dropdown-item <?php echo $filterType === 'reply' ? 'active' : ''; ?>">回复</a>
                        <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => 'follow']); ?>" data-tab="notifications" class="dropdown-item <?php echo $filterType === 'follow' ? 'active' : ''; ?>">关注</a>
                        <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => 'tip']); ?>" data-tab="notifications" class="dropdown-item <?php echo $filterType === 'tip' ? 'active' : ''; ?>">打赏</a>
                    </div>
                </div>
            </div>
            <button class="header-action-btn" id="manageBtn" title="管理">
                <svg viewBox="0 0 24 24" width="22" height="22" stroke="white" stroke-width="2" fill="none">
                    <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2" />
                </svg>
            </button>
        </div>
    </div>

    <main class="main-content">
        <div class="messages-container">
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="60" height="60">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <p>暂无互动消息</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notif): 
                    $unread = !$notif['is_read'];
                    $actor = $notif['actor_username'] ?? '未知用户';
                    $actorAvatar = $notif['actor_avatar'] ?? '';
                    $actorAvatarText = $notif['actor_avatar_text'] ?? '';
                    $actorAvatarBg = $notif['actor_avatar_bg'] ?? '';
                    $time = date('Y-m-d H:i', strtotime($notif['created_at']));
                    $type = $notif['type'];
                    $targetId = $notif['target_id'];
                    $data = $notif['data'];
                    
                    $message = '';
                    $link = '#';
                    $commentContent = '';
                    
                    switch ($type) {
                        case 'like_post':
                            $postId = $targetId;
                            $message = "点赞了你的帖子";
                            $link = url('user', ['id' => $notif['actor_id']]);
                            break;
                        case 'like_comment':
                            $commentId = $targetId;
                            $postId = $data['post_id'] ?? 0;
                            $message = "点赞了你的评论";
                            $link = url('user', ['id' => $notif['actor_id']]);
                            break;
                        case 'comment':
                            $postId = $targetId;
                            $message = "评论了你的帖子";
                            $link = url('post', ['id' => $postId]) . '#comments';
                            if (isset($data['comment_id'])) {
                                $comment = getCommentById($data['comment_id']);
                                if ($comment && isset($comment['content'])) {
                                    $rawContent = strip_tags($comment['content']);
                                    $commentContent = mb_substr($rawContent, 0, 100, 'UTF-8');
                                    if (mb_strlen($rawContent) > 100) $commentContent .= '...';
                                } else {
                                    $commentContent = '(内容已删除)';
                                }
                            }
                            break;
                        case 'reply':
                            $parentCommentId = $targetId;
                            $postId = $data['post_id'] ?? 0;
                            $message = "回复了你的评论";
                            $link = $postId ? url('reply_comment', [], ['post_id' => $postId, 'comment_id' => $parentCommentId]) : '#';
                            if (isset($data['comment_id'])) {
                                $comment = getCommentById($data['comment_id']);
                                if ($comment && isset($comment['content'])) {
                                    $rawContent = strip_tags($comment['content']);
                                    $commentContent = mb_substr($rawContent, 0, 100, 'UTF-8');
                                    if (mb_strlen($rawContent) > 100) $commentContent .= '...';
                                } else {
                                    $commentContent = '(内容已删除)';
                                }
                            }
                            break;
                        case 'follow':
                            $message = "关注了你";
                            $link = url('user', ['id' => $notif['actor_id']]);
                            break;
                        case 'tip':
                            $postId = $targetId;
                            $amount = $data['amount'] ?? 0;
                            $message = "打赏了你 " . $amount . " 积分";
                            $link = url('user', ['id' => $notif['actor_id']]);
                            break;
                        case 'audit_approved':
                            $auditType = $data['type'] ?? '';
                            $typeLabels = ['avatar'=>'头像','post'=>'帖子','username'=>'用户名','comment'=>'评论','image'=>'图片','background_image'=>'背景图'];
                            $typeCn = $typeLabels[$auditType] ?? $auditType;
                            $message = "你的" . $typeCn . "已通过审核";
                            $link = url('user', ['id' => $notif['actor_id']]);
                            break;
                        case 'audit_rejected':
                            $auditType = $data['type'] ?? '';
                            $typeLabels = ['avatar'=>'头像','post'=>'帖子','username'=>'用户名','comment'=>'评论','image'=>'图片','background_image'=>'背景图'];
                            $typeCn = $typeLabels[$auditType] ?? $auditType;
                            $message = "你的" . $typeCn . "未通过审核";
                            $link = url('user', ['id' => $notif['actor_id']]);
                            break;
                        default:
                            $message = "有新的互动";
                    }
                    
                    $actorLink = url('user', ['id' => $notif['actor_id']]);
                    $actorUser = [
                        'id' => $notif['actor_id'],
                        'avatar' => $actorAvatar,
                        'avatar_text' => $actorAvatarText,
                        'avatar_bg_color' => $actorAvatarBg,
                        'username' => $actor,
                    ];
                ?>
                    <div class="message-item <?php echo $unread ? 'unread' : ''; ?>" id="msg-<?php echo $notif['id']; ?>">
                        <label class="msg-checkbox">
                            <input type="checkbox" class="msg-select" value="<?php echo $notif['id']; ?>">
                        </label>
                        <a href="#" data-nav-url="<?php echo $actorLink; ?>" class="message-avatar">
                            <?php echo getUserAvatarHtml($actorUser, 'message-avatar'); ?>
                        </a>
                        <div class="message-content">
                            <div class="message-header">
                                <a href="#" data-nav-url="<?php echo $actorLink; ?>" class="actor-name"><?php echo escape($actor); ?></a>
                                <span class="message-time"><?php echo $time; ?></span>
                            </div>
                            <div class="message-body">
                                <a href="#" data-nav-url="<?php echo $link; ?>"><?php echo escape($message); ?></a>
                                <?php if (!empty($commentContent)): ?>
                                    <div class="comment-preview"> 评论内容：<?php echo escape($commentContent); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => $filterType, 'page' => 1]); ?>" data-tab="notifications">首页</a>
                            <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => $filterType, 'page' => $page - 1]); ?>" data-tab="notifications">上一页</a>
                        <?php else: ?>
                            <span class="disabled">首页</span>
                            <span class="disabled">上一页</span>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        if ($start > 1) echo '<span>...</span>';
                        for ($i = $start; $i <= $end; $i++):
                            if ($i == $page):
                        ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => $filterType, 'page' => $i]); ?>" data-tab="notifications"><?php echo $i; ?></a>
                        <?php endif; endfor; ?>
                        <?php if ($end < $totalPages) echo '<span>...</span>'; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => $filterType, 'page' => $page + 1]); ?>" data-tab="notifications">下一页</a>
                            <a href="#" data-nav-url="<?php echo url('interactive_messages', [], ['type' => $filterType, 'page' => $totalPages]); ?>" data-tab="notifications">尾页</a>
                        <?php else: ?>
                            <span class="disabled">下一页</span>
                            <span class="disabled">尾页</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    <div class="delete-bar" id="deleteBar">
        <div class="delete-bar-inner">
            <label class="select-all-label">
                <input type="checkbox" id="selectAllCb"> 全选
            </label>
            <button class="delete-bar-cancel" id="manageCancelBtn">取消</button>
            <button class="delete-btn is-disabled" id="deleteSelectedBtn">删除选中 (0)</button>
        </div>
    </div>
    <script>
        // 强制给标题栏设色（兜底，不依赖 CSS 变量或 style swap）
        (function(){
            var h = document.querySelector('.interactive-header');
            if (h) {
                h.style.backgroundColor = '<?php echo $pageAccentColor; ?>';
            }
        })();

        var dropdownBtn = document.getElementById('filterDropdown');
        var filterMenu = document.getElementById('filterMenu');
        dropdownBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            filterMenu.classList.toggle('show');
        });
        document.addEventListener('click', function(e) {
            if (!dropdownBtn.contains(e.target) && !filterMenu.contains(e.target)) {
                filterMenu.classList.remove('show');
            }
        });

        /* ===== 多选删除管理功能 ===== */
        var manageBtn = document.getElementById('manageBtn');
        var deleteBar = document.getElementById('deleteBar');
        var deleteBtn = document.getElementById('deleteSelectedBtn');
        var cancelBtn = document.getElementById('manageCancelBtn');
        var selectAllCb = document.getElementById('selectAllCb');
        var selectedIds = new Set();
        var isManageMode = false;

        function exitManageMode() {
            isManageMode = false;
            manageBtn.classList.remove('active');
            document.querySelectorAll('.msg-checkbox').forEach(function(el) {
                el.classList.remove('show');
            });
            document.querySelectorAll('.msg-select').forEach(function(el) {
                el.checked = false;
            });
            selectedIds.clear();
            selectAllCb.checked = false;
            selectAllCb.indeterminate = false;
            deleteBar.classList.remove('show');
            document.body.classList.remove('has-delete-bar');
        }

        function updateDeleteBar() {
            var count = selectedIds.size;
            var total = document.querySelectorAll('.msg-select').length;
            deleteBtn.textContent = '删除选中 (' + count + ')';
            if (count === 0) {
                deleteBtn.classList.add('is-disabled');
            } else {
                deleteBtn.classList.remove('is-disabled');
            }
            // 更新全选复选框状态
            selectAllCb.checked = (count === total && total > 0);
            selectAllCb.indeterminate = (count > 0 && count < total);
            if (isManageMode) {
                deleteBar.classList.add('show');
                document.body.classList.add('has-delete-bar');
            } else {
                deleteBar.classList.remove('show');
                document.body.classList.remove('has-delete-bar');
            }
        }

        manageBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            isManageMode = !isManageMode;
            if (isManageMode) {
                manageBtn.classList.add('active');
                document.querySelectorAll('.msg-checkbox').forEach(function(el) {
                    el.classList.add('show');
                });
                updateDeleteBar();
            } else {
                exitManageMode();
            }
        });

        document.querySelectorAll('.msg-select').forEach(function(cb) {
            cb.addEventListener('change', function() {
                if (this.checked) {
                    selectedIds.add(this.value);
                } else {
                    selectedIds.delete(this.value);
                }
                updateDeleteBar();
            });
        });

        // 全选/取消全选
        selectAllCb.addEventListener('change', function() {
            var checked = this.checked;
            document.querySelectorAll('.msg-select').forEach(function(cb) {
                cb.checked = checked;
                if (checked) {
                    selectedIds.add(cb.value);
                } else {
                    selectedIds.delete(cb.value);
                }
            });
            updateDeleteBar();
        });

        cancelBtn.addEventListener('click', function() {
            exitManageMode();
        });

        deleteBtn.addEventListener('click', function(e) {
            if (this.classList.contains('is-disabled')) return;
            if (selectedIds.size === 0) return;
            if (!confirm('确定要删除选中的 ' + selectedIds.size + ' 条消息吗？')) return;

            var self = this;
            var originalText = self.textContent;
            self.textContent = '删除中...';
            self.classList.add('is-disabled');

            var ids = [];
            selectedIds.forEach(function(id) { ids.push(id); });

            var params = new URLSearchParams();
            params.append('action', 'batch_delete');
            params.append('ids', JSON.stringify(ids));
            params.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            fetch(location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params
            }).then(function(r) { return r.json(); }).then(function(data) {
                if (data.success) {
                    ids.forEach(function(id) {
                        var el = document.getElementById('msg-' + id);
                        if (el) el.remove();
                    });
                    exitManageMode();
                    if (document.querySelectorAll('.message-item').length === 0) {
                        location.reload();
                    }
                } else {
                    self.textContent = originalText;
                    self.classList.remove('is-disabled');
                    alert('删除失败，请重试');
                }
            }).catch(function() {
                self.textContent = originalText;
                self.classList.remove('is-disabled');
                alert('网络错误，请重试');
            });
        });
    </script>
    </div><!-- /page-content -->
    <?php include 'auth_modal.php'; ?>
    <?php include 'spa.php'; ?>
</body>
</html>