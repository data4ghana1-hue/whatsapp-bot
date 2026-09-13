<?php
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        file_put_contents(__DIR__ . '/fatal_error.log', json_encode($error));
    }
});

require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_paystack') {
    header('Content-Type: application/json');
    if (empty($_SESSION['user'])) {
        echo json_encode(['success' => false, 'message' => 'User not logged in.']);
        exit;
    }
    
    $reference = trim($_POST['reference'] ?? '');
    $network = trim($_POST['network'] ?? '');
    $bundleSize = trim($_POST['bundle_size'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    
    if (empty($reference)) {
        echo json_encode(['success' => false, 'message' => 'Missing transaction reference.']);
        exit;
    }
    
    $pdo = db_connect();
    
    // Prevent double crediting
    $stmt = $pdo->prepare("SELECT id FROM topups WHERE transaction_id = ? LIMIT 1");
    $stmt->execute([$reference]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Duplicate transaction: Reference already verified.']);
        exit;
    }
    
    // Verify transaction with Paystack API
    $secretKey = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '';
    if (empty($secretKey)) {
        echo json_encode(['success' => false, 'message' => 'Configuration Error: Paystack Secret Key missing on server.']);
        exit;
    }
    $url = 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secretKey,
        'Cache-Control: no-cache'
    ]);
    
    $responseJson = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        echo json_encode(['success' => false, 'message' => 'Paystack API curl error: ' . $err]);
        exit;
    }
    
    $responseData = json_decode($responseJson, true);
    if (!isset($responseData['status']) || !$responseData['status'] || !isset($responseData['data'])) {
        echo json_encode(['success' => false, 'message' => 'Paystack verification failed: ' . ($responseData['message'] ?? 'Unknown error')]);
        exit;
    }
    
    $txData = $responseData['data'];
    if ($txData['status'] !== 'success') {
        echo json_encode(['success' => false, 'message' => 'Transaction was not successful. Status: ' . $txData['status']]);
        exit;
    }
    
    // Validate currency and amount
    $verifiedCurrency = strtoupper($txData['currency']);
    if ($verifiedCurrency !== 'GHS') {
        echo json_encode(['success' => false, 'message' => 'Invalid currency: expected GHS, got ' . $verifiedCurrency]);
        exit;
    }
    
    // Paystack returns amount in pesewas. GHS 1 = 100 pesewas.
    $verifiedAmountGhs = (float)($txData['amount'] / 100);
    // Allow slight rounding differences
    $expectedTotalPayable = $amount * 1.02;
    if (abs($verifiedAmountGhs - $expectedTotalPayable) > 0.05) {
        echo json_encode(['success' => false, 'message' => 'Amount mismatch: expected GHS ' . number_format($expectedTotalPayable, 2) . ' (incl. 2% charge), got GHS ' . number_format($verifiedAmountGhs, 2)]);
        exit;
    }
    
    // Process top-up
    try {
        $pdo->beginTransaction();
        
        $user = $_SESSION['user'];
        $userId = (int)$user['id'];
        
        $phoneDetails = 'Wallet Topup via Paystack';
        
        $stmt = $pdo->prepare('INSERT INTO topups (user_id, network, phone, amount, status, transaction_id) VALUES (:user_id, :network, :phone, :amount, "approved", :tx_id)');
        $stmt->execute([
            'user_id' => $userId,
            'network' => $network,
            'phone'   => $phoneDetails,
            'amount'  => $amount,
            'tx_id'   => $reference,
        ]);
        
        addWalletTransaction($pdo, $userId, $amount, 'credit', 'PAYSTACK-' . $reference, 'Wallet funding via Paystack');
        
        $pdo->commit();
        
        // Optional SMS
        try {
            require_once __DIR__ . '/classes/SmsHelper.php';
            if (class_exists('SmsHelper')) {
                SmsHelper::sendTopupApprovedSMS($pdo, $userId, $network, $amount, $amount);
            }
        } catch (Exception $smsEx) {}
        
        $_SESSION['topup_message'] = "success|Online Payment Successful! GHS " . number_format($amount, 2) . " has been credited to your account.";
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

if (isset($_GET['reference'])) {
    $reference = trim($_GET['reference']);
    if (!empty($reference)) {
        $pdo = db_connect();
        $stmt = $pdo->prepare("SELECT id, status, user_id, amount FROM topups WHERE transaction_id = ? LIMIT 1");
        $stmt->execute([$reference]);
        $existingOrder = $stmt->fetch(PDO::FETCH_ASSOC);

        // If order exists but is pending, or is new reference, verify and credit instantly
        if (!$existingOrder || $existingOrder['status'] !== 'approved') {
            $secretKey = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '';
            if (empty($secretKey) && file_exists(__DIR__ . '/admin_settings.json')) {
                $set = json_decode(file_get_contents(__DIR__ . '/admin_settings.json'), true);
                $secretKey = $set['paystack_secret_key'] ?? '';
            }

            if (!empty($secretKey)) {
                $url = 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secretKey, 'Cache-Control: no-cache']);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $responseJson = curl_exec($ch);
                curl_close($ch);

                if ($responseJson) {
                    $responseData = json_decode($responseJson, true);
                    if (isset($responseData['status']) && $responseData['status'] && isset($responseData['data']) && $responseData['data']['status'] === 'success') {
                        $txData = $responseData['data'];
                        $verifiedCurrency = strtoupper($txData['currency'] ?? 'GHS');
                        if ($verifiedCurrency === 'GHS') {
                            $verifiedAmountGhs = (float)($txData['amount'] / 100);
                            $baseAmount = round($verifiedAmountGhs / 1.02, 2); // Reverse 2% fee
                            if ($baseAmount <= 0) $baseAmount = $verifiedAmountGhs;

                            $user = $_SESSION['user'] ?? [];
                            $userId = (int)($user['id'] ?? ($existingOrder['user_id'] ?? 0));

                            if ($userId > 0) {
                                try {
                                    $pdo->beginTransaction();

                                    // Lock user row for balance update
                                    $stmtLock = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
                                    $stmtLock->execute([$userId]);
                                    $balBefore = (float)$stmtLock->fetchColumn();
                                    $balAfter = $balBefore + $baseAmount;

                                    if ($existingOrder) {
                                        $stmtUpdate = $pdo->prepare("UPDATE topups SET status = 'approved', amount = :amount, balance_before = :b_before, balance_after = :b_after WHERE id = :id");
                                        $stmtUpdate->execute(['amount' => $baseAmount, 'b_before' => $balBefore, 'b_after' => $balAfter, 'id' => $existingOrder['id']]);
                                    } else {
                                        $stmtInsert = $pdo->prepare('INSERT INTO topups (user_id, network, phone, amount, status, transaction_id, balance_before, balance_after) VALUES (:user_id, "Paystack", :phone, :amount, "approved", :tx_id, :b_before, :b_after)');
                                        $stmtInsert->execute([
                                            'user_id'  => $userId,
                                            'phone'    => 'Wallet Topup - GHS ' . number_format($baseAmount, 2),
                                            'amount'   => $baseAmount,
                                            'tx_id'    => $reference,
                                            'b_before' => $balBefore,
                                            'b_after'  => $balAfter
                                        ]);
                                    }

                                    addWalletTransaction($pdo, $userId, $baseAmount, 'credit', 'PAYSTACK-WEB-' . $reference, 'Wallet funding via Paystack');
                                    $pdo->commit();

                                    // Update session balance for instant UI reflect without relogin
                                    $stmtBal = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
                                    $stmtBal->execute([$userId]);
                                    $_SESSION['user']['wallet_balance'] = $stmtBal->fetchColumn();

                                    // Send Topup SMS
                                    try {
                                        require_once __DIR__ . '/classes/SmsHelper.php';
                                        if (class_exists('SmsHelper')) {
                                            SmsHelper::sendTopupApprovedSMS($pdo, $userId, 'Wallet Topup', $baseAmount, $baseAmount);
                                        }
                                    } catch (Exception $smsEx) {}

                                    // Send Push Notification
                                    try {
                                        require_once __DIR__ . '/classes/NotificationHelper.php';
                                        NotificationHelper::sendPush(
                                            $userId,
                                            "Wallet Credited! 💰",
                                            "Your wallet has been credited with GHS " . number_format($baseAmount, 2) . " via Paystack.",
                                            APP_URL . "dashboard"
                                        );
                                    } catch (Exception $pushEx) {}

                                    $_SESSION['topup_message'] = "success|Online Payment Successful! GHS " . number_format($baseAmount, 2) . " has been instantly credited and approved!";
                                } catch (Exception $e) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    header('Location: topup.php');
    exit;
}

if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $refParam = trim($_GET['reference'] ?? $_GET['trxref'] ?? $_SESSION['last_paystack_ref'] ?? '');
    $refUrl = !empty($refParam) ? '&reference=' . urlencode($refParam) : '';
    $_SESSION['topup_message'] = "success|Online Payment initiated. Your wallet will be credited shortly via our automated system once Paystack confirms.";
    header('Location: topup.php?auto_verify=1' . $refUrl);
    exit;
}

if (isset($_SESSION['topup_message'])) {
    $message = $_SESSION['topup_message'];
    unset($_SESSION['topup_message']);
} else {
    $message = '';
}

$pdo = db_connect();
$settings = load_all_settings($pdo);
$paystackEnabled = !empty($settings['paystack_enabled']);

if (empty($_SESSION['user'])) {
    header('Location: ' . APP_URL . 'login');
    exit;
}

$user = $_SESSION['user'];
$userId = (int)$user['id'];
$errors = [];
// $message is already defined by session logic
$claimDetails = null;

$userEmail = '';
try {
    $stmtEmail = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmtEmail->execute([$userId]);
    $userEmail = $stmtEmail->fetchColumn();
} catch (Exception $e) {}
if (empty($userEmail)) {
    $userEmail = $user['username'] . '@apexprime.com';
}

// Load active pricing packages from DB (fallback to empty if table not yet created)
$pricingPackages = [];
try {
    $pricingPackages = $pdo->query("SELECT * FROM network_pricing WHERE is_active=1 ORDER BY network, sort_order, gb_amount")->fetchAll();
} catch (Exception $e) {
    // Table doesn't exist yet â€” silently ignore; hardcoded fallback used below
    $pricingPackages = [];
}
// Group by network
$pricingByNetwork = [];
foreach ($pricingPackages as $p) {
    $pricingByNetwork[$p['network']][] = $p;
}

$userCustomAfaPrice = null;
try {
    $stmtAfaPrice = $pdo->prepare("SELECT afa_price FROM users WHERE id = ?");
    $stmtAfaPrice->execute([$userId]);
    $dbAfaPrice = $stmtAfaPrice->fetchColumn();
    if ($dbAfaPrice !== false && $dbAfaPrice !== null) {
        $userCustomAfaPrice = (float)$dbAfaPrice;
    }
} catch (Exception $e) {}

$baseAfaPrice = (float)($settings['mtn_afa_price'] ?? 10.00);
$afaRole = strtolower($user['role'] ?? '');
if ($userCustomAfaPrice !== null) {
    $afaPrice = $userCustomAfaPrice;
} else {
    $afaPrice = ($afaRole === 'super_agent' || $afaRole === 'admin') ? max(0, $baseAfaPrice - 1) : $baseAfaPrice;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'manual';

    if ($mode === 'manual') {
        $network     = trim($_POST['network'] ?? '');
        $bundleSize  = trim($_POST['bundle_size'] ?? '');

        if ($network === '') $errors[] = 'Please select a network.';
        if ($bundleSize === '') $errors[] = 'Please enter a bundle size.';

        if (empty($errors)) {
            try {
                $priceVal = 0.00;
                if ($network === 'Wallet Topup' || stripos($network, 'Wallet') !== false || stripos($network, 'Paystack') !== false) {
                    $priceVal = (float)preg_replace('/[^0-9.]/', '', $bundleSize);
                    $bundleSize = 'GHS ' . number_format($priceVal, 2);
                } elseif (preg_match('/GHS\s*([0-9.]+)/i', $bundleSize, $m)) {
                    $priceVal = (float)$m[1];
                } else {
                    $priceVal = (float)preg_replace('/[^0-9.]/', '', $bundleSize);
                }

                $stmt = $pdo->prepare('INSERT INTO topups (user_id, network, phone, amount, status) VALUES (:user_id, :network, :phone, :amount, "pending")');
                $stmt->execute([
                    'user_id' => $userId,
                    'network' => $network,
                    'phone'   => $bundleSize,
                    'amount'  => $priceVal,
                ]);
                $newOrderId  = $pdo->lastInsertId();
                $orderCode   = str_pad(($newOrderId * 397 + 137) % 900 + 100, 3, '0', STR_PAD_LEFT);
                $rawMomoNum = $settings['momo_number'] ?? '0530429556';
                $momoNum = htmlspecialchars($rawMomoNum, ENT_QUOTES, 'UTF-8');
                $momoName = htmlspecialchars($settings['momo_name'] ?? 'Sir Esarq Ent (Eric Fosu)', ENT_QUOTES, 'UTF-8');
                $formattedAmount = '<strong style="color: #fde047; font-weight: 800;">' . htmlspecialchars($bundleSize, ENT_QUOTES, 'UTF-8') . '</strong>';
                $formattedOrderId = '<strong style="color: #000000; font-weight: 900; letter-spacing: 0.04em;">' . htmlspecialchars($orderCode, ENT_QUOTES, 'UTF-8') . '</strong>';

                $copyNumBtn = '<button type=\"button\" class=\"copy-btn-badge js-copy-btn\" data-copy=\"' . htmlspecialchars($rawMomoNum, ENT_QUOTES, 'UTF-8') . '\" title=\"Copy Number\"><i class=\"far fa-copy\"></i></button>';
                $copyIdBtn = '<button type=\"button\" class=\"copy-btn-badge js-copy-btn\" data-copy=\"' . htmlspecialchars($orderCode, ENT_QUOTES, 'UTF-8') . '\" title=\"Copy Order ID\"><i class=\"far fa-copy\"></i></button>';

                $paymentBox = '<div style="margin-top: 1rem; background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.3); border-radius: 14px; padding: 0.85rem 1rem; text-align: left; color: #ffffff; font-size: 0.88rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">'
                    . '<div style="font-weight: 800; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.5rem; color: #ffffff; display: flex; align-items: center; gap: 0.4rem;"><i class=\"fas fa-money-bill-wave\"></i> Payment Details</div>'
                    . '<div style=\"display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;\"><span style=\"opacity: 0.9;\">MoMo Number:</span> <span style=\"display: inline-flex; align-items: center; gap: 0.45rem;\"><strong style=\"letter-spacing: 0.03em; font-family: monospace; font-size: 0.92rem;\">' . $momoNum . '</strong> ' . $copyNumBtn . '</span></div>'
                    . '<div style=\"display: flex; justify-content: space-between; margin-bottom: 0.35rem;\"><span style=\"opacity: 0.9;\">Account Name:</span> <strong style=\"text-align: right;\">' . $momoName . '</strong></div>'
                    . '<div style=\"display: flex; justify-content: space-between; margin-bottom: 0.35rem;\"><span style=\"opacity: 0.9;\">Amount:</span> ' . $formattedAmount . '</div>'
                    . '<div style=\"display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.25); padding-top: 0.45rem; margin-top: 0.45rem;\"><span style=\"opacity: 0.9;\">Order ID:</span> <span style=\"display: inline-flex; align-items: center; gap: 0.45rem;\">' . $formattedOrderId . ' ' . $copyIdBtn . '</span></div>'
                    . '<div style=\"margin-top: 0.5rem; font-size: 0.76rem; color: #334155; text-align: center; background: #f1f5f9; padding: 0.4rem 0.6rem; border-radius: 8px; font-weight: 700; box-shadow: 0 1px 3px rgba(0,0,0,0.1);\"><i class=\"fas fa-info-circle\" style=\"margin-right: 4px; color: #475569;\"></i> Use your order ID as payment reference</div>'
                    . '</div>';

                $message = "success|A top-up request has been submitted for <strong>{$network}</strong> of size {$formattedAmount}." . $paymentBox;
                
                // Redirect to prevent duplicate submission on refresh
                $_SESSION['topup_message'] = $message;
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            } catch (Exception $e) {
                $errors[] = 'Submission failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        }
    } elseif ($mode === 'verify_paystack_receipt') {
        $refInput = trim($_POST['paystack_ref'] ?? $_POST['reference'] ?? '');
        if (empty($refInput)) {
            $errors[] = 'Please enter a valid Paystack payment reference or receipt number.';
        } else {
            $secretKey = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '';
            if (empty($secretKey) && file_exists(__DIR__ . '/admin_settings.json')) {
                $set = json_decode(file_get_contents(__DIR__ . '/admin_settings.json'), true);
                $secretKey = $set['paystack_secret_key'] ?? '';
            }
            if (empty($secretKey)) {
                $errors[] = 'Paystack Secret Key is missing on the server.';
            } else {
                $stmtChk = $pdo->prepare("SELECT id FROM topups WHERE transaction_id = ? OR transaction_id = ? LIMIT 1");
                $stmtChk->execute([$refInput, 'PAYSTACK-' . $refInput]);
                if ($stmtChk->fetch()) {
                    $errors[] = 'This payment reference / receipt number has already been verified and credited.';
                } else {
                    $url = 'https://api.paystack.co/transaction/verify/' . rawurlencode($refInput);
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secretKey, 'Cache-Control: no-cache']);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    $resp = curl_exec($ch);
                    curl_close($ch);

                    $resData = json_decode($resp, true);
                    if (isset($resData['status']) && $resData['status'] && isset($resData['data']) && ($resData['data']['status'] ?? '') === 'success') {
                        $tx = $resData['data'];
                        $amountGhs = (float)($tx['amount'] / 100);
                        $baseCredit = round($amountGhs / 1.02, 2);
                        if ($baseCredit <= 0) $baseCredit = $amountGhs;

                        try {
                            $pdo->beginTransaction();
                            $stmtIns = $pdo->prepare("INSERT INTO topups (user_id, network, phone, amount, status, transaction_id) VALUES (:user_id, 'Wallet Topup via Paystack Receipt', :phone, :amount, 'approved', :tx_id)");
                            $stmtIns->execute([
                                'user_id' => $userId,
                                'phone'   => 'Paystack Receipt (' . $refInput . ')',
                                'amount'  => $baseCredit,
                                'tx_id'   => $refInput,
                            ]);
                            addWalletTransaction($pdo, $userId, $baseCredit, 'credit', 'PAYSTACK-REC-' . $refInput, 'Wallet funding via Paystack Receipt Verification');
                            $pdo->commit();

                            $_SESSION['user']['wallet_balance'] = getUserWalletBalance($pdo, $userId);
                            $message = 'success|Paystack Receipt Verified! GHS ' . number_format($baseCredit, 2) . ' has been credited to your wallet balance.';
                            $_SESSION['topup_message'] = $message;
                            header('Location: ' . $_SERVER['REQUEST_URI']);
                            exit;
                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $errors[] = 'Database error: ' . $e->getMessage();
                        }
                    } else {
                        $errors[] = 'Paystack Verification Failed: ' . ($resData['message'] ?? 'Transaction reference not found or invalid.');
                    }
                }
            }
        }
    } elseif ($mode === 'search_claim') {
        $txId = trim($_POST['tx_id'] ?? '');
        if (empty($txId)) {
            $errors[] = 'Please enter a Transaction ID.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM webhook_payments WHERE transaction_id = :tx_id LIMIT 1');
            $stmt->execute(['tx_id' => $txId]);
            $foundTx = $stmt->fetch();

            if (!$foundTx) {
                $errors[] = 'Transaction ID not found. Please ensure the payment was sent and try again in a few minutes.';
            } elseif ($foundTx['is_claimed'] == 1) {
                $errors[] = 'This transaction has already been claimed.';
            } else {
                $claimDetails = $foundTx;
            }
        }
    } elseif ($mode === 'confirm_claim') {
        $txId = trim($_POST['tx_id'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM webhook_payments WHERE transaction_id = :tx_id AND is_claimed = 0 LIMIT 1');
        $stmt->execute(['tx_id' => $txId]);
        $foundTx = $stmt->fetch();

        if ($foundTx) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('UPDATE webhook_payments SET is_claimed = 1, claimed_by_user_id = :user_id WHERE id = :id');
                $stmt->execute(['user_id' => $userId, 'id' => $foundTx['id']]);

                $r = strtolower(trim($user['role'] ?? ''));
                $isElevated = in_array($r, ['admin', 'client', 'vip', 'dealer', 'dealers', 'agent', 'reseller']);
                $targetNetwork = $isElevated ? 'Wallet Topup' : ($foundTx['provider'] ?? 'Unknown');

                $stmtBal = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
                $stmtBal->execute([$userId]);
                $balBefore = (float)$stmtBal->fetchColumn();
                $balAfter = $balBefore + (float)$foundTx['amount'];

                $stmt = $pdo->prepare('INSERT INTO topups (user_id, network, phone, amount, status, transaction_id, balance_before, balance_after) VALUES (:user_id, :network, :phone, :amount, "approved", :tx_id, :b_before, :b_after)');
                $stmt->execute([
                    'user_id'  => $userId,
                    'network'  => $targetNetwork,
                    'phone'    => 'Claimed via Tx ID',
                    'amount'   => $foundTx['amount'],
                    'tx_id'    => $foundTx['transaction_id'],
                    'b_before' => $balBefore,
                    'b_after'  => $balAfter
                ]);

                if ($isElevated) {
                    addWalletTransaction($pdo, $userId, $foundTx['amount'], 'credit', 'CLAIM-' . $foundTx['transaction_id'], 'Wallet funding via claim');
                }

                $pdo->commit();
                
                // Send Top-up SMS
                require_once __DIR__ . '/classes/SmsHelper.php';
                try {
                    if (class_exists('SmsHelper')) {
                        SmsHelper::sendTopupApprovedSMS($pdo, $userId, 'Wallet Topup', $foundTx['amount'], $foundTx['amount']);
                    }
                } catch (Exception $e) {}
                $message = 'success|GHS ' . number_format($foundTx['amount'], 2) . ' successfully claimed and credited to your wallet!';
                
                // Redirect to prevent duplicate submission on refresh
                $_SESSION['topup_message'] = $message;
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Claim failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        } else {
            $errors[] = 'Transaction is no longer available for claiming.';
        }
    }
}

$stmt = $pdo->prepare('SELECT payment_ref FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$paymentRef = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM topups WHERE user_id = :user_id AND status = 'pending' ORDER BY created_at DESC");
$stmt->execute(['user_id' => $userId]);
$pendingOrders = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM topups WHERE user_id = :user_id AND status = 'approved' ORDER BY created_at DESC LIMIT 20");
$stmt->execute(['user_id' => $userId]);
$approvedOrders = $stmt->fetchAll();

$activeTab = $_GET['tab'] ?? 'pending';
$ordersToShow = ($activeTab === 'approved') ? $approvedOrders : $pendingOrders;

// Auto Paystack Reference calculation for pre-filling verification modal
$autoPaystackRef = trim($_GET['reference'] ?? $_GET['trxref'] ?? $_GET['paystack_ref'] ?? $_SESSION['last_paystack_ref'] ?? '');

// Auto Manual MoMo Transaction ID calculation for pre-filling MoMo claim modal
$autoManualTxId = '';
if (isset($pdo) && !empty($userId)) {
    try {
        // A. Check if user has a pending topup order with a transaction_id
        $stmtPendingTx = $pdo->prepare("SELECT transaction_id FROM topups WHERE user_id = :uid AND status = 'pending' AND transaction_id IS NOT NULL AND transaction_id != '' AND network NOT LIKE '%Paystack%' AND transaction_id NOT LIKE 'PAYSTACK-%' ORDER BY id DESC LIMIT 1");
        $stmtPendingTx->execute(['uid' => $userId]);
        $autoManualTxId = trim($stmtPendingTx->fetchColumn() ?: '');

        // B. If empty, check if any unclaimed webhook_payment matches any of user's pending order IDs
        if (empty($autoManualTxId) && !empty($pendingOrders)) {
            foreach ($pendingOrders as $pOrder) {
                $orderCode = str_pad(($pOrder['id'] * 397 + 137) % 900 + 100, 3, '0', STR_PAD_LEFT);
                $stmtM = $pdo->prepare("SELECT transaction_id FROM webhook_payments WHERE is_claimed = 0 AND (reference = ? OR reference = ?) ORDER BY id DESC LIMIT 1");
                $stmtM->execute([$orderCode, (string)$pOrder['id']]);
                $foundTx = trim($stmtM->fetchColumn() ?: '');
                if ($foundTx) {
                    $autoManualTxId = $foundTx;
                    break;
                }
            }
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<script src="https://js.paystack.co/v1/inline.js"></script>
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no, viewport-fit=cover">
<title>Account Topup Portal Â· Apex Prime</title>
<meta name="theme-color" content="#4f46e5">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Apex Prime">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
<link rel="stylesheet" href="/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/image/icon-192.png">
<script src="/js/script.js"></script>
<style>
  /* Custom Notice Styling (Like Data Delivered notice) */
  .rounded-notice-card {
      border-radius: 28px !important;
      box-shadow: 0 20px 40px rgba(0,0,0,0.25) !important;
      padding: 2.25rem 1.75rem !important;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
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
      font-size: 1.02rem !important;
      font-weight: 400 !important;
      line-height: 1.6 !important;
      color: #ffffff !important;
      margin: 0.5rem 0 1.25rem 0 !important;
      padding: 0 !important;
  }
  .rounded-notice-card .swal2-actions {
      margin: 0.5rem 0 0 0 !important;
  }
  .rounded-notice-btn {
      border-radius: 99px !important;
      padding: 0.65rem 2.2rem !important;
      background-color: #ffffff !important;
      color: #3b82f6 !important;
      font-weight: 800 !important;
      border: none !important;
      box-shadow: 0 4px 14px rgba(0,0,0,0.15) !important;
      cursor: pointer !important;
      font-size: 0.95rem !important;
      letter-spacing: -0.01em !important;
      outline: none !important;
  }
  .copy-btn-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      background: rgba(255,255,255,0.22);
      border: 1px solid rgba(255,255,255,0.35);
      color: #ffffff;
      padding: 0.15rem 0.45rem;
      border-radius: 6px;
      font-size: 0.72rem;
      cursor: pointer;
      transition: all 0.2s;
      user-select: none;
  }
  .copy-btn-badge:hover {
      background: rgba(255,255,255,0.38);
      color: #ffffff;
  }
  .copy-btn-badge:active {
      transform: scale(0.95);
  }
  @keyframes noticeScaleUp {
      from { transform: scale(0.92); opacity: 0; }
      to { transform: scale(1); opacity: 1; }
  }
</style>
<script>
window.copyToClipboard = function(text, btnElement) {
    if (!text) return;
    
    function onSuccess() {
        if (!btnElement) return;
        var origHtml = btnElement.innerHTML;
        btnElement.innerHTML = '<i class="fas fa-check" style="color:#22c55e;"></i>';
        setTimeout(function() {
            btnElement.innerHTML = origHtml;
        }, 1800);
    }

    // 1. Try standard navigator.clipboard if supported and secure
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(onSuccess).catch(function() {
            fallbackCopy(text, onSuccess);
        });
        return;
    }

    // 2. Cross-browser fallback (supports iOS Safari, non-HTTPS, inside modal overlays)
    fallbackCopy(text, onSuccess);

    function fallbackCopy(str, cb) {
        var el = document.createElement('textarea');
        el.value = str;
        el.setAttribute('readonly', '');
        el.style.contain = 'strict';
        el.style.position = 'absolute';
        el.style.left = '-9999px';
        el.style.fontSize = '12pt'; // Prevent auto-zoom on iOS
        document.body.appendChild(el);
        
        var selected = document.getSelection().rangeCount > 0 ? document.getSelection().getRangeAt(0) : false;
        el.select();
        el.setSelectionRange(0, 999999); // For iOS mobile
        
        var copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (e) {}
        
        document.body.removeChild(el);
        if (selected) {
            document.getSelection().removeAllRanges();
            document.getSelection().addRange(selected);
        }
        
        if (copied && typeof cb === 'function') {
            cb();
        }
    }
};

// Global click delegation for all copy buttons across native and SweetAlert modals
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.js-copy-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    var val = btn.getAttribute('data-copy');
    if (val) {
        window.copyToClipboard(val, btn);
    }
});
</script>
<style>

  /* â”€â”€ Topup page compact overrides â”€â”€ */
  .topup-page { font-size: 13px; }
  .topup-page .card { padding: 0.875rem; }
  .topup-page .form-label { font-size: 0.7rem; margin-bottom: 0.35rem; }
  .topup-page .form-control {
    padding: 0.5rem 0.75rem;
    font-size: 13px;
    border-radius: 0.4rem;
  }
  .topup-page select.form-control { padding: 0.5rem 0.75rem; }
  .topup-page .form-group { margin-bottom: 0.875rem; }
  .topup-page .btn { padding: 0.5rem 0.85rem; font-size: 0.75rem; }
  .topup-page .page-title { font-size: 1rem; }
  .topup-page .page-subtitle { font-size: 0.7rem; margin-bottom: 1rem; }
  .topup-page .text-xl { font-size: 0.95rem; }
  .topup-page .text-sm { font-size: 0.75rem; }
  .topup-page .text-xs { font-size: 0.65rem; }
  .topup-page .stat-icon { width: 26px; height: 26px; font-size: 0.7rem; }
  .topup-page input[style*="padding-left: 2.5rem"] { padding-left: 2.25rem !important; }
  .topup-page input[style*="padding-left: 2.25rem"] { padding-left: 2rem !important; }

  /* â”€â”€ Toast Alerts â”€â”€ */
  .toast-alert {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.75rem 0.875rem;
    border-radius: 0.4rem;
    margin-bottom: 1rem;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1.4;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    color: white !important;
    border: none;
  }
  .toast-alert.success { background: var(--success); }
  .toast-alert.success i { color: white; font-size: 0.9rem; }
  .toast-alert.danger { background: var(--danger); }
  .toast-alert.danger i { color: white; font-size: 0.9rem; }

  /* Loading spinner keyframe */
  @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

  /* Full screen loader overlay */
  #pageLoader {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,0.35);
    backdrop-filter: blur(3px);
    display: none;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 0.75rem;
  }
  #pageLoader.active { display: flex; }
  .loader-box {
    background: var(--surface-color);
    border-radius: var(--radius-lg);
    padding: 1.5rem 2rem;
    text-align: center;
    box-shadow: var(--shadow-lg);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    min-width: 180px;
  }
  .loader-ring {
    width: 44px; height: 44px;
    border: 4px solid var(--primary-light);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.75s linear infinite;
  }
  .loader-text {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
  }

  /* â”€â”€ Small ID Button â”€â”€ */
  .btn-id {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--primary);
    color: white !important;
    font-weight: 600;
    padding: 0.25rem 0.6rem;
    border-radius: 0.4rem;
    font-size: 0.65rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    letter-spacing: 0.05em;
    border: none;
  }
  .btn-id.pending { background: var(--warning); }
  .btn-id.approved { background: var(--success); }
</style>
</head>
<body class="topup-page">

<?php if (!empty($message)): 
    $msgText = strpos($message,'success|')===0 ? substr($message,8) : htmlspecialchars($message,ENT_QUOTES,'UTF-8');
    $badgeText = 'STATUS: SUBMITTED';
    $modalTitle = 'Top-up Notice';
    $badgeHtml = '<div style="margin-top:0.85rem;"><span style="display:inline-block; background:rgba(255,255,255,0.25); color:#ffffff; padding:0.35rem 0.95rem; border-radius:99px; font-weight:800; font-size:0.78rem; letter-spacing:0.05em; text-transform:uppercase;">' . $badgeText . '</span></div>';
?>
<!-- Data Delivered notice style Success Modal -->
<div id="successModal" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 999999; padding: 1.25rem;">
    <div style="background:#3b82f6; border-radius:28px; padding:2.25rem 1.75rem; width:100%; max-width:420px; text-align:center; box-shadow:0 20px 40px rgba(0,0,0,0.25); color:#ffffff; font-family:'Inter',sans-serif; animation:noticeScaleUp 0.3s ease-out; box-sizing:border-box;">
        <h2 style="font-size:1.35rem; font-weight:700; color:#ffffff; margin:0 0 0.65rem 0; letter-spacing:-0.01em;"><?= htmlspecialchars($modalTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <div style="font-size:1.02rem; font-weight:400; line-height:1.6; color:#ffffff; margin-bottom:1.15rem; text-align:center;">
            <?= $msgText ?>
        </div>
        <div style="margin-bottom:1.5rem;">
            <?= $badgeHtml ?>
        </div>
        <div>
            <button type="button" onclick="document.getElementById('successModal').style.display='none'" class="rounded-notice-btn" style="display:inline-block; border-radius:99px; padding:0.65rem 2.2rem; background-color:#ffffff; color:#3b82f6; font-weight:800; border:none; box-shadow:0 4px 14px rgba(0,0,0,0.15); cursor:pointer; font-size:0.95rem;">
                Understood
            </button>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof playOrderSuccessSound === 'function') {
        playOrderSuccessSound();
    }
    if (typeof Swal !== 'undefined') {
        var succEl = document.getElementById('successModal');
        if (succEl) succEl.style.display = 'none';
        Swal.fire({
            title: <?= json_encode($modalTitle) ?>,
            html: <?= json_encode('<div style="font-size:1.02rem; line-height:1.6; color:#ffffff;">' . $msgText . '</div>' . $badgeHtml) ?>,
            background: '#3b82f6',
            color: '#ffffff',
            confirmButtonColor: '#ffffff',
            confirmButtonText: '<span style="color:#3b82f6; font-weight:800;">Understood</span>',
            padding: '2.25rem 1.75rem',
            customClass: {
                popup: 'rounded-notice-card',
                confirmButton: 'rounded-notice-btn'
            }
        });
    }
});
</script>
<?php endif; ?>

<?php if (!empty($errors)): 
    $isCoolingPeriod = false;
    foreach ($errors as $e) {
        if (stripos($e, 'refund') !== false || stripos($e, 'approval') !== false || stripos($e, '1 week') !== false) {
            $isCoolingPeriod = true;
            break;
        }
    }
    $badgeText = $isCoolingPeriod ? 'PROVIDER STATUS: COOLING PERIOD' : 'PROVIDER STATUS: NOTICE';
    $modalTitle = $isCoolingPeriod ? 'Order Notice' : 'Top-up Notice';
    $escapedErrors = array_map(function($e) {
        return htmlspecialchars($e, ENT_QUOTES, 'UTF-8');
    }, $errors);
    $errorHtml = count($escapedErrors) === 1 
        ? $escapedErrors[0] 
        : '<ul style="text-align:left; margin:0; padding-left:1.2rem;"><li>' . implode('</li><li style="margin-top:0.35rem;">', $escapedErrors) . '</li></ul>';
    $badgeHtml = '<div style="margin-top:0.85rem;"><span style="display:inline-block; background:rgba(255,255,255,0.25); color:#ffffff; padding:0.35rem 0.95rem; border-radius:99px; font-weight:800; font-size:0.78rem; letter-spacing:0.05em; text-transform:uppercase;">' . $badgeText . '</span></div>';
?>
<!-- Data Delivered notice style Error Modal -->
<div id="errorModal" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 999999; padding: 1.25rem;">
    <div style="background:#3b82f6; border-radius:28px; padding:2.25rem 1.75rem; width:100%; max-width:420px; text-align:center; box-shadow:0 20px 40px rgba(0,0,0,0.25); color:#ffffff; font-family:'Inter',sans-serif; animation:noticeScaleUp 0.3s ease-out; box-sizing:border-box;">
        <h2 style="font-size:1.35rem; font-weight:700; color:#ffffff; margin:0 0 0.65rem 0; letter-spacing:-0.01em;"><?= htmlspecialchars($modalTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <div style="font-size:1.02rem; font-weight:400; line-height:1.6; color:#ffffff; margin-bottom:1.15rem; text-align:center;">
            <?= $errorHtml ?>
        </div>
        <div style="margin-bottom:1.5rem;">
            <?= $badgeHtml ?>
        </div>
        <div>
            <button type="button" onclick="document.getElementById('errorModal').style.display='none'" class="rounded-notice-btn" style="display:inline-block; border-radius:99px; padding:0.65rem 2.2rem; background-color:#ffffff; color:#3b82f6; font-weight:800; border:none; box-shadow:0 4px 14px rgba(0,0,0,0.15); cursor:pointer; font-size:0.95rem;">
                Understood
            </button>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Swal !== 'undefined') {
        var errorEl = document.getElementById('errorModal');
        if (errorEl) errorEl.style.display = 'none';
        Swal.fire({
            title: <?= json_encode($modalTitle) ?>,
            html: <?= json_encode('<div style="font-size:1.02rem; line-height:1.6; color:#ffffff;">' . $errorHtml . '</div>' . $badgeHtml) ?>,
            background: '#3b82f6',
            color: '#ffffff',
            confirmButtonColor: '#ffffff',
            confirmButtonText: '<span style="color:#3b82f6; font-weight:800;">Understood</span>',
            padding: '2.25rem 1.75rem',
            customClass: {
                popup: 'rounded-notice-card',
                confirmButton: 'rounded-notice-btn'
            }
        });
    }
});
</script>
<?php endif; ?>

