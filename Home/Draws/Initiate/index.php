<?php
require_once __DIR__ . '/../../../assets/config/config.php';
require_once __DIR__ . '/../../../assets/config/mailer.php';
check_auth(['PRIMARY_VERIFIER', 'ADMIN']);

$error = '';
$success = '';
$mfa_step = (int)($_SESSION['initiate_mfa_step'] ?? 1);
$today_date = date('Y-m-d');

const REQUIRED_SNAPSHOT_COUNT = 3;
const RESUMABLE_STATUSES = ['DRAFT', 'IN_PROGRESS'];

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

// Helper to find a resumable DRAFT / IN_PROGRESS draw by id, draw_hash_code, certificate_ref, or draw_date
$find_resumable_draw = function (array $criteria) use ($pdo) {
    $id_str   = trim((string)($criteria['id'] ?? ''));
    $hash_str = trim((string)($criteria['hash'] ?? ''));
    $ref_str  = trim((string)($criteria['ref'] ?? ''));
    $date_str = trim((string)($criteria['date'] ?? ''));

    $conditions = [];
    $params = [
        ':st1' => RESUMABLE_STATUSES[0],
        ':st2' => RESUMABLE_STATUSES[1],
    ];

    if ($id_str !== '' && ctype_digit($id_str)) {
        $conditions[] = "id = :id_num";
        $params[':id_num'] = (int)$id_str;
    }
    if ($hash_str !== '') {
        $conditions[] = "draw_hash_code = :hash_val";
        $params[':hash_val'] = $hash_str;
    }
    if ($id_str !== '' && !ctype_digit($id_str)) {
        $conditions[] = "draw_hash_code = :id_hash_val";
        $params[':id_hash_val'] = $id_str;
    }
    if ($ref_str !== '') {
        $conditions[] = "certificate_ref = :ref_val";
        $params[':ref_val'] = $ref_str;
    }
    if ($date_str !== '') {
        $conditions[] = "draw_date = :date_val";
        $params[':date_val'] = $date_str;
    }

    if (empty($conditions)) {
        return null;
    }

    $sql = "SELECT * FROM draws
            WHERE status IN (:st1, :st2)
              AND (" . implode(' OR ', $conditions) . ")
            ORDER BY id DESC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

// 1. Resolve resume key from GET (?resume=... / ?draft_id=... / ?hash=... / ?id=...) or POST / date
$resume_key = trim((string)($_GET['resume'] ?? ($_GET['draft_id'] ?? ($_GET['hash'] ?? ($_GET['id'] ?? '')))));
$post_draw_id   = trim((string)($_POST['draw_id'] ?? ''));
$post_draw_hash = trim((string)($_POST['draw_hash_code'] ?? ''));
$target_date    = trim((string)($_POST['draw_date'] ?? ($_GET['draw_date'] ?? ($resume_key === '' ? $today_date : ''))));

$resume_draw = $find_resumable_draw([
    'id'   => $post_draw_id !== '' ? $post_draw_id : (ctype_digit($resume_key) ? $resume_key : ''),
    'hash' => $post_draw_hash !== '' ? $post_draw_hash : $resume_key,
    'ref'  => $resume_key,
    'date' => $target_date,
]);

// Decode previously persisted photo paths from hidden input (JSON array) or resumed draft
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

$active_draw_id   = $post_draw_id !== '' ? $post_draw_id : (string)($resume_draw['id'] ?? '');
$active_draw_hash = $post_draw_hash !== '' ? $post_draw_hash : (string)($resume_draw['draw_hash_code'] ?? '');

// Fetch already-recorded draw dates (excluding the active draft/in-progress draw being resumed)
$taken_dates = [];
$exclude_clauses = [];
$exclude_params = [];
if ($active_draw_id !== '' && ctype_digit($active_draw_id)) {
    $exclude_clauses[] = "id != :excl_id";
    $exclude_params[':excl_id'] = (int)$active_draw_id;
}
if ($active_draw_hash !== '') {
    $exclude_clauses[] = "draw_hash_code != :excl_hash";
    $exclude_params[':excl_hash'] = $active_draw_hash;
}
$date_sql = "SELECT DISTINCT draw_date FROM draws WHERE draw_date IS NOT NULL";
if (!empty($exclude_clauses)) {
    $date_sql .= " AND " . implode(" AND ", $exclude_clauses);
}
$date_sql .= " ORDER BY draw_date DESC";
$date_stmt = $pdo->prepare($date_sql);
$date_stmt->execute($exclude_params);
while ($row_date = $date_stmt->fetch(PDO::FETCH_COLUMN)) {
    $taken_dates[] = $row_date;
}

// Helper to persist all 3 snapshot slots safely to /uploads/ and sync with draft
$persist_all_snapshots = function ($draw_id_val, $draw_hash_val, array $current_saved) use ($pdo, $parse_db_photos) {
    $draw_id_str   = trim((string)$draw_id_val);
    $draw_hash_str = trim((string)$draw_hash_val);
    $final_paths = ['', '', ''];
    for ($i = 0; $i < REQUIRED_SNAPSHOT_COUNT; $i++) {
        $final_paths[$i] = trim((string)($current_saved[$i] ?? ''));
    }

    $match_parts = [];
    $match_params = [];
    if ($draw_id_str !== '' && ctype_digit($draw_id_str)) {
        $match_parts[] = "id = :m_id";
        $match_params[':m_id'] = (int)$draw_id_str;
    }
    if ($draw_hash_str !== '') {
        $match_parts[] = "draw_hash_code = :m_hash";
        $match_params[':m_hash'] = $draw_hash_str;
    } elseif ($draw_id_str !== '' && !ctype_digit($draw_id_str)) {
        $match_parts[] = "draw_hash_code = :m_id_hash";
        $match_params[':m_id_hash'] = $draw_id_str;
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

// Count how many of the 3 required snapshot slots have a file
$count_attached_snapshots = function (array $paths) {
    $cnt = 0;
    foreach ($paths as $p) {
        if (trim((string)$p) !== '') {
            $cnt++;
        }
    }
    return $cnt;
};

// 1. Handle AJAX Draft Creation / Resume / Date Check Request (Before any HTML output)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_draft') {
    ini_set('display_errors', 0);
    error_reporting(0);

    header('Content-Type: application/json');

    try {
        $existing_draw_id   = trim((string)($_POST['draw_id'] ?? ''));
        $existing_draw_hash = trim((string)($_POST['draw_hash_code'] ?? ''));
        $draw_date = trim($_POST['draw_date'] ?? date('Y-m-d'));
        $draw_session = trim($_POST['draw_session'] ?? 'Unnamed Session');
        $time_only = trim($_POST['start_time'] ?? date('H:i:s'));

        if ($draw_date > date('Y-m-d')) {
            echo json_encode(['status' => 'error', 'message' => 'Future dates are strictly disallowed for lottery draw verification.']);
            exit;
        }

        // Check if a DRAFT or IN_PROGRESS draw already exists for this draw_id, draw_hash_code, or draw_date
        $existing_in_progress = $find_resumable_draw([
            'id'   => $existing_draw_id,
            'hash' => $existing_draw_hash,
            'date' => $draw_date,
        ]);

        if ($existing_in_progress) {
            $start_datetime = $draw_date . ' ' . $time_only;
            $kept_hash = !empty($existing_in_progress['draw_hash_code'])
                ? (string)$existing_in_progress['draw_hash_code']
                : ($existing_draw_hash !== '' ? $existing_draw_hash : md5(uniqid((string)$existing_in_progress['id'], true)));

            $pdo->prepare("UPDATE draws SET draw_date = ?, draw_hash_code = ?, draw_session = ?, start_time = ?, status = 'IN_PROGRESS' WHERE id = ?")
                ->execute([$draw_date, $kept_hash, $draw_session, $start_datetime, $existing_in_progress['id']]);

            echo json_encode([
                'status'         => 'success',
                'draw_id'        => (string)$existing_in_progress['id'],
                'draw_hash_code' => $kept_hash,
                'ref'            => (string)$existing_in_progress['certificate_ref'],
                'start_time'     => $time_only,
                'resumed'        => true,
            ]);
            exit;
        }

        // Enforce 1 draw per day rule against non-draft/submitted/completed draws on that date
        $chk_dup = $pdo->prepare("SELECT id, certificate_ref, status FROM draws WHERE draw_date = ? ORDER BY id DESC LIMIT 1");
        $chk_dup->execute([$draw_date]);
        if ($existing_draw = $chk_dup->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode([
                'status'  => 'error',
                'message' => "A draw ({$existing_draw['status']}) is already recorded for {$draw_date} (Ref: {$existing_draw['certificate_ref']}). Only one draw per day is permitted."
            ]);
            exit;
        }

        $start_datetime = $draw_date . ' ' . $time_only;

        $ref = generate_reference($pdo, $draw_date);
        $hash = ($existing_draw_hash !== '') ? $existing_draw_hash : md5(uniqid($ref, true));

        $stmt = $pdo->prepare("INSERT INTO draws 
            (draw_hash_code, certificate_ref, draw_date, draw_session, start_time, venue_location, primary_verifier_id, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'IN_PROGRESS')");

        $success_draft = $stmt->execute([
            $hash, $ref, $draw_date, $draw_session, $start_datetime,
            'Rwanda Broadcasting Agency (RBA) Studio, Kacyiru', $_SESSION['user_id']
        ]);

        if ($success_draft) {
            $new_id = (string)$pdo->lastInsertId();
            if ($new_id === '' || $new_id === '0') {
                $new_id = $hash;
            }
            echo json_encode([
                'status'         => 'success',
                'draw_id'        => $new_id,
                'draw_hash_code' => $hash,
                'ref'            => $ref,
                'start_time'     => $time_only,
                'resumed'        => false,
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database execution failed.']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 2. Handle MFA Code Request & Final Submission (Before any HTML output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    $draw_id        = trim((string)($_POST['draw_id'] ?? ''));
    $draw_hash_code = trim((string)($_POST['draw_hash_code'] ?? ''));
    $draw_date      = trim($_POST['draw_date'] ?? '');
    $today          = date('Y-m-d');

    $matching_draft = $find_resumable_draw([
        'id'   => $draw_id,
        'hash' => $draw_hash_code,
        'date' => $draw_date,
    ]);
    if ($matching_draft) {
        $draw_id = (string)$matching_draft['id'];
        if (!empty($matching_draft['draw_hash_code'])) {
            $draw_hash_code = (string)$matching_draft['draw_hash_code'];
        }
    }

    // Enforce 1 draw per day (excluding current draft's own ID/hash if present)
    $dup_sql = "SELECT id, certificate_ref FROM draws WHERE draw_date = :d_date";
    $dup_params = [':d_date' => $draw_date];
    if ($draw_id !== '' && ctype_digit($draw_id)) {
        $dup_sql .= " AND id != :excl_id";
        $dup_params[':excl_id'] = (int)$draw_id;
    }
    if ($draw_hash_code !== '') {
        $dup_sql .= " AND draw_hash_code != :excl_hash";
        $dup_params[':excl_hash'] = $draw_hash_code;
    }
    $dup_sql .= " LIMIT 1";
    $dup_stmt = $pdo->prepare($dup_sql);
    $dup_stmt->execute($dup_params);
    $duplicate_draw = $dup_stmt->fetch();

    // Exceptions handling
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
    } elseif ($draw_date === '' || $draw_date > $today) {
        $error = "Error: Future or empty dates are strictly disallowed for lottery draw verification.";
        $saved_photos = $persist_all_snapshots($draw_id, $draw_hash_code, $saved_photos);
    } elseif ($duplicate_draw) {
        $error = "Error: A draw is already recorded for " . htmlspecialchars($draw_date) . " (Ref: " . htmlspecialchars($duplicate_draw['certificate_ref']) . "). Only one draw per day is permitted.";
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
    } elseif (empty($_POST['start_time']) || empty($_POST['end_time'])) {
        $error = "Error: Both start session and end session timestamps must be recorded.";
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
                $audit_target = ($draw_id !== '') ? $draw_id : ($draw_hash_code !== '' ? $draw_hash_code : null);
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
                $mfa_step = 2;
            } else {
                $pdo->prepare("UPDATE mfa_codes SET is_used = 1 WHERE id = ?")->execute([$valid_mfa['id']]);

                $start_datetime = $draw_date . ' ' . $_POST['start_time'];
                $end_datetime = $draw_date . ' ' . $_POST['end_time'];
                $photos_json = json_encode(array_values($saved_photos));

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

                    if ($draw_id !== '' && ctype_digit($draw_id)) {
                        $where_parts[] = "id = :w_id";
                        $upd_params[':w_id'] = (int)$draw_id;
                    }
                    if ($draw_hash_code !== '') {
                        $where_parts[] = "draw_hash_code = :w_hash";
                        $upd_params[':w_hash'] = $draw_hash_code;
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
                    $hash = ($draw_hash_code !== '') ? $draw_hash_code : md5(uniqid($ref, true));
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

                $audit_target = ($draw_id !== '') ? $draw_id : $draw_hash_code;
                log_system_audit($pdo, "SUBMITTED_DRAW_VERIFICATION", $audit_target, ($_POST['lat'] ?: '0') . ',' . ($_POST['lng'] ?: '0'));

                $ref_stmt = $pdo->prepare("SELECT certificate_ref, draw_hash_code FROM draws WHERE draw_date = ? ORDER BY id DESC LIMIT 1");
                $ref_stmt->execute([$draw_date]);
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
                    localStorage.removeItem('ldc_draw_form');
                    window.location.href = '" . BASE_URL . "Home/Draws/Review/?submitted=1&id=" . urlencode($hash_for_link) . "';
                </script>";
                exit;
            }
        }
    }
}

// Resolve initial values from POST, resumed DB draft, or defaults
$val_draw_id      = (string)($_POST['draw_id'] ?? ($resume_draw['id'] ?? $active_draw_id));
$val_draw_hash    = (string)($_POST['draw_hash_code'] ?? ($resume_draw['draw_hash_code'] ?? $active_draw_hash));
$val_draw_date    = (string)($_POST['draw_date'] ?? ($resume_draw['draw_date'] ?? $today_date));
$val_draw_session = (string)($_POST['draw_session'] ?? ($resume_draw['draw_session'] ?? ''));
$val_start_time   = (string)($_POST['start_time'] ?? (!empty($resume_draw['start_time']) ? date('H:i:s', strtotime((string)$resume_draw['start_time'])) : ''));
$val_end_time     = (string)($_POST['end_time'] ?? (!empty($resume_draw['end_time']) ? date('H:i:s', strtotime((string)$resume_draw['end_time'])) : ''));
$val_venue        = (string)($_POST['venue'] ?? ($resume_draw['venue_location'] ?? 'Rwanda Broadcasting Agency (RBA) Studio, Kacyiru'));
$val_lat          = (string)($_POST['lat'] ?? ($resume_draw['gps_latitude'] ?? ''));
$val_lng          = (string)($_POST['lng'] ?? ($resume_draw['gps_longitude'] ?? ''));

$chk_pre_val  = isset($_POST['chk_pre']) ? true : (!empty($resume_draw['chk_pre_draw']));
$chk_obs_val  = isset($_POST['chk_obs']) ? true : (!empty($resume_draw['chk_draw_obs']));
$chk_post_val = isset($_POST['chk_post']) ? true : (!empty($resume_draw['chk_post_draw']));

$curr_exc_status = (string)($_POST['exception_status'] ?? ($resume_draw['exception_status'] ?? 'NONE'));
$curr_exc_ref    = (string)($_POST['exception_report_ref'] ?? ($resume_draw['exception_report_ref'] ?? ''));
$curr_exc_desc   = (string)($_POST['exception_details'] ?? ($resume_draw['exception_details'] ?? ''));

$default_conclusion = "Based on the procedures performed and the evidence obtained, the scheduled draw was observed and the results verified against the agreed records. No significant exception was identified in the procedures covered by this verification.";
$val_notes = (string)($_POST['procedure_notes'] ?? ($resume_draw['procedure_notes'] ?? $default_conclusion));

// 3. Include HTML Layout Headers
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
    .non-today-banner {
        display: none;
        background: #fef7e0;
        border: 1px solid #f5d98e;
        border-left: 5px solid var(--gold);
        color: #7c5200;
        padding: 12px 15px;
        border-radius: 4px;
        margin-bottom: 16px;
        font-size: 0.85rem;
    }
    .in-progress-banner {
        display: none;
        background: #eefaf3;
        border: 1px solid #b7e4c7;
        border-left: 5px solid var(--jade);
        color: #1b4332;
        padding: 12px 15px;
        border-radius: 4px;
        margin-bottom: 16px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    .taken-date-banner {
        display: none;
        background: #fdecea;
        border: 1px solid #f5c2c7;
        border-left: 5px solid var(--red);
        color: #842029;
        padding: 12px 15px;
        border-radius: 4px;
        margin-bottom: 16px;
        font-size: 0.85rem;
        font-weight: 600;
    }
</style>

<script>
    const RBA_LAT = -1.93843;
    const RBA_LNG = 30.08274;
    const TODAY_DATE = <?= json_encode($today_date) ?>;
    const TAKEN_DRAW_DATES = new Set(<?= json_encode($taken_dates) ?>);
    const REQUIRED_SNAPSHOTS = <?= REQUIRED_SNAPSHOT_COUNT ?>;
    const HAS_SERVER_RESUME = <?= $resume_draw ? 'true' : 'false' ?>;

    function isDateTaken(dateStr) {
        return TAKEN_DRAW_DATES.has(dateStr);
    }

    function hasActiveDraftSession() {
        const drawIdEl = document.getElementById('draw_id');
        const drawHashEl = document.getElementById('draw_hash_code');
        const hasId = !!(drawIdEl && drawIdEl.value.trim() !== '');
        const hasHash = !!(drawHashEl && drawHashEl.value.trim() !== '');
        return hasId || hasHash || HAS_SERVER_RESUME;
    }

    function unlockAllFormControls() {
        const container = document.getElementById('draw-details-fields');
        if (container) {
            container.style.opacity = '1';
            container.querySelectorAll('input, textarea, select, button').forEach(el => {
                if (el.id === 'end-session-btn') {
                    const hasStart = !!(document.getElementById('start_time')?.value.trim());
                    const hasEnd = !!(document.getElementById('end_time')?.value.trim());
                    el.disabled = !hasStart || hasEnd;
                    return;
                }
                if (el.id === 'submit-btn') {
                    const hasEnd = !!(document.getElementById('end_time')?.value.trim());
                    el.disabled = !hasEnd;
                    return;
                }
                if (el.id === 'exception_report_ref') {
                    return;
                }
                el.disabled = false;
            });
        }
        const startBtn = document.getElementById('start-session-btn');
        if (startBtn) {
            startBtn.disabled = false;
            startBtn.style.opacity = '1';
        }
        toggleExceptionFields();
    }

    function setRemainingFieldsDisabled(disabledState) {
        if (!disabledState) {
            unlockAllFormControls();
            return;
        }
        const container = document.getElementById('draw-details-fields');
        if (!container) return;
        container.querySelectorAll('input, textarea, select, button').forEach(el => {
            if (el.id === 'end-session-btn' || el.id === 'submit-btn' || el.id === 'exception_report_ref') {
                return;
            }
            el.disabled = true;
        });
        container.style.opacity = '0.55';
    }

    function handleDrawDateChange(interactive = false) {
        const dateInput = document.getElementById('draw_date');
        const takenBanner = document.getElementById('taken-date-banner');
        const takenText = document.getElementById('taken-date-text');
        const inProgressBanner = document.getElementById('in-progress-banner');
        const inProgressText = document.getElementById('in-progress-text');
        const nonTodayBanner = document.getElementById('non-today-banner');
        const nonTodayText = document.getElementById('non-today-text');
        const startBtn = document.getElementById('start-session-btn');

        if (!dateInput) return;
        const chosen = dateInput.value;

        // Always keep fields unlocked when resuming or editing an IN_PROGRESS / DRAFT draw
        if (hasActiveDraftSession()) {
            if (takenBanner) takenBanner.style.display = 'none';
            if (nonTodayBanner) nonTodayBanner.style.display = 'none';
            if (inProgressBanner && inProgressText) {
                const hashVal = (document.getElementById('draw_hash_code')?.value || '').trim();
                inProgressText.textContent = 'Draw on ' + chosen + ' is IN PROGRESS / DRAFT' + (hashVal ? ' (Hash: ' + hashVal.substring(0, 12) + '…)' : '') + ' — continue editing.';
                inProgressBanner.style.display = 'block';
            }
            unlockAllFormControls();
            return;
        }

        if (inProgressBanner) inProgressBanner.style.display = 'none';

        if (chosen && isDateTaken(chosen)) {
            if (nonTodayBanner) nonTodayBanner.style.display = 'none';
            if (takenBanner && takenText) {
                takenText.textContent = 'A draw is already recorded for ' + chosen + '. Only one draw per day is permitted—please select an available date before proceeding.';
                takenBanner.style.display = 'block';
            }
            if (startBtn && startBtn.style.display !== 'none') {
                startBtn.disabled = true;
                startBtn.style.opacity = '0.5';
            }
            setRemainingFieldsDisabled(true);
            return;
        } else {
            if (takenBanner) takenBanner.style.display = 'none';
            unlockAllFormControls();
        }

        if (chosen && chosen !== TODAY_DATE) {
            if (interactive) {
                const proceed = confirm(
                    "DATE NOTICE: You are choosing " + chosen + ", which is NOT today's date (" + TODAY_DATE + ").\n\n" +
                    "Click OK to confirm you intend to log a draw for " + chosen + " before proceeding with other fields, or Cancel to reset to today's date."
                );
                if (!proceed) {
                    dateInput.value = TODAY_DATE;
                    handleDrawDateChange(false);
                    saveFormBackup();
                    return;
                }
            }
            if (nonTodayBanner && nonTodayText) {
                nonTodayText.innerHTML = 'You have selected <strong>' + chosen + '</strong>, which is not today\'s date (<strong>' + TODAY_DATE + '</strong>). Please ensure all session details correspond to this date.';
                nonTodayBanner.style.display = 'block';
            }
        } else {
            if (nonTodayBanner) nonTodayBanner.style.display = 'none';
        }
    }

    function resetDateToToday() {
        const dateInput = document.getElementById('draw_date');
        if (dateInput) {
            dateInput.value = TODAY_DATE;
            handleDrawDateChange(false);
            saveFormBackup();
        }
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

    function renderGpsSuccess(position) {
        const userLat = position.coords.latitude;
        const userLng = position.coords.longitude;
        const acc = position.coords.accuracy ? Math.round(position.coords.accuracy) : null;

        const latEl = document.getElementById('lat');
        const lngEl = document.getElementById('lng');
        const statusEl = document.getElementById('loc-status');

        if (latEl) latEl.value = userLat;
        if (lngEl) lngEl.value = userLng;

        const dist = calculateDistance(userLat, userLng, RBA_LAT, RBA_LNG);
        const distText = dist < 1000 ? Math.round(dist) + " meters" : (dist / 1000).toFixed(2) + " km";
        const accText = acc !== null ? " (±" + acc + "m)" : "";

        if (statusEl) {
            statusEl.innerHTML = '<i class="fa-solid fa-location-dot" style="color: var(--jade);"></i> GPS Active (' +
                userLat.toFixed(5) + ', ' + userLng.toFixed(5) + ')' + accText +
                ' — <strong>' + distText + '</strong> from RBA Studio.';
        }
    }

    function captureLocation(highAccuracy = true) {
        const statusEl = document.getElementById('loc-status');

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
                (highAccuracy ? 'Acquiring GPS coordinates...' : 'Trying network/coarse location...');
        }

        navigator.geolocation.getCurrentPosition(
            renderGpsSuccess,
            function(error) {
                if (highAccuracy && (error.code === error.TIMEOUT || error.code === error.POSITION_UNAVAILABLE)) {
                    captureLocation(false);
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
                maximumAge: 30000
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
        const chosenDate = document.getElementById('draw_date').value;
        const isResumingDraft = hasActiveDraftSession();

        if (!chosenDate) {
            alert("Please select a Draw Date first.");
            document.getElementById('draw_date').focus();
            return;
        }
        if (isDateTaken(chosenDate) && !isResumingDraft) {
            alert("A draw already exists for " + chosenDate + ". Only one draw per day is permitted.");
            document.getElementById('draw_date').focus();
            return;
        }

        const sessionName = document.querySelector('input[name="draw_session"]').value.trim();
        if (!sessionName) {
            alert("Please enter a Draw Session Name before starting the session.");
            document.querySelector('input[name="draw_session"]').focus();
            return;
        }

        if (confirm("SMART WARNING: Clicking OK records the exact live start timestamp and saves a secure draft in the database. Proceed?")) {
            const existingStart = document.getElementById('start_time').value.trim();
            const now = new Date();
            const timeString = existingStart || (
                String(now.getHours()).padStart(2, '0') + ':' +
                String(now.getMinutes()).padStart(2, '0') + ':' +
                String(now.getSeconds()).padStart(2, '0')
            );

            document.getElementById('start_time').value = timeString;

            const formData = new FormData();
            formData.append('ajax_action', 'create_draft');
            formData.append('draw_id', document.getElementById('draw_id').value.trim());
            formData.append('draw_hash_code', document.getElementById('draw_hash_code').value.trim());
            formData.append('draw_date', chosenDate);
            formData.append('draw_session', sessionName);
            formData.append('start_time', timeString);

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
                    console.error("Server response was not valid JSON:", rawText);
                    throw new Error("Server output: " + rawText.substring(0, 150));
                }
            })
            .then(data => {
                if (data.status === 'success') {
                    document.getElementById('draw_id').value = String(data.draw_id || '');
                    if (data.draw_hash_code) {
                        document.getElementById('draw_hash_code').value = String(data.draw_hash_code);
                    }
                    document.getElementById('start_time').disabled = false;
                    document.getElementById('start-session-btn').style.display = 'none';
                    document.getElementById('end-session-btn').disabled = false;
                    document.getElementById('end-session-btn').style.opacity = '1';
                    document.getElementById('session-started-badge').style.display = 'inline-block';
                    document.getElementById('session-saved-notice').innerHTML = '<i class="fa-solid fa-cloud-arrow-up" style="color: var(--jade);"></i> ' + (data.resumed ? 'Resumed & updated draft' : 'Draft securely saved') + ' (Ref: ' + data.ref + ')';
                    handleDrawDateChange(false);
                    saveFormBackup();
                } else {
                    if (!existingStart) document.getElementById('start_time').value = '';
                    alert("Draft creation error: " + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                if (!existingStart) document.getElementById('start_time').value = '';
                console.error("Draft sync error:", err);
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

    function triggerInstantCamera(slotIdx) {
        const inp = document.getElementById('snapshot_file_' + slotIdx);
        if (inp) {
            inp.setAttribute('capture', 'environment');
            inp.click();
        }
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

        if (input.files && input.files[0]) {
            const file = input.files[0];
            const reader = new FileReader();
            reader.onload = function(e) {
                if (img) img.src = e.target.result;
                if (cap) cap.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
                if (box) box.style.display = 'block';
                refreshSnapshotCounter();
            };
            reader.readAsDataURL(file);
        } else {
            refreshSnapshotCounter();
        }
    }

    function saveFormBackup() {
        const formObj = {
            draw_id: String(document.getElementById('draw_id').value || ''),
            draw_hash_code: String(document.getElementById('draw_hash_code').value || ''),
            draw_date: document.getElementById('draw_date').value,
            draw_session: document.querySelector('input[name="draw_session"]').value,
            start_time: document.getElementById('start_time').value,
            end_time: document.getElementById('end_time').value,
            venue: document.querySelector('input[name="venue"]').value,
            procedure_notes: document.querySelector('textarea[name="procedure_notes"]').value,
            seta: [1, 2, 3, 4, 5].map(i => document.getElementById('seta_n' + i).value),
            setb: [1, 2, 3, 4, 5].map(i => document.getElementById('setb_n' + i).value),
            started: document.getElementById('start-session-btn').style.display === 'none',
            exception_status: (document.querySelector('input[name="exception_status"]:checked') || { value: 'NONE' }).value,
            exception_report_ref: document.getElementById('exception_report_ref') ? document.getElementById('exception_report_ref').value : '',
            exception_details: document.getElementById('exception_details') ? document.getElementById('exception_details').value : ''
        };
        localStorage.setItem('ldc_draw_form', JSON.stringify(formObj));
    }

    window.addEventListener('DOMContentLoaded', function() {
        captureLocation(true);

        const startTimeEl = document.getElementById('start_time');
        const endTimeEl = document.getElementById('end_time');
        const startBtn = document.getElementById('start-session-btn');
        const endBtn = document.getElementById('end-session-btn');
        const startedBadge = document.getElementById('session-started-badge');
        const submitBtn = document.getElementById('submit-btn');

        const saved = localStorage.getItem('ldc_draw_form');
        if (saved && !document.querySelector('.alert-error')) {
            try {
                const data = JSON.parse(saved);
                const drawIdEl = document.getElementById('draw_id');
                const drawHashEl = document.getElementById('draw_hash_code');
                const drawDateEl = document.getElementById('draw_date');
                const sessionEl = document.querySelector('input[name="draw_session"]');
                const venueEl = document.querySelector('input[name="venue"]');
                const notesEl = document.querySelector('textarea[name="procedure_notes"]');

                const isServerAuthoritative = <?= (isset($_POST['draw_date']) || $resume_draw) ? 'true' : 'false' ?>;
                const sameSessionAsBackup = (!drawIdEl?.value && !drawHashEl?.value) ||
                    (data.draw_id && data.draw_id === drawIdEl?.value) ||
                    (data.draw_hash_code && data.draw_hash_code === drawHashEl?.value);

                if (sameSessionAsBackup) {
                    if (drawIdEl && !drawIdEl.value && data.draw_id) drawIdEl.value = String(data.draw_id);
                    if (drawHashEl && !drawHashEl.value && data.draw_hash_code) drawHashEl.value = String(data.draw_hash_code);
                    if (drawDateEl && data.draw_date && !isServerAuthoritative) {
                        drawDateEl.value = data.draw_date;
                    }
                    if (sessionEl && !sessionEl.value && data.draw_session) sessionEl.value = data.draw_session;
                    if (startTimeEl && !startTimeEl.value && data.start_time) startTimeEl.value = data.start_time;
                    if (endTimeEl && !endTimeEl.value && data.end_time) endTimeEl.value = data.end_time;
                    if (venueEl && data.venue && !isServerAuthoritative) venueEl.value = data.venue;
                    if (notesEl && !notesEl.value && data.procedure_notes) notesEl.value = data.procedure_notes;

                    if (Array.isArray(data.seta)) {
                        data.seta.forEach((val, idx) => {
                            const inp = document.getElementById('seta_n' + (idx + 1));
                            if (inp && inp.value === '' && val !== '') inp.value = val;
                        });
                    }
                    if (Array.isArray(data.setb)) {
                        data.setb.forEach((val, idx) => {
                            const inp = document.getElementById('setb_n' + (idx + 1));
                            if (inp && inp.value === '' && val !== '') inp.value = val;
                        });
                    }

                    if (data.exception_status && !<?= (isset($_POST['exception_status']) || $resume_draw) ? 'true' : 'false' ?>) {
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
                }
            } catch (e) {
                console.error("Backup restoration error:", e);
            }
        }

        toggleExceptionFields();
        handleDrawDateChange(false);
        refreshSnapshotCounter();

        const startTimeVal = startTimeEl ? startTimeEl.value : '';
        const endTimeVal = endTimeEl ? endTimeEl.value : '';

        if (startTimeVal || hasActiveDraftSession()) {
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
        } else if ((startTimeVal || hasActiveDraftSession()) && endBtn) {
            endBtn.disabled = false;
            endBtn.style.opacity = '1';
        }

        updateBallPreview('seta');
        updateBallPreview('setb');

        document.querySelectorAll('input, textarea').forEach(el => {
            el.addEventListener('input', saveFormBackup);
        });
    });
</script>

<main class="main-content">
    <div style="background: var(--charcoal); color: #fff; padding: 20px; border-radius: 4px; margin-bottom: 20px; border-left: 6px solid var(--red);">
        <h2><i class="fa-solid fa-dice"></i> Initiate Draw Session Verification</h2>
        <p style="font-size: 0.85rem; color: var(--pale-charcoal);">Only one draw per day is permitted, and exactly <?= REQUIRED_SNAPSHOT_COUNT ?> snapshots are required before submission.</p>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <p id="loc-status" style="font-weight: bold; font-size: 0.85rem; margin: 0;"><i class="fa-solid fa-spinner fa-spin"></i> Checking GPS location...</p>
            <button type="button" onclick="captureLocation(true)" class="gps-retry-btn" title="Refresh GPS Coordinates">
                <i class="fa-solid fa-rotate"></i> Retry GPS
            </button>
        </div>
        <span id="session-saved-notice" style="font-size: 0.8rem; font-weight: bold; color: var(--slate);">
            <?php if ($resume_draw): ?>
                <i class="fa-solid fa-rotate-left" style="color: var(--jade);"></i> Resuming <?= htmlspecialchars((string)$resume_draw['status']) ?> (Ref: <?= htmlspecialchars((string)$resume_draw['certificate_ref']) ?><?= $val_draw_hash !== '' ? ' · Hash: ' . htmlspecialchars(substr($val_draw_hash, 0, 12)) . '…' : '' ?>)
            <?php endif; ?>
        </span>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Info Banner when draw date already exists in system as IN PROGRESS / DRAFT -->
    <div id="in-progress-banner" class="in-progress-banner">
        <i class="fa-solid fa-pen-to-square"></i> <span id="in-progress-text"></span>
    </div>

    <!-- Warning Banner when selected date already has a completed/submitted draw in DB -->
    <div id="taken-date-banner" class="taken-date-banner">
        <i class="fa-solid fa-ban"></i> <span id="taken-date-text"></span>
    </div>

    <!-- Notice Banner when selected date is not today's date -->
    <div id="non-today-banner" class="non-today-banner">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <i class="fa-solid fa-calendar-day"></i> <strong>Non-Today Date Selected:</strong> <span id="non-today-text"></span>
            </div>
            <button type="button" onclick="resetDateToToday()" style="background: #7c5200; color: #fff; border: none; padding: 5px 10px; border-radius: 3px; font-size: 0.76rem; font-weight: 700; cursor: pointer;">
                Switch Back to Today (<?= htmlspecialchars($today_date) ?>)
            </button>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" style="background: #fff; padding: 20px; border: 1px solid var(--pale-charcoal); border-radius: 4px;">
        <input type="hidden" name="draw_id" id="draw_id" value="<?= htmlspecialchars($val_draw_id) ?>">
        <input type="hidden" name="draw_hash_code" id="draw_hash_code" value="<?= htmlspecialchars($val_draw_hash) ?>">
        <input type="hidden" name="existing_photos_json" id="existing_photos_json" value="<?= htmlspecialchars(json_encode($saved_photos)) ?>">
        <input type="hidden" name="lat" id="lat" value="<?= htmlspecialchars($val_lat) ?>">
        <input type="hidden" name="lng" id="lng" value="<?= htmlspecialchars($val_lng) ?>">

        <!-- Step 1: Draw Date Selection (Editable while in Draft / In Progress) -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
            <div>
                <label style="font-size: 0.85rem; font-weight: 600;">Draw Date <span style="color: var(--slate); font-weight: 400;">(1 draw per day max)</span></label>
                <input type="date"
                       name="draw_date"
                       id="draw_date"
                       value="<?= htmlspecialchars($val_draw_date) ?>"
                       max="<?= htmlspecialchars($today_date) ?>"
                       onchange="handleDrawDateChange(true); saveFormBackup();"
                       required
                       style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;">
                <?php if (!empty($taken_dates)): ?>
                    <small style="display: block; color: var(--slate); font-size: 0.74rem; margin-top: 4px;">
                        Unavailable (already recorded): <strong><?= htmlspecialchars(implode(', ', array_slice($taken_dates, 0, 6))) ?></strong><?= count($taken_dates) > 6 ? ' (+' . (count($taken_dates) - 6) . ' more)' : '' ?>
                    </small>
                <?php endif; ?>
            </div>
            <div>
                <label style="font-size: 0.85rem; font-weight: 600;">Draw Session Name</label>
                <input type="text" name="draw_session" id="draw_session" value="<?= htmlspecialchars($val_draw_session) ?>" placeholder="e.g. Intego 5/90 Evening Main Draw" required style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;">
            </div>
        </div>

        <!-- Remaining Fields -->
        <div id="draw-details-fields">
            <div style="background: #f4f6f9; padding: 15px; border-radius: 4px; border: 1px solid var(--pale-charcoal); margin-bottom: 20px;">
                <h4 style="color: var(--charcoal); margin-bottom: 10px;"><i class="fa-solid fa-stopwatch"></i> Live Session Timing Controls &amp; Instant Draft Locking</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; align-items: center;">
                    <div>
                        <label style="font-size: 0.85rem; font-weight: 600;">Start Time (Preserved)</label>
                        <input type="text" name="start_time" id="start_time" value="<?= htmlspecialchars($val_start_time) ?>" required readonly style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px; background: #e9ecef;">
                    </div>
                    <div>
                        <label style="font-size: 0.85rem; font-weight: 600;">End Time (Preserved)</label>
                        <input type="text" name="end_time" id="end_time" value="<?= htmlspecialchars($val_end_time) ?>" required readonly style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px; background: #e9ecef;">
                    </div>
                    <div style="display: flex; gap: 8px; align-items: flex-end; padding-top: 20px;">
                        <button type="button" id="start-session-btn" onclick="startDrawSession()" style="background: var(--jade); color: #fff; border: none; padding: 10px 14px; font-weight: bold; border-radius: 4px; cursor: pointer;">
                            <i class="fa-solid fa-play"></i> Start Session &amp; Save Draft
                        </button>
                        <button type="button" id="end-session-btn" onclick="endDrawSession()" disabled style="background: var(--burgundy); color: #fff; border: none; padding: 10px 14px; font-weight: bold; border-radius: 4px; cursor: pointer; opacity: 0.6;">
                            <i class="fa-solid fa-stop"></i> End Session
                        </button>
                    </div>
                </div>
                <div style="margin-top: 10px;">
                    <span id="session-started-badge" style="display: none; background: var(--jade); color: #fff; padding: 3px 8px; font-size: 0.75rem; font-weight: bold; border-radius: 3px;">
                        <i class="fa-solid fa-circle-check"></i> Session Active &amp; Draft Locked in DB
                    </span>
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
                            <?php $n_a_val = $_POST['n'.$i.'_a'] ?? (isset($resume_draw['num'.$i.'_a']) && $resume_draw['num'.$i.'_a'] !== null ? (string)$resume_draw['num'.$i.'_a'] : ''); ?>
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
                            <?php $n_b_val = $_POST['n'.$i.'_b'] ?? (isset($resume_draw['num'.$i.'_b']) && $resume_draw['num'.$i.'_b'] !== null ? (string)$resume_draw['num'.$i.'_b'] : ''); ?>
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

            <!-- Multiple Snapshots (Exactly 3) with Instant Mobile Camera Capture & Preview -->
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
                    On mobile, tap <strong>Take Photo</strong> to launch the camera immediately, or choose an image file from your device.
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
                                    <button type="button" onclick="triggerInstantCamera(<?= $s ?>)" class="cam-trigger-btn" title="Open Camera Instantly">
                                        <i class="fa-solid fa-camera"></i> Take Photo
                                    </button>
                                </div>

                                <input type="file"
                                       name="central_photos[<?= $s ?>]"
                                       id="snapshot_file_<?= $s ?>"
                                       accept="image/*"
                                       capture="environment"
                                       onchange="previewSnapshotSlot(this, <?= $s ?>)"
                                       <?= $slot_saved === '' ? 'required' : '' ?>
                                       style="width: 100%; font-size: 0.76rem;">
                            </div>

                            <div id="snapshot_preview_box_<?= $s ?>" class="snapshot-thumb-box" style="display: <?= $slot_saved !== '' ? 'block' : 'none' ?>;">
                                <img id="snapshot_preview_img_<?= $s ?>"
                                     src="<?= $slot_saved !== '' ? htmlspecialchars(BASE_URL . ltrim($slot_saved, '/')) : '' ?>"
                                     alt="Snapshot <?= $s + 1 ?> Preview">
                                <div id="snapshot_preview_cap_<?= $s ?>" style="font-size: 0.72rem; color: var(--slate); margin-top: 5px; word-break: break-all;">
                                    <?= $slot_saved !== '' ? htmlspecialchars(basename($slot_saved)) : '' ?>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-size: 0.85rem; font-weight: 600;">Verification Conclusion</label>
                <textarea name="procedure_notes" rows="2" placeholder="Verification Conclusion" style="width: 100%; padding: 8px; border: 1px solid var(--pale-charcoal); margin-top: 4px;"><?= htmlspecialchars($val_notes) ?></textarea>
            </div>

            <!-- MFA Step 1 vs Step 2 Submission Controls -->
            <?php if ($mfa_step === 1): ?>
                <button type="submit" name="action_request_submit_mfa" id="submit-btn" disabled class="flat-cta" style="cursor: pointer; background: var(--red); color: #fff; border: none; padding: 12px 25px; border-radius: 4px; font-weight: bold; opacity: 0.6;" title="Conclude session to enable submission">
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