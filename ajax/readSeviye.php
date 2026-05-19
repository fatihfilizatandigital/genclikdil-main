<?php
// 1. BAĞLANTIYI KUR (Dosya yolunu otomatik bulur)
if(file_exists("connectt.php")) {
    include("connectt.php");
} elseif(file_exists("../connectt.php")) {
    include("../connectt.php");
} else {
    die('<tr><td colspan="10" class="text-danger">HATA: connectt.php bulunamadı!</td></tr>');
}

// 2. BAĞLANTI KONTROLÜ
if(!isset($conn)) {
    die('<tr><td colspan="10" class="text-danger">HATA: Veritabanı bağlantı değişkeni ($conn) tanımlı değil.</td></tr>');
}

// Türkçe karakter seti
mysqli_set_charset($conn, "utf8");

// --- FİLTRELEME MANTIĞI ---
$whereClause = "WHERE 1=1"; 

// AD SOYAD ARAMA (DÜZELTİLDİ: 'ad' yerine 'ogrenciAd' kullanıyoruz)
if(isset($_GET['ogrenciAd']) && $_GET['ogrenciAd'] != "") {
    $isim = mysqli_real_escape_string($conn, $_GET['ogrenciAd']);
    $whereClause .= " AND (ad_soyad LIKE '%$isim%' OR telefon LIKE '%$isim%')";
}

// Şehir Arama
if(isset($_GET['sehir']) && $_GET['sehir'] != "") {
    $sehir = mysqli_real_escape_string($conn, $_GET['sehir']);
    $whereClause .= " AND sehir LIKE '%$sehir%'";
}

// Dil Filtresi
if(isset($_GET['dil']) && $_GET['dil'] != "") {
    $dil = mysqli_real_escape_string($conn, $_GET['dil']);
    $whereClause .= " AND dil = '$dil'";
}

// Seviye Filtresi
if(isset($_GET['seviye']) && $_GET['seviye'] != "") {
    $seviye = mysqli_real_escape_string($conn, $_GET['seviye']);
    $seviye_kisa = explode(" ", $seviye)[0]; 
    $whereClause .= " AND hesaplanan_seviye LIKE '$seviye_kisa%'";
}

// --- TABLO BAŞLIĞI ---
$data = '<table class="table table-striped table-hover table-bordered">
<thead style="background-color: #8e44ad; color: white;">
<tr>
    <th>#</th>
    <th>AD SOYAD</th>
    <th>TELEFON</th>
    <th>YAŞ</th>
    <th>ŞEHİR</th>
    <th>DİL</th>
    <th>AMAÇ</th>
    <th>BAŞLANGIÇ</th>
    <th>SONUÇ SEVİYESİ</th>
    <th>TARİH</th>
</tr>
</thead>
<tbody>';

// --- SORGUYU ÇALIŞTIR ---
$sql = "SELECT * FROM tbl_seviye_tespit $whereClause ORDER BY id DESC";
$result = mysqli_query($conn, $sql);

if(!$result){
    echo '<tr><td colspan="10" class="text-danger">SQL Hatası: '.mysqli_error($conn).'</td></tr>';
    exit;
}

if(mysqli_num_rows($result) > 0){
    while($row = mysqli_fetch_assoc($result)) {
        
        $tarih = date("d.m.Y H:i", strtotime($row['tarih']));
        
        // Seviye Renklendirme
        $sonuc = $row['hesaplanan_seviye'];
        $badgeClass = "bg-secondary"; 
        
        if(strpos($sonuc, 'A1') !== false) $badgeClass = "bg-A1";
        if(strpos($sonuc, 'A2') !== false) $badgeClass = "bg-A2";
        if(strpos($sonuc, 'B1') !== false) $badgeClass = "bg-B1";
        if(strpos($sonuc, 'B2') !== false) $badgeClass = "bg-B2";
        if(strpos($sonuc, 'C1') !== false) $badgeClass = "bg-C1";
        if(strpos($sonuc, 'C2') !== false) $badgeClass = "bg-C2";

        $data .= '<tr>
            <td>'.$row['id'].'</td>
            <td><strong>'.$row['ad_soyad'].'</strong></td>
            <td>'.$row['telefon'].'</td>
            <td>'.$row['yas'].'</td>
            <td style="text-transform: capitalize;">'.$row['sehir'].'</td>
            <td>'.$row['dil'].'</td>
            <td>'.$row['amac'].'</td>
            <td style="color:#777; font-size:12px;">'.$row['baslangic_seviye'].'</td>
            <td style="font-size:16px;"><span class="badge-level '.$badgeClass.'">'.$sonuc.'</span></td>
            <td style="font-size:12px; color:#555;">'.$tarih.'</td>
        </tr>';
    }
} else {
    $data .= '<tr><td colspan="10" class="text-center" style="padding:20px;">Aradığınız kriterlere uygun kayıt bulunamadı.</td></tr>';
}

$data .= '</tbody></table>';
echo $data;
?>