<?php
require __DIR__ . '/includes/bootstrap.php';
$me = requireLogin();

$monthFrom = date('Y-m-01');
$monthTo   = date('Y-m-t');
$monthName = arMonthName(date('Y-m'));

if ($me['role'] === 'admin') {
    $wallets = [];
    $treasuries = [];
    // كل المجاميع لكل عملة على حدة (الدولار وحده والليرة وحدها) — بدون تحويل
    $treasByCur = []; $staffByCur = []; $spentByCur = []; $fundedByCur = [];
    foreach (wlWalletSummaries($pdo, $monthFrom, $monthTo) as $w) {
        if ((int)$w['is_main'] === 1) {
            $treasuries[] = $w;
            $treasByCur[$w['currency']] = (isset($treasByCur[$w['currency']]) ? $treasByCur[$w['currency']] : 0) + (float)$w['balance'];
            continue;
        }
        $wallets[] = $w;
        $staffByCur[$w['currency']] = (isset($staffByCur[$w['currency']]) ? $staffByCur[$w['currency']] : 0) + (float)$w['balance'];
    }
    // مجاميع الشهر تشمل كل المحافظ (حتى المؤرشفة) لتطابق توزيع التصنيفات
    $st = $pdo->prepare("SELECT w.currency,
            COALESCE(SUM(CASE WHEN t.type='expense' THEN -t.amount END),0) AS spent,
            COALESCE(SUM(CASE WHEN t.type='transfer_in' AND w.is_main=0 THEN t.amount END),0) AS funded
        FROM wl_transactions t JOIN wl_wallets w ON w.id = t.wallet_id
        WHERE t.voided = 0 AND t.txn_date BETWEEN ? AND ?
        GROUP BY w.currency");
    $st->execute([$monthFrom, $monthTo]);
    foreach ($st->fetchAll() as $mt) {
        if ((float)$mt['spent'] != 0)  $spentByCur[$mt['currency']]  = (float)$mt['spent'];
        if ((float)$mt['funded'] != 0) $fundedByCur[$mt['currency']] = (float)$mt['funded'];
    }
    // ترتيب ثابت: العملة الأساسية أولاً ثم حسب جدول العملات
    $curOrder = array_keys(wlCurrencies());
    $sortCur = function (array $a) use ($curOrder) {
        uksort($a, function ($x, $y) use ($curOrder) {
            $ix = array_search($x, $curOrder); $iy = array_search($y, $curOrder);
            return ($ix === false ? 999 : $ix) - ($iy === false ? 999 : $iy);
        });
        return $a;
    };
    $treasByCur = $sortCur($treasByCur); $staffByCur = $sortCur($staffByCur);
    $spentByCur = $sortCur($spentByCur); $fundedByCur = $sortCur($fundedByCur);

    $archivedCount = (int)$pdo->query("SELECT COUNT(*) FROM wl_wallets WHERE archived = 1")->fetchColumn();
    $catTotals = wlCategoryTotals($pdo, $monthFrom, $monthTo);
    $rows      = wlTxnQuery($pdo, [], 12);
    $issues    = wlIntegrity($pdo);
} else {
    $wallet = $me['wallet_id'] ? wlGetWallet($pdo, $me['wallet_id']) : null;
    if ($wallet) {
        $st = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN type='expense' THEN -amount END),0) AS spent,
                COALESCE(SUM(CASE WHEN type='transfer_in' THEN amount END),0) AS got,
                COALESCE(SUM(CASE WHEN type='transfer_out' THEN -amount END),0) AS returned
            FROM wl_transactions WHERE wallet_id=? AND voided=0 AND txn_date BETWEEN ? AND ?");
        $st->execute([$wallet['id'], $monthFrom, $monthTo]);
        $m = $st->fetch();
        $catTotals = wlCategoryTotals($pdo, $monthFrom, $monthTo, $wallet['id']);
        $catCur    = $wallet['currency'];
        $rows      = wlTxnQuery($pdo, ['wallet_id' => $wallet['id']], 10);
    }
}

