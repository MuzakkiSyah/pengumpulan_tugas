<?php
require_once 'config.php';
check_login('laboran');

$user_id = $_SESSION['user_id'];

// Ambil data profil laboran/staff lengkap
$stmt_profile = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt_profile->execute([$user_id]);
$current_user = $stmt_profile->fetch();
$current_jabatan = $current_user['jabatan'] ?? 'laboran';

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

    // Validasi Akses Asisten Laboran (Hanya boleh add_assignment dan grade_submission)
    if ($current_jabatan === 'asisten_laboran' && !in_array($_POST['action'], ['add_assignment', 'grade_submission'])) {
        $message = 'Akses ditolak! Asisten Laboran hanya diizinkan untuk membuat tugas dan menilai.';
        $message_type = 'error';
    }
    // --- Tambah Semester ---
    elseif ($_POST['action'] === 'add_semester') {
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
        $prodi       = clean_input($_POST['prodi_matkul'] ?? 'D3 RMIK');
        $kode_matkul = strtoupper(clean_input($_POST['kode_matkul']));
        $nama_matkul = clean_input($_POST['nama_matkul']);
        $semester_level = (int)$_POST['semester_level'];
        $deskripsi   = null;
        if (!empty($id_semester) && !empty($kode_matkul) && !empty($nama_matkul) && $semester_level >= 1 && $semester_level <= 6) {
            try {
                $stmt = $pdo->prepare("INSERT INTO mata_kuliah (id_semester, kode_matkul, nama_matkul, semester, prodi, deskripsi, dibuat_oleh) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id_semester, $kode_matkul, $nama_matkul, $semester_level, $prodi, $deskripsi, $user_id]);
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
            $message = 'Semester, kode, nama mata kuliah, dan semester tingkat wajib diisi!';
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
        $kelas_post = $_POST['kelas_target'] ?? ['all'];
        $prodi_target = clean_input($_POST['prodi_target'] ?? 'all');
        if (in_array('all', $kelas_post) || empty($kelas_post)) {
            $kelas_target = 'all';
        } else {
            $cleaned_classes = array_map(function($c) {
                return strtoupper(clean_input($c));
            }, $kelas_post);
            $kelas_target = implode(',', $cleaned_classes);
        }

        if (!empty($id_matkul) && !empty($judul) && !empty($deadline)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO assignments (id_matkul, judul, deskripsi, deadline, tipe_file, kelas, prodi, dibuat_oleh) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id_matkul, $judul, $deskripsi, $deadline, $tipe_file ?: 'all', $kelas_target, $prodi_target, $user_id]);
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
        $semester = (int)$_POST['semester_mhs'];
        $kelas = strtoupper(clean_input($_POST['kelas_mhs'] ?? 'A'));
        $prodi = clean_input($_POST['prodi_mhs'] ?? 'D3 RMIK');
        if (empty($kelas)) $kelas = 'A';

        if (empty($nama) || empty($nim) || empty($uname) || empty($pass) || $semester < 1 || $semester > 6) {
            $message = 'Semua field mahasiswa dan semester tingkat wajib diisi!';
            $message_type = 'error';
        } else {
            try {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nomor_induk, role, semester, kelas, prodi) VALUES (?, ?, ?, ?, 'mahasiswa', ?, ?, ?)");
                $stmt->execute([$uname, $hash, $nama, $nim, $semester, $kelas, $prodi]);
                $message = "Akun mahasiswa $nama ($prodi - Kelas $kelas) berhasil dibuat!";
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Username atau NIM sudah terdaftar!';
                $message_type = 'error';
            }
        }
    }

    // --- Edit Mahasiswa & Reset Password ---
    elseif ($_POST['action'] === 'edit_mahasiswa') {
        $mhs_id   = (int)$_POST['mhs_id'];
        $semester = (int)$_POST['semester_mhs'];
        $kelas    = strtoupper(clean_input($_POST['kelas_mhs'] ?? 'A'));
        $prodi    = clean_input($_POST['prodi_mhs'] ?? 'D3 RMIK');
        $new_pass = clean_input($_POST['new_password']);
        if (empty($kelas)) $kelas = 'A';
        
        if ($mhs_id > 0 && $semester >= 1 && $semester <= 6) {
            try {
                if (!empty($new_pass)) {
                    $hash = password_hash($new_pass, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET semester = ?, kelas = ?, prodi = ?, password = ? WHERE id = ? AND role = 'mahasiswa'");
                    $stmt->execute([$semester, $kelas, $prodi, $hash, $mhs_id]);
                    $message = 'Data mahasiswa dan password berhasil diperbarui!';
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET semester = ?, kelas = ?, prodi = ? WHERE id = ? AND role = 'mahasiswa'");
                    $stmt->execute([$semester, $kelas, $prodi, $mhs_id]);
                    $message = 'Data mahasiswa berhasil diperbarui!';
                }
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Gagal memperbarui data mahasiswa: ' . $e->getMessage();
                $message_type = 'error';
            }
        } else {
            $message = 'Input data tidak valid!';
            $message_type = 'error';
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

    // --- Import Mahasiswa via Excel ---
    elseif ($_POST['action'] === 'import_excel') {
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Harap pilih file Excel yang valid!';
            $message_type = 'error';
        } else {
            $file = $_FILES['excel_file'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') {
                $message = 'Hanya file Excel (.xlsx) yang didukung!';
                $message_type = 'error';
            } else {
                if ($xlsx = \Shuchkin\SimpleXLSX::parse($file['tmp_name'])) {
                    $imported = 0;
                    $skipped  = 0;
                    $errors   = [];
                    $row_num  = 0;

                    foreach ($xlsx->rows() as $row) {
                        $row_num++;
                        // Skip header row
                        if ($row_num === 1) {
                            if (isset($row[0]) && strtolower(trim($row[0])) === 'nama_lengkap') continue;
                        }
                        if (!isset($row[0]) || trim($row[0]) === '') continue;

                        $nama  = trim($row[0]);
                        $nim   = trim($row[1] ?? '');
                        $uname = trim($row[2] ?? $nim); // default username = nim
                        $pass  = trim($row[3] ?? $nim); // default password = nim
                        $prodi_excel = isset($row[4]) ? trim($row[4]) : '';
                        $semester = isset($row[5]) && trim($row[5]) !== '' ? (int)trim($row[5]) : 1;
                        if ($semester < 1 || $semester > 6) $semester = 1;
                        $kelas = isset($row[6]) && trim($row[6]) !== '' ? strtoupper(trim($row[6])) : 'A';

                        if (empty($nama) || empty($nim)) {
                            $errors[] = "Baris $row_num: nama/NIM kosong.";
                            $skipped++;
                            continue;
                        }
                        if (empty($uname)) $uname = $nim;
                        if (empty($pass))  $pass  = $nim;

                        // Determine prodi from excel or auto-detect based on NIM prefix
                        $prodi = 'D3 RMIK';
                        if (!empty($prodi_excel)) {
                            if (stripos($prodi_excel, 'D4') !== false || stripos($prodi_excel, 'D13') !== false) {
                                $prodi = 'D4 MIK';
                            } else {
                                $prodi = 'D3 RMIK';
                            }
                        } else {
                            if (stripos($nim, 'D22') === 0) {
                                $prodi = 'D3 RMIK';
                            } elseif (stripos($nim, 'D13') === 0) {
                                $prodi = 'D4 MIK';
                            }
                        }

                        try {
                            $hash = password_hash($pass, PASSWORD_BCRYPT);
                            $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nomor_induk, role, semester, kelas, prodi) VALUES (?, ?, ?, ?, 'mahasiswa', ?, ?, ?)");
                            $stmt->execute([$uname, $hash, $nama, $nim, $semester, $kelas, $prodi]);
                            $imported++;
                        } catch (PDOException $e) {
                            $errors[] = "Baris $row_num ($nama): username/NIM duplikat, dilewati.";
                            $skipped++;
                        }
                    }

                    $msg_parts = ["$imported akun berhasil diimpor."];
                    if ($skipped > 0) $msg_parts[] = "$skipped dilewati.";
                    if (!empty($errors)) $msg_parts[] = implode(' | ', array_slice($errors, 0, 3));

                    $message = implode(' ', $msg_parts);
                    $message_type = $imported > 0 ? 'success' : 'error';
                } else {
                    $message = 'Gagal memproses file Excel: ' . \Shuchkin\SimpleXLSX::parseError();
                    $message_type = 'error';
                }
            }
        }
    }

    // --- Import Mata Kuliah via Excel ---
    elseif ($_POST['action'] === 'import_matkul_excel') {
        if (!isset($_FILES['excel_file_matkul']) || $_FILES['excel_file_matkul']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Harap pilih file Excel yang valid!';
            $message_type = 'error';
        } else {
            $file = $_FILES['excel_file_matkul'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') {
                $message = 'Hanya file Excel (.xlsx) yang didukung!';
                $message_type = 'error';
            } else {
                if ($xlsx = \Shuchkin\SimpleXLSX::parse($file['tmp_name'])) {
                    $imported = 0;
                    $skipped  = 0;
                    $errors   = [];
                    $row_num  = 0;

                    foreach ($xlsx->rows() as $row) {
                        $row_num++;
                        // Skip header row
                        if ($row_num === 1) {
                            if (isset($row[0]) && strtolower(trim($row[0])) === 'kode_matkul') continue;
                        }
                        if (!isset($row[0]) || trim($row[0]) === '') continue;

                        $kode_matkul        = strtoupper(trim($row[0]));
                        $nama_matkul        = trim($row[1] ?? '');
                        $semester_akademik  = trim($row[2] ?? '');
                        $prodi_excel        = isset($row[3]) ? trim($row[3]) : '';
                        $tingkat_semester   = isset($row[4]) && trim($row[4]) !== '' ? (int)trim($row[4]) : 1;
                        $deskripsi          = null; // Deskripsi dihilangkan

                        // Determine prodi from excel or auto-detect based on kode_matkul prefix
                        $prodi = 'D3 RMIK';
                        if (!empty($prodi_excel)) {
                            if (stripos($prodi_excel, 'D4') !== false || stripos($prodi_excel, 'D13') !== false) {
                                $prodi = 'D4 MIK';
                            } else {
                                $prodi = 'D3 RMIK';
                            }
                        } else {
                            if (stripos($kode_matkul, 'D22') === 0) {
                                $prodi = 'D3 RMIK';
                            } elseif (stripos($kode_matkul, 'D13') === 0) {
                                $prodi = 'D4 MIK';
                            }
                        }

                        if (empty($kode_matkul) || empty($nama_matkul) || empty($semester_akademik)) {
                            $errors[] = "Baris $row_num: Kode, Nama, atau Semester kosong.";
                            $skipped++;
                            continue;
                        }
                        if ($tingkat_semester < 1 || $tingkat_semester > 6) {
                            $tingkat_semester = 1;
                        }

                        try {
                            // 1. Dapatkan atau buat ID semester akademik
                            $stmt_sem = $pdo->prepare("SELECT id FROM semesters WHERE nama_semester = ?");
                            $stmt_sem->execute([$semester_akademik]);
                            $sem = $stmt_sem->fetch();
                            if ($sem) {
                                $id_semester = $sem['id'];
                            } else {
                                $stmt_ins_sem = $pdo->prepare("INSERT INTO semesters (nama_semester, status) VALUES (?, 'aktif')");
                                $stmt_ins_sem->execute([$semester_akademik]);
                                $id_semester = $pdo->lastInsertId();
                            }

                            // 2. Insert mata kuliah
                            $stmt = $pdo->prepare("INSERT INTO mata_kuliah (id_semester, kode_matkul, nama_matkul, semester, prodi, deskripsi, dibuat_oleh) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$id_semester, $kode_matkul, $nama_matkul, $tingkat_semester, $prodi, $deskripsi, $user_id]);
                            $imported++;
                        } catch (PDOException $e) {
                            if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
                                $errors[] = "Baris $row_num ($kode_matkul): duplikat kode matkul pada semester akademik.";
                            } else {
                                $errors[] = "Baris $row_num: " . $e->getMessage();
                            }
                            $skipped++;
                        }
                    }

                    $msg_parts = ["$imported mata kuliah berhasil diimpor."];
                    if ($skipped > 0) $msg_parts[] = "$skipped dilewati.";
                    if (!empty($errors)) $msg_parts[] = implode(' | ', array_slice($errors, 0, 3));

                    $message = implode(' ', $msg_parts);
                    $message_type = $imported > 0 ? 'success' : 'error';
                } else {
                    $message = 'Gagal memproses file Excel: ' . \Shuchkin\SimpleXLSX::parseError();
                    $message_type = 'error';
                }
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

    // --- Tambah Staff Baru ---
    elseif ($_POST['action'] === 'add_staff') {
        $nama  = clean_input($_POST['nama_lengkap']);
        $npp   = clean_input($_POST['nomor_induk']);
        $uname = clean_input($_POST['username_staff']);
        $pass  = clean_input($_POST['password_staff']);
        $jabatan_post = clean_input($_POST['jabatan']);
        
        if (empty($nama) || empty($npp) || empty($uname) || empty($pass) || empty($jabatan_post)) {
            $message = 'Semua field staff wajib diisi!';
            $message_type = 'error';
        } elseif (!in_array($jabatan_post, ['asisten_laboran', 'laboran', 'kepala_laboratorium'])) {
            $message = 'Jabatan tidak valid!';
            $message_type = 'error';
        } else {
            try {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nomor_induk, role, jabatan) VALUES (?, ?, ?, ?, 'laboran', ?)");
                $stmt->execute([$uname, $hash, $nama, $npp, $jabatan_post]);
                $message = "Staff $nama berhasil ditambahkan!";
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Username atau NPP sudah terdaftar!';
                $message_type = 'error';
            }
        }
    }

    // --- Edit Staff & Reset Password ---
    elseif ($_POST['action'] === 'edit_staff') {
        $staff_id = (int)$_POST['staff_id'];
        $nama     = clean_input($_POST['nama_lengkap']);
        $npp      = clean_input($_POST['nomor_induk']);
        $jabatan_post = clean_input($_POST['jabatan']);
        $new_pass = clean_input($_POST['new_password']);
        
        if ($staff_id > 0 && !empty($nama) && !empty($npp) && in_array($jabatan_post, ['asisten_laboran', 'laboran', 'kepala_laboratorium'])) {
            try {
                if (!empty($new_pass)) {
                    $hash = password_hash($new_pass, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET nama_lengkap = ?, nomor_induk = ?, jabatan = ?, password = ? WHERE id = ? AND role = 'laboran'");
                    $stmt->execute([$nama, $npp, $jabatan_post, $hash, $staff_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET nama_lengkap = ?, nomor_induk = ?, jabatan = ? WHERE id = ? AND role = 'laboran'");
                    $stmt->execute([$nama, $npp, $jabatan_post, $staff_id]);
                }
                $message = 'Data staff berhasil diperbarui!';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Gagal memperbarui data staff: ' . $e->getMessage();
                $message_type = 'error';
            }
        } else {
            $message = 'Input data tidak valid!';
            $message_type = 'error';
        }
    }

    // --- Hapus Staff ---
    elseif ($_POST['action'] === 'delete_staff') {
        $staff_id = (int)$_POST['staff_id'];
        if ($staff_id === (int)$user_id) {
            $message = 'Anda tidak dapat menghapus akun Anda sendiri!';
            $message_type = 'error';
        } else {
            try {
                $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'laboran'")->execute([$staff_id]);
                $message = 'Akun staff berhasil dihapus!';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Gagal menghapus staff: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    }

    // Post-Redirect-Get pattern to prevent form resubmission
    if ($message !== '') {
        $active_tab = 'tugas';
        if (in_array($_POST['action'], ['add_semester','delete_semester','toggle_semester'])) {
            $active_tab = 'semester';
        } elseif (in_array($_POST['action'], ['add_matkul','delete_matkul','import_matkul_excel'])) {
            $active_tab = 'matkul';
        } elseif ($_POST['action'] === 'add_assignment') {
            $active_tab = 'buat-tugas';
        } elseif (in_array($_POST['action'], ['add_mahasiswa','delete_mahasiswa','edit_mahasiswa','reset_password','import_excel'])) {
            $active_tab = 'akun';
        } elseif (in_array($_POST['action'], ['add_staff','edit_staff','delete_staff'])) {
            $active_tab = 'staff';
        }

        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $message_type;
        $_SESSION['flash_tab'] = $active_tab;
        
        $qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
        header("Location: laboran.php" . $qs);
        exit();
    }
}

// Download template Excel
if (isset($_GET['download_template'])) {
    check_login('laboran');
    $data = [
        ['nama_lengkap', 'nim', 'username', 'password', 'program_studi', 'semester', 'kelas'],
        ['Budi Santoso', 'D222024001', 'budi2024', 'D222024001', 'D3 RMIK', 1, 'A'],
        ['Siti Rahayu', 'D132024002', 'siti2024', 'D132024002', 'D4 MIK', 2, 'B'],
        ['Ahmad Fauzi', 'D222024003', '', '', 'D3 RMIK', 3, 'A'] // kosong = default nim
    ];
    \Shuchkin\SimpleXLSXGen::fromArray($data)->downloadAs('template_mahasiswa.xlsx');
    exit();
}

// Download template Excel Mata Kuliah
if (isset($_GET['download_template_matkul'])) {
    check_login('laboran');
    $data = [
        ['kode_matkul', 'nama_matkul', 'semester_akademik', 'program_studi', 'tingkat_semester'],
        ['D22.101', 'Pengantar Teknologi Informasi', 'Ganjil 2026/2027', 'D3 RMIK', 1],
        ['D13.201', 'Algoritma & Pemrograman', 'Ganjil 2026/2027', 'D4 MIK', 2],
        ['D22.302', 'Basis Data', 'Genap 2026/2027', 'D3 RMIK', 3]
    ];
    \Shuchkin\SimpleXLSXGen::fromArray($data)->downloadAs('template_mata_kuliah.xlsx');
    exit();
}

// Export Excel Nilai per Semester
if (isset($_GET['export_excel_grades'])) {
    check_login('laboran');
    
    $semester_tingkat = isset($_GET['export_semester']) ? (int)$_GET['export_semester'] : 0;
    $prodi = isset($_GET['export_prodi']) ? trim($_GET['export_prodi']) : '';
    $matkul_id = isset($_GET['export_matkul']) ? (int)$_GET['export_matkul'] : 0;
    
    if (!$semester_tingkat || !$prodi) {
        die("Semester Tingkat dan Program Studi harus dipilih.");
    }
    
    $active_semester_id = $pdo->query("SELECT id FROM semesters WHERE status = 'aktif' LIMIT 1")->fetchColumn() ?: 0;
    
    if ($matkul_id > 0) {
        // Query untuk satu mata kuliah saja
        $stmt = $pdo->prepare("
            SELECT u.nomor_induk AS nim, u.nama_lengkap AS nama_mahasiswa, u.kelas AS kelas_mahasiswa, 
                   mk.nama_matkul, a.id AS assignment_id, a.judul AS nama_tugas, sub.nilai
            FROM users u
            JOIN mata_kuliah mk ON mk.id = ?
            JOIN assignments a ON a.id_matkul = mk.id 
                AND (a.prodi = 'all' OR a.prodi = u.prodi)
                AND (a.kelas = 'all' OR a.kelas = u.kelas)
            LEFT JOIN submissions sub ON sub.id_assignment = a.id AND sub.id_mahasiswa = u.id
            WHERE u.role = 'mahasiswa' 
              AND u.prodi = mk.prodi 
              AND u.semester = mk.semester
            ORDER BY u.kelas ASC, u.nomor_induk ASC, a.created_at ASC
        ");
        $stmt->execute([$matkul_id]);
    } else {
        // Query untuk semua mata kuliah di semester tingkat & prodi tersebut pada semester akademik aktif
        $stmt = $pdo->prepare("
            SELECT u.nomor_induk AS nim, u.nama_lengkap AS nama_mahasiswa, u.kelas AS kelas_mahasiswa, 
                   mk.nama_matkul, a.id AS assignment_id, a.judul AS nama_tugas, sub.nilai
            FROM users u
            JOIN mata_kuliah mk ON mk.id_semester = ? AND mk.prodi = ? AND mk.semester = ?
            JOIN assignments a ON a.id_matkul = mk.id 
                AND (a.prodi = 'all' OR a.prodi = u.prodi)
                AND (a.kelas = 'all' OR a.kelas = u.kelas)
            LEFT JOIN submissions sub ON sub.id_assignment = a.id AND sub.id_mahasiswa = u.id
            WHERE u.role = 'mahasiswa' 
              AND u.prodi = mk.prodi 
              AND u.semester = mk.semester
            ORDER BY mk.nama_matkul ASC, u.kelas ASC, u.nomor_induk ASC, a.created_at ASC
        ");
        $stmt->execute([$active_semester_id, $prodi, $semester_tingkat]);
    }
    
    $rows = $stmt->fetchAll();
    
    $stmt_sem = $pdo->prepare("SELECT nama_semester FROM semesters WHERE id = ?");
    $stmt_sem->execute([$active_semester_id]);
    $sem_name = $stmt_sem->fetchColumn() ?: "Semester Aktif";
    
    $filename = 'rekap_nilai_semester_' . $semester_tingkat . '_' . strtolower(str_replace(' ', '_', $prodi)) . '_' . date('Ymd_His') . '.xlsx';
    
    if (empty($rows)) {
        $excel_data = [
            ['REKAP NILAI MAHASISWA'],
            ['Semester Akademik:', $sem_name],
            ['Semester Tingkat:', 'Semester ' . $semester_tingkat],
            ['Program Studi:', $prodi],
            [],
            ['Tidak ada data nilai atau tugas yang ditemukan untuk kriteria ini.']
        ];
        \Shuchkin\SimpleXLSXGen::fromArray($excel_data)->downloadAs($filename);
        exit();
    }
    
    // Grouping data per kelas
    $data_per_kelas = [];
    $assignments_per_kelas = [];
    
    foreach ($rows as $r) {
        $kelas = $r['kelas_mahasiswa'] ?: 'Tanpa Kelas';
        $nim = $r['nim'];
        $assign_id = $r['assignment_id'];
        
        // Group assignments by class to maintain order
        if (!isset($assignments_per_kelas[$kelas][$assign_id])) {
            $assignments_per_kelas[$kelas][$assign_id] = [
                'nama_matkul' => $r['nama_matkul'],
                'nama_tugas'  => $r['nama_tugas']
            ];
        }
        
        // Initialize student if not set
        if (!isset($data_per_kelas[$kelas][$nim])) {
            $data_per_kelas[$kelas][$nim] = [
                'nim'  => $nim,
                'nama' => $r['nama_mahasiswa'],
                'nilai' => []
            ];
        }
        
        // Store grade
        $data_per_kelas[$kelas][$nim]['nilai'][$assign_id] = $r['nilai'];
    }
    
    // Create new XLSX generator
    $xlsx = new \Shuchkin\SimpleXLSXGen();
    
    foreach ($data_per_kelas as $kelas => $students) {
        $sheet_rows = [
            ['REKAP NILAI MAHASISWA - KELAS ' . $kelas],
            ['Semester Akademik:', $sem_name],
            ['Semester Tingkat:', 'Semester ' . $semester_tingkat],
            ['Program Studi:', $prodi],
            [],
        ];
        
        // Header Row: NIM, Nama Mahasiswa, [Assignments...]
        $header_row = ['NIM', 'Nama Mahasiswa'];
        $class_assignments = $assignments_per_kelas[$kelas];
        foreach ($class_assignments as $assign_id => $assign_info) {
            if ($matkul_id > 0) {
                $header_row[] = $assign_info['nama_tugas'];
            } else {
                $header_row[] = $assign_info['nama_matkul'] . ' - ' . $assign_info['nama_tugas'];
            }
        }
        $sheet_rows[] = $header_row;
        
        // Data Rows for each student
        foreach ($students as $nim => $student) {
            $row = [
                $student['nim'],
                $student['nama']
            ];
            
            foreach ($class_assignments as $assign_id => $assign_info) {
                $nilai = $student['nilai'][$assign_id] ?? null;
                $row[] = ($nilai !== null) ? (int)$nilai : 'Belum Dinilai';
            }
            $sheet_rows[] = $row;
        }
        
        // Clean sheet name (Excel limits sheet names to 31 chars and bans certain characters)
        $sheet_name = preg_replace('/[\/\\\?\*\:\[\]]/', '_', $kelas);
        $xlsx->addSheet($sheet_rows, $sheet_name);
    }
    
    $xlsx->downloadAs($filename);
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

// Ambil semua mata kuliah lengkap untuk tab buat-tugas dan asisten laboran
$all_matkul_full = $pdo->query("
    SELECT mk.*, s.nama_semester, s.status as status_sem
    FROM mata_kuliah mk
    JOIN semesters s ON mk.id_semester = s.id
    ORDER BY s.created_at DESC, mk.semester ASC, mk.kode_matkul ASC
")->fetchAll();

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
        SELECT sub.*, u.nama_lengkap, u.nomor_induk, u.kelas, u.prodi
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
    <title>Dashboard Laboran - Sistem Informasi Pengumpulan Tugas Lab RM</title>
    <link rel="icon" type="image/png" href="https://raw.githubusercontent.com/MuzakkiSyah/laboratoriumrm/47ccb8aadc7a14211df38be5f26f4e45f75a0f20/LOGO%20LAB-06%20-%20Copy.png">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
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
        
        .preview-modal-content {
            background: #ffffff;
            border: 1.5px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            width: 95%;
            max-width: 1000px;
            height: 85vh;
            box-shadow: var(--shadow-lg);
            display: flex;
            flex-direction: column;
            transform: scale(0.9);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .modal-container.show .preview-modal-content {
            transform: scale(1);
        }
    </style>

</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="navbar-container">
        <span class="navbar-brand" style="display:flex; align-items:center; gap:0.5rem;">
            <img src="https://raw.githubusercontent.com/MuzakkiSyah/laboratoriumrm/47ccb8aadc7a14211df38be5f26f4e45f75a0f20/LOGO%20LAB-06%20-%20Copy.png" alt="Sistem Informasi Pengumpulan Tugas Lab RM Logo" style="height: 32px; object-fit: contain;">
            <span class="brand-text">Sistem Informasi Pengumpulan Tugas Lab RM</span>
        </span>
        <div class="navbar-user">
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($_SESSION['nama_lengkap']) ?></div>
                <?php
                $jabatan_labels = [
                    'asisten_laboran'     => 'Asisten Laboran',
                    'laboran'             => 'Laboran',
                    'kepala_laboratorium' => 'Kepala Laboratorium'
                ];
                $display_jabatan = $jabatan_labels[$current_jabatan] ?? 'Staff';
                ?>
                <div class="user-role"><?= htmlspecialchars($display_jabatan) ?></div>
            </div>
            <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
        </div>
    </div>
</nav>

<!-- Detail Side Panel -->
<div class="detail-overlay" id="detailOverlay" data-should-open="<?= $detail_assignment ? 'true' : 'false' ?>" onclick="closeDetail()"></div>
<div class="detail-panel" id="detailPanel" data-should-open="<?= $detail_assignment ? 'true' : 'false' ?>">
    <?php if ($detail_assignment): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
            <h3 style="font-size:1.1rem;">Detail Pengumpulan</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeDetail()">✕ Tutup</button>
        </div>
        <div style="margin-bottom:1.25rem;">
            <h4 style="margin-bottom:.3rem;"><?= htmlspecialchars($detail_assignment['judul']) ?></h4>
            <p style="font-size:.82rem;color:var(--accent-cyan);margin-bottom:.25rem;">
                <?= htmlspecialchars($detail_assignment['kode_matkul']) ?> · <?= htmlspecialchars($detail_assignment['nama_matkul']) ?>
            </p>
            <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:.25rem;"><?= htmlspecialchars($detail_assignment['nama_semester']) ?></p>
            <p style="font-size:.82rem;color:var(--color-warning);">⏰ <?= format_tanggal($detail_assignment['deadline']) ?></p>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem; flex-wrap:wrap; gap:.5rem;">
            <div style="font-size:.88rem;font-weight:600;color:var(--text-muted);" id="subCountText"><?= count($detail_submissions) ?> Mahasiswa Mengumpulkan</div>
            <?php if (!empty($detail_submissions)): ?>
                <a href="download.php?assignment_id=<?= $detail_assignment['id'] ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;" title="Download semua pengumpulan tugas ini sebagai ZIP">
                    📦 Download ZIP
                </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($detail_submissions)): ?>
            <!-- Search bar -->
            <div style="margin-bottom:0.75rem;">
                <input type="text" id="searchSubName" class="form-input" placeholder="🔍 Cari nama mahasiswa..." style="padding:.45rem .75rem; font-size:.85rem; height:auto;" oninput="updateSubmissionsList()">
            </div>

            <!-- Filter & Sort controls -->
            <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; margin-bottom:1.25rem; flex-wrap:wrap;">
                <!-- Class Filter -->
                <div style="display:flex; align-items:center; gap:.3rem; flex:1; min-width:130px;">
                    <label class="form-label" style="margin-bottom:0; font-size:.8rem; white-space:nowrap;">Kelas:</label>
                    <select class="form-select" id="filterClassSub" style="padding:.3rem 1.75rem .3rem .5rem; font-size:.8rem; height:auto; background-position: right 0.5rem center;" onchange="updateSubmissionsList()">
                        <option value="all">Semua Kelas</option>
                        <?php 
                        $classes = array_unique(array_filter(array_column($detail_submissions, 'kelas')));
                        sort($classes);
                        foreach ($classes as $cls):
                        ?>
                            <option value="<?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($cls) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Prodi Filter -->
                <div style="display:flex; align-items:center; gap:.3rem; flex:1; min-width:160px;">
                    <label class="form-label" style="margin-bottom:0; font-size:.8rem; white-space:nowrap;">Prodi:</label>
                    <select class="form-select" id="filterProdiSub" style="padding:.3rem 1.75rem .3rem .5rem; font-size:.8rem; height:auto; background-position: right 0.5rem center;" onchange="updateSubmissionsList()">
                        <option value="all">Semua Prodi</option>
                        <option value="D3 RMIK">D3 RMIK</option>
                        <option value="D4 MIK">D4 MIK</option>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($detail_submissions)): ?>
            <div style="text-align:center;color:var(--text-muted);padding:3rem 0;">
                <div style="font-size:2rem;margin-bottom:.5rem;">📭</div>
                <p>Belum ada yang mengumpulkan</p>
            </div>
        <?php else: ?>
            <div id="submissionsContainer">
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
            <div class="submission-card" 
                 data-class="<?= htmlspecialchars($sub['kelas'] ?? 'A') ?>" 
                 data-prodi="<?= htmlspecialchars($sub['prodi'] ?? 'D3 RMIK') ?>" 
                 data-name="<?= htmlspecialchars(strtolower($sub['nama_lengkap'])) ?>" 
                 data-time="<?= htmlspecialchars($sub['waktu_unggah']) ?>"
                 style="border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: var(--bg-card);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.75rem;">
                    <div>
                        <div style="font-weight:600; font-size:.95rem; color:var(--text-main);"><?= htmlspecialchars($sub['nama_lengkap']) ?> <span class="badge badge-info" style="font-size:.7rem; font-weight:normal; border-radius:4px; padding:.1rem .3rem; text-transform:none; margin-left:.25rem;"><?= htmlspecialchars($sub['prodi'] ?? 'D3 RMIK') ?> - Kelas <?= htmlspecialchars($sub['kelas'] ?? 'A') ?></span></div>
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
                        <button type="button" class="btn btn-secondary btn-sm" onclick="openPreviewModal('view_submission.php?id=<?= $sub['id'] ?>', '<?= htmlspecialchars(addslashes($sub['nama_file'])) ?>')" style="font-size:.78rem; padding: 0.35rem 0.65rem; display:inline-flex; align-items:center; gap:0.2rem; border-radius:6px;">👁️ Lihat</button>
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
            </div> <!-- #submissionsContainer -->
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
        <?php if ($current_jabatan !== 'asisten_laboran'): ?>
            <button class="tab-btn" id="tab-btn-matkul"     onclick="switchTab('matkul')">📁 Mata Kuliah</button>
            <button class="tab-btn" id="tab-btn-semester"   onclick="switchTab('semester')">📅 Semester</button>
        <?php endif; ?>
        <button class="tab-btn" id="tab-btn-buat-tugas" onclick="switchTab('buat-tugas')">➕ Buat Tugas</button>
        <?php if ($current_jabatan !== 'asisten_laboran'): ?>
            <button class="tab-btn" id="tab-btn-akun"       onclick="switchTab('akun')">👥 Akun Mahasiswa</button>
        <?php endif; ?>
        <?php if (in_array($current_jabatan, ['laboran', 'kepala_laboratorium'])): ?>
            <button class="tab-btn" id="tab-btn-staff"      onclick="switchTab('staff')">👥 Role Access / Staff</button>
        <?php endif; ?>
        <button class="tab-btn" id="tab-btn-rekap-nilai" onclick="switchTab('rekap-nilai')">📊 Rekap Nilai</button>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: DAFTAR TUGAS (grouped by mata kuliah) -->
    <!-- ============================================================ -->
    <div class="tab-content active" id="tab-tugas">

        <!-- Filter Dropdowns -->
        <?php 
        $selected_semester_name = 'Semua Semester';
        foreach ($semesters as $sem) {
            if ($filter_semester == $sem['id']) {
                $selected_semester_name = $sem['nama_semester'];
                break;
            }
        }

        $selected_matkul_name = 'Semua Mata Kuliah';
        if ($filter_semester && !empty($all_matkul)) {
            foreach ($all_matkul as $mk) {
                if ($filter_matkul == $mk['id']) {
                    $selected_matkul_name = $mk['kode_matkul'] . ' — ' . $mk['nama_matkul'];
                    break;
                }
            }
        }
        ?>
        <div class="filter-bar" style="gap: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <span class="filter-label">📅 Semester:</span>
                <div class="searchable-dropdown" id="dropdown-semester">
                    <div class="dropdown-trigger">
                        <span class="dropdown-trigger-text"><?= htmlspecialchars($selected_semester_name) ?></span>
                        <span class="dropdown-arrow">▼</span>
                    </div>
                    <div class="dropdown-menu">
                        <div class="dropdown-search-wrapper">
                            <input type="text" class="dropdown-search-input" placeholder="Cari semester...">
                        </div>
                        <div class="dropdown-options">
                            <div class="dropdown-option <?= !$filter_semester ? 'selected' : '' ?>" data-value="0" data-url="laboran.php">Semua Semester</div>
                            <?php foreach ($semesters as $sem): ?>
                                <div class="dropdown-option <?= $filter_semester == $sem['id'] ? 'selected' : '' ?>" data-value="<?= $sem['id'] ?>" data-url="laboran.php?semester=<?= $sem['id'] ?>">
                                    <?= htmlspecialchars($sem['nama_semester']) ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="dropdown-option no-results">Tidak ada hasil</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($filter_semester && !empty($all_matkul)): ?>
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <span class="filter-label">📁 Matkul:</span>
                <div class="searchable-dropdown" id="dropdown-matkul">
                    <div class="dropdown-trigger">
                        <span class="dropdown-trigger-text"><?= htmlspecialchars($selected_matkul_name) ?></span>
                        <span class="dropdown-arrow">▼</span>
                    </div>
                    <div class="dropdown-menu">
                        <div class="dropdown-search-wrapper">
                            <input type="text" class="dropdown-search-input" placeholder="Cari matkul...">
                        </div>
                        <div class="dropdown-options">
                            <div class="dropdown-option <?= !$filter_matkul ? 'selected' : '' ?>" data-value="0" data-url="laboran.php?semester=<?= $filter_semester ?>">Semua Mata Kuliah</div>
                            <?php foreach ($all_matkul as $mk): ?>
                                <div class="dropdown-option <?= $filter_matkul == $mk['id'] ? 'selected' : '' ?>" data-value="<?= $mk['id'] ?>" data-url="laboran.php?semester=<?= $filter_semester ?>&matkul=<?= $mk['id'] ?>">
                                    <?= htmlspecialchars($mk['kode_matkul']) ?> — <?= htmlspecialchars($mk['nama_matkul']) ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="dropdown-option no-results">Tidak ada hasil</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

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
                    <div style="display:flex; align-items:center; gap:0.75rem;">
                        <span style="font-size:.82rem;color:rgba(255,255,255,0.85);"><?= htmlspecialchars($group['info']['nama_semester']) ?> · <?= count($group['tugas']) ?> tugas</span>
                        <a href="download.php?matkul_id=<?= $mk_id ?>" class="btn btn-secondary btn-sm" style="text-decoration:none; background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); color:#fff; font-size:0.78rem; padding:0.25rem 0.5rem;" title="Download semua tugas mata kuliah ini sebagai ZIP">
                            📦 Backup ZIP
                        </a>
                    </div>
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
                                <span>🎯 Target: <?= $a['prodi'] === 'all' ? 'Semua Prodi' : htmlspecialchars($a['prodi']) ?> (<?= $a['kelas'] === 'all' ? 'Semua Kelas' : 'Kelas ' . htmlspecialchars(str_replace(',', ', ', $a['kelas'])) ?>)</span>
                                <span>👥 <?= $a['jumlah_pengumpul'] ?>/<?= $total_mahasiswa ?> mengumpulkan</span>
                            </div>
                            <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= $pct ?>%"></div></div>
                        </div>
                        <div class="action-btns">
                            <a href="laboran.php?detail=<?= $a['id'] ?>&semester=<?= $filter_semester ?>&matkul=<?= $filter_matkul ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;">👁 Detail</a>
                            <a href="download.php?assignment_id=<?= $a['id'] ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;" title="Download semua pengumpulan tugas ini sebagai ZIP">📦 Zip</a>
                            <?php if ($current_jabatan !== 'asisten_laboran'): ?>
                            <form method="POST" onsubmit="return confirm('Hapus tugas ini?')">
                                <input type="hidden" name="action" value="delete_assignment">
                                <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                            </form>
                            <?php endif; ?>
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
    <?php if ($current_jabatan !== 'asisten_laboran'): ?>
    <div class="tab-content" id="tab-matkul">

        <!-- Import Excel Matkul -->
        <div class="glass-panel" style="margin-bottom:2rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
                <div>
                    <h3 style="font-size:1.2rem; margin-bottom:.3rem;">📤 Import Mata Kuliah via Excel</h3>
                    <p style="font-size:.88rem; color:var(--text-muted);">Upload file Excel berisi data mata kuliah. Kolom: <code style="background:var(--bg-input); padding:.1rem .4rem; border-radius:4px;">kode_matkul, nama_matkul, semester_akademik, program_studi, tingkat_semester</code></p>
                </div>
                <a href="laboran.php?download_template_matkul=1" class="btn btn-secondary btn-sm" style="white-space:nowrap;">
                    ⬇ Unduh Template Excel
                </a>
            </div>

            <div style="background:var(--accent-gold-light); border:1.5px solid rgba(242,183,5,.3); border-radius:8px; padding:1rem; margin-bottom:1.25rem; font-size:.85rem; color:var(--accent-gold-dark);">
                💡 <strong>Tips:</strong> Jika nama semester akademik (contoh: <em>Ganjil 2026/2027</em>) belum terdaftar di sistem, otomatis akan dibuat otomatis.
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_matkul_excel">
                <div style="display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap;">
                    <div style="flex:1; min-width:220px;">
                        <label class="form-label">Pilih File Excel Mata Kuliah</label>
                        <input type="file" name="excel_file_matkul" class="form-input" accept=".xlsx" required style="padding:.6rem;">
                    </div>
                    <button type="submit" class="btn btn-primary">📤 Import Sekarang</button>
                </div>
            </form>
        </div>

        <!-- Form Tambah Matkul -->
        <div class="glass-panel" style="margin-bottom:2rem;">
            <h3 style="font-size:1.2rem;margin-bottom:1.5rem;">📁 Tambah Mata Kuliah Baru</h3>
            <?php if (empty($semesters)): ?>
                <div class="alert alert-error">⚠️ Tambahkan semester terlebih dahulu!</div>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="add_matkul">
                <div class="form-row-4" style="display:grid; grid-template-columns: 1.5fr 1.5fr 1fr 1fr; gap: 1rem; margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label">Semester Akademik (Periode)</label>
                        <select name="id_semester" class="form-select" required>
                            <option value="">-- Pilih Semester --</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= $sem['id'] ?>"><?= htmlspecialchars($sem['nama_semester']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Program Studi</label>
                        <select name="prodi_matkul" class="form-select" required>
                            <option value="D3 RMIK">D3 RMIK (D22)</option>
                            <option value="D4 MIK">D4 MIK (D13)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tingkat Semester</label>
                        <select name="semester_level" class="form-select" required>
                            <option value="1">Semester 1</option>
                            <option value="2">Semester 2</option>
                            <option value="3">Semester 3</option>
                            <option value="4">Semester 4</option>
                            <option value="5">Semester 5</option>
                            <option value="6">Semester 6</option>
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
                <button type="submit" class="btn btn-primary">➕ Tambah Mata Kuliah</button>
            </form>
            <?php endif; ?>
        </div>

        <!-- Daftar Matkul per Semester -->
        <?php
        $sort_matkul = isset($_GET['sort_matkul']) ? $_GET['sort_matkul'] : 'semester_asc';

        $order_by_matkul = "mk.semester ASC, mk.kode_matkul ASC";
        if ($sort_matkul === 'semester_desc') {
            $order_by_matkul = "mk.semester DESC, mk.kode_matkul ASC";
        } elseif ($sort_matkul === 'kode_asc') {
            $order_by_matkul = "mk.kode_matkul ASC";
        } elseif ($sort_matkul === 'kode_desc') {
            $order_by_matkul = "mk.kode_matkul DESC";
        } elseif ($sort_matkul === 'nama_asc') {
            $order_by_matkul = "mk.nama_matkul ASC";
        } elseif ($sort_matkul === 'nama_desc') {
            $order_by_matkul = "mk.nama_matkul DESC";
        }

        // Kelompokkan matkul per semester
        $matkul_by_sem = [];
        $all_matkul_full = $pdo->query("
            SELECT mk.*, s.nama_semester, s.status as status_sem
            FROM mata_kuliah mk
            JOIN semesters s ON mk.id_semester = s.id
            ORDER BY s.created_at DESC, $order_by_matkul
        ")->fetchAll();
        foreach ($all_matkul_full as $mk) {
            $matkul_by_sem[$mk['id_semester']]['semester'] = $mk['nama_semester'];
            $matkul_by_sem[$mk['id_semester']]['matkul'][] = $mk;
        }
        ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.5rem;">
            <h3 style="font-size:1.2rem; margin-bottom:0;">📋 Daftar Mata Kuliah</h3>
            <div style="display:flex; align-items:center; gap:.5rem;">
                <label class="form-label" style="margin-bottom:0; font-size:.85rem; white-space:nowrap;">Urutkan:</label>
                <select class="form-input" style="padding:.3rem .6rem; font-size:.82rem; width:180px;" onchange="location.href='laboran.php?tab=matkul&sort_matkul=' + this.value">
                    <option value="semester_asc" <?= $sort_matkul === 'semester_asc' ? 'selected' : '' ?>>Semester (Terkecil)</option>
                    <option value="semester_desc" <?= $sort_matkul === 'semester_desc' ? 'selected' : '' ?>>Semester (Terbesar)</option>
                    <option value="kode_asc" <?= $sort_matkul === 'kode_asc' ? 'selected' : '' ?>>Kode Matkul (A-Z)</option>
                    <option value="kode_desc" <?= $sort_matkul === 'kode_desc' ? 'selected' : '' ?>>Kode Matkul (Z-A)</option>
                    <option value="nama_asc" <?= $sort_matkul === 'nama_asc' ? 'selected' : '' ?>>Nama Matkul (A-Z)</option>
                    <option value="nama_desc" <?= $sort_matkul === 'nama_desc' ? 'selected' : '' ?>>Nama Matkul (Z-A)</option>
                </select>
            </div>
        </div>
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
                            <span class="badge badge-inactive" style="font-size:.7rem; padding:.1rem .4rem; text-transform:none; border-radius:4px; font-weight:normal; background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-muted);"><?= htmlspecialchars($mk['prodi'] ?? 'D3 RMIK') ?></span>
                            <span class="badge badge-info" style="font-size:.7rem; padding:.1rem .4rem; text-transform:none; border-radius:4px; font-weight:normal;">Semester <?= $mk['semester'] ?></span>
                            <div>
                                <div style="font-weight:600;font-size:.95rem;"><?= htmlspecialchars($mk['nama_matkul']) ?></div>
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
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- TAB: KELOLA SEMESTER -->
    <!-- ============================================================ -->
    <?php if ($current_jabatan !== 'asisten_laboran'): ?>
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
    <?php endif; ?>

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
                    <label class="form-label" style="font-weight: 600;">Filter Semester</label>
                    <div class="filter-btn-group">
                        <button type="button" class="filter-btn active" data-semester="all" onclick="filterBySemester('all')">Semua Semester</button>
                        <button type="button" class="filter-btn" data-semester="1" onclick="filterBySemester('1')">Semester 1</button>
                        <button type="button" class="filter-btn" data-semester="2" onclick="filterBySemester('2')">Semester 2</button>
                        <button type="button" class="filter-btn" data-semester="3" onclick="filterBySemester('3')">Semester 3</button>
                        <button type="button" class="filter-btn" data-semester="4" onclick="filterBySemester('4')">Semester 4</button>
                        <button type="button" class="filter-btn" data-semester="5" onclick="filterBySemester('5')">Semester 5</button>
                        <button type="button" class="filter-btn" data-semester="6" onclick="filterBySemester('6')">Semester 6</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Mata Kuliah</label>
                    <select name="id_matkul" id="id_matkul" class="form-select" required onchange="filterTargetKelasByMatkul(this); autoSelectProdiTarget(this)">
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
                                    <option value="<?= $mk['id'] ?>" data-semester="<?= $mk['semester'] ?>" data-prodi="<?= htmlspecialchars($mk['prodi'] ?? 'D3 RMIK') ?>">[<?= htmlspecialchars($mk['kode_matkul']) ?>] <?= htmlspecialchars($mk['nama_matkul']) ?> (<?= htmlspecialchars($mk['prodi'] ?? 'D3 RMIK') ?>)</option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Judul Tugas</label>
                    <input type="text" name="judul" class="form-input" placeholder="Contoh: Tugas 1 - Array dan String" required>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Deadline Pengumpulan</label>
                        <input type="datetime-local" name="deadline" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tipe File</label>
                        <input type="text" name="tipe_file" class="form-input" placeholder="pdf,zip (kosong = semua)">
                    </div>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div class="form-group">
                        <label class="form-label">Program Studi Penerima</label>
                            <select name="prodi_target" class="form-select" required>
                                <option value="all">Semua Program Studi (D3 & D4)</option>
                                <option value="D3 RMIK">D3 RMIK (D22)</option>
                                <option value="D4 MIK">D4 MIK (D13)</option>
                            </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kelas Penerima</label>
                        <div style="display: flex; gap: 1rem; align-items: center; padding: 0.6rem 1rem; background: var(--bg-input); border: 1.5px solid var(--border-color); border-radius: 8px; flex-wrap: wrap; height: 42px;">
                            <label style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.9rem; cursor: pointer; color: var(--text-main);">
                                <input type="checkbox" name="kelas_target[]" value="all" id="chkAllKelas" checked onchange="toggleAllKelas(this)"> Semua Kelas
                            </label>
                            <?php
                            $existing_classes = $pdo->query("SELECT DISTINCT kelas FROM users WHERE role = 'mahasiswa' AND kelas IS NOT NULL AND kelas != '' ORDER BY kelas ASC")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($existing_classes as $cls):
                            ?>
                                <label class="chk-kelas-label" data-class-name="<?= htmlspecialchars($cls) ?>" style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.9rem; cursor: pointer; color: var(--text-main);">
                                    <input type="checkbox" name="kelas_target[]" value="<?= htmlspecialchars($cls) ?>" class="chk-kelas" onchange="toggleClassOption(this)"> Kelas <?= htmlspecialchars($cls) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
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
    <?php if ($current_jabatan !== 'asisten_laboran'): ?>
    <div class="tab-content" id="tab-akun">

        <!-- Import Excel -->
        <div class="glass-panel" style="margin-bottom:2rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
                <div>
                    <h3 style="font-size:1.2rem; margin-bottom:.3rem;">📤 Import Mahasiswa via Excel</h3>
                    <p style="font-size:.88rem; color:var(--text-muted);">Upload file Excel berisi data mahasiswa. Kolom: <code style="background:var(--bg-input); padding:.1rem .4rem; border-radius:4px;">nama_lengkap, nim, username, password, program_studi, semester, kelas</code></p>
                </div>
                <a href="laboran.php?download_template=1" class="btn btn-secondary btn-sm" style="white-space:nowrap;">
                    ⬇ Unduh Template Excel
                </a>
            </div>

            <div style="background:var(--accent-gold-light); border:1.5px solid rgba(242,183,5,.3); border-radius:8px; padding:1rem; margin-bottom:1.25rem; font-size:.85rem; color:var(--accent-gold-dark);">
                💡 <strong>Tips:</strong> Jika kolom <em>username</em> atau <em>password</em> dikosongkan, otomatis menggunakan NIM. Format file harus berupa <strong>.xlsx</strong>.
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                <div style="display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap;">
                    <div style="flex:1; min-width:220px;">
                        <label class="form-label">Pilih File Excel (.xlsx)</label>
                        <input type="file" name="excel_file" class="form-input" accept=".xlsx" required style="padding:.6rem;">
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
                        <input type="text" name="nim" id="nim_mhs" class="form-input" placeholder="Nomor Induk Mahasiswa" required oninput="detectProdiFromNim(this.value)">
                    </div>
                </div>
                <div class="form-row-3" style="display:grid; grid-template-columns: 2fr 2fr 2fr 1fr 1fr; gap: 1rem; margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username_mhs" class="form-input" placeholder="Username untuk login" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="text" name="password_mhs" class="form-input" placeholder="Password awal (bisa diubah nanti)" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Program Studi</label>
                        <select name="prodi_mhs" class="form-select" required>
                            <option value="D3 RMIK">D3 RMIK</option>
                            <option value="D4 MIK">D4 MIK</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Semester</label>
                        <select name="semester_mhs" class="form-select" required>
                            <option value="1">Semester 1</option>
                            <option value="2">Semester 2</option>
                            <option value="3">Semester 3</option>
                            <option value="4">Semester 4</option>
                            <option value="5">Semester 5</option>
                            <option value="6">Semester 6</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kelas</label>
                        <input type="text" name="kelas_mhs" class="form-input" placeholder="Contoh: A" required value="A" style="text-transform:uppercase;">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">➕ Tambah Mahasiswa</button>
            </form>
        </div>

        <!-- Daftar Mahasiswa -->
        <h3 style="font-size:1.2rem; margin-bottom:1rem;">👥 Daftar Akun Mahasiswa</h3>
        <?php
        $sort_mhs = isset($_GET['sort_mhs']) ? $_GET['sort_mhs'] : 'nama_asc';
        
        $order_by_mhs = "u.nama_lengkap ASC";
        if ($sort_mhs === 'nama_desc') {
            $order_by_mhs = "u.nama_lengkap DESC";
        } elseif ($sort_mhs === 'nim_asc') {
            $order_by_mhs = "u.nomor_induk ASC";
        } elseif ($sort_mhs === 'nim_desc') {
            $order_by_mhs = "u.nomor_induk DESC";
        } elseif ($sort_mhs === 'semester_asc') {
            $order_by_mhs = "u.semester ASC, u.nama_lengkap ASC";
        } elseif ($sort_mhs === 'semester_desc') {
            $order_by_mhs = "u.semester DESC, u.nama_lengkap ASC";
        } elseif ($sort_mhs === 'kumpul_asc') {
            $order_by_mhs = "total_kumpul ASC, u.nama_lengkap ASC";
        } elseif ($sort_mhs === 'kumpul_desc') {
            $order_by_mhs = "total_kumpul DESC, u.nama_lengkap ASC";
        }

        $daftar_mhs = $pdo->query("
            SELECT u.*, 
                   COUNT(sub.id) as total_kumpul
            FROM users u
            LEFT JOIN submissions sub ON sub.id_mahasiswa = u.id
            WHERE u.role = 'mahasiswa'
            GROUP BY u.id
            ORDER BY $order_by_mhs
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
            <!-- Search & Sort filter -->
            <div style="margin-bottom:1rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
                <div style="flex:1; min-width:250px;">
                    <input type="text" id="searchMhs" class="form-input" placeholder="🔍 Cari nama atau NIM..." oninput="filterMhs()" style="width:100%;">
                </div>
                <div style="display:flex; align-items:center; gap:.5rem;">
                    <label class="form-label" style="margin-bottom:0; font-size:.85rem; white-space:nowrap;">Urutkan:</label>
                    <select class="form-input" style="padding:.3rem .6rem; font-size:.82rem; width:180px;" onchange="location.href='laboran.php?tab=akun&sort_mhs=' + this.value">
                        <option value="nama_asc" <?= $sort_mhs === 'nama_asc' ? 'selected' : '' ?>>Nama Lengkap (A-Z)</option>
                        <option value="nama_desc" <?= $sort_mhs === 'nama_desc' ? 'selected' : '' ?>>Nama Lengkap (Z-A)</option>
                        <option value="nim_asc" <?= $sort_mhs === 'nim_asc' ? 'selected' : '' ?>>NIM (Terkecil)</option>
                        <option value="nim_desc" <?= $sort_mhs === 'nim_desc' ? 'selected' : '' ?>>NIM (Terbesar)</option>
                        <option value="semester_asc" <?= $sort_mhs === 'semester_asc' ? 'selected' : '' ?>>Semester (Terkecil)</option>
                        <option value="semester_desc" <?= $sort_mhs === 'semester_desc' ? 'selected' : '' ?>>Semester (Terbesar)</option>
                        <option value="kumpul_asc" <?= $sort_mhs === 'kumpul_asc' ? 'selected' : '' ?>>Total Kumpul (Sedikit)</option>
                        <option value="kumpul_desc" <?= $sort_mhs === 'kumpul_desc' ? 'selected' : '' ?>>Total Kumpul (Banyak)</option>
                    </select>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="custom-table" id="mhsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Lengkap</th>
                            <th>NIM</th>
                            <th>Program Studi</th>
                            <th>Semester</th>
                            <th>Kelas</th>
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
                            <td><span class="badge badge-inactive" style="font-weight:500; font-size:.8rem;"><?= htmlspecialchars($mhs['prodi'] ?? 'D3 RMIK') ?></span></td>
                            <td>Semester <?= htmlspecialchars($mhs['semester'] ?? 1) ?></td>
                            <td>Kelas <?= htmlspecialchars($mhs['kelas'] ?? 'A') ?></td>
                            <td><span class="badge badge-info"><?= $mhs['total_kumpul'] ?> file</span></td>
                            <td>
                                <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
                                    <!-- Edit -->
                                    <button class="btn btn-secondary btn-sm" onclick="showResetForm(<?= $mhs['id'] ?>, '<?= htmlspecialchars(addslashes($mhs['nama_lengkap'])) ?>', <?= $mhs['semester'] ?? 1 ?>, '<?= htmlspecialchars(addslashes($mhs['kelas'] ?? 'A')) ?>', '<?= htmlspecialchars(addslashes($mhs['prodi'] ?? 'D3 RMIK')) ?>')">📝 Edit</button>
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
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem; flex-wrap:wrap; gap:1rem;">
                <p id="mhsPageInfo" style="font-size:.85rem; color:var(--text-muted); margin:0;">Total: <?= count($daftar_mhs) ?> mahasiswa terdaftar</p>
                <div style="display:flex; gap:0.5rem; align-items:center;">
                    <button type="button" id="mhsPrevBtn" class="btn btn-secondary btn-sm" onclick="mhsPrevPage()" style="font-size:0.8rem; padding:0.35rem 0.65rem;">◀ Sebelumnya</button>
                    <button type="button" id="mhsNextBtn" class="btn btn-secondary btn-sm" onclick="mhsNextPage()" style="font-size:0.8rem; padding:0.35rem 0.65rem;">Selanjutnya ▶</button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modal Edit Mahasiswa (hidden by default) -->
        <div id="resetModal" class="modal-container">
            <div class="modal-content">
                <h4 style="margin-bottom:1.25rem;">📝 Edit Data & Reset Password Mahasiswa</h4>
                <p id="resetMhsName" style="color:var(--text-muted); font-size:.9rem; margin-bottom:1.25rem;"></p>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_mahasiswa">
                    <input type="hidden" name="mhs_id" id="resetMhsId">
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label">Program Studi</label>
                        <select name="prodi_mhs" id="editMhsProdi" class="form-select" required>
                            <option value="D3 RMIK">D3 RMIK</option>
                            <option value="D4 MIK">D4 MIK</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label">Semester Tingkat</label>
                        <select name="semester_mhs" id="editMhsSemester" class="form-select" required>
                            <option value="1">Semester 1</option>
                            <option value="2">Semester 2</option>
                            <option value="3">Semester 3</option>
                            <option value="4">Semester 4</option>
                            <option value="5">Semester 5</option>
                            <option value="6">Semester 6</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label">Kelas</label>
                        <input type="text" name="kelas_mhs" id="editMhsKelas" class="form-input" required style="text-transform:uppercase;">
                    </div>
                    <div class="form-group" style="margin-bottom:1.25rem;">
                        <label class="form-label">Reset Password Baru (Opsional)</label>
                        <input type="text" name="new_password" class="form-input" placeholder="Kosongkan jika tidak ingin mereset password">
                    </div>
                    <div style="display:flex; gap:.75rem;">
                        <button type="submit" class="btn btn-primary">✅ Simpan Perubahan</button>
                        <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- TAB: KELOLA STAFF & ROLE ACCESS -->
    <!-- ============================================================ -->
    <?php if (in_array($current_jabatan, ['laboran', 'kepala_laboratorium'])): ?>
    <div class="tab-content" id="tab-staff">
        <!-- Form Tambah Staff -->
        <div class="glass-panel" style="margin-bottom:2rem;">
            <h3 style="font-size:1.2rem;margin-bottom:1.5rem;">👥 Tambah Staff Baru</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_staff">
                <div class="form-row" style="margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" class="form-input" placeholder="Nama Lengkap Staff" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">NPP (Nomor Pokok Pegawai)</label>
                        <input type="text" name="nomor_induk" class="form-input" placeholder="NPP / NIP" required>
                    </div>
                </div>
                <div class="form-row-3" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username_staff" class="form-input" placeholder="Username untuk login" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" name="password_staff" class="form-input" placeholder="Password" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jabatan (Akses)</label>
                        <select name="jabatan" class="form-select" required>
                            <option value="asisten_laboran">Asisten Laboran</option>
                            <option value="laboran" selected>Laboran</option>
                            <option value="kepala_laboratorium">Kepala Laboratorium</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">➕ Tambah Staff</button>
            </form>
        </div>

        <!-- Daftar Staff -->
        <h3 style="font-size:1.2rem; margin-bottom:1rem;">👥 Daftar Akun Staff / User</h3>
        <?php
        $daftar_staff = $pdo->query("
            SELECT * FROM users 
            WHERE role = 'laboran' 
            ORDER BY FIELD(jabatan, 'kepala_laboratorium', 'laboran', 'asisten_laboran'), nama_lengkap ASC
        ")->fetchAll();
        ?>
        <div class="table-wrapper">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama Lengkap</th>
                        <th>NPP</th>
                        <th>Username</th>
                        <th>Jabatan (Role Access)</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daftar_staff as $i => $st): ?>
                    <tr>
                        <td style="color:var(--text-muted);"><?= $i+1 ?></td>
                        <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($st['nama_lengkap']) ?></td>
                        <td><code style="background:var(--bg-input); padding:.15rem .5rem; border-radius:4px; font-size:.85rem;"><?= htmlspecialchars($st['nomor_induk']) ?></code></td>
                        <td><?= htmlspecialchars($st['username']) ?></td>
                        <td>
                            <?php if ($st['jabatan'] === 'kepala_laboratorium'): ?>
                                <span class="badge badge-gold">👑 Kepala Laboratorium</span>
                            <?php elseif ($st['jabatan'] === 'laboran'): ?>
                                <span class="badge badge-info">🛠️ Laboran</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">📋 Asisten Laboran</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
                                <button class="btn btn-secondary btn-sm" onclick="showStaffForm(<?= $st['id'] ?>, '<?= htmlspecialchars(addslashes($st['nama_lengkap'])) ?>', '<?= htmlspecialchars(addslashes($st['nomor_induk'])) ?>', '<?= htmlspecialchars($st['jabatan']) ?>')">📝 Edit</button>
                                <?php if ($st['id'] !== (int)$user_id): ?>
                                    <form method="POST" onsubmit="return confirm('Hapus staff <?= htmlspecialchars(addslashes($st['nama_lengkap'])) ?>?')">
                                        <input type="hidden" name="action" value="delete_staff">
                                        <input type="hidden" name="staff_id" value="<?= $st['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Modal Edit Staff (hidden by default) -->
        <div id="staffModal" class="modal-container">
            <div class="modal-content">
                <h4 style="margin-bottom:1.25rem;">📝 Edit Data & Reset Password Staff</h4>
                <p id="editStaffName" style="color:var(--text-muted); font-size:.9rem; margin-bottom:1.25rem;"></p>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_staff">
                    <input type="hidden" name="staff_id" id="editStaffId">
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" id="editStaffFullName" class="form-input" required>
                    </div>
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label">NPP</label>
                        <input type="text" name="nomor_induk" id="editStaffNpp" class="form-input" required>
                    </div>
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label">Jabatan (Akses)</label>
                        <select name="jabatan" id="editStaffJabatan" class="form-select" required>
                            <option value="asisten_laboran">Asisten Laboran</option>
                            <option value="laboran">Laboran</option>
                            <option value="kepala_laboratorium">Kepala Laboratorium</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:1.25rem;">
                        <label class="form-label">Reset Password Baru (Opsional)</label>
                        <input type="text" name="new_password" class="form-input" placeholder="Kosongkan jika tidak ingin mereset password">
                    </div>
                    <div style="display:flex; gap:.75rem;">
                        <button type="submit" class="btn btn-primary">✅ Simpan Perubahan</button>
                        <button type="button" class="btn btn-secondary" onclick="closeStaffModal()">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- TAB: REKAP NILAI -->
    <!-- ============================================================ -->
    <div class="tab-content" id="tab-rekap-nilai">
        <div class="glass-panel" style="margin-bottom:2rem;">
            <h3 style="font-size:1.2rem; margin-bottom:0.5rem;">📊 Export Rekap Nilai ke Excel</h3>
            <p style="font-size:0.9rem; color:var(--text-muted); margin-bottom:1.5rem;">
                Unduh rekap nilai mahasiswa dalam format Excel (.xlsx) per semester, program studi, dan mata kuliah.
            </p>
            <form method="GET" action="laboran.php">
                <input type="hidden" name="export_excel_grades" value="1">
                
                <div class="form-row-3" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom:1.5rem;">
                    <div class="form-group">
                        <label class="form-label">Pilih Semester Tingkat</label>
                        <select name="export_semester" id="export_semester" class="form-select" required onchange="filterMatkulExport()">
                            <option value="1" selected>Semester 1</option>
                            <option value="2">Semester 2</option>
                            <option value="3">Semester 3</option>
                            <option value="4">Semester 4</option>
                            <option value="5">Semester 5</option>
                            <option value="6">Semester 6</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Program Studi</label>
                        <select name="export_prodi" id="export_prodi" class="form-select" required onchange="filterMatkulExport()">
                            <option value="D3 RMIK">D3 RMIK</option>
                            <option value="D4 MIK">D4 MIK</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Mata Kuliah</label>
                        <select name="export_matkul" id="export_matkul" class="form-select">
                            <option value="0" data-semester="all" data-prodi="all">-- Semua Mata Kuliah --</option>
                            <?php
                            $all_mk = $pdo->query("SELECT id, nama_matkul, semester, prodi FROM mata_kuliah ORDER BY nama_matkul ASC")->fetchAll();
                            foreach ($all_mk as $mk):
                            ?>
                                <option value="<?= $mk['id'] ?>" data-semester="<?= $mk['semester'] ?>" data-prodi="<?= htmlspecialchars($mk['prodi']) ?>">
                                    <?= htmlspecialchars($mk['nama_matkul']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">📥 Export ke Excel</button>
            </form>
        </div>
    </div>

</div>

    <!-- Modal Preview File (hidden by default) -->
    <div id="previewFileModal" class="modal-container">
        <div class="preview-modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                <h4 id="previewFileTitle" style="margin:0; font-size:1.1rem; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">👁️ Preview File</h4>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closePreviewModal()">✕ Tutup</button>
            </div>
            <iframe id="previewFileFrame" src="" style="width:100%; flex:1; border:1px solid var(--border-color); border-radius:8px; background:#f8f9fa;"></iframe>
        </div>
    </div>

<footer><p>Sistem Informasi Pengumpulan Tugas Lab RM &copy; 2026</p></footer>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.getElementById('tab-btn-' + name).classList.add('active');
}
function closeDetail() {
    const panel = document.getElementById('detailPanel');
    const overlay = document.getElementById('detailOverlay');
    if (panel) panel.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
    setTimeout(() => {
        const url = new URL(window.location.href);
        url.searchParams.delete('detail');
        window.location.href = url.pathname + url.search;
    }, 300);
}
<?php if (isset($_GET['tab'])): ?>
    switchTab('<?= htmlspecialchars($_GET['tab']) ?>');
<?php elseif ($flash_tab): ?>
    switchTab('<?= $flash_tab ?>');
<?php elseif (isset($_POST['action'])): ?>
    <?php if (in_array($_POST['action'], ['add_semester','delete_semester','toggle_semester'])): ?>
        switchTab('semester');
    <?php elseif (in_array($_POST['action'], ['add_matkul','delete_matkul','import_matkul_excel'])): ?>
        switchTab('matkul');
    <?php elseif ($_POST['action'] === 'add_assignment'): ?>
        switchTab('buat-tugas');
    <?php elseif (in_array($_POST['action'], ['add_mahasiswa','delete_mahasiswa','edit_mahasiswa','reset_password','import_excel'])): ?>
        switchTab('akun');
    <?php elseif (in_array($_POST['action'], ['add_staff','edit_staff','delete_staff'])): ?>
        switchTab('staff');
    <?php endif; ?>
<?php endif; ?>

let mhsCurrentPage = 1;
const mhsPerPage = 10;

function filterMhs() {
    const q = document.getElementById('searchMhs').value.toLowerCase();
    const rows = document.querySelectorAll('#mhsTable .mhs-row');
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        if (text.includes(q)) {
            row.classList.remove('search-hidden');
        } else {
            row.classList.add('search-hidden');
        }
    });
    mhsCurrentPage = 1;
    renderMhsPagination();
}

function renderMhsPagination() {
    const allRows = Array.from(document.querySelectorAll('#mhsTable .mhs-row'));
    if (allRows.length === 0) return;
    
    const visibleRows = allRows.filter(row => !row.classList.contains('search-hidden'));
    const totalRows = visibleRows.length;
    const totalPages = Math.ceil(totalRows / mhsPerPage) || 1;

    if (mhsCurrentPage > totalPages) {
        mhsCurrentPage = totalPages;
    }

    visibleRows.forEach((row, index) => {
        const start = (mhsCurrentPage - 1) * mhsPerPage;
        const end = start + mhsPerPage;
        if (index >= start && index < end) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    allRows.forEach(row => {
        if (row.classList.contains('search-hidden')) {
            row.style.display = 'none';
        }
    });

    const prevBtn = document.getElementById('mhsPrevBtn');
    const nextBtn = document.getElementById('mhsNextBtn');
    const infoText = document.getElementById('mhsPageInfo');

    if (prevBtn && nextBtn && infoText) {
        prevBtn.disabled = (mhsCurrentPage === 1);
        nextBtn.disabled = (mhsCurrentPage === totalPages);
        infoText.textContent = `Halaman ${mhsCurrentPage} dari ${totalPages} (${totalRows} Mahasiswa)`;
    }
}

function mhsPrevPage() {
    if (mhsCurrentPage > 1) {
        mhsCurrentPage--;
        renderMhsPagination();
    }
}

function mhsNextPage() {
    const allRows = Array.from(document.querySelectorAll('#mhsTable .mhs-row'));
    const visibleRows = allRows.filter(row => !row.classList.contains('search-hidden'));
    const totalPages = Math.ceil(visibleRows.length / mhsPerPage) || 1;
    if (mhsCurrentPage < totalPages) {
        mhsCurrentPage++;
        renderMhsPagination();
    }
}

function detectProdiFromNim(nim) {
    const prodiSelect = document.getElementsByName('prodi_mhs')[0];
    if (!prodiSelect) return;
    if (nim.startsWith('D22') || nim.startsWith('d22')) {
        prodiSelect.value = 'D3 RMIK';
    } else if (nim.startsWith('D13') || nim.startsWith('d13')) {
        prodiSelect.value = 'D4 MIK';
    }
}

function showResetForm(id, nama, semester, kelas, prodi) {
    document.getElementById('resetMhsId').value = id;
    document.getElementById('resetMhsName').textContent = 'Mahasiswa: ' + nama;
    document.getElementById('editMhsSemester').value = semester;
    document.getElementById('editMhsKelas').value = kelas;
    document.getElementById('editMhsProdi').value = prodi || 'D3 RMIK';
    const modal = document.getElementById('resetModal');
    modal.classList.add('show');
}

function closeResetModal() {
    document.getElementById('resetModal').classList.remove('show');
}

function showStaffForm(id, nama, npp, jabatan) {
    document.getElementById('editStaffId').value = id;
    document.getElementById('editStaffName').textContent = 'Staff: ' + nama;
    document.getElementById('editStaffFullName').value = nama;
    document.getElementById('editStaffNpp').value = npp;
    document.getElementById('editStaffJabatan').value = jabatan;
    const modal = document.getElementById('staffModal');
    modal.classList.add('show');
}

function closeStaffModal() {
    document.getElementById('staffModal').classList.remove('show');
}

function autoSelectProdiTarget(selectEl) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    if (!selectedOption) return;
    const prodi = selectedOption.getAttribute('data-prodi');
    const prodiTargetSelect = document.getElementsByName('prodi_target')[0];
    if (prodiTargetSelect && prodi) {
        prodiTargetSelect.value = prodi;
    }
}

function updateSubmissionsList() {
    const container = document.getElementById('submissionsContainer');
    if (!container) return;

    const cards = Array.from(container.querySelectorAll('.submission-card'));
    
    // Get filter, search, and prodi values
    const classFilterEl = document.getElementById('filterClassSub');
    const classFilter = classFilterEl ? classFilterEl.value : 'all';
    
    const prodiFilterEl = document.getElementById('filterProdiSub');
    const prodiFilter = prodiFilterEl ? prodiFilterEl.value : 'all';
    
    const searchEl = document.getElementById('searchSubName');
    const searchQuery = searchEl ? searchEl.value.toLowerCase().trim() : '';

    // Filter and Search
    let visibleCount = 0;
    cards.forEach(card => {
        const cardClass = card.getAttribute('data-class') || '';
        const cardProdi = card.getAttribute('data-prodi') || '';
        const cardName = card.getAttribute('data-name') || '';
        
        const matchClass = (classFilter === 'all' || cardClass === classFilter);
        const matchProdi = (prodiFilter === 'all' || cardProdi === prodiFilter);
        const matchSearch = (searchQuery === '' || cardName.includes(searchQuery));

        if (matchClass && matchProdi && matchSearch) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    // Update the counter
    const countText = document.getElementById('subCountText');
    if (countText) {
        countText.textContent = visibleCount + ' Mahasiswa Mengumpulkan';
    }
}

function filterSubmissionsByClass(cls) {
    const classFilterEl = document.getElementById('filterClassSub');
    if (classFilterEl) {
        classFilterEl.value = cls;
    }
    updateSubmissionsList();
}

function toggleAllKelas(chk) {
    if (chk.checked) {
        document.querySelectorAll('.chk-kelas').forEach(el => el.checked = false);
    }
}
function toggleClassOption(chk) {
    if (chk.checked) {
        document.getElementById('chkAllKelas').checked = false;
    } else {
        const anyChecked = Array.from(document.querySelectorAll('.chk-kelas')).some(el => el.checked);
        if (!anyChecked) {
            document.getElementById('chkAllKelas').checked = true;
        }
    }
}

// Store original Mata Kuliah options grouped by optgroups
let originalMatkulOptions = [];

document.addEventListener("DOMContentLoaded", function() {
    // Animate detail panel opening
    const panel = document.getElementById('detailPanel');
    const overlay = document.getElementById('detailOverlay');
    if (panel && panel.getAttribute('data-should-open') === 'true') {
        setTimeout(() => {
            panel.classList.add('open');
            overlay.classList.add('open');
        }, 50);
    }

    const selectEl = document.getElementById('id_matkul');
    if (selectEl) {
        const optgroups = selectEl.querySelectorAll('optgroup');
        optgroups.forEach(group => {
            const label = group.getAttribute('label');
            const options = [];
            group.querySelectorAll('option').forEach(opt => {
                options.push({
                    value: opt.value,
                    text: opt.textContent,
                    semester: opt.getAttribute('data-semester')
                });
            });
            originalMatkulOptions.push({
                label: label,
                options: options
            });
        });
    }
});

function getSemesterFromClass(className) {
    const dotIdx = className.indexOf('.');
    if (dotIdx !== -1 && dotIdx + 1 < className.length) {
        const charAfterDot = className.charAt(dotIdx + 1);
        if (charAfterDot >= '1' && charAfterDot <= '9') {
            return charAfterDot;
        }
    }
    return null;
}

function filterBySemester(semester) {
    // 1. Update active button style
    const buttons = document.querySelectorAll('.filter-btn');
    buttons.forEach(btn => {
        if (btn.getAttribute('data-semester') === semester) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    // 2. Filter Mata Kuliah select dropdown
    const selectEl = document.getElementById('id_matkul');
    if (selectEl) {
        // Clear all except the first option
        selectEl.innerHTML = '<option value="">-- Pilih Mata Kuliah --</option>';

        originalMatkulOptions.forEach(group => {
            const filteredOptions = group.options.filter(opt => {
                if (semester === 'all') return true;
                return opt.semester === semester;
            });

            if (filteredOptions.length > 0) {
                const optgroup = document.createElement('optgroup');
                optgroup.setAttribute('label', group.label);
                
                filteredOptions.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt.value;
                    option.textContent = opt.text;
                    option.setAttribute('data-semester', opt.semester);
                    optgroup.appendChild(option);
                });

                selectEl.appendChild(optgroup);
            }
        });
    }

    // 3. Filter Kelas Target Checkboxes
    const classLabels = document.querySelectorAll('.chk-kelas-label');
    classLabels.forEach(label => {
        const checkbox = label.querySelector('.chk-kelas');
        if (!checkbox) return;
        
        const className = checkbox.value;
        const classSem = getSemesterFromClass(className);

        if (semester === 'all') {
            label.style.display = 'flex';
        } else {
            if (classSem === null) {
                label.style.display = 'flex';
            } else if (classSem === semester) {
                label.style.display = 'flex';
            } else {
                label.style.display = 'none';
                if (checkbox.checked) {
                    checkbox.checked = false;
                }
            }
        }
    });

    // Reset the "Semua Kelas" checkbox if all visible ones are unchecked
    const anyChecked = Array.from(document.querySelectorAll('.chk-kelas')).some(el => el.checked);
    if (!anyChecked) {
        document.getElementById('chkAllKelas').checked = true;
    } else {
        document.getElementById('chkAllKelas').checked = false;
    }
}

// Searchable Dropdowns for tab-tugas
document.addEventListener('DOMContentLoaded', function() {
    const dropdowns = document.querySelectorAll('.searchable-dropdown');
    dropdowns.forEach(dropdown => {
        const trigger = dropdown.querySelector('.dropdown-trigger');
        const searchInput = dropdown.querySelector('.dropdown-search-input');
        const options = dropdown.querySelectorAll('.dropdown-option:not(.no-results)');
        
        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdowns.forEach(other => {
                if (other !== dropdown) other.classList.remove('open');
            });
            dropdown.classList.toggle('open');
            if (dropdown.classList.contains('open') && searchInput) {
                searchInput.focus();
                searchInput.value = '';
                options.forEach(opt => opt.style.display = '');
                const noResults = dropdown.querySelector('.dropdown-option.no-results');
                if (noResults) noResults.style.display = 'none';
            }
        });
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = searchInput.value.toLowerCase().trim();
                let visibleCount = 0;
                options.forEach(opt => {
                    const text = opt.textContent.toLowerCase();
                    if (text.includes(query)) {
                        opt.style.display = '';
                        visibleCount++;
                    } else {
                        opt.style.display = 'none';
                    }
                });
                const noResults = dropdown.querySelector('.dropdown-option.no-results');
                if (noResults) {
                    noResults.style.display = visibleCount === 0 ? 'block' : 'none';
                }
            });
            searchInput.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }
        
        options.forEach(opt => {
            opt.addEventListener('click', function() {
                const value = opt.getAttribute('data-value');
                const url = opt.getAttribute('data-url');
                dropdown.querySelectorAll('.dropdown-option').forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');
                const triggerText = trigger.querySelector('.dropdown-trigger-text');
                if (triggerText) triggerText.textContent = opt.textContent;
                dropdown.classList.remove('open');
                if (url) {
                    window.location.href = url;
                }
            });
        });
    });
    
    document.addEventListener('click', function() {
        dropdowns.forEach(dropdown => {
            dropdown.classList.remove('open');
        });
    });
});

function filterMatkulExport() {
    const semesterVal = document.getElementById('export_semester').value;
    const prodiVal = document.getElementById('export_prodi').value;
    const matkulSelect = document.getElementById('export_matkul');
    
    if (!matkulSelect) return;
    
    // Store original options in a global variable on first execution
    if (!window.masterMatkulOptions) {
        window.masterMatkulOptions = Array.from(matkulSelect.options).map(opt => ({
            value: opt.value,
            text: opt.textContent,
            semester: opt.getAttribute('data-semester'),
            prodi: opt.getAttribute('data-prodi')
        }));
    }
    
    // Clear the dropdown
    matkulSelect.innerHTML = '';
    
    // Filter and append matching options
    window.masterMatkulOptions.forEach(opt => {
        const matchSemester = (opt.value === "0" || opt.semester === semesterVal);
        const matchProdi = (opt.value === "0" || opt.prodi === prodiVal);
        
        if (matchSemester && matchProdi) {
            const newOpt = document.createElement('option');
            newOpt.value = opt.value;
            newOpt.textContent = opt.text;
            newOpt.setAttribute('data-semester', opt.semester);
            newOpt.setAttribute('data-prodi', opt.prodi);
            matkulSelect.appendChild(newOpt);
        }
    });
}

document.addEventListener("DOMContentLoaded", function() {
    filterMatkulExport();
    renderMhsPagination();
});
function openPreviewModal(url, filename) {
    document.getElementById('previewFileTitle').textContent = '👁️ Preview: ' + filename;
    document.getElementById('previewFileFrame').src = url;
    document.getElementById('previewFileModal').classList.add('show');
}

function closePreviewModal() {
    document.getElementById('previewFileModal').classList.remove('show');
    document.getElementById('previewFileFrame').src = '';
}
</script>

</body>
</html>
