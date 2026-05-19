<?php
session_start();
// Tüm giriş yapmış kullanıcılar düzenleme yapabilsin
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

mysqli_set_charset($conn, "utf8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz istek.']);
    exit;
}

$id = (int) $_POST['id'];
if ($id <= 0) {
    echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz kayıt.']);
    exit;
}

$Ad        = mysqli_real_escape_string($conn, trim($_POST['Ad'] ?? ''));
$Soyad     = mysqli_real_escape_string($conn, trim($_POST['Soyad'] ?? ''));
$TC        = mysqli_real_escape_string($conn, preg_replace('/\s+/', '', $_POST['TC'] ?? ''));
$Sinif     = mysqli_real_escape_string($conn, trim($_POST['Sinif'] ?? ''));
$Dogum     = mysqli_real_escape_string($conn, trim($_POST['Dogum'] ?? ''));
$VeliAd    = mysqli_real_escape_string($conn, trim($_POST['VeliAd'] ?? ''));
$VeliSoyad = mysqli_real_escape_string($conn, trim($_POST['VeliSoyad'] ?? ''));
$VeliTel1  = mysqli_real_escape_string($conn, trim($_POST['VeliTel1'] ?? ''));
$VeliTel2  = mysqli_real_escape_string($conn, trim($_POST['VeliTel2'] ?? ''));
$VeliEmail = mysqli_real_escape_string($conn, trim($_POST['VeliEmail'] ?? ''));
$Okul      = mysqli_real_escape_string($conn, trim($_POST['Okul'] ?? ''));
$Cinsiyet  = mysqli_real_escape_string($conn, trim($_POST['Cinsiyet'] ?? ''));
$VeliMeslek= mysqli_real_escape_string($conn, trim($_POST['VeliMeslek'] ?? ''));
$Sube      = mysqli_real_escape_string($conn, trim($_POST['Sube'] ?? ''));
$SinavTuru = mysqli_real_escape_string($conn, trim($_POST['SinavTuru'] ?? ''));

$sql = "UPDATE yenibursluluk SET 
    Ad = '$Ad', Soyad = '$Soyad', TC = '$TC', Sinif = '$Sinif', Dogum = '$Dogum',
    VeliAd = '$VeliAd', VeliSoyad = '$VeliSoyad', VeliTel1 = '$VeliTel1', VeliTel2 = '$VeliTel2',
    VeliEmail = '$VeliEmail', Okul = '$Okul', Cinsiyet = '$Cinsiyet', VeliMeslek = '$VeliMeslek',
    Sube = '$Sube', SinavTuru = '$SinavTuru'
    WHERE ID = $id";

header('Content-Type: application/json; charset=utf-8');
if (mysqli_query($conn, $sql)) {
    echo json_encode(['ok' => true, 'mesaj' => 'Kayıt güncellendi.']);
} else {
    echo json_encode(['ok' => false, 'mesaj' => 'Güncelleme hatası: ' . mysqli_error($conn)]);
}
