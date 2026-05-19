<?php
/**
 * Veritabanı tabloları ve gorusme_fiyat_teklifi yapısı / örnek veri.
 * Hatayı bulmak için bir kez çalıştırıp çıktıyı inceleyin; işiniz bitince dosyayı silebilirsiniz.
 */
require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style='background:#1e293b;color:#e2e8f0;padding:16px;font-size:13px;'>\n";

echo "=== BAĞLANTI ===\n";
echo $conn ? "OK\n" : "HATA\n";

echo "\n=== TABLOLAR (SHOW TABLES) ===\n";
$tables = mysqli_query($conn, "SHOW TABLES");
if ($tables) {
    while ($row = mysqli_fetch_array($tables)) {
        echo $row[0] . "\n";
    }
} else {
    echo "Hata: " . mysqli_error($conn) . "\n";
}

echo "\n=== gorusme_fiyat_teklifi SÜTUNLARI (DESCRIBE) ===\n";
$desc = mysqli_query($conn, "DESCRIBE gorusme_fiyat_teklifi");
if ($desc) {
    while ($r = mysqli_fetch_assoc($desc)) {
        echo $r['Field'] . " | " . $r['Type'] . " | " . $r['Null'] . " | " . $r['Default'] . "\n";
    }
} else {
    echo "Hata (tablo yok olabilir): " . mysqli_error($conn) . "\n";
}

echo "\n=== paylasim_token OLAN BİR KAYIT (SELECT *) ===\n";
$sel = mysqli_query($conn, "SELECT * FROM gorusme_fiyat_teklifi WHERE paylasim_token IS NOT NULL AND paylasim_token != '' LIMIT 1");
if ($sel && mysqli_num_rows($sel) > 0) {
    $row = mysqli_fetch_assoc($sel);
    foreach ($row as $k => $v) {
        $len = $v === null ? 'NULL' : (is_string($v) ? strlen($v) . ' char' : '');
        $preview = $v === null ? 'NULL' : (is_string($v) && strlen($v) > 80 ? substr($v, 0, 80) . '...' : $v);
        echo "[$k] => " . var_export($preview, true) . " $len\n";
    }
    if (isset($row['hesap_detay']) && $row['hesap_detay'] !== null && $row['hesap_detay'] !== '') {
        $dec = json_decode($row['hesap_detay'], true);
        echo "\nhesap_detay json_decode: " . (is_array($dec) ? "array, " . count($dec) . " eleman" : "HATA json_last_error=" . json_last_error()) . "\n";
    } else {
        echo "\nhesap_detay: boş veya NULL\n";
    }
} else {
    echo "Kayıt yok veya paylasim_token sütunu yok. Hata: " . mysqli_error($conn) . "\n";
    $any = mysqli_query($conn, "SELECT id, gorusme_listesi_id, toplam_fiyat, hesap_detay, personel FROM gorusme_fiyat_teklifi LIMIT 1");
    if ($any && mysqli_num_rows($any) > 0) {
        echo "\nİlk kayıt (paylasim_token olmadan):\n";
        print_r(mysqli_fetch_assoc($any));
    }
}

echo "\n</pre>";
