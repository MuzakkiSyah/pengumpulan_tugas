<?php
// Memulai session jika belum aktif
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Konfigurasi Database
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_pengumpulan_tugas');

try {
    // Membuat koneksi database menggunakan PDO
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    // Mengatur mode error PDO ke Exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Mengatur fetch mode default ke objek/asosiatif
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

// Dynamic database migration: add status column to submissions table if not exists
try {
    $pdo->query("SELECT status FROM submissions LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN status VARCHAR(20) DEFAULT 'dikumpul' AFTER catatan_nilai");
    } catch (PDOException $ex) {
        // Ignore errors
    }
}

// Dynamic database migration: create submission_feedback table if not exists
try {
    $pdo->query("SELECT 1 FROM submission_feedback LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `submission_feedback` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `id_submission` INT NOT NULL,
              `id_laboran` INT NOT NULL,
              `nilai` TINYINT UNSIGNED DEFAULT NULL,
              `catatan_nilai` TEXT DEFAULT NULL,
              `status` VARCHAR(20) DEFAULT 'dikumpul',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`id_submission`) REFERENCES `submissions`(`id`) ON DELETE CASCADE,
              FOREIGN KEY (`id_laboran`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (PDOException $ex) {
        // Ignore errors
    }
}

// Dynamic database migration: add semester column to users table if not exists
try {
    $pdo->query("SELECT semester FROM users LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN semester TINYINT DEFAULT 1 AFTER role");
    } catch (PDOException $ex) {
        // Ignore errors
    }
}

// Dynamic database migration: add semester column to mata_kuliah table if not exists
try {
    $pdo->query("SELECT semester FROM mata_kuliah LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE mata_kuliah ADD COLUMN semester TINYINT DEFAULT 1 AFTER nama_matkul");
    } catch (PDOException $ex) {
        // Ignore errors
    }
}


// Fungsi bantu untuk mengecek login
function check_login($required_role = null) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
    
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            session_destroy();
            header("Location: login.php");
            exit();
        }
        
        if ($required_role && $user['role'] !== $required_role) {
            if ($user['role'] === 'laboran') {
                header("Location: laboran.php");
            } else {
                header("Location: mahasiswa.php");
            }
            exit();
        }
    } catch (PDOException $e) {
        session_destroy();
        header("Location: login.php");
        exit();
    }
}

// Fungsi untuk membersihkan input data
function clean_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

// Fungsi format tanggal lokal Indonesia
function format_tanggal($datetime) {
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    
    $time = strtotime($datetime);
    $day_name = $days[date('w', $time)];
    $day = date('j', $time);
    $month_name = $months[date('n', $time)];
    $year = date('Y', $time);
    $hour_minute = date('H:i', $time);
    
    return "$day_name, $day $month_name $year - Pukul $hour_minute WIB";
}
?>
