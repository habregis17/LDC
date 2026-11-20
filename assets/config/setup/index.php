<?php
require_once '../config.php';

// Check if any user already exists in the database
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM users");
$user_count = $stmt->fetch()['cnt'];

$error = '';
$success = '';

// If users already exist, prevent running setup again
if ($user_count > 0) {
    header("Location: ../../../welcome/");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    
    if (empty($full_name) || empty($email) || empty($password)) {
        $error = "All fields are required.";
    } else {
        // Fetch ADMIN role ID from roles table
        $role_stmt = $pdo->prepare("SELECT id FROM roles WHERE role_code = 'ADMIN' LIMIT 1");
        $role_stmt->execute();
        $admin_role = $role_stmt->fetch();
        
        if (!$admin_role) {
            $error = "System Error: ADMIN role not found. Please ensure your database schema script has been executed.";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $insert = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role_id) VALUES (?, ?, ?, ?)");
            if ($insert->execute([$full_name, $email, $password_hash, $admin_role['id']])) {
                $success = "Initial Administrator account created successfully!";
            } else {
                $error = "Failed to create administrator account.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Initial Setup - First Admin | LDC System</title>
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body style="justify-content: center; align-items: center;">
    <div style="background: #fff; width: 100%; max-width: 420px; padding: 25px; border-top: 5px solid var(--red); border-radius: 4px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
        <div style="text-align: center; margin-bottom: 20px;">
            <!-- <i class="fa-solid fa-user-shield" style="font-size: 2.5rem; color: var(--red);"></i> -->
            <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQlvWnPWdQkwLxQ63ri_CCc0RiLA0Vvxy_WxT1ao2xrSA&s" alt="BDO Logo" style="width: 120px; margin-bottom: 15px;">
            <h2 style="color: var(--charcoal); margin-top: 10px;">LDC System Setup</h2>
            <p style="font-size: 0.8rem; color: var(--slate);">Initialize First Administrator Account</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <div style="margin-top: 20px; text-align: center;">
                <a href="../../../welcome" style="background: var(--jade); color: #fff; padding: 12px 20px; text-decoration: none; font-weight: bold; border-radius: 4px; display: inline-block;">
                    <i class="fa-solid fa-right-to-bracket"></i> Proceed to Login
                </a>
            </div>
        <?php else: ?>
            <form method="POST">
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 0.85rem; font-weight: 600;">Full Name</label>
                    <input type="text" name="full_name" required placeholder="e.g. HABUWITEKA François Régis" style="width: 100%; padding: 10px; border: 1px solid var(--pale-charcoal); margin-top: 5px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 0.85rem; font-weight: 600;">Admin Email Address</label>
                    <input type="email" name="email" required placeholder="admin@ldc.rw" style="width: 100%; padding: 10px; border: 1px solid var(--pale-charcoal); margin-top: 5px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="font-size: 0.85rem; font-weight: 600;">Password</label>
                    <input type="password" name="password" required placeholder="Enter secure password" style="width: 100%; padding: 10px; border: 1px solid var(--pale-charcoal); margin-top: 5px;">
                </div>
                <button type="submit" style="width: 100%; padding: 12px; background: var(--red); color: #fff; border: none; font-weight: bold; cursor: pointer; border-radius: 4px;">
                    <i class="fa-solid fa-check-circle"></i> Create First Admin User
                </button>
            </form>
        <?php endif; ?>

        <div style="margin-top: 25px; text-align: center; border-top: 1px solid var(--pale-charcoal); padding-top: 15px;">
            <p style="font-size: 0.75rem; color: var(--slate);">Powered by <strong>BDO East Africa(Rwanda) Ltd</strong></p>
        </div>
    </div>
</body>
</html>