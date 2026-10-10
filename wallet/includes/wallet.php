<?php
// محرّك المحافظ — كل حركة تتم داخل transaction مع قفل صفوف المحافظ (FOR UPDATE)
// حتى لا يصبح الرصيد خاطئاً عند تسجيل حركتين بنفس اللحظة.

class WalletError extends Exception
{
}

// أول محفظة رئيسية (خزينة) — للتوافق؛ يمكن وجود عدة محافظ رئيسية بعملات مختلفة
function wlMainWallet(PDO $pdo)
{
    return $pdo->query("SELECT * FROM wl_wallets WHERE is_main = 1 AND archived = 0 ORDER BY id LIMIT 1")->fetch();
}

function wlTreasuries(PDO $pdo, $withArchived = false)
{
    return $pdo->query("SELECT * FROM wl_wallets WHERE is_main = 1" . ($withArchived ? "" : " AND archived = 0") . " ORDER BY id")->fetchAll();
}

// ===== العملات وأسعار الصرف =====
// سعر الصرف يُكتب دائماً بصيغة «1 من العملة الأقوى = X من الأضعف» (مثال: 1 USD = 13800 SYP)
function wlFxHi($a, $b)
{
    $base = baseCurrency();
    if ($a === $base && $b !== $base) {
        return $b;
    }
    if ($b === $base && $a !== $base) {
        return $a;
    }
    return currencyRate($a) >= currencyRate($b) ? $a : $b;
}

// السعر الافتراضي المحفوظ للزوج (0 = غير محدد)
function wlDefaultFx($a, $b)
{
    $hi = wlFxHi($a, $b);
    $lo = $hi === $a ? $b : $a;
    $rh = currencyRate($hi);
    $rl = currencyRate($lo);
    return ($rh > 0 && $rl > 0) ? $rh / $rl : 0.0;
}

// تحويل مبلغ من عملة إلى أخرى بسعر «1 أقوى = X أضعف»
function wlFxConvert($amount, $from, $to, $fx)
{
    if ($from === $to) {
        return round((float)$amount, 2);
    }
    if ($fx <= 0) {
        throw new WalletError('أدخل سعر الصرف');
    }
    return wlFxHi($from, $to) === $from ? round($amount * $fx, 2) : round($amount / $fx, 2);
}

