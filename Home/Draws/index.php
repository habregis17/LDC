<?php
require_once __DIR__ . '/../../assets/config/config.php';
require_once __DIR__ . '/../../assets/config/mailer.php';
check_auth(['PARTNER', 'PRIMARY_VERIFIER', 'ADMIN']);

$error = '';
$success = '';

// 1. Handle Resend Notification / Certificate to Stakeholders (Before HTML Output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_resend_draw'])) {
    $target_draw_id = (int)($_POST['draw_id'] ?? 0);
    $recipient_scope = $_POST['recipient_scope'] ?? 'DEFAULT';
    $extra_emails_raw = trim($_POST['extra_emails'] ?? '');

    $d_stmt = $pdo->prepare("
        SELECT d.*, 
               u.full_name AS officer_name, 
               u.email AS officer_email,
               p.full_name AS partner_name,
               p.email AS partner_email
        FROM draws d
        JOIN users u ON d.primary_verifier_id = u.id
        LEFT JOIN users p ON d.partner_id = p.id
        WHERE d.id = ?
    ");
    $d_stmt->execute([$target_draw_id]);
    $target_draw = $d_stmt->fetch();

    if (!$target_draw) {
        $error = "Error: Selected draw record could not be found.";
    } elseif ($target_draw['status'] === 'IN_PROGRESS') {
        $error = "Notice: Draw " . htmlspecialchars($target_draw['certificate_ref']) . " is still an in-progress draft and cannot be dispatched yet.";
    } else {
        $recipients = []; // email => name

        if ($target_draw['status'] === 'PENDING_PARTNER_APPROVAL') {
            // Pending approval: notify active Partners (and Verifier if requested)
            $partners = $pdo->query("SELECT u.email, u.full_name FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_code = 'PARTNER' AND u.is_active = 1")->fetchAll();
            foreach ($partners as $pt) {
                $recipients[$pt['email']] = $pt['full_name'];
            }
            if ($recipient_scope === 'ALL_STAKEHOLDERS' && !empty($target_draw['officer_email'])) {
                $recipients[$target_draw['officer_email']] = $target_draw['officer_name'];
            }
        } else {
            // CERTIFIED: notify Verifier, Approving Partner, and optionally all Partners/Admins
            if (!empty($target_draw['officer_email'])) {
                $recipients[$target_draw['officer_email']] = $target_draw['officer_name'];
            }
            if (!empty($target_draw['partner_email'])) {
                $recipients[$target_draw['partner_email']] = $target_draw['partner_name'];
            }
            if ($recipient_scope === 'ALL_STAKEHOLDERS') {
                $all_staff = $pdo->query("SELECT u.email, u.full_name FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_code IN ('PARTNER', 'ADMIN') AND u.is_active = 1")->fetchAll();
                foreach ($all_staff as $st) {
                    $recipients[$st['email']] = $st['full_name'];
                }
            }
        }

        // Parse any optional comma/semicolon-separated stakeholder emails
        if ($extra_emails_raw !== '') {
            $parts = preg_split('/[\s,;]+/', $extra_emails_raw, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $raw_email) {
                $clean_email = filter_var(trim($raw_email), FILTER_VALIDATE_EMAIL);
                if ($clean_email) {
                    $recipients[$clean_email] = 'Stakeholder';
                }
            }
        }

        if (empty($recipients)) {
            $error = "Error: No valid recipient email addresses were found for this draw.";
        } else {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
            $base_url_full = $protocol . "://" . $_SERVER['HTTP_HOST'] . BASE_URL;

            $winning_nums = (int)$target_draw['num1_a'] . ", " . (int)$target_draw['num2_a'] . ", " . (int)$target_draw['num3_a'] . ", " . (int)$target_draw['num4_a'] . ", " . (int)$target_draw['num5_a'];
            $sent_count = 0;

            if ($target_draw['status'] === 'CERTIFIED') {
                $cert_link = $base_url_full . "Home/Draws/Certificate/?id=" . urlencode($target_draw['draw_hash_code']);
                $subj = "Copy: Certified Draw Certificate - Ref " . $target_draw['certificate_ref'];
                $body = "<p>Hello,</p>
                         <p>Please find below the official record and certificate link for Certified Lottery Draw <strong>" . htmlspecialchars($target_draw['certificate_ref']) . "</strong>:</p>
                         <ul style='background: #f4f6f9; padding: 14px; border-radius: 4px; list-style-type: none;'>
                             <li><strong>Session:</strong> " . htmlspecialchars($target_draw['draw_session']) . "</li>
                             <li><strong>Draw Date:</strong> " . htmlspecialchars($target_draw['draw_date']) . "</li>
                             <li><strong>Winning Numbers:</strong> " . htmlspecialchars($winning_nums) . "</li>
                             <li><strong>Certified By:</strong> " . htmlspecialchars($target_draw['partner_name'] ?? 'Authorized Partner') . "</li>
                             <li><strong>Verification Hash:</strong> <span style='font-family: monospace;'>" . htmlspecialchars($target_draw['draw_hash_code']) . "</span></li>
                         </ul>
                         <p><a href='" . $cert_link . "' style='background: #E81A3B; color: #fff; padding: 10px 16px; text-decoration: none; border-radius: 4px; font-weight: bold;'>Open Official Certificate</a></p>";
            } else {
                $review_link = $base_url_full . "Home/Draws/Review/?id=" . (int)$target_draw['id'];
                $subj = "Reminder: Pending Draw Approval - Ref " . $target_draw['certificate_ref'];
                $body = "<p>Hello,</p>
                         <p>This is a reminder that Lottery Draw Verification <strong>" . htmlspecialchars($target_draw['certificate_ref']) . "</strong> is awaiting Partner review and e-signature:</p>
                         <ul style='background: #f4f6f9; padding: 14px; border-radius: 4px; list-style-type: none;'>
                             <li><strong>Session:</strong> " . htmlspecialchars($target_draw['draw_session']) . "</li>
                             <li><strong>Draw Date:</strong> " . htmlspecialchars($target_draw['draw_date']) . "</li>
                             <li><strong>Submitted By:</strong> " . htmlspecialchars($target_draw['officer_name']) . "</li>
                         </ul>
                         <p><a href='" . $review_link . "' style='background: #E81A3B; color: #fff; padding: 10px 16px; text-decoration: none; border-radius: 4px; font-weight: bold;'>Review & E-Sign Draw</a></p>";
            }

            foreach ($recipients as $email_addr => $rec_name) {
                if (send_system_email($email_addr, $subj, $body)) {
                    $sent_count++;
                }
            }

            if ($sent_count > 0) {
                $audit_msg = mb_substr("RESENT_DRAW_EMAIL_" . $target_draw['certificate_ref'] . "_TO_" . $sent_count . "_RECIPIENTS", 0, 100);
                log_system_audit($pdo, $audit_msg, $target_draw_id);
                $success = "Dispatched draw notification for <strong>" . htmlspecialchars($target_draw['certificate_ref']) . "</strong> to <strong>" . $sent_count . "</strong> stakeholder(s).";
            } else {
                $error = "SMTP Error: Unable to send email notifications. Please verify mail server settings.";
            }
        }
    }
}

