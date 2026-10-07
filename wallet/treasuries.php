<?php
// المحافظ الرئيسية (بنوك، شام كاش، صندوق…) والعملات وأسعار الصرف
require __DIR__ . '/includes/bootstrap.php';
$me = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = post('action');
    try {
        if ($action === 'create_treasury') {
            $name = mb_substr(post('name'), 0, 120);
            $cur  = post('currency');
            $c    = wlCurrencies();
            if ($name === '') throw new WalletError('أدخل اسم المحفظة');
            if (!isset($c[$cur]) || !(int)$c[$cur]['active']) throw new WalletError('اختر العملة');
            if ($cur !== baseCurrency() && (float)$c[$cur]['rate'] <= 0) throw new WalletError('حدّد سعر صرف ' . $c[$cur]['name'] . ' أولاً من جدول العملات');
            $pdo->prepare("INSERT INTO wl_wallets (user_id, name, currency, is_main) VALUES (NULL, ?, ?, 1)")->execute([$name, $cur]);
            flash('success', 'تمت إضافة المحفظة الرئيسية «' . $name . '» — يمكنك ضبط رصيد أول المدة لها');
        } elseif ($action === 'rename') {
            $name = mb_substr(post('name'), 0, 120);
            if ($name === '') throw new WalletError('أدخل الاسم');
            $pdo->prepare("UPDATE wl_wallets SET name = ? WHERE id = ? AND is_main = 1")->execute([$name, (int)post('id')]);
            flash('success', 'تم تغيير الاسم');
        } elseif ($action === 'archive_treasury') {
            $w = wlGetWallet($pdo, (int)post('id'));
            if (!$w || !$w['is_main']) throw new WalletError('المحفظة غير موجودة');
            if (round((float)$w['balance'], 2) != 0) throw new WalletError('رصيد «' . $w['name'] . '» ليس صفراً — حوّل رصيدها أولاً');
            if (count(wlTreasuries($pdo)) <= 1) throw new WalletError('يجب أن تبقى محفظة رئيسية واحدة على الأقل');
            $pdo->prepare("UPDATE wl_wallets SET archived = 1, archived_at = NOW(), active = 0 WHERE id = ?")->execute([$w['id']]);
            flash('success', 'تمت أرشفة «' . $w['name'] . '»');
        } elseif ($action === 'restore_treasury') {
            $pdo->prepare("UPDATE wl_wallets SET archived = 0, archived_at = NULL, active = 1 WHERE id = ? AND is_main = 1")->execute([(int)post('id')]);
            flash('success', 'تمت الاستعادة');
        } elseif ($action === 'save_currency') {
            $code = strtoupper(post('code'));
            if (!preg_match('/^[A-Z]{3,8}$/', $code)) throw new WalletError('رمز العملة 3 أحرف إنكليزية (مثل USD, EUR)');
            $name = mb_substr(post('cname'), 0, 50);
            $sym  = mb_substr(post('symbol'), 0, 12);
            if ($name === '' || $sym === '') throw new WalletError('أدخل اسم العملة ورمزها');
            if ($code === baseCurrency()) {
                $pdo->prepare("UPDATE wl_currencies SET name = ?, symbol = ? WHERE code = ?")->execute([$name, $sym, $code]);
            } else {
                $rate = parseRate(post('rate'));
                if ($rate === null) throw new WalletError('أدخل سعر صرف صحيحاً أكبر من صفر');
                $pdo->prepare("INSERT INTO wl_currencies (code, name, symbol, rate, sort_order, updated_at) VALUES (?, ?, ?, ?, 100, NOW())
                               ON DUPLICATE KEY UPDATE name = VALUES(name), symbol = VALUES(symbol), rate = VALUES(rate), updated_at = NOW()")
                    ->execute([$code, $name, $sym, $rate]);
            }
            flash('success', 'تم حفظ العملة ' . $code);
        }
    } catch (WalletError $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('treasuries.php');
}

$from = date('Y-m-01');
$to   = date('Y-m-t');
$rows = wlWalletSummaries($pdo, $from, $to, false, null, true);
$treas = [];
$archivedT = [];
foreach ($rows as $r) {
    if ((int)$r['is_main'] !== 1) continue;
    if ((int)$r['archived'] === 1) $archivedT[] = $r; else $treas[] = $r;
}
$curs = wlCurrencies(true);
$base = baseCurrency();

$pageTitle = 'المحافظ الرئيسية';
$active = 'treasuries';
require __DIR__ . '/includes/header.php';
?>
<h1>🏦 المحافظ الرئيسية والعملات</h1>

<div class="grid wallets" style="margin-bottom:16px">
  <?php foreach ($treas as $t): ?>
    <div class="wcard">
      <div class="wname">🏦 <?= e($t['name']) ?> <span class="pill p-opening"><?= e($t['currency']) ?></span></div>
      <a href="wallet.php?id=<?= (int)$t['id'] ?>" class="wbal num" style="display:block;color:inherit"><?= e(money($t['balance'], true, $t['currency'])) ?></a>
      <?php if ($t['currency'] !== $base && currencyRate($t['currency']) > 0): ?>
        <div class="wmeta">≈ <span class="num"><?= e(money($t['balance'] * currencyRate($t['currency']))) ?></span></div>
      <?php endif; ?>
      <form method="post" style="display:flex;gap:6px;margin-top:8px">
        <?= csrfField() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <input type="text" name="name" value="<?= e($t['name']) ?>" style="padding:5px 8px"><button class="btn btn-gray btn-sm">حفظ</button>
      </form>
      <div class="actions" style="margin-top:8px">
        <a class="btn btn-light btn-sm" href="fund.php?op=deposit&wallet=<?= (int)$t['id'] ?>">إيداع</a>
        <a class="btn btn-light btn-sm" href="opening.php?wallet=<?= (int)$t['id'] ?>">رصيد أول المدة</a>
        <?php if (round((float)$t['balance'], 2) == 0 && count($treas) > 1): ?>
        <form method="post" data-confirm="أرشفة «<?= e($t['name']) ?>»؟"><?= csrfField() ?><input type="hidden" name="action" value="archive_treasury"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-gray btn-sm">📦</button></form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="card" style="margin:0">
    <h2>➕ محفظة رئيسية جديدة</h2>
    <form method="post">
      <?= csrfField() ?><input type="hidden" name="action" value="create_treasury">
      <div class="form-row"><input type="text" name="name" required placeholder="مثال: بنك بيمو دولار / شام كاش / الصندوق"></div>
      <div class="form-row">
        <select name="currency">
          <?php foreach ($curs as $c): if (!(int)$c['active']) continue; ?>
            <option value="<?= e($c['code']) ?>"><?= e($c['name'] . ' (' . $c['symbol'] . ')') ?><?= ($c['code'] !== $base && (float)$c['rate'] <= 0) ? ' — حدّد السعر أولاً' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-block">إضافة</button>
    </form>
  </div>
</div>

<?php if ($archivedT): ?>
<div class="card">
  <h2>📦 محافظ رئيسية مؤرشفة</h2>
  <?php foreach ($archivedT as $t): ?>
    <form method="post" class="actions" style="margin-bottom:6px"><?= csrfField() ?><input type="hidden" name="action" value="restore_treasury"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
      <span><?= e($t['name']) ?> (<?= e($t['currency']) ?>)</span><button class="btn btn-light btn-sm">↩️ استعادة</button></form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" id="currencies">
  <h2>💱 العملات وأسعار الصرف</h2>
  <p class="sub">السعر = قيمة وحدة واحدة من العملة بـ<?= e(currencySymbol($base)) ?>. يُستخدم كسعر افتراضي عند التحويل بين العملات (ويمكن تعديله في كل تحويل)، ولحساب المعادل بالليرة في التقارير.</p>
  <div class="tbl-wrap"><table>
    <thead><tr><th>الرمز</th><th>الاسم</th><th>العلامة</th><th>السعر (1 = ؟ <?= e(currencySymbol($base)) ?>)</th><th>آخر تحديث</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($curs as $c): $fid = 'cur' . e($c['code']); $isBase = $c['code'] === $base; ?>
      <tr>
        <td><b dir="ltr"><?= e($c['code']) ?></b><?= $isBase ? ' <span class="pill p-deposit">أساسية</span>' : '' ?></td>
        <td><input form="<?= $fid ?>" type="text" name="cname" value="<?= e($c['name']) ?>"></td>
        <td style="width:90px"><input form="<?= $fid ?>" type="text" name="symbol" value="<?= e($c['symbol']) ?>"></td>
        <td style="width:170px"><?php if ($isBase): ?>1<?php else: ?>
          <input form="<?= $fid ?>" type="text" name="rate" inputmode="decimal" value="<?= (float)$c['rate'] > 0 ? e(plainNumber($c['rate'])) : '' ?>" placeholder="غير محدد" style="direction:ltr;text-align:center<?= (float)$c['rate'] <= 0 ? ';border-color:#F87171' : '' ?>">
        <?php endif; ?></td>
        <td class="sub num"><?= e($c['updated_at'] ? substr($c['updated_at'], 0, 16) : '—') ?></td>
        <td><form id="<?= $fid ?>" method="post"><?= csrfField() ?><input type="hidden" name="action" value="save_currency"><input type="hidden" name="code" value="<?= e($c['code']) ?>"><button class="btn btn-sm">حفظ</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <h3 style="font-size:1rem;margin-top:16px">➕ عملة جديدة</h3>
  <form method="post" class="filters">
    <?= csrfField() ?><input type="hidden" name="action" value="save_currency">
    <div class="form-row"><label>الرمز</label><input type="text" name="code" placeholder="EUR" maxlength="8" dir="ltr" required></div>
    <div class="form-row"><label>الاسم</label><input type="text" name="cname" placeholder="يورو" required></div>
    <div class="form-row"><label>العلامة</label><input type="text" name="symbol" placeholder="€" required></div>
    <div class="form-row"><label>السعر بـ<?= e(currencySymbol($base)) ?></label><input type="text" name="rate" inputmode="decimal" dir="ltr" required></div>
    <div class="form-row"><button class="btn">إضافة</button></div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php';
