<?php
require_once './config/config.php';

// Check if logged in
if (!isCompanyLoggedIn() || !verifyCompanyToken($pdo)) {
    redirect('auth.php');
    exit();
}

$company = getCompanyData($pdo, $_SESSION['company_id']);
$company_name = $company['company_name'] ?? 'Company';
$company_id = $company['company_id'] ?? '';
$company_db_id = $company['id'] ?? 0;

// Get dashboard statistics from database
$stats = [
    'total_jobs' => 0,
    'total_applications' => 0,
    'total_candidates' => 0,
    'views_today' => 0,
    'pending_applications' => 0,
    'shortlisted_applications' => 0,
    'rejected_applications' => 0,
    'hired_applications' => 0
];

try {
    // Get total jobs
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM jobs_post WHERE company_id = ? AND status != 'deleted'");
    $stmt->execute([$company_db_id]);
    $stats['total_jobs'] = $stmt->fetchColumn() ?: 0;

    // Get total applications
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM job_applications WHERE company_id = ?");
    $stmt->execute([$company_db_id]);
    $stats['total_applications'] = $stmt->fetchColumn() ?: 0;

    // Get total unique candidates
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT applicant_email) as total FROM job_applications WHERE company_id = ?");
    $stmt->execute([$company_db_id]);
    $stats['total_candidates'] = $stmt->fetchColumn() ?: 0;

    // Get applications by status
    $statuses = ['pending', 'reviewed', 'shortlisted', 'rejected', 'hired'];
    foreach ($statuses as $status) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM job_applications WHERE company_id = ? AND status = ?");
        $stmt->execute([$company_db_id, $status]);
        $stats[$status . '_applications'] = $stmt->fetchColumn() ?: 0;
    }

    // Get today's views (jobs posted today)
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM jobs_post WHERE company_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$company_db_id]);
    $stats['views_today'] = $stmt->fetchColumn() ?: 0;

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
            c.company_name
        FROM job_applications a
        INNER JOIN jobs_post j ON a.job_id = j.id
        INNER JOIN companies c ON a.company_id = c.id
        WHERE a.company_id = ?
        ORDER BY a.applied_at DESC
        LIMIT 5
    ");
    $stmt->execute([$company_db_id]);
    $recent_applications = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching recent applications: " . $e->getMessage());
}

// Helper function for status badge
function getStatusBadge($status) {
    $badges = [
        'pending' => 'bg-warning bg-opacity-10 text-warning',
        'reviewed' => 'bg-info bg-opacity-10 text-info',
        'shortlisted' => 'bg-success bg-opacity-10 text-success',
        'rejected' => 'bg-danger bg-opacity-10 text-danger',
        'hired' => 'bg-success bg-opacity-10 text-success'
    ];
    return $badges[$status] ?? 'bg-secondary bg-opacity-10 text-secondary';
}

// Helper function for status label
function getStatusLabel($status) {
    $labels = [
        'pending' => 'Pending',
        'reviewed' => 'Reviewed',
        'shortlisted' => 'Shortlisted',
        'rejected' => 'Rejected',
        'hired' => 'Hired'
    ];
    return $labels[$status] ?? ucfirst($status);
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

// Get initials for avatar
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    return substr($initials, 0, 2);
}

// Get color for avatar
function getAvatarColor($name) {
    $colors = ['primary', 'warning', 'success', 'danger', 'info', 'secondary'];
    $index = abs(crc32($name)) % count($colors);
    return $colors[$index];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once './templates/head.php'; ?>
</head>

<body>

    <!-- Overlay -->
    <div class="overlay-modern" id="overlay" onclick="closeSidebar()"></div>

    <?php include_once './templates/sidebar.php'; ?>

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
                        <div class="stat-icon orange flex-shrink-0"><i class="bi bi-eye-fill"></i></div>
                        <div>
                            <div class="stat-value"><?= $stats['views_today'] ?></div>
                            <div class="stat-label">Jobs Today</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-3 bg-white">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold mb-0" style="color: #1e293b;">Recent Applications</h6>
                                <a href="applications.php" class="text-decoration-none small">View All <i class="bi bi-arrow-right"></i></a>
                            </div>
                            <div class="table-responsive">
                                <?php if (empty($recent_applications)): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 2rem; color: #d1d5db;"></i>
                                        <p class="text-muted mt-2" style="font-size: 0.85rem;">No applications yet</p>
                                    </div>
                                <?php else: ?>
                                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                        <thead>
                                            <tr>
                                                <th>Applicant</th>
                                                <th>Position</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_applications as $app): ?>
                                                <?php
                                                    $initials = getInitials($app['applicant_name']);
                                                    $color = getAvatarColor($app['applicant_name']);
                                                    $status_badge = getStatusBadge($app['status']);
                                                    $status_label = getStatusLabel($app['status']);
                                                    $time_ago = timeAgo($app['applied_at']);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <div class="rounded-circle bg-<?= $color ?> bg-opacity-10 text-<?= $color ?> d-flex align-items-center justify-content-center" style="width: 30px; height: 30px; font-weight: 600; font-size: 0.7rem;">
                                                                <?= $initials ?>
                                                            </div>
                                                            <?= htmlspecialchars($app['applicant_name']) ?>
                                                        </div>
                                                    </td>
                                                    <td><?= htmlspecialchars($app['job_title']) ?></td>
                                                    <td><span class="badge <?= $status_badge ?>"><?= $status_label ?></span></td>
                                                    <td><?= $time_ago ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-3 bg-white">
                        <div class="card-body p-4">
                            <h6 class="fw-bold mb-3" style="color: #1e293b;">Quick Actions</h6>
                            <div class="d-grid gap-2">
                                <a href="post-job.php" class="btn btn-primary d-flex align-items-center gap-2" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; justify-content: center; border-radius: 12px;">
                                    <i class="bi bi-plus-circle"></i> Post New Job
                                </a>
                                <a href="jobs.php" class="btn btn-outline-primary d-flex align-items-center gap-2" style="border-color: #e9ecef; justify-content: flex-start; border-radius: 12px;">
                                    <i class="bi bi-briefcase"></i> Manage Jobs
                                </a>
                                <a href="candidates.php" class="btn btn-outline-primary d-flex align-items-center gap-2" style="border-color: #e9ecef; justify-content: flex-start; border-radius: 12px;">
                                    <i class="bi bi-people"></i> View Candidates
                                </a>
                                <a href="subscription-plans.php" class="btn btn-outline-primary d-flex align-items-center gap-2" style="border-color: #e9ecef; justify-content: flex-start; border-radius: 12px;">
                                    <i class="bi bi-box"></i> Subscription Plans
                                </a>
                            </div>
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