<?php
require_once __DIR__ . '/functions.php';

$currentUserForTheme = getCurrentUser();
checkMaintenanceMode($currentUserForTheme);

if (!isLoggedIn()) {
    showAuthModalOnly();
    exit;
}

$myId = (int)$_SESSION['user_id'];

function showAuthModalOnly() {
    global $currentUserForTheme;
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>私信 - 主播模拟器论坛</title>
        <link rel="stylesheet" href="/css/style.css?v=1782016963"><link rel="stylesheet" href="/theme.css"><script src="/theme.js"></script>
    </head>
    <body>
        <div id="page-content">
        <main class="main-content">
            <div style="text-align:center;margin-top:3rem;padding:2rem;background:var(--bg-primary);max-width:400px;margin-left:auto;margin-right:auto;">
                <h2 style="color:var(--accent-color);margin-bottom:1rem;"> 请先登录</h2>
                <p style="color:var(--text-secondary);margin-bottom:1.5rem;">登录后即可使用私信功能</p>
                <button class="btn-primary" onclick="showAuthModal(true)">立即登录</button>
            </div>
        </main>
        </div><!-- /page-content -->
        <?php include __DIR__ . '/auth_modal.php'; ?>
        <?php include 'spa.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

define('PM_DATA_DIR', __DIR__ . '/data/pm/');
define('PM_CONVERSATIONS_FILE', PM_DATA_DIR . 'conversations.json');
define('PM_READ_STATE_FILE', PM_DATA_DIR . 'read_state.json');
if (!file_exists(PM_DATA_DIR)) mkdir(PM_DATA_DIR, 0755, true);

function pl_getConversations() {
    if (!file_exists(PM_CONVERSATIONS_FILE)) return [];
    $c = file_get_contents(PM_CONVERSATIONS_FILE);
    return $c ? (json_decode($c, true) ?: []) : [];
}
function pl_saveConversations($convs) {
    return file_put_contents(PM_CONVERSATIONS_FILE, json_encode($convs, JSON_UNESCAPED_UNICODE));
}
function pl_getConvMessages($convId, $limit = 200) {
    $file = PM_DATA_DIR . "conv_{$convId}.json";
    if (!file_exists($file)) return [];
    $c = file_get_contents($file);
    if (empty($c)) return [];
    $msgs = json_decode($c, true);
    return is_array($msgs) ? $msgs : [];
}
function pl_getReadState() {
    if (!file_exists(PM_READ_STATE_FILE)) return [];
    $c = file_get_contents(PM_READ_STATE_FILE);
    return $c ? (json_decode($c, true) ?: []) : [];
}
function pl_saveReadState($state) {
    return file_put_contents(PM_READ_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE));
}

// AJAX: mark_all_read
if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    header('Content-Type: application/json; charset=utf-8');
    $readState = pl_getReadState();
    $convs = pl_getConversations();
    foreach ($convs as $convId => $conv) {
        if ($conv['user1_id'] == $myId || $conv['user2_id'] == $myId) {
            $key = "{$myId}_{$convId}";
            $readState[$key] = ['time' => time(), 'count' => 0];
        }
    }
    pl_saveReadState($readState);
    echo json_encode(['status' => 'success']);
    exit;
}

// AJAX: delete conversations
if (isset($_POST['action']) && $_POST['action'] === 'delete_convs') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['status' => 'error', 'message' => 'CSRF令牌验证失败']);
        exit;
    }
    $ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : [];
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['status' => 'error', 'message' => '参数错误']);
        exit;
    }
    $convs = pl_getConversations();
    $readState = pl_getReadState();
    foreach ($ids as $cid) {
        $cid = (string)$cid;
        if (isset($convs[$cid])) {
            // 只有对话参与者才能删除
            if ($convs[$cid]['user1_id'] == $myId || $convs[$cid]['user2_id'] == $myId) {
                unset($convs[$cid]);
                $msgFile = PM_DATA_DIR . "conv_{$cid}.json";
                if (file_exists($msgFile)) @unlink($msgFile);
                $key = "{$myId}_{$cid}";
                unset($readState[$key]);
            }
        }
    }
    pl_saveConversations($convs);
    pl_saveReadState($readState);
    echo json_encode(['status' => 'success']);
    exit;
}

