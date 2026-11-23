<?php
require_once '../assets/config/config.php';
check_auth();

// Include Header & Sidebar
include_once __DIR__ . '/../assets/includes/header.php';
include_once __DIR__ . '/../assets/includes/sidebar.php';

// Fetch Summary Statistics
$total_certified = $pdo->query("SELECT COUNT(*) FROM draws WHERE status = 'CERTIFIED'")->fetchColumn();
$total_pending = $pdo->query("SELECT COUNT(*) FROM draws WHERE status = 'PENDING_PARTNER_APPROVAL'")->fetchColumn();

$today = date('Y-m-d');
$today_stmt = $pdo->prepare("SELECT * FROM draws WHERE draw_date = ?");
$today_stmt->execute([$today]);
$today_draw = $today_stmt->fetch();

// Fetch Top 10 Recent Draws including winning numbers
$stmt = $pdo->query("SELECT d.*, u.full_name as officer FROM draws d JOIN users u ON d.primary_verifier_id = u.id ORDER BY d.created_at DESC LIMIT 10");
$recent_draws = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intego Draw Verification | LDC System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Intego Lotto Inspired Flat Design (No Gradients) */
        .intego-hero {
            background-color: var(--charcoal);
            color: #ffffff;
            padding: 24px;
            border-radius: 4px;
            margin-bottom: 24px;
            border-left: 6px solid var(--red);
            border: 1px solid var(--pale-charcoal);
        }
        .intego-hero h2 { font-size: 1.5rem; margin-bottom: 6px; color: #ffffff; }
        .intego-hero p { color: var(--pale-charcoal); font-size: 0.9rem; }
        
        /* Solid Flat Lottery Ball Badges */
        .lottery-balls-container {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .lottery-ball {
            width: 30px;
            height: 30px;
            background-color: var(--red);
            color: #ffffff;
            border-radius: 50%;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            font-weight: 700;
            font-size: 0.8rem;
            border: 2px solid #ffffff;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid var(--pale-charcoal);
            border-top: 4px solid var(--red);
            padding: 20px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stat-card.blue { border-top-color: var(--ocean); }
        .stat-card.green { border-top-color: var(--jade); }
        .stat-card i { font-size: 2rem; color: var(--slate); }
        .stat-info h3 { font-size: 1.5rem; color: var(--charcoal); margin-bottom: 2px; }
        .stat-info p { font-size: 0.8rem; color: var(--slate); text-transform: uppercase; font-weight: 600; }

        /* Solid Flat CTA Button (No Gradients) */
        .flat-cta {
            background-color: var(--red);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            font-size: 0.95rem;
            border: none;
        }
        .flat-cta:hover {
            background-color: var(--burgundy);
        }
    </style>
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
            
            document.getElementById('live-clock').innerText = `${day}/${month}/${year} ${hours}:${minutes}:${seconds}`;
        }
        setInterval(updateLiveClock, 1000);
    </script>
</head>
<body>

        <!-- Main Content -->
        <main class="main-content">

        <!-- print BASE URL -->
        <!-- <p>Base URL: <?= BASE_URL ?></p> -->

            <!-- Intego-Inspired Flat Hero Banner -->
            <div class="intego-hero">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h2><i class="fa-solid fa-ticket" style="color: var(--gold);"></i> Intego Lotto 5/90 & Daily Draws Certification</h2>
                        <p>Welcome, <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong></p>
                    </div>
                    <!-- Only for Verifiers -->
                    <?php if (isset($_SESSION['is_verifier']) && $_SESSION['is_verifier'] === true): ?>
                        <a href="javascript:void(0);" class="flat-cta" onclick="openAddDrawModal()">
                            <i class="fa-solid fa-circle-plus"></i> Initiate Draw Verification
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Summary Statistics Area -->
            <div class="stats-grid">
                <div class="stat-card green">
                    <i class="fa-solid fa-certificate"></i>
                    <div class="stat-info">
                        <h3><?= $total_certified ?></h3>
                        <p>Total Certified Draws</p>
                    </div>
                </div>
                <div class="stat-card blue">
                    <i class="fa-solid fa-hourglass-half"></i>
                    <div class="stat-info">
                        <h3><?= $total_pending ?></h3>
                        <p>Pending Partner Approvals</p>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fa-solid fa-calendar-day"></i>
                    <div class="stat-info">
                        <h3 style="font-size: 1.1rem; margin-top: 4px;">
                            <?php if ($today_draw): ?>
                                <span style="color: var(--jade);"><i class="fa-solid fa-circle-check"></i> Recorded</span>
                            <?php else: ?>
                                <span style="color: var(--gold);"><i class="fa-solid fa-circle-exclamation"></i> Pending Today</span>
                            <?php endif; ?>
                        </h3>
                        <p>Today's Status (<?= date('d M') ?>)</p>
                    </div>
                </div>
            </div>

            <?php if (!$today_draw && $_SESSION['role_code'] === 'PRIMARY_VERIFIER'): ?>
                <div class="alert alert-warning" style="display: flex; align-items: center; justify-content: space-between;">
                    <span><i class="fa-solid fa-triangle-exclamation"></i> Notice: No draw verification record has been submitted for today yet.</span>
                    <!-- <a href="openAddDrawModal()" style="color: #fff; text-decoration: underline; font-weight: bold;">Start Verification &rarr;</a> -->
                    <button onclick="openAddDrawModal()" style="background: var(--red); color: #fff; border: none; padding: 8px 14px; border-radius: 4px; font-weight: bold; cursor: pointer;">
                        <i class="fa-solid fa-circle-plus"></i> Initiate Today's Draw
                    </button>
                </div>
            <?php endif; ?>

            <!-- Recent Draws Table with Solid Flat Lottery Balls -->
            <div class="table-container" style="margin-top: 20px;">
                <div style="padding: 15px; background: var(--charcoal); color: #fff; font-weight: bold; display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid var(--red);">
                    <span><i class="fa-solid fa-list-ol"></i> Recent Draw Winning Numbers & Certifications</span>
                    <span style="font-size: 0.8rem; font-weight: normal;">Latest 10 Verified Sessions</span>
                </div>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Session & Date</th>
                            <th>Winning Numbers (Lotto 5/90)</th>
                            <th>Officer</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                            <?php
                            $role_code = strtoupper((string)($_SESSION['role_code'] ?? ($_SESSION['user_role'] ?? '')));

                            $visible_draws = array_values(array_filter($recent_draws ?? [], function ($draw) use ($role_code) {
                                $status = strtoupper((string)($draw['status'] ?? ''));
                                $has_partner_sig = !empty($draw['partner_signed_at']) || !empty($draw['partner_signature']) || !empty($draw['signed_at']);

                                if ($role_code === 'PARTNER') {
                                    return $status === 'PENDING_PARTNER_APPROVAL';
                                }

                                if ($role_code === 'STAKEHOLDER') {
                                    return ($status === 'CERTIFIED') || $has_partner_sig;
                                }

                                return true;
                            }));
                            ?>

                            <?php if (empty($visible_draws)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 20px; color: var(--slate);">
                                        <?php if ($role_code === 'PARTNER'): ?>
                                            No draw records pending partner review.
                                        <?php elseif ($role_code === 'STAKEHOLDER'): ?>
                                            No signed draw records available yet.
                                        <?php else: ?>
                                            No draw records found in the system yet.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($visible_draws as $d): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($d['certificate_ref']) ?></strong></td>
                                        <td>
                                            <?= htmlspecialchars($d['draw_session']) ?><br>
                                            <span style="font-size: 0.75rem; color: var(--slate);"><?= date('d/m/Y', strtotime($d['draw_date'])) ?> <?= htmlspecialchars($d['start_time']) ?></span>
                                        </td>
                                        <td>
                                            <div class="lottery-balls-container">
                                                <div class="lottery-ball"><?= (int)$d['num1_a'] ?></div>
                                                <div class="lottery-ball"><?= (int)$d['num2_a'] ?></div>
                                                <div class="lottery-ball"><?= (int)$d['num3_a'] ?></div>
                                                <div class="lottery-ball"><?= (int)$d['num4_a'] ?></div>
                                                <div class="lottery-ball"><?= (int)$d['num5_a'] ?></div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($d['officer']) ?></td>
                                        <td>
                                            <?php if ($d['status'] === 'CERTIFIED'): ?>
                                                <span style="color: var(--jade); font-weight: bold;"><i class="fa-solid fa-circle-check"></i> Certified</span>
                                            <?php else: ?>
                                                <span style="color: var(--gold); font-weight: bold;"><i class="fa-solid fa-clock-rotate-left"></i> <?= htmlspecialchars(str_replace('_', ' ', $d['status'])) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?= BASE_URL ?>Home/Draws/Review/?id=<?= urlencode((string)$d['draw_hash_code']) ?>" style="color: var(--ocean); text-decoration: none; font-weight: 600;">
                                                <i class="fa-solid fa-eye"></i> Review
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
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
   <?php include_once __DIR__ . '/../assets/includes/footer.php'; ?>
</body>
</html>