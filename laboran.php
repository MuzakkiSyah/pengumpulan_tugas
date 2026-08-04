<?php
require_once 'config.php';
check_login('laboran');

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

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
                $message = 'Kode mata kuliah sudah ada di semester ini.';
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
        .stat-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:1.5rem; text-align:center; }
        .stat-num { font-size:2.2rem; font-weight:700; background:var(--grad-primary); -webkit-background-clip:text; -webkit-text-fill-color:transparent; font-family:'Outfit',sans-serif; }
        .stat-desc { color:var(--text-muted); font-size:0.82rem; margin-top:0.25rem; }

        .tab-bar { display:flex; gap:0.5rem; margin-bottom:2rem; flex-wrap:wrap; }
        .tab-btn { padding:0.6rem 1.2rem; border-radius:8px; border:1px solid var(--border-color); background:transparent; color:var(--text-muted); cursor:pointer; font-family:'Inter',sans-serif; font-size:0.9rem; transition:all 0.3s; }
        .tab-btn.active { background:var(--grad-primary); color:#05070e; border-color:transparent; font-weight:600; }
        .tab-btn:hover:not(.active) { background:rgba(255,255,255,.05); color:var(--text-main); }
        .tab-content { display:none; }
        .tab-content.active { display:block; }

        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        @media(max-width:700px) { .form-row { grid-template-columns:1fr; } }
        .form-row-3 { display:grid; grid-template-columns:1fr 2fr 2fr; gap:1rem; }
        @media(max-width:700px) { .form-row-3 { grid-template-columns:1fr; } }

        /* Semester row */
        .semester-row { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-radius:10px; background:rgba(15,18,35,.5); border:1px solid var(--border-color); margin-bottom:.75rem; gap:1rem; flex-wrap:wrap; }
        .semester-name { font-weight:600; }

        /* Matkul Card */
        .matkul-group { margin-bottom:2rem; }
        .matkul-header {
            display:flex; justify-content:space-between; align-items:center;
            padding:1rem 1.5rem; border-radius:12px 12px 0 0;
            background:linear-gradient(135deg,rgba(79,172,254,.12),rgba(0,242,254,.06));
            border:1px solid rgba(79,172,254,.2);
            gap:1rem; flex-wrap:wrap;
        }
        .matkul-title { font-size:1.05rem; font-weight:700; display:flex; align-items:center; gap:.6rem; }
        .matkul-code { background:rgba(0,242,254,.12); color:var(--accent-cyan); border:1px solid rgba(0,242,254,.2); padding:.2rem .6rem; border-radius:6px; font-size:.8rem; font-family:'Outfit',sans-serif; font-weight:700; }
        .matkul-body { border:1px solid rgba(79,172,254,.15); border-top:none; border-radius:0 0 12px 12px; overflow:hidden; }
        .assignment-row { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.5rem; border-bottom:1px solid var(--border-color); gap:1rem; flex-wrap:wrap; transition:background .2s; }
        .assignment-row:last-child { border-bottom:none; }
        .assignment-row:hover { background:rgba(255,255,255,.02); }
        .assignment-info { flex:1; }
        .assignment-title { font-weight:600; font-size:.98rem; margin-bottom:.3rem; }
        .assignment-meta { display:flex; gap:1rem; flex-wrap:wrap; font-size:.8rem; color:var(--text-muted); align-items:center; }
        .deadline-text { color:var(--color-warning); }
        .deadline-text.overdue { color:var(--color-error); }
        .progress-bar-wrap { height:4px; background:rgba(255,255,255,.06); border-radius:99px; margin-top:.6rem; overflow:hidden; width:180px; max-width:100%; }
        .progress-bar-fill { height:100%; background:var(--grad-primary); border-radius:99px; }
        .action-btns { display:flex; gap:.4rem; }
        .btn-sm { padding:.4rem .85rem; font-size:.82rem; border-radius:6px; }

        /* Matkul list for kelola */
        .matkul-list-item { display:flex; justify-content:space-between; align-items:center; padding:.9rem 1.25rem; border-radius:10px; background:rgba(15,18,35,.5); border:1px solid var(--border-color); margin-bottom:.6rem; gap:1rem; flex-wrap:wrap; }
        .matkul-list-info { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }

        /* Filter bar */
        .filter-bar { display:flex; gap:.6rem; flex-wrap:wrap; margin-bottom:1.5rem; align-items:center; }
        .filter-label { color:var(--text-muted); font-size:.88rem; }

        /* Empty state */
        .empty-state { text-align:center; padding:3.5rem 2rem; color:var(--text-muted); }
        .empty-icon { font-size:3rem; margin-bottom:.75rem; }

        /* Detail panel */
        .detail-panel { position:fixed; top:0; right:0; width:480px; max-width:100%; height:100vh; background:rgba(7,9,19,.97); backdrop-filter:blur(20px); border-left:1px solid var(--border-color); z-index:200; overflow-y:auto; padding:2rem; transform:translateX(100%); transition:transform .35s cubic-bezier(.4,0,.2,1); }
        .detail-panel.open { transform:translateX(0); }
        .detail-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); backdrop-filter:blur(4px); z-index:199; display:none; }
        .detail-overlay.open { display:block; }
        .sub-row { display:flex; justify-content:space-between; align-items:center; padding:.75rem 0; border-bottom:1px solid var(--border-color); gap:.5rem; flex-wrap:wrap; }
        .sub-row:last-child { border-bottom:none; }
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
            <?php foreach ($detail_submissions as $sub): ?>
            <div class="sub-row">
                <div>
                    <div style="font-weight:500;font-size:.93rem;"><?= htmlspecialchars($sub['nama_lengkap']) ?></div>
                    <div style="font-size:.78rem;color:var(--text-muted);">NIM: <?= htmlspecialchars($sub['nomor_induk']) ?></div>
                    <div style="font-size:.75rem;color:var(--text-muted-dark);">📎 <?= htmlspecialchars($sub['nama_file']) ?></div>
                    <div style="font-size:.73rem;color:var(--text-muted-dark);">⏱ <?= format_tanggal($sub['waktu_unggah']) ?></div>
                </div>
                <a href="download.php?sub_id=<?= $sub['id'] ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;font-size:.78rem;white-space:nowrap;">⬇ Unduh</a>
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
<?php if (isset($_POST['action'])): ?>
    <?php if (in_array($_POST['action'], ['add_semester','delete_semester','toggle_semester'])): ?>
        switchTab('semester');
    <?php elseif (in_array($_POST['action'], ['add_matkul','delete_matkul'])): ?>
        switchTab('matkul');
    <?php elseif ($_POST['action'] === 'add_assignment'): ?>
        switchTab('buat-tugas');
    <?php endif; ?>
<?php endif; ?>
</script>
</body>
</html>
