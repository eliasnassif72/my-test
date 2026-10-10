<?php
// ويدجت أرصدة المحافظ — يُضمَّن في لوحة elias controle عبر iframe (يتطلب مفتاح API)
define('WL_NO_SESSION', true);
require __DIR__ . '/includes/bootstrap.php';

$key = (string)setting($pdo, 'api_key', '');
if ($key === '' || !hash_equals($key, get('key'))) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    exit('مفتاح غير صالح');
}
header('Cache-Control: no-store');

$from = date('Y-m-01');
$to   = date('Y-m-t');
$rows = wlWalletSummaries($pdo, $from, $to);
// المجاميع لكل عملة على حدة
$treas = []; $list = []; $sumW = []; $sumSpent = [];
foreach ($rows as $r) {
    $c = $r['currency'];
    if ((float)$r['month_spent'] != 0) $sumSpent[$c] = (isset($sumSpent[$c]) ? $sumSpent[$c] : 0) + (float)$r['month_spent'];
    if ((int)$r['is_main'] === 1) { $treas[] = $r; } else { $list[] = $r; $sumW[$c] = (isset($sumW[$c]) ? $sumW[$c] : 0) + (float)$r['balance']; }
}
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>أرصدة المحافظ</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=3">
<style>body{background:transparent}.wrap{padding:8px}.wcard{cursor:default}.wcard:hover{transform:none}</style>
</head>
<body>
<div class="wrap">
  <div class="grid wallets" style="margin-bottom:12px">
    <?php foreach ($treas as $t): ?>
      <div class="stat hero"><div class="lbl">🏦 <?= e($t['name']) ?></div><div class="val num" style="font-size:1.5rem"><?= e(money($t['balance'], true, $t['currency'])) ?></div></div>
    <?php endforeach; ?>
  </div>
  <div class="grid g2" style="margin-bottom:12px">
    <div class="stat blue"><div class="lbl">👛 أرصدة الموظفين (<?= count($list) ?>)</div>
      <?php if (!$sumW): ?><div class="val num">0</div><?php endif; ?>
      <?php foreach ($sumW as $c => $v): ?><div class="val num" style="font-size:<?= count($sumW) > 1 ? '1.15rem' : '1.45rem' ?>"><?= e(money($v, true, $c)) ?></div><?php endforeach; ?></div>
    <div class="stat"><div class="lbl">➖ مصاريف <?= e(arMonthName(date('Y-m'))) ?></div>
      <?php if (!$sumSpent): ?><div class="val num neg">0</div><?php endif; ?>
      <?php foreach ($sumSpent as $c => $v): ?><div class="val num neg" style="font-size:<?= count($sumSpent) > 1 ? '1.15rem' : '1.45rem' ?>"><?= e(money($v, true, $c)) ?></div><?php endforeach; ?></div>
  </div>
  <div class="grid wallets">
    <?php foreach ($list as $w): ?>
      <div class="wcard <?= (int)$w['active'] !== 1 ? 'off' : '' ?> <?= $w['balance'] <= 0 ? 'low' : '' ?>">
        <div class="wname">👤 <?= e($w['name']) ?></div>
        <div class="wbal num <?= $w['balance'] < 0 ? 'neg' : '' ?>"><?= e(money($w['balance'], true, $w['currency'])) ?></div>
        <div class="wmeta">مصروف الشهر: <b class="num"><?= e(money($w['month_spent'], false)) ?></b></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="sub" style="text-align:center;margin-top:8px">آخر تحديث: <span class="num"><?= date('Y-m-d H:i') ?></span></div>
</div>
</body>
</html>
