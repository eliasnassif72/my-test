<?php
require __DIR__ . '/includes/bootstrap.php';
$me = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = post('action');
    if ($action === 'general') {
        setSetting($pdo, 'app_name', mb_substr(post('app_name'), 0, 80) ?: 'Elias Control');
        setSetting($pdo, 'currency', mb_substr(post('currency'), 0, 12) ?: 'ل.س');
        setSetting($pdo, 'allow_negative', post('allow_negative') === '1' ? '1' : '0');
        flash('success', 'تم حفظ الإعدادات');
    } elseif ($action === 'regen_key') {
        setSetting($pdo, 'api_key', bin2hex(random_bytes(20)));
        flash('success', 'تم توليد مفتاح جديد — حدّث الرابط في لوحة elias controle');
    } elseif ($action === 'fix_balances') {
        $n = $pdo->exec("UPDATE wl_wallets w SET w.balance = (
                SELECT COALESCE(SUM(t.amount), 0) FROM wl_transactions t WHERE t.wallet_id = w.id AND t.voided = 0)");
        flash('success', 'تمت إعادة احتساب الأرصدة من سجل الحركات (' . (int)$n . ' محفظة عُدّلت)');
    }
    redirect('settings.php');
}

$https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$base   = ($https ? 'https' : 'http') . '://' . $host . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$key    = setting($pdo, 'api_key');
$issues = wlIntegrity($pdo);

$pageTitle = 'الإعدادات';
$active = 'settings';
require __DIR__ . '/includes/header.php';
?>
<h1>⚙️ الإعدادات</h1>
<div class="grid g2" style="align-items:start">
  <div class="card">
    <h2>عام</h2>
    <form method="post">
      <?= csrfField() ?><input type="hidden" name="action" value="general">
      <div class="form-row"><label>اسم النظام</label><input type="text" name="app_name" value="<?= e(setting($pdo, 'app_name')) ?>"></div>
      <div class="form-row"><label>رمز العملة</label><input type="text" name="currency" value="<?= e(setting($pdo, 'currency')) ?>"></div>
      <div class="form-row"><label class="inline-check"><input type="checkbox" name="allow_negative" value="1" <?= setting($pdo, 'allow_negative') === '1' ? 'checked' : '' ?>> السماح بالرصيد السالب (صرف أكثر من الرصيد)</label>
        <div class="hint">افتراضياً: لا يمكن تسجيل مصروف أو تحويل أكبر من رصيد المحفظة.</div></div>
      <button class="btn">حفظ</button>
    </form>
  </div>

  <div class="card" id="integrity">
    <h2>🧮 فحص الأرصدة</h2>
    <?php if (!$issues): ?>
      <div class="alert alert-success">كل الأرصدة مطابقة لسجل الحركات ✅</div>
    <?php else: ?>
      <div class="alert alert-error">عدم تطابق في <?= count($issues) ?> محفظة:</div>
      <table><tr><th>المحفظة</th><th>الرصيد المخزّن</th><th>حسب الحركات</th></tr>
        <?php foreach ($issues as $i): ?><tr><td><?= e($i['name']) ?></td><td class="num"><?= e(money($i['balance'])) ?></td><td class="num"><?= e(money($i['ledger'])) ?></td></tr><?php endforeach; ?>
      </table>
      <form method="post" data-confirm="إعادة احتساب كل الأرصدة من سجل الحركات؟" style="margin-top:10px">
        <?= csrfField() ?><input type="hidden" name="action" value="fix_balances"><button class="btn btn-red">إصلاح الأرصدة</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>🔗 الربط مع لوحة elias controle</h2>
  <p class="sub">لعرض أرصدة المحافظ داخل لوحة elias controle (أو أي لوحة أخرى) استخدم أحد الخيارين. الرابط يحتوي مفتاحاً سرياً — لا تشاركه علناً.</p>

  <h3 style="font-size:1rem">1) ويدجت جاهز (iframe) — الأسهل</h3>
  <code class="key">&lt;iframe src="<?= e($base) ?>/widget.php?key=<?= e($key) ?>" style="width:100%;height:420px;border:0"&gt;&lt;/iframe&gt;</code>
  <p><a class="btn btn-light btn-sm" target="_blank" href="widget.php?key=<?= e($key) ?>">معاينة الويدجت</a></p>

  <h3 style="font-size:1rem">2) API بصيغة JSON</h3>
  <code class="key">GET <?= e($base) ?>/api.php
Header: X-API-KEY: <?= e($key) ?></code>
  <p class="hint">أو <span dir="ltr">?key=…</span> في الرابط. اختياري: <span dir="ltr">&amp;wallet=ID</span> لمحفظة واحدة، <span dir="ltr">&amp;month=YYYY-MM</span> لمصاريف شهر محدد. تعمل مع n8n (HTTP Request).</p>

  <form method="post" data-confirm="توليد مفتاح جديد سيوقف الروابط القديمة فوراً. متابعة؟">
    <?= csrfField() ?><input type="hidden" name="action" value="regen_key"><button class="btn btn-red btn-sm">توليد مفتاح جديد</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php';
