<?php
require_once __DIR__ . '/functions.php';

$currentUserForTheme = getCurrentUser();
checkMaintenanceMode($currentUserForTheme);

if (!isLoggedIn()) {
    showAuthModalOnly();
    exit;
}

// ========== 数据文件 ==========
define('CHAT_DATA_DIR', __DIR__ . '/data/chat/');
define('CHAT_GROUPS_FILE', CHAT_DATA_DIR . 'groups.json');
if (!file_exists(CHAT_DATA_DIR)) mkdir(CHAT_DATA_DIR, 0755, true);

function gl_getChatGroups() {
    if (!file_exists(CHAT_GROUPS_FILE)) return [];
    $content = file_get_contents(CHAT_GROUPS_FILE);
    return $content ? (json_decode($content, true) ?: []) : [];
}
function gl_saveChatGroups($groups) {
    return file_put_contents(CHAT_GROUPS_FILE, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ========== AJAX 处理 ==========
header('Content-Type: application/json; charset=utf-8');
if (isset($_POST['action'])) {
    ob_clean();
    switch ($_POST['action']) {
        case 'create_group':
            if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error', 'message' => '未登录']); exit; }
            $groupName = trim($_POST['group_name'] ?? '');
            if (empty($groupName)) { echo json_encode(['status' => 'error', 'message' => '群聊名称不能为空']); exit; }
            if (mb_strlen($groupName) > 50) { echo json_encode(['status' => 'error', 'message' => '名称不能超过50字']); exit; }
            $username = $_SESSION['chat_username'] ?? $_SESSION['username'];
            $groups = gl_getChatGroups();
            $groupId = uniqid();
            $groups[$groupId] = [
                'name' => $groupName,
                'creator' => $username,
                'created_at' => time(),
                'members' => [$username]
            ];
            if (gl_saveChatGroups($groups)) {
                echo json_encode(['status' => 'success', 'message' => '群聊创建成功', 'group_id' => $groupId], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['status' => 'error', 'message' => '创建失败'], JSON_UNESCAPED_UNICODE);
            }
            exit;
        case 'join_group':
            if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error', 'message' => '未登录']); exit; }
            $groupId = trim($_POST['group_id'] ?? '');
            if (empty($groupId)) { echo json_encode(['status' => 'error', 'message' => '请输入群组ID']); exit; }
            $username = $_SESSION['chat_username'] ?? $_SESSION['username'];
            $groups = gl_getChatGroups();
            if (!isset($groups[$groupId])) { echo json_encode(['status' => 'error', 'message' => '群组不存在，请检查ID']); exit; }
            if (in_array($username, $groups[$groupId]['members'])) {
                echo json_encode(['status' => 'error', 'message' => '你已在该群组中']); exit;
            }
            $groups[$groupId]['members'][] = $username;
            if (gl_saveChatGroups($groups)) {
                echo json_encode(['status' => 'success', 'message' => '加入成功', 'group_id' => $groupId], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['status' => 'error', 'message' => '加入失败'], JSON_UNESCAPED_UNICODE);
            }
            exit;
        case 'leave_group':
            if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error', 'message' => '未登录']); exit; }
            $groupId = $_POST['group_id'] ?? '';
            if (empty($groupId)) { echo json_encode(['status' => 'error', 'message' => '参数错误']); exit; }
            $username = $_SESSION['chat_username'] ?? $_SESSION['username'];
            $groups = gl_getChatGroups();
            if (!isset($groups[$groupId])) { echo json_encode(['status' => 'error', 'message' => '群组不存在']); exit; }
            if ($groups[$groupId]['creator'] === $username) { echo json_encode(['status' => 'error', 'message' => '群主不能退出群聊']); exit; }
            $groups[$groupId]['members'] = array_values(array_filter($groups[$groupId]['members'], fn($m) => $m !== $username));
            if (gl_saveChatGroups($groups)) {
                echo json_encode(['status' => 'success', 'message' => '已退出群聊'], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['status' => 'error', 'message' => '操作失败'], JSON_UNESCAPED_UNICODE);
            }
            exit;
        case 'mark_all_read':
            if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'ok']); exit; }
            $chatGroups = gl_getChatGroups();
            $readStateFile = CHAT_DATA_DIR . 'read_state.json';
            $readState = file_exists($readStateFile) ? (json_decode(file_get_contents($readStateFile), true) ?: []) : [];
            $now = time();
            foreach ($chatGroups as $gid => $group) {
                if (in_array($forumUsername ?? $_SESSION['username'], $group['members'] ?? [])) {
                    $key = $_SESSION['user_id'] . '_' . $gid;
                    $msgFile = CHAT_DATA_DIR . "group_{$gid}.json";
                    if (file_exists($msgFile)) {
                        $msgs = json_decode(file_get_contents($msgFile), true) ?: [];
                        $latest = 0;
                        foreach ($msgs as $m) { if (($m['time'] ?? 0) > $latest) $latest = $m['time']; }
                        $readState[$key] = ['time' => $latest > 0 ? $latest : $now, 'mentions' => 0];
                    }
                }
            }
            file_put_contents($readStateFile, json_encode($readState, JSON_UNESCAPED_UNICODE));
            echo json_encode(['status' => 'ok']);
            exit;
        case 'dismiss_group':
            if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error', 'message' => '未登录']); exit; }
            $groupId = $_POST['group_id'] ?? '';
            if (empty($groupId)) { echo json_encode(['status' => 'error', 'message' => '参数错误']); exit; }
            $username = $_SESSION['chat_username'] ?? $_SESSION['username'];
            $groups = gl_getChatGroups();
            if (!isset($groups[$groupId])) { echo json_encode(['status' => 'error', 'message' => '群组不存在']); exit; }
            if ($groups[$groupId]['creator'] !== $username && !isAdmin()) { echo json_encode(['status' => 'error', 'message' => '只有群主可以解散']); exit; }
            if ($groupId === '1') { echo json_encode(['status' => 'error', 'message' => '官方群不能解散']); exit; }
            unset($groups[$groupId]);
            $msgFile = CHAT_DATA_DIR . "group_{$groupId}.json";
            if (file_exists($msgFile)) @unlink($msgFile);
            if (gl_saveChatGroups($groups)) {
                echo json_encode(['status' => 'success', 'message' => '群聊已解散'], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['status' => 'error', 'message' => '操作失败'], JSON_UNESCAPED_UNICODE);
            }
            exit;
        default:
            echo json_encode(['status' => 'error', 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
            exit;
    }
}
header_remove('Content-Type');

// ========== 确保用户加入官方群 ==========
$forumUsername = $_SESSION['username'];
$_SESSION['chat_username'] = $forumUsername;
$groups = gl_getChatGroups();
if (isset($groups['1']) && !in_array($forumUsername, $groups['1']['members'])) {
    $groups['1']['members'][] = $forumUsername;
    gl_saveChatGroups($groups);
}
if (!isset($groups['1'])) {
    $groups['1'] = [
        'name' => '官方聊天群',
        'creator' => 'system',
        'created_at' => time(),
        'members' => [$forumUsername]
    ];
    gl_saveChatGroups($groups);
}

$userGroups = [];
$unreadCounts = [];
$mentionFlags = [];
$totalUnread = 0;

// 读取已读状态
$readState = [];
$readStateFile = CHAT_DATA_DIR . 'read_state.json';
if (file_exists($readStateFile)) {
    $readState = json_decode(file_get_contents($readStateFile), true) ?: [];
}

foreach ($groups as $gid => $group) {
    if (in_array($forumUsername, $group['members'])) {
        $userGroups[$gid] = $group;
        // 计算未读数
        $msgFile = CHAT_DATA_DIR . "group_{$gid}.json";
        $key = $_SESSION['user_id'] . '_' . $gid;
        $lastRead = $readState[$key]['time'] ?? 0;
        $unread = 0;
        $hasMention = false;
        if (file_exists($msgFile)) {
            $msgs = json_decode(file_get_contents($msgFile), true) ?: [];
            foreach ($msgs as $m) {
                if (($m['time'] ?? 0) > $lastRead && !($m['deleted'] ?? false) && ($m['username'] ?? '') !== $forumUsername) {
                    $unread++;
                    // 检查是否有人回复我
                    if (!empty($m['reply_to'])) {
                        // 需要查原始消息是否是我的
                        foreach ($msgs as $origMsg) {
                            if (($origMsg['id'] ?? '') === $m['reply_to'] && ($origMsg['username'] ?? '') === $forumUsername) {
                                $hasMention = true;
                                break;
                            }
                        }
                    }
                }
            }
        }
        $unreadCounts[$gid] = $unread;
        $mentionFlags[$gid] = $hasMention;
        $totalUnread += $unread;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>群聊 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <?php
    if ($currentUserForTheme && isset($currentUserForTheme['theme']) && $currentUserForTheme['theme'] === 'custom' && !empty($currentUserForTheme['theme_settings'])) {
        $settings = $currentUserForTheme['theme_settings'];
        $primary = htmlspecialchars($settings['primary'] ?? '#2196F3', ENT_QUOTES, 'UTF-8');
        list($r, $g, $b) = sscanf($primary, "#%02x%02x%02x");
        $r = max(0, $r - 20); $g = max(0, $g - 20); $b = max(0, $b - 20);
        $to = sprintf("#%02x%02x%02x", $r, $g, $b);
        echo "<style data-page-style>:root{--accent-color:$primary;--accent-gradient-from:$primary;--accent-gradient-to:$to;}</style>";
    }
    ?>
    <style data-page-style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; margin: 0 !important; padding: 0 !important; overflow: hidden; }
        body { background: var(--bg-secondary); color: var(--text-primary); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .gl-container { display: flex; flex-direction: column; height: 100vh; height: 100dvh; margin: 0 auto; background: var(--bg-primary); }
        .gl-header { background: var(--accent-gradient-from); color: white; padding: 0 1rem; display: flex; align-items: center; justify-content: space-between; height: 56px; flex-shrink: 0; position: relative; }
        .gl-back-btn { background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: background 0.2s; text-decoration: none; }
        .gl-back-btn:hover { background: rgba(255,255,255,0.15); }
        .gl-header-title { flex: 1; text-align: center; font-size: 1.1rem; font-weight: 600; }
        .gl-header-right { width: 44px; display: flex; align-items: center; justify-content: center; }
        .gl-menu-btn { background: none; border: none; color: white; cursor: pointer; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: background 0.2s; }
        .gl-menu-btn:hover { background: rgba(255,255,255,0.15); }
        .gl-menu-dots { display: flex; flex-direction: column; gap: 3px; align-items: center; }
        .gl-menu-dots span { width: 4px; height: 4px; border-radius: 50%; background: white; }
        .gl-dropdown { position: absolute; top: 56px; right: 8px; background: var(--bg-primary); box-shadow: 0 4px 20px rgba(0,0,0,0.25); min-width: 150px; z-index: 200; display: none; overflow: hidden; border: 1px solid var(--border-color); }
        .gl-dropdown.active { display: block; }
        .gl-dropdown-item { padding: 0.85rem 1.2rem; color: var(--text-primary); cursor: pointer; font-size: 0.9rem; border-bottom: 1px solid var(--border-color); transition: background 0.2s; display: flex; align-items: center; gap: 0.5rem; }
        .gl-dropdown-item:last-child { border-bottom: none; }
        .gl-dropdown-item:hover { background: var(--link-hover-bg); }
        .gl-dnd-row { display: flex; align-items: center; justify-content: space-between; padding: 0.6rem 1.2rem; background: var(--bg-primary); border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
        .gl-dnd-label { font-size: 0.9rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem; }
        .gl-dnd-toggle { position: relative; width: 48px; height: 26px; cursor: pointer; flex-shrink: 0; }
        .gl-dnd-toggle input { display: none; }
        .gl-dnd-slider { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: var(--border-color); border-radius: 26px; transition: 0.3s; }
        .gl-dnd-slider::before { content: ''; position: absolute; left: 3px; bottom: 3px; width: 20px; height: 20px; background: white; border-radius: 50%; transition: 0.3s; }
        .gl-dnd-toggle input:checked + .gl-dnd-slider { background: var(--accent-color); }
        .gl-dnd-toggle input:checked + .gl-dnd-slider::before { transform: translateX(22px); }
        .gl-list { flex: 1; overflow-y: auto; padding: 0.5rem 0; }
        .gl-group-item { display: flex; align-items: center; padding: 1rem 1.2rem; cursor: pointer; transition: background 0.2s; border-bottom: 1px solid var(--border-color); }
        .gl-group-item:hover { background: var(--link-hover-bg); }
        .gl-group-info { flex: 1; min-width: 0; }
        .gl-group-name { font-size: 1rem; font-weight: 500; color: var(--text-primary); }
        .gl-group-meta { font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; }
        .gl-group-avatar { width: 44px; height: 44px; border-radius: 8px; background: var(--accent-gradient-from); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 600; margin-right: 0.8rem; flex-shrink: 0; }
        .gl-item-left { display: flex; align-items: center; flex: 1; min-width: 0; }
        .gl-empty { text-align: center; padding: 4rem 2rem; color: var(--text-secondary); }
        .gl-empty-icon { font-size: 3rem; margin-bottom: 1rem; }
        .gl-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
        .gl-modal.active { display: flex; }
        .gl-modal-box { background: var(--bg-primary); padding: 1.5rem; max-width: 360px; width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.3); border: 1px solid var(--border-color); }
        .gl-modal-box h3 { color: var(--text-primary); margin-bottom: 1rem; }
        .gl-modal-input { width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); font-size: 0.95rem; margin-bottom: 1rem; outline: none; }
        .gl-modal-input:focus { border-color: var(--accent-color); }
        .gl-modal-actions { display: flex; gap: 0.75rem; justify-content: flex-end; }
        .gl-btn { padding: 0.5rem 1.2rem; border: none; font-size: 0.9rem; cursor: pointer; transition: opacity 0.2s; }
        .gl-btn-primary { background: var(--accent-gradient-from); color: white; }
        .gl-btn-secondary { background: var(--bg-secondary); color: var(--text-primary); border: 1px solid var(--border-color); }
        .gl-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .gl-badge { display: inline-block; padding: 0.1rem 0.4rem; border-radius: 10px; font-size: 0.7rem; background: var(--accent-color); color: white; margin-left: 0.5rem; }
        .gl-unread-badge { display: inline-block; min-width: 20px; height: 20px; border-radius: 10px; background: #e74c3c; color: white; font-size: 0.7rem; line-height: 20px; text-align: center; padding: 0 6px; margin-left: 0.5rem; font-weight: 600; }
        .gl-mention-badge { display: inline-block; padding: 0.1rem 0.4rem; border-radius: 10px; font-size: 0.7rem; background: #e74c3c; color: white; margin-left: 0.3rem; }
        .gl-group-meta-badges { display: flex; align-items: center; gap: 0.3rem; margin-top: 0.25rem; flex-wrap: wrap; }
        .gl-modal-hint { font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 1rem; }
        @media (max-width: 480px) {
            .gl-header { height: 48px; padding: 0 0.75rem; }
            .gl-header-title { font-size: 1rem; }
            .gl-group-item { padding: 0.85rem 1rem; }
            .gl-group-avatar { width: 38px; height: 38px; font-size: 1rem; }
            .gl-dropdown { top: 48px; right: 4px; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <div id="page-content">
    <div class="gl-container">
        <div class="gl-header">
            <a href="#" data-nav-url="<?php echo url('notifications'); ?>" data-tab="notifications" class="gl-back-btn">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </a>
            <div class="gl-header-title">群聊</div>
            <div class="gl-header-right" style="position:relative;">
                <button class="gl-menu-btn" id="menuBtn"><div class="gl-menu-dots"><span></span><span></span><span></span></div></button>
                <div class="gl-dropdown" id="menuDropdown">
                    <div class="gl-dropdown-item" onclick="openCreateModal()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>创建群聊
                    </div>
                    <div class="gl-dropdown-item" onclick="openJoinModal()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>加入群聊
                    </div>
                </div>
            </div>
        </div>
        <div class="gl-dnd-row">
            <div class="gl-dnd-label">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>消息免打扰
            </div>
            <label class="gl-dnd-toggle"><input type="checkbox" id="dndCheckbox"><span class="gl-dnd-slider"></span></label>
        </div>
        <div class="gl-list" id="groupList">
            <?php if (empty($userGroups)): ?>
            <div class="gl-empty">
                <div class="gl-empty-icon"></div>
                <div>你还没有加入任何群聊</div>
                <div style="margin-top:0.5rem;font-size:0.85rem;color:var(--text-secondary);">点击右上角菜单加入或创建群聊</div>
            </div>
            <?php else: ?>
                <?php foreach ($userGroups as $gid => $group): 
                    $memberCount = count($group['members'] ?? []);
                    $createdAt = date('Y-m-d', $group['created_at'] ?? 0);
                    $isCreator = ($group['creator'] ?? '') === $forumUsername;
                    $unread = $unreadCounts[$gid] ?? 0;
                    $hasMention = $mentionFlags[$gid] ?? false;
                ?>
                <div class="gl-group-item" onclick="goToChat('<?php echo $gid; ?>')">
                    <div class="gl-item-left">
                        <div class="gl-group-avatar"><?php echo mb_substr($group['name'], 0, 1); ?></div>
                        <div class="gl-group-info">
                            <div class="gl-group-name">
                                <?php echo htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($isCreator): ?><span class="gl-badge">群主</span><?php endif; ?>
                                <?php if ($unread > 0): ?><span class="gl-unread-badge"><?php echo $unread > 99 ? '99+' : $unread; ?></span><?php endif; ?>
                            </div>
                            <div class="gl-group-meta-badges">
                                <span style="font-size:0.75rem;color:var(--text-secondary);"><?php echo $memberCount; ?> 成员 · <?php echo $createdAt; ?></span>
                                <?php if ($hasMention): ?><span class="gl-mention-badge">有人@我</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <!-- 创建群聊弹窗 -->
    <div class="gl-modal" id="createModal">
        <div class="gl-modal-box"><h3>创建新群聊</h3>
            <input type="text" class="gl-modal-input" id="groupNameInput" placeholder="群聊名称">
            <div class="gl-modal-actions">
                <button class="gl-btn gl-btn-secondary" onclick="closeCreateModal()">取消</button>
                <button class="gl-btn gl-btn-primary" id="createBtn" onclick="createGroup()">创建</button>
            </div>
        </div>
    </div>
    <!-- 加入群聊弹窗 -->
    <div class="gl-modal" id="joinModal">
        <div class="gl-modal-box"><h3>加入群聊</h3>
            <input type="text" class="gl-modal-input" id="groupIdInput" placeholder="输入群组ID">
            <div class="gl-modal-hint">让群主分享群组ID给你即可加入</div>
            <div class="gl-modal-actions">
                <button class="gl-btn gl-btn-secondary" onclick="closeJoinModal()">取消</button>
                <button class="gl-btn gl-btn-primary" id="joinBtn" onclick="joinGroup()">加入</button>
            </div>
        </div>
    </div>
    <script>
        // 进入列表即标记所有群聊已读
        (async function() {
            try {
                var fd = new FormData(); fd.append('action', 'mark_all_read');
                await fetch('', { method: 'POST', body: fd });
            } catch(e) {}
        })();

        // ===== 菜单 =====
        var menuBtn = document.getElementById('menuBtn'), menuDropdown = document.getElementById('menuDropdown');
        menuBtn.addEventListener('click', e => { e.stopPropagation(); menuDropdown.classList.toggle('active'); });
        document.addEventListener('click', () => menuDropdown.classList.remove('active'));

        // ===== 免打扰 =====
        var dndCheckbox = document.getElementById('dndCheckbox');
        dndCheckbox.checked = localStorage.getItem('gc_dnd_global') === '1';
        dndCheckbox.addEventListener('change', () => localStorage.setItem('gc_dnd_global', dndCheckbox.checked ? '1' : '0'));

        // ===== 导航 =====
        function goToChat(gid) {
            navigateTo('<?php echo url('group_chat'); ?>?group_id=' + gid, 'notifications');
        }

        // ===== 创建群聊 =====
        function openCreateModal() { menuDropdown.classList.remove('active'); document.getElementById('createModal').classList.add('active'); }
        function closeCreateModal() { document.getElementById('createModal').classList.remove('active'); document.getElementById('groupNameInput').value = ''; }
        document.getElementById('createModal').addEventListener('click', function(e) { if (e.target === this) closeCreateModal(); });
        async function createGroup() {
            var input = document.getElementById('groupNameInput'), btn = document.getElementById('createBtn'), name = input.value.trim();
            if (!name) { alert('请输入群聊名称'); return; }
            if (name.length < 2) { alert('群聊名称至少2个字符'); return; }
            btn.disabled = true; btn.textContent = '创建中...';
            try {
                var fd = new FormData(); fd.append('action', 'create_group'); fd.append('group_name', name);
                var res = await fetch('', { method: 'POST', body: fd }); var data = await res.json();
                if (data.status === 'success') {
                    closeCreateModal(); alert('群聊创建成功！'); location.reload();
                } else alert(data.message || '创建失败');
            } catch (e) { alert('网络错误: ' + e.message); }
            finally { btn.disabled = false; btn.textContent = '创建'; }
        }

        // ===== 加入群聊 =====
        function openJoinModal() { menuDropdown.classList.remove('active'); document.getElementById('joinModal').classList.add('active'); }
        function closeJoinModal() { document.getElementById('joinModal').classList.remove('active'); document.getElementById('groupIdInput').value = ''; }
        document.getElementById('joinModal').addEventListener('click', function(e) { if (e.target === this) closeJoinModal(); });
        async function joinGroup() {
            var input = document.getElementById('groupIdInput'), btn = document.getElementById('joinBtn'), gid = input.value.trim();
            if (!gid) { alert('请输入群组ID'); return; }
            btn.disabled = true; btn.textContent = '加入中...';
            try {
                var fd = new FormData(); fd.append('action', 'join_group'); fd.append('group_id', gid);
                var res = await fetch('', { method: 'POST', body: fd }); var data = await res.json();
                if (data.status === 'success') {
                    closeJoinModal(); alert('加入成功！'); location.reload();
                } else alert(data.message || '加入失败');
            } catch (e) { alert('网络错误: ' + e.message); }
            finally { btn.disabled = false; btn.textContent = '加入'; }
        }
    </script>
    </div><!-- /page-content -->
    <?php include 'spa.php'; ?>
</body>
</html>
<?php
function showAuthModalOnly() {
    global $currentUserForTheme;
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>群聊 - 主播模拟器论坛</title>
        <link rel="stylesheet" href="/css/style.css?v=1782016963"><link rel="stylesheet" href="/theme.css"><script src="/theme.js"></script>
    </head>
    <body>
        <div id="page-content">
        <main class="main-content">
            <div style="text-align:center;margin-top:3rem;padding:2rem;background:var(--bg-primary);max-width:400px;margin-left:auto;margin-right:auto;box-shadow:var(--card-shadow);">
                <h2 style="color:var(--accent-color);margin-bottom:1rem;"> 请先登录</h2>
                <p style="color:var(--text-secondary);margin-bottom:1.5rem;">登录后即可进入群聊</p>
                <button class="btn-primary" onclick="showAuthModal(true)">立即登录</button>
                <p style="color:var(--text-secondary);font-size:0.9rem;margin-top:1.5rem;">还没有账号？<a href="javascript:void(0);" onclick="showAuthModal(false)" style="color:var(--accent-color);text-decoration:none;margin-left:0.5rem;">立即注册</a></p>
            </div>
        </main>
        </div><!-- /page-content -->
        <?php include 'auth_modal.php'; ?>
        <?php include 'spa.php'; ?>
    </body></html><?php } ?>
