<?php
// محرّك المحافظ — كل حركة تتم داخل transaction مع قفل صفوف المحافظ (FOR UPDATE)
// حتى لا يصبح الرصيد خاطئاً عند تسجيل حركتين بنفس اللحظة.

class WalletError extends Exception
{
}

function wlMainWallet(PDO $pdo)
{
    return $pdo->query("SELECT * FROM wl_wallets WHERE is_main = 1 ORDER BY id LIMIT 1")->fetch();
}

function wlGetWallet(PDO $pdo, $id)
{
    $st = $pdo->prepare("SELECT w.*, u.full_name AS owner_name, u.username AS owner_username
                         FROM wl_wallets w LEFT JOIN wl_users u ON u.id = w.user_id
                         WHERE w.id = ?");
    $st->execute([(int)$id]);
    return $st->fetch();
}

function wlAllowNegative(PDO $pdo)
{
    return setting($pdo, 'allow_negative', '0') === '1';
}

// يقفل المحافظ بترتيب تصاعدي لتجنّب deadlock
function wlLock(PDO $pdo, array $ids)
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    $out = [];
    $st = $pdo->prepare("SELECT * FROM wl_wallets WHERE id = ? FOR UPDATE");
    foreach ($ids as $id) {
        $st->execute([$id]);
        $w = $st->fetch();
        if (!$w) {
            throw new WalletError('المحفظة غير موجودة');
        }
        $out[$id] = $w;
    }
    return $out;
}

// يطبّق حركة على محفظة مقفولة مسبقاً ويُرجع id الحركة
function wlApply(PDO $pdo, array &$wallet, $delta, $type, array $o)
{
    $delta = round((float)$delta, 2);
    $new   = round((float)$wallet['balance'] + $delta, 2);
    if ($delta < 0 && $new < 0 && !wlAllowNegative($pdo)) {
        throw new WalletError('الرصيد غير كافٍ في «' . $wallet['name'] . '» — المتاح: ' . money($wallet['balance']));
    }
    // المحفظة الموقوفة لا تقبل مصاريف جديدة، لكن يبقى بإمكان المدير إرجاع رصيدها للرئيسية
    if ($type === 'expense' && (int)$wallet['active'] !== 1) {
        throw new WalletError('المحفظة «' . $wallet['name'] . '» موقوفة');
    }
    $pdo->prepare("UPDATE wl_wallets SET balance = ? WHERE id = ?")->execute([$new, $wallet['id']]);
    $wallet['balance'] = $new;

    $pdo->prepare("INSERT INTO wl_transactions
        (wallet_id, type, amount, balance_after, category_id, counter_wallet_id, ref, txn_date, note, receipt, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $wallet['id'], $type, $delta, $new,
            isset($o['category_id']) ? $o['category_id'] : null,
            isset($o['counter_wallet_id']) ? $o['counter_wallet_id'] : null,
            isset($o['ref']) ? $o['ref'] : null,
            $o['date'],
            (isset($o['note']) && $o['note'] !== '') ? mb_substr($o['note'], 0, 500) : null,
            isset($o['receipt']) ? $o['receipt'] : null,
            (int)$o['by'],
        ]);
    return (int)$pdo->lastInsertId();
}