// 2. Search, Filter & Pagination Parameters
$search_q     = trim($_GET['q'] ?? '');
$status_f     = trim($_GET['status'] ?? '');
$date_from    = trim($_GET['date_from'] ?? '');
$date_to      = trim($_GET['date_to'] ?? '');
$verifier_f   = (int)($_GET['verifier_id'] ?? 0);
$per_page     = (int)($_GET['per_page'] ?? 15);
if (!in_array($per_page, [10, 15, 25, 50], true)) {
    $per_page = 15;
}
$page = max(1, (int)($_GET['page'] ?? 1));

$where_clauses = [];
$params = [];

if ($search_q !== '') {
    $where_clauses[] = "(d.certificate_ref LIKE :q OR d.draw_session LIKE :q OR d.venue_location LIKE :q OR d.draw_hash_code LIKE :q OR u.full_name LIKE :q OR p.full_name LIKE :q)";
    $params[':q'] = '%' . $search_q . '%';
}

$allowed_statuses = ['CERTIFIED', 'PENDING_PARTNER_APPROVAL', 'IN_PROGRESS'];
if ($status_f !== '' && in_array($status_f, $allowed_statuses, true)) {
    $where_clauses[] = "d.status = :status";
    $params[':status'] = $status_f;
}

if ($date_from !== '') {
    $where_clauses[] = "d.draw_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if ($date_to !== '') {
    $where_clauses[] = "d.draw_date <= :date_to";
    $params[':date_to'] = $date_to;
}

if ($verifier_f > 0) {
    $where_clauses[] = "d.primary_verifier_id = :verifier_id";
    $params[':verifier_id'] = $verifier_f;
}

