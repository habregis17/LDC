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
                    <?php if ($_SESSION['role_code'] === 'PRIMARY_VERIFIER' && !$today_draw): ?>
                        <a href="<?= BASE_URL ?>Home/Draws/Initiate" class="flat-cta">
                            <i class="fa-solid fa-circle-plus"></i> Initiate Today's Draw
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
                    <a href="<?= BASE_URL ?>Home/Draws/Initiate" style="color: #fff; text-decoration: underline; font-weight: bold;">Start Verification &rarr;</a>
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
                        <?php if (empty($recent_draws)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 20px; color: var(--slate);">No draw records found in the system yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_draws as $d): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($d['certificate_ref']) ?></strong></td>
                                    <td>
                                        <?= htmlspecialchars($d['draw_session']) ?><br>
                                        <span style="font-size: 0.75rem; color: var(--slate);"><?= date('d/m/Y', strtotime($d['draw_date'])) ?> <?= $d['start_time'] ?></span>
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
                                            <span style="color: var(--gold); font-weight: bold;"><i class="fa-solid fa-clock-rotate-left"></i> <?= str_replace('_', ' ', $d['status']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?= BASE_URL ?>Home/Draws/Review/?id=<?= $d['draw_hash_code'] ?>" style="color: var(--ocean); text-decoration: none; font-weight: 600;">
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
   <?php include_once __DIR__ . '/../assets/includes/footer.php'; ?>
</body>
</html>