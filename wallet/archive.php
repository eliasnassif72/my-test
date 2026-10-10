<?php
// تسليم العهدة وأرشفة محفظة (موظف ترك العمل) — واستعادتها
require __DIR__ . '/includes/bootstrap.php';
$me = requireAdmin();

$wallet = wlGetWallet($pdo, (int)(post('wallet_id') !== '' ? post('wallet_id') : get('wallet')));
if (!$wallet || $wallet['is_main']) {
    flash('error', 'المحفظة غير موجودة');
    redirect('users.php');
}

// محفظة شخصية لمدير: تُؤرشف لكن حسابه لا يُوقف
$ownerAdmin = false;
if ($wallet['user_id']) {
    $st = $pdo->prepare("SELECT role FROM wl_users WHERE id = ?");
    $st->execute([(int)$wallet['user_id']]);
    $ownerAdmin = $st->fetchColumn() === 'admin';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    try {
        if (post('action') === 'restore') {
            wlRestoreWallet($pdo, $wallet['id']);
            flash('success', 'تمت استعادة محفظة «' . $wallet['name'] . '» وتفعيل حساب صاحبها');
            redirect('wallet.php?id=' . (int)$wallet['id']);
        }
        $tr = wlGetWallet($pdo, (int)post('treasury'));
        $fx = post('fx') !== '' ? parseRate(post('fx')) : null;
        $settled = wlArchiveWallet($pdo, $wallet['id'], (int)post('treasury'), $fx, post('note'), $me['id']);
        $msg = 'تمت أرشفة «' . $wallet['name'] . '»' . ($ownerAdmin ? '' : ' وإيقاف حسابه');
        if ($settled > 0) {
            $msg .= ' — أُرجع ' . money($settled, true, $wallet['currency']) . ' إلى «' . $tr['name'] . '»';
        } elseif ($settled < 0) {
            $msg .= ' — دُفع له ' . money(-$settled, true, $wallet['currency']) . ' من «' . $tr['name'] . '»';
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
$treasuries = wlTreasuries($pdo);
$defT = 0;
foreach ($treasuries as $t) {
    if ($t['currency'] === $wallet['currency']) { $defT = (int)$t['id']; break; }
}
if (!$defT && $treasuries) $defT = (int)$treasuries[0]['id'];
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
  <div class="stat hero" style="margin-bottom:14px"><div class="lbl">الرصيد الحالي</div><div class="val num"><?= e(money($bal, true, $wallet['currency'])) ?></div></div>
  <p>عند التأكيد سيتم:</p>
  <ol>
    <?php if ($bal > 0): ?>
      <li>إرجاع <b class="num"><?= e(money($bal, true, $wallet['currency'])) ?></b> (العهدة المتبقية) إلى المحفظة الرئيسية المختارة أدناه.</li>
    <?php elseif ($bal < 0): ?>
      <li>دفع <b class="num"><?= e(money(-$bal, true, $wallet['currency'])) ?></b> له (مستحق له) من المحفظة الرئيسية المختارة أدناه.</li>
    <?php else: ?>
      <li>الرصيد صفر — لا حاجة لتسوية.</li>
    <?php endif; ?>
    <?php if ($ownerAdmin): ?>
      <li>إخفاء المحفظة من الداشبورد والقوائم. <b>حساب الدخول يبقى فعّالاً</b> لأن صاحبها مدير نظام.</li>
    <?php else: ?>
      <li>إخفاء المحفظة من الداشبورد والقوائم وإيقاف حساب الدخول.</li>
    <?php endif; ?>
    <li>الإبقاء على كامل سجل الحركات للتدقيق، مع إمكانية الاستعادة لاحقاً.</li>
  </ol>
  <p class="sub">إذا سلّم جزءاً من العهدة كمصاريف لم تُسجّل بعد، سجّلها أولاً من «تسجيل مصروف» قبل الأرشفة.</p>
  <form method="post" data-confirm="تأكيد تسليم العهدة وأرشفة المحفظة؟">
    <?= csrfField() ?>
    <input type="hidden" name="wallet_id" value="<?= (int)$wallet['id'] ?>">
    <?php if ($bal != 0): ?>
    <div class="form-row"><label>التسوية عبر المحفظة الرئيسية</label>
      <select name="treasury" id="arcT">
        <?php foreach ($treasuries as $t): ?>
          <option value="<?= (int)$t['id'] ?>" data-cur="<?= e($t['currency']) ?>" <?= (int)$t['id'] === $defT ? 'selected' : '' ?>>🏦 <?= e($t['name']) ?> — <?= e(money($t['balance'], true, $t['currency'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row" id="arcFx" style="display:none">
      <label>سعر الصرف: 1 <span id="arcHi"></span> = ؟ <span id="arcLo"></span></label>
      <input type="text" name="fx" id="arcFxIn" inputmode="decimal" style="direction:ltr;text-align:center;font-weight:800">
    </div>
    <script>
    (function () {
      var W = <?= json_encode($wallet['currency']) ?>, BASE = <?= json_encode(baseCurrency()) ?>;
      var CUR = <?php $cj = []; foreach (wlCurrencies() as $c) { $cj[$c['code']] = ['s' => $c['symbol'], 'r' => (float)$c['rate']]; } echo json_encode($cj, JSON_UNESCAPED_UNICODE); ?>;
      var sel = document.getElementById('arcT'), box = document.getElementById('arcFx'), inp = document.getElementById('arcFxIn');
      function rate(c) { return c === BASE ? 1 : (CUR[c] ? CUR[c].r : 0); }
      function hi(a, b) { if (a === BASE && b !== BASE) return b; if (b === BASE && a !== BASE) return a; return rate(a) >= rate(b) ? a : b; }
      function sync() {
        var t = sel.options[sel.selectedIndex].getAttribute('data-cur');
        if (t === W) { box.style.display = 'none'; inp.required = false; return; }
        var h = hi(W, t), l = h === W ? t : W;
        document.getElementById('arcHi').textContent = CUR[h] ? CUR[h].s : h;
        document.getElementById('arcLo').textContent = CUR[l] ? CUR[l].s : l;
        if (!inp.value) { var d = rate(l) > 0 ? rate(h) / rate(l) : 0; inp.value = d > 0 ? d : ''; }
        box.style.display = ''; inp.required = true;
      }
      sel.addEventListener('change', function () { inp.value = ''; sync(); });
      sync();
    })();
    </script>
    <?php else: ?>
      <input type="hidden" name="treasury" value="<?= $defT ?>">
    <?php endif; ?>
    <div class="form-row"><label>ملاحظة التسليم</label><input type="text" name="note" maxlength="500" placeholder="مثال: ترك العمل وسلّم العهدة نقداً بتاريخ ..."></div>
    <div class="actions">
      <button class="btn btn-red">تأكيد التسليم والأرشفة</button>
      <a class="btn btn-gray" href="wallet.php?id=<?= (int)$wallet['id'] ?>">رجوع</a>
    </div>
  </form>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php';
