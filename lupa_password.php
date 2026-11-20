<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/mailer.php';

if (isset($_SESSION['user_id'])) redirect('/dashboard.php');


try {
    $dbInit = getDB();
    $dbInit->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `email`      VARCHAR(100) NOT NULL,
        `token`      VARCHAR(64)  NOT NULL UNIQUE,
        `expires_at` DATETIME     NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_token` (`token`),
        INDEX `idx_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {  }

$error      = '';
$success    = '';
$email_sent = false;
$mail_error = '';
$reset_link = ''; // fallback jika email gagal

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Masukkan alamat email yang valid.';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT id, nama FROM users WHERE email = ? AND status = 'aktif' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Hapus token lama untuk email ini
                $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);

                // Buat token baru (valid 1 jam)
                $token = bin2hex(random_bytes(32));

                $ins = $db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
                $ins->execute([$email, $token]);

                // Buat link reset lengkap menggunakan domain hosting (APP_URL)
                $appBaseUrl      = defined('APP_URL') && !empty(APP_URL) ? rtrim(APP_URL, '/') : 'https://kontrakanku.42web.io';
                $reset_link_full = $appBaseUrl . '/reset_password.php?token=' . $token;

                // Kirim email via PHPMailer
                $mailResult = sendPasswordResetEmail($email, $user['nama'], $reset_link_full);

                if ($mailResult === true) {
                    $email_sent = true;
                    $success = 'Email reset password telah dikirim ke <strong>' . htmlspecialchars($email) . '</strong>. Periksa kotak masuk (dan folder Spam) Anda.';
                } else {
                    // Email gagal → tampilkan link sebagai fallback
                    $mail_error = $mailResult;
                    $reset_link = $reset_link_full;
                    $success = 'Link reset berhasil dibuat untuk <strong>' . htmlspecialchars($user['nama']) . '</strong>, namun pengiriman email gagal. Gunakan link di bawah ini.';
                }
            } else {
                // Pesan samar agar tidak bocorkan data
                $success = 'Jika email terdaftar dan aktif, instruksi reset akan dikirim ke email tersebut.';
            }
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan server: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password — KontrakanKu</title>
    <meta name="description" content="Reset password akun KontrakanKu Anda">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .reset-link-box {
            background: rgba(124, 111, 239, 0.08);
            border: 1px solid rgba(124, 111, 239, 0.35);
            border-radius: 10px;
            padding: 16px;
            margin-top: 18px;
            word-break: break-all;
        }
        .reset-link-box .link-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--accent-primary);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .reset-link-box .link-url {
            font-size: 12.5px;
            color: var(--text-secondary);
            font-family: monospace;
            line-height: 1.5;
        }
        .reset-link-box .btn-copy {
            margin-top: 12px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 16px;
            background: var(--accent-gradient);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        .reset-link-box .btn-copy:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }
        .expire-note {
            font-size: 11.5px;
            color: var(--warning);
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .step-hint {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-top: 20px;
        }
        .step-hint .step-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .step-hint ol {
            list-style: decimal;
            padding-left: 18px;
            color: var(--text-secondary);
            font-size: 12.5px;
            line-height: 1.8;
        }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card fade-in">
        <div class="auth-logo">
            <div class="auth-logo-icon">🔑</div>
            <h1>KontrakanKu</h1>
            <p>Reset Password Akun</p>
        </div>

        <div class="auth-form">
            <h2>Lupa Password?</h2>
            <p class="subtitle">Masukkan email Anda dan kami akan memberikan link untuk mereset password</p>

            <?php if ($error): ?>
            <div class="alert alert-danger" data-dismiss="6000">
                <i class="fa-solid fa-circle-xmark"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-<?= $email_sent ? 'success' : ($mail_error ? 'warning' : 'success') ?>">
                <i class="fa-solid fa-<?= $email_sent ? 'envelope-circle-check' : ($mail_error ? 'triangle-exclamation' : 'circle-check') ?>"></i>
                <?= $success ?>
            </div>

            <?php if ($email_sent): ?>
            <!-- Email berhasil dikirim -->
            <div class="step-hint">
                <div class="step-title">
                    <i class="fa-solid fa-envelope"></i>
                    Langkah selanjutnya:
                </div>
                <ol>
                    <li>Buka kotak masuk email <strong><?= htmlspecialchars($_POST['email'] ?? '') ?></strong></li>
                    <li>Cari email dari <strong>KontrakanKu</strong> (cek folder Spam jika tidak ada)</li>
                    <li>Klik tombol <em>"Reset Password Sekarang"</em> di email</li>
                    <li>Masukkan password baru dan simpan</li>
                </ol>
            </div>
            <?php endif; ?>

            <?php if ($mail_error): ?>
            <!-- SMTP error detail -->
            <div style="background:rgba(239,68,68,0.07);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:10px 14px;margin-bottom:12px;">
                <p style="font-size:11.5px;color:var(--danger);margin:0;"><i class="fa-solid fa-circle-info"></i> <strong>Detail error SMTP:</strong> <?= htmlspecialchars($mail_error) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($reset_link): ?>
            <div class="reset-link-box">
                <div class="link-label">
                    <i class="fa-solid fa-link"></i>
                    Link Reset Password (Fallback)
                </div>
                <div class="link-url" id="resetLinkUrl"><?= htmlspecialchars($reset_link) ?></div>
                <button class="btn-copy" onclick="copyLink()">
                    <i class="fa-regular fa-copy" id="copyIcon"></i>
                    <span id="copyText">Salin Link</span>
                </button>
                <div class="expire-note">
                    <i class="fa-solid fa-clock"></i>
                    Link berlaku selama <strong>1 jam</strong> dan hanya dapat digunakan sekali.
                </div>
            </div>

            <div class="step-hint">
                <div class="step-title">
                    <i class="fa-solid fa-circle-info"></i>
                    Cara menggunakan:
                </div>
                <ol>
                    <li>Salin link di atas</li>
                    <li>Buka link tersebut di browser</li>
                    <li>Masukkan password baru Anda</li>
                    <li>Login dengan password baru</li>
                </ol>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <form method="POST" action="" id="forgotForm">
                <div class="form-group">
                    <label for="email">Alamat Email Terdaftar</label>
                    <div class="input-group">
                        <i class="fa-solid fa-envelope input-icon"></i>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            placeholder="contoh@email.com"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            required
                            autocomplete="email"
                        >
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:8px" id="submitBtn">
                    <i class="fa-solid fa-paper-plane"></i>
                    Kirim Email Reset
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="auth-footer">
            Ingat password Anda? <a href="<?= BASE_URL ?>/login.php">Kembali ke Login</a>
        </div>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
function copyLink() {
    const url = document.getElementById('resetLinkUrl')?.textContent?.trim();
    if (!url) return;

    navigator.clipboard.writeText(url).then(() => {
        const icon = document.getElementById('copyIcon');
        const text = document.getElementById('copyText');
        icon.className = 'fa-solid fa-check';
        text.textContent = 'Tersalin!';

        setTimeout(() => {
            icon.className = 'fa-regular fa-copy';
            text.textContent = 'Salin Link';
        }, 2500);
    }).catch(() => {
        // Fallback
        const el = document.createElement('textarea');
        el.value = url;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);

        const icon = document.getElementById('copyIcon');
        const text = document.getElementById('copyText');
        icon.className = 'fa-solid fa-check';
        text.textContent = 'Tersalin!';
        setTimeout(() => {
            icon.className = 'fa-regular fa-copy';
            text.textContent = 'Salin Link';
        }, 2500);
    });
}

// Loading state on submit
const form = document.getElementById('forgotForm');
if (form) {
    form.addEventListener('submit', () => {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memproses...';
    });
}
</script>
</body>
</html>
