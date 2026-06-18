<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => '请求方法错误']);
    exit;
}

$data = getJsonInput();

if (!$data || !isset($data['username']) || !isset($data['password'])) {
    echo json_encode(['error' => '请填写用户名和密码']);
    exit;
}

$username = $data['username'];
$password = $data['password'];

try {
    // 查找用户
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['error' => '用户名或密码错误']);
        exit;
    }

    // 验证密码
    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(['error' => '用户名或密码错误']);
        exit;
    }

    // 检查是否被封禁
    if (!empty($user['is_banned'])) {
        echo json_encode(['error' => '账号已被封禁，请联系管理员']);
        exit;
    }

    $token = generateToken($user['id']);

    echo json_encode([
        'success' => true,
        'message' => '登录成功',
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username']
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => '登录失败: ' . $e->getMessage()]);
}
?>
