<?php
require_once './config/config.php';

// Check if logged in as company
if (!isCompanyLoggedIn()) {
     header("Location: " . APP_URL . "auth.php");
    exit();
}

// Get company details from session
$company_id = $_SESSION['company_id'] ?? 0;
$company_name = $_SESSION['company_name'] ?? 'Company';
$company_email = $_SESSION['email'] ?? '';

// If company_id not in session, fetch from database
if (!$company_id) {
    $stmt = $pdo->prepare("SELECT id, company_name, email, plan_id FROM companies WHERE email = ?");
    $stmt->execute([$_SESSION['email']]);
    $company = $stmt->fetch();
    if ($company) {
        $company_id = $company['id'];
        $company_name = $company['company_name'];
        $company_email = $company['email'];
        $company_plan_id = $company['plan_id'];
        $_SESSION['company_id'] = $company_id;
        $_SESSION['company_name'] = $company_name;
        $_SESSION['plan_id'] = $company_plan_id;
    }
}

// Get company's plan and subscription limits from the subscription plan
$plan_id = $_SESSION['plan_id'] ?? 0;
$job_posts_limit = 0;
$current_plan_name = 'No Plan';

// First check if company has plan_id in companies table
if ($plan_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT plan_name, job_posts_limit FROM subscription_plans WHERE id = ? AND is_active = 1");
        $stmt->execute([$plan_id]);
        $plan = $stmt->fetch();
        if ($plan) {
            $job_posts_limit = intval($plan['job_posts_limit'] ?? 0);
            $current_plan_name = $plan['plan_name'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching plan: " . $e->getMessage());
    }
}

// If no plan found in companies table, check company_subscriptions for active subscription
if ($plan_id == 0 || $job_posts_limit == 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT cs.plan_id, cs.plan_name, sp.job_posts_limit 
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
            $job_posts_limit = intval($subscription['job_posts_limit'] ?? 0);
            $current_plan_name = $subscription['plan_name'];
            // Update company table with plan_id
            $stmt = $pdo->prepare("UPDATE companies SET plan_id = ? WHERE id = ?");
            $stmt->execute([$plan_id, $company_id]);
            $_SESSION['plan_id'] = $plan_id;
        }
    } catch (PDOException $e) {
        error_log("Error fetching subscription: " . $e->getMessage());
    }
}

