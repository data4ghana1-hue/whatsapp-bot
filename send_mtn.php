<?php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }

// Guard: redirect to login if not logged in
if (empty($_SESSION['user'])) {
    header('Location: ' . APP_URL . 'login');
    exit;
}

$user   = $_SESSION['user'];
$userId = (int)$user['id'];
$pdo    = db_connect();

$role = strtolower(trim($user['role'] ?? ''));
if (!in_array($role, ['super agent', 'super_agent', 'elite', 'admin', 'reseller', 'dealer', 'dealers'])) {
    $pageTitle = 'Access Denied';
    ?>
    <!DOCTYPE html>
    <html lang="en" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no, viewport-fit=cover">
        <title><?= $pageTitle ?> &middot; <?= htmlspecialchars(APP_NAME ?? 'Apex', ENT_QUOTES, 'UTF-8') ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="/css/style.css">
    </head>
    <body class="dash2">
    <div class="app-wrapper">
        <main class="main-content" style="padding: 0; background-color: #f8fafc;">
            <?php include __DIR__ . '/header.php'; ?>
            <div style="padding: 3rem 1.5rem; display: flex; justify-content: center;">
                <div style="background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 2.5rem 2rem; text-align: center; max-width: 360px; width: 100%; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);">
                    <div style="width: 64px; height: 64px; background: rgba(239, 68, 68, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                        <i class="fas fa-lock" style="font-size: 1.75rem; color: #ef4444;"></i>
                    </div>
                    <h2 style="font-size: 1.35rem; font-weight: 700; color: #0f172a; margin-bottom: 0.5rem; font-family: 'Inter', sans-serif;">Access Denied</h2>
                    <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 2rem; line-height: 1.5; font-family: 'Inter', sans-serif;">This feature is exclusive to Elite and Super Agents.</p>
                    <a href="dashboard" style="display: inline-block; background: #3b82f6; color: white; padding: 0.75rem 1.5rem; border-radius: 10px; font-weight: 600; font-size: 0.85rem; text-decoration: none; font-family: 'Inter', sans-serif; transition: background 0.2s;">Return to Dashboard</a>
                </div>
            </div>
        </main>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// Auto-create table if missing
$pdo->exec("
CREATE TABLE IF NOT EXISTS bundle_sends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    network VARCHAR(50) NOT NULL,
    recipient_phone VARCHAR(20) NOT NULL,
    gb_amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
try {
    $pdo->exec("ALTER TABLE bundle_sends ADD COLUMN message VARCHAR(255) DEFAULT NULL AFTER status");
} catch (Exception $e) {}

$errors  = [];
$success = '';

// Calculate total available MTN GB from helper
try {
    $availableGb = getNetworkBalance($pdo, $userId, 'MTN');
} catch (Exception $e) {
    $availableGb = 0.0;
}

// Get user's wallet balance
$stmtWallet = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
$stmtWallet->execute([$userId]);
$walletBalance = (float)$stmtWallet->fetchColumn();

// Get system settings and network status
$settings = function_exists('load_all_settings') ? load_all_settings($pdo) : [];
$isMtnActive = !empty($settings['mtn_enabled']);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($role !== 'admin' && !$isMtnActive) {
        $errors[] = "MTN Group Share service is currently offline for maintenance. Please check back shortly.";
    } else {
        $mode = $_POST['mode'] ?? 'single';
    
    if ($mode === 'bulk') {
        $bulkData = trim($_POST['bulk_data'] ?? '');
        if (empty($bulkData)) {
            $errors[] = "Please enter bulk data.";
        } else {
            $lines = explode("\n", $bulkData);
            $totalAmount = 0;
            $validOrders = [];
            $invalidLines = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $parts = preg_split('/[\s,]+/', $line);
                if (count($parts) >= 2) {
                    $recip = trim($parts[0]);
                    $amt = (float)trim($parts[1]);
                    $validPhone = normalizeAndValidatePhone($recip, 'MTN');
                    if ($amt >= 1 && $amt <= 100 && $validPhone) {
                        // No verification check — all valid MTN numbers proceed
                        $totalAmount += $amt;
                        $validOrders[] = ['recipient' => $validPhone, 'amount' => $amt];
                    } else {
                        $invalidLines[] = $line;
                    }
                } else {
                    $invalidLines[] = $line;
                }
            }

            $freeMode = (int)($user['free_mode'] ?? 0);
            $bulkPhones = !empty($validOrders) ? array_column($validOrders, 'recipient') : [];
            $refundRestricted = (!empty($bulkPhones) && function_exists('getBatchPhoneRefundRestrictions')) ? getBatchPhoneRefundRestrictions($pdo, $bulkPhones) : [];

            if (!empty($invalidLines)) {
                $errors[] = "Some entries are invalid: " . implode("; ", array_slice($invalidLines, 0, 3)) . (count($invalidLines) > 3 ? "..." : "");
            } elseif (!empty($refundRestricted)) {
                $restrMsgs = [];
                foreach ($refundRestricted as $rPhone => $rInfo) {
                    $restrMsgs[] = "• " . htmlspecialchars($rPhone) . ": Refunded on " . htmlspecialchars($rInfo['refund_date_fmt']) . " (available on " . htmlspecialchars($rInfo['unlock_time_fmt']) . ", in " . htmlspecialchars($rInfo['remaining_text']) . ")";
                }
                $errors[] = "The following number(s) had an order refunded recently and cannot receive orders until 1 week after refund:<br>" . implode("<br>", $restrMsgs);
            } elseif (empty($validOrders)) {
                $errors[] = "No valid entries found. Please use format: Number, GB";
            } elseif ($totalAmount > $availableGb) {
                $errors[] = "Insufficient data balance. Total bulk amount is " . number_format($totalAmount, 2) . " GB but you only have " . number_format($availableGb, 2) . " GB available.";
            } else {
                $successCount = 0;
                $failCount = 0;
                $totalSuccessAmount = 0;
                require_once __DIR__ . '/classes/SupplierApi.php';
                require_once __DIR__ . '/classes/Supplier2Api.php';
                require_once __DIR__ . '/classes/Supplier3Api.php';
                require_once __DIR__ . '/classes/Supplier5Api.php';
                require_once __DIR__ . '/classes/BackupApi.php';
                require_once __DIR__ . '/classes/MtnUp2uApi.php';
                require_once __DIR__ . '/classes/NitghtApi.php';
                require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
                require_once __DIR__ . '/classes/JaybartWhitelist.php';
                
                $mtnUp2GbApi = MtnUp2uPortalApi::isNetworkEnabled('MTN') ? new MtnUp2uPortalApi() : null;
                $mtnUp2uApi  = MtnUp2uApi::isNetworkEnabled('MTN')  ? new MtnUp2uApi()  : null;
                $nitghtApi   = NitghtApi::isNetworkEnabled('MTN')   ? new NitghtApi()   : null;
                $s1Api = SupplierApi::isNetworkEnabled('MTN') ? new SupplierApi() : null;
                $s2Api = Supplier2Api::isNetworkEnabled('mtn') ? new Supplier2Api() : null;
                $s3Api = Supplier3Api::isNetworkEnabled('mtn') ? new Supplier3Api() : null;
                $s4Api = Supplier5Api::isNetworkEnabled('mtn') ? new Supplier5Api() : null;
                $backupApi = BackupApi::isNetworkEnabled('mtn') ? new BackupApi() : null;
                
                $useSupplier = ($mtnUp2GbApi || $mtnUp2uApi || $nitghtApi || $s1Api || $s2Api || $s3Api || $s4Api || $backupApi);
                
                foreach ($validOrders as $order) {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, status) VALUES (?, 'MTN', ?, ?, 'pending')");
                        $stmt->execute([$userId, $order['recipient'], $order['amount']]);
                        $orderId = (int)$pdo->lastInsertId();
                        
                        if ($useSupplier) {
                            $resolvedSupp = resolve_order_supplier('MTN', $order['amount'], $order['recipient'], $pdo);
                            $supplierApi  = $resolvedSupp['api'];
                            $supplierName = $resolvedSupp['name'];
                            
                            if ($supplierApi) {
                                if ($supplierName === 'MTNUP2U') {
                                    $recipientPhone = preg_replace('/[^0-9]/', '', $order['recipient']);
                                    if (strlen($recipientPhone) === 12 && substr($recipientPhone, 0, 3) === '233') {
                                        $recipientPhone = '0' . substr($recipientPhone, 3);
                                    }
                                    
                                    $inMtnUp2u = $mtnUp2uApi->isBeneficiary($order['recipient'], 'MTN');

                                    if (!$inMtnUp2u) {
                                        // Not yet on MTNUP2U list — set to accepted
                                        $status = 'accepted';
                                        $message = "Submitted for MTN Verification on Accepted status.";
                                        $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ?");
                                        $stmtUpdate->execute([$status, $message, $orderId]);
                                        $totalSuccessAmount += $order['amount'];
                                        $successCount++;
                                        $pdo->commit();
                                        continue;
                                    }
                                }
                                $apiResult = $supplierApi->sendBundle('MTN', $order['recipient'], $order['amount'], $orderId);
                                
                                if ($apiResult['success']) {
                                    $status = Supplier5Api::mapStatus('MTN', $apiResult);
                                    if ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') {
                                        $status = MtnUp2GbApi::mapStatus('MTN', $apiResult);
                                    } elseif ($supplierName === 'MTNUP2U') {
                                        $status = MtnUp2uApi::mapStatus('MTN', $apiResult);
                                    } elseif ($supplierName === 'NITGHT') {
                                        $status = NitghtApi::mapStatus('MTN', $apiResult);
                                        $apiMsg = strtolower($apiResult['message'] ?? $apiResult['data']['message'] ?? '');
                                        if ($status === 'validating' || stripos($apiMsg, 'awaiting') !== false || stripos($apiMsg, 'validating') !== false) {
                                            $rerouteRes = reroute_order_by_priority($pdo, $orderId, 'MTN', $order['recipient'], (float)$order['amount'], 'nitght', 'Night API');
                                            if (!empty($rerouteRes['rerouted'])) {
                                                $status = $rerouteRes['status'];
                                                $message = $rerouteRes['message'];
                                            }
                                        }
                                    } elseif ($supplierName === 'Supplier 1') {
                                        $status = SupplierApi::mapStatus('MTN', $apiResult);
                                    } elseif ($supplierName === 'Supplier 2') {
                                        $status = Supplier2Api::mapStatus('MTN', $apiResult);
                                        if ($status === 'processing') $status = 'unverified';
                                    } elseif ($supplierName === 'Supplier 3') {
                                        $status = Supplier3Api::mapStatus('MTN', $apiResult);
                                        if ($status === 'processing') $status = 'waiting';
                                    } elseif ($supplierName === 'Backup') {
                                        $status = BackupApi::mapStatus('MTN', $apiResult);
                                    }
                                    
                                    $supplierData = $apiResult['data'] ?? [];
                                    $suppId = $apiResult['reference'] ?? $apiResult['order_id'] ?? $apiResult['transaction_id'] ?? $apiResult['id'] ?? $supplierData['reference'] ?? $supplierData['reference_id'] ?? $supplierData['order_id'] ?? $supplierData['transaction_id'] ?? $supplierData['trx_ref'] ?? $supplierData['id'] ?? '';
                                    $message = "Sent to " . $supplierName . ". Order ID: " . $suppId;
                                    $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                                    $stmtUpdate->execute([$status, $message, $orderId]);
                                    $totalSuccessAmount += $order['amount'];
                                    $successCount++;
                                } else {
                                    $rawErr = SupplierApi::sanitizeErrorMessage($apiResult['message'] ?? 'Unknown error');
                                    $fullMsg = $apiResult['message'] ?? '';
                                    if (isBeneficiaryError($rawErr) || isBeneficiaryError($fullMsg)) {
                                        $status = 'unverified';
                                        $message = "Beneficiary Pending List";
                                        $totalSuccessAmount += $order['amount'];
                                        $successCount++;
                                    } elseif (isInsufficientBalanceError($rawErr) || isInsufficientBalanceError($fullMsg)) {
                                        $status = 'pending';
                                        $message = "Pending manual dispatch";
                                        $failCount++;
                                    } else {
                                        $status = 'pending';
                                        $message = "Supplier Error: " . $rawErr;
                                        $failCount++;
                                    }
                                    $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                                    $stmtUpdate->execute([$status, $message, $orderId]);
                                }
                            } else {
                                $totalSuccessAmount += $order['amount'];
                                $successCount++;
                            }
                        } else {
                            $totalSuccessAmount += $order['amount'];
                            $successCount++;
                        }
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $failCount++;
                    }
                }
                
                $availableGb -= $totalSuccessAmount;
                if ($successCount > 0) $success = "Successfully submitted {$successCount} bulk requests totaling {$totalSuccessAmount} GB.";
                if ($failCount > 0) $errors[] = "{$failCount} bulk request(s) failed during processing.";
            }
        }
    } else {
        $recipient = trim($_POST['recipient'] ?? '');
        $amount    = (float)($_POST['amount'] ?? 0);
        $validPhone = normalizeAndValidatePhone($recipient, 'MTN');

        $refundRestriction = ($validPhone && function_exists('getPhoneRefundRestriction')) ? getPhoneRefundRestriction($pdo, $validPhone) : null;

        if ($amount < 1 || $amount > 100) {
            $errors[] = "Please enter a valid GB amount between 1 and 100 GB.";
        } elseif ($amount > $availableGb) {
            $errors[] = "Insufficient balance. You only have " . number_format($availableGb, 2) . " GB available.";
        } elseif (!$validPhone) {
            $errors[] = "Please enter a valid MTN phone number.";
        } elseif ($refundRestriction) {
            $errors[] = $refundRestriction['message'];
        } else {
            $stmtRoute = $pdo->prepare("SELECT supplier FROM supplier_routing WHERE phone_number = ? LIMIT 1");
            $stmtRoute->execute([$recipient]);
            $assignedSupplier = $stmtRoute->fetchColumn();

            // No number verification — proceed directly to send
            {
            try {
                $stmt = $pdo->prepare("INSERT INTO bundle_sends (user_id, network, recipient_phone, gb_amount, status) VALUES (?, 'MTN', ?, ?, 'pending')");
                $stmt->execute([$userId, $recipient, $amount]);
                $orderId = (int)$pdo->lastInsertId();
                require_once __DIR__ . '/classes/SupplierApi.php';
                require_once __DIR__ . '/classes/Supplier2Api.php';
                require_once __DIR__ . '/classes/Supplier3Api.php';
                require_once __DIR__ . '/classes/Supplier5Api.php';
                require_once __DIR__ . '/classes/BackupApi.php';
                require_once __DIR__ . '/classes/MtnUp2uApi.php';
                require_once __DIR__ . '/classes/NitghtApi.php';
                require_once __DIR__ . '/classes/MtnUp2uPortalApi.php';
                require_once __DIR__ . '/classes/JaybartWhitelist.php';
                $mtnUp2GbApi = MtnUp2uPortalApi::isNetworkEnabled('MTN') ? new MtnUp2uPortalApi() : null;
                $mtnUp2uApi  = MtnUp2uApi::isNetworkEnabled('MTN')  ? new MtnUp2uApi()  : null;
                $nitghtApi   = NitghtApi::isNetworkEnabled('MTN')   ? new NitghtApi()   : null;
                $s1Api = SupplierApi::isNetworkEnabled('MTN') ? new SupplierApi() : null;
                $s2Api = Supplier2Api::isNetworkEnabled('mtn') ? new Supplier2Api() : null;
                $s3Api = Supplier3Api::isNetworkEnabled('mtn') ? new Supplier3Api() : null;
                $s4Api = Supplier5Api::isNetworkEnabled('mtn') ? new Supplier5Api() : null;
                $resolvedSupp = resolve_order_supplier('MTN', $amount, $recipient, $pdo);
                $supplierApi  = $resolvedSupp['api'];
                $supplierName = $resolvedSupp['name'];
                if ($supplierApi) {
                        $apiResult = $supplierApi->sendBundle('MTN', $recipient, $amount, $orderId);
                        if ($apiResult['success']) {
                            $status = Supplier5Api::mapStatus('MTN', $apiResult);
                            if ($supplierName === 'MTNUP2U PORTAL' || $supplierName === 'MTNUP2 GB') $status = MtnUp2GbApi::mapStatus('MTN', $apiResult);
                            elseif ($supplierName === 'MTNUP2U') $status = MtnUp2uApi::mapStatus('MTN', $apiResult);
                            elseif ($supplierName === 'NITGHT') {
                                 $status = NitghtApi::mapStatus('MTN', $apiResult);
                                 $apiMsg = strtolower($apiResult['message'] ?? $apiResult['data']['message'] ?? '');
                                 if ($status === 'validating' || stripos($apiMsg, 'awaiting') !== false || stripos($apiMsg, 'validating') !== false) {
                                      $rerouteRes = reroute_order_by_priority($pdo, $orderId, 'MTN', $recipient, (float)$amount, 'nitght', 'Night API');
                                      if (!empty($rerouteRes['rerouted'])) {
                                          $status = $rerouteRes['status'];
                                          $message = $rerouteRes['message'];
                                      }
                                 }
                             }
                            elseif ($supplierName === 'Supplier 1') $status = SupplierApi::mapStatus('MTN', $apiResult);
                            elseif ($supplierName === 'Supplier 2') { $status = Supplier2Api::mapStatus('MTN', $apiResult); if ($status === 'processing') $status = 'unverified'; }
                            elseif ($supplierName === 'Supplier 3') { $status = Supplier3Api::mapStatus('MTN', $apiResult); if ($status === 'processing') $status = 'waiting'; }
                            $supplierData = $apiResult['data'] ?? [];
                            $suppId = $apiResult['reference'] ?? $apiResult['order_id'] ?? $apiResult['transaction_id'] ?? $apiResult['id'] ?? $supplierData['reference'] ?? $supplierData['reference_id'] ?? $supplierData['order_id'] ?? $supplierData['transaction_id'] ?? $supplierData['trx_ref'] ?? $supplierData['id'] ?? '';
                            $message = "Sent to " . $supplierName . ". Order ID: " . $suppId;
                            $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                            $stmtUpdate->execute([$status, $message, $orderId]);
                            $success = "Successfully submitted a request to send {$amount} GB to {$recipient}. Supplier Order ID: " . htmlspecialchars($suppId);
                            $availableGb -= $amount;
                        } else {
                            $errReason = SupplierApi::sanitizeErrorMessage($apiResult['message'] ?? 'Unknown error');
                            $fullMsg = $apiResult['message'] ?? '';
                            if (isBeneficiaryError($errReason) || isBeneficiaryError($fullMsg)) {
                                $status = 'unverified';
                                $message = "Beneficiary Pending List";
                                $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                                $stmtUpdate->execute([$status, $message, $orderId]);
                                $success = "Order #{$orderId} submitted for verification (Beneficiary pending list).";
                                $availableGb -= $amount;
                            } elseif (isInsufficientBalanceError($errReason) || isInsufficientBalanceError($fullMsg)) {
                                $status = 'pending';
                                $message = "Pending manual dispatch";
                                $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ? WHERE id = ? AND status NOT IN ('completed', 'successful', 'sucessfully', 'failed', 'refunded')");
                                $stmtUpdate->execute([$status, $message, $orderId]);
                                $success = "Successfully submitted a request to send {$amount} GB to {$recipient}. (Status: Pending)";
                                $availableGb -= $amount;
                            } else {
                                $status = 'failed';
                                $message = $errReason;
                                $stmtUpdate = $pdo->prepare("UPDATE bundle_sends SET status = ?, message = ?, refund_transferred = 1 WHERE id = ?");
                                $stmtUpdate->execute([$status, $message, $orderId]);
                                if ($amount > 0) {
                                    addWalletTransaction($pdo, $userId, $amount, 'credit', 'REFUND-' . $orderId, "Refund for failed supplier order #{$orderId}: " . $errReason);
                                }
                                $errors[] = htmlspecialchars($errReason, ENT_QUOTES, 'UTF-8');
                            }
                        }
                    } else {
                        $success = "Successfully submitted a request to send {$amount} GB to {$recipient}.";
                        $availableGb -= $amount;
                    }
            } catch (Exception $e) {
                $errors[] = "Failed to send bundle: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
            }
        }
    }
    }
    
    // Low Balance Check
    if (isset($success) && $success !== '' && $availableGb < 5) {
        if (empty($_SESSION['low_balance_notified_MTN'])) {
            require_once __DIR__ . '/classes/SmsApi.php';
            $stmtPhone = $pdo->prepare('SELECT phone FROM users WHERE id = :id');
            $stmtPhone->execute(['id' => $userId]);
            $userPhone = $stmtPhone->fetchColumn();
            if ($userPhone) {
                $smsApi = new SmsApi();
                $message = "Low MTN balance alert! Your new balance is " . number_format($availableGb, 2) . " GB. Please top up soon.";
                $smsApi->sendSms($userPhone, $message);
                $_SESSION['low_balance_notified_MTN'] = true;
            }
        }
    } elseif ($availableGb >= 5) {
        unset($_SESSION['low_balance_notified_MTN']);
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Apex Prime">
<link rel="apple-touch-icon" href="/image/icon-192.png">
<link rel="manifest" href="/manifest.json">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no, viewport-fit=cover">
<title>Send MTN Bundle Â· Apex Prime Admin</title>
<meta name="theme-color" content="#facc15">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<script src="/js/script.js?v=<?= filemtime(__DIR__ . '/js/script.js') ?>"></script>
<style>
  /* â”€â”€ Page overrides â”€â”€ */
  .dashboard-page { font-size: 13px; }
  .dashboard-page .card { padding: 0.875rem; }
  .dashboard-page .page-title { font-size: 1rem; margin-bottom: 0.2rem; }
  .dashboard-page .page-subtitle { font-size: 0.7rem; margin-bottom: 1rem; color: var(--text-secondary); }

  
  /* Premium Stat Card Colors */
  .dashboard-page .stat-card-mtn { 
      background: linear-gradient(135deg, #fef08a 0%, #fde047 100%); 
      border: 1px solid #facc15; 
      box-shadow: 0 4px 15px rgba(250, 204, 21, 0.2); 
      color: #713f12;
  }
  .dark .dashboard-page .stat-card-mtn { 
      background: linear-gradient(135deg, rgba(250, 204, 21, 0.15) 0%, rgba(250, 204, 21, 0.05) 100%); 
      border-color: rgba(250, 204, 21, 0.3); 
      box-shadow: none; 
      color: #fef08a;
  }

  /* Form styling */
  .form-control {
      width: 100%;
      border-radius: 12px !important;
      padding: 0.75rem 1rem !important;
      font-size: 0.85rem !important;
      background-color: var(--surface-color) !important;
      border: 2px solid var(--border-color) !important;
      color: var(--text-primary);
      font-family: inherit;
      transition: all 0.2s ease !important;
  }
  .form-control:focus {
      outline: none;
      border-color: #facc15 !important;
      box-shadow: 0 0 0 4px rgba(250, 204, 21, 0.15) !important;
      background-color: var(--surface-color) !important;
  }
  .form-label { font-size: 0.75rem !important; font-weight: 700 !important; color: var(--text-secondary) !important; }
  
  /* Brand-specific button & badge styles */
  .mode-switch-container {
      --switch-color: #facc15;
      --switch-color-shadow: rgba(250, 204, 21, 0.3);
  }
  .mode-switch-btn.active {
      color: #713f12 !important;
  }
  
  .brand-btn-primary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 0.75rem 1.5rem !important;
      font-size: 0.82rem !important;
      border-radius: 12px !important;
      font-weight: 700 !important;
      border: none !important;
      background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
      color: white !important;
      box-shadow: 0 6px 16px rgba(245, 158, 11, 0.3) !important;
      cursor: pointer;
      transition: all 0.25s ease;
      width: auto;
  }
  .brand-btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(245, 158, 11, 0.4) !important;
  }
  
  .brand-btn-toggle {
      padding: 0.45rem 0.9rem !important;
      font-size: 0.75rem !important;
      border-radius: 10px !important;
      font-weight: 700 !important;
      border: 1.5px solid #facc15 !important;
      background-color: transparent !important;
      color: #b45309 !important;
      white-space: nowrap !important;
      transition: all 0.25s ease;
      cursor: pointer;
  }
  .brand-btn-toggle:hover {
      background-color: #fffbeb !important;
      transform: translateY(-1px);
  }
  .dark .brand-btn-toggle {
      color: #fde047 !important;
  }
  .dark .brand-btn-toggle:hover {
      background-color: rgba(250, 204, 21, 0.1) !important;
  }
  
  /* Spinner */
  @keyframes spin { 100% { transform: rotate(360deg); } }
  #submitBtnLoading { display: none; }
  
  /* Loading Overlay */
  #fullScreenLoader {
      position: fixed;
      top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.8);
      z-index: 9999;
      display: none;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: white;
  }
