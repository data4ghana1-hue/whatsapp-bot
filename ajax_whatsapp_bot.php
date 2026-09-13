<?php
/**
 * Alexa Covert — WhatsApp Bot Activation & Management AJAX Handler
 * 
 * Handles GHS 2.00 wallet deduction, pairing code generation requests,
 * live connection status polling, and personal bot feature settings.
 */

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

// Clean output buffer to ensure pure JSON response
if (ob_get_level()) {
    ob_clean();
}
header('Content-Type: application/json; charset=utf-8');

// Ensure user is logged in
if (empty($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    echo json_encode([
        'success' => false,
        'code'    => 'AUTH_REQUIRED',
        'message' => 'Please log in to your account to activate WhatsApp Bot.'
    ]);
    exit;
}

$pdo = db_connect();
$userId = (int)$_SESSION['user']['id'];

// Ensure user_whatsapp_bots table exists
try {
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
} catch (Throwable $e) {}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
$botDir = __DIR__ . '/whatsapp_qr_bot';
$pairingReqFile   = $botDir . '/pairing_request.json';
$pairingStateFile = $botDir . '/pairing_state.json';

// Helper: normalize Ghanaian / international phone to 233...
function normalizeBotPhone(string $raw): string {
    $digits = preg_replace('/\D/', '', $raw);
    if (empty($digits)) return '';
    if (strpos($digits, '0') === 0) {
        $digits = '233' . substr($digits, 1);
    }
    if (strlen($digits) === 9) {
        $digits = '233' . $digits;
    }
    return $digits;
}

// Helper: locate Node.js binary across cPanel CloudLinux, VPS, and standard environments
function findNodeBinary(): string {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return 'node';
    }

    $candidates = [
        '/opt/alt/alt-nodejs22/root/usr/bin/node',
        '/opt/alt/alt-nodejs20/root/usr/bin/node',
        '/opt/alt/alt-nodejs18/root/usr/bin/node',
        '/opt/alt/alt-nodejs16/root/usr/bin/node',
        '/usr/local/bin/node',
        '/usr/bin/node',
        'node'
    ];

    $which = @trim(shell_exec('which node 2>/dev/null') ?: '');
    if ($which && @is_executable($which)) {
        return $which;
    }

    foreach ($candidates as $bin) {
        if (@is_executable($bin)) {
            return $bin;
        }
    }

    return '/opt/alt/alt-nodejs18/root/usr/bin/node';
}

// Helper: check if bot process is currently running on the server
function isBotProcessRunning(): bool {
    // 1. Fast HTTP API status check
    $ch = curl_init('http://127.0.0.1:3000/api/status');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 1,
        CURLOPT_CONNECTTIMEOUT => 1
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res && json_decode($res, true)) {
        return true;
    }

    // 2. Process list check on Linux
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        $pgrep = @shell_exec("pgrep -f '[b]ot.js' 2>/dev/null");
        if (!empty(trim($pgrep ?: ''))) {
            return true;
        }
        $ps = @shell_exec("ps aux 2>/dev/null | grep '[b]ot.js'");
        if (!empty(trim($ps ?: ''))) {
            return true;
        }
    } else {
        $tasks = @shell_exec("tasklist /FI \"IMAGENAME eq node.exe\" 2>NUL");
        if ($tasks && stripos($tasks, 'node.exe') !== false) {
            return true;
        }
    }

    return false;
}

// Helper: start Node bot in background with proper binary and environment
function startBotProcess(string $botDir): void {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Write a temp batch file to launch bot detached so Windows won't block on log redirection
        $batFile = $botDir . DIRECTORY_SEPARATOR . 'start_bot_temp.bat';
        $logOut = $botDir . DIRECTORY_SEPARATOR . 'bot_out.log';
        $logErr = $botDir . DIRECTORY_SEPARATOR . 'bot_err.log';
        $batContent = "@echo off\r\ncd /d \"" . $botDir . "\"\r\nstart \"\" /b node bot.js >\"" . $logOut . "\" 2>\"" . $logErr . "\"\r\n";
        @file_put_contents($batFile, $batContent);
        @pclose(@popen('cmd /c "' . $batFile . '" >nul 2>&1', 'r'));
    } else {
        $nodeBin = findNodeBinary();
        $logFile = $botDir . '/bot.log';
        $cmd = sprintf(
            "cd %s && nohup %s bot.js > %s 2>&1 < /dev/null &",
            escapeshellarg($botDir),
            escapeshellarg($nodeBin),
            escapeshellarg($logFile)
        );
        @exec($cmd);
    }
}


