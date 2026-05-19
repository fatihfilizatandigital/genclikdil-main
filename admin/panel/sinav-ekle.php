<?php
/**
 * Öğrenciye sınav türü ekler (bursluluk_ogrenci_sinav) ve not girişine yönlendirir.
 */
require_once __DIR__ . '/../auth.php';
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/personel_log.php';

$ogrenci_id = (int)($_GET['ogrenci_id'] ?? 0);
$sinav_turu = trim($_GET['sinav_turu'] ?? '');
$geri_sinif = trim($_GET['sinif'] ?? '');
$geri_ara = trim($_GET['ara'] ?? '');
$geri_query = ($geri_sinif !== '' ? '&sinif=' . urlencode($geri_sinif) : '') . ($geri_ara !== '' ? '&ara=' . urlencode($geri_ara) : '');

if ($ogrenci_id <= 0 || !in_array($sinav_turu, ['İngilizce', 'Almanca'], true)) {
    header('Location: ogrenci-listesi.php' . ($geri_query ? '?' . ltrim($geri_query, '&') : ''));
    exit;
}

$stmt = mysqli_prepare($conn, "INSERT IGNORE INTO bursluluk_ogrenci_sinav (ogrenci_id, sinav_turu) VALUES (?, ?)");
mysqli_stmt_bind_param($stmt, "is", $ogrenci_id, $sinav_turu);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
@personel_log_ekle($conn, 'panel/sinav-ekle.php', 'sinav_ekle', [], ['ogrenci_id' => $ogrenci_id, 'sinav_turu' => $sinav_turu]);

header('Location: not-giris.php?ogrenci_id=' . $ogrenci_id . '&sinav_turu=' . urlencode($sinav_turu) . $geri_query);
exit;
