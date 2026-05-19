<?php
/**
 * yenibursluluk-1.xml dosyasından KatilimDurumu=1 öğrencileri import eder.
 */
require_once __DIR__ . '/../auth.php';
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/personel_log.php';

$xml_path = __DIR__ . '/yenibursluluk-1.xml';
$mesaj = '';
$hata = '';
$istatistik = ['eklenen' => 0, 'guncellenen' => 0, 'sinav_kayit' => 0, 'atlanan' => 0];

if (!file_exists($xml_path)) {
    $hata = 'XML dosyası bulunamadı: yenibursluluk-1.xml';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['onay'])) {
    $t = @mysqli_query($conn, "SHOW TABLES LIKE 'bursluluk_ogrenciler'");
    if (!$t || mysqli_num_rows($t) === 0) {
        $hata = 'Önce database/schema_ogrenciler.sql ve migrate_ogrenci_dogum.sql dosyalarını çalıştırın.';
    } else {
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_file($xml_path);
    if ($xml === false) {
        $hata = 'XML okunamadı.';
    } else {
        $tables = @$xml->xpath('//*[local-name()="table" and @name="yenibursluluk"]');
        if (empty($tables)) {
            $tables = isset($xml->database->table) ? (is_array($xml->database->table) ? $xml->database->table : [$xml->database->table]) : [];
        }
        foreach ($tables as $table) {
            $row = [];
            foreach ($table->column as $col) {
                $name = (string)($col['name'] ?? '');
                $row[$name] = trim((string)$col);
            }
            $katilim = (int)($row['KatilimDurumu'] ?? 0);
            if ($katilim !== 1) {
                $istatistik['atlanan']++;
                continue;
            }
            $kaynak_id = (int)($row['ID'] ?? 0);
            if ($kaynak_id <= 0) continue;

            $ad = trim($row['Ad'] ?? '');
            $soyad = trim($row['Soyad'] ?? '');
            $veli_ad = trim($row['VeliAd'] ?? '');
            $veli_soyad = trim($row['VeliSoyad'] ?? '');
            $veli_tel1 = trim($row['VeliTel1'] ?? '');
            $veli_tel2 = trim($row['VeliTel2'] ?? '');
            $sinif_raw = trim($row['Sinif'] ?? '');
            $dogum_raw = trim($row['Dogum'] ?? '');
            $sube = trim($row['Sube'] ?? '');
            $sinav_turu_raw = trim($row['SinavTuru'] ?? '');

            if ($ad === '' || $soyad === '') continue;

            $sinif = $sinif_raw;
            if (preg_match('/^\d+$/', $sinif)) {
                $sinif = $sinif . '. sınıf';
            }

            $dogum_tarihi = null;
            if ($dogum_raw !== '' && strtoupper($dogum_raw) !== 'NULL') {
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dogum_raw, $m)) {
                    $dogum_tarihi = $m[1] . '-' . $m[2] . '-' . $m[3];
                } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dogum_raw, $m)) {
                    $dogum_tarihi = $m[3] . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
                } elseif (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $dogum_raw, $m)) {
                    $dogum_tarihi = $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[3], 2, '0', STR_PAD_LEFT);
                }
            }

            $sinav_turleri = [];
            if (stripos($sube, 'Merkez') !== false) {
                $st = str_replace([' ', 'ı', 'İ'], ['', 'i', 'I'], $sinav_turu_raw);
                if (stripos($st, 'HerIkisi') !== false || stripos($st, 'herikisi') !== false) {
                    $sinav_turleri = ['İngilizce', 'Almanca'];
                } elseif (stripos($st, 'Almanca') !== false) {
                    $sinav_turleri = ['Almanca'];
                } else {
                    $sinav_turleri = ['İngilizce'];
                }
            } elseif (stripos($sube, 'Amerikan') !== false) {
                $sinav_turleri = ['İngilizce', 'Almanca'];
            } elseif (stripos($sube, 'İngiliz') !== false) {
                $sinav_turleri = ['İngilizce'];
            } else {
                $sinav_turleri = ['İngilizce'];
            }

            $stmt = mysqli_prepare($conn, "INSERT INTO bursluluk_ogrenciler (kaynak_id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, dogum_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE ogrenci_adi=VALUES(ogrenci_adi), ogrenci_soyadi=VALUES(ogrenci_soyadi), veli_adi=VALUES(veli_adi), veli_soyadi=VALUES(veli_soyadi), veli_telefon=VALUES(veli_telefon), veli_telefon_2=VALUES(veli_telefon_2), sinif=VALUES(sinif), dogum_tarihi=VALUES(dogum_tarihi)");
            $tel2 = $veli_tel2 === '' ? null : $veli_tel2;
            mysqli_stmt_bind_param($stmt, "issssssss", $kaynak_id, $ad, $soyad, $veli_ad, $veli_soyad, $veli_tel1, $tel2, $sinif, $dogum_tarihi);
            mysqli_stmt_execute($stmt);
            $ar = mysqli_affected_rows($conn);
            if ($ar === 1) $istatistik['eklenen']++;
            elseif ($ar >= 2) $istatistik['guncellenen']++;
            $ogrenci_id = mysqli_insert_id($conn);
            if ($ogrenci_id === 0) {
                $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM bursluluk_ogrenciler WHERE kaynak_id = " . (int)$kaynak_id));
                $ogrenci_id = $r ? (int)$r['id'] : 0;
            }
            mysqli_stmt_close($stmt);

            if ($ogrenci_id > 0) {
                foreach ($sinav_turleri as $tur) {
                    $ins = mysqli_prepare($conn, "INSERT IGNORE INTO bursluluk_ogrenci_sinav (ogrenci_id, sinav_turu) VALUES (?, ?)");
                    mysqli_stmt_bind_param($ins, "is", $ogrenci_id, $tur);
                    mysqli_stmt_execute($ins);
                    if (mysqli_affected_rows($conn) > 0) $istatistik['sinav_kayit']++;
                    mysqli_stmt_close($ins);
                }
            }
        }
        $mesaj = 'Import tamamlandı. Eklenen: ' . $istatistik['eklenen'] . ', Güncellenen: ' . $istatistik['guncellenen'] . ', Sınav kaydı: ' . $istatistik['sinav_kayit'] . ', Atlanan (KatilimDurumu≠1): ' . $istatistik['atlanan'];
        @personel_log_ekle($conn, 'panel/xml-import.php', 'import', $_POST, ['istatistik' => $istatistik]);
    }
    }
}
$aktif_personel = $_SESSION['personel_adi'] ?? '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XML import — Sınav not girişi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --g-primary: #0d9488; --g-primary-dark: #0f766e; --g-bg: #f0f4f8; --g-card: #fff; --g-border: #e2e8f0; --g-radius: 12px; --g-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--g-bg); color: #1e293b; }
        .top-bar { background: linear-gradient(135deg, var(--g-primary) 0%, var(--g-primary-dark) 100%); padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(13,148,136,0.25); }
        .top-bar .brand { color: #fff; font-weight: 700; font-size: 1.2rem; }
        .top-bar .btn-outline-light { border-color: rgba(255,255,255,0.6); color: #fff; }
        .top-bar .btn-outline-light:hover { background: rgba(255,255,255,0.2); color: #fff; }
        .panel-card { background: var(--g-card); border-radius: var(--g-radius); box-shadow: var(--g-shadow); border: 1px solid var(--g-border); padding: 24px; margin-bottom: 20px; }
        .btn-panel { background: var(--g-primary); border-color: var(--g-primary); color: #fff; }
        .btn-panel:hover { background: var(--g-primary-dark); color: #fff; }
    </style>
</head>
<body>
<div class="top-bar">
    <div class="brand">Sınav not girişi <span>— XML import</span></div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-white-50"><?= htmlspecialchars($aktif_personel) ?></span>
        <a href="index.php" class="btn btn-sm btn-outline-light">Ana sayfa</a>
    </div>
</div>
<div class="container py-4">
    <div class="panel-card" style="max-width: 640px;">
        <h1 class="h5 fw-bold mb-2">XML öğrenci import</h1>
        <p class="small text-muted mb-3">yenibursluluk-1.xml içinden <strong>KatilimDurumu=1</strong> olan öğrencileri sisteme ekler. Şube: Amerikan Kültür → her iki sınav; İngiliz Kültür → İngilizce; Merkez → SinavTuru (İngilizce / Almanca / Her Ikisi).</p>
        <?php if ($mesaj): ?><div class="alert alert-success py-2"><?= htmlspecialchars($mesaj) ?></div><?php endif; ?>
        <?php if ($hata): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($hata) ?></div><?php endif; ?>
        <?php if (!file_exists($xml_path)): ?>
            <p class="text-muted">Proje kökünde <strong>yenibursluluk-1.xml</strong> dosyasını bulundurun.</p>
        <?php else: ?>
            <form method="post" onsubmit="return confirm('Import başlatılsın mı? Mevcut kayıtlar (kaynak_id aynı) güncellenir.');">
                <input type="hidden" name="onay" value="1">
                <button type="submit" class="btn btn-panel">Import başlat</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
