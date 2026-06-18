<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$data = getJsonInput();

if (!$data || !isset($data['holding_id']) || !isset($data['current_price'])) {
    echo json_encode(['error' => '请提供持仓ID和当前价格']);
    exit;
}

$holdingId = $data['holding_id'];
$currentPrice = $data['current_price'];
$dividendYield = isset($data['dividend_yield']) ? $data['dividend_yield'] : 0;

try {
    // 验证持仓是否属于当前用户
    $stmt = $pdo->prepare("
        SELECT h.id, h.stock_id
        FROM holdings h
        WHERE h.id = ? AND h.user_id = ?
    ");
    $stmt->execute([$holdingId, $userId]);
    $holding = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$holding) {
        echo json_encode(['error' => '持仓不存在']);
        exit;
    }

    // 更新股票价格和股息率
    $stmt = $pdo->prepare("UPDATE stocks SET current_price = ?, dividend_yield = ?, last_updated = NOW() WHERE id = ?");
    $stmt->execute([$currentPrice, $dividendYield, $holding['stock_id']]);

    echo json_encode([
        'success' => true,
        'message' => '价格和股息率更新成功'
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => '更新失败: ' . $e->getMessage()]);
}
?>
