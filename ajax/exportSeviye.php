<?php
session_start();
// YETKİ KONTROLÜ
if(!isset($_SESSION['logged_in']) || $_SESSION['yetki'] != 'admin'){
    die("Yetkisiz erişim!");
}

// BAĞLANTI
if(file_exists("connectt.php")) {
    include("connectt.php");
} elseif(file_exists("../connectt.php")) {
    include("../connectt.php");
}

mysqli_set_charset($conn, "utf8");

// FİLTRELEME
$whereClause = "WHERE 1=1"; 

if(isset($_GET['ogrenciAd']) && $_GET['ogrenciAd'] != "") {
    $isim = mysqli_real_escape_string($conn, $_GET['ogrenciAd']);
    $whereClause .= " AND (ad_soyad LIKE '%$isim%' OR telefon LIKE '%$isim%')";
}

if(isset($_GET['sehir']) && $_GET['sehir'] != "") {
    $sehir = mysqli_real_escape_string($conn, $_GET['sehir']);
    $whereClause .= " AND sehir LIKE '%$sehir%'";
}

if(isset($_GET['dil']) && $_GET['dil'] != "") {
    $dil = mysqli_real_escape_string($conn, $_GET['dil']);
    $whereClause .= " AND dil = '$dil'";
}

if(isset($_GET['seviye']) && $_GET['seviye'] != "") {
    $seviye = mysqli_real_escape_string($conn, $_GET['seviye']);
    $seviye_kisa = explode(" ", $seviye)[0]; 
    $whereClause .= " AND hesaplanan_seviye LIKE '$seviye_kisa%'";
}

// Sorgu
$sql = "SELECT * FROM tbl_seviye_tespit $whereClause ORDER BY id DESC";
$result = mysqli_query($conn, $sql);

// Excel Header'ları
$dosyaAdi = "Seviye_Tespit_Sonuclari_" . date("Y-m-d_H-i") . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$dosyaAdi\"");
header("Pragma: no-cache");
header("Expires: 0");

// BOM for UTF-8
echo "\xEF\xBB\xBF";

// Excel için HTML tablo formatı
echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>';
echo '<body>';

echo '<table border="1">';
echo '<thead>';
echo '<tr style="background-color: #8e44ad; color: white; font-weight: bold;">';
echo '<th>ID</th>';
echo '<th>Ad Soyad</th>';
echo '<th>Telefon</th>';
echo '<th>Yaş</th>';
echo '<th>Şehir</th>';
echo '<th>Dil</th>';
echo '<th>Amaç</th>';
echo '<th>Başlangıç Seviyesi</th>';
echo '<th>Hesaplanan Seviye</th>';
echo '<th>Kayıt Tarihi</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

if(mysqli_num_rows($result) > 0){
    while($row = mysqli_fetch_assoc($result)) {
        $tarih = date("d.m.Y H:i", strtotime($row['tarih']));
        
        echo '<tr>';
        echo '<td>'.$row['id'].'</td>';
        echo '<td>'.$row['ad_soyad'].'</td>';
        echo '<td style="mso-number-format:\@">'.$row['telefon'].'</td>';
        echo '<td>'.$row['yas'].'</td>';
        echo '<td>'.$row['sehir'].'</td>';
        echo '<td>'.$row['dil'].'</td>';
        echo '<td>'.$row['amac'].'</td>';
        echo '<td>'.$row['baslangic_seviye'].'</td>';
        echo '<td>'.$row['hesaplanan_seviye'].'</td>';
        echo '<td>'.$tarih.'</td>';
        echo '</tr>';
    }
}

echo '</tbody>';
echo '</table>';
echo '</body></html>';

mysqli_close($conn);
?>