// 构建会话列表
$convs = pl_getConversations();
$readState = pl_getReadState();
$convList = [];
foreach ($convs as $convId => $conv) {
    if (($conv['user1_id'] ?? 0) != $myId && ($conv['user2_id'] ?? 0) != $myId) continue;
    $otherId = ($conv['user1_id'] ?? 0) == $myId ? ($conv['user2_id'] ?? 0) : ($conv['user1_id'] ?? 0);
    $otherName = ($conv['user1_id'] ?? 0) == $myId ? ($conv['user2_name'] ?? '') : ($conv['user1_name'] ?? '');
    $key = "{$myId}_{$convId}";
    $lastRead = $readState[$key]['time'] ?? 0;
    $unread = 0;
    $messages = pl_getConvMessages($convId, 200);
    foreach ($messages as $msg) {
        if ($msg['sender_id'] != $myId && ($msg['time'] ?? 0) > $lastRead && empty($msg['deleted'])) $unread++;
    }
    $lastMsg = end($messages);
    $lastContent = '';
    if ($lastMsg) {
        $lastContent = $lastMsg['type'] === 'image' ? '[图片]' : ($lastMsg['type'] === 'image_text' ? '[图片] ' . mb_substr(strip_tags($lastMsg['content'] ?? ''), 0, 20) : mb_substr(strip_tags($lastMsg['content'] ?? ''), 0, 30));
        if ($lastMsg['deleted']) $lastContent = '[消息已撤回]';
    }
    $otherAvatar = '';
    $otherAvatarText = '';
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT avatar, avatar_text, avatar_bg_color FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$otherId]);
        $ou = $stmt->fetch();
        $otherAvatar = $ou ? ($ou['avatar'] ?? '') : '';
        $otherAvatarText = $ou ? ($ou['avatar_text'] ?? '') : '';
        $otherAvatarBg = $ou ? ($ou['avatar_bg_color'] ?? '') : '';
    } catch (Exception $e) {}

    $convList[] = [
        'conv_id' => $convId,
        'other_id' => $otherId,
        'other_name' => $otherName,
        'other_avatar' => $otherAvatar,
        'other_avatar_text' => $otherAvatarText,
        'other_avatar_bg' => $otherAvatarBg,
        'last_time' => $conv['last_time'] ?? 0,
        'last_content' => $lastContent,
        'unread' => $unread
    ];
}
usort($convList, function($a, $b) { return $b['last_time'] - $a['last_time']; });

$totalUnread = array_sum(array_column($convList, 'unread'));

