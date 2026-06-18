<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$keyword = isset($_GET['symbol']) ? trim($_GET['symbol']) : '';

if (empty($keyword)) {
    echo json_encode(['error' => '请输入股票代码或名称']);
    exit;
}

$results = [];

// 使用 file_get_contents 调用东方财富 API
$searchUrl = 'https://searchapi.eastmoney.com/api/suggest/get?input=' . urlencode($keyword) . '&type=14&token=D43BF722C8E33BDC906FB84D85E326E8&count=10';

$ctx = stream_context_create([
    'http' => [
        'timeout' => 5,
        'header' => "User-Agent: Mozilla/5.0\r\n"
    ]
]);

$response = @file_get_contents($searchUrl, false, $ctx);

if ($response) {
    $data = json_decode($response, true);
    if (!empty($data['QuotationCodeTable']['Data'])) {
        foreach ($data['QuotationCodeTable']['Data'] as $item) {
            $code = isset($item['Code']) ? $item['Code'] : '';
            $name = isset($item['Name']) ? $item['Name'] : '';
            $classify = isset($item['Classify']) ? $item['Classify'] : '';
            if (empty($code) || empty($name)) continue;

            $market = '未知';
            if ($classify === 'AStock' || $classify === 'SHStock' || $classify === 'SZStock') {
                $market = 'A股';
            } elseif ($classify === 'HK' || $classify === 'HKStock') {
                $market = '港股';
            } elseif ($classify === 'UsStock') {
                $market = '美股';
            }

            $results[] = [
                'symbol' => $code,
                'name' => $name,
                'market' => $market
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'results' => array_slice($results, 0, 10)
]);
?>
