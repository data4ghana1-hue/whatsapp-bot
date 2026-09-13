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

// Filters & Pagination
$filterStatus = trim($_GET['status'] ?? '');
$searchQuery  = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 50;
$offset       = ($page - 1) * $limit;

// Base query for MTN Unverified orders of this user
$whereClauses = ["user_id = :uid", "UPPER(network) = 'MTN UNVERIFIED'"];
$params = [':uid' => $userId];

if ($filterStatus !== '') {
    if ($filterStatus === 'inflight') {
        $whereClauses[] = "status IN ('waiting', 'processing', 'pending', 'accepted', 'validating')";
    } elseif ($filterStatus === 'completed') {
        $whereClauses[] = "status IN ('completed', 'successful', 'success', 'successfully')";
    } elseif ($filterStatus === 'failed') {
        $whereClauses[] = "status IN ('failed', 'rejected', 'refunded')";
    } else {
        $whereClauses[] = "status = :st";
        $params[':st'] = $filterStatus;
    }
}

if ($searchQuery !== '') {
    $whereClauses[] = "(id = :qid OR recipient_phone LIKE :qphone)";
    $params[':qid'] = (int)$searchQuery;
    $params[':qphone'] = '%' . $searchQuery . '%';
}

$whereSql = implode(' AND ', $whereClauses);

// Count total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM bundle_sends WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $limit));

// Fetch paginated rows
$sql = "SELECT * FROM bundle_sends WHERE $whereSql ORDER BY id DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics KPI
$kpiStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN status IN ('completed', 'successful', 'success') THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN status IN ('waiting', 'processing', 'pending', 'accepted', 'validating') THEN 1 ELSE 0 END) AS inflight_count,
        SUM(CASE WHEN status IN ('failed', 'rejected', 'refunded') THEN 1 ELSE 0 END) AS failed_count,
        COALESCE(SUM(gb_amount), 0) AS total_gb
    FROM bundle_sends
    WHERE user_id = ? AND UPPER(network) = 'MTN UNVERIFIED'
");
$kpiStmt->execute([$userId]);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_count' => 0, 'completed_count' => 0, 'inflight_count' => 0, 'failed_count' => 0, 'total_gb' => 0
];

function get_unverified_status_badge($status) {
    $s = strtolower(trim($status ?? ''));
    if (in_array($s, ['completed', 'successful', 'success', 'successfully'])) {
        return '<span class="status-pill pill-success"><i class="fas fa-check-circle"></i> Completed</span>';
    }
    if (in_array($s, ['waiting', 'processing'])) {
        return '<span class="status-pill pill-processing"><i class="fas fa-spinner fa-spin"></i> In-Flight</span>';
    }
    if ($s === 'pending') {
        return '<span class="status-pill pill-pending"><i class="fas fa-clock"></i> Queued</span>';
    }
    if ($s === 'refunded' || $s === 'reversal') {
        return '<span class="status-pill pill-purple"><i class="fas fa-undo"></i> Refunded</span>';
    }
    if (in_array($s, ['failed', 'rejected'])) {
        return '<span class="status-pill pill-failed"><i class="fas fa-times-circle"></i> Failed</span>';
    }
    return '<span class="status-pill pill-neutral">' . htmlspecialchars(ucfirst($s)) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no, viewport-fit=cover">
<title>MTN Unverified Orders &middot; <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
<script src="/js/script.js?v=<?= filemtime(__DIR__ . '/js/script.js') ?>"></script>
<style>
* {
    box-sizing: border-box;
}
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    margin: 0;
    padding: 0;
}
.app-wrapper {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}
.main-content {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
    min-width: 0 !important;
}
.page-container {
    width: 100% !important;
    max-width: 800px !important;
    margin: 0 auto;
    padding: 0.85rem 0.85rem 3rem !important;
    box-sizing: border-box;
    overflow-x: hidden !important;
}
@media (max-width: 480px) {
    .page-container {
        padding: 0.6rem 0.6rem 3rem !important;
    }
}

