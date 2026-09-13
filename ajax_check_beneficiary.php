<?php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }

header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/classes/NitghtApi.php';
require_once __DIR__ . '/classes/MtnUp2uApi.php';
require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
require_once __DIR__ . '/classes/JaybartWhitelist.php';
require_once __DIR__ . '/classes/Supplier1Whitelist.php';

$rawPhones = $_REQUEST['phones'] ?? $_REQUEST['recipients'] ?? $_REQUEST['bulk'] ?? $_REQUEST['phone'] ?? $_REQUEST['recipient'] ?? '';

$phoneList = [];
if (is_array($rawPhones)) {
    $phoneList = $rawPhones;
} elseif (is_string($rawPhones)) {
    // If it's a string, split by newlines, commas, or spaces
    $lines = preg_split('/[\r\n,]+/', $rawPhones);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $parts = preg_split('/\s+/', $line);
        if (!empty($parts[0])) {
            $phoneList[] = $parts[0];
        }
    }
}

// Clean and normalize phone numbers
$normalizedList = [];
foreach ($phoneList as $phone) {
    $clean = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strlen($clean) === 12 && substr($clean, 0, 3) === '233') {
        $clean = '0' . substr($clean, 3);
    } elseif (strlen($clean) === 9 && substr($clean, 0, 1) !== '0') {
        $clean = '0' . $clean;
    }
    if (strlen($clean) === 10) {
        $normalizedList[] = $clean;
    }
}

$normalizedList = array_values(array_unique($normalizedList));

if (empty($normalizedList)) {
    echo json_encode(['success' => false, 'message' => 'No valid 10-digit phone numbers provided.', 'has_new' => false, 'new_count' => 0, 'new_numbers' => []]);
    exit;
}

try {
    $pdo = db_connect();
    
    // Fetch all local verified numbers in one batch query for performance
    $placeholders = implode(',', array_fill(0, count($normalizedList), '?'));
    $stmt = $pdo->prepare("SELECT phone_number FROM mtn_verified_numbers WHERE phone_number IN ($placeholders)");
    $stmt->execute($normalizedList);
    $localVerifiedSet = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $network = trim($_REQUEST['network'] ?? 'MTN');
    $isMtn = (strcasecmp($network, 'MTN') === 0 || stripos($network, 'mtn') !== false || strcasecmp($network, 'YELLO') === 0);

    $nitghtOrderableSet = [];
    $nitghtEnabled = ($isMtn && NitghtApi::isEnabled() && NitghtApi::isNetworkEnabled('MTN'));

    // Check missing numbers against Night API MTN pre-check in one batch
    $missingLocal = [];
    foreach ($normalizedList as $phone) {
        if (!isset($localVerifiedSet[$phone])) {
            $missingLocal[] = $phone;
        }
    }

    if ($nitghtEnabled && !empty($missingLocal)) {
        try {
            $nitghtApi = new NitghtApi();
            $precheckRes = $nitghtApi->precheck(array_values($missingLocal), 'MTN');
            if (!empty($precheckRes['checked'])) {
                $unorderableMap = array_flip($precheckRes['unorderable'] ?? []);
                foreach ($missingLocal as $mp) {
                    if (!isset($unorderableMap[$mp])) {
                        $nitghtOrderableSet[$mp] = true;
                        $localVerifiedSet[$mp] = true;
                        try {
                            $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$mp]);
                        } catch (Exception $e) {}
                    }
                }
            }
        } catch (Exception $e) {}
    }

    $mtnUp2GbEnabled = MtnUp2uPortalApi::isNetworkEnabled('MTN');
    $mtnUp2GbApi = $mtnUp2GbEnabled ? new MtnUp2uPortalApi() : null;

    $mtnUp2uEnabled = MtnUp2uApi::isNetworkEnabled('MTN');
    $mtnUp2uApi = $mtnUp2uEnabled ? new MtnUp2uApi() : null;

    $newNumbers = [];
    $details = [];

    $refundRestrictedMap = function_exists('getBatchPhoneRefundRestrictions') ? getBatchPhoneRefundRestrictions($pdo, $normalizedList) : [];

    foreach ($normalizedList as $phone) {
        $inLocalDb = isset($localVerifiedSet[$phone]);
        $inNitght  = isset($nitghtOrderableSet[$phone]);
        $inMtnUp2u = false;
        
        if (!$inLocalDb && $mtnUp2GbApi) {
            $inMtnUp2u = $mtnUp2GbApi->isBeneficiary($phone, $network);
            if ($inMtnUp2u) {
                $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$phone]);
                $inLocalDb = true;
            }
        }
        if (!$inLocalDb && !$inMtnUp2u && $mtnUp2uApi) {
            $inMtnUp2u = $mtnUp2uApi->isBeneficiary($phone, $network);
            if ($inMtnUp2u) {
                $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$phone]);
                $inLocalDb = true;
            }
        }

        $inJaybart = JaybartWhitelist::isWhitelisted($phone);
        $inSupplier1 = Supplier1Whitelist::isWhitelisted($phone);

        // A number is considered NEW if it's NOT in local DB, NOT orderable in Nitght, NOT in MTNUP2U, NOT in Jaybart whitelist, and NOT in Supplier1 whitelist
        $isNew = (!$inLocalDb && !$inNitght && !$inMtnUp2u && !$inJaybart && !$inSupplier1);

        if ($isNew) {
            $newNumbers[] = $phone;
        }

        $rInfo = $refundRestrictedMap[$phone] ?? null;

        $details[] = [
            'phone' => $phone,
            'in_system' => $inLocalDb,
            'in_nitght' => $inNitght,
            'in_mtnup2u' => $inMtnUp2u,
            'in_jaybart' => $inJaybart,
            'in_supplier1' => $inSupplier1,
            'is_new' => $isNew,
            'is_refund_restricted' => ($rInfo !== null),
            'restriction_info' => $rInfo
        ];
    }

    $hasNew = count($newNumbers) > 0;
    $hasRefundRestricted = !empty($refundRestrictedMap);
    
    // Legacy single-number compatibility flags
    $firstPhone = $normalizedList[0];
    $firstDetail = $details[0];
    $existsInSystem = ($firstDetail['in_system'] || $firstDetail['in_nitght'] || $firstDetail['in_mtnup2u'] || $firstDetail['in_jaybart'] || $firstDetail['in_supplier1']);

    echo json_encode([
        'success' => true,
        'has_new' => $hasNew,
        'new_count' => count($newNumbers),
        'new_numbers' => $newNumbers,
        'has_refund_restricted' => $hasRefundRestricted,
        'refund_restricted' => $refundRestrictedMap,
        'exists' => $existsInSystem, // For backward compatibility with single-number checks
        'details' => $details
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'has_new' => false, 'new_count' => 0, 'new_numbers' => []]);
}
