<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// Disable default PHP error output to keep JSON clean
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Response helpers
function jsonResponse($success, $message, $data = [], $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$requestData = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $requestData = $decoded;
        }
    }
}

$action = strtolower(trim($requestData['action'] ?? $_POST['action'] ?? $_GET['action'] ?? ''));

// Map RESTful endpoint slugs to legacy action names
if ($action === 'wallet' || $action === 'balances') {
    $action = 'check_balance';
} elseif ($action === 'transactions') {
    $action = 'get_wallet';
} elseif ($action === 'store-products' || $action === 'products') {
    $action = 'get_store_products';
} elseif ($action === 'store-order' || $action === 'order') {
    $action = 'store_order';
} elseif ($action === 'afa-registration' || $action === 'afa') {
    $action = 'register_afa';
} elseif ($action === 'send-bundle' || $action === 'send_bundle') {
    $action = 'send_bundle';
} elseif ($action === 'send-ishare' || $action === 'send_ishare') {
    $action = 'send_ishare';
} elseif ($action === 'status') {
    $action = 'check_status';
} elseif ($action === 'whitelist-number' || $action === 'whitelist_number') {
    $action = 'whitelist_number';
} elseif ($action === 'verify-number' || $action === 'verify_number') {
    $action = 'verify_number';
} elseif ($action === 'whatsapp' || $action === 'whatsapp-webhook' || isset($_GET['hub_mode']) || isset($_GET['hub.mode'])) {
    $action = 'whatsapp_webhook';
}

// ── WhatsApp Cloud API Webhook Handshake (GET & POST) ─────────
if ($action === 'whatsapp_webhook' || isset($_GET['hub_mode']) || isset($_GET['hub.mode'])) {
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
        if (defined('WHATSAPP_VERIFY_TOKEN') && !empty(WHATSAPP_VERIFY_TOKEN)) {
            $validTokens[] = WHATSAPP_VERIFY_TOKEN;
        }

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

    // WhatsApp Event Notification (POST)
    $input = $requestData;
    if (is_array($input) && isset($input['object']) && $input['object'] === 'whatsapp_business_account') {
        if (!is_dir(__DIR__ . '/logs')) @mkdir(__DIR__ . '/logs', 0755, true);
        $logMsg = '[' . date('Y-m-d H:i:s') . '] [WhatsApp Event in api.php] ' . json_encode($input) . "\n";
        @file_put_contents(__DIR__ . '/logs/whatsapp_webhook.log', $logMsg, FILE_APPEND | LOCK_EX);

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'EVENT_RECEIVED']);
        exit;
    }

    // Health check for GET
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'online',
        'service' => 'Apex Prime WhatsApp Webhook (API endpoint)',
        'time'    => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ── Bot Internal Bridge Endpoint for Real-Time Website Database Sync ──
