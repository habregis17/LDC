<?php
require_once '../../assets/config/config.php';
require_once '../../assets/config/mailer.php'; // Required for credential dispatch
check_auth(['ADMIN']); 

// Ensure must_change_password column exists programmatically
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) DEFAULT 0");
} catch (PDOException $e) {}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action_type'] ?? '';
    
    if ($action === 'create') {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        $role_id = intval($_POST['role_id']);
        
        if (empty($full_name) || empty($email) || empty($password) || empty($role_id)) {
            $error = "All fields are required to create a user.";
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error = "A user with this email address already exists.";
            } else {
                // Fetch Role Name for the email notification
                $role_stmt = $pdo->prepare("SELECT role_name FROM roles WHERE id = ?");
                $role_stmt->execute([$role_id]);
                $role_data = $role_stmt->fetch();
                $role_name = $role_data ? $role_data['role_name'] : 'System User';

                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert user with must_change_password = 1
                $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role_id, is_active, must_change_password) VALUES (?, ?, ?, ?, 1, 1)");
                if ($stmt->execute([$full_name, $email, $password_hash, $role_id])) {
                    
                    // Dispatch credentials email via PHPMailer
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                    $login_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . BASE_URL . "login.php";
                    
                    $email_subject = "Your LDC System Access Credentials";
                    $email_body = "<p>Hello <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                                   <p>An account has been provisioned for you on the <strong>Lottery Draw Certification (LDC)</strong> system under the role of <strong>" . htmlspecialchars($role_name) . "</strong>.</p>
                                   <p>Here are your secure login credentials:</p>
                                   <ul style='background: #f4f6f9; padding: 15px; border-radius: 4px; list-style-type: none;'>
                                       <li><strong>Login URL:</strong> <a href='" . $login_link . "'>" . $login_link . "</a></li>
                                       <li><strong>Email:</strong> " . htmlspecialchars($email) . "</li>
                                       <li><strong>Temporary Password:</strong> <span style='font-family: monospace; background: #E7E7E7; padding: 2px 6px;'>" . htmlspecialchars($password) . "</span></li>
                                   </ul>
                                   <p><em>Security Notice: You will be required to change this temporary password immediately upon your first login.</em></p>";
                    
                    $mail_error = '';
                    $mail_sent = send_system_email($email, $email_subject, $email_body, $mail_error);
                    
                    log_system_audit($pdo, "ADMIN_CREATED_USER: " . $email);
                    
                    if ($mail_sent) {
                        $success = "User account successfully created and credentials emailed.";
                    } else {
                        $success = "User account created, but email dispatch failed: " . htmlspecialchars($mail_error);
                    }
                } else {
                    $error = "Failed to create user account.";
                }
            }
        }
    } elseif ($action === 'edit') {
        $user_id = intval($_POST['user_id']);
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $role_id = intval($_POST['role_id']);
        $new_password = trim($_POST['password']);
        
        if (empty($full_name) || empty($email) || empty($user_id)) {
            $error = "Name and email are required.";
        } else {
            if (!empty($new_password)) {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, role_id = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$full_name, $email, $role_id, $password_hash, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, role_id = ? WHERE id = ?");
                $stmt->execute([$full_name, $email, $role_id, $user_id]);
            }
            log_system_audit($pdo, "ADMIN_UPDATED_USER_ID: " . $user_id);
            $success = "User details successfully updated.";
        }
    } elseif ($action === 'toggle_status') {
        $user_id = intval($_POST['user_id']);
        $current_status = intval($_POST['current_status']);
        $new_status = $current_status === 1 ? 0 : 1;
        
        if ($user_id === intval($_SESSION['user_id'])) {
            $error = "Action Denied: You cannot deactivate your own administrator account.";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_status, $user_id]);
            log_system_audit($pdo, "ADMIN_TOGGLED_USER_STATUS_ID: " . $user_id);
            $success = "User status successfully updated.";
        }
    }
}

// Fetch all users and roles
$users = $pdo->query("SELECT u.*, r.role_name, r.role_code FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.created_at DESC")->fetchAll();
$roles = $pdo->query("SELECT * FROM roles")->fetchAll();

$page_title = "Manage Users | LDC System";
include_once __DIR__ . '../../../assets/includes/header.php';
include_once __DIR__ . '../../../assets/includes/sidebar.php';
?>

<style>
    .modal {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5);
        justify-content: center;
        align-items: center;
        z-index: 2000;
    }
    .modal-content {
        background: #ffffff;
        width: 100%;
        max-width: 500px;
        padding: 25px;
        border-radius: 4px;
        border-top: 5px solid var(--red);
    }