// ─────────────────────────────────────────────────────────────
// 1. ACTION: Check Current Status
// ─────────────────────────────────────────────────────────────
if ($action === 'get_status') {
    // 1. Fetch user record from database
    $stmt = $pdo->prepare("SELECT * FROM user_whatsapp_bots WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $botRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Fetch live bot status from Node.js or pairing_state.json
    $liveStatus = 'offline';
    $connectedPhone = null;
    $livePairingCode = null;

    // Check Node HTTP API first
    $ch = curl_init('http://127.0.0.1:3000/api/status');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_CONNECTTIMEOUT => 1
    ]);
    $nodeRes = curl_exec($ch);
    curl_close($ch);

    if ($nodeRes) {
        $nodeData = json_decode($nodeRes, true);
        if ($nodeData) {
            $liveStatus = $nodeData['status'] ?? 'offline';
            $connectedPhone = $nodeData['phone'] ?? null;
            $livePairingCode = $nodeData['pairing_code'] ?? null;
        }
    }

    // Check pairing_state.json file
    if (file_exists($pairingStateFile)) {
        $stateRaw = @file_get_contents($pairingStateFile);
        $stateData = json_decode($stateRaw, true);
        if ($stateData) {
            if (!empty($stateData['pairing_code'])) {
                if (empty($stateData['expires_at']) || ($stateData['expires_at'] / 1000) > time()) {
                    $livePairingCode = $stateData['pairing_code'];
                    if ($liveStatus === 'offline' || $liveStatus === 'scan_qr') {
                        $liveStatus = 'pairing';
                    }
                }
            }
            if (($stateData['status'] ?? '') === 'connected') {
                $liveStatus = 'connected';
                if (!empty($stateData['phone'])) {
                    $connectedPhone = $stateData['phone'];
                }
            }
        }
    }

    // If live status is connected, ensure DB record reflects it
    if ($liveStatus === 'connected' && $botRecord && $botRecord['status'] !== 'connected') {
        $upd = $pdo->prepare("UPDATE user_whatsapp_bots SET status = 'connected', connected_at = IFNULL(connected_at, NOW()) WHERE id = ?");
        $upd->execute([$botRecord['id']]);
        $botRecord['status'] = 'connected';
    }

    // Refresh wallet balance
    $balStmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $balStmt->execute([$userId]);
    $walletBal = (float)($balStmt->fetchColumn() ?: 0.00);

    // Fetch all accounts for multi-account history
    $allStmt = $pdo->prepare("SELECT * FROM user_whatsapp_bots WHERE user_id = ? ORDER BY id DESC");
    $allStmt->execute([$userId]);
    $allRecords = $allStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'         => true,
        'wallet_balance'  => $walletBal,
        'live_status'     => $liveStatus,
        'connected_phone' => $connectedPhone,
        'pairing_code'    => $livePairingCode,
        'record'          => $botRecord ?: null,
        'records'         => $allRecords ?: []
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. ACTION: Request Pairing Code (Debits GHS 2.00)
// ─────────────────────────────────────────────────────────────
if ($action === 'request_pairing') {
    @set_time_limit(120);
    $rawPhone = trim($_POST['phone'] ?? '');
    $cleanPhone = normalizeBotPhone($rawPhone);
    $isRefresh = !empty($_POST['is_refresh']);

    if (empty($cleanPhone) || strlen($cleanPhone) < 10) {
        echo json_encode([
            'success' => false,
            'message' => 'Please enter a valid WhatsApp phone number (e.g. 0553381853 or 233553381853).'
        ]);
        exit;
    }

    // Check if user has an existing active pending record in last 15 minutes to avoid double-charge on refresh/retry
    $existingStmt = $pdo->prepare("SELECT * FROM user_whatsapp_bots WHERE user_id = ? AND phone_number = ? AND status = 'pending' AND created_at >= (NOW() - INTERVAL 15 MINUTE) ORDER BY id DESC LIMIT 1");
    $existingStmt->execute([$userId, $cleanPhone]);
    $existingPending = $existingStmt->fetch(PDO::FETCH_ASSOC);

    $activationFee = 2.00;
    $txRef = 'WABOT-' . time() . '-' . mt_rand(1000, 9999);
    $botRecordId = null;

    if ($existingPending && $isRefresh) {
        $botRecordId = (int)$existingPending['id'];
    } else {
        // 1. Check current wallet balance
        $balStmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $balStmt->execute([$userId]);
        $currentBal = (float)($balStmt->fetchColumn() ?: 0.00);

        if ($currentBal < $activationFee) {
            echo json_encode([
                'success'        => false,
                'code'           => 'INSUFFICIENT_BALANCE',
                'required'       => $activationFee,
                'wallet_balance' => $currentBal,
                'message'        => 'Insufficient wallet balance. You need at least GHS ' . number_format($activationFee, 2) . ' to activate WhatsApp Bot. Please top up your wallet.'
            ]);
            exit;
        }

        // 2. Debit GHS 2.00 via standard wallet transaction
        $desc = "WhatsApp Bot Activation Fee (+{$cleanPhone})";

        $debited = false;
        if (function_exists('addWalletTransaction')) {
            $debited = addWalletTransaction($pdo, $userId, $activationFee, 'debit', $txRef, $desc);
        } else {
            $upd = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ? AND wallet_balance >= ?");
            $debited = $upd->execute([$activationFee, $userId, $activationFee]);
        }

        if (!$debited) {
            echo json_encode([
                'success' => false,
                'message' => 'Unable to process wallet deduction. Please verify your balance and try again.'
            ]);
            exit;
        }

        // 3. Record in user_whatsapp_bots (personal bot defaults to autoreply = 0 so contacts aren't disturbed)
        $stmt = $pdo->prepare("
            INSERT INTO user_whatsapp_bots 
            (user_id, bot_name, phone_number, status, fee_paid, transaction_ref, autoreply, created_at)
            VALUES (?, 'Alexa Covert', ?, 'pending', ?, ?, 0, NOW())
        ");
        $stmt->execute([$userId, $cleanPhone, $activationFee, $txRef]);
        $botRecordId = (int)$pdo->lastInsertId();
    }

    // 4. Request pairing code from Node.js Bot Service
    $pairingCode = null;
    $pairingError = null;
    $postPayload = json_encode(['phone' => $cleanPhone]);

    // A. Prepare the file bridge immediately so any running or launching bot picks it up
    @unlink($pairingStateFile);
    @file_put_contents($pairingReqFile, json_encode([
        'phone'             => $cleanPhone,
        'linked_via'        => 'phone_number',
        'personal_bot_mode' => true,
        'user_id'           => $userId,
        'record_id'         => $botRecordId,
        'timestamp'         => time()
    ], JSON_PRETTY_PRINT));

    // B. Ensure Node bot process is actively running on the server
    if (!isBotProcessRunning()) {
        startBotProcess($botDir);
        // Allow up to 8 seconds for process to launch and bind port 3000
        for ($s = 0; $s < 16; $s++) {
            usleep(500000); // 500ms
            if (isBotProcessRunning()) {
                // Give it an extra 1.5s to be fully ready after port bind
                usleep(1500000);
                break;
            }
        }
    }

    // C. Direct HTTP request to Node.js bot server (Priority: local port 3000)
    // Timeout is 45s — bot needs up to ~30s to start fresh and get a pairing code from WhatsApp
    $ch = curl_init('http://127.0.0.1:3000/api/request-pairing-code');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
    ]);
    $nodeApiRes = curl_exec($ch);
    curl_close($ch);

    if ($nodeApiRes) {
        $apiJson = json_decode($nodeApiRes, true);
        if (!empty($apiJson['pairing_code'])) {
            $pairingCode = $apiJson['pairing_code'];
        } elseif (!empty($apiJson['message']) && ($apiJson['success'] ?? true) === false) {
            $pairingError = $apiJson['message'];
        }
    }

    // D. File Bridge Fallback (reads pairing_state.json and polls up to 50 seconds)
    if (!$pairingCode && empty($pairingError)) {
        for ($w = 0; $w < 100; $w++) { // 100 * 500ms = 50 seconds
            usleep(500000);
            if (file_exists($pairingStateFile)) {
                $stData = json_decode(@file_get_contents($pairingStateFile), true);
                if ($stData) {
                    if (!empty($stData['pairing_code']) && ($stData['phone'] ?? '') === $cleanPhone) {
                        $pairingCode = $stData['pairing_code'];
                        break;
                    }
                    if (isset($stData['success']) && $stData['success'] === false && ($stData['phone'] ?? '') === $cleanPhone) {
                        $pairingError = $stData['error'] ?? 'WhatsApp pairing was rejected.';
                        break;
                    }
                }
            }
        }
    }

    // E. If still no valid code generated from WhatsApp servers, REFUND user and inform them
    if (!$pairingCode) {
        if (!$existingPending || !$isRefresh) {
            $refundTx = 'REF-' . time() . '-' . mt_rand(1000, 9999);
            if (function_exists('addWalletTransaction')) {
                addWalletTransaction($pdo, $userId, $activationFee, 'credit', $refundTx, 'Refund: WhatsApp Linking Code Timeout');
            } else {
                $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")->execute([$activationFee, $userId]);
            }
        }
        $pdo->prepare("UPDATE user_whatsapp_bots SET status = 'expired' WHERE id = ?")->execute([$botRecordId]);

        $balStmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $balStmt->execute([$userId]);
        $refundedBal = (float)($balStmt->fetchColumn() ?: 0.00);
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['wallet_balance'] = $refundedBal;
        }

        // Inspect bot.log for any fatal process crash if error is still empty
        $logFile = $botDir . '/bot.log';
        if (empty($pairingError) && file_exists($logFile)) {
            $logLines = @file($logFile) ?: [];
            $lastLog = trim(implode(' ', array_slice($logLines, -4)));
            if (!empty($lastLog) && (stripos($lastLog, 'Error') !== false || stripos($lastLog, 'Cannot find') !== false || stripos($lastLog, 'SyntaxError') !== false)) {
                $pairingError = "Process log: " . substr(strip_tags($lastLog), 0, 160);
            }
        }

        $userMsg = 'WhatsApp servers took too long to return the linking code. Your GHS 2.00 has been refunded to your wallet. Please tap Generate Linking Code again.';
        if (!empty($pairingError)) {
            $userMsg = "WhatsApp engine notice: {$pairingError}. Your GHS 2.00 has been refunded to your wallet. Please try again.";
        }

        echo json_encode([
            'success'        => false,
            'code'           => 'PAIRING_TIMEOUT',
            'wallet_balance' => $refundedBal,
            'message'        => $userMsg
        ]);
        exit;
    }

    // Format pairing code nicely as ABCD-1234 if needed
    $cleanCode = str_replace('-', '', trim($pairingCode));
    if (strlen($cleanCode) === 8) {
        $pairingCode = substr($cleanCode, 0, 4) . '-' . substr($cleanCode, 4);
    }

    // Update database record with authentic WhatsApp pairing code
    $updStmt = $pdo->prepare("UPDATE user_whatsapp_bots SET pairing_code = ? WHERE id = ?");
    $updStmt->execute([$pairingCode, $botRecordId]);

    // Get updated wallet balance and sync session
    $balStmt->execute([$userId]);
    $newBal = (float)($balStmt->fetchColumn() ?: 0.00);
    if (isset($_SESSION['user'])) {
        $_SESSION['user']['wallet_balance'] = $newBal;
    }

    $formattedPhone = '+233 ' . substr($cleanPhone, 3, 2) . ' ' . substr($cleanPhone, 5, 3) . ' ' . substr($cleanPhone, 8);

    echo json_encode([
        'success'         => true,
        'pairing_code'    => $pairingCode,
        'target_phone'    => '+' . $cleanPhone,
        'formatted_phone' => $formattedPhone,
        'new_balance'     => $newBal,
        'tx_ref'          => $txRef,
        'message'         => 'Pairing code generated successfully! Enter this code on your WhatsApp.'
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. ACTION: Update Personal Bot Feature Toggles
// ─────────────────────────────────────────────────────────────
if ($action === 'update_settings') {
    $botId      = isset($_POST['bot_id']) ? (int)$_POST['bot_id'] : 0;
    $autoview   = isset($_POST['autoview']) ? 1 : 0;
    $autolike   = isset($_POST['autolike']) ? 1 : 0;
    $antidelete = isset($_POST['antidelete']) ? 1 : 0;
    $savedviews = isset($_POST['savedviews']) ? 1 : 0;
    $autoreply  = isset($_POST['autoreply']) ? 1 : 0;
    $downloader = isset($_POST['downloader_enabled']) ? 1 : 0;
    $music      = isset($_POST['music_enabled']) ? 1 : 0;

    if ($botId > 0) {
        $stmt = $pdo->prepare("
            UPDATE user_whatsapp_bots 
            SET autoview_status = ?, autolike_status = ?, antidelete = ?, savedviews = ?, 
                autoreply = ?, downloader_enabled = ?, music_enabled = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$autoview, $autolike, $antidelete, $savedviews, $autoreply, $downloader, $music, $botId, $userId]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE user_whatsapp_bots 
            SET autoview_status = ?, autolike_status = ?, antidelete = ?, savedviews = ?, 
                autoreply = ?, downloader_enabled = ?, music_enabled = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$autoview, $autolike, $antidelete, $savedviews, $autoreply, $downloader, $music, $userId]);
    }

    // Sync to bot_commands.json and whatsapp_qr_bot/commands.json userbot_settings
    $syncFiles = [
        __DIR__ . '/bot_commands.json',
        $botDir . '/commands.json',
        $botDir . '/bot_commands.json'
    ];
    foreach ($syncFiles as $cfgFile) {
        if (file_exists($cfgFile)) {
            $cfg = json_decode(@file_get_contents($cfgFile), true) ?: [];
            $cfg['userbot_settings'] = $cfg['userbot_settings'] ?? [];
            $cfg['userbot_settings']['autoview'] = (bool)$autoview;
            $cfg['userbot_settings']['autolike'] = (bool)$autolike;
            $cfg['userbot_settings']['savedviews'] = (bool)$savedviews;
            $cfg['userbot_settings']['recoverydeleted'] = (bool)$antidelete;
            $cfg['userbot_settings']['autoreply'] = (bool)$autoreply;
            $cfg['userbot_settings']['personal_mode_only'] = ($autoreply === 0);
            @file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    // Sync to session_info.json
    $sessFile = $botDir . '/session_info.json';
    if (file_exists($sessFile)) {
        $sData = json_decode(@file_get_contents($sessFile), true) ?: [];
        $sData['personal_bot_mode'] = ($autoreply === 0);
        @file_put_contents($sessFile, json_encode($sData, JSON_PRETTY_PRINT));
    }

    echo json_encode([
        'success' => true,
        'message' => 'Alexa Covert settings updated successfully!'
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 4. ACTION: Disconnect / Unlink Device
// ─────────────────────────────────────────────────────────────
if ($action === 'disconnect') {
    $botId = isset($_POST['bot_id']) ? (int)$_POST['bot_id'] : 0;

    // Signal bot to disconnect
    $ch = curl_init('http://127.0.0.1:3000/api/logout');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3
    ]);
    @curl_exec($ch);
    @curl_close($ch);

    // Clean pairing state files
    @unlink($pairingReqFile);
    @unlink($pairingStateFile);
    $sessFile = $botDir . '/session_info.json';
    @unlink($sessFile);

    // Update database
    if ($botId > 0) {
        $stmt = $pdo->prepare("UPDATE user_whatsapp_bots SET status = 'disconnected' WHERE id = ? AND user_id = ?");
        $stmt->execute([$botId, $userId]);
    } else {
        $stmt = $pdo->prepare("UPDATE user_whatsapp_bots SET status = 'disconnected' WHERE user_id = ?");
        $stmt->execute([$userId]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Device unlinked successfully.'
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 5. ACTION: Delete / Remove Account from History
// ─────────────────────────────────────────────────────────────
if ($action === 'delete_record') {
    $botId = (int)($_POST['bot_id'] ?? 0);
    if ($botId > 0) {
        $del = $pdo->prepare("DELETE FROM user_whatsapp_bots WHERE id = ? AND user_id = ? AND status != 'connected'");
        $del->execute([$botId, $userId]);
        echo json_encode(['success' => true, 'message' => 'Account removed from history.']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unable to remove account.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
exit;
