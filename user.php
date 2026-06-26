<?php
// user.php - 用户个人主页（翻新游戏风格）
require_once __DIR__ . '/functions.php';

$currentUserForTheme = getCurrentUser();

$userId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($userId <= 0) {
    show_error_page('参数错误', '用户ID无效，请返回首页。', url('index'));
}

$targetUser = getUserById($userId);
if (!$targetUser) {
    show_error_page('用户不存在', '您访问的用户不存在或已被删除。', url('index'));
}

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);
$isSelf = ($currentUser && $currentUser['id'] == $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_follow') {
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
        exit;
    }
    $result = toggleFollow($currentUser['id'], $userId);
    echo json_encode($result);
    exit;
}

$followStats = getUserFollowStats($userId);
$receivedLikes = getUserReceivedLikes($userId);

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$posts = getUserPosts($userId, $page, $perPage);
$totalPosts = getUserPostsCount($userId);
$totalPages = ceil($totalPosts / $perPage);

$isFollowing = false;
if ($currentUser && !$isSelf && !$targetUser['is_banned']) {
    $isFollowing = isFollowing($currentUser['id'], $userId);
}

$avatarUrl = !empty($targetUser['avatar']) ? htmlspecialchars($targetUser['avatar']) : null;
$avatarText = !empty($targetUser['avatar_text']) ? htmlspecialchars($targetUser['avatar_text']) : '';
$backgroundUrl = !empty($targetUser['profile_background']) ? htmlspecialchars($targetUser['profile_background']) : null;
$bgPending = !empty($targetUser['background_pending']);
$isBanned = $targetUser['is_banned'] ? true : false;

