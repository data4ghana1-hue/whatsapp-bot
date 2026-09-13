<?php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }

if (empty($_SESSION['user'])) {
    header('Location: ' . APP_URL . 'login');
    exit;
}

$user   = $_SESSION['user'];
$userId = (int)$user['id'];
$pdo    = db_connect();

$errors  = [];
$success = $_SESSION['store_success'] ?? '';
if ($success) {
    unset($_SESSION['store_success']);
}

// Get wallet balance
$walletBalance = getUserWalletBalance($pdo, $userId);

// Fetch Price
$role = strtolower(trim($user['role'] ?? 'client'));
$roleCol = 'price_' . str_replace(' ', '_', $role);
if ($roleCol === 'price_super agent') $roleCol = 'price_super_agent';

$packages = [];
try {
    $stmtPrice = $pdo->prepare("SELECT price_ghs, package_label, gb_amount, $roleCol FROM network_pricing WHERE network = 'MTN EXPRESS' AND is_active = 1 ORDER BY sort_order ASC, gb_amount ASC");
    $stmtPrice->execute();
    $packages = $stmtPrice->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    try {
        $stmtPrice = $pdo->prepare("SELECT price_ghs, package_label, gb_amount FROM network_pricing WHERE network = 'MTN EXPRESS' AND is_active = 1 ORDER BY sort_order ASC, gb_amount ASC");
        $stmtPrice->execute();
        $packages = $stmtPrice->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $ex) { }
}

foreach ($packages as &$p) {
    $rolePrice = isset($p[$roleCol]) ? (float)$p[$roleCol] : 0;
    if ($rolePrice <= 0) {
        $rolePrice = (float)($p['price_ghs'] ?? 0);
    }
    $p['actual_price'] = $rolePrice;
}
unset($p);

