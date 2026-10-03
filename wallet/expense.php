<?php
require __DIR__ . '/includes/bootstrap.php';
$me = requireLogin();
$admin = $me['role'] === 'admin';

if ($admin) {
    $walletList = $pdo->query("SELECT id, name, balance, is_main FROM wl_wallets WHERE active = 1 ORDER BY is_main DESC, name")->fetchAll();
} else {
    if (!$me['wallet_id']) {
        flash('error', 'لا توجد محفظة مرتبطة بحسابك');
        redirect('dashboard.php');
    }
    $walletList = [wlGetWallet($pdo, $me['wallet_id'])];
}
$cats = $pdo->query("SELECT * FROM wl_categories WHERE active = 1 ORDER BY sort_order, name")->fetchAll();

$form = [
    'wallet_id'   => $admin ? (int)get('wallet', 0) : (int)$me['wallet_id'],
    'amount'      => '',
    'category_id' => 0,
    'date'        => date('Y-m-d'),
    'note'        => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $form['wallet_id']   = $admin ? (int)post('wallet_id') : (int)$me['wallet_id'];
    $form['amount']      = post('amount');
    $form['category_id'] = (int)post('category_id');
    $form['date']        = post('date');
    $form['note']        = post('note');

    $amount = parseAmount($form['amount']);
    $err = '';
    $allowedIds = array_map('intval', array_column($walletList, 'id'));
    if (!in_array($form['wallet_id'], $allowedIds, true)) {
        $err = 'اختر المحفظة';
    } elseif ($amount === null) {
        $err = 'أدخل مبلغاً صحيحاً أكبر من صفر';
    } elseif (!validDate($form['date']) || $form['date'] > date('Y-m-d')) {
        $err = 'تاريخ غير صحيح (لا يمكن أن يكون في المستقبل)';
    }
    if (!$err) {
        try {
            $receipt = wlSaveReceipt(isset($_FILES['receipt']) ? $_FILES['receipt'] : null);
            try {
                wlExpense($pdo, $form['wallet_id'], $amount, $form['category_id'], $form['date'], $form['note'], $receipt, $me['id']);
            } catch (Exception $ex) {
                if ($receipt) {
                    @unlink(__DIR__ . '/uploads/receipts/' . $receipt);
                }
                throw $ex;
            }
            $w = wlGetWallet($pdo, $form['wallet_id']);
            flash('success', '✅ تم تسجيل مصروف ' . money($amount) . ' — الرصيد الحالي لـ«' . $w['name'] . '»: ' . money($w['balance']));
            redirect('expense.php' . ($admin ? '?wallet=' . (int)$form['wallet_id'] : ''));
        } catch (WalletError $ex) {
            $err = $ex->getMessage();
        } catch (Exception $ex) {
            error_log('expense save: ' . $ex->getMessage());
            $err = 'حدث خطأ أثناء الحفظ';
        }
    }
    if ($err) {
        flash('error', $err);
    }
}

$pageTitle = 'مصروف جديد';
$active = 'expense';
require __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:720px;margin:0 auto">
  <h1>➖ تسجيل مصروف</h1>
  <form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>

    <?php if ($admin): ?>
      <div class="form-row">
        <label>من محفظة</label>
        <select name="wallet_id" required>
          <option value="">— اختر —</option>
          <?php foreach ($walletList as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $form['wallet_id'] ? 'selected' : '' ?>>
              <?= $w['is_main'] ? '🏦 ' : '👤 ' ?><?= e($w['name']) ?> — <?= e(money($w['balance'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php else: $w = $walletList[0]; ?>
      <div class="alert alert-info">رصيدك المتاح: <b class="num"><?= e(money($w['balance'])) ?></b></div>
    <?php endif; ?>

    <div class="form-row">
      <label>المبلغ (<?= e(setting($pdo, 'currency', 'ل.س')) ?>)</label>
      <input class="big" type="text" name="amount" inputmode="decimal" data-money value="<?= e($form['amount']) ?>" placeholder="0" required autocomplete="off">
    </div>

    <div class="form-row">
      <label>التصنيف</label>
      <div class="cats">
        <?php foreach ($cats as $c): ?>
          <label><input type="radio" name="category_id" value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $form['category_id'] ? 'checked' : '' ?> required><span><?= e($c['icon']) ?> <?= e($c['name']) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="grid g2">
      <div class="form-row">
        <label>التاريخ</label>
        <input type="date" name="date" value="<?= e($form['date']) ?>" max="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-row">
        <label>صورة الإيصال (اختياري)</label>
        <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf">
        <div class="hint">JPG / PNG / WEBP / PDF — حتى 5MB</div>
      </div>
    </div>

    <div class="form-row">
      <label>البيان / ملاحظة</label>
      <textarea name="note" maxlength="500" placeholder="مثال: بنزين سيارة التوزيع — طرطوس"><?= e($form['note']) ?></textarea>
    </div>

    <button class="btn btn-block">حفظ المصروف</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php';
