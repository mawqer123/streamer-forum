<?php
// captcha.php - 增强型图片验证码，防机器人

// ========== 与 functions.php 保持一致的 Session 配置（支持Redis） ==========
$redisSessionEnabled = false;
if (extension_loaded('redis')) {
    // 从配置文件读取Redis配置（可选，如果config.php有定义则使用）
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    }
    $redisHost = defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1';
    $redisPort = defined('REDIS_PORT') ? REDIS_PORT : 6379;
    $redisPrefix = defined('REDIS_SESSION_PREFIX') ? REDIS_SESSION_PREFIX : 'session:';
    try {
        $testRedis = new Redis();
        if (@$testRedis->connect($redisHost, $redisPort)) {
            $redisSessionEnabled = true;
            ini_set('session.save_handler', 'redis');
            ini_set('session.save_path', "tcp://{$redisHost}:{$redisPort}?prefix={$redisPrefix}");
        }
        unset($testRedis);
    } catch (Exception $e) {
        $redisSessionEnabled = false;
    }
}

if (!$redisSessionEnabled) {
    ini_set('session.cookie_lifetime', 31536000);
    ini_set('session.gc_maxlifetime', 31536000);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 1000);

    $sessionPath = __DIR__ . '/sessions';
    if (!file_exists($sessionPath)) {
        @mkdir($sessionPath, 0777, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
}

session_name('streamer_forum_session');
// =============================================================

session_start();

// 使用安全的随机数生成器
try {
    $random_int = random_int(0, PHP_INT_MAX);
} catch (Exception $e) {
    $random_int = mt_rand();
}

// 随机长度 4-6 位
$length = mt_rand(4, 6);
// 字符集排除易混淆字符 (0, O, 1, l, I, etc.)
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
$captcha = '';
$maxIndex = strlen($chars) - 1;
for ($i = 0; $i < $length; $i++) {
    $captcha .= $chars[mt_rand(0, $maxIndex)];
}
$_SESSION['captcha'] = $captcha;

$width = 150;
$height = 50;
$image = imagecreatetruecolor($width, $height);

// 背景色 - 随机浅色
$bg_r = mt_rand(220, 255);
$bg_g = mt_rand(220, 255);
$bg_b = mt_rand(220, 255);
$bgColor = imagecolorallocate($image, $bg_r, $bg_g, $bg_b);
imagefill($image, 0, 0, $bgColor);

// 添加渐变背景效果（可选，增加复杂度）
for ($i = 0; $i < $height; $i++) {
    $gradient = imagecolorallocate($image, $bg_r - mt_rand(0, 30), $bg_g - mt_rand(0, 30), $bg_b - mt_rand(0, 30));
    imageline($image, 0, $i, $width, $i, $gradient);
}

// 绘制干扰弧线/曲线 (正弦波风格)
for ($i = 0; $i < 8; $i++) {
    $curveColor = imagecolorallocate($image, mt_rand(100, 200), mt_rand(100, 200), mt_rand(100, 200));
    // 随机贝塞尔曲线或弧线
    imagearc($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(30, 100), mt_rand(30, 100), mt_rand(0, 360), mt_rand(0, 360), $curveColor);
}

// 干扰线条 (直线)
for ($i = 0; $i < 6; $i++) {
    $lineColor = imagecolorallocate($image, mt_rand(80, 180), mt_rand(80, 180), mt_rand(80, 180));
    imageline($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), $lineColor);
}

// 干扰像素点 (增加噪点)
for ($i = 0; $i < 400; $i++) {
    $pixelColor = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
    imagesetpixel($image, mt_rand(0, $width), mt_rand(0, $height), $pixelColor);
}

// 干扰矩形块 (随机小方块)
for ($i = 0; $i < 15; $i++) {
    $rectColor = imagecolorallocate($image, mt_rand(150, 220), mt_rand(150, 220), mt_rand(150, 220));
    imagerectangle($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), $rectColor);
}

// 字体设置 - 优先使用多个随机字体，增强防识别
$fontDir = __DIR__ . '/assets/fonts/'; // 可放置多个 .ttf 字体在此目录
$fontFiles = glob($fontDir . '*.ttf');
$useTTF = !empty($fontFiles) && file_exists($fontFiles[0]);

if ($useTTF) {
    // 随机选择一个字体
    $fontFile = $fontFiles[array_rand($fontFiles)];
    // 每个字符单独绘制，应用随机旋转、偏移和大小
    $charSpacing = $width / ($length + 1);
    $startX = $charSpacing / 2;
    for ($i = 0; $i < $length; $i++) {
        $charColor = imagecolorallocate($image, mt_rand(0, 80), mt_rand(0, 80), mt_rand(0, 80));
        $angle = mt_rand(-25, 25);
        $fontSize = mt_rand(20, 28);
        // 计算坐标并强制转为整数，避免 PHP 8.1+ 的隐式转换警告
        $x = (int) ($startX + $i * $charSpacing + mt_rand(-3, 3));
        $y = (int) mt_rand($height - 20, $height - 12);
        imagettftext($image, $fontSize, $angle, $x, $y, $charColor, $fontFile, $captcha[$i]);
    }
} else {
    // 如果没有TTF字体，降级使用内置字体但增加扭曲效果
    $fontSize = 5; // 内置字体大小固定
    $textWidth = imagefontwidth($fontSize) * $length;
    $textHeight = imagefontheight($fontSize);
    $x = (int) (($width - $textWidth) / 2);
    $y = (int) (($height - $textHeight) / 2);
    // 绘制多个干扰层再写文字
    for ($i = 0; $i < $length; $i++) {
        $charColor = imagecolorallocate($image, mt_rand(0, 100), mt_rand(0, 100), mt_rand(0, 100));
        $offsetX = (int) ($x + $i * imagefontwidth($fontSize) + mt_rand(-2, 2));
        $offsetY = (int) ($y + mt_rand(-2, 2));
        imagestring($image, $fontSize, $offsetX, $offsetY, $captcha[$i], $charColor);
    }
    // 添加额外的波浪干扰 (像素级扭曲)
    $tempImage = imagecreatetruecolor($width, $height);
    imagecopy($tempImage, $image, 0, 0, 0, 0, $width, $height);
    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            $rgb = imagecolorat($tempImage, $x, $y);
            $newX = (int) ($x + sin($y / 10) * 2);
            $newY = (int) ($y + cos($x / 10) * 2);
            if ($newX >= 0 && $newX < $width && $newY >= 0 && $newY < $height) {
                imagesetpixel($image, $newX, $newY, $rgb);
            }
        }
    }
    // 移除 imagedestroy() 调用（PHP 8.0+ 中无实际效果且会产生弃用警告）
}

// 最后增加一层半透明噪点 (覆盖层)
$noiseLayer = imagecreatetruecolor($width, $height);
imagefill($noiseLayer, 0, 0, imagecolorallocatealpha($noiseLayer, 0, 0, 0, 127));
for ($i = 0; $i < 500; $i++) {
    $noiseColor = imagecolorallocatealpha($noiseLayer, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(70, 100));
    imagesetpixel($noiseLayer, mt_rand(0, $width), mt_rand(0, $height), $noiseColor);
}
imagecopy($image, $noiseLayer, 0, 0, 0, 0, $width, $height);
// 移除 imagedestroy($noiseLayer);  PHP 8.0+ 中无需手动销毁

// 输出图像
header('Content-Type: image/png');
imagepng($image);
// 移除 imagedestroy($image);  PHP 8.0+ 中无需手动销毁
?>