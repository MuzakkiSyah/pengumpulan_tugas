<?php
require_once 'config.php';
check_login(); // Hanya perlu login, kedua role bisa download

$pdo_error = '';

// Bulk download (backup zip) untuk Laboran
if (isset($_GET['assignment_id']) || isset($_GET['matkul_id'])) {
    // Hanya laboran yang boleh download backup massal
    check_login('laboran');

    if (!class_exists('ZipArchive')) {
        die("<script>alert('Ekstensi ZipArchive tidak aktif pada server PHP Anda.'); window.history.back();</script>");
    }

    $zip = new ZipArchive();
    $temp_file = tempnam(sys_get_temp_dir(), 'zip_backup');
    if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        die("<script>alert('Gagal membuat file penyimpanan sementara ZIP.'); window.history.back();</script>");
    }

    if (isset($_GET['assignment_id'])) {
        $assignment_id = (int)$_GET['assignment_id'];
        
        // Ambil info tugas dan matkul
        $stmt = $pdo->prepare("
            SELECT a.*, mk.nama_matkul, mk.kode_matkul 
            FROM assignments a
            JOIN mata_kuliah mk ON a.id_matkul = mk.id
            WHERE a.id = ?
        ");
        $stmt->execute([$assignment_id]);
        $assignment = $stmt->fetch();
        if (!$assignment) {
            die("<script>alert('Tugas tidak ditemukan.'); window.history.back();</script>");
        }

        // Ambil semua submissions untuk tugas ini
        $stmt_subs = $pdo->prepare("
            SELECT sub.*, u.nama_lengkap, u.nomor_induk 
            FROM submissions sub
            JOIN users u ON sub.id_mahasiswa = u.id
            WHERE sub.id_assignment = ?
        ");
        $stmt_subs->execute([$assignment_id]);
        $submissions = $stmt_subs->fetchAll();

        if (empty($submissions)) {
            $zip->close();
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            die("<script>alert('Belum ada mahasiswa yang mengumpulkan tugas ini.'); window.history.back();</script>");
        }

        $added_files = 0;
        foreach ($submissions as $sub) {
            $file_path = $sub['path_file'];
            if (file_exists($file_path)) {
                $ext = pathinfo($sub['nama_file'], PATHINFO_EXTENSION);
                $safe_file_name = preg_replace('/[^a-zA-Z0-9_\-\s]/', '_', $sub['nomor_induk'] . '_' . $sub['nama_lengkap']) . '.' . $ext;
                $zip->addFile($file_path, $safe_file_name);
                $added_files++;
            }
        }
        
        if ($added_files === 0) {
            $zip->close();
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            die("<script>alert('File fisik tugas tidak ditemukan di server.'); window.history.back();</script>");
        }

        $zip_filename = preg_replace('/[^a-zA-Z0-9_\-\s]/', '_', $assignment['kode_matkul'] . '_' . $assignment['judul']) . '_Backup.zip';

    } else {
        $matkul_id = (int)$_GET['matkul_id'];
        
        // Ambil info matkul
        $stmt = $pdo->prepare("SELECT * FROM mata_kuliah WHERE id = ?");
        $stmt->execute([$matkul_id]);
        $matkul = $stmt->fetch();
        if (!$matkul) {
            die("<script>alert('Mata kuliah tidak ditemukan.'); window.history.back();</script>");
        }

        // Ambil semua tugas untuk matkul ini
        $stmt_assign = $pdo->prepare("SELECT * FROM assignments WHERE id_matkul = ?");
        $stmt_assign->execute([$matkul_id]);
        $assignments = $stmt_assign->fetchAll();

        $has_files = false;
        foreach ($assignments as $a) {
            $stmt_subs = $pdo->prepare("
                SELECT sub.*, u.nama_lengkap, u.nomor_induk 
                FROM submissions sub
                JOIN users u ON sub.id_mahasiswa = u.id
                WHERE sub.id_assignment = ?
            ");
            $stmt_subs->execute([$a['id']]);
            $submissions = $stmt_subs->fetchAll();
            
            $folder_name = preg_replace('/[^a-zA-Z0-9_\-\s]/', '_', $a['judul']);
            
            foreach ($submissions as $sub) {
                $file_path = $sub['path_file'];
                if (file_exists($file_path)) {
                    $ext = pathinfo($sub['nama_file'], PATHINFO_EXTENSION);
                    $safe_file_name = preg_replace('/[^a-zA-Z0-9_\-\s]/', '_', $sub['nomor_induk'] . '_' . $sub['nama_lengkap']) . '.' . $ext;
                    $zip->addFile($file_path, $folder_name . '/' . $safe_file_name);
                    $has_files = true;
                }
            }
        }

        if (!$has_files) {
            $zip->close();
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            die("<script>alert('Belum ada pengumpulan tugas pada mata kuliah ini.'); window.history.back();</script>");
        }
        $zip_filename = preg_replace('/[^a-zA-Z0-9_\-\s]/', '_', $matkul['kode_matkul'] . '_' . $matkul['nama_matkul']) . '_Backup_Lengkap.zip';
    }

    $zip->close();

    // Stream zip ke browser
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . filesize($temp_file));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($temp_file);
    if (file_exists($temp_file)) {
        unlink($temp_file);
    }
    exit();
}

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