function wlRun(PDO $pdo, $fn)
{
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

// إيداع رأس مال في المحفظة الرئيسية
function wlDeposit(PDO $pdo, $amount, $date, $note, $by)
{
    return wlRun($pdo, function () use ($pdo, $amount, $date, $note, $by) {
        $main = wlMainWallet($pdo);
        $l = wlLock($pdo, [$main['id']]);
        $w = $l[$main['id']];
        return wlApply($pdo, $w, $amount, 'deposit', ['date' => $date, 'note' => $note, 'by' => $by]);
    });
}

// سحب من المحفظة الرئيسية إلى خارج النظام
function wlWithdraw(PDO $pdo, $amount, $date, $note, $by)
{
    return wlRun($pdo, function () use ($pdo, $amount, $date, $note, $by) {
        $main = wlMainWallet($pdo);
        $l = wlLock($pdo, [$main['id']]);
        $w = $l[$main['id']];
        return wlApply($pdo, $w, -$amount, 'withdraw', ['date' => $date, 'note' => $note, 'by' => $by]);
    });
}

// تحويل بين محفظتين (تغذية من الرئيسية أو إرجاع إليها)
function wlTransfer(PDO $pdo, $fromId, $toId, $amount, $date, $note, $by)
{
    if ((int)$fromId === (int)$toId) {
        throw new WalletError('لا يمكن التحويل لنفس المحفظة');
    }
    return wlRun($pdo, function () use ($pdo, $fromId, $toId, $amount, $date, $note, $by) {
        $l    = wlLock($pdo, [$fromId, $toId]);
        $from = $l[(int)$fromId];
        $to   = $l[(int)$toId];
        if ((int)$to['active'] !== 1) {
            throw new WalletError('المحفظة «' . $to['name'] . '» موقوفة');
        }
        $ref = bin2hex(random_bytes(8));
        wlApply($pdo, $from, -$amount, 'transfer_out', [
            'date' => $date, 'note' => $note, 'by' => $by, 'ref' => $ref, 'counter_wallet_id' => $to['id'],
        ]);
        return wlApply($pdo, $to, $amount, 'transfer_in', [
            'date' => $date, 'note' => $note, 'by' => $by, 'ref' => $ref, 'counter_wallet_id' => $from['id'],
        ]);
    });
}

// تسجيل مصروف يُخصم من المحفظة
function wlExpense(PDO $pdo, $walletId, $amount, $categoryId, $date, $note, $receipt, $by)
{
    $st = $pdo->prepare("SELECT id FROM wl_categories WHERE id = ? AND active = 1");
    $st->execute([(int)$categoryId]);
    if (!$st->fetchColumn()) {
        throw new WalletError('اختر تصنيف المصروف');
    }
    return wlRun($pdo, function () use ($pdo, $walletId, $amount, $categoryId, $date, $note, $receipt, $by) {
        $l = wlLock($pdo, [$walletId]);
        $w = $l[(int)$walletId];
        return wlApply($pdo, $w, -$amount, 'expense', [
            'date' => $date, 'note' => $note, 'by' => $by,
            'category_id' => (int)$categoryId, 'receipt' => $receipt,
        ]);
    });
}

// إلغاء حركة (للمدير) — يُعكس أثرها على الرصيد وتبقى ظاهرة كـ«ملغاة» للتدقيق.
// التحويل يُلغى بطرفيه معاً.
function wlVoid(PDO $pdo, $txnId, $reason, $by)
{
    return wlRun($pdo, function () use ($pdo, $txnId, $reason, $by) {
        $st = $pdo->prepare("SELECT * FROM wl_transactions WHERE id = ?");
        $st->execute([(int)$txnId]);
        $t = $st->fetch();
        if (!$t) {
            throw new WalletError('الحركة غير موجودة');
        }
        // اقفل المحافظ المعنية أولاً (بنفس ترتيب wlApply) ثم أعد قراءة الحركة تحت القفل،
        // حتى لا يمرّ إلغاءان متزامنان لنفس الحركة.
        $walletIds = [(int)$t['wallet_id']];
        if ($t['ref'] && $t['counter_wallet_id']) {
            $walletIds[] = (int)$t['counter_wallet_id'];
        }
        $wallets = wlLock($pdo, $walletIds);

        if ($t['ref']) {
            $st = $pdo->prepare("SELECT * FROM wl_transactions WHERE ref = ? FOR UPDATE");
            $st->execute([$t['ref']]);
        } else {
            $st = $pdo->prepare("SELECT * FROM wl_transactions WHERE id = ? FOR UPDATE");
            $st->execute([(int)$t['id']]);
        }
        $legs = $st->fetchAll();
        foreach ($legs as $leg) {
            if ((int)$leg['voided'] === 1) {
                throw new WalletError('الحركة ملغاة مسبقاً');
            }
            if (!isset($wallets[(int)$leg['wallet_id']])) {
                throw new WalletError('بيانات التحويل غير متطابقة');
            }
        }

        $allowNeg = wlAllowNegative($pdo);
        foreach ($legs as $leg) {
            $w   = &$wallets[(int)$leg['wallet_id']];
            $new = round((float)$w['balance'] - (float)$leg['amount'], 2);
            // نتحقق فقط عندما يُنقص الإلغاءُ الرصيد (عكس تغذية/إيداع) — عكس المصروف يزيده دائماً
            if ((float)$leg['amount'] > 0 && $new < 0 && !$allowNeg) {
                throw new WalletError('لا يمكن الإلغاء: رصيد «' . $w['name'] . '» لا يكفي لعكس الحركة (المتاح ' . money($w['balance']) . ')');
            }
            $pdo->prepare("UPDATE wl_wallets SET balance = ? WHERE id = ?")->execute([$new, $w['id']]);
            $w['balance'] = $new;
            $pdo->prepare("UPDATE wl_transactions SET voided = 1, voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?")
                ->execute([(int)$by, mb_substr((string)$reason, 0, 255), $leg['id']]);
            unset($w);
        }
        return count($legs);
    });
}

// إعادة احتساب كل الأرصدة من سجل الحركات (مع قفل المحافظ)
function wlRecalcBalances(PDO $pdo)
{
    return wlRun($pdo, function () use ($pdo) {
        $pdo->query("SELECT id FROM wl_wallets FOR UPDATE")->fetchAll();
        return $pdo->exec("UPDATE wl_wallets w SET w.balance = (
                SELECT COALESCE(SUM(t.amount), 0) FROM wl_transactions t WHERE t.wallet_id = w.id AND t.voided = 0)");
    });
}

// ملخص كل المحافظ لفترة — مصدر واحد للداشبورد والويدجت والـ API
function wlWalletSummaries(PDO $pdo, $from, $to, $activeOnly = false, $walletId = null)
{
    $sql = "SELECT w.id, w.user_id, w.name, w.is_main, w.balance, w.active,
               u.full_name AS owner, u.username,
               COALESCE(SUM(CASE WHEN t.type='expense' AND t.voided=0 AND t.txn_date BETWEEN ? AND ? THEN -t.amount END),0) AS month_spent,
               COALESCE(SUM(CASE WHEN t.amount>0 AND t.voided=0 AND t.txn_date BETWEEN ? AND ? THEN t.amount END),0) AS month_in,
               MAX(CASE WHEN t.voided=0 THEN t.txn_date END) AS last_move,
               MAX(CASE WHEN t.voided=0 THEN t.created_at END) AS last_activity
            FROM wl_wallets w
            LEFT JOIN wl_users u ON u.id = w.user_id
            LEFT JOIN wl_transactions t ON t.wallet_id = w.id
            WHERE 1=1";
    $p = [$from, $to, $from, $to];
    if ($activeOnly) {
        $sql .= " AND w.active = 1";
    }
    if ($walletId) {
        $sql .= " AND w.id = ?";
        $p[] = (int)$walletId;
    }
    $sql .= " GROUP BY w.id, w.user_id, w.name, w.is_main, w.balance, w.active, u.full_name, u.username
              ORDER BY w.is_main DESC, w.active DESC, w.name";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

// فحص تطابق الأرصدة المخزنة مع مجموع الحركات
function wlIntegrity(PDO $pdo)
{
    return $pdo->query("SELECT w.id, w.name, w.balance,
            COALESCE(SUM(CASE WHEN t.voided = 0 THEN t.amount END), 0) AS ledger
        FROM wl_wallets w LEFT JOIN wl_transactions t ON t.wallet_id = w.id
        GROUP BY w.id, w.name, w.balance
        HAVING ROUND(w.balance, 2) <> ROUND(ledger, 2)")->fetchAll();
}

// مصاريف حسب التصنيف لفترة (لمحفظة أو للكل)
function wlCategoryTotals(PDO $pdo, $from, $to, $walletId = null)
{
    $sql = "SELECT c.id, c.name, c.icon, SUM(-t.amount) AS total, COUNT(*) AS cnt
            FROM wl_transactions t JOIN wl_categories c ON c.id = t.category_id
            WHERE t.type = 'expense' AND t.voided = 0 AND t.txn_date BETWEEN ? AND ?";
    $p = [$from, $to];
    if ($walletId) {
        $sql .= " AND t.wallet_id = ?";
        $p[] = (int)$walletId;
    }
    $sql .= " GROUP BY c.id, c.name, c.icon ORDER BY total DESC";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

// حفظ صورة/ملف إيصال مرفوع — يُرجع اسم الملف أو null
function wlSaveReceipt($file)
{
    if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new WalletError('فشل رفع الإيصال');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new WalletError('حجم الإيصال أكبر من 5MB');
    }
    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
    ];
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']);
    }
    if (!isset($allowed[$mime])) {
        throw new WalletError('نوع الإيصال غير مسموح (JPG / PNG / WEBP / PDF فقط)');
    }
    $dir = __DIR__ . '/../uploads/receipts';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $name = date('Ym') . '_' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new WalletError('تعذّر حفظ الإيصال على السيرفر');
    }
    return $name;
}

