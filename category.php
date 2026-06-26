<?php
// category.php - 统一分类页面（支持 mod、exchange、chat）
$active_page = isset($_GET['slug']) ? $_GET['slug'] : 'mod';
$active_bottom = 'home';
require_once __DIR__ . '/functions.php';

// 获取分类标识
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : 'mod';
$validSlugs = ['mod', 'exchange', 'chat'];
if (!in_array($slug, $validSlugs)) {
    show_error_page('分类不存在', '您访问的分类不存在，请返回首页浏览其他内容。', url('index'));
}

// 获取分类信息
$category = getCategoryBySlug($slug);
if (!$category) {
    show_error_page('分类不存在', '该分类可能已被删除或禁用。', url('index'));
}

// 页面标题映射
$pageTitles = [
    'mod' => 'Mod专区',
    'exchange' => '交流专区',
    'chat' => '闲聊专区'
];
$pageTitle = $pageTitles[$slug] ?? '论坛分类';

// 获取当前用户信息（用于主题）
$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);

// 获取当前页码
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;

// 获取排序参数
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'latest';
$validSorts = ['latest', 'popular', 'like', 'comment'];
if (!in_array($sort, $validSorts)) {
    $sort = 'latest';
}

// 获取帖子数据
$topPosts = getTopPosts($category['id']);
// 修改：获取非置顶帖子数用于分页
$totalPosts = getPostCount($category['id'], true);
$posts = getPostsByCategory($category['id'], $page, $perPage, $sort);
$totalPages = ceil($totalPosts / $perPage);

// 获取当前用户信息（用于点赞和收藏状态）
$currentUserForLike = getCurrentUser();
$userId = $currentUserForLike['id'] ?? 0;

// 预加载当前用户对所有帖子的收藏状态
$favoritedStatus = [];
if ($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT post_id FROM favorites WHERE user_id = ?");
        $stmt->execute([$userId]);
        $favorited = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $favoritedStatus = array_flip($favorited);
    } catch (Exception $e) {
        // 忽略错误
    }
}

