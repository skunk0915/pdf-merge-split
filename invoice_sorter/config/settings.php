<?php
/**
 * PDF Invoice Sorter - Configuration & Helper Functions
 */

define('BASE_DIR', dirname(__DIR__));
define('STORAGE_DIR', BASE_DIR . '/storage');
define('INBOX_DIR', STORAGE_DIR . '/inbox');
define('SORTED_DIR', STORAGE_DIR . '/sorted');
define('LOGS_DIR', STORAGE_DIR . '/logs');
define('DB_PATH', STORAGE_DIR . '/db.sqlite');
define('LOG_FILE', LOGS_DIR . '/app.log');

// ディレクトリ自動生成
foreach ([STORAGE_DIR, INBOX_DIR, SORTED_DIR, LOGS_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

/**
 * ログ記録関数（10MBローテーション対応）
 */
function writeAppLog($message, $level = 'INFO') {
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > 10 * 1024 * 1024) {
        $backup = LOGS_DIR . '/app_' . date('Ymd_His') . '.log';
        @rename(LOG_FILE, $backup);
        // バックアップが3個を超えたら古いものを削除
        $backups = glob(LOGS_DIR . '/app_*.log');
        if (count($backups) > 3) {
            sort($backups);
            while (count($backups) > 3) {
                @unlink(array_shift($backups));
            }
        }
    }
    $timestamp = date('Y-m-d H:i:s');
    $logLine = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($level), $message);
    @file_put_contents(LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * SQLiteデータベース接続の取得
 */
function getDbConnection() {
    static $db = null;
    if ($db === null) {
        $isNew = !file_exists(DB_PATH);
        try {
            $db = new PDO('sqlite:' . DB_PATH);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            if ($isNew || filesize(DB_PATH) === 0) {
                initDatabase($db);
            }
        } catch (PDOException $e) {
            writeAppLog("DB connection error: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }
    return $db;
}

/**
 * データベース初期化
 */
function initDatabase($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            original_filename TEXT NOT NULL,
            file_hash TEXT,
            inbox_path TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'queued', -- queued, processing, completed, needs_review, error
            client_name TEXT,
            invoice_date TEXT,
            payment_date TEXT,
            year_month TEXT,
            sorted_filename TEXT,
            sorted_path TEXT,
            folder_structure TEXT,
            gdrive_synced INTEGER DEFAULT 0,
            gcp_synced INTEGER DEFAULT 0,
            error_message TEXT,
            ai_confidence REAL DEFAULT 0,
            ai_raw_output TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status);
        CREATE INDEX IF NOT EXISTS idx_jobs_created ON jobs(created_at);
    ");

    // デフォルト設定の投入
    $defaultSettings = [
        'naming_pattern' => 'invoice_date,client_name,payment_date', // 請求日_クライアント_支払日
        'date_format' => 'YYYY-MM-DD',
        'folder_pattern' => 'client_then_month', // client_then_month (クライアント/年月) or month_then_client (年月/クライアント)
        'auto_gdrive_sync' => '0',
        'auto_gcp_sync' => '0',
    ];

    $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)");
    foreach ($defaultSettings as $k => $v) {
        $stmt->execute([':key' => $k, ':value' => $v]);
    }
}

/**
 * 設定値の取得
 */
function getSetting($key, $default = null) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = :key");
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

/**
 * 設定値の保存
 */
function setSetting($key, $value) {
    $db = getDbConnection();
    $stmt = $db->prepare("INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)
                          ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = CURRENT_TIMESTAMP");
    return $stmt->execute([':key' => $key, ':value' => $value]);
}

/**
 * 命名規則に基づいてファイル名を生成
 * 
 * @param string $pattern カンマ区切りの順序（例: "invoice_date,client_name,payment_date"）
 * @param string $clientName クライアント名
 * @param string $invoiceDate 請求日 (YYYY-MM-DD)
 * @param string $paymentDate 支払日 (YYYY-MM-DD)
 * @param string $originalExt 拡張子（通常は pdf）
 * @return string 生成されたファイル名
 */
function buildSortedFilename($pattern, $clientName, $invoiceDate, $paymentDate, $originalExt = 'pdf') {
    $parts = explode(',', $pattern);
    $nameSegments = [];

    // サニタイズ
    $safeClient = preg_replace('/[\\/\\\\:\\*\\?\"<>\\|]/u', '', trim($clientName ?: ''));
    if ($safeClient === '') $safeClient = '不明取引先';

    $safeInvoice = preg_replace('/[^0-9\\-]/', '', trim($invoiceDate ?: ''));
    if ($safeInvoice === '') $safeInvoice = '請求日不明';

    $safePayment = preg_replace('/[^0-9\\-]/', '', trim($paymentDate ?: ''));
    
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === 'invoice_date') {
            $nameSegments[] = $safeInvoice;
        } elseif ($part === 'client_name') {
            $nameSegments[] = $safeClient;
        } elseif ($part === 'payment_date') {
            if ($safePayment !== '') {
                $nameSegments[] = $safePayment;
            }
        }
    }

    if (empty($nameSegments)) {
        $nameSegments = [$safeInvoice, $safeClient];
    }

    return implode('_', $nameSegments) . '.' . ltrim($originalExt, '.');
}

/**
 * フォルダ階層の決定
 * 
 * @param string $folderPattern 'client_then_month' or 'month_then_client'
 * @param string $clientName
 * @param string $yearMonth (YYYY-MM)
 * @return string 相対ディレクトリパス（例: "ABC商事/2026-11/"）
 */
function buildFolderPath($folderPattern, $clientName, $yearMonth) {
    $safeClient = preg_replace('/[\\/\\\\:\\*\\?\"<>\\|]/u', '', trim($clientName ?: '不明取引先'));
    if ($safeClient === '') $safeClient = '不明取引先';

    $safeYm = preg_replace('/[^0-9\\-]/', '', trim($yearMonth ?: ''));
    if (!preg_match('/^\\d{4}-\\d{2}$/', $safeYm)) {
        $safeYm = date('Y-m');
    }

    if ($folderPattern === 'month_then_client') {
        return $safeYm . '/' . $safeClient . '/';
    } else {
        return $safeClient . '/' . $safeYm . '/';
    }
}

/**
 * 空ディレクトリの再帰的クリーンアップ
 */
function cleanEmptyDirectories($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    $items = array_diff($items, ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            cleanEmptyDirectories($path);
        }
    }
    // 再度チェックして空なら削除（ただしSORTED_DIR自体は削除しない）
    $remaining = array_diff(scandir($dir), ['.', '..']);
    if (empty($remaining) && realpath($dir) !== realpath(SORTED_DIR) && realpath($dir) !== realpath(STORAGE_DIR)) {
        @rmdir($dir);
    }
}