?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>私信</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <script src="/theme.js"></script>
    <style data-page-style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary); color: var(--text-primary);
            min-height: 100dvh; padding-bottom: 70px;
            -webkit-tap-highlight-color: transparent;
        }
        /* 标题栏 */
        .pl-header {
            display: flex; align-items: center; padding: 0.75rem 1rem;
            background: var(--accent-color);
            position: sticky; top: 0; z-index: 10;
        }
        .pl-back {
            background: none; border: none; color: #fff; font-size: 1.5rem;
            cursor: pointer; padding: 0.25rem; line-height: 1; text-decoration: none;
        }
        .pl-title {
            flex: 1; text-align: center; font-weight: 600; font-size: 1.1rem;
            color: #fff;
        }
        .pl-badge {
            background: #e53e3e; color: white; font-size: 0.7rem; font-weight: bold;
            min-width: 18px; height: 18px; border-radius: 9px; display: inline-flex;
            align-items: center; justify-content: center; padding: 0 5px; margin-left: 0.3rem;
        }
        .pl-manage-btn {
            background: none; border: 1px solid rgba(255,255,255,0.5); color: #fff;
            font-size: 0.78rem; padding: 3px 10px; border-radius: 12px;
            cursor: pointer; flex-shrink: 0; white-space: nowrap;
        }
        .pl-manage-btn.active {
            background: rgba(255,255,255,0.2);
        }
        .pl-manage-btn.done {
            border-color: rgba(255,255,255,0.8);
        }

        /* 列表 */
        .pl-list { padding: 0.5rem 0; }
        .pl-item {
            display: flex; align-items: center; padding: 0.85rem 1rem;
            background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
            cursor: pointer; transition: background 0.15s; text-decoration: none; color: inherit;
            position: relative;
        }
        .pl-item:hover { background: var(--link-hover-bg); }
        .pl-item.selecting { padding-left: 3.2rem; }
        .pl-avatar {
            width: 48px; height: 48px; border-radius: 50%; object-fit: cover; flex-shrink: 0; margin-right: 0.75rem;
        }
        .pl-avatar-placeholder {
            width: 48px; height: 48px; border-radius: 50%; flex-shrink: 0; margin-right: 0.75rem;
            background: var(--accent-gradient-from); color: white; display: flex; align-items: center;
            justify-content: center; font-weight: 600; font-size: 1.1rem;
        }
        .pl-info { flex: 1; overflow: hidden; }
        .pl-name { font-weight: 600; font-size: 0.95rem; margin-bottom: 0.2rem; display: flex; align-items: center; gap: 0.4rem; }
        .pl-preview { font-size: 0.8rem; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pl-time { font-size: 0.72rem; color: var(--text-secondary); flex-shrink: 0; margin-left: 0.5rem; }
        .pl-unread {
            background: #e53e3e; color: white; font-size: 0.68rem; font-weight: bold;
            min-width: 18px; height: 18px; border-radius: 9px; display: inline-flex;
            align-items: center; justify-content: center; padding: 0 5px; flex-shrink: 0;
        }
        .pl-empty {
            text-align: center; padding: 4rem 2rem; color: var(--text-secondary);
        }

        /* 多选复选框 */
        .pl-checkbox {
            position: absolute; left: 1rem; top: 50%; transform: translateY(-50%);
            width: 22px; height: 22px; border-radius: 50%; border: 2px solid var(--border-color);
            background: var(--bg-primary); display: none; align-items: center; justify-content: center;
            flex-shrink: 0; transition: all 0.15s; z-index: 2;
        }
        .pl-checkbox.checked {
            background: var(--accent-color); border-color: var(--accent-color);
        }
        .pl-checkbox.checked::after {
            content: ''; display: block; width: 6px; height: 10px;
            border: solid #fff; border-width: 0 2px 2px 0;
            transform: rotate(45deg) translateY(-1px);
        }
        .pl-item.selecting .pl-checkbox { display: flex; }
        .pl-item.selected { background: var(--link-hover-bg); }

        /* 底部操作栏 */
        .pl-action-bar {
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 1001;
            background: var(--bg-primary); border-top: 1px solid var(--border-color);
            padding: 10px 16px; display: flex; align-items: center;
            justify-content: space-between; transform: translateY(100%);
            transition: transform 0.25s ease; pointer-events: none;
        }
        .pl-action-bar.show {
            transform: translateY(0);
            pointer-events: auto;
        }
        .pl-action-count {
            font-size: 0.9rem; color: var(--text-secondary);
        }
        .pl-action-count strong { color: var(--text-primary); }
        .pl-delete-btn {
            background: #ef4444; color: #fff; border: none;
            padding: 8px 20px; border-radius: 8px; font-size: 0.9rem; font-weight: 500;
            cursor: pointer; transition: opacity 0.2s;
        }
        .pl-delete-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .pl-delete-btn:not(:disabled):active { opacity: 0.8; }
    </style>
</head>
<body>
    <div id="page-content">
    <div class="pl-header">
        <a href="#" data-nav-url="<?php echo url('notifications'); ?>" data-tab="notifications" class="pl-back">←</a>
        <div class="pl-title">私信 <?php if ($totalUnread > 0): ?><span class="pl-badge"><?php echo $totalUnread > 99 ? '99+' : $totalUnread; ?></span><?php endif; ?></div>
        <?php if (!empty($convList)): ?>
        <button class="pl-manage-btn" id="manageBtn" onclick="toggleSelectMode()">管理</button>
        <?php else: ?>
        <div style="width:32px"></div>
        <?php endif; ?>
    </div>

    <div class="pl-list" id="plList">
        <?php if (empty($convList)): ?>
            <div class="pl-empty">
                <p>还没有私信对话</p>
                <p style="font-size:0.8rem;margin-top:0.5rem">在其他用户主页点击「私信」即可开始</p>
            </div>
        <?php else: ?>
            <?php foreach ($convList as $c): ?>
            <div class="pl-item" data-conv-id="<?php echo htmlspecialchars($c['conv_id']); ?>" onclick="handleItemClick(this, event)">
                <div class="pl-checkbox" data-conv-id="<?php echo htmlspecialchars($c['conv_id']); ?>"></div>
                <?php if ($c['other_avatar']): ?>
                    <img class="pl-avatar" src="<?php echo htmlspecialchars($c['other_avatar']); ?>" alt="">
                <?php else: ?>
                    <div class="pl-avatar-placeholder"<?php if (!empty($c['other_avatar_text'])): ?> style="background: <?php echo htmlspecialchars($c['other_avatar_bg'] ?? '#6366f1'); ?>"<?php endif; ?>><?php echo htmlspecialchars(mb_substr($c['other_avatar_text'] ?: $c['other_name'], 0, 1)); ?></div>
                <?php endif; ?>
                <div class="pl-info">
                    <div class="pl-name">
                        <?php echo htmlspecialchars($c['other_name']); ?>
                        <?php if ($c['unread'] > 0): ?><span class="pl-unread"><?php echo $c['unread'] > 99 ? '99+' : $c['unread']; ?></span><?php endif; ?>
                    </div>
                    <div class="pl-preview"><?php echo htmlspecialchars($c['last_content'] ?: '暂无消息'); ?></div>
                </div>
                <?php if ($c['last_time'] > 0): ?>
                <div class="pl-time"><?php echo date('m-d H:i', $c['last_time']); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

    <!-- 底部操作栏 -->
    <div class="pl-action-bar" id="actionBar">
        <span class="pl-action-count">已选 <strong id="selectedCount">0</strong> 项</span>
        <button class="pl-delete-btn" id="deleteBtn" disabled onclick="deleteSelected()">删除</button>
    </div>

    <script>
        var selectMode = false;
        var selected = {};

        function toggleSelectMode() {
            selectMode = !selectMode;
            var btn = document.getElementById('manageBtn');
            var items = document.querySelectorAll('.pl-item');

            if (selectMode) {
                btn.textContent = '完成';
                btn.classList.add('active');
                items.forEach(el => el.classList.add('selecting'));
                document.getElementById('actionBar').classList.add('show');
            } else {
                btn.textContent = '管理';
                btn.classList.remove('active');
                selected = {};
                updateUI();
                items.forEach(el => {
                    el.classList.remove('selecting', 'selected');
                });
                document.getElementById('actionBar').classList.remove('show');
            }
        }

        function handleItemClick(el, event) {
            if (selectMode) {
                var convId = el.dataset.convId;
                var cb = el.querySelector('.pl-checkbox');
                if (selected[convId]) {
                    delete selected[convId];
                    cb.classList.remove('checked');
                    el.classList.remove('selected');
                } else {
                    selected[convId] = true;
                    cb.classList.add('checked');
                    el.classList.add('selected');
                }
                updateUI();
                event.preventDefault();
            } else {
                // 正常跳转
                var convId = el.dataset.convId;
                if (convId) navigateTo('/pm_chat?conv_id=' + encodeURIComponent(convId), 'notifications');
            }
        }

        function updateUI() {
            var count = Object.keys(selected).length;
            document.getElementById('selectedCount').textContent = count;
            var deleteBtn = document.getElementById('deleteBtn');
            deleteBtn.disabled = count === 0;
        }

        function deleteSelected() {
            var ids = Object.keys(selected);
            if (ids.length === 0) return;
            if (!confirm('确定删除选中的 ' + ids.length + ' 个对话？删除后不可恢复。')) return;

            var deleteBtn = document.getElementById('deleteBtn');
            deleteBtn.disabled = true;
            deleteBtn.textContent = '删除中...';

            var formData = new FormData();
            formData.append('action', 'delete_convs');
            formData.append('ids', JSON.stringify(ids));
            formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            fetch('', { method: 'POST', body: new URLSearchParams(formData) })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') {
                        // 移除被删除的 DOM 元素
                        ids.forEach(id => {
                            var el = document.querySelector('.pl-item[data-conv-id="' + id + '"]');
                            if (el) el.remove();
                        });
                        selected = {};
                        updateUI();
                        // 如果列表空了，显示空状态
                        var items = document.querySelectorAll('.pl-item');
                        if (items.length === 0) {
                            document.getElementById('plList').innerHTML =
                                '<div class="pl-empty"><p>还没有私信对话</p><p style="font-size:0.8rem;margin-top:0.5rem">在其他用户主页点击「私信」即可开始</p></div>';
                            toggleSelectMode();
                            document.getElementById('manageBtn').style.display = 'none';
                        }
                    } else {
                        alert(res.message || '删除失败');
                    }
                })
                .catch(() => alert('网络错误'))
                .finally(() => {
                    deleteBtn.textContent = '删除';
                    deleteBtn.disabled = false;
                });
        }

        // 进入页面标记全部已读
        fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=mark_all_read' }).catch(() => {});
    </script>
    </div><!-- /page-content -->
    <?php include 'spa.php'; ?>
</body>
</html>
