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
        SELECT h.buy_price, h.quantity, s.current_price, s.dividend_yield,
               COALESCE(d.total_dividends, 0) as totalDividends
        FROM holdings h
        JOIN stocks s ON h.stock_id = s.id
        LEFT JOIN (
            SELECT holding_id, SUM(total_amount) as total_dividends
            FROM dividends GROUP BY holding_id
        ) d ON d.holding_id = h.id
        WHERE h.user_id = ?
    ");
    $stmt->execute([$userId]);
    $holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalCost = 0;
    $totalValue = 0;
    $totalAnnualDividend = 0;
    $totalDividendsReceived = 0;

    foreach ($holdings as $holding) {
        $dividendsReceived = floatval($holding['totalDividends']);
        $cost = ($holding['buy_price'] * $holding['quantity']) - $dividendsReceived;
        $value = $holding['current_price'] * $holding['quantity'];
        $dividend = $value * ($holding['dividend_yield'] / 100);

        $totalCost += $cost;
        $totalValue += $value;
        $totalAnnualDividend += $dividend;
        $totalDividendsReceived += $dividendsReceived;
    }

    $totalProfit = $totalValue - $totalCost;
    $totalYieldOnCost = $totalCost > 0 ? ($totalAnnualDividend / $totalCost) * 100 : 0;
    $totalProfitPercentage = $totalCost > 0 ? ($totalProfit / $totalCost) * 100 : 0;

    echo json_encode([
        'totalCost' => $totalCost,
        'totalValue' => $totalValue,
        'totalProfit' => $totalProfit,
        'totalProfitPercentage' => $totalProfitPercentage,
        'totalAnnualDividend' => $totalAnnualDividend,
        'totalYieldOnCost' => $totalYieldOnCost,
        'totalDividendsReceived' => $totalDividendsReceived,
        'holdingCount' => count($holdings)
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => '获取收益汇总失败: ' . $e->getMessage()]);
}
?>