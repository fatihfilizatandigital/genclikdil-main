<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$tablolar_var = false;
@$t = mysqli_query($conn, "SHOW TABLES LIKE 'bursluluk_ogrenciler'");
if ($t && mysqli_num_rows($t) > 0) $tablolar_var = true;

$mesaj = '';
$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablolar_var) {
    $ad = trim($_POST['ogrenci_adi'] ?? '');
    $soyad = trim($_POST['ogrenci_soyadi'] ?? '');
    $veli_ad = trim($_POST['veli_adi'] ?? '');
    $veli_soyad = trim($_POST['veli_soyadi'] ?? '');
    $veli_tel = trim($_POST['veli_telefon'] ?? '');
    $veli_tel2 = trim($_POST['veli_telefon_2'] ?? '') ?: null;
    $sinif = trim($_POST['sinif'] ?? '');
    $dogum = trim($_POST['dogum_tarihi'] ?? '') ?: null;
    $sinav_ing = !empty($_POST['sinav_ingilizce']);
    $sinav_alm = !empty($_POST['sinav_almanca']);

    if ($ad === '' || $soyad === '' || $veli_ad === '' || $veli_soyad === '' || $veli_tel === '' || $sinif === '') {
        $hata = 'Öğrenci adı, soyadı, veli adı, soyadı, veli telefon ve sınıf zorunludur.';
    } elseif (!$sinav_ing && !$sinav_alm) {
        $hata = 'En az bir sınav türü (İngilizce veya Almanca) seçin.';
    } else {
        $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(kaynak_id),0)+1 AS next_id FROM bursluluk_ogrenciler"));
        $kaynak_id = (int)($r['next_id'] ?? 1);

        $stmt = mysqli_prepare($conn, "INSERT INTO bursluluk_ogrenciler (kaynak_id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, dogum_tarihi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "issssssss", $kaynak_id, $ad, $soyad, $veli_ad, $veli_soyad, $veli_tel, $veli_tel2, $sinif, $dogum);
        if (!mysqli_stmt_execute($stmt)) {
            $hata = 'Kayıt hatası: ' . mysqli_error($conn);
            mysqli_stmt_close($stmt);
        } else {
            $oid = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
            if ($oid > 0) {
                if ($sinav_ing) {
                    mysqli_query($conn, "INSERT IGNORE INTO bursluluk_ogrenci_sinav (ogrenci_id, sinav_turu) VALUES ($oid, 'İngilizce')");
                }
                if ($sinav_alm) {
                    mysqli_query($conn, "INSERT IGNORE INTO bursluluk_ogrenci_sinav (ogrenci_id, sinav_turu) VALUES ($oid, 'Almanca')");
                }
            }
            $geri = $sinif !== '' ? '?mesaj=eklendi&sinif=' . urlencode($sinif) : '?mesaj=eklendi';
            header('Location: ogrenci-listesi.php' . $geri);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Öğrenci Ekle - Bursluluk Sınav Sonuç Sistemi</title>
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
            <h1>Öğrenci Ekle</h1>
            <p class="page-subtitle"><a href="ogrenci-listesi.php">← Öğrenci listesine dön</a></p>
            <?php if ($hata): ?><div class="mesaj err"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

            <?php if (!$tablolar_var): ?>
                <p class="mesaj err">Önce veritabanı tablolarını oluşturun (schema_ogrenciler.sql).</p>
            <?php else: ?>
            <form method="post" action="">
                <div class="block">
                    <h3>Öğrenci</h3>
                    <div class="row2">
                        <div class="form-group">
                            <label>Öğrenci Adı *</label>
                            <input type="text" name="ogrenci_adi" required value="<?= htmlspecialchars($_POST['ogrenci_adi'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Öğrenci Soyadı *</label>
                            <input type="text" name="ogrenci_soyadi" required value="<?= htmlspecialchars($_POST['ogrenci_soyadi'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Doğum Tarihi</label>
                        <input type="date" name="dogum_tarihi" value="<?= htmlspecialchars($_POST['dogum_tarihi'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="max-width:160px;">
                        <label>Sınıf *</label>
                        <select name="sinif" required>
                            <option value="">Seçiniz</option>
                            <?php for ($i = 1; $i <= 12; $i++): $val = $i . '. sınıf'; ?>
                                <option value="<?= htmlspecialchars($val) ?>" <?= ($_POST['sinif'] ?? '') === $val ? ' selected' : '' ?>><?= $i ?>. sınıf</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="block">
                    <h3>Veli</h3>
                    <div class="row2">
                        <div class="form-group">
                            <label>Veli Adı *</label>
                            <input type="text" name="veli_adi" required value="<?= htmlspecialchars($_POST['veli_adi'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Veli Soyadı *</label>
                            <input type="text" name="veli_soyadi" required value="<?= htmlspecialchars($_POST['veli_soyadi'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row2">
                        <div class="form-group">
                            <label>Veli Telefon *</label>
                            <input type="text" name="veli_telefon" required value="<?= htmlspecialchars($_POST['veli_telefon'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>2. Veli Telefon</label>
                            <input type="text" name="veli_telefon_2" value="<?= htmlspecialchars($_POST['veli_telefon_2'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <div class="block">
                    <h3>Katılacağı sınavlar *</h3>
                    <div class="form-group">
                        <label><input type="checkbox" name="sinav_ingilizce" value="1" <?= !empty($_POST['sinav_ingilizce']) ? 'checked' : '' ?>> İngilizce</label>
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="sinav_almanca" value="1" <?= !empty($_POST['sinav_almanca']) ? 'checked' : '' ?>> Almanca</label>
                    </div>
                </div>
                <button type="submit" class="btn">Öğrenci Ekle</button>
            </form>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
