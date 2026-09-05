<?php
require_once './config/config.php';

// Check if logged in as company
if (!isCompanyLoggedIn()) {
    header("Location: " . APP_URL . "auth.php");
    exit();
}

// Get company details from session FIRST
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

// Handle AJAX requests for candidate details
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Get candidate details
    if ($_GET['action'] === 'get_candidate') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    a.*,
                    j.job_title,
                    j.location as job_location,
                    j.job_type
                FROM job_applications a
                INNER JOIN jobs_post j ON a.job_id = j.id
                WHERE a.id = ? AND a.company_id = ?
            ");
            $stmt->execute([$id, $company_id]);
            $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $candidate]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
}

// Get company's plan and subscription limits
$plan_id = $_SESSION['plan_id'] ?? 0;
$resume_views_limit = 0;
$current_plan_name = 'No Plan';
$resume_views_used = 0;
$has_reached_resume_limit = false;

// Get subscription plan limits
if ($plan_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT plan_name, resume_views_limit FROM subscription_plans WHERE id = ? AND is_active = 1");
        $stmt->execute([$plan_id]);
        $plan = $stmt->fetch();
        if ($plan) {
            $resume_views_limit = intval($plan['resume_views_limit'] ?? 0);
            $current_plan_name = $plan['plan_name'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching plan: " . $e->getMessage());
    }
}

// If no plan found in companies table, check company_subscriptions
if ($plan_id == 0 || $resume_views_limit == 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT cs.plan_id, cs.plan_name, sp.resume_views_limit 
            FROM company_subscriptions cs
            JOIN subscription_plans sp ON cs.plan_id = sp.id
            WHERE cs.company_id = ? AND cs.status = 'active' 
            AND cs.expiry_date > NOW()
            ORDER BY cs.created_at DESC LIMIT 1
        ");
        $stmt->execute([$company_id]);
        $subscription = $stmt->fetch();
        if ($subscription) {
            $plan_id = $subscription['plan_id'];
            $resume_views_limit = intval($subscription['resume_views_limit'] ?? 0);
            $current_plan_name = $subscription['plan_name'];
            $_SESSION['plan_id'] = $plan_id;
        }
    } catch (PDOException $e) {
        error_log("Error fetching subscription: " . $e->getMessage());
    }
}

