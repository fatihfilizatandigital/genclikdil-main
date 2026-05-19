<?php
date_default_timezone_set('Europe/Istanbul'); // Saat dilimi ayarı

if(file_exists("../connectt.php")) {
    include("../connectt.php");
} elseif(file_exists("connectt.php")) {
    include("connectt.php");
} else {
    die("Bağlantı hatası");
}

if(isset($_POST['id']) && isset($_POST['durum'])) {
    
    $id = mysqli_real_escape_string($conn, $_POST['id']);
    $durum = mysqli_real_escape_string($conn, $_POST['durum']);
    
    // Şu anki tarih ve saat
    $simdi = date("Y-m-d H:i:s");
    // Kullanıcıya göstermek için formatlı hali
    $gosterilenTarih = date("d.m.Y H:i");

    // Sadece 0 veya 1 izin ver
    if($durum != '0' && $durum != '1') {
        die("Hata: Geçersiz durum");
    }

    // Hem durumu hem tarihi güncelle
    $sql = "UPDATE yenibursluluk SET KatilimDurumu = '$durum', KatilimTarihi = '$simdi' WHERE ID = '$id'";
    
    if(mysqli_query($conn, $sql)) {
        // Başarılıysa ekrana yeni saati yazdırıyoruz (JS bunu alıp butona yazacak)
        echo $gosterilenTarih;
    } else {
        echo "Hata: " . mysqli_error($conn);
    }
}
?>