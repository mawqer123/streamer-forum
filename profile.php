<?php
$active_bottom = 'profile';
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    redirect(url('index'));
}

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);

$continuousDays = 0;
$totalSignins = 0;
if ($currentUser) {
    $continuousDays = getContinuousSigninDays($currentUser['id']);
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM daily_signins WHERE user_id = ?");
        $stmt->execute([$currentUser['id']]);
        $result = $stmt->fetch();
        $totalSignins = $result ? $result['count'] : 0;
    } catch (PDOException $e) {
        $totalSignins = 0;
    }
}

$followStats = getUserFollowStats($currentUser['id']);
$receivedLikes = getUserReceivedLikes($currentUser['id']);
$levelProgress = getExpProgress($currentUser['exp'] ?? 0);

$avatarUrl = !empty($currentUser['avatar']) ? htmlspecialchars($currentUser['avatar']) : null;
$avatarText = !empty($currentUser['avatar_text']) ? htmlspecialchars($currentUser['avatar_text']) : '';
$backgroundUrl = null;

$userTheme = $currentUser['theme'] ?? 'light';
$userThemeSettings = $currentUser['theme_settings'] ?? [];
$userThemeSettingsJson = json_encode($userThemeSettings);

// 获取公开显示的ID
$displayId = !empty($currentUser['public_uid']) ? $currentUser['public_uid'] : 'UID:' . $currentUser['id'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>我的 - 主播模拟器论坛</title>
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
        /* profile.php 特有样式 */
        body.profile-page { margin: 0; padding: 0; }
        body.profile-page #top-bar { display: none !important; }
        body.profile-page .main-content { padding: 0 !important; margin: 0 !important; }
        .profile-container {
            width: 100%; max-width: none; margin: 0 0 1rem 0;
            background: var(--bg-primary); border-radius: 0; box-shadow: none;
            color: var(--text-primary); transition: all 0.3s;
        }
        .user-info-card {
            position: relative; background: var(--bg-primary); padding: 1rem 2rem 0;
            color: var(--text-primary); display: flex; align-items: center; gap: 1.5rem;
        }
        .user-avatar {
            width: 100px; height: 100px; border-radius: 50%; background: var(--bg-secondary);
            display: flex; align-items: center; justify-content: center; font-size: 3rem;
            font-weight: bold; color: var(--text-primary); border: 4px solid var(--border-color);
            flex-shrink: 0; overflow: hidden; position: relative;
            text-transform: uppercase;
        }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-placeholder {
            width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
            background: var(--bg-secondary);
        }
        .user-details { flex: 1; display: flex; flex-direction: row; align-items: center; gap: 0;
        }
        .user-info-inner {
            display: flex; flex-direction: column; align-items: flex-start; flex: 1;
        }
        .user-name-row {
            display: flex; align-items: center; margin-bottom: 0.15rem;
            justify-content: flex-start; width: 100%;
            overflow-x: auto; white-space: nowrap;
        }
        .user-name-display { font-size: 1.8rem; font-weight: 600; text-align: left; }
        .founder-tag {
            display: inline-block; background: #fbbf24; color: white;
            padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.6rem; font-weight: 600;
            margin-left: 0.5rem; border: 1px solid #f59e0b; line-height: 1;
        }
        .admin-tag {
            display: inline-block; background: var(--accent-gradient-from); color: white;
            padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 600;
            margin-left: 0.75rem; border: none;
        }
        .user-uid {
            font-size: 1rem; opacity: 0.9; display: flex; align-items: center;
            text-align: left; margin-top: 0;
        }
        .stats-row {
            margin-top: 0.5rem;
            padding: 0.75rem 2rem; display: flex;
            flex-wrap: wrap; justify-content: space-around; align-items: center;
            border-top: 1px solid var(--border-color);
        }
        .stat-item {
            display: flex; align-items: center; gap: 0.5rem; color: var(--text-primary);
            font-size: 1rem; text-decoration: none;
        }
        .stat-label { color: var(--text-secondary); }
        .stat-value { font-weight: 700; color: var(--accent-color); font-size: 1.2rem; }
        .stat-divider { width: 1px; height: 24px; background-color: var(--border-color); }
        .tools-card {
            background: var(--bg-primary); margin: 0.5rem 0 1rem; padding: 1rem 0;
            border-radius: 0;
        }
        .tools-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0.5rem;
        }
        .tool-item {
            flex: 0 1 auto; text-align: center; text-decoration: none; color: var(--text-primary);
            transition: transform 0.2s, background-color 0.2s; padding: 0.4rem;
            border-radius: 0; min-width: 60px; max-width: 80px;
        }
        .tool-item:hover { background-color: var(--link-hover-bg); transform: translateY(-2px); }
        .tool-icon svg {
            width: 24px; height: 24px; stroke: var(--accent-color); stroke-width: 1.8;
            fill: none; margin-bottom: 0.2rem;
        }
        .tool-text { font-size: 0.7rem; font-weight: 500; color: var(--text-secondary); line-height: 1.2; white-space: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; }

        @media (max-width: 768px) {
            .user-info-card { padding: 1rem 1.5rem 0; flex-direction: row; gap: 1rem; }
            .user-avatar { width: 80px; height: 80px; font-size: 2.5rem; }
            .user-name-display { font-size: 1.5rem; }
            .stats-row { margin-top: 0.5rem; padding: 0.5rem 1.5rem; gap: 0.5rem; border-top: 1px solid var(--border-color); }
            .stat-item { font-size: 0.9rem; }
            .stat-value { font-size: 1.1rem; }
            .tools-card { margin: 0.75rem 0; padding: 0.8rem 0; }
            .tool-item { min-width: 55px; max-width: 70px; }
            .tool-icon svg { width: 22px; height: 22px; }
            .tool-text { font-size: 0.65rem; }
        }
        @media (max-width: 480px) {
            .user-avatar { width: 70px; height: 70px; font-size: 2rem; }
            .user-name-display { font-size: 1.3rem; }
            .stats-row { flex-wrap: wrap; justify-content: space-between; margin-top: 0.5rem; padding: 0.5rem 1rem; border-top: 1px solid var(--border-color); }
            .stat-divider { display: none; }
            .tool-item { min-width: 50px; max-width: 60px; }
            .tool-icon svg { width: 20px; height: 20px; }
            .tool-text { font-size: 0.6rem; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body class="profile-page">
    <?php $currentTab = 'profile'; include __DIR__ . '/header.php'; ?>
    <div id="page-content">
    <main class="main-content">
        <?php if ($currentUser): ?>
            <div class="profile-container">
                <div class="user-info-card" id="userInfoCard">
                    <?php echo getUserAvatarHtml($currentUser, 'user-avatar'); ?>
                    <div class="user-details">
                        <div class="user-info-inner">
                            <div class="user-name-row">
                                <span class="user-name-display"><?php echo escape($currentUser['username']); ?></span>
                                <?php if ($currentUser['is_founder']): ?>
                                    <span class="founder-tag">站长</span>
                                <?php elseif ($currentUser['is_admin']): ?>
                                    <span class="admin-tag">管理员</span>
                                <?php endif; ?>
                            </div>
                            <div class="user-uid">
                                <span class="uid-icon"></span>
                                <?php echo escape($displayId); ?>
                            </div>
                        </div>
                        <a href="#" data-nav-url="<?php echo url('user', ['id' => $currentUser['id']]); ?>" data-tab="profile" style="margin-left: auto; display: flex; align-items: center; color: var(--text-secondary); text-decoration: none; padding-left: 1rem;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width: 22px; height: 22px;"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </a>
                    </div>
                </div>
                
                <div class="stats-row">
                    <a href="#" data-nav-url="<?php echo url('follows', ['type' => 'following', 'id' => $currentUser['id']]); ?>" data-tab="profile" class="stat-item">
                        <span class="stat-label">关注</span>
                        <span class="stat-value"><?php echo $followStats['following']; ?></span>
                    </a>
                    <span class="stat-divider"></span>
                    <a href="#" data-nav-url="<?php echo url('follows', ['type' => 'followers', 'id' => $currentUser['id']]); ?>" data-tab="profile" class="stat-item">
                        <span class="stat-label">粉丝</span>
                        <span class="stat-value"><?php echo $followStats['followers']; ?></span>
                    </a>
                    <span class="stat-divider"></span>
                    <div class="stat-item">
                        <span class="stat-label">获赞</span>
                        <span class="stat-value"><?php echo $receivedLikes; ?></span>
                    </div>
                    <span class="stat-divider"></span>
                    <div class="stat-item">
                        <span class="stat-label">积分</span>
                        <span class="stat-value"><?php echo escape($currentUser['points']); ?></span>
                    </div>
                </div>

                <div class="level-section" style="padding: 0.75rem 0;">
                    <div style="margin-bottom: 0.4rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="font-weight: 700; color: var(--accent-color); font-size: 1.1rem;">Lv.<?php echo $levelProgress['level']; ?></span>
                            <span style="color: var(--text-secondary); font-size: 0.95rem;"><?php echo escape($levelProgress['name']); ?></span>
                        </div>
                    </div>
                    <div style="height: 8px; background: var(--bg-secondary); overflow: hidden;">
                        <div style="height: 100%; width: <?php echo $levelProgress['progress']; ?>%; background: linear-gradient(90deg, var(--accent-gradient-from), var(--accent-gradient-to));"></div>
                    </div>
                    <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.4rem;">
                        <?php echo $levelProgress['have']; ?> / <?php echo $levelProgress['needed']; ?> (<?php echo $levelProgress['progress']; ?>%)
                    </div>
                </div>

                <div class="tools-card">
                    <div class="tools-grid">

                        <a href="#" data-nav-url="<?php echo url('settings'); ?>" data-tab="profile" class="tool-item">
                            <div class="tool-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9M16.5 3.5L20 7l-9 9H7v-4l9-9z"></path></svg></div>
                            <div class="tool-text">编辑资料</div>
                        </a>
                        <a href="#" data-nav-url="<?php echo url('favorites'); ?>" data-tab="profile" class="tool-item">
                            <div class="tool-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.07 5.82 22 7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg></div>
                            <div class="tool-text">我的收藏</div>
                        </a>
                        <a href="#" data-nav-url="<?php echo url('theme'); ?>" data-tab="profile" class="tool-item">
                            <div class="tool-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg></div>
                            <div class="tool-text">主题</div>
                        </a>
                        <a href="#" onclick="showQQImport(event); return false;" class="tool-item">
                            <div class="tool-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2a5 5 0 0 1 5 5v1c0 2-1 4-5 6-4-2-5-4-5-6V7a5 5 0 0 1 5-5z"/><path d="M12 16v6"/><path d="M8 22h8"/></svg></div>
                            <div class="tool-text">QQ头像</div>
                        </a>
                        <?php if ($currentUser['is_admin']): ?>
                            <a href="/audit" class="tool-item" id="auditToolItem">
                                <div class="tool-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
                                <div class="tool-text">审核 <span class="audit-tool-badge" id="auditToolBadge" style="display:none;background:#e53e3e;color:white;font-size:0.6rem;padding:0.04rem 0.35rem;border-radius:4px;margin-left:0.2rem;font-weight:600;"></span></div>
                            </a>
                            <a href="#" data-nav-url="<?php echo url('admin'); ?>" data-tab="profile" class="tool-item">
                                <div class="tool-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2Zm10-10V7a4 4 0 0 0-8 0v4h8Z"/></svg></div>
                                <div class="tool-text">后台管理</div>
                            </a>
                            <a href="#" data-nav-url="<?php echo url('cleanup_files'); ?>" data-tab="profile" class="tool-item">
                                <div class="tool-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></div>
                                <div class="tool-text">清理文件</div>
                            </a>
                        <?php endif; ?>

                        <a href="#" onclick="logout(); return false;" class="tool-item">
                            <div class="tool-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg></div>
                            <div class="tool-text">退出登录</div>
                        </a>
                        <?php if (getSetting('github_oauth_enabled', '0') === '1' && !empty(getSetting('github_client_id', ''))): ?>
                        <?php 
                        // 直接从数据库重新查询，绕过可能的 getCurrentUser 缓存问题
                        try {
                            $db2 = getDbConnection();
                            $st2 = $db2->prepare("SELECT github_id, github_username, github_avatar, gitee_id, gitee_username, gitee_avatar FROM users WHERE id = ?");
                            $st2->execute([$_SESSION['user_id']]);
                            $bindData = $st2->fetch();
                        } catch (Exception $e) { $bindData = []; }
                        $ghId = !empty($bindData['github_id']);
                        $ghAvatar = !empty($bindData['github_avatar']);
                        $ghName = $bindData['github_username'] ?? '';
                        $geId = !empty($bindData['gitee_id']);
                        $geAvatar = !empty($bindData['gitee_avatar']);
                        $geName = $bindData['gitee_username'] ?? '';
                        ?>
                        <a href="auth.php?action=github_login" class="tool-item">
                            <div class="tool-icon" style="width: 100%; height: auto; border-radius: 0; background: none;">
                                <?php if ($ghId && $ghAvatar): ?>
                                    <img src="<?php echo htmlspecialchars($bindData['github_avatar']); ?>" alt="" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; display: inline-block; margin-bottom: 0.2rem;">
                                <?php else: ?>
                                    <img src="github.png" alt="" style="width: 24px; height: 24px; display: inline-block; margin-bottom: 0.2rem;">
                                <?php endif; ?>
                            </div>
                            <div class="tool-text"><?php echo $ghId ? (htmlspecialchars($ghName ?: 'GitHub')) : '绑定 GitHub'; ?></div>
                        </a>
                        <?php endif; ?>
                        <?php if (getSetting('gitee_oauth_enabled', '0') === '1' && !empty(getSetting('gitee_client_id', ''))): ?>
                        <a href="auth.php?action=gitee_login" class="tool-item">
                            <div class="tool-icon" style="width: 100%; height: auto; border-radius: 0; background: none;">
                                <?php if ($geId && $geAvatar): ?>
                                    <img src="<?php echo htmlspecialchars($bindData['gitee_avatar']); ?>" alt="" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; display: inline-block; margin-bottom: 0.2rem;">
                                <?php else: ?>
                                    <svg viewBox="0 0 90 90" style="width: 24px; height: 24px; margin-bottom: 0.2rem;"><circle fill="#C71D23" cx="45" cy="45" r="44.85"/><path d="M67.56 39.87H42.09c-1.22.01-2.22 1-2.22 2.22l-.01 5.54c0 1.22 1 2.21 2.22 2.21h15.5c1.23 0 2.22.99 2.22 2.22v.55l.01.56c0 3.67-2.98 6.64-6.65 6.64H32.12c-1.22 0-2.21-.99-2.21-2.21V36.55c0-3.67 2.97-6.64 6.65-6.64h31c1.22 0 2.22-.99 2.22-2.22v-5.54c0-1.22-1-2.22-2.22-2.22H36.55C27.37 19.94 19.94 27.37 19.94 36.55v31c0 1.22 1 2.21 2.22 2.21h32.67c8.26 0 14.95-6.69 14.95-14.95V42.09c0-1.22-1-2.22-2.22-2.22z" fill="#FFF"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="tool-text"><?php echo $geId ? (htmlspecialchars($geName ?: 'Gitee')) : '绑定 Gitee'; ?></div>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script>
        function logout() {
            if (confirm('确定要退出登录吗？')) {
                const formData = new FormData();
                formData.append('action', 'logout');
                fetch('/auth.php', { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) location.href = '<?php echo url('index'); ?>';
                        else alert(result.message);
                    })
                    .catch(error => alert('网络错误，请稍后重试！'));
            }
        }

        // 审核小红点计数
        (function() {
            var badge = document.getElementById('auditToolBadge');
            if (!badge) return;
            try {
                if (sessionStorage.getItem('audit_visited') === '1') {
                    badge.style.display = 'none';
                    return;
                }
            } catch(e) {}
            fetch('/audit?action=count&_t=' + Date.now())
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.count > 0) {
                        badge.textContent = data.count > 9 ? '9+' : data.count;
                        badge.style.display = 'inline-block';
                    }
                })
                .catch(function() {});
        })();

        // 审核链接点过后清除小红点
        var auditToolItem = document.getElementById('auditToolItem');
        if (auditToolItem) {
            auditToolItem.addEventListener('click', function() {
                try { sessionStorage.setItem('audit_visited', '1'); } catch(e) {}
            });
        }
    </script>

    <!-- QQ Import Modal -->
    <div id="qqImportModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
        <div style="background:var(--bg-secondary);border-radius:12px;padding:1.5rem;width:90%;max-width:360px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <h3 style="margin:0;font-size:1.1rem;">导入QQ头像</h3>
                <button onclick="closeQQImport()" style="background:none;border:none;color:var(--text-secondary);font-size:1.4rem;cursor:pointer;">&times;</button>
            </div>
            <p style="color:var(--text-secondary);font-size:0.9rem;margin-bottom:0.8rem;">输入QQ号，一键换上QQ头像</p>
            <input type="text" id="qqInput" placeholder="输入QQ号" 
                style="width:100%;padding:0.7rem;border-radius:8px;border:1px solid var(--border-color);background:var(--bg-primary);color:var(--text-primary);font-size:1rem;margin-bottom:0.5rem;outline:none;box-sizing:border-box;">
            <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.85rem;color:var(--text-secondary);margin-bottom:0.3rem;cursor:pointer;">
                <input type="checkbox" id="qqUpdateName" style="accent-color:var(--accent-color);">
                同时更新昵称为QQ初始名
            </label>
            <div style="font-size:0.75rem;color:var(--text-muted,#999);margin-bottom:0.8rem;line-height:1.4;background:var(--bg-primary,#f0f0f0);padding:0.4rem 0.5rem;border-radius:6px;">
                ⚠ 提醒：此 API 返回的是该QQ号注册时的初始名称，可能包含隐私信息。
                如介意请勿勾选上方选项，仅导入头像。
            </div>
            <div id="qqImportStatus" style="display:none;padding:0.5rem;border-radius:6px;margin-bottom:0.5rem;font-size:0.9rem;"></div>
            <button id="qqImportBtn" onclick="doQQImport()" 
                style="width:100%;padding:0.7rem;border:none;border-radius:8px;background:var(--accent-gradient-from, #6366f1);color:white;font-size:1rem;cursor:pointer;font-weight:600;">
                确认导入
            </button>
        </div>
    </div>

    <script>
    function showQQImport(e) {
        document.getElementById('qqImportModal').style.display = 'flex';
        document.getElementById('qqInput').value = '';
        document.getElementById('qqImportStatus').style.display = 'none';
    }
    function closeQQImport() {
        document.getElementById('qqImportModal').style.display = 'none';
    }
    function doQQImport() {
        const qq = document.getElementById('qqInput').value.trim();
        if (!qq || !/^\d{5,11}$/.test(qq)) {
            const st = document.getElementById('qqImportStatus');
            st.style.display = 'block';
            st.style.background = '#fff0f0';
            st.style.color = '#e53e3e';
            st.textContent = '请输入正确的QQ号（5-11位数字）';
            return;
        }
        const btn = document.getElementById('qqImportBtn');
        const st = document.getElementById('qqImportStatus');
        btn.disabled = true;
        btn.textContent = '导入中...';
        st.style.display = 'none';
        const userId = <?php echo json_encode($currentUser['id']); ?>;
        const updateName = document.getElementById('qqUpdateName').checked;
        fetch('/api/qq/import?qq=' + encodeURIComponent(qq) + '&user_id=' + userId + '&update_name=' + updateName)
            .then(r => r.json())
            .then(d => {
                st.style.display = 'block';
                if (d.success) {
                    st.style.background = '#f0fff4';
                    st.style.color = '#38a169';
                    st.textContent = d.message;
                    // Refresh page after 1.5s to show new avatar
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    st.style.background = '#fff0f0';
                    st.style.color = '#e53e3e';
                    st.textContent = d.error || d.message || '导入失败';
                    btn.disabled = false;
                    btn.textContent = '确认导入';
                }
            })
            .catch(e => {
                st.style.display = 'block';
                st.style.background = '#fff0f0';
                st.style.color = '#e53e3e';
                st.textContent = '网络错误: ' + e.message;
                btn.disabled = false;
                btn.textContent = '确认导入';
            });
    }
    // Close on backdrop click
    document.getElementById('qqImportModal').addEventListener('click', function(e) {
        if (e.target === this) closeQQImport();
    });
    // Enter key to submit
    document.getElementById('qqInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') doQQImport();
    });
    </script>

    </div><!-- /page-content -->
    <?php include 'bottom_nav.php'; ?>
    <?php include __DIR__ . '/spa.php'; ?>
</body>
</html>