<?php
// لوحة المحفظة: كشف حساب شهري + توزيع المصاريف حسب التصنيف
require __DIR__ . '/includes/bootstrap.php';
$me = requireLogin();

$wallet = wlGetWallet($pdo, (int)get('id'));
if (!$wallet || !canViewWallet($wallet)) {
    flash('error', 'غير مصرح');
    redirect('dashboard.php');
}
$admin = $me['role'] === 'admin';

$ym = get('m', date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
    $ym = date('Y-m');
}
$from = $ym . '-01';
$to   = date('Y-m-t', strtotime($from));
$prevM = date('Y-m', strtotime($from . ' -1 month'));
$nextM = date('Y-m', strtotime($from . ' +1 month'));

// رصيد أول المدة = كل ما قبل الشهر + قيد «رصيد أول المدة» إن وقع داخل الشهر
$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wl_transactions
    WHERE wallet_id=? AND voided=0 AND (txn_date < ? OR (type='opening' AND txn_date <= ?))");
$st->execute([$wallet['id'], $from, $to]);
$opening = (float)$st->fetchColumn();

$st = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN amount > 0 THEN amount END),0) AS inflow,
        COALESCE(SUM(CASE WHEN type='expense' THEN -amount END),0) AS spent,
        COALESCE(SUM(CASE WHEN amount < 0 AND type<>'expense' THEN -amount END),0) AS outflow,
        COUNT(CASE WHEN type='expense' THEN 1 END) AS exp_count
    FROM wl_transactions WHERE wallet_id=? AND voided=0 AND type<>'opening' AND txn_date BETWEEN ? AND ?");
$st->execute([$wallet['id'], $from, $to]);
$p = $st->fetch();
$closing = $opening + $p['inflow'] - $p['spent'] - $p['outflow'];

$catTotals = wlCategoryTotals($pdo, $from, $to, $wallet['id']);
$catCur    = $wallet['currency'];
$rows = wlTxnQuery($pdo, ['wallet_id' => $wallet['id'], 'from' => $from, 'to' => $to], 0);

$pageTitle = $wallet['name'];
$active = $admin ? '' : 'wallet';
require __DIR__ . '/includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
  <h1 style="margin:0"><?= $wallet['is_main'] ? '🏦' : '👛' ?> <?= e($wallet['name']) ?> <span class="pill p-opening"><?= e($wallet['currency']) ?></span>
    <?php if ((int)$wallet['active'] !== 1): ?><span class="pill p-void">موقوفة</span><?php endif; ?>
  </h1>
  <div class="actions">
    <a class="btn btn-gray btn-sm" href="?id=<?= (int)$wallet['id'] ?>&m=<?= e($prevM) ?>">→ الشهر السابق</a>
    <form method="get" style="display:flex;gap:6px">
      <input type="hidden" name="id" value="<?= (int)$wallet['id'] ?>">
      <input type="month" name="m" value="<?= e($ym) ?>" onchange="this.form.submit()" style="padding:5px 8px">
    </form>
    <a class="btn btn-gray btn-sm" href="?id=<?= (int)$wallet['id'] ?>&m=<?= e($nextM) ?>">الشهر التالي ←</a>
  </div>
</div>

<div class="grid g4" style="margin-bottom:16px">
  <div class="stat hero"><div class="lbl">الرصيد الحالي</div><div class="val num"><?= e(money($wallet['balance'], true, $wallet['currency'])) ?></div></div>
  <div class="stat"><div class="lbl">💸 وارد <?= e(arMonthName($ym)) ?></div><div class="val num pos"><?= e(money($p['inflow'], true, $wallet['currency'])) ?></div></div>
  <div class="stat"><div class="lbl">➖ مصاريف (<?= (int)$p['exp_count'] ?>)</div><div class="val num neg"><?= e(money($p['spent'], true, $wallet['currency'])) ?></div></div>
  <div class="stat"><div class="lbl">↩️ تحويلات صادرة / سحب</div><div class="val num"><?= e(money($p['outflow'], true, $wallet['currency'])) ?></div></div>
