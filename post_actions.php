<?php
// post_actions.php - 帖子操作处理器
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

// 只处理POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '请求方法错误']);
    exit();
}

// 验证CSRF令牌
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF令牌验证失败']);
    exit();
}

// 检查用户是否登录（除查看帖子外的大部分操作都需要登录）
$action = $_POST['action'] ?? '';
$needLogin = !in_array($action, ['get_post', 'get_comments']); // 只有查看帖子和评论不需要登录

if ($needLogin && !isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit();
}

$response = ['success' => false, 'message' => '未知操作'];

try {
    $pdo = getDbConnection();
    
    switch ($action) {
        case 'like_post':
            $postId = intval($_POST['post_id'] ?? 0);
            if ($postId <= 0) {
                throw new Exception('帖子ID错误');
            }
            
            $userId = $_SESSION['user_id'];
            $result = togglePostLike($postId, $userId);
            
            $response = [
                'success' => true,
                'liked' => $result['liked'],
                'message' => $result['message']
            ];
            break;
            
        case 'set_top':
            // 只有管理员可以置顶帖子
            if (!isAdmin()) {
                throw new Exception('权限不足');
            }
            
            $postId = intval($_POST['post_id'] ?? 0);
            $isTop = intval($_POST['is_top'] ?? 0);
            
            if ($postId <= 0) {
                throw new Exception('帖子ID错误');
            }
            
            if (setPostTop($postId, $isTop)) {
                $response = [
                    'success' => true,
                    'message' => $isTop ? '帖子已置顶' : '帖子已取消置顶'
                ];
            } else {
                throw new Exception('操作失败');
            }
            break;
            
        case 'set_approval':
            // 只有管理员可以审核帖子
            if (!isAdmin()) {
                throw new Exception('权限不足');
            }
            
            $postId = intval($_POST['post_id'] ?? 0);
            $isApproved = intval($_POST['is_approved'] ?? 0);
            
            if ($postId <= 0) {
                throw new Exception('帖子ID错误');
            }
            
            if (setPostApproval($postId, $isApproved)) {
                $response = [
                    'success' => true,
                    'message' => $isApproved ? '帖子已通过审核' : '帖子已取消审核'
                ];
            } else {
                throw new Exception('操作失败');
            }
            break;
            
        case 'delete_post':
            $postId = intval($_POST['post_id'] ?? 0);
            if ($postId <= 0) {
                throw new Exception('帖子ID错误');
            }
            
            // 获取帖子信息
            $post = getPostById($postId);
            if (!$post) {
                throw new Exception('帖子不存在');
            }
            
            // 检查是否有权限删除：管理员 或 帖子作者本人
            $currentUserId = $_SESSION['user_id'];
            $isAuthor = ($currentUserId == $post['user_id']);
            if (!isAdmin() && !$isAuthor) {
                throw new Exception('权限不足');
            }
            
            // 防止删除站长的帖子
            $author = getUserById($post['user_id']);
            if ($author && $author['is_founder'] && !isFounder($currentUserId)) {
                throw new Exception('不能删除站长的帖子');
            }
            
            // 执行删除
            if (deletePost($postId)) {
                $response = [
                    'success' => true,
                    'message' => '帖子删除成功'
                ];
            } else {
                throw new Exception('删除失败');
            }
            break;
            
        case 'add_comment':
            $postId = intval($_POST['post_id'] ?? 0);
            $content = trim($_POST['content'] ?? '');
            
            if ($postId <= 0) {
                throw new Exception('帖子ID错误');
            }
            
            if (empty($content)) {
                throw new Exception('评论内容不能为空');
            }
            
            if (mb_strlen($content, 'UTF-8') > 1000) {
                throw new Exception('评论内容不能超过1000字');
            }
            
            $commentData = [
                'post_id' => $postId,
                'user_id' => $_SESSION['user_id'],
                'content' => $content
            ];
            
            $result = addComment($commentData);
            $response = [
                'success' => $result['success'],
                'message' => $result['message'],
                'comment_id' => $result['comment_id'] ?? 0
            ];
            break;
            
        case 'delete_comment':
            // 只有管理员可以删除评论
            if (!isAdmin()) {
                throw new Exception('权限不足');
            }
            
            $commentId = intval($_POST['comment_id'] ?? 0);
            if ($commentId <= 0) {
                throw new Exception('评论ID错误');
            }
            
            // 删除评论
            $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
            if ($stmt->execute([$commentId])) {
                $response = [
                    'success' => true,
                    'message' => '评论删除成功'
                ];
            } else {
                throw new Exception('删除失败');
            }
            break;
            
        case 'like_comment':
            $commentId = intval($_POST['comment_id'] ?? 0);
            if ($commentId <= 0) {
                throw new Exception('评论ID错误');
            }
            
            $userId = $_SESSION['user_id'];
            
            // 检查是否已经点赞
            $stmt = $pdo->prepare("SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ?");
            $stmt->execute([$commentId, $userId]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // 删除点赞
                $stmt = $pdo->prepare("DELETE FROM comment_likes WHERE id = ?");
                $stmt->execute([$existing['id']]);
                
                // 更新评论点赞数
                $stmt = $pdo->prepare("UPDATE comments SET like_count = like_count - 1 WHERE id = ?");
                $stmt->execute([$commentId]);
                
                $response = [
                    'success' => true,
                    'liked' => false,
                    'message' => '取消点赞成功'
                ];
            } else {
                // 添加点赞
                $stmt = $pdo->prepare("INSERT INTO comment_likes (comment_id, user_id) VALUES (?, ?)");
                $stmt->execute([$commentId, $userId]);
                
                // 更新评论点赞数
                $stmt = $pdo->prepare("UPDATE comments SET like_count = like_count + 1 WHERE id = ?");
                $stmt->execute([$commentId]);
                
                $response = [
                    'success' => true,
                    'liked' => true,
                    'message' => '点赞成功'
                ];
            }
            break;
            
        case 'toggle_favorite':
            $postId = intval($_POST['post_id'] ?? 0);
            if ($postId <= 0) {
                throw new Exception('帖子ID错误');
            }
            
            $userId = $_SESSION['user_id'];
            $result = togglePostFavorite($postId, $userId);
            
            $response = [
                'success' => $result['success'],
                'favorited' => $result['favorited'] ?? false,
                'message' => $result['message'],
                'favorite_count' => $result['favorite_count'] ?? 0
            ];
            break;

        case 'batch_unfavorite':
            $idsJson = $_POST['ids'] ?? '[]';
            $ids = json_decode($idsJson, true);
            if (empty($ids) || !is_array($ids)) {
                $response = ['success' => false, 'message' => '参数错误'];
                break;
            }
            $userId = $_SESSION['user_id'];
            $count = 0;
            try {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("DELETE FROM favorites WHERE post_id = ? AND user_id = ?");
                foreach ($ids as $pid) {
                    $pid = intval($pid);
                    if ($pid > 0) {
                        $stmt->execute([$pid, $userId]);
                        if ($stmt->rowCount() > 0) $count++;
                    }
                }
                $response = ['success' => true, 'deleted' => $count, 'message' => "成功删除 $count 个收藏"];
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => '删除失败: ' . $e->getMessage()];
            }
            break;
            
        case 'get_post':
            // 获取帖子详情（用于AJAX加载）
            $postId = intval($_POST['post_id'] ?? 0);
            if ($postId <= 0) {
                throw new Exception('帖子ID错误');
            }
            
            $post = getPostById($postId);
            if (!$post) {
                throw new Exception('帖子不存在');
            }
            
            $response = [
                'success' => true,
                'post' => $post,
                'is_admin' => isAdmin(),
                'can_edit' => (isAdmin() || (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $post['user_id']))
            ];
            break;
            
        case 'get_comments':
            // 获取评论列表（用于AJAX加载）
            $postId = intval($_POST['post_id'] ?? 0);
            $page = intval($_POST['page'] ?? 1);
            $perPage = 20;
            
            if ($postId <= 0) {
                throw new Exception('帖子ID错误');
            }
            
            $comments = getPostComments($postId, $page, $perPage);
            $commentCount = getCommentCount($postId);
            
            $response = [
                'success' => true,
                'comments' => $comments,
                'total_count' => $commentCount,
                'total_pages' => ceil($commentCount / $perPage),
                'current_page' => $page
            ];
            break;
            
        case 'upload_image':
            // 处理图片上传（用于富文本编辑器等）
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('文件上传失败');
            }
            
            $uploadResult = uploadFile($_FILES['image'], 'image');
            if ($uploadResult['success']) {
                $response = [
                    'success' => true,
                    'url' => $uploadResult['file_url'],
                    'message' => '图片上传成功'
                ];
            } else {
                throw new Exception($uploadResult['message']);
            }
            break;
            
        case 'search_posts':
            // 搜索帖子
            $keyword = trim($_POST['keyword'] ?? '');
            $categoryId = intval($_POST['category_id'] ?? 0);
            $page = intval($_POST['page'] ?? 1);
            $perPage = 15;
            
            if (empty($keyword)) {
                throw new Exception('请输入搜索关键词');
            }
            
            $offset = ($page - 1) * $perPage;
            $params = [];
            
            // 构建查询条件
            $where = "p.is_approved = 1";
            
            if ($categoryId > 0) {
                $where .= " AND p.category_id = ?";
                $params[] = $categoryId;
            }
            
            // 使用全文搜索或LIKE搜索
            $keywordLike = "%{$keyword}%";
            $where .= " AND (p.title LIKE ? OR p.content LIKE ? OR u.username LIKE ?)";
            $params[] = $keywordLike;
            $params[] = $keywordLike;
            $params[] = $keywordLike;
            
            // 查询帖子
            $sql = "SELECT p.*, u.username, u.avatar, c.name as category_name 
                    FROM posts p 
                    LEFT JOIN users u ON p.user_id = u.id 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE {$where} 
                    ORDER BY p.created_at DESC 
                    LIMIT ?, ?";
            
            $params[] = $offset;
            $params[] = $perPage;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $posts = $stmt->fetchAll();
            
            // 查询总数
            $countSql = "SELECT COUNT(*) as count 
                        FROM posts p 
                        LEFT JOIN users u ON p.user_id = u.id 
                        WHERE {$where}";
            
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute(array_slice($params, 0, count($params) - 2));
            $total = $countStmt->fetch()['count'];
            
            $response = [
                'success' => true,
                'posts' => $posts,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
                'current_page' => $page
            ];
            break;

        // ==================== 新增：发送打赏 ====================
        case 'send_tip':
            $postId = intval($_POST['post_id'] ?? 0);
            $amount = intval($_POST['amount'] ?? 0);
            
            if ($postId <= 0) {
                throw new Exception('帖子ID错误');
            }
            
            if ($amount <= 0) {
                throw new Exception('打赏积分必须大于0');
            }
            
            // 获取帖子信息，确认作者
            $post = getPostById($postId);
            if (!$post) {
                throw new Exception('帖子不存在');
            }
            
            $fromUserId = $_SESSION['user_id'];
            $toUserId = $post['user_id'];
            
            if ($fromUserId == $toUserId) {
                throw new Exception('不能给自己打赏');
            }
            
            $result = sendTip($fromUserId, $toUserId, $postId, $amount);
            
            $response = [
                'success' => $result['success'],
                'message' => $result['message']
            ];
            break;

        // ==================== 新增：移动帖子到其他分类（管理员/站长） ====================
        case 'move_post':
            // 只有管理员或站长可以移动帖子
            if (!isAdmin() && !isFounder()) {
                throw new Exception('权限不足，只有管理员可以移动帖子');
            }
            
            $postId = intval($_POST['post_id'] ?? 0);
            $targetSlug = trim($_POST['target_slug'] ?? '');
            
            if ($postId <= 0) {
                throw new Exception('帖子ID错误');
            }
            
            if (empty($targetSlug)) {
                throw new Exception('请选择目标分类');
            }
            
            // 验证目标分类是否有效
            $validSlugs = ['mod', 'exchange', 'chat'];
            if (!in_array($targetSlug, $validSlugs)) {
                throw new Exception('无效的目标分类');
            }
            
            $result = movePostToCategory($postId, $targetSlug);
            
            $response = [
                'success' => $result['success'],
                'message' => $result['message'],
                'redirect' => $result['success'] ? url('post', ['id' => $postId]) : null
            ];
            break;
            
        default:
            $response = ['success' => false, 'message' => '未知操作类型'];
            break;
    }
    
} catch (PDOException $e) {
    error_log('Database error in post_actions.php: ' . $e->getMessage());
    $response = ['success' => false, 'message' => '数据库错误：' . $e->getMessage()];
} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response);
exit();
?>