<?php
require_once './config/config.php';
function timeAgo($timestamp)
{
    $time_diff = time() - strtotime($timestamp);

    if ($time_diff < 60) {
        return 'Just now';
    } elseif ($time_diff < 3600) {
        return floor($time_diff / 60) . 'm ago';
    } elseif ($time_diff < 86400) {
        return floor($time_diff / 3600) . 'h ago';
    } elseif ($time_diff < 604800) {
        return floor($time_diff / 86400) . 'd ago';
    } elseif ($time_diff < 2592000) {
        return floor($time_diff / 604800) . 'w ago';
    } elseif ($time_diff < 31536000) {
        return floor($time_diff / 2592000) . 'mo ago';
    } else {
        return date('M d, Y', strtotime($timestamp));
    }
}

// Check if logged in
if (!isCompanyLoggedIn() || !verifyCompanyToken($pdo)) {
    redirect('auth.php');
    exit();
}

$company = getCompanyData($pdo, $_SESSION['company_id']);
$company_name = $company['company_name'] ?? 'Company';
$company_id = $company['company_id'] ?? '';
$company_db_id = $company['id'] ?? 0;

// Get subscription plan limits
$subscription_plan = null;
$job_posts_limit = 0;
$resume_views_limit = 0;
$job_posts_used = 0;
$resume_views_used = 0;
$has_reached_job_limit = false;
$has_reached_resume_limit = false;

try {
    // Get company subscription
    $sub_stmt = $pdo->prepare("
        SELECT cs.*, sp.job_posts_limit, sp.resume_views_limit 
        FROM company_subscriptions cs
        INNER JOIN subscription_plans sp ON cs.plan_id = sp.id
        WHERE cs.company_id = ? AND cs.status = 'active' AND cs.expiry_date > NOW()
        ORDER BY cs.id DESC LIMIT 1
    ");
    $sub_stmt->execute([$company_db_id]);
    $subscription_plan = $sub_stmt->fetch();

    if ($subscription_plan) {
        $job_posts_limit = $subscription_plan['job_posts_limit'] ?? 0;
        $resume_views_limit = $subscription_plan['resume_views_limit'] ?? 0;

        // Get current usage
        $usage_stmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM jobs_post WHERE company_id = ? AND status != 'deleted') as job_count,
                (SELECT COUNT(*) FROM job_applications WHERE company_id = ?) as resume_views_count
        ");
        $usage_stmt->execute([$company_db_id, $company_db_id]);
        $usage = $usage_stmt->fetch();

        $job_posts_used = $usage['job_count'] ?? 0;
        $resume_views_used = $usage['resume_views_count'] ?? 0;

        // Check limits
        if ($job_posts_limit > 0 && $job_posts_used >= $job_posts_limit) {
            $has_reached_job_limit = true;
        }
        if ($resume_views_limit > 0 && $resume_views_used >= $resume_views_limit) {
            $has_reached_resume_limit = true;
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching subscription: " . $e->getMessage());
}

// Get dashboard statistics
$stats = [
    'total_jobs' => 0,
    'total_applications' => 0,
    'total_candidates' => 0,
    'views_today' => 0,
    'pending_applications' => 0,
    'reviewed_applications' => 0,
    'shortlisted_applications' => 0,
    'rejected_applications' => 0,
    'hired_applications' => 0,
    'job_posts_limit' => $job_posts_limit,
    'job_posts_used' => $job_posts_used,
    'resume_views_limit' => $resume_views_limit,
    'resume_views_used' => $resume_views_used,
    'has_reached_job_limit' => $has_reached_job_limit,
    'has_reached_resume_limit' => $has_reached_resume_limit
];

try {
    // Get total jobs
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM jobs_post WHERE company_id = ? AND status != 'deleted'");
    $stmt->execute([$company_db_id]);
    $stats['total_jobs'] = $stmt->fetchColumn();

    // Get total applications
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM job_applications WHERE company_id = ?");
    $stmt->execute([$company_db_id]);
    $stats['total_applications'] = $stmt->fetchColumn();

    // Get total unique candidates
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT applicant_email) as total FROM job_applications WHERE company_id = ?");
    $stmt->execute([$company_db_id]);
    $stats['total_candidates'] = $stmt->fetchColumn();

    // Get applications by status
    $statuses = ['pending', 'reviewed', 'shortlisted', 'rejected', 'hired'];
    foreach ($statuses as $status) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM job_applications WHERE company_id = ? AND status = ?");
        $stmt->execute([$company_db_id, $status]);
        $stats[$status . '_applications'] = $stmt->fetchColumn();
    }

    // Get today's views
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM jobs_post WHERE company_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$company_db_id]);
    $stats['views_today'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
}