// Get total job posts count for this company - using jobs_post table
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM jobs_post WHERE company_id = ? AND status != 'deleted'");
    $stmt->execute([$company_id]);
    $total_jobs = $stmt->fetch()['total'] ?? 0;
} catch (PDOException $e) {
    $total_jobs = 0;
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Add new job
    if ($_GET['action'] === 'add_job') {
        $job_title = isset($_POST['job_title']) ? trim($_POST['job_title']) : '';
        $job_type = isset($_POST['job_type']) ? $_POST['job_type'] : '';
        $experience_level = isset($_POST['experience_level']) ? $_POST['experience_level'] : '';
        $experience_min = isset($_POST['experience_min']) ? intval($_POST['experience_min']) : 0;
        $experience_max = isset($_POST['experience_max']) ? intval($_POST['experience_max']) : 0;
        $salary_min = isset($_POST['salary_min']) ? floatval($_POST['salary_min']) : 0;
        $salary_max = isset($_POST['salary_max']) ? floatval($_POST['salary_max']) : 0;
        $location = isset($_POST['location']) ? trim($_POST['location']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $requirements = isset($_POST['requirements']) ? trim($_POST['requirements']) : '';
        $skills = isset($_POST['skills']) ? trim($_POST['skills']) : '';
        $benefits = isset($_POST['benefits']) ? trim($_POST['benefits']) : '';
        $application_deadline = isset($_POST['application_deadline']) ? $_POST['application_deadline'] : '';
        $status = isset($_POST['status']) ? $_POST['status'] : 'active';

        // Validate required fields
        if (empty($job_title) || empty($job_type) || empty($location) || empty($description)) {
            echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
            exit();
        }

        // Check job post limit
        if ($job_posts_limit > 0 && $total_jobs >= $job_posts_limit) {
            echo json_encode([
                'success' => false,
                'message' => 'You have reached the maximum job posts limit (' . $job_posts_limit . ' jobs) for your ' . $current_plan_name . ' plan. Please upgrade your plan to post more jobs.'
            ]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO jobs_post (
                    company_id, job_title, job_type, experience_level,
                    experience_min, experience_max, salary_min, salary_max,
                    location, description, requirements, skills, benefits,
                    application_deadline, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $company_id,
                $job_title,
                $job_type,
                $experience_level,
                $experience_min,
                $experience_max,
                $salary_min,
                $salary_max,
                $location,
                $description,
                $requirements,
                $skills,
                $benefits,
                $application_deadline,
                $status
            ]);

            $job_id = $pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Job posted successfully',
                'job_id' => $job_id
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // Get job details for editing
    if ($_GET['action'] === 'get_job') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        try {
            $stmt = $pdo->prepare("SELECT * FROM jobs_post WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $job]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // Update job
    if ($_GET['action'] === 'update_job') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $job_title = isset($_POST['job_title']) ? trim($_POST['job_title']) : '';
        $job_type = isset($_POST['job_type']) ? $_POST['job_type'] : '';
        $experience_level = isset($_POST['experience_level']) ? $_POST['experience_level'] : '';
        $experience_min = isset($_POST['experience_min']) ? intval($_POST['experience_min']) : 0;
        $experience_max = isset($_POST['experience_max']) ? intval($_POST['experience_max']) : 0;
        $salary_min = isset($_POST['salary_min']) ? floatval($_POST['salary_min']) : 0;
        $salary_max = isset($_POST['salary_max']) ? floatval($_POST['salary_max']) : 0;
        $location = isset($_POST['location']) ? trim($_POST['location']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $requirements = isset($_POST['requirements']) ? trim($_POST['requirements']) : '';
        $skills = isset($_POST['skills']) ? trim($_POST['skills']) : '';
        $benefits = isset($_POST['benefits']) ? trim($_POST['benefits']) : '';
        $application_deadline = isset($_POST['application_deadline']) ? $_POST['application_deadline'] : '';
        $status = isset($_POST['status']) ? $_POST['status'] : 'active';

        if (empty($job_title) || empty($job_type) || empty($location) || empty($description)) {
            echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE jobs_post SET
                    job_title = ?, job_type = ?, experience_level = ?,
                    experience_min = ?, experience_max = ?, salary_min = ?, salary_max = ?,
                    location = ?, description = ?, requirements = ?, skills = ?, benefits = ?,
                    application_deadline = ?, status = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");

            $stmt->execute([
                $job_title,
                $job_type,
                $experience_level,
                $experience_min,
                $experience_max,
                $salary_min,
                $salary_max,
                $location,
                $description,
                $requirements,
                $skills,
                $benefits,
                $application_deadline,
                $status,
                $id,
                $company_id
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Job updated successfully'
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // Delete job
    if ($_GET['action'] === 'delete_job') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        try {
            $stmt = $pdo->prepare("UPDATE jobs_post SET status = 'deleted', updated_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);
            echo json_encode(['success' => true, 'message' => 'Job deleted successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // Update job status
    if ($_GET['action'] === 'update_status') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $status = isset($_POST['status']) ? $_POST['status'] : '';
        try {
            $stmt = $pdo->prepare("UPDATE jobs_post SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$status, $id, $company_id]);
            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
}

// Get all jobs for this company
try {
    $stmt = $pdo->prepare("
        SELECT * FROM jobs_post 
        WHERE company_id = ? AND status != 'deleted'
        ORDER BY created_at DESC
    ");
    $stmt->execute([$company_id]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $jobs = [];
}

// Check if jobs table exists
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'jobs_post'");
    $table_exists = $stmt->rowCount() > 0;
} catch (PDOException $e) {
    $table_exists = false;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once './templates/head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge.expired {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.deleted {
            background: #e9ecef;
            color: #6c757d;
        }

        .limit-bar {
            background: #e9ecef;
            border-radius: 8px;
            height: 8px;
            overflow: hidden;
        }

        .limit-bar .limit-fill {
            height: 100%;
            border-radius: 8px;
            transition: width 0.5s ease;
            background: #667eea;
        }

        .limit-bar .limit-fill.warning {
            background: #f6ad55;
        }

        .limit-bar .limit-fill.danger {
            background: #fc8181;
        }

        .modal-job .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }

        .modal-job .modal-header {
            border-bottom: 1px solid #e9ecef;
            padding: 1.2rem 1.5rem;
        }

        .modal-job .modal-body {
            padding: 1.5rem;
        }

        .modal-job .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 1rem 1.5rem;
        }

        .select2-container--default .select2-selection--single {
            border: 1px solid #ced4da;
            border-radius: 8px;
            height: 38px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
            padding-left: 12px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 38px;
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
                <h5 class="mb-0 fw-bold" style="color: #1a2332;">Job Posts</h5>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-dark px-3 py-2">
                    <i class="bi bi-building me-1"></i> <?= htmlspecialchars($company_name) ?>
                </span>
                <span class="badge bg-primary px-3 py-2">
                    <i class="bi bi-box me-1"></i> <?= htmlspecialchars($current_plan_name) ?>
                </span>
                <span class="badge bg-info text-dark px-3 py-2">
                    <i class="bi bi-file-text me-1"></i> <?= $total_jobs ?> / <?= $job_posts_limit > 0 ? $job_posts_limit : '∞' ?> Jobs
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

            <!-- Statistics & Limit -->
            <div class="card-custom mb-4">
                <div class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: #f0f3ff; color: #667eea; font-size: 1.4rem;">
                                <i class="bi bi-briefcase"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0"><?= $total_jobs ?></h5>
                                <p class="text-muted mb-0" style="font-size: 0.8rem;">Total Jobs Posted</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div>
                            <div class="d-flex justify-content-between">
                                <span style="font-size: 0.85rem;">
                                    <i class="bi bi-box me-1"></i> <?= htmlspecialchars($current_plan_name) ?> Plan
                                </span>
                                <span style="font-size: 0.85rem; font-weight: 600;">
                                    <?= $total_jobs ?> / <?= $job_posts_limit > 0 ? $job_posts_limit : 'Unlimited' ?> Jobs
                                </span>
                            </div>
                            <div class="limit-bar mt-1">
                                <?php if ($job_posts_limit > 0): ?>
                                    <?php
                                    $percentage = min(($total_jobs / $job_posts_limit) * 100, 100);
                                    $class = $percentage >= 100 ? 'danger' : ($percentage > 80 ? 'warning' : '');
                                    ?>
                                    <div class="limit-fill <?= $class ?>" style="width: <?= $percentage ?>%;"></div>
                                <?php else: ?>
                                    <div class="limit-fill" style="width: 100%; background: #48bb78;"></div>
                                <?php endif; ?>
                            </div>
                            <?php if ($job_posts_limit > 0 && $total_jobs >= $job_posts_limit): ?>
                                <small class="text-danger mt-1 d-block">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                    You've reached your job post limit. <a href="subscription.php">Upgrade plan</a> to post more.
                                </small>
                            <?php elseif ($job_posts_limit > 0 && $total_jobs >= $job_posts_limit - 1): ?>
                                <small class="text-warning mt-1 d-block">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                    Only <?= $job_posts_limit - $total_jobs ?> job slot remaining.
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3 text-md-end">
                        <?php if ($job_posts_limit > 0 && $total_jobs >= $job_posts_limit): ?>
                            <button class="btn btn-secondary" disabled>
                                <i class="bi bi-lock me-1"></i> Limit Reached
                            </button>
                        <?php else: ?>
                            <button class="btn btn-primary" onclick="openAddJobModal()">
                                <i class="bi bi-plus-circle me-1"></i> Post New Job
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Job List -->
            <div class="card-custom">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: #f0f3ff; color: #667eea; font-size: 1.4rem;">
                            <i class="bi bi-list-ul"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0" style="color: #1a2332;">Job Listings</h5>
                            <p class="text-muted mb-0" style="font-size: 0.85rem;">Manage your job posts</p>
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2">
                            <i class="bi bi-database me-1"></i> <?= count($jobs) ?> Jobs
                        </span>
                    </div>
                </div>

                <?php if (empty($jobs)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-briefcase" style="font-size: 3rem; color: #adb5bd;"></i>
                        <p class="text-muted mt-3">No jobs posted yet</p>
                        <?php if ($job_posts_limit == 0 || $total_jobs < $job_posts_limit): ?>
                            <button class="btn btn-primary" onclick="openAddJobModal()">
                                <i class="bi bi-plus-circle me-1"></i> Post Your First Job
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Job Title</th>
                                    <th>Type</th>
                                    <th>Experience</th>
                                    <th>Salary</th>
                                    <th>Location</th>
                                    <th>Deadline</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobs as $index => $job): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <div class="fw-semibold" style="font-size: 0.9rem;"><?= htmlspecialchars($job['job_title']) ?></div>
                                            <div class="text-muted" style="font-size: 0.75rem;">Posted: <?= date('d M Y', strtotime($job['created_at'])) ?></div>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.85rem;"><?= htmlspecialchars($job['job_type']) ?></span>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.85rem;">
                                                <?php
                                                if ($job['experience_level'] == 'fresher') {
                                                    echo 'Fresher';
                                                } else {
                                                    echo $job['experience_min'] . ' - ' . $job['experience_max'] . ' yrs';
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.85rem;">
                                                <?php if ($job['salary_min'] > 0 || $job['salary_max'] > 0): ?>
                                                    ₹<?= number_format($job['salary_min']) ?> - ₹<?= number_format($job['salary_max']) ?>
                                                <?php else: ?>
                                                    Negotiable
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.85rem;"><?= htmlspecialchars($job['location']) ?></span>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.85rem;">
                                                <?= $job['application_deadline'] ? date('d M Y', strtotime($job['application_deadline'])) : 'N/A' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm status-select" style="width: auto; min-width: 100px;" onchange="updateStatus(<?= $job['id'] ?>, this.value)">
                                                <option value="active" <?= $job['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                                                <option value="inactive" <?= $job['status'] == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                <option value="pending" <?= $job['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="expired" <?= $job['status'] == 'expired' ? 'selected' : '' ?>>Expired</option>
                                            </select>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn btn-sm btn-outline-primary" onclick="editJob(<?= $job['id'] ?>)" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteJob(<?= $job['id'] ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-info" onclick="viewJob(<?= $job['id'] ?>)" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add/Edit Job Modal -->
    <div class="modal fade modal-job" id="jobModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: #f0f3ff; color: #667eea; font-size: 1.4rem;">
                            <i class="bi bi-plus-circle"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold" id="jobModalTitle">Post New Job</h5>
                            <span class="text-muted" style="font-size: 0.85rem;" id="jobModalSubtitle"><?= htmlspecialchars($company_name) ?></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="jobForm" novalidate>
                    <div class="modal-body">
                        <input type="hidden" id="jobId" name="id" value="0">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Job Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="jobTitle" name="job_title" required placeholder="e.g. Senior PHP Developer">
                            </div>
                            <div class="col-md-6">
                                <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Job Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="jobType" name="job_type" required>
                                    <option value="">Select type...</option>
                                    <option value="Full-time">Full-time</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Internship">Internship</option>
                                    <option value="Remote">Remote</option>
                                    <option value="Freelance">Freelance</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Experience Level</label>
                                <select class="form-select" id="experienceLevel" name="experience_level">
                                    <option value="fresher">Fresher (0 years)</option>
                                    <option value="experienced">Experienced</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="experienceRange">
                                <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Experience Range (Years)</label>
                                <div class="d-flex gap-2">
                                    <input type="number" class="form-control" id="experienceMin" name="experience_min" placeholder="Min" min="0" value="1">
                                    <span class="d-flex align-items-center">-</span>
                                    <input type="number" class="form-control" id="experienceMax" name="experience_max" placeholder="Max" min="1" value="5">
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Salary Range (₹)</label>
                                <div class="d-flex gap-2">
                                    <input type="number" class="form-control" id="salaryMin" name="salary_min" placeholder="Min" min="0" value="0">
                                    <span class="d-flex align-items-center">-</span>
                                    <input type="number" class="form-control" id="salaryMax" name="salary_max" placeholder="Max" min="0" value="0">
                                </div>
                                <small class="text-muted">Leave 0 for negotiable</small>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Location <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="location" name="location" required placeholder="e.g. Chennai, India">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Job Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="description" name="description" rows="3" required placeholder="Describe the job role, responsibilities, and expectations..."></textarea>
                        </div>

                        <div class="mt-3">
                            <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Requirements</label>
                            <textarea class="form-control" id="requirements" name="requirements" rows="2" placeholder="List the requirements for this position..."></textarea>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Skills</label>
                                <input type="text" class="form-control" id="skills" name="skills" placeholder="e.g. PHP, MySQL, JavaScript">
                            </div>
                            <div class="col-md-6">
                                <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Benefits</label>
                                <input type="text" class="form-control" id="benefits" name="benefits" placeholder="e.g. Health insurance, Flexible hours">
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Application Deadline</label>
                                <input type="date" class="form-control" id="applicationDeadline" name="application_deadline">
                            </div>
                            <div class="col-md-6">
                                <label class="fw-semibold mb-1" style="font-size: 0.9rem;">Status</label>
                                <select class="form-select" id="jobStatus" name="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="bi bi-check-circle me-1"></i> <span id="submitBtnText">Post Job</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Job Modal -->
    <div class="modal fade modal-job" id="viewJobModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: #f0f3ff; color: #667eea; font-size: 1.4rem;">
                            <i class="bi bi-file-text"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold" id="viewJobTitle">Job Details</h5>
                            <span class="text-muted" style="font-size: 0.85rem;" id="viewJobCompany"><?= htmlspecialchars($company_name) ?></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewJobContent">
                    <!-- Content loaded via JS -->
                </div>
                <div class="modal-footer">
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

        // Experience level toggle - Fix the validation error
        document.getElementById('experienceLevel').addEventListener('change', function() {
            const range = document.getElementById('experienceRange');
            const expMin = document.getElementById('experienceMin');
            const expMax = document.getElementById('experienceMax');

            if (this.value === 'fresher') {
                range.style.display = 'none';
                expMin.value = 0;
                expMax.value = 0;
                expMin.removeAttribute('required');
                expMax.removeAttribute('required');
                expMin.min = 0;
                expMax.min = 0;
            } else {
                range.style.display = 'flex';
                expMin.value = 1;
                expMax.value = 5;
                expMin.setAttribute('required', 'required');
                expMax.setAttribute('required', 'required');
                expMin.min = 0;
                expMax.min = 1;
            }
        });

        // Initialize
        if (document.getElementById('experienceLevel')) {
            document.getElementById('experienceLevel').dispatchEvent(new Event('change'));
        }

        // Job Modal
        let jobModal = new bootstrap.Modal(document.getElementById('jobModal'));
        let viewJobModal = new bootstrap.Modal(document.getElementById('viewJobModal'));

        // Open Add Job Modal
        function openAddJobModal() {
            document.getElementById('jobModalTitle').textContent = 'Post New Job';
            document.getElementById('submitBtnText').textContent = 'Post Job';
            document.getElementById('jobForm').reset();
            document.getElementById('jobId').value = 0;
            document.getElementById('experienceLevel').dispatchEvent(new Event('change'));
            document.getElementById('jobStatus').value = 'active';
            jobModal.show();
        }

        // Edit Job
        function editJob(id) {
            document.getElementById('jobModalTitle').textContent = 'Edit Job';
            document.getElementById('submitBtnText').textContent = 'Update Job';
            document.getElementById('jobId').value = id;

            fetch('?action=get_job&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        const job = data.data;
                        document.getElementById('jobTitle').value = job.job_title || '';
                        document.getElementById('jobType').value = job.job_type || '';
                        document.getElementById('experienceLevel').value = job.experience_level || 'fresher';
                        document.getElementById('experienceMin').value = job.experience_min || 0;
                        document.getElementById('experienceMax').value = job.experience_max || 0;
                        document.getElementById('salaryMin').value = job.salary_min || 0;
                        document.getElementById('salaryMax').value = job.salary_max || 0;
                        document.getElementById('location').value = job.location || '';
                        document.getElementById('description').value = job.description || '';
                        document.getElementById('requirements').value = job.requirements || '';
                        document.getElementById('skills').value = job.skills || '';
                        document.getElementById('benefits').value = job.benefits || '';
                        document.getElementById('applicationDeadline').value = job.application_deadline || '';
                        document.getElementById('jobStatus').value = job.status || 'active';
                        document.getElementById('experienceLevel').dispatchEvent(new Event('change'));
                        jobModal.show();
                    }
                })
                .catch(error => {
                    showToast('Error loading job details', 'error');
                });
        }

        // View Job
        function viewJob(id) {
            fetch('?action=get_job&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        const job = data.data;
                        document.getElementById('viewJobTitle').textContent = job.job_title;

                        const experience = job.experience_level === 'fresher' ? 'Fresher' : job.experience_min + ' - ' + job.experience_max + ' years';
                        const salary = (job.salary_min > 0 || job.salary_max > 0) ? '₹' + Number(job.salary_min).toLocaleString() + ' - ₹' + Number(job.salary_max).toLocaleString() : 'Negotiable';
                        const statusText = job.status.charAt(0).toUpperCase() + job.status.slice(1);
                        const statusClass = job.status;

                        document.getElementById('viewJobContent').innerHTML = `
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted" style="font-size: 0.75rem;">Job Type</div>
                            <div class="fw-semibold">${job.job_type || 'N/A'}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted" style="font-size: 0.75rem;">Experience</div>
                            <div class="fw-semibold">${experience}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted" style="font-size: 0.75rem;">Salary</div>
                            <div class="fw-semibold">${salary}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted" style="font-size: 0.75rem;">Location</div>
                            <div class="fw-semibold">${job.location || 'N/A'}</div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted" style="font-size: 0.75rem;">Description</div>
                            <div class="fw-semibold" style="white-space: pre-wrap;">${job.description || 'N/A'}</div>
                        </div>
                        ${job.requirements ? `
                        <div class="col-12">
                            <div class="text-muted" style="font-size: 0.75rem;">Requirements</div>
                            <div class="fw-semibold" style="white-space: pre-wrap;">${job.requirements}</div>
                        </div>
                        ` : ''}
                        ${job.skills ? `
                        <div class="col-md-6">
                            <div class="text-muted" style="font-size: 0.75rem;">Skills</div>
                            <div class="fw-semibold">${job.skills}</div>
                        </div>
                        ` : ''}
                        ${job.benefits ? `
                        <div class="col-md-6">
                            <div class="text-muted" style="font-size: 0.75rem;">Benefits</div>
                            <div class="fw-semibold">${job.benefits}</div>
                        </div>
                        ` : ''}
                        <div class="col-md-6">
                            <div class="text-muted" style="font-size: 0.75rem;">Deadline</div>
                            <div class="fw-semibold">${job.application_deadline ? new Date(job.application_deadline).toLocaleDateString() : 'No deadline'}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted" style="font-size: 0.75rem;">Status</div>
                            <div class="fw-semibold"><span class="status-badge ${statusClass}">${statusText}</span></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted" style="font-size: 0.75rem;">Posted On</div>
                            <div class="fw-semibold">${new Date(job.created_at).toLocaleString()}</div>
                        </div>
                    </div>
                `;
                        viewJobModal.show();
                    }
                })
                .catch(error => {
                    showToast('Error loading job details', 'error');
                });
        }

        // Submit Job Form
        document.getElementById('jobForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const id = document.getElementById('jobId').value;
            const isEdit = id > 0;
            const formData = new FormData(this);
            const action = isEdit ? 'update_job' : 'add_job';

            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtnText').textContent = isEdit ? 'Updating...' : 'Posting...';

            fetch('?action=' + action, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    document.getElementById('submitBtn').disabled = false;
                    document.getElementById('submitBtnText').textContent = isEdit ? 'Update Job' : 'Post Job';

                    if (data.success) {
                        showToast(data.message, 'success');
                        jobModal.hide();
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    document.getElementById('submitBtn').disabled = false;
                    document.getElementById('submitBtnText').textContent = isEdit ? 'Update Job' : 'Post Job';
                    showToast('An error occurred', 'error');
                });
        });

        // Update Status
        function updateStatus(id, status) {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('status', status);

            fetch('?action=update_status', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                    } else {
                        showToast(data.message, 'error');
                        location.reload();
                    }
                })
                .catch(error => {
                    showToast('An error occurred', 'error');
                    location.reload();
                });
        }

        // Delete Job
        function deleteJob(id) {
            if (!confirm('Are you sure you want to delete this job?')) return;

            const formData = new FormData();
            formData.append('id', id);

            fetch('?action=delete_job', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('An error occurred', 'error');
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