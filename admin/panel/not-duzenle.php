<?php
require_once __DIR__ . '/../auth.php';
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/sinav_soru_limits.php';
require_once __DIR__ . '/../../config/personel_log.php';

// Gecici olarak devre disi
header('Location: sonuclar.php?bilgi=not-duzenle-devre-disi');
exit;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: sonuclar.php');
    exit;
}

$has_dogum_col = false;
$has_toplam_cols = false;
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari");
if ($chk) {
    while ($r = mysqli_fetch_assoc($chk)) {
        if ($r['Field'] === 'dogum_tarihi') $has_dogum_col = true;
        if ($r['Field'] === 'toplam_dogru') $has_toplam_cols = true;
    }
}
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
        $valid = sinav_soru_limits_validate(
            $v['sinif'], $v['sinav_turu'],
            $v['okuma_yazma_dogru'], $v['okuma_yazma_yanlis'], $v['okuma_yazma_bos'],
            $v['dinleme_konusma_dogru'], $v['dinleme_konusma_yanlis'], $v['dinleme_konusma_bos']
        );
        if (!$valid['ok']) {
            $hata = $valid['mesaj'];
        } else {
        $toplam_dogru   = $v['okuma_yazma_dogru'] + $v['dinleme_konusma_dogru'];
        $toplam_yanlis  = $v['okuma_yazma_yanlis'] + $v['dinleme_konusma_yanlis'];
        $toplam_bos     = $v['okuma_yazma_bos'] + $v['dinleme_konusma_bos'];
        $toplam_soru    = $toplam_dogru + $toplam_yanlis + $toplam_bos;
        $basari_yuzdesi = $toplam_soru > 0 ? round(($toplam_dogru / $toplam_soru) * 100, 2) : 0;

        $update_sql = "UPDATE sinav_sonuclari SET
            ogrenci_adi = ?, ogrenci_soyadi = ?, veli_adi = ?, veli_soyadi = ?, veli_telefon = ?, veli_telefon_2 = ?, sinif = ?, sinav_turu = ?,
            okuma_yazma_dogru = ?, okuma_yazma_yanlis = ?, okuma_yazma_bos = ?,
            dinleme_konusma_dogru = ?, dinleme_konusma_yanlis = ?, dinleme_konusma_bos = ?";
        $update_params = [$v['ogrenci_adi'], $v['ogrenci_soyadi'], $v['veli_adi'], $v['veli_soyadi'], $v['veli_telefon'], $v['veli_telefon_2'], $v['sinif'], $v['sinav_turu'],
            $v['okuma_yazma_dogru'], $v['okuma_yazma_yanlis'], $v['okuma_yazma_bos'],
            $v['dinleme_konusma_dogru'], $v['dinleme_konusma_yanlis'], $v['dinleme_konusma_bos']];
        $update_types = "ssssssssiiiiii";
        if ($has_toplam_cols) {
            $update_sql .= ", toplam_dogru = ?, toplam_yanlis = ?, toplam_bos = ?, toplam_soru = ?, basari_yuzdesi = ?";
            $update_params = array_merge($update_params, [$toplam_dogru, $toplam_yanlis, $toplam_bos, $toplam_soru, $basari_yuzdesi]);
            $update_types .= "iiiid";
        }
        if ($has_dogum_col) {
            $update_sql .= ", dogum_tarihi = ?";
            $update_params[] = $v['dogum_tarihi'] ?? null;
            $update_types .= "s";
        }
        $update_sql .= " WHERE id = ?";
        $update_params[] = $id;
        $update_types .= "i";
        $stmt = mysqli_prepare($conn, $update_sql);
        if ($stmt) {
            $bind_args = [$update_types];
            foreach ($update_params as $key => $val) {
                $bind_args[] = &$update_params[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_args);
        }

        if ($stmt && mysqli_stmt_execute($stmt)) {
            @personel_log_ekle($conn, 'panel/not-duzenle.php', 'guncelle', $_POST);
            $geri = 'sonuclar.php';
            $q = [];
            if (!empty($_GET['sinav_turu'])) $q[] = 'sinav_turu=' . urlencode($_GET['sinav_turu']);
            if (!empty($_GET['sinif'])) $q[] = 'sinif=' . urlencode($_GET['sinif']);
            if (!empty($q)) $geri .= '?' . implode('&', $q);
            header('Location: ' . $geri);
            exit;
        }
        $hata = 'Güncelleme hatası: ' . mysqli_error($conn);
        if ($stmt) mysqli_stmt_close($stmt);
        }
    }
}
$v = $veri;
$aktif_personel = $_SESSION['personel_adi'] ?? '';
$soru_limits_mevcut = sinav_soru_limits_get($v['sinif'] ?? '', $v['sinav_turu'] ?? '');
$soru_limits_json = json_encode([
    'İngilizce' => [
        '2. sınıf' => ['oy' => 25, 'dk' => 0], '3. sınıf' => ['oy' => 30, 'dk' => 10], '4. sınıf' => ['oy' => 35, 'dk' => 5],
        '5. sınıf' => ['oy' => 40, 'dk' => 5], '6. sınıf' => ['oy' => 37, 'dk' => 3], '7. sınıf' => ['oy' => 35, 'dk' => 5],
        '8. sınıf' => ['oy' => 40, 'dk' => 5], '9. sınıf' => ['oy' => 49, 'dk' => 10], '10. sınıf' => ['oy' => 49, 'dk' => 10],
        '11. sınıf' => ['oy' => 49, 'dk' => 10], '12. sınıf' => ['oy' => 49, 'dk' => 10],
    ],
    'Almanca' => [
        '2. sınıf' => ['oy' => 30, 'dk' => 0], '3. sınıf' => ['oy' => 30, 'dk' => 0], '4. sınıf' => ['oy' => 30, 'dk' => 0],
        '5. sınıf' => ['oy' => 30, 'dk' => 0], '6. sınıf' => ['oy' => 30, 'dk' => 0], '7. sınıf' => ['oy' => 30, 'dk' => 0],
        '8. sınıf' => ['oy' => 30, 'dk' => 0], '9. sınıf' => ['oy' => 50, 'dk' => 0], '10. sınıf' => ['oy' => 50, 'dk' => 0],
        '11. sınıf' => ['oy' => 50, 'dk' => 0], '12. sınıf' => ['oy' => 50, 'dk' => 0],
    ],
], JSON_UNESCAPED_UNICODE);
$geri_q = [];
if (!empty($_GET['sinav_turu'])) $geri_q[] = 'sinav_turu=' . urlencode($_GET['sinav_turu']);
if (!empty($_GET['sinif'])) $geri_q[] = 'sinif=' . urlencode($_GET['sinif']);
$geri_suffix = empty($geri_q) ? '' : '?' . implode('&', $geri_q);
$sonuclar_url = 'sonuclar.php' . $geri_suffix;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Not düzenle — Sınav not girişi</title>
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
    <div class="brand">Sınav not girişi <span>— Not düzenle</span></div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-white-50"><?= htmlspecialchars($aktif_personel) ?></span>
        <a href="<?= htmlspecialchars($sonuclar_url) ?>" class="btn btn-sm btn-outline-light">← Sonuçlara dön</a>
        <a href="index.php" class="btn btn-sm btn-outline-light">Ana sayfa</a>
    </div>
