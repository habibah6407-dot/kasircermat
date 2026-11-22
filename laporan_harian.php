<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

date_default_timezone_set('Asia/Makassar');

require_once 'classes/Database.php';

$db = new Database();
$conn = $db->getConnection();
if (isset($conn) && is_object($conn)) {
    $conn->query("SET time_zone = '+08:00'");
}

$namaUser = $_SESSION['nama'] ?? $_SESSION['username'];
$roleUser = strtolower($_SESSION['role'] ?? 'kasir');

// Tanggal yang dipilih dari kalender (default: tanggal hari ini otomatis)
$tanggalHariIni = date('Y-m-d');
$tanggalDipilih = $_GET['tanggal'] ?? $tanggalHariIni;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalDipilih)) {
    $tanggalDipilih = $tanggalHariIni;
}
$timestampTanggal = strtotime($tanggalDipilih . ' 00:00:00');
$labelTanggal = $timestampTanggal ? date('d M Y', $timestampTanggal) : date('d M Y');

// Filter kategori jumlah barang: 'semua', 'sedikit' (1-2 barang), 'banyak' (3+ barang)
$filterJumlah = $_GET['tipe_barang'] ?? 'semua';
if (!in_array($filterJumlah, ['semua', 'sedikit', 'banyak'])) {
    $filterJumlah = 'semua';
}

// Cek kolom tambahan di tabel transaksi (jumlah_barang & rincian_barang)
$kolomTransaksi = [];
$qKol = $conn->query("SHOW COLUMNS FROM transaksi");
if ($qKol) {
    while ($kRow = $qKol->fetch_assoc()) {
        if (!empty($kRow['Field'])) {
            $kolomTransaksi[] = $kRow['Field'];
        }
    }
}
$adaJumlahBarang = in_array('jumlah_barang', $kolomTransaksi, true);
$adaRincianBarang = in_array('rincian_barang', $kolomTransaksi, true);

$extraSelectTr = "";
if ($adaJumlahBarang) {
    $extraSelectTr .= ", t.jumlah_barang";
}
if ($adaRincianBarang) {
    $extraSelectTr .= ", t.rincian_barang";
}

