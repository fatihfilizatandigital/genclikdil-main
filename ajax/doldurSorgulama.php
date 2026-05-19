<?php
// Hataları ekrana basması için bu satırları açıyoruz (test bitince kapatabilirsin)
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("connectt.php");

// Bağlantı kontrolü
if (!$conn) {
    die("Veritabanı bağlantı hatası: " . mysqli_connect_error());
}

$TC = preg_replace('/\s+/', '', $_POST["TC"]); // Boşlukları kaldır
$data = '';

// SQL Sorgusu
$sql = "SELECT * FROM yenibursluluk WHERE TC = '$TC'";
$result = mysqli_query($conn, $sql);

if (!$result) {
    // Sorgu hatası varsa ekrana bas
    echo "SQL Hatası: " . mysqli_error($conn);
    exit();
}

if (mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Not: Eğer veritabanında 'RandevuTur' adında bir sütun yoksa hata verir.
        // O yüzden isset ile kontrol ediyoruz.
        $randevu = isset($row['RandevuTur']) ? $row['RandevuTur'] : 'Belirtilmemiş';
        
        // Veriyi oluştur
        $data .= '<div class="alert alert-success" role="alert">';
        $data .= '<strong>Ad Soyad:</strong> ' . $row['Ad'] . ' ' . $row['Soyad'] . '<br>';
        $data .= '<strong>Şube:</strong> ' . $row['Sube'] . '<br>';
        $data .= '<strong>Durum:</strong> ' . $randevu;
        $data .= '</div>';
    }
} else {
    // Kayıt yoksa kullanıcıya bilgi ver
    $data = '<div class="alert alert-warning" role="alert">Bu TC Kimlik numarası ile kayıt bulunamadı.</div>';
}

echo $data;
?>