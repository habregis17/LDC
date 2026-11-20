<?php
require_once __DIR__ . '/../../../assets/config/config.php';
require_once __DIR__ . '/../../../assets/includes/certificate_pdf_maker.php';

check_auth(['PARTNER', 'PRIMARY_VERIFIER', 'ADMIN']);

$id_param = trim($_GET['id'] ?? '');
$mode     = ($_GET['mode'] ?? 'inline') === 'download' ? 'download' : 'inline';

$draw = fetch_certified_draw($pdo, $id_param);

if (!$draw) {
    http_response_code(404);
    die("Error: Certificate record not found.");
}

if ($draw['status'] !== 'CERTIFIED') {
    http_response_code(403);
    die("Notice: This draw has not been certified yet. Current status: " . htmlspecialchars($draw['status']));
}

$protocol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$verify_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . BASE_URL . "verify.php?ref=" . urlencode($draw['certificate_ref']) . "&hash=" . urlencode($draw['draw_hash_code']);

render_draw_certificate_pdf($draw, $verify_url, $mode);
exit;