// Ambil rincian barang dari detail_transaksi JOIN produk
$mapDetailBarang = [];
$qDetail = $conn->query("
    SELECT
        dt.id_transaksi,
        dt.id_produk,
        dt.jumlah,
        dt.subtotal,
        p.kode_produk,
        p.nama_produk,
        p.harga
    FROM detail_transaksi dt
    INNER JOIN produk p ON dt.id_produk = p.id
    ORDER BY dt.id ASC
");
if ($qDetail) {
    while ($r = $qDetail->fetch_assoc()) {
        $idTr = (int)$r['id_transaksi'];
        $qty  = (int)$r['jumlah'];
        $hargaSatuan = (float)($r['harga'] ?? 0);
        $subtotalItem = (float)($r['subtotal'] ?? 0);
        if ($subtotalItem <= 0 && $hargaSatuan > 0 && $qty > 0) {
            $subtotalItem = $hargaSatuan * $qty;
        }
        if ($hargaSatuan <= 0 && $qty > 0 && $subtotalItem > 0) {
            $hargaSatuan = $subtotalItem / $qty;
        }

        if (!isset($mapDetailBarang[$idTr])) {
            $mapDetailBarang[$idTr] = [
                'total_item' => 0,
                'items'      => [],
            ];
        }
        $mapDetailBarang[$idTr]['total_item'] += $qty;
        $mapDetailBarang[$idTr]['items'][] = [
            'kode'     => $r['kode_produk'] ?? '',
            'nama'     => $r['nama_produk'] ?? 'Produk',
            'qty'      => $qty,
            'harga'    => $hargaSatuan,
            'subtotal' => $subtotalItem,
        ];
    }
}

// Ambil transaksi sesuai tanggal kalender yang dipilih, diurutkan dari transaksi pertama
$semuaTransaksiTanggal = [];
$stmtList = $conn->prepare("
    SELECT
        t.id,
        t.kode_transaksi,
        t.tanggal,
        t.id_kasir,
        t.total_harga,
        t.uang_bayar,
        t.kembalian
        {$extraSelectTr},
        u.nama AS nama_kasir
    FROM transaksi t
    LEFT JOIN users u ON t.id_kasir = u.id
    WHERE DATE(t.tanggal) = ?
    ORDER BY t.id ASC
");
if ($stmtList) {
    $stmtList->bind_param("s", $tanggalDipilih);
    $stmtList->execute();
    $qList = $stmtList->get_result();
    if ($qList) {
        while ($row = $qList->fetch_assoc()) {
            $idTr = (int)$row['id'];
            $itemsTr = [];
            $jmlBarang = 0;

            if (isset($mapDetailBarang[$idTr]) && !empty($mapDetailBarang[$idTr]['items'])) {
                $itemsTr   = $mapDetailBarang[$idTr]['items'];
                $jmlBarang = (int)$mapDetailBarang[$idTr]['total_item'];
            } elseif (!empty($row['rincian_barang'])) {
                $pecah = explode(',', (string)$row['rincian_barang']);
                $totalQtyDariTeks = 0;

                foreach ($pecah as $bagian) {
                    $bagianTrim = trim($bagian);
                    if ($bagianTrim !== '') {
                        if (preg_match('/^(.*)\s+x(\d+)$/i', $bagianTrim, $match)) {
                            $namaParsed = trim($match[1]);
                            $qtyParsed  = (int)$match[2];
                            $totalQtyDariTeks += $qtyParsed;
                            $itemsTr[] = [
                                'kode'      => '',
                                'nama'      => $namaParsed,
                                'qty'       => $qtyParsed,
                                'harga'     => 0,
                                'subtotal'  => 0,
                                'teks_saja' => false,
                            ];
                        } else {
                            $itemsTr[] = [
                                'kode'      => '',
                                'nama'      => $bagianTrim,
                                'qty'       => 0,
                                'harga'     => 0,
                                'subtotal'  => 0,
                                'teks_saja' => true,
                            ];
                        }
                    }
                }

                if (isset($row['jumlah_barang']) && (int)$row['jumlah_barang'] > 0) {
                    $jmlBarang = (int)$row['jumlah_barang'];
                } elseif ($totalQtyDariTeks > 0) {
                    $jmlBarang = $totalQtyDariTeks;
                } else {
                    $jmlBarang = count($itemsTr);
                }
            } elseif (isset($row['jumlah_barang']) && (int)$row['jumlah_barang'] > 0) {
                $jmlBarang = (int)$row['jumlah_barang'];
            }

            $row['jml_barang'] = $jmlBarang;
            $row['items_detail'] = $itemsTr;
            $row['kategori_barang'] = ($jmlBarang <= 2) ? '1-2 Barang' : 'Beberapa Jumlah';
            $semuaTransaksiTanggal[] = $row;
        }
    }
}

$countSemua = count($semuaTransaksiTanggal);
$countSedikit = 0;
$countBanyak = 0;
foreach ($semuaTransaksiTanggal as $item) {
    if ($item['jml_barang'] <= 2) {
        $countSedikit++;
    } else {
        $countBanyak++;
    }
}

$daftarHarian = [];
$totalPendapatanHarian = 0;
$totalBayarHarian = 0;
$totalKembalianHarian = 0;
$totalBarangHarian = 0;

foreach ($semuaTransaksiTanggal as $item) {
    $cocok = false;
    if ($filterJumlah === 'semua') {
        $cocok = true;
    } elseif ($filterJumlah === 'sedikit' && $item['jml_barang'] <= 2) {
        $cocok = true;
    } elseif ($filterJumlah === 'banyak' && $item['jml_barang'] > 2) {
        $cocok = true;
    }

    if ($cocok) {
        $daftarHarian[] = $item;
        $totalPendapatanHarian += (float)$item['total_harga'];
        $totalBayarHarian += (float)$item['uang_bayar'];
        $totalKembalianHarian += (float)$item['kembalian'];
        $totalBarangHarian += (int)$item['jml_barang'];
    }
}

$jumlahTransaksiHarian = count($daftarHarian);

$judulFilter = 'Semua Pembeli';
if ($filterJumlah === 'sedikit') {
    $judulFilter = 'Pembeli 1–2 Barang';
} elseif ($filterJumlah === 'banyak') {
    $judulFilter = 'Pembeli Beberapa Jumlah (3+ Barang)';
}

function urlRekapan($tgl, $tipe) {
    return 'laporan_harian.php?tanggal=' . urlencode($tgl) . '&tipe_barang=' . urlencode($tipe);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekapan Harian - KasirCermat</title>
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
            --border-outer: #94a3b8;
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
            margin-bottom: 18px;
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

        /* Kalender Pencarian Tanggal */
        .date-toolbar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .date-form {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .date-form label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
        }
        .date-input {
            padding: 8px 12px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 13.5px;
            font-family: inherit;
            color: var(--text);
            background: #fff;
        }
        .date-input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .btn-date {
            padding: 8px 14px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-date:hover { background: var(--primary-hover); }
        .btn-today {
            padding: 8px 12px;
            background: #f8fafc;
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
        }

        .filter-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }
        .tab-btn {
            padding: 9px 14px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tab-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .badge-count {
            font-size: 11.5px;
            padding: 2px 7px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.08);
        }
        .tab-btn.active .badge-count {
            background: rgba(255, 255, 255, 0.22);
            color: #fff;
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
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card-header { margin-bottom: 14px; }
        .card-header h2 { margin: 0; font-size: 17px; }

        .buyer-block {
            border: 2px solid var(--border-outer);
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 16px;
            background: #ffffff;
        }
        .buyer-block:last-child {
            margin-bottom: 0;
        }
        .buyer-code-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            padding-bottom: 10px;
        }
        .buyer-code {
            font-weight: 700;
            font-size: 14.5px;
            color: var(--text);
        }
        .buyer-meta {
            font-size: 12.5px;
            color: var(--muted);
        }

        .items-table-wrap {
            width: 100%;
            overflow-x: auto;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .items-table thead th {
            color: var(--muted);
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 6px 10px 8px;
        }
        .items-table tbody td {
            padding: 7px 10px;
            vertical-align: middle;
        }
        .items-table th.col-left,
        .items-table td.col-left {
            text-align: left;
        }
        .items-table th.col-center,
        .items-table td.col-center {
            text-align: center;
            white-space: nowrap;
            width: 110px;
        }
        .items-table th.col-right,
        .items-table td.col-right {
            text-align: right;
            white-space: nowrap;
            width: 165px;
        }

        .buyer-rekap-inline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            padding-top: 10px;
            margin-top: 4px;
            font-size: 13.5px;
        }
        .buyer-rekap-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
        }
        .buyer-figures {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .fig-box {
            min-width: 115px;
            text-align: right;
        }
        .fig-box span {
            display: block;
            font-size: 11.5px;
            color: var(--muted);
        }
        .fig-box strong {
            font-size: 14px;
        }

        .rekap-footer-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: #f8fafc;
            border-top: 2px solid #cbd5e1;
            border-radius: 10px;
            padding: 13px 16px;
            margin-top: 16px;
            font-weight: 700;
            font-size: 14px;
        }
        .rekap-footer-totals {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .total-highlight { color: var(--success); font-size: 15px; }

        @media (max-width: 960px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; flex-direction: row; align-items: center; padding: 12px 16px; }
            .nav-menu, .sidebar-footer .user-info { display: none; }
            .sidebar-footer { border-top: none; padding-top: 0; }
        }
        @media (max-width: 768px) {
            .main { padding: 16px; }
            .stats-grid { grid-template-columns: 1fr; gap: 12px; }
            .buyer-block { padding: 13px; }
            .items-table th.col-right,
            .items-table td.col-right {
                width: auto;
            }
            .items-table th.col-center,
            .items-table td.col-center {
                width: auto;
            }
            .buyer-rekap-inline { flex-direction: column; align-items: stretch; }
            .buyer-figures { width: 100%; justify-content: space-between; gap: 10px; }
            .fig-box { min-width: 0; text-align: left; }
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
            <h1>Rekapan Harian (<?= htmlspecialchars($labelTanggal) ?>)</h1>
            <p>Daftar barang tersusun ke bawah beserta harga di sampingnya, menyatu dengan rekapan per pembeli.</p>
        </div>
        <div class="top-actions">
            <a href="rekapan_laporan.php" class="btn-back">&larr; Kembali ke Rekapan Laporan</a>
            <a href="dashboard.php" class="btn-back">Dashboard</a>
        </div>
    </div>

    <div class="date-toolbar">
        <form method="GET" action="laporan_harian.php" class="date-form">
            <label for="tglRekapan">Pilih / Cari Tanggal:</label>
            <input type="date" id="tglRekapan" name="tanggal" class="date-input" value="<?= htmlspecialchars($tanggalDipilih) ?>">
            <input type="hidden" name="tipe_barang" value="<?= htmlspecialchars($filterJumlah) ?>">
            <button type="submit" class="btn-date">Cari Tanggal</button>
            <?php if ($tanggalDipilih !== $tanggalHariIni): ?>
                <a href="<?= htmlspecialchars(urlRekapan($tanggalHariIni, $filterJumlah)) ?>" class="btn-today">Kembali ke Hari Ini</a>
            <?php endif; ?>
        </form>
        <div class="buyer-meta">
            Menampilkan tanggal: <strong><?= htmlspecialchars($tanggalDipilih) ?></strong>
        </div>
    </div>

    <div class="filter-tabs">
        <a href="<?= htmlspecialchars(urlRekapan($tanggalDipilih, 'semua')) ?>" class="tab-btn <?= $filterJumlah === 'semua' ? 'active' : '' ?>">
            Semua <span class="badge-count"><?= $countSemua ?></span>
        </a>
        <a href="<?= htmlspecialchars(urlRekapan($tanggalDipilih, 'sedikit')) ?>" class="tab-btn <?= $filterJumlah === 'sedikit' ? 'active' : '' ?>">
            1–2 Barang <span class="badge-count"><?= $countSedikit ?></span>
        </a>
        <a href="<?= htmlspecialchars(urlRekapan($tanggalDipilih, 'banyak')) ?>" class="tab-btn <?= $filterJumlah === 'banyak' ? 'active' : '' ?>">
            Beberapa Jumlah (3+) <span class="badge-count"><?= $countBanyak ?></span>
        </a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Pendapatan (<?= htmlspecialchars($judulFilter) ?>)</div>
            <div class="stat-value">Rp <?= number_format($totalPendapatanHarian, 0, ',', '.') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Jumlah Transaksi</div>
            <div class="stat-value"><?= $jumlahTransaksiHarian ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Daftar Transaksi - Rekapan Harian (<?= htmlspecialchars($judulFilter) ?>)</h2>
        </div>

        <?php if (!empty($daftarHarian)): ?>
            <?php foreach ($daftarHarian as $tr): ?>
                <div class="buyer-block">
                    <div class="buyer-code-bar">
                        <div class="buyer-code"><?= htmlspecialchars($tr['kode_transaksi']) ?></div>
                        <div class="buyer-meta">
                            <?= htmlspecialchars($tr['tanggal']) ?> &bull; Kasir: <?= htmlspecialchars($tr['nama_kasir'] ?? ('ID #' . $tr['id_kasir'])) ?>
                        </div>
                    </div>

                    <div class="items-table-wrap">
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th class="col-left">Barang yang Dibeli</th>
                                    <th class="col-center">Qty</th>
                                    <th class="col-right">Harga Satuan</th>
                                    <th class="col-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($tr['items_detail'])): ?>
                                <?php foreach ($tr['items_detail'] as $dtItem): ?>
                                    <tr>
                                        <td class="col-left"><strong><?= htmlspecialchars($dtItem['nama']) ?></strong></td>
                                        <td class="col-center">
                                            <?= !empty($dtItem['teks_saja']) ? '-' : ((int)$dtItem['qty'] . 'x') ?>
                                        </td>
                                        <td class="col-right">
                                            <?= ((float)$dtItem['harga'] > 0) ? ('Rp ' . number_format((float)$dtItem['harga'], 0, ',', '.')) : '-' ?>
                                        </td>
                                        <td class="col-right">
                                            <strong><?= ((float)$dtItem['subtotal'] > 0) ? ('Rp ' . number_format((float)$dtItem['subtotal'], 0, ',', '.')) : '-' ?></strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="buyer-meta" style="text-align:center;">
                                        Rincian barang tidak tercatat di detail_transaksi
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="buyer-rekap-inline">
                        <div class="buyer-rekap-label">Rekapan Pembeli (<?= htmlspecialchars($tr['kode_transaksi']) ?>)</div>
                        <div class="buyer-figures">
                            <div class="fig-box">
                                <span>Jumlah Barang</span>
                                <strong><?= (int)$tr['jml_barang'] ?> Barang</strong>
                            </div>
                            <div class="fig-box">
                                <span>Total</span>
                                <strong>Rp <?= number_format((float)$tr['total_harga'], 0, ',', '.') ?></strong>
                            </div>
                            <div class="fig-box">
                                <span>Bayar</span>
                                <strong>Rp <?= number_format((float)$tr['uang_bayar'], 0, ',', '.') ?></strong>
                            </div>
                            <div class="fig-box">
                                <span>Kembalian</span>
                                <strong style="color:var(--success);">Rp <?= number_format((float)$tr['kembalian'], 0, ',', '.') ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="rekap-footer-bar">
                <div>Total Keseluruhan (<?= $jumlahTransaksiHarian ?> Transaksi &bull; <?= $totalBarangHarian ?> Barang)</div>
                <div class="rekap-footer-totals">
                    <span class="total-highlight">Total: Rp <?= number_format($totalPendapatanHarian, 0, ',', '.') ?></span>
                    <span>Bayar: Rp <?= number_format($totalBayarHarian, 0, ',', '.') ?></span>
                    <span>Kembalian: Rp <?= number_format($totalKembalianHarian, 0, ',', '.') ?></span>
                </div>
            </div>
        <?php else: ?>
            <p style="text-align:center; color:var(--muted); padding:20px; margin:0;">
                Belum ada transaksi pada kategori ini untuk tanggal <?= htmlspecialchars($labelTanggal) ?>.
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Total Keseluruhan - Rekapan Harian (<?= htmlspecialchars($labelTanggal) ?>)</h2>
        </div>
        <div class="stats-grid" style="margin-bottom: 0;">
            <div class="stat-card">
                <div class="stat-label">Total Transaksi</div>
                <div class="stat-value"><?= $jumlahTransaksiHarian ?> Transaksi</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Uang Bayar</div>
                <div class="stat-value">Rp <?= number_format($totalBayarHarian, 0, ',', '.') ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Kembalian</div>
                <div class="stat-value">Rp <?= number_format($totalKembalianHarian, 0, ',', '.') ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Pendapatan</div>
                <div class="stat-value">Rp <?= number_format($totalPendapatanHarian, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
</main>
</body>
</html>