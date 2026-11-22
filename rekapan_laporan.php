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

$totalHariIni = 0;
$jmlHariIni = 0;
$qHari = $conn->query("SELECT COUNT(*) AS jml, COALESCE(SUM(total_harga), 0) AS total FROM transaksi WHERE DATE(tanggal) = CURDATE()");
if ($qHari && $rHari = $qHari->fetch_assoc()) {
    $jmlHariIni = (int)($rHari['jml'] ?? 0);
    $totalHariIni = (float)($rHari['total'] ?? 0);
}

$totalBulanIni = 0;
$jmlBulanIni = 0;
$qBulan = $conn->query("SELECT COUNT(*) AS jml, COALESCE(SUM(total_harga), 0) AS total FROM transaksi WHERE YEAR(tanggal) = YEAR(CURDATE()) AND MONTH(tanggal) = MONTH(CURDATE())");
if ($qBulan && $rBulan = $qBulan->fetch_assoc()) {
    $jmlBulanIni = (int)($rBulan['jml'] ?? 0);
    $totalBulanIni = (float)($rBulan['total'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekapan Laporan - KasirCermat</title>
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
            margin-bottom: 24px;
        }
        .topbar h1 { margin: 0; font-size: 22px; }
        .topbar p { margin: 3px 0 0; color: var(--muted); font-size: 13.5px; }
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
        .boxes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }
        .rekap-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 18px;
        }
        .rekap-box h2 { margin: 0 0 6px; font-size: 19px; }
        .rekap-box p { margin: 0; color: var(--muted); font-size: 13.5px; }
        .rekap-preview {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
        }
        .rekap-preview small { color: var(--muted); display: block; font-size: 12px; }
        .rekap-preview strong { font-size: 20px; color: var(--success); display: block; margin-top: 4px; }
        .rekap-preview span { font-size: 12.5px; color: var(--muted); }
        .btn-open {
            display: inline-block;
            text-align: center;
            padding: 11px 16px;
            background: var(--primary);
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
        }
        .btn-open:hover { background: var(--primary-hover); }
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
            <h1>Rekapan Laporan</h1>
            <p>Pilih kotak rekapan di bawah untuk membuka halaman laporan harian atau bulanan.</p>
        </div>
        <a href="dashboard.php" class="btn-back">&larr; Kembali ke Dashboard</a>
    </div>

    <div class="boxes-grid">
        <div class="rekap-box">
            <div>
                <h2>Rekapan Harian</h2>
                <p>Buka halaman laporan harian untuk melihat transaksi dan menghitung total pendapatan hari ini (<?= date('d M Y') ?>).</p>
            </div>
            <div class="rekap-preview">
                <small>Total Pendapatan Hari Ini</small>
                <strong>Rp <?= number_format($totalHariIni, 0, ',', '.') ?></strong>
                <span><?= $jmlHariIni ?> transaksi hari ini</span>
            </div>
            <a href="laporan_harian.php" class="btn-open">Buka Rekapan Harian &rarr;</a>
        </div>

        <div class="rekap-box">
            <div>
                <h2>Rekapan Bulanan</h2>
                <p>Buka halaman laporan bulanan untuk melihat transaksi dan menghitung total pendapatan bulan ini (<?= date('F Y') ?>).</p>
            </div>
            <div class="rekap-preview">
                <small>Total Pendapatan Bulan Ini</small>
                <strong>Rp <?= number_format($totalBulanIni, 0, ',', '.') ?></strong>
                <span><?= $jmlBulanIni ?> transaksi bulan ini</span>
            </div>
            <a href="laporan_bulanan.php" class="btn-open">Buka Rekapan Bulanan &rarr;</a>
        </div>
    </div>
</main>
</body>
</html>