function wlFxLabel($a, $b, $fx)
{
    $hi = wlFxHi($a, $b);
    $lo = $hi === $a ? $b : $a;
    return '1 ' . currencySymbol($hi) . ' = ' . money($fx, true, $lo);
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
    if ($delta < 0 && $new < 0 && empty($o['force']) && !wlAllowNegative($pdo)) {
        throw new WalletError('الرصيد غير كافٍ في «' . $wallet['name'] . '» — المتاح: ' . money($wallet['balance'], true, $wallet['currency']));
    }
    // المحفظة الموقوفة لا تقبل مصاريف جديدة، لكن يبقى بإمكان المدير إرجاع رصيدها للرئيسية
    if ($type === 'expense' && ((int)$wallet['active'] !== 1 || !empty($wallet['archived']))) {
        throw new WalletError('المحفظة «' . $wallet['name'] . '» موقوفة');
    }
    $pdo->prepare("UPDATE wl_wallets SET balance = ? WHERE id = ?")->execute([$new, $wallet['id']]);
    $wallet['balance'] = $new;

    // المعادل بالعملة الأساسية (للتقارير الشاملة) بسعر يوم الحركة
    $base = array_key_exists('base', $o) ? $o['base'] : round($delta * currencyRate($wallet['currency']), 2);
    $pdo->prepare("INSERT INTO wl_transactions
        (wallet_id, type, amount, balance_after, fx_rate, base_amount, category_id, counter_wallet_id, ref, txn_date, note, receipt, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $wallet['id'], $type, $delta, $new,
            isset($o['fx']) ? $o['fx'] : null,
            $base,
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
        wlMarkDirty();
        return $r;
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function wlLockTreasury(PDO $pdo, $walletId)
{
    $l = wlLock($pdo, [$walletId]);
    $w = $l[(int)$walletId];
    if ((int)$w['is_main'] !== 1 || !empty($w['archived'])) {
        throw new WalletError('اختر محفظة رئيسية');
    }
    return $w;
}

// إيداع (إضافة رصيد من خارج النظام) في محفظة رئيسية
function wlDeposit(PDO $pdo, $walletId, $amount, $date, $note, $by)
{
    return wlRun($pdo, function () use ($pdo, $walletId, $amount, $date, $note, $by) {
        $w = wlLockTreasury($pdo, $walletId);
        return wlApply($pdo, $w, $amount, 'deposit', ['date' => $date, 'note' => $note, 'by' => $by]);
    });
}

// سحب من محفظة رئيسية إلى خارج النظام
function wlWithdraw(PDO $pdo, $walletId, $amount, $date, $note, $by)
{
    return wlRun($pdo, function () use ($pdo, $walletId, $amount, $date, $note, $by) {
        $w = wlLockTreasury($pdo, $walletId);
        return wlApply($pdo, $w, -$amount, 'withdraw', ['date' => $date, 'note' => $note, 'by' => $by]);
    });
}

// قيد طرفي تحويل على محفظتين مقفولتين (قد تختلف عملتاهما)
function wlPostTransfer(PDO $pdo, array &$from, array &$to, $amtFrom, $amtTo, $fx, $date, $note, $by, $forceFrom = false)
{
    $base = baseCurrency();
    if ($from['currency'] === $base) {
        $baseAmt = $amtFrom;
    } elseif ($to['currency'] === $base) {
        $baseAmt = $amtTo;
    } else {
        $baseAmt = round($amtFrom * currencyRate($from['currency']), 2);
    }
    $ref = bin2hex(random_bytes(8));
    wlApply($pdo, $from, -$amtFrom, 'transfer_out', [
        'date' => $date, 'note' => $note, 'by' => $by, 'ref' => $ref, 'counter_wallet_id' => $to['id'],
        'fx' => $fx, 'base' => -$baseAmt, 'force' => $forceFrom,
    ]);
    return wlApply($pdo, $to, $amtTo, 'transfer_in', [
        'date' => $date, 'note' => $note, 'by' => $by, 'ref' => $ref, 'counter_wallet_id' => $from['id'],
        'fx' => $fx, 'base' => $baseAmt,
    ]);
}

// حفظ سعر الصرف المستخدم كسعر افتراضي (عندما يكون أحد الطرفين العملة الأساسية)
function wlRememberFx(PDO $pdo, $a, $b, $fx)
{
    $base = baseCurrency();
    if ($fx > 0 && $a !== $b && ($a === $base || $b === $base)) {
        $other = $a === $base ? $b : $a;
        $pdo->prepare("UPDATE wl_currencies SET rate = ?, updated_at = NOW() WHERE code = ? AND is_base = 0")->execute([$fx, $other]);
        wlCurrencies(true);
    }
}

// تحويل بين أي محفظتين. $amount بعملة المحفظة المصدر؛ إذا اختلفت العملتان يلزم سعر الصرف
// بصيغة «1 أقوى = X أضعف» ويُحسب المبلغ الواصل تلقائياً (مثال: 100$ × 13800 = 1,380,000 ل.س)
function wlTransfer(PDO $pdo, $fromId, $toId, $amount, $date, $note, $by, $fx = null, $rememberFx = true)
{
    if ((int)$fromId === (int)$toId) {
        throw new WalletError('لا يمكن التحويل لنفس المحفظة');
    }
    return wlRun($pdo, function () use ($pdo, $fromId, $toId, $amount, $date, $note, $by, $fx, $rememberFx) {
        $l    = wlLock($pdo, [$fromId, $toId]);
        $from = $l[(int)$fromId];
        $to   = $l[(int)$toId];
        if ((int)$to['active'] !== 1 || !empty($to['archived'])) {
            throw new WalletError('المحفظة «' . $to['name'] . '» موقوفة');
        }
        if (!empty($from['archived'])) {
            throw new WalletError('المحفظة «' . $from['name'] . '» مؤرشفة');
        }
        $same  = $from['currency'] === $to['currency'];
        $fx    = $same ? null : (float)$fx;
        $amtTo = wlFxConvert($amount, $from['currency'], $to['currency'], (float)$fx);
        if ($amtTo <= 0) {
            throw new WalletError('المبلغ بعد التحويل صفر — راجع سعر الصرف');
        }
        $id = wlPostTransfer($pdo, $from, $to, $amount, $amtTo, $fx, $date, $note, $by);
        if (!$same && $rememberFx) {
            wlRememberFx($pdo, $from['currency'], $to['currency'], $fx);
        }
        return $id;
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
function wlWalletSummaries(PDO $pdo, $from, $to, $activeOnly = false, $walletId = null, $withArchived = false)
{
    $sql = "SELECT w.id, w.user_id, w.name, w.currency, w.account_no, w.account_name, w.is_main, w.balance, w.active, w.archived,
               u.full_name AS owner, u.username,
               COALESCE(SUM(CASE WHEN t.type='expense' AND t.voided=0 AND t.txn_date BETWEEN ? AND ? THEN -t.amount END),0) AS month_spent,
               COALESCE(SUM(CASE WHEN t.amount>0 AND t.type<>'opening' AND t.voided=0 AND t.txn_date BETWEEN ? AND ? THEN t.amount END),0) AS month_in,
               COALESCE(SUM(CASE WHEN t.type='expense' AND t.voided=0 AND t.txn_date BETWEEN ? AND ? THEN -COALESCE(t.base_amount,t.amount) END),0) AS month_spent_base,
               COALESCE(SUM(CASE WHEN t.amount>0 AND t.type<>'opening' AND t.voided=0 AND t.txn_date BETWEEN ? AND ? THEN COALESCE(t.base_amount,t.amount) END),0) AS month_in_base,
               MAX(CASE WHEN t.voided=0 THEN t.txn_date END) AS last_move,
               MAX(CASE WHEN t.voided=0 THEN t.created_at END) AS last_activity
            FROM wl_wallets w
            LEFT JOIN wl_users u ON u.id = w.user_id
            LEFT JOIN wl_transactions t ON t.wallet_id = w.id
            WHERE 1=1";
    $p = [$from, $to, $from, $to, $from, $to, $from, $to];
    if ($activeOnly) {
        $sql .= " AND w.active = 1";
    }
    if (!$withArchived && !$walletId) {
        $sql .= " AND w.archived = 0";
    }
    if ($walletId) {
        $sql .= " AND w.id = ?";
        $p[] = (int)$walletId;
    }
    $sql .= " GROUP BY w.id, w.user_id, w.name, w.currency, w.account_no, w.account_name, w.is_main, w.balance, w.active, w.archived, u.full_name, u.username
              ORDER BY w.is_main DESC, w.active DESC, w.name";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

// آخر اطلاع على الويدجت (توقيت قاعدة البيانات) — أول مرة: بداية اليوم
function wlWidgetSeen(PDO $pdo)
{
    $v = (string)setting($pdo, 'widget_seen_at', '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
        $v = (string)$pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m-%d 00:00:00')")->fetchColumn();
    }
    return $v;
}

function wlWidgetAck(PDO $pdo)
{
    setSetting($pdo, 'widget_seen_at', (string)$pdo->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")->fetchColumn());
}

// التعديلات على كل محفظة منذ وقت معيّن: حركات جديدة أو إلغاءات.
// delta = صافي تغيّر الرصيد (الجديد غير الملغى − ما أُلغي من حركات سابقة)
function wlChangesSince(PDO $pdo, $since)
{
    $st = $pdo->prepare("SELECT wallet_id,
            SUM(CASE WHEN created_at > ? THEN 1 ELSE 0 END) + SUM(CASE WHEN voided = 1 AND voided_at > ? AND created_at <= ? THEN 1 ELSE 0 END) AS moves,
            COALESCE(SUM(CASE WHEN created_at > ? AND voided = 0 THEN amount END), 0)
              - COALESCE(SUM(CASE WHEN voided = 1 AND voided_at > ? AND created_at <= ? THEN amount END), 0) AS delta,
            GREATEST(COALESCE(MAX(CASE WHEN created_at > ? THEN created_at END), '1970-01-01'),
                     COALESCE(MAX(CASE WHEN voided = 1 AND voided_at > ? THEN voided_at END), '1970-01-01')) AS last_at
        FROM wl_transactions
        WHERE created_at > ? OR (voided = 1 AND voided_at > ?)
        GROUP BY wallet_id");
    $st->execute([$since, $since, $since, $since, $since, $since, $since, $since, $since, $since]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['wallet_id']] = ['moves' => (int)$r['moves'], 'delta' => round((float)$r['delta'], 2), 'last_at' => $r['last_at']];
    }
    if ($out) {
        // آخر 3 حركات لكل محفظة متغيّرة للعرض
        $st = $pdo->prepare("SELECT t.wallet_id, t.type, t.amount, t.note, t.voided, c.name AS cat
            FROM wl_transactions t LEFT JOIN wl_categories c ON c.id = t.category_id
            WHERE t.created_at > ? OR (t.voided = 1 AND t.voided_at > ?)
            ORDER BY GREATEST(t.created_at, COALESCE(t.voided_at, t.created_at)) DESC, t.id DESC");
        $st->execute([$since, $since]);
        foreach ($st->fetchAll() as $r) {
            $w = (int)$r['wallet_id'];
            if (!isset($out[$w]['items'])) $out[$w]['items'] = [];
            if (count($out[$w]['items']) < 3) $out[$w]['items'][] = $r;
        }
    }
    return $out;
}

// رصيد أول المدة — قيد افتتاحي لمطابقة الحسابات القديمة (لا يُخصم من الرئيسية).
// موجب = مبلغ في عهدة صاحب المحفظة، سالب = مبلغ له على الشركة.
// إعادة الضبط تُلغي القيد السابق (يبقى ظاهراً كـ«ملغى») وتسجّل الجديد؛ القيمة 0 تحذف الرصيد الافتتاحي.
function wlSetOpening(PDO $pdo, $walletId, $signedAmount, $date, $note, $by)
{
    return wlRun($pdo, function () use ($pdo, $walletId, $signedAmount, $date, $note, $by) {
        $l = wlLock($pdo, [$walletId]);
        $w = $l[(int)$walletId];
        if (!empty($w['archived'])) {
            throw new WalletError('المحفظة مؤرشفة — استعدها أولاً');
        }
        $st = $pdo->prepare("SELECT * FROM wl_transactions WHERE wallet_id = ? AND type = 'opening' AND voided = 0 FOR UPDATE");
        $st->execute([$w['id']]);
        foreach ($st->fetchAll() as $old) {
            $w['balance'] = round((float)$w['balance'] - (float)$old['amount'], 2);
            $pdo->prepare("UPDATE wl_wallets SET balance = ? WHERE id = ?")->execute([$w['balance'], $w['id']]);
            $pdo->prepare("UPDATE wl_transactions SET voided = 1, voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?")
                ->execute([(int)$by, 'استُبدل برصيد أول مدة جديد', $old['id']]);
        }
        $signedAmount = round((float)$signedAmount, 2);
        if ($signedAmount == 0) {
            return 0;
        }
        return wlApply($pdo, $w, $signedAmount, 'opening', [
            'date' => $date, 'note' => $note, 'by' => $by, 'force' => true,
        ]);
    });
}

function wlCurrentOpening(PDO $pdo, $walletId)
{
    $st = $pdo->prepare("SELECT * FROM wl_transactions WHERE wallet_id = ? AND type = 'opening' AND voided = 0 ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$walletId]);
    return $st->fetch();
}

// تسليم العهدة وأرشفة المحفظة: تُصفّى إلى صفر عبر محفظة رئيسية ثم تُخفى ويوقف حساب صاحبها.
// الرصيد الموجب يُرجَع للرئيسية، والسالب (مستحق له) يُدفع له منها؛ مع تحويل العملة إن اختلفت.
function wlArchiveWallet(PDO $pdo, $walletId, $treasuryId, $fx, $note, $by)
{
    return wlRun($pdo, function () use ($pdo, $walletId, $treasuryId, $fx, $note, $by) {
        if ((int)$walletId === (int)$treasuryId) {
            throw new WalletError('اختر محفظة رئيسية أخرى للتسوية');
        }
        $l = wlLock($pdo, [$walletId, $treasuryId]);
        $w = $l[(int)$walletId];
        $m = $l[(int)$treasuryId];
        if (!empty($w['archived'])) {
            throw new WalletError('المحفظة مؤرشفة مسبقاً');
        }
        if ((int)$m['is_main'] !== 1 || !empty($m['archived'])) {
            throw new WalletError('اختر محفظة رئيسية للتسوية');
        }
        $bal  = round((float)$w['balance'], 2);
        $date = date('Y-m-d');
        $note = trim((string)$note) !== '' ? $note : 'تسليم العهدة عند الأرشفة';
        $same = $w['currency'] === $m['currency'];
        $fx   = $same ? null : (float)$fx;
        if ($bal > 0) {
            $amtM = wlFxConvert($bal, $w['currency'], $m['currency'], (float)$fx);
            wlPostTransfer($pdo, $w, $m, $bal, $amtM, $fx, $date, $note, $by, true);
        } elseif ($bal < 0) {
            $amtM = wlFxConvert(-$bal, $w['currency'], $m['currency'], (float)$fx);
            wlPostTransfer($pdo, $m, $w, $amtM, -$bal, $fx, $date, $note . ' (تسوية مستحق له)', $by);
        }
        $pdo->prepare("UPDATE wl_wallets SET archived = 1, archived_at = NOW(), active = 0 WHERE id = ?")->execute([$w['id']]);
        // حساب المدير لا يُوقف أبداً عند أرشفة محفظته الشخصية — وإلا يُقفل خارج النظام
        if ($w['user_id']) {
            $pdo->prepare("UPDATE wl_users SET active = 0 WHERE id = ? AND role <> 'admin'")->execute([$w['user_id']]);
        }
        return $bal;
    });
}

function wlRestoreWallet(PDO $pdo, $walletId)
{
    $pdo->prepare("UPDATE wl_wallets SET archived = 0, archived_at = NULL, active = 1 WHERE id = ? AND is_main = 0")->execute([(int)$walletId]);
    $pdo->prepare("UPDATE wl_users u JOIN wl_wallets w ON w.user_id = u.id SET u.active = 1 WHERE w.id = ?")->execute([(int)$walletId]);
}

// حذف نهائي لمستخدم ومحفظته — مسموح فقط إذا لم يُسجَّل عليهما أي شيء إطلاقاً
function wlDeleteUser(PDO $pdo, $userId)
{
    return wlRun($pdo, function () use ($pdo, $userId) {
        $st = $pdo->prepare("SELECT id, balance FROM wl_wallets WHERE user_id = ? FOR UPDATE");
        $st->execute([(int)$userId]);
        $w = $st->fetch();
        $st = $pdo->prepare("SELECT COUNT(*) FROM wl_transactions WHERE created_by = ? OR wallet_id = ? OR counter_wallet_id = ?");
        $wid = $w ? (int)$w['id'] : 0;
        $st->execute([(int)$userId, $wid, $wid]);
        if ((int)$st->fetchColumn() > 0) {
            throw new WalletError('لا يمكن الحذف: عليها حركات مسجّلة — استخدم «تسليم العهدة وأرشفة» بدلاً من ذلك');
        }
        if ($w) {
            $pdo->prepare("DELETE FROM wl_wallets WHERE id = ?")->execute([$wid]);
        }
        $pdo->prepare("DELETE FROM wl_users WHERE id = ?")->execute([(int)$userId]);
        return true;
    });
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
    $col = $walletId ? 't.amount' : 'COALESCE(t.base_amount, t.amount)';
    $sql = "SELECT c.id, c.name, c.icon, SUM(-$col) AS total, COUNT(*) AS cnt
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
    $a = !empty($f['wallet_id']) ? 't.amount' : 'COALESCE(t.base_amount, t.amount)';
    $st = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN t.amount > 0 AND t.type <> 'opening' THEN $a END), 0) AS inflow,
            COALESCE(SUM(CASE WHEN t.type = 'expense' THEN -$a END), 0) AS spent,
            COALESCE(SUM(CASE WHEN t.amount < 0 AND t.type NOT IN ('expense','opening') THEN -$a END), 0) AS outflow,
            COALESCE(SUM(CASE WHEN t.type = 'opening' THEN $a END), 0) AS opening,
            COUNT(*) AS cnt
        FROM wl_transactions t WHERE $where AND t.voided = 0");
    $st->execute($p);
    return $st->fetch();
}

function wlTxnQuery(PDO $pdo, array $f, $limit = 200, $offset = 0)
{
    list($where, $p) = wlTxnWhere($f);
    $sql = "SELECT t.*, w.name AS wallet_name, w.is_main, w.currency,
                   cw.currency AS counter_currency, ct.amount AS counter_amount,
                   c.name AS cat_name, c.icon AS cat_icon, c.account_no AS cat_acc,
                   cw.name AS counter_name, u.full_name AS creator_name
            FROM wl_transactions t
            JOIN wl_wallets w ON w.id = t.wallet_id
            LEFT JOIN wl_categories c ON c.id = t.category_id
            LEFT JOIN wl_wallets cw ON cw.id = t.counter_wallet_id
            LEFT JOIN wl_transactions ct ON ct.ref = t.ref AND ct.id <> t.id
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
