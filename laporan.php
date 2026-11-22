<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'classes/Database.php';
require_once 'classes/Produk.php';
require_once 'classes/Transaksi.php';

$db = new Database();
$conn = $db->getConnection();

$produkObj = new Produk($conn);
$transaksiObj = new Transaksi($conn);

$namaUser = $_SESSION['nama'] ?? $_SESSION['username'];
$roleUser = strtolower($_SESSION['role'] ?? 'kasir');
$userId = (int)$_SESSION['user_id'];

$notifProduk = "";
$errorProduk = "";
$notifTransaksi = "";
$errorTransaksi = "";

// Proses tambah barang (khusus Admin)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['tambah_produk'])) {
    if ($roleUser !== 'admin') {
        $errorProduk = "Hanya Admin yang dapat menambah barang.";
    } else {
        $kodeProduk = trim($_POST['kode_produk'] ?? '');
        $namaProduk = trim($_POST['nama_produk'] ?? '');
        $hargaProduk = (float)($_POST['harga'] ?? 0);
        $stokProduk = (int)($_POST['stok'] ?? 0);

        if ($namaProduk === '' || $hargaProduk <= 0 || $stokProduk < 0) {
            $errorProduk = "Lengkapi nama produk, harga (> 0), dan stok (minimal 0).";
        } else {
            if ($kodeProduk === '') {
                $kodeProduk = 'BRG' . strtoupper(substr(uniqid(), -4));
            }

            $tersimpan = false;
            $duplikatKode = false;

            try {
                if (method_exists($produkObj, 'tambahProduk')) {
                    $tersimpan = (bool)$produkObj->tambahProduk($kodeProduk, $namaProduk, $hargaProduk, $stokProduk);
                } elseif (method_exists($produkObj, 'create')) {
                    $tersimpan = (bool)$produkObj->create($kodeProduk, $namaProduk, $hargaProduk, $stokProduk);
                } else {
                    $stmt = $conn->prepare("INSERT INTO produk (kode_produk, nama_produk, harga, stok) VALUES (?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("ssdi", $kodeProduk, $namaProduk, $hargaProduk, $stokProduk);
                        $tersimpan = $stmt->execute();
                    }
                }
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1062) {
                    $duplikatKode = true;
                } else {
                    $errorProduk = "Kesalahan database: " . $e->getMessage();
                }
            }

            if ($tersimpan) {
                $notifProduk = "Barang \"$namaProduk\" berhasil ditambahkan ke katalog.";
            } elseif ($duplikatKode) {
                $errorProduk = "Kode barang \"$kodeProduk\" sudah terdaftar. Gunakan kode lain atau kosongkan agar dibuat otomatis.";
            } elseif ($errorProduk === '') {
                $errorProduk = "Gagal menyimpan barang ke database. Periksa kode barang atau struktur tabel produk.";
            }
        }
    }
}

