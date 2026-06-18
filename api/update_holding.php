<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

$data = getJsonInput();

if (!$data || !isset($data['buy_price']) || !isset($data['quantity'])) {
    echo json_encode(['error' => '请填写买入价格和数量']);
    exit;
}

$buyPrice = $data['buy_price'];
$quantity = $data['quantity'];
$buyDate = isset($data['buy_date']) ? $data['buy_date'] : null;
$notes = isset($data['notes']) ? $data['notes'] : null;

try {
    // 验证持仓是否属于当前用户
    $stmt = $pdo->prepare("SELECT id FROM holdings WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) {
        echo json_encode(['error' => '持仓不存在']);
        exit;
    }

    // 更新持仓
    $stmt = $pdo->prepare("UPDATE holdings SET buy_price = ?, quantity = ?, buy_date = ?, notes = ? WHERE id = ?");
    $stmt->execute([$buyPrice, $quantity, $buyDate, $notes, $id]);

    // 返回更新后的持仓
    $stmt = $pdo->prepare("
        SELECT h.*, s.symbol, s.name, s.current_price, s.dividend_yield
        FROM holdings h
        JOIN stocks s ON h.stock_id = s.id
        WHERE h.id = ?
    ");
    $stmt->execute([$id]);
    $holding = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => '持仓更新成功',
        'holding' => $holding
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => '更新持仓失败: ' . $e->getMessage()]);
}
?>
