<?php
// رصيد أول المدة لكل محفظة — لمطابقة الحسابات القديمة عند بدء العمل على النظام
require __DIR__ . '/includes/bootstrap.php';
$me = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $wid  = (int)post('wallet_id');
    $date = post('date');
    try {
        $w = wlGetWallet($pdo, $wid);
        if (!$w || !empty($w['archived'])) {
            throw new WalletError('المحفظة غير موجودة أو مؤرشفة');
        }
        if (post('action') === 'remove') {
            wlSetOpening($pdo, $wid, 0, date('Y-m-d'), '', $me['id']);
            flash('success', 'تم حذف رصيد أول المدة لـ«' . $w['name'] . '»');
        } else {
            $amount = parseAmount(post('amount'));
            if ($amount === null) {
                throw new WalletError('أدخل مبلغاً صحيحاً أكبر من صفر');
            }
            if (!validDate($date) || $date > date('Y-m-d')) {
                throw new WalletError('تاريخ غير صحيح');
            }
            $signed = post('sign') === 'neg' ? -$amount : $amount;
            wlSetOpening($pdo, $wid, $signed, $date, post('note'), $me['id']);
            $w = wlGetWallet($pdo, $wid);
            flash('success', 'تم ضبط رصيد أول المدة لـ«' . $w['name'] . '»: ' . money($signed) . ' — الرصيد الحالي ' . money($w['balance']));
        }
    } catch (WalletError $ex) {
        flash('error', $ex->getMessage());
    } catch (Exception $ex) {
        error_log('opening: ' . $ex->getMessage());
        flash('error', 'حدث خطأ أثناء الحفظ');
    }
    redirect('opening.php' . (get('wallet') ? '?wallet=' . (int)get('wallet') : ''));
}

$only = (int)get('wallet');
$sql = "SELECT w.*, o.id AS op_id, o.amount AS op_amount, o.txn_date AS op_date, o.note AS op_note
        FROM wl_wallets w
        LEFT JOIN wl_transactions o ON o.id = (
            SELECT MAX(t.id) FROM wl_transactions t WHERE t.wallet_id = w.id AND t.type = 'opening' AND t.voided = 0)
        WHERE w.archived = 0" . ($only ? " AND w.id = " . $only : "") . "
        ORDER BY w.is_main DESC, w.name";
$rows = $pdo->query($sql)->fetchAll();

$pageTitle = 'رصيد أول المدة';
$active = 'opening';
require __DIR__ . '/includes/header.php';
?>
<h1>📌 رصيد أول المدة</h1>
<div class="alert alert-info">
  أدخل لكل محفظة رصيدها في دفاترك القديمة بتاريخ بدء العمل على النظام. هذا القيد <b>لا يُخصم من المحفظة الرئيسية</b> — هو رصيد موجود أصلاً.<br>
  <b>موجب</b> = مبلغ في عهدة صاحب المحفظة · <b>سالب</b> = مبلغ له على الشركة (صرف من جيبه). التعديل لاحقاً يستبدل القيد السابق ويبقيه ظاهراً كـ«ملغى» للتدقيق.
</div>
<?php if ($only): ?><p><a href="opening.php">← عرض كل المحافظ</a></p><?php endif; ?>

<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,330px),1fr))">
<?php foreach ($rows as $r): $has = $r['op_id'] !== null; $neg = $has && $r['op_amount'] < 0; ?>
  <div class="card" style="margin:0">
    <h2 style="margin-bottom:6px"><?= $r['is_main'] ? '🏦' : '👤' ?> <?= e($r['name']) ?></h2>
    <div class="sub">الرصيد الحالي: <b class="num"><?= e(money($r['balance'])) ?></b></div>
    <div class="sub" style="margin-bottom:10px">رصيد أول المدة:
      <?php if ($has): ?><b class="num <?= $neg ? 'neg' : 'pos' ?>"><?= e(money($r['op_amount'])) ?></b> <span class="num">(<?= e($r['op_date']) ?>)</span><?php else: ?>— غير مضبوط<?php endif; ?>
    </div>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="wallet_id" value="<?= (int)$r['id'] ?>">
      <div class="form-row"><input type="text" name="amount" inputmode="decimal" data-money placeholder="المبلغ" value="<?= $has ? e(rtrim(rtrim(number_format(abs($r['op_amount']), 2, '.', ','), '0'), '.')) : '' ?>" required style="direction:ltr;text-align:center;font-weight:700"></div>
      <div class="grid g2" style="gap:8px">
        <div class="form-row" style="margin:0">
          <select name="sign">
            <option value="pos" <?= !$neg ? 'selected' : '' ?>>موجب (عهدة لديه)</option>
            <option value="neg" <?= $neg ? 'selected' : '' ?>>سالب (مستحق له)</option>
          </select>
        </div>
        <div class="form-row" style="margin:0"><input type="date" name="date" value="<?= e($has ? $r['op_date'] : date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required></div>
      </div>
      <div class="form-row" style="margin-top:8px"><input type="text" name="note" maxlength="500" placeholder="ملاحظة (اختياري) — مثال: حسب دفتر أيلول" value="<?= $has ? e($r['op_note']) : '' ?>"></div>
      <div class="actions">
        <button class="btn btn-sm"><?= $has ? 'تعديل' : 'حفظ' ?></button>
        <?php if ($has): ?>
          <button class="btn btn-red btn-sm" name="action" value="remove" formnovalidate onclick="return confirm('حذف رصيد أول المدة لهذه المحفظة؟')">حذف</button>
        <?php endif; ?>
        <a class="btn btn-gray btn-sm" href="wallet.php?id=<?= (int)$r['id'] ?>">المحفظة</a>
      </div>
    </form>
  </div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php';
