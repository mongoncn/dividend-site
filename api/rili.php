<?php
/**
 * 分红日历代理
 * 必须登录；只向页面返回日历，不返回持仓结构（最小暴露）
 */
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

$token = getAuthHeader();
if (!$token || !verifyToken($token)) {
    http_response_code(401);
    echo json_encode(['error' => '未授权，请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 已收到的分红（来自数据库，原仪表盘的历史记录）
$uid = verifyToken($token);
$received = [];
try {
    $stmt = $pdo->prepare(
        "SELECT s.name, s.symbol, d.dividend_date, d.amount_per_share, d.quantity, d.total_amount
           FROM dividends d
           JOIN holdings h ON d.holding_id = h.id
           JOIN stocks  s ON h.stock_id  = s.id
          WHERE h.user_id = ?
          ORDER BY d.dividend_date DESC");
    $stmt->execute([$uid]);
    $received = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $received = [];
}

// 待收排期跟着仪表盘持仓走；为空则回退 portfolio.json
$d = proxy_report(user_positions_for_python($uid));
if ($d === null) {
    http_response_code(502);
    echo json_encode(['error' => '数据服务暂不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 只透出日历，持仓明细不出这个口
echo json_encode([
    'as_of'    => isset($d['as_of']) ? $d['as_of'] : '',
    'calendar' => isset($d['calendar']) ? $d['calendar'] : [],
    'received' => $received,
], JSON_UNESCAPED_UNICODE);
