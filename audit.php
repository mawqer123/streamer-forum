<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /');
    exit;
}
$isFounder = $currentUser['is_founder'] ?? false;
$isAdmin = $currentUser['is_admin'] ?? false;
if (!$isAdmin && !$isFounder) {
    header("Location: /");
    exit;
}

// JSON 接口：待审核数量
if (isset($_GET["action"]) && $_GET["action"] === "count") {
    header("Content-Type: application/json");
    try {
        $pdoCount = getDbConnection();
        $stmt = $pdoCount->query("SELECT COUNT(*) FROM audit_items WHERE status=\"pending\"");
        $count = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $count = 0;
    }
    echo json_encode(["count" => $count]);
    exit;
}

$adminId = $currentUser['id'];
$pdo = getDbConnection();

// 处理表单
$successMessage = '';
$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = '安全令牌验证失败，请刷新页面重试';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_audit_settings') {
            setSetting('audit_enabled', $_POST['audit_enabled'] ?? '0');
            setSetting('audit_auto', $_POST['audit_auto'] ?? '0');
            setSetting('audit_intercept', $_POST['audit_intercept'] ?? '0');
            foreach (['audit_api_url','audit_api_key','audit_api_model','audit_api_prompt'] as $k) {
                if (isset($_POST[$k])) setSetting($k, $_POST[$k]);
            }
            $successMessage = '审核设置已保存';
        } elseif ($action === 'approve' && isset($_POST['item_id'])) {
            $itemId = intval($_POST['item_id']);
            $stmt = $pdo->prepare("SELECT * FROM audit_items WHERE id=?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                $pdo->prepare("UPDATE audit_items SET status='approved', reviewed_at=NOW(), reviewed_by=? WHERE id=?")->execute([$adminId, $itemId]);
                if ($item['content_type'] === 'post' && $item['content_id']) {
                    $pdo->prepare("UPDATE posts SET is_approved=1 WHERE id=?")->execute([$item['content_id']]);
                } elseif ($item['content_type'] === 'comment' && $item['content_id']) {
                    $pdo->prepare("UPDATE comments SET is_approved=1 WHERE id=?")->execute([$item['content_id']]);
                    $stmtC = $pdo->prepare("SELECT post_id, parent_id FROM comments WHERE id=?");
                    $stmtC->execute([$item['content_id']]);
                    $c = $stmtC->fetch(PDO::FETCH_ASSOC);
                    if ($c) {
                        $pdo->prepare("UPDATE posts SET comment_count = comment_count + 1 WHERE id=?")->execute([$c['post_id']]);
                        if (!empty($c['parent_id'])) {
                            $pdo->prepare("UPDATE comments SET replies_count = replies_count + 1 WHERE id=?")->execute([$c['parent_id']]);
                        }
                    }
                } elseif ($item['content_type'] === 'username' && $item['content_id']) {
                    $newUsername = $item['content_data'];
                    $oldUsername = $item['old_value'];
                    $stmtU = $pdo->prepare("SELECT username FROM users WHERE id=?");
                    $stmtU->execute([$item['content_id']]);
                    $u = $stmtU->fetch(PDO::FETCH_ASSOC);
                    if ($u && $u['username'] === $oldUsername) {
                        $pdo->prepare("UPDATE users SET username=?, last_username_change=NOW() WHERE id=?")->execute([$newUsername, $item['content_id']]);
                        syncChatUsername($oldUsername, $newUsername);
                    }
                } elseif ($item['content_type'] === 'avatar_image' && $item['content_id']) {
                    $avatarUrl = $item['content_data'];
                    $stmtA = $pdo->prepare("SELECT avatar, username FROM users WHERE id=?");
                    $stmtA->execute([$item['content_id']]);
                    $userData = $stmtA->fetch(PDO::FETCH_ASSOC);
                    if ($userData) {
                        $oldAvatar = $userData['avatar'];
                        $username = $userData['username'];
                        $pdo->prepare("UPDATE users SET avatar=?, avatar_text=NULL, avatar_bg_color=NULL, avatar_pending=0 WHERE id=?")->execute([$avatarUrl, $item['content_id']]);
                        if (!empty($oldAvatar) && $oldAvatar !== $avatarUrl) {
                            $oldPath = __DIR__ . $oldAvatar;
                            if (file_exists($oldPath)) @unlink($oldPath);
                        }
                        syncChatAvatar($username, $avatarUrl);
                    }
                } elseif ($item['content_type'] === 'background_image' && $item['content_id']) {
                    $newBg = $item['content_data'];
                    $stmtB = $pdo->prepare("SELECT profile_background FROM users WHERE id=?");
                    $stmtB->execute([$item['content_id']]);
                    $oldBg = $stmtB->fetchColumn();
                    $pdo->prepare("UPDATE users SET profile_background=?, background_pending=0 WHERE id=?")->execute([$newBg, $item['content_id']]);
                    if (!empty($oldBg) && $oldBg !== $newBg) {
                        $oldBgPath = __DIR__ . $oldBg;
                        if (file_exists($oldBgPath)) @unlink($oldBgPath);
                    }
                }
                createNotification($item['user_id'], 'audit_approved', $adminId, $itemId, ['type' => $item['content_type']]);
                $successMessage = '已批准';
            }
        } elseif ($action === 'reject' && isset($_POST['item_id'])) {
            $itemId = intval($_POST['item_id']);
            $stmt = $pdo->prepare("SELECT * FROM audit_items WHERE id=?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                $pdo->prepare("UPDATE audit_items SET status='rejected', reviewed_at=NOW(), reviewed_by=? WHERE id=?")->execute([$adminId, $itemId]);
                if ($item['content_type'] === 'post' && $item['content_id']) {
                    $pdo->prepare("UPDATE posts SET is_approved=0 WHERE id=?")->execute([$item['content_id']]);
                } elseif ($item['content_type'] === 'comment' && $item['content_id']) {
                    $pdo->prepare("UPDATE comments SET is_approved=0 WHERE id=?")->execute([$item['content_id']]);
                } elseif ($item['content_type'] === 'avatar_image' && $item['content_id']) {
                    $pdo->prepare("UPDATE users SET avatar_pending=0 WHERE id=?")->execute([$item['content_id']]);
                    $rejectedPath = __DIR__ . $item['content_data'];
                    if (file_exists($rejectedPath)) @unlink($rejectedPath);
                } elseif ($item['content_type'] === 'background_image' && $item['content_id']) {
                    $pdo->prepare("UPDATE users SET background_pending=0 WHERE id=?")->execute([$item['content_id']]);
                    $rejectedPath = __DIR__ . $item['content_data'];
                    if (file_exists($rejectedPath)) @unlink($rejectedPath);
                }
                createNotification($item['user_id'], 'audit_rejected', $adminId, $itemId, ['type' => $item['content_type']]);
                $successMessage = '已驳回';
            }
        }
    }
}