</div>
<div class="container py-4">
    <div class="panel-card" style="max-width: 640px;">
        <h1 class="h5 fw-bold mb-2">Not düzenle</h1>
        <p class="small text-muted mb-3">Kayıtlı sınav sonucunu güncelleyin.</p>
        <?php if ($mesaj): ?><div class="alert alert-success py-2"><?= htmlspecialchars($mesaj) ?></div><?php endif; ?>
        <?php if ($hata): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($hata) ?></div><?php endif; ?>
        <div class="alert alert-info py-2 small" id="limit-bilgi">
            <?php if ($soru_limits_mevcut): ?>
            <strong>Toplam soru sayıları (sabit):</strong> Okuma-Yazma = <strong id="oy-limit"><?= (int)$soru_limits_mevcut['oy'] ?></strong> soru
            <?php if ($soru_limits_mevcut['dk'] > 0): ?>, Dinleme-Konuşma = <strong id="dk-limit"><?= (int)$soru_limits_mevcut['dk'] ?></strong> soru.<?php else: ?>; Dinleme-Konuşma yok (0 girin).<?php endif; ?>
            <?php else: ?>Sınıf ve sınav türü seçin — toplam soru sayıları sabittir.<?php endif; ?>
        </div>

        <form method="post" action="" id="not-duzenle-form">
            <div class="mb-4">
                <label class="form-label fw-bold">Öğrenci bilgileri</label>
                <div class="row g-2 mb-2">
                    <div class="col-md-4"><label class="form-label small">Sınav türü *</label><select name="sinav_turu" class="form-select" required><option value="">Seçiniz</option><option value="İngilizce" <?= ($v['sinav_turu'] ?? '') === 'İngilizce' ? ' selected' : '' ?>>İngilizce</option><option value="Almanca" <?= ($v['sinav_turu'] ?? '') === 'Almanca' ? ' selected' : '' ?>>Almanca</option></select></div>
                    <div class="col-md-4"><label class="form-label small">Sınıf *</label><select name="sinif" class="form-select" required><option value="">Seçiniz</option><?php for ($i = 1; $i <= 12; $i++): $val = $i . '. sınıf'; ?><option value="<?= htmlspecialchars($val) ?>" <?= ($v['sinif'] ?? '') === $val ? ' selected' : '' ?>><?= $i ?>. sınıf</option><?php endfor; ?></select></div>
                    <?php if ($has_dogum_col): ?><div class="col-md-4"><label class="form-label small">Doğum tarihi</label><input type="date" name="dogum_tarihi" class="form-control" value="<?= htmlspecialchars($v['dogum_tarihi'] ?? '') ?>"></div><?php endif; ?>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Öğrenci adı *</label><input type="text" name="ogrenci_adi" class="form-control" required value="<?= htmlspecialchars($v['ogrenci_adi']) ?>"></div>
                    <div class="col-md-6"><label class="form-label small">Öğrenci soyadı *</label><input type="text" name="ogrenci_soyadi" class="form-control" required value="<?= htmlspecialchars($v['ogrenci_soyadi']) ?>"></div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Veli adı *</label><input type="text" name="veli_adi" class="form-control" required value="<?= htmlspecialchars($v['veli_adi']) ?>"></div>
                    <div class="col-md-6"><label class="form-label small">Veli soyadı *</label><input type="text" name="veli_soyadi" class="form-control" required value="<?= htmlspecialchars($v['veli_soyadi']) ?>"></div>
                </div>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label small">Veli telefon *</label><input type="text" name="veli_telefon" class="form-control" required placeholder="05xxxxxxxxx" value="<?= htmlspecialchars($v['veli_telefon']) ?>"></div>
                    <div class="col-md-6"><label class="form-label small">2. veli telefon</label><input type="text" name="veli_telefon_2" class="form-control" placeholder="05xxxxxxxxx" value="<?= htmlspecialchars($v['veli_telefon_2'] ?? '') ?>"></div>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Okuma-Yazma <span class="text-muted fw-normal" id="oy-hint">(toplam <span id="oy-toplam-text">—</span> olmalı)</span></label>
                <div class="row g-2">
                    <div class="col-4"><label class="form-label small">Doğru</label><input type="number" name="okuma_yazma_dogru" class="form-control" min="0" value="<?= (int)($v['okuma_yazma_dogru'] ?? 0) ?>"></div>
                    <div class="col-4"><label class="form-label small">Yanlış</label><input type="number" name="okuma_yazma_yanlis" class="form-control" min="0" value="<?= (int)($v['okuma_yazma_yanlis'] ?? 0) ?>"></div>
                    <div class="col-4"><label class="form-label small">Boş</label><input type="number" name="okuma_yazma_bos" class="form-control" min="0" value="<?= (int)($v['okuma_yazma_bos'] ?? 0) ?>"></div>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Dinleme-Konuşma <span class="text-muted fw-normal" id="dk-hint">(toplam <span id="dk-toplam-text">—</span> olmalı / yok)</span></label>
                <div class="row g-2">
                    <div class="col-4"><label class="form-label small">Doğru</label><input type="number" name="dinleme_konusma_dogru" class="form-control" min="0" value="<?= (int)($v['dinleme_konusma_dogru'] ?? 0) ?>"></div>
                    <div class="col-4"><label class="form-label small">Yanlış</label><input type="number" name="dinleme_konusma_yanlis" class="form-control" min="0" value="<?= (int)($v['dinleme_konusma_yanlis'] ?? 0) ?>"></div>
                    <div class="col-4"><label class="form-label small">Boş</label><input type="number" name="dinleme_konusma_bos" class="form-control" min="0" value="<?= (int)($v['dinleme_konusma_bos'] ?? 0) ?>"></div>
                </div>
            </div>

            <button type="submit" class="btn btn-panel">Güncelle</button>
        </form>
        <script>
        (function(){
            var limits = <?= $soru_limits_json ?>;
            var form = document.getElementById('not-duzenle-form');
            var sinifSel = form.querySelector('[name="sinif"]');
            var sinavSel = form.querySelector('[name="sinav_turu"]');
            var oyToplamEl = document.getElementById('oy-toplam-text');
            var dkToplamEl = document.getElementById('dk-toplam-text');
            var oyLimitEl = document.getElementById('oy-limit');
            var dkLimitEl = document.getElementById('dk-limit');
            var limitBilgi = document.getElementById('limit-bilgi');

            form.querySelectorAll('input[type="number"]').forEach(function(inp){
                inp.addEventListener('focus', function(){ if (this.value === '0') this.value = ''; });
                inp.addEventListener('blur', function(){ if (this.value === '') this.value = '0'; });
            });

            function getLimits() {
                var sinav = (sinavSel && sinavSel.value) || '';
                var sinif = (sinifSel && sinifSel.value) || '';
                if (sinav === 'Almanca' && limits.Almanca && sinif) return limits.Almanca[sinif] || null;
                if (sinav === 'İngilizce' && limits['İngilizce'] && sinif) return limits['İngilizce'][sinif] || null;
                return null;
            }
            function updateHints() {
                var L = getLimits();
                if (!L) {
                    if (oyToplamEl) oyToplamEl.textContent = '—';
                    if (dkToplamEl) dkToplamEl.textContent = '—';
                    if (limitBilgi) limitBilgi.innerHTML = 'Sınıf ve sınav türü seçin — toplam soru sayıları sabittir.';
                    return;
                }
                if (oyToplamEl) oyToplamEl.textContent = L.oy;
                if (dkToplamEl) dkToplamEl.textContent = L.dk === 0 ? 'yok (0)' : L.dk;
                if (limitBilgi) {
                    limitBilgi.innerHTML = '<strong>Toplam soru sayıları (sabit):</strong> Okuma-Yazma = <strong id="oy-limit">' + L.oy + '</strong> soru' +
                        (L.dk > 0 ? ', Dinleme-Konuşma = <strong id="dk-limit">' + L.dk + '</strong> soru.' : '; Dinleme-Konuşma yok (0 girin).');
                }
            }
            if (sinifSel) sinifSel.addEventListener('change', updateHints);
            if (sinavSel) sinavSel.addEventListener('change', updateHints);
            updateHints();

            form.addEventListener('submit', function(e){
                var L = getLimits();
                if (!L) {
                    e.preventDefault();
                    alert('Lütfen sınıf ve sınav türü seçin.');
                    return;
                }
                var oyD = parseInt(form.querySelector('[name="okuma_yazma_dogru"]').value, 10) || 0;
                var oyY = parseInt(form.querySelector('[name="okuma_yazma_yanlis"]').value, 10) || 0;
                var oyB = parseInt(form.querySelector('[name="okuma_yazma_bos"]').value, 10) || 0;
                var dkD = parseInt(form.querySelector('[name="dinleme_konusma_dogru"]').value, 10) || 0;
                var dkY = parseInt(form.querySelector('[name="dinleme_konusma_yanlis"]').value, 10) || 0;
                var dkB = parseInt(form.querySelector('[name="dinleme_konusma_bos"]').value, 10) || 0;
                var oyGiren = oyD + oyY + oyB;
                var dkGiren = dkD + dkY + dkB;
                if (oyGiren !== L.oy) {
                    e.preventDefault();
                    alert('Okuma-Yazma toplamı ' + L.oy + ' olmalı. Sizin girişiniz: ' + oyGiren + '.');
                    return;
                }
                if (L.dk === 0 && dkGiren !== 0) {
                    e.preventDefault();
                    alert('Bu sınavda Dinleme-Konuşma yok; tüm değerler 0 olmalı.');
                    return;
                }
                if (L.dk > 0 && dkGiren !== L.dk) {
                    e.preventDefault();
                    alert('Dinleme-Konuşma toplamı ' + L.dk + ' olmalı. Sizin girişiniz: ' + dkGiren + '.');
                    return;
                }
            });
        })();
        </script>
    </div>
</div>
</body>
</html>
