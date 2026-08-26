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
$company_name = isset($_POST['company_name']) ? trim($_POST['company_name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$industry = isset($_POST['industry']) ? trim($_POST['industry']) : '';
$city = isset($_POST['city']) ? trim($_POST['city']) : '';
$state = isset($_POST['state']) ? trim($_POST['state']) : '';
$country = isset($_POST['country']) ? trim($_POST['country']) : '';

// Validate inputs
if (empty($company_name) || empty($email) || empty($password)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please fill in all required fields'
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

// Validate password length
if (strlen($password) < 6) {
    echo json_encode([
        'success' => false,
        'message' => 'Password must be at least 6 characters'
    ]);
    exit();
}

try {
    // Check if email exists
    $stmt = $pdo->prepare("SELECT id FROM companies WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Email already registered'
        ]);
        exit();
    }

    // Generate company ID
    $company_id = generateCompanyId();
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert company
    $stmt = $pdo->prepare("
        INSERT INTO companies (
            company_id, company_name, email, password, phone, 
            industry, city, state, country, is_verified, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)
    ");

    $stmt->execute([
        $company_id,
        $company_name,
        $email,
        $hashed_password,
        $phone,
        $industry,
        $city,
        $state,
        $country
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Registration successful! Please login.',
        'company_id' => $company_id
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred'
    ]);
}
