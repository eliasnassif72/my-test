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

// «تم الاطلاع» — يصفّر إشارات التعديل (محمي بنفس المفتاح)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && post('ack') === '1') {
    wlWidgetAck($pdo);
    header('Location: widget.php?key=' . rawurlencode(get('key')));
    exit;
}

$seen    = wlWidgetSeen($pdo);
$changes = wlChangesSince($pdo, $seen);

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
<style>body{background:transparent}.wrap{padding:8px}.wcard{cursor:default}.wcard:hover{transform:none}
.chg{border:2px solid #F59E0B !important;box-shadow:0 0 0 3px rgba(245,158,11,.18)}
.chg-badge{display:inline-block;background:#F59E0B;color:#fff;border-radius:999px;padding:1px 8px;font-size:.75rem;font-weight:800;margin-inline-start:6px}
.chg-d{font-weight:800;font-size:.9rem}.stat.hero .chg-d{color:#FEF3C7}
.chg-list{font-size:.78rem;color:var(--muted);margin-top:4px;border-top:1px dashed var(--line);padding-top:4px}
.stat.hero .chg-list{color:rgba(255,255,255,.85);border-color:rgba(255,255,255,.3)}
.chg-bar{display:flex;align-items:center;justify-content:space-between;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;color:#92400E;border-radius:12px;padding:8px 12px;margin-bottom:10px;font-weight:700}
</style>
</head>
<body>
<div class="wrap">
  <?php
  $nChanged = count(array_intersect_key($changes, array_flip(array_map('intval', array_column($rows, 'id')))));
  // شارة التعديل + صافي التغيّر + آخر الحركات
  $chg = function ($w) use ($changes) {
      $c = isset($changes[(int)$w['id']]) ? $changes[(int)$w['id']] : null;
      if (!$c) return '';
      $d = $c['delta'];
      $h = '<div class="chg-d num">' . ($d > 0 ? '▲ +' : ($d < 0 ? '▼ −' : '')) . e(money(abs($d), true, $w['currency'])) . '</div>';
      $h .= '<div class="chg-list">';
      foreach ($c['items'] as $it) {
          $lbl = $it['type'] === 'expense' && $it['cat'] ? $it['cat'] : txnTypeLabel($it['type']);
          $h .= '<div>' . ((int)$it['voided'] ? '↩️ ملغاة: ' : '• ') . e($lbl) . ' <span class="num">' . e(money(abs((float)$it['amount']), false)) . '</span>'
              . ($it['note'] ? ' — ' . e(mb_substr($it['note'], 0, 30)) : '') . '</div>';
      }
      if ($c['moves'] > count($c['items'])) $h .= '<div>+' . ($c['moves'] - count($c['items'])) . ' أخرى</div>';
      return $h . '</div>';
  };
  $badge = function ($w) use ($changes) {
      $c = isset($changes[(int)$w['id']]) ? $changes[(int)$w['id']] : null;
      return $c ? '<span class="chg-badge">🔔 ' . $c['moves'] . '</span>' : '';
  };
  ?>
  <?php if ($nChanged): ?>
    <form method="post" class="chg-bar">
      <span>🔔 <?= $nChanged ?> محفظة جرى عليها تعديل منذ آخر اطلاع (<span class="num"><?= e(substr($seen, 0, 16)) ?></span>)</span>
      <input type="hidden" name="ack" value="1"><button class="btn btn-sm">✓ تم الاطلاع</button>
    </form>
  <?php endif; ?>
  <div class="grid wallets" style="margin-bottom:12px">
    <?php foreach ($treas as $t): ?>
      <div class="stat hero <?= isset($changes[(int)$t['id']]) ? 'chg' : '' ?>"><div class="lbl">🏦 <?= e($t['name']) ?><?= $badge($t) ?></div><div class="val num" style="font-size:1.5rem"><?= e(money($t['balance'], true, $t['currency'])) ?></div><?= $chg($t) ?></div>
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
      <div class="wcard <?= (int)$w['active'] !== 1 ? 'off' : '' ?> <?= $w['balance'] <= 0 ? 'low' : '' ?> <?= isset($changes[(int)$w['id']]) ? 'chg' : '' ?>">
        <div class="wname">👤 <?= e($w['name']) ?><?= $badge($w) ?></div>
        <div class="wbal num <?= $w['balance'] < 0 ? 'neg' : '' ?>"><?= e(money($w['balance'], true, $w['currency'])) ?></div>
        <div class="wmeta">مصروف الشهر: <b class="num"><?= e(money($w['month_spent'], false)) ?></b></div>
        <?= $chg($w) ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="sub" style="text-align:center;margin-top:8px">آخر تحديث: <span class="num"><?= date('Y-m-d H:i') ?></span></div>
</div>
</body>
</html>
