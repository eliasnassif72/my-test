<?php
// حركة الأموال (للمدير): تحويل بين أي محفظتين مع تحويل العملة، إيداع وسحب من المحافظ الرئيسية
require __DIR__ . '/includes/bootstrap.php';
$me = requireAdmin();

$all = $pdo->query("SELECT id, name, currency, balance, is_main, active FROM wl_wallets
                    WHERE archived = 0 ORDER BY is_main DESC, active DESC, name")->fetchAll();
$byId = [];
$treasuries = [];
$staff = [];
foreach ($all as $w) {
    $byId[(int)$w['id']] = $w;
    if ((int)$w['is_main'] === 1) {
        $treasuries[] = $w;
    } else {
        $staff[] = $w;
    }
}

$ops = [
    'transfer' => ['💸 تحويل بين المحافظ', 'تغذية موظف، إرجاع للرئيسية، أو بين البنوك — مع تحويل العملة'],
    'deposit'  => ['🏦 إيداع في محفظة رئيسية', 'إضافة رصيد من خارج النظام'],
    'withdraw' => ['🏧 سحب من محفظة رئيسية', 'إخراج مبلغ من النظام'],
];

// اختيار خزينة افتراضية بنفس عملة المحفظة إن وُجدت
$pickTreasury = function ($cur) use ($treasuries) {
    foreach ($treasuries as $t) {
        if ($t['currency'] === $cur) {
            return (int)$t['id'];
        }
    }
    return $treasuries ? (int)$treasuries[0]['id'] : 0;
};

$op = get('op', 'transfer');
$wq = (int)get('wallet');
$form = ['op' => 'transfer', 'from' => 0, 'to' => 0, 'treasury' => 0, 'amount' => '', 'fx' => '', 'date' => date('Y-m-d'), 'note' => ''];
if ($op === 'topup') {
    $form['to'] = $wq;
    $form['from'] = isset($byId[$wq]) ? $pickTreasury($byId[$wq]['currency']) : $pickTreasury(baseCurrency());
} elseif ($op === 'return') {
    $form['from'] = $wq;
    $form['to'] = isset($byId[$wq]) ? $pickTreasury($byId[$wq]['currency']) : $pickTreasury(baseCurrency());
} elseif ($op === 'deposit' || $op === 'withdraw') {
    $form['op'] = $op;
    $form['treasury'] = isset($byId[$wq]) && $byId[$wq]['is_main'] ? $wq : $pickTreasury(baseCurrency());
} else {
    $form['from'] = $pickTreasury(baseCurrency());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $form['op']       = isset($ops[post('op')]) ? post('op') : '';
    $form['from']     = (int)post('from');
    $form['to']       = (int)post('to');
    $form['treasury'] = (int)post('treasury');
    $form['amount']   = post('amount');
    $form['fx']       = post('fx');
    $form['date']     = post('date');
    $form['note']     = post('note');

    $amount = parseAmount($form['amount']);
    $err = '';
    if ($form['op'] === '') {
        $err = 'اختر نوع العملية';
    } elseif ($amount === null) {
        $err = 'أدخل مبلغاً صحيحاً أكبر من صفر';
    } elseif (!validDate($form['date']) || $form['date'] > date('Y-m-d')) {
        $err = 'تاريخ غير صحيح (لا يمكن أن يكون في المستقبل)';
    } elseif ($form['op'] === 'transfer' && (!isset($byId[$form['from']]) || !isset($byId[$form['to']]))) {
        $err = 'اختر المحفظتين';
    } elseif ($form['op'] !== 'transfer' && !isset($byId[$form['treasury']])) {
        $err = 'اختر المحفظة الرئيسية';
    }
    if (!$err) {
        try {
            if ($form['op'] === 'transfer') {
                $fx = $form['fx'] !== '' ? parseRate($form['fx']) : null;
                wlTransfer($pdo, $form['from'], $form['to'], $amount, $form['date'], $form['note'], $me['id'], $fx);
                $f = wlGetWallet($pdo, $form['from']);
                $t = wlGetWallet($pdo, $form['to']);
                $msg = '✅ تم التحويل من «' . $f['name'] . '» (' . money($amount, true, $f['currency']) . ')';
                if ($f['currency'] !== $t['currency']) {
                    $msg .= ' ← وصل إلى «' . $t['name'] . '» ' . money(wlFxConvert($amount, $f['currency'], $t['currency'], $fx), true, $t['currency'])
                          . ' بسعر ' . wlFxLabel($f['currency'], $t['currency'], $fx);
                } else {
                    $msg .= ' إلى «' . $t['name'] . '»';
                }
                $msg .= ' · رصيد «' . $f['name'] . '»: ' . money($f['balance'], true, $f['currency'])
                      . ' · رصيد «' . $t['name'] . '»: ' . money($t['balance'], true, $t['currency']);
                flash('success', $msg);
                redirect('fund.php?op=transfer');
            }
            if ($form['op'] === 'deposit') {
                wlDeposit($pdo, $form['treasury'], $amount, $form['date'], $form['note'], $me['id']);
            } else {
                wlWithdraw($pdo, $form['treasury'], $amount, $form['date'], $form['note'], $me['id']);
            }
            $t = wlGetWallet($pdo, $form['treasury']);
            flash('success', '✅ ' . $ops[$form['op']][0] . ': ' . money($amount, true, $t['currency']) . ' — رصيد «' . $t['name'] . '» الآن ' . money($t['balance'], true, $t['currency']));
            redirect('fund.php?op=' . $form['op'] . '&wallet=' . $form['treasury']);
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

// بيانات العملات للمعاينة الفورية في المتصفح
$curJs = [];
foreach (wlCurrencies() as $c) {
    $curJs[$c['code']] = ['symbol' => $c['symbol'], 'rate' => (float)$c['rate']];
}

$walletOption = function ($w, $selected) {
    return '<option value="' . (int)$w['id'] . '" data-cur="' . e($w['currency']) . '"' . ((int)$w['id'] === (int)$selected ? ' selected' : '')
        . ((int)$w['active'] !== 1 ? ' data-off="1"' : '') . '>'
        . ($w['is_main'] ? '🏦 ' : '👤 ') . e($w['name']) . ' — ' . e(money($w['balance'], true, $w['currency']))
        . ((int)$w['active'] !== 1 ? ' (موقوفة)' : '') . '</option>';
};
$walletSelect = function ($name, $selected) use ($treasuries, $staff, $walletOption) {
    $h = '<select name="' . $name . '" id="sel-' . $name . '"><option value="">— اختر —</option><optgroup label="المحافظ الرئيسية">';
    foreach ($treasuries as $w) {
        $h .= $walletOption($w, $selected);
    }
    $h .= '</optgroup><optgroup label="محافظ الموظفين">';
    foreach ($staff as $w) {
        $h .= $walletOption($w, $selected);
    }
    return $h . '</optgroup></select>';
};

$pageTitle = 'حركة الأموال';
$active = 'fund';
require __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:780px;margin:0 auto">
  <h1>💸 حركة الأموال</h1>
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

    <div id="transferRows">
      <div class="grid g2">
        <div class="form-row"><label>من محفظة</label><?= $walletSelect('from', $form['from']) ?></div>
        <div class="form-row"><label>إلى محفظة</label><?= $walletSelect('to', $form['to']) ?></div>
      </div>
    </div>

    <div class="form-row" id="treasuryRow">
      <label>المحفظة الرئيسية</label>
      <select name="treasury" id="sel-treasury">
        <?php foreach ($treasuries as $w): ?>
          <option value="<?= (int)$w['id'] ?>" data-cur="<?= e($w['currency']) ?>" <?= (int)$w['id'] === (int)$form['treasury'] ? 'selected' : '' ?>>🏦 <?= e($w['name']) ?> — <?= e(money($w['balance'], true, $w['currency'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-row">
      <label>المبلغ <span id="amtCur" class="sub"></span></label>
      <input class="big" type="text" name="amount" id="amount" inputmode="decimal" data-money value="<?= e($form['amount']) ?>" placeholder="0" required autocomplete="off">
    </div>

    <div class="card" id="fxBox" style="background:var(--primary-bg);display:none">
      <label>سعر الصرف</label>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <b>1 <span id="fxHi"></span> =</b>
        <input type="text" name="fx" id="fx" inputmode="decimal" value="<?= e($form['fx']) ?>" style="max-width:180px;direction:ltr;text-align:center;font-weight:800" autocomplete="off">
        <b id="fxLo"></b>
      </div>
      <div style="margin-top:10px;font-size:1.1rem">يصل إلى المحفظة: <b class="num pos" id="fxResult">—</b></div>
      <div class="hint">السعر معبّأ من آخر سعر محفوظ ويمكن تعديله لهذه العملية، ويُحفظ كسعر افتراضي للمرة القادمة.</div>
    </div>

    <div class="grid g2">
      <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= e($form['date']) ?>" max="<?= date('Y-m-d') ?>" required></div>
      <div class="form-row"><label>ملاحظة</label><input type="text" name="note" maxlength="500" value="<?= e($form['note']) ?>" placeholder="مثال: سلفة مصاريف شهر تشرين"></div>
    </div>
    <button class="btn btn-block">تنفيذ</button>
  </form>
  <p class="hint">لإضافة محفظة رئيسية (بنك، شام كاش، صندوق دولار…) أو تعديل أسعار الصرف: <a href="treasuries.php">المحافظ الرئيسية والعملات</a>.</p>
</div>
<script>
(function () {
  var CUR = <?= json_encode($curJs, JSON_UNESCAPED_UNICODE) ?>, BASE = <?= json_encode(baseCurrency()) ?>;
  var f = document.getElementById('fundForm');
  var selFrom = document.getElementById('sel-from'), selTo = document.getElementById('sel-to'), selT = document.getElementById('sel-treasury');
  var fx = document.getElementById('fx'), amount = document.getElementById('amount');
  var fxTouched = fx.value !== '';
  function cur(sel) { var o = sel.options[sel.selectedIndex]; return o ? o.getAttribute('data-cur') : null; }
  function sym(c) { return CUR[c] ? CUR[c].symbol : c; }
  function rate(c) { return c === BASE ? 1 : (CUR[c] ? CUR[c].rate : 0); }
  function hi(a, b) { if (a === BASE && b !== BASE) return b; if (b === BASE && a !== BASE) return a; return rate(a) >= rate(b) ? a : b; }
  function num(v) { v = String(v).replace(/[^\d.]/g, ''); return v ? parseFloat(v) : 0; }
  function fmt(n) { return n.toLocaleString('en-US', { maximumFractionDigits: 2 }); }
  function sync() {
    var op = (f.querySelector('input[name=op]:checked') || {}).value;
    var tr = op === 'transfer';
    document.getElementById('transferRows').style.display = tr ? '' : 'none';
    document.getElementById('treasuryRow').style.display = tr ? 'none' : '';
    selFrom.required = selTo.required = tr;
    var a = tr ? cur(selFrom) : cur(selT), b = tr ? cur(selTo) : null;
    document.getElementById('amtCur').textContent = a ? '(' + sym(a) + ')' : '';
    var box = document.getElementById('fxBox');
    if (tr && a && b && a !== b) {
      var h = hi(a, b), l = h === a ? b : a;
      document.getElementById('fxHi').textContent = sym(h);
      document.getElementById('fxLo').textContent = sym(l);
      if (!fxTouched) { var d = rate(l) > 0 ? rate(h) / rate(l) : 0; fx.value = d > 0 ? d : ''; }
      fx.required = true;
      box.style.display = '';
      var amt = num(amount.value), r = num(fx.value), out = 0;
      if (amt > 0 && r > 0) out = (h === a) ? amt * r : amt / r;
      document.getElementById('fxResult').textContent = out > 0 ? fmt(Math.round(out * 100) / 100) + ' ' + sym(b) : '—';
    } else {
      fx.required = false;
      box.style.display = 'none';
    }
  }
  f.querySelectorAll('input[name=op]').forEach(function (r) { r.addEventListener('change', sync); });
  [selFrom, selTo, selT].forEach(function (s) { s.addEventListener('change', function () { fxTouched = false; sync(); }); });
  fx.addEventListener('input', function () { fxTouched = true; sync(); });
  amount.addEventListener('input', sync);
  sync();
})();
</script>
<?php require __DIR__ . '/includes/footer.php';
