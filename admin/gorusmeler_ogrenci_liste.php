<?php
/**
 * Görüşmeler Öğrenci Detay Listesi (Excel benzeri görünüm)
 * - Tüm öğrenciler tek tabloda
 * - Filtre/sıralama
 * - CSV (Excel) dışa aktarma
 */
require_once __DIR__ . '/auth_gorusmeler.php';

$aktif_personel = $_SESSION['personel_adi'] ?? '';
$conn = mysqli_connect("213.142.130.21", "adnan", "adnan.1234", "farmMysql", 3306);
mysqli_set_charset($conn, "utf8mb4");
if (!$conn) die("Veritabanı bağlantısı kurulamadı.");

$tbl = @mysqli_query($conn, "SHOW TABLES LIKE 'gorusme_listesi'");
if (!$tbl || mysqli_num_rows($tbl) === 0) die("gorusme_listesi tablosu yok.");

$tbl_r = @mysqli_query($conn, "SHOW TABLES LIKE 'gorusme_randevulari'");
$randevulari_var = ($tbl_r && mysqli_num_rows($tbl_r) > 0);

function gl_parse_sinif($raw): int {
    $s = trim((string)$raw);
    if ($s === '') return 0;
    if (preg_match('/^\d+/', $s, $m)) return (int)$m[0];
    return (int)$s;
}

$LISE_SINIFLAR = [9, 10, 11, 12];

// Filtreler
$f_sinif = isset($_GET['sinif']) ? trim((string)$_GET['sinif']) : '';
$f_q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$f_durum = isset($_GET['durum']) ? trim((string)$_GET['durum']) : '';
$f_sinif_sirala = isset($_GET['sinif_sirala']) ? trim((string)$_GET['sinif_sirala']) : 'asc'; // asc|desc
$f_sira_sirala = isset($_GET['sira_sirala']) ? trim((string)$_GET['sira_sirala']) : ''; // asc|desc|''
$f_randevu = isset($_GET['randevu']) ? trim((string)$_GET['randevu']) : 'tum'; // tum|var|yok
$f_r_gun = isset($_GET['r_gun']) ? trim((string)$_GET['r_gun']) : ''; // YYYY-MM-DD
$f_r_durum = isset($_GET['r_durum']) ? trim((string)$_GET['r_durum']) : ''; // Bekleniyor|Geldi|Gelmedi|''
$f_r_zaman = isset($_GET['r_zaman']) ? trim((string)$_GET['r_zaman']) : 'tum'; // tum|yaklasan|gecmis
$f_export = isset($_GET['export']) && $_GET['export'] === '1';

if (!in_array($f_sinif_sirala, ['asc', 'desc'], true)) $f_sinif_sirala = 'asc';
if (!in_array($f_sira_sirala, ['', 'asc', 'desc'], true)) $f_sira_sirala = '';
if (!in_array($f_randevu, ['tum', 'var', 'yok'], true)) $f_randevu = 'tum';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_r_gun)) $f_r_gun = '';
if (!in_array($f_r_durum, ['', 'Bekleniyor', 'Geldi', 'Gelmedi'], true)) $f_r_durum = '';
if (!in_array($f_r_zaman, ['tum', 'yaklasan', 'gecmis'], true)) $f_r_zaman = 'tum';
$durum_ops = [
    'Bekliyor',
    'Sonuc Iletildi',
    'Randevu Alindi',
    'Gorusuldu (Yuz Yuze)',
    'Gorusuldu (Telefon)',
    'Sonuc Icin Ulasilamadi',
    'Gorusme Icin Ulasilamadi',
    'Ertelendi',
    'WhatsappDonusYapmadi',
    'KayitOldu',
    'KayitOlmakIstemiyor'
];
if (!in_array($f_durum, array_merge([''], $durum_ops), true)) $f_durum = '';

// Randevu kolon kontrolü
$randevu_notu_kolon_var = false;
$randevu_sorumlu_kolon_var = false;
if ($randevulari_var) {
    $c1 = @mysqli_query($conn, "SHOW COLUMNS FROM gorusme_randevulari LIKE 'randevu_notu'");
    $randevu_notu_kolon_var = ($c1 && mysqli_num_rows($c1) > 0);
    $c2 = @mysqli_query($conn, "SHOW COLUMNS FROM gorusme_randevulari LIKE 'randevu_sorumlusu'");
    $randevu_sorumlu_kolon_var = ($c2 && mysqli_num_rows($c2) > 0);
}

