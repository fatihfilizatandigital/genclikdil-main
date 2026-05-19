<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';

mysqli_set_charset($conn, "utf8mb4");

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$flash_ok = '';
$flash_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : 0;

    $veli_ad_soyad = trim((string)($_POST['veli_ad_soyad'] ?? ''));
    $ogrenci_ad_soyad = trim((string)($_POST['ogrenci_ad_soyad'] ?? ''));
    $veli_tel1 = preg_replace('/\D/', '', (string)($_POST['veli_tel1'] ?? ''));
    $veli_tel1 = strlen($veli_tel1) > 12 ? substr($veli_tel1, 0, 12) : $veli_tel1;
    $veli_tel2 = preg_replace('/\D/', '', (string)($_POST['veli_tel2'] ?? ''));
    $veli_tel2 = strlen($veli_tel2) > 12 ? substr($veli_tel2, 0, 12) : $veli_tel2;
    $tc = preg_replace('/\D/', '', (string)($_POST['tc'] ?? ''));
    $tc = strlen($tc) > 11 ? substr($tc, 0, 11) : $tc;
    $dogum_tarihi_raw = trim((string)($_POST['dogum_tarihi'] ?? ''));
    $cinsiyet = trim((string)($_POST['cinsiyet'] ?? ''));
    $okul = trim((string)($_POST['okul'] ?? ''));
    $sinif = trim((string)($_POST['sinif'] ?? ''));

    $toNullable = static function (string $s) {
        $s = trim($s);
        return $s === '' ? null : $s;
    };

    $dogum_tarihi = null;
    if ($dogum_tarihi_raw !== '') {
        // input[type=date] => YYYY-MM-DD
        $dt = DateTime::createFromFormat('Y-m-d', $dogum_tarihi_raw);
        if ($dt !== false) {
            $dogum_tarihi = $dt->format('Y-m-d');
        }
    }

    $veli_ad_soyad = $toNullable($veli_ad_soyad);
    $ogrenci_ad_soyad = $toNullable($ogrenci_ad_soyad);
    $veli_tel1 = $toNullable($veli_tel1);
    $veli_tel2 = $toNullable($veli_tel2);
    $tc = $toNullable($tc);
    $cinsiyet = $toNullable($cinsiyet);
    $okul = $toNullable($okul);
    $sinif = $toNullable($sinif);

    $required_ok = ($veli_ad_soyad !== null && $ogrenci_ad_soyad !== null && $veli_tel1 !== null
        && $tc !== null && $dogum_tarihi !== null && $cinsiyet !== null && $okul !== null && $sinif !== null);
    if (!$required_ok) {
        $flash_err = 'Lütfen zorunlu tüm alanları doldurun. (Veli Tel 2 hariç)';
    }

    if ($required_ok && $id > 0) {
        $sql = "UPDATE yaz_kampanya_basvurular
                SET veli_ad_soyad=?,
                    ogrenci_ad_soyad=?,
                    veli_tel1=?,
                    veli_tel2=?,
                    tc=?,
                    dogum_tarihi=?,
                    cinsiyet=?,
                    okul=?,
                    sinif=?
                WHERE id=?
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'sssssssssi',
                $veli_ad_soyad,
                $ogrenci_ad_soyad,
                $veli_tel1,
                $veli_tel2,
                $tc,
                $dogum_tarihi,
                $cinsiyet,
                $okul,
                $sinif,
                $id
            );
            if (mysqli_stmt_execute($stmt)) {
                $flash_ok = 'Başvuru güncellendi.';
            } else {
                $flash_err = 'Güncelleme hatası: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } else {
            $flash_err = 'Hazırlama hatası: ' . mysqli_error($conn);
        }
    }
    if ($required_ok && $id <= 0) {
        $sql = "INSERT INTO yaz_kampanya_basvurular
                (veli_ad_soyad, ogrenci_ad_soyad, veli_tel1, veli_tel2, tc, dogum_tarihi, cinsiyet, okul, sinif)
                VALUES (?,?,?,?,?,?,?,?,?)";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'sssssssss',
                $veli_ad_soyad,
                $ogrenci_ad_soyad,
                $veli_tel1,
                $veli_tel2,
                $tc,
                $dogum_tarihi,
                $cinsiyet,
                $okul,
                $sinif
            );
            if (mysqli_stmt_execute($stmt)) {
                $flash_ok = 'Başvuru eklendi.';
            } else {
                $flash_err = 'Ekleme hatası: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } else {
            $flash_err = 'Hazırlama hatası: ' . mysqli_error($conn);
        }
    }
}

