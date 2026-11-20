<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db  = getDB();
$msg = $msgType = '';

// Ubah status pengguna (penghuni atau admin)
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $uid = (int)$_GET['toggle'];
    $cur = $db->prepare("SELECT id, nama, email, role, status FROM users WHERE id = ?");
    $cur->execute([$uid]);
    $row = $cur->fetch();
    if ($row) {
        if ($row['role'] === 'admin') {
            if (!isSuperAdmin()) {
                $msg = "Hanya Admin Utama (" . SUPER_ADMIN_EMAIL . ") yang berhak mengubah status akun admin.";
                $msgType = 'danger';
            } elseif ($row['email'] === SUPER_ADMIN_EMAIL) {
                $msg = "Status akun Admin Utama tidak dapat diubah!";
                $msgType = 'danger';
            } else {
                $newStatus = $row['status'] === 'aktif' ? 'nonaktif' : 'aktif';
                $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $uid]);
                $msg = "Status admin \"" . $row['nama'] . "\" berhasil diubah ke $newStatus.";
                $msgType = 'success';
            }
        } else {
            $newStatus = $row['status'] === 'aktif' ? 'nonaktif' : 'aktif';
            $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $uid]);
            $msg = "Status penghuni berhasil diubah ke $newStatus.";
            $msgType = 'success';
        }
    }
    header('Location: users.php?msg=' . urlencode($msg) . '&type=' . $msgType);
    exit;
}

// Hapus pengguna (penghuni atau admin)
if (
    ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) ||
    (isset($_GET['delete']) && is_numeric($_GET['delete']))
) {
    $uid = (int)($_POST['id'] ?? $_GET['delete']);

    $cur = $db->prepare("SELECT id, nama, email, status, role FROM users WHERE id = ?");
    $cur->execute([$uid]);
    $user = $cur->fetch();

    if (!$user) {
        $msg = "Pengguna tidak ditemukan.";
        $msgType = 'danger';
    } elseif ($user['role'] === 'admin') {
        // Hanya Admin Utama yang berhak menghapus akun admin lainnya
        if (!isSuperAdmin()) {
            $msg = "Hanya Admin Utama (" . SUPER_ADMIN_EMAIL . ") yang memiliki wewenang untuk menghapus akun admin.";
            $msgType = 'danger';
        } elseif ($user['email'] === SUPER_ADMIN_EMAIL) {
            $msg = "Akun Admin Utama (" . SUPER_ADMIN_EMAIL . ") tidak dapat dihapus!";
            $msgType = 'danger';
        } elseif ($user['id'] === currentUserId()) {
            $msg = "Anda tidak dapat menghapus akun Anda sendiri.";
            $msgType = 'danger';
        } else {
            try {
                $db->beginTransaction();

                // Hapus token reset password
                $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$user['email']]);
                // Hapus notifikasi terkait
                $db->prepare("DELETE FROM notifikasi WHERE user_id = ?")->execute([$uid]);
                // Hapus akun admin
                $db->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'")->execute([$uid]);

                $db->commit();
                $msg = "Akun Admin \"" . $user['nama'] . "\" (" . $user['email'] . ") berhasil dihapus secara permanen.";
                $msgType = 'success';
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $msg = "Gagal menghapus admin: " . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($user['status'] !== 'nonaktif') {
        $msg = "Penghuni \"" . $user['nama'] . "\" masih aktif! Nonaktifkan akun terlebih dahulu sebelum menghapus.";
        $msgType = 'danger';
    } else {
        try {
            $db->beginTransaction();

            // Hapus token reset password terkait email ini jika ada
            $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$user['email']]);

            // Hapus penghuni (pembayaran & notifikasi ikut terhapus via CASCADE)
            $db->prepare("DELETE FROM users WHERE id = ? AND role = 'penghuni' AND status = 'nonaktif'")->execute([$uid]);

            $db->commit();
            $msg = "Penghuni \"" . $user['nama'] . "\" berhasil dihapus secara permanen.";
            $msgType = 'success';
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $msg = "Gagal menghapus penghuni: " . $e->getMessage();
            $msgType = 'danger';
        }
    }
    header('Location: users.php?msg=' . urlencode($msg) . '&type=' . $msgType);
    exit;
}

if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

// Daftar semua penghuni
$search = trim($_GET['q'] ?? '');
if ($search) {
    $stmt = $db->prepare(
        "SELECT * FROM users WHERE role='penghuni' AND (nama LIKE ? OR email LIKE ? OR nomor_kamar LIKE ?)
         ORDER BY nomor_kamar, nama"
    );
    $like = "%$search%";
    $stmt->execute([$like, $like, $like]);
} else {
    $stmt = $db->query("SELECT * FROM users WHERE role='penghuni' ORDER BY nomor_kamar, nama");
}
$penghuniList = $stmt->fetchAll();

// Daftar akun admin
$adminList = $db->query("SELECT * FROM users WHERE role='admin' ORDER BY created_at ASC")->fetchAll();

