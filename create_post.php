<?php
// create_post.php - 统一发布/编辑帖子页面（支持图片和附件上传）
require_once __DIR__ . '/functions.php';

// 检查用户是否登录
if (!isLoggedIn()) {
    redirect(url('index'));
}

// 检查用户是否被封禁
if (isCurrentUserBanned()) {
    die('您的账号已被封禁，无法发布帖子。');
}

// 获取当前用户信息（用于主题）
$currentUser = getCurrentUser();
checkMaintenanceMode($currentUser);

// ===================== AJAX 上传处理 =====================

// 处理 AJAX 图片上传（用于插入编辑器）
if (isset($_FILES['upload_image']) && isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    while (ob_get_level() > 0) ob_end_clean();
    ob_start();
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json');

    try {
        $uploadResult = uploadFile($_FILES['upload_image'], 'image');
        ob_end_clean();
        if ($uploadResult['success']) {
            echo json_encode(['success' => true, 'url' => $uploadResult['file_url']]);
        } else {
            echo json_encode(['success' => false, 'message' => $uploadResult['message']]);
        }
    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => '上传失败：' . $e->getMessage()]);
    }
    exit;
}

// 处理 AJAX 附件上传（用于插入编辑器，支持多文件）
if (isset($_FILES['insert_attachments']) && isset($_POST['ajax']) && $_POST['ajax'] === '2') {
    while (ob_get_level() > 0) ob_end_clean();
    ob_start();
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json');

    try {
        $files = $_FILES['insert_attachments'];
        $results = [];
        if (is_array($files['name'])) {
            $fileCount = count($files['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $file = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i]
                    ];
                    $uploadResult = uploadFile($file, 'attachment');
                    if ($uploadResult['success']) {
                        $results[] = [
                            'success' => true,
                            'name' => $uploadResult['file_name'],
                            'url' => $uploadResult['file_url'],
                            'size' => $uploadResult['file_size']
                        ];
                    } else {
                        $results[] = [
                            'success' => false,
                            'name' => $file['name'],
                            'message' => $uploadResult['message']
                        ];
                    }
                } else {
                    $results[] = [
                        'success' => false,
                        'name' => $files['name'][$i],
                        'message' => '文件上传错误'
                    ];
                }
            }
        } else {
            if ($files['error'] === UPLOAD_ERR_OK) {
                $uploadResult = uploadFile($files, 'attachment');
                if ($uploadResult['success']) {
                    $results[] = [
                        'success' => true,
                        'name' => $uploadResult['file_name'],
                        'url' => $uploadResult['file_url'],
                        'size' => $uploadResult['file_size']
                    ];
                } else {
                    $results[] = [
                        'success' => false,
                        'name' => $files['name'],
                        'message' => $uploadResult['message']
                    ];
                }
            } else {
                $results[] = [
                    'success' => false,
                    'name' => $files['name'],
                    'message' => '文件上传错误'
                ];
            }
        }
        ob_end_clean();
        echo json_encode(['success' => true, 'results' => $results]);
    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => '上传失败：' . $e->getMessage()]);
    }
    exit;
}

// ===================== 普通页面逻辑 =====================

$postId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$isEdit = ($postId > 0);
$post = null;
$categorySlug = '';

if ($isEdit) {
    $post = getPostById($postId);
    if (!$post) die('帖子不存在！');
    if (!isAdmin() && $currentUser['id'] != $post['user_id']) die('无权编辑此帖子！');
    $categorySlug = $post['category_slug'];
} else {
    $categorySlug = isset($_GET['category']) ? trim($_GET['category']) : '';
    if (empty($categorySlug)) die('分类参数错误！');
}

$category = getCategoryBySlug($categorySlug);
if (!$category) die('分类不存在或参数错误！');

