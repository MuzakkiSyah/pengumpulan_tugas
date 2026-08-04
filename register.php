<?php
require_once 'config.php';

// Jika sudah login, alihkan ke dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'laboran') {
        header("Location: laboran.php");
    } else {
        header("Location: mahasiswa.php");
    }
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean_input($_POST['username']);
    $password = clean_input($_POST['password']);
    $nama_lengkap = clean_input($_POST['nama_lengkap']);
    $nomor_induk = clean_input($_POST['nomor_induk']);
    $role = clean_input($_POST['role']);
    
    if (empty($username) || empty($password) || empty($nama_lengkap) || empty($nomor_induk) || empty($role)) {
        $error = 'Semua field wajib diisi!';
    } elseif (!in_array($role, ['laboran', 'mahasiswa'])) {
        $error = 'Role tidak valid!';
    } else {
        try {
            // Cek apakah username sudah terdaftar
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Username sudah digunakan!';
            } else {
                // Cek apakah NIM/NIP sudah terdaftar
                $stmt = $pdo->prepare("SELECT id FROM users WHERE nomor_induk = ?");
                $stmt->execute([$nomor_induk]);
                if ($stmt->fetch()) {
                    $error = 'Nomor Induk (NIM/NIP) sudah terdaftar!';
                } else {
                    // Hash password
                    $password_hashed = password_hash($password, PASSWORD_BCRYPT);
                    
                    // Simpan user baru
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nomor_induk, role) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $password_hashed, $nama_lengkap, $nomor_induk, $role]);
                    
                    $success = 'Registrasi berhasil! Silakan login.';
                }
            }
        } catch (PDOException $e) {
            $error = 'Terjadi kesalahan: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrasi Akun - Sistem Pengumpulan Tugas</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function updateLabel() {
            var roleSelect = document.getElementById("role");
            var nomorIndukLabel = document.getElementById("nomor-induk-label");
            var nomorIndukInput = document.getElementById("nomor_induk");
            
            if (roleSelect.value === "laboran") {
                nomorIndukLabel.textContent = "NIP (Nomor Induk Pegawai)";
                nomorIndukInput.placeholder = "Masukkan NIP Anda";
            } else {
                nomorIndukLabel.textContent = "NIM (Nomor Induk Mahasiswa)";
                nomorIndukInput.placeholder = "Masukkan NIM Anda";
            }
        }
    </script>
</head>
<body>
    <div class="auth-wrapper">
        <div class="glass-panel auth-card">
            <div class="auth-header">
                <div class="auth-logo">KumpulTugas</div>
                <div class="auth-subtitle">Daftar akun baru untuk mulai menggunakan sistem</div>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <span class="btn-icon">⚠️</span>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <span class="btn-icon">✅</span>
                    <span><?php echo $success; ?> <a href="login.php" style="color: inherit; text-decoration: underline; font-weight: bold;">Login di sini</a></span>
                </div>
            <?php endif; ?>
            
            <form action="register.php" method="POST">
                <div class="form-group">
                    <label for="nama_lengkap" class="form-label">Nama Lengkap</label>
                    <input type="text" id="nama_lengkap" name="nama_lengkap" class="form-input" placeholder="Masukkan nama lengkap Anda" required value="<?php echo isset($_POST['nama_lengkap']) ? htmlspecialchars($_POST['nama_lengkap']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="role" class="form-label">Pilih Role</label>
                    <select id="role" name="role" class="form-select" onchange="updateLabel()" required>
                        <option value="mahasiswa" <?php echo (isset($_POST['role']) && $_POST['role'] === 'mahasiswa') ? 'selected' : ''; ?>>Mahasiswa</option>
                        <option value="laboran" <?php echo (isset($_POST['role']) && $_POST['role'] === 'laboran') ? 'selected' : ''; ?>>Asisten Laboran / Laboran</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="nomor_induk" id="nomor-induk-label" class="form-label">NIM (Nomor Induk Mahasiswa)</label>
                    <input type="text" id="nomor_induk" name="nomor_induk" class="form-input" placeholder="Masukkan NIM Anda" required value="<?php echo isset($_POST['nomor_induk']) ? htmlspecialchars($_POST['nomor_induk']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" id="username" name="username" class="form-input" placeholder="Buat username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-input" placeholder="Buat password" required>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                    Daftar Akun
                </button>
            </form>
            
            <div style="margin-top: 1.5rem; text-align: center; font-size: 0.9rem; color: var(--text-muted);">
                Sudah punya akun? <a href="login.php">Login di sini</a>
            </div>
        </div>
    </div>
    <script>
        // Jalankan sekali saat page load untuk sinkronisasi label
        updateLabel();
    </script>
</body>
</html>
