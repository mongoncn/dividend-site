<?php
// 数据库配置
$host = 'localhost';
$port = 3306;
$dbname = 'YOUR_DB_NAME';
$username = 'YOUR_DB_USER';
$password = "YOUR_DB_PASSWORD";

// 创建数据库连接
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => '数据库连接失败']));
}

// 安全响应头
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// CORS：默认放行全部（部署简单）；生产环境建议设置环境变量 APP_ORIGIN 收紧到你的域名
$allowed_origin = getenv('APP_ORIGIN') ?: '';
if ($allowed_origin) {
    if (($_SERVER['HTTP_ORIGIN'] ?? '') === $allowed_origin) {
        header('Access-Control-Allow-Origin: ' . $allowed_origin);
        header('Access-Control-Allow-Credentials: true');
    }
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// 获取JSON输入
function getJsonInput() {
    $json = file_get_contents('php://input');
    return json_decode($json, true);
}

// 生成token：HMAC签名，防止伪造userId
function generateToken($userId) {
    $key = getenv('SX_TOKEN_KEY') ?: 'sx-fallback-secret';
    $payload = $userId . '|' . time() . '|' . bin2hex(random_bytes(8));
    $sig = hash_hmac('sha256', $payload, $key);
    return base64_encode($payload . '|' . $sig);
}

// 验证token：校验签名与时效
function verifyToken($token) {
    if (!$token) return false;
    $decoded = base64_decode($token);
    $parts = explode('|', $decoded);
    if (count($parts) !== 4) return false;
    list($userId, $ts, $rand, $sig) = $parts;
    if (!ctype_digit($userId) || !ctype_digit($ts)) return false;
    $key = getenv('SX_TOKEN_KEY') ?: 'sx-fallback-secret';
    $expect = hash_hmac('sha256', $userId . '|' . $ts . '|' . $rand, $key);
    if (!hash_equals($expect, $sig)) return false;
    if (time() - (int)$ts > 86400 * 30) return false;
    return (int)$userId;
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
    }
}