<?php
/**
 * Yönetim paneli erişim kontrolü.
 * Giriş yapmış tüm personel panele girebilir (admin ayrımı yok).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['giris_yapildi']) || $_SESSION['giris_yapildi'] !== true) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = (preg_match('#[/\\\\]admin[/\\\\]panel[/\\\\]#', $script)) ? '../../' : '../';
    header('Location: ' . $base . 'giris.php');
    exit;
}
