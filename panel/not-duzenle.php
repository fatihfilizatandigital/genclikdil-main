<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: sonuclar.php');
    exit;
}

$has_dogum_col = false;
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari LIKE 'dogum_tarihi'");
if ($chk && mysqli_num_rows($chk) > 0) $has_dogum_col = true;
$cols = "id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, sinav_turu, okuma_yazma_dogru, okuma_yazma_yanlis, okuma_yazma_bos, dinleme_konusma_dogru, dinleme_konusma_yanlis, dinleme_konusma_bos";
if ($has_dogum_col) $cols = "id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, dogum_tarihi, sinav_turu, okuma_yazma_dogru, okuma_yazma_yanlis, okuma_yazma_bos, dinleme_konusma_dogru, dinleme_konusma_yanlis, dinleme_konusma_bos";
$stmt = mysqli_prepare($conn, "SELECT $cols FROM sinav_sonuclari WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$kayit = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$kayit) {
    header('Location: sonuclar.php');
    exit;
}

$mesaj = '';
$hata = '';
$veri = $kayit;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $veri = [
        'ogrenci_adi'      => trim($_POST['ogrenci_adi'] ?? ''),
        'ogrenci_soyadi'   => trim($_POST['ogrenci_soyadi'] ?? ''),
        'veli_adi'         => trim($_POST['veli_adi'] ?? ''),
        'veli_soyadi'      => trim($_POST['veli_soyadi'] ?? ''),
        'veli_telefon'     => trim($_POST['veli_telefon'] ?? ''),
        'veli_telefon_2'   => trim($_POST['veli_telefon_2'] ?? '') ?: null,
        'sinif'            => trim($_POST['sinif'] ?? ''),
        'dogum_tarihi'     => trim($_POST['dogum_tarihi'] ?? '') ?: null,
        'sinav_turu'       => trim($_POST['sinav_turu'] ?? ''),
        'okuma_yazma_dogru' => (int)($_POST['okuma_yazma_dogru'] ?? 0),
        'okuma_yazma_yanlis' => (int)($_POST['okuma_yazma_yanlis'] ?? 0),
        'okuma_yazma_bos'  => (int)($_POST['okuma_yazma_bos'] ?? 0),
        'dinleme_konusma_dogru' => (int)($_POST['dinleme_konusma_dogru'] ?? 0),
        'dinleme_konusma_yanlis' => (int)($_POST['dinleme_konusma_yanlis'] ?? 0),
        'dinleme_konusma_bos' => (int)($_POST['dinleme_konusma_bos'] ?? 0),
    ];
    $v = &$veri;

    if (!$v['ogrenci_adi'] || !$v['ogrenci_soyadi'] || !$v['veli_adi'] || !$v['veli_soyadi'] || !$v['veli_telefon'] || $v['sinif'] === '' || $v['sinav_turu'] === '') {
        $hata = 'Öğrenci adı, soyadı, veli adı, soyadı, veli telefon, sınıf ve sınav türü zorunludur.';
    } else {
        $toplam_dogru   = $v['okuma_yazma_dogru'] + $v['dinleme_konusma_dogru'];
        $toplam_yanlis  = $v['okuma_yazma_yanlis'] + $v['dinleme_konusma_yanlis'];
        $toplam_bos     = $v['okuma_yazma_bos'] + $v['dinleme_konusma_bos'];
        $toplam_soru    = $toplam_dogru + $toplam_yanlis + $toplam_bos;
        $basari_yuzdesi = $toplam_soru > 0 ? round(($toplam_dogru / $toplam_soru) * 100, 2) : 0;

        $update_sql = "UPDATE sinav_sonuclari SET
            ogrenci_adi = ?, ogrenci_soyadi = ?, veli_adi = ?, veli_soyadi = ?, veli_telefon = ?, veli_telefon_2 = ?, sinif = ?, sinav_turu = ?,
            okuma_yazma_dogru = ?, okuma_yazma_yanlis = ?, okuma_yazma_bos = ?,
            dinleme_konusma_dogru = ?, dinleme_konusma_yanlis = ?, dinleme_konusma_bos = ?,
            toplam_dogru = ?, toplam_yanlis = ?, toplam_bos = ?, toplam_soru = ?, basari_yuzdesi = ?";
        $update_params = [$v['ogrenci_adi'], $v['ogrenci_soyadi'], $v['veli_adi'], $v['veli_soyadi'], $v['veli_telefon'], $v['veli_telefon_2'], $v['sinif'], $v['sinav_turu'],
            $v['okuma_yazma_dogru'], $v['okuma_yazma_yanlis'], $v['okuma_yazma_bos'],
            $v['dinleme_konusma_dogru'], $v['dinleme_konusma_yanlis'], $v['dinleme_konusma_bos'],
            $toplam_dogru, $toplam_yanlis, $toplam_bos, $toplam_soru, $basari_yuzdesi];
        $update_types = "ssssssssiiiiiiiiid";
        if ($has_dogum_col) {
            $update_sql .= ", dogum_tarihi = ?";
            $update_params[] = $v['dogum_tarihi'] ?? null;
            $update_types .= "s";
        }
        $update_sql .= " WHERE id = ?";
        $update_params[] = $id;
        $update_types .= "i";
        $stmt = mysqli_prepare($conn, $update_sql);
        $refs = [];
        foreach ($update_params as $key => $val) $refs[$key] = &$update_params[$key];
        call_user_func_array([$stmt, 'bind_param'], array_merge([$update_types], $refs));

        if (mysqli_stmt_execute($stmt)) {
            $geri = 'sonuclar.php';
            $q = [];
            if (!empty($_GET['sinav_turu'])) $q[] = 'sinav_turu=' . urlencode($_GET['sinav_turu']);
            if (!empty($_GET['sinif'])) $q[] = 'sinif=' . urlencode($_GET['sinif']);
            if (!empty($q)) $geri .= '?' . implode('&', $q);
            header('Location: ' . $geri);
            exit;
        }
        $hata = 'Güncelleme hatası: ' . mysqli_error($conn);
        mysqli_stmt_close($stmt);
    }
}
$v = $veri;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Not Düzenle - Bursluluk Sınav Sonuç Sistemi</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <header class="admin-header">
        <div class="header-inner">
            <a href="index.php" class="logo">Bursluluk Sınav Sonuç Sistemi</a>
            <nav class="nav">
                <a href="index.php">Ana Sayfa</a>
                <?php
                $liste_q = [];
                if (!empty($_GET['sinif'])) $liste_q[] = 'sinif=' . urlencode($_GET['sinif']);
                if (!empty($_GET['ara'])) $liste_q[] = 'ara=' . urlencode($_GET['ara']);
                $liste_suffix = empty($liste_q) ? '' : '?' . implode('&', $liste_q);
                $geri_q = [];
                if (!empty($_GET['sinav_turu'])) $geri_q[] = 'sinav_turu=' . urlencode($_GET['sinav_turu']);
                if (!empty($_GET['sinif'])) $geri_q[] = 'sinif=' . urlencode($_GET['sinif']);
                $geri_suffix = empty($geri_q) ? '' : '?' . implode('&', $geri_q);
                ?>
                <a href="ogrenci-listesi.php<?= $liste_suffix ?>">Öğrenci Listesi</a>
                <a href="sonuclar.php<?= $geri_suffix ?>">Sonuçlar</a>
                <a href="xml-import.php">XML Import</a>
            </nav>
        </div>
    </header>
    <main class="page-content">
        <div class="page-card narrow">
            <h1>Not Düzenle</h1>
            <p class="page-subtitle">Kayıtlı sınav sonucunu güncelleyin.</p>
        <?php if ($mesaj): ?><div class="mesaj ok"><?= htmlspecialchars($mesaj) ?></div><?php endif; ?>
        <?php if ($hata): ?><div class="mesaj err"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

        <form method="post" action="">
            <div class="block">
                <h3>Öğrenci Bilgileri</h3>
                <div class="row2" style="margin-bottom: 16px;">
                    <div class="form-group" style="max-width: 180px;">
                        <label>Sınav türü *</label>
                        <select name="sinav_turu" required>
                            <option value="">Seçiniz</option>
                            <option value="İngilizce" <?= ($v['sinav_turu'] ?? '') === 'İngilizce' ? ' selected' : '' ?>>İngilizce</option>
                            <option value="Almanca" <?= ($v['sinav_turu'] ?? '') === 'Almanca' ? ' selected' : '' ?>>Almanca</option>
                        </select>
                    </div>
                    <div class="form-group" style="max-width: 160px;">
                        <label>Sınıf *</label>
                        <select name="sinif" required>
                            <option value="">Seçiniz</option>
                            <?php for ($i = 1; $i <= 12; $i++): $val = $i . '. sınıf'; ?>
                                <option value="<?= htmlspecialchars($val) ?>" <?= ($v['sinif'] ?? '') === $val ? ' selected' : '' ?>><?= $i ?>. sınıf</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php if ($has_dogum_col): ?>
                    <div class="form-group" style="max-width: 160px;">
                        <label>Doğum Tarihi</label>
                        <input type="date" name="dogum_tarihi" value="<?= htmlspecialchars($v['dogum_tarihi'] ?? '') ?>">
                    </div>
                    <?php endif; ?>
                </div>
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
                        <input type="text" name="veli_telefon" required placeholder="05xxxxxxxxx" value="<?= htmlspecialchars($v['veli_telefon']) ?>">
                    </div>
                    <div class="form-group">
                        <label>2. Veli Telefon (opsiyonel)</label>
                        <input type="text" name="veli_telefon_2" placeholder="05xxxxxxxxx" value="<?= htmlspecialchars($v['veli_telefon_2'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="block">
                <h3>Değerlendirme: Okuma-Yazma</h3>
                <div class="row3">
                    <div class="form-group">
                        <label>Doğru</label>
                        <input type="number" name="okuma_yazma_dogru" min="0" value="<?= (int)($v['okuma_yazma_dogru'] ?? 0) ?>">
                    </div>
                    <div class="form-group">
                        <label>Yanlış</label>
                        <input type="number" name="okuma_yazma_yanlis" min="0" value="<?= (int)($v['okuma_yazma_yanlis'] ?? 0) ?>">
                    </div>
                    <div class="form-group">
                        <label>Boş</label>
                        <input type="number" name="okuma_yazma_bos" min="0" value="<?= (int)($v['okuma_yazma_bos'] ?? 0) ?>">
                    </div>
                </div>
            </div>

            <div class="block">
                <h3>Değerlendirme: Dinleme-Konuşma</h3>
                <div class="row3">
                    <div class="form-group">
                        <label>Doğru</label>
                        <input type="number" name="dinleme_konusma_dogru" min="0" value="<?= (int)($v['dinleme_konusma_dogru'] ?? 0) ?>">
                    </div>
                    <div class="form-group">
                        <label>Yanlış</label>
                        <input type="number" name="dinleme_konusma_yanlis" min="0" value="<?= (int)($v['dinleme_konusma_yanlis'] ?? 0) ?>">
                    </div>
                    <div class="form-group">
                        <label>Boş</label>
                        <input type="number" name="dinleme_konusma_bos" min="0" value="<?= (int)($v['dinleme_konusma_bos'] ?? 0) ?>">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn">Güncelle</button>
        </form>
        </div>
    </main>
</body>
</html>
