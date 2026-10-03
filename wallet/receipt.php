<?php
// عرض الإيصال — فقط لمن يحق له رؤية المحفظة (مجلد uploads محجوب عن الوصول المباشر)
require __DIR__ . '/includes/bootstrap.php';
requireLogin();
$st = $pdo->prepare("SELECT t.receipt, w.* FROM wl_transactions t JOIN wl_wallets w ON w.id = t.wallet_id WHERE t.id = ?");
$st->execute([(int)get('id')]);
$r = $st->fetch();
if (!$r || !$r['receipt'] || !canViewWallet($r) || !preg_match('/^[A-Za-z0-9_]+\.(jpg|png|webp|pdf)$/', $r['receipt'])) {
    http_response_code(404);
    exit('غير موجود');
}
$path = __DIR__ . '/uploads/receipts/' . $r['receipt'];
if (!is_file($path)) {
    http_response_code(404);
    exit('الملف غير موجود على السيرفر');
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
$ext = pathinfo($path, PATHINFO_EXTENSION);
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="receipt.' . $ext . '"');
readfile($path);
