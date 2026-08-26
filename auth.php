<?php
require_once './config/config.php';

// If already logged in, redirect to dashboard
if (isCompanyLoggedIn() && verifyCompanyToken($pdo)) {
    redirect('index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Company Login · Register</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(135deg, #0f1724 0%, #1a2332 50%, #2d1b69 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .auth-wrapper {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .auth-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border-radius: 30px;
            padding: 2.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5);
        }

        .auth-tabs .nav-link {
            color: rgba(255, 255, 255, 0.5);
            padding: 0.8rem 2rem;
            border-radius: 50px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s;
            font-weight: 600;
            background: transparent;
        }

        .auth-tabs .nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
        }

        .auth-tabs .nav-link.active {
            color: #fff;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: transparent;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .auth-form .form-control {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
            border-radius: 12px;
            padding: 0.8rem 1rem;
        }

        .auth-form .form-control:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
            color: #fff;
        }

        .auth-form .form-control::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        .auth-form .input-group-text {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.5);
            border-right: none;
        }

        .auth-form .input-group .form-control {
            border-left: none;
        }

        .auth-form .input-group .form-control:focus {
            border-left: none;
        }

        .auth-form .input-group .password-toggle {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-left: none;
            border-radius: 0 12px 12px 0;
            color: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            padding: 0 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .auth-form .input-group .password-toggle:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.08);
        }

        .auth-form label {
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .btn-auth {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0.8rem;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.25);
        }

        .btn-auth:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
            color: #fff;
        }

        .btn-auth:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }

        .auth-title {
            color: #fff;
            font-weight: 700;
            font-size: 2rem;
        }

        .auth-subtitle {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.9rem;
        }

        .auth-side {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 2.5rem;
            height: 100%;
            min-height: 400px;
        }

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 400px;
            width: 100%;
        }

        .toast-notification {
            padding: 1rem 1.2rem;
            border-radius: 12px;
            color: #fff;
            font-weight: 500;
            font-size: 0.9rem;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.3);
            transform: translateX(120%);
            animation: slideIn 0.4s ease forwards;
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(10px);
        }

        .toast-notification.hide {
            animation: slideOut 0.4s ease forwards;
        }

        .toast-success {
            background: linear-gradient(135deg, #48bb78, #38a169);
        }

        .toast-error {
            background: linear-gradient(135deg, #fc8181, #e53e3e);
        }

        .toast-info {
            background: linear-gradient(135deg, #63b3ed, #3182ce);
        }

        @keyframes slideIn {
            0% {
                transform: translateX(120%);
                opacity: 0;
            }

            100% {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            0% {
                transform: translateX(0);
                opacity: 1;
            }

            100% {
                transform: translateX(120%);
                opacity: 0;
            }
        }

        .spinner-border-sm {
            width: 1.2rem;
            height: 1.2rem;
        }

        @media (max-width: 768px) {
            .auth-card {
                padding: 1.5rem;
            }

            .auth-side {
                min-height: 200px;
            }

            .auth-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>

    <div class="auth-wrapper">
        <div class="row g-0 auth-card">
            <!-- Left Side - Branding -->
            <div class="col-lg-5 d-none d-lg-block pe-4">
                <div class="auth-side d-flex flex-column justify-content-center text-white">
                    <div class="mb-4">
                        <i class="bi bi-building" style="font-size: 3rem; opacity: 0.3;"></i>
                    </div>
                    <h2 class="fw-bold mb-3">Welcome to JobHub</h2>
                    <p class="opacity-75 mb-4">The ultimate platform for companies to find the best talent. Post jobs, manage applications, and grow your team.</p>
                    <div class="d-flex gap-3 mt-3">
                        <div>
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <span class="small">Post Unlimited Jobs</span>
                        </div>
                        <div>
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <span class="small">Find Top Talent</span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="border-top border-white border-opacity-25 pt-3">
                            <small class="opacity-50">Already have an account? Login below</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side - Login/Register -->
            <div class="col-lg-7">
                <ul class="nav auth-tabs nav-pills mb-4" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="login-tab" data-bs-toggle="pill" data-bs-target="#login-pane" type="button" role="tab">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Login
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="register-tab" data-bs-toggle="pill" data-bs-target="#register-pane" type="button" role="tab">
                            <i class="bi bi-person-plus me-2"></i>Register
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Login Tab -->
                    <div class="tab-pane fade show active" id="login-pane" role="tabpanel">
                        <h4 class="auth-title">Sign In</h4>
                        <p class="auth-subtitle mb-4">Welcome back! Login to your company dashboard</p>

                        <form id="loginForm" class="auth-form">
                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                                    <input type="email" class="form-control" id="loginEmail" placeholder="company@email.com" required autofocus />
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" class="form-control" id="loginPassword" placeholder="Enter your password" required />
                                    <span class="password-toggle" onclick="toggleLoginPassword()">
                                        <i class="bi bi-eye" id="loginToggleIcon"></i>
                                    </span>
                                </div>
                            </div>
                            <button type="submit" class="btn-auth" id="loginBtn">
                                <span id="loginText"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In</span>
                                <span id="loginSpinner" class="spinner-border spinner-border-sm" style="display:none;"></span>
                            </button>
                            <div class="text-center mt-3">
                                <small class="text-white-50">Don't have an account? <a href="#" class="text-primary" onclick="document.getElementById('register-tab').click()">Register here</a></small>
                            </div>
                        </form>
                    </div>

                    <!-- Register Tab -->
                    <div class="tab-pane fade" id="register-pane" role="tabpanel">
                        <h4 class="auth-title">Create Account</h4>
                        <p class="auth-subtitle mb-4">Register your company to start hiring</p>

                        <form id="registerForm" class="auth-form">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Company Name <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-building"></i></span>
                                        <input type="text" class="form-control" id="regCompanyName" placeholder="Tech Solutions Inc." required />
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                                        <input type="email" class="form-control" id="regEmail" placeholder="company@email.com" required />
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone Number</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-phone-fill"></i></span>
                                        <input type="tel" class="form-control" id="regPhone" placeholder="+1-555-0100" />
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Website</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-globe"></i></span>
                                        <input type="url" class="form-control" id="regWebsite" placeholder="https://www.yourcompany.com" />
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Industry</label>
                                    <select class="form-control" id="regIndustry">
                                        <option value="">Select Industry</option>
                                        <option value="Technology">Technology</option>
                                        <option value="Healthcare">Healthcare</option>
                                        <option value="Finance">Finance</option>
                                        <option value="Education">Education</option>
                                        <option value="Retail">Retail</option>
                                        <option value="Manufacturing">Manufacturing</option>
                                        <option value="Design">Design</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                        <input type="password" class="form-control" id="regPassword" placeholder="Min 6 characters" required />
                                        <span class="password-toggle" onclick="toggleRegisterPassword()">
                                            <i class="bi bi-eye" id="registerToggleIcon"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-shield-lock-fill"></i></span>
                                        <input type="password" class="form-control" id="regConfirmPassword" placeholder="Confirm password" required />
                                        <span class="password-toggle" onclick="toggleRegisterConfirmPassword()">
                                            <i class="bi bi-eye" id="registerConfirmToggleIcon"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">City</label>
                                    <input type="text" class="form-control" id="regCity" placeholder="San Francisco" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">State</label>
                                    <input type="text" class="form-control" id="regState" placeholder="CA" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Country</label>
                                    <input type="text" class="form-control" id="regCountry" placeholder="USA" />
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn-auth" id="registerBtn">
                                        <span id="registerText"><i class="bi bi-person-plus me-2"></i>Create Company Account</span>
                                        <span id="registerSpinner" class="spinner-border spinner-border-sm" style="display:none;"></span>
                                    </button>
                                </div>
                                <div class="col-12 text-center">
                                    <small class="text-white-50">Already have an account? <a href="#" class="text-primary" onclick="document.getElementById('login-tab').click()">Login here</a></small>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // =============================================
        // PASSWORD TOGGLE FUNCTIONS
        // =============================================
        
        // Login Password Toggle
        function toggleLoginPassword() {
            const passwordInput = document.getElementById('loginPassword');
            const icon = document.getElementById('loginToggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }

        // Register Password Toggle
        function toggleRegisterPassword() {
            const passwordInput = document.getElementById('regPassword');
            const icon = document.getElementById('registerToggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }

        // Register Confirm Password Toggle
        function toggleRegisterConfirmPassword() {
            const passwordInput = document.getElementById('regConfirmPassword');
            const icon = document.getElementById('registerConfirmToggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }

        // =============================================
        // LOGIN FORM HANDLER
        // =============================================
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const email = document.getElementById('loginEmail').value.trim();
            const password = document.getElementById('loginPassword').value.trim();
            const btn = document.getElementById('loginBtn');
            const text = document.getElementById('loginText');
            const spinner = document.getElementById('loginSpinner');

            if (!email || !password) {
                showToast('Please fill in all fields', 'error');
                return;
            }

            // Validate email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showToast('Please enter a valid email address', 'error');
                return;
            }

            // Show loading
            btn.disabled = true;
            text.style.display = 'none';
            spinner.style.display = 'inline-block';

            const formData = new FormData();
            formData.append('email', email);
            formData.append('password', password);

            fetch('ajax/login_company.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    text.style.display = 'inline';
                    spinner.style.display = 'none';

                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(() => {
                            window.location.href = data.redirect || 'index.php';
                        }, 1500);
                    } else {
                        showToast(data.message, 'error');
                        document.getElementById('loginPassword').value = '';
                        document.getElementById('loginPassword').focus();
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    text.style.display = 'inline';
                    spinner.style.display = 'none';
                    showToast('An error occurred. Please try again.', 'error');
                    console.error('Error:', error);
                });
        });

        // =============================================
        // REGISTER FORM HANDLER
        // =============================================
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const company_name = document.getElementById('regCompanyName').value.trim();
            const email = document.getElementById('regEmail').value.trim();
            const password = document.getElementById('regPassword').value.trim();
            const confirm_password = document.getElementById('regConfirmPassword').value.trim();
            const phone = document.getElementById('regPhone').value.trim();
            const website = document.getElementById('regWebsite').value.trim();
            const industry = document.getElementById('regIndustry').value;
            const city = document.getElementById('regCity').value.trim();
            const state = document.getElementById('regState').value.trim();
            const country = document.getElementById('regCountry').value.trim();
            const btn = document.getElementById('registerBtn');
            const text = document.getElementById('registerText');
            const spinner = document.getElementById('registerSpinner');

            // Validation
            if (!company_name || !email || !password) {
                showToast('Please fill in all required fields', 'error');
                return;
            }

            if (password !== confirm_password) {
                showToast('Passwords do not match', 'error');
                return;
            }

            if (password.length < 6) {
                showToast('Password must be at least 6 characters', 'error');
                return;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showToast('Please enter a valid email address', 'error');
                return;
            }

            // Validate website URL if provided
            if (website) {
                try {
                    const url = new URL(website);
                    if (!url.protocol.startsWith('http')) {
                        showToast('Please enter a valid website URL (e.g., https://example.com)', 'error');
                        return;
                    }
                } catch (e) {
                    showToast('Please enter a valid website URL (e.g., https://example.com)', 'error');
                    return;
                }
            }

            // Show loading
            btn.disabled = true;
            text.style.display = 'none';
            spinner.style.display = 'inline-block';

            const formData = new FormData();
            formData.append('company_name', company_name);
            formData.append('email', email);
            formData.append('password', password);
            formData.append('phone', phone);
            formData.append('website', website);
            formData.append('industry', industry);
            formData.append('city', city);
            formData.append('state', state);
            formData.append('country', country);

            fetch('ajax/register_company.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    text.style.display = 'inline';
                    spinner.style.display = 'none';

                    if (data.success) {
                        showToast(data.message, 'success');
                        // Switch to login tab
                        document.getElementById('login-tab').click();
                        // Fill login email
                        document.getElementById('loginEmail').value = email;
                        // Clear register form
                        document.getElementById('registerForm').reset();
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    text.style.display = 'inline';
                    spinner.style.display = 'none';
                    showToast('An error occurred. Please try again.', 'error');
                    console.error('Error:', error);
                });
        });

        // =============================================
        // TOAST NOTIFICATION SYSTEM
        // =============================================
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = `toast-notification toast-${type}`;
            const icons = {
                success: 'bi bi-check-circle-fill',
                error: 'bi bi-exclamation-circle-fill',
                info: 'bi bi-info-circle-fill'
            };
            toast.innerHTML = `
                <span><i class="${icons[type] || icons.info}"></i></span>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()" style="background: transparent; border: none; color: inherit; opacity: 0.7; cursor: pointer; margin-left: auto; font-size: 1.1rem;">&times;</button>
            `;
            container.appendChild(toast);

            setTimeout(() => {
                if (toast.parentNode) {
                    toast.classList.add('hide');
                    setTimeout(() => {
                        if (toast.parentNode) toast.remove();
                    }, 400);
                }
            }, 4000);
        }

        // =============================================
        // ENTER KEY SUPPORT
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const activeTab = document.querySelector('.tab-pane.active');
                if (activeTab) {
                    const form = activeTab.querySelector('form');
                    if (form) {
                        form.dispatchEvent(new Event('submit'));
                    }
                }
            }
        });
    </script>
</body>

</html>