</style>
<script>
    function openCreateModal() {
        document.getElementById('modal-title').innerText = "Create New User & Email Credentials";
        document.getElementById('action_type').value = "create";
        document.getElementById('user_id').value = "";
        document.getElementById('full_name').value = "";
        document.getElementById('email').value = "";
        document.getElementById('password').required = true;
        document.getElementById('pass-hint').innerText = "Temporary password to be emailed to the user.";
        document.getElementById('userModal').style.display = 'flex';
    }

    function openEditModal(id, name, email, roleId) {
        document.getElementById('modal-title').innerText = "Edit User Account";
        document.getElementById('action_type').value = "edit";
        document.getElementById('user_id').value = id;
        document.getElementById('full_name').value = name;
        document.getElementById('email').value = email;
        document.getElementById('role_id').value = roleId;
        document.getElementById('password').required = false;
        document.getElementById('pass-hint').innerText = "Leave blank to keep current password.";
        document.getElementById('userModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('userModal').style.display = 'none';
    }
</script>

<main class="main-content">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h2>System Users & Access Control</h2>
            <p style="font-size: 0.85rem; color: var(--slate);">Provision accounts with automated credential dispatch.</p>
        </div>
        <button onclick="openCreateModal()" class="flat-cta" style="cursor: pointer; background: var(--red); color: #fff; padding: 10px 18px; border: none; border-radius: 4px; font-weight: bold;">
            <i class="fa-solid fa-user-plus"></i> Add New User
        </button>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="table-container">
        <div style="padding: 15px; background: var(--charcoal); color: #fff; font-weight: bold; border-bottom: 3px solid var(--red);">
            <i class="fa-solid fa-users"></i> Registered System Users Directory
        </div>
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Email Address</th>
                    <th>Assigned Role</th>
                    <th>Status</th>
                    <th>First Login Reset</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td>
                            <span style="background: var(--pale-charcoal); padding: 3px 8px; border-radius: 3px; font-size: 0.75rem; font-weight: bold;">
                                <?= htmlspecialchars($u['role_name']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['is_active'] == 1): ?>
                                <span style="color: var(--jade); font-weight: bold;"><i class="fa-solid fa-circle-check"></i> Active</span>
                            <?php else: ?>
                                <span style="color: var(--red); font-weight: bold;"><i class="fa-solid fa-circle-xmark"></i> Deactivated</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['must_change_password'] == 1): ?>
                                <span style="color: var(--gold); font-size: 0.8rem; font-weight: bold;"><i class="fa-solid fa-triangle-exclamation"></i> Pending Reset</span>
                            <?php else: ?>
                                <span style="color: var(--slate); font-size: 0.8rem;">Completed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 8px;">
                                <button onclick="openEditModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>', <?= $u['role_id'] ?>)" style="background: var(--ocean); color: #fff; border: none; padding: 6px 10px; border-radius: 3px; cursor: pointer; font-size: 0.8rem;">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </button>
                                
                                <form method="POST" onsubmit="return confirm('Change status for this user?');" style="margin: 0;">
                                    <input type="hidden" name="action_type" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <input type="hidden" name="current_status" value="<?= $u['is_active'] ?>">
                                    <?php if ($u['is_active'] == 1): ?>
                                        <button type="submit" style="background: var(--burgundy); color: #fff; border: none; padding: 6px 10px; border-radius: 3px; cursor: pointer; font-size: 0.8rem;" title="Deactivate">
                                            <i class="fa-solid fa-user-slash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" style="background: var(--jade); color: #fff; border: none; padding: 6px 10px; border-radius: 3px; cursor: pointer; font-size: 0.8rem;" title="Activate">
                                            <i class="fa-solid fa-user-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- Create / Edit User Modal -->
<div id="userModal" class="modal">
    <div class="modal-content">
        <h3 id="modal-title" style="margin-bottom: 15px; color: var(--charcoal);">User Account</h3>
        <form method="POST">
            <input type="hidden" name="action_type" id="action_type" value="create">
            <input type="hidden" name="user_id" id="user_id">

            <div style="margin-bottom: 12px;">
                <label style="font-size: 0.85rem; font-weight: 600;">Full Name</label>
                <input type="text" name="full_name" id="full_name" required style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-size: 0.85rem; font-weight: 600;">Email Address</label>
                <input type="email" name="email" id="email" required style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-size: 0.85rem; font-weight: 600;">System Role</label>
                <select name="role_id" id="role_id" required style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-size: 0.85rem; font-weight: 600;">Password</label>
                <input type="password" name="password" id="password" style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;">
                <small id="pass-hint" style="color: var(--slate); font-size: 0.75rem;"></small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeModal()" style="background: var(--pale-charcoal); border: none; padding: 10px 15px; cursor: pointer; font-weight: 600; border-radius: 3px;">Cancel</button>
                <button type="submit" style="background: var(--red); color: #fff; border: none; padding: 10px 20px; cursor: pointer; font-weight: bold; border-radius: 3px;">Save & Send Credentials</button>
            </div>
        </form>
    </div>
</div>

<?php include_once __DIR__ . '../../../assets/includes/footer.php'; ?>