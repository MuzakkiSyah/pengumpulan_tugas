<?php
// Registrasi publik dinonaktifkan.
// Pengelolaan akun mahasiswa dilakukan oleh Admin/Laboran melalui laboran.php
require_once 'config.php';
header("Location: login.php");
exit();
?>
