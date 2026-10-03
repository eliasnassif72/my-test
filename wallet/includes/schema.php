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
        is_main    TINYINT(1) NOT NULL DEFAULT 0,
        balance    DECIMAL(15,2) NOT NULL DEFAULT 0,
        active     TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_wallet_user (user_id)
    $tail");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wl_categories (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL,
        icon       VARCHAR(16)  NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        active     TINYINT(1) NOT NULL DEFAULT 1
    $tail");

    // amount موقّع: موجب = دخول للمحفظة، سالب = خروج منها
    $pdo->exec("CREATE TABLE IF NOT EXISTS wl_transactions (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        wallet_id         INT NOT NULL,
        type              ENUM('deposit','withdraw','transfer_in','transfer_out','expense') NOT NULL,
        amount            DECIMAL(15,2) NOT NULL,
        balance_after     DECIMAL(15,2) NOT NULL,
        category_id       INT NULL,
        counter_wallet_id INT NULL,
        ref               VARCHAR(32) NULL,
        txn_date          DATE NOT NULL,
        note              VARCHAR(500) NULL,
        receipt           VARCHAR(255) NULL,
        created_by        INT NOT NULL,
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

    // المحفظة الرئيسية (مع مدير النظام) — واحدة فقط
    $hasMain = (int)$pdo->query("SELECT COUNT(*) FROM wl_wallets WHERE is_main=1")->fetchColumn();
    if ($hasMain === 0) {
        $pdo->exec("INSERT INTO wl_wallets (user_id, name, is_main, balance) VALUES (NULL, 'المحفظة الرئيسية', 1, 0)");
    }

    // تصنيفات المصاريف الافتراضية
    $hasCats = (int)$pdo->query("SELECT COUNT(*) FROM wl_categories")->fetchColumn();
    if ($hasCats === 0) {
        $defaults = [
            ['وقود ومواصلات', '⛽'],
            ['طعام وضيافة', '🍽️'],
            ['قرطاسية ومستلزمات مكتب', '📎'],
            ['اتصالات وإنترنت', '📱'],
            ['صيانة وإصلاحات', '🔧'],
            ['شحن وتوصيل', '🚚'],
            ['إيجارات', '🏢'],
            ['فواتير كهرباء وماء', '💡'],
            ['رواتب وأجور', '👷'],
            ['تسويق وإعلان', '📣'],
            ['متفرقات', '🧾'],
        ];
        $st = $pdo->prepare("INSERT INTO wl_categories (name, icon, sort_order) VALUES (?, ?, ?)");
        foreach ($defaults as $i => $c) {
            $st->execute([$c[0], $c[1], ($i + 1) * 10]);
        }
    }

    $st = $pdo->prepare("INSERT IGNORE INTO wl_settings (k, v) VALUES (?, ?)");
    $st->execute(['app_name', 'Elias Control — المحافظ']);
    $st->execute(['currency', 'ل.س']);
    $st->execute(['allow_negative', '0']);
    $st->execute(['api_key', bin2hex(random_bytes(20))]);
}
