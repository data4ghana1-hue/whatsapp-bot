<?php
/**
 * Apex Prime - Momo SMS Webhook
 * Accepts incoming SMS from an SMS-to-HTTP forwarder.
 */

// ── WhatsApp Cloud API Webhook Handshake (GET) ───────────────────
$hubMode        = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
$hubChallenge   = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
$hubVerifyToken = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';

if (!empty($hubMode) || !empty($hubVerifyToken)) {
    $validTokens = [
        'Apex Prime',
        'ApexPrime',
        'apex prime',
        'apexprime',
        'Apex_Prime',
        'Apex-Prime',
        'EAAVbwPrkZA5EBSWYPZCejcQIT87IReAyjpXQZAAsjxQ6BWLvP6ZCwnabpoTbQ2S6UN0ZBnBJSKcYlNDiV7Yx3mSBTzJO3Ri6sy0XFFL9NeqcyIsDEbKDf2bFUwEQ10Az7Tena4DvwmQeMj4iQAZCeLAkURotrEpHaYLKDNPPsU8jYbhmnYd05Jq1tXbbWZB8wZDZD',
        'EAAVbwPrkZA5EBSTJ2A9xBaF8mJkuwJ3Ykbcky005Ttof0FAtZBRStfgpZAsE3WHi726cU59j4jD8FYh6Mv0aAymSZCoavqkFYNvJsKR1jVWynukbrFFmqS90xqUGyZCEfKbU8UqsOCbIV3AwHNjz2VNrF0tJMKuX6IEnWi5KWAZAVQZC8L1BPFzEZCIKy9EBiVgl2JpTlq6kGPnyETgA2PtTLEbmGnZCHpZA1xaZCFZA3ZAhzW5xqia1lPJo1OQW08XVxZALkSwbK95C9bDNnxMD3vegxxYMgZD'
    ];

    $isVerified = false;
    $cleanReceived = strtolower(trim($hubVerifyToken));
    foreach ($validTokens as $expected) {
        if ($hubVerifyToken === $expected 
            || strtolower(trim($expected)) === $cleanReceived
            || hash_equals((string)$expected, (string)$hubVerifyToken)) {
            $isVerified = true;
            break;
        }
    }

    if ($hubMode === 'subscribe' && $isVerified) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo (string)$hubChallenge;
        exit;
    } else {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden: Invalid verify token';
        exit;
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/MomoParser.php';

// ── 1. Accept input (Supports both POST Form Data and JSON) ───
$input = $_POST;

if (empty($input)) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if ($json) $input = $json;
}

// ── WhatsApp Cloud API Event Notification (POST) ────────────────
if (is_array($input) && isset($input['object']) && $input['object'] === 'whatsapp_business_account') {
    if (!is_dir(__DIR__ . '/logs')) @mkdir(__DIR__ . '/logs', 0755, true);
    $logMsg = '[' . date('Y-m-d H:i:s') . '] [WhatsApp Event in webhook.php] ' . json_encode($input) . "\n";
    @file_put_contents(__DIR__ . '/logs/whatsapp_webhook.log', $logMsg, FILE_APPEND | LOCK_EX);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'EVENT_RECEIVED']);
    exit;
}

$sender  = $input['from'] 
       ?? $input['sender'] 
       ?? $input['sender_number'] 
       ?? $input['sender-number'] 
       ?? $input['phone'] 
       ?? $input['sender_contact']
       ?? $input['sender-contact']
       ?? $input['contact']
       ?? null;

$message = $input['message'] 
        ?? $input['msg'] 
        ?? $input['text'] 
        ?? $input['body'] 
        ?? $input['content'] 
        ?? null;

if (!$message) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No message received.']);
    exit;
}

// ── 2. Parse SMS ───────────────────────────────────────────
$data = MomoParser::parse($message);

