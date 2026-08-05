<?php
require_once 'config.php';
check_login('laboran');

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';
$flash_tab = '';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'];
    if (isset($_SESSION['flash_tab'])) {
        $flash_tab = $_SESSION['flash_tab'];
    }
    unset($_SESSION['flash_message'], $_SESSION['flash_type'], $_SESSION['flash_tab']);
}

// =====================================================================
// PROSES SEMUA AKSI POST
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // --- Tambah Semester ---
    if ($_POST['action'] === 'add_semester') {
        $nama_semester = clean_input($_POST['nama_semester']);
        $status = clean_input($_POST['status']);
        if (!empty($nama_semester)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO semesters (nama_semester, status) VALUES (?, ?)");
                $stmt->execute([$nama_semester, $status]);
                $message = 'Semester berhasil ditambahkan!';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Semester sudah ada atau terjadi kesalahan.';
                $message_type = 'error';
            }
        }
    }

    // --- Toggle Status Semester ---
    elseif ($_POST['action'] === 'toggle_semester') {
        $semester_id = (int)$_POST['semester_id'];
        $new_status = clean_input($_POST['new_status']);
        if (in_array($new_status, ['aktif', 'nonaktif'])) {
            $stmt = $pdo->prepare("UPDATE semesters SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $semester_id]);
            $message = 'Status semester diperbarui!';
            $message_type = 'success';
        }
    }

    // --- Hapus Semester ---
    elseif ($_POST['action'] === 'delete_semester') {
        $semester_id = (int)$_POST['semester_id'];
        try {
            // Hapus file-file submission yang terkait
            $sub_files = $pdo->query("
                SELECT sub.path_file FROM submissions sub
                JOIN assignments a ON sub.id_assignment = a.id
                JOIN mata_kuliah mk ON a.id_matkul = mk.id
                WHERE mk.id_semester = $semester_id
            ")->fetchAll();
            foreach ($sub_files as $f) {
                if (file_exists($f['path_file'])) unlink($f['path_file']);
            }
            $pdo->prepare("DELETE FROM semesters WHERE id = ?")->execute([$semester_id]);
            $message = 'Semester beserta seluruh isinya berhasil dihapus!';
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'Gagal menghapus: ' . $e->getMessage();
            $message_type = 'error';
        }
    }

    // --- Tambah Mata Kuliah ---
    elseif ($_POST['action'] === 'add_matkul') {
        $id_semester = (int)$_POST['id_semester'];
        $kode_matkul = strtoupper(clean_input($_POST['kode_matkul']));
        $nama_matkul = clean_input($_POST['nama_matkul']);
        $deskripsi   = clean_input($_POST['deskripsi_matkul']);
        if (!empty($id_semester) && !empty($kode_matkul) && !empty($nama_matkul)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO mata_kuliah (id_semester, kode_matkul, nama_matkul, deskripsi, dibuat_oleh) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$id_semester, $kode_matkul, $nama_matkul, $deskripsi, $user_id]);
                $message = "Mata kuliah $nama_matkul berhasil ditambahkan!";
                $message_type = 'success';
            } catch (PDOException $e) {
                if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
                    $message = 'Kode mata kuliah sudah ada di semester ini.';
                } else {
                    $message = 'Gagal menambahkan mata kuliah: ' . $e->getMessage();
                }
                $message_type = 'error';
            }
        } else {
            $message = 'Semester, kode, dan nama mata kuliah wajib diisi!';
            $message_type = 'error';
        }
    }

    // --- Hapus Mata Kuliah ---
    elseif ($_POST['action'] === 'delete_matkul') {
        $matkul_id = (int)$_POST['matkul_id'];
        try {
            $sub_files = $pdo->query("
                SELECT sub.path_file FROM submissions sub
                JOIN assignments a ON sub.id_assignment = a.id
                WHERE a.id_matkul = $matkul_id
            ")->fetchAll();
            foreach ($sub_files as $f) {
                if (file_exists($f['path_file'])) unlink($f['path_file']);
            }
            $pdo->prepare("DELETE FROM mata_kuliah WHERE id = ?")->execute([$matkul_id]);
            $message = 'Mata kuliah beserta seluruh tugasnya berhasil dihapus!';
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'Gagal menghapus mata kuliah.';
            $message_type = 'error';
        }
    }

    // --- Tambah Tugas ---
    elseif ($_POST['action'] === 'add_assignment') {
        $id_matkul  = (int)$_POST['id_matkul'];
        $judul      = clean_input($_POST['judul']);
        $deskripsi  = clean_input($_POST['deskripsi']);
        $deadline   = clean_input($_POST['deadline']);
        $tipe_file  = clean_input($_POST['tipe_file']);
        if (!empty($id_matkul) && !empty($judul) && !empty($deadline)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO assignments (id_matkul, judul, deskripsi, deadline, tipe_file, dibuat_oleh) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id_matkul, $judul, $deskripsi, $deadline, $tipe_file ?: 'all', $user_id]);
                $message = 'Tugas berhasil dibuat!';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Gagal membuat tugas: ' . $e->getMessage();
                $message_type = 'error';
            }
        } else {
            $message = 'Mata kuliah, judul, dan deadline wajib diisi!';
            $message_type = 'error';
        }
    }

    // --- Hapus Tugas ---
    elseif ($_POST['action'] === 'delete_assignment') {
        $assignment_id = (int)$_POST['assignment_id'];
        try {
            $subs = $pdo->prepare("SELECT path_file FROM submissions WHERE id_assignment = ?");
            $subs->execute([$assignment_id]);
            foreach ($subs->fetchAll() as $sub) {
                if (file_exists($sub['path_file'])) unlink($sub['path_file']);
            }
            $pdo->prepare("DELETE FROM assignments WHERE id = ?")->execute([$assignment_id]);
            $message = 'Tugas berhasil dihapus!';
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'Gagal menghapus tugas.';
            $message_type = 'error';
        }
    }

    // --- Tambah Mahasiswa Manual ---
    elseif ($_POST['action'] === 'add_mahasiswa') {
        $nama  = clean_input($_POST['nama_lengkap']);
        $nim   = clean_input($_POST['nim']);
        $uname = clean_input($_POST['username_mhs']);
        $pass  = clean_input($_POST['password_mhs']);
        if (empty($nama) || empty($nim) || empty($uname) || empty($pass)) {
            $message = 'Semua field mahasiswa wajib diisi!';
            $message_type = 'error';
        } else {
            try {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nomor_induk, role) VALUES (?, ?, ?, ?, 'mahasiswa')");
                $stmt->execute([$uname, $hash, $nama, $nim]);
                $message = "Akun mahasiswa $nama berhasil dibuat!";
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Username atau NIM sudah terdaftar!';
                $message_type = 'error';
            }
        }
    }

    // --- Reset Password Mahasiswa ---
    elseif ($_POST['action'] === 'reset_password') {
        $mhs_id   = (int)$_POST['mhs_id'];
        $new_pass = clean_input($_POST['new_password']);
        if (empty($new_pass)) {
            $message = 'Password baru tidak boleh kosong!';
            $message_type = 'error';
        } else {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'mahasiswa'")->execute([$hash, $mhs_id]);
            $message = 'Password berhasil direset!';
            $message_type = 'success';
        }
    }

    // --- Hapus Mahasiswa ---
    elseif ($_POST['action'] === 'delete_mahasiswa') {
        $mhs_id = (int)$_POST['mhs_id'];
        try {
            // Hapus file submissions milik mahasiswa ini
            $subs = $pdo->prepare("SELECT path_file FROM submissions WHERE id_mahasiswa = ?");
            $subs->execute([$mhs_id]);
            foreach ($subs->fetchAll() as $sub) {
                if (file_exists($sub['path_file'])) unlink($sub['path_file']);
            }
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'mahasiswa'")->execute([$mhs_id]);
            $message = 'Akun mahasiswa berhasil dihapus!';
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'Gagal menghapus akun: ' . $e->getMessage();
            $message_type = 'error';
        }
    }

    // --- Import Mahasiswa via CSV ---
    elseif ($_POST['action'] === 'import_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Harap pilih file CSV yang valid!';
            $message_type = 'error';
        } else {
            $file = $_FILES['csv_file'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'])) {
                $message = 'Hanya file CSV (.csv) yang didukung!';
                $message_type = 'error';
            } else {
                $handle = fopen($file['tmp_name'], 'r');
                $imported = 0;
                $skipped  = 0;
                $errors   = [];
                $row_num  = 0;

                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    $row_num++;
                    // Skip header row
                    if ($row_num === 1) {
                        // Deteksi header (jika ada)
                        if (strtolower(trim($row[0])) === 'nama_lengkap') continue;
                    }
                    if (empty($row[0])) continue;

                    $nama  = trim($row[0]);
                    $nim   = trim($row[1] ?? '');
                    $uname = trim($row[2] ?? $nim); // default username = nim
                    $pass  = trim($row[3] ?? $nim); // default password = nim

                    if (empty($nama) || empty($nim)) {
                        $errors[] = "Baris $row_num: nama/NIM kosong.";
                        $skipped++;
                        continue;
                    }
                    if (empty($uname)) $uname = $nim;
                    if (empty($pass))  $pass  = $nim;

                    try {
                        $hash = password_hash($pass, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nomor_induk, role) VALUES (?, ?, ?, ?, 'mahasiswa')");
                        $stmt->execute([$uname, $hash, $nama, $nim]);
                        $imported++;
                    } catch (PDOException $e) {
                        $errors[] = "Baris $row_num ($nama): username/NIM duplikat, dilewati.";
                        $skipped++;
                    }
                }
                fclose($handle);

                $msg_parts = ["$imported akun berhasil diimpor."];
                if ($skipped > 0) $msg_parts[] = "$skipped dilewati.";
                if (!empty($errors)) $msg_parts[] = implode(' | ', array_slice($errors, 0, 3));

                $message = implode(' ', $msg_parts);
                $message_type = $imported > 0 ? 'success' : 'error';
            }
        }
    }

    // --- Beri Nilai & Komentar (Grade Submission) ---
    elseif ($_POST['action'] === 'grade_submission') {
        $sub_id = (int)$_POST['submission_id'];
        $nilai = $_POST['nilai'] !== '' ? (int)$_POST['nilai'] : null;
        $catatan = clean_input($_POST['catatan_nilai']);
        $status = clean_input($_POST['status']);
        
        if ($sub_id > 0 && in_array($status, ['dikumpul', 'perlu_perbaikan', 'disetujui'])) {
            try {
                $pdo->beginTransaction();
                
                // 1. Update main submission record
                $stmt = $pdo->prepare("UPDATE submissions SET nilai = ?, catatan_nilai = ?, status = ? WHERE id = ?");
                $stmt->execute([$nilai, $catatan, $status, $sub_id]);
                
                // 2. Insert into feedback history logs
                $stmt2 = $pdo->prepare("INSERT INTO submission_feedback (id_submission, id_laboran, nilai, catatan_nilai, status) VALUES (?, ?, ?, ?, ?)");
                $stmt2->execute([$sub_id, $_SESSION['user_id'], $nilai, $catatan, $status]);
                
                $pdo->commit();
                $message = 'Nilai dan komentar berhasil disimpan!';
                $message_type = 'success';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'Gagal menyimpan nilai: ' . $e->getMessage();
                $message_type = 'error';
            }
        } else {
            $message = 'Data input tidak valid!';
            $message_type = 'error';
        }
    }

    // Post-Redirect-Get pattern to prevent form resubmission
    if ($message !== '') {
        $active_tab = 'tugas';
        if (in_array($_POST['action'], ['add_semester','delete_semester','toggle_semester'])) {
            $active_tab = 'semester';
        } elseif (in_array($_POST['action'], ['add_matkul','delete_matkul'])) {
            $active_tab = 'matkul';
        } elseif ($_POST['action'] === 'add_assignment') {
            $active_tab = 'buat-tugas';
        } elseif (in_array($_POST['action'], ['add_mahasiswa','delete_mahasiswa','reset_password','import_csv'])) {
            $active_tab = 'akun';
        }

        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $message_type;
        $_SESSION['flash_tab'] = $active_tab;
        
        $qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
        header("Location: laboran.php" . $qs);
        exit();
    }
}

