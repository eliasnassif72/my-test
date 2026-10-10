<?php
// دفع لقطة الأرصدة إلى لوحة Control Room (webhook في n8n) بعد كل تعديل.
// السيرفر لا يقبل اتصالات واردة من n8n، فالسيرفر هو من يرسل (مثل CRM والمتجر).
// الإرسال يتم بعد انتهاء الطلب وإرسال الصفحة للمستخدم فلا يبطّئه.

function wlMarkDirty($on = true)
{
    static $dirty = false;
    if ($on === null) {
        return $dirty;
    }
    $dirty = $dirty || (bool)$on;
    return $dirty;
}

function wlPushUrl(PDO $pdo)
{
    $u = trim((string)setting($pdo, 'dashboard_push_url', ''));
    return preg_match('~^https?://[^\s]+$~i', $u) ? $u : '';
}

// يرجع ['ok' => bool, 'code' => int, 'error' => string]
function wlPushSnapshot(PDO $pdo)
{
    $url = wlPushUrl($pdo);
    if ($url === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'رابط الإرسال غير محدد'];
    }
    $snap = wlDashboardSnapshot($pdo, date('Y-m'));
    $body = json_encode($snap, JSON_UNESCAPED_UNICODE);
    $code = 0;
    $err  = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 0) {
            $err = curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json; charset=utf-8\r\n",
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        if ($res === false && $code === 0) {
            $err = 'تعذّر الاتصال';
        }
    }
    $ok = $code >= 200 && $code < 300;
    if (!$ok && $err === '') {
        $err = 'HTTP ' . $code;
    }
    setSetting($pdo, 'dashboard_push_last', json_encode(['at' => date('Y-m-d H:i:s'), 'ok' => $ok, 'code' => $code, 'error' => $err], JSON_UNESCAPED_UNICODE));
    return ['ok' => $ok, 'code' => $code, 'error' => $err];
}

// يُستدعى عند انتهاء كل طلب: إذا تغيّر شيء يُرسل اللقطة بعد تسليم الصفحة
function wlPushOnShutdown(PDO $pdo)
{
    if (!wlMarkDirty(null) || wlPushUrl($pdo) === '') {
        return;
    }
    try {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        wlPushSnapshot($pdo);
    } catch (Throwable $e) {
        error_log('dashboard push failed: ' . $e->getMessage());
    }
}
