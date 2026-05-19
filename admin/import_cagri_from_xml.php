<?php
/**
 * yenibursluluk.xml dosyasından KatilimDurumu=1 olan kayıtları
 * cagri_listesi (aramalar sayfası listesi) tablosuna ekler.
 * VeliTel1 -> tel_orijinal (orijinal), tel_temiz (sadece rakam, 0 ile başlayan format).
 */
require_once __DIR__ . '/auth.php';
if (($_SESSION['yetki'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/personel_log.php';

$xml_path = __DIR__ . '/yenibursluluk.xml';
if (!is_readable($xml_path)) {
    die("XML dosyası bulunamadı veya okunamıyor: admin/yenibursluluk.xml");
}

// cagri_listesi tablosu var mı?
$tbl = @mysqli_query($conn, "SHOW TABLES LIKE 'cagri_listesi'");
if (!$tbl || mysqli_num_rows($tbl) === 0) {
    die("cagri_listesi tablosu veritabanında yok. Önce bu tabloyu oluşturmanız gerekiyor.");
}

function normalize_tel($raw) {
    $digits = preg_replace('/\D+/', '', $raw);
    if (strlen($digits) >= 10 && strlen($digits) <= 11) {
        if (strlen($digits) === 10 && substr($digits, 0, 1) === '5') {
            $digits = '0' . $digits;
        }
        if (strlen($digits) === 11 && substr($digits, 0, 1) !== '0') {
            $digits = '0' . substr($digits, 0, 10);
        }
    }
    return $digits;
}

function getColumnValue($tableNode, $colName) {
    foreach ($tableNode->column as $col) {
        $name = (string)$col['name'];
        if (strcasecmp($name, $colName) === 0) {
            return trim((string)$col);
        }
    }
    return '';
}

libxml_use_internal_errors(true);
$xml = @simplexml_load_file($xml_path);
if ($xml === false) {
    die("XML ayrıştırılamadı.");
}

$eklenen = 0;
$atlanan = 0;
$hata = 0;

// phpMyAdmin export: <database><table name="yenibursluluk"> veya doğrudan <table>
$tables = isset($xml->database) ? $xml->database->table : $xml->table;
if (!isset($tables)) {
    die("XML içinde tablo bulunamadı.");
}
foreach ($tables as $table) {
    if (strcasecmp((string)$table['name'], 'yenibursluluk') !== 0) continue;

    $katilim = getColumnValue($table, 'KatilimDurumu');
    if ((string)$katilim !== '1') continue;

    $veliAd    = getColumnValue($table, 'VeliAd');
    $veliSoyad = getColumnValue($table, 'VeliSoyad');
    $veliTel1  = getColumnValue($table, 'VeliTel1');
    $ad        = getColumnValue($table, 'Ad');
    $soyad     = getColumnValue($table, 'Soyad');
    $sinif     = (int)getColumnValue($table, 'Sinif');

    if (trim($veliTel1) === '') continue;

    $tel_temiz = normalize_tel($veliTel1);
    if (strlen($tel_temiz) < 10) continue;

    $veli_ad      = mysqli_real_escape_string($conn, $veliAd);
    $veli_soyad   = mysqli_real_escape_string($conn, $veliSoyad);
    $tel_orijinal = mysqli_real_escape_string($conn, $veliTel1);
    $tel_temiz_esc = mysqli_real_escape_string($conn, $tel_temiz);
    $ogrenci_ad   = mysqli_real_escape_string($conn, $ad);
    $ogrenci_soyad= mysqli_real_escape_string($conn, $soyad);

    // Aynı veli+öğrenci+tel+sinif zaten var mı?
    $check = mysqli_query($conn, "SELECT 1 FROM cagri_listesi WHERE tel_temiz = '$tel_temiz_esc' AND ogrenci_ad = '$ogrenci_ad' AND ogrenci_soyad = '$ogrenci_soyad' AND sinif = $sinif LIMIT 1");
    if ($check && mysqli_num_rows($check) > 0) {
        $atlanan++;
        continue;
    }

    $sql = "INSERT INTO cagri_listesi (veli_ad, veli_soyad, tel_temiz, tel_orijinal, ogrenci_ad, ogrenci_soyad, sinif, arama_durumu) 
            VALUES ('$veli_ad', '$veli_soyad', '$tel_temiz_esc', '$tel_orijinal', '$ogrenci_ad', '$ogrenci_soyad', $sinif, 'Bekliyor')";
    if (mysqli_query($conn, $sql)) {
        $eklenen++;
    } else {
        $hata++;
    }
}

$mesaj = "İşlem tamamlandı. Eklenen: $eklenen, Zaten var (atlanan): $atlanan" . ($hata > 0 ? ", Hata: $hata" : "") . ".";
@personel_log_ekle($conn, 'import_cagri_from_xml.php', 'import', [], ['eklenen' => $eklenen, 'atlanan' => $atlanan, 'hata' => $hata]);
header("Location: aramalar.php?import_sonuc=" . urlencode($mesaj));
exit;
