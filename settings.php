<?php
// settings.php - 用户设置页面（统一上传逻辑，支持修改密码，支持文字头像）
$active_bottom = 'profile';
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    redirect(url('index'));
}

$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);
$avatarUrl = !empty($currentUser['avatar']) ? htmlspecialchars($currentUser['avatar']) : null;
$avatarText = !empty($currentUser['avatar_text']) ? htmlspecialchars($currentUser['avatar_text']) : '';
$avatarPending = $currentUser['avatar_pending'] ?? null;
if ($avatarPending === null) {
    try {
        $pdo = getDbConnection();
        $st = $pdo->prepare("SELECT avatar_pending FROM users WHERE id=?");
        $st->execute([$currentUser['id']]);
        $avatarPending = (int)$st->fetchColumn();
    } catch (Exception $e) {}
}
$backgroundUrl = !empty($currentUser['profile_background']) ? htmlspecialchars($currentUser['profile_background']) : null;
$bgPending = !empty($currentUser['background_pending']);
$emailVerificationEnabled = isEmailVerificationEnabled(); // 检查是否开启邮箱验证
$isFounder = $currentUser['is_founder'] ?? false; // 是否为站长
$isAdmin = $currentUser['is_admin'] ?? false;
$canBypassNameLimit = ($isAdmin || $isFounder);
$lastUsernameChange = $currentUser['last_username_change'] ?? null;
$nextNameChangeDate = null;
if ($lastUsernameChange && !$canBypassNameLimit) {
    $nextTs = strtotime($lastUsernameChange) + 30 * 86400;
    if ($nextTs > time()) {
        $nextNameChangeDate = date('Y-m-d', $nextTs);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>设置 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/cropper.min.css">
    <link rel="stylesheet" href="/theme.css">
    <script src="/upload_manager.js"></script>
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
    <style>
        /* settings.php 特有样式 */
        body {
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            margin: 0;
            padding: 0;
        }
        .settings-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 56px;
            background: var(--accent-gradient-from);
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .settings-header .back-icon {
            color: white;
            text-decoration: none;
            font-size: 1.8rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
            line-height: 1;
        }
        .settings-header .back-icon:hover {
            background: rgba(255,255,255,0.2);
        }
        .settings-header h1 {
            font-size: 1.2rem;
            font-weight: 500;
            margin: 0;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            white-space: nowrap;
        }
        .settings-header .right-placeholder {
            width: 40px;
        }
        /* 重要：主内容区定位在导航栏下方，左右不留空隙 */
        .main-content {
            padding: 0 !important;
            margin: 56px 0 0 0 !important;
        }
        .settings-container {
            width: 100%;
            margin: 0;
            padding: 0;
        }
        .settings-list {
            background: var(--bg-primary);
            border-radius: 0;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            margin: 0;
        }
        .setting-item {
            display: flex;
            align-items: center;
            padding: 0.8rem 1rem;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: background 0.2s, transform 0.15s;
            position: relative;
            overflow: hidden;
            -webkit-tap-highlight-color: transparent;
        }
        .setting-item:last-child {
            border-bottom: none;
        }
        .setting-item:hover {
            background: var(--bg-secondary);
        }
        .setting-item:active {
            background: var(--border-color);
            transform: scale(0.98);
        }
        .setting-item.danger {
            color: #e53e3e;
        }
        .setting-item.danger .setting-icon {
            color: #e53e3e;
        }
        .setting-item.readonly {
            cursor: default;
        }
        .setting-item.readonly:hover {
            background: transparent;
        }
        .setting-item.readonly:active {
            transform: none;
        }
        .setting-item .setting-icon {
            transition: transform 0.2s;
        }
        .setting-item:active .setting-icon {
            transform: scale(0.9);
        }
        .setting-icon {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.8rem;
            color: var(--accent-color);
            flex-shrink: 0;
            font-size: 1.2rem;
        }
        .setting-content {
            flex: 1;
            min-width: 0;
        }
        .setting-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.15rem;
            font-size: 0.95rem;
        }
        .setting-desc {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        .setting-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-secondary);
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .avatar-thumb {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--accent-gradient-from);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            overflow: hidden;
            border: 2px solid var(--border-color);
        }
        .avatar-thumb img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        .bg-indicator {
            width: 40px;
            height: 26px;
            border-radius: 0;
            background: var(--accent-gradient-from);
            background-size: cover;
            background-position: center;
            border: 1px solid var(--border-color);
        }
        .arrow-right {
            color: var(--border-color);
            font-size: 1.2rem;
        }
        .hidden-input {
            display: none;
        }
        .cropper-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: #000000 !important;
            opacity: 1 !important;
            z-index: 10000 !important;
            align-items: center;
            justify-content: center;
        }
        .cropper-container {
            background: #ffffff !important;
            border-radius: 0;
            padding: 1.5rem;
            max-width: 90%;
            max-height: 90%;
            width: 600px;
            overflow: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            position: relative;
            z-index: 10001;
            opacity: 1 !important;
        }
        .cropper-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .cropper-header h3 {
            margin: 0;
            color: #333;
        }
        .close-cropper {
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: #666;
            line-height: 1;
            padding: 0;
        }
        .close-cropper:hover {
            color: #333;
        }
        .image-preview-container {
            max-width: 100%;
            max-height: 400px;
            overflow: hidden;
            margin-bottom: 1rem;
            background: #f5f5f5;
            text-align: center;
            border: 2px solid #ddd;
            border-radius: 0;
        }
        #crop-image, #crop-bg-image {
            max-width: 100%;
            display: block;
        }
        .cropper-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* ====== 输入框模板样式 ====== */
        .input {
            padding: 12px;
            border: none;
            border-radius: 0;
            box-shadow: 2px 2px 7px 0 rgb(0, 0, 0, 0.2);
            outline: none;
            color: var(--text-primary, #333);
            background-color: var(--bg-primary, #fff);
            width: 100%;
            font-size: 1rem;
            transition: box-shadow 0.3s, color 0.3s;
            box-sizing: border-box;
        }

        .input:focus {
            box-shadow: 2px 2px 12px 0 rgba(0,0,0,0.3);
        }

        .input:not(:placeholder-shown):invalid {
            animation: justshake 0.3s forwards;
            color: var(--error-color, red);
        }

        @keyframes justshake {
            25% { transform: translateX(5px); }
            50% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
            100% { transform: translateX(0); }
        }

        .username-modal, .password-modal, .avatartext-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: #000000 !important;
            opacity: 1 !important;
            z-index: 10000 !important;
            align-items: center;
            justify-content: center;
        }
        .username-modal .modal-container, .password-modal .modal-container, .avatartext-modal .modal-container {
            background: #ffffff !important;
            border-radius: 0;
            padding: 1.5rem;
            max-width: 90%;
            width: 400px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        }
        .password-modal .modal-container {
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        .avatartext-modal .modal-container {
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        .avatartext-modal h3 {
            margin-top: 0;
            color: var(--text-primary);
        }
        .password-modal h3 {
            margin-top: 0;
            color: var(--text-primary);
        }
        .password-modal .modal-actions, .avatartext-modal .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }
        .password-modal .form-group, .avatartext-modal .form-group {
            margin-bottom: 1rem;
        }
        .password-modal input, .avatartext-modal input {
            width: 100%;
        }
        .password-feedback, .avatartext-feedback {
            color: #e53e3e;
            font-size: 0.9rem;
            margin-top: -0.5rem;
            margin-bottom: 1rem;
        }
        .avatartext-hint {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }
        .delete-account-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        .delete-account-modal .modal-container {
            background: var(--bg-primary);
            border-radius: 0;
            padding: 1.5rem;
            max-width: 90%;
            width: 400px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            color: var(--text-primary);
        }
        .delete-account-modal h3 {
            margin-top: 0;
            color: #e53e3e;
        }
        .delete-account-modal p {
            margin-bottom: 1rem;
            line-height: 1.5;
        }
        .delete-account-modal .warning-text {
            color: #e53e3e;
            font-weight: 500;
            margin-bottom: 1rem;
        }
        .delete-account-modal .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
        .delete-account-modal .btn-danger {
            background: #e53e3e;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 0;
            font-weight: 600;
            cursor: pointer;
        }
        .delete-account-modal .btn-danger:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .delete-account-feedback {
            color: #e53e3e;
            font-size: 0.9rem;
            margin-top: -0.5rem;
            margin-bottom: 1rem;
        }
        .send-code-btn {
            background: linear-gradient(135deg, var(--accent-gradient-from), var(--accent-gradient-to));
            color: white;
            border: none;
            border-radius: 0;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            transition: opacity 0.3s;
        }
        .send-code-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        @media (max-width: 768px) {
            .settings-header h1 {
                font-size: 1rem;
            }
            .setting-item {
                padding: 0.7rem 1rem;
            }
            .setting-icon {
                width: 24px;
                height: 24px;
                font-size: 1rem;
            }
            .setting-title {
                font-size: 0.9rem;
            }
            .setting-desc {
                font-size: 0.75rem;
            }
            .avatar-thumb {
                width: 36px;
                height: 36px;
            }
            .bg-indicator {
                width: 36px;
                height: 22px;
            }
    </style>
    <header class="settings-header">
        <a href="<?php echo url('profile'); ?>" class="back-icon" aria-label="返回">←</a>
        <h1>编辑资料</h1>
        <div class="right-placeholder"></div>
    </header>

    <main class="main-content">
        <div class="settings-container">
            <div class="settings-list">
                <div class="setting-item" id="avatarItem">
                    <div class="setting-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></div>
                    <div class="setting-content">
                        <div class="setting-title">头像</div>
                        <div class="setting-desc">点击修改头像（上传图片或设置文字）</div>
                    </div>
                    <div class="setting-right">
                        <div class="avatar-thumb" id="avatarThumb"<?php if (!$avatarUrl && !empty($avatarText) && empty($avatarPending)): ?> style="background: <?php echo escape($currentUser['avatar_bg_color'] ?? 'var(--accent-gradient-from)'); ?> !important;"<?php endif; ?>>
                            <?php if (!empty($avatarPending)): ?>
                                <img src="/zbgameshz.png" alt="审核中" style="width:100%;height:100%;object-fit:cover;">
                            <?php elseif ($avatarUrl): ?>
                                <img src="<?php echo $avatarUrl; ?>" alt="avatar" id="avatarThumbImg">
                            <?php elseif (!empty($avatarText)): ?>
                                <?php echo escape(mb_substr($avatarText, 0, 2, 'UTF-8')); ?>
                            <?php else: ?>
                                <?php echo mb_substr(escape($currentUser['username']), 0, 1, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                        <span class="arrow-right">›</span>
                    </div>
                </div>
                <!-- DEBUG: avatar_bg_color = <?php echo var_export($currentUser['avatar_bg_color'] ?? 'NULL', true); ?> -->
                <div class="setting-item" id="bgItem">
                    <div class="setting-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>
                    <div class="setting-content">
                        <div class="setting-title">背景图</div>
                        <div class="setting-desc">设置个人主页背景</div>
                    </div>
                    <div class="setting-right">
                        <div class="bg-indicator" id="bgIndicator" style="<?php echo $bgPending ? 'background-image: url(/zbgameshz.png);' : ($backgroundUrl ? 'background-image: url(' . $backgroundUrl . ');' : ''); ?>"></div>
                        <span class="arrow-right">›</span>
                    </div>
                </div>
                <div class="setting-item" id="usernameItem">
                    <div class="setting-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></div>
                    <div class="setting-content">
                        <div class="setting-title">用户名</div>
                        <div class="setting-desc" id="usernameDesc">
                            当前：<?php echo escape($currentUser['username']); ?>
                            <?php if (!$canBypassNameLimit): ?>
                                <?php if ($nextNameChangeDate): ?>
                                    <br><span style="color:#e53e3e;font-size:0.75rem;">⏳ 下次可修改：<?php echo $nextNameChangeDate; ?></span>
                                <?php else: ?>
                                    <br><span style="color:var(--text-secondary);font-size:0.75rem;">每30天可修改一次</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <br><span style="color:var(--accent-color);font-size:0.75rem;"> 管理员特权：无限制修改</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="setting-right">
                        <span class="arrow-right">›</span>
                    </div>
                </div>
                <?php if ($emailVerificationEnabled): ?>
                <div class="setting-item" id="passwordItem">
                    <div class="setting-icon"></div>
                    <div class="setting-content">
                        <div class="setting-title">修改密码</div>
                        <div class="setting-desc">使用邮箱验证码修改密码</div>
                    </div>
                    <div class="setting-right">
                        <span class="arrow-right">›</span>
                    </div>
                </div>
                <?php endif; ?>
                <div class="setting-item readonly">
                    <div class="setting-icon"></div>
                    <div class="setting-content">
                        <div class="setting-title">邮箱</div>
                        <div class="setting-desc"><?php echo escape($currentUser['email']); ?></div>
                    </div>
                    <div class="setting-right">
                        <span style="color: var(--text-secondary);">不可修改</span>
                    </div>
                </div>
            </div>

            <?php if (!$isFounder): ?>
            <!-- 站长不显示注销账号入口 -->
            <div class="settings-list" style="margin-top: 1rem;">
                <div class="setting-item danger" id="deleteAccountItem">
                    <div class="setting-icon"></div>
                    <div class="setting-content">
                        <div class="setting-title">注销账号</div>
                        <div class="setting-desc">永久删除账号及所有数据</div>
                    </div>
                    <div class="setting-right">
                        <span class="arrow-right">›</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- 裁剪头像模态框 -->
    <div class="cropper-modal" id="cropperModal">
        <div class="cropper-container">
            <div class="cropper-header">
                <h3>裁剪头像</h3>
                <button class="close-cropper" id="closeCropperBtn">&times;</button>
            </div>
            <div class="image-preview-container">
                <img id="crop-image" src="" alt="待裁剪图片">
            </div>
            <div id="avatarUploadProgress" class="upload-progress" style="display: none;">
                <div class="upload-progress-bar"></div>
                <div class="upload-status"></div>
            </div>
            <div class="cropper-actions">
                <button class="btn-secondary" id="cancelCropBtn">取消</button>
                <button class="btn-primary" id="confirmCropBtn">确认上传</button>
            </div>
        </div>
    </div>

    <!-- 裁剪背景图模态框（新增） -->
    <div class="cropper-modal" id="bgCropperModal">
        <div class="cropper-container">
            <div class="cropper-header">
                <h3>裁剪背景图</h3>
                <button class="close-cropper" id="closeBgCropperBtn">&times;</button>
            </div>
            <div class="image-preview-container">
                <img id="crop-bg-image" src="" alt="待裁剪背景图">
            </div>
            <div id="bgUploadProgress" class="upload-progress" style="display: none;">
                <div class="upload-progress-bar"></div>
                <div class="upload-status"></div>
            </div>
            <div class="cropper-actions">
                <button class="btn-secondary" id="cancelBgCropBtn">取消</button>
                <button class="btn-primary" id="confirmBgCropBtn">确认上传</button>
            </div>
        </div>
    </div>

    <!-- 修改用户名模态框 -->
    <div class="username-modal" id="usernameModal">
        <div class="modal-container">
            <h3>修改用户名</h3>
            <input type="text" id="newUsernameInput" class="input" placeholder="新用户名（2-16个字符）" maxlength="16" required>
            <div id="usernameModalFeedback" class="username-feedback"></div>
            <div class="modal-actions">
                <button class="btn-secondary" id="cancelUsernameBtn">取消</button>
                <button class="btn-primary" id="saveUsernameBtn">保存</button>
            </div>
        </div>
    </div>

    <!-- 修改文字头像模态框 -->
    <div class="avatartext-modal" id="avatartextModal">
        <div class="modal-container">
            <h3>设置文字头像</h3>
            <div class="form-group">
                <input type="text" id="avatarTextInput" class="input" placeholder="输入1-2个字符" maxlength="2" value="<?php echo escape($avatarText); ?>">
                <div class="avatartext-hint">建议输入1-2个字符（如姓名首字母、昵称缩写）</div>
            </div>
            <div class="form-group">
                <label style="display:block;font-size:0.85rem;color:var(--text-secondary);margin-bottom:0.5rem;">背景颜色</label>
                <div style="display:flex;gap:10px;align-items:center;">
                    <input type="color" id="avatarBgColorInput" value="#6366f1" style="width:40px;height:40px;border:none;cursor:pointer;padding:0;background:none;flex-shrink:0;">
                    <div id="avatarPreview" style="width:40px;height:40px;border-radius:50%;background:<?php echo escape($currentUser['avatar_bg_color'] ?? '#6366f1'); ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:0.9rem;flex-shrink:0;"><?php echo escape(mb_substr($avatarText ?: 'A', 0, 2, 'UTF-8')); ?></div>
                    <span style="font-size:0.8rem;color:var(--text-secondary);">点击色块预览</span>
                </div>
            </div>
            <div id="avatarTextFeedback" class="avatartext-feedback"></div>
            <div class="modal-actions">
                <button class="btn-secondary" id="cancelAvatarTextBtn">取消</button>
                <button class="btn-secondary" id="clearAvatarTextBtn" style="background: #e53e3e; color: white;">清除文字头像</button>
                <button class="btn-primary" id="saveAvatarTextBtn">保存</button>
            </div>
        </div>
    </div>

    <!-- 修改密码模态框（仅当邮箱验证开启时显示） -->
    <div class="password-modal" id="passwordModal">
        <div class="modal-container">
            <h3>修改密码</h3>
            <div id="passwordError" class="password-feedback"></div>
            <div class="form-group">
                <label for="changeEmailCode">邮箱验证码</label>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <input type="text" id="changeEmailCode" class="input" placeholder="验证码" style="flex:1;" required>
                    <button type="button" class="send-code-btn" id="sendChangeCodeBtn">获取验证码</button>
                </div>
                <small class="help-text">验证码将发送至 <?php echo escape($currentUser['email']); ?>，有效期5分钟</small>
            </div>
            <div class="form-group">
                <label for="newPassword">新密码</label>
                <input type="password" id="newPassword" class="input" placeholder="至少6位" required>
            </div>
            <div class="form-group">
                <label for="confirmPassword">确认新密码</label>
                <input type="password" id="confirmPassword" class="input" placeholder="再次输入新密码" required>
            </div>
            <div class="modal-actions">
                <button class="btn-secondary" id="cancelPasswordBtn">取消</button>
                <button class="btn-primary" id="savePasswordBtn">保存</button>
            </div>
        </div>
    </div>

    <!-- 注销账号模态框 -->
    <div class="delete-account-modal" id="deleteAccountModal">
        <div class="modal-container">
            <h3> 注销账号</h3>
            <p>此操作将永久删除您的账号及所有相关数据，包括：</p>
            <ul style="margin-bottom: 1rem; padding-left: 1.5rem;">
                <li>发布的帖子、评论</li>
                <li>收藏、点赞记录</li>
                <li>关注关系、消息通知</li>
                <li>上传的图片和附件</li>
            </ul>
            <p class="warning-text">此操作不可撤销，请谨慎操作！</p>
            <p>请输入您的密码以确认身份：</p>
            <input type="password" id="deleteAccountPassword" class="input" placeholder="当前密码" autocomplete="current-password" required>
            <div id="deleteAccountFeedback" class="delete-account-feedback"></div>
            <div class="modal-actions">
                <button class="btn-secondary" id="cancelDeleteAccountBtn">取消</button>
                <button class="btn-danger" id="confirmDeleteAccountBtn">确认注销</button>
            </div>
        </div>
    </div>

    <input type="file" id="avatarFileInput" accept="image/jpeg,image/png,image/gif" class="hidden-input">
    <input type="file" id="bgFileInput" accept="image/jpeg,image/png,image/gif" class="hidden-input">

    <script src="/cropper.min.js"></script>
    <script>
        let cropper = null;
        let bgCropper = null;
        const avatarThumb = document.getElementById('avatarThumb');
        const avatarThumbImg = document.getElementById('avatarThumbImg');
        const bgIndicator = document.getElementById('bgIndicator');

        // ========== 头像逻辑（支持图片上传和文字头像） ==========
        const avatarItem = document.getElementById('avatarItem');
        const avatarFileInput = document.getElementById('avatarFileInput');
        let selectedFile = null;
        const cropperModal = document.getElementById('cropperModal');
        const cropImage = document.getElementById('crop-image');
        const closeCropperBtn = document.getElementById('closeCropperBtn');
        const cancelCropBtn = document.getElementById('cancelCropBtn');
        const confirmCropBtn = document.getElementById('confirmCropBtn');
        const avatarUploadProgress = document.getElementById('avatarUploadProgress');

        // 点击头像项 → 跳转到头像预览页面
        avatarItem.addEventListener('click', function() {
            if (typeof navigateTo === 'function') {
                navigateTo('/avatar');
            } else {
                window.location.href = '/avatar';
            }
        });

        // 选择图片后跳转到全屏裁剪页（crop.php 是独立页面，不做 SPA 加载）
        avatarFileInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            if (!file.type.match('image.*')) { alert('请选择图片文件'); return; }
            if (file.size > 5*1024*1024) { alert('图片大小不能超过5MB'); return; }
            var reader = new FileReader();
            reader.onload = function(ev) {
                try { sessionStorage.setItem('crop_image_data', ev.target.result); } catch(ex) {}
                window.location.href = '/crop';
            };
            reader.readAsDataURL(file);
            avatarFileInput.value = '';
        });

        // 裁剪页回来时
        (function() {
            if (location.search.indexOf('crop_success=1') >= 0) {
                history.replaceState(null, '', location.pathname);
                location.reload();
            }
        })();

        // 文字头像颜色预览
        const avatarBgInput = document.getElementById('avatarBgColorInput');
        const avatarPreview = document.getElementById('avatarPreview');
        const avatarTextInput = document.getElementById('avatarTextInput');
        if (avatarBgInput && avatarPreview) {
            avatarBgInput.addEventListener('input', function() {
                avatarPreview.style.background = this.value;
            });
        }
        if (avatarTextInput && avatarPreview) {
            avatarTextInput.addEventListener('input', function() {
                const val = this.value.trim();
                avatarPreview.textContent = val ? val : 'A';
            });
        }

        function openAvatarTextModal() {
            document.getElementById('avatarTextInput').value = '<?php echo escape($avatarText); ?>';
            document.getElementById('avatarTextFeedback').textContent = '';
            <?php
            $currentBg = $currentUser['avatar_bg_color'] ?? '#6366f1';
            ?>
            if (document.querySelector('#avatarBgColorInput')) {
                document.getElementById('avatarBgColorInput').value = '<?php echo $currentBg; ?>';
            }
            if (document.querySelector('#avatarPreview')) {
                document.getElementById('avatarPreview').style.background = '<?php echo $currentBg; ?>';
            }
            document.getElementById('avatartextModal').style.display = 'flex';
        }

        // 文字头像保存
        document.getElementById('saveAvatarTextBtn')?.addEventListener('click', function() {
            const avatarText = document.getElementById('avatarTextInput').value.trim();
            if (avatarText.length > 2) {
                document.getElementById('avatarTextFeedback').textContent = '最多输入2个字符';
                return;
            }
            const bgColor = document.getElementById('avatarBgColorInput').value;
            const btn = this;
            btn.disabled = true;
            btn.textContent = '保存中...';
            
            const formData = new FormData();
            formData.append('action', 'update_avatar_text');
            formData.append('avatar_text', avatarText);
            formData.append('bg_color', bgColor);
            formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
            
            fetch('/auth.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        // 更新头像显示
                        if (avatarThumbImg) {
                            avatarThumbImg.remove();
                        }
                        avatarThumb.innerHTML = result.avatar_text ? result.avatar_text : '?';
                        avatarThumb.style.background = result.bg_color || 'var(--accent-gradient-from)';
                        document.getElementById('avatartextModal').style.display = 'none';
                        location.reload(); // 刷新以更新全局头像显示
                    } else {
                        document.getElementById('avatarTextFeedback').textContent = result.message;
                    }
                })
                .catch(error => {
                    document.getElementById('avatarTextFeedback').textContent = '网络错误，请重试';
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.textContent = '保存';
                });
        });
        
        document.getElementById('clearAvatarTextBtn')?.addEventListener('click', function() {
            if (confirm('确定要清除文字头像吗？清除后将显示用户名首字母。')) {
                const btn = this;
                btn.disabled = true;
                const formData = new FormData();
                formData.append('action', 'update_avatar_text');
                formData.append('avatar_text', '');
                formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
                fetch('/auth.php', { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            document.getElementById('avatartextModal').style.display = 'none';
                            location.reload();
                        } else {
                            document.getElementById('avatarTextFeedback').textContent = result.message;
                        }
                    })
                    .catch(error => {
                        document.getElementById('avatarTextFeedback').textContent = '网络错误，请重试';
                    })
                    .finally(() => {
                        btn.disabled = false;
                    });
            }
        });
        
        document.getElementById('cancelAvatarTextBtn')?.addEventListener('click', function() {
            document.getElementById('avatartextModal').style.display = 'none';
        });
        document.getElementById('avatartextModal')?.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });

        // ========== 使用全屏裁剪页（crop.php），旧 CropperJS 模态框已废弃 ==========

        confirmCropBtn.addEventListener('click', function() {
            if (!cropper) { alert('请先选择图片'); return; }
            const canvas = cropper.getCroppedCanvas({ width: 200, height: 200 });
            canvas.toBlob(function(blob) {
                avatarUploadProgress.style.display = 'block';
                const bar = avatarUploadProgress.querySelector('.upload-progress-bar');
                const status = avatarUploadProgress.querySelector('.upload-status');
                bar.style.width = '0%';
                status.textContent = '上传中 0%';
                confirmCropBtn.disabled = true;
                
                uploadFile(blob, '/auth.php', function(formData) {
                    formData.append('action', 'upload_avatar_cropped');
                    formData.append('avatar', blob, 'avatar.jpg');
                }, function(percent) {
                    bar.style.width = percent + '%';
                    status.textContent = '上传中 ' + percent + '%';
                }, function(response) {
                    if (response.success) {
                        // 上传成功后删除旧头像文件
                        if (oldAvatarUrl) {
                            fetch('/cleanup_utils.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=delete_file&file=' + encodeURIComponent(oldAvatarUrl) + '&csrf_token=<?php echo generateCsrfToken(); ?>'
                            }).catch(e => console.error);
                        }
                        if (avatarThumbImg) {
                            avatarThumbImg.src = response.avatar_url + '?t=' + Date.now();
                        } else {
                            avatarThumb.innerHTML = `<img src="${response.avatar_url}?t=${Date.now()}" alt="avatar" id="avatarThumbImg">`;
                        }
                        alert('头像上传成功！');
                        closeCropper();
                        location.reload();
                    } else {
                        alert(response.message || '上传失败');
                    }
                }, function(errorMsg) {
                    alert('上传失败：' + errorMsg);
                }).finally(() => {
                    avatarUploadProgress.style.display = 'none';
                    confirmCropBtn.disabled = false;
                });
            }, 'image/jpeg', 0.9);
        });

        // ========== 背景上传（支持裁剪） ==========
        const bgItem = document.getElementById('bgItem');
        const bgFileInput = document.getElementById('bgFileInput');
        const bgCropperModal = document.getElementById('bgCropperModal');
        const cropBgImage = document.getElementById('crop-bg-image');
        const closeBgCropperBtn = document.getElementById('closeBgCropperBtn');
        const cancelBgCropBtn = document.getElementById('cancelBgCropBtn');
        const confirmBgCropBtn = document.getElementById('confirmBgCropBtn');
        const bgUploadProgress = document.getElementById('bgUploadProgress');
        let selectedBgFile = null;
        let oldBgUrl = '<?php echo $backgroundUrl; ?>';

        bgItem.addEventListener('click', function() {
            if (typeof navigateTo === 'function') navigateTo('/bg');
            else window.location.href = '/bg';
        });

        bgFileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            if (!file.type.match('image.*')) { alert('请选择图片文件'); return; }
            if (file.size > 5 * 1024 * 1024) { alert('图片大小不能超过5MB'); return; }
            const reader = new FileReader();
            reader.onload = function(event) {
                try { sessionStorage.setItem('crop_bg_image_data', event.target.result); } catch(ex) {}
                sessionStorage.setItem('crop_return_url', '/bg');
                window.location.href = '/crop-bg';
            };
            reader.readAsDataURL(file);
        });

        function closeBgCropper() {
            bgCropperModal.style.display = 'none';
            if (bgCropper) { bgCropper.destroy(); bgCropper = null; }
            cropBgImage.src = '';
            bgFileInput.value = '';
            selectedBgFile = null;
        }

        closeBgCropperBtn.addEventListener('click', closeBgCropper);
        cancelBgCropBtn.addEventListener('click', closeBgCropper);
        bgCropperModal.addEventListener('click', function(e) {
            if (e.target === bgCropperModal) closeBgCropper();
        });

        confirmBgCropBtn.addEventListener('click', function() {
            if (!bgCropper) { alert('请先选择图片'); return; }
            // 获取裁剪后的 canvas（不限制尺寸，保持原比例或用户裁剪区域）
            const canvas = bgCropper.getCroppedCanvas();
            canvas.toBlob(function(blob) {
                bgUploadProgress.style.display = 'block';
                const bar = bgUploadProgress.querySelector('.upload-progress-bar');
                const status = bgUploadProgress.querySelector('.upload-status');
                bar.style.width = '0%';
                status.textContent = '上传中 0%';
                confirmBgCropBtn.disabled = true;
                
                uploadFile(blob, '/auth.php', function(formData) {
                    formData.append('action', 'upload_background');
                    formData.append('background', blob, 'background.jpg');
                }, function(percent) {
                    bar.style.width = percent + '%';
                    status.textContent = '上传中 ' + percent + '%';
                }, function(response) {
                    if (response.success) {
                        if (response.pending) {
                            // 需要审核，显示审核中占位图
                            bgIndicator.style.backgroundImage = 'url(/zbgameshz.png)';
                            alert('背景图已提交审核，请等待管理员审核通过后生效');
                        } else {
                            // 上传成功后删除旧背景图
                            if (oldBgUrl) {
                                fetch('/cleanup_utils.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: 'action=delete_file&file=' + encodeURIComponent(oldBgUrl) + '&csrf_token=<?php echo generateCsrfToken(); ?>'
                                }).catch(e => console.error);
                            }
                            bgIndicator.style.backgroundImage = `url(${response.background_url}?t=${Date.now()})`;
                            alert('背景图上传成功！');
                        }
                        closeBgCropper();
                        location.reload();
                    } else {
                        alert(response.message || '上传失败');
                    }
                }, function(errorMsg) {
                    alert('上传失败：' + errorMsg);
                }).finally(() => {
                    bgUploadProgress.style.display = 'none';
                    confirmBgCropBtn.disabled = false;
                });
            }, 'image/jpeg', 0.9);
        });

        // ========== 修改用户名 ==========
        const usernameItem = document.getElementById('usernameItem');
        const usernameModal = document.getElementById('usernameModal');
        const newUsernameInput = document.getElementById('newUsernameInput');
        const usernameModalFeedback = document.getElementById('usernameModalFeedback');
        const cancelUsernameBtn = document.getElementById('cancelUsernameBtn');
        const saveUsernameBtn = document.getElementById('saveUsernameBtn');
        const settingDesc = document.querySelector('#usernameItem .setting-desc');

        usernameItem.addEventListener('click', function() {
            newUsernameInput.value = '';
            usernameModalFeedback.textContent = '';
            usernameModal.style.display = 'flex';
        });

        function closeUsernameModal() {
            usernameModal.style.display = 'none';
        }

        cancelUsernameBtn.addEventListener('click', closeUsernameModal);
        usernameModal.addEventListener('click', function(e) {
            if (e.target === usernameModal) closeUsernameModal();
        });

        saveUsernameBtn.addEventListener('click', function() {
            const newName = newUsernameInput.value.trim();
            if (!newName) {
                usernameModalFeedback.textContent = '用户名不能为空';
                return;
            }
            if (newName.length < 2 || newName.length > 16) {
                usernameModalFeedback.textContent = '用户名长度需在2-16个字符之间';
                return;
            }
            saveUsernameBtn.disabled = true;
            saveUsernameBtn.textContent = '保存中...';
            const formData = new FormData();
            formData.append('action', 'update_username');
            formData.append('new_username', newName);
            fetch('/auth.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('用户名修改成功！');
                    closeUsernameModal();
                    // 刷新页面以更新所有显示和冷却计时
                    location.reload();
                } else {
                    usernameModalFeedback.textContent = result.message || '修改失败';
                }
            })
            .catch(error => {
                usernameModalFeedback.textContent = '网络错误，请重试';
            })
            .finally(() => {
                saveUsernameBtn.disabled = false;
                saveUsernameBtn.textContent = '保存';
            });
        });

        // ========== 修改密码 ==========
        const passwordItem = document.getElementById('passwordItem');
        const passwordModal = document.getElementById('passwordModal');
        const passwordError = document.getElementById('passwordError');
        const sendChangeCodeBtn = document.getElementById('sendChangeCodeBtn');
        const savePasswordBtn = document.getElementById('savePasswordBtn');
        const cancelPasswordBtn = document.getElementById('cancelPasswordBtn');
        const changeEmailCodeInput = document.getElementById('changeEmailCode');
        const newPasswordInput = document.getElementById('newPassword');
        const confirmPasswordInput = document.getElementById('confirmPassword');

        let changePwdCountdownInterval = null;
        let changePwdCountdownSeconds = 0;
        let changePwdCodeSent = false;

        function resetChangePwdState() {
            changePwdCodeSent = false;
            if (changePwdCountdownInterval) clearInterval(changePwdCountdownInterval);
            sendChangeCodeBtn.disabled = false;
            sendChangeCodeBtn.textContent = '获取验证码';
            changeEmailCodeInput.value = '';
            newPasswordInput.value = '';
            confirmPasswordInput.value = '';
            passwordError.textContent = '';
        }

        passwordItem.addEventListener('click', function() {
            resetChangePwdState();
            passwordModal.style.display = 'flex';
        });

        function closePasswordModal() {
            passwordModal.style.display = 'none';
            resetChangePwdState();
        }

        cancelPasswordBtn.addEventListener('click', closePasswordModal);
        passwordModal.addEventListener('click', function(e) {
            if (e.target === passwordModal) closePasswordModal();
        });

        sendChangeCodeBtn.addEventListener('click', function() {
            passwordError.textContent = '';
            sendChangeCodeBtn.disabled = true;
            sendChangeCodeBtn.textContent = '发送中...';
            
            const formData = new FormData();
            formData.append('action', 'send_change_password_code');
            formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
            
            fetch('/auth.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    changePwdCodeSent = true;
                    startChangePwdCountdown(sendChangeCodeBtn);
                } else {
                    passwordError.textContent = result.message;
                    sendChangeCodeBtn.disabled = false;
                    sendChangeCodeBtn.textContent = '获取验证码';
                }
            })
            .catch(error => {
                passwordError.textContent = '网络错误，请重试';
                sendChangeCodeBtn.disabled = false;
                sendChangeCodeBtn.textContent = '获取验证码';
            });
        });

        function startChangePwdCountdown(btn) {
            changePwdCountdownSeconds = 60;
            btn.disabled = true;
            btn.textContent = `${changePwdCountdownSeconds}秒后重试`;
            if (changePwdCountdownInterval) clearInterval(changePwdCountdownInterval);
            changePwdCountdownInterval = setInterval(() => {
                changePwdCountdownSeconds--;
                if (changePwdCountdownSeconds <= 0) {
                    clearInterval(changePwdCountdownInterval);
                    changePwdCountdownInterval = null;
                    btn.disabled = false;
                    btn.textContent = '获取验证码';
                } else {
                    btn.textContent = `${changePwdCountdownSeconds}秒后重试`;
                }
            }, 1000);
        }

        savePasswordBtn.addEventListener('click', function() {
            passwordError.textContent = '';
            const code = changeEmailCodeInput.value.trim();
            const newPwd = newPasswordInput.value;
            const confirmPwd = confirmPasswordInput.value;

            if (!code) { passwordError.textContent = '请输入邮箱验证码'; return; }
            if (!newPwd) { passwordError.textContent = '请输入新密码'; return; }
            if (newPwd.length < 6) { passwordError.textContent = '新密码至少6位'; return; }
            if (newPwd !== confirmPwd) { passwordError.textContent = '两次密码不一致'; return; }

            savePasswordBtn.disabled = true;
            savePasswordBtn.textContent = '保存中...';

            const formData = new FormData();
            formData.append('action', 'change_password');
            formData.append('code', code);
            formData.append('new_password', newPwd);
            formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            fetch('/auth.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('密码修改成功！');
                    closePasswordModal();
                } else {
                    passwordError.textContent = result.message;
                }
            })
            .catch(error => {
                passwordError.textContent = '网络错误，请重试';
            })
            .finally(() => {
                savePasswordBtn.disabled = false;
                savePasswordBtn.textContent = '保存';
            });
        });

        // ========== 注销账号（仅非站长可见） ==========
        const deleteAccountItem = document.getElementById('deleteAccountItem');
        const deleteAccountModal = document.getElementById('deleteAccountModal');
        const cancelDeleteAccountBtn = document.getElementById('cancelDeleteAccountBtn');
        const confirmDeleteAccountBtn = document.getElementById('confirmDeleteAccountBtn');
        const deleteAccountPassword = document.getElementById('deleteAccountPassword');
        const deleteAccountFeedback = document.getElementById('deleteAccountFeedback');

        if (deleteAccountItem) {
            deleteAccountItem.addEventListener('click', function() {
                deleteAccountPassword.value = '';
                deleteAccountFeedback.textContent = '';
                deleteAccountModal.style.display = 'flex';
            });
        }

        function closeDeleteAccountModal() {
            deleteAccountModal.style.display = 'none';
        }

        if (cancelDeleteAccountBtn) {
            cancelDeleteAccountBtn.addEventListener('click', closeDeleteAccountModal);
        }
        if (deleteAccountModal) {
            deleteAccountModal.addEventListener('click', function(e) {
                if (e.target === deleteAccountModal) closeDeleteAccountModal();
            });
        }

        if (confirmDeleteAccountBtn) {
            confirmDeleteAccountBtn.addEventListener('click', function() {
                const password = deleteAccountPassword.value.trim();
                if (!password) {
                    deleteAccountFeedback.textContent = '请输入密码';
                    return;
                }

                if (!confirm('确定要永久注销您的账号吗？此操作不可撤销！')) {
                    return;
                }

                confirmDeleteAccountBtn.disabled = true;
                confirmDeleteAccountBtn.textContent = '处理中...';
                deleteAccountFeedback.textContent = '';

                const formData = new FormData();
                formData.append('action', 'delete_account');
                formData.append('password', password);
                formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

                fetch('/auth.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert(result.message);
                        window.location.href = '<?php echo url('index'); ?>';
                    } else {
                        deleteAccountFeedback.textContent = result.message || '注销失败';
                        confirmDeleteAccountBtn.disabled = false;
                        confirmDeleteAccountBtn.textContent = '确认注销';
                    }
                })
                .catch(error => {
                    deleteAccountFeedback.textContent = '网络错误，请重试';
                    confirmDeleteAccountBtn.disabled = false;
                    confirmDeleteAccountBtn.textContent = '确认注销';
                });
            });
        }


    </script>
    <script src="/js/click-ripple.js?v=<?php echo filemtime(__DIR__ . '/js/click-ripple.js'); ?>"></script>
    <script>
        injectRippleStyle && injectRippleStyle();
        document.querySelectorAll('.setting-item:not(.readonly)').forEach(function(el) {
            el.setAttribute('data-ripple', '');
        });
        setTimeout(initRippleEffects, 100);
    </script>
</body>
</html>