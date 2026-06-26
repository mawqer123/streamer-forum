<?php
// post.php - 帖子详情页面（统一上传逻辑，支持评论置顶，支持打赏积分，支持图片双指缩放）
require_once __DIR__ . '/functions.php';

// 获取当前用户信息（用于主题）
$currentUserForTheme = getCurrentUser();

// 检查帖子ID
$postId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($postId <= 0) {
    show_error_page('参数错误', '帖子ID无效，请返回首页浏览其他内容。', url('index'));
}

// 获取帖子信息
$post = getPostById($postId);
if (!$post) {
    show_error_page('帖子不存在', '您访问的帖子不存在或已被删除。', url('index'));
}

// 检查帖子审核状态
if (!$post['is_approved'] && (!isLoggedIn() || (!isAdmin() && $_SESSION['user_id'] != $post['user_id']))) {
    show_error_page('审核中', '帖子正在审核中，暂时无法查看！', url('index'));
}

// 增加浏览量
incrementPostView($postId);

// 获取帖子相关信息
$postImages = getPostImages($postId);
$currentUserId = $currentUser ? $currentUser['id'] : null;
$comments = getPostComments($postId, 1, 20, $currentUserId);
$commentCount = getCommentCount($postId);
$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);
$hasLiked = $currentUser ? hasUserLikedPost($postId, $currentUser['id']) : false;
$isBanned = $currentUser ? isCurrentUserBanned() : false;

// 获取收藏状态和数量
$favoriteCount = getPostFavoriteCount($postId);
$isFavorited = ($currentUser && isPostFavorited($postId, $currentUser['id']));

// 检查当前用户是否关注了帖子作者
$isFollowingAuthor = false;
if ($currentUser && $currentUser['id'] != $post['user_id']) {
    $isFollowingAuthor = isFollowing($currentUser['id'], $post['user_id']);
}

// 检查当前用户是否有编辑/删除权限
$isAuthor = ($currentUser && $currentUser['id'] == $post['user_id']);
$canEdit = isAdmin() || $isAuthor;
$canDelete = isAdmin() || $isAuthor;
$canManageComments = isAdmin() || $isAuthor;

// 获取打赏记录
$tipRecords = getTipRecords($postId);
$totalTips = getPostTotalTips($postId);

// 预加载当前用户对评论的点赞状态
$userLikedComments = [];
if ($currentUser) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT comment_id FROM comment_likes WHERE user_id = ?");
        $stmt->execute([$currentUser['id']]);
        $userLikedComments = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
}

// 处理 POST 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('CSRF令牌验证失败！');
    }
    
    if (!isLoggedIn()) {
        $errorMessage = '请先登录后再操作！';
    } elseif ($isBanned && in_array($_POST['action'], ['add_comment', 'like_comment', 'like_post', 'toggle_follow_author', 'toggle_favorite', 'send_tip'])) {
        $errorMessage = '您的账号已被封禁，无法进行操作。';
    } else {
        switch ($_POST['action']) {
            case 'add_comment':
                $content = trim($_POST['content'] ?? '');
                if (empty($content)) {
                    $commentError = '评论内容不能为空！';
                } else {
                    $imageUrls = [];
                    // Handle AJAX-uploaded image URLs (JSON array)
                    if (!empty($_POST['comment_image_urls'])) {
                        $decoded = json_decode($_POST['comment_image_urls'], true);
                        if (is_array($decoded)) {
                            $imageUrls = array_slice($decoded, 0, 9);
                        }
                    }
                    // Handle direct file upload
                    if (isset($_FILES['comment_image']) && $_FILES['comment_image']['error'] === UPLOAD_ERR_OK) {
                        if (count($imageUrls) < 9) {
                            $uploadResult = uploadFile($_FILES['comment_image'], 'comment');
                            if ($uploadResult['success']) {
                                $imageUrls[] = $uploadResult['file_url'];
                            } else {
                                $commentError = $uploadResult['message'];
                                break;
                            }
                        }
                    }
                    
                    $commentData = [
                        'post_id' => $postId,
                        'user_id' => $currentUser['id'],
                        'content' => $content,
                        'image_url' => !empty($imageUrls) ? $imageUrls[0] : null,
                        'image_urls' => $imageUrls
                    ];
                    $result = addComment($commentData);
                    if ($result['success']) {
                        redirect(url('post', ['id' => $postId]) . '#comments');
                    } else {
                        $commentError = $result['message'];
                    }
                }
                break;
                
            case 'delete_comment':
                $commentId = intval($_POST['comment_id'] ?? 0);
                if ($commentId <= 0) { $commentError = '评论ID无效'; break; }
                if (!$canManageComments) { $commentError = '没有权限删除此评论'; break; }
                $comment = getCommentById($commentId);
                if (!$comment || $comment['post_id'] != $postId) { $commentError = '评论不存在'; break; }
                if (deleteComment($commentId)) {
                    redirect(url('post', ['id' => $postId]) . '#comments');
                } else {
                    $commentError = '删除失败，请稍后重试';
                }
                break;

            case 'set_comment_top':
                $commentId = intval($_POST['comment_id'] ?? 0);
                $isTop = intval($_POST['is_top'] ?? 0);
                if ($commentId <= 0) { echo json_encode(['success' => false, 'message' => '评论ID无效']); exit; }
                if (!$canManageComments) { echo json_encode(['success' => false, 'message' => '没有权限']); exit; }
                $comment = getCommentById($commentId);
                if (!$comment || $comment['post_id'] != $postId) { echo json_encode(['success' => false, 'message' => '评论不存在']); exit; }
                if ($comment['parent_id'] !== null) { echo json_encode(['success' => false, 'message' => '只有顶级评论可以置顶']); exit; }
                if (setCommentTop($commentId, $isTop)) {
                    echo json_encode(['success' => true, 'is_top' => $isTop]);
                } else {
                    echo json_encode(['success' => false, 'message' => '操作失败']);
                }
                exit;

            case 'like_comment':
                $commentId = intval($_POST['comment_id'] ?? 0);
                if ($commentId <= 0) { echo json_encode(['success' => false]); exit; }
                $result = toggleCommentLike($commentId, $currentUser['id']);
                $comment = getCommentById($commentId);
                echo json_encode([
                    'success' => true,
                    'liked' => $result['liked'],
                    'like_count' => $comment ? $comment['like_count'] : 0
                ]);
                exit;

            case 'toggle_follow_author':
                $result = toggleFollow($currentUser['id'], $post['user_id']);
                echo json_encode($result);
                exit;

            case 'toggle_favorite':
                $result = togglePostFavorite($postId, $currentUser['id']);
                echo json_encode($result);
                exit;

            case 'send_tip':
                $amount = intval($_POST['amount'] ?? 0);
                if ($amount <= 0) { echo json_encode(['success' => false, 'message' => '打赏积分必须大于0']); exit; }
                $result = sendTip($currentUser['id'], $post['user_id'], $postId, $amount);
                echo json_encode($result);
                exit;
        }
    }
}

