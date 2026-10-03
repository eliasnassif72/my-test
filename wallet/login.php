<?php
require __DIR__ . '/includes/bootstrap.php';
if (currentUser()) {
    redirect('dashboard.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $ip = clientIp();
    $pdo->prepare("DELETE FROM wl_login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)")->execute();
    $st = $pdo->prepare("SELECT COUNT(*) FROM wl_login_attempts WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL 5 MINUTE)");
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 5) {
        $err = 'محاولات كثيرة خاطئة — حاول بعد 5 دقائق';
    } else {
        $st = $pdo->prepare("SELECT * FROM wl_users WHERE username = ?");
        $st->execute([post('username')]);
        $u = $st->fetch();
        $pass = isset($_POST['password']) ? (string)$_POST['password'] : '';
        if ($u && (int)$u['active'] === 1 && password_verify($pass, $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['wl_uid'] = (int)$u['id'];
            $pdo->prepare("DELETE FROM wl_login_attempts WHERE ip_address = ?")->execute([$ip]);
            $pdo->prepare("UPDATE wl_users SET last_login = NOW() WHERE id = ?")->execute([$u['id']]);
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                $pdo->prepare("UPDATE wl_users SET password_hash = ? WHERE id = ?")
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            redirect('dashboard.php');
        }
        $pdo->prepare("INSERT INTO wl_login_attempts (ip_address, attempted_at) VALUES (?, NOW())")->execute([$ip]);
        $err = ($u && (int)$u['active'] !== 1) ? 'الحساب موقوف — راجع مدير النظام' : 'اسم المستخدم أو كلمة المرور غير صحيحة';
    }
}
$pageTitle = 'تسجيل الدخول';
require __DIR__ . '/includes/header.php';
?>
<div class="login-box card">
  <div class="login-logo">💼</div>
  <h1 class="login-title"><?= e(setting($pdo, 'app_name', 'Elias Control')) ?></h1>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
  <form method="post">
    <?= csrfField() ?>
    <div class="form-row"><label>اسم المستخدم</label><input type="text" name="username" value="<?= e(post('username')) ?>" required dir="ltr" autocomplete="username" autofocus></div>
    <div class="form-row"><label>كلمة المرور</label><input type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn-block">دخول</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php';
