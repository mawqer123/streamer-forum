<?php
// auth.php - 登录/注册处理文件（支持验证码校验、邮箱验证、忘记密码、修改密码、账号注销）
require_once __DIR__ . '/functions.php';

// OAuth 流程需要提前启动 Session，否则 $_SESSION 写入不生效
start_session_force();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ========== GitHub OAuth 登录重定向 ==========
if ($action === 'github_login') {
    $githubEnabled = getSetting('github_oauth_enabled', '0') === '1';
    $clientId = getSetting('github_client_id', '');
    if (!$githubEnabled || empty($clientId)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'GitHub 登录未开启']);
        exit;
    }
    $redirectUri = rtrim(SITE_URL, '/') . '/auth.php?action=github_callback';
    $state = bin2hex(random_bytes(16));
    $_SESSION['github_oauth_state'] = $state;
    $url = 'https://github.com/login/oauth/authorize?client_id=' . urlencode($clientId)
         . '&redirect_uri=' . urlencode($redirectUri)
         . '&scope=read:user,user:email'
         . '&state=' . $state;
    header('Location: ' . $url);
    exit;
}

// ========== Gitee OAuth 登录重定向 ==========
if ($action === 'gitee_login') {
    $giteeEnabled = getSetting('gitee_oauth_enabled', '0') === '1';
    $clientId = getSetting('gitee_client_id', '');
    if (!$giteeEnabled || empty($clientId)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Gitee 登录未开启']);
        exit;
    }
    $redirectUri = rtrim(SITE_URL, '/') . '/auth.php?action=gitee_callback';
    $state = bin2hex(random_bytes(16));
    $_SESSION['gitee_oauth_state'] = $state;
    $url = 'https://gitee.com/oauth/authorize?client_id=' . urlencode($clientId)
         . '&redirect_uri=' . urlencode($redirectUri)
         . '&response_type=code'
         . '&state=' . $state;
    header('Location: ' . $url);
    exit;
}

