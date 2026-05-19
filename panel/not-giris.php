<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$mesaj = '';
$hata = '';

$ogrenci_id = (int)($_GET['ogrenci_id'] ?? 0);
$sinav_turu = trim($_GET['sinav_turu'] ?? '');
$geri_sinif = trim($_GET['sinif'] ?? '');
$geri_ara = trim($_GET['ara'] ?? '');
$geri_query = ($geri_sinif !== '' ? '&sinif=' . urlencode($geri_sinif) : '') . ($geri_ara !== '' ? '&ara=' . urlencode($geri_ara) : '');
$liste_url = 'ogrenci-listesi.php' . ($geri_query !== '' ? '?' . ltrim($geri_query, '&') : '');

$tablolar_var = false;
@$tablo_check = mysqli_query($conn, "SHOW TABLES LIKE 'bursluluk_ogrenciler'");
if ($tablo_check && mysqli_num_rows($tablo_check) > 0) $tablolar_var = true;

$ogrenci = null;
$sinav_uygun = false;
if ($tablolar_var && $ogrenci_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, dogum_tarihi FROM bursluluk_ogrenciler WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $ogrenci_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $ogrenci = mysqli_fetch_assoc($r);
    mysqli_stmt_close($stmt);
    if ($ogrenci && $sinav_turu !== '') {
        $q = mysqli_prepare($conn, "SELECT 1 FROM bursluluk_ogrenci_sinav WHERE ogrenci_id = ? AND sinav_turu = ?");
        mysqli_stmt_bind_param($q, "is", $ogrenci_id, $sinav_turu);
        mysqli_stmt_execute($q);
        mysqli_stmt_store_result($q);
        $sinav_uygun = mysqli_stmt_num_rows($q) > 0;
        mysqli_stmt_close($q);
    }
}

if (!$ogrenci || !$sinav_uygun) {
    header('Location: ' . $liste_url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oy_dogru   = (int)($_POST['okuma_yazma_dogru'] ?? 0);
    $oy_yanlis  = (int)($_POST['okuma_yazma_yanlis'] ?? 0);
    $oy_bos     = (int)($_POST['okuma_yazma_bos'] ?? 0);
    $dk_dogru   = (int)($_POST['dinleme_konusma_dogru'] ?? 0);
    $dk_yanlis  = (int)($_POST['dinleme_konusma_yanlis'] ?? 0);
    $dk_bos     = (int)($_POST['dinleme_konusma_bos'] ?? 0);

    $o = $ogrenci;
    $toplam_dogru   = $oy_dogru + $dk_dogru;
    $toplam_yanlis  = $oy_yanlis + $dk_yanlis;
    $toplam_bos     = $oy_bos + $dk_bos;
    $toplam_soru    = $toplam_dogru + $toplam_yanlis + $toplam_bos;
    $basari_yuzdesi = $toplam_soru > 0 ? round(($toplam_dogru / $toplam_soru) * 100, 2) : 0;
    $veli_tel2 = $o['veli_telefon_2'] ?? '';
    $dogum = $o['dogum_tarihi'] ?? null;
    if ($dogum !== null) $dogum = (string)$dogum;

    $has_oid = false;
    $has_dogum = false;
    $cols = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari");
    if ($cols) {
        while ($c = mysqli_fetch_assoc($cols)) {
            if ($c['Field'] === 'ogrenci_id') $has_oid = true;
            if ($c['Field'] === 'dogum_tarihi') $has_dogum = true;
        }
    }

    if ($has_oid && $has_dogum) {
        $stmt = mysqli_prepare($conn, "INSERT INTO sinav_sonuclari (
            ogrenci_id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, dogum_tarihi, sinav_turu,
            okuma_yazma_dogru, okuma_yazma_yanlis, okuma_yazma_bos,
            dinleme_konusma_dogru, dinleme_konusma_yanlis, dinleme_konusma_bos,
            toplam_dogru, toplam_yanlis, toplam_bos, toplam_soru, basari_yuzdesi
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "isssssssssiiiiiiiiiid",
            $ogrenci_id, $o['ogrenci_adi'], $o['ogrenci_soyadi'], $o['veli_adi'], $o['veli_soyadi'], $o['veli_telefon'], $veli_tel2, $o['sinif'], $dogum, $sinav_turu,
            $oy_dogru, $oy_yanlis, $oy_bos, $dk_dogru, $dk_yanlis, $dk_bos,
            $toplam_dogru, $toplam_yanlis, $toplam_bos, $toplam_soru, $basari_yuzdesi
        );
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO sinav_sonuclari (
            ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, sinav_turu,
            okuma_yazma_dogru, okuma_yazma_yanlis, okuma_yazma_bos,
            dinleme_konusma_dogru, dinleme_konusma_yanlis, dinleme_konusma_bos,
            toplam_dogru, toplam_yanlis, toplam_bos, toplam_soru, basari_yuzdesi
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "ssssssssiiiiiiiiiid",
            $o['ogrenci_adi'], $o['ogrenci_soyadi'], $o['veli_adi'], $o['veli_soyadi'], $o['veli_telefon'], $veli_tel2, $o['sinif'], $sinav_turu,
            $oy_dogru, $oy_yanlis, $oy_bos, $dk_dogru, $dk_yanlis, $dk_bos,
            $toplam_dogru, $toplam_yanlis, $toplam_bos, $toplam_soru, $basari_yuzdesi
        );
    }

    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        header('Location: ogrenci-listesi.php?mesaj=1' . $geri_query);
        exit;
    }
    $err = mysqli_stmt_error($stmt);
    $hata = 'Kayıt hatası: ' . ($err !== '' ? $err : mysqli_error($conn));
    mysqli_stmt_close($stmt);
}