$pageTitle    = 'Data Penghuni & Admin';
$pageSubtitle = 'Kelola akun penghuni dan administrator kontrakan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header fade-in">
    <div>
        <h1>Data Pengguna & Admin</h1>
        <p>Manajemen akun penghuni dan daftar administrator kontrakan</p>
    </div>
    <div class="d-flex gap-8">
        <a href="<?= BASE_URL ?>/register_admin.php" class="btn btn-primary">
            <i class="fa-solid fa-user-shield"></i> Registrasi Admin Baru
        </a>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> fade-in" data-dismiss="4000">
    <i class="fa-solid fa-<?= $msgType === 'danger' ? 'circle-exclamation' : 'circle-check' ?>"></i> <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Stats -->
<?php
$aktifCount    = count(array_filter($penghuniList, fn($u) => $u['status'] === 'aktif'));
$nonaktifCount = count($penghuniList) - $aktifCount;
?>
<div class="stats-grid fade-in" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:20px">
    <div class="stat-card purple">
        <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
        <div class="stat-label">Total Penghuni</div>
        <div class="stat-value"><?= count($penghuniList) ?></div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon"><i class="fa-solid fa-user-check"></i></div>
        <div class="stat-label">Aktif</div>
        <div class="stat-value"><?= $aktifCount ?></div>
    </div>
    <div class="stat-card red">
        <div class="stat-icon"><i class="fa-solid fa-user-xmark"></i></div>
        <div class="stat-label">Nonaktif</div>
        <div class="stat-value"><?= $nonaktifCount ?></div>
    </div>
    <div class="stat-card blue">
        <div class="stat-icon"><i class="fa-solid fa-user-shield"></i></div>
        <div class="stat-label">Total Admin</div>
        <div class="stat-value"><?= count($adminList) ?></div>
    </div>
</div>

