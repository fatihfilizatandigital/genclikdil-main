<?php
require_once __DIR__ . '/../auth.php';
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/inc/sinif_lise.php';

$sinav_opts = [['value' => 'İngilizce', 'label' => 'İngilizce'], ['value' => 'Almanca', 'label' => 'Almanca']];

$sinif_filtre = trim($_GET['sinif'] ?? '');
$sinav_filtre = trim($_GET['sinav_turu'] ?? '');

$siniflar_raw = [];
$sq = mysqli_query($conn, "SELECT DISTINCT sinif FROM sinav_sonuclari ORDER BY sinif");
while ($r = mysqli_fetch_assoc($sq)) $siniflar_raw[] = $r['sinif'];
$sinif_opts = panel_sinif_dropdown_opts($siniflar_raw);

// Sınav ve sınıf zorunlu: seçilmemişse ilk sınav + ilk sınıfa yönlendir (seçenek varsa)
if (!empty($sinif_opts) && ($sinav_filtre === '' || $sinif_filtre === '')) {
    $s1 = $sinav_opts[0]['value'];
    $s2 = $sinif_opts[0]['value'];
    header('Location: sonuclar.php?sinav_turu=' . urlencode($s1) . '&sinif=' . urlencode($s2));
    exit;
}

$has_dogum = false;
$dc = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari LIKE 'dogum_tarihi'");
if ($dc && mysqli_num_rows($dc) > 0) $has_dogum = true;

$tum_kayitlar = [];
if ($sinav_filtre !== '' && $sinif_filtre !== '') {
    list($sinif_where, $sinif_params) = panel_sinif_where_sql($sinif_filtre);
    $sql = "SELECT id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, " . ($has_dogum ? "dogum_tarihi, " : "") . "sinav_turu,
            okuma_yazma_dogru, okuma_yazma_yanlis, okuma_yazma_bos,
            dinleme_konusma_dogru, dinleme_konusma_yanlis, dinleme_konusma_bos,
            toplam_dogru, toplam_yanlis, toplam_bos, toplam_soru, basari_yuzdesi, kayit_tarihi
            FROM sinav_sonuclari WHERE sinav_turu = ? AND " . $sinif_where . "
            ORDER BY basari_yuzdesi DESC, toplam_yanlis ASC";
    if ($has_dogum) {
        $sql .= ", (CASE WHEN dogum_tarihi IS NULL THEN 1 ELSE 0 END) ASC, dogum_tarihi DESC";
    }
    $params = array_merge([$sinav_filtre], $sinif_params);
    $types = 's' . str_repeat('s', count($sinif_params));
    $stmt = mysqli_prepare($conn, $sql);
    $refs = [];
    foreach ($params as $k => $v) $refs[$k] = &$params[$k];
    call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) $tum_kayitlar[] = $row;
    mysqli_stmt_close($stmt);
}

$sinav_onceki = null;
$sinif_onceki = null;
$sira = 0;
// Lise filtresinde tüm liste başarıya göre tek sıra (1,2,3...); diğer sınıflarda sıra sınıf/sınav değişiminde sıfırlanır
$lise_tek_sira = ($sinif_filtre === 'Lise');
foreach ($tum_kayitlar as $i => $r) {
    if ($lise_tek_sira) {
        $sira++;
        $tum_kayitlar[$i]['sinif_sira'] = $sira;
    } else {
        if (($r['sinav_turu'] ?? '') !== $sinav_onceki || $r['sinif'] !== $sinif_onceki) {
            $sira = 1;
            $sinav_onceki = $r['sinav_turu'] ?? '';
            $sinif_onceki = $r['sinif'];
        } else {
            $sira++;
        }
        $tum_kayitlar[$i]['sinif_sira'] = $sira;
    }
}

