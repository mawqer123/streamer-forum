<?php
// level.php - 等级经验值页面
$active_page = 'level';
$active_bottom = 'level';
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    redirect(url('index'));
}

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);
$userId = $currentUser['id'];

$expInfo = getExpProgress($currentUser['exp'] ?? 0);
$level = $expInfo['level'];
$name = $expInfo['name'];
$nextName = $expInfo['next_name'];
$exp = $expInfo['exp'];
$needed = $expInfo['needed'];
$have = $expInfo['have'];
$progress = $expInfo['progress'];

// 距离下一级还差多少
$remaining = $needed - $have;

// 获取签名记录
try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM daily_signins WHERE user_id = ?");
    $stmt->execute([$userId]);
    $totalSignins = $stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM comments WHERE user_id = ?");
    $stmt->execute([$userId]);
    $totalComments = $stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM posts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $totalPosts = $stmt->fetch()['total'];
} catch (Exception $e) {
    $totalSignins = 0;
    $totalComments = 0;
    $totalPosts = 0;
}

// 预计还需
$todayExp = 0;
$dailyStats = [];
try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT daily_comments, daily_posts, last_daily_reset FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $daily = $stmt->fetch();
    if ($daily && $daily['last_daily_reset'] === date('Y-m-d')) {
        $dailyStats = $daily;
    }
} catch (Exception $e) {}

