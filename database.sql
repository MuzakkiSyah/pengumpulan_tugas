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
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Semester
CREATE TABLE IF NOT EXISTS `semesters` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nama_semester` VARCHAR(50) UNIQUE NOT NULL, -- Contoh: "Ganjil 2026/2027"
  `status` ENUM('aktif', 'nonaktif') DEFAULT 'aktif',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Tugas (Assignments)
CREATE TABLE IF NOT EXISTS `assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_semester` INT NOT NULL,
  `judul` VARCHAR(150) NOT NULL,
  `deskripsi` TEXT DEFAULT NULL,
  `deadline` DATETIME NOT NULL,
  `tipe_file` VARCHAR(100) DEFAULT 'all', -- Ekstensi yang diperbolehkan, misal: "pdf,zip,doc,docx"
  `dibuat_oleh` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`id_semester`) REFERENCES `semesters`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`dibuat_oleh`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Pengumpulan Tugas (Submissions)
CREATE TABLE IF NOT EXISTS `submissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_assignment` INT NOT NULL,
  `id_mahasiswa` INT NOT NULL,
  `nama_file` VARCHAR(255) NOT NULL,
  `path_file` VARCHAR(255) NOT NULL,
  `waktu_unggah` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`id_assignment`) REFERENCES `assignments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`id_mahasiswa`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_submission` (`id_assignment`, `id_mahasiswa`) -- Mahasiswa hanya memiliki satu baris pengumpulan per tugas
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tambahkan beberapa data awal untuk testing
-- Password untuk akun default ini adalah 'laboran123'
INSERT IGNORE INTO `users` (`id`, `username`, `password`, `nama_lengkap`, `nomor_induk`, `role`) 
VALUES (1, 'laboran', '$2y$10$w8uQZkMWh/lP8iP2Kqj9Eemq6m6Z7mCO4QeZ.4t4iE5tDkIqGkFmS', 'Asisten Laboran Utama', '197001012026081001', 'laboran');

-- Tambahkan data semester awal
INSERT IGNORE INTO `semesters` (`id`, `nama_semester`, `status`) 
VALUES (1, 'Ganjil 2026/2027', 'aktif'), (2, 'Genap 2026/2027', 'nonaktif');