// Sıra hesapları (gorusmeler.php ile uyumlu)
$sira_by_id = [];
$toplam_by_id = [];
$rank_by_sonuc = [];
$total_by_sonuc = [];

$has_ss = @mysqli_query($conn, "SHOW TABLES LIKE 'sinav_sonuclari'");
if ($has_ss && mysqli_num_rows($has_ss) > 0) {
    $has_toplam_yanlis = false;
    $has_dogum_tarihi = false;
    $c1 = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari LIKE 'toplam_yanlis'");
    if ($c1 && mysqli_num_rows($c1) > 0) $has_toplam_yanlis = true;
    $c2 = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari LIKE 'dogum_tarihi'");
    if ($c2 && mysqli_num_rows($c2) > 0) $has_dogum_tarihi = true;

    $ss_sql = "SELECT id, sinif, IFNULL(sinav_turu,'') AS sinav_turu, basari_yuzdesi";
    $ss_sql .= $has_toplam_yanlis ? ", toplam_yanlis" : ", 0 AS toplam_yanlis";
    $ss_sql .= $has_dogum_tarihi ? ", dogum_tarihi" : ", NULL AS dogum_tarihi";
    $ss_sql .= " FROM sinav_sonuclari";
    $res_ss = mysqli_query($conn, $ss_sql);
    if ($res_ss) {
        $gruplar = [];
        while ($row = mysqli_fetch_assoc($res_ss)) {
            $sinif_no = gl_parse_sinif($row['sinif'] ?? 0);
            $grup_key = in_array($sinif_no, $LISE_SINIFLAR, true) ? 'Lise' : (string)$sinif_no;
            $key = $grup_key . '_' . trim((string)($row['sinav_turu'] ?? ''));
            if (!isset($gruplar[$key])) $gruplar[$key] = [];
            $gruplar[$key][] = [
                'id' => (int)$row['id'],
                'basari' => $row['basari_yuzdesi'] === null ? -1 : (float)$row['basari_yuzdesi'],
                'yanlis' => (int)($row['toplam_yanlis'] ?? 0),
                'dogum' => $row['dogum_tarihi'] ?? null,
            ];
        }
        foreach ($gruplar as $ids) {
            usort($ids, function($a, $b) {
                if ($a['basari'] !== $b['basari']) return ($a['basari'] < $b['basari']) ? 1 : -1;
                if ($a['yanlis'] !== $b['yanlis']) return ($a['yanlis'] < $b['yanlis']) ? -1 : 1;
                $aNull = empty($a['dogum']) ? 1 : 0;
                $bNull = empty($b['dogum']) ? 1 : 0;
                if ($aNull !== $bNull) return $aNull <=> $bNull;
                if (!$aNull && !$bNull && $a['dogum'] !== $b['dogum']) return strcmp((string)$b['dogum'], (string)$a['dogum']);
                return $a['id'] <=> $b['id'];
            });
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                $rank_by_sonuc[$ids[$i]['id']] = $i + 1;
                $total_by_sonuc[$ids[$i]['id']] = $n;
            }
        }
    }
}

