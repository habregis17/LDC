<?php
require_once __DIR__ . '/../../../assets/config/config.php';
require_once __DIR__ . '/../../../assets/config/mailer.php';
require_once __DIR__ . '/../../../assets/includes/certificate_pdf_maker.php';

check_auth(['PARTNER', 'ADMIN', 'PRIMARY_VERIFIER']);

$draw_id  = trim((string)($_GET['id'] ?? ''));
$error    = '';
$success  = '';
$mfa_step = $_SESSION['partner_mfa_step'] ?? 1;

// Fetch draw details along with Primary Verifier and Partner info
$stmt = $pdo->prepare("
    SELECT d.*, 
           u.full_name AS officer_name, 
           u.email AS officer_email,
           p.full_name AS partner_name,
           p.email AS partner_email
    FROM draws d
    JOIN users u ON d.primary_verifier_id = u.id
    LEFT JOIN users p ON d.partner_id = p.id
    WHERE d.draw_hash_code = ?
    LIMIT 1
");
$stmt->execute([$draw_id]);
$draw = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$draw) {
    die("Error: Draw verification record not found.");
}

// Fetch current user details if not yet in session
if (empty($_SESSION['user_email']) || empty($_SESSION['user_name'])) {
    $u_stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
    $u_stmt->execute([$_SESSION['user_id']]);
    $u_data = $u_stmt->fetch(PDO::FETCH_ASSOC);
    if ($u_data) {
        $_SESSION['user_email'] = $u_data['email'];
        $_SESSION['user_name']  = $u_data['full_name'];
    }
}

