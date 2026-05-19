<?php
/**
 * Eski teklif linkleri: sonuclar.php'ye yönlendir (bursluluk sınav sonucu sayfası).
 * Yeni linkler: /sonuclar.php?t=TOKEN&ad=OGRENCI_AD_SOYAD
 */
$query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: sonuclar.php' . $query, true, 301);
exit;
