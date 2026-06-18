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

if (!$input || !isset($input['password'])) {
    echo json_encode(['error' => '请提供密码']);
    exit;
}

$password = $input['password'];

try {
    // 验证密码
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['error' => '用户不存在']);
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(['error' => '密码不正确']);
        exit;
    }

    // 删除分红记录（因为有外键约束，需要先删分红）
    $stmt = $pdo->prepare("
        DELETE d FROM dividends d
        JOIN holdings h ON d.holding_id = h.id
        WHERE h.user_id = ?
    ");
    $stmt->execute([$userId]);

    // 删除持仓记录
    $stmt = $pdo->prepare("DELETE FROM holdings WHERE user_id = ?");
    $stmt->execute([$userId]);

    echo json_encode(['success' => true, 'message' => '所有数据已清空']);

} catch (PDOException $e) {
    echo json_encode(['error' => '清空数据失败: ' . $e->getMessage()]);
}
?>
