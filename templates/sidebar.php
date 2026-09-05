<!-- Sidebar - Modern Glassmorphism -->
<nav class="sidebar-modern" id="sidebar">
    <div class="brand d-flex align-items-center justify-content-between">
        <div>
            <h4 class="mb-0"><i class="bi bi-briefcase-fill me-2"></i>JobHub</h4>
            <small>Company Dashboard</small>
        </div>
        <button class="close-sidebar-modern" onclick="closeSidebar()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="nav-section">
        <div class="section-title">Main</div>
        <ul class="nav flex-column gap-0">
            <li><a href="<?= url('index.php') ?>" class="nav-link active"><i class="bi bi-grid-fill"></i> Dashboard</a></li>
            <li><a href="job-post" class="nav-link"><i class="bi bi-briefcase"></i> Jobs <span class="badge-count">12</span></a></li>
            <li><a href="applications.php" class="nav-link"><i class="bi bi-file-earmark-text"></i> Applications <span class="badge-count">24</span></a></li>
            <li><a href="candidates" class="nav-link"><i class="bi bi-people"></i> Candidates</a></li>
                        <!-- Subscriptions - credit card icon (already using) -->
            <li><a href="subscriptions" class="nav-link"><i class="bi bi-credit-card-fill me-3"></i> Subscriptions</a></li>
        </ul>
    </div>

   

    <div class="company-profile">
        <div class="d-flex align-items-center gap-3">
            <div class="avatar"><?= strtoupper(substr($company_name, 0, 1)) ?></div>
            <div class="company-info">
                <div class="name"><?= htmlspecialchars($company_name) ?></div>
                <div class="id">ID: <?= htmlspecialchars($company_id) ?></div>
            </div>
            <div class="dropdown">
                <button class="btn btn-sm btn-light rounded-circle p-1" data-bs-toggle="dropdown" style="width: 32px; height: 32px; border-color: #e9ecef;">
                    <i class="bi bi-three-dots-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-1" style="min-width: 160px;">
                    <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider" /></li>
                    <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>