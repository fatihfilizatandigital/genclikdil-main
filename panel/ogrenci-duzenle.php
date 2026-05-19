<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$id = (int)($_GET['id'] ?? 0);
$geri_sinif = trim($_GET['sinif'] ?? '');
$geri_ara = trim($_GET['ara'] ?? '');
$geri_query = ($geri_sinif !== '' ? '&sinif=' . urlencode($geri_sinif) : '') . ($geri_ara !== '' ? '&ara=' . urlencode($geri_ara) : '');
$liste_url = 'ogrenci-listesi.php' . ($geri_query !== '' ? '?' . ltrim($geri_query, '&') : '');

if ($id <= 0) {
    header('Location: ' . $liste_url);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, dogum_tarihi FROM bursluluk_ogrenciler WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$o = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$o) {
    header('Location: ' . $liste_url);
    exit;
}

$mesaj = '';
$hata = '';
$v = $o;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = [
        'ogrenci_adi'   => trim($_POST['ogrenci_adi'] ?? ''),
        'ogrenci_soyadi'=> trim($_POST['ogrenci_soyadi'] ?? ''),
        'veli_adi'      => trim($_POST['veli_adi'] ?? ''),
        'veli_soyadi'   => trim($_POST['veli_soyadi'] ?? ''),
        'veli_telefon'  => trim($_POST['veli_telefon'] ?? ''),
        'veli_telefon_2'=> trim($_POST['veli_telefon_2'] ?? '') ?: null,
        'sinif'         => trim($_POST['sinif'] ?? ''),
        'dogum_tarihi'  => trim($_POST['dogum_tarihi'] ?? '') ?: null,
    ];
    if (!$v['ogrenci_adi'] || !$v['ogrenci_soyadi'] || !$v['veli_adi'] || !$v['veli_soyadi'] || !$v['veli_telefon'] || $v['sinif'] === '') {
        $hata = 'Öğrenci adı, soyadı, veli adı, soyadı, veli telefon ve sınıf zorunludur.';
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE bursluluk_ogrenciler SET ogrenci_adi=?, ogrenci_soyadi=?, veli_adi=?, veli_soyadi=?, veli_telefon=?, veli_telefon_2=?, sinif=?, dogum_tarihi=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "ssssssssi", $v['ogrenci_adi'], $v['ogrenci_soyadi'], $v['veli_adi'], $v['veli_soyadi'], $v['veli_telefon'], $v['veli_telefon_2'], $v['sinif'], $v['dogum_tarihi'], $id);
        if (mysqli_stmt_execute($stmt)) {
            header('Location: ogrenci-listesi.php?mesaj=duzenle' . $geri_query);
            exit;
        }
        $hata = 'Güncelleme hatası: ' . mysqli_error($conn);
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Öğrenci Bilgilerini Düzenle</title>
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
            <h1>Öğrenci Bilgilerini Düzenle</h1>
            <p class="page-subtitle"><a href="<?= htmlspecialchars($liste_url) ?>">← Öğrenci listesine dön</a></p>
            <?php if ($hata): ?><div class="mesaj err"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

            <form method="post" action="">
                <div class="block">
                    <h3>Öğrenci</h3>
                    <div class="row2">
                        <div class="form-group">
                            <label>Öğrenci Adı *</label>
                            <input type="text" name="ogrenci_adi" required value="<?= htmlspecialchars($v['ogrenci_adi']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Öğrenci Soyadı *</label>
                            <input type="text" name="ogrenci_soyadi" required value="<?= htmlspecialchars($v['ogrenci_soyadi']) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Doğum Tarihi</label>
                        <input type="date" name="dogum_tarihi" value="<?= htmlspecialchars($v['dogum_tarihi'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="max-width:160px;">
                        <label>Sınıf *</label>
                        <select name="sinif" required>
                            <?php for ($i = 1; $i <= 12; $i++): $val = $i . '. sınıf'; ?>
                                <option value="<?= htmlspecialchars($val) ?>" <?= ($v['sinif'] ?? '') === $val ? ' selected' : '' ?>><?= $i ?>. sınıf</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="block">
                    <h3>Veli</h3>
                    <div class="row2">
                        <div class="form-group">
                            <label>Veli Adı *</label>
                            <input type="text" name="veli_adi" required value="<?= htmlspecialchars($v['veli_adi']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Veli Soyadı *</label>
                            <input type="text" name="veli_soyadi" required value="<?= htmlspecialchars($v['veli_soyadi']) ?>">
                        </div>
                    </div>
                    <div class="row2">
                        <div class="form-group">
                            <label>Veli Telefon *</label>
                            <input type="text" name="veli_telefon" required value="<?= htmlspecialchars($v['veli_telefon']) ?>">
                        </div>
                        <div class="form-group">
                            <label>2. Veli Telefon</label>
                            <input type="text" name="veli_telefon_2" value="<?= htmlspecialchars($v['veli_telefon_2'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn">Kaydet</button>
            </form>
        </div>
    </main>
</body>
</html>
