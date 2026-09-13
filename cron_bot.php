<?php
/**
 * Apex Prime — 24/7 WhatsApp QR Bot Cron Runner & Web Manager
 * 
 * Automatically keeps WhatsApp Bot running 24/7 on cPanel.
 * Can be run via cPanel Cron Job, CLI, or Web Browser.
 */

// 1. Locate Node.js executable on the server
function findNodeBinary() {
    $candidates = [
        '/opt/alt/alt-nodejs22/root/usr/bin/node',
        '/opt/alt/alt-nodejs20/root/usr/bin/node',
        '/opt/alt/alt-nodejs18/root/usr/bin/node',
        '/opt/alt/alt-nodejs16/root/usr/bin/node',
        '/usr/local/bin/node',
        '/usr/bin/node',
        'node'
    ];
    
    // Check which node via shell
    $which = @trim(shell_exec('which node 2>/dev/null') ?: '');
    if ($which && @is_executable($which)) {
        return $which;
    }
    
    foreach ($candidates as $bin) {
        if (@is_executable($bin)) {
            return $bin;
        }
    }
    
    // Fallback to default CloudLinux Node 18
    return '/opt/alt/alt-nodejs18/root/usr/bin/node';
}

// 2. Locate the whatsapp_qr_bot directory
function findBotDirectory() {
    $possiblePaths = [
        __DIR__ . '/whatsapp_qr_bot',
        __DIR__ . '/../whatsapp_qr_bot',
        dirname(__DIR__) . '/whatsapp_qr_bot',
        '/home2/apexpri8/public_html/whatsapp_qr_bot',
        '/home2/apexpri8/whatsapp_qr_bot',
        '/home2/apexpri8/whatsapp-bot',
        __DIR__
    ];
    
    foreach ($possiblePaths as $p) {
        if (file_exists($p . '/bot.js')) {
            return realpath($p);
        }
    }
    
    return __DIR__ . '/whatsapp_qr_bot';
}

// 3. Get running PID of bot.js
function getBotPids() {
    $output = @shell_exec("pgrep -f '[b]ot.js' 2>/dev/null");
    if ($output) {
        $pids = array_filter(array_map('trim', explode("\n", trim($output))));
        return $pids;
    }
    
    // Fallback using ps
    $ps = @shell_exec("ps aux 2>/dev/null | grep '[b]ot.js'");
    if ($ps) {
        $pids = [];
        foreach (explode("\n", trim($ps)) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (isset($parts[1]) && is_numeric($parts[1])) {
                $pids[] = $parts[1];
            }
        }
        return array_unique($pids);
    }
    
    return [];
}

// 4. Start the bot in background
function startBot($nodeBin, $botDir) {
    $logFile = $botDir . '/bot.log';
    $cmd = sprintf(
        "cd %s && nohup %s bot.js > %s 2>&1 < /dev/null & echo $!",
        escapeshellarg($botDir),
        escapeshellarg($nodeBin),
        escapeshellarg($logFile)
    );
    $pid = @trim(shell_exec($cmd));
    return $pid;
}

// 5. Stop the bot
function stopBot() {
    @shell_exec("pkill -9 -f '[b]ot.js' 2>/dev/null");
}

$nodeBin = findNodeBinary();
$botDir  = findBotDirectory();
$logFile = $botDir . '/bot.log';
$qrFile  = $botDir . '/qr.png';
$pids    = getBotPids();
$isRunning = !empty($pids);

// Handle Actions (Start / Stop / Restart)
$action = $_GET['action'] ?? ($argv[1] ?? 'status');

if ($action === 'start') {
    if (!$isRunning) {
        startBot($nodeBin, $botDir);
        sleep(2);
        header('Location: cron_bot.php?started=1');
        exit;
    }
} elseif ($action === 'stop') {
    stopBot();
    sleep(1);
    header('Location: cron_bot.php?stopped=1');
    exit;
} elseif ($action === 'restart') {
    stopBot();
    sleep(1);
    startBot($nodeBin, $botDir);
    sleep(2);
    header('Location: cron_bot.php?restarted=1');
    exit;
}

// Cron Mode (run via curl or cPanel Cron Job)
$isCli = (php_sapi_name() === 'cli');
if ($action === 'cron' || $isCli) {
    header('Content-Type: text/plain');
    if ($isRunning) {
        echo "[OK] WhatsApp Bot is already running (PID: " . implode(', ', $pids) . ")\n";
    } else {
        $newPid = startBot($nodeBin, $botDir);
        echo "[RESTARTED] WhatsApp Bot was offline. Started successfully (PID: $newPid)\n";
    }
    exit;
}

// Read log tail for web UI
$logContent = 'No logs available yet.';
if (file_exists($logFile)) {
    $lines = file($logFile);
    $lastLines = array_slice($lines, -40);
    $logContent = htmlspecialchars(implode('', $lastLines));
}

