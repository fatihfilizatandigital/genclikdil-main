<?php
require_once __DIR__ . '/../auth.php';
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/sinav_soru_limits.php';
require_once __DIR__ . '/../../config/personel_log.php';

// Gecici olarak devre disi
header('Location: ogrenci-listesi.php?bilgi=not-giris-devre-disi');
exit;

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

$soru_limits = sinav_soru_limits_get($ogrenci['sinif'], $sinav_turu);
if ($soru_limits === null) {
    $hata = 'Bu sınıf ve sınav türü için soru limiti tanımlı değil. Not girişi yapılamaz.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $soru_limits !== null) {
    $oy_dogru   = (int)($_POST['okuma_yazma_dogru'] ?? 0);
    $oy_yanlis  = (int)($_POST['okuma_yazma_yanlis'] ?? 0);
    $oy_bos     = (int)($_POST['okuma_yazma_bos'] ?? 0);
    $dk_dogru   = (int)($_POST['dinleme_konusma_dogru'] ?? 0);
    $dk_yanlis  = (int)($_POST['dinleme_konusma_yanlis'] ?? 0);
    $dk_bos     = (int)($_POST['dinleme_konusma_bos'] ?? 0);

    $valid = sinav_soru_limits_validate($ogrenci['sinif'], $sinav_turu, $oy_dogru, $oy_yanlis, $oy_bos, $dk_dogru, $dk_yanlis, $dk_bos);
    if (!$valid['ok']) {
        $hata = $valid['mesaj'];
    } else {
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
        @personel_log_ekle($conn, 'panel/not-giris.php', 'kaydet', $_POST);
        mysqli_stmt_close($stmt);
        header('Location: ogrenci-listesi.php?mesaj=1' . $geri_query);
        exit;
    }
    $err = mysqli_stmt_error($stmt);
    $hata = 'Kayıt hatası: ' . ($err !== '' ? $err : mysqli_error($conn));
    mysqli_stmt_close($stmt);
    }
}

$o = $ogrenci;
$aktif_personel = $_SESSION['personel_adi'] ?? '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Not girişi — <?= htmlspecialchars($o['ogrenci_adi'] . ' ' . $o['ogrenci_soyadi']) ?> (<?= htmlspecialchars($sinav_turu) ?>)</title>
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
        .form-control:focus { border-color: var(--g-primary); box-shadow: 0 0 0 3px rgba(13,148,136,0.15); }
    </style>
</head>
<body>
<div class="top-bar">
    <div class="brand">Sınav not girişi <span>— Not gir</span></div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-white-50"><?= htmlspecialchars($aktif_personel) ?></span>
        <a href="<?= htmlspecialchars($liste_url) ?>" class="btn btn-sm btn-outline-light">← Listeye dön</a>
        <a href="index.php" class="btn btn-sm btn-outline-light">Ana sayfa</a>
    </div>