$pageTitle = 'الرئيسية';
$active = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<?php if ($me['role'] === 'admin'): ?>

  <?php if ($issues): ?>
    <div class="alert alert-error">⚠️ يوجد عدم تطابق في رصيد <?= count($issues) ?> محفظة — راجع <a href="settings.php#integrity">الإعدادات ← فحص الأرصدة</a></div>
  <?php endif; ?>

  <div class="grid wallets" style="margin-bottom:14px">
    <?php foreach ($treasuries as $t): ?>
      <a class="stat hero" href="wallet.php?id=<?= (int)$t['id'] ?>" style="color:#fff">
        <div class="lbl">🏦 <?= e($t['name']) ?></div>
        <div class="val num" style="font-size:1.6rem"><?= e(money($t['balance'], true, $t['currency'])) ?></div>
        <?php if ($t['currency'] !== baseCurrency() && currencyRate($t['currency']) > 0): ?>
          <div class="lbl">≈ <span class="num"><?= e(money($t['balance'] * currencyRate($t['currency']))) ?></span></div>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php
  // سطر لكل عملة داخل البطاقة
  $curLines = function (array $byCur, $cls = '') {
      if (!$byCur) { echo '<div class="val num ' . $cls . '">0</div>'; return; }
      $fs = count($byCur) > 1 ? '1.15rem' : '1.45rem';
      foreach ($byCur as $cur => $sum) {
          echo '<div class="val num ' . $cls . '" style="font-size:' . $fs . '">' . e(money($sum, true, $cur)) . '</div>';
      }
  };
  ?>
  <div class="grid g4" style="margin-bottom:16px">
    <div class="stat blue">
      <div class="lbl">🏦 مجموع المحافظ الرئيسية</div>
      <?php $curLines($treasByCur); ?>
    </div>
    <div class="stat">
      <div class="lbl">👛 أرصدة الموظفين (<?= count($wallets) ?>)</div>
      <?php $curLines($staffByCur); ?>
    </div>
    <div class="stat">
      <div class="lbl">➖ مصاريف <?= e($monthName) ?></div>
      <?php $curLines($spentByCur, 'neg'); ?>
    </div>
    <div class="stat">
      <div class="lbl">💸 تغذية الموظفين هذا الشهر</div>
      <?php $curLines($fundedByCur, 'pos'); ?>
    </div>
  </div>

  <div class="qa">
    <a href="fund.php?op=transfer"><span>💸</span>تحويل / تغذية</a>
    <a href="fund.php?op=deposit"><span>🏦</span>إيداع في رئيسية</a>
    <a href="expense.php"><span>➖</span>تسجيل مصروف</a>
    <a href="transactions.php?from=<?= e($monthFrom) ?>&to=<?= e($monthTo) ?>"><span>📊</span>تقرير الشهر</a>
    <a href="users.php"><span>👥</span>إضافة مستخدم</a>
    <a href="opening.php"><span>📌</span>رصيد أول المدة</a>
    <a href="voucher.php"><span>📤</span>تصدير سند</a>
  </div>

  <div class="card">
    <h2>👛 المحافظ<?php if ($archivedCount): ?> <a class="btn btn-gray btn-sm" style="float:left" href="users.php#archived">📦 المؤرشفة (<?= $archivedCount ?>)</a><?php endif; ?></h2>
    <?php if (!$wallets): ?>
      <div class="empty">لا توجد محافظ بعد — <a href="users.php">أضف مستخدماً</a> وستُنشأ محفظته تلقائياً</div>
    <?php else: ?>
    <div class="grid wallets">
      <?php foreach ($wallets as $w): ?>
        <a class="wcard <?= (int)$w['active'] !== 1 ? 'off' : '' ?> <?= $w['balance'] <= 0 ? 'low' : '' ?>" href="wallet.php?id=<?= (int)$w['id'] ?>">
          <div class="wname">👤 <?= e($w['name']) ?><?= (int)$w['active'] !== 1 ? ' <span class="pill p-void">موقوفة</span>' : '' ?></div>
          <div class="wbal num <?= $w['balance'] < 0 ? 'neg' : '' ?>"><?= e(money($w['balance'], true, $w['currency'])) ?></div>
          <div class="wmeta">مصروف الشهر: <b class="num"><?= e(money($w['month_spent'], false)) ?></b> · تغذية: <b class="num"><?= e(money($w['month_in'], false)) ?></b></div>
          <div class="wmeta">آخر حركة: <span class="num"><?= e($w['last_move'] ?: '—') ?></span></div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="grid g12">
    <div class="card">
      <h2>🏷️ المصاريف حسب التصنيف — <?= e($monthName) ?></h2>
      <?php include __DIR__ . '/includes/cat_bars.php'; ?>
    </div>
    <div class="card">
      <h2>🕒 آخر الحركات <a class="btn btn-light btn-sm" style="float:left" href="transactions.php">الكل</a></h2>
      <?php $showWallet = true; $canVoid = false; include __DIR__ . '/includes/txn_table.php'; ?>
    </div>
  </div>

<?php else: ?>

  <?php if (!$wallet): ?>
    <div class="alert alert-info">لا توجد محفظة مرتبطة بحسابك بعد — راجع مدير النظام.</div>
  <?php else: ?>
    <div class="grid g3" style="margin-bottom:16px">
      <div class="stat hero">
        <div class="lbl">👛 رصيد محفظتي</div>
        <div class="val num <?= $wallet['balance'] < 0 ? 'neg' : '' ?>" style="<?= $wallet['balance'] < 0 ? 'color:#FECACA !important' : '' ?>"><?= e(money($wallet['balance'], true, $wallet['currency'])) ?></div>
      </div>
      <div class="stat">
        <div class="lbl">➖ مصاريفي في <?= e($monthName) ?></div>
        <div class="val num neg"><?= e(money($m['spent'], true, $wallet['currency'])) ?></div>
      </div>
      <div class="stat">
        <div class="lbl">💸 ما استلمته هذا الشهر</div>
        <div class="val num pos"><?= e(money($m['got'], true, $wallet['currency'])) ?></div>
        <?php if ($m['returned'] > 0): ?><div class="sub">أُرجع للرئيسية: <span class="num"><?= e(money($m['returned'], true, $wallet['currency'])) ?></span></div><?php endif; ?>
      </div>
    </div>

    <a class="btn btn-block" href="expense.php" style="margin-bottom:16px">➖ تسجيل مصروف جديد</a>

    <div class="grid g12">
      <div class="card">
        <h2>🏷️ مصاريفي حسب التصنيف — <?= e($monthName) ?></h2>
        <?php include __DIR__ . '/includes/cat_bars.php'; ?>
      </div>
      <div class="card">
        <h2>🕒 آخر حركاتي <a class="btn btn-light btn-sm" style="float:left" href="wallet.php?id=<?= (int)$wallet['id'] ?>">كشف كامل</a></h2>
        <?php $showWallet = false; $canVoid = false; include __DIR__ . '/includes/txn_table.php'; ?>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php';