// Download template CSV
if (isset($_GET['download_template'])) {
    check_login('laboran');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_mahasiswa.csv"');
    $out = fopen('php://output', 'w');
    // BOM untuk Excel agar karakter Indonesia terbaca
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['nama_lengkap', 'nim', 'username', 'password']);
    fputcsv($out, ['Budi Santoso', '2024001', 'budi2024', '2024001']);
    fputcsv($out, ['Siti Rahayu', '2024002', 'siti2024', '2024002']);
    fputcsv($out, ['Ahmad Fauzi', '2024003', '', '']); // kosong = default nim
    fclose($out);
    exit();
}
// =====================================================================
// AMBIL DATA
// =====================================================================
$filter_semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$filter_matkul   = isset($_GET['matkul'])   ? (int)$_GET['matkul']   : 0;

// Semua semester
$semesters = $pdo->query("SELECT * FROM semesters ORDER BY created_at DESC")->fetchAll();

// Semua mata kuliah (filter per semester jika ada)
if ($filter_semester > 0) {
    $stmt = $pdo->prepare("SELECT mk.*, s.nama_semester FROM mata_kuliah mk JOIN semesters s ON mk.id_semester = s.id WHERE mk.id_semester = ? ORDER BY mk.kode_matkul ASC");
    $stmt->execute([$filter_semester]);
} else {
    $stmt = $pdo->query("SELECT mk.*, s.nama_semester FROM mata_kuliah mk JOIN semesters s ON mk.id_semester = s.id ORDER BY s.created_at DESC, mk.kode_matkul ASC");
}
$all_matkul = $stmt->fetchAll();

