<?php
// =============================================
// Mail Helper — Kirim email via PHPMailer
// =============================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/mail.php';

/**
 * Kirim email reset password
 *
 * @param string $toEmail   Alamat email tujuan
 * @param string $toName    Nama penerima
 * @param string $resetLink URL link reset
 * @return true|string      true jika berhasil, pesan error jika gagal
 */
function sendPasswordResetEmail(string $toEmail, string $toName, string $resetLink): true|string
{
    $mail    = new PHPMailer(true);
    $smtpLog = [];

    try {
        // Server settings
        $mail->SMTPDebug  = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function(string $str, int $level) use (&$smtpLog): void {
            $smtpLog[] = trim($str);
        };

        $mail->isSMTP();
        $mail->Host        = MAIL_HOST;
        $mail->SMTPAuth    = true;
        $mail->Username    = MAIL_USERNAME;
        $mail->Password    = MAIL_PASSWORD;
        $mail->SMTPSecure  = MAIL_ENCRYPTION === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = MAIL_PORT;
        $mail->CharSet     = 'UTF-8';
        $mail->Timeout     = 15;

        // Bypass SSL cert verification (aman untuk localhost/dev)
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // Pengirim & penerima
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_FROM_EMAIL, MAIL_FROM_NAME);

        // Konten email
        $mail->isHTML(true);
        $mail->Subject = '[' . APP_NAME . '] Reset Password Akun Anda';
        $mail->Body    = generateResetEmailHtml($toName, $resetLink);
        $mail->AltBody = generateResetEmailText($toName, $resetLink);

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Kembalikan ErrorInfo PHPMailer (lebih detail dari $e->getMessage())
        return $mail->ErrorInfo ?: $e->getMessage();
    }
}

/**
 * Template HTML email reset password
 */
function generateResetEmailHtml(string $name, string $link): string
{
    $appName = APP_NAME;
    $year    = date('Y');
    $expiry  = '1 jam';

    return <<<HTML
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Reset Password</title>
    </head>
    <body style="margin:0;padding:0;background:#0b0b18;font-family:'Segoe UI',Arial,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#0b0b18;padding:40px 16px;">
            <tr>
                <td align="center">
                    <table width="540" cellpadding="0" cellspacing="0" style="background:#111128;border-radius:16px;border:1px solid rgba(255,255,255,0.07);overflow:hidden;max-width:540px;width:100%;">

                        <!-- Header -->
                        <tr>
                            <td style="background:linear-gradient(135deg,#7c6fef,#a855f7);padding:32px;text-align:center;">
                                <div style="font-size:36px;margin-bottom:10px;">🔑</div>
                                <h1 style="color:#fff;font-size:22px;margin:0;font-weight:700;">{$appName}</h1>
                                <p style="color:rgba(255,255,255,0.8);font-size:13px;margin:6px 0 0;">Sistem Keuangan Kontrakan</p>
                            </td>
                        </tr>

                        <!-- Body -->
                        <tr>
                            <td style="padding:36px 32px;">
                                <h2 style="color:#f0f0ff;font-size:20px;margin:0 0 8px;">Halo, {$name}!</h2>
                                <p style="color:#9090b0;font-size:14px;line-height:1.7;margin:0 0 24px;">
                                    Kami menerima permintaan untuk mereset password akun <strong style="color:#f0f0ff;">{$appName}</strong> Anda.
                                    Klik tombol di bawah ini untuk membuat password baru.
                                </p>

                                <!-- CTA Button -->
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td align="center" style="padding:8px 0 28px;">
                                            <a href="{$link}"
                                               style="display:inline-block;background:linear-gradient(135deg,#7c6fef,#a855f7);color:#fff;text-decoration:none;font-size:15px;font-weight:700;padding:14px 36px;border-radius:10px;letter-spacing:0.3px;">
                                                🔐 Reset Password Sekarang
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <!-- Info box -->
                                <div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:10px;padding:14px 16px;margin-bottom:24px;">
                                    <p style="color:#f59e0b;font-size:13px;margin:0;line-height:1.6;">
                                        ⏰ <strong>Link berlaku selama {$expiry}</strong> dan hanya dapat digunakan sekali.
                                        Jika Anda tidak meminta reset password, abaikan email ini.
                                    </p>
                                </div>

                                <!-- Fallback link -->
                                <p style="color:#5a5a7a;font-size:12px;line-height:1.6;margin:0;">
                                    Jika tombol tidak berfungsi, salin dan buka link berikut di browser:<br>
                                    <a href="{$link}" style="color:#7c6fef;word-break:break-all;">{$link}</a>
                                </p>
                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td style="background:rgba(255,255,255,0.03);border-top:1px solid rgba(255,255,255,0.07);padding:20px 32px;text-align:center;">
                                <p style="color:#5a5a7a;font-size:11px;margin:0;line-height:1.6;">
                                    Email ini dikirim otomatis oleh {$appName}. Jangan balas email ini.<br>
                                    © {$year} {$appName}. All rights reserved.
                                </p>
                            </td>
                        </tr>

                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    HTML;
}

/**
 * Plain text fallback
 */
function generateResetEmailText(string $name, string $link): string
{
    $appName = APP_NAME;
    return <<<TEXT
    Halo, {$name}!

    Kami menerima permintaan reset password akun {$appName} Anda.

    Klik link berikut untuk membuat password baru (berlaku 1 jam):
    {$link}

    Jika Anda tidak meminta reset password, abaikan email ini.

    Salam,
    Tim {$appName}
    TEXT;
}