$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF令牌验证失败！']); exit; }
        die('CSRF令牌验证失败！');
    }
    
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    
    if (empty($title) || empty($content)) {
        $errorMessage = '标题和内容不能为空！';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $errorMessage]); exit; }
    } elseif (mb_strlen($title, 'UTF-8') > 255) {
        $errorMessage = '标题不能超过255个字符！';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $errorMessage]); exit; }
    } else {
        file_put_contents('/tmp/create_post_debug.txt', date('Y-m-d H:i:s') . ' entering POST handler, isEdit=' . ($isEdit?'true':'false') . ' postId=' . ($postId ?? 'null') . ' POST='.print_r($_POST,true).' GET='.print_r($_GET,true).PHP_EOL, FILE_APPEND);
        try {
            $pdo = getDbConnection();
            
            if ($isEdit) {
                $result = updatePost($postId, ['title' => $title, 'content' => $content]);
                if ($result['success']) {
                    $ocFinalUrl = url('post', ['id' => $postId]);
                    file_put_contents('/tmp/create_post_debug.txt', date('Y-m-d H:i:s') . ' edit success, redirect to: ' . $ocFinalUrl . ' isAjax=' . ($isAjax?'yes':'no') . ' POST='.print_r($_POST,true).' GET='.print_r($_GET,true).PHP_EOL, FILE_APPEND);
                    header('Location: ' . $ocFinalUrl);
                    exit;
                } else {
                    throw new Exception($result['message']);
                }
            } else {
                $postData = [
                    'user_id' => $currentUser['id'],
                    'category_slug' => $categorySlug,
                    'title' => $title,
                    'content' => $content
                ];
                $result = createPost($postData);
                if (!$result['success']) throw new Exception($result['message']);
                $newPostId = $result['post_id'];
                $ocFinalUrl = url('post', ['id' => $newPostId]);
                file_put_contents('/tmp/create_post_debug.txt', date('Y-m-d H:i:s') . ' create success, redirect to: ' . $ocFinalUrl . ' isAjax=' . ($isAjax?'yes':'no') . ' POST='.print_r($_POST,true).' GET='.print_r($_GET,true).PHP_EOL, FILE_APPEND);
                header('Location: ' . $ocFinalUrl);
                exit;
            }
        } catch (Exception $e) {
            file_put_contents('/tmp/create_post_debug.txt', date('Y-m-d H:i:s') . ' caught exception: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            
            $errorMessage = ($isEdit ? '更新失败：' : '发布失败：') . $e->getMessage();
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $errorMessage]); exit; }
        }
    }
}

$currentTitle = $isEdit ? $post['title'] : (isset($_POST['title']) ? $_POST['title'] : '');
$currentContent = $isEdit ? $post['content'] : (isset($_POST['content']) ? $_POST['content'] : '');

