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

// Ad parametresi
if(isset($_GET['ogrenciAd']) && $_GET['ogrenciAd'] != "") {
    $ad = mysqli_real_escape_string($conn, $_GET['ogrenciAd']);
    $whereClause .= " AND Ad LIKE '%$ad%'";
}

// Soyad parametresi (Ad ile birlikte çalışır - AND mantığı)
if(isset($_GET['ogrenciSoyad']) && $_GET['ogrenciSoyad'] != "") {
    $soyad = mysqli_real_escape_string($conn, $_GET['ogrenciSoyad']);
    $whereClause .= " AND Soyad LIKE '%$soyad%'";
}

// TC Kimlik No parametresi (format farklarını dikkate alarak - boşluklu/boşluksuz)
if(isset($_GET['ogrenciTC']) && $_GET['ogrenciTC'] != "") {
    $tc = mysqli_real_escape_string($conn, $_GET['ogrenciTC']);
    // Boşlukları ve tireleri kaldırarak karşılaştırma yap
    $tcTemiz = preg_replace('/[\s\-]+/', '', $tc);
    $whereClause .= " AND REPLACE(REPLACE(TC, ' ', ''), '-', '') LIKE '%$tcTemiz%'";
}

// Telefon numarası parametresi (sadece VeliTel1 ve VeliTel2'de ara - format farklarını dikkate alarak)
if(isset($_GET['ogrenciNumara']) && $_GET['ogrenciNumara'] != "") {
    $numara = mysqli_real_escape_string($conn, $_GET['ogrenciNumara']);
    // Boşlukları, tireleri ve parantezleri kaldırarak karşılaştırma yap
    $numaraTemiz = preg_replace('/[\s\-\(\)]+/', '', $numara);
    
    // Hem "0 555" hem "9 055" formatlarını kontrol et
    // Eğer numara 9 ile başlıyorsa, hem "9" hem "0" ile başlayan versiyonlarını ara
    if(preg_match('/^9/', $numaraTemiz)) {
        $numaraAlternatif = '0' . substr($numaraTemiz, 1);
        $whereClause .= " AND (";
        $whereClause .= "REPLACE(REPLACE(REPLACE(REPLACE(VeliTel1, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraTemiz%'";
        $whereClause .= " OR REPLACE(REPLACE(REPLACE(REPLACE(VeliTel1, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraAlternatif%'";
        $whereClause .= " OR REPLACE(REPLACE(REPLACE(REPLACE(VeliTel2, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraTemiz%'";
        $whereClause .= " OR REPLACE(REPLACE(REPLACE(REPLACE(VeliTel2, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraAlternatif%'";
        $whereClause .= ")";
    } else {
        // Normal arama (0 ile başlayan veya diğer formatlar)
        $whereClause .= " AND (";
        $whereClause .= "REPLACE(REPLACE(REPLACE(REPLACE(VeliTel1, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraTemiz%'";
        $whereClause .= " OR REPLACE(REPLACE(REPLACE(REPLACE(VeliTel2, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraTemiz%'";
        $whereClause .= ")";
    }
}

if(isset($_GET['sinav']) && $_GET['sinav'] != "") {
    $sinav = mysqli_real_escape_string($conn, $_GET['sinav']);
    $whereClause .= " AND SinavTuru = '$sinav'";
}

if(isset($_GET['sinif']) && $_GET['sinif'] != "") {
    $sinif = mysqli_real_escape_string($conn, $_GET['sinif']);
    $whereClause .= " AND Sinif = '$sinif'";
}

// Sorgu
$sql = "SELECT * FROM yenibursluluk $whereClause ORDER BY ID DESC";
$result = mysqli_query($conn, $sql);

// Excel Header'ları
$dosyaAdi = "Bursluluk_Basvurulari_" . date("Y-m-d_H-i") . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$dosyaAdi\"");
header("Pragma: no-cache");
header("Expires: 0");

// BOM for UTF-8
echo "\xEF\xBB\xBF";

// Excel için HTML tablo formatı (Excel bunu okuyabilir)
echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>';
echo '<body>';

echo '<table border="1">';
echo '<thead>';
echo '<tr style="background-color: #34495e; color: white; font-weight: bold;">';
echo '<th>ID</th>';
echo '<th>Sınav Türü</th>';
echo '<th>Adı</th>';
echo '<th>Soyadı</th>';
echo '<th>Cinsiyet</th>';
echo '<th>Sınıf</th>';
echo '<th>TC No</th>';
echo '<th>Doğum Tarihi</th>';
echo '<th>Okul</th>';
echo '<th>Veli Adı</th>';
echo '<th>Veli Soyadı</th>';
echo '<th>Veli Tel 1</th>';
echo '<th>Veli Tel 2</th>';
echo '<th>Veli Meslek</th>';
echo '<th>Veli Email</th>';
echo '<th>Şube</th>';
echo '<th>Kayıt Tarihi</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

if(mysqli_num_rows($result) > 0){
    while($row = mysqli_fetch_assoc($result)) {
        $kayitTarihi = date("d.m.Y H:i", strtotime($row['Tarih']));
        
        echo '<tr>';
        echo '<td>'.$row['ID'].'</td>';
        echo '<td>'.$row['SinavTuru'].'</td>';
        echo '<td>'.$row['Ad'].'</td>';
        echo '<td>'.$row['Soyad'].'</td>';
        echo '<td>'.$row['Cinsiyet'].'</td>';
        echo '<td>'.$row['Sinif'].'</td>';
        echo '<td style="mso-number-format:\@">'.$row['TC'].'</td>'; // TC'yi metin olarak formatla
        echo '<td>'.$row['Dogum'].'</td>';
        echo '<td>'.$row['Okul'].'</td>';
        echo '<td>'.$row['VeliAd'].'</td>';
        echo '<td>'.$row['VeliSoyad'].'</td>';
        echo '<td style="mso-number-format:\@">'.$row['VeliTel1'].'</td>';
        echo '<td style="mso-number-format:\@">'.$row['VeliTel2'].'</td>';
        echo '<td>'.$row['VeliMeslek'].'</td>';
        echo '<td>'.$row['VeliEmail'].'</td>';
        echo '<td>'.$row['Sube'].'</td>';
        echo '<td>'.$kayitTarihi.'</td>';
        echo '</tr>';
    }
}

echo '</tbody>';
echo '</table>';
echo '</body></html>';

mysqli_close($conn);
?>
