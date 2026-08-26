<?php
header('Content-Type: application/json');
require_once '../config/config.php';

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit();
}

// Get POST data
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

// Validate inputs
if (empty($email) || empty($password)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please fill in all fields'
    ]);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email format'
    ]);
    exit();
}

try {
    // Query company by email
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $company = $stmt->fetch();

    // Check if company exists
    if (!$company) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password'
        ]);
        exit();
    }

    // Verify password
    if (!password_verify($password, $company['password'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password'
        ]);
        exit();
    }

    // Check if company is verified
    if ($company['is_verified'] == 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Your account is pending verification. Please wait for admin approval.'
        ]);
        exit();
    }

    // Generate new token
    $token = bin2hex(random_bytes(32));

    // Update company with new token and last login
    $updateStmt = $pdo->prepare("UPDATE companies SET token = ?, last_login = NOW() WHERE id = ?");
    $updateStmt->execute([$token, $company['id']]);

    // Set session
    $_SESSION['company_id'] = $company['id'];
    $_SESSION['company_name'] = $company['company_name'];
    $_SESSION['company_email'] = $company['email'];
    $_SESSION['company_token'] = $token;

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Login successful! Welcome back!',
        'redirect' => BASE_URL . 'index.php',
        'company' => [
            'id' => $company['id'],
            'company_id' => $company['company_id'],
            'name' => $company['company_name'],
            'email' => $company['email']
        ]
    ]);

} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred'
    ]);
}
?>