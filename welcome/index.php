<?php
require_once '../assets/config/config.php';
require_once '../assets/config/mailer.php';

// Check if any users exist; if none exist, redirect directly to setup page
$user_check = $pdo->query("SELECT COUNT(*) as cnt FROM users")->fetch()['cnt'];
if ($user_check == 0) {
    header("Location: ../assets/config/setup");
    exit;
}

$error = '';
$success = '';
$step = $_SESSION['mfa_pending'] ?? 1;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'];
    $ua = $_SERVER['HTTP_USER_AGENT'];
    
    // Check IP Throttle: 2 min block after failed attempts
    $stmt = $pdo->prepare("SELECT attempt_time FROM login_logs WHERE ip_address = ? AND status = 'No' ORDER BY attempt_time DESC LIMIT 1");
    $stmt->execute([$ip]);
    $last_failed = $stmt->fetch();
    
    if ($last_failed && (time() - strtotime($last_failed['attempt_time'])) < 120) {
        $error = "Too many failed attempts. Please wait 2 minutes before trying again.";
    } else {
        if (isset($_POST['action_login'])) {
            $email = trim($_POST['email']);
            $password = trim($_POST['password']);
            
            $stmt = $pdo->prepare("SELECT u.*, r.role_code FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Log Success Pre-MFA
                $pdo->prepare("INSERT INTO login_logs (email, ip_address, device_user_agent, status) VALUES (?, ?, ?, 'Ok')")->execute([$email, $ip, $ua]);
                
                // Generate MFA Code
                $mfa_code = rand(100000, 999999);
                $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                $pdo->prepare("INSERT INTO mfa_codes (user_id, action_type, code, expires_at) VALUES (?, 'LOGIN', ?, ?)")->execute([$user['id'], $mfa_code, $expires]);
                
                $_SESSION['temp_user_id'] = $user['id'];
                $_SESSION['temp_role'] = $user['role_code'];
                $_SESSION['temp_name'] = $user['full_name'];
                $_SESSION['temp_must_change'] = $user['must_change_password'] ?? 0; // Capture flag
                $_SESSION['mfa_pending'] = 2;
                $step = 2;
               // Send MFA via Email Helper
                $email_subject = "LDC System Login Verification Code";
                $email_body = "<p>Hello <strong>" . htmlspecialchars($user['full_name']) . "</strong>,</p>
                               <p>A login attempt was initiated for your account. Please use the following 6-digit verification code to complete your sign-in:</p>
                               <div style='text-align: center; margin: 20px 0;'>
                                   <span style='font-size: 2rem; font-weight: bold; color: #E81A3B; background: #E7E7E7; padding: 10px 20px; letter-spacing: 5px; border-radius: 4px;'>" . $mfa_code . "</span>
                               </div>
                               <p>This code is valid for 10 minutes. If you did not request this, please secure your account immediately.</p>";
                
                send_system_email($email, $email_subject, $email_body);
                $success = "An MFA verification code has been sent to your email address.";
            } 
            
            else {
                $pdo->prepare("INSERT INTO login_logs (email, ip_address, device_user_agent, status) VALUES (?, ?, ?, 'No')")->execute([$email, $ip, $ua]);
                $error = "Invalid credentials provided.";
            }
        } elseif (isset($_POST['action_mfa'])) {
            $code = trim($_POST['mfa_code']);
            $user_id = $_SESSION['temp_user_id'];
            
            $stmt = $pdo->prepare("SELECT * FROM mfa_codes WHERE user_id = ? AND code = ? AND is_used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id, $code]);
            $valid_mfa = $stmt->fetch();
            if ($valid_mfa) {
                    $pdo->prepare("UPDATE mfa_codes SET is_used = 1 WHERE id = ?")->execute([$valid_mfa['id']]);
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['role_code'] = $_SESSION['temp_role'];
                    $_SESSION['full_name'] = $_SESSION['temp_name'];
                    $must_change = $_SESSION['temp_must_change'] ?? 0;
                    
                    unset($_SESSION['temp_user_id'], $_SESSION['temp_role'], $_SESSION['temp_name'], $_SESSION['temp_must_change'], $_SESSION['mfa_pending']);
                    
                    log_system_audit($pdo, "USER_LOGIN_SUCCESS");
                    
                    // Redirect based on password change requirement
                    if ($must_change == 1) {
                        header("Location: " . BASE_URL . "welcome/Changepassword");
                    } else {
                        header("Location: " . BASE_URL . "Home");
                    }
                    exit;
                }
            
           else {
                $error = "Invalid or expired MFA code.";
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
    <title> Welcome | LDC System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body style="justify-content: center; align-items: center;">
    <div style="background: #fff; width: 100%; max-width: 400px; padding: 25px; border-top: 5px solid var(--red); border-radius: 4px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
        <div style="text-align: center; margin-bottom: 20px;">
            <!-- BDO Logo centered -->
             <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQlvWnPWdQkwLxQ63ri_CCc0RiLA0Vvxy_WxT1ao2xrSA&s" alt="BDO Logo" style="width: 120px; margin-bottom: 15px;">
            <!-- <i class="fa-solid fa-diamond" style="font-size: 2.5rem; color: var(--red);"></i> -->
            <h2 style="color: var(--charcoal); margin-top: 10px;">Loterry Draw Certification</h2>
            <p style="font-size: 0.8rem; color: var(--slate);">Verrification Portal</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form method="POST">
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 0.85rem; font-weight: 600;">Email</label>
                    <input type="email" name="email" required style="width: 100%; padding: 10px; border: 1px solid var(--pale-charcoal); margin-top: 5px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="font-size: 0.85rem; font-weight: 600;">Password</label>
                    <input type="password" name="password" required style="width: 100%; padding: 10px; border: 1px solid var(--pale-charcoal); margin-top: 5px;">
                </div>
                <button type="submit" name="action_login" style="width: 100%; padding: 12px; background: var(--charcoal); color: #fff; border: none; font-weight: bold; cursor: pointer;">
                    <i class="fa-solid fa-right-to-bracket"></i> Enter
                </button>
            </form>
        <?php else: ?>
            <form method="POST">
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 0.85rem; font-weight: 600;">Enter 6-Digit Code Sent on your email</label>
                    <input type="text" name="mfa_code" maxlength="6" required style="width: 100%; padding: 10px; border: 1px solid var(--pale-charcoal); margin-top: 5px; text-align: center; font-size: 1.2rem; letter-spacing: 5px;">
                </div>
                <button type="submit" name="action_mfa" style="width: 100%; padding: 12px; background: var(--jade); color: #fff; border: none; font-weight: bold; cursor: pointer;">
                    <i class="fa-solid fa-key"></i> Verify Security Code
                </button>
            </form>
        <?php endif; ?>

        <div style="margin-top: 25px; text-align: center; border-top: 1px solid var(--pale-charcoal); padding-top: 15px;">
            <p style="font-size: 0.75rem; color: var(--slate);">Powered by <strong>BDO East Africa(Rwanda) Ltd.</strong></p>
        </div>
    </div>
</body>
</html>