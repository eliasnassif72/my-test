<?php
require __DIR__ . '/includes/bootstrap.php';
$me = requireLogin();

$monthFrom = date('Y-m-01');
$monthTo   = date('Y-m-t');
$monthName = arMonthName(date('Y-m'));

if ($me['role'] === 'admin') {
    $main = wlMainWallet($pdo);

    $wallets = [];
    $usersTotal = 0; $monthSpent = 0; $monthFunded = 0;
    foreach (wlWalletSummaries($pdo, $monthFrom, $monthTo) as $w) {
        $monthSpent += (float)$w['month_spent'];
        if ((int)$w['is_main'] === 1) {
            continue;
        }
        $wallets[]    = $w;
        $usersTotal  += (float)$w['balance'];
        $monthFunded += (float)$w['month_in'];
    }

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

  <div class="grid g4" style="margin-bottom:16px">
    <a class="stat hero" href="wallet.php?id=<?= (int)$main['id'] ?>" style="color:#fff">
      <div class="lbl">🏦 المحفظة الرئيسية</div>
      <div class="val num"><?= e(money($main['balance'])) ?></div>
    </a>
    <div class="stat blue">
      <div class="lbl">👛 مجموع أرصدة المحافظ (<?= count($wallets) ?>)</div>
      <div class="val num"><?= e(money($usersTotal)) ?></div>
    </div>
    <div class="stat">
      <div class="lbl">➖ مصاريف <?= e($monthName) ?></div>
      <div class="val num neg"><?= e(money($monthSpent)) ?></div>
    </div>
    <div class="stat">
      <div class="lbl">💸 تغذية المحافظ هذا الشهر</div>
      <div class="val num pos"><?= e(money($monthFunded)) ?></div>
    </div>
  </div>

  <div class="qa">
    <a href="fund.php?op=topup"><span>💸</span>تغذية محفظة</a>
    <a href="fund.php?op=deposit"><span>🏦</span>إيداع في الرئيسية</a>
    <a href="expense.php"><span>➖</span>تسجيل مصروف</a>
    <a href="transactions.php?from=<?= e($monthFrom) ?>&to=<?= e($monthTo) ?>"><span>📊</span>تقرير الشهر</a>
    <a href="users.php"><span>👥</span>إضافة مستخدم</a>
  </div>

  <div class="card">
    <h2>👛 المحافظ</h2>
    <?php if (!$wallets): ?>
      <div class="empty">لا توجد محافظ بعد — <a href="users.php">أضف مستخدماً</a> وستُنشأ محفظته تلقائياً</div>
    <?php else: ?>
    <div class="grid wallets">
      <?php foreach ($wallets as $w): ?>
        <a class="wcard <?= (int)$w['active'] !== 1 ? 'off' : '' ?> <?= $w['balance'] <= 0 ? 'low' : '' ?>" href="wallet.php?id=<?= (int)$w['id'] ?>">
          <div class="wname">👤 <?= e($w['name']) ?><?= (int)$w['active'] !== 1 ? ' <span class="pill p-void">موقوفة</span>' : '' ?></div>
          <div class="wbal num <?= $w['balance'] < 0 ? 'neg' : '' ?>"><?= e(money($w['balance'])) ?></div>
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
        <div class="val num <?= $wallet['balance'] < 0 ? 'neg' : '' ?>" style="<?= $wallet['balance'] < 0 ? 'color:#FECACA !important' : '' ?>"><?= e(money($wallet['balance'])) ?></div>
      </div>
      <div class="stat">
        <div class="lbl">➖ مصاريفي في <?= e($monthName) ?></div>
        <div class="val num neg"><?= e(money($m['spent'])) ?></div>
      </div>
      <div class="stat">
        <div class="lbl">💸 ما استلمته هذا الشهر</div>
        <div class="val num pos"><?= e(money($m['got'])) ?></div>
        <?php if ($m['returned'] > 0): ?><div class="sub">أُرجع للرئيسية: <span class="num"><?= e(money($m['returned'])) ?></span></div><?php endif; ?>
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
