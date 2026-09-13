<?php
error_reporting(0);
ob_start();
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }

header('Content-Type: application/json');

function send_json($data) {
    if (ob_get_length()) ob_end_clean();
    echo json_encode($data);
    exit;
}

if (empty($_SESSION['user'])) {
    send_json(['success' => false, 'message' => 'Unauthorized']);
}

$user   = $_SESSION['user'];
$userId = (int)($user['id'] ?? 0);

$orderId = (int)($_POST['order_id'] ?? 0);
$type    = strtolower(trim($_POST['type'] ?? 'store'));

if ($orderId <= 0) {
    send_json(['success' => false, 'message' => 'Invalid order ID']);
}

try {
    $pdo = db_connect();

    if ($type === 'afa') {
        // AFA Registration Check
        $stmt = $pdo->prepare("SELECT * FROM mtn_afa_registrations WHERE id = ? AND user_id = ?");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();

        if (!$order) {
            send_json(['success' => false, 'message' => 'AFA Registration order not found.']);
        }

        $st = strtolower(trim($order['status'] ?? 'pending'));
        $displayApiStatus = strtoupper($order['status'] ?? 'PENDING');

        if (in_array($st, ['completed', 'registered', 'success', 'successful', 'approved'])) {
            send_json([
                'success' => true,
                'status'  => 'completed',
                'raw_api_status' => $displayApiStatus,
                'title'   => 'Registration Completed',
                'message' => 'Your MTN AFA registration is completed and active.'
            ]);
        } elseif (in_array($st, ['failed', 'rejected', 'cancelled'])) {
            send_json([
                'success' => true,
                'status'  => 'failed',
                'raw_api_status' => $displayApiStatus,
                'title'   => 'Registration Failed',
                'message' => 'Registration was rejected: ' . ($order['message'] ?: 'Unknown error')
            ]);
        } else {
            send_json([
                'success' => true,
                'status'  => 'processing',
                'raw_api_status' => $displayApiStatus,
                'title'   => 'Order Under Review',
                'message' => 'Your MTN AFA registration is currently under review by the team.'
            ]);
        }
    } else {
        // Data Bundle / Store Order Check
        $stmt = $pdo->prepare("SELECT * FROM bundle_sends WHERE id = ? AND user_id = ?");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();

        if (!$order) {
            send_json(['success' => false, 'message' => 'Store order not found.']);
        }

        $currentStatus = strtolower(trim($order['status'] ?? 'pending'));

        // If admin has manually locked this order's status, skip the live API check
        // and just report the current status as-is.
        if (!empty($order['admin_override'])) {
            $retStatus = in_array($currentStatus, ['pending', 'validating', 'processing', 'waiting', 'accepted']) ? $currentStatus : $currentStatus;
            send_json([
                'success'        => true,
                'status'         => $retStatus,
                'raw_api_status' => strtoupper($currentStatus),
                'title'          => 'Order Under Review',
                'message'        => 'This order status has been manually set by admin and is under review.'
            ]);
        }

        // Live check against supplier API
        $message = $order['message'] ?? '';
        $suppOrderId = null;
        if (preg_match('/(?:Order ID|Ref|Reference|trx_ref|ID):\s*([A-Za-z0-9\-_]+)/i', $message, $m)) {
            $suppOrderId = trim($m[1]);
        } elseif (preg_match('/(?:APEX|API|AVD)[_\-][A-Za-z0-9\-_]+/i', $message, $m)) {
            $suppOrderId = trim($m[0]);
        }
        if (!$suppOrderId) {
            $suppOrderId = (string)$orderId;
        }

        $mappedStatus = null;
        $rawApiStatus = null;

        // Dynamically reroute validating NITGHT orders according to the Automated Dispatch Board Priority
        $alreadyRerouted = (stripos($message, 'Re-routed') !== false || stripos($message, 'Rerouted') !== false);
        if (!$alreadyRerouted && $order['status'] === 'validating' && (stripos($message, 'NITGHT') !== false || stripos($message, 'Aviator') !== false || stripos($message, 'Awaiting') !== false)) {
            $phoneToUse = $order['recipient_phone'] ?? $order['phone'] ?? '';
            $rerouteRes = reroute_order_by_priority($pdo, $orderId, $order['network'] ?? 'MTN', $phoneToUse, (float)$order['gb_amount'], 'nitght', 'Night API');
            if (!empty($rerouteRes['rerouted'])) {
                $mappedStatus = $rerouteRes['status'];
            }
        }
        
        if ($mappedStatus !== null) {
            // Already rerouted
        } elseif ((stripos($message, 'MTNUP2U PORTAL') !== false || stripos($message, 'MTNUP2 GB') !== false || stripos($message, 'MTNUP2GB') !== false) && $suppOrderId !== null) {
            require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
            $api = new MtnUp2uPortalApi();
            $res = $api->checkOrderStatus($suppOrderId);
            $dataObj = $res['data'] ?? [];
            $rawApiStatus = $dataObj['status'] ?? $dataObj['order_status'] ?? $dataObj['orderStatus'] ?? $dataObj['delivery_status'] ?? $dataObj['state'] ?? $res['status'] ?? $res['order_status'] ?? $res['state'] ?? null;
            if (!empty($res['success']) || isset($res['data']) || isset($res['status'])) {
                $mappedStatus = MtnUp2GbApi::mapStatus($order['network'], $res);
            }
        } elseif (stripos($message, 'MTNUP2U') !== false && stripos($message, 'MTNUP2U PORTAL') === false && $suppOrderId !== null) {
            require_once __DIR__ . '/classes/MtnUp2uApi.php';
            $api = new MtnUp2uApi();
            $res = $api->checkOrderStatus($suppOrderId);
            $dataObj = $res['data'] ?? [];
            $rawApiStatus = $dataObj['status'] ?? $dataObj['order_status'] ?? $dataObj['orderStatus'] ?? $dataObj['delivery_status'] ?? $dataObj['state'] ?? $res['status'] ?? $res['order_status'] ?? $res['state'] ?? null;
            if (!empty($res['success']) || isset($res['data']) || isset($res['status'])) {
                $mappedStatus = MtnUp2uApi::mapStatus($order['network'], $res);
            }
        } elseif ((stripos($message, 'Supplier 5') !== false || stripos($message, 'Jaybart') !== false) && $suppOrderId !== null) {
            require_once __DIR__ . '/classes/Supplier5Api.php';
            $api = new Supplier5Api();
            $res = $api->checkOrderStatus($suppOrderId);
            $dataObj = $res['data'] ?? [];
            $rawApiStatus = $dataObj['status'] ?? $res['status'] ?? null;
            if (!empty($res['success'])) {
                $mappedStatus = Supplier5Api::mapStatus($order['network'], $res);
                if ($mappedStatus === 'waiting') {
                    require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
                    $phoneToUse = $order['recipient_phone'] ?? $order['phone'] ?? '';
                    $rerouteRes = MtnUp2uPortalApi::rerouteFromJaybart($pdo, $orderId, $order['network'], $phoneToUse, $order['gb_amount']);
                    if (!empty($rerouteRes['status'])) {
                        $mappedStatus = $rerouteRes['status'];
                    }
                }
            }
        } elseif (stripos($message, 'Supplier 2') !== false && $suppOrderId !== null) {
            require_once __DIR__ . '/classes/Supplier2Api.php';
            $api = new Supplier2Api();
            $res = $api->checkOrderStatus($suppOrderId);
            $rawApiStatus = $res['order_status'] ?? $res['status'] ?? null;
            if (!empty($res['success']) && !empty($res['order_status'])) {
                $mappedStatus = MtnUp2GbApi::mapStatus($order['network'], ['status' => $res['order_status']]);
            }
        } elseif (stripos($message, 'Supplier 3') !== false && $suppOrderId !== null) {
            require_once __DIR__ . '/classes/Supplier3Api.php';
            $api = new Supplier3Api();
            $res = $api->checkOrderStatus($suppOrderId);
            $rawApiStatus = $res['status'] ?? null;
            if (!empty($res['success']) && !empty($res['status'])) {
                $mappedStatus = MtnUp2GbApi::mapStatus($order['network'], ['status' => $res['status']]);
            }
        } elseif (stripos($message, 'Supplier 1') !== false || ($suppOrderId && stripos($suppOrderId, 'APEX_') === 0) || stripos($message, 'APEX_') !== false) {
            require_once __DIR__ . '/classes/SupplierApi.php';
            $api = new SupplierApi();
            $refStr = $suppOrderId ?: ('APEX_' . $order['id']);
            if (is_numeric($refStr) || stripos($refStr, 'APEX_') === false) {
                $refStr = 'APEX_' . preg_replace('/^APEX[_\-]?/i', '', $refStr);
            }
            $res = $api->checkOrderStatus($refStr);
            $rawApiStatus = $res['data']['status'] ?? $res['status'] ?? null;
            if (!empty($res['success'])) {
                $mappedStatus = MtnUp2GbApi::mapStatus($order['network'], ['status' => $rawApiStatus ?? '']);
            }
        } elseif (stripos($message, 'Backup') !== false && $suppOrderId !== null) {
            require_once __DIR__ . '/classes/BackupApi.php';
            $api = new BackupApi();
            $res = $api->checkOrderStatus($suppOrderId);
            $dataObj = $res['data'] ?? [];
            $rawApiStatus = $dataObj['status'] ?? $res['status'] ?? null;
            if (!empty($res['success']) || isset($res['data']) || isset($res['status'])) {
                $mappedStatus = BackupApi::mapStatus($order['network'], $res);
                if (strtolower(trim((string)$rawApiStatus)) === 'refunded') {
                    $mappedStatus = 'validating';
                    $appendMsg = "Refunded at Backup";
                    $newMessage = empty($message) ? $appendMsg : $message . ' | ' . $appendMsg;
                    $pdo->prepare("UPDATE bundle_sends SET message = ? WHERE id = ?")->execute([$newMessage, $orderId]);
                    $order['message'] = $newMessage;
                }
            }
        } elseif ((stripos($message, 'NITGHT') !== false || stripos($message, 'Aviator') !== false) && $suppOrderId !== null) {
            require_once __DIR__ . '/classes/NitghtApi.php';
            $api = new NitghtApi();
            $phoneToUse = $order['recipient_phone'] ?? $order['phone'] ?? '';
            $res = $api->checkOrderStatus($suppOrderId, $phoneToUse, (float)($order['gb_amount'] ?? 0));
            $dataObj = $res['data'] ?? [];
            $rawApiStatus = $dataObj['status'] ?? $res['status'] ?? null;
            if (!empty($res['success']) || isset($res['data']) || isset($res['status'])) {
                $mappedStatus = NitghtApi::mapStatus($order['network'], $res);
                @file_put_contents(__DIR__ . '/logs/debug_status.log', "[" . date('Y-m-d H:i:s') . "] NITGHT ajax_check order {$orderId}: " . json_encode($res) . " => mapped: " . $mappedStatus . "\n", FILE_APPEND);
                if (strtoupper(trim((string)$rawApiStatus)) === 'REFUNDED') {
                    $mappedStatus = 'waiting';
                } elseif ($mappedStatus === 'validating') {
                    $phoneToUse = $order['recipient_phone'] ?? $order['phone'] ?? '';
                    $rerouteRes = reroute_order_by_priority($pdo, $orderId, $order['network'] ?? 'MTN', $phoneToUse, (float)$order['gb_amount'], 'nitght', 'Night API');
                    if (!empty($rerouteRes['rerouted'])) {
                        $mappedStatus = $rerouteRes['status'];
                    }
                }
            }
        }

        if ($rawApiStatus === null && $suppOrderId === null) {
            $displayApiStatus = "PROCESSING WITH NETWORK";
        } else {
            $displayApiStatus = strtoupper((string)($rawApiStatus ?: $currentStatus));
        }

        // If already terminal or live check completed
        if ($mappedStatus === 'completed' || in_array($currentStatus, ['completed', 'successful', 'success', 'sucessfully'])) {
            if ($mappedStatus === 'completed' && !in_array($currentStatus, ['completed', 'successful', 'success', 'sucessfully'])) {
                $pdo->prepare("UPDATE bundle_sends SET status = 'completed', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
                creditStoreOrderProfit($pdo, $orderId);
                if (!empty($order['recipient_phone'])) {
                    autoVerifyAndWhitelistNumber($pdo, $order['recipient_phone'], $order['network']);
                }
                require_once __DIR__ . '/classes/MailHelper.php';
                MailHelper::sendOrderCompletionEmail($pdo, $orderId);
            }

            send_json([
                'success' => true,
                'status'  => 'completed',
                'raw_api_status' => $displayApiStatus,
                'title'   => 'Data Delivered',
                'message' => 'Data bundle of ' . (float)$order['gb_amount'] . 'GB to ' . $order['recipient_phone'] . ' was verified and delivered successfully!'
            ]);
        } elseif ($mappedStatus === 'failed' || in_array($currentStatus, ['failed', 'refunded'])) {
            $checkMsg = $res['message'] ?? $order['message'] ?? $message ?? '';
            if (isBeneficiaryError($checkMsg)) {
                $pdo->prepare("UPDATE bundle_sends SET status = 'unverified', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
                send_json([
                    'success' => true,
                    'status'  => 'unverified',
                    'raw_api_status' => 'UNVERIFIED',
                    'title'   => 'Order Unverified',
                    'message' => 'Recipient number is not on the supplier beneficiary list yet. It will be processed once registered.'
                ]);
            } elseif (isInsufficientBalanceError($checkMsg)) {
                $pdo->prepare("UPDATE bundle_sends SET status = 'pending', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
                send_json([
                    'success' => true,
                    'status'  => 'pending',
                    'raw_api_status' => 'PENDING',
                    'title'   => 'Order Pending',
                    'message' => 'Your order is pending fulfillment due to supplier processing/balance. It will be delivered shortly.'
                ]);
            } elseif ($mappedStatus === 'failed' && !in_array($currentStatus, ['failed', 'refunded'])) {
                $pdo->prepare("UPDATE bundle_sends SET status = 'failed', refund_transferred = 1, refunded_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$orderId]);
                if (empty($order['refund_transferred'])) {
                    $uStmt = $pdo->prepare("SELECT role, free_mode FROM users WHERE id = ?");
                    $uStmt->execute([$userId]);
                    $uData = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $uRole = $uData['role'] ?? 'client';
                    $isFree = (int)($uData['free_mode'] ?? 0);
                    $refundAmount = getGbPriceGhs($pdo, $order['network'], $order['gb_amount'], $uRole);
                    $ref = 'REFUND-' . $orderId;
                    if ($isFree) {
                        $pdo->prepare("UPDATE users SET debt = GREATEST(0, debt - ?) WHERE id = ?")->execute([$refundAmount, $userId]);
                        $bal = getUserWalletBalance($pdo, (int)$userId);
                        $stmtTx = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, 'credit', ?, ?, ?, 'success')");
                        $stmtTx->execute([$userId, $refundAmount, $ref, "Refund (Status Check Failed) [DEBT REVERSED]: {$order['network']} " . (float)$order['gb_amount'] . "GB", $bal, $bal]);
                    } else {
                        $descr = "Refund (Status Check Failed): {$order['network']} " . (float)$order['gb_amount'] . "GB to {$order['recipient_phone']}";
                        addWalletTransaction($pdo, $userId, $refundAmount, 'credit', $ref, $descr);
                        // Notify user on their registered phone
                        sendRefundSmsToUser($pdo, $userId, $refundAmount, $orderId, $order['network'], (float)$order['gb_amount'], $order['recipient_phone']);
                    }
                }

            }

            send_json([
                'success' => true,
                'status'  => 'failed',
                'raw_api_status' => $displayApiStatus,
                'title'   => 'Order Failed',
                'message' => 'Order failed with supplier: ' . ($order['message'] ?: 'Order could not be processed.')
            ]);
        } else {
            if ($mappedStatus && $mappedStatus !== $currentStatus) {
                if ($mappedStatus === 'waiting') {
                    $appendMsg = "refunded message from Alexa";
                    $newMessage = empty($message) ? $appendMsg : $message . ' | ' . $appendMsg;
                    $pdo->prepare("UPDATE bundle_sends SET status = 'waiting', message = ?, updated_at = NOW() WHERE id = ?")->execute([$newMessage, $orderId]);
                    $currentStatus = 'waiting';
                } else {
                    $pdo->prepare("UPDATE bundle_sends SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$mappedStatus, $orderId]);
                    $currentStatus = $mappedStatus;
                }
            }

            $retStatus = $currentStatus === 'waiting' ? 'waiting' : ($currentStatus !== 'pending' ? $currentStatus : 'processing');
            $retTitle = $currentStatus === 'waiting' ? 'Order Waiting' : 'Order Under Review';
            $retMessage = $currentStatus === 'waiting' ? 'Order was refunded by Alexa provider.' : 'Order is under review and currently processing with the network provider.';

            $createdAtTs = !empty($order['created_at']) ? strtotime($order['created_at']) : time();
            $diffSeconds = max(0, time() - $createdAtTs);
            $netStr = strtoupper($order['network'] ?? '');
            $isMtn = (strpos($netStr, 'MTN') !== false);
            $isOngoing = !in_array($currentStatus, ['completed', 'successful', 'success', 'failed', 'refunded', 'cancelled']);

            if ($isOngoing && $isMtn && $diffSeconds >= 3600) {
                $retTitle = 'MTN System Busy';
                $retMessage = 'Est. delivery: Not available! MTN system is currently busy. Please try again later.';
            } elseif ($isOngoing && $isMtn && ($currentStatus === 'validating' || $mappedStatus === 'validating')) {
                $retTitle = 'MTN Validation In Progress';
                $retMessage = 'Awaiting MTN approval. A validation process is currently ongoing on the MTN system.';
            } elseif (strtoupper(trim((string)$rawApiStatus)) === 'RECEIVED') {
                $retTitle = 'Order Received';
                $retMessage = 'Your order has been received by Apex Prime Team and is being processed.';
            }

            send_json([
                'success' => true,
                'status'  => $retStatus,
                'raw_api_status' => $displayApiStatus,
                'title'   => $retTitle,
                'message' => $retMessage
            ]);
        }
    }
} catch (Exception $e) {
    send_json(['success' => false, 'message' => 'Error checking order status: ' . $e->getMessage()]);
}
