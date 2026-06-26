<?php
require_once __DIR__ . '/functions.php';

$currentUser = getCurrentUser();
$maintenanceMode = getSetting('maintenance_mode', '0') === '1';
if (!$maintenanceMode) {
    redirect(url('index'));
}
if ($currentUser && (isAdmin() || !empty($currentUser['is_founder']) || !empty($currentUser['maintenance_bypass']))) {
    redirect(url('index'));
}

$siteName = getSetting('site_name', '论坛');
$maintenanceTitle = getSetting('maintenance_title', '维护中');
$maintenanceMessage = getSetting('maintenance_message', '论坛正在进行系统维护，请稍后再来。');

$pageAccentColor = '#2196F3';
if ($currentUser && isset($currentUser['theme']) && $currentUser['theme'] === 'custom' && !empty($currentUser['theme_settings'])) {
    $settings = json_decode($currentUser['theme_settings'], true);
    if ($settings && isset($settings['primary'])) {
        $pageAccentColor = $settings['primary'];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($maintenanceTitle); ?> - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        :root {
            --accent-color: <?php echo $pageAccentColor; ?>;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans SC', sans-serif;
            background: #f5f5f5;
            display: flex;
            flex-direction: column;
            color: #333;
            min-height: 100vh;
        }
        /* 头部：与论坛 header-bar 风格一致 */
        .header-bar {
            background: <?php echo $pageAccentColor; ?>;
            padding: 0 16px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .header-bar .site-title {
            font-size: 18px;
            font-weight: 700;
            color: #fff;
        }
        .header-bar .header-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-bar .header-right .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            overflow: hidden;
            flex-shrink: 0;
        }
        .header-bar .header-right .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .header-bar .header-right .user-name {
            font-size: 14px;
            font-weight: 500;
            color: #fff;
        }
        .header-bar .header-right .auth-btn {
            padding: 7px 16px;
            border-radius: 6px;
            background: rgba(255,255,255,0.2);
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }
        .header-bar .header-right .auth-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        .header-bar .header-right .auth-btn.signup {
            background: rgba(255,255,255,0.35);
        }
        .header-bar .header-right .auth-btn.signup:hover {
            background: rgba(255,255,255,0.45);
        }
        /* 主体：居中展示维护信息 */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            text-align: center;
        }
        .main-content h1 {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 16px;
            color: #333;
        }
        .main-content p {
            font-size: 15px;
            line-height: 1.7;
            color: #666;
            max-width: 400px;
        }
        .logout-btn-left {
            background: transparent;
            border: 2px solid rgba(255,255,255,0.5);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .logout-btn-left:hover {
            background: rgba(255,255,255,0.15);
            border-color: white;
        }
    </style>
</head>
<body>
    <div class="header-bar" style="display: flex; align-items: center; justify-content: space-between; padding: 0 1rem;">
        <div class="header-left">
            <?php if ($currentUser): ?>
                <button onclick="doLogout()" class="logout-btn-left">退出</button>
            <?php endif; ?>
        </div>
        <div class="header-right">
            <?php if ($currentUser): ?>
                <span class="user-name"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                <div class="user-avatar">
                    <?php if (!empty($currentUser['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="">
                    <?php else: ?>
                        <?php echo htmlspecialchars(mb_substr($currentUser['username'], 0, 1, 'UTF-8')); ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <button onclick="showAuthModal(true)" class="auth-btn">登录</button>
                <button onclick="showAuthModal(false)" class="auth-btn signup">注册</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="main-content">
        <h1><?php echo htmlspecialchars($maintenanceTitle); ?></h1>
        <p><?php echo nl2br(htmlspecialchars($maintenanceMessage)); ?></p>
    </div>
<?php include __DIR__ . '/auth_modal.php'; ?>
<script>
function doLogout() {
    if (!confirm('确定要退出登录吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'auth.php?action=logout', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        window.location.href = '/';
    };
    xhr.send();
}
</script>
</body>
</html>
