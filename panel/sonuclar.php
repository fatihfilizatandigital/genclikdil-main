<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$sinif_filtre = $_GET['sinif'] ?? '';
$sinav_filtre = $_GET['sinav_turu'] ?? '';

$has_dogum = false;
$dc = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari LIKE 'dogum_tarihi'");
if ($dc && mysqli_num_rows($dc) > 0) $has_dogum = true;

$sql = "SELECT id, ogrenci_adi, ogrenci_soyadi, veli_adi, veli_soyadi, veli_telefon, veli_telefon_2, sinif, " . ($has_dogum ? "dogum_tarihi, " : "") . "sinav_turu,
        okuma_yazma_dogru, okuma_yazma_yanlis, okuma_yazma_bos,
        dinleme_konusma_dogru, dinleme_konusma_yanlis, dinleme_konusma_bos,
        toplam_dogru, toplam_yanlis, toplam_bos, toplam_soru, basari_yuzdesi, kayit_tarihi
        FROM sinav_sonuclari";
$where = [];
$types = '';
$params = [];
if ($sinav_filtre !== '') {
    $where[] = "sinav_turu = ?";
    $types .= 's';
    $params[] = $sinav_filtre;
}
if ($sinif_filtre !== '') {
    $where[] = "sinif = ?";
    $types .= 's';
    $params[] = $sinif_filtre;
}
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY sinav_turu ASC, sinif ASC, basari_yuzdesi DESC, toplam_yanlis ASC";
if ($has_dogum) {
    $sql .= ", (CASE WHEN dogum_tarihi IS NULL THEN 1 ELSE 0 END) ASC, dogum_tarihi DESC";
}

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql);
    $refs = [];
    foreach ($params as $key => $val) $refs[$key] = &$params[$key];
    call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
}

$tum_kayitlar = [];
while ($row = mysqli_fetch_assoc($result)) {
    $tum_kayitlar[] = $row;
}

$sinav_onceki = null;
$sinif_onceki = null;
$sira = 0;
foreach ($tum_kayitlar as $i => $r) {
    if (($r['sinav_turu'] ?? '') !== $sinav_onceki || $r['sinif'] !== $sinif_onceki) {
        $sira = 1;
        $sinav_onceki = $r['sinav_turu'] ?? '';
        $sinif_onceki = $r['sinif'];
    } else {
        $sira++;
    }
    $tum_kayitlar[$i]['sinif_sira'] = $sira;
}

$siniflar_sql = "SELECT DISTINCT sinif FROM sinav_sonuclari";
if ($sinav_filtre !== '') {
    $siniflar_sql .= " WHERE sinav_turu = '" . mysqli_real_escape_string($conn, $sinav_filtre) . "'";
}
$siniflar_sql .= " ORDER BY sinif";
$siniflar_result = mysqli_query($conn, $siniflar_sql);
$siniflar = [];
while ($row = mysqli_fetch_assoc($siniflar_result)) {
    $siniflar[] = $row['sinif'];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sonuçlar - Notu Girilmiş Öğrenciler</title>
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
            <h1>Notu Girilmiş Öğrenciler</h1>
            <p class="page-subtitle">Sınav sonuçları, sınıf bazında sıralı.</p>

            <div class="filtre">
            <label>Sınav türü:</label>
            <select id="filtre_sinav" onchange="applyFilter()">
                <option value="" <?= $sinav_filtre === '' ? 'selected' : '' ?>>Tümü</option>
                <option value="İngilizce" <?= $sinav_filtre === 'İngilizce' ? 'selected' : '' ?>>İngilizce</option>
                <option value="Almanca" <?= $sinav_filtre === 'Almanca' ? 'selected' : '' ?>>Almanca</option>
            </select>
            <label>Sınıf:</label>
            <select id="filtre_sinif" onchange="applyFilter()">
                <option value="">Tümü</option>
                <?php foreach ($siniflar as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $sinif_filtre === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <script>
        function applyFilter() {
            var sinav = document.getElementById('filtre_sinav').value;
            var sinif = document.getElementById('filtre_sinif').value;
            var q = [];
            if (sinav) q.push('sinav_turu=' + encodeURIComponent(sinav));
            if (sinif) q.push('sinif=' + encodeURIComponent(sinif));
            location.href = 'sonuclar.php' + (q.length ? '?' + q.join('&') : '');
        }
        </script>

        <?php if (empty($tum_kayitlar)): ?>
            <p class="bos">Henüz not girilmiş öğrenci yok. <a href="ogrenci-listesi.php">Öğrenci listesinden</a> not girişi yapabilirsiniz.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sıra</th>
                        <th>Sınav</th>
                        <th>Sınıf</th>
                        <?php if ($has_dogum): ?><th>Doğum Tarihi</th><?php endif; ?>
                        <th>Öğrenci Adı Soyadı</th>
                        <th>Veli Adı Soyadı</th>
                        <th>Veli Tel</th>
                        <th>O-Y Doğru</th>
                        <th>O-Y Yanlış</th>
                        <th>O-Y Boş</th>
                        <th>D-K Doğru</th>
                        <th>D-K Yanlış</th>
                        <th>D-K Boş</th>
                        <th>Toplam Doğru</th>
                        <th>Toplam</th>
                        <th>Başarı %</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tum_kayitlar as $k): ?>
                    <tr>
                        <td class="sira"><?= (int)$k['sinif_sira'] ?></td>
                        <td><?= htmlspecialchars($k['sinav_turu'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($k['sinif']) ?></td>
                        <?php if ($has_dogum): ?><td><?= !empty($k['dogum_tarihi']) ? htmlspecialchars($k['dogum_tarihi']) : '—' ?></td><?php endif; ?>
                        <td><?= htmlspecialchars($k['ogrenci_adi'] . ' ' . $k['ogrenci_soyadi']) ?></td>
                        <td><?= htmlspecialchars($k['veli_adi'] . ' ' . $k['veli_soyadi']) ?></td>
                        <td><?= htmlspecialchars($k['veli_telefon']) ?><?= $k['veli_telefon_2'] ? ' / ' . htmlspecialchars($k['veli_telefon_2']) : '' ?></td>
                        <td><?= (int)$k['okuma_yazma_dogru'] ?></td>
                        <td><?= (int)$k['okuma_yazma_yanlis'] ?></td>
                        <td><?= (int)$k['okuma_yazma_bos'] ?></td>
                        <td><?= (int)$k['dinleme_konusma_dogru'] ?></td>
                        <td><?= (int)$k['dinleme_konusma_yanlis'] ?></td>
                        <td><?= (int)$k['dinleme_konusma_bos'] ?></td>
                        <td><strong><?= (int)$k['toplam_dogru'] ?></strong></td>
                        <td><?= (int)$k['toplam_soru'] ?></td>
                        <td><strong><?= number_format($k['basari_yuzdesi'], 1, ',', '') ?>%</strong></td>
                        <td><a href="not-duzenle.php?id=<?= (int)$k['id'] ?><?= $sinav_filtre !== '' ? '&sinav_turu=' . urlencode($sinav_filtre) : '' ?><?= $sinif_filtre !== '' ? '&sinif=' . urlencode($sinif_filtre) : '' ?>" class="duzenle-link">Düzenle</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="table-footer">O-Y: Okuma-Yazma, D-K: Dinleme-Konuşma. Sıra her sınav türü ve sınıf içinde: önce başarı %, sonra az yanlış, eşitse doğum tarihine göre (daha küçük yaş önde).</p>
        <?php endif; ?>
        </div>
    </main>
</body>
</html>