</style>
</head>
<body class="dashboard-page">
<?php if ($success): ?>
    <div id="successModal" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 999999; padding: 1rem;">
        <div style="background: white; border-radius: var(--radius-lg); padding: 1.5rem; width: 100%; max-width: 320px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); animation: scaleUp 0.3s ease-out;">
            <div style="width: 50px; height: 50px; background-color: #dcfce7; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem auto;">
                <i class="fas fa-check" style="color: #22c55e; font-size: 1.5rem;"></i>
            </div>
            <h2 style="font-size: 1.15rem; font-weight: 800; color: #1e293b; margin-bottom: 0.5rem;">Success!</h2>
            <p style="font-size: 0.9rem; color: #64748b; margin-bottom: 1.5rem; line-height: 1.4;">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <button onclick="document.getElementById('successModal').style.display='none'" class="btn btn-primary" style="width: 100%; padding: 0.6rem; font-size: 0.9rem; font-weight: 700; border-radius: 0.4rem; background-color: #22c55e; border: none; box-shadow: 0 4px 6px rgba(34, 197, 94, 0.2);">OK</button>
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
    <style>
        @keyframes scaleUp {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
    </style>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div id="errorModal" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 999999; padding: 1rem;">
        <div style="background: white; border-radius: var(--radius-lg); padding: 1.5rem; width: 100%; max-width: 320px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); animation: scaleUp 0.3s ease-out;">
            <div style="width: 50px; height: 50px; background-color: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem auto;">
                <i class="fas fa-exclamation-triangle" style="color: #ef4444; font-size: 1.5rem;"></i>
            </div>
            <h2 style="font-size: 1.15rem; font-weight: 800; color: #1e293b; margin-bottom: 0.5rem;">Warning</h2>
            <div style="font-size: 0.9rem; color: #64748b; margin-bottom: 1.5rem; line-height: 1.4; text-align: left;">
                <ul style="margin: 0; padding-left: 1.2rem; list-style-type: disc;">
                    <?php foreach ($errors as $error): ?>
                        <li style="margin-bottom: 0.25rem;"><?= $error ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button onclick="document.getElementById('errorModal').style.display='none'" class="btn btn-primary" style="width: 100%; padding: 0.6rem; font-size: 0.9rem; font-weight: 700; border-radius: 0.4rem; background-color: #ef4444; border: none; box-shadow: 0 4px 6px rgba(239, 68, 68, 0.2);">OK</button>
        </div>
    </div>
<?php endif; ?>

<!-- NEW BENEFICIARY WARNING MODAL -->

<!-- MTN ORDER CONFIRMATION MODAL -->
<div id="sendMtnConfirmModal" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 999999; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 20px; padding: 1.5rem; width: 100%; max-width: 420px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); animation: scaleUp 0.25s ease-out; border: 2.5px solid #3b82f6; position: relative;">
        <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div style="width: 38px; height: 38px; background-color: #dbeafe; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="fas fa-question-circle" style="color: #2563eb; font-size: 1.25rem;"></i>
                </div>
                <h3 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0; font-family: 'Inter', sans-serif;">
                    Confirm Order
                </h3>
            </div>
            <button type="button" onclick="closeSendMtnConfirmModal()" style="background: transparent; border: none; font-size: 1.35rem; color: #94a3b8; cursor: pointer; padding: 0.2rem;" aria-label="Close">&times;</button>
        </div>

        <p style="font-size: 0.88rem; color: #475569; margin-bottom: 1rem; line-height: 1.5; font-family: 'Inter', sans-serif;">
            Are you sure you want to proceed with this MTN data purchase?
        </p>

        <!-- Airtime/Credit Debt Notice -->
        <div style="background-color: #fff7ed; border: 1.5px solid #ffedd5; border-left: 4px solid #ea580c; border-radius: 12px; padding: 0.9rem 1rem; margin-bottom: 1.25rem; text-align: left; font-family: 'Inter', sans-serif;">
            <div style="display: flex; align-items: center; gap: 0.45rem; color: #c2410c; font-weight: 800; font-size: 0.85rem; margin-bottom: 0.35rem;">
                <i class="fas fa-exclamation-circle" style="font-size: 0.95rem;"></i> Airtime/Credit Debt Notice
            </div>
            <div style="font-size: 0.8rem; color: #9a3412; line-height: 1.45; font-weight: 500;">
                If you have unpaid credit or airtime debts, please settle them before placing new orders. Data cannot be delivered to numbers with outstanding balances.
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem;">
            <button type="button" onclick="closeSendMtnConfirmModal()" style="padding: 0.8rem 1rem; border-radius: 12px; font-size: 0.88rem; font-weight: 700; background-color: #64748b; color: #ffffff; border: none; cursor: pointer; font-family: 'Inter', sans-serif;">
                Cancel
            </button>
            <button type="button" onclick="proceedSendMtnForm()" style="padding: 0.8rem 1rem; border-radius: 12px; font-size: 0.88rem; font-weight: 700; background-color: #2563eb; color: #ffffff; border: none; cursor: pointer; font-family: 'Inter', sans-serif; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);">
                Confirm
            </button>
        </div>
    </div>
