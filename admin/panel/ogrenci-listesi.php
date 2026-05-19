<?php
require_once __DIR__ . '/../auth.php';
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/inc/sinif_lise.php';

function geri_parametreleri() {
    $p = [];
    if (!empty($_GET['sinif'])) $p[] = 'sinif=' . urlencode($_GET['sinif']);
    if (!empty($_GET['ara'])) $p[] = 'ara=' . urlencode($_GET['ara']);
    return empty($p) ? '' : '&' . implode('&', $p);
}

$tablolar_var = false;
@$t = mysqli_query($conn, "SHOW TABLES LIKE 'bursluluk_ogrenciler'");
if ($t && mysqli_num_rows($t) > 0) $tablolar_var = true;

$siniflar_raw = [];
if ($tablolar_var) {
    $sq = mysqli_query($conn, "SELECT DISTINCT sinif FROM bursluluk_ogrenciler ORDER BY sinif");
    while ($r = mysqli_fetch_assoc($sq)) $siniflar_raw[] = $r['sinif'];
}
$sinif_opts = panel_sinif_dropdown_opts($siniflar_raw);

$sinif_filtre = trim($_GET['sinif'] ?? '');
$ara_filtre = trim($_GET['ara'] ?? '');

// Sınıf zorunlu: seçilmemişse ilk seçeneğe yönlendir
if ($tablolar_var && !empty($sinif_opts) && $sinif_filtre === '') {
    $first = $sinif_opts[0]['value'];
    header('Location: ogrenci-listesi.php?sinif=' . urlencode($first) . ($ara_filtre !== '' ? '&ara=' . urlencode($ara_filtre) : ''));
    exit;
}

$ogrenciler = [];
$ogrenci_sinavlar = [];
$sonuc_ids = [];

