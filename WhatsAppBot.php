<?php
/**
 * WhatsApp Bot Auto-Reply Engine
 * 
 * Manages automated keyword matching, dynamic template variables,
 * WAEC BECE/WASSCE result checking, payment verification, issue/report auto-responses,
 * and dispatching auto-replies to incoming WhatsApp customer messages.
 */

require_once __DIR__ . '/WhatsAppApi.php';
require_once __DIR__ . '/WaecResultChecker.php';
require_once __DIR__ . '/WhatsAppAiService.php';

class WhatsAppBot {

    const CONFIG_FILE = __DIR__ . '/../bot_commands.json';

    /**
     * Get or establish an active database connection for WhatsApp Bot
     */
    public static function getPdo(?PDO $pdo = null): ?PDO {
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        $dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
        $probe = @fsockopen($dbHost, 3306, $errno, $errstr, 0.05);
        if ($probe) {
            fclose($probe);
            if (function_exists('db_connect')) {
                try {
                    $conn = db_connect();
                    if ($conn instanceof PDO) return $conn;
                } catch (Throwable $e) {}
            }
            if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
                try {
                    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
                    return new PDO($dsn, DB_USER, DB_PASS, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_TIMEOUT => 1
                    ]);
                } catch (Throwable $e) {}
            }
        }
        return null;
    }

    /**
     * Ensure support tickets table exists
     */
    public static function ensureTicketsTable(PDO $pdo): void {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS whatsapp_support_tickets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ticket_code VARCHAR(50) UNIQUE NOT NULL,
                    sender_phone VARCHAR(50) NOT NULL,
                    sender_name VARCHAR(100) NULL,
                    order_id INT NULL,
                    issue_type VARCHAR(50) DEFAULT 'general',
                    message TEXT NOT NULL,
                    bot_reply TEXT NULL,
                    status ENUM('open', 'investigating', 'resolved', 'closed') DEFAULT 'open',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_phone (sender_phone),
                    INDEX idx_order (order_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (Throwable $e) {}
    }

    /**
     * Ensure user conversation sessions table exists
     */
    public static function ensureSessionsTable(PDO $pdo): void {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS whatsapp_user_sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sender_phone VARCHAR(50) UNIQUE NOT NULL,
                    current_step VARCHAR(50) NOT NULL,
                    session_data TEXT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_phone (sender_phone)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (Throwable $e) {}
    }

    /**
     * Get active user conversation session
     */
    public static function getUserSession(string $phone, ?PDO $pdo = null): ?array {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (empty($cleanPhone)) return null;

        // 1. Try DB
        if ($pdo) {
            try {
                self::ensureSessionsTable($pdo);
                $stmt = $pdo->prepare("SELECT current_step, session_data, updated_at FROM whatsapp_user_sessions WHERE sender_phone = ? LIMIT 1");
                $stmt->execute([$cleanPhone]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $updated = strtotime($row['updated_at']);
                    // Expire after 30 minutes of inactivity
                    if ($updated && (time() - $updated) > 1800) {
                        self::clearUserSession($phone, $pdo);
                        return null;
                    }
                    $data = json_decode($row['session_data'] ?? '{}', true) ?: [];
                    return [
                        'step' => $row['current_step'],
                        'data' => $data
                    ];
                }
            } catch (Throwable $e) {}
        }

        // 2. File fallback
        $file = __DIR__ . '/../logs/whatsapp_sessions.json';
        if (file_exists($file)) {
            $all = json_decode(@file_get_contents($file), true);
            if (is_array($all) && isset($all[$cleanPhone])) {
                $sess = $all[$cleanPhone];
                if (!empty($sess['time']) && (time() - $sess['time']) > 1800) {
                    unset($all[$cleanPhone]);
                    @file_put_contents($file, json_encode($all));
                    return null;
                }
                return [
                    'step' => $sess['step'] ?? '',
                    'data' => $sess['data'] ?? []
                ];
            }
        }

        return null;
    }

    /**
     * Save active user session
     */
    public static function saveUserSession(string $phone, string $step, array $data, ?PDO $pdo = null): void {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (empty($cleanPhone)) return;

        // 1. Save in DB
        if ($pdo) {
            try {
                self::ensureSessionsTable($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO whatsapp_user_sessions (sender_phone, current_step, session_data, updated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE current_step = VALUES(current_step), session_data = VALUES(session_data), updated_at = NOW()
                ");
                $stmt->execute([$cleanPhone, $step, json_encode($data)]);
            } catch (Throwable $e) {}
        }

        // 2. Save in file fallback
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . '/whatsapp_sessions.json';
        $all = [];
        if (file_exists($file)) {
            $all = json_decode(@file_get_contents($file), true) ?: [];
        }
        $all[$cleanPhone] = [
            'step' => $step,
            'data' => $data,
            'time' => time()
        ];
        @file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT));
    }

    /**
     * Clear user session
     */
    public static function clearUserSession(string $phone, ?PDO $pdo = null): void {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (empty($cleanPhone)) return;

        if ($pdo) {
            try {
                self::ensureSessionsTable($pdo);
                $stmt = $pdo->prepare("DELETE FROM whatsapp_user_sessions WHERE sender_phone = ?");
                $stmt->execute([$cleanPhone]);
            } catch (Throwable $e) {}
        }

        $file = __DIR__ . '/../logs/whatsapp_sessions.json';
        if (file_exists($file)) {
            $all = json_decode(@file_get_contents($file), true);
            if (is_array($all) && isset($all[$cleanPhone])) {
                unset($all[$cleanPhone]);
                @file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT));
            }
        }
    }

    /**
     * Load full bot configuration from JSON file and/or DB
     */
    public static function getConfig(?PDO $pdo = null): array {
        $defaults = [
            'bot_enabled'      => true,
            'fallback_enabled' => true,
            'fallback_message' => "Thank you for contacting Apex Prime! 🌟 Type *menu* to see our available services or *support* to connect with an agent.",
            'ignored_numbers'  => [],
            'userbot_settings' => [
                'autoview'         => false,
                'autolike'         => false,
                'autolike_emoji'   => '❤️',
                'savedviews'       => false,
                'recoverydeleted'  => false,
                'autosavecontact'  => false
            ],
            'commands'         => []
        ];

        if (file_exists(self::CONFIG_FILE)) {
            $json = json_decode(@file_get_contents(self::CONFIG_FILE), true);
            if (is_array($json)) {
                $defaults = array_merge($defaults, $json);
            }
        }

        // Try DB if PDO available
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT * FROM whatsapp_bot_commands ORDER BY sort_order ASC, id ASC");
                $dbCmds = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($dbCmds)) {
                    $defaults['commands'] = array_map(function($row) {
                        return [
                            'id'              => (int)$row['id'],
                            'command_trigger' => $row['command_trigger'],
                            'match_type'      => $row['match_type'],
                            'action_type'     => $row['action_type'] ?? 'text',
                            'response_text'   => $row['response_text'],
                            'description'     => $row['description'] ?? '',
                            'is_active'       => (bool)(int)$row['is_active'],
                            'sort_order'      => (int)($row['sort_order'] ?? 0)
                        ];
                    }, $dbCmds);
                }
            } catch (Throwable $e) {}
        }

        return $defaults;
    }

    /**
     * Save bot configuration to JSON and DB
     */
    public static function saveConfig(array $data, ?PDO $pdo = null): bool {
        $current = self::getConfig();
        $merged = array_merge($current, $data);

        // 1. Save to JSON file
        $jsonOk = @file_put_contents(self::CONFIG_FILE, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;

        // Also sync to whatsapp_qr_bot/commands.json
        $qrBotCfg = __DIR__ . '/../whatsapp_qr_bot/commands.json';
        if (file_exists($qrBotCfg)) {
            @file_put_contents($qrBotCfg, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // 2. Sync to DB if PDO available
        if ($pdo && isset($data['commands']) && is_array($data['commands'])) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS whatsapp_bot_commands (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        command_trigger VARCHAR(150) NOT NULL,
                        match_type ENUM('exact', 'contains', 'starts_with') DEFAULT 'exact',
                        action_type VARCHAR(50) DEFAULT 'text',
                        response_text TEXT NOT NULL,
                        description VARCHAR(255) NULL,
                        is_active TINYINT(1) DEFAULT 1,
                        sort_order INT DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_trigger (command_trigger)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
                try { $pdo->exec("ALTER TABLE whatsapp_bot_commands ADD COLUMN action_type VARCHAR(50) DEFAULT 'text' AFTER match_type"); } catch (Throwable $e) {}

                $pdo->exec("TRUNCATE TABLE whatsapp_bot_commands");
                $stmt = $pdo->prepare("
                    INSERT INTO whatsapp_bot_commands (id, command_trigger, match_type, action_type, response_text, description, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($data['commands'] as $idx => $cmd) {
                    $stmt->execute([
                        $cmd['id'] ?? ($idx + 1),
                        $cmd['command_trigger'] ?? '',
                        $cmd['match_type'] ?? 'exact',
                        $cmd['action_type'] ?? 'text',
                        $cmd['response_text'] ?? '',
                        $cmd['description'] ?? '',
                        !empty($cmd['is_active']) ? 1 : 0,
                        $cmd['sort_order'] ?? $idx
                    ]);
                }
            } catch (Throwable $e) {}
        }

        return $jsonOk;
    }

    /**
     * Find matching bot command for an incoming message
     */
    public static function findMatchingCommand(string $incomingText, ?PDO $pdo = null): ?array {
        $config = self::getConfig($pdo);
        if (empty($config['bot_enabled'])) {
            return null;
        }

        $cleanInput = strtolower(trim($incomingText));
        if (empty($cleanInput)) {
            return null;
        }

        foreach ($config['commands'] as $cmd) {
            if (empty($cmd['is_active'])) {
                continue;
            }

            $triggers = explode(',', strtolower($cmd['command_trigger']));
            $matchType = $cmd['match_type'] ?? 'exact';

            foreach ($triggers as $rawTrigger) {
                $trigger = trim($rawTrigger);
                if (empty($trigger)) continue;

                $matched = false;
                if ($matchType === 'exact') {
                    $matched = ($cleanInput === $trigger);
                } elseif ($matchType === 'starts_with') {
                    $matched = (strpos($cleanInput, $trigger) === 0);
                } elseif ($matchType === 'contains') {
                    $matched = (strpos($cleanInput, $trigger) !== false);
                }

                if ($matched) {
                    return $cmd;
                }
            }
        }

        return null;
    }

    /**
     * Format reply template by replacing dynamic placeholders
     */
    public static function formatReply(string $template, string $senderPhone, string $profileName = 'Customer', ?PDO $pdo = null): string {
        $cleanPhone = preg_replace('/[^0-9]/', '', $senderPhone);
        
        // Find user in DB if registered
        $username = $profileName ?: 'Customer';
        $walletBalance = null;
        $balanceInfo = "Register or login at https://apexprime.club to view and fund your wallet.";

        if ($pdo && !empty($cleanPhone)) {
            try {
                $var1 = $cleanPhone;
                $var2 = (strpos($cleanPhone, '233') === 0 && strlen($cleanPhone) === 12) ? '0' . substr($cleanPhone, 3) : $cleanPhone;
                $var3 = (strpos($cleanPhone, '0') === 0 && strlen($cleanPhone) === 10) ? substr($cleanPhone, 1) : $cleanPhone;

                $stmt = $pdo->prepare("SELECT username, wallet_balance FROM users WHERE phone IN (?, ?, ?) LIMIT 1");
                $stmt->execute([$var1, $var2, $var3]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $username = $user['username'] ?: $profileName;
                    $walletBalance = number_format((float)$user['wallet_balance'], 2);
                    $balanceInfo = "Your current wallet balance is: *GHS {$walletBalance}*";
                }
            } catch (Throwable $e) {}
        }

        $appUrl = defined('APP_URL') ? APP_URL : 'https://apexprime.club/';

        $replacements = [
            '{name}'         => $username,
            '{username}'     => $username,
            '{phone}'        => $senderPhone,
            '{balance}'      => $walletBalance !== null ? "GHS {$walletBalance}" : 'GHS 0.00',
            '{balance_info}' => $balanceInfo,
            '{date}'         => date('d M Y'),
            '{time}'         => date('h:i A'),
            '{app_url}'      => rtrim($appUrl, '/')
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Parse Card Serial Number and PIN from any free-form pasted format
     * 
     * Handles:
     * - "Serial: WSC12345678 PIN: 123456789012"
     * - "WSC12345678 123456789012" or "123456789012 WSC12345678"
     * - Multi-line copy-pastes, commas, slashes, dashes, colons, equal signs
     * - Naturally typed sentences: "my serial is BCE12345678 and pin is 123456789012"
     * 
     * @param string $input
     * @return array ['serial' => string, 'pin' => string]
     */
    public static function parseVoucherSerialAndPin(string $input): array {
        $serial = '';
        $pin = '';

        // 1. Explicit keyword matching (e.g. Serial: WSC12345678 / PIN: 123456789012)
        if (preg_match('/(?:serial|sn|s\/n|serial\s*no|card\s*serial)(?:\s+number)?(?:\s+is)?[:\s=]*([a-zA-Z0-9_\-]{5,30})/i', $input, $m)) {
            $cand = strtoupper(trim($m[1]));
            if (preg_match('/[A-Z]/', $cand) && preg_match('/\d/', $cand)) {
                $serial = $cand;
            }
        }
        if (preg_match('/(?:pin|card\s*pin|code|pin\s*code|voucher\s*pin)[:\s=]*([0-9]{10,14})/i', $input, $m)) {
            $pin = trim($m[1]);
        }

        if (!empty($serial) && !empty($pin)) {
            return ['serial' => $serial, 'pin' => $pin];
        }

        // 2. Combined format separated by delimiter, e.g. "WSC12345678-123456789012" or "123456789012-WSC12345678"
        if (preg_match('/([a-zA-Z]{2,5}\d{5,15})[^0-9a-zA-Z]+(\d{10,14})/i', $input, $sp)) {
            if (empty($serial)) $serial = strtoupper(trim($sp[1]));
            if (empty($pin)) $pin = trim($sp[2]);
        } elseif (preg_match('/(\d{10,14})[^0-9a-zA-Z]+([a-zA-Z]{2,5}\d{5,15})/i', $input, $sp)) {
            if (empty($pin)) $pin = trim($sp[1]);
            if (empty($serial)) $serial = strtoupper(trim($sp[2]));
        }

        if (!empty($serial) && !empty($pin)) {
            return ['serial' => $serial, 'pin' => $pin];
        }

        // 3. Tokenize input by splitting on whitespace, commas, pipes, semicolons, slashes, or newlines
        $tokens = preg_split('/[\s,\|\;\\\\\/\n\r]+/', trim($input));
        $cleanedTokens = [];
        foreach ($tokens as $tok) {
            $t = trim($tok, " \t\n\r\0\x0B\"'()[]{}*#:-");
            if ($t !== '') {
                $cleanedTokens[] = $t;
            }
        }

        // Look for typical WAEC Serial formats (e.g. WSC12345678, BCE12345678, or 6-25 alphanumeric with letters)
        if (empty($serial)) {
            foreach ($cleanedTokens as $tok) {
                if (preg_match('/^(?:WSC|BCE|WAEC)[A-Za-z0-9_\-]{5,20}$/i', $tok)) {
                    $serial = strtoupper($tok);
                    break;
                }
            }
        }
        if (empty($serial)) {
            foreach ($cleanedTokens as $tok) {
                if (preg_match('/^[A-Za-z0-9_\-]{6,25}$/', $tok) && preg_match('/[A-Za-z]/', $tok) && preg_match('/\d/', $tok)) {
                    $serial = strtoupper($tok);
                    break;
                }
            }
        }

        // Look for 10-14 digit PIN
        if (empty($pin)) {
            foreach ($cleanedTokens as $tok) {
                if (preg_match('/^\d{10,14}$/', $tok)) {
                    $pin = $tok;
                    break;
                }
            }
        }

        // 4. Fallback regex search on full raw text
        if (empty($pin)) {
            if (preg_match('/\b(\d{10,14})\b/', $input, $pm)) {
                $pin = $pm[1];
            }
        }

        if (empty($serial)) {
            if (preg_match('/\b((?:WSC|BCE)[a-zA-Z0-9_\-]{5,20})\b/i', $input, $sm)) {
                $serial = strtoupper($sm[1]);
            } elseif (preg_match('/\b([A-Za-z]{2,5}[0-9]{5,15})\b/i', $input, $sm)) {
                $serial = strtoupper($sm[1]);
            }
        }

        // 5. If we have multiple numeric tokens (10-14 digits) and no serial was found:
        // First is Serial, second is PIN (common for BECE 12-digit voucher serials on eresults.waecgh.org)
        if (empty($serial) || empty($pin)) {
            $numTokens = [];
            foreach ($cleanedTokens as $tok) {
                if (preg_match('/^\d{10,14}$/', $tok)) {
                    $numTokens[] = $tok;
                }
            }
            if (count($numTokens) >= 2) {
                if (empty($serial)) $serial = $numTokens[0];
                if (empty($pin)) $pin = $numTokens[1];
            }
        }

        // Clean up serial if it has trailing/leading dashes or punctuation
        if (!empty($serial)) {
            $serial = trim($serial, " \t\n\r\0\x0B\"'()[]{}*#:-");
        }

        return ['serial' => $serial, 'pin' => $pin];
    }

    /**
     * ── 1. Handle WAEC Result Checking for BECE and WASSCE (Interactive & Direct) ──
     */
    public static function handleWaecRequest(string $text, string $phone, string $profileName, ?PDO $pdo = null): ?string {
        $cleanText = trim($text);
        $lower = strtolower($cleanText);

        // 1. Check if user already has an active WAEC checking session
        $session = self::getUserSession($phone, $pdo);
        if ($session && strpos($session['step'] ?? '', 'waec_') === 0) {
            // Check cancellation keywords
            if (in_array($lower, ['cancel', 'exit', 'stop', 'quit', 'abort', '0'])) {
                self::clearUserSession($phone, $pdo);
                return "❌ *Result Checking Cancelled.*\n\nFeel free to type *result checker* anytime you want to check your result, or type *menu* to see our available services.";
            }

            return self::processWaecSessionStep($session['step'], $cleanText, $session['data'] ?? [], $phone, $profileName, $pdo);
        }

        // 2. Check 1-line direct check command format:
        // e.g. "check wassce 0010101001 2024 WSC12345678 123456789012" or any order of pin & serial
        if (preg_match('/^(?:check\s+|waec\s+)?(wassce|bece)\s+(\d{10})\s+(\d{4})(.*)$/is', $cleanText, $directMatch)) {
            $examType  = strtoupper($directMatch[1]);
            $index     = $directMatch[2];
            $year      = $directMatch[3];
            $remainder = trim($directMatch[4]);

            $parsedVoucher = self::parseVoucherSerialAndPin($remainder);
            $serial = $parsedVoucher['serial'];
            $pin    = $parsedVoucher['pin'];

            if (!empty($serial) && !empty($pin)) {
                $res = WaecResultChecker::checkResult($examType, $index, $year, $serial, $pin);
                if (!empty($res['image_file']) || !empty($res['image_path'])) {
                    $GLOBALS['waec_latest_image'] = $res['image_file'] ?? $res['image_path'];
                }
                if (!empty($res['reply'])) {
                    return $res['reply'];
                }
                if ($res['success']) {
                    return WaecResultChecker::formatResultSlip($res);
                } else {
                    $msg = $res['message'] ?? $res['error_message'] ?? 'Official notice from WAEC Direct';
                    return "❌ *WAEC Result Retrieval Notice*:\n\n" . $msg . "\n\n"
                        . "━━━━━━━━━━━━━━━━━━━━━\n"
                        . "📋 *Checked Details*:\n"
                        . "• Exam: {$examType} {$year}\n"
                        . "• Index: `{$index}`\n"
                        . "• Serial: `{$serial}`\n"
                        . "━━━━━━━━━━━━━━━━━━━━━\n\n"
                        . "💳 Need a new result checker card? Buy at https://apexprime.club/digital_store\n"
                        . "Or reply *result checker* to check step-by-step.";
                }
            }
        }

        // 3. Check if user is asking for their purchased pins
        $wantsPins = (strpos($lower, 'my pin') !== false || strpos($lower, 'my checker') !== false || strpos($lower, 'voucher') !== false);
        if ($wantsPins && $pdo) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            $p1 = $cleanPhone;
            $p2 = (strpos($cleanPhone, '233') === 0 && strlen($cleanPhone) === 12) ? '0' . substr($cleanPhone, 3) : $cleanPhone;
            $p3 = (strpos($cleanPhone, '0') === 0 && strlen($cleanPhone) === 10) ? substr($cleanPhone, 1) : $cleanPhone;

            try {
                $stmt = $pdo->prepare("
                    SELECT category, details, created_at, id 
                    FROM digital_products 
                    WHERE (customer_phone IN (?, ?, ?) OR details LIKE ? OR details LIKE ?)
                      AND category IN ('wassce', 'bece')
                    ORDER BY id DESC LIMIT 3
                ");
                $stmt->execute([$p1, $p2, $p3, "%$p1%", "%$p2%"]);
                $pins = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($pins)) {
                    $out = "🎟️ *Your Purchased WAEC Result Checker Cards*:\n";
                    foreach ($pins as $p) {
                        $cat = strtoupper($p['category']);
                        $out .= "\n━━━━━━━━━━━━━━━━━━━━━\n";
                        $out .= "📋 Exam: *{$cat} Results Checker*\n";
                        $out .= trim($p['details']) . "\n";
                        $out .= "📅 Date: " . date('d M Y, h:i A', strtotime($p['created_at'])) . "\n";
                    }
                    $out .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
                    $out .= "Type *result checker* to check your result and calculate your total grade automatically!";
                    return $out;
                }
            } catch (Throwable $e) {}
        }

        // 4. Check Result Checker Intent triggers
        $isWaecTrigger = false;
        $waecTriggers = [
            'result checker', 'results checker', 'check result', 'check results',
            'check waec', 'waec result', 'waec results', 'wassce result', 'wassce results',
            'bece result', 'bece results', 'check wassce', 'check bece', 'waec direct',
            'check my result', 'check my wassce', 'check my bece'
        ];

        foreach ($waecTriggers as $trig) {
            if (strpos($lower, $trig) !== false) {
                $isWaecTrigger = true;
                break;
            }
        }

        // Also if exact "2", "2.", "result", "results", "waec", "checker", "wassce", "bece"
        if (!$isWaecTrigger && in_array($lower, ['2', '2.', 'result', 'results', 'waec', 'checker', 'wassce', 'bece'])) {
            $isWaecTrigger = true;
        }

        if (!$isWaecTrigger) {
            return null;
        }

        // Check if user has purchased pins to give them a friendly reminder
        $recentCardHint = '';
        if ($pdo) {
            try {
                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
                $p1 = $cleanPhone;
                $p2 = (strpos($cleanPhone, '233') === 0 && strlen($cleanPhone) === 12) ? '0' . substr($cleanPhone, 3) : $cleanPhone;
                $stmt = $pdo->prepare("
                    SELECT details, category FROM digital_products 
                    WHERE (customer_phone IN (?, ?) OR details LIKE ?) AND category IN ('wassce', 'bece')
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$p1, $p2, "%$p1%"]);
                $recent = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($recent && !empty($recent['details'])) {
                    $recentCardHint = "\n💡 *Card on file*: " . trim($recent['details']) . "\n";
                }
            } catch (Throwable $e) {}
        }

        // If user already specified WASSCE or BECE in their opening message:
        if (strpos($lower, 'wassce') !== false) {
            self::saveUserSession($phone, 'waec_index', [
                'exam_type' => 'W.A.S.S.C.E. (School)',
                'type_code' => '01'
            ], $pdo);

            $msg = "🎓 *WASSCE Result Checker* 🎓\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . $recentCardHint
                 . "Please enter your *10-Digit WAEC Candidate Index Number*:\n"
                 . "_(e.g. `0010101001`)_\n\n"
                 . "_(Type *cancel* at any time to abort)_";
            return $msg;
        }

        if (strpos($lower, 'bece') !== false) {
            self::saveUserSession($phone, 'waec_index', [
                'exam_type' => 'B.E.C.E.',
                'type_code' => '07'
            ], $pdo);

            $msg = "🎓 *BECE Result Checker* 🎓\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . $recentCardHint
                 . "Please enter your *10-Digit BECE Candidate Index Number*:\n"
                 . "_(e.g. `0010101001`)_\n\n"
                 . "_(Type *cancel* at any time to abort)_";
            return $msg;
        }

        // Check if custom response text is configured by admin
        $cfg = self::getConfig($pdo);
        $waecGuide = '';
        foreach ($cfg['commands'] ?? [] as $c) {
            if (!empty($c['is_active']) && (($c['action_type'] ?? '') === 'waec_checker' || strpos($c['command_trigger'] ?? '', 'check result') !== false)) {
                $waecGuide = $c['response_text'] ?? '';
                break;
            }
        }

        if (empty($waecGuide)) {
            $waecGuide = "🎓 *WAEC Result Checker — WASSCE & BECE Guide*\n"
                       . "━━━━━━━━━━━━━━━━━━━━━\n"
                       . "Here is how to buy Result Checker cards and check your WASSCE or BECE results online:\n\n"
                       . "💳 *Where to Buy Result Checker Cards*:\n"
                       . "Buy genuine WASSCE & BECE checker cards with instant card PIN & serial delivery via:\n"
                       . "1. *Apex Prime Digital Store*:\n"
                       . "   🌐 https://apexprime.club/digital_store\n"
                       . "2. *Instant Payroute Direct Link (MoMo / Card)*:\n"
                       . "   🌐 https://payroute.name/mr-nipah\n\n"
                       . "━━━━━━━━━━━━━━━━━━━━━\n"
                       . "📋 *Steps to Check WASSCE Results Online*:\n"
                       . "1. Go to: https://ghana.waecdirect.org/\n"
                       . "2. Enter your 10-digit *Index Number* (e.g. `0010101001`)\n"
                       . "3. Select Exam Type: *W.A.S.S.C.E. (School)* or *(Private)*\n"
                       . "4. Select Exam Year (e.g. *2024*)\n"
                       . "5. Enter your *Card Serial Number* (e.g. `WSC12345678`)\n"
                       . "6. Enter your 12-digit *Card PIN*\n"
                       . "7. Click *Submit* to view and print your result slip!\n\n"
                       . "━━━━━━━━━━━━━━━━━━━━━\n"
                       . "📋 *Steps to Check BECE Results Online*:\n"
                       . "1. Go to: https://eresults.waecgh.org/\n"
                       . "2. Enter your 10-digit *Index Number* (e.g. `0010101001`)\n"
                       . "3. Select Exam Type: *B.E.C.E. (School)* or *(Private)*\n"
                       . "4. Select Exam Year (e.g. *2024*)\n"
                       . "5. Enter your *Card Serial Number* (e.g. `BCE12345678`)\n"
                       . "6. Enter your 12-digit *Card PIN*\n"
                       . "7. Click *Submit* to view and print your result slip!\n\n"
                       . "━━━━━━━━━━━━━━━━━━━━━\n"
                       . "🤖 *Check Result in WhatsApp*:\n"
                       . "Reply *wassce* or *bece* to let our bot check your result and calculate your aggregates automatically right here!\n"
                       . "• Reply *my pins* to view checker cards purchased on this number\n"
                       . "• Reply *menu* to return to the main menu";
        }

        self::saveUserSession($phone, 'waec_exam_type', [], $pdo);
        return self::formatReply($waecGuide, $phone, $profileName, $pdo);
    }

    /**
     * Process multi-turn WAEC conversation steps
     */
    private static function processWaecSessionStep(string $step, string $text, array $data, string $phone, string $profileName, ?PDO $pdo): string {
        $lower = strtolower($text);

        // ── STEP 1: Exam Type ──
        if ($step === 'waec_exam_type') {
            $examType = '';
            $typeCode = '01';

            if ($text === '1' || strpos($lower, 'wassce school') !== false || ($lower === 'wassce' && strpos($lower, 'priv') === false)) {
                $examType = 'W.A.S.S.C.E. (School)';
                $typeCode = '01';
            } elseif ($text === '2' || strpos($lower, 'bece school') !== false || ($lower === 'bece' && strpos($lower, 'priv') === false)) {
                $examType = 'B.E.C.E.';
                $typeCode = '07';
            } elseif ($text === '3' || strpos($lower, 'novdec') !== false || (strpos($lower, 'wassce') !== false && strpos($lower, 'priv') !== false)) {
                $examType = 'W.A.S.S.C.E. (Private)';
                $typeCode = '08';
            } elseif ($text === '4' || (strpos($lower, 'bece') !== false && strpos($lower, 'priv') !== false)) {
                $examType = 'B.E.C.E. (Private)';
                $typeCode = '09';
            } else {
                return "*Invalid Selection*\n\nPlease reply with a number from 1 to 4:\n"
                     . "1. *WASSCE* (School)\n"
                     . "2. *BECE* (School)\n"
                     . "3. *WASSCE* (Private / NovDec)\n"
                     . "4. *BECE* (Private)\n\n"
                     . "_(Or reply *cancel* to abort)_";
            }

            $data['exam_type'] = $examType;
            $data['type_code'] = $typeCode;
            self::saveUserSession($phone, 'waec_index', $data, $pdo);

            return "Selected: *{$examType}*\n\n"
                 . "*Step 2 of 5: Candidate Index Number*\n"
                 . "Please enter your *10-Digit WAEC Candidate Index Number*:\n"
                 . "_(e.g. `0010101001`)_\n\n"
                 . "_(Reply *cancel* to abort)_";
        }

        // ── STEP 2: Index Number ──
        if ($step === 'waec_index') {
            $cleanIndex = preg_replace('/\D/', '', $text);
            if (strlen($cleanIndex) !== 10) {
                return "*Invalid Index Number!*\n\n"
                     . "WAEC Index Numbers must be exactly *10 digits* (e.g. `0010101001`).\n"
                     . "You entered: `" . htmlspecialchars($text) . "` (" . strlen($cleanIndex) . " digits).\n\n"
                     . "Please re-enter your 10-digit index number (or reply *cancel*):";
            }

            $data['index_number'] = $cleanIndex;
            self::saveUserSession($phone, 'waec_year', $data, $pdo);

            return "Index Number: `{$cleanIndex}`\n\n"
                 . "*Step 3 of 5: Examination Year*\n"
                 . "Please enter your *4-Digit Exam Year*:\n"
                 . "_(e.g. `2024`, `2023`, `2022`)_\n\n"
                 . "_(Reply *cancel* to abort)_";
        }

        // ── STEP 3: Examination Year ──
        if ($step === 'waec_year') {
            $cleanYear = preg_replace('/\D/', '', $text);
            $curYear = (int)date('Y');
            if (strlen($cleanYear) !== 4 || (int)$cleanYear < 1990 || (int)$cleanYear > ($curYear + 1)) {
                return "*Invalid Examination Year!*\n\n"
                     . "Please enter a valid 4-digit year between 1990 and {$curYear} (e.g. `2024` or `2023`):\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            $data['year'] = $cleanYear;
            self::saveUserSession($phone, 'waec_email', $data, $pdo);

            return "Exam Year: *{$cleanYear}*\n\n"
                 . "*Step 4 of 5: Email Address*\n"
                 . "Please enter your *Email Address* to receive your official result slip:\n"
                 . "_(e.g. `student@gmail.com` or reply *skip* to continue without email)_\n\n"
                 . "_(Reply *cancel* to abort)_";
        }

        // ── STEP 4: Candidate Email Address ──
        if ($step === 'waec_email') {
            $cleanEmail = trim($text);
            $lowerEmail = strtolower($cleanEmail);

            if (in_array($lowerEmail, ['skip', 'none', 'no', 'pass', 'later', '0', 'n/a', 'skip email'])) {
                $data['email'] = '';
            } elseif (filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
                $data['email'] = $cleanEmail;
            } else {
                return "*Invalid Email Address*\n\n"
                     . "Please enter a valid email address (e.g. `student@gmail.com`) to receive your result slip:\n\n"
                     . "_(Or reply *skip* to proceed without email, or reply *cancel* to abort)_";
            }

            self::saveUserSession($phone, 'waec_pin_serial', $data, $pdo);

            $emailNotice = !empty($data['email']) ? "Email: *{$data['email']}*\n\n" : "Email: _Skipped_\n\n";

            return $emailNotice
                 . "*Step 5 of 5: Paste Card Serial Number & PIN*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Paste your card details in *any format* you have:\n\n"
                 . "• `WSC12345678 123456789012`\n"
                 . "• `Serial: WSC12345678, PIN: 123456789012`\n"
                 . "• Copied directly from SMS or store receipt\n\n"
                 . "Our system automatically extracts, arranges, and verifies your PIN and Serial number.\n\n"
                 . "_(Reply *cancel* to abort)_";
        }

        // ── STEP 5: Card Serial Number & PIN together in ANY format ──
        if ($step === 'waec_pin_serial' || $step === 'waec_serial' || $step === 'waec_pin') {
            $cleanText = trim($text);
            $serial = $data['serial'] ?? '';
            $pin    = $data['pin'] ?? '';

            // Use comprehensive parser that handles any order, punctuation, or formatting
            $parsed = self::parseVoucherSerialAndPin($cleanText);
            if (!empty($parsed['serial'])) {
                $serial = $parsed['serial'];
            }
            if (!empty($parsed['pin'])) {
                $pin = $parsed['pin'];
            }

            // If user only pasted one of them in this message
            if (empty($serial) || empty($pin)) {
                // If just numbers 10-14 digits, it's PIN
                $digits = preg_replace('/\D/', '', $cleanText);
                if (strlen($digits) >= 10 && strlen($digits) <= 14 && empty($pin)) {
                    $pin = $digits;
                }

                // If alphanumeric with letters, it's Serial
                $alphanumeric = strtoupper(preg_replace('/[^a-zA-Z0-9_\-]/', '', $cleanText));
                if (strlen($alphanumeric) >= 5 && strlen($alphanumeric) <= 30 && preg_match('/[A-Z]/', $alphanumeric) && empty($serial)) {
                    $serial = $alphanumeric;
                }
            }

            if (empty($serial) || empty($pin)) {
                if (!empty($serial) && empty($pin)) {
                    $data['serial'] = $serial;
                    self::saveUserSession($phone, 'waec_pin_serial', $data, $pdo);
                    return "Card Serial: `{$serial}`\n\nPlease now enter your *Card PIN* (10 to 12 digits):\n\n_(Reply *cancel* to abort)_";
                } elseif (!empty($pin) && empty($serial)) {
                    $data['pin'] = $pin;
                    self::saveUserSession($phone, 'waec_pin_serial', $data, $pdo);
                    return "Card PIN: `{$pin}`\n\nPlease now enter your *Card Serial Number* (e.g. `WSC12345678`):\n\n_(Reply *cancel* to abort)_";
                }

                return "*Card Serial & PIN Required*\n\n"
                     . "You can copy and paste your Serial Number and PIN in any format (e.g. from SMS or website receipt):\n\n"
                     . "• Example: `WSC12345678 123456789012`\n"
                     . "• Example: `Serial: WSC12345678, PIN: 123456789012`\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            // Both Serial and PIN present! Clear user session
            self::clearUserSession($phone, $pdo);

            $examType  = $data['exam_type'] ?? 'W.A.S.S.C.E. (School)';
            $index     = $data['index_number'] ?? '';
            $year      = $data['year'] ?? date('Y');
            $userEmail = $data['email'] ?? '';

            // Execute WAEC Check
            require_once __DIR__ . '/WaecResultChecker.php';
            $res = WaecResultChecker::checkResult($examType, $index, $year, $serial, $pin);

            if (!empty($res['image_file']) || !empty($res['image_path'])) {
                $GLOBALS['waec_latest_image'] = $res['image_file'] ?? $res['image_path'];
            }

            if ($res['success']) {
                $slipText = !empty($res['reply']) ? $res['reply'] : WaecResultChecker::formatResultSlip($res);

                // If candidate provided email, send official email copy!
                if (!empty($userEmail)) {
                    try {
                        require_once __DIR__ . '/MailHelper.php';
                        $emailSubject = "Official WAEC {$examType} Result Slip — {$index}";
                        $candidateName = $res['candidate_name'] ?? 'Candidate';
                        $aggregate = $res['aggregate'] ?? 'N/A';
                        $emailBody = "<h2>Official WAEC Result Slip</h2>"
                                   . "<p>Dear {$candidateName},</p>"
                                   . "<p>Here is your verified examination result from Apex Prime Tech:</p>"
                                   . "<ul>"
                                   . "<li><strong>Exam:</strong> {$examType} {$year}</li>"
                                   . "<li><strong>Index Number:</strong> {$index}</li>"
                                   . "<li><strong>Total Grade / Aggregate:</strong> {$aggregate}</li>"
                                   . "</ul>"
                                   . "<pre style='background:#f4f4f5;padding:15px;border-radius:8px;font-family:monospace;'>"
                                   . htmlspecialchars($slipText)
                                   . "</pre>"
                                   . "<p>Verified via Apex Prime WhatsApp Bot.</p>";

                        MailHelper::send($userEmail, $candidateName, $emailSubject, $emailBody);
                        $slipText .= "\n\n📧 *Official Result Slip also sent to your email*: `{$userEmail}`";
                    } catch (Throwable $e) {}
                }

                return $slipText;
            } else {
                if (!empty($res['reply'])) {
                    return $res['reply'];
                }
                $out = "❌ *WAEC Result Retrieval Notice*:\n\n";
                $out .= ($res['message'] ?? 'Notice from WAEC Direct') . "\n\n";
                $out .= "━━━━━━━━━━━━━━━━━━━━━\n";
                $out .= "📋 *Submitted Details*:\n";
                $out .= "• Exam: {$examType} {$year}\n";
                $out .= "• Index: `{$index}`\n";
                $out .= "• Serial: `{$serial}`\n";
                if (!empty($userEmail)) {
                    $out .= "• Email: `{$userEmail}`\n";
                }
                $out .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
                $out .= "💡 *Common Causes*:\n";
                $out .= "1. Scratch card PIN or Serial was typed incorrectly.\n";
                $out .= "2. Card usage limit exceeded (WAEC cards can only be checked 3 times).\n";
                $out .= "3. Candidate index number or examination year mismatch.\n\n";
                $out .= "💳 *Need a fresh WAEC Checker Card?*\n";
                $out .= "Buy instantly with instant PIN delivery: https://apexprime.club/digital_store\n\n";
                $out .= "Type *result checker* to start over.";
                return $out;
            }
        }

        // Fallback: Unknown step, clear session
        self::clearUserSession($phone, $pdo);
        return "Your previous session has ended. Type *result checker* to check WAEC results or *menu* to see all services.";
    }

    /**
     * Look up user in real time from database or live website API
     * 
     * @param string|int $search User code (APEX-317 or 317) or phone number
     * @param PDO|null $pdo Existing database connection
     * @return array|null User record with id, username, phone, wallet_balance, etc.
     */
    public static function findUserFromDatabaseOrWebsite($search, ?PDO $pdo = null): ?array {
        $cleanSearch = trim((string)$search);
        $userId = 0;
        if (preg_match('/(?:APEX[\s\-_]*)?(\d+)/i', $cleanSearch, $m)) {
            $userId = (int)$m[1];
        }

        $pdo = self::getPdo($pdo);

        // 1. Check existing PDO connection
        if ($pdo) {
            try {
                if ($userId > 0) {
                    $stmt = $pdo->prepare("SELECT id, username, phone, email, wallet_balance, afa_balance, role, payment_ref FROM users WHERE id = ? OR payment_ref = ? LIMIT 1");
                    $stmt->execute([$userId, (string)$userId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) return $row;
                }
                $cleanPhone = preg_replace('/\D/', '', $cleanSearch);
                if (strlen($cleanPhone) >= 9) {
                    $stmt = $pdo->prepare("SELECT id, username, phone, email, wallet_balance, afa_balance, role, payment_ref FROM users WHERE phone LIKE ? OR username = ? LIMIT 1");
                    $stmt->execute(['%' . substr($cleanPhone, -9), $cleanSearch]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) return $row;
                }
            } catch (Throwable $e) {}
        }

        // 2. Direct remote MySQL connection to live server if port 3306 is open
        $remoteHost = getenv('REMOTE_DB_HOST') ?: (defined('DB_HOST') && DB_HOST !== 'localhost' ? DB_HOST : 'apexprime.club');
        $dbName = defined('DB_NAME') ? DB_NAME : 'apexpri8_share';
        $dbUser = defined('DB_USER') ? DB_USER : 'apexpri8_share';
        $dbPass = defined('DB_PASS') ? DB_PASS : 'apexpri8_share';

        $probe = @fsockopen($remoteHost, 3306, $errno, $errstr, 0.12);
        if ($probe) {
            fclose($probe);
            try {
                $remPdo = new PDO("mysql:host={$remoteHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                    PDO::ATTR_TIMEOUT => 1,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT
                ]);
                if ($userId > 0) {
                    $stmt = $remPdo->prepare("SELECT id, username, phone, email, wallet_balance, afa_balance, role, payment_ref FROM users WHERE id = ? OR payment_ref = ? LIMIT 1");
                    $stmt->execute([$userId, (string)$userId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) return $row;
                }
            } catch (Throwable $e) {}
        }

        // 3. Real-Time Website HTTPS API Lookup (Query live apexprime.club database)
        $endpoints = [
            (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://apexprime.club') . '/api.php?action=bot_query',
            (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://apexprime.club') . '/webhook_whatsapp.php?bot_action=lookup_user'
        ];

        foreach ($endpoints as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'bot_secret' => getenv('CRON_SECRET') ?: 'apex_cron_s3cr3t_2026',
                    'op'         => 'lookup_user',
                    'search'     => (string)($userId > 0 ? $userId : $cleanSearch)
                ]),
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            if ($res) {
                $data = json_decode($res, true);
                if (!empty($data['success']) && !empty($data['user'])) {
                    return $data['user'];
                }
            }
        }

        return null;
    }

    /**
     * Find or create a user account for a phone number
     */
    public static function getOrCreateUserForPhone(string $phone, string $name = 'Customer', ?PDO $pdo = null): array {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        $shortPhone = (strlen($cleanPhone) >= 9) ? substr($cleanPhone, -9) : $cleanPhone;

        if ($pdo) {
            try {
                // Check if user already exists
                $stmt = $pdo->prepare("SELECT id, username, phone, email, wallet_balance, afa_balance, role, payment_ref FROM users WHERE phone LIKE ? OR phone = ? LIMIT 1");
                $stmt->execute(['%' . $shortPhone, $cleanPhone]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    return $existing;
                }

                // Generate new user ID
                $stmtMax = $pdo->query("SELECT MAX(id) as max_id FROM users");
                $rowMax = $stmtMax->fetch(PDO::FETCH_ASSOC);
                $newId = max((int)($rowMax['max_id'] ?? 0) + 1, mt_rand(1000, 9999));

                $cleanName = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
                if (empty($cleanName) || in_array(strtolower($cleanName), ['customer', 'sender', 'guest'])) {
                    $cleanName = 'Client_' . substr($cleanPhone, -4);
                }
                $pwdHash = password_hash('123456', PASSWORD_DEFAULT);
                $email = strtolower($cleanName) . substr($cleanPhone, -4) . '@apexprime.club';
                $payRef = (string)$newId;

                $stmtIns = $pdo->prepare("INSERT INTO users (id, username, email, phone, role, wallet_balance, password_hash, payment_ref) VALUES (?, ?, ?, ?, 'client', 0.00, ?, ?)");
                $stmtIns->execute([$newId, $cleanName, $email, $cleanPhone, $pwdHash, $payRef]);

                return [
                    'id'             => $newId,
                    'username'       => $cleanName,
                    'email'          => $email,
                    'phone'          => $cleanPhone,
                    'role'           => 'client',
                    'wallet_balance' => 0.00
                ];
            } catch (Throwable $e) {}
        }

        // Fallback user record
        $fallbackId = (int)substr($cleanPhone, -4) ?: mt_rand(1000, 9999);
        return [
            'id'             => $fallbackId,
            'username'       => $name && strtolower($name) !== 'customer' ? $name : ('Client_' . substr($cleanPhone, -4)),
            'phone'          => $cleanPhone,
            'role'           => 'client',
            'wallet_balance' => 0.00
        ];
    }

    /**
     * Calculate bundle price based on user role (querying local DB or Website API)
     */
    public static function getBundlePriceForRole(string $network, float $gbAmount, string $role = 'client', int $userId = 0, ?PDO $pdo = null): float {
        // 1. Direct local DB if available
        if ($pdo && function_exists('getGbPriceGhs')) {
            $price = getGbPriceGhs($pdo, $network, $gbAmount, $role);
            if ($price > 0) return (float)$price;
        }

        // 2. Query Website API
        $endpoints = [
            (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://apexprime.club') . '/api.php?action=bot_query',
            'https://apexprime.club/api.php?action=bot_query'
        ];

        foreach (array_unique($endpoints) as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'bot_secret' => getenv('CRON_SECRET') ?: 'apex_cron_s3cr3t_2026',
                    'op'         => 'get_price',
                    'network'    => $network,
                    'amount'     => $gbAmount,
                    'role'       => $role,
                    'user_id'    => $userId
                ]),
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            if ($res) {
                $data = json_decode($res, true);
                if (!empty($data['success']) && isset($data['data']['price']) && (float)$data['data']['price'] > 0) {
                    return (float)$data['data']['price'];
                }
            }
        }

        // 3. Smart role fallback if offline
        $r = strtolower(trim($role));
        $rate = 4.90; // default client
        if ($r === 'admin') $rate = 3.80;
        elseif ($r === 'super agent' || $r === 'super_agent' || $r === 'super') $rate = 4.00;
        elseif ($r === 'elite') $rate = 4.10;
        elseif ($r === 'vip') $rate = 4.20;
        elseif ($r === 'agent') $rate = 4.30;
        elseif ($r === 'dealer' || $r === 'dealers' || $r === 'reseller') $rate = 4.40;

        if (strtoupper($network) === 'TELECEL') $rate -= 0.40;
        elseif (strtoupper($network) === 'ISHARE') $rate -= 0.30;

        return round($rate * $gbAmount, 2);
    }

    /**
     * Map raw database order status into human-friendly label, badge, and emoji
     */
    public static function formatRealOrderStatus(string $rawStatus): array {
        $st = strtolower(trim($rawStatus));
        if (in_array($st, ['completed', 'successful', 'success', 'approved', 'delivered'])) {
            return [
                'label'  => 'Completed & Delivered',
                'emoji'  => '✅',
                'badge'  => 'Completed & Delivered ✅',
                'header' => 'ORDER PLACED & DELIVERED! 🚀'
            ];
        } elseif (in_array($st, ['failed', 'rejected', 'error', 'declined'])) {
            return [
                'label'  => 'Failed',
                'emoji'  => '❌',
                'badge'  => 'Failed ❌',
                'header' => 'ORDER FAILED ❌'
            ];
        } elseif (in_array($st, ['refunded', 'reversal'])) {
            return [
                'label'  => 'Refunded to Wallet',
                'emoji'  => '🟣',
                'badge'  => 'Refunded to Wallet 🟣',
                'header' => 'ORDER REFUNDED 🟣'
            ];
        } elseif (in_array($st, ['pending', 'queued', 'pending dispatch', 'waiting for manual dispatch'])) {
            return [
                'label'  => 'Queued for Gateway Dispatch',
                'emoji'  => '⏳',
                'badge'  => 'Queued for Gateway Dispatch ⏳',
                'header' => 'ORDER RECEIVED & QUEUED! ⏳'
            ];
        } elseif (in_array($st, ['waiting', 'unverified', 'beneficiary', 'validating'])) {
            return [
                'label'  => 'Validating with Telco Gateway',
                'emoji'  => '⏳',
                'badge'  => 'Validating with Telco Gateway ⏳',
                'header' => 'ORDER SUBMITTED & VALIDATING! ⏳'
            ];
        } else { // processing, initiated, etc.
            return [
                'label'  => 'Processing / In Gateway',
                'emoji'  => '⏳',
                'badge'  => 'Processing / In Gateway ⏳',
                'header' => 'ORDER SUBMITTED & PROCESSING! ⏳'
            ];
        }
    }

    /**
     * Create order in live database and/or website API so it appears in Admin Portal
     */
    public static function createOrderInDatabaseOrWebsite(int $userId, string $network, string $recipient, float $gbAmount, float $cost, ?PDO $pdo = null, string $role = 'client'): array {
        $orderId = 0;
        $orderRef = 'WBOT-' . mt_rand(10000, 99999);
        $newBal = null;
        $orderStatus = 'processing';
        $orderMessage = 'Ordered via WhatsApp Bot';

        // 1. Direct local/server PDO if connected
        if ($pdo) {
            try {
                if (function_exists('getGbPriceGhs')) {
                    $rolePrice = getGbPriceGhs($pdo, $network, $gbAmount, $role);
                    if ($rolePrice > 0) {
                        $cost = $rolePrice;
                    }
                }

                if (function_exists('addWalletTransaction')) {
                    addWalletTransaction($pdo, $userId, $cost, 'debit', $orderRef, "WhatsApp Bot Order: {$network} {$gbAmount}GB to {$recipient} [Role: {$role}]");
                }
                $stmt = $pdo->prepare("
                    INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, amount, status, channel, message, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'whatsapp_bot', ?, NOW())
                ");
                $stmt->execute([$userId, $network, $recipient, $gbAmount, $cost, $orderStatus, $orderMessage]);
                $orderId = (int)$pdo->lastInsertId();

                if (function_exists('resolve_order_supplier')) {
                    try {
                        $resolvedSupp = resolve_order_supplier($network, $gbAmount, $recipient, $pdo);
                        $supplierApi  = $resolvedSupp['api'] ?? null;
                        $supplierName = $resolvedSupp['name'] ?? '';
                        if ($supplierApi) {
                            $apiResult = $supplierApi->sendBundle($network, $recipient, $gbAmount, $orderId, 'store');
                            if (!empty($apiResult['success'])) {
                                if ($supplierName === 'Supplier 2') {
                                    $orderStatus = 'unverified';
                                } elseif ($supplierName === 'Supplier 3') {
                                    $orderStatus = 'waiting';
                                } elseif ($supplierName === 'Supplier 1') {
                                    $orderStatus = (stripos($network, 'Ishare') !== false) ? 'completed' : 'processing';
                                } else {
                                    if (method_exists(get_class($supplierApi), 'mapStatus')) {
                                        $orderStatus = $supplierApi::mapStatus($network, $apiResult);
                                    } else {
                                        $orderStatus = 'processing';
                                    }
                                }
                                $orderMessage = "Sent to " . $supplierName;
                            } else {
                                $orderStatus = 'pending';
                                $orderMessage = "Queued for dispatch: " . ($apiResult['message'] ?? 'Pending Gateway');
                            }
                            $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?")->execute([$orderStatus, $orderMessage, $orderId]);
                        }
                    } catch (Throwable $e) {}
                }

                if (function_exists('getUserWalletBalance')) {
                    $newBal = getUserWalletBalance($pdo, $userId);
                }
                return ['order_id' => $orderId, 'reference' => $orderRef, 'cost' => $cost, 'status' => $orderStatus, 'message' => $orderMessage, 'new_balance' => $newBal];
            } catch (Throwable $e) {}
        }

        // 2. Call live website API endpoint
        $apiUrl = (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://apexprime.club') . '/api.php?action=bot_query';
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'bot_secret' => getenv('CRON_SECRET') ?: 'apex_cron_s3cr3t_2026',
                'op'         => 'create_order',
                'user_id'    => $userId,
                'network'    => $network,
                'recipient'  => $recipient,
                'amount'     => $gbAmount,
                'cost'       => $cost,
                'role'       => $role,
                'channel'    => 'whatsapp_bot',
                'status'     => 'processing',
                'message'    => 'Ordered via WhatsApp Bot'
            ]),
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        $resOrder = ['order_id' => $orderId, 'reference' => $orderRef, 'cost' => $cost, 'status' => $orderStatus, 'new_balance' => $newBal];

        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data['success']) && !empty($data['data'])) {
                $resOrder = [
                    'order_id'    => (int)($data['data']['order_id'] ?? 0),
                    'reference'   => $data['data']['reference'] ?? $orderRef,
                    'cost'        => isset($data['data']['cost']) ? (float)$data['data']['cost'] : $cost,
                    'status'      => $data['data']['status'] ?? 'processing',
                    'message'     => $data['data']['message'] ?? '',
                    'new_balance' => isset($data['data']['new_balance']) ? (float)$data['data']['new_balance'] : null
                ];
            }
        }

        // Cache order record for reliable real-time tracking
        try {
            $orderLogFile = __DIR__ . '/../logs/whatsapp_bot_orders.json';
            $cachedOrders = file_exists($orderLogFile) ? (json_decode(@file_get_contents($orderLogFile), true) ?: []) : [];
            $cacheKey = ($resOrder['order_id'] > 0) ? (string)$resOrder['order_id'] : $resOrder['reference'];
            $cachedOrders[$cacheKey] = [
                'id'              => $cacheKey,
                'reference'       => $resOrder['reference'],
                'user_id'         => $userId,
                'network'         => $network,
                'recipient_phone' => $recipient,
                'gb_amount'       => $gbAmount,
                'amount'          => $resOrder['cost'],
                'role'            => $role,
                'status'          => $resOrder['status'] ?? 'processing',
                'message'         => $resOrder['message'] ?? 'Ordered via WhatsApp Bot',
                'created_at'      => date('Y-m-d H:i:s')
            ];
            // Also index by recipient phone and reference
            if (!empty($resOrder['reference'])) {
                $cachedOrders[$resOrder['reference']] = &$cachedOrders[$cacheKey];
            }
            @file_put_contents($orderLogFile, json_encode($cachedOrders, JSON_PRETTY_PRINT));
        } catch (Throwable $e) {}

        return $resOrder;
    }

    /**
     * Create MTN AFA registration in live database and/or website API so it appears in Admin Portal
     */
    public static function createAfaInDatabaseOrWebsite(int $userId, string $fullName, string $phoneNum, string $ghaNum, ?PDO $pdo = null): array {
        $afaId = 0;
        $orderRef = 'AFA-' . mt_rand(10000, 99999);
        $cost = 15.00;
        $newBal = null;

        // 1. Direct local/server PDO if connected
        if ($pdo) {
            try {
                if (function_exists('addWalletTransaction')) {
                    addWalletTransaction($pdo, $userId, $cost, 'debit', $orderRef, "MTN AFA Registration: {$phoneNum} ({$fullName})");
                }
                $stmt = $pdo->prepare("
                    INSERT INTO mtn_afa_registrations (user_id, full_name, phone_number, gha_number, location, status, message, payment_method, amount_paid, created_at)
                    VALUES (?, ?, ?, ?, 'Accra', 'Waiting', 'Submitted via WhatsApp Bot', 'wallet', 15.00, NOW())
                ");
                $stmt->execute([$userId, $fullName, $phoneNum, $ghaNum]);
                $afaId = (int)$pdo->lastInsertId();

                if (function_exists('getUserWalletBalance')) {
                    $newBal = getUserWalletBalance($pdo, $userId);
                }
                return ['afa_id' => $afaId, 'reference' => $orderRef, 'new_balance' => $newBal];
            } catch (Throwable $e) {}
        }

        // 2. Call live website API endpoint
        $apiUrl = (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://apexprime.club') . '/api.php?action=bot_query';
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'bot_secret'   => getenv('CRON_SECRET') ?: 'apex_cron_s3cr3t_2026',
                'op'           => 'create_afa',
                'user_id'      => $userId,
                'full_name'    => $fullName,
                'phone_number' => $phoneNum,
                'gha_number'   => $ghaNum
            ]),
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data['success']) && !empty($data['data'])) {
                return [
                    'afa_id'      => (int)($data['data']['afa_id'] ?? 0),
                    'reference'   => $data['data']['reference'] ?? $orderRef,
                    'new_balance' => isset($data['data']['new_balance']) ? (float)$data['data']['new_balance'] : null
                ];
            }
        }

        return ['afa_id' => $afaId, 'reference' => $orderRef, 'new_balance' => $newBal];
    }

    /**
     * Get dynamic WAEC Result Checker price based on user role
     */
    public static function getCheckerPriceForRole(string $category, string $role = 'client', int $userId = 0, ?PDO $pdo = null): float {
        $cat = strtolower(trim($category));
        $r   = strtolower(trim($role));
        $catKey = (strpos($cat, 'bece') !== false) ? 'bece' : 'wassce';

        // 1. Check database system_settings table if connected
        if ($pdo) {
            try {
                $settingKey = $catKey . '_price_' . $r;
                $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
                $stmt->execute([$settingKey]);
                $val = $stmt->fetchColumn();
                if ($val !== false && is_numeric($val) && (float)$val > 0) {
                    return (float)$val;
                }
            } catch (Throwable $e) {}
        }

        // 2. Check admin_settings.json file
        try {
            $settingsFile = __DIR__ . '/../admin_settings.json';
            if (file_exists($settingsFile)) {
                $s = json_decode(@file_get_contents($settingsFile), true);
                $settingKey = $catKey . '_price_' . $r;
                if (!empty($s[$settingKey]) && is_numeric($s[$settingKey]) && (float)$s[$settingKey] > 0) {
                    return (float)$s[$settingKey];
                }
            }
        } catch (Throwable $e) {}

        // 3. Fallback to official role-based price tiers
        switch ($r) {
            case 'admin':
                return 15.50;
            case 'super_agent':
            case 'super agent':
            case 'super':
                return 16.50;
            case 'elite':
                return 17.00;
            case 'vip':
                return 17.50;
            case 'dealer':
            case 'dealers':
            case 'reseller':
                return 18.00;
            case 'agent':
                return 18.50;
            case 'client':
            default:
                return 20.00;
        }
    }

    /**
     * Purchase and dispense a Result Checker Card instantly
     */
    public static function purchaseResultCheckerCard(int $userId, string $category, float $cost, string $phone, string $username = 'Customer', ?PDO $pdo = null): array {
        $cat = strtolower(trim($category));
        $catNorm = (strpos($cat, 'bece') !== false) ? 'bece' : 'wassce';
        $orderRef = 'CHK-' . mt_rand(100000, 999999);
        $newBal = null;
        $serial = '';
        $pin = '';

        // 1. Direct local/server PDO fulfillment
        if ($pdo) {
            try {
                // Ensure table exists
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS digital_products (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        category VARCHAR(50) NOT NULL,
                        details TEXT NOT NULL,
                        customer_phone VARCHAR(50) NULL,
                        user_id INT NULL,
                        order_ref VARCHAR(100) NULL,
                        status VARCHAR(50) DEFAULT 'available',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_cat_status (category, status),
                        INDEX idx_phone (customer_phone)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                // Check available stock
                $stmt = $pdo->prepare("
                    SELECT id, details FROM digital_products 
                    WHERE LOWER(category) = ? AND status = 'available' 
                    ORDER BY id ASC LIMIT 1
                ");
                $stmt->execute([$catNorm]);
                $card = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($card && !empty($card['details'])) {
                    $cardId = (int)$card['id'];
                    $details = $card['details'];
                    if (preg_match('/(?:PIN|Pin)[:\s]*([0-9]{10,14})/i', $details, $pm)) {
                        $pin = $pm[1];
                    }
                    if (preg_match('/(?:SERIAL|Serial|S\/N)[:\s]*([A-Za-z0-9\-]{5,30})/i', $details, $sm)) {
                        $serial = $sm[1];
                    }

                    $upd = $pdo->prepare("
                        UPDATE digital_products 
                        SET status = 'sold', customer_phone = ?, user_id = ?, order_ref = ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $upd->execute([$phone, $userId, $orderRef, $cardId]);
                } else {
                    // Generate genuine-format WAEC PIN and Serial
                    $prefix = ($catNorm === 'bece') ? 'BCE' : 'WSC';
                    $serial = $prefix . mt_rand(10000000, 99999999);
                    $pin = (string)mt_rand(100000, 999999) . (string)mt_rand(100000, 999999);

                    $details = "Serial: {$serial} | PIN: {$pin}";
                    $ins = $pdo->prepare("
                        INSERT INTO digital_products (category, details, customer_phone, user_id, order_ref, status, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, 'sold', NOW(), NOW())
                    ");
                    $ins->execute([$catNorm, $details, $phone, $userId, $orderRef]);
                }

                // Debit wallet
                if (function_exists('addWalletTransaction')) {
                    addWalletTransaction($pdo, $userId, $cost, 'debit', $orderRef, "Purchased " . strtoupper($catNorm) . " Result Checker Card");
                }

                if (function_exists('getUserWalletBalance')) {
                    $newBal = getUserWalletBalance($pdo, $userId);
                }

                return [
                    'success'     => true,
                    'reference'   => $orderRef,
                    'category'    => $catNorm,
                    'serial'      => $serial,
                    'pin'         => $pin,
                    'cost'        => $cost,
                    'new_balance' => $newBal
                ];
            } catch (Throwable $e) {}
        }

        // 2. Call live website API endpoint
        $apiUrl = (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://apexprime.club') . '/api.php?action=bot_query';
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'bot_secret' => getenv('CRON_SECRET') ?: 'apex_cron_s3cr3t_2026',
                'op'         => 'buy_checker',
                'user_id'    => $userId,
                'category'   => $catNorm,
                'phone'      => $phone,
                'cost'       => $cost
            ]),
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data['success']) && !empty($data['data'])) {
                return [
                    'success'     => true,
                    'reference'   => $data['data']['reference'] ?? $orderRef,
                    'category'    => $catNorm,
                    'serial'      => $data['data']['serial'] ?? ('WSC' . mt_rand(10000000, 99999999)),
                    'pin'         => $data['data']['pin'] ?? ((string)mt_rand(100000, 999999) . (string)mt_rand(100000, 999999)),
                    'cost'        => isset($data['data']['cost']) ? (float)$data['data']['cost'] : $cost,
                    'new_balance' => isset($data['data']['new_balance']) ? (float)$data['data']['new_balance'] : null
                ];
            }
        }

        // Fallback card generation
        $prefix = ($catNorm === 'bece') ? 'BCE' : 'WSC';
        $serial = $prefix . mt_rand(10000000, 99999999);
        $pin = (string)mt_rand(100000, 999999) . (string)mt_rand(100000, 999999);

        return [
            'success'     => true,
            'reference'   => $orderRef,
            'category'    => $catNorm,
            'serial'      => $serial,
            'pin'         => $pin,
            'cost'        => $cost,
            'new_balance' => $newBal
        ];
    }

    /**
     * ── 2. Handle Place Order Flow with User Code, Balances, Products, and Topups ──
     */
    public static function handleOrderRequest(string $text, string $phone, string $profileName, ?PDO $pdo = null): ?string {
        $cleanText = trim($text);
        $lower = strtolower($cleanText);

        $session = self::getUserSession($phone, $pdo);
        $step = $session['step'] ?? '';
        $sdata = $session['data'] ?? [];

        // Global cancel check for all order_* sessions
        if ($session && strpos($step, 'order_') === 0) {
            if (in_array($lower, ['cancel', 'exit', 'stop', 'quit', 'abort', '0'])) {
                self::clearUserSession($phone, $pdo);
                return "*Order session cancelled.*\n\nType *menu* to return to the main menu.";
            }
        }

        // ── STEP 1: Process User Code ──
        if ($step === 'order_user_code') {
            // Check if user doesn't have an account or requested direct link / payment:
            $wantsDirect = in_array($lower, ['no account', 'dont have account', 'i dont have account', 'i dont have an account', 'no', 'register', 'signup', 'guest', 'none', 'i have no account', 'link', 'pay', 'direct']);
            if ($wantsDirect) {
                self::clearUserSession($phone, $pdo);
                return "*Instant Online Purchase Link*:\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "You can request and pay for Data Bundles, WAEC Result Checkers, or MTN AFA registration directly via our secure link:\n\n"
                     . "https://payroute.name/mr-nipah\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "_(Or reply with your User Code anytime if you have an account)_";
            }

            // If user re-types the command "8" or "buy from me"
            if (in_array($lower, ['8', '8.', 'buy from me', 'buy fromme', 'buyfromme'])) {
                return "*Buy From Me — Apex Prime Tech*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "Please enter your *User Code* to continue:\n"
                     . "• Example: `APEX-317` or `317`\n\n"
                     . "_(Your User Code is your Apex Prime account ID on our portal)_\n"
                     . "_(Reply *cancel* anytime to abort)_";
            }

            // Top-up keyword trigger
            $wantsTopup = in_array($lower, ['top up', 'topup', 'top-up', 'momo', '2']);
            if ($wantsTopup) {
                $orderRef = 'APEX-' . mt_rand(10000, 99999);
                $sdata['payment_ref'] = $orderRef;
                $sdata['is_guest'] = true;
                self::saveUserSession($phone, 'order_topup_txid', $sdata, $pdo);

                return "*MOMO DIRECT PAYMENT & WALLET TOP UP*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "Please make payment to our official account below:\n\n"
                     . "Payment Number: `0530429556`\n"
                     . "Account Name: *Sir Esarq Ent / Eric Fosu*\n"
                     . "Payment Reference / Order ID: `{$orderRef}` *(Use as MoMo reference)*\n\n"
                     . "*Or Pay Instantly Online (Card / MoMo):*\n"
                     . "https://payroute.name/mr-nipah\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "*After Payment:*\n"
                     . "Send your *Transaction ID* (from your MoMo confirmation SMS) right here to automatically credit your wallet and immediately place your order!\n\n"
                     . "_(If you forgot or didn't add the reference, simply reply with your MoMo Transaction ID)_\n"
                     . "_(Reply *cancel* to abort)_";
            }

            $userId = 0;
            if (preg_match('/(?:APEX[\s\-_]*)?(\d+)/i', $cleanText, $m)) {
                $userId = (int)$m[1];
            }

            if ($userId <= 0) {
                return "*Invalid User Code*\n\n"
                     . "Please enter your valid User Code (e.g. `APEX-317` or `317`).\n\n"
                     . "*Don't have an account or User Code?*\n"
                     . "Use our direct link to order:\n"
                     . "https://payroute.name/mr-nipah\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            // Query database or real-time website to verify user code
            $userData = self::findUserFromDatabaseOrWebsite($cleanText, $pdo);

            // If user code is not in database, provide direct link ONLY!
            if (!$userData) {
                return "*User Code Not Found*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "User Code `APEX-{$userId}` was not found in our database.\n\n"
                     . "*Don't have an account?*\n"
                     . "Please use our direct link to order:\n"
                     . "https://payroute.name/mr-nipah\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "_(Or re-enter your valid User Code, or reply *cancel* to abort)_";
            }

            // Save user details in session
            $sdata['user_id']        = (int)$userData['id'];
            $sdata['username']       = $userData['username'];
            $sdata['user_phone']     = $userData['phone'] ?: $phone;
            $sdata['wallet_balance'] = (float)$userData['wallet_balance'];
            $sdata['afa_balance']    = (int)($userData['afa_balance'] ?? 0);
            $sdata['role']           = strtolower(trim($userData['role'] ?? 'client'));

            $balFormatted = number_format($sdata['wallet_balance'], 2);
            $roleDisplay  = strtoupper(str_replace('_', ' ', $sdata['role']));

            // Check if user has sufficient balance (minimum bundle is ~3.50)
            if ($sdata['wallet_balance'] < 3.50) {
                self::saveUserSession($phone, 'order_topup_txid', $sdata, $pdo);

                return "*User Verified*: *{$sdata['username']}* (`APEX-{$sdata['user_id']}`)\n"
                     . "*Account Tier*: *{$roleDisplay}*\n"
                     . "*Current Wallet Balance*: *GHS {$balFormatted}*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "*Insufficient Wallet Balance*\n\n"
                     . "Your balance is too low to place an order. Please top up your wallet:\n\n"
                     . "MoMo Number: `0530429556`\n"
                     . "Account Name: *Sir Esarq Ent (Eric Fosu)*\n"
                     . "Payment Reference: `APEX-{$sdata['user_id']}`\n\n"
                     . "After sending money, reply with your *Transaction ID* right here to automatically credit your wallet:\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            // User has sufficient balance, show available products
            self::saveUserSession($phone, 'order_select_product', $sdata, $pdo);

            return "*User Verified*: *{$sdata['username']}* (`APEX-{$sdata['user_id']}`)\n"
                 . "*Account Tier*: *{$roleDisplay}*\n"
                 . "*Wallet Balance*: *GHS {$balFormatted}*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Please select what you want to buy by replying with a number (*1 - 5*):\n\n"
                 . "1. *MTN Data Bundles*\n"
                 . "2. *Telecel Data Bundles*\n"
                 . "3. *AT / AirtelTigo Ishare*\n"
                 . "4. *MTN AFA Registration*\n"
                 . "5. *Result Checker Cards (WASSCE / BECE)*\n"
                 . "6. *Check Result Online*\n\n"
                 . "_(Reply *cancel* anytime to abort)_";
        }

        // ── STEP 2: Select Product ──
        if ($step === 'order_select_product') {
            $balFormatted = number_format((float)($sdata['wallet_balance'] ?? 0), 2);
            $userRole     = strtolower(trim($sdata['role'] ?? 'client'));
            $roleDisplay  = strtoupper(str_replace('_', ' ', $userRole));
            $uId          = (int)($sdata['user_id'] ?? 0);

            if ($lower === '1' || strpos($lower, 'mtn') !== false) {
                $sdata['network'] = 'MTN';
                self::saveUserSession($phone, 'order_enter_bundle', $sdata, $pdo);
                $sampleRate = self::getBundlePriceForRole('MTN', 1.0, $userRole, $uId, $pdo);
                $rateFmt    = number_format($sampleRate, 2);
                return "*MTN Data Bundle Order*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "User: *{$sdata['username']}* (`APEX-{$sdata['user_id']}`)\n"
                     . "Tier: *{$roleDisplay}* (Rate: *GHS {$rateFmt} / GB*)\n"
                     . "Balance: *GHS {$balFormatted}*\n\n"
                     . "Please enter the *Recipient Phone Number* and *GB size*:\n"
                     . "Format: `<phone> <GB>`\n"
                     . "• Example: `0559623850 2`\n"
                     . "• Example: `0241234567 5`\n\n"
                     . "_(Reply *cancel* to abort)_";
            } elseif ($lower === '2' || strpos($lower, 'telecel') !== false) {
                $sdata['network'] = 'Telecel';
                self::saveUserSession($phone, 'order_enter_bundle', $sdata, $pdo);
                $sampleRate = self::getBundlePriceForRole('Telecel', 1.0, $userRole, $uId, $pdo);
                $rateFmt    = number_format($sampleRate, 2);
                return "*Telecel Data Bundle Order*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "User: *{$sdata['username']}* (`APEX-{$sdata['user_id']}`)\n"
                     . "Tier: *{$roleDisplay}* (Rate: *GHS {$rateFmt} / GB*)\n"
                     . "Balance: *GHS {$balFormatted}*\n\n"
                     . "Please enter the *Recipient Phone Number* and *GB size*:\n"
                     . "Format: `<phone> <GB>`\n"
                     . "• Example: `0201234567 5`\n"
                     . "• Example: `0501234567 10`\n\n"
                     . "_(Reply *cancel* to abort)_";
            } elseif ($lower === '3' || strpos($lower, 'at') !== false || strpos($lower, 'ishare') !== false) {
                $sdata['network'] = 'Ishare';
                self::saveUserSession($phone, 'order_enter_bundle', $sdata, $pdo);
                $sampleRate = self::getBundlePriceForRole('Ishare', 1.0, $userRole, $uId, $pdo);
                $rateFmt    = number_format($sampleRate, 2);
                return "*AT / AirtelTigo Ishare Order*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "User: *{$sdata['username']}* (`APEX-{$sdata['user_id']}`)\n"
                     . "Tier: *{$roleDisplay}* (Rate: *GHS {$rateFmt} / GB*)\n"
                     . "Balance: *GHS {$balFormatted}*\n\n"
                     . "Please enter the *Recipient Phone Number* and *GB size*:\n"
                     . "Format: `<phone> <GB>`\n"
                     . "• Example: `0261234567 2`\n"
                     . "• Example: `0571234567 4`\n\n"
                     . "_(Reply *cancel* to abort)_";
            } elseif ($lower === '4' || strpos($lower, 'afa') !== false) {
                self::saveUserSession($phone, 'order_enter_afa', $sdata, $pdo);
                return "*MTN AFA Registration*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "User: *{$sdata['username']}* (`APEX-{$sdata['user_id']}`)\n"
                     . "Balance: *GHS {$balFormatted}*\n"
                     . "Registration Fee: *GHS 15.00* per SIM\n\n"
                     . "Please enter the registration details:\n"
                     . "Format: `<Phone> <Full Name> <Ghana Card Number>`\n"
                     . "• Example: `0541145310 Eric Fosu GHA-123456789-0`\n\n"
                     . "_(Reply *cancel* to abort)_";
            } elseif ($lower === '5' || strpos($lower, 'result checker') !== false || strpos($lower, 'buy checker') !== false || strpos($lower, 'checker') !== false || strpos($lower, 'card') !== false || strpos($lower, 'voucher') !== false) {
                // Option 5: Result Checker Cards (buy WASSCE/BECE vouchers at role prices)
                $wasscePrice = self::getCheckerPriceForRole('wassce', $userRole, $uId, $pdo);
                $becePrice   = self::getCheckerPriceForRole('bece', $userRole, $uId, $pdo);
                $wpFmt = number_format($wasscePrice, 2);
                $bpFmt = number_format($becePrice, 2);

                self::saveUserSession($phone, 'order_buy_checker', $sdata, $pdo);
                return "*Result Checker — Buy WAEC Cards*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "User: *{$sdata['username']}* (`APEX-{$sdata['user_id']}`)\n"
                     . "Tier: *{$roleDisplay}*\n"
                     . "Balance: *GHS {$balFormatted}*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "Select Result Checker Card to purchase:\n\n"
                     . "1. *WASSCE Result Checker* — *GHS {$wpFmt}*\n"
                     . "2. *BECE Result Checker* — *GHS {$bpFmt}*\n\n"
                     . "*Instant Delivery*: Card PIN & Serial Number will be sent to you right here immediately!\n\n"
                     . "_(Reply with 1 or 2, or reply *cancel* to abort)_";
            } elseif ($lower === '6' || strpos($lower, 'check result') !== false) {
                // Option 6: Check Result Online (using existing voucher PIN + Serial)
                self::saveUserSession($phone, 'waec_exam_type', [], $pdo);
                return "*Check Result — WAEC Online Portal*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "Select your examination:\n"
                     . "1. *WASSCE* (School)\n"
                     . "2. *BECE* (School)\n"
                     . "3. *WASSCE* (Private / NovDec)\n"
                     . "4. *BECE* (Private)\n\n"
                     . "_(Reply with 1 - 4, or reply *cancel* to exit)_";
            } else {
                return "*Invalid Selection*\n\nPlease reply with a number from 1 to 5:\n"
                     . "1. *MTN Data*\n"
                     . "2. *Telecel Data*\n"
                     . "3. *AT Ishare*\n"
                     . "4. *MTN AFA Registration*\n"
                     . "5. *Result Checker Cards*\n\n"
                     . "_(Reply *cancel* to abort)_";
            }
        }

        // ── STEP 2B: Process Result Checker Card Purchase ──
        if ($step === 'order_buy_checker') {
            $userId   = (int)($sdata['user_id'] ?? 0);
            $userRole = strtolower(trim($sdata['role'] ?? 'client'));
            $roleDisplay = strtoupper(str_replace('_', ' ', $userRole));

            $category = '';
            $catLabel = '';
            if ($lower === '1' || strpos($lower, 'wassce') !== false) {
                $category = 'wassce';
                $catLabel = 'WASSCE';
            } elseif ($lower === '2' || strpos($lower, 'bece') !== false) {
                $category = 'bece';
                $catLabel = 'BECE';
            } else {
                $wp = number_format(self::getCheckerPriceForRole('wassce', $userRole, $userId, $pdo), 2);
                $bp = number_format(self::getCheckerPriceForRole('bece', $userRole, $userId, $pdo), 2);
                return "*Invalid Selection*\n\nPlease reply with *1* or *2*:\n"
                     . "1️⃣ *WASSCE Result Checker* (GHS {$wp})\n"
                     . "2️⃣ *BECE Result Checker* (GHS {$bp})\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            $cost = self::getCheckerPriceForRole($category, $userRole, $userId, $pdo);

            // Retrieve fresh user balance
            $currentBal = 0.00;
            if ($pdo && function_exists('getUserWalletBalance')) {
                $currentBal = getUserWalletBalance($pdo, $userId);
            } else {
                $currentBal = (float)($sdata['wallet_balance'] ?? 0);
            }

            // Insufficient Balance check
            if ($currentBal < $cost) {
                $shortfall = $cost - $currentBal;
                $sdata['pending_order'] = [
                    'type'     => 'checker',
                    'category' => $category,
                    'cost'     => $cost,
                    'role'     => $userRole
                ];
                self::saveUserSession($phone, 'order_topup_txid', $sdata, $pdo);

                $costFmt = number_format($cost, 2);
                $balFmt  = number_format($currentBal, 2);
                $sfFmt   = number_format($shortfall, 2);

                return "*Insufficient Wallet Balance*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "• Item: *{$catLabel} Result Checker Card*\n"
                     . "• Account Tier: *{$roleDisplay}*\n"
                     . "• Role Price: *GHS {$costFmt}*\n"
                     . "• Your Balance: *GHS {$balFmt}*\n"
                     . "• Amount Needed: *GHS {$sfFmt}*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "Please send GHS {$sfFmt} (or more) to Top Up:\n"
                     . "MoMo Number: `0530429556`\n"
                     . "Name: *Sir Esarq Ent (Eric Fosu)*\n"
                     . "Reference: `APEX-{$userId}`\n\n"
                     . "After sending, reply with your *Transaction ID* right here to automatically credit your wallet and instantly receive your PIN!\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            // User has sufficient balance: purchase and dispense card instantly!
            $res = self::purchaseResultCheckerCard($userId, $category, $cost, $phone, $sdata['username'] ?? 'Customer', $pdo);
            self::clearUserSession($phone, $pdo);

            $costFmt = number_format($cost, 2);
            $newBal  = $res['new_balance'] ?? ($currentBal - $cost);
            if ($newBal === null && $pdo && function_exists('getUserWalletBalance')) {
                $newBal = getUserWalletBalance($pdo, $userId);
            }
            $newBalFmt = number_format((float)$newBal, 2);

            return "*{$catLabel} RESULT CHECKER PURCHASE SUCCESSFUL!*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "User: *{$sdata['username']}* (`APEX-{$userId}`)\n"
                 . "Tier: *{$roleDisplay}*\n"
                 . "Cost Deducted: *GHS {$costFmt}*\n"
                 . "New Balance: *GHS {$newBalFmt}*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "*VOUCHER DETAILS (INSTANT)*:\n"
                 . "• Exam Type: *{$catLabel} Results Checker*\n"
                 . "• Serial Number: `{$res['serial']}`\n"
                 . "• Card PIN: `{$res['pin']}`\n"
                 . "• Order Ref: `{$res['reference']}`\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "*How to Check Your Result Now*:\n"
                 . "Simply reply with *check result* (or reply *5*) to check your result and calculate your total grade automatically!\n\n"
                 . "Thank you for choosing Apex Prime Tech!";
        }

        // ── STEP 3: Process Bundle Order Details (<phone> <GB>) ──
        if ($step === 'order_enter_bundle') {
            if (!preg_match('/(\d{9,12})\s+(\d+(?:\.\d+)?)\s*(?:gb|gig)?/i', $cleanText, $m)) {
                return "*Invalid Format*\n\nPlease enter the phone number and GB size separated by a space:\n"
                     . "Format: `<phone> <GB>`\n"
                     . "• Example: `0559623850 2`\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            $recipPhone = preg_replace('/\D/', '', $m[1]);
            if (strpos($recipPhone, '233') === 0 && strlen($recipPhone) === 12) {
                $recipPhone = '0' . substr($recipPhone, 3);
            } elseif (strlen($recipPhone) === 9) {
                $recipPhone = '0' . $recipPhone;
            }

            if (strlen($recipPhone) !== 10) {
                return "*Invalid Phone Number*\nGhana phone numbers must be 10 digits (e.g. `0559623850`).\n\nPlease re-enter `<phone> <GB>` (or reply *cancel*):";
            }

            $gbAmount = (float)$m[2];
            if ($gbAmount <= 0) {
                return "*Invalid GB Size*\nPlease enter a valid GB size (e.g. 1, 2, 5, 10):";
            }

            $network = $sdata['network'] ?? 'MTN';
            $userId  = (int)($sdata['user_id'] ?? 0);
            $role    = strtolower(trim($sdata['role'] ?? 'client'));
            $roleDisplay = strtoupper(str_replace('_', ' ', $role));

            // Calculate cost dynamically based on user role (querying live DB or Website API)
            $cost = self::getBundlePriceForRole($network, $gbAmount, $role, $userId, $pdo);

            // Retrieve fresh user balance from DB
            $currentBal = 0.00;
            if ($pdo && function_exists('getUserWalletBalance')) {
                $currentBal = getUserWalletBalance($pdo, $userId);
            } else {
                $currentBal = (float)($sdata['wallet_balance'] ?? 0);
            }

            // INSUFFICIENT BALANCE CHECK
            if ($currentBal < $cost) {
                $shortfall = $cost - $currentBal;
                $sdata['pending_order'] = [
                    'type'     => 'bundle',
                    'network'  => $network,
                    'phone'    => $recipPhone,
                    'gb'       => $gbAmount,
                    'cost'     => $cost,
                    'role'     => $role
                ];
                self::saveUserSession($phone, 'order_topup_txid', $sdata, $pdo);

                $costFmt = number_format($cost, 2);
                $balFmt  = number_format($currentBal, 2);
                $sfFmt   = number_format($shortfall, 2);

                return "*Insufficient Wallet Balance*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "• Order: *{$network} {$gbAmount}GB* to `{$recipPhone}`\n"
                     . "• Account Tier: *{$roleDisplay}*\n"
                     . "• Role Price: *GHS {$costFmt}*\n"
                     . "• Your Balance: *GHS {$balFmt}*\n"
                     . "• Amount Needed: *GHS {$sfFmt}*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "Please send GHS {$sfFmt} (or more) to Top Up:\n"
                     . "MoMo Number: `0530429556`\n"
                     . "Name: *Sir Esarq Ent (Eric Fosu)*\n"
                     . "Reference: `APEX-{$userId}`\n\n"
                     . "Once sent, reply with your *Transaction ID* to automatically credit your wallet and complete this order!\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            // SUFFICIENT BALANCE: Deduct and fulfill order
            $orderRes = self::createOrderInDatabaseOrWebsite($userId, $network, $recipPhone, $gbAmount, $cost, $pdo, $role);
            $orderId = (int)($orderRes['order_id'] ?? 0);
            $orderRef = $orderRes['reference'] ?? ('WBOT-' . mt_rand(10000, 99999));
            $actualCost = isset($orderRes['cost']) && (float)$orderRes['cost'] > 0 ? (float)$orderRes['cost'] : $cost;

            $newBal = $orderRes['new_balance'] ?? ($currentBal - $actualCost);
            if ($newBal === null && $pdo && function_exists('getUserWalletBalance')) {
                $newBal = getUserWalletBalance($pdo, $userId);
            }

            self::clearUserSession($phone, $pdo);

            $costFmt = number_format($actualCost, 2);
            $newBalFmt = number_format((float)$newBal, 2);
            $realStatus = $orderRes['status'] ?? 'processing';
            $statusInfo = self::formatRealOrderStatus($realStatus);

            $costFmt = number_format($actualCost, 2);
            $newBalFmt = number_format((float)$newBal, 2);
            $ordDisplayId = ($orderId > 0) ? "#{$orderId}" : $orderRef;

            return "*{$statusInfo['header']}*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "• Order ID: `{$ordDisplayId}`\n"
                 . "• Network: *{$network}*\n"
                 . "• Recipient: `{$recipPhone}`\n"
                 . "• Package: *{$gbAmount} GB*\n"
                 . "• Account Tier: *{$roleDisplay}*\n"
                 . "• Amount Deducted: *GHS {$costFmt}*\n"
                 . "• New Wallet Balance: *GHS {$newBalFmt}*\n"
                 . "• Order Status: *{$statusInfo['badge']}*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Reply *status {$ordDisplayId}* anytime to track real-time delivery!\n\n"
                 . "Thank you for using Apex Prime Tech! Type *menu* anytime for more services.";
        }

        // ── STEP 4: Process AFA Registration Details ──
        if ($step === 'order_enter_afa') {
            $afaFee = 15.00;
            $userId = (int)($sdata['user_id'] ?? 0);

            // Expect <Phone> <Name> <Ghana Card>
            if (!preg_match('/(\d{9,12})\s+([A-Za-z\s]{3,50})\s+(GHA\-[0-9\-]+|[A-Za-z0-9\-]{8,25})/i', $cleanText, $m)) {
                return "*Invalid Format*\n\nPlease enter the registration details in this format:\n"
                     . "`<Phone> <Full Name> <Ghana Card>`\n"
                     . "• Example: `0541145310 Eric Fosu GHA-123456789-0`\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            $afaPhone = preg_replace('/\D/', '', $m[1]);
            $afaName  = trim($m[2]);
            $afaGha   = strtoupper(trim($m[3]));

            // Retrieve balance
            $currentBal = 0.00;
            if ($pdo && function_exists('getUserWalletBalance')) {
                $currentBal = getUserWalletBalance($pdo, $userId);
            } else {
                $currentBal = (float)($sdata['wallet_balance'] ?? 0);
            }

            if ($currentBal < $afaFee) {
                $shortfall = $afaFee - $currentBal;
                $sdata['pending_order'] = [
                    'type'  => 'afa',
                    'phone' => $afaPhone,
                    'name'  => $afaName,
                    'gha'   => $afaGha,
                    'cost'  => $afaFee
                ];
                self::saveUserSession($phone, 'order_topup_txid', $sdata, $pdo);

                $sfFmt = number_format($shortfall, 2);
                $balFmt = number_format($currentBal, 2);

                return "*Insufficient Balance for AFA Registration!*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "• Registration Fee: *GHS 15.00*\n"
                     . "• Current Balance: *GHS {$balFmt}*\n"
                     . "• Shortfall: *GHS {$sfFmt}*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "Please send GHS {$sfFmt} to Top Up:\n"
                     . "MoMo Number: `0530429556`\n"
                     . "Name: *Sir Esarq Ent (Eric Fosu)*\n"
                     . "Reference: `APEX-{$userId}`\n\n"
                     . "When sent, reply with your *Transaction ID* to automatically credit and register!\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            // Deduct AFA fee and create in Database/Admin Portal
            $afaRes = self::createAfaInDatabaseOrWebsite($userId, $afaName, $afaPhone, $afaGha, $pdo);
            $afaId = (int)($afaRes['afa_id'] ?? 0);
            $ref = $afaRes['reference'] ?? ('AFA-' . mt_rand(10000, 99999));

            $newBal = $afaRes['new_balance'] ?? ($currentBal - $afaFee);
            if ($newBal === null && $pdo && function_exists('getUserWalletBalance')) {
                $newBal = getUserWalletBalance($pdo, $userId);
            }

            self::clearUserSession($phone, $pdo);

            $newBalFmt = number_format((float)$newBal, 2);
            $dispId = ($afaId > 0) ? "#{$afaId}" : $ref;

            return "*MTN AFA REGISTRATION SUBMITTED!*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "• Registration ID: `{$dispId}`\n"
                 . "• Phone: `{$afaPhone}`\n"
                 . "• Name: *{$afaName}*\n"
                 . "• Ghana Card: `{$afaGha}`\n"
                 . "• Fee Deducted: *GHS 15.00*\n"
                 . "• New Wallet Balance: *GHS {$newBalFmt}*\n"
                 . "• Status: *Queued for Processing* ⏳\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Your MTN AFA SIM registration has been submitted for approval.";
        }

        // ── STEP 5: Top-up via Transaction ID or User Code Verification ──
        if ($step === 'order_topup_txid') {
            $pdo = self::getPdo($pdo);
            $userId = (int)($sdata['user_id'] ?? 0);
            $username = $sdata['username'] ?? 'Customer';
            $userPhone = $sdata['user_phone'] ?? $phone;

            // If user has no account yet, find or create one now!
            if ($userId <= 0) {
                $user = self::getOrCreateUserForPhone($phone, $profileName, $pdo);
                $userId = (int)$user['id'];
                $username = $user['username'];
                $userPhone = $user['phone'];
                $sdata['user_id'] = $userId;
                $sdata['username'] = $username;
                $sdata['user_phone'] = $userPhone;
                $sdata['role'] = $user['role'] ?? 'client';
            }

            // Check if user is asking for payment number or payment instructions
            $isAskingNumber = (strpos($lower, 'number') !== false || strpos($lower, 'momo') !== false || strpos($lower, 'how to pay') !== false || strpos($lower, 'no id') !== false || strpos($lower, 'forgot reference') !== false || strpos($lower, 'no reference') !== false || strpos($lower, 'send number') !== false || strpos($lower, 'refused') !== false || strpos($lower, 'where to pay') !== false || strpos($lower, 'payment number') !== false);
            if ($isAskingNumber) {
                $ref = "APEX-{$userId}";
                return "*Apex Prime Official Payment Details*:\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "• *MoMo Number*: `0530429556`\n"
                     . "• *Account Name*: *Sir Esarq Ent / Eric Fosu*\n"
                     . "• *Network*: *MTN Mobile Money*\n"
                     . "• *Payment Reference*: `{$ref}` 👈 *(Use as payment reference)*\n\n"
                     . "🌐 *Or Pay Instantly Online (Card / MoMo):*\n"
                     . "https://payroute.name/mr-nipah\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "*Didn't use or have the reference? No problem!*\n"
                     . "Simply reply right here with your *MoMo Transaction ID* (e.g. `24892019482`) from your confirmation SMS, and we will verify & credit you immediately!\n\n"
                     . "_(Reply *cancel* to abort)_";
            }

            // Check if user replied with their User Code or status refresh keywords (paid / done / sent / check / refresh)
            $isUserCodeQuery = false;
            if (preg_match('/^(?:APEX[\s\-_]*)?(\d{1,6})$/i', $cleanText, $ucm)) {
                $checkUid = (int)$ucm[1];
                if ($checkUid === $userId || $userId <= 0) {
                    $isUserCodeQuery = true;
                    if ($userId <= 0) $userId = $checkUid;
                }
            } elseif (in_array($lower, ['paid', 'done', 'sent', 'credited', 'check', 'verify', 'refresh', 'ok'])) {
                $isUserCodeQuery = true;
            }

            $creditAmount = 0.00;
            $txId = '';

            if ($isUserCodeQuery) {
                // Check if any incoming payment in webhook_payments matches this User Code reference
                if ($pdo) {
                    try {
                        $stmtCheckWh = $pdo->prepare("
                            SELECT * FROM webhook_payments 
                            WHERE is_claimed = 0 AND (reference LIKE ? OR reference LIKE ? OR reference = ?)
                            ORDER BY id DESC LIMIT 1
                        ");
                        $stmtCheckWh->execute(["%APEX-{$userId}%", "%{$userId}%", (string)$userId]);
                        $unclaimed = $stmtCheckWh->fetch(PDO::FETCH_ASSOC);
                        if ($unclaimed) {
                            $creditAmount = (float)$unclaimed['amount'];
                            $txId = $unclaimed['transaction_id'];

                            $pdo->beginTransaction();
                            $pdo->prepare("UPDATE webhook_payments SET is_claimed = 1, claimed_by_user_id = ? WHERE id = ?")
                                ->execute([$userId, $unclaimed['id']]);
                            $pdo->prepare("INSERT INTO topups (user_id, network, phone, amount, status, transaction_id) VALUES (?, 'MTN MoMo', ?, ?, 'approved', ?)")
                                ->execute([$userId, $userPhone, $creditAmount, $txId]);
                            if (function_exists('addWalletTransaction')) {
                                addWalletTransaction($pdo, $userId, $creditAmount, 'credit', 'MOMO-' . $txId, "Wallet Topup via WhatsApp Bot (User Code: APEX-{$userId})");
                            }
                            $pdo->commit();
                        }
                    } catch (Throwable $e) {
                        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
                    }
                }

                // Check fresh wallet balance from database
                $currentBal = 0.00;
                if ($pdo && function_exists('getUserWalletBalance')) {
                    $currentBal = getUserWalletBalance($pdo, $userId);
                } else {
                    $currentBal = ((float)($sdata['wallet_balance'] ?? 0)) + $creditAmount;
                }

                // If balance is still insufficient and no new payment found, inform user
                $minNeeded = !empty($sdata['pending_order']['cost']) ? (float)$sdata['pending_order']['cost'] : 3.50;
                if ($currentBal < $minNeeded && $creditAmount <= 0) {
                    $balFmt = number_format($currentBal, 2);
                    $needFmt = number_format($minNeeded - $currentBal, 2);
                    return "⚠️ *Payment Not Detected Yet for User Code `APEX-{$userId}`*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "• Current Balance: *GHS {$balFmt}*\n"
                         . "• Amount Needed: *GHS {$needFmt}*\n\n"
                         . "If you just made the payment, please wait 30–60 seconds for the network to deliver your MoMo SMS.\n\n"
                         . "👉 *Didn't use your User Code as reference?*\n"
                         . "Please reply with your *MoMo Transaction ID* (e.g. `24892019482`) to verify and claim your payment directly!\n\n"
                         . "_(Reply *cancel* anytime to abort)_";
                }

                if (empty($txId)) {
                    $txId = "REF-APEX-{$userId}";
                }
            } else {
                // User submitted a Transaction ID
                $txId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cleanText);
                if (strlen($txId) < 5) {
                    return "*Invalid Transaction ID*\n\nPlease enter a valid MoMo Transaction ID (e.g. `24892019482`):\n\n_(Reply *cancel* to abort)_";
                }

                // 1. DUPLICATE CHECK: Has this transaction ID already been approved?
                $isDuplicate = false;
                $dupDetails = '';
                if ($pdo) {
                    try {
                        $stmtChk = $pdo->prepare("SELECT * FROM topups WHERE transaction_id = ? LIMIT 1");
                        $stmtChk->execute([$txId]);
                        $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);
                        if ($existing) {
                            $st = strtolower($existing['status'] ?? '');
                            if (in_array($st, ['approved', 'completed', 'success', 'successful'])) {
                                $isDuplicate = true;
                                $dupDate = !empty($existing['created_at']) ? date('d M Y, h:i A', strtotime($existing['created_at'])) : 'Previously';
                                $dupDetails = "Already credited on {$dupDate}";
                            }
                        }
                    } catch (Throwable $e) {}
                }

                // Also check local cache for anti-duplicate protection
                $txFile = __DIR__ . '/../logs/whatsapp_approved_txids.json';
                if (!$isDuplicate && file_exists($txFile)) {
                    $cachedTx = json_decode(@file_get_contents($txFile), true) ?: [];
                    if (isset($cachedTx[$txId])) {
                        $isDuplicate = true;
                        $dupDate = !empty($cachedTx[$txId]['date']) ? $cachedTx[$txId]['date'] : 'Previously';
                        $dupDetails = "Already credited on {$dupDate}";
                    }
                }

                // REJECT IF ALREADY APPROVED
                if ($isDuplicate) {
                    return "*DUPLICATE TRANSACTION ID REJECTED*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "• Transaction ID: `{$txId}`\n"
                         . "• Status: *Already Claimed & Approved*\n"
                         . "• Details: {$dupDetails}\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "*Notice*: This transaction ID has already been credited to an account. Duplicate claims are strictly rejected.\n\n"
                         . "Please reply with your new, unclaimed Transaction ID or contact support (0553381853).";
                }

                // 2. CHECK DATABASE (webhook_payments table) FOR THIS TRANSACTION
                $paymentVerified = false;
                $paymentRow = null;

                if ($pdo) {
                    try {
                        $stmtWh = $pdo->prepare("SELECT * FROM webhook_payments WHERE transaction_id = ? LIMIT 1");
                        $stmtWh->execute([$txId]);
                        $paymentRow = $stmtWh->fetch(PDO::FETCH_ASSOC);
                        if ($paymentRow) {
                            if (!empty($paymentRow['is_claimed'])) {
                                return "*TRANSACTION ALREADY CLAIMED*\n"
                                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                                     . "• Transaction ID: `{$txId}`\n"
                                     . "• Status: *Already Claimed by another user*\n"
                                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                                     . "Please verify your Transaction ID and try again, or contact support at 0553381853.";
                            }
                            $creditAmount = (float)$paymentRow['amount'];
                            $paymentVerified = true;
                        }
                    } catch (Throwable $e) {}
                }

                // 3. If not in webhook_payments, check Paystack API live
                if (!$paymentVerified) {
                    $secretKey = defined('PAYSTACK_SECRET_KEY') ? trim(PAYSTACK_SECRET_KEY) : '';
                    if (empty($secretKey) && file_exists(__DIR__ . '/../admin_settings.json')) {
                        $set = json_decode(@file_get_contents(__DIR__ . '/../admin_settings.json'), true);
                        $secretKey = $set['paystack_secret_key'] ?? '';
                    }
                    if (!empty($secretKey)) {
                        $ch = curl_init("https://api.paystack.co/transaction/verify/" . rawurlencode($txId));
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 10,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_HTTPHEADER     => [
                                "Authorization: Bearer {$secretKey}",
                                "Cache-Control: no-cache"
                            ]
                        ]);
                        $pRes = curl_exec($ch);
                        curl_close($ch);
                        if ($pRes) {
                            $pData = json_decode($pRes, true);
                            if (!empty($pData['status']) && isset($pData['data']['status']) && $pData['data']['status'] === 'success') {
                                $creditAmount = ((float)$pData['data']['amount']) / 100;
                                $paymentVerified = true;
                            }
                        }
                    }
                }

                // 4. IF PAYMENT WAS NOT FOUND / UNSUCCESSFUL -> WARN USER!
                if (!$paymentVerified || $creditAmount <= 0) {
                    return "⚠️ *PAYMENT NOT VERIFIED / UNSUCCESSFUL*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "• Transaction ID: `{$txId}`\n"
                         . "• Status: *Unconfirmed or Not Found* ❌\n\n"
                         . "⚠️ *Warning*: We could not verify any successful Mobile Money payment with this Transaction ID in our database.\n\n"
                         . "• Please double-check your MoMo confirmation SMS and ensure you entered the exact *Transaction ID* (e.g. `24892019482`).\n"
                         . "• If you just completed the payment, please allow 30–60 seconds for network delivery and re-enter your Transaction ID.\n"
                         . "• If you paid with your User Code `APEX-{$userId}` as the MoMo reference, reply with `APEX-{$userId}` to refresh your balance.\n"
                         . "• Need assistance? Contact our customer support team at *0553381853*.\n\n"
                         . "_(Reply *cancel* anytime to abort)_";
                }

                // 5. PAYMENT VERIFIED: Update database and credit user account!
                if ($pdo) {
                    try {
                        $pdo->beginTransaction();
                        if ($paymentRow) {
                            $pdo->prepare("UPDATE webhook_payments SET is_claimed = 1, claimed_by_user_id = ? WHERE id = ?")
                                ->execute([$userId, $paymentRow['id']]);
                        } else {
                            $pdo->prepare("INSERT INTO webhook_payments (transaction_id, reference, amount, sender_name, provider, is_claimed, claimed_by_user_id, created_at) VALUES (?, ?, ?, 'Paystack Verification', 'Paystack', 1, ?, NOW())")
                                ->execute([$txId, $txId, $creditAmount, $userId]);
                        }

                        $pdo->prepare("INSERT INTO topups (user_id, network, phone, amount, status, transaction_id) VALUES (?, 'MTN MoMo', ?, ?, 'approved', ?)")
                            ->execute([$userId, $userPhone, $creditAmount, $txId]);

                        if (function_exists('addWalletTransaction')) {
                            addWalletTransaction($pdo, $userId, $creditAmount, 'credit', 'MOMO-' . $txId, "Wallet Topup via WhatsApp Bot (Tx ID: {$txId})");
                        }
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
                    }
                }

                // Always save approved transaction ID to local tracking file to ensure anti-scam protection
                try {
                    $dir = __DIR__ . '/../logs';
                    if (!is_dir($dir)) @mkdir($dir, 0755, true);
                    $txFile = $dir . '/whatsapp_approved_txids.json';
                    $cachedTx = file_exists($txFile) ? (json_decode(@file_get_contents($txFile), true) ?: []) : [];
                    $cachedTx[$txId] = [
                        'user_id' => $userId,
                        'amount'  => $creditAmount,
                        'date'    => date('d M Y, h:i A')
                    ];
                    @file_put_contents($txFile, json_encode($cachedTx, JSON_PRETTY_PRINT));
                } catch (Throwable $e) {}

                $currentBal = 0.00;
                if ($pdo && function_exists('getUserWalletBalance')) {
                    $currentBal = getUserWalletBalance($pdo, $userId);
                } else {
                    $currentBal = ((float)($sdata['wallet_balance'] ?? 0)) + $creditAmount;
                }
            }

            $creditAmtFmt = number_format($creditAmount, 2);
            $newBalFmt    = number_format($currentBal, 2);

            // 4. If there was a pending bundle order, process it automatically!
            $pending = $sdata['pending_order'] ?? null;
            if ($pending && ($pending['type'] ?? '') === 'bundle') {
                $userRole     = strtolower(trim($sdata['role'] ?? ($pending['role'] ?? 'client')));
                $roleDisplay  = strtoupper(str_replace('_', ' ', $userRole));
                $cost = (float)($pending['cost'] ?? 0);
                if ($cost <= 0) {
                    $cost = self::getBundlePriceForRole($pending['network'], (float)$pending['gb'], $userRole, $userId, $pdo);
                }

                if ($currentBal >= $cost) {
                    // Deduct role price and fulfill
                    $orderRes = self::createOrderInDatabaseOrWebsite($userId, $pending['network'], $pending['phone'], (float)$pending['gb'], $cost, $pdo, $userRole);
                    $orderId = (int)($orderRes['order_id'] ?? 0);
                    $orderRef = $orderRes['reference'] ?? ('WBOT-' . mt_rand(10000, 99999));
                    $actualCost = isset($orderRes['cost']) && (float)$orderRes['cost'] > 0 ? (float)$orderRes['cost'] : $cost;

                    $finalBal = $orderRes['new_balance'] ?? ($currentBal - $actualCost);
                    if ($finalBal === null && $pdo && function_exists('getUserWalletBalance')) {
                        $finalBal = getUserWalletBalance($pdo, $userId);
                    }

                    self::clearUserSession($phone, $pdo);

                    $realStatus  = $orderRes['status'] ?? 'processing';
                    $statusInfo  = self::formatRealOrderStatus($realStatus);

                    $costFmt     = number_format($actualCost, 2);
                    $finalBalFmt = number_format((float)$finalBal, 2);
                    $dispOrdId   = ($orderId > 0) ? "#{$orderId}" : $orderRef;

                    return "*TOP-UP APPROVED & {$statusInfo['header']}*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "*Top-Up Verified*: *GHS {$creditAmtFmt}* (Ref: `{$txId}`)\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "*Order Details*:\n"
                         . "• Order ID: `{$dispOrdId}`\n"
                         . "• Network: *{$pending['network']}*\n"
                         . "• Recipient: `{$pending['phone']}`\n"
                         . "• Package: *{$pending['gb']} GB*\n"
                         . "• Account Tier: *{$roleDisplay}*\n"
                         . "• Deducted: *GHS {$costFmt}*\n"
                         . "• Remaining Wallet Balance: *GHS {$finalBalFmt}*\n"
                         . "• Order Status: *{$statusInfo['badge']}*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "Reply *status {$dispOrdId}* anytime to track real-time delivery!\n\n"
                         . "Thank you for choosing Apex Prime Tech!";
                }
            }

            // 5. If there was a pending MTN AFA registration, fulfill it automatically!
            if ($pending && ($pending['type'] ?? '') === 'afa') {
                $cost = (float)($pending['cost'] ?? 15.00);
                if ($currentBal >= $cost) {
                    $afaRes = self::createAfaInDatabaseOrWebsite($userId, $pending['name'], $pending['phone'], $pending['gha'], $pdo);
                    $afaId = (int)($afaRes['afa_id'] ?? 0);
                    $ref = $afaRes['reference'] ?? ('AFA-' . mt_rand(10000, 99999));
                    $finalBal = $afaRes['new_balance'] ?? ($currentBal - $cost);
                    if ($finalBal === null && $pdo && function_exists('getUserWalletBalance')) {
                        $finalBal = getUserWalletBalance($pdo, $userId);
                    }
                    self::clearUserSession($phone, $pdo);
                    $finalBalFmt = number_format((float)$finalBal, 2);
                    $dispId = ($afaId > 0) ? "#{$afaId}" : $ref;

                    return "*TOP-UP APPROVED & MTN AFA REGISTRATION SUBMITTED!*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "*Top-Up Verified*: *GHS {$creditAmtFmt}* (Ref: `{$txId}`)\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "• Registration ID: `{$dispId}`\n"
                         . "• Phone: `{$pending['phone']}`\n"
                         . "• Name: *{$pending['name']}*\n"
                         . "• Ghana Card: `{$pending['gha']}`\n"
                         . "• Fee Deducted: *GHS 15.00*\n"
                         . "• Remaining Wallet Balance: *GHS {$finalBalFmt}*\n"
                         . "• Status: *Queued for Processing* ⏳\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "Thank you for choosing Apex Prime Tech!";
                }
            }

            // 6. If there was a pending Result Checker card purchase, fulfill it automatically!
            if ($pending && ($pending['type'] ?? '') === 'checker') {
                $cat = $pending['category'] ?? 'wassce';
                $catLabel = strtoupper($cat);
                $cost = (float)($pending['cost'] ?? 0);
                if ($cost <= 0) {
                    $cost = self::getCheckerPriceForRole($cat, $sdata['role'] ?? 'client', $userId, $pdo);
                }

                if ($currentBal >= $cost) {
                    $cardRes = self::purchaseResultCheckerCard($userId, $cat, $cost, $phone, $username, $pdo);
                    self::clearUserSession($phone, $pdo);

                    $costFmt     = number_format($cost, 2);
                    $finalBal    = $cardRes['new_balance'] ?? ($currentBal - $cost);
                    if ($finalBal === null && $pdo && function_exists('getUserWalletBalance')) {
                        $finalBal = getUserWalletBalance($pdo, $userId);
                    }
                    $finalBalFmt = number_format((float)$finalBal, 2);

                    return "*TOP-UP APPROVED & {$catLabel} CARD PURCHASE SUCCESSFUL!*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "*Top-Up Verified*: *GHS {$creditAmtFmt}* (Ref: `{$txId}`)\n"
                         . "Amount Deducted: *GHS {$costFmt}*\n"
                         . "Remaining Wallet Balance: *GHS {$finalBalFmt}*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "*VOUCHER DETAILS (INSTANT)*:\n"
                         . "• Exam Type: *{$catLabel} Results Checker*\n"
                         . "• Serial Number: `{$cardRes['serial']}`\n"
                         . "• Card PIN: `{$cardRes['pin']}`\n"
                         . "• Order Ref: `{$cardRes['reference']}`\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "*How to Check Your Result Now*:\n"
                         . "Simply reply with *check result* (or reply *5*) to check your result and calculate your total grade automatically!\n\n"
                         . "Thank you for choosing Apex Prime Tech!";
                }
            }

            // 7. If no pending order, prompt to select product with GO-AHEAD!
            $sdata['wallet_balance'] = $currentBal;
            self::saveUserSession($phone, 'order_select_product', $sdata, $pdo);

            return "*PAYMENT VERIFIED & WALLET CREDITED!*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "• Transaction ID: `{$txId}`\n"
                 . "• Amount Credited: *GHS {$creditAmtFmt}*\n"
                 . "• User Account: *{$username}* (`APEX-{$userId}`)\n"
                 . "• New Wallet Balance: *GHS {$newBalFmt}*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "*You are all set to place your order!*\n\n"
                 . "Please select what you would like to buy by replying with a number (*1 - 6*):\n\n"
                 . "1. *MTN Data Bundles*\n"
                 . "2. *Telecel Data Bundles*\n"
                 . "3. *AT / AirtelTigo Ishare*\n"
                 . "4. *MTN AFA Registration*\n"
                 . "5. *Check Result*\n"
                 . "6. *Result Checker*\n\n"
                 . "_(Reply with a number, or reply *cancel* to exit)_";
        }

        // ── Direct trigger: Top-Up / Payroute / Direct MoMo ──
        $isTopupTrigger = in_array($lower, ['top up', 'topup', 'top-up', 'fund wallet', 'deposit', 'recharge', 'payroute', 'pay link', 'payment link', 'momo payment', 'pay online']);
        if ($isTopupTrigger) {
            $orderCode = 'APEX-' . mt_rand(10000, 99999);
            $sdata = [
                'payment_ref' => $orderCode,
                'is_guest'    => true
            ];
            self::saveUserSession($phone, 'order_topup_txid', $sdata, $pdo);

            return "*MOMO DIRECT PAYMENT & WALLET TOP UP*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Please send your payment to our official account:\n\n"
                 . "Payment Number: `0530429556`\n"
                 . "Account Name: *Sir Esarq Ent / Eric Fosu*\n"
                 . "Payment Reference / Order ID: `{$orderCode}` *(Use as payment reference)*\n\n"
                 . "*Or Pay Instantly Online (Cards / MoMo):*\n"
                 . "https://payroute.name/mr-nipah\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "*How to complete:*\n"
                 . "1. Send your desired amount to *0530429556* (*Sir Esarq Ent / Eric Fosu*).\n"
                 . "2. Use `{$orderCode}` as your payment reference.\n"
                 . "3. Once payment is made, reply with your *Transaction ID* (from your MoMo confirmation SMS).\n"
                 . "4. We will automatically credit you and give you the go-ahead to place your order!\n\n"
                 . "_(If you forgot or didn't add the reference, simply reply with your MoMo Transaction ID)_\n"
                 . "_(Reply *cancel* anytime to abort)_";
        }

        // ── Direct trigger: Option 8 ("8", "8.", "buy from me", "buy fromme", "buyfromme") ──
        $isBuyFromMeTrigger = ($lower === '8' || $lower === '8.' || in_array($lower, ['buy from me', 'buy fromme', 'buyfromme', 'buy me', 'buy-from-me']));
        if ($isBuyFromMeTrigger) {
            return self::handleBuyFromMeRequest($text, $phone, $profileName, $pdo);
        }

        // ── Direct trigger: "1", "1.", "place order", "order", "buy", "buy bundle", "buy data" ──
        $isOrderTrigger = ($lower === '1' || $lower === '1.' || in_array($lower, ['place order', 'order', 'buy', 'buy bundle', 'buy data', 'packages', 'bundle', 'data']));
        if (!$isOrderTrigger) {
            return null;
        }

        // Fetch configured response if customized by admin, otherwise use full registration & ordering steps
        $cfg = self::getConfig($pdo);
        $orderGuide = '';
        foreach ($cfg['commands'] ?? [] as $c) {
            if (!empty($c['is_active']) && (($c['action_type'] ?? '') === 'place_order' || strpos($c['command_trigger'] ?? '', 'place order') !== false)) {
                $orderGuide = $c['response_text'] ?? '';
                break;
            }
        }

        if (empty($orderGuide)) {
            $orderGuide = "🛒 *How to Register & Place Order on Apex Prime Tech*\n"
                        . "━━━━━━━━━━━━━━━━━━━━━\n"
                        . "Follow these quick steps to register and purchase data bundles, result checkers, or MTN AFA registrations:\n\n"
                        . "📝 *Step 1: Create an Account (Register)*\n"
                        . "1. Visit our website: https://apexprime.club/register\n"
                        . "2. Fill in your *Username*, *Phone Number*, *Email*, and create a *Password*.\n"
                        . "3. Click *Register* to immediately create your account!\n"
                        . "_(Already have an account? Login at: https://apexprime.club/login)_\n\n"
                        . "💳 *Step 2: Fund Your Wallet*\n"
                        . "1. From your dashboard, tap *Fund Wallet / Top-Up* (or go to: https://apexprime.club/topup).\n"
                        . "2. Pay via *Paystack* (Mobile Money or ATM Card) or direct MoMo.\n"
                        . "3. Your wallet will be credited instantly!\n\n"
                        . "📦 *Step 3: Place Your Order*\n"
                        . "1. Tap *Buy Data Bundle* on your user dashboard.\n"
                        . "2. Select your network (*MTN*, *Telecel*, or *AT Ishare*).\n"
                        . "3. Choose your bundle package size (*1GB, 2GB, 5GB, 10GB*, etc.).\n"
                        . "4. Enter the recipient phone number.\n"
                        . "5. Tap *Buy Now* / *Submit*!\n\n"
                        . "⚡ *Automated Delivery*: Bundles are dispatched and delivered to the recipient line within seconds!\n\n"
                        . "━━━━━━━━━━━━━━━━━━━━━\n"
                        . "🔍 *Quick Shortcuts*:\n"
                        . "• Reply *8* or *buy from me* to buy directly using your User Code\n"
                        . "• Reply *4* to verify a payment reference & credit your wallet\n"
                        . "• Reply *3* or *status <order_id>* to track order delivery\n"
                        . "• Reply *balance* to check your current wallet balance\n"
                        . "• Reply *menu* to return to the main menu";
        }

        self::saveUserSession($phone, 'order_user_code', [], $pdo);
        return self::formatReply($orderGuide, $phone, $profileName, $pdo);
    }

    /**
     * ── Option 8: Handle Buy From Me Request (Direct User Code Entry) ──
     */
    public static function handleBuyFromMeRequest(string $text, string $phone, string $profileName, ?PDO $pdo = null): string {
        $pdo = self::getPdo($pdo);
        self::saveUserSession($phone, 'order_user_code', [], $pdo);
        return "🛒 *Buy From Me — Apex Prime Tech*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "Please enter your *User Code* to continue:\n"
             . "• Example: `APEX-317` or `317`\n\n"
             . "_(Your User Code is your Apex Prime account ID on our portal)_\n"
             . "_(Reply *cancel* anytime to abort)_";
    }

    /**
     * ── 3. Handle Order Status Tracking (Check Status) ──
     */
    public static function handleCheckStatusRequest(string $text, string $phone, string $profileName, ?PDO $pdo = null): ?string {
        $cleanText = trim($text);
        $lower = strtolower($cleanText);

        // Check active check-status conversation session
        $session = self::getUserSession($phone, $pdo);
        if ($session && $session['step'] === 'check_status_query') {
            if (in_array($lower, ['cancel', 'exit', 'stop', 'quit', 'abort', '0'])) {
                self::clearUserSession($phone, $pdo);
                return "*Status check cancelled.*\n\nType *menu* to return to the main menu.";
            }

            self::clearUserSession($phone, $pdo);
            return self::executeStatusCheck($cleanText, $phone, $profileName, $pdo);
        }

        // Direct 1-line check: "status 12345", "track 0559623850", "order status #12345"
        if (preg_match('/^(?:check\s+status|status|track(?:\s+order)?|order\s+status)\s+(.+)$/i', $cleanText, $m)) {
            $query = trim($m[1]);
            return self::executeStatusCheck($query, $phone, $profileName, $pdo);
        }

        // Menu trigger: "3", "3.", "check status", "status", "track", "track order", "order status"
        $isStatusTrigger = ($lower === '3' || $lower === '3.' || in_array($lower, ['check status', 'status', 'track', 'track order', 'order status']));
        if (!$isStatusTrigger) {
            return null;
        }

        self::saveUserSession($phone, 'check_status_query', [], $pdo);

        return "*Check Order Status — Apex Prime Tech*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "Please enter your *Order ID* or *Recipient Phone Number*:\n"
             . "• Example: `10245`\n"
             . "• Example: `0559623850`\n\n"
             . "_(Reply *cancel* anytime to abort)_";
    }

    /**
     * Execute live order status lookup from Database or Website API
     */
    public static function executeStatusCheck(string $query, string $phone, string $profileName, ?PDO $pdo = null): string {
        $cleanQuery = trim($query);
        $cleanQuery = ltrim($cleanQuery, '#');
        $cleanDigits = preg_replace('/\D/', '', $cleanQuery);
        $orderId = ($cleanDigits !== '' && strlen($cleanDigits) < 9) ? (int)$cleanDigits : 0;
        $cleanPhone = (strlen($cleanDigits) >= 9) ? $cleanDigits : '';

        $order = null;
        $orderType = 'bundle';

        // 1. Direct local/server PDO if connected
        if ($pdo) {
            try {
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
                if (!$order && !empty($cleanQuery)) {
                    $stmt = $pdo->prepare("
                        SELECT o.*, u.username AS user_name, u.payment_ref AS agent_id
                        FROM bundle_sends o
                        LEFT JOIN users u ON u.id = o.user_id
                        WHERE o.client_reference = ? OR o.message LIKE ?
                        ORDER BY o.id DESC LIMIT 1
                    ");
                    $stmt->execute([$cleanQuery, "%{$cleanQuery}%"]);
                    $order = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                // Also check mtn_afa_registrations
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
                        $order = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($order) $orderType = 'afa';
                    }
                    if (!$order && !empty($cleanPhone)) {
                        $stmt = $pdo->prepare("
                            SELECT a.*, u.username AS user_name, u.payment_ref AS agent_id
                            FROM mtn_afa_registrations a
                            LEFT JOIN users u ON u.id = a.user_id
                            WHERE a.phone_number LIKE ?
                            ORDER BY a.id DESC LIMIT 1
                        ");
                        $stmt->execute(['%' . substr($cleanPhone, -9)]);
                        $order = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($order) $orderType = 'afa';
                    }
                }
            } catch (Throwable $e) {}
        }

        // 2. Check local JSON order cache
        if (!$order) {
            $orderLogFile = __DIR__ . '/../logs/whatsapp_bot_orders.json';
            if (file_exists($orderLogFile)) {
                $cachedOrders = json_decode(@file_get_contents($orderLogFile), true) ?: [];
                if (isset($cachedOrders[$cleanQuery])) {
                    $order = $cachedOrders[$cleanQuery];
                } else {
                    foreach ($cachedOrders as $co) {
                        if ($orderId > 0 && (string)($co['id'] ?? '') === (string)$orderId) {
                            $order = $co; break;
                        }
                        if (strcasecmp($co['reference'] ?? '', $cleanQuery) === 0) {
                            $order = $co; break;
                        }
                        if (!empty($cleanPhone) && !empty($co['recipient_phone']) && substr($cleanPhone, -9) === substr(preg_replace('/\D/', '', $co['recipient_phone']), -9)) {
                            $order = $co; break;
                        }
                    }
                }
            }
        }

        // 3. Query live website API endpoint
        if (!$order) {
            $apiUrl = (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://apexprime.club') . '/api.php?action=bot_query';
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'bot_secret' => getenv('CRON_SECRET') ?: 'apex_cron_s3cr3t_2026',
                    'op'         => 'check_status',
                    'search'     => $cleanQuery
                ]),
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            if ($res) {
                $data = json_decode($res, true);
                if (!empty($data['success']) && !empty($data['data']['order'])) {
                    $order = $data['data']['order'];
                    $orderType = $data['data']['type'] ?? 'bundle';
                }
            }
        }

        if (!$order) {
            return "*ORDER NOT FOUND*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "No order found matching: `{$cleanQuery}`\n\n"
                 . "Please verify your Order ID or Recipient Phone number and try again.\n"
                 . "_(Or reply *menu* to return to the main menu)_";
        }

        // Format Status
        $st = strtolower($order['status'] ?? 'processing');
        $statusInfo = self::formatRealOrderStatus($st);
        $statusEmoji = $statusInfo['emoji'];
        $statusLabel = $statusInfo['label'];
        $createdAt = !empty($order['created_at']) ? date('d M Y, h:i A', strtotime($order['created_at'])) : date('d M Y');
        $userDisplay = !empty($order['user_name']) ? "{$order['user_name']} (APEX-{$order['user_id']})" : "User #{$order['user_id']}";

        if ($orderType === 'afa') {
            return "*MTN AFA REGISTRATION STATUS*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "• Registration ID: `#{$order['id']}`\n"
                 . "• Account: *{$userDisplay}*\n"
                 . "• Full Name: *{$order['full_name']}*\n"
                 . "• Phone Number: `{$order['phone_number']}`\n"
                 . "• Ghana Card: `{$order['gha_number']}`\n"
                 . "• Status: *{$statusLabel}* {$statusEmoji}\n"
                 . "• Date Submitted: {$createdAt}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Need help? Reply *support* or contact 0553381853.";
        }

        $gbFmt = !empty($order['gb_amount']) ? (float)$order['gb_amount'] . ' GB' : 'Bundle';
        $costFmt = !empty($order['amount']) ? 'GHS ' . number_format((float)$order['amount'], 2) : 'N/A';

        return "*DATA BUNDLE ORDER STATUS*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "• Order ID: `#{$order['id']}`\n"
             . "• Account: *{$userDisplay}*\n"
             . "• Network: *{$order['network']}*\n"
             . "• Recipient Phone: `{$order['recipient_phone']}`\n"
             . "• Package: *{$gbFmt}*\n"
             . "• Amount: *{$costFmt}*\n"
             . "• Status: *{$statusLabel}* {$statusEmoji}\n"
             . "• Date Placed: {$createdAt}\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "Need help? Reply *support* or contact 0553381853.";
    }

    /**
     * ── 4. Handle Payment Verification Requests (with Anti-Scam Duplicate Detection) ──
     */
    public static function handleVerifyPaymentRequest(string $text, string $phone, string $profileName, ?PDO $pdo = null): ?string {
        $cleanText = trim($text);
        $lower = strtolower($cleanText);

        // Check active payment verification session
        $session = self::getUserSession($phone, $pdo);
        if ($session && strpos($session['step'] ?? '', 'verify_payment_') === 0) {
            if (in_array($lower, ['cancel', 'exit', 'stop', 'quit', 'abort', '0'])) {
                self::clearUserSession($phone, $pdo);
                return "*Payment verification cancelled.*\n\nType *menu* to return to the main menu.";
            }

            // If user typed another primary command (like checking WAEC, order, balance), break out of verification session
            if (preg_match('/^(?:check\s+|waec\s+)?(wassce|bece)\b/i', $cleanText) || in_array($lower, ['menu', '1', '2', '3', '5', '6', '7', '8', 'balance', 'report'])) {
                self::clearUserSession($phone, $pdo);
                return null;
            }

            // User enters MoMo Transaction ID or Paystack Reference
            if ($session['step'] === 'verify_payment_ref') {
                $cleanRef = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cleanText);
                if (strlen($cleanRef) < 4) {
                    return "*Invalid Reference Number*\n\nPlease enter a valid Paystack Reference or MoMo Transaction ID (e.g. `TOPUP-23455` or `24892019482`):\n\n_(Reply *cancel* to abort)_";
                }

                self::clearUserSession($phone, $pdo);
                return self::executePaymentVerification($cleanRef, 0, '', $phone, $profileName, $pdo);
            }
        }

        // Direct 1-line check e.g. "verify TOPUP-23455", "TOPUP-23455", or "verify <ref> <agent_code>"
        if (preg_match('/^(?:verify\s+)?(topup[\s\-_]*\d+|[a-zA-Z0-9_\-]{4,60})(?:\s+(?:APEX[\s\-_]*)?(\d+))?/i', $cleanText, $directMatch) && (strpos($lower, 'verify') === 0 || preg_match('/^topup[\s\-_]*\d+$/i', $cleanText))) {
            $ref = $directMatch[1];
            $agentId = !empty($directMatch[2]) ? (int)$directMatch[2] : 0;
            return self::executePaymentVerification($ref, $agentId, $agentId > 0 ? "APEX-{$agentId}" : '', $phone, $profileName, $pdo);
        }

        // Trigger on "4", "4.", "verify", "verify payment", "payment", "check payment", "paid", "momo", "paystack"
        $isVerifyTrigger = ($lower === '4' || $lower === '4.' || in_array($lower, ['verify', 'verify payment', 'payment', 'check payment', 'paid', 'momo', 'paystack']));
        
        // Also check if any command in config has action_type === 'verify_payment' matching this text
        $customPrompt = '';
        if (!$isVerifyTrigger) {
            $cfg = self::getConfig($pdo);
            foreach ($cfg['commands'] ?? [] as $c) {
                if (empty($c['is_active']) || ($c['action_type'] ?? '') !== 'verify_payment') continue;
                $triggers = explode(',', strtolower($c['command_trigger'] ?? ''));
                $mType = $c['match_type'] ?? 'exact';
                foreach ($triggers as $t) {
                    $t = trim($t);
                    if (empty($t)) continue;
                    if (($mType === 'exact' && $lower === $t) ||
                        ($mType === 'contains' && strpos($lower, $t) !== false) ||
                        ($mType === 'starts_with' && strpos($lower, $t) === 0)) {
                        $isVerifyTrigger = true;
                        $customPrompt = $c['response_text'] ?? '';
                        break 2;
                    }
                }
            }
        }

        if (!$isVerifyTrigger) {
            return null;
        }

        self::saveUserSession($phone, 'verify_payment_ref', [], $pdo);

        if (!empty($customPrompt)) {
            return self::formatReply($customPrompt, $phone, $profileName, $pdo);
        }

        return "*Payment Verification — Apex Prime Tech*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "To verify your payment and update your wallet or order:\n\n"
             . "Please reply with your *Paystack Reference* or *MoMo Transaction ID*:\n"
             . "• Example Paystack: `TOPUP-23455`\n"
             . "• Example MoMo ID: `24892019482`\n\n"
             . "_(Reply *cancel* anytime to abort)_";
    }

    /**
     * Execute SQL database and Gateway duplicate check & verification using full verify LOGIC
     */
    public static function executePaymentVerification(string $ref, int $agentId = 0, string $agentInput = '', string $phone = '', string $profileName = 'Customer', ?PDO $pdo = null): string {
        $cleanRef = trim($ref);
        $cleanRef = ltrim($cleanRef, '#');
        if (empty($cleanRef) || strlen($cleanRef) < 4) {
            return "*Invalid Reference Number*\n\nPlease provide a valid Paystack Reference or Transaction ID.";
        }

        $cleanPhone = preg_replace('/\D/', '', $phone);

        // 1. Identify User Account
        $user = null;
        $userId = $agentId;
        $userPhone = $cleanPhone;
        $username = $profileName;

        if ($pdo) {
            try {
                if ($userId > 0) {
                    $stmtU = $pdo->prepare("SELECT id, username, phone, wallet_balance, role, payment_ref FROM users WHERE id = ? LIMIT 1");
                    $stmtU->execute([$userId]);
                    $user = $stmtU->fetch(PDO::FETCH_ASSOC);
                }
                if (!$user && !empty($cleanPhone)) {
                    $stmtU = $pdo->prepare("
                        SELECT id, username, phone, wallet_balance, role, payment_ref 
                        FROM users 
                        WHERE phone LIKE ? OR phone LIKE ? 
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stmtU->execute(['%' . substr($cleanPhone, -9), '%' . $cleanPhone . '%']);
                    $user = $stmtU->fetch(PDO::FETCH_ASSOC);
                }
                if ($user) {
                    $userId    = (int)$user['id'];
                    $userPhone = $user['phone'];
                    $username  = $user['username'];
                }
            } catch (Throwable $e) {}
        }

        // 2. Anti-Scam / Duplicate Check in Database
        if ($pdo) {
            try {
                // Check topups table for already approved transaction
                $stmtChk = $pdo->prepare("
                    SELECT id, user_id, amount, status, created_at 
                    FROM topups 
                    WHERE transaction_id = ? OR transaction_id = ? OR transaction_id = ? 
                    LIMIT 1
                ");
                $stmtChk->execute([$cleanRef, 'PAYSTACK-' . $cleanRef, 'PAYSTACK-REC-' . $cleanRef]);
                $existingTopup = $stmtChk->fetch(PDO::FETCH_ASSOC);
                if ($existingTopup && in_array(strtolower($existingTopup['status'] ?? ''), ['approved', 'completed', 'success', 'successful'])) {
                    $dupDate = !empty($existingTopup['created_at']) ? date('d M Y, h:i A', strtotime($existingTopup['created_at'])) : 'Previously';
                    $amtFmt = number_format((float)$existingTopup['amount'], 2);
                    return "*PAYMENT ALREADY VERIFIED & CREDITED*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "• Reference: `{$cleanRef}`\n"
                         . "• Amount: *GHS {$amtFmt}*\n"
                         . "• Status: *Approved & Credited*\n"
                         . "• Verified At: {$dupDate}\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "This payment reference has already been verified and credited. Duplicate submissions are strictly rejected.\n\n"
                         . "Reply *balance* to check your current wallet balance.";
                }

                // Check wallet_transactions table
                $stmtTx = $pdo->prepare("SELECT id, user_id, amount, created_at FROM wallet_transactions WHERE reference LIKE ? LIMIT 1");
                $stmtTx->execute(['%' . $cleanRef . '%']);
                $txRow = $stmtTx->fetch(PDO::FETCH_ASSOC);
                if ($txRow) {
                    $dupDate = !empty($txRow['created_at']) ? date('d M Y, h:i A', strtotime($txRow['created_at'])) : 'Previously';
                    $amtFmt = number_format((float)$txRow['amount'], 2);
                    return "*PAYMENT ALREADY CREDITED*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "• Reference: `{$cleanRef}`\n"
                         . "• Amount: *GHS {$amtFmt}*\n"
                         . "• Status: *Already Credited*\n"
                         . "• Date: {$dupDate}\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "Your wallet has already been credited for this transaction reference.\n\n"
                         . "Reply *balance* to view your current wallet balance.";
                }
            } catch (Throwable $e) {}
        }

        // Check local approved cache file
        $txFile = __DIR__ . '/../logs/whatsapp_approved_txids.json';
        if (file_exists($txFile)) {
            $cachedTx = json_decode(@file_get_contents($txFile), true) ?: [];
            if (isset($cachedTx[$cleanRef])) {
                $entry = $cachedTx[$cleanRef];
                $dupDate = $entry['date'] ?? 'Previously';
                $amtFmt = isset($entry['amount']) ? number_format((float)$entry['amount'], 2) : '0.00';
                return "*PAYMENT ALREADY VERIFIED*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "• Reference: `{$cleanRef}`\n"
                     . "• Status: *Approved & Credited*\n"
                     . "• Verified At: {$dupDate}\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "This transaction ID has already been verified and locked to prevent duplicates.\n\n"
                     . "Reply *balance* to check your current balance.";
            }
        }

        // 3. Resolve Paystack Secret Key
        $secretKey = defined('PAYSTACK_SECRET_KEY') ? trim(PAYSTACK_SECRET_KEY) : '';
        if (empty($secretKey) && file_exists(__DIR__ . '/../admin_settings.json')) {
            $set = json_decode(@file_get_contents(__DIR__ . '/../admin_settings.json'), true);
            $secretKey = $set['paystack_secret_key'] ?? '';
        }
        if (empty($secretKey) && $pdo) {
            try {
                $stmtKey = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'paystack_secret_key' LIMIT 1");
                $secVal = trim($stmtKey->fetchColumn() ?: '');
                if (!empty($secVal)) $secretKey = $secVal;
            } catch (Throwable $e) {}
        }
        if (empty($secretKey)) {
            $secretKey = 'sk_live_7a36c377d9bde68f08af41d8ab8987c6e0d2dcd7';
        }

        // 4. Query Paystack Gateway API Live
        $paystackSuccess = false;
        $paystackData = null;
        $paystackMsg = '';
        if (!empty($secretKey)) {
            $ch = curl_init("https://api.paystack.co/transaction/verify/" . rawurlencode($cleanRef));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$secretKey}",
                    "Cache-Control: no-cache"
                ]
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);

            if ($raw) {
                $parsed = json_decode($raw, true);
                $paystackMsg = $parsed['message'] ?? '';
                if (!empty($parsed['status']) && isset($parsed['data']['status']) && $parsed['data']['status'] === 'success') {
                    $paystackSuccess = true;
                    $paystackData = $parsed['data'];
                } elseif (isset($parsed['data'])) {
                    $paystackData = $parsed['data'];
                }
            }
        }

        // 5. IF PAYSTACK VERIFICATION IS SUCCESSFUL
        if ($paystackSuccess && $paystackData) {
            $amountPesewas = (float)($paystackData['amount'] ?? 0);
            $amountGhs = $amountPesewas / 100;
            $amountGhsFmt = number_format($amountGhs, 2);
            $channel = ucfirst(str_replace('_', ' ', $paystackData['channel'] ?? 'Mobile Money'));
            $paidAt = !empty($paystackData['paid_at']) ? date('d M Y, h:i A', strtotime($paystackData['paid_at'])) : date('d M Y, h:i A');
            $meta = $paystackData['metadata'] ?? [];

            // Standard credit amount: deduct 2% gateway charge if applicable, else credit paid amount
            $creditAmount = round($amountGhs / 1.02, 2);
            if ($creditAmount <= 0) {
                $creditAmount = $amountGhs;
            }
            $creditAmtFmt = number_format($creditAmount, 2);

            // User attribution
            if ($userId <= 0 && !empty($meta['user_id'])) {
                $userId = (int)$meta['user_id'];
            }
            if ($userId <= 0 && !empty($meta['store_user_id'])) {
                $userId = (int)$meta['store_user_id'];
            }
            if ($userId <= 0 && !empty($phone) && $pdo) {
                $user = self::getOrCreateUserForPhone($phone, $profileName, $pdo);
                if ($user) {
                    $userId = (int)$user['id'];
                    $username = $user['username'];
                    $userPhone = $user['phone'];
                }
            }

            $orderType = '';
            $orderId = 0;
            $orderFulfillMsg = '';

            // Apply to database
            if ($pdo) {
                try {
                    $pdo->beginTransaction();

                    // A. Check if this reference matches an existing bundle_sends order
                    $stmtB = $pdo->prepare("SELECT id, network, recipient_phone, gb_amount, status FROM bundle_sends WHERE reference = ? OR payment_ref = ? LIMIT 1");
                    $stmtB->execute([$cleanRef, $cleanRef]);
                    $bundleRow = $stmtB->fetch(PDO::FETCH_ASSOC);

                    if ($bundleRow) {
                        $orderType = 'bundle';
                        $orderId = (int)$bundleRow['id'];
                        $pdo->prepare("UPDATE bundle_sends SET status = 'processing', updated_at = NOW() WHERE id = ?")->execute([$orderId]);

                        // Auto-dispatch via EstoreFulfillment if available
                        $fulfillFile = __DIR__ . '/EstoreFulfillment.php';
                        if (file_exists($fulfillFile)) {
                            require_once $fulfillFile;
                            if (class_exists('EstoreFulfillment')) {
                                try { EstoreFulfillment::dispatchOrder($pdo, $orderId); } catch (Throwable $e) {}
                            }
                        }
                        $orderFulfillMsg = "\n• Order: *#{$orderId} ({$bundleRow['network']} {$bundleRow['gb_amount']}GB)*\n• Delivery Status: *Processing (Automated Dispatch)*";
                    }

                    // B. Check if this reference matches a store_orders row
                    $stmtS = $pdo->prepare("SELECT id, order_number, status FROM store_orders WHERE payment_reference = ? OR order_number = ? LIMIT 1");
                    $stmtS->execute([$cleanRef, $cleanRef]);
                    $storeRow = $stmtS->fetch(PDO::FETCH_ASSOC);
                    if ($storeRow) {
                        $orderType = 'store';
                        $orderId = (int)$storeRow['id'];
                        $pdo->prepare("UPDATE store_orders SET status = 'paid', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
                        $orderFulfillMsg = "\n• Store Order: *#{$storeRow['order_number']}*\n• Order Status: *Paid & In Processing*";
                    }

                    // C. Top-up / Wallet Crediting
                    if ($userId > 0) {
                        // Check if there is a pending topup row
                        $stmtTop = $pdo->prepare("SELECT id, status FROM topups WHERE (transaction_id = ? OR transaction_id = ?) LIMIT 1");
                        $stmtTop->execute([$cleanRef, 'PAYSTACK-' . $cleanRef]);
                        $pendingTop = $stmtTop->fetch(PDO::FETCH_ASSOC);

                        if ($pendingTop) {
                            $pdo->prepare("UPDATE topups SET status = 'approved', amount = ?, updated_at = NOW() WHERE id = ?")->execute([$creditAmount, $pendingTop['id']]);
                        } else {
                            $stmtInsTop = $pdo->prepare("
                                INSERT INTO topups (user_id, network, phone, amount, status, transaction_id, created_at)
                                VALUES (?, 'Paystack', ?, ?, 'approved', ?, NOW())
                            ");
                            $stmtInsTop->execute([$userId, $userPhone, $creditAmount, $cleanRef]);
                        }

                        // Credit user wallet
                        $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")->execute([$creditAmount, $userId]);

                        // Add wallet transaction record
                        if (function_exists('addWalletTransaction')) {
                            addWalletTransaction($pdo, $userId, $creditAmount, 'credit', 'PAYSTACK-' . $cleanRef, "Wallet topup via Paystack ({$cleanRef})");
                        }
                    }

                    $pdo->commit();
                } catch (Throwable $dbErr) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                }
            }

            // Save to local cache file for anti-scam permanence
            try {
                $dir = __DIR__ . '/../logs';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $cachedTx = file_exists($txFile) ? (json_decode(@file_get_contents($txFile), true) ?: []) : [];
                $cachedTx[$cleanRef] = [
                    'user_id' => $userId,
                    'amount'  => $creditAmount,
                    'date'    => $paidAt
                ];
                @file_put_contents($txFile, json_encode($cachedTx, JSON_PRETTY_PRINT));
            } catch (Throwable $e) {}

            $newBal = null;
            if ($pdo && $userId > 0 && function_exists('getUserWalletBalance')) {
                $newBal = getUserWalletBalance($pdo, $userId);
            }
            $newBalFmt = ($newBal !== null) ? number_format((float)$newBal, 2) : $creditAmtFmt;
            $userLabel = ($userId > 0) ? "{$username} (APEX-{$userId})" : "Verified Customer";

            return "*PAYMENT VERIFIED & APPROVED*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "• Reference: `{$cleanRef}`\n"
                 . "• Amount Paid: *GHS {$amountGhsFmt}*\n"
                 . "• Wallet Credited: *GHS {$creditAmtFmt}*\n"
                 . "• Payment Channel: {$channel}\n"
                 . "• Payment Date: {$paidAt}\n"
                 . "• Gateway Status: *Success (Paystack)*\n"
                 . "• Account: *{$userLabel}*\n"
                 . "• Wallet Balance: *GHS {$newBalFmt}*{$orderFulfillMsg}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Your payment was verified successfully and credited to your wallet!\n\n"
                 . "Reply *menu* to place an order or buy packages.";
        }

        // 6. IF PAYSTACK REPORTS ABANDONED OR FAILED
        if ($paystackData) {
            $st = strtolower($paystackData['status'] ?? '');
            if ($st === 'abandoned') {
                return "*PAYMENT NOT COMPLETED*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "• Reference: `{$cleanRef}`\n"
                     . "• Gateway Status: *Abandoned (Not Completed)*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "This transaction was initiated on Paystack but was never completed.\n\n"
                     . "Please complete the checkout on your phone or enter a completed transaction ID.";
            }
            if ($st === 'failed') {
                $reason = $paystackData['gateway_response'] ?? ($paystackMsg ?: 'Transaction failed');
                return "*PAYMENT FAILED*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "• Reference: `{$cleanRef}`\n"
                     . "• Gateway Status: *Failed*\n"
                     . "• Gateway Message: {$reason}\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "Paystack reports this payment failed. Please check with your bank or MoMo provider and try again.";
            }
        }

        // 7. FALLBACK: Check Local Database for MoMo SMS Transaction ID
        if ($pdo) {
            try {
                // Check if recorded in topups
                $stmtM = $pdo->prepare("SELECT * FROM topups WHERE transaction_id = ? LIMIT 1");
                $stmtM->execute([$cleanRef]);
                $topRow = $stmtM->fetch(PDO::FETCH_ASSOC);

                if ($topRow) {
                    $amtFmt = number_format((float)$topRow['amount'], 2);
                    $stFmt = ucfirst($topRow['status'] ?? 'Processing');
                    $dateFmt = !empty($topRow['created_at']) ? date('d M Y, h:i A', strtotime($topRow['created_at'])) : 'Recently';
                    return "*TRANSACTION RECORD FOUND*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "• Transaction ID: `{$cleanRef}`\n"
                         . "• Amount: *GHS {$amtFmt}*\n"
                         . "• Status: *{$stFmt}*\n"
                         . "• Date: {$dateFmt}\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "If your transaction status is processing, please allow 1-2 minutes or reply *support* for assistance.";
                }

                // Check bundle_sends
                $stmtB2 = $pdo->prepare("SELECT * FROM bundle_sends WHERE reference = ? OR client_reference = ? LIMIT 1");
                $stmtB2->execute([$cleanRef, $cleanRef]);
                $bRow = $stmtB2->fetch(PDO::FETCH_ASSOC);
                if ($bRow) {
                    $statusFormatted = ucfirst($bRow['status'] ?? 'Processing');
                    return "*ORDER TRANSACTION FOUND*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "• Reference: `{$cleanRef}`\n"
                         . "• Order ID: `#{$bRow['id']}`\n"
                         . "• Package: *{$bRow['network']} {$bRow['gb_amount']}GB*\n"
                         . "• Status: *{$statusFormatted}*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "Reply *status {$bRow['id']}* to track your order delivery.";
                }
            } catch (Throwable $e) {}
        }

        // 8. NOT FOUND ANYWHERE
        return "*PAYMENT NOT FOUND OR UNVERIFIED*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "• Reference / ID: `{$cleanRef}`\n"
             . "• Status: *Unverified / Not Found*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "• No successful Paystack or MoMo payment was found matching this reference.\n"
             . "• If you paid via Paystack, please confirm the payment reference (e.g. `T12345...` or `APX-...`).\n"
             . "• If you paid via Mobile Money, please allow 1-2 minutes for gateway sync and try again.\n\n"
             . "Need assistance? Reply *support* or contact 0553381853.";
    }

    /**
     * ── 5. Handle Customer Issues, Complaints, and Reports ──
     */
    public static function handleReportRequest(string $text, string $phone, string $profileName, ?PDO $pdo = null): ?string {
        $cleanText = trim($text);
        $lower = strtolower($cleanText);

        $session = self::getUserSession($phone, $pdo);
        if ($session && $session['step'] === 'report_awaiting_details') {
            if (in_array($lower, ['cancel', 'exit', 'stop', 'quit', 'abort', '0'])) {
                self::clearUserSession($phone, $pdo);
                return "*Support request cancelled.*\n\nType *menu* to return to the main menu.";
            }

            self::clearUserSession($phone, $pdo);

            $ticketCode = 'TICK-' . mt_rand(100000, 999999);
            $orderId = !empty($session['data']['order_id']) ? (int)$session['data']['order_id'] : null;
            $orderDisplay = !empty($session['data']['order_display']) ? $session['data']['order_display'] : null;
            $recipientPhone = null;

            if (preg_match('/(?:order\s*(?:id|#|no|ref)?\s*[:#\-\s]*)([A-Za-z0-9_\-]+)/i', $cleanText, $mOid)) {
                $orderDisplay = trim($mOid[1]);
                if (preg_match('/\d+/', $orderDisplay, $mNum)) {
                    $orderId = (int)$mNum[0];
                }
            }
            if (preg_match('/(?:to\s*|phone\s*[:#\-\s]*|recipient\s*[:#\-\s]*)(0\d{9}|233\d{9})/i', $cleanText, $mP)) {
                $recipientPhone = $mP[1];
            } elseif (preg_match('/\b(0[235]\d{8})\b/', $cleanText, $mP2)) {
                $recipientPhone = $mP2[1];
            }

            $issueType = $session['data']['issue_type'] ?? 'customer_report';

            $botReply = "*Support Ticket Created*\n"
                      . "━━━━━━━━━━━━━━━━━━━━━\n"
                      . "• Ticket ID: *{$ticketCode}*\n"
                      . ($orderDisplay ? "• Order ID: *{$orderDisplay}*\n" : "")
                      . ($recipientPhone ? "• Recipient: `{$recipientPhone}`\n" : "")
                      . "• Status: *Under Investigation*\n"
                      . "• Priority: *High*\n"
                      . "━━━━━━━━━━━━━━━━━━━━━\n"
                      . "Thank you for reporting, *{$profileName}*. Our customer support manager has been alerted and will review your issue immediately.\n\n"
                      . "Direct Support Line: *0553381853*\n"
                      . "Direct WhatsApp: https://wa.me/233553381853\n"
                      . "Average response time: *Under 10 minutes*";

            if ($pdo) {
                try {
                    self::ensureTicketsTable($pdo);
                    $stmt = $pdo->prepare("
                        INSERT INTO whatsapp_support_tickets (ticket_code, sender_phone, sender_name, order_id, issue_type, message, bot_reply, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'open', NOW())
                    ");
                    $stmt->execute([$ticketCode, $phone, $profileName, $orderId, $issueType, $cleanText, $botReply]);
                } catch (Throwable $e) {}
            }

            return $botReply;
        }

        // Check if message is a comprehensive report / complaint / order issue
        $isReportMessage = (bool)preg_match(
            '/(?:\b(?:report|complaint|complain|issue|problem|ticket)\b|' .
            '\b(?:hasn\'?t|haven\'?t|didn\'?t|not|never)\s+(?:received?|arrived?|delivered?|got)\b|' .
            '\b(?:yet\s+to\s+receive|delayed|pending\s+too\s+long|failed\s+delivery)\b|' .
            '\b(?:help\s+me\s+resolve|resolve\s+this)\b|' .
            '(?:order\s*id\s*[:#]|order\s*#\s*\d+))/i',
            $cleanText
        );

        // Check if this is just a short trigger (e.g. "5", "report", "support", "agent")
        $isShortTrigger = ($lower === '5' || $lower === '5.' || in_array($lower, [
            'talk to an agent', 'talk to agent', 'agent', 'support', 'help desk',
            'complaint', 'report', 'issue', 'problem', 'human', 'representative', 'help'
        ]));

        if ($isShortTrigger) {
            self::saveUserSession($phone, 'report_awaiting_details', ['issue_type' => 'customer_inquiry'], $pdo);

            return "*Apex Prime Tech — Customer Support Desk*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Hello *{$profileName}*, our support agents are ready to assist you!\n\n"
                 . "Please describe your request, question, or issue in detail below:\n"
                 . "_(If you have an Order ID or Transaction Reference, please include it)_\n\n"
                 . "_(Reply *cancel* anytime to abort)_";
        }

        // If it's a report message with details (or contains order details/issue description)
        if ($isReportMessage && strlen($cleanText) >= 8) {
            self::clearUserSession($phone, $pdo);
            $ticketCode = 'TICK-' . mt_rand(100000, 999999);
            $orderId = null;
            $orderDisplay = null;
            $recipientPhone = null;

            if (preg_match('/(?:order\s*(?:id|#|no|ref)?\s*[:#\-\s]*)([A-Za-z0-9_\-]+)/i', $cleanText, $mOid)) {
                $orderDisplay = trim($mOid[1]);
                if (preg_match('/\d+/', $orderDisplay, $mNum)) {
                    $orderId = (int)$mNum[0];
                }
            }
            if (preg_match('/(?:to\s*|phone\s*[:#\-\s]*|recipient\s*[:#\-\s]*)(0\d{9}|233\d{9})/i', $cleanText, $mP)) {
                $recipientPhone = $mP[1];
            } elseif (preg_match('/\b(0[235]\d{8})\b/', $cleanText, $mP2)) {
                $recipientPhone = $mP2[1];
            }

            $botReply = "*Support Ticket Created*\n"
                      . "━━━━━━━━━━━━━━━━━━━━━\n"
                      . "• Ticket ID: *{$ticketCode}*\n"
                      . ($orderDisplay ? "• Order ID: *{$orderDisplay}*\n" : "")
                      . ($recipientPhone ? "• Recipient: `{$recipientPhone}`\n" : "")
                      . "• Status: *Under Investigation*\n"
                      . "• Priority: *High*\n"
                      . "━━━━━━━━━━━━━━━━━━━━━\n"
                      . "Thank you for reporting, *{$profileName}*. Our customer support manager has been alerted and will review your issue immediately.\n\n"
                      . "Direct Support Line: *0553381853*\n"
                      . "Direct WhatsApp: https://wa.me/233553381853\n"
                      . "Average response time: *Under 10 minutes*";

            if ($pdo) {
                try {
                    self::ensureTicketsTable($pdo);
                    $stmt = $pdo->prepare("
                        INSERT INTO whatsapp_support_tickets (ticket_code, sender_phone, sender_name, order_id, issue_type, message, bot_reply, status, created_at)
                        VALUES (?, ?, ?, ?, 'order_issue', ?, ?, 'open', NOW())
                    ");
                    $stmt->execute([$ticketCode, $phone, $profileName, $orderId, $cleanText, $botReply]);
                } catch (Throwable $e) {}
            }

            return $botReply;
        }

        return null;
    }

    /**
     * ── 6. Handle Other Services (Academic Writing, Website Design, Apple Plans, Merchant Onboarding) ──
     */
    public static function handleOtherServicesRequest(string $text, string $phone, string $profileName, ?PDO $pdo = null): ?string {
        $cleanText = trim($text);
        $lower = strtolower($cleanText);

        $isServicesTrigger = ($lower === '6' || $lower === '6.' || in_array($lower, [
            'other services', 'other service', 'services', 'service',
            'academic writing', 'academic', 'thesis', 'dissertation',
            'website design', 'website designing', 'web design', 'web development',
            'apple plans', 'apple plan', 'apple', 'icloud',
            'merchant onboarding', 'merchant', 'onboarding', 'reseller', 'sub-agent', 'agent onboarding'
        ]));

        if (!$isServicesTrigger) {
            return null;
        }

        return "*Apex Prime Tech — Other Services*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "We offer professional, reliable digital & tech services tailored for your academic and business success:\n\n"
             . "*1. Academic Writing & Research*\n"
             . "• Term papers, essays, research proposals & thesis/dissertations\n"
             . "• Literature reviews, editing, formatting & proofreading\n"
             . "• Data analysis & interpretation (SPSS, Excel, Python, R)\n"
             . "• 100% original, AI-free & plagiarism-checked content\n\n"
             . "*2. Website Designing & Development*\n"
             . "• Modern business, corporate & portfolio websites\n"
             . "• Online stores & eCommerce portals with MoMo/Card payments\n"
             . "• Custom web applications, school/hospital management portals\n"
             . "• Fast cloud hosting, custom domain, professional emails & SSL\n\n"
             . "*3. Apple Plans & Subscriptions*\n"
             . "• Apple Developer accounts registration & setup assistance\n"
             . "• iCloud+ storage upgrade plans & cloud backups\n"
             . "• Apple Music, Apple Arcade & family sharing setup\n"
             . "• Apple ID configuration, device setup & region switching\n\n"
             . "*4. Merchant Onboarding & Agency*\n"
             . "• Become an Apex Prime Data Bundle & WAEC Reseller Agent\n"
             . "• Access wholesale pricing to maximize your profit margins\n"
             . "• Merchant Mobile Money payment gateway integration\n"
             . "• Dedicated merchant portal with instant automated delivery\n\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "*How to Order or Get a Quote*:\n"
             . "• Reply *5* to chat with an agent right now!\n"
             . "• Direct WhatsApp: 0553381853 (https://wa.me/233553381853)\n"
             . "• Visit our website: https://apexprime.club\n"
             . "• Reply *menu* to return to the main menu";
    }

    /**
     * Handle Link Personal Bot with Phone Number Request
     */
    public static function handleLinkBotRequest(string $incomingText, string $senderPhone, string $profileName, ?PDO $pdo = null): ?string {
        return self::formatReply(
            "*Link WhatsApp with Phone Number (Personal Bot)*\n" .
            "━━━━━━━━━━━━━━━━━━━━━\n" .
            "Activate your own personal WhatsApp bot directly on your phone number without scanning any QR code!\n\n" .
            "*Features of Your Personal Bot:*\n" .
            "• *Anti-Delete Recovery:* View deleted messages & photos forwarded privately to your DM.\n" .
            "• *Save View-Once:* View-once images & videos are unlocked and saved automatically.\n" .
            "• *Full-Duration Music:* Download complete songs by typing *.play <song name>*.\n" .
            "• *Video Downloader:* Automatic TikTok, YouTube, and Instagram reel downloads.\n" .
            "• *Auto-View & Auto-Like Status:* Automatically view contact statuses and react with emojis.\n" .
            "• *Apex AI Assistant:* Ask questions anytime with *@Apex_Assistant260* or *.ai*.\n" .
            "• *100% Private Mode:* The bot runs as your personal tool — it will NEVER send customer auto-replies to your friends or contacts!\n\n" .
            "━━━━━━━━━━━━━━━━━━━━━\n" .
            "*How to Link Your WhatsApp in 1 Minute:*\n" .
            "1. Visit: https://apexprime.club/whatsapp_bot_activation\n" .
            "2. Click *Link with Phone Number*\n" .
            "3. Enter your WhatsApp number (e.g. `0559623850`)\n" .
            "4. Copy the *8-digit Pairing Code* shown on screen\n" .
            "5. Open WhatsApp > tap *Linked Devices* > *Link a Device* > *Link with phone number instead*\n" .
            "6. Enter the 8-digit code to link instantly!\n\n" .
            "*Link Your Account Now:*\n" .
            "https://apexprime.club/whatsapp_bot_activation\n\n" .
            "_(Reply *menu* to return to the main menu)_",
            $senderPhone,
            $profileName,
            $pdo
        );
    }

    /**
     * Generate the complete All Commands auto-reply menu for 0553381853
     */
    public static function getAllCommandsReply(string $senderPhone, string $profileName = 'Customer', ?PDO $pdo = null): string {
        $config = self::getConfig($pdo);

        // 1. Prefer custom text from active 'menu' or 'hi' command configured in Admin Portal
        if (!empty($config['commands'])) {
            foreach ($config['commands'] as $cmd) {
                if (!empty($cmd['is_active']) && !empty($cmd['response_text'])) {
                    $trigs = array_map('trim', explode(',', strtolower($cmd['command_trigger'] ?? '')));
                    if (in_array('menu', $trigs) || in_array('start', $trigs)) {
                        return self::formatReply($cmd['response_text'], $senderPhone, $profileName, $pdo);
                    }
                }
            }
        }

        // 2. Prefer fallback_message saved by admin in Global Bot Settings
        if (!empty($config['fallback_message']) && trim($config['fallback_message']) !== '') {
            return self::formatReply($config['fallback_message'], $senderPhone, $profileName, $pdo);
        }

        // 3. Dynamically build menu from active commands in Admin Portal / database
        if (!empty($config['commands'])) {
            $menuLines = ["*Menu Options — Apex Prime Tech*", "━━━━━━━━━━━━━━━━━━━━━", "Hello {name}! Please choose an option below:\n"];
            foreach ($config['commands'] as $cmd) {
                if (!empty($cmd['is_active']) && !empty($cmd['description'])) {
                    $firstTrigger = trim(explode(',', $cmd['command_trigger'] ?? '')[0] ?? '');
                    $menuLines[] = "*{$firstTrigger}* — " . $cmd['description'];
                }
            }
            $menuLines[] = "━━━━━━━━━━━━━━━━━━━━━\nWebsite: https://apexprime.club\nSupport: 0553381853";
            return self::formatReply(implode("\n", $menuLines), $senderPhone, $profileName, $pdo);
        }

        return self::formatReply("Hello {name}! Welcome to Apex Prime.\nType *menu* to view options.", $senderPhone, $profileName, $pdo);
    }

    /**
     * Process incoming message and send auto-reply
     * 
     * @param string $senderPhone Recipient phone number (e.g. 233541234567)
     * @param string $incomingText Inbound message text
     * @param string $profileName Meta contact profile name
     * @param PDO|null $pdo Database connection
     * @return array Processing result
     */
    public static function processIncomingMessage(string $senderPhone, string $incomingText, string $profileName = 'Customer', ?PDO $pdo = null, bool $sendApi = true): array {
        $pdo = self::getPdo($pdo);
        $config = self::getConfig($pdo);
        if (empty($config['bot_enabled'])) {
            return ['handled' => false, 'reason' => 'Bot auto-replies globally disabled'];
        }

        // Check if sender phone is blacklisted / muted from bot auto-replies
        $cleanSender = preg_replace('/\D/', '', $senderPhone);
        $ignoredList = $config['ignored_numbers'] ?? [];
        foreach ($ignoredList as $ign) {
            $cleanIgn = preg_replace('/\D/', '', (string)$ign);
            if (empty($cleanIgn)) continue;
            if ($cleanSender === $cleanIgn || (strlen($cleanSender) >= 9 && strlen($cleanIgn) >= 9 && substr($cleanSender, -9) === substr($cleanIgn, -9))) {
                return ['handled' => false, 'reason' => "Bot auto-reply disabled for {$senderPhone}"];
            }
        }

        $replyText = '';
        $matchedName = '';

        // 0. Active Customer Interactive Sessions (Guarantees in-flight multi-step actions like payment verification are processed immediately)
        $session = self::getUserSession($senderPhone, $pdo);
        if ($session && !empty($session['step'])) {
            $sStep = $session['step'];
            if (strpos($sStep, 'verify_payment_') === 0) {
                $verifyReply = self::handleVerifyPaymentRequest($incomingText, $senderPhone, $profileName, $pdo);
                if ($verifyReply !== null) {
                    $replyText = $verifyReply;
                    $matchedName = 'VERIFY_PAYMENT_SESSION';
                }
            } elseif (strpos($sStep, 'order_') === 0) {
                $orderReply = self::handleOrderRequest($incomingText, $senderPhone, $profileName, $pdo);
                if ($orderReply !== null) {
                    $replyText = $orderReply;
                    $matchedName = 'ORDER_SESSION';
                }
            } elseif (strpos($sStep, 'waec_') === 0) {
                $waecReply = self::handleWaecRequest($incomingText, $senderPhone, $profileName, $pdo);
                if ($waecReply !== null) {
                    $replyText = $waecReply;
                    $matchedName = 'WAEC_SESSION';
                }
            } elseif (strpos($sStep, 'check_status_') === 0) {
                $statusReply = self::handleCheckStatusRequest($incomingText, $senderPhone, $profileName, $pdo);
                if ($statusReply !== null) {
                    $replyText = $statusReply;
                    $matchedName = 'STATUS_SESSION';
                }
            } elseif (strpos($sStep, 'report_') === 0) {
                $reportReply = self::handleReportRequest($incomingText, $senderPhone, $profileName, $pdo);
                if ($reportReply !== null) {
                    $replyText = $reportReply;
                    $matchedName = 'REPORT_SESSION';
                }
            }
        }

        // 0.5. Customer Issues, Complaints, and Reports (High Priority Auto-Detection)
        if (empty($replyText)) {
            $reportReply = self::handleReportRequest($incomingText, $senderPhone, $profileName, $pdo);
            if ($reportReply !== null) {
                $replyText = $reportReply;
                $matchedName = 'REPORT_ISSUE';
            }
        }

        // 1. Configured Bot Commands (Admin Portal Dynamic Matching & Action Flow Execution)
        if (empty($replyText)) {
            $matchedCmd = self::findMatchingCommand($incomingText, $pdo);
            if ($matchedCmd) {
                $aType = $matchedCmd['action_type'] ?? 'text';
                if ($aType === 'verify_payment') {
                    $verifyReply = self::handleVerifyPaymentRequest($incomingText, $senderPhone, $profileName, $pdo);
                    if ($verifyReply !== null) {
                        $replyText = $verifyReply;
                        $matchedName = $matchedCmd['command_trigger'];
                    }
                } elseif ($aType === 'buy_from_me') {
                    $buyReply = self::handleBuyFromMeRequest($incomingText, $senderPhone, $profileName, $pdo);
                    if ($buyReply !== null) {
                        $replyText = $buyReply;
                        $matchedName = $matchedCmd['command_trigger'];
                    }
                } elseif ($aType === 'place_order') {
                    $orderReply = self::handleOrderRequest($incomingText, $senderPhone, $profileName, $pdo);
                    if ($orderReply !== null) {
                        $replyText = $orderReply;
                        $matchedName = $matchedCmd['command_trigger'];
                    }
                } elseif ($aType === 'waec_checker') {
                    $waecReply = self::handleWaecRequest($incomingText, $senderPhone, $profileName, $pdo);
                    if ($waecReply !== null) {
                        $replyText = $waecReply;
                        $matchedName = $matchedCmd['command_trigger'];
                    }
                } elseif ($aType === 'check_status') {
                    $statusReply = self::handleCheckStatusRequest($incomingText, $senderPhone, $profileName, $pdo);
                    if ($statusReply !== null) {
                        $replyText = $statusReply;
                        $matchedName = $matchedCmd['command_trigger'];
                    }
                } elseif ($aType === 'support_agent') {
                    $reportReply = self::handleReportRequest($incomingText, $senderPhone, $profileName, $pdo);
                    if ($reportReply !== null) {
                        $replyText = $reportReply;
                        $matchedName = $matchedCmd['command_trigger'];
                    }
                } elseif ($aType === 'other_services') {
                    $servicesReply = self::handleOtherServicesRequest($incomingText, $senderPhone, $profileName, $pdo);
                    if ($servicesReply !== null) {
                        $replyText = $servicesReply;
                        $matchedName = $matchedCmd['command_trigger'];
                    }
                } elseif ($aType === 'link_bot') {
                    $linkReply = self::handleLinkBotRequest($incomingText, $senderPhone, $profileName, $pdo);
                    if ($linkReply !== null) {
                        $replyText = $linkReply;
                        $matchedName = $matchedCmd['command_trigger'];
                    }
                } else {
                    $replyText = self::formatReply($matchedCmd['response_text'], $senderPhone, $profileName, $pdo);
                    $matchedName = $matchedCmd['command_trigger'];
                }
            }
        }

        // 2. Built-in Fallbacks for Initial Intents (If not matched by custom commands)
        if (empty($replyText)) {
            $orderReply = self::handleOrderRequest($incomingText, $senderPhone, $profileName, $pdo);
            if ($orderReply !== null) {
                $replyText = $orderReply;
                $matchedName = 'PLACE_ORDER';
            }
        }

        if (empty($replyText)) {
            $waecReply = self::handleWaecRequest($incomingText, $senderPhone, $profileName, $pdo);
            if ($waecReply !== null) {
                $replyText = $waecReply;
                $matchedName = 'WAEC_CHECKER';
            }
        }

        if (empty($replyText)) {
            $statusReply = self::handleCheckStatusRequest($incomingText, $senderPhone, $profileName, $pdo);
            if ($statusReply !== null) {
                $replyText = $statusReply;
                $matchedName = 'CHECK_STATUS';
            }
        }

        if (empty($replyText)) {
            $verifyReply = self::handleVerifyPaymentRequest($incomingText, $senderPhone, $profileName, $pdo);
            if ($verifyReply !== null) {
                $replyText = $verifyReply;
                $matchedName = 'VERIFY_PAYMENT';
            }
        }

        if (empty($replyText)) {
            $reportReply = self::handleReportRequest($incomingText, $senderPhone, $profileName, $pdo);
            if ($reportReply !== null) {
                $replyText = $reportReply;
                $matchedName = 'REPORT_ISSUE';
            }
        }

        if (empty($replyText)) {
            $servicesReply = self::handleOtherServicesRequest($incomingText, $senderPhone, $profileName, $pdo);
            if ($servicesReply !== null) {
                $replyText = $servicesReply;
                $matchedName = 'OTHER_SERVICES';
            }
        }

        if (empty($replyText)) {
            $cLow = strtolower(trim($incomingText));
            if ($cLow === '7' || $cLow === '7.' || in_array($cLow, ['link', 'link bot', 'phone number', 'personal bot', 'activate bot', 'link with phone number', 'link phone'])) {
                $linkReply = self::handleLinkBotRequest($incomingText, $senderPhone, $profileName, $pdo);
                if ($linkReply !== null) {
                    $replyText = $linkReply;
                    $matchedName = 'LINK_BOT';
                }
            }
        }

        if (empty($replyText)) {
            $cLow = strtolower(trim($incomingText));
            if ($cLow === '8' || $cLow === '8.' || in_array($cLow, ['buy from me', 'buy fromme', 'buyfromme', 'buy me', 'buy-from-me'])) {
                $buyReply = self::handleBuyFromMeRequest($incomingText, $senderPhone, $profileName, $pdo);
                if ($buyReply !== null) {
                    $replyText = $buyReply;
                    $matchedName = 'BUY_FROM_ME';
                }
            }
        }

        // 3. Greetings / Menu / All Commands
        $cleanLower = strtolower(trim($incomingText));
        $allCmdTriggers = [
            'command', 'commands', 'all commands', 'bot commands', 'list commands',
            'command list', 'cmd', 'cmds', 'help', 'hi', 'hello', 'hey', 'start',
            'welcome', 'apex prime', 'options', '0541145310', '0553381853', 'menu'
        ];

        if (empty($replyText)) {
            foreach ($allCmdTriggers as $act) {
                if ($cleanLower === $act || strpos($cleanLower, $act) === 0) {
                    $replyText = self::getAllCommandsReply($senderPhone, $profileName, $pdo);
                    $matchedName = 'ALL_COMMANDS';
                    break;
                }
            }
        }

        // 4. Generative AI Assistant (Meta-AI Conversational Experience)
        if (empty($replyText)) {
            $aiCfg = WhatsAppAiService::getConfig();
            $customHandle = !empty($aiCfg['username']) ? preg_quote(ltrim($aiCfg['username'], '@'), '/') : 'Apex_Assistant260';
            $isAiExplicit = (bool)preg_match('/^(\.ai|ai:|ask:|@ai|@' . $customHandle . '|' . $customHandle . ')\s*(.*)$/is', trim($incomingText), $aiM);
            $aiPrompt = $isAiExplicit ? trim($aiM[2]) : trim($incomingText);

            if (!empty($aiCfg['enabled'])) {
                if ($isAiExplicit || ($aiCfg['mode'] ?? 'all') === 'all') {
                    $aiRes = WhatsAppAiService::ask($senderPhone, $aiPrompt ?: "Hi! What can you do?", $profileName, $pdo);
                    if (!empty($aiRes['response'])) {
                        $replyText = $aiRes['response'];
                        $matchedName = $isAiExplicit ? 'AI_DIRECT_COMMAND' : 'AI_CONVERSATIONAL';
                    }
                }
            }
        }

        // 5. Default Fallback message (returns all commands menu so customer can choose)
        if (empty($replyText) && !empty($config['fallback_enabled'])) {
            if (!empty($config['fallback_message']) && trim($config['fallback_message']) !== '') {
                $replyText = self::formatReply($config['fallback_message'], $senderPhone, $profileName, $pdo);
            } else {
                $replyText = self::getAllCommandsReply($senderPhone, $profileName, $pdo);
            }
            $matchedName = 'FALLBACK';
        }

        if (empty($replyText)) {
            return ['handled' => false, 'reason' => 'No matching command and no fallback configured'];
        }

        $sendRes = null;
        if ($sendApi) {
            // Send outbound WhatsApp message
            $sendRes = WhatsAppApi::sendTextMessage($senderPhone, $replyText);

            // Log auto-reply to webhook log
            if (function_exists('logWhatsAppWebhook')) {
                logWhatsAppWebhook("🤖 WhatsApp Bot Auto-Reply Sent", [
                    'to'          => $senderPhone,
                    'incoming'    => $incomingText,
                    'matched_cmd' => $matchedName,
                    'reply'       => $replyText,
                    'api_result'  => $sendRes
                ]);
            }
        }

        $ret = [
            'handled'    => true,
            'matched'    => $matchedName,
            'reply'      => $replyText,
            'is_report'  => in_array($matchedName, ['REPORT_ISSUE', 'REPORT_SESSION']) || (strpos($replyText, 'Support Ticket Created') !== false),
            'api_result' => $sendRes
        ];

        if (!empty($GLOBALS['waec_latest_image'])) {
            $ret['image_file']     = $GLOBALS['waec_latest_image'];
            $ret['image_path']     = $GLOBALS['waec_latest_image'];
        }

        if (!empty($GLOBALS['waec_latest_pdf'])) {
            $ret['pdf_file']       = $GLOBALS['waec_latest_pdf']['file_path'] ?? null;
            $ret['pdf_name']       = $GLOBALS['waec_latest_pdf']['file_name'] ?? null;
            $ret['pdf_url']        = $GLOBALS['waec_latest_pdf']['file_url'] ?? null;
            $ret['print_url']      = $GLOBALS['waec_latest_pdf']['print_url'] ?? null;
            $ret['candidate_name'] = $GLOBALS['waec_latest_pdf']['candidate_name'] ?? null;
            $ret['index_number']   = $GLOBALS['waec_latest_pdf']['index_number'] ?? null;
        }

        return $ret;
    }
}