</div>

<div class="app-wrapper">
    <!-- Main Content -->
    <main class="main-content" style="padding: 0; background-color: #f8fafc;">
        <?php 
        $pageTitle = 'MTN Bundle';
        include __DIR__ . '/header.php'; 
        ?>
        <div class="container fade-up" style="max-width: 800px; margin: 0 auto; padding-top: 1.5rem;">
            <?php 
            $isSuperAgentOrAdmin = in_array(strtolower($_SESSION['user']['role'] ?? ''), ['super agent', 'super_agent', 'admin', 'elite']);
            if (!$isSuperAgentOrAdmin): 
            ?>
                <div style="text-align: center; padding: 4rem 2rem; background: white; border-radius: 14px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 2rem;">
                    <i class="fas fa-lock" style="font-size: 3.5rem; color: #ef4444; margin-bottom: 1.5rem;"></i>
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin-bottom: 0.5rem;">Access Denied</h3>
                    <p style="color: #64748b; font-size: 0.85rem; line-height: 1.6; max-width: 400px; margin: 0 auto 2rem auto;">
                        This page is strictly reserved for <strong>Admins, Elite, and Super Agents</strong>.<br>
                        Please contact the administrator for more information.
                    </p>
                    <a href="store" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; border-radius: 99px; text-decoration: none; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); transition: transform 0.2s, box-shadow 0.2s;">
                        <i class="fas fa-store"></i> Go to Agent Store
                    </a>
                </div>
            <?php else: ?>
            
            <!-- MTN Banner -->
            <div style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 1.25rem 1.5rem; text-align: center; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(37,99,235,0.25); border-radius: 0 0 24px 24px;">
                <h1 style="font-size: 1.35rem; font-weight: 800; color: #ffffff; margin-bottom: 0.4rem; letter-spacing: 0.02em; text-transform: uppercase;">MTN DATA BUNDLE</h1>
                <p style="font-size: 0.9rem; font-weight: 500; color: #ffffff; opacity: 0.9; margin: 0 auto; line-height: 1.4; max-width: 600px;">Fast & reliable connectivity with MTN high-speed bundles and strong network coverage.</p>
            </div>
            
            <div class="grid grid-cols-1" style="gap: 1.5rem; margin-bottom: 2rem;">
                <?php if (!$isMtnActive && $role !== 'admin'): ?>
                    <div style="background: rgba(239, 68, 68, 0.1); border: 1.5px solid rgba(239, 68, 68, 0.3); border-radius: 16px; padding: 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 1rem; color: #ef4444;">
                        <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(239, 68, 68, 0.15); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;">
                            <i class="fas fa-tools"></i>
                        </div>
                        <div>
                            <div style="font-weight: 800; font-size: 0.95rem; margin-bottom: 0.2rem;">MTN Service Maintenance</div>
                            <div style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.4;">
                                MTN Data / Group Share orders are temporarily offline for maintenance. Please check back shortly or try another network.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Send Form -->
                <div class="card" <?= (!$isMtnActive && $role !== 'admin') ? 'style="opacity: 0.6; pointer-events: none;"' : '' ?>>
                    <div style="text-align: center; margin-bottom: 1.5rem;">
                        <div class="mode-switch-container">
                            <div class="switch-slider" id="switchSlider"></div>
                            <button type="button" class="mode-switch-btn active" id="mode-single" onclick="setMode('single')">
                                <i class="fas fa-user"></i> Single
                            </button>
                            <button type="button" class="mode-switch-btn" id="mode-bulk" onclick="setMode('bulk')">
                                <i class="fas fa-exchange-alt"></i> Bulk
                            </button>
                        </div>
                    </div>
                    <form method="POST" id="sendMtnForm" onsubmit="return handleSendMtnSubmit(event)">
                        <input type="hidden" name="mode" id="formMode" value="single">
                        
                        <div style="max-width: 380px; margin: 0 auto; width: 100%;">
                            <div id="singleFields">
                                <div style="margin-bottom: 1rem;">
                                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.4rem;">Recipient Phone Number</label>
                                    <div style="position: relative; display: flex; align-items: center;">
                                        <input type="tel" name="recipient" id="recipientInput" required class="form-control" placeholder="e.g. 024XXXXXXX" pattern="[0-9]{10}" title="Enter a valid 10-digit phone number" style="padding-right: 2.5rem;">
                                        <div id="phoneNetworkBadge" style="position: absolute; right: 0.6rem; display: none; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; overflow: hidden; border: 1px solid #cbd5e1; background: #fff;">
                                            <img id="phoneNetworkImg" src="" style="width: 100%; height: 100%; object-fit: cover;">
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="margin-bottom: 1.5rem;">
                                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.4rem;">GB Amount to Send</label>
                                    <input type="number" step="0.01" min="1" max="100" name="amount" id="amountInput" required class="form-control" placeholder="e.g. 5">
                                </div>
                            </div>

                            <div id="bulkFields" style="display: none; margin-bottom: 1.5rem;">
                                <div class="bulk-editor-wrapper">
                                    <div class="bulk-editor-header">
                                        <label class="form-label" style="margin: 0 !important;">Bulk Numbers & Amounts</label>
                                        <div class="bulk-editor-actions">
                                            <button type="button" class="bulk-editor-action-btn" onclick="loadSampleBulk()">Sample</button>
                                            <button type="button" class="bulk-editor-action-btn" onclick="clearBulk()">Clear</button>
                                            <button type="button" class="bulk-editor-action-btn" onclick="pasteMatrix()">Paste</button>
                                        </div>
                                    </div>
                                    <textarea name="bulk_data" id="bulkInput" class="bulk-textarea" placeholder="e.g.&#10;0241234567 1.5&#10;0547654321 2.5"></textarea>
                                    <div class="bulk-editor-footer">
                                        <div class="bulk-stat">
                                            Recipients: <strong id="bulkCounter">0</strong> | Total GB: <strong id="bulkTotalGb">0.00 GB</strong>
                                        </div>
                                        <div class="bulk-status" id="bulkStatusIcon">
                                            <i class="fas fa-info-circle text-secondary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="display: flex; justify-content: center; margin-top: 1.25rem;">
                                <button type="submit" id="submitBtn" class="brand-btn-primary">
                                    <span id="submitBtnContent">Send Bundle</span>
                                    <span id="submitBtnLoading" style="display: none;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="animation: spin 0.8s linear infinite;">
                                            <circle cx="12" cy="12" r="10" stroke="rgba(255, 255, 255, 0.3)" stroke-width="3"/>
                                            <path d="M12 2a10 10 0 0 1 10 10" stroke="#ffffff" stroke-width="3" stroke-linecap="round"/>
                                        </svg>
                                        Processing...
                                    </span>
                                </button>
                            </div>
                            
                            <div style="text-align: center; margin-top: 1rem; font-size: 0.85rem; color: var(--text-secondary); font-weight: 500;">
                                Available Balance: <span style="font-weight: 700; color: var(--text-primary);"><?= number_format($availableGb, 2) ?> GB</span>
                            </div>
                        </div>
                    </form>
                    <script>
                        function setMode(mode) {
                            var single = document.getElementById('singleFields');
                            var bulk = document.getElementById('bulkFields');
                            var modeInput = document.getElementById('formMode');
                            var recInput = document.getElementById('recipientInput');
                            var amtInput = document.getElementById('amountInput');
                            var blkInput = document.getElementById('bulkInput');
                            var badge = document.getElementById('phoneNetworkBadge');
                            
                            modeInput.value = mode;
                            document.getElementById('mode-single').classList.toggle('active', mode === 'single');
                            document.getElementById('mode-bulk').classList.toggle('active', mode === 'bulk');
                            
                            const slider = document.getElementById('switchSlider');
                            if (slider) {
                                slider.style.transform = mode === 'single' ? 'translateX(0)' : 'translateX(100%)';
                            }
                            
                            if (mode === 'single') {
                                single.style.display = 'block';
                                bulk.style.display = 'none';
                                recInput.required = true;
                                amtInput.required = true;
                                blkInput.required = false;
                                blkInput.value = '';
                                document.getElementById('submitBtnContent').textContent = 'Send Bundle';
                            } else {
                                single.style.display = 'none';
                                bulk.style.display = 'block';
                                recInput.required = false;
                                amtInput.required = false;
                                blkInput.required = true;
                                recInput.value = '';
                                amtInput.value = '';
                                badge.style.display = 'none';
                                document.getElementById('submitBtnContent').textContent = 'Send Bulk';
                            }
                        }

                        document.getElementById('recipientInput').addEventListener('input', function() {
                            let val = this.value.trim();
                            let badge = document.getElementById('phoneNetworkBadge');
                            let img = document.getElementById('phoneNetworkImg');
                            if (val.length >= 3) {
                                let prefix = val.substring(0, 3);
                                let mtnPrefixes = ['024', '054', '055', '059', '025', '053'];
                                let telPrefixes = ['020', '050'];
                                let ishPrefixes = ['026', '056', '027', '057'];
                                
                                if (mtnPrefixes.includes(prefix)) {
                                    img.src = '/image/MTN.jpg';
                                    badge.style.display = 'flex';
                                } else if (telPrefixes.includes(prefix)) {
                                    img.src = '/image/TELECEL.jpg';
                                    badge.style.display = 'flex';
                                } else if (ishPrefixes.includes(prefix)) {
                                    img.src = '/image/Ishare.png';
                                    badge.style.display = 'flex';
                                } else {
                                    badge.style.display = 'none';
                                }
                            } else {
                                badge.style.display = 'none';
                            }
                        });
                    </script>
                </div>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <footer style="margin-top: 3rem; padding: 1.5rem 0; text-align: center; font-size: 0.72rem; font-weight: 500; color: var(--text-secondary); border-top: 1px solid var(--border-color); font-family: 'Inter', sans-serif; opacity: 0.8;">
                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                    <span style="display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.95rem;">Developed by <span style="font-weight: 800; background: linear-gradient(135deg, #3b82f6, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Apex Prime Technology</span></span>
                    <span style="font-size: 0.85rem;">@ 2026</span>
                </div>
            </footer>
        </div>
    </main>
