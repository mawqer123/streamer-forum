<?php
require_once __DIR__ . '/functions.php';

$user = getCurrentUser();
if (!$user) {
    header('Location: ' . url('index'));
    exit;
}

$backgroundUrl = !empty($user['profile_background']) ? htmlspecialchars($user['profile_background']) : null;
$bgPending = !empty($user['background_pending']);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>背景图预览 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/theme.css">
    <script src="/theme.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            width: 100%; height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: rgba(0,0,0,0.85);
            overflow: hidden;
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
        }

        /* 顶部标题栏 */
        .bg-header {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
            background: linear-gradient(to bottom, rgba(0,0,0,0.8), transparent);
        }
        .bg-header .back-btn {
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-color, #2196F3);
            font-size: 1.6rem;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px 12px;
            line-height: 1;
            font-weight: 300;
        }
        .bg-header .title {
            color: var(--accent-color, #2196F3);
            font-size: 1rem;
            font-weight: 600;
        }

        /* 背景显示区 */
        .bg-display-area {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .bg-display-area .bg-view {
            display: flex;
            align-items: center;
            justify-content: center;
            transition: none;
            will-change: transform;
        }
        .bg-display-area .bg-view img {
            display: block;
            max-width: none;
            max-height: none;
            width: 100%;
            height: auto;
            transform-origin: center center;
            pointer-events: none;
            -webkit-user-drag: none;
        }

        /* 缩放提示 */
        .zoom-hint {
            position: fixed;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255,255,255,0.5);
            font-size: 0.8rem;
            z-index: 60;
            text-align: center;
            transition: opacity 0.4s;
            pointer-events: none;
        }

        /* 底部操作区 */
        .bg-actions {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            display: flex;
            overflow-x: auto;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
            gap: 0;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 100;
            padding-bottom: env(safe-area-inset-bottom, 8px);
        }
        .bg-actions::-webkit-scrollbar { display: none; }
        .bg-actions .action-btn {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 14px 20px;
            font-size: 0.85rem;
            color: var(--accent-color, #2196F3);
            background: none;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }
        .bg-actions .action-btn:active {
            background: rgba(255,255,255,0.1);
        }
        .bg-actions .action-btn:disabled {
            opacity: 0.4;
            cursor: default;
        }

        input[type="file"] { display: none; }
    </style>
</head>
<body>

    <!-- 顶部标题栏 -->
    <div class="bg-header">
        <button class="back-btn" data-nav-url="settings" onclick="if(!window.__ocSpaLoaded){window.location.href='/settings'}" aria-label="返回">‹</button>
        <span class="title">背景图预览</span>
    </div>

    <!-- 背景显示区 -->
    <div class="bg-display-area">
        <div class="bg-view" id="bgView">
            <?php if ($backgroundUrl): ?>
            <img id="bgImg" src="<?php echo $backgroundUrl; ?>?t=<?php echo time(); ?>" alt="背景图" style="opacity:0;">
            <?php else: ?>
            <div style="color:rgba(255,255,255,0.3);font-size:1rem;text-align:center;">
                <div style="font-size:3rem;margin-bottom:10px;"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.4)" stroke-width="1.5" style="width:48px;height:48px;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>
                尚未设置背景图
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 缩放提示 -->
    <div class="zoom-hint" id="zoomHint">双指缩放 · 拖动浏览</div>

    <!-- 底部操作区 -->
    <div class="bg-actions">
        <button class="action-btn btn-upload" id="uploadBgBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            自行上传背景图
        </button>
        <button class="action-btn btn-reset" id="resetBgBtn"<?php if (!$backgroundUrl): ?> style="display:none"<?php endif; ?>>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
            恢复默认背景
        </button>
    </div>

    <!-- 隐藏文件输入 -->
    <input type="file" id="bgFileInput" accept="image/jpeg,image/png,image/gif">

    <script>
    (function() {
        var bgView = document.getElementById('bgView');
        var bgImg = document.getElementById('bgImg');
        var zoomHint = document.getElementById('zoomHint');

        // ========== 缩放 + 拖动 ==========
        var scale = 1;
        var offsetX = 0;
        var offsetY = 0;
        var lastDist = 0;
        var lastTouchX = 0;
        var lastTouchY = 0;
        var isPinching = false;
        var isDragging = false;
        var lastTap = 0;
        var imgLoaded = !!bgImg;

        function applyTransform() {
            bgView.style.transform = 'translate(' + offsetX + 'px,' + offsetY + 'px) scale(' + scale + ')';
        }

        if (bgImg) {
            bgImg.onload = function() {
                bgImg.style.opacity = '1';
                imgLoaded = true;
                setTimeout(function() { zoomHint.style.opacity = '0'; }, 3000);
            };
            if (bgImg.complete) { bgImg.style.opacity = '1'; imgLoaded = true; }
        }

        // ========== 触摸事件 ==========
        var displayArea = document.querySelector('.bg-display-area');
        displayArea.addEventListener('touchstart', function(e) {
            var touches = e.touches;
            if (touches.length === 2) {
                isPinching = true;
                isDragging = false;
                lastDist = Math.hypot(touches[0].clientX - touches[1].clientX, touches[0].clientY - touches[1].clientY);
                lastTap = 0;
                zoomHint.style.opacity = '0';
            } else if (touches.length === 1 && imgLoaded) {
                isDragging = true;
                lastTouchX = touches[0].clientX;
                lastTouchY = touches[0].clientY;
            }
        }, { passive: true });

        displayArea.addEventListener('touchmove', function(e) {
            if (e.touches.length === 2 && isPinching) {
                e.preventDefault();
                var dist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                if (lastDist > 0) {
                    var delta = dist / lastDist;
                    scale = Math.min(Math.max(scale * delta, 0.5), 5);
                }
                lastDist = dist;
                applyTransform();
            } else if (e.touches.length === 1 && isDragging && imgLoaded) {
                e.preventDefault();
                offsetX += e.touches[0].clientX - lastTouchX;
                offsetY += e.touches[0].clientY - lastTouchY;
                lastTouchX = e.touches[0].clientX;
                lastTouchY = e.touches[0].clientY;
                applyTransform();
            }
        }, { passive: false });

        displayArea.addEventListener('touchend', function(e) {
            if (e.changedTouches.length === 1 && !isPinching) {
                var now = Date.now();
                if (now - lastTap < 300) {
                    scale = 1; offsetX = 0; offsetY = 0;
                    applyTransform();
                    zoomHint.style.opacity = '0.7';
                    setTimeout(function() { zoomHint.style.opacity = '0'; }, 2000);
                }
                lastTap = now;
            }
            if (isPinching && e.touches.length < 2) { isPinching = false; lastDist = 0; }
            isDragging = false;
        }, { passive: true });

        // ========== 鼠标支持 ==========
        var isMouseDown = false, mouseX = 0, mouseY = 0;
        displayArea.addEventListener('mousedown', function(e) {
            if (e.button === 0 && imgLoaded) {
                isMouseDown = true; mouseX = e.clientX; mouseY = e.clientY;
                zoomHint.style.opacity = '0';
            }
        });
        window.addEventListener('mousemove', function(e) {
            if (isMouseDown) {
                offsetX += e.clientX - mouseX; offsetY += e.clientY - mouseY;
                mouseX = e.clientX; mouseY = e.clientY;
                applyTransform();
            }
        });
        window.addEventListener('mouseup', function() { isMouseDown = false; });

        displayArea.addEventListener('wheel', function(e) {
            e.preventDefault();
            if (!imgLoaded) return;
            var delta = e.deltaY > 0 ? 0.9 : 1.1;
            scale = Math.min(Math.max(scale * delta, 0.5), 5);
            applyTransform();
            zoomHint.style.opacity = '0.7';
            setTimeout(function() { zoomHint.style.opacity = '0'; }, 2000);
        }, { passive: false });

        // ========== 上传背景图 ==========
        var fileInput = document.getElementById('bgFileInput');
        document.getElementById('uploadBgBtn').addEventListener('click', function() {
            fileInput.click();
        });

        fileInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            if (!file.type.match('image.*')) { alert('请选择图片文件'); return; }
            if (file.size > 5*1024*1024) { alert('图片大小不能超过5MB'); return; }
            var reader = new FileReader();
            reader.onload = function(ev) {
                try { sessionStorage.setItem('crop_bg_image_data', ev.target.result); } catch(ex) {}
                sessionStorage.setItem('crop_return_url', '/bg');
                window.location.href = '/crop-bg';
            };
            reader.readAsDataURL(file);
            fileInput.value = '';
        });

        // ========== 恢复默认 ==========
        document.getElementById('resetBgBtn')?.addEventListener('click', function() {
            if (!confirm('确定要恢复默认背景图吗？')) return;
            var btn = this;
            btn.disabled = true;
            btn.textContent = '恢复中...';
            var fd = new FormData();
            fd.append('action', 'reset_background');
            fd.append('csrf_token', '<?php echo $csrfToken; ?>');
            fetch('/auth.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.success) {
                        alert('已恢复默认背景图');
                        window.location.reload();
                    } else {
                        alert(result.message || '操作失败');
                    }
                })
                .catch(function() { alert('网络错误，请重试'); })
                .finally(function() { btn.disabled = false; btn.textContent = '恢复默认背景'; });
        });

        // ========== 返回 ==========
        document.querySelector('.back-btn')?.addEventListener('click', function() {
            if (typeof navigateTo === 'function') navigateTo('/settings');
            else window.location.href = '/settings';
        });
    })();
    </script>
</body>
</html>