$where_sql = !empty($where_clauses) ? ('WHERE ' . implode(' AND ', $where_clauses)) : '';

// Summary KPI Counts across all draws
$stats = $pdo->query("
    SELECT 
        COUNT(*) AS total_all,
        SUM(CASE WHEN status = 'CERTIFIED' THEN 1 ELSE 0 END) AS total_certified,
        SUM(CASE WHEN status = 'PENDING_PARTNER_APPROVAL' THEN 1 ELSE 0 END) AS total_pending,
        SUM(CASE WHEN status = 'IN_PROGRESS' THEN 1 ELSE 0 END) AS total_drafts
    FROM draws
")->fetch();

// Total matching records for pagination
$count_sql = "
    SELECT COUNT(*) 
    FROM draws d
    JOIN users u ON d.primary_verifier_id = u.id
    LEFT JOIN users p ON d.partner_id = p.id
    $where_sql
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

// Fetch paginated draws
$data_sql = "
    SELECT d.*, 
           u.full_name AS officer_name, 
           u.email AS officer_email,
           p.full_name AS partner_name
    FROM draws d
    JOIN users u ON d.primary_verifier_id = u.id
    LEFT JOIN users p ON d.partner_id = p.id
    $where_sql
    ORDER BY d.draw_date DESC, d.id DESC
    LIMIT :limit OFFSET :offset
";
$data_stmt = $pdo->prepare($data_sql);
foreach ($params as $key => $val) {
    $data_stmt->bindValue($key, $val);
}
$data_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$data_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$data_stmt->execute();
$draws = $data_stmt->fetchAll();

// Fetch active verifiers for filter dropdown
$verifiers_list = $pdo->query("
    SELECT DISTINCT u.id, u.full_name 
    FROM users u 
    JOIN draws d ON d.primary_verifier_id = u.id 
    ORDER BY u.full_name ASC
")->fetchAll();

// Helper to preserve active filter query parameters across pagination & quick-filter tabs
function build_history_url(array $overrides = []) {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $k => $v) {
        if ($v === '' || $v === null) {
            unset($query[$k]);
        }
    }
    return '?' . http_build_query($query);
}

$page_title = "Draws History & Certificates | LDC System";
include_once __DIR__ . '/../../assets/includes/header.php';
include_once __DIR__ . '/../../assets/includes/sidebar.php';
?>

<style>
    .history-kpi-strip {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 20px;
    }
    @media (max-width: 900px) {
        .history-kpi-strip { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 520px) {
        .history-kpi-strip { grid-template-columns: 1fr; }
    }
    .kpi-tile {
        background: #ffffff;
        border: 1px solid var(--pale-charcoal);
        border-radius: 4px;
        padding: 14px 16px;
        text-decoration: none;
        color: var(--charcoal);
        display: block;
        transition: border-color 0.15s ease;
    }
    .kpi-tile:hover {
        border-color: var(--red);
    }
    .kpi-tile.active-filter {
        background: #fdf6f7;
        border-color: var(--red);
    }
    .filter-card {
        background: #ffffff;
        border: 1px solid var(--pale-charcoal);
        border-radius: 4px;
        padding: 16px;
        margin-bottom: 18px;
    }
    .filter-grid {
        display: grid;
        grid-template-columns: 2fr 1.1fr 1fr 1fr 1.2fr auto;
        gap: 10px;
        align-items: end;
    }
    @media (max-width: 1100px) {
        .filter-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 600px) {
        .filter-grid { grid-template-columns: 1fr; }
    }
    .filter-input {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid var(--pale-charcoal);
        border-radius: 4px;
        font-size: 0.85rem;
        margin-top: 4px;
    }
    .mini-ball {
        display: inline-flex;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background-color: var(--red);
        color: #ffffff;
        font-weight: 700;
        font-size: 0.78rem;
        align-items: center;
        justify-content: center;
        box-shadow: inset -2px -2px 4px rgba(0,0,0,0.35);
    }
    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 9px;
        border-radius: 3px;
        font-size: 0.74rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .pill-certified { background: #e6f4ea; color: var(--jade); border: 1px solid #b7dfc3; }
    .pill-pending   { background: #fef7e0; color: #9a6700; border: 1px solid #f5d98e; }
    .pill-draft     { background: #f1f3f4; color: var(--slate); border: 1px solid #d2d6dc; }

    .action-btn-sm {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 9px;
        border-radius: 3px;
        font-size: 0.76rem;
        font-weight: 700;
        text-decoration: none;
        border: none;
        cursor: pointer;
        white-space: nowrap;
    }
    .pagination-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 16px;
        background: #ffffff;
        border: 1px solid var(--pale-charcoal);
        border-top: none;
        border-radius: 0 0 4px 4px;
        flex-wrap: wrap;
        gap: 10px;
        font-size: 0.84rem;
    }
    .page-link {
        display: inline-block;
        padding: 5px 10px;
        border: 1px solid var(--pale-charcoal);
        border-radius: 3px;
        text-decoration: none;
        color: var(--charcoal);
        font-weight: 600;
        margin-left: 3px;
    }
    .page-link.active {
        background: var(--red);
        color: #ffffff;
        border-color: var(--red);
    }
    .page-link.disabled {
        opacity: 0.45;
        pointer-events: none;
    }

    /* Resend Modal */
    .resend-modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(25, 28, 36, 0.6);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .resend-modal-card {
        background: #ffffff;
        width: 100%;
        max-width: 460px;
        border-radius: 4px;
        border-top: 5px solid var(--red);
        padding: 22px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    }
</style>

<main class="main-content">
    <div style="background: var(--charcoal); color: #ffffff; padding: 20px; border-radius: 4px; margin-bottom: 20px; border-left: 6px solid var(--red); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 style="margin: 0;"><i class="fa-solid fa-clock-rotate-left"></i> Lottery Draws History &amp; Certificates Archive</h2>
            <p style="font-size: 0.85rem; color: var(--pale-charcoal); margin: 4px 0 0;">Search, filter, inspect full audit details, open official certificates, or re-dispatch notifications to stakeholders.</p>
        </div>
        <?php if (in_array($_SESSION['role_code'] ?? '', ['PRIMARY_VERIFIER', 'ADMIN'], true)): ?>
            <a href="javascript:void(0);" onclick="openAddDrawModal();" style="background: var(--red); color: #ffffff; padding: 10px 16px; border-radius: 4px; text-decoration: none; font-weight: 700; font-size: 0.85rem;">
                <i class="fa-solid fa-plus"></i> New Draw Session
            </a>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <!-- Quick-Filter Status Summary Strip -->
    <div class="history-kpi-strip">
        <a href="<?= build_history_url(['status' => '', 'page' => 1]) ?>" class="kpi-tile <?= $status_f === '' ? 'active-filter' : '' ?>" style="border-left: 4px solid var(--charcoal);">
            <div style="font-size: 0.75rem; font-weight: 700; color: var(--slate); text-transform: uppercase;">All Recorded Draws</div>
            <div style="font-size: 1.55rem; font-weight: 800; margin-top: 4px;"><?= (int)($stats['total_all'] ?? 0) ?></div>
        </a>
        <a href="<?= build_history_url(['status' => 'CERTIFIED', 'page' => 1]) ?>" class="kpi-tile <?= $status_f === 'CERTIFIED' ? 'active-filter' : '' ?>" style="border-left: 4px solid var(--jade);">
            <div style="font-size: 0.75rem; font-weight: 700; color: var(--jade); text-transform: uppercase;">Certified Draws</div>
            <div style="font-size: 1.55rem; font-weight: 800; margin-top: 4px;"><?= (int)($stats['total_certified'] ?? 0) ?></div>
        </a>
        <a href="<?= build_history_url(['status' => 'PENDING_PARTNER_APPROVAL', 'page' => 1]) ?>" class="kpi-tile <?= $status_f === 'PENDING_PARTNER_APPROVAL' ? 'active-filter' : '' ?>" style="border-left: 4px solid var(--gold);">
            <div style="font-size: 0.75rem; font-weight: 700; color: #9a6700; text-transform: uppercase;">Pending Partner Approval</div>
            <div style="font-size: 1.55rem; font-weight: 800; margin-top: 4px;"><?= (int)($stats['total_pending'] ?? 0) ?></div>
        </a>
        <a href="<?= build_history_url(['status' => 'IN_PROGRESS', 'page' => 1]) ?>" class="kpi-tile <?= $status_f === 'IN_PROGRESS' ? 'active-filter' : '' ?>" style="border-left: 4px solid var(--slate);">
            <div style="font-size: 0.75rem; font-weight: 700; color: var(--slate); text-transform: uppercase;">In-Progress Drafts</div>
            <div style="font-size: 1.55rem; font-weight: 800; margin-top: 4px;"><?= (int)($stats['total_drafts'] ?? 0) ?></div>
        </a>
    </div>

    <!-- Search & Filter Bar -->
    <form method="GET" class="filter-card">
        <div class="filter-grid">
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--charcoal);">Search Reference, Session, Hash, or Staff</label>
                <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="e.g. LDC-2026, Evening Draw, Officer name..." class="filter-input">
            </div>
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--charcoal);">Status</label>
                <select name="status" class="filter-input">
                    <option value="">All Statuses</option>
                    <option value="CERTIFIED" <?= $status_f === 'CERTIFIED' ? 'selected' : '' ?>>Certified</option>
                    <option value="PENDING_PARTNER_APPROVAL" <?= $status_f === 'PENDING_PARTNER_APPROVAL' ? 'selected' : '' ?>>Pending Partner Approval</option>
                    <option value="IN_PROGRESS" <?= $status_f === 'IN_PROGRESS' ? 'selected' : '' ?>>In-Progress Draft</option>
                </select>
            </div>
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--charcoal);">From Date</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="filter-input">
            </div>
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--charcoal);">To Date</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="filter-input">
            </div>
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--charcoal);">Verification Officer</label>
                <select name="verifier_id" class="filter-input">
                    <option value="0">All Officers</option>
                    <?php foreach ($verifiers_list as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $verifier_f === (int)$v['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; gap: 6px;">
                <button type="submit" style="background: var(--charcoal); color: #ffffff; border: none; padding: 9px 14px; border-radius: 4px; font-weight: 700; font-size: 0.84rem; cursor: pointer;">
                    <i class="fa-solid fa-magnifying-glass"></i> Filter
                </button>
                <a href="index.php" style="background: var(--pale-charcoal); color: var(--charcoal); padding: 9px 12px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.84rem;" title="Reset Filters">
                    Reset
                </a>
            </div>
        </div>
    </form>

<!-- Indexed Draws Table -->
    <div style="background: #ffffff; border: 1px solid var(--pale-charcoal); border-radius: 4px 4px 0 0; overflow-x: auto;">
        <?php
        $role_code = strtoupper((string)($_SESSION['role_code'] ?? ($_SESSION['user_role'] ?? '')));

        $visible_draws = array_values(array_filter($draws ?? [], function ($draw) use ($role_code) {
            $status = strtoupper((string)($draw['status'] ?? ''));
            $has_partner_sig = !empty($draw['partner_signed_at'])
                || !empty($draw['partner_signature'])
                || !empty($draw['signed_at'])
                || !empty($draw['partner_name']);

            if ($role_code === 'PARTNER') {
                return $status === 'PENDING_PARTNER_APPROVAL';
            }

            if ($role_code === 'STAKEHOLDER') {
                return ($status === 'CERTIFIED') || $has_partner_sig;
            }

            return true;
        }));
        ?>

        <table class="custom-table" style="margin: 0; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 48px;">#</th>
                    <th>Certificate Ref &amp; Session</th>
                    <th>Draw Date &amp; Window</th>
                    <th>Winning Numbers</th>
                    <th>Verification &amp; Sign-Off</th>
                    <th>Status</th>
                    <th style="text-align: right;">Quick Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($visible_draws)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 28px; color: var(--slate);">
                            <i class="fa-solid fa-folder-open fa-lg" style="margin-bottom: 6px; display: block;"></i>
                            <?php if ($role_code === 'PARTNER'): ?>
                                No draw records pending partner review match your current search or filter criteria.
                            <?php elseif ($role_code === 'STAKEHOLDER'): ?>
                                No signed draw records match your current search or filter criteria.
                            <?php else: ?>
                                No draw records match your current search or filter criteria.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($visible_draws as $vis_idx => $d): ?>
                        <?php $row_num = ($offset ?? 0) + $vis_idx + 1; ?>
                        <tr>
                            <td style="color: var(--slate); font-weight: 700; font-size: 0.82rem;"><?= $row_num ?></td>
                            <td>
                                <div style="font-weight: 800; color: var(--burgundy); font-size: 0.9rem;">
                                    <?= htmlspecialchars($d['certificate_ref']) ?>
                                </div>
                                <div style="font-size: 0.83rem; font-weight: 600; color: var(--charcoal);">
                                    <?= htmlspecialchars($d['draw_session']) ?>
                                </div>
                                <div style="font-size: 0.72rem; color: var(--slate); font-family: monospace;">
                                    Hash: <?= htmlspecialchars(substr((string)$d['draw_hash_code'], 0, 12)) ?>...
                                </div>
                            </td>
                            <td style="font-size: 0.83rem;">
                                <strong><?= htmlspecialchars(date('d M Y', strtotime($d['draw_date']))) ?></strong><br>
                                <span style="color: var(--slate); font-size: 0.78rem;">
                                    <?= !empty($d['start_time']) ? htmlspecialchars(date('H:i:s', strtotime($d['start_time']))) : '--:--' ?>
                                    &ndash;
                                    <?= !empty($d['end_time']) ? htmlspecialchars(date('H:i:s', strtotime($d['end_time']))) : 'In Progress' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($d['status'] === 'IN_PROGRESS' && $d['num1_a'] === null): ?>
                                    <span style="font-size: 0.78rem; color: var(--slate); font-style: italic;">Awaiting entry</span>
                                <?php else: ?>
                                    <div style="display: flex; gap: 5px; flex-wrap: nowrap;">
                                        <span class="mini-ball"><?= (int)$d['num1_a'] ?></span>
                                        <span class="mini-ball"><?= (int)$d['num2_a'] ?></span>
                                        <span class="mini-ball"><?= (int)$d['num3_a'] ?></span>
                                        <span class="mini-ball"><?= (int)$d['num4_a'] ?></span>
                                        <span class="mini-ball"><?= (int)$d['num5_a'] ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.8rem;">
                                <div><span style="color: var(--slate);">Verifier:</span> <strong><?= htmlspecialchars($d['officer_name']) ?></strong></div>
                                <?php if (!empty($d['partner_name'])): ?>
                                    <div><span style="color: var(--jade);">Partner:</span> <strong><?= htmlspecialchars($d['partner_name']) ?></strong></div>
                                <?php else: ?>
                                    <div style="color: var(--slate); font-size: 0.75rem;">Partner: Pending</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($d['status'] === 'CERTIFIED'): ?>
                                    <span class="status-pill pill-certified"><i class="fa-solid fa-circle-check"></i> Certified</span>
                                <?php elseif ($d['status'] === 'PENDING_PARTNER_APPROVAL'): ?>
                                    <span class="status-pill pill-pending"><i class="fa-solid fa-clock"></i> Pending Approval</span>
                                <?php else: ?>
                                    <span class="status-pill pill-draft"><i class="fa-solid fa-pen-to-square"></i> Draft</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap;">
                                    <?php
                                    $cert_hash    = trim((string)($d['draw_hash_code'] ?? ''));
                                    $is_certified = (($d['status'] ?? '') === 'CERTIFIED');
                                    ?>

                                    <?php if ($is_certified && $cert_hash !== ''): ?>
                                        <a href="<?= BASE_URL ?>Home/Draws/Certificate/pdf.php?id=<?= urlencode($cert_hash) ?>&mode=inline" target="_blank" class="action-btn-sm" style="background: var(--burgundy); color: #ffffff;">
                                            <i class="fa-solid fa-file-pdf"></i> View PDF
                                        </a>
                                        <a href="<?= BASE_URL ?>Home/Draws/Certificate/pdf.php?id=<?= urlencode($cert_hash) ?>&mode=download" class="action-btn-sm" style="background: var(--gold); color: #ffffff;">
                                            <i class="fa-solid fa-download"></i> Download PDF
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($d['status'] !== 'IN_PROGRESS'): ?>
                                        <a href="<?= BASE_URL ?>Home/Draws/Review/?id=<?= urlencode((string)$d['draw_hash_code']) ?>" class="action-btn-sm" style="background: var(--ocean); color: #ffffff;" title="Inspect Full Details & Audit Trail">
                                            <i class="fa-solid fa-eye"></i> Details
                                        </a>

                                        <button type="button"
                                                class="action-btn-sm"
                                                style="background: var(--charcoal); color: #ffffff;"
                                                onclick="openResendModal(<?= (int)$d['id'] ?>, '<?= htmlspecialchars((string)$d['certificate_ref'], ENT_QUOTES) ?>', '<?= htmlspecialchars((string)$d['status'], ENT_QUOTES) ?>')"
                                                title="Resend Notification or Certificate via Email">
                                            <i class="fa-solid fa-paper-plane"></i> Resend
                                        </button>
                                    <?php else: ?>
                                        <a href="<?= BASE_URL ?>Home/Draws/Initiate/?hash=<?= urlencode((string)$d['draw_hash_code']) ?>" class="action-btn-sm" style="background: var(--gold); color: #ffffff;">
                                            <i class="fa-solid fa-play"></i> Resume
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination & Page-Size Bar -->
    <div class="pagination-bar">
        <div style="color: var(--slate);">
            Showing <strong><?= $total_rows > 0 ? ($offset + 1) : 0 ?></strong> to <strong><?= min($offset + $per_page, $total_rows) ?></strong> of <strong><?= $total_rows ?></strong> draw(s)
        </div>

        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <form method="GET" style="display: inline-flex; align-items: center; gap: 6px; margin: 0;">
                <?php foreach ($_GET as $gk => $gv): ?>
                    <?php if ($gk !== 'per_page' && $gk !== 'page'): ?>
                        <input type="hidden" name="<?= htmlspecialchars($gk) ?>" value="<?= htmlspecialchars($gv) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <label style="font-size: 0.8rem; color: var(--slate);">Rows:</label>
                <select name="per_page" onchange="this.form.submit()" style="padding: 4px 6px; border: 1px solid var(--pale-charcoal); border-radius: 3px; font-size: 0.8rem;">
                    <?php foreach ([10, 15, 25, 50] as $sz): ?>
                        <option value="<?= $sz ?>" <?= $per_page === $sz ? 'selected' : '' ?>><?= $sz ?></option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div>
                <a href="<?= build_history_url(['page' => max(1, $page - 1)]) ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">&laquo; Prev</a>
                <?php
                $start_p = max(1, $page - 2);
                $end_p = min($total_pages, $page + 2);
                for ($p_i = $start_p; $p_i <= $end_p; $p_i++):
                ?>
                    <a href="<?= build_history_url(['page' => $p_i]) ?>" class="page-link <?= $p_i === $page ? 'active' : '' ?>"><?= $p_i ?></a>
                <?php endfor; ?>
                <a href="<?= build_history_url(['page' => min($total_pages, $page + 1)]) ?>" class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>">Next &raquo;</a>
            </div>
        </div>
    </div>
</main>
    <!-- Add Draw Session Date Modal -->
<div id="add-draw-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; width:100%; max-width:430px; border-radius:6px; border-top:5px solid var(--red); padding:22px; box-shadow:0 12px 30px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 style="margin:0; color:var(--charcoal); font-size:1.05rem;">
                <i class="fa-solid fa-calendar-plus"></i> Select Draw Date
            </h3>
            <button type="button" onclick="closeAddDrawModal()" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--slate);">&times;</button>
        </div>

        <p style="font-size:0.82rem; color:var(--slate); margin:0 0 14px;">
            Choose the draw date first. We will create the draw record, generate its hashcode, and open the verification page with the date locked.
        </p>

        <div style="margin-bottom:14px;">
            <label for="modal_draw_date" style="display:block; font-size:0.85rem; font-weight:700; color:var(--charcoal); margin-bottom:5px;">
                Draw Date
            </label>
            <input type="date" id="modal_draw_date" value="<?= date('Y-m-d') ?>" required
                   style="width:100%; padding:9px; border:1px solid var(--pale-charcoal); border-radius:4px; font-size:0.9rem;">
        </div>

        <div style="margin-bottom:18px;">
            <label for="modal_draw_session" style="display:block; font-size:0.85rem; font-weight:700; color:var(--charcoal); margin-bottom:5px;">
                Draw Session Name <span style="font-weight:400; color:var(--slate);">(optional now)</span>
            </label>
            <input type="text" id="modal_draw_session" placeholder="e.g. Intego 5/90 Evening Main Draw"
                   style="width:100%; padding:9px; border:1px solid var(--pale-charcoal); border-radius:4px; font-size:0.9rem;">
        </div>

        <div id="modal-draw-error" style="display:none; background:#fdecea; color:#842029; border:1px solid #f5c2c7; padding:8px 10px; border-radius:4px; font-size:0.8rem; margin-bottom:14px;"></div>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" onclick="closeAddDrawModal()" style="background:var(--pale-charcoal); color:var(--charcoal); border:none; padding:9px 14px; border-radius:4px; font-weight:600; cursor:pointer;">
                Cancel
            </button>
            <button type="button" id="modal-create-draw-btn" onclick="submitPrecreateDraw()" style="background:var(--red); color:#fff; border:none; padding:9px 16px; border-radius:4px; font-weight:700; cursor:pointer;">
                <i class="fa-solid fa-arrow-right"></i> Create &amp; Open Initiate
            </button>
        </div>
    </div>
</div>

<script>
function openAddDrawModal() {
    const modal = document.getElementById('add-draw-modal');
    const errBox = document.getElementById('modal-draw-error');
    if (errBox) {
        errBox.style.display = 'none';
        errBox.textContent = '';
    }
    if (modal) modal.style.display = 'flex';
}

function closeAddDrawModal() {
    const modal = document.getElementById('add-draw-modal');
    if (modal) modal.style.display = 'none';
}

function submitPrecreateDraw() {
    const dateEl = document.getElementById('modal_draw_date');
    const sessEl = document.getElementById('modal_draw_session');
    const btn = document.getElementById('modal-create-draw-btn');
    const errBox = document.getElementById('modal-draw-error');

    const chosenDate = dateEl ? dateEl.value.trim() : '';
    const sessionName = sessEl ? sessEl.value.trim() : '';

    if (!chosenDate) {
        if (errBox) {
            errBox.textContent = 'Please select a draw date.';
            errBox.style.display = 'block';
        }
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating...';
    }

    const fd = new FormData();
    fd.append('ajax_action', 'precreate_draw');
    fd.append('draw_date', chosenDate);
    fd.append('draw_session', sessionName);

    fetch('<?= BASE_URL ?>Home/Draws/Initiate/', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    })
    .then(async res => {
        const txt = await res.text();
        try {
            return JSON.parse(txt);
        } catch (e) {
            throw new Error(txt.substring(0, 160) || 'Invalid server response');
        }
    })
    .then(data => {
        if (data.status === 'success' && data.draw_hash_code) {
            window.location.href = '<?= BASE_URL ?>Home/Draws/Initiate/?hash=' + encodeURIComponent(data.draw_hash_code);
        } else {
            throw new Error(data.message || 'Could not pre-create draw.');
        }
    })
    .catch(err => {
        if (errBox) {
            errBox.textContent = err.message;
            errBox.style.display = 'block';
        }
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-arrow-right"></i> Create &amp; Open Initiate';
        }
    });
}
</script>

