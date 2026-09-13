<?php
/**
 * Apex Prime — Alexa Covert WhatsApp Bot Activation
 * 
 * User-facing activation dashboard:
 * - GHS 2.00 activation fee debited from user wallet
 * - WhatsApp Phone Number Pairing Code (No QR required)
 * - Anti-Delete, Saved Views, Auto-Status, Media Downloaders & Music Player
 */

require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }

if (empty($_SESSION['user'])) {
    header('Location: ' . APP_URL . 'login');
    exit;
}

$user   = $_SESSION['user'];
$userId = (int)$user['id'];
$pdo    = db_connect();
$pageTitle = 'WhatsApp Bot';

// Refresh user details from DB
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$userId]);
$dbUser = $uStmt->fetch(PDO::FETCH_ASSOC) ?: $user;
$walletBal = (float)($dbUser['wallet_balance'] ?? 0);
$userPhone = $dbUser['phone'] ?? '';

// Fetch all connected and historical accounts for this user (Multi-account support)
$allBotsStmt = $pdo->prepare("SELECT * FROM user_whatsapp_bots WHERE user_id = ? ORDER BY id DESC");
$allBotsStmt->execute([$userId]);
$allBotRecords = $allBotsStmt->fetchAll(PDO::FETCH_ASSOC);

// Primary / active bot record is either the first connected one, or the latest record
$connectedBotRecord = null;
$connectedCount = 0;
foreach ($allBotRecords as $r) {
    if ($r['status'] === 'connected') {
        $connectedCount++;
        if (!$connectedBotRecord) $connectedBotRecord = $r;
    }
}
$botRecord = $connectedBotRecord ?: ($allBotRecords[0] ?? null);

