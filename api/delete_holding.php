<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(['error' => '请求方法错误']);
    exit;
}

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

// 从URL获取ID
$id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$id) {
    echo json_encode(['error' => '缺少持仓ID']);
    exit;
}

try {
    // 验证持仓是否属于当前用户
    $stmt = $pdo->prepare("SELECT id FROM holdings WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) {
        echo json_encode(['error' => '持仓不存在']);
        exit;
    }

    // 删除持仓
    $stmt = $pdo->prepare("DELETE FROM holdings WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode([
        'success' => true,
        'message' => '持仓已删除'
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => '删除持仓失败: ' . $e->getMessage()]);
}
?>
