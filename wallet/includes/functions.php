<?php
// دوال مساعدة عامة

function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function setting(PDO $pdo, $key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT k, v FROM wl_settings") as $r) {
                $cache[$r['k']] = $r['v'];
            }
        } catch (Exception $ex) {
        }
    }
    if ($key === null) { // تفريغ الكاش بعد الحفظ
        $cache = null;
        return null;
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function setSetting(PDO $pdo, $key, $value)
{
    $pdo->prepare("INSERT INTO wl_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute([$key, $value]);
    setting($pdo, null);
}

// العملات المعرّفة (مخزّنة مؤقتاً لكل طلب) — code => row
function wlCurrencies($refresh = false)
{
    global $pdo;
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT * FROM wl_currencies ORDER BY sort_order, code") as $r) {
                $cache[$r['code']] = $r;
            }
        } catch (Exception $ex) {
        }
    }
    return $cache;
}

function baseCurrency()
{
    return defined('WL_BASE_CURRENCY') ? WL_BASE_CURRENCY : 'SYP';
}

function currencySymbol($code = null)
{
    global $pdo;
    $code = $code ?: baseCurrency();
    $c = wlCurrencies();
    if (isset($c[$code])) {
        return $c[$code]['symbol'];
    }
    return $code === baseCurrency() ? setting($pdo, 'currency', 'ل.س') : $code;
}

// قيمة وحدة من العملة بالعملة الأساسية (0 = غير محدد)
function currencyRate($code)
{
    if ($code === baseCurrency()) {
        return 1.0;
    }
    $c = wlCurrencies();
    return isset($c[$code]) ? (float)$c[$code]['rate'] : 0.0;
}

// تنسيق مبلغ؛ $cur = رمز العملة (افتراضياً الأساسية)
function money($n, $withCurrency = true, $cur = null)
{
    $n = (float)$n;
    $dec = (abs($n - round($n)) > 0.001) ? 2 : 0;
    // عزل الرقم باتجاه LTR (LRI…PDI) حتى تبقى إشارة السالب بمكانها داخل النص العربي
    $s = "\u{2066}" . number_format($n, $dec, '.', ',') . "\u{2069}";
    return $withCurrency ? $s . ' ' . currencySymbol($cur) : $s;
}

// رقم عادي لحقول الإدخال (بدون رموز اتجاه)
function plainNumber($n)
{
    return rtrim(rtrim(number_format((float)$n, 6, '.', ''), '0'), '.');
}

// يقبل "1,500" أو "1500.50" أو أرقام عربية-هندية
function parseAmount($raw)
{
    $raw = trim((string)$raw);
    $raw = strtr($raw, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '٫' => '.', '٬' => '', ',' => '', ' ' => '', "\u{2066}" => '', "\u{2069}" => '',
    ]);
    if ($raw === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        return null;
    }
    $v = round((float)$raw, 2);
    if ($v <= 0 || $v > 999999999999) {
        return null;
    }
    return $v;
}

// سعر صرف: رقم موجب حتى 6 منازل عشرية (يقبل الفواصل والأرقام العربية)
function parseRate($raw)
{
    $raw = strtr(trim((string)$raw), [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '٫' => '.', '٬' => '', ',' => '', ' ' => '',
    ]);
    if ($raw === '' || !preg_match('/^\d+(\.\d{1,6})?$/', $raw)) {
        return null;
    }
    $v = (float)$raw;
    return ($v > 0 && $v < 1e12) ? $v : null;
}

function validDate($d)
{
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function csrfToken()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField()
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function csrfCheck()
{
    $t = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($t === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(400);
        exit('انتهت صلاحية النموذج — أعد تحميل الصفحة وحاول مجدداً.');
    }
}

function flash($type, $msg)
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function takeFlashes()
{
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function get($key, $default = '')
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function txnTypeLabel($type)
{
    $map = [
        'deposit'      => 'إيداع في الرئيسية',
        'withdraw'     => 'سحب من الرئيسية',
        'transfer_in'  => 'تغذية واردة',
        'transfer_out' => 'تحويل صادر',
        'expense'      => 'مصروف',
        'opening'      => 'رصيد أول المدة',
    ];
    return isset($map[$type]) ? $map[$type] : $type;
}

function arMonthName($ym)
{
    $months = ['', 'كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران',
        'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'];
    $p = explode('-', $ym);
    return $months[(int)$p[1]] . ' ' . $p[0];
}

function clientIp()
{
    return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '0.0.0.0';
}
