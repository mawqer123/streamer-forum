<?php
// admin.php - 综合后台管理页面（合并用户管理和首页内容管理）
require_once __DIR__ . '/functions.php';

// 检查用户是否登录且是否是管理员
if (!isLoggedIn()) {
    redirect(url('index'));
}
if (!isAdmin()) {
    die('权限不足！只有管理员可以访问此页面。');
}

// 获取当前用户信息（用于主题）
$currentUser = getCurrentUser();

// ---------- 定义首页内容管理所需常量 ----------
define('UPLOAD_DIR_SLIDES', 'uploads/slides/');
$allowedImageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// 确保上传目录存在
if (!file_exists(UPLOAD_DIR_SLIDES)) {
    mkdir(UPLOAD_DIR_SLIDES, 0755, true);
}

// ---------- 文件上传处理函数 ----------
if (!function_exists('handleImageUpload')) {
    function handleImageUpload($fileInputName) {
        global $allowedImageTypes;
        
        if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => '没有文件上传或上传出错'];
        }
        
        $file = $_FILES[$fileInputName];
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxFileSize) {
            return ['success' => false, 'message' => '文件大小不能超过5MB'];
        }
        
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, $allowedImageTypes)) {
            return ['success' => false, 'message' => '只允许上传 JPG、PNG、GIF、WebP 格式的图片'];
        }
        
        $fileName = uniqid() . '_' . time() . '.' . $fileExt;
        $filePath = UPLOAD_DIR_SLIDES . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            return [
                'success' => true,
                'file_url' => $filePath,
                'file_name' => $fileName
            ];
        } else {
            return ['success' => false, 'message' => '文件保存失败'];
        }
    }
}

// ---------- 获取当前选项卡 ----------
$tab = $_GET['tab'] ?? 'users'; // users / settings / home / levels / maintenance / github / gitee

