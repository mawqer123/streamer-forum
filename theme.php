<?php
// theme.php - 主题设置全屏页面
$active_bottom = 'profile';
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    redirect(url('index'));
}

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);
$userTheme = $currentUser['theme'] ?? 'light';
$userThemeSettings = $currentUser['theme_settings'] ?? [];
$primaryColor = '#2196F3';
if ($userTheme === 'custom' && !empty($userThemeSettings['primary'])) {
    $primaryColor = $userThemeSettings['primary'];
}

$colorThemes = [
    ['name' => '红色', 'color' => '#E53935'],
    ['name' => '青蓝', 'color' => '#00BCD4'],
    ['name' => '深蓝', 'color' => '#1565C0'],
    ['name' => '绿色', 'color' => '#43A047'],
    ['name' => '粉色', 'color' => '#E91E63'],
    ['name' => '黄色', 'color' => '#FDD835'],
    ['name' => '黑色', 'color' => '#424242'],
];

// 匹配当前颜色主题名称
$currentThemeName = '亮色';
if ($userTheme === 'dark') {
    $currentThemeName = '暗色';
} elseif ($userTheme === 'custom') {
    $found = false;
    foreach ($colorThemes as $ct) {
        if (strcasecmp($ct['color'], $primaryColor) === 0) {
            $currentThemeName = $ct['name'];
            $found = true;
            break;
        }
    }
    if (!$found) $currentThemeName = '自定义';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>主题设置 - 主播模拟器论坛</title>
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
    <script>
        document.documentElement.style.setProperty('--accent-color', '<?php echo $primary; ?>');
        document.documentElement.style.setProperty('--accent-gradient-from', '<?php echo $primary; ?>');
        document.documentElement.style.setProperty('--accent-gradient-to', '<?php echo $to; ?>');
    </script>
    <?php } ?>
    <style data-page-style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { margin: 0 !important; padding: 0 !important; height: 100%; }
        body { background-color: var(--bg-secondary); color: var(--text-primary); transition: background-color 0.3s, color 0.3s; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

        .theme-header {
            background-color: var(--accent-color);
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            width: 100%;
        }
        .theme-header-inner {
            max-width: 420px;
            margin: 0 auto;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .back-btn {
            font-size: 1.8rem; line-height: 1; color: white; text-decoration: none;
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; transition: background-color 0.2s; cursor: pointer;
        }
        .back-btn:hover { background-color: rgba(255,255,255,0.2); }
        .page-title { font-size: 1.2rem; font-weight: 600; color: white; flex: 1; text-align: center; }
        .placeholder { width: 40px; }

        .theme-container {
            max-width: 420px;
            margin: 0 auto;
            padding: 1.5rem 1rem;
        }

        .theme-section {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--border-color);
        }

        .theme-presets {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .preset-btn {
            flex: 1;
            min-width: 100px;
            padding: 1rem 0.5rem;
            border: 2px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-primary);
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            border-radius: 0;
        }
        .preset-btn:hover {
            border-color: var(--accent-color);
        }
        .preset-btn.active {
            border-color: var(--accent-color);
            background: var(--accent-color);
            color: white;
        }

        .color-section {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            padding: 1.5rem;
        }
        .color-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
        }
        .color-btn {
            flex: 1 1 calc(33.33% - 0.6rem);
            min-width: 80px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.4rem;
            padding: 0.75rem 0.5rem;
            border: 2px solid var(--border-color);
            background: var(--bg-secondary);
            cursor: pointer;
            transition: all 0.2s;
        }
        .color-btn:hover {
            border-color: var(--accent-color);
        }
        .color-btn.active {
            border-color: var(--accent-color);
            background: var(--accent-color);
        }
        .color-btn.active .color-label {
            color: white;
        }
        .color-swatch {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .color-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-primary);
        }
        .reset-btn {
            display: block;
            width: 100%;
            margin-top: 1.2rem;
            padding: 0.75rem;
            border: 2px dashed var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .reset-btn:hover {
            border-color: var(--text-secondary);
            color: var(--text-primary);
            background: var(--bg-primary);
        }

        .current-theme-info {
            text-align: center;
            padding: 0.75rem;
            margin-bottom: 1.5rem;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        .current-theme-info strong {
            color: var(--accent-color);
        }

        @media (max-width: 768px) {
            .theme-header-inner { padding: 0.8rem 1rem; }
            .back-btn { font-size: 1.6rem; width: 36px; height: 36px; }
            .page-title { font-size: 1.1rem; }
        }
        @media (max-width: 480px) {
            .theme-header-inner { padding: 0.6rem 1rem; }
            .back-btn { font-size: 1.5rem; width: 32px; height: 32px; }
            .page-title { font-size: 1rem; }
            .preset-btn { min-width: 80px; padding: 0.8rem 0.4rem; font-size: 0.85rem; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <div id="page-content">
        <div class="theme-header">
            <div class="theme-header-inner">
                <a href="#" data-nav-url="<?php echo url('profile'); ?>" class="back-btn">←</a>
                <h2 class="page-title">主题设置</h2>
                <span class="placeholder"></span>
            </div>
        </div>
        <main class="main-content" style="padding-top: 0;">
            <div class="theme-container">
                <div class="current-theme-info">
                    当前主题：<strong><?php echo htmlspecialchars($currentThemeName); ?></strong>
                </div>

                <div class="theme-section">
                    <h3 class="section-title">模式</h3>
                    <div class="theme-presets">
                        <button class="preset-btn <?php echo $userTheme === 'light' || $userTheme === 'custom' ? 'active' : ''; ?>" data-theme="mode" data-mode="light">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0;"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                            亮色
                        </button>
                        <button class="preset-btn <?php echo $userTheme === 'dark' ? 'active' : ''; ?>" data-theme="mode" data-mode="dark">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0;"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                            暗色
                        </button>
                    </div>
                </div>

                <div class="color-section">
                    <h3 class="section-title">主题色</h3>
                    <div class="color-grid">
                        <?php foreach ($colorThemes as $ct):
                            $isActive = ($userTheme === 'custom' && strcasecmp($ct['color'], $primaryColor) === 0);
                        ?>
                        <button class="color-btn <?php echo $isActive ? 'active' : ''; ?>" data-color="<?php echo $ct['color']; ?>">
                            <span class="color-swatch" style="background:<?php echo $ct['color']; ?>"></span>
                            <span class="color-label"><?php echo $ct['name']; ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <button class="reset-btn" id="resetThemeBtn">恢复默认主题颜色</button>
                </div>
            </div>
        <script>
        (function(){
            var pc = document.getElementById('page-content');
            if (!pc) return;

            // 模式按钮（亮色/暗色）
            pc.querySelectorAll('.theme-presets .preset-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var mode = this.dataset.mode;
                    pc.querySelectorAll('.theme-presets .preset-btn').forEach(function(b) { b.classList.remove('active'); });
                    this.classList.add('active');
                    saveTheme(mode, null);
                });
            });

            // 颜色按钮
            pc.querySelectorAll('.color-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var color = this.dataset.color;
                    pc.querySelectorAll('.color-btn').forEach(function(b) { b.classList.remove('active'); });
                    this.classList.add('active');
                    saveTheme('custom', { primary: color });
                });
            });

            // 恢复默认
            var resetBtn = pc.querySelector('#resetThemeBtn');
            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    pc.querySelectorAll('.color-btn').forEach(function(b) { b.classList.remove('active'); });
                    pc.querySelectorAll('.preset-btn').forEach(function(b) { b.classList.remove('active'); });
                    var lightBtn = pc.querySelector('.preset-btn[data-mode="light"]');
                    if (lightBtn) lightBtn.classList.add('active');
                    saveTheme('light', null);
                });
            }

            function getColorName(color) {
                var map = {
                    '#E53935': '红色',
                    '#00BCD4': '青蓝',
                    '#1565C0': '深蓝',
                    '#43A047': '绿色',
                    '#E91E63': '粉色',
                    '#FDD835': '黄色',
                    '#424242': '黑色'
                };
                return map[color] || '自定义';
            }

            function getCurrentMode() {
                var modeBtn = pc.querySelector('.theme-presets .preset-btn.active');
                if (modeBtn) return modeBtn.dataset.mode;
                // 读取当前 data-theme
                var dt = document.documentElement.getAttribute('data-theme');
                return (dt === 'dark') ? 'dark' : 'light';
            }

            function applyAccent(settings) {
                if (settings && settings.primary) {
                    document.documentElement.style.setProperty('--accent-color', settings.primary);
                    document.documentElement.style.setProperty('--accent-gradient-from', settings.primary);
                    document.documentElement.style.setProperty('--accent-gradient-to', settings.primary);
                } else {
                    document.documentElement.style.removeProperty('--accent-color');
                    document.documentElement.style.removeProperty('--accent-gradient-from');
                    document.documentElement.style.removeProperty('--accent-gradient-to');
                }
            }

            function saveTheme(theme, settings) {
                var currentMode = (theme === 'custom') ? getCurrentMode() : theme;
                // 先立即更新视觉效果，不等待服务器
                if (theme === 'light' || theme === 'dark') {
                    document.documentElement.setAttribute('data-theme', theme);
                    applyAccent(null);
                } else if (theme === 'custom' && settings) {
                    document.documentElement.setAttribute('data-theme', currentMode);
                    applyAccent(settings);
                }
                // 同步更新 localStorage（保证即使 fetch 失败也不回退）
                localStorage.setItem('forum_theme', theme);
                if (theme === 'light' || theme === 'dark') {
                    localStorage.removeItem('forum_accent_color');
                    localStorage.removeItem('forum_theme_mode');
                } else if (theme === 'custom' && settings) {
                    localStorage.setItem('forum_accent_color', settings.primary);
                    localStorage.setItem('forum_theme_mode', currentMode);
                }
                // 服务器保存（不阻塞 UI）
                var formData = new FormData();
                formData.append('action', 'update_theme');
                formData.append('theme', theme);
                if (settings) formData.append('theme_settings', JSON.stringify(settings));
                fetch('/auth.php', { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        if (result.success) {
                            var infoEl = document.querySelector('.current-theme-info strong');
                            if (infoEl) {
                                var name = '';
                                if (theme === 'light') name = '亮色';
                                else if (theme === 'dark') name = '暗色';
                                else if (theme === 'custom' && settings) name = getColorName(settings.primary);
                                if (name) infoEl.textContent = name;
                            }
                        } else {
                            alert(result.message || '保存失败');
                        }
                    })
                    .catch(function() {
                        alert('网络错误，请重试');
                    });
            }
        })();
        </script>
        </main>
    </div><!-- /page-content -->
    <?php include 'auth_modal.php'; ?>
    <?php include 'spa.php'; ?>
</body>
</html>
