<?php
/**
 * WhatsApp Cloud API Client
 * 
 * Provides methods to send messages and templates via Meta's WhatsApp Cloud API.
 */

class WhatsAppApi {

    /**
     * Load settings from admin_settings.json or DB
     */
    public static function getSettings(): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $s = [];
        $jsonFile = __DIR__ . '/../admin_settings.json';
        if (file_exists($jsonFile)) {
            $json = json_decode(@file_get_contents($jsonFile), true);
            if (is_array($json)) {
                $s = $json;
            }
        }

        // Try DB settings if credentials are defined and reachable
        if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
            try {
                $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                    PDO::ATTR_TIMEOUT => 2
                ]);
                $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
                if (!empty($rows)) {
                    $s = array_merge($s, $rows);
                }
            } catch (\Throwable $e) {}
        }

        $cached = $s;
        return $cached;
    }

    /**
     * Get configured access token
     */
    public static function getToken(): string {
        $s = self::getSettings();
        if (!empty($s['whatsapp_token'])) {
            return trim($s['whatsapp_token']);
        }
        if (defined('WHATSAPP_TOKEN') && !empty(WHATSAPP_TOKEN)) {
            return WHATSAPP_TOKEN;
        }
        return getenv('WHATSAPP_TOKEN') ?: 'EAAVbwPrkZA5EBSWYPZCejcQIT87IReAyjpXQZAAsjxQ6BWLvP6ZCwnabpoTbQ2S6UN0ZBnBJSKcYlNDiV7Yx3mSBTzJO3Ri6sy0XFFL9NeqcyIsDEbKDf2bFUwEQ10Az7Tena4DvwmQeMj4iQAZCeLAkURotrEpHaYLKDNPPsU8jYbhmnYd05Jq1tXbbWZB8wZDZD';
    }

    /**
     * Get configured Phone Number ID
     */
    public static function getPhoneNumberId(): string {
        $s = self::getSettings();
        if (!empty($s['whatsapp_phone_number_id'])) {
            return trim($s['whatsapp_phone_number_id']);
        }
        if (defined('WHATSAPP_PHONE_NUMBER_ID') && !empty(WHATSAPP_PHONE_NUMBER_ID)) {
            return WHATSAPP_PHONE_NUMBER_ID;
        }
        return getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '1361643693689804';
    }

    /**
     * Get configured WhatsApp Business Account ID
     */
    public static function getBusinessAccountId(): string {
        $s = self::getSettings();
        if (!empty($s['whatsapp_business_account_id'])) {
            return trim($s['whatsapp_business_account_id']);
        }
        if (defined('WHATSAPP_BUSINESS_ACCOUNT_ID') && !empty(WHATSAPP_BUSINESS_ACCOUNT_ID)) {
            return WHATSAPP_BUSINESS_ACCOUNT_ID;
        }
        return getenv('WHATSAPP_BUSINESS_ACCOUNT_ID') ?: '2143228069926641';
    }

    /**
     * Get configured Webhook Verify Token
     */
    public static function getVerifyToken(): string {
        $s = self::getSettings();
        if (!empty($s['whatsapp_verify_token'])) {
            return trim($s['whatsapp_verify_token']);
        }
        if (defined('WHATSAPP_VERIFY_TOKEN') && !empty(WHATSAPP_VERIFY_TOKEN)) {
            return WHATSAPP_VERIFY_TOKEN;
        }
        return getenv('WHATSAPP_VERIFY_TOKEN') ?: 'Apex Prime';
    }

    /**
     * Check if WhatsApp API integration is enabled
     */
    public static function isEnabled(): bool {
        $s = self::getSettings();
        return isset($s['whatsapp_enabled']) ? (bool)$s['whatsapp_enabled'] : true;
    }

    /**
     * Format phone number to international format without plus (e.g. 0541234567 -> 233541234567)
     */
    public static function formatPhoneNumber(string $phone): string {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean) === 10 && substr($clean, 0, 1) === '0') {
            return '233' . substr($clean, 1);
        }
        return $clean;
    }

    /**
     * Send a standard text message
     * 
     * @param string $to Recipient phone number (e.g. '0541234567' or '233541234567')
     * @param string $message Text content
     * @param string|null $phoneNumberId Meta Phone Number ID (optional, or reads from env)
     * @return array Response from Meta Graph API
     */
    public static function sendTextMessage(string $to, string $message, ?string $phoneNumberId = null): array {
        $token = self::getToken();
        if (empty($token)) {
            return ['success' => false, 'message' => 'WhatsApp access token not configured'];
        }

        $phoneId = $phoneNumberId ?: self::getPhoneNumberId();
        if (empty($phoneId)) {
            return ['success' => false, 'message' => 'WhatsApp Phone Number ID not configured'];
        }

        $url = "https://graph.facebook.com/v20.0/{$phoneId}/messages";
        $recipient = self::formatPhoneNumber($to);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $recipient,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => $message
            ]
        ];

        return self::makeRequest($url, $payload, $token);
    }

    /**
     * Send an emoji reaction to a message
     */
    public static function sendReaction(string $to, string $messageId, string $emoji = '📝', ?string $phoneNumberId = null): array {
        $token = self::getToken();
        if (empty($token) || empty($messageId)) {
            return ['success' => false, 'message' => 'Token or message ID missing'];
        }

        $phoneId = $phoneNumberId ?: self::getPhoneNumberId();
        if (empty($phoneId)) {
            return ['success' => false, 'message' => 'Phone ID missing'];
        }

        $url = "https://graph.facebook.com/v20.0/{$phoneId}/messages";
        $recipient = self::formatPhoneNumber($to);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $recipient,
            'type'              => 'reaction',
            'reaction'          => [
                'message_id' => $messageId,
                'emoji'      => $emoji
            ]
        ];

        return self::makeRequest($url, $payload, $token);
    }

    /**
     * Send a pre-approved Meta WhatsApp template message
     * 
     * @param string $to Recipient phone number
     * @param string $templateName Name of approved template in Meta Manager
     * @param string $language Language code (default: 'en_US')
     * @param array $components Optional template components (header, body, buttons)
     * @param string|null $phoneNumberId
     * @return array
     */
    public static function sendTemplate(string $to, string $templateName, string $language = 'en_US', array $components = [], ?string $phoneNumberId = null): array {
        $token = self::getToken();
        if (empty($token)) {
            return ['success' => false, 'message' => 'WhatsApp access token not configured'];
        }

        $phoneId = $phoneNumberId ?: self::getPhoneNumberId();
        if (empty($phoneId)) {
            return ['success' => false, 'message' => 'WhatsApp Phone Number ID not configured'];
        }

        $url = "https://graph.facebook.com/v20.0/{$phoneId}/messages";
        $recipient = self::formatPhoneNumber($to);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $recipient,
            'type'              => 'template',
            'template'          => [
                'name'     => $templateName,
                'language' => [
                    'code' => $language
                ]
            ]
        ];

        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        return self::makeRequest($url, $payload, $token);
    }

    /**
     * Send HTTP POST to Meta Graph API
     */
    private static function makeRequest(string $url, array $payload, string $token): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['success' => false, 'message' => 'cURL Error: ' . $curlErr];
        }

        $data = json_decode($response, true) ?: [];
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $data];
        }

        $errMsg = $data['error']['message'] ?? "HTTP Error {$httpCode}";
        return ['success' => false, 'message' => $errMsg, 'data' => $data];
    }
}