</div>

<!-- Full Screen Loader -->
<div id="fullScreenLoader">
    <div style="background: white; padding: 2rem; border-radius: 1rem; display: flex; flex-direction: column; align-items: center; gap: 1rem;">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" style="animation: spin 1s linear infinite;">
            <circle cx="12" cy="12" r="10" stroke="#fef08a" stroke-width="4"/>
            <path d="M12 2a10 10 0 0 1 10 10" stroke="#facc15" stroke-width="4" stroke-linecap="round"/>
        </svg>
        <div style="color: #713f12; font-weight: 700; font-family: Inter, sans-serif;">Sending Bundle...</div>
    </div>
</div>

<script>
function showLoader() {
    document.getElementById('submitBtnContent').style.display = 'none';
    document.getElementById('submitBtnLoading').style.display = 'inline-flex';
    document.getElementById('submitBtnLoading').style.alignItems = 'center';
    document.getElementById('submitBtnLoading').style.gap = '0.5rem';
    document.getElementById('submitBtn').style.opacity = '0.8';
    document.getElementById('submitBtn').style.cursor = 'not-allowed';
    
    document.getElementById('fullScreenLoader').style.display = 'flex';
}

function filterTable() {
    let input = document.getElementById("searchInput");
    let filter = input.value.toUpperCase();
    let table = document.getElementById("recentTable");
    if (!table) return;
    let tr = table.getElementsByTagName("tr");
    
    for (let i = 1; i < tr.length; i++) {
        let td = tr[i].getElementsByTagName("td")[1]; 
        if (td) {
            let txtValue = td.textContent || td.innerText;
            if (txtValue.toUpperCase().indexOf(filter) > -1) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }
    }
}