// Filters (GET)
$q_veli = trim((string)($_GET['veli'] ?? ''));
$q_ogr = trim((string)($_GET['ogrenci'] ?? ''));
$q_tc = preg_replace('/\s+/', '', trim((string)($_GET['tc'] ?? '')));
$q_tel = preg_replace('/\s+/', '', trim((string)($_GET['tel'] ?? '')));

$where = [];
if ($q_veli !== '') {
    $v = mysqli_real_escape_string($conn, $q_veli);
    $where[] = "veli_ad_soyad LIKE '%$v%'";
}
if ($q_ogr !== '') {
    $o = mysqli_real_escape_string($conn, $q_ogr);
    $where[] = "ogrenci_ad_soyad LIKE '%$o%'";
}
if ($q_tc !== '') {
    $tcEsc = mysqli_real_escape_string($conn, $q_tc);
    $where[] = "REPLACE(REPLACE(tc,' ',''),'-','') LIKE '%$tcEsc%'";
}
if ($q_tel !== '') {
    $telEsc = mysqli_real_escape_string($conn, $q_tel);
    $where[] = "(REPLACE(REPLACE(veli_tel1,' ',''),'-','') LIKE '%$telEsc%' OR REPLACE(REPLACE(veli_tel2,' ',''),'-','') LIKE '%$telEsc%')";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";
$limit = 51;
$offset = 0;
$sqlList = "SELECT id, veli_ad_soyad, ogrenci_ad_soyad, veli_tel1, veli_tel2, tc, dogum_tarihi, cinsiyet, okul, sinif, created_at, updated_at
            FROM yaz_kampanya_basvurular
            $whereSql
            ORDER BY id DESC
            LIMIT $limit OFFSET $offset";

$rows = [];
$result = mysqli_query($conn, $sqlList);
if ($result) {
    while ($r = mysqli_fetch_assoc($result)) $rows[] = $r;
} else {
    $flash_err = $flash_err ?: ('Listeleme hatası: ' . mysqli_error($conn));
}
$has_more = count($rows) > 50;
$rows = array_slice($rows, 0, 50);
$next_offset = 50;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>Yaz Dönemi Başvuruları - Yönetim</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="../resimler/logoGenclik.jpg">
    <link rel="stylesheet" type="text/css" href="../css/bootstrap.min.css"/>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Montserrat:400,700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Montserrat', sans-serif; }
        .panel-head { padding: 20px; background: #fff; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .card-box { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .filter-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-item { flex: 1; min-width: 180px; }
        .filter-item label { font-weight: bold; font-size: 12px; color: #7f8c8d; margin-bottom: 5px; display: block; }
        .table-container { overflow-x: auto; }
        table { font-size: 13px; }
        thead { background-color: #34495e; color: white; }
        th { font-weight: 500; white-space: nowrap; padding: 12px !important; }
        td { vertical-align: middle !important; padding: 10px !important; }
        .btn-outline-primary { border-color: #4361ee; color: #4361ee; }
        .btn-outline-primary:hover { background: #4361ee; border-color: #4361ee; color: #fff; }
        .badge-soft { background: rgba(52,73,94,0.08); color: #34495e; border-radius: 999px; padding: 4px 10px; font-weight: 600; }
        .yaz-cinsiyet-btn.active { background: #4361ee; border-color: #4361ee; color: #fff; }
    </style>
</head>
<body>
    <div class="panel-head">
        <h4 style="margin:0;"><i class="fas fa-sun"></i> 2026/2027 Yaz Dönemi Başvuruları</h4>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn btn-success" type="button" data-toggle="modal" data-target="#basvuruModal" onclick="openNew()">
                <i class="fas fa-plus"></i> Yeni Başvuru
            </button>
            <a href="yaz_kampanyasi.php" class="btn btn-default"><i class="fas fa-arrow-left"></i> Geri</a>
            <a href="index.php" class="btn btn-default"><i class="fas fa-home"></i> Panele Dön</a>
        </div>
    </div>

    <div class="container-fluid" style="padding: 20px;">
        <?php if ($flash_ok): ?>
            <div class="alert alert-success"><?php echo h($flash_ok); ?></div>
        <?php endif; ?>
        <?php if ($flash_err): ?>
            <div class="alert alert-danger"><?php echo h($flash_err); ?></div>
        <?php endif; ?>

        <div class="card-box">
            <h5 class="text-primary" style="margin-bottom: 15px;"><i class="fas fa-filter"></i> Arama Kriterleri</h5>
            <form method="get" class="filter-row">
                <div class="filter-item">
                    <label>VELİ AD SOYAD</label>
                    <input type="text" name="veli" class="form-control" placeholder="Veli..." value="<?php echo h($q_veli); ?>">
                </div>
                <div class="filter-item">
                    <label>ÖĞRENCİ AD SOYAD</label>
                    <input type="text" name="ogrenci" class="form-control" placeholder="Öğrenci..." value="<?php echo h($q_ogr); ?>">
                </div>
                <div class="filter-item">
                    <label>TC</label>
                    <input type="text" name="tc" class="form-control" placeholder="TC..." value="<?php echo h($q_tc); ?>">
                </div>
                <div class="filter-item">
                    <label>TELEFON</label>
                    <input type="text" name="tel" class="form-control" placeholder="Telefon..." value="<?php echo h($q_tel); ?>">
                </div>
                <div class="filter-item" style="flex: 0 0 auto; min-width: 140px;">
                    <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-search"></i> LİSTELE</button>
                </div>
                <div class="filter-item" style="flex: 0 0 auto; min-width: 140px;">
                    <a class="btn btn-light btn-block" href="yaz_basvurular.php"><i class="fas fa-undo"></i> SIFIRLA</a>
                </div>
            </form>
        </div>

        <div class="card-box">
            <div class="table-container">
                <table class="table table-striped table-hover table-bordered">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th style="text-align:center; min-width:120px;">İŞLEMLER</th>
                            <th>Veli</th>
                            <th>Öğrenci</th>
                            <th>Veli Tel 1</th>
                            <th>Veli Tel 2</th>
                            <th>TC</th>
                            <th>Doğum Tarihi</th>
                            <th>Cinsiyet</th>
                            <th>Okul</th>
                            <th>Sınıfı</th>
                            <th>Kayıt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="12" class="text-center" style="padding:20px;">Kayıt bulunamadı.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $dogum = '';
                                    if (!empty($r['dogum_tarihi'])) {
                                        $ts = strtotime($r['dogum_tarihi']);
                                        if ($ts) $dogum = date('d.m.Y', $ts);
                                    }
                                    $kayit = '';
                                    if (!empty($r['created_at'])) {
                                        $ts = strtotime($r['created_at']);
                                        if ($ts) $kayit = date('d.m.Y H:i', $ts);
                                    }
                                ?>
                                <tr>
                                    <td><?php echo (int)$r['id']; ?></td>
                                    <td style="text-align:center;">
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" data-toggle="modal" data-target="#basvuruModal" onclick="openEdit(this)"
                                                data-id="<?php echo (int)$r['id']; ?>"
                                                data-veli_ad_soyad="<?php echo h($r['veli_ad_soyad'] ?? ''); ?>"
                                                data-ogrenci_ad_soyad="<?php echo h($r['ogrenci_ad_soyad'] ?? ''); ?>"
                                                data-veli_tel1="<?php echo h($r['veli_tel1'] ?? ''); ?>"
                                                data-veli_tel2="<?php echo h($r['veli_tel2'] ?? ''); ?>"
                                                data-tc="<?php echo h($r['tc'] ?? ''); ?>"
                                                data-dogum_tarihi="<?php echo h($r['dogum_tarihi'] ?? ''); ?>"
                                                data-cinsiyet="<?php echo h($r['cinsiyet'] ?? ''); ?>"
                                                data-okul="<?php echo h($r['okul'] ?? ''); ?>"
                                                data-sinif="<?php echo h($r['sinif'] ?? ''); ?>"
                                                title="Düzenle"><i class="fas fa-edit"></i> Düzenle</button>
                                            <button type="button" class="btn btn-outline-danger yaz-basvuru-sil-btn" data-id="<?php echo (int)$r['id']; ?>" title="Sil"><i class="fas fa-trash-alt"></i> Sil</button>
                                        </div>
                                    </td>
                                    <td><strong><?php echo h($r['veli_ad_soyad'] ?? ''); ?></strong></td>
                                    <td><strong><?php echo h($r['ogrenci_ad_soyad'] ?? ''); ?></strong></td>
                                    <td><?php echo h($r['veli_tel1'] ?? ''); ?></td>
                                    <td><?php echo h($r['veli_tel2'] ?? ''); ?></td>
                                    <td><?php echo h($r['tc'] ?? ''); ?></td>
                                    <td><?php echo h($dogum); ?></td>
                                    <td><?php echo h($r['cinsiyet'] ?? ''); ?></td>
                                    <td><?php echo h($r['okul'] ?? ''); ?></td>
                                    <td style="text-align:center;"><span class="badge-soft"><?php echo h($r['sinif'] ?? ''); ?></span></td>
                                    <td><?php echo h($kayit); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!empty($rows)): ?>
                                <tr id="yaz-basvuru-sentinel" class="yaz-basvuru-sentinel" data-has-more="<?php echo $has_more ? '1' : '0'; ?>" data-next-offset="<?php echo (int)$next_offset; ?>">
                                    <td colspan="12" class="text-center" style="padding:12px; color:#6b7280; font-size:13px;">
                                        <?php if ($has_more): ?>
                                            <span class="yaz-sentinel-text">Aşağı kaydırarak daha fazla yükleyin</span>
                                        <?php else: ?>
                                            Tüm kayıtlar listelendi.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="yaz-basvuru-paging" style="display:none;"
                 data-has-more="<?php echo $has_more ? '1' : '0'; ?>"
                 data-next-offset="<?php echo (int)$next_offset; ?>"
                 data-veli="<?php echo h($q_veli); ?>"
                 data-ogrenci="<?php echo h($q_ogr); ?>"
                 data-tc="<?php echo h($q_tc); ?>"
                 data-tel="<?php echo h($q_tel); ?>"></div>
            <div style="color:#6b7280;font-size:12px;">
                İlk 50 kayıt gösteriliyor; aşağı kaydırdıkça 50'şer yüklenir.
            </div>
        </div>
    </div>

    <!-- Ekle/Düzenle Modal -->
    <div class="modal fade" id="basvuruModal" tabindex="-1" role="dialog" aria-labelledby="basvuruModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="basvuruModalLabel"><i class="fas fa-edit"></i> Başvuru</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Kapat"><span aria-hidden="true">&times;</span></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="f_id" value="">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Veli Ad Soyad <span class="text-danger">*</span></label>
                                    <input type="text" name="veli_ad_soyad" id="f_veli_ad_soyad" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Öğrenci Ad Soyad <span class="text-danger">*</span></label>
                                    <input type="text" name="ogrenci_ad_soyad" id="f_ogrenci_ad_soyad" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Veli Tel 1 <span class="text-danger">*</span></label>
                                    <input type="text" name="veli_tel1" id="f_veli_tel1" class="form-control yaz-tel-input" placeholder="0 5xx xxx xx xx" maxlength="15" autocomplete="off" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Veli Tel 2</label>
                                    <input type="text" name="veli_tel2" id="f_veli_tel2" class="form-control yaz-tel-input" placeholder="0 5xx xxx xx xx" maxlength="15" autocomplete="off">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>TC Kimlik No <span class="text-danger">*</span></label>
                                    <input type="text" name="tc" id="f_tc" class="form-control yaz-tc-input" placeholder="11 hane" maxlength="11" pattern="[0-9]*" inputmode="numeric" autocomplete="off" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Doğum Tarihi <span class="text-danger">*</span></label>
                                    <input type="date" name="dogum_tarihi" id="f_dogum_tarihi" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Cinsiyet <span class="text-danger">*</span></label>
                                    <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
                                        <label class="btn btn-outline-secondary yaz-cinsiyet-btn">
                                            <input type="radio" name="cinsiyet" value="Kız" id="f_cinsiyet_kiz" required> Kız
                                        </label>
                                        <label class="btn btn-outline-secondary yaz-cinsiyet-btn">
                                            <input type="radio" name="cinsiyet" value="Erkek" id="f_cinsiyet_erkek"> Erkek
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label>Okuduğu Okul <span class="text-danger">*</span></label>
                                    <input type="text" name="okul" id="f_okul" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Sınıfı <span class="text-danger">*</span></label>
                                    <select name="sinif" id="f_sinif" class="form-control" required>
                                        <option value="">Seçiniz</option>
                                        <?php for ($i = 1; $i <= 12; $i++) echo '<option value="'.$i.'">'.$i.'. Sınıf</option>'; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.2.0.min.js"></script>
    <script src="../js/bootstrap.min.js"></script>
    <script>
        function formatTelDisplay(val) {
            if (!val) return '';
            var d = (val + '').replace(/\D/g, '').slice(0, 12);
            if (d.charAt(0) !== '0' && d.length > 0) d = '0' + d.slice(0, 11);
            if (d.length <= 1) return d;
            if (d.length <= 4) return d.slice(0,1) + ' ' + d.slice(1);
            if (d.length <= 7) return d.slice(0,1) + ' ' + d.slice(1,4) + ' ' + d.slice(4);
            if (d.length <= 9) return d.slice(0,1) + ' ' + d.slice(1,4) + ' ' + d.slice(4,7) + ' ' + d.slice(7);
            if (d.length <= 12) return d.slice(0,1) + ' ' + d.slice(1,4) + ' ' + d.slice(4,7) + ' ' + d.slice(7,9) + ' ' + d.slice(9,12);
            return d.slice(0,1) + ' ' + d.slice(1,4) + ' ' + d.slice(4,7) + ' ' + d.slice(7,9) + ' ' + d.slice(9,12);
        }
        function formatTelInput(el) {
            var d = (el.value || '').replace(/\D/g, '').slice(0, 12);
            if (d.length > 0 && d.charAt(0) !== '5' && d.charAt(0) !== '0') d = '0' + d.slice(0, 11);
            if (d.length > 0 && d.charAt(0) !== '0') d = '0' + d.slice(0, 11);
            el.value = formatTelDisplay(d);
        }
        function openNew() {
            document.getElementById('basvuruModalLabel').innerHTML = '<i class="fas fa-plus"></i> Yeni Başvuru';
            document.getElementById('f_id').value = '';
            document.getElementById('f_veli_ad_soyad').value = '';
            document.getElementById('f_ogrenci_ad_soyad').value = '';
            document.getElementById('f_veli_tel1').value = '';
            document.getElementById('f_veli_tel2').value = '';
            document.getElementById('f_tc').value = '';
            document.getElementById('f_dogum_tarihi').value = '';
            document.getElementById('f_okul').value = '';
            document.getElementById('f_sinif').value = '';
            var r = document.querySelectorAll('input[name="cinsiyet"]');
            for (var i = 0; i < r.length; i++) { r[i].checked = false; }
            document.querySelectorAll('.yaz-cinsiyet-btn').forEach(function(l) { l.classList.remove('active'); });
        }
        function openEdit(btn) {
            document.getElementById('basvuruModalLabel').innerHTML = '<i class="fas fa-edit"></i> Başvuru Düzenle';
            document.getElementById('f_id').value = btn.getAttribute('data-id') || '';
            document.getElementById('f_veli_ad_soyad').value = btn.getAttribute('data-veli_ad_soyad') || '';
            document.getElementById('f_ogrenci_ad_soyad').value = btn.getAttribute('data-ogrenci_ad_soyad') || '';
            document.getElementById('f_veli_tel1').value = formatTelDisplay(btn.getAttribute('data-veli_tel1') || '');
            document.getElementById('f_veli_tel2').value = formatTelDisplay(btn.getAttribute('data-veli_tel2') || '');
            var tc = (btn.getAttribute('data-tc') || '').replace(/\D/g, '').slice(0, 11);
            document.getElementById('f_tc').value = tc;
            document.getElementById('f_dogum_tarihi').value = btn.getAttribute('data-dogum_tarihi') || '';
            document.getElementById('f_okul').value = btn.getAttribute('data-okul') || '';
            document.getElementById('f_sinif').value = btn.getAttribute('data-sinif') || '';
            var c = (btn.getAttribute('data-cinsiyet') || '').trim();
            var r = document.querySelectorAll('input[name="cinsiyet"]');
            document.querySelectorAll('.yaz-cinsiyet-btn').forEach(function(l) { l.classList.remove('active'); });
            for (var i = 0; i < r.length; i++) {
                r[i].checked = (r[i].value === c);
                if (r[i].checked) r[i].closest('label').classList.add('active');
            }
        }
        $(function() {
            $(document).on('input', '.yaz-tel-input', function() { formatTelInput(this); });
            $(document).on('input', '.yaz-tc-input', function() {
                this.value = (this.value || '').replace(/\D/g, '').slice(0, 11);
            });
            $(document).on('change', 'input[name="cinsiyet"]', function() {
                document.querySelectorAll('.yaz-cinsiyet-btn').forEach(function(l) { l.classList.remove('active'); });
                if (this.checked) this.closest('label').classList.add('active');
            });
            $('#basvuruModal').on('submit', 'form', function() {
                var t1 = document.getElementById('f_veli_tel1');
                var t2 = document.getElementById('f_veli_tel2');
                if (t1) t1.value = (t1.value || '').replace(/\D/g, '').slice(0, 12);
                if (t2) t2.value = (t2.value || '').replace(/\D/g, '').slice(0, 12);
            });
            $(document).on('click', '.yaz-basvuru-sil-btn', function() {
                if (!confirm('Bu başvuruyu silmek istediğinize emin misiniz?')) return;
                var id = $(this).data('id');
                var $tr = $(this).closest('tr');
                if (!$tr.length || !id) return;
                $.post('ajax/yaz_basvurular_delete.php', { id: id }, 'json').done(function(data) {
                    if (data && data.ok) $tr.fadeOut(300, function() { $(this).remove(); });
                    else alert(data && data.error ? data.error : 'Silme işlemi başarısız.');
                }).fail(function() { alert('Silme isteği gönderilemedi.'); });
            });
        });

        (function() {
            var PAGE_SIZE = 50;
            var loading = false;
            var $sentinel = $('#yaz-basvuru-sentinel');
            var $paging = $('#yaz-basvuru-paging');
            var $tbody = $('.table-container table tbody');

            function getParams() {
                if (!$paging.length) return {};
                return {
                    offset: parseInt($paging.attr('data-next-offset') || '0', 10),
                    veli: $paging.attr('data-veli') || '',
                    ogrenci: $paging.attr('data-ogrenci') || '',
                    tc: $paging.attr('data-tc') || '',
                    tel: $paging.attr('data-tel') || ''
                };
            }

            function setHasMore(hasMore, nextOffset) {
                $paging.attr('data-has-more', hasMore ? '1' : '0');
                $paging.attr('data-next-offset', nextOffset);
                var $td = $sentinel.find('td');
                if ($td.length) $td.find('.yaz-sentinel-text').text(hasMore ? 'Aşağı kaydırarak daha fazla yükleyin' : 'Tüm kayıtlar listelendi.');
            }

            function loadMore() {
                if (loading) return;
                if ($paging.attr('data-has-more') !== '1') return;
                var p = getParams();
                loading = true;
                $sentinel.find('td').html('<i class="fas fa-spinner fa-spin"></i> Yükleniyor...');

                $.get('ajax/yaz_basvurular_more.php', {
                    offset: p.offset,
                    veli: p.veli,
                    ogrenci: p.ogrenci,
                    tc: p.tc,
                    tel: p.tel
                }, 'json').done(function(data) {
                    if (data.rows_html) $sentinel.before(data.rows_html);
                    setHasMore(!!data.has_more, data.next_offset || 0);
                }).fail(function() {
                    $sentinel.find('td').html('<span class="text-danger">Yükleme hatası.</span>');
                    setHasMore(true, p.offset);
                }).always(function() {
                    loading = false;
                });
            }

            $(window).on('scroll', function() {
                if (!$sentinel.length || !$paging.length) return;
                if ($paging.attr('data-has-more') !== '1') return;
                var st = $(window).scrollTop();
                var wh = $(window).height();
                var sentinelTop = $sentinel.offset().top;
                if (sentinelTop - wh < st + 400) loadMore();
            });
        })();
    </script>
</body>
</html>

