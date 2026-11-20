<?php
// mailer.php - PHPMailer Integration for LDC System

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../../vendor/autoload.php';

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.office365.com');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', 'alert.rw@bdo-ea.com');
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', 'Bdo@2023!');
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', 'Lottery Draw Certification (LDC) System');
}

function ldc_get_logo_path(): ?string
{
    $candidates = [
        __DIR__ . '/../images/bdo-logo.jpg',
        __DIR__ . '/../images/bdo-logo.png',
    ];
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function ldc_wrap_email_html(string $html_message, bool $has_embedded_logo = false): string
{
    $logo_markup = $has_embedded_logo
        ? '<img src="cid:bdo_logo" alt="BDO" style="height: 54px; width: auto; max-width: 180px; display: inline-block; margin: 0 auto 10px auto;">'
        : '<div style="display: inline-block; background-color: #E81A3B; color: #ffffff; font-family: "Trebuchet MS", "Lucida Sans Unicode", "Lucida Grande", "Lucida Sans", Arial, sans-serif; font-size: 20px; font-weight: 900; letter-spacing: 2px; padding: 6px 14px; border-radius: 3px; margin: 0 auto 10px auto;">BDO</div>';

    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: "Trebuchet MS", "Lucida Sans Unicode", "Lucida Grande", "Lucida Sans", Arial, sans-serif; background-color: #f4f6f9; color: #333333; margin: 0; padding: 20px; }
            .email-container { max-width: 600px; background: #ffffff; margin: 0 auto; border-top: 4px solid #E81A3B; padding: 22px; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
            .email-header { text-align: center; border-bottom: 1px solid #E7E7E7; padding-bottom: 15px; margin-bottom: 20px; }
            .email-header h2 { color: #E81A3B; margin: 4px 0 0; font-size: 1.15rem; }
            .email-body { font-size: 0.95rem; line-height: 1.5; color: #333333; }
            .email-footer { margin-top: 30px; border-top: 1px solid #E7E7E7; padding-top: 15px; text-align: center; font-size: 0.75rem; color: #5b6e7f; }
        </style>
    </head>
    <body>
        <div class="email-container">
            <div class="email-header">
                <div style="text-align: center;">' . $logo_markup . '</div>
                <h2>Lottery Draw Certification (LDC)</h2>
                <p style="margin: 5px 0 0; font-size: 0.8rem; color: #5b6e7f;">BDO East Africa (Rwanda) Ltd &bull; Risk Advisory</p>
            </div>
            <div class="email-body">
                ' . $html_message . '
            </div>
            <div class="email-footer">
                <p>&copy; ' . date('Y') . ' BDO East Africa (Rwanda) Ltd &mdash; LDC System. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>';
}

function ldc_create_smtp_mailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
    return $mail;
}

function ldc_attach_inline_logo(PHPMailer $mail): bool
{
    $logo_path = ldc_get_logo_path();
    if ($logo_path === null) {
        return false;
    }
    $mime = mime_content_type($logo_path) ?: 'image/jpeg';
    return $mail->addEmbeddedImage($logo_path, 'bdo_logo', basename($logo_path), 'base64', $mime);
}

function send_system_email($to_email, $subject, $html_message, &$error_message = '')
{
    $mail = ldc_create_smtp_mailer();

    try {
        $mail->addAddress($to_email);
        $has_logo = ldc_attach_inline_logo($mail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = ldc_wrap_email_html((string)$html_message, $has_logo);
        $mail->AltBody = strip_tags((string)$html_message);

        $mail->send();
        return true;
    } catch (Exception $e) {
        $error_message = $mail->ErrorInfo;
        return false;
    }
}

/**
 * Send an HTML email with an attached PDF certificate to TO recipients and CC recipients.
 *
 * @param array<int, string> $to_emails
 * @param array<int, string> $cc_emails
 */
function send_system_email_with_pdf(
    array $to_emails,
    string $subject,
    string $html_message,
    string $pdf_binary,
    string $pdf_filename,
    array $cc_emails = [],
    string &$error_message = ''
): bool {
    $mail = ldc_create_smtp_mailer();

    try {
        $added_to = 0;
        $normalized_to = [];

        foreach ($to_emails as $to_addr) {
            $clean_to = trim((string)$to_addr);
            if ($clean_to !== '' && filter_var($clean_to, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($clean_to);
                $normalized_to[] = strtolower($clean_to);
                $added_to++;
            }
        }

        if ($added_to === 0) {
            $error_message = 'No valid TO recipient email addresses were provided.';
            return false;
        }

        foreach ($cc_emails as $cc_addr) {
            $clean_cc = trim((string)$cc_addr);
            if ($clean_cc !== '' && filter_var($clean_cc, FILTER_VALIDATE_EMAIL) && !in_array(strtolower($clean_cc), $normalized_to, true)) {
                $mail->addCC($clean_cc);
            }
        }

        $has_logo = ldc_attach_inline_logo($mail);

        if ($pdf_binary !== '') {
            $mail->addStringAttachment($pdf_binary, $pdf_filename, 'base64', 'application/pdf');
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = ldc_wrap_email_html($html_message, $has_logo);
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $html_message));

        $mail->send();
        return true;
    } catch (Exception $e) {
        $error_message = $mail->ErrorInfo;
        return false;
    }
}
?>