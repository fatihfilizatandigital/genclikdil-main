<?php
// sorgu.php - Kullanıcı bazlı ve genel istatistik / sorgu ekranı
session_start();
ob_start();

if (!isset($_SESSION['giris_yapildi']) || $_SESSION['giris_yapildi'] !== true) {
    header("Location: giris.php");
    exit;
}

$aktif_personel = $_SESSION['personel_adi'] ?? '';
if ($aktif_personel === '') {
    header("Location: giris.php");
    exit;
}

$conn = mysqli_connect("213.142.130.21", "adnan", "adnan.1234", "farmMysql", 3306);
mysqli_set_charset($conn, "utf8mb4");
if (!$conn) {
    die("Veritabanı bağlantısı kurulamadı.");
}

$aktif_personel_db = mysqli_real_escape_string($conn, $aktif_personel);

// Tarih filtresi
$periyot = isset($_GET['periyot']) ? $_GET['periyot'] : 'gunluk';
$tarih_bas = isset($_GET['tarih_bas']) ? trim($_GET['tarih_bas']) : '';
$tarih_son = isset($_GET['tarih_son']) ? trim($_GET['tarih_son']) : '';
$filtre_personel = isset($_GET['personel']) ? trim($_GET['personel']) : '';

$where_tarih = "1=1";
$where_tarih_param = [];

if ($periyot === 'gunluk') {
    $where_tarih = "DATE(islem_tarihi) = CURDATE()";
} elseif ($periyot === 'haftalik') {
    $where_tarih = "islem_tarihi >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($periyot === 'ozel' && $tarih_bas !== '' && $tarih_son !== '') {
    $tb = mysqli_real_escape_string($conn, $tarih_bas);
    $ts = mysqli_real_escape_string($conn, $tarih_son);
    $where_tarih = "islem_tarihi >= '$tb 00:00:00' AND islem_tarihi <= '$ts 23:59:59'";
}
// 'tumu' için ek koşul yok

$where_personel = "";
if ($filtre_personel !== '') {
    $fp = mysqli_real_escape_string($conn, $filtre_personel);
    $where_personel = " AND personel = '$fp'";
}

$base_where = "WHERE islem_tarihi IS NOT NULL AND $where_tarih $where_personel";

// ---- 1. Genel özet ----
$q_toplam = "SELECT COUNT(*) AS toplam FROM cagri_listesi $base_where";
$r_toplam = mysqli_query($conn, $q_toplam);
$toplam_kayit = ($r_toplam && $row = mysqli_fetch_assoc($r_toplam)) ? (int)$row['toplam'] : 0;

$q_tekil_kisi = "SELECT COUNT(DISTINCT tel_temiz) AS tekil FROM cagri_listesi $base_where";
$r_tekil = mysqli_query($conn, $q_tekil);
$tekil_kisi = ($r_tekil && $row = mysqli_fetch_assoc($r_tekil)) ? (int)$row['tekil'] : 0;

// ---- 2. Personel bazında: kim kaç kayıt almış, kim kaç kişi aramış ----
$q_personel = "SELECT 
    personel,
    COUNT(*) AS kayit_sayisi,
    COUNT(DISTINCT tel_temiz) AS aradigi_kisi_sayisi
FROM cagri_listesi 
$base_where
AND personel IS NOT NULL AND personel <> ''
GROUP BY personel
ORDER BY kayit_sayisi DESC";
$res_personel = mysqli_query($conn, $q_personel);

// ---- 3. Durum bazında dağılım ----
$q_durum = "SELECT 
    arama_durumu,
    COUNT(*) AS adet
FROM cagri_listesi 
$base_where
GROUP BY arama_durumu
ORDER BY adet DESC";
$res_durum = mysqli_query($conn, $q_durum);