$o = $ogrenci;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Not Girişi - <?= htmlspecialchars($o['ogrenci_adi'] . ' ' . $o['ogrenci_soyadi']) ?> (<?= htmlspecialchars($sinav_turu) ?>)</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <header class="admin-header">
        <div class="header-inner">
            <a href="index.php" class="logo">Bursluluk Sınav Sonuç Sistemi</a>
            <nav class="nav">
                <a href="index.php">Ana Sayfa</a>
                <a href="ogrenci-listesi.php">Öğrenci Listesi</a>
                <a href="sonuclar.php">Sonuçlar</a>
                <a href="xml-import.php">XML Import</a>
            </nav>
        </div>
    </header>
    <main class="page-content">
        <div class="page-card narrow">
            <h1>Not Girişi</h1>
            <p class="page-subtitle"><a href="<?= htmlspecialchars($liste_url) ?>">← Öğrenci listesine dön</a></p>
            <?php if ($hata): ?><div class="mesaj err"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

            <div class="block ogrenci-ozet">
                <h3>Öğrenci</h3>
                <p><strong><?= htmlspecialchars($o['ogrenci_adi'] . ' ' . $o['ogrenci_soyadi']) ?></strong> — <?= htmlspecialchars($o['sinif']) ?> — <strong><?= htmlspecialchars($sinav_turu) ?></strong></p>
                <p>Veli: <?= htmlspecialchars($o['veli_adi'] . ' ' . $o['veli_soyadi']) ?> · Tel: <?= htmlspecialchars($o['veli_telefon']) ?><?= !empty($o['veli_telefon_2']) ? ' / ' . htmlspecialchars($o['veli_telefon_2']) : '' ?></p>
                <p>Doğum: <?= !empty($o['dogum_tarihi']) ? htmlspecialchars($o['dogum_tarihi']) : '—' ?></p>
            </div>

            <form method="post" action="">
                <input type="hidden" name="ogrenci_id" value="<?= (int)$ogrenci_id ?>">
                <input type="hidden" name="sinav_turu" value="<?= htmlspecialchars($sinav_turu) ?>">

                <div class="block">
                    <h3>Okuma-Yazma</h3>
                    <div class="row3">
                        <div class="form-group">
                            <label>Doğru</label>
                            <input type="number" name="okuma_yazma_dogru" min="0" value="<?= (int)($_POST['okuma_yazma_dogru'] ?? 0) ?>">
                        </div>
                        <div class="form-group">
                            <label>Yanlış</label>
                            <input type="number" name="okuma_yazma_yanlis" min="0" value="<?= (int)($_POST['okuma_yazma_yanlis'] ?? 0) ?>">
                        </div>
                        <div class="form-group">
                            <label>Boş</label>
                            <input type="number" name="okuma_yazma_bos" min="0" value="<?= (int)($_POST['okuma_yazma_bos'] ?? 0) ?>">
                        </div>
                    </div>
                </div>

                <div class="block">
                    <h3>Dinleme-Konuşma</h3>
                    <div class="row3">
                        <div class="form-group">
                            <label>Doğru</label>
                            <input type="number" name="dinleme_konusma_dogru" min="0" value="<?= (int)($_POST['dinleme_konusma_dogru'] ?? 0) ?>">
                        </div>
                        <div class="form-group">
                            <label>Yanlış</label>
                            <input type="number" name="dinleme_konusma_yanlis" min="0" value="<?= (int)($_POST['dinleme_konusma_yanlis'] ?? 0) ?>">
                        </div>
                        <div class="form-group">
                            <label>Boş</label>
                            <input type="number" name="dinleme_konusma_bos" min="0" value="<?= (int)($_POST['dinleme_konusma_bos'] ?? 0) ?>">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn">Kaydet</button>
            </form>
        </div>
    </main>
</body>
</html>
