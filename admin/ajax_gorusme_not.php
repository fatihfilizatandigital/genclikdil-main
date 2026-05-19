<?php
/**
 * Görüşme notu ekle / güncelle / sil. Giriş yapmış tüm panel kullanıcıları kullanabilir.
 * POST: action=ekle|guncelle|sil, gorusme_listesi_id, [id], [baslik], [icerik], [sira]
 */
session_start();
if (empty($_SESSION['giris_yapildi']) || $_SESSION['giris_yapildi'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mesaj' => 'Yetkisiz']);
    exit;
}

$conn = mysqli_connect("213.142.130.21", "adnan", "adnan.1234", "farmMysql", 3306);
mysqli_set_charset($conn, "utf8mb4");
require_once __DIR__ . '/../config/personel_log.php';
if (!$conn) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mesaj' => 'Veritabanı hatası']);
    exit;
}

$action = $_POST['action'] ?? '';
$gorusme_id = (int)($_POST['gorusme_listesi_id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');

if ($action === 'ekle') {
    $baslik = mysqli_real_escape_string($conn, trim($_POST['baslik'] ?? 'Not'));
    $icerik = mysqli_real_escape_string($conn, trim($_POST['icerik'] ?? ''));
    $sira = (int)($_POST['sira'] ?? 0);
    if ($gorusme_id <= 0) {
        echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz görüşme']);
        exit;
    }
    $q = mysqli_query($conn, "SELECT MAX(sira) AS mx FROM gorusme_notlari WHERE gorusme_listesi_id = $gorusme_id");
    $r = $q ? mysqli_fetch_assoc($q) : null;
    if ($r && $r['mx'] !== null) $sira = max($sira, (int)$r['mx'] + 1);
    else $sira = 1;
    $sql = "INSERT INTO gorusme_notlari (gorusme_listesi_id, baslik, icerik, sira) VALUES ($gorusme_id, '$baslik', '$icerik', $sira)";
    if (mysqli_query($conn, $sql)) {
        @personel_log_ekle($conn, 'ajax_gorusme_not.php', 'not_ekle', $_POST);
        echo json_encode(['ok' => true, 'id' => (int)mysqli_insert_id($conn), 'mesaj' => 'Not eklendi']);
    } else {
        echo json_encode(['ok' => false, 'mesaj' => mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'guncelle') {
    $id = (int)($_POST['id'] ?? 0);
    $baslik = mysqli_real_escape_string($conn, trim($_POST['baslik'] ?? ''));
    $icerik = mysqli_real_escape_string($conn, trim($_POST['icerik'] ?? ''));
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz not']);
        exit;
    }
    $sql = "UPDATE gorusme_notlari SET baslik = '$baslik', icerik = '$icerik' WHERE id = $id AND gorusme_listesi_id = $gorusme_id";
    if (mysqli_query($conn, $sql)) {
        @personel_log_ekle($conn, 'ajax_gorusme_not.php', 'not_guncelle', $_POST);
        echo json_encode(['ok' => true, 'mesaj' => 'Güncellendi']);
    } else {
        echo json_encode(['ok' => false, 'mesaj' => mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'sil') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz not']);
        exit;
    }
    $sql = "DELETE FROM gorusme_notlari WHERE id = $id AND gorusme_listesi_id = $gorusme_id";
    if (mysqli_query($conn, $sql)) {
        @personel_log_ekle($conn, 'ajax_gorusme_not.php', 'not_sil', $_POST);
        echo json_encode(['ok' => true, 'mesaj' => 'Silindi']);
    } else {
        echo json_encode(['ok' => false, 'mesaj' => mysqli_error($conn)]);
    }
    exit;
}

echo json_encode(['ok' => false, 'mesaj' => 'Geçersiz işlem']);
