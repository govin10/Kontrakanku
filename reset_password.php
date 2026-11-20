<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (isset($_SESSION['user_id'])) redirect('/dashboard.php');

$error   = '';
$success = '';
$token   = trim($_GET['token'] ?? '');
$valid   = false;
$email   = '';


try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `email`      VARCHAR(100) NOT NULL,
        `token`      VARCHAR(64)  NOT NULL UNIQUE,
        `expires_at` DATETIME     NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_token` (`token`),
        INDEX `idx_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    
}

// Validasi token
if (empty($token)) {
    $error = 'Token tidak valid atau tidak ditemukan.';
} else {
    try {
        $stmt = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if ($reset) {
            $valid = true;
            $email = $reset['email'];
        } else {
            $error = 'Link reset tidak valid atau sudah kadaluarsa. Silakan minta link baru.';
        }
    } catch (Exception $e) {
        $error = 'Terjadi kesalahan server.';
    }
}

// Proses reset password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $password = trim($_POST['password']      ?? '');
    $konfirm  = trim($_POST['konfirm_pass']  ?? '');

    if (empty($password) || strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif ($password !== $konfirm) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);

            // Update password user
            $upd = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
            $upd->execute([$hash, $email]);

            // Hapus token yang sudah dipakai
            $db->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);

            $success = 'Password berhasil direset! Silakan login dengan password baru.';
            $valid   = false; // Sembunyikan form
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan server. Coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — KontrakanKu</title>
    <meta name="description" content="Buat password baru untuk akun KontrakanKu Anda">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .password-strength {
            margin-top: 8px;
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
            transition: all 0.3s;
        }
        .password-strength-bar {
            height: 100%;
            border-radius: 2px;
            transition: all 0.4s ease;
            width: 0%;
        }
        .strength-text {
            font-size: 11px;
            margin-top: 4px;
            font-weight: 500;
            transition: color 0.3s;
        }
        .strength-weak   { color: var(--danger); }
        .strength-medium { color: var(--warning); }
        .strength-strong { color: var(--success); }

        .token-info {
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.25);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 12.5px;
            color: var(--success);
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .expired-box {
            text-align: center;
            padding: 20px 0;
        }
        .expired-box .expired-icon {
            font-size: 48px;
            color: var(--danger);
            opacity: 0.5;
            margin-bottom: 14px;
            display: block;
        }
        .expired-box p {
            color: var(--text-secondary);
            font-size: 13.5px;
            margin-bottom: 20px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card fade-in">
        <div class="auth-logo">
            <div class="auth-logo-icon">🔐</div>
            <h1>KontrakanKu</h1>
            <p>Buat Password Baru</p>
        </div>

        <div class="auth-form">
            <h2>Reset Password</h2>
            <p class="subtitle">Masukkan password baru untuk akun Anda</p>

            <?php if ($error && !$valid): ?>
            <div class="expired-box">
                <i class="fa-solid fa-triangle-exclamation expired-icon"></i>
                <p><?= htmlspecialchars($error) ?></p>
                <a href="<?= BASE_URL ?>/lupa_password.php" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-rotate-left"></i>
                    Minta Link Reset Baru
                </a>
            </div>
            <?php elseif ($success): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <?= htmlspecialchars($success) ?>
            </div>
            <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary btn-block btn-lg" style="margin-top:8px">
                <i class="fa-solid fa-right-to-bracket"></i>
                Masuk Sekarang
            </a>
            <?php elseif ($valid): ?>

            <?php if ($error): ?>
            <div class="alert alert-danger" data-dismiss="5000">
                <i class="fa-solid fa-circle-xmark"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <div class="token-info">
                <i class="fa-solid fa-shield-halved"></i>
                Link valid untuk: <strong><?= htmlspecialchars($email) ?></strong>
            </div>

            <form method="POST" action="" id="resetForm">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <div class="form-group">
                    <label for="password">Password Baru</label>
                    <div class="input-group">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Minimal 6 karakter"
                            required
                            autocomplete="new-password"
                            id="newPassword"
                        >
                        <i class="fa-regular fa-eye toggle-pw"></i>
                    </div>
                    <div class="password-strength">
                        <div class="password-strength-bar" id="strengthBar"></div>
                    </div>
                    <div class="strength-text" id="strengthText"></div>
                </div>

                <div class="form-group">
                    <label for="konfirm_pass">Konfirmasi Password Baru</label>
                    <div class="input-group">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input
                            type="password"
                            id="konfirm_pass"
                            name="konfirm_pass"
                            class="form-control"
                            placeholder="Ulangi password baru"
                            required
                            autocomplete="new-password"
                        >
                        <i class="fa-regular fa-eye toggle-pw"></i>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:8px" id="submitBtn">
                    <i class="fa-solid fa-key"></i>
                    Simpan Password Baru
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="auth-footer">
            <a href="<?= BASE_URL ?>/login.php">
                <i class="fa-solid fa-arrow-left"></i>
                Kembali ke Login
            </a>
        </div>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
// Password strength meter
const pwInput = document.getElementById('password');
if (pwInput) {
    pwInput.addEventListener('input', () => {
        const val = pwInput.value;
        const bar = document.getElementById('strengthBar');
        const txt = document.getElementById('strengthText');
        let score = 0;
        if (val.length >= 6)  score++;
        if (val.length >= 10) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        const levels = [
            { pct: '0%',   color: '',                        cls: '',               label: '' },
            { pct: '25%',  color: 'var(--danger)',           cls: 'strength-weak',  label: 'Lemah' },
            { pct: '50%',  color: 'var(--warning)',          cls: 'strength-medium',label: 'Sedang' },
            { pct: '75%',  color: 'var(--warning)',          cls: 'strength-medium',label: 'Baik' },
            { pct: '90%',  color: 'var(--success)',          cls: 'strength-strong',label: 'Kuat' },
            { pct: '100%', color: 'var(--success)',          cls: 'strength-strong',label: 'Sangat Kuat' },
        ];

        const lvl = levels[score] || levels[0];
        bar.style.width = lvl.pct;
        bar.style.background = lvl.color;
        txt.textContent = lvl.label;
        txt.className = 'strength-text ' + lvl.cls;
    });
}

// Loading state on submit
const form = document.getElementById('resetForm');
if (form) {
    form.addEventListener('submit', () => {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';
    });
}
</script>
</body>
</html>
