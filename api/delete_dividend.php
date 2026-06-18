<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(['error' => '请求方法错误']);
    exit;
}

$token = getAuthHeader();
if (!$token) { echo json_encode(['error' => '未授权']); exit; }

$userId = verifyToken($token);
if (!$userId) { echo json_encode(['error' => '无效的token']); exit; }

$id = isset($_GET['id']) ? intval($_GET['id']) : null;
if (!$id) { echo json_encode(['error' => '缺少分红记录ID']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT d.id FROM dividends d
        JOIN holdings h ON d.holding_id = h.id
        WHERE d.id = ? AND h.user_id = ?
    ");
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) {
        echo json_encode(['error' => '记录不存在']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM dividends WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => '分红记录已删除']);

} catch (PDOException $e) {
    echo json_encode(['error' => '删除失败: ' . $e->getMessage()]);
}
?>