if ($isEdit && !empty($currentContent)) {
    $currentContent = preg_replace('/href="uploads\//', 'href="/uploads/', $currentContent);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isEdit ? '编辑帖子' : '发布帖子'; ?> - 主播模拟器论坛</title>
    <link rel="stylesheet" href="/css/style.css?v=1782016963">
    <link rel="stylesheet" href="/theme.css">
    <link rel="stylesheet" href="/assets/quill/quill.snow.css">
    <script src="/upload_manager.js"></script>
    <script src="/assets/quill/quill.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css">
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quill-image-resize-module@3.0.0/image-resize.min.js"></script>
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
    <?php } ?>
    <style data-page-style>
        #top-bar, #bottom-bar { display: none !important; }
        /* create_post.php 特有样式 */
        .create-post-header {
            background-color: var(--accent-color);
            color: white;
            padding: 0.75rem 1rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .create-post-header-container {
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .header-left { width: 40px; display: flex; align-items: center; }
        .back-btn {
            color: white;
            text-decoration: none;
            font-size: 1.8rem;
            line-height: 1;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }
        .back-btn:hover { background-color: rgba(255,255,255,0.2); }
        .header-title { font-size: 1.2rem; font-weight: 600; text-align: center; }
        .header-right { width: 40px; }
        .create-post-container { max-width: 100%; margin: 0 !important; padding: 0 !important; }
        .create-post-page.main-content { padding-left: 0 !important; padding-right: 0 !important; padding-top: 0 !important; }
        .form-group { margin-bottom: 0.75rem; padding: 0; }
        .create-post-card { padding: 0; }
        .create-post-card .form-group:first-child { margin-top: 0; }
        .character-count { font-size: 0.85rem; color: var(--text-secondary); text-align: right; margin-top: 0.25rem; }
        .character-count.warning { color: #e53e3e; }
        /* Quill 编辑器样式 */
        .ql-container {
            font-family: inherit;
            font-size: 1rem;
            line-height: 1.6;
            border: 2px solid var(--border-color) !important;
            border-top: none !important;
        }
        .ql-editor {
            min-height: 250px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        .ql-editor:focus {
            border-color: var(--accent-color);
        }
        .ql-snow .ql-toolbar {
            background: var(--bg-secondary);
            border: 2px solid var(--border-color) !important;
            border-bottom: none !important;
        }
        .ql-snow .ql-toolbar button {
            color: var(--text-primary);
            transition: all 0.2s;
        }
        .ql-snow .ql-toolbar button:hover {
            color: var(--accent-color);
        }
        .ql-snow .ql-toolbar button.ql-active {
            color: var(--accent-color);
        }
        .ql-snow .ql-stroke { stroke: currentColor; }
        .ql-snow .ql-fill { fill: currentColor; }
        .ql-snow.ql-toolbar button:hover .ql-stroke,
        .ql-snow .ql-toolbar button:hover .ql-stroke { stroke: var(--accent-color); }
        .ql-snow.ql-toolbar button.ql-active .ql-stroke,
        .ql-snow .ql-toolbar button.ql-active .ql-stroke { stroke: var(--accent-color); }
        .ql-snow .ql-picker { color: var(--text-primary); }
        .ql-snow .ql-picker:hover .ql-picker-label { color: var(--accent-color); }
        .ql-snow .ql-picker.ql-expanded .ql-picker-label { color: var(--accent-color); border-color: var(--border-color); }
        .ql-snow .ql-picker-options {
            background: var(--bg-primary);
            border-color: var(--border-color);
        }
        .ql-snow .ql-picker.ql-expanded .ql-picker-label { border-color: var(--border-color); }
        .ql-editor img { max-width: 100%; border-radius: 0; }
        .ql-editor .ql-formula { cursor: pointer; background: var(--bg-secondary); padding: 0 4px; border-radius: 3px; }
        .ql-editor .ql-formula:hover { background: var(--link-hover-bg); }
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding: 1rem 1rem 2rem;
            border-top: 1px solid var(--border-color);
        }
        .btn-cancel {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 0.75rem 1.5rem;
            border-radius: 0;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background-color 0.2s, transform 0.2s;
        }
        .btn-cancel:hover { background-color: var(--link-hover-bg); transform: translateY(-1px); }

        /* 输入框模板样式 */
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

        @media (max-width: 768px) {
            .form-group { padding: 0; }
            .form-actions { padding: 1rem 0.75rem 2rem; }
        }
        @media (max-width: 480px) {
            .form-group { padding: 0; }
            .form-actions { padding: 1rem 0.5rem 2rem; }
        }
        .font-trigger-label {
            flex: 1;
            text-align: left;
        }
    </style>
    <script src="/theme.js"></script>
</head>
<body>
    <?php $hideTopBar = true; $hideBottomBar = true; include __DIR__ . '/header.php'; ?>
    <div id="page-content">
    <div class="create-post-header">
        <div class="create-post-header-container">
            <div class="header-left">
                <a href="<?php echo $isEdit ? url('post', ['id' => $postId]) : url('category', ['slug' => $categorySlug]); ?>" data-nav-url="<?php echo $isEdit ? url('post', ['id' => $postId]) : url('category', ['slug' => $categorySlug]); ?>" data-tab="home" class="back-btn" aria-label="返回">←</a>
            </div>
            <div class="header-title"><?php echo $isEdit ? '编辑帖子' : '发布新帖子'; ?></div>
            <div class="header-right"></div>
        </div>
    </div>

    <main class="main-content create-post-page">
        <div class="create-post-container">
            <?php if ($errorMessage): ?>
                <div class="alert alert-error"><?php echo escape($errorMessage); ?></div>
            <?php endif; ?>
            <?php if ($successMessage): ?>
                <div class="alert alert-success"><?php echo escape($successMessage); ?></div>
            <?php endif; ?>
            
            <div class="create-post-card">
                <form method="POST" enctype="multipart/form-data" id="postForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    
                    <div class="form-group">
                        <input type="text" id="title" name="title" class="input" 
                               placeholder="请输入帖子标题" required maxlength="255"
                               value="<?php echo escape($currentTitle); ?>">
                        <div class="character-count" id="titleCount">0/255</div>
                    </div>
                    
                    <div class="form-group">
                        <div id="quill-editor" style="min-height:280px;"></div>
                        <input type="hidden" name="content" id="quill-content" value="<?php echo escape($currentContent); ?>">
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-primary" style="width: auto; padding: 0.75rem 2rem; border-radius: 8px;"><?php echo $isEdit ? '保存修改' : '发布帖子'; ?></button>
                    </div>
                </form>
            </div>
            
                    <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem;">
                        <button type="button" id="uploadImageBtn" class="btn-secondary" style="font-size:0.85rem;padding:0.5rem 1rem;"> 上传图片</button>
                        <button type="button" id="uploadAttachmentsBtn" class="btn-secondary" style="font-size:0.85rem;padding:0.5rem 1rem;"> 插入附件</button>
                    </div>
                    <input type="file" id="uploadImageInput" accept="image/*" style="display:none;">
                    <input type="file" id="insertAttachmentsInput" multiple style="display:none;">
            <div style="margin-top: 2rem; padding: 1rem; background: var(--bg-secondary); border-radius: 0;">
                <h3 style="color: var(--text-primary); margin-bottom: 0.5rem; font-size: 0.95rem;">发帖须知：</h3>
                <ul style="color: var(--text-secondary); font-size: 0.85rem; line-height: 1.6; padding-left: 1.2rem; margin: 0;">
                    <li>请遵守社区规则，发布健康、积极的内容</li>
                    <li>请勿发布广告、垃圾信息或违规内容</li>
                    <li>图片和附件大小均不能超过5MB</li>
                    <li>上传文件时会显示进度条，请等待上传完成后再提交帖子</li>
                    <li>帖子发布后可能需要管理员审核</li>
                </ul>
            </div>
        </div>
    </main>
    
</div><!-- /page-content -->
    <script>
    // 标题字数计数
    var titleInput = document.getElementById('title');
    var titleCount = document.getElementById('titleCount');
    function updateTitleCount() {
        var len = titleInput.value.length;
        titleCount.textContent = len + '/255';
        titleCount.classList.toggle('warning', len > 229);
    }
    titleInput.addEventListener('input', updateTitleCount);
    updateTitleCount();
    </script>
    <script>
    // 上传图片到 Trix 编辑器
    // 初始化 Quill 编辑器
    var quill = new Quill('#quill-editor', {
        theme: 'snow',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                ['blockquote', 'code-block', 'link'],
                [{ 'script': 'sub'}, { 'script': 'super' }],
                ['clean']
            ]
        },
        placeholder: '请输入帖子内容...'
    });
    
    // 加载已有内容
    var existingContent = document.getElementById('quill-content').value;
    if (existingContent) {
        quill.root.innerHTML = existingContent;
    }
    
    // 提交时同步内容到隐藏域
    var form = quill.root.closest('form');
    if (form) {
        form.addEventListener('submit', function() {
            var contentInput = document.getElementById('quill-content');
            contentInput.value = quill.root.innerHTML;
        });
    }
    
    // 公式按钮（KaTeX 弹窗）
    var formulaBtn = document.createElement('button');
    formulaBtn.innerHTML = '∑';
    formulaBtn.title = '插入公式 (LaTeX)';
    formulaBtn.type = 'button';
    formulaBtn.className = 'btn-secondary';
    formulaBtn.style.cssText = 'font-size:0.85rem;padding:0.5rem 1rem;font-weight:bold;';
    
    var btnContainer = document.getElementById('uploadImageBtn').parentNode;
    btnContainer.appendChild(formulaBtn);
    
    formulaBtn.addEventListener('click', function() {
        var latex = prompt('请输入 LaTeX 公式：', 'E = mc^2');
        if (latex) {
            try {
                var html = katex.renderToString(latex, {
                    throwOnError: false,
                    displayMode: false
                });
                var range = quill.getSelection();
                if (range) {
                    quill.clipboard.dangerouslyPasteHTML(range.index, html);
                } else {
                    quill.clipboard.dangerouslyPasteHTML(quill.getLength(), html);
                }
            } catch(e) {
                alert('公式语法错误：' + e.message);
            }
        }
    });
    
    // 上传图片
    document.getElementById('uploadImageBtn').addEventListener('click', function() {
        document.getElementById('uploadImageInput').click();
    });
    document.getElementById('uploadImageInput').addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) { alert('请选择图片文件！'); return; }
        if (file.size > 5 * 1024 * 1024) { alert('图片大小不能超过5MB！'); return; }
        
        var progressDiv = document.createElement('div');
        progressDiv.className = 'upload-progress';
        progressDiv.innerHTML = '<div class="upload-progress-bar"></div><div class="upload-status">上传中 0%</div>';
        document.querySelector('.ql-editor').parentNode.insertBefore(progressDiv, document.querySelector('.ql-editor').nextSibling);
        
        uploadFile(file, '/create_post.php', function(fd) {
            fd.append('upload_image', file);
            fd.append('ajax', '1');
        }, function(pct) {
            var bar = progressDiv.querySelector('.upload-progress-bar');
            var status = progressDiv.querySelector('.upload-status');
            bar.style.width = pct + '%';
            status.textContent = '上传中 ' + pct + '%';
        }, function(resp) {
            progressDiv.remove();
            var range = quill.getSelection(true);
            quill.clipboard.dangerouslyPasteHTML(range ? range.index : 0, '<img src="' + resp.url + '" style="max-width:100%">');
        }, function(err) {
            progressDiv.remove();
            alert('上传失败：' + err);
        });
        e.target.value = '';
    });
    
    // 上传附件
    document.getElementById('uploadAttachmentsBtn').addEventListener('click', function() {
        document.getElementById('insertAttachmentsInput').click();
    });
    document.getElementById('insertAttachmentsInput').addEventListener('change', function(e) {
        var files = e.target.files;
        if (!files || files.length === 0) return;
        for (var i = 0; i < files.length; i++) {
            if (files[i].size > 5 * 1024 * 1024) {
                alert('文件 "' + files[i].name + '" 大小超过5MB限制！');
                return;
            }
        }
        
        var progressDiv = document.createElement('div');
        progressDiv.className = 'upload-progress';
        progressDiv.innerHTML = '<div class="upload-progress-bar"></div><div class="upload-status">上传附件中...</div>';
        document.querySelector('.ql-editor').parentNode.insertBefore(progressDiv, document.querySelector('.ql-editor').nextSibling);
        
        var completed = 0;
        var total = files.length;
        
        for (var i = 0; i < files.length; i++) {
            (function(file) {
                uploadFile(file, '/create_post.php', function(fd) {
                    fd.append('insert_attachments[]', file);
                    fd.append('ajax', '2');
                }, function(pct) {
                    var overall = Math.floor((completed + pct/100) / total * 100);
                    var bar = progressDiv.querySelector('.upload-progress-bar');
                    var status = progressDiv.querySelector('.upload-status');
                    bar.style.width = overall + '%';
                    status.textContent = '上传中 ' + overall + '% (' + (completed+1) + '/' + total + ')';
                }, function(resp) {
                    completed++;
                    if (resp.results && resp.results.length) {
                        resp.results.forEach(function(item) {
                            if (item.success) {
                                var html = '<a href="' + item.url + '" download="' + item.name + '">' + item.name + '</a>';
                                var range = quill.getSelection(true);
                                quill.clipboard.dangerouslyPasteHTML(range ? range.index : 0, html);
                            } else {
                                alert('文件 "' + item.name + '" 上传失败：' + item.message);
                            }
                        });
                    }
                    if (completed === total) progressDiv.remove();
                }, function(err) {
                    progressDiv.remove();
                    alert('上传失败：' + err);
                });
            })(files[i]);
        }
        e.target.value = '';
    });
    </script>
    <?php include 'auth_modal.php'; ?>
    <?php include 'spa.php'; ?>
</body>
</html>