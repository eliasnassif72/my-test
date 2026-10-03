<?php
// عمليات المدير على الأموال: إيداع في الرئيسية، تغذية محفظة، إرجاع للرئيسية، سحب من الرئيسية
require __DIR__ . '/includes/bootstrap.php';
$me = requireAdmin();

$main    = wlMainWallet($pdo);
$wallets = $pdo->query("SELECT id, name, balance, active FROM wl_wallets WHERE is_main = 0 ORDER BY active DESC, name")->fetchAll();

$ops = [
    'topup'   => ['💸 تغذية محفظة', 'من الرئيسية ← إلى محفظة مستخدم'],
    'return'  => ['↩️ إرجاع للرئيسية', 'من محفظة مستخدم ← إلى الرئيسية'],
    'deposit' => ['🏦 إيداع في الرئيسية', 'إضافة رأس مال / نقد للنظام'],
    'withdraw'=> ['🏧 سحب من الرئيسية', 'إخراج نقد من النظام'],
];

$form = [
    'op'        => isset($ops[get('op')]) ? get('op') : 'topup',
    'wallet_id' => (int)get('wallet', 0),
    'amount'    => '',
    'date'      => date('Y-m-d'),
    'note'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $form['op']        = isset($ops[post('op')]) ? post('op') : '';
    $form['wallet_id'] = (int)post('wallet_id');
    $form['amount']    = post('amount');
    $form['date']      = post('date');
    $form['note']      = post('note');

    $amount = parseAmount($form['amount']);
    $err = '';
    $needsWallet = in_array($form['op'], ['topup', 'return'], true);
    if ($form['op'] === '') {
        $err = 'اختر نوع العملية';
    } elseif ($needsWallet && !in_array($form['wallet_id'], array_map('intval', array_column($wallets, 'id')), true)) {
        $err = 'اختر المحفظة';
    } elseif ($amount === null) {
        $err = 'أدخل مبلغاً صحيحاً أكبر من صفر';
    } elseif (!validDate($form['date']) || $form['date'] > date('Y-m-d')) {
        $err = 'تاريخ غير صحيح (لا يمكن أن يكون في المستقبل)';
    }
    if (!$err) {
        try {
            switch ($form['op']) {
                case 'deposit':
                    wlDeposit($pdo, $amount, $form['date'], $form['note'], $me['id']);
                    break;
                case 'withdraw':
                    wlWithdraw($pdo, $amount, $form['date'], $form['note'], $me['id']);
                    break;
                case 'topup':
                    wlTransfer($pdo, $main['id'], $form['wallet_id'], $amount, $form['date'], $form['note'], $me['id']);
                    break;
                case 'return':
                    wlTransfer($pdo, $form['wallet_id'], $main['id'], $amount, $form['date'], $form['note'], $me['id']);
                    break;
            }
            $m = wlMainWallet($pdo);
            $msg = '✅ ' . $ops[$form['op']][0] . ': ' . money($amount) . ' — رصيد الرئيسية الآن ' . money($m['balance']);
            if ($needsWallet) {
                $w = wlGetWallet($pdo, $form['wallet_id']);
                $msg .= ' · رصيد «' . $w['name'] . '»: ' . money($w['balance']);
            }
            flash('success', $msg);
            redirect('fund.php?op=' . $form['op'] . ($needsWallet ? '&wallet=' . $form['wallet_id'] : ''));
        } catch (WalletError $ex) {
            $err = $ex->getMessage();
        } catch (Exception $ex) {
            error_log('fund: ' . $ex->getMessage());
            $err = 'حدث خطأ أثناء الحفظ';
        }
    }
    if ($err) {
        flash('error', $err);
    }
}

$pageTitle = 'تغذية / إيداع';
$active = 'fund';
require __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:760px;margin:0 auto">
  <h1>💸 حركة أموال</h1>
  <div class="alert alert-info">🏦 رصيد المحفظة الرئيسية: <b class="num"><?= e(money($main['balance'])) ?></b></div>
  <form method="post" id="fundForm">
    <?= csrfField() ?>
    <div class="form-row">
      <label>نوع العملية</label>
      <div class="ops">
        <?php foreach ($ops as $k => $o): ?>
          <label><input type="radio" name="op" value="<?= e($k) ?>" <?= $form['op'] === $k ? 'checked' : '' ?>><span><?= e($o[0]) ?><small><?= e($o[1]) ?></small></span></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-row" id="walletRow">
      <label>محفظة المستخدم</label>
      <select name="wallet_id">
        <option value="">— اختر —</option>
        <?php foreach ($wallets as $w): ?>
          <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $form['wallet_id'] ? 'selected' : '' ?> data-off="<?= (int)$w['active'] !== 1 ? 1 : 0 ?>">
            <?= e($w['name']) ?> — <?= e(money($w['balance'])) ?><?= (int)$w['active'] !== 1 ? ' (موقوفة)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-row">
      <label>المبلغ (<?= e(setting($pdo, 'currency', 'ل.س')) ?>)</label>
      <input class="big" type="text" name="amount" inputmode="decimal" data-money value="<?= e($form['amount']) ?>" placeholder="0" required autocomplete="off">
    </div>
    <div class="grid g2">
      <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= e($form['date']) ?>" max="<?= date('Y-m-d') ?>" required></div>
      <div class="form-row"><label>ملاحظة</label><input type="text" name="note" maxlength="500" value="<?= e($form['note']) ?>" placeholder="مثال: سلفة مصاريف شهر تشرين"></div>
    </div>
    <button class="btn btn-block">تنفيذ</button>
  </form>
</div>
<script>
(function () {
  var f = document.getElementById('fundForm'), row = document.getElementById('walletRow');
  function sync() {
    var op = (f.querySelector('input[name=op]:checked') || {}).value;
    var need = op === 'topup' || op === 'return';
    row.style.display = need ? '' : 'none';
    var sel = row.querySelector('select');
    sel.required = need;
    // المحفظة الموقوفة: يُسمح فقط بإرجاع رصيدها للرئيسية
    sel.querySelectorAll('option[data-off="1"]').forEach(function (o) {
      o.disabled = op !== 'return';
      if (o.disabled && o.selected) sel.value = '';
    });
  }
  f.querySelectorAll('input[name=op]').forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>
<?php require __DIR__ . '/includes/footer.php';
