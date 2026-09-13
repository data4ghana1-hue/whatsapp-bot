<?php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }

if (empty($_SESSION['user'])) {
    header('Location: ' . APP_URL . 'login');
    exit;
}

// Allow all logged-in users to access store


$user   = $_SESSION['user'];
$userId = (int)$user['id'];
$pdo    = db_connect();

try { $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN channel VARCHAR(50) DEFAULT 'send'"); } catch (Exception $e) {}

$errors  = [];
$success = $_SESSION['store_success'] ?? '';
if ($success) {
    unset($_SESSION['store_success']);
}

function normalizeStoreErrors($value): array {
    $clean = [];

    if (is_array($value)) {
        foreach ($value as $entry) {
            foreach (normalizeStoreErrors($entry) as $message) {
                $clean[] = $message;
            }
        }
        return array_values(array_unique(array_filter(array_map(function ($item) {
            $message = trim((string)$item);
            if ($message === '') {
                return null;
            }
            return strip_tags($message);
        }, $clean), function ($item) {
            return $item !== null && $item !== '';
        })));
    }

    $text = trim((string)$value);
    if ($text === '') {
        return [];
    }

    $parts = preg_split('/\s*\|\s*/', $text, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($parts as $part) {
        $message = trim(strip_tags((string)$part));
        if ($message !== '') {
            $clean[] = $message;
        }
    }

    return array_values(array_unique($clean));
}

function flashStoreErrors(array $errorList): void {
    if (empty($errorList)) {
        return;
    }

    $_SESSION['store_error'] = normalizeStoreErrors($errorList);
}

$sessionError = $_SESSION['store_error'] ?? '';
$sessionErrors = normalizeStoreErrors($sessionError);
if (!empty($sessionErrors)) {
    foreach ($sessionErrors as $error) {
        $errors[] = $error;
    }
    unset($_SESSION['store_error']);
}

// Fetch wallet balance and free mode status
$walletBalance = getUserWalletBalance($pdo, $userId);
$freeMode = (int)($user['free_mode'] ?? 0);

// Fetch user phone number for 1-click shortcut autofill
$userPhone = '';
try {
    $stmtUser = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $userPhone = trim((string)$stmtUser->fetchColumn());
} catch(Exception $e) {}
if (!$userPhone && !empty($_SESSION['user']['phone'])) {
    $userPhone = trim((string)$_SESSION['user']['phone']);
}

require_once __DIR__ . '/classes/SupplierApi.php';
require_once __DIR__ . '/classes/Supplier2Api.php';
require_once __DIR__ . '/classes/Supplier3Api.php';
require_once __DIR__ . '/classes/Supplier5Api.php';
                require_once __DIR__ . '/classes/BackupApi.php';
require_once __DIR__ . '/classes/MtnUp2uApi.php';
require_once __DIR__ . '/classes/NitghtApi.php';
require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
require_once __DIR__ . '/classes/Supplier1Whitelist.php';

// Fetch pricing packages: local DB → Supplier 1 → Supplier 2 → hardcoded fallback
function getPricing(PDO $pdo, string $network, string $role = 'client'): array {
    $settings = function_exists('load_all_settings') ? load_all_settings($pdo) : [];
    $netUpper = strtoupper(trim($network));
    if (($netUpper === 'MTN' || $netUpper === 'MTN EXPRESS') && empty($settings['mtn_enabled'])) {
        return [];
    }
    if (($netUpper === 'TELECEL' || $netUpper === 'VODAFONE') && empty($settings['telecel_enabled'])) {
        return [];
    }
    if (($netUpper === 'ISHARE' || $netUpper === 'AT' || $netUpper === 'AIRTELTIGO') && empty($settings['ishare_enabled'])) {
        return [];
    }

    // Priority 1: Local DB Pricing (Admin configured, Role-based)
    $roleCol = 'price_client';
    $r = strtolower(trim($role));
    if ($r === 'admin') $roleCol = 'price_admin';
    elseif ($r === 'super agent' || $r === 'super_agent' || $r === 'super') $roleCol = 'price_super_agent';
    elseif ($r === 'elite') $roleCol = 'price_elite';
    elseif ($r === 'vip') $roleCol = 'price_vip';
    elseif ($r === 'agent') $roleCol = 'price_agent';
    elseif ($r === 'dealer' || $r === 'dealers' || $r === 'reseller') $roleCol = 'price_dealer';
    
    try {
        $stmt = $pdo->prepare("SELECT gb_amount, COALESCE(NULLIF($roleCol, 0), NULLIF(price_dealer, 0), NULLIF(price_client, 0), price_ghs) AS price_ghs FROM network_pricing WHERE UPPER(network) = UPPER(?) AND is_active = 1 ORDER BY sort_order ASC, gb_amount ASC");
        $stmt->execute([$network]);
        $rows = $stmt->fetchAll();
        if (!empty($rows)) return $rows;
    } catch (Exception $e) {}

    // Priority 2: Supplier 1 (all networks)
    if (SupplierApi::isNetworkEnabled($network)) {
        $supplierApi = new SupplierApi();
        $apiBundles = $supplierApi->getAvailableBundles($network);
        if (!empty($apiBundles['success']) && !empty($apiBundles['data']['bundles'])) {
            $prices = [];
            foreach ($apiBundles['data']['bundles'] as $b) {
                $prices[] = ['gb_amount' => (float)$b['size_gb'], 'price_ghs' => (float)$b['price']];
            }
            usort($prices, function($a, $b) {
                return $a['gb_amount'] > $b['gb_amount'] ? 1 : ($a['gb_amount'] < $b['gb_amount'] ? -1 : 0);
            });
            return $prices;
        }
    }

    // Priority 3: Supplier 2 (MTN only — BoatLink)
    if (strtolower($network) === 'mtn' && Supplier2Api::isNetworkEnabled('mtn')) {
        $s2Api = new Supplier2Api();
        $s2Bundles = $s2Api->getAvailableBundles();
        if (!empty($s2Bundles['success']) && !empty($s2Bundles['data']['bundles'])) {
            $prices = [];
            foreach ($s2Bundles['data']['bundles'] as $b) {
                $prices[] = ['gb_amount' => (float)$b['size_gb'], 'price_ghs' => (float)$b['price']];
            }
            usort($prices, function($a, $b) {
                return $a['gb_amount'] > $b['gb_amount'] ? 1 : ($a['gb_amount'] < $b['gb_amount'] ? -1 : 0);
            });
            return $prices;
        }
    }
    // Fallback hardcoded packages
    if ($network === 'MTN') {
        return [
            ['gb_amount'=>1,  'price_ghs'=>4.00],
            ['gb_amount'=>2,  'price_ghs'=>8.00],
            ['gb_amount'=>3,  'price_ghs'=>12.00],
            ['gb_amount'=>5,  'price_ghs'=>20.00],
            ['gb_amount'=>10, 'price_ghs'=>39.50],
            ['gb_amount'=>15, 'price_ghs'=>59.25],
            ['gb_amount'=>20, 'price_ghs'=>79.00],
        ];
    } elseif ($network === 'Telecel') {
        return [
            ['gb_amount'=>1,  'price_ghs'=>3.50],
            ['gb_amount'=>2,  'price_ghs'=>7.00],
            ['gb_amount'=>3,  'price_ghs'=>10.50],
            ['gb_amount'=>5,  'price_ghs'=>17.50],
            ['gb_amount'=>10, 'price_ghs'=>35.00],
            ['gb_amount'=>15, 'price_ghs'=>52.50],
            ['gb_amount'=>20, 'price_ghs'=>68.00],
        ];
    } else { // Ishare/AT
        return [
            ['gb_amount'=>1,  'price_ghs'=>3.70],
            ['gb_amount'=>2,  'price_ghs'=>7.40],
            ['gb_amount'=>3,  'price_ghs'=>11.10],
            ['gb_amount'=>5,  'price_ghs'=>18.50],
            ['gb_amount'=>10, 'price_ghs'=>37.00],
            ['gb_amount'=>15, 'price_ghs'=>55.50],
            ['gb_amount'=>20, 'price_ghs'=>74.00],
        ];
    }
}

$mtnPrices     = getPricing($pdo, 'MTN', $user['role'] ?? 'client');
$telecelPrices = getPricing($pdo, 'Telecel', $user['role'] ?? 'client');
$isharePrices  = getPricing($pdo, 'Ishare', $user['role'] ?? 'client');
$mtnExpressPrices = getPricing($pdo, 'MTN EXPRESS', $user['role'] ?? 'client');

// Handle POST order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buy') {
    $network   = trim($_POST['network'] ?? '');
    $recipient = trim($_POST['recipient'] ?? '');
    $gb_amount = (float)($_POST['gb_amount'] ?? 0);
    $mode      = $_POST['mode'] ?? 'single';
    $bulkData  = trim($_POST['bulk_data'] ?? '');

    $validNetworks = ['MTN', 'Telecel', 'Ishare'];
    if (!in_array($network, $validNetworks)) {
        $errors[] = 'Invalid network selected.';
    } elseif ($mode === 'bulk') {
        if (empty($bulkData)) {
            $errors[] = 'Please enter dispatch matrix data.';
        } else {
            $lines = explode("\n", $bulkData);
            $validOrders = [];
            $invalidLines = [];
            $totalCost = 0;
            
            $phonesToCheck = [];
            if ($network === 'MTN') {
                foreach ($lines as $line) {
                    $parts = preg_split('/[\s,]+/', trim($line));
                    if (count($parts) >= 2) {
                        $p = normalizeAndValidatePhone(trim($parts[0]), 'MTN');
                        if ($p) $phonesToCheck[] = $p;
                    }
                }
            }
            
            $registeredPhones = [];
            if (!empty($phonesToCheck)) {
                $inQuery = implode(',', array_fill(0, count($phonesToCheck), '?'));
                $stmt = $pdo->prepare("SELECT phone_number FROM mtn_verified_numbers WHERE phone_number IN ($inQuery)");
                $stmt->execute($phonesToCheck);
                $registeredPhones = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

                // Check missing phones with supplier beneficiary APIs & whitelists
                $missingPhones = array_diff($phonesToCheck, $registeredPhones);
                if (!empty($missingPhones)) {
                    require_once __DIR__ . '/classes/NitghtApi.php';
                    require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
                    require_once __DIR__ . '/classes/MtnUp2uApi.php';
                    require_once __DIR__ . '/classes/JaybartWhitelist.php';
                    require_once __DIR__ . '/classes/Supplier1Whitelist.php';

                    // 1. Batch pre-check with Night API if enabled for MTN
                    if (NitghtApi::isEnabled() && NitghtApi::isNetworkEnabled('MTN')) {
                        try {
                            $nitghtApi = new NitghtApi();
                            $pRes = $nitghtApi->precheck(array_values($missingPhones), 'MTN');
                            if (!empty($pRes['checked'])) {
                                $unorderableMap = array_flip($pRes['unorderable'] ?? []);
                                foreach ($missingPhones as $mp) {
                                    if (!isset($unorderableMap[$mp])) {
                                        $registeredPhones[] = $mp;
                                        try {
                                            $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$mp]);
                                        } catch (Exception $e) {}
                                    }
                                }
                                $missingPhones = array_diff($phonesToCheck, $registeredPhones);
                            }
                        } catch (Exception $e) {}
                    }

                    if (!empty($missingPhones)) {
                        $up2gb = MtnUp2uPortalApi::isNetworkEnabled('MTN') ? new MtnUp2uPortalApi() : null;
                        $up2u  = MtnUp2uApi::isNetworkEnabled('MTN') ? new MtnUp2uApi() : null;

                        foreach ($missingPhones as $mp) {
                            $mpVerified = false;
                            if ($up2gb) {
                                try {
                                    if ($up2gb->isBeneficiary($mp, 'MTN')) {
                                        $mpVerified = true;
                                        $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$mp]);
                                    }
                                } catch (Exception $ex) {}
                            }
                            if (!$mpVerified && $up2u) {
                                try {
                                    if ($up2u->isBeneficiary($mp, 'MTN')) {
                                        $mpVerified = true;
                                        $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$mp]);
                                    }
                                } catch (Exception $ex) {}
                            }
                            if (!$mpVerified && (JaybartWhitelist::isWhitelisted($mp) || Supplier1Whitelist::isWhitelisted($mp))) {
                                $mpVerified = true;
                            }
                            if ($mpVerified) {
                                $registeredPhones[] = $mp;
                            }
                        }
                    }
                }
            }
            $unverifiedNumbers = [];
            $confirmUnverified = $_POST['confirm_unverified'] ?? '0';

            $batchPkg = 'mixed';
            $selectedGb = 0;

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $parts = preg_split('/[\s,]+/', $line);
                
                $recip = trim($parts[0]);
                
                if ($batchPkg !== 'mixed') {
                    $amt = $selectedGb;
                } else {
                    if (count($parts) < 2) {
                        $invalidLines[] = htmlspecialchars($line) . " (Missing GB amount)";
                        continue;
                    }
                    $amt = (float)trim($parts[1]);
                }
                
                $validPhone = normalizeAndValidatePhone($recip, $network);
                if ($amt >= 1 && $amt <= 100 && $validPhone) {
                    $isNumVerified = ($network !== 'MTN' || in_array($validPhone, $registeredPhones));
                    if (!$isNumVerified) {
                        $unverifiedNumbers[] = $validPhone;
                    }
                    
                    $cost = 0;
                    $pricingArray = ($network === 'MTN') ? ($isNumVerified ? $mtnPrices : $mtnExpressPrices) : (($network === 'Telecel') ? $telecelPrices : $isharePrices);
                    foreach ($pricingArray as $pkg) {
                        if ((float)$pkg['gb_amount'] === (float)$amt) {
                            $cost = (float)$pkg['price_ghs'];
                            break;
                        }
                    }
                    
                    if ($cost > 0) {
                        $totalCost += $cost;
                        $validOrders[] = ['recipient' => $validPhone, 'amount' => $amt, 'cost' => $cost, 'is_verified' => $isNumVerified];
                    } else {
                        $invalidLines[] = htmlspecialchars($line) . " (Invalid size)";
                    }
                } else {
                    $invalidLines[] = htmlspecialchars($line);
                }
            }

            // Note: $freeMode is defined globally above

            $bulkPhones = !empty($validOrders) ? array_column($validOrders, 'recipient') : [];
            $refundRestricted = (!empty($bulkPhones) && function_exists('getBatchPhoneRefundRestrictions')) ? getBatchPhoneRefundRestrictions($pdo, $bulkPhones) : [];

            if (!empty($invalidLines)) {
                $errors[] = "Some numbers have invalid formats. Invalid entries: " . implode("; ", array_slice($invalidLines, 0, 3)) . (count($invalidLines) > 3 ? "..." : "");
            } elseif (!empty($refundRestricted)) {
                $restrMsgs = [];
                foreach ($refundRestricted as $rPhone => $rInfo) {
                    $restrMsgs[] = "• " . htmlspecialchars($rPhone) . ": Refunded on " . htmlspecialchars($rInfo['refund_date_fmt']) . " (available on " . htmlspecialchars($rInfo['unlock_time_fmt']) . ", in " . htmlspecialchars($rInfo['remaining_text']) . ")";
                }
                $errors[] = "The following number(s) had an order refunded recently and cannot receive orders until 1 week after refund:<br>" . implode("<br>", $restrMsgs);
            } elseif (!empty($unverifiedNumbers) && $confirmUnverified !== '1') {
                $errors[] = count($unverifiedNumbers) . " number(s) are not in our beneficiary list. Please confirm to submit for MTN Verification.";
            } elseif (empty($validOrders)) {
                $errors[] = 'No valid entries found. Format: Number GB (one per line)';
            } elseif (!$freeMode && $totalCost > $walletBalance) {
                $errors[] = 'Insufficient wallet balance. Total cost is GHS ' . number_format($totalCost, 2) . ' but your balance is GHS ' . number_format($walletBalance, 2) . '.';
            } else {
                try {
                    require_once __DIR__ . '/classes/JaybartWhitelist.php';
                    $mtnUp2GbApi = MtnUp2uPortalApi::isNetworkEnabled($network) ? new MtnUp2uPortalApi() : null;
                    $mtnUp2uApi  = MtnUp2uApi::isNetworkEnabled($network)  ? new MtnUp2uApi()  : null;
                    $nitghtApi   = NitghtApi::isNetworkEnabled($network)   ? new NitghtApi()   : null;
                    $s1Api = SupplierApi::isNetworkEnabled($network) ? new SupplierApi() : null;
                    $s2Api = (strtolower($network) === 'mtn' && Supplier2Api::isNetworkEnabled('mtn')) ? new Supplier2Api() : null;
                    $s3Api = (strtolower($network) === 'mtn' && Supplier3Api::isNetworkEnabled('mtn')) ? new Supplier3Api() : null;
                    $s4Api = Supplier5Api::isNetworkEnabled($network) ? new Supplier5Api() : null;
                    $backupApi = BackupApi::isNetworkEnabled($network) ? new BackupApi() : null;

                    $pdo->beginTransaction();
                    // Log wallet debit for bulk bundle
                    if ($totalCost > 0) {
                        $ref = 'STORE-BULK-' . mt_rand(10000, 99999);
                        $descr = 'Bulk ' . $network . ' bundle purchase (' . count($validOrders) . ' orders)';
                        if ($freeMode) {
                            addFreeModeTransaction($pdo, $userId, $totalCost, $ref, $descr);
                        } else {
                            addWalletTransaction($pdo, $userId, $totalCost, 'debit', $ref, $descr);
                        }

                        $chkPending  = $pdo->prepare("SELECT 1 FROM bundle_sends WHERE recipient_phone = ? AND status NOT IN ('completed', 'successful', 'success', 'sucessfully', 'successfully', 'failed', 'refunded', 'rejected') LIMIT 1");
                        $chkVerified = $pdo->prepare("SELECT 1 FROM mtn_verified_numbers WHERE phone_number = ? LIMIT 1");
                        $stmtIns     = $pdo->prepare("INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, status, channel, message) VALUES (?, ?, ?, ?, ?, ?, ?)");

                        $insertedOrders = []; // track [orderId, recip, amount, ordNet] for API dispatch

                        foreach ($validOrders as $order) {
                            $recip = $order['recipient'];
                            $chkPending->execute([$recip]);
                            $hasActiveOrder = (bool)$chkPending->fetchColumn();

                            if ($hasActiveOrder) {
                                $ordStatus = 'multiple_order';
                                $ordNet    = $network;
                                $ordChan   = 'store';
                                $ordMsg    = 'Duplicate order placed while previous order is still pending/uncompleted.';
                                $stmtIns->execute([$userId, $ordNet, $recip, $order['amount'], $ordStatus, $ordChan, $ordMsg]);
                            } else {
                                $isVer = true;
                                if ($network === 'MTN') {
                                    $chkVerified->execute([$recip]);
                                    if (!$chkVerified->fetch()) {
                                        $isVer = false;
                                    }
                                }
                                if (!$isVer) {
                                    // Unverified MTN - store as accepted, skip API
                                    $stmtIns->execute([$userId, 'MTN EXPRESS', $recip, $order['amount'], 'accepted', 'verification', 'Submitted to MTN Verification (Accepted).']);
                                } else {
                                    // Verified - insert as pending, schedule for API dispatch
                                    $stmtIns->execute([$userId, $network, $recip, $order['amount'], 'pending', 'store', '']);
                                    $insertedOrders[] = ['id' => (int)$pdo->lastInsertId(), 'recip' => $recip, 'amount' => $order['amount']];
                                }
                            }
                        }
                    }

                    $pdo->commit();

                    // --- Dispatch each verified order to the supplier API (outside transaction) ---
                    foreach ($insertedOrders as $ins) {
                        $orderId  = $ins['id'];
                        $recip    = $ins['recip'];
                        $gbAmount = $ins['amount'];

                        // Pick supplier for this recipient based on admin priority & phone routing
                        $resolvedSupp = resolve_order_supplier($network, $gbAmount, $recip, $pdo);
                        $supplierApi  = $resolvedSupp['api'];
                        $supplierName = $resolvedSupp['name'];

                        if (!$supplierApi) {
                            $pdo->prepare("UPDATE bundle_sends SET status = 'pending', message = 'API Offline - Pending dispatch' WHERE id = ?")->execute([$orderId]);
                            continue; // leave as pending if no supplier available
                        }

                        // MTNUP2U / MTNUP2U PORTAL beneficiary check
                        if ($supplierName === 'MTNUP2U' || $supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') {
                            $recipientPhone = preg_replace('/[^0-9]/', '', $recip);
                            if (strlen($recipientPhone) === 12 && substr($recipientPhone, 0, 3) === '233') {
                                $recipientPhone = '0' . substr($recipientPhone, 3);
                            }
                            $stmtLocalCheck = $pdo->prepare("SELECT 1 FROM mtn_verified_numbers WHERE phone_number = ? LIMIT 1");
                            $stmtLocalCheck->execute([$recipientPhone]);
                            $inLocalDb  = (bool)$stmtLocalCheck->fetchColumn();
                            $activeApi  = ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') ? $mtnUp2GbApi : $mtnUp2uApi;
                            $inMtnUp2u  = $activeApi->isBeneficiary($recip, $network);
                            if ($inMtnUp2u && !$inLocalDb) {
                                $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$recipientPhone]);
                            }
                        }

                        $sendAmount = $gbAmount;
                        if ($supplierName === 'Supplier 1' && strtolower($network) === 'ishare') {
                            $sendAmount = $gbAmount * 1000;
                        }

                        try {
                            $apiResult = $supplierApi->sendBundle($network, $recip, $sendAmount, $orderId, 'store');
                            if ($apiResult && $apiResult['success']) {
                                $apiStatus = ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') ? MtnUp2GbApi::mapStatus($network, $apiResult)
                                           : ($supplierName === 'MTNUP2U'    ? MtnUp2uApi::mapStatus($network, $apiResult)
                                           : ($supplierName === 'NITGHT'     ? NitghtApi::mapStatus($network, $apiResult)
                                           : ($supplierName === 'Supplier 1' ? SupplierApi::mapStatus($network, $apiResult)
                                           : ($supplierName === 'Supplier 2' ? Supplier2Api::mapStatus($network, $apiResult)
                                           : ($supplierName === 'Supplier 3' ? Supplier3Api::mapStatus($network, $apiResult)
                                           : Supplier5Api::mapStatus($network, $apiResult))))));
                                if ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB' || $supplierName === 'MTNUP2U') {
                                    if ($apiStatus === 'processing' || $apiStatus === 'pending' || $apiStatus === 'accepted') $apiStatus = 'waiting';
                                }
                                if (strtolower($network) === 'ishare') $apiStatus = 'completed';
                                if ($supplierName === 'Supplier 2' && $apiStatus === 'processing') $apiStatus = 'unverified';
                                if ($supplierName === 'Supplier 3' && $apiStatus === 'processing') $apiStatus = 'waiting';
                                $suppData = $apiResult['data'] ?? [];
                                $suppId = $apiResult['reference'] ?? $apiResult['order_id'] ?? $apiResult['transaction_id'] ?? $apiResult['id'] ?? $suppData['reference'] ?? $suppData['reference_id'] ?? $suppData['order_id'] ?? $suppData['transaction_id'] ?? $suppData['trx_ref'] ?? $suppData['id'] ?? '';
                                $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status=?, message=? WHERE id=? AND status NOT IN ('completed','successful','sucessfully','failed','refunded')");
                                $stmtUpdate->execute([$apiStatus, "Sent to {$supplierName}. Order ID: {$suppId}", $orderId]);
                                if ($stmtUpdate->rowCount() > 0 && $apiStatus === 'completed') {
                                    require_once __DIR__ . '/classes/MailHelper.php';
                                    MailHelper::sendOrderCompletionEmail($pdo, $orderId);
                                }
                            } else {
                                $errReason = $apiResult['message'] ?? 'Unknown error';
                                if (isBeneficiaryError($errReason)) {
                                    $status = 'unverified';
                                    $message = 'Beneficiary Pending List';
                                    $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?")
                                        ->execute([$status, $message, $orderId]);
                                    $_SESSION['store_success'] = 'Order received! Recipient number is pending beneficiary list addition.';
                                } elseif (isInsufficientBalanceError($errReason)) {
                                    $status = 'pending';
                                    $message = 'Pending manual dispatch';
                                    $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?")
                                        ->execute([$status, $message, $orderId]);
                                    $_SESSION['store_success'] = 'Order received successfully. It is currently under review by our fulfillment team.';
                                } else {
                                    $pdo->prepare("UPDATE bundle_sends SET status='failed', message=?, refund_transferred=1 WHERE id=?")
                                        ->execute([$errReason, $orderId]);
                                    if (!$freeMode && $itemCost > 0) {
                                        addWalletTransaction($pdo, $userId, $itemCost, 'credit', 'REFUND-' . $orderId, "Refund for failed supplier order #{$orderId}: " . $errReason);
                                    }
                                    $errors[] = "Failed for {$recip}: " . htmlspecialchars($errReason, ENT_QUOTES, 'UTF-8');
                                }
                            }
                        } catch (Exception $apiEx) {
                            $errReason = $apiEx->getMessage();
                            if (isBeneficiaryError($errReason)) {
                                $status = 'unverified';
                                $message = 'Beneficiary Pending List';
                                $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?")
                                    ->execute([$status, $message, $orderId]);
                                $_SESSION['store_success'] = 'Order received! Recipient number is pending beneficiary list addition.';
                            } elseif (isInsufficientBalanceError($errReason)) {
                                $status = 'pending';
                                $message = 'Pending manual dispatch';
                                $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?")
                                    ->execute([$status, $message, $orderId]);
                                $_SESSION['store_success'] = 'Order received successfully. It is currently under review by our fulfillment team.';
                            } else {
                                $pdo->prepare("UPDATE bundle_sends SET status='failed', message=?, refund_transferred=1 WHERE id=?")
                                    ->execute(["API Exception: " . $errReason, $orderId]);
                                if (!$freeMode && $itemCost > 0) {
                                    addWalletTransaction($pdo, $userId, $itemCost, 'credit', 'REFUND-' . $orderId, "Refund for failed supplier order #{$orderId}: " . $errReason);
                                }
                                $errors[] = "API Error: " . htmlspecialchars($errReason, ENT_QUOTES, 'UTF-8');
                            }
                        }
                    }
                    // -------------------------------------------------------------------------

                    $_SESSION['store_success'] = 'Order received successfully. It is currently under review by our fulfillment team.';

                    header('Location: ' . APP_URL . 'data_bundles');
                    exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Order failed: ' . htmlspecialchars($e->getMessage());
                    flashStoreErrors($errors);
                }
            }
        }
    } else {
        // Single order
        $validPhone = normalizeAndValidatePhone($recipient, $network);
        
        $isRegistered = true;
        if ($network === 'MTN' && $validPhone) {
            $stmt = $pdo->prepare("SELECT phone_number FROM mtn_verified_numbers WHERE phone_number = ?");
            $stmt->execute([$validPhone]);
            if (!$stmt->fetch()) {
                $isRegistered = false;
                require_once __DIR__ . '/classes/NitghtApi.php';
                require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
                require_once __DIR__ . '/classes/MtnUp2uApi.php';
                require_once __DIR__ . '/classes/JaybartWhitelist.php';
                require_once __DIR__ . '/classes/Supplier1Whitelist.php';

                if (NitghtApi::isEnabled() && NitghtApi::isNetworkEnabled('MTN')) {
                    try {
                        $nitghtApi = new NitghtApi();
                        if ($nitghtApi->isBeneficiary($validPhone, 'MTN')) {
                            $isRegistered = true;
                            $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$validPhone]);
                        }
                    } catch (Exception $ex) {}
                }

                if (!$isRegistered && MtnUp2uPortalApi::isNetworkEnabled('MTN')) {
                    try {
                        $up2gb = new MtnUp2uPortalApi();
                        if ($up2gb->isBeneficiary($validPhone, 'MTN')) {
                            $isRegistered = true;
                            $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$validPhone]);
                        }
                    } catch (Exception $ex) {}
                }
                if (!$isRegistered && MtnUp2uApi::isNetworkEnabled('MTN')) {
                    try {
                        $up2u = new MtnUp2uApi();
                        if ($up2u->isBeneficiary($validPhone, 'MTN')) {
                            $isRegistered = true;
                            $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$validPhone]);
                        }
                    } catch (Exception $ex) {}
                }
                if (!$isRegistered && (JaybartWhitelist::isWhitelisted($validPhone) || Supplier1Whitelist::isWhitelisted($validPhone))) {
                    $isRegistered = true;
                }
            }
        }

        $assignedSupplier = null;
        if ($validPhone) {
            $stmtRoute = $pdo->prepare("SELECT supplier FROM supplier_routing WHERE phone_number = ? LIMIT 1");
            $stmtRoute->execute([$validPhone]);
            $assignedSupplier = $stmtRoute->fetchColumn();
        }

        require_once __DIR__ . '/classes/NitghtApi.php';
        $nitghtApiCheck = NitghtApi::isNetworkEnabled($network, $gb_amount) ? new NitghtApi() : null;
        $canBypassVerification = ($nitghtApiCheck !== null || $assignedSupplier === '7');
        
        $cost = 0;
        $pricingArray = ($network === 'MTN') ? $mtnPrices : (($network === 'Telecel') ? $telecelPrices : $isharePrices);
        if (!$isRegistered && !$canBypassVerification && $network === 'MTN') {
            $pricingArray = $mtnExpressPrices;
        }
        foreach ($pricingArray as $pkg) {
            if ((float)$pkg['gb_amount'] === (float)$gb_amount) {
                $cost = (float)$pkg['price_ghs'];
                break;
            }
        }

        $confirmUnverified = $_POST['confirm_unverified'] ?? '0';

        $refundRestriction = ($validPhone && function_exists('getPhoneRefundRestriction')) ? getPhoneRefundRestriction($pdo, $validPhone) : null;

        if ($gb_amount < 1 || $gb_amount > 100) {
            $errors[] = 'Please select a valid bundle size.';
        } elseif (!$validPhone) {
            $errors[] = 'Invalid ' . $network . ' phone number.';
        } elseif ($refundRestriction) {
            $errors[] = $refundRestriction['message'];
        } elseif (!$isRegistered && !$canBypassVerification && $confirmUnverified !== '1') {
            $errors[] = 'The phone number ' . htmlspecialchars($validPhone) . ' is not added to our beneficiary list. Please confirm to submit for MTN Verification.';
        } elseif ($cost <= 0) {
            $errors[] = 'Selected bundle size is not available.';
        } elseif (!$freeMode && $cost > $walletBalance) {
            $errors[] = 'Insufficient wallet balance. Cost is GHS ' . number_format($cost, 2) . ' but your balance is GHS ' . number_format($walletBalance, 2) . '.';
        } else {
            try {
                $chkPending = $pdo->prepare("SELECT 1 FROM bundle_sends WHERE recipient_phone = ? AND status NOT IN ('completed', 'successful', 'success', 'sucessfully', 'successfully', 'failed', 'refunded', 'rejected') LIMIT 1");
                $chkPending->execute([$validPhone]);
                $hasActiveOrder = (bool)$chkPending->fetchColumn();

                if ($hasActiveOrder) {
                    $initialStatus = 'multiple_order';
                    $orderNetwork  = $network;
                    $orderChannel  = 'store';
                    $orderMsg      = 'Duplicate order placed while previous order is still pending/uncompleted.';
                } else {
                    $initialStatus = (!$isRegistered && !$canBypassVerification) ? 'accepted' : 'pending';
                    $orderNetwork  = (!$isRegistered && !$canBypassVerification) ? 'MTN EXPRESS' : $network;
                    $orderChannel  = (!$isRegistered && !$canBypassVerification) ? 'verification' : 'store';
                    $orderMsg      = (!$isRegistered && !$canBypassVerification) ? 'New beneficiary number. Submitted to MTN Verification (Accepted).' : '';
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, status, channel, message) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $orderNetwork, $validPhone, $gb_amount, $initialStatus, $orderChannel, $orderMsg]);
                $orderId = (int)$pdo->lastInsertId();
                
                // Log wallet debit
                $ref = 'STR-' . mt_rand(10000, 99999);
                $descr = 'Purchased physical item: ' . $network . ' ' . $gb_amount . 'GB bundle to ' . $validPhone;
                if ($freeMode) {
                    addFreeModeTransaction($pdo, $userId, $cost, $ref, $descr);
                } else {
                    addWalletTransaction($pdo, $userId, $cost, 'debit', $ref, $descr);
                }
                $pdo->commit();
                
                if ($isRegistered || $canBypassVerification) {
                
                // Call Supplier API for verified numbers
                    $mtnUp2GbApi = MtnUp2uPortalApi::isNetworkEnabled($network, $gb_amount) ? new MtnUp2uPortalApi() : null;
                    $mtnUp2uApi  = MtnUp2uApi::isNetworkEnabled($network, $gb_amount)  ? new MtnUp2uApi()  : null;
                    $nitghtApi   = $nitghtApiCheck;
                    $s1Api = SupplierApi::isNetworkEnabled($network, $gb_amount) ? new SupplierApi() : null;
                    $s2Api = (strtolower($network) === 'mtn' && Supplier2Api::isNetworkEnabled('mtn', $gb_amount)) ? new Supplier2Api() : null;
                    $s3Api = (strtolower($network) === 'mtn' && Supplier3Api::isNetworkEnabled('mtn', $gb_amount)) ? new Supplier3Api() : null;
                    $s4Api = Supplier5Api::isNetworkEnabled($network, $gb_amount) ? new Supplier5Api() : null;
                    $backupApi = BackupApi::isNetworkEnabled($network, $gb_amount) ? new BackupApi() : null;
                    
                    // Pick supplier for this recipient based on admin priority & phone routing
                    $resolvedSupp = resolve_order_supplier($network, $gb_amount, $validPhone, $pdo);
                    $supplierApi  = $resolvedSupp['api'];
                    $supplierName = $resolvedSupp['name'];
                    
                    if ($supplierApi) {
                        $sendAmount = $gb_amount;
                        if ($supplierName === 'Supplier 1' && strtolower($network) === 'ishare') {
                            $sendAmount = $gb_amount * 1000;
                        }
                        
                        if (($supplierName === 'MTNUP2U' || $supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') && (strtolower($network) === 'mtn' || strpos(strtolower($network), 'mtn') !== false)) {
                            $recipientPhone = preg_replace('/[^0-9]/', '', $validPhone);
                            if (strlen($recipientPhone) === 12 && substr($recipientPhone, 0, 3) === '233') {
                                $recipientPhone = '0' . substr($recipientPhone, 3);
                            }

                            $stmtLocalCheck = $pdo->prepare("SELECT 1 FROM mtn_verified_numbers WHERE phone_number = ? LIMIT 1");
                            $stmtLocalCheck->execute([$recipientPhone]);
                            $inLocalDb = (bool)$stmtLocalCheck->fetchColumn();

                            $activeApi = ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') ? $mtnUp2GbApi : $mtnUp2uApi;
                            $inMtnUp2u = $activeApi->isBeneficiary($validPhone, $network);

                            if ($inMtnUp2u && !$inLocalDb) {
                                $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$recipientPhone]);
                            }
                        }
                        
                        $apiResult = $supplierApi ? $supplierApi->sendBundle($network, $validPhone, $sendAmount, $orderId, 'store') : null;
                        if ($apiResult && $apiResult['success']) {
                            $status = Supplier5Api::mapStatus($network, $apiResult);
                            if ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') {
                                $status = MtnUp2GbApi::mapStatus($network, $apiResult);
                                if ($status === 'processing' || $status === 'pending' || $status === 'accepted') $status = 'waiting';
                            } elseif ($supplierName === 'MTNUP2U') {
                                $status = MtnUp2uApi::mapStatus($network, $apiResult);
                                if ($status === 'processing' || $status === 'pending' || $status === 'accepted') $status = 'waiting';
                            } elseif ($supplierName === 'NITGHT') {
                                $status = NitghtApi::mapStatus($network, $apiResult);
                                if ($status === 'processing' || $status === 'pending' || $status === 'accepted') $status = 'waiting';
                            } elseif ($supplierName === 'Supplier 1') {
                                $status = SupplierApi::mapStatus($network, $apiResult);
                            } elseif ($supplierName === 'Supplier 2') {
                                $status = Supplier2Api::mapStatus($network, $apiResult);
                                // Override for Supplier 2 if mapping failed to processing
                                if ($status === 'processing') $status = 'unverified';
                            } elseif ($supplierName === 'Supplier 3') {
                                $status = Supplier3Api::mapStatus($network, $apiResult);
                                // Override for Supplier 3 if needed
                                if ($status === 'processing') $status = 'waiting';
                            }
                            
                            // Instant complete for Ishare
                            if (strtolower($network) === 'ishare') {
                                $status = 'completed';
                                require_once __DIR__ . '/classes/SmsApi.php';
                                $smsApiInst = new SmsApi();
                                $smsMsg = "Your {$gb_amount}GB iShare bundle has been processed successfully.";
                                $smsApiInst->sendSms($validPhone, $smsMsg, null, 'ISHARE');
                            }
                            $supplierData = $apiResult['data'] ?? [];
                            $suppId = $apiResult['reference'] ?? $apiResult['order_id'] ?? $apiResult['transaction_id'] ?? $apiResult['id'] ?? $supplierData['reference'] ?? $supplierData['reference_id'] ?? $supplierData['order_id'] ?? $supplierData['transaction_id'] ?? $supplierData['trx_ref'] ?? $supplierData['id'] ?? '';
                            $message = "Sent to " . $supplierName . ". Order ID: " . $suppId;
                            
                            $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ?, updated_at = NOW() WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                            $stmtUpdate->execute([$status, $message, $orderId]);
                            if ($stmtUpdate->rowCount() > 0 && $status === 'completed') {
                                require_once __DIR__ . '/classes/MailHelper.php';
                                MailHelper::sendOrderCompletionEmail($pdo, $orderId);
                            }
                            
                            $_SESSION['store_success'] = 'Order received successfully. It is currently under review by our fulfillment team.';
                        } else {
                            require_once __DIR__ . '/classes/SupplierApi.php';
                            $errReason = SupplierApi::sanitizeErrorMessage($apiResult['message'] ?? 'Unknown error');
                            
                            if (isBeneficiaryError($errReason)) {
                                $status = 'unverified';
                                $message = 'Beneficiary Pending List';
                                
                                $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?");
                                $stmtUpdate->execute([$status, $message, $orderId]);
                                
                                $_SESSION['store_success'] = 'Order received! Recipient number is pending beneficiary list addition.';
                            } elseif (isInsufficientBalanceError($errReason)) {
                                $status = 'pending';
                                $message = 'Pending manual dispatch';
                                
                                $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?");
                                $stmtUpdate->execute([$status, $message, $orderId]);
                                
                                $_SESSION['store_success'] = 'Order received successfully. It is currently under review by our fulfillment team.';
                            } elseif (($supplierName === 'MTNUP2U' || $supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') && stripos($errReason, 'Unknown error') !== false) {
                                $status = 'pending';
                                $message = 'Supplier Error: Unknown error - Awaiting manual review';
                                
                                $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?");
                                $stmtUpdate->execute([$status, $message, $orderId]);
                                
                                $_SESSION['store_success'] = 'Order received! Note: Supplier Error: Unknown error - Awaiting manual review.';
                            } else {
                                $status = 'failed';
                                $message = $errReason;

                                $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ?, refund_transferred = 1 WHERE id = ?");
                                $stmtUpdate->execute([$status, $message, $orderId]);

                                // Refund user wallet immediately so money is NOT deducted
                                if (!$freeMode && $cost > 0) {
                                    addWalletTransaction($pdo, $userId, $cost, 'credit', 'REFUND-' . $orderId, "Refund for failed supplier order #{$orderId}: " . $errReason);
                                }

                                unset($_SESSION['store_success']);
                                $errors[] = htmlspecialchars($errReason, ENT_QUOTES, 'UTF-8');
                            }
                        }
                    } else {
                        if ($notWhitelistedForS1) {
                            // Put status at pending and warn the user. Do NOT fail or refund.
                            $status = 'pending';
                            $message = 'Number not whitelisted for Supplier 1. Awaiting manual review.';
                            
                            $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                            $stmtUpdate->execute([$status, $message, $orderId]);
                            
                            $_SESSION['store_success'] = 'Order received! Note: This number is not whitelisted for automated delivery and is pending manual review by the admin.';
                        } else {
                            $_SESSION['store_success'] = 'Order received successfully. It is currently under review by our fulfillment team.';
                        }
                    }
                    }
                    
                    $walletBalance = getUserWalletBalance($pdo, $userId);
                    
                    if (empty($errors)) {
                        header('Location: ' . APP_URL . 'data_bundles');
                        exit;
                    }

                    flashStoreErrors($errors);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Order failed: ' . htmlspecialchars($e->getMessage());
                    flashStoreErrors($errors);
                }
            }
        }
    }