// جلب الحركات مع الفلاتر: wallet_id, type, category_id, from, to, voided(0/1/null), q, user_id(محفظة مستخدم)
function wlTxnWhere(array $f)
{
    $where = ['1=1'];
    $p = [];
    if (!empty($f['wallet_id'])) {
        $where[] = 't.wallet_id = ?';
        $p[] = (int)$f['wallet_id'];
    }
    if (!empty($f['type'])) {
        if ($f['type'] === 'funding') {
            $where[] = "t.type IN ('deposit','withdraw','transfer_in','transfer_out')";
        } else {
            $where[] = 't.type = ?';
            $p[] = $f['type'];
        }
    }
    if (!empty($f['category_id'])) {
        $where[] = 't.category_id = ?';
        $p[] = (int)$f['category_id'];
    }
    if (!empty($f['from'])) {
        $where[] = 't.txn_date >= ?';
        $p[] = $f['from'];
    }
    if (!empty($f['to'])) {
        $where[] = 't.txn_date <= ?';
        $p[] = $f['to'];
    }
    if (isset($f['voided']) && $f['voided'] !== null) {
        $where[] = 't.voided = ?';
        $p[] = (int)$f['voided'];
    }
    if (!empty($f['q'])) {
        $where[] = 't.note LIKE ?';
        $p[] = '%' . $f['q'] . '%';
    }
    return [implode(' AND ', $where), $p];
}