$aktif_personel = $_SESSION['personel_adi'] ?? '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sonuçlar — Sınav not girişi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --g-primary: #0d9488; --g-primary-dark: #0f766e; --g-bg: #f0f4f8; --g-card: #fff; --g-border: #e2e8f0; --g-text: #1e293b; --g-muted: #64748b; --g-radius: 12px; --g-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--g-bg); color: var(--g-text); }
        .top-bar { background: linear-gradient(135deg, var(--g-primary) 0%, var(--g-primary-dark) 100%); padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(13,148,136,0.25); }
        .top-bar .brand { color: #fff; font-weight: 700; font-size: 1.2rem; }
        .top-bar .btn-outline-light { border-color: rgba(255,255,255,0.6); color: #fff; }
        .top-bar .btn-outline-light:hover { background: rgba(255,255,255,0.2); color: #fff; }
        .panel-card { background: var(--g-card); border-radius: var(--g-radius); box-shadow: var(--g-shadow); border: 1px solid var(--g-border); padding: 20px; margin-bottom: 20px; overflow-x: auto; }
        .panel-card table { font-size: 0.9rem; }
        .panel-card table th { background: #f8fafc; font-weight: 600; white-space: nowrap; }
        .form-select:focus { border-color: var(--g-primary); box-shadow: 0 0 0 3px rgba(13,148,136,0.15); }
    </style>
</head>
<body>
<div class="top-bar">
    <div class="brand">Sınav not girişi <span>— Sonuçlar</span></div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-white-50"><?= htmlspecialchars($aktif_personel) ?></span>
        <a href="index.php" class="btn btn-sm btn-outline-light">Ana sayfa</a>
        <a href="../index.php" class="btn btn-sm btn-outline-light">Yönetim paneline dön</a>
    </div>
</div>
<div class="container-fluid px-3 py-4">
    <div class="panel-card">
        <h1 class="h5 fw-bold mb-2">Notu girilmiş öğrenciler</h1>
        <p class="text-muted small mb-3">Sınav sonuçları, sınıf bazında sıralı.</p>

        <?php if (!empty($sinif_opts)): ?>
        <form method="get" action="sonuclar.php" class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <label class="form-label mb-0 small fw-bold text-secondary">Sınav türü</label>
            <select name="sinav_turu" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                <?php foreach ($sinav_opts as $o): ?>
                    <option value="<?= htmlspecialchars($o['value']) ?>" <?= $sinav_filtre === $o['value'] ? 'selected' : '' ?>><?= htmlspecialchars($o['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="form-label mb-0 small fw-bold text-secondary">Sınıf</label>
            <select name="sinif" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                <?php foreach ($sinif_opts as $o): ?>
                    <option value="<?= htmlspecialchars($o['value']) ?>" <?= $sinif_filtre === $o['value'] ? 'selected' : '' ?>><?= htmlspecialchars($o['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
        <?php if (!empty($_GET['bilgi']) && $_GET['bilgi'] === 'not-duzenle-devre-disi'): ?>
            <div class="alert alert-warning py-2 mb-3">Not düzenleme özelliği geçici olarak devre dışıdır.</div>
        <?php endif; ?>
        <?php if (empty($sinif_opts)): ?>
            <p class="text-muted">Henüz not girilmiş kayıt yok. <a href="ogrenci-listesi.php">Öğrenci listesinden</a> not girişi yapın.</p>
        <?php elseif (empty($tum_kayitlar)): ?>
            <p class="text-muted">Bu sınav ve sınıf için kayıt yok.</p>
        <?php else: ?>
            <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th>Sıra</th>
                        <th>Sınav</th>
                        <th>Sınıf</th>
                        <?php if ($has_dogum): ?><th>Doğum</th><?php endif; ?>
                        <th>Öğrenci</th>
                        <th>Veli</th>
                        <th>Tel</th>
                        <th>O-Y D/Y/B</th>
                        <th>D-K D/Y/B</th>
                        <th>Toplam</th>
                        <th>%</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tum_kayitlar as $k): ?>
                    <tr>
                        <td><?= (int)$k['sinif_sira'] ?></td>
                        <td><?= htmlspecialchars($k['sinav_turu'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($k['sinif']) ?></td>
                        <?php if ($has_dogum): ?><td><?= !empty($k['dogum_tarihi']) ? htmlspecialchars($k['dogum_tarihi']) : '—' ?></td><?php endif; ?>
                        <td><?= htmlspecialchars($k['ogrenci_adi'] . ' ' . $k['ogrenci_soyadi']) ?></td>
                        <td><?= htmlspecialchars($k['veli_adi'] . ' ' . $k['veli_soyadi']) ?></td>
                        <td><?= htmlspecialchars($k['veli_telefon']) ?><?= !empty($k['veli_telefon_2']) ? ' / ' . htmlspecialchars($k['veli_telefon_2']) : '' ?></td>
                        <td><?= (int)$k['okuma_yazma_dogru'] ?> / <?= (int)$k['okuma_yazma_yanlis'] ?> / <?= (int)$k['okuma_yazma_bos'] ?></td>
                        <td><?= (int)$k['dinleme_konusma_dogru'] ?> / <?= (int)$k['dinleme_konusma_yanlis'] ?> / <?= (int)$k['dinleme_konusma_bos'] ?></td>
                        <td><strong><?= (int)$k['toplam_dogru'] ?></strong> / <?= (int)$k['toplam_soru'] ?></td>
                        <td><strong><?= number_format($k['basari_yuzdesi'], 1, ',', '') ?>%</strong></td>
                        <td><span class="text-muted">Geçici olarak kapalı</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="small text-muted mt-2">O-Y: Okuma-Yazma, D-K: Dinleme-Konuşma. Sıra: başarı %, sonra az yanlış.</p>
        <?php endif; ?>
    </div>
</div>
<script>
(function(){
    var KEY = 'scroll_sonuclar';
    document.addEventListener('click', function(e){
        var a = e.target.closest('a[href*="not-duzenle"]');
        if (a) sessionStorage.setItem(KEY, window.scrollY || document.documentElement.scrollTop);
    });
    var pos = sessionStorage.getItem(KEY);
    if (pos !== null) {
        sessionStorage.removeItem(KEY);
        requestAnimationFrame(function(){ window.scrollTo(0, parseInt(pos, 10)); });
    }
})();
</script>
</body>
</html>
