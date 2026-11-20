<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Common\Version;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QRGdImagePNG;

/**
 * Compute distance in meters/km from RBA Studio (-1.93843, 30.08274)
 */
function cert_pdf_rba_distance($lat, $lng): string
{
    if (!is_numeric($lat) || !is_numeric($lng) || ((float)$lat === 0.0 && (float)$lng === 0.0)) {
        return 'Recorded at Venue';
    }
    $rba_lat = -1.93843;
    $rba_lng = 30.08274;
    $dLat = deg2rad($rba_lat - (float)$lat);
    $dLng = deg2rad($rba_lng - (float)$lng);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad((float)$lat)) * cos(deg2rad($rba_lat)) * sin($dLng / 2) ** 2;
    $meters = 6371000.0 * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    return $meters < 1000
        ? round($meters) . ' m from RBA Studio'
        : number_format($meters / 1000, 2) . ' km from RBA Studio';
}

/**
 * Fetch a certified draw record by draw_hash_code or numeric ID.
 */
function fetch_certified_draw(PDO $pdo, string $id_param): ?array
{
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
    $draw = $stmt->fetch(PDO::FETCH_ASSOC);
    return $draw ?: null;
}

/**
 * Render the single-page A4 Portrait Certificate HTML tailored specifically for Dompdf.
 */
