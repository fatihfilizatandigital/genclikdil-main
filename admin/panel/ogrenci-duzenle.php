<?php
require_once __DIR__ . '/../auth.php';
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/personel_log.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_sil'])) {
    $stmt = mysqli_prepare($conn, "DELETE FROM bursluluk_ogrenciler WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (mysqli_stmt_execute($stmt)) {
        @personel_log_ekle($conn, 'panel/ogrenci-duzenle.php', 'sil', ['id' => $id, 'ogrenci' => $o['ogrenci_adi'] . ' ' . $o['ogrenci_soyadi']]);
        header('Location: ogrenci-listesi.php?mesaj=silindi' . $geri_query);
        exit;
    }
    $hata = 'Silme hatası: ' . mysqli_error($conn);
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['btn_sil'])) {
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
            @personel_log_ekle($conn, 'panel/ogrenci-duzenle.php', 'guncelle', $_POST);
            header('Location: ogrenci-listesi.php?mesaj=duzenle' . $geri_query);
            exit;
        }
        $hata = 'Güncelleme hatası: ' . mysqli_error($conn);
        mysqli_stmt_close($stmt);
    }
}
$aktif_personel = $_SESSION['personel_adi'] ?? '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Öğrenci bilgilerini düzenle — Sınav not girişi</title>
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
    <div class="brand">Sınav not girişi <span>— Öğrenci düzenle</span></div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-white-50"><?= htmlspecialchars($aktif_personel) ?></span>
        <a href="<?= htmlspecialchars($liste_url) ?>" class="btn btn-sm btn-outline-light">← Listeye dön</a>
        <a href="index.php" class="btn btn-sm btn-outline-light">Ana sayfa</a>
    </div>
</div>
<div class="container py-4">
    <div class="panel-card" style="max-width: 640px;">
        <h1 class="h5 fw-bold mb-2">Öğrenci bilgilerini düzenle</h1>
        <p class="small text-muted mb-3"><a href="<?= htmlspecialchars($liste_url) ?>" class="text-decoration-none">← Öğrenci listesine dön</a></p>
        <?php if ($hata): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

        <form method="post" action="">
            <div class="mb-4">
                <label class="form-label fw-bold">Öğrenci</label>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Öğrenci adı *</label><input type="text" name="ogrenci_adi" class="form-control" required value="<?= htmlspecialchars($v['ogrenci_adi']) ?>"></div>
                    <div class="col-md-6"><label class="form-label small">Öğrenci soyadı *</label><input type="text" name="ogrenci_soyadi" class="form-control" required value="<?= htmlspecialchars($v['ogrenci_soyadi']) ?>"></div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Doğum tarihi</label><input type="date" name="dogum_tarihi" class="form-control" value="<?= htmlspecialchars($v['dogum_tarihi'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label small">Sınıf *</label><select name="sinif" class="form-select" required><?php for ($i = 1; $i <= 12; $i++): $val = $i . '. sınıf'; ?><option value="<?= htmlspecialchars($val) ?>" <?= ($v['sinif'] ?? '') === $val ? ' selected' : '' ?>><?= $i ?>. sınıf</option><?php endfor; ?></select></div>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold">Veli</label>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Veli adı *</label><input type="text" name="veli_adi" class="form-control" required value="<?= htmlspecialchars($v['veli_adi']) ?>"></div>
                    <div class="col-md-6"><label class="form-label small">Veli soyadı *</label><input type="text" name="veli_soyadi" class="form-control" required value="<?= htmlspecialchars($v['veli_soyadi']) ?>"></div>
                </div>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label small">Veli telefon *</label><input type="text" name="veli_telefon" class="form-control" required value="<?= htmlspecialchars($v['veli_telefon']) ?>"></div>
                    <div class="col-md-6"><label class="form-label small">2. veli telefon</label><input type="text" name="veli_telefon_2" class="form-control" value="<?= htmlspecialchars($v['veli_telefon_2'] ?? '') ?>"></div>
                </div>
            </div>
            <button type="submit" class="btn btn-panel">Kaydet</button>
        </form>

        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center">
            <p class="small text-muted mb-0">Bu öğrenciyi kalıcı olarak silmek için:</p>
            <form method="post" action="" onsubmit="return confirm('Bu öğrenci kaydı kalıcı olarak silinecek.\n\nÖğrenci: <?= htmlspecialchars(addslashes($o['ogrenci_adi'] . ' ' . $o['ogrenci_soyadi']), ENT_QUOTES, 'UTF-8') ?>\n\nDevam etmek istiyor musunuz?');">
                <button type="submit" name="btn_sil" value="1" class="btn btn-outline-danger btn-sm">Kaydı sil</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
