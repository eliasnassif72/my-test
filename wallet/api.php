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

$ym = get('month', date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
    $ym = date('Y-m');
}
$from = $ym . '-01';
$to   = date('Y-m-t', strtotime($from));

$rows = wlWalletSummaries($pdo, $from, $to, false, get('wallet') !== '' ? (int)get('wallet') : null);

$main = null;
$wallets = [];
$totalUsers = 0;
$totalExp = 0;
foreach ($rows as $r) {
    $item = [
        'id'             => (int)$r['id'],
        'name'           => $r['name'],
        'owner'          => $r['owner'],
        'username'       => $r['username'],
        'balance'        => (float)$r['balance'],
        'month_expenses' => (float)$r['month_spent'],
        'month_in'       => (float)$r['month_in'],
        'active'         => (int)$r['active'] === 1,
        'last_activity'  => $r['last_activity'],
    ];
    $totalExp += $item['month_expenses'];
    if ((int)$r['is_main'] === 1) {
        $main = $item;
    } else {
        $totalUsers += $item['balance'];
        $wallets[] = $item;
    }
}

$cats = [];
foreach (wlCategoryTotals($pdo, $from, $to, get('wallet') !== '' ? (int)get('wallet') : null) as $c) {
    $cats[] = ['category' => $c['name'], 'icon' => $c['icon'], 'total' => (float)$c['total'], 'count' => (int)$c['cnt']];
}

echo json_encode([
    'ok'           => true,
    'app'          => setting($pdo, 'app_name'),
    'currency'     => setting($pdo, 'currency'),
    'month'        => $ym,
    'generated_at' => date('c'),
    'main_wallet'  => $main,
    'wallets'      => $wallets,
    'totals'       => [
        'main_balance'    => $main ? $main['balance'] : null,
        'wallets_balance' => $totalUsers,
        'month_expenses'  => $totalExp,
    ],
    'expenses_by_category' => $cats,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
