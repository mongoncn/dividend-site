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

try {
    // 获取用户信息
    $stmt = $pdo->prepare("SELECT id, username, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['error' => '用户不存在']);
        exit;
    }

    // 获取持仓数量
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM holdings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $holdingCount = $stmt->fetchColumn();

    // 获取分红记录数
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM dividends d
        JOIN holdings h ON d.holding_id = h.id
        WHERE h.user_id = ?
    ");
    $stmt->execute([$userId]);
    $dividendCount = $stmt->fetchColumn();

    // 获取累计分红金额
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.total_amount), 0) FROM dividends d
        JOIN holdings h ON d.holding_id = h.id
        WHERE h.user_id = ?
    ");
    $stmt->execute([$userId]);
    $totalDividends = $stmt->fetchColumn();

    echo json_encode([
        'id' => $user['id'],
        'username' => $user['username'],
        'created_at' => $user['created_at'],
        'holdingCount' => (int)$holdingCount,
        'dividendCount' => (int)$dividendCount,
        'totalDividends' => $totalDividends
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => '获取用户信息失败: ' . $e->getMessage()]);
}
?>
