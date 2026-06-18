<?php
// 数据库配置 — 请在部署时替换为真实值
$host = 'localhost';
$port = 3306;
$dbname = 'YOUR_DB_NAME';
$username = 'YOUR_DB_USER';
$password = 'YOUR_DB_PASSWORD';

// 创建数据库连接
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['error' => '数据库连接失败: ' . $e->getMessage()]));
}

// 设置CORS头
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// 获取JSON输入
function getJsonInput() {
    $json = file_get_contents('php://input');
    return json_decode($json, true);
}

// 生成token
function generateToken($userId) {
    return base64_encode($userId . '_' . time() . '_' . uniqid());
}

// 验证token
function verifyToken($token) {
    if (!$token) return false;
    $decoded = base64_decode($token);
    $parts = explode('_', $decoded);
    if (count($parts) >= 2) {
        return $parts[0];
    }
    return false;
}

// 获取请求头（兼容不同服务器）
function getAuthHeader() {
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) == 'HTTP_') {
                $headerKey = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerKey] = $value;
            }
        }
    }
    return isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : '';
}

// 确保 dividends 表存在
function ensureDividendsTable($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dividends (
            id INT PRIMARY KEY AUTO_INCREMENT,
            holding_id INT NOT NULL,
            dividend_date DATE NOT NULL,
            amount_per_share DECIMAL(10,4) NOT NULL,
            quantity INT NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            notes VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (holding_id) REFERENCES holdings(id) ON DELETE CASCADE
        )");
    } catch (PDOException $e) {
        // 忽略错误
    }
}
?>
