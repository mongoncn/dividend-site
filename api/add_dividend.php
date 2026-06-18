<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => '请求方法错误']);
    exit;
}

$token = getAuthHeader();
if (!$token) { echo json_encode(['error' => '未授权']); exit; }

$userId = verifyToken($token);
if (!$userId) { echo json_encode(['error' => '无效的token']); exit; }

// 确保表存在
ensureDividendsTable($pdo);

$data = getJsonInput();
if (!$data || !isset($data['holding_id']) || !isset($data['amount_per_share'])) {
    echo json_encode(['error' => '请填写分红信息']);
    exit;
}

$holdingId = intval($data['holding_id']);
$amountPerShare = floatval($data['amount_per_share']);
$dividendDate = isset($data['dividend_date']) ? $data['dividend_date'] : date('Y-m-d');
$notes = isset($data['notes']) ? $data['notes'] : null;

try {
    // 验证持仓属于当前用户
    $stmt = $pdo->prepare("SELECT h.quantity FROM holdings h WHERE h.id = ? AND h.user_id = ?");
    $stmt->execute([$holdingId, $userId]);
    $holding = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$holding) {
        echo json_encode(['error' => '持仓不存在']);
        exit;
    }

    $quantity = intval($holding['quantity']);
    $totalAmount = round($amountPerShare * $quantity, 2);

    $stmt = $pdo->prepare("INSERT INTO dividends (holding_id, dividend_date, amount_per_share, quantity, total_amount, notes) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$holdingId, $dividendDate, $amountPerShare, $quantity, $totalAmount, $notes]);

    echo json_encode([
        'success' => true,
        'message' => '分红记录添加成功',
        'dividend' => [
            'id' => $pdo->lastInsertId(),
            'holding_id' => $holdingId,
            'dividend_date' => $dividendDate,
            'amount_per_share' => $amountPerShare,
            'quantity' => $quantity,
            'total_amount' => $totalAmount,
            'notes' => $notes
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => '添加分红失败: ' . $e->getMessage()]);
}
?>
