<?php
// reply_comment.php - 评论详情页面(统一上传逻辑,支持评论置顶,主评论菜单移至点赞右侧)
require_once __DIR__ . '/functions.php';

$currentUserForTheme = getCurrentUser();

if (!isLoggedIn()) {
    show_error_page('请先登录', '您需要登录后才能查看评论详情。', url('index'));
}

$postId = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
$commentId = isset($_GET['comment_id']) ? intval($_GET['comment_id']) : 0;

if ($postId <= 0 || $commentId <= 0) {
    show_error_page('参数错误', '帖子ID或评论ID无效。', url('index'));
}

$post = getPostById($postId);
if (!$post) {
    show_error_page('帖子不存在', '您访问的帖子不存在或已被删除。', url('index'));
}

$pdo = getDbConnection();
$stmt = $pdo->prepare("SELECT c.*, u.username, u.avatar, u.avatar_text, u.is_admin, u.is_founder, u.is_banned, u.exp FROM comments c
                      LEFT JOIN users u ON c.user_id = u.id
                      WHERE c.id = ? LIMIT 1");
$stmt->execute([$commentId]);
$mainComment = $stmt->fetch();

if (!$mainComment) {
    show_error_page('评论不存在', '您访问的评论不存在或已被删除。', url('post', ['id' => $postId]));
}

// 未审核的评论仅作者和管理员可见
if (!$mainComment['is_approved'] && (!$currentUser || ($currentUser['id'] != $mainComment['user_id'] && !isAdmin()))) {
    show_error_page('评论不存在', '您访问的评论不存在或已被删除。', url('post', ['id' => $postId]));
}

// 获取当前登录用户是否是帖子作者,以及是否有管理评论权限
$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);
$isPostAuthor = ($currentUser && $currentUser['id'] == $post['user_id']);
$canManageComments = isAdmin() || $isPostAuthor;

function getAllReplies($pdo, $parentId, $currentUserId = null) {
    $allReplies = [];
    $idsToFetch = [$parentId];
    $fetchedIds = [];
    $userId = intval($currentUserId ?? 0);
    while (!empty($idsToFetch)) {
        $placeholders = implode(',', array_fill(0, count($idsToFetch), '?'));
        $sql = "SELECT c.*, u.username, u.avatar, u.avatar_text, u.is_admin, u.is_founder, u.is_banned, u.exp
                FROM comments c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.parent_id IN ($placeholders)
                  AND (c.is_approved = 1 OR (c.is_approved = 0 AND c.user_id = ?))
                ORDER BY c.created_at ASC";
        $stmt = $pdo->prepare($sql);
        $params = array_merge($idsToFetch, [$userId]);
        $stmt->execute($params);
        $replies = $stmt->fetchAll();
        $newIds = [];
        foreach ($replies as $reply) {
            if (!in_array($reply['id'], $fetchedIds)) {
                $allReplies[] = $reply;
                $fetchedIds[] = $reply['id'];
                $newIds[] = $reply['id'];
            }
        }
        $idsToFetch = $newIds;
    }
    return $allReplies;
}

$replies = getAllReplies($pdo, $commentId, $currentUser ? $currentUser['id'] : null);