// 获取数据
$auditEnabled = getSetting('audit_enabled', '0');
$auditAuto = getSetting('audit_auto', '0');
$auditIntercept = getSetting('audit_intercept', '0');
$stmt = $pdo->query("SELECT a.*, u.username, u.avatar, u.avatar_text, u.avatar_bg_color FROM audit_items a LEFT JOIN users u ON a.user_id = u.id WHERE a.status='pending' ORDER BY a.created_at DESC LIMIT 100");
$auditItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pendingCount = count($auditItems);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>审核 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <script src="/theme.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            width: 100%; height: 100%;
            background: var(--bg-secondary);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* 标题栏 */
        .audit-header {
            position: fixed; top: 0; left: 0; right: 0; height: 50px;
            display: flex; align-items: center; justify-content: center;
            background: var(--accent-gradient-from); z-index: 100;
        }
        .audit-header .back-btn {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: #fff; font-size: 1.2rem;
            background: none; border: none; cursor: pointer; padding: 8px;
        }
        .audit-header h1 {
            font-size: 1.1rem; font-weight: 600; color: #fff;
        }

        /* 导航标签 */
        .audit-tabs {
            position: fixed; top: 50px; left: 0; right: 0;
            display: flex; background: var(--bg-primary);
            border-bottom: 1px solid var(--border-color); z-index: 99;
        }
        .audit-tab {
            flex: 1; text-align: center; padding: 0.75rem 0;
            font-size: 0.9rem; font-weight: 500; color: var(--text-secondary);
            cursor: pointer; position: relative;
            border-bottom: 2px solid transparent; transition: all 0.2s;
        }
        .audit-tab.active {
            color: var(--accent-color); border-bottom-color: var(--accent-color); font-weight: 600;
        }
        .audit-tab-badge {
            display: none;
            position: absolute; top: 8px; right: 25%;
            min-width: 18px; height: 18px;
            background: #e53e3e; color: white;
            font-size: 0.65rem; font-weight: 600;
            line-height: 18px; text-align: center;
            border-radius: 9px; padding: 0 5px;
        }
        .audit-tab-badge.show { display: inline-block; }

        /* 主内容区 */
        .audit-content {
            margin-top: 102px; padding: 0.8rem;
        }
        .audit-settings-panel, .audit-list-panel {
            display: none;
        }
        .audit-settings-panel.active, .audit-list-panel.active {
            display: block;
        }

        /* 设置面板样式 */
        .setting-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 1rem 0; border-bottom: 1px solid var(--border-color);
        }
        .setting-row:last-child { border-bottom: none; }
        .setting-label { font-size: 0.95rem; color: var(--text-primary); }
        .setting-desc { font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.2rem; }
        .toggle-switch { position: relative; display: inline-block; width: 48px; height: 26px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: var(--bg-secondary); border-radius: 13px; transition: 0.3s; }
        .toggle-slider:before { position: absolute; content: ''; height: 20px; width: 20px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.3s; }
        .toggle-switch input:checked + .toggle-slider { background: var(--accent-color); }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(22px); }

        /* 审核列表样式 */
        .audit-item {
            background: var(--bg-primary); border-radius: 8px;
            padding: 0.8rem; margin-bottom: 0.6rem;
            box-shadow: var(--shadow);
        }
        .audit-item .user-row {
            display: flex; align-items: center; gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .audit-item .user-row .user-avatar {
            width: 32px; height: 32px; border-radius: 50%;
            font-size: 0.8rem;
        }
        .audit-item .user-row .username {
            font-weight: 500; font-size: 0.85rem; color: var(--text-primary);
        }
        .audit-item .content-type {
            font-size: 0.75rem; color: var(--text-secondary); margin-left: auto;
        }
        .audit-item .content-text {
            font-size: 0.85rem; color: var(--text-secondary);
            padding: 0.5rem; background: var(--bg-secondary);
            border-radius: 4px; margin-bottom: 0.5rem;
            word-break: break-all; max-height: 80px; overflow-y: auto;
        }
        .audit-item .content-text a { color: var(--accent-color); }
        .audit-item .action-row {
            display: flex; gap: 0.5rem; justify-content: flex-end;
        }
        .audit-item .action-row form { display: inline; }
        .btn-approve {
            padding: 0.35rem 0.8rem; border: 1px solid #38a169;
            background: transparent; color: #38a169; border-radius: 4px;
            font-size: 0.8rem; cursor: pointer; font-weight: 500;
        }
        .btn-approve:hover { background: #38a169; color: white; }
        .btn-reject {
            padding: 0.35rem 0.8rem; border: 1px solid #e53e3e;
            background: transparent; color: #e53e3e; border-radius: 4px;
            font-size: 0.8rem; cursor: pointer; font-weight: 500;
        }
        .btn-reject:hover { background: #e53e3e; color: white; }

        .empty-state {
            text-align: center; padding: 2rem 1rem;
            color: var(--text-secondary); font-size: 0.9rem;
        }
        .success-msg {
            background: #38a169; color: white; padding: 0.5rem 0.8rem;
            border-radius: 4px; font-size: 0.85rem; margin-bottom: 0.8rem;
        }
        .error-msg {
            background: #e53e3e; color: white; padding: 0.5rem 0.8rem;
            border-radius: 4px; font-size: 0.85rem; margin-bottom: 0.8rem;
        }

        /* 设置卡片 */
        .settings-card {
            background: var(--bg-primary); border-radius: 8px;
            padding: 0.8rem; margin-bottom: 1rem; box-shadow: var(--shadow);
        }
        .settings-card h3 {
            font-size: 0.9rem; margin: 0 0 0.5rem 0; color: var(--text-primary);
        }
        .settings-card input[type="text"],
        .settings-card input[type="password"],
        .settings-card textarea {
            width: 100%; max-width: 100%; margin-top: 0.3rem;
            padding: 0.4rem; border: 1px solid var(--border-color);
            background: var(--bg-primary); color: var(--text-primary);
            font-size: 0.85rem; border-radius: 4px; font-family: monospace;
        }
        .settings-card textarea { font-family: inherit; resize: vertical; }
        .model-grid {
            display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem;
        }
        .model-card {
            flex: 1; min-width: 140px; padding: 0.5rem;
            background: var(--bg-primary); border: 1px solid var(--border-color);
            border-radius: 6px; font-size: 0.78rem;
        }
        .model-card strong { display: block; }
        .model-card .url { color: var(--text-secondary); }
        .model-card .model-name { display: block; }
        .model-card .badge-tag { font-size: 0.7rem; color: var(--accent-color); }
        .btn-save { padding: 0.4rem 1.2rem; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="audit-header">
        <button class="back-btn" id="backBtn">&larr;</button>
        <h1>审核</h1>
    </div>
    <div class="audit-tabs">
        <div class="audit-tab active" data-tab="settings">审核设置</div>
        <div class="audit-tab" data-tab="content">
            内容审核
            <span class="audit-tab-badge <?php echo $pendingCount > 0 ? 'show' : ''; ?>" id="auditBadge"><?php echo $pendingCount > 0 ? $pendingCount : ''; ?></span>
        </div>
    </div>
    <div class="audit-content" id="auditContent">
        <?php if ($successMessage): ?>
            <div class="success-msg"><?php echo escape($successMessage); ?></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="error-msg"><?php echo escape($errorMessage); ?></div>
        <?php endif; ?>

        <div class="audit-settings-panel active" id="settingsPanel">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_audit_settings">
                <div class="settings-card">
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">开启审核</div>
                            <div class="setting-desc">开启后用户上传的内容需审核通过才可见</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="audit_enabled" value="1" onchange="this.form.submit()" <?php echo $auditEnabled === '1' ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">自动审核</div>
                            <div class="setting-desc">使用AI自动审核内容</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="audit_auto" value="1" onchange="this.form.submit()" <?php echo $auditAuto === '1' ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">违规拦截</div>
                            <div class="setting-desc">自动检测并撤回违规内容</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="audit_intercept" value="1" onchange="this.form.submit()" <?php echo $auditIntercept === '1' ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
                <div class="settings-card">
                    <h3>AI 审核配置</h3>
                    <div class="setting-row" style="flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <div class="setting-label">API 地址</div>
                            <div class="setting-desc">支持任意 OpenAI 兼容接口</div>
                            <input type="text" name="audit_api_url" value="<?php echo escape(getSetting('audit_api_url', '')); ?>" placeholder="https://api.deepseek.com/v1/chat/completions">
                        </div>
                    </div>
                    <div class="setting-row" style="flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <div class="setting-label">API Key</div>
                            <input type="password" name="audit_api_key" value="<?php echo escape(getSetting('audit_api_key', '')); ?>" placeholder="sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                        </div>
                    </div>
                    <div class="setting-row" style="flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <div class="setting-label">模型名称</div>
                            <div class="setting-desc">deepseek-chat / gpt-4o-mini / qwen-turbo</div>
                            <input type="text" name="audit_api_model" value="<?php echo escape(getSetting('audit_api_model', '')); ?>" placeholder="deepseek-chat">
                        </div>
                    </div>
                    <div class="setting-row" style="flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <div class="setting-label">审核提示词 <span style="font-size:0.7rem;color:var(--text-secondary);">（可选）</span></div>
                            <div class="setting-desc">不填则使用系统默认提示词。要求 AI 输出 JSON：{"flagged":true/false,"reason":"..."}</div>
                            <textarea name="audit_api_prompt" rows="4" placeholder="自定义审核提示词..."><?php echo escape(getSetting('audit_api_prompt', '')); ?></textarea>
                        </div>
                    </div>
                    <div class="model-grid">
                        <div class="model-card">
                            <strong>DeepSeek</strong>
                            <span class="url">api.deepseek.com</span>
                            <span class="model-name">deepseek-chat</span>
                            <span class="badge-tag">🇨🇳 免费500万tokens</span>
                        </div>
                        <div class="model-card">
                            <strong>通义千问</strong>
                            <span class="url">dashscope.aliyuncs.com</span>
                            <span class="model-name">qwen-turbo-2024-11-01</span>
                            <span class="badge-tag">🇨🇳 免费100万tokens/月</span>
                        </div>
                        <div class="model-card">
                            <strong>智谱 GLM</strong>
                            <span class="url">open.bigmodel.cn</span>
                            <span class="model-name">glm-4-flash</span>
                            <span class="badge-tag">🇨🇳 免费版可用</span>
                        </div>
                        <div class="model-card">
                            <strong>OpenAI</strong>
                            <span class="url">api.openai.com</span>
                            <span class="model-name">gpt-4o-mini</span>
                            <span class="badge-tag">需付费</span>
                        </div>
                    </div>
                    <div style="text-align:right;margin-top:0.8rem;">
                        <button type="submit" class="btn-primary btn-save">保存配置</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="audit-list-panel" id="contentPanel">
            <?php if (empty($auditItems)): ?>
                <div class="empty-state">暂无待审核内容</div>
            <?php else: ?>
                <?php foreach ($auditItems as $item): ?>
                    <?php
                        $contentDisplay = '';
                        $postUrl = '';
                        $typeLabel = '';
                        switch ($item['content_type']) {
                            case 'post':
                                $contentDisplay = escape(mb_substr($item['content_data'] ?: '', 0, 200));
                                $postUrl = url('post', ['id' => $item['content_id']]);
                                $typeLabel = '帖子';
                                break;
                            case 'comment':
                                $contentDisplay = escape(mb_substr($item['content_data'] ?: '', 0, 200));
                                $typeLabel = '评论';
                                break;
                            case 'username':
                                $contentDisplay = '申请修改用户名：' . escape($item['content_data']);
                                $typeLabel = '用户名';
                                break;
                            case 'avatar_image':
                                $contentDisplay = '申请更换头像';
                                $typeLabel = '头像';
                                break;
                            case 'background_image':
                                $contentDisplay = '申请更换背景图';
                                $typeLabel = '背景图';
                                break;
                            default:
                                $contentDisplay = escape(mb_substr($item['content_data'] ?: '', 0, 200));
                                $typeLabel = '其他';
                        }
                    ?>
                    <div class="audit-item">
                        <div class="user-row">
                            <?php echo getUserAvatarHtml($item, 'user-avatar'); ?>
                            <span class="username"><?php echo escape($item['username'] ?: '未知用户'); ?></span>
                            <span class="content-type"><?php echo $typeLabel; ?></span>
                        </div>
                        <div class="content-text">
                            <?php if ($postUrl): ?>
                                <a href="<?php echo $postUrl; ?>" target="_blank"><?php echo $contentDisplay; ?></a>
                            <?php else: ?>
                                <?php echo $contentDisplay; ?>
                            <?php endif; ?>
                        </div>
                        <div class="action-row">
                            <form method="POST" onsubmit="return confirm('确定批准此项？')">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" class="btn-approve">批准</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('确定驳回此项？')">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" class="btn-reject">驳回</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // 标签切换
    document.querySelectorAll('.audit-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.audit-tab').forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
            var tabName = this.getAttribute('data-tab');
            document.getElementById('settingsPanel').classList.toggle('active', tabName === 'settings');
            document.getElementById('contentPanel').classList.toggle('active', tabName === 'content');
        });
    });

    // 默认显示内容审核面板（如果有待审核项）
    <?php if ($pendingCount > 0): ?>
    document.querySelector('.audit-tab[data-tab="content"]').click();
    <?php endif; ?>

    // 返回
    document.getElementById('backBtn').addEventListener('click', function() {
        window.location.href = '/profile';
    });
    </script>
</body>
</html>