// ========== Gitee OAuth 回调处理 ==========
if ($action === 'gitee_callback') {
    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    $savedState = $_SESSION['gitee_oauth_state'] ?? '';
    unset($_SESSION['gitee_oauth_state']);
    
    if (empty($code) || empty($state) || $state !== $savedState) {
        header('Location: ' . SITE_URL . '?error=gitee_auth_failed');
        exit;
    }
    
    $clientId = getSetting('gitee_client_id', '');
    $clientSecret = getSetting('gitee_client_secret', '');
    $redirectUri = rtrim(SITE_URL, '/') . '/auth.php?action=gitee_callback';
    
    // 交换 access token（Gitee 用 POST）
    $tokenUrl = 'https://zbgame.hyperspark.cn/gitee_proxy/oauth/token';
    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $tokenResponse = curl_exec($ch);
    curl_close($ch);
    $tokenData = json_decode($tokenResponse, true);
    $accessToken = $tokenData['access_token'] ?? '';
    
    if (empty($accessToken)) {
        header('Location: ' . SITE_URL . '?error=gitee_token_failed');
        exit;
    }
    
    // 获取 Gitee 用户信息
    $ch = curl_init('https://zbgame.hyperspark.cn/gitee_proxy/api/v5/user?access_token=' . urlencode($accessToken));
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $userResponse = curl_exec($ch);
    curl_close($ch);
    $giteeUser = json_decode($userResponse, true);
    $giteeId = (string)($giteeUser['id'] ?? '');
    $giteeLogin = $giteeUser['login'] ?? '';
    $giteeEmail = $giteeUser['email'] ?? '';
    
    if (empty($giteeId)) {
        header('Location: ' . SITE_URL . '?error=gitee_user_failed');
        exit;
    }
    
    $pdo = getDbConnection();
    
    // 已登录 → 绑定
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE gitee_id = ? AND id != ? LIMIT 1");
        $stmt->execute([$giteeId, $_SESSION['user_id']]);
        $otherUser = $stmt->fetch();
        if ($otherUser) {
            $otherId = $otherUser['id'];
            // 自动转移：如果对方是 OAuth 自动注册的空账号（无帖子/无评论），转移绑定并删除
            $st2 = $pdo->prepare("SELECT is_gitee_user FROM users WHERE id = ?");
            $st2->execute([$otherId]);
            $other = $st2->fetch();
            $st3 = $pdo->prepare("SELECT COUNT(*) as c FROM posts WHERE user_id = ?");
            $st3->execute([$otherId]);
            $oc = (int)$st3->fetchColumn();
            $st4 = $pdo->prepare("SELECT COUNT(*) as c FROM comments WHERE user_id = ?");
            $st4->execute([$otherId]);
            $cc = (int)$st4->fetchColumn();
            if (!empty($other['is_gitee_user']) && $oc === 0 && $cc === 0) {
                // 解绑空账号并删除
                $pdo->prepare("UPDATE users SET gitee_id = NULL, gitee_username = NULL, gitee_avatar = NULL, is_gitee_user = 0 WHERE id = ?")->execute([$otherId]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$otherId]);
                // 放行到下面继续绑定
            } else {
                header('Location: ' . SITE_URL . '?error=gitee_already_bound');
                exit;
            }
        }
        $pdo->prepare("UPDATE users SET gitee_id = ?, gitee_username = ?, gitee_avatar = ? WHERE id = ?")->execute([$giteeId, $giteeLogin, $giteeUser['avatar_url'] ?? '', $_SESSION['user_id']]);
        header('Location: ' . SITE_URL . '?success=gitee_bound');
        exit;
    }
    
    // 查找已有
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE gitee_id = ? LIMIT 1");
    $stmt->execute([$giteeId]);
    $existingUser = $stmt->fetch();
    if ($existingUser) {
        $_SESSION['user_id'] = $existingUser['id'];
        session_regenerate_id(true);
        header('Location: ' . SITE_URL);
        exit;
    }
    
    // 按邮箱匹配
    if (!empty($giteeEmail)) {
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$giteeEmail]);
        $emailUser = $stmt->fetch();
        if ($emailUser) {
            $pdo->prepare("UPDATE users SET gitee_id = ?, gitee_username = ?, gitee_avatar = ? WHERE id = ?")->execute([$giteeId, $giteeLogin, $giteeUser['avatar_url'] ?? '', $emailUser['id']]);
            $_SESSION['user_id'] = $emailUser['id'];
            session_regenerate_id(true);
            header('Location: ' . SITE_URL);
            exit;
        }
    }
    
    // 创建新用户
    $username = $giteeLogin;
    $baseUsername = $username;
    $suffix = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        if (!$stmt->fetch()) break;
        $username = $baseUsername . $suffix;
        $suffix++;
    }
    
    $hashedPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, gitee_id, gitee_username, gitee_avatar, created_at, is_gitee_user) VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)");
    $stmt->execute([$username, $giteeEmail ?: '', $hashedPassword, $giteeId, $giteeLogin, $giteeUser['avatar_url'] ?? '']);
    $newUserId = $pdo->lastInsertId();
    $_SESSION['user_id'] = $newUserId;
    session_regenerate_id(true);
    header('Location: ' . SITE_URL);
    exit;
}
// GitHub API 反向代理（通过 nginx 加速国内访问）
$githubApiProxy = "https://zbgame.hyperspark.cn/ghproxy_github/";
$githubApiProxyApi = "https://zbgame.hyperspark.cn/ghproxy_api/";
// ========== GitHub OAuth 回调处理 ==========
if ($action === 'github_callback') {
    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    $savedState = $_SESSION['github_oauth_state'] ?? '';
    unset($_SESSION['github_oauth_state']);
    
    if (empty($code) || empty($state) || $state !== $savedState) {
        header('Location: ' . SITE_URL . '?error=github_auth_failed');
        exit;
    }
    
    $clientId = getSetting('github_client_id', '');
    $clientSecret = getSetting('github_client_secret', '');
    $redirectUri = rtrim(SITE_URL, '/') . '/auth.php?action=github_callback';
    
    // 交换 access token
    $tokenUrl = 'https://github.com/login/oauth/access_token';
    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $tokenResponse = curl_exec($ch);
    curl_close($ch);
    $tokenData = json_decode($tokenResponse, true);
    $accessToken = $tokenData['access_token'] ?? '';
    
    if (empty($accessToken)) {
        header('Location: ' . SITE_URL . '?error=github_token_failed');
        exit;
    }
    
    // 获取 GitHub 用户信息
    $ch = curl_init($githubApiProxyApi . 'user');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'User-Agent: StreamerForum/1.0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $userResponse = curl_exec($ch);
    curl_close($ch);
    $githubUser = json_decode($userResponse, true);
    $githubId = (string)($githubUser['id'] ?? '');
    $githubLogin = $githubUser['login'] ?? '';
    
    if (empty($githubId)) {
        header('Location: ' . SITE_URL . '?error=github_user_failed');
        exit;
    }
    
    // 获取 GitHub 邮箱
    $ch = curl_init($githubApiProxyApi . 'user/emails');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'User-Agent: StreamerForum/1.0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $emailResponse = curl_exec($ch);
    curl_close($ch);
    $emails = json_decode($emailResponse, true) ?: [];
    $primaryEmail = '';
    foreach ($emails as $e) {
        if (!empty($e['primary']) && !empty($e['email'])) {
            $primaryEmail = $e['email'];
            break;
        }
    }
    
    $pdo = getDbConnection();
    
    // ========== 情况1：用户已登录 → 绑定 GitHub 到当前账户 ==========
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE github_id = ? AND id != ? LIMIT 1");
        $stmt->execute([$githubId, $_SESSION['user_id']]);
        $otherUser = $stmt->fetch();
        if ($otherUser) {
            $otherId = $otherUser['id'];
            // 自动转移：如果对方是 OAuth 自动注册的空账号（无帖子/无评论），转移绑定并删除
            $st2 = $pdo->prepare("SELECT is_github_user FROM users WHERE id = ?");
            $st2->execute([$otherId]);
            $other = $st2->fetch();
            $st3 = $pdo->prepare("SELECT COUNT(*) as c FROM posts WHERE user_id = ?");
            $st3->execute([$otherId]);
            $oc = (int)$st3->fetchColumn();
            $st4 = $pdo->prepare("SELECT COUNT(*) as c FROM comments WHERE user_id = ?");
            $st4->execute([$otherId]);
            $cc = (int)$st4->fetchColumn();
            if (!empty($other['is_github_user']) && $oc === 0 && $cc === 0) {
                // 解绑空账号并删除
                $pdo->prepare("UPDATE users SET github_id = NULL, github_username = NULL, github_avatar = NULL, is_github_user = 0 WHERE id = ?")->execute([$otherId]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$otherId]);
                // 放行到下面继续绑定
            } else {
                header('Location: ' . SITE_URL . '?error=github_already_bound');
                exit;
            }
        }
        $pdo->prepare("UPDATE users SET github_id = ?, github_username = ?, github_avatar = ? WHERE id = ?")->execute([$githubId, $githubLogin, $githubUser['avatar_url'] ?? '', $_SESSION['user_id']]);
        header('Location: ' . SITE_URL . '?success=github_bound');
        exit;
    }
    
    // ========== 情况2：未登录 → 查找/自动注册 ==========
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE github_id = ? LIMIT 1");
    $stmt->execute([$githubId]);
    $existingUser = $stmt->fetch();
    
    if ($existingUser) {
        $_SESSION['user_id'] = $existingUser['id'];
        session_regenerate_id(true);
        header('Location: ' . SITE_URL);
        exit;
    }
    
    if (!empty($primaryEmail)) {
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$primaryEmail]);
        $emailUser = $stmt->fetch();
        if ($emailUser) {
            $pdo->prepare("UPDATE users SET github_id = ?, github_username = ?, github_avatar = ? WHERE id = ?")->execute([$githubId, $githubLogin, $githubUser['avatar_url'] ?? '', $emailUser['id']]);
            $_SESSION['user_id'] = $emailUser['id'];
            session_regenerate_id(true);
            header('Location: ' . SITE_URL);
            exit;
        }
    }
    
    $username = $githubLogin;
    $baseUsername = $username;
    $suffix = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        if (!$stmt->fetch()) break;
        $username = $baseUsername . $suffix;
        $suffix++;
    }
    
    $hashedPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, github_id, github_username, github_avatar, created_at, is_github_user) VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)");
    $stmt->execute([$username, $primaryEmail ?: '', $hashedPassword, $githubId, $githubLogin, $githubUser['avatar_url'] ?? '']);
    $newUserId = $pdo->lastInsertId();
    
    $_SESSION['user_id'] = $newUserId;
    session_regenerate_id(true);
    header('Location: ' . SITE_URL);
    exit;
}

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

