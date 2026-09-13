<?php
/**
 * Apex Prime — (MTNUP2U / MTNUP2U PORTAL) Webhook Handler
 * 
 * Receives real-time order status change notifications from DataHub Ghana API.
 * Documentation: https://user.datahubgh.com/docs/api
 * Webhook URL  : https://yourdomain.com/webhook_mtnup2u.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/MtnUp2uApi.php';
require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';

header('Content-Type: application/json');

// 1. Read raw input payload & headers
$rawInput = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? $_SERVER['X_WEBHOOK_SIGNATURE'] ?? '';

// 2. Logging for auditing & debugging
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}
$logMsg = '[' . date('Y-m-d H:i:s') . '] [DataHub Webhook] Payload: ' . ($rawInput ?: 'EMPTY') . ' | Signature: ' . ($signatureHeader ?: 'none') . "\n";
@file_put_contents($logsDir . '/webhook_mtnup2u.log', $logMsg, FILE_APPEND | LOCK_EX);

// 3. HMAC Signature Verification (optional if secret is configured)
$webhookSecret = defined('DATAHUB_WEBHOOK_SECRET') ? DATAHUB_WEBHOOK_SECRET : (getenv('DATAHUB_WEBHOOK_SECRET') ?: '');
if (!empty($webhookSecret) && !empty($signatureHeader)) {
    $expectedSignature = hash_hmac('sha256', $rawInput, $webhookSecret);
    if (!hash_equals($expectedSignature, $signatureHeader)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid HMAC signature']);
        exit;
    }
}

// 4. Validate JSON payload
$payload = json_decode($rawInput, true);
if (!$payload && empty($_GET)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or empty JSON payload']);
    exit;
}

$payload = is_array($payload) ? $payload : [];
$data    = $payload['data'] ?? $payload;

// 5. Extract fields from DataHub Ghana payload
$orderNumber = $data['orderNumber'] ?? $data['order_number'] ?? $data['orderId'] ?? $data['order_id'] ?? null;
$reference   = $data['reference']   ?? $data['ref']          ?? $data['trx_ref']   ?? $_GET['reference'] ?? null;
$phoneNumber = $data['phoneNumber'] ?? $data['recipient']    ?? $data['phone']     ?? $data['recipient_phone'] ?? $_GET['phone'] ?? null;
$rawStatus   = strtoupper(trim((string)($data['status']      ?? $data['state']     ?? $_GET['status'] ?? '')));
$network     = strtoupper(trim((string)($data['network']     ?? 'MTN')));

if (empty($reference) && empty($orderNumber) && empty($phoneNumber)) {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'No order reference, order number, or recipient provided. Payload logged.']);
    exit;
}

try {
    $pdo = db_connect();
    $ord = null;

    // Search 1: Match by DataHub reference in message column
    if (!empty($reference)) {
        $stmt = $pdo->prepare("SELECT * FROM bundle_sends WHERE message LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['%' . $reference . '%']);
        $ord = $stmt->fetch();
    }

    // Search 2: Match by orderNumber in message column
    if (!$ord && !empty($orderNumber)) {
        $stmt = $pdo->prepare("SELECT * FROM bundle_sends WHERE message LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['%' . $orderNumber . '%']);
        $ord = $stmt->fetch();
    }

    // Search 3: Match by local numeric order ID if reference is numeric
    if (!$ord && !empty($reference) && is_numeric($reference)) {
        $stmt = $pdo->prepare("SELECT * FROM bundle_sends WHERE id = ?");
        $stmt->execute([(int)$reference]);
        $ord = $stmt->fetch();
    }

    // Search 4: Match by recipient phone number if recent non-terminal order
    if (!$ord && !empty($phoneNumber)) {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        if (strlen($cleanPhone) === 12 && substr($cleanPhone, 0, 3) === '233') {
            $cleanPhone = '0' . substr($cleanPhone, 3);
        }
        $stmt = $pdo->prepare("SELECT * FROM bundle_sends WHERE (recipient_phone = ? OR recipient_phone = ?) AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cleanPhone, $phoneNumber]);
        $ord = $stmt->fetch();
    }

    if (!$ord) {
        $logLine = '[' . date('Y-m-d H:i:s') . "] [DataHub Webhook] No matching order found for ref: {$reference}, orderNumber: {$orderNumber}, phone: {$phoneNumber}\n";
        @file_put_contents($logsDir . '/webhook_mtnup2u.log', $logLine, FILE_APPEND | LOCK_EX);
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Order not found, payload logged.']);
        exit;
    }

    $localOrderId = (int)$ord['id'];
    $orderNetwork = $ord['network'] ?? $network;

    // 6. Status Mapping according to DataHub status events
    $newStatus = 'processing';
    if (in_array($rawStatus, ['SUCCESSFUL', 'COMPLETED', 'DELIVERED', 'PAID', 'APPROVED', 'SUCCESS'])) {
        $newStatus = 'completed';
    } elseif (in_array($rawStatus, ['FAILED', 'CANCELLED', 'REJECTED', 'DECLINED', 'ERROR'])) {
        if (isInsufficientBalanceError($rawStatus) || isInsufficientBalanceError($ord['message'] ?? '')) {
            $newStatus = 'pending';
        } else {
            $newStatus = 'failed';
        }
    } elseif (in_array($rawStatus, ['PROCESSING', 'PENDING', 'INITIATED', 'QUEUED'])) {
        $newStatus = 'processing';
    }

    // 7. Update database if order is not already in terminal status
    if (!in_array($ord['status'], ['completed', 'successful', 'sucessfully', 'failed', 'refunded'])) {
        $currentMsg = $ord['message'] ?? '';
        if ($newStatus === 'completed') {
            $newMessage = empty($currentMsg) ? 'Completed via DataHub Webhook' : $currentMsg;
        } else {
            $appendMsg = "DataHub Webhook: Status changed to {$rawStatus}";
            $newMessage = empty($currentMsg) ? $appendMsg : $currentMsg . ' | ' . $appendMsg;
        }

        $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?");
        $stmtUpdate->execute([$newStatus, $newMessage, $localOrderId]);
        if (function_exists('trigger_webhook')) {
            trigger_webhook($pdo, (int)$localOrderId, $newStatus, $newMessage);
        }

        // Auto-verify and whitelist phone number on completion
        if ($newStatus === 'completed' && !empty($ord['recipient_phone'])) {
            autoVerifyAndWhitelistNumber($pdo, $ord['recipient_phone'], $orderNetwork);
            creditStoreOrderProfit($pdo, $localOrderId);
            
            require_once __DIR__ . '/classes/MailHelper.php';
            MailHelper::sendOrderCompletionEmail($pdo, $localOrderId);
        }

        // Auto-refund user if order status updated to failed
        if ($newStatus === 'failed' && empty($ord['refund_transferred'])) {
            $uStmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
            $uStmt->execute([$ord['user_id']]);
            $user = $uStmt->fetch();
            if ($user) {
                $refundAmount = getGbPriceGhs($pdo, $ord['network'], $ord['gb_amount'], $user['role']);
                $ref = 'REFUND-DATAHUB-' . mt_rand(10000, 99999);
                $descr = "Refund (DataHub Webhook Failed): {$ord['network']} " . (float)$ord['gb_amount'] . "GB to {$ord['recipient_phone']}";
                addWalletTransaction($pdo, (int)$user['id'], $refundAmount, 'credit', $ref, $descr);
                $pdo->prepare("UPDATE bundle_sends SET refund_transferred = 1, refunded_at = NOW() WHERE id = ?")->execute([$localOrderId]);
                if (function_exists('sendRefundSmsToUser')) {
                    sendRefundSmsToUser($pdo, (int)$user['id'], $refundAmount, $localOrderId, $ord['network'] ?? '', (float)($ord['gb_amount'] ?? 0), $ord['recipient_phone'] ?? '');
                }
            }
        }

        $logLine = '[' . date('Y-m-d H:i:s') . "] [DataHub Webhook] Order #{$localOrderId} status updated: '{$ord['status']}' => '{$newStatus}' (Raw: {$rawStatus})\n";
        @file_put_contents($logsDir . '/webhook_mtnup2u.log', $logLine, FILE_APPEND | LOCK_EX);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Webhook processed successfully',
        'order_id' => $localOrderId,
        'status' => $newStatus
    ]);

} catch (Exception $e) {
    $errLine = '[' . date('Y-m-d H:i:s') . "] [DataHub Webhook ERROR] " . $e->getMessage() . "\n";
    @file_put_contents($logsDir . '/webhook_mtnup2u.log', $errLine, FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
