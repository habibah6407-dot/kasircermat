<?php
session_start();
require_once 'classes/Database.php';
require_once 'classes/User.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $db = new Database();
    $userObj = new User();

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $loginUser = $userObj->login($username, $password);
    if ($loginUser) {
        $_SESSION['user_id'] = $loginUser['id'];
        $_SESSION['username'] = $loginUser['username'];
        $_SESSION['nama'] = $loginUser['nama'] ?? $loginUser['username'];
        $_SESSION['role'] = $loginUser['role'];

        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Username atau password tidak sesuai. Silakan coba lagi.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk - KasirCermat POS</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --bg-grad: linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #2563eb 100%);
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --danger-bg: #fef2f2;
            --danger-text: #b91c1c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-grad);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-main);
        }
        .auth-wrapper {
            width: 100%;
            max-width: 860px;
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            display: grid;
            grid-template-columns: 1.05fr 1fr;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
        }
        .brand-panel {
            background: linear-gradient(160deg, #1e293b 0%, #0f172a 100%);
            color: #f8fafc;
            padding: 40px 34px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(59, 130, 246, 0.18);
            border: 1px solid rgba(96, 165, 250, 0.35);
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            color: #93c5fd;
            width: fit-content;
        }
        .brand-panel h1 {
            margin: 22px 0 12px;
            font-size: 28px;
            line-height: 1.25;
        }
        .brand-panel p {
            margin: 0;
            color: #cbd5e1;
            font-size: 14px;
            line-height: 1.6;
        }
        .feature-list {
            list-style: none;
            padding: 0;
            margin: 24px 0 0;
            display: grid;
            gap: 10px;
            font-size: 13.5px;
            color: #e2e8f0;
        }
        .feature-list li::before {
            content: "✓ ";
            color: #4ade80;
            font-weight: bold;
            margin-right: 6px;
        }
        .demo-box {
            margin-top: 28px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 12.5px;
            color: #cbd5e1;
            line-height: 1.5;
        }
        .form-panel {
            padding: 40px 36px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .form-panel h2 {
            margin: 0 0 6px;
            font-size: 24px;
        }
        .subtitle {
            margin: 0 0 24px;
            color: var(--text-muted);
            font-size: 14px;
        }
        .alert-error {
            background: var(--danger-bg);
            color: var(--danger-text);
            border: 1px solid #fecaca;
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 13.5px;
            margin-bottom: 18px;
        }
        .field {
            margin-bottom: 16px;
        }
        .field label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 7px;
            color: #334155;
        }
        .input-wrap {
            position: relative;
        }
        .input-wrap input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .input-wrap input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .toggle-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            padding: 4px 6px;
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 6px;
            transition: background 0.2s, transform 0.1s;
        }
        .btn-submit:hover { background: var(--primary-dark); }
        .btn-submit:active { transform: scale(0.99); }

        @media (max-width: 760px) {
            .auth-wrapper { grid-template-columns: 1fr; }
            .brand-panel { padding: 28px 24px; }
            .form-panel { padding: 28px 24px; }
        }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <div class="brand-panel">
        <div>
            <div class="brand-badge">KasirCermat POS</div>
            <h1>Kelola Kasir &amp; Stok Lebih Cepat dan Rapi</h1>
            <p>Sistem manajemen kasir berbasis web dengan pemisahan hak akses Admin dan Kasir yang terstruktur.</p>
            <ul class="feature-list">
                <li>Hitung total belanja dan kembalian otomatis</li>
                <li>Pantau stok produk secara real-time</li>
                <li>Hak akses terpisah antara Admin &amp; Kasir</li>
            </ul>
        </div>
        <div class="demo-box">
            <strong>Akun Uji Coba:</strong><br>
            Admin: <code>admin</code> / <code>12345</code><br>
            Kasir: <code>kasir</code> / <code>12345</code>
        </div>
    </div>

    <div class="form-panel">
        <h2>Selamat Datang</h2>
        <p class="subtitle">Masuk untuk mulai melayani transaksi</p>

        <?php if ($error !== ""): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="field">
                <label for="username">Username</label>
                <div class="input-wrap">
                    <input type="text" id="username" name="username" placeholder="Masukkan username" required autofocus>
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <input type="password" id="password" name="password" placeholder="Masukkan password" required>
                    <button type="button" class="toggle-btn" onclick="togglePass()">Lihat</button>
                </div>
            </div>

            <button type="submit" class="btn-submit">Masuk ke Dashboard</button>
        </form>
    </div>
</div>

<script>
function togglePass() {
    const input = document.getElementById('password');
    const btn = document.querySelector('.toggle-btn');
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = 'Sembunyi';
    } else {
        input.type = 'password';
        btn.textContent = 'Lihat';
    }
}
</script>
</body>
</html>