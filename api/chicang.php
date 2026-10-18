<?php
/**
 * 持仓分析代理
 * 必须登录；返回集中度、行业分布、加权股息率、风险提示
 */
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

$token = getAuthHeader();
if (!$token || !verifyToken($token)) {
    http_response_code(401);
    echo json_encode(['error' => '未授权，请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 60, 'ignore_errors' => true]]);
$out = @file_get_contents('http://127.0.0.1:8001/api/report', false, $ctx);

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

// 只透出分析所需字段，场外基金明细不出这个口
echo json_encode([
    'as_of'      => isset($d['as_of']) ? $d['as_of'] : '',
    'summary'    => isset($d['summary']) ? $d['summary'] : [],
    'positions'  => isset($d['positions']) ? $d['positions'] : [],
    'industries' => isset($d['industries']) ? $d['industries'] : [],
    'alerts'     => isset($d['alerts']) ? $d['alerts'] : [],
    'benchmarks' => isset($d['benchmarks']) ? $d['benchmarks'] : [],
], JSON_UNESCAPED_UNICODE);