// Get recent applications with job details
$recent_applications = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            j.job_title,
            j.location,
            j.job_type,
            c.company_name
        FROM job_applications a
        INNER JOIN jobs_post j ON a.job_id = j.id
        INNER JOIN companies c ON a.company_id = c.id
        WHERE a.company_id = ?
        ORDER BY a.applied_at DESC
        LIMIT 10
    ");
    $stmt->execute([$company_db_id]);
    $recent_applications = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching recent applications: " . $e->getMessage());
}

// Get all job postings for filter
$job_listings = [];
try {
    $stmt = $pdo->prepare("SELECT id, job_title FROM jobs_post WHERE company_id = ? AND status = 'active' ORDER BY created_at DESC");
    $stmt->execute([$company_db_id]);
    $job_listings = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching job listings: " . $e->getMessage());
}

// Handle application status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $application_id = intval($_POST['application_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';

    if ($application_id > 0 && in_array($new_status, ['pending', 'reviewed', 'shortlisted', 'rejected', 'hired'])) {
        try {
            $stmt = $pdo->prepare("UPDATE job_applications SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$new_status, $application_id, $company_db_id]);

            $_SESSION['success_message'] = 'Application status updated successfully.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (PDOException $e) {
            error_log("Error updating application status: " . $e->getMessage());
            $_SESSION['error_message'] = 'Failed to update application status.';
        }
    }
}