$displayId = !empty($targetUser['public_uid']) ? $targetUser['public_uid'] : 'UID:' . $userId;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo escape($targetUser['username']); ?> 的个人主页 - 主播模拟器论坛</title>
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
        /* user.php 特有样式 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { margin: 0 !important; padding: 0 !important; height: 100%; }
        body { background-color: var(--bg-secondary); color: var(--text-primary); transition: background-color 0.3s, color 0.3s; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .main-content { margin: 0 !important; padding: 0 !important; min-height: 100%; }

        .profile-top-bar {
            display: flex; align-items: center; justify-content: center;
            padding: 0.75rem 1rem;
            background: transparent;
            position: absolute; top: 0; left: 0; right: 0;
            z-index: 10; min-height: 44px;
        }
        .profile-top-bar .back-btn {
            position: absolute; left: 1rem; top: 50%; transform: translateY(-50%);
            display: flex; align-items: center; justify-content: center;
            width: 40px; height: 40px;
            background: transparent; border: none;
            cursor: pointer;
            transition: opacity 0.2s;
            text-decoration: none;
        }
        .profile-top-bar .back-btn svg {
            width: 28px; height: 28px;
        }
        .profile-top-bar .back-btn:hover {
            opacity: 0.7;
        }
        .profile-top-bar .profile-title {
            font-size: 1.1rem; font-weight: 600; color: white;
        }
        .profile-container { margin: 0 auto; padding: 0; }

        .user-info-card {
            background: var(--accent-gradient-from);
            padding: 3.5rem 20px 0.5rem;
            margin: 0 0 0 0;
            display: flex;
            align-items: center; min-height: 150px;
            position: relative;
            overflow: hidden;
            box-shadow: none;
            border-radius: 0;
        }
        .user-info-card.has-background {
            background: linear-gradient(rgba(0,0,0,0.2), rgba(0,0,0,0.2)), var(--bg-image) no-repeat center center / cover;
            animation: none;
        }

        .user-avatar {
            width: 65px; height: 65px; border-radius: 50%; background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px); display: flex; align-items: center; justify-content: center;
            font-size: 28px; font-weight: bold; margin-right: 18px; flex-shrink: 0; overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.4); box-shadow: 0 0 20px rgba(0, 0, 0, 0.2);
            position: relative; z-index: 1; color: white;
            text-transform: uppercase;
        }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-details { flex: 1; min-width: 0; position: relative; z-index: 1; }
        .user-name {
            font-size: 20px; font-weight: bold; margin-bottom: 4px; color: #fff;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3); white-space: nowrap;
            overflow-x: auto; -webkit-overflow-scrolling: touch;
            display: flex; align-items: center; gap: 0;
        }
        .banned-tag {
            display: inline-block; background: rgba(229, 62, 62, 0.9); color: white;
            padding: 0.08rem 0.5rem; border-radius: 0; font-size: 0.7rem; font-weight: 600;
            margin-left: 0.3rem; border: none; text-shadow: none; vertical-align: middle;
        }
        .founder-tag, .admin-tag {
            display: inline-block; padding: 0.08rem 0.45rem; border-radius: 4px;
            font-size: 0.55rem; font-weight: 600; margin-left: 0.2rem; line-height: 1.3; border: none; vertical-align: middle;
        }
        .founder-tag { background: #fbbf24; color: white; }
        .admin-tag { background: rgba(255, 255, 255, 0.2); color: white; }
        .user-name .level-badge { margin-left: 0.2rem; font-size: 0.55rem; padding: 0.08rem 0.35rem; line-height: 1.3; border: none; vertical-align: middle; }
        .user-uid { font-size: 12px; color: rgba(255, 255, 255, 0.9); word-break: break-word; display: flex; align-items: center; gap: 4px; }

        .stats-card {
            background: var(--bg-primary); border-radius: 0; padding: 20px;
            margin: 0 0 1.5rem 0; display: flex; justify-content: space-around;
            align-items: center; gap: 10px; flex-wrap: nowrap;
            overflow-x: auto;
            box-shadow: none;
        }
        .stat-item { display: flex; align-items: center; gap: 6px; min-width: fit-content; text-decoration: none; color: inherit; }
        .stat-label { font-size: 14px; color: var(--text-secondary); white-space: nowrap; }
        .stat-value { font-size: 18px; font-weight: bold; color: var(--accent-color); white-space: nowrap; }

        .profile-actions {
            display: flex; gap: 0; margin-top: 10px;
        }
        .profile-actions .follow-btn,
        .profile-actions .pm-btn {
            flex: 1;
        }
        .follow-btn {
            background: var(--accent-gradient-from); color: white; border: none; border-radius: 0;
            padding: 0.6rem 1rem; font-size: 0.95rem; font-weight: 600; cursor: pointer;
            transition: opacity 0.3s, transform 0.2s;
            text-align: center; text-decoration: none;
        }
        .follow-btn:hover:not(:disabled) { opacity: 0.9; transform: translateY(-2px); }
        .follow-btn.following { background: var(--bg-secondary); color: var(--text-primary); border: 1px solid var(--border-color); box-shadow: none; }
        .follow-btn.banned { background: #e53e3e; color: white; box-shadow: none; cursor: default; width: 100%; }
        .pm-btn {
            background: #3b82f6; color: white; border: none; border-radius: 0;
            padding: 0.6rem 1rem; font-size: 0.95rem; font-weight: 600; cursor: pointer;
            transition: opacity 0.3s, transform 0.2s;
            text-align: center; text-decoration: none;
        }
        .pm-btn:hover { opacity: 0.9; transform: translateY(-2px); }

        .posts-section {
            background: var(--bg-primary); border-radius: 0; padding: 1.5rem;
            margin: 0;
            box-shadow: none;
        }
        .section-title {
            font-size: 1.2rem; font-weight: 600; color: var(--text-primary);
            margin-bottom: 0; padding-bottom: 0.5rem; border-bottom: 2px solid var(--accent-color);
        }
        .post-card {
            display: flex; align-items: stretch; gap: 0;
            text-decoration: none; color: inherit; margin-bottom: 0;
            border-bottom: 1px solid var(--border-color); padding: 0.75rem 0;
            transition: background-color 0.2s; min-height: 70px;
        }
        
        .post-card:last-child { border-bottom: none; }
        .post-card:hover { background-color: var(--link-hover-bg); }
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
            margin-bottom: 0.25rem;
        }
        .post-time {
            font-size: 0.75rem; color: var(--text-secondary);
        }
        .pagination-info { margin-left: 10px; font-size: 12px; color: var(--text-secondary); }

        @media (max-width: 768px) {
            .profile-top-bar { padding: 0.5rem 0.75rem; }
            .profile-top-bar .back-btn { width: 36px; height: 36px; left: 0.75rem; }
            .profile-top-bar .back-btn svg { width: 24px; height: 24px; }
            .profile-top-bar .profile-title { font-size: 1rem; }
            .stats-card { padding: 15px; gap: 5px; }
            .stat-label { font-size: 12px; }
            .stat-value { font-size: 16px; }
            .posts-section { padding: 1rem; }
        }
        @media (max-width: 480px) {
            .user-avatar { width: 55px; height: 55px; font-size: 24px; margin-right: 12px; }
            .user-name { font-size: 18px; }
            .stats-card { flex-wrap: nowrap; overflow-x: auto; justify-content: flex-start; padding: 15px 10px; }
            .post-stats { flex-wrap: wrap; gap: 10px; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <div id="page-content">
    <main class="main-content">
        <div class="profile-container">
            <div class="user-info-card <?php echo ($backgroundUrl || $bgPending) ? 'has-background' : ''; ?>" 
                 style="<?php echo $bgPending ? '--bg-image: url(/zbgameshz.png);' : ($backgroundUrl ? '--bg-image: url(' . $backgroundUrl . ');' : ''); ?>">
                    <div class="profile-top-bar">
                        <a href="#" data-nav-url="<?php echo url('index'); ?>" class="back-btn" aria-label="返回">
                            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m7-7-7 7 7 7"/></svg>
                        </a>
                        <span class="profile-title"></span>
                    </div>
                                <?php echo getUserAvatarHtml($targetUser, 'user-avatar'); ?>
                <div class="user-details">
                    <div class="user-name">
                        <?php echo escape($targetUser['username']); ?>
                        <?php if ($isBanned): ?>
                            <span class="banned-tag">封禁中</span>
                        <?php elseif ($targetUser['is_founder']): ?>
                            <span class="founder-tag">站长</span>
                        <?php elseif ($targetUser['is_admin']): ?>
                            <span class="admin-tag">管理员</span>
                        <?php endif; ?>
                        <?php echo getLevelBadgeHtml($targetUser['exp'] ?? 0); ?>
                    </div>
                    <div class="user-uid"> <?php echo escape($displayId); ?></div>
                </div>
            </div>

            <div class="stats-card">
                <a href="#" data-nav-url="<?php echo url('follows', ['type' => 'following', 'id' => $userId]); ?>" data-tab="profile" class="stat-item">
                    <span class="stat-label">关注</span>
                    <span class="stat-value"><?php echo $followStats['following']; ?></span>
                </a>
                <a href="#" data-nav-url="<?php echo url('follows', ['type' => 'followers', 'id' => $userId]); ?>" data-tab="profile" class="stat-item">
                    <span class="stat-label">粉丝</span>
                    <span class="stat-value"><?php echo $followStats['followers']; ?></span>
                </a>
                <div class="stat-item">
                    <span class="stat-label">获赞</span>
                    <span class="stat-value"><?php echo $receivedLikes; ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">积分</span>
                    <span class="stat-value"><?php echo $targetUser['points']; ?></span>
                </div>
                </div>

            <?php if (!$isSelf): ?>
                <div class="profile-actions">
                <?php if ($isBanned): ?>
                    <button class="follow-btn banned" disabled> 账号已封禁</button>
                <?php elseif (isLoggedIn()): ?>
                    <button class="follow-btn <?php echo $isFollowing ? 'following' : ''; ?>" 
                            id="followBtn" 
                            onclick="toggleFollow()"
                            data-following="<?php echo $isFollowing ? '1' : '0'; ?>">
                        <?php echo $isFollowing ? '已关注' : '＋ 关注'; ?>
                    </button>
                    <button class="pm-btn" onclick="startPM(<?php echo $userId; ?>)">
                         私信
                    </button>
                <?php else: ?>
                    <button class="follow-btn" onclick="showAuthModal(true)">登录后关注</button>
                <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="posts-section">
                <h3 class="section-title">帖子 <?php echo $totalPosts; ?></h3>
                <?php if (empty($posts)): ?>
                    <div class="empty-state"><p>还没有发布过帖子</p></div>
                <?php else: ?>
                    <div class="posts-list">
                        <?php foreach ($posts as $post): 
                            $firstImage = null;
                            if (!empty($post['content'])) {
                                preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $post['content'], $imgMatch);
                                if (!empty($imgMatch[1])) $firstImage = $imgMatch[1];
                            }
                        ?>
                            <a href="<?php echo url('post', ['id' => $post['id']]); ?>" class="post-card" data-nav-url="<?php echo url('post', ['id' => $post['id']]); ?>" data-tab="profile">
                                <?php if ($firstImage): ?>
                                    <img src="<?php echo getImageUrl(escape($firstImage)); ?>" alt="" class="post-thumb" loading="lazy">
                                <?php endif; ?>
                                <div class="post-card-body">
                                    <div class="post-title"><?php echo escape($post['title']); ?></div>
                                    <div class="post-time"><?php echo date('Y-m-d H:i', strtotime($post['created_at'])); ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo url('user', ['id' => $userId], ['page' => 1]); ?>">首页</a>
                                <a href="<?php echo url('user', ['id' => $userId], ['page' => $page - 1]); ?>">上一页</a>
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
                                <a href="<?php echo url('user', ['id' => $userId], ['page' => $i]); ?>"><?php echo $i; ?></a>
                            <?php endif; endfor; ?>
                            <?php if ($end < $totalPages) echo '<span>...</span>'; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo url('user', ['id' => $userId], ['page' => $page + 1]); ?>">下一页</a>
                                <a href="<?php echo url('user', ['id' => $userId], ['page' => $totalPages]); ?>">尾页</a>
                            <?php else: ?>
                                <span class="disabled">下一页</span>
                                <span class="disabled">尾页</span>
                            <?php endif; ?>
                            <span class="pagination-info">第 <?php echo $page; ?> / <?php echo $totalPages; ?> 页</span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script>
        function toggleFollow() {
            const btn = document.getElementById('followBtn');
            if (!btn) return;
            btn.disabled = true;
            const formData = new FormData();
            formData.append('action', 'toggle_follow');
            formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    if (result.following) {
                        btn.textContent = '已关注';
                        btn.classList.add('following');
                        btn.dataset.following = '1';
                        updateStats(1);
                    } else {
                        btn.textContent = '＋ 关注';
                        btn.classList.remove('following');
                        btn.dataset.following = '0';
                        updateStats(-1);
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

        function updateStats(delta) {
            const followerStat = document.querySelector('.stats-card a:nth-child(2) .stat-value');
            if (followerStat) {
                let val = parseInt(followerStat.textContent) + delta;
                followerStat.textContent = val;
            }
        }

        async function startPM(userId) {
            const fd = new FormData(); fd.append('action', 'start_conversation'); fd.append('user_id', userId);
            try {
                const res = await fetch('/pm_chat', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    navigateTo('/pm_chat?conv_id=' + encodeURIComponent(data.conv_id), 'notifications');
                } else {
                    alert('发起私信失败: ' + (data.message || '未知错误'));
                }
            } catch(e) { alert('网络错误'); }
        }
    </script>
    </div><!-- /page-content -->
    <?php include 'auth_modal.php'; ?>
    <?php include 'spa.php'; ?>
</body>
</html>