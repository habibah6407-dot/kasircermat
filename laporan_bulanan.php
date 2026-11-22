<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'classes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$namaUser = $_SESSION['nama'] ?? $_SESSION['username'];
$roleUser = strtolower($_SESSION['role'] ?? 'kasir');

// Mode tampilan: 'bulanan' (default) atau 'harian'
$mode = $_GET['mode'] ?? 'bulanan';
if (!in_array($mode, ['bulanan', 'harian'])) {
    $mode = 'bulanan';
}

// Nilai kalender bulan (format YYYY-MM)
$bulanDipilih = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulanDipilih)) {
    $bulanDipilih = date('Y-m');
}

// Nilai kalender harian (format YYYY-MM-DD)
$tanggalDipilih = $_GET['tanggal'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalDipilih)) {
    $tanggalDipilih = date('Y-m-d');
}

$totalPendapatan = 0;
$totalBayar = 0;
$totalKembalian = 0;
$jumlahTransaksi = 0;
$daftarTransaksi = [];

if ($mode === 'harian') {
    $judulPeriode = "Rekapan Harian (" . date('d M Y', strtotime($tanggalDipilih)) . ")";
    $labelTotal = "Hari Ini / Tanggal Dipilih";

    $stmtTotal = $conn->prepare("
        SELECT
            COUNT(*) AS jml,
            COALESCE(SUM(total_harga), 0) AS total,
            COALESCE(SUM(uang_bayar), 0) AS bayar,
            COALESCE(SUM(kembalian), 0) AS kembali
        FROM transaksi
        WHERE DATE(tanggal) = ?
    ");
    if ($stmtTotal) {
        $stmtTotal->bind_param("s", $tanggalDipilih);
        $stmtTotal->execute();
        $resTotal = $stmtTotal->get_result();
        if ($resTotal && $rowTotal = $resTotal->fetch_assoc()) {
            $jumlahTransaksi = (int)($rowTotal['jml'] ?? 0);
            $totalPendapatan = (float)($rowTotal['total'] ?? 0);
            $totalBayar = (float)($rowTotal['bayar'] ?? 0);
            $totalKembalian = (float)($rowTotal['kembali'] ?? 0);
        }
    }

    $stmtList = $conn->prepare("
        SELECT t.*, u.nama AS nama_kasir
        FROM transaksi t
        LEFT JOIN users u ON t.id_kasir = u.id
        WHERE DATE(t.tanggal) = ?
        ORDER BY t.tanggal DESC
    ");
    if ($stmtList) {
        $stmtList->bind_param("s", $tanggalDipilih);
        $stmtList->execute();
        $resList = $stmtList->get_result();
        while ($resList && $row = $resList->fetch_assoc()) {
            $daftarTransaksi[] = $row;
        }
    }
} else {
    $judulPeriode = "Rekapan Bulanan (" . date('F Y', strtotime($bulanDipilih . '-01')) . ")";
    $labelTotal = "Bulan Dipilih";

    $stmtTotal = $conn->prepare("
        SELECT
            COUNT(*) AS jml,
            COALESCE(SUM(total_harga), 0) AS total,
            COALESCE(SUM(uang_bayar), 0) AS bayar,
            COALESCE(SUM(kembalian), 0) AS kembali
        FROM transaksi
        WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?
    ");
    if ($stmtTotal) {
        $stmtTotal->bind_param("s", $bulanDipilih);
        $stmtTotal->execute();
        $resTotal = $stmtTotal->get_result();
        if ($resTotal && $rowTotal = $resTotal->fetch_assoc()) {
            $jumlahTransaksi = (int)($rowTotal['jml'] ?? 0);
            $totalPendapatan = (float)($rowTotal['total'] ?? 0);
            $totalBayar = (float)($rowTotal['bayar'] ?? 0);
            $totalKembalian = (float)($rowTotal['kembali'] ?? 0);
        }
    }

    $stmtList = $conn->prepare("
        SELECT t.*, u.nama AS nama_kasir
        FROM transaksi t
        LEFT JOIN users u ON t.id_kasir = u.id
        WHERE DATE_FORMAT(t.tanggal, '%Y-%m') = ?
        ORDER BY t.tanggal DESC
    ");
    if ($stmtList) {
        $stmtList->bind_param("s", $bulanDipilih);
        $stmtList->execute();
        $resList = $stmtList->get_result();
        while ($resList && $row = $resList->fetch_assoc()) {
            $daftarTransaksi[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($judulPeriode) ?> - KasirCermat</title>
    <style>
        :root {
            --bg: #f1f5f9;
            --surface: #ffffff;
            --sidebar: #0f172a;
            --sidebar-hover: #1e293b;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #16a34a;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 245px;
            background: var(--sidebar);
            color: #e2e8f0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 22px 16px;
            flex-shrink: 0;
        }
        .logo {
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            padding: 0 10px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .role-pill {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 999px;
            background: rgba(59, 130, 246, 0.25);
            color: #93c5fd;
            text-transform: uppercase;
        }
        .nav-menu {
            list-style: none;
            padding: 16px 0 0;
            margin: 0;
            display: grid;
            gap: 6px;
        }
        .nav-menu a {
            display: block;
            padding: 11px 14px;
            border-radius: 10px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .nav-menu a:hover, .nav-menu a.active {
            background: var(--sidebar-hover);
            color: #fff;
        }
        .nav-menu a.active { border-left: 3px solid #3b82f6; }
        .sidebar-footer {
            border-top: 1px solid rgba(255,255,255,0.08);
            padding-top: 16px;
        }
        .user-info { font-size: 13px; margin-bottom: 10px; padding: 0 6px; }
        .user-info span { display: block; color: #94a3b8; font-size: 12px; }
        .logout-btn {
            display: block;
            text-align: center;
            padding: 10px;
            border-radius: 10px;
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
        }
        .main { flex: 1; padding: 24px 28px; overflow-x: auto; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .topbar h1 { margin: 0; font-size: 22px; }
        .topbar p { margin: 3px 0 0; color: var(--muted); font-size: 13.5px; }
        .top-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-back {
            padding: 9px 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 9px;
            color: var(--text);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: flex-end;
            justify-content: space-between;
        }
        .filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }
        .date-input {
            padding: 8px 11px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 13.5px;
            background: #fff;
            color: var(--text);
        }
        .btn-filter {
            padding: 9px 14px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-filter:hover { background: var(--primary-hover); }
        .btn-secondary {
            padding: 9px 14px;
            background: #f8fafc;
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-secondary.active {
            background: #0f172a;
            color: #fff;
            border-color: #0f172a;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px 20px;
        }
        .stat-label { font-size: 12.5px; color: var(--muted); font-weight: 600; }
        .stat-value { font-size: 21px; font-weight: 700; margin-top: 6px; color: var(--success); }
        .card-header { margin-bottom: 14px; }
        .card-header h2 { margin: 0; font-size: 17px; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        th, td { padding: 11px 10px; border-bottom: 1px solid var(--border); text-align: left; }
        th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; }
        tfoot td {
            background: #f8fafc;
            font-weight: 700;
            border-top: 2px solid #cbd5e1;
            border-bottom: none;
            padding: 13px 10px;
        }
        .total-highlight { color: var(--success); font-size: 15px; }
        @media (max-width: 960px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; flex-direction: row; align-items: center; padding: 12px 16px; }
            .nav-menu, .sidebar-footer .user-info { display: none; }
            .sidebar-footer { border-top: none; padding-top: 0; }
        }
    </style>
</head>
<body>
<aside class="sidebar">
    <div>
        <div class="logo">
            <span>KasirCermat</span>
            <span class="role-pill"><?= htmlspecialchars($roleUser) ?></span>
        </div>
        <ul class="nav-menu">
            <li><a href="dashboard.php">Kasir &amp; Produk</a></li>
            <?php if ($roleUser === 'admin'): ?>
                <li><a href="dashboard.php#tambah-barang">Tambah Barang</a></li>
                <li><a href="dashboard.php#laporan">Laporan &amp; Akses Admin</a></li>
            <?php endif; ?>
            <li><a href="rekapan_laporan.php" class="active">Rekapan Laporan</a></li>
        </ul>
    </div>

    <div class="sidebar-footer">
        <div class="user-info">
            <strong><?= htmlspecialchars($namaUser) ?></strong>
            <span>Login sebagai <?= htmlspecialchars(strtoupper($roleUser)) ?></span>
        </div>
        <a href="logout.php" class="logout-btn">Keluar / Logout</a>
    </div>
</aside>

<main class="main">
    <div class="topbar">
        <div>
            <h1><?= htmlspecialchars($judulPeriode) ?></h1>
            <p>Pilih bulan lain atau tekan mode harian dengan kalender untuk melihat transaksi sesuai pilihan.</p>
        </div>
        <div class="top-actions">
            <a href="rekapan_laporan.php" class="btn-back">&larr; Kembali ke Rekapan Laporan</a>
            <a href="dashboard.php" class="btn-back">Dashboard</a>
        </div>
    </div>

    <div class="card">
        <div class="filter-bar">
            <!-- Form Kalender Bulan Lain -->
            <form method="GET" action="" class="filter-group">
                <input type="hidden" name="mode" value="bulanan">
                <label for="kalenderBulan">Pilih Bulan:</label>
                <input type="month" id="kalenderBulan" name="bulan" class="date-input" value="<?= htmlspecialchars($bulanDipilih) ?>">
                <button type="submit" class="btn-filter">Tampilkan Bulan</button>
                <a href="laporan_bulanan.php?mode=bulanan&amp;bulan=<?= date('Y-m') ?>" class="btn-secondary <?= ($mode === 'bulanan' && $bulanDipilih === date('Y-m')) ? 'active' : '' ?>">
                    Bulan Ini
                </a>
            </form>

            <!-- Form Kalender Harian -->
            <form method="GET" action="" class="filter-group" id="formHarian">
                <input type="hidden" name="mode" value="harian">
                <label for="kalenderHarian">Kalender Harian:</label>
                <input type="date" id="kalenderHarian" name="tanggal" class="date-input" value="<?= htmlspecialchars($tanggalDipilih) ?>">
                <button type="submit" class="btn-secondary <?= $mode === 'harian' ? 'active' : '' ?>">
                    Harian
                </button>
            </form>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Pendapatan (<?= htmlspecialchars($labelTotal) ?>)</div>
            <div class="stat-value">Rp <?= number_format($totalPendapatan, 0, ',', '.') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Jumlah Transaksi</div>
            <div class="stat-value"><?= $jumlahTransaksi ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Daftar Transaksi - <?= htmlspecialchars($judulPeriode) ?></h2>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Kode Transaksi</th>
                    <th>Tanggal &amp; Waktu</th>
                    <th>Kasir</th>
                    <th>Total</th>
                    <th>Bayar</th>
                    <th>Kembalian</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($daftarTransaksi)): ?>
                <?php foreach ($daftarTransaksi as $tr): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($tr['kode_transaksi'] ?? ('TRX-' . ($tr['id'] ?? ''))) ?></strong></td>
                    <td><?= htmlspecialchars($tr['tanggal'] ?? '') ?></td>
                    <td><?= htmlspecialchars($tr['nama_kasir'] ?? ('ID #' . ($tr['id_kasir'] ?? ''))) ?></td>
                    <td>Rp <?= number_format((float)($tr['total_harga'] ?? 0), 0, ',', '.') ?></td>
                    <td>Rp <?= number_format((float)($tr['uang_bayar'] ?? 0), 0, ',', '.') ?></td>
                    <td>Rp <?= number_format((float)($tr['kembalian'] ?? 0), 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align:center; color:var(--muted); padding:20px;">
                        Belum ada transaksi pada periode yang dipilih.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3">Total Keseluruhan (<?= $jumlahTransaksi ?> Transaksi)</td>
                    <td class="total-highlight">Rp <?= number_format($totalPendapatan, 0, ',', '.') ?></td>
                    <td>Rp <?= number_format($totalBayar, 0, ',', '.') ?></td>
                    <td>Rp <?= number_format($totalKembalian, 0, ',', '.') ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Total Keseluruhan - <?= htmlspecialchars($judulPeriode) ?></h2>
        </div>
        <div class="stats-grid" style="margin-bottom: 0;">
            <div class="stat-card">
                <div class="stat-label">Total Transaksi</div>
                <div class="stat-value"><?= $jumlahTransaksi ?> Transaksi</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Uang Bayar</div>
                <div class="stat-value">Rp <?= number_format($totalBayar, 0, ',', '.') ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Kembalian</div>
                <div class="stat-value">Rp <?= number_format($totalKembalian, 0, ',', '.') ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Pendapatan</div>
                <div class="stat-value">Rp <?= number_format($totalPendapatan, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
</main>

<script>
// Saat tanggal pada kalender harian diubah, otomatis tampilkan rekapan hari tersebut
document.getElementById('kalenderHarian').addEventListener('change', function () {
    document.getElementById('formHarian').submit();
});
</script>
</body>
</html>