function sendMtnVerificationToApi($pdo, $orderId, $recipient, $gbAmount) {
    try {
        require_once __DIR__ . '/classes/SupplierApi.php';
        require_once __DIR__ . '/classes/Supplier2Api.php';
        require_once __DIR__ . '/classes/Supplier3Api.php';
        require_once __DIR__ . '/classes/Supplier5Api.php';
        require_once __DIR__ . '/classes/BackupApi.php';
        require_once __DIR__ . '/classes/MtnUp2uApi.php';
        require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
        require_once __DIR__ . '/classes/NitghtApi.php';
        require_once __DIR__ . '/classes/JaybartWhitelist.php';
        require_once __DIR__ . '/classes/Supplier1Whitelist.php';

        $mtnUp2GbApi = MtnUp2uPortalApi::isNetworkEnabled('MTN') ? new MtnUp2uPortalApi() : null;
        $nitghtApi   = NitghtApi::isNetworkEnabled('MTN')   ? new NitghtApi()   : null;
        $s4Api       = Supplier5Api::isNetworkEnabled('MTN') ? new Supplier5Api() : null;
        
        $mtnUp2uApi  = MtnUp2uApi::isNetworkEnabled('MTN')  ? new MtnUp2uApi()  : null;
        $s1Api = SupplierApi::isNetworkEnabled('MTN') ? new SupplierApi() : null;
        $s2Api = Supplier2Api::isNetworkEnabled('mtn') ? new Supplier2Api() : null;
        $s3Api = Supplier3Api::isNetworkEnabled('mtn') ? new Supplier3Api() : null;
        $backupApi = BackupApi::isNetworkEnabled('MTN') ? new BackupApi() : null;

        $supplierApi = null;
        $supplierName = '';

        if ($nitghtApi) {
            $supplierApi = $nitghtApi;
            $supplierName = 'NITGHT';
        } elseif ($mtnUp2GbApi) {
            $supplierApi = $mtnUp2GbApi;
            $supplierName = 'MTNUP2U PORTAL';
        } elseif ($s4Api) {
            $supplierApi = $s4Api;
            $supplierName = 'Supplier 5';
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
        } elseif ($backupApi) {
            $supplierApi = $backupApi;
            $supplierName = 'Backup';
        } elseif ($s1Api) {
            $supplierApi = $s1Api;
            $supplierName = 'Supplier 1';
        }

        if ($supplierApi) {
            $apiResult = $supplierApi->sendBundle('MTN', $recipient, $gbAmount, $orderId);
            $supplierData = $apiResult['data'] ?? [];
            $suppId = $apiResult['reference'] ?? $apiResult['order_id'] ?? $apiResult['transaction_id'] ?? $apiResult['id'] ?? $supplierData['reference'] ?? $supplierData['reference_id'] ?? $supplierData['order_id'] ?? $supplierData['transaction_id'] ?? $supplierData['trx_ref'] ?? $supplierData['id'] ?? '';
            
            if ($supplierName === 'NITGHT') {
                $orderStatus = NitghtApi::mapStatus('MTN', $apiResult);
                $apiMsg = strtolower($apiResult['message'] ?? $apiResult['data']['message'] ?? '');
                if ($orderStatus === 'validating' || stripos($apiMsg, 'awaiting') !== false || stripos($apiMsg, 'validating') !== false) {
                    $rerouteRes = reroute_order_by_priority($pdo, $orderId, 'MTN', $recipient, (float)$gbAmount, 'nitght', 'Night API');
                    if (!empty($rerouteRes['rerouted'])) {
                        $orderStatus = $rerouteRes['status'];
                        $msg = $rerouteRes['message'];
                    } else {
                        $msg = "Sent to NITGHT. " . ($suppId ? "Order ID: " . $suppId : "");
                    }
                } else {
                    $msg = "Sent to NITGHT. " . ($suppId ? "Order ID: " . $suppId : "");
                }
            } elseif ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') {
                $orderStatus = MtnUp2GbApi::mapStatus('MTN', $apiResult);
                $msg = "Sent to MTNUP2U PORTAL. " . ($suppId ? "Order ID: " . $suppId : "");
            } elseif ($supplierName === 'MTNUP2U') {
                $orderStatus = MtnUp2uApi::mapStatus('MTN', $apiResult);
                $msg = "Sent to MTNUP2U. " . ($suppId ? "Order ID: " . $suppId : "");
            } else {
                $orderStatus = 'accepted';
                $msg = "Sent to " . $supplierName . ". " . ($suppId ? "Ref: " . $suppId : "");
            }

            $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?")->execute([$orderStatus, $msg, $orderId]);
        } else {
            $pdo->prepare("UPDATE bundle_sends SET status = 'pending', message = 'No API enabled/available' WHERE id = ?")->execute([$orderId]);
        }
    } catch (Exception $e) {
        $pdo->prepare("UPDATE bundle_sends SET status = 'pending', message = ? WHERE id = ?")->execute(["API Error: " . substr($e->getMessage(), 0, 100), $orderId]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($packages)) {
        $errors[] = "MTN EXPRESS is currently unavailable (No pricing set in admin).";
    } else {
        $mode = $_POST['mode'] ?? 'single';
        $selectedGb = trim($_POST['pkg'] ?? '');
        $selectedPkg = null;
        foreach ($packages as $p) {
            if ((string)$p['gb_amount'] === $selectedGb) {
                $selectedPkg = $p;
                break;
            }
        }
        // Fallback to first if not found (legacy behavior)
        if (!$selectedPkg && !empty($packages)) {
            $selectedPkg = $packages[0];
        }
        
        $price = $selectedPkg['actual_price'];
        
        if ($mode === 'bulk') {
            $bulkData = trim($_POST['bulk_data'] ?? '');
            if (empty($bulkData)) {
                $errors[] = "Please enter phone numbers.";
            } else {
                $lines = explode("\n", $bulkData);
                $validEntries = [];
                $invalidLines = [];
                $totalCost = 0;
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    $parts = preg_split('/[\s,]+/', $line);
                    $phone = $parts[0] ?? '';
                    $gb = isset($parts[1]) ? (string)$parts[1] : '';

                    if (strpos($phone, '+233') === 0) {
                        $phone = '0' . substr($phone, 4);
                    } elseif (strpos($phone, '233') === 0 && strlen($phone) === 12) {
                        $phone = '0' . substr($phone, 3);
                    }
                    
                    if (preg_match('/^\d{10}$/', $phone) && !empty($gb)) {
                        $pkgFound = null;
                        foreach ($packages as $p) {
                            if ((float)$p['gb_amount'] == (float)$gb) {
                                $pkgFound = $p;
                                break;
                            }
                        }
                        
                        if ($pkgFound) {
                            $validEntries[] = [
                                'phone' => $phone,
                                'gb' => $pkgFound['gb_amount'],
                                'price' => $pkgFound['actual_price']
                            ];
                            $totalCost += $pkgFound['actual_price'];
                        } else {
                            $invalidLines[] = $line . " (Invalid GB)";
                        }
                    } else {
                        $invalidLines[] = $line . (empty($gb) ? " (Missing GB)" : "");
                    }
                }
                
                // Fetch free_mode status
                $stmtFree = $pdo->prepare("SELECT free_mode FROM users WHERE id = ?");
                $stmtFree->execute([$userId]);
                $freeMode = (int)$stmtFree->fetchColumn();

                $verPhones = !empty($validEntries) ? array_column($validEntries, 'phone') : [];
                $refundRestricted = (!empty($verPhones) && function_exists('getBatchPhoneRefundRestrictions')) ? getBatchPhoneRefundRestrictions($pdo, $verPhones) : [];

                if (!empty($invalidLines)) {
                    $errors[] = "Some entries are invalid or missing GB (format: 024XXXXXXX 2): " . implode(', ', array_slice($invalidLines, 0, 3)) . (count($invalidLines) > 3 ? "..." : "");
                } elseif (!empty($refundRestricted)) {
                    $restrMsgs = [];
                    foreach ($refundRestricted as $rPhone => $rInfo) {
                        $restrMsgs[] = "• " . htmlspecialchars($rPhone) . ": Refunded on " . htmlspecialchars($rInfo['refund_date_fmt']) . " (available on " . htmlspecialchars($rInfo['unlock_time_fmt']) . ", in " . htmlspecialchars($rInfo['remaining_text']) . ")";
                    }
                    $errors[] = "The following number(s) had an order refunded recently and cannot receive orders until 1 week after refund:<br>" . implode("<br>", $restrMsgs);
                } elseif (empty($validEntries)) {
                    $errors[] = "No valid entries found.";
                } elseif (!$freeMode && $walletBalance < $totalCost) {
                    $errors[] = "Insufficient balance. You need GHS " . number_format($totalCost, 2) . " to verify " . count($validEntries) . " numbers, but you only have GHS " . number_format($walletBalance, 2) . ".";
                } else {
                    try {
                        $pdo->beginTransaction();
                        $ref = 'VER-' . mt_rand(10000, 99999);
                        $desc = "Bulk MTN EXPRESS for " . count($validEntries) . " numbers";
                        
                        if ($freeMode) {
                            addFreeModeTransaction($pdo, $userId, $totalCost, $ref, $desc);
                        } else {
                            addWalletTransaction($pdo, $userId, $totalCost, 'debit', $ref, $desc);
                        }
                        
                        try {
                            $stmt = $pdo->prepare("INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, amount, status, channel, customer_name) VALUES (?, 'MTN', ?, ?, ?, 'accepted', 'verification', ?)");
                            foreach ($validEntries as $entry) {
                                $stmt->execute([$userId, $entry['phone'], $entry['gb'], $entry['price'], $user['name'] ?? '']);
                                $orderId = $pdo->lastInsertId();
                                sendMtnVerificationToApi($pdo, $orderId, $entry['phone'], $entry['gb']);
                            }
                        } catch (Exception $ex) {
                            $stmt = $pdo->prepare("INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, status, channel) VALUES (?, 'MTN', ?, ?, 'accepted', 'verification')");
                            foreach ($validEntries as $entry) {
                                $stmt->execute([$userId, $entry['phone'], $entry['gb']]);
                                $orderId = $pdo->lastInsertId();
                                sendMtnVerificationToApi($pdo, $orderId, $entry['phone'], $entry['gb']);
                            }
                        }
                        
                        $pdo->commit();
                        $_SESSION['store_success'] = "Successfully submitted " . count($validEntries) . " numbers for verification.";
                        header('Location: ' . APP_URL . 'mtn_verification');
                        exit;
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = "A system error occurred during bulk verification.";
                    }
                }
            }
        } else {
            $phoneNumber = trim($_POST['phone_number'] ?? '');
            if (!preg_match('/^\d{10}$/', $phoneNumber)) {
                $errors[] = "Phone Number must be exactly 10 digits.";
            } else {
                // Fetch free_mode status
                $stmtFree = $pdo->prepare("SELECT free_mode FROM users WHERE id = ?");
                $stmtFree->execute([$userId]);
                $freeMode = (int)$stmtFree->fetchColumn();

                if (!$freeMode && $walletBalance < $price) {
                    $errors[] = "Insufficient balance. You need GHS " . number_format($price, 2) . " but you have GHS " . number_format($walletBalance, 2);
                } else {
                    try {
                        $pdo->beginTransaction();
                        
                        $ref = 'VER-' . mt_rand(10000, 99999);
                        $desc = "MTN EXPRESS for " . $phoneNumber;
                        if ($freeMode) {
                            addFreeModeTransaction($pdo, $userId, $price, $ref, $desc);
                        } else {
                            addWalletTransaction($pdo, $userId, $price, 'debit', $ref, $desc);
                        }
                        
                        try {
                            $stmt = $pdo->prepare("INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, amount, status, channel, customer_name) VALUES (?, 'MTN', ?, ?, ?, 'accepted', 'verification', ?)");
                            $stmt->execute([$userId, $phoneNumber, ($selectedPkg['gb_amount'] ?? 0), $price, $user['name'] ?? '']);
                            $orderId = $pdo->lastInsertId();
                            sendMtnVerificationToApi($pdo, $orderId, $phoneNumber, ($selectedPkg['gb_amount'] ?? 0));
                        } catch (Exception $ex) {
                            $stmt = $pdo->prepare("INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, status, channel) VALUES (?, 'MTN', ?, ?, 'accepted', 'verification')");
                            $stmt->execute([$userId, $phoneNumber, ($selectedPkg['gb_amount'] ?? 0)]);
                            $orderId = $pdo->lastInsertId();
                            sendMtnVerificationToApi($pdo, $orderId, $phoneNumber, ($selectedPkg['gb_amount'] ?? 0));
                        }
                        
                        $pdo->commit();
                        $_SESSION['store_success'] = "Verification request for $phoneNumber has been submitted successfully.";
                        header('Location: ' . APP_URL . 'mtn_verification');
                        exit;
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = "A system error occurred. Please try again.";
                    }
                }
            }
        }
    }
}
$pageTitle = 'MTN EXPRESS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no, viewport-fit=cover">
<title><?= $pageTitle ?> · <?= htmlspecialchars(APP_NAME ?? 'Apex', ENT_QUOTES, 'UTF-8') ?></title>
<meta name="theme-color" content="#1e3a8a">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/css/style.css?v=<?= file_exists(__DIR__ . '/css/style.css') ? filemtime(__DIR__ . '/css/style.css') : time() ?>">
<script src="/js/script.js" defer></script>
<style>
  body {
      font-family: 'Inter', sans-serif;
      background-color: var(--bg-color);
      color: var(--text-primary);
      margin: 0;
      padding: 0;
      transition: background-color 0.3s ease, color 0.3s ease;
      overflow-y: auto !important;
      -webkit-overflow-scrolling: touch;
  }
  
  .store-container {
      max-width: 800px;
      margin: 1.5rem auto 3rem auto;
      padding: 0 1rem;
  }
  
  .card {
      background: var(--surface-color);
      border-radius: 16px;
      padding: 1.5rem;
      box-shadow: var(--shadow-md);
      border: 1px solid var(--border-color);
  }
  
  .switch-container {
      display: flex;
      background: var(--border-color);
      border-radius: 10px;
      padding: 4px;
      gap: 4px;
      margin: 0 auto 1.5rem;
      max-width: 280px;
      transition: background-color 0.3s;
  }
  .switch-btn {
      flex: 1;
      padding: 0.45rem 0.75rem;
      border: none;
      border-radius: 8px;
      font-size: 0.75rem;
      font-weight: 500;
      cursor: pointer;
      background: transparent;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      transition: all 0.2s ease;
  }
  .switch-btn.active {
      background: var(--primary);
      color: white;
      box-shadow: 0 4px 10px rgba(52, 84, 209, 0.2);
  }

  .store-card-form { padding: 0; }

  .form-control-modal {
      width: 100%;
      padding: 0.7rem 0.85rem;
      border: 2px solid var(--border-color);
      border-radius: 12px;
      font-family: 'Inter', sans-serif;
      font-size: 0.88rem;
      font-weight: 500;
      outline: none;
      box-sizing: border-box;
      background: var(--bg-color);
      color: var(--text-primary);
      transition: all 0.2s ease;
  }
  .form-control-modal:focus {
      border-color: var(--primary);
      background: var(--surface-color);
      box-shadow: 0 0 0 4px var(--primary-light);
  }

  select.form-control-modal {
      appearance: none;
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2364748b'><path d='M7 10l5 5 5-5z'/></svg>");
      background-repeat: no-repeat;
      background-position: right 1rem center;
      background-size: 1.25rem;
      padding-right: 2.5rem;
  }
  .dark select.form-control-modal {
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2394a3b8'><path d='M7 10l5 5 5-5z'/></svg>");
  }

  .buy-btn {
      width: 100%;
      height: 40px;
      min-height: 40px;
      border: none;
      border-radius: 10px;
      font-size: 0.85rem;
      font-weight: 500;
      color: white;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      background: var(--primary);
      transition: background 0.2s ease;
  }
  .buy-btn:hover { background: var(--primary-hover); }

  .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(6px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 99999;
      padding: 1rem;
  }
  .modal-content {
      background: var(--surface-color);
      color: var(--text-primary);
      border-radius: 20px;
      padding: 1.75rem 1.5rem;
      width: 100%;
      max-width: 360px;
      box-shadow: var(--shadow-lg);
      transition: background-color 0.3s, color 0.3s;
  }
  
  .batch-textarea {
      width: 100%;
      min-height: 120px;
      padding: 0.75rem;
      border: 2px solid var(--border-color);
      border-radius: 12px;
      font-family: monospace;
      font-size: 0.8rem;
      outline: none;
      box-sizing: border-box;
      resize: vertical;
      background: var(--bg-color);
      color: var(--text-primary);
      transition: all 0.2s ease;
  }
  .batch-textarea:focus {
      border-color: var(--primary);
      background: var(--surface-color);
  }