// Read QR code as base64 for web UI
$qrBase64 = null;
if (file_exists($qrFile)) {
    $qrBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($qrFile));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apex WhatsApp Bot — 24/7 Server Control</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b141a; color: #e9edef; margin: 0; padding: 24px; }
        .container { max-width: 760px; margin: 0 auto; }
        .card { background: #111b21; border: 1px solid #202c33; border-radius: 16px; padding: 28px; margin-bottom: 20px; box-shadow: 0 8px 24px rgba(0,0,0,0.4); }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        h1 { margin: 0; font-size: 22px; color: #fff; }
        .status-badge { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; padding: 6px 16px; border-radius: 999px; }
        .status-on { background: rgba(37, 211, 102, 0.15); color: #25d366; }
        .status-off { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .dot-on { background: #25d366; box-shadow: 0 0 10px #25d366; }
        .dot-off { background: #ef4444; }
        .btn-group { display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap; }
        .btn { padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-block; transition: all 0.2s; }
        .btn-start { background: #25d366; color: #0b141a; }
        .btn-restart { background: #202c33; color: #e9edef; border: 1px solid #3b4a54; }
        .btn-stop { background: #ef4444; color: #fff; }
        .btn:hover { opacity: 0.85; }
        .qr-section { text-align: center; padding: 20px; background: #202c33; border-radius: 12px; margin-top: 20px; }
        .qr-img { background: #fff; padding: 12px; border-radius: 8px; width: 240px; height: 240px; margin: 12px auto; display: block; }
        .code-box { background: #0a1014; border: 1px solid #202c33; border-radius: 8px; padding: 14px; font-family: Consolas, monospace; font-size: 13px; color: #00a884; overflow-x: auto; word-break: break-all; }
        .log-box { background: #0a1014; border: 1px solid #202c33; border-radius: 8px; padding: 14px; font-family: Consolas, monospace; font-size: 12px; color: #8696a0; max-height: 250px; overflow-y: auto; white-space: pre-wrap; }
        .label { font-size: 12px; text-transform: uppercase; color: #8696a0; letter-spacing: 0.5px; margin-bottom: 8px; font-weight: 600; }
    </style>
</head>
<body>
<div class="container">

    <!-- Status Card -->
    <div class="card">
        <div class="header">
            <div>
                <h1>🤖 WhatsApp Bot 24/7 Manager</h1>
                <p style="margin:4px 0 0 0; color:#8696a0; font-size:13px;">Runs completely on your server — laptop can stay powered off.</p>
            </div>
            <?php if ($isRunning): ?>
                <div class="status-badge status-on"><span class="dot dot-on"></span> ONLINE 24/7 (PID: <?= implode(', ', $pids) ?>)</div>
            <?php else: ?>
                <div class="status-badge status-off"><span class="dot dot-off"></span> STOPPED</div>
            <?php endif; ?>
        </div>

        <div class="btn-group">
            <?php if (!$isRunning): ?>
                <a href="cron_bot.php?action=start" class="btn btn-start">▶ Start Bot</a>
            <?php else: ?>
                <a href="cron_bot.php?action=restart" class="btn btn-restart">🔄 Restart Bot</a>
                <a href="cron_bot.php?action=stop" class="btn btn-stop" onclick="return confirm('Stop the bot?')">⏹ Stop Bot</a>
            <?php endif; ?>
            <a href="cron_bot.php" class="btn btn-restart">🔄 Refresh Page</a>
        </div>
    </div>

    <!-- QR Code Section -->
    <?php if ($qrBase64): ?>
    <div class="card">
        <div class="label">Link WhatsApp Device</div>
        <div class="qr-section">
            <h3 style="margin:0 0 6px 0;">Scan to Connect WhatsApp</h3>
            <p style="margin:0 0 12px 0; color:#8696a0; font-size:13px;">Open WhatsApp > Linked Devices > Link a Device > Point camera here:</p>
            <img class="qr-img" src="<?= $qrBase64 ?>" alt="WhatsApp QR Code">
            <p style="color:#8696a0; font-size:12px; margin:8px 0 0 0;">Once connected, you can close this page and the bot stays online 24/7.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Cron Job Command to Copy -->
    <div class="card">
        <div class="label">⏰ Keep-Alive Cron Job (Add in cPanel Cron Jobs)</div>
        <p style="margin:0 0 10px 0; font-size:13px; color:#8696a0;">
            Add this command to your cPanel <strong>Cron Jobs</strong> set to run <strong>Every 5 Minutes</strong> (<code>*/5 * * * *</code>). It ensures the bot never stops:
        </p>
        <div class="code-box">curl -s "https://<?= $_SERVER['HTTP_HOST'] ?>/cron_bot.php?action=cron" &gt; /dev/null 2&gt;&amp;1</div>
        <p style="margin:10px 0 0 0; font-size:12px; color:#8696a0;">
            Node path detected: <code style="color:#25d366;"><?= htmlspecialchars($nodeBin) ?></code> | Bot folder: <code style="color:#25d366;"><?= htmlspecialchars($botDir) ?></code>
        </p>
    </div>

    <!-- Live Logs -->
    <div class="card">
        <div class="label">Live Bot Output Log</div>
        <div class="log-box"><?= $logContent ?></div>
    </div>

</div>
</body>
</html>
