<?php
require_once __DIR__ . '/../auth.php';
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/personel_log.php';

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
            @personel_log_ekle($conn, 'panel/ogrenci-ekle.php', 'kaydet', $_POST);
            $geri = $sinif !== '' ? '?mesaj=eklendi&sinif=' . urlencode($sinif) : '?mesaj=eklendi';
            header('Location: ogrenci-listesi.php' . $geri);
            exit;
        }
    }
}
$aktif_personel = $_SESSION['personel_adi'] ?? '';
$geri_sinif = trim($_GET['sinif'] ?? '');
$liste_url = 'ogrenci-listesi.php' . ($geri_sinif !== '' ? '?sinif=' . urlencode($geri_sinif) : '');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Öğrenci ekle — Sınav not girişi</title>
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
        .form-control:focus, .form-select:focus { border-color: var(--g-primary); box-shadow: 0 0 0 3px rgba(13,148,136,0.15); }
    </style>
</head>
<body>
<div class="top-bar">
    <div class="brand">Sınav not girişi <span>— Öğrenci ekle</span></div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-white-50"><?= htmlspecialchars($aktif_personel) ?></span>
        <a href="<?= htmlspecialchars($liste_url) ?>" class="btn btn-sm btn-outline-light">← Listeye dön</a>
        <a href="index.php" class="btn btn-sm btn-outline-light">Ana sayfa</a>
    </div>
</div>
<div class="container py-4">
    <div class="panel-card" style="max-width: 640px;">
        <h1 class="h5 fw-bold mb-2">Öğrenci ekle</h1>
        <p class="small text-muted mb-3"><a href="<?= htmlspecialchars($liste_url) ?>" class="text-decoration-none">← Öğrenci listesine dön</a></p>
        <?php if ($hata): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

        <?php if (!$tablolar_var): ?>
            <p class="text-danger">Önce veritabanı tablolarını oluşturun (schema_ogrenciler.sql).</p>
        <?php else: ?>
        <form method="post" action="">
            <div class="mb-4">
                <label class="form-label fw-bold">Öğrenci</label>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Öğrenci adı *</label><input type="text" name="ogrenci_adi" class="form-control" required value="<?= htmlspecialchars($_POST['ogrenci_adi'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label small">Öğrenci soyadı *</label><input type="text" name="ogrenci_soyadi" class="form-control" required value="<?= htmlspecialchars($_POST['ogrenci_soyadi'] ?? '') ?>"></div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Doğum tarihi</label><input type="date" name="dogum_tarihi" class="form-control" value="<?= htmlspecialchars($_POST['dogum_tarihi'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label small">Sınıf *</label>
                        <select name="sinif" class="form-select" required>
                            <option value="">Seçiniz</option>
                            <?php for ($i = 1; $i <= 12; $i++): $val = $i . '. sınıf'; ?>
                                <option value="<?= htmlspecialchars($val) ?>" <?= ($_POST['sinif'] ?? '') === $val ? ' selected' : '' ?>><?= $i ?>. sınıf</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold">Veli</label>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Veli adı *</label><input type="text" name="veli_adi" class="form-control" required value="<?= htmlspecialchars($_POST['veli_adi'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label small">Veli soyadı *</label><input type="text" name="veli_soyadi" class="form-control" required value="<?= htmlspecialchars($_POST['veli_soyadi'] ?? '') ?>"></div>
                </div>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label small">Veli telefon *</label><input type="text" name="veli_telefon" class="form-control" required value="<?= htmlspecialchars($_POST['veli_telefon'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label small">2. veli telefon</label><input type="text" name="veli_telefon_2" class="form-control" value="<?= htmlspecialchars($_POST['veli_telefon_2'] ?? '') ?>"></div>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold">Katılacağı sınavlar *</label>
                <div class="form-check"><input type="checkbox" name="sinav_ingilizce" value="1" class="form-check-input" id="s_ing" <?= !empty($_POST['sinav_ingilizce']) ? 'checked' : '' ?>><label class="form-check-label" for="s_ing">İngilizce</label></div>
                <div class="form-check"><input type="checkbox" name="sinav_almanca" value="1" class="form-check-input" id="s_alm" <?= !empty($_POST['sinav_almanca']) ? 'checked' : '' ?>><label class="form-check-label" for="s_alm">Almanca</label></div>
            </div>
            <button type="submit" class="btn btn-panel">Öğrenci ekle</button>
        </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
