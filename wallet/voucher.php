<?php
// تصدير سند قيد لبرنامج المحاسبة (الأمين): مصاريف محفظة خلال فترة → Excel للنسخ واللصق في السند
// الأعمدة بنفس ترتيب شبكة السند: الحساب | البيان | مدين | دائن | العملة | التعادل | المكافئ
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/xlsx.php';
$me = requireAdmin();

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$g = function ($k, $d = '') use ($src) { return isset($src[$k]) ? trim((string)$src[$k]) : $d; };

$wallets = $pdo->query("SELECT id, name, currency, is_main, archived, account_no, account_name FROM wl_wallets ORDER BY is_main, archived, name")->fetchAll();
$wid     = (int)$g('wallet');
$from    = validDate($g('from')) ? $g('from') : date('Y-m-01');
$to      = validDate($g('to')) ? $g('to') : date('Y-m-d');
$withExp = $g('include_exported') === '1';
$credit  = $g('credit', '1') === '1';
$wallet  = $wid ? wlGetWallet($pdo, $wid) : null;

// اسم الحساب بصيغة الأمين «رقم-اسم»
function wlAccLabel($no, $name)
{
    return ($no !== null && $no !== '') ? $no . '-' . $name : $name;
}

$lines = [];
$rows  = [];
$warn  = [];
$totAmt = 0.0;
$totBase = 0.0;
if ($wallet) {
    $sql = "SELECT t.id, t.amount, t.base_amount, t.note, t.txn_date, t.exported_at, c.name AS cat_name, c.account_no
            FROM wl_transactions t LEFT JOIN wl_categories c ON c.id = t.category_id
            WHERE t.wallet_id = ? AND t.type = 'expense' AND t.voided = 0 AND t.txn_date BETWEEN ? AND ?"
         . ($withExp ? "" : " AND t.exported_at IS NULL")
         . " ORDER BY t.txn_date, t.id";
    $st = $pdo->prepare($sql);
    $st->execute([$wallet['id'], $from, $to]);
    $rows = $st->fetchAll();

    $foreign = $wallet['currency'] !== baseCurrency();
    $sym = currencySymbol($wallet['currency']);
    $noAcc = 0;
    foreach ($rows as $r) {
        $amt  = round(-(float)$r['amount'], 2);
        $base = round(-(float)($r['base_amount'] !== null ? $r['base_amount'] : $r['amount']), 2);
        $totAmt += $amt;
        $totBase += $base;
        if ($r['account_no'] === null || $r['account_no'] === '') {
            $noAcc++;
        }
        $lines[] = [
            wlAccLabel($r['account_no'], $r['cat_name']),
            (string)$r['note'],
            $amt, null,
            $foreign ? $sym : null,
            $foreign ? round($base / $amt, 6) : null,
            $foreign ? $base : null,
        ];
    }
    if ($lines && $credit) {
        $accName = $wallet['account_name'] ?: $wallet['name'];
        array_unshift($lines, [
            wlAccLabel($wallet['account_no'], $accName),
            '',
            null, round($totAmt, 2),
            $foreign ? $sym : null,
            $foreign ? round($totBase / $totAmt, 6) : null,
            $foreign ? round($totBase, 2) : null,
        ]);
        if (!$wallet['account_no']) {
            $warn[] = 'لم يُحدَّد رقم حساب لهذه المحفظة — السطر الدائن سيظهر بالاسم فقط. حدّده من «المستخدمون ← ✏️» أو من «المحافظ الرئيسية».';
        }
    }
    if ($noAcc) {
        $warn[] = $noAcc . ' مصروف على تصنيف بدون رقم حساب — سيظهر بالاسم فقط. أضف رقم الحساب من «التصنيفات».';
    }
}
$head = ['الحساب', 'البيان', 'مدين', 'دائن', 'العملة', 'التعادل', 'المكافئ'];

