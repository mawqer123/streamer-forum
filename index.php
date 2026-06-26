<?php
$active_page = 'home';
$active_bottom = 'home';
require_once __DIR__ . '/functions.php';

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);

try {
    $pdo = getDbConnection();
    $slidesStmt = $pdo->query("SELECT * FROM home_slides WHERE is_active = 1 ORDER BY sort_order ASC, created_at DESC");
    $slides = $slidesStmt->fetchAll();
    $linksStmt = $pdo->query("SELECT * FROM home_links WHERE is_active = 1 ORDER BY sort_order ASC, created_at DESC");
    $links = $linksStmt->fetchAll();
} catch (Exception $e) {
    $slides = [];
    $links = [];
}

trackOnlineUser();

$hasSignedInToday = false;
$continuousDays = 0;
if (isLoggedIn()) {
    $hasSignedInToday = hasSignedInToday($_SESSION['user_id']);
    $continuousDays = getContinuousSigninDays($_SESSION['user_id']);
}

// 获取注册开关状态
$registrationEnabled = isRegistrationEnabled();
// 获取用户总数（用于统计卡片）
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="description" content="主播模拟器（Streamer Simulator）玩家交流社区 - MOD分享、游戏攻略、玩家交流。免费注册，支持手机浏览。">
    <meta property="og:title" content="主播模拟器论坛">
    <meta property="og:description" content="主播模拟器玩家交流社区 - MOD分享、游戏攻略、玩家交流">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://zbgame.hyperspark.cn/">
    <title>主播模拟器论坛 - Streamer Simulator 玩家社区</title>
    <link rel="stylesheet" href="/css/style.css?v=2">
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
    }
    ?>
    <style data-page-style>
        /* index.php 特有样式 */
        .slideshow-container {
            width: calc(100% + 1.5rem);
            max-width: calc(100% + 1.5rem);
            margin-left: -0.75rem;
            margin-top: -0.75rem;
            position: relative;
            background: var(--bg-primary);
            overflow: hidden;
            max-height: 360px;
            aspect-ratio: 21 / 9;
        }
        @media (max-width: 1024px) {
            .slideshow-container { max-height: 260px; aspect-ratio: 3 / 1; }
        }
        @media (max-width: 768px) {
            .slideshow-container { max-height: 200px; aspect-ratio: 16 / 7; margin-top: 0; }
        }
        @media (max-width: 480px) {
            .slideshow-container { max-height: 150px; aspect-ratio: 16 / 6; }
        }
        .slideshow {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            transition: opacity 0.5s ease-in-out;
        }
        .slide a {
            display: block;
            height: 100%;
            width: 100%;
        }
        .slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .slideshow-dots {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            display: flex;
            gap: 0;
            z-index: 10;
        }
        .slideshow-dot {
            flex: 1;
            height: 100%;
            background: rgba(255,255,255,0.35);
            border: none;
            cursor: pointer;
            transition: background 0.3s;
            padding: 0;
        }
        .slideshow-dot.active {
            background: rgba(255,255,255,0.85);
        }

        .links-section {
            max-width: 1200px;
            margin: 0 auto 2rem auto;
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            box-shadow: var(--card-shadow);
        }
        .section-title {
            color: var(--text-primary);
            font-size: 1.2rem;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .links-grid {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .link-card {
            display: flex;
            align-items: center;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.95rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            transition: all 0.2s ease;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
        }
        .link-card:hover {
            background: var(--accent-color);
            color: #fff;
            border-color: var(--accent-color);
        }
        .link-card .link-label {
            line-height: 1.4;
        }

        /* ===== 时间卡片样式（支持白天/夜晚动态） ===== */
        .time-card {
            max-width: 1200px;
            margin: 0 auto 2rem auto;
            background: #1a1e2c;
            border-radius: 0;
            padding: 0;
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            cursor: pointer;
            transition: all 0.3s ease-in-out;
            overflow: hidden;
            position: relative;
            color: white;
            backdrop-filter: blur(2px);
        }
        /* 蓝色白天模式 */
        .time-card.blue-mode {
            background: #4facfe;
            color: #ffffff;
        }
        .time-card.blue-mode .moon-icon,
        .time-card.blue-mode .sun-icon {
            color: rgba(255,255,255,0.9);
        }
        /* 金色晨昏过渡模式（黎明/黄昏） */
        .time-card.gold-mode {
            background: #f5a623;
            color: #2d2d2d;
        }
        .time-card.gold-mode .moon-icon,
        .time-card.gold-mode .sun-icon {
            color: rgba(255,255,255,0.9);
        }
        .time-card:hover {
            box-shadow: 0 12px 35px rgba(0,0,0,0.4);
            transform: translateY(-2px);
        }
        .time-card-inner {
            padding: 1.2rem 1.5rem;
            position: relative;
            z-index: 1;
        }
        .time-text {
            font-size: 3.2rem;
            font-weight: 700;
            margin: 0;
            font-family: 'Gill Sans', 'Gill Sans MT', Calibri, 'Trebuchet MS', sans-serif;
            letter-spacing: 2px;
            display: flex;
            align-items: baseline;
            gap: 8px;
            flex-wrap: wrap;
        }
        .time-sub-text {
            font-size: 1.1rem;
            font-weight: 500;
            margin-left: 5px;
        }
        .day-text {
            font-size: 1.1rem;
            margin-top: 0.5rem;
            font-weight: 500;
            font-family: 'Gill Sans', 'Gill Sans MT', Calibri, 'Trebuchet MS', sans-serif;
            opacity: 0.9;
        }
        .moon-icon, .sun-icon {
            position: absolute;
            right: 1.5rem;
            top: 1.2rem;
            font-size: 1.8rem;
            transition: all 0.3s ease-in-out;
        }
        .time-card:hover .moon-icon,
        .time-card:hover .sun-icon {
            font-size: 2rem;
            transform: rotate(10deg);
        }
        @media (max-width: 768px) {
            .time-text { font-size: 2.5rem; }
            .time-sub-text { font-size: 1rem; }
            .day-text { font-size: 0.95rem; }
            .moon-icon, .sun-icon { font-size: 1.5rem; right: 1rem; top: 1rem; }
            .time-card-inner { padding: 1rem 1.2rem; }
        }
        @media (max-width: 480px) {
            .time-text { font-size: 2rem; }
            .day-text { font-size: 0.85rem; }
        }

        .signin-section {
            max-width: 1200px;
            margin: 0 auto 2rem auto;
            background: var(--bg-primary);
            border-radius: 0;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
        }
        .signin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .signin-title {
            color: var(--text-primary);
            font-size: 1.2rem;
            font-weight: 600;
        }
        .signin-status {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .signin-button {
            background: var(--accent-gradient-from);
            color: white;
            border: none;
            border-radius: 0;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 6px rgba(94, 114, 228, 0.3);
        }
        .signin-button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(94, 114, 228, 0.4);
        }
        .signin-button:disabled {
            background: #a0aec0;
            cursor: not-allowed;
            box-shadow: none;
        }
        .signin-button.signed-in {
            background: #38a169;
            box-shadow: 0 4px 6px rgba(56, 161, 105, 0.3);
        }
        .signin-details {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        .signin-detail-item {
            text-align: center;
        }
        .signin-detail-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--accent-color);
            margin-bottom: 0.25rem;
        }
        .signin-detail-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .signin-message {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            background: rgba(94, 114, 228, 0.1);
            border-radius: 0;
            color: var(--text-primary);
            font-size: 0.9rem;
            text-align: center;
            border-left: 3px solid var(--accent-color);
        }
        .welcome-message {
            text-align: center;
            color: var(--text-secondary);
            margin-top: 2rem;
        }
        .registration-closed-banner {
            max-width: 1200px;
            margin: 0 auto 2rem auto;
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            border-radius: 0;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        .registration-closed-banner .banner-icon {
            font-size: 1.8rem;
        }
        .registration-closed-banner .banner-text {
            flex: 1;
            color: #856404;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .registration-closed-banner .banner-text strong {
            font-weight: 700;
        }

        /* 新增网站统计卡片样式 */
        .stats-site-section {
            max-width: 1200px;
            margin: 0 auto 2rem auto;
            padding: 0;
        }
        .stats-site-card {
            background: var(--bg-primary);
            border-radius: 0;
            padding: 1rem 1.5rem;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
            flex-wrap: wrap;
            border: 1px solid var(--border-color);
        }
        .stats-site-item {
            flex: 1;
            text-align: center;
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .stats-site-label {
            font-size: 1rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .stats-site-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--accent-color);
        }
        .stats-site-divider {
            width: 1px;
            height: 40px;
            background: var(--border-color);
        }
        /* 运行天数数字渐变颜色动画 */


        @media (max-width: 768px) {
            .links-section { padding: 1rem; border-radius: 8px; }
            .link-card { padding: 0.65rem 0.75rem; font-size: 0.9rem; }
            .signin-detail-value { font-size: 1rem; }
            .signin-detail-label { font-size: 0.75rem; }
            .registration-closed-banner { padding: 0.75rem 1rem; }
            .registration-closed-banner .banner-icon { font-size: 1.4rem; }
            .registration-closed-banner .banner-text { font-size: 0.85rem; }

        }
        @media (max-width: 480px) {
            .links-grid { gap: 0.4rem; }
            .link-card { padding: 0.55rem 0.75rem; font-size: 0.85rem; }
            .signin-details { gap: 0.25rem; }
            .signin-detail-value { font-size: 0.9rem; }
            .signin-detail-label { font-size: 0.7rem; }
            .signin-message { font-size: 0.85rem; padding: 0.6rem; }
            .registration-closed-banner { flex-direction: column; align-items: flex-start; gap: 0.5rem; }

        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <?php $currentTab = 'home'; include 'header.php'; ?>
    <?php include 'nav.php'; ?>
    <div id="page-content">
    <main class="main-content">
        <div class="slideshow-container">
            <?php if (!empty($slides)): ?>
                <div class="slideshow" id="slideshow">
                    <?php foreach ($slides as $index => $slide): ?>
                        <div class="slide">
                            <?php
                            $svgTitle = escape($slide['title']);
                            $color = '5e72e4';
                            $svgFallback = 'data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%221200%22%20height%3D%22300%22%20viewBox%3D%220%200%201200%20300%22%3E%3Crect%20width%3D%22100%25%22%20height%3D%22100%25%22%20fill%3D%22' . urlencode($color) . '%22%2F%3E%3Ctext%20x%3D%2250%25%22%20y%3D%2250%25%22%20font-family%3D%22Arial%22%20font-size%3D%2224%22%20fill%3D%22white%22%20text-anchor%3D%22middle%22%20dy%3D%22.3em%22%3E' . urlencode($svgTitle) . '%3C%2Ftext%3E%3C%2Fsvg%3E';
                            $linkUrl = escape($slide['link_url'] ?? '');
                            $linkTarget = ($slide['link_target'] ?? 0) ? '_blank' : '_self';
                            ?>
                            <?php if (!empty($linkUrl)): ?>
                                <a href="<?php echo $linkUrl; ?>" target="<?php echo $linkTarget; ?>" aria-label="<?php echo escape($slide['title']); ?>">
                                    <img src="<?php echo getImageUrl(escape($slide['image_url'])); ?>" 
                                         alt="<?php echo escape($slide['title']); ?>"
                                         onerror="this.src='<?php echo $svgFallback; ?>'">
                                </a>
                            <?php else: ?>
                                <img src="<?php echo getImageUrl(escape($slide['image_url'])); ?>" 
                                     alt="<?php echo escape($slide['title']); ?>"
                                     onerror="this.src='<?php echo $svgFallback; ?>'">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="slideshow-dots" id="slideshowDots">
                    <?php foreach ($slides as $index => $slide): ?>
                        <button class="slideshow-dot <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>"></button>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-data" style="height: 300px; display: flex; align-items: center; justify-content: center;">
                    <p>暂无幻灯片内容</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 注册关闭声明（当后台关闭注册时显示） -->
        <?php if (!$registrationEnabled): ?>
        <div class="registration-closed-banner">
            <div class="banner-icon"></div>
            <div class="banner-text">
                <strong>新用户注册已关闭</strong><br>
                站点目前已暂停新用户注册，请前往网址zbgame.hyperspark.cn。
            </div>
        </div>
        <?php endif; ?>
        
        <script id="__LINKS_DATA__" type="application/json"><?php echo json_encode(array_map(function($l) {
            return ['title' => $l['title'], 'url' => $l['link_url'], 'target' => $l['link_target'] ?? '_self'];
        }, $links ?? [])); ?></script>
        <div id="links-container"></div>
        <script>
        if (typeof renderLinksCards === 'function') {
            renderLinksCards('#links-container', '常用链接');
        }
        </script>
        
        <div class="signin-section">
            <div class="signin-header">
                <div class="signin-title"> 每日签到</div>
                <div class="signin-status">
                    <?php if (isLoggedIn()): ?>
                        <?php if ($hasSignedInToday): ?>
                            <button class="signin-button signed-in" disabled> 今日已签到</button>
                        <?php else: ?>
                            <button class="signin-button" id="signinButton" onclick="signIn()"> 立即签到</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <button class="signin-button" onclick="showAuthModal(true)"> 登录后签到</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (isLoggedIn()): ?>
                <div class="signin-details">
                    <div class="signin-detail-item">
                        <div class="signin-detail-value"><?php echo getUserPoints($_SESSION['user_id']); ?></div>
                        <div class="signin-detail-label">当前积分</div>
                    </div>
                    <div class="signin-detail-item">
                        <div class="signin-detail-value"><?php echo $continuousDays; ?></div>
                        <div class="signin-detail-label">连续签到</div>
                    </div>
                    <div class="signin-detail-item">
                        <div class="signin-detail-value">+<?php echo SIGNIN_POINTS; ?></div>
                        <div class="signin-detail-label">基础奖励</div>
                    </div>
                </div>
                <?php if ($continuousDays >= SIGNIN_BONUS_DAYS): ?>
                    <div class="signin-message">
                         您已连续签到 <?php echo $continuousDays; ?> 天，获得额外 <?php echo SIGNIN_BONUS_POINTS; ?> 积分奖励！
                    </div>
                <?php elseif ($continuousDays > 0): ?>
                    <div class="signin-message">
                        再连续签到 <?php echo SIGNIN_BONUS_DAYS - $continuousDays; ?> 天，即可获得额外 <?php echo SIGNIN_BONUS_POINTS; ?> 积分奖励！
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="signin-message">
                     登录后每日签到可获得积分奖励，连续签到还有额外奖励！
                </div>
            <?php endif; ?>
        </div>


        
        <?php if (isLoggedIn()): ?>
            <p class="welcome-message">欢迎回来，<?php echo escape($_SESSION['username']); ?>！</p>
        <?php else: ?>
            <p class="welcome-message">请登录后查看更多精彩内容</p>
        <?php endif; ?>
    <script>
        // Slideshow initialized by TypeScript (js/layout.js)
        // Touch swipe handled by TS SlideshowManager
        window.__ocPageIntervals = window.__ocPageIntervals || [];
        
        function signIn() {
        
        function signIn() {
            const button = document.getElementById('signinButton');
            if (!button) return;
            button.disabled = true;
            button.innerHTML = '签到中...';
            fetch('/auth.php?action=signin', { method: 'POST' })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        button.className = 'signin-button signed-in';
                        button.innerHTML = ' 今日已签到';
                        alert(result.message);
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        button.disabled = false;
                        button.innerHTML = ' 立即签到';
                        alert(result.message);
                    }
                })
                .catch(error => {
                    button.disabled = false;
                    button.innerHTML = ' 立即签到';
                    alert('签到失败，请检查网络连接');
                });
        }
        

        // 运行天数已移入管理员面板
    </script>
    </main>
    </div><!-- /page-content -->
    <?php include 'bottom_nav.php'; ?>
    <?php include 'auth_modal.php'; ?>

    <script src="/js/click-ripple.js?v=<?php echo filemtime(__DIR__ . '/js/click-ripple.js'); ?>"></script>
    <script>
    injectRippleStyle();
    setTimeout(function() {
      if (typeof initRippleEffects === 'function') initRippleEffects();
    }, 100);
    </script>
</body>
</html>