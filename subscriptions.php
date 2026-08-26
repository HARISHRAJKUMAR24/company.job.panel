<?php
require_once './config/config.php';

// Check if logged in as company
if (!isCompanyLoggedIn()) {
    header("Location: " . APP_URL . "login.php");
    exit();
}

// Get company details from session
$company_id = $_SESSION['company_id'] ?? 0;
$company_name = $_SESSION['company_name'] ?? 'Company';
$company_email = $_SESSION['email'] ?? '';

// If company_id not in session, fetch from database
if (!$company_id) {
    $stmt = $pdo->prepare("SELECT id, company_name, email FROM companies WHERE email = ?");
    $stmt->execute([$_SESSION['email']]);
    $company = $stmt->fetch();
    if ($company) {
        $company_id = $company['id'];
        $company_name = $company['company_name'];
        $company_email = $company['email'];
        $_SESSION['company_id'] = $company_id;
        $_SESSION['company_name'] = $company_name;
    }
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Get plan details
    if ($_GET['action'] === 'get_plan') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        try {
            $stmt = $pdo->prepare("SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1");
            $stmt->execute([$id]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $plan]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // Get Razorpay keys
    if ($_GET['action'] === 'get_keys') {
        try {
            $stmt = $pdo->query("SELECT razorpay_key_id, razorpay_key_secret FROM admin_settings LIMIT 1");
            $settings = $stmt->fetch();
            echo json_encode([
                'success' => true,
                'key_id' => $settings['razorpay_key_id'] ?? '',
                'key_secret' => $settings['razorpay_key_secret'] ?? ''
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // Create subscription
    if ($_GET['action'] === 'create_subscription') {
        $plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : 0;
        $payment_id = isset($_POST['payment_id']) ? $_POST['payment_id'] : '';
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $company_id = isset($_POST['company_id']) ? intval($_POST['company_id']) : 0;

        if (!$plan_id || !$company_id || !$payment_id) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }

        try {
            // Get plan details
            $stmt = $pdo->prepare("SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1");
            $stmt->execute([$plan_id]);
            $plan = $stmt->fetch();

            if (!$plan) {
                echo json_encode(['success' => false, 'message' => 'Plan not found or inactive']);
                exit();
            }

            // Check if company already has an active subscription
            $stmt = $pdo->prepare("SELECT id FROM company_subscriptions WHERE company_id = ? AND status = 'active'");
            $stmt->execute([$company_id]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Update existing subscription instead of creating new one
                $stmt = $pdo->prepare("
                    UPDATE company_subscriptions 
                    SET plan_id = ?, plan_name = ?, duration_months = ?, price_inr = ?,
                        job_posts_limit = ?, resume_views_limit = ?, has_custom_domain = ?,
                        has_priority_support = ?, has_advanced_analytics = ?, is_featured = ?,
                        payment_id = ?, start_date = NOW(), 
                        expiry_date = DATE_ADD(NOW(), INTERVAL ? MONTH),
                        status = 'active', updated_at = NOW()
                    WHERE company_id = ? AND status = 'active'
                ");

                $stmt->execute([
                    $plan['id'],
                    $plan['plan_name'],
                    $plan['duration_months'],
                    $plan['price_inr'],
                    $plan['job_posts_limit'],
                    $plan['resume_views_limit'],
                    $plan['has_custom_domain'],
                    $plan['has_priority_support'],
                    $plan['has_advanced_analytics'],
                    $plan['is_featured'],
                    $payment_id,
                    $plan['duration_months'],
                    $company_id
                ]);

                $subscription_id = $existing['id'];
            } else {
                // Insert new subscription record
                $start_date = date('Y-m-d H:i:s');
                $expiry_date = date('Y-m-d H:i:s', strtotime("+{$plan['duration_months']} months"));

                $stmt = $pdo->prepare("
                    INSERT INTO company_subscriptions (
                        company_id, plan_id, plan_name, duration_months, price_inr,
                        job_posts_limit, resume_views_limit, has_custom_domain,
                        has_priority_support, has_advanced_analytics, is_featured,
                        payment_id, start_date, expiry_date, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                ");

                $stmt->execute([
                    $company_id,
                    $plan['id'],
                    $plan['plan_name'],
                    $plan['duration_months'],
                    $plan['price_inr'],
                    $plan['job_posts_limit'],
                    $plan['resume_views_limit'],
                    $plan['has_custom_domain'],
                    $plan['has_priority_support'],
                    $plan['has_advanced_analytics'],
                    $plan['is_featured'],
                    $payment_id,
                    $start_date,
                    $expiry_date
                ]);

                $subscription_id = $pdo->lastInsertId();
            }

            // Update company table with plan_id
            $stmt = $pdo->prepare("UPDATE companies SET plan_id = ? WHERE id = ?");
            $stmt->execute([$plan_id, $company_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Subscription created successfully',
                'subscription_id' => $subscription_id
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
}

// Get all active subscription plans
try {
    $stmt = $pdo->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC, price_inr ASC");
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $plans = [];
}

// Get company's current subscription
try {
    $stmt = $pdo->prepare("
        SELECT cs.*, c.plan_id as current_plan_id
        FROM companies c
        LEFT JOIN company_subscriptions cs ON c.id = cs.company_id AND cs.status = 'active'
        WHERE c.id = ?
    ");
    $stmt->execute([$company_id]);
    $current_subscription = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $current_subscription = null;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once './templates/head.php'; ?>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f6f9;
        }

        .sidebar {
            background: #1a2332;
            min-height: 100vh;
            transition: all 0.3s ease;
            width: 260px;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1050;
            overflow-y: auto;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 0.7rem 1rem;
            border-radius: 8px;
            transition: all 0.2s;
            font-size: 0.9rem;
        }

        .sidebar .nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
        }

        .sidebar .nav-link.active {
            color: #fff;
            background: #667eea;
        }

        .sidebar .nav-link i {
            width: 24px;
            font-size: 1.1rem;
        }

        .sidebar .brand {
            padding: 1.2rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .sidebar .brand h5 {
            color: #fff;
            font-weight: 700;
        }

        .sidebar .brand small {
            color: rgba(255, 255, 255, 0.4);
            font-size: 0.7rem;
        }

        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .top-nav {
            background: #fff;
            padding: 0.8rem 1.5rem;
            border-bottom: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .hamburger {
            background: transparent;
            border: none;
            color: #1a2332;
            font-size: 1.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            display: none;
            cursor: pointer;
        }

        .hamburger:hover {
            background: #f0f2f5;
        }

        .close-sidebar {
            display: none;
            background: transparent;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            padding: 0.25rem 0.5rem;
            cursor: pointer;
        }

        .close-sidebar:hover {
            color: #ff6b6b;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1040;
        }

        .card-custom {
            background: #fff;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            border: 1px solid #f0f0f0;
        }

        /* Plan Cards */
        .plan-card {
            background: #fff;
            border-radius: 16px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
            padding: 1.5rem;
            position: relative;
            height: 100%;
        }

        .plan-card:hover {
            border-color: #667eea;
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.1);
            transform: translateY(-4px);
        }

        .plan-card.featured {
            border-color: #667eea;
            background: linear-gradient(135deg, #f8f9ff 0%, #fff 100%);
        }

        .plan-card .plan-badge {
            position: absolute;
            top: -12px;
            right: 20px;
            background: #667eea;
            color: #fff;
            padding: 0.2rem 1rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .plan-card .plan-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: #f0f3ff;
            color: #667eea;
        }

        .plan-card .plan-name {
            font-weight: 700;
            font-size: 1.2rem;
            color: #1a2332;
        }

        .plan-card .plan-price {
            font-weight: 700;
            font-size: 1.8rem;
            color: #1a2332;
        }

        .plan-card .plan-price small {
            font-size: 0.9rem;
            font-weight: 400;
            color: #6b7280;
        }

        .plan-card .plan-features {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .plan-card .plan-features li {
            padding: 0.4rem 0;
            font-size: 0.85rem;
            color: #4a5568;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .plan-card .plan-features li i {
            color: #667eea;
            font-size: 1rem;
        }

        .plan-card .plan-features li .text-muted {
            color: #adb5bd !important;
        }

        .current-plan-badge {
            display: inline-block;
            background: #d4edda;
            color: #155724;
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Modal styles */
        .purchase-modal .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }

        .purchase-modal .modal-header {
            border-bottom: 1px solid #e9ecef;
            padding: 1.2rem 1.5rem;
        }

        .purchase-modal .modal-body {
            padding: 1.5rem;
        }

        .purchase-modal .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 1rem 1.5rem;
        }

        /* Payment loader */
        .payment-loader {
            display: none;
            text-align: center;
            padding: 2rem;
        }

        .payment-loader .spinner-border {
            width: 3rem;
            height: 3rem;
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .hamburger {
                display: block;
            }

            .close-sidebar {
                display: block;
            }

            .overlay.active {
                display: block;
            }
        }

        @media (max-width: 576px) {
            .top-nav {
                padding: 0.6rem 1rem;
            }

            .card-custom {
                padding: 1rem;
            }
        }
    </style>
</head>

<body>

    <!-- Overlay -->
    <div class="overlay" id="overlay" onclick="closeSidebar()"></div>

    <?php include_once './templates/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">

        <!-- Top Navbar -->
        <nav class="top-nav d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <button class="hamburger" onclick="openSidebar()"><i class="bi bi-list"></i></button>
                <h5 class="mb-0 fw-bold" style="color: #1a2332;">Subscriptions</h5>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-dark px-3 py-2">
                    <i class="bi bi-building me-1"></i> <?= htmlspecialchars($company_name) ?>
                </span>
                <div class="dropdown">
                    <button class="btn btn-light btn-sm d-flex align-items-center gap-2" data-bs-toggle="dropdown" style="border-color: #e9ecef;">
                        <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 28px; height: 28px; background: #667eea; font-weight: 600; font-size: 0.75rem;">
                            <?= strtoupper(substr($company_name, 0, 1)) ?>
                        </span>
                        <span class="d-none d-sm-inline"><?= htmlspecialchars($company_name) ?></span>
                        <i class="bi bi-chevron-down d-none d-sm-inline" style="font-size: 0.7rem;"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-1" style="min-width: 160px;">
                        <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li>
                            <hr class="dropdown-divider" />
                        </li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Content -->
        <div class="p-3 p-md-4">

            <!-- Current Subscription Status -->
            <?php if ($current_subscription && $current_subscription['status'] == 'active'): ?>
                <div class="card-custom mb-4" style="border-left: 4px solid #667eea;">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div>
                            <span class="text-muted" style="font-size: 0.8rem;">Current Subscription</span>
                            <h5 class="fw-bold mb-0"><?= htmlspecialchars($current_subscription['plan_name']) ?></h5>
                            <p class="text-muted mb-0" style="font-size: 0.85rem;">
                                Expires: <?= date('d M Y', strtotime($current_subscription['expiry_date'])) ?>
                                <?php
                                $days_left = (strtotime($current_subscription['expiry_date']) - time()) / (60 * 60 * 24);
                                if ($days_left < 7 && $days_left > 0):
                                ?>
                                    <span class="badge bg-warning text-dark ms-2"><?= round($days_left) ?> days left</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="badge bg-success px-3 py-2">Active</span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Subscription Plans -->
            <div class="card-custom">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: #f0f3ff; color: #667eea; font-size: 1.4rem;">
                            <i class="bi bi-boxes"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0" style="color: #1a2332;">Subscription Plans</h5>
                            <p class="text-muted mb-0" style="font-size: 0.85rem;">Choose a plan that fits your business needs</p>
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2">
                            <i class="bi bi-database me-1"></i> <?= count($plans) ?> Active Plans
                        </span>
                    </div>
                </div>

                <?php if (empty($plans)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam" style="font-size: 3rem; color: #adb5bd;"></i>
                        <p class="text-muted mt-3">No active subscription plans available</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($plans as $plan): ?>
                            <div class="col-lg-4 col-md-6">
                                <div class="plan-card <?= $plan['is_featured'] ? 'featured' : '' ?>">
                                    <?php if ($plan['is_featured']): ?>
                                        <span class="plan-badge"><i class="bi bi-star-fill me-1"></i>Featured</span>
                                    <?php endif; ?>

                                    <?php if ($current_subscription && $current_subscription['plan_id'] == $plan['id'] && $current_subscription['status'] == 'active'): ?>
                                        <span class="plan-badge" style="background: #28a745; right: auto; left: 20px;">
                                            <i class="bi bi-check-circle-fill me-1"></i>Current Plan
                                        </span>
                                    <?php endif; ?>

                                    <div class="d-flex align-items-center gap-3 mb-3">
                                        <div class="plan-icon">
                                            <i class="bi bi-box"></i>
                                        </div>
                                        <div>
                                            <div class="plan-name"><?= htmlspecialchars($plan['plan_name']) ?></div>
                                            <div class="text-muted" style="font-size: 0.8rem;">
                                                <i class="bi bi-clock me-1"></i> <?= $plan['duration_months'] ?> Month<?= $plan['duration_months'] > 1 ? 's' : '' ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="plan-price">
                                        ₹<?= number_format($plan['price_inr'], 2) ?>
                                        <small>/ <?= $plan['duration_months'] ?> month<?= $plan['duration_months'] > 1 ? 's' : '' ?></small>
                                    </div>

                                    <p class="text-muted mt-2" style="font-size: 0.85rem;">
                                        <?= htmlspecialchars($plan['short_description'] ?? '') ?>
                                    </p>

                                    <ul class="plan-features mt-3">
                                        <li><i class="bi bi-check-circle-fill"></i> <?= $plan['job_posts_limit'] ? 'Up to ' . $plan['job_posts_limit'] . ' job posts' : 'Unlimited job posts' ?></li>
                                        <li><i class="bi bi-check-circle-fill"></i> <?= $plan['resume_views_limit'] ? $plan['resume_views_limit'] . ' resume views' : 'Unlimited resume views' ?></li>
                                        <?php if ($plan['has_custom_domain']): ?>
                                            <li><i class="bi bi-check-circle-fill"></i> Custom domain support</li>
                                        <?php endif; ?>
                                        <?php if ($plan['has_priority_support']): ?>
                                            <li><i class="bi bi-check-circle-fill"></i> Priority support</li>
                                        <?php endif; ?>
                                        <?php if ($plan['has_advanced_analytics']): ?>
                                            <li><i class="bi bi-check-circle-fill"></i> Advanced analytics</li>
                                        <?php endif; ?>
                                    </ul>

                                    <?php if ($current_subscription && $current_subscription['plan_id'] == $plan['id'] && $current_subscription['status'] == 'active'): ?>
                                        <button class="btn btn-success w-100 mt-3" disabled>
                                            <i class="bi bi-check-circle me-1"></i> Current Plan
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-primary w-100 mt-3" onclick="openPurchaseModal(<?= $plan['id'] ?>)">
                                            <i class="bi bi-cart-plus me-1"></i> Purchase Plan
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Purchase Modal -->
    <div class="modal fade purchase-modal" id="purchaseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: #f0f3ff; color: #667eea; font-size: 1.4rem;">
                            <i class="bi bi-cart-check"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold" id="purchaseModalTitle">Purchase Plan</h5>
                            <span class="text-muted" style="font-size: 0.85rem;"><?= htmlspecialchars($company_name) ?></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="purchaseForm">
                        <input type="hidden" id="selectedPlanId" name="plan_id" value="">
                        <input type="hidden" id="companyId" name="company_id" value="<?= $company_id ?>">

                        <!-- Company Info -->
                        <div class="p-3 bg-light rounded-3 mb-3">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Company</div>
                                    <div class="fw-semibold"><?= htmlspecialchars($company_name) ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Email</div>
                                    <div class="fw-semibold"><?= htmlspecialchars($company_email) ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Plan Summary -->
                        <div class="p-3 bg-primary bg-opacity-10 rounded-3 mb-3">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Plan</div>
                                    <div class="fw-semibold" id="modalPlanName">-</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Price</div>
                                    <div class="fw-semibold" id="modalPlanPrice">-</div>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Loader -->
                        <div class="payment-loader" id="paymentLoader">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 text-muted">Processing payment...</p>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="purchaseBtn" onclick="initiatePurchase()">
                        <i class="bi bi-credit-card me-1"></i> Pay Now
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Razorpay SDK -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>

    <script>
        // Sidebar functions
        function openSidebar() {
            document.getElementById('sidebar').classList.add('open');
            document.getElementById('overlay').classList.add('active');
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('overlay').classList.remove('active');
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSidebar();
        });
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) closeSidebar();
        });

        // Purchase Modal
        let purchaseModal = new bootstrap.Modal(document.getElementById('purchaseModal'));
        let selectedPlanData = null;

        function openPurchaseModal(planId) {
            // Reset form
            document.getElementById('selectedPlanId').value = planId;
            document.getElementById('paymentLoader').style.display = 'none';
            document.getElementById('purchaseBtn').disabled = false;
            document.getElementById('purchaseBtn').innerHTML = '<i class="bi bi-credit-card me-1"></i> Pay Now';

            // Get plan details
            fetch('?action=get_plan&id=' + planId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.data) {
                        selectedPlanData = data.data;
                        document.getElementById('modalPlanName').textContent = selectedPlanData.plan_name;
                        document.getElementById('modalPlanPrice').textContent = '₹' + parseFloat(selectedPlanData.price_inr).toFixed(2);
                        document.getElementById('purchaseModalTitle').textContent = 'Purchase ' + selectedPlanData.plan_name;
                        purchaseModal.show();
                    } else {
                        showToast('Error loading plan details', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An error occurred loading plan details', 'error');
                });
        }

        // Initiate purchase with Razorpay
        function initiatePurchase() {
            const planId = document.getElementById('selectedPlanId').value;
            const companyId = document.getElementById('companyId').value;

            if (!companyId) {
                showToast('Company not found', 'error');
                return;
            }

            // Show loader
            document.getElementById('paymentLoader').style.display = 'block';
            document.getElementById('purchaseBtn').disabled = true;
            document.getElementById('purchaseBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

            // Get Razorpay keys
            fetch('?action=get_keys')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data.success || !data.key_id) {
                        showToast('Razorpay keys not configured. Please contact admin.', 'error');
                        document.getElementById('paymentLoader').style.display = 'none';
                        document.getElementById('purchaseBtn').disabled = false;
                        document.getElementById('purchaseBtn').innerHTML = '<i class="bi bi-credit-card me-1"></i> Pay Now';
                        return;
                    }

                    // Create Razorpay order
                    const options = {
                        key: data.key_id,
                        amount: Math.round(selectedPlanData.price_inr * 100), // Amount in paise
                        currency: 'INR',
                        name: 'JobHub Subscription',
                        description: selectedPlanData.plan_name + ' Plan for ' + '<?= htmlspecialchars($company_name) ?>',
                        prefill: {
                            name: '<?= htmlspecialchars($company_name) ?>',
                            email: '<?= htmlspecialchars($company_email) ?>'
                        },
                        handler: function(response) {
                            // Payment successful - create subscription
                            createSubscription(
                                planId,
                                companyId,
                                response.razorpay_payment_id,
                                selectedPlanData.price_inr
                            );
                        },
                        modal: {
                            ondismiss: function() {
                                document.getElementById('paymentLoader').style.display = 'none';
                                document.getElementById('purchaseBtn').disabled = false;
                                document.getElementById('purchaseBtn').innerHTML = '<i class="bi bi-credit-card me-1"></i> Pay Now';
                            }
                        }
                    };

                    const rzp = new Razorpay(options);
                    rzp.open();
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An error occurred', 'error');
                    document.getElementById('paymentLoader').style.display = 'none';
                    document.getElementById('purchaseBtn').disabled = false;
                    document.getElementById('purchaseBtn').innerHTML = '<i class="bi bi-credit-card me-1"></i> Pay Now';
                });
        }

        // Create subscription after payment
        function createSubscription(planId, companyId, paymentId, amount) {
            const formData = new FormData();
            formData.append('plan_id', planId);
            formData.append('company_id', companyId);
            formData.append('payment_id', paymentId);
            formData.append('amount', amount);

            fetch('?action=create_subscription', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    document.getElementById('paymentLoader').style.display = 'none';
                    document.getElementById('purchaseBtn').disabled = false;
                    document.getElementById('purchaseBtn').innerHTML = '<i class="bi bi-credit-card me-1"></i> Pay Now';

                    if (data.success) {
                        showToast('Subscription created successfully!', 'success');
                        purchaseModal.hide();
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showToast(data.message || 'Error creating subscription', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An error occurred', 'error');
                    document.getElementById('paymentLoader').style.display = 'none';
                    document.getElementById('purchaseBtn').disabled = false;
                    document.getElementById('purchaseBtn').innerHTML = '<i class="bi bi-credit-card me-1"></i> Pay Now';
                });
        }

        // Toast notification
        function showToast(message, type = 'success') {
            let container = document.getElementById('toastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toastContainer';
                container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; max-width: 400px; width: 100%;';
                document.body.appendChild(container);
            }
            const toast = document.createElement('div');
            const colors = {
                success: '#48bb78',
                error: '#fc8181',
                info: '#63b3ed'
            };
            const icons = {
                success: 'bi-check-circle-fill',
                error: 'bi-exclamation-circle-fill',
                info: 'bi-info-circle-fill'
            };
            toast.style.cssText = `
        padding: 1rem 1.2rem;
        border-radius: 12px;
        color: #fff;
        font-weight: 500;
        font-size: 0.9rem;
        box-shadow: 0 12px 32px rgba(0,0,0,0.15);
        background: ${colors[type] || colors.info};
        transform: translateX(120%);
        animation: slideIn 0.4s ease forwards;
        display: flex;
        align-items: center;
        gap: 12px;
    `;
            toast.innerHTML = `
        <span><i class="bi ${icons[type] || icons.info}"></i></span>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" style="background: transparent; border: none; color: inherit; opacity: 0.7; cursor: pointer; margin-left: auto; font-size: 1.1rem;">&times;</button>
    `;
            container.appendChild(toast);
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.style.animation = 'slideOut 0.4s ease forwards';
                    setTimeout(() => {
                        if (toast.parentNode) toast.remove();
                    }, 400);
                }
            }, 4000);
        }

        // Add keyframe styles
        const styleSheet = document.createElement('style');
        styleSheet.textContent = `
    @keyframes slideIn {
        0% { transform: translateX(120%); opacity: 0; }
        100% { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
        0% { transform: translateX(0); opacity: 1; }
        100% { transform: translateX(120%); opacity: 0; }
    }
`;
        document.head.appendChild(styleSheet);
    </script>
</body>

</html>