/* Hero Header styling matching dashboard font aesthetics */
.unv-hero-header {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
    border-radius: 14px;
    padding: 0.95rem 1.05rem;
    color: #fff;
    margin-bottom: 0.85rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 14px rgba(67, 56, 202, 0.2);
    box-sizing: border-box;
    width: 100%;
}
.unv-hero-header::after {
    content: '';
    position: absolute;
    top: -40%;
    right: -10%;
    width: 220px;
    height: 220px;
    background: radial-gradient(circle, rgba(249,115,22,0.18) 0%, rgba(249,115,22,0) 70%);
    border-radius: 50%;
    pointer-events: none;
}
.unv-hero-header h1 {
    font-size: 1.15rem;
    font-weight: 700;
    margin: 0.35rem 0 0.2rem 0;
    letter-spacing: -0.01em;
    color: #ffffff !important;
}
.unv-hero-desc {
    font-size: 0.76rem;
    color: #cbd5e1;
    margin: 0 0 0.75rem 0;
    line-height: 1.4;
    font-weight: 400;
}
.unv-badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(249,115,22,0.18);
    border: 1px solid rgba(249,115,22,0.35);
    color: #fdba74;
    font-size: 0.68rem;
    font-weight: 600;
    padding: 0.18rem 0.55rem;
    border-radius: 99px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.unv-sla-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.22);
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 500;
    padding: 0.18rem 0.55rem;
    border-radius: 99px;
}

/* 24-Hour Notice Bar */
.delivery-notice-bar {
    background: #fff;
    border: 1px solid #fed7aa;
    border-left: 3px solid #f97316;
    border-radius: 12px;
    padding: 0.65rem 0.85rem;
    margin-bottom: 0.85rem;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 1px 4px rgba(249,115,22,0.03);
    box-sizing: border-box;
    width: 100%;
}
.notice-icon-box {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    flex-shrink: 0;
}

/* Compact KPI Summary Box - 4 stats in a neat small card */
.kpi-summary-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 0.5rem 0.6rem;
    margin-bottom: 0.85rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 640px) {
    .kpi-summary-box {
        grid-template-columns: repeat(2, 1fr);
        gap: 6px;
        padding: 0.5rem;
    }
}
.kpi-box-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0.45rem 0.55rem;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    box-sizing: border-box;
    min-width: 0;
}
.kpi-box-icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    flex-shrink: 0;
}
.kpi-box-icon.total     { background: #e0e7ff; color: #4338ca; }
.kpi-box-icon.inflight  { background: #e0f2fe; color: #0284c7; }
.kpi-box-icon.completed { background: #dcfce7; color: #16a34a; }
.kpi-box-icon.failed    { background: #fee2e2; color: #dc2626; }

.kpi-box-data {
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
}
.kpi-box-label {
    font-size: 0.65rem;
    font-weight: 500;
    color: #64748b;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.kpi-box-val {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.1;
    margin-top: 1px;
}

/* Filter Card */
.filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.65rem 0.75rem;
    margin-bottom: 0.85rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    box-sizing: border-box;
    width: 100%;
}
.quick-filter-btn {
    padding: 4px 10px;
    border-radius: 99px;
    font-size: 0.72rem;
    font-weight: 500;
    text-decoration: none;
    transition: 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid transparent;
}
.quick-filter-btn.active {
    background: #4338ca;
    color: #fff;
    font-weight: 600;
    box-shadow: 0 2px 6px rgba(67, 56, 202, 0.18);
}
.quick-filter-btn:not(.active) {
    background: #f1f5f9;
    color: #475569;
    border-color: #e2e8f0;
}
.quick-filter-btn:not(.active):hover {
    background: #e2e8f0;
    color: #0f172a;
}

/* Nice Colored Buttons for Order Status */
.status-pill, .status-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 11px;
    border-radius: 8px;
    font-size: 0.72rem;
    font-weight: 600;
    color: #ffffff !important;
    white-space: nowrap;
    letter-spacing: 0.02em;
    border: none;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.status-pill:hover, .status-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.18);
}
.pill-success, .status-btn-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.35);
}
.pill-processing, .status-btn-processing {
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
    box-shadow: 0 2px 8px rgba(14, 165, 233, 0.35);
}
.pill-pending, .status-btn-pending {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.35);
}
.pill-failed, .status-btn-failed {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.35);
}
.pill-purple, .status-btn-purple {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    box-shadow: 0 2px 8px rgba(139, 92, 246, 0.35);
}
.pill-neutral, .status-btn-neutral {
    background: linear-gradient(135deg, #64748b 0%, #475569 100%);
    box-shadow: 0 2px 6px rgba(100, 116, 139, 0.25);
}

/* Table Container - Strict Table Format across all devices with Horizontal Scroll */
.orders-table-wrap {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch;
    box-shadow: 0 1px 4px rgba(0,0,0,0.02);
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
    margin-bottom: 0.85rem;
}
.orders-table {
    width: 100%;
    min-width: 540px;
    border-collapse: collapse;
    font-size: 0.78rem;
}
.orders-table th {
    background: #f8fafc;
    padding: 9px 12px;
    font-weight: 600;
    font-size: 0.68rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    white-space: nowrap;
}
.orders-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
    white-space: nowrap;
    font-weight: 400;
}
.orders-table tr:hover {
    background: #f8fafc;
}

