<?php
// المصادقة والصلاحيات — المستخدم يُعاد تحميله من القاعدة في كل طلب
// حتى يُطرد فوراً إذا أوقفه المدير.

function currentUser()
{
    global $pdo;
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    if (!empty($_SESSION['wl_uid'])) {
        $st = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.phone, u.role, u.active,
                                    w.id AS wallet_id
                             FROM wl_users u
                             LEFT JOIN wl_wallets w ON w.user_id = u.id
                             WHERE u.id = ?");
        $st->execute([(int)$_SESSION['wl_uid']]);
        $row = $st->fetch();
        if ($row && (int)$row['active'] === 1) {
            $u = $row;
        } else {
            unset($_SESSION['wl_uid']);
        }
    }
    return $u;
}

function isAdmin()
{
    $u = currentUser();
    return $u && $u['role'] === 'admin';
}

function requireLogin()
{
    if (!currentUser()) {
        redirect('login.php');
    }
    return currentUser();
}

function requireAdmin()
{
    requireLogin();
    if (!isAdmin()) {
        flash('error', 'هذه الصفحة لمدير النظام فقط');
        redirect('dashboard.php');
    }
    return currentUser();
}

// هل يحق للمستخدم الحالي رؤية هذه المحفظة؟
function canViewWallet(array $wallet)
{
    $u = currentUser();
    if (!$u) {
        return false;
    }
    if ($u['role'] === 'admin') {
        return true;
    }
    return (int)$wallet['user_id'] === (int)$u['id'];
}
