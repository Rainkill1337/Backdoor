<?php
// 返回 403，禁止访问。
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>403 Forbidden</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .error {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        h1 {
            color: #d32f2f;
            font-size: 48px;
            margin: 0 0 20px 0;
        }
        p {
            color: #666;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <div class="error">
        <h1>403</h1>
        <p>Forbidden</p>
        <hr>
        <p style="font-size: 14px; color: #999;">nginx/<?php echo mt_rand(1,2); ?>.<?php echo mt_rand(10,20); ?>.<?php echo mt_rand(1,9); ?></p>
    </div>
</body>
</html>
<?php
// 閸氬骸褰寸拋鏉跨秿鐠佸潡妫堕弮銉ョ箶
require_once 'config.php';
$logger = Logger::getInstance();
$logger->warning('Unauthorized index access', [
    'ip' => $_SERVER['REMOTE_ADDR'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
]);
?>

