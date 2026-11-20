<?php
require_once '../../assets/config/config.php';
require_once '../../assets/config/mailer.php'; // Required for credential dispatch

if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "welcome");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password and clear must_change_password flag
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
        $stmt->execute([$hash, $_SESSION['user_id']]);
        
        log_system_audit($pdo, "USER_CHANGED_PASSWORD_ON_FIRST_LOGIN");
        header("Location: " . BASE_URL . "welcome");
        exit;
    }
}

$page_title = "Change Password | LDC System";
include_once __DIR__ . '../../../assets/includes/header.php';
?>

<main class="main-content" style="display: flex; justify-content: center; align-items: center; min-height: 70vh;">
    <div style="background: #fff; width: 100%; max-width: 420px; padding: 25px; border-top: 5px solid var(--red); border-radius: 4px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
        <div style="text-align: center; margin-bottom: 20px;">
            <i class="fa-solid fa-key" style="font-size: 2.2rem; color: var(--red);"></i>
            <h2 style="color: var(--charcoal); margin-top: 10px;">Security Password Reset</h2>
            <p style="font-size: 0.8rem; color: var(--slate);">You are logging in with a temporary credential. Please set your permanent password.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div style="margin-bottom: 15px;">
                <label style="font-size: 0.85rem; font-weight: 600;">New Password</label>
                <input type="password" name="new_password" required placeholder="At least 6 characters" style="width: 100%; padding: 10px; border: 1px solid var(--pale-charcoal); margin-top: 5px;">
            </div>
            <div style="margin-bottom: 20px;">
                <label style="font-size: 0.85rem; font-weight: 600;">Confirm New Password</label>
                <input type="password" name="confirm_password" required placeholder="Re-enter new password" style="width: 100%; padding: 10px; border: 1px solid var(--pale-charcoal); margin-top: 5px;">
            </div>
            <button type="submit" style="width: 100%; padding: 12px; background: var(--red); color: #fff; border: none; font-weight: bold; cursor: pointer; border-radius: 4px;">
                <i class="fa-solid fa-check"></i> Update Password & Proceed
            </button>
        </form>
    </div>
</main>

<?php include_once __DIR__ . '../../../assets/includes/footer.php'; ?>?>