// Personel listesi (filtre dropdown)
$res_tum_personel = mysqli_query($conn, "SELECT DISTINCT personel FROM cagri_listesi WHERE personel IS NOT NULL AND personel <> '' ORDER BY personel ASC");
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sorgu / Raporlar - Bursluluk CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="text-primary">📊 Sorgu / Raporlar</h4>
        <div>
            <span class="text-secondary me-2">👤 <?= htmlspecialchars($aktif_personel) ?></span>
            <a href="aramalar.php" class="btn btn-sm btn-outline-primary me-1">Aramalara Dön</a>
            <a href="giris.php?cikis=1" class="btn btn-sm btn-outline-danger">Çıkış</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white py-2">Filtreler</div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-0">Periyot</label>
                    <select name="periyot" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="gunluk" <?= $periyot === 'gunluk' ? 'selected' : '' ?>>Bugün</option>
                        <option value="haftalik" <?= $periyot === 'haftalik' ? 'selected' : '' ?>>Son 7 gün</option>
                        <option value="tumu" <?= $periyot === 'tumu' ? 'selected' : '' ?>>Tüm zamanlar</option>
                        <option value="ozel" <?= $periyot === 'ozel' ? 'selected' : '' ?>>Özel aralık</option>
                    </select>
                </div>
                <div class="col-auto" id="ozel_tarih" style="<?= $periyot !== 'ozel' ? 'display:none;' : '' ?>">
                    <label class="form-label small mb-0">Başlangıç</label>
                    <input type="date" name="tarih_bas" class="form-control form-control-sm" value="<?= htmlspecialchars($tarih_bas) ?>">
                </div>
                <div class="col-auto" id="ozel_tarih_son" style="<?= $periyot !== 'ozel' ? 'display:none;' : '' ?>">
                    <label class="form-label small mb-0">Bitiş</label>
                    <input type="date" name="tarih_son" class="form-control form-control-sm" value="<?= htmlspecialchars($tarih_son) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Kullanıcı (isteğe bağlı)</label>
                    <select name="personel" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Tümü</option>
                        <?php if ($res_tum_personel) { while($p = mysqli_fetch_assoc($res_tum_personel)): ?>
                            <option value="<?= htmlspecialchars($p['personel']) ?>" <?= $filtre_personel === $p['personel'] ? 'selected' : '' ?>><?= htmlspecialchars($p['personel']) ?></option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Sorgula</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    $periyot_etiket = ['gunluk' => 'Bugün', 'haftalik' => 'Son 7 gün', 'tumu' => 'Tüm zamanlar', 'ozel' => 'Özel aralık'];
    $etiket = $periyot_etiket[$periyot] ?? $periyot;
    if ($periyot === 'ozel' && $tarih_bas && $tarih_son) $etiket = $tarih_bas . ' – ' . $tarih_son;
    ?>
    <p class="text-muted small">Seçili periyot: <strong><?= htmlspecialchars($etiket) ?></strong><?= $filtre_personel !== '' ? ' · Kullanıcı: ' . htmlspecialchars($filtre_personel) : '' ?></p>

    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="text-muted small">Toplam işlem (kayıt güncellemesi)</h5>
                    <h2 class="text-primary mb-0"><?= number_format($toplam_kayit) ?></h2>
                    <small class="text-muted">islem_tarihi bu aralıkta güncellenen satır</small>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="text-muted small">Aranan tekil kişi sayısı</h5>
                    <h2 class="text-success mb-0"><?= number_format($tekil_kisi) ?></h2>
                    <small class="text-muted">farklı tel_temiz (veli)</small>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="text-muted small">İşlem yapan personel sayısı</h5>
                    <h2 class="text-info mb-0"><?= $res_personel ? mysqli_num_rows($res_personel) : 0 ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white py-2">Kim kaç kayıt almış / kaç kişi aramış</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Personel</th>
                                    <th class="text-end">Kayıt sayısı</th>
                                    <th class="text-end">Aradığı kişi sayısı</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if ($res_personel && mysqli_num_rows($res_personel) > 0) {
                                    mysqli_data_seek($res_personel, 0);
                                    while ($row = mysqli_fetch_assoc($res_personel)) {
                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($row['personel']) . '</td>';
                                        echo '<td class="text-end">' . number_format($row['kayit_sayisi']) . '</td>';
                                        echo '<td class="text-end">' . number_format($row['aradigi_kisi_sayisi']) . '</td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="3" class="text-muted text-center py-3">Bu aralıkta kayıt yok.</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white py-2">Durum bazında dağılım</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Arama durumu</th>
                                    <th class="text-end">Adet</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if ($res_durum && mysqli_num_rows($res_durum) > 0) {
                                    while ($row = mysqli_fetch_assoc($res_durum)) {
                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($row['arama_durumu']) . '</td>';
                                        echo '<td class="text-end">' . number_format($row['adet']) . '</td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="2" class="text-muted text-center py-3">Kayıt yok.</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.querySelector('select[name="periyot"]').addEventListener('change', function() {
    var v = this.value;
    var el1 = document.getElementById('ozel_tarih');
    var el2 = document.getElementById('ozel_tarih_son');
    if (el1 && el2) {
        el1.style.display = v === 'ozel' ? 'block' : 'none';
        el2.style.display = v === 'ozel' ? 'block' : 'none';
    }
});
</script>
</body>
</html>