<!-- Stakeholder Resend Modal -->
<div id="resend-modal" class="resend-modal-backdrop" onclick="if(event.target === this) closeResendModal();">
    <div class="resend-modal-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <h3 style="margin: 0; color: var(--charcoal); font-size: 1.05rem;">
                <i class="fa-solid fa-paper-plane" style="color: var(--red);"></i> Resend Draw Dispatch
            </h3>
            <button type="button" onclick="closeResendModal()" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--slate);">&times;</button>
        </div>

        <p style="font-size: 0.84rem; color: var(--slate); margin-bottom: 14px;">
            Re-send the official notification / certificate link for Reference <strong id="resend-ref-label" style="color: var(--burgundy);"></strong>.
        </p>

        <form method="POST">
            <input type="hidden" name="draw_id" id="resend-draw-id">

            <div style="margin-bottom: 12px;">
                <label style="font-size: 0.82rem; font-weight: 700; color: var(--charcoal);">Target Stakeholders</label>
                <select name="recipient_scope" id="resend-scope" class="filter-input">
                    <option value="DEFAULT">Primary Stakeholders (Verifier &amp; Approving / Active Partners)</option>
                    <option value="ALL_STAKEHOLDERS">All Active Partners, Admins &amp; Verifier</option>
                </select>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="font-size: 0.82rem; font-weight: 700; color: var(--charcoal);">Additional Stakeholder Emails (Optional)</label>
                <input type="text" name="extra_emails" placeholder="e.g. compliance@client.rw, audit@bdo-ea.com" class="filter-input">
                <small style="color: var(--slate); font-size: 0.74rem;">Separate multiple email addresses with commas.</small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" onclick="closeResendModal()" style="background: var(--pale-charcoal); color: var(--charcoal); border: none; padding: 9px 14px; border-radius: 4px; font-weight: 600; cursor: pointer;">
                    Cancel
                </button>
                <button type="submit" name="action_resend_draw" style="background: var(--red); color: #ffffff; border: none; padding: 9px 16px; border-radius: 4px; font-weight: 700; cursor: pointer;">
                    <i class="fa-solid fa-envelope"></i> Send Now
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openResendModal(drawId, refCode, status) {
        document.getElementById('resend-draw-id').value = drawId;
        document.getElementById('resend-ref-label').textContent = refCode;
        document.getElementById('resend-modal').style.display = 'flex';
    }

    function closeResendModal() {
        document.getElementById('resend-modal').style.display = 'none';
    }
</script>

<?php include_once __DIR__ . '/../../assets/includes/footer.php'; ?>
 