</style>
</head>
<body>

<?php if ($success): ?>
<div id="successModal" class="modal-overlay" style="display:flex;">
    <div class="modal-content" style="text-align:center;">
        <div style="width:60px;height:60px;background:#22c55e;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <i class="fas fa-check" style="color:white;font-size:1.5rem;"></i>
        </div>
        <h2 style="font-size:1.15rem;font-weight:600;margin-top:0;margin-bottom:0.5rem;">Request Successful</h2>
        <p style="font-size:0.85rem;color:#64748b;margin-bottom:1.5rem;line-height:1.5;"><?= htmlspecialchars($success) ?></p>
        <button onclick="document.getElementById('successModal').style.display='none'" class="buy-btn" style="background:#22c55e;">OK</button>
    </div>
</div>
<script>
    if (typeof playOrderSuccessSound === 'function') {
        playOrderSuccessSound();
    } else {
        window.addEventListener('load', function() {
            if (typeof playOrderSuccessSound === 'function') playOrderSuccessSound();
        });
    }
</script>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div id="errorModal" class="modal-overlay" style="display:flex;">
    <div class="modal-content">
        <div style="width:60px;height:60px;background:#ef4444;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <i class="fas fa-exclamation-triangle" style="color:white;font-size:1.35rem;"></i>
        </div>
        <h2 style="font-size:1.15rem;font-weight:600;text-align:center;margin-top:0;margin-bottom:0.75rem;">Request Failed</h2>
        <div style="font-size:0.85rem;color:#64748b;margin-bottom:1.5rem;line-height:1.5;">
            <ul style="margin:0;padding-left:1.2rem;">
                <?php foreach ($errors as $e): ?>
                    <li style="margin-bottom:0.25rem;"><?= $e ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <button onclick="document.getElementById('errorModal').style.display='none'" class="buy-btn" style="background:#ef4444;">Dismiss</button>
    </div>