function loadSampleBulk() {
    const sampleText = "0241234567 1.5\n0547654321 2.0";
    const bulkInput = document.getElementById('bulkInput');
    if (bulkInput) {
        bulkInput.value = sampleText;
        bulkInput.dispatchEvent(new Event('input'));
    }
}

function clearBulk() {
    const bulkInput = document.getElementById('bulkInput');
    if (bulkInput) {
        bulkInput.value = '';
        bulkInput.dispatchEvent(new Event('input'));
    }
}

async function pasteMatrix() {
    try {
        const text = await navigator.clipboard.readText();
        const bulkInput = document.getElementById('bulkInput');
        if (bulkInput) {
            bulkInput.value = text;
            bulkInput.dispatchEvent(new Event('input'));
        }
    } catch(e) {
        alert("Please paste manually using Ctrl+V or Long Press.");
    }
}

document.getElementById('bulkInput').addEventListener('input', function() {
    let text = this.value;
    let lines = text.split('\n').map(l => l.trim()).filter(l => l.length > 0);
    let count = lines.length;
    let totalGb = 0;
    let hasError = false;
    let errorReason = "";
    
    const mtnPrefixes = ['024', '054', '055', '059', '025', '053'];
    
    lines.forEach(line => {
        let parts = line.split(/[\s,]+/);
        if (parts.length >= 2) {
            let num = parts[0].trim();
            let gb = parseFloat(parts[1].trim());
            
            let validPrefix = mtnPrefixes.includes(num.substring(0, 3));
            if (num.match(/^[0-9]{10}$/) && validPrefix && !isNaN(gb) && gb > 0) {
                totalGb += gb;
            } else {
                hasError = true;
                if (!num.match(/^[0-9]{10}$/)) {
                    errorReason = "Phone must be 10 digits";
                } else if (!validPrefix) {
                    errorReason = "Contains non-MTN number";
                } else {
                    errorReason = "Invalid GB amount";
                }
            }
        } else {
            hasError = true;
            errorReason = "Format must be Number GB";
        }
    });
    
    document.getElementById('bulkCounter').textContent = count;
    document.getElementById('bulkTotalGb').textContent = totalGb.toFixed(2) + ' GB';
    
    let statusIcon = document.getElementById('bulkStatusIcon');
    if (statusIcon) {
        if (count === 0) {
            statusIcon.innerHTML = '<i class="fas fa-info-circle text-secondary"></i>';
        } else if (hasError) {
            statusIcon.innerHTML = `<i class="fas fa-exclamation-triangle text-danger"></i> <span class="text-danger" style="font-size:0.75rem;">${errorReason}</span>`;
        } else {
            statusIcon.innerHTML = '<i class="fas fa-check-circle text-success"></i> <span class="text-success" style="font-size:0.75rem;">Valid formatting</span>';
        }
    }
});

let isSubmittingMtnForm = false;
let detectedNewNumbers = [];

function downloadNewBeneficiariesCSV() {
    if (!detectedNewNumbers || detectedNewNumbers.length === 0) return;
    let csvContent = "data:text/csv;charset=utf-8,Phone Number\n" + detectedNewNumbers.join("\n");
    let encodedUri = encodeURI(csvContent);
    let link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "new_beneficiary_numbers.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function handleSendMtnSubmit(e) {
    if (e) e.preventDefault();
    if (isSubmittingMtnForm) {
        showLoader();
        document.getElementById('sendMtnForm').submit();
        return true;
    }
    // Show confirmation modal directly — no number verification check
    document.getElementById('sendMtnConfirmModal').style.display = 'flex';
    return false;
}


function closeSendMtnConfirmModal() {
    document.getElementById('sendMtnConfirmModal').style.display = 'none';
}

function proceedSendMtnForm() {
    document.getElementById('sendMtnConfirmModal').style.display = 'none';
    isSubmittingMtnForm = true;
    showLoader();
    if (typeof playOrderSuccessSound === 'function') {
        playOrderSuccessSound();
    }
    document.getElementById('sendMtnForm').submit();
}
</script>

</body>
</html>