$res_gl_rank = mysqli_query($conn, "SELECT id, sinav_sonuc_id, sinav_sonuc_id_ingilizce, sinav_sonuc_id_almanca, basari_yuzdesi, basari_yuzdesi_ingilizce, basari_yuzdesi_almanca, sinif, sinav_turu FROM gorusme_listesi ORDER BY sinif ASC, sinav_turu ASC, basari_yuzdesi DESC, id ASC");
if ($res_gl_rank) {
    $fallback_gruplar = [];
    while ($row = mysqli_fetch_assoc($res_gl_rank)) {
        $gid = (int)$row['id'];
        $sid_eng = (int)($row['sinav_sonuc_id_ingilizce'] ?? 0);
        $sid_alm = (int)($row['sinav_sonuc_id_almanca'] ?? 0);
        $sid_legacy = (int)($row['sinav_sonuc_id'] ?? 0);
        $sid = $sid_eng > 0 ? $sid_eng : ($sid_alm > 0 ? $sid_alm : $sid_legacy);
        if ($sid > 0 && isset($rank_by_sonuc[$sid], $total_by_sonuc[$sid])) {
            $sira_by_id[$gid] = $rank_by_sonuc[$sid];
            $toplam_by_id[$gid] = $total_by_sonuc[$sid];
            continue;
        }
        $sinif_no = (int)$row['sinif'];
        $grup_key = in_array($sinif_no, $LISE_SINIFLAR, true) ? 'Lise' : (string)$sinif_no;
        $fallback_turu = $sid_eng > 0 ? 'İngilizce' : ($sid_alm > 0 ? 'Almanca' : trim((string)($row['sinav_turu'] ?? '')));
        $key = $grup_key . '_' . $fallback_turu;
        $basari_pref = $row['basari_yuzdesi_ingilizce'];
        if ($basari_pref === null || $basari_pref === '') $basari_pref = $row['basari_yuzdesi_almanca'];
        if ($basari_pref === null || $basari_pref === '') $basari_pref = $row['basari_yuzdesi'];
        if (!isset($fallback_gruplar[$key])) $fallback_gruplar[$key] = [];
        $fallback_gruplar[$key][] = ['id' => $gid, 'basari' => $basari_pref === null ? -1 : (float)$basari_pref];
    }
    foreach ($fallback_gruplar as $ids) {
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            $sira_by_id[$ids[$i]['id']] = $i + 1;
            $toplam_by_id[$ids[$i]['id']] = $n;
        }
    }
}

// Filtre WHERE
$where = ["1=1"];
if ($f_sinif !== '' && $f_sinif !== 'tum') {
    if ($f_sinif === 'Lise') {
        $where[] = "gl.sinif IN (" . implode(',', $LISE_SINIFLAR) . ")";
    } else {
        $where[] = "gl.sinif = " . (int)$f_sinif;
    }
}
if ($f_q !== '') {
    $q_esc = mysqli_real_escape_string($conn, $f_q);
    $where[] = "(gl.ogrenci_ad LIKE '%$q_esc%' OR gl.ogrenci_soyad LIKE '%$q_esc%' OR gl.veli_ad LIKE '%$q_esc%' OR gl.veli_soyad LIKE '%$q_esc%' OR gl.tel_temiz LIKE '%$q_esc%' OR gl.gorusme_durumu LIKE '%$q_esc%')";
}
if ($f_durum !== '') {
    $f_durum_esc = mysqli_real_escape_string($conn, $f_durum);
    $where[] = "gl.gorusme_durumu = '$f_durum_esc'";
}
if ($randevulari_var) {
    $rf = [];
    if ($f_r_gun !== '') {
        $f_r_gun_esc = mysqli_real_escape_string($conn, $f_r_gun);
        $rf[] = "DATE(rf.randevu_tarihi) = '$f_r_gun_esc'";
    }
    if ($f_r_durum !== '') {
        $f_r_durum_esc = mysqli_real_escape_string($conn, $f_r_durum);
        $rf[] = "rf.randevu_durumu = '$f_r_durum_esc'";
    }
    if ($f_r_zaman === 'yaklasan') $rf[] = "rf.randevu_tarihi >= NOW()";
    if ($f_r_zaman === 'gecmis') $rf[] = "rf.randevu_tarihi < NOW()";
    $rf_cond = !empty($rf) ? implode(' AND ', $rf) : '1=1';

    if ($f_randevu === 'yok') {
        $where[] = "NOT EXISTS (SELECT 1 FROM gorusme_randevulari rf WHERE rf.tel_temiz = gl.tel_temiz)";
    } elseif ($f_randevu === 'var' || $f_r_gun !== '' || $f_r_durum !== '' || $f_r_zaman !== 'tum') {
        $where[] = "EXISTS (SELECT 1 FROM gorusme_randevulari rf WHERE rf.tel_temiz = gl.tel_temiz AND $rf_cond)";
    }
}
$where_sql = implode(' AND ', $where);