if ($tablolar_var && $sinif_filtre !== '') {
    list($sinif_where, $sinif_params) = panel_sinif_where_sql($sinif_filtre);
    $sql = "SELECT id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, dogum_tarihi FROM bursluluk_ogrenciler WHERE " . $sinif_where;
    $params = $sinif_params;
    $types = str_repeat('s', count($sinif_params));
    if ($ara_filtre !== '') {
        $like = '%' . $ara_filtre . '%';
        $sql .= " AND (ogrenci_adi LIKE ? OR ogrenci_soyadi LIKE ? OR veli_adi LIKE ? OR veli_soyadi LIKE ? OR veli_telefon LIKE ? OR veli_telefon_2 LIKE ? OR sinif LIKE ? OR dogum_tarihi LIKE ?)";
        for ($i = 0; $i < 8; $i++) { $params[] = $like; $types .= 's'; }
    }
    $sql .= " ORDER BY sinif, ogrenci_adi, ogrenci_soyadi";

    if (!empty($params)) {
        $stmt = mysqli_prepare($conn, $sql);
        $refs = [];
        foreach ($params as $k => $v) $refs[$k] = &$params[$k];
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $ogrenciler[] = $row;
        mysqli_stmt_close($stmt);
    } else {
        $res = mysqli_query($conn, $sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $ogrenciler[] = $row;
    }

    $q2 = mysqli_query($conn, "SELECT ogrenci_id, sinav_turu FROM bursluluk_ogrenci_sinav");
    while ($row = mysqli_fetch_assoc($q2)) {
        $ogrenci_sinavlar[$row['ogrenci_id']][] = $row['sinav_turu'];
    }
    $chk_oid = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari LIKE 'ogrenci_id'");
    $has_oid = $chk_oid && mysqli_num_rows($chk_oid) > 0;
    if ($has_oid) {
        $q3 = mysqli_query($conn, "SELECT id, ogrenci_id, sinav_turu FROM sinav_sonuclari WHERE ogrenci_id IS NOT NULL");
        while ($row = mysqli_fetch_assoc($q3)) {
            $sonuc_ids[$row['ogrenci_id']][$row['sinav_turu']] = (int)$row['id'];
        }
    }
}

$link_geri = ($sinif_filtre !== '' ? '&sinif=' . urlencode($sinif_filtre) : '') . ($ara_filtre !== '' ? '&ara=' . urlencode($ara_filtre) : '');
$aktif_personel = $_SESSION['personel_adi'] ?? '';
$gecici_not_islemleri_kapali = true;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Öğrenci Listesi — Sınav Not Girişi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --g-primary: #0d9488; --g-primary-dark: #0f766e; --g-bg: #f0f4f8; --g-card: #fff; --g-border: #e2e8f0; --g-text: #1e293b; --g-muted: #64748b; --g-radius: 12px; --g-radius-sm: 8px; --g-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--g-bg); color: var(--g-text); }
        .top-bar { background: linear-gradient(135deg, var(--g-primary) 0%, var(--g-primary-dark) 100%); padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(13,148,136,0.25); }
        .top-bar .brand { color: #fff; font-weight: 700; font-size: 1.2rem; }
        .top-bar .brand span { opacity: 0.9; font-weight: 500; font-size: 0.9rem; }
        .top-bar .btn-outline-light { border-color: rgba(255,255,255,0.6); color: #fff; }
        .top-bar .btn-outline-light:hover { background: rgba(255,255,255,0.2); color: #fff; }
        .panel-card { background: var(--g-card); border-radius: var(--g-radius); box-shadow: var(--g-shadow); border: 1px solid var(--g-border); padding: 20px; margin-bottom: 20px; }
        .panel-card .card-header { font-weight: 600; padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid var(--g-border); border-radius: var(--g-radius) var(--g-radius) 0 0; margin: -20px -20px 16px -20px; font-size: 0.95rem; }
        .ogrenci-kart { border: 1px solid var(--g-border); border-radius: var(--g-radius-sm); padding: 14px 16px; margin-bottom: 10px; background: #fff; transition: background 0.2s; }
        .ogrenci-kart:hover { background: #f0fdfa; }
        .ogrenci-kart .ogrenci-ad { font-weight: 600; color: var(--g-text); }
        .ogrenci-kart .ogrenci-sinif { font-size: 0.85rem; color: var(--g-muted); margin-left: 8px; }
        .btn-panel { background: var(--g-primary); border-color: var(--g-primary); color: #fff; }
        .btn-panel:hover { background: var(--g-primary-dark); color: #fff; }
        .form-select:focus, .form-control:focus { border-color: var(--g-primary); box-shadow: 0 0 0 3px rgba(13,148,136,0.15); }
    </style>
</head>
<body>
<div class="top-bar">
    <div class="brand">Sınav not girişi <span>— Öğrenci listesi</span></div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-white-50"><?= htmlspecialchars($aktif_personel) ?></span>
        <a href="index.php" class="btn btn-sm btn-outline-light">Ana sayfa</a>
        <a href="../index.php" class="btn btn-sm btn-outline-light">Yönetim paneline dön</a>
    </div>
</div>
<div class="container-fluid px-3 py-4">
    <div class="panel-card">
        <h1 class="h5 fw-bold mb-2">Öğrenci listesi</h1>
        <p class="text-muted small mb-3">Kayıtlı öğrenciler; bilgi düzenleme ve İngilizce/Almanca not girişi.</p>
        <?php
        if (!empty($_GET['mesaj'])):
            if ($_GET['mesaj'] === 'eklendi') echo '<div class="alert alert-success py-2">Öğrenci eklendi.</div>';
            elseif ($_GET['mesaj'] === 'duzenle') echo '<div class="alert alert-success py-2">Öğrenci bilgileri güncellendi.</div>';
            else echo '<div class="alert alert-success py-2">Not kaydedildi.</div>';
        endif;
        ?>

        <?php if (!empty($sinif_opts)): ?>
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <form method="get" action="ogrenci-listesi.php" class="d-flex gap-2 flex-wrap align-items-center">
                <label class="form-label mb-0 small fw-bold text-secondary">Sınıf</label>
                <select name="sinif" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                    <?php foreach ($sinif_opts as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['value']) ?>" <?= $sinif_filtre === $opt['value'] ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <form method="get" action="ogrenci-listesi.php" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="sinif" value="<?= htmlspecialchars($sinif_filtre) ?>">
                <input type="text" name="ara" class="form-control form-control-sm" style="width:220px;" value="<?= htmlspecialchars($ara_filtre) ?>" placeholder="Öğrenci, veli, telefon...">
                <button type="submit" class="btn btn-sm btn-panel">Ara</button>
            </form>
            <a href="ogrenci-ekle.php?sinif=<?= urlencode($sinif_filtre) ?>" class="btn btn-sm btn-panel">+ Öğrenci ekle</a>
        </div>
        <?php endif; ?>

        <?php if (!empty($_GET['bilgi']) && ($_GET['bilgi'] === 'not-giris-devre-disi' || $_GET['bilgi'] === 'not-duzenle-devre-disi')): ?>
            <div class="alert alert-warning py-2 mb-3">Not giriş ve not düzenleme özellikleri geçici olarak devre dışıdır.</div>
        <?php endif; ?>
        <?php if (!$tablolar_var): ?>
            <p class="text-muted">Önce <a href="xml-import.php">XML Import</a> veya Öğrenci Ekle ile kayıt oluşturun.</p>
        <?php elseif (empty($sinif_opts)): ?>
            <p class="text-muted">Henüz sınıf verisi yok.</p>
        <?php elseif (empty($ogrenciler)): ?>
            <p class="text-muted">Bu sınıfta kayıtlı öğrenci yok. <a href="ogrenci-ekle.php?sinif=<?= urlencode($sinif_filtre) ?>">Öğrenci ekle</a></p>
        <?php else: ?>
            <div class="ogrenci-liste">
                <?php foreach ($ogrenciler as $o):
                    $oid = (int)$o['id'];
                    $sinavlar = $ogrenci_sinavlar[$oid] ?? [];
                ?>
                <div class="ogrenci-kart">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <strong class="ogrenci-ad"><?= htmlspecialchars($o['ogrenci_adi'] . ' ' . $o['ogrenci_soyadi']) ?></strong>
                            <span class="ogrenci-sinif"><?= htmlspecialchars($o['sinif']) ?></span>
                            <a href="ogrenci-duzenle.php?id=<?= $oid ?><?= $link_geri ?>" class="btn btn-sm btn-outline-secondary ms-2">Bilgileri düzenle</a>
                            <div class="small text-muted mt-1">Doğum: <?= !empty($o['dogum_tarihi']) ? htmlspecialchars($o['dogum_tarihi']) : '—' ?> · Veli: <?= htmlspecialchars($o['veli_adi'] . ' ' . $o['veli_soyadi']) ?> · Tel: <?= htmlspecialchars($o['veli_telefon']) ?><?= !empty($o['veli_telefon_2']) ? ' / ' . htmlspecialchars($o['veli_telefon_2']) : '' ?></div>
                        </div>
                        <div class="d-flex gap-1 flex-wrap">
                            <?php if ($gecici_not_islemleri_kapali): ?>
                                <span class="badge text-bg-secondary">Not işlemleri geçici olarak kapalı</span>
                            <?php else: ?>
                                <?php foreach (['İngilizce', 'Almanca'] as $tur):
                                    $sonuc_id = $sonuc_ids[$oid][$tur] ?? null;
                                    $kayitli = in_array($tur, $ogrenci_sinavlar[$oid] ?? [], true);
                                    if ($sonuc_id): ?>
                                        <a href="not-duzenle.php?id=<?= $sonuc_id ?><?= $link_geri ?>" class="btn btn-sm btn-outline-primary"><?= htmlspecialchars($tur) ?> — Düzenle</a>
                                    <?php elseif ($kayitli): ?>
                                        <a href="not-giris.php?ogrenci_id=<?= $oid ?>&sinav_turu=<?= urlencode($tur) ?><?= $link_geri ?>" class="btn btn-sm btn-panel"><?= htmlspecialchars($tur) ?> — Not gir</a>
                                    <?php else: ?>
                                        <a href="sinav-ekle.php?ogrenci_id=<?= $oid ?>&sinav_turu=<?= urlencode($tur) ?><?= $link_geri ?>" class="btn btn-sm btn-panel"><?= htmlspecialchars($tur) ?> — Not gir</a>
                                    <?php endif;
                                endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function(){
    var KEY = 'scroll_ogrenci_listesi';
    document.addEventListener('click', function(e){
        var a = e.target.closest('a[href*="duzenle"], a[href*="not-giris"], a[href*="sinav-ekle"]');
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
