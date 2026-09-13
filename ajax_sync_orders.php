<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/SupplierApi.php';
require_once __DIR__ . '/classes/Supplier2Api.php';
require_once __DIR__ . '/classes/Supplier3Api.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }
if (empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = $_SESSION['user'];
$userId = (int)$user['id'];

header('Content-Type: application/json');

try {
    $pdo = db_connect();

    // Fetch pending orders — include admin_override flag (excluding unpaid/initiated)
    $stmt = $pdo->prepare("SELECT *, COALESCE(admin_override, 0) AS admin_override FROM bundle_sends WHERE user_id = ? AND status NOT IN ('success', 'successful', 'successfully', 'completed', 'refunded', 'failed', 'initiated', 'unpaid', 'abandoned') AND network IN ('MTN', 'Telecel', 'Ishare', 'AT') ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$userId]);
    $pendingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendingOrders)) {
        echo json_encode(['success' => true, 'updated' => 0, 'message' => 'No pending orders to sync.']);
        exit;
    }

    $updatedCount = 0;

    foreach ($pendingOrders as $order) {
        $orderId = $order['id'];
        $message = $order['message'] ?? '';

        // SKIP: Admin has manually set this order's status — don't let API sync overwrite it
        if (!empty($order['admin_override'])) {
            continue;
        }
        
        $suppOrderId = null;
        if (preg_match('/(?:Order ID|Ref|trx_ref|ID):\s*(\S+)/i', $message, $m)) {
            $suppOrderId = trim($m[1]);
        }
        
        $apiStatus = null;

        // Dynamically reroute validating NITGHT orders according to the Automated Dispatch Board Priority
        if ($order['status'] === 'validating' && (stripos($message, 'NITGHT') !== false || stripos($message, 'Aviator') !== false || stripos($message, 'Awaiting') !== false)) {
            $phoneToUse = $order['recipient_phone'] ?? $order['phone'] ?? '';
            $rerouteRes = reroute_order_by_priority($pdo, $orderId, $order['network'] ?? 'MTN', $phoneToUse, (float)$order['gb_amount'], 'nitght', 'Night API');
            if (!empty($rerouteRes['rerouted'])) {
                $apiStatus = $rerouteRes['status'];
            }
        }
        
        // Strategy 0: MTNUP2U PORTAL API check
        elseif ((stripos($message, 'MTNUP2U PORTAL') !== false || stripos($message, 'MTNUP2 GB') !== false || stripos($message, 'MTNUP2GB') !== false) && $suppOrderId !== null) {
            static $mtnUp2GbApi = null;
            if ($mtnUp2GbApi === null) {
                require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
                $mtnUp2GbApi = new MtnUp2uPortalApi();
            }
            $mRes = $mtnUp2GbApi->checkOrderStatus($suppOrderId);
            if (!empty($mRes['success']) || isset($mRes['data']) || isset($mRes['status'])) {
                $apiStatus = MtnUp2GbApi::mapStatus($order['network'], $mRes);
            }
        }
        elseif (stripos($message, 'MTNUP2U') !== false && stripos($message, 'MTNUP2U PORTAL') === false && $suppOrderId !== null) {
            static $mtnUp2uApi = null;
            if ($mtnUp2uApi === null) {
                require_once __DIR__ . '/classes/MtnUp2uApi.php';
                $mtnUp2uApi = new MtnUp2uApi();
            }
            $mRes = $mtnUp2uApi->checkOrderStatus($suppOrderId);
            if (!empty($mRes['success']) || isset($mRes['data']) || isset($mRes['status'])) {
                $apiStatus = MtnUp2uApi::mapStatus($order['network'], $mRes);
            }
        }
        elseif ((stripos($message, 'NITGHT') !== false || stripos($message, 'Aviator') !== false) && $suppOrderId !== null) {
            static $nitghtApi = null;
            if ($nitghtApi === null) {
                require_once __DIR__ . '/classes/NitghtApi.php';
                $nitghtApi = new NitghtApi();
            }
            $nRes = $nitghtApi->checkOrderStatus($suppOrderId);
            if (!empty($nRes['success']) || isset($nRes['data']) || isset($nRes['status'])) {
                $mappedStatus = NitghtApi::mapStatus($order['network'], $nRes);
                $apiStatus = $mappedStatus;
                if ($mappedStatus === 'validating') {
                    $phoneToUse = $order['recipient_phone'] ?? $order['phone'] ?? '';
                    $rerouteRes = reroute_order_by_priority($pdo, $orderId, $order['network'] ?? 'MTN', $phoneToUse, (float)$order['gb_amount'], 'nitght', 'Night API');
                    if (!empty($rerouteRes['rerouted'])) {
                        $apiStatus = $rerouteRes['status'];
                    }
                }
            }
        }
        // Strategy 5: Supplier 5 (Jaybart Services) API check
        elseif ((stripos($message, 'Supplier 5') !== false || stripos($message, 'Jaybart') !== false) && $suppOrderId !== null) {
            static $s5Api = null;
            if ($s5Api === null) {
                require_once __DIR__ . '/classes/Supplier5Api.php';
                $s5Api = new Supplier5Api();
            }
            $s5Res = $s5Api->checkOrderStatus($suppOrderId);
            if (!empty($s5Res['success'])) {
                $mappedS5Status = Supplier5Api::mapStatus($order['network'], $s5Res);
                if ($mappedS5Status === 'waiting') {
                    require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
                    $phoneToUse = $order['recipient_phone'] ?? $order['phone'] ?? '';
                    $rerouteRes = MtnUp2uPortalApi::rerouteFromJaybart($pdo, $orderId, $order['network'], $phoneToUse, $order['gb_amount']);
                    if (!empty($rerouteRes['status'])) {
                        $apiStatus = $rerouteRes['status'];
                    } else {
                        $apiStatus = 'waiting';
                    }
                } else {
                    $apiStatus = $mappedS5Status;
                }
            }
        }
        // Strategy 1: Supplier 2 (BoatLink) API check
        elseif (stripos($message, 'Supplier 2') !== false && $suppOrderId !== null) {
            static $s2Api = null;
            if ($s2Api === null) $s2Api = new Supplier2Api();
            $s2Res = $s2Api->checkOrderStatus($suppOrderId);
            if (!empty($s2Res['success']) && !empty($s2Res['order_status'])) {
                $apiStatus = strtolower(trim($s2Res['order_status']));
            }
        }
        // Strategy 2: Supplier 3 API check
        elseif (stripos($message, 'Supplier 3') !== false && $suppOrderId !== null) {
            static $s3Api = null;
            if ($s3Api === null) $s3Api = new Supplier3Api();
            $s3Res = $s3Api->checkOrderStatus($suppOrderId);
            if (!empty($s3Res['success']) && !empty($s3Res['status'])) {
                $apiStatus = strtolower(trim($s3Res['status']));
            }
        }
        // Strategy 3: Supplier 1 API check
        elseif ((stripos($message, 'Supplier 1') !== false || stripos($suppOrderId, 'APEX_') === 0)) {
            static $s1Api = null;
            if ($s1Api === null) $s1Api = new SupplierApi();
            $refStr = $suppOrderId ? $suppOrderId : 'APEX_' . $orderId;
            $s1Res = $s1Api->checkOrderStatus($refStr);
            
            if (isset($s1Res['success']) && $s1Res['success']) {
                if (!empty($s1Res['data'])) {
                    $data = $s1Res['data'];
                    if (is_array($data) && isset($data['status'])) {
                        $apiStatus = strtolower(trim($data['status']));
                    } elseif (is_string($data)) {
                        $apiStatus = strtolower(trim($data));
                    }
                } elseif (isset($s1Res['status'])) {
                    $apiStatus = strtolower(trim($s1Res['status']));
                }
            }
        } elseif (stripos($message, 'Backup') !== false && $suppOrderId !== null) {
            static $backupApi = null;
            if ($backupApi === null) {
                require_once __DIR__ . '/classes/BackupApi.php';
                $backupApi = new BackupApi();
            }
            $res = $backupApi->checkOrderStatus($suppOrderId);
            if (!empty($res['success']) || isset($res['status'])) {
                $mapped = BackupApi::mapStatus($order['network'], $res);
                $apiStatus = $mapped;
                if ($mapped === 'validating') {
                    $appendMsg = "Refunded at Backup";
                    $newMessage = empty($message) ? $appendMsg : $message . ' | ' . $appendMsg;
                    $pdo->prepare("UPDATE bundle_sends SET message = ? WHERE id = ?")->execute([$newMessage, $orderId]);
                }
            }

        // ── PAID BUT NEVER DISPATCHED ────────────────────────────────────────────
        // Order is 'pending' or 'processing' with no supplier message = payment was
        // successful but the API was offline (or a parse error) at checkout time.
        // Re-dispatch now through the full supplier priority chain.
        } elseif (
            in_array($order['status'], ['pending', 'processing', 'waiting']) &&
            $suppOrderId === null &&
            !empty($order['reference'])  // has a Paystack reference = was paid
        ) {
            try {
                $phoneToUse = $order['recipient_phone'] ?? '';
                $netName    = strtoupper($order['network']);
                $gbAmount   = (float)$order['gb_amount'];

                $settings    = load_all_settings($pdo);
                $autoFulfill = ($settings['estore_auto_fulfill'] ?? '1') === '1';

                if ($autoFulfill) {
                    $netEnabled = true;
                    if (strpos($netName, 'MTN')     !== false && ($settings['estore_mtn_enabled']     ?? '1') === '0') $netEnabled = false;
                    if (strpos($netName, 'TELECEL') !== false && ($settings['estore_telecel_enabled'] ?? '1') === '0') $netEnabled = false;
                    if ((strpos($netName, 'ISHARE') !== false || strpos($netName, 'AT') !== false) && ($settings['estore_ishare_enabled'] ?? '1') === '0') $netEnabled = false;

                    if ($netEnabled) {
                        require_once __DIR__ . '/classes/NitghtApi.php';
                        require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
                        require_once __DIR__ . '/classes/MtnUp2uApi.php';
                        require_once __DIR__ . '/classes/Supplier5Api.php';
                        require_once __DIR__ . '/classes/BackupApi.php';

                        $availableApis = [
                            'nitght'    => NitghtApi::isNetworkEnabled($netName, $gbAmount)   ? ['api' => new NitghtApi(),   'name' => 'NITGHT']    : null,
                            'mtnup2gb'  => MtnUp2uPortalApi::isNetworkEnabled($netName, $gbAmount) ? ['api' => new MtnUp2uPortalApi(), 'name' => 'MTNUP2U PORTAL'] : null,
                            'mtnup2u'   => MtnUp2uApi::isNetworkEnabled($netName, $gbAmount)  ? ['api' => new MtnUp2uApi(),  'name' => 'MTNUP2U']   : null,
                            'jaybart'   => Supplier5Api::isNetworkEnabled($netName, $gbAmount) ? ['api' => new Supplier5Api(), 'name' => 'Jaybart']  : null,
                            'supplier1' => SupplierApi::isNetworkEnabled($netName, $gbAmount)  ? ['api' => new SupplierApi(),  'name' => 'Supplier 1']: null,
                            'supplier2' => (strpos($netName, 'MTN') !== false && Supplier2Api::isNetworkEnabled('mtn', $gbAmount)) ? ['api' => new Supplier2Api(), 'name' => 'Supplier 2'] : null,
                            'supplier3' => (strpos($netName, 'MTN') !== false && Supplier3Api::isNetworkEnabled('mtn', $gbAmount)) ? ['api' => new Supplier3Api(), 'name' => 'Supplier 3'] : null,
                            'backup'    => BackupApi::isNetworkEnabled($netName, $gbAmount)   ? ['api' => new BackupApi(),   'name' => 'Backup']    : null,
                        ];

                        $netUpper = strtoupper($netName);
                        $isIshare = (strpos($netUpper, 'ISHARE') !== false || strpos($netUpper, 'AT') !== false);
                        $isTelecel = (strpos($netUpper, 'TELECEL') !== false);
                        $isMtn = (strpos($netUpper, 'MTN') !== false);

                        $designatedSupplier = '';
                        if ($isIshare) {
                            $designatedSupplier = $settings['estore_ishare_supplier'] ?? 'supplier1';
                        } elseif ($isTelecel) {
                            $designatedSupplier = $settings['estore_telecel_supplier'] ?? 'supplier1';
                        } elseif ($isMtn) {
                            $designatedSupplier = $settings['estore_mtn_supplier'] ?? ($settings['estore_primary_supplier'] ?? 'nitght');
                        }

                        $boardPriority = get_supplier_priority_order($pdo);
                        $mappedBoardPriority = array_map(function($k) { return $k === 'supplier5' ? 'jaybart' : $k; }, $boardPriority);
                        $priorityKeys = array_values(array_unique(array_filter($mappedBoardPriority)));

                        $supplierApi  = null;
                        $supplierName = '';
                        foreach ($priorityKeys as $pKey) {
                            if (!empty($availableApis[$pKey])) {
                                $supplierApi  = $availableApis[$pKey]['api'];
                                $supplierName = $availableApis[$pKey]['name'];
                                break;
                            }
                        }

                        if ($supplierApi) {
                            $dispatchResult = $supplierApi->sendBundle($netName, $phoneToUse, $gbAmount, $orderId);
                            $dData  = $dispatchResult['data'] ?? [];
                            $dRef   = $dispatchResult['reference'] ?? $dispatchResult['order_id'] ?? $dispatchResult['transaction_id']
                                    ?? $dispatchResult['id'] ?? $dData['reference'] ?? $dData['order_id']
                                    ?? $dData['trx_ref'] ?? $dData['id'] ?? '';

                            if (!empty($dispatchResult['success'])) {
                                if ($supplierName === 'NITGHT') {
                                    $orderStatus = NitghtApi::mapStatus($netName, $dispatchResult);
                                    $dispMsg     = "Sent to NITGHT. Order ID: " . $dRef;
                                    if ($orderStatus === 'validating' || stripos($dispatchResult['message'] ?? '', 'awaiting') !== false) {
                                        $rerouteRes = reroute_order_by_priority($pdo, $orderId, $netName, $phoneToUse, (float)$gbAmount, 'nitght', 'Night API');
                                        if (!empty($rerouteRes['rerouted'])) {
                                            $orderStatus = $rerouteRes['status'];
                                            $dispMsg = $rerouteRes['message'];
                                        }
                                    }
                                } elseif ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') {
                                    $orderStatus = MtnUp2GbApi::mapStatus($netName, $dispatchResult);
                                    $dispMsg     = "Sent to MTNUP2U PORTAL. Order ID: " . $dRef;
                                } elseif ($supplierName === 'MTNUP2U') {
                                    $orderStatus = MtnUp2uApi::mapStatus($netName, $dispatchResult);
                                    $dispMsg     = "Sent to MTNUP2U. Order ID: " . $dRef;
                                } elseif ($supplierName === 'Supplier 1') {
                                    $orderStatus = SupplierApi::mapStatus($netName, $dispatchResult);
                                    $dispMsg     = "Sent to Supplier 1. Ref: " . $dRef;
                                } else {
                                    $orderStatus = 'waiting';
                                    $dispMsg     = "Sent to {$supplierName}. Ref: " . $dRef;
                                }
                                // Remap in-flight status → 'waiting': sent to API, waiting for fulfillment
                                if ($orderStatus === 'processing' || $orderStatus === 'accepted') $orderStatus = 'waiting';
                                $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?")
                                    ->execute([$orderStatus, $dispMsg, $orderId]);
                                $apiStatus = $orderStatus;
                                $updatedCount++;
                            }
                            // If dispatch failed, leave as-is — will retry on next sync
                        }
                    }
                }
            } catch (Exception $dispEx) {}
        }

        if (!empty($apiStatus)) {
            $newStatus = $order['status'];

            if (in_array($apiStatus, ['completed', 'success', 'successful', 'delivered'])) {
                $newStatus = 'successfully';
            } elseif (in_array($apiStatus, ['failed', 'error', 'cancelled', 'declined', 'reversed'])) {
                $rawMsg = $dispMsg ?? $order['message'] ?? '';
                if (isBeneficiaryError($rawMsg)) {
                    $newStatus = 'unverified';
                } elseif (isInsufficientBalanceError($rawMsg)) {
                    $newStatus = 'pending';
                } else {
                    $newStatus = 'failed';
                }
            } elseif (in_array($apiStatus, ['refunded'])) {
                $newStatus = 'refunded';
            } elseif (in_array($apiStatus, ['validating'])) {
                $newStatus = 'validating';
            } elseif (in_array($apiStatus, ['unverified'])) {
                $newStatus = 'unverified';
            }

            // If status changed to successfully, failed, refunded, validating, or unverified, update DB
            if ($newStatus !== $order['status'] && in_array($newStatus, ['successfully', 'failed', 'refunded', 'validating', 'unverified'])) {
                if (in_array($newStatus, ['failed', 'refunded'])) {
                    $updateStmt = $pdo->prepare("UPDATE bundle_sends SET status = ?, refunded_at = NOW(), updated_at = NOW() WHERE id = ?");
                } else {
                    $updateStmt = $pdo->prepare("UPDATE bundle_sends SET status = ?, updated_at = NOW() WHERE id = ?");
                }
                $updateStmt->execute([$newStatus, $orderId]);
                
                // Refund if failed
                if ($newStatus === 'failed') {
                    $uStmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
                    $uStmt->execute([$order['user_id']]);
                    $usr = $uStmt->fetch();
                    if ($usr) {
                        $refundAmount = getGbPriceGhs($pdo, $order['network'], $order['gb_amount'], $usr['role']);
                        $stmtBal = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
                        $stmtBal->execute([$usr['id']]);
                        $balBefore = (float)($stmtBal->fetchColumn() ?: 0.00);
                        $balAfter = $balBefore + $refundAmount;

                        $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$balAfter, $usr['id']]);
                        $ref = 'REF-' . $order['id'] . '-' . mt_rand(1000, 9999);
                        $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, 'credit', ?, ?, ?, 'success')")
                            ->execute([$usr['id'], $refundAmount, $ref, "Refund for failed {$order['network']} {$order['gb_amount']}GB order", $balBefore, $balAfter]);
                        // Notify user on their registered phone
                        sendRefundSmsToUser($pdo, $usr['id'], $refundAmount, $order['id'], $order['network'], (float)$order['gb_amount'], $order['recipient_phone'] ?? '');
                    }

                }
                
                $updatedCount++;
            }
        }
    }

    echo json_encode(['success' => true, 'updated' => $updatedCount]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
