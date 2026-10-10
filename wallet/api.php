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

$treasuries = [];
$wallets = [];
$byCur = [];
$treasByCur = [];
$expByCur = [];
$totalTreasBase = 0;
$totalUsersBase = 0;
$totalExpBase = 0;
foreach ($rows as $r) {
    $rate = currencyRate($r['currency']);
    $item = [
        'id'             => (int)$r['id'],
        'name'           => $r['name'],
        'currency'       => $r['currency'],
        'owner'          => $r['owner'],
        'username'       => $r['username'],
        'balance'        => (float)$r['balance'],
        'balance_base'   => round((float)$r['balance'] * $rate, 2),
        'month_expenses' => (float)$r['month_spent'],
        'month_in'       => (float)$r['month_in'],
        'active'         => (int)$r['active'] === 1,
        'last_activity'  => $r['last_activity'],
    ];
    $totalExpBase += (float)$r['month_spent_base'];
    if ((float)$r['month_spent'] != 0) {
        $expByCur[$r['currency']] = (isset($expByCur[$r['currency']]) ? $expByCur[$r['currency']] : 0) + (float)$r['month_spent'];
    }
    if ((int)$r['is_main'] === 1) {
        $treasuries[] = $item;
        $totalTreasBase += $item['balance_base'];
        $treasByCur[$r['currency']] = (isset($treasByCur[$r['currency']]) ? $treasByCur[$r['currency']] : 0) + $item['balance'];
    } else {
        $wallets[] = $item;
        $totalUsersBase += $item['balance_base'];
        $byCur[$r['currency']] = (isset($byCur[$r['currency']]) ? $byCur[$r['currency']] : 0) + $item['balance'];
    }
}
$currencies = [];
foreach (wlCurrencies() as $c) {
    $currencies[$c['code']] = ['name' => $c['name'], 'symbol' => $c['symbol'], 'rate' => (float)$c['rate']];
}

$cats = [];
foreach (wlCategoryTotals($pdo, $from, $to, get('wallet') !== '' ? (int)get('wallet') : null) as $c) {
    $cats[] = ['category' => $c['name'], 'icon' => $c['icon'], 'total' => (float)$c['total'], 'count' => (int)$c['cnt']];
}

echo json_encode([
    'ok'            => true,
    'app'           => setting($pdo, 'app_name'),
    'base_currency' => baseCurrency(),
    'currency'      => currencySymbol(),
    'currencies'    => $currencies,
    'month'         => $ym,
    'generated_at'  => date('c'),
    'treasuries'    => $treasuries,
    'main_wallet'   => $treasuries ? $treasuries[0] : null,
    'wallets'       => $wallets,
    'totals'        => [
        'treasuries_balance_by_currency' => $treasByCur,
        'month_expenses_by_currency'  => $expByCur,
        'treasuries_balance_base'     => $totalTreasBase,
        'wallets_balance_base'        => $totalUsersBase,
        'wallets_balance_by_currency' => $byCur,
        'month_expenses_base'         => $totalExpBase,
        // حقول قديمة للتوافق (بالعملة الأساسية)
        'main_balance'    => $totalTreasBase,
        'wallets_balance' => $totalUsersBase,
        'month_expenses'  => $totalExpBase,
    ],
    'expenses_by_category' => $cats,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
