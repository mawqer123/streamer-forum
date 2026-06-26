<?php
// auth_modal.php - 全屏登录/注册弹窗（白色卡片风格，主题色按钮）
// 全局 CSRF Token 已在 functions.php 中初始化，直接使用 $GLOBALS['_csrf_token_page']
?>
<!-- 登录/注册全屏页面 -->
<div id="authModal" class="modal">
    <div class="modal-container">
        <!-- 关闭按钮（右上角） -->
        <span class="close-btn" onclick="hideAuthModal()">&times;</span>
        
        <div class="form-container">
            <!-- 登录表单 -->
            <form id="loginForm" class="auth-form">
                <p class="innerText">用户登录</p>
                <p class="desc">欢迎回来，请登录你的账号</p>
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?php echo $GLOBALS['_csrf_token_page'] ?? ''; ?>">
                
                <div class="form-group">
                    <input type="text" id="loginIdentifier" name="identifier" class="input" placeholder="用户名或邮箱" required>
                </div>
                
                <div class="form-group">
                    <div class="password-input-wrapper">
                        <input type="password" id="loginPassword" name="password" class="input" placeholder="密码" required>
                        <button type="button" class="toggle-password-btn" data-target="loginPassword" title="显示/隐藏密码">
                            <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div class="form-error" id="loginError"></div>
                
                <button type="submit" class="btn-black">登录</button>

                <?php if (isEmailVerificationEnabled()): ?>
                <div style="text-align: center; margin-top: 1rem;">
                    <a href="javascript:void(0)" id="forgotPasswordLink" style="color: var(--input-focus); text-decoration: none; font-size: 0.9rem;">忘记密码？</a>
                </div>
                <?php endif; ?>
            </form>
            
            <!-- 注册表单 -->
            <form id="registerForm" class="auth-form" style="display: none;">
                <p class="innerText">用户注册</p>
                <p class="desc">创建新账号，加入社区</p>
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="csrf_token" value="<?php echo $GLOBALS['_csrf_token_page'] ?? ''; ?>">
                
                <div class="form-group">
                    <input type="text" id="registerUsername" name="username" class="input" placeholder="用户名 (2-16个字符)" required>
                    <small class="help-text">支持中文、字母、数字、下划线</small>
                </div>
                
                <div class="form-group">
                    <input type="email" id="registerEmail" name="email" class="input" placeholder="邮箱" required>
                </div>
                
                <div class="form-group">
                    <div class="password-input-wrapper">
                        <input type="password" id="registerPassword" name="password" class="input" placeholder="密码 (至少6位)" required>
                        <button type="button" class="toggle-password-btn" data-target="registerPassword" title="显示/隐藏密码">
                            <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <?php
                $captchaEnabled = getSetting('captcha_enabled', '0') === '1';
                if ($captchaEnabled):
                ?>
                <div class="form-group">
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <input type="text" id="captchaCode" name="captcha_code" class="input" style="flex:1;" placeholder="验证码" required>
                        <img id="captchaImg" data-src="/captcha.php" alt="验证码" style="height:40px; border-radius:0; cursor:pointer;" onclick="refreshCaptcha()" title="点击刷新">
                        <button type="button" id="refreshCaptchaBtn" style="background:none; border:1px solid var(--border-color); border-radius:0; padding:0.3rem 0.6rem; cursor:pointer;">↻</button>
                    </div>
                    <small class="help-text">不区分大小写，点击图片刷新</small>
                </div>
                <?php endif; ?>

                <?php
                $emailVerificationEnabled = isEmailVerificationEnabled();
                if ($emailVerificationEnabled):
                ?>
                <div class="form-group">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="text" id="emailCode" name="email_code" class="input" style="flex:1;" placeholder="邮箱验证码" required>
                        <button type="button" id="sendEmailCodeBtn" class="send-code-btn">获取验证码</button>
                    </div>
                    <small class="help-text">验证码将发送至注册邮箱，有效期5分钟</small>
                    <div id="emailCodeError" class="form-error" style="display: none;"></div>
                </div>
                <?php endif; ?>
                
                <div class="form-error" id="registerError"></div>
                
                <button type="submit" class="btn-black">注册</button>
            </form>

            <!-- 忘记密码表单 -->
            <form id="forgotPasswordForm" class="auth-form" style="display: none;">
                <p class="innerText">重置密码</p>
                <p class="desc">输入注册邮箱，我们将发送验证码</p>
                <input type="hidden" name="csrf_token" value="<?php echo $GLOBALS['_csrf_token_page'] ?? ''; ?>">
                
                <div class="form-group">
                    <input type="email" id="resetEmail" name="email" class="input" placeholder="注册邮箱" required>
                </div>
                <div class="form-group" id="resetCodeGroup" style="display: none;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="text" id="resetCode" name="code" class="input" style="flex:1;" placeholder="验证码" required>
                        <button type="button" id="resetSendCodeBtn" class="send-code-btn">获取验证码</button>
                    </div>
                    <small class="help-text">有效期5分钟</small>
                </div>
                <div class="form-group" id="resetPasswordGroup" style="display: none;">
                    <div class="password-input-wrapper">
                        <input type="password" id="resetPassword" name="new_password" class="input" placeholder="新密码 (至少6位)" required>
                        <button type="button" class="toggle-password-btn" data-target="resetPassword" title="显示/隐藏密码">
                            <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="form-group" id="resetConfirmGroup" style="display: none;">
                    <div class="password-input-wrapper">
                        <input type="password" id="resetConfirmPassword" name="confirm_password" class="input" placeholder="再次输入新密码" required>
                        <button type="button" class="toggle-password-btn" data-target="resetConfirmPassword" title="显示/隐藏密码">
                            <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="form-error" id="forgotPasswordError"></div>
                <button type="submit" class="btn-black" id="resetSubmitBtn">重置密码</button>
                <div style="text-align: center; margin-top: 1rem;">
                    <a href="javascript:void(0)" id="backToLoginLink" style="color: var(--input-focus); text-decoration: none; font-size: 0.9rem;">返回登录</a>
                </div>
            </form>
            
            <div class="auth-switch" id="authSwitchContainer">
                <span id="switchText">还没有账号？</span>
                <a href="#" id="switchLink" class="switch-link">立即注册</a>
            </div>
            <?php 
            $githubEnabledForModal = getSetting('github_oauth_enabled', '0') === '1' && !empty(getSetting('github_client_id', ''));
            $giteeEnabledForModal = getSetting('gitee_oauth_enabled', '0') === '1' && !empty(getSetting('gitee_client_id', ''));
            ?>
            <?php if ($githubEnabledForModal || $giteeEnabledForModal): ?>
            <div class="github-login-row" id="githubLoginRow">
                <?php if ($githubEnabledForModal): ?>
                <a href="javascript:void(0)" class="github-login-btn" onclick="githubLogin()">
                    <svg viewBox="0 0 24 24" fill="currentColor" style="width: 20px; height: 20px;"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                    <span>使用 GitHub 登录</span>
                </a>
                <?php endif; ?>
                <?php if ($giteeEnabledForModal): ?>
                <a href="javascript:void(0)" class="github-login-btn" onclick="giteeLogin()" style="margin-top: 8px;">
                    <svg viewBox="0 0 90 90" style="width: 20px; height: 20px;"><circle fill="#C71D23" cx="45" cy="45" r="44.85"/><path d="M67.56 39.87H42.09c-1.22.01-2.22 1-2.22 2.22l-.01 5.54c0 1.22 1 2.21 2.22 2.21h15.5c1.23 0 2.22.99 2.22 2.22v.55l.01.56c0 3.67-2.98 6.64-6.65 6.64H32.12c-1.22 0-2.21-.99-2.21-2.21V36.55c0-3.67 2.97-6.64 6.65-6.64h31c1.22 0 2.22-.99 2.22-2.22v-5.54c0-1.22-1-2.22-2.22-2.22H36.55C27.37 19.94 19.94 27.37 19.94 36.55v31c0 1.22 1 2.21 2.22 2.21h32.67c8.26 0 14.95-6.69 14.95-14.95V42.09c0-1.22-1-2.22-2.22-2.22z" fill="#FFF"/></svg>
                    <span>使用 Gitee 登录</span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div style="text-align: center; margin-top: 1rem; padding-top: 0.8rem; border-top: 1px solid var(--border-color);">
                <a href="privacy.php" target="_blank" style="color: var(--text-secondary); text-decoration: none; font-size: 0.8rem; margin: 0 0.5rem;">隐私政策</a>
                <span style="color: var(--border-color);">|</span>
                <a href="terms.php" target="_blank" style="color: var(--text-secondary); text-decoration: none; font-size: 0.8rem; margin: 0 0.5rem;">服务条款</a>
            </div>
        </div>
    </div>
