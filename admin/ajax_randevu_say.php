<?php
/**
 * Seçilen tarih/saatte kaç kişi (veli) için randevu olduğunu döner.
 * GET: tarih=YYYY-MM-DD&saat=HH:mm (dakika sadece 00 veya 30)
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['giris_yapildi']) || $_SESSION['giris_yapildi'] !== true) {
    echo json_encode(['ok' => false, 'count' => 0]);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$tarih = preg_replace('/[^0-9\-]/', '', $_GET['tarih'] ?? '');
$saat  = trim($_GET['saat'] ?? '');
if ($tarih === '' || $saat === '') {
    echo json_encode(['ok' => true, 'count' => 0]);
    exit;
}
// Saat HH:mm -> dakikayı 30 dilimine yuvarla (sadece 00 veya 30)
$parca = explode(':', $saat);
$h = isset($parca[0]) ? (int)$parca[0] : 0;
$m = isset($parca[1]) ? (int)$parca[1] : 0;
$m = ($m < 30) ? 0 : 30;
if ($h >= 24) $h = 23;
$saat_str = sprintf('%02d:%02d:00', $h, $m);
$datetime = $tarih . ' ' . $saat_str;

$tbl = @mysqli_query($conn, "SHOW TABLES LIKE 'gorusme_randevulari'");
if (!$tbl || mysqli_num_rows($tbl) === 0) {
    echo json_encode(['ok' => true, 'count' => 0]);
    exit;
}

$datetime_esc = mysqli_real_escape_string($conn, $datetime);
$q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM gorusme_randevulari WHERE randevu_tarihi = '$datetime_esc'");
$r = $q ? mysqli_fetch_assoc($q) : null;
$count = $r ? (int)$r['cnt'] : 0;
echo json_encode(['ok' => true, 'count' => $count]);
