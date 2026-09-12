<?php
// Buffer all output from the very first byte so headers can always be sent.
if (!ob_get_level()) {
    ob_start();
}

// Production: suppress display of errors to prevent output before headers.
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Ensure standardized timezone (Ghana GMT / Africa/Accra)
if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Africa/Accra');
}

// Google Gemini AI API Key (Set via .env or server environment variable: GEMINI_API_KEY)

// -- Secure Session Cookie Configuration -------------------------------------
// Guard against "headers already sent" — only set when session hasn't started yet.
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || ($_SERVER['SERVER_PORT'] ?? 80) == 443
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    @session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $_isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_isHttps);
}

// Start session once centrally — suppressed with @ so no PHP warning can ever render on screen
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}
// ---------------------------------------------------------------------------

// Load environment configuration from .env file if present.
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) { continue; }
        if (!strpos($line, '=')) { continue; }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim(trim($value), "\"'");
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// Application metadata.
define('APP_NAME', getenv('APP_NAME') ?: 'Apex Console');
$fallbackUrl = 'http://localhost/';
if (isset($_SERVER['HTTP_HOST'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')) ? "https://" : "http://";
    define('APP_URL', $protocol . $_SERVER['HTTP_HOST'] . '/');
} else {
    define('APP_URL', getenv('APP_URL') ?: $fallbackUrl);
}
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'apexpri8_share');
define('DB_USER', getenv('DB_USER') ?: 'apexpri8_share');
define('DB_PASS', getenv('DB_PASS') ?: 'apexpri8_share');

define('SMS_API_KEY', getenv('SMS_API_KEY') ?: 'smp_tQQKxqoOTU7PtBXi40PuNhtgazPmbBp4jnPWhS17');
define('SMS_SENDER_ID', getenv('SMS_SENDER_ID') ?: 'APEX-PRIME');
define('SMS_BASE_URL', getenv('SMS_BASE_URL') ?: 'https://aigh.dev/api/v1');

define('SMTP_HOST', getenv('SMTP_HOST') ?: 'mail.apexprime.club');
define('SMTP_PORT', getenv('SMTP_PORT') ?: '465');
define('SMTP_USER', getenv('SMTP_USER') ?: 'info@apexprime.club');
define('SMTP_PASS', getenv('SMTP_PASS') ?: ''); // Assuming set in actual env if missing
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'ssl');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Apex Prime Tech');

define('SUPPLIER_API_KEY', getenv('SUPPLIER_API_KEY') ?: 'bh_live_03c994bfd0a543be8f910676e4bbb1d1');
define('SUPPLIER_BASE_URL', getenv('SUPPLIER_BASE_URL') ?: 'https://qrzjkrkawcdoaggblvjc.supabase.co/functions/v1/developer-api');

define('SUPPLIER2_API_KEY', getenv('SUPPLIER2_API_KEY') ?: 'sk_live_2a85b645e8ddeee0ad93aa43d9b127d889b93ef2d2f8bc3486b2b45986185d49');
define('SUPPLIER2_PUBLIC_KEY', getenv('SUPPLIER2_PUBLIC_KEY') ?: '');
define('SUPPLIER2_BASE_URL', getenv('SUPPLIER2_BASE_URL') ?: 'https://boatlinkgh.com/api/v1');

define('AFA_API_KEY', getenv('AFA_API_KEY') ?: 'bh_live_03c994bfd0a543be8f910676e4bbb1d1');
define('AFA_API_URL', getenv('AFA_API_URL') ?: 'https://qrzjkrkawcdoaggblvjc.supabase.co/functions/v1/developer-api');

define('SUPPLIER5_API_KEY', getenv('SUPPLIER5_API_KEY') ?: '3e2e325d890b2d3aa39bc039568744637ad7b591');
define('SUPPLIER5_BASE_URL', getenv('SUPPLIER5_BASE_URL') ?: 'https://agent.jaybartservices.com/api/v1');

define('CHECKER1_API_KEY', getenv('CHECKER1_API_KEY') ?: 'api_c368cf39261975a5fdcbda48e2a23a0ddf3bf55639ee0def04343bc5ed279933');
define('CHECKER1_BASE_URL', getenv('CHECKER1_BASE_URL') ?: 'https://cleanheartsolutions.com/api');

define('MTNUP2U_API_KEY', getenv('MTNUP2U_API_KEY') ?: 'sk_cec9bdc2f01fb41e601b0097f67db47407b87b056aac5f82');
define('MTNUP2U_BASE_URL', getenv('MTNUP2U_BASE_URL') ?: 'https://app.datahubgh.com/api/external');

define('MTNUP2GB_API_KEY', getenv('MTNUP2GB_API_KEY') ?: 'sk_1fa7d0703419ca6d1bdc0c6f9e8afe126533df74b9af8f06');
define('MTNUP2GB_BASE_URL', getenv('MTNUP2GB_BASE_URL') ?: 'https://user.datahubgh.com/api/external');

define('NITGHT_API_KEY', getenv('NITGHT_API_KEY') ?: 'ak_live_FXUW2CUVKHCDRRDFRPNUTSXPGB3QE7TGRNKMA3ZH');
define('NITGHT_BASE_URL', getenv('NITGHT_BASE_URL') ?: 'https://aviatordelivery.app/api/v1');

define('WHATSAPP_TOKEN', getenv('WHATSAPP_TOKEN') ?: 'EAAVbwPrkZA5EBSWYPZCejcQIT87IReAyjpXQZAAsjxQ6BWLvP6ZCwnabpoTbQ2S6UN0ZBnBJSKcYlNDiV7Yx3mSBTzJO3Ri6sy0XFFL9NeqcyIsDEbKDf2bFUwEQ10Az7Tena4DvwmQeMj4iQAZCeLAkURotrEpHaYLKDNPPsU8jYbhmnYd05Jq1tXbbWZB8wZDZD');
define('WHATSAPP_VERIFY_TOKEN', getenv('WHATSAPP_VERIFY_TOKEN') ?: 'EAAVbwPrkZA5EBSWYPZCejcQIT87IReAyjpXQZAAsjxQ6BWLvP6ZCwnabpoTbQ2S6UN0ZBnBJSKcYlNDiV7Yx3mSBTzJO3Ri6sy0XFFL9NeqcyIsDEbKDf2bFUwEQ10Az7Tena4DvwmQeMj4iQAZCeLAkURotrEpHaYLKDNPPsU8jYbhmnYd05Jq1tXbbWZB8wZDZD');
define('WHATSAPP_PHONE_NUMBER_ID', getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '1361643693689804');
define('WHATSAPP_BUSINESS_ACCOUNT_ID', getenv('WHATSAPP_BUSINESS_ACCOUNT_ID') ?: '2143228069926641');


// ── DB-backed system settings helpers ────────────────────────────────────────

/**
 * Helper function to check if supplier APIs are online for a network.
 */
function isApiOnline($network = 'MTN'): bool {
    $settingsFile = __DIR__ . '/admin_settings.json';
    $settings = [];
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
    }

    if (isset($settings['api_online']) && !$settings['api_online']) return false;
    if (isset($settings['api_enabled']) && !$settings['api_enabled']) return false;

    $n = strtolower(trim($network));
    $netKey = $n . '_enabled';
    if (isset($settings[$netKey]) && !$settings[$netKey]) return false;

    $s1       = !empty($settings['supplier1_enabled']);
    $s2       = !empty($settings['mtnup2u_enabled']);
    $s3       = !empty($settings['backup_enabled']);
    $s4       = !empty($settings['supplier5_enabled']);
    $night    = !empty($settings['nitght_enabled']);
    $mtnup2gb = !empty($settings['mtnup2gb_enabled']);

    return ($s1 || $s2 || $s3 || $s4 || $night || $mtnup2gb);
}

/**
 * Check if an error message or API response indicates insufficient balance / funds / stock at supplier API.
 */
