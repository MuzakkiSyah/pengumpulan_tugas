-- Buat database jika belum ada
CREATE DATABASE IF NOT EXISTS `db_pengumpulan_tugas`;
USE `db_pengumpulan_tugas`;

-- Tabel Pengguna (Laboran / Mahasiswa)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `nama_lengkap` VARCHAR(100) NOT NULL,
  `nomor_induk` VARCHAR(50) UNIQUE NOT NULL, -- NIP untuk laboran, NIM untuk mahasiswa
  `role` ENUM('laboran', 'mahasiswa') NOT NULL,
  `semester` TINYINT DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Semester
CREATE TABLE IF NOT EXISTS `semesters` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nama_semester` VARCHAR(50) UNIQUE NOT NULL, -- Contoh: "Ganjil 2026/2027"
  `status` ENUM('aktif', 'nonaktif') DEFAULT 'aktif',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Mata Kuliah
CREATE TABLE IF NOT EXISTS `mata_kuliah` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_semester` INT NOT NULL,
  `kode_matkul` VARCHAR(20) NOT NULL,
  `nama_matkul` VARCHAR(100) NOT NULL,
  `semester` TINYINT DEFAULT 1,
  `deskripsi` TEXT DEFAULT NULL,
  `dibuat_oleh` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`id_semester`) REFERENCES `semesters`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`dibuat_oleh`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_matkul_semester` (`id_semester`, `kode_matkul`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Tugas (Assignments) - mengacu ke mata_kuliah, bukan langsung semester
CREATE TABLE IF NOT EXISTS `assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_matkul` INT NOT NULL,
  `judul` VARCHAR(150) NOT NULL,
  `deskripsi` TEXT DEFAULT NULL,
  `deadline` DATETIME NOT NULL,
  `tipe_file` VARCHAR(100) DEFAULT 'all', -- Ekstensi yang diperbolehkan, misal: "pdf,zip,doc,docx"
  `dibuat_oleh` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`id_matkul`) REFERENCES `mata_kuliah`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`dibuat_oleh`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Pengumpulan Tugas (Submissions)
CREATE TABLE IF NOT EXISTS `submissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_assignment` INT NOT NULL,
  `id_mahasiswa` INT NOT NULL,
  `nama_file` VARCHAR(255) NOT NULL,
  `path_file` VARCHAR(255) NOT NULL,
  `nilai` TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100',
  `catatan_nilai` TEXT DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'dikumpul',
  `waktu_unggah` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`id_assignment`) REFERENCES `assignments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`id_mahasiswa`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_submission` (`id_assignment`, `id_mahasiswa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Riwayat Feedback / Penilaian (Comment History)
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

-- Jika database sudah ada, jalankan ALTER berikut secara manual di phpMyAdmin:
-- ALTER TABLE `submissions` ADD COLUMN `nilai` TINYINT UNSIGNED DEFAULT NULL AFTER `waktu_unggah`;
-- ALTER TABLE `submissions` ADD COLUMN `catatan_nilai` TEXT DEFAULT NULL AFTER `nilai`;

-- Data semester awal
INSERT IGNORE INTO `semesters` (`id`, `nama_semester`, `status`)
VALUES (1, 'Ganjil 2026/2027', 'aktif'), (2, 'Genap 2026/2027', 'nonaktif');

-- =========================================================
-- CATATAN PENTING:
-- Setelah import database ini, jalankan setup.php di browser:
--   http://localhost/pengumpulan_tugas/setup.php
-- untuk membuat akun admin pertama (admin / labrm2026).
-- Hapus setup.php setelah berhasil dijalankan!
-- =========================================================

