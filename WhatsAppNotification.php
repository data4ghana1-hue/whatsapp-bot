<?php
/**
 * WhatsAppNotification
 * 
 * Dispatches automated customer WhatsApp alerts for:
 * 1. Order delivery & completion
 * 2. Order status updates (failed / refunded)
 * 3. Wallet top-up approvals & credits
 * 
 * Supports delivery via:
 * - Local/Cloud Baileys Bot HTTP Server (port 3000)
 * - Meta WhatsApp Cloud API (as fallback if configured)
 */

class WhatsAppNotification {

    /**
     * Ensure database columns exist in `users` table
     */
    public static function ensureSchema(?PDO $pdo = null): void {
        static $checked = false;
        if ($checked) return;
        if (!$pdo) {
            if (function_exists('db_connect')) {
                $pdo = db_connect();
            } elseif (function_exists('db')) {
                $pdo = db();
            }
        }
        if ($pdo) {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_phone VARCHAR(30) DEFAULT NULL");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_notify_enabled TINYINT(1) DEFAULT 1");
            } catch (Exception $e) {}
            $checked = true;
        }
    }

    /**
     * Format phone number to international format (Ghana 233xxxxxxxx)
     */
    public static function formatPhone(string $phone): string {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean) === 10 && substr($clean, 0, 1) === '0') {
            return '233' . substr($clean, 1);
        }
        return $clean;
    }

    /**
     * Get configured bot HTTP URL (default http://127.0.0.1:3000)
     */
    public static function getBotUrl(): string {
        if (defined('WHATSAPP_BOT_URL') && !empty(WHATSAPP_BOT_URL)) {
            return rtrim(WHATSAPP_BOT_URL, '/');
        }
        $env = getenv('WHATSAPP_BOT_URL');
        if (!empty($env)) {
            return rtrim($env, '/');
        }
        return 'http://127.0.0.1:3000';
    }

    /**
     * Send outbound WhatsApp message to raw phone number
     *
     * @param string $phone Target phone number
     * @param string $message Message text
     * @return array ['success' => bool, 'provider' => string, 'message' => string]
     */
    public static function send(string $phone, string $message): array {
        $cleanPhone = self::formatPhone($phone);
        if (empty($cleanPhone) || empty(trim($message))) {
            return ['success' => false, 'message' => 'Phone or message empty'];
        }

        // 1. Try local or configured Baileys bot HTTP endpoint
        $botUrl = self::getBotUrl() . '/api/send-message';
        $ch = curl_init($botUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['phone' => $cleanPhone, 'message' => $message]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 2
        ]);
        $botRes = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($botRes && $httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($botRes, true);
            if (!empty($data['success'])) {
                self::log("SUCCESS (Baileys) -> To: $cleanPhone | Msg: " . substr($message, 0, 60));
                return ['success' => true, 'provider' => 'baileys', 'data' => $data];
            }
        }

        // 2. Fallback: Meta WhatsApp Cloud API if configured
        try {
            $apiPath = __DIR__ . '/WhatsAppApi.php';
            if (file_exists($apiPath)) {
                require_once $apiPath;
                if (class_exists('WhatsAppApi') && WhatsAppApi::isEnabled()) {
                    $token = WhatsAppApi::getToken();
                    if (!empty($token)) {
                        $metaRes = WhatsAppApi::sendTextMessage($cleanPhone, $message);
                        if (!empty($metaRes['success'])) {
                            self::log("SUCCESS (Meta Cloud) -> To: $cleanPhone | Msg: " . substr($message, 0, 60));
                            return ['success' => true, 'provider' => 'meta', 'data' => $metaRes];
                        }
                    }
                }
            }
        } catch (Exception $metaEx) {
            self::log("Meta API error: " . $metaEx->getMessage());
        }

        self::log("FAILED -> To: $cleanPhone | Bot HTTP Code: $httpCode | Bot Res: $botRes");
        return ['success' => false, 'message' => 'WhatsApp bot offline and Meta Cloud API unavailable'];
    }

    /**
     * Send WhatsApp notification to a specific user ID
     * Respects user's notification preferences and custom WhatsApp number
     *
     * @param int|string $userId
     * @param string     $message
     * @param PDO|null   $pdo
     * @return bool
     */
    public static function sendToUser($userId, string $message, ?PDO $pdo = null): bool {
        try {
            if (!$pdo) {
                if (function_exists('db_connect')) {
                    $pdo = db_connect();
                } elseif (function_exists('db')) {
                    $pdo = db();
                }
            }
            if (!$pdo) return false;

            self::ensureSchema($pdo);

            $stmt = $pdo->prepare("SELECT phone, whatsapp_phone, whatsapp_notify_enabled FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$u) return false;

            // Check if user disabled WhatsApp notifications
            if (isset($u['whatsapp_notify_enabled']) && (int)$u['whatsapp_notify_enabled'] === 0) {
                return false;
            }

            // Target phone: whatsapp_phone preferred, otherwise fallback to primary phone
            $targetPhone = !empty($u['whatsapp_phone']) ? $u['whatsapp_phone'] : ($u['phone'] ?? '');
            if (empty(trim($targetPhone))) {
                return false;
            }

            $res = self::send($targetPhone, $message);
            return !empty($res['success']);
        } catch (Exception $e) {
            self::log("sendToUser error for #$userId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify customer on Order Status Change (Completed, Failed, Refunded)
     */
    public static function sendOrderNotification($userId, string $status, string $network, $gbAmount, string $recipientPhone, $orderId, ?PDO $pdo = null): bool {
        $statusClean = strtolower(trim($status));
        $gbFormatted = is_numeric($gbAmount) ? number_format((float)$gbAmount, 2) . ' GB' : (string)$gbAmount;
        $appName = defined('APP_NAME') ? APP_NAME : 'Apex Prime';

        if (in_array($statusClean, ['completed', 'successful', 'success', 'sucessfully', 'successfully'])) {
            $msg = "🎉 *Order Delivered Successfully!*\n\n"
                 . "Dear Customer, your data bundle order has been processed and delivered:\n\n"
                 . "📦 *Package:* {$gbFormatted} ({$network})\n"
                 . "📱 *Recipient:* {$recipientPhone}\n"
                 . "🆔 *Order ID:* #{$orderId}\n\n"
                 . "Thank you for using {$appName}! 🚀";
        } elseif (in_array($statusClean, ['failed', 'declined', 'cancelled'])) {
            $msg = "❌ *Order Failed Notice*\n\n"
                 . "Your order could not be completed:\n\n"
                 . "📦 *Package:* {$gbFormatted} ({$network})\n"
                 . "📱 *Recipient:* {$recipientPhone}\n"
                 . "🆔 *Order ID:* #{$orderId}\n\n"
                 . "If you were charged, your wallet has been refunded. Please check your transaction history on {$appName}.";
        } elseif (in_array($statusClean, ['refunded', 'reversal'])) {
            $msg = "💰 *Order Refunded*\n\n"
                 . "Your order has been refunded to your wallet:\n\n"
                 . "📦 *Order:* #{$orderId} ({$gbFormatted} {$network})\n"
                 . "📱 *Recipient:* {$recipientPhone}\n\n"
                 . "The funds have been returned to your {$appName} wallet balance.";
        } else {
            $msg = "ℹ️ *Order Update (#{$orderId})*\n\n"
                 . "📦 *Package:* {$gbFormatted} ({$network})\n"
                 . "📱 *Recipient:* {$recipientPhone}\n"
                 . "📊 *Status:* " . ucfirst($statusClean) . "\n\n"
                 . "Thank you for choosing {$appName}!";
        }

        return self::sendToUser($userId, $msg, $pdo);
    }

    /**
     * Notify customer on Wallet Top-up Approval / Credit
     */
    public static function sendTopupNotification($userId, float $amount, float $newBalance, ?PDO $pdo = null, string $method = ''): bool {
        $appName = defined('APP_NAME') ? APP_NAME : 'Apex Prime';
        $methodText = !empty($method) ? " via {$method}" : "";

        $msg = "💰 *Wallet Credited Successfully!*\n\n"
             . "Your wallet has been funded{$methodText}:\n\n"
             . "💵 *Amount Added:* GHS " . number_format($amount, 2) . "\n"
             . "💳 *New Wallet Balance:* GHS " . number_format($newBalance, 2) . "\n\n"
             . "Your funds are ready to use. Thank you for choosing {$appName}! ✨";

        return self::sendToUser($userId, $msg, $pdo);
    }

    /**
     * Internal logger
     */
    private static function log(string $msg): void {
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/whatsapp_notifications.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    }
}
