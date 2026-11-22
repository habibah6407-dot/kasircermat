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
$notifKasir = "";
$errorKasir = "";

$tabAdmin = $_GET['tab_admin'] ?? 'laporan';
if (!in_array($tabAdmin, ['laporan', 'kasir'])) {
    $tabAdmin = 'laporan';
}

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

// Proses tambah akun kasir (khusus Admin)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['tambah_kasir'])) {
    $tabAdmin = 'kasir';
    if ($roleUser !== 'admin') {
        $errorKasir = "Hanya Admin yang dapat menambah akun kasir.";
    } else {
        $namaKasir = trim($_POST['nama_kasir'] ?? '');
        $usernameKasir = trim($_POST['username_kasir'] ?? '');
        $passwordKasir = $_POST['password_kasir'] ?? '';
        $roleKasir = strtolower(trim($_POST['role_kasir'] ?? 'kasir'));
        if (!in_array($roleKasir, ['kasir', 'admin'])) {
            $roleKasir = 'kasir';
        }

        if ($namaKasir === '' || $usernameKasir === '' || $passwordKasir === '') {
            $errorKasir = "Lengkapi nama, username, dan password.";
        } else {
            $cek = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $cek->bind_param("s", $usernameKasir);
            $cek->execute();
            $hasilCek = $cek->get_result();

            if ($hasilCek && $hasilCek->num_rows > 0) {
                $errorKasir = "Username \"$usernameKasir\" sudah digunakan.";
            } else {
                $passHash = md5($passwordKasir);
                $stmtKasir = $conn->prepare("INSERT INTO users (nama, username, password, role) VALUES (?, ?, ?, ?)");
                if ($stmtKasir) {
                    $stmtKasir->bind_param("ssss", $namaKasir, $usernameKasir, $passHash, $roleKasir);
                    if ($stmtKasir->execute()) {
                        $notifKasir = "Akun \"$namaKasir\" ($roleKasir) berhasil ditambahkan.";
                    } else {
                        $errorKasir = "Gagal menambah akun pengguna.";
                    }
                }
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
        $totalQtyBelanja = 0;
        $rincianTeksArr = [];

        foreach ($items as $item) {
            $hargaItem = (float)($item['harga'] ?? 0);
            $qtyItem = (int)($item['qty'] ?? 0);
            $namaItem = trim((string)($item['nama'] ?? ''));
            if ($hargaItem > 0 && $qtyItem > 0) {
                $totalBelanja += ($hargaItem * $qtyItem);
                $totalQtyBelanja += $qtyItem;
                if ($namaItem !== '') {
                    $rincianTeksArr[] = $namaItem . ' x' . $qtyItem;
                }
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
                $rincianTeks = implode(', ', $rincianTeksArr);

                $stmtTrx = $conn->prepare("INSERT INTO transaksi (kode_transaksi, id_kasir, jumlah_barang, rincian_barang, total_harga, uang_bayar, kembalian, tanggal) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                if (!$stmtTrx) {
                    $stmtTrx = $conn->prepare("INSERT INTO transaksi (kode_transaksi, id_kasir, total_harga, uang_bayar, kembalian, tanggal) VALUES (?, ?, ?, ?, ?, NOW())");
                    if (!$stmtTrx) {
                        throw new Exception("Gagal menyiapkan query transaksi.");
                    }
                    $stmtTrx->bind_param("siddd", $kodeTransaksi, $userId, $totalBelanja, $uangBayar, $kembalian);
                } else {
                    $stmtTrx->bind_param("siisddd", $kodeTransaksi, $userId, $totalQtyBelanja, $rincianTeks, $totalBelanja, $uangBayar, $kembalian);
                }

                if (!$stmtTrx->execute()) {
                    throw new Exception("Gagal menyimpan data transaksi.");
                }

                $idTransaksiBaru = (int)$conn->insert_id;

                $stmtCariProduk = $conn->prepare("SELECT id, harga FROM produk WHERE nama_produk = ? LIMIT 1");
                $stmtDetail = $conn->prepare("INSERT INTO detail_transaksi (id_transaksi, id_produk, jumlah, subtotal) VALUES (?, ?, ?, ?)");
                $stmtStok = $conn->prepare("UPDATE produk SET stok = GREATEST(0, stok - ?) WHERE nama_produk = ?");

                foreach ($items as $item) {
                    $namaItem = trim((string)($item['nama'] ?? ''));
                    $qtyItem = (int)($item['qty'] ?? 0);
                    $hargaItem = (float)($item['harga'] ?? 0);

                    if ($namaItem !== '' && $qtyItem > 0) {
                        $idProdukDb = 0;
                        $hargaDb = $hargaItem;

                        if ($stmtCariProduk) {
                            $stmtCariProduk->bind_param("s", $namaItem);
                            $stmtCariProduk->execute();
                            $resProd = $stmtCariProduk->get_result();
                            if ($resProd && $rowProd = $resProd->fetch_assoc()) {
                                $idProdukDb = (int)$rowProd['id'];
                                if ((float)$rowProd['harga'] > 0) {
                                    $hargaDb = (float)$rowProd['harga'];
                                }
                            }
                        }

                        $subtotalItem = $hargaDb * $qtyItem;
                        if ($stmtDetail && $idProdukDb > 0 && $idTransaksiBaru > 0) {
                            $stmtDetail->bind_param("iiid", $idTransaksiBaru, $idProdukDb, $qtyItem, $subtotalItem);
                            if (!$stmtDetail->execute()) {
                                throw new Exception("Gagal menyimpan detail transaksi.");
                            }
                        }

                        if ($stmtStok) {
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

// Data Kasir & Laporan Transaksi untuk Admin
$daftarKasir = [];
$riwayatTransaksi = [];
if ($roleUser === 'admin') {
    $qKasir = $conn->query("SELECT id, nama, username, role FROM users ORDER BY id ASC");
    if ($qKasir) {
        while ($rk = $qKasir->fetch_assoc()) {
            $daftarKasir[] = $rk;
        }
    }

    $qLaporan = $conn->query("SELECT t.*, u.nama AS nama_kasir FROM transaksi t LEFT JOIN users u ON t.id_kasir = u.id ORDER BY t.tanggal DESC");
    if ($qLaporan) {
        while ($rl = $qLaporan->fetch_assoc()) {
            $riwayatTransaksi[] = $rl;
        }
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
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            width: 245px;
            background: var(--sidebar);
            color: #e2e8f0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 22px 16px;
            flex-shrink: 0;
            overflow: hidden;
            white-space: nowrap;
            transition: width 0.25s ease, padding 0.25s ease;
        }
        /* Saat disembunyikan, garis biru tua benar-benar hilang (lebar 0) */
        .sidebar.tersembunyi {
            width: 0 !important;
            min-width: 0 !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            padding-top: 22px !important;
            padding-bottom: 22px !important;
            border: none !important;
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
            gap: 8px;
        }
        .logo-left {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }
        .role-pill {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 999px;
            background: rgba(59, 130, 246, 0.25);
            color: #93c5fd;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
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
        .nav-menu a.active {
            border-left: 3px solid #3b82f6;
        }
        .sidebar-footer {
            border-top: 1px solid rgba(255,255,255,0.08);
            padding-top: 16px;
        }
        .user-info {
            font-size: 13px;
            margin-bottom: 10px;
            padding: 0 6px;
        }
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

        /* Tombol Garis Tiga di Area Utama agar selalu bisa diklik saat sidebar tertutup */
        .toggle-sidebar-btn {
            background: var(--sidebar);
            color: #fff;
            border: 1px solid rgba(15, 23, 42, 0.2);
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 16px;
            cursor: pointer;
            line-height: 1;
            flex-shrink: 0;
        }
        .toggle-sidebar-btn:hover {
            background: var(--sidebar-hover);
        }

        /* Main Area */
        .main {
            flex: 1;
            min-width: 0;
            padding: 24px 28px;
            overflow-x: auto;
            transition: width 0.25s ease;
        }
        .topbar {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 22px;
        }
        .topbar h1 { margin: 0; font-size: 22px; }
        .topbar p { margin: 3px 0 0; color: var(--muted); font-size: 13.5px; }

        /* Stats */
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

        /* Form Tambah Barang & Kasir */
        .form-produk-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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

        /* POS Grid */
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
            min-width: 0;
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
        .search-input {
            padding: 8px 12px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 13.5px;
            width: 210px;
        }
        .search-input:focus { outline: none; border-color: var(--primary); }

        .tab-group { display: flex; gap: 8px; flex-wrap: wrap; }
        .tab-btn {
            padding: 7px 14px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid var(--border);
            color: var(--text);
            background: #f8fafc;
        }
        .tab-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        th, td { padding: 11px 10px; border-bottom: 1px solid var(--border); text-align: left; }
        th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
        .nowrap { white-space: nowrap; }
        .badge-stok {
            background: #dcfce7;
            color: #166534;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-low {
            background: #fef3c7;
            color: #92400e;
        }
        .btn-add {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 6px 11px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-add:hover { background: var(--primary-hover); }

        /* Cart */
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

        /* Khusus layar HP saja (tidak mengubah tampilan laptop) */
        @media (max-width: 768px) {
            body {
                min-width: 0 !important;
                width: 100% !important;
                font-size: 11px;
            }
            .sidebar {
                width: 145px !important;
                min-width: 145px !important;
                padding: 10px 8px !important;
            }
            .logo {
                font-size: 12px !important;
                padding-bottom: 10px !important;
                gap: 4px !important;
            }
            .role-pill {
                font-size: 8.5px !important;
                padding: 2px 5px !important;
            }
            .nav-menu a {
                font-size: 10.5px !important;
                padding: 7px 6px !important;
            }
            .user-info, .logout-btn {
                font-size: 10px !important;
            }
            .main {
                min-width: 0 !important;
                width: 100% !important;
                padding: 12px 8px !important;
                overflow-x: hidden !important;
            }
            .topbar h1 {
                font-size: 15px !important;
            }
            .topbar p, .stat-label, .card-header h2, th, td, label, input, select, button, .tab-btn {
                font-size: 10px !important;
            }
            .stat-value {
                font-size: 13.5px !important;
            }
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
                gap: 6px !important;
            }
            .pos-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            .card {
                padding: 10px 8px !important;
            }
            .tab-btn {
                padding: 6px 10px !important;
            }
            /* Katalog Produk: tampil penuh tanpa geser horizontal di HP */
            #tabelProduk {
                width: 100% !important;
                min-width: 0 !important;
                table-layout: auto;
            }
            #tabelProduk th,
            #tabelProduk td {
                padding: 6px 3px !important;
            }
            #tabelProduk .btn-add {
                padding: 4px 6px !important;
                font-size: 9.5px !important;
            }
            #tabelProduk .badge-stok {
                padding: 2px 5px !important;
                font-size: 9.5px !important;
            }
            /* Tabel Laporan & Kasir tetap bisa digeser agar kolomnya tidak terpotong */
            #laporan .table-responsive table {
                min-width: 520px;
            }
            #laporan th,
            #laporan td {
                padding: 7px 6px !important;
            }
        }
    </style>
</head>
<body>
<aside class="sidebar" id="sidebar">
    <div>
        <div class="logo">
            <div class="logo-left">
                <span class="logo-title">KasirCermat</span>
            </div>
            <span class="role-pill"><?= htmlspecialchars($roleUser) ?></span>
        </div>
        <ul class="nav-menu">
            <li><a href="#kasir" class="active">Kasir &amp; Produk</a></li>
            <?php if ($roleUser === 'admin'): ?>
                <li><a href="#tambah-barang">Tambah Barang</a></li>
                <li><a href="#laporan">Laporan &amp; Akses Admin</a></li>
                <li><a href="rekapan_laporan.php">Rekapan Laporan</a></li>
            <?php endif; ?>
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
        <button type="button" onclick="toggleSidebar()" class="toggle-sidebar-btn" title="Sembunyikan / Tampilkan Menu">☰</button>
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
            <div>
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
                            <td class="nowrap">Rp <?= number_format($harga, 0, ',', '.') ?></td>
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

    <?php if ($roleUser === 'admin'): ?>
    <div class="card" id="laporan">
        <div class="card-header">
            <h2>Laporan &amp; Akses Admin</h2>
            <div class="tab-group">
                <a href="?tab_admin=laporan#laporan" class="tab-btn <?= $tabAdmin === 'laporan' ? 'active' : '' ?>">Laporan Transaksi</a>
                <a href="?tab_admin=kasir#laporan" class="tab-btn <?= $tabAdmin === 'kasir' ? 'active' : '' ?>">Data Kasir</a>
            </div>
        </div>

        <?php if ($tabAdmin === 'kasir'): ?>
            <?php if ($notifKasir !== ''): ?>
                <div class="alert-ok"><?= htmlspecialchars($notifKasir) ?></div>
            <?php endif; ?>
            <?php if ($errorKasir !== ''): ?>
                <div class="alert-err"><?= htmlspecialchars($errorKasir) ?></div>
            <?php endif; ?>

            <form method="POST" action="?tab_admin=kasir#laporan" style="margin-bottom: 18px;">
                <div class="form-produk-grid">
                    <div class="form-group">
                        <label for="nama_kasir">Nama Lengkap</label>
                        <input type="text" id="nama_kasir" name="nama_kasir" class="form-control" placeholder="Contoh: Kasir 2" required>
                    </div>
                    <div class="form-group">
                        <label for="username_kasir">Username</label>
                        <input type="text" id="username_kasir" name="username_kasir" class="form-control" placeholder="Contoh: kasir2" required>
                    </div>
                    <div class="form-group">
                        <label for="password_kasir">Password</label>
                        <input type="password" id="password_kasir" name="password_kasir" class="form-control" placeholder="12345" required>
                    </div>
                    <div class="form-group">
                        <label for="role_kasir">Role</label>
                        <select id="role_kasir" name="role_kasir" class="form-control">
                            <option value="kasir">kasir</option>
                            <option value="admin">admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="tambah_kasir" class="btn-save">+ Tambah Akun</button>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nama</th>
                            <th>Username</th>
                            <th>Role</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($daftarKasir)): ?>
                        <?php foreach ($daftarKasir as $k): ?>
                        <tr>
                            <td><?= (int)($k['id'] ?? 0) ?></td>
                            <td><strong><?= htmlspecialchars($k['nama'] ?? '') ?></strong></td>
                            <td><?= htmlspecialchars($k['username'] ?? '') ?></td>
                            <td><span class="badge-stok"><?= htmlspecialchars(strtoupper($k['role'] ?? 'kasir')) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center; color:var(--muted); padding:16px;">Belum ada data pengguna.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="table-responsive">
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
                    <?php if (!empty($riwayatTransaksi)): ?>
                        <?php foreach ($riwayatTransaksi as $tr): ?>
                        <tr>
                            <td class="nowrap"><strong><?= htmlspecialchars($tr['kode_transaksi'] ?? ('TRX-' . ($tr['id'] ?? ''))) ?></strong></td>
                            <td class="nowrap"><?= htmlspecialchars($tr['tanggal'] ?? '') ?></td>
                            <td><?= htmlspecialchars($tr['nama_kasir'] ?? ('ID #' . ($tr['id_kasir'] ?? ''))) ?></td>
                            <td class="nowrap">Rp <?= number_format((float)($tr['total_harga'] ?? 0), 0, ',', '.') ?></td>
                            <td class="nowrap">Rp <?= number_format((float)($tr['uang_bayar'] ?? 0), 0, ',', '.') ?></td>
                            <td class="nowrap">Rp <?= number_format((float)($tr['kembalian'] ?? 0), 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center; color:var(--muted); padding:16px;">Belum ada data transaksi.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>

<script>
let keranjang = [];

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('tersembunyi');
}

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