$userLikedReplies = [];
if ($currentUser) {
    try {
        $stmt = $pdo->prepare("SELECT comment_id FROM comment_likes WHERE user_id = ?");
        $stmt->execute([$currentUser['id']]);
        $userLikedReplies = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('CSRF令牌验证失败!');
    }
    switch ($_POST['action']) {
        case 'add_reply':
            $content = trim($_POST['content'] ?? '');
            $parentId = intval($_POST['parent_id'] ?? $commentId);
            if (empty($content)) {
                $replyError = '回复内容不能为空!';
            } else {
                $imageUrls = [];
                // Handle AJAX-uploaded image URLs (JSON array)
                if (!empty($_POST['reply_image_urls'])) {
                    $decoded = json_decode($_POST['reply_image_urls'], true);
                    if (is_array($decoded)) {
                        $imageUrls = array_slice($decoded, 0, 9);
                    }
                }
                // Handle direct file upload
                if (isset($_FILES['reply_image']) && $_FILES['reply_image']['error'] === UPLOAD_ERR_OK) {
                    if (count($imageUrls) < 9) {
                        $uploadResult = uploadFile($_FILES['reply_image'], 'comment');
                        if ($uploadResult['success']) {
                            $imageUrls[] = $uploadResult['file_url'];
                        } else {
                            $replyError = $uploadResult['message'];
                            break;
                        }
                    }
                }
                $commentData = [
                    'post_id' => $postId,
                    'user_id' => $currentUser['id'],
                    'content' => $content,
                    'image_url' => !empty($imageUrls) ? $imageUrls[0] : null,
                    'image_urls' => $imageUrls,
                    'parent_id' => $parentId
                ];
                $result = addComment($commentData);
                if ($result['success']) {
                    redirect(url('reply_comment', [], ['post_id' => $postId, 'comment_id' => $commentId]));
                } else {
                    $replyError = $result['message'];
                }
            }
            break;
        case 'delete_comment':
            $delCommentId = intval($_POST['comment_id'] ?? 0);
            $delPostId = intval($_POST['post_id'] ?? 0);
            if ($delCommentId <= 0 || $delPostId <= 0) {
                show_error_page('参数错误', '评论ID或帖子ID无效。', url('index'));
            }
            if (!$canManageComments) {
                show_error_page('权限不足', '您没有权限删除此评论。', url('reply_comment', [], ['post_id' => $postId, 'comment_id' => $commentId]));
            }
            $stmt = $pdo->prepare("SELECT parent_id FROM comments WHERE id = ?");
            $stmt->execute([$delCommentId]);
            $comment = $stmt->fetch();
            if (!$comment) {
                show_error_page('评论不存在', '该评论可能已被删除。', url('reply_comment', [], ['post_id' => $postId, 'comment_id' => $commentId]));
            }
            try {
                if (!deleteComment($delCommentId)) {
                    throw new Exception('删除评论失败');
                }
                if ($comment['parent_id'] == 0) {
                    redirect(url('post', ['id' => $delPostId]) . '#comments');
                } else {
                    $parentId = $comment['parent_id'];
                    redirect(url('reply_comment', [], ['post_id' => $delPostId, 'comment_id' => $parentId]));
                }
            } catch (Exception $e) {
                show_error_page('删除失败', '删除评论时发生错误:' . $e->getMessage(), url('reply_comment', [], ['post_id' => $postId, 'comment_id' => $commentId]));
            }
            break;
        case 'set_comment_top':
            $topCommentId = intval($_POST['comment_id'] ?? 0);
            $isTop = intval($_POST['is_top'] ?? 0);
            if ($topCommentId <= 0) {
                echo json_encode(['success' => false, 'message' => '评论ID无效']);
                exit;
            }
            if (!$canManageComments) {
                echo json_encode(['success' => false, 'message' => '没有权限']);
                exit;
            }
            $comment = getCommentById($topCommentId);
            if (!$comment || $comment['post_id'] != $postId) {
                echo json_encode(['success' => false, 'message' => '评论不存在']);
                exit;
            }
            if ($comment['parent_id'] !== null) {
                echo json_encode(['success' => false, 'message' => '只有顶级评论可以置顶']);
                exit;
            }
            if (setCommentTop($topCommentId, $isTop)) {
                echo json_encode(['success' => true, 'is_top' => $isTop, 'message' => $isTop ? '已置顶' : '已取消置顶']);
            } else {
                echo json_encode(['success' => false, 'message' => '操作失败']);
            }
            exit;
            break;
        case 'like_reply':
            $replyId = intval($_POST['reply_id'] ?? 0);
            if ($replyId <= 0) {
                echo json_encode(['success' => false, 'message' => '参数错误']);
                exit;
            }
            $result = toggleCommentLike($replyId, $currentUser['id']);
            if ($result['liked'] !== null) {
                $reply = getCommentById($replyId);
                $newLikeCount = $reply ? $reply['like_count'] : 0;
                echo json_encode([
                    'success' => true,
                    'liked' => $result['liked'],
                    'like_count' => $newLikeCount
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => $result['message']]);
            }
            exit;
            break;
    }
}

$csrfToken = generateCsrfToken();

$mainBadgeHtml = '';
if (!empty($mainComment['is_banned'])) {
    $mainBadgeHtml = '<span class="user-badge badge-banned">封禁</span>';
} elseif (!empty($mainComment['is_founder'])) {
    $mainBadgeHtml = '<span class="user-badge badge-founder">站长</span>';
} elseif (!empty($mainComment['is_admin'])) {
    $mainBadgeHtml = '<span class="user-badge badge-admin">管理员</span>';
}