if (!$data) {
    if (!is_dir('logs')) mkdir('logs', 0755, true);
    file_put_contents('logs/momo_failed.log', date('Y-m-d H:i:s') . " - Unparsed SMS from $sender: $message\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'SMS could not be parsed.']);
    exit;
}

// ── 3. Store in Database & Process Matches ──────────────────
try {
    $pdo = db_connect();

    // Ensure the table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webhook_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(100) UNIQUE NOT NULL,
            reference VARCHAR(100) NULL,
            amount DECIMAL(10,2) NOT NULL,
            sender_name VARCHAR(255) NULL,
            provider VARCHAR(50) NULL,
            is_claimed TINYINT(1) DEFAULT 0,
            claimed_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Add reference and sender_name if they don't exist (in case table was created earlier without them)
    try {
        $pdo->exec("ALTER TABLE webhook_payments ADD COLUMN reference VARCHAR(100) NULL AFTER transaction_id");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE webhook_payments ADD COLUMN sender_name VARCHAR(255) NULL AFTER amount");
    } catch (Exception $e) {}

    // Check if the payment already exists
    $stmt = $pdo->prepare("SELECT id FROM webhook_payments WHERE transaction_id = ?");
    $stmt->execute([$data['transaction_id']]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'success', 'message' => 'Payment already exists.']);
        exit;
    }

    // Attempt to automatically match with a pending Order ID (Requirement 6)
    $matchedOrder = null;
    $matchedUser = null;
    $ref = $data['reference'] ?? '';

    if ($ref) {
        $stmt = $pdo->query("SELECT id, user_id, amount FROM topups WHERE status = 'pending'");
        $pendingTopups = $stmt->fetchAll();
        foreach ($pendingTopups as $order) {
            $expectedOrderId = str_pad(($order['id'] * 397 + 137) % 900 + 100, 3, '0', STR_PAD_LEFT);
            if ($ref === (string)$expectedOrderId || $ref === (string)($expectedOrderId * 1)) {
                if ((float)$data['amount'] >= (float)$order['amount']) {
                    $matchedOrder = $order;
                }
                break;
            }
        }

        // Match User Code as MoMo payment reference (e.g. APEX-317 or 317)
        if (!$matchedOrder) {
            $userCodeId = 0;
            if (preg_match('/(?:APEX[\s\-_]*)?(\d+)/i', $ref, $um)) {
                $userCodeId = (int)$um[1];
            }
            if ($userCodeId > 0) {
                $stmtU = $pdo->prepare("SELECT id, username, phone, wallet_balance FROM users WHERE id = ? OR payment_ref = ? LIMIT 1");
                $stmtU->execute([$userCodeId, (string)$userCodeId]);
                $matchedUser = $stmtU->fetch(PDO::FETCH_ASSOC);
            }
        }
    }

    $pdo->beginTransaction();

    if ($matchedOrder) {
        // Automatically credit the user
        $stmt = $pdo->prepare("
            INSERT INTO webhook_payments (transaction_id, reference, amount, sender_name, provider, is_claimed, claimed_by_user_id, created_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
        ");
        $stmt->execute([
            $data['transaction_id'],
            $data['reference'],
            $data['amount'],
            $data['sender_name'],
            $data['provider'],
            $matchedOrder['user_id']
        ]);

        // Approve the topup order
        $stmt = $pdo->prepare("UPDATE topups SET status = 'approved', transaction_id = ? WHERE id = ?");
        $stmt->execute([$data['transaction_id'], $matchedOrder['id']]);

        // Send Top-up SMS with new balance
        require_once __DIR__ . '/classes/SmsHelper.php';
        $stmtTopup2 = $pdo->prepare('SELECT network, phone FROM topups WHERE id = :id');
        $stmtTopup2->execute(['id' => $matchedOrder['id']]);
        $topupRow2 = $stmtTopup2->fetch(PDO::FETCH_ASSOC);
        if ($topupRow2) {
            // Increment AFA balance if needed
            if (stripos($topupRow2['network'], 'AFA') !== false) {
                $qty = 0;
                if (preg_match('/(\d+)\s*Qty/i', $topupRow2['phone'], $m)) {
                    $qty = (int)$m[1];
                }
                if ($qty > 0) {
                    $stmtAfa = $pdo->prepare("UPDATE users SET afa_balance = afa_balance + ? WHERE id = ?");
                    $stmtAfa->execute([$qty, $matchedOrder['user_id']]);
                }
            }

            // Credit wallet balance for Wallet Topup orders
            if ($topupRow2['network'] === 'Wallet Topup') {
                addWalletTransaction($pdo, $matchedOrder['user_id'], $data['amount'], 'credit', 'WEBHOOK-' . $data['transaction_id'], 'Wallet funding via webhook');
            }

            sendTopupSms($pdo, $matchedOrder['user_id'], $topupRow2['network'], $topupRow2['phone']);
        }

    } elseif ($matchedUser) {
        // Automatically credit user who paid using their User Code as MoMo reference (e.g. APEX-317 or 317)
        $stmt = $pdo->prepare("
            INSERT INTO webhook_payments (transaction_id, reference, amount, sender_name, provider, is_claimed, claimed_by_user_id, created_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
        ");
        $stmt->execute([
            $data['transaction_id'],
            $data['reference'],
            $data['amount'],
            $data['sender_name'],
            $data['provider'],
            $matchedUser['id']
        ]);

        // Insert into topups as approved
        $stmtIns = $pdo->prepare("
            INSERT INTO topups (user_id, network, phone, amount, status, transaction_id)
            VALUES (?, 'MTN MoMo', ?, ?, 'approved', ?)
        ");
        $userPhone = !empty($matchedUser['phone']) ? $matchedUser['phone'] : ($data['sender_name'] ?: 'MoMo Direct');
        $stmtIns->execute([
            $matchedUser['id'],
            $userPhone,
            $data['amount'],
            $data['transaction_id']
        ]);

        // Credit user wallet transaction
        addWalletTransaction(
            $pdo,
            $matchedUser['id'],
            $data['amount'],
            'credit',
            'MOMO-' . $data['transaction_id'],
            "Wallet funding via User Code Reference: {$ref}"
        );

        // Send Topup Approved SMS if class exists
        try {
            require_once __DIR__ . '/classes/SmsHelper.php';
            if (class_exists('SmsHelper')) {
                SmsHelper::sendTopupApprovedSMS($pdo, $matchedUser['id'], 'MTN MoMo', $data['amount'], $data['amount']);
            }
        } catch (Exception $smsEx) {}

    } else {
        // Just store for manual claiming
        $stmt = $pdo->prepare("
            INSERT INTO webhook_payments (transaction_id, reference, amount, sender_name, provider, is_claimed, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([
            $data['transaction_id'],
            $data['reference'],
            $data['amount'],
            $data['sender_name'],
            $data['provider']
        ]);
    }

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'status' => 'success', 
        'message' => 'Payment logged successfully.',
        'auto_matched' => $matchedOrder ? true : false
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