// مجاميع الحركات غير الملغاة لكامل الفلتر (لا تتأثر بحد العرض)
function wlTxnTotals(PDO $pdo, array $f)
{
    list($where, $p) = wlTxnWhere($f);
    $st = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount END), 0) AS inflow,
            COALESCE(SUM(CASE WHEN t.type = 'expense' THEN -t.amount END), 0) AS spent,
            COALESCE(SUM(CASE WHEN t.amount < 0 AND t.type <> 'expense' THEN -t.amount END), 0) AS outflow,
            COUNT(*) AS cnt
        FROM wl_transactions t WHERE $where AND t.voided = 0");
    $st->execute($p);
    return $st->fetch();
}

function wlTxnQuery(PDO $pdo, array $f, $limit = 200, $offset = 0)
{
    list($where, $p) = wlTxnWhere($f);
    $sql = "SELECT t.*, w.name AS wallet_name, w.is_main,
                   c.name AS cat_name, c.icon AS cat_icon,
                   cw.name AS counter_name, u.full_name AS creator_name
            FROM wl_transactions t
            JOIN wl_wallets w ON w.id = t.wallet_id
            LEFT JOIN wl_categories c ON c.id = t.category_id
            LEFT JOIN wl_wallets cw ON cw.id = t.counter_wallet_id
            LEFT JOIN wl_users u ON u.id = t.created_by
            WHERE $where
            ORDER BY t.txn_date DESC, t.id DESC";
    if ($limit) {
        $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
    }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}