function build_draw_certificate_html(array $draw, string $verify_url): string
{
    // Embed BDO logo as Base64 data URI so Dompdf never hits path/permission issues
    $logo_disk_path = __DIR__ . '/../images/bdo-logo.jpg';

    if (is_file($logo_disk_path) && is_readable($logo_disk_path)) {
        $mime = mime_content_type($logo_disk_path) ?: 'image/jpeg';
        $b64  = base64_encode((string)file_get_contents($logo_disk_path));
        $logo_html = '<img src="data:' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') . ';base64,' . $b64 . '" alt="BDO" style="height: 100px; width: auto; max-width: 190pt; display: inline-block; margin-bottom: 4pt;">';
    } else {
        $logo_html = '
        <table style="border-collapse: collapse; margin: 0 auto 4pt auto;">
            <tr>
                <td style="background-color: #E81A3B; color: #ffffff; padding: 4pt 10pt 3pt 10pt; border-radius: 3pt; vertical-align: middle;">
                    <span style="font-family: \'DejaVu Sans\', Helvetica, Arial, sans-serif; font-size: 18pt; font-weight: bold; letter-spacing: 2pt; line-height: 1;">BDO</span>
                </td>
            </tr>
        </table>';
    }

    // Embed Partner's signature
    $signature_path = __DIR__ . '/../images/signature.jpg';

    if (is_file($signature_path) && is_readable($signature_path)) {
        $mime = mime_content_type($signature_path) ?: 'image/jpeg';
        $b64  = base64_encode((string)file_get_contents($signature_path));
        $signature_html = '<img src="data:' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') . ';base64,' . $b64 . '" alt="Signature" style="height: 50px; width: auto; max-width: 190pt; display: block; margin-top: 4pt;">';
    } else {
        $signature_html = '
        <table style="border-collapse: collapse; margin-top: 4pt;">
            <tr>
                <td style="color: #333333; padding: 2pt 6pt; vertical-align: middle;">
                    <span style="font-family: \'DejaVu Serif\', Georgia, serif; font-style: italic; font-size: 14pt; font-weight: bold; letter-spacing: 1pt; line-height: 1;">C.N.K.E</span>
                </td>
            </tr>
        </table>';
    }

    // Generate crisp inline Base64 QR Code PNG (v5+/v6 compatible)
    $qr_options = new QROptions([
        'version'         => Version::AUTO,
        'outputInterface' => QRGdImagePNG::class,
        'eccLevel'        => EccLevel::M,
        'scale'           => 5,
        'outputBase64'    => true,
        'bgColor'         => [255, 255, 255],
        'moduleValues'    => [
            QRMatrix::M_FINDER_DARK    => [31, 36, 48],
            QRMatrix::M_FINDER_DOT     => [31, 36, 48],
            QRMatrix::M_ALIGNMENT_DARK => [31, 36, 48],
            QRMatrix::M_TIMING_DARK    => [31, 36, 48],
            QRMatrix::M_FORMAT_DARK    => [31, 36, 48],
            QRMatrix::M_VERSION_DARK   => [31, 36, 48],
            QRMatrix::M_DATA_DARK      => [31, 36, 48],
            QRMatrix::M_DARKMODULE     => [31, 36, 48],
        ],
    ]);
    $qr_data_uri = (new QRCode($qr_options))->render($verify_url);

    $draw_date_txt = date('l, d F Y', strtotime($draw['draw_date']));
    $start_txt     = !empty($draw['start_time']) ? date('H:i:s', strtotime($draw['start_time'])) : 'N/A';
    $end_txt       = !empty($draw['end_time'])   ? date('H:i:s', strtotime($draw['end_time']))   : 'N/A';
    $signed_txt    = !empty($draw['partner_signed_at'])
        ? date('d M Y \a\t H:i:s', strtotime($draw['partner_signed_at'])) . ' CAT'
        : '—';

    $exc_status = $draw['exception_status'] ?? 'NONE';
    $exc_ref    = trim((string)($draw['exception_report_ref'] ?? ''));
    $exc_desc   = trim((string)($draw['exception_details'] ?? ''));
    $proc_notes = trim((string)($draw['procedure_notes'] ?? ''));

    $chk_pre  = !empty($draw['chk_pre_draw']);
    $chk_obs  = !empty($draw['chk_draw_obs']);
    $chk_post = !empty($draw['chk_post_draw']);

    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    // Dedicated clean lottery number tiles (centered reliably in Dompdf)
    $ball_accents = ['#E81A3B', '#1E40AF', '#B45309', '#15803D', '#7C1928'];
    $drawn_nums   = [
        (int)($draw['num1_a'] ?? 0),
        (int)($draw['num2_a'] ?? 0),
        (int)($draw['num3_a'] ?? 0),
        (int)($draw['num4_a'] ?? 0),
        (int)($draw['num5_a'] ?? 0),
    ];

    $lottery_tiles_html = '';
    foreach ($drawn_nums as $idx => $num) {
        $accent = $ball_accents[$idx % count($ball_accents)];
        $pos_label = 'BALL ' . ($idx + 1);
        $lottery_tiles_html .= '
        <td style="width: 20%; padding: 0 4pt; text-align: center; vertical-align: middle;">
            <table style="width: 100%; border-collapse: collapse; background-color: #ffffff; border: 1pt solid #d8dde6; border-top: 3pt solid ' . $accent . '; border-radius: 4pt;">
                <tr>
                    <td style="padding: 4pt 2pt 1pt 2pt; text-align: center; font-size: 5.5pt; font-weight: bold; letter-spacing: 0.8pt; color: #6b7280;">' . $pos_label . '</td>
                </tr>
                <tr>
                    <td style="padding: 2pt 2pt 6pt 2pt; text-align: center; font-family: \'DejaVu Serif\', Georgia, serif; font-size: 13pt; font-weight: bold; color: #1f2430; line-height: 1.15;">' . $num . '</td>
                </tr>
            </table>
        </td>';
    }

    $notes_block = '';
    if ($proc_notes !== '') {
        $notes_block = '<div class="note-box"><strong>Observation notes:</strong> ' . nl2br($e($proc_notes)) . '</div>';
    }

    $exc_details_block = '';
    if ($exc_desc !== '') {
        $exc_details_block = '<div class="note-box"><strong>Details:</strong> ' . nl2br($e($exc_desc)) . '</div>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    @page {
        size: A4 portrait;
        margin: 9mm;
    }
    * {
        box-sizing: border-box;
    }
    body {
        margin: 0;
        padding: 0;
        font-family: "DejaVu Sans", Helvetica, Arial, sans-serif;
        color: #1f2430;
        font-size: 9pt;
        background: #ffffff;
    }
    .outer-frame {
        border: 1.5pt solid #1f2430;
        padding: 3pt;
        background: #ffffff;
    }
    .inner-frame {
        border: 0.8pt solid #E81A3B;
        padding: 14pt 18pt 12pt;
    }
    table.layout {
        width: 100%;
        border-collapse: collapse;
    }
    .title-block {
        text-align: center;
        margin-top: 6pt;
        margin-bottom: 9pt;
    }
    .eyebrow {
        font-size: 7pt;
        font-weight: bold;
        letter-spacing: 2px;
        text-transform: uppercase;
        color: #E81A3B;
    }
    .main-title {
        font-family: "DejaVu Serif", Georgia, serif;
        font-size: 17.5pt;
        font-weight: bold;
        color: #1f2430;
        margin: 3pt 0 5pt;
    }
    .divider-line {
        width: 220pt;
        margin: 0 auto 6pt;
        border-top: 0.8pt solid #E81A3B;
        text-align: center;
        line-height: 0.1;
    }
    .divider-diamond {
        background: #ffffff;
        padding: 0 6pt;
        color: #E81A3B;
        font-size: 8pt;
    }
    .statement {
        font-family: "DejaVu Serif", Georgia, serif;
        font-size: 8.8pt;
        line-height: 1.5;
        color: #333a48;
        text-align: center;
        padding: 0 12pt;
        margin: 0;
    }
    
    .lottery-board {
        font-family: "DejaVu Serif", Georgia, serif;
        background-color: #1f2430;
        border-left: 4.5pt solid #E81A3B;
        border-radius: 5pt;
        padding: 8pt 10pt 9pt;
        margin: 8pt 0 10pt;
    }
    .lottery-board-header {
        font-family: "DejaVu Serif", Georgia, serif;
        margin-bottom: 6pt;
        padding: 0 4pt;
    }
    .lottery-board-title {
        font-family: "DejaVu Serif", Georgia, serif;
        font-size: 7pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1.3pt;
        color: #ffffff;
    }
    .lottery-board-sub {
        font-family: "DejaVu Serif", Georgia, serif;
        font-size: 6.4pt;
        color: #cbd5e1;
    }
    .sec-heading {
        font-family: "DejaVu Serif", Georgia, serif;
        font-size: 7.2pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1.1px;
        color: #7c1928;
        border-bottom: 0.8pt solid #e4e6ec;
        padding-bottom: 3pt;
        margin: 0 0 6pt;
    }
    .fact-lbl {
        font-family: "DejaVu Serif", Georgia, serif;
        font-size: 6.4pt;
        text-transform: uppercase;
        letter-spacing: 0.7px;
        color: #5b6270;
        font-weight: bold;
    }
    .fact-val {
        font-family: "DejaVu Serif", Georgia, serif;
        font-size: 8.3pt;
        font-weight: bold;
        color: #1f2430;
        margin-top: 1pt;
        margin-bottom: 6pt;
    }
    .chk-row {
        margin-bottom: 4.5pt;
        font-size: 8pt;
        line-height: 1.35;
    }
    .tick-box {
        display: inline-block;
        width: 11pt;
        height: 11pt;
        line-height: 10.5pt;
        text-align: center;
        border: 1pt solid #9aa1ae;
        border-radius: 2.5pt;
        font-size: 7.5pt;
        font-weight: bold;
        color: #ffffff;
        margin-right: 5pt;
        vertical-align: middle;
    }
    .tick-on {
        background: #165b33;
        border-color: #165b33;
    }
    .tick-flag {
        background: #b45309;
        border-color: #b45309;
    }
    .note-box {
        font-family: "DejaVu Serif", Georgia, serif;
        margin-top: 5pt;
        font-size: 7.3pt;
        color: #374151;
        background: #f7f8fa;
        padding: 5pt 8pt;
        border-left: 2.5pt solid #9ca3af;
        line-height: 1.35;
    }
    .signoff-wrap {
        font-family: "DejaVu Serif", Georgia, serif;
        margin-top: 10pt;
        padding-top: 10pt;
        border-top: 1.2pt solid #1f2430;
    }
    .partner-box {
        font-family: "DejaVu Serif", Georgia, serif;
        background: #ffffff;
        border: 0.8pt solid #cfe5d8;
        border-radius: 5pt;
        padding: 10pt 12pt;
    }
    .sign-eyebrow {
        font-family: "DejaVu Serif", Georgia, serif;
        font-size: 6.6pt;
        font-weight: bold;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: #165b33;
    }
    .sign-name {
        font-family: "DejaVu Serif", Georgia, serif;
        font-size: 11.5pt;
        font-style: italic;
        color: #1f2430;
        margin-top: 4pt;
        padding-bottom: 2pt;
        border-bottom: 0.8pt solid #9ca3af;
    }
    .sign-meta {
        font-family: "DejaVu Serif", Georgia, serif;
        font-size: 7.3pt;
        color: #5b6270;
        margin-top: 4pt;
        line-height: 1.45;
    }
    .qr-box {
        font-family: "DejaVu Serif", Georgia, serif;
        border: 0.8pt solid #e4e6ec;
        background: #ffffff;
        border-radius: 5pt;
        padding: 8pt;
        text-align: center;
    }
    .disclaimer {
        font-family: "DejaVu Serif", Georgia, serif;
        margin-top: 10pt;
        padding-top: 7pt;
        border-top: 0.8pt solid #e4e6ec;
        font-size: 6.3pt;
        line-height: 1.42;
        color: #6b7280;
        text-align: justify;
    }
</style>
</head>
<body>
<div class="outer-frame">
    <div class="inner-frame">

        <!-- Header -->
        <table class="layout">
            <tr>
                <td style="width: 100%; vertical-align: top; text-align: center;">
                    ' . $logo_html . '
                </td>
            </tr>
        </table>

        <!-- Title & Statement -->
        <div class="title-block">
            <div class="eyebrow">Independent Draw Observation &amp; Certification</div>
            <div class="main-title">Certificate of Lottery Draw Verification</div>
            <div class="divider-line"><span class="divider-diamond">&#9670;</span></div>
            <p class="statement">
                This is to certify that on ' . $e($draw_date_txt) . ',
                an independent observer from BDO East Africa (Rwanda) Ltd watched the
                <span class="draw-name">' . $e($draw['draw_session']) . '</span> draw take place at
                ' . $e($draw['venue_location']) . '.
                The winning numbers below were recorded by two people independently and checked against the central system.
            </p>
        </div>

        <!-- Winning Numbers (Dedicated Draw Board) -->
        <div class="lottery-board">
            <table class="layout lottery-board-header">
                <tr>
                    <td style="text-align: left; vertical-align: middle;">
                        <span class="lottery-board-title"> Winning Numbers </span>
                    </td>
                </tr>
            </table>
            <table class="layout">
                <tr>' . $lottery_tiles_html . '</tr>
            </table>
        </div>

        <!-- Draw Details -->
        <div class="sec-heading">Draw Details</div>
        <table class="layout" style="margin-bottom: 6pt;">
            <tr>
                <td style="width: 33.3%; vertical-align: top; padding-right: 10pt;">
                    <div class="fact-lbl">Draw ID</div>
                    <div class="fact-val">' . $e($draw['certificate_ref']) . '</div>
                </td>
                <td style="width: 33.3%; vertical-align: top; padding-right: 10pt;">
                    <div class="fact-lbl">Name of Draw</div>
                    <div class="fact-val">' . $e($draw['draw_session']) . '</div>
                </td>
                <td style="width: 33.4%; vertical-align: top;">
                    <div class="fact-lbl">Draw Date</div>
                    <div class="fact-val">' . $e($draw_date_txt) . '</div>
                </td>
            </tr>
            <tr>
                <td style="vertical-align: top; padding-right: 10pt;">
                    <div class="fact-lbl">Observation Window</div>
                    <div class="fact-val">' . $e($start_txt) . ' &rarr; ' . $e($end_txt) . '</div>
                </td>
                <td colspan="2" style="vertical-align: top;">
                    <div class="fact-lbl">Observing Officer</div>
                    <div class="fact-val">' . $e($draw['officer_name'] ?? '') . '</div>
                </td>
            </tr>
        </table>

        <!-- What We Checked + Exceptions Identified -->
        <table class="layout" style="margin-top: 4pt;">
            <tr>
                <td style="width: 49%; vertical-align: top; padding-right: 10pt;">
                    <div class="sec-heading">What We Checked</div>
                    <div class="chk-row"><span class="tick-box ' . ($chk_pre ? 'tick-on' : '') . '">' . ($chk_pre ? '&#10003;' : '&nbsp;') . '</span>Pre-Draw Machinery &amp; Equipment Check</div>
                    <div class="chk-row"><span class="tick-box ' . ($chk_obs ? 'tick-on' : '') . '">' . ($chk_obs ? '&#10003;' : '&nbsp;') . '</span>Live Draw Observed Against the SOP</div>
                    <div class="chk-row"><span class="tick-box ' . ($chk_post ? 'tick-on' : '') . '">' . ($chk_post ? '&#10003;' : '&nbsp;') . '</span>Post-Draw Reconciliation &amp; Audit</div>
                    ' . $notes_block . '
                </td>
                <td style="width: 51%; vertical-align: top; padding-left: 10pt;">
                    <div class="sec-heading">Exceptions Identified</div>
                    <div class="chk-row"><span class="tick-box ' . ($exc_status === 'NONE' ? 'tick-on' : '') . '">' . ($exc_status === 'NONE' ? '&#10003;' : '&nbsp;') . '</span><strong>None.</strong></div>
                    <div class="chk-row"><span class="tick-box ' . ($exc_status === 'MINOR_RESOLVED' ? 'tick-flag' : '') . '">' . ($exc_status === 'MINOR_RESOLVED' ? '&#10003;' : '&nbsp;') . '</span>Minor issue(s) noted and resolved during the session.</div>
                    <div class="chk-row"><span class="tick-box ' . ($exc_status === 'SIGNIFICANT_EXCEPTION' ? 'tick-flag' : '') . '">' . ($exc_status === 'SIGNIFICANT_EXCEPTION' ? '&#10003;' : '&nbsp;') . '</span>Significant exception(s) &mdash; Report Ref: <strong>' . $e($exc_ref !== '' ? $exc_ref : 'N/A') . '</strong></div>
                    ' . $exc_details_block . '
                </td>
            </tr>
        </table>

        <!-- Partner Sign-Off & QR Verification -->
        <div class="signoff-wrap">
            <table class="layout">
                <tr>
                    <td style="width: 65%; vertical-align: middle; padding-right: 12pt;">
                        <div class="partner-box">
                            <table class="layout">
                                <tr>
                                    <td style="vertical-align: middle; padding-right: 8pt;">
                                        <div class="sign-eyebrow">Certified By</div>
                                        <div class="sign-name">' . $e($draw['partner_name'] ?? '') . '</div>
                                        <div class="sign-meta">
                                            <strong style="color: #1f2430;">Partner, Risk Advisory Services</strong> &mdash; <br>BDO East Africa (Rwanda) Ltd<br>
                                            Signed: ' . $e($signed_txt) . '<br>
                                            ' . $signature_html . '
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </td>
                    <td style="width: 35%; vertical-align: middle;">
                        <div class="qr-box">
                            <img src="' . $qr_data_uri . '" alt="QR Verify" style="width: 68pt; height: 68pt; display: block; margin: 0 auto;">
                            <div style="font-size: 6.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4pt; color: #1f2430;">Scan to Verify</div>
                            <div style="font-size: 6pt; color: #5b6270; margin-top: 1pt; line-height: 1.3;">Checks live against the<br>BDO LDC Registry</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Disclaimer -->
        <div class="disclaimer">
            <strong>Disclaimer:</strong> This certificate is issued byBDO East Africa (Rwanda) Ltd and is intended solely for the Client’s operational and governance purposes. Any external publication or reliance by third parties requires prior written consent from BDO East Africa(Rwanda) Ltd. </strong> 

    </div>
</div>
</body>
</html>';
}

/**
 * Generate the PDF binary string (or stream directly to browser).
 *
 * @param array  $draw       Row from fetch_certified_draw()
 * @param string $verify_url Public QR verification URL
 * @param string $outputMode 'string' | 'inline' | 'download'
 * @return string|null       Returns PDF binary when $outputMode === 'string'
 */
function render_draw_certificate_pdf(array $draw, string $verify_url, string $outputMode = 'string'): ?string
{
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(build_draw_certificate_html($draw, $verify_url), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $safe_ref = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string)($draw['certificate_ref'] ?? 'Certificate'));
    $filename = 'BDO_Draw_Certificate_' . $safe_ref . '.pdf';

    if ($outputMode === 'inline') {
        $dompdf->stream($filename, ['Attachment' => false]);
        return null;
    }
    if ($outputMode === 'download') {
        $dompdf->stream($filename, ['Attachment' => true]);
        return null;
    }

    return $dompdf->output();
}