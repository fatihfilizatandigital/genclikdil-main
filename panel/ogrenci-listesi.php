<?php
session_start();
require_once __DIR__ . '/../config/db.php';

function geri_parametreleri() {
    $p = [];
    if (!empty($_GET['sinif'])) $p[] = 'sinif=' . urlencode($_GET['sinif']);
    if (!empty($_GET['ara'])) $p[] = 'ara=' . urlencode($_GET['ara']);
    return empty($p) ? '' : '&' . implode('&', $p);
}
function geri_url($base = 'ogrenci-listesi.php') {
    $q = geri_parametreleri();
    return $base . ($q === '' ? '' : (strpos($base, '?') !== false ? $q : '?' . ltrim($q, '&')));
}

$tablolar_var = false;
@$t = mysqli_query($conn, "SHOW TABLES LIKE 'bursluluk_ogrenciler'");
if ($t && mysqli_num_rows($t) > 0) $tablolar_var = true;

$sinif_filtre = trim($_GET['sinif'] ?? '');
$ara_filtre = trim($_GET['ara'] ?? '');

$ogrenciler = [];
$ogrenci_sinavlar = [];
$sonuc_ids = [];

if ($tablolar_var) {
    $sql = "SELECT id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, dogum_tarihi FROM bursluluk_ogrenciler WHERE 1=1";
    $params = [];
    $types = '';
    if ($sinif_filtre !== '') {
        $sql .= " AND sinif = ?";
        $params[] = $sinif_filtre;
        $types .= 's';
    }
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
        while ($row = mysqli_fetch_assoc($res)) $ogrenciler[] = $row;
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

$siniflar = [];
if ($tablolar_var) {
    $sq = mysqli_query($conn, "SELECT DISTINCT sinif FROM bursluluk_ogrenciler ORDER BY sinif");
    while ($r = mysqli_fetch_assoc($sq)) $siniflar[] = $r['sinif'];
}

$geri_suffix = geri_parametreleri();
if ($geri_suffix !== '') $geri_suffix = '?' . ltrim($geri_suffix, '&');
$link_geri = ($sinif_filtre !== '' ? '&sinif=' . urlencode($sinif_filtre) : '') . ($ara_filtre !== '' ? '&ara=' . urlencode($ara_filtre) : '');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Öğrenci Listesi - Bursluluk Sınav Sonuç Sistemi</title>
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
        <div class="page-card wide">
            <h1>Öğrenci Listesi</h1>
            <p class="page-subtitle">Kayıtlı öğrenciler. Bilgileri düzenleyebilir, İngilizce/Almanca not girişi yapabilirsiniz.</p>
            <?php
            if (!empty($_GET['mesaj'])):
                if ($_GET['mesaj'] === 'eklendi') echo '<div class="mesaj ok">Öğrenci eklendi.</div>';
                elseif ($_GET['mesaj'] === 'duzenle') echo '<div class="mesaj ok">Öğrenci bilgileri güncellendi.</div>';
                else echo '<div class="mesaj ok">Not kaydedildi.</div>';
            endif;
            ?>

            <div class="liste-ust">
                <form method="get" action="ogrenci-listesi.php" class="arama-form">
                    <input type="text" id="arama_input" name="ara" value="<?= htmlspecialchars($ara_filtre) ?>" placeholder="Öğrenci adı, veli adı, telefon, sınıf, doğum tarihi..." class="arama-input">
                    <?php if ($sinif_filtre !== ''): ?><input type="hidden" name="sinif" value="<?= htmlspecialchars($sinif_filtre) ?>"><?php endif; ?>
                    <button type="submit" class="btn btn-ara">Ara</button>
                </form>
                <a href="ogrenci-ekle.php<?= $sinif_filtre !== '' ? '?sinif=' . urlencode($sinif_filtre) : '' ?>" class="btn btn-ekle">+ Öğrenci Ekle</a>
            </div>

            <?php if (!$tablolar_var): ?>
                <p class="bos">Önce <a href="xml-import.php">XML Import</a> veya yukarıdaki Öğrenci Ekle ile kayıt oluşturun.</p>
            <?php elseif (empty($ogrenciler)): ?>
                <p class="bos">Bu sınıfta kayıtlı öğrenci yok. <a href="ogrenci-ekle.php">Öğrenci Ekle</a> veya sınıf filtresini kaldırın.</p>
            <?php else: ?>
            <div class="filtre">
                <label>Sınıf:</label>
                <select id="filtre_sinif" onchange="applyFiltre()">
                    <option value="">Tümü</option>
                    <?php foreach ($siniflar as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $sinif_filtre === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($ara_filtre !== ''): ?><span class="arama-etiket">Arama: &quot;<?= htmlspecialchars($ara_filtre) ?>&quot;</span><?php endif; ?>
            </div>
            <script>
            function applyFiltre() {
                var s = document.getElementById('filtre_sinif').value;
                var ara = (document.getElementById('arama_input') || {}).value ? (document.getElementById('arama_input').value.trim()) : '';
                var parts = [];
                if (s) parts.push('sinif=' + encodeURIComponent(s));
                if (ara) parts.push('ara=' + encodeURIComponent(ara));
                location.href = 'ogrenci-listesi.php' + (parts.length ? '?' + parts.join('&') : '');
            }
            </script>

            <div class="ogrenci-liste">
                <?php foreach ($ogrenciler as $o): 
                    $oid = (int)$o['id'];
                    $sinavlar = $ogrenci_sinavlar[$oid] ?? [];
                ?>
                <div class="ogrenci-kart">
                    <div class="ogrenci-kart-bilgi">
                        <div class="ogrenci-kart-sol">
                            <div class="ogrenci-kart-ust">
                                <strong class="ogrenci-ad"><?= htmlspecialchars($o['ogrenci_adi'] . ' ' . $o['ogrenci_soyadi']) ?></strong>
                                <span class="ogrenci-sinif"><?= htmlspecialchars($o['sinif']) ?></span>
                                <a href="ogrenci-duzenle.php?id=<?= $oid ?><?= $link_geri ?>" class="btn btn-kucuk btn-bilgi" title="Ad, veli, sınıf vb. düzenle">Bilgileri Düzenle</a>
                            </div>
                            <div class="ogrenci-kart-detay">
                                <span>Doğum: <?= !empty($o['dogum_tarihi']) ? htmlspecialchars($o['dogum_tarihi']) : '—' ?></span>
                                <span>Veli: <?= htmlspecialchars($o['veli_adi'] . ' ' . $o['veli_soyadi']) ?></span>
                                <span>Tel: <?= htmlspecialchars($o['veli_telefon']) ?><?= !empty($o['veli_telefon_2']) ? ' / ' . htmlspecialchars($o['veli_telefon_2']) : '' ?></span>
                            </div>
                        </div>
                        <div class="ogrenci-kart-sinavlar">
                            <?php
                            foreach (['İngilizce', 'Almanca'] as $tur):
                                $sonuc_id = $sonuc_ids[$oid][$tur] ?? null;
                                $kayitli = in_array($tur, $ogrenci_sinavlar[$oid] ?? [], true);
                            ?>
                                <?php if ($sonuc_id): ?>
                                    <a href="not-duzenle.php?id=<?= $sonuc_id ?><?= $link_geri ?>" class="btn btn-kucuk btn-duzenle"><?= htmlspecialchars($tur) ?> — Notu Düzenle</a>
                                <?php elseif ($kayitli): ?>
                                    <a href="not-giris.php?ogrenci_id=<?= $oid ?>&sinav_turu=<?= urlencode($tur) ?><?= $link_geri ?>" class="btn btn-kucuk btn-gir"><?= htmlspecialchars($tur) ?> — Not Gir</a>
                                <?php else: ?>
                                    <a href="sinav-ekle.php?ogrenci_id=<?= $oid ?>&sinav_turu=<?= urlencode($tur) ?><?= $link_geri ?>" class="btn btn-kucuk btn-gir"><?= htmlspecialchars($tur) ?> — Not Gir</a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
