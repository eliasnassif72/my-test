<?php
// تسليم العهدة وأرشفة محفظة (موظف ترك العمل) — واستعادتها
require __DIR__ . '/includes/bootstrap.php';
$me = requireAdmin();

$wallet = wlGetWallet($pdo, (int)(post('wallet_id') !== '' ? post('wallet_id') : get('wallet')));
if (!$wallet || $wallet['is_main']) {
    flash('error', 'المحفظة غير موجودة');
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    try {
        if (post('action') === 'restore') {
            wlRestoreWallet($pdo, $wallet['id']);
            flash('success', 'تمت استعادة محفظة «' . $wallet['name'] . '» وتفعيل حساب صاحبها');
            redirect('wallet.php?id=' . (int)$wallet['id']);
        }
        $settled = wlArchiveWallet($pdo, $wallet['id'], post('note'), $me['id']);
        $msg = 'تمت أرشفة «' . $wallet['name'] . '» وإيقاف حسابه';
        if ($settled > 0) {
            $msg .= ' — أُرجع ' . money($settled) . ' للمحفظة الرئيسية';
        } elseif ($settled < 0) {
            $msg .= ' — دُفع له ' . money(-$settled) . ' من المحفظة الرئيسية';
        }
        flash('success', $msg);
        redirect('users.php');
    } catch (WalletError $ex) {
        flash('error', $ex->getMessage());
    } catch (Exception $ex) {
        error_log('archive: ' . $ex->getMessage());
        flash('error', 'حدث خطأ أثناء العملية');
    }
    redirect('archive.php?wallet=' . (int)$wallet['id']);
}

$bal  = (float)$wallet['balance'];
$main = wlMainWallet($pdo);
$pageTitle = 'تسليم العهدة';
$active = 'users';
require __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:620px;margin:0 auto">
<?php if (!empty($wallet['archived'])): ?>
  <h1>📦 «<?= e($wallet['name']) ?>» مؤرشفة</h1>
  <p class="sub">أُرشفت بتاريخ <span class="num"><?= e($wallet['archived_at']) ?></span>. سجل حركاتها محفوظ ويمكن الرجوع إليه.</p>
  <form method="post" class="actions">
    <?= csrfField() ?>
    <input type="hidden" name="wallet_id" value="<?= (int)$wallet['id'] ?>">
    <button class="btn" name="action" value="restore">↩️ استعادة المحفظة وتفعيل الحساب</button>
    <a class="btn btn-gray" href="wallet.php?id=<?= (int)$wallet['id'] ?>">عرض السجل</a>
  </form>
<?php else: ?>
  <h1>📦 تسليم العهدة وأرشفة «<?= e($wallet['name']) ?>»</h1>
  <div class="stat hero" style="margin-bottom:14px"><div class="lbl">الرصيد الحالي</div><div class="val num"><?= e(money($bal)) ?></div></div>
  <p>عند التأكيد سيتم:</p>
  <ol>
    <?php if ($bal > 0): ?>
      <li>إرجاع <b class="num"><?= e(money($bal)) ?></b> (العهدة المتبقية) إلى المحفظة الرئيسية.</li>
    <?php elseif ($bal < 0): ?>
      <li>دفع <b class="num"><?= e(money(-$bal)) ?></b> له من المحفظة الرئيسية (مستحق له) — رصيد الرئيسية الآن <span class="num"><?= e(money($main['balance'])) ?></span>.</li>
    <?php else: ?>
      <li>الرصيد صفر — لا حاجة لتسوية.</li>
    <?php endif; ?>
    <li>إخفاء المحفظة من الداشبورد والقوائم وإيقاف حساب الدخول.</li>
    <li>الإبقاء على كامل سجل الحركات للتدقيق، مع إمكانية الاستعادة لاحقاً.</li>
  </ol>
  <p class="sub">إذا سلّم جزءاً من العهدة كمصاريف لم تُسجّل بعد، سجّلها أولاً من «تسجيل مصروف» قبل الأرشفة.</p>
  <form method="post" data-confirm="تأكيد تسليم العهدة وأرشفة المحفظة؟">
    <?= csrfField() ?>
    <input type="hidden" name="wallet_id" value="<?= (int)$wallet['id'] ?>">
    <div class="form-row"><label>ملاحظة التسليم</label><input type="text" name="note" maxlength="500" placeholder="مثال: ترك العمل وسلّم العهدة نقداً بتاريخ ..."></div>
    <div class="actions">
      <button class="btn btn-red">تأكيد التسليم والأرشفة</button>
      <a class="btn btn-gray" href="wallet.php?id=<?= (int)$wallet['id'] ?>">رجوع</a>
    </div>
  </form>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php';
