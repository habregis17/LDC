<?php
require_once 'assets/config/config.php';
check_auth();

// Fetch Top 10 Recent Draws
$stmt = $pdo->query("SELECT d.*, u.full_name as officer FROM draws d JOIN users u ON d.primary_verifier_id = u.id ORDER BY d.created_at DESC LIMIT 10");
$recent_draws = $stmt->fetchAll();

$today = date('Y-m-d');
$today_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM draws WHERE draw_date = ?");
$today_stmt->execute([$today]);
$has_today_draw = $today_stmt->fetch()['cnt'] > 0;
?>