// Ambil tugas dikelompokkan per mata kuliah
$tugas_query_where = '';
$tugas_params = [];
if ($filter_matkul > 0) {
    $tugas_query_where = 'WHERE a.id_matkul = ?';
    $tugas_params[] = $filter_matkul;
} elseif ($filter_semester > 0) {
    $tugas_query_where = 'WHERE mk.id_semester = ?';
    $tugas_params[] = $filter_semester;
}

$stmt = $pdo->prepare("
    SELECT a.*, mk.nama_matkul, mk.kode_matkul, mk.id as mk_id,
           s.nama_semester,
           COUNT(sub.id) as jumlah_pengumpul
    FROM assignments a
    JOIN mata_kuliah mk ON a.id_matkul = mk.id
    JOIN semesters s ON mk.id_semester = s.id
    LEFT JOIN submissions sub ON sub.id_assignment = a.id
    $tugas_query_where
    GROUP BY a.id
    ORDER BY mk.kode_matkul ASC, a.deadline ASC
");
$stmt->execute($tugas_params);
$all_assignments = $stmt->fetchAll();

// Kelompokkan tugas per matkul untuk ditampilkan
$grouped_assignments = [];
foreach ($all_assignments as $a) {
    $grouped_assignments[$a['mk_id']]['info'] = [
        'nama_matkul'   => $a['nama_matkul'],
        'kode_matkul'   => $a['kode_matkul'],
        'nama_semester' => $a['nama_semester'],
    ];
    $grouped_assignments[$a['mk_id']]['tugas'][] = $a;
}

// Detail panel
$detail_assignment_id = isset($_GET['detail']) ? (int)$_GET['detail'] : 0;
$detail_assignment = null;
$detail_submissions = [];
if ($detail_assignment_id > 0) {
    $stmt = $pdo->prepare("
        SELECT a.*, mk.nama_matkul, mk.kode_matkul, s.nama_semester
        FROM assignments a
        JOIN mata_kuliah mk ON a.id_matkul = mk.id
        JOIN semesters s ON mk.id_semester = s.id
        WHERE a.id = ?
    ");
    $stmt->execute([$detail_assignment_id]);
    $detail_assignment = $stmt->fetch();
    $stmt = $pdo->prepare("
        SELECT sub.*, u.nama_lengkap, u.nomor_induk
        FROM submissions sub
        JOIN users u ON sub.id_mahasiswa = u.id
        WHERE sub.id_assignment = ?
        ORDER BY sub.waktu_unggah ASC
    ");
    $stmt->execute([$detail_assignment_id]);
    $detail_submissions = $stmt->fetchAll();
}

// Statistik
$total_mahasiswa  = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'mahasiswa'")->fetchColumn();
$total_matkul     = $pdo->query("SELECT COUNT(*) FROM mata_kuliah")->fetchColumn();
$total_assignments= $pdo->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
$total_submissions= $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Laboran - KumpulTugas</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .stats-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:2rem; }
        @media(max-width:700px) { .stats-grid { grid-template-columns:repeat(2,1fr); } }

        .form-row-3 { display:grid; grid-template-columns:1fr 2fr 2fr; gap:1rem; }
        @media(max-width:700px) { .form-row-3 { grid-template-columns:1fr; } }

        /* Assignment row within matkul body */
        .assignment-row { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.5rem; border-bottom:1px solid var(--border-color); gap:1rem; flex-wrap:wrap; transition:background .2s; }
        .assignment-row:last-child { border-bottom:none; }
        .assignment-row:hover { background:var(--accent-primary-light); }
        .assignment-info { flex:1; }
        .assignment-title { font-weight:600; font-size:.98rem; margin-bottom:.3rem; color:var(--text-main); }
        .assignment-meta { display:flex; gap:1rem; flex-wrap:wrap; font-size:.8rem; color:var(--text-muted); align-items:center; }
        .action-btns { display:flex; gap:.4rem; }
    </style>

</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="navbar-container">
        <span class="navbar-brand">🎓 KumpulTugas</span>
        <div class="navbar-user">
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($_SESSION['nama_lengkap']) ?></div>
                <div class="user-role">Asisten Laboran</div>
            </div>
            <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
        </div>
    </div>
</nav>

<!-- Detail Side Panel -->
<div class="detail-overlay <?= $detail_assignment ? 'open' : '' ?>" id="detailOverlay" onclick="closeDetail()"></div>
<div class="detail-panel <?= $detail_assignment ? 'open' : '' ?>" id="detailPanel">
    <?php if ($detail_assignment): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
            <h3 style="font-size:1.1rem;">Detail Pengumpulan</h3>
            <a href="laboran.php?<?= http_build_query(array_filter(['semester'=>$filter_semester,'matkul'=>$filter_matkul])) ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;">✕ Tutup</a>
        </div>
        <div style="margin-bottom:1.25rem;">
            <h4 style="margin-bottom:.3rem;"><?= htmlspecialchars($detail_assignment['judul']) ?></h4>
            <p style="font-size:.82rem;color:var(--accent-cyan);margin-bottom:.25rem;">
                <?= htmlspecialchars($detail_assignment['kode_matkul']) ?> · <?= htmlspecialchars($detail_assignment['nama_matkul']) ?>
            </p>
            <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:.25rem;"><?= htmlspecialchars($detail_assignment['nama_semester']) ?></p>
            <p style="font-size:.82rem;color:var(--color-warning);">⏰ <?= format_tanggal($detail_assignment['deadline']) ?></p>
        </div>
        <div style="font-size:.88rem;font-weight:600;margin-bottom:1rem;color:var(--text-muted);"><?= count($detail_submissions) ?> Mahasiswa Mengumpulkan</div>
        <?php if (empty($detail_submissions)): ?>
            <div style="text-align:center;color:var(--text-muted);padding:3rem 0;">
                <div style="font-size:2rem;margin-bottom:.5rem;">📭</div>
                <p>Belum ada yang mengumpulkan</p>
            </div>
        <?php else: ?>
            <?php foreach ($detail_submissions as $sub): 
                $stmt_fb = $pdo->prepare("
                    SELECT sf.*, u.nama_lengkap as nama_laboran 
                    FROM submission_feedback sf 
                    JOIN users u ON sf.id_laboran = u.id 
                    WHERE sf.id_submission = ? 
                    ORDER BY sf.created_at ASC
                ");
                $stmt_fb->execute([$sub['id']]);
                $feedback_logs = $stmt_fb->fetchAll();
            ?>
            <div class="submission-card" style="border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: var(--bg-card);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.75rem;">
                    <div>
                        <div style="font-weight:600; font-size:.95rem; color:var(--text-main);"><?= htmlspecialchars($sub['nama_lengkap']) ?></div>
                        <div style="font-size:.8rem; color:var(--text-muted);">NIM: <?= htmlspecialchars($sub['nomor_induk']) ?></div>
                        <div style="font-size:.78rem; color:var(--text-muted-dark); margin-top:0.25rem;">📎 <?= htmlspecialchars($sub['nama_file']) ?></div>
                        <div style="font-size:.75rem; color:var(--text-muted-dark);">⏱ <?= format_tanggal($sub['waktu_unggah']) ?></div>
                        
                        <!-- Badge status pengumpulan -->
                        <div style="margin-top:0.5rem; display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                            <?php if ($sub['status'] === 'disetujui'): ?>
                                <span class="badge badge-active" style="font-size:0.68rem; padding:0.1rem 0.5rem; border-radius:4px; text-transform:none;">Selesai / Disetujui</span>
                            <?php elseif ($sub['status'] === 'perlu_perbaikan'): ?>
                                <span class="badge" style="background:var(--color-warning-bg); color:var(--color-warning); border:1.5px solid var(--color-warning-border); font-size:0.68rem; padding:0.1rem 0.5rem; border-radius:4px; text-transform:none;">Perlu Perbaikan</span>
                            <?php else: ?>
                                <span class="badge badge-info" style="font-size:0.68rem; padding:0.1rem 0.5rem; border-radius:4px; text-transform:none;">Belum Dinilai</span>
                            <?php endif; ?>
                            
                            <?php if ($sub['nilai'] !== null): ?>
                                <span style="font-size:0.82rem; font-weight:700; color:var(--accent-primary);">Nilai: <?= $sub['nilai'] ?>/100</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div style="display:flex; gap:0.3rem;">
                        <!-- View Button -->
                        <a href="view_submission.php?id=<?= $sub['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" style="text-decoration:none; font-size:.78rem; padding: 0.35rem 0.65rem; display:inline-flex; align-items:center; gap:0.2rem; border-radius:6px;">👁️ Lihat</a>
                        <!-- Download Button -->
                        <a href="download.php?sub_id=<?= $sub['id'] ?>" class="btn btn-secondary btn-sm" style="text-decoration:none; font-size:.78rem; padding: 0.35rem 0.65rem; display:inline-flex; align-items:center; gap:0.2rem; border-radius:6px;">⬇ Unduh</a>
                    </div>
                </div>

                <!-- Feedback Logs / Comment History Timeline -->
                <?php if (!empty($feedback_logs)): ?>
                    <div style="margin-top: 1rem; margin-bottom: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color);">
                        <div style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem;">
                            📜 Riwayat Komentar & Koreksi (<?= count($feedback_logs) ?>)
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 0.75rem; border-left: 2px solid var(--border-color); padding-left: 0.9rem; margin-left: 0.5rem;">
                            <?php foreach ($feedback_logs as $fb): ?>
                                <div style="position: relative;">
                                    <!-- Timeline Dot -->
                                    <div style="position: absolute; left: -1.22rem; top: 0.25rem; width: 8px; height: 8px; border-radius: 50%; background: <?= $fb['status'] === 'disetujui' ? 'var(--color-success)' : ($fb['status'] === 'perlu_perbaikan' ? 'var(--color-warning)' : 'var(--text-muted-dark)') ?>; border: 2px solid #fff; box-shadow: 0 0 0 2px var(--border-color);"></div>
                                    
                                    <div style="font-size: 0.74rem; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.3rem;">
                                        <span>Oleh: <strong><?= htmlspecialchars($fb['nama_laboran']) ?></strong></span>
                                        <span>⏱ <?= format_tanggal($fb['created_at']) ?></span>
                                    </div>
                                    
                                    <div style="margin-top: 0.2rem; font-size: 0.85rem; color: var(--text-secondary);">
                                        <?php if ($fb['nilai'] !== null): ?>
                                            <span style="font-weight: 600; color: var(--text-main);">Nilai: <?= $fb['nilai'] ?></span> · 
                                        <?php endif; ?>
                                        
                                        <?php if ($fb['status'] === 'disetujui'): ?>
                                            <span style="color: var(--color-success); font-weight: 600;">Disetujui</span>
                                        <?php elseif ($fb['status'] === 'perlu_perbaikan'): ?>
                                            <span style="color: var(--color-warning); font-weight: 600;">Perlu Perbaikan</span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-weight: 600;">Belum Dinilai</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($fb['catatan_nilai'])): ?>
                                        <div style="margin-top: 0.3rem; font-size: 0.82rem; font-style: italic; background: var(--bg-main); padding: 0.4rem 0.6rem; border-radius: 6px; color: var(--text-muted); line-height: 1.4; border-left: 2.5px solid var(--accent-primary-mid);">
                                            <?= nl2br(htmlspecialchars($fb['catatan_nilai'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Grading Form Toggle -->
                <details style="margin-top:0.5rem; border-top:1px dashed var(--border-color); padding-top:0.5rem;">
                    <summary style="font-size:0.82rem; color:var(--accent-primary); cursor:pointer; font-weight:600; outline:none; user-select:none;">
                        📝 Atur Nilai & Komentar
                    </summary>
                    
                    <form method="POST" style="margin-top:0.75rem; background:var(--bg-main); padding:0.75rem; border-radius:8px; border:1px solid var(--border-color);">
                        <input type="hidden" name="action" value="grade_submission">
                        <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
                        
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-bottom:0.5rem;">
                            <div>
                                <label class="form-label" style="font-size:0.78rem; margin-bottom:0.2rem; display:block;">Nilai (0-100)</label>
                                <input type="number" name="nilai" class="form-input" min="0" max="100" value="<?= htmlspecialchars($sub['nilai'] ?? '') ?>" placeholder="N/A" style="padding:0.35rem; font-size:0.85rem; border-radius:6px; background:var(--bg-card);">
                            </div>
                            <div>
                                <label class="form-label" style="font-size:0.78rem; margin-bottom:0.2rem; display:block;">Status</label>
                                <select name="status" class="form-select" style="padding:0.35rem; font-size:0.85rem; height:auto; border-radius:6px; background:var(--bg-card);" required>
                                    <option value="dikumpul" <?= $sub['status'] === 'dikumpul' ? 'selected' : '' ?>>Belum Dinilai</option>
                                    <option value="disetujui" <?= $sub['status'] === 'disetujui' ? 'selected' : '' ?>>Selesai / Disetujui</option>
                                    <option value="perlu_perbaikan" <?= $sub['status'] === 'perlu_perbaikan' ? 'selected' : '' ?>>Perlu Perbaikan</option>
                                </select>
                            </div>
                        </div>
                        
                        <div style="margin-bottom:0.75rem;">
                            <label class="form-label" style="font-size:0.78rem; margin-bottom:0.2rem; display:block;">Komentar / Catatan Perbaikan</label>
                            <textarea name="catatan_nilai" class="form-textarea" rows="2" placeholder="Tulis komentar atau instruksi revisi..." style="padding:0.4rem; font-size:0.85rem; height:auto; min-height:50px; border-radius:6px; background:var(--bg-card);"><?= htmlspecialchars($sub['catatan_nilai'] ?? '') ?></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-sm" style="width:100%; font-size:0.8rem; padding:0.45rem; border-radius:6px;">Simpan Nilai & Komentar</button>
                    </form>
                </details>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Main Content -->
<div class="main-content">

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <?= $message_type === 'success' ? '✅' : '⚠️' ?> <?= $message ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-header">
        <div>
            <h1 class="dashboard-title">Dashboard Laboran</h1>
            <p class="dashboard-subtitle">Kelola semester, mata kuliah, slot tugas, dan pantau pengumpulan mahasiswa.</p>
        </div>
    </div>

    <!-- Statistik -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-num"><?= $total_mahasiswa ?></div><div class="stat-desc">Total Mahasiswa</div></div>
        <div class="stat-card"><div class="stat-num"><?= count($semesters) ?></div><div class="stat-desc">Semester</div></div>
        <div class="stat-card"><div class="stat-num"><?= $total_matkul ?></div><div class="stat-desc">Mata Kuliah</div></div>
        <div class="stat-card"><div class="stat-num"><?= $total_submissions ?></div><div class="stat-desc">Total Pengumpulan</div></div>
    </div>

    <!-- Tab Bar -->
    <div class="tab-bar">
        <button class="tab-btn active" id="tab-btn-tugas"      onclick="switchTab('tugas')">📋 Daftar Tugas</button>
        <button class="tab-btn" id="tab-btn-matkul"     onclick="switchTab('matkul')">📁 Mata Kuliah</button>
        <button class="tab-btn" id="tab-btn-semester"   onclick="switchTab('semester')">📅 Semester</button>
        <button class="tab-btn" id="tab-btn-buat-tugas" onclick="switchTab('buat-tugas')">➕ Buat Tugas</button>
        <button class="tab-btn" id="tab-btn-akun"       onclick="switchTab('akun')">👥 Akun Mahasiswa</button>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: DAFTAR TUGAS (grouped by mata kuliah) -->
    <!-- ============================================================ -->
    <div class="tab-content active" id="tab-tugas">

        <!-- Filter -->
        <div class="filter-bar">
            <span class="filter-label">Semester:</span>
            <a href="laboran.php" class="btn btn-sm <?= !$filter_semester ? 'btn-primary' : 'btn-secondary' ?>" style="text-decoration:none;">Semua</a>
            <?php foreach ($semesters as $sem): ?>
                <a href="laboran.php?semester=<?= $sem['id'] ?>" class="btn btn-sm <?= $filter_semester == $sem['id'] ? 'btn-primary' : 'btn-secondary' ?>" style="text-decoration:none;">
                    <?= htmlspecialchars($sem['nama_semester']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($filter_semester && !empty($all_matkul)): ?>
        <div class="filter-bar" style="margin-top:-.5rem;">
            <span class="filter-label">Matkul:</span>
            <a href="laboran.php?semester=<?= $filter_semester ?>" class="btn btn-sm <?= !$filter_matkul ? 'btn-primary' : 'btn-secondary' ?>" style="text-decoration:none;">Semua</a>
            <?php foreach ($all_matkul as $mk): ?>
                <a href="laboran.php?semester=<?= $filter_semester ?>&matkul=<?= $mk['id'] ?>" class="btn btn-sm <?= $filter_matkul == $mk['id'] ? 'btn-primary' : 'btn-secondary' ?>" style="text-decoration:none;">
                    <?= htmlspecialchars($mk['kode_matkul']) ?> — <?= htmlspecialchars($mk['nama_matkul']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($grouped_assignments)): ?>
            <div class="glass-panel">
                <div class="empty-state">
                    <div class="empty-icon">📝</div>
                    <p style="font-size:1rem;">Belum ada tugas yang dibuat.</p>
                    <button class="btn btn-primary" style="margin-top:1.25rem;" onclick="switchTab('buat-tugas')">➕ Buat Tugas Pertama</button>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($grouped_assignments as $mk_id => $group): ?>
            <div class="matkul-group">
                <div class="matkul-header">
                    <div class="matkul-title">
                        <span class="matkul-code"><?= htmlspecialchars($group['info']['kode_matkul']) ?></span>
                        📁 <?= htmlspecialchars($group['info']['nama_matkul']) ?>
                    </div>
                    <span style="font-size:.82rem;color:var(--text-muted);"><?= htmlspecialchars($group['info']['nama_semester']) ?> · <?= count($group['tugas']) ?> tugas</span>
                </div>
                <div class="matkul-body">
                    <?php foreach ($group['tugas'] as $a):
                        $is_overdue = strtotime($a['deadline']) < time();
                        $pct = $total_mahasiswa > 0 ? min(100, round(($a['jumlah_pengumpul'] / $total_mahasiswa) * 100)) : 0;
                    ?>
                    <div class="assignment-row">
                        <div class="assignment-info">
                            <div class="assignment-title"><?= htmlspecialchars($a['judul']) ?></div>
                            <div class="assignment-meta">
                                <span class="deadline-text <?= $is_overdue ? 'overdue' : '' ?>">
                                    <?= $is_overdue ? '❌' : '⏰' ?> <?= format_tanggal($a['deadline']) ?>
                                </span>
                                <span>👥 <?= $a['jumlah_pengumpul'] ?>/<?= $total_mahasiswa ?> mengumpulkan</span>
                            </div>
                            <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= $pct ?>%"></div></div>
                        </div>
                        <div class="action-btns">
                            <a href="laboran.php?detail=<?= $a['id'] ?>&semester=<?= $filter_semester ?>&matkul=<?= $filter_matkul ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;">👁 Detail</a>
                            <form method="POST" onsubmit="return confirm('Hapus tugas ini?')">
                                <input type="hidden" name="action" value="delete_assignment">
                                <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: KELOLA MATA KULIAH -->
    <!-- ============================================================ -->
    <div class="tab-content" id="tab-matkul">
        <!-- Form Tambah Matkul -->
        <div class="glass-panel" style="margin-bottom:2rem;">
            <h3 style="font-size:1.2rem;margin-bottom:1.5rem;">📁 Tambah Mata Kuliah Baru</h3>
            <?php if (empty($semesters)): ?>
                <div class="alert alert-error">⚠️ Tambahkan semester terlebih dahulu!</div>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="add_matkul">
                <div class="form-row" style="margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label">Semester</label>
                        <select name="id_semester" class="form-select" required>
                            <option value="">-- Pilih Semester --</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= $sem['id'] ?>"><?= htmlspecialchars($sem['nama_semester']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kode Mata Kuliah</label>
                        <input type="text" name="kode_matkul" class="form-input" placeholder="Contoh: CS201" required maxlength="20">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nama Mata Kuliah</label>
                    <input type="text" name="nama_matkul" class="form-input" placeholder="Contoh: Algoritma dan Pemrograman" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Deskripsi (Opsional)</label>
                    <textarea name="deskripsi_matkul" class="form-textarea" rows="2" placeholder="Deskripsi singkat mata kuliah..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">➕ Tambah Mata Kuliah</button>
            </form>
            <?php endif; ?>
        </div>

        <!-- Daftar Matkul per Semester -->
        <h3 style="font-size:1.2rem;margin-bottom:1rem;">📋 Daftar Mata Kuliah</h3>
        <?php
        // Kelompokkan matkul per semester
        $matkul_by_sem = [];
        $all_matkul_full = $pdo->query("
            SELECT mk.*, s.nama_semester, s.status as status_sem
            FROM mata_kuliah mk
            JOIN semesters s ON mk.id_semester = s.id
            ORDER BY s.created_at DESC, mk.kode_matkul ASC
        ")->fetchAll();
        foreach ($all_matkul_full as $mk) {
            $matkul_by_sem[$mk['id_semester']]['semester'] = $mk['nama_semester'];
            $matkul_by_sem[$mk['id_semester']]['matkul'][] = $mk;
        }
        ?>
        <?php if (empty($matkul_by_sem)): ?>
            <div class="glass-panel"><div class="empty-state"><div class="empty-icon">📭</div><p>Belum ada mata kuliah yang ditambahkan.</p></div></div>
        <?php else: ?>
            <?php foreach ($matkul_by_sem as $sem_id => $sem_group): ?>
                <div style="margin-bottom:1.5rem;">
                    <div style="font-size:.82rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.6rem;padding-left:.25rem;">
                        📅 <?= htmlspecialchars($sem_group['semester']) ?>
                    </div>
                    <?php foreach ($sem_group['matkul'] as $mk): ?>
                    <div class="matkul-list-item">
                        <div class="matkul-list-info">
                            <span class="matkul-code"><?= htmlspecialchars($mk['kode_matkul']) ?></span>
                            <div>
                                <div style="font-weight:600;font-size:.95rem;"><?= htmlspecialchars($mk['nama_matkul']) ?></div>
                                <?php if ($mk['deskripsi']): ?>
                                    <div style="font-size:.8rem;color:var(--text-muted);margin-top:.1rem;"><?= htmlspecialchars($mk['deskripsi']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <form method="POST" onsubmit="return confirm('Hapus mata kuliah ini? Semua tugas di dalamnya ikut terhapus!')">
                            <input type="hidden" name="action" value="delete_matkul">
                            <input type="hidden" name="matkul_id" value="<?= $mk['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑 Hapus</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: KELOLA SEMESTER -->
    <!-- ============================================================ -->
    <div class="tab-content" id="tab-semester">
        <div class="glass-panel" style="margin-bottom:2rem;">
            <h3 style="font-size:1.2rem;margin-bottom:1.5rem;">➕ Tambah Semester Baru</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_semester">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nama Semester</label>
                        <input type="text" name="nama_semester" class="form-input" placeholder="Contoh: Ganjil 2027/2028" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="aktif">Aktif</option>
                            <option value="nonaktif">Nonaktif</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Tambah Semester</button>
            </form>
        </div>
        <h3 style="font-size:1.2rem;margin-bottom:1rem;">📅 Daftar Semester</h3>
        <?php if (empty($semesters)): ?>
            <div class="glass-panel"><div class="empty-state"><div class="empty-icon">📭</div><p>Belum ada semester.</p></div></div>
        <?php else: ?>
            <?php foreach ($semesters as $sem): ?>
            <div class="semester-row">
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                    <div class="semester-name"><?= htmlspecialchars($sem['nama_semester']) ?></div>
                    <span class="badge <?= $sem['status'] === 'aktif' ? 'badge-active' : 'badge-inactive' ?>"><?= ucfirst($sem['status']) ?></span>
                </div>
                <div style="display:flex;gap:.5rem;">
                    <form method="POST">
                        <input type="hidden" name="action" value="toggle_semester">
                        <input type="hidden" name="semester_id" value="<?= $sem['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= $sem['status'] === 'aktif' ? 'nonaktif' : 'aktif' ?>">
                        <button type="submit" class="btn btn-secondary btn-sm"><?= $sem['status'] === 'aktif' ? '🔴 Nonaktifkan' : '🟢 Aktifkan' ?></button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Hapus semester ini beserta seluruh mata kuliah dan tugasnya?')">
                        <input type="hidden" name="action" value="delete_semester">
                        <input type="hidden" name="semester_id" value="<?= $sem['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: BUAT TUGAS BARU -->
    <!-- ============================================================ -->
    <div class="tab-content" id="tab-buat-tugas">
        <div class="glass-panel">
            <h3 style="font-size:1.2rem;margin-bottom:1.5rem;">📝 Buat Slot Tugas Baru</h3>
            <?php if (empty($all_matkul_full)): ?>
                <div class="alert alert-error">⚠️ Buat semester dan mata kuliah terlebih dahulu sebelum membuat tugas!</div>
                <button class="btn btn-primary" style="margin-top:1rem;" onclick="switchTab('matkul')">📁 Kelola Mata Kuliah</button>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="add_assignment">
                <div class="form-group">
                    <label class="form-label">Mata Kuliah</label>
                    <select name="id_matkul" class="form-select" required>
                        <option value="">-- Pilih Mata Kuliah --</option>
                        <?php
                        $matkul_grouped_opt = [];
                        foreach ($all_matkul_full as $mk) {
                            $matkul_grouped_opt[$mk['nama_semester']][] = $mk;
                        }
                        foreach ($matkul_grouped_opt as $sem_name => $mklist):
                        ?>
                            <optgroup label="📅 <?= htmlspecialchars($sem_name) ?>">
                                <?php foreach ($mklist as $mk): ?>
                                    <option value="<?= $mk['id'] ?>">[<?= htmlspecialchars($mk['kode_matkul']) ?>] <?= htmlspecialchars($mk['nama_matkul']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Judul Tugas</label>
                    <input type="text" name="judul" class="form-input" placeholder="Contoh: Tugas 1 - Array dan String" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Deadline Pengumpulan</label>
                        <input type="datetime-local" name="deadline" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tipe File yang Diizinkan</label>
                        <input type="text" name="tipe_file" class="form-input" placeholder="pdf,docx,zip (kosong = semua)">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Deskripsi / Instruksi (Opsional)</label>
                    <textarea name="deskripsi" class="form-textarea" rows="4" placeholder="Tulis instruksi pengerjaan tugas..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">✅ Buat Tugas</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: KELOLA AKUN MAHASISWA -->
    <!-- ============================================================ -->
    <div class="tab-content" id="tab-akun">

        <!-- Import CSV -->
        <div class="glass-panel" style="margin-bottom:2rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
                <div>
                    <h3 style="font-size:1.2rem; margin-bottom:.3rem;">📤 Import Mahasiswa via CSV</h3>
                    <p style="font-size:.88rem; color:var(--text-muted);">Upload file CSV berisi data mahasiswa. Kolom: <code style="background:var(--bg-input); padding:.1rem .4rem; border-radius:4px;">nama_lengkap, nim, username, password</code></p>
                </div>
                <a href="laboran.php?download_template=1" class="btn btn-secondary btn-sm" style="white-space:nowrap;">
                    ⬇ Unduh Template CSV
                </a>
            </div>

            <div style="background:var(--accent-gold-light); border:1.5px solid rgba(242,183,5,.3); border-radius:8px; padding:1rem; margin-bottom:1.25rem; font-size:.85rem; color:var(--accent-gold-dark);">
                💡 <strong>Tips:</strong> Jika kolom <em>username</em> atau <em>password</em> dikosongkan, otomatis menggunakan NIM. File Excel: simpan dulu sebagai <strong>.CSV (Comma delimited)</strong>.
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_csv">
                <div style="display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap;">
                    <div style="flex:1; min-width:220px;">
                        <label class="form-label">Pilih File CSV</label>
                        <input type="file" name="csv_file" class="form-input" accept=".csv,.txt" required style="padding:.6rem;">
                    </div>
                    <button type="submit" class="btn btn-primary">📤 Import Sekarang</button>
                </div>
            </form>
        </div>

        <!-- Tambah Manual -->
        <div class="glass-panel" style="margin-bottom:2rem;">
            <h3 style="font-size:1.2rem; margin-bottom:1.5rem;">➕ Tambah Mahasiswa Manual</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_mahasiswa">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" class="form-input" placeholder="Nama lengkap mahasiswa" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">NIM</label>
                        <input type="text" name="nim" class="form-input" placeholder="Nomor Induk Mahasiswa" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username_mhs" class="form-input" placeholder="Username untuk login" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="text" name="password_mhs" class="form-input" placeholder="Password awal (bisa diubah nanti)" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">➕ Tambah Mahasiswa</button>
            </form>
        </div>

        <!-- Daftar Mahasiswa -->
        <h3 style="font-size:1.2rem; margin-bottom:1rem;">👥 Daftar Akun Mahasiswa</h3>
        <?php
        $daftar_mhs = $pdo->query("
            SELECT u.*, 
                   COUNT(sub.id) as total_kumpul
            FROM users u
            LEFT JOIN submissions sub ON sub.id_mahasiswa = u.id
            WHERE u.role = 'mahasiswa'
            GROUP BY u.id
            ORDER BY u.nama_lengkap ASC
        ")->fetchAll();
        ?>
        <?php if (empty($daftar_mhs)): ?>
            <div class="glass-panel">
                <div class="empty-state">
                    <div class="empty-icon">👤</div>
                    <p>Belum ada akun mahasiswa. Import CSV atau tambah secara manual.</p>
                </div>
            </div>
        <?php else: ?>
            <!-- Search filter -->
            <div style="margin-bottom:1rem;">
                <input type="text" id="searchMhs" class="form-input" placeholder="🔍 Cari nama atau NIM..." oninput="filterMhs()" style="max-width:350px;">
            </div>
            <div class="table-wrapper">
                <table class="custom-table" id="mhsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Lengkap</th>
                            <th>NIM</th>
                            <th>Username</th>
                            <th>Total Kumpul</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daftar_mhs as $i => $mhs): ?>
                        <tr class="mhs-row">
                            <td style="color:var(--text-muted);"><?= $i+1 ?></td>
                            <td style="font-weight:500; color:var(--text-main);"><?= htmlspecialchars($mhs['nama_lengkap']) ?></td>
                            <td><code style="background:var(--bg-input); padding:.15rem .5rem; border-radius:4px; font-size:.85rem;"><?= htmlspecialchars($mhs['nomor_induk']) ?></code></td>
                            <td><?= htmlspecialchars($mhs['username']) ?></td>
                            <td><span class="badge badge-info"><?= $mhs['total_kumpul'] ?> file</span></td>
                            <td>
                                <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
                                    <!-- Reset Password -->
                                    <button class="btn btn-secondary btn-sm" onclick="showResetForm(<?= $mhs['id'] ?>, '<?= htmlspecialchars($mhs['nama_lengkap']) ?>')">🔑 Reset</button>
                                    <!-- Hapus -->
                                    <form method="POST" onsubmit="return confirm('Hapus akun <?= htmlspecialchars(addslashes($mhs['nama_lengkap'])) ?>? Semua file tugasnya ikut terhapus!')">
                                        <input type="hidden" name="action" value="delete_mahasiswa">
                                        <input type="hidden" name="mhs_id" value="<?= $mhs['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="font-size:.82rem; color:var(--text-muted); margin-top:.75rem;">Total: <?= count($daftar_mhs) ?> mahasiswa terdaftar</p>
        <?php endif; ?>

        <!-- Modal Reset Password (hidden by default) -->
        <div id="resetModal" style="display:none; position:fixed; inset:0; background:rgba(11,78,162,.15); backdrop-filter:blur(4px); z-index:300; justify-content:center; align-items:center;">
            <div style="background:#fff; border:1.5px solid var(--border-color); border-radius:16px; padding:2rem; width:100%; max-width:420px; box-shadow:var(--shadow-lg);">
                <h4 style="margin-bottom:1.25rem;">🔑 Reset Password Mahasiswa</h4>
                <p id="resetMhsName" style="color:var(--text-muted); font-size:.9rem; margin-bottom:1.25rem;"></p>
                <form method="POST">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="mhs_id" id="resetMhsId">
                    <div class="form-group">
                        <label class="form-label">Password Baru</label>
                        <input type="text" name="new_password" class="form-input" placeholder="Masukkan password baru" required>
                    </div>
                    <div style="display:flex; gap:.75rem;">
                        <button type="submit" class="btn btn-primary">✅ Simpan</button>
                        <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<footer><p>KumpulTugas &copy; 2026 — Sistem Pengumpulan Tugas Mahasiswa</p></footer>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.getElementById('tab-btn-' + name).classList.add('active');
}
function closeDetail() {
    document.getElementById('detailPanel').classList.remove('open');
    document.getElementById('detailOverlay').classList.remove('open');
}
<?php if ($flash_tab): ?>
    switchTab('<?= $flash_tab ?>');
<?php elseif (isset($_POST['action'])): ?>
    <?php if (in_array($_POST['action'], ['add_semester','delete_semester','toggle_semester'])): ?>
        switchTab('semester');
    <?php elseif (in_array($_POST['action'], ['add_matkul','delete_matkul'])): ?>
        switchTab('matkul');
    <?php elseif ($_POST['action'] === 'add_assignment'): ?>
        switchTab('buat-tugas');
    <?php elseif (in_array($_POST['action'], ['add_mahasiswa','delete_mahasiswa','reset_password','import_csv'])): ?>
        switchTab('akun');
    <?php endif; ?>
<?php endif; ?>

function filterMhs() {
    const q = document.getElementById('searchMhs').value.toLowerCase();
    document.querySelectorAll('#mhsTable .mhs-row').forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
    });
}

function showResetForm(id, nama) {
    document.getElementById('resetMhsId').value = id;
    document.getElementById('resetMhsName').textContent = 'Mahasiswa: ' + nama;
    const modal = document.getElementById('resetModal');
    modal.style.display = 'flex';
}

function closeResetModal() {
    document.getElementById('resetModal').style.display = 'none';
}
</script>

</body>
</html>