$csrfToken = generateCsrfToken();

// 帖子作者身份标签
$postAuthorBadgeHtml = '';
if (!empty($post['is_banned'])) {
    $postAuthorBadgeHtml = '<span class="user-badge badge-banned">封禁</span>';
} elseif (!empty($post['is_founder'])) {
    $postAuthorBadgeHtml = '<span class="user-badge badge-founder">站长</span>';
} elseif (!empty($post['is_admin'])) {
    $postAuthorBadgeHtml = '<span class="user-badge badge-admin">管理员</span>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo escape($post['title']); ?> - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/cropper.min.css">
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
        /* ===== 帖子详情页特有样式 ===== */
        #top-bar { display: none !important; }
        #bottom-bar { display: none !important; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { margin: 0 !important; padding: 0 !important; background-color: var(--bg-secondary); }
        .main-content { margin: 0 !important; padding: 0 !important; min-height: 100vh; }
        .post-container { margin: 0 !important; padding: 0 !important; }

        .post-detail-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            height: 56px;
            background-color: var(--accent-color);
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .post-detail-header .back-arrow {
            font-size: 1.8rem; line-height: 1; color: white; text-decoration: none;
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; transition: background-color 0.2s;
        }
        .post-detail-header .back-arrow:hover { background-color: rgba(255,255,255,0.2); }
        .post-detail-header .header-title { font-size: 1.2rem; font-weight: 600; color: white; flex: 1; text-align: center; margin: 0; }
        .post-detail-header .menu-button {
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; cursor: pointer; transition: background-color 0.2s;
            color: white; font-size: 1.5rem; user-select: none; position: relative;
        }
        .post-detail-header .menu-button:hover { background-color: rgba(255,255,255,0.2); }
        .menu-dropdown {
            position: absolute; top: 100%; right: 0; background: var(--bg-primary);
            border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.15); min-width: 160px;
            display: none; z-index: 1000; overflow: hidden; border: 1px solid var(--border-color);
        }
        .menu-dropdown.show { display: block; }
        .menu-item {
            display: block; padding: 0.75rem 1rem; color: var(--text-primary);
            text-decoration: none; font-size: 0.9rem; border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s; cursor: pointer; text-align: left; width: 100%;
            background: none; border: none;
        }
        .menu-item:hover { background-color: var(--link-hover-bg); }
        .menu-item.delete { color: #e53e3e; }

        .post-content-card { background: transparent; padding: 0.5rem 2rem 2rem; margin-bottom: 2rem; }
        .post-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.3rem; flex-wrap: wrap; gap: 1rem; }
        .post-title-section { flex: 1; min-width: 0; }
        .post-meta {
            display: flex; justify-content: space-between; align-items: center;
            color: var(--text-secondary); font-size: 0.9rem; flex-wrap: wrap; gap: 0.5rem 1rem;
            border-top: 1px solid var(--border-color); padding-top: 0.8rem; margin-top: 0.5rem;
        }
        .post-author { display: flex; align-items: flex-start; gap: 0.5rem; white-space: nowrap; }
        .post-author-link { text-decoration: none; color: inherit; display: flex; align-items: flex-start; gap: 0.2rem; }
        .post-author-info { display: flex; align-items: flex-start; gap: 0.5rem; }
        .post-author-body { display: flex; flex-direction: column; }
        .post-author-name-row { display: flex; align-items: center; gap: 0.1rem; flex-wrap: wrap; }
        .post-author-name { font-weight: 600; color: var(--text-primary); font-size: 0.85rem; }
        .post-author-time { font-size: 0.75rem; color: var(--text-secondary); margin-top: 1px; }
        /* 帖子头部标签紧贴用户名 */
        .post-author-name-row .user-badge { margin-left: 0; font-size: 0.55rem; padding: 0.08rem 0.4rem; line-height: 1.3; }
        .post-author-name-row .level-badge { margin-left: 0; font-size: 0.55rem; padding: 0.08rem 0.35rem; line-height: 1.3; border: none; }
        .post-title-row {
            display: flex; align-items: center; gap: 0.6rem;
        }
        .post-title-wrap {
            flex: 1; min-width: 0; overflow-x: auto; white-space: nowrap; scrollbar-width: none;
        }
        .post-title-wrap::-webkit-scrollbar { display: none; }
        .post-title {
            color: var(--text-primary); font-size: 1.5rem; font-weight: 600; line-height: 1.3;
            margin: 0; white-space: nowrap;
        }
        .post-title-sticky {
            flex-shrink: 0;
        }
        .post-status { display: flex; gap: 0.5rem; margin-bottom: 0.3rem; }
        .status-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.8rem; font-weight: 500; }
        .status-top { background-color: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }
        .status-pending { background-color: #e6f7ff; color: #0066cc; border: 1px solid #91d5ff; }

        /* 关键修复：覆盖发布时插入的内联缩略图样式，恢复原图比例，限制在容器内 */
        .post-body img {
            max-width: 100% !important;
            max-height: none !important;
            height: auto !important;
            border-radius: 0;
            margin: 1rem 0;
            cursor: pointer;
        }
        .attachments-section { margin-bottom: 2rem; }
        .section-title { color: var(--text-primary); font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid var(--border-color); }
        
        .tip-section { margin: 1rem 0; padding: 1.5rem; background: var(--bg-primary); border-radius: 0; box-shadow: var(--card-shadow); }
        .tip-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem; }
        .tip-title { font-size: 1.1rem; font-weight: 600; color: var(--text-primary); }
        .tip-total { font-size: 1.2rem; font-weight: 700; color: var(--accent-color); }
        .tip-button {
            background: #f59e0b; color: white; border: none;
            padding: 0.6rem 1.5rem; border-radius: 8px; font-size: 0.95rem; font-weight: 600;
            cursor: pointer; transition: opacity 0.2s, transform 0.2s; box-shadow: 0 2px 8px rgba(245,158,11,0.3);
        }
        .tip-button:hover { opacity: 0.9; transform: translateY(-1px); }
        .tip-button:disabled { opacity: 0.5; cursor: not-allowed; }
        .tip-modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center;
        }
        .tip-modal {
            background: var(--bg-primary); border-radius: 0; padding: 1.5rem; max-width: 400px;
            width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .tip-modal h3 { margin-bottom: 1rem; color: var(--text-primary); }
        .tip-amount-input {
            width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: 0;
            font-size: 1rem; margin-bottom: 1rem; background: var(--bg-secondary); color: var(--text-primary);
        }
        .tip-presets { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
        .tip-preset {
            flex: 1; padding: 0.5rem; background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 0; cursor: pointer; text-align: center; font-weight: 500; color: var(--text-primary);
        }
        .tip-preset:hover { border-color: var(--accent-color); }
        .tip-modal-actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem; }
        .tip-records { margin-top: 1rem; max-height: 200px; overflow-y: auto; border-top: 1px solid var(--border-color); padding-top: 1rem; }
        .tip-record-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px dashed var(--border-color); }
        .tip-record-avatar {
            width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--accent-gradient-from), var(--accent-gradient-to));
            color: white; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; overflow: hidden;
        }
        .tip-record-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .tip-record-info { flex: 1; }
        .tip-record-name { font-weight: 600; color: var(--text-primary); font-size: 0.9rem; }
        .tip-record-time { font-size: 0.75rem; color: var(--text-secondary); }
        .tip-record-amount { font-weight: 700; color: #f59e0b; }
        .tip-empty { text-align: center; color: var(--text-secondary); padding: 1rem 0; }

        .comments-section { background: transparent; padding: 2rem; }

        /* ===== 底部固定评论栏 ===== */
        .main-content { padding-bottom: 60px !important; }
        .comment-bar {
            position: fixed;
            bottom: 0;
            z-index: 1000;
            margin: 0 auto;
            left: 0;
            right: 0;
            background: var(--bg-primary);
            border-top: 1px solid var(--border-color);
            padding: 0.5rem 0.75rem;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        .comment-bar-inner {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .comment-bar-input {
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
        .comment-bar-input:focus {
            border-color: var(--accent-color);
        }
        .comment-bar-send {
            padding: 0.55rem 1.2rem;
            background: var(--accent-gradient-from);
            color: white;
            border: none;
            font-size: 0.95rem;
            cursor: pointer;
            white-space: nowrap;
            font-weight: 500;
        }
        .comment-bar-send:disabled {
            opacity: 0.6;
            cursor: wait;
        }
        .comment-bar-image-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem;
            cursor: pointer;
            color: var(--text-secondary);
            background: none;
            border: none;
        }
        .comment-bar-image-btn svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }
        .comment-bar-image-btn:hover {
            color: var(--accent-color);
        }
        .comment-bar-image-btn input[type="file"] { display: none; }
        .comment-bar-preview { padding: 0.25rem 0; display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .comment-bar .upload-progress {
            display: none;
            height: 4px;
            background: var(--bg-secondary);
            margin-bottom: 0.25rem;
        }
        .comment-bar .upload-progress-bar {
            height: 100%;
            background: var(--accent-color);
            width: 0%;
            transition: width 0.3s;
        }

        .comments-list { display: flex; flex-direction: column; gap: 0; }
        .comment-item { border-bottom: 1px solid var(--border-color); padding: 0 0 0.5rem 0; position: relative; }
        .comment-top-badge {
            display: inline-block; background: #fbbf24; color: #1a202c; padding: 0.2rem 0.6rem;
            border-radius: 4px; font-size: 0.75rem; font-weight: 600; margin-right: 0.5rem;
        }
        .comment-top-right {
            margin-left: auto;
            margin-right: 0;
            flex-shrink: 0;
        }
        .comment-header { display: flex; justify-content: flex-start; align-items: center; margin-bottom: 0.75rem; flex-wrap: nowrap; gap: 0.5rem; }
        .comment-author { display: flex; align-items: flex-start; gap: 0.5rem; min-width: 0; flex-shrink: 1; }
        .comment-author a { text-decoration: none; color: inherit; display: flex; align-items: center; gap: 0.2rem; }
        .comment-user-info { display: flex; flex-direction: column; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .comment-username {
            font-weight: 600; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis;
            white-space: nowrap; display: flex; align-items: center; gap: 0.15rem;
            font-size: 0.85rem;
        }
        .comment-time { color: var(--text-secondary); font-size: 0.75rem; }
        .comment-avatar { line-height: normal !important; }
        /* 评论中标签紧贴用户名 */
        .comment-username .user-badge { margin-left: 0; font-size: 0.55rem; padding: 0.08rem 0.4rem; line-height: 1.3; }
        .comment-username .level-badge-sm { margin-left: 0; font-size: 0.55rem; padding: 0.08rem 0.35rem; line-height: 1.3; border: none; }
        /* 评论上传预览图片 — 小正方形 + 边角框架 */
        .image-preview {
            margin-top: 0.5rem;
        }
        .preview-wrap {
            position: relative;
            display: inline-block;
            width: 72px;
            height: 72px;
        }
        .preview-wrap .preview-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border: 2px solid var(--border-color);
            display: block;
        }
        .preview-remove {
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
        .comment-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 0.5rem; }
        .comment-footer-left { display: flex; align-items: center; gap: 1rem; }
        .comment-actions { position: relative; display: inline-block; }
        .comment-menu-button {
            background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-secondary);
            padding: 0.2rem 0.5rem; border-radius: 4px; transition: background 0.2s;
        }
        .comment-menu-button:hover { background: var(--bg-secondary); color: var(--text-primary); }
        .comment-menu-dropdown {
            position: absolute; top: 100%; right: 0; background: var(--bg-primary);
            border: 1px solid var(--border-color); border-radius: 0; box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            min-width: 120px; z-index: 1000; display: none;
        }
        .comment-menu-dropdown.show { display: block; }
        .comment-menu-dropdown .menu-item { padding: 0.5rem 1rem; font-size: 0.85rem; }
        .comment-menu-dropdown .menu-item.delete { color: #e53e3e; }



        .post-meta .follow-btn {
            padding: 0.3rem 1rem;
            font-size: 0.8rem;
            border-radius: 0;
            line-height: 1.2;
            flex-shrink: 0;
            white-space: nowrap;
        }
        .meta-top {
            flex-shrink: 0;
            margin: 0;
            padding: 0.2rem 0.5rem;
            font-size: 0.75rem;
            line-height: 1.3;
        }

        @media (max-width: 768px) {
            .post-content-card, .comments-section { padding: 1rem; }
            .post-title { font-size: 1.3rem; }
        }
        @media (max-width: 480px) {
            .post-title { font-size: 1.2rem; }
        }

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
            top: 0;
            left: 0;
        }
        /* 移动帖子模态框样式 */
        .move-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .move-modal {
            background: var(--bg-primary);
            border-radius: 0;
            padding: 1.5rem;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .move-modal h3 {
            margin-top: 0;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }
        .move-category-wrapper {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .move-category-trigger {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 1rem;
            font-family: inherit;
            cursor: pointer;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .move-category-trigger:hover {
            border-color: var(--accent-color);
        }
        .move-category-trigger.active {
            border-color: var(--accent-color);
        }
        .move-category-label {
            flex: 1;
            text-align: left;
        }
        .move-category-arrow {
            flex-shrink: 0;
            transition: transform 0.25s ease;
            color: var(--text-secondary);
        }
        .move-category-trigger.active .move-category-arrow {
            transform: rotate(180deg);
        }
        .move-modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <?php $currentTab = 'home'; $hideTopBar = true; $hideBottomBar = true; include __DIR__ . '/header.php'; ?>
    <div id="page-content">
    <main class="main-content">
        <div class="post-container">
            <div class="post-detail-header">
                <a href="#" data-nav-url="<?php echo url('category', ['slug' => $post['category_slug']]); ?>" data-tab="home" class="back-arrow">←</a>
                <span class="header-title">帖子详情</span>
                <?php if ($canEdit || isAdmin() || isFounder()): ?>
                <div class="menu-button" id="menuToggle" onclick="toggleMenu()">⋮</div>
                <div class="menu-dropdown" id="adminMenu">
                    <?php if ($canEdit): ?>
                    <a href="<?php echo url('create_post', [], ['id' => $postId]); ?>" data-nav-url="<?php echo url('create_post', [], ['id' => $postId]); ?>" data-tab="home" class="menu-item">编辑</a>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                    <button class="menu-item delete" onclick="deletePost(<?php echo $postId; ?>)">删除帖子</button>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <button class="menu-item" onclick="setPostTop(<?php echo $postId; ?>, <?php echo $post['is_top'] ? '0' : '1'; ?>)"><?php echo $post['is_top'] ? '取消置顶' : '设为置顶'; ?></button>
                    <button class="menu-item" onclick="setPostApproval(<?php echo $postId; ?>, <?php echo $post['is_approved'] ? '0' : '1'; ?>)"><?php echo $post['is_approved'] ? '取消审核' : '通过审核'; ?></button>
                    <?php endif; ?>
                    <?php if (isAdmin() || isFounder()): ?>
                    <button class="menu-item" onclick="openMoveModal()">移动帖子</button>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div style="width: 40px;"></div>
                <?php endif; ?>
            </div>
            
            <div class="post-content-card">
                <div class="post-header">
                    <div class="post-title-section">
                        <div class="post-title-row">
                            <div class="post-title-wrap">
                                <h1 class="post-title"><?php echo escape($post['title']); ?></h1>
                            </div>
                            <?php if ($post['is_top']): ?><span class="status-badge status-top meta-top post-title-sticky"> 置顶</span><?php endif; ?>
                        </div>
                        <div class="post-meta">
                            <div class="post-author">
                                <div class="post-author-info">
                                    <a href="#" data-nav-url="<?php echo url('user', ['id' => $post['user_id']]); ?>" data-tab="profile" class="post-author-link">
                                        <?php echo getUserAvatarHtml($post, 'post-avatar'); ?>
                                        <div class="post-author-body">
                                            <div class="post-author-name-row">
                                                <span class="post-author-name"><?php echo escape($post['username']); ?></span>
                                                <?php echo $postAuthorBadgeHtml; ?>
                                                <?php echo getLevelBadgeHtml($post['exp'] ?? 0); ?>
                                            </div>
                                            <div class="post-author-time"><?php echo date('Y-m-d H:i', strtotime($post['created_at'])); ?></div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                            <?php if (isLoggedIn() && $currentUser['id'] != $post['user_id']): ?>
                                <button class="follow-btn <?php echo $isFollowingAuthor ? 'following' : ''; ?>" id="followAuthorBtn" onclick="toggleFollowAuthor(<?php echo $post['user_id']; ?>)" <?php echo $isBanned ? 'disabled' : ''; ?>><?php echo $isFollowingAuthor ? '已关注' : '+ 关注'; ?></button>
                            <?php elseif (!isLoggedIn()): ?>
                                <button class="follow-btn" onclick="showAuthModal(true)">+ 关注</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="post-status">
                    <?php if (!$post['is_approved']): ?><span class="status-badge status-pending">⏳ 审核中</span><?php endif; ?>
                </div>
                
                <div class="post-body"><?php echo $post['content']; ?></div>
                
                <?php if (!empty($postImages)): ?>
                    <div class="attachments-section">
                        <h3 class="section-title">帖子图片</h3>
                        <div class="post-images-grid">
                            <?php foreach ($postImages as $index => $image): ?>
                                <div class="post-image-item" onclick="viewImage(<?php echo $index; ?>)">
                                    <img src="<?php echo getImageUrl(escape($image['image_url'])); ?>" alt="图片">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($post['attachment_name'])): ?>
                    <div class="attachments-section">
                        <h3 class="section-title">附件下载</h3>
                        <div class="file-card">
                            <div class="file-info">
                                <div class="file-name"><?php echo escape($post['attachment_name']); ?></div>
                                <div class="file-size"><?php echo formatFileSize($post['attachment_size']); ?></div>
                            </div>
                            <a href="<?php echo getImageUrl($post['attachment_path']); ?>" download="<?php echo escape($post['attachment_name']); ?>" class="download-btn">下载</a>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="post-footer">
                    <div class="post-stats">
                        <div class="post-stat" style="cursor: default;">
                            <svg class="stat-icon view-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <?php echo $post['view_count']; ?>
                        </div>
                        <div class="post-stat" onclick="window.location.href='#comments'">
                            <svg class="stat-icon comment-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <?php echo $commentCount; ?>
                        </div>
                        <div class="post-stat" onclick="likePost(<?php echo $postId; ?>, this)">
                            <svg class="stat-icon like-icon <?php echo $hasLiked ? 'liked' : ''; ?>" viewBox="0 0 24 24" fill="<?php echo $hasLiked ? '#e53e3e' : 'none'; ?>" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>
                            <span id="like-count-<?php echo $postId; ?>"><?php echo $post['like_count']; ?></span>
                        </div>
                        <div class="post-stat" onclick="toggleFavorite(<?php echo $postId; ?>, this)">
                            <svg class="stat-icon favorite-icon <?php echo $isFavorited ? 'favorited' : ''; ?>" viewBox="0 0 24 24" fill="<?php echo $isFavorited ? '#fbbf24' : 'none'; ?>" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.07 5.82 22 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <span id="favorite-count-<?php echo $postId; ?>"><?php echo $favoriteCount; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="tip-section">
                    <div class="tip-header">
                        <span class="tip-title"> 打赏记录</span>
                        <span class="tip-total">共 <?php echo $totalTips; ?> 积分</span>
                        <?php if (isLoggedIn() && !$isBanned && $currentUser['id'] != $post['user_id']): ?>
                            <button class="tip-button" id="openTipModalBtn"> 打赏积分</button>
                        <?php elseif (!isLoggedIn()): ?>
                            <button class="tip-button" onclick="showAuthModal(true)"> 登录后打赏</button>
                        <?php elseif ($currentUser['id'] == $post['user_id']): ?>
                            <button class="tip-button" disabled title="不能打赏自己"> 打赏积分</button>
                        <?php endif; ?>
                    </div>
                    <div class="tip-records" id="tipRecords">
                        <?php if (empty($tipRecords)): ?>
                            <div class="tip-empty">暂无打赏记录</div>
                        <?php else: ?>
                            <?php foreach ($tipRecords as $record): ?>
                                <div class="tip-record-item">
                                    <div class="tip-record-avatar">
                                        <?php if (!empty($record['avatar'])): ?>
                                            <img src="<?php echo getImageUrl(escape($record['avatar'])); ?>" alt="avatar">
                                        <?php else: ?>
                                            <?php echo mb_substr(escape($record['username']), 0, 1, 'UTF-8'); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tip-record-info">
                                        <div class="tip-record-name"><?php echo escape($record['username']); ?></div>
                                        <div class="tip-record-time"><?php echo date('m-d H:i', strtotime($record['created_at'])); ?></div>
                                    </div>
                                    <div class="tip-record-amount">+<?php echo $record['amount']; ?> 积分</div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="comments-section" id="comments">
                <h3 class="section-title">评论（<?php echo $commentCount; ?>）</h3>
                <?php if (isset($commentError)): ?>
                    <div class="alert alert-error"><?php echo escape($commentError); ?></div>
                <?php endif; ?>
                
                <?php if (isLoggedIn() && !$isBanned): ?>
                    <div class="comment-bar" id="commentBar">
                        <div id="commentBarProgress" class="upload-progress">
                            <div class="upload-progress-bar"></div>
                        </div>
                        <form method="POST" enctype="multipart/form-data" id="commentForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="add_comment">
                            <div class="comment-bar-inner">
                                <label class="comment-bar-image-btn" id="commentImageBtn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    <input type="file" name="comment_image" id="commentImage" accept="image/*">
                                </label>
                                <input type="text" name="content" class="comment-bar-input"
                                       placeholder="请输入您的评论..." required id="commentInput">
                                <button type="submit" class="comment-bar-send" id="commentSendBtn">发送</button>
                            </div>
                            <div class="comment-bar-preview" id="commentImagePreview"></div>
                        </form>
                    </div>
                <?php elseif (!isLoggedIn()): ?>
                    <div style="text-align: center; padding: 1.5rem;"><button class="btn-primary" onclick="showAuthModal(true)">立即登录</button></div>
                <?php else: ?>
                    <div style="text-align: center; padding: 1.5rem;"><p>您的账号已被封禁，无法评论。</p></div>
                <?php endif; ?>
                
                <div class="comments-list">
                    <?php if (empty($comments)): ?>
                        <div class="empty-state">暂无评论</div>
                    <?php else: ?>
                        <?php foreach ($comments as $comment): 
                            $liked = in_array($comment['id'], $userLikedComments);
                            $isTop = $comment['is_top'] ?? 0;
                            $commentBadgeHtml = '';
                            if (!empty($comment['is_banned'])) $commentBadgeHtml = '<span class="user-badge badge-banned">封禁</span>';
                            elseif (!empty($comment['is_founder'])) $commentBadgeHtml = '<span class="user-badge badge-founder">站长</span>';
                            elseif (!empty($comment['is_admin'])) $commentBadgeHtml = '<span class="user-badge badge-admin">管理员</span>';
                        ?>
                            <div class="comment-item" id="comment-<?php echo $comment['id']; ?>">
                                <div class="comment-header">
                                    <div class="comment-author">
                                        <a href="#" data-nav-url="<?php echo url('user', ['id' => $comment['user_id']]); ?>" data-tab="profile">
                                            <?php echo getUserAvatarHtml($comment, 'comment-avatar'); ?>
                                            <div class="comment-user-info">
                                                <div class="comment-username">
                                                    <?php echo escape($comment['username']); ?>
                                                    <?php echo $commentBadgeHtml; ?>
                                                    <?php echo getLevelBadgeSmHtml($comment['exp'] ?? 0); ?>
                                                </div>
                                                <div class="comment-time"><?php echo date('Y-m-d H:i', strtotime($comment['created_at'])); ?></div>
                                            </div>
                                        </a>
                                    </div>
                                    <?php if ($isTop): ?><span class="comment-top-badge comment-top-right"> 置顶</span><?php endif; ?>
                                </div>
                                <div class="comment-content"><?php echo nl2br(escape($comment['content'])); ?></div>
                                <?php 
                                $commentImages = getCommentImages($comment['id']);
                                if (empty($commentImages) && !empty($comment['image_url'])) {
                                    $commentImages = [$comment['image_url']];
                                }
                                if (!empty($commentImages)): ?>
                                    <div class="post-images-grid">
                                        <?php foreach ($commentImages as $img): ?>
                                        <div class="post-image-item" onclick="viewImageUrl('<?php echo getImageUrl(escape($img)); ?>')">
                                            <img src="<?php echo getImageUrl(escape($img)); ?>" alt="评论图片">
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="comment-footer">
                                    <div class="comment-footer-left">
                                        <a href="#" data-nav-url="<?php echo url('reply_comment', [], ['post_id' => $postId, 'comment_id' => $comment['id']]); ?>" data-tab="home" class="reply-link">回复<?php echo $comment['reply_count'] > 0 ? ' (' . $comment['reply_count'] . ')' : ''; ?></a>
                                        <div class="comment-like <?php echo $liked ? 'liked' : ''; ?>" onclick="likeComment(<?php echo $comment['id']; ?>, this)">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="<?php echo $liked ? '#e53e3e' : 'none'; ?>" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>
                                            <span><?php echo $comment['like_count']; ?></span>
                                        </div>
                                    </div>
                                    <?php if ($canManageComments): ?>
                                    <div class="comment-actions">
                                        <button class="comment-menu-button" onclick="toggleCommentMenu(<?php echo $comment['id']; ?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:18px;height:18px;vertical-align:-3px"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
                                        <div class="comment-menu-dropdown" id="comment-menu-<?php echo $comment['id']; ?>">
                                            <?php if ($comment['parent_id'] === null): ?>
                                            <button class="menu-item" onclick="toggleCommentTop(<?php echo $comment['id']; ?>, <?php echo $isTop ? '0' : '1'; ?>)"><?php echo $isTop ? '取消置顶' : '置顶'; ?></button>
                                            <?php endif; ?>
                                            <form method="POST" onsubmit="return confirm('确定删除？')">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="action" value="delete_comment">
                                                <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                                <button type="submit" class="menu-item delete">删除</button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <div class="tip-modal-overlay" id="tipModal">
        <div class="tip-modal">
            <h3>打赏给 <?php echo escape($post['username']); ?></h3>
            <p>您的积分：<strong><?php echo $currentUser ? getUserPoints($currentUser['id']) : 0; ?></strong></p>
            <input type="number" id="tipAmountInput" class="tip-amount-input" min="1" value="5">
            <div class="tip-presets">
                <div class="tip-preset" data-amount="5">5</div>
                <div class="tip-preset" data-amount="10">10</div>
                <div class="tip-preset" data-amount="20">20</div>
                <div class="tip-preset" data-amount="50">50</div>
            </div>
            <div class="tip-modal-actions">
                <button class="btn-secondary" onclick="document.getElementById('tipModal').style.display='none'">取消</button>
                <button class="btn-primary" id="confirmTipBtn">确认打赏</button>
            </div>
            <div id="tipError" style="color: #e53e3e;"></div>
        </div>
    </div>
    
    <!-- 移动帖子模态框 -->
    <div class="move-modal-overlay" id="moveModal">
        <div class="move-modal">
            <h3>移动帖子到其他讨论区</h3>
            <?php
                $catOptions = [
                    ['value' => 'mod', 'label' => 'Mod 专区'],
                    ['value' => 'exchange', 'label' => '交流专区'],
                    ['value' => 'chat', 'label' => '闲聊专区']
                ];
                $currentSlug = $post['category_slug'];
                $currentCatLabel = '';
                foreach ($catOptions as $opt) {
                    if ($opt['value'] === $currentSlug) { $currentCatLabel = $opt['label']; break; }
                }
            ?>
            <div class="move-category-wrapper">
                <button type="button" class="move-category-trigger" onclick="toggleMoveCategoryDropdown(event)">
                    <span class="move-category-label"><?php echo $currentCatLabel; ?></span>
                    <svg class="move-category-arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
            </div>
            <input type="hidden" id="targetCategoryValue" value="<?php echo $currentSlug; ?>">
            <div class="move-modal-actions">
                <button class="btn-secondary" onclick="closeMoveModal()">取消</button>
                <button class="btn-primary" id="confirmMoveBtn">确认移动</button>
            </div>
            <div id="moveError" style="color: #e53e3e; margin-top: 0.5rem;"></div>
        </div>
    </div>
    
    <div id="imageModal" class="image-modal">
        <span class="modal-close" onclick="closeImageViewer()">&times;</span>
        <div class="image-wrapper" id="imageWrapper">
            <img id="modalImage" src="" alt="查看图片">
        </div>
    </div>
    
    <?php include 'auth_modal.php'; ?>
    
    <script>
    var csrfToken = '<?php echo $csrfToken; ?>';
    var postImages = <?php echo json_encode($postImages); ?>;
    var currentImageIndex = 0;
    
    function toggleMenu() { document.getElementById('adminMenu').classList.toggle('show'); }
    function toggleCommentMenu(id) {
        document.querySelectorAll('.comment-menu-dropdown').forEach(m => { if (m.id !== 'comment-menu-'+id) m.classList.remove('show'); });
        document.getElementById('comment-menu-'+id).classList.toggle('show');
    }
    function toggleCommentTop(id, isTop) {
        fetch(window.location.href, { method: 'POST', body: new URLSearchParams({action:'set_comment_top', comment_id:id, is_top:isTop, csrf_token:csrfToken}) })
        .then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert(d.message); });
    }
    function likePost(id, el) {
        fetch('/post_actions.php', { method: 'POST', body: new URLSearchParams({action:'like_post', post_id:id, csrf_token:csrfToken}) })
        .then(r=>r.json()).then(d=>{
            if(d.success) {
                let icon = el.querySelector('.like-icon');
                let cnt = document.getElementById('like-count-'+id);
                if(d.liked) { icon.classList.add('liked'); icon.setAttribute('fill','#e53e3e'); cnt.textContent = parseInt(cnt.textContent)+1; }
                else { icon.classList.remove('liked'); icon.setAttribute('fill','none'); cnt.textContent = parseInt(cnt.textContent)-1; }
            } else alert(d.message);
        });
    }
    function toggleFavorite(id, el) {
        fetch('/post_actions.php', { method: 'POST', body: new URLSearchParams({action:'toggle_favorite', post_id:id, csrf_token:csrfToken}) })
        .then(r=>r.json()).then(d=>{
            if(d.success) {
                let icon = el.querySelector('.favorite-icon');
                let cnt = document.getElementById('favorite-count-'+id);
                if(d.favorited) { icon.classList.add('favorited'); icon.setAttribute('fill','#fbbf24'); cnt.textContent = parseInt(cnt.textContent)+1; }
                else { icon.classList.remove('favorited'); icon.setAttribute('fill','none'); cnt.textContent = parseInt(cnt.textContent)-1; }
            } else alert(d.message);
        });
    }
    function likeComment(id, el) {
        fetch(window.location.href, { method: 'POST', body: new URLSearchParams({action:'like_comment', comment_id:id, csrf_token:csrfToken}) })
        .then(r=>r.json()).then(d=>{
            if(d.success) {
                let svg = el.querySelector('svg');
                let cnt = el.querySelector('span');
                if(d.liked) { el.classList.add('liked'); svg.setAttribute('fill','#e53e3e'); }
                else { el.classList.remove('liked'); svg.setAttribute('fill','none'); }
                cnt.textContent = d.like_count;
            }
        });
    }
    function toggleFollowAuthor(aid) {
        fetch(window.location.href, { method: 'POST', body: new URLSearchParams({action:'toggle_follow_author', csrf_token:csrfToken}) })
        .then(r=>r.json()).then(d=>{
            if(d.success) location.reload();
            else alert(d.message);
        });
    }
    function deletePost(id) {
        if(!confirm('确定删除？')) return;
        fetch('/post_actions.php', { method: 'POST', body: new URLSearchParams({action:'delete_post', post_id:id, csrf_token:csrfToken}) })
        .then(r=>r.json()).then(d=>{ if(d.success) navigateTo('<?php echo url('category', ['slug' => $post['category_slug']]); ?>' + '?_r='+Date.now(),'home'); else alert(d.message); });
    }
    function setPostTop(id, isTop) {
        fetch('/post_actions.php', { method: 'POST', body: new URLSearchParams({action:'set_top', post_id:id, is_top:isTop, csrf_token:csrfToken}) })
        .then(r=>r.json()).then(d=>{ if(d.success) navigateTo(window.location.href + (window.location.href.indexOf('?')>=0?'&':'?')+'_r='+Date.now(),'home'); else alert(d.message); });
    }
    function setPostApproval(id, isApproved) {
        fetch('/post_actions.php', { method: 'POST', body: new URLSearchParams({action:'set_approval', post_id:id, is_approved:isApproved, csrf_token:csrfToken}) })
        .then(r=>r.json()).then(d=>{ if(d.success) navigateTo(window.location.href + (window.location.href.indexOf('?')>=0?'&':'?')+'_r='+Date.now(),'home'); else alert(d.message); });
    }
    
    // 移动帖子相关函数
    function openMoveModal() {
        document.getElementById('moveModal').style.display = 'flex';
        document.getElementById('moveError').textContent = '';
    }
    function closeMoveModal() {
        document.getElementById('moveModal').style.display = 'none';
    }
    document.getElementById('confirmMoveBtn')?.addEventListener('click', function() {
        const targetSlug = document.getElementById('targetCategoryValue').value;
        const confirmBtn = this;
        confirmBtn.disabled = true;
        confirmBtn.textContent = '处理中...';
        document.getElementById('moveError').textContent = '';
        
        const formData = new URLSearchParams();
        formData.append('action', 'move_post');
        formData.append('post_id', <?php echo $postId; ?>);
        formData.append('target_slug', targetSlug);
        formData.append('csrf_token', csrfToken);
        
        fetch('/post_actions.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert(result.message);
                    if (result.redirect) {
                        navigateTo(result.redirect, 'home');
                    } else {
                        navigateTo(window.location.href + (window.location.href.indexOf('?')>=0?'&':'?')+'_r='+Date.now(), 'home');
                    }
                } else {
                    document.getElementById('moveError').textContent = result.message;
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = '确认移动';
                }
            })
            .catch(error => {
                document.getElementById('moveError').textContent = '网络错误，请重试';
                confirmBtn.disabled = false;
                confirmBtn.textContent = '确认移动';
            });
    });
    document.getElementById('moveModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeMoveModal();
    });
    
    var imageModal = document.getElementById('imageModal');
    var modalImage = document.getElementById('modalImage');
    var imageWrapper = document.getElementById('imageWrapper');
    
    var currentScale = 1;
    var currentTranslateX = 0;
    var currentTranslateY = 0;
    var baseWidth = 0;
    var baseHeight = 0;
    var initialPinchDistance = 0;
    var initialScale = 1;
    var pinchMidX = 0;
    var pinchMidY = 0;
    var pinchOffsetX = 0;
    var pinchOffsetY = 0;
    var isDragging = false;
    var lastTouchX = 0;
    var lastTouchY = 0;
    var startTranslateX = 0;
    var startTranslateY = 0;
    
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
    
    function openImageViewer(src) {
        modalImage.style.transform = '';
        modalImage.src = src;
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
    
    function viewImage(index) {
        if (postImages && postImages[index]) {
            openImageViewer(postImages[index].image_url);
        }
    }
    
    function viewImageUrl(url) {
        openImageViewer(url);
    }
    
    (function() {
        const postBody = document.querySelector('.post-body');
        if (postBody) {
            postBody.addEventListener('click', function(e) {
                const img = e.target.closest('img');
                if (img && img.src) {
                    e.preventDefault();
                    e.stopPropagation();
                    openImageViewer(img.src);
                }
            });
        }
    })();
    
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
    
    document.getElementById('openTipModalBtn')?.addEventListener('click', ()=> document.getElementById('tipModal').style.display='flex');
    document.querySelectorAll('.tip-preset').forEach(p=> p.addEventListener('click', ()=> document.getElementById('tipAmountInput').value = p.dataset.amount));
    document.getElementById('confirmTipBtn')?.addEventListener('click', ()=>{
        let amt = document.getElementById('tipAmountInput').value;
        fetch(window.location.href, { method: 'POST', body: new URLSearchParams({action:'send_tip', amount:amt, csrf_token:csrfToken}) })
        .then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else document.getElementById('tipError').textContent = d.message; });
    });
    
    var commentImageInput = document.getElementById('commentImage');
    var commentBarProgress = document.getElementById('commentBarProgress');
    var MAX_COMMENT_IMAGES = 9;
    var uploadingComment = false;
    var uploadedCommentImageUrls = [];
    
    function renderCommentImagePreviews() {
        const preview = document.getElementById('commentImagePreview');
        preview.innerHTML = uploadedCommentImageUrls.map((url, i) => 
            `<div class="preview-wrap"><img src="${url}" class="preview-image"><button class="preview-remove" onclick="removeCommentImage(${i})">×</button></div>`
        ).join('');
        const btn = document.getElementById('commentImageBtn');
        if (btn) btn.style.display = uploadedCommentImageUrls.length >= MAX_COMMENT_IMAGES ? 'none' : '';
    }
    
    commentImageInput?.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        if (uploadedCommentImageUrls.length >= MAX_COMMENT_IMAGES) { alert('最多只能上传' + MAX_COMMENT_IMAGES + '张图片！'); return; }
        if (!file.type.startsWith('image/')) { alert('请选择图片文件！'); return; }
        if (file.size > 5 * 1024 * 1024) { alert('图片大小不能超过5MB！'); return; }
        
        commentBarProgress.style.display = 'block';
        const bar = commentBarProgress.querySelector('.upload-progress-bar');
        bar.style.width = '0%';
        
        uploadingComment = true;
        
        uploadFile(file, '/post_actions.php', function(formData) {
            formData.append('action', 'upload_image');
            formData.append('image', file);
            formData.append('csrf_token', csrfToken);
        }, function(percent) {
            bar.style.width = percent + '%';
        }, function(response) {
            uploadedCommentImageUrls.push(response.url);
            commentBarProgress.style.display = 'none';
            renderCommentImagePreviews();
            uploadingComment = false;
            commentImageInput.value = '';
        }, function(errorMsg) {
            commentBarProgress.style.display = 'none';
            alert('上传失败：' + errorMsg);
            uploadingComment = false;
            commentImageInput.value = '';
        });
    });
    
    function removeCommentImage(index) {
        uploadedCommentImageUrls.splice(index, 1);
        renderCommentImagePreviews();
        commentImageInput.value = '';
    }
    
    document.getElementById('commentForm')?.addEventListener('submit', function(e) {
        if (uploadingComment) {
            e.preventDefault();
            alert('请等待图片上传完成后再提交评论。');
            return;
        }
        if (uploadedCommentImageUrls.length > 0) {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'comment_image_urls';
            hiddenInput.value = JSON.stringify(uploadedCommentImageUrls);
            this.appendChild(hiddenInput);
        }
    });
    
    document.addEventListener('click', e => { if(!e.target.closest('.menu-button') && !e.target.closest('.menu-dropdown')) document.getElementById('adminMenu')?.classList.remove('show'); });
    
    // 自定义移动分类下拉菜单
    var MOVE_CAT_OPTIONS = [
        { value: 'mod', label: 'Mod 专区' },
        { value: 'exchange', label: '交流专区' },
        { value: 'chat', label: '闲聊专区' }
    ];
    var moveCatMenuActive = false;
    var moveCatMenuElement = null;
    
    function buildMoveCatMenuItems() {
        var ns = 'http://www.w3.org/2000/svg';
        var currentVal = document.getElementById('targetCategoryValue').value;
        var items = [];
        MOVE_CAT_OPTIONS.forEach(function(opt) {
            var div = document.createElement('div');
            div.className = 'sort-dropdown-item' + (opt.value === currentVal ? ' active' : '');
            var span = document.createElement('span');
            span.className = 'sort-item-label';
            span.textContent = opt.label;
            div.appendChild(span);
            if (opt.value === currentVal) {
                var svg = document.createElementNS(ns, 'svg');
                svg.setAttribute('class', 'sort-check-icon');
                svg.setAttribute('viewBox', '0 0 24 24');
                svg.setAttribute('width', '14');
                svg.setAttribute('height', '14');
                svg.setAttribute('fill', 'none');
                svg.setAttribute('stroke', 'currentColor');
                svg.setAttribute('stroke-width', '2.5');
                svg.setAttribute('stroke-linecap', 'round');
                svg.setAttribute('stroke-linejoin', 'round');
                var polyline = document.createElementNS(ns, 'polyline');
                polyline.setAttribute('points', '20 6 9 17 4 12');
                svg.appendChild(polyline);
                div.appendChild(svg);
            }
            div.addEventListener('click', function(e) {
                e.stopPropagation();
                closeMoveCatMenu();
                document.getElementById('targetCategoryValue').value = opt.value;
                var label = document.querySelector('.move-category-label');
                if (label) label.textContent = opt.label;
            });
            items.push(div);
        });
        return items;
    }
    
    function openMoveCatMenu() {
        var trigger = document.querySelector('.move-category-trigger');
        if (!trigger) return;
        closeMoveCatMenu();
        var menu = document.createElement('div');
        menu.className = 'sort-dropdown-menu-fixed';
        var rect = trigger.getBoundingClientRect();
        menu.style.top = (rect.bottom + 4) + 'px';
        menu.style.left = rect.left + 'px';
        menu.style.minWidth = Math.max(rect.width, 140) + 'px';
        var items = buildMoveCatMenuItems();
        items.forEach(function(item) { menu.appendChild(item); });
        document.body.appendChild(menu);
        moveCatMenuElement = menu;
        moveCatMenuActive = true;
        trigger.classList.add('active');
        requestAnimationFrame(function() { menu.classList.add('open'); });
    }
    
    function closeMoveCatMenu() {
        if (moveCatMenuElement) {
            moveCatMenuElement.classList.remove('open');
            if (moveCatMenuElement.parentNode) moveCatMenuElement.parentNode.removeChild(moveCatMenuElement);
            moveCatMenuElement = null;
        }
        moveCatMenuActive = false;
        var trigger = document.querySelector('.move-category-trigger');
        if (trigger) trigger.classList.remove('active');
    }
    
    function toggleMoveCategoryDropdown(event) {
        event.stopPropagation();
        if (moveCatMenuActive) { closeMoveCatMenu(); } else { openMoveCatMenu(); }
    }
    
    // 关闭移动分类下拉的全局事件
    document.addEventListener('click', function(e) {
        if (!moveCatMenuActive) return;
        var trigger = document.querySelector('.move-category-trigger');
        if (trigger && !trigger.contains(e.target) && moveCatMenuElement && !moveCatMenuElement.contains(e.target)) {
            closeMoveCatMenu();
        }
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && moveCatMenuActive) closeMoveCatMenu();
    });
    window.addEventListener('scroll', function() {
        if (moveCatMenuActive) closeMoveCatMenu();
    }, true);
    window.addEventListener('resize', function() {
        if (moveCatMenuActive) closeMoveCatMenu();
    });
    </script>
    </div><!-- /page-content -->
    <?php include __DIR__ . '/bottom_nav.php'; ?>
    <?php include __DIR__ . '/spa.php'; ?>
</body>
</html>