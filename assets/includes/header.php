<?php
if (!defined('BASE_URL')) {
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'LDC - Lottery Draw Certification System' ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script>
        // Dynamic Live Ticking Clock (GMT+2 / Local)
        function updateLiveClock() {
            const now = new Date();
            const day = String(now.getDate()).padStart(2, '0');
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const year = now.getFullYear();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            const clockEl = document.getElementById('live-clock');
            if (clockEl) {
                clockEl.innerText = `${day}/${month}/${year} ${hours}:${minutes}:${seconds}`;
            }
        }
        setInterval(updateLiveClock, 1000);
    </script>
</head>
<body>

    <!-- Header Component -->
    <header class="app-header">
        <div class="brand">
            <i class="fa-solid fa-award fa-xl" style="color: var(--red);"></i>
            <div>
                <h1>LDC - Intego Lotto Draw Certification</h1>
                <span>Powered by BDO East Africa(Rwanda) Ltd</span>
            </div>
        </div>
        <div class="clock-display" style="background: var(--pale-charcoal); padding: 6px 12px; border-radius: 4px;">
            <i class="fa-regular fa-clock" style="color: var(--red);"></i> 
            <span id="live-clock"><?= date('d/m/Y H:i:s') ?></span> <span style="font-size: 0.75rem; color: var(--slate);">(GMT+2)</span>
        </div>
        <div>
            <a href="<?= BASE_URL ?>logout/" style="color: var(--red); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                <i class="fa-solid fa-power-off"></i> Logout
            </a>
        </div>
    </header>

    <div class="app-layout">