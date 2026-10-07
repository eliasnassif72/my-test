<?php
// نقطة التحميل المشتركة لكل الصفحات
$__cfg = __DIR__ . '/../config.php';
if (!is_file($__cfg)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('config.php غير موجود — انسخ config.sample.php باسم config.php وعدّل بيانات قاعدة البيانات.');
}
require $__cfg;

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Damascus');

if (defined('DEBUG') && DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $e) {
    error_log('Wallet DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('تعذّر الاتصال بقاعدة البيانات — راجع config.php');
}

require __DIR__ . '/schema.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/wallet.php';

try {
    wlSchemaMigrate($pdo);
} catch (Exception $e) {
    error_log('Wallet schema migrate failed: ' . $e->getMessage());
}

if (!defined('WL_NO_SESSION')) {
    if (session_status() === PHP_SESSION_NONE) {
        $params = ['httponly' => true, 'samesite' => 'Lax'];
        if (defined('SESSION_SECURE') && SESSION_SECURE) {
            $params['secure'] = true;
        }
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        }
        session_name('WLSESSID');
        session_start();
    }
    require __DIR__ . '/auth.php';
}
