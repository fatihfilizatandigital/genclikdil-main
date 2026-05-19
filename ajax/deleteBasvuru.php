<?php
session_start();
// Tüm giriş yapmış kullanıcılar silebilsin
if (empty($_SESSION['giris_yapildi']) || $_SESSION['giris_yapildi'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mesaj' => 'Yetkisiz işlem.']);
    exit;
}

if (file_exists("../connectt.php")) include("../connectt.php");
elseif (file_exists("connectt.php")) include("connectt.php");
else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mesaj' => 'Bağlantı hatası.']);
    exit;
}

if (!isset($conn)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mesaj' => 'Veritabanı bağlantısı yok.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz istek.']);
    exit;
}

$id = (int) $_POST['id'];
if ($id <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz kayıt.']);
    exit;
}

$sql = "DELETE FROM yenibursluluk WHERE ID = $id";
header('Content-Type: application/json; charset=utf-8');
if (mysqli_query($conn, $sql)) {
    echo json_encode(['ok' => true, 'mesaj' => 'Kayıt silindi.']);
} else {
    echo json_encode(['ok' => false, 'mesaj' => 'Silme hatası: ' . mysqli_error($conn)]);
}