/* Action Buttons */
.btn-action {
    padding: 4px 9px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 500;
    cursor: pointer;
    transition: 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-decoration: none;
    border: none;
}
.btn-action-note {
    background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
    border: none;
    color: #ffffff !important;
    padding: 6px 13px;
    border-radius: 8px;
    font-size: 0.74rem;
    font-weight: 600;
    box-shadow: 0 2px 6px rgba(79, 70, 229, 0.28);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    letter-spacing: 0.01em;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.btn-action-note:hover {
    background: linear-gradient(135deg, #4338ca 0%, #3730a3 100%);
    color: #ffffff !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4);
}
.btn-action-note:active {
    transform: translateY(0);
    box-shadow: 0 1px 3px rgba(79, 70, 229, 0.3);
}

/* Modal */
.custom-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.custom-modal.open { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 20px;
    max-width: 440px;
    width: 100%;
    padding: 1.5rem;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.12);
    position: relative;
    animation: popModal 0.2s ease-out;
}
@keyframes popModal {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
</style>
</head>
<body class="dashboard-page">
<div class="app-wrapper">
    <main class="main-content" style="padding: 0; background-color: #f8fafc; min-height: 100vh;">
        <?php include __DIR__ . '/header.php'; ?>

        <div class="page-container">
            
            <!-- Hero Header Banner -->
            <div class="unv-hero-header">
                <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                    <span class="unv-badge-pill">
                        <i class="fas fa-shield-alt"></i> Backup Network
                    </span>
                    <span class="unv-sla-badge">
                        <i class="fas fa-clock"></i> 24H Delivery Window
                    </span>
                </div>
                <h1>My MTN Unverified Orders</h1>
                <p class="unv-hero-desc">
                    Track fulfillment progress for your MTN Unverified orders routed through high-capacity backup lines.
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <a href="mtn_unverified" style="background: #ffffff; color: #4338ca; text-decoration: none; padding: 0.45rem 0.85rem; border-radius: 8px; font-weight: 600; font-size: 0.76rem; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 1px 4px rgba(0,0,0,0.08);">
                        <i class="fas fa-plus-circle"></i> Place New Order
                    </a>
                    <a href="mtn_unverified_orders" style="background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.22); color: #fff; text-decoration: none; padding: 0.45rem 0.85rem; border-radius: 8px; font-weight: 500; font-size: 0.76rem; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </a>
                </div>
            </div>

            <!-- 24-Hour SLA Notice -->
            <div class="delivery-notice-bar">
                <div class="notice-icon-box">
                    <i class="fas fa-history"></i>
                </div>
                <div style="min-width: 0;">
                    <div style="font-weight: 600; color: #9a3412; font-size: 0.78rem; margin-bottom: 1px;">
                        24-Hour Fulfillment Commitment
                    </div>
                    <div style="font-size: 0.73rem; color: #7c2d12; line-height: 1.35; font-weight: 400;">
                        Orders are processed via automated batch dispatch and typically delivered within 24 hours.
                    </div>
                </div>
            </div>

            <!-- Compact KPI Box: Total Orders, In-Flight, Completed, Failed -->
            <div class="kpi-summary-box">
                <div class="kpi-box-item">
                    <div class="kpi-box-icon total">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <div class="kpi-box-data">
                        <span class="kpi-box-label">Total Orders</span>
                        <span class="kpi-box-val"><?= number_format((int)$kpi['total_count']) ?></span>
                    </div>
                </div>
                <div class="kpi-box-item">
                    <div class="kpi-box-icon inflight">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div class="kpi-box-data">
                        <span class="kpi-box-label" style="color: #0284c7;">In-Flight</span>
                        <span class="kpi-box-val" style="color: #0284c7;"><?= number_format((int)$kpi['inflight_count']) ?></span>
                    </div>
                </div>
                <div class="kpi-box-item">
                    <div class="kpi-box-icon completed">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <div class="kpi-box-data">
                        <span class="kpi-box-label" style="color: #16a34a;">Completed</span>
                        <span class="kpi-box-val" style="color: #16a34a;"><?= number_format((int)$kpi['completed_count']) ?></span>
                    </div>
                </div>
                <div class="kpi-box-item">
                    <div class="kpi-box-icon failed">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="kpi-box-data">
                        <span class="kpi-box-label" style="color: #dc2626;">Failed</span>
                        <span class="kpi-box-val" style="color: #dc2626;"><?= number_format((int)$kpi['failed_count']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Search and Filter Bar -->
            <div class="filter-card">
                <form method="GET" style="display: flex; flex-direction: column; gap: 8px; width: 100%;">
                    <div style="display: flex; gap: 6px; align-items: center; width: 100%;">
                        <div style="flex: 1; min-width: 0; position: relative;">
                            <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.75rem;"></i>
                            <input type="text" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search phone or Order ID..." style="width: 100%; padding: 0.5rem 0.65rem 0.5rem 1.9rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.78rem; font-weight: 400; outline: none; box-sizing: border-box; font-family: inherit;">
                        </div>
                        <button type="submit" style="background: #0f172a; color: #fff; border: none; padding: 0.5rem 0.85rem; border-radius: 8px; font-size: 0.78rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0;">
                            <i class="fas fa-filter"></i> Search
                        </button>
                        <?php if ($filterStatus !== '' || $searchQuery !== ''): ?>
                            <a href="mtn_unverified_orders" style="color: #64748b; font-size: 0.75rem; font-weight: 500; text-decoration: none; padding: 0.5rem 0.4rem; flex-shrink: 0;">
                                Clear
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Status Pills -->
                    <div style="display: flex; flex-wrap: wrap; gap: 5px; align-items: center; padding-top: 4px; border-top: 1px solid #f1f5f9;">
                        <span style="font-size: 0.68rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; margin-right: 2px;">Status:</span>
                        <a href="?status=&q=<?= urlencode($searchQuery) ?>" class="quick-filter-btn <?= $filterStatus === '' ? 'active' : '' ?>">All</a>
                        <a href="?status=inflight&q=<?= urlencode($searchQuery) ?>" class="quick-filter-btn <?= $filterStatus === 'inflight' ? 'active' : '' ?>">In-Flight</a>
                        <a href="?status=completed&q=<?= urlencode($searchQuery) ?>" class="quick-filter-btn <?= $filterStatus === 'completed' ? 'active' : '' ?>">Completed</a>
                        <a href="?status=failed&q=<?= urlencode($searchQuery) ?>" class="quick-filter-btn <?= $filterStatus === 'failed' ? 'active' : '' ?>">Failed</a>
                    </div>
                </form>
            </div>

            <!-- Table Header Label & Mobile Scroll Hint -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; padding: 0 2px;">
                <span style="font-size: 0.74rem; font-weight: 600; color: #475569;">Orders History</span>
                <span style="font-size: 0.68rem; color: #94a3b8; display: inline-flex; align-items: center; gap: 4px;">
                    <i class="fas fa-arrows-alt-h"></i> Scroll table horizontally
                </span>
            </div>

            <!-- Desktop Orders Table -->
            <div class="orders-table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Recipient</th>
                            <th>Data Size</th>
                            <th>Status</th>
                            <th>Date Placed</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 3.5rem 1rem; color: #94a3b8;">
                                    <div style="width: 50px; height: 50px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin: 0 auto 0.75rem; font-size: 1.35rem; color: #cbd5e1;">
                                        <i class="fas fa-inbox"></i>
                                    </div>
                                    <div style="font-size: 0.92rem; font-weight: 600; color: #475569; margin-bottom: 3px;">No MTN Unverified orders found</div>
                                    <span style="font-size: 0.8rem; font-weight: 400;">Your placed orders will appear here automatically.</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $ord): ?>
                                <tr id="row-<?= (int)$ord['id'] ?>">
                                    <td>
                                        <span style="color: #334155; font-family: monospace; font-size: 0.85rem; background: #f1f5f9; padding: 2px 7px; border-radius: 6px; font-weight: 600;">
                                            #<?= (int)$ord['id'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div style="width: 26px; height: 26px; border-radius: 50%; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 0.72rem; border: 1px solid #ffedd5;">
                                                <i class="fas fa-phone-alt"></i>
                                            </div>
                                            <span style="font-weight: 500; color: #1e293b; letter-spacing: 0.01em;"><?= htmlspecialchars($ord['recipient_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="display: inline-block; font-weight: 600; color: #4338ca; background: #eef2ff; padding: 2px 8px; border-radius: 6px; border: 1px solid #e0e7ff; font-size: 0.82rem;">
                                            <?= (float)$ord['gb_amount'] ?> GB
                                        </span>
                                    </td>
                                    <td id="status-badge-<?= (int)$ord['id'] ?>">
                                        <?= get_unverified_status_badge($ord['status']) ?>
                                    </td>
                                    <td style="color: #64748b; font-size: 0.8rem;">
                                        <div style="font-weight: 500; color: #334155;"><?= date('M d, Y', strtotime($ord['created_at'])) ?></div>
                                        <div style="font-size: 0.72rem; color: #94a3b8; font-weight: 400;"><?= date('h:i A', strtotime($ord['created_at'])) ?></div>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: flex; justify-content: flex-end;">
                                            <?php
                                                $sanitizedNote = function_exists('sanitizeOrderNoteForClient') 
                                                    ? sanitizeOrderNoteForClient($ord['message'] ?? '', $ord['status'] ?? '', $ord['network'] ?? '', $ord['created_at'] ?? null, $ord['gb_amount'] ?? null, $ord['recipient_phone'] ?? null)
                                                    : ($ord['message'] ?? '');
                                            ?>
                                            <button class="btn-action btn-action-note" onclick="showNoteModal('<?= (int)$ord['id'] ?>', '<?= htmlspecialchars(addslashes($sanitizedNote), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($ord['recipient_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>', '<?= (float)$ord['gb_amount'] ?>', '<?= strtolower($ord['status'] ?? '') ?>')">
                                                <i class="fas fa-info-circle"></i> Details
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; margin-top: 14px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 0.82rem; color: #64748b;">
                    <div>Page <span style="font-weight: 600; color: #0f172a;"><?= $page ?></span> of <span style="font-weight: 600; color: #0f172a;"><?= $totalPages ?></span> (<?= number_format($totalRows) ?> total orders)</div>
                    <div style="display: flex; gap: 8px;">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&status=<?= urlencode($filterStatus) ?>&q=<?= urlencode($searchQuery) ?>" style="padding: 5px 11px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; color: #0f172a; text-decoration: none; font-weight: 600; font-size: 0.8rem;">
                                <i class="fas fa-chevron-left"></i> Prev
                            </a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&status=<?= urlencode($filterStatus) ?>&q=<?= urlencode($searchQuery) ?>" style="padding: 5px 11px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; color: #0f172a; text-decoration: none; font-weight: 600; font-size: 0.8rem;">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<!-- Order Note & Details Modal -->
<div class="custom-modal" id="noteModal" onclick="if(event.target===this)closeNoteModal()">
    <div class="modal-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.85rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-shield-alt" style="color: #f97316;"></i> Order #<span id="modalOrderId"></span>
                </h3>
                <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                    MTN Unverified Data Fulfillment Details
                </div>
            </div>
            <button onclick="closeNoteModal()" style="background: #f1f5f9; border: none; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #64748b; cursor: pointer;">&times;</button>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 1rem;">
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 8px 12px;">
                <div style="font-size: 0.68rem; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Recipient Phone</div>
                <div style="font-size: 0.88rem; font-weight: 800; color: #0f172a; margin-top: 2px;" id="modalRecipient">-</div>
            </div>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 8px 12px;">
                <div style="font-size: 0.68rem; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Package Volume</div>
                <div style="font-size: 0.88rem; font-weight: 800; color: #4338ca; margin-top: 2px;" id="modalGb">-</div>
            </div>
        </div>

        <div style="margin-bottom: 1.25rem;">
            <div style="font-size: 0.72rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 6px;">Provider Delivery Note:</div>
            <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 14px; font-size: 0.85rem; color: #334155; line-height: 1.55; position: relative;" id="modalNoteText">
                -
            </div>
        </div>

        <div id="modalSlaBox" style="background: rgba(249,115,22,0.06); border: 1px solid rgba(249,115,22,0.25); border-radius: 12px; padding: 10px 14px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-clock" style="color: #f97316; font-size: 1rem;"></i>
            <span id="modalSlaText" style="font-size: 0.78rem; color: #9a3412; font-weight: 600;">
                Delivery Timeframe: Within 24 hours of placement.
            </span>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 8px;">
            <button onclick="closeNoteModal()" style="background: #0f172a; color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 10px; font-size: 0.82rem; font-weight: 700; cursor: pointer;">
                Close
            </button>
        </div>
    </div>
</div>

<script>
function showNoteModal(orderId, note, recipient, gb, status) {
    const isCompleted = ['completed', 'successful', 'success', 'delivered', 'approved'].includes((status || '').toLowerCase());
    
    if (typeof Swal !== 'undefined') {
        if (!document.getElementById('swal-rounded-style')) {
            const style = document.createElement('style');
            style.id = 'swal-rounded-style';
            style.innerHTML = '.rounded-notice-card { border-radius: 28px !important; } .rounded-notice-card .swal2-icon { display: none !important; } .rounded-notice-btn { border-radius: 99px !important; padding: 0.5rem 1.5rem !important; background-color: #ffffff !important; color: #3b82f6 !important; font-weight: 800 !important; border: none !important; }';
            document.head.appendChild(style);
        }

        let title = 'Order Processing';
        let badgeText = 'PROVIDER STATUS: IN-FLIGHT';
        const cleanGb = gb ? parseFloat(gb) + 'GB ' : '';
        let displayMessage = note || ('Order #' + orderId + ' received and currently processing for delivery.');

        if (isCompleted) {
            title = 'Data Delivered';
            badgeText = 'PROVIDER STATUS: DELIVERED';
            displayMessage = (note && note.toLowerCase().includes('delivered successfully'))
                ? note
                : ('Data bundle of ' + cleanGb + 'to ' + recipient + ' was verified and delivered successfully!');
        } else if (['failed', 'rejected', 'refunded'].includes((status || '').toLowerCase())) {
            title = 'Order Notice';
            badgeText = 'STATUS: ' + status.toUpperCase();
        } else if ((status || '').toLowerCase() === 'waiting') {
            title = 'Order Waiting';
            badgeText = 'STATUS: WAITING';
        }

        const apiStatusBadge = '<div style="margin-top:0.75rem;"><span style="display:inline-block; background:rgba(255,255,255,0.25); color:#ffffff; padding:0.35rem 0.85rem; border-radius:99px; font-weight:800; font-size:0.78rem; letter-spacing:0.05em; text-transform:uppercase;">' + badgeText + '</span></div>';

        Swal.fire({
            title: title,
            html: '<div style="font-size:1.05rem; line-height:1.5; margin-bottom:0.5rem; color:#ffffff;">' + displayMessage + '</div>' + apiStatusBadge,
            background: '#3b82f6',
            color: '#ffffff',
            confirmButtonColor: '#ffffff',
            confirmButtonText: '<span style="color:#3b82f6; font-weight:800;">Understood</span>',
            padding: '2em',
            customClass: {
                popup: 'rounded-notice-card',
                confirmButton: 'rounded-notice-btn'
            }
        });
        return;
    }

    document.getElementById('modalOrderId').innerText = orderId;
    document.getElementById('modalRecipient').innerText = recipient || '-';
    document.getElementById('modalGb').innerText = (gb ? gb + ' GB' : '-');

    const slaBox = document.getElementById('modalSlaBox');
    if (isCompleted) {
        document.getElementById('modalNoteText').innerText = (note && note.toLowerCase().includes('delivered successfully'))
            ? note
            : ('Data bundle of ' + (gb ? gb + 'GB ' : '') + 'to ' + recipient + ' was verified and delivered successfully!');
        if (slaBox) {
            slaBox.style.background = 'rgba(16, 185, 129, 0.08)';
            slaBox.style.borderColor = 'rgba(16, 185, 129, 0.3)';
            slaBox.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981; font-size: 1rem;"></i><span style="font-size: 0.78rem; color: #065f46; font-weight: 700;">Delivered Successfully</span>';
        }
    } else {
        document.getElementById('modalNoteText').innerText = note ? note : 'Order received by AI Assistant Provider and currently processing for delivery.';
        if (slaBox) {
            slaBox.style.background = 'rgba(249,115,22,0.06)';
            slaBox.style.borderColor = 'rgba(249,115,22,0.25)';
            slaBox.innerHTML = '<i class="fas fa-clock" style="color: #f97316; font-size: 1rem;"></i><span style="font-size: 0.78rem; color: #9a3412; font-weight: 600;">Delivery Timeframe: Within 24 hours of placement.</span>';
        }
    }
    document.getElementById('noteModal').classList.add('open');
}

function closeNoteModal() {
    document.getElementById('noteModal').classList.remove('open');
}

function checkSingleStatus(orderId) {
    const btn = document.getElementById('btn-chk-' + orderId);
    const mbtn = document.getElementById('mbtn-chk-' + orderId);
    [btn, mbtn].forEach(b => {
        if (b) {
            b.disabled = true;
            b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }
    });

    const fd = new FormData();
    fd.append('order_id', orderId);

    fetch('/ajax_single_order_status.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            const badgeEl = document.getElementById('status-badge-' + orderId);
            const mbadgeEl = document.getElementById('mstatus-badge-' + orderId);
            const status = (res.status || '').toLowerCase();
            let html = '';
            if (['completed', 'successful', 'success'].includes(status)) {
                html = '<span class="status-pill pill-success"><i class="fas fa-check-circle"></i> Completed</span>';
                [btn, mbtn].forEach(b => { if (b) b.style.display = 'none'; });
            } else if (['waiting', 'processing'].includes(status)) {
                html = '<span class="status-pill pill-processing"><i class="fas fa-spinner fa-spin"></i> In-Flight</span>';
                [btn, mbtn].forEach(b => { if (b) { b.disabled = false; b.innerHTML = '<i class="fas fa-sync-alt"></i> Check'; } });
            } else if (['failed', 'rejected', 'refunded'].includes(status)) {
                html = '<span class="status-pill pill-failed"><i class="fas fa-times-circle"></i> Failed</span>';
                [btn, mbtn].forEach(b => { if (b) b.style.display = 'none'; });
            } else {
                html = '<span class="status-pill pill-neutral">' + status.toUpperCase() + '</span>';
                [btn, mbtn].forEach(b => { if (b) { b.disabled = false; b.innerHTML = '<i class="fas fa-sync-alt"></i> Check'; } });
            }
            if (badgeEl) badgeEl.innerHTML = html;
            if (mbadgeEl) mbadgeEl.innerHTML = html;
        } else {
            alert(res.message || 'Status check could not be completed.');
            [btn, mbtn].forEach(b => { if (b) { b.disabled = false; b.innerHTML = '<i class="fas fa-sync-alt"></i> Check'; } });
        }
    })
    .catch(err => {
        alert('Network error communicating with server.');
        [btn, mbtn].forEach(b => { if (b) { b.disabled = false; b.innerHTML = '<i class="fas fa-sync-alt"></i> Check'; } });
    });
}
</script>
</body>
</html>