$randevu_sub = "NULL AS randevu_ozet";
if ($randevulari_var) {
    $r_not = $randevu_notu_kolon_var ? "IFNULL(NULLIF(TRIM(r.randevu_notu),''),'-')" : "'-'";
    $r_sorumlu = $randevu_sorumlu_kolon_var ? "IFNULL(NULLIF(TRIM(r.randevu_sorumlusu),''), IFNULL(NULLIF(TRIM(r.personel),''), '-'))" : "IFNULL(NULLIF(TRIM(r.personel),''), '-')";
    $randevu_sub = "(SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(r.randevu_tarihi, '%d.%m.%Y %H:%i'), ' [', IFNULL(NULLIF(TRIM(r.randevu_durumu),''),'-'), '] - ', $r_sorumlu, ' - ', $r_not) ORDER BY r.randevu_tarihi ASC SEPARATOR ' || ')
                    FROM gorusme_randevulari r
                    WHERE r.tel_temiz = gl.tel_temiz) AS randevu_ozet";
}

$sql = "SELECT
            gl.id,
            gl.ogrenci_ad, gl.ogrenci_soyad,
            gl.veli_ad, gl.veli_soyad,
            gl.tel_temiz, gl.tel_orijinal,
            gl.sinif,
            gl.sinav_turu,
            gl.sinav_sonuc_id_ingilizce, gl.sinav_sonuc_id_almanca,
            gl.basari_yuzdesi_ingilizce, gl.basari_yuzdesi_almanca,
            gl.gorusme_durumu,
            gl.personel AS son_islem_personel,
            (SELECT GROUP_CONCAT(CONCAT(IFNULL(NULLIF(TRIM(n.baslik),''),'Not'), ': ', IFNULL(NULLIF(TRIM(n.icerik),''), '-')) ORDER BY n.sira ASC, n.id ASC SEPARATOR ' || ')
             FROM gorusme_notlari n
             WHERE n.gorusme_listesi_id = gl.id) AS gorusme_notu_ozet,
            $randevu_sub
        FROM gorusme_listesi gl
        WHERE $where_sql
        ORDER BY gl.sinif " . ($f_sinif_sirala === 'desc' ? "DESC" : "ASC") . ", gl.ogrenci_ad ASC, gl.ogrenci_soyad ASC";

$res = mysqli_query($conn, $sql);
$rows = [];
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $id = (int)$r['id'];
    $turlar = [];
    if ((int)($r['sinav_sonuc_id_ingilizce'] ?? 0) > 0 || $r['basari_yuzdesi_ingilizce'] !== null) $turlar[] = 'İngilizce';
    if ((int)($r['sinav_sonuc_id_almanca'] ?? 0) > 0 || $r['basari_yuzdesi_almanca'] !== null) $turlar[] = 'Almanca';
    if (empty($turlar) && trim((string)($r['sinav_turu'] ?? '')) !== '') $turlar[] = trim((string)$r['sinav_turu']);
    $r['sinav_turleri_ozet'] = !empty($turlar) ? implode(', ', array_unique($turlar)) : '-';
    if (isset($sira_by_id[$id], $toplam_by_id[$id])) {
        $r['sinav_sira_no'] = (int)$sira_by_id[$id];
        $r['sinav_sira_toplam * 2'] = (int)$toplam_by_id[$id] * 2;
        $r['sinav_sirasi'] = $r['sinav_sira_no'] . ". sıra (" . $r['sinav_sira_toplam * 2'] . ")";
    } else {
        $r['sinav_sira_no'] = 999999;
        $r['sinav_sira_toplam * 2'] = 0;
        $r['sinav_sirasi'] = '-';
    }
    $rows[] = $r;
}

if ($f_sira_sirala !== '') {
    usort($rows, function($a, $b) use ($f_sira_sirala) {
        $aVal = (int)($a['sinav_sira_no'] ?? 999999);
        $bVal = (int)($b['sinav_sira_no'] ?? 999999);
        if ($aVal === $bVal) return 0;
        if ($f_sira_sirala === 'asc') return $aVal <=> $bVal;
        return $bVal <=> $aVal;
    });
}