try {
    $pdo = getDbConnection();
    
    switch ($action) {
        case 'login':
            $identifier = trim($_POST['identifier'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if (empty($identifier) || empty($password)) {
                throw new Exception('请填写所有必填项！');
            }
            
            // 查询用户（支持邮箱或用户名），同时获取 is_banned 和 is_founder 字段
            $stmt = $pdo->prepare("SELECT id, username, email, password, is_admin, is_founder, avatar, avatar_text, avatar_bg_color, avatar_pending, profile_background, background_pending, is_banned, last_username_change FROM users 
                                  WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();
            
            if (!$user || !verifyPassword($password, $user['password'])) {
                throw new Exception('用户名/邮箱或密码错误！');
            }
            
            // 登录成功，强制启动 Session
            start_session_force();
            session_regenerate_id(true); // 安全：重新生成 session ID
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['is_founder'] = $user['is_founder'];
            $_SESSION['last_username_change'] = $user['last_username_change'];
            $_SESSION['avatar'] = $user['avatar'];
            $_SESSION['avatar_text'] = $user['avatar_text'];
            $_SESSION['avatar_bg_color'] = $user['avatar_bg_color'];
            $_SESSION['avatar_pending'] = $user['avatar_pending'] ?? false;
            $_SESSION['background_pending'] = $user['background_pending'] ?? false;
            $_SESSION['profile_background'] = $user['profile_background'];
            $_SESSION['is_banned'] = $user['is_banned'];
            
            // 更新最后登录时间
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
                ->execute([$user['id']]);
            
            // 记录在线用户
            trackOnlineUser();
            
            $response['success'] = true;
            $response['message'] = '登录成功！';
            $response['redirect'] = 'index.php';
            break;
            
        case 'send_email_code':
            // 1. CSRF 防护（注册场景下需要，因为调用前页面已生成 token）
            if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('CSRF令牌验证失败，请刷新页面后重试');
            }
            
            $email = trim($_POST['email'] ?? '');
            if (empty($email)) {
                throw new Exception('邮箱不能为空');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('邮箱格式不正确');
            }
            
            // 检查邮箱是否已被注册
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('该邮箱已被注册');
            }
            
            // 2. 速率限制：基于 IP + 邮箱（同一 IP 对同一邮箱的限制）
            // 确保 session 已启动（用于存储速率限制数据）
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
                cleanup_expired_sessions();
            }
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $rateKey = 'email_rate_' . md5($ip . '|' . $email);
            
            // 初始化速率记录结构
            if (!isset($_SESSION[$rateKey])) {
                $_SESSION[$rateKey] = [
                    'count' => 0,
                    'first_time' => time(),
                    'last_time' => 0
                ];
            }
            $rate = &$_SESSION[$rateKey];
            $now = time();
            
            // 如果超过1小时，重置计数
            if ($now - $rate['first_time'] > 3600) {
                $rate = ['count' => 0, 'first_time' => $now, 'last_time' => 0];
            }
            
            // 检查频率：60秒内最多1次，1小时内最多5次
            if ($rate['last_time'] > 0 && ($now - $rate['last_time']) < 60) {
                $remaining = 60 - ($now - $rate['last_time']);
                throw new Exception("发送过于频繁，请等待 {$remaining} 秒后再试");
            }
            if ($rate['count'] >= 5) {
                throw new Exception('每小时最多发送5次验证码，请稍后再试');
            }
            
            // 生成验证码
            $code = generateEmailVerificationCode();
            // 存储验证码（session，5分钟过期）
            storeEmailVerificationCode($email, $code, 300);
            
            // 发送邮件
            $result = sendVerificationEmail($email, $code);
            if ($result['success']) {
                // 更新速率计数
                $rate['count']++;
                $rate['last_time'] = $now;
                $response['success'] = true;
                $response['message'] = '验证码已发送至您的邮箱，请查收';
            } else {
                throw new Exception($result['message']);
            }
            break;
            
        case 'register':
            // 检查注册是否开启
            if (!isRegistrationEnabled()) {
                throw new Exception('站点目前已暂停新用户注册，如有疑问请联系管理员。');
            }
            
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $captchaCode = trim($_POST['captcha_code'] ?? '');
            $emailCode = trim($_POST['email_code'] ?? '');
            
            // 验证输入
            if (empty($username) || empty($email) || empty($password)) {
                throw new Exception('请填写所有必填项！');
            }
            
            // 验证码校验（如果后台开启了验证码）
            if (getSetting('captcha_enabled', '0') === '1') {
                // 启动 Session 以获取存储的验证码
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    session_start();
                }
                if (empty($_SESSION['captcha'])) {
                    throw new Exception('验证码已过期，请刷新重试');
                }
                if (strtolower($captchaCode) !== strtolower($_SESSION['captcha'])) {
                    throw new Exception('验证码错误，请重新输入');
                }
                // 验证通过后清除验证码，防止重复使用
                unset($_SESSION['captcha']);
            }
            
            // 邮箱验证码校验（如果后台开启了邮箱验证）
            if (isEmailVerificationEnabled()) {
                if (empty($emailCode)) {
                    throw new Exception('请输入邮箱验证码');
                }
                if (!verifyEmailVerificationCode($email, $emailCode)) {
                    throw new Exception('邮箱验证码错误或已过期');
                }
            }
            
            // 验证用户名格式
            if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
                throw new Exception('用户名只能包含字母、数字、下划线和中文！');
            }
            
            if (mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 16) {
                throw new Exception('用户名长度必须在2-16个字符之间！');
            }
            
            // 验证邮箱格式
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('邮箱格式不正确！');
            }
            
            // 验证密码长度
            if (strlen($password) < 6) {
                throw new Exception('密码长度至少为6位！');
            }
            
            // 检查用户名和邮箱是否已存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('用户名或邮箱已被注册！');
            }
            
            // 插入新用户（is_banned 默认为 0，is_founder 默认为 0）
            $hashedPassword = hashPassword($password);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            
            if ($stmt->execute([$username, $email, $hashedPassword])) {
                $userId = $pdo->lastInsertId();
                
                // 注册成功，强制启动 Session
                start_session_force();
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;
                $_SESSION['is_admin'] = 0;
                $_SESSION['is_founder'] = 0;
                $_SESSION['avatar'] = null;
                $_SESSION['avatar_text'] = null;
                $_SESSION['profile_background'] = null;
                $_SESSION['is_banned'] = 0;
                
                // 记录在线用户
                trackOnlineUser();
                
                // 如果开启了自动关注站长，则执行关注
                if (isAutoFollowEnabled()) {
                    followUserToFounder($userId);
                }
                
                $response['success'] = true;
                $response['message'] = '注册成功！已自动登录。';
                $response['redirect'] = 'index.php';
            } else {
                throw new Exception('注册失败，请稍后重试！');
            }
            break;
            
        case 'logout':
            // 修复退出登录：强制启动 Session 以确保能销毁
            start_session_force();
            if (session_status() === PHP_SESSION_ACTIVE) {
                trackOnlineUser(); // 更新在线状态（移除当前用户）
                session_destroy();
                setcookie(session_name(), '', time() - 3600, '/');
            }
            $response['success'] = true;
            $response['message'] = '退出登录成功！';
            $response['redirect'] = 'index.php';
            break;

        // ========== 忘记密码重置 ==========
        case 'reset_password_request':
            // 发送密码重置验证码
            $email = trim($_POST['email'] ?? '');
            if (empty($email)) {
                throw new Exception('请输入注册邮箱');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('邮箱格式不正确');
            }
            // 检查邮箱是否存在
            $stmt = $pdo->prepare("SELECT id, username, is_banned FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user) {
                throw new Exception('该邮箱尚未注册');
            }
            if ($user['is_banned']) {
                throw new Exception('该账号已被封禁，无法重置密码');
            }
            // 生成验证码并存储到 session，5分钟有效
            $code = generateEmailVerificationCode();
            storeResetPasswordCode($email, $code, 300);
            
            // 使用赛博科技模板构建邮件正文
            $body = buildCyberCodeEmail(
                $code,
                $user['username'], // 使用用户名作为问候语
                '密码重置验证',
                '您正在请求重置主播模拟器论坛账户的密码。为保护您的账户安全，请使用以下验证码完成验证。'
            );
            
            // 发送邮件
            $subject = '【主播模拟器论坛】密码重置验证码';
            $result = sendEmail($email, $subject, $body);
            if ($result['success']) {
                $response['success'] = true;
                $response['message'] = '验证码已发送至您的邮箱，请查收';
            } else {
                throw new Exception($result['message']);
            }
            break;

        case 'reset_password_reset':
            // 验证并重置密码
            if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('CSRF令牌验证失败');
            }
            $email = trim($_POST['email'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';
            if (empty($email) || empty($code) || empty($newPassword)) {
                throw new Exception('请填写所有字段');
            }
            if (strlen($newPassword) < 6) {
                throw new Exception('新密码长度至少为6位');
            }
            if (!verifyResetPasswordCode($email, $code)) {
                throw new Exception('验证码错误或已过期');
            }
            // 更新密码
            $hashed = hashPassword($newPassword);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
            if ($stmt->execute([$hashed, $email])) {
                clearResetPasswordCode($email);
                $response['success'] = true;
                $response['message'] = '密码重置成功，请使用新密码登录';
            } else {
                throw new Exception('密码重置失败，请稍后重试');
            }
            break;

        // ========== 登录后修改密码（通过邮箱验证码） ==========
        case 'send_change_password_code':
            if (!isLoggedIn()) {
                throw new Exception('请先登录！');
            }
            $userId = $_SESSION['user_id'];
            $email = $_SESSION['email'];
            $username = $_SESSION['username'];
            // 生成验证码并存储到 session
            $code = generateEmailVerificationCode();
            storeChangePasswordCode($email, $code, 300);
            
            // 使用赛博科技模板构建邮件正文
            $body = buildCyberCodeEmail(
                $code,
                $username,
                '修改密码验证',
                '您正在请求修改主播模拟器论坛账户的密码。为保护您的账户安全，请使用以下验证码完成验证。'
            );
            
            // 发送邮件
            $subject = '【主播模拟器论坛】修改密码验证码';
            $result = sendEmail($email, $subject, $body);
            if ($result['success']) {
                $response['success'] = true;
                $response['message'] = '验证码已发送至您的注册邮箱';
            } else {
                throw new Exception($result['message']);
            }
            break;

        case 'change_password':
            if (!isLoggedIn()) {
                throw new Exception('请先登录！');
            }
            if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('CSRF令牌验证失败');
            }
            $email = $_SESSION['email'];
            $code = trim($_POST['code'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';
            if (empty($code) || empty($newPassword)) {
                throw new Exception('请填写所有字段');
            }
            if (strlen($newPassword) < 6) {
                throw new Exception('新密码长度至少为6位');
            }
            if (!verifyChangePasswordCode($email, $code)) {
                throw new Exception('验证码错误或已过期');
            }
            // 更新密码
            $hashed = hashPassword($newPassword);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashed, $_SESSION['user_id']])) {
                clearChangePasswordCode($email);
                $response['success'] = true;
                $response['message'] = '密码修改成功';
            } else {
                throw new Exception('密码修改失败，请稍后重试');
            }
            break;
        // ====================================
            
        case 'signin':
            if (!isLoggedIn()) {
                throw new Exception('请先登录后再签到！');
            }
            
            $userId = $_SESSION['user_id'];
            $signinResult = userSignIn($userId);
            
            $response['success'] = $signinResult['success'];
            $response['message'] = $signinResult['message'];
            
            if ($signinResult['success']) {
                $response['points'] = $signinResult['points'];
                $response['continuous_days'] = $signinResult['continuous_days'];
                $response['base_points'] = $signinResult['base_points'];
                $response['bonus_points'] = $signinResult['bonus_points'];
            }
            break;
            
        case 'get_online_stats':
            $onlineStats = getOnlineUsersCount();
            $response['success'] = true;
            $response['total'] = $onlineStats['total'];
            $response['members'] = $onlineStats['members'];
            $response['guests'] = $onlineStats['guests'];
            $response['message'] = '在线人数统计获取成功';
            break;
            
        case 'get_user_points':
            if (!isLoggedIn()) {
                throw new Exception('请先登录！');
            }
            
            $userId = $_SESSION['user_id'];
            $points = getUserPoints($userId);
            
            $response['success'] = true;
            $response['points'] = $points;
            $response['message'] = '积分获取成功';
            break;

        case 'update_username':
            if (!isLoggedIn()) {
                throw new Exception('请先登录！');
            }

            $newUsername = trim($_POST['new_username'] ?? '');
            $userId = $_SESSION['user_id'];
            $oldUsername = $_SESSION['username'];
            $isAdmin = ($_SESSION['is_admin'] ?? 0) == 1;
            $isFounder = ($_SESSION['is_founder'] ?? 0) == 1;
            $canBypassLimit = ($isAdmin || $isFounder);

            if (empty($newUsername)) {
                throw new Exception('用户名不能为空');
            }

            if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $newUsername)) {
                throw new Exception('用户名只能包含字母、数字、下划线和中文！');
            }

            // 用户名长度限制：2-16个字符（中英文均按1字计算）
            if (mb_strlen($newUsername, 'UTF-8') < 2 || mb_strlen($newUsername, 'UTF-8') > 16) {
                throw new Exception('用户名长度必须在2-16个字符之间！');
            }

            // 普通用户每月只能修改一次用户名（30天冷却期）
            if (!$canBypassLimit) {
                // 从数据库实时查询，确保数据最新（避免session过期问题）
                $stmt = $pdo->prepare("SELECT last_username_change FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if ($row && $row['last_username_change']) {
                    $lastChange = strtotime($row['last_username_change']);
                    $thirtyDaysAgo = strtotime('-30 days');
                    if ($lastChange > $thirtyDaysAgo) {
                        $nextAvailable = date('Y-m-d', $lastChange + 30 * 86400);
                        throw new Exception('您在上次修改后的30天内无法再次修改用户名，下次可修改日期：' . $nextAvailable);
                    }
                }
            }

            // 检查新用户名是否已被其他用户使用
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$newUsername, $userId]);
            if ($stmt->fetch()) {
                throw new Exception('该用户名已被其他用户使用');
            }

            // 审核检查：如果开启了用户名审核，先创建审核记录，不立即修改
            if (getSetting('audit_enabled', '0') === '1') {
                $auditResult = checkContentAudit($userId, 'username', $userId, $newUsername, $oldUsername);
                if ($auditResult['status'] === 'approved') {
                    // 自动审核通过，直接修改
                    goto perform_username_update;
                } elseif ($auditResult['status'] === 'rejected') {
                    throw new Exception('新用户名包含违规词汇，请重新输入');
                } else {
                    // pending - 已创建审核记录，等待管理员审核
                    // 不实际修改用户名
                    $response['success'] = true;
                    $response['message'] = '用户名修改已提交审核，请等待管理员审核通过';
                    break;
                }
            }
            
            perform_username_update:
            // 更新数据库（同时记录修改时间）
            $stmt = $pdo->prepare("UPDATE users SET username = ?, last_username_change = NOW() WHERE id = ?");
            if ($stmt->execute([$newUsername, $userId])) {
                // 同步聊天室历史消息中的用户名
                syncChatUsername($oldUsername, $newUsername);

                // 更新 session
                $_SESSION['username'] = $newUsername;
                $_SESSION['last_username_change'] = date('Y-m-d H:i:s');

                $response['success'] = true;
                $response['message'] = '用户名修改成功';
                $response['new_username'] = $newUsername;
            } else {
                throw new Exception('用户名修改失败，请稍后重试');
            }
            break;

        // ========== 更新文字头像（修正：设置文字头像时清空 avatar 字段，支持自定义背景色） ==========
        case 'update_avatar_text':
            if (!isLoggedIn()) {
                throw new Exception('请先登录！');
            }
            
            $avatarText = trim($_POST['avatar_text'] ?? '');
            $bgColor = trim($_POST['bg_color'] ?? '');
            $userId = $_SESSION['user_id'];
            
            // 验证背景色格式（允许 #rrggbb 格式或空）
            if (!empty($bgColor) && !preg_match('/^#[0-9a-fA-F]{6}$/', $bgColor)) {
                $bgColor = '';
            }
            if (empty($bgColor)) $bgColor = null;
            
            if (empty($avatarText)) {
                $stmt = $pdo->prepare("UPDATE users SET avatar_text = NULL, avatar_bg_color = NULL WHERE id = ?");
                $stmt->execute([$userId]);
                $_SESSION['avatar_text'] = null;
                unset($_SESSION['avatar_bg_color']);
                $response['success'] = true;
                $response['message'] = '文字头像已清除';
            } else {
                $avatarText = mb_substr($avatarText, 0, 2, 'UTF-8');
                // 文字头像直接保存，不经过审核
                $stmt = $pdo->prepare("UPDATE users SET avatar_text = ?, avatar = NULL, avatar_bg_color = ? WHERE id = ?");
                $stmt->execute([$avatarText, $bgColor, $userId]);
                $_SESSION['avatar_text'] = $avatarText;
                $_SESSION['avatar'] = null;
                $_SESSION['avatar_bg_color'] = $bgColor;
                $response['success'] = true;
                $response['message'] = '文字头像设置成功';
                $response['avatar_text'] = $avatarText;
                $response['bg_color'] = $bgColor;
            }
            break;

        case 'upload_avatar':
            if (!isLoggedIn()) {
                throw new Exception('请先登录！');
            }

            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('文件上传失败，错误码：' . ($_FILES['avatar']['error'] ?? '未知'));
            }

            $file = $_FILES['avatar'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($file['type'], $allowedTypes)) {
                throw new Exception('只允许上传 JPEG、PNG、GIF 图片');
            }

            if ($file['size'] > 2 * 1024 * 1024) {
                throw new Exception('图片大小不能超过 2MB');
            }

            $userId = $_SESSION['user_id'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
            $uploadDir = __DIR__ . '/uploads/avatars/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $targetPath = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new Exception('保存文件失败');
            }

            $avatarUrl = '/uploads/avatars/' . $filename;
            
            // 审核检查：上传头像图片需要审核
            $isAdmin = !empty($_SESSION['is_founder']) || !empty($_SESSION['is_admin']);
            if (getSetting('audit_enabled', '0') === '1' && !$isAdmin) {
                // 文件已保存，设置 pending 标记，创建审核条目
                $pdo->prepare("UPDATE users SET avatar_pending = 1 WHERE id = ?")->execute([$userId]);
                $_SESSION['avatar_pending'] = true;
                $stmt = $pdo->prepare("INSERT INTO audit_items (user_id, content_type, content_id, content_data, status) VALUES (?, 'avatar_image', ?, ?, 'pending')");
                $stmt->execute([$userId, $userId, $avatarUrl]);
                $response['success'] = true;
                $response['message'] = '头像已提交审核，请等待管理员审核通过后生效';
                break;
            }
            
            // 上传图片时清空 avatar_text 字段，并删除旧头像文件
            $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $oldAvatar = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("UPDATE users SET avatar = ?, avatar_text = NULL, avatar_bg_color = NULL WHERE id = ?");
            if ($stmt->execute([$avatarUrl, $userId])) {
                $_SESSION['avatar'] = $avatarUrl;
                $_SESSION['avatar_text'] = null;
                unset($_SESSION['avatar_pending']);
                
                // 删除旧头像文件
                if ($oldAvatar && $oldAvatar !== $avatarUrl) {
                    deleteFileIfExists($oldAvatar);
                }
                
                // 同步聊天室历史消息中的头像
                syncChatAvatar($_SESSION['username'], $avatarUrl);
                $response['success'] = true;
                $response['message'] = '头像上传成功';
                $response['avatar_url'] = $avatarUrl;
            } else {
                unlink($targetPath);
                throw new Exception('数据库更新失败');
            }
            break;

        case 'upload_avatar_cropped':
            if (!isLoggedIn()) {
                throw new Exception('请先登录！');
            }

            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('文件上传失败，错误码：' . ($_FILES['avatar']['error'] ?? '未知'));
            }

            $file = $_FILES['avatar'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($file['type'], $allowedTypes)) {
                throw new Exception('只允许上传 JPEG、PNG、GIF 图片');
            }

            if ($file['size'] > 2 * 1024 * 1024) {
                throw new Exception('图片大小不能超过 2MB');
            }

            $userId = $_SESSION['user_id'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
            $uploadDir = __DIR__ . '/uploads/avatars/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $targetPath = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new Exception('保存文件失败');
            }

            $avatarUrl = '/uploads/avatars/' . $filename;

            // 审核检查：上传头像图片需要审核
            $isAdmin = !empty($_SESSION['is_founder']) || !empty($_SESSION['is_admin']);
            if (getSetting('audit_enabled', '0') === '1' && !$isAdmin) {
                $pdo->prepare("UPDATE users SET avatar_pending = 1 WHERE id = ?")->execute([$userId]);
                $_SESSION['avatar_pending'] = true;
                $stmt = $pdo->prepare("INSERT INTO audit_items (user_id, content_type, content_id, content_data, status) VALUES (?, 'avatar_image', ?, ?, 'pending')");
                $stmt->execute([$userId, $userId, $avatarUrl]);
                $response['success'] = true;
                $response['message'] = '头像已提交审核，请等待管理员审核通过后生效';
                break;
            }

            // 获取旧头像路径
            $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $oldAvatar = $stmt->fetchColumn();

            // 上传图片时清空 avatar_text 字段
            $stmt = $pdo->prepare("UPDATE users SET avatar = ?, avatar_text = NULL, avatar_bg_color = NULL WHERE id = ?");
            if ($stmt->execute([$avatarUrl, $userId])) {
                $_SESSION['avatar'] = $avatarUrl;
                $_SESSION['avatar_text'] = null;
                unset($_SESSION['avatar_pending']);
                
                // 删除旧头像文件
                if ($oldAvatar && $oldAvatar !== $avatarUrl) {
                    deleteFileIfExists($oldAvatar);
                }
                
                // 同步聊天室历史消息中的头像
                syncChatAvatar($_SESSION['username'], $avatarUrl);
                $response['success'] = true;
                $response['message'] = '头像上传成功';
                $response['avatar_url'] = $avatarUrl;
            } else {
                unlink($targetPath);
                throw new Exception('数据库更新失败');
            }
            break;

        case 'upload_background':
            if (!isLoggedIn()) {
                throw new Exception('请先登录！');
            }

            if (!isset($_FILES['background']) || $_FILES['background']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('文件上传失败，错误码：' . ($_FILES['background']['error'] ?? '未知'));
            }

            $file = $_FILES['background'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($file['type'], $allowedTypes)) {
                throw new Exception('只允许上传 JPEG、PNG、GIF 图片');
            }

            if ($file['size'] > 5 * 1024 * 1024) {
                throw new Exception('图片大小不能超过 5MB');
            }

            $userId = $_SESSION['user_id'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'bg_' . $userId . '_' . time() . '.' . $ext;
            $uploadDir = __DIR__ . '/uploads/backgrounds/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $targetPath = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new Exception('保存文件失败');
            }

            $backgroundUrl = '/uploads/backgrounds/' . $filename;

            // 获取旧背景图路径
            $stmt = $pdo->prepare("SELECT profile_background FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $oldBg = $stmt->fetchColumn();

            // 审核检查：上传背景图需要审核
            $isAdmin = !empty($_SESSION['is_founder']) || !empty($_SESSION['is_admin']);
            if (getSetting('audit_enabled', '0') === '1' && !$isAdmin) {
                $pdo->prepare("UPDATE users SET background_pending = 1 WHERE id = ?")->execute([$userId]);
                $_SESSION['background_pending'] = true;
                $stmt = $pdo->prepare("INSERT INTO audit_items (user_id, content_type, content_id, content_data, status) VALUES (?, 'background_image', ?, ?, 'pending')");
                $stmt->execute([$userId, $userId, $backgroundUrl]);
                $response['success'] = true;
                $response['message'] = '背景图已提交审核，请等待管理员审核通过后生效';
                $response['pending'] = true;
                break;
            }

            $stmt = $pdo->prepare("UPDATE users SET profile_background = ?, background_pending = 0 WHERE id = ?");
            if ($stmt->execute([$backgroundUrl, $userId])) {
                $_SESSION['profile_background'] = $backgroundUrl;
                $_SESSION['background_pending'] = false;
                
                // 删除旧背景图文件
                if ($oldBg && $oldBg !== $backgroundUrl) {
                    deleteFileIfExists($oldBg);
                }
                
                $response['success'] = true;
                $response['message'] = '背景图上传成功';
                $response['background_url'] = $backgroundUrl;
            } else {
                unlink($targetPath);
                throw new Exception('数据库更新失败');
            }
            break;

        case 'reset_background':
            if (!isLoggedIn()) {
                throw new Exception('请先登录！');
            }
            $userId = $_SESSION['user_id'];
            // 获取旧背景图路径
            $stmt = $pdo->prepare("SELECT profile_background FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $oldBg = $stmt->fetchColumn();
            // 重置为默认
            $pdo->prepare("UPDATE users SET profile_background = NULL, background_pending = 0 WHERE id = ?")->execute([$userId]);
            $_SESSION['profile_background'] = null;
            $_SESSION['background_pending'] = false;
            // 删除旧文件
            if ($oldBg) {
                deleteFileIfExists($oldBg);
            }
            $response['success'] = true;
            $response['message'] = '已恢复默认背景图';
            break;

        case 'update_theme':
            if (!isLoggedIn()) {
                throw new Exception('请先登录');
            }
            $theme = $_POST['theme'] ?? '';
            $theme_settings = isset($_POST['theme_settings']) ? json_decode($_POST['theme_settings'], true) : null;

            if (empty($theme)) {
                throw new Exception('主题参数错误');
            }

            $userId = $_SESSION['user_id'];

            if ($theme === 'custom' && $theme_settings) {
                if (setUserThemeSettings($userId, $theme_settings)) {
                    setUserTheme($userId, 'custom');
                    $response['success'] = true;
                    $response['message'] = '自定义主题保存成功';
                } else {
                    throw new Exception('保存失败');
                }
            } else {
                if (setUserTheme($userId, $theme)) {
                    $response['success'] = true;
                    $response['message'] = '主题切换成功';
                } else {
                    throw new Exception('保存失败');
                }
            }
            break;

        case 'delete_account':
            if (!isLoggedIn()) {
                throw new Exception('请先登录！');
            }

            // CSRF 验证
            if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('CSRF令牌验证失败');
            }

            $userId = $_SESSION['user_id'];
            $password = $_POST['password'] ?? '';

            if (empty($password)) {
                throw new Exception('请输入密码');
            }

            // 验证密码
            if (!verifyUserPassword($userId, $password)) {
                throw new Exception('密码错误');
            }

            // 站长不能注销（通过后台管理删除站长是不允许的，但站长自己也不应注销）
            if (isFounder($userId)) {
                throw new Exception('站长账号不能注销');
            }

            // 执行注销（forceDeleteUser 会删除头像和背景图文件）
            if (forceDeleteUser($userId)) {
                // 销毁 session
                start_session_force();
                session_destroy();
                setcookie(session_name(), '', time() - 3600, '/');

                $response['success'] = true;
                $response['message'] = '账号已成功注销';
            } else {
                throw new Exception('注销失败，请稍后重试');
            }
            break;
            
        default:
            throw new Exception('无效的操作！');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit();
?>