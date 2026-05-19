<?php 
// Çalışan dosyanızdaki (ekleBursluluk.php) bağlantı dosyasını dahil ediyoruz
include("connectt.php");

// Türkçe karakter sorunu olmaması için (Referans dosyanızdaki gibi)
if (isset($conn)) {
    mysqli_set_charset($conn, "utf8");
} else {
    die("Veritabanı bağlantı hatası: \$conn değişkeni bulunamadı. connectt.php dosyasını kontrol edin.");
}

if ($_POST) {
    // Verileri Al ve SQL Injection'a Karşı Temizle
    // (Referans dosyanızda direk $_POST alınmış ama burada güvenliği artırmak için escape yaptık)
    $ad_soyad   = mysqli_real_escape_string($conn, trim($_POST['ad']));
    $telefon    = mysqli_real_escape_string($conn, trim($_POST['tel']));
    $yas        = intval($_POST['yas']);
    $sehir      = mysqli_real_escape_string($conn, trim($_POST['sehir']));
    $dil        = mysqli_real_escape_string($conn, trim($_POST['dil']));
    $baslangic  = trim($_POST['beyanSeviye']);
    $amac       = mysqli_real_escape_string($conn, trim($_POST['amac']));
    $sonuc      = mysqli_real_escape_string($conn, trim($_POST['sonuc']));
    $ip         = $_SERVER['REMOTE_ADDR'];

    // Beyan Seviyesini (0,1,2,3) Anlaşılır Metne Çevir
    $seviyeTanimi = "Belirsiz";
    if($baslangic == "0") $seviyeTanimi = "Sıfır";
    elseif($baslangic == "1") $seviyeTanimi = "Temel";
    elseif($baslangic == "2") $seviyeTanimi = "Orta";
    elseif($baslangic == "3") $seviyeTanimi = "İleri";

    // SQL Sorgusu (tbl_seviye_tespit tablosuna kayıt)
    $sql = "INSERT INTO tbl_seviye_tespit 
            (ad_soyad, telefon, yas, sehir, dil, baslangic_seviye, amac, hesaplanan_seviye, ip_adresi) 
            VALUES 
            ('$ad_soyad', '$telefon', '$yas', '$sehir', '$dil', '$seviyeTanimi', '$amac', '$sonuc', '$ip')";

    // Sorguyu Çalıştır (Referans dosyanızdaki mysqli_query yapısı)
    if (mysqli_query($conn, $sql)) {
        echo "basarili";
    } else {
        // Hata varsa detayını göster
        echo "Hata: " . mysqli_error($conn);
    }
    
    // Bağlantıyı kapat
    mysqli_close($conn);

} else {
    echo "POST verisi gelmedi.";
}
?>