$pageTitle = 'Data Bundles';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Apex Prime">
<link rel="apple-touch-icon" href="/image/icon-192.png">
<link rel="manifest" href="/manifest.json">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no, viewport-fit=cover">
<title>Agent Store · <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
<meta name="theme-color" content="#1e3a8a">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<script src="/js/script.js" defer></script>
<style>
  body {
      font-family: 'Inter', sans-serif;
      background-color: var(--bg-color);
      color: var(--text-primary);
      margin: 0;
      padding: 0;
      transition: background-color 0.3s ease, color 0.3s ease;
      overflow-y: auto !important;
      -webkit-overflow-scrolling: touch;
  }
  
  .store-container {
      max-width: 480px;
      margin: 1.5rem auto 3rem auto;
      padding: 1.5rem 1rem 2rem;
      background: var(--surface-color);
      border-radius: 16px;
      box-shadow: var(--shadow-md);
      border: 1px solid var(--border-color);
  }
  
  /* Modern Switch Container */
  .switch-container {
      display: flex;
      background: var(--border-color);
      border-radius: 99px;
      padding: 4px;
      gap: 4px;
      margin: 0 auto 1.5rem;
      max-width: 280px;
      transition: background-color 0.3s;
  }
  .switch-btn {
      flex: 1;
      padding: 0.45rem 0.75rem;
      border: none;
      border-radius: 99px;
      font-size: 0.75rem;
      font-weight: 500;
      cursor: pointer;
      background: transparent;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      transition: all 0.2s ease;
  }
  .switch-btn.active {
      background: var(--primary);
      color: white;
      box-shadow: 0 4px 10px rgba(52, 84, 209, 0.2);
  }

  /* Network Selector Pills / Buttons */
  .network-pill-btn {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      border-radius: 12px;
      border: 2px solid var(--border-color);
      background: var(--surface-color);
      cursor: pointer;
      transition: all 0.2s;
      padding: 0.4rem;
      gap: 0.25rem;
      outline: none;
      -webkit-tap-highlight-color: transparent;
  }
  .network-pill-btn span {
      font-size: 0.7rem;
      font-weight: 500;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.02em;
  }
  .network-pill-btn.active-mtn { border-color: #facc15; background: #fffdf5; }
  .network-pill-btn.active-mtn span { color: #b45309; }
  
  .network-pill-btn.active-tel { border-color: #ef4444; background: #fff5f5; }
  .network-pill-btn.active-tel span { color: #b91c1c; }
  
  .network-pill-btn.active-ish { border-color: #3b82f6; background: #eff6ff; }
  .network-pill-btn.active-ish span { color: #1d4ed8; }

  /* Dark mode active selector pills overrides */
  .dark .network-pill-btn.active-mtn { border-color: rgba(245, 158, 11, 0.4); background: rgba(245, 158, 11, 0.12); }
  .dark .network-pill-btn.active-mtn span { color: #fbbf24; }

  .dark .network-pill-btn.active-tel { border-color: rgba(239, 68, 68, 0.4); background: rgba(239, 68, 68, 0.12); }
  .dark .network-pill-btn.active-tel span { color: #f87171; }

  .dark .network-pill-btn.active-ish { border-color: rgba(59, 130, 246, 0.4); background: rgba(59, 130, 246, 0.12); }
  .dark .network-pill-btn.active-ish span { color: #60a5fa; }

  /* Form and Select styling (16px rounded corners, drop shadows) */
  .store-card-form {
      padding: 0;
  }

  .form-control-modal {
      width: 100%;
      padding: 0.7rem 0.85rem;
      border: 2px solid var(--border-color);
      border-radius: 12px;
      font-family: 'Inter', sans-serif;
      font-size: 0.88rem;
      font-weight: 500;
      outline: none;
      box-sizing: border-box;
      background: var(--bg-color);
      color: var(--text-primary);
      transition: all 0.2s ease;
  }
  .form-control-modal:focus {
      border-color: var(--primary);
      background: var(--surface-color);
      box-shadow: 0 0 0 4px var(--primary-light);
  }

  /* Dropdown arrow customizer */
  select.form-control-modal {
      appearance: none;
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2364748b'><path d='M7 10l5 5 5-5z'/></svg>");
      background-repeat: no-repeat;
      background-position: right 1rem center;
      background-size: 1.25rem;
      padding-right: 2.5rem;
  }
  .dark select.form-control-modal {
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2394a3b8'><path d='M7 10l5 5 5-5z'/></svg>");
  }

  /* Buy Now Buttons (Thumb friendly, minimum 44px) */
  .buy-btn {
      width: 100%;
      height: 40px;
      min-height: 40px;
      border: none;
      border-radius: 10px;
      font-size: 0.85rem;
      font-weight: 500;
      color: white;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      background: var(--primary);
      transition: background 0.2s ease;
  }
  .buy-btn:hover { background: var(--primary-hover); }

  /* Modal overlay */
  .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(6px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 99999;
      padding: 1rem;
  }
  .modal-content {
      background: var(--surface-color);
      color: var(--text-primary);
      border-radius: 20px;
      padding: 1.75rem 1.5rem;
      width: 100%;
      max-width: 360px;
      box-shadow: var(--shadow-lg);
      transition: background-color 0.3s, color 0.3s;
  }

  /* Batch UI styling */
  .batch-textarea {
      width: 100%;
      min-height: 120px;
      padding: 0.75rem;
      border: 2px solid var(--border-color);
      border-radius: 12px;
      font-family: monospace;
      font-size: 0.8rem;
      outline: none;
      box-sizing: border-box;
      resize: vertical;
      background: var(--bg-color);
      color: var(--text-primary);
      transition: all 0.2s ease;
  }
  .batch-textarea:focus {
      border-color: var(--primary);
      background: var(--surface-color);
  }

  /* Phone input shortcut buttons (My Number, Paste, Clear) */
  .phone-shortcut-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      background: var(--bg-color);
      border: 1px solid var(--border-color);
      padding: 0.25rem 0.55rem;
      border-radius: 6px;
      font-size: 0.68rem;
      font-weight: 600;
      color: var(--text-secondary);
      cursor: pointer;
      transition: all 0.2s ease;
      outline: none;
      -webkit-tap-highlight-color: transparent;
  }
  .phone-shortcut-btn:hover {
      background: var(--surface-color);
      border-color: var(--primary);
      color: var(--primary);
      transform: translateY(-1px);
  }
  .phone-shortcut-btn:active {
      transform: translateY(0);
  }
</style>

    <!-- Structured Data: Service Schema -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Service",
      "name": "Data Bundle Store — Apex Prime",
      "url": "https://apexprime.club/store",
      "description": "Buy and send MTN, Telecel, and AT mobile data bundles in Ghana instantly at competitive prices.",
      "provider": {
        "@type": "Organization",
        "name": "Apex Prime",
        "url": "https://apexprime.club"
      },
      "areaServed": {
        "@type": "Country",
        "name": "Ghana"
      },
      "serviceType": "Mobile Data Bundles",
      "hasOfferCatalog": {
        "@type": "OfferCatalog",
        "name": "Ghana Data Bundles",
        "itemListElement": [
          {
            "@type": "Offer",
            "itemOffered": {
              "@type": "Service",
              "name": "MTN Data Bundles",
              "description": "Affordable MTN Ghana internet data bundles"
            }
          },
          {
            "@type": "Offer",
            "itemOffered": {
              "@type": "Service",
              "name": "Telecel Data Bundles",
              "description": "Affordable Telecel Ghana internet data bundles"
            }
          },
          {
            "@type": "Offer",
            "itemOffered": {
              "@type": "Service",
              "name": "AT (AirtelTigo) Data Bundles",
              "description": "Affordable AT Ghana internet data bundles"
            }
          }
        ]
      },
      "breadcrumb": {
        "@type": "BreadcrumbList",
        "itemListElement": [
          {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://apexprime.club/"},
          {"@type": "ListItem", "position": 2, "name": "Store", "item": "https://apexprime.club/store"}
        ]
      }
    }
    </script>
</head>
<body>

<!-- Alert Modals -->
<?php if ($success): ?>
<div id="successModal" class="modal-overlay" style="display:flex;">
    <div class="modal-content" style="text-align:center;">
        <div style="width:60px;height:60px;background:#22c55e;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <i class="fas fa-check" style="color:white;font-size:1.5rem;"></i>
        </div>
        <h2 style="font-size:1.15rem;font-weight:600;margin-top:0;margin-bottom:0.5rem;">Order Successful</h2>
        <p style="font-size:0.85rem;color:#64748b;margin-bottom:1.5rem;line-height:1.5;"><?= htmlspecialchars($success) ?></p>
        <button onclick="window.location.href=''" class="buy-btn" style="background:#22c55e; width: 100%;">OK</button>
    </div>
</div>
<script>
    if (typeof playOrderSuccessSound === 'function') {
        playOrderSuccessSound();
    } else {
        window.addEventListener('load', function() {
            if (typeof playOrderSuccessSound === 'function') playOrderSuccessSound();
        });
    }
</script>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div id="errorModal" class="modal-overlay" style="display:flex;">
    <div class="modal-content">
        <div style="width:60px;height:60px;background:#ef4444;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <i class="fas fa-exclamation-triangle" style="color:white;font-size:1.35rem;"></i>
        </div>
        <h2 style="font-size:1.15rem;font-weight:600;text-align:center;margin-top:0;margin-bottom:0.75rem;">Failed to Order</h2>
        <div style="font-size:0.85rem;color:#64748b;margin-bottom:1.5rem;line-height:1.5;">
            <ul style="margin:0;padding-left:1.2rem;">
                <?php foreach ($errors as $e): ?>
                    <li style="margin-bottom:0.25rem;"><?= $e ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <button onclick="document.getElementById('errorModal').style.display='none'" class="buy-btn" style="background:#ef4444; width: 100%;">Dismiss</button>
    </div>
</div>
<?php endif; ?>

<!-- Confirmation Modal -->
<div id="confirmModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="text-align:center;">
        <div style="width:60px;height:60px;background:var(--primary,#4f46e5);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <i class="fas fa-question" style="color:white;font-size:1.35rem;"></i>
        </div>
        <h2 style="font-size:1.15rem;font-weight:600;margin-top:0;margin-bottom:0.5rem;color:var(--text-primary);">Confirm Purchase</h2>
        <p style="font-size:0.85rem;color:#64748b;margin-bottom:1rem;line-height:1.5;" id="confirmModalText">Are you sure you want to purchase?</p>
        
        <!-- Airtime/Credit Debt Notice (shown for MTN Network) -->
        <div id="mtnDebtWarningBox" style="display:none; background-color: #fff7ed; border: 1.5px solid #ffedd5; border-left: 4px solid #ea580c; border-radius: 12px; padding: 0.9rem 1rem; margin-bottom: 1.25rem; text-align: left; font-family: 'Inter', sans-serif;">
            <div style="display: flex; align-items: center; gap: 0.45rem; color: #c2410c; font-weight: 800; font-size: 0.85rem; margin-bottom: 0.35rem;">
                <i class="fas fa-exclamation-circle" style="font-size: 0.95rem;"></i> Airtime/Credit Debt Notice
            </div>
            <div style="font-size: 0.8rem; color: #9a3412; line-height: 1.45; font-weight: 500;">
                If you have unpaid credit or airtime debts, please settle them before placing new orders. Data cannot be delivered to numbers with outstanding balances.
            </div>
        </div>

        <div style="display:flex; gap: 0.5rem; justify-content: center;">
            <button id="cancelActionBtn" onclick="cancelPurchase()" class="buy-btn" style="width: 50%; background:var(--surface-color); border:2px solid var(--border-color); color:var(--text-primary);">Cancel</button>
            <button id="confirmActionBtn" onclick="proceedWithPurchase()" class="buy-btn" style="width: 50%;">Confirm</button>
        </div>
    </div>
</div>

<!-- NEW BENEFICIARY WARNING MODAL -->
<div id="beneficiaryWarningModal" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 999999; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 18px; padding: 1.25rem 1.2rem; width: 100%; max-width: 400px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); animation: scaleUp 0.25s ease-out; position: relative;">

        <!-- Close X Button -->
        <button type="button" onclick="closeBeneficiaryModal()" style="position: absolute; top: 0.85rem; right: 0.9rem; background: transparent; border: none; font-size: 1.2rem; color: #94a3b8; cursor: pointer; line-height: 1; padding: 0.2rem; outline: none;">
            &times;
        </button>

        <!-- Warning Header -->
        <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.9rem;">
            <h3 style="margin: 0; font-size: 1rem; font-weight: 800; color: #0f172a; font-family: 'Inter', sans-serif;">
                New Beneficiary Warning
            </h3>
        </div>

        <!-- Compact Red Warning Box -->
        <div style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); border-radius: 12px; padding: 0.85rem 1rem; margin-bottom: 0.9rem; text-align: left; font-family: 'Inter', sans-serif; color: #ffffff; box-shadow: 0 3px 12px rgba(220, 38, 38, 0.3);">

            <!-- Phone number row -->
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.6rem; font-size: 0.82rem;">
                <span style="color: #fecaca;">Number:</span>
                <strong id="detectedBeneficiaryPhoneText" style="background-color: #ffffff; color: #000000; font-weight: 800; padding: 2px 8px; border-radius: 5px; font-family: monospace; font-size: 0.82rem;">0249115328</strong>
                <span style="color: #fecaca; font-size: 0.75rem;">— not in beneficiary list</span>
            </div>

            <!-- Fee row -->
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.65rem; font-size: 0.82rem;">
                <span style="color: #fecaca;">MTN Verification Fee:</span>
                <strong id="detectedBeneficiaryFeeText" style="background-color: #ffffff; color: #2563eb; font-weight: 800; padding: 2px 10px; border-radius: 5px; font-size: 0.85rem;">GHS 0.00</strong>
            </div>

            <!-- Info row -->
            <div style="font-size: 0.78rem; color: #fef08a; font-weight: 600; margin-bottom: 0.5rem;">
                Order will be submitted for MTN Verification.
            </div>

            <!-- Refund warning -->
            <div style="font-size: 0.76rem; color: #ffffff; font-weight: 800; background: rgba(0,0,0,0.2); padding: 5px 10px; border-radius: 6px;">
                Orders cannot be refunded or canceled!
            </div>

            <!-- Badges container for multiple numbers -->
            <div id="newBeneficiariesBadges" style="display: none; flex-wrap: wrap; gap: 0.4rem; max-height: 80px; overflow-y: auto; margin-top: 0.65rem; padding: 0.4rem; background: #ffffff; border-radius: 6px;">
                <!-- Dynamically populated -->
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem;">
            <button type="button" onclick="closeBeneficiaryModal()" style="padding: 0.6rem 0.8rem; border-radius: 10px; font-size: 0.82rem; font-weight: 700; background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif;">
                Cancel
            </button>
            <button type="button" onclick="confirmBeneficiaryAnyway()" id="beneficiaryConfirmBtn" style="padding: 0.6rem 0.8rem; border-radius: 10px; font-size: 0.82rem; font-weight: 700; background-color: #dc2626; color: #ffffff; border: none; cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif; box-shadow: 0 3px 10px rgba(220, 38, 38, 0.35);">
                Continue Anyway
            </button>
        </div>

    </div>
</div>

<div class="app-wrapper">
    <main class="main-content" style="padding:0;">
        <?php include __DIR__ . '/header.php'; ?>

        <div class="store-container">
            <!-- Store Banner -->
            <div style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 1.25rem 1.5rem; text-align: center; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(37,99,235,0.25); border-radius: 0 0 24px 24px; margin-top: -1px;">
                <h1 style="font-size: 1.35rem; font-weight: 800; color: #ffffff; margin-bottom: 0.4rem; letter-spacing: 0.02em; text-transform: uppercase;"><i class="fas fa-store" style="margin-right: 8px;"></i> AGENT STORE</h1>
                <p style="font-size: 0.9rem; font-weight: 500; color: #ffffff; opacity: 0.9; margin: 0 auto; line-height: 1.4; max-width: 600px;">Buy bundle packages instantly using your wallet balance.</p>
            </div>

            <!-- Modern Segmented Mode Switcher -->
            <div class="switch-container">
                <button type="button" class="switch-btn active" id="btnModeSingle" onclick="switchStoreMode('single')">Single</button>
                <button type="button" class="switch-btn" id="btnModeBulk" onclick="switchStoreMode('bulk')">Batch Dispatch</button>
            </div>

            <!-- SINGLE BUNDLE STORE SECTION (DROPDOWN BASED) -->
            <div id="storeSingleSection">
                <div class="card">
                    <form method="POST" action="" id="buySingleForm" onsubmit="return validateSingleSubmit(event)">
                        <input type="hidden" name="action" value="buy">
                        <input type="hidden" name="mode" value="single">
                        <input type="hidden" name="network" id="singleNetworkField" value="MTN">
                        <input type="hidden" name="confirm_unverified" id="singleConfirmUnverified" value="0">
                        
                        <!-- Network Selector Squares inside the card -->
                        <div style="margin-bottom: 1.25rem;">
                            <label style="font-size: 0.72rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; display: block; margin-bottom: 0.5rem; text-align: center;">Select Network</label>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
                                <button type="button" id="pill-MTN" onclick="filterNetwork('MTN')" class="network-pill-btn active-mtn">
                                    <img src="/image/MTN.jpg" style="width: 32px; height: 32px; border-radius: 6px; object-fit: cover; box-shadow: 0 2px 4px rgba(0,0,0,0.05);" alt="MTN">
                                    <span>MTN</span>
                                </button>
                                <button type="button" id="pill-Telecel" onclick="filterNetwork('Telecel')" class="network-pill-btn">
                                    <img src="/image/TELECEL.jpg" style="width: 32px; height: 32px; border-radius: 6px; object-fit: cover; box-shadow: 0 2px 4px rgba(0,0,0,0.05);" alt="Telecel">
                                    <span>Telecel</span>
                                </button>
                                <button type="button" id="pill-Ishare" onclick="filterNetwork('Ishare')" class="network-pill-btn">
                                    <img src="/image/Ishare.png" style="width: 32px; height: 32px; border-radius: 6px; object-fit: cover; box-shadow: 0 2px 4px rgba(0,0,0,0.05);" alt="Ishare">
                                    <span>Ishare</span>
                                </button>
                            </div>
                        </div>

                        <!-- Dropdown Select Bundle -->
                        <div style="margin-bottom: 1.25rem;">
                            <label for="singleGbField" style="font-size: 0.72rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; display: block; margin-bottom: 0.5rem;">Select Bundle</label>
                            <select name="gb_amount" id="singleGbField" class="form-control-modal" onchange="updateSingleCost()">
                                <!-- Rendered dynamically via javascript -->
                            </select>
                        </div>

                        <!-- Recipient Phone Input -->
                        <div style="margin-bottom: 1.25rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                                <label for="singlePhoneField" style="font-size: 0.72rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin: 0;">Recipient Number</label>
                                <div style="display: flex; gap: 0.3rem; align-items: center;">
                                    <?php if (!empty($userPhone)): ?>
                                    <button type="button" onclick="fillMyNumber('<?= htmlspecialchars($userPhone, ENT_QUOTES, 'UTF-8') ?>')" class="phone-shortcut-btn" title="Auto-fill my phone number">
                                        <i class="fas fa-user-check" style="color: #3b82f6;"></i>
                                        <span>My Number</span>
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" onclick="pasteToSinglePhone()" class="phone-shortcut-btn" title="Paste number from clipboard">
                                        <i class="fas fa-paste" style="color: #10b981;"></i>
                                        <span>Paste</span>
                                    </button>
                                    <button type="button" onclick="clearSinglePhone()" id="clearSinglePhoneBtn" class="phone-shortcut-btn" style="display:none; color: #ef4444;" title="Clear number">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <div style="position:relative;">
                                <input type="tel" name="recipient" id="singlePhoneField" class="form-control-modal" placeholder="e.g. 024XXXXXXX" pattern="[0-9]{10}" required autocomplete="tel" oninput="onSinglePhoneInput(this.value)">
                                <div id="singlePrefixBadge" style="position: absolute; right: 0.75rem; bottom: 0.6rem; display: none; align-items:center;">
                                    <span style="font-size:0.6rem; font-weight:500; padding:0.15rem 0.4rem; border-radius:4px; text-transform:uppercase;" id="singlePrefixText">MTN</span>
                                </div>
                            </div>
                        </div>

                        <!-- Cost Preview inside the single form -->
                        <div style="background:#f1f5f9; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:500; display:block;">Estimated Cost</span>
                                <span id="singleCostLabel" style="font-size:1.05rem; font-weight:600; color:#0f172a;">GHS 0.00</span>
                            </div>
                            <div style="text-align:right;">
                                <span id="singleRemainingText" style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:500; display:block;">Remaining Wallet</span>
                                <span id="singleRemainingLabel" style="font-size:0.9rem; font-weight:600; color:#10b981;">GHS <?= number_format($walletBalance, 2) ?></span>
                            </div>
                        </div>

                        <button type="submit" class="buy-btn" style="width: 100%;">Buy Now</button>
                    </form>
                </div>
            </div>

            <!-- BATCH DISPATCH SECTION -->
            <div id="storeBulkSection" style="display:none;">
                <div class="card">
                    <form method="POST" action="" id="buyBatchForm" onsubmit="return validateBatchSubmit()">
                        <input type="hidden" name="action" value="buy">
                        <input type="hidden" name="mode" value="bulk">
                        <input type="hidden" name="confirm_unverified" id="batchConfirmUnverified" value="0">
                        
                        <div style="margin-bottom: 1.25rem;">
                            <label style="font-size: 0.72rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; display: block; margin-bottom: 0.5rem;">Select network</label>
                            <select name="network" id="batchNetworkSelect" class="form-control-modal" onchange="renderBatchPackages()">
                                <option value="MTN">MTN</option>
                                <option value="Telecel">Telecel</option>
                                <option value="Ishare">Ishare</option>
                            </select>
                        </div>



                        <div style="margin-bottom:1.25rem;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                <label style="font-size: 0.72rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Dispatch Matrix</label>
                                <div style="display:flex; gap:0.3rem; align-items:center;">
                                    <?php if (!empty($userPhone)): ?>
                                    <button type="button" onclick="appendMyNumberToMatrix('<?= htmlspecialchars($userPhone, ENT_QUOTES, 'UTF-8') ?>')" class="phone-shortcut-btn" title="Add my number with 1GB">
                                        <i class="fas fa-user-plus" style="color:#3b82f6;"></i>
                                        <span>+ My Number</span>
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" onclick="pasteClipboardToMatrix()" class="phone-shortcut-btn" title="Paste lines from clipboard">
                                        <i class="fas fa-paste" style="color:#10b981;"></i>
                                        <span>Paste</span>
                                    </button>
                                    <button type="button" onclick="clearMatrix()" class="phone-shortcut-btn" style="color:#64748b;" title="Clear matrix">
                                        <i class="fas fa-trash-alt"></i>
                                        <span>Clear</span>
                                    </button>
                                </div>
                            </div>
                            <textarea name="bulk_data" id="batchMatrixTextarea" class="batch-textarea" placeholder="e.g.&#10;0241234567 5&#10;0547654321 10" oninput="updateBatchCost()"></textarea>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.4rem; font-size:0.72rem; color:#64748b; font-weight:500;">
                                <div>Orders: <span id="batchCountText" style="font-weight: 600;">0</span> | Total GB: <span id="batchGbText" style="font-weight: 600;">0.00 GB</span></div>
                                <div id="batchValidityText"></div>
                            </div>
                        </div>

                        <!-- Cost Preview -->
                        <div style="background:#f1f5f9; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:500; display:block;">Total Cost</span>
                                <span id="batchCostLabel" style="font-size:1.05rem; font-weight:600; color:#0f172a;">GHS 0.00</span>
                            </div>
                            <div style="text-align:right;">
                                <span id="batchRemainingText" style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:500; display:block;">Wallet Balance</span>
                                <span id="batchRemainingLabel" style="font-size:0.9rem; font-weight:600; color:#10b981;">GHS <?= number_format($walletBalance, 2) ?></span>
                            </div>
                        </div>

                        <button type="submit" class="buy-btn" style="width: 100%;">Initiate Batch</button>
                    </form>
                </div>
            </div>

            <!-- View Orders Shortcut -->
            <div style="margin-top: 2rem; text-align: center;">
                <a href="<?= APP_URL ?>store_orders" style="display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.78rem; font-weight: 500; color: #475569; text-decoration: none; border-bottom: 1.5px solid #cbd5e1; padding-bottom: 0.15rem;">
                    <i class="fas fa-receipt"></i> View Order History
                </a>
            </div>
        </div>
    </main>
</div>

<script>
const freeMode = <?= (int)($user['free_mode'] ?? 0) ?>;
const currentDebt = <?= (float)($user['debt'] ?? 0) ?>;
const walletBalance = <?= $walletBalance ?>;
const pricing = {
    MTN:     <?= json_encode($mtnPrices) ?>,
    Telecel: <?= json_encode($telecelPrices) ?>,
    Ishare:  <?= json_encode($isharePrices) ?>
};
const mtnExpressPricing = <?= json_encode($mtnExpressPrices) ?>;
const netPrefixes = {
    MTN:     ['024','025','053','054','055','059'],
    Telecel: ['020','050'],
    Ishare:  ['026','027','056','057']
};

let currentNetwork = 'MTN';
let currentMode = 'single';

function switchStoreMode(mode) {
    currentMode = mode;
    document.getElementById('btnModeSingle').classList.toggle('active', mode === 'single');
    document.getElementById('btnModeBulk').classList.toggle('active', mode === 'bulk');
    
    document.getElementById('storeSingleSection').style.display = mode === 'single' ? 'block' : 'none';
    document.getElementById('storeBulkSection').style.display = mode === 'bulk' ? 'block' : 'none';
}

function filterNetwork(net) {
    currentNetwork = net;
    document.getElementById('singleNetworkField').value = net;
    
    ['MTN', 'Telecel', 'Ishare'].forEach(n => {
        const btn = document.getElementById('pill-' + n);
        if (n === net) {
            btn.classList.add('active-' + n.toLowerCase().substring(0, 3));
        } else {
            btn.classList.remove('active-mtn', 'active-tel', 'active-ish');
        }
    });
    
    renderDropdownOptions();
    validateSinglePhonePrefix(document.getElementById('singlePhoneField').value.trim());
}

function renderDropdownOptions() {
    const select = document.getElementById('singleGbField');
    if (!select) return;
    const pkgs = pricing[currentNetwork] || [];
    if (pkgs.length === 0) {
        select.innerHTML = '<option value="">Network offline for maintenance</option>';
    } else {
        select.innerHTML = '<option value="">Choose package size...</option>';
        pkgs.forEach(pkg => {
            const opt = document.createElement('option');
            opt.value = pkg.gb_amount;
            opt.dataset.price = pkg.price_ghs;
            opt.textContent = `${pkg.gb_amount % 1 === 0 ? parseInt(pkg.gb_amount) : pkg.gb_amount} GB - GHS ${parseFloat(pkg.price_ghs).toFixed(2)}`;
            select.appendChild(opt);
        });
    }
    updateSingleCost();
}

function updateSingleCost() {
    const select = document.getElementById('singleGbField');
    const selectedOpt = select.options[select.selectedIndex];
    let cost = 0;
    if (selectedOpt && selectedOpt.value) {
        cost = parseFloat(selectedOpt.dataset.price);
    }
    document.getElementById('singleCostLabel').textContent = 'GHS ' + cost.toFixed(2);
    const remaining = walletBalance - cost;
    const remainingLabel = document.getElementById('singleRemainingLabel');
    const remainingText = document.getElementById('singleRemainingText');
    
    if (freeMode) {
        if (remainingText) remainingText.textContent = "New Debt Balance";
        const newDebt = currentDebt + cost;
        remainingLabel.textContent = 'GHS ' + newDebt.toFixed(2);
        remainingLabel.style.color = '#f59e0b';
    } else {
        if (remainingText) remainingText.textContent = "Remaining Wallet";
        remainingLabel.textContent = 'GHS ' + Math.max(0, remaining).toFixed(2);
        if (remaining < 0) {
            remainingLabel.style.color = '#ef4444';
        } else {
            remainingLabel.style.color = '#10b981';
        }
    }
}

function validateSinglePhonePrefix(val) {
    const badge = document.getElementById('singlePrefixBadge');
    const badgeText = document.getElementById('singlePrefixText');
    
    if (val.length >= 3) {
        const prefix = val.substring(0, 3);
        const prefixes = netPrefixes[currentNetwork];
        
        if (prefixes.includes(prefix)) {
            badgeText.textContent = currentNetwork;
            
            const isDark = document.documentElement.classList.contains('dark');
            const netColors = isDark ? {
                MTN:     { color: '#fbbf24', bg: 'rgba(245,158,11,0.2)' },
                Telecel: { color: '#f87171', bg: 'rgba(239,68,68,0.2)' },
                Ishare:  { color: '#60a5fa', bg: 'rgba(59,130,246,0.2)' }
            } : {
                MTN:     { color: '#b45309', bg: '#fef3c7' },
                Telecel: { color: '#b91c1c', bg: '#fee2e2' },
                Ishare:  { color: '#1d4ed8', bg: '#dbeafe' }
            };
            
            badgeText.style.background = netColors[currentNetwork].bg;
            badgeText.style.color = netColors[currentNetwork].color;
            badge.style.display = 'flex';
            return;
        }
    }
    badge.style.display = 'none';
}

function onSinglePhoneInput(val) {
    validateSinglePhonePrefix(val);
    var clearBtn = document.getElementById('clearSinglePhoneBtn');
    if (clearBtn) clearBtn.style.display = val.trim().length > 0 ? 'inline-flex' : 'none';
}

function fillMyNumber(phone) {
    if (!phone) return;
    var cleanPhone = phone.replace(/[^0-9]/g, '');
    if (cleanPhone.length === 12 && cleanPhone.startsWith('233')) {
        cleanPhone = '0' + cleanPhone.substring(3);
    } else if (cleanPhone.length === 9) {
        cleanPhone = '0' + cleanPhone;
    }
    var input = document.getElementById('singlePhoneField');
    if (input) {
        input.value = cleanPhone;
        onSinglePhoneInput(cleanPhone);
        input.focus();
    }
}

function pasteToSinglePhone() {
    if (navigator.clipboard && navigator.clipboard.readText) {
        navigator.clipboard.readText().then(function(clipText) {
            if (!clipText) return;
            var cleanPhone = clipText.replace(/[^0-9]/g, '');
            if (cleanPhone.length === 12 && cleanPhone.startsWith('233')) {
                cleanPhone = '0' + cleanPhone.substring(3);
            } else if (cleanPhone.length === 9) {
                cleanPhone = '0' + cleanPhone;
            } else if (cleanPhone.length > 10) {
                cleanPhone = cleanPhone.substring(0, 10);
            }
            var input = document.getElementById('singlePhoneField');
            if (input) {
                input.value = cleanPhone;
                onSinglePhoneInput(cleanPhone);
                input.focus();
            }
        }).catch(function() {
            var input = document.getElementById('singlePhoneField');
            if (input) input.focus();
        });
    } else {
        var input = document.getElementById('singlePhoneField');
        if (input) input.focus();
    }
}

function clearSinglePhone() {
    var input = document.getElementById('singlePhoneField');
    if (input) {
        input.value = '';
        onSinglePhoneInput('');
        input.focus();
    }
}

function appendMyNumberToMatrix(phone) {
    if (!phone) return;
    var cleanPhone = phone.replace(/[^0-9]/g, '');
    if (cleanPhone.length === 12 && cleanPhone.startsWith('233')) {
        cleanPhone = '0' + cleanPhone.substring(3);
    } else if (cleanPhone.length === 9) {
        cleanPhone = '0' + cleanPhone;
    }
    var matrix = document.getElementById('batchMatrixTextarea');
    if (matrix) {
        var current = matrix.value.trim();
        matrix.value = (current ? current + '\n' : '') + cleanPhone + ' 1';
        updateBatchCost();
        matrix.focus();
    }
}

// Batch mode math
function updateBatchCost() {
    const raw = document.getElementById('batchMatrixTextarea').value;
    const net = document.getElementById('batchNetworkSelect').value;
    const lines = raw.split('\n').map(l => l.trim()).filter(l => l.length > 0);
    
    let totalCost = 0;
    let totalGb = 0;
    let count = 0;
    let hasError = false;
    let errorReason = "";
    
    const prefixes = netPrefixes[net];
    
    lines.forEach(line => {
        const parts = line.split(/[\s,]+/);
        const num = parts[0].trim();
        const validPrefix = prefixes.includes(num.substring(0, 3));
        
        let gb = 0;
        
        if (parts.length >= 2) {
            gb = parseFloat(parts[1].trim());
        }
        
        if (num.match(/^[0-9]{10}$/) && validPrefix && !isNaN(gb) && gb > 0) {
            totalGb += gb;
            
            // Lookup pricing
            const pkg = pricing[net].find(p => parseFloat(p.gb_amount) === gb);
            if (pkg) {
                totalCost += parseFloat(pkg.price_ghs);
            } else {
                hasError = true;
                errorReason = `Package ${gb}GB not found for ${net}`;
            }
            count++;
        } else {
            hasError = true;
            if (!num.match(/^[0-9]{10}$/)) {
                errorReason = "Bad phone format (need 10 digits)";
            } else if (!validPrefix) {
                errorReason = "Phone must belong to " + net;
            } else {
                errorReason = "Missing or invalid GB amount";
            }
        }
    });
    
    document.getElementById('batchCountText').textContent = count;
    document.getElementById('batchGbText').textContent = totalGb.toFixed(2) + ' GB';
    document.getElementById('batchCostLabel').textContent = 'GHS ' + totalCost.toFixed(2);
    
    const remaining = walletBalance - totalCost;
    const remainingLabel = document.getElementById('batchRemainingLabel');
    const remainingText = document.getElementById('batchRemainingText');
    
    if (freeMode) {
        if (remainingText) remainingText.textContent = "New Debt Balance";
        const newDebt = currentDebt + totalCost;
        remainingLabel.textContent = 'GHS ' + newDebt.toFixed(2);
        remainingLabel.style.color = '#f59e0b';
    } else {
        if (remainingText) remainingText.textContent = "Wallet Balance";
        remainingLabel.textContent = 'GHS ' + Math.max(0, remaining).toFixed(2);
        if (remaining < 0) {
            remainingLabel.style.color = '#ef4444';
        } else {
            remainingLabel.style.color = '#10b981';
        }
    }
    
    const validity = document.getElementById('batchValidityText');
    if (lines.length === 0) {
        validity.innerHTML = '';
    } else if (hasError) {
        validity.innerHTML = `<span style="color:#ef4444;"><i class="fas fa-times-circle"></i> ${errorReason}</span>`;
    } else {
        validity.innerHTML = '<span style="color:#22c55e;"><i class="fas fa-check-circle"></i> Entries Valid</span>';
    }
}

function clearMatrix() {
    document.getElementById('batchMatrixTextarea').value = '';
    updateBatchCost();
}

async function pasteClipboardToMatrix() {
    try {
        const text = await navigator.clipboard.readText();
        document.getElementById('batchMatrixTextarea').value = text;
        updateBatchCost();
    } catch(e) {
        document.getElementById('batchMatrixTextarea').focus();
    }
}

let currentFormToSubmit = null;
let detectedNewNumbers = [];

function downloadNewBeneficiariesCSV() {
    if (!detectedNewNumbers || detectedNewNumbers.length === 0) return;
    let csvContent = "data:text/csv;charset=utf-8,Phone Number\n" + detectedNewNumbers.join("\n");
    let encodedUri = encodeURI(csvContent);
    let link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "new_beneficiary_numbers.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function validateBatchSubmit() {
    if (currentFormToSubmit === 'buyBatchForm') return true;
    
    const raw = document.getElementById('batchMatrixTextarea').value.trim();
    if (raw.length === 0) {
        alert("Please enter dispatch matrix data first.");
        return false;
    }
    const label = document.getElementById('batchRemainingLabel');
    if (!freeMode && label.style.color === 'rgb(239, 68, 68)') {
        alert("Insufficient wallet balance to perform this batch dispatch.");
        return false;
    }
    
    const bNet = document.getElementById('batchNetworkSelect') ? document.getElementById('batchNetworkSelect').value : '';
    let bDebtBox = document.getElementById('mtnDebtWarningBox');
    if (bDebtBox) bDebtBox.style.display = (bNet === 'MTN') ? 'block' : 'none';
    
    if (bNet === 'MTN') {
        fetch('<?= APP_URL ?>ajax_check_beneficiary.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'network=MTN&recipients=' + encodeURIComponent(raw)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.has_new && data.new_count > 0) {
                detectedNewNumbers = data.new_numbers;
                let textElem = document.getElementById('detectedBeneficiaryPhoneText');
                let badgesElem = document.getElementById('newBeneficiariesBadges');
                
                if (textElem) {
                    textElem.textContent = data.new_numbers.join(', ');
                }
                let feeElem = document.getElementById('detectedBeneficiaryFeeText');
                if (feeElem) {
                    // Get exact price from the batch total cost label
                    let rawCost = document.getElementById('batchCostLabel') ? document.getElementById('batchCostLabel').textContent : 'GHS 0.00';
                    feeElem.textContent = rawCost;
                    feeElem.title = 'Total cost for ' + data.new_count + ' new beneficiary order(s)';
                }
                
                if (badgesElem) {
                    let badgesHtml = data.new_numbers.map(num => 
                        `<span style="background-color: #ffffff; color: #000000; font-weight: 800; font-size: 0.82rem; padding: 4px 10px; border-radius: 6px; font-family: monospace; border: 1px solid #cbd5e1;">${num}</span>`
                    ).join('');
                    badgesElem.innerHTML = badgesHtml;
                    badgesElem.style.display = 'flex';
                }
                document.getElementById('beneficiaryWarningModal').style.display = 'flex';
                currentFormToSubmit = 'buyBatchForm';
            } else {
                document.getElementById('confirmModalText').innerText = "Are you sure you want to purchase this batch order? The cost will be deducted from your wallet.";
                document.getElementById('confirmModal').style.display = 'flex';
                currentFormToSubmit = 'buyBatchForm';
            }
        })
        .catch(() => {
            document.getElementById('confirmModalText').innerText = "Are you sure you want to purchase this batch order? The cost will be deducted from your wallet.";
            document.getElementById('confirmModal').style.display = 'flex';
            currentFormToSubmit = 'buyBatchForm';
        });
        return false;
    }

    document.getElementById('confirmModalText').innerText = "Are you sure you want to purchase this batch order? The cost will be deducted from your wallet.";
    document.getElementById('confirmModal').style.display = 'flex';
    currentFormToSubmit = 'buyBatchForm';
    return false;
}

function validateSingleSubmit(e) {
    if (e) e.preventDefault();
    if (currentFormToSubmit === 'buySingleForm') {
        document.getElementById('buySingleForm').submit();
        return true;
    }
    
    const select = document.getElementById('singleGbField');
    if (!select.value) {
        alert("Please select a bundle size first.");
        return false;
    }
    const phone = document.getElementById('singlePhoneField').value;
    if (!phone.match(/^[0-9]{10}$/)) {
        alert("Please enter a valid 10-digit phone number.");
        return false;
    }
    const label = document.getElementById('singleRemainingLabel');
    if (!freeMode && label.style.color === 'rgb(239, 68, 68)') {
        alert("Insufficient wallet balance.");
        return false;
    }
    
    const net = document.getElementById('singleNetworkField').value;
    const confirmUnverified = document.getElementById('singleConfirmUnverified').value;
    let debtBox = document.getElementById('mtnDebtWarningBox');
    if (debtBox) debtBox.style.display = (net === 'MTN') ? 'block' : 'none';
    
    if (net === 'MTN' && confirmUnverified !== '1') {
        fetch('<?= APP_URL ?>ajax_check_beneficiary.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'network=MTN&recipients=' + encodeURIComponent(phone)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.has_new && data.new_count > 0) {
                detectedNewNumbers = data.new_numbers;
                let textElem = document.getElementById('detectedBeneficiaryPhoneText');
                let badgesElem = document.getElementById('newBeneficiariesBadges');
                
                if (textElem) {
                    textElem.textContent = data.new_numbers.join(', ');
                }
                let feeElem = document.getElementById('detectedBeneficiaryFeeText');
                if (feeElem) {
                    // Get exact price from the selected GB option's data-price
                    const gbSelect = document.getElementById('singleGbField');
                    const selectedOpt = gbSelect ? gbSelect.options[gbSelect.selectedIndex] : null;
                    let exactPrice = selectedOpt && selectedOpt.dataset.price ? parseFloat(selectedOpt.dataset.price) : 0;
                    
                    if (typeof mtnExpressPricing !== 'undefined' && document.getElementById('singleNetworkField').value === 'MTN') {
                        const selectedGb = selectedOpt ? parseFloat(selectedOpt.value) : 0;
                        const xpressPkg = mtnExpressPricing.find(p => parseFloat(p.gb_amount) === selectedGb);
                        if (xpressPkg) {
                            exactPrice = parseFloat(xpressPkg.price_ghs);
                        }
                    }
                    feeElem.textContent = exactPrice > 0 ? 'GHS ' + exactPrice.toFixed(2) : (document.getElementById('singleCostLabel') ? document.getElementById('singleCostLabel').textContent : 'GHS 0.00');
                }
                
                if (badgesElem) {
                    if (data.new_numbers.length > 1) {
                        let badgesHtml = data.new_numbers.map(num => 
                            `<span style="background-color: #ffffff; color: #000000; font-weight: 800; font-size: 0.82rem; padding: 4px 10px; border-radius: 6px; font-family: monospace; border: 1px solid #cbd5e1;">${num}</span>`
                        ).join('');
                        badgesHtml = badgesHtml;
                        badgesElem.innerHTML = badgesHtml;
                        badgesElem.style.display = 'flex';
                    } else {
                        badgesElem.style.display = 'none';
                    }
                }
                document.getElementById('beneficiaryWarningModal').style.display = 'flex';
                currentFormToSubmit = 'buySingleForm';
            } else {
                document.getElementById('confirmModalText').innerText = "Are you sure you want to purchase this bundle? The cost will be deducted from your wallet.";
                document.getElementById('confirmModal').style.display = 'flex';
                currentFormToSubmit = 'buySingleForm';
            }
        })
        .catch(() => {
            document.getElementById('confirmModalText').innerText = "Are you sure you want to purchase this bundle? The cost will be deducted from your wallet.";
            document.getElementById('confirmModal').style.display = 'flex';
            currentFormToSubmit = 'buySingleForm';
        });
        return false;
    }
    
    document.getElementById('confirmModalText').innerText = "Are you sure you want to purchase this bundle? The cost will be deducted from your wallet.";
    document.getElementById('confirmModal').style.display = 'flex';
    currentFormToSubmit = 'buySingleForm';
    return false;
}

function closeBeneficiaryModal() {
    document.getElementById('beneficiaryWarningModal').style.display = 'none';
}

function confirmBeneficiaryAnyway() {
    // Show spinner on the Continue Anyway button
    let btn = document.getElementById('beneficiaryConfirmBtn');
    let cancelBtn = document.querySelector('#beneficiaryWarningModal button[onclick="closeBeneficiaryModal()"]');
    if (btn) {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i> Processing...';
        btn.disabled = true;
        btn.style.opacity = '0.85';
        btn.style.cursor = 'not-allowed';
    }
    if (cancelBtn) {
        cancelBtn.disabled = true;
        cancelBtn.style.opacity = '0.4';
        cancelBtn.style.cursor = 'not-allowed';
    }
    const singleIn = document.getElementById('singleConfirmUnverified');
    if (singleIn) singleIn.value = '1';
    const batchIn = document.getElementById('batchConfirmUnverified');
    if (batchIn) batchIn.value = '1';
    // Small delay so spinner is visible before page navigates
    setTimeout(function() {
        if (currentFormToSubmit) {
            document.getElementById(currentFormToSubmit).submit();
        }
    }, 150);
}

function proceedWithPurchase() {
    if (currentFormToSubmit) {
        let btn = document.getElementById('confirmActionBtn');
        let cancelBtn = document.getElementById('cancelActionBtn');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Accepting...';
            btn.style.background = '#22c55e';
            btn.style.borderColor = '#22c55e';
            btn.style.color = 'white';
            btn.disabled = true;
        }
        if (cancelBtn) {
            cancelBtn.disabled = true;
            cancelBtn.style.opacity = '0.5';
        }
        if (typeof playOrderSuccessSound === 'function') {
            playOrderSuccessSound();
        }
        document.getElementById(currentFormToSubmit).submit();
    }
}

function cancelPurchase() {
    currentFormToSubmit = null;
    document.getElementById('confirmModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    filterNetwork('MTN');
    renderBatchPackages();
});
</script>
</body>
</html>