</div>
<?php endif; ?>

<div class="app-wrapper">
    <main class="main-content" style="padding:0;">
        <?php include __DIR__ . '/header.php'; ?>

        <div class="store-container">
            <!-- Modern Blue Banner -->
            <div style="background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white; padding: 2rem 1.5rem; text-align: center; margin-bottom: 2rem; box-shadow: 0 4px 15px rgba(37,99,235,0.25); border-radius: 20px;">
                <h1 style="font-size: 1.5rem; font-weight: 800; color: #ffffff; margin-bottom: 0.5rem; letter-spacing: 0.02em; text-transform: uppercase;"><i class="fas fa-store" style="margin-right: 8px;"></i> NEW NUMBERS</h1>
                <p style="font-size: 1rem; font-weight: 500; color: #ffffff; opacity: 0.9; margin: 0 auto; line-height: 1.5; max-width: 600px;">Submit MTN numbers for verification.</p>
            </div>

            <div class="switch-container">
                <button type="button" class="switch-btn active" id="btnModeSingle" onclick="switchStoreMode('single')">Single</button>
                <button type="button" class="switch-btn" id="btnModeBulk" onclick="switchStoreMode('bulk')">Batch Dispatch</button>
            </div>

            <!-- Single -->
            <div id="storeSingleSection">
                <div class="card">
                    <form method="POST" action="">
                        <input type="hidden" name="mode" value="single">
                        
                        <div style="margin-bottom: 1.25rem;">
                            <label style="font-size: 0.72rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; display: block; margin-bottom: 0.5rem;">Select Package</label>
                            <select name="pkg" class="form-control-modal" id="singlePackageSelect" onchange="updateSingleCost()">
                                <?php foreach($packages as $pkg_item): ?>
                                    <option value="<?= htmlspecialchars($pkg_item['gb_amount']) ?>" data-price="<?= $pkg_item['actual_price'] ?>">
                                        <?= (!empty($pkg_item['gb_amount']) ? (float)$pkg_item['gb_amount'] . ' GB' : 'MTN EXPRESS') ?> - GHS <?= number_format($pkg_item['actual_price'], 2) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="margin-bottom: 1.25rem;">
                            <label style="font-size: 0.72rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; display: block; margin-bottom: 0.4rem;">Recipient Number</label>
                            <input type="tel" id="singlePhoneInput" name="phone_number" class="form-control-modal" placeholder="e.g. 024XXXXXXX" pattern="[0-9]{10}" required autocomplete="tel" oninput="updateSingleCost()">
                        </div>

                        <!-- Cost Preview -->
                        <div style="background:#f1f5f9; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:500; display:block;">Verification Fee</span>
                                <span id="singleCostLabel" style="font-size:1.05rem; font-weight:600; color:#0f172a;">GHS 0.00</span>
                            </div>
                            <div style="text-align:right;">
                                <span style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:500; display:block;">Wallet Balance</span>
                                <span style="font-size:0.9rem; font-weight:600; color:#10b981;">GHS <?= number_format($walletBalance, 2) ?></span>
                            </div>
                        </div>

                        <button type="submit" class="buy-btn">Verify Number</button>
                    </form>
                </div>
            </div>

            <!-- Bulk -->
            <div id="storeBulkSection" style="display:none;">
                <div class="card">
                    <form method="POST" action="">
                        <input type="hidden" name="mode" value="bulk">
                        
                        <div style="margin-bottom:1.25rem;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                <label style="font-size: 0.72rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Phone Numbers List</label>
                            </div>
                            <textarea name="bulk_data" id="batchMatrixTextarea" class="batch-textarea" placeholder="0241234567 2&#10;0547654321 5" oninput="updateBatchCost()"></textarea>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.4rem; font-size:0.72rem; color:#64748b; font-weight:500;">
                                <div>Numbers: <span id="batchCountText" style="font-weight: 600;">0</span></div>
                            </div>
                        </div>

                        <!-- Cost Preview -->
                        <div style="background:#f1f5f9; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:500; display:block;">Total Fee</span>
                                <span id="batchCostLabel" style="font-size:1.05rem; font-weight:600; color:#0f172a;">GHS 0.00</span>
                            </div>
                            <div style="text-align:right;">
                                <span style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:500; display:block;">Wallet Balance</span>
                                <span style="font-size:0.9rem; font-weight:600; color:#10b981;">GHS <?= number_format($walletBalance, 2) ?></span>
                            </div>
                        </div>

                        <button type="submit" class="buy-btn">Verify Batch</button>
                    </form>
                </div>
            </div>

            <!-- View History -->
            <div style="margin-top: 2rem; text-align: center;">
                <a href="<?= APP_URL ?>history" style="display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.78rem; font-weight: 500; color: #475569; text-decoration: none; border-bottom: 1.5px solid #cbd5e1; padding-bottom: 0.15rem;">
                    <i class="fas fa-receipt"></i> View Order History
                </a>
            </div>
        </div>
    </main>
