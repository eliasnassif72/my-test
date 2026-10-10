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

// إشارات التعديل: منذ آخر «تم الاطلاع» في الويدجت، أو ?since=YYYY-MM-DD HH:MM:SS
$since = get('since');
if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $since)) {
    $since = wlWidgetSeen($pdo);
}
$changes = wlChangesSince($pdo, $since);

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
        'changed'        => isset($changes[(int)$r['id']]),
        'new_moves'      => isset($changes[(int)$r['id']]) ? $changes[(int)$r['id']]['moves'] : 0,
        'change_amount'  => isset($changes[(int)$r['id']]) ? $changes[(int)$r['id']]['delta'] : 0,
        'changed_at'     => isset($changes[(int)$r['id']]) ? $changes[(int)$r['id']]['last_at'] : null,
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

// آخر الحركات (7 أيام) مع توقيت قاعدة البيانات — ليحسب أي داشبورد خارجي «ما تغيّر منذ آخر اطلاع» بنفسه
$dbNow = (string)$pdo->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")->fetchColumn();
$recent = [];
$st = $pdo->query("SELECT t.id, t.wallet_id, t.type, t.amount, t.note, t.voided,
        DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
        DATE_FORMAT(t.voided_at, '%Y-%m-%d %H:%i:%s') AS voided_at, c.name AS category
    FROM wl_transactions t LEFT JOIN wl_categories c ON c.id = t.category_id
    WHERE t.created_at >= NOW() - INTERVAL 7 DAY OR t.voided_at >= NOW() - INTERVAL 7 DAY
    ORDER BY t.id DESC LIMIT 300");
foreach ($st->fetchAll() as $m) {
    $recent[] = [
        'id'         => (int)$m['id'],
        'wallet_id'  => (int)$m['wallet_id'],
        'type'       => $m['type'],
        'type_label' => txnTypeLabel($m['type']),
        'amount'     => (float)$m['amount'],
        'category'   => $m['category'],
        'note'       => $m['note'],
        'voided'     => (int)$m['voided'] === 1,
        'created_at' => $m['created_at'],
        'voided_at'  => $m['voided_at'],
    ];
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
    'db_now'        => $dbNow,
    'changes_since' => $since,
    'changed_count' => count(array_filter(array_merge($treasuries, $wallets), function ($i) { return $i['changed']; })),
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
    'recent_moves'  => $recent,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
