<?php
// هيكل قاعدة البيانات — كل الجداول utf8mb4 صراحةً (لا نعتمد على ترميز القاعدة الافتراضي)

function wlSchemaInstall(PDO $pdo)
{
    $tail = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS wl_users (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(60)  NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name     VARCHAR(120) NOT NULL,
        phone         VARCHAR(32)  NULL,
        role          ENUM('admin','user') NOT NULL DEFAULT 'user',
        active        TINYINT(1) NOT NULL DEFAULT 1,
        last_login    DATETIME NULL,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    $tail");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wl_wallets (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NULL,
        name       VARCHAR(120) NOT NULL,
        currency   VARCHAR(8) NOT NULL DEFAULT 'SYP',
        account_no   VARCHAR(20)  NULL,
        account_name VARCHAR(120) NULL,
        is_main    TINYINT(1) NOT NULL DEFAULT 0,
        balance    DECIMAL(15,2) NOT NULL DEFAULT 0,
        active     TINYINT(1) NOT NULL DEFAULT 1,
        archived    TINYINT(1) NOT NULL DEFAULT 0,
        archived_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_wallet_user (user_id)
    $tail");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wl_categories (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL,
        icon       VARCHAR(16)  NOT NULL DEFAULT '',
        account_no VARCHAR(20)  NULL,
        group_name VARCHAR(100) NULL,
        hints      TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        active     TINYINT(1) NOT NULL DEFAULT 1
    $tail");

    // amount موقّع: موجب = دخول للمحفظة، سالب = خروج منها
    $pdo->exec("CREATE TABLE IF NOT EXISTS wl_transactions (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        wallet_id         INT NOT NULL,
        type              ENUM('deposit','withdraw','transfer_in','transfer_out','expense','opening') NOT NULL,
        amount            DECIMAL(15,2) NOT NULL,
        balance_after     DECIMAL(15,2) NOT NULL,
        fx_rate           DECIMAL(18,6) NULL,
        base_amount       DECIMAL(18,2) NULL,
        category_id       INT NULL,
        counter_wallet_id INT NULL,
        ref               VARCHAR(32) NULL,
        txn_date          DATE NOT NULL,
        note              VARCHAR(500) NULL,
        receipt           VARCHAR(255) NULL,
        created_by        INT NOT NULL,
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        exported_at       DATETIME NULL,
        voided            TINYINT(1) NOT NULL DEFAULT 0,
        voided_by         INT NULL,
        voided_at         DATETIME NULL,
        void_reason       VARCHAR(255) NULL,
        KEY idx_wallet_date (wallet_id, txn_date),
        KEY idx_cat (category_id),
        KEY idx_ref (ref),
        KEY idx_type (type)
    $tail");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wl_settings (
        k VARCHAR(64) PRIMARY KEY,
        v TEXT NULL
    $tail");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wl_login_attempts (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        ip_address   VARCHAR(45) NOT NULL,
        attempted_at DATETIME NOT NULL,
        KEY idx_ip_time (ip_address, attempted_at)
    $tail");

    wlSchemaCurrencies($pdo, 'ل.س');

    // المحفظة الرئيسية الأولى (خزينة بالعملة الأساسية) — يمكن إضافة غيرها لاحقاً
    $hasMain = (int)$pdo->query("SELECT COUNT(*) FROM wl_wallets WHERE is_main=1")->fetchColumn();
    if ($hasMain === 0) {
        $pdo->exec("INSERT INTO wl_wallets (user_id, name, is_main, balance) VALUES (NULL, 'المحفظة الرئيسية', 1, 0)");
    }

    // تصنيفات المصاريف = حسابات المصاريف في برنامج المحاسبة (رقم + اسم + الحساب الأب)
    $hasCats = (int)$pdo->query("SELECT COUNT(*) FROM wl_categories")->fetchColumn();
    if ($hasCats === 0) {
        wlSeedAccountCategories($pdo, false);
    }

    $st = $pdo->prepare("INSERT IGNORE INTO wl_settings (k, v) VALUES (?, ?)");
    $st->execute(['app_name', 'Elias Control — المحافظ']);
    $st->execute(['currency', 'ل.س']);
    $st->execute(['allow_negative', '0']);
    $st->execute(['api_key', bin2hex(random_bytes(20))]);
    $st->execute(['schema_version', (string)WL_SCHEMA_VERSION]);
}

define('WL_SCHEMA_VERSION', 4);
define('WL_BASE_CURRENCY', 'SYP');

// جدول العملات — rate = قيمة وحدة واحدة بالعملة الأساسية (الليرة السورية = 1)
// الدولار يُضاف بسعر 0 (غير محدد) حتى يُدخل المدير سعره من صفحة العملات
function wlSchemaCurrencies(PDO $pdo, $baseSymbol)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS wl_currencies (
        code       VARCHAR(8) PRIMARY KEY,
        name       VARCHAR(50) NOT NULL,
        symbol     VARCHAR(12) NOT NULL,
        rate       DECIMAL(18,6) NOT NULL DEFAULT 0,
        is_base    TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        active     TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $st = $pdo->prepare("INSERT IGNORE INTO wl_currencies (code, name, symbol, rate, is_base, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    $st->execute([WL_BASE_CURRENCY, 'ليرة سورية', $baseSymbol ?: 'ل.س', 1, 1, 10]);
    $st->execute(['USD', 'دولار أمريكي', '$', 0, 0, 20]);
}

// استيراد حسابات المصاريف من برنامج المحاسبة كتصنيفات (لا يكرر حساباً موجوداً).
// $hideOthers: إخفاء التصنيفات القديمة التي ليس لها رقم حساب (تبقى في السجل).
function wlSeedAccountCategories(PDO $pdo, $hideOthers)
{
    $rows = include __DIR__ . '/accounts_seed.php';
    usort($rows, function ($a, $b) { return $b[3] - $a[3]; });
    $exists = $pdo->prepare("SELECT COUNT(*) FROM wl_categories WHERE account_no = ?");
    $ins = $pdo->prepare("INSERT INTO wl_categories (name, icon, account_no, group_name, hints, sort_order) VALUES (?, '', ?, ?, ?, ?)");
    $n = 0;
    foreach ($rows as $i => $r) {
        $exists->execute([$r[0]]);
        if ((int)$exists->fetchColumn() > 0) {
            continue;
        }
        $ins->execute([$r[1], $r[0], $r[2] !== '' ? $r[2] : null, implode("\n", $r[4]), ($i + 1) * 10]);
        $n++;
    }
    if ($hideOthers) {
        $pdo->exec("UPDATE wl_categories SET active = 0 WHERE account_no IS NULL OR account_no = ''");
    }
    return $n;
}

// ترقية قاعدة بيانات قائمة إلى آخر هيكل — آمنة للتكرار، وتعمل تلقائياً من bootstrap
function wlSchemaMigrate(PDO $pdo)
{
    $ver = (int)setting($pdo, 'schema_version', '1');
    if ($ver >= WL_SCHEMA_VERSION) {
        return;
    }
    $hasTable = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wl_wallets'")->fetchColumn();
    if (!$hasTable) {
        return; // لم يُثبَّت بعد — start.php ينشئ الهيكل الكامل
    }
    $col = function ($table, $column) use ($pdo) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    };
    if ($ver < 2) {
        // v2: رصيد أول المدة + أرشفة المحافظ
        $pdo->exec("ALTER TABLE wl_transactions MODIFY type
            ENUM('deposit','withdraw','transfer_in','transfer_out','expense','opening') NOT NULL");
        if (!$col('wl_wallets', 'archived')) {
            $pdo->exec("ALTER TABLE wl_wallets ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER active");
        }
        if (!$col('wl_wallets', 'archived_at')) {
            $pdo->exec("ALTER TABLE wl_wallets ADD COLUMN archived_at DATETIME NULL AFTER archived");
        }
    }
    if ($ver < 3) {
        // v3: تعدد العملات + محافظ رئيسية متعددة
        wlSchemaCurrencies($pdo, setting($pdo, 'currency', 'ل.س'));
        if (!$col('wl_wallets', 'currency')) {
            $pdo->exec("ALTER TABLE wl_wallets ADD COLUMN currency VARCHAR(8) NOT NULL DEFAULT 'SYP' AFTER name");
        }
        if (!$col('wl_transactions', 'fx_rate')) {
            $pdo->exec("ALTER TABLE wl_transactions ADD COLUMN fx_rate DECIMAL(18,6) NULL AFTER balance_after");
        }
        if (!$col('wl_transactions', 'base_amount')) {
            $pdo->exec("ALTER TABLE wl_transactions ADD COLUMN base_amount DECIMAL(18,2) NULL AFTER fx_rate");
        }
        $pdo->exec("UPDATE wl_transactions SET base_amount = amount WHERE base_amount IS NULL");
    }
    if ($ver < 4) {
        // v4: أرقام حسابات المحاسبة للتصنيفات والمحافظ + تصدير السندات
        if (!$col('wl_categories', 'account_no')) {
            $pdo->exec("ALTER TABLE wl_categories ADD COLUMN account_no VARCHAR(20) NULL AFTER icon");
        }
        if (!$col('wl_categories', 'group_name')) {
            $pdo->exec("ALTER TABLE wl_categories ADD COLUMN group_name VARCHAR(100) NULL AFTER account_no");
        }
        if (!$col('wl_categories', 'hints')) {
            $pdo->exec("ALTER TABLE wl_categories ADD COLUMN hints TEXT NULL AFTER group_name");
        }
        if (!$col('wl_wallets', 'account_no')) {
            $pdo->exec("ALTER TABLE wl_wallets ADD COLUMN account_no VARCHAR(20) NULL AFTER currency");
        }
        if (!$col('wl_wallets', 'account_name')) {
            $pdo->exec("ALTER TABLE wl_wallets ADD COLUMN account_name VARCHAR(120) NULL AFTER account_no");
        }
        if (!$col('wl_transactions', 'exported_at')) {
            $pdo->exec("ALTER TABLE wl_transactions ADD COLUMN exported_at DATETIME NULL AFTER created_at");
        }
        wlSeedAccountCategories($pdo, true);
    }
    setSetting($pdo, 'schema_version', (string)WL_SCHEMA_VERSION);
}
