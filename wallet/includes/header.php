<?php
// $pageTitle و $active تُعرَّفان قبل التضمين
$__u   = currentUser();
$__app = setting($pdo, 'app_name', 'Elias Control');
$__nav = [];
if ($__u) {
    $__nav[] = ['dashboard.php', '🏠', 'الرئيسية', 'dashboard'];
    $__nav[] = ['expense.php', '➖', 'مصروف جديد', 'expense'];
    if ($__u['role'] === 'admin') {
        $__nav[] = ['fund.php', '💸', 'تغذية / إيداع', 'fund'];
    } elseif ($__u['wallet_id']) {
        $__nav[] = ['wallet.php?id=' . (int)$__u['wallet_id'], '👛', 'محفظتي', 'wallet'];
    }
    $__nav[] = ['transactions.php', '📒', 'الحركات', 'transactions'];
    if ($__u['role'] === 'admin') {
        $__nav[] = ['users.php', '👥', 'المستخدمون', 'users'];
        $__nav[] = ['categories.php', '🏷️', 'التصنيفات', 'categories'];
        $__nav[] = ['settings.php', '⚙️', 'الإعدادات', 'settings'];
    }
}
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(isset($pageTitle) ? $pageTitle . ' — ' . $__app : $__app) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=2">
</head>
<body>
<?php if ($__u): ?>
<header class="topbar">
  <div class="topbar-in">
    <a class="brand" href="dashboard.php"><span class="brand-ico">💼</span><?= e($__app) ?></a>
    <div class="who">
      <span class="who-name"><?= e($__u['full_name']) ?></span>
      <span class="badge <?= $__u['role'] === 'admin' ? 'badge-admin' : '' ?>"><?= $__u['role'] === 'admin' ? 'مدير النظام' : 'مستخدم' ?></span>
      <a class="lnk" href="profile.php" title="كلمة المرور">🔑</a>
      <a class="lnk" href="logout.php">خروج</a>
    </div>
  </div>
  <nav class="nav">
    <?php foreach ($__nav as $n): ?>
      <a href="<?= e($n[0]) ?>" class="<?= (isset($active) && $active === $n[3]) ? 'on' : '' ?>"><span><?= $n[1] ?></span><?= e($n[2]) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<?php endif; ?>
<main class="wrap">
<?php foreach (takeFlashes() as $__fl): ?>
  <div class="alert alert-<?= e($__fl['type']) ?>"><?= e($__fl['msg']) ?></div>
<?php endforeach; ?>
