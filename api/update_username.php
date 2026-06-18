<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

$token = getAuthHeader();
if (!$token) {
    echo json_encode(['error' => '未授权']);
    exit;
}

$userId = verifyToken($token);
if (!$userId) {
    echo json_encode(['error' => '无效的token']);
    exit;
}

$data = getJsonInput();
if (!$data || !isset($data['username'])) {
    echo json_encode(['error' => '请填写新用户名']);
    exit;
}

$newUsername = trim($data['username']);

if (strlen($newUsername) < 2 || strlen($newUsername) > 20) {
    echo json_encode(['error' => '用户名长度需要2-20个字符']);
    exit;
}

try {
    // 检查用户名是否已存在
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$newUsername, $userId]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => '用户名已被使用']);
        exit;
    }

    // 更新用户名
    $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
    $stmt->execute([$newUsername, $userId]);

    echo json_encode([
        'success' => true,
        'username' => $newUsername
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => '修改失败: ' . $e->getMessage()]);
}
?>
