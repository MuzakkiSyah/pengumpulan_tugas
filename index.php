<?php
require_once 'config.php';

// Halaman utama - alihkan berdasarkan status login
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'laboran') {
        header("Location: laboran.php");
    } else {
        header("Location: mahasiswa.php");
    }
} else {
    header("Location: login.php");
}
exit();
?>
