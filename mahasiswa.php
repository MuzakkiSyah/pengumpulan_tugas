<?php
require_once 'config.php';
check_login('mahasiswa');

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// =====================================================================
// AKSI: Upload Tugas
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    $assignment_id = (int)$_POST['assignment_id'];

    $stmt = $pdo->prepare("SELECT a.*, mk.nama_matkul FROM assignments a JOIN mata_kuliah mk ON a.id_matkul = mk.id WHERE a.id = ?");
    $stmt->execute([$assignment_id]);
    $assignment = $stmt->fetch();

    if (!$assignment) {
        $message = 'Tugas tidak ditemukan!';
        $message_type = 'error';
    } elseif (strtotime($assignment['deadline']) < time()) {
        $message = 'Deadline sudah lewat! Anda tidak dapat mengumpulkan tugas ini.';
        $message_type = 'error';
    } elseif (!isset($_FILES['file_tugas']) || $_FILES['file_tugas']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Harap pilih file yang valid untuk diunggah!';
        $message_type = 'error';
    } else {
        $file = $_FILES['file_tugas'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $allowed_types = [];
        if (!empty($assignment['tipe_file']) && $assignment['tipe_file'] !== 'all') {
            $allowed_types = array_map('trim', explode(',', strtolower($assignment['tipe_file'])));
        }

        if (!empty($allowed_types) && !in_array($file_ext, $allowed_types)) {
            $message = 'Tipe file tidak diizinkan! File yang boleh: ' . implode(', ', $allowed_types);
            $message_type = 'error';
        } elseif ($file['size'] > 20 * 1024 * 1024) {
            $message = 'Ukuran file maksimal 20MB!';
            $message_type = 'error';
        } else {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $safe_name  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
            $unique_name = 'A' . $assignment_id . '_U' . $user_id . '_' . time() . '_' . $safe_name;
            $dest_path  = $upload_dir . $unique_name;

            if (move_uploaded_file($file['tmp_name'], $dest_path)) {
                try {
                    $stmt = $pdo->prepare("SELECT path_file FROM submissions WHERE id_assignment = ? AND id_mahasiswa = ?");
                    $stmt->execute([$assignment_id, $user_id]);
                    $old_sub = $stmt->fetch();
                    if ($old_sub && file_exists($old_sub['path_file'])) unlink($old_sub['path_file']);

                    $stmt = $pdo->prepare("
                        INSERT INTO submissions (id_assignment, id_mahasiswa, nama_file, path_file, waktu_unggah)
                        VALUES (?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE nama_file = VALUES(nama_file), path_file = VALUES(path_file), waktu_unggah = NOW()
                    ");
                    $stmt->execute([$assignment_id, $user_id, $file['name'], $dest_path]);
                    $message = 'Tugas berhasil dikumpulkan! 🎉';
                    $message_type = 'success';
                } catch (PDOException $e) {
                    $message = 'Gagal menyimpan data: ' . $e->getMessage();
                    $message_type = 'error';
                    if (file_exists($dest_path)) unlink($dest_path);
                }
            } else {
                $message = 'Gagal mengunggah file. Periksa izin folder uploads/.';
                $message_type = 'error';
            }
        }
    }
}

// =====================================================================
// AMBIL DATA
// =====================================================================
$filter_semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$filter_matkul   = isset($_GET['matkul'])   ? (int)$_GET['matkul']   : 0;

// Semester aktif
$semesters = $pdo->query("SELECT * FROM semesters WHERE status = 'aktif' ORDER BY created_at DESC")->fetchAll();

// Mata kuliah aktif (untuk filter)
if ($filter_semester > 0) {
    $stmt = $pdo->prepare("SELECT mk.* FROM mata_kuliah mk JOIN semesters s ON mk.id_semester = s.id WHERE mk.id_semester = ? AND s.status = 'aktif' ORDER BY mk.kode_matkul ASC");
    $stmt->execute([$filter_semester]);
} else {
    $stmt = $pdo->query("SELECT mk.* FROM mata_kuliah mk JOIN semesters s ON mk.id_semester = s.id WHERE s.status = 'aktif' ORDER BY mk.kode_matkul ASC");
}
$all_matkul = $stmt->fetchAll();

// Bangun WHERE clause untuk tugas
$where_clauses = ["s.status = 'aktif'"];
$params = [$user_id];
if ($filter_matkul > 0) {
    $where_clauses[] = "a.id_matkul = ?";
    $params[] = $filter_matkul;
} elseif ($filter_semester > 0) {
    $where_clauses[] = "mk.id_semester = ?";
    $params[] = $filter_semester;
}
$where_sql = implode(' AND ', $where_clauses);

$stmt = $pdo->prepare("
    SELECT a.*, mk.nama_matkul, mk.kode_matkul, mk.id as mk_id,
           s.nama_semester,
           sub.id as sub_id, sub.nama_file, sub.waktu_unggah, sub.path_file
    FROM assignments a
    JOIN mata_kuliah mk ON a.id_matkul = mk.id
    JOIN semesters s ON mk.id_semester = s.id
    LEFT JOIN submissions sub ON sub.id_assignment = a.id AND sub.id_mahasiswa = ?
    WHERE $where_sql
    ORDER BY mk.kode_matkul ASC, a.deadline ASC
");
$stmt->execute($params);
$all_assignments = $stmt->fetchAll();

// Kelompokkan per matkul
$grouped = [];
foreach ($all_assignments as $a) {
    $grouped[$a['mk_id']]['info'] = [
        'kode_matkul'   => $a['kode_matkul'],
        'nama_matkul'   => $a['nama_matkul'],
        'nama_semester' => $a['nama_semester'],
    ];
    $grouped[$a['mk_id']]['tugas'][] = $a;
}

// Statistik
$total_tugas = count($all_assignments);
$total_kumpul = 0;
foreach ($all_assignments as $a) { if ($a['sub_id']) $total_kumpul++; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Mahasiswa - KumpulTugas</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .stats-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:2rem; }
        @media(max-width:600px) { .stats-grid { grid-template-columns:1fr; } }
        .stat-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:1.5rem; text-align:center; }
        .stat-num { font-size:2.2rem; font-weight:700; font-family:'Outfit',sans-serif; }
        .stat-num.primary { background:var(--grad-primary); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
        .stat-num.success { color:var(--color-success); }
        .stat-num.warning { color:var(--color-warning); }
        .stat-desc { color:var(--text-muted); font-size:.82rem; margin-top:.25rem; }

        /* Matkul group */
        .matkul-group { margin-bottom:2.5rem; }
        .matkul-header {
            display:flex; justify-content:space-between; align-items:center;
            padding:1rem 1.5rem;
            background:linear-gradient(135deg,rgba(138,43,226,.14),rgba(255,0,127,.06));
            border:1px solid rgba(138,43,226,.2);
            border-radius:14px 14px 0 0;
            gap:1rem; flex-wrap:wrap;
        }
        .matkul-title { font-size:1.05rem; font-weight:700; display:flex; align-items:center; gap:.6rem; }
        .matkul-code { background:rgba(138,43,226,.15); color:#c084fc; border:1px solid rgba(138,43,226,.25); padding:.2rem .6rem; border-radius:6px; font-size:.8rem; font-family:'Outfit',sans-serif; font-weight:700; }
        .matkul-progress { display:flex; align-items:center; gap:.6rem; font-size:.82rem; color:var(--text-muted); }
        .matkul-progress-bar { width:80px; height:5px; background:rgba(255,255,255,.07); border-radius:99px; overflow:hidden; }
        .matkul-progress-fill { height:100%; background:var(--grad-primary); border-radius:99px; }
        .matkul-body { border:1px solid rgba(138,43,226,.15); border-top:none; border-radius:0 0 14px 14px; overflow:hidden; }

        /* Tugas card dalam matkul */
        .tugas-row { padding:1.25rem 1.5rem; border-bottom:1px solid var(--border-color); transition:background .2s; }
        .tugas-row:last-child { border-bottom:none; }
        .tugas-row:hover { background:rgba(255,255,255,.015); }
        .tugas-header-row { display:flex; justify-content:space-between; align-items:flex-start; gap:.75rem; margin-bottom:.6rem; }
        .tugas-title { font-weight:600; font-size:.98rem; }
        .tugas-status { font-size:.75rem; font-weight:600; padding:.25rem .7rem; border-radius:99px; white-space:nowrap; }
        .status-collected { background:rgba(16,185,129,.15); color:var(--color-success); border:1px solid rgba(16,185,129,.25); }
        .status-pending { background:rgba(245,158,11,.15); color:var(--color-warning); border:1px solid rgba(245,158,11,.25); }
        .status-overdue { background:rgba(239,68,68,.1); color:var(--color-error); border:1px solid rgba(239,68,68,.2); }
        .tugas-desc { font-size:.85rem; color:var(--text-muted); margin-bottom:.75rem; line-height:1.6; }
        .tugas-meta { display:flex; gap:1rem; flex-wrap:wrap; font-size:.8rem; color:var(--text-muted); margin-bottom:.9rem; align-items:center; }
        .countdown { font-weight:600; }
        .countdown.urgent { color:var(--color-error); }
        .countdown.soon { color:var(--color-warning); }
        .countdown.safe { color:var(--color-success); }

        .uploaded-file { display:flex; align-items:center; justify-content:space-between; gap:1rem; background:rgba(16,185,129,.05); border:1px solid rgba(16,185,129,.15); border-radius:8px; padding:.75rem 1rem; margin-bottom:.75rem; flex-wrap:wrap; }
        .upload-form { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; }
        .file-input-wrapper { flex:1; min-width:200px; }

        .filter-bar { display:flex; gap:.6rem; flex-wrap:wrap; margin-bottom:1.5rem; align-items:center; }
        .filter-label { color:var(--text-muted); font-size:.88rem; }
        .btn-sm { padding:.4rem .85rem; font-size:.82rem; border-radius:6px; }
        .empty-state { text-align:center; padding:4rem 2rem; }
        .empty-icon { font-size:3.5rem; margin-bottom:1rem; }
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
                <div class="user-role">Mahasiswa · NIM: <?= htmlspecialchars($_SESSION['nomor_induk']) ?></div>
            </div>
            <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="main-content">

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <?= $message_type === 'success' ? '✅' : '⚠️' ?> <?= $message ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-header">
        <div>
            <h1 class="dashboard-title">Halo, <?= htmlspecialchars(explode(' ', $_SESSION['nama_lengkap'])[0]) ?>! 👋</h1>
            <p class="dashboard-subtitle">Kumpulkan tugas per mata kuliah tepat waktu.</p>
        </div>
    </div>

    <!-- Statistik -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-num primary"><?= $total_tugas ?></div><div class="stat-desc">Total Tugas Aktif</div></div>
        <div class="stat-card"><div class="stat-num success"><?= $total_kumpul ?></div><div class="stat-desc">Sudah Dikumpulkan</div></div>
        <div class="stat-card"><div class="stat-num warning"><?= $total_tugas - $total_kumpul ?></div><div class="stat-desc">Belum Dikumpulkan</div></div>
    </div>

    <!-- Filter Semester -->
    <div class="filter-bar">
        <span class="filter-label">📅 Semester:</span>
        <a href="mahasiswa.php" class="btn btn-sm <?= !$filter_semester ? 'btn-primary' : 'btn-secondary' ?>" style="text-decoration:none;">Semua</a>
        <?php foreach ($semesters as $sem): ?>
            <a href="mahasiswa.php?semester=<?= $sem['id'] ?>" class="btn btn-sm <?= $filter_semester == $sem['id'] ? 'btn-primary' : 'btn-secondary' ?>" style="text-decoration:none;">
                <?= htmlspecialchars($sem['nama_semester']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if ($filter_semester && !empty($all_matkul)): ?>
    <div class="filter-bar" style="margin-top:-.5rem;">
        <span class="filter-label">📁 Matkul:</span>
        <a href="mahasiswa.php?semester=<?= $filter_semester ?>" class="btn btn-sm <?= !$filter_matkul ? 'btn-primary' : 'btn-secondary' ?>" style="text-decoration:none;">Semua</a>
        <?php foreach ($all_matkul as $mk): ?>
            <a href="mahasiswa.php?semester=<?= $filter_semester ?>&matkul=<?= $mk['id'] ?>" class="btn btn-sm <?= $filter_matkul == $mk['id'] ? 'btn-primary' : 'btn-secondary' ?>" style="text-decoration:none;">
                <?= htmlspecialchars($mk['kode_matkul']) ?> — <?= htmlspecialchars($mk['nama_matkul']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Daftar Tugas Per Matkul -->
    <?php if (empty($grouped)): ?>
        <div class="glass-panel">
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <p style="font-size:1rem;color:var(--text-muted);">Belum ada tugas aktif saat ini.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($grouped as $mk_id => $group):
            $tugas_list = $group['tugas'];
            $kumpul_count = count(array_filter($tugas_list, fn($t) => $t['sub_id']));
            $pct_matkul = count($tugas_list) > 0 ? round(($kumpul_count / count($tugas_list)) * 100) : 0;
        ?>
        <div class="matkul-group">
            <!-- Header Folder Matkul -->
            <div class="matkul-header">
                <div class="matkul-title">
                    <span class="matkul-code"><?= htmlspecialchars($group['info']['kode_matkul']) ?></span>
                    📁 <?= htmlspecialchars($group['info']['nama_matkul']) ?>
                    <span style="font-size:.8rem;color:var(--text-muted);font-weight:400;">— <?= htmlspecialchars($group['info']['nama_semester']) ?></span>
                </div>
                <div class="matkul-progress">
                    <span><?= $kumpul_count ?>/<?= count($tugas_list) ?> terkumpul</span>
                    <div class="matkul-progress-bar"><div class="matkul-progress-fill" style="width:<?= $pct_matkul ?>%"></div></div>
                    <span><?= $pct_matkul ?>%</span>
                </div>
            </div>
            <!-- Body: Daftar Tugas -->
            <div class="matkul-body">
                <?php foreach ($tugas_list as $a):
                    $now = time();
                    $deadline_ts = strtotime($a['deadline']);
                    $is_overdue  = $deadline_ts < $now;
                    $is_submitted = !empty($a['sub_id']);
                    $diff_sec = $deadline_ts - $now;

                    if ($is_overdue) { $ct_text = 'Deadline sudah lewat'; $ct_class = 'urgent'; }
                    elseif ($diff_sec < 3600) { $ct_text = 'Sisa: ' . ceil($diff_sec/60) . ' menit!'; $ct_class = 'urgent'; }
                    elseif ($diff_sec < 86400) { $ct_text = 'Sisa: ' . ceil($diff_sec/3600) . ' jam'; $ct_class = 'soon'; }
                    else { $ct_text = 'Sisa: ' . ceil($diff_sec/86400) . ' hari'; $ct_class = 'safe'; }
                ?>
                <div class="tugas-row">
                    <div class="tugas-header-row">
                        <div class="tugas-title"><?= htmlspecialchars($a['judul']) ?></div>
                        <?php if ($is_submitted): ?>
                            <span class="tugas-status status-collected">✅ Sudah Dikumpulkan</span>
                        <?php elseif ($is_overdue): ?>
                            <span class="tugas-status status-overdue">❌ Deadline Lewat</span>
                        <?php else: ?>
                            <span class="tugas-status status-pending">⏳ Belum Dikumpulkan</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($a['deskripsi'])): ?>
                        <p class="tugas-desc"><?= nl2br(htmlspecialchars($a['deskripsi'])) ?></p>
                    <?php endif; ?>

                    <div class="tugas-meta">
                        <span>⏰ <?= format_tanggal($a['deadline']) ?></span>
                        <?php if (!empty($a['tipe_file']) && $a['tipe_file'] !== 'all'): ?>
                            <span>📎 <?= htmlspecialchars(strtoupper(str_replace(',', ', ', $a['tipe_file']))) ?></span>
                        <?php else: ?>
                            <span>📎 Semua format</span>
                        <?php endif; ?>
                        <span class="countdown <?= $ct_class ?>">🕐 <?= $ct_text ?></span>
                    </div>

                    <!-- Upload area -->
                    <?php if ($is_submitted): ?>
                        <div class="uploaded-file">
                            <div style="display:flex;align-items:center;gap:.6rem;">
                                <span style="font-size:1.4rem;">📄</span>
                                <div>
                                    <div style="font-weight:500;font-size:.9rem;"><?= htmlspecialchars($a['nama_file']) ?></div>
                                    <div style="font-size:.75rem;color:var(--text-muted);">Dikumpulkan: <?= format_tanggal($a['waktu_unggah']) ?></div>
                                </div>
                            </div>
                            <a href="download.php?sub_id=<?= $a['sub_id'] ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;font-size:.78rem;">⬇ Unduh</a>
                        </div>
                        <?php if (!$is_overdue): ?>
                            <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:.6rem;">Ingin mengganti file?</p>
                            <form method="POST" enctype="multipart/form-data" class="upload-form">
                                <input type="hidden" name="action" value="upload">
                                <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                <div class="file-input-wrapper"><input type="file" name="file_tugas" class="form-input" required style="padding:.5rem;"></div>
                                <button type="submit" class="btn btn-secondary btn-sm">🔄 Ganti File</button>
                            </form>
                        <?php endif; ?>
                    <?php elseif (!$is_overdue): ?>
                        <form method="POST" enctype="multipart/form-data" class="upload-form">
                            <input type="hidden" name="action" value="upload">
                            <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                            <div class="file-input-wrapper">
                                <input type="file" name="file_tugas" class="form-input" required style="padding:.5rem;"
                                    <?php if (!empty($a['tipe_file']) && $a['tipe_file'] !== 'all'): ?>
                                        accept=".<?= str_replace(',', ',.', strtolower($a['tipe_file'])) ?>"
                                    <?php endif; ?>>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">⬆ Kumpulkan Tugas</button>
                        </form>
                    <?php else: ?>
                        <div style="color:var(--color-error);font-size:.88rem;font-style:italic;">❌ Tidak mengumpulkan sebelum deadline.</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<footer><p>KumpulTugas &copy; 2026 — Sistem Pengumpulan Tugas Mahasiswa</p></footer>
</body>
</html>
