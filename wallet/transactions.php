<?php
// سجل الحركات + تقارير + تصدير CSV
require __DIR__ . '/includes/bootstrap.php';
$me = requireLogin();
$admin = $me['role'] === 'admin';

$f = [
    'wallet_id'   => $admin ? (int)get('wallet') : (int)$me['wallet_id'],
    'type'        => in_array(get('type'), ['expense', 'funding', 'deposit', 'withdraw', 'transfer_in', 'transfer_out', 'opening'], true) ? get('type') : '',
    'category_id' => (int)get('cat'),
    'from'        => validDate(get('from')) ? get('from') : date('Y-m-01'),
    'to'          => validDate(get('to')) ? get('to') : date('Y-m-d'),
    'q'           => mb_substr(get('q'), 0, 100),
    'voided'      => get('voided') === '1' ? null : 0,
];
if (!$admin && !$me['wallet_id']) {
    flash('error', 'لا توجد محفظة مرتبطة بحسابك');
    redirect('dashboard.php');
}

if (get('export') === 'csv') {
    $rows = wlTxnQuery($pdo, $f, 0);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions_' . $f['from'] . '_' . $f['to'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['#', 'التاريخ', 'المحفظة', 'النوع', 'التصنيف', 'رقم الحساب', 'الطرف الآخر', 'المبلغ', 'العملة', 'سعر الصرف', 'المعادل بـ' . baseCurrency(), 'الرصيد بعدها', 'ملاحظة', 'بواسطة', 'الحالة']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['txn_date'], $r['wallet_name'], txnTypeLabel($r['type']),
            $r['cat_name'], $r['cat_acc'], $r['counter_name'], $r['amount'], $r['currency'], $r['fx_rate'], $r['base_amount'], $r['balance_after'],
            $r['note'], $r['creator_name'], (int)$r['voided'] === 1 ? 'ملغاة' : '',
        ]);
    }
    fclose($out);
    exit;
}

$rows = wlTxnQuery($pdo, $f, 500);
$tot = wlTxnTotals($pdo, $f);
// مع اختيار محفظة: بعملتها؛ لكل المحافظ: بما يعادل العملة الأساسية
$totCur = null;
if ($f['wallet_id']) {
    $fw = wlGetWallet($pdo, $f['wallet_id']);
    $totCur = $fw ? $fw['currency'] : null;
}
$catCur = $totCur;
$eqv = !$f['wallet_id'] && (int)$pdo->query("SELECT COUNT(DISTINCT currency) FROM wl_wallets")->fetchColumn() > 1 ? ' (بما يعادل)' : '';
$sumIn = (float)$tot['inflow']; $sumExp = (float)$tot['spent']; $sumOut = (float)$tot['outflow'];
$catTotals = ($f['type'] === '' || $f['type'] === 'expense')
    ? wlCategoryTotals($pdo, $f['from'], $f['to'], $f['wallet_id'] ?: null) : [];
if ($f['category_id']) {
    $catTotals = array_values(array_filter($catTotals, function ($c) use ($f) { return (int)$c['id'] === $f['category_id']; }));
}

$wallets = $admin ? $pdo->query("SELECT id, name, is_main, archived FROM wl_wallets ORDER BY is_main DESC, archived, name")->fetchAll() : [];
$cats = $pdo->query("SELECT id, name, icon FROM wl_categories ORDER BY sort_order, name")->fetchAll();
$qs = $_GET;
$qs['export'] = 'csv';

$pageTitle = 'الحركات';
$active = 'transactions';
require __DIR__ . '/includes/header.php';
?>
<h1>📒 الحركات والتقارير <?php if ($admin): ?><a class="btn btn-light btn-sm" style="float:left" href="voucher.php?<?= e(http_build_query(['wallet' => $f['wallet_id'] ?: '', 'from' => $f['from'], 'to' => $f['to']])) ?>">📤 تصدير سند للمحاسبة</a><?php endif; ?></h1>
<form class="card filters" method="get">
  <?php if ($admin): ?>
  <div class="form-row"><label>المحفظة</label>
    <select name="wallet"><option value="">كل المحافظ</option>
      <?php foreach ($wallets as $w): ?><option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $f['wallet_id'] ? 'selected' : '' ?>><?= $w['is_main'] ? '🏦 ' : '' ?><?= e($w['name']) ?><?= $w['archived'] ? ' (مؤرشفة)' : '' ?></option><?php endforeach; ?>
    </select></div>
  <?php endif; ?>
  <div class="form-row"><label>النوع</label>
    <select name="type">
      <option value="">الكل</option>
      <option value="expense" <?= $f['type'] === 'expense' ? 'selected' : '' ?>>مصاريف</option>
      <option value="funding" <?= $f['type'] === 'funding' ? 'selected' : '' ?>>تغذية وتحويلات</option>
      <option value="opening" <?= $f['type'] === 'opening' ? 'selected' : '' ?>>رصيد أول المدة</option>
    </select></div>
  <div class="form-row"><label>التصنيف</label>
    <select name="cat"><option value="">الكل</option>
      <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $f['category_id'] ? 'selected' : '' ?>><?= e($c['icon'] . ' ' . $c['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="form-row"><label>من</label><input type="date" name="from" value="<?= e($f['from']) ?>"></div>
  <div class="form-row"><label>إلى</label><input type="date" name="to" value="<?= e($f['to']) ?>"></div>
  <div class="form-row"><label>بحث في الملاحظة</label><input type="text" name="q" value="<?= e($f['q']) ?>"></div>
  <div class="form-row"><label class="inline-check"><input type="checkbox" name="voided" value="1" <?= $f['voided'] === null ? 'checked' : '' ?>> إظهار الملغاة</label></div>
  <div class="form-row actions"><button class="btn">عرض</button><a class="btn btn-gray" href="?<?= e(http_build_query($qs)) ?>">⬇️ CSV</a></div>
</form>

<div class="grid g3" style="margin-bottom:16px">
  <div class="stat"><div class="lbl">💸 مجموع الوارد<?= $eqv ?></div><div class="val num pos"><?= e(money($sumIn, true, $totCur)) ?></div></div>
  <div class="stat"><div class="lbl">➖ مجموع المصاريف<?= $eqv ?></div><div class="val num neg"><?= e(money($sumExp, true, $totCur)) ?></div></div>
  <div class="stat"><div class="lbl">↩️ تحويلات صادرة / سحب</div><div class="val num"><?= e(money($sumOut, true, $totCur)) ?></div></div>
</div>

<?php if ($catTotals): ?>
<div class="card"><h2>🏷️ المصاريف حسب التصنيف</h2><?php include __DIR__ . '/includes/cat_bars.php'; ?></div>
<?php endif; ?>

<div class="card">
  <h2>الحركات (<?= count($rows) ?><?= count($rows) >= 500 ? ' — أول 500، ضيّق الفلتر أو صدّر CSV' : '' ?>)</h2>
  <?php $showWallet = $admin && !$f['wallet_id']; $canVoid = $admin; include __DIR__ . '/includes/txn_table.php'; ?>
</div>
<?php require __DIR__ . '/includes/footer.php';