// Proses pembayaran transaksi
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['proses_pembayaran'])) {
    $itemsJson = $_POST['items_json'] ?? '[]';
    $items = json_decode($itemsJson, true);
    $uangBayar = (float)($_POST['uang_bayar'] ?? 0);

    if (!is_array($items) || count($items) === 0) {
        $errorTransaksi = "Keranjang transaksi masih kosong.";
    } else {
        $totalBelanja = 0;
        foreach ($items as $item) {
            $hargaItem = (float)($item['harga'] ?? 0);
            $qtyItem = (int)($item['qty'] ?? 0);
            if ($hargaItem > 0 && $qtyItem > 0) {
                $totalBelanja += ($hargaItem * $qtyItem);
            }
        }

        if ($totalBelanja <= 0) {
            $errorTransaksi = "Total belanja tidak valid.";
        } elseif ($uangBayar < $totalBelanja) {
            $errorTransaksi = "Uang tunai diterima kurang dari total bayar.";
        } else {
            $conn->begin_transaction();
            try {
                $kodeTransaksi = 'TRX' . date('YmdHis') . rand(10, 99);
                $kembalian = $uangBayar - $totalBelanja;
                $stmtTrx = $conn->prepare("INSERT INTO transaksi (kode_transaksi, id_kasir, total_harga, uang_bayar, kembalian, tanggal) VALUES (?, ?, ?, ?, ?, NOW())");
                if (!$stmtTrx) {
                    throw new Exception("Gagal menyiapkan query transaksi.");
                }
                $stmtTrx->bind_param("siddd", $kodeTransaksi, $userId, $totalBelanja, $uangBayar, $kembalian);
                if (!$stmtTrx->execute()) {
                    throw new Exception("Gagal menyimpan data transaksi.");
                }

                $stmtStok = $conn->prepare("UPDATE produk SET stok = GREATEST(0, stok - ?) WHERE nama_produk = ?");
                if ($stmtStok) {
                    foreach ($items as $item) {
                        $namaItem = trim((string)($item['nama'] ?? ''));
                        $qtyItem = (int)($item['qty'] ?? 0);
                        if ($namaItem !== '' && $qtyItem > 0) {
                            $stmtStok->bind_param("is", $qtyItem, $namaItem);
                            $stmtStok->execute();
                        }
                    }
                }

                $conn->commit();
                $notifTransaksi = "Pembayaran berhasil! Kode: $kodeTransaksi | Total: Rp " . number_format($totalBelanja, 0, ',', '.') .
                                  " | Kembalian: Rp " . number_format($kembalian, 0, ',', '.');
            } catch (Exception $e) {
                $conn->rollback();
                $errorTransaksi = "Transaksi gagal: " . $e->getMessage();
            }
        }
    }
}

$daftarProduk = [];
if (method_exists($produkObj, 'getAll')) {
    $resProduk = $produkObj->getAll();
    if (is_array($resProduk)) {
        $daftarProduk = $resProduk;
    } elseif (is_object($resProduk) && method_exists($resProduk, 'fetch_all')) {
        $daftarProduk = $resProduk->fetch_all(MYSQLI_ASSOC);
    } elseif (is_object($resProduk) && method_exists($resProduk, 'fetch_assoc')) {
        while ($row = $resProduk->fetch_assoc()) {
            $daftarProduk[] = $row;
        }
    }
}
if (empty($daftarProduk)) {
    $qProduk = $conn->query("SELECT * FROM produk ORDER BY id DESC");
    if ($qProduk) {
        while ($row = $qProduk->fetch_assoc()) {
            $daftarProduk[] = $row;
        }
    }
}

$totalTransaksiHariIni = 0;
if (method_exists($transaksiObj, 'getTotalHariIni')) {
    $totalTransaksiHariIni = (float)$transaksiObj->getTotalHariIni();
} else {
    $qTotal = $conn->query("SELECT COALESCE(SUM(total_harga), 0) AS total FROM transaksi WHERE DATE(tanggal) = CURDATE()");
    if ($qTotal && $rowTotal = $qTotal->fetch_assoc()) {
        $totalTransaksiHariIni = (float)($rowTotal['total'] ?? 0);
    }
}

$jumlahProduk = count($daftarProduk);
$stokMenipis = 0;
foreach ($daftarProduk as $p) {
    if ((int)($p['stok'] ?? 0) <= 5) {
        $stokMenipis++;
    }
}

