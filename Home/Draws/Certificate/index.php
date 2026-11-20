<?php
require_once __DIR__ . '/../../../assets/config/config.php';
check_auth(['PARTNER', 'PRIMARY_VERIFIER', 'ADMIN']);

$id_param = trim($_GET['id'] ?? '');
$certified_notice = isset($_GET['certified']);

// Adjust path to your BDO logo asset if stored elsewhere in /assets/
$company_logo_url = BASE_URL . 'assets/images/bdo-logo.png';

$stmt = $pdo->prepare("
    SELECT d.*, 
           u.full_name AS officer_name, 
           u.email AS officer_email,
           p.full_name AS partner_name,
           p.email AS partner_email
    FROM draws d
    JOIN users u ON d.primary_verifier_id = u.id
    LEFT JOIN users p ON d.partner_id = p.id
    WHERE d.draw_hash_code = :hash OR (d.id = :num_id AND :is_num = 1)
    LIMIT 1
");
$stmt->execute([
    ':hash'   => $id_param,
    ':num_id' => ctype_digit($id_param) ? (int)$id_param : 0,
    ':is_num' => ctype_digit($id_param) ? 1 : 0,
]);
$draw = $stmt->fetch();

if (!$draw) {
    die("Error: Certificate record not found.");
}

if ($draw['status'] !== 'CERTIFIED') {
    die("Notice: This draw has not been certified yet. Current status: " . htmlspecialchars($draw['status']));
}

// Compute distance from RBA Studio (-1.93843, 30.08274)
function cert_rba_distance($lat, $lng) {
    if (!is_numeric($lat) || !is_numeric($lng) || ((float)$lat === 0.0 && (float)$lng === 0.0)) {
        return 'Recorded at Venue';
    }
    $rba_lat = -1.93843;
    $rba_lng = 30.08274;
    $dLat = deg2rad($rba_lat - (float)$lat);
    $dLng = deg2rad($rba_lng - (float)$lng);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad((float)$lat)) * cos(deg2rad($rba_lat)) * sin($dLng / 2) ** 2;
    $meters = 6371000.0 * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    return $meters < 1000 ? round($meters) . ' m from RBA Studio' : number_format($meters / 1000, 2) . ' km from RBA Studio';
}

// Build public QR verification URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$verify_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . BASE_URL . "verify.php?ref=" . urlencode($draw['certificate_ref']) . "&hash=" . urlencode($draw['draw_hash_code']);

$exc_status = $draw['exception_status'] ?? 'NONE';
$exc_ref    = trim($draw['exception_report_ref'] ?? '');
$exc_desc   = trim($draw['exception_details'] ?? '');

$page_title = "Certificate " . htmlspecialchars($draw['certificate_ref']) . " | BDO LDC";
include_once __DIR__ . '/../../../assets/includes/header.php';
include_once __DIR__ . '/../../../assets/includes/sidebar.php';
?>