// 为 nav.php 传递排序变量
$currentSort = $sort;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?> - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <?php
    // 输出自定义主题的内联样式（如果当前用户已登录且主题为 custom）
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
    <script>
        document.documentElement.style.setProperty('--accent-color', '<?php echo $primary; ?>');
        document.documentElement.style.setProperty('--accent-gradient-from', '<?php echo $primary; ?>');
        document.documentElement.style.setProperty('--accent-gradient-to', '<?php echo $to; ?>');
    </script>
    <?php } ?>
    <style data-page-style>
        /* 分类页面特有样式（仅保留 style.css 未覆盖的少量特定布局） */
        .main-content { padding: 0 0 72px; }
        .posts-section {
            max-width: 100%;
            margin: 0;
            background: var(--bg-primary);
            padding: 0;
        }
        .create-post-btn {
            position: fixed;
            bottom: 80px;
            right: 16px;
            width: 56px;
            height: 56px;
            border-radius: 0;
            background: var(--accent-gradient-from);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            z-index: 100;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .create-post-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        }
        @media (max-width: 768px) {
            .posts-section { padding: 0; }
            .create-post-btn {
                bottom: 70px;
                right: 15px;
                width: 48px;
                height: 48px;
                font-size: 1.5rem;
            }
        }
        @media (max-width: 480px) {
            .create-post-btn {
                bottom: 65px;
                right: 10px;
                width: 44px;
                height: 44px;
                font-size: 1.3rem;
            }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <?php include 'header.php'; ?>
    <?php include 'nav.php'; ?>
    <div id="page-content">
    <main class="main-content">
        <?php if (!isLoggedIn()): ?>
            <div style="text-align: center; margin-top: 2rem; padding: 1.5rem; background: var(--bg-primary); max-width: 420px; margin-left: auto; margin-right: auto;">
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">请先登录后再参与<?php echo $pageTitle; ?></p>
                <button class="btn-primary" onclick="showAuthModal(true)" style="padding: 0.5rem 1.5rem; border-radius: 0;">立即登录</button>
            </div>
        <?php else: ?>
            <!-- 置顶帖子区域 -->
            <?php if (!empty($topPosts)): ?>
                <div style="padding: 0; background: transparent;">
                    <?php $topCount = count($topPosts); ?>
                    <?php foreach ($topPosts as $i => $post): ?>
                        <a href="#" data-nav-url="<?php echo url('post', ['id' => $post['id']]); ?>" data-tab="home" style="display: block; padding: 0.75rem 0; color: var(--text-primary); text-decoration: none; font-size: 0.95rem;border-bottom: 1px solid var(--border-color)">
                            <?php echo escape($post['title']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- 帖子列表区域 -->
            <div class="posts-section">
                <?php if (empty($posts)): ?>
                    <div class="empty-state">
                        <p>暂无帖子，快来发布第一条吧！</p>
                        <?php if (isLoggedIn()): ?>
                            <button data-nav-url="<?php echo url('create_post', [], ['category' => $slug]); ?>" data-tab="home" class="btn-primary" style="border-radius: 0;">发布帖子</button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="posts-list">
                        <?php foreach ($posts as $post): 
                            $postImages = getPostAllImages($post['id'], $post['content']);
                            $hasLiked = hasUserLikedPost($post['id'], $currentUserForLike['id'] ?? 0);
                            $isFavorited = isset($favoritedStatus[$post['id']]);
                            $favoriteCount = $post['favorite_count'] ?? 0;
                            
                            // 身份标签处理
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
                                    <a href="<?php echo url('user', ['id' => $post['user_id']]); ?>">
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
                                
                                <div class="post-content" id="content-<?php echo $post['id']; ?>">
                                    <?php 
                                    $content = strip_tags($post['content']);
                                    if (mb_strlen($content, 'UTF-8') > 150) {
                                        echo escape(mb_substr($content, 0, 150, 'UTF-8')) . '...';
                                    } else {
                                        echo escape($content);
                                    }
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
                                            <div class="post-image-item" onclick="viewImage(event, '<?php echo $imgUrl; ?>')">
                                                <img src="<?php echo $imgUrl; ?>" alt="帖子图片">
                                                <div class="image-more-overlay">+<?php echo $totalImages - 5; ?></div>
                                            </div>
                                        <?php else: ?>
                                            <div class="post-image-item" onclick="viewImage(event, '<?php echo $imgUrl; ?>')">
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
                                        <div class="post-stat" onclick="goToComments(event, <?php echo $post['id']; ?>)">
                                            <svg class="stat-icon comment-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                                            </svg>
                                            <span class="stat-number"><?php echo $post['comment_count']; ?></span>
                                        </div>
                                        <div class="post-stat" onclick="likePost(event, <?php echo $post['id']; ?>, this)">
                                            <svg class="stat-icon like-icon <?php echo $hasLiked ? 'liked' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path>
                                            </svg>
                                            <span class="stat-number like-count-<?php echo $post['id']; ?>"><?php echo $post['like_count']; ?></span>
                                        </div>
                                        <div class="post-stat" onclick="toggleFavorite(event, <?php echo $post['id']; ?>, this)">
                                            <svg class="stat-icon favorite-icon <?php echo $isFavorited ? 'favorited' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.07 5.82 22 7 14.14 2 9.27l6.91-1.01L12 2z"></path>
                                            </svg>
                                            <span class="stat-number favorite-count-<?php echo $post['id']; ?>"><?php echo $favoriteCount; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo url('category', ['slug' => $slug], ['page' => 1, 'sort' => $sort]); ?>">首页</a>
                                <a href="<?php echo url('category', ['slug' => $slug], ['page' => $page - 1, 'sort' => $sort]); ?>">上一页</a>
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
                                <a href="<?php echo url('category', ['slug' => $slug], ['page' => $i, 'sort' => $sort]); ?>"><?php echo $i; ?></a>
                            <?php endif; endfor; ?>
                            <?php if ($end < $totalPages) echo '<span>...</span>'; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo url('category', ['slug' => $slug], ['page' => $page + 1, 'sort' => $sort]); ?>">下一页</a>
                                <a href="<?php echo url('category', ['slug' => $slug], ['page' => $totalPages, 'sort' => $sort]); ?>">尾页</a>
                            <?php else: ?>
                                <span class="disabled">下一页</span>
                                <span class="disabled">尾页</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <?php if (isLoggedIn()): ?>
                <button data-nav-url="<?php echo url('create_post', [], ['category' => $slug]); ?>" data-tab="home" class="create-post-btn">+</button>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    
    <div id="imageModal" class="image-modal" style="display: none;">
        <span class="modal-close" onclick="document.getElementById('imageModal').style.display='none'">&times;</span>
        <img id="modalImage" class="modal-image" src="">
    </div>
    
    <script>

    function viewImage(event, imageUrl) {
        event.stopPropagation();
        const modal = document.getElementById('imageModal');
        const modalImg = document.getElementById('modalImage');
        modal.style.display = 'flex';
        modalImg.src = imageUrl;
    }
    
    function goToComments(event, postId) {
        event.stopPropagation();
        window.location.href = '<?php echo url('post', ['id' => '']); ?>' + postId + '#comments';
    }
    
    function likePost(event, postId, element) {
        event.stopPropagation();
        <?php if (!isLoggedIn()): ?>
            showAuthModal(true);
            return;
        <?php endif; ?>
        const formData = new FormData();
        formData.append('action', 'like_post');
        formData.append('post_id', postId);
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        const likeIcon = element.querySelector('.like-icon');
        const likeCountElement = element.querySelector('.stat-number');
        let currentLikeCount = parseInt(likeCountElement.textContent);
        fetch('post_actions.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    if (result.liked) {
                        likeIcon.classList.add('liked');
                        likeIcon.style.fill = '#e53e3e';
                        likeCountElement.textContent = currentLikeCount + 1;
                    } else {
                        likeIcon.classList.remove('liked');
                        likeIcon.style.fill = 'none';
                        likeCountElement.textContent = currentLikeCount - 1;
                    }
                } else {
                    alert(result.message);
                }
            })
            .catch(error => alert('网络错误，请稍后重试！'));
    }
    
    function toggleFavorite(event, postId, element) {
        event.stopPropagation();
        <?php if (!isLoggedIn()): ?>
            showAuthModal(true);
            return;
        <?php endif; ?>
        const formData = new FormData();
        formData.append('action', 'toggle_favorite');
        formData.append('post_id', postId);
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        const favoriteIcon = element.querySelector('.favorite-icon');
        const favoriteCountElement = element.querySelector('.stat-number');
        let currentCount = parseInt(favoriteCountElement.textContent);
        fetch('post_actions.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    if (result.favorited) {
                        favoriteIcon.classList.add('favorited');
                        favoriteCountElement.textContent = currentCount + 1;
                    } else {
                        favoriteIcon.classList.remove('favorited');
                        favoriteCountElement.textContent = currentCount - 1;
                    }
                } else {
                    alert(result.message);
                }
            })
            .catch(error => alert('网络错误，请稍后重试！'));
    }
    
    // 保存当前排序到会话存储（便于从其他页面返回时恢复）
    function saveCurrentSort() {
        var s = new URL(window.location.href).searchParams.get('sort');
        if (s) sessionStorage.setItem('category_sort', s);
    }

    // 排序切换函数（内部刷新）
    function changeSort(sort) {
        closeSortMenu();
        sessionStorage.setItem('category_sort', sort);
        var url = new URL(window.location.href);
        url.searchParams.set('sort', sort);
        url.searchParams.delete('page'); // 切换排序时重置页码
        // 手动更新排序按钮的文字
        var labelEl = document.querySelector('.sort-trigger-label');
        if (labelEl) {
            var labels = {'latest':'最新发布','popular':'最多阅读','like':'最多点赞','comment':'最多评论'};
            labelEl.textContent = labels[sort] || '最新发布';
        }
        navigateTo(url.pathname + url.search, 'home');
    }
    
    // 自定义排序下拉菜单（固定定位到 body 层，避免被 nav-list 裁剪）
    var SORT_OPTIONS = [
        { value: 'latest', label: '最新发布' },
        { value: 'popular', label: '最多阅读' },
        { value: 'like', label: '最多点赞' },
        { value: 'comment', label: '最多评论' }
    ];
    var sortMenuActive = false;
    var sortMenuElement = null;
    
    function getCurrentSort() {
        var s = new URL(window.location.href).searchParams.get('sort');
        return s || 'latest';
    }
    
    function buildSortMenuItems() {
        var current = getCurrentSort();
        var ns = 'http://www.w3.org/2000/svg';
        var items = [];
        SORT_OPTIONS.forEach(function(opt) {
            var div = document.createElement('div');
            div.className = 'sort-dropdown-item' + (opt.value === current ? ' active' : '');
            
            var span = document.createElement('span');
            span.className = 'sort-item-label';
            span.textContent = opt.label;
            div.appendChild(span);
            
            if (opt.value === current) {
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
                closeSortMenu();
                changeSort(opt.value);
            });
            
            items.push(div);
        });
        return items;
    }
    
    function openSortMenu() {
        var trigger = document.querySelector('.sort-dropdown-trigger');
        if (!trigger) return;
        
        var menu = document.createElement('div');
        menu.className = 'sort-dropdown-menu-fixed';
        
        var rect = trigger.getBoundingClientRect();
        menu.style.top = (rect.bottom + 4) + 'px';
        menu.style.left = rect.left + 'px';
        menu.style.minWidth = Math.max(rect.width, 120) + 'px';
        
        var items = buildSortMenuItems();
        items.forEach(function(item) { menu.appendChild(item); });
        
        document.body.appendChild(menu);
        sortMenuElement = menu;
        sortMenuActive = true;
        trigger.classList.add('active');
        
        // 延迟触发动画，确保 DOM 已插入
        requestAnimationFrame(function() {
            menu.classList.add('open');
        });
    }
    
    function closeSortMenu() {
        if (sortMenuElement) {
            sortMenuElement.classList.remove('open');
            if (sortMenuElement.parentNode) {
                sortMenuElement.parentNode.removeChild(sortMenuElement);
            }
            sortMenuElement = null;
        }
        sortMenuActive = false;
        var trigger = document.querySelector('.sort-dropdown-trigger');
        if (trigger) trigger.classList.remove('active');
    }
    
    function toggleSortDropdown(event) {
        event.stopPropagation();
        if (sortMenuActive) {
            closeSortMenu();
        } else {
            openSortMenu();
        }
    }
    
    (function() {
        const imageModal = document.getElementById('imageModal');
        if (imageModal) {
            imageModal.addEventListener('click', function(e) {
                if (e.target === imageModal) imageModal.style.display = 'none';
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (imageModal.style.display === 'flex') {
                    imageModal.style.display = 'none';
                }
                if (sortMenuActive) {
                    closeSortMenu();
                }
            }
        });
        
        // 页面滚动或尺寸变化时关闭下拉菜单
        window.addEventListener('scroll', function() {
            if (sortMenuActive) closeSortMenu();
        }, true);
        window.addEventListener('resize', function() {
            if (sortMenuActive) closeSortMenu();
        });
        document.querySelectorAll('.like-icon.liked').forEach(icon => icon.style.fill = '#e53e3e');
        document.querySelectorAll('.favorite-icon.favorited').forEach(icon => icon.style.fill = '#fbbf24');
        
        // 点击其他区域关闭自定义排序下拉菜单（body层）
        document.addEventListener('click', function(e) {
            if (!sortMenuActive) return;
            var trigger = document.querySelector('.sort-dropdown-trigger');
            if (trigger && !trigger.contains(e.target) && sortMenuElement && !sortMenuElement.contains(e.target)) {
                closeSortMenu();
            }
        });

        // 从 sessionStorage 恢复排序显示（当通过 SPA 导航从别的页面回来时）
        var savedSort = sessionStorage.getItem('category_sort');
        var currentSortFromUrl = new URL(window.location.href).searchParams.get('sort') || 'latest';
        if (savedSort && savedSort !== currentSortFromUrl) {
            // 手动更新排序按钮的显示（替换导航前的视觉反馈）
            var sortBtn = document.querySelector('.sort-dropdown-trigger');
            if (sortBtn) {
                sortBtn.setAttribute('data-current', savedSort);
                var labelEl = document.querySelector('.sort-trigger-label');
                var labels = {'latest':'最新发布','popular':'最多阅读','like':'最多点赞','comment':'最多评论'};
                if (labelEl) labelEl.textContent = labels[savedSort] || '最新发布';
            }
            // 用正确的排序重新请求（用 replace 避免增加历史条目）
            var url = new URL(window.location.href);
            url.searchParams.set('sort', savedSort);
            navigateTo(url.pathname + url.search, 'home', true);
        } else {
            // 当前排序正确，保存到会话存储
            saveCurrentSort();
        }
    })();
    </script>
    </div><!-- /page-content -->
    <?php include 'bottom_nav.php'; ?>
    <?php include 'auth_modal.php'; ?>
</body>
</html>