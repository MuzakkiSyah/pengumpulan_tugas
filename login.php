<?php
require_once 'config.php';

// Jika sudah login, alihkan ke dashboard masing-masing
if (isset($_SESSION['user_id'])) {
    header("Location: " . ($_SESSION['role'] === 'laboran' ? 'laboran.php' : 'mahasiswa.php'));
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean_input($_POST['username']);
    $password = clean_input($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = 'Username dan Password harus diisi!';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id']      = $user['id'];
                $_SESSION['username']     = $user['username'];
                $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                $_SESSION['nomor_induk']  = $user['nomor_induk'];
                $_SESSION['role']         = $user['role'];
                $_SESSION['jabatan']      = $user['jabatan'] ?? null;

                header("Location: " . ($user['role'] === 'laboran' ? 'laboran.php' : 'mahasiswa.php'));
                exit();
            } else {
                $error = 'Username atau Password salah!';
            }
        } catch (PDOException $e) {
            $error = 'Terjadi kesalahan sistem.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistem Informasi Pengumpulan Tugas Lab RM</title>
    <link rel="icon" type="image/png" href="https://raw.githubusercontent.com/MuzakkiSyah/laboratoriumrm/47ccb8aadc7a14211df38be5f26f4e45f75a0f20/LOGO%20LAB-06%20-%20Copy.png">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .login-card-hero {
            background: var(--grad-hero);
            border-radius: 16px 16px 0 0;
            padding: 2.5rem 2.5rem 2rem;
            text-align: center;
            margin: -2.5rem -2.5rem 2rem -2.5rem;
        }
        .login-card-hero h1 {
            font-weight: 800;
            color: #FFFFFF;
            margin-bottom: .25rem;
        }
        .login-card-hero p { color: rgba(255,255,255,.7); font-size: .9rem; }
        .login-card-hero .hero-logo { margin-bottom: .5rem; }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <!-- Hero Header -->
        <div class="login-card-hero">
            <div class="hero-logo">
                <img src="https://raw.githubusercontent.com/MuzakkiSyah/laboratoriumrm/47ccb8aadc7a14211df38be5f26f4e45f75a0f20/LOGO%20LAB-06%20-%20Copy.png" alt="Sistem Informasi Pengumpulan Tugas Lab RM Logo" style="height: 60px; object-fit: contain;">
            </div>
            <h1 style="font-size: 1.6rem; margin-top: 0.5rem; line-height: 1.3;">Sistem Informasi Pengumpulan Tugas Lab RM</h1>
            <p>Laboratorium Rekam Medis Udinus</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">⚠️ <?= $error ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <input type="text" id="username" name="username" class="form-input"
                    placeholder="Masukkan username Anda" required autofocus
                    value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
            </div>
            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input type="password" id="password" name="password" class="form-input"
                    placeholder="Masukkan password Anda" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:.5rem;">
                🔐 Masuk
            </button>
        </form>

        <p style="margin-top:1.5rem; text-align:center; font-size:.82rem; color:var(--text-muted);">
            Belum punya akun? Hubungi <strong>Asisten Laboran</strong> untuk mendapatkan akun.
        </p>
    </div>
</div>
</body>
</html>