if ($action === 'bot_query' || $action === 'bot_lookup_user') {
    $botSecret = trim($requestData['bot_secret'] ?? $_POST['bot_secret'] ?? $_GET['bot_secret'] ?? '');
    $validSecret = getenv('CRON_SECRET') ?: ($_ENV['CRON_SECRET'] ?? 'apex_cron_s3cr3t_2026');
    if ($botSecret !== $validSecret && $botSecret !== 'ApexPrimeBot_2026') {
        jsonResponse(false, 'Unauthorized bot query', [], 403);
    }

    try {
        $pdo = db_connect();
    } catch (Throwable $e) {
        $pdo = null;
    }
    $op = trim($requestData['op'] ?? $_POST['op'] ?? $_GET['op'] ?? 'lookup_user');

    // 1. LOOKUP USER
    if ($op === 'lookup_user') {
        $search = trim($requestData['search'] ?? $_POST['search'] ?? $_GET['search'] ?? '');
        $userId = (int)preg_replace('/\D/', '', $search);

        $userData = null;
        if ($userId > 0) {
            $stmt = $pdo->prepare("SELECT id, username, phone, email, wallet_balance, afa_balance, role, payment_ref FROM users WHERE id = ? OR payment_ref = ? LIMIT 1");
            $stmt->execute([$userId, (string)$userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$userData && !empty($search)) {
            $stmt = $pdo->prepare("SELECT id, username, phone, email, wallet_balance, afa_balance, role, payment_ref FROM users WHERE phone = ? OR username = ? LIMIT 1");
            $stmt->execute([$search, $search]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($userData) {
            jsonResponse(true, 'User found in website database', ['user' => $userData]);
        } else {
            jsonResponse(false, 'User not found in website database', [], 404);
        }
    }

    // 2. GET BUNDLE PRICE FOR USER ROLE
    if ($op === 'get_price') {
        $network  = trim($requestData['network'] ?? $_POST['network'] ?? 'MTN');
        $gbAmount = (float)($requestData['amount'] ?? $_POST['amount'] ?? 0);
        $role     = trim($requestData['role'] ?? $_POST['role'] ?? 'client');
        $userId   = (int)($requestData['user_id'] ?? $_POST['user_id'] ?? 0);

        if ($userId > 0 && (empty($requestData['role']) && empty($_POST['role']))) {
            $stmtU = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmtU->execute([$userId]);
            $u = $stmtU->fetch(PDO::FETCH_ASSOC);
            if ($u && !empty($u['role'])) {
                $role = $u['role'];
            }
        }

        $cost = 0.0;
        if (function_exists('getGbPriceGhs') && $gbAmount > 0) {
            $cost = getGbPriceGhs($pdo, $network, $gbAmount, $role);
        }

        jsonResponse(true, 'Role bundle price calculated', [
            'network' => $network,
            'amount'  => $gbAmount,
            'role'    => $role,
            'price'   => $cost
        ]);
    }

    // 3. CREATE ORDER FROM USER ACCOUNT (Show in Admin Portal & Deduct by Role)
    if ($op === 'create_order') {
        $userId    = (int)($requestData['user_id'] ?? $_POST['user_id'] ?? 0);
        $network   = trim($requestData['network'] ?? $_POST['network'] ?? 'MTN');
        $recipient = trim($requestData['recipient'] ?? $_POST['recipient'] ?? '');
        $gbAmount  = (float)($requestData['amount'] ?? $_POST['amount'] ?? 0);
        $cost      = (float)($requestData['cost'] ?? $_POST['cost'] ?? 0);
        $channel   = trim($requestData['channel'] ?? $_POST['channel'] ?? 'whatsapp_bot');
        $status    = trim($requestData['status'] ?? $_POST['status'] ?? 'processing');
        if (empty($status) || $status === 'completed') {
            $status = 'processing';
        }
        $message   = trim($requestData['message'] ?? $_POST['message'] ?? 'Ordered via WhatsApp Bot');

        if ($userId <= 0 || empty($recipient) || $gbAmount <= 0) {
            jsonResponse(false, 'Invalid order parameters', [], 400);
        }

        // Verify user exists and retrieve user role
        $stmtU = $pdo->prepare("SELECT id, username, wallet_balance, role FROM users WHERE id = ? LIMIT 1");
        $stmtU->execute([$userId]);
        $userRow = $stmtU->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            jsonResponse(false, 'User account not found', [], 404);
        }

        $userRole = strtolower(trim($userRow['role'] ?? 'client'));

        // Always calculate and enforce role-based pricing from network_pricing table
        if (function_exists('getGbPriceGhs')) {
            $roleCost = getGbPriceGhs($pdo, $network, $gbAmount, $userRole);
            if ($roleCost > 0) {
                $cost = $roleCost;
            }
        }

        $refundRestriction = function_exists('getPhoneRefundRestriction') ? getPhoneRefundRestriction($pdo, $recipient) : null;
        if ($refundRestriction) {
            jsonResponse(false, $refundRestriction['message'], [
                'code'            => 'REFUND_RESTRICTED',
                'phone'           => $recipient,
                'cooldown_until'  => $refundRestriction['unlock_time'],
                'remaining_days'  => $refundRestriction['remaining_days'],
                'remaining_secs'  => $refundRestriction['remaining_seconds']
            ], 422);
        }

        $currentBal = (float)$userRow['wallet_balance'];
        if ($currentBal < $cost) {
            jsonResponse(false, 'Insufficient wallet balance', ['balance' => $currentBal, 'needed' => $cost, 'role' => $userRole], 400);
        }

        // Debit wallet with role price
        $ref = 'WBOT-' . mt_rand(10000, 99999);
        if (function_exists('addWalletTransaction')) {
            addWalletTransaction($pdo, $userId, $cost, 'debit', $ref, "WhatsApp Bot Order: {$network} {$gbAmount}GB to {$recipient} [Role: {$userRole}]");
        }

        // Insert into bundle_sends with initial processing status so it shows up in Admin Portal!
        $stmtIns = $pdo->prepare("
            INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, amount, status, channel, message, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmtIns->execute([$userId, $network, $recipient, $gbAmount, $cost, $status, $channel, $message]);
        $orderId = (int)$pdo->lastInsertId();

        // Dispatch via supplier if configured
        if (function_exists('resolve_order_supplier')) {
            try {
                $resolvedSupp = resolve_order_supplier($network, $gbAmount, $recipient, $pdo);
                $supplierApi  = $resolvedSupp['api'] ?? null;
                $supplierName = $resolvedSupp['name'] ?? '';
                if ($supplierApi) {
                    $apiResult = $supplierApi->sendBundle($network, $recipient, $gbAmount, $orderId, 'store');
                    if (!empty($apiResult['success'])) {
                        if ($supplierName === 'Supplier 2') {
                            $status = 'unverified';
                        } elseif ($supplierName === 'Supplier 3') {
                            $status = 'waiting';
                        } elseif ($supplierName === 'Supplier 1') {
                            $status = (stripos($network, 'Ishare') !== false) ? 'completed' : 'processing';
                        } else {
                            if (method_exists(get_class($supplierApi), 'mapStatus')) {
                                $status = $supplierApi::mapStatus($network, $apiResult);
                            } else {
                                $status = 'processing';
                            }
                        }
                        $message = "Sent to " . $supplierName;
                    } else {
                        $status = 'pending';
                        $message = "Queued for dispatch: " . ($apiResult['message'] ?? 'Pending Gateway');
                    }
                    $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?")->execute([$status, $message, $orderId]);
                }
            } catch (Throwable $e) {}
        }

        $newBal = $currentBal - $cost;
        if (function_exists('getUserWalletBalance')) {
            $newBal = getUserWalletBalance($pdo, $userId);
        }

        jsonResponse(true, 'Order created successfully and registered in Admin Portal', [
            'order_id'    => $orderId,
            'reference'   => $ref,
            'cost'        => $cost,
            'role'        => $userRole,
            'status'      => $status,
            'message'     => $message,
            'new_balance' => $newBal
        ]);
    }

    // 3. CREATE AFA REGISTRATION FROM USER ACCOUNT
    if ($op === 'create_afa') {
        $userId   = (int)($requestData['user_id'] ?? $_POST['user_id'] ?? 0);
        $fullName = trim($requestData['full_name'] ?? $_POST['full_name'] ?? '');
        $phoneNum = trim($requestData['phone_number'] ?? $_POST['phone_number'] ?? '');
        $ghaNum   = trim($requestData['gha_number'] ?? $_POST['gha_number'] ?? '');
        $cost     = 15.00;

        if ($userId <= 0 || empty($fullName) || empty($phoneNum)) {
            jsonResponse(false, 'Invalid AFA registration parameters', [], 400);
        }

        // Check user balance
        $stmtU = $pdo->prepare("SELECT id, wallet_balance FROM users WHERE id = ? LIMIT 1");
        $stmtU->execute([$userId]);
        $userRow = $stmtU->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            jsonResponse(false, 'User account not found', [], 404);
        }

        $currentBal = (float)$userRow['wallet_balance'];
        if ($currentBal < $cost) {
            jsonResponse(false, 'Insufficient balance for AFA registration', ['balance' => $currentBal, 'needed' => $cost], 400);
        }

        // Debit wallet
        $ref = 'AFA-' . mt_rand(10000, 99999);
        if (function_exists('addWalletTransaction')) {
            addWalletTransaction($pdo, $userId, $cost, 'debit', $ref, "MTN AFA Registration: {$phoneNum} ({$fullName})");
        }

        // Insert into mtn_afa_registrations for Admin Portal!
        $stmtAfa = $pdo->prepare("
            INSERT INTO mtn_afa_registrations (user_id, full_name, phone_number, gha_number, location, status, message, payment_method, amount_paid, created_at)
            VALUES (?, ?, ?, ?, 'Accra', 'Waiting', 'Submitted via WhatsApp Bot', 'wallet', 15.00, NOW())
        ");
        $stmtAfa->execute([$userId, $fullName, $phoneNum, $ghaNum]);
        $afaId = (int)$pdo->lastInsertId();

        $newBal = $currentBal - $cost;
        if (function_exists('getUserWalletBalance')) {
            $newBal = getUserWalletBalance($pdo, $userId);
        }

        jsonResponse(true, 'AFA Registration created in Admin Portal', [
            'afa_id'      => $afaId,
            'reference'   => $ref,
            'new_balance' => $newBal
        ]);
    }

    // 4. CHECK ORDER STATUS IN DATABASE
    if ($op === 'check_status') {
        $search = trim($requestData['search'] ?? $_POST['search'] ?? $_GET['search'] ?? '');
        $cleanDigits = preg_replace('/\D/', '', $search);
        $orderId = ($cleanDigits !== '' && strlen($cleanDigits) < 9) ? (int)$cleanDigits : 0;
        $cleanPhone = (strlen($cleanDigits) >= 9) ? $cleanDigits : '';

        $order = null;
        if ($orderId > 0) {
            $stmt = $pdo->prepare("
                SELECT o.*, u.username AS user_name, u.payment_ref AS agent_id
                FROM bundle_sends o
                LEFT JOIN users u ON u.id = o.user_id
                WHERE o.id = ?
                LIMIT 1
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$order && !empty($cleanPhone)) {
            $stmt = $pdo->prepare("
                SELECT o.*, u.username AS user_name, u.payment_ref AS agent_id
                FROM bundle_sends o
                LEFT JOIN users u ON u.id = o.user_id
                WHERE o.recipient_phone LIKE ?
                ORDER BY o.id DESC LIMIT 1
            ");
            $stmt->execute(['%' . substr($cleanPhone, -9)]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$order && !empty($search)) {
            $stmt = $pdo->prepare("
                SELECT o.*, u.username AS user_name, u.payment_ref AS agent_id
                FROM bundle_sends o
                LEFT JOIN users u ON u.id = o.user_id
                WHERE o.client_reference = ? OR o.message LIKE ?
                ORDER BY o.id DESC LIMIT 1
            ");
            $stmt->execute([$search, "%{$search}%"]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Also check mtn_afa_registrations if not found in bundle_sends
        $afaOrder = null;
        if (!$order) {
            if ($orderId > 0) {
                $stmt = $pdo->prepare("
                    SELECT a.*, u.username AS user_name, u.payment_ref AS agent_id
                    FROM mtn_afa_registrations a
                    LEFT JOIN users u ON u.id = a.user_id
                    WHERE a.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$orderId]);
                $afaOrder = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        if ($order) {
            jsonResponse(true, 'Order found', ['type' => 'bundle', 'order' => $order]);
        } elseif ($afaOrder) {
            jsonResponse(true, 'AFA order found', ['type' => 'afa', 'order' => $afaOrder]);
        } else {
            jsonResponse(false, 'Order not found in database', [], 404);
        }
    }

    // 5. VERIFY PAYMENT (Paystack Reference or MoMo Transaction ID)
    if ($op === 'verify_payment') {
        $ref   = trim($requestData['reference'] ?? $_POST['reference'] ?? $_GET['reference'] ?? '');
        $phone = trim($requestData['phone'] ?? $_POST['phone'] ?? $_GET['phone'] ?? '');
        $name  = trim($requestData['name'] ?? $_POST['name'] ?? $_GET['name'] ?? 'Customer');
        $agent = (int)($requestData['user_id'] ?? $_POST['user_id'] ?? 0);

        if (empty($ref)) {
            jsonResponse(false, 'Missing payment reference or transaction ID', [], 400);
        }

        require_once __DIR__ . '/classes/WhatsAppBot.php';
        $reply = WhatsAppBot::executePaymentVerification($ref, $agent, '', $phone, $name, $pdo);

        jsonResponse(true, 'Payment verification processed', [
            'reply'     => $reply,
            'reference' => $ref
        ]);
    }

    // 6. BUY RESULT CHECKER CARD
    if ($op === 'buy_checker') {
        $userId   = (int)($requestData['user_id'] ?? $_POST['user_id'] ?? 0);
        $category = trim($requestData['category'] ?? $_POST['category'] ?? 'wassce');
        $phoneNum = trim($requestData['phone'] ?? $_POST['phone'] ?? '');
        $cost     = (float)($requestData['cost'] ?? $_POST['cost'] ?? 0);

        require_once __DIR__ . '/classes/WhatsAppBot.php';
        $res = WhatsAppBot::purchaseResultCheckerCard($userId, $category, $cost, $phoneNum, 'Customer', $pdo);
        jsonResponse(true, 'Checker purchased', ['data' => $res]);
    }

    // 7. LOG CUSTOMER SUPPORT TICKET / REPORT
    if ($op === 'log_ticket') {
        $ticketCode  = trim($requestData['ticket_code'] ?? $_POST['ticket_code'] ?? ('TICK-' . mt_rand(100000, 999999)));
        $senderPhone = trim($requestData['phone'] ?? $_POST['phone'] ?? '');
        $senderName  = trim($requestData['name'] ?? $_POST['name'] ?? 'Customer');
        $message     = trim($requestData['message'] ?? $_POST['message'] ?? '');
        $issueType   = trim($requestData['issue_type'] ?? $_POST['issue_type'] ?? 'general');
        $orderId     = !empty($requestData['order_id']) ? (int)$requestData['order_id'] : null;
        $botReply    = trim($requestData['bot_reply'] ?? $_POST['bot_reply'] ?? '');

        require_once __DIR__ . '/classes/WhatsAppBot.php';
        if ($pdo) {
            try {
                WhatsAppBot::ensureTicketsTable($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO whatsapp_support_tickets (ticket_code, sender_phone, sender_name, order_id, issue_type, message, bot_reply, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'open', NOW())
                ");
                $stmt->execute([$ticketCode, $senderPhone, $senderName, $orderId, $issueType, $message, $botReply]);
            } catch (Throwable $e) {}
        }
        jsonResponse(true, 'Ticket logged successfully', ['ticket_code' => $ticketCode]);
    }
}

$suppliedApiKey = trim($requestData['api_key'] ?? $_POST['api_key'] ?? $_GET['api_key'] ?? '');

$pdo = db_connect();

// 1. Authenticate via Session or API Key
if (session_status() === PHP_SESSION_NONE) {
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }
}

$userId = null;
$user = null;

if (!empty($_SESSION['user'])) {
    $user = $_SESSION['user'];
    $userId = (int)$user['id'];
} else {
    $apiKey = trim($requestData['api_key'] ?? '');
    if (empty($apiKey)) {
        // Check Authorization Header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $apiKey = trim($matches[1]);
        }
    }

    if (empty($apiKey)) {
        jsonResponse(false, 'Missing API Key. Provide it in api_key parameter or Authorization header.', [], 401);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE api_key = :key LIMIT 1");
    $stmt->execute(['key' => $apiKey]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(false, 'Invalid API Key.', [], 401);
    }

    $userId = (int)$user['id'];
}

// Helper to get balance per user and network
function getAvailableBalance($pdo, $userId, $network) {
    return getNetworkBalance($pdo, $userId, $network);
}

// 2. Route API Actions
if ($action === 'send_bundle' || $action === 'send_order' || $action === 'send_ishare') {
    $rawNet    = trim($requestData['network'] ?? '');
    if ($action === 'send_ishare' && empty($rawNet)) $rawNet = 'Ishare';
    $recipient = trim($requestData['recipient'] ?? '');
    $gbAmount  = (float)($requestData['amount'] ?? 0);

    // Normalize network name
    $network = $rawNet;
    $netUpper = strtoupper($rawNet);
    if ($netUpper === 'MTN' || $netUpper === 'MTN GROUP SHARE' || $netUpper === 'MTN DATA') {
        $network = 'MTN';
    } elseif ($netUpper === 'TELECEL' || $netUpper === 'TELECEL GROUP SHARE' || $netUpper === 'VODAFONE') {
        $network = 'Telecel';
    } elseif ($netUpper === 'ISHARE' || $netUpper === 'AT' || $netUpper === 'AIRTELTIGO') {
        $network = 'Ishare';
    }

    // Validate network
    $allowedNetworks = ['MTN', 'Telecel', 'Ishare', 'MTN Group Share', 'Telecel Group Share'];
    if (!in_array($network, $allowedNetworks)) {
        jsonResponse(false, 'Invalid network. Supported networks: MTN, Telecel, Ishare.');
    }

    // Validate phone prefix
    $validatedPhone = normalizeAndValidatePhone($recipient, $network);
    if (!$validatedPhone) {
        jsonResponse(false, "Invalid phone number format or prefix for network: {$network}.");
    }

    $refundRestriction = function_exists('getPhoneRefundRestriction') ? getPhoneRefundRestriction($pdo, $validatedPhone) : null;
    if ($refundRestriction) {
        jsonResponse(false, $refundRestriction['message'], [
            'code'            => 'REFUND_RESTRICTED',
            'phone'           => $validatedPhone,
            'cooldown_until'  => $refundRestriction['unlock_time'],
            'remaining_days'  => $refundRestriction['remaining_days'],
            'remaining_secs'  => $refundRestriction['remaining_seconds']
        ], 422);
    }

    // Validate if MTN number is registered
    $force = filter_var($requestData['force'] ?? $_POST['force'] ?? $_GET['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$force && stripos($network, 'MTN') !== false && stripos($network, 'Group') === false) {
        $checkReg = $pdo->prepare("SELECT 1 FROM mtn_registered_numbers WHERE phone = ?");
        $checkReg->execute([$validatedPhone]);
        if (!$checkReg->fetch()) {
            jsonResponse(false, "Number is not registered on this system. Please upload your number for verification. We will notify you when the number is registered. Thank you.");
        }
    }

    // Validate amount
    $minGb = (stripos($network, 'Telecel') !== false) ? 2 : 1;
    if ($gbAmount < $minGb || $gbAmount > 100) {
        jsonResponse(false, "GB amount must be between {$minGb} and 100.");
    }

    // Fetch user info: free_mode, role, wallet balance
    $stmtU = $pdo->prepare("SELECT role, free_mode, debt, wallet_balance FROM users WHERE id = ?");
    $stmtU->execute([$userId]);
    $uData = $stmtU->fetch(PDO::FETCH_ASSOC) ?: [];

    $userRole      = $uData['role'] ?? 'client';
    $freeMode      = (int)($uData['free_mode'] ?? 0);
    $walletBalance = (float)($uData['wallet_balance'] ?? 0.0);

    // Calculate bundle cost in GHS for this user role
    $cost = function_exists('getGbPriceGhs') ? getGbPriceGhs($pdo, $network, $gbAmount, $userRole) : 0.0;

    // Validate balance / determine payment method
    $isSandbox = (bool)($requestData['sandbox'] ?? $requestData['test_mode'] ?? false);
    $paidViaFreeMode = false;
    $paidViaWallet   = false;
    $paidViaGb       = false;

    if (!$isSandbox) {
        if ($freeMode) {
            $paidViaFreeMode = true;
        } else {
            $gbBalance = getAvailableBalance($pdo, $userId, $network);
            if ($gbBalance >= $gbAmount) {
                $paidViaGb = true;
            } elseif ($cost > 0 && $walletBalance >= $cost) {
                $paidViaWallet = true;
            } else {
                jsonResponse(false, "Insufficient balance. Available: " . number_format($gbBalance, 2) . " GB {$network} balance, or GHS " . number_format($walletBalance, 2) . " wallet balance (Cost: GHS " . number_format($cost, 2) . ").", [
                    'available_gb_balance'     => $gbBalance,
                    'available_wallet_balance' => $walletBalance,
                    'required_cost_ghs'        => $cost
                ]);
            }
        }
    }

    $callbackUrl = trim($requestData['callback_url'] ?? $requestData['webhook_url'] ?? $_POST['callback_url'] ?? $_POST['webhook_url'] ?? '');
    if (empty($callbackUrl) && !empty($user['webhook_url'])) {
        $callbackUrl = trim($user['webhook_url']);
    }
    $clientRef = trim($requestData['reference'] ?? $requestData['client_reference'] ?? $_POST['reference'] ?? $_POST['client_reference'] ?? '');

    try {
        $orderStatus = $isSandbox ? 'sandbox' : 'pending';
        $orderChannel = 'api';

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, status, channel, callback_url, client_reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $network, $validatedPhone, $gbAmount, $orderStatus, $orderChannel, $callbackUrl ?: null, $clientRef ?: null]);
        $orderId = (int)$pdo->lastInsertId();

        $clientCode = str_pad(($orderId * 397 + 137) % 900 + 100, 3, '0', STR_PAD_LEFT);

        // Deduct payment or record free mode debt
        if (!$isSandbox) {
            $ref = 'API-' . mt_rand(10000, 99999);
            $descr = "API Data Bundle: {$network} {$gbAmount}GB to {$validatedPhone}";
            if ($paidViaFreeMode) {
                if (function_exists('addFreeModeTransaction')) {
                    addFreeModeTransaction($pdo, $userId, $cost, $ref, $descr);
                } else {
                    $pdo->prepare("UPDATE users SET debt = debt + ? WHERE id = ?")->execute([$cost, $userId]);
                    $stmtTx = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, 'debit', ?, ?, ?, 'success')");
                    $stmtTx->execute([$userId, $cost, $ref, $descr . " [FREE MODE DEBT]", $walletBalance, $walletBalance]);
                }
            } elseif ($paidViaWallet && $cost > 0) {
                if (function_exists('addWalletTransaction')) {
                    addWalletTransaction($pdo, $userId, $cost, 'debit', $ref, $descr);
                } else {
                    $newBal = $walletBalance - $cost;
                    $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$newBal, $userId]);
                    $stmtTx = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, 'debit', ?, ?, ?, 'success')");
                    $stmtTx->execute([$userId, $cost, $ref, $descr, $walletBalance, $newBal]);
                }
            }
        }
        $pdo->commit();

        if (!$isSandbox) {
            require_once __DIR__ . '/classes/SupplierApi.php';
            require_once __DIR__ . '/classes/Supplier2Api.php';
            require_once __DIR__ . '/classes/Supplier3Api.php';
            require_once __DIR__ . '/classes/Supplier5Api.php';
                require_once __DIR__ . '/classes/BackupApi.php';
            require_once __DIR__ . '/classes/MtnUp2uApi.php';
            require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
            require_once __DIR__ . '/classes/NitghtApi.php';
            
            $mtnUp2GbApi = MtnUp2uPortalApi::isNetworkEnabled($network, $gbAmount) ? new MtnUp2uPortalApi() : null;
            $mtnUp2uApi  = MtnUp2uApi::isNetworkEnabled($network, $gbAmount)  ? new MtnUp2uApi()  : null;
            $nitghtApi   = NitghtApi::isNetworkEnabled($network, $gbAmount)   ? new NitghtApi()   : null;
            $s1Api = SupplierApi::isNetworkEnabled($network, $gbAmount) ? new SupplierApi() : null;
            $s2Api = (strtolower($network) === 'mtn' && Supplier2Api::isNetworkEnabled('mtn', $gbAmount)) ? new Supplier2Api() : null;
            $s3Api = (strtolower($network) === 'mtn' && Supplier3Api::isNetworkEnabled('mtn', $gbAmount)) ? new Supplier3Api() : null;
            $s4Api = Supplier5Api::isNetworkEnabled($network, $gbAmount) ? new Supplier5Api() : null;
                $backupApi = BackupApi::isNetworkEnabled($network, $gbAmount) ? new BackupApi() : null;
            
            $resolvedSupp = resolve_order_supplier($network, $gbAmount, $validatedPhone, $pdo);
            $supplierApi  = $resolvedSupp['api'];
            $supplierName = $resolvedSupp['name'];
            
            if (!$supplierApi) {
                $pdo->prepare("UPDATE bundle_sends SET status = 'pending', message = 'API Offline - Pending dispatch' WHERE id = ?")->execute([$orderId]);
            }
            
            if ($supplierApi) {
                $apiResult = $supplierApi->sendBundle($network, $validatedPhone, $gbAmount, $orderId, 'store');

                if ($apiResult['success']) {
                    if ($supplierName === 'Supplier 2') {
                        $orderStatus = 'unverified';
                    } elseif ($supplierName === 'Supplier 3') {
                        $orderStatus = 'waiting';
                    } elseif ($supplierName === 'Supplier 1') {
                        if (stripos($network, 'MTN') !== false) {
                            $orderStatus = 'processing';
                        } elseif (stripos($network, 'Telecel') !== false) {
                            $orderStatus = 'initiated';
                        } elseif (stripos($network, 'Ishare') !== false) {
                            $orderStatus = 'completed';
                        } else {
                            $orderStatus = 'processing';
                        }
                    } elseif ($supplierName === 'NITGHT') {
                        $orderStatus = NitghtApi::mapStatus($network, $apiResult);
                        $apiMsg = strtolower($apiResult['message'] ?? $apiResult['data']['message'] ?? '');
                        if ($orderStatus === 'validating' || stripos($apiMsg, 'awaiting') !== false || stripos($apiMsg, 'validating') !== false) {
                            $rerouteRes = reroute_order_by_priority($pdo, $orderId, $network, $validatedPhone, (float)$gbAmount, 'nitght', 'Night API');
                            if (!empty($rerouteRes['rerouted'])) {
                                $orderStatus = $rerouteRes['status'];
                                $message = $rerouteRes['message'];
                            }
                        }
                    } elseif ($supplierName === 'MTNUP2U') {
                        $orderStatus = MtnUp2uApi::mapStatus($network, $apiResult);
                    } elseif ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') {
                        $orderStatus = MtnUp2GbApi::mapStatus($network, $apiResult);
                    } else {
                        $orderStatus = SupplierApi::mapStatus($network, $apiResult);
                    }
                    $supplierData = $apiResult['data'] ?? [];
                    $suppId = $apiResult['reference'] ?? $apiResult['order_id'] ?? $apiResult['transaction_id'] ?? $apiResult['id'] ?? $supplierData['reference'] ?? $supplierData['reference_id'] ?? $supplierData['order_id'] ?? $supplierData['transaction_id'] ?? $supplierData['trx_ref'] ?? $supplierData['id'] ?? '';
                    if (empty($message) || strpos($message, 'Rerouted') === false) {
                        $message = "Sent to " . $supplierName . ". Order ID: " . $suppId;
                    }

                    $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                    $stmtUpdate->execute([$orderStatus, $message, $orderId]);
                } else {
                    require_once __DIR__ . '/classes/SupplierApi.php';
                    $errReason = SupplierApi::sanitizeErrorMessage($apiResult['message'] ?? 'Unknown error');
                    if (isBeneficiaryError($errReason) || isBeneficiaryError($apiResult['message'] ?? '')) {
                        $orderStatus = 'unverified';
                        $message = "Beneficiary Pending List";

                        $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                        $stmtUpdate->execute([$orderStatus, $message, $orderId]);

                        jsonResponse(true, "Bundle send order submitted for verification (Beneficiary pending list).", [
                            'order_id' => $orderId,
                            'client_code' => $clientCode,
                            'status' => 'unverified'
                        ]);
                    } elseif (isInsufficientBalanceError($errReason)) {
                        $orderStatus = 'pending';
                        $message = "Pending manual dispatch";

                        $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                        $stmtUpdate->execute([$orderStatus, $message, $orderId]);

                        jsonResponse(true, "Bundle send order initiated and set to pending.", [
                            'order_id' => $orderId,
                            'client_code' => $clientCode,
                            'status' => 'pending'
                        ]);
                    } else {
                        $orderStatus = 'failed';
                        $message = $errReason;

                        $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ?, refund_transferred = 1, refunded_at = NOW(), updated_at = NOW() WHERE id = ?");
                        $stmtUpdate->execute([$orderStatus, $message, $orderId]);

                        if (!$isSandbox) {
                            if ($paidViaFreeMode && $cost > 0) {
                                $pdo->prepare("UPDATE users SET debt = GREATEST(0, debt - ?) WHERE id = ?")->execute([$cost, $userId]);
                                $stmtTx = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, 'credit', ?, ?, ?, 'success')");
                                $stmtTx->execute([$userId, $cost, 'REFUND-' . $orderId, "Refund for failed Free Mode API order #{$orderId}: " . $errReason . " [DEBT REVERSED]", $walletBalance, $walletBalance]);
                            } elseif ($paidViaWallet && $cost > 0) {
                                addWalletTransaction($pdo, $userId, $cost, 'credit', 'REFUND-' . $orderId, "Refund for failed supplier order #{$orderId}: " . $errReason);
                                if (function_exists('sendRefundSmsToUser')) {
                                    sendRefundSmsToUser($pdo, $userId, $cost, $orderId, $network, (float)$gbAmount, $validatedPhone);
                                }
                            }
                        }

                        jsonResponse(false, "Supplier Error: " . $errReason, [
                            'order_id' => $orderId,
                            'client_code' => $clientCode,
                            'status' => $orderStatus
                        ]);
                    }
                }
            }
        }

        jsonResponse(true, "Bundle send order initiated successfully" . ($isSandbox ? " (SANDBOX MODE)" : "") . ".", [
            'order_id'       => $orderId,
            'client_code'    => $clientCode,
            'network'        => $network,
            'recipient'      => $validatedPhone,
            'gb_amount'      => $gbAmount,
            'status'         => $orderStatus,
            'cost_ghs'       => $cost,
            'payment_method' => $paidViaFreeMode ? 'free_mode' : ($paidViaWallet ? 'wallet' : 'gb_balance'),
            'sandbox'        => $isSandbox
        ]);
    } catch (Exception $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }

} elseif ($action === 'register_afa') {
    $fullName    = trim($requestData['full_name'] ?? '');
    $phoneNumber = trim($requestData['phone_number'] ?? '');
    $ghaNumber   = trim($requestData['gha_number'] ?? '');
    $location    = trim($requestData['location'] ?? '');

    if (empty($fullName) || empty($phoneNumber) || empty($ghaNumber) || empty($location)) {
        jsonResponse(false, 'Missing required parameters: full_name, phone_number, gha_number, location.');
    }

    if (!preg_match('/^\d{10}$/', $phoneNumber)) {
        jsonResponse(false, "Phone Number must be exactly 10 digits.");
    }
    if (!preg_match('/^[Gg][Hh][Aa]-\d{9}-\d$/i', $ghaNumber)) {
        jsonResponse(false, "Ghana Card must be in the format GHA-123456789-0.");
    }

    // Check for existing duplicate AFA registration
    $stmtChk = $pdo->prepare("SELECT status FROM mtn_afa_registrations WHERE phone_number = ? AND status NOT IN ('Failed', 'Rejected', 'Cancelled') ORDER BY id DESC LIMIT 1");
    $stmtChk->execute([$phoneNumber]);
    $existingAfa = $stmtChk->fetch();
    if ($existingAfa) {
        jsonResponse(false, "Duplicate AFA Registration: Phone number {$phoneNumber} already has an active or completed registration (Status: {$existingAfa['status']}).");
    }

    $isSandbox = (bool)($requestData['sandbox'] ?? $requestData['test_mode'] ?? false);

    $requestedPaymentMethod = strtolower(trim($requestData['payment_method'] ?? $requestData['pay_with'] ?? $requestData['payment_type'] ?? 'auto'));
    $paymentMethod = null;
    $baseAfaPrice = 0.00;

    if (!$isSandbox) {
        $stmtU = $pdo->prepare("SELECT wallet_balance, afa_balance, afa_price, role, free_mode FROM users WHERE id = ?");
        $stmtU->execute([$userId]);
        $uData = $stmtU->fetch();

        $afaBalance    = (int)($uData['afa_balance'] ?? 0);
        $walletBalance = (float)($uData['wallet_balance'] ?? 0.00);
        $userRole      = $uData['role'] ?? 'client';
        $freeMode      = (int)($uData['free_mode'] ?? 0);
        $userAfaPrice  = ($uData && $uData['afa_price'] !== null && $uData['afa_price'] !== '') ? (float)$uData['afa_price'] : null;

        $roleAfaPrice = null;
        try {
            $stmtRole = $pdo->prepare("SELECT standard_price FROM afa_pricing WHERE role = ?");
            $stmtRole->execute([$userRole]);
            $res = $stmtRole->fetchColumn();
            if ($res !== false) {
                $roleAfaPrice = (float)$res;
            }
        } catch (Exception $e) {}

        $allSettings = load_all_settings($pdo);
        $settingsAfaPrice = (float)($allSettings['mtn_afa_price'] ?? 10.00);
        if ($roleAfaPrice !== null) {
            $settingsAfaPrice = $roleAfaPrice;
        }
        $baseAfaPrice = ($userAfaPrice !== null && $userAfaPrice >= 0) ? $userAfaPrice : $settingsAfaPrice;

        if (in_array($requestedPaymentMethod, ['slot', 'slots', 'afa_slot', 'afa_slots', 'afa'])) {
            if ($afaBalance >= 1) {
                $paymentMethod = 'slot';
            } elseif ($freeMode) {
                $paymentMethod = 'free_mode';
            } else {
                jsonResponse(false, "Insufficient AFA slots. You need at least 1 AFA slot to complete this registration (Available: {$afaBalance} slot(s)).", [
                    'available_afa_slots' => $afaBalance,
                    'required_afa_slots'  => 1,
                    'wallet_balance_ghs'  => $walletBalance
                ]);
            }
        } elseif (in_array($requestedPaymentMethod, ['wallet', 'balance', 'main_wallet', 'cash', 'ghs'])) {
            if ($freeMode) {
                $paymentMethod = 'free_mode';
            } elseif ($walletBalance >= $baseAfaPrice) {
                $paymentMethod = 'wallet';
            } else {
                jsonResponse(false, "Insufficient wallet balance. You need at least GHS " . number_format($baseAfaPrice, 2) . " in your wallet balance to complete this registration (Available: GHS " . number_format($walletBalance, 2) . ").", [
                    'available_wallet_balance_ghs' => $walletBalance,
                    'required_wallet_balance_ghs'  => $baseAfaPrice,
                    'available_afa_slots'          => $afaBalance
                ]);
            }
        } else {
            // Auto fallback: use slot if available, otherwise wallet or free mode
            if ($afaBalance >= 1) {
                $paymentMethod = 'slot';
            } elseif ($freeMode) {
                $paymentMethod = 'free_mode';
            } elseif ($walletBalance >= $baseAfaPrice) {
                $paymentMethod = 'wallet';
            } else {
                jsonResponse(false, "Insufficient AFA slots or wallet balance. You need at least 1 AFA slot or GHS " . number_format($baseAfaPrice, 2) . " in your wallet balance. (Available: {$afaBalance} slot(s), GHS " . number_format($walletBalance, 2) . ").", [
                    'available_afa_slots' => $afaBalance,
                    'wallet_balance_ghs'  => $walletBalance,
                    'required_ghs'        => $baseAfaPrice
                ]);
            }
        }
    }

    $callbackUrl = trim($requestData['callback_url'] ?? $requestData['webhook_url'] ?? '');
    if (empty($callbackUrl) && !empty($user['webhook_url'])) {
        $callbackUrl = trim($user['webhook_url']);
    }

    try {
        if (!$isSandbox) {
            $pdo->beginTransaction();
            
            $regStatus = 'Waiting';
            $defaultMessage = "Registration received. Awaiting admin review.";
            
            try {
                $stmt = $pdo->prepare("INSERT INTO mtn_afa_registrations (user_id, full_name, phone_number, gha_number, location, status, message, callback_url, payment_method, amount_paid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $fullName, $phoneNumber, strtoupper($ghaNumber), $location, $regStatus, $defaultMessage, $callbackUrl ?: null, $paymentMethod, ($paymentMethod === 'slot' ? 0.00 : $baseAfaPrice)]);
            } catch (Exception $e) {
                $stmt = $pdo->prepare("INSERT INTO mtn_afa_registrations (user_id, full_name, phone_number, gha_number, location, status, message, callback_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $fullName, $phoneNumber, strtoupper($ghaNumber), $location, $regStatus, $defaultMessage, $callbackUrl ?: null]);
            }
            $regId = (int)$pdo->lastInsertId();

            if ($paymentMethod === 'slot') {
                $balBefore = logTransactionBefore($pdo, $userId, 'afa');
                $stmtUpdate = $pdo->prepare("UPDATE users SET afa_balance = afa_balance - 1 WHERE id = ?");
                $stmtUpdate->execute([$userId]);
                logTransactionAfter($pdo, $userId, 'afa', $balBefore, 'deduct', 1, "AFA API Registration (Phone: {$phoneNumber})");
            } elseif ($paymentMethod === 'free_mode') {
                $ref = 'AFA-' . time() . '-' . rand(100, 999);
                $desc = "Paid GHS " . number_format($baseAfaPrice, 2) . " for AFA API Registration (Phone: {$phoneNumber})";
                if (function_exists('addFreeModeTransaction')) {
                    addFreeModeTransaction($pdo, $userId, $baseAfaPrice, $ref, $desc);
                } else {
                    $pdo->prepare("UPDATE users SET debt = debt + ? WHERE id = ?")->execute([$baseAfaPrice, $userId]);
                    $stmtTx = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, 'debit', ?, ?, ?, 'success')");
                    $stmtTx->execute([$userId, $baseAfaPrice, $ref, $desc . " [FREE MODE DEBT]", $walletBalance, $walletBalance]);
                }
            } else {
                $balBefore = logTransactionBefore($pdo, $userId, 'wallet');
                $stmtUpdate = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
                $stmtUpdate->execute([$baseAfaPrice, $userId]);
                logTransactionAfter($pdo, $userId, 'wallet', $balBefore, 'deduct', $baseAfaPrice, "Paid GHS " . number_format($baseAfaPrice, 2) . " for AFA API Registration (Phone: {$phoneNumber})");
            }
            
            $pdo->commit();
            
            jsonResponse(true, "MTN AFA registration initiated successfully.", [
                'registration_id'          => $regId,
                'full_name'                => $fullName,
                'phone_number'             => $phoneNumber,
                'gha_number'               => strtoupper($ghaNumber),
                'location'                 => $location,
                'status'                   => $regStatus,
                'message'                  => $defaultMessage,
                'paid_via'                 => $paymentMethod === 'slot' ? 'AFA Slot' : ($paymentMethod === 'free_mode' ? 'Free Mode Debt' : 'Wallet Balance'),
                'payment_method_used'      => $paymentMethod,
                'deducted_slots'           => $paymentMethod === 'slot' ? 1 : 0,
                'deducted_amount_ghs'      => ($paymentMethod === 'wallet' || $paymentMethod === 'free_mode') ? $baseAfaPrice : 0.00,
                'remaining_afa_slots'      => $paymentMethod === 'slot' ? max(0, $afaBalance - 1) : $afaBalance,
                'remaining_wallet_balance' => $paymentMethod === 'wallet' ? round(max(0, $walletBalance - $baseAfaPrice), 2) : round($walletBalance, 2)
            ]);
        } else {
            jsonResponse(true, "MTN AFA registration initiated successfully (SANDBOX MODE).", [
                'registration_id' => 999,
                'full_name' => $fullName,
                'phone_number' => $phoneNumber,
                'gha_number' => strtoupper($ghaNumber),
                'location' => $location,
                'status' => 'sandbox',
                'message' => 'Simulated test registration.'
            ]);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }

} elseif ($action === 'check_status') {
    $type    = strtolower(trim($requestData['type'] ?? ''));
    $orderId = trim($requestData['order_id'] ?? '');

    if (empty($type) || empty($orderId)) {
        jsonResponse(false, 'Both type (bundle/afa) and order_id parameters are required.');
    }

    if (!in_array($type, ['bundle', 'afa', 'store'])) {
        jsonResponse(false, 'Invalid status type. Supported: bundle, afa, store.');
    }

    // Attempt to decode the 3-digit client code if passed (length === 3)
    $decodedId = null;
    if (strlen($orderId) === 3 && is_numeric($orderId)) {
        // Reverse formula:
        // (db_id * 397 + 137) % 900 + 100 = orderId
        // Find matching DB ID under current user
        $targetTable = ($type === 'bundle' || $type === 'store') ? 'bundle_sends' : 'mtn_afa_registrations';
        $stmt = $pdo->prepare("SELECT id FROM {$targetTable} WHERE user_id = ?");
        $stmt->execute([$userId]);
        $allIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($allIds as $dbId) {
            $code = str_pad(($dbId * 397 + 137) % 900 + 100, 3, '0', STR_PAD_LEFT);
            if ($code === $orderId) {
                $decodedId = (int)$dbId;
                break;
            }
        }
    } else {
        $decodedId = (int)$orderId;
    }

    if (!$decodedId) {
        jsonResponse(false, 'Order not found.');
    }

    if ($type === 'bundle' || $type === 'store') {
        $stmt = $pdo->prepare("SELECT * FROM bundle_sends WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$decodedId, $userId]);
        $order = $stmt->fetch();

        if (!$order) {
            jsonResponse(false, 'Bundle order not found.');
        }

        $clientCode = str_pad(($order['id'] * 397 + 137) % 900 + 100, 3, '0', STR_PAD_LEFT);

        jsonResponse(true, 'Bundle order status retrieved.', [
            'order_id' => (int)$order['id'],
            'client_code' => $clientCode,
            'network' => $order['network'],
            'recipient' => $order['recipient_phone'],
            'gb_amount' => (float)$order['gb_amount'],
            'status' => $order['status'],
            'message' => $order['message'] ?: 'Awaiting processing',
            'created_at' => $order['created_at']
        ]);
    } else {
        // AFA
        $stmt = $pdo->prepare("SELECT * FROM mtn_afa_registrations WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$decodedId, $userId]);
        $reg = $stmt->fetch();

        if (!$reg) {
            jsonResponse(false, 'AFA registration not found.');
        }

        // Apply custom status fallbacks if empty
        $dispMsg = trim($reg['message'] ?? '');
        if ($dispMsg === '') {
            $st = strtolower($reg['status']);
            if ($st === 'registered') { $dispMsg = 'Registration completed successfully.'; }
            elseif ($st === 'rejected') { $dispMsg = 'Rejected. Details mismatch or invalid.'; }
            elseif ($st === 'initiated') { $dispMsg = 'Processing your AFA registration.'; }
            else { $dispMsg = 'Awaiting admin processing.'; }
        }

        jsonResponse(true, 'AFA registration status retrieved.', [
            'registration_id' => (int)$reg['id'],
            'full_name' => $reg['full_name'],
            'phone_number' => $reg['phone_number'],
            'gha_number' => $reg['gha_number'],
            'location' => $reg['location'],
            'status' => $reg['status'],
            'message' => $dispMsg,
            'created_at' => $reg['created_at']
        ]);
    }

} elseif ($action === 'get_wallet') {
    $stmt = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Provide mappings for JS
    $formatted = array_map(function($t) {
        return [
            'type' => $t['type'] ?? 'credit',
            'amount' => $t['amount'] ?? 0,
            'description' => $t['description'] ?? '',
            'date' => $t['created_at'] ?? '',
            'reference' => $t['reference'] ?? '',
            'balance_before' => $t['balance_before'] ?? 0,
            'balance_after' => $t['balance_after'] ?? 0
        ];
    }, $txns);

    jsonResponse(true, 'Transactions retrieved.', [
        'transactions' => $formatted,
        'account' => [
            'wallet_balance' => getAvailableBalance($pdo, $userId, 'MTN')
        ]
    ]);

} elseif ($action === 'check_balance') {
    $mtn = getAvailableBalance($pdo, $userId, 'MTN');
    $telecel = getAvailableBalance($pdo, $userId, 'Telecel');
    $ishare = getAvailableBalance($pdo, $userId, 'Ishare');
    
    $stmt = $pdo->prepare("SELECT afa_balance FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $afa = (int)$stmt->fetchColumn();

    $mainWallet = function_exists('getUserWalletBalance') ? getUserWalletBalance($pdo, $userId) : 0;

    jsonResponse(true, 'Balances retrieved successfully.', [
        'balances' => [
            'MTN' => [
                'amount' => $mtn,
                'unit' => 'GB'
            ],
            'Telecel' => [
                'amount' => $telecel,
                'unit' => 'GB'
            ],
            'Ishare' => [
                'amount' => $ishare,
                'unit' => 'GB'
            ],
            'AFA' => [
                'amount' => $afa,
                'unit' => 'Qty'
            ],
            'Main_Wallet' => [
                'amount' => round($mainWallet, 2),
                'unit' => 'GHS'
            ]
        ]
    ]);

} elseif ($action === 'get_store_products') {
    // Return all store products: Digital (WASSCE, BECE, NETFLIX) + Data Packages
    $settings = load_all_settings($pdo);
    $roleSafe = str_replace(' ', '_', strtolower(trim($user['role'] ?? 'agent')));
    
    $digitalProducts = [];
    $cats = ['wassce', 'bece', 'netflix'];
    foreach ($cats as $cat) {
        $price = (float)($settings[$cat . '_price_' . $roleSafe] ?? $settings[$cat . '_price'] ?? 0);
        if ($price > 0) {
            $digitalProducts[] = [
                'product_id' => $cat,
                'name' => strtoupper($cat) . ' PIN/Account',
                'type' => 'digital',
                'price_ghs' => $price
            ];
        }
    }
    
    // Data packages from network_pricing
    $roleCol = 'price_client';
    $r = strtolower(trim($user['role'] ?? 'client'));
    if ($r === 'admin') $roleCol = 'price_admin';
    elseif (in_array($r, ['agent', 'dealer', 'reseller', 'dealers'])) $roleCol = 'price_dealer';
    elseif ($r === 'vip') $roleCol = 'price_vip';
    elseif ($r === 'elite') $roleCol = 'price_elite';
    
    $dataProducts = [];
    $stmt = $pdo->query("SELECT id as product_id, network, gb_amount, package_label, COALESCE(NULLIF($roleCol, 0), price_ghs) AS price_ghs FROM network_pricing WHERE is_active = 1 AND network IN ('MTN', 'Telecel', 'Ishare') ORDER BY network ASC, sort_order ASC, gb_amount ASC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dataProducts[] = [
            'product_id' => (int)$row['product_id'],
            'name' => $row['network'] . ' ' . (!empty($row['package_label']) ? $row['package_label'] : $row['gb_amount'] . 'GB'),
            'type' => 'data',
            'network' => $row['network'],
            'gb_amount' => (float)$row['gb_amount'],
            'price_ghs' => (float)$row['price_ghs']
        ];
    }
    
    jsonResponse(true, 'Store products retrieved.', [
        'digital_products' => $digitalProducts,
        'data_products' => $dataProducts
    ]);

} elseif ($action === 'store_order') {
    $productId = trim($requestData['product_id'] ?? '');
    $recipient = trim($requestData['recipient'] ?? '');
    
    if (empty($productId)) {
        jsonResponse(false, 'Missing product_id.');
    }
    
    $settings = load_all_settings($pdo);
    $walletBalance = function_exists('getUserWalletBalance') ? getUserWalletBalance($pdo, $userId) : 0;
    
    // Fetch free_mode status
    $stmtFree = $pdo->prepare("SELECT free_mode FROM users WHERE id = ?");
    $stmtFree->execute([$userId]);
    $freeMode = (int)$stmtFree->fetchColumn();
    
    // Check if Digital Product
    if (in_array(strtolower($productId), ['wassce', 'bece', 'netflix'])) {
        $category = strtolower($productId);
        $roleSafe = str_replace(' ', '_', strtolower(trim($user['role'] ?? 'agent')));
        $price = (float)($settings[$category . '_price_' . $roleSafe] ?? $settings[$category . '_price'] ?? 0);
        
        if ($price <= 0) {
            jsonResponse(false, 'Pricing error for digital product.');
        }
        if (!$freeMode && $price > $walletBalance) {
            jsonResponse(false, 'Insufficient balance. You need GHS ' . number_format($price, 2));
        }
        
        $pdo->beginTransaction();
        $stmtFind = $pdo->prepare("SELECT id, details FROM digital_products WHERE category = ? AND status = 'available' LIMIT 1 FOR UPDATE");
        $stmtFind->execute([$category]);
        $item = $stmtFind->fetch();
        
        $usingApi = false;
        if (!$item) {
            if (($category === 'wassce' || $category === 'bece') && !empty($settings['checker1_enabled'])) {
                require_once __DIR__ . '/classes/Checker1Api.php';
                $api = new Checker1Api();
                $resp = $api->purchaseChecker($category);
                if (!$resp['success']) {
                    $pdo->rollBack();
                    jsonResponse(false, 'API Error: ' . $resp['message']);
                }
                $finalLink = ($category === 'wassce') ? 'https://ghana.waecdirect.org' : ($resp['link'] ?? 'https://eresults.waecgh.org/');
                $item = [
                    'id' => 'API-' . ($resp['reference'] ?? time()),
                    'details' => "Type: " . strtoupper($category) . "\nPIN: " . $resp['pin'] . "\nSerial Number: " . $resp['serial'] . "\nChecker Link: " . $finalLink
                ];
                $usingApi = true;
            } else {
                $pdo->rollBack();
                jsonResponse(false, strtoupper($category) . ' is currently out of stock.');
            }
        }
        
        $desc = "Purchased " . strtoupper($category) . ($usingApi ? " Card via API" : " Card/Login");
        if ($freeMode) {
            $pdo->prepare("UPDATE users SET debt = debt + ? WHERE id = ?")->execute([$price, $userId]);
            $ref = 'DIG-' . mt_rand(10000, 99999);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, 'debit', ?, ?, ?, 'success')")
                ->execute([$userId, $price, $ref, $desc . " [FREE MODE DEBT]", $walletBalance, $walletBalance]);
            $newBal = $walletBalance;
        } else {
            $newBal = $walletBalance - $price;
            $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$newBal, $userId]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id, type, amount, balance_after, description) VALUES (?, 'debit', ?, ?, ?)")->execute([$userId, $price, $newBal, $desc]);
        }
        
        if ($usingApi) {
            $pdo->prepare("INSERT INTO digital_products (category, details, status, buyer_id, purchased_at) VALUES (?, ?, 'sold', ?, NOW())")->execute([$category, $item['details'], $userId]);
        } else {
            $pdo->prepare("UPDATE digital_products SET status = 'sold', buyer_id = ?, purchased_at = NOW() WHERE id = ?")->execute([$userId, $item['id']]);
        }
        $pdo->commit();
        
        if (!empty($recipient)) {
            require_once __DIR__ . '/classes/SmsApi.php';
            try {
                $smsApi = new SmsApi();
                if ($category === 'netflix') {
                    $smsApi->setSenderId('BUS-REG');
                    $smsText = "Thank you Buying from us Trial {$item['id']} . these are your logins detils , do not share . fsiler to follow is instrcuction will be remove from plan .\n\n{$item['details']}";
                } else {
                    $smsApi->setSenderId('E-VOUCHER');
                    $smsText = "Here is your " . strtoupper($category) . " PIN:\n" . $item['details'];
                }
                $smsApi->send($recipient, $smsText);
            } catch (Exception $e) {}
        }
        
        jsonResponse(true, 'Digital product purchased successfully.', [
            'product_id' => $category,
            'details' => $item['details'],
            'price_deducted' => $price,
            'balance_after' => $newBal
        ]);
        
    } elseif (is_numeric($productId)) {
        $roleCol = 'price_client';
        $r = strtolower(trim($user['role'] ?? 'client'));
        if ($r === 'admin') $roleCol = 'price_admin';
        elseif (in_array($r, ['agent', 'dealer', 'reseller', 'dealers'])) $roleCol = 'price_dealer';
        elseif ($r === 'vip') $roleCol = 'price_vip';
        elseif ($r === 'elite') $roleCol = 'price_elite';
        
        $stmtPkg = $pdo->prepare("SELECT network, gb_amount, COALESCE(NULLIF($roleCol, 0), price_ghs) AS price_ghs FROM network_pricing WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmtPkg->execute([$productId]);
        $pkg = $stmtPkg->fetch(PDO::FETCH_ASSOC);
        
        if (!$pkg) {
            jsonResponse(false, 'Invalid or disabled data product_id.');
        }
        
        if (empty($recipient)) {
            jsonResponse(false, 'Missing recipient phone number.');
        }
        
        $network = $pkg['network'];
        $gbAmount = (float)$pkg['gb_amount'];
        $cost = (float)$pkg['price_ghs'];

        $netLower = strtolower($network);
        if (strpos($netLower, 'mtn') !== false && empty($settings['mtn_enabled'])) {
            jsonResponse(false, 'MTN Data service is currently offline for maintenance. Please check back shortly.');
        } elseif (strpos($netLower, 'telecel') !== false && empty($settings['telecel_enabled'])) {
            jsonResponse(false, 'Telecel Data service is currently offline for maintenance. Please check back shortly.');
        } elseif ((strpos($netLower, 'ishare') !== false || strpos($netLower, 'at') !== false || strpos($netLower, 'airteltigo') !== false) && empty($settings['ishare_enabled'])) {
            jsonResponse(false, 'AT / Ishare Data service is currently offline for maintenance. Please check back shortly.');
        }
        
        $validatedPhone = normalizeAndValidatePhone($recipient, $network);
        if (!$validatedPhone) {
            jsonResponse(false, "Invalid phone number format or prefix for network: {$network}.");
        }

        $refundRestriction = function_exists('getPhoneRefundRestriction') ? getPhoneRefundRestriction($pdo, $validatedPhone) : null;
        if ($refundRestriction) {
            jsonResponse(false, $refundRestriction['message'], [
                'code'            => 'REFUND_RESTRICTED',
                'phone'           => $validatedPhone,
                'cooldown_until'  => $refundRestriction['unlock_time'],
                'remaining_days'  => $refundRestriction['remaining_days'],
                'remaining_secs'  => $refundRestriction['remaining_seconds']
            ], 422);
        }
        
        if (!$freeMode && $cost > $walletBalance) {
            jsonResponse(false, "Insufficient balance. You need GHS " . number_format($cost, 2));
        }
        
        $isSandbox = (bool)($requestData['sandbox'] ?? $requestData['test_mode'] ?? false);
        $orderStatus = $isSandbox ? 'sandbox' : 'pending';

        $callbackUrl = trim($requestData['callback_url'] ?? $requestData['webhook_url'] ?? $_POST['callback_url'] ?? $_POST['webhook_url'] ?? '');
        if (empty($callbackUrl) && !empty($user['webhook_url'])) {
            $callbackUrl = trim($user['webhook_url']);
        }
        $clientRef = trim($requestData['reference'] ?? $requestData['client_reference'] ?? $_POST['reference'] ?? $_POST['client_reference'] ?? '');
        
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, status, channel, callback_url, client_reference) VALUES (?, ?, ?, ?, ?, 'api', ?, ?)");
        $stmt->execute([$userId, $network, $validatedPhone, $gbAmount, $orderStatus, $callbackUrl ?: null, $clientRef ?: null]);
        $orderId = (int)$pdo->lastInsertId();
        
        $desc = "Purchased physical item via API: {$network} {$gbAmount}GB bundle to {$validatedPhone}";
        if ($freeMode) {
            $pdo->prepare("UPDATE users SET debt = debt + ? WHERE id = ?")->execute([$cost, $userId]);
            $ref = 'STR-' . mt_rand(10000, 99999);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, reference, type, description, balance_before, balance_after, status) VALUES (?, ?, ?, 'debit', ?, ?, ?, 'success')")
                ->execute([$userId, $cost, $ref, $desc . " [FREE MODE DEBT]", $walletBalance, $walletBalance]);
            $newBal = $walletBalance;
        } else {
            $newBal = $walletBalance - $cost;
            $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$newBal, $userId]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id, type, amount, balance_after, description) VALUES (?, 'debit', ?, ?, ?)")->execute([$userId, $cost, $newBal, $desc]);
        }
        
        $pdo->commit();
        $clientCode = str_pad(($orderId * 397 + 137) % 900 + 100, 3, '0', STR_PAD_LEFT);
        
        if (!$isSandbox) {
            require_once __DIR__ . '/classes/SupplierApi.php';
            require_once __DIR__ . '/classes/Supplier2Api.php';
            require_once __DIR__ . '/classes/Supplier3Api.php';
            require_once __DIR__ . '/classes/Supplier5Api.php';
                require_once __DIR__ . '/classes/BackupApi.php';
            require_once __DIR__ . '/classes/MtnUp2uApi.php';
            require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
            require_once __DIR__ . '/classes/NitghtApi.php';
            require_once __DIR__ . '/classes/Supplier1Whitelist.php';
            
            $mtnUp2GbApi = MtnUp2uPortalApi::isNetworkEnabled($network, $gbAmount) ? new MtnUp2uPortalApi() : null;
            $mtnUp2uApi  = MtnUp2uApi::isNetworkEnabled($network, $gbAmount) ? new MtnUp2uApi() : null;
            $nitghtApi   = NitghtApi::isNetworkEnabled($network, $gbAmount) ? new NitghtApi() : null;
            $s1Api = SupplierApi::isNetworkEnabled($network, $gbAmount) ? new SupplierApi() : null;
            $s2Api = (strtolower($network) === 'mtn' && Supplier2Api::isNetworkEnabled('mtn', $gbAmount)) ? new Supplier2Api() : null;
            $s3Api = (strtolower($network) === 'mtn' && Supplier3Api::isNetworkEnabled('mtn', $gbAmount)) ? new Supplier3Api() : null;
            $s4Api = Supplier5Api::isNetworkEnabled($network, $gbAmount) ? new Supplier5Api() : null;
            $backupApi = BackupApi::isNetworkEnabled($network, $gbAmount) ? new BackupApi() : null;
            
            require_once __DIR__ . '/classes/JaybartWhitelist.php';
            $supplierApi = null;
            $supplierName = '';
            $notWhitelistedForS1 = false;
            
            $isAtOrTelecel = (stripos($network, 'ISHARE') !== false || stripos($network, 'AT') !== false || stripos($network, 'TELECEL') !== false);
            if ($isAtOrTelecel && $s1Api) {
                $supplierApi = $s1Api;
                $supplierName = 'Supplier 1';
            } elseif ($s4Api) {
                $supplierApi = $s4Api;
                $supplierName = 'Supplier 5';
            } elseif ($nitghtApi) {
                $supplierApi = $nitghtApi;
                $supplierName = 'NITGHT';
            } elseif ($mtnUp2GbApi) {
                $supplierApi = $mtnUp2GbApi;
                $supplierName = 'MTNUP2U PORTAL';
            } elseif ($mtnUp2uApi) {
                $supplierApi = $mtnUp2uApi;
                $supplierName = 'MTNUP2U';
            } elseif ($s1Api) {
                $supplierApi = $s1Api;
                $supplierName = 'Supplier 1';
            } elseif ($s2Api) {
                $supplierApi = $s2Api;
                $supplierName = 'Supplier 2';
            } elseif ($s3Api) {
                $supplierApi = $s3Api;
                $supplierName = 'Supplier 3';
            } elseif ($s4Api) {
                                $supplierApi = $s4Api;
                                $supplierName = 'Supplier 5';
                            } elseif ($backupApi) {
                                $supplierApi = $backupApi;
                                $supplierName = 'Backup';
                            } elseif ($s1Api) {
                $notWhitelistedForS1 = true;
            }
            
            if ($supplierApi) {
                if ($supplierName === 'MTNUP2U' && (strtolower($network) === 'mtn' || strpos(strtolower($network), 'mtn') !== false)) {
                    $recipientPhone = preg_replace('/[^0-9]/', '', $validatedPhone);
                    if (strlen($recipientPhone) === 12 && substr($recipientPhone, 0, 3) === '233') {
                        $recipientPhone = '0' . substr($recipientPhone, 3);
                    }

                    $stmtLocalCheck = $pdo->prepare("SELECT 1 FROM mtn_verified_numbers WHERE phone_number = ? LIMIT 1");
                    $stmtLocalCheck->execute([$recipientPhone]);
                    $inLocalDb = (bool)$stmtLocalCheck->fetchColumn();

                    $inMtnUp2u = $mtnUp2uApi->isBeneficiary($validatedPhone, $network);
                    $force = filter_var($requestData['force'] ?? $_POST['force'] ?? $_GET['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    if ($inMtnUp2u) {
                        if (!$inLocalDb) {
                            $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$recipientPhone]);
                        }
                    } elseif (!$force) {
                        if ($inLocalDb) {
                            // In local saved list BUT not yet on MTNUP2U beneficiary list -> set status to 'pending'
                            $dbStatus = 'pending';
                            $message = "Pending MTNUP2U beneficiary list update.";
                            $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?")->execute([$dbStatus, $message, $orderId]);
                            jsonResponse(true, $message, ['order_id' => $orderId, 'status' => 'pending', 'client_code' => $clientCode]);
                        } else {
                            // NOT in local saved list AND NOT on MTNUP2U beneficiary list -> set status to 'accepted'
                            $dbStatus = 'accepted';
                            $message = "Submitted for MTN Verification on Accepted status.";
                            $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?")->execute([$dbStatus, $message, $orderId]);
                            jsonResponse(true, $message, ['order_id' => $orderId, 'status' => 'accepted', 'client_code' => $clientCode]);
                        }
                    }
                }
                $apiResult = $supplierApi->sendBundle($network, $validatedPhone, $gbAmount, $orderId, 'store');
                if ($apiResult['success']) {
                    $supplierData = $apiResult['data'] ?? [];
                    $suppId = $apiResult['reference'] ?? $apiResult['order_id'] ?? $apiResult['transaction_id'] ?? $apiResult['id'] ?? $supplierData['reference'] ?? $supplierData['reference_id'] ?? $supplierData['order_id'] ?? $supplierData['transaction_id'] ?? $supplierData['trx_ref'] ?? $supplierData['id'] ?? '';
                    $message = "Sent to " . $supplierName . ". Order ID: " . $suppId;
                    
                    $dbStatus = Supplier5Api::mapStatus($network, $apiResult);
                    if ($supplierName === 'MTNUP2U') $dbStatus = MtnUp2uApi::mapStatus($network, $apiResult);
                    elseif ($supplierName === 'NITGHT') $dbStatus = NitghtApi::mapStatus($network, $apiResult);
                    elseif ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') $dbStatus = MtnUp2GbApi::mapStatus($network, $apiResult);
                    elseif ($supplierName === 'Supplier 1') $dbStatus = SupplierApi::mapStatus($network, $apiResult);
                    elseif ($supplierName === 'Supplier 2') $dbStatus = 'unverified';
                    elseif ($supplierName === 'Supplier 3') $dbStatus = 'waiting';
                    
                    $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                    $stmtUpdate->execute([$dbStatus, $message, $orderId]);
                    if ($stmtUpdate->rowCount() > 0 && $dbStatus === 'completed') {
                        require_once __DIR__ . '/classes/MailHelper.php';
                        MailHelper::sendOrderCompletionEmail($pdo, $orderId);
                    }
                    $orderStatus = $dbStatus;
                } else {
                    require_once __DIR__ . '/classes/SupplierApi.php';
                    $orderStatus = 'pending';
                    $rawErr = SupplierApi::sanitizeErrorMessage($apiResult['message'] ?? 'Unknown error');
                    $message = "Supplier Error: " . $rawErr;
                    $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')")->execute([$orderStatus, $message, $orderId]);
                }
            } else {
                if ($notWhitelistedForS1) {
                    $pdo->prepare("UPDATE bundle_sends SET message = 'Number not whitelisted for Supplier 1. Awaiting manual review.' WHERE id = ?")->execute([$orderId]);
                }
            }
        }
        
        jsonResponse(true, "Store data order initiated successfully.", [
            'order_id' => $orderId,
            'client_code' => $clientCode,
            'network' => $network,
            'recipient' => $validatedPhone,
            'gb_amount' => $gbAmount,
            'status' => $orderStatus,
            'price_deducted' => $cost,
            'balance_after' => $newBal
        ]);
        
    } else {
        jsonResponse(false, 'Invalid product_id format.');
    }

} elseif ($action === 'whitelist_number') {
    $phoneNumber = trim($requestData['phone_number'] ?? '');
    if (empty($phoneNumber)) {
        jsonResponse(false, 'Missing phone_number.');
    }
    
    $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
    if (strlen($cleanPhone) === 12 && substr($cleanPhone, 0, 3) === '233') {
        $cleanPhone = '0' . substr($cleanPhone, 3);
    }
    if (strlen($cleanPhone) !== 10) {
        jsonResponse(false, 'Invalid phone number format.');
    }

    try {
        $stmtLocalCheck = $pdo->prepare("SELECT 1 FROM mtn_verified_numbers WHERE phone_number = ? LIMIT 1");
        $stmtLocalCheck->execute([$cleanPhone]);
        $inLocalDb = (bool)$stmtLocalCheck->fetchColumn();

        if ($inLocalDb) {
            jsonResponse(true, 'Number is already in the whitelist.', ['phone_number' => $cleanPhone]);
        } else {
            $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$cleanPhone]);
            jsonResponse(true, 'Number successfully added to the whitelist.', ['phone_number' => $cleanPhone]);
        }
    } catch (Exception $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }

} elseif ($action === 'verify_number') {
    $phoneNumber = trim($requestData['phone_number'] ?? '');
    $network = trim($requestData['network'] ?? 'MTN');

    if (empty($phoneNumber)) {
        jsonResponse(false, 'Missing phone_number.');
    }
    
    // Quick local prefix validation
    $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
    if (strlen($cleanPhone) === 12 && substr($cleanPhone, 0, 3) === '233') {
        $cleanPhone = '0' . substr($cleanPhone, 3);
    }
    if (strlen($cleanPhone) !== 10 || substr($cleanPhone, 0, 1) !== '0') {
        jsonResponse(false, 'Invalid phone number format. Must be 10 digits starting with 0.');
    }

    // Check if already verified in local database
    try {
        $stmtCheck = $pdo->prepare("SELECT 1 FROM mtn_verified_numbers WHERE phone_number = ? LIMIT 1");
        $stmtCheck->execute([$cleanPhone]);
        if ($stmtCheck->fetchColumn()) {
            jsonResponse(true, 'Number is already verified in system.', [
                'phone_number' => $cleanPhone,
                'network' => $network,
                'is_valid' => true,
                'already_verified' => true
            ]);
        }
    } catch (Exception $exDb) {}

    try {
        require_once __DIR__ . '/classes/NitghtApi.php';
        require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
        require_once __DIR__ . '/classes/MtnUp2uApi.php';
        
        $networkKey = 'YELLO';
        $networkLower = strtolower(trim($network));
        $isMtn = ($networkLower === 'mtn' || $networkLower === 'yello' || strpos($networkLower, 'mtn') !== false);
        if ($networkLower === 'mtn_xpress' || strpos($networkLower, 'xpress') !== false) {
            $networkKey = 'mtn_xpress';
        } elseif ($networkLower === 'telecel' || strpos($networkLower, 'telecel') !== false) {
            $networkKey = 'TELECEL';
        } elseif (strpos($networkLower, 'ishare') !== false || strpos($networkLower, 'airteltigo') !== false || $networkLower === 'at') {
            $networkKey = 'AT_PREMIUM';
        }

        $res = null;
        $nightPrecheckRes = null;

        // 1. Check Night API MTN pre-check first if MTN and enabled
        if ($isMtn && NitghtApi::isEnabled() && NitghtApi::isNetworkEnabled('MTN')) {
            try {
                $nitghtApi = new NitghtApi();
                $nRes = $nitghtApi->verifyNumber($cleanPhone, 'MTN');
                if (!empty($nRes['success'])) {
                    $nightPrecheckRes = $nRes;
                    if (!empty($nRes['data']['is_valid'])) {
                        $res = $nRes;
                    }
                }
            } catch (Exception $exNight) {}
        }

        // 2. If not verified by Night API, check MTNUP2U PORTAL
        if (empty($res) || empty($res['success'])) {
            if (MtnUp2uPortalApi::isEnabled()) {
                $api = new MtnUp2uPortalApi();
                $pRes = $api->verifyNumber($cleanPhone, $networkKey);
                if (!empty($pRes['success'])) {
                    $res = $pRes;
                }
            }
        }

        // 3. If MTNUP2U PORTAL verification failed or was not enabled, try MTNUP2U as fallback
        if (empty($res) || empty($res['success'])) {
            if (MtnUp2uApi::isEnabled()) {
                $uApi = new MtnUp2uApi();
                $uRes = $uApi->verifyNumber($cleanPhone, $networkKey);
                if (!empty($uRes['success'])) {
                    $res = $uRes;
                }
            }
        }

        // 4. Fallback to Night precheck result if available, or default MtnUp2uPortalApi
        if (empty($res)) {
            if ($nightPrecheckRes) {
                $res = $nightPrecheckRes;
            } else {
                $api = new MtnUp2uPortalApi();
                $res = $api->verifyNumber($cleanPhone, $networkKey);
            }
        }

        if (!empty($res['success'])) {
            // Check success metrics from verify endpoint
            $isValid = false;
            if (isset($res['data']['exists'])) {
                $isValid = (bool)$res['data']['exists'];
            } elseif (isset($res['data']['verified'])) {
                $isValid = (bool)$res['data']['verified'];
            } elseif (isset($res['data']['is_valid'])) {
                $isValid = (bool)$res['data']['is_valid'];
            } elseif (isset($res['data']['status'])) {
                $st = strtolower(trim((string)$res['data']['status']));
                $isValid = in_array($st, ['true', '1', 'verified', 'success', 'successful', 'active', 'ok', 'valid']);
            } else {
                $isValid = true; // Fallback if API returned success=true but no exact boolean flags
            }

            if ($isValid) {
                // Register it locally
                $pdo->beginTransaction();
                $pdo->prepare("INSERT IGNORE INTO mtn_registered_numbers (phone) VALUES (?)")->execute([$cleanPhone]);
                $pdo->prepare("INSERT IGNORE INTO mtn_verified_numbers (phone_number) VALUES (?)")->execute([$cleanPhone]);
                $stmt = $pdo->prepare("INSERT INTO mtn_number_requests (user_id, raw_text, file_path, status) VALUES (?, ?, ?, 'approved')");
                $stmt->execute([$userId, $cleanPhone, null]);
                $pdo->commit();

                jsonResponse(true, 'Number successfully verified.', [
                    'phone_number' => $cleanPhone,
                    'network' => $network,
                    'is_valid' => true,
                    'provider_data' => $res['data'] ?? []
                ]);
            } else {
                $failMsg = !empty($res['message']) ? $res['message'] : 'Number verification failed. Provider indicates number is not valid or not a beneficiary.';
                jsonResponse(false, $failMsg, [
                    'phone_number' => $cleanPhone,
                    'is_valid' => false,
                    'provider_data' => $res['data'] ?? []
                ]);
            }
        } else {
            $errMsg = $res['message'] ?? ($res['error'] ?? 'Unknown error');
            jsonResponse(false, 'API Provider verification failed: ' . $errMsg, [
                'provider_response' => $res,
                'phone_number' => $cleanPhone,
                'is_valid' => false
            ]);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, 'Error during verification: ' . $e->getMessage(), [], 500);
    }

} else {
    jsonResponse(false, 'Invalid API action.');
}


