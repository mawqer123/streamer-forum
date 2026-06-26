<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no"></head><body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;background:rgba(0,0,0,0.85);color:#fff;font-family:sans-serif;">请先 <a href="/" style="color:var(--accent-color,#2196F3)">登录</a></body></html>';
    exit;
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>裁剪背景图 - 主播模拟器论坛</title>
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
            color: #fff; font-size: 1.2rem;
            background: none; border: none; cursor: pointer; padding: 8px;
        }
        .crop-header .title {
            color: var(--accent-color, #2196F3);
            font-size: 1rem; font-weight: 600;
        }
        .crop-header .confirm-btn {
            color: var(--accent-color, #2196F3);
            font-size: 0.95rem; font-weight: 600;
            background: none; border: none; cursor: pointer; padding: 8px;
        }
        .crop-header .confirm-btn:disabled { opacity: 0.4; cursor: default; }

        .crop-area {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .crop-area img {
            display: block; max-width: none; max-height: none;
            transform-origin: center center;
            will-change: transform;
        }

        .crop-mask {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 50;
            pointer-events: none;
        }
        .crop-mask .crop-window {
            position: absolute;
            border: 2px solid rgba(255,255,255,0.6);
            box-shadow: 0 0 0 9999px rgba(0,0,0,0.55);
            pointer-events: none;
        }

        .crop-grid {
            position: absolute;
            pointer-events: none;
            z-index: 51;
            opacity: 0.3;
        }
        .crop-grid .hline, .crop-grid .vline {
            position: absolute;
            background: rgba(255,255,255,0.5);
        }

        .upload-progress {
            position: fixed;
            bottom: 80px; left: 50%;
            transform: translateX(-50%);
            width: 200px;
            z-index: 200;
            display: none;
        }
        .upload-progress .progress-bar {
            height: 4px;
            background: rgba(255,255,255,0.2);
            border-radius: 2px; overflow: hidden;
        }
        .upload-progress .progress-bar .fill {
            height: 100%; width: 0%;
            background: var(--accent-color, #2196F3);
            border-radius: 2px; transition: width 0.15s;
        }
        .upload-progress .status {
            color: rgba(255,255,255,0.7);
            font-size: 0.75rem;
            text-align: center; margin-top: 6px;
        }
    </style>
</head>
<body>

    <div class="crop-header">
        <button class="back-btn" id="backBtn">&larr;</button>
        <span class="title">裁剪背景图</span>
        <button class="confirm-btn" id="confirmBtn" disabled>完成</button>
    </div>

    <div class="crop-mask" id="cropMaskOverlay">
        <div class="crop-window" id="cropWindow"></div>
    </div>

    <div class="crop-area" id="cropArea">
        <img id="cropImg" src="" alt="裁剪图片" style="opacity:0;">
    </div>

    <div class="upload-progress" id="uploadProgress">
        <div class="progress-bar"><div class="fill" id="progressFill"></div></div>
        <div class="status" id="progressStatus">上传中</div>
    </div>

    <script>
    (function() {
        var cropImg = document.getElementById('cropImg');
        var cropArea = document.getElementById('cropArea');
        var confirmBtn = document.getElementById('confirmBtn');
        var backBtn = document.getElementById('backBtn');
        var progress = document.getElementById('uploadProgress');
        var progressFill = document.getElementById('progressFill');
        var progressStatus = document.getElementById('progressStatus');
        var cropWindow = document.getElementById('cropWindow');

        // ----- 状态 -----
        var scale = 1;
        var minScale = 1;
        var maxScale = 5;
        var tx = 0;   // translateX
        var ty = 0;   // translateY
        var imgLoaded = false;

        // 裁剪框几何
        var cw = 0, ch = 0, cl = 0, ct = 0, ccx = 0, ccy = 0;

        function updateCropGeo() {
            var w = window.innerWidth;
            var h = window.innerHeight;
            cw = w * 0.9;
            ch = cw * 9 / 16;
            cl = (w - cw) / 2;
            ct = h * 0.35 - ch / 2;
            ccx = cl + cw / 2;
            ccy = ct + ch / 2;
            cropWindow.style.cssText = 'left:' + cl + 'px;top:' + ct + 'px;width:' + cw + 'px;height:' + ch + 'px;';
        }
        updateCropGeo();
        window.addEventListener('resize', function() {
            updateCropGeo();
            applyTransform();
        });

        // ----- 在设置 src 前注册 onload -----
        cropImg.onload = function() {
            imgLoaded = true;
            var iw = cropImg.naturalWidth, ih = cropImg.naturalHeight;
            // 填满裁剪框
            var fitX = cw / iw, fitY = ch / ih;
            minScale = Math.max(fitX, fitY);
            scale = minScale;
            // 图片中心对齐裁剪框中心
            tx = ccx - window.innerWidth / 2;
            ty = ccy - window.innerHeight / 2;
            applyTransform();
            cropImg.style.opacity = '1';
            confirmBtn.disabled = false;
        };
        cropImg.onerror = function() {
            alert('图片加载失败');
            window.location.href = '/bg';
        };

        // 加载图片
        var src = '';
        try { src = sessionStorage.getItem('crop_bg_image_data'); } catch(e) {}
        if (!src) {
            alert('缺少图片');
            window.location.href = '/bg';
            return;
        }
        cropImg.src = src;

        function applyTransform() {
            cropImg.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')';
        }

        // ----- 边界约束（拖拽结束后调用，不打断操作中的手感）-----
        function clampBounds() {
            if (!imgLoaded) return;
            var iw = cropImg.naturalWidth * scale;
            var ih = cropImg.naturalHeight * scale;
            var screenW = window.innerWidth;
            var screenH = window.innerHeight;
            var imgCX = screenW / 2 + tx;
            var imgCY = screenH / 2 + ty;

            // 图片必须填满裁剪框
            var limitL = cl + cw / 2 - iw / 2;   // 图片左边缘最多到 crop 左边缘
            var limitR = cl + cw / 2 + iw / 2 - screenW;  // 图片右边缘最多到 crop 右边缘
            var limitT = ct + ch / 2 - ih / 2;
            var limitB = ct + ch / 2 + ih / 2 - screenH;

            // 自由但合理地约束
            tx = Math.min(Math.max(tx, -limitL), limitR);
            ty = Math.min(Math.max(ty, -limitT), limitB);
            applyTransform();
        }

        // ========== 触摸 ==========
        var isPinching = false;
        var isDragging = false;
        var lastDist = 0;
        var lastTouchX = 0, lastTouchY = 0;
        var lastTap = 0;
        var startTx = 0, startTy = 0;

        cropArea.addEventListener('touchstart', function(e) {
            if (e.touches.length === 2) {
                isPinching = true; isDragging = false;
                lastDist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                lastTap = 0;
                cropImg.style.transition = 'none';
            } else if (e.touches.length === 1) {
                isDragging = true;
                lastTouchX = e.touches[0].clientX; lastTouchY = e.touches[0].clientY;
                startTx = tx; startTy = ty;
                cropImg.style.transition = 'none';
            }
        }, { passive: true });

        cropArea.addEventListener('touchmove', function(e) {
            if (e.touches.length === 2 && isPinching) {
                e.preventDefault();
                var dist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                if (lastDist > 0) {
                    var newScale = Math.min(Math.max(scale * dist / lastDist, minScale), maxScale);
                    // 以裁剪框中心为缩放中心
                    var cx = ccx, cy = ccy;
                    var screenW = window.innerWidth, screenH = window.innerHeight;
                    // 当前图片坐标 (cx,cy) 在图片上的位置
                    var imgX = (cx - screenW / 2 - tx) / scale;
                    var imgY = (cy - screenH / 2 - ty) / scale;
                    scale = newScale;
                    // 保持 (cx,cy) 处的像素不动
                    tx = cx - screenW / 2 - imgX * scale;
                    ty = cy - screenH / 2 - imgY * scale;
                }
                lastDist = dist;
                applyTransform();
            } else if (e.touches.length === 1 && isDragging) {
                e.preventDefault();
                tx = startTx + (e.touches[0].clientX - lastTouchX);
                ty = startTy + (e.touches[0].clientY - lastTouchY);
                applyTransform();
            }
        }, { passive: false });

        cropArea.addEventListener('touchend', function(e) {
            if (e.changedTouches.length === 1 && !isPinching) {
                var now = Date.now();
                if (now - lastTap < 300) {
                    // 双击重置
                    cropImg.style.transition = 'transform 0.25s ease';
                    scale = minScale;
                    tx = ccx - window.innerWidth / 2;
                    ty = ccy - window.innerHeight / 2;
                    applyTransform();
                    setTimeout(function() { cropImg.style.transition = 'none'; }, 260);
                }
                lastTap = now;
            }
            if (isPinching && e.touches.length < 2) {
                isPinching = false;
                lastDist = 0;
            }
            if (!isPinching) {
                isDragging = false;
                cropImg.style.transition = "none";
                setTimeout(function() { cropImg.style.transition = 'none'; }, 160);
            }
        }, { passive: true });

        // ========== 鼠标 ==========
        var isMouseDown = false;
        var mouseStartX = 0, mouseStartY = 0;
        var mouseTx = 0, mouseTy = 0;
        cropArea.addEventListener('mousedown', function(e) {
            if (e.button === 0 && imgLoaded) {
                isMouseDown = true;
                mouseStartX = e.clientX; mouseStartY = e.clientY;
                mouseTx = tx; mouseTy = ty;
                cropImg.style.transition = 'none';
                e.preventDefault();
            }
        });
        window.addEventListener('mousemove', function(e) {
            if (isMouseDown) {
                tx = mouseTx + (e.clientX - mouseStartX);
                ty = mouseTy + (e.clientY - mouseStartY);
                applyTransform();
            }
        });
        window.addEventListener("mouseup", function() {
            if (isMouseDown) {
                isMouseDown = false;
            }
        });

        cropArea.addEventListener("wheel", function(e) {
            e.preventDefault();
            if (!imgLoaded) return;
            cropImg.style.transition = "none";
            var cx = e.clientX, cy = e.clientY;
            var newScale = Math.min(Math.max(scale * (e.deltaY > 0 ? 0.92 : 1.08), minScale), maxScale);
            var screenW = window.innerWidth, screenH = window.innerHeight;
            var imgX = (cx - screenW / 2 - tx) / scale;
            var imgY = (cy - screenH / 2 - ty) / scale;
            scale = newScale;
            tx = cx - screenW / 2 - imgX * scale;
            ty = cy - screenH / 2 - imgY * scale;
            applyTransform();
        }, { passive: false });

        // ========== 裁剪上传 ==========
        confirmBtn.addEventListener('click', function() {
            if (confirmBtn.disabled) return;

            var canvas = document.createElement('canvas');
            canvas.width = 1200;
            canvas.height = 675;
            var ctx = canvas.getContext('2d');

            var iw = cropImg.naturalWidth;
            var ih = cropImg.naturalHeight;
            var screenW = window.innerWidth;
            var screenH = window.innerHeight;

            // 图片在屏幕上的范围
            var imgScreenX = screenW / 2 + tx - iw * scale / 2;
            var imgScreenY = screenH / 2 + ty - ih * scale / 2;

            // 裁剪框在图片坐标中的区域
            var sx = (cl - imgScreenX) / scale;
            var sy = (ct - imgScreenY) / scale;
            var sw = cw / scale;
            var sh = ch / scale;

            ctx.drawImage(cropImg, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);

            confirmBtn.disabled = true;
            confirmBtn.textContent = '上传中...';
            progress.style.display = 'block';
            progressFill.style.width = '0%';
            progressStatus.textContent = '0%';

            canvas.toBlob(function(blob) {
                var fd = new FormData();
                fd.append('action', 'upload_background');
                fd.append('background', blob, 'background.jpg');
                fd.append('csrf_token', '<?php echo $csrfToken; ?>');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '/auth.php', true);
                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) {
                        progressFill.style.width = Math.round(e.loaded / e.total * 100) + '%';
                        progressStatus.textContent = Math.round(e.loaded / e.total * 100) + '%';
                    }
                };
                xhr.onload = function() {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            progressStatus.textContent = '上传成功！';
                            setTimeout(function() {
                                var returnUrl = '/settings';
                                try { var s = sessionStorage.getItem('crop_return_url'); if (s) { returnUrl = s; sessionStorage.removeItem('crop_return_url'); } } catch(ex) {}
                                window.location.href = returnUrl + '?crop_success=1';
                            }, 500);
                        } else {
                            alert(resp.message || '上传失败');
                        }
                    } catch(e) { alert('上传失败：解析响应错误'); }
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = '完成';
                    progress.style.display = 'none';
                };
                xhr.onerror = function() {
                    alert('网络错误，请重试');
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = '完成';
                    progress.style.display = 'none';
                };
                xhr.send(fd);
            }, 'image/jpeg', 0.9);
        });

        // ========== 返回 ==========
        backBtn.addEventListener('click', function() {
            window.location.href = '/bg';
        });
    })();
    </script>
</body>
</html>
