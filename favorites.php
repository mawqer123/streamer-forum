<?php
$active_bottom = 'profile';
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    redirect(url('index'));
}

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);
$userId = $currentUser['id'];

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;
$favorites = getUserFavorites($userId, $page, $perPage);
$total = getUserFavoritesCount($userId);
$totalPages = ceil($total / $perPage);

$currentUserForLike = $currentUser;
$favoritedStatus = [];
try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT post_id FROM favorites WHERE user_id = ?");
    $stmt->execute([$userId]);
    $favorited = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $favoritedStatus = array_flip($favorited);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>我的收藏 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <?php
    if ($currentUser && isset($currentUser['theme']) && $currentUser['theme'] === 'custom' && !empty($currentUser['theme_settings'])) {
        $settings = $currentUser['theme_settings'];
        $primary = $settings['primary'] ?? '#2196F3';
        list($r, $g, $b) = sscanf($primary, "#%02x%02x%02x");
        $r = max(0, $r - 20);
        $g = max(0, $g - 20);
        $b = max(0, $b - 20);
        $to = sprintf("#%02x%02x%02x", $r, $g, $b);
        echo "<style data-page-style>:root{--accent-color:$primary;--accent-gradient-from:$primary;--accent-gradient-to:$to;}</style>";
    ?>
    <?php } ?>
    <style data-page-style>
        /* favorites.php 特有样式 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { margin: 0 !important; padding: 0 !important; height: 100%; }
        #top-bar { display: none !important; }
        body { background-color: var(--bg-secondary); color: var(--text-primary); transition: background-color 0.3s, color 0.3s; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .main-content { margin: 0 !important; padding: 0 !important; min-height: 100%; }



        .favorites-header-wrapper {
            background-color: var(--accent-color);
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            width: 100%;
        }
        .favorites-header {
            margin: 0 auto;
            padding: 0 1rem;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .favorites-back {
            font-size: 1.8rem; line-height: 1; color: white; text-decoration: none;
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; transition: background-color 0.2s; cursor: pointer;
        }
        .favorites-title { font-size: 1.2rem; font-weight: 600; color: white; margin: 0; flex: 1; text-align: center; }
        .favorites-action {
            width: 40px; height: 40px; background: transparent; border: none;
            color: white; font-size: 1rem; font-weight: 500; cursor: pointer;
            border-radius: 0; transition: background-color 0.2s; padding: 0;
        }
        .favorites-back:hover { background-color: rgba(255,255,255,0.2); }

        .favorites-action:hover { background-color: rgba(255,255,255,0.2); }
        .delete-bar {
            display: none; position: fixed; bottom: 0; left: 0; right: 0;
            background: var(--bg-primary); padding: 0.75rem 1rem;
            border-top: 1px solid var(--border-color); z-index: 100;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
            align-items: center; justify-content: space-between;
        }
        .delete-bar.show { display: flex; }
        .delete-bar .selected-count {
            font-size: 0.9rem; color: var(--text-secondary);
        }
        .delete-bar .delete-btn {
            background: #e53e3e; color: white; border: none;
            padding: 0.5rem 1.2rem; font-size: 0.9rem; cursor: pointer;
            border-radius: 0; font-weight: 500;
        }
        .delete-bar .delete-btn:disabled {
            background: var(--bg-secondary); color: var(--text-secondary);
            cursor: not-allowed;
        }
        .post-card-check {
            display: none; width: 24px; flex-shrink: 0;
            align-items: center; justify-content: center; margin-right: 0.5rem;
        }
        .post-card-check input {
            width: 18px; height: 18px; cursor: pointer;
            accent-color: var(--accent-color);
        }
        .editing .post-card-check { display: flex; }
        .editing .post-card { cursor: default; }
        .favorites-placeholder { width: 40px; }

.post-card {
            display: flex; align-items: stretch; gap: 0;
            text-decoration: none; color: inherit; margin-bottom: 0;
            border-bottom: 1px solid var(--border-color); padding: 0.75rem 1rem;
            transition: background-color 0.2s; min-height: 70px; cursor: pointer;
        }
        .post-card:hover { background-color: var(--link-hover-bg); }
        .post-card:last-child { border-bottom: none; }
        .post-thumb {
            width: 100px; height: 70px; flex-shrink: 0;
            background: var(--bg-secondary); object-fit: cover;
            margin-right: 0.75rem; display: block;
        }
        .post-card-body {
            flex: 1; display: flex; flex-direction: column;
            justify-content: space-between; min-width: 0;
        }
        .post-title {
            font-size: 0.95rem; font-weight: 600; color: var(--text-primary);
            line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
        }
        .post-time {
            font-size: 0.75rem; color: var(--text-secondary);
        }
        @media (max-width: 768px) {
            .favorites-header { padding: 0.8rem 1rem; }
            .favorites-back { font-size: 1.6rem; width: 36px; height: 36px; }
            .favorites-title { font-size: 1.1rem; }
            .favorites-action { width: 36px; height: 36px; font-size: 0.9rem; }
        }
        @media (max-width: 480px) {
            .favorites-back { font-size: 1.5rem; width: 32px; height: 32px; }
            .favorites-title { font-size: 1rem; }
            .favorites-action { width: 32px; height: 32px; font-size: 0.85rem; }
        }
    
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <?php $currentTab = 'profile'; $hideTopBar = true; include __DIR__ . '/header.php'; ?>
    <div id="page-content">
    <div class="favorites-header-wrapper">
        <div class="favorites-header">
            <a href="#" data-nav-url="<?php echo url('profile'); ?>" data-tab="profile" class="favorites-back">←</a>
            <h2 class="favorites-title">我的收藏</h2>
            <button class="favorites-action" id="editBtn" onclick="toggleEditMode()">编辑</button>
        </div>
    </div>
    <main class="main-content">
        <div class="posts-section" style="max-width: 100%; margin: 0; background: var(--bg-primary); padding: 0;">
            <?php if (empty($favorites)): ?>
                <div class="empty-state">
                    <p>暂无收藏的帖子</p>
                    <a href="#" data-nav-url="<?php echo url('index'); ?>" data-tab="home" class="btn-primary" style="display: inline-block; padding: 0.6rem 1.2rem; border-radius: 0; text-decoration: none;">去首页看看</a>
                </div>
            <?php else: ?>
                <div class="posts-list">
                    <?php foreach ($favorites as $post): ?>
                        <div class="post-card" data-nav-url="<?php echo url('post', ['id' => $post['id']]); ?>" data-tab="home">
                            <div class="post-card-check">
                                <input type="checkbox" class="favorite-check" value="<?php echo $post['id']; ?>" onclick="event.stopPropagation(); updateSelected();">
                            </div>
                            <?php 
                            $firstImage = null;
                            if (!empty($post['content'])) {
                                preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $post['content'], $imgMatch);
                                if (!empty($imgMatch[1])) $firstImage = $imgMatch[1];
                            }
                            ?>
                            <?php if ($firstImage): ?>
                                <img src="<?php echo getImageUrl(escape($firstImage)); ?>" alt="" class="post-thumb" loading="lazy">
                            <?php endif; ?>
                            <div class="post-card-body">
                                <div class="post-title"><?php echo escape($post['title']); ?></div>
                                <div class="post-time"><?php echo date('Y-m-d H:i', strtotime($post['created_at'])); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="#" data-nav-url="<?php echo url('favorites', [], ['page' => 1]); ?>" data-tab="profile">首页</a>
                            <a href="#" data-nav-url="<?php echo url('favorites', [], ['page' => $page - 1]); ?>" data-tab="profile">上一页</a>
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
                            <a href="#" data-nav-url="<?php echo url('favorites', [], ['page' => $i]); ?>" data-tab="profile"><?php echo $i; ?></a>
                        <?php endif; endfor; ?>
                        <?php if ($end < $totalPages) echo '<span>...</span>'; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="#" data-nav-url="<?php echo url('favorites', [], ['page' => $page + 1]); ?>" data-tab="profile">下一页</a>
                            <a href="#" data-nav-url="<?php echo url('favorites', [], ['page' => $totalPages]); ?>" data-tab="profile">尾页</a>
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
        <span class="selected-count" id="selectedCount">已选 0 项</span>
        <button class="delete-btn" id="deleteBtn" disabled onclick="batchDelete()">删除</button>
    </div>

    <script>
    var editing = false;



    function toggleEditMode() {
        var container = document.querySelector('.posts-section');
        if (!container) { editing = false; return; }
        editing = !editing;
        var btn = document.getElementById('editBtn');
        var bar = document.getElementById('deleteBar');
        if (editing) {
            container.classList.add('editing');
            btn.textContent = '完成';
            bar.classList.add('show');
        } else {
            container.classList.remove('editing');
            btn.textContent = '编辑';
            bar.classList.remove('show');
            document.querySelectorAll('.favorite-check').forEach(cb => cb.checked = false);
            updateSelected();
        }
    }

    function updateSelected() {
        var checks = document.querySelectorAll('.favorite-check:checked');
        var count = checks.length;
        document.getElementById('selectedCount').textContent = '已选 ' + count + ' 项';
        document.getElementById('deleteBtn').disabled = count === 0;
    }

    function batchDelete() {
        var checks = document.querySelectorAll('.favorite-check:checked');
        if (checks.length === 0) return;
        if (!confirm('确定删除选中的 ' + checks.length + ' 个收藏吗？')) return;

        var ids = Array.from(checks).map(cb => cb.value);
        var formData = new FormData();
        formData.append('action', 'batch_unfavorite');
        formData.append('ids', JSON.stringify(ids));
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

        fetch('post_actions.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    checks.forEach(cb => cb.closest('.post-card').remove());
                    if (document.querySelectorAll('.post-card').length === 0) location.reload();
                    else updateSelected();
                } else {
                    alert(result.message || '删除失败');
                }
            })
            .catch(() => alert('网络错误，请稍后重试'));
    }
    </script>
    </div><!-- /page-content -->
    <?php include 'auth_modal.php'; ?>
    <?php include 'spa.php'; ?>
</body>
</html>