</div>
<div class="container py-4">
    <div class="panel-card" style="max-width: 560px;">
        <h1 class="h5 fw-bold mb-2">Not girişi</h1>
        <p class="small text-muted mb-3"><a href="<?= htmlspecialchars($liste_url) ?>" class="text-decoration-none">← Öğrenci listesine dön</a></p>
        <?php if ($hata): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($hata) ?></div><?php endif; ?>
        <?php if ($soru_limits !== null): ?>
        <div class="alert alert-info py-2 small">
            <strong>Toplam soru sayıları (sabit):</strong> Okuma-Yazma = <strong><?= (int)$soru_limits['oy'] ?></strong> soru
            <?php if ($soru_limits['dk'] > 0): ?>, Dinleme-Konuşma = <strong><?= (int)$soru_limits['dk'] ?></strong> soru.<?php else: ?>; Dinleme-Konuşma yok (0 girin).<?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="border rounded p-3 mb-4 bg-light">
            <p class="mb-1"><strong><?= htmlspecialchars($o['ogrenci_adi'] . ' ' . $o['ogrenci_soyadi']) ?></strong> — <?= htmlspecialchars($o['sinif']) ?> — <strong><?= htmlspecialchars($sinav_turu) ?></strong></p>
            <p class="mb-0 small text-muted">Veli: <?= htmlspecialchars($o['veli_adi'] . ' ' . $o['veli_soyadi']) ?> · Tel: <?= htmlspecialchars($o['veli_telefon']) ?><?= !empty($o['veli_telefon_2']) ? ' / ' . htmlspecialchars($o['veli_telefon_2']) : '' ?> · Doğum: <?= !empty($o['dogum_tarihi']) ? htmlspecialchars($o['dogum_tarihi']) : '—' ?></p>
        </div>

        <?php if ($soru_limits !== null): ?>
        <form method="post" action="" id="not-giris-form" data-oy-toplam="<?= (int)$soru_limits['oy'] ?>" data-dk-toplam="<?= (int)$soru_limits['dk'] ?>">
            <input type="hidden" name="ogrenci_id" value="<?= (int)$ogrenci_id ?>">
            <input type="hidden" name="sinav_turu" value="<?= htmlspecialchars($sinav_turu) ?>">

            <div class="mb-4">
                <label class="form-label fw-bold">Okuma-Yazma <span class="text-muted fw-normal">(toplam <?= (int)$soru_limits['oy'] ?> olmalı)</span></label>
                <div class="row g-2">
                    <div class="col-4"><label class="form-label small">Doğru</label><input type="number" name="okuma_yazma_dogru" class="form-control" min="0" value="<?= (int)($_POST['okuma_yazma_dogru'] ?? 0) ?>"></div>
                    <div class="col-4"><label class="form-label small">Yanlış</label><input type="number" name="okuma_yazma_yanlis" class="form-control" min="0" value="<?= (int)($_POST['okuma_yazma_yanlis'] ?? 0) ?>"></div>
                    <div class="col-4"><label class="form-label small">Boş</label><input type="number" name="okuma_yazma_bos" class="form-control" min="0" value="<?= (int)($_POST['okuma_yazma_bos'] ?? 0) ?>"></div>
                </div>
            </div>

            <div class="mb-4" id="dk-blok">
                <label class="form-label fw-bold">Dinleme-Konuşma <?php if ($soru_limits['dk'] > 0): ?><span class="text-muted fw-normal">(toplam <?= (int)$soru_limits['dk'] ?> olmalı)</span><?php else: ?><span class="text-muted fw-normal">(yok — 0 girin)</span><?php endif; ?></label>
                <div class="row g-2">
                    <div class="col-4"><label class="form-label small">Doğru</label><input type="number" name="dinleme_konusma_dogru" class="form-control" min="0" value="<?= (int)($_POST['dinleme_konusma_dogru'] ?? 0) ?>"></div>
                    <div class="col-4"><label class="form-label small">Yanlış</label><input type="number" name="dinleme_konusma_yanlis" class="form-control" min="0" value="<?= (int)($_POST['dinleme_konusma_yanlis'] ?? 0) ?>"></div>
                    <div class="col-4"><label class="form-label small">Boş</label><input type="number" name="dinleme_konusma_bos" class="form-control" min="0" value="<?= (int)($_POST['dinleme_konusma_bos'] ?? 0) ?>"></div>
                </div>
            </div>

            <button type="submit" class="btn btn-panel">Kaydet</button>
        </form>
        <script>
        (function(){
            var form = document.getElementById('not-giris-form');
            if (!form) return;
            var oyToplam = parseInt(form.getAttribute('data-oy-toplam'), 10) || 0;
            var dkToplam = parseInt(form.getAttribute('data-dk-toplam'), 10) || 0;
            form.querySelectorAll('input[type="number"]').forEach(function(inp){
                inp.addEventListener('focus', function(){ if (this.value === '0') this.value = ''; });
                inp.addEventListener('blur', function(){ if (this.value === '') this.value = '0'; });
            });
            form.addEventListener('submit', function(e){
                var oyD = parseInt(form.querySelector('[name="okuma_yazma_dogru"]').value, 10) || 0;
                var oyY = parseInt(form.querySelector('[name="okuma_yazma_yanlis"]').value, 10) || 0;
                var oyB = parseInt(form.querySelector('[name="okuma_yazma_bos"]').value, 10) || 0;
                var dkD = parseInt(form.querySelector('[name="dinleme_konusma_dogru"]').value, 10) || 0;
                var dkY = parseInt(form.querySelector('[name="dinleme_konusma_yanlis"]').value, 10) || 0;
                var dkB = parseInt(form.querySelector('[name="dinleme_konusma_bos"]').value, 10) || 0;
                var oyGiren = oyD + oyY + oyB;
                var dkGiren = dkD + dkY + dkB;
                if (oyGiren !== oyToplam) {
                    e.preventDefault();
                    alert('Okuma-Yazma toplamı ' + oyToplam + ' olmalı. Sizin girişiniz: ' + oyGiren + '.');
                    return;
                }
                if (dkToplam === 0 && dkGiren !== 0) {
                    e.preventDefault();
                    alert('Bu sınavda Dinleme-Konuşma yok; tüm değerler 0 olmalı.');
                    return;
                }
                if (dkToplam > 0 && dkGiren !== dkToplam) {
                    e.preventDefault();
                    alert('Dinleme-Konuşma toplamı ' + dkToplam + ' olmalı. Sizin girişiniz: ' + dkGiren + '.');
                    return;
                }
            });
        })();
        </script>
        <?php else: ?>
        <p class="text-muted">Not girişi yapılamıyor.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