<style>
    .cert-toolbar { max-width: 794px; margin: 0 auto 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    .certificate-wrapper { background: #fff; max-width: 794px; margin: 0 auto 30px; padding: 10px; border: 1px solid #d9dce3; box-shadow: 0 14px 36px rgba(20,24,34,.14); }

    /* ---------- The certificate itself ---------- */
    .certificate-frame {
        --ink: #1f2430; --muted: #5b6270; --red: #E81A3B; --wine: #7c1928; --green: #165b33; --line: #e4e6ec; --paper: #fffdf9;
        font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        color: var(--ink);
        background: var(--paper);
        border: 1.5px solid var(--ink);
        outline: 1px solid var(--red);
        outline-offset: -7px;
        padding: 26px 34px 20px;
        position: relative;
        display: flex;
        flex-direction: column;
        gap: 12px;
        min-height: 1040px;
    }
    .serif { font-family: Georgia, 'Times New Roman', serif; }

    /* header */
    .cert-top { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
    .cert-logo-block img { max-height: 44px; width: auto; display: block; margin-bottom: 4px; }
    .cert-logo-fallback { font-size: 1.7rem; font-weight: 900; color: var(--red); letter-spacing: 1.5px; line-height: 1; }
    .cert-org { font-size: .8rem; font-weight: 700; }
    .cert-org-sub { font-size: .7rem; color: var(--muted); }
    .cert-ref { text-align: right; }
    .cert-ref .lbl { font-size: .62rem; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); font-weight: 700; }
    .cert-ref .num { font-size: 1.05rem; font-weight: 800; color: var(--wine); letter-spacing: .4px; }
    .cert-badge { display: inline-block; margin-top: 3px; background: var(--green); color: #fff; font-size: .62rem; font-weight: 800; padding: 2px 9px; border-radius: 20px; letter-spacing: .8px; }

    /* title */
    .cert-title { text-align: center; padding: 6px 0 0; }
    .cert-title .eyebrow { font-size: .68rem; font-weight: 800; letter-spacing: 2.4px; text-transform: uppercase; color: var(--red); }
    .cert-title h1 { font-family: Georgia, 'Times New Roman', serif; font-size: 1.9rem; font-weight: 700; margin: 5px 0 6px; letter-spacing: .2px; }
    .cert-divider { display: flex; align-items: center; justify-content: center; gap: 10px; color: var(--red); margin: 0 auto 10px; max-width: 320px; }
    .cert-divider::before, .cert-divider::after { content: ""; flex: 1; height: 1px; background: linear-gradient(90deg, transparent, var(--red)); }
    .cert-divider::after { background: linear-gradient(270deg, transparent, var(--red)); }
    .cert-statement { font-family: Georgia, serif; font-size: .93rem; line-height: 1.6; color: #333a48; text-align: center; max-width: 620px; margin: 0 auto; }
    .cert-statement strong { color: var(--ink); }
    .cert-statement .draw-name { color: var(--wine); font-weight: 700; }

    /* winning numbers */
    .winning-numbers-panel { background: linear-gradient(135deg, #1f2430 0%, #2b3142 100%); color: #fff; border-radius: 8px; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; gap: 14px; border-left: 6px solid var(--red); }
    .winning-numbers-panel .t1 { font-size: .68rem; letter-spacing: 1.4px; text-transform: uppercase; font-weight: 700; color: #d6d9e0; }
    .winning-numbers-panel .t2 { font-size: .74rem; color: #a5abb8; margin-top: 3px; max-width: 220px; line-height: 1.35; }
    .cert-balls-row { display: flex; gap: 10px; }
    .cert-ball { width: 46px; height: 46px; border-radius: 50%; background: radial-gradient(circle at 35% 30%, #ff6a80 0%, #E81A3B 60%, #8b0d21 100%); color: #fff; font-weight: 800; font-size: 1.12rem; display: inline-flex; align-items: center; justify-content: center; border: 2px solid #fff; box-shadow: 0 3px 8px rgba(0,0,0,.35); }

    /* section cards */
    .cert-heading { font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.3px; color: var(--wine); display: flex; align-items: center; gap: 8px; margin: 0 0 7px; }
    .cert-heading::after { content: ""; flex: 1; height: 1px; background: var(--line); }
    .facts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 22px; font-size: .8rem; }
    .fact .k { font-size: .64rem; text-transform: uppercase; letter-spacing: .8px; color: var(--muted); font-weight: 700; }
    .fact .v { font-weight: 600; margin-top: 1px; line-height: 1.35; }
    .fact.wide { grid-column: 1 / -1; }
    .fact .hash { font-family: Consolas, monospace; font-size: .72rem; font-weight: 400; color: #374151; word-break: break-all; }
    .fact small { color: var(--muted); font-weight: 400; }

    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
    .check-list { display: flex; flex-direction: column; gap: 5px; font-size: .79rem; }
    .check-item { display: flex; align-items: flex-start; gap: 8px; line-height: 1.35; }
    .tick { flex: 0 0 16px; height: 16px; border-radius: 4px; border: 1.5px solid #9aa1ae; display: inline-flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 900; color: #fff; margin-top: 1px; }
    .tick.on { background: var(--green); border-color: var(--green); }
    .tick.flag { background: #b45309; border-color: #b45309; }
    .note-box { margin-top: 7px; font-size: .74rem; color: #374151; background: #f7f8fa; padding: 6px 10px; border-left: 3px solid #9ca3af; border-radius: 0 4px 4px 0; line-height: 1.4; }

    /* sign-off */
    .signoff-and-qr { display: grid; grid-template-columns: 1.7fr 1fr; gap: 20px; padding-top: 12px; border-top: 1.5px solid var(--ink); align-items: stretch; }
    .partner-sign-box { background: #f6faf7; border: 1px solid #cfe5d8; border-radius: 6px; padding: 12px 16px; display: grid; grid-template-columns: 1fr 96px; gap: 12px; align-items: center; }
    .sign-title { font-size: .66rem; font-weight: 800; letter-spacing: 1.1px; text-transform: uppercase; color: var(--green); }
    .sign-name { font-family: Georgia, serif; font-size: 1.15rem; font-style: italic; margin-top: 5px; padding-bottom: 3px; border-bottom: 1px solid #9ca3af; min-height: 26px; }
    .sign-meta { font-size: .72rem; color: var(--muted); margin-top: 4px; line-height: 1.45; }
    .sign-meta strong { color: var(--ink); }
    .seal { width: 90px; height: 90px; border-radius: 50%; border: 2px solid var(--green); outline: 1px solid var(--green); outline-offset: 3px; color: var(--green); display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; transform: rotate(-8deg); margin: 0 auto; }
    .seal .s1 { font-size: .62rem; font-weight: 800; letter-spacing: 1.5px; }
    .seal .s2 { font-size: .95rem; font-weight: 900; letter-spacing: 1px; line-height: 1.1; }
    .seal .s3 { font-size: .5rem; font-weight: 700; letter-spacing: .6px; margin-top: 2px; }
    .qr-verify-box { border: 1px solid var(--line); background: #fff; border-radius: 6px; padding: 10px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    #cert-qr-code img, #cert-qr-code canvas { margin: 0 auto; display: block; }
    .qr-verify-box .q1 { font-size: .66rem; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; margin-top: 6px; }
    .qr-verify-box .q2 { font-size: .64rem; color: var(--muted); margin-top: 2px; line-height: 1.35; }

    .cert-disclaimer { margin-top: auto; padding-top: 8px; border-top: 1px solid var(--line); font-size: .62rem; line-height: 1.45; color: #6b7280; text-align: justify; }

    @media (max-width: 780px) {
        .certificate-frame { padding: 20px 16px; min-height: 0; }
        .two-col, .facts, .signoff-and-qr, .partner-sign-box { grid-template-columns: 1fr; }
        .winning-numbers-panel { flex-direction: column; text-align: center; }
        .cert-balls-row { flex-wrap: wrap; justify-content: center; }
    }

    /* ---------- Print: exactly one A4 page ---------- */
    @media print {
        @page { size: A4 portrait; margin: 8mm; }
        html, body { height: auto !important; background: #fff !important; }
        .app-header, .sidebar, .app-footer, .no-print { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
        .certificate-wrapper { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; max-width: 100% !important; }
        .certificate-frame { height: 280mm; min-height: 0; overflow: hidden; page-break-inside: avoid; break-inside: avoid; padding: 9mm 11mm 7mm; gap: 3.2mm; }
        .certificate-frame, .certificate-frame * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .cert-title h1 { font-size: 1.75rem; }
        .certificate-wrapper, .certificate-frame { page-break-after: avoid; }
    }
</style>

<main class="main-content">
    <?php if ($certified_notice): ?>
        <div class="alert alert-success no-print" style="max-width: 794px; margin: 0 auto 14px;">
            <i class="fa-solid fa-circle-check"></i> Draw <strong><?= htmlspecialchars($draw['certificate_ref']) ?></strong> has been officially certified, logged in the audit trail, and dispatched via email.
        </div>
    <?php endif; ?>

    <div class="cert-toolbar no-print">
        <a href="<?= BASE_URL ?>Home/Draws/History/" style="color: var(--charcoal); text-decoration: none; font-weight: 700; font-size: 0.88rem;">
            <i class="fa-solid fa-arrow-left"></i> Back to Draws History
        </a>
        <div style="display: flex; gap: 10px;">
            <a href="<?= htmlspecialchars($verify_url) ?>" target="_blank" style="background: #ffffff; color: var(--charcoal); border: 1px solid var(--pale-charcoal); padding: 9px 14px; border-radius: 4px; text-decoration: none; font-weight: 700; font-size: 0.84rem;">
                <i class="fa-solid fa-qrcode"></i> Test QR Verification Link
            </a>
            <button type="button" onclick="window.print()" style="background: var(--red); color: #ffffff; border: none; padding: 10px 18px; border-radius: 4px; font-weight: 700; font-size: 0.85rem; cursor: pointer;">
                <i class="fa-solid fa-print"></i> Print / Export Official PDF
            </button>
        </div>
    </div>

    <?php
        $draw_date_txt = date('l, d F Y', strtotime($draw['draw_date']));
        $start_txt = !empty($draw['start_time']) ? date('H:i:s', strtotime($draw['start_time'])) : 'N/A';
        $end_txt   = !empty($draw['end_time'])   ? date('H:i:s', strtotime($draw['end_time']))   : 'N/A';
    ?>

    <div class="certificate-wrapper">
        <div class="certificate-frame">

            <!-- Header -->
            <div class="cert-top">
                <div class="cert-logo-block">
                    <img src="<?= htmlspecialchars($company_logo_url) ?>"
                         alt="BDO East Africa (Rwanda) Ltd"
                         onerror="this.style.display='none'; document.getElementById('bdo-text-logo').style.display='block';">
                    <div id="bdo-text-logo" class="cert-logo-fallback" style="display: none;">BDO</div>
                    <div class="cert-org">BDO East Africa (Rwanda) Ltd</div>
                    <div class="cert-org-sub">Risk Advisory &amp; Independent Assurance Services &bull; Kigali, Rwanda</div>
                </div>
                <div class="cert-ref">
                    <div class="lbl">Certificate Reference</div>
                    <div class="num"><?= htmlspecialchars($draw['certificate_ref']) ?></div>
                    <span class="cert-badge">&#10003; OFFICIALLY CERTIFIED</span>
                </div>
            </div>

            <!-- Title + human statement -->
            <div class="cert-title">
                <div class="eyebrow">Independent Draw Observation &amp; Certification</div>
                <h1>Certificate of Lottery Draw Verification</h1>
                <div class="cert-divider">&#9670;</div>
                <p class="cert-statement">
                    This is to certify that on <strong><?= htmlspecialchars($draw_date_txt) ?></strong>,
                    an independent observer from <strong>BDO East Africa (Rwanda) Ltd</strong> watched the
                    <span class="draw-name"><?= htmlspecialchars($draw['draw_session']) ?></span> draw take place at
                    <?= htmlspecialchars($draw['venue_location']) ?>.
                    The winning numbers below were recorded by two people independently and checked against the central system.
                </p>
            </div>

            <!-- Winning numbers -->
            <div class="winning-numbers-panel">
                <div>
                    <div class="t1">Certified Winning Numbers</div>
                    <div class="t2">Dual-control verified &amp; reconciled against central system records</div>
                </div>
                <div class="cert-balls-row">
                    <div class="cert-ball"><?= (int)$draw['num1_a'] ?></div>
                    <div class="cert-ball"><?= (int)$draw['num2_a'] ?></div>
                    <div class="cert-ball"><?= (int)$draw['num3_a'] ?></div>
                    <div class="cert-ball"><?= (int)$draw['num4_a'] ?></div>
                    <div class="cert-ball"><?= (int)$draw['num5_a'] ?></div>
                </div>
            </div>

            <!-- Draw particulars -->
            <section>
                <div class="cert-heading">Draw Details</div>
                <div class="facts">
                    <div class="fact"><div class="k">Name of Draw</div><div class="v"><?= htmlspecialchars($draw['draw_session']) ?></div></div>
                    <div class="fact"><div class="k">Draw Date</div><div class="v"><?= htmlspecialchars($draw_date_txt) ?></div></div>
                    <div class="fact"><div class="k">Observation Window</div><div class="v"><?= htmlspecialchars($start_txt) ?> &rarr; <?= htmlspecialchars($end_txt) ?></div></div>
                    <div class="fact"><div class="k">Observing Officer</div><div class="v"><?= htmlspecialchars($draw['officer_name'] ?? '') ?></div></div>
                    <div class="fact wide">
                        <div class="k">Venue &amp; Geolocation</div>
                        <div class="v"><?= htmlspecialchars($draw['venue_location']) ?>
                            <small>(<?= htmlspecialchars(cert_rba_distance($draw['gps_latitude'] ?? null, $draw['gps_longitude'] ?? null)) ?>)</small>
                        </div>
                    </div>
                    <div class="fact wide"><div class="k">Record Integrity Hash</div><div class="v hash"><?= htmlspecialchars($draw['draw_hash_code']) ?></div></div>
                </div>
            </section>

            <!-- Procedures + Exceptions side by side -->
            <div class="two-col">
                <section>
                    <div class="cert-heading">What We Checked</div>
                    <div class="check-list">
                        <div class="check-item"><span class="tick <?= !empty($draw['chk_pre_draw']) ? 'on' : '' ?>"><?= !empty($draw['chk_pre_draw']) ? '&#10003;' : '' ?></span> Pre-draw machinery &amp; equipment check</div>
                        <div class="check-item"><span class="tick <?= !empty($draw['chk_draw_obs']) ? 'on' : '' ?>"><?= !empty($draw['chk_draw_obs']) ? '&#10003;' : '' ?></span> Live draw observed against the SOP</div>
                        <div class="check-item"><span class="tick <?= !empty($draw['chk_post_draw']) ? 'on' : '' ?>"><?= !empty($draw['chk_post_draw']) ? '&#10003;' : '' ?></span> Post-draw reconciliation &amp; audit</div>
                    </div>
                    <?php if (!empty(trim($draw['procedure_notes'] ?? ''))): ?>
                        <div class="note-box"><strong>Observation notes:</strong> <?= nl2br(htmlspecialchars($draw['procedure_notes'])) ?></div>
                    <?php endif; ?>
                </section>

                <section>
                    <div class="cert-heading">Exceptions Identified</div>
                    <div class="check-list">
                        <div class="check-item"><span class="tick <?= $exc_status === 'NONE' ? 'on' : '' ?>"><?= $exc_status === 'NONE' ? '&#10003;' : '' ?></span> <span><strong>None.</strong></span></div>
                        <div class="check-item"><span class="tick <?= $exc_status === 'MINOR_RESOLVED' ? 'flag' : '' ?>"><?= $exc_status === 'MINOR_RESOLVED' ? '&#10003;' : '' ?></span> <span>Minor issue(s) noted and resolved during the session.</span></div>
                        <div class="check-item"><span class="tick <?= $exc_status === 'SIGNIFICANT_EXCEPTION' ? 'flag' : '' ?>"><?= $exc_status === 'SIGNIFICANT_EXCEPTION' ? '&#10003;' : '' ?></span>
                            <span>Significant exception(s) &mdash; see separate Exception Report (Ref: <strong><?= htmlspecialchars($exc_ref !== '' ? $exc_ref : 'N/A') ?></strong>).</span></div>
                    </div>
                    <?php if ($exc_desc !== ''): ?>
                        <div class="note-box"><strong>Details:</strong> <?= nl2br(htmlspecialchars($exc_desc)) ?></div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- Sign-off + QR -->
            <div class="signoff-and-qr">
                <div class="partner-sign-box">
                    <div>
                        <div class="sign-title">Authorised Partner Sign-Off</div>
                        <div class="sign-name"><?= htmlspecialchars($draw['partner_name'] ?? '') ?></div>
                        <div class="sign-meta">
                            <strong>Partner, Risk Advisory Services</strong> &mdash; BDO East Africa (Rwanda) Ltd<br>
                            Signed: <?= !empty($draw['partner_signed_at']) ? htmlspecialchars(date('d M Y \a\t H:i:s', strtotime($draw['partner_signed_at']))) . ' CAT' : '&mdash;' ?><br>
                            Digitally approved via MFA &bull; Ref <?= htmlspecialchars($draw['certificate_ref']) ?>
                        </div>
                    </div>
                    <div class="seal">
                        <span class="s1">BDO</span>
                        <span class="s2">CERTIFIED</span>
                        <span class="s3">MFA APPROVED</span>
                    </div>
                </div>

                <div class="qr-verify-box">
                    <div id="cert-qr-code"></div>
                    <div class="q1">Scan to verify</div>
                    <div class="q2">Checks live against the<br>BDO LDC Registry</div>
                </div>
            </div>

            <!-- Disclaimer -->
            <div class="cert-disclaimer">
                <strong>Scope of assurance:</strong> This certificate is issued by <strong>BDO East Africa (Rwanda) Ltd</strong> solely to confirm that independent draw observation and control verification procedures were performed for the specific lottery draw session identified above. Our engagement is limited to observing the draw execution, verifying dual-control number recording, and confirming adherence to established Standard Operating Procedures (SOPs) during the stated session window. This certificate does not constitute a financial audit, legal opinion, or guarantee of underlying gaming software source code, ticket sales accounting, or prize payout administration outside the observed draw session. Any alteration, tampering, or reproduction without a matching verification record in the BDO LDC Registry renders this document invalid.
            </div>
        </div>
    </div>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const qrContainer = document.getElementById('cert-qr-code');
        const verifyUrl = <?= json_encode($verify_url) ?>;
        if (qrContainer && typeof QRCode !== 'undefined') {
            new QRCode(qrContainer, {
                text: verifyUrl,
                width: 92,
                height: 92,
                colorDark: "#1f2430",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
        } else if (qrContainer) {
            qrContainer.innerHTML = '<div style="font-size:0.6rem; word-break:break-all; color:#4b5563;">' + verifyUrl + '</div>';
        }
    });
</script>

<?php include_once __DIR__ . '/../../../assets/includes/footer.php'; ?>