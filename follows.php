<?php
// follows.php - 统一的关注/粉丝列表页面
require_once __DIR__ . '/functions.php';

$currentUserForTheme = getCurrentUser();

// 获取目标用户ID
$userId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($userId <= 0) {
    show_error_page('参数错误', '用户ID无效，请返回首页。', url('index'));
}

$targetUser = getUserById($userId);
if (!$targetUser) {
    show_error_page('用户不存在', '您访问的用户不存在或已被删除。', url('index'));
}

// 获取列表类型
$type = isset($_GET['type']) ? trim($_GET['type']) : 'following';
if (!in_array($type, ['following', 'followers'])) {
    $type = 'following';
}

// 分页参数
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);

// 根据类型调用不同的函数
if ($type === 'following') {
    $users = getFollowingUsers($userId, $page, $perPage);
    $total = getFollowingCount($userId);
    $title = $targetUser['username'] . ' 的关注';
    $emptyMsg = '暂无关注';
    $backUrl = ($currentUser && $userId == $currentUser['id']) ? url('profile') : url('user', ['id' => $userId]);
} else {
    $users = getFollowerUsers($userId, $page, $perPage);
    $total = getFollowerCount($userId);
    $title = $targetUser['username'] . ' 的粉丝';
    $emptyMsg = '暂无粉丝';
    $backUrl = ($currentUser && $userId == $currentUser['id']) ? url('profile') : url('user', ['id' => $userId]);
}

$totalPages = ceil($total / $perPage);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($title); ?> - 主播模拟器论坛</title>
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
        /* follows.php 特有样式 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { margin: 0 !important; padding: 0 !important; height: 100%; }
        body { background-color: var(--bg-secondary); color: var(--text-primary); transition: background-color 0.3s, color 0.3s; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .main-content { margin: 0 !important; padding: 0 !important; min-height: 100%; }



        .back-btn {
            font-size: 1.8rem; line-height: 1; color: white; text-decoration: none;
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; transition: background-color 0.2s; cursor: pointer;
        }
        .back-btn:hover { background-color: rgba(255,255,255,0.2); }


        .users-list {
            display: flex;
            flex-direction: column;
        }
        .user-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: inherit;
        }
        .avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--accent-gradient-from);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.95rem;
            overflow: hidden;
            line-height: 0;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .username {
            font-weight: 600;
            color: var(--text-primary);
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }
        @media (max-width: 768px) {
    
        @media (max-width: 480px) {
    
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <div id="page-content">

    <main class="main-content">
        <div class="container">
            <div class="users-list">
                <?php if (empty($users)): ?>
                    <div class="no-data"><?php echo htmlspecialchars($emptyMsg); ?></div>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <div class="user-item">
                            <a href="#" data-nav-url="<?php echo url('user', ['id' => $user['id']]); ?>" class="user-info">
                                <?php echo getUserAvatarHtml($user, 'avatar'); ?>
                                <span class="username"><?php echo escape($user['username']); ?></span>
                            </a>
                            <?php if ($currentUser && $currentUser['id'] != $user['id']): ?>
                                <button class="follow-btn <?php echo $user['is_followed_by_me'] ? 'following' : ''; ?>"
                                        data-user-id="<?php echo $user['id']; ?>"
                                        onclick="toggleFollow(this, <?php echo $user['id']; ?>)">
                                    <?php echo $user['is_followed_by_me'] ? '已关注' : '+ 关注'; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="#" data-nav-url="<?php echo url('follows', ['type' => $type, 'id' => $userId], ['page' => 1]); ?>">首页</a>
                                <a href="#" data-nav-url="<?php echo url('follows', ['type' => $type, 'id' => $userId], ['page' => $page - 1]); ?>">上一页</a>
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
                                <a href="#" data-nav-url="<?php echo url('follows', ['type' => $type, 'id' => $userId], ['page' => $i]); ?>"><?php echo $i; ?></a>
                            <?php endif; endfor; ?>
                            <?php if ($end < $totalPages) echo '<span>...</span>'; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="#" data-nav-url="<?php echo url('follows', ['type' => $type, 'id' => $userId], ['page' => $page + 1]); ?>">下一页</a>
                                <a href="#" data-nav-url="<?php echo url('follows', ['type' => $type, 'id' => $userId], ['page' => $totalPages]); ?>">尾页</a>
                            <?php else: ?>
                                <span class="disabled">下一页</span>
                                <span class="disabled">尾页</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script>
        function toggleFollow(btn, targetUserId) {
            <?php if (!isLoggedIn()): ?>
                showAuthModal(true);
                return;
            <?php endif; ?>

            btn.disabled = true;
            const formData = new FormData();
            formData.append('action', 'toggle_follow');
            formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            // 使用占位符动态生成正确的用户主页URL，兼容伪静态与动态模式
            const urlTemplate = '<?php echo url('user', ['id' => '__ID__']); ?>';
            fetch(urlTemplate.replace('__ID__', targetUserId), {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    if (result.following) {
                        btn.textContent = '已关注';
                        btn.classList.add('following');
                    } else {
                        btn.textContent = '+ 关注';
                        btn.classList.remove('following');
                    }
                } else {
                    alert(result.message);
                }
                btn.disabled = false;
            })
            .catch(error => {
                alert('网络错误，请稍后重试');
                btn.disabled = false;
            });
        }
    </script>
    </div><!-- /page-content -->
    <?php include 'auth_modal.php'; ?>
    <?php include 'spa.php'; ?>
</body>
</html>