function isInsufficientBalanceError($msg): bool {
    if (empty($msg)) return false;
    if (is_array($msg)) {
        $msg = json_encode($msg);
    }
    if (!is_string($msg)) return false;
    $msgLower = strtolower($msg);
    
    $patterns = [
        'insufficient balance',
        'insufficient wallet balance',
        'insufficient funds',
        'insufficient fund',
        'insufficient credit',
        'insufficient credits',
        'insufficient data balance',
        'insufficient_balance',
        'insufficient_fund',
        'insufficient_funds',
        'low balance',
        'low_balance',
        'balance too low',
        'not enough balance',
        'not enough credit',
        'not enough funds',
        'balance is insufficient',
        'your balance is insufficient',
        'insufficient stock',
        'out of stock',
        'insufficient user balance',
        'insufficient api balance',
        'check your balance',
        'wallet balance is too low',
        'insufficient float',
        'balance is low',
        'insufficient-balance',
        'balance_insufficient'
    ];
    
    foreach ($patterns as $pattern) {
        if (strpos($msgLower, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Check if a message or error indicates beneficiary list issue from MTNUP2U PORTAL or other APIs.
 */
function isBeneficiaryError($msg): bool {
    if (empty($msg)) {
        return false;
    }
    if (is_array($msg)) {
        $msg = json_encode($msg);
    }
    if (!is_string($msg)) {
        return false;
    }
    $msgLower = strtolower($msg);
    $patterns = [
        'is not added to our beneficiary list',
        'not added to our beneficiary list',
        'will be added to our beneficiary list',
        'has been recorded and will be added',
        'not on our beneficiary list',
        'beneficiary list',
        'beneficiary error',
        'failed_beneficiary',
        'not a beneficiary'
    ];
    foreach ($patterns as $pattern) {
        if (strpos($msgLower, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Load ALL settings into an associative array.
 * Priority: system_settings table → admin_settings.json fallback.
 */
function load_all_settings(PDO $pdo): array {
    $defaults = [
        'momo_number' => '', 'momo_name' => '', 'momo_reference' => 'Use your Order ID as payment reference.',
        'mtn_enabled' => true, 'telecel_enabled' => true, 'ishare_enabled' => true, 'afa_enabled' => true,
        'mtn_stock_status' => 'instock', 'telecel_stock_status' => 'instock', 'ishare_stock_status' => 'instock',
        'estore_digital_enabled' => true, 'estore_wassce_enabled' => true, 'estore_bece_enabled' => true,
        'estore_mtn_enabled' => true, 'estore_telecel_enabled' => true, 'estore_ishare_enabled' => true,
        'paystack_enabled' => true,
        'supplier1_enabled' => false, 'supplier1_mtn_enabled' => false, 'supplier1_ishare_enabled' => false, 'supplier1_telecel_enabled' => false, 'supplier1_min_gb' => 0, 'supplier1_max_gb' => 999999,
        'supplier2_enabled' => false, 'supplier2_mtn_enabled' => false, 'supplier2_min_gb' => 0, 'supplier2_max_gb' => 999999,
        'supplier3_enabled' => false, 'supplier3_mtn_enabled' => false, 'supplier3_min_gb' => 0, 'supplier3_max_gb' => 999999,
        'mtnup2u_enabled' => false, 'mtnup2u_mtn_enabled' => false, 'mtnup2u_ishare_enabled' => false, 'mtnup2u_telecel_enabled' => false, 'mtnup2u_min_gb' => 0, 'mtnup2u_max_gb' => 999999,
        'nitght_enabled' => false, 'nitght_mtn_enabled' => false, 'nitght_ishare_enabled' => false, 'nitght_telecel_enabled' => false, 'nitght_min_gb' => 0, 'nitght_max_gb' => 999999,
        'mtnup2gb_min_gb' => 0, 'mtnup2gb_max_gb' => 999999,
        'supplier5_min_gb' => 0, 'supplier5_max_gb' => 999999,
        'backup_min_gb' => 0, 'backup_max_gb' => 999999,
        'afa_api_enabled' => false, 'afa_min_amount' => 10,
        'wassce_base_cost' => 15.50, 'bece_base_cost' => 15.50, 'netflix_base_cost' => 40.00,
        'wassce_base_price' => 15.50, 'bece_base_price' => 15.50, 'netflix_base_price' => 40.00,
        'wassce_price' => 20, 'bece_price' => 20, 'netflix_price' => 50,
        'registration_code_enabled' => true,
        'announcement_enabled' => false,
        'announcement_text'    => '',
        'announcement_type'    => 'info',
        'supplier_priority_order' => 'nitght,mtnup2gb,mtnup2u,supplier5,supplier1,supplier2,supplier3,backup',
        'refund_sms_enabled'     => true,
        'refund_sms_elite'       => true,
        'refund_sms_dealers'     => true,
        'refund_sms_super_agent' => true,
        'refund_sms_other'       => true,
        'refund_sms_template'    => "Dear {username}, your {gb}{network}data order #{order_id}{recipient} could not be completed and GHS {amount} has been refunded to your Apex Prime wallet. The recipient number has been submitted for network approval. Kindly try again after 1 week. We apologise for the inconvenience. - Apex Prime Support",
        // WhatsApp Cloud API Settings
        'whatsapp_enabled'             => true,
        'whatsapp_token'               => 'EAAVbwPrkZA5EBSWYPZCejcQIT87IReAyjpXQZAAsjxQ6BWLvP6ZCwnabpoTbQ2S6UN0ZBnBJSKcYlNDiV7Yx3mSBTzJO3Ri6sy0XFFL9NeqcyIsDEbKDf2bFUwEQ10Az7Tena4DvwmQeMj4iQAZCeLAkURotrEpHaYLKDNPPsU8jYbhmnYd05Jq1tXbbWZB8wZDZD',
        'whatsapp_verify_token'        => 'Apex Prime',
        'whatsapp_phone_number_id'     => '1361643693689804',
        'whatsapp_business_account_id' => '2143228069926641',
    ];

    $boolKeys = ['mtn_enabled','telecel_enabled','ishare_enabled','afa_enabled',
                 'estore_digital_enabled','estore_wassce_enabled','estore_bece_enabled',
                 'estore_mtn_enabled','estore_telecel_enabled','estore_ishare_enabled','paystack_enabled',
                 'supplier1_enabled','supplier1_mtn_enabled','supplier1_ishare_enabled','supplier1_telecel_enabled',
                 'supplier2_enabled','supplier2_mtn_enabled',
                 'supplier3_enabled','supplier3_mtn_enabled',
                 'mtnup2u_enabled','mtnup2u_mtn_enabled','mtnup2u_ishare_enabled','mtnup2u_telecel_enabled',
                 'nitght_enabled','nitght_mtn_enabled','nitght_ishare_enabled','nitght_telecel_enabled',
                 'afa_api_enabled', 'registration_code_enabled', 'announcement_enabled',
                 'refund_sms_enabled', 'refund_sms_elite', 'refund_sms_dealers', 'refund_sms_super_agent', 'refund_sms_other',
                 'whatsapp_enabled'];

    // 1. Fallback: JSON file
    $jsonFile = __DIR__ . '/admin_settings.json';
    if (file_exists($jsonFile)) {
        $fromFile = json_decode(file_get_contents($jsonFile), true);
        if (is_array($fromFile)) {
            $defaults = array_merge($defaults, $fromFile);
        }
    }

    // 2. Try DB (highest priority)
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($rows)) {
            foreach ($rows as $k => $v) {
                $defaults[$k] = in_array($k, $boolKeys) ? (bool)(int)$v : $v;
            }
        }
    } catch (Exception $e) {}

    return $defaults;
}


/**
 * Save a single setting to the system_settings DB table.
 * Also keeps admin_settings.json in sync for legacy code and fallbacks.
 */
function save_setting(PDO $pdo, string $key, $value): bool {
    $strVal = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
    $dbOk = false;
    try {
        $pdo->prepare(
            "INSERT INTO system_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        )->execute([$key, $strVal]);
        $dbOk = true;
    } catch (Exception $e) {}

    // Keep JSON file in sync
    $jsonFile = __DIR__ . '/admin_settings.json';
    $current = [];
    if (file_exists($jsonFile)) {
        $current = json_decode(file_get_contents($jsonFile), true) ?: [];
    }
    $current[$key] = is_bool($value) ? $value : (is_numeric($value) ? $value + 0 : $value);
    $fileOk = @file_put_contents($jsonFile, json_encode($current, JSON_PRETTY_PRINT)) !== false;

    return $dbOk || $fileOk;
}

/**
 * Save multiple settings at once and sync to JSON file.
 */
function save_all_settings(PDO $pdo, array $settings): bool {
    $ok = true;
    foreach ($settings as $key => $value) {
        if (!save_setting($pdo, $key, $value)) $ok = false;
    }
    return $ok;
}

/**
 * Returns all active data bundle suppliers and their configuration metadata.
 */
function get_all_data_suppliers(): array {
    return [
        'nitght' => [
            'key'      => 'nitght',
            'name'     => 'NITGHT',
            'fullName' => 'NITGHT (Aviator)',
            'class'    => 'NitghtApi',
            'file'     => __DIR__ . '/classes/NitghtApi.php',
            'setting'  => 'nitght_enabled',
            'networks' => ['MTN', 'Ishare', 'Telecel'],
            'color'    => '#8b5cf6',
            'icon'     => 'fa-plane'
        ],
        'mtnup2gb' => [
            'key'      => 'mtnup2gb',
            'name'     => 'MTNUP2U PORTAL',
            'fullName' => 'MTNUP2U PORTAL',
            'class'    => 'MtnUp2uPortalApi',
            'file'     => __DIR__ . '/classes/MtnUp2uPortalApi.php',
            'setting'  => 'mtnup2gb_enabled',
            'networks' => ['MTN', 'Ishare', 'Telecel'],
            'color'    => '#f59e0b',
            'icon'     => 'fa-bolt'
        ],
        'mtnup2u' => [
            'key'      => 'mtnup2u',
            'name'     => 'MTNUP2U',
            'fullName' => 'MTNUP2U',
            'class'    => 'MtnUp2uApi',
            'file'     => __DIR__ . '/classes/MtnUp2uApi.php',
            'setting'  => 'mtnup2u_enabled',
            'networks' => ['MTN', 'Ishare', 'Telecel'],
            'color'    => '#eab308',
            'icon'     => 'fa-sim-card'
        ],
        'supplier5' => [
            'key'      => 'supplier5',
            'name'     => 'Supplier 5',
            'fullName' => 'Supplier 5 (Jaybart)',
            'class'    => 'Supplier5Api',
            'file'     => __DIR__ . '/classes/Supplier5Api.php',
            'setting'  => 'supplier5_enabled',
            'networks' => ['MTN', 'Ishare', 'Telecel'],
            'color'    => '#10b981',
            'icon'     => 'fa-server'
        ],
        'supplier1' => [
            'key'      => 'supplier1',
            'name'     => 'Supplier 1',
            'fullName' => 'Supplier 1 (General Gateway)',
            'class'    => 'SupplierApi',
            'file'     => __DIR__ . '/classes/SupplierApi.php',
            'setting'  => 'supplier1_enabled',
            'networks' => ['MTN', 'Ishare', 'Telecel'],
            'color'    => '#4f46e5',
            'icon'     => 'fa-plug'
        ],
        'supplier2' => [
            'key'      => 'supplier2',
            'name'     => 'Supplier 2',
            'fullName' => 'Supplier 2 (BoatLink)',
            'class'    => 'Supplier2Api',
            'file'     => __DIR__ . '/classes/Supplier2Api.php',
            'setting'  => 'supplier2_enabled',
            'networks' => ['MTN'],
            'color'    => '#06b6d4',
            'icon'     => 'fa-ship'
        ],
        'supplier3' => [
            'key'      => 'supplier3',
            'name'     => 'Supplier 3',
            'fullName' => 'Supplier 3 (Heart)',
            'class'    => 'Supplier3Api',
            'file'     => __DIR__ . '/classes/Supplier3Api.php',
            'setting'  => 'supplier3_enabled',
            'networks' => ['MTN'],
            'color'    => '#ec4899',
            'icon'     => 'fa-heart'
        ],
        'backup' => [
            'key'      => 'backup',
            'name'     => 'Backup',
            'fullName' => 'Backup API',
            'class'    => 'BackupApi',
            'file'     => __DIR__ . '/classes/BackupApi.php',
            'setting'  => 'backup_enabled',
            'networks' => ['MTN', 'Ishare', 'Telecel'],
            'color'    => '#64748b',
            'icon'     => 'fa-life-ring'
        ],
    ];
}

/**
 * Get ordered array of supplier keys by admin priority.
 */
function get_supplier_priority_order($pdo = null): array {
    $defaults = ['nitght', 'mtnup2gb', 'mtnup2u', 'supplier5', 'supplier1', 'supplier2', 'supplier3', 'backup'];
    $allKnown = array_keys(get_all_data_suppliers());
    $savedOrder = null;

    try {
        if (!$pdo && function_exists('db_connect')) {
            $pdo = db_connect();
        }
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'supplier_priority_order' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if (!empty($val)) {
                $savedOrder = array_map('trim', explode(',', (string)$val));
            }
        }
    } catch (Exception $e) {}

    if (empty($savedOrder)) {
        $jsonFile = __DIR__ . '/admin_settings.json';
        if (file_exists($jsonFile)) {
            $data = json_decode(file_get_contents($jsonFile), true);
            if (!empty($data['supplier_priority_order'])) {
                $val = $data['supplier_priority_order'];
                $savedOrder = is_array($val) ? $val : array_map('trim', explode(',', (string)$val));
            }
        }
    }

    if (empty($savedOrder)) {
        return $defaults;
    }

    $result = [];
    foreach ($savedOrder as $k) {
        $k = strtolower(trim((string)$k));
        if (in_array($k, $allKnown) && !in_array($k, $result)) {
            $result[] = $k;
        }
    }
    foreach ($defaults as $k) {
        if (!in_array($k, $result)) {
            $result[] = $k;
        }
    }
    return $result;
}

/**
 * Saves the supplier priority order to DB and JSON fallback.
 */
function save_supplier_priority_order(PDO $pdo, array $order): bool {
    $allKnown = array_keys(get_all_data_suppliers());
    $cleaned = [];
    foreach ($order as $k) {
        $k = strtolower(trim((string)$k));
        if (in_array($k, $allKnown) && !in_array($k, $cleaned)) {
            $cleaned[] = $k;
        }
    }
    foreach ($allKnown as $k) {
        if (!in_array($k, $cleaned)) {
            $cleaned[] = $k;
        }
    }
    return save_setting($pdo, 'supplier_priority_order', implode(',', $cleaned));
}

/**
 * Resolves the appropriate supplier API instance for an order
 * based on admin priority and availability.
 */
function resolve_order_supplier(string $network, ?float $gbAmount = null, ?string $phone = null, ?PDO $pdo = null, array $excludeKeys = []): array {
    if (!$pdo && function_exists('db_connect')) {
        $pdo = db_connect();
    }

    $allSuppliers = get_all_data_suppliers();
    $priorityOrder = get_supplier_priority_order($pdo);

    // 1. Check dedicated phone routing override in supplier_routing
    if (!empty($phone) && $pdo) {
        try {
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($cleanPhone) === 12 && substr($cleanPhone, 0, 3) === '233') {
                $cleanPhone = '0' . substr($cleanPhone, 3);
            }
            $stmtRoute = $pdo->prepare("SELECT supplier FROM supplier_routing WHERE phone_number = ? LIMIT 1");
            $stmtRoute->execute([$cleanPhone]);
            $assignedId = (string)$stmtRoute->fetchColumn();

            $routeMap = [
                '1' => 'supplier1',
                '2' => 'supplier2',
                '3' => 'supplier3',
                '4' => 'supplier5',
                '5' => 'mtnup2u',
                '6' => 'mtnup2gb',
                '7' => 'nitght'
            ];

            if (!empty($assignedId) && isset($routeMap[$assignedId])) {
                $key = $routeMap[$assignedId];
                if (!in_array($key, $excludeKeys) && isset($allSuppliers[$key])) {
                    $supp = $allSuppliers[$key];
                    if (file_exists($supp['file'])) require_once $supp['file'];
                    $cls = $supp['class'];
                    if (class_exists($cls) && $cls::isNetworkEnabled($network, $gbAmount)) {
                        return ['api' => new $cls(), 'name' => $supp['name'], 'key' => $key];
                    }
                }
            }
        } catch (Exception $e) {}
    }

    // 2. Cascade through priority order
    foreach ($priorityOrder as $key) {
        if (in_array($key, $excludeKeys)) continue;
        if (!isset($allSuppliers[$key])) continue;
        $supp = $allSuppliers[$key];
        if (file_exists($supp['file'])) require_once $supp['file'];
        $cls = $supp['class'];
        if (!class_exists($cls)) continue;

        if ($cls::isNetworkEnabled($network, $gbAmount)) {
            return ['api' => new $cls(), 'name' => $supp['name'], 'key' => $key];
        }
    }

    return ['api' => null, 'name' => '', 'key' => ''];
}

/**
 * Reroutes an order dynamically according to the Automated Dispatch Board priority order.
 * Strictly respects the board sequence and skips the originating or previously failed supplier.
 *
 * @param PDO $pdo
 * @param int $orderId
 * @param string $network
 * @param string $phone
 * @param float $gbAmount
 * @param string $fromSupplierKey
 * @param string $fromSupplierName
 * @return array ['rerouted' => bool, 'status' => string, 'message' => string, 'supplier' => string, 'ref' => string]
 */
function reroute_order_by_priority(PDO $pdo, int $orderId, string $network, string $phone, float $gbAmount, string $fromSupplierKey = 'nitght', string $fromSupplierName = 'Night API'): array {
    $allSuppliers = get_all_data_suppliers();
    $priorityOrder = get_supplier_priority_order($pdo);

    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($cleanPhone) === 12 && substr($cleanPhone, 0, 3) === '233') {
        $cleanPhone = '0' . substr($cleanPhone, 3);
    }

    $exclude = [strtolower(trim($fromSupplierKey))];
    if (in_array('nitght', $exclude)) $exclude[] = 'aviator';
    if (in_array('supplier5', $exclude)) $exclude[] = 'jaybart';
    if (in_array('jaybart', $exclude)) $exclude[] = 'supplier5';

    // Prevent re-dispatching to any supplier that was already recorded in order message
    try {
        $stmtMsg = $pdo->prepare("SELECT message FROM bundle_sends WHERE id = ? LIMIT 1");
        $stmtMsg->execute([$orderId]);
        $currMsg = (string)$stmtMsg->fetchColumn();
        if (stripos($currMsg, 'Jaybart') !== false || stripos($currMsg, 'Supplier 5') !== false) {
            $exclude[] = 'supplier5';
            $exclude[] = 'jaybart';
        }
        if (stripos($currMsg, 'MTNUP2U PORTAL') !== false || stripos($currMsg, 'MTNUP2 GB') !== false || stripos($currMsg, 'MTNUP2GB') !== false) {
            $exclude[] = 'mtnup2gb';
        }
        if (stripos($currMsg, 'MTNUP2U') !== false && stripos($currMsg, 'MTNUP2U PORTAL') === false) {
            $exclude[] = 'mtnup2u';
        }
        if (stripos($currMsg, 'Supplier 1') !== false) {
            $exclude[] = 'supplier1';
        }
    } catch (Exception $e) {}

    foreach ($priorityOrder as $rankIdx => $sKey) {
        $key = strtolower(trim($sKey));
        if (in_array($key, $exclude)) continue;
        if (!isset($allSuppliers[$key])) continue;

        $supp = $allSuppliers[$key];
        if (file_exists($supp['file'])) require_once $supp['file'];
        $cls = $supp['class'];
        if (!class_exists($cls)) continue;

        if (!$cls::isNetworkEnabled($network, (float)$gbAmount)) {
            continue;
        }

        // Special handling if MTNUP2U PORTAL has specific rerouteFromNitght helper
        if ($key === 'mtnup2gb' && (method_exists('MtnUp2uPortalApi', 'rerouteFromNitght') || method_exists('MtnUp2GbApi', 'rerouteFromNitght')) && in_array('nitght', $exclude)) {
            require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
            $rerouteRes = MtnUp2uPortalApi::rerouteFromNitght($pdo, $orderId, $network, $cleanPhone, (float)$gbAmount);
            if (!empty($rerouteRes['success']) || !empty($rerouteRes['status'])) {
                $status = $rerouteRes['status'] ?? 'processing';
                $msg = $rerouteRes['message'] ?? ("Rerouted to MTNUP2U PORTAL (Priority #" . ($rankIdx + 1) . ")");
                return [
                    'rerouted' => true,
                    'status'   => $status,
                    'message'  => $msg,
                    'supplier' => 'MTNUP2U PORTAL',
                    'ref'      => ''
                ];
            }
        }

        // Generic dispatch to candidate supplier
        try {
            $apiObj = new $cls();
            $sendAmount = (float)$gbAmount;
            if ($key === 'supplier1' && strtolower($network) === 'ishare') {
                $sendAmount = $sendAmount * 1000;
            }

            // MTNUP2U beneficiary check
            if ($key === 'mtnup2u' && method_exists($apiObj, 'isBeneficiary')) {
                if (!$apiObj->isBeneficiary($cleanPhone, $network)) {
                    continue;
                }
            }

            $res = $apiObj->sendBundle($network, $cleanPhone, $sendAmount, $orderId);
            if (!empty($res['success'])) {
                $sData = is_array($res['data'] ?? null) ? $res['data'] : [];
                $ref = $res['reference'] 
                    ?? $res['order_id'] 
                    ?? $res['transaction_id'] 
                    ?? $res['id'] 
                    ?? $sData['reference'] 
                    ?? $sData['reference_id'] 
                    ?? $sData['order_id'] 
                    ?? $sData['transaction_id'] 
                    ?? $sData['trx_ref'] 
                    ?? $sData['id'] 
                    ?? ('APEX_' . $orderId);

                $mappedStatus = 'processing';
                if (method_exists($cls, 'mapStatus')) {
                    $mappedStatus = $cls::mapStatus($network, $res);
                }
                if ($key === 'mtnup2gb' || $key === 'mtnup2u') {
                    if (in_array($mappedStatus, ['processing', 'pending', 'accepted'])) {
                        $mappedStatus = 'waiting';
                    }
                }
                if ($key === 'supplier2' && $mappedStatus === 'processing') $mappedStatus = 'unverified';
                if ($key === 'supplier3' && $mappedStatus === 'processing') $mappedStatus = 'waiting';

                $rankNum = $rankIdx + 1;
                $msg = "{$fromSupplierName} validating. Re-routed to {$supp['name']} (Priority #{$rankNum}). Order ID: {$ref}";
                if ($pdo && $orderId) {
                    try {
                        $stmtUp = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'success', 'failed', 'refunded')");
                        $stmtUp->execute([$mappedStatus, $msg, $orderId]);
                    } catch (Exception $e) {}
                }

                return [
                    'rerouted' => true,
                    'status'   => $mappedStatus,
                    'message'  => $msg,
                    'supplier' => $supp['name'],
                    'ref'      => $ref
                ];
            }
        } catch (Exception $e) {
            error_log("[reroute_order_by_priority] Error dispatching to {$supp['name']}: " . $e->getMessage());
            continue;
        }
    }

    return ['rerouted' => false, 'status' => 'validating', 'message' => '', 'supplier' => '', 'ref' => ''];
}



function db_connect() {
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        try {
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE wallet_transactions ADD COLUMN balance_before DECIMAL(10,2) DEFAULT 0.00 AFTER description");
            $pdo->exec("ALTER TABLE wallet_transactions ADD COLUMN balance_after DECIMAL(10,2) DEFAULT 0.00 AFTER balance_before");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN afa_balance INT DEFAULT 0");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN afa_price DECIMAL(10,2) DEFAULT NULL");
        } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(50) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN api_key VARCHAR(64) UNIQUE DEFAULT NULL");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN wallet_balance DECIMAL(10,2) DEFAULT 0.00");
        } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                reference VARCHAR(100) UNIQUE,
                type ENUM('credit', 'debit') NOT NULL,
                description VARCHAR(255),
                balance_before DECIMAL(10,2) DEFAULT 0.00,
                balance_after DECIMAL(10,2) DEFAULT 0.00,
                status VARCHAR(50) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sms_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                sender_id VARCHAR(50) NOT NULL,
                recipient VARCHAR(20) NOT NULL,
                message TEXT NOT NULL,
                reference VARCHAR(100) UNIQUE,
                status VARCHAR(50) DEFAULT 'pending',
                status_text VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS jaybart_whitelist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone_number VARCHAR(20) UNIQUE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN channel VARCHAR(20) DEFAULT 'send'");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN amount DECIMAL(10,2) DEFAULT 0.00");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN refund_transferred TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN callback_url VARCHAR(255) DEFAULT NULL");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN client_reference VARCHAR(100) DEFAULT NULL");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN webhook_url VARCHAR(255) DEFAULT NULL");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE topups ADD COLUMN transaction_id VARCHAR(100) DEFAULT NULL");
        } catch (Exception $e) {}
        // Supplier routing
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_routing (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone_number VARCHAR(20) NOT NULL UNIQUE,
                supplier ENUM('1', '2', '3') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}

        // Digital Products
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS digital_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category ENUM('wassce', 'bece', 'netflix') NOT NULL,
                details TEXT NOT NULL,
                status ENUM('available', 'sold') DEFAULT 'available',
                buyer_id INT DEFAULT NULL,
                purchased_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE digital_products ADD COLUMN order_id INT DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE digital_products ADD COLUMN customer_phone VARCHAR(30) DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE digital_products ADD COLUMN customer_email VARCHAR(150) DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE digital_products ADD COLUMN channel VARCHAR(20) DEFAULT 'dashboard'"); } catch (Exception $e) {}

        // Role Upgrade Requests
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS role_upgrade_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                requested_role VARCHAR(50) NOT NULL,
                evidence_text TEXT,
                evidence_file VARCHAR(255),
                status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
