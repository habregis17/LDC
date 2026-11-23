<?php
if (!defined('BASE_URL')) {
    exit;
}

// Ensure consistent CAT (GMT+2 / Africa/Kigali) display if timezone isn't already set
$display_tz = new DateTimeZone('Africa/Kigali');
$now_dt = new DateTime('now', $display_tz);
$initial_dt_label = $now_dt->format('D, d M Y') . ' · ' . $now_dt->format('H:i:s');
$logo_url = BASE_URL . 'assets/images/bdo-logo.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#E81A3B">
    <title><?= htmlspecialchars((string)($page_title ?? 'LDC - Lottery Draw Certification System')) ?></title>

    <!-- Website Icon / Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($logo_url) ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= htmlspecialchars($logo_url) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($logo_url) ?>">

    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">

    <style>
        .app-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 10px 18px;
        }
        .app-header .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1 1 240px;
        }
        .app-header .brand-logo-img {
            width: 34px;
            height: 34px;
            object-fit: contain;
            border-radius: 6px;
            flex-shrink: 0;
            background: #ffffff;
            padding: 2px;
        }
        .app-header .brand-text {
            min-width: 0;
        }
        .app-header .brand-text h1 {
            margin: 0;
            font-size: 1.05rem;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .app-header .brand-text span {
            display: block;
            font-size: 0.76rem;
            opacity: 0.85;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .app-header .header-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .clock-display {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--pale-charcoal);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.84rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .tz-badge {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--slate);
            background: rgba(255, 255, 255, 0.75);
            padding: 2px 6px;
            border-radius: 4px;
        }
        .logout-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--red);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.86rem;
            padding: 6px 10px;
            border-radius: 6px;
            white-space: nowrap;
        }
        .logout-link:hover {
            background: rgba(232, 26, 59, 0.08);
        }

        @media (max-width: 640px) {
            .app-header {
                padding: 10px 12px;
                gap: 8px;
            }
            .app-header .brand {
                flex: 1 1 100%;
            }
            .app-header .brand-text h1 {
                font-size: 0.95rem;
                white-space: normal;
            }
            .app-header .brand-text span {
                font-size: 0.72rem;
                white-space: normal;
            }
            .app-header .header-right {
                width: 100%;
                justify-content: space-between;
                gap: 8px;
            }
            .clock-display {
                font-size: 0.76rem;
                padding: 5px 9px;
            }
            .logout-link {
                font-size: 0.8rem;
                padding: 5px 8px;
            }
        }
    </style>

    <script>
        // Dynamic Live Ticking Clock (Africa/Kigali · GMT+2)
        function updateLiveClock() {
            const clockEl = document.getElementById('live-clock');
            if (!clockEl) return;

            const now = new Date();
            const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            const weekday = weekdays[now.getDay()];
            const day = String(now.getDate()).padStart(2, '0');
            const month = months[now.getMonth()];
            const year = now.getFullYear();

            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');

            clockEl.textContent = `${weekday}, ${day} ${month} ${year} · ${hours}:${minutes}:${seconds}`;
        }

        document.addEventListener('DOMContentLoaded', updateLiveClock);
        setInterval(updateLiveClock, 1000);
    </script>
</head>
<body>

    <!-- Header Component -->
    <header class="app-header">
        <div class="brand">
            <img src="<?= htmlspecialchars($logo_url) ?>"
                 alt="LDC Logo"
                 class="brand-logo-img"
                 onerror="this.style.display='none'; document.getElementById('brand-fallback-icon').style.display='inline-flex';">
            <span id="brand-fallback-icon" style="display:none; align-items:center; justify-content:center; width:34px; height:34px;">
                <i class="fa-solid fa-award fa-xl" style="color: var(--red);"></i>
            </span>
            <div class="brand-text">
                <h1>LDC - Intego Lotto Draw Certification</h1>
                <span>Powered by BDO East Africa (Rwanda) Ltd</span>
            </div>
        </div>

        <div class="header-right">
            <div class="clock-display">
                <i class="fa-regular fa-clock" style="color: var(--red);"></i>
                <span id="live-clock"><?= htmlspecialchars($initial_dt_label) ?></span>
                <span class="tz-badge">GMT+2</span>
            </div>
            <a href="<?= BASE_URL ?>logout/" class="logout-link">
                <i class="fa-solid fa-power-off"></i>
                <span>Logout</span>
            </a>
        </div>
    </header>

    <div class="app-layout">