<div class="app-wrapper">
    <!-- Main Content -->
    <main class="main-content" style="padding: 0; background-color: #f8fafc;">
        <?php 
        $pageTitle = 'Topup Portal';
        include __DIR__ . '/header.php'; 
        ?>
        <div class="container fade-up" style="padding-top: 1.5rem;">
            <!-- Topup Banner -->
            <div style="background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%); color: white; padding: 1.25rem 1.5rem; text-align: center; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(37, 99, 235, 0.25); border-radius: 0 0 24px 24px;">
                <h1 style="font-size: 1.35rem; font-weight: 800; color: #ffffff; margin-bottom: 0.4rem; letter-spacing: 0.02em; text-transform: uppercase;">ONLINE TOPUP</h1>
                <p style="font-size: 0.9rem; font-weight: 500; color: #ffffff; opacity: 0.9; margin: 0 auto; line-height: 1.4; max-width: 600px;">Topup your wallet with ease</p>
            </div>
            
            <div style="max-width: 520px; margin: 0 auto; display: flex; flex-direction: column; gap: 1rem;">

                <!-- Top Up Form Card -->
                <div class="card">
                    <div style="margin-bottom: 1.25rem;">
                        <!-- Dynamic Pricing Alerts -->
                        <div id="pricing-mtn" style="display: none; margin-top: 1rem; background: rgba(250, 204, 21, 0.1); border: 1px solid rgba(250, 204, 21, 0.3); color: #854d0e; padding: 0.5rem; border-radius: 0.4rem; font-size: 0.75rem; text-align: center; font-weight: 500;">
                            <strong style="color: #713f12;"><i class="fas fa-tags"></i> MTN Rates:</strong> GHS 3.90 / GB (1-10 GB) &nbsp;&bull;&nbsp; GHS 3.80 / GB (11-500 GB)
                        </div>
                        <div id="pricing-telecel" style="display: none; margin-top: 1rem; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #991b1b; padding: 0.5rem; border-radius: 0.4rem; font-size: 0.75rem; text-align: center; font-weight: 500;">
                            <strong style="color: #7f1d1d;"><i class="fas fa-tags"></i> Telecel Rates:</strong> GHS 3.50 / GB (1-10 GB) &nbsp;&bull;&nbsp; GHS 3.40 / GB (11-100 GB)
                        </div>
                        <div id="pricing-ishare" style="display: none; margin-top: 1rem; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: #1e40af; padding: 0.5rem; border-radius: 0.4rem; font-size: 0.75rem; text-align: center; font-weight: 500;">
                            <strong style="color: #1e3a8a;"><i class="fas fa-tags"></i> Ishare Rates:</strong> GHS 3.70 / GB (Flat Rate)
                        </div>
                        <div id="pricing-afa" style="display: none; margin-top: 1rem; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #047857; padding: 0.5rem; border-radius: 0.4rem; font-size: 0.75rem; text-align: center; font-weight: 500;">
                            <strong style="color: #064e3b;"><i class="fas fa-tags"></i> AFA Registration Rates:</strong> GHS <?= $afaPrice ?>.00 / Registration
                        </div>
                    </div>

                    <form method="post" action="">
                        <input type="hidden" name="mode" value="manual">
                        
                        <!-- Swap Payment Method Button -->
                        <?php if ($paystackEnabled): ?>
                        <div class="form-group" style="margin-bottom: 1.25rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: var(--shadow-sm);">
                                <div>
                                    <span style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em; display: block; margin-bottom: 0.15rem;">Payment Method</span>
                                    <span id="activePayMethodText" style="font-size: 0.85rem; font-weight: 800; color: var(--primary);">
                                        <i class="fas fa-bolt"></i> Pay Online (Instant)
                                    </span>
                                </div>
                                <button type="button" id="btnSwapMethod" style="padding: 0.45rem 0.85rem; font-size: 0.72rem; font-weight: 700; border-radius: 8px; border: none; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 10px rgba(217, 119, 6, 0.2);">
                                    <i class="fas fa-exchange-alt"></i> Swap to Momo Pay
                                </button>
                            </div>
                            <input type="hidden" id="payment_method_input" name="payment_method" value="online">
                        </div>
                        <?php else: ?>
                        <div class="form-group" style="margin-bottom: 1.25rem;">
                            <div style="padding: 0.65rem 0.85rem; background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; align-items: flex-start;">
                                <span style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em; display: block; margin-bottom: 0.15rem;">Payment Method</span>
                                <span style="font-size: 0.85rem; font-weight: 800; color: #f59e0b;">
                                    <i class="fas fa-university"></i> Momo Pay (Manual)
                                </span>
                            </div>
                            <input type="hidden" id="payment_method_input" name="payment_method" value="manual">
                        </div>
                        <?php endif; ?>
                            <input type="hidden" name="network" value="Wallet Topup">
                            <div class="form-group">
                                <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>Wallet Top-up Amount (GHS)</span>
                                    <span style="font-weight: 700; color: var(--primary); background: #e0e7ff; padding: 0.2rem 0.5rem; border-radius: 0.25rem; font-size: 0.65rem;">
                                        Bal: GHS <?= number_format(function_exists('getUserWalletBalance') ? getUserWalletBalance($pdo, $userId) : 0, 2) ?>
                                    </span>
                                </label>
                                <div style="position: relative;">
                                    <i class="fas fa-wallet text-secondary" style="position: absolute; left: 0.875rem; top: 50%; transform: translateY(-50%); font-size: 0.75rem;"></i>
                                    <input type="number" step="0.01" name="bundle_size" required class="form-control" style="padding-left: 2.25rem;" placeholder="e.g. 50.00" min="1">
                                </div>
                            </div>

                        <!-- Paystack Processing Fee Display -->
                        <div id="paystackFeeDetails" style="margin-top: 0.75rem; background: rgba(59, 130, 246, 0.06); border: 1px dashed rgba(59, 130, 246, 0.3); padding: 0.75rem; border-radius: 8px; display: <?= $paystackEnabled ? 'block' : 'none' ?>;">
                            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.25rem;">
                                <span>Top-up Amount:</span>
                                <span style="font-weight: 600; color: var(--text-primary);" id="feeBaseAmount">GHS 0.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.25rem;">
                                <span>Paystack Fee (2%):</span>
                                <span style="font-weight: 600; color: #ef4444;" id="feeChargeAmount">GHS 0.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; font-weight: 700; color: var(--text-primary); border-top: 1px solid var(--border-color); padding-top: 0.25rem; margin-top: 0.25rem;">
                                <span>Total Payable:</span>
                                <span style="color: var(--primary);" id="feeTotalAmount">GHS 0.00</span>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: center; margin-top: 1.25rem;">
                            <button type="submit" id="submitBtn" class="btn btn-primary" style="display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; padding: 0.45rem 0.9rem; font-size: 0.75rem; border-radius: 0.4rem; font-weight: 700; border: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1); cursor: pointer; width: auto;">
                                <span id="submitBtnContent">
                                    <?php if ($paystackEnabled): ?>
                                    <i class="fas fa-credit-card" style="margin-right: 0.4rem;"></i> Pay via Paystack
                                    <?php else: ?>
                                    <i class="fas fa-paper-plane" style="margin-right: 0.4rem;"></i> Submit Request (Manual)
                                    <?php endif; ?>
                                </span>
                                <span id="submitBtnLoading" style="display:none; align-items: center; justify-content: center; gap: 0.5rem;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="animation: spin 0.8s linear infinite; flex-shrink:0;">
                                        <circle cx="12" cy="12" r="10" stroke="rgba(255,255,255,0.3)" stroke-width="3"/>
                                        <path d="M12 2a10 10 0 0 1 10 10" stroke="white" stroke-width="3" stroke-linecap="round"/>
                                    </svg>
                                    Processing...
                                </span>
                            </button>
                        </div>
                    </form>

                    <!-- Payment Info -->
                    <div id="momoDetailsCard" style="display: <?= $paystackEnabled ? 'none' : 'block' ?>; margin-top: 1rem; padding: 1rem; border-radius: 0.4rem; background: #f1f5f9; color: var(--text-primary); border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: left;">
                        <p style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.6rem; display: flex; align-items: center; gap: 0.4rem; color: var(--text-secondary);">
                            <i class="fas fa-wallet"></i> Payment Details
                        </p>
                        <div style="display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.8rem; font-weight: 600;">
                            <div><span style="color: var(--text-secondary); font-weight: 500; margin-right: 0.3rem;">Number:</span> <?= htmlspecialchars($settings['momo_number'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div><span style="color: var(--text-secondary); font-weight: 500; margin-right: 0.3rem;">Name:</span> <?= htmlspecialchars($settings['momo_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div style="margin-top: 0.4rem; padding-top: 0.4rem; border-top: 1px solid #cbd5e1; font-size: 0.75rem;">
                                <span style="color: var(--text-secondary); font-weight: 500; margin-right: 0.3rem;">Reference (Order ID):</span> 
                                <span style="color: #16a34a; font-weight: 500; font-size: 0.75rem; letter-spacing: 0.5px;"><?= htmlspecialchars($settings['momo_reference'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if ($paystackEnabled): ?>
                    <!-- Paystack Automatic Verification Card -->
                    <div class="card" style="margin-top: 1rem; text-align: center; padding: 1rem; border: 1px solid rgba(59, 130, 246, 0.3); background: rgba(59, 130, 246, 0.03);">
                        <h3 style="font-size: 0.9rem; font-weight: 800; margin-bottom: 0.4rem; color: var(--primary);">
                            <i class="fas fa-shield-alt" style="color: var(--primary);"></i> Paystack Automatic Verification
                        </h3>
                        <p style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.85rem; line-height: 1.4;">
                            Verify your Paystack payment reference automatically to confirm your transaction and credit your wallet immediately.
                        </p>
                        <button type="button" onclick="openVerifyModal('', false);" class="btn" style="width: 100%; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; font-weight: 700; border: none; box-shadow: 0 4px 10px rgba(59,130,246,0.25);">
                            <i class="fas fa-check-circle" style="margin-right: 0.4rem;"></i> Verify Paystack Payment
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- Claim Missing Payment -->
                    <div class="card" style="margin-top: 1rem; text-align: center; padding: 1rem;">
                        <h3 style="font-size: 0.9rem; font-weight: 800; margin-bottom: 0.5rem; color: #f59e0b;"><i class="fas fa-shield-alt" style="color: #f59e0b;"></i> MoMo Automatic Verification</h3>
                        <p style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 1rem;">Verify and claim your manual MoMo payment transaction automatically to credit your wallet.</p>
                        <button type="button" onclick="openVerifyModal('', true);" class="btn" style="width: 100%; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; font-weight: 700; border: none; box-shadow: 0 4px 10px rgba(217,119,6,0.25);">
                            <i class="fas fa-check-circle" style="margin-right: 0.4rem;"></i> Verify MoMo Payment
                        </button>
                    </div>
                </div>

                <!-- Orders Table Card -->
                <div class="card" id="ordersSection">
                    <div style="margin-bottom: 0.75rem;">
                        <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.6rem; color: #1e293b;">My Orders</div>

                        <!-- Tabs -->
                        <div style="display: flex; gap: 0.4rem; background-color: var(--bg-color); padding: 0.2rem; border-radius: var(--radius-md);">
                            <button onclick="switchTab('pending')" id="tab-pending"
                                style="flex: 1; padding: 0.45rem; font-size: 0.78rem; font-weight: 600; border-radius: 0.35rem; cursor: pointer; transition: all 0.2s; border: none; background-color: var(--primary); color: white;">
                                <i class="fas fa-clock" style="margin-right: 0.3rem;"></i>Pending
                                <span style="background: rgba(255,255,255,0.3); border-radius: 9999px; padding: 0 0.35rem; font-size: 0.7rem; margin-left: 0.2rem;"><?= count($pendingOrders) ?></span>
                            </button>
                            <button onclick="switchTab('approved')" id="tab-approved"
                                style="flex: 1; padding: 0.45rem; font-size: 0.78rem; font-weight: 600; border-radius: 0.35rem; cursor: pointer; transition: all 0.2s; border: none; background-color: transparent; color: var(--text-secondary);">
                                <i class="fas fa-check-circle" style="margin-right: 0.3rem;"></i>Approved
                                <span style="background: var(--success-light); color: var(--success); border-radius: 9999px; padding: 0 0.35rem; font-size: 0.7rem; margin-left: 0.2rem;"><?= count($approvedOrders) ?></span>
                            </button>
                        </div>
                    </div>

                    <!-- Pending Table -->
                    <div id="table-pending">
                        <?php if (empty($pendingOrders)): ?>
                            <div style="text-align: center; padding: 2rem 1rem; color: var(--text-secondary);">
                                <i class="fas fa-inbox" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem; opacity: 0.4;"></i>
                                <span style="font-size: 0.8rem;">No pending orders yet.</span>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem;">
                                    <thead>
                                        <tr style="background-color: var(--bg-color);">
                                            <th style="padding: 0.5rem 0.6rem; text-align: center; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">Action</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: left; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">ID</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: left; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">Bundle</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: right; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">Amount</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: left; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">Network</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: left; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">SMS Ref</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: right; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingOrders as $i => $order): 
                                            $isWallet = ($order['network'] === 'Wallet Topup' || stripos($order['network'], 'Wallet') !== false || stripos($order['network'], 'Paystack') !== false);
                                            $parts = explode(' - ', $order['phone']);
                                            $bundleOnly = $isWallet ? 'Wallet Funding' : (!empty($parts[0]) ? $parts[0] : $order['phone']);
                                            $amountOnly = ($order['amount'] > 0) ? 'GHS ' . number_format((float)$order['amount'], 2) : (!empty($parts[1]) ? $parts[1] : 'GHS ' . number_format((float)$order['amount'], 2));
                                            $isPaystackOrder = (
                                                (isset($order['network']) && stripos($order['network'], 'paystack') !== false) ||
                                                (isset($order['transaction_id']) && strpos($order['transaction_id'], 'PAYSTACK') === 0)
                                            );
                                        ?>
                                            <tr style="border-bottom: 1px solid var(--border-color); <?= $i % 2 === 0 ? '' : 'background-color: var(--bg-color);' ?>">
                                                <td style="padding: 0.4rem 0.5rem; text-align: center; white-space: nowrap;">
                                                    <button type="button"
                                                        onclick="openVerifyModal('<?= htmlspecialchars($order['transaction_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>', <?= $isPaystackOrder ? 'false' : 'true' ?>)"
                                                        style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.28rem 0.55rem; font-size: 0.65rem; font-weight: 700; border-radius: 0.35rem; border: none; cursor: pointer; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; box-shadow: 0 2px 6px rgba(16,185,129,0.3); transition: opacity 0.15s, transform 0.15s; letter-spacing: 0.03em;"
                                                        onmouseover="this.style.opacity='0.85'; this.style.transform='scale(1.04)';"
                                                        onmouseout="this.style.opacity='1'; this.style.transform='scale(1)';"
                                                        title="<?= $isPaystackOrder ? 'Verify your Paystack payment for this order' : 'Verify / Claim your Momo payment for this order' ?>">
                                                        <i class="fas fa-shield-check" style="font-size: 0.6rem;"></i> Verify
                                                    </button>
                                                </td>
                                                <td style="padding: 0.5rem 0.6rem; white-space: nowrap;">
                                                    <span class="btn-id pending"><?= str_pad(($order['id'] * 397 + 137) % 900 + 100, 3, '0', STR_PAD_LEFT) ?></span>
                                                </td>
                                                <td style="padding: 0.5rem 0.6rem; white-space: nowrap; font-weight: 700; color: var(--primary);"><?= htmlspecialchars($bundleOnly, ENT_QUOTES, 'UTF-8') ?></td>
                                                <td style="padding: 0.5rem 0.6rem; text-align: right; white-space: nowrap; font-weight: 700; color: var(--text-secondary);"><?= htmlspecialchars($amountOnly, ENT_QUOTES, 'UTF-8') ?></td>
                                                <td style="padding: 0.5rem 0.6rem; white-space: nowrap; font-weight: 600; color: var(--text-primary); font-size: 0.72rem;"><?= htmlspecialchars($order['network'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td style="padding: 0.5rem 0.6rem; white-space: nowrap; font-weight: 600; color: var(--text-secondary); font-size: 0.72rem;"><?= htmlspecialchars($order['transaction_id'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td style="padding: 0.5rem 0.6rem; text-align: right; white-space: nowrap; color: var(--text-secondary); font-size: 0.7rem;"><?= date('M j, g:i A', strtotime($order['created_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Approved Table -->
                    <div id="table-approved" style="display: none;">
                        <?php if (empty($approvedOrders)): ?>
                            <div style="text-align: center; padding: 2rem 1rem; color: var(--text-secondary);">
                                <i class="fas fa-inbox" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem; opacity: 0.4;"></i>
                                <span style="font-size: 0.8rem;">No approved orders yet.</span>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem;">
                                    <thead>
                                        <tr style="background-color: var(--bg-color);">
                                            <th style="padding: 0.5rem 0.6rem; text-align: left; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">ID</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: left; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">Bundle</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: right; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">Amount</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: left; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">Network</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: left; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">SMS Ref</th>
                                            <th style="padding: 0.5rem 0.6rem; text-align: right; font-weight: 700; color: var(--text-secondary); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); white-space: nowrap;">Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($approvedOrders as $i => $order): 
                                            $isWallet = ($order['network'] === 'Wallet Topup' || stripos($order['network'], 'Wallet') !== false || stripos($order['network'], 'Paystack') !== false);
                                            $parts = explode(' - ', $order['phone']);
                                            $bundleOnly = $isWallet ? 'Wallet Funding' : (!empty($parts[0]) ? $parts[0] : $order['phone']);
                                            $amountOnly = ($order['amount'] > 0) ? 'GHS ' . number_format((float)$order['amount'], 2) : (!empty($parts[1]) ? $parts[1] : 'GHS ' . number_format((float)$order['amount'], 2));
                                        ?>
                                            <tr style="border-bottom: 1px solid var(--border-color); <?= $i % 2 === 0 ? '' : 'background-color: var(--bg-color);' ?>">
                                                <td style="padding: 0.5rem 0.6rem; white-space: nowrap;">
                                                    <span class="btn-id approved"><?= str_pad(($order['id'] * 397 + 137) % 900 + 100, 3, '0', STR_PAD_LEFT) ?></span>
                                                </td>
                                                <td style="padding: 0.5rem 0.6rem; white-space: nowrap; font-weight: 700; color: var(--success);"><?= htmlspecialchars($bundleOnly, ENT_QUOTES, 'UTF-8') ?></td>
                                                <td style="padding: 0.5rem 0.6rem; text-align: right; white-space: nowrap; font-weight: 700; color: var(--text-secondary);"><?= htmlspecialchars($amountOnly, ENT_QUOTES, 'UTF-8') ?></td>
                                                <td style="padding: 0.5rem 0.6rem; white-space: nowrap; font-weight: 600; color: var(--text-primary); font-size: 0.72rem;"><?= htmlspecialchars($order['network'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td style="padding: 0.5rem 0.6rem; white-space: nowrap; font-weight: 600; color: var(--text-secondary); font-size: 0.72rem;"><?= htmlspecialchars($order['transaction_id'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td style="padding: 0.5rem 0.6rem; text-align: right; white-space: nowrap; color: var(--text-secondary); font-size: 0.7rem;"><?= date('M j, g:i A', strtotime($order['created_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- Footer -->
            <footer style="margin-top: 3rem; padding: 1.5rem 0; text-align: center; font-size: 0.72rem; font-weight: 500; color: var(--text-secondary); border-top: 1px solid var(--border-color); font-family: 'Inter', sans-serif; opacity: 0.8;">
                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                    <span style="display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.95rem;">Developed by <span style="font-weight: 800; background: linear-gradient(135deg, #3b82f6, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Apex Prime Technology</span></span>
                    <span style="font-size: 0.85rem;">@ 2026</span>
                </div>
            </footer>
            </div>
        </div>
    </main>
</div>

<script>
function switchTab(tab) {
    document.getElementById('table-pending').style.display  = tab === 'pending'  ? 'block' : 'none';
    document.getElementById('table-approved').style.display = tab === 'approved' ? 'block' : 'none';

    const activeStyle   = 'flex:1;padding:0.45rem;font-size:0.78rem;font-weight:700;border-radius:0.35rem;cursor:pointer;transition:all 0.2s;border:none;background-color:var(--primary);color:white;';
    const inactiveStyle = 'flex:1;padding:0.45rem;font-size:0.78rem;font-weight:700;border-radius:0.35rem;cursor:pointer;transition:all 0.2s;border:none;background-color:transparent;color:var(--text-secondary);';
    document.getElementById('tab-pending').style.cssText  = tab === 'pending'  ? activeStyle : inactiveStyle;
    document.getElementById('tab-approved').style.cssText = tab === 'approved' ? activeStyle : inactiveStyle;
}

let allBundleOptions = [];

// Filter bundle size options based on selected network
function filterBundleOptions(networkVal) {
    const bundleSel = document.getElementById('bundleSizeSelect');
    if (!bundleSel) return;
    
    // Clear and rebuild options dynamically
    bundleSel.innerHTML = '';
    
    // Create placeholder option
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = networkVal ? 'Select bundle size...' : 'Select network first...';
    bundleSel.appendChild(placeholder);
    
    let first = true;
    allBundleOptions.forEach(function(opt) {
        if (networkVal && opt.network === networkVal) {
            const newOpt = document.createElement('option');
            newOpt.value = opt.value;
            newOpt.textContent = opt.text;
            newOpt.setAttribute('data-network', opt.network);
            bundleSel.appendChild(newOpt);
            
            if (first) {
                bundleSel.value = opt.value;
                first = false;
            }
        }
    });
    
    // Toggle dynamic pricing
    document.getElementById('pricing-mtn').style.display = (networkVal === 'MTN Group Share') ? 'block' : 'none';
    document.getElementById('pricing-telecel').style.display = (networkVal === 'Telecel Group Share') ? 'block' : 'none';
    document.getElementById('pricing-ishare').style.display = (networkVal === 'Ishare') ? 'block' : 'none';
    document.getElementById('pricing-afa').style.display = (networkVal === 'AFA Registration') ? 'block' : 'none';
}

// Submit loading animation
document.addEventListener('DOMContentLoaded', function () {
    const form        = document.querySelector('form[method="post"]');
    const btn         = document.getElementById('submitBtn');
    const btnContent  = document.getElementById('submitBtnContent');
    const btnLoading  = document.getElementById('submitBtnLoading');
    const loader      = document.getElementById('pageLoader');

    const bundleSel = document.getElementById('bundleSizeSelect');
    if (bundleSel) {
        const opts = bundleSel.querySelectorAll('option[data-network]');
        opts.forEach(function(opt) {
            allBundleOptions.push({
                value: opt.value,
                text: opt.textContent.trim(),
                network: opt.getAttribute('data-network')
            });
        });
    }

    const networkSel = form ? form.querySelector('select[name="network"]') : null;
    if (networkSel) {
        networkSel.addEventListener('change', function() {
            filterBundleOptions(this.value);
        });
        // Run on load in case a network is pre-selected
        filterBundleOptions(networkSel.value);
    }

    // Swap Payment Method
    const btnSwap = document.getElementById('btnSwapMethod');
    const payMethodInput = document.getElementById('payment_method_input');
    const activePayMethodText = document.getElementById('activePayMethodText');
    const momoDetailsCard = document.getElementById('momoDetailsCard');
    const feeDetailsCard = document.getElementById('paystackFeeDetails');

    function updatePayMethodUI() {
        const method = payMethodInput ? payMethodInput.value : 'online';
        if (method === 'online') {
            if (activePayMethodText) activePayMethodText.innerHTML = '<i class="fas fa-bolt" style="color: var(--primary);"></i> Pay Online (Instant)';
            if (btnSwap) {
                btnSwap.innerHTML = '<i class="fas fa-exchange-alt"></i> Swap to Momo Pay';
                btnSwap.style.cssText = 'padding: 0.45rem 0.85rem; font-size: 0.72rem; font-weight: 700; border-radius: 8px; border: none; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 10px rgba(217, 119, 6, 0.2);';
            }
            if (momoDetailsCard) momoDetailsCard.style.display = 'none';
            if (feeDetailsCard) feeDetailsCard.style.display = 'block';
            if (btnContent) btnContent.innerHTML = '<i class="fas fa-credit-card" style="margin-right: 0.4rem;"></i> Pay via Paystack';
        } else {
            if (activePayMethodText) activePayMethodText.innerHTML = '<i class="fas fa-university" style="color: #f59e0b;"></i> Momo Pay (Manual)';
            if (btnSwap) {
                btnSwap.innerHTML = '<i class="fas fa-exchange-alt"></i> Swap to Pay Online';
                btnSwap.style.cssText = 'padding: 0.45rem 0.85rem; font-size: 0.72rem; font-weight: 700; border-radius: 8px; border: none; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: white; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);';
            }
            if (momoDetailsCard) momoDetailsCard.style.display = 'block';
            if (feeDetailsCard) feeDetailsCard.style.display = 'none';
            if (btnContent) btnContent.innerHTML = '<i class="fas fa-paper-plane" style="margin-right: 0.4rem;"></i> Submit Request';
        }
        updateFeeCalculation();
    }

    if (btnSwap && payMethodInput) {
        btnSwap.addEventListener('click', function() {
            payMethodInput.value = payMethodInput.value === 'online' ? 'manual' : 'online';
            updatePayMethodUI();
        });
    }

    // Fee calculation logic
    const amountInput = form ? form.querySelector('input[name="bundle_size"]') : null;
    const bundleSelect = document.getElementById('bundleSizeSelect');
    
    function getSelectedAmount() {
        if (amountInput) {
            const val = parseFloat(amountInput.value);
            return isNaN(val) ? 0 : val;
        }
        return 0;
    }

    function updateFeeCalculation() {
        const amount = getSelectedAmount();
        const baseSpan = document.getElementById('feeBaseAmount');
        const chargeSpan = document.getElementById('feeChargeAmount');
        const totalSpan = document.getElementById('feeTotalAmount');

        if (baseSpan && chargeSpan && totalSpan) {
            const charge = amount * 0.02;
            const total = amount + charge;

            baseSpan.textContent = 'GHS ' + amount.toFixed(2);
            chargeSpan.textContent = 'GHS ' + charge.toFixed(2);
            totalSpan.textContent = 'GHS ' + total.toFixed(2);
        }
    }

    if (amountInput) {
        amountInput.addEventListener('input', updateFeeCalculation);
    }
    if (bundleSelect) {
        bundleSelect.addEventListener('change', updateFeeCalculation);
    }
    // Run once on load
    updatePayMethodUI();

    function payWithPaystack() {
        const amount = getSelectedAmount();
        if (amount <= 0) {
            alert('Please enter or select a valid amount.');
            return;
        }

        const loaderText = loader.querySelector('.loader-text');
        if (loaderText) loaderText.textContent = 'Initializing payment...';
        loader.classList.add('active');

        const formData = new FormData();
        formData.append('amount', amount);

        fetch('/ajax_init_topup.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.authorization_url) {
                window.location.href = data.authorization_url; // Secure server-side init redirect
            } else {
                loader.classList.remove('active');
                alert(data.message || 'Payment initialization failed.');
            }
        })
        .catch(err => {
            loader.classList.remove('active');
            alert('An error occurred. Please try again.');
            console.error(err);
        });
    }

    if (form && btn) {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) return; // let browser handle validation
            
            const method = payMethodInput ? payMethodInput.value : 'online';
            if (method === 'online') {
                e.preventDefault();
                payWithPaystack();
                return;
            }

            // Button state
            btn.disabled = true;
            btnContent.style.display = 'none';
            btnLoading.style.display = 'flex';

            // Full-screen overlay
            loader.classList.add('active');
        });
    }

    // Paystack Receipt Verification AJAX Handler
    function showSuccessCard(msg) {
        document.getElementById('dynamicSuccessMsg').innerHTML = msg;
        document.getElementById('dynamicSuccessModal').style.display = 'flex';
    }

    function showWarningCard(msg) {
        var pModal = document.getElementById('paystackReceiptModal');
        if (pModal) pModal.style.display = 'none';
        document.getElementById('dynamicWarningMsg').innerHTML = msg;
        document.getElementById('dynamicWarningModal').style.display = 'flex';
    }

    const receiptForm = document.getElementById('paystackReceiptForm');
    const btnVerifyPaystack = document.getElementById('btnVerifyPaystack');
    if (receiptForm && btnVerifyPaystack) {
        receiptForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const refVal = document.getElementById('paystack_ref_input').value.trim();
            if (!refVal) return;

            btnVerifyPaystack.disabled = true;
            btnVerifyPaystack.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying with Paystack...';

            const fd = new FormData();
            fd.append('reference', refVal);

            fetch('/ajax_verify_paystack_receipt.php', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                btnVerifyPaystack.disabled = false;
                btnVerifyPaystack.innerHTML = '<i class="fas fa-shield-alt" style="margin-right: 0.4rem;"></i> Verify & Credit Account';

                if (data.success) {
                    document.getElementById('paystackReceiptModal').style.display = 'none';
                    showSuccessCard(data.message);
                } else {
                    showWarningCard(data.message);
                }
            })
            .catch(err => {
                btnVerifyPaystack.disabled = false;
                btnVerifyPaystack.innerHTML = '<i class="fas fa-shield-alt" style="margin-right: 0.4rem;"></i> Verify & Credit Account';
                showWarningCard('An error occurred while verifying. Please check your network connection and try again.');
            });
        });
    }

    <?php if (!empty($message)): ?>
    // Auto-scroll to orders after successful submission
    document.getElementById('ordersSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
    <?php endif; ?>

    <?php if (!empty($_GET['auto_verify']) || !empty($_GET['reference']) || !empty($_GET['trxref'])): ?>
    setTimeout(function() {
        openVerifyModal('<?= htmlspecialchars(trim($_GET['reference'] ?? $_GET['trxref'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', false);
    }, 400);
    <?php endif; ?>
});

const AUTO_PAYSTACK_REF = <?= json_encode($autoPaystackRef) ?>;
const AUTO_MANUAL_TX_ID = <?= json_encode($autoManualTxId) ?>;

function handleClaimSubmit(form, loadingText) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right: 0.4rem;"></i> ' + (loadingText || 'Verifying...');
    }
    const loader = document.getElementById('pageLoader');
    if (loader) {
        const loaderText = loader.querySelector('.loader-text');
        if (loaderText) loaderText.textContent = loadingText || 'Verifying & Claiming...';
        loader.classList.add('active');
    }
    return true;
}

/**
 * Opens the verification modal for an order.
 * Automatically pre-fills the reference ID in the field if available from the database.
 */
function openVerifyModal(ref, isManual) {
    if (isManual) {
        var input = document.getElementById('claim_tx_id_input');
        var hint = document.getElementById('claim_tx_hint');
        if (input) {
            var targetTx = (ref && ref !== '-') ? ref : AUTO_MANUAL_TX_ID;
            if (targetTx) {
                input.value = targetTx;
            } else {
                if (!input.value) input.value = '';
            }
            input.readOnly = true;
            input.style.backgroundColor = '#f1f5f9';
            input.style.cursor = 'not-allowed';
            if (hint) hint.innerHTML = '<i class="fas fa-lock" style="font-size: 0.65rem; color: #64748b; margin-right: 0.2rem;"></i> Transaction ID locked for security.';
            input.focus();
        }
        var modal = document.getElementById('claimModal');
        if (modal) modal.style.display = 'flex';
    } else {
        var input = document.getElementById('paystack_ref_input');
        var hint = document.getElementById('paystack_ref_hint');
        if (input) {
            var targetRef = (ref && ref !== '-') ? ref : AUTO_PAYSTACK_REF;
            if (targetRef) {
                input.value = targetRef;
            } else {
                if (!input.value) input.value = '';
            }
            input.readOnly = true;
            input.style.backgroundColor = '#f1f5f9';
            input.style.cursor = 'not-allowed';
            if (hint) hint.innerHTML = '<i class="fas fa-lock" style="font-size: 0.65rem; color: #64748b; margin-right: 0.2rem;"></i> Reference locked for security.';
            input.focus();
        }
        var modal = document.getElementById('paystackReceiptModal');
        if (modal) modal.style.display = 'flex';
    }
}

function toggleEditPaystackRef() {
    var input = document.getElementById('paystack_ref_input');
    var hint = document.getElementById('paystack_ref_hint');
    if (input) {
        if (input.readOnly) {
            input.readOnly = false;
            input.style.backgroundColor = '#ffffff';
            input.style.cursor = 'text';
            input.placeholder = 'Enter your custom Tx ID / Paystack Ref';
            if (hint) hint.innerHTML = '<i class="fas fa-edit" style="color: #3b82f6;"></i> Unlocked! Type or paste your Transaction ID.';
            input.focus();
            input.select();
        } else {
            input.readOnly = true;
            input.style.backgroundColor = '#f1f5f9';
            input.style.cursor = 'not-allowed';
            if (hint) hint.innerHTML = '<i class="fas fa-lock" style="color: #64748b;"></i> Reference locked for security.';
        }
    }
}

function toggleEditClaimTx() {
    var input = document.getElementById('claim_tx_id_input');
    var hint = document.getElementById('claim_tx_hint');
    if (input) {
        if (input.readOnly) {
            input.readOnly = false;
            input.style.backgroundColor = '#ffffff';
            input.style.cursor = 'text';
            input.placeholder = 'Enter your MoMo Transaction ID';
            if (hint) hint.innerHTML = '<i class="fas fa-edit" style="color: #f59e0b;"></i> Unlocked! Type or paste your MoMo Tx ID.';
            input.focus();
            input.select();
        } else {
            input.readOnly = true;
            input.style.backgroundColor = '#f1f5f9';
            input.style.cursor = 'not-allowed';
            if (hint) hint.innerHTML = '<i class="fas fa-lock" style="color: #64748b;"></i> Transaction ID locked for security.';
        }
    }
}
</script>

<!-- Page Loader Overlay -->
<div id="pageLoader">
    <div class="loader-box">
        <div class="loader-ring"></div>
        <div class="loader-text">Submitting request…</div>
    </div>
</div>

<!-- Paystack Receipt Verification Modal -->
<div id="paystackReceiptModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); z-index: 1000; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px);">
    <div class="card" style="width: 100%; max-width: 420px; position: relative; animation: slideUp 0.3s ease;">
        <button type="button" onclick="document.getElementById('paystackReceiptModal').style.display='none';" style="position: absolute; right: 1rem; top: 1rem; background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-secondary);"><i class="fas fa-times"></i></button>
        <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 0.5rem; color: var(--text-primary);"><i class="fas fa-shield-alt" style="color: var(--primary);"></i> Paystack Automatic Verification</h3>
        <p style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 1.25rem; line-height: 1.4;">Your Paystack transaction reference is auto-filled below. Click "Verify & Credit Account" to complete your wallet top-up.</p>
        
        <form method="POST" id="paystackReceiptForm">
            <input type="hidden" name="mode" value="verify_paystack_receipt">
            <div style="margin-bottom: 1.25rem;">
                <label class="form-label" style="font-weight: 700; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">Payment Reference ID</label>
                <input type="text" name="paystack_ref" id="paystack_ref_input" class="form-control" placeholder="e.g. TOPUP-12345" readonly required style="font-size: 0.95rem; padding: 0.7rem; border-radius: 8px; font-weight: 700; background-color: #f1f5f9; cursor: not-allowed; font-family: monospace; letter-spacing: 0.05em; color: #1e293b; border: 1px solid #cbd5e1;">
                <p style="font-size: 0.7rem; color: var(--text-secondary); margin-top: 0.4rem;"><i class="fas fa-lock" style="font-size: 0.65rem; color: #64748b; margin-right: 0.2rem;"></i> Reference locked automatically for security verification.</p>
            </div>
            <button type="submit" id="btnVerifyPaystack" class="btn btn-primary" style="width: 100%; font-size: 0.9rem; padding: 0.75rem; border-radius: 8px; font-weight: 700; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); border: none; box-shadow: 0 4px 10px rgba(59,130,246,0.25);">
                <i class="fas fa-shield-alt" style="margin-right: 0.4rem;"></i> Verify & Credit Account
            </button>
        </form>
    </div>
</div>

<!-- Claim Modal -->
<div id="claimModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); z-index: 1000; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px);">
    <div class="card" style="width: 100%; max-width: 400px; position: relative; animation: slideUp 0.3s ease;">
        <button type="button" onclick="document.getElementById('claimModal').style.display='none';" style="position: absolute; right: 1rem; top: 1rem; background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-secondary);"><i class="fas fa-times"></i></button>
        <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 0.5rem; color: #f59e0b;"><i class="fas fa-shield-alt" style="color: #f59e0b;"></i> MoMo Automatic Verification</h3>
        <p style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 1.25rem; line-height: 1.4;">Your Mobile Money payment transaction ID is detected and locked below. Click "Verify & Claim Funds" to confirm.</p>
        
        <?php if (isset($claimDetails)): ?>
            <div style="background: var(--success-light); padding: 1.25rem; border-radius: 0.5rem; margin-bottom: 1rem; border: 1px solid rgba(34, 197, 94, 0.2);">
                <p style="font-size: 0.9rem; font-weight: 800; color: var(--success); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem;">
                    <i class="fas fa-check-circle"></i> Transaction Found!
                </p>
                <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.3rem;">Amount: GHS <?= number_format($claimDetails['amount'], 2) ?></div>
                <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.3rem;">Sender: <?= htmlspecialchars($claimDetails['sender_name'] ?? 'Unknown') ?></div>
                <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 1rem;">Provider: <?= htmlspecialchars($claimDetails['provider'] ?? 'Unknown') ?></div>
                
                <form method="POST" onsubmit="return handleClaimSubmit(this, 'Confirming & Claiming Funds...');">
                    <input type="hidden" name="mode" value="confirm_claim">
                    <input type="hidden" name="tx_id" value="<?= htmlspecialchars($claimDetails['transaction_id']) ?>">
                    <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 0.9rem; padding: 0.75rem; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none; font-weight: 700;">Confirm & Claim Funds</button>
                </form>
            </div>
            <script>document.getElementById('claimModal').style.display='flex';</script>
        <?php else: ?>
            <form method="POST" onsubmit="return handleClaimSubmit(this, 'Verifying MoMo Payment...');">
                <input type="hidden" name="mode" value="search_claim">
                <div style="margin-bottom: 1.25rem;">
                    <label class="form-label" style="font-weight: 700; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">MoMo Transaction ID</label>
                    <input type="text" name="tx_id" id="claim_tx_id_input" class="form-control" placeholder="e.g. 123456789" readonly required style="font-size: 0.95rem; padding: 0.7rem; border-radius: 8px; font-weight: 700; background-color: #f1f5f9; cursor: not-allowed; font-family: monospace; letter-spacing: 0.05em; color: #1e293b; border: 1px solid #cbd5e1;">
                    <div style="margin-top: 0.6rem; display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                        <span id="claim_tx_hint" style="font-size: 0.7rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.25rem; padding-top: 0.2rem;">
                            <i class="fas fa-lock" style="font-size: 0.6rem; color: #94a3b8;"></i> Transaction ID locked for security.
                        </span>
                        <button type="button" onclick="toggleEditClaimTx()" id="editClaimTxBtn"
                            style="display: inline-flex; align-items: center; gap: 0.4rem; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1.5px solid #93c5fd; border-radius: 999px; padding: 0.35rem 0.75rem; font-size: 0.72rem; font-weight: 700; color: #1d4ed8; cursor: pointer; white-space: nowrap; transition: all 0.2s ease; box-shadow: 0 1px 4px rgba(37,99,235,0.10);"
                            onmouseover="this.style.background='linear-gradient(135deg,#dbeafe 0%,#bfdbfe 100%)'; this.style.boxShadow='0 2px 8px rgba(37,99,235,0.18)'; this.style.borderColor='#3b82f6';"
                            onmouseout="this.style.background='linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%)'; this.style.boxShadow='0 1px 4px rgba(37,99,235,0.10)'; this.style.borderColor='#93c5fd';">
                            <span style="display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; background:#2563eb; border-radius:50%;"><i class="fas fa-pencil-alt" style="color:#fff; font-size:0.5rem;"></i></span>
                            Enter Transaction ID manually
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 0.9rem; padding: 0.75rem; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border: none; font-weight: 700; box-shadow: 0 4px 10px rgba(217,119,6,0.25);"><i class="fas fa-shield-alt" style="margin-right: 0.4rem;"></i> Verify & Claim Funds</button>
            </form>
            <?php if (isset($mode) && $mode === 'search_claim' && !empty($errors)): ?>
                <script>document.getElementById('claimModal').style.display='flex';</script>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Dynamic Response Modals -->
<div id="dynamicSuccessModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 999999; padding: 1rem;">
    <div style="background: white; border-radius: var(--radius-lg); padding: 1.5rem; width: 100%; max-width: 340px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); animation: scaleUp 0.3s ease-out;">
        <div style="width: 54px; height: 54px; background-color: #dcfce7; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem auto;">
            <i class="fas fa-check-circle" style="color: #22c55e; font-size: 1.8rem;"></i>
        </div>
        <h2 style="font-size: 1.15rem; font-weight: 800; color: #1e293b; margin-bottom: 0.5rem;">Payment Verified!</h2>
        <p id="dynamicSuccessMsg" style="font-size: 0.88rem; color: #475569; margin-bottom: 1.5rem; line-height: 1.4;"></p>
        <button onclick="window.location.reload();" class="btn btn-primary" style="width: 100%; padding: 0.65rem; font-size: 0.9rem; font-weight: 700; border-radius: 0.4rem; background-color: #22c55e; border: none; box-shadow: 0 4px 6px rgba(34, 197, 94, 0.25); color: white; cursor: pointer;">Awesome!</button>
    </div>
</div>

<div id="dynamicWarningModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 999999; padding: 1rem;">
    <div style="background: white; border-radius: var(--radius-lg); padding: 1.5rem; width: 100%; max-width: 340px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); animation: scaleUp 0.3s ease-out;">
        <div style="width: 54px; height: 54px; background-color: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem auto;">
            <i class="fas fa-exclamation-triangle" style="color: #ef4444; font-size: 1.6rem;"></i>
        </div>
        <h2 style="font-size: 1.15rem; font-weight: 800; color: #1e293b; margin-bottom: 0.5rem;">Payment Unsuccessful</h2>
        <p id="dynamicWarningMsg" style="font-size: 0.88rem; color: #475569; margin-bottom: 1.5rem; line-height: 1.4;"></p>
        <button onclick="document.getElementById('dynamicWarningModal').style.display='none';" class="btn btn-primary" style="width: 100%; padding: 0.65rem; font-size: 0.9rem; font-weight: 700; border-radius: 0.4rem; background-color: #ef4444; border: none; box-shadow: 0 4px 6px rgba(239, 68, 68, 0.25); color: white; cursor: pointer;">Try Again</button>
    </div>
</div>

</body>
</html>
