<?php
require_once 'config.php';
check_login('laboran');

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// =====================================================================
// AKSI: Tambah Semester
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

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
                $message = 'Semester sudah ada atau terjadi kesalahan: ' . $e->getMessage();
                $message_type = 'error';
            }
        } else {
            $message = 'Nama semester tidak boleh kosong!';
            $message_type = 'error';
        }
    }
    
    // =====================================================================
    // AKSI: Tambah Tugas
    // =====================================================================
    elseif ($_POST['action'] === 'add_assignment') {
        $judul = clean_input($_POST['judul']);
        $deskripsi = clean_input($_POST['deskripsi']);
        $id_semester = (int)$_POST['id_semester'];
        $deadline = clean_input($_POST['deadline']);
        $tipe_file = clean_input($_POST['tipe_file']);
        
        if (!empty($judul) && !empty($id_semester) && !empty($deadline)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO assignments (id_semester, judul, deskripsi, deadline, tipe_file, dibuat_oleh) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id_semester, $judul, $deskripsi, $deadline, $tipe_file, $user_id]);
                $message = 'Tugas berhasil dibuat!';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Gagal membuat tugas: ' . $e->getMessage();
                $message_type = 'error';
            }
        } else {
            $message = 'Judul, semester, dan deadline wajib diisi!';
            $message_type = 'error';
        }
    }

    // =====================================================================
    // AKSI: Hapus Tugas
    // =====================================================================
    elseif ($_POST['action'] === 'delete_assignment') {
        $assignment_id = (int)$_POST['assignment_id'];
        try {
            // Ambil data file untuk dihapus dari server
            $stmt = $pdo->prepare("SELECT path_file FROM submissions WHERE id_assignment = ?");
            $stmt->execute([$assignment_id]);
            $subs = $stmt->fetchAll();
            foreach ($subs as $sub) {
                if (file_exists($sub['path_file'])) {
                    unlink($sub['path_file']);
                }
            }
            $stmt = $pdo->prepare("DELETE FROM assignments WHERE id = ? AND dibuat_oleh = ?");
            $stmt->execute([$assignment_id, $user_id]);
            $message = 'Tugas berhasil dihapus!';
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'Gagal menghapus: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
    
    // =====================================================================
    // AKSI: Hapus Semester
    // =====================================================================
    elseif ($_POST['action'] === 'delete_semester') {
        $semester_id = (int)$_POST['semester_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM semesters WHERE id = ?");
            $stmt->execute([$semester_id]);
            $message = 'Semester berhasil dihapus!';
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'Gagal menghapus semester: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
    
    // =====================================================================
    // AKSI: Toggle Status Semester
    // =====================================================================
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
}

// =====================================================================
// AMBIL DATA
// =====================================================================
$filter_semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;

// Ambil semua semester
$semesters = $pdo->query("SELECT * FROM semesters ORDER BY created_at DESC")->fetchAll();

// Ambil tugas berdasarkan filter
if ($filter_semester > 0) {
    $stmt = $pdo->prepare("
        SELECT a.*, s.nama_semester, u.nama_lengkap as pembuat,
               COUNT(sub.id) as jumlah_pengumpul
        FROM assignments a 
        LEFT JOIN semesters s ON a.id_semester = s.id 
        LEFT JOIN users u ON a.dibuat_oleh = u.id
        LEFT JOIN submissions sub ON sub.id_assignment = a.id
        WHERE a.id_semester = ?
        GROUP BY a.id
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$filter_semester]);
} else {
    $stmt = $pdo->query("
        SELECT a.*, s.nama_semester, u.nama_lengkap as pembuat,
               COUNT(sub.id) as jumlah_pengumpul
        FROM assignments a 
        LEFT JOIN semesters s ON a.id_semester = s.id 
        LEFT JOIN users u ON a.dibuat_oleh = u.id
        LEFT JOIN submissions sub ON sub.id_assignment = a.id
        GROUP BY a.id
        ORDER BY a.created_at DESC
    ");
}
$assignments = $stmt->fetchAll();

// Ambil semua mahasiswa yang sudah submit (untuk detail panel)
$detail_assignment_id = isset($_GET['detail']) ? (int)$_GET['detail'] : 0;
$detail_assignment = null;
$detail_submissions = [];
if ($detail_assignment_id > 0) {
    $stmt = $pdo->prepare("SELECT a.*, s.nama_semester FROM assignments a LEFT JOIN semesters s ON a.id_semester = s.id WHERE a.id = ?");
    $stmt->execute([$detail_assignment_id]);
    $detail_assignment = $stmt->fetch();
    
    $stmt = $pdo->prepare("
        SELECT sub.*, u.nama_lengkap, u.nomor_induk
        FROM submissions sub
        LEFT JOIN users u ON sub.id_mahasiswa = u.id
        WHERE sub.id_assignment = ?
        ORDER BY sub.waktu_unggah ASC
    ");
    $stmt->execute([$detail_assignment_id]);
    $detail_submissions = $stmt->fetchAll();
}

// Statistik
$total_mahasiswa = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'mahasiswa'")->fetchColumn();
$total_assignments = $pdo->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
$total_submissions = $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Laboran - KumpulTugas</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem; }
        @media(max-width: 600px) { .stats-grid { grid-template-columns: 1fr; } }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
        }
        .stat-num { font-size: 2.5rem; font-weight: 700; background: var(--grad-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-family: 'Outfit', sans-serif; }
        .stat-desc { color: var(--text-muted); font-size: 0.85rem; margin-top: 0.25rem; }
        .tab-bar { display: flex; gap: 0.5rem; margin-bottom: 2rem; flex-wrap: wrap; }
        .tab-btn { padding: 0.6rem 1.25rem; border-radius: 8px; border: 1px solid var(--border-color); background: transparent; color: var(--text-muted); cursor: pointer; font-family: 'Inter', sans-serif; font-size: 0.9rem; transition: all 0.3s; }
        .tab-btn.active { background: var(--grad-primary); color: #05070e; border-color: transparent; font-weight: 600; }
        .tab-btn:hover:not(.active) { background: rgba(255,255,255,0.05); color: var(--text-main); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .section-title { font-size: 1.4rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media(max-width: 700px) { .form-row { grid-template-columns: 1fr; } }
        .assignment-card {
            background: rgba(15, 18, 35, 0.6);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }
        .assignment-card:hover { border-color: rgba(79, 172, 254, 0.25); }
        .assignment-info { flex: 1; }
        .assignment-title { font-size: 1.05rem; font-weight: 600; margin-bottom: 0.4rem; }
        .assignment-meta-row { display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; }
        .deadline-text { color: var(--color-warning); font-size: 0.8rem; }
        .deadline-text.overdue { color: var(--color-error); }
        .progress-bar-wrap { height: 5px; background: rgba(255,255,255,0.07); border-radius: 99px; margin-top: 0.75rem; overflow: hidden; width: 100%; }
        .progress-bar-fill { height: 100%; background: var(--grad-primary); border-radius: 99px; transition: width 0.5s ease; }
        .action-btns { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .btn-sm { padding: 0.45rem 0.9rem; font-size: 0.85rem; border-radius: 6px; }
        .semester-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.25rem;
            border-radius: 10px;
            background: rgba(15, 18, 35, 0.5);
            border: 1px solid var(--border-color);
            margin-bottom: 0.75rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .semester-name { font-weight: 600; font-size: 1rem; }
        .detail-panel {
            position: fixed;
            top: 0; right: 0;
            width: 480px;
            max-width: 100%;
            height: 100vh;
            background: rgba(10, 12, 25, 0.97);
            backdrop-filter: blur(20px);
            border-left: 1px solid var(--border-color);
            z-index: 200;
            overflow-y: auto;
            padding: 2rem;
            transform: translateX(100%);
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .detail-panel.open { transform: translateX(0); }
        .detail-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 199;
            display: none;
        }
        .detail-overlay.open { display: block; }
        .sub-row { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color); gap: 0.5rem; flex-wrap: wrap; }
        .sub-row:last-child { border-bottom: none; }
        .sub-name { font-weight: 500; font-size: 0.95rem; }
        .sub-nim { color: var(--text-muted); font-size: 0.8rem; }
        .sub-time { font-size: 0.78rem; color: var(--text-muted-dark); }
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
                <div class="user-role">Asisten Laboran</div>
            </div>
            <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
        </div>
    </div>
</nav>

<!-- Detail Overlay -->
<div class="detail-overlay <?php echo $detail_assignment ? 'open' : ''; ?>" id="detailOverlay" onclick="closeDetail()"></div>
<div class="detail-panel <?php echo $detail_assignment ? 'open' : ''; ?>" id="detailPanel">
    <?php if ($detail_assignment): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
            <h3 style="font-size:1.2rem;">Detail Pengumpulan</h3>
            <a href="laboran.php<?php echo $filter_semester ? '?semester='.$filter_semester : ''; ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;">✕ Tutup</a>
        </div>
        <h4 style="margin-bottom:0.5rem;"><?php echo htmlspecialchars($detail_assignment['judul']); ?></h4>
        <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:0.5rem;"><?php echo $detail_assignment['nama_semester']; ?></p>
        <p style="color:var(--color-warning); font-size:0.85rem; margin-bottom:2rem;">⏰ Deadline: <?php echo format_tanggal($detail_assignment['deadline']); ?></p>
        
        <div style="font-size:0.9rem; font-weight:600; margin-bottom:1rem; color:var(--text-muted);">
            <?php echo count($detail_submissions); ?> Mahasiswa Telah Mengumpulkan
        </div>
        
        <?php if (empty($detail_submissions)): ?>
            <div style="text-align:center; color:var(--text-muted); padding: 3rem 0;">
                <div style="font-size:2rem; margin-bottom:0.5rem;">📭</div>
                <p>Belum ada mahasiswa yang mengumpulkan</p>
            </div>
        <?php else: ?>
            <?php foreach ($detail_submissions as $sub): ?>
                <div class="sub-row">
                    <div>
                        <div class="sub-name"><?php echo htmlspecialchars($sub['nama_lengkap']); ?></div>
                        <div class="sub-nim">NIM: <?php echo htmlspecialchars($sub['nomor_induk']); ?></div>
                        <div class="sub-time">⏱ <?php echo format_tanggal($sub['waktu_unggah']); ?></div>
                    </div>
                    <a href="download.php?id=<?php echo $sub['id']; ?>" class="btn btn-secondary btn-sm" style="white-space:nowrap; text-decoration:none; font-size:0.8rem;">
                        ⬇ Unduh
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Main Content -->
<div class="main-content">

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <?php echo $message_type === 'success' ? '✅' : '⚠️'; ?>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="dashboard-header">
        <div>
            <h1 class="dashboard-title">Dashboard Laboran</h1>
            <p class="dashboard-subtitle">Kelola semester, buat slot tugas, dan pantau pengumpulan mahasiswa.</p>
        </div>
    </div>

    <!-- Statistik -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-num"><?php echo $total_mahasiswa; ?></div>
            <div class="stat-desc">Total Mahasiswa</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?php echo $total_assignments; ?></div>
            <div class="stat-desc">Total Tugas Dibuat</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?php echo $total_submissions; ?></div>
            <div class="stat-desc">Total Pengumpulan</div>
        </div>
    </div>

    <!-- Tab Bar -->
    <div class="tab-bar">
        <button class="tab-btn active" id="tab-btn-tugas" onclick="switchTab('tugas')">📋 Daftar Tugas</button>
        <button class="tab-btn" id="tab-btn-semester" onclick="switchTab('semester')">📅 Kelola Semester</button>
        <button class="tab-btn" id="tab-btn-buat-tugas" onclick="switchTab('buat-tugas')">➕ Buat Tugas Baru</button>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: DAFTAR TUGAS -->
    <!-- ============================================================ -->
    <div class="tab-content active" id="tab-tugas">
        <!-- Filter Semester -->
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.5rem; align-items:center;">
            <span style="color:var(--text-muted); font-size:0.9rem;">Filter Semester:</span>
            <a href="laboran.php" class="btn btn-sm <?php echo !$filter_semester ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration:none;">Semua</a>
            <?php foreach ($semesters as $sem): ?>
                <a href="laboran.php?semester=<?php echo $sem['id']; ?>" 
                   class="btn btn-sm <?php echo $filter_semester == $sem['id'] ? 'btn-primary' : 'btn-secondary'; ?>"
                   style="text-decoration:none;">
                    <?php echo htmlspecialchars($sem['nama_semester']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($assignments)): ?>
            <div class="glass-panel" style="text-align:center; padding:4rem;">
                <div style="font-size:3rem; margin-bottom:1rem;">📝</div>
                <p style="color:var(--text-muted); font-size:1.05rem;">Belum ada tugas yang dibuat.</p>
                <button class="btn btn-primary" style="margin-top:1.5rem;" onclick="switchTab('buat-tugas')">➕ Buat Tugas Pertama</button>
            </div>
        <?php else: ?>
            <?php foreach ($assignments as $a):
                $is_overdue = strtotime($a['deadline']) < time();
                $pct = $total_mahasiswa > 0 ? min(100, round(($a['jumlah_pengumpul'] / $total_mahasiswa) * 100)) : 0;
            ?>
            <div class="assignment-card">
                <div class="assignment-info">
                    <div class="assignment-title"><?php echo htmlspecialchars($a['judul']); ?></div>
                    <div class="assignment-meta-row">
                        <span class="badge badge-info"><?php echo htmlspecialchars($a['nama_semester']); ?></span>
                        <span class="deadline-text <?php echo $is_overdue ? 'overdue' : ''; ?>">
                            <?php echo $is_overdue ? '❌' : '⏰'; ?>
                            <?php echo format_tanggal($a['deadline']); ?>
                        </span>
                        <span style="color:var(--text-muted); font-size:0.8rem;">
                            👤 <?php echo $a['jumlah_pengumpul']; ?> / <?php echo $total_mahasiswa; ?> mahasiswa mengumpulkan
                        </span>
                    </div>
                    <div class="progress-bar-wrap">
                        <div class="progress-bar-fill" style="width:<?php echo $pct; ?>%;"></div>
                    </div>
                </div>
                <div class="action-btns" style="flex-shrink:0;">
                    <a href="laboran.php?detail=<?php echo $a['id']; ?>&semester=<?php echo $filter_semester; ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;">👁 Detail</a>
                    <form method="POST" onsubmit="return confirm('Hapus tugas ini? Semua file pengumpulan juga akan terhapus.')">
                        <input type="hidden" name="action" value="delete_assignment">
                        <input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                        <button type="submit" class="btn btn-danger btn-sm">🗑 Hapus</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: KELOLA SEMESTER -->
    <!-- ============================================================ -->
    <div class="tab-content" id="tab-semester">
        <!-- Form Tambah Semester -->
        <div class="glass-panel" style="margin-bottom:2rem;">
            <h3 class="section-title">➕ Tambah Semester Baru</h3>
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

        <!-- Daftar Semester -->
        <h3 class="section-title">📅 Daftar Semester</h3>
        <?php if (empty($semesters)): ?>
            <div class="glass-panel" style="text-align:center; padding:3rem; color:var(--text-muted);">Belum ada semester yang ditambahkan.</div>
        <?php else: ?>
            <?php foreach ($semesters as $sem): ?>
            <div class="semester-row">
                <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                    <div class="semester-name"><?php echo htmlspecialchars($sem['nama_semester']); ?></div>
                    <span class="badge <?php echo $sem['status'] === 'aktif' ? 'badge-active' : 'badge-inactive'; ?>">
                        <?php echo ucfirst($sem['status']); ?>
                    </span>
                </div>
                <div style="display:flex; gap:0.5rem;">
                    <!-- Toggle Status -->
                    <form method="POST">
                        <input type="hidden" name="action" value="toggle_semester">
                        <input type="hidden" name="semester_id" value="<?php echo $sem['id']; ?>">
                        <input type="hidden" name="new_status" value="<?php echo $sem['status'] === 'aktif' ? 'nonaktif' : 'aktif'; ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <?php echo $sem['status'] === 'aktif' ? '🔴 Nonaktifkan' : '🟢 Aktifkan'; ?>
                        </button>
                    </form>
                    <!-- Hapus Semester -->
                    <form method="POST" onsubmit="return confirm('Hapus semester ini? Semua tugas dan pengumpulan di dalamnya juga akan terhapus!')">
                        <input type="hidden" name="action" value="delete_semester">
                        <input type="hidden" name="semester_id" value="<?php echo $sem['id']; ?>">
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
            <h3 class="section-title">📝 Buat Slot Pengumpulan Tugas Baru</h3>
            <?php if (empty($semesters)): ?>
                <div class="alert alert-error">⚠️ Anda harus menambah semester terlebih dahulu sebelum membuat tugas!</div>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="add_assignment">
                <div class="form-group">
                    <label class="form-label">Judul Tugas</label>
                    <input type="text" name="judul" class="form-input" placeholder="Masukkan judul tugas..." required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Semester</label>
                        <select name="id_semester" class="form-select" required>
                            <option value="">-- Pilih Semester --</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?php echo $sem['id']; ?>"><?php echo htmlspecialchars($sem['nama_semester']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Deadline Pengumpulan</label>
                        <input type="datetime-local" name="deadline" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Tipe File yang Diizinkan</label>
                    <input type="text" name="tipe_file" class="form-input" placeholder="Contoh: pdf,docx,zip (kosongkan untuk semua jenis file)" value="">
                    <small style="color:var(--text-muted); font-size:0.8rem; margin-top:0.4rem;">Pisahkan dengan koma. Kosongkan untuk mengizinkan semua jenis file.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Deskripsi Tugas (Opsional)</label>
                    <textarea name="deskripsi" class="form-textarea" rows="4" placeholder="Masukkan deskripsi, instruksi, atau catatan untuk mahasiswa..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">✅ Buat Tugas</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

</div>

<footer>
    <p>KumpulTugas &copy; 2026 — Sistem Pengumpulan Tugas Mahasiswa</p>
</footer>

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
// Jika ada pesan setelah aksi form, pertahankan tab yang sesuai
<?php if (isset($_POST['action'])): ?>
    <?php if ($_POST['action'] === 'add_semester' || $_POST['action'] === 'delete_semester' || $_POST['action'] === 'toggle_semester'): ?>
        switchTab('semester');
    <?php elseif ($_POST['action'] === 'add_assignment'): ?>
        switchTab('buat-tugas');
    <?php endif; ?>
<?php endif; ?>
</script>
</body>
</html>
