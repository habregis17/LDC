<?php
if (!defined('BASE_URL')) {
    exit;
}
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Sidebar Navigation Component -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <i class="fa-solid fa-shield-virus fa-2x"></i>
    </div>
    <nav>
        <a href="<?= BASE_URL ?>Home/" class="<?= $current_page === 'Home/' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-line"></i> Home
        </a>
        <?php if (isset($_SESSION['role_code']) && $_SESSION['role_code'] === 'PRIMARY_VERIFIER'): ?>
            <a href="<?= BASE_URL ?>Home/Draws/Initiate" class="<?= $current_page === 'Home/Draws/Initiate' ? 'active' : '' ?>">
                <i class="fa-solid fa-plus-circle"></i> New Draw Verification
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>Home/Draws/" class="<?= $current_page === 'Draws/' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-contract"></i> Certified Draws
        </a>
        <?php if (isset($_SESSION['role_code']) && $_SESSION['role_code'] === 'ADMIN'): ?>
            <a href="<?= BASE_URL ?>Home/Users/" class="<?= $current_page === 'Users/' ? 'active' : '' ?>">
                <i class="fa-solid fa-users-gear"></i> Manage System Users
            </a>
        <?php endif; ?>
    </nav>
</aside>