<!-- Daftar Administrator -->
<div class="card fade-in mb-20">
    <div class="card-header d-flex justify-between align-center">
        <div>
            <h3 style="display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-shield-halved text-primary"></i> Daftar Administrator Kontrakan
            </h3>
            <p style="font-size:12.5px; color:var(--text-secondary); margin:4px 0 0;">
                Kelola akun administrator dengan hak akses pengelolaan sistem keuangan kontrakan
            </p>
        </div>
        <a href="<?= BASE_URL ?>/register_admin.php" class="btn btn-sm btn-outline-primary">
            <i class="fa-solid fa-plus"></i> Tambah Admin
        </a>
    </div>

    <?php if (isSuperAdmin()): ?>
    <div style="padding: 12px 20px; background: rgba(245,158,11,0.08); border-bottom: 1px solid rgba(245,158,11,0.2); font-size: 13px; color: #fbbf24; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-crown"></i>
        <span>Anda login sebagai <strong>Admin Utama (<?= htmlspecialchars(SUPER_ADMIN_EMAIL) ?>)</strong>. Anda memiliki wewenang penuh untuk mengelola dan menghapus akun admin lainnya.</span>
    </div>
    <?php else: ?>
    <div style="padding: 12px 20px; background: rgba(59,130,246,0.08); border-bottom: 1px solid rgba(59,130,246,0.2); font-size: 12.5px; color: #93c5fd; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-shield-halved"></i>
        <span>Hanya <strong>Admin Utama (<?= htmlspecialchars(SUPER_ADMIN_EMAIL) ?>)</strong> yang memiliki wewenang untuk menghapus akun administrator.</span>
    </div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Administrator</th>
                    <th>Email</th>
                    <th>Tingkat Akses</th>
                    <th>Bergabung</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($adminList as $adm):
                    $isMainAdmin = ($adm['email'] === SUPER_ADMIN_EMAIL);
                ?>
                <tr>
                    <td>
                        <div class="d-flex align-center gap-12">
                            <div class="user-avatar" style="width:36px;height:36px;font-size:14px;flex-shrink:0;background:<?= $isMainAdmin ? 'linear-gradient(135deg, #f59e0b, #d97706)' : 'linear-gradient(135deg, #7c6fef, #ec4899)' ?>;color:#fff;">
                                <?= strtoupper(substr($adm['nama'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-600">
                                    <?= htmlspecialchars($adm['nama']) ?>
                                    <?php if ($adm['id'] == currentUserId()): ?>
                                    <span class="badge badge-info" style="font-size:10px;margin-left:4px;">Anda</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="text-muted"><?= htmlspecialchars($adm['email']) ?></td>
                    <td>
                        <?php if ($isMainAdmin): ?>
                        <span class="badge" style="background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.3); font-weight:600;">
                            <i class="fa-solid fa-crown" style="font-size:11px;"></i> Admin Utama
                        </span>
                        <?php else: ?>
                        <span class="badge badge-primary" style="background:rgba(124,111,239,0.15); color:#a78bfa; border:1px solid rgba(124,111,239,0.3);">
                            <i class="fa-solid fa-shield-halved" style="font-size:11px;"></i> Admin
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= date('d M Y', strtotime($adm['created_at'])) ?></td>
                    <td>
                        <?php if ($adm['status'] === 'aktif'): ?>
                        <span class="badge badge-success">Aktif</span>
                        <?php else: ?>
                        <span class="badge badge-danger">Nonaktif</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isMainAdmin): ?>
                        <span class="text-muted" style="font-size:12px;" title="Akun Admin Utama dilindungi dan tidak dapat dihapus">
                            <i class="fa-solid fa-lock text-warning"></i> Terproteksi
                        </span>
                        <?php elseif (isSuperAdmin()): ?>
                        <div class="d-flex align-center gap-8">
                            <a href="?toggle=<?= $adm['id'] ?>"
                               class="btn btn-sm btn-<?= $adm['status'] === 'aktif' ? 'warning' : 'success' ?>"
                               title="<?= $adm['status'] === 'aktif' ? 'Nonaktifkan Admin' : 'Aktifkan Admin' ?>"
                               data-confirm="<?= $adm['status'] === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' ?> akses administrator <?= htmlspecialchars($adm['nama']) ?>?">
                                <i class="fa-solid fa-<?= $adm['status'] === 'aktif' ? 'user-slash' : 'user-check' ?>"></i>
                            </a>
                            <form method="POST" action="users.php" style="display:inline;margin:0">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $adm['id'] ?>">
                                <button type="submit"
                                        class="btn btn-sm btn-danger"
                                        data-confirm="Hapus permanen akun admin <?= htmlspecialchars($adm['nama']) ?> (<?= htmlspecialchars($adm['email']) ?>)?&#10;&#10;PERHATIAN: Hak akses akun admin ini akan dicabut secara permanen. Tindakan tidak dapat dibatalkan!"
                                        title="Hapus administrator">
                                    <i class="fa-solid fa-trash"></i> Hapus
                                </button>
                            </form>
                        </div>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:12px;" title="Hanya Admin Utama yang berwenang mengelola admin">
                            <i class="fa-solid fa-shield-halved"></i> Khusus Admin Utama
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Search Penghuni -->
<div class="card fade-in mb-20">
    <form method="GET" class="d-flex gap-12">
        <div class="input-group" style="flex:1">
            <i class="fa-solid fa-search input-icon"></i>
            <input type="text" name="q" class="form-control" placeholder="Cari nama, email, atau nomor kamar penghuni..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <button type="submit" class="btn btn-primary">Cari</button>
        <?php if ($search): ?>
        <a href="users.php" class="btn btn-secondary">Reset</a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabel Penghuni -->
<div class="card fade-in">
    <div class="card-header">
        <h3>Daftar Penghuni <?= $search ? '— hasil pencarian "' . htmlspecialchars($search) . '"' : '' ?></h3>
    </div>
    <?php if (empty($penghuniList)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-users"></i>
        <p>Belum ada penghuni terdaftar</p>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Penghuni</th>
                    <th>Email</th>
                    <th>Kamar</th>
                    <th>Bergabung</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($penghuniList as $u): ?>
                <tr>
                    <td>
                        <div class="d-flex align-center gap-12">
                            <div class="user-avatar" style="width:36px;height:36px;font-size:14px;flex-shrink:0"><?= strtoupper(substr($u['nama'], 0, 1)) ?></div>
                            <div class="fw-600"><?= htmlspecialchars($u['nama']) ?></div>
                        </div>
                    </td>
                    <td class="text-muted"><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <span class="chip">Kamar <?= htmlspecialchars($u['nomor_kamar'] ?? '—') ?></span>
                    </td>
                    <td class="text-muted"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <?php if ($u['status'] === 'aktif'): ?>
                        <span class="badge badge-success">Aktif</span>
                        <?php else: ?>
                        <span class="badge badge-danger">Nonaktif</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['status'] === 'aktif'): ?>
                        <a href="?toggle=<?= $u['id'] ?>"
                           class="btn btn-sm btn-warning"
                           data-confirm="Nonaktifkan penghuni <?= htmlspecialchars($u['nama']) ?>?">
                            <i class="fa-solid fa-user-xmark"></i> Nonaktifkan
                        </a>
                        <?php else: ?>
                        <div class="d-flex align-center gap-8">
                            <a href="?toggle=<?= $u['id'] ?>" class="btn btn-sm btn-success" title="Aktifkan kembali akun ini">
                                <i class="fa-solid fa-user-check"></i> Aktifkan
                            </a>
                            <form method="POST" action="users.php" style="display:inline;margin:0">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit"
                                        class="btn btn-sm btn-danger"
                                        data-confirm="Hapus permanen penghuni <?= htmlspecialchars($u['nama']) ?>?&#10;&#10;PERHATIAN: Riwayat pembayaran dan data terkait akun ini akan ikut terhapus. Tindakan ini tidak dapat dibatalkan!"
                                        title="Hapus permanen penghuni">
                                    <i class="fa-solid fa-trash"></i> Hapus
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
