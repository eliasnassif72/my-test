<?php
require __DIR__ . '/includes/bootstrap.php';
$me = requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('transactions.php');
}
csrfCheck();
$back = post('back');
// إعادة التوجيه داخل التطبيق فقط
if (!preg_match('#^/(?![/\\\\])[A-Za-z0-9_\-./?=&%+]*$#', $back)) {
    $back = 'transactions.php';
}
try {
    $n = wlVoid($pdo, (int)post('id'), post('reason') !== '' ? post('reason') : 'إلغاء من المدير', $me['id']);
    flash('success', $n > 1 ? 'تم إلغاء التحويل بطرفيه وإعادة الأرصدة' : 'تم إلغاء الحركة وإعادة الرصيد');
} catch (WalletError $ex) {
    flash('error', $ex->getMessage());
} catch (Exception $ex) {
    error_log('void: ' . $ex->getMessage());
    flash('error', 'حدث خطأ أثناء الإلغاء');
}
redirect($back);