// ---------- 处理 POST 请求 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('CSRF令牌验证失败！');
    }

    // 判断是否为 AJAX 请求
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // ----- 处理注册开关 -----
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_registration') {
        $enable = ($_POST['enable'] ?? '') === '1' ? 1 : 0;
        setRegistrationEnabled($enable);
        $message = $enable ? '新用户注册已开启' : '新用户注册已关闭';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $message, 'new_state' => $enable]);
            exit;
        }
        $successMessage = $message;
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    // ----- 处理自动关注站长开关 -----
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_auto_follow') {
        $enable = ($_POST['enable'] ?? '') === '1' ? 1 : 0;
        $previous = getSetting('auto_follow_founder', '0');
        
        setSetting('auto_follow_founder', $enable ? '1' : '0');
        
        $message = '';
        $success = true;
        if ($enable && $previous !== '1') {
            $result = followAllUsersToFounder();
            if ($result['success']) {
                $message = '自动关注已开启，并已为所有用户添加关注站长关系。' . ($result['message'] ?: '');
            } else {
                $success = false;
                $message = '自动关注已开启，但批量关注失败：' . $result['message'];
            }
        } else {
            $message = $enable ? '自动关注已开启（新用户注册时将自动关注站长）' : '自动关注已关闭';
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $success, 'message' => $message, 'new_state' => $enable]);
            exit;
        }
        if ($success) {
            $successMessage = $message;
        } else {
            $errorMessage = $message;
        }
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    // ----- 伪静态开关 -----
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_pretty_url') {
        $enable = ($_POST['enable'] ?? '') === '1' ? 1 : 0;
        setPrettyUrlEnabled($enable);
        $message = $enable ? '伪静态已开启' : '伪静态已关闭';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $message, 'new_state' => $enable]);
            exit;
        }
        $successMessage = $message;
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    // ----- 所有用户关注站长（保留手动按钮） -----
    if (isset($_POST['action']) && $_POST['action'] === 'follow_station_master') {
        $result = followAllUsersToFounder();
        if ($result['success']) {
            $successMessage = $result['message'];
        } else {
            $errorMessage = $result['message'];
        }
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    // ----- 处理验证码开关 -----
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_captcha') {
        $enable = ($_POST['enable'] ?? '') === '1' ? 1 : 0;
        setSetting('captcha_enabled', $enable ? '1' : '0');
        $message = $enable ? '注册验证码已开启（新用户注册需要输入验证码）' : '注册验证码已关闭';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $message, 'new_state' => $enable]);
            exit;
        }
        $successMessage = $message;
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    // ----- 处理邮箱验证开关及SMTP配置 -----
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_email_verification') {
        $enable = ($_POST['enable'] ?? '') === '1' ? 1 : 0;
        setEmailVerificationEnabled($enable);
        $message = $enable ? '邮箱验证已开启（新用户注册需要验证邮箱）' : '邮箱验证已关闭';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $message, 'new_state' => $enable]);
            exit;
        }
        $successMessage = $message;
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'toggle_github_oauth') {
        $enable = ($_POST['enable'] ?? '') === '1' ? 1 : 0;
        setSetting('github_oauth_enabled', $enable ? '1' : '0');
        $message = $enable ? 'GitHub 登录已开启' : 'GitHub 登录已关闭';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $message, 'new_state' => $enable]);
            exit;
        }
        $successMessage = $message;
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_github_oauth') {
        setSetting('github_client_id', trim($_POST['github_client_id'] ?? ''));
        setSetting('github_client_secret', trim($_POST['github_client_secret'] ?? ''));
        // 代理使用服务器 nginx 硬编码，无需配置
        $successMessage = 'GitHub OAuth 配置已保存';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $successMessage]);
            exit;
        }
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'toggle_gitee_oauth') {
        $enable = ($_POST['enable'] ?? '') === '1' ? 1 : 0;
        setSetting('gitee_oauth_enabled', $enable ? '1' : '0');
        $message = $enable ? 'Gitee 登录已开启' : 'Gitee 登录已关闭';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $message, 'new_state' => $enable]);
            exit;
        }
        $successMessage = $message;
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_gitee_oauth') {
        setSetting('gitee_client_id', trim($_POST['gitee_client_id'] ?? ''));
        setSetting('gitee_client_secret', trim($_POST['gitee_client_secret'] ?? ''));
        $successMessage = 'Gitee OAuth 配置已保存';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $successMessage]);
            exit;
        }
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'toggle_maintenance') {
        $enable = ($_POST['enable'] ?? '') === '1' ? 1 : 0;
        if ($enable) {
            setSetting('maintenance_title', '维护中');
            setSetting('maintenance_message', '论坛正在进行系统维护，请稍后再来。');
        }
        setMaintenanceMode($enable);
        $message = $enable ? '维护模式已开启' : '维护模式已关闭';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $message, 'new_state' => $enable]);
            exit;
        }
        $successMessage = $message;
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_maintenance_settings') {
        setSetting('maintenance_title', trim($_POST['maintenance_title'] ?? ''));
        setSetting('maintenance_message', trim($_POST['maintenance_message'] ?? ''));
        $successMessage = '维护页面内容已保存';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $successMessage]);
            exit;
        }
        redirect(url('admin', [], ['tab' => 'settings']));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_smtp_config') {
        $config = [
            'host' => trim($_POST['smtp_host'] ?? ''),
            'port' => intval($_POST['smtp_port'] ?? 587),
            'encryption' => trim($_POST['smtp_encryption'] ?? 'tls'),
            'username' => trim($_POST['smtp_username'] ?? ''),
            'password' => trim($_POST['smtp_password'] ?? ''),
            'from_email' => trim($_POST['smtp_from_email'] ?? ''),
            'from_name' => trim($_POST['smtp_from_name'] ?? '主播模拟器论坛')
        ];
        if (saveSmtpConfig($config)) {
            $successMessage = 'SMTP配置已保存';
        } else {
            $errorMessage = 'SMTP配置保存失败';
        }
        redirect(url('admin', [], ['tab' => 'email']));
    }

    // ----- 首页内容管理相关操作 -----
    if (isset($_POST['form_action'])) {
        $formAction = $_POST['form_action'];
        $formType = $_POST['form_type'] ?? 'slide';

        switch ($formAction) {
            case 'save_slide':
                // 保留链接设置，标题和描述不再显示和保存
                $data = [
                    'title' => '',
                    'description' => '',
                    'link_url' => trim($_POST['link_url'] ?? ''),
                    'link_text' => trim($_POST['link_text'] ?? ''),
                    'link_target' => isset($_POST['link_target']) ? 1 : 0,
                    'sort_order' => intval($_POST['sort_order'] ?? 0),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0
                ];
                
                $imageUrl = trim($_POST['image_url'] ?? '');
                $oldImageUrl = null;
                
                // 如果是编辑，先获取旧图片URL以便后续删除
                if (isset($_POST['id']) && !empty($_POST['id'])) {
                    $id = intval($_POST['id']);
                    $oldSlide = getSlideById($id);
                    if ($oldSlide) {
                        $oldImageUrl = $oldSlide['image_url'];
                    }
                }
                
                if (isset($_FILES['slide_image']) && $_FILES['slide_image']['error'] === UPLOAD_ERR_OK) {
                    $uploadResult = handleImageUpload('slide_image');
                    if ($uploadResult['success']) {
                        $data['image_url'] = $uploadResult['file_url'];
                        // 上传新图片成功，删除旧图片
                        if ($oldImageUrl && file_exists($oldImageUrl)) {
                            @unlink($oldImageUrl);
                        }
                    } else {
                        $errorMessage = $uploadResult['message'];
                        break;
                    }
                } elseif (!empty($imageUrl)) {
                    $data['image_url'] = $imageUrl;
                    // 如果使用了新的URL（非上传），且旧图片是本地文件，删除旧图片
                    if ($oldImageUrl && strpos($oldImageUrl, UPLOAD_DIR_SLIDES) === 0 && file_exists($oldImageUrl) && $oldImageUrl !== $imageUrl) {
                        @unlink($oldImageUrl);
                    }
                } else {
                    $errorMessage = '请上传图片或输入图片URL！';
                    break;
                }
                
                if (isset($_POST['id']) && !empty($_POST['id'])) {
                    $id = intval($_POST['id']);
                    if (updateSlide($id, $data)) {
                        $successMessage = '幻灯片更新成功！';
                    } else {
                        $errorMessage = '幻灯片更新失败！';
                    }
                } else {
                    $id = addSlide($data);
                    if ($id) {
                        $successMessage = '幻灯片添加成功！';
                    } else {
                        $errorMessage = '幻灯片添加失败！';
                    }
                }
                break;

            case 'save_link':
                $data = [
                    'title' => trim($_POST['title'] ?? ''),
                    'link_url' => trim($_POST['link_url'] ?? ''),
                    'link_target' => isset($_POST['link_target']) ? 1 : 0,
                    'sort_order' => intval($_POST['sort_order'] ?? 0),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0
                ];
                
                if (empty($data['title']) || empty($data['link_url'])) {
                    $errorMessage = '标题和链接URL不能为空！';
                } else {
                    if (isset($_POST['id']) && !empty($_POST['id'])) {
                        $id = intval($_POST['id']);
                        if (updateLink($id, $data)) {
                            $successMessage = '链接更新成功！';
                        } else {
                            $errorMessage = '链接更新失败！';
                        }
                    } else {
                        $id = addLink($data);
                        if ($id) {
                            $successMessage = '链接添加成功！';
                        } else {
                            $errorMessage = '链接添加失败！';
                        }
                    }
                }
                break;

            case 'delete_slide':
                $id = intval($_POST['id'] ?? 0);
                if ($id > 0) {
                    $slide = getSlideById($id);
                    if ($slide && strpos($slide['image_url'], UPLOAD_DIR_SLIDES) === 0) {
                        $filePath = $slide['image_url'];
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                    if (deleteSlide($id)) {
                        $successMessage = '幻灯片删除成功！';
                    } else {
                        $errorMessage = '幻灯片删除失败！';
                    }
                } else {
                    $errorMessage = '无效的幻灯片ID！';
                }
                break;

            case 'delete_link':
                $id = intval($_POST['id'] ?? 0);
                if ($id > 0 && deleteLink($id)) {
                    $successMessage = '链接删除成功！';
                } else {
                    $errorMessage = '链接删除失败！';
                }
                break;
        }

        if (isset($successMessage) && !isset($_GET['no_redirect'])) {
            $redirectParams = ['tab' => 'home', 'type' => $_GET['type'] ?? 'slide'];
            redirect(url('admin', [], $redirectParams));
        }
    }

    // ----- 用户管理相关操作 -----
    // 单个删除（通过外部隐藏表单提交）
    if (isset($_POST['action']) && $_POST['action'] === 'delete_user' && isset($_POST['user_id'])) {
        $userId = intval($_POST['user_id']);
        $currentUserId = $_SESSION['user_id'];
        if ($userId == $currentUserId) {
            $errorMessage = '不能删除自己！';
        } else {
            $user = getUserById($userId);
            if ($user && ($user['is_founder'] ?? 0)) {
                $errorMessage = '不能删除站长！';
            } else {
                if (forceDeleteUser($userId)) {
                    $successMessage = '用户删除成功！';
                } else {
                    $errorMessage = '删除用户失败，请检查数据库日志。';
                }
            }
        }
        start_session_force();
        $_SESSION['flash_message'] = ['type' => isset($successMessage) ? 'success' : 'error', 'text' => isset($successMessage) ? $successMessage : $errorMessage];
        redirect(url('admin', [], ['tab' => 'users', 'page' => $_GET['page'] ?? 1]));
    }

    // 批量删除（一次性删除多个用户）- 自动跳过站长和当前登录用户
    if (isset($_POST['batch_action']) && $_POST['batch_action'] === 'delete_selected' && isset($_POST['selected_users'])) {
        $deletedCount = 0;
        $skippedCount = 0;
        $currentUserId = $_SESSION['user_id'];
        $selectedIds = array_map('intval', $_POST['selected_users']);
        
        // 过滤掉当前用户和站长
        $toDelete = [];
        foreach ($selectedIds as $userId) {
            if ($userId == $currentUserId) {
                $skippedCount++;
                continue;
            }
            $user = getUserById($userId);
            if ($user && ($user['is_founder'] ?? 0)) {
                $skippedCount++;
                continue;
            }
            $toDelete[] = $userId;
        }
        
        if (!empty($toDelete)) {
            try {
                $pdo = getDbConnection();
                $pdo->beginTransaction();
                
                $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                
                // 批量删除关联数据
                $pdo->prepare("DELETE FROM notifications WHERE user_id IN ($placeholders) OR actor_id IN ($placeholders)")->execute(array_merge($toDelete, $toDelete));
                $pdo->prepare("DELETE FROM favorites WHERE user_id IN ($placeholders)")->execute($toDelete);
                $pdo->prepare("DELETE FROM post_likes WHERE user_id IN ($placeholders)")->execute($toDelete);
                $pdo->prepare("DELETE FROM comment_likes WHERE user_id IN ($placeholders)")->execute($toDelete);
                $pdo->prepare("DELETE FROM follows WHERE follower_id IN ($placeholders) OR following_id IN ($placeholders)")->execute(array_merge($toDelete, $toDelete));
                $pdo->prepare("DELETE FROM daily_signins WHERE user_id IN ($placeholders)")->execute($toDelete);
                $pdo->prepare("DELETE FROM tips WHERE from_user_id IN ($placeholders) OR to_user_id IN ($placeholders)")->execute(array_merge($toDelete, $toDelete));
                $pdo->prepare("DELETE FROM comments WHERE user_id IN ($placeholders)")->execute($toDelete);
                // 删除帖子前收集文件
                $stmt = $pdo->prepare("SELECT id FROM posts WHERE user_id IN ($placeholders)");
                $stmt->execute($toDelete);
                $postIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($postIds as $pid) {
                    $files = getPostAllFiles($pid);
                    foreach ($files as $file) {
                        deleteFileIfExists($file);
                    }
                }
                $pdo->prepare("DELETE FROM posts WHERE user_id IN ($placeholders)")->execute($toDelete);
                // 删除用户头像和背景图
                $stmt = $pdo->prepare("SELECT avatar, profile_background FROM users WHERE id IN ($placeholders)");
                $stmt->execute($toDelete);
                $userFiles = $stmt->fetchAll();
                foreach ($userFiles as $uf) {
                    if (!empty($uf['avatar'])) deleteFileIfExists($uf['avatar']);
                    if (!empty($uf['profile_background'])) deleteFileIfExists($uf['profile_background']);
                }
                $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($toDelete);
                $deletedCount = count($toDelete);
                
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("批量删除用户失败: " . $e->getMessage());
                // 如果批量删除失败，回退到逐个删除
                foreach ($toDelete as $userId) {
                    if (forceDeleteUser($userId)) {
                        $deletedCount++;
                    } else {
                        $skippedCount++;
                    }
                }
            }
        }
        
        start_session_force();
        if ($deletedCount > 0) {
            $message = "成功删除 {$deletedCount} 个用户。";
            if ($skippedCount > 0) {
                $message .= " 跳过 {$skippedCount} 个用户（包含站长或当前登录用户）。";
            }
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => $message];
        } else {
            $message = "没有删除任何用户。";
            if ($skippedCount > 0) {
                $message .= " 跳过了 {$skippedCount} 个用户（可能包含站长或当前登录用户）。";
            }
            $_SESSION['flash_message'] = ['type' => 'error', 'text' => $message];
        }
        redirect(url('admin', [], ['tab' => 'users', 'page' => $_GET['page'] ?? 1]));
    }

    // ----- 设置管理员权限 -----
    if (isset($_POST['action']) && $_POST['action'] === 'set_admin' && isset($_POST['user_id'])) {
        $targetId = intval($_POST['user_id']);
        $setAdmin = isset($_POST['set_admin']) ? intval($_POST['set_admin']) : 0;
        
        if ($targetId == $_SESSION['user_id']) {
            $errorMessage = '不能修改自己的管理员权限！';
        } else {
            if (setUserAdmin($targetId, $setAdmin)) {
                $successMessage = $setAdmin ? '已授予管理员权限' : '已取消管理员权限';
            } else {
                $errorMessage = '操作失败：不能修改站长的权限或用户不存在。';
            }
        }
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            if (isset($successMessage)) {
                echo json_encode(['success' => true, 'message' => $successMessage]);
            } else {
                echo json_encode(['success' => false, 'message' => $errorMessage ?? '操作失败']);
            }
            exit;
        }
        
        start_session_force();
        $_SESSION['flash_message'] = ['type' => isset($successMessage) ? 'success' : 'error', 'text' => isset($successMessage) ? $successMessage : $errorMessage];
        redirect(url('admin', [], ['tab' => 'users', 'page' => $_GET['page'] ?? 1]));
    }

    // ----- 封禁/解封用户 -----
    if (isset($_POST['action']) && $_POST['action'] === 'set_maintenance_bypass' && isset($_POST['user_id'])) {
        $targetId = intval($_POST['user_id']);
        $bypass = $_POST['bypass'] === '1' ? 1 : 0;
        error_log("set_maintenance_bypass: user_id=$targetId bypass=$bypass");
        $pdo = getDbConnection();
        $pdo->prepare("UPDATE users SET maintenance_bypass = ? WHERE id = ?")->execute([$bypass, $targetId]);
        $message = $bypass ? '已为此用户开启维护模式免检' : '已取消此用户的维护模式免检';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $message, 'new_state' => $bypass]);
            exit;
        }
        $successMessage = $message;
        redirect(url('admin', [], ['tab' => 'users'], '#users'));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'set_ban' && isset($_POST['user_id'])) {
        $targetId = intval($_POST['user_id']);
        $ban = isset($_POST['ban']) ? intval($_POST['ban']) : 0;
        
        if ($targetId == $_SESSION['user_id']) {
            $errorMessage = '不能封禁自己！';
        } else {
            if (setUserBan($targetId, $ban)) {
                $successMessage = $ban ? '用户已封禁' : '用户已解封';
            } else {
                $errorMessage = '操作失败：不能封禁站长或用户不存在。';
            }
        }
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            if (isset($successMessage)) {
                echo json_encode(['success' => true, 'message' => $successMessage]);
            } else {
                echo json_encode(['success' => false, 'message' => $errorMessage ?? '操作失败']);
            }
            exit;
        }
        
        start_session_force();
        $_SESSION['flash_message'] = ['type' => isset($successMessage) ? 'success' : 'error', 'text' => isset($successMessage) ? $successMessage : $errorMessage];
        redirect(url('admin', [], ['tab' => 'users', 'page' => $_GET['page'] ?? 1]));
    }
}

// ---------- 根据当前选项卡获取数据 ----------
// 共享变量：所有选项卡都可能用到
$autoFollowEnabled = isAutoFollowEnabled();
$captchaEnabled = getSetting('captcha_enabled', '0') === '1';
$emailVerificationEnabled = isEmailVerificationEnabled();
$smtpConfig = getSmtpConfig();
$prettyUrlEnabled = isPrettyUrlEnabled();
$registrationEnabled = isRegistrationEnabled();
$maintenanceMode = isMaintenanceMode();
$maintenanceTitle = getSetting('maintenance_title', '维护中');
$maintenanceMessage = getSetting('maintenance_message', '论坛正在进行系统维护，请稍后再来。');
$githubOAuthEnabled = getSetting('github_oauth_enabled', '0') === '1';
$githubClientId = getSetting('github_client_id', '');
$githubClientSecret = getSetting('github_client_secret', '');
$githubApiProxy = ''; // 使用 nginx 代理
$giteeOAuthEnabled = getSetting('gitee_oauth_enabled', '0') === '1';
$giteeClientId = getSetting('gitee_client_id', '');
$giteeClientSecret = getSetting('gitee_client_secret', '');