// Reseller Stores (Store Control)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS reseller_stores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                business_name VARCHAR(255) NOT NULL,
                whatsapp_number VARCHAR(20) NOT NULL,
                email_address VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}

        // Reseller Pricing
        try {
            $pdo->exec("ALTER TABLE reseller_pricing ADD COLUMN is_active TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS reseller_pricing (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                product_type VARCHAR(50) NOT NULL,
                plan_id VARCHAR(100) NOT NULL,
                selling_price DECIMAL(10,2) NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_plan (user_id, product_type, plan_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}

        // Store Withdrawals
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN store_balance DECIMAL(10,2) DEFAULT 0.00");
        } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS store_withdrawals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                net_amount DECIMAL(10,2) NOT NULL,
                method VARCHAR(50) NOT NULL,
                recipient VARCHAR(255) DEFAULT NULL,
                status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
                reference VARCHAR(100) UNIQUE,
                error_msg TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            try { $pdo->exec("ALTER TABLE store_withdrawals ADD COLUMN transfer_code VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE store_withdrawals ADD COLUMN error_msg TEXT DEFAULT NULL"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE store_withdrawals ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Exception $e) {}
        } catch (Exception $e) {}

        // Store Orders (Public Storefront)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS store_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                bundle_send_id INT DEFAULT NULL,
                reference VARCHAR(100) UNIQUE NOT NULL,
                network VARCHAR(50) NOT NULL,
                recipient_phone VARCHAR(20) NOT NULL,
                gb_amount DECIMAL(10,2) NOT NULL,
                selling_price DECIMAL(10,2) NOT NULL,
                base_price DECIMAL(10,2) NOT NULL,
                profit DECIMAL(10,2) NOT NULL,
                customer_email VARCHAR(255) DEFAULT NULL,
                status ENUM('pending_payment', 'paid', 'completed', 'failed') DEFAULT 'pending_payment',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}

        // Push Subscriptions
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                endpoint TEXT NOT NULL,
                p256dh VARCHAR(255) NOT NULL,
                auth VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}

        // Pending Notifications
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pending_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                url VARCHAR(255) DEFAULT NULL,
                status ENUM('pending', 'delivered') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}

        // Auto-migrate network_pricing table
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS network_pricing (
                id INT AUTO_INCREMENT PRIMARY KEY,
                network VARCHAR(50) NOT NULL,
                gb_amount DECIMAL(10,2) NOT NULL,
                price_ghs DECIMAL(10,2) NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_network_gb (network, gb_amount)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS mtn_registered_numbers (
                phone VARCHAR(20) PRIMARY KEY,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS mtn_number_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                raw_text TEXT,
                file_path VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
        } catch (Exception $e) {}

        try { $pdo->exec("ALTER TABLE network_pricing ADD COLUMN package_label VARCHAR(100) DEFAULT '' AFTER network"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE network_pricing ADD COLUMN validity VARCHAR(50) DEFAULT '30 Days' AFTER gb_amount"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE network_pricing ADD COLUMN base_cost DECIMAL(10,2) DEFAULT 0.00 AFTER price_ghs"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE network_pricing ADD COLUMN price_client DECIMAL(10,2) DEFAULT 0.00 AFTER base_cost"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE network_pricing ADD COLUMN price_dealer DECIMAL(10,2) DEFAULT 0.00 AFTER price_client"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE network_pricing ADD COLUMN price_vip DECIMAL(10,2) DEFAULT 0.00 AFTER price_dealer"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE network_pricing ADD COLUMN price_elite DECIMAL(10,2) DEFAULT 0.00 AFTER price_vip"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE network_pricing ADD COLUMN price_admin DECIMAL(10,2) DEFAULT 0.00 AFTER price_elite"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE network_pricing ADD COLUMN sort_order INT DEFAULT 10 AFTER price_admin"); } catch (Exception $e) {}

        // Reseller Storefront schema
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_stores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                business_name VARCHAR(100) NOT NULL,
                slug VARCHAR(50) NOT NULL UNIQUE,
                whatsapp VARCHAR(20) NOT NULL,
                email VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS store_pricing (
                id INT AUTO_INCREMENT PRIMARY KEY,
                store_id INT NOT NULL,
                product_type VARCHAR(50) NOT NULL,
                package_id VARCHAR(50) NOT NULL,
                cost_price DECIMAL(10,2) DEFAULT 0.00,
                retail_price DECIMAL(10,2) DEFAULT 0.00,
                is_active TINYINT(1) DEFAULT 1,
                FOREIGN KEY (store_id) REFERENCES user_stores(id) ON DELETE CASCADE,
                UNIQUE KEY unique_store_product (store_id, product_type, package_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS registration_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(255) NOT NULL,
                code VARCHAR(50) UNIQUE NOT NULL,
                is_used TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS user_whatsapp_bots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                bot_name VARCHAR(100) DEFAULT 'Alexa Covert',
                phone_number VARCHAR(30) NOT NULL,
                pairing_code VARCHAR(20) DEFAULT NULL,
                status ENUM('pending', 'connected', 'disconnected', 'expired') DEFAULT 'pending',
                fee_paid DECIMAL(10,2) DEFAULT 2.00,
                transaction_ref VARCHAR(100) DEFAULT NULL,
                autoview_status TINYINT(1) DEFAULT 1,
                autolike_status TINYINT(1) DEFAULT 1,
                antidelete TINYINT(1) DEFAULT 1,
                savedviews TINYINT(1) DEFAULT 1,
                autoreply TINYINT(1) DEFAULT 1,
                downloader_enabled TINYINT(1) DEFAULT 1,
                music_enabled TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                connected_at DATETIME DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_user (user_id),
                KEY idx_phone (phone_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}

        try { $pdo->exec("ALTER TABLE registration_codes MODIFY COLUMN phone VARCHAR(255) NOT NULL"); } catch (Exception $e) {}

        try { $pdo->exec("ALTER TABLE users ADD COLUMN profit_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN store_id INT DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN profit_earned DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN customer_name VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
        
        try { $pdo->exec("ALTER TABLE topups ADD COLUMN store_id INT DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE topups ADD COLUMN profit_earned DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE topups ADD COLUMN customer_name VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}

        // Pre-populate if empty
        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM network_pricing")->fetchColumn();
            if ($count === 0) {
                $stmt = $pdo->prepare("INSERT INTO network_pricing (network, package_label, gb_amount, validity, price_ghs, base_cost, price_client, price_dealer, price_vip, price_elite, price_admin, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                
                // MTN Defaults
                $stmt->execute(['MTN', '1GB',  1.0,  '30 Days', 4.00,  3.90, 4.00,  3.90, 3.90, 3.90, 3.90, 10]);
                $stmt->execute(['MTN', '2GB',  2.0,  '30 Days', 8.00,  7.80, 8.00,  7.80, 7.80, 7.80, 7.80, 10]);
                $stmt->execute(['MTN', '3GB',  3.0,  '30 Days', 12.00, 11.70, 12.00, 11.70, 11.70, 11.70, 11.70, 10]);
                $stmt->execute(['MTN', '5GB',  5.0,  '30 Days', 20.00, 19.50, 20.00, 19.50, 19.50, 19.50, 19.50, 10]);
                $stmt->execute(['MTN', '10GB', 10.0, '30 Days', 39.50, 39.00, 39.50, 39.00, 39.00, 39.00, 39.00, 10]);
                $stmt->execute(['MTN', '15GB', 15.0, '30 Days', 59.25, 58.50, 59.25, 58.50, 58.50, 58.50, 58.50, 10]);
                $stmt->execute(['MTN', '20GB', 20.0, '30 Days', 79.00, 76.00, 79.00, 76.00, 76.00, 76.00, 76.00, 10]);

                // Telecel Defaults
                $stmt->execute(['Telecel', '1GB',  1.0,  '30 Days', 3.50,  3.50, 3.50,  3.50, 3.50, 3.50, 3.50, 10]);
                $stmt->execute(['Telecel', '2GB',  2.0,  '30 Days', 7.00,  7.00, 7.00,  7.00, 7.00, 7.00, 7.00, 10]);
                $stmt->execute(['Telecel', '3GB',  3.0,  '30 Days', 10.50, 10.50, 10.50, 10.50, 10.50, 10.50, 10.50, 10]);
                $stmt->execute(['Telecel', '5GB',  5.0,  '30 Days', 17.50, 17.50, 17.50, 17.50, 17.50, 17.50, 17.50, 10]);
                $stmt->execute(['Telecel', '10GB', 10.0, '30 Days', 34.00, 34.00, 34.00, 34.00, 34.00, 34.00, 34.00, 10]);
                $stmt->execute(['Telecel', '15GB', 15.0, '30 Days', 51.00, 51.00, 51.00, 51.00, 51.00, 51.00, 51.00, 10]);
                $stmt->execute(['Telecel', '20GB', 20.0, '30 Days', 68.00, 68.00, 68.00, 68.00, 68.00, 68.00, 68.00, 10]);

                // Ishare Defaults
                $stmt->execute(['Ishare', '1GB',  1.0,  '30 Days', 3.70,  3.70, 3.70,  3.70, 3.70, 3.70, 3.70, 10]);
                $stmt->execute(['Ishare', '2GB',  2.0,  '30 Days', 7.40,  7.40, 7.40,  7.40, 7.40, 7.40, 7.40, 10]);
                $stmt->execute(['Ishare', '3GB',  3.0,  '30 Days', 11.10, 11.10, 11.10, 11.10, 11.10, 11.10, 11.10, 10]);
                $stmt->execute(['Ishare', '5GB',  5.0,  '30 Days', 18.50, 18.50, 18.50, 18.50, 18.50, 18.50, 18.50, 10]);
                $stmt->execute(['Ishare', '10GB', 10.0, '30 Days', 37.00, 37.00, 37.00, 37.00, 37.00, 37.00, 37.00, 10]);
                $stmt->execute(['Ishare', '15GB', 15.0, '30 Days', 55.50, 55.50, 55.50, 55.50, 55.50, 55.50, 55.50, 10]);
                $stmt->execute(['Ishare', '20GB', 20.0, '30 Days', 74.00, 74.00, 74.00, 74.00, 74.00, 74.00, 74.00, 10]);
            }
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN free_mode TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN debt DECIMAL(10,2) DEFAULT 0.00");
        } catch (Exception $e) {}

        // Auto-seed and normalize MTN Unverified packages
        try {
            $pdo->exec("UPDATE network_pricing SET network = 'MTN Unverified' WHERE network = 'MTN UNVERIFIED'");
            $pdo->exec("UPDATE bundle_sends SET network = 'MTN Unverified' WHERE network = 'MTN UNVERIFIED'");

            $stmtCheckUnv = $pdo->query("SELECT COUNT(*) FROM network_pricing WHERE UPPER(network) = 'MTN UNVERIFIED'");
            if ((int)$stmtCheckUnv->fetchColumn() === 0) {
                $stmtIns = $pdo->prepare("INSERT INTO network_pricing (network, package_label, gb_amount, validity, price_ghs, base_cost, price_client, price_dealer, price_agent, price_vip, price_elite, price_super_agent, price_admin, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $unvPlans = [
                    ['MTN Unverified', '1GB',   1.0, '30 Days', 5.00,  4.50, 5.00,  4.80, 4.80, 4.70, 4.60, 4.55, 4.50, 1],
                    ['MTN Unverified', '2GB',   2.0, '30 Days', 10.00, 9.00, 10.00, 9.60, 9.60, 9.40, 9.20, 9.10, 9.00, 2],
                    ['MTN Unverified', '3GB',   3.0, '30 Days', 15.00, 13.50, 15.00, 14.40, 14.40, 14.10, 13.80, 13.65, 13.50, 3],
                    ['MTN Unverified', '4GB',   4.0, '30 Days', 20.00, 18.00, 20.00, 19.20, 19.20, 18.80, 18.40, 18.20, 18.00, 4],
                    ['MTN Unverified', '5GB',   5.0, '30 Days', 25.00, 22.50, 25.00, 24.00, 24.00, 23.50, 23.00, 22.75, 22.50, 5],
                    ['MTN Unverified', '6GB',   6.0, '30 Days', 30.00, 27.00, 30.00, 28.80, 28.80, 28.20, 27.60, 27.30, 27.00, 6],
                    ['MTN Unverified', '7GB',   7.0, '30 Days', 35.00, 31.50, 35.00, 33.60, 33.60, 32.90, 32.20, 31.85, 31.50, 7],
                    ['MTN Unverified', '8GB',   8.0, '30 Days', 40.00, 36.00, 40.00, 38.40, 38.40, 37.60, 36.80, 36.40, 36.00, 8],
                    ['MTN Unverified', '10GB', 10.0, '30 Days', 50.00, 45.00, 50.00, 48.00, 48.00, 47.00, 46.00, 45.50, 45.00, 9],
                    ['MTN Unverified', '15GB', 15.0, '30 Days', 75.00, 67.50, 75.00, 72.00, 72.00, 70.50, 69.00, 68.25, 67.50, 10],
                    ['MTN Unverified', '20GB', 20.0, '30 Days', 100.00, 90.00, 100.00, 96.00, 96.00, 94.00, 92.00, 91.00, 90.00, 11],
                    ['MTN Unverified', '25GB', 25.0, '30 Days', 125.00, 112.50, 125.00, 120.00, 120.00, 117.50, 115.00, 113.75, 112.50, 12],
                    ['MTN Unverified', '30GB', 30.0, '30 Days', 150.00, 135.00, 150.00, 144.00, 144.00, 141.00, 138.00, 136.50, 135.00, 13],
                    ['MTN Unverified', '40GB', 40.0, '30 Days', 200.00, 180.00, 200.00, 192.00, 192.00, 188.00, 184.00, 182.00, 180.00, 14],
                    ['MTN Unverified', '50GB', 50.0, '30 Days', 250.00, 225.00, 250.00, 240.00, 240.00, 235.00, 230.00, 227.50, 225.00, 15]
                ];
                foreach ($unvPlans as $plan) {
                    $stmtIns->execute($plan);
                }
            }
        } catch (Exception $e) {}
    } catch (PDOException $e) {
        if (php_sapi_name() === 'cli' || defined('IGNORE_DB_CONNECT_EXIT')) {
            throw $e;
        }
        http_response_code(500);
        echo 'Database connection error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
    }

    return $pdo;
}

function asset_url($path) {
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

function normalizeAndValidatePhone($phone, $network) {
    $phone = preg_replace('/\D/', '', $phone);
    if (strpos($phone, '233') === 0 && strlen($phone) === 12) {
        $phone = '0' . substr($phone, 3);
    } elseif (strlen($phone) === 9) {
        $phone = '0' . $phone;
    }
    if (strlen($phone) !== 10) return false;
    
    $prefix = substr($phone, 0, 3);
    if (strpos($network, 'MTN') !== false) {
        $validPrefixes = ['024', '025', '053', '054', '055', '059'];
        if (!in_array($prefix, $validPrefixes)) return false;
    } elseif (strpos($network, 'Telecel') !== false) {
        $validPrefixes = ['020', '050'];
        if (!in_array($prefix, $validPrefixes)) return false;
    } elseif (strpos($network, 'Ishare') !== false) {
        $validPrefixes = ['027', '057', '026', '056'];
        if (!in_array($prefix, $validPrefixes)) return false;
    }
    return $phone;
}

function getNetworkBalance(PDO $pdo, int $userId, string $network): float {
    $networkMap = [
        'MTN'     => ['topup_networks' => ['MTN Group Share', 'MTN'],                     'send_network' => 'MTN'],
        'Telecel' => ['topup_networks' => ['Telecel Group Share', 'Telecel', 'Vodafone'],  'send_network' => 'Telecel'],
        'Ishare'  => ['topup_networks' => ['Ishare', 'AT', 'AirtelTigo'],                 'send_network' => 'Ishare'],
    ];

    $matchedKey = null;
    foreach ($networkMap as $key => $map) {
        if (stripos($network, $key) !== false) {
            $matchedKey = $key;
            break;
        }
        foreach ($map['topup_networks'] as $tn) {
            if (stripos($network, $tn) !== false) {
                $matchedKey = $key;
                break 2;
            }
        }
    }

    if (!$matchedKey) return 0.0;

    $topupNetworks = $networkMap[$matchedKey]['topup_networks'];
    $sendNetwork   = $networkMap[$matchedKey]['send_network'];

    // Query approved topups
    $placeholders = implode(',', array_fill(0, count($topupNetworks), '?'));
    $stmt = $pdo->prepare("SELECT phone, amount FROM topups WHERE user_id = ? AND network IN ($placeholders) AND status = 'approved'");
    $stmt->execute(array_merge([$userId], $topupNetworks));
    $topups = $stmt->fetchAll();

    $total = 0.0;
    foreach ($topups as $t) {
        $gb = 0.0;
        // 1. Try to parse from phone field first (e.g., "10 GB - GHS 50")
        if (preg_match('/^(\d+(\.\d+)?)/', $t['phone'], $m)) {
            $gb = (float)$m[1];
        } else {
            // 2. Try lookup in network_pricing
            $price = (float)$t['amount'];
            if ($price > 0) {
                try {
                    $stmtPrice = $pdo->prepare("SELECT gb_amount FROM network_pricing WHERE network = ? AND price_ghs = ? AND is_active = 1 LIMIT 1");
                    $stmtPrice->execute([$networkMap[$matchedKey]['topup_networks'][0], $price]);
                    $pkgGb = $stmtPrice->fetchColumn();
                    if ($pkgGb !== false) {
                        $gb = (float)$pkgGb;
                    } else {
                        // Fallback using pricing rates
                        if ($matchedKey === 'Ishare') {
                            $gb = $price / 3.70;
                        } elseif ($matchedKey === 'Telecel') {
                            $gb = ($price <= 35.00) ? ($price / 3.50) : ($price / 3.40);
                        } elseif ($matchedKey === 'MTN') {
                            $gb = ($price < 39.50) ? ($price / 4.00) : ($price / 3.95);
                        }
                    }
                } catch (Exception $e) {
                    // Fallback directly
                    if ($matchedKey === 'Ishare') {
                        $gb = $price / 3.70;
                    } elseif ($matchedKey === 'Telecel') {
                        $gb = ($price <= 35.00) ? ($price / 3.50) : ($price / 3.40);
                    } elseif ($matchedKey === 'MTN') {
                        $gb = ($price < 39.50) ? ($price / 4.00) : ($price / 3.95);
                    }
                }
            }
        }
        $total += $gb;
    }

    // Query sent bundles (excluding store purchases which are paid via wallet balance)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(gb_amount), 0) FROM bundle_sends WHERE user_id = ? AND network = ? AND status IN ('pending', 'approved') AND (channel != 'store' OR channel IS NULL)");
    $stmt->execute([$userId, $sendNetwork]);
    $sent = (float)$stmt->fetchColumn();

    return max(0.0, $total - $sent);
}

function isElevatedRole(string $role): bool {
    return in_array(strtolower($role), ['admin', 'agent', 'reseller', 'elite', 'dealers', 'vip', 'super agent', 'super_agent']);
}

function getUserWalletBalance(PDO $pdo, int $userId): float {
    $stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $bal = $stmt->fetchColumn();
    return $bal !== false ? (float)$bal : 0.0;
}

function logTransactionBefore($pdo, $userId, $type = 'wallet') {
    if (strtolower($type) === 'wallet') {
        $stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return (float)($stmt->fetchColumn() ?: 0.00);
    } else {
        $stmt = $pdo->prepare("SELECT afa_balance FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}

function logTransactionAfter($pdo, $userId, $type = 'wallet', $balBefore = 0.00, $action = 'deduct', $amount = 0.00, $description = '') {
    $balAfter = 0.00;
    if (strtolower($type) === 'wallet') {
        $stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $balAfter = (float)($stmt->fetchColumn() ?: 0.00);

        $ref = 'TX-' . mt_rand(10000, 99999);
        $transType = ($action === 'deduct' || $action === 'edit' && $balBefore > $balAfter) ? 'debit' : 'credit';
        $stmtTx = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'success')");
        $stmtTx->execute([$userId, $amount, $ref, $transType, $description, $balBefore, $balAfter]);
    } else {
        $stmt = $pdo->prepare("SELECT afa_balance FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $balAfter = (int)($stmt->fetchColumn() ?: 0);
        
        $ref = 'AFA-' . mt_rand(10000, 99999);
        $transType = ($action === 'deduct' || $action === 'edit' && $balBefore > $balAfter) ? 'debit' : 'credit';
        $stmtTx = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'success')");
        $stmtTx->execute([$userId, $amount, $ref, $transType, "[AFA Slot] " . $description, $balBefore, $balAfter]);
    }
}

function addWalletTransaction(PDO $pdo, int $userId, float $amount, string $type, string $reference, string $description): bool {
    try {
        $stmtB = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $stmtB->execute([$userId]);
        $balBefore = (float)$stmtB->fetchColumn();

        $amount = abs($amount); // Ensure amount is positive
        if ($type === 'debit') {
            $balAfter = max(0, $balBefore - $amount);
        } else {
            $balAfter = $balBefore + $amount;
        }

        $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$balAfter, $userId]);
        
        $stmtTx = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'success')");
        $stmtTx->execute([$userId, $amount, $reference, $type, $description, $balBefore, $balAfter]);
        
        // SMS Notification
        try {
            $stmtPhone = $pdo->prepare("SELECT phone, username FROM users WHERE id = ?");
            $stmtPhone->execute([$userId]);
            $u = $stmtPhone->fetch();
            if ($u && !empty($u['phone'])) {
                $actionStr = $type === 'credit' ? 'credited with' : 'debited for';
                $msg = "Hello " . $u['username'] . ", your Apex Prime wallet has been " . $actionStr . " GHS " . number_format($amount, 2) . ". Ref: " . $reference . ". New Balance: GHS " . number_format($balAfter, 2) . ".";
                if (function_exists('sendSms')) {
                    sendSms($u['phone'], $msg);
                }
            }
        } catch (Exception $e) {}

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Send refund notification SMS to the recipient phone using sender ID 'BUS-REG'
 */
function sendRefundSmsToRecipient(string $recipientPhone, ?string $packagePlan = null): bool {
    // Disabled: Do not send refund SMS to recipient numbers
    return true;
}

/**
 * Send a refund notification SMS to the user's registered phone number.
 *
 * @param PDO    $pdo          Database connection
 * @param int    $userId       The user's ID
 * @param float  $amount       The refunded amount in GHS
 * @param string $orderId      The order reference / ID
 * @param string $network      Network name (e.g. MTN, Telecel)
 * @param float  $gb           Data bundle size in GB
 * @param string $recipientPhone The original recipient phone number
 */
if (!function_exists('sendRefundSmsToUser')) {
    function sendRefundSmsToUser(PDO $pdo, int $userId, float $amount, $orderId, string $network = '', float $gb = 0, string $recipientPhone = ''): void {
        try {
            $settings = load_all_settings($pdo);

            // Master Refund SMS Switch
            if (!($settings['refund_sms_enabled'] ?? true)) {
                return;
            }

            $stmt = $pdo->prepare("SELECT phone, username, role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$u || empty($u['phone'])) return;

            // Role-based control: Elite, Dealers, Super Agent, Other
            $uRole = strtolower(trim($u['role'] ?? 'client'));
            $uRole = str_replace(' ', '_', $uRole);

            if ($uRole === 'elite') {
                if (!($settings['refund_sms_elite'] ?? true)) return;
            } elseif ($uRole === 'dealer' || $uRole === 'dealers') {
                if (!($settings['refund_sms_dealers'] ?? true)) return;
            } elseif ($uRole === 'super_agent' || $uRole === 'super') {
                if (!($settings['refund_sms_super_agent'] ?? true)) return;
            } else {
                if (!($settings['refund_sms_other'] ?? true)) return;
            }

            $cleanPhone = preg_replace('/\D/', '', $u['phone']);
            if (strlen($cleanPhone) === 12 && substr($cleanPhone, 0, 3) === '233') {
                $cleanPhone = '0' . substr($cleanPhone, 3);
            } elseif (strlen($cleanPhone) === 9) {
                $cleanPhone = '0' . $cleanPhone;
            }
            if (strlen($cleanPhone) !== 10) return;

            $name     = $u['username'] ?? 'Customer';
            $gbStr    = $gb > 0 ? number_format($gb, 0) . 'GB ' : '';
            $netStr   = !empty($network) ? trim($network) . ' ' : '';
            $recipStr = !empty($recipientPhone) ? " (Recipient: {$recipientPhone})" : '';
            $amtStr   = number_format($amount, 2);

            $template = !empty($settings['refund_sms_template'])
                ? $settings['refund_sms_template']
                : "Dear {username}, your {gb}{network}data order #{order_id}{recipient} could not be completed and GHS {amount} has been refunded to your Apex Prime wallet. The recipient number has been submitted for network approval. Kindly try again after 1 week. We apologise for the inconvenience. - Apex Prime Support";

            $msg = str_replace(
                ['{username}', '{amount}', '{order_id}', '{network}', '{gb}', '{recipient}'],
                [$name, $amtStr, $orderId, $netStr, $gbStr, $recipStr],
                $template
            );

            require_once __DIR__ . '/classes/SmsApi.php';
            $smsApi = new SmsApi();
            $smsApi->setSenderId('BUS-REG');
            $smsApi->sendSms($cleanPhone, $msg, null, 'BUS-REG');
        } catch (Exception $e) {
            error_log("sendRefundSmsToUser error: " . $e->getMessage());
        }
    }
}


function addFreeModeTransaction(PDO $pdo, int $userId, float $amount, string $reference, string $description): bool {
    try {
        $stmtB = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $stmtB->execute([$userId]);
        $balBefore = (float)$stmtB->fetchColumn();

        $amount = abs($amount); // Ensure amount is positive

        // 1. Update debt instead of wallet balance
        $pdo->prepare("UPDATE users SET debt = debt + ? WHERE id = ?")->execute([$amount, $userId]);
        
        // 2. Log in wallet_transactions as debit but keeping wallet balance unchanged
        $stmtTx = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, 'debit', ?, ?, ?, 'success')");
        $stmtTx->execute([$userId, $amount, $reference, $description . " [FREE MODE DEBT]", $balBefore, $balBefore]);
        return true;
    } catch (Exception $e) {
        error_log("addFreeModeTransaction error: " . $e->getMessage());
        return false;
    }
}

function getGbPriceGhs(PDO $pdo, string $network, float $amount, string $role = 'client'): float {
    try {
        // Normalize role name to database column
        $roleCol = 'price_client';
        $r = strtolower(trim($role));
        if ($r === 'admin') $roleCol = 'price_admin';
        elseif ($r === 'super agent' || $r === 'super_agent' || $r === 'super') $roleCol = 'price_super_agent';
        elseif ($r === 'elite') $roleCol = 'price_elite';
        elseif ($r === 'vip') $roleCol = 'price_vip';
        elseif ($r === 'agent') $roleCol = 'price_agent';
        elseif ($r === 'dealer' || $r === 'dealers' || $r === 'reseller') $roleCol = 'price_dealer';
        
        $stmt = $pdo->prepare("SELECT $roleCol, price_dealer, price_client, price_ghs FROM network_pricing WHERE UPPER(network) = UPPER(?) AND gb_amount = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$network, $amount]);
        $row = $stmt->fetch();
        if ($row !== false) {
            $rolePrice = (float)($row[$roleCol] ?? 0);
            if ($rolePrice > 0) return $rolePrice;
            if (!empty($row['price_dealer']) && (float)$row['price_dealer'] > 0) return (float)$row['price_dealer'];
            if (!empty($row['price_client']) && (float)$row['price_client'] > 0) return (float)$row['price_client'];
            return (float)$row['price_ghs'];
        }
    } catch (Exception $e) {}
    
    // Fallbacks
    if (strtoupper($network) === 'MTN') return $amount * 3.90;
    if (strtoupper($network) === 'TELECEL') return $amount * 3.50;
    return $amount * 3.70; // Ishare
}

/**
 * Get digital product (WASSCE, BECE, NETFLIX, etc.) price for a specific user role.
 */
function getDigitalRolePrice(PDO $pdo, string $category, string $role = 'client', float $defaultBase = 15.50): float {
    $cat = strtolower(trim($category));
    $roleClean = strtolower(trim($role));
    $roleSafe = str_replace(' ', '_', $roleClean);
    
    // Normalize role column name for network_pricing
    $roleCol = 'price_client';
    if ($roleClean === 'admin') $roleCol = 'price_admin';
    elseif (in_array($roleClean, ['super agent', 'super_agent', 'super'])) $roleCol = 'price_super_agent';
    elseif ($roleClean === 'elite') $roleCol = 'price_elite';
    elseif ($roleClean === 'vip') $roleCol = 'price_vip';
    elseif ($roleClean === 'agent') $roleCol = 'price_agent';
    elseif (in_array($roleClean, ['dealer', 'dealers', 'reseller'])) $roleCol = 'price_dealer';
    
    $settings = load_all_settings($pdo);
    
    // Check role price in settings (e.g. wassce_price_dealers, wassce_price_dealer, wassce_price_agent, etc.)
    $roleKeys = [$roleSafe];
    if ($roleSafe === 'dealer') { $roleKeys[] = 'dealers'; }
    elseif ($roleSafe === 'dealers') { $roleKeys[] = 'dealer'; }
    elseif ($roleSafe === 'super_agent' || $roleSafe === 'super') { $roleKeys[] = 'super_agent'; $roleKeys[] = 'super agent'; }
    elseif ($roleSafe === 'reseller') { $roleKeys[] = 'dealer'; $roleKeys[] = 'dealers'; }
    
    foreach ($roleKeys as $rk) {
        if (!empty($settings[$cat . '_price_' . $rk]) && (float)$settings[$cat . '_price_' . $rk] > 0) {
            return (float)$settings[$cat . '_price_' . $rk];
        }
    }
    
    // Check network_pricing table
    try {
        $stmtNP = $pdo->prepare("SELECT $roleCol, price_dealer, price_client, price_ghs FROM network_pricing WHERE UPPER(network) = UPPER(?) AND is_active = 1 LIMIT 1");
        $stmtNP->execute([$cat]);
        $npRow = $stmtNP->fetch(PDO::FETCH_ASSOC);
        if ($npRow) {
            $npPrice = (float)($npRow[$roleCol] ?? 0);
            if ($npPrice <= 0) $npPrice = (float)($npRow['price_dealer'] ?? $npRow['price_client'] ?? $npRow['price_ghs'] ?? 0);
            if ($npPrice > 0) return $npPrice;
        }
    } catch (Exception $e) {}
    
    // General price fallback in settings
    if (!empty($settings[$cat . '_price']) && (float)$settings[$cat . '_price'] > 0) {
        return (float)$settings[$cat . '_price'];
    }
    if (!empty($settings[$cat . '_base_cost']) && (float)$settings[$cat . '_base_cost'] > 0) {
        return (float)$settings[$cat . '_base_cost'];
    }
    if (!empty($settings[$cat . '_base_price']) && (float)$settings[$cat . '_base_price'] > 0) {
        return (float)$settings[$cat . '_base_price'];
    }
    
    if ($cat === 'netflix') return 50.00;
    return $defaultBase;
}

define('PAYSTACK_PUBLIC_KEY', getenv('PAYSTACK_PUBLIC_KEY') ?: ($_ENV['PAYSTACK_PUBLIC_KEY'] ?? 'pk_live_d0e487f59fb6d525dfa23c864198bb97883b0daa'));
define('PAYSTACK_SECRET_KEY', getenv('PAYSTACK_SECRET_KEY') ?: ($_ENV['PAYSTACK_SECRET_KEY'] ?? 'sk_live_7a36c377d9bde68f08af41d8ab8987c6e0d2dcd7'));

define('MAPLERAD_SECRET_KEY', getenv('MAPLERAD_SECRET_KEY') ?: 'mpr_sandbox_sk_e524de31-f217-40b3-b2de-d24a05d7bf7f');
define('MAPLERAD_PUBLIC_KEY', getenv('MAPLERAD_PUBLIC_KEY') ?: 'mpr_sandbox_pk_7995c0418fe17d765aa6cc02847305b6');


// ── Store Profit Processing ──────────────────────────────────────────
function process_delayed_profits(PDO $pdo) {
    try {
        // Fast checks
        $hasSends = $pdo->query("SELECT id FROM bundle_sends WHERE store_id > 0 AND is_profit_credited = 0 AND status IN ('successful', 'sucessfully', 'completed') LIMIT 1")->fetchColumn();
        $hasTopups = $pdo->query("SELECT id FROM topups WHERE store_id > 0 AND is_profit_credited = 0 AND status IN ('successful', 'sucessfully', 'completed', 'approved') LIMIT 1")->fetchColumn();

        if ($hasSends) {
            $sends = $pdo->query("SELECT id, user_id, profit_earned FROM bundle_sends WHERE store_id > 0 AND is_profit_credited = 0 AND status IN ('successful', 'sucessfully', 'completed')")->fetchAll();
            foreach ($sends as $s) {
                if ((float)$s['profit_earned'] > 0) {
                    $pdo->prepare("UPDATE users SET profit_balance = profit_balance + ? WHERE id = ?")->execute([$s['profit_earned'], $s['user_id']]);
                }
                $pdo->prepare("UPDATE bundle_sends SET is_profit_credited = 1 WHERE id = ?")->execute([$s['id']]);
            }
        }
        
        if ($hasTopups) {
            $topups = $pdo->query("SELECT id, user_id, profit_earned FROM topups WHERE store_id > 0 AND is_profit_credited = 0 AND status IN ('successful', 'sucessfully', 'completed', 'approved')")->fetchAll();
            foreach ($topups as $t) {
                if ((float)$t['profit_earned'] > 0) {
                    $pdo->prepare("UPDATE users SET profit_balance = profit_balance + ? WHERE id = ?")->execute([$t['profit_earned'], $t['user_id']]);
                }
                $pdo->prepare("UPDATE topups SET is_profit_credited = 1 WHERE id = ?")->execute([$t['id']]);
            }
        }
    } catch (Exception $e) {}
}

// Auto-run profit processing and run missing alterations once
try {
    $pdoGlobal = db_connect();
    
    try {
        $pdoGlobal->exec("ALTER TABLE bundle_sends ADD COLUMN is_profit_credited TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {}
    try {
        // Prevents auto-sync from overwriting statuses that admin has manually set
        $pdoGlobal->exec("ALTER TABLE bundle_sends ADD COLUMN admin_override TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $pdoGlobal->exec("ALTER TABLE topups ADD COLUMN is_profit_credited TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {}
    
    process_delayed_profits($pdoGlobal);
} catch (Exception $e) {}

/**
 * Automatically verify and add recipient phone number to website local whitelist & supplier whitelist APIs
 */
function autoVerifyAndWhitelistNumber(PDO $pdo, string $phone, string $network = 'MTN'): bool {
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($cleanPhone) === 12 && substr($cleanPhone, 0, 3) === '233') {
        $cleanPhone = '0' . substr($cleanPhone, 3);
    } elseif (strlen($cleanPhone) === 9) {
        $cleanPhone = '0' . $cleanPhone;
    }
    
    if (strlen($cleanPhone) !== 10) {
        return false;
    }

    try {
        // 1. Add to local mtn_verified_numbers table
        $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$cleanPhone]);

        // 2. Add to local jaybart_whitelist table if exists
        try {
            $pdo->prepare("INSERT IGNORE INTO jaybart_whitelist (phone_number) VALUES (?)")->execute([$cleanPhone]);
        } catch (Exception $exDb) {}

        // 3. Register on Supplier Whitelist APIs (MTNUP2U beneficiary verify)
        try {
            require_once __DIR__ . '/classes/MtnUp2uApi.php';
            if (MtnUp2uApi::isEnabled()) {
                $mtnUp2uApi = new MtnUp2uApi();
                $mtnUp2uApi->verifyNumber($cleanPhone, 'YELLO');
            }
        } catch (Exception $exMtn) {}

        // 4. Register on Supplier Whitelist APIs (MTNUP2U PORTAL beneficiary verify)
        try {
            require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
            if (MtnUp2uPortalApi::isEnabled()) {
                $mtnUp2GbApi = new MtnUp2uPortalApi();
                $mtnUp2GbApi->verifyNumber($cleanPhone, 'YELLO');
            }
        } catch (Exception $exGb) {}

        // 5. Pre-check with Night API (Aviator Delivery MTN pre-check)
        try {
            require_once __DIR__ . '/classes/NitghtApi.php';
            if (NitghtApi::isEnabled() && NitghtApi::isNetworkEnabled('MTN')) {
                $nitghtApi = new NitghtApi();
                $nitghtApi->verifyNumber($cleanPhone, 'MTN');
            }
        } catch (Exception $exNight) {}

        return true;
    } catch (Exception $e) {
        return false;
    }
}




// Backup API
define('BACKUP_API_KEY', getenv('BACKUP_API_KEY') ?: 'dk_RLirXDf8bQ23NvTrpCVFD2m_hQPutrHw');
define('BACKUP_API_URL', getenv('BACKUP_API_URL') ?: 'https://www.portal-02.com/api/v1');

// Server-side 2-Minute Background Auto Sync Trigger (runs even when admin is offline or away from admin page)
if (!defined('CRON_RUN')) {
    $lastSyncFile = __DIR__ . '/logs/last_2min_sync.timestamp';
    $lastSync = file_exists($lastSyncFile) ? (int)@file_get_contents($lastSyncFile) : 0;
    if (time() - $lastSync >= 120) {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents($lastSyncFile, time());
        $cronScript = __DIR__ . '/cron/sync_order_status.php';
        if (file_exists($cronScript)) {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                @pclose(@popen("start /B php \"" . $cronScript . "\" secret=apex_cron_s3cr3t_2026 >NUL 2>&1", "r"));
            } else {
                @exec("php \"" . $cronScript . "\" secret=apex_cron_s3cr3t_2026 >/dev/null 2>&1 &");
            }
        }
    }
}





function mapleradRequest($method, $endpoint, $data = []) {
    $url = 'https://api.maplerad.com' . $endpoint;
    $ch = curl_init($url);
    
    $headers = [
        'Authorization: Bearer ' . MAPLERAD_SECRET_KEY,
        'Content-Type: application/json'
    ];
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    if (!empty($data) && strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        return ['status' => false, 'message' => $err];
    }
    
    $decoded = json_decode($response, true);
    return $decoded;
}

/**
 * Credits store order profit to the reseller/store owner's store_balance (withdrawal balance)
 * ONLY WHEN the order status is completed/successful.
 */
function creditStoreOrderProfit(PDO $pdo, int $orderId): void {
    try {
        if ($orderId <= 0) return;

        // Ensure necessary DB columns exist
        try { $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN profit_amount DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN profit_credited TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN store_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}

        // Fetch order details
        $stmt = $pdo->prepare("SELECT id, user_id, network, gb_amount, selling_price, profit_amount, profit_credited, status, channel, reference, customer_email, store_id FROM bundle_sends WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) return;
        if (!empty($order['profit_credited'])) return; // Profit already credited!

        $status = strtolower(trim($order['status'] ?? ''));
        if (!in_array($status, ['completed', 'successful', 'success', 'sucessfully', 'successfully', 'delivered', 'registered'])) {
            return; // STRICTLY credit ONLY when order is completed/successful!
        }

        // Only credit for E-store orders
        $isStoreOrder = ($order['channel'] === 'store') 
            || (isset($order['store_id']) && (int)$order['store_id'] > 0)
            || (strpos($order['reference'] ?? '', 'STR_') === 0) 
            || !empty($order['customer_email']);

        if (!$isStoreOrder) {
            $pdo->prepare("UPDATE bundle_sends SET profit_credited = 1 WHERE id = ?")->execute([$orderId]);
            return;
        }

        $storeUserId = (int)($order['user_id'] ?? 0);
        if ($storeUserId <= 0) return;

        // Fetch store owner's role
        $ownerRole = 'dealer';
        try {
            $stmtR = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmtR->execute([$storeUserId]);
            $dbOwnerRole = $stmtR->fetchColumn();
            if (!empty($dbOwnerRole)) $ownerRole = $dbOwnerRole;
        } catch (Exception $e) {}

        $sellingPrice = (float)($order['selling_price'] ?? 0);
        $gbAmt        = (float)($order['gb_amount'] ?? 0);
        $net          = strtoupper($order['network'] ?? '');
        $profit       = (float)($order['profit_amount'] ?? 0);

        // If profit_amount was not set, calculate from selling_price and user role base price
        if ($profit <= 0 && $sellingPrice > 0) {
            $basePrice = 0.00;
            if (in_array($net, ['MTN', 'TELECEL', 'ISHARE'])) {
                $basePrice = getGbPriceGhs($pdo, $net, $gbAmt, $ownerRole);
            } elseif ($net === 'AFA') {
                $afaRole = strtolower(trim($ownerRole));
                if ($afaRole === 'dealer' || $afaRole === 'reseller') $afaRole = 'dealers';
                elseif ($afaRole === 'super_agent') $afaRole = 'super agent';
                try {
                    $stmtAfa = $pdo->prepare("SELECT standard_price FROM afa_pricing WHERE role = ? LIMIT 1");
                    $stmtAfa->execute([$afaRole]);
                    $basePrice = (float)$stmtAfa->fetchColumn();
                } catch (Exception $e) {}
                if ($basePrice <= 0) $basePrice = 10.00;
            } elseif ($net === 'WASSCE' || $net === 'BECE') {
                $basePrice = getDigitalRolePrice($pdo, strtolower($net), $ownerRole, 15.50);
            } elseif ($net === 'NETFLIX') {
                $basePrice = getDigitalRolePrice($pdo, 'netflix', $ownerRole, 50.00);
            } elseif ($net === 'DIGITAL') {
                $basePrice = getDigitalRolePrice($pdo, 'wassce', $ownerRole, 15.50);
            }
            $profit = max(0, $sellingPrice - $basePrice);
        }

        if ($profit > 0) {
            $pdo->beginTransaction();
            // Mark profit_credited = 1 atomically to prevent race conditions
            $stmtMark = $pdo->prepare("UPDATE bundle_sends SET profit_amount = ?, profit_credited = 1 WHERE id = ? AND (profit_credited = 0 OR profit_credited IS NULL)");
            $stmtMark->execute([$profit, $orderId]);

            if ($stmtMark->rowCount() > 0) {
                // Fetch current store_balance before crediting
                $stmtUser = $pdo->prepare("SELECT store_balance FROM users WHERE id = ? FOR UPDATE");
                $stmtUser->execute([$storeUserId]);
                $balBefore = (float)($stmtUser->fetchColumn() ?: 0.00);
                $balAfter  = $balBefore + $profit;

                // Credit user's withdrawal store_balance (Withdrawal Balance ONLY - NOT main wallet)
                $pdo->prepare("UPDATE users SET store_balance = store_balance + ? WHERE id = ?")
                    ->execute([$profit, $storeUserId]);

                // Update reseller_stores total profit & store_balance
                try {
                    try { $pdo->exec("ALTER TABLE reseller_stores ADD COLUMN store_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
                    try { $pdo->exec("ALTER TABLE reseller_stores ADD COLUMN total_profit DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
                    $pdo->prepare("UPDATE reseller_stores SET store_balance = store_balance + ?, total_profit = total_profit + ? WHERE user_id = ?")
                        ->execute([$profit, $profit, $storeUserId]);
                } catch (Exception $e) {}

                // Record in wallet_transactions as profit log with balance_before and balance_after
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        amount DECIMAL(10,2) NOT NULL,
                        type VARCHAR(20) NOT NULL,
                        reference VARCHAR(100) DEFAULT NULL,
                        description VARCHAR(255) DEFAULT NULL,
                        balance_before DECIMAL(10,2) DEFAULT 0.00,
                        balance_after DECIMAL(10,2) DEFAULT 0.00,
                        status VARCHAR(20) DEFAULT 'success',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

                    $profRef  = 'STORE-PROF-' . $orderId;
                    $itemDesc = $gbAmt > 0 ? "{$gbAmt}GB" : "{$net}";
                    $profDesc = "Store Order Profit: {$net} {$itemDesc} for Order #{$orderId} added to Withdrawal Balance";
                    $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, type, reference, description, balance_before, balance_after, status) VALUES (?, ?, 'profit', ?, ?, ?, ?, 'success')")
                        ->execute([$storeUserId, $profit, $profRef, $profDesc, $balBefore, $balAfter]);
                } catch (Exception $e) {}
            }
            $pdo->commit();
        } else {
            // Mark profit_credited = 1 even if profit is 0
            $pdo->prepare("UPDATE bundle_sends SET profit_credited = 1 WHERE id = ?")->execute([$orderId]);
        }
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

/**
 * Reconciles and syncs a user's store profit balance strictly against completed store orders and completed withdrawals.
 * Ensures Available Store Profit Balance = (Total Completed Store Profits - Total Completed Withdrawals)
 */
function sync_store_profit_balance(PDO $pdo, int $userId): float {
    try {
        if ($userId <= 0) return 0.00;

        // Ensure columns exist
        try { $pdo->exec("ALTER TABLE users ADD COLUMN store_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN profit_amount DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN profit_credited TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE reseller_stores ADD COLUMN store_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE reseller_stores ADD COLUMN total_profit DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}

        // 1. Process any uncredited COMPLETED store orders for this user
        $uncredited = $pdo->prepare("SELECT id FROM bundle_sends WHERE user_id = ? AND (channel = 'store' OR reference LIKE 'STR_%' OR (customer_email != '' AND customer_email IS NOT NULL) OR store_id > 0) AND status IN ('completed', 'successful', 'success', 'sucessfully', 'successfully', 'delivered', 'registered') AND (profit_credited = 0 OR profit_credited IS NULL)");
        $uncredited->execute([$userId]);
        $uncreditedIds = $uncredited->fetchAll(PDO::FETCH_COLUMN);
        foreach ($uncreditedIds as $ordId) {
            creditStoreOrderProfit($pdo, (int)$ordId);
        }

        // 2. Fetch total lifetime earned profit strictly from COMPLETED store orders
        $stmtProf = $pdo->prepare("SELECT COALESCE(SUM(profit_amount), 0) FROM bundle_sends WHERE user_id = ? AND (channel = 'store' OR reference LIKE 'STR_%' OR (customer_email != '' AND customer_email IS NOT NULL) OR store_id > 0) AND status IN ('completed', 'successful', 'success', 'sucessfully', 'successfully', 'delivered', 'registered')");
        $stmtProf->execute([$userId]);
        $totalEarned = (float)$stmtProf->fetchColumn();

        // 3. Fetch total completed withdrawals
        $totalWithdrawn = 0.00;
        try {
            $stmtWd = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM store_withdrawals WHERE user_id = ? AND status IN ('completed', 'success', 'approved')");
            $stmtWd->execute([$userId]);
            $totalWithdrawn = (float)$stmtWd->fetchColumn();
        } catch (Exception $e) {}

        // 4. Net available profit balance strictly from completed orders
        $expectedAvailable = max(0, round($totalEarned - $totalWithdrawn, 2));

        // Sync users.store_balance
        $pdo->prepare("UPDATE users SET store_balance = ? WHERE id = ?")->execute([$expectedAvailable, $userId]);

        // Sync reseller_stores table
        try {
            $pdo->prepare("UPDATE reseller_stores SET store_balance = ?, total_profit = ? WHERE user_id = ?")
                ->execute([$expectedAvailable, $totalEarned, $userId]);
        } catch (Exception $ex) {}

        return $expectedAvailable;
    } catch (Exception $e) {
        error_log('[sync_store_profit_balance Error] ' . $e->getMessage());
        return 0.00;
    }
}

if (!function_exists('trigger_webhook')) {
    function trigger_webhook(PDO $pdo, int $orderId, ?string $newStatus = null, ?string $message = null) {
        try {
            require_once __DIR__ . '/classes/WebhookDispatcher.php';
            return WebhookDispatcher::dispatchOrderStatus($pdo, $orderId, $newStatus, $message);
        } catch (Exception $e) {
            error_log('trigger_webhook error: ' . $e->getMessage());
            return false;
        }
    }
}

// ── Auto-Login & 5-Hour Recent Login Session Management ───────────────────────
if (!defined('RECENT_LOGIN_COOKIE')) {
    define('RECENT_LOGIN_COOKIE', 'apex_recent_login');
}
if (!defined('RECENT_LOGIN_MAX_AGE')) {
    define('RECENT_LOGIN_MAX_AGE', 5 * 3600); // 5 hours (18,000 seconds)
}

if (!function_exists('get_auth_secret_key')) {
    function get_auth_secret_key(): string {
        return hash('sha256', (defined('DB_PASS') ? DB_PASS : 'apex_secret') . '_apex_login_auth_salt_2026');
    }
}

if (!function_exists('set_recent_login_cookie')) {
    function set_recent_login_cookie(array $user): void {
        if (empty($user['id'])) return;
        $timestamp = time();
        $key = get_auth_secret_key();
        $passwordHash = (string)($user['password_hash'] ?? $user['password'] ?? '');
        $hash = hash_hmac('sha256', $user['id'] . '|' . $timestamp . '|' . $passwordHash, $key);
        $payload = base64_encode(json_encode([
            'uid' => (int)$user['id'],
            'time' => $timestamp,
            'hash' => $hash,
        ]));

        $_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || ($_SERVER['SERVER_PORT'] ?? 80) == 443
                 || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        $expire = $timestamp + RECENT_LOGIN_MAX_AGE;

        if (!headers_sent()) {
            setcookie(RECENT_LOGIN_COOKIE, $payload, [
                'expires' => $expire,
                'path' => '/',
                'domain' => '',
                'secure' => $_isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        $_COOKIE[RECENT_LOGIN_COOKIE] = $payload;
    }
}

if (!function_exists('clear_recent_login_cookie')) {
    function clear_recent_login_cookie(): void {
        $_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || ($_SERVER['SERVER_PORT'] ?? 80) == 443
                 || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        if (!headers_sent()) {
            setcookie(RECENT_LOGIN_COOKIE, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => $_isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        unset($_COOKIE[RECENT_LOGIN_COOKIE]);
    }
}

if (!function_exists('restore_recent_login_session')) {
    function restore_recent_login_session(): ?array {
        if (!empty($_SESSION['user'])) {
            return $_SESSION['user'];
        }

        if (empty($_COOKIE[RECENT_LOGIN_COOKIE])) {
            return null;
        }

        $raw = @base64_decode($_COOKIE[RECENT_LOGIN_COOKIE]);
        if (!$raw) {
            clear_recent_login_cookie();
            return null;
        }

        $data = @json_decode($raw, true);
        if (!is_array($data) || empty($data['uid']) || empty($data['time']) || empty($data['hash'])) {
            clear_recent_login_cookie();
            return null;
        }

        $now = time();
        $loginTime = (int)$data['time'];
        // Must be within 5 hours (18,000s)
        if ($loginTime > $now || ($now - $loginTime) > RECENT_LOGIN_MAX_AGE) {
            clear_recent_login_cookie();
            return null;
        }

        try {
            $pdo = db_connect();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$data['uid']]);
            $user = $stmt->fetch();

            if (!$user) {
                clear_recent_login_cookie();
                return null;
            }

            $accountStatus = strtolower((string)($user['account_status'] ?? 'active'));
            if ($accountStatus === 'banned' || $accountStatus === 'suspended') {
                clear_recent_login_cookie();
                return null;
            }

            $key = get_auth_secret_key();
            $passwordHash = (string)($user['password_hash'] ?? $user['password'] ?? '');
            $expectedHash = hash_hmac('sha256', $user['id'] . '|' . $loginTime . '|' . $passwordHash, $key);

            if (!hash_equals($expectedHash, (string)$data['hash'])) {
                clear_recent_login_cookie();
                return null;
            }

            // Successfully authenticated from recent 5-hour login
            $_SESSION['user'] = $user;
            return $user;
        } catch (Exception $e) {
            return null;
        }
    }
}

// Automatically restore user session if user recently logged in within 5 hours
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user']) && !empty($_COOKIE[RECENT_LOGIN_COOKIE])) {
    restore_recent_login_session();
}

/**
 * Sanitizes order note/message for client and store owner view:
 * - Masks internal suppliers (NITGHT, MTNUP2U, Supplier 1, Jaybart, etc.) with 'AI ASSISTANT Bot'
 * - Formats rerouted messages as 'Auto-rerouted by AI Assistant Provider to preferred system for fast delivery. (Ref: ...)'
 * - Formats number not registered messages as 'Recipient number is not registered for MTN data. Please verify or register the number first.'
 * - Formats validating/awaiting approval as 'Awaiting MTN approval'
 * - Strips internal AutoSync logs and sensitive API traces
 */
function sanitizeOrderNoteForClient(?string $message, string $status = '', string $network = '', ?string $createdAt = null, $gbAmount = null, ?string $recipientPhone = null): string {
    $raw = trim((string)$message);
    $st = strtolower(trim($status));
    $net = strtoupper(trim($network));

    // If order is completed/successful, return verified and delivered message
    $isCompleted = in_array($st, ['approved', 'successful', 'success', 'successfully', 'sucessfully', 'completed', 'delivered', 'registered']);
    if ($isCompleted) {
        if (!empty($gbAmount) && !empty($recipientPhone)) {
            return 'Data bundle of ' . (float)$gbAmount . 'GB to ' . $recipientPhone . ' was verified and delivered successfully!';
        } elseif (!empty($recipientPhone)) {
            return 'Data bundle to ' . $recipientPhone . ' was verified and delivered successfully!';
        }
        return 'Data bundle was verified and delivered successfully!';
    }

    $isMtn = (strpos($net, 'MTN') !== false);
    $isOngoing = in_array($st, ['pending', 'processing', 'validating', 'waiting', 'accepted', 'initiated']);
    $isOver1Hr = false;
    if ($createdAt) {
        $cTs = strtotime($createdAt);
        if ($cTs > 0 && (time() - $cTs) >= 3600) {
            $isOver1Hr = true;
        }
    }

    // If empty or literal None
    if ($raw === '' || strcasecmp($raw, 'none') === 0 || strcasecmp($raw, 'null') === 0) {
        if ($st === 'validating' || ($isMtn && $st === 'validating')) {
            return 'Awaiting MTN approval';
        }
        if ($isOngoing && $isMtn && $isOver1Hr) {
            return 'Est. delivery: Not available! MTN system is currently busy. Please try again later.';
        }
        return '';
    }

    $clean = $raw;

    // 1. Strip internal AutoSync noise
    $clean = preg_replace('/\|\s*AutoSync.*?$/i', '', $clean);
    $clean = preg_replace('/AutoSync.*$/i', '', $clean);

    // 2. Check for "Number not registered" / beneficiary errors
    if (
        stripos($clean, 'not registered') !== false ||
        stripos($clean, 'not added to our beneficiary list') !== false ||
        stripos($clean, 'will be added to our beneficiary list') !== false ||
        stripos($clean, 'beneficiary') !== false ||
        $st === 'unverified'
    ) {
        return 'Recipient number is not registered. Please let us verify or register the number first. If a refund is needed, we will notify you.';
    }

    // 3. Check for Rerouted orders
    if (preg_match('/(?:re-routed|rerouted)/i', $clean)) {
        $orderId = '';
        if (preg_match('/(?:Order ID|Ref)[:\s]+([A-Za-z0-9\-_]+)/i', $clean, $m)) {
            $orderId = ' (Ref: ' . $m[1] . ')';
        }
        $msgOut = 'Auto-rerouted by AI Assistant Provider to preferred system for fast delivery.' . $orderId;
        if ($isOngoing && $isMtn && $isOver1Hr) {
            $msgOut .= "\n\nEst. delivery: Not available! MTN system is currently busy. Please try again later.";
        }
        return $msgOut;
    }

    // 4. Check for "Awaiting MTN approval" / validating status
    if (
        stripos($clean, 'awaiting mtn approval') !== false ||
        stripos($clean, 'awaiting mtn approva') !== false ||
        stripos($clean, 'awaiting approval') !== false ||
        stripos($clean, 'awaiting mtn') !== false ||
        $st === 'validating'
    ) {
        return 'Awaiting MTN approval';
    }

    // 5. Check for orders received by / sent to AI Assistant Provider
    if (
        preg_match('/(?:sent\s+to|received\s+by|dispatched\s+to|forwarded\s+to|placed\s+with)\b/i', $clean) ||
        (in_array($st, ['waiting', 'processing', 'accepted', 'pending', 'initiated']) && preg_match('/(?:NITGHT|MTNUP2U|Supplier\s+[0-9]+|Jaybart|Aviator|API-DEV|AI Assistant)/i', $clean)) ||
        (in_array($st, ['waiting', 'processing']) && in_array(strtolower(trim($clean)), ['waiting', 'processing', 'order is processing', 'in progress', 'sent']))
    ) {
        $refStr = '';
        if (preg_match('/(?:Order ID|Ref|Reference)[:\s]+([A-Za-z0-9\-_]+)/i', $clean, $m)) {
            $refStr = ' (Ref: ' . $m[1] . ')';
        }

        if (stripos($clean, 'afa') !== false || $net === 'AFA') {
            $msgOut = 'Application received by AI Assistant Provider and currently processing.' . $refStr;
        } else {
            $msgOut = 'Order received by AI Assistant Provider and currently processing for delivery.' . $refStr;
        }

        if ($isOngoing && $isMtn && $isOver1Hr) {
            $msgOut .= "\n\nEst. delivery: Not available! MTN system is currently busy. Please try again later.";
        }
        return $msgOut;
    }

    // 6. Mask known supplier names with 'AI Assistant Provider'
    $supplierPatterns = [
        '/NITGHT/i',
        '/NIGHT\s+API/i',
        '/NIGHT/i',
        '/MTNUP2U\s+PORTAL/i',
        '/MTNUP2U/i',
        '/Supplier\s+[0-9]+/i',
        '/supplier=[0-9]+/i',
        '/Jaybart/i',
        '/api-dev/i',
        '/Aviator/i',
        '/AI ASSISTANT Bot/i'
    ];

    foreach ($supplierPatterns as $pat) {
        $clean = preg_replace($pat, 'AI Assistant Provider', $clean);
    }

    // Clean up redundant phrases like "AI Assistant Provider validating."
    $clean = preg_replace('/AI Assistant Provider\s+validating\.\s*/i', '', $clean);
    $clean = preg_replace('/\s+/', ' ', $clean);
    $clean = trim($clean, " |.-");

    if (strcasecmp($clean, 'none') === 0 || strcasecmp($clean, 'null') === 0) {
        if ($isOngoing && $isMtn && $isOver1Hr) {
            return 'Est. delivery: Not available! MTN system is currently busy. Please try again later.';
        }
        return '';
    }

    if ($isOngoing && $isMtn && $isOver1Hr) {
        $clean .= "\n\nEst. delivery: Not available! MTN system is currently busy. Please try again later.";
    }

    return $clean;
}