// Get resume views used (total applications received)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM job_applications WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $resume_views_used = $stmt->fetchColumn() ?: 0;
    
    // Check if limit reached
    if ($resume_views_limit > 0 && $resume_views_used >= $resume_views_limit) {
        $has_reached_resume_limit = true;
    }
} catch (PDOException $e) {
    $resume_views_used = 0;
    error_log("Error counting applications: " . $e->getMessage());
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$job_filter = isset($_GET['job']) ? intval($_GET['job']) : 0;
$search_filter = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query for candidates
$where_conditions = ["a.company_id = ?"];
$params = [$company_id];

if (!empty($status_filter)) {
    $where_conditions[] = "a.status = ?";
    $params[] = $status_filter;
}

if ($job_filter > 0) {
    $where_conditions[] = "a.job_id = ?";
    $params[] = $job_filter;
}

if (!empty($search_filter)) {
    $where_conditions[] = "(a.applicant_name LIKE ? OR a.applicant_email LIKE ? OR a.current_company LIKE ? OR a.current_position LIKE ?)";
    $search_param = '%' . $search_filter . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = implode(" AND ", $where_conditions);

// DEBUG - Check what's in the database
try {
    $debug_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM job_applications WHERE company_id = ?");
    $debug_stmt->execute([$company_id]);
    $debug_total = $debug_stmt->fetchColumn();
    error_log("Total applications for company $company_id: " . $debug_total);
} catch (PDOException $e) {
    error_log("Debug error: " . $e->getMessage());
}

// Get total count for pagination
try {
    $count_sql = "
        SELECT COUNT(*) as total 
        FROM job_applications a
        WHERE $where_clause
    ";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_candidates = $count_stmt->fetchColumn() ?: 0;
    $total_pages = ceil($total_candidates / $per_page);
} catch (PDOException $e) {
    error_log("Error counting candidates: " . $e->getMessage());
    $total_candidates = 0;
    $total_pages = 0;
}

// Get candidates with their applications - FIXED: Use simpler query first
try {
    // First, get all applications for this company without pagination to debug
    $debug_apps_stmt = $pdo->prepare("
        SELECT a.*, j.job_title, j.location as job_location, j.job_type
        FROM job_applications a
        INNER JOIN jobs_post j ON a.job_id = j.id
        WHERE a.company_id = ?
        ORDER BY a.applied_at DESC
    ");
    $debug_apps_stmt->execute([$company_id]);
    $debug_apps = $debug_apps_stmt->fetchAll();
    error_log("Found " . count($debug_apps) . " applications for company $company_id");

    // Now get paginated results
    $sql = "
        SELECT 
            a.*,
            j.job_title,
            j.location as job_location,
            j.job_type,
            c.company_name
        FROM job_applications a
        INNER JOIN jobs_post j ON a.job_id = j.id
        INNER JOIN companies c ON a.company_id = c.id
        WHERE $where_clause
        ORDER BY a.applied_at DESC
        LIMIT " . intval($per_page) . " OFFSET " . intval($offset) . "
    ";
    
    // Rebuild params without the LIMIT/OFFSET as they're in the query string
    $params_for_query = [];
    foreach ($params as $p) {
        $params_for_query[] = $p;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_for_query);
    $candidates = $stmt->fetchAll();
    error_log("Fetched " . count($candidates) . " candidates with pagination");

} catch (PDOException $e) {
    $candidates = [];
    error_log("Error fetching candidates: " . $e->getMessage());
}

// If still no candidates, try a simpler query
if (empty($candidates) && $total_candidates > 0) {
    try {
        error_log("Trying simpler query...");
        $simple_sql = "
            SELECT 
                a.*,
                j.job_title,
                j.location as job_location,
                j.job_type,
                c.company_name
            FROM job_applications a
            INNER JOIN jobs_post j ON a.job_id = j.id
            INNER JOIN companies c ON a.company_id = c.id
            WHERE a.company_id = ?
            ORDER BY a.applied_at DESC
            LIMIT 20
        ";
        $simple_stmt = $pdo->prepare($simple_sql);
        $simple_stmt->execute([$company_id]);
        $candidates = $simple_stmt->fetchAll();
        error_log("Simple query fetched " . count($candidates) . " candidates");
        $total_candidates = count($candidates);
        $total_pages = 1;
    } catch (PDOException $e) {
        error_log("Simple query error: " . $e->getMessage());
    }
}

// Get all job postings for filter
$job_listings = [];
try {
    $stmt = $pdo->prepare("SELECT id, job_title FROM jobs_post WHERE company_id = ? AND status = 'active' ORDER BY created_at DESC");
    $stmt->execute([$company_id]);
    $job_listings = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching job listings: " . $e->getMessage());
}

// Get statistics
$stats = [
    'total_candidates' => $total_candidates,
    'pending' => 0,
    'reviewed' => 0,
    'shortlisted' => 0,
    'rejected' => 0,
    'hired' => 0
];

try {
    $statuses = ['pending', 'reviewed', 'shortlisted', 'rejected', 'hired'];
    foreach ($statuses as $status) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM job_applications WHERE company_id = ? AND status = ?");
        $stmt->execute([$company_id, $status]);
        $stats[$status] = $stmt->fetchColumn() ?: 0;
    }
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
}

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    $classes = [
        'pending' => 'pending',
        'reviewed' => 'reviewed',
        'shortlisted' => 'shortlisted',
        'rejected' => 'rejected',
        'hired' => 'hired'
    ];
    return $classes[$status] ?? 'pending';
}

// Helper function for time ago
function timeAgo($timestamp) {
    $time_diff = time() - strtotime($timestamp);
    if ($time_diff < 60) return 'Just now';
    if ($time_diff < 3600) return floor($time_diff / 60) . 'm ago';
    if ($time_diff < 86400) return floor($time_diff / 3600) . 'h ago';
    if ($time_diff < 604800) return floor($time_diff / 86400) . 'd ago';
    if ($time_diff < 2592000) return floor($time_diff / 604800) . 'w ago';
    return date('M d, Y', strtotime($timestamp));
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

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.reviewed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-badge.shortlisted {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.rejected {
            background: #fecaca;
            color: #991b1b;
        }

        .status-badge.hired {
            background: #a7f3d0;
            color: #065f46;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1rem 1.2rem;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: white;
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .stat-icon.orange {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        .stat-icon.green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        .stat-icon.blue {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
        .stat-icon.red {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1a2332;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 500;
        }

        .candidate-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            color: white;
            flex-shrink: 0;
        }

        .filter-section {
            background: white;
            padding: 16px;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }

        .tr-locked {
            opacity: 0.5;
            pointer-events: none;
            position: relative;
        }

        .tr-locked::after {
            content: '🔒 Limit Reached';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            z-index: 5;
        }

        .limit-warning {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
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
            
            .stat-card {
                padding: 0.75rem 1rem;
            }
            
            .stat-value {
                font-size: 1rem;
            }
        }

        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }

        .table-responsive::-webkit-scrollbar {
            width: 6px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .pagination .page-link {
            color: #667eea;
        }

        .pagination .page-item.active .page-link {
            background: #667eea;
            border-color: #667eea;
            color: white;
        }

        .pagination .page-item.disabled .page-link {
            color: #adb5bd;
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
                <h5 class="mb-0 fw-bold" style="color: #1a2332;">Candidates</h5>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-dark px-3 py-2">
                    <i class="bi bi-building me-1"></i> <?= htmlspecialchars($company_name) ?>
                </span>
                <span class="badge bg-primary px-3 py-2">
                    <i class="bi bi-box me-1"></i> <?= htmlspecialchars($current_plan_name) ?>
                </span>
                <span class="badge bg-info text-dark px-3 py-2">
                    <i class="bi bi-file-text me-1"></i> <?= $resume_views_used ?> / <?= $resume_views_limit > 0 ? $resume_views_limit : '∞' ?> Resumes
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

            <!-- Resume Limit Warning -->
            <?php if ($has_reached_resume_limit && $resume_views_limit > 0): ?>
                <div class="limit-warning mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Resume View Limit Reached:</strong> You've viewed <?= $resume_views_used ?> of <?= $resume_views_limit ?> resumes.
                    </div>
                    <a href="subscription-plans.php" class="btn btn-sm btn-warning">Upgrade Plan</a>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card d-flex align-items-center gap-3">
                        <div class="stat-icon purple"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="stat-value"><?= $total_candidates ?></div>
                            <div class="stat-label">Total Candidates</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card d-flex align-items-center gap-3">
                        <div class="stat-icon orange"><i class="bi bi-clock"></i></div>
                        <div>
                            <div class="stat-value"><?= $stats['pending'] ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card d-flex align-items-center gap-3">
                        <div class="stat-icon green"><i class="bi bi-star"></i></div>
                        <div>
                            <div class="stat-value"><?= $stats['shortlisted'] ?></div>
                            <div class="stat-label">Shortlisted</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card d-flex align-items-center gap-3">
                        <div class="stat-icon <?= $has_reached_resume_limit ? 'red' : 'blue' ?>">
                            <i class="bi bi-file-pdf"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $resume_views_used ?></div>
                            <div class="stat-label">
                                Resumes Viewed
                                <?php if ($resume_views_limit > 0): ?>
                                    <span class="text-muted" style="font-size: 0.6rem;">(Limit: <?= $resume_views_limit ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section mb-4">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label fw-semibold" style="font-size: 0.8rem;">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm" 
                               placeholder="Search by name, email, company..." 
                               value="<?= htmlspecialchars($search_filter) ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fw-semibold" style="font-size: 0.8rem;">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="reviewed" <?= $status_filter == 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                            <option value="shortlisted" <?= $status_filter == 'shortlisted' ? 'selected' : '' ?>>Shortlisted</option>
                            <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="hired" <?= $status_filter == 'hired' ? 'selected' : '' ?>>Hired</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold" style="font-size: 0.8rem;">Job</label>
                        <select name="job" class="form-select form-select-sm">
                            <option value="">All Jobs</option>
                            <?php foreach ($job_listings as $job): ?>
                                <option value="<?= $job['id'] ?>" <?= $job_filter == $job['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($job['job_title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-search me-1"></i> Filter
                            </button>
                            <a href="candidates.php" class="btn btn-light btn-sm border">
                                <i class="bi bi-x-circle me-1"></i> Clear
                            </a>
                            <span class="ms-auto text-muted small align-self-center">
                                Showing <?= count($candidates) ?> of <?= $total_candidates ?> candidates
                            </span>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Candidates Table -->
            <div class="card-custom">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: #f0f3ff; color: #667eea; font-size: 1.4rem;">
                            <i class="bi bi-people"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0" style="color: #1a2332;">Candidate List</h5>
                            <p class="text-muted mb-0" style="font-size: 0.85rem;">All candidates who applied to your jobs</p>
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2">
                            <i class="bi bi-database me-1"></i> <?= $total_candidates ?> Candidates
                        </span>
                    </div>
                </div>

                <?php if (empty($candidates)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 3rem; color: #adb5bd;"></i>
                        <p class="text-muted mt-3">No candidates found</p>
                        <p class="text-muted small">When candidates apply to your jobs, they'll appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" style="width: 100%; font-size: 0.85rem;">
                            <thead>
                                <tr style="background: #f8fafc;">
                                    <th>#</th>
                                    <th>Candidate</th>
                                    <th>Position Applied</th>
                                    <th>Experience</th>
                                    <th>Status</th>
                                    <th>Applied</th>
                                    <th>Resume</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $row_count = 0;
                                foreach ($candidates as $candidate): 
                                    $row_count++;
                                    $is_locked = $has_reached_resume_limit && $row_count > $resume_views_limit;
                                    $status_class = getStatusBadgeClass($candidate['status']);
                                    $status_label = ucfirst($candidate['status']);
                                    $initials = strtoupper(substr($candidate['applicant_name'], 0, 2));
                                    $colors = ['#667eea', '#f59e0b', '#10b981', '#ef4444', '#3b82f6', '#8b5cf6'];
                                    $color = $colors[abs(crc32($candidate['applicant_name'])) % count($colors)];
                                    
                                    // Get experience display
                                    $exp_display = 'Fresher';
                                    if ($candidate['experience_years'] > 0) {
                                        $exp_display = $candidate['experience_years'] . '+ years';
                                    }
                                    if (!empty($candidate['current_company'])) {
                                        $exp_display .= ' at ' . htmlspecialchars($candidate['current_company']);
                                    }
                                ?>
                                    <tr class="<?= $is_locked ? 'tr-locked' : '' ?>" data-locked="<?= $is_locked ? 'true' : 'false' ?>">
                                        <td><?= $row_count ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="candidate-avatar" style="background: <?= $color ?>;">
                                                    <?= $initials ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold" style="font-size: 0.85rem;"><?= htmlspecialchars($candidate['applicant_name']) ?></div>
                                                    <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($candidate['applicant_email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold" style="font-size: 0.8rem;"><?= htmlspecialchars($candidate['job_title']) ?></div>
                                            <div class="text-muted" style="font-size: 0.7rem;">
                                                <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($candidate['job_location'] ?? 'N/A') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.8rem;"><?= $exp_display ?></span>
                                            <?php if (!empty($candidate['current_position'])): ?>
                                                <div class="text-muted" style="font-size: 0.65rem;">
                                                    <i class="bi bi-briefcase"></i> <?= htmlspecialchars($candidate['current_position']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $status_class ?>">
                                                <?php if ($status_class == 'pending'): ?>
                                                    <i class="bi bi-clock me-1"></i>
                                                <?php elseif ($status_class == 'reviewed'): ?>
                                                    <i class="bi bi-eye me-1"></i>
                                                <?php elseif ($status_class == 'shortlisted'): ?>
                                                    <i class="bi bi-star me-1"></i>
                                                <?php elseif ($status_class == 'rejected'): ?>
                                                    <i class="bi bi-x-circle me-1"></i>
                                                <?php elseif ($status_class == 'hired'): ?>
                                                    <i class="bi bi-check-circle me-1"></i>
                                                <?php endif; ?>
                                                <?= $status_label ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.75rem; color: #64748b;">
                                                <?= date('M d, Y', strtotime($candidate['applied_at'])) ?>
                                            </div>
                                            <div style="font-size: 0.65rem; color: #94a3b8;">
                                                <?= timeAgo($candidate['applied_at']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($candidate['resume_file'])): ?>
                                                <?php if ($is_locked || $has_reached_resume_limit): ?>
                                                    <span class="badge bg-secondary bg-opacity-25 text-secondary" style="cursor: not-allowed;">
                                                        <i class="bi bi-lock me-1"></i> Locked
                                                    </span>
                                                <?php else: ?>
                                                    <a href="<?= htmlspecialchars($candidate['resume_file']) ?>" target="_blank" 
                                                       class="btn btn-sm btn-outline-primary" 
                                                       style="padding: 0.15rem 0.6rem; font-size: 0.7rem;" 
                                                       title="View Resume">
                                                        <i class="bi bi-file-pdf"></i> View
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size: 0.7rem;">No resume</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-secondary" style="padding: 0.15rem 0.5rem; font-size: 0.7rem;" 
                                                    title="View Details" onclick="viewCandidate(<?= $candidate['id'] ?>)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav class="mt-4">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page-1 ?>&status=<?= urlencode($status_filter) ?>&job=<?= $job_filter ?>&search=<?= urlencode($search_filter) ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link"><i class="bi bi-chevron-left"></i></span>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>&job=<?= $job_filter ?>&search=<?= urlencode($search_filter) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page+1 ?>&status=<?= urlencode($status_filter) ?>&job=<?= $job_filter ?>&search=<?= urlencode($search_filter) ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link"><i class="bi bi-chevron-right"></i></span>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Candidate Detail Modal -->
    <div class="modal fade" id="candidateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">
                <div class="modal-header" style="border-bottom: 1px solid #e9ecef; padding: 1.2rem 1.5rem;">
                    <div class="d-flex align-items-center gap-3">
                        <div class="candidate-avatar" id="modalAvatar" style="width: 48px; height: 48px; font-size: 1.2rem;">JD</div>
                        <div>
                            <h5 class="modal-title fw-bold" id="modalCandidateName">Candidate Name</h5>
                            <span class="text-muted" style="font-size: 0.85rem;" id="modalCandidateEmail">email@example.com</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding: 1.5rem;" id="modalCandidateContent">
                    <!-- Content loaded via JS -->
                </div>
                <div class="modal-footer" style="border-top: 1px solid #e9ecef; padding: 1rem 1.5rem;">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

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

        // View Candidate Details
        let candidateModal = new bootstrap.Modal(document.getElementById('candidateModal'));

        function viewCandidate(id) {
            fetch('?action=get_candidate&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        const c = data.data;
                        
                        document.getElementById('modalCandidateName').textContent = c.applicant_name || 'N/A';
                        document.getElementById('modalCandidateEmail').textContent = c.applicant_email || 'N/A';
                        document.getElementById('modalAvatar').textContent = c.applicant_name ? c.applicant_name.substring(0, 2).toUpperCase() : 'NA';
                        
                        const statusClass = c.status || 'pending';
                        const statusLabel = c.status ? c.status.charAt(0).toUpperCase() + c.status.slice(1) : 'Pending';
                        const expYears = c.experience_years > 0 ? c.experience_years + ' years' : 'Fresher';
                        
                        document.getElementById('modalCandidateContent').innerHTML = `
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Phone</div>
                                    <div class="fw-semibold">${c.applicant_phone || 'N/A'}</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Applied For</div>
                                    <div class="fw-semibold">${c.job_title || 'N/A'}</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Current Position</div>
                                    <div class="fw-semibold">${c.current_position || 'N/A'}</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Current Company</div>
                                    <div class="fw-semibold">${c.current_company || 'N/A'}</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Experience</div>
                                    <div class="fw-semibold">${expYears}</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Status</div>
                                    <div class="fw-semibold"><span class="status-badge ${statusClass}">${statusLabel}</span></div>
                                </div>
                                ${c.cover_letter ? `
                                <div class="col-12">
                                    <div class="text-muted" style="font-size: 0.75rem;">Cover Letter</div>
                                    <div class="fw-semibold" style="white-space: pre-wrap; background: #f8fafc; padding: 12px; border-radius: 8px; font-size: 0.9rem;">${c.cover_letter}</div>
                                </div>
                                ` : ''}
                                <div class="col-md-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Applied On</div>
                                    <div class="fw-semibold">${new Date(c.applied_at).toLocaleString()}</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="text-muted" style="font-size: 0.75rem;">Resume</div>
                                    <div class="fw-semibold">
                                        ${c.resume_file ? `<a href="${c.resume_file}" target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-file-pdf me-1"></i> View Resume</a>` : 'No resume uploaded'}
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        candidateModal.show();
                    } else {
                        showToast('Error loading candidate details', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An error occurred while loading details', 'error');
                });
        }

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