if ($tab === 'users') {
    $search = trim($_GET['search'] ?? '');
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = 20;
    
    if (!empty($search)) {
        $totalUsers = searchUsersCount($search);
        $totalPages = ceil($totalUsers / $perPage);
        $users = searchUsers($search, $page, $perPage);
    } else {
        $totalUsers = getUserCount();
        $totalPages = ceil($totalUsers / $perPage);
        $users = getAllUsers($page, $perPage);
    }

    // 处理 session flash 消息
    start_session_force();
    $flashMessage = null;
    if (isset($_SESSION['flash_message'])) {
        $flashMessage = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
    }
    if ($flashMessage) {
        if ($flashMessage['type'] === 'success') {
            $successMessage = $flashMessage['text'];
        } else {
            $errorMessage = $flashMessage['text'];
        }
    }
} elseif ($tab === 'settings') {
    // 变量已在共享块中初始化
} elseif ($tab === 'maintenance') {
    // 变量已在共享块中初始化
} elseif ($tab === 'github') {
    // 变量已在共享块中初始化
} elseif ($tab === 'gitee') {
    // 变量已在共享块中初始化
} elseif ($tab === "api") {
    $apiBackend = getSetting("api_backend", "php");
    if ($_SERVER["REQUEST_METHOD"] === "POST" && verifyCsrfToken($_POST["csrf_token"] ?? "")) {
        setSetting("api_backend", $_POST["api_backend"] ?? "php");
        $apiBackend = $_POST["api_backend"] ?? "php";
        $successMessage = "API 后端设置已保存";
    }
    $rustStatus = "未启动";
    if ($apiBackend === "rust") {
        $ch = curl_init("http://127.0.0.1:3001/api/health");
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $resp === "OK") {
            $rustStatus = "运行中";
        } else {
            $rustStatus = "连接失败";
        }
    }
} elseif ($tab === 'email') {
    $smtpConfig = getSmtpConfig();
} elseif ($tab === 'home') {
    $type = isset($_GET['type']) ? $_GET['type'] : 'slide';
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $slides = getAllSlides();
    $links = getAllLinks();
    $editItem = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $editItem = ($type === 'slide') ? getSlideById($id) : getLinkById($id);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理 - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
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
        /* ===== 后台管理特有样式 ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { margin: 0 !important; padding: 0 !important; background: var(--bg-secondary); }
        body { margin: 0 !important; padding: 0 !important; background-color: var(--bg-secondary); }
        .main-content { margin: 0 !important; padding: 0 !important; min-height: 100vh; max-width: none !important; width: 100%; }
        .admin-container { max-width: none !important; margin: 0; padding: 0; width: 100%; }
        .admin-header {
            background-color: var(--accent-color);
            color: white;
            padding: 0.75rem 1rem;
            margin: 0 !important;
        }
        .admin-header-row {
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .admin-header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .back-home {
            color: white;
            text-decoration: none;
            font-size: 1.8rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
            margin: 0;
        }
        .back-home:hover { background-color: rgba(255,255,255,0.2); }
        .admin-title { font-size: 1.8rem; color: white; margin: 0; line-height: 1.2; }
        .admin-tabs {
            background-color: var(--bg-primary);
            padding: 0 1rem;
            margin: 0 !important;
        }
        .admin-tabs-container {
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            gap: 2rem;
            overflow-x: auto;
            white-space: nowrap;
            flex-wrap: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }
        .admin-tab {
            padding: 0.8rem 0;
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: color 0.2s, border-color 0.2s;
            flex-shrink: 0;
            white-space: nowrap;
        }
        .admin-tab:hover { color: var(--accent-color); }
        .admin-tab.active { color: var(--accent-color); border-bottom-color: var(--accent-color); }
        .admin-container { max-width: 100%; margin: 0; padding: 0; }
        .stats-card, .settings-card, .content-card {
            padding: 1rem;
            margin-bottom: 0;
        }
        .settings-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        .settings-label { font-weight: 500; color: var(--text-primary); }
        .settings-desc { font-size: 0.85rem; color: var(--text-secondary); }
        /* 自定义复选框 - 替换原有 switch */
        .custom-checkbox {
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            user-select: none;
            font-size: 16px;
            color: var(--text-primary);
            transition: color 0.3s;
        }
        .custom-checkbox input[type="checkbox"] {
            display: none;
        }
        .custom-checkbox .checkmark {
            width: 24px;
            height: 24px;
            border: 2px solid var(--border-color);
            border-radius: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 0;
            transition: background-color 1.3s, border-color 1.3s, transform 0.3s;
            transform-style: preserve-3d;
        }
        .custom-checkbox .checkmark svg {
            width: 16px;
            height: 16px;
            stroke: white;
            stroke-width: 3;
            fill: none;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .custom-checkbox input[type="checkbox"]:checked + .checkmark {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            transform: scale(1.1) rotateZ(360deg) rotateY(360deg);
        }
        .custom-checkbox input[type="checkbox"]:checked + .checkmark svg {
            opacity: 1;
        }
        .custom-checkbox:hover {
            color: var(--accent-color);
        }
        .custom-checkbox:hover .checkmark {
            border-color: var(--accent-color);
            background-color: var(--link-hover-bg);
            transform: scale(1.05);
        }
        .custom-checkbox input[type="checkbox"]:focus + .checkmark {
            box-shadow: 0 0 3px 2px rgba(0, 0, 0, 0.2);
            outline: none;
        }
        .manual-btn {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 0.5rem 1rem;
            border-radius: 0;
            cursor: pointer;
        }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th {
            padding: 1rem;
            text-align: left;
            color: var(--text-secondary);
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }
        td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .btn-delete {
            background-color: #e53e3e;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .batch-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .select-all { margin-right: 1rem; }
        .type-tabs {
            display: flex;
            background: var(--bg-primary);
            border-radius: 0;
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .type-tab {
            flex: 1;
            padding: 1rem;
            text-align: center;
            text-decoration: none;
            color: var(--text-secondary);
            font-weight: 500;
            transition: all 0.2s;
            border-bottom: 3px solid transparent;
        }
        .type-tab:hover { background-color: var(--bg-secondary); }
        .type-tab.active {
            color: var(--accent-color);
            border-bottom-color: var(--accent-color);
            background-color: var(--bg-secondary);
        }
        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }
        .image-preview {
            margin-top: 0.5rem;
            max-width: 400px;
            border-radius: 0;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        .image-preview img { width: 100%; height: auto; display: block; }
        .upload-section {
            background-color: var(--bg-secondary);
            border: 2px dashed var(--border-color);
            border-radius: 0;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }
        .upload-section:hover {
            border-color: var(--accent-color);
            background-color: var(--link-hover-bg);
        }
        .upload-button {
            display: inline-block;
            background-color: var(--accent-color);
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 0;
            cursor: pointer;
            margin-top: 0.5rem;
        }
        .file-input { display: none; }
        .image-options {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .option-title {
            font-size: 1rem;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        .link-url-cell {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: var(--bg-primary);
            border-radius: 0;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .modal-content .modal-body {
            max-height: calc(80vh - 60px);
            overflow-y: auto;
        }
        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }
        .modal-header h3 { margin: 0; color: var(--text-primary); }
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-secondary);
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            margin: 0;
        }
        .modal-close:hover {
            color: var(--text-primary);
            background: var(--bg-secondary);
        }
        .modal-body { padding: 1.5rem; }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        /* ===== 用户列表特有样式（修复样式失效） ===== */
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent-gradient-from);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1rem;
            overflow: hidden;
        }
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .username {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .user-email {
            color: var(--text-secondary);
            font-size: 0.9rem;
            word-break: break-all;
        }
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-badge.status-banned {
            background: #e53e3e;
            color: white;
        }
        .founder-badge, .admin-badge {
            padding: 0.15rem 0.6rem !important;
            line-height: 1.4 !important;
            border-radius: 4px !important;
            font-size: 0.7rem !important;
            white-space: nowrap !important;
        }
        .founder-badge {
            background: #fbbf24;
            color: white;
        }
        .admin-badge {
            background: var(--accent-gradient-from);
            color: white;
        }
        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 0.4rem 0.8rem;
            border-radius: 0;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-secondary:hover {
            background-color: var(--link-hover-bg);
        }
        /* 分页样式补充 */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 0;
            text-decoration: none;
            color: var(--text-primary);
            background: var(--bg-primary);
            font-size: 0.9rem;
        }
        .pagination a:hover {
            background: var(--link-hover-bg);
            border-color: var(--accent-color);
        }
        .pagination .active {
            background: var(--accent-gradient-from);
            color: white;
            border-color: transparent;
        }
        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        @media (max-width: 768px) {
            .admin-header-row { flex-direction: column; align-items: flex-start; }
            .admin-title { font-size: 1.4rem; }
            .admin-tabs-container { gap: 1rem; }
            .type-tabs { flex-direction: column; }
            .batch-actions { width: 100%; justify-content: space-between; }
            .action-buttons { flex-direction: column; }
            .admin-container { padding: 0; }
        }
        @media (max-width: 480px) {
            .admin-title { font-size: 1.2rem; }
            .back-home { font-size: 1.5rem; width: 32px; height: 32px; }
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <main class="main-content" style="max-width: none !important; width: 100%; margin: 0 !important; padding: 0 !important;">
        <div class="admin-header">
            <div class="admin-header-row">
                <div class="admin-header-left">
                    <a href="<?php echo url('index'); ?>" class="back-home" title="返回主页">←</a>
                    <h1 class="admin-title">主播模拟器论坛后台</h1>
                </div>
            </div>
        </div>

        <div class="admin-tabs">
            <div class="admin-tabs-container">
                <a href="<?php echo url('admin', [], ['tab' => 'users']); ?>" class="admin-tab <?php echo $tab === 'users' ? 'active' : ''; ?>">用户管理</a>
                <a href="<?php echo url('admin', [], ['tab' => 'settings']); ?>" class="admin-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">功能设置</a>
                <a href="<?php echo url('admin', [], ['tab' => 'maintenance']); ?>" class="admin-tab <?php echo $tab === 'maintenance' ? 'active' : ''; ?>">维护模式</a>
                <a href="<?php echo url('admin', [], ['tab' => 'github']); ?>" class="admin-tab <?php echo $tab === 'github' ? 'active' : ''; ?>">GitHub 登录</a>
                <a href="<?php echo url('admin', [], ['tab' => 'gitee']); ?>" class="admin-tab <?php echo $tab === 'gitee' ? 'active' : ''; ?>">Gitee 登录</a>
                <a href="<?php echo url('admin', [], ['tab' => 'email']); ?>" class="admin-tab <?php echo $tab === 'email' ? 'active' : ''; ?>">配置邮箱</a>
                <a href="<?php echo url('admin', [], ['tab' => 'home']); ?>" class="admin-tab <?php echo $tab === 'home' ? 'active' : ''; ?>">首页内容管理</a>
                <a href="<?php echo url('admin', [], ['tab' => 'levels']); ?>" class="admin-tab <?php echo $tab === 'levels' ? 'active' : ''; ?>">等级管理</a>
                <a href="<?php echo url('admin', [], ['tab' => 'api']); ?>" class="admin-tab <?php echo $tab === 'api' ? 'active' : ''; ?>">API切换</a>
            </div>
        </div>

        <div class="admin-container">
            <?php if (isset($successMessage)): ?>
                <div class="alert alert-success"><?php echo escape($successMessage); ?></div>
            <?php endif; ?>
            <?php if (isset($errorMessage)): ?>
                <div class="alert alert-error"><?php echo escape($errorMessage); ?></div>
            <?php endif; ?>

            <?php if ($tab === 'users'): ?>
                <!-- 用户管理内容 -->
                <div class="search-bar" style="margin-bottom: 0; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color);">
                    <form method="GET" style="max-width: 400px; position: relative;">
                        <input type="hidden" name="tab" value="users">
                        <input type="text" name="search" class="form-input" style="width: 100%; padding-right: 2.5rem; box-sizing: border-box;"
                               placeholder="搜索用户名或邮箱..." 
                               value="<?php echo escape($search); ?>"
                               oninput="clearTimeout(this._timer); this._timer=setTimeout(()=>this.form.submit(),400)">
                        <button type="submit" style="position: absolute; right: 0; top: 0; bottom: 0; width: 2.5rem; border: none; background: none; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; color: var(--text-secondary);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;">
                                <circle cx="11" cy="11" r="8"/>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                        </button>
                        <?php if (!empty($search)): ?>
                            <a href="<?php echo url('admin', [], ['tab' => 'users']); ?>" style="position: absolute; right: 2.5rem; top: 0; bottom: 0; width: 2rem; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); text-decoration: none;" title="清除搜索">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width: 16px; height: 16px;">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (empty($users)): ?>
                    <div style="padding: 0; color: var(--text-secondary);"><p style="padding: 1.5rem 1rem; margin: 0;"><?php echo !empty($search) ? '未找到匹配的用户' : '暂无用户数据'; ?></p></div>
                <?php else: ?>
                    <?php foreach ($users as $user): 
                        $isCurrentUser = ($user['id'] == $_SESSION['user_id']);
                        $isFounder = ($user['is_founder'] ?? 0);
                        $isProtected = $isCurrentUser || $isFounder;
                    ?>
                    <div style="display: flex; align-items: center; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); gap: 0.75rem;">
                        <div class="user-avatar" style="width: 44px; height: 44px; font-size: 1.1rem; flex-shrink: 0;<?php if (empty($user['avatar']) && !empty($user['avatar_text'])): ?> background: <?php echo escape($user['avatar_bg_color'] ?? 'var(--accent-gradient-from)'); ?>;<?php endif; ?>">
                            <?php echo getUserAvatarHtml($user, 'user-avatar'); ?>
                        </div>
                        <div style="flex: 1; min-width: 0; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                            <span style="font-weight: 600; color: var(--text-primary);"><?php echo escape($user['username']); ?></span>
                            <?php if ($isFounder): ?>
                                <span class="founder-badge">站长</span>
                            <?php elseif ($user['is_admin']): ?>
                                <span class="admin-badge">管理员</span>
                            <?php endif; ?>
                            <?php if ($user['is_banned']): ?>
                                <span class="status-badge status-banned">封禁</span>
                            <?php endif; ?>
                        </div>
                        <div style="flex-shrink: 0; display: flex; align-items: center; gap: 0.5rem;">
                            <?php if ($isCurrentUser): ?>
                                <span style="color: var(--accent-color); font-size: 0.85rem;">当前用户</span>
                            <?php elseif ($isFounder): ?>
                                <span style="color: #f59e0b; font-size: 0.85rem;">站长</span>
                            <?php else: ?>
                                <button type="button" class="btn-secondary edit-user-btn" 
                                        data-id="<?php echo $user['id']; ?>"
                                        data-username="<?php echo escape($user['username']); ?>"
                                        data-email="<?php echo escape($user['email']); ?>"
                                        data-isadmin="<?php echo $user['is_admin'] ? 1 : 0; ?>"
                                        data-isbanned="<?php echo $user['is_banned'] ? 1 : 0; ?>"
                                        data-isfounder="<?php echo $isFounder ? 1 : 0; ?>"
                                        data-maintbypass="<?php echo ($user['maintenance_bypass'] ?? 0) ? 1 : 0; ?>"
                                        data-isprotected="<?php echo $isProtected ? 1 : 0; ?>">
                                    编辑
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; color: var(--text-secondary); font-size: 0.9rem;">
                        <span>共 <?php echo $totalUsers; ?> 个用户，<?php echo $totalPages; ?> 页</span>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php 
                        $pageUrl = !empty($search) 
                            ? ['tab' => 'users', 'search' => $search, 'page' => '__PAGE__']
                            : ['tab' => 'users', 'page' => '__PAGE__'];
                        ?>
                        <?php if ($page > 1): ?>
                            <a href="<?php echo str_replace('__PAGE__', 1, url('admin', [], $pageUrl)); ?>">首页</a>
                            <a href="<?php echo str_replace('__PAGE__', $page - 1, url('admin', [], $pageUrl)); ?>">上一页</a>
                        <?php else: ?>
                            <span class="disabled">首页</span>
                            <span class="disabled">上一页</span>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        if ($start > 1) echo '<span>...</span>';
                        for ($i = $start; $i <= $end; $i++):
                            if ($i == $page):
                        ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo str_replace('__PAGE__', $i, url('admin', [], $pageUrl)); ?>"><?php echo $i; ?></a>
                        <?php endif; endfor; ?>
                        <?php if ($end < $totalPages) echo '<span>...</span>'; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo str_replace('__PAGE__', $page + 1, url('admin', [], $pageUrl)); ?>">下一页</a>
                            <a href="<?php echo str_replace('__PAGE__', $totalPages, url('admin', [], $pageUrl)); ?>">尾页</a>
                        <?php else: ?>
                            <span class="disabled">下一页</span>
                            <span class="disabled">尾页</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- 隐藏的单删表单（给编辑弹窗的删除按钮用） -->
                    <?php foreach ($users as $user): 
                        $isProtectedForDelete = ($user['id'] == $_SESSION['user_id']) || ($user['is_founder'] ?? 0);
                        if (!$isProtectedForDelete):
                    ?>
                        <form id="singleDelete<?php echo $user['id']; ?>" method="POST" style="display: none;">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        </form>
                    <?php endif; endforeach; ?>
                <?php endif; ?>

                <!-- 用户编辑弹窗 -->
                <div id="editUserModal" class="modal-overlay">
                    <div class="modal-content" style="max-width: 420px;">
                        <div class="modal-header">
                            <h3 id="editModalTitle">编辑用户</h3>
                            <button class="modal-close" onclick="closeEditModal()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width: 18px; height: 18px;">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                        </div>
                        <div class="modal-body">
                            <!-- 管理员开关 -->
                            <div class="settings-item" style="border-bottom: 1px solid var(--border-color);">
                                <div>
                                    <div class="settings-label">管理员权限</div>
                                    <div class="settings-desc">授予或取消用户的管理员权限</div>
                                </div>
                                <div>
                                    <form id="editAdminForm" method="POST" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="set_admin">
                                        <input type="hidden" id="editAdminUserId" name="user_id" value="">
                                        <input type="hidden" id="editAdminValue" name="set_admin" value="">
                                        <label class="custom-checkbox" id="editAdminCheckbox">
                                            <input type="checkbox" id="editAdminCheckboxInput">
                                            <span class="checkmark">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                                    <polyline points="4 12 10 18 20 6"/>
                                                </svg>
                                            </span>
                                        </label>
                                    </form>
                                </div>
                            </div>
                            <!-- 封禁开关 -->
                            <div class="settings-item" style="border-bottom: 1px solid var(--border-color);">
                                <div>
                                    <div class="settings-label">封禁用户</div>
                                    <div class="settings-desc">封禁后用户将无法登录和操作</div>
                                </div>
                                <div>
                                    <form id="editBanForm" method="POST" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="set_ban">
                                        <input type="hidden" id="editBanUserId" name="user_id" value="">
                                        <input type="hidden" id="editBanValue" name="ban" value="">
                                        <label class="custom-checkbox" id="editBanCheckbox">
                                            <input type="checkbox" id="editBanCheckboxInput">
                                            <span class="checkmark">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                                    <polyline points="4 12 10 18 20 6"/>
                                                </svg>
                                            </span>
                                        </label>
                                    </form>
                                </div>
                            </div>
                            <!-- 维护模式免检开关 -->
                            <div class="settings-item" style="border-bottom: 1px solid var(--border-color);">
                                <div>
                                    <div class="settings-label">维护模式免检</div>
                                    <div class="settings-desc">开启后此用户可在维护模式下正常访问论坛</div>
                                </div>
                                <div>
                                    <form id="editMaintenanceBypassForm" method="POST" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="set_maintenance_bypass">
                                        <input type="hidden" id="editMaintBypassUserId" name="user_id" value="">
                                        <input type="hidden" id="editMaintBypassValue" name="bypass" value="">
                                        <label class="custom-checkbox" id="editMaintBypassCheckbox">
                                            <input type="checkbox" id="editMaintBypassCheckboxInput">
                                            <span class="checkmark">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                                    <polyline points="4 12 10 18 20 6"/>
                                                </svg>
                                            </span>
                                        </label>
                                    </form>
                                </div>
                            </div>
                            <!-- 邮箱显示 -->
                            <div class="settings-item" style="border-bottom: 1px solid var(--border-color);">
                                <div>
                                    <div class="settings-label">邮箱</div>
                                </div>
                                <div id="editModalEmail" style="color: var(--text-secondary); word-break: break-all;"></div>
                            </div>
                            <!-- 删除用户 -->
                            <div style="margin-top: 1.5rem; text-align: center;">
                                <button type="button" id="editModalDeleteBtn" class="btn-delete" style="width: 100%; padding: 0.75rem; font-size: 1rem;">
                                    删除用户
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                function openEditModal(user) {
                    document.getElementById('editModalTitle').textContent = '编辑用户 - ' + user.username;
                    document.getElementById('editAdminUserId').value = user.id;
                    document.getElementById('editAdminValue').value = user.isadmin ? 0 : 1;
                    document.getElementById('editAdminCheckboxInput').checked = user.isadmin == 1;
                    
                    document.getElementById('editBanUserId').value = user.id;
                    document.getElementById('editBanValue').value = user.isbanned ? 0 : 1;
                    document.getElementById('editBanCheckboxInput').checked = user.isbanned == 1;

                    document.getElementById('editMaintBypassUserId').value = user.id;
                    document.getElementById('editMaintBypassValue').value = user.maintbypass ? 0 : 1;
                    document.getElementById('editMaintBypassCheckboxInput').checked = user.maintbypass == 1;
                    
                    document.getElementById('editModalEmail').textContent = user.email;
                    
                    var deleteBtn = document.getElementById('editModalDeleteBtn');
                    if (user.isprotected || user.isfounder) {
                        deleteBtn.disabled = true;
                        deleteBtn.style.opacity = '0.5';
                        deleteBtn.style.cursor = 'not-allowed';
                        deleteBtn.textContent = '不可删除（站长或当前用户）';
                    } else {
                        deleteBtn.disabled = false;
                        deleteBtn.style.opacity = '1';
                        deleteBtn.style.cursor = 'pointer';
                        deleteBtn.textContent = '删除用户';
                        deleteBtn.onclick = function() {
                            if (confirm('确定要删除用户 ' + user.username + ' 吗？此操作不可撤销！')) {
                                document.getElementById('singleDelete' + user.id).submit();
                            }
                        };
                    }
                    
                    document.getElementById('editUserModal').classList.add('active');
                }

                function closeEditModal() {
                    document.getElementById('editUserModal').classList.remove('active');
                }

                document.getElementById('editUserModal').addEventListener('click', function(e) {
                    if (e.target === this) closeEditModal();
                });

                // 管理员开关 AJAX
                document.getElementById('editAdminCheckboxInput').addEventListener('change', function() {
                    var form = document.getElementById('editAdminForm');
                    var newVal = this.checked ? 1 : 0;
                    document.getElementById('editAdminValue').value = newVal;
                    
                    var formData = new FormData(form);
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert(data.message || '操作失败');
                            location.reload();
                        }
                    })
                    .catch(function() {
                        alert('网络错误');
                        location.reload();
                    });
                });

                // 封禁开关 AJAX
                document.getElementById('editBanCheckboxInput').addEventListener('change', function() {
                    var form = document.getElementById('editBanForm');
                    var newVal = this.checked ? 1 : 0;
                    document.getElementById('editBanValue').value = newVal;
                    
                    var formData = new FormData(form);
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .catch(function() { return { success: false }; })
                    .then(function(data) {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert(data.message || '操作失败');
                            location.reload();
                        }
                    })
                    .catch(function() {
                        alert('网络错误');
                        location.reload();
                    });
                });

                // 维护模式免检开关 AJAX
                document.getElementById('editMaintBypassCheckboxInput').addEventListener('change', function() {
                    var form = document.getElementById('editMaintenanceBypassForm');
                    var newVal = this.checked ? 1 : 0;
                    document.getElementById('editMaintBypassValue').value = newVal;

                    var formData = new FormData(form);
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert(data.message || '操作失败');
                            location.reload();
                        }
                    })
                    .catch(function() {
                        alert('网络错误');
                        location.reload();
                    });
                });

                // 绑定编辑按钮
                document.querySelectorAll('.edit-user-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        openEditModal({
                            id: this.dataset.id,
                            username: this.dataset.username,
                            email: this.dataset.email,
                            isadmin: parseInt(this.dataset.isadmin),
                            isbanned: parseInt(this.dataset.isbanned),
                            maintbypass: parseInt(this.dataset.maintbypass || 0),
                            isfounder: parseInt(this.dataset.isfounder),
                            isprotected: parseInt(this.dataset.isprotected)
                        });
                    });
                });
                </script>

            <?php elseif ($tab === 'settings'): ?>
                <!-- 功能设置 -->
                <div class="settings-card">
                    <h3 style="margin-bottom: 1rem; color: var(--text-primary);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; vertical-align: -3px; margin-right: 6px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>安全与功能设置</h3>
                    
                    <!-- 注册开关 -->
                    <div class="settings-item">
                        <div>
                            <div class="settings-label">新用户注册</div>
                            <div class="settings-desc">关闭后，网站将停止接受新用户注册，首页轮播图下方会显示注册关闭声明。</div>
                        </div>
                        <div class="settings-switch">
                            <form method="POST" class="switch-form" data-action="toggle_registration">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="toggle_registration">
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="enable" <?php echo $registrationEnabled ? 'checked' : ''; ?>>
                                    <span class="checkmark">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <polyline points="4 12 10 18 20 6"/>
                                        </svg>
                                    </span>
                                </label>
                            </form>
                        </div>
                    </div>

                    <div class="settings-item">
                        <div>
                            <div class="settings-label">注册验证码</div>
                            <div class="settings-desc">开启后，新用户注册时需要输入图片验证码，防止脚本批量注册。</div>
                        </div>
                        <div class="settings-switch">
                            <form method="POST" class="switch-form" data-action="toggle_captcha">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="toggle_captcha">
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="enable" <?php echo $captchaEnabled ? 'checked' : ''; ?>>
                                    <span class="checkmark">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <polyline points="4 12 10 18 20 6"/>
                                        </svg>
                                    </span>
                                </label>
                            </form>
                        </div>
                    </div>
                    <div class="settings-item">
                        <div>
                            <div class="settings-label">注册邮箱验证</div>
                            <div class="settings-desc">开启后，新用户注册时需要输入邮箱验证码，确保邮箱真实性。</div>
                        </div>
                        <div class="settings-switch">
                            <form method="POST" class="switch-form" data-action="toggle_email_verification">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="toggle_email_verification">
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="enable" <?php echo $emailVerificationEnabled ? 'checked' : ''; ?>>
                                    <span class="checkmark">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <polyline points="4 12 10 18 20 6"/>
                                        </svg>
                                    </span>
                                </label>
                            </form>
                        </div>
                    </div>
                    <div class="settings-item">
                        <div>
                            <div class="settings-label">SMTP 邮件配置</div>
                            <div class="settings-desc">配置邮件服务器，用于发送注册验证码等通知邮件。</div>
                        </div>
                        <div>
                            <a href="<?php echo url('admin', [], ['tab' => 'email']); ?>" class="manual-btn" style="text-decoration: none; display: inline-block;">配置 SMTP</a>
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <h3 style="margin-bottom: 1rem; color: var(--text-primary);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; vertical-align: -3px; margin-right: 6px;"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>关注站长设置</h3>
                    <div class="settings-item">
                        <div>
                            <div class="settings-label">新用户自动关注站长</div>
                            <div class="settings-desc">开启后，新注册的用户将自动关注站长。关闭后新用户不再自动关注，但已关注的不会取消。</div>
                        </div>
                        <div class="settings-switch">
                            <form method="POST" class="switch-form" data-action="toggle_auto_follow">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="toggle_auto_follow">
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="enable" <?php echo $autoFollowEnabled ? 'checked' : ''; ?>>
                                    <span class="checkmark">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <polyline points="4 12 10 18 20 6"/>
                                        </svg>
                                    </span>
                                </label>
                            </form>
                        </div>
                    </div>
                    <div class="settings-item">
                        <div>
                            <div class="settings-label">手动批量关注</div>
                            <div class="settings-desc">让所有已有用户立即关注站长（已关注的不影响）。</div>
                        </div>
                        <div>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="follow_station_master">
                                <button type="submit" class="manual-btn" onclick="return confirm('确定要让所有用户关注站长吗？此操作可能耗时较长！')">立即执行</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <h3 style="margin-bottom: 1rem; color: var(--text-primary);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; vertical-align: -3px; margin-right: 6px;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>URL 设置</h3>
                    <div class="settings-item">
                        <div>
                            <div class="settings-label">启用伪静态</div>
                            <div class="settings-desc">开启后，页面链接将显示为类似 /mod/1 的简洁形式（需要服务器支持 URL 重写）。</div>
                        </div>
                        <div class="settings-switch">
                            <form method="POST" class="switch-form" data-action="toggle_pretty_url">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="toggle_pretty_url">
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="enable" <?php echo $prettyUrlEnabled ? 'checked' : ''; ?>>
                                    <span class="checkmark">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <polyline points="4 12 10 18 20 6"/>
                                        </svg>
                                    </span>
                                </label>
                            </form>
                        </div>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 2rem;">
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">
                        当前登录管理员：<strong><?php echo escape($_SESSION['username']); ?></strong>
                    </p>
                </div>

            <?php elseif ($tab === 'maintenance'): ?>
                <!-- 维护模式 -->
                <div class="settings-card">
                    <h3 style="margin-bottom: 1rem; color: var(--text-primary);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; vertical-align: -3px; margin-right: 6px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>维护模式设置</h3>
                    <div class="settings-item">
                        <div>
                            <div class="settings-label">维护模式</div>
                            <div class="settings-desc">开启后普通用户访问论坛将显示维护页面，管理员和站长不受影响。个别用户可通过编辑用户开启免检。</div>
                        </div>
                        <div class="settings-switch">
                            <form method="POST" class="switch-form" data-action="toggle_maintenance">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="toggle_maintenance">
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="enable" <?php echo $maintenanceMode ? 'checked' : ''; ?>>
                                    <span class="checkmark">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <polyline points="4 12 10 18 20 6"/>
                                        </svg>
                                    </span>
                                </label>
                            </form>
                        </div>
                    </div>
                    <div id="maintenanceSettings" style="display: <?php echo $maintenanceMode ? 'block' : 'none'; ?>;">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            <input type="hidden" name="action" value="save_maintenance_settings">
                            <div class="settings-item">
                                <div>
                                    <div class="settings-label">维护标题</div>
                                    <div class="settings-desc">维护页面显示的大标题</div>
                                </div>
                                <div>
                                    <input type="text" name="maintenance_title" class="form-input" style="width: 200px;" value="<?php echo htmlspecialchars($maintenanceTitle); ?>" maxlength="100">
                                </div>
                            </div>
                            <div class="settings-item">
                                <div>
                                    <div class="settings-label">维护说明</div>
                                    <div class="settings-desc">维护页面显示的详细信息</div>
                                </div>
                                <div>
                                    <textarea name="maintenance_message" class="form-input" style="width: 250px; height: 60px; resize: vertical;"><?php echo htmlspecialchars($maintenanceMessage); ?></textarea>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn-primary">保存维护页面内容</button>
                            </div>
                        </form>
                    </div>
                    <script>
                    document.querySelector('.switch-form[data-action="toggle_maintenance"]').addEventListener('change', function() {
                        var settings = document.getElementById('maintenanceSettings');
                        setTimeout(function() {
                            var form = document.querySelector('.switch-form[data-action="toggle_maintenance"]');
                            var checked = form.querySelector('input[type="checkbox"]').checked;
                            settings.style.display = checked ? 'block' : 'none';
                        }, 100);
                    });
                    </script>
                </div>

            <?php elseif ($tab === 'github'): ?>
                <!-- GitHub 登录设置 -->
                <div class="settings-card">
                    <h3 style="margin-bottom: 1rem; color: var(--text-primary);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; vertical-align: -3px; margin-right: 6px;"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>GitHub 登录设置</h3>
                    <div class="settings-item">
                        <div>
                            <div class="settings-label">GitHub 登录</div>
                            <div class="settings-desc">开启后登录页面将显示「GitHub 登录」按钮，用户可使用 GitHub 账号登录。</div>
                        </div>
                        <div class="settings-switch">
                            <form method="POST" class="switch-form" data-action="toggle_github_oauth">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="toggle_github_oauth">
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="enable" <?php echo $githubOAuthEnabled ? 'checked' : ''; ?>>
                                    <span class="checkmark">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <polyline points="4 12 10 18 20 6"/>
                                        </svg>
                                    </span>
                                </label>
                            </form>
                        </div>
                    </div>
                    <div id="githubOAuthSettings" style="display: <?php echo $githubOAuthEnabled ? 'block' : 'none'; ?>;">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            <input type="hidden" name="action" value="save_github_oauth">
                            <div class="settings-item">
                                <div>
                                    <div class="settings-label">Client ID</div>
                                    <div class="settings-desc">GitHub OAuth App 的 Client ID</div>
                                </div>
                                <div>
                                    <input type="text" name="github_client_id" class="form-input" style="width: 250px;" value="<?php echo htmlspecialchars($githubClientId); ?>" placeholder="Iv1.xxxxxxxxxxxx">
                                </div>
                            </div>
                            <div class="settings-item">
                                <div>
                                    <div class="settings-label">Client Secret</div>
                                    <div class="settings-desc">GitHub OAuth App 的 Client Secret</div>
                                </div>
                                <div>
                                    <input type="password" name="github_client_secret" class="form-input" style="width: 250px;" value="<?php echo htmlspecialchars($githubClientSecret); ?>" placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                                </div>
                            </div>
                            <div class="settings-item">
                                <div>
                                    <div class="settings-label">回调地址</div>
                                    <div class="settings-desc">在 GitHub OAuth App 设置中填写此地址</div>
                                </div>
                                <div style="font-size: 12px; color: var(--text-secondary); word-break: break-all;">
                                    <?php echo htmlspecialchars(SITE_URL); ?>auth.php?action=github_callback
                                </div>
                            </div>
                            <div style="font-size: 12px; color: var(--text-secondary); word-break: break-all; margin-top: 8px;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg> 已启用服务器本地 nginx 代理加速
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn-primary">保存 GitHub 配置</button>
                            </div>
                        </form>
                    </div>
                    <script>
                    document.querySelector('.switch-form[data-action="toggle_github_oauth"]').addEventListener('change', function() {
                        var settings = document.getElementById('githubOAuthSettings');
                        setTimeout(function() {
                            var form = document.querySelector('.switch-form[data-action="toggle_github_oauth"]');
                            var checked = form.querySelector('input[type="checkbox"]').checked;
                            settings.style.display = checked ? 'block' : 'none';
                        }, 100);
                    });
                    </script>
                </div>

            <?php elseif ($tab === 'gitee'): ?>
                <!-- Gitee 登录设置 -->
                <div class="settings-card">
                    <h3 style="margin-bottom: 1rem; color: var(--text-primary);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; vertical-align: -3px; margin-right: 6px;"><circle cx="12" cy="12" r="10" fill="#C71D23" stroke="#C71D23"/><text x="12" y="16" text-anchor="middle" fill="white" font-size="14" font-weight="bold">G</text></svg>Gitee 登录设置</h3>
                    <div class="settings-item">
                        <div>
                            <div class="settings-label">Gitee 登录</div>
                            <div class="settings-desc">开启后登录页面将显示「Gitee 登录」按钮，用户可使用 Gitee 账号登录。</div>
                        </div>
                        <div class="settings-switch">
                            <form method="POST" class="switch-form" data-action="toggle_gitee_oauth">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="toggle_gitee_oauth">
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="enable" <?php echo $giteeOAuthEnabled ? 'checked' : ''; ?>>
                                    <span class="checkmark">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <polyline points="4 12 10 18 20 6"/>
                                        </svg>
                                    </span>
                                </label>
                            </form>
                        </div>
                    </div>
                    <div id="giteeOAuthSettings" style="display: <?php echo $giteeOAuthEnabled ? 'block' : 'none'; ?>;">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            <input type="hidden" name="action" value="save_gitee_oauth">
                            <div class="settings-item">
                                <div>
                                    <div class="settings-label">Client ID</div>
                                    <div class="settings-desc">Gitee OAuth App 的 Client ID</div>
                                </div>
                                <div>
                                    <input type="text" name="gitee_client_id" class="form-input" style="width: 250px;" value="<?php echo htmlspecialchars($giteeClientId); ?>" placeholder="填写 Gitee OAuth App 的 Client ID">
                                </div>
                            </div>
                            <div class="settings-item">
                                <div>
                                    <div class="settings-label">Client Secret</div>
                                    <div class="settings-desc">Gitee OAuth App 的 Client Secret</div>
                                </div>
                                <div>
                                    <input type="password" name="gitee_client_secret" class="form-input" style="width: 250px;" value="<?php echo htmlspecialchars($giteeClientSecret); ?>" placeholder="填写 Gitee OAuth App 的 Client Secret">
                                </div>
                            </div>
                            <div class="settings-item">
                                <div>
                                    <div class="settings-label">回调地址</div>
                                    <div class="settings-desc">在 Gitee OAuth App 设置中填写此地址</div>
                                </div>
                                <div style="font-size: 12px; color: var(--text-secondary); word-break: break-all;">
                                    <?php echo htmlspecialchars(SITE_URL); ?>auth.php?action=gitee_callback
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn-primary">保存 Gitee 配置</button>
                            </div>
                        </form>
                    </div>
                    <script>
                    document.querySelector('.switch-form[data-action="toggle_gitee_oauth"]').addEventListener('change', function() {
                        var settings = document.getElementById('giteeOAuthSettings');
                        setTimeout(function() {
                            var form = document.querySelector('.switch-form[data-action="toggle_gitee_oauth"]');
                            var checked = form.querySelector('input[type="checkbox"]').checked;
                            settings.style.display = checked ? 'block' : 'none';
                        }, 100);
                    });
                    </script>
                </div>
            <?php elseif ($tab === 'email'): ?>
                <!-- 配置邮箱 -->
                <div class="content-card">
                    <h3 class="card-title" style="margin-bottom: 1.5rem;">SMTP 邮件配置</h3>
                    <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">配置邮件服务器，用于发送注册验证码等通知邮件。</p>
                    <form method="POST" id="smtpForm" style="max-width: 500px;">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="save_smtp_config">
                        <div class="form-group">
                            <label>SMTP 服务器地址</label>
                            <input type="text" name="smtp_host" class="form-input" value="<?php echo escape($smtpConfig['host']); ?>" placeholder="smtp.example.com" required>
                        </div>
                        <div class="form-group">
                            <label>端口</label>
                            <input type="number" name="smtp_port" class="form-input" value="<?php echo $smtpConfig['port']; ?>" placeholder="587" required>
                        </div>
                        <div class="form-group">
                            <label>加密方式</label>
                            <select name="smtp_encryption" class="form-input">
                                <option value="tls" <?php echo $smtpConfig['encryption'] === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                <option value="ssl" <?php echo $smtpConfig['encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                <option value="" <?php echo $smtpConfig['encryption'] === '' ? 'selected' : ''; ?>>无</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" name="smtp_username" class="form-input" value="<?php echo escape($smtpConfig['username']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>密码</label>
                            <input type="password" name="smtp_password" class="form-input" value="<?php echo escape($smtpConfig['password']); ?>">
                            <small style="color: var(--text-secondary);">留空则保持原密码不变</small>
                        </div>
                        <div class="form-group">
                            <label>发件人邮箱</label>
                            <input type="email" name="smtp_from_email" class="form-input" value="<?php echo escape($smtpConfig['from_email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>发件人名称</label>
                            <input type="text" name="smtp_from_name" class="form-input" value="<?php echo escape($smtpConfig['from_name']); ?>" placeholder="主播模拟器论坛">
                        </div>
                        <div style="margin-top: 1.5rem;">
                            <button type="submit" class="btn-primary">保存配置</button>
                        </div>
                    </form>
                </div>

            <?php elseif ($tab === 'home'): ?>
                <!-- 首页内容管理模块 -->
                <div class="type-tabs">
                    <a href="<?php echo url('admin', [], ['tab' => 'home', 'type' => 'slide']); ?>" class="type-tab <?php echo $type === 'slide' ? 'active' : ''; ?>">幻灯片管理</a>
                    <a href="<?php echo url('admin', [], ['tab' => 'home', 'type' => 'link']); ?>" class="type-tab <?php echo $type === 'link' ? 'active' : ''; ?>">文字链接管理</a>
                </div>

                <?php if ($action === 'edit' || $action === 'add'): ?>
                    <div class="content-card">
                        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                            <h3 class="card-title">
                                <?php echo $action === 'edit' ? '编辑' : '添加'; ?>
                                <?php echo $type === 'slide' ? '幻灯片' : '文字链接'; ?>
                            </h3>
                            <a href="<?php echo url('admin', [], ['tab' => 'home', 'type' => $type]); ?>" class="btn-secondary">返回列表</a>
                        </div>

                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            <input type="hidden" name="form_type" value="<?php echo $type; ?>">
                            <input type="hidden" name="form_action" value="<?php echo $type === 'slide' ? 'save_slide' : 'save_link'; ?>">
                            <?php if ($editItem && $action === 'edit'): ?>
                                <input type="hidden" name="id" value="<?php echo $editItem['id']; ?>">
                            <?php endif; ?>

                            <?php if ($type === 'slide'): ?>
                                <!-- 幻灯片编辑/添加：只保留图片、链接设置和排序/启用，去掉标题和描述 -->

                                <div class="image-options">
                                    <div>
                                        <div class="option-title">上传图片</div>
                                        <div class="upload-section" id="uploadSection">
                                            <div class="upload-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 24px; height: 24px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></div>
                                            <div class="upload-text">
                                                <?php if ($action === 'edit' && !empty($editItem['image_url'])): ?>
                                                    点击上传新图片替换当前图片
                                                <?php else: ?>
                                                    点击上传图片
                                                <?php endif; ?>
                                            </div>
                                            <div class="upload-hint">支持 JPG、PNG、GIF、WebP 格式，最大5MB</div>
                                            <div class="upload-button" onclick="document.getElementById('slideImage').click()">选择图片</div>
                                            <input type="file" id="slideImage" name="slide_image" class="file-input" accept=".jpg,.jpeg,.png,.gif,.webp" onchange="previewImage(this)">
                                        </div>

                                        <?php if ($action === 'edit' && !empty($editItem['image_url'])): ?>
                                            <div class="image-preview" id="currentImagePreview">
                                                <img src="<?php echo getImageUrl(escape($editItem['image_url'])); ?>" alt="当前图片" onerror="this.src='data:image/svg+xml;charset=UTF-8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 400 200\"><rect width=\"100%\" height=\"100%\" fill=\"%23f0f0f0\"/><text x=\"50%\" y=\"50%\" font-family=\"Arial\" font-size=\"14\" fill=\"%23999\" text-anchor=\"middle\" dy=\".3em\">图片加载失败</text></svg>'">
                                            </div>
                                        <?php endif; ?>
                                        <div class="image-preview" id="newImagePreview" style="display: none;"></div>
                                    </div>

                                    <div>
                                        <div class="option-title">或使用图片URL</div>
                                        <div class="form-group">
                                            <label for="image_url" class="form-label">图片URL</label>
                                            <input type="url" id="image_url" name="image_url" class="form-input" value="<?php echo escape($editItem['image_url'] ?? ''); ?>" placeholder="https://example.com/image.jpg">
                                            <small style="color: var(--text-secondary); font-size: 0.85rem;">建议尺寸：1200×300像素</small>
                                        </div>
                                    </div>
                                </div>

                                <!-- 链接设置部分（仅幻灯片） -->
                                <div class="form-group">
                                    <label for="link_url" class="form-label">链接URL</label>
                                    <input type="url" id="link_url" name="link_url" class="form-input" value="<?php echo escape($editItem['link_url'] ?? ''); ?>" placeholder="https://example.com">
                                    <small style="color: var(--text-secondary);">点击幻灯片时跳转的地址（留空则不跳转）</small>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="link_text" class="form-label">链接文字</label>
                                        <input type="text" id="link_text" name="link_text" class="form-input" value="<?php echo escape($editItem['link_text'] ?? '了解更多'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <div style="margin-bottom: 0.5rem;">选项</div>
                                        <div class="form-checkbox">
                                            <input type="checkbox" id="link_target" name="link_target" <?php echo (($editItem['link_target'] ?? 0) == 1) ? 'checked' : ''; ?>>
                                            <label for="link_target" style="margin-bottom: 0;">在新窗口打开链接</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="sort_order" class="form-label">排序</label>
                                        <input type="number" id="sort_order" name="sort_order" class="form-input" value="<?php echo escape($editItem['sort_order'] ?? 0); ?>" min="0" max="999">
                                        <small style="color: var(--text-secondary); font-size: 0.85rem;">数字越小越靠前</small>
                                    </div>
                                    <div class="form-group">
                                        <div style="margin-bottom: 0.5rem;">选项</div>
                                        <div class="form-checkbox">
                                            <input type="checkbox" id="is_active" name="is_active" <?php echo (($editItem['is_active'] ?? 1) == 1) ? 'checked' : ''; ?>>
                                            <label for="is_active" style="margin-bottom: 0;">启用</label>
                                        </div>
                                    </div>
                                </div>

                            <?php else: ?>
                                <!-- 文字链接编辑部分保持不变 -->
                                <div class="form-group">
                                    <label for="title" class="form-label">标题 *</label>
                                    <input type="text" id="title" name="title" class="form-input" value="<?php echo escape($editItem['title'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="link_url" class="form-label">链接URL *</label>
                                    <input type="url" id="link_url" name="link_url" class="form-input" value="<?php echo escape($editItem['link_url'] ?? ''); ?>" required placeholder="https://example.com">
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="sort_order" class="form-label">排序</label>
                                        <input type="number" id="sort_order" name="sort_order" class="form-input" value="<?php echo escape($editItem['sort_order'] ?? 0); ?>" min="0" max="999">
                                        <small style="color: var(--text-secondary); font-size: 0.85rem;">数字越小越靠前</small>
                                    </div>
                                    <div class="form-group">
                                        <div style="margin-bottom: 0.5rem;">选项</div>
                                        <div class="form-checkbox">
                                            <input type="checkbox" id="link_target" name="link_target" <?php echo (($editItem['link_target'] ?? 0) == 1) ? 'checked' : ''; ?>>
                                            <label for="link_target" style="margin-bottom: 0;">在新窗口打开链接</label>
                                        </div>
                                        <div class="form-checkbox">
                                            <input type="checkbox" id="is_active" name="is_active" <?php echo (($editItem['is_active'] ?? 1) == 1) ? 'checked' : ''; ?>>
                                            <label for="is_active" style="margin-bottom: 0;">启用</label>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="form-actions">
                                <a href="<?php echo url('admin', [], ['tab' => 'home', 'type' => $type]); ?>" class="btn-secondary">取消</a>
                                <button type="submit" class="btn-primary">保存</button>
                            </div>
                        </form>
                    </div>

                <?php else: ?>
                    <div class="content-card">
                        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                            <h3 class="card-title">
                                <?php echo $type === 'slide' ? '幻灯片' : '文字链接'; ?>列表
                                <small style="font-size: 0.9rem; color: var(--text-secondary); font-weight: normal; margin-left: 0.5rem;">
                                    (共 <?php echo $type === 'slide' ? count($slides) : count($links); ?> 条)
                                </small>
                            </h3>
                            <a href="<?php echo url('admin', [], ['tab' => 'home', 'type' => $type, 'action' => 'add']); ?>" class="btn-primary">+ 添加<?php echo $type === 'slide' ? '幻灯片' : '链接'; ?></a>
                        </div>

                        <?php 
                        $items = $type === 'slide' ? $slides : $links;
                        if (empty($items)): 
                        ?>
                            <div class="empty-state">
                                <p>暂无<?php echo $type === 'slide' ? '幻灯片' : '文字链接'; ?>数据</p>
                                <a href="<?php echo url('admin', [], ['tab' => 'home', 'type' => $type, 'action' => 'add']); ?>" class="btn-primary">添加第一个<?php echo $type === 'slide' ? '幻灯片' : '链接'; ?></a>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <?php if ($type === 'slide'): ?>
                                                <th>预览</th>
                                                <th>排序</th>
                                                <th>状态</th>
                                                <th>操作</th>
                                            <?php else: ?>
                                                <th>标题</th>
                                                <th>链接</th>
                                                <th>排序</th>
                                                <th>状态</th>
                                                <th>操作</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item): ?>
                                        <tr>
                                            <?php if ($type === 'slide'): ?>
                                                <td style="width: 120px;">
                                                    <div style="width: 100px; height: 50px; border-radius: 0; overflow: hidden;">
                                                        <img src="<?php echo getImageUrl(escape($item['image_url'])); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.src='data:image/svg+xml;charset=UTF-8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 50\"><rect width=\"100%\" height=\"100%\" fill=\"%23f0f0f0\"/><text x=\"50%\" y=\"50%\" font-family=\"Arial\" font-size=\"8\" fill=\"%23999\" text-anchor=\"middle\" dy=\".3em\">图片</text></svg>'">
                                                    </div>
                                                </td>
                                                <td><?php echo $item['sort_order']; ?></td>
                                                <td><span class="status-badge <?php echo $item['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $item['is_active'] ? '启用' : '禁用'; ?></span></td>
                                            <?php else: ?>
                                                <td><?php echo escape($item['title']); ?></td>
                                                <td class="link-url-cell"><a href="<?php echo escape($item['link_url']); ?>" target="_blank" style="color: var(--accent-color); font-size: 0.9rem; word-break: break-all;" title="<?php echo escape($item['link_url']); ?>"><?php echo mb_strlen($item['link_url']) > 40 ? escape(mb_substr($item['link_url'], 0, 40, 'UTF-8')) . '...' : escape($item['link_url']); ?></a></td>
                                                <td><?php echo $item['sort_order']; ?></td>
                                                <td><span class="status-badge <?php echo $item['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $item['is_active'] ? '启用' : '禁用'; ?></span></td>
                                            <?php endif; ?>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('admin', [], ['tab' => 'home', 'type' => $type, 'action' => 'edit', 'id' => $item['id']]); ?>" class="btn-secondary">编辑</a>
                                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('确定要删除吗？此操作不可撤销！');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                                        <input type="hidden" name="form_action" value="<?php echo $type === 'slide' ? 'delete_slide' : 'delete_link'; ?>">
                                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                        <button type="submit" class="btn-danger">删除</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: center; margin-top: 2rem; color: var(--text-secondary); font-size: 0.9rem;">
                        <p>提示：上传的图片将保存在 <?php echo UPLOAD_DIR_SLIDES; ?> 目录下，建议尺寸：1200×300像素</p>
                    </div>
                <!-- /settings-card -->

                <!-- 站点统计 -->
                <div class="stats-card" style="margin-top: 1.5rem;">
                    <h3 style="margin-bottom: 1rem; color: var(--text-primary);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; vertical-align: -3px; margin-right: 6px;"><path d="M2 3v6a7 7 0 0 0 7 7h6"/><path d="M22 3v6a7 7 0 0 1-7 7H9"/></svg>站点统计</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.2rem;">
                        <!-- 总用户 / 已运行 -->
                        <div style="background: var(--bg-secondary); border-radius: var(--radius); padding: 1rem; text-align: center;">
                            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem;">总用户</div>
                            <div style="font-size: 1.8rem; font-weight: 700; color: var(--accent-color);"><?php echo number_format(getUserCount()); ?></div>
                        </div>
                        <div style="background: var(--bg-secondary); border-radius: var(--radius); padding: 1rem; text-align: center;">
                            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem;">当前时间</div>
                            <div style="font-size: 1.2rem; font-weight: 600; color: var(--text-primary);" id="adminClock">--:--:--</div>
                            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.3rem;" id="adminDate">----年--月--日</div>
                        </div>
                        <div style="background: var(--bg-secondary); border-radius: var(--radius); padding: 1rem; text-align: center;">
                            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem;">已运行</div>
                            <div style="font-size: 1.1rem; font-weight: 600; color: var(--text-primary);" id="adminRunningDays">计算中...</div>
                        </div>
                        <div style="background: var(--bg-secondary); border-radius: var(--radius); padding: 1rem; text-align: center;">
                            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem;">访问统计</div>
                            <img src="https://count.getloli.com/@zbgame?name=zbgame&theme=minecraft&padding=7&offset=0&align=top&scale=1&pixelated=1&darkmode=auto" alt="访问统计" style="max-width:100%;height:auto;border-radius:4px;">
                        </div>
                    </div>
                </div>
                <script>
                (function() {
                    function updateAdminClock() {
                        var now = new Date();
                        var h = String(now.getHours()).padStart(2, '0');
                        var m = String(now.getMinutes()).padStart(2, '0');
                        var s = String(now.getSeconds()).padStart(2, '0');
                        var clk = document.getElementById('adminClock');
                        if (clk) clk.textContent = h + ':' + m + ':' + s;
                        var y = now.getFullYear();
                        var mo = String(now.getMonth() + 1).padStart(2, '0');
                        var d = String(now.getDate()).padStart(2, '0');
                        var days = ['日','一','二','三','四','五','六'];
                        var dt = document.getElementById('adminDate');
                        if (dt) dt.textContent = y + '年' + mo + '月' + d + '日 星期' + days[now.getDay()];
                    }
                    function updateAdminRunningDays() {
                        try {
                            var startTime = new Date('2026-05-18T05:24:00+08:00');
                            var now = new Date();
                            var diff = now - startTime;
                            if (diff < 0) { var el = document.getElementById('adminRunningDays'); if (el) el.textContent = '0天'; return; }
                            var totalSeconds = Math.floor(diff / 1000);
                            var days = Math.floor(totalSeconds / 86400);
                            var hours = Math.floor((totalSeconds % 86400) / 3600);
                            var minutes = Math.floor((totalSeconds % 3600) / 60);
                            var seconds = totalSeconds % 60;
                            var el = document.getElementById('adminRunningDays');
                            if (el) el.textContent = days + '天 ' + hours + '小时 ' + minutes + '分钟 ' + seconds + '秒';
                        } catch(e) {}
                    }
                    updateAdminClock();
                    updateAdminRunningDays();
                    setInterval(updateAdminClock, 1000);
                    setInterval(updateAdminRunningDays, 1000);
                })();
                </script>
            <?php endif; ?>

            <?php elseif ($tab === 'levels'): ?>
                <?php
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_level_names') {
                    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                        $errorMessage = 'CSRF 令牌验证失败！';
                    } else {
                        $names = [];
                        for ($i = 1; $i <= MAX_LEVEL; $i++) {
                            $key = 'level_name_' . $i;
                            $names[$i] = trim($_POST[$key] ?? '');
                            if (empty($names[$i])) {
                                $names[$i] = getLevelName($i);
                            }
                        }
                        setSetting('level_names', json_encode($names));
                        $successMessage = '等级名称已保存！';
                    }
                }
                $levelNamesSetting = getSetting('level_names', '');
                $levelNames = [];
                if (!empty($levelNamesSetting)) {
                    $levelNames = json_decode($levelNamesSetting, true);
                }
                if (empty($levelNames)) {
                    for ($i = 1; $i <= MAX_LEVEL; $i++) {
                        $levelNames[$i] = getLevelName($i);
                    }
                }
                ?>
                <div class="content-card">
                    <h3>等级名称配置 (1-100级)</h3>
                    <p style="color:var(--text-secondary);font-size:0.85rem;margin-bottom:1rem;">自定义每个等级的称号名称，留空则使用默认名称。</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="save_level_names">
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0.5rem;">
                            <?php for ($i = 1; $i <= MAX_LEVEL; $i++): ?>
                                <div>
                                    <label style="font-size:0.8rem;color:var(--text-secondary);">Lv.<?php echo $i; ?></label>
                                    <input type="text" name="level_name_<?php echo $i; ?>" 
                                           value="<?php echo escape($levelNames[$i] ?? getLevelName($i)); ?>"
                                           style="width:100%;padding:0.4rem;border:1px solid var(--border-color);background:var(--bg-primary);color:var(--text-primary);box-sizing:border-box;">
                                </div>
                            <?php endfor; ?>
                        </div>
                        <div style="margin-top:1.5rem;">
                            <button type="submit" class="btn-primary" style="padding:0.75rem 2rem;">保存所有等级名称</button>
                        </div>
                    </form>
                </div>

                <?php
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_level_colors') {
                    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                        $errorMessage = 'CSRF 令牌验证失败！';
                    } else {
                        $colors = [];
                        for ($t = 1; $t <= 10; $t++) {
                            $c = trim($_POST['level_color_' . $t] ?? '');
                            $colors[$t] = preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#6b7280';
                        }
                        setSetting('level_badge_colors', json_encode($colors));
                        $successMessage = '等级边框颜色已保存！';
                    }
                }
                $currentColors = getLevelBadgeColors();
                ?>
                <div class="content-card" style="margin-top:1.5rem;">
                    <h3>等级边框颜色配置</h3>
                    <p style="color:var(--text-secondary);font-size:0.85rem;margin-bottom:1rem;">
                        为每10个等级段设置不同的边框颜色。Lv.1-10用一个颜色，Lv.11-20用下一个，以此类推。
                    </p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="save_level_colors">
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:0.8rem;">
                            <?php for ($t = 1; $t <= 10; $t++): 
                                $from = ($t - 1) * 10 + 1;
                                $to = $t * 10;
                                $color = $currentColors[$t] ?? '#6b7280';
                            ?>
                                <div style="text-align:center;">
                                    <label style="font-size:0.8rem;font-weight:600;color:var(--text-primary);display:block;margin-bottom:0.3rem;">Lv.<?php echo $from; ?>-<?php echo $to; ?></label>
                                    <div style="display:flex;align-items:center;gap:0.4rem;justify-content:center;">
                                        <input type="color" name="level_color_<?php echo $t; ?>" value="<?php echo $color; ?>" style="width:50px;height:36px;padding:2px;border:1px solid var(--border-color);background:none;cursor:pointer;">
                                        <input type="text" name="level_color_<?php echo $t; ?>_text" value="<?php echo $color; ?>"
                                               style="width:80px;padding:0.4rem;border:1px solid var(--border-color);background:var(--bg-primary);color:var(--text-primary);font-size:0.8rem;text-align:center;"
                                               oninput="this.form['level_color_<?php echo $t; ?>'].value=this.value"
                                               onchange="this.form['level_color_<?php echo $t; ?>'].value=this.value">
                                    </div>
                                    <div style="margin-top:0.3rem;width:36px;height:4px;border-radius:2px;background:<?php echo $color; ?>;display:inline-block;"></div>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <div style="margin-top:1.5rem;">
                            <button type="submit" class="btn-primary" style="padding:0.75rem 2rem;">保存颜色配置</button>
                        </div>
                    </form>
                </div>
                </div>
            <?php elseif ($tab === 'api'): ?>
                <div class="content-card">
                    <h3>API 后端切换</h3>
                    <p style="color:var(--text-secondary);font-size:0.85rem;margin-bottom:1rem;">
                        将部分功能切换到 Rust 高性能后端。切换后需要确保 Rust 服务正在运行。
                    </p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <div class="setting-row">
                            <div>
                                <div class="setting-label">API 后端</div>
                                <div class="setting-desc">php = 原有 PHP 处理，rust = Rust 微服务</div>
                            </div>
                            <select name="api_backend" style="padding:0.4rem;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-primary);color:var(--text-primary);font-size:0.85rem;">
                                <option value="php" <?php echo $apiBackend === 'php' ? 'selected' : ''; ?>>PHP（原有）</option>
                                <option value="rust" <?php echo $apiBackend === 'rust' ? 'selected' : ''; ?>>Rust（高性能）</option>
                            </select>
                        </div>
                        <div style="text-align:right;margin-top:1rem;">
                            <button type="submit" class="btn-primary">保存设置</button>
                        </div>
                    </form>
                    <?php if ($apiBackend === 'rust'): ?>
                        <div class="alert <?php echo $rustStatus === '运行中' ? 'alert-success' : 'alert-error'; ?>" style="margin-top:0.8rem;">
                            Rust 服务状态：<?php echo $rustStatus; ?>
                            <?php if ($rustStatus !== '运行中'): ?>
                                <br><small>请运行：cd /www/wwwroot/7890liuliu/rust-api && cargo run --release</small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php
                        // Redis 状态检测
                        $redisStatus = '未启动';
                        $redisOk = false;
                        try {
                            $r = new Redis();
                            $redisOk = $r->connect('127.0.0.1', 6379, 2);
                            if ($redisOk) {
                                $redisStatus = '运行中';
                                $redisInfo = $r->info('server');
                            }
                        } catch (Exception $e) {
                            $redisStatus = '连接失败: ' . $e->getMessage();
                        }
                    ?>
                    <div class="alert <?php echo $redisOk ? 'alert-success' : 'alert-error'; ?>" style="margin-top:0.8rem;">
                        Redis 状态：<?php echo $redisStatus; ?>
                        <?php if ($redisOk && isset($redisInfo['redis_version'])): ?>
                            <small>（v<?php echo $redisInfo['redis_version']; ?>，端口 6379，Session 已存入 Redis）</small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="content-card" style="margin-top:1rem;">
                    <h3>已迁移到 Rust + Redis 的功能</h3>
                    <ul style="color:var(--text-secondary);font-size:0.85rem;line-height:1.8;padding-left:1.2rem;">
                        <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> 文件上传 - <code>POST /api/upload</code>（带类型/大小验证）</li>
                        <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> 帖子查看（含评论分页） - <code>GET /api/post/{id}</code> <small>（Redis 缓存 5 分钟）</small></li>
                        <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg> 通知服务：未读计数 <small>（Redis 缓存 60 秒）</small>/ 列表 / 标记已读 / 创建</li>
                    </ul>
                    <div style="margin-top:0.8rem;padding:0.5rem;background:var(--bg-secondary);border-radius:4px;font-size:0.8rem;color:var(--text-secondary);">
                        <strong>PHP Session</strong>：已存入 Redis（<code>tcp://127.0.0.1:6379</code>）<br>
                        <strong>Rust API 端口</strong>：127.0.0.1:3001（内部通信，不对外开放）
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'auth_modal.php'; ?>

    <script>
        // AJAX 开关逻辑
        document.querySelectorAll('.switch-form').forEach(form => {
            const checkbox = form.querySelector('input[type="checkbox"]');
            checkbox.addEventListener('change', function(e) {
                e.preventDefault();
                const originalState = !this.checked;
                const formData = new FormData(form);
                formData.set('enable', this.checked ? '1' : '0');
                
                this.disabled = true;
                
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        this.checked = originalState;
                        alert(data.message || '操作失败');
                    }
                })
                .catch(err => {
                    this.checked = originalState;
                    alert('网络错误，请稍后重试');
                })
                .finally(() => {
                    this.disabled = false;
                });
            });
        });

        function updateSelectedCount() {
            const checked = document.querySelectorAll('input[name="selected_users[]"]:checked:not(:disabled)').length;
            const countSpan = document.getElementById('selectedCount');
            if (countSpan) countSpan.textContent = checked;
        }
        function validateSelection() {
            const checked = document.querySelectorAll('input[name="selected_users[]"]:checked:not(:disabled)').length;
            if (checked === 0) { alert('请至少选择一个可删除的用户（站长和当前用户不可选）'); return false; }
            return true;
        }
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('input[name="selected_users[]"]:not(:disabled)');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            updateSelectedCount();
        }
        document.querySelectorAll('input[name="selected_users[]"]').forEach(cb => cb.addEventListener('change', updateSelectedCount));
        updateSelectedCount();

        const imageUrlInput = document.getElementById('image_url');
        if (imageUrlInput) {
            const previewContainer = document.createElement('div');
            previewContainer.className = 'image-preview';
            previewContainer.style.display = 'none';
            previewContainer.id = 'urlImagePreview';
            imageUrlInput.parentNode.appendChild(previewContainer);
            imageUrlInput.addEventListener('input', function() {
                const url = this.value.trim();
                if (url) {
                    previewContainer.innerHTML = `<img src="${url}" alt="URL图片预览" onerror="this.style.display='none'">`;
                    previewContainer.style.display = 'block';
                } else {
                    previewContainer.style.display = 'none';
                }
            });
        }

        window.previewImage = function(input) {
            const newImagePreview = document.getElementById('newImagePreview');
            const currentImagePreview = document.getElementById('currentImagePreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    newImagePreview.innerHTML = `<img src="${e.target.result}" alt="新图片预览">`;
                    newImagePreview.style.display = 'block';
                    if (currentImagePreview) currentImagePreview.style.display = 'none';
                    const urlImagePreview = document.getElementById('urlImagePreview');
                    if (urlImagePreview) urlImagePreview.style.display = 'none';
                }
                reader.readAsDataURL(input.files[0]);
            }
        };

        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (this.querySelector('input[name="form_action"]')?.value === 'save_slide') {
                    const fileInput = document.getElementById('slideImage');
                    const imageUrlInput = document.getElementById('image_url');
                    if (!fileInput?.files[0] && !imageUrlInput?.value.trim()) {
                        e.preventDefault();
                        alert('请上传图片或输入图片URL！');
                        return;
                    }
                }
                const requiredFields = this.querySelectorAll('[required]');
                let isValid = true;
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.style.borderColor = '#e53e3e';
                    } else {
                        field.style.borderColor = 'var(--border-color)';
                    }
                });
                if (!isValid) {
                    e.preventDefault();
                    alert('请填写所有必填项！');
                }
            });
        });

        const uploadSection = document.getElementById('uploadSection');
        if (uploadSection) {
            uploadSection.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--accent-color)';
                this.style.backgroundColor = 'var(--link-hover-bg)';
            });
            uploadSection.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--border-color)';
                this.style.backgroundColor = 'var(--bg-secondary)';
            });
            uploadSection.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--border-color)';
                this.style.backgroundColor = 'var(--bg-secondary)';
                const fileInput = document.getElementById('slideImage');
                if (e.dataTransfer.files.length > 0) {
                    fileInput.files = e.dataTransfer.files;
                    previewImage(fileInput);
                }
            });
        }


    </script>
</body>
</html>