// Handle application deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_application'])) {
    $application_id = intval($_POST['application_id'] ?? 0);

    if ($application_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM job_applications WHERE id = ? AND company_id = ?");
            $stmt->execute([$application_id, $company_db_id]);

            $_SESSION['success_message'] = 'Application deleted successfully.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (PDOException $e) {
            error_log("Error deleting application: " . $e->getMessage());
            $_SESSION['error_message'] = 'Failed to delete application.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
   <?php require_once './templates/head.php'  ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            overflow-x: hidden;
        }

        .main-content-modern {
            margin-left: 260px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .sidebar-modern {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100%;
            background: #ffffff;
            border-right: 1px solid #e9ecef;
            overflow-y: auto;
            z-index: 1050;
            transition: all 0.3s ease;
        }

        .sidebar-modern .sidebar-brand {
            padding: 1.5rem 1.5rem 0.5rem 1.5rem;
        }

        .sidebar-modern .sidebar-brand h5 {
            font-weight: 800;
            color: #1e293b;
        }

        .sidebar-modern .sidebar-brand h5 span {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .sidebar-modern .nav-link {
            color: #64748b;
            padding: 0.7rem 1.5rem;
            border-radius: 0;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-modern .nav-link:hover {
            color: #1e293b;
            background: #f8fafc;
        }

        .sidebar-modern .nav-link.active {
            color: #4f46e5;
            background: #eef2ff;
            border-left-color: #4f46e5;
        }

        .sidebar-modern .nav-link i {
            margin-right: 12px;
            font-size: 1.1rem;
        }

        .sidebar-modern .nav-link .badge {
            float: right;
            margin-top: 2px;
            background: #4f46e5;
            color: white;
            font-weight: 600;
            font-size: 0.7rem;
            padding: 0.25rem 0.6rem;
        }

        .sidebar-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 1rem 1.5rem;
            border-top: 1px solid #e9ecef;
        }

        .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-footer .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            color: white;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        /* Top Nav */
        .top-nav-modern {
            background: #ffffff;
            padding: 0.75rem 1.5rem;
            border-bottom: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 1040;
        }

        .hamburger-modern {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #1e293b;
            padding: 0.25rem 0.5rem;
            display: none;
        }

        /* Stats Cards */
        .stat-card-modern {
            background: white;
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .stat-card-modern:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }

        .stat-icon.green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .stat-icon.orange {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .stat-icon.red {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e293b;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #94a3b8;
            font-weight: 500;
        }

        .stat-limit {
            font-size: 0.65rem;
            color: #94a3b8;
        }

        /* Status Badges */
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

        /* Overlay */
        .overlay-modern {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1045;
            display: none;
        }

        .overlay-modern.active {
            display: block;
        }

        /* Mobile Responsive */
        @media (max-width: 992px) {
            .sidebar-modern {
                transform: translateX(-100%);
            }

            .sidebar-modern.open {
                transform: translateX(0);
            }

            .main-content-modern {
                margin-left: 0;
            }

            .hamburger-modern {
                display: block;
            }
        }

        @media (max-width: 576px) {
            .top-nav-modern .form-control-sm {
                min-width: 120px !important;
            }

            .stat-card-modern {
                padding: 0.75rem 1rem;
            }

            .stat-value {
                font-size: 1.2rem;
            }
        }

        .table-responsive {
            max-height: 400px;
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

        /* Limit Reached Styles */
        .limit-warning {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
        }

        .blur-cell {
            filter: blur(4px);
            pointer-events: none;
            user-select: none;
            opacity: 0.6;
        }

        .blur-cell .btn,
        .blur-cell a {
            pointer-events: none;
            cursor: not-allowed;
        }

        .blur-overlay {
            position: relative;
        }

        .blur-overlay .lock-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 10;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            gap: 6px;
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
    </style>
</head>

<body>

    <!-- Overlay -->
    <div class="overlay-modern" id="overlay" onclick="closeSidebar()"></div>

    <!-- Sidebar -->
    <?php include './templates/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content-modern" id="mainContent">

        <!-- Top Navbar -->
        <nav class="top-nav-modern d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <button class="hamburger-modern" onclick="openSidebar()"><i class="bi bi-list"></i></button>
                <h5 class="mb-0 fw-bold" style="color: #1e293b;">Dashboard</h5>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="position-relative">
                    <input type="text" class="form-control form-control-sm rounded-pill" placeholder="Search..." style="min-width: 200px; border-color: #e9ecef; padding-left: 2.2rem; font-size: 0.85rem;">
                    <i class="bi bi-search position-absolute" style="left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                </div>
                <button class="btn btn-light btn-sm rounded-circle position-relative" style="width: 38px; height: 38px; border-color: #e9ecef;">
                    <i class="bi bi-bell"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-circle bg-danger" style="font-size: 0.55rem; padding: 0.2rem 0.35rem;"><?= $stats['pending_applications'] ?></span>
                </button>
                <div class="dropdown d-none d-sm-block">
                    <button class="btn btn-light btn-sm d-flex align-items-center gap-2" data-bs-toggle="dropdown" style="border-color: #e9ecef; padding: 0.3rem 1rem;">
                        <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 28px; height: 28px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-weight: 600; font-size: 0.75rem;">
                            <?= strtoupper(substr($company_name, 0, 1)) ?>
                        </span>
                        <span class="fw-semibold" style="font-size: 0.85rem;"><?= htmlspecialchars($company_name) ?></span>
                        <i class="bi bi-chevron-down" style="font-size: 0.7rem; color: #94a3b8;"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-1" style="min-width: 180px;">
                        <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li>
                            <hr class="dropdown-divider" />
                        </li>
                        <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Content -->
        <div class="p-3 p-md-4">

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 rounded-3" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= htmlspecialchars($_SESSION['success_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= htmlspecialchars($_SESSION['error_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Subscription Limit Warnings -->
            <?php if ($stats['has_reached_job_limit'] && $stats['job_posts_limit'] > 0): ?>
                <div class="limit-warning mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Job Post Limit Reached:</strong> You've used <?= $stats['job_posts_used'] ?> of <?= $stats['job_posts_limit'] ?> job posts.
                    </div>
                    <a href="subscription-plans.php" class="btn btn-sm btn-warning">Upgrade Plan</a>
                </div>
            <?php endif; ?>

            <?php if ($stats['has_reached_resume_limit'] && $stats['resume_views_limit'] > 0): ?>
                <div class="limit-warning mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Resume View Limit Reached:</strong> You've viewed <?= $stats['resume_views_used'] ?> of <?= $stats['resume_views_limit'] ?> resumes.
                    </div>
                    <a href="subscription-plans.php" class="btn btn-sm btn-warning">Upgrade Plan</a>
                </div>
            <?php endif; ?>

            <!-- Welcome -->
            <div class="mb-4">
                <h4 class="fw-bold mb-1" style="color: #1e293b;">Welcome, <?= htmlspecialchars($company_name) ?>! 👋</h4>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">Here's what's happening with your job postings today.</p>
            </div>

            <!-- Stats -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card-modern d-flex align-items-center gap-3">
                        <div class="stat-icon purple flex-shrink-0"><i class="bi bi-briefcase-fill"></i></div>
                        <div>
                            <div class="stat-value"><?= $stats['total_jobs'] ?></div>
                            <div class="stat-label">Total Jobs</div>
                            <?php if ($stats['job_posts_limit'] > 0): ?>
                                <div class="stat-limit">Limit: <?= $stats['job_posts_limit'] ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card-modern d-flex align-items-center gap-3">
                        <div class="stat-icon blue flex-shrink-0"><i class="bi bi-file-earmark-text-fill"></i></div>
                        <div>
                            <div class="stat-value"><?= $stats['total_applications'] ?></div>
                            <div class="stat-label">Applications</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card-modern d-flex align-items-center gap-3">
                        <div class="stat-icon green flex-shrink-0"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="stat-value"><?= $stats['total_candidates'] ?></div>
                            <div class="stat-label">Candidates</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card-modern d-flex align-items-center gap-3">
                        <div class="stat-icon <?= $stats['has_reached_resume_limit'] ? 'red' : 'orange' ?> flex-shrink-0">
                            <i class="bi bi-file-pdf-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['resume_views_used'] ?></div>
                            <div class="stat-label">Resume Views</div>
                            <?php if ($stats['resume_views_limit'] > 0): ?>
                                <div class="stat-limit">Limit: <?= $stats['resume_views_limit'] ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Application Status Summary -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-3">
                        <div class="badge bg-warning bg-opacity-10 text-warning p-3 rounded-3" style="font-weight: 500;">
                            <i class="bi bi-clock me-1"></i> Pending: <?= $stats['pending_applications'] ?>
                        </div>
                        <div class="badge bg-info bg-opacity-10 text-info p-3 rounded-3" style="font-weight: 500;">
                            <i class="bi bi-eye me-1"></i> Reviewed: <?= $stats['reviewed_applications'] ?>
                        </div>
                        <div class="badge bg-success bg-opacity-10 text-success p-3 rounded-3" style="font-weight: 500;">
                            <i class="bi bi-star me-1"></i> Shortlisted: <?= $stats['shortlisted_applications'] ?>
                        </div>
                        <div class="badge bg-danger bg-opacity-10 text-danger p-3 rounded-3" style="font-weight: 500;">
                            <i class="bi bi-x-circle me-1"></i> Rejected: <?= $stats['rejected_applications'] ?>
                        </div>
                        <div class="badge bg-emerald bg-opacity-10 text-emerald p-3 rounded-3" style="font-weight: 500; background: #d1fae5; color: #065f46;">
                            <i class="bi bi-check-circle me-1"></i> Hired: <?= $stats['hired_applications'] ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Applications -->
            <div class="row">
                <div class="col-12">
                    <div class="card border-0 shadow-sm rounded-3 bg-white">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                <h6 class="fw-bold mb-0" style="color: #1e293b;">
                                    <i class="bi bi-file-earmark-text me-2"></i>Recent Applications
                                </h6>
                                <div class="d-flex gap-2">
                                    <select class="form-select form-select-sm" style="width: auto; border-color: #e9ecef; font-size: 0.8rem;">
                                        <option value="">All Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="reviewed">Reviewed</option>
                                        <option value="shortlisted">Shortlisted</option>
                                        <option value="rejected">Rejected</option>
                                        <option value="hired">Hired</option>
                                    </select>
                                    <select class="form-select form-select-sm" style="width: auto; border-color: #e9ecef; font-size: 0.8rem;">
                                        <option value="">All Jobs</option>
                                        <?php foreach ($job_listings as $job): ?>
                                            <option value="<?= $job['id'] ?>"><?= htmlspecialchars($job['job_title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <?php if (empty($recent_applications)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-inbox" style="font-size: 3rem; color: #d1d5db;"></i>
                                    <h6 class="mt-3 text-muted">No applications yet</h6>
                                    <p class="text-muted small">When candidates apply, they'll appear here.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                        <thead>
                                            <tr style="background: #f8fafc;">
                                                <th>Applicant</th>
                                                <th>Position</th>
                                                <th>Experience</th>
                                                <th>Status</th>
                                                <th>Applied</th>
                                                <th>Resume</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $row_count = 0;
                                            foreach ($recent_applications as $app):
                                                $row_count++;
                                                $is_locked = $stats['has_reached_resume_limit'] && $row_count > $stats['resume_views_limit'];
                                                $status_class = $app['status'];
                                                $status_label = ucfirst($app['status']);
                                                $initials = strtoupper(substr($app['applicant_name'], 0, 2));
                                                $colors = ['primary', 'warning', 'success', 'danger', 'info', 'secondary'];
                                                $color = $colors[abs(crc32($app['applicant_name'])) % count($colors)];
                                            ?>
                                                <tr class="<?= $is_locked ? 'tr-locked' : '' ?>" data-locked="<?= $is_locked ? 'true' : 'false' ?>">
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <div class="rounded-circle d-flex align-items-center justify-content-center"
                                                                style="width: 32px; height: 32px; background: <?= $color === 'primary' ? '#eef2ff' : ($color === 'warning' ? '#fef3c7' : ($color === 'success' ? '#d1fae5' : ($color === 'danger' ? '#fecaca' : '#dbeafe'))) ?>; 
                                                                        color: <?= $color === 'primary' ? '#4f46e5' : ($color === 'warning' ? '#92400e' : ($color === 'success' ? '#065f46' : ($color === 'danger' ? '#991b1b' : '#1e40af'))) ?>; 
                                                                        font-weight: 600; font-size: 0.7rem;">
                                                                <?= $initials ?>
                                                            </div>
                                                            <div>
                                                                <div class="fw-semibold" style="font-size: 0.85rem; color: #1e293b;"><?= htmlspecialchars($app['applicant_name']) ?></div>
                                                                <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($app['applicant_email']) ?></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold" style="font-size: 0.8rem; color: #1e293b;"><?= htmlspecialchars($app['job_title']) ?></div>
                                                        <div class="text-muted" style="font-size: 0.7rem;">
                                                            <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($app['location'] ?? 'N/A') ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($app['experience_years'] > 0): ?>
                                                            <span class="badge bg-secondary bg-opacity-10 text-secondary">
                                                                <?= $app['experience_years'] ?>+ years
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary bg-opacity-10 text-secondary">Fresher</span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($app['current_company'])): ?>
                                                            <div class="text-muted" style="font-size: 0.65rem;">at <?= htmlspecialchars($app['current_company']) ?></div>
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
                                                            <?= date('M d, Y', strtotime($app['applied_at'])) ?>
                                                        </div>
                                                        <div style="font-size: 0.65rem; color: #94a3b8;">
                                                            <?= timeAgo($app['applied_at']) ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($app['resume_file'])): ?>
                                                            <?php if ($is_locked || $stats['has_reached_resume_limit']): ?>
                                                                <span class="badge bg-secondary bg-opacity-25 text-secondary" style="cursor: not-allowed;">
                                                                    <i class="bi bi-lock me-1"></i> Locked
                                                                </span>
                                                            <?php else: ?>
                                                                <a href="<?= htmlspecialchars($app['resume_file']) ?>" target="_blank"
                                                                    class="btn btn-sm btn-outline-primary"
                                                                    style="padding: 0.15rem 0.5rem; font-size: 0.7rem;"
                                                                    title="View Resume">
                                                                    <i class="bi bi-file-pdf"></i> View
                                                                </a>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted" style="font-size: 0.7rem;">No resume</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex gap-1">
                                                            <button class="btn btn-sm btn-outline-secondary" style="padding: 0.15rem 0.5rem; font-size: 0.7rem;"
                                                                data-bs-toggle="modal" data-bs-target="#statusModal<?= $app['id'] ?>" title="Update Status">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>

                                                            <button class="btn btn-sm btn-outline-danger" style="padding: 0.15rem 0.5rem; font-size: 0.7rem;"
                                                                onclick="confirmDelete(<?= $app['id'] ?>)" title="Delete">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>

                                                <!-- Status Update Modal -->
                                                <div class="modal fade" id="statusModal<?= $app['id'] ?>" tabindex="-1">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content border-0 shadow-lg rounded-3">
                                                            <div class="modal-header border-0">
                                                                <h6 class="modal-title fw-bold">Update Application Status</h6>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form method="POST">
                                                                <div class="modal-body">
                                                                    <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-semibold" style="font-size: 0.85rem;">Applicant: <?= htmlspecialchars($app['applicant_name']) ?></label>
                                                                        <p class="text-muted small">Position: <?= htmlspecialchars($app['job_title']) ?></p>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label" style="font-size: 0.85rem;">New Status</label>
                                                                        <select name="new_status" class="form-select" required>
                                                                            <option value="pending" <?= $app['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                                            <option value="reviewed" <?= $app['status'] == 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                                                                            <option value="shortlisted" <?= $app['status'] == 'shortlisted' ? 'selected' : '' ?>>Shortlisted</option>
                                                                            <option value="rejected" <?= $app['status'] == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                                                            <option value="hired" <?= $app['status'] == 'hired' ? 'selected' : '' ?>>Hired</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer border-0">
                                                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <?php if (count($recent_applications) >= 10): ?>
                                <div class="text-center mt-3">
                                    <a href="#" class="text-decoration-none small">View All Applications <i class="bi bi-arrow-right"></i></a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="text-center text-muted mt-4 pt-3 border-top" style="font-size: 0.75rem; border-color: #e9ecef !important;">
                &copy; <?= date('Y') ?> JobHub · Company Dashboard
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-3">
                <div class="modal-header border-0">
                    <h6 class="modal-title fw-bold text-danger">Confirm Delete</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this application? This action cannot be undone.</p>
                </div>
                <div class="modal-footer border-0">
                    <form method="POST" id="deleteForm">
                        <input type="hidden" name="application_id" id="deleteApplicationId">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_application" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function openSidebar() {
            document.getElementById('sidebar').classList.add('open');
            document.getElementById('overlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('overlay').classList.remove('active');
            document.body.style.overflow = '';
        }

        function confirmDelete(applicationId) {
            document.getElementById('deleteApplicationId').value = applicationId;
            var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSidebar();
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) {
                closeSidebar();
                document.body.style.overflow = '';
            }
        });
    </script>

</body>

</html>