</div>

<style>
/* 全屏白色登录页样式 */
.modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: #fff;
    overflow-y: auto;
}

/* 弹窗容器 */
.modal-container {
    position: relative;
    width: 100%;
    min-height: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
    box-sizing: border-box;
}

/* 白色卡片 */
.card {
    width: 400px;
    max-width: 100%;
    padding: 0;
}

/* 关闭按钮 */
.close-btn {
    position: absolute;
    top: 12px;
    right: 16px;
    font-size: 28px;
    font-weight: 400;
    color: #999;
    cursor: pointer;
    line-height: 1;
    background: none;
    border: none;
    transition: color 0.2s;
    padding: 0;
}
.close-btn:hover {
    color: #333;
}

/* 标题 */
.innerText {
    font-size: 24px;
    font-weight: 700;
    color: #333;
    line-height: 1.3;
    margin: 0 0 6px;
    text-align: center;
}
.desc {
    color: #888;
    font-size: 14px;
    margin: 0 0 24px;
    text-align: center;
}

/* 输入框 */
.input {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    outline: none;
    color: #333;
    background: #f9f9f9;
    font-size: 15px;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
    margin-bottom: 12px;
}
.input:focus {
    border-color: var(--accent-color, #2196F3);
    box-shadow: 0 0 0 3px rgba(33,150,243,0.1);
    background: #fff;
}

/* 密码可见切换 */
.password-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.password-input-wrapper input {
    padding-right: 2.8rem;
}
.toggle-password-btn {
    position: absolute;
    right: 8px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 6px;
    color: #999;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
}
.toggle-password-btn:hover {
    background: rgba(0,0,0,0.05);
    color: #333;
}
.eye-icon {
    width: 20px;
    height: 20px;
}

/* 提交按钮 — 沿用论坛主题色 */
.btn-black {
    width: 100%;
    padding: 12px;
    background: var(--accent-color, #2196F3);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.2s;
    margin-top: 8px;
}
.btn-black:hover {
    opacity: 0.85;
}
.btn-black:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* 发送验证码按钮 */
.send-code-btn {
    background: var(--accent-color, #2196F3);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
    transition: opacity 0.2s;
}
.send-code-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* 错误消息 */
.form-error {
    color: #e53e3e;
    font-size: 13px;
    margin: 6px 0;
    min-height: 20px;
}

/* 帮助文字 */
.help-text {
    font-size: 12px;
    color: #888;
    margin-top: 2px;
    display: block;
}

/* 切换链接 */
.auth-switch {
    text-align: center;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #eee;
    color: #666;
    font-size: 14px;
}
.switch-link {
    color: var(--accent-color, #2196F3);
    text-decoration: none;
    font-weight: 600;
    margin-left: 4px;
}
.switch-link:hover {
    text-decoration: underline;
}

/* GitHub 登录按钮 */
.github-login-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fff;
    color: #333;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.2s;
    cursor: pointer;
    box-sizing: border-box;
}
.github-login-btn:hover {
    background: #f6f8fa;
}

/* 忘记密码链接 */
a#forgotPasswordLink,
a#backToLoginLink {
    color: var(--accent-color, #2196F3) !important;
}

/* 移动端适配 */
@media (max-width: 768px) {
    .card {
        padding: 24px 20px;
    }
    .innerText {
        font-size: 22px;
    }
}
</style>

<script>
// 刷新验证码图片
// Load captcha image lazily
function loadCaptcha() {
    var img = document.getElementById("captchaImg");
    if (img && !img.src && img.dataset.src) {
        img.src = img.dataset.src + "?" + Date.now();
    }
}

function refreshCaptcha() {
    const img = document.getElementById('captchaImg');
    if (img) {
        img.src = '/captcha.php?' + Date.now();
    }
}
window.refreshCaptcha = refreshCaptcha;

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('authModal');
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const forgotPasswordForm = document.getElementById('forgotPasswordForm');
    const switchLink = document.getElementById('switchLink');
    const switchText = document.getElementById('switchText');
    const authSwitchContainer = document.getElementById('authSwitchContainer');
    const loginError = document.getElementById('loginError');
    const registerError = document.getElementById('registerError');
    const forgotPasswordError = document.getElementById('forgotPasswordError');
    
    let isLoginMode = true;
    let countdownInterval = null;
    let countdownSeconds = 0;
    let resetStep = 1;

    // 忘记密码相关元素
    const resetEmail = document.getElementById('resetEmail');
    const resetCodeGroup = document.getElementById('resetCodeGroup');
    const resetPasswordGroup = document.getElementById('resetPasswordGroup');
    const resetConfirmGroup = document.getElementById('resetConfirmGroup');
    const resetSendCodeBtn = document.getElementById('resetSendCodeBtn');
    const resetSubmitBtn = document.getElementById('resetSubmitBtn');
    const forgotPasswordLink = document.getElementById('forgotPasswordLink');
    const backToLoginLink = document.getElementById('backToLoginLink');

    // 显示全屏登录页
    window.showAuthModal = function(login = true) {
        modal.style.display = 'block';
        // Lazy load captcha when modal opens
        loadCaptcha();
        document.body.style.overflow = 'hidden';
        
        if (login) {
            showLoginForm();
        } else {
            showRegisterForm();
        }
    };
    
    // 隐藏
    window.hideAuthModal = function() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        clearErrors();
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
        resetForgotPasswordForm();
        const sendBtn = document.getElementById('sendEmailCodeBtn');
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.textContent = '获取验证码';
        }
    };
    
    function showLoginForm() {
        isLoginMode = true;
        loginForm.style.display = 'block';
        registerForm.style.display = 'none';
        forgotPasswordForm.style.display = 'none';
        switchText.textContent = '还没有账号？';
        switchLink.textContent = '立即注册';
        authSwitchContainer.style.display = 'block';
        clearErrors();
    }
    
    function showRegisterForm() {
        isLoginMode = false;
        loginForm.style.display = 'none';
        registerForm.style.display = 'block';
        forgotPasswordForm.style.display = 'none';
        switchText.textContent = '已有账号？';
        switchLink.textContent = '立即登录';
        authSwitchContainer.style.display = 'block';
        clearErrors();
        refreshCaptcha();
        const sendBtn = document.getElementById('sendEmailCodeBtn');
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.textContent = '获取验证码';
        }
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    }

    function showForgotPasswordForm() {
        loginForm.style.display = 'none';
        registerForm.style.display = 'none';
        forgotPasswordForm.style.display = 'block';
        authSwitchContainer.style.display = 'none';
        clearErrors();
        resetForgotPasswordForm();
    }

    function resetForgotPasswordForm() {
        resetStep = 1;
        resetEmail.value = '';
        resetEmail.disabled = false;
        resetCodeGroup.style.display = 'none';
        resetPasswordGroup.style.display = 'none';
        resetConfirmGroup.style.display = 'none';
        resetSendCodeBtn.disabled = false;
        resetSendCodeBtn.textContent = '获取验证码';
        document.getElementById('resetCode').value = '';
        document.getElementById('resetPassword').value = '';
        document.getElementById('resetConfirmPassword').value = '';
        forgotPasswordError.textContent = '';
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    }
    
    function clearErrors() {
        loginError.textContent = '';
        registerError.textContent = '';
        forgotPasswordError.textContent = '';
        const emailCodeError = document.getElementById('emailCodeError');
        if (emailCodeError) emailCodeError.style.display = 'none';
    }
    
    // 发送邮箱验证码（注册用）- 修复 VULN-006：添加 CSRF token
    async function sendEmailCode() {
        const emailInput = document.getElementById('registerEmail');
        const email = emailInput.value.trim();
        const errorDiv = document.getElementById('emailCodeError');
        const sendBtn = document.getElementById('sendEmailCodeBtn');
        
        if (!email) {
            errorDiv.textContent = '请先填写邮箱地址';
            errorDiv.style.display = 'block';
            emailInput.focus();
            return false;
        }
        if (!/^[^\s@]+@([^\s@.,]+\.)+[^\s@.,]{2,}$/.test(email)) {
            errorDiv.textContent = '邮箱格式不正确';
            errorDiv.style.display = 'block';
            emailInput.focus();
            return false;
        }
        
        // 获取 CSRF token（从注册表单中获取）
        const csrfToken = document.querySelector('#registerForm input[name="csrf_token"]').value;
        if (!csrfToken) {
            errorDiv.textContent = '安全令牌缺失，请刷新页面重试';
            errorDiv.style.display = 'block';
            return false;
        }
        
        errorDiv.style.display = 'none';
        sendBtn.disabled = true;
        sendBtn.textContent = '发送中...';
        
        try {
            const formData = new FormData();
            formData.append('action', 'send_email_code');
            formData.append('email', email);
            formData.append('csrf_token', csrfToken);
            
            const response = await fetch('/auth.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                errorDiv.style.color = '#38a169';
                errorDiv.textContent = result.message;
                errorDiv.style.display = 'block';
                startCountdown(sendBtn);
            } else {
                errorDiv.style.color = '#e53e3e';
                errorDiv.textContent = result.message;
                errorDiv.style.display = 'block';
                sendBtn.disabled = false;
                sendBtn.textContent = '获取验证码';
            }
        } catch (err) {
            errorDiv.style.color = '#e53e3e';
            errorDiv.textContent = '网络错误，请重试';
            errorDiv.style.display = 'block';
            sendBtn.disabled = false;
            sendBtn.textContent = '获取验证码';
        }
        return false;
    }

    // 忘记密码 - 发送验证码
    async function sendResetCode() {
        const email = resetEmail.value.trim();
        if (!email) {
            forgotPasswordError.textContent = '请输入注册邮箱';
            return;
        }
        if (!/^[^\s@]+@([^\s@.,]+\.)+[^\s@.,]{2,}$/.test(email)) {
            forgotPasswordError.textContent = '邮箱格式不正确';
            return;
        }
        
        resetSendCodeBtn.disabled = true;
        resetSendCodeBtn.textContent = '发送中...';
        forgotPasswordError.textContent = '';
        
        try {
            const formData = new FormData();
            formData.append('action', 'reset_password_request');
            formData.append('email', email);
            formData.append('csrf_token', document.querySelector('#forgotPasswordForm input[name="csrf_token"]').value);
            
            const response = await fetch('/auth.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                resetStep = 2;
                resetEmail.disabled = true;
                resetCodeGroup.style.display = 'block';
                resetPasswordGroup.style.display = 'block';
                resetConfirmGroup.style.display = 'block';
                startCountdown(resetSendCodeBtn);
            } else {
                forgotPasswordError.textContent = result.message;
                resetSendCodeBtn.disabled = false;
                resetSendCodeBtn.textContent = '获取验证码';
            }
        } catch (err) {
            forgotPasswordError.textContent = '网络错误，请重试';
            resetSendCodeBtn.disabled = false;
            resetSendCodeBtn.textContent = '获取验证码';
        }
    }
    
    function startCountdown(btn) {
        countdownSeconds = 60;
        btn.disabled = true;
        btn.textContent = `${countdownSeconds}秒后重试`;
        if (countdownInterval) clearInterval(countdownInterval);
        countdownInterval = setInterval(() => {
            countdownSeconds--;
            if (countdownSeconds <= 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                btn.disabled = false;
                btn.textContent = '获取验证码';
            } else {
                btn.textContent = `${countdownSeconds}秒后重试`;
            }
        }, 1000);
    }
    
    // 绑定发送验证码按钮事件（注册）
    const sendCodeBtn = document.getElementById('sendEmailCodeBtn');
    if (sendCodeBtn) {
        sendCodeBtn.addEventListener('click', sendEmailCode);
    }

    // 绑定忘记密码发送验证码按钮事件
    if (resetSendCodeBtn) {
        resetSendCodeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            sendResetCode();
        });
    }
    
    // 刷新验证码按钮事件
    const refreshBtn = document.getElementById('refreshCaptchaBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', refreshCaptcha);
    }
    const captchaImg = document.getElementById('captchaImg');
    if (captchaImg) {
        captchaImg.addEventListener('click', refreshCaptcha);
    }
    
    // 切换登录/注册
    switchLink.addEventListener('click', function(e) {
        e.preventDefault();
        if (isLoginMode) {
            showRegisterForm();
        } else {
            showLoginForm();
        }
    });

    // 忘记密码链接点击
    if (forgotPasswordLink) {
        forgotPasswordLink.addEventListener('click', function(e) {
            e.preventDefault();
            showForgotPasswordForm();
        });
    }

    // 返回登录链接点击
    if (backToLoginLink) {
        backToLoginLink.addEventListener('click', function(e) {
            e.preventDefault();
            showLoginForm();
        });
    }
    
    // 点击关闭按钮
    document.querySelector('.close-btn').addEventListener('click', hideAuthModal);
    
    // 点击模态框外部关闭（点击背景空白区）
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            hideAuthModal();
        }
    });
    
    // 密码可见切换功能
    document.querySelectorAll('.toggle-password-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (input) {
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
            }
        });
    });
    
    // 表单提交
    loginForm.addEventListener('submit', handleSubmit);
    registerForm.addEventListener('submit', handleSubmit);
    forgotPasswordForm.addEventListener('submit', handleForgotPasswordSubmit);
    
    async function handleSubmit(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const errorDiv = form.id === 'loginForm' ? loginError : registerError;
        
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = '处理中...';
        
        try {
            const response = await fetch('/auth.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                errorDiv.style.color = '#38a169';
                errorDiv.textContent = result.message;
                setTimeout(() => {
                    if (result.redirect) {
                        window.location.href = result.redirect;
                    } else {
                        location.reload();
                    }
                }, 1000);
            } else {
                errorDiv.textContent = result.message;
                errorDiv.style.color = '#e53e3e';
                if (form.id === 'registerForm') refreshCaptcha();
            }
        } catch (error) {
            errorDiv.textContent = '网络错误，请稍后重试';
            errorDiv.style.color = '#e53e3e';
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = form.id === 'loginForm' ? '登录' : '注册';
        }
    }

    async function handleForgotPasswordSubmit(e) {
        e.preventDefault();
        const form = e.target;
        const errorDiv = forgotPasswordError;
        errorDiv.textContent = '';

        if (resetStep === 1) {
            sendResetCode();
            return;
        }

        const code = document.getElementById('resetCode').value.trim();
        const newPassword = document.getElementById('resetPassword').value;
        const confirmPassword = document.getElementById('resetConfirmPassword').value;

        if (!code) { errorDiv.textContent = '请输入验证码'; return; }
        if (!newPassword) { errorDiv.textContent = '请输入新密码'; return; }
        if (newPassword.length < 6) { errorDiv.textContent = '密码至少6位'; return; }
        if (newPassword !== confirmPassword) { errorDiv.textContent = '两次密码不一致'; return; }

        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = '处理中...';

        try {
            const formData = new FormData();
            formData.append('action', 'reset_password_reset');
            formData.append('email', resetEmail.value);
            formData.append('code', code);
            formData.append('new_password', newPassword);
            formData.append('csrf_token', document.querySelector('#forgotPasswordForm input[name="csrf_token"]').value);

            const response = await fetch('/auth.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                errorDiv.style.color = '#38a169';
                errorDiv.textContent = result.message;
                setTimeout(() => {
                    hideAuthModal();
                    showAuthModal(true);
                }, 2000);
            } else {
                errorDiv.textContent = result.message;
                errorDiv.style.color = '#e53e3e';
            }
        } catch (err) {
            errorDiv.textContent = '网络错误，请重试';
            errorDiv.style.color = '#e53e3e';
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = '重置密码';
        }
    }
    
    // ESC键关闭
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            hideAuthModal();
        }
    });
    
    // 初始化验证码
    if (document.getElementById('captchaImg')) {
        refreshCaptcha();
    }
});

    function githubLogin() {
        window.location.href = "auth.php?action=github_login";
    }
    function giteeLogin() {
        window.location.href = 'auth.php?action=gitee_login';
    }
</script>