// Transaksi yang telah terjadi di BULAN INI (ditampilkan di bagian bawah dashboard)
$transaksiBulanIni = [];
$qBulanIni = $conn->query("
    SELECT t.*, u.nama AS nama_kasir
    FROM transaksi t
    LEFT JOIN users u ON t.id_kasir = u.id
    WHERE YEAR(t.tanggal) = YEAR(CURDATE()) AND MONTH(t.tanggal) = MONTH(CURDATE())
    ORDER BY t.tanggal DESC
");
if ($qBulanIni) {
    while ($rb = $qBulanIni->fetch_assoc()) {
        $transaksiBulanIni[] = $rb;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard POS - KasirCermat</title>
    <style>
        :root {
            --bg: #f1f5f9;
            --surface: #ffffff;
            --sidebar: #0f172a;
            --sidebar-hover: #1e293b;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #16a34a;
            --warning: #d97706;
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
            letter-spacing: 0.5px;
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
            transition: background 0.15s, color 0.15s;
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
        .logout-btn:hover { background: rgba(239, 68, 68, 0.25); }
        .main { flex: 1; padding: 24px 28px; overflow-x: auto; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 22px;
        }
        .topbar h1 { margin: 0; font-size: 22px; }
        .topbar p { margin: 3px 0 0; color: var(--muted); font-size: 13.5px; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .stat-label { font-size: 12.5px; color: var(--muted); font-weight: 600; }
        .stat-value { font-size: 22px; font-weight: 700; margin-top: 6px; }
        .stat-value.green { color: var(--success); }
        .stat-value.amber { color: var(--warning); }
        .form-produk-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            align-items: end;
        }
        .form-group label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text);
        }
        .form-control {
            width: 100%;
            padding: 9px 11px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 13.5px;
        }
        .form-control:focus { outline: none; border-color: var(--primary); }
        .btn-save {
            padding: 10px 16px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
        }
        .btn-save:hover { background: var(--primary-hover); }
        .alert-ok {
            background: #dcfce7;
            color: #166534;
            padding: 10px 14px;
            border-radius: 9px;
            font-size: 13px;
            margin-bottom: 12px;
            font-weight: 500;
        }
        .alert-err {
            background: #fee2e2;
            color: #991b1b;
            padding: 10px 14px;
            border-radius: 9px;
            font-size: 13px;
            margin-bottom: 12px;
            font-weight: 500;
        }
        .pos-grid {
            display: grid;
            grid-template-columns: 1.55fr 1fr;
            gap: 20px;
            align-items: start;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            margin-bottom: 20px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }
        .card-header h2 { margin: 0; font-size: 17px; }
        .link-laporan {
            font-size: 13px;
            font-weight: 600;
            color: var(--primary);
            text-decoration: none;
        }
        .link-laporan:hover { text-decoration: underline; }
        .search-input {
            padding: 8px 12px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 13.5px;
            width: 210px;
        }
        .search-input:focus { outline: none; border-color: var(--primary); }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        th, td { padding: 11px 10px; border-bottom: 1px solid var(--border); text-align: left; }
        th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; }
        .badge-stok {
            background: #dcfce7;
            color: #166534;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-low { background: #fef3c7; color: #92400e; }
        .btn-add {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 6px 11px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-add:hover { background: var(--primary-hover); }
        .cart-items {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 14px;
            background: #f8fafc;
        }
        .cart-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 4px;
            border-bottom: 1px dashed #cbd5e1;
            font-size: 13px;
        }
        .cart-row:last-child { border-bottom: none; }
        .summary-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .summary-line.total {
            font-size: 17px;
            font-weight: 700;
            padding-top: 8px;
            border-top: 1.5px solid var(--border);
        }
        .pay-input {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 14px;
            margin: 8px 0 12px;
        }
        .btn-checkout {
            width: 100%;
            padding: 11px;
            background: var(--success);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 14.5px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-checkout:hover { background: #15803d; }
        @media (max-width: 960px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; flex-direction: row; align-items: center; padding: 12px 16px; }
            .nav-menu, .sidebar-footer .user-info { display: none; }
            .sidebar-footer { border-top: none; padding-top: 0; }
            .pos-grid { grid-template-columns: 1fr; }
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
            <li><a href="#kasir" class="active">Kasir &amp; Produk</a></li>
            <?php if ($roleUser === 'admin'): ?>
                <li><a href="#tambah-barang">Tambah Barang</a></li>
            <?php endif; ?>
            <li><a href="laporan.php?periode=bulan">Laporan Bulan Ini</a></li>
            <li><a href="laporan.php?periode=hari">Laporan Hari Ini</a></li>
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

<main class="main" id="kasir">
    <div class="topbar">
        <div>
            <h1>Dashboard KasirCermat</h1>
            <p>Halo, <strong><?= htmlspecialchars($namaUser) ?></strong> — siap memproses transaksi hari ini.</p>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Pendapatan Hari Ini</div>
            <div class="stat-value green">Rp <?= number_format($totalTransaksiHariIni, 0, ',', '.') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Jenis Produk</div>
            <div class="stat-value"><?= $jumlahProduk ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Stok Menipis (&le; 5)</div>
            <div class="stat-value amber"><?= $stokMenipis ?></div>
        </div>
    </div>

    <?php if ($roleUser === 'admin'): ?>
    <div class="card" id="tambah-barang">
        <div class="card-header">
            <h2>Tambah Barang Baru</h2>
        </div>

        <?php if ($notifProduk !== ''): ?>
            <div class="alert-ok"><?= htmlspecialchars($notifProduk) ?></div>
        <?php endif; ?>
        <?php if ($errorProduk !== ''): ?>
            <div class="alert-err"><?= htmlspecialchars($errorProduk) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-produk-grid">
                <div class="form-group">
                    <label for="kode_produk">Kode Barang</label>
                    <input type="text" id="kode_produk" name="kode_produk" class="form-control" placeholder="Contoh: BRG001">
                </div>
                <div class="form-group">
                    <label for="nama_produk">Nama Barang</label>
                    <input type="text" id="nama_produk" name="nama_produk" class="form-control" placeholder="Contoh: Air Mineral" required>
                </div>
                <div class="form-group">
                    <label for="harga">Harga (Rp)</label>
                    <input type="number" id="harga" name="harga" class="form-control" placeholder="4000" min="1" required>
                </div>
                <div class="form-group">
                    <label for="stok">Stok</label>
                    <input type="number" id="stok" name="stok" class="form-control" placeholder="20" min="0" required>
                </div>
                <div class="form-group">
                    <button type="submit" name="tambah_produk" class="btn-save">+ Simpan Barang</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="pos-grid">
        <div class="card">
            <div class="card-header">
                <h2>Katalog Produk</h2>
                <input type="text" id="searchProduk" class="search-input" placeholder="Cari nama produk..." onkeyup="filterProduk()">
            </div>
            <table id="tabelProduk">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>Harga</th>
                        <th>Stok</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($daftarProduk)): ?>
                    <?php foreach ($daftarProduk as $item):
                        $kode = $item['kode_produk'] ?? '';
                        $nama = $item['nama_produk'] ?? 'Produk';
                        $harga = (float)($item['harga'] ?? 0);
                        $stok = (int)($item['stok'] ?? 0);
                        $lowClass = $stok <= 5 ? 'badge-low' : '';
                    ?>
                    <tr>
                        <td class="nama-col">
                            <strong><?= htmlspecialchars($nama) ?></strong>
                            <?php if ($kode !== ''): ?>
                                <br><small style="color:var(--muted);"><?= htmlspecialchars($kode) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>Rp <?= number_format($harga, 0, ',', '.') ?></td>
                        <td><span class="badge-stok <?= $lowClass ?>"><?= $stok ?></span></td>
                        <td>
                            <button type="button" class="btn-add"
                                onclick='tambahKeranjang(<?= json_encode($nama) ?>, <?= $harga ?>)'>
                                + Tambah
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align:center; color:var(--muted); padding:20px;">
                            Belum ada data produk di database.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Keranjang Transaksi</h2>
            </div>

            <?php if ($notifTransaksi !== ''): ?>
                <div class="alert-ok"><?= htmlspecialchars($notifTransaksi) ?></div>
            <?php endif; ?>
            <?php if ($errorTransaksi !== ''): ?>
                <div class="alert-err"><?= htmlspecialchars($errorTransaksi) ?></div>
            <?php endif; ?>

            <div class="cart-items" id="cartList">
                <div style="text-align:center; color:var(--muted); padding:24px 8px;" id="emptyCart">
                    Klik <strong>+ Tambah</strong> pada produk untuk memulai.
                </div>
            </div>

            <div class="summary-line total">
                <span>Total Bayar</span>
                <span id="totalText">Rp 0</span>
            </div>

            <label for="uangBayar" style="font-size:13px; font-weight:600;">Uang Tunai Diterima (Rp)</label>
            <input type="number" id="uangBayar" class="pay-input" placeholder="Contoh: 50000" min="0" oninput="hitungKembalian()">

            <div class="summary-line">
                <span>Kembalian</span>
                <strong id="kembalianText" style="color:var(--success);">Rp 0</strong>
            </div>

            <form method="POST" action="" id="formBayar">
                <input type="hidden" name="items_json" id="itemsJson">
                <input type="hidden" name="uang_bayar" id="uangBayarInput">
                <input type="hidden" name="proses_pembayaran" value="1">
                <button type="button" class="btn-checkout" onclick="prosesBayar()">
                    Proses Pembayaran
                </button>
            </form>
        </div>
    </div>

    <div class="card" id="transaksi-bulan-ini">
        <div class="card-header">
            <h2>Transaksi Bulan Ini (<?= date('F Y') ?>)</h2>
            <a href="laporan.php?periode=bulan" class="link-laporan">Lihat Laporan Lengkap &rarr;</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Kode Transaksi</th>
                    <th>Tanggal</th>
                    <th>Kasir</th>
                    <th>Total</th>
                    <th>Bayar</th>
                    <th>Kembalian</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($transaksiBulanIni)): ?>
                <?php foreach ($transaksiBulanIni as $tr): ?>
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
                    <td colspan="6" style="text-align:center; color:var(--muted); padding:18px;">
                        Belum ada transaksi di bulan ini.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<script>
let keranjang = [];

function formatRp(angka) {
    return 'Rp ' + Math.max(0, Math.round(angka)).toLocaleString('id-ID');
}

function tambahKeranjang(nama, harga) {
    const idx = keranjang.findIndex(item => item.nama === nama);
    if (idx >= 0) {
        keranjang[idx].qty += 1;
    } else {
        keranjang.push({ nama, harga, qty: 1 });
    }
    renderKeranjang();
}

function ubahQty(idx, delta) {
    keranjang[idx].qty += delta;
    if (keranjang[idx].qty <= 0) {
        keranjang.splice(idx, 1);
    }
    renderKeranjang();
}

function renderKeranjang() {
    const list = document.getElementById('cartList');
    if (keranjang.length === 0) {
        list.innerHTML = '<div style="text-align:center; color:#64748b; padding:24px 8px;">Klik <strong>+ Tambah</strong> pada produk untuk memulai.</div>';
        document.getElementById('totalText').textContent = 'Rp 0';
        hitungKembalian();
        return;
    }

    let html = '';
    let total = 0;
    keranjang.forEach((item, i) => {
        const sub = item.harga * item.qty;
        total += sub;
        html += `
            <div class="cart-row">
                <div>
                    <strong>${item.nama}</strong><br>
                    <small style="color:#64748b;">${formatRp(item.harga)} x ${item.qty}</small>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" onclick="ubahQty(${i}, -1)" style="padding:2px 7px; cursor:pointer;">-</button>
                    <span>${item.qty}</span>
                    <button type="button" onclick="ubahQty(${i}, 1)" style="padding:2px 7px; cursor:pointer;">+</button>
                    <strong style="margin-left:4px;">${formatRp(sub)}</strong>
                </div>
            </div>
        `;
    });

    list.innerHTML = html;
    document.getElementById('totalText').textContent = formatRp(total);
    hitungKembalian(total);
}

function hitungKembalian(totalOverride = null) {
    let total = totalOverride;
    if (total === null) {
        total = keranjang.reduce((sum, item) => sum + (item.harga * item.qty), 0);
    }
    const bayar = parseFloat(document.getElementById('uangBayar').value || '0');
    const kembali = bayar - total;
    const el = document.getElementById('kembalianText');
    el.textContent = formatRp(kembali);
    el.style.color = kembali >= 0 ? '#16a34a' : '#dc2626';
}

function prosesBayar() {
    if (keranjang.length === 0) {
        alert('Keranjang masih kosong!');
        return;
    }
    const total = keranjang.reduce((sum, item) => sum + (item.harga * item.qty), 0);
    const bayar = parseFloat(document.getElementById('uangBayar').value || '0');
    if (bayar < total) {
        alert('Uang tunai diterima masih kurang dari total bayar!');
        return;
    }

    document.getElementById('itemsJson').value = JSON.stringify(keranjang);
    document.getElementById('uangBayarInput').value = bayar;
    document.getElementById('formBayar').submit();
}

function filterProduk() {
    const q = document.getElementById('searchProduk').value.toLowerCase();
    const rows = document.querySelectorAll('#tabelProduk tbody tr');
    rows.forEach(row => {
        const namaCell = row.querySelector('.nama-col');
        if (!namaCell) return;
        const cocok = namaCell.textContent.toLowerCase().includes(q);
        row.style.display = cocok ? '' : 'none';
    });
}
</script>
</body>
</html>