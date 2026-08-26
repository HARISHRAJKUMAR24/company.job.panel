<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'jobhub');
define('DB_USER', 'root');
define('DB_PASS', '');

// Application configuration
define('APP_URL', 'http://localhost/company.job.panel/');
define('BASE_URL', 'http://localhost/company.job.panel/');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper functions
function isCompanyLoggedIn() {
    return isset($_SESSION['company_id']);
}

function verifyCompanyToken($pdo) {
    if (!isset($_SESSION['company_id']) || !isset($_SESSION['company_token'])) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT token FROM companies WHERE id = ?");
        $stmt->execute([$_SESSION['company_id']]);
        $company = $stmt->fetch();
        
        return $company && $company['token'] === $_SESSION['company_token'];
    } catch(PDOException $e) {
        return false;
    }
}

function getCompanyData($pdo, $id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch(PDOException $e) {
        return null;
    }
}

function generateCompanyId() {
    $prefix = 'COMP-';
    $number = rand(100, 999);
    return $prefix . str_pad($number, 3, '0', STR_PAD_LEFT);
}

// URL helper functions
function url($path = '') {
    return BASE_URL . ltrim($path, '/');
}

function redirect($path = '') {
    header("Location: " . url($path));
    exit();
}

function getData($column, $table, $condition) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT $column FROM $table WHERE $condition");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result[$column] : '';
    } catch(PDOException $e) {
        return '';
    }
}
?>