// تنزيل الملف (POST) + تعليم المصاريف كمُصدَّرة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!$wallet || !$lines) {
        flash('error', 'لا توجد مصاريف للتصدير في هذه الفترة');
        redirect('voucher.php?' . http_build_query(['wallet' => $wid, 'from' => $from, 'to' => $to]));
    }
    if (post('mark') === '1') {
        $ids = array_map('intval', array_column($rows, 'id'));
        $pdo->exec("UPDATE wl_transactions SET exported_at = NOW() WHERE id IN (" . implode(',', $ids) . ")");
    }
    $x = new WlXlsx();
    $x->addSheet('السند', array_merge([$head], $lines), [34, 30, 14, 14, 8, 12, 16]);
    $x->addSheet('ملخص', [
        ['البند', 'القيمة'],
        ['المحفظة', $wallet['name']],
        ['حساب السلفة', wlAccLabel($wallet['account_no'], $wallet['account_name'] ?: $wallet['name'])],
        ['من تاريخ', $from],
        ['إلى تاريخ', $to],
        ['عدد المصاريف', count($rows)],
        ['المجموع (' . currencySymbol($wallet['currency']) . ')', round($totAmt, 2)],
        ['المعادل (' . currencySymbol() . ')', round($totBase, 2)],
        ['تاريخ التصدير', date('Y-m-d H:i')],
    ], [22, 40]);
    $fn = 'voucher_' . preg_replace('/[^A-Za-z0-9]/', '', (string)$wallet['account_no'] ?: (string)$wallet['id']) . '_' . $from . '_' . $to . '.xlsx';
    $x->download($fn);
    exit;
}

$pageTitle = 'تصدير سند';
$active = 'transactions';
require __DIR__ . '/includes/header.php';
?>
<h1>📤 تصدير سند لبرنامج المحاسبة</h1>
<form class="card filters" method="get">
  <div class="form-row" style="grid-column:span 2"><label>المحفظة</label>
    <select name="wallet" required>
      <option value="">— اختر —</option>
      <?php foreach ($wallets as $w): ?>
        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $wid ? 'selected' : '' ?>>
          <?= $w['is_main'] ? '🏦 ' : '👤 ' ?><?= e($w['name']) ?><?= $w['account_no'] ? ' — ' . e($w['account_no']) : '' ?><?= $w['archived'] ? ' (مؤرشفة)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select></div>
  <div class="form-row"><label>من</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="form-row"><label>إلى</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="form-row"><input type="hidden" name="credit" value="0"><label class="inline-check"><input type="checkbox" name="credit" value="1" <?= $credit ? 'checked' : '' ?>> سطر دائن على حساب السلفة</label></div>
  <div class="form-row"><label class="inline-check"><input type="checkbox" name="include_exported" value="1" <?= $withExp ? 'checked' : '' ?>> تضمين ما صُدِّر سابقاً</label></div>
  <div class="form-row"><button class="btn">معاينة</button></div>
</form>

<?php if ($wallet): ?>
  <?php foreach ($warn as $w): ?><div class="alert alert-info">⚠️ <?= e($w) ?></div><?php endforeach; ?>
  <?php if (!$lines): ?>
    <div class="card empty">لا توجد مصاريف <?= $withExp ? '' : 'غير مُصدَّرة ' ?>لـ«<?= e($wallet['name']) ?>» من <span class="num"><?= e($from) ?></span> إلى <span class="num"><?= e($to) ?></span></div>
  <?php else: ?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
      <h2 style="margin:0"><?= count($rows) ?> مصروف — المجموع <span class="num"><?= e(money($totAmt, true, $wallet['currency'])) ?></span></h2>
      <form method="post" class="actions">
        <?= csrfField() ?>
        <input type="hidden" name="wallet" value="<?= (int)$wallet['id'] ?>">
        <input type="hidden" name="from" value="<?= e($from) ?>">
        <input type="hidden" name="to" value="<?= e($to) ?>">
        <input type="hidden" name="credit" value="<?= $credit ? '1' : '0' ?>">
        <input type="hidden" name="include_exported" value="<?= $withExp ? '1' : '0' ?>">
        <label class="inline-check"><input type="checkbox" name="mark" value="1" checked> تعليمها «مُصدَّرة» (لا تتكرر في سند لاحق)</label>
        <button class="btn">⬇️ تنزيل Excel</button>
      </form>
    </div>
    <p class="hint">افتح الملف، حدّد الأسطر تحت العناوين (بدون صف العناوين)، انسخها والصقها في أول سطر من شبكة السند في برنامج المحاسبة.</p>
    <div class="tbl-wrap">
      <table>
        <thead><tr><?php foreach ($head as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $l): ?>
          <tr<?= ($i === 0 && $credit) ? ' style="background:var(--primary-bg);font-weight:700"' : '' ?>>
            <td><?= e($l[0]) ?></td>
            <td><?= e($l[1]) ?></td>
            <td class="num"><?= $l[2] !== null ? e(money($l[2], false)) : '' ?></td>
            <td class="num"><?= $l[3] !== null ? e(money($l[3], false)) : '' ?></td>
            <td><?= e((string)$l[4]) ?></td>
            <td class="num"><?= $l[5] !== null ? e(plainNumber($l[5])) : '' ?></td>
            <td class="num"><?= $l[6] !== null ? e(money($l[6], false)) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php';
