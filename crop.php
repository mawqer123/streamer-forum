<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no"></head><body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;background:rgba(0,0,0,0.85);color:#fff;font-family:sans-serif;">请先 <a href="/" style="color:var(--accent-color,#2196F3)">登录</a></body></html>';
    exit;
}

$imageUrl = isset($_GET['url']) ? $_GET['url'] : '';
$imageBase64 = isset($_GET['b64']) ? $_GET['b64'] : '';

// CSRF token for crop upload
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>裁剪头像 - 主播模拟器论坛</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.85);
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
        }

        /* 顶部栏 */
        .crop-header {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 100;
            background: linear-gradient(to bottom, rgba(0,0,0,0.7), transparent);
        }
        .crop-header .back-btn {
            color: #fff;
            font-size: 1.2rem;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            line-height: 1;
        }
        .crop-header .title {
            color: var(--accent-color, #2196F3);
            font-size: 1rem;
            font-weight: 600;
        }
        .crop-header .confirm-btn {
            color: var(--accent-color, #2196F3);
            font-size: 0.95rem;
            font-weight: 600;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            line-height: 1;
        }
        .crop-header .confirm-btn:disabled {
            opacity: 0.4;
            cursor: default;
        }

        /* 图片显示区 */
        .crop-area {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .crop-area img {
            display: block;
            max-width: none;
            max-height: none;
            transform-origin: center center;
            will-change: transform;
        }

        /* 裁剪遮罩 — 径向渐变圆形镂空 */
        .crop-mask {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 50;
            pointer-events: none;
            background: radial-gradient(
                circle at 50% 45%,
                transparent 0px,
                transparent 0px,
                rgba(0,0,0,0.55) 0px,
                rgba(0,0,0,0.55) 100%
            );
        }

        /* 进度条 */
        .upload-progress {
            position: fixed;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%);
            width: 200px;
            z-index: 200;
            display: none;
        }
        .upload-progress .progress-bar {
            height: 4px;
            background: rgba(255,255,255,0.2);
            border-radius: 2px;
            overflow: hidden;
        }
        .upload-progress .progress-bar .fill {
            height: 100%;
            width: 0%;
            background: var(--accent-color, #2196F3);
            border-radius: 2px;
            transition: width 0.15s;
        }
        .upload-progress .status {
            color: rgba(255,255,255,0.7);
            font-size: 0.75rem;
            text-align: center;
            margin-top: 6px;
        }

        /* 提示 */
        .crop-hint {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255,255,255,0.5);
            font-size: 0.8rem;
            z-index: 60;
            text-align: center;
            transition: opacity 0.4s;
        }
    </style>
</head>
<body>

    <!-- 顶部栏 -->
    <div class="crop-header">
        <button class="back-btn" id="backBtn">&larr;</button>
        <span class="title">裁剪头像</span>
        <button class="confirm-btn" id="confirmBtn" disabled>完成</button>
    </div>

    <!-- 裁剪遮罩（圆形裁剪区，用两个半透明半圆拼接实现） -->
    <div class="crop-mask" id="cropMaskOverlay"></div>

    <!-- 图片 -->
    <div class="crop-area" id="cropArea">
        <img id="cropImg" src="" alt="裁剪图片" style="opacity:0;">
    </div>

    <!-- 进度条 -->
    <div class="upload-progress" id="uploadProgress">
        <div class="progress-bar">
            <div class="fill" id="progressFill"></div>
        </div>
        <div class="status" id="progressStatus">上传中</div>
    </div>

    <!-- 底部提示 -->
    <div class="crop-hint" id="cropHint">拖动移动 · 双指缩放</div>

    <script>
    (function() {
        var cropImg = document.getElementById('cropImg');
        var cropArea = document.getElementById('cropArea');
        var confirmBtn = document.getElementById('confirmBtn');
        var backBtn = document.getElementById('backBtn');
        var progress = document.getElementById('uploadProgress');
        var progressFill = document.getElementById('progressFill');
        var progressStatus = document.getElementById('progressStatus');
        var cropHint = document.getElementById('cropHint');

        // 加载图片：优先从 sessionStorage 读取，其次 URL 参数
        var src = <?php echo json_encode($imageB64 ?: $imageUrl); ?>;
        if (!src) {
            try { src = sessionStorage.getItem('crop_image_data'); } catch(e) {}
        }
        if (!src) {
            alert('缺少图片');
            window.location.href = '/settings';
            return;
        }
        // 必须在设置 src 之前注册 onload（data URI 可能同步触发 onload）
        cropImg.onload = function() {
            imgLoaded = true;

            // 计算最小缩放：图片至少填满裁剪框
            var imgW = cropImg.naturalWidth;
            var imgH = cropImg.naturalHeight;
            var covered = Math.max(cropRadius * 2 / imgW, cropRadius * 2 / imgH);
            minScale = Math.max(covered, 1);
            scale = minScale;

            // 居中
            offsetX = 0;
            offsetY = 0;
            applyTransform();
            cropImg.style.opacity = '1';
            confirmBtn.disabled = false;

            // 隐藏提示
            setTimeout(function() {
                cropHint.style.opacity = '0';
            }, 3000);
        };
        cropImg.onerror = function() {
            alert('图片加载失败');
            var returnUrl = '/settings';
            try { var s = sessionStorage.getItem('crop_return_url'); if (s) returnUrl = s; } catch(ex) {}
            window.location.href = returnUrl;
        };
        cropImg.src = src;

        // 状态
        var scale = 1;
        var offsetX = 0;
        var offsetY = 0;
        var minScale = 1;
        var maxScale = 5;
        var isPinching = false;
        var isDragging = false;
        var lastDist = 0;
        var lastTouchX = 0, lastTouchY = 0;
        var startOx = 0, startOy = 0;
        var lastTap = 0;
        var imgLoaded = false;

        // 裁剪框物理尺寸
        var cropMaskOverlay = document.getElementById('cropMaskOverlay');
        var cropRadius = 0;   // 像素半径
        var cropCx = 0;       // 圆心 X
        var cropCy = 0;       // 圆心 Y

        function updateCropMask() {
            var r = Math.round(cropRadius);
            var cx = Math.round(cropCx);
            var cy = Math.round(cropCy);
            cropMaskOverlay.style.background = 'radial-gradient(circle at ' + cx + 'px ' + cy + 'px, transparent 0px, transparent ' + r + 'px, rgba(0,0,0,0.55) ' + (r + 1) + 'px, rgba(0,0,0,0.55) 100%)';
        }

        function updateCropGeo() {
            var w = window.innerWidth;
            var h = window.innerHeight;
            var size = Math.min(w, h) * 0.64;
            cropRadius = size / 2;
            cropCx = w / 2;
            cropCy = h * 0.45;
            updateCropMask();
        }
        updateCropGeo();
        window.addEventListener('resize', function() {
            updateCropGeo();
            applyTransform();
        });

        // 当图片加载完成
        function applyTransform() {
            cropImg.style.transform = 'translate(' + offsetX + 'px,' + offsetY + 'px) scale(' + scale + ')';
        }

        function clampPosition() {
            if (!imgLoaded) return;
            var w = cropImg.naturalWidth * scale;
            var h = cropImg.naturalHeight * scale;
            var extraX = Math.max(w - cropRadius * 2, 0) / 2;
            var extraY = Math.max(h - cropRadius * 2, 0) / 2;
            var maxX = Math.max(30, extraX * 0.8);
            var maxY = Math.max(30, extraY * 0.8);
            offsetX = Math.min(Math.max(offsetX, -maxX), maxX);
            offsetY = Math.min(Math.max(offsetY, -maxY), maxY);
        }

        // ========== 触摸（同 bg 裁剪自由风格）==========
        cropArea.addEventListener('touchstart', function(e) {
            if (e.touches.length === 2) {
                isPinching = true; isDragging = false;
                lastDist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                lastTap = 0;
                cropImg.style.transition = 'none';
            } else if (e.touches.length === 1) {
                isDragging = true;
                lastTouchX = e.touches[0].clientX; lastTouchY = e.touches[0].clientY;
                startOx = offsetX; startOy = offsetY;
                cropImg.style.transition = 'none';
            }
        }, { passive: true });

        cropArea.addEventListener('touchmove', function(e) {
            if (e.touches.length === 2 && isPinching) {
                e.preventDefault();
                var dist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                if (lastDist > 0) {
                    var newScale = Math.min(Math.max(scale * dist / lastDist, minScale), maxScale);
                    // 以裁剪圈中心缩放
                    var imgX = (cropCx - window.innerWidth / 2 - offsetX) / scale;
                    var imgY = (cropCy - window.innerHeight / 2 - offsetY) / scale;
                    scale = newScale;
                    offsetX = cropCx - window.innerWidth / 2 - imgX * scale;
                    offsetY = cropCy - window.innerHeight / 2 - imgY * scale;
                }
                lastDist = dist;
                applyTransform();
            } else if (e.touches.length === 1 && isDragging) {
                e.preventDefault();
                offsetX = startOx + (e.touches[0].clientX - lastTouchX);
                offsetY = startOy + (e.touches[0].clientY - lastTouchY);
                applyTransform();
            }
        }, { passive: false });

        cropArea.addEventListener('touchend', function(e) {
            if (e.changedTouches.length === 1 && !isPinching) {
                var now = Date.now();
                if (now - lastTap < 300) {
                    // 双击重置
                    cropImg.style.transition = 'transform 0.25s ease';
                    scale = minScale; offsetX = 0; offsetY = 0;
                    applyTransform();
                    setTimeout(function() { cropImg.style.transition = 'none'; }, 260);
                }
                lastTap = now;
            }
            if (isPinching && e.touches.length < 2) { isPinching = false; lastDist = 0; }
            if (!isPinching) {
                isDragging = false;
                cropImg.style.transition = 'none';
            }
        }, { passive: true });

        // ========== 鼠标 ==========
        var isMouseDown = false;
        var mouseStartX = 0, mouseStartY = 0;
        var mouseOx = 0, mouseOy = 0;
        cropArea.addEventListener('mousedown', function(e) {
            if (e.button === 0 && imgLoaded) {
                isMouseDown = true;
                mouseStartX = e.clientX; mouseStartY = e.clientY;
                mouseOx = offsetX; mouseOy = offsetY;
                cropImg.style.transition = 'none';
                e.preventDefault();
            }
        });
        window.addEventListener('mousemove', function(e) {
            if (isMouseDown) {
                offsetX = mouseOx + (e.clientX - mouseStartX);
                offsetY = mouseOy + (e.clientY - mouseStartY);
                applyTransform();
            }
        });
        window.addEventListener('mouseup', function() {
            if (isMouseDown) { isMouseDown = false; cropImg.style.transition = 'none'; }
        });
        cropArea.addEventListener('wheel', function(e) {
            e.preventDefault();
            if (!imgLoaded) return;
            cropImg.style.transition = 'none';
            var cx = e.clientX, cy = e.clientY;
            var newScale = Math.min(Math.max(scale * (e.deltaY > 0 ? 0.92 : 1.08), minScale), maxScale);
            var imgX = (cx - window.innerWidth / 2 - offsetX) / scale;
            var imgY = (cy - window.innerHeight / 2 - offsetY) / scale;
            scale = newScale;
            offsetX = cx - window.innerWidth / 2 - imgX * scale;
            offsetY = cy - window.innerHeight / 2 - imgY * scale;
            applyTransform();
        }, { passive: false });

        // ========== 裁剪并上传 ==========
        confirmBtn.addEventListener('click', function() {
            if (confirmBtn.disabled) return;

            // 创建 canvas 进行裁剪
            var canvas = document.createElement('canvas');
            var size = cropRadius * 2;
            canvas.width = 200;
            canvas.height = 200;
            var ctx = canvas.getContext('2d');

            // 计算映射坐标
            var imgW = cropImg.naturalWidth;
            var imgH = cropImg.naturalHeight;
            // 图片中心 = 视口中心（flexbox 居中），不随裁剪圈偏移
            var imgCenterX = window.innerWidth / 2;
            var imgCenterY = window.innerHeight / 2;
            // 裁剪圈中心 = cropCx/cropCy（在 0.45 高度处）
            var cropCircleX = cropCx;
            var cropCircleY = cropCy;

            // 图片左上角在屏幕坐标中的位置
            var imgScreenX = imgCenterX + offsetX - imgW * scale / 2;
            var imgScreenY = imgCenterY + offsetY - imgH * scale / 2;

            // 裁剪框左上角在屏幕中的位置
            var cropLeft = cropCircleX - cropRadius;
            var cropTop = cropCircleY - cropRadius;

            // 裁剪框左上角在图片坐标系中的位置
            var sx = (cropLeft - imgScreenX) / scale;
            var sy = (cropTop - imgScreenY) / scale;
            var sw = (cropRadius * 2) / scale;
            var sh = (cropRadius * 2) / scale;

            ctx.beginPath();
            ctx.arc(100, 100, 100, 0, Math.PI * 2);
            ctx.clip();
            ctx.drawImage(cropImg, sx, sy, sw, sh, 0, 0, 200, 200);

            // 上传
            confirmBtn.disabled = true;
            confirmBtn.textContent = '上传中...';
            progress.style.display = 'block';
            progressFill.style.width = '0%';
            progressStatus.textContent = '0%';

            canvas.toBlob(function(blob) {
                var formData = new FormData();
                formData.append('action', 'upload_avatar_cropped');
                formData.append('avatar', blob, 'avatar.jpg');
                formData.append('csrf_token', '<?php echo $csrfToken; ?>');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '/auth.php', true);

                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) {
                        var pct = Math.round(e.loaded / e.total * 100);
                        progressFill.style.width = pct + '%';
                        progressStatus.textContent = pct + '%';
                    }
                };

                xhr.onload = function() {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            progressStatus.textContent = '上传成功！';
                            setTimeout(function() {
                                // 从 sessionStorage 读取返回地址，默认回设置页
                                var returnUrl = '/settings';
                                try {
                                    var stored = sessionStorage.getItem('crop_return_url');
                                    if (stored) { returnUrl = stored; sessionStorage.removeItem('crop_return_url'); }
                                } catch(ex) {}
                                window.location.href = returnUrl + '?crop_success=1';
                            }, 500);
                        } else {
                            alert(resp.message || '上传失败');
                            confirmBtn.disabled = false;
                            confirmBtn.textContent = '完成';
                            progress.style.display = 'none';
                        }
                    } catch(e) {
                        alert('上传失败：解析响应错误');
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = '完成';
                        progress.style.display = 'none';
                    }
                };

                xhr.onerror = function() {
                    alert('网络错误，请重试');
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = '完成';
                    progress.style.display = 'none';
                };

                xhr.send(formData);
            }, 'image/jpeg', 0.9);
        });

        // ========== 返回 ==========
        backBtn.addEventListener('click', function() {
            var returnUrl = '/avatar';
            try { var s = sessionStorage.getItem('crop_return_url'); if (s) returnUrl = s; } catch(ex) {}
            window.location.href = returnUrl;
        });
    })();
    </script>
</body>
</html>