$sets_match = (
    (int)$draw['num1_a'] === (int)$draw['num1_b'] &&
    (int)$draw['num2_a'] === (int)$draw['num2_b'] &&
    (int)$draw['num3_a'] === (int)$draw['num3_b'] &&
    (int)$draw['num4_a'] === (int)$draw['num4_b'] &&
    (int)$draw['num5_a'] === (int)$draw['num5_b']
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $draw['status'] !== 'CERTIFIED') {
    if (isset($_POST['action_request_mfa'])) {
        $partner_notes = trim((string)($_POST['partner_notes'] ?? ''));
        $_SESSION['pending_partner_notes'] = $partner_notes;

        $mfa_code = (string)random_int(100000, 999999);
        $expires  = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $pdo->prepare("INSERT INTO mfa_codes (user_id, action_type, code, expires_at) VALUES (?, 'APPROVE_DRAW', ?, ?)")
            ->execute([$_SESSION['user_id'], $mfa_code, $expires]);

        $subj = "LDC E-Signature Security Code - Ref " . $draw['certificate_ref'];
        $body = "<p>Hello <strong>" . htmlspecialchars((string)$_SESSION['user_name']) . "</strong>,</p>
                 <p>You requested to e-sign and certify Lottery Draw Verification <strong>" . htmlspecialchars((string)$draw['certificate_ref']) . "</strong> (" . htmlspecialchars((string)$draw['draw_session']) . " on " . htmlspecialchars((string)$draw['draw_date']) . ").</p>
                 <p>Enter this 6-digit verification code on the review screen to complete certification:</p>
                 <div style='text-align: center; margin: 20px 0;'>
                     <span style='font-size: 2rem; font-weight: bold; color: #E81A3B; background: #E7E7E7; padding: 10px 22px; letter-spacing: 5px; border-radius: 4px;'>" . $mfa_code . "</span>
                 </div>
                 <p>This code expires in 10 minutes.</p>";

        $mail_err = '';
        if (send_system_email((string)$_SESSION['user_email'], $subj, $body, $mail_err)) {
            $_SESSION['partner_mfa_step'] = 2;
            $mfa_step = 2;
            log_system_audit($pdo, "PARTNER_REQUESTED_APPROVAL_MFA_REF_" . $draw['certificate_ref'], $draw_id);
            $success = "A 6-digit approval verification code has been sent to " . htmlspecialchars((string)$_SESSION['user_email']) . ".";
        } else {
            $error = "Failed to send verification code email: " . htmlspecialchars($mail_err);
        }
    } elseif (isset($_POST['action_confirm_approve'])) {
        $code          = trim((string)($_POST['mfa_code'] ?? ''));
        $partner_notes = trim((string)($_POST['partner_notes'] ?? ($_SESSION['pending_partner_notes'] ?? '')));

        $mfa_chk = $pdo->prepare("
            SELECT * FROM mfa_codes 
            WHERE user_id = ? AND action_type = 'APPROVE_DRAW' AND code = ? AND is_used = 0 AND expires_at > NOW() 
            ORDER BY id DESC LIMIT 1
        ");
        $mfa_chk->execute([$_SESSION['user_id'], $code]);
        $valid_code = $mfa_chk->fetch(PDO::FETCH_ASSOC);

        if (!$valid_code) {
            $error = "Invalid or expired security code. Please check your email and try again.";
            $mfa_step = 2;
        } else {
            $pdo->beginTransaction();
            try {
                // Mark MFA code as used
                $pdo->prepare("UPDATE mfa_codes SET is_used = 1 WHERE id = ?")->execute([$valid_code['id']]);

                // Update draw record to CERTIFIED and lock partner e-signature metadata
                $upd = $pdo->prepare("
                    UPDATE draws 
                    SET status = 'CERTIFIED', 
                        partner_id = ?, 
                        partner_signed_at = NOW()
                    WHERE draw_hash_code = ?
                ");
                $upd->execute([$_SESSION['user_id'], $draw_id]);

                // Record detailed audit trail
                $audit_details = "PARTNER_CERTIFIED_DRAW_REF_" . $draw['certificate_ref'];
                if ($partner_notes !== '') {
                    $audit_details .= " | NOTES: " . $partner_notes;
                }
                log_system_audit($pdo, $audit_details, $draw_id, ($draw['gps_latitude'] ?? '0') . ',' . ($draw['gps_longitude'] ?? '0'));

                $pdo->commit();

                // Fetch active STAKEHOLDER emails from users + roles
                $stakeholder_stmt = $pdo->prepare("
                    SELECT DISTINCT u.email, u.full_name
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    WHERE r.role_code = 'STAKEHOLDER'
                      AND u.is_active = 1
                      AND u.email IS NOT NULL
                      AND u.email != ''
                ");
                $stakeholder_stmt->execute();
                $stakeholder_rows = $stakeholder_stmt->fetchAll(PDO::FETCH_ASSOC);

                $to_recipients = [];
                foreach ($stakeholder_rows as $sh) {
                    $sh_mail = trim((string)($sh['email'] ?? ''));
                    if ($sh_mail !== '' && filter_var($sh_mail, FILTER_VALIDATE_EMAIL)) {
                        $to_recipients[] = $sh_mail;
                    }
                }
                $to_recipients = array_values(array_unique($to_recipients));

                // CC Primary Verifier and Partner
                $cc_recipients = [];
                foreach ([($draw['officer_email'] ?? ''), ($_SESSION['user_email'] ?? '')] as $cc_candidate) {
                    $cc_mail = trim((string)$cc_candidate);
                    if ($cc_mail !== '' && filter_var($cc_mail, FILTER_VALIDATE_EMAIL) && !in_array($cc_mail, $to_recipients, true)) {
                        $cc_recipients[] = $cc_mail;
                    }
                }
                $cc_recipients = array_values(array_unique($cc_recipients));

                // If no stakeholder rows exist, make verifier/partner the primary TO so notification still sends
                if (empty($to_recipients) && !empty($cc_recipients)) {
                    $to_recipients = [array_shift($cc_recipients)];
                }

                // Generate PDF binary using certificate_pdf_maker.php
                $protocol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                $cert_link  = $protocol . "://" . $_SERVER['HTTP_HOST'] . BASE_URL . "Home/Draws/Certificate/index.php?id=" . urlencode($draw_id);
                $verify_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . BASE_URL . "verify.php?ref=" . urlencode((string)$draw['certificate_ref']) . "&hash=" . urlencode((string)$draw['draw_hash_code']);

                $certified_draw = fetch_certified_draw($pdo, $draw_id) ?: $draw;
                $pdf_binary     = render_draw_certificate_pdf($certified_draw, $verify_url, 'string') ?? '';
                $safe_ref       = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string)$draw['certificate_ref']);
                $pdf_filename   = 'BDO_Draw_Certificate_' . $safe_ref . '.pdf';

                $winning_nums_display = (int)$draw['num1_a'] . ", " . (int)$draw['num2_a'] . ", " . (int)$draw['num3_a'] . ", " . (int)$draw['num4_a'] . ", " . (int)$draw['num5_a'];
                $cert_subject = "Draw Certified - Certificate Ref " . $draw['certificate_ref'];
                $cert_body    = "<p>Hello,</p>
                              <p>Lottery Draw Verification <strong>" . htmlspecialchars((string)$draw['certificate_ref']) . "</strong> has been officially <strong>CERTIFIED</strong> by Partner <strong>" . htmlspecialchars((string)$_SESSION['user_name']) . "</strong>.</p>
                              <ul style='background: #f4f6f9; padding: 14px; border-radius: 4px; list-style-type: none;'>
                                  <li><strong>Draw Session:</strong> " . htmlspecialchars((string)$draw['draw_session']) . "</li>
                                  <li><strong>Draw Date:</strong> " . htmlspecialchars((string)$draw['draw_date']) . "</li>
                                  <li><strong>Certified Winning Numbers:</strong> " . htmlspecialchars($winning_nums_display) . "</li>
                                  <li><strong>Certificate Hash:</strong> <span style='font-family: monospace;'>" . htmlspecialchars((string)$draw['draw_hash_code']) . "</span></li>
                              </ul>
                              <p>The official PDF certificate is attached to this email</p>";

                $attach_err = '';
                if (!empty($to_recipients)) {
                    $sent_with_attach = send_system_email_with_pdf(
                        $to_recipients,
                        $cert_subject,
                        $cert_body,
                        $pdf_binary,
                        $pdf_filename,
                        $cc_recipients,
                        $attach_err
                    );
                    if (!$sent_with_attach) {
                        foreach ($to_recipients as $to_addr) {
                            send_system_email($to_addr, $cert_subject, $cert_body);
                        }
                    }
                }

                log_system_audit(
                    $pdo,
                    "DISPATCHED_CERTIFIED_PDF_REF_" . $draw['certificate_ref'] . " | TO: " . implode(',', $to_recipients) . " | CC: " . implode(',', $cc_recipients),
                    $draw_id
                );

                unset($_SESSION['partner_mfa_step'], $_SESSION['pending_partner_notes']);

                $home_url = BASE_URL . "Home/";
                echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Certification Complete</title>
                <style>
                    body { font-family: Arial, sans-serif; background: #f4f6f9; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                    .done-card { background: #fff; border-top: 5px solid #165b33; padding: 28px 32px; border-radius: 6px; box-shadow: 0 10px 28px rgba(0,0,0,0.12); max-width: 440px; text-align: center; }
                    .done-card h3 { margin: 0 0 8px; color: #1f2430; }
                    .done-card p { margin: 0; color: #5b6270; font-size: 0.9rem; line-height: 1.5; }
                </style></head><body>
                <div class='done-card'>
                    <h3>Draw Certified &amp; Sent</h3>
                    <p>Certificate <strong>" . htmlspecialchars((string)$draw['certificate_ref']) . "</strong> has been generated and sent to stakeholders (with verifier and partner CC'd). Returning to the Homepage...</p>
                </div>
                <script>
                    setTimeout(function() { window.location.href = " . json_encode($home_url) . "; }, 1600);
                </script>
                </body></html>";
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to finalize draw certification: " . htmlspecialchars($e->getMessage());
            }
        }
    } elseif (isset($_POST['action_cancel_mfa'])) {
        unset($_SESSION['partner_mfa_step'], $_SESSION['pending_partner_notes']);
        $mfa_step = 1;
    }
}

// Fetch audit trail entries for this draw
$audit_logs = [];
try {
    $log_stmt = $pdo->prepare("
        SELECT al.*, u.full_name AS actor_name 
        FROM system_audit_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        WHERE al.target_id = ? OR al.action_taken LIKE ?
        ORDER BY al.created_at DESC
    ");
    $log_stmt->execute([$draw_id, '%' . $draw['certificate_ref'] . '%']);
    $audit_logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $audit_logs = [];
}

function calculate_rba_distance_label($lat, $lng) {
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return 'Distance from RBA Studio: Unavailable';
    }

    $lat1 = (float)$lat;
    $lng1 = (float)$lng;

    if ($lat1 === 0.0 && $lng1 === 0.0) {
        return 'Distance from RBA Studio: GPS not recorded';
    }

    $rba_lat = -1.93843;
    $rba_lng = 30.08274;
    $earth_radius_m = 6371000.0;

    $dLat = deg2rad($rba_lat - $lat1);
    $dLng = deg2rad($rba_lng - $lng1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($rba_lat)) *
         sin($dLng / 2) * sin($dLng / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $distance_meters = $earth_radius_m * $c;

    if ($distance_meters < 1000) {
        $formatted = round($distance_meters) . ' meters';
    } else {
        $formatted = number_format($distance_meters / 1000, 2) . ' km';
    }

    return 'Approximate Distance from RBA Studio: ' . $formatted;
}

$page_title = "Partner Review & Certification | LDC System";
include_once __DIR__ . '/../../../assets/includes/header.php';
include_once __DIR__ . '/../../../assets/includes/sidebar.php';
?>

<style>
    .review-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
    }
    @media (max-width: 900px) {
        .review-grid { grid-template-columns: 1fr; }
    }
    .panel-box {
        background: #ffffff;
        border: 1px solid var(--pale-charcoal);
        border-radius: 4px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .ball-badge {
        display: inline-flex;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background-color: var(--red);
        color: #ffffff;
        font-weight: 800;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        border: 2px solid #ffffff;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }
    .checklist-item {
        padding: 8px 12px;
        background: #f8f9fa;
        border-left: 3px solid var(--jade);
        margin-bottom: 8px;
        font-size: 0.88rem;
    }
    .btn-back-flat {
        background-color: #ffffff;
        color: var(--charcoal);
        border: 1px solid var(--pale-charcoal);
        padding: 8px 14px;
        border-radius: 4px;
        font-weight: 700;
        font-size: 0.84rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }
    .btn-back-flat:hover {
        background-color: #f4f6f9;
    }
    .btn-primary-flat {
        background-color: var(--red);
        color: #ffffff;
        border: none;
        padding: 11px 18px;
        border-radius: 4px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    .btn-primary-flat:hover { background-color: var(--burgundy); }
    .btn-approve-flat {
        background-color: var(--jade);
        color: #ffffff;
        border: none;
        padding: 11px 18px;
        border-radius: 4px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    .btn-download-flat {
        background-color: var(--charcoal);
        color: #ffffff;
        border: none;
        padding: 11px 18px;
        border-radius: 4px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    .btn-download-flat:hover {
        opacity: 0.92;
    }
    .exceptions-card {
        background: #ffffff;
        border: 1px solid var(--pale-charcoal);
        border-radius: 4px;
        padding: 16px 18px;
        margin-top: 14px;
    }
    .exceptions-card__header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        font-weight: 800;
        color: var(--charcoal);
        font-size: 0.85rem;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        padding-bottom: 10px;
        margin-bottom: 12px;
        border-bottom: 1px solid var(--pale-charcoal);
    }
    .exceptions-badge {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.3px;
        text-transform: none;
        padding: 3px 9px;
        border-radius: 3px;
    }
    .exceptions-badge--none {
        background: #e6f4ea;
        color: #165b33;
    }
    .exceptions-badge--minor {
        background: #fef7e0;
        color: #7c5200;
    }
    .exceptions-badge--significant {
        background: #fdecea;
        color: #842029;
    }
    .exceptions-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        font-size: 0.88rem;
    }
    .exception-row {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        padding: 8px 10px;
        border-radius: 4px;
        border: 1px solid transparent;
        color: var(--slate);
        line-height: 1.45;
    }
    .exception-row--active-none {
        background: #f6fcf8;
        border-color: #bfe3cd;
        color: var(--charcoal);
    }
    .exception-row--active-minor {
        background: #fffdf5;
        border-color: #f3d68a;
        color: var(--charcoal);
    }
    .exception-row--active-significant {
        background: #fff8f8;
        border-color: #f1b8be;
        color: var(--charcoal);
    }
    .exception-glyph {
        font-size: 1rem;
        line-height: 1.3;
        flex-shrink: 0;
    }
    .exception-ref-code {
        font-family: monospace;
        background: #eef1f5;
        padding: 1px 6px;
        border-radius: 3px;
        color: var(--charcoal);
    }
    .exception-notes-box {
        margin-top: 12px;
        padding: 10px 12px;
        background: #f8f9fa;
        border-left: 3px solid var(--charcoal);
        border-radius: 0 4px 4px 0;
        font-size: 0.85rem;
        color: var(--charcoal);
        line-height: 1.5;
    }
    #cert-progress-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(31, 36, 48, 0.65);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }
</style>

<main class="main-content">
    <div style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <a href="<?= BASE_URL ?>Home/Draws/" class="btn-back-flat">
            <i class="fa-solid fa-arrow-left"></i> Back to Draws
        </a>
    </div>

    <div style="background-color: var(--charcoal); color: #ffffff; padding: 20px; border-radius: 4px; margin-bottom: 20px; border-left: 6px solid var(--jade);">
        <h2><i class="fa-solid fa-file-shield"></i> Review &amp; E-Signature Portal</h2>
        <p style="font-size: 0.85rem; color: var(--pale-charcoal);">Verify draw execution details, review evidence, record approval notes, and issue the official certification.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="review-grid">
        <!-- Left Column: Draw Details, Numbers & Audit Trail -->
        <div>
            <div class="panel-box">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--pale-charcoal); padding-bottom: 10px; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <h3 style="color: var(--burgundy); margin: 0;">Reference: <?= htmlspecialchars((string)$draw['certificate_ref']) ?></h3>
                    <?php if ($draw['status'] === 'CERTIFIED'): ?>
                        <span style="background-color: var(--jade); color: #ffffff; padding: 4px 10px; border-radius: 3px; font-size: 0.78rem; font-weight: 700;">
                            <i class="fa-solid fa-circle-check"></i> CERTIFIED
                        </span>
                    <?php else: ?>
                        <span style="background-color: var(--gold); color: #ffffff; padding: 4px 10px; border-radius: 3px; font-size: 0.78rem; font-weight: 700;">
                            <i class="fa-solid fa-clock"></i> <?= htmlspecialchars(str_replace('_', ' ', (string)$draw['status'])) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <table class="custom-table" style="margin-bottom: 18px;">
                    <tbody>
                        <tr>
                            <td style="width: 35%; color: var(--slate); font-weight: 600;">Draw Session</td>
                            <td><strong><?= htmlspecialchars((string)$draw['draw_session']) ?></strong></td>
                        </tr>
                        <tr>
                            <td style="color: var(--slate); font-weight: 600;">Draw Date</td>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime((string)$draw['draw_date']))) ?></td>
                        </tr>
                        <tr>
                            <td style="color: var(--slate); font-weight: 600;">Session Window</td>
                            <td><?= htmlspecialchars((string)$draw['start_time']) ?> &ndash; <?= htmlspecialchars((string)$draw['end_time']) ?></td>
                        </tr>
                        <tr>
                            <td style="color: var(--slate); font-weight: 600;">Venue &amp; GPS</td>
                            <?php $rba_distance_label = calculate_rba_distance_label($draw['gps_latitude'] ?? null, $draw['gps_longitude'] ?? null); ?>
                            <td>
                                <?= htmlspecialchars((string)$draw['venue_location']) ?><br>
                                <span style="font-size: 0.78rem; color: var(--slate);">
                                    GPS Coordinates: <?= htmlspecialchars((string)($draw['gps_latitude'] ?? '')) ?>, <?= htmlspecialchars((string)($draw['gps_longitude'] ?? '')) ?>
                                </span><br>
                                <span style="font-size: 0.78rem; font-weight: 600; color: var(--charcoal);">
                                    <i class="fa-solid fa-route" style="color: var(--red);"></i> <?= htmlspecialchars($rba_distance_label) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td style="color: var(--slate); font-weight: 600;">Primary Verifier</td>
                            <td><?= htmlspecialchars((string)$draw['officer_name']) ?> (<?= htmlspecialchars((string)$draw['officer_email']) ?>)</td>
                        </tr>
                        <tr>
                            <td style="color: var(--slate); font-weight: 600;">Integrity Hash</td>
                            <td style="font-family: monospace; font-size: 0.82rem;"><?= htmlspecialchars((string)$draw['draw_hash_code']) ?></td>
                        </tr>
                    </tbody>
                </table>

                <h4 style="color: var(--charcoal); margin-bottom: 10px;">Verified Winning Numbers</h4>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px;">
                    <div class="ball-badge"><?= (int)$draw['num1_a'] ?></div>
                    <div class="ball-badge"><?= (int)$draw['num2_a'] ?></div>
                    <div class="ball-badge"><?= (int)$draw['num3_a'] ?></div>
                    <div class="ball-badge"><?= (int)$draw['num4_a'] ?></div>
                    <div class="ball-badge"><?= (int)$draw['num5_a'] ?></div>
                </div>
                <?php if ($sets_match): ?>
                    <p style="font-size: 0.82rem; color: var(--jade); font-weight: 700; margin-bottom: 18px;">
                        <i class="fa-solid fa-circle-check"></i> Double-entry Set A and Set B match verified.
                    </p>
                <?php else: ?>
                    <p style="font-size: 0.82rem; color: var(--red); font-weight: 700; margin-bottom: 18px;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Warning: Set A and Set B entries do not match.
                    </p>
                <?php endif; ?>

                <?php
                    $review_photos = [];
                    if (!empty($draw['central_system_photo'])) {
                        $raw_p = trim((string)$draw['central_system_photo']);
                        if (str_starts_with($raw_p, '[')) {
                            $decoded_p = json_decode($raw_p, true);
                            if (is_array($decoded_p)) {
                                $review_photos = array_values(array_filter($decoded_p));
                            }
                        } else {
                            $review_photos = [$raw_p];
                        }
                    }
                ?>
                <?php if (!empty($review_photos)): ?>
                    <h4 style="color: var(--charcoal); margin-top: 20px; margin-bottom: 10px;">
                        Draw Snapshots (<?= count($review_photos) ?> Attached)
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 18px;">
                        <?php foreach ($review_photos as $idx => $p_path): ?>
                            <div style="border: 1px solid var(--pale-charcoal); border-radius: 4px; padding: 8px; background: #fafbfc;">
                                <div style="font-size: 0.76rem; font-weight: 700; color: var(--slate); margin-bottom: 6px;">Snapshot <?= $idx + 1 ?></div>
                                <a href="<?= BASE_URL . ltrim((string)$p_path, '/') ?>" target="_blank">
                                    <img src="<?= BASE_URL . ltrim((string)$p_path, '/') ?>" alt="Snapshot <?= $idx + 1 ?>" style="width: 100%; height: 180px; object-fit: cover; border-radius: 3px;">
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h4 style="color: var(--charcoal); margin-bottom: 10px;">SOP Procedure Checklist</h4>
                <div class="checklist-item">
                    <i class="fa-solid fa-check" style="color: var(--jade);"></i>
                    Pre-draw machinery &amp; equipment verification: <strong><?= !empty($draw['chk_pre_draw']) ? 'Confirmed' : 'Not Confirmed' ?></strong>
                </div>
                <div class="checklist-item">
                    <i class="fa-solid fa-check" style="color: var(--jade);"></i>
                    Live draw observation per SOPs: <strong><?= !empty($draw['chk_draw_obs']) ? 'Confirmed' : 'Not Confirmed' ?></strong>
                </div>
                <div class="checklist-item">
                    <i class="fa-solid fa-check" style="color: var(--jade);"></i>
                    Post-draw reconciliation &amp; audit: <strong><?= !empty($draw['chk_post_draw']) ? 'Confirmed' : 'Not Confirmed' ?></strong>
                </div>

                <?php
                    $exc_status = $draw['exception_status'] ?? 'NONE';
                    $exc_ref    = trim((string)($draw['exception_report_ref'] ?? ''));
                    $exc_desc   = trim((string)($draw['exception_details'] ?? ''));

                    $badge_class = 'exceptions-badge--none';
                    $badge_label = 'No Exceptions';
                    if ($exc_status === 'MINOR_RESOLVED') {
                        $badge_class = 'exceptions-badge--minor';
                        $badge_label = 'Minor Issue Resolved';
                    } elseif ($exc_status === 'SIGNIFICANT_EXCEPTION') {
                        $badge_class = 'exceptions-badge--significant';
                        $badge_label = 'Significant Exception';
                    }
                ?>

                <div class="exceptions-card">
                    <div class="exceptions-card__header">
                        <span>3. Exceptions Identified</span>
                        <span class="exceptions-badge <?= $badge_class ?>"><?= htmlspecialchars($badge_label) ?></span>
                    </div>

                    <div class="exceptions-list">
                        <div class="exception-row <?= $exc_status === 'NONE' ? 'exception-row--active-none' : '' ?>">
                            <span class="exception-glyph"><?= $exc_status === 'NONE' ? '&#9746;' : '&#9744;' ?></span>
                            <span><strong>None.</strong></span>
                        </div>

                        <div class="exception-row <?= $exc_status === 'MINOR_RESOLVED' ? 'exception-row--active-minor' : '' ?>">
                            <span class="exception-glyph"><?= $exc_status === 'MINOR_RESOLVED' ? '&#9746;' : '&#9744;' ?></span>
                            <span>Minor issue(s) noted and resolved during the session (describe briefly below).</span>
                        </div>

                        <div class="exception-row <?= $exc_status === 'SIGNIFICANT_EXCEPTION' ? 'exception-row--active-significant' : '' ?>">
                            <span class="exception-glyph"><?= $exc_status === 'SIGNIFICANT_EXCEPTION' ? '&#9746;' : '&#9744;' ?></span>
                            <span>
                                Significant exception(s) - see separate Exception Report (reference:
                                <strong class="exception-ref-code"><?= htmlspecialchars($exc_ref !== '' ? $exc_ref : '________________') ?></strong>).
                            </span>
                        </div>
                    </div>

                    <?php if ($exc_desc !== ''): ?>
                        <div class="exception-notes-box">
                            <strong>Issue Description:</strong> <?= nl2br(htmlspecialchars($exc_desc)) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($draw['procedure_notes'])): ?>
                    <div style="margin-top: 12px; background: #f4f6f9; padding: 12px; border-radius: 4px; font-size: 0.85rem;">
                        <strong>Verifier Notes:</strong> <?= nl2br(htmlspecialchars((string)$draw['procedure_notes'])) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Draw Audit Trail -->
            <div class="panel-box">
                <h4 style="color: var(--charcoal); margin-bottom: 12px; border-bottom: 2px solid var(--pale-charcoal); padding-bottom: 6px;">
                    <i class="fa-solid fa-clock-rotate-left"></i> Draw Audit Trail
                </h4>
                <?php if (empty($audit_logs)): ?>
                    <p style="font-size: 0.85rem; color: var(--slate);">No audit events recorded for this draw yet.</p>
                <?php else: ?>
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Actor</th>
                                <th>Action</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audit_logs as $log): ?>
                                <tr>
                                    <td style="font-size: 0.8rem;"><?= htmlspecialchars((string)$log['created_at']) ?></td>
                                    <td style="font-size: 0.82rem;"><?= htmlspecialchars((string)($log['actor_name'] ?? 'System')) ?></td>
                                    <td style="font-size: 0.82rem; font-weight: 600;"><?= htmlspecialchars((string)$log['action_taken']) ?></td>
                                    <td style="font-size: 0.8rem;"><?= htmlspecialchars((string)($log['ip_address'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Column: E-Signature & Certificate Generation Box -->
        <?php $is_partner = (isset($_SESSION['role_code']) && $_SESSION['role_code'] === 'PARTNER'); ?>
        <div>
            <div class="panel-box">
                <h3 style="color: var(--charcoal); margin-bottom: 12px; font-size: 1.1rem;">
                    <i class="fa-solid fa-stamp" style="color: var(--red);"></i> Partner E-Signature &amp; Certificate
                </h3>

                <?php if ($draw['status'] === 'CERTIFIED'): ?>
                    <?php
                        $cert_id_param = urlencode((string)($draw['draw_hash_code'] ?: $draw['id']));
                        $pdf_base_url  = BASE_URL . 'Home/Draws/Certificate/pdf.php?id=' . $cert_id_param;
                    ?>
                    <div style="background-color: var(--jade); color: #ffffff; padding: 16px; border-radius: 4px; text-align: center; margin-bottom: 15px;">
                        <i class="fa-solid fa-certificate fa-2x" style="margin-bottom: 8px; display: block;"></i>
                        <strong>Draw Officially Certified</strong>
                        <p style="font-size: 0.78rem; margin-top: 6px; opacity: 0.95;">
                            Signed by <?= htmlspecialchars((string)($draw['partner_name'] ?? ($_SESSION['user_name'] ?? 'Authorized Partner'))) ?><br>
                            on <?= htmlspecialchars((string)($draw['partner_signed_at'] ?? '')) ?>
                        </p>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <!-- <a href="<?= BASE_URL ?>Home/Draws/Certificate/?id=<?= $cert_id_param ?>" class="btn-primary-flat" style="width: 100%; background-color: var(--ocean);">
                            <i class="fa-solid fa-file-award"></i> Open Official Certificate
                        </a> -->
                        <a href="<?= $pdf_base_url ?>&amp;mode=inline" target="_blank" class="btn-download-flat" style="width: 100%;">
                            <i class="fa-solid fa-file-pdf"></i> Preview PDF
                        </a>
                        <a href="<?= $pdf_base_url ?>&amp;mode=download" class="btn-approve-flat" style="width: 100%;">
                            <i class="fa-solid fa-download"></i> Download PDF
                        </a>
                    </div>
                <?php elseif (!$is_partner): ?>
                    <div style="background-color: #f4f6f9; border-left: 4px solid var(--gold); padding: 14px; border-radius: 4px; font-size: 0.85rem; color: var(--charcoal);">
                        <strong><i class="fa-solid fa-lock"></i> Partner Action Required</strong>
                        <p style="margin-top: 6px; margin-bottom: 0; color: var(--slate);">
                            This draw is currently <strong><?= htmlspecialchars(str_replace('_', ' ', (string)$draw['status'])) ?></strong>. E-signature approval and certificate issuance can only be performed by an active <strong>PARTNER</strong> account.
                        </p>
                    </div>
                <?php else: ?>
                    <p style="font-size: 0.84rem; color: var(--slate); line-height: 1.5; margin-bottom: 15px;">
                        Confirming approval locks the draw record, logs your partner e-signature, attaches the official PDF certificate for active stakeholders (with verifier and partner CC'd), and returns you to the Homepage.
                    </p>

                    <form method="POST">
                        <div style="margin-bottom: 14px;">
                            <label style="font-size: 0.82rem; font-weight: 600;">Partner Review Notes (Optional)</label>
                            <textarea name="partner_notes" rows="3" placeholder="Optional remarks for the audit trail..." style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); border-radius: 4px; margin-top: 4px; font-size: 0.85rem;"><?= htmlspecialchars((string)($_SESSION['pending_partner_notes'] ?? '')) ?></textarea>
                        </div>

                        <?php if ($mfa_step === 1): ?>
                            <button type="submit" name="action_request_mfa" class="btn-primary-flat" style="width: 100%;">
                                <i class="fa-solid fa-envelope-circle-check"></i> Send MFA Code to Approve
                            </button>
                        <?php else: ?>
                            <div style="margin-bottom: 14px;">
                                <label style="font-size: 0.82rem; font-weight: 600;">Enter 6-Digit Email Security Code</label>
                                <input type="text" name="mfa_code" maxlength="6" inputmode="numeric" pattern="[0-9]*" required placeholder="000000" style="width: 100%; padding: 10px; border: 1px solid var(--pale-charcoal); border-radius: 4px; margin-top: 4px; text-align: center; font-size: 1.2rem; letter-spacing: 5px; font-weight: 700;">
                            </div>
                            <button type="submit" name="action_confirm_approve" class="btn-approve-flat" style="width: 100%; margin-bottom: 8px;">
                                <i class="fa-solid fa-signature"></i> Approve &amp; Generate Certificate
                            </button>
                            <button type="submit" name="action_cancel_mfa" formnovalidate style="width: 100%; background: var(--pale-charcoal); color: var(--charcoal); border: none; padding: 8px; border-radius: 4px; font-size: 0.82rem; font-weight: 600; cursor: pointer;">
                                Cancel Verification Step
                            </button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Progress Pop-Up Shown While Generating PDF & Dispatching Emails -->
<div id="cert-progress-modal">
    <div style="background: #ffffff; border-top: 5px solid var(--red); border-radius: 6px; padding: 24px 28px; max-width: 390px; width: 90%; text-align: center; box-shadow: 0 12px 30px rgba(0,0,0,0.25);">
        <div style="font-size: 1rem; font-weight: 800; color: var(--charcoal); margin-bottom: 8px;">
            <i class="fa-solid fa-spinner fa-spin" style="color: var(--red);"></i> Certifying &amp; Sending Certificate...
        </div>
        <p style="font-size: 0.84rem; color: var(--slate); margin: 0; line-height: 1.45;">
            Generating the official PDF certificate and sending it to stakeholders. Please wait...
        </p>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const approveBtn = document.querySelector('button[name="action_confirm_approve"]');
        const modal = document.getElementById('cert-progress-modal');
        if (approveBtn && modal) {
            const form = approveBtn.closest('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (e.submitter === approveBtn && form.checkValidity()) {
                        modal.style.display = 'flex';
                    }
                });
            }
        }
    });
</script>

<?php include_once __DIR__ . '/../../../assets/includes/footer.php'; ?>