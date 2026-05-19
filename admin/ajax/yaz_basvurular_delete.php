<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, "utf8mb4");

$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Geçersiz ID']);
    exit;
}

$stmt = mysqli_prepare($conn, "DELETE FROM yaz_kampanya_basvurular WHERE id = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)]);
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $id);
if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Kayıt silinemedi veya bulunamadı.']);
}
mysqli_stmt_close($stmt);
