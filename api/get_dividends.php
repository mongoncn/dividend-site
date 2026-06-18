<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

$token = getAuthHeader();
if (!$token) { echo json_encode(['error' => '未授权']); exit; }

$userId = verifyToken($token);
if (!$userId) { echo json_encode(['error' => '无效的token']); exit; }

// 确保表存在
ensureDividendsTable($pdo);

$holdingId = isset($_GET['holding_id']) ? $_GET['holding_id'] : null;

try {
    if ($holdingId) {
        $stmt = $pdo->prepare("
            SELECT d.*, s.symbol, s.name
            FROM dividends d
            JOIN holdings h ON d.holding_id = h.id
            JOIN stocks s ON h.stock_id = s.id
            WHERE d.holding_id = ? AND h.user_id = ?
            ORDER BY d.dividend_date DESC
        ");
        $stmt->execute([$holdingId, $userId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT d.*, s.symbol, s.name
            FROM dividends d
            JOIN holdings h ON d.holding_id = h.id
            JOIN stocks s ON h.stock_id = s.id
            WHERE h.user_id = ?
            ORDER BY d.dividend_date DESC
        ");
        $stmt->execute([$userId]);
    }

    $dividends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalDividend = 0;
    foreach ($dividends as $d) {
        $totalDividend += floatval($d['total_amount']);
    }

    echo json_encode([
        'success' => true,
        'dividends' => $dividends,
        'totalDividend' => $totalDividend
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => true,
        'dividends' => [],
        'totalDividend' => 0
    ]);
}
?>
