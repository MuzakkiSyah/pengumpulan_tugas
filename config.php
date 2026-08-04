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

// Fungsi bantu untuk mengecek login
function check_login($required_role = null) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
    
    if ($required_role && $_SESSION['role'] !== $required_role) {
        // Alihkan sesuai dengan role yang sebenarnya
        if ($_SESSION['role'] === 'laboran') {
            header("Location: laboran.php");
        } else {
            header("Location: mahasiswa.php");
        }
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