// Excel (CSV) export
if ($f_export) {
    $filename = "gorusmeler_ogrenci_detay_" . date('Ymd_His') . ".xls";
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    ?>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Calibri, Arial, sans-serif; }
            .title { font-size: 16px; font-weight: bold; color: #0f766e; margin-bottom: 6px; }
            .meta { font-size: 11px; color: #555; margin-bottom: 10px; }
            table { border-collapse: collapse; width: 100%; font-size: 11px; }
            th, td { border: 1px solid #d0d7de; padding: 6px; vertical-align: top; }
            th { background: #0f766e; color: #fff; font-weight: 700; text-align: left; }
            tr:nth-child(even) td { background: #f8fafc; }
            .wrap { white-space: normal; }
            .narrow { white-space: nowrap; }
            .as-text { mso-number-format:"\@"; }
        </style>
    </head>
    <body>
    <div class="title">Görüşmeler Öğrenci Detay Listesi</div>
    <div class="meta">Tarih: <?= htmlspecialchars(date('d.m.Y H:i')) ?> | Personel: <?= htmlspecialchars($aktif_personel) ?></div>
    <table>
        <thead>
            <tr>
                <th class="narrow">#</th>
                <th>Öğrenci Ad Soyad</th>
                <th>Veli Ad Soyad</th>
                <th class="narrow">Veli Tel</th>
                <th class="narrow">Sınıf</th>
                <th class="narrow">Sınav Sıralaması</th>
                <th class="narrow">Sınav Türleri</th>
                <th class="narrow">Görüşme Durumu</th>
                <th class="wrap">Görüşme Notları</th>
                <th class="wrap">Randevu / Randevu Notları</th>
                <th class="narrow">Son İşlem Personeli</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td class="narrow"><?= $i + 1 ?></td>
                <td><?= htmlspecialchars(trim(($r['ogrenci_ad'] ?? '') . ' ' . ($r['ogrenci_soyad'] ?? ''))) ?></td>
                <td><?= htmlspecialchars(trim(($r['veli_ad'] ?? '') . ' ' . ($r['veli_soyad'] ?? ''))) ?></td>
                <td class="narrow"><?= htmlspecialchars((string)($r['tel_orijinal'] ?: $r['tel_temiz'])) ?></td>
                <td class="narrow"><?= (int)($r['sinif'] ?? 0) ?></td>
                <td class="narrow as-text"><?= htmlspecialchars((string)($r['sinav_sirasi'] ?? '-')) ?></td>
                <td class="narrow"><?= htmlspecialchars((string)($r['sinav_turleri_ozet'] ?? '-')) ?></td>
                <td class="narrow"><?= htmlspecialchars((string)($r['gorusme_durumu'] ?? '-')) ?></td>
                <td class="wrap"><?= nl2br(htmlspecialchars((string)($r['gorusme_notu_ozet'] ?? '-'))) ?></td>
                <td class="wrap"><?= nl2br(htmlspecialchars((string)($r['randevu_ozet'] ?? '-'))) ?></td>
                <td class="narrow"><?= htmlspecialchars((string)($r['son_islem_personel'] ?? '-')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </body>
    </html>
    <?php
    exit;
}

$sinif_opts = [];
$s_res = mysqli_query($conn, "SELECT DISTINCT sinif FROM gorusme_listesi WHERE sinif IS NOT NULL AND sinif > 0 ORDER BY sinif ASC");
while ($s_res && ($sr = mysqli_fetch_assoc($s_res))) {
    $sinif_opts[] = (int)$sr['sinif'];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Görüşmeler Öğrenci Detay Listesi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background:#f3f4f6; font-family: 'Segoe UI', Tahoma, sans-serif; }
        .topbar { background:#0f766e; color:#fff; padding:12px 18px; display:flex; justify-content:space-between; align-items:center; gap:12px; }
        .topbar a { color:#fff; text-decoration:none; }
        .wrap { max-width: 98vw; margin: 12px auto; padding: 0 10px; }
        .cardx { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.05); }
        .table-wrap { overflow:auto; max-height: calc(100vh - 210px); }
        table { font-size: 12px; white-space: nowrap; }
        td.wraptxt { white-space: normal; min-width: 320px; max-width: 600px; }
    </style>
</head>
<body>
    <div class="topbar">
        <div><i class="bi bi-table me-2"></i><strong>Öğrenci Detay Tablosu</strong> <span class="opacity-75 ms-2">| <?= htmlspecialchars($aktif_personel) ?></span></div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-light" href="gorusmeler.php"><i class="bi bi-arrow-left"></i> Görüşmelere Dön</a>
            <a class="btn btn-sm btn-outline-light" href="index.php"><i class="bi bi-grid"></i> Panel</a>
        </div>
    </div>

    <div class="wrap">
        <div class="cardx p-3 mb-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Sınıf Filtre</label>
                    <select name="sinif" class="form-select form-select-sm">
                        <option value="tum" <?= ($f_sinif === '' || $f_sinif === 'tum') ? 'selected' : '' ?>>Tüm Sınıflar</option>
                        <?php foreach ($sinif_opts as $sn): ?>
                        <option value="<?= (int)$sn ?>" <?= $f_sinif === (string)$sn ? 'selected' : '' ?>><?= (int)$sn ?>. Sınıf</option>
                        <?php endforeach; ?>
                        <option value="Lise" <?= $f_sinif === 'Lise' ? 'selected' : '' ?>>Lise</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Sınıf Sırası</label>
                    <select name="sinif_sirala" class="form-select form-select-sm">
                        <option value="asc" <?= $f_sinif_sirala === 'asc' ? 'selected' : '' ?>>Artan</option>
                        <option value="desc" <?= $f_sinif_sirala === 'desc' ? 'selected' : '' ?>>Azalan</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Sınav Sırası</label>
                    <select name="sira_sirala" class="form-select form-select-sm">
                        <option value="" <?= $f_sira_sirala === '' ? 'selected' : '' ?>>Varsayılan</option>
                        <option value="asc" <?= $f_sira_sirala === 'asc' ? 'selected' : '' ?>>En iyi (1→)</option>
                        <option value="desc" <?= $f_sira_sirala === 'desc' ? 'selected' : '' ?>>En düşük</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Arama</label>
                    <input type="text" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($f_q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Öğrenci, veli, telefon, durum...">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Görüşme Durumu</label>
                    <select name="durum" class="form-select form-select-sm">
                        <option value="" <?= $f_durum === '' ? 'selected' : '' ?>>Tümü</option>
                        <option value="Bekliyor" <?= $f_durum === 'Bekliyor' ? 'selected' : '' ?>>Bekliyor</option>
                        <option value="Sonuc Iletildi" <?= $f_durum === 'Sonuc Iletildi' ? 'selected' : '' ?>>Sonuç İletildi</option>
                        <option value="Randevu Alindi" <?= $f_durum === 'Randevu Alindi' ? 'selected' : '' ?>>Randevu Alındı</option>
                        <option value="Gorusuldu (Yuz Yuze)" <?= $f_durum === 'Gorusuldu (Yuz Yuze)' ? 'selected' : '' ?>>Görüşüldü (Yüz Yüze)</option>
                        <option value="Gorusuldu (Telefon)" <?= $f_durum === 'Gorusuldu (Telefon)' ? 'selected' : '' ?>>Görüşüldü (Telefon)</option>
                        <option value="Sonuc Icin Ulasilamadi" <?= $f_durum === 'Sonuc Icin Ulasilamadi' ? 'selected' : '' ?>>Sonuç İçin Ulaşılamadı</option>
                        <option value="Gorusme Icin Ulasilamadi" <?= $f_durum === 'Gorusme Icin Ulasilamadi' ? 'selected' : '' ?>>Görüşme İçin Ulaşılamadı</option>
                        <option value="Ertelendi" <?= $f_durum === 'Ertelendi' ? 'selected' : '' ?>>Ertelendi</option>
                        <option value="WhatsappDonusYapmadi" <?= $f_durum === 'WhatsappDonusYapmadi' ? 'selected' : '' ?>>WhatsApp Dönüş Yok</option>
                        <option value="KayitOldu" <?= $f_durum === 'KayitOldu' ? 'selected' : '' ?>>Kayıt Oldu</option>
                        <option value="KayitOlmakIstemiyor" <?= $f_durum === 'KayitOlmakIstemiyor' ? 'selected' : '' ?>>Kayıt Olmak İstemiyor</option>
                    </select>
                </div>
                <?php if ($randevulari_var): ?>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Randevu</label>
                    <select name="randevu" class="form-select form-select-sm">
                        <option value="tum" <?= $f_randevu === 'tum' ? 'selected' : '' ?>>Tümü</option>
                        <option value="var" <?= $f_randevu === 'var' ? 'selected' : '' ?>>Var</option>
                        <option value="yok" <?= $f_randevu === 'yok' ? 'selected' : '' ?>>Yok</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Randevu Günü</label>
                    <input type="date" name="r_gun" class="form-control form-control-sm" value="<?= htmlspecialchars($f_r_gun, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Randevu Durumu</label>
                    <select name="r_durum" class="form-select form-select-sm">
                        <option value="" <?= $f_r_durum === '' ? 'selected' : '' ?>>Tümü</option>
                        <option value="Bekleniyor" <?= $f_r_durum === 'Bekleniyor' ? 'selected' : '' ?>>Bekleniyor</option>
                        <option value="Geldi" <?= $f_r_durum === 'Geldi' ? 'selected' : '' ?>>Geldi</option>
                        <option value="Gelmedi" <?= $f_r_durum === 'Gelmedi' ? 'selected' : '' ?>>Gelmedi</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Zaman</label>
                    <select name="r_zaman" class="form-select form-select-sm">
                        <option value="tum" <?= $f_r_zaman === 'tum' ? 'selected' : '' ?>>Tümü</option>
                        <option value="yaklasan" <?= $f_r_zaman === 'yaklasan' ? 'selected' : '' ?>>Yaklaşan</option>
                        <option value="gecmis" <?= $f_r_zaman === 'gecmis' ? 'selected' : '' ?>>Geçmiş</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-3 ms-md-auto d-flex gap-2 justify-content-md-end align-self-end">
                    <button class="btn btn-sm btn-primary flex-fill" type="submit"><i class="bi bi-funnel"></i> Uygula</button>
                    <?php
                    $export_qs = $_GET;
                    $export_qs['export'] = '1';
                    ?>
                    <a class="btn btn-sm btn-success flex-fill" href="?<?= htmlspecialchars(http_build_query($export_qs), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                </div>
            </form>
        </div>

        <div class="cardx table-wrap">
            <table class="table table-sm table-striped table-bordered align-middle mb-0">
                <thead class="table-light position-sticky top-0" style="z-index:1;">
                    <tr>
                        <th>#</th>
                        <th>Öğrenci Ad Soyad</th>
                        <th>Veli Ad Soyad</th>
                        <th>Veli Tel</th>
                        <th>Sınıf</th>
                        <th>Sınav Sıralaması</th>
                        <th>Sınav Türleri</th>
                        <th>Görüşme Durumu</th>
                        <th class="wraptxt">Görüşme Notları</th>
                        <th class="wraptxt">Randevu / Randevu Notları</th>
                <th>Son İşlem Personeli</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($rows)): foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars(trim(($r['ogrenci_ad'] ?? '') . ' ' . ($r['ogrenci_soyad'] ?? ''))) ?></td>
                        <td><?= htmlspecialchars(trim(($r['veli_ad'] ?? '') . ' ' . ($r['veli_soyad'] ?? ''))) ?></td>
                        <td><?= htmlspecialchars((string)($r['tel_orijinal'] ?: $r['tel_temiz'])) ?></td>
                        <td><?= (int)($r['sinif'] ?? 0) ?></td>
                        <td><?= htmlspecialchars((string)($r['sinav_sirasi'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string)($r['sinav_turleri_ozet'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string)($r['gorusme_durumu'] ?? '-')) ?></td>
                        <td class="wraptxt"><?= htmlspecialchars((string)($r['gorusme_notu_ozet'] ?? '-')) ?></td>
                        <td class="wraptxt"><?= htmlspecialchars((string)($r['randevu_ozet'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string)($r['son_islem_personel'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">Kayıt bulunamadı.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

