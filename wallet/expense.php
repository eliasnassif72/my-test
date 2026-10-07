<?php
require __DIR__ . '/includes/bootstrap.php';
$me = requireLogin();
$admin = $me['role'] === 'admin';

if ($admin) {
    $walletList = $pdo->query("SELECT id, name, balance, currency, is_main FROM wl_wallets WHERE active = 1 AND archived = 0 ORDER BY is_main DESC, name")->fetchAll();
} else {
    if (!$me['wallet_id']) {
        flash('error', 'لا توجد محفظة مرتبطة بحسابك');
        redirect('dashboard.php');
    }
    $walletList = [wlGetWallet($pdo, $me['wallet_id'])];
}
$cats = $pdo->query("SELECT * FROM wl_categories WHERE active = 1 ORDER BY sort_order, name")->fetchAll();
// تجميع التصنيفات حسب الحساب الأب (المجموعات مرتّبة حسب أكثر استخداماً)
$groups = [];
foreach ($cats as $c) {
    $g = $c['group_name'] ?: 'أخرى';
    $groups[$g][] = $c;
}
// اقتراحات البيان لكل تصنيف: من سجل الاستخدام الفعلي أولاً ثم من الاقتراحات المستوردة
$hints = [];
$st = $pdo->query("SELECT category_id, note, COUNT(*) AS n FROM wl_transactions
                   WHERE type = 'expense' AND voided = 0 AND note IS NOT NULL AND note <> '' AND CHAR_LENGTH(note) <= 60
                     AND txn_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                   GROUP BY category_id, note ORDER BY n DESC");
foreach ($st as $r) {
    $hints[(int)$r['category_id']][] = $r['note'];
}
foreach ($cats as $c) {
    $own = isset($hints[(int)$c['id']]) ? $hints[(int)$c['id']] : [];
    $seed = $c['hints'] ? explode("\n", $c['hints']) : [];
    $hints[(int)$c['id']] = array_slice(array_values(array_unique(array_merge($own, $seed))), 0, 10);
}

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
            flash('success', '✅ تم تسجيل مصروف ' . money($amount, true, $w['currency']) . ' — الرصيد الحالي لـ«' . $w['name'] . '»: ' . money($w['balance'], true, $w['currency']));
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
            <option value="<?= (int)$w['id'] ?>" data-sym="<?= e(currencySymbol($w['currency'])) ?>" <?= (int)$w['id'] === $form['wallet_id'] ? 'selected' : '' ?>>
              <?= $w['is_main'] ? '🏦 ' : '👤 ' ?><?= e($w['name']) ?> — <?= e(money($w['balance'], true, $w['currency'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php else: $w = $walletList[0]; ?>
      <div class="alert alert-info">رصيدك المتاح: <b class="num"><?= e(money($w['balance'], true, $w['currency'])) ?></b></div>
    <?php endif; ?>

    <div class="form-row">
      <label>المبلغ (<span id="expSym"><?= e(currencySymbol($admin ? null : $walletList[0]['currency'])) ?></span>)</label>
      <input class="big" type="text" name="amount" inputmode="decimal" data-money value="<?= e($form['amount']) ?>" placeholder="0" required autocomplete="off">
    </div>

    <div class="form-row">
      <label>التصنيف (حساب المصروف)</label>
      <input type="search" id="catSearch" placeholder="🔍 ابحث بالاسم أو رقم الحساب — مثال: بنزين أو 339001" autocomplete="off" style="margin-bottom:8px">
      <div id="catPicked" class="alert alert-success" style="display:none;margin-bottom:8px"></div>
      <div id="catGroups" style="max-height:420px;overflow:auto;border:1px solid var(--line);border-radius:12px;padding:8px">
        <?php foreach ($groups as $gname => $list): ?>
          <div class="cat-group">
            <div class="sub" style="font-weight:700;margin:6px 2px"><?= e($gname) ?></div>
            <div class="cats">
              <?php foreach ($list as $c): ?>
                <label data-q="<?= e(mb_strtolower($c['name'] . ' ' . $c['account_no'] . ' ' . $gname)) ?>" data-label="<?= e(($c['account_no'] ? $c['account_no'] . ' — ' : '') . $c['name']) ?>"><input type="radio" name="category_id" value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $form['category_id'] ? 'checked' : '' ?> required><span><?= e($c['icon']) ?> <?= e($c['name']) ?><?php if ($c['account_no']): ?><small class="sub" style="display:block;direction:ltr"><?= e($c['account_no']) ?></small><?php endif; ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
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
      <label>البيان</label>
      <input type="text" name="note" id="note" maxlength="500" list="noteHints" autocomplete="off" value="<?= e($form['note']) ?>" placeholder="مثال: شانا / خبز / غيار زيت">
      <datalist id="noteHints"></datalist>
      <div id="hintChips" class="actions" style="margin-top:6px"></div>
    </div>

    <button class="btn btn-block">حفظ المصروف</button>
  </form>
</div>
<script>
(function () {
  var HINTS = <?= json_encode($hints, JSON_UNESCAPED_UNICODE) ?>;
  var search = document.getElementById('catSearch'), groups = document.querySelectorAll('.cat-group');
  var note = document.getElementById('note'), dl = document.getElementById('noteHints'), chips = document.getElementById('hintChips');
  var picked = document.getElementById('catPicked');
  search.addEventListener('input', function () {
    var q = search.value.trim().toLowerCase();
    groups.forEach(function (g) {
      var any = false;
      g.querySelectorAll('label[data-q]').forEach(function (l) {
        var hit = !q || l.getAttribute('data-q').indexOf(q) !== -1;
        l.style.display = hit ? '' : 'none';
        if (hit) any = true;
      });
      g.style.display = any ? '' : 'none';
    });
  });
  function onPick() {
    var r = document.querySelector('input[name=category_id]:checked');
    dl.innerHTML = ''; chips.innerHTML = '';
    if (!r) { picked.style.display = 'none'; return; }
    picked.style.display = '';
    picked.textContent = '✔ ' + r.parentNode.getAttribute('data-label');
    (HINTS[r.value] || []).forEach(function (h) {
      var o = document.createElement('option'); o.value = h; dl.appendChild(o);
      var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-gray btn-sm'; b.textContent = h;
      b.addEventListener('click', function () { note.value = h; note.focus(); });
      chips.appendChild(b);
    });
  }
  document.querySelectorAll('input[name=category_id]').forEach(function (r) { r.addEventListener('change', onPick); });
  onPick();
})();
</script>
<?php if ($admin): ?>
<script>
(function () {
  var sel = document.querySelector('select[name=wallet_id]'), lbl = document.getElementById('expSym');
  function sync() { var o = sel.options[sel.selectedIndex]; if (o && o.getAttribute('data-sym')) lbl.textContent = o.getAttribute('data-sym'); }
  sel.addEventListener('change', sync); sync();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php';
