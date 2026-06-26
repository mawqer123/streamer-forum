<?php
require_once __DIR__ . '/functions.php';

$user = getCurrentUser();
if (!$user) {
    header('Location: ' . url('index'));
    exit;
}

$avatarUrl = !empty($user['avatar']) ? htmlspecialchars($user['avatar']) : null;
$avatarText = !empty($user['avatar_text']) ? htmlspecialchars($user['avatar_text']) : '';
$avatarBg = !empty($user['avatar_bg_color']) ? htmlspecialchars($user['avatar_bg_color']) : '#6366f1';
$avatarPending = $user['avatar_pending'] ?? null;

// 检查是否有待审核头像
$hasPending = false;
if ($avatarPending === null) {
    $st = $pdo->prepare("SELECT avatar_pending FROM users WHERE id=?");
    $st->execute([$user['id']]);
    $dbPending = (int)$st->fetchColumn();
    $hasPending = ($dbPending === 1);
} else {
    $hasPending = ($avatarPending == 1);
}

// 获取默认文字头像显示内容
function getDefaultAvatarText($user, $avatarText) {
    if (!empty($avatarText)) return escape(mb_substr($avatarText, 0, 2, 'UTF-8'));
    return escape(mb_substr($user['username'], 0, 1, 'UTF-8'));
}
$displayText = getDefaultAvatarText($user, $avatarText);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>头像预览 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/theme.css">
    <script src="/theme.js"></script>
    <style data-page-style>
        html, body {
            height: 100%;
            overflow: hidden;
            background: rgba(0,0,0,0.85);
        }
        .avatar-page-wrapper {
            display: flex;
            flex-direction: column;
            height: 100vh;
            height: 100dvh;
            background: rgba(0,0,0,0.85);
        }
        .avatar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            height: 48px;
            border-bottom: none;
            flex-shrink: 0;
            background: rgba(0,0,0,0.85);
            z-index: 10;
        }
        .avatar-header .back-btn {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            color: var(--accent-color);
            font-size: 1.5rem;
            cursor: pointer;
            border-radius: 50%;
            transition: background 0.15s;
        }
        .avatar-header .back-btn:active {
            background: rgba(255,255,255,0.1);
        }
        .avatar-header .title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--accent-color);
        }
        .avatar-header .placeholder {
            width: 36px;
        }

        /* 头像展示区 — 可缩放 */
        .avatar-display-area {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
            touch-action: none;
            background: rgba(0,0,0,0.85);
        }
        .avatar-display-area .avatar-view {
            display: flex;
            align-items: center;
            justify-content: center;
            transition: none;
            will-change: transform;
        }
        .avatar-display-area .avatar-view img,
        .avatar-display-area .avatar-view .text-avatar {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            user-select: none;
            -webkit-user-drag: none;
            pointer-events: none;
        }
        .avatar-display-area .avatar-view .text-avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            font-weight: bold;
            color: #fff;
        }
        .avatar-display-area .zoom-hint {
            position: absolute;
            bottom: 1.5rem;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.75rem;
            color: #666;
            opacity: 0.7;
            pointer-events: none;
            transition: opacity 0.3s;
        }

        /* 底部操作区 — 横向滚动 */
        .avatar-actions {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem 1rem env(safe-area-inset-bottom, 0.75rem);
            border-top: none;
            background: rgba(0,0,0,0.85);
            flex-shrink: 0;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .avatar-actions::-webkit-scrollbar {
            display: none;
        }
        .avatar-actions .action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            flex-shrink: 0;
            padding: 0.6rem 0.85rem;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.15s;
            white-space: nowrap;
        }
        .avatar-actions .action-btn:active {
            opacity: 0.7;
        }
        .avatar-actions .btn-upload {
            background: var(--accent-gradient-from);
            color: #fff;
        }
        .avatar-actions .btn-text-avatar {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .avatar-actions .btn-reset {
            background: rgba(255,255,255,0.05);
            color: #999;
            font-size: 0.85rem;
        }
        .hidden-input {
            position: absolute;
            width: 0;
            height: 0;
            opacity: 0;
            pointer-events: none;
        }

        /* 文字头像弹窗 */
        .text-modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 20000;
            align-items: center;
            justify-content: center;
        }
        .text-modal.show {
            display: flex;
        }
        .text-modal .modal-box {
            background: #1a1a1a;
            border-radius: 12px;
            padding: 1.5rem;
            width: 300px;
            max-width: 85vw;
        }
        .text-modal .modal-box h3 {
            margin: 0 0 1rem;
            font-size: 1.1rem;
            color: var(--accent-color);
            text-align: center;
        }
        .text-modal .modal-box .form-group {
            margin-bottom: 0.75rem;
        }
        .text-modal .modal-box label {
            display: block;
            font-size: 0.85rem;
            color: #999;
            margin-bottom: 0.3rem;
        }
        .text-modal .modal-box input[type="text"] {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #333;
            border-radius: 6px;
            background: rgba(0,0,0,0.85);
            color: #fff;
            font-size: 0.95rem;
            box-sizing: border-box;
        }
        .text-modal .modal-box .color-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .text-modal .modal-box .color-row input[type="color"] {
            width: 36px;
            height: 36px;
            border: none;
            cursor: pointer;
            padding: 0;
            background: none;
            flex-shrink: 0;
        }
        .text-modal .modal-box .color-row .mini-preview {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.85rem;
            color: #fff;
            flex-shrink: 0;
        }
        .text-modal .modal-box .modal-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        .text-modal .modal-box .modal-actions button {
            flex: 1;
            padding: 0.5rem;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .text-modal .modal-box .modal-actions .btn-cancel {
            background: #333;
            color: #fff;
            border: 1px solid #444;
        }
        .text-modal .modal-box .modal-actions .btn-save {
            background: var(--accent-gradient-from);
            color: #fff;
        }
        .text-modal .modal-box .modal-actions .btn-danger {
            background: #e53e3e;
            color: #fff;
        }
        .text-modal .modal-box .feedback {
            font-size: 0.8rem;
            color: #e53e3e;
            margin-top: 0.3rem;
            min-height: 1.2em;
        }
    </style>
</head>
<body>
<div id="page-content">
<div class="avatar-page-wrapper">
    <!-- 标题栏 -->
    <div class="avatar-header">
        <button class="back-btn" data-nav-url="settings" onclick="if(!window.__ocSpaLoaded){window.location.href='/settings'}" aria-label="返回">‹</button>
        <span class="title">头像预览</span>
        <div class="placeholder"></div>
    </div>

    <!-- 头像展示区 -->
    <div class="avatar-display-area" id="avatarDisplayArea">
        <div class="avatar-view" id="avatarView">
            <?php if ($hasPending): ?>
                <img src="/zbgameshz.png" alt="头像审核中" class="avatar-img">
            <?php elseif ($avatarUrl): ?>
                <img src="<?php echo $avatarUrl; ?>" alt="头像" class="avatar-img" id="avatarImg">
            <?php else: ?>
                <div class="text-avatar" id="textAvatarView" style="background: <?php echo escape($avatarBg ?: 'var(--accent-gradient-from)'); ?>;">
                    <?php echo $displayText; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="zoom-hint" id="zoomHint">双指缩放</div>
    </div>

    <!-- 底部操作区 -->
    <div class="avatar-actions">
        <button class="action-btn btn-upload" id="uploadAvatarBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            自行上传头像
        </button>
        <button class="action-btn btn-text-avatar" id="textAvatarBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
            更改文字头像
        </button>
        <button class="action-btn btn-reset" id="resetAvatarBtn">恢复默认文字头像</button>
    </div>
</div>

<!-- 文字头像弹窗 -->
<div class="text-modal" id="textModal">
    <div class="modal-box">
        <h3>设置文字头像</h3>
        <div class="form-group">
            <label>文字内容</label>
            <input type="text" id="textInput" maxlength="2" placeholder="输入1-2个字符" value="<?php echo escape($avatarText); ?>">
        </div>
        <div class="form-group">
            <label>背景颜色</label>
            <div class="color-row">
                <input type="color" id="bgColorInput" value="#6366f1">
                <div class="mini-preview" id="miniPreview" style="background: <?php echo escape($avatarBg ?: '#6366f1'); ?>;"><?php echo $displayText; ?></div>
                <span style="font-size:0.75rem;color:var(--text-secondary);">点击色块预览</span>
            </div>
        </div>
        <div class="feedback" id="textFeedback"></div>
        <div class="modal-actions">
            <button class="btn-cancel" id="cancelTextBtn">取消</button>
            <button class="btn-save" id="saveTextBtn">保存</button>
        </div>
    </div>
</div>

<input type="file" id="avatarFileInput" accept="image/jpeg,image/png,image/gif" class="hidden-input">
</div>

<script src="/cropper.min.js"></script>
<script>
(function() {
    var displayArea = document.getElementById('avatarDisplayArea');
    var avatarView = document.getElementById('avatarView');
    var zoomHint = document.getElementById('zoomHint');

    // ========== 双指缩放 + 单指拖动 ==========
    var scale = 1;
    var offsetX = 0;
    var offsetY = 0;
    var lastDist = 0;
    var lastTouchX = 0;
    var lastTouchY = 0;
    var isPinching = false;
    var isDragging = false;
    var lastTap = 0;

    function applyTransform() {
        avatarView.style.transform = 'translate(' + offsetX + 'px,' + offsetY + 'px) scale(' + scale + ')';
    }

    displayArea.addEventListener('touchstart', function(e) {
        var touches = e.touches;
        if (touches.length === 2) {
            isPinching = true;
            isDragging = false;
            lastDist = Math.hypot(
                touches[0].clientX - touches[1].clientX,
                touches[0].clientY - touches[1].clientY
            );
            lastTap = 0;
            zoomHint.style.opacity = '0';
        } else if (touches.length === 1 && scale > 1) {
            isDragging = true;
            lastTouchX = touches[0].clientX;
            lastTouchY = touches[0].clientY;
        }
    }, { passive: true });

    displayArea.addEventListener('touchmove', function(e) {
        if (e.touches.length === 2 && isPinching) {
            e.preventDefault();
            var dist = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
            if (lastDist > 0) {
                var delta = dist / lastDist;
                scale = Math.min(Math.max(scale * delta, 0.5), 5);
            }
            lastDist = dist;
            applyTransform();
        } else if (e.touches.length === 1 && isDragging) {
            e.preventDefault();
            var dx = e.touches[0].clientX - lastTouchX;
            var dy = e.touches[0].clientY - lastTouchY;
            offsetX += dx;
            offsetY += dy;
            lastTouchX = e.touches[0].clientX;
            lastTouchY = e.touches[0].clientY;
            applyTransform();
        }
    }, { passive: false });

    displayArea.addEventListener('touchend', function(e) {
        if (e.changedTouches.length === 1 && !isPinching) {
            var now = Date.now();
            if (now - lastTap < 300) {
                scale = 1;
                offsetX = 0;
                offsetY = 0;
                applyTransform();
                zoomHint.style.opacity = '0.7';
                setTimeout(function() { zoomHint.style.opacity = '0'; }, 2000);
            }
            lastTap = now;
        }
        if (isPinching && e.touches.length < 2) {
            isPinching = false;
            lastDist = 0;
        }
        isDragging = false;
    }, { passive: true });

    // ========== 上传头像 ==========
    var fileInput = document.getElementById('avatarFileInput');
    document.getElementById('uploadAvatarBtn').addEventListener('click', function() {
        fileInput.click();
    });

    fileInput.addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;
        if (!file.type.match('image.*')) {
            alert('请选择图片文件');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert('图片大小不能超过5MB');
            return;
        }
        // 跳转到裁剪页面（必须裁剪后才能上传）
        var reader = new FileReader();
        reader.onload = function(ev) {
            try { sessionStorage.setItem('crop_image_data', ev.target.result); } catch(ex) {}
            sessionStorage.setItem('crop_return_url', '/avatar');
            window.location.href = '/crop';
        };
        reader.readAsDataURL(file);
        fileInput.value = '';
    });

    // ========== 文字头像 ==========
    var textModal = document.getElementById('textModal');
    var textInput = document.getElementById('textInput');
    var bgColorInput = document.getElementById('bgColorInput');
    var miniPreview = document.getElementById('miniPreview');
    var textFeedback = document.getElementById('textFeedback');

    document.getElementById('textAvatarBtn').addEventListener('click', function() {
        textInput.value = '<?php echo escape($avatarText); ?>';
        <?php if ($avatarBg): ?>
        bgColorInput.value = '<?php echo $avatarBg; ?>';
        miniPreview.style.background = '<?php echo $avatarBg; ?>';
        <?php endif; ?>
        var txt = textInput.value.trim();
        miniPreview.textContent = txt || '<?php echo escape(mb_substr($user['username'], 0, 1, 'UTF-8')); ?>';
        textFeedback.textContent = '';
        textModal.classList.add('show');
    });

    bgColorInput.addEventListener('input', function() {
        miniPreview.style.background = this.value;
    });
    textInput.addEventListener('input', function() {
        var val = this.value.trim();
        miniPreview.textContent = val || '<?php echo escape(mb_substr($user['username'], 0, 1, 'UTF-8')); ?>';
    });

    document.getElementById('cancelTextBtn').addEventListener('click', function() {
        textModal.classList.remove('show');
    });
    textModal.addEventListener('click', function(e) {
        if (e.target === textModal) textModal.classList.remove('show');
    });

    document.getElementById('saveTextBtn').addEventListener('click', function() {
        var avatarText = textInput.value.trim();
        if (avatarText.length > 2) {
            textFeedback.textContent = '最多输入2个字符';
            return;
        }
        var bgColor = bgColorInput.value;
        var btn = this;
        btn.disabled = true;
        btn.textContent = '保存中...';

        var fd = new FormData();
        fd.append('action', 'update_avatar_text');
        fd.append('avatar_text', avatarText);
        fd.append('bg_color', bgColor);
        fd.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

        fetch('/auth.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success) {
                    // 更新预览
                    var img = document.getElementById('avatarImg');
                    if (img) { img.style.display = 'none'; }
                    var textEl = document.getElementById('textAvatarView');
                    if (!textEl) {
                        textEl = document.createElement('div');
                        textEl.className = 'text-avatar';
                        textEl.id = 'textAvatarView';
                        textEl.style.cssText = 'width:200px;height:200px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:4rem;font-weight:bold;color:#fff;';
                        avatarView.innerHTML = '';
                        avatarView.appendChild(textEl);
                    }
                    textEl.style.display = 'flex';
                    textEl.style.background = result.bg_color || 'var(--accent-gradient-from)';
                    textEl.textContent = result.avatar_text || '<?php echo escape(mb_substr($user['username'], 0, 1, 'UTF-8')); ?>';
                    textModal.classList.remove('show');
                    // 更新迷你预览颜色
                    miniPreview.style.background = result.bg_color || '#6366f1';
                } else {
                    textFeedback.textContent = result.message || '保存失败';
                }
            })
            .catch(function() {
                textFeedback.textContent = '网络错误，请重试';
            })
            .finally(function() {
                btn.disabled = false;
                btn.textContent = '保存';
            });
    });

    // ========== 恢复默认文字头像 ==========
    document.getElementById('resetAvatarBtn').addEventListener('click', function() {
        if (!confirm('确定恢复默认文字头像？')) return;
        var btn = this;
        btn.disabled = true;
        btn.textContent = '恢复中...';

        var fd = new FormData();
        fd.append('action', 'update_avatar_text');
        fd.append('avatar_text', '');
        fd.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

        fetch('/auth.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success) {
                    // 更新显示：显示用户名首字母 + 默认颜色
                    var img = document.getElementById('avatarImg');
                    if (img) { img.style.display = 'none'; }
                    var textEl = document.getElementById('textAvatarView');
                    if (!textEl) {
                        textEl = document.createElement('div');
                        textEl.className = 'text-avatar';
                        textEl.id = 'textAvatarView';
                        textEl.style.cssText = 'width:200px;height:200px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:4rem;font-weight:bold;color:#fff;';
                        avatarView.innerHTML = '';
                        avatarView.appendChild(textEl);
                    }
                    textEl.style.display = 'flex';
                    textEl.style.background = result.bg_color || 'var(--accent-gradient-from)';
                    textEl.textContent = result.avatar_text || '<?php echo escape(mb_substr($user['username'], 0, 1, 'UTF-8')); ?>';
                    alert('已恢复默认文字头像');
                } else {
                    alert(result.message || '操作失败');
                }
            })
            .catch(function() {
                alert('网络错误，请重试');
            })
            .finally(function() {
                btn.disabled = false;
                btn.textContent = '恢复默认文字头像';
            });
    });
})();
</script>
</body>
</html>
