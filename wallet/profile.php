<?php
require __DIR__ . '/includes/bootstrap.php';
$me = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $st = $pdo->prepare("SELECT password_hash FROM wl_users WHERE id = ?");
    $st->execute([$me['id']]);
    $hash = $st->fetchColumn();
    $old = isset($_POST['old']) ? (string)$_POST['old'] : '';
    $new = isset($_POST['new']) ? (string)$_POST['new'] : '';
    $rep = isset($_POST['rep']) ? (string)$_POST['rep'] : '';
    if (!password_verify($old, $hash)) {
        flash('error', 'كلمة المرور الحالية غير صحيحة');
    } elseif (strlen($new) < 6) {
        flash('error', 'كلمة المرور الجديدة 6 أحرف على الأقل');
    } elseif ($new !== $rep) {
        flash('error', 'التأكيد لا يطابق كلمة المرور الجديدة');
    } else {
        $pdo->prepare("UPDATE wl_users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        session_regenerate_id(true);
        flash('success', 'تم تغيير كلمة المرور');
    }
    redirect('profile.php');
}
$pageTitle = 'كلمة المرور';
require __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:480px;margin:0 auto">
  <h1>🔑 تغيير كلمة المرور</h1>
  <p class="sub">المستخدم: <b dir="ltr"><?= e($me['username']) ?></b></p>
  <form method="post">
    <?= csrfField() ?>
    <div class="form-row"><label>كلمة المرور الحالية</label><input type="password" name="old" required autocomplete="current-password"></div>
    <div class="form-row"><label>كلمة المرور الجديدة</label><input type="password" name="new" required minlength="6" autocomplete="new-password"></div>
    <div class="form-row"><label>تأكيد كلمة المرور الجديدة</label><input type="password" name="rep" required minlength="6" autocomplete="new-password"></div>
    <button class="btn btn-block">حفظ</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php';