// 今日剩余可获经验
$remainingCommentExp = max(0, MAX_DAILY_COMMENT_EXP - ($dailyStats['daily_comments'] ?? 0));
$remainingPostExp = max(0, MAX_DAILY_POST_EXP - ($dailyStats['daily_posts'] ?? 0));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>等级经验 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <?php if ($currentUser && isset($currentUser['theme']) && $currentUser['theme'] === 'custom' && !empty($currentUser['theme_settings'])): ?>
    <style><?php
        $settings = $currentUser['theme_settings'];
        $primary = $settings['primary'] ?? '#2196F3';
        list($r, $g, $b) = sscanf($primary, "#%02x%02x%02x");
        $r = max(0, $r - 20); $g = max(0, $g - 20); $b = max(0, $b - 20);
        $to = sprintf("#%02x%02x%02x", $r, $g, $b);
        echo ":root{--accent-color:$primary;--accent-gradient-from:$primary;--accent-gradient-to:$to;}";
    ?></style>
    <?php endif; ?>
    <style>
        .level-topbar {
            background: var(--accent-gradient-from);
            color: white;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .level-topbar-back {
            position: absolute;
            left: 1rem;
            color: white;
            text-decoration: none;
            font-size: 1.8rem;
            line-height: 1;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }
        .level-topbar-back:hover { background-color: rgba(255,255,255,0.2); }
        .level-topbar-title { font-size: 1.2rem; font-weight: 600; }
        .level-page { max-width: 700px; margin: 0 auto; padding: 1rem; }
        .level-hero {
            text-align: center;
            padding: 2rem 1rem;
            background: var(--accent-gradient-from);
            color: white;
            margin-bottom: 1.5rem;
        }
        .level-number { font-size: 3.5rem; font-weight: 800; line-height: 1.2; }
        .level-title { font-size: 1.2rem; opacity: 0.9; margin-top: 0.3rem; }
        .level-progress-wrap { margin-top: 1rem; }
        .level-progress-bar {
            height: 8px;
            background: rgba(255,255,255,0.3);
            border-radius: 4px;
            overflow: hidden;
        }
        .level-progress-fill {
            height: 100%;
            max-width: 100%;
            background: #fff;
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        .level-progress-text { font-size: 0.85rem; margin-top: 0.4rem; opacity: 0.85; }
        .level-card {
            background: var(--bg-primary);
            padding: 1.2rem;
            margin-bottom: 1rem;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
        }
        .level-card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.8rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        .level-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            font-size: 0.9rem;
            color: var(--text-primary);
        }
        .level-row + .level-row { border-top: 1px solid var(--border-color); }
        .level-row-label { color: var(--text-secondary); }
        .level-row-value { font-weight: 600; }
        .exp-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .exp-table th {
            background: var(--bg-secondary);
            padding: 0.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-color);
        }
        .exp-table td {
            padding: 0.4rem 0.5rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        .exp-table .highlight { background: var(--link-hover-bg); font-weight: 600; }
        .level-all-btn {
            display: inline-block;
            margin-top: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--accent-gradient-from);
            color: white;
            border: none;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <div class="level-topbar">
        <a href="<?php echo url('profile'); ?>" class="level-topbar-back" aria-label="返回">＜</a>
        <span class="level-topbar-title">等级</span>
    </div>
    <main class="main-content">
        <div class="level-page">
            <div class="level-hero">
                <div class="level-number">Lv.<?php echo $level; ?></div>
                <div class="level-title"><?php echo escape($name); ?></div>
                <div class="level-progress-wrap">
                    <div class="level-progress-bar">
                        <div class="level-progress-fill" style="width: <?php echo min(100, max(0, $progress)); ?>%; max-width: 100%"></div>
                    </div>
                    <div class="level-progress-text">
                        <?php echo number_format($exp); ?>/<?php echo number_format($expInfo['next_level_exp']); ?> (<?php echo $progress; ?>%)
                        <?php if ($level < MAX_LEVEL): ?>
                            — 距 <?php echo escape($nextName); ?> 还差 <?php echo number_format($remaining); ?> 经验
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="level-card">
                <div class="level-card-title">经验获取统计</div>
                <div class="level-row">
                    <span class="level-row-label">累计签到</span>
                    <span class="level-row-value"><?php echo number_format($totalSignins); ?> 次</span>
                </div>
                <div class="level-row">
                    <span class="level-row-label">累计评论</span>
                    <span class="level-row-value"><?php echo number_format($totalComments); ?> 条</span>
                </div>
                <div class="level-row">
                    <span class="level-row-label">累计发帖</span>
                    <span class="level-row-value"><?php echo number_format($totalPosts); ?> 篇</span>
                </div>
                <div class="level-row">
                    <span class="level-row-label">当前经验值</span>
                    <span class="level-row-value"><?php echo number_format($exp); ?></span>
                </div>
            </div>

            <div class="level-card">
                <div class="level-card-title">今日经验获取</div>
                <div class="level-row">
                    <span class="level-row-label">签到</span>
                    <span class="level-row-value">+<?php echo EXP_PER_SIGNIN; ?> 经验</span>
                </div>
                <div class="level-row">
                    <span class="level-row-label">评论</span>
                    <span class="level-row-value">+<?php echo EXP_PER_COMMENT; ?> 经验/次 (今日剩余 <?php echo $remainingCommentExp; ?> 次)</span>
                </div>
                <div class="level-row">
                    <span class="level-row-label">发帖</span>
                    <span class="level-row-value">+<?php echo EXP_PER_POST; ?> 经验/次 (今日剩余 <?php echo $remainingPostExp; ?> 次)</span>
                </div>
                <div class="level-row">
                    <span class="level-row-label">每日经验上限</span>
                    <span class="level-row-value">签到1次 + 评论<?php echo MAX_DAILY_COMMENT_EXP; ?>次 + 发帖<?php echo MAX_DAILY_POST_EXP; ?>次</span>
                </div>
            </div>

            <div style="text-align:center;margin-bottom:1rem;">
                <button class="level-all-btn" onclick="toggleLevelTable()">展开全部100级列表</button>
            </div>

            <div class="level-card" id="levelTableWrap" style="display:none;overflow-x:auto;">
                <div class="level-card-title">1-100级全览</div>
                <table class="exp-table">
                    <thead>
                        <tr><th>等级</th><th>称号</th><th>所需经验</th></tr>
                    </thead>
                    <tbody>
                        <?php 
                        $userLvl = $level;
                        for ($i = 1; $i <= MAX_LEVEL; $i++): 
                            $lvlExp = getLevelExp($i);
                            $lvlName = getLevelName($i);
                            $highlight = ($i == $userLvl || $i == $userLvl || ($i > $userLvl && $i <= $userLvl + 1)) ? 'highlight' : '';
                        ?>
                        <tr class="<?php echo ($i == $userLvl) ? 'highlight' : ''; ?>">
                            <td>Lv.<?php echo $i; ?></td>
                            <td><?php echo escape($lvlName); ?></td>
                            <td><?php echo number_format($lvlExp); ?></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    <script>
    function toggleLevelTable() {
        var wrap = document.getElementById('levelTableWrap');
        wrap.style.display = wrap.style.display === 'none' ? 'block' : 'none';
    }
    </script>
</body>
</html>