</div>

<?php if (!empty($wallet['archived'])): ?>
  <div class="alert alert-info">📦 هذه المحفظة مؤرشفة منذ <span class="num"><?= e(substr((string)$wallet['archived_at'], 0, 10)) ?></span> — السجل للعرض فقط.
    <?php if ($admin): ?><a href="archive.php?wallet=<?= (int)$wallet['id'] ?>">استعادة</a><?php endif; ?></div>
<?php elseif ($admin || (int)$wallet['user_id'] === (int)$me['id']): ?>
<div class="actions" style="margin-bottom:16px">
  <?php if ($admin && !$wallet['is_main']): ?>
    <a class="btn" href="fund.php?op=topup&wallet=<?= (int)$wallet['id'] ?>">💸 تغذية هذه المحفظة</a>
    <a class="btn btn-light" href="fund.php?op=return&wallet=<?= (int)$wallet['id'] ?>">↩️ إرجاع للرئيسية</a>
  <?php elseif ($admin && $wallet['is_main']): ?>
    <a class="btn" href="fund.php?op=deposit&wallet=<?= (int)$wallet['id'] ?>">🏦 إيداع</a>
    <a class="btn btn-light" href="fund.php?op=withdraw&wallet=<?= (int)$wallet['id'] ?>">🏧 سحب</a>
    <a class="btn btn-light" href="fund.php?op=return&wallet=<?= (int)$wallet['id'] ?>">💸 تحويل منها</a>
  <?php endif; ?>
  <a class="btn btn-light" href="expense.php<?= $admin ? '?wallet=' . (int)$wallet['id'] : '' ?>">➖ تسجيل مصروف</a>
  <?php if ($admin): ?>
    <a class="btn" href="voucher.php?wallet=<?= (int)$wallet['id'] ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">📤 تصدير سند</a>
    <a class="btn btn-light" href="opening.php?wallet=<?= (int)$wallet['id'] ?>">📌 رصيد أول المدة</a>
    <?php if (!$wallet['is_main']): ?><a class="btn btn-gray" href="archive.php?wallet=<?= (int)$wallet['id'] ?>">📦 تسليم العهدة</a><?php endif; ?>
  <?php endif; ?>
  <a class="btn btn-gray" href="transactions.php?wallet=<?= (int)$wallet['id'] ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv">⬇️ تصدير CSV</a>
</div>
<?php endif; ?>

<div class="grid g2">
  <div class="card">
    <h2>🧾 كشف حساب <?= e(arMonthName($ym)) ?></h2>
    <table>
      <tr><td>رصيد أول المدة</td><td class="num" style="text-align:left"><b><?= e(money($opening, true, $wallet['currency'])) ?></b></td></tr>
      <tr><td>+ وارد (تغذية / إيداع)</td><td class="num pos" style="text-align:left"><?= e(money($p['inflow'], true, $wallet['currency'])) ?></td></tr>
      <tr><td>− مصاريف</td><td class="num neg" style="text-align:left"><?= e(money($p['spent'], true, $wallet['currency'])) ?></td></tr>
      <tr><td>− تحويلات صادرة / سحب</td><td class="num" style="text-align:left"><?= e(money($p['outflow'], true, $wallet['currency'])) ?></td></tr>
      <tr style="background:var(--primary-bg)"><td><b>رصيد آخر المدة</b></td><td class="num" style="text-align:left"><b><?= e(money($closing, true, $wallet['currency'])) ?></b></td></tr>
    </table>
  </div>
  <div class="card">
    <h2>🏷️ المصاريف حسب التصنيف</h2>
    <?php include __DIR__ . '/includes/cat_bars.php'; ?>
  </div>
</div>

<div class="card">
  <h2>📒 حركات <?= e(arMonthName($ym)) ?> (<?= count($rows) ?>)</h2>
  <?php $showWallet = false; $canVoid = $admin; include __DIR__ . '/includes/txn_table.php'; ?>
</div>
<?php require __DIR__ . '/includes/footer.php';
