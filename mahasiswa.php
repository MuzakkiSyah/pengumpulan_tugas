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
    
    // Validasi assignment ada dan belum lewat deadline
    $stmt = $pdo->prepare("SELECT * FROM assignments WHERE id = ?");
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
        
        // Validasi tipe file
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
            // Buat folder uploads jika belum ada
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Buat nama file yang unik dan aman
            $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
            $unique_name = 'A' . $assignment_id . '_U' . $user_id . '_' . time() . '_' . $safe_name;
            $dest_path = $upload_dir . $unique_name;
            
            if (move_uploaded_file($file['tmp_name'], $dest_path)) {
                try {
                    // Hapus file lama jika ada pengumpulan sebelumnya (re-submit)
                    $stmt = $pdo->prepare("SELECT path_file FROM submissions WHERE id_assignment = ? AND id_mahasiswa = ?");
                    $stmt->execute([$assignment_id, $user_id]);
                    $old_sub = $stmt->fetch();
                    if ($old_sub && file_exists($old_sub['path_file'])) {
                        unlink($old_sub['path_file']);
                    }
                    
                    // Simpan atau update data di database (UPSERT)
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

// Ambil semua semester aktif
$semesters = $pdo->query("SELECT * FROM semesters WHERE status = 'aktif' ORDER BY created_at DESC")->fetchAll();

// Ambil tugas beserta status pengumpulan mahasiswa ini
if ($filter_semester > 0) {
    $stmt = $pdo->prepare("
        SELECT a.*, s.nama_semester, s.status as status_semester,
               sub.id as sub_id, sub.nama_file, sub.waktu_unggah, sub.path_file
        FROM assignments a
        LEFT JOIN semesters s ON a.id_semester = s.id
        LEFT JOIN submissions sub ON sub.id_assignment = a.id AND sub.id_mahasiswa = ?
        WHERE a.id_semester = ?
        ORDER BY a.deadline ASC
    ");
    $stmt->execute([$user_id, $filter_semester]);
} else {
    $stmt = $pdo->prepare("
        SELECT a.*, s.nama_semester, s.status as status_semester,
               sub.id as sub_id, sub.nama_file, sub.waktu_unggah, sub.path_file
        FROM assignments a
        LEFT JOIN semesters s ON a.id_semester = s.id
        LEFT JOIN submissions sub ON sub.id_assignment = a.id AND sub.id_mahasiswa = ?
        WHERE s.status = 'aktif'
        ORDER BY a.deadline ASC
    ");
    $stmt->execute([$user_id]);
}
$assignments = $stmt->fetchAll();

// Statistik ringkas
$total_tugas = count($assignments);
$total_sudah_kumpul = 0;
$total_tepat_waktu = 0;
foreach ($assignments as $a) {
    if ($a['sub_id']) {
        $total_sudah_kumpul++;
        if (strtotime($a['waktu_unggah']) <= strtotime($a['deadline'])) {
            $total_tepat_waktu++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Mahasiswa - KumpulTugas</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem; }
        @media(max-width: 600px) { .stats-grid { grid-template-columns: 1fr; } }
        .stat-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem; text-align: center; }
        .stat-num { font-size: 2.5rem; font-weight: 700; font-family: 'Outfit', sans-serif; }
        .stat-num.primary { background: var(--grad-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .stat-num.success { color: var(--color-success); }
        .stat-num.warning { color: var(--color-warning); }
        .stat-desc { color: var(--text-muted); font-size: 0.85rem; margin-top: 0.25rem; }
        
        .tugas-card {
            background: rgba(15, 18, 35, 0.65);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            transition: all 0.3s ease;
        }
        .tugas-card:hover { transform: translateY(-2px); border-color: rgba(79, 172, 254, 0.2); }
        .tugas-card.submitted { border-left: 3px solid var(--color-success); }
        .tugas-card.overdue-notsubmit { border-left: 3px solid var(--color-error); opacity: 0.7; }
        
        .tugas-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
        .tugas-title { font-size: 1.1rem; font-weight: 600; }
        .tugas-status { font-size: 0.8rem; font-weight: 600; padding: 0.3rem 0.8rem; border-radius: 99px; white-space: nowrap; }
        .status-collected { background: rgba(16,185,129,0.15); color: var(--color-success); border: 1px solid rgba(16,185,129,0.25); }
        .status-pending { background: rgba(245,158,11,0.15); color: var(--color-warning); border: 1px solid rgba(245,158,11,0.25); }
        .status-overdue { background: rgba(239,68,68,0.1); color: var(--color-error); border: 1px solid rgba(239,68,68,0.2); }
        
        .tugas-desc { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem; line-height: 1.6; }
        .tugas-meta { display: flex; gap: 1.25rem; flex-wrap: wrap; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.25rem; }
        .tugas-meta span { display: flex; align-items: center; gap: 0.3rem; }
        
        .upload-section { border-top: 1px solid var(--border-color); padding-top: 1.25rem; margin-top: 0.5rem; }
        .uploaded-file { 
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            background: rgba(16,185,129,0.05); border: 1px solid rgba(16,185,129,0.15);
            border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; flex-wrap: wrap;
        }
        .file-info { display: flex; align-items: center; gap: 0.6rem; }
        .file-icon { font-size: 1.4rem; }
        .file-name { font-size: 0.9rem; font-weight: 500; }
        .file-time { font-size: 0.75rem; color: var(--text-muted); }
        
        .upload-form { display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap; }
        .file-input-wrapper { flex: 1; min-width: 200px; }
        
        .countdown { font-weight: 600; }
        .countdown.urgent { color: var(--color-error); animation: pulse 1.5s infinite; }
        .countdown.soon { color: var(--color-warning); }
        .countdown.safe { color: var(--color-success); }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .empty-state { text-align: center; padding: 4rem 2rem; }
        .empty-icon { font-size: 4rem; margin-bottom: 1rem; }
        .empty-title { font-size: 1.3rem; font-weight: 600; margin-bottom: 0.5rem; }
        .empty-desc { color: var(--text-muted); font-size: 0.95rem; }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="navbar-container">
        <span class="navbar-brand">🎓 KumpulTugas</span>
        <div class="navbar-user">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['nama_lengkap']); ?></div>
                <div class="user-role">Mahasiswa · NIM: <?php echo htmlspecialchars($_SESSION['nomor_induk']); ?></div>
            </div>
            <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="main-content">

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <?php echo $message_type === 'success' ? '✅' : '⚠️'; ?> <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="dashboard-header">
        <div>
            <h1 class="dashboard-title">Halo, <?php echo htmlspecialchars(explode(' ', $_SESSION['nama_lengkap'])[0]); ?>! 👋</h1>
            <p class="dashboard-subtitle">Kumpulkan tugas-tugas Anda tepat waktu. Pantau status pengumpulan di sini.</p>
        </div>
    </div>

    <!-- Statistik -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-num primary"><?php echo $total_tugas; ?></div>
            <div class="stat-desc">Total Tugas Aktif</div>
        </div>
        <div class="stat-card">
            <div class="stat-num success"><?php echo $total_sudah_kumpul; ?></div>
            <div class="stat-desc">Sudah Dikumpulkan</div>
        </div>
        <div class="stat-card">
            <div class="stat-num warning"><?php echo $total_tugas - $total_sudah_kumpul; ?></div>
            <div class="stat-desc">Belum Dikumpulkan</div>
        </div>
    </div>

    <!-- Filter Semester -->
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:2rem; align-items:center;">
        <span style="color:var(--text-muted); font-size:0.9rem;">📅 Semester:</span>
        <a href="mahasiswa.php" class="btn btn-sm <?php echo !$filter_semester ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration:none;">Semua Aktif</a>
        <?php foreach ($semesters as $sem): ?>
            <a href="mahasiswa.php?semester=<?php echo $sem['id']; ?>" 
               class="btn btn-sm <?php echo $filter_semester == $sem['id'] ? 'btn-primary' : 'btn-secondary'; ?>"
               style="text-decoration:none;">
                <?php echo htmlspecialchars($sem['nama_semester']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Daftar Tugas -->
    <?php if (empty($assignments)): ?>
        <div class="glass-panel">
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <div class="empty-title">Belum Ada Tugas</div>
                <div class="empty-desc">Tidak ada tugas aktif saat ini. Tunggu laboran membuat slot tugas baru.</div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($assignments as $a):
            $now = time();
            $deadline_ts = strtotime($a['deadline']);
            $is_overdue = $deadline_ts < $now;
            $is_submitted = !empty($a['sub_id']);
            $diff_sec = $deadline_ts - $now;
            
            // Countdown text
            if ($is_overdue) {
                $countdown_text = 'Deadline sudah lewat';
                $countdown_class = 'urgent';
            } elseif ($diff_sec < 3600) {
                $minutes = ceil($diff_sec / 60);
                $countdown_text = "Sisa: $minutes menit!";
                $countdown_class = 'urgent';
            } elseif ($diff_sec < 86400) {
                $hours = ceil($diff_sec / 3600);
                $countdown_text = "Sisa: $hours jam";
                $countdown_class = 'soon';
            } else {
                $days = ceil($diff_sec / 86400);
                $countdown_text = "Sisa: $days hari";
                $countdown_class = 'safe';
            }
            
            $card_class = $is_submitted ? 'submitted' : ($is_overdue ? 'overdue-notsubmit' : '');
        ?>
        <div class="tugas-card <?php echo $card_class; ?>">
            <div class="tugas-header">
                <div class="tugas-title"><?php echo htmlspecialchars($a['judul']); ?></div>
                <div>
                    <?php if ($is_submitted): ?>
                        <span class="tugas-status status-collected">✅ Sudah Dikumpulkan</span>
                    <?php elseif ($is_overdue): ?>
                        <span class="tugas-status status-overdue">❌ Deadline Lewat</span>
                    <?php else: ?>
                        <span class="tugas-status status-pending">⏳ Belum Dikumpulkan</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!empty($a['deskripsi'])): ?>
                <p class="tugas-desc"><?php echo nl2br(htmlspecialchars($a['deskripsi'])); ?></p>
            <?php endif; ?>
            
            <div class="tugas-meta">
                <span>📅 <?php echo htmlspecialchars($a['nama_semester']); ?></span>
                <span>⏰ <?php echo format_tanggal($a['deadline']); ?></span>
                <?php if (!empty($a['tipe_file']) && $a['tipe_file'] !== 'all'): ?>
                    <span>📎 Format: <?php echo htmlspecialchars(strtoupper(str_replace(',', ', ', $a['tipe_file']))); ?></span>
                <?php else: ?>
                    <span>📎 Semua format file</span>
                <?php endif; ?>
                <span class="countdown <?php echo $countdown_class; ?>">🕐 <?php echo $countdown_text; ?></span>
            </div>
            
            <!-- Bagian Upload/Status -->
            <div class="upload-section">
                <?php if ($is_submitted): ?>
                    <!-- File sudah diunggah -->
                    <div class="uploaded-file">
                        <div class="file-info">
                            <span class="file-icon">📄</span>
                            <div>
                                <div class="file-name"><?php echo htmlspecialchars($a['nama_file']); ?></div>
                                <div class="file-time">Dikumpulkan: <?php echo format_tanggal($a['waktu_unggah']); ?></div>
                            </div>
                        </div>
                        <a href="download.php?sub_id=<?php echo $a['sub_id']; ?>" class="btn btn-secondary btn-sm" style="text-decoration:none; font-size:0.8rem;">⬇ Unduh</a>
                    </div>
                    <?php if (!$is_overdue): ?>
                        <!-- Opsi ganti file -->
                        <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:0.75rem;">Ingin mengganti file? Upload ulang di bawah ini:</p>
                        <form method="POST" enctype="multipart/form-data" class="upload-form">
                            <input type="hidden" name="action" value="upload">
                            <input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                            <div class="file-input-wrapper">
                                <input type="file" name="file_tugas" class="form-input" required style="padding:0.6rem;">
                            </div>
                            <button type="submit" class="btn btn-secondary btn-sm">🔄 Ganti File</button>
                        </form>
                    <?php endif; ?>
                <?php elseif (!$is_overdue): ?>
                    <!-- Form Upload -->
                    <form method="POST" enctype="multipart/form-data" class="upload-form">
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                        <div class="file-input-wrapper">
                            <input type="file" name="file_tugas" class="form-input" required style="padding:0.6rem;" 
                                <?php if (!empty($a['tipe_file']) && $a['tipe_file'] !== 'all'): ?>
                                    accept=".<?php echo str_replace(',', ',.', strtolower($a['tipe_file'])); ?>"
                                <?php endif; ?>>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">⬆ Kumpulkan Tugas</button>
                    </form>
                <?php else: ?>
                    <!-- Deadline lewat, belum kumpul -->
                    <div style="color:var(--color-error); font-size:0.9rem; font-style:italic;">
                        ❌ Anda tidak mengumpulkan tugas ini sebelum deadline.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<footer>
    <p>KumpulTugas &copy; 2026 — Sistem Pengumpulan Tugas Mahasiswa</p>
</footer>

</body>
</html>
