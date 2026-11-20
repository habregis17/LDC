<?php
// Define the project root base URL
define('BASE_URL', '/LDC/');

// Set System Timezone to GMT+2 (Central Africa Time / Kigali)
date_default_timezone_set('Africa/Kigali');

define('DB_HOST', 'testapp-db-1');
define('DB_NAME', 'LDC');
define('DB_USER', 'root');
define('DB_PASS', 'root');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function check_auth($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "welcome");
        exit;
    }
    if (!empty($allowed_roles) && !in_array($_SESSION['role_code'], $allowed_roles)) {
        die("Unauthorized access.");
    }
}

function generate_reference(PDO $pdo, string $date_str): string
{
    $timestamp      = strtotime($date_str) ?: time();
    $formatted_date = date('Ymd', $timestamp);

    // Find the highest numeric suffix currently used in certificate_ref (e.g. DVC-20261119-059 -> 59)
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING_INDEX(certificate_ref, '-', -1) AS UNSIGNED)) AS max_seq
        FROM draws
        WHERE certificate_ref LIKE 'DVC-%-%'
    ");
    $row     = $stmt->fetch(PDO::FETCH_ASSOC);
    $max_seq = (int)($row['max_seq'] ?? 0);

    $next_seq = $max_seq + 1;

    // Optional: cap or warn at 999 if your numbering scheme strictly tops out at 999
    if ($next_seq > 999) {
        throw new RuntimeException("Certificate sequence limit (999) reached.");
    }

    $padded_seq = str_pad((string)$next_seq, 3, '0', STR_PAD_LEFT);

    return "DVC-" . $formatted_date . "-" . $padded_seq;
}

function log_system_audit($pdo, $action, $draw_id = null, $gps = null) {
    $stmt = $pdo->prepare("INSERT INTO system_audit_logs (user_id, action, draw_id, ip_address, device_info, gps_coords) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        $draw_id,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'],
        $gps
    ]);
}
?>