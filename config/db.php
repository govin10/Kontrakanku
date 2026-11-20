<?php
// =============================================
// Konfigurasi Koneksi Database
// =============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_kontrakan');
// Deteksi BASE_URL otomatis (mendukung localhost Laragon dan hosting online)
$httpHost = $_SERVER['HTTP_HOST'] ?? '';
if (empty($httpHost) || str_contains($httpHost, 'localhost') || str_contains($httpHost, '127.0.0.1')) {
    define('BASE_URL', '/system-keuangan-kontrakan');
} else {
    define('BASE_URL', '');
}

// Kode keamanan pendaftaran admin baru (dapat diubah sesuai kebutuhan pengelola)
define('ADMIN_SECRET_KEY', 'ADMIN123');

// Akun Admin Utama (memiliki hak istimewa menghapus akun admin lainnya)
define('SUPER_ADMIN_EMAIL', 'admin@kontrakan.com');

date_default_timezone_set('Asia/Jakarta');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Koneksi database gagal: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

function redirect(string $path): void {
    header('Location: ' . BASE_URL . $path);
    exit;
}

function formatRupiah(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function namaBulan(int $bulan): string {
    $names = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
        4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September',
        10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    return $names[$bulan] ?? '-';
}

/**
 * Sinkronisasi otomatis rincian biaya bulanan ke tabel pengeluaran
 * saat seluruh penghuni aktif telah lunas membayar iuran untuk periode tersebut.
 */
function syncBiayaToPengeluaranIfAllLunas(PDO $db, int $biayaId): array {
    $stmtBiaya = $db->prepare("SELECT * FROM biaya_bulanan WHERE id = ?");
    $stmtBiaya->execute([$biayaId]);
    $biaya = $stmtBiaya->fetch();
    if (!$biaya) {
        return ['synced' => false, 'action' => 'none', 'count' => 0, 'message' => 'Biaya bulanan tidak ditemukan'];
    }

    $bulan = (int)$biaya['bulan'];
    $tahun = (int)$biaya['tahun'];
    $namaPeriode = namaBulan($bulan) . ' ' . $tahun;
    $tagAuto = "[Auto-Biaya-{$biayaId}]";

    // Ambil semua penghuni aktif
    $stmtPenghuni = $db->query("SELECT id FROM users WHERE role = 'penghuni' AND status = 'aktif'");
    $penghuniAktif = $stmtPenghuni->fetchAll(PDO::FETCH_COLUMN);
    $totalPenghuni = count($penghuniAktif);

    if ($totalPenghuni === 0) {
        return ['synced' => false, 'action' => 'none', 'count' => 0, 'message' => 'Tidak ada penghuni aktif'];
    }

    // Cek penghuni aktif yang belum lunas
    $inPlaceholders = implode(',', array_fill(0, $totalPenghuni, '?'));
    $params = array_merge([$biayaId], $penghuniAktif);

    $stmtBelum = $db->prepare(
        "SELECT COUNT(*) FROM users u
         LEFT JOIN pembayaran p ON p.user_id = u.id AND p.biaya_id = ?
         WHERE u.id IN ($inPlaceholders) AND (p.status IS NULL OR p.status != 'lunas')"
    );
    $stmtBelum->execute($params);
    $unpaidCount = (int)$stmtBelum->fetchColumn();

    $isAllLunas = ($unpaidCount === 0);

    // Ambil item pengeluaran yang saat ini ber-tag biaya ini
    $stmtExisting = $db->prepare("SELECT id FROM pengeluaran WHERE keterangan LIKE ?");
    $stmtExisting->execute(['%' . $tagAuto . '%']);
    $existingIds = $stmtExisting->fetchAll(PDO::FETCH_COLUMN);

    if ($isAllLunas) {
        // Jika semua lunas: bersihkan data lama dengan tag ini jika ada, lalu insert ulang agar selalu up-to-date
        if (!empty($existingIds)) {
            $inIds = implode(',', array_fill(0, count($existingIds), '?'));
            $db->prepare("DELETE FROM pengeluaran WHERE id IN ($inIds)")->execute($existingIds);
        }

        $items = [
            'Sewa Kontrakan'  => (float)$biaya['total_sewa'],
            'Listrik'         => (float)$biaya['total_listrik'],
            'Air'             => (float)$biaya['total_air'],
            'Wifi'            => (float)$biaya['total_wifi'],
            'Biaya Lain-lain' => (float)$biaya['total_lain'],
        ];

        $tglPengeluaran = sprintf('%04d-%02d-01', $tahun, $bulan);
        $adminId = $biaya['created_by'] ?: ($_SESSION['user_id'] ?? null);

        $inserted = 0;
        $ins = $db->prepare(
            "INSERT INTO pengeluaran (nama_item, jumlah, keterangan, tanggal, input_by)
             VALUES (?, ?, ?, ?, ?)"
        );

        foreach ($items as $namaItem => $nominal) {
            if ($nominal > 0) {
                $ket = "Otomatis dari Biaya Bulanan {$namaPeriode} (Semua penghuni lunas) {$tagAuto}";
                $ins->execute([$namaItem, $nominal, $ket, $tglPengeluaran, $adminId]);
                $inserted++;
            }
        }

        return [
            'synced'  => true,
            'action'  => 'inserted',
            'count'   => $inserted,
            'message' => "Semua penghuni lunas! {$inserted} item biaya periode {$namaPeriode} otomatis masuk ke Pengeluaran."
        ];
    } else {
        // Jika tidak semua lunas dan sebelumnya sempat dibuat otomatis, tarik kembali
        if (!empty($existingIds)) {
            $inIds = implode(',', array_fill(0, count($existingIds), '?'));
            $db->prepare("DELETE FROM pengeluaran WHERE id IN ($inIds)")->execute($existingIds);

            return [
                'synced'  => false,
                'action'  => 'removed',
                'count'   => count($existingIds),
                'message' => "Pengeluaran otomatis ditarik kembali karena status belum lunas seluruhnya."
            ];
        }

        return [
            'synced'  => false,
            'action'  => 'none',
            'count'   => 0,
            'message' => "Masih ada {$unpaidCount} penghuni yang belum lunas."
        ];
    }
}

