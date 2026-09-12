<?php
/**
 * Apex Prime — WhatsApp Bot CLI Bridge
 * 
 * Provides a bridge between Node.js QR Bot (Baileys) and the full PHP WhatsAppBot engine.
 * Handles database queries, WAEC result retrieval, payment verification, and session state.
 */

// Suppress error outputs to keep JSON output clean
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Ensure standardized timezone (Ghana GMT / Africa/Accra)
if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Africa/Accra');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/WhatsAppBot.php';

// Accept arguments from CLI: php whatsapp_bridge.php <phone> <message> <name>
$phone   = $argv[1] ?? '';
$message = $argv[2] ?? '';
$name    = $argv[3] ?? 'Customer';

if (empty($phone) || !isset($argv[2])) {
    header('Content-Type: application/json');
    echo json_encode(['handled' => false, 'error' => 'Missing phone or message arguments']);
    exit(1);
}

// Safely connect to database with fast socket probe (fails in ~80ms if offline instead of 2000ms)
$pdo = null;
if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
    $dbHost = DB_HOST;
    $dbPort = 3306;
    $probe = @fsockopen($dbHost, $dbPort, $errno, $errstr, 0.08);
    if ($probe) {
        fclose($probe);
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_TIMEOUT => 1
            ]);
        } catch (Throwable $e) {
            $pdo = null;
        }
    }
}

try {
    // Process message through full WhatsAppBot engine without sending via Meta API ($sendApi = false)
    $res = WhatsAppBot::processIncomingMessage($phone, $message, $name, $pdo, false);
    
    // Output single clean JSON line for Node.js caller
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    echo json_encode([
        'handled' => false,
        'error'   => $ex->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