$isBotConnected = ($botRecord && $botRecord['status'] === 'connected');
$existingPendingCode = '';
$existingPendingPhone = '';
$remainingSeconds = 120;
if ($botRecord && $botRecord['status'] === 'pending' && !empty($botRecord['pairing_code'])) {
    $createdTime = !empty($botRecord['updated_at']) ? strtotime($botRecord['updated_at']) : strtotime($botRecord['created_at']);
    $elapsed = time() - $createdTime;
    if ($elapsed < 120) {
        $existingPendingCode = $botRecord['pairing_code'];
        $existingPendingPhone = $botRecord['phone_number'] ?? '';
        $remainingSeconds = max(5, 120 - $elapsed);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle) ?> · <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="theme-color" content="#059669">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/style.css?v=<?= file_exists(__DIR__ . '/css/style.css') ? filemtime(__DIR__ . '/css/style.css') : time() ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        body, .dash2 {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            font-size: 14px;
            color: var(--text-primary, #0d0f1a);
            font-weight: 400 !important;
            -webkit-font-smoothing: antialiased;
        }
        .dash2 .container { padding: 0.875rem; max-width: 860px; margin: 0 auto; }

        h1, h2, h3, h4, h5, h6 {
            font-weight: 600 !important;
        }
        b, strong {
            font-weight: 600 !important;
        }

        /* Custom SweetAlert Notice Styling (Like Data Delivered notice) */
        .rounded-notice-card {
            border-radius: 28px !important;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2) !important;
            padding: 2rem 1.75rem !important;
        }
        .rounded-notice-card .swal2-icon {
            display: none !important;
        }
        .rounded-notice-card .swal2-title {
            font-size: 1.35rem !important;
            font-weight: 700 !important;
            color: #ffffff !important;
            margin: 0 0 0.5rem 0 !important;
            padding: 0 !important;
        }
        .rounded-notice-card .swal2-html-container {
            font-size: 0.95rem !important;
            font-weight: 400 !important;
            line-height: 1.55 !important;
            color: #ffffff !important;
            margin: 0.5rem 0 1.25rem 0 !important;
            padding: 0 !important;
        }
        .rounded-notice-card .swal2-actions {
            margin: 0.5rem 0 0 0 !important;
            gap: 0.5rem !important;
        }
        .rounded-notice-btn {
            border-radius: 99px !important;
            padding: 0.65rem 1.6rem !important;
            background-color: #ffffff !important;
            color: #059669 !important;
            font-weight: 600 !important;
            border: none !important;
            box-shadow: 0 4px 14px rgba(0,0,0,0.12) !important;
            cursor: pointer !important;
            font-size: 0.9rem !important;
        }
        .rounded-notice-cancel-btn {
            border-radius: 99px !important;
            padding: 0.65rem 1.3rem !important;
            background-color: rgba(255,255,255,0.2) !important;
            color: #ffffff !important;
            font-weight: 500 !important;
            border: 1px solid rgba(255,255,255,0.4) !important;
            cursor: pointer !important;
            font-size: 0.9rem !important;
        }

        /* Hero Banner */
        .hero-alexa {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 45%, #059669 100%);
            border-radius: 20px;
            padding: 1.5rem 1.6rem;
            color: #fff;
            position: relative;
            overflow: hidden;
            margin-bottom: 1.25rem;
            box-shadow: 0 14px 30px -6px rgba(5, 150, 105, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .hero-alexa::after {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .badge-fee {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #fff;
            padding: 0.3rem 0.8rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        /* Glass Card */
        .card-custom {
            background: var(--card-bg, #ffffff);
            border: 1.5px solid var(--border-color, #e2e8f0);
            border-radius: 18px;
            padding: 1.4rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 4px 16px rgba(0,0,0,0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        /* Segmented Pairing Code Boxes (Minimized & Responsive) */
        .code-outer-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 0.85rem 0.6rem;
            margin: 0.75rem auto 1rem auto;
            max-width: 360px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pairing-code-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            margin: 0;
            flex-wrap: nowrap !important;
            width: 100%;
        }
        .code-box-char {
            width: 34px;
            height: 44px;
            max-width: 34px;
            flex: 0 0 34px;
            background: #ffffff;
            border: 1.5px solid #059669;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            font-weight: 700;
            font-family: monospace;
            color: #065f46;
            box-shadow: 0 2px 6px rgba(5, 150, 105, 0.12);
            text-transform: uppercase;
            transition: all 0.2s ease;
        }
        .code-box-char:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(5, 150, 105, 0.22);
            border-color: #10b981;
        }
        .code-separator {
            font-size: 1.2rem;
            font-weight: 700;
            color: #059669;
            margin: 0 0.15rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        @media (max-width: 380px) {
            .code-outer-card {
                padding: 0.65rem 0.4rem;
            }
            .pairing-code-wrapper {
                gap: 0.2rem;
            }
            .code-box-char {
                width: 29px;
                height: 38px;
                max-width: 29px;
                flex: 0 0 29px;
                font-size: 1.1rem;
                border-radius: 7px;
            }
            .code-separator {
                font-size: 1rem;
                margin: 0 0.08rem;
            }
        }

        /* Live countdown badge (Compact) */
        .countdown-live-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: #f0fdf4;
            color: #15803d;
            border: 1.5px solid #bbf7d0;
            font-size: 0.78rem;
            font-weight: 500;
            padding: 0.32rem 0.85rem;
            border-radius: 99px;
            margin-bottom: 0.65rem;
            transition: all 0.3s ease;
        }
        .countdown-live-badge.expiring-soon {
            background: #fef2f2 !important;
            color: #dc2626 !important;
            border-color: #fecaca !important;
            animation: pulse-border 1.2s infinite;
        }
        @keyframes pulse-border {
            0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.35); }
            50% { box-shadow: 0 0 0 6px rgba(220, 38, 38, 0.15); }
        }
        .pulse-dot-timer {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #22c55e;
            animation: blinker 1.2s infinite;
            display: inline-block;
        }
        .countdown-live-badge.expiring-soon .pulse-dot-timer {
            background: #dc2626 !important;
        }

        /* Nice Color Buttons (Minimized & Sleek) */
        .btn-nice-color {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: #ffffff !important;
            border: none;
            padding: 0.58rem 1.2rem;
            border-radius: 99px;
            font-weight: 600;
            font-size: 0.84rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.28);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            text-decoration: none;
        }
        .btn-nice-color:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(5, 150, 105, 0.38);
        }
        .btn-nice-color:active {
            transform: translateY(0);
        }

        .btn-wa-open {
            background: #2563eb;
            color: #ffffff !important;
            text-decoration: none;
            padding: 0.58rem 1.05rem;
            border-radius: 99px;
            font-weight: 600;
            font-size: 0.84rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.22);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .btn-wa-open:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.32);
        }

        /* Balance debited flash animation */
        .balance-debited-flash {
            animation: balanceFlash 1.5s ease;
        }
        @keyframes balanceFlash {
            0% { transform: scale(1); background-color: #dcfce7 !important; border-color: #22c55e !important; }
            50% { transform: scale(1.02); background-color: #bbf7d0 !important; border-color: #16a34a !important; }
            100% { transform: scale(1); }
        }

        /* Feature Pills */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 0.85rem;
            margin-top: 1rem;
        }
        .feature-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-weight: 400;
        }
        .feature-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        /* Pulse animation */
        .pulse-online {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 10px #22c55e;
            animation: blinker 1.5s infinite;
        }
        @keyframes blinker {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        /* Toggle switches */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .3s;
            border-radius: 24px;
        }
        .toggle-slider:before {
            position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .toggle-slider { background-color: #059669; }
        input:checked + .toggle-slider:before { transform: translateX(20px); }

        /* Command pill */
        .command-badge {
            background: #eff6ff;
            color: #2563eb;
            font-family: monospace;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.8rem;
            border: 1px solid #bfdbfe;
        }
    </style>
</head>
<body class="dash2">
<?php require_once __DIR__ . '/header.php'; ?>

<main class="container" style="padding-top: 1rem; padding-bottom: 3rem;">

    <!-- Hero Banner -->
    <div class="hero-alexa">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.75rem;">
            <div>
                <span class="badge-fee">
                    <i class="fab fa-whatsapp"></i> 24/7 ASSISTANT
                </span>
                <h1 style="font-size: 1.5rem; font-weight: 600; margin: 0.5rem 0 0.2rem 0; letter-spacing: -0.02em;">
                    WhatsApp Bot
                </h1>
                <p style="margin: 0; font-size: 0.85rem; color: rgba(255,255,255,0.9); max-width: 540px; line-height: 1.5; font-weight: 400;">
                    Your 24/7 WhatsApp assistant with TikTok & YouTube video downloaders, music player, anti-delete protection, and status auto-reactions.
                </p>
            </div>
            <div style="text-align: right; background: rgba(0,0,0,0.2); padding: 0.5rem 0.9rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.15);">
                <div style="font-size: 0.7rem; color: rgba(255,255,255,0.8); text-transform: uppercase; font-weight: 500;">Activation Fee</div>
                <div style="font-size: 1.25rem; font-weight: 600; color: #fff;">GHS 2.00</div>
            </div>
        </div>
    </div>

    <!-- Wallet Balance Pill -->
    <div id="walletBalanceWrapper" style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 0.85rem 1.15rem; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem; transition: all 0.3s ease;">
        <div style="display: flex; align-items: center; gap: 0.6rem;">
            <div style="width: 38px; height: 38px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                <i class="fas fa-wallet"></i>
            </div>
            <div>
                <div style="font-size: 0.72rem; color: #64748b; font-weight: 500; text-transform: uppercase;">Available Wallet Balance</div>
                <div style="font-size: 1.15rem; font-weight: 600; color: #0f172a;" id="displayWalletBal">
                    GHS <?= number_format($walletBal, 2) ?>
                </div>
            </div>
        </div>
        <?php if ($walletBal < 2.00): ?>
            <a href="<?= APP_URL ?>topup" class="btn" style="background: #2563eb; color: #fff; padding: 0.45rem 1rem; border-radius: 99px; text-decoration: none; font-size: 0.8rem; font-weight: 500; display: inline-flex; align-items: center; gap: 0.4rem;">
                <i class="fas fa-plus-circle"></i> Top Up GHS <?= number_format(2.00 - $walletBal, 2) ?>
            </a>
        <?php else: ?>
            <span style="font-size: 0.78rem; font-weight: 500; color: #16a34a; background: #dcfce7; padding: 0.35rem 0.8rem; border-radius: 99px; border: 1px solid #86efac;">
                <i class="fas fa-check-circle"></i> Balance Sufficient
            </span>
        <?php endif; ?>
    </div>

    <!-- Activation Card (Phone Number Pairing) -->
    <div class="card-custom" id="activationCard" style="<?= $isBotConnected ? 'display:none;' : '' ?>">
        <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.5rem;">
            <div style="width: 32px; height: 32px; border-radius: 8px; background: #dcfce7; color: #059669; display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                <i class="fas fa-link"></i>
            </div>
            <h2 style="font-size: 1.1rem; font-weight: 600; color: #0f172a; margin: 0;">Link With Phone Number</h2>
        </div>
        <p style="font-size: 0.82rem; color: #64748b; margin: 0 0 1.25rem 0; line-height: 1.5; font-weight: 400;">
            Enter your WhatsApp phone number below. The system will charge <strong>GHS 2.00</strong> from your wallet and generate an <strong>8-character verification code</strong> to link directly on your WhatsApp without scanning any QR code.
        </p>

        <form id="botPairingForm" onsubmit="event.preventDefault(); requestPairingCode();">
            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; font-size: 0.75rem; font-weight: 500; color: #334155; text-transform: uppercase; margin-bottom: 0.4rem;">
                    Your WhatsApp Phone Number <span style="color:#dc2626;">*</span>
                </label>
                <div style="display: flex; align-items: center; border: 1.5px solid #cbd5e1; border-radius: 12px; overflow: hidden; background: #fff;">
                    <div style="padding: 0.75rem 0.9rem; background: #f1f5f9; border-right: 1.5px solid #cbd5e1; color: #475569; font-weight: 500; font-size: 0.88rem;">
                        🇬🇭 +233
                    </div>
                    <input type="tel" id="botPhoneInput" value="<?= htmlspecialchars($userPhone, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 0553381853" required style="width: 100%; border: none; padding: 0.75rem 0.9rem; font-size: 0.95rem; font-weight: 500; outline: none; color: #0f172a;">
                </div>
                <small style="display: block; color: #94a3b8; font-size: 0.72rem; margin-top: 0.35rem; font-weight: 400;">Enter phone in format 0553381853 or 233553381853.</small>
            </div>

            <button type="submit" id="btnRequestPairing" style="width: 100%; padding: 0.85rem; border-radius: 12px; background: linear-gradient(135deg, #059669 0%, #047857 100%); border: none; color: #fff; font-weight: 600; font-size: 0.92rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem; box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35); transition: opacity 0.2s;">
                <i class="fab fa-whatsapp" style="font-size: 1.2rem;"></i> Generate Linking Code (GHS 2.00)
            </button>
        </form>

        <!-- Dynamic Pairing Code Section (Shown once code generated) -->
        <div id="pairingCodeDisplaySection" style="display: none; margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1.5px dashed #e2e8f0; text-align: center;">
            
            <!-- Live Animated Countdown Badge -->
            <div>
                <div id="liveCountdownWrapper" class="countdown-live-badge">
                    <span class="pulse-dot-timer"></span>
                    <span id="countdownLabelText">Code expires in:</span>
                    <span id="codeCountdown" style="font-family: monospace; font-weight: 700; font-size: 0.85rem; margin-left: 2px;">01:00</span>
                </div>
            </div>

            <h3 style="font-size: 0.98rem; font-weight: 600; color: #0f172a; margin: 0 0 0.25rem 0;">Enter this 8-digit code on WhatsApp:</h3>
            <div style="margin: 0.35rem 0 0.65rem 0;">
                <span style="display: inline-flex; align-items: center; gap: 6px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; font-size: 0.8rem; font-weight: 600; padding: 4px 12px; border-radius: 999px;">
                    <i class="fab fa-whatsapp" style="color: #059669;"></i> Target Phone: <span id="displayTargetPhone" style="font-family: monospace; font-size: 0.88rem; letter-spacing: 0.03em;">+233559623850</span>
                </span>
            </div>
            <p style="font-size: 0.76rem; color: #64748b; margin: 0 0 0.5rem 0; font-weight: 400; line-height: 1.5;">
                Open WhatsApp &gt; Linked Devices &gt; Link a Device &gt; <strong>Link with phone number instead</strong>:
            </p>

            <!-- Critical Instruction Tip for WhatsApp App -->
            <div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 12px; padding: 0.65rem 0.95rem; margin: 0.4rem auto 0.85rem auto; max-width: 480px; text-align: left;">
                <div style="font-weight: 700; color: #1e40af; font-size: 0.82rem; margin-bottom: 3px; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-info-circle" style="color: #2563eb;"></i> On your phone's 'Enter code' screen:
                </div>
                <div style="font-size: 0.76rem; color: #1e3a8a; line-height: 1.45;">
                    Type <strong>ONLY the 8-character code</strong> shown below into the 8 empty boxes on your WhatsApp screen.
                </div>
            </div>

            <!-- Minimized 8-character segmented display card (guaranteed single-row on all screens) -->
            <div class="code-outer-card">
                <div class="pairing-code-wrapper" id="pairingBoxes">
                    <!-- Segmented boxes injected by JavaScript -->
                </div>
            </div>

            <!-- Nice Color Buttons: Display & Copy the 8-Digit Code (Compact & Sleek) -->
            <div style="display: flex; gap: 0.5rem; justify-content: center; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem;">
                <button type="button" id="btnCopyCode" onclick="copyPairingCode()" class="btn-nice-color">
                    <i class="fas fa-copy"></i>
                    <span>Copy:</span>
                    <strong id="btnCodeText" style="letter-spacing: 0.08em; font-family: monospace; font-size: 0.95rem; background: rgba(255,255,255,0.25); padding: 0.15rem 0.55rem; border-radius: 6px;">--------</strong>
                </button>

                <a href="whatsapp://" class="btn-wa-open">
                    <i class="fab fa-whatsapp" style="font-size: 1rem;"></i>
                    <span>Open WhatsApp</span>
                </a>
            </div>

            <!-- Instructions Card -->
            <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 1.15rem; text-align: left;">
                <div style="font-weight: 600; font-size: 0.85rem; color: #0f172a; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem;">
                    <i class="fas fa-mobile-alt" style="color: #059669;"></i> Steps to link on your phone:
                </div>
                <ol style="margin: 0; padding-left: 1.25rem; font-size: 0.8rem; color: #475569; line-height: 1.8; font-weight: 400;">
                    <li>Open <strong>WhatsApp</strong> on your phone.</li>
                    <li>Tap <strong>Settings</strong> (iOS) or <strong>⋮ (Three dots)</strong> (Android) &gt; <strong>Linked Devices</strong>.</li>
                    <li>Tap <strong>Link a Device</strong>.</li>
                    <li>Tap <strong>"Link with phone number instead"</strong> at the bottom of the camera screen.</li>
                    <li>Type the <strong>8-character code</strong> shown above.</li>
                </ol>
            </div>

            <div style="margin-top: 1.25rem; display: flex; align-items: center; justify-content: center; gap: 0.6rem; color: #64748b; font-size: 0.8rem; font-weight: 400;">
                <div class="pulse-online"></div>
                <span>Waiting for WhatsApp authorization... page will auto-update when connected.</span>
            </div>
        </div>
    </div>

    <!-- Connected Account History Card (Multi-Account Management) -->
    <div class="card-custom" id="connectedAccountsHistoryCard" style="margin-top: 1.25rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.15rem;">
            <div style="display: flex; align-items: center; gap: 0.6rem;">
                <div style="width: 34px; height: 34px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1.05rem;">
                    <i class="fas fa-history"></i>
                </div>
                <div>
                    <h3 style="font-size: 1.05rem; font-weight: 600; color: #0f172a; margin: 0;">Connected Account History</h3>
                    <div style="font-size: 0.75rem; color: #64748b;">Manage all your linked WhatsApp numbers and personal bot utilities</div>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <span class="badge" style="background: #f1f5f9; color: #334155; font-weight: 600; padding: 0.35rem 0.75rem; border-radius: 99px; font-size: 0.75rem; border: 1px solid #cbd5e1;">
                    <span id="accountCountLabel"><?= count($allBotRecords) ?></span> <?= count($allBotRecords) === 1 ? 'Number' : 'Numbers' ?> Registered
                </span>
                <button type="button" onclick="showLinkNewForm()" class="btn" style="background: linear-gradient(135deg, #059669 0%, #047857 100%); color: #fff; border: none; border-radius: 8px; padding: 0.45rem 0.85rem; font-size: 0.78rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem; box-shadow: 0 2px 8px rgba(5, 150, 105, 0.25);">
                    <i class="fas fa-plus-circle"></i> Link Another Number
                </button>
            </div>
        </div>

        <!-- Accounts History List Container -->
        <div id="accountsHistoryList" style="display: flex; flex-direction: column; gap: 0.85rem;">
            <?php if (empty($allBotRecords)): ?>
                <div style="text-align: center; padding: 2rem 1rem; background: #f8fafc; border-radius: 12px; border: 1.5px dashed #cbd5e1;">
                    <div style="width: 48px; height: 48px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; margin: 0 auto 0.75rem auto;">
                        <i class="fab fa-whatsapp"></i>
                    </div>
                    <div style="font-weight: 600; color: #0f172a; font-size: 0.92rem; margin-bottom: 0.25rem;">No WhatsApp Accounts Linked Yet</div>
                    <p style="font-size: 0.78rem; color: #64748b; margin: 0 0 1rem 0;">Enter your WhatsApp phone number above to generate an 8-character pairing code and link your first number.</p>
                    <button type="button" onclick="showLinkNewForm()" class="btn" style="background: #059669; color: #fff; padding: 0.45rem 1rem; border-radius: 8px; font-size: 0.8rem; font-weight: 600; border: none; cursor: pointer;">
                        <i class="fas fa-plus"></i> Link Phone Number
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($allBotRecords as $acc): 
                    $accStatus = $acc['status'] ?? 'pending';
                    $accPhone  = $acc['phone_number'] ?? '';
                    $isAccConnected = ($accStatus === 'connected');
                    $isAccPending   = ($accStatus === 'pending');
                    $isAccExpired   = ($accStatus === 'expired');
                    $isPersMode     = empty($acc['autoreply']);
                ?>
                <div class="account-history-row" id="accRow_<?= $acc['id'] ?>" style="background: #ffffff; border: 1.5px solid <?= $isAccConnected ? '#86efac' : '#e2e8f0' ?>; border-radius: 14px; padding: 1rem 1.15rem; transition: all 0.2s; box-shadow: <?= $isAccConnected ? '0 4px 12px rgba(34, 197, 94, 0.08)' : '0 1px 3px rgba(0,0,0,0.02)' ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.75rem;">
                        <!-- Left Info -->
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                            <div style="width: 42px; height: 42px; border-radius: 10px; background: <?= $isAccConnected ? '#dcfce7' : ($isAccPending ? '#fef9c3' : '#f1f5f9') ?>; color: <?= $isAccConnected ? '#15803d' : ($isAccPending ? '#854d0e' : '#64748b') ?>; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;">
                                <i class="fab fa-whatsapp"></i>
                            </div>
                            <div>
                                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                    <span style="font-size: 1.02rem; font-weight: 700; color: #0f172a; font-family: monospace; letter-spacing: 0.02em;">
                                        +<?= htmlspecialchars($accPhone) ?>
                                    </span>
                                    
                                    <!-- Status Pill -->
                                    <?php if ($isAccConnected): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; background: #dcfce7; color: #15803d; border: 1px solid #86efac; border-radius: 99px; padding: 2px 8px; font-size: 0.7rem; font-weight: 600;">
                                            <span style="width: 7px; height: 7px; border-radius: 50%; background: #22c55e; display: inline-block;"></span> Active & Online
                                        </span>
                                    <?php elseif ($isAccPending): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; background: #fef9c3; color: #854d0e; border: 1px solid #fde047; border-radius: 99px; padding: 2px 8px; font-size: 0.7rem; font-weight: 600;">
                                            <i class="fas fa-clock" style="font-size: 0.65rem;"></i> Pairing Pending
                                        </span>
                                    <?php elseif ($isAccExpired): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; border-radius: 99px; padding: 2px 8px; font-size: 0.7rem; font-weight: 600;">
                                            <i class="fas fa-exclamation-circle" style="font-size: 0.65rem;"></i> Expired Code
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; border-radius: 99px; padding: 2px 8px; font-size: 0.7rem; font-weight: 600;">
                                            <i class="fas fa-unlink" style="font-size: 0.65rem;"></i> Disconnected
                                        </span>
                                    <?php endif; ?>

                                    <!-- Mode Tag -->
                                    <span style="display: inline-flex; align-items: center; gap: 3px; background: <?= $isPersMode ? '#f8fafc' : '#eff6ff' ?>; color: <?= $isPersMode ? '#0f766e' : '#1d4ed8' ?>; border: 1px solid <?= $isPersMode ? '#99f6e4' : '#bfdbfe' ?>; border-radius: 99px; padding: 2px 8px; font-size: 0.68rem; font-weight: 500;">
                                        <i class="fas <?= $isPersMode ? 'fa-user-shield' : 'fa-store' ?>" style="font-size: 0.65rem;"></i>
                                        <?= $isPersMode ? 'Personal Bot (Auto-reply Silenced)' : 'Business SQR Bot' ?>
                                    </span>
                                </div>

                                <!-- Metadata -->
                                <div style="display: flex; align-items: center; gap: 0.85rem; font-size: 0.74rem; color: #64748b; margin-top: 0.35rem; flex-wrap: wrap;">
                                    <span><i class="far fa-calendar-alt"></i> Linked: <strong><?= date('d M Y, h:i A', strtotime($acc['created_at'])) ?></strong></span>
                                    <?php if (!empty($acc['connected_at'])): ?>
                                        <span><i class="fas fa-check-double" style="color: #16a34a;"></i> Connected: <strong><?= date('d M Y, h:i A', strtotime($acc['connected_at'])) ?></strong></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-tag"></i> Fee: <strong>GHS <?= number_format((float)($acc['fee_paid'] ?? 2.00), 2) ?></strong></span>
                                    <?php if (!empty($acc['transaction_ref'])): ?>
                                        <span>Ref: <code style="font-size: 0.7rem; background: #f1f5f9; padding: 1px 4px; border-radius: 4px;"><?= htmlspecialchars($acc['transaction_ref']) ?></code></span>
                                    <?php endif; ?>
                                </div>

                                <!-- Active Feature Badges -->
                                <div style="display: flex; gap: 0.35rem; margin-top: 0.5rem; flex-wrap: wrap;">
                                    <span style="font-size: 0.68rem; padding: 2px 7px; border-radius: 6px; background: <?= !empty($acc['antidelete']) ? '#ecfdf5' : '#f1f5f9' ?>; color: <?= !empty($acc['antidelete']) ? '#059669' : '#94a3b8' ?>; border: 1px solid <?= !empty($acc['antidelete']) ? '#a7f3d0' : '#e2e8f0' ?>;">
                                        🛡️ Anti-Delete <?= !empty($acc['antidelete']) ? 'ON' : 'OFF' ?>
                                    </span>
                                    <span style="font-size: 0.68rem; padding: 2px 7px; border-radius: 6px; background: <?= !empty($acc['savedviews']) ? '#ecfdf5' : '#f1f5f9' ?>; color: <?= !empty($acc['savedviews']) ? '#059669' : '#94a3b8' ?>; border: 1px solid <?= !empty($acc['savedviews']) ? '#a7f3d0' : '#e2e8f0' ?>;">
                                        📸 View-Once <?= !empty($acc['savedviews']) ? 'ON' : 'OFF' ?>
                                    </span>
                                    <span style="font-size: 0.68rem; padding: 2px 7px; border-radius: 6px; background: <?= !empty($acc['autoview_status']) ? '#ecfdf5' : '#f1f5f9' ?>; color: <?= !empty($acc['autoview_status']) ? '#059669' : '#94a3b8' ?>; border: 1px solid <?= !empty($acc['autoview_status']) ? '#a7f3d0' : '#e2e8f0' ?>;">
                                        👀 Auto-View <?= !empty($acc['autoview_status']) ? 'ON' : 'OFF' ?>
                                    </span>
                                    <span style="font-size: 0.68rem; padding: 2px 7px; border-radius: 6px; background: <?= !empty($acc['autolike_status']) ? '#ecfdf5' : '#f1f5f9' ?>; color: <?= !empty($acc['autolike_status']) ? '#059669' : '#94a3b8' ?>; border: 1px solid <?= !empty($acc['autolike_status']) ? '#a7f3d0' : '#e2e8f0' ?>;">
                                        ❤️ Auto-Like <?= !empty($acc['autolike_status']) ? 'ON' : 'OFF' ?>
                                    </span>
                                    <span style="font-size: 0.68rem; padding: 2px 7px; border-radius: 6px; background: <?= !empty($acc['music_enabled']) ? '#ecfdf5' : '#f1f5f9' ?>; color: <?= !empty($acc['music_enabled']) ? '#059669' : '#94a3b8' ?>; border: 1px solid <?= !empty($acc['music_enabled']) ? '#a7f3d0' : '#e2e8f0' ?>;">
                                        🎵 Music <?= !empty($acc['music_enabled']) ? 'ON' : 'OFF' ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Right Actions -->
                        <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                            <?php if ($isAccConnected): ?>
                                <button type="button" onclick="openAccountPreferences(<?= $acc['id'] ?>, '<?= htmlspecialchars($accPhone) ?>', <?= (int)$acc['antidelete'] ?>, <?= (int)$acc['savedviews'] ?>, <?= (int)$acc['autoview_status'] ?>, <?= (int)$acc['autolike_status'] ?>, <?= (int)$acc['downloader_enabled'] ?>, <?= (int)$acc['music_enabled'] ?>, <?= (int)$acc['autoreply'] ?>)" class="btn btn-sm" style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 8px; padding: 0.4rem 0.8rem; font-size: 0.75rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    <i class="fas fa-sliders-h"></i> Preferences
                                </button>
                                <button type="button" onclick="disconnectSpecificBot(<?= $acc['id'] ?>, '<?= htmlspecialchars($accPhone) ?>')" class="btn btn-sm" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; border-radius: 8px; padding: 0.4rem 0.8rem; font-size: 0.75rem; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    <i class="fas fa-unlink"></i> Disconnect
                                </button>
                            <?php elseif ($isAccPending): ?>
                                <button type="button" onclick="relinkNumber('<?= htmlspecialchars($accPhone) ?>', true)" class="btn btn-sm" style="background: #fef9c3; color: #854d0e; border: 1px solid #fde047; border-radius: 8px; padding: 0.4rem 0.8rem; font-size: 0.75rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    <i class="fas fa-key"></i> Show Code
                                </button>
                            <?php else: ?>
                                <button type="button" onclick="relinkNumber('<?= htmlspecialchars($accPhone) ?>')" class="btn btn-sm" style="background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; border-radius: 8px; padding: 0.4rem 0.8rem; font-size: 0.75rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    <i class="fas fa-redo-alt"></i> Re-link
                                </button>
                                <button type="button" onclick="removeAccountRecord(<?= $acc['id'] ?>, '<?= htmlspecialchars($accPhone) ?>')" class="btn btn-sm" style="background: #f8fafc; color: #94a3b8; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.4rem 0.6rem; font-size: 0.75rem; cursor: pointer;" title="Remove from list">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Active Connected Bot Card (Shown when connected) -->
    <div class="card-custom" id="connectedCard" style="<?= !$isBotConnected ? 'display:none;' : '' ?>">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.25rem;">
            <div style="display: flex; align-items: center; gap: 0.6rem;">
                <div class="pulse-online"></div>
                <div>
                    <h2 style="font-size: 1.1rem; font-weight: 600; color: #0f172a; margin: 0;">WhatsApp Bot Is Connected!</h2>
                    <div style="font-size: 0.78rem; color: #059669; font-weight: 500;" id="connectedPhoneLabel">
                        Connected Account: +<?= htmlspecialchars($botRecord['phone_number'] ?? $userPhone) ?>
                    </div>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <button type="button" onclick="showLinkNewForm()" class="btn btn-sm" style="background: #f1f5f9; color: #059669; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.45rem 0.85rem; font-weight: 600; font-size: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <i class="fas fa-plus-circle"></i> Link Another
                </button>
                <button type="button" onclick="disconnectBot()" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; border-radius: 8px; padding: 0.45rem 0.85rem; font-weight: 500; font-size: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <i class="fas fa-unlink"></i> Disconnect Device
                </button>
            </div>
        </div>

        <div style="border-top: 1px solid #e2e8f0; padding-top: 1.15rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.85rem;">
                <h3 style="font-size: 0.88rem; font-weight: 600; color: #0f172a; margin: 0;">WhatsApp Bot Preferences</h3>
                <span id="prefAccountNotice" style="font-size: 0.74rem; color: #2563eb; font-weight: 500; background: #eff6ff; border: 1px solid #bfdbfe; padding: 0.2rem 0.6rem; border-radius: 6px;">
                    Configuring: <strong id="prefAccountPhoneText">+<?= htmlspecialchars($botRecord['phone_number'] ?? $userPhone) ?></strong>
                </span>
            </div>
            
            <form id="botSettingsForm" onsubmit="event.preventDefault(); updateBotSettings();">
                <input type="hidden" name="bot_id" id="pref_bot_id" value="<?= (int)($botRecord['id'] ?? 0) ?>">
                <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                    <!-- Anti-Delete -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: 500; font-size: 0.85rem; color: #0f172a;">🛡️ Anti-Delete Message Recovery</div>
                            <div style="font-size: 0.74rem; color: #64748b; font-weight: 400;">Forwards deleted messages and revoked photos back to you.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="antidelete" id="pref_antidelete" <?= !empty($botRecord['antidelete']) ? 'checked' : '' ?> onchange="updateBotSettings()">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <!-- View-Once Saver -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: 500; font-size: 0.85rem; color: #0f172a;">📸 Anti View-Once Media Saver</div>
                            <div style="font-size: 0.74rem; color: #64748b; font-weight: 400;">Automatically captures and permanently saves view-once media.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="savedviews" id="pref_savedviews" <?= !empty($botRecord['savedviews']) ? 'checked' : '' ?> onchange="updateBotSettings()">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <!-- Auto-View Status -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: 500; font-size: 0.85rem; color: #0f172a;">👀 24/7 Status Auto-View</div>
                            <div style="font-size: 0.74rem; color: #64748b; font-weight: 400;">Automatically views contacts' WhatsApp statuses in background.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="autoview" id="pref_autoview" <?= !empty($botRecord['autoview_status']) ? 'checked' : '' ?> onchange="updateBotSettings()">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <!-- Auto-Like Status -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: 500; font-size: 0.85rem; color: #0f172a;">❤️ Status Auto-Reaction (Auto-Like)</div>
                            <div style="font-size: 0.74rem; color: #64748b; font-weight: 400;">Reacts with ❤️ to viewed WhatsApp status updates.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="autolike" id="pref_autolike" <?= !empty($botRecord['autolike_status']) ? 'checked' : '' ?> onchange="updateBotSettings()">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <!-- Media Downloader -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: 500; font-size: 0.85rem; color: #0f172a;">📥 TikTok, YouTube & IG Downloader</div>
                            <div style="font-size: 0.74rem; color: #64748b; font-weight: 400;">Auto-downloads video links pasted into your chat.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="downloader_enabled" id="pref_downloader" <?= !empty($botRecord['downloader_enabled']) ? 'checked' : '' ?> onchange="updateBotSettings()">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <!-- Music Player -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: 500; font-size: 0.85rem; color: #0f172a;">🎵 Music Player Command (`play <song>`)</div>
                            <div style="font-size: 0.74rem; color: #64748b; font-weight: 400;">Allows playing and downloading music directly on WhatsApp.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="music_enabled" id="pref_music" <?= !empty($botRecord['music_enabled']) ? 'checked' : '' ?> onchange="updateBotSettings()">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <!-- Customer Auto-Reply (Personal Protection) -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: 500; font-size: 0.85rem; color: #0f172a;">💬 Customer Auto-Reply to Messages</div>
                            <div style="font-size: 0.74rem; color: #64748b; font-weight: 400;">Disabled for personal phone links so friends and family don't receive automated bot menus.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="autoreply" id="pref_autoreply" <?= !empty($botRecord['autoreply']) ? 'checked' : '' ?> onchange="updateBotSettings()">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Commands Cheat Sheet Card -->
    <div class="card-custom">
        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.85rem;">
            <i class="fas fa-terminal" style="color: #2563eb; font-size: 1.1rem;"></i>
            <h3 style="font-size: 1rem; font-weight: 600; color: #0f172a; margin: 0;">WhatsApp Bot Commands Guide</h3>
        </div>
        <p style="font-size: 0.8rem; color: #64748b; margin: 0 0 1rem 0; font-weight: 400;">
            Use these commands directly in your WhatsApp chats with the bot:
        </p>

        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 0.5rem;">
                <div>
                    <span class="command-badge">play &lt;song name&gt;</span>
                    <div style="font-size: 0.75rem; color: #475569; margin-top: 0.2rem; font-weight: 400;">Searches, downloads & sends playable high-quality MP3 audio with cover artwork.</div>
                </div>
                <span style="font-size: 0.7rem; color: #16a34a; font-weight: 500;">🎵 Music Player</span>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 0.5rem;">
                <div>
                    <span class="command-badge">TikTok URL</span>
                    <div style="font-size: 0.75rem; color: #475569; margin-top: 0.2rem; font-weight: 400;">Paste any TikTok link to auto-download watermark-free HD video.</div>
                </div>
                <span style="font-size: 0.7rem; color: #2563eb; font-weight: 500;">🎬 TikTok No-WM</span>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 0.5rem;">
                <div>
                    <span class="command-badge">YouTube URL</span>
                    <div style="font-size: 0.75rem; color: #475569; margin-top: 0.2rem; font-weight: 400;">Paste any YouTube video or Shorts link to receive the MP4 video directly.</div>
                </div>
                <span style="font-size: 0.7rem; color: #ef4444; font-weight: 500;">🎥 YouTube Video</span>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 0.5rem;">
                <div>
                    <span class="command-badge">ig &lt;Instagram URL&gt;</span>
                    <div style="font-size: 0.75rem; color: #475569; margin-top: 0.2rem; font-weight: 400;">Downloads and delivers Instagram Reels, Posts, and Videos.</div>
                </div>
                <span style="font-size: 0.7rem; color: #d946ef; font-weight: 500;">📸 Instagram Reel</span>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 0.5rem;">
                <div>
                    <span class="command-badge">.s / .sticker</span>
                    <div style="font-size: 0.75rem; color: #475569; margin-top: 0.2rem; font-weight: 400;">Reply to any photo or GIF with <code>.s</code> to instantly create a sticker.</div>
                </div>
                <span style="font-size: 0.7rem; color: #f59e0b; font-weight: 500;">🎨 Sticker Maker</span>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 0.5rem;">
                <div>
                    <span class="command-badge">.ai &lt;question&gt;</span>
                    <div style="font-size: 0.75rem; color: #475569; margin-top: 0.2rem; font-weight: 400;">Instant AI question answering, essay writing, and assistant responses.</div>
                </div>
                <span style="font-size: 0.7rem; color: #8b5cf6; font-weight: 500;">🧠 AI Assistant</span>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 0.5rem;">
                <div>
                    <span class="command-badge">.lyrics &lt;song&gt;</span>
                    <div style="font-size: 0.75rem; color: #475569; margin-top: 0.2rem; font-weight: 400;">Fetches complete song lyrics with artist breakdown.</div>
                </div>
                <span style="font-size: 0.7rem; color: #06b6d4; font-weight: 500;">🎤 Song Lyrics</span>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 0.5rem;">
                <div>
                    <span class="command-badge">.tts &lt;text&gt;</span>
                    <div style="font-size: 0.75rem; color: #475569; margin-top: 0.2rem; font-weight: 400;">Speaks your message aloud as a natural WhatsApp voice note.</div>
                </div>
                <span style="font-size: 0.7rem; color: #10b981; font-weight: 500;">🗣️ Text to Speech</span>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 0.5rem;">
                <div>
                    <span class="command-badge">.menu / .help</span>
                    <div style="font-size: 0.75rem; color: #475569; margin-top: 0.2rem; font-weight: 400;">Displays the interactive command menu right on your WhatsApp.</div>
                </div>
                <span style="font-size: 0.7rem; color: #64748b; font-weight: 500;">📋 Help Menu</span>
            </div>
        </div>
    </div>

</main>

<script>
let currentPairingCode = '';
let statusPoller = null;
let countdownTimer = null;

// Copy Pairing Code
function copyPairingCode() {
    if (!currentPairingCode) return;
    const clean = currentPairingCode.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
    navigator.clipboard.writeText(clean).then(() => {
        const btn = document.getElementById('btnCopyCode');
        if (btn) {
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> <span>Copied ' + clean + '!</span>';
            btn.style.background = 'linear-gradient(135deg, #16a34a 0%, #15803d 100%)';
            setTimeout(() => {
                btn.innerHTML = origHtml;
                btn.style.background = '';
            }, 2500);
        }

        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2000,
            timerProgressBar: true
        });
        Toast.fire({
            icon: 'success',
            title: 'Pairing code ' + clean + ' copied!'
        });
    }).catch(() => {
        alert('Your pairing code: ' + clean);
    });
}

// Render 8-character boxes and update action button text
function renderPairingBoxes(code) {
    currentPairingCode = code;
    const container = document.getElementById('pairingBoxes');
    if (!container) return;
    container.innerHTML = '';

    const clean = code.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
    for (let i = 0; i < clean.length; i++) {
        if (i === 4) {
            const sep = document.createElement('div');
            sep.className = 'code-separator';
            sep.innerText = '–';
            container.appendChild(sep);
        }
        const box = document.createElement('div');
        box.className = 'code-box-char';
        box.innerText = clean[i];
        container.appendChild(box);
    }

    // Update the nice color button with the 8-character code
    const btnText = document.getElementById('btnCodeText');
    if (btnText) {
        let displayCode = clean;
        if (clean.length === 8) {
            displayCode = clean.slice(0, 4) + ' – ' + clean.slice(4);
        }
        btnText.innerText = displayCode;
    }
}

// Live Countdown timer (ticking MM:SS)
function startCountdown(seconds = 60) {
    if (countdownTimer) clearInterval(countdownTimer);
    let remaining = seconds;
    const cdEl = document.getElementById('codeCountdown');
    const badge = document.getElementById('liveCountdownWrapper');
    const label = document.getElementById('countdownLabelText');

    function formatTime(s) {
        const m = Math.floor(s / 60);
        const sec = s % 60;
        return (m < 10 ? '0' + m : m) + ':' + (sec < 10 ? '0' + sec : sec);
    }

    if (cdEl) cdEl.innerText = formatTime(remaining);
    if (badge) badge.classList.remove('expiring-soon');
    if (label) label.innerText = 'Code expires in:';

    countdownTimer = setInterval(() => {
        remaining--;
        if (cdEl) cdEl.innerText = formatTime(remaining);

        if (remaining <= 20 && remaining > 0) {
            if (badge) badge.classList.add('expiring-soon');
            if (label) label.innerText = 'Expiring soon:';
        }

        if (remaining <= 0) {
            clearInterval(countdownTimer);
            if (label) label.innerText = 'Code expired!';
            if (cdEl) cdEl.innerText = '00:00';
            if (badge) badge.classList.add('expiring-soon');

            // Allow user to regenerate code easily
            const btnCopy = document.getElementById('btnCopyCode');
            if (btnCopy) {
                btnCopy.innerHTML = '<i class="fas fa-redo"></i> <span>Get New Linking Code</span>';
                btnCopy.onclick = function() { requestPairingCode(true); };
            }
        }
    }, 1000);
}

// Normalization helpers for Ghanaian phone numbers
function normalizeGhanaPhone(raw) {
    let digits = String(raw || '').replace(/\D/g, '');
    if (!digits) return '';
    if (digits.startsWith('0')) {
        digits = '233' + digits.substring(1);
    } else if (digits.length === 9) {
        digits = '233' + digits;
    }
    return digits;
}

function formatPhoneDisplay(normalized) {
    if (!normalized) return '';
    if (normalized.startsWith('233') && normalized.length === 12) {
        return '+233 ' + normalized.substring(3, 5) + ' ' + normalized.substring(5, 8) + ' ' + normalized.substring(8);
    }
    return '+' + normalized;
}

// Request Pairing Code
async function requestPairingCode(isRefresh = false) {
    const rawInput = document.getElementById('botPhoneInput').value.trim();
    if (!rawInput) {
        Swal.fire({
            title: 'WhatsApp Bot',
            html: '<div style="font-size:0.95rem; line-height:1.55; color:#ffffff; font-weight:400;">Please enter your WhatsApp phone number.</div>',
            background: '#ef4444',
            color: '#ffffff',
            confirmButtonColor: '#ffffff',
            confirmButtonText: '<span style="color:#ef4444; font-weight:600;">Understood</span>',
            padding: '2em',
            customClass: {
                popup: 'rounded-notice-card',
                confirmButton: 'rounded-notice-btn'
            }
        });
        return;
    }

    const cleanPhone = normalizeGhanaPhone(rawInput);
    if (!cleanPhone || cleanPhone.length < 10) {
        Swal.fire({
            title: 'WhatsApp Bot',
            html: '<div style="font-size:0.95rem; line-height:1.55; color:#ffffff; font-weight:400;">Please enter a valid WhatsApp phone number (e.g. 0559623850 or +233559623850).</div>',
            background: '#ef4444',
            color: '#ffffff',
            confirmButtonColor: '#ffffff',
            confirmButtonText: '<span style="color:#ef4444; font-weight:600;">Understood</span>',
            padding: '2em',
            customClass: {
                popup: 'rounded-notice-card',
                confirmButton: 'rounded-notice-btn'
            }
        });
        return;
    }

    const displayPhone = formatPhoneDisplay(cleanPhone);

    if (!isRefresh) {
        const confirmRes = await Swal.fire({
            title: 'WhatsApp Bot',
            html: '<div style="font-size:0.98rem; line-height:1.6; margin-bottom:0.75rem; color:#ffffff; font-weight:400;">' +
                  'A one-time activation fee of <strong style="font-weight:600; text-decoration:underline;">GHS 2.00</strong> will be deducted from your wallet balance to link your device.' +
                  '</div>' +
                  '<div style="text-align:center; margin-top:0.85rem;">' +
                  '<span style="display:inline-block; background:rgba(255,255,255,0.22); color:#ffffff; padding:0.35rem 0.95rem; border-radius:99px; font-weight:500; font-size:0.82rem; letter-spacing:0.03em;"><i class="fab fa-whatsapp" style="margin-right:5px;"></i> Target Phone: ' + displayPhone + '</span>' +
                  '</div>',
            background: '#059669',
            color: '#ffffff',
            showCancelButton: true,
            confirmButtonColor: '#ffffff',
            confirmButtonText: '<span style="color:#059669; font-weight:600;">Deduct GHS 2.00 & Link</span>',
            cancelButtonColor: 'transparent',
            cancelButtonText: '<span style="color:#ffffff; font-weight:500;">Cancel</span>',
            padding: '2em',
            customClass: {
                popup: 'rounded-notice-card',
                confirmButton: 'rounded-notice-btn',
                cancelButton: 'rounded-notice-cancel-btn'
            }
        });

        if (!confirmRes.isConfirmed) return;
    }

    const btn = document.getElementById('btnRequestPairing');
    btn.disabled = true;
    btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Contacting WhatsApp Engine...`;

    try {
        const formData = new FormData();
        formData.append('action', 'request_pairing');
        formData.append('phone', cleanPhone);
        if (isRefresh) {
            formData.append('is_refresh', '1');
        }

        const res = await fetch('<?= APP_URL ?>ajax_whatsapp_bot.php', {
            method: 'POST',
            body: formData
        });

        let data;
        try {
            data = await res.json();
        } catch (e) {
            throw new Error('Unable to connect to WhatsApp engine. Please try again.');
        }

        btn.disabled = false;
        btn.innerHTML = `<i class="fab fa-whatsapp" style="font-size: 1.2rem;"></i> Generate Linking Code (GHS 2.00)`;

        if (!data.success) {
            if (data.wallet_balance !== undefined) {
                const balEl = document.getElementById('displayWalletBal');
                if (balEl) {
                    balEl.innerText = `GHS ${parseFloat(data.wallet_balance).toFixed(2)}`;
                }
            }
            if (data.code === 'INSUFFICIENT_BALANCE') {
                Swal.fire({
                    title: 'WhatsApp Bot',
                    html: '<div style="font-size:0.95rem; line-height:1.55; margin-bottom:1rem; color:#ffffff; font-weight:400;">' + (data.message || 'Insufficient wallet balance.') + '</div><a href="<?= APP_URL ?>topup" class="rounded-notice-btn" style="display:inline-block; text-decoration:none; color:#2563eb !important; font-weight:600; padding:0.6rem 1.4rem;">Top Up Wallet Now</a>',
                    background: '#2563eb',
                    color: '#ffffff',
                    showConfirmButton: false,
                    padding: '2em',
                    customClass: {
                        popup: 'rounded-notice-card'
                    }
                });
            } else {
                Swal.fire({
                    title: 'WhatsApp Bot',
                    html: '<div style="font-size:0.95rem; line-height:1.55; color:#ffffff; font-weight:400;">' + (data.message || 'Failed to request pairing code.') + '</div>',
                    background: '#ef4444',
                    color: '#ffffff',
                    confirmButtonColor: '#ffffff',
                    confirmButtonText: '<span style="color:#ef4444; font-weight:600;">Close</span>',
                    padding: '2em',
                    customClass: {
                        popup: 'rounded-notice-card',
                        confirmButton: 'rounded-notice-btn'
                    }
                });
            }
            return;
        }

        // 1. Immediately deduct amount from user wallet balance in UI
        if (data.new_balance !== undefined) {
            const balEl = document.getElementById('displayWalletBal');
            if (balEl) {
                balEl.innerText = `GHS ${parseFloat(data.new_balance).toFixed(2)}`;
            }
            const balWrap = document.getElementById('walletBalanceWrapper');
            if (balWrap) {
                balWrap.classList.remove('balance-debited-flash');
                void balWrap.offsetWidth; // trigger reflow
                balWrap.classList.add('balance-debited-flash');
            }
        }

        // 2. Display code section and scroll smoothly into view
        const sec = document.getElementById('pairingCodeDisplaySection');
        if (sec) {
            sec.style.display = 'block';
            sec.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // 3. Render 8 code and start live countdown
        if (data.pairing_code) {
            renderPairingBoxes(data.pairing_code);
            startCountdown(120);
        }

        // Update target phone badge in UI
        const phoneBadgeEl = document.getElementById('displayTargetPhone');
        if (phoneBadgeEl) {
            phoneBadgeEl.innerText = data.formatted_phone || displayPhone || ('+' + cleanPhone);
        }
        const tipWithoutZero = document.getElementById('phoneWithoutZeroTip');
        if (tipWithoutZero) {
            tipWithoutZero.innerText = cleanPhone.startsWith('233') ? cleanPhone.substring(3) : cleanPhone;
        }

        // 4. Start real-time polling to detect connection
        startStatusPolling();

    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = `<i class="fab fa-whatsapp" style="font-size: 1.2rem;"></i> Generate Linking Code (GHS 2.00)`;
        Swal.fire({
            title: 'WhatsApp Bot',
            html: '<div style="font-size:0.95rem; line-height:1.55; color:#ffffff; font-weight:400;">' + err.message + '</div>',
            background: '#ef4444',
            color: '#ffffff',
            confirmButtonColor: '#ffffff',
            confirmButtonText: '<span style="color:#ef4444; font-weight:600;">Close</span>',
            padding: '2em',
            customClass: {
                popup: 'rounded-notice-card',
                confirmButton: 'rounded-notice-btn'
            }
        });
    }
}

// Poll Status
function startStatusPolling() {
    if (statusPoller) clearInterval(statusPoller);

    statusPoller = setInterval(async () => {
        try {
            const res = await fetch('<?= APP_URL ?>ajax_whatsapp_bot.php?action=get_status');
            const data = await res.json();

            if (data && data.success) {
                // If pairing code arrived from engine
                if (data.pairing_code && !currentPairingCode) {
                    renderPairingBoxes(data.pairing_code);
                    startCountdown(60);
                }

                // If connected!
                if (data.live_status === 'connected' || (data.record && data.record.status === 'connected')) {
                    clearInterval(statusPoller);
                    document.getElementById('activationCard').style.display = 'none';
                    document.getElementById('connectedCard').style.display = 'block';
                    if (data.connected_phone) {
                        document.getElementById('connectedPhoneLabel').innerText = `Connected Account: +${data.connected_phone}`;
                        const prefPhoneEl = document.getElementById('prefAccountPhoneText');
                        if (prefPhoneEl) prefPhoneEl.innerText = `+${data.connected_phone}`;
                    }
                    if (data.record && data.record.id) {
                        const botIdInput = document.getElementById('pref_bot_id');
                        if (botIdInput) botIdInput.value = data.record.id;
                    }
                    Swal.fire({
                        title: 'WhatsApp Bot',
                        html: '<div style="font-size:0.98rem; line-height:1.55; color:#ffffff; font-weight:400; margin-bottom:0.5rem;">🎉 Your WhatsApp Bot is now active and online on your account 24/7!</div>',
                        background: '#059669',
                        color: '#ffffff',
                        timer: 2500,
                        showConfirmButton: false,
                        padding: '2em',
                        customClass: {
                            popup: 'rounded-notice-card'
                        }
                    }).then(() => {
                        // Reload page to seamlessly refresh history table & statuses
                        window.location.reload();
                    });
                }
            }
        } catch (e) {}
    }, 2500);
}

// Update Preferences
async function updateBotSettings() {
    const formData = new FormData(document.getElementById('botSettingsForm'));
    formData.append('action', 'update_settings');

    try {
        const res = await fetch('<?= APP_URL ?>ajax_whatsapp_bot.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const toast = Swal.mixin({
                toast: true, position: 'top-end', showConfirmButton: false, timer: 1500
            });
            toast.fire({ icon: 'success', title: 'Settings saved' });
        }
    } catch (e) {}
}

// Show Link New / Another Form
function showLinkNewForm() {
    const actCard = document.getElementById('activationCard');
    if (actCard) {
        actCard.style.display = 'block';
        actCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    const phoneInput = document.getElementById('botPhoneInput');
    if (phoneInput) {
        phoneInput.value = '';
        phoneInput.focus();
    }
    const codeSec = document.getElementById('pairingCodeDisplaySection');
    if (codeSec) {
        codeSec.style.display = 'none';
    }
}

// Open Preferences for a Specific Connected Bot
function openAccountPreferences(botId, phone, antiDelete, savedViews, autoView, autoLike, downloader, music, autoReply) {
    const connectedCard = document.getElementById('connectedCard');
    if (connectedCard) {
        connectedCard.style.display = 'block';
    }

    const botIdInput = document.getElementById('pref_bot_id');
    if (botIdInput) botIdInput.value = botId;

    const prefPhoneText = document.getElementById('prefAccountPhoneText');
    if (prefPhoneText) prefPhoneText.innerText = '+' + phone;

    const labelPhone = document.getElementById('connectedPhoneLabel');
    if (labelPhone) labelPhone.innerText = 'Connected Account: +' + phone;

    const chkAntiDelete = document.getElementById('pref_antidelete');
    if (chkAntiDelete) chkAntiDelete.checked = Boolean(antiDelete);

    const chkSavedViews = document.getElementById('pref_savedviews');
    if (chkSavedViews) chkSavedViews.checked = Boolean(savedViews);

    const chkAutoView = document.getElementById('pref_autoview');
    if (chkAutoView) chkAutoView.checked = Boolean(autoView);

    const chkAutoLike = document.getElementById('pref_autolike');
    if (chkAutoLike) chkAutoLike.checked = Boolean(autoLike);

    const chkDownloader = document.getElementById('pref_downloader');
    if (chkDownloader) chkDownloader.checked = Boolean(downloader);

    const chkMusic = document.getElementById('pref_music');
    if (chkMusic) chkMusic.checked = Boolean(music);

    const chkAutoReply = document.getElementById('pref_autoreply');
    if (chkAutoReply) chkAutoReply.checked = Boolean(autoReply);

    if (connectedCard) {
        connectedCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// Relink existing number
function relinkNumber(phone, isPending = false) {
    const actCard = document.getElementById('activationCard');
    if (actCard) {
        actCard.style.display = 'block';
        actCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    const phoneInput = document.getElementById('botPhoneInput');
    if (phoneInput && phone) {
        phoneInput.value = phone.startsWith('233') ? '0' + phone.substring(3) : phone;
    }
    if (isPending) {
        const codeSec = document.getElementById('pairingCodeDisplaySection');
        if (codeSec) {
            codeSec.style.display = 'block';
            codeSec.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

// Disconnect a specific bot by ID
async function disconnectSpecificBot(botId, phone) {
    const confirmRes = await Swal.fire({
        title: 'Disconnect WhatsApp Bot',
        html: `<div style="font-size:0.95rem; line-height:1.55; margin-bottom:0.5rem; color:#ffffff; font-weight:400;">` +
              `This will unlink session for <strong>+${phone}</strong>.<br>You can re-link this number at any time.</div>`,
        background: '#dc2626',
        color: '#ffffff',
        showCancelButton: true,
        confirmButtonColor: '#ffffff',
        confirmButtonText: '<span style="color:#dc2626; font-weight:600;">Yes, Disconnect</span>',
        cancelButtonColor: 'transparent',
        cancelButtonText: '<span style="color:#ffffff; font-weight:500;">Cancel</span>',
        padding: '2em',
        customClass: {
            popup: 'rounded-notice-card',
            confirmButton: 'rounded-notice-btn',
            cancelButton: 'rounded-notice-cancel-btn'
        }
    });

    if (!confirmRes.isConfirmed) return;

    try {
        const formData = new FormData();
        formData.append('action', 'disconnect');
        formData.append('bot_id', botId);
        const res = await fetch('<?= APP_URL ?>ajax_whatsapp_bot.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            Swal.fire({
                title: 'WhatsApp Bot',
                html: `<div style="font-size:0.95rem; line-height:1.55; color:#ffffff; font-weight:400;">WhatsApp session for +${phone} has been disconnected.</div>`,
                background: '#475569',
                color: '#ffffff',
                confirmButtonColor: '#ffffff',
                confirmButtonText: '<span style="color:#475569; font-weight:600;">OK</span>',
                padding: '2em',
                customClass: {
                    popup: 'rounded-notice-card',
                    confirmButton: 'rounded-notice-btn'
                }
            }).then(() => {
                window.location.reload();
            });
        } else {
            throw new Error(data.message || 'Failed to disconnect');
        }
    } catch (e) {
        Swal.fire({
            title: 'WhatsApp Bot',
            html: '<div style="font-size:0.95rem; line-height:1.55; color:#ffffff; font-weight:400;">' + e.message + '</div>',
            background: '#ef4444',
            color: '#ffffff',
            confirmButtonColor: '#ffffff',
            confirmButtonText: '<span style="color:#ef4444; font-weight:600;">Close</span>',
            padding: '2em',
            customClass: {
                popup: 'rounded-notice-card',
                confirmButton: 'rounded-notice-btn'
            }
        });
    }
}

// Remove disconnected / expired account history record
async function removeAccountRecord(botId, phone) {
    const confirmRes = await Swal.fire({
        title: 'Remove Account Record',
        html: `<div style="font-size:0.95rem; line-height:1.55; color:#ffffff; font-weight:400;">` +
              `Remove record for <strong>+${phone}</strong> from your connected history?</div>`,
        background: '#475569',
        color: '#ffffff',
        showCancelButton: true,
        confirmButtonColor: '#ffffff',
        confirmButtonText: '<span style="color:#475569; font-weight:600;">Remove</span>',
        cancelButtonColor: 'transparent',
        cancelButtonText: '<span style="color:#ffffff; font-weight:500;">Cancel</span>',
        padding: '2em',
        customClass: {
            popup: 'rounded-notice-card',
            confirmButton: 'rounded-notice-btn',
            cancelButton: 'rounded-notice-cancel-btn'
        }
    });

    if (!confirmRes.isConfirmed) return;

    try {
        const formData = new FormData();
        formData.append('action', 'delete_record');
        formData.append('bot_id', botId);
        const res = await fetch('<?= APP_URL ?>ajax_whatsapp_bot.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            const row = document.getElementById('accRow_' + botId);
            if (row) {
                row.remove();
            }
            const countLabel = document.getElementById('accountCountLabel');
            if (countLabel) {
                const currentCount = parseInt(countLabel.innerText, 10) || 1;
                countLabel.innerText = Math.max(0, currentCount - 1);
            }
            const toast = Swal.mixin({
                toast: true, position: 'top-end', showConfirmButton: false, timer: 1500
            });
            toast.fire({ icon: 'success', title: 'Record removed' });
        } else {
            throw new Error(data.message || 'Failed to remove record');
        }
    } catch (e) {
        Swal.fire({
            title: 'WhatsApp Bot',
            html: '<div style="font-size:0.95rem; line-height:1.55; color:#ffffff; font-weight:400;">' + e.message + '</div>',
            background: '#ef4444',
            color: '#ffffff',
            confirmButtonColor: '#ffffff',
            confirmButtonText: '<span style="color:#ef4444; font-weight:600;">Close</span>',
            padding: '2em',
            customClass: {
                popup: 'rounded-notice-card',
                confirmButton: 'rounded-notice-btn'
            }
        });
    }
}

// Disconnect currently active bot (from top card)
async function disconnectBot() {
    const activeBotId = document.getElementById('pref_bot_id')?.value;
    if (activeBotId) {
        return disconnectSpecificBot(activeBotId, 'this account');
    }
    const confirmRes = await Swal.fire({
        title: 'WhatsApp Bot',
        html: '<div style="font-size:0.95rem; line-height:1.55; margin-bottom:0.5rem; color:#ffffff; font-weight:400;">This will disconnect your WhatsApp session. You will need to link your device again to reactivate.</div>',
        background: '#dc2626',
        color: '#ffffff',
        showCancelButton: true,
        confirmButtonColor: '#ffffff',
        confirmButtonText: '<span style="color:#dc2626; font-weight:600;">Yes, Disconnect</span>',
        cancelButtonColor: 'transparent',
        cancelButtonText: '<span style="color:#ffffff; font-weight:500;">Cancel</span>',
        padding: '2em',
        customClass: {
            popup: 'rounded-notice-card',
            confirmButton: 'rounded-notice-btn',
            cancelButton: 'rounded-notice-cancel-btn'
        }
    });

    if (!confirmRes.isConfirmed) return;

    try {
        const formData = new FormData();
        formData.append('action', 'disconnect');
        const res = await fetch('<?= APP_URL ?>ajax_whatsapp_bot.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            window.location.reload();
        }
    } catch (e) {
        Swal.fire({
            title: 'WhatsApp Bot',
            html: '<div style="font-size:0.95rem; line-height:1.55; color:#ffffff; font-weight:400;">' + e.message + '</div>',
            background: '#ef4444',
            color: '#ffffff',
            confirmButtonColor: '#ffffff',
            confirmButtonText: '<span style="color:#ef4444; font-weight:600;">Close</span>',
            padding: '2em',
            customClass: {
                popup: 'rounded-notice-card',
                confirmButton: 'rounded-notice-btn'
            }
        });
    }
}

// Auto-display active pending pairing code on page load if active
document.addEventListener('DOMContentLoaded', () => {
    const existingCode = '<?= !empty($existingPendingCode) ? htmlspecialchars($existingPendingCode, ENT_QUOTES, 'UTF-8') : '' ?>';
    const existingPhone = '<?= !empty($existingPendingPhone) ? htmlspecialchars($existingPendingPhone, ENT_QUOTES, 'UTF-8') : '' ?>';
    const remainingSec = <?= (int)$remainingSeconds ?>;
    if (existingCode) {
        const sec = document.getElementById('pairingCodeDisplaySection');
        if (sec) sec.style.display = 'block';
        renderPairingBoxes(existingCode);
        if (existingPhone) {
            const normP = normalizeGhanaPhone(existingPhone);
            const badgeEl = document.getElementById('displayTargetPhone');
            if (badgeEl) badgeEl.innerText = formatPhoneDisplay(normP);
            const tipEl = document.getElementById('phoneWithoutZeroTip');
            if (tipEl) tipEl.innerText = normP.startsWith('233') ? normP.substring(3) : normP;
        }
        startCountdown(remainingSec);
        startStatusPolling();
    }
});
</script>
</body>
</html>
