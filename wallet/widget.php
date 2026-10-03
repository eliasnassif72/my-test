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
$main = null; $list = []; $sumW = 0; $sumSpent = 0;
foreach ($rows as $r) {
    $sumSpent += $r['month_spent'];
    if ((int)$r['is_main'] === 1) { $main = $r; } else { $list[] = $r; $sumW += $r['balance']; }
}
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>أرصدة المحافظ</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=1">
<style>body{background:transparent}.wrap{padding:8px}.wcard{cursor:default}.wcard:hover{transform:none}</style>
</head>
<body>
<div class="wrap">
  <div class="grid g3" style="margin-bottom:12px">
    <div class="stat hero"><div class="lbl">🏦 المحفظة الرئيسية</div><div class="val num"><?= e(money($main ? $main['balance'] : 0)) ?></div></div>
    <div class="stat blue"><div class="lbl">👛 مجموع المحافظ (<?= count($list) ?>)</div><div class="val num"><?= e(money($sumW)) ?></div></div>
    <div class="stat"><div class="lbl">➖ مصاريف <?= e(arMonthName(date('Y-m'))) ?></div><div class="val num neg"><?= e(money($sumSpent)) ?></div></div>
  </div>
  <div class="grid wallets">
    <?php foreach ($list as $w): ?>
      <div class="wcard <?= (int)$w['active'] !== 1 ? 'off' : '' ?> <?= $w['balance'] <= 0 ? 'low' : '' ?>">
        <div class="wname">👤 <?= e($w['name']) ?></div>
        <div class="wbal num <?= $w['balance'] < 0 ? 'neg' : '' ?>"><?= e(money($w['balance'])) ?></div>
        <div class="wmeta">مصروف الشهر: <b class="num"><?= e(money($w['month_spent'], false)) ?></b></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="sub" style="text-align:center;margin-top:8px">آخر تحديث: <span class="num"><?= date('Y-m-d H:i') ?></span></div>
</div>
</body>
</html>
