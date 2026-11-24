<?php
// =============================================
// Logika Hapus Pengguna (penghuni / admin)
// Dipindahkan dari blok "Hapus pengguna" di admin/users.php
// agar dapat diuji secara unit. Logika di dalamnya tidak diubah.
// =============================================

/**
 * Menentukan ID pengguna yang akan dihapus dari data request.
 * (Kondisi pemicu dan perhitungan $uid yang semula ada di admin/users.php.)
 *
 * @return int|null ID pengguna, atau null jika request bukan permintaan hapus.
 */
function tentukanIdHapus(array $server, array $post, array $get): ?int
{
    if (
        (($server['REQUEST_METHOD'] ?? null) === 'POST' && isset($post['action']) && $post['action'] === 'delete' && isset($post['id'])) ||
        (isset($get['delete']) && is_numeric($get['delete']))
    ) {
        return (int)($post['id'] ?? $get['delete']);
    }
    return null;
}

/**
 * Menghapus pengguna (penghuni atau admin) beserta aturan hak aksesnya.
 *
 * @return array{0:string,1:string} [pesan, tipe pesan: 'success' | 'danger']
 */
function hapusPengguna(PDO $db, int $uid): array
{
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

    return [$msg, $msgType];
}
