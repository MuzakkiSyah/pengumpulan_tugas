<?php
/**
 * SETUP.PHP — Jalankan SEKALI untuk membuat akun admin pertama.
 * HAPUS file ini setelah berhasil dijalankan!
 */
require_once 'config.php';

$done = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    try {
        // Cek apakah username 'admin' sudah ada
        $check = $pdo->prepare("SELECT id FROM users WHERE username = 'admin'");
        $check->execute();
        if ($check->fetch()) {
            $error = 'Akun admin sudah ada! File ini tidak perlu dijalankan lagi.';
        } else {
            $hash = password_hash('labrm2026', PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password, nama_lengkap, nomor_induk, role)
                VALUES ('admin', ?, 'Rizka Muzakki Syah', '000000000', 'laboran')
            ");
            $stmt->execute([$hash]);
            $done = true;
        }
    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Akun Admin - Sistem Informasi Pengumpulan Tugas Lab RM</title>
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="auth-wrapper">
    <div class="glass-panel auth-card">
        <div class="auth-header">
            <div class="auth-logo">⚙️ Setup Admin</div>
            <div class="auth-subtitle">Inisialisasi akun laboran pertama</div>
        </div>

        <?php if ($done): ?>
            <div class="alert alert-success">
                ✅ Akun admin berhasil dibuat!
            </div>
            <div style="background:var(--accent-primary-light); border:1.5px solid var(--color-info-border); border-radius:10px; padding:1.25rem; margin-bottom:1.5rem;">
                <p style="font-weight:700; color:var(--accent-primary); margin-bottom:.75rem;">Detail Akun Admin:</p>
                <table style="width:100%; font-size:.93rem; border-collapse:collapse;">
                    <tr><td style="padding:.3rem .5rem; color:var(--text-muted); width:120px;">Nama</td><td style="color:var(--text-main); font-weight:500;">Rizka Muzakki Syah</td></tr>
                    <tr><td style="padding:.3rem .5rem; color:var(--text-muted);">Username</td><td style="color:var(--text-main); font-weight:500;">admin</td></tr>
                    <tr><td style="padding:.3rem .5rem; color:var(--text-muted);">Password</td><td style="color:var(--text-main); font-weight:500;">labrm2026</td></tr>
                    <tr><td style="padding:.3rem .5rem; color:var(--text-muted);">Role</td><td style="color:var(--text-main); font-weight:500;">Laboran (Admin)</td></tr>
                </table>
            </div>
            <div style="background:var(--color-error-bg); border:1.5px solid var(--color-error-border); border-radius:8px; padding:1rem; margin-bottom:1.5rem; font-size:.88rem; color:var(--color-error);">
                ⚠️ <strong>Penting:</strong> Hapus file <code>setup.php</code> dari server setelah ini untuk keamanan!
            </div>
            <a href="login.php" class="btn btn-primary" style="width:100%;">🔐 Lanjut ke Halaman Login</a>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error">⚠️ <?= $error ?></div>
            <?php endif; ?>
            <div style="background:var(--color-warning-bg); border:1.5px solid var(--color-warning-border); border-radius:10px; padding:1.1rem; margin-bottom:1.5rem; font-size:.9rem; color:var(--color-warning);">
                ℹ️ Script ini akan membuat akun <strong>Admin/Laboran</strong> pertama dengan detail berikut:
            </div>
            <div style="background:var(--bg-input); border-radius:10px; padding:1.25rem; margin-bottom:1.5rem;">
                <table style="width:100%; font-size:.93rem; border-collapse:collapse;">
                    <tr><td style="padding:.35rem .5rem; color:var(--text-muted); width:120px;">Nama</td><td style="color:var(--text-main); font-weight:600;">Rizka Muzakki Syah</td></tr>
                    <tr><td style="padding:.35rem .5rem; color:var(--text-muted);">Username</td><td style="color:var(--text-main); font-weight:600;">admin</td></tr>
                    <tr><td style="padding:.35rem .5rem; color:var(--text-muted);">Password</td><td style="color:var(--text-main); font-weight:600;">labrm2026</td></tr>
                    <tr><td style="padding:.35rem .5rem; color:var(--text-muted);">Role</td><td style="color:var(--text-main); font-weight:600;">Laboran (Admin)</td></tr>
                </table>
            </div>
            <form method="POST">
                <button type="submit" name="create" value="1" class="btn btn-primary" style="width:100%;">
                    ✅ Buat Akun Admin Sekarang
                </button>
            </form>
            <div style="margin-top:1rem; text-align:center;">
                <a href="login.php" style="font-size:.88rem; color:var(--text-muted);">Kembali ke Login</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
