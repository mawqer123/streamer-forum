<?php
// search.php - 搜索页面（支持搜索帖子和用户，选项卡切换）
$active_page = 'search';
$active_bottom = 'home';
require_once __DIR__ . '/functions.php';

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);

// 修复 VULN-005: 防止 $_GET['q'] 为数组时导致 trim() 报错暴露路径
$rawKeyword = $_GET['q'] ?? '';
if (is_array($rawKeyword)) {
    $rawKeyword = '';
}
$keyword = trim($rawKeyword);
$keyword = escape($keyword);

$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['posts', 'users']) ? $_GET['tab'] : 'posts';

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$upage = isset($_GET['upage']) ? max(1, intval($_GET['upage'])) : 1;
$perPage = 15;

$postResults = [];
$postTotal = 0;
$postPages = 0;

$userResults = [];
$userTotal = 0;
$userPages = 0;

if (!empty($keyword)) {
    $postResults = searchPosts($keyword, 0, $page, $perPage);
    $postTotal = searchPostsCount($keyword, 0);
    $postPages = ceil($postTotal / $perPage);
    
    $userResults = searchUsers($keyword, $upage, $perPage);
    $userTotal = searchUsersCount($keyword);
    $userPages = ceil($userTotal / $perPage);
}

$backUrl = url('index');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>搜索 - 主播模拟器论坛</title>
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
        /* ===== 搜索页面特有样式 ===== */
        .search-header {
            background-color: var(--accent-color);
            color: white;
            padding: 0 1rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .search-header-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            height: 40px;
        }
        .header-left { flex-shrink: 0; display: flex; align-items: center; }
        .back-btn {
            color: white;
            text-decoration: none;
            font-size: 1.6rem;
            line-height: 1;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
            flex-shrink: 0;
        }
        .back-btn:hover { background-color: rgba(255,255,255,0.2); }
        .search-header-form {
            flex: 1;
            display: flex;
            align-items: stretch;
        }
        .search-header-form .search-input {
            flex: 1;
            height: 32px;
            box-sizing: border-box;
            padding: 0 10px;
            border: none;
            outline: none;
            color: var(--text-primary, #333);
            background-color: #fff;
            font-size: 0.9rem;
            transition: box-shadow 0.3s;
        }
        .search-header-form .search-btn {
            border: none;
            background: transparent;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            padding: 0;
            outline: none;
            margin-left: 6px;
        }
        .search-header-form .search-btn:hover { opacity: 0.8; }

        .search-header-form .search-icon-svg { width: 18px; height: 18px; stroke: white; stroke-width: 2; fill: none; }
        .search-page-main { padding-top: 0 !important; }
        .search-container { max-width: 1200px; margin: 0 auto; padding: 0; }
        .search-tabs { background: var(--bg-primary); border-bottom: 1px solid var(--border-color); margin-top: 0; }
        .tabs-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            gap: 2rem;
            padding: 0 1rem;
        }
        .tab-link {
            padding: 0.8rem 0;
            font-size: 1rem;
            font-weight: 500;
            text-decoration: none;
            color: var(--text-secondary);
            border-bottom: 3px solid transparent;
            transition: color 0.2s, border-color 0.2s;
        }
        .tab-link.active { color: var(--accent-color); border-bottom-color: var(--accent-color); }
        .tab-link:hover { color: var(--accent-color); }

        .users-list { display: flex; flex-direction: column; }
        .user-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
            text-decoration: none;
            color: inherit;
        }
        .user-card-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
            min-width: 0;
        }
        .user-avatar {
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
            flex-shrink: 0;
            overflow: hidden;
            text-transform: uppercase;
        }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-info { flex: 1; min-width: 0; }
        .user-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        .user-uid { font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.15rem; word-break: break-all; }
        .follow-btn-small {
            background: var(--accent-gradient-from);
            color: white;
            border: none;
            padding: 0.3rem 0.8rem;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.2s;
            flex-shrink: 0;
            margin-left: 0.75rem;
        }
        .follow-btn-small.following { background: var(--bg-secondary); color: var(--text-primary); border: 1px solid var(--border-color); }
        .follow-btn-small:disabled { opacity: 0.6; cursor: not-allowed; }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin: 1rem 0 0.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
            padding: 0;
        }
        .section-title { font-size: 1.2rem; font-weight: 600; color: var(--text-primary); margin: 0; }
        .section-count { font-size: 0.9rem; color: var(--text-secondary); }
        .search-container .post-card { padding: 1rem 0; }

        @media (max-width: 768px) {
            .tabs-container { gap: 1rem; padding: 0 1rem; }
            .tab-link { padding: 0.6rem 0; font-size: 0.95rem; }
            .user-avatar { width: 40px; height: 40px; font-size: 0.9rem; }
            .user-name { font-size: 0.95rem; }
        }
        @media (max-width: 480px) {
            .user-card { flex-wrap: wrap; gap: 0.8rem; }
            .follow-btn-small { margin-left: auto; }
            .tab-link { font-size: 0.9rem; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <?php $currentTab = 'search'; include __DIR__ . '/header.php'; ?>
    <div id="page-content">
    <div class="search-header">
        <div class="search-header-container">
            <div class="header-left">
                <a href="#" data-nav-url="<?php echo $backUrl; ?>" data-tab="home" class="back-btn" aria-label="返回">←</a>
            </div>
            <form method="GET" action="<?php echo url('search'); ?>" class="search-header-form" id="searchForm">
                <input type="text" 
                       name="q" 
                       class="search-input" 
                       placeholder="搜索帖子或用户..." 
                       value="<?php echo $keyword; ?>"
                       maxlength="100"
                       required>
                <button type="submit" class="search-btn">
                    <svg class="search-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.35-4.35"></path>
                    </svg>
                </button>
            </form>
        </div>
    </div>

    <main class="main-content search-page-main">
        <div class="search-container">

            <?php if (!empty($keyword)): ?>
                <div class="search-tabs">
                    <div class="tabs-container">
                        <a href="#" data-nav-url="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'posts', 'page' => 1, 'upage' => 1]); ?>" data-tab="home" 
                           class="tab-link <?php echo $tab === 'posts' ? 'active' : ''; ?>">帖子</a>
                        <a href="#" data-nav-url="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'users', 'page' => 1, 'upage' => 1]); ?>" data-tab="home" 
                           class="tab-link <?php echo $tab === 'users' ? 'active' : ''; ?>">用户</a>
                    </div>
                </div>

                <?php if ($tab === 'posts'): ?>
                    <div id="posts-section">
                        <div class="section-header">
                            <h3 class="section-title">帖子结果</h3>
                            <?php if ($postTotal > 0): ?>
                                <span class="section-count">共 <?php echo $postTotal; ?> 条</span>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($postResults)): ?>
                            <div class="empty-state"><p>没有找到相关帖子</p></div>
                        <?php else: ?>
                            <div class="posts-list">
                                <?php foreach ($postResults as $post):
                                    $postImages = getPostAllImages($post['id'], $post['content']);
                                    $hasLiked = hasUserLikedPost($post['id'], $currentUser['id'] ?? 0);
                                    $isFavorited = $currentUser ? isPostFavorited($post['id'], $currentUser['id']) : false;
                                    $favoriteCount = getPostFavoriteCount($post['id']);
                                    
                                    $badgeHtml = '';
                                    if (!empty($post['is_banned'])) {
                                        $badgeHtml = '<span class="user-badge badge-banned">封禁</span>';
                                    } elseif (!empty($post['is_founder'])) {
                                        $badgeHtml = '<span class="user-badge badge-founder">站长</span>';
                                    } elseif (!empty($post['is_admin'])) {
                                        $badgeHtml = '<span class="user-badge badge-admin">管理员</span>';
                                    }
                                ?>
                                    <div class="post-card" data-nav-url="<?php echo url('post', ['id' => $post['id']]); ?>" data-tab="home">
                                        <div class="post-header">
                                            <a href="#" data-nav-url="<?php echo url('user', ['id' => $post['user_id']]); ?>" data-tab="profile">
                                                <?php echo getUserAvatarHtml($post, 'post-avatar'); ?>
                                                <div class="post-user-info">
                                                    <div class="post-username">
                                                        <?php echo escape($post['username']); ?>
                                                        <?php echo $badgeHtml; ?>
                                                        <?php echo getLevelBadgeHtml($post['exp'] ?? 0); ?>
                                                    </div>
                                                    <div class="post-time"><?php echo date('Y-m-d H:i', strtotime($post['created_at'])); ?></div>
                                                </div>
                                            </a>
                                        </div>
                                        <h3 class="post-title"><?php echo escape($post['title']); ?></h3>
                                        <div class="post-content">
                                            <?php 
                                            $content = strip_tags($post['content']);
                                            echo escape(mb_substr($content, 0, 150, 'UTF-8')) . (mb_strlen($content) > 150 ? '...' : '');
                                            ?>
                                        </div>
                                        <?php if (!empty($postImages)): ?>
                                            <div class="post-images-grid">
                                                <?php 
                                                $totalImages = count($postImages);
                                                $displayCount = min(5, $totalImages);
                                                for ($i = 0; $i < $displayCount; $i++): 
                                                    $imgUrl = getImageUrl(escape($postImages[$i]));
                                                    if ($i === 4 && $totalImages > 5):
                                                ?>
                                                    <div class="post-image-item" onclick="event.stopPropagation(); viewImage('<?php echo $imgUrl; ?>')">
                                                        <img src="<?php echo $imgUrl; ?>" alt="帖子图片">
                                                        <div class="image-more-overlay">+<?php echo $totalImages - 5; ?></div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="post-image-item" onclick="event.stopPropagation(); viewImage('<?php echo $imgUrl; ?>')">
                                                        <img src="<?php echo $imgUrl; ?>" alt="帖子图片">
                                                    </div>
                                                <?php endif; endfor; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="post-footer">
                                            <div class="post-stats">
                                                <div class="post-stat" style="cursor: default;">
                                                    <svg class="stat-icon view-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                                        <circle cx="12" cy="12" r="3"></circle>
                                                    </svg>
                                                    <span class="stat-number"><?php echo $post['view_count']; ?></span>
                                                </div>
                                                <div class="post-stat" onclick="event.stopPropagation(); goToComments(<?php echo $post['id']; ?>)">
                                                    <svg class="stat-icon comment-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                                                    </svg>
                                                    <span class="stat-number"><?php echo $post['comment_count']; ?></span>
                                                </div>
                                                <div class="post-stat" onclick="event.stopPropagation(); likePost(event, <?php echo $post['id']; ?>, this)">
                                                    <svg class="stat-icon like-icon <?php echo $hasLiked ? 'liked' : ''; ?>" 
                                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path>
                                                    </svg>
                                                    <span class="stat-number like-count-<?php echo $post['id']; ?>"><?php echo $post['like_count']; ?></span>
                                                </div>
                                                <div class="post-stat" onclick="event.stopPropagation(); toggleFavorite(event, <?php echo $post['id']; ?>, this)">
                                                    <svg class="stat-icon favorite-icon <?php echo $isFavorited ? 'favorited' : ''; ?>" 
                                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.07 5.82 22 7 14.14 2 9.27l6.91-1.01L12 2z"></path>
                                                    </svg>
                                                    <span class="stat-number favorite-count-<?php echo $post['id']; ?>"><?php echo $favoriteCount; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($postPages > 1): ?>
                                <div class="pagination">
                                    <?php if ($page > 1): ?>
                                        <a href="#" data-nav-url="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'posts', 'page' => 1, 'upage' => 1]); ?>" data-tab="home">首页</a>
                                        <a href="#" data-nav-url="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'posts', 'page' => $page - 1, 'upage' => 1]); ?>" data-tab="home">上一页</a>
                                    <?php else: ?>
                                        <span class="disabled">首页</span>
                                        <span class="disabled">上一页</span>
                                    <?php endif; ?>
                                    <?php
                                    $start = max(1, $page - 2);
                                    $end = min($postPages, $page + 2);
                                    if ($start > 1) echo '<span>...</span>';
                                    for ($i = $start; $i <= $end; $i++):
                                        if ($i == $page):
                                    ?>
                                        <span class="active"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'posts', 'page' => $i, 'upage' => 1]); ?>"><?php echo $i; ?></a>
                                    <?php endif; endfor; ?>
                                    <?php if ($end < $postPages) echo '<span>...</span>'; ?>
                                    <?php if ($page < $postPages): ?>
                                        <a href="#" data-nav-url="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'posts', 'page' => $page + 1, 'upage' => 1]); ?>" data-tab="home">下一页</a>
                                        <a href="#" data-nav-url="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'posts', 'page' => $postPages, 'upage' => 1]); ?>" data-tab="home">尾页</a>
                                    <?php else: ?>
                                        <span class="disabled">下一页</span>
                                        <span class="disabled">尾页</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'users'): ?>
                    <div id="users-section">
                        <div class="section-header">
                            <h3 class="section-title">用户结果</h3>
                            <?php if ($userTotal > 0): ?>
                                <span class="section-count">共 <?php echo $userTotal; ?> 人</span>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($userResults)): ?>
                            <div class="empty-state"><p>没有找到相关用户</p></div>
                        <?php else: ?>
                            <div class="users-list">
                                <?php foreach ($userResults as $user):
                                    $isBanned = $user['is_banned'] ?? 0;
                                    $isFollowed = false;
                                    if ($currentUser && $currentUser['id'] != $user['id'] && !$isBanned) {
                                        $isFollowed = isFollowing($currentUser['id'], $user['id']);
                                    }
                                    $displayId = !empty($user['public_uid']) ? $user['public_uid'] : 'UID:' . $user['id'];
                                    

                                ?>
                                    <a href="#" data-nav-url="<?php echo url('user', ['id' => $user['id']]); ?>" data-tab="profile" class="user-card">
                                        <div class="user-card-left">
                                            <?php echo getUserAvatarHtml($user, 'user-avatar'); ?>
                                            <div class="user-info">
                                                <div class="user-name">
                                                    <?php echo escape($user['username']); ?>
                                                </div>
                                                <div class="user-uid"><?php echo escape($displayId); ?></div>
                                            </div>
                                        </div>
                                        <?php if ($currentUser && $currentUser['id'] != $user['id'] && !$isBanned): ?>
                                            <button class="follow-btn-small <?php echo $isFollowed ? 'following' : ''; ?>" 
                                                    data-user-id="<?php echo $user['id']; ?>"
                                                    onclick="event.preventDefault(); toggleFollowFromCard(this, <?php echo $user['id']; ?>);">
                                                <?php echo $isFollowed ? '已关注' : '+ 关注'; ?>
                                            </button>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($userPages > 1): ?>
                                <div class="pagination">
                                    <?php if ($upage > 1): ?>
                                        <a href="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'users', 'page' => 1, 'upage' => 1]); ?>">首页</a>
                                        <a href="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'users', 'page' => 1, 'upage' => $upage - 1]); ?>">上一页</a>
                                    <?php else: ?>
                                        <span class="disabled">首页</span>
                                        <span class="disabled">上一页</span>
                                    <?php endif; ?>
                                    <?php
                                    $start = max(1, $upage - 2);
                                    $end = min($userPages, $upage + 2);
                                    if ($start > 1) echo '<span>...</span>';
                                    for ($i = $start; $i <= $end; $i++):
                                        if ($i == $upage):
                                    ?>
                                        <span class="active"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'users', 'page' => 1, 'upage' => $i]); ?>"><?php echo $i; ?></a>
                                    <?php endif; endfor; ?>
                                    <?php if ($end < $userPages) echo '<span>...</span>'; ?>
                                    <?php if ($upage < $userPages): ?>
                                        <a href="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'users', 'page' => 1, 'upage' => $upage + 1]); ?>">下一页</a>
                                        <a href="<?php echo url('search', [], ['q' => $keyword, 'tab' => 'users', 'page' => 1, 'upage' => $userPages]); ?>">尾页</a>
                                    <?php else: ?>
                                        <span class="disabled">下一页</span>
                                        <span class="disabled">尾页</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>输入关键词搜索帖子和用户</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="imageModal" class="image-modal" style="display: none;">
        <span class="modal-close" onclick="document.getElementById('imageModal').style.display='none'">&times;</span>
        <img id="modalImage" class="modal-image" src="">
    </div>

    <script>
    function viewImage(url) {
        var modal = document.getElementById('imageModal');
        modal.style.display = 'flex';
        document.getElementById('modalImage').src = url;
    }
    document.getElementById('imageModal')?.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') document.getElementById('imageModal').style.display = 'none';
    });

    function goToComments(postId) {
        window.location.href = '<?php echo url('post', ['id' => '']); ?>' + postId + '#comments';
    }

    function likePost(event, postId, element) {
        event.stopPropagation();
        <?php if (!isLoggedIn()): ?> showAuthModal(true); return; <?php endif; ?>
        var formData = new FormData();
        formData.append('action', 'like_post');
        formData.append('post_id', postId);
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        var likeIcon = element.querySelector('.like-icon');
        var likeCountSpan = element.querySelector('.like-count-' + postId);
        var currentCount = parseInt(likeCountSpan.textContent);
        fetch('/post_actions.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    if (res.liked) {
                        likeIcon.classList.add('liked');
                        likeCountSpan.textContent = currentCount + 1;
                    } else {
                        likeIcon.classList.remove('liked');
                        likeCountSpan.textContent = currentCount - 1;
                    }
                } else {
                    alert(res.message);
                }
            })
            .catch(e => alert('网络错误'));
    }

    function toggleFavorite(event, postId, element) {
        event.stopPropagation();
        <?php if (!isLoggedIn()): ?> showAuthModal(true); return; <?php endif; ?>
        var formData = new FormData();
        formData.append('action', 'toggle_favorite');
        formData.append('post_id', postId);
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        var favIcon = element.querySelector('.favorite-icon');
        var favCountSpan = element.querySelector('.favorite-count-' + postId);
        var currentCount = parseInt(favCountSpan.textContent);
        fetch('/post_actions.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    if (res.favorited) {
                        favIcon.classList.add('favorited');
                        favCountSpan.textContent = currentCount + 1;
                    } else {
                        favIcon.classList.remove('favorited');
                        favCountSpan.textContent = currentCount - 1;
                    }
                } else {
                    alert(res.message);
                }
            })
            .catch(e => alert('网络错误'));
    }

    function toggleFollowFromCard(btn, targetUserId) {
        <?php if (!isLoggedIn()): ?> showAuthModal(true); return; <?php endif; ?>
        btn.disabled = true;
        var formData = new FormData();
        formData.append('action', 'toggle_follow');
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
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

    // 拦截搜索表单提交，改为内部刷新
    function _searchFormSubmit(e) {
        e.preventDefault();
        var q = document.querySelector('.search-input').value.trim();
        if (q) {
            navigateTo('<?php echo url('search', [], ['q' => '']); ?>' + encodeURIComponent(q), 'home');
        }
    }
    var searchForm = document.getElementById('searchForm');
    if (searchForm) {
        searchForm.removeEventListener('submit', _searchFormSubmit);
        searchForm.addEventListener('submit', _searchFormSubmit);
    }

    (function() {
        var searchInput = document.querySelector('.search-input');
        if (searchInput && searchInput.value === '') searchInput.focus();
    });
    </script>
    </div><!-- /page-content -->
    <?php include __DIR__ . '/bottom_nav.php'; ?>
    <?php include 'auth_modal.php'; ?>
    <?php include 'spa.php'; ?>
</body>
</html>