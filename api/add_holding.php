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

if (!$data || !isset($data['symbol']) || !isset($data['buy_price']) || !isset($data['quantity'])) {
    echo json_encode(['error' => '请填写股票代码、买入价格和数量']);
    exit;
}

$symbol = strtoupper($data['symbol']);
$name = (!empty($data['name'])) ? $data['name'] : $symbol;
$buyPrice = $data['buy_price'];
$quantity = $data['quantity'];
$buyDate = isset($data['buy_date']) ? $data['buy_date'] : null;
$notes = isset($data['notes']) ? $data['notes'] : null;

// 当前价格默认等于买入价格，股息率默认为0
$currentPrice = $buyPrice;
$dividendYield = 0;

try {
    // 查找或创建股票
    $stmt = $pdo->prepare("SELECT id FROM stocks WHERE symbol = ?");
    $stmt->execute([$symbol]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($stock) {
        $stockId = $stock['id'];
        // 更新股票信息
        $stmt = $pdo->prepare("UPDATE stocks SET name = ?, current_price = ? WHERE id = ?");
        $stmt->execute([$name, $currentPrice, $stockId]);
    } else {
        // 创建新股票
        $stmt = $pdo->prepare("INSERT INTO stocks (symbol, name, market, current_price, dividend_yield, last_updated) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$symbol, $name, '未知', $currentPrice, $dividendYield]);
        $stockId = $pdo->lastInsertId();
    }

    // 添加持仓
    $stmt = $pdo->prepare("INSERT INTO holdings (user_id, stock_id, buy_price, quantity, buy_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $stockId, $buyPrice, $quantity, $buyDate, $notes]);

    $holdingId = $pdo->lastInsertId();

    // 返回新创建的持仓
    $stmt = $pdo->prepare("
        SELECT h.*, s.symbol, s.name, s.current_price, s.dividend_yield
        FROM holdings h
        JOIN stocks s ON h.stock_id = s.id
        WHERE h.id = ?
    ");
    $stmt->execute([$holdingId]);
    $holding = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => '持仓添加成功',
        'holding' => $holding
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => '添加持仓失败: ' . $e->getMessage()]);
}
?>