</div>

<script>
const packagesData = <?= json_encode($packages) ?>;

function switchStoreMode(mode) {
    document.getElementById('btnModeSingle').classList.toggle('active', mode === 'single');
    document.getElementById('btnModeBulk').classList.toggle('active', mode === 'bulk');
    
    document.getElementById('storeSingleSection').style.display = mode === 'single' ? 'block' : 'none';
    document.getElementById('storeBulkSection').style.display = mode === 'bulk' ? 'block' : 'none';
}

function updateSingleCost() {
    const select = document.getElementById('singlePackageSelect');
    if (!select || select.selectedIndex < 0) return;
    const option = select.options[select.selectedIndex];
    const price = parseFloat(option.getAttribute('data-price') || 0);
    document.getElementById('singleCostLabel').innerText = 'GHS ' + price.toFixed(2);
}

function updateBatchCost() {
    const text = document.getElementById('batchMatrixTextarea').value;
    let totalCost = 0;
    const lines = text.split('\n');
    let count = 0;
    
    lines.forEach(line => {
        let trimmed = line.trim();
        if (!trimmed) return;
        
        let parts = trimmed.split(/[\s,]+/);
        let p = parts[0] || '';
        let gb = parts[1] || '';
        
        if(p.startsWith('+233')) p = '0'+p.substring(4);
        else if(p.startsWith('233') && p.length===12) p = '0'+p.substring(3);
        
        if(p.match(/^[0-9]{10}$/) && gb) {
            let pkg = packagesData.find(x => parseFloat(x.gb_amount) === parseFloat(gb));
            if (pkg) {
                count++;
                totalCost += parseFloat(pkg.actual_price || 0);
            }
        }
    });
    
    document.getElementById('batchCountText').innerText = count;
    document.getElementById('batchCostLabel').innerText = 'GHS ' + totalCost.toFixed(2);
}

document.addEventListener('DOMContentLoaded', () => {
    updateSingleCost();
    updateBatchCost();
});
</script>
</body>
</html>


