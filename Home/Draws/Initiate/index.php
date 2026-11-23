<?php
//error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../assets/config/config.php';
require_once __DIR__ . '/../../../assets/config/mailer.php';
check_auth(['PRIMARY_VERIFIER', 'ADMIN']);

$error = '';
$success = '';
$today_date = date('Y-m-d');

// Only stay on MFA step 2 during an active POST confirmation/retry; never show MFA automatically on fresh GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['initiate_mfa_step']);
}
$mfa_step = (int)($_SESSION['initiate_mfa_step'] ?? 1);

const REQUIRED_SNAPSHOT_COUNT = 3;

// Normalize time input (HH:MM or HH:MM:SS) to HH:MM:SS or '' if invalid/empty
$normalize_24h_time = function (?string $raw): string {
    $t = trim((string)$raw);
    if ($t === '') {
        return '';
    }
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $t, $m)) {
        $sec = isset($m[3]) ? $m[3] : '00';
        return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)$sec);
    }
    return '';
};

// Helper to normalize stored DB photo column (supports legacy single string or JSON array)
$parse_db_photos = function ($raw_val) {
    $out = ['', '', ''];
    if (empty($raw_val)) {
        return $out;
    }
    $trim_val = trim((string)$raw_val);
    if (str_starts_with($trim_val, '[')) {
        $arr = json_decode($trim_val, true);
        if (is_array($arr)) {
            for ($i = 0; $i < REQUIRED_SNAPSHOT_COUNT; $i++) {
                $out[$i] = isset($arr[$i]) ? trim((string)$arr[$i]) : '';
            }
            return $out;
        }
    }
    $out[0] = $trim_val;
    return $out;
};

