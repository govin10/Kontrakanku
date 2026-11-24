<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit Test: fungsi tentukanIdHapus() dan hapusPengguna()
 * (fitur Kelola Penghuni - blok "Hapus Pengguna", sebelumnya di admin/users.php).
 *
 * Test Double yang dipakai:
 *  - STUB : PDOStatement. Hanya menyediakan data jawaban (baris pengguna dari fetch()).
 *  - MOCK : PDO. Selain menjawab prepare(), diverifikasi interaksinya:
 *           beginTransaction() / commit() / rollBack() dipanggil atau tidak.
 *
 * Setiap test mengikuti pola Arrange - Act - Assert.
 * Kode TC-xx merujuk ke test case pada dokumen Pre-Lab (Statement & Branch Coverage).
 */
final class HapusPenggunaTest extends TestCase
{
    private const SUPER = 'admin@kontrakan.com';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ---------------------------------------------------------------
    // Helper (Arrange)
    // ---------------------------------------------------------------

    /** Sesi login: role admin dengan email & id tertentu. */
    private function loginAdmin(string $email, int $id): void
    {
        $_SESSION = ['user_id' => $id, 'role' => 'admin', 'email' => $email];
    }

    /** STUB PDOStatement: fetch() mengembalikan $row (atau false jika tidak ada). */
    private function stubStatement(array|false $row): PDOStatement
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->method('execute')->willReturn(true);
        return $stmt;
    }

    /**
     * MOCK PDO.
     * - prepare("SELECT ...") -> stub statement berisi $row.
     * - prepare("DELETE ...") -> stub statement kosong; melempar Exception bila SQL memuat $gagalPada.
     */
    private function mockPdo(array|false $row, ?string $gagalPada = null): PDO
    {
        $select = $this->stubStatement($row);
        $lain   = $this->stubStatement(false);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($select, $lain, $gagalPada) {
                if (str_starts_with($sql, 'SELECT')) {
                    return $select;
                }
                if ($gagalPada !== null && str_contains($sql, $gagalPada)) {
                    throw new Exception('DB error');
                }
                return $lain;
            }
        );
        return $db;
    }

    private function adminLain(): array
    {
        return ['id' => 2, 'nama' => 'Admin Dua', 'email' => 'admin2@kontrakan.com', 'status' => 'aktif', 'role' => 'admin'];
    }

    private function penghuni(string $status): array
    {
        return ['id' => 3, 'nama' => 'Budi', 'email' => 'budi@mail.com', 'status' => $status, 'role' => 'penghuni'];
    }

    // ---------------------------------------------------------------
    // hapusPengguna(): TC-01 s/d TC-11
    // ---------------------------------------------------------------

    /** TC-01 (negatif): ID tidak ada di tabel users. */
    public function testPenggunaTidakDitemukan(): void
    {
        // Arrange
        $this->loginAdmin(self::SUPER, 1);
        $db = $this->mockPdo(false);
        $db->expects($this->never())->method('beginTransaction');

        // Act
        $hasil = hapusPengguna($db, 999);

        // Assert
        $this->assertSame(['Pengguna tidak ditemukan.', 'danger'], $hasil);
    }

    /** TC-02 (negatif): admin biasa (bukan Admin Utama) mencoba menghapus admin lain. */
    public function testAdminBiasaTidakBolehMenghapusAdmin(): void
    {
        // Arrange
        $this->loginAdmin('admin3@kontrakan.com', 9);
        $db = $this->mockPdo($this->adminLain());
        $db->expects($this->never())->method('beginTransaction');

        // Act
        $hasil = hapusPengguna($db, 2);

        // Assert
        $this->assertSame(
            ['Hanya Admin Utama (' . self::SUPER . ') yang memiliki wewenang untuk menghapus akun admin.', 'danger'],
            $hasil
        );
    }

    /** TC-03 (negatif): Admin Utama mencoba menghapus akun Admin Utama. */
    public function testAkunAdminUtamaTidakBolehDihapus(): void
    {
        // Arrange
        $this->loginAdmin(self::SUPER, 1);
        $row = ['id' => 1, 'nama' => 'Admin Utama', 'email' => self::SUPER, 'status' => 'aktif', 'role' => 'admin'];
        $db = $this->mockPdo($row);
        $db->expects($this->never())->method('beginTransaction');

        // Act
        $hasil = hapusPengguna($db, 1);

        // Assert
        $this->assertSame(
            ['Akun Admin Utama (' . self::SUPER . ') tidak dapat dihapus!', 'danger'],
            $hasil
        );
    }

    /** TC-04 (negatif): id target sama dengan user_id sesi, email target bukan Admin Utama. */
    public function testTidakBolehMenghapusAkunSendiri(): void
    {
        // Arrange (kondisi sesi dibuat khusus agar cabang ini tercapai)
        $this->loginAdmin(self::SUPER, 2);
        $db = $this->mockPdo($this->adminLain()); // id = 2
        $db->expects($this->never())->method('beginTransaction');

        // Act
        $hasil = hapusPengguna($db, 2);

        // Assert
        $this->assertSame(['Anda tidak dapat menghapus akun Anda sendiri.', 'danger'], $hasil);
    }

    /** TC-05 (positif): Admin Utama berhasil menghapus admin lain. */
    public function testAdminUtamaBerhasilMenghapusAdminLain(): void
    {
        // Arrange
        $this->loginAdmin(self::SUPER, 1);
        $db = $this->mockPdo($this->adminLain());
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollBack');

        // Act
        $hasil = hapusPengguna($db, 2);

        // Assert
        $this->assertSame(
            ['Akun Admin "Admin Dua" (admin2@kontrakan.com) berhasil dihapus secara permanen.', 'success'],
            $hasil
        );
    }

    /** TC-06 (negatif): query gagal saat transaksi aktif -> rollBack() dipanggil. */
    public function testHapusAdminGagalMelakukanRollbackSaatTransaksiAktif(): void
    {
        // Arrange
        $this->loginAdmin(self::SUPER, 1);
        $db = $this->mockPdo($this->adminLain(), 'DELETE FROM password_resets');
        $db->expects($this->once())->method('beginTransaction');
        $db->method('inTransaction')->willReturn(true);
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollBack');

        // Act
        $hasil = hapusPengguna($db, 2);

        // Assert
        $this->assertSame(['Gagal menghapus admin: DB error', 'danger'], $hasil);
    }

    /** TC-07 (negatif): penghuni masih aktif tidak boleh dihapus. */
    public function testPenghuniAktifTidakBolehDihapus(): void
    {
        // Arrange
        $this->loginAdmin(self::SUPER, 1);
        $db = $this->mockPdo($this->penghuni('aktif'));
        $db->expects($this->never())->method('beginTransaction');

        // Act
        $hasil = hapusPengguna($db, 3);

        // Assert
        $this->assertSame(
            ['Penghuni "Budi" masih aktif! Nonaktifkan akun terlebih dahulu sebelum menghapus.', 'danger'],
            $hasil
        );
    }

    /** TC-08 (positif): penghuni nonaktif berhasil dihapus. */
    public function testPenghuniNonaktifBerhasilDihapus(): void
    {
        // Arrange
        $this->loginAdmin(self::SUPER, 1);
        $db = $this->mockPdo($this->penghuni('nonaktif'));
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollBack');

        // Act
        $hasil = hapusPengguna($db, 3);

        // Assert
        $this->assertSame(['Penghuni "Budi" berhasil dihapus secara permanen.', 'success'], $hasil);
    }

    /** TC-09 (negatif): hapus penghuni gagal saat transaksi aktif -> rollBack() dipanggil. */
    public function testHapusPenghuniGagalMelakukanRollbackSaatTransaksiAktif(): void
    {
        // Arrange
        $this->loginAdmin(self::SUPER, 1);
        $db = $this->mockPdo($this->penghuni('nonaktif'), 'DELETE FROM password_resets');
        $db->expects($this->once())->method('beginTransaction');
        $db->method('inTransaction')->willReturn(true);
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollBack');

        // Act
        $hasil = hapusPengguna($db, 3);

        // Assert
        $this->assertSame(['Gagal menghapus penghuni: DB error', 'danger'], $hasil);
    }

    /** TC-10 (negatif): beginTransaction() gagal (transaksi belum aktif) -> rollBack() tidak dipanggil. */
    public function testHapusAdminGagalTanpaRollbackJikaTransaksiBelumAktif(): void
    {
        // Arrange
        $this->loginAdmin(self::SUPER, 1);
        $db = $this->mockPdo($this->adminLain());
        $db->expects($this->once())->method('beginTransaction')->willThrowException(new Exception('DB error'));
        $db->method('inTransaction')->willReturn(false);
        $db->expects($this->never())->method('commit');
        $db->expects($this->never())->method('rollBack');

        // Act
        $hasil = hapusPengguna($db, 2);

        // Assert
        $this->assertSame(['Gagal menghapus admin: DB error', 'danger'], $hasil);
    }

    /** TC-11 (negatif): beginTransaction() gagal pada penghuni -> rollBack() tidak dipanggil. */
    public function testHapusPenghuniGagalTanpaRollbackJikaTransaksiBelumAktif(): void
    {
        // Arrange
        $this->loginAdmin(self::SUPER, 1);
        $db = $this->mockPdo($this->penghuni('nonaktif'));
        $db->expects($this->once())->method('beginTransaction')->willThrowException(new Exception('DB error'));
        $db->method('inTransaction')->willReturn(false);
        $db->expects($this->never())->method('commit');
        $db->expects($this->never())->method('rollBack');

        // Act
        $hasil = hapusPengguna($db, 3);

        // Assert
        $this->assertSame(['Gagal menghapus penghuni: DB error', 'danger'], $hasil);
    }

    // ---------------------------------------------------------------
    // tentukanIdHapus(): TC-12 (+ variasi kondisi pemicu)
    // ---------------------------------------------------------------

    /** TC-12 (negatif): tidak ada permintaan hapus -> null. */
    public function testTidakAdaPermintaanHapus(): void
    {
        // Arrange
        $server = ['REQUEST_METHOD' => 'GET'];
        $get    = ['q' => 'budi'];

        // Act
        $uid = tentukanIdHapus($server, [], $get);

        // Assert
        $this->assertNull($uid);
    }

    /** Pemicu lewat POST action=delete dan id. */
    public function testPermintaanHapusViaPost(): void
    {
        // Arrange
        $server = ['REQUEST_METHOD' => 'POST'];
        $post   = ['action' => 'delete', 'id' => '7'];

        // Act
        $uid = tentukanIdHapus($server, $post, []);

        // Assert
        $this->assertSame(7, $uid);
    }

    /** Pemicu lewat GET ?delete=<angka>. */
    public function testPermintaanHapusViaGet(): void
    {
        // Arrange
        $server = ['REQUEST_METHOD' => 'GET'];
        $get    = ['delete' => '5'];

        // Act
        $uid = tentukanIdHapus($server, [], $get);

        // Assert
        $this->assertSame(5, $uid);
    }

    /** Negatif: GET ?delete bukan angka tidak dianggap permintaan hapus. */
    public function testPermintaanHapusViaGetNonNumerikDitolak(): void
    {
        // Arrange
        $server = ['REQUEST_METHOD' => 'GET'];
        $get    = ['delete' => 'abc'];

        // Act
        $uid = tentukanIdHapus($server, [], $get);

        // Assert
        $this->assertNull($uid);
    }

    /** Negatif: POST dengan action selain "delete" tidak dianggap permintaan hapus. */
    public function testPostDenganActionLainDitolak(): void
    {
        // Arrange
        $server = ['REQUEST_METHOD' => 'POST'];
        $post   = ['action' => 'update', 'id' => '7'];

        // Act
        $uid = tentukanIdHapus($server, $post, []);

        // Assert
        $this->assertNull($uid);
    }
}
