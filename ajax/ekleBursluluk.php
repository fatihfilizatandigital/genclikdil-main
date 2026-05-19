<?php 
include("connectt.php");

// Türkçe karakter sorunu olmaması için
mysqli_set_charset($conn, "utf8");

// JS'den gelen ORİJİNAL isimleri karşılıyoruz
$Cinsiyet    = $_POST["Cinsiyet"];
$Ad          = $_POST["Ad"];
$Soyad       = $_POST["Soyad"];
$TC          = preg_replace('/\s+/', '', $_POST["TC"]); // Boşlukları kaldır
$Dogum       = $_POST["Dogum"];      // JS artık 'Dogum' olarak gönderiyor
$Okul        = $_POST["Okul"];       // JS artık 'Okul' olarak gönderiyor
$Sinif       = $_POST["Sinif"];
$VeliAd      = $_POST["VeliAd"];
$VeliSoyad   = $_POST["VeliSoyad"];
$VeliTel1    = $_POST["VeliTel1"];   // JS artık 'VeliTel1' olarak gönderiyor
$VeliTel2    = $_POST["VeliTel2"];
$VeliMeslek  = $_POST["VeliMeslek"];
$VeliEmail   = $_POST["VeliEmail"];  // JS artık 'VeliEmail' olarak gönderiyor

// Sabit veya JS'den gelen diğer veriler
$Sube        = isset($_POST["Sube"]) ? $_POST["Sube"] : "Amerikan Kültür";
$RandevuTur  = isset($_POST["RandevuTur"]) ? $_POST["RandevuTur"] : "Bursluluk";

// YENİ EKLENEN
$SinavTuru   = $_POST["SinavTuru"];


// 1. Kayıt Var mı Kontrolü
$sqlKayit = "SELECT * FROM yenibursluluk WHERE TC='$TC'";
$kayitVarMi = mysqli_query($conn, $sqlKayit);

if (!$kayitVarMi) {
    die('Sorgu Hatası: ' . mysqli_error($conn));
}

if(mysqli_num_rows($kayitVarMi) > 0){
    // Orijinal yapıdaki gibi sadece "kayitli" yazıyoruz.
    // JS tarafı bunu yakalayıp uyarı verecek.
    echo "kayitli";
}
else {       
    // 2. Yeni Kayıt Ekleme (SinavTuru eklendi)
    $sql = "INSERT INTO yenibursluluk (
                Cinsiyet, Ad, Soyad, TC, Dogum, Okul, Sinif, 
                VeliAd, VeliSoyad, VeliTel1, VeliTel2, VeliMeslek, VeliEmail, 
                Sube, RandevuTur, SinavTuru, Tarih
            )
            VALUES (
                '$Cinsiyet', '$Ad', '$Soyad', '$TC', '$Dogum', '$Okul', '$Sinif', 
                '$VeliAd', '$VeliSoyad', '$VeliTel1', '$VeliTel2', '$VeliMeslek', '$VeliEmail', 
                '$Sube', '$RandevuTur', '$SinavTuru', now()
            )";

    if (mysqli_query($conn, $sql)) {
        // Orijinal yapıdaki gibi "eklendi" yazıyoruz.
        echo "eklendi";
    } else {
        echo "Hata: " . $sql . "<br>" . mysqli_error($conn);
    }

    mysqli_close($conn);
}
?>