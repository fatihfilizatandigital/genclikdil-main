<?php
/**
 * Veritabanı bağlantı ayarları - Bursluluk Sınav Sonuç Sistemi
 */
$conn = mysqli_connect("213.142.130.21", "adnan", "adnan.1234", "farmMysql", 3306);
mysqli_set_charset($conn, "utf8mb4");

if (!$conn) {
    die("Veritabanı bağlantı hatası: " . mysqli_connect_error());
}
