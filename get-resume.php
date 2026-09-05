<?php
require_once './config/config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if company is logged in
if (!isset($_SESSION['company_id']) || empty($_SESSION['company_id'])) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Access denied. Please login as a company.';
    exit;
}

// Get resume path from URL
$resume_path = isset($_GET['file']) ? $_GET['file'] : '';
$resume_path = str_replace('..', '', $resume_path); // Prevent directory traversal
$resume_path = ltrim($resume_path, '/');

if (empty($resume_path)) {
    header('HTTP/1.0 404 Not Found');
    echo 'Resume not specified.';
    exit;
}

// Verify the resume belongs to this company
try {
    $company_id = $_SESSION['company_id'];
    
    $stmt = $pdo->prepare("
        SELECT a.resume_file, a.id, a.applicant_name, j.job_title
        FROM job_applications a
        INNER JOIN jobs_post j ON a.job_id = j.id
        WHERE a.resume_file = ? AND a.company_id = ?
    ");
    $stmt->execute([$resume_path, $company_id]);
    $application = $stmt->fetch();
    
    if (!$application) {
        header('HTTP/1.0 403 Forbidden');
        echo 'Access denied: You do not have permission to view this resume.';
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error verifying resume access: " . $e->getMessage());
    header('HTTP/1.0 500 Internal Server Error');
    echo 'Database error occurred.';
    exit;
}

// Build the correct file path - look in employees.website
$file_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'employees.website' . DIRECTORY_SEPARATOR . $resume_path;

// Also try alternative locations
$alternative_paths = [
    $file_path,
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'company.job.panel' . DIRECTORY_SEPARATOR . $resume_path,
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'resumes' . DIRECTORY_SEPARATOR . basename($resume_path)
];

$found_path = null;
foreach ($alternative_paths as $path) {
    if (file_exists($path)) {
        $found_path = $path;
        break;
    }
}

if (!$found_path) {
    error_log("Resume file not found. Searched paths: " . implode(', ', $alternative_paths));
    header('HTTP/1.0 404 Not Found');
    echo 'Resume file not found.';
    exit;
}

// Get file extension and set headers
$file_extension = strtolower(pathinfo($found_path, PATHINFO_EXTENSION));
$mime_types = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

$mime_type = $mime_types[$file_extension] ?? 'application/octet-stream';

// Set headers for inline display or download
header('Content-Type: ' . $mime_type);
header('Content-Disposition: inline; filename="' . basename($found_path) . '"');
header('Content-Length: ' . filesize($found_path));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Output the file
readfile($found_path);
exit;
?>