// $mainComment is used directly by getUserAvatarHtml below
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>评论详情 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <script src="/upload_manager.js"></script>
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
        /* reply_comment.php 特有样式 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { margin: 0 !important; padding: 0 !important; background-color: var(--bg-secondary); }
        .main-content { margin: 0 !important; padding: 0 !important; min-height: 100vh; }
        .detail-header-wrapper {
            background-color: var(--accent-color);
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            width: 100%;
            margin: 0 !important;
        }
        .detail-header {
            max-width: 100%;
            margin: 0;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .detail-back {
            font-size: 1.8rem; line-height: 1; color: white; text-decoration: none;
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; transition: background-color 0.2s; cursor: pointer;
        }
        .detail-back:hover { background-color: rgba(255,255,255,0.2); }
        .detail-title { font-size: 1.2rem; font-weight: 600; color: white; margin: 0; flex: 1; text-align: center; }
        .detail-placeholder { width: 40px; }
        .comment-detail-container { max-width: 100%; margin: 0; padding: 0; }
        .main-comment-card {
            background: var(--bg-primary);
            padding: 0.8rem 0.8rem 0;
            margin-bottom: 0;
            box-shadow: var(--card-shadow);
        }
        .main-comment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.2rem;
            flex-wrap: wrap;
            gap: 0.2rem;
        }
        .comment-author { display: flex; align-items: flex-start; gap: 0.5rem; }
        .comment-author a { text-decoration: none; color: inherit; display: flex; align-items: flex-start; gap: 0.2rem; }
        .comment-avatar {
            width: 40px; height: 40px; border-radius: 50%; background: var(--accent-gradient-from);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: 1rem; flex-shrink: 0; overflow: hidden;
            text-transform: uppercase;
        }
        .comment-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .comment-user-info { display: flex; flex-direction: column; }
        .comment-username {
            font-weight: 600; color: var(--text-primary); font-size: 0.85rem;
            display: flex; align-items: center; gap: 0.15rem;
        }
        .comment-username .user-badge { margin-left: 0; font-size: 0.55rem; padding: 0.08rem 0.4rem; line-height: 1.3; }
        .comment-username .level-badge-sm { margin-left: 0; font-size: 0.55rem; padding: 0.08rem 0.35rem; line-height: 1.3; border: none; }
        .comment-top-badge {
            display: inline-block; background: #fbbf24; color: #1a202c;
            padding: 0.2rem 0.6rem; border-radius: 0; font-size: 0.75rem; font-weight: 600;
        }
        .comment-time { color: var(--text-secondary); font-size: 0.75rem; }
        .comment-content { color: var(--text-primary); line-height: 1.5; margin-bottom: 0.3rem; font-size: 1rem; }
        .comment-image-container { margin-top: 1rem; }
        .comment-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            cursor: pointer;
        }
        /* ===== 图片查看器(与帖子页一致) ===== */
        .image-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 3000;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }
        .image-modal .modal-close {
            position: absolute;
            top: 20px;
            right: 25px;
            color: #fff;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10;
        }
        .image-wrapper {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            touch-action: none;
        }
        .image-wrapper img {
            position: absolute;
            transform-origin: 0 0;
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
            transition: none;
            user-select: none;
            pointer-events: auto;
        }
        .comment-stats {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 0.3rem; padding-top: 0.3rem; border-top: 1px solid var(--border-color);
        }
        .comment-stats-left { color: var(--text-secondary); font-size: 0.9rem; }
        .comment-stats-right { display: flex; align-items: center; gap: 0.75rem; }
        .comment-like-stat {
            display: inline-flex; align-items: center; gap: 0.25rem;
            color: var(--text-secondary); font-size: 0.9rem; cursor: default;
        }
        .replies-section {
            background: var(--bg-primary); padding: 0 0.5rem 0; margin-bottom: 0;
        }
        .replies-section .section-title { margin-top: 0; margin-bottom: 0.2rem; }
        .no-replies { margin: 0; padding: 0; }
        .no-replies p { margin: 0; }
        .section-title {
            color: var(--text-primary); font-size: 0.85rem; font-weight: 600;
            margin-bottom: 0.2rem; padding-bottom: 0.15rem; display: flex; justify-content: space-between; align-items: center;
        }
        .replies-count { color: var(--text-secondary); font-size: 0.8rem; font-weight: normal; }
        .replies-list { display: flex; flex-direction: column; gap: 0.1rem; }
        .reply-item { padding-bottom: 0.15rem; border-bottom: 1px dashed var(--border-color); }
        .reply-item:last-child { border-bottom: none; padding-bottom: 0; }
        .reply-item[data-depth="1"] { margin-left: 1rem; }
        .reply-item[data-depth="2"] { margin-left: 2rem; }
        .reply-item[data-depth="3"] { margin-left: 3rem; }
        .reply-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 0.1rem; flex-wrap: wrap; gap: 0.2rem;
        }
        .reply-user { display: flex; align-items: flex-start; gap: 0.5rem; }
        .reply-user a { text-decoration: none; color: inherit; display: flex; align-items: flex-start; gap: 0.2rem; }
        .reply-avatar {
            width: 32px; height: 32px; border-radius: 50%; background: var(--accent-gradient-from);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: 0.9rem; flex-shrink: 0; overflow: hidden;
            text-transform: uppercase;
        }
        .reply-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .reply-user-info { display: flex; flex-direction: column; }
        .reply-username { font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 0.15rem; font-size: 0.85rem; }
        .reply-time { color: var(--text-secondary); font-size: 0.75rem; }
        .reply-username .user-badge { margin-left: 0; font-size: 0.55rem; padding: 0.08rem 0.4rem; line-height: 1.3; }
        .reply-username .level-badge-sm { margin-left: 0; font-size: 0.55rem; padding: 0.08rem 0.35rem; line-height: 1.3; border: none; }
        .reply-content { color: var(--text-primary); line-height: 1.4; margin-bottom: 0.1rem; }
        .reply-image-container { margin-top: 0.1rem; }
        .reply-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            cursor: pointer;
        }
        /* ===== 底部固定回复栏 ===== */
        .main-content { padding-bottom: 60px !important; }
        .reply-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-primary);
            border-top: 1px solid var(--border-color);
            padding: 0.5rem 0.75rem;
            z-index: 1000;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        .reply-bar-inner {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .reply-bar-input {
            flex: 1;
            padding: 0.55rem 0.8rem;
            border: 1px solid var(--border-color);
            border-radius: 0;
            font-size: 0.95rem;
            background: var(--bg-secondary);
            color: var(--text-primary);
            outline: none;
            font-family: inherit;
        }
        .reply-bar-input:focus {
            border-color: var(--accent-color);
        }
        .reply-bar-send {
            padding: 0.55rem 1.2rem;
            background: var(--accent-gradient-from);
            color: white;
            border: none;
            font-size: 0.95rem;
            cursor: pointer;
            white-space: nowrap;
            font-weight: 500;
        }
        .reply-bar-send:disabled {
            opacity: 0.6;
            cursor: wait;
        }
        .reply-bar-image-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem;
            cursor: pointer;
            color: var(--text-secondary);
            background: none;
            border: none;
        }
        .reply-bar-image-btn svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }
        .reply-bar-image-btn:hover {
            color: var(--accent-color);
        }
        .reply-bar-preview {
            padding: 0.25rem 0;
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }
        .reply-bar-preview .preview-wrap {
            position: relative;
            display: inline-block;
            width: 72px;
            height: 72px;
        }
        .reply-bar-preview .preview-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border: 2px solid var(--border-color);
            display: block;
        }
        .reply-bar-preview .preview-remove {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 20px;
            height: 20px;
            background: #e53e3e;
            color: #fff;
            border: none;
            border-radius: 50%;
            font-size: 0.75rem;
            line-height: 20px;
            text-align: center;
            cursor: pointer;
            font-weight: bold;
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
        }
        .reply-bar .upload-progress {
            display: none;
            height: 4px;
            background: var(--bg-secondary);
            margin-top: 0.25rem;
        }
        .reply-bar .upload-progress-bar {
            height: 100%;
            background: var(--accent-color);
            width: 0%;
            transition: width 0.3s;
        }
        .reply-bar-image-btn input[type="file"] { display: none; }
        .reply-to-hint {
            font-size: 0.8rem;
            color: var(--text-secondary);
            padding: 0.1rem 0.25rem 0.3rem;
        }
        .reply-to-hint a {
            color: var(--accent-color);
            cursor: pointer;
            text-decoration: none;
        }
        .reply-to-hint a:hover {
            text-decoration: underline;
        }
        .comment-actions { position: relative; display: inline-block; }
        .comment-menu-button {
            background: none; border: none; font-size: 1.2rem; cursor: pointer;
            color: var(--text-secondary); padding: 0.2rem 0.5rem; border-radius: 0;
            transition: background 0.2s;
        }
        .comment-menu-button:hover { background: var(--bg-secondary); color: var(--text-primary); }
        .comment-menu-dropdown {
            position: absolute; top: 100%; right: 0; background: var(--bg-primary);
            border: 1px solid var(--border-color); border-radius: 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); min-width: 120px; z-index: 1000; display: none;
        }
        .comment-menu-dropdown.show { display: block; }
        .comment-menu-dropdown .menu-item {
            padding: 0.5rem 1rem; font-size: 0.85rem; display: block; width: 100%;
            text-align: left; background: none; border: none; cursor: pointer; color: var(--text-primary);
        }
        .comment-menu-dropdown .menu-item:hover { background: var(--bg-secondary); }
        .comment-menu-dropdown .menu-item.delete { color: #e53e3e; }
        @media (max-width: 768px) {
            .detail-header { padding: 0.8rem 1rem; }
            .detail-back { font-size: 1.6rem; width: 36px; height: 36px; }
            .detail-title { font-size: 1.1rem; }
            .main-comment-card, .replies-section, .reply-form-container { padding: 0.6rem; }
            .reply-item[data-depth="1"] { margin-left: 0.5rem; }
            .reply-item[data-depth="2"] { margin-left: 1rem; }
            .reply-item[data-depth="3"] { margin-left: 1.5rem; }
        }
        @media (max-width: 480px) {
            .detail-back { font-size: 1.5rem; width: 32px; height: 32px; }
            .main-comment-card { padding: 0.4rem 0.6rem 0; }
            .replies-section { padding: 0 0.5rem 0; }
            .reply-form-container { padding: 0.4rem 0.6rem; }
            .reply-item[data-depth="1"] { margin-left: 0.3rem; }
            .reply-item[data-depth="2"] { margin-left: 0.6rem; }
            .reply-item[data-depth="3"] { margin-left: 0.9rem; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <div id="page-content">
    <main class="main-content">
        <div class="detail-header-wrapper">
            <div class="detail-header">
                <a href="#" data-nav-url="<?php echo url('post', ['id' => $postId]); ?>#comments" data-tab="home" class="detail-back">←</a>
                <h2 class="detail-title">评论详情页</h2>
                <span class="detail-placeholder"></span>
            </div>
        </div>

        <div class="comment-detail-container">
            <div class="main-comment-card">
                <div class="main-comment">
                    <div class="main-comment-header">
                        <div class="comment-author">
                            <a href="<?php echo url('user', ['id' => $mainComment['user_id']]); ?>">
                                <?php echo getUserAvatarHtml($mainComment, 'comment-avatar'); ?>
                                <div class="comment-user-info">
                                    <div class="comment-username">
                                        <?php if ($mainComment['is_top'] ?? 0): ?>
                                            <span class="comment-top-badge"> 置顶</span>
                                        <?php endif; ?>
                                        <?php echo escape($mainComment['username']); ?>
                                        <?php echo $mainBadgeHtml; ?>
                                        <?php echo getLevelBadgeSmHtml($mainComment['exp'] ?? 0); ?>
                                    </div>
                                    <div class="comment-time"><?php echo date('Y-m-d H:i', strtotime($mainComment['created_at'])); ?></div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="comment-content">
                        <?php echo nl2br(escape($mainComment['content'])); ?>
                    </div>
                    <?php 
                    $mainCommentImages = getCommentImages($mainComment['id']);
                    if (empty($mainCommentImages) && !empty($mainComment['image_url'])) {
                        $mainCommentImages = [$mainComment['image_url']];
                    }
                    if (!empty($mainCommentImages)): ?>
                        <div class="comment-image-container">
                            <?php foreach ($mainCommentImages as $img): ?>
                            <img src="<?php echo getImageUrl(escape($img)); ?>" 
                                 alt="评论图片"
                                 class="comment-image"
                                 onclick="viewCommentImage('<?php echo getImageUrl(escape($img)); ?>')">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="comment-stats">
                        <div class="comment-stats-left"></div>
                        <div class="comment-stats-right">
                            <div class="comment-like-stat">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path>
                                </svg>
                                <span><?php echo $mainComment['like_count']; ?></span>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="replies-section">
                <h3 class="section-title">
                    回复列表
                    <span class="replies-count">(<?php echo count($replies); ?> 条)</span>
                </h3>
                <?php if (empty($replies)): ?>
                    <div class="no-replies"><p>暂无回复,快来发表第一条回复吧!</p></div>
                <?php else: ?>
                    <div class="replies-list">
                        <?php
                        $depthMap = [];
                        $replyItems = [];
                        foreach ($replies as $reply) $replyItems[$reply['id']] = $reply;
                        foreach ($replyItems as $id => $reply) {
                            $depth = 1;
                            $pid = $reply['parent_id'];
                            while ($pid != $commentId && isset($replyItems[$pid])) {
                                $depth++;
                                $pid = $replyItems[$pid]['parent_id'];
                            }
                            $depthMap[$id] = $depth;
                        }
                        foreach ($replies as $reply):
                            $liked = in_array($reply['id'], $userLikedReplies);
                            $depth = isset($depthMap[$reply['id']]) ? $depthMap[$reply['id']] : 1;

                            $replyBadgeHtml = '';
                            if (!empty($reply['is_banned'])) {
                                $replyBadgeHtml = '<span class="user-badge badge-banned">封禁</span>';
                            } elseif (!empty($reply['is_founder'])) {
                                $replyBadgeHtml = '<span class="user-badge badge-founder">站长</span>';
                            } elseif (!empty($reply['is_admin'])) {
                                $replyBadgeHtml = '<span class="user-badge badge-admin">管理员</span>';
                            }

                            // 构建回复头像 HTML
                            ?>
                            <div class="reply-item" id="reply-<?php echo $reply['id']; ?>" data-depth="<?php echo $depth; ?>">
                                <div class="reply-header">
                                    <div class="reply-user">
                                        <a href="<?php echo url('user', ['id' => $reply['user_id']]); ?>">
                                            <?php echo getUserAvatarHtml($reply, 'reply-avatar'); ?>
                                            </div>
                                            <div class="reply-user-info">
                                                <div class="reply-username">
                                                    <?php echo escape($reply['username']); ?>
                                                    <?php echo $replyBadgeHtml; ?>
                                                    <?php echo getLevelBadgeSmHtml($reply['exp'] ?? 0); ?>
                                                </div>
                                                <div class="reply-time"><?php echo date('Y-m-d H:i', strtotime($reply['created_at'])); ?></div>
                                            </div>
                                        </a>
                                    </div>
                                </div>
                                <div class="reply-content">
                                    <?php echo nl2br(escape($reply['content'])); ?>
                                </div>
                                <?php 
                                $replyImages = getCommentImages($reply['id']);
                                if (empty($replyImages) && !empty($reply['image_url'])) {
                                    $replyImages = [$reply['image_url']];
                                }
                                if (!empty($replyImages)): ?>
                                    <div class="reply-image-container">
                                        <?php foreach ($replyImages as $rimg): ?>
                                        <img src="<?php echo getImageUrl(escape($rimg)); ?>"
                                             alt="回复图片"
                                             class="reply-image"
                                             onclick="viewCommentImage('<?php echo getImageUrl(escape($rimg)); ?>')">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="reply-footer">
                                    <div class="reply-footer-left">
                                        <a href="javascript:void(0)" class="reply-link" onclick="setReplyTo(<?php echo $reply['id']; ?>, '<?php echo escape($reply['username']); ?>')">
                                            回复
                                        </a>
                                        <div class="reply-like <?php echo $liked ? 'liked' : ''; ?>"
                                             onclick="likeReply(<?php echo $reply['id']; ?>, this)"
                                             data-reply-id="<?php echo $reply['id']; ?>">
                                            <svg width="16" height="16" viewBox="0 0 24 24"
                                                 fill="<?php echo $liked ? '#e53e3e' : 'none'; ?>"
                                                 stroke="currentColor" stroke-width="2">
                                                <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path>
                                            </svg>
                                            <span class="reply-like-count"><?php echo $reply['like_count']; ?></span>
                                        </div>
                                    </div>
                                    <?php if ($canManageComments): ?>
                                    <div class="comment-actions">
                                        <button class="comment-menu-button" id="reply-menu-btn-<?php echo $reply['id']; ?>" onclick="toggleReplyMenu(<?php echo $reply['id']; ?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:18px;height:18px;vertical-align:-3px"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
                                        <div class="comment-menu-dropdown" id="reply-menu-<?php echo $reply['id']; ?>">
                                            <form method="POST" onsubmit="return confirm('确定要删除这条回复吗?')" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="action" value="delete_comment">
                                                <input type="hidden" name="comment_id" value="<?php echo $reply['id']; ?>">
                                                <input type="hidden" name="post_id" value="<?php echo $postId; ?>">
                                                <button type="submit" class="menu-item delete">删除</button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (isset($replyError)): ?>
                <div class="alert alert-error" style="margin: 0.5rem 0.75rem; max-width: 420px; margin-left: auto; margin-right: auto;"><?php echo escape($replyError); ?></div>
            <?php endif; ?>
            <div class="reply-bar" id="replyBar">
                <div id="replyBarProgress" class="upload-progress">
                    <div class="upload-progress-bar"></div>
                </div>
                <form method="POST" enctype="multipart/form-data" id="replyForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="add_reply">
                    <input type="hidden" name="parent_id" id="parent_id" value="<?php echo $commentId; ?>">
                    <div id="replyToHint" class="reply-to-hint" style="display: none;"></div>
                    <div class="reply-bar-inner">
                        <label class="reply-bar-image-btn" id="replyImageBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <polyline points="21 15 16 10 5 21"></polyline>
                            </svg>
                            <input type="file" name="reply_image" id="replyImage" accept="image/*">
                        </label>
                        <input type="text" name="content" class="reply-bar-input"
                               placeholder="回复 <?php echo escape($mainComment['username']); ?>..." required id="replyInput">
                        <button type="submit" class="reply-bar-send" id="replySendBtn">发送</button>
                    </div>
                    <div class="reply-bar-preview" id="replyImagePreview"></div>
                </form>
            </div>
        </div>
    </main>

    <?php include 'auth_modal.php'; ?>

    <div id="imageModal" class="image-modal">
        <span class="modal-close" onclick="closeImageViewer()">&times;</span>
        <div class="image-wrapper" id="imageWrapper">
            <img id="modalImage" src="" alt="查看图片">
        </div>
    </div>

    <script>
    const csrfToken = '<?php echo $csrfToken; ?>';

    const replyImageInput = document.getElementById('replyImage');
    const replyBarProgress = document.getElementById('replyBarProgress');
    const MAX_REPLY_IMAGES = 9;
    let uploadingReplyImage = false;
    let uploadedReplyImageUrls = [];

    function renderReplyImagePreviews() {
        const preview = document.getElementById('replyImagePreview');
        preview.innerHTML = uploadedReplyImageUrls.map((url, i) => 
            `<div class="preview-wrap"><img src="${url}"><button class="preview-remove" onclick="removeReplyImage(${i})">×</button></div>`
        ).join('');
        const btn = document.getElementById('replyImageBtn');
        if (btn) btn.style.display = uploadedReplyImageUrls.length >= MAX_REPLY_IMAGES ? 'none' : '';
    }

    replyImageInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        if (uploadedReplyImageUrls.length >= MAX_REPLY_IMAGES) { alert('最多只能上传' + MAX_REPLY_IMAGES + '张图片！'); return; }
        if (!file.type.startsWith('image/')) { alert('请选择图片文件!'); return; }
        if (file.size > 5 * 1024 * 1024) { alert('图片大小不能超过5MB!'); return; }

        replyBarProgress.style.display = 'block';
        const bar = replyBarProgress.querySelector('.upload-progress-bar');
        bar.style.width = '0%';

        uploadingReplyImage = true;

        uploadFile(file, '/post_actions.php', function(formData) {
            formData.append('action', 'upload_image');
            formData.append('image', file);
            formData.append('csrf_token', csrfToken);
        }, function(percent) {
            bar.style.width = percent + '%';
        }, function(response) {
            uploadedReplyImageUrls.push(response.url);
            replyBarProgress.style.display = 'none';
            renderReplyImagePreviews();
            uploadingReplyImage = false;
            replyImageInput.value = '';
        }, function(errorMsg) {
            replyBarProgress.style.display = 'none';
            alert('上传失败:' + errorMsg);
            uploadingReplyImage = false;
            replyImageInput.value = '';
        });
    });

    function removeReplyImage(index) {
        uploadedReplyImageUrls.splice(index, 1);
        renderReplyImagePreviews();
        replyImageInput.value = '';
    }

    document.getElementById('replyForm').addEventListener('submit', function(e) {
        if (uploadingReplyImage) {
            e.preventDefault();
            alert('请等待图片上传完成后再提交回复。');
            return;
        }
        if (uploadedReplyImageUrls.length > 0) {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'reply_image_urls';
            hiddenInput.value = JSON.stringify(uploadedReplyImageUrls);
            this.appendChild(hiddenInput);
        }
    });

    // ===== 图片查看器(与帖子页一致) =====
    const imageModal = document.getElementById('imageModal');
    const modalImage = document.getElementById('modalImage');
    const imageWrapper = document.getElementById('imageWrapper');

    let currentScale = 1;
    let currentTranslateX = 0;
    let currentTranslateY = 0;
    let baseWidth = 0;
    let baseHeight = 0;
    let initialPinchDistance = 0;
    let initialScale = 1;
    let pinchMidX = 0;
    let pinchMidY = 0;
    let pinchOffsetX = 0;
    let pinchOffsetY = 0;
    let isDragging = false;
    let lastTouchX = 0;
    let lastTouchY = 0;
    let startTranslateX = 0;
    let startTranslateY = 0;

    function applyTransform() {
        modalImage.style.transform = `translate(${currentTranslateX}px, ${currentTranslateY}px) scale(${currentScale})`;
    }

    function resetImageView() {
        currentScale = 1;
        currentTranslateX = 0;
        currentTranslateY = 0;
        applyTransform();
    }

    function updateInitialPosition() {
        const rect = modalImage.getBoundingClientRect();
        currentTranslateX = rect.left;
        currentTranslateY = rect.top;
        currentScale = 1;
        baseWidth = rect.width;
        baseHeight = rect.height;
        applyTransform();
    }

    function viewCommentImage(imageUrl) {
        modalImage.style.transform = '';
        modalImage.src = imageUrl;
        imageModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        modalImage.onload = function() {
            updateInitialPosition();
        };
        if (modalImage.complete) {
            updateInitialPosition();
        }
    }

    function closeImageViewer() {
        imageModal.style.display = 'none';
        document.body.style.overflow = '';
        resetImageView();
        modalImage.onload = null;
    }

    imageModal.addEventListener('click', function(e) {
        if (e.target === imageModal || e.target.classList.contains('modal-close')) {
            closeImageViewer();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && imageModal.style.display === 'flex') {
            closeImageViewer();
        }
    });

    imageWrapper.addEventListener('touchstart', function(e) {
        const touches = e.touches;
        if (touches.length === 2) {
            e.preventDefault();
            isDragging = false;

            const dx = touches[0].clientX - touches[1].clientX;
            const dy = touches[0].clientY - touches[1].clientY;
            initialPinchDistance = Math.sqrt(dx * dx + dy * dy);
            initialScale = currentScale;

            pinchMidX = (touches[0].clientX + touches[1].clientX) / 2;
            pinchMidY = (touches[0].clientY + touches[1].clientY) / 2;

            const rect = modalImage.getBoundingClientRect();
            const imgWidth = rect.width;
            const imgHeight = rect.height;
            if (imgWidth > 0 && imgHeight > 0) {
                pinchOffsetX = (pinchMidX - rect.left) / imgWidth;
                pinchOffsetY = (pinchMidY - rect.top) / imgHeight;
            } else {
                pinchOffsetX = 0.5;
                pinchOffsetY = 0.5;
            }
        } else if (touches.length === 1 && currentScale > 1) {
            isDragging = true;
            lastTouchX = touches[0].clientX;
            lastTouchY = touches[0].clientY;
            startTranslateX = currentTranslateX;
            startTranslateY = currentTranslateY;
        }
    }, { passive: false });

    imageWrapper.addEventListener('touchmove', function(e) {
        const touches = e.touches;
        if (touches.length === 2) {
            e.preventDefault();
            const dx = touches[0].clientX - touches[1].clientX;
            const dy = touches[0].clientY - touches[1].clientY;
            const newDist = Math.sqrt(dx * dx + dy * dy);

            if (initialPinchDistance > 0) {
                let newScale = initialScale * (newDist / initialPinchDistance);
                newScale = Math.min(Math.max(newScale, 0.5), 5);

                const newMidX = (touches[0].clientX + touches[1].clientX) / 2;
                const newMidY = (touches[0].clientY + touches[1].clientY) / 2;

                const newTranslateX = newMidX - pinchOffsetX * baseWidth * newScale;
                const newTranslateY = newMidY - pinchOffsetY * baseHeight * newScale;

                currentScale = newScale;
                currentTranslateX = newTranslateX;
                currentTranslateY = newTranslateY;
                applyTransform();
            }
        } else if (touches.length === 1 && isDragging && currentScale > 1) {
            e.preventDefault();
            const deltaX = touches[0].clientX - lastTouchX;
            const deltaY = touches[0].clientY - lastTouchY;
            currentTranslateX = startTranslateX + deltaX;
            currentTranslateY = startTranslateY + deltaY;
            applyTransform();
        }
    }, { passive: false });

    imageWrapper.addEventListener('touchend', function(e) {
        if (e.touches.length < 2) {
            initialPinchDistance = 0;
        }
        if (e.touches.length === 0) {
            isDragging = false;
        }
    });

    modalImage.addEventListener('contextmenu', function(e) { e.preventDefault(); });

    function toggleReplyMenu(replyId) {
        const menu = document.getElementById('reply-menu-' + replyId);
        if (menu) {
            document.querySelectorAll('[id^="reply-menu-"]').forEach(m => {
                if (m.id !== 'reply-menu-' + replyId) m.classList.remove('show');
            });
            menu.classList.toggle('show');
        }
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.comment-menu-button') && !e.target.closest('.comment-menu-dropdown')) {
            document.querySelectorAll('[id^="reply-menu-"]').forEach(m => m.classList.remove('show'));
        }
    });

    function likeReply(replyId, element) {
        <?php if (!isLoggedIn()): ?> showAuthModal(true); return; <?php endif; ?>
        event.stopPropagation();
        const formData = new FormData();
        formData.append('action', 'like_reply');
        formData.append('reply_id', replyId);
        formData.append('csrf_token', csrfToken);
        const svg = element.querySelector('svg');
        const countSpan = element.querySelector('.reply-like-count');
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    if (result.liked) {
                        element.classList.add('liked');
                        svg.setAttribute('fill', '#e53e3e');
                    } else {
                        element.classList.remove('liked');
                        svg.setAttribute('fill', 'none');
                    }
                    countSpan.textContent = result.like_count;
                } else {
                    alert(result.message);
                }
            });
    }

    function setReplyTo(replyId, username) {
        const parentIdInput = document.getElementById('parent_id');
        const replyInput = document.getElementById('replyInput');
        const hintDiv = document.getElementById('replyToHint');
        parentIdInput.value = replyId;
        replyInput.value = '@' + username + ' ';
        replyInput.placeholder = '回复 @' + escapeHtml(username) + '...';
        hintDiv.innerHTML = '正在回复 <strong>' + escapeHtml(username) + '</strong> <a href="javascript:void(0)" onclick="resetReplyTo()">[取消]</a>';
        hintDiv.style.display = 'block';
        replyInput.focus();
    }

    function resetReplyTo() {
        const parentIdInput = document.getElementById('parent_id');
        const replyInput = document.getElementById('replyInput');
        const hintDiv = document.getElementById('replyToHint');
        parentIdInput.value = <?php echo $commentId; ?>;
        replyInput.value = '';
        replyInput.placeholder = '回复 <?php echo escape($mainComment['username']); ?>...';
        hintDiv.style.display = 'none';
    }

    function escapeHtml(text) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    </script>
    </div><!-- /page-content -->
    <?php include 'spa.php'; ?>
</body>
</html>