// 0. AJAX Pre-Creation Endpoint (Creates draw & hashcode immediately on modal confirm)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'precreate_draw') {
    ini_set('display_errors', 0);
    error_reporting(0);
    header('Content-Type: application/json');

    try {
        $draw_date    = trim((string)($_POST['draw_date'] ?? ''));
        $draw_session = trim((string)($_POST['draw_session'] ?? ''));

        if ($draw_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $draw_date)) {
            echo json_encode(['status' => 'error', 'message' => 'A valid draw date is required.']);
            exit;
        }
        if ($draw_date > date('Y-m-d')) {
            echo json_encode(['status' => 'error', 'message' => 'Future dates are not allowed.']);
            exit;
        }

        $ref  = generate_reference($pdo, $draw_date);
        $hash = md5(uniqid($ref . '_' . $draw_date, true));

        $stmt = $pdo->prepare("INSERT INTO draws
            (draw_hash_code, certificate_ref, draw_date, draw_session, start_time, end_time,
             venue_location, gps_latitude, gps_longitude, primary_verifier_id,
             chk_pre_draw, chk_draw_obs, chk_post_draw, procedure_notes,
             exception_status, exception_report_ref, exception_details,
             num1_a, num2_a, num3_a, num4_a, num5_a,
             num1_b, num2_b, num3_b, num4_b, num5_b,
             central_system_photo, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, '', 'NONE', '', '', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, '[]', 'IN_PROGRESS')");

        $ok = $stmt->execute([
            $hash,
            $ref,
            $draw_date,
            $draw_session,
            $draw_date . ' 00:00:00',
            $draw_date . ' 00:00:00',
            'Rwanda Broadcasting Agency (RBA) Studio, Kacyiru',
            0.0,
            0.0,
            $_SESSION['user_id']
        ]);

        if ($ok) {
            $new_id = (string)$pdo->lastInsertId();
            echo json_encode([
                'status'          => 'success',
                'draw_id'         => ($new_id !== '' && $new_id !== '0') ? $new_id : $hash,
                'draw_hash_code'  => $hash,
                'certificate_ref' => $ref,
                'draw_date'       => $draw_date,
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to pre-create draw record.']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 0b. AJAX Instant Snapshot Upload Endpoint (Persists each photo immediately across refreshes)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_snapshot') {
    ini_set('display_errors', 0);
    error_reporting(0);
    header('Content-Type: application/json');

    try {
        $slot_idx      = (int)($_POST['slot_index'] ?? -1);
        $draw_id_str   = trim((string)($_POST['draw_id'] ?? ''));
        $draw_hash_str = trim((string)($_POST['draw_hash_code'] ?? ''));

        if ($slot_idx < 0 || $slot_idx >= REQUIRED_SNAPSHOT_COUNT) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid snapshot slot.']);
            exit;
        }
        if ($draw_id_str === '' && $draw_hash_str === '') {
            echo json_encode(['status' => 'error', 'message' => 'Missing draw reference. Open or start a draw session first.']);
            exit;
        }
        if (!isset($_FILES['snapshot_file']) || ($_FILES['snapshot_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'No valid image uploaded.']);
            exit;
        }

        $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $orig_name = $_FILES['snapshot_file']['name'] ?? '';
        $tmp_name  = $_FILES['snapshot_file']['tmp_name'] ?? '';
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext, true) || !is_uploaded_file($tmp_name)) {
            echo json_encode(['status' => 'error', 'message' => 'Unsupported image format.']);
            exit;
        }

        $match_parts = [];
        $match_params = [];
        if ($draw_hash_str !== '') {
            $match_parts[] = "draw_hash_code = :m_hash";
            $match_params[':m_hash'] = $draw_hash_str;
        }
        if ($draw_id_str !== '' && ctype_digit($draw_id_str)) {
            $match_parts[] = "id = :m_id";
            $match_params[':m_id'] = (int)$draw_id_str;
        }

        $sel = $pdo->prepare("SELECT central_system_photo FROM draws WHERE " . implode(" OR ", $match_parts) . " LIMIT 1");
        $sel->execute($match_params);
        $current_paths = $parse_db_photos($sel->fetchColumn());

        $upload_dir = __DIR__ . '/../../../uploads';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0775, true);
        }

        $filename = md5(time() . '_' . $slot_idx . '_' . random_int(1000, 9999)) . '.' . $ext;
        $disk_target = $upload_dir . '/' . $filename;

        if (!move_uploaded_file($tmp_name, $disk_target)) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded image to uploads directory.']);
            exit;
        }

        $rel_path = 'uploads/' . $filename;
        $current_paths[$slot_idx] = $rel_path;

        $upd_params = $match_params;
        $upd_params[':photos'] = json_encode(array_values($current_paths));
        $upd = $pdo->prepare("UPDATE draws SET central_system_photo = :photos WHERE " . implode(" OR ", $match_parts));
        $upd->execute($upd_params);

        echo json_encode([
            'status' => 'success',
            'path'   => $rel_path,
            'url'    => BASE_URL . ltrim($rel_path, '/'),
            'photos' => array_values($current_paths),
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Resolve draw by hashcode (primary) or id/ref fallback
$resume_key = trim((string)($_GET['hash'] ?? ($_GET['resume'] ?? ($_GET['draft_id'] ?? ($_GET['id'] ?? '')))));
$post_hash  = trim((string)($_POST['draw_hash_code'] ?? ''));
$post_id    = trim((string)($_POST['draw_id'] ?? ''));

$lookup_hash = $post_hash !== '' ? $post_hash : $resume_key;
$resume_draw = null;

if ($lookup_hash !== '') {
    $r_stmt = $pdo->prepare("SELECT * FROM draws WHERE draw_hash_code = ? OR certificate_ref = ? LIMIT 1");
    $r_stmt->execute([$lookup_hash, $lookup_hash]);
    $resume_draw = $r_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$resume_draw) {
    $id_candidate = $post_id !== '' ? $post_id : (ctype_digit($resume_key) ? $resume_key : '');
    if ($id_candidate !== '') {
        $r_stmt = $pdo->prepare("SELECT * FROM draws WHERE id = ? LIMIT 1");
        $r_stmt->execute([(int)$id_candidate]);
        $resume_draw = $r_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// Decode previously persisted photo paths
$saved_photos = ['', '', ''];
if (!empty($_POST['existing_photos_json'])) {
    $decoded = json_decode((string)$_POST['existing_photos_json'], true);
    if (is_array($decoded)) {
        for ($i = 0; $i < REQUIRED_SNAPSHOT_COUNT; $i++) {
            $saved_photos[$i] = isset($decoded[$i]) ? trim((string)$decoded[$i]) : '';
        }
    }
}
if ($resume_draw && !empty($resume_draw['central_system_photo'])) {
    $db_photos = $parse_db_photos($resume_draw['central_system_photo']);
    for ($i = 0; $i < REQUIRED_SNAPSHOT_COUNT; $i++) {
        if ($saved_photos[$i] === '' && $db_photos[$i] !== '') {
            $saved_photos[$i] = $db_photos[$i];
        }
    }
}

// Ensure current verifier email and full name are in session
if (empty($_SESSION['user_email']) || empty($_SESSION['user_name'])) {
    $u_stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
    $u_stmt->execute([$_SESSION['user_id']]);
    $u_data = $u_stmt->fetch();
    if ($u_data) {
        $_SESSION['user_email'] = $u_data['email'];
        $_SESSION['user_name'] = $u_data['full_name'];
    }
}

// Helper to persist all 3 snapshot slots safely to /uploads/ on form POST
$persist_all_snapshots = function ($draw_id_val, $draw_hash_val, array $current_saved) use ($pdo, $parse_db_photos) {
    $draw_id_str   = trim((string)$draw_id_val);
    $draw_hash_str = trim((string)$draw_hash_val);
    $final_paths = ['', '', ''];
    for ($i = 0; $i < REQUIRED_SNAPSHOT_COUNT; $i++) {
        $final_paths[$i] = trim((string)($current_saved[$i] ?? ''));
    }

    $match_parts = [];
    $match_params = [];
    if ($draw_hash_str !== '') {
        $match_parts[] = "draw_hash_code = :m_hash";
        $match_params[':m_hash'] = $draw_hash_str;
    }
    if ($draw_id_str !== '' && ctype_digit($draw_id_str)) {
        $match_parts[] = "id = :m_id";
        $match_params[':m_id'] = (int)$draw_id_str;
    }

    if (!empty($match_parts)) {
        $ph_stmt = $pdo->prepare("SELECT central_system_photo FROM draws WHERE " . implode(" OR ", $match_parts) . " LIMIT 1");
        $ph_stmt->execute($match_params);
        $db_raw = $ph_stmt->fetchColumn();
        $db_paths = $parse_db_photos($db_raw);
        for ($i = 0; $i < REQUIRED_SNAPSHOT_COUNT; $i++) {
            if ($final_paths[$i] === '' && $db_paths[$i] !== '') {
                $final_paths[$i] = $db_paths[$i];
            }
        }
    }

    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $upload_dir = __DIR__ . '/../../../uploads';
    $any_new_upload = false;

    if (isset($_FILES['central_photos']) && is_array($_FILES['central_photos']['name'])) {
        for ($i = 0; $i < REQUIRED_SNAPSHOT_COUNT; $i++) {
            $err_code = $_FILES['central_photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err_code === UPLOAD_ERR_OK) {
                $orig_name = $_FILES['central_photos']['name'][$i] ?? '';
                $tmp_name  = $_FILES['central_photos']['tmp_name'][$i] ?? '';
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed_ext, true) && is_uploaded_file($tmp_name)) {
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0775, true);
                    }
                    $filename = md5(time() . '_' . $i . '_' . random_int(1000, 9999)) . '.' . $ext;
                    $disk_target = $upload_dir . '/' . $filename;
                    if (move_uploaded_file($tmp_name, $disk_target)) {
                        $final_paths[$i] = 'uploads/' . $filename;
                        $any_new_upload = true;
                    }
                }
            }
        }
    }

    if (!empty($match_parts) && ($any_new_upload || array_filter($final_paths))) {
        $upd_params = $match_params;
        $upd_params[':photos'] = json_encode($final_paths);
        $upd_ph = $pdo->prepare("UPDATE draws SET central_system_photo = :photos WHERE " . implode(" OR ", $match_parts));
        $upd_ph->execute($upd_params);
    }

    return $final_paths;
};

$count_attached_snapshots = function (array $paths) {
    $cnt = 0;
    foreach ($paths as $p) {
        if (trim((string)$p) !== '') {
            $cnt++;
        }
    }
    return $cnt;
};

// 1. Handle AJAX Draft Sync on Start Session (also persists drawn numbers)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_draft') {
    ini_set('display_errors', 0);
    error_reporting(0);
    header('Content-Type: application/json');

    try {
        $existing_draw_id   = trim((string)($_POST['draw_id'] ?? ''));
        $existing_draw_hash = trim((string)($_POST['draw_hash_code'] ?? ''));
        $draw_date          = trim((string)($_POST['draw_date'] ?? date('Y-m-d')));
        $draw_session       = trim((string)($_POST['draw_session'] ?? 'Unnamed Session'));
        $time_only          = $normalize_24h_time($_POST['start_time'] ?? date('H:i:s')) ?: date('H:i:s');

        if ($draw_date === '') {
            $draw_date = date('Y-m-d');
        }
        $start_datetime = $draw_date . ' ' . $time_only;

        $n_a = [];
        $n_b = [];
        for ($k = 1; $k <= 5; $k++) {
            $va = $_POST['n' . $k . '_a'] ?? '';
            $vb = $_POST['n' . $k . '_b'] ?? '';
            $n_a[$k] = ($va !== '' && is_numeric($va)) ? max(0, min(90, (int)$va)) : 0;
            $n_b[$k] = ($vb !== '' && is_numeric($vb)) ? max(0, min(90, (int)$vb)) : 0;
        }

        $existing_row = null;
        if ($existing_draw_hash !== '') {
            $chk = $pdo->prepare("SELECT id, certificate_ref, draw_hash_code, draw_date FROM draws WHERE draw_hash_code = ? LIMIT 1");
            $chk->execute([$existing_draw_hash]);
            $existing_row = $chk->fetch(PDO::FETCH_ASSOC);
        } elseif ($existing_draw_id !== '' && ctype_digit($existing_draw_id)) {
            $chk = $pdo->prepare("SELECT id, certificate_ref, draw_hash_code, draw_date FROM draws WHERE id = ? LIMIT 1");
            $chk->execute([(int)$existing_draw_id]);
            $existing_row = $chk->fetch(PDO::FETCH_ASSOC);
        }

        if ($existing_row) {
            $locked_date = !empty($existing_row['draw_date']) ? (string)$existing_row['draw_date'] : $draw_date;
            $kept_hash   = !empty($existing_row['draw_hash_code']) ? (string)$existing_row['draw_hash_code'] : md5(uniqid((string)$existing_row['id'], true));
            $pdo->prepare("UPDATE draws SET draw_date = ?, draw_hash_code = ?, draw_session = ?, start_time = ?,
                num1_a = ?, num2_a = ?, num3_a = ?, num4_a = ?, num5_a = ?,
                num1_b = ?, num2_b = ?, num3_b = ?, num4_b = ?, num5_b = ?,
                status = 'IN_PROGRESS' WHERE id = ?")
                ->execute([
                    $locked_date, $kept_hash, $draw_session, $locked_date . ' ' . $time_only,
                    $n_a[1], $n_a[2], $n_a[3], $n_a[4], $n_a[5],
                    $n_b[1], $n_b[2], $n_b[3], $n_b[4], $n_b[5],
                    $existing_row['id']
                ]);

            echo json_encode([
                'status'         => 'success',
                'draw_id'        => (string)$existing_row['id'],
                'draw_hash_code' => $kept_hash,
                'ref'            => (string)$existing_row['certificate_ref'],
                'start_time'     => $time_only,
            ]);
            exit;
        }

        $ref  = generate_reference($pdo, $draw_date);
        $hash = ($existing_draw_hash !== '') ? $existing_draw_hash : md5(uniqid($ref, true));

        $stmt = $pdo->prepare("INSERT INTO draws 
            (draw_hash_code, certificate_ref, draw_date, draw_session, start_time, end_time, venue_location, primary_verifier_id,
             num1_a, num2_a, num3_a, num4_a, num5_a, num1_b, num2_b, num3_b, num4_b, num5_b, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'IN_PROGRESS')");

        $success_draft = $stmt->execute([
            $hash, $ref, $draw_date, $draw_session, $start_datetime, $draw_date . ' 00:00:00',
            'Rwanda Broadcasting Agency (RBA) Studio, Kacyiru', $_SESSION['user_id'],
            $n_a[1], $n_a[2], $n_a[3], $n_a[4], $n_a[5],
            $n_b[1], $n_b[2], $n_b[3], $n_b[4], $n_b[5]
        ]);

        if ($success_draft) {
            $new_id = (string)$pdo->lastInsertId();
            echo json_encode([
                'status'         => 'success',
                'draw_id'        => ($new_id !== '' && $new_id !== '0') ? $new_id : $hash,
                'draw_hash_code' => $hash,
                'ref'            => $ref,
                'start_time'     => $time_only,
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database execution failed.']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 2. Handle MFA Code Request & Final Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    $draw_id        = trim((string)($_POST['draw_id'] ?? ($resume_draw['id'] ?? '')));
    $draw_hash_code = trim((string)($_POST['draw_hash_code'] ?? ($resume_draw['draw_hash_code'] ?? '')));
    $draw_date      = trim((string)($resume_draw['draw_date'] ?? ($_POST['draw_date'] ?? '')));
    $is_backdated   = ($draw_date !== '' && $draw_date < $today_date);

    $raw_start_in = $is_backdated ? ($_POST['start_time_manual'] ?? '') : ($_POST['start_time'] ?? '');
    $raw_end_in   = $is_backdated ? ($_POST['end_time_manual'] ?? '') : ($_POST['end_time'] ?? '');

    $start_time_24 = $normalize_24h_time($raw_start_in);
    $end_time_24   = $normalize_24h_time($raw_end_in);

    $exception_status = $_POST['exception_status'] ?? 'NONE';
    $allowed_exceptions = ['NONE', 'MINOR_RESOLVED', 'SIGNIFICANT_EXCEPTION'];
    if (!in_array($exception_status, $allowed_exceptions, true)) {
        $exception_status = 'NONE';
    }

    $exception_report_ref = trim($_POST['exception_report_ref'] ?? '');
    $exception_details = trim($_POST['exception_details'] ?? '');

    if ($exception_status === 'NONE') {
        $exception_report_ref = null;
        $exception_details = null;
    } elseif ($exception_status === 'MINOR_RESOLVED') {
        $exception_report_ref = null;
    } elseif ($exception_status === 'SIGNIFICANT_EXCEPTION') {
        if ($exception_details === '') {
            $exception_details = null;
        }
    }

    $set_a = [
        (int)($_POST['n1_a'] ?? -1),
        (int)($_POST['n2_a'] ?? -1),
        (int)($_POST['n3_a'] ?? -1),
        (int)($_POST['n4_a'] ?? -1),
        (int)($_POST['n5_a'] ?? -1),
    ];
    $set_b = [
        (int)($_POST['n1_b'] ?? -1),
        (int)($_POST['n2_b'] ?? -1),
        (int)($_POST['n3_b'] ?? -1),
        (int)($_POST['n4_b'] ?? -1),
        (int)($_POST['n5_b'] ?? -1),
    ];

    $out_of_bounds = false;
    foreach (array_merge($set_a, $set_b) as $num) {
        if ($num < 0 || $num > 90) {
            $out_of_bounds = true;
            break;
        }
    }

    if (isset($_POST['action_cancel_submit_mfa'])) {
        unset($_SESSION['initiate_mfa_step']);
        $mfa_step = 1;
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
    } elseif ($draw_date === '' || $draw_date > $today_date) {
        $error = "Error: Please select a valid draw date on or before today (" . $today_date . ").";
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
    } elseif ($exception_status === 'MINOR_RESOLVED' && ($exception_details === null || $exception_details === '')) {
        $error = "Error: Please provide a brief description of the minor issue(s) noted and resolved.";
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
    } elseif ($exception_status === 'SIGNIFICANT_EXCEPTION' && $exception_report_ref === '') {
        $error = "Error: Please enter the separate Exception Report reference for the significant exception(s).";
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
    } elseif ($out_of_bounds) {
        $error = "Error: All winning numbers must be between 0 and 90.";
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
    } elseif ($set_a !== $set_b) {
        $error = "Verification Mismatch: Independent Winning Number Set A does not match Set B. Please re-verify the drawn numbers.";
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
    } elseif ($start_time_24 === '' || $end_time_24 === '') {
        $error = "Error: Both start time and end time must be valid 24-hour times (HH:MM).";
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
    } elseif ($end_time_24 <= $start_time_24) {
        $error = "Data Quality Error: End time (" . substr($end_time_24, 0, 5) . ") must be later than start time (" . substr($start_time_24, 0, 5) . ").";
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
    } elseif (isset($_POST['action_request_submit_mfa'])) {
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
        $attached_cnt = $count_attached_snapshots($saved_photos);

        if ($attached_cnt !== REQUIRED_SNAPSHOT_COUNT) {
            $error = "Error: Exactly " . REQUIRED_SNAPSHOT_COUNT . " snapshots are required before requesting MFA (currently attached: " . $attached_cnt . " of " . REQUIRED_SNAPSHOT_COUNT . ").";
        } else {
            $mfa_code = (string)random_int(100000, 999999);
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            $pdo->prepare("INSERT INTO mfa_codes (user_id, action_type, code, expires_at) VALUES (?, 'SUBMIT_DRAW', ?, ?)")
                ->execute([$_SESSION['user_id'], $mfa_code, $expires]);

            $subj = "LDC Draw Submission Security Code - " . ($_POST['draw_session'] ?? 'Draw');
            $body = "<p>Hello <strong>" . htmlspecialchars($_SESSION['user_name']) . "</strong>,</p>
                     <p>Enter this 6-digit security code to submit draw session <strong>" . htmlspecialchars($_POST['draw_session'] ?? '') . "</strong> (" . htmlspecialchars($draw_date) . ") with " . REQUIRED_SNAPSHOT_COUNT . " verified snapshots for Partner approval:</p>
                     <div style='text-align: center; margin: 20px 0;'>
                         <span style='font-size: 2rem; font-weight: bold; color: #E81A3B; background: #E7E7E7; padding: 10px 22px; letter-spacing: 5px; border-radius: 4px;'>" . $mfa_code . "</span>
                     </div>
                     <p>This code is valid for 10 minutes.</p>";

            $mail_err = '';
            if (send_system_email($_SESSION['user_email'], $subj, $body, $mail_err)) {
                $_SESSION['initiate_mfa_step'] = 2;
                $mfa_step = 2;
                $audit_target = ($draw_hash_code !== '') ? $draw_hash_code : ($draw_id !== '' ? $draw_id : null);
                log_system_audit($pdo, "REQUESTED_SUBMIT_MFA", $audit_target, ($_POST['lat'] ?: '0') . ',' . ($_POST['lng'] ?: '0'));
                $success = "A 6-digit submission verification code has been sent to " . htmlspecialchars($_SESSION['user_email']) . ".";
            } else {
                $error = "Failed to send submission MFA email: " . htmlspecialchars($mail_err);
            }
        }
    } elseif (isset($_POST['action_confirm_submit'])) {
        $code = trim($_POST['submit_mfa_code'] ?? '');
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
        $attached_cnt = $count_attached_snapshots($saved_photos);

        if ($attached_cnt !== REQUIRED_SNAPSHOT_COUNT) {
            $error = "Error: Exactly " . REQUIRED_SNAPSHOT_COUNT . " snapshots are required to submit (currently attached: " . $attached_cnt . " of " . REQUIRED_SNAPSHOT_COUNT . ").";
            $_SESSION['initiate_mfa_step'] = 2;
            $mfa_step = 2;
        } else {
            $mfa_chk = $pdo->prepare("
                SELECT * FROM mfa_codes 
                WHERE user_id = ? AND action_type = 'SUBMIT_DRAW' AND code = ? AND is_used = 0 AND expires_at > NOW() 
                ORDER BY id DESC LIMIT 1
            ");
            $mfa_chk->execute([$_SESSION['user_id'], $code]);
            $valid_mfa = $mfa_chk->fetch();

            if (!$valid_mfa) {
                $error = "Invalid or expired submission MFA code. Please check your email and try again.";
                $_SESSION['initiate_mfa_step'] = 2;
                $mfa_step = 2;
            } else {
                $pdo->prepare("UPDATE mfa_codes SET is_used = 1 WHERE id = ?")->execute([$valid_mfa['id']]);

                $start_datetime = $draw_date . ' ' . $start_time_24;
                $end_datetime   = $draw_date . ' ' . $end_time_24;
                $photos_json    = json_encode(array_values($saved_photos));

                if ($draw_id !== '' || $draw_hash_code !== '') {
                    $where_parts = [];
                    $upd_params = [
                        ':draw_date'            => $draw_date,
                        ':draw_session'         => $_POST['draw_session'],
                        ':start_time'           => $start_datetime,
                        ':end_time'             => $end_datetime,
                        ':venue_location'       => $_POST['venue'],
                        ':gps_latitude'         => $_POST['lat'] ?: 0.0,
                        ':gps_longitude'        => $_POST['lng'] ?: 0.0,
                        ':chk_pre_draw'         => isset($_POST['chk_pre']) ? 1 : 0,
                        ':chk_draw_obs'         => isset($_POST['chk_obs']) ? 1 : 0,
                        ':chk_post_draw'        => isset($_POST['chk_post']) ? 1 : 0,
                        ':procedure_notes'      => $_POST['procedure_notes'],
                        ':exception_status'     => $exception_status,
                        ':exception_report_ref' => $exception_report_ref,
                        ':exception_details'    => $exception_details,
                        ':num1_a'               => $set_a[0],
                        ':num2_a'               => $set_a[1],
                        ':num3_a'               => $set_a[2],
                        ':num4_a'               => $set_a[3],
                        ':num5_a'               => $set_a[4],
                        ':num1_b'               => $set_b[0],
                        ':num2_b'               => $set_b[1],
                        ':num3_b'               => $set_b[2],
                        ':num4_b'               => $set_b[3],
                        ':num5_b'               => $set_b[4],
                        ':central_system_photo' => $photos_json,
                    ];

                    if ($draw_hash_code !== '') {
                        $where_parts[] = "draw_hash_code = :w_hash";
                        $upd_params[':w_hash'] = $draw_hash_code;
                    }
                    if ($draw_id !== '' && ctype_digit($draw_id)) {
                        $where_parts[] = "id = :w_id";
                        $upd_params[':w_id'] = (int)$draw_id;
                    }

                    $stmt = $pdo->prepare("UPDATE draws SET 
                        draw_date = :draw_date, draw_session = :draw_session, start_time = :start_time, end_time = :end_time, venue_location = :venue_location, 
                        gps_latitude = :gps_latitude, gps_longitude = :gps_longitude, chk_pre_draw = :chk_pre_draw, chk_draw_obs = :chk_draw_obs, chk_post_draw = :chk_post_draw, 
                        procedure_notes = :procedure_notes, exception_status = :exception_status, exception_report_ref = :exception_report_ref, exception_details = :exception_details,
                        num1_a = :num1_a, num2_a = :num2_a, num3_a = :num3_a, num4_a = :num4_a, num5_a = :num5_a, 
                        num1_b = :num1_b, num2_b = :num2_b, num3_b = :num3_b, num4_b = :num4_b, num5_b = :num5_b, central_system_photo = :central_system_photo, 
                        status = 'PENDING_PARTNER_APPROVAL', verifier_signed_at = NOW() 
                        WHERE " . implode(" OR ", $where_parts));

                    $stmt->execute($upd_params);
                } else {
                    $ref = generate_reference($pdo, $draw_date);
                    $hash = md5(uniqid($ref, true));
                    $stmt = $pdo->prepare("INSERT INTO draws 
                        (draw_hash_code, certificate_ref, draw_date, draw_session, start_time, end_time, venue_location, gps_latitude, gps_longitude, primary_verifier_id, chk_pre_draw, chk_draw_obs, chk_post_draw, procedure_notes, exception_status, exception_report_ref, exception_details, num1_a, num2_a, num3_a, num4_a, num5_a, num1_b, num2_b, num3_b, num4_b, num5_b, central_system_photo, status, verifier_signed_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_PARTNER_APPROVAL', NOW())");
                    $stmt->execute([
                        $hash, $ref, $draw_date, $_POST['draw_session'], $start_datetime, $end_datetime,
                        $_POST['venue'], $_POST['lat'] ?: 0.0, $_POST['lng'] ?: 0.0, $_SESSION['user_id'],
                        isset($_POST['chk_pre']) ? 1 : 0, isset($_POST['chk_obs']) ? 1 : 0, isset($_POST['chk_post']) ? 1 : 0,
                        $_POST['procedure_notes'], $exception_status, $exception_report_ref, $exception_details,
                        $set_a[0], $set_a[1], $set_a[2], $set_a[3], $set_a[4],
                        $set_b[0], $set_b[1], $set_b[2], $set_b[3], $set_b[4], $photos_json
                    ]);
                    $last_id = (string)$pdo->lastInsertId();
                    $draw_id = ($last_id !== '' && $last_id !== '0') ? $last_id : $hash;
                    $draw_hash_code = $hash;
                }

                $audit_target = ($draw_hash_code !== '') ? $draw_hash_code : $draw_id;
                log_system_audit($pdo, "SUBMITTED_DRAW_VERIFICATION", $audit_target, ($_POST['lat'] ?: '0') . ',' . ($_POST['lng'] ?: '0'));

                $ref_stmt = $pdo->prepare("SELECT certificate_ref, draw_hash_code FROM draws WHERE draw_hash_code = ? OR draw_date = ? ORDER BY id DESC LIMIT 1");
                $ref_stmt->execute([$draw_hash_code, $draw_date]);
                $draw_meta = $ref_stmt->fetch();

                $ref_code = $draw_meta['certificate_ref'] ?? '';
                $hash_for_link = !empty($draw_meta['draw_hash_code']) ? $draw_meta['draw_hash_code'] : ($draw_hash_code !== '' ? $draw_hash_code : $draw_id);

                $partners = $pdo->query("SELECT u.email, u.full_name FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_code = 'PARTNER' AND u.is_active = 1")->fetchAll();
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                $review_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . BASE_URL . "Home/Draws/Review/?id=" . urlencode($hash_for_link);

                foreach ($partners as $partner) {
                    $subj = "Action Required: Pending Draw Verification Ref " . $ref_code;
                    $msg = "<p>Hello <strong>" . htmlspecialchars($partner['full_name']) . "</strong>,</p>
                            <p>A new lottery draw verification (" . REQUIRED_SNAPSHOT_COUNT . " snapshots attached) has been submitted by <strong>" . htmlspecialchars($_SESSION['user_name']) . "</strong> and is pending your review and e-signature.</p>
                            <p><strong>Reference:</strong> " . htmlspecialchars($ref_code) . "<br><strong>Session:</strong> " . htmlspecialchars($_POST['draw_session']) . " (" . htmlspecialchars($draw_date) . ")</p>
                            <p><a href='" . $review_link . "' style='background: #E81A3B; color: #fff; padding: 10px 15px; text-decoration: none; border-radius: 4px; font-weight: bold;'>Review & E-Sign Certificate</a></p>";
                    send_system_email($partner['email'], $subj, $msg);
                }

                unset($_SESSION['initiate_mfa_step']);
                echo "<script>
                    Object.keys(localStorage).forEach(k => {
                        if (k.startsWith('ldc_draw_form_') || k.startsWith('ldc_gps_locked_')) localStorage.removeItem(k);
                    });
                    window.location.href = '" . BASE_URL . "Home/Draws/Review/?submitted=1&id=" . urlencode($hash_for_link) . "';
                </script>";
                exit;
            }
        }
    }
}

// Resolve initial values from pre-created/resumed draw or POST
$val_draw_id      = (string)($_POST['draw_id'] ?? ($resume_draw['id'] ?? ''));
$val_draw_hash    = (string)($_POST['draw_hash_code'] ?? ($resume_draw['draw_hash_code'] ?? ''));
$val_draw_date    = (string)($_POST['draw_date'] ?? ($resume_draw['draw_date'] ?? ''));
$val_draw_session = (string)($_POST['draw_session'] ?? ($resume_draw['draw_session'] ?? ''));

$raw_start_time = (string)($_POST['start_time'] ?? (!empty($resume_draw['start_time']) ? date('H:i:s', strtotime((string)$resume_draw['start_time'])) : ''));
$raw_end_time   = (string)($_POST['end_time'] ?? (!empty($resume_draw['end_time']) ? date('H:i:s', strtotime((string)$resume_draw['end_time'])) : ''));

$is_initial_zero_start = ($raw_start_time === '00:00:00' && empty($_POST['start_time']) && empty($_POST['start_time_manual']));
$is_initial_zero_end   = ($raw_end_time === '00:00:00' && empty($_POST['end_time']) && empty($_POST['end_time_manual']));

$val_start_time = $is_initial_zero_start ? '' : $raw_start_time;
$val_end_time   = $is_initial_zero_end ? '' : $raw_end_time;

$val_start_manual = (string)($_POST['start_time_manual'] ?? ($val_start_time !== '' ? substr($val_start_time, 0, 5) : ''));
$val_end_manual   = (string)($_POST['end_time_manual'] ?? ($val_end_time !== '' ? substr($val_end_time, 0, 5) : ''));

$is_backdated_draw = ($val_draw_date !== '' && $val_draw_date < $today_date);
$is_date_locked    = ($val_draw_date !== '');

$val_venue = (string)($_POST['venue'] ?? ($resume_draw['venue_location'] ?? 'Rwanda Broadcasting Agency (RBA) Studio, Kacyiru'));
$val_lat   = (string)($_POST['lat'] ?? ($resume_draw['gps_latitude'] ?? ''));
$val_lng   = (string)($_POST['lng'] ?? ($resume_draw['gps_longitude'] ?? ''));

$chk_pre_val  = isset($_POST['chk_pre']) ? true : (!empty($resume_draw['chk_pre_draw']));
$chk_obs_val  = isset($_POST['chk_obs']) ? true : (!empty($resume_draw['chk_draw_obs']));
$chk_post_val = isset($_POST['chk_post']) ? true : (!empty($resume_draw['chk_post_draw']));

$curr_exc_status = (string)($_POST['exception_status'] ?? ($resume_draw['exception_status'] ?? 'NONE'));
$curr_exc_ref    = (string)($_POST['exception_report_ref'] ?? ($resume_draw['exception_report_ref'] ?? ''));
$curr_exc_desc   = (string)($_POST['exception_details'] ?? ($resume_draw['exception_details'] ?? ''));

$default_conclusion = "Based on the procedures performed and the evidence obtained, the scheduled draw was observed and the results verified against the agreed records. No significant exception was identified in the procedures covered by this verification.";
$val_notes = (string)($_POST['procedure_notes'] ?? ($resume_draw['procedure_notes'] ?? $default_conclusion));

// Check if all 5 numbers in DB are 0 (fresh pre-created draft) so we don't overwrite user's localStorage with 0s
$db_has_nonzero_numbers = false;
if ($resume_draw) {
    for ($k = 1; $k <= 5; $k++) {
        if ((int)($resume_draw['num' . $k . '_a'] ?? 0) !== 0 || (int)($resume_draw['num' . $k . '_b'] ?? 0) !== 0) {
            $db_has_nonzero_numbers = true;
            break;
        }
    }
}

$page_title = "Initiate Draw Verification | LDC System";
include_once __DIR__ . '/../../../assets/includes/header.php';
include_once __DIR__ . '/../../../assets/includes/sidebar.php';

$slot_labels = [
    0 => 'Snapshot 1 — Central System / Result Screen',
    1 => 'Snapshot 2 — Draw Machine / Chamber View',
    2 => 'Snapshot 3 — Post-Draw Reconciliation / Secondary View',
];
?>

<style>
    .ball-preview {
        display: inline-flex;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        color: #fff;
        font-weight: 800;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        position: relative;
        overflow: hidden;
        box-shadow: inset -4px -4px 10px rgba(0,0,0,0.6), 0 4px 8px rgba(0,0,0,0.3);
    }
    .ball-preview::after {
        content: '';
        position: absolute;
        top: 4px; left: 7px; width: 12px; height: 6px;
        background: rgba(255, 255, 255, 0.45);
        border-radius: 50%;
        transform: rotate(-35deg);
    }
    .ball-red { background: radial-gradient(circle at 35% 35%, #ff5269 0%, #d90429 60%, #800e13 100%); }
    .ball-blue { background: radial-gradient(circle at 35% 35%, #4cc9f0 0%, #0077b6 60%, #03045e 100%); }
    .ball-gold { background: radial-gradient(circle at 35% 35%, #ffb703 0%, #fb8500 60%, #b7094c 100%); }
    .ball-jade { background: radial-gradient(circle at 35% 35%, #52b788 0%, #2d6a4f 100%, #1b4332 100%); }
    .ball-burgundy { background: radial-gradient(circle at 35% 35%, #ad2831 0%, #641220 100%, #3a0ca3 100%); }

    .snapshots-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
        margin-top: 10px;
    }
    @media (max-width: 900px) {
        .snapshots-grid { grid-template-columns: 1fr; }
    }
    .snapshot-slot-card {
        background: #f8f9fa;
        border: 1px solid var(--pale-charcoal);
        border-radius: 4px;
        padding: 12px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .snapshot-slot-card.is-ready {
        border-color: var(--jade);
        background: #f6fcf8;
    }
    .snapshot-thumb-box {
        margin-top: 10px;
        background: #ffffff;
        border: 1px dashed #cbd5e1;
        border-radius: 4px;
        padding: 8px;
        text-align: center;
    }
    .snapshot-thumb-box img {
        max-width: 100%;
        max-height: 150px;
        border-radius: 3px;
        margin: 0 auto;
        display: block;
    }
    .cam-trigger-btn {
        background: var(--charcoal);
        color: #ffffff;
        border: none;
        padding: 6px 10px;
        border-radius: 3px;
        font-size: 0.76rem;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .gps-retry-btn {
        background: #ffffff;
        border: 1px solid var(--pale-charcoal);
        color: var(--charcoal);
        padding: 4px 10px;
        border-radius: 3px;
        font-size: 0.76rem;
        font-weight: 700;
        cursor: pointer;
    }
    input.date-locked {
        background: #e9ecef !important;
        color: #333;
        font-weight: 700;
        cursor: not-allowed;
    }
</style>

<script>
    const RBA_LAT = -1.93843;
    const RBA_LNG = 30.08274;
    const TODAY_DATE = <?= json_encode($today_date) ?>;
    const IS_BACKDATED = <?= $is_backdated_draw ? 'true' : 'false' ?>;
    const IS_DATE_LOCKED = <?= $is_date_locked ? 'true' : 'false' ?>;
    const REQUIRED_SNAPSHOTS = <?= REQUIRED_SNAPSHOT_COUNT ?>;
    const DRAW_KEY_SUFFIX = <?= json_encode($val_draw_hash) ?> || 'default';
    const STORAGE_KEY = 'ldc_draw_form_' + DRAW_KEY_SUFFIX;
    const GPS_LOCK_KEY = 'ldc_gps_locked_' + DRAW_KEY_SUFFIX;

    let gpsTrackedOnce = false;

    function lockDrawDateInput() {
        const dateInput = document.getElementById('draw_date');
        const lockBadge = document.getElementById('date-locked-badge');
        if (!dateInput || !dateInput.value) return;
        dateInput.readOnly = true;
        dateInput.classList.add('date-locked');
        if (lockBadge) lockBadge.style.display = 'inline-block';
    }

    function applyTimingMode() {
        const manualBox = document.getElementById('backdate-clock-controls');
        const autoBox   = document.getElementById('live-auto-controls');
        const submitBtn = document.getElementById('submit-btn');

        if (IS_BACKDATED) {
            if (manualBox) manualBox.style.display = 'block';
            if (autoBox) autoBox.style.display = 'none';
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.title = '';
            }
        } else {
            if (manualBox) manualBox.style.display = 'none';
            if (autoBox) autoBox.style.display = 'block';
        }
    }

    function validateManualClockOrder() {
        if (!IS_BACKDATED) return true;
        const sEl = document.getElementById('start_time_manual');
        const eEl = document.getElementById('end_time_manual');
        const warnEl = document.getElementById('clock-order-warning');
        if (!sEl || !eEl) return true;

        const sVal = sEl.value.trim();
        const eVal = eEl.value.trim();
        if (sVal && eVal && eVal <= sVal) {
            if (warnEl) {
                warnEl.textContent = 'End time (' + eVal + ') must be later than start time (' + sVal + ').';
                warnEl.style.display = 'block';
            }
            return false;
        }
        if (warnEl) {
            warnEl.style.display = 'none';
            warnEl.textContent = '';
        }
        return true;
    }

    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371e3;
        const φ1 = lat1 * Math.PI / 180;
        const φ2 = lat2 * Math.PI / 180;
        const Δφ = (lat2 - lat1) * Math.PI / 180;
        const Δλ = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
                  Math.cos(φ1) * Math.cos(φ2) *
                  Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
        return R * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
    }

    function lockGpsUI(lat, lng, htmlMessage) {
        gpsTrackedOnce = true;
        const latEl = document.getElementById('lat');
        const lngEl = document.getElementById('lng');
        const statusEl = document.getElementById('loc-status');

        if (latEl) latEl.value = lat;
        if (lngEl) lngEl.value = lng;
        if (statusEl) {
            statusEl.innerHTML = htmlMessage + ' <span style="background:#e9ecef;color:#333;padding:2px 6px;border-radius:3px;font-size:0.72rem;margin-left:4px;"><i class="fa-solid fa-lock"></i> Locked</span>';
        }
        localStorage.setItem(GPS_LOCK_KEY, JSON.stringify({ lat: lat, lng: lng, html: htmlMessage }));
    }

    function renderGpsSuccess(position) {
        if (gpsTrackedOnce) return;
        const userLat = position.coords.latitude;
        const userLng = position.coords.longitude;
        const acc = position.coords.accuracy ? Math.round(position.coords.accuracy) : null;

        const dist = calculateDistance(userLat, userLng, RBA_LAT, RBA_LNG);
        const distText = dist < 1000 ? Math.round(dist) + " meters" : (dist / 1000).toFixed(2) + " km";
        const accText = acc !== null ? " (±" + acc + "m)" : "";

        const msg = '<i class="fa-solid fa-location-dot" style="color: var(--jade);"></i> GPS Captured (' +
            userLat.toFixed(5) + ', ' + userLng.toFixed(5) + ')' + accText +
            ' — <strong>' + distText + '</strong> from RBA Studio.';

        lockGpsUI(userLat, userLng, msg);
        saveFormBackup();
    }

    function captureLocation(highAccuracy = true, forceUnlock = false) {
        const statusEl = document.getElementById('loc-status');

        if (forceUnlock) {
            gpsTrackedOnce = false;
            localStorage.removeItem(GPS_LOCK_KEY);
        }

        if (gpsTrackedOnce) return;

        // If already tracked once for this draw, restore and keep locked
        const cachedGps = localStorage.getItem(GPS_LOCK_KEY);
        if (cachedGps && !forceUnlock) {
            try {
                const g = JSON.parse(cachedGps);
                if (g && g.lat && g.lng && g.html) {
                    lockGpsUI(g.lat, g.lng, g.html);
                    return;
                }
            } catch (e) {}
        }

        if (!navigator.geolocation) {
            if (statusEl) {
                statusEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color: var(--gold);"></i> Geolocation is not supported by this browser.';
            }
            return;
        }

        if (window.location.protocol !== 'https:' &&
            window.location.hostname !== 'localhost' &&
            window.location.hostname !== '127.0.0.1') {
            if (statusEl) {
                statusEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color: var(--gold);"></i> GPS requires HTTPS in most browsers (Manual location fallback active).';
            }
            return;
        }

        if (statusEl) {
            statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' +
                (highAccuracy ? 'Tracking GPS once...' : 'Trying network/coarse location...');
        }

        navigator.geolocation.getCurrentPosition(
            renderGpsSuccess,
            function(error) {
                if (gpsTrackedOnce) return;
                if (highAccuracy && (error.code === error.TIMEOUT || error.code === error.POSITION_UNAVAILABLE)) {
                    captureLocation(false, false);
                    return;
                }

                let msg = 'GPS unavailable (Manual location fallback active).';
                if (error.code === error.PERMISSION_DENIED) {
                    msg = 'Location permission denied in browser (Manual location fallback active).';
                } else if (error.code === error.TIMEOUT) {
                    msg = 'GPS timed out — click Retry GPS or continue with manual venue.';
                } else if (error.code === error.POSITION_UNAVAILABLE) {
                    msg = 'Location signal unavailable on this device (Manual location fallback active).';
                }

                if (statusEl) {
                    statusEl.innerHTML = '<i class="fa-solid fa-circle-info" style="color: var(--slate);"></i> ' + msg;
                }
            },
            {
                enableHighAccuracy: highAccuracy,
                timeout: highAccuracy ? 10000 : 15000,
                maximumAge: 86400000
            }
        );
    }

    function toggleExceptionFields() {
        const selected = document.querySelector('input[name="exception_status"]:checked');
        const mode = selected ? selected.value : 'NONE';
        const refInput = document.getElementById('exception_report_ref');
        const descInput = document.getElementById('exception_details');

        if (refInput) {
            const isSig = (mode === 'SIGNIFICANT_EXCEPTION');
            refInput.disabled = !isSig;
            refInput.style.background = isSig ? '#ffffff' : '#f1f3f4';
            if (!isSig) refInput.value = '';
        }

        if (descInput) {
            descInput.required = (mode === 'MINOR_RESOLVED');
        }
    }

    function startDrawSession() {
        const chosenDate = document.getElementById('draw_date').value || TODAY_DATE;
        const sessionName = document.querySelector('input[name="draw_session"]').value.trim();
        if (!sessionName) {
            alert("Please enter a Draw Session Name before starting the session.");
            document.querySelector('input[name="draw_session"]').focus();
            return;
        }

        if (confirm("SMART WARNING: Clicking OK records the exact live start timestamp and saves a secure draft in the database. Proceed?")) {
            const now = new Date();
            const timeString = String(now.getHours()).padStart(2, '0') + ':' +
                               String(now.getMinutes()).padStart(2, '0') + ':' +
                               String(now.getSeconds()).padStart(2, '0');

            document.getElementById('start_time').value = timeString;

            const formData = new FormData();
            formData.append('ajax_action', 'create_draft');
            formData.append('draw_id', document.getElementById('draw_id').value.trim());
            formData.append('draw_hash_code', document.getElementById('draw_hash_code').value.trim());
            formData.append('draw_date', chosenDate);
            formData.append('draw_session', sessionName);
            formData.append('start_time', timeString);
            for (let i = 1; i <= 5; i++) {
                formData.append('n' + i + '_a', document.getElementById('seta_n' + i).value);
                formData.append('n' + i + '_b', document.getElementById('setb_n' + i).value);
            }

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(async res => {
                const rawText = await res.text();
                try {
                    return JSON.parse(rawText);
                } catch (e) {
                    throw new Error("Server output: " + rawText.substring(0, 150));
                }
            })
            .then(data => {
                if (data.status === 'success') {
                    document.getElementById('draw_id').value = String(data.draw_id || '');
                    if (data.draw_hash_code) {
                        document.getElementById('draw_hash_code').value = String(data.draw_hash_code);
                    }
                    document.getElementById('start-session-btn').style.display = 'none';
                    document.getElementById('end-session-btn').disabled = false;
                    document.getElementById('end-session-btn').style.opacity = '1';
                    document.getElementById('session-started-badge').style.display = 'inline-block';
                    document.getElementById('session-saved-notice').innerHTML = '<i class="fa-solid fa-cloud-arrow-up" style="color: var(--jade);"></i> Draft securely saved (Ref: ' + data.ref + ')';
                    saveFormBackup();
                } else {
                    document.getElementById('start_time').value = '';
                    alert("Draft creation error: " + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                document.getElementById('start_time').value = '';
                alert("Draft Error: " + err.message);
            });
        }
    }

    function endDrawSession() {
        if (confirm("Conclude draw session and capture final completion timestamp?")) {
            const now = new Date();
            const timeString = String(now.getHours()).padStart(2, '0') + ':' +
                               String(now.getMinutes()).padStart(2, '0') + ':' +
                               String(now.getSeconds()).padStart(2, '0');

            document.getElementById('end_time').value = timeString;
            const endBtn = document.getElementById('end-session-btn');
            endBtn.disabled = true;
            endBtn.innerText = "Session Concluded";
            endBtn.style.opacity = '0.6';

            const submitBtn = document.getElementById('submit-btn');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
            }
            saveFormBackup();
        }
    }

    function validateAndPreview(input, prefix) {
        let val = parseInt(input.value, 10);
        if (!isNaN(val)) {
            if (val < 0) input.value = 0;
            if (val > 90) input.value = 90;
        }
        updateBallPreview(prefix);
        saveFormBackup();
    }

    function updateBallPreview(prefix) {
        const colors = ['ball-red', 'ball-blue', 'ball-gold', 'ball-jade', 'ball-burgundy'];
        const container = document.getElementById(prefix + '-balls-preview');
        if (!container) return;
        container.innerHTML = '';

        for (let i = 1; i <= 5; i++) {
            const inputVal = document.getElementById(prefix + '_n' + i).value;
            const ball = document.createElement('div');
            ball.className = 'ball-preview ' + colors[(i - 1) % colors.length];
            ball.innerText = (inputVal !== '' && !isNaN(parseInt(inputVal, 10))) ? parseInt(inputVal, 10) : '--';
            container.appendChild(ball);
        }
    }

    // Dedicated camera trigger (rear camera) while file input stays open for gallery/files
    function triggerInstantCamera(slotIdx) {
        const camInp = document.getElementById('snapshot_cam_' + slotIdx);
        if (camInp) {
            camInp.value = '';
            camInp.click();
        }
    }

    function handleSnapshotSelection(sourceInput, slotIdx) {
        if (!sourceInput || !sourceInput.files || !sourceInput.files[0]) {
            refreshSnapshotCounter();
            return;
        }

        const file = sourceInput.files[0];
        const mainFileInp = document.getElementById('snapshot_file_' + slotIdx);
        const camInp = document.getElementById('snapshot_cam_' + slotIdx);

        // If captured via the camera button, copy into the main file input so PHP POST receives it too
        if (camInp && sourceInput === camInp && mainFileInp && typeof DataTransfer !== 'undefined') {
            const dt = new DataTransfer();
            dt.items.add(file);
            mainFileInp.files = dt.files;
        }

        previewSnapshotSlot(mainFileInp || sourceInput, slotIdx);
    }

    function refreshSnapshotCounter() {
        const counterEl = document.getElementById('snapshot-count-badge');
        if (!counterEl) return;

        let count = 0;
        let savedArr = ['', '', ''];
        const hiddenJson = document.getElementById('existing_photos_json');
        if (hiddenJson && hiddenJson.value) {
            try {
                const parsed = JSON.parse(hiddenJson.value);
                if (Array.isArray(parsed)) savedArr = parsed;
            } catch (e) {}
        }

        for (let i = 0; i < REQUIRED_SNAPSHOTS; i++) {
            const inp = document.getElementById('snapshot_file_' + i);
            const card = document.getElementById('snapshot_card_' + i);
            const hasLocalFile = inp && inp.files && inp.files.length > 0;
            const hasSavedPath = savedArr[i] && String(savedArr[i]).trim() !== '';
            const ready = hasLocalFile || hasSavedPath;
            if (ready) count++;
            if (card) {
                card.classList.toggle('is-ready', ready);
            }
        }

        counterEl.textContent = count + ' / ' + REQUIRED_SNAPSHOTS + ' snapshots attached';
        counterEl.style.color = (count === REQUIRED_SNAPSHOTS) ? 'var(--jade)' : 'var(--burgundy)';
    }

    function previewSnapshotSlot(input, slotIdx) {
        const box = document.getElementById('snapshot_preview_box_' + slotIdx);
        const img = document.getElementById('snapshot_preview_img_' + slotIdx);
        const cap = document.getElementById('snapshot_preview_cap_' + slotIdx);
        const hiddenJson = document.getElementById('existing_photos_json');

        if (!input || !input.files || !input.files[0]) {
            refreshSnapshotCounter();
            return;
        }

        const file = input.files[0];
        const reader = new FileReader();
        reader.onload = function(e) {
            if (img) img.src = e.target.result;
            if (cap) cap.textContent = file.name + ' — saving...';
            if (box) box.style.display = 'block';
        };
        reader.readAsDataURL(file);

        const drawId = (document.getElementById('draw_id')?.value || '').trim();
        const drawHash = (document.getElementById('draw_hash_code')?.value || '').trim();

        if (!drawId && !drawHash) {
            if (cap) cap.textContent = file.name + ' (attached locally; start session or submit to store)';
            refreshSnapshotCounter();
            return;
        }

        const fd = new FormData();
        fd.append('ajax_action', 'upload_snapshot');
        fd.append('slot_index', String(slotIdx));
        fd.append('draw_id', drawId);
        fd.append('draw_hash_code', drawHash);
        fd.append('snapshot_file', file);

        fetch(window.location.href, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(async res => {
            const txt = await res.text();
            try {
                return JSON.parse(txt);
            } catch (e) {
                throw new Error(txt.substring(0, 140) || 'Invalid upload response');
            }
        })
        .then(data => {
            if (data.status === 'success' && Array.isArray(data.photos)) {
                if (hiddenJson) hiddenJson.value = JSON.stringify(data.photos);
                if (img && data.url) img.src = data.url;
                if (cap) cap.textContent = file.name + ' (saved)';
                const mainInp = document.getElementById('snapshot_file_' + slotIdx);
                if (mainInp) mainInp.required = false;
                refreshSnapshotCounter();
            } else {
                throw new Error(data.message || 'Upload failed');
            }
        })
        .catch(err => {
            if (cap) cap.textContent = file.name + ' (upload error: ' + err.message + ')';
            refreshSnapshotCounter();
        });
    }

    function saveFormBackup() {
        const formObj = {
            draw_id: String(document.getElementById('draw_id').value || ''),
            draw_hash_code: String(document.getElementById('draw_hash_code').value || ''),
            draw_date: document.getElementById('draw_date').value,
            draw_session: document.querySelector('input[name="draw_session"]').value,
            start_time: document.getElementById('start_time') ? document.getElementById('start_time').value : '',
            end_time: document.getElementById('end_time') ? document.getElementById('end_time').value : '',
            start_time_manual: document.getElementById('start_time_manual') ? document.getElementById('start_time_manual').value : '',
            end_time_manual: document.getElementById('end_time_manual') ? document.getElementById('end_time_manual').value : '',
            venue: document.querySelector('input[name="venue"]').value,
            procedure_notes: document.querySelector('textarea[name="procedure_notes"]').value,
            chk_pre: document.querySelector('input[name="chk_pre"]').checked,
            chk_obs: document.querySelector('input[name="chk_obs"]').checked,
            chk_post: document.querySelector('input[name="chk_post"]').checked,
            seta: [1, 2, 3, 4, 5].map(i => String(document.getElementById('seta_n' + i).value ?? '')),
            setb: [1, 2, 3, 4, 5].map(i => String(document.getElementById('setb_n' + i).value ?? '')),
            exception_status: (document.querySelector('input[name="exception_status"]:checked') || { value: 'NONE' }).value,
            exception_report_ref: document.getElementById('exception_report_ref') ? document.getElementById('exception_report_ref').value : '',
            exception_details: document.getElementById('exception_details') ? document.getElementById('exception_details').value : ''
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(formObj));
    }

    window.addEventListener('DOMContentLoaded', function() {
        applyTimingMode();

        const startTimeEl = document.getElementById('start_time');
        const endTimeEl = document.getElementById('end_time');
        const startBtn = document.getElementById('start-session-btn');
        const endBtn = document.getElementById('end-session-btn');
        const startedBadge = document.getElementById('session-started-badge');
        const submitBtn = document.getElementById('submit-btn');

        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            try {
                const data = JSON.parse(saved);
                const sessionEl = document.querySelector('input[name="draw_session"]');
                const venueEl = document.querySelector('input[name="venue"]');
                const notesEl = document.querySelector('textarea[name="procedure_notes"]');

                if (sessionEl && data.draw_session && !<?= isset($_POST['draw_session']) ? 'true' : 'false' ?>) {
                    sessionEl.value = data.draw_session;
                }
                if (startTimeEl && !startTimeEl.value && data.start_time) startTimeEl.value = data.start_time;
                if (endTimeEl && !endTimeEl.value && data.end_time) endTimeEl.value = data.end_time;

                const sMan = document.getElementById('start_time_manual');
                const eMan = document.getElementById('end_time_manual');
                if (sMan && !sMan.value && data.start_time_manual) sMan.value = data.start_time_manual;
                if (eMan && !eMan.value && data.end_time_manual) eMan.value = data.end_time_manual;

                if (venueEl && data.venue && !<?= isset($_POST['venue']) ? 'true' : 'false' ?>) venueEl.value = data.venue;
                if (notesEl && data.procedure_notes && !<?= isset($_POST['procedure_notes']) ? 'true' : 'false' ?>) notesEl.value = data.procedure_notes;

                if (typeof data.chk_pre === 'boolean' && !<?= isset($_POST['chk_pre']) ? 'true' : 'false' ?>) {
                    document.querySelector('input[name="chk_pre"]').checked = data.chk_pre;
                }
                if (typeof data.chk_obs === 'boolean' && !<?= isset($_POST['chk_obs']) ? 'true' : 'false' ?>) {
                    document.querySelector('input[name="chk_obs"]').checked = data.chk_obs;
                }
                if (typeof data.chk_post === 'boolean' && !<?= isset($_POST['chk_post']) ? 'true' : 'false' ?>) {
                    document.querySelector('input[name="chk_post"]').checked = data.chk_post;
                }

                // Restore Set A & Set B numbers whenever localStorage has them
                if (Array.isArray(data.seta)) {
                    data.seta.forEach((val, idx) => {
                        const inp = document.getElementById('seta_n' + (idx + 1));
                        if (inp && val !== '' && val !== undefined && val !== null) {
                            inp.value = String(val);
                        }
                    });
                }
                if (Array.isArray(data.setb)) {
                    data.setb.forEach((val, idx) => {
                        const inp = document.getElementById('setb_n' + (idx + 1));
                        if (inp && val !== '' && val !== undefined && val !== null) {
                            inp.value = String(val);
                        }
                    });
                }

                if (data.exception_status && !<?= isset($_POST['exception_status']) ? 'true' : 'false' ?>) {
                    const radio = document.querySelector('input[name="exception_status"][value="' + data.exception_status + '"]');
                    if (radio) radio.checked = true;
                }
                const refEl = document.getElementById('exception_report_ref');
                const descEl = document.getElementById('exception_details');
                if (refEl && !refEl.value && data.exception_report_ref) {
                    refEl.value = data.exception_report_ref;
                }
                if (descEl && !descEl.value && data.exception_details) {
                    descEl.value = data.exception_details;
                }
            } catch (e) {
                console.error("Backup restoration error:", e);
            }
        }

        // Track GPS once (or restore locked GPS)
        captureLocation(true, false);

        if (IS_DATE_LOCKED || document.getElementById('draw_date')?.value) {
            lockDrawDateInput();
        }

        toggleExceptionFields();
        validateManualClockOrder();
        refreshSnapshotCounter();

        if (!IS_BACKDATED) {
            const startTimeVal = startTimeEl ? startTimeEl.value : '';
            const endTimeVal = endTimeEl ? endTimeEl.value : '';

            if (startTimeVal) {
                if (startBtn) startBtn.style.display = 'none';
                if (startedBadge) startedBadge.style.display = 'inline-block';
            }

            if (endTimeVal) {
                if (endBtn) {
                    endBtn.disabled = true;
                    endBtn.innerText = "Session Concluded";
                    endBtn.style.opacity = '0.6';
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                }
            } else if (startTimeVal && endBtn) {
                endBtn.disabled = false;
                endBtn.style.opacity = '1';
            }
        }

        updateBallPreview('seta');
        updateBallPreview('setb');

        document.querySelectorAll('input, textarea, select').forEach(el => {
            if (el.type === 'file') return;
            el.addEventListener('input', () => {
                validateManualClockOrder();
                saveFormBackup();
            });
            el.addEventListener('change', () => {
                validateManualClockOrder();
                saveFormBackup();
            });
        });
    });
</script>

<main class="main-content">
    <div style="background: var(--charcoal); color: #fff; padding: 20px; border-radius: 4px; margin-bottom: 20px; border-left: 6px solid var(--red);">
        <h2><i class="fa-solid fa-dice"></i> Initiate Draw Session Verification</h2>
        <p style="font-size: 0.85rem; color: var(--pale-charcoal);">
            <?php if ($val_draw_hash !== ''): ?>
                Hashcode: <code style="color:#fff;"><?= htmlspecialchars($val_draw_hash) ?></code> ·
            <?php endif; ?>
            <?= $is_backdated_draw ? 'Backdated session detected: pick start and end hour/minute on the clock (stored in 24h, shown AM/PM on reports).' : 'Live/current session: use Start & End controls to record timestamps automatically.' ?>
        </p>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <p id="loc-status" style="font-weight: bold; font-size: 0.85rem; margin: 0;"><i class="fa-solid fa-spinner fa-spin"></i> Checking GPS location...</p>
            <button type="button" onclick="captureLocation(true, true)" class="gps-retry-btn" title="Unlock and Refresh GPS Coordinates">
                <i class="fa-solid fa-rotate"></i> Retry GPS
            </button>
        </div>
        <span id="session-saved-notice" style="font-size: 0.8rem; font-weight: bold; color: var(--slate);"></span>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" style="background: #fff; padding: 20px; border: 1px solid var(--pale-charcoal); border-radius: 4px;">
        <input type="hidden" name="draw_id" id="draw_id" value="<?= htmlspecialchars($val_draw_id) ?>">
        <input type="hidden" name="draw_hash_code" id="draw_hash_code" value="<?= htmlspecialchars($val_draw_hash) ?>">
        <input type="hidden" name="existing_photos_json" id="existing_photos_json" value="<?= htmlspecialchars(json_encode($saved_photos)) ?>">
        <input type="hidden" name="lat" id="lat" value="<?= htmlspecialchars($val_lat) ?>">
        <input type="hidden" name="lng" id="lng" value="<?= htmlspecialchars($val_lng) ?>">

        <!-- Locked Draw Date & Session Name -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <label style="font-size: 0.85rem; font-weight: 600;">Draw Date</label>
                    <span id="date-locked-badge" style="display: <?= $is_date_locked ? 'inline-block' : 'none' ?>; font-size: 0.72rem; font-weight: 700; color: var(--burgundy);">
                        <i class="fa-solid fa-lock"></i> Locked
                    </span>
                </div>
                <input type="date"
                       name="draw_date"
                       id="draw_date"
                       value="<?= htmlspecialchars($val_draw_date) ?>"
                       max="<?= htmlspecialchars($today_date) ?>"
                       <?= $is_date_locked ? 'readonly class="date-locked"' : '' ?>
                       required
                       style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;">
            </div>
            <div>
                <label style="font-size: 0.85rem; font-weight: 600;">Draw Session Name</label>
                <input type="text" name="draw_session" id="draw_session" value="<?= htmlspecialchars($val_draw_session) ?>" placeholder="e.g. Intego 5/90 Evening Main Draw" required style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;">
            </div>
        </div>

        <!-- Timing Section: Clock Hour/Minute when Backdated, Automatic Start/End when Current/Live -->
        <div id="draw-details-fields">
            <div style="background: #f4f6f9; padding: 15px; border-radius: 4px; border: 1px solid var(--pale-charcoal); margin-bottom: 20px;">
                <h4 style="color: var(--charcoal); margin-bottom: 10px;">
                    <i class="fa-solid fa-stopwatch"></i> Session Timing Controls
                </h4>

                <!-- Backdated: Clock Picker (Hour & Minute) -->
                <div id="backdate-clock-controls" style="display: <?= $is_backdated_draw ? 'block' : 'none' ?>;">
                    <p style="font-size: 0.8rem; color: var(--slate); margin: 0 0 10px;">
                        Backdated draw (<strong><?= htmlspecialchars($val_draw_date) ?></strong> &lt; <strong><?= htmlspecialchars($today_date) ?></strong>): choose hour and minute on the clock (end time must be after start time).
                    </p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label for="start_time_manual" style="font-size: 0.85rem; font-weight: 600;">Start Time (Hour &amp; Minute)</label>
                            <input type="time"
                                   name="start_time_manual"
                                   id="start_time_manual"
                                   step="60"
                                   value="<?= htmlspecialchars($val_start_manual) ?>"
                                   style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); border-radius: 4px; margin-top: 4px; background: #fff; font-weight: 600;">
                        </div>
                        <div>
                            <label for="end_time_manual" style="font-size: 0.85rem; font-weight: 600;">End Time (Hour &amp; Minute)</label>
                            <input type="time"
                                   name="end_time_manual"
                                   id="end_time_manual"
                                   step="60"
                                   value="<?= htmlspecialchars($val_end_manual) ?>"
                                   style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); border-radius: 4px; margin-top: 4px; background: #fff; font-weight: 600;">
                        </div>
                    </div>
                    <div id="clock-order-warning" style="display:none; color:#842029; font-size:0.78rem; font-weight:700; margin-top:8px;"></div>
                </div>

                <!-- Current/Live Date: Automatic Start & End Controls -->
                <div id="live-auto-controls" style="display: <?= $is_backdated_draw ? 'none' : 'block' ?>;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; align-items: center;">
                        <div>
                            <label style="font-size: 0.85rem; font-weight: 600;">Start Time (Auto-Recorded)</label>
                            <input type="text" name="start_time" id="start_time" value="<?= htmlspecialchars($val_start_time) ?>" readonly style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px; background: #e9ecef;">
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; font-weight: 600;">End Time (Auto-Recorded)</label>
                            <input type="text" name="end_time" id="end_time" value="<?= htmlspecialchars($val_end_time) ?>" readonly style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px; background: #e9ecef;">
                        </div>
                        <div style="display: flex; gap: 8px; align-items: flex-end; padding-top: 20px;">
                            <button type="button" id="start-session-btn" onclick="startDrawSession()" style="background: var(--jade); color: #fff; border: none; padding: 10px 14px; font-weight: bold; border-radius: 4px; cursor: pointer;">
                                <i class="fa-solid fa-play"></i> Start Session
                            </button>
                            <button type="button" id="end-session-btn" onclick="endDrawSession()" disabled style="background: var(--burgundy); color: #fff; border: none; padding: 10px 14px; font-weight: bold; border-radius: 4px; cursor: pointer; opacity: 0.6;">
                                <i class="fa-solid fa-stop"></i> End Session
                            </button>
                        </div>
                    </div>
                    <div style="margin-top: 10px;">
                        <span id="session-started-badge" style="display: none; background: var(--jade); color: #fff; padding: 3px 8px; font-size: 0.75rem; font-weight: bold; border-radius: 3px;">
                            <i class="fa-solid fa-circle-check"></i> Session Active
                        </span>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="font-size: 0.85rem; font-weight: 600;">Venue Location</label>
                <input type="text" name="venue" value="<?= htmlspecialchars($val_venue) ?>" required style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;">
            </div>

            <h4 style="margin-top: 25px; color: var(--burgundy); border-bottom: 2px solid var(--pale-charcoal); padding-bottom: 5px;">Procedure Checklist Confirmation</h4>
            <div style="margin: 15px 0; display: flex; flex-direction: column; gap: 10px;">
                <label><input type="checkbox" name="chk_pre" value="1" <?= $chk_pre_val ? 'checked' : '' ?> required> Pre-draw machinery &amp; equipment verification completed per Game Rules</label>
                <label><input type="checkbox" name="chk_obs" value="1" <?= $chk_obs_val ? 'checked' : '' ?> required> Live draw observation executed strictly per Live Draw SOPs</label>
                <label><input type="checkbox" name="chk_post" value="1" <?= $chk_post_val ? 'checked' : '' ?> required> Post-draw reconciliation and audit completed successfully</label>
            </div>

            <h4 style="margin-top: 25px; color: var(--burgundy); border-bottom: 2px solid var(--pale-charcoal); padding-bottom: 5px;">
                3. EXCEPTIONS IDENTIFIED
            </h4>

            <div style="margin: 14px 0; display: flex; flex-direction: column; gap: 10px; font-size: 0.9rem;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="radio" name="exception_status" value="NONE" <?= $curr_exc_status === 'NONE' ? 'checked' : '' ?> onchange="toggleExceptionFields()">
                    <span><strong>None.</strong></span>
                </label>

                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="radio" name="exception_status" value="MINOR_RESOLVED" <?= $curr_exc_status === 'MINOR_RESOLVED' ? 'checked' : '' ?> onchange="toggleExceptionFields()">
                    <span>Minor issue(s) noted and resolved during the session (describe briefly below).</span>
                </label>

                <label style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span style="display: inline-flex; align-items: center; gap: 8px;">
                        <input type="radio" name="exception_status" value="SIGNIFICANT_EXCEPTION" <?= $curr_exc_status === 'SIGNIFICANT_EXCEPTION' ? 'checked' : '' ?> onchange="toggleExceptionFields()">
                        <span>Significant exception(s) - see separate Exception Report (reference:</span>
                    </span>
                    <input type="text"
                           name="exception_report_ref"
                           id="exception_report_ref"
                           value="<?= htmlspecialchars($curr_exc_ref) ?>"
                           placeholder="e.g. EX-2026-014"
                           style="padding: 5px 8px; border: 1px solid var(--pale-charcoal); border-radius: 3px; width: 180px; font-size: 0.85rem;">
                    <span>).</span>
                </label>
            </div>

            <div style="margin-bottom: 20px;">
                <label for="exception_details" style="font-size: 0.85rem; font-weight: 600; color: var(--charcoal);">
                    Brief description of any minor issues, if applicable
                </label>
                <textarea name="exception_details"
                          id="exception_details"
                          rows="2"
                          placeholder="Brief description of any minor issues, if applicable"
                          style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); border-radius: 4px; margin-top: 4px; font-size: 0.85rem;"><?= htmlspecialchars($curr_exc_desc) ?></textarea>
            </div>

            <h4 style="margin-top: 25px; color: var(--burgundy); border-bottom: 2px solid var(--pale-charcoal); padding-bottom: 5px;">Winning Numbers Double-Entry Verification (0–90)</h4>

            <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <p style="font-size: 0.85rem; font-weight: 600; color: var(--charcoal); margin-bottom: 8px;"><i class="fa-solid fa-pen-nib"></i> Primary Record Entry (Set A):</p>
                    <div style="display: flex; gap: 8px;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php
                            if (isset($_POST['n'.$i.'_a'])) {
                                $n_a_val = (string)$_POST['n'.$i.'_a'];
                            } elseif ($db_has_nonzero_numbers && isset($resume_draw['num'.$i.'_a']) && $resume_draw['num'.$i.'_a'] !== null) {
                                $n_a_val = (string)$resume_draw['num'.$i.'_a'];
                            } else {
                                $n_a_val = '';
                            }
                            ?>
                            <input type="number" name="n<?= $i ?>_a" id="seta_n<?= $i ?>" value="<?= htmlspecialchars($n_a_val) ?>" min="0" max="90" step="1" inputmode="numeric" pattern="[0-9]*" required placeholder="0-90" oninput="validateAndPreview(this, 'seta')" style="width: 65px; padding: 8px; text-align: center; font-weight: bold;">
                        <?php endfor; ?>
                    </div>
                </div>
                <div>
                    <p style="font-size: 0.75rem; color: var(--slate); margin-bottom: 4px; font-weight: bold;">Shiny Balls Preview (Set A):</p>
                    <div id="seta-balls-preview" style="display: flex; gap: 8px;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <div class="ball-preview ball-red">--</div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <p style="font-size: 0.85rem; font-weight: 600; color: var(--charcoal); margin-bottom: 8px;"><i class="fa-solid fa-copy"></i> Independent Dual-Control Re-entry (Set B):</p>
                    <div style="display: flex; gap: 8px;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php
                            if (isset($_POST['n'.$i.'_b'])) {
                                $n_b_val = (string)$_POST['n'.$i.'_b'];
                            } elseif ($db_has_nonzero_numbers && isset($resume_draw['num'.$i.'_b']) && $resume_draw['num'.$i.'_b'] !== null) {
                                $n_b_val = (string)$resume_draw['num'.$i.'_b'];
                            } else {
                                $n_b_val = '';
                            }
                            ?>
                            <input type="number" name="n<?= $i ?>_b" id="setb_n<?= $i ?>" value="<?= htmlspecialchars($n_b_val) ?>" min="0" max="90" step="1" inputmode="numeric" pattern="[0-9]*" required placeholder="0-90" oninput="validateAndPreview(this, 'setb')" style="width: 65px; padding: 8px; text-align: center; font-weight: bold;">
                        <?php endfor; ?>
                    </div>
                    <small style="color: var(--slate); font-size: 0.75rem;">Set B numbers must match Set A exactly (0–90).</small>
                </div>
                <div>
                    <p style="font-size: 0.75rem; color: var(--slate); margin-bottom: 4px; font-weight: bold;">Shiny Balls Preview (Set B):</p>
                    <div id="setb-balls-preview" style="display: flex; gap: 8px;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <div class="ball-preview ball-red">--</div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Multiple Snapshots (Exactly 3) — Gallery/File Picker + Instant Camera Option -->
            <div style="margin-bottom: 25px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <label style="font-size: 0.85rem; font-weight: 700; color: var(--charcoal);">
                        Official Draw Snapshots (Exactly <?= REQUIRED_SNAPSHOT_COUNT ?> Required)
                    </label>
                    <span id="snapshot-count-badge" style="font-size: 0.8rem; font-weight: 800; color: var(--burgundy);">
                        0 / <?= REQUIRED_SNAPSHOT_COUNT ?> snapshots attached
                    </span>
                </div>
                <p style="font-size: 0.76rem; color: var(--slate); margin: 4px 0 8px;">
                    Choose an image from your gallery/files or tap <strong>Take Photo</strong> to snap directly with your camera. Each photo saves automatically so refreshing won't lose it.
                </p>

                <div class="snapshots-grid">
                    <?php for ($s = 0; $s < REQUIRED_SNAPSHOT_COUNT; $s++): ?>
                        <?php $slot_saved = $saved_photos[$s] ?? ''; ?>
                        <div id="snapshot_card_<?= $s ?>" class="snapshot-slot-card <?= $slot_saved !== '' ? 'is-ready' : '' ?>">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 6px; margin-bottom: 8px;">
                                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--charcoal);">
                                        <?= htmlspecialchars($slot_labels[$s] ?? ('Snapshot ' . ($s + 1))) ?>
                                    </span>
                                    <button type="button" onclick="triggerInstantCamera(<?= $s ?>)" class="cam-trigger-btn" title="Take Photo with Camera">
                                        <i class="fa-solid fa-camera"></i> Take Photo
                                    </button>
                                </div>

                                <!-- Standard gallery / file picker (no capture attribute) -->
                                <input type="file"
                                       name="central_photos[<?= $s ?>]"
                                       id="snapshot_file_<?= $s ?>"
                                       accept="image/*"
                                       onchange="handleSnapshotSelection(this, <?= $s ?>)"
                                       <?= $slot_saved === '' ? 'required' : '' ?>
                                       style="width: 100%; font-size: 0.76rem;">

                                <!-- Dedicated rear-camera input -->
                                <input type="file"
                                       id="snapshot_cam_<?= $s ?>"
                                       accept="image/*"
                                       capture="environment"
                                       onchange="handleSnapshotSelection(this, <?= $s ?>)"
                                       style="display: none;">
                            </div>

                            <div id="snapshot_preview_box_<?= $s ?>" class="snapshot-thumb-box" style="display: <?= $slot_saved !== '' ? 'block' : 'none' ?>;">
                                <img id="snapshot_preview_img_<?= $s ?>"
                                     src="<?= $slot_saved !== '' ? htmlspecialchars(BASE_URL . ltrim($slot_saved, '/')) : '' ?>"
                                     alt="Snapshot <?= $s + 1 ?> Preview">
                                <div id="snapshot_preview_cap_<?= $s ?>" style="font-size: 0.72rem; color: var(--slate); margin-top: 5px; word-break: break-all;">
                                    <?= $slot_saved !== '' ? htmlspecialchars(basename($slot_saved)) . ' (saved)' : '' ?>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-size: 0.85rem; font-weight: 600;">Verification Conclusion</label>
                <textarea name="procedure_notes" rows="2" placeholder="Verification Conclusion" style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;"><?= htmlspecialchars($val_notes) ?>Based on the procedures performed and the evidence obtained, the scheduled draw was observed and the results verified against the agreed records. No significant exception was identified in the procedures covered by this verification.</textarea>
            </div>

            <!-- MFA Step 1 vs Step 2 Submission Controls -->
            <?php if ($mfa_step === 1): ?>
                <button type="submit" name="action_request_submit_mfa" id="submit-btn" <?= $is_backdated_draw ? '' : 'disabled' ?> class="flat-cta" style="cursor: pointer; background: var(--red); color: #fff; border: none; padding: 12px 25px; border-radius: 4px; font-weight: bold; opacity: <?= $is_backdated_draw ? '1' : '0.6' ?>;" title="<?= $is_backdated_draw ? '' : 'Conclude session to enable submission' ?>">
                    <i class="fa-solid fa-envelope-circle-check"></i> Send MFA Code to Submit
                </button>
            <?php else: ?>
                <div style="background: #f4f6f9; border: 1px solid var(--pale-charcoal); border-left: 4px solid var(--red); padding: 16px; border-radius: 4px; max-width: 440px;">
                    <label style="font-size: 0.85rem; font-weight: 700; color: var(--charcoal);">Enter 6-Digit Submission MFA Code</label>
                    <p style="font-size: 0.78rem; color: var(--slate); margin: 4px 0 8px;">Check your email for the 6-digit security code to finalize submission.</p>
                    <input type="text" name="submit_mfa_code" maxlength="6" inputmode="numeric" pattern="[0-9]*" required placeholder="000000" style="width: 100%; padding: 10px; border: 1px solid var(--pale-charcoal); border-radius: 4px; text-align: center; font-size: 1.2rem; letter-spacing: 5px; font-weight: 700;">
                    <div style="display: flex; gap: 10px; margin-top: 12px;">
                        <button type="submit" name="action_confirm_submit" style="background: var(--jade); color: #fff; border: none; padding: 11px 18px; border-radius: 4px; font-weight: bold; cursor: pointer;">
                            <i class="fa-solid fa-paper-plane"></i> Verify MFA &amp; Submit Draw
                        </button>
                        <button type="submit" name="action_cancel_submit_mfa" formnovalidate style="background: var(--pale-charcoal); color: var(--charcoal); border: none; padding: 11px 14px; border-radius: 4px; font-weight: 600; cursor: pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </form>
</main>

<?php include_once __DIR__ . '/../../../assets/includes/footer.php'; ?>