<?php
// انسخ هذا الملف باسم config.php ثم عدّل بيانات قاعدة البيانات.
// config.php لا يُرفع إلى git — خاص بكل سيرفر.

define('DB_HOST', 'localhost');
define('DB_NAME', 'perfecti_wallet');   // اسم قاعدة البيانات على السيرفر
define('DB_USER', 'perfecti_wallet');
define('DB_PASS', 'CHANGE_ME');

define('APP_TIMEZONE', 'Asia/Damascus');

// اتركه false على perfectionsyria.net (تفعيله كسر الجلسات على هذه الاستضافة سابقاً)
define('SESSION_SECURE', false);

// true مؤقتاً فقط لتشخيص صفحة بيضاء / خطأ 500 — ثم أعده false
define('DEBUG', false);
