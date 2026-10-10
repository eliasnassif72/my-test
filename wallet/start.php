<?php
// تثبيت لمرة واحدة: ينشئ الجداول + حساب مدير النظام الأول.
// بعد إنشاء المدير تُقفل الصفحة تلقائياً (لا يمكن استخدامها مرة ثانية).
require __DIR__ . '/includes/bootstrap.php';

wlSchemaInstall($pdo);

$hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM wl_users WHERE role='admin'")->fetchColumn();
$err = '';

if (!$hasAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $username = post('username');
    $name     = post('full_name');
    $pass     = isset($_POST['password']) ? (string)$_POST['password'] : '';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) {
        $err = 'اسم المستخدم: 3 أحرف على الأقل، إنكليزي/أرقام فقط';
    } elseif ($name === '') {
        $err = 'أدخل الاسم الكامل';
    } elseif (strlen($pass) < 8) {
        $err = 'كلمة المرور 8 أحرف على الأقل';
    } else {
        $pdo->prepare("INSERT INTO wl_users (username, password_hash, full_name, role) VALUES (?, ?, ?, 'admin')")
            ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $name]);
        flash('success', 'تم التثبيت — سجّل الدخول بحساب المدير');
        redirect('login.php');
    }
}
$pageTitle = 'التثبيت';
require __DIR__ . '/includes/header.php';
?>
<div class="login-box card">
  <div class="login-logo">💼</div>
  <h1 class="login-title">تثبيت نظام المحافظ</h1>
  <?php if ($hasAdmin): ?>
    <div class="alert alert-info">النظام مثبّت مسبقاً. الجداول محدّثة ✅</div>
    <a class="btn btn-block" href="login.php">الذهاب لتسجيل الدخول</a>
  <?php else: ?>
    <p class="sub">تم إنشاء الجداول والمحفظة الرئيسية وتصنيفات المصاريف الافتراضية. أنشئ الآن حساب مدير النظام:</p>
    <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
    <form method="post">
      <?= csrfField() ?>
      <div class="form-row"><label>اسم المستخدم</label><input type="text" name="username" value="<?= e(post('username')) ?>" required dir="ltr" autocomplete="username"></div>
      <div class="form-row"><label>الاسم الكامل</label><input type="text" name="full_name" value="<?= e(post('full_name')) ?>" required></div>
      <div class="form-row"><label>كلمة المرور</label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
      <button class="btn btn-block">إنشاء حساب المدير</button>
    </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php';
