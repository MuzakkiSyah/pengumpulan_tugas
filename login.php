<?php
require_once 'config.php';

// Jika sudah login, alihkan ke dashboard masing-masing
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'laboran') {
        header("Location: laboran.php");
    } else {
        header("Location: mahasiswa.php");
    }
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
                // Set Session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                $_SESSION['nomor_induk'] = $user['nomor_induk'];
                $_SESSION['role'] = $user['role'];
                
                // Alihkan ke halaman yang sesuai
                if ($user['role'] === 'laboran') {
                    header("Location: laboran.php");
                } else {
                    header("Location: mahasiswa.php");
                }
                exit();
            } else {
                $error = 'Username atau Password salah!';
            }
        } catch (PDOException $e) {
            $error = 'Terjadi kesalahan sistem: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistem Pengumpulan Tugas</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="auth-wrapper">
        <div class="glass-panel auth-card">
            <div class="auth-header">
                <div class="auth-logo">KumpulTugas</div>
                <div class="auth-subtitle">Silakan login untuk mengakses akun Anda</div>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <span class="btn-icon">⚠️</span>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>
            
            <form action="login.php" method="POST">
                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" id="username" name="username" class="form-input" placeholder="Masukkan username Anda" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-input" placeholder="Masukkan password Anda" required>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                    Masuk
                </button>
            </form>
            
            <div style="margin-top: 1.5rem; text-align: center; font-size: 0.9rem; color: var(--text-muted);">
                Belum punya akun? <a href="register.php">Daftar Sekarang</a>
            </div>
        </div>
    </div>
</body>
</html>
