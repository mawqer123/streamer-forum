<?php
// nav.php - 可复用的导航栏组件
$active_page = isset($active_page) ? $active_page : 'home';

// 检查用户登录状态
require_once __DIR__ . '/functions.php';
$isLoggedIn = isLoggedIn();

// 获取当前排序（用于分类页面）
$currentSort = isset($sort) ? $sort : 'latest'; // 默认最新
?>

<nav class="nav-bar">
    <div class="nav-container">
        <ul class="nav-list">
            <li class="nav-item <?php echo $active_page === 'home' ? 'active' : ''; ?>">
                <a href="#" data-nav-url="<?php echo url('index'); ?>" data-tab="home" class="nav-link">主页</a>
            </li>
            <li class="nav-item <?php echo $active_page === 'mod' ? 'active' : ''; ?>">
                <a href="#" data-nav-url="<?php echo url('category', ['slug' => 'mod']); ?>" data-tab="home" class="nav-link">mod</a>
            </li>
            <li class="nav-item <?php echo $active_page === 'exchange' ? 'active' : ''; ?>">
                <a href="#" data-nav-url="<?php echo url('category', ['slug' => 'exchange']); ?>" data-tab="home" class="nav-link">交流</a>
            </li>
            <li class="nav-item <?php echo $active_page === 'chat' ? 'active' : ''; ?>" 
                <?php echo !$isLoggedIn ? 'onclick="showAuthModal(true)"' : ''; ?>>
                <a href="#" class="nav-link" <?php echo !$isLoggedIn ? 'onclick="showAuthModal(true); return false;"' : 'data-nav-url="' . url('category', ['slug' => 'chat']) . '" data-tab="home"'; ?>>闲聊</a>
            </li>

            <!-- 自定义排序下拉菜单，仅当在分类页面时显示 -->
            <?php if (in_array($active_page, ['mod', 'exchange', 'chat'])): 
                $sortLabels = [
                    'latest' => '最新发布',
                    'popular' => '最多阅读',
                    'like' => '最多点赞',
                    'comment' => '最多评论'
                ];
                $currentSortLabel = $sortLabels[$currentSort] ?? '最新发布';
            ?>
            <li class="nav-item sort-item" style="margin-left: auto; display: flex; align-items: center;">
                <div class="custom-sort-dropdown">
                    <button class="sort-dropdown-trigger" type="button" onclick="toggleSortDropdown(event)" data-current="<?php echo $currentSort; ?>">
                        <span class="sort-trigger-label"><?php echo $currentSortLabel; ?></span>
                        <svg class="sort-arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                </div>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<style data-page-style>
/* 确保 nav 使用主题变量 */
.nav-bar {
    background-color: var(--bg-primary);
    border-bottom: 1px solid var(--border-color);
}
.nav-link {
    color: var(--text-secondary);
}
.nav-link:hover {
    color: var(--accent-color);
}
.nav-item.active .nav-link {
    color: var(--accent-color);
    border-bottom-color: var(--accent-color);
}
/* ===== 自定义排序下拉菜单 ===== */
.custom-sort-dropdown {
    position: relative;
    display: inline-block;
    user-select: none;
    -webkit-user-select: none;
}
.sort-dropdown-trigger {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 0.5rem 0.85rem;
    height: 40px;
    min-width: 110px;
    background-color: var(--bg-primary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    cursor: pointer;
    font-size: 0.9rem;
    font-family: inherit;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
    white-space: nowrap;
}
.sort-dropdown-trigger:hover {
    border-color: var(--accent-color);
}
.sort-dropdown-trigger:focus-visible {
    outline: 2px solid var(--accent-color);
    outline-offset: 1px;
}
.sort-dropdown-trigger.active {
    border-color: var(--accent-color);
    box-shadow: 0 0 0 1px var(--accent-color);
}
.sort-arrow {
    flex-shrink: 0;
    transition: transform 0.25s ease;
    color: var(--text-secondary);
}
.sort-dropdown-trigger.active .sort-arrow {
    transform: rotate(180deg);
}
.sort-trigger-label {
    flex: 1;
    text-align: left;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>