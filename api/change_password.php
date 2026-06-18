<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

// 获取token
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

// 获取输入
$input = getJsonInput();

if (!$input || !isset($input['current_password']) || !isset($input['new_password'])) {
    echo json_encode(['error' => '请提供当前密码和新密码']);
    exit;
}

$currentPassword = $input['current_password'];
$newPassword = $input['new_password'];

if (strlen($newPassword) < 6) {
    echo json_encode(['error' => '新密码至少需要6位']);
    exit;
}

try {
    // 获取当前密码hash
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['error' => '用户不存在']);
        exit;
    }

    // 验证当前密码
    if (!password_verify($currentPassword, $user['password_hash'])) {
        echo json_encode(['error' => '当前密码不正确']);
        exit;
    }

    // 更新密码
    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$newPasswordHash, $userId]);

    echo json_encode(['success' => true, 'message' => '密码修改成功']);

} catch (PDOException $e) {
    echo json_encode(['error' => '修改密码失败: ' . $e->getMessage()]);
}
?>
