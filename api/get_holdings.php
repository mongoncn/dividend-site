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

try {
    // 一次查询：持仓 + 分红总额
    $stmt = $pdo->prepare("
        SELECT h.id, h.buy_price, h.quantity, h.buy_date, h.notes,
               s.symbol, s.name, s.current_price, s.dividend_yield,
               COALESCE(d.total_dividends, 0) as totalDividends
        FROM holdings h
        JOIN stocks s ON h.stock_id = s.id
        LEFT JOIN (
            SELECT holding_id, SUM(total_amount) as total_dividends
            FROM dividends GROUP BY holding_id
        ) d ON d.holding_id = h.id
        WHERE h.user_id = ?
        ORDER BY h.created_at DESC
    ");
    $stmt->execute([$userId]);
    $holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($holdings as $holding) {
        $totalDividends = floatval($holding['totalDividends']);
        $originalCost = $holding['buy_price'] * $holding['quantity'];
        $costBasis = $originalCost - $totalDividends;
        $adjustedBuyPrice = $holding['quantity'] > 0 ? $costBasis / $holding['quantity'] : 0;
        $currentValue = $holding['current_price'] * $holding['quantity'];
        $annualDividend = $currentValue * ($holding['dividend_yield'] / 100);
        $yieldOnCost = $costBasis > 0 ? ($annualDividend / $costBasis) * 100 : 0;

        $result[] = [
            'id' => (int)$holding['id'],
            'symbol' => $holding['symbol'],
            'name' => $holding['name'],
            'buy_price' => $holding['buy_price'],
            'adjusted_buy_price' => $adjustedBuyPrice,
            'quantity' => (int)$holding['quantity'],
            'buy_date' => $holding['buy_date'],
            'notes' => $holding['notes'],
            'current_price' => $holding['current_price'],
            'dividend_yield' => $holding['dividend_yield'],
            'totalDividends' => $totalDividends,
            'costBasis' => $costBasis,
            'currentValue' => $currentValue,
            'annualDividend' => $annualDividend,
            'yieldOnCost' => $yieldOnCost,
            'profit' => $currentValue - $costBasis,
            'profitPercentage' => $costBasis > 0 ? (($currentValue - $costBasis) / $costBasis) * 100 : 0
        ];
    }

    echo json_encode($result);

} catch (PDOException $e) {
    echo json_encode(['error' => '获取持仓失败: ' . $e->getMessage()]);
}
?>