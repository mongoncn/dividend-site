<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => '请求方法错误']);
    exit;
}

$data = getJsonInput();

if (!$data || !isset($data['username']) || !isset($data['password'])) {
    echo json_encode(['error' => '请填写所有必填字段']);
    exit;
}

$username = $data['username'];
$password = $data['password'];

try {
    // 检查用户名是否已存在
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => '用户名已存在']);
        exit;
    }

    // 加密密码
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // 创建用户
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmt->execute([$username, $passwordHash]);

    $userId = $pdo->lastInsertId();
    $token = generateToken($userId);

    echo json_encode([
        'success' => true,
        'message' => '注册成功',
        'token' => $token,
        'user' => [
            'id' => $userId,
            'username' => $username
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => '注册失败: ' . $e->getMessage()]);
}
?>
