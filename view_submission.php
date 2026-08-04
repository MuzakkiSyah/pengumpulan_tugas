<?php
/**
 * view_submission.php
 * Serve file submission untuk preview inline oleh laboran.
 * Mendukung PDF, gambar, teks. File lain di-download.
 */
require_once 'config.php';
check_login('laboran');

$sub_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$sub_id) { http_response_code(400); exit('Parameter tidak valid.'); }

$stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
$stmt->execute([$sub_id]);
$sub = $stmt->fetch();

if (!$sub) { http_response_code(404); exit('File tidak ditemukan.'); }

$file_path = $sub['path_file'];
if (!file_exists($file_path)) {
    http_response_code(404);
    exit('File fisik tidak ditemukan di server.');
}

$ext      = strtolower(pathinfo($sub['nama_file'], PATHINFO_EXTENSION));
$file_size = filesize($file_path);

// Tentukan MIME type dan mode tampil
$mime_map = [
    'pdf'  => ['application/pdf',      'inline'],
    'png'  => ['image/png',             'inline'],
    'jpg'  => ['image/jpeg',            'inline'],
    'jpeg' => ['image/jpeg',            'inline'],
    'gif'  => ['image/gif',             'inline'],
    'webp' => ['image/webp',            'inline'],
    'svg'  => ['image/svg+xml',         'inline'],
    'txt'  => ['text/plain; charset=utf-8', 'inline'],
    'csv'  => ['text/plain; charset=utf-8', 'inline'],
];

if (isset($mime_map[$ext])) {
    [$mime, $disposition] = $mime_map[$ext];
} else {
    // File lain: paksa download
    $mime = 'application/octet-stream';
    $disposition = 'attachment';
}

header("Content-Type: $mime");
header("Content-Disposition: $disposition; filename=\"" . rawurlencode($sub['nama_file']) . "\"");
header("Content-Length: $file_size");
header("Cache-Control: private, max-age=3600");
header("X-Content-Type-Options: nosniff");

readfile($file_path);
exit();
