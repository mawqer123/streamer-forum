<?php
// bottom_nav.php - 底部导航（TS渲染DOM，PHP只在首页传递数据）
$active_bottom = isset($active_bottom) ? $active_bottom : 'home';

// 只保留脚本部分和 SPA
?>
<!-- TS 渲染的底栏容器 -->
<nav id="bottom-bar" class="bottom-nav"></nav>

<?php include 'spa.php'; ?>
