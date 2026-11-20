<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Jika sudah login sebagai penghuni biasa, arahkan ke dashboard
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'penghuni') {
    redirect('/dashboard.php');
}

$isAlreadyAdmin = isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin';
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama      = trim($_POST['nama']         ?? '');
    $email     = trim($_POST['email']        ?? '');
    $adminKey  = trim($_POST['admin_key']    ?? '');
    $password  = trim($_POST['password']     ?? '');
    $konfirm   = trim($_POST['konfirm_pass'] ?? '');

    if (empty($nama) || empty($email) || empty($adminKey) || empty($password) || empty($konfirm)) {
        $error = 'Semua field wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strtolower($email) === strtolower(SUPER_ADMIN_EMAIL)) {
        $error = 'Email Admin Utama (' . SUPER_ADMIN_EMAIL . ') dilindungi dan tidak dapat didaftarkan ulang.';
    } elseif ($adminKey !== ADMIN_SECRET_KEY) {
        $error = 'Kode keamanan admin salah! Hubungi pengelola kontrakan untuk mendapatkan kode yang valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif ($password !== $konfirm) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        try {
            $db    = getDB();
            $check = $db->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'Email sudah terdaftar. Gunakan email lain.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare(
                    "INSERT INTO users (nama, email, password, role, nomor_kamar, status)
                     VALUES (?, ?, ?, 'admin', NULL, 'aktif')"
                );
                $stmt->execute([$nama, $email, $hash]);
                $newAdminId = (int)$db->lastInsertId();

                // Notifikasi selamat datang untuk admin baru
                try {
                    $notifStmt = $db->prepare(
                        "INSERT INTO notifikasi (user_id, judul, pesan, is_read)
                         VALUES (?, 'Selamat Datang Admin!', 'Akun admin Anda berhasil didaftarkan. Anda memiliki akses penuh ke panel pengelolaan keuangan kontrakan.', 0)"
                    );
                    $notifStmt->execute([$newAdminId]);
                } catch (Exception $e) {
                    // Ignore notification error if table schema varies
                }

                if ($isAlreadyAdmin) {
                    redirect('/admin/users.php?msg=' . urlencode("Akun admin baru ($nama) berhasil didaftarkan!") . '&type=success');
                } else {
                    redirect('/login.php?registered_admin=1');
                }
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
    <title>Registrasi Admin — KontrakanKu</title>
    <meta name="description" content="Pendaftaran akun administrator sistem keuangan kontrakan">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .admin-badge-portal {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(168, 85, 247, 0.15);
            border: 1px solid rgba(168, 85, 247, 0.4);
            color: #c084fc;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .admin-glow-icon {
            background: linear-gradient(135deg, #7c6fef 0%, #ec4899 100%) !important;
            box-shadow: 0 8px 24px rgba(236, 72, 153, 0.35) !important;
            color: #ffffff;
        }
        .secret-key-hint {
            font-size: 11.5px;
            color: var(--text-secondary);
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card fade-in" style="max-width:500px">
        <div class="auth-logo">
            <div class="auth-logo-icon admin-glow-icon">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <div class="admin-badge-portal">
                <i class="fa-solid fa-shield-halved"></i> Portal Khusus Pengelola
            </div>
            <h1>KontrakanKu</h1>
            <p>Registrasi Akun Administrator Baru</p>
        </div>

        <div class="auth-form">
            <h2>Daftar Sebagai Admin</h2>
            <p class="subtitle">Memerlukan kode keamanan khusus yang diberikan oleh pemilik atau pengelola.</p>

            <?php if ($isAlreadyAdmin): ?>
            <div class="alert alert-info" style="background:rgba(59,130,246,0.12); border-color:rgba(59,130,246,0.3); color:#93c5fd; margin-bottom:16px;">
                <i class="fa-solid fa-circle-info"></i>
                Anda sedang login sebagai <strong><?= htmlspecialchars($_SESSION['nama']) ?> (Admin)</strong>. Akun yang didaftarkan di sini akan ditambahkan sebagai admin baru.
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger" data-dismiss="5000">
                <i class="fa-solid fa-circle-xmark"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="nama">Nama Lengkap Admin</label>
                    <div class="input-group">
                        <i class="fa-solid fa-user input-icon"></i>
                        <input type="text" id="nama" name="nama" class="form-control"
                            placeholder="Contoh: Budi Santoso (Admin)"
                            value="<?= htmlspecialchars($_POST['nama'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Alamat Email</label>
                    <div class="input-group">
                        <i class="fa-solid fa-envelope input-icon"></i>
                        <input type="email" id="email" name="email" class="form-control"
                            placeholder="admin@kontrakan.com"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="admin_key">Kode Keamanan Admin (Secret Key)</label>
                    <div class="input-group">
                        <i class="fa-solid fa-key input-icon"></i>
                        <input type="password" id="admin_key" name="admin_key" class="form-control"
                            placeholder="Masukkan kode keamanan pengelola"
                            value="<?= htmlspecialchars($_POST['admin_key'] ?? '') ?>" required autocomplete="off">
                        <i class="fa-regular fa-eye toggle-pw"></i>
                    </div>
                    <div class="secret-key-hint">
                        <i class="fa-solid fa-shield"></i>
                        Kode verifikasi rahasia untuk otorisasi hak akses administrator
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" class="form-control"
                            placeholder="Minimal 6 karakter" required autocomplete="new-password">
                        <i class="fa-regular fa-eye toggle-pw"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="konfirm_pass">Konfirmasi Password</label>
                    <div class="input-group">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input type="password" id="konfirm_pass" name="konfirm_pass" class="form-control"
                            placeholder="Ulangi password Anda" required autocomplete="new-password">
                        <i class="fa-regular fa-eye toggle-pw"></i>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:12px; background:linear-gradient(135deg, #7c6fef 0%, #ec4899 100%);">
                    <i class="fa-solid fa-user-shield"></i>
                    Daftar Sebagai Admin
                </button>
            </form>
        </div>

        <div class="auth-footer" style="display:flex; flex-direction:column; gap:8px;">
            <?php if ($isAlreadyAdmin): ?>
            <div>
                <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary btn-sm" style="width:100%;">
                    <i class="fa-solid fa-arrow-left"></i> Kembali ke Panel Admin
                </a>
            </div>
            <?php else: ?>
            <div>
                Sudah punya akun? <a href="<?= BASE_URL ?>/login.php">Masuk di sini</a>
            </div>
            <div style="font-size:12.5px;">
                Bukan admin? <a href="<?= BASE_URL ?>/register.php">Daftar sebagai Penghuni</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
