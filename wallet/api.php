<?php
// API للقراءة فقط — أرصدة المحافظ لعرضها في لوحة elias controle أو n8n
define('WL_NO_SESSION', true);
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY');
header('Cache-Control: no-store');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$given = isset($_SERVER['HTTP_X_API_KEY']) ? (string)$_SERVER['HTTP_X_API_KEY'] : get('key');
$key   = (string)setting($pdo, 'api_key', '');
if ($key === '' || $given === '' || !hash_equals($key, $given)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$snap = wlDashboardSnapshot($pdo, get('month', date('Y-m')), get('wallet') !== '' ? (int)get('wallet') : null, get('since'));
echo json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
