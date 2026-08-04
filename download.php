<?php
require_once 'config.php';
check_login(); // Hanya perlu login, kedua role bisa download

$pdo_error = '';

// Download berdasarkan ID submission
if (isset($_GET['sub_id'])) {
    $sub_id = (int)$_GET['sub_id'];
    
    // Jika mahasiswa, hanya bisa download miliknya sendiri
    if ($_SESSION['role'] === 'mahasiswa') {
        $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = ? AND id_mahasiswa = ?");
        $stmt->execute([$sub_id, $_SESSION['user_id']]);
    } else {
        // Laboran bisa download semua
        $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
        $stmt->execute([$sub_id]);
    }
    
    $sub = $stmt->fetch();
    
    if (!$sub) {
        die("File tidak ditemukan atau Anda tidak memiliki akses.");
    }
    
    $file_path = $sub['path_file'];
    
    if (!file_exists($file_path)) {
        die("File tidak ada di server.");
    }
    
    // Kirim file sebagai download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($sub['nama_file']) . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($file_path);
    exit();
}

// Jika tidak ada parameter valid
header("Location: index.php");
exit();
?>
