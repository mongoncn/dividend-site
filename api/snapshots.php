<?php
/**
 * 每日市值快照代理（收益曲线数据）
 */
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

$token = getAuthHeader();
if (!$token || !verifyToken($token)) {
    http_response_code(401);
    echo json_encode(['error' => '未授权，请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 20, 'ignore_errors' => true]]);
$out = @file_get_contents('http://127.0.0.1:8001/api/snapshots', false, $ctx);

if ($out === false || $out === '') {
    http_response_code(502);
    echo json_encode(['error' => '数据服务暂不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$d = json_decode($out, true);
if (!is_array($d)) {
    http_response_code(502);
    echo json_encode(['error' => '数据格式异常'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['items' => isset($d['items']) ? $d['items'] : []], JSON_UNESCAPED_UNICODE);
