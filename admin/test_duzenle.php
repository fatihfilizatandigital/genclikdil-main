<?php
require_once __DIR__ . '/auth.php';
if (file_exists(__DIR__ . "/../ajax/connectt.php")) include(__DIR__ . "/../ajax/connectt.php");
else die("Bağlantı hatası.");
mysqli_set_charset($conn, "utf8mb4");

$id = (int)($_GET['id'] ?? $_POST['test_id'] ?? 0);
if ($id <= 0) die("Geçersiz test.");

$test = @mysqli_fetch_assoc(@mysqli_query($conn, "SELECT * FROM tbl_testler WHERE id = $id LIMIT 1"));
if (!$test) die("Test bulunamadı.");

$mesaj = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_test_guncelle'])) {
    $baslik = trim((string)($_POST['baslik'] ?? ''));
    $dil = trim((string)($_POST['dil'] ?? 'Ingilizce'));
    $seviye = trim((string)($_POST['seviye'] ?? 'A1'));
    if ($baslik === '') $baslik = 'Test';
    if (!in_array($dil, ['Ingilizce', 'Almanca'], true)) $dil = 'Ingilizce';
    if (!in_array($seviye, ['A1','A2','B1','B2','C1','C2'], true)) $seviye = 'A1';

    mysqli_begin_transaction($conn);
    $ok = true;

    $b = mysqli_real_escape_string($conn, $baslik);
    $d = mysqli_real_escape_string($conn, $dil);
    $s = mysqli_real_escape_string($conn, $seviye);
    if (!@mysqli_query($conn, "UPDATE tbl_testler SET baslik='$b', dil='$d', seviye='$s' WHERE id = $id LIMIT 1")) $ok = false;

    $soru_ids = $_POST['soru_id'] ?? [];
    $soru_metni = $_POST['soru_metni'] ?? [];
    $secA = $_POST['secA'] ?? [];
    $secB = $_POST['secB'] ?? [];
    $secC = $_POST['secC'] ?? [];
    $secD = $_POST['secD'] ?? [];
    $dogru = $_POST['dogru'] ?? [];
    $gorsel_eski = $_POST['gorsel_eski'] ?? [];
    $gorsel_sil = $_POST['gorsel_sil'] ?? [];

    $upload_dir_abs = __DIR__ . '/uploads/test_sorular';
    $upload_dir_rel = 'uploads/test_sorular';
    if (!is_dir($upload_dir_abs)) @mkdir($upload_dir_abs, 0777, true);

    foreach ($soru_ids as $idx => $sid_raw) {
        if (!$ok) break;
        $sid = (int)$sid_raw;
        if ($sid <= 0) continue;

        $q = trim((string)($soru_metni[$idx] ?? ''));
        $a = trim((string)($secA[$idx] ?? ''));
        $b2 = trim((string)($secB[$idx] ?? ''));
        $c = trim((string)($secC[$idx] ?? ''));
        $d2 = trim((string)($secD[$idx] ?? ''));
        $dc = (int)($dogru[$idx] ?? 0);
        if ($dc < 0 || $dc > 3) $dc = 0;

        if ($q === '' || $a === '' || $b2 === '' || $c === '' || $d2 === '') {
            $ok = false;
            break;
        }

        $g_rel = trim((string)($gorsel_eski[$idx] ?? ''));
        $sil = isset($gorsel_sil[$idx]) && $gorsel_sil[$idx] === '1';
        if ($sil && $g_rel !== '') {
            $old_abs = __DIR__ . '/' . ltrim($g_rel, '/\\');
            if (is_file($old_abs)) @unlink($old_abs);
            $g_rel = '';
        }

        if (isset($_FILES['gorsel']['name'][$idx]) && ($_FILES['gorsel']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = $_FILES['gorsel']['tmp_name'][$idx];
            $name = (string)$_FILES['gorsel']['name'][$idx];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
                if ($g_rel !== '') {
                    $old_abs = __DIR__ . '/' . ltrim($g_rel, '/\\');
                    if (is_file($old_abs)) @unlink($old_abs);
                }
                $new_name = 'test_' . $id . '_' . $sid . '_' . time() . '.' . $ext;
                $dst_abs = $upload_dir_abs . '/' . $new_name;
                if (@move_uploaded_file($tmp, $dst_abs)) {
                    $g_rel = $upload_dir_rel . '/' . $new_name;
                }
            }
        }

        $qe = mysqli_real_escape_string($conn, $q);
        $ae = mysqli_real_escape_string($conn, $a);
        $be = mysqli_real_escape_string($conn, $b2);
        $ce = mysqli_real_escape_string($conn, $c);
        $de = mysqli_real_escape_string($conn, $d2);
        $ge = $g_rel !== '' ? ("'" . mysqli_real_escape_string($conn, $g_rel) . "'") : "NULL";

        $upd = "UPDATE tbl_test_sorular
                SET soru_metni='$qe', gorsel_yolu=$ge, secenek_a='$ae', secenek_b='$be', secenek_c='$ce', secenek_d='$de', dogru_cevap=$dc
                WHERE id = $sid AND test_id = $id
                LIMIT 1";
        if (!@mysqli_query($conn, $upd)) $ok = false;
    }

    if ($ok) {
        mysqli_commit($conn);
        $mesaj = '<div class="alert alert-success">Test güncellendi.</div>';
    } else {
        mysqli_rollback($conn);
        $mesaj = '<div class="alert alert-danger">Güncelleme sırasında hata oluştu.</div>';
    }
}

$test = @mysqli_fetch_assoc(@mysqli_query($conn, "SELECT * FROM tbl_testler WHERE id = $id LIMIT 1"));
$res = @mysqli_query($conn, "SELECT * FROM tbl_test_sorular WHERE test_id = $id ORDER BY soru_no ASC, id ASC");
$sorular = [];
while ($res && ($r = mysqli_fetch_assoc($res))) $sorular[] = $r;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test Düzenle</title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <style>
        body { background:#f1f5f9; font-family: Arial, sans-serif; }
        .wrap { max-width: 1100px; margin: 14px auto; padding:0 12px; }
        .cardx { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px; margin-bottom:10px; }
        .qbox { border:1px solid #dbe3ec; border-radius:8px; padding:10px; margin-bottom:10px; background:#f9fbfd; }
        .previmg { max-height:70px; border:1px solid #ddd; border-radius:6px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h4 class="m-0">Test Düzenle</h4>
        <a href="testler.php" class="btn btn-sm btn-outline-secondary">Geri</a>
    </div>
    <?= $mesaj ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="test_id" value="<?= (int)$id ?>">
        <div class="cardx">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label form-label-sm">Başlık</label>
                    <input type="text" name="baslik" class="form-control form-control-sm" required value="<?= htmlspecialchars((string)$test['baslik']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Dil</label>
                    <select name="dil" class="form-select form-select-sm">
                        <option value="Ingilizce" <?= (string)$test['dil'] === 'Ingilizce' ? 'selected' : '' ?>>İngilizce</option>
                        <option value="Almanca" <?= (string)$test['dil'] === 'Almanca' ? 'selected' : '' ?>>Almanca</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Seviye</label>
                    <select name="seviye" class="form-select form-select-sm">
                        <?php foreach (['A1','A2','B1','B2','C1','C2'] as $sv): ?>
                        <option <?= (string)$test['seviye'] === $sv ? 'selected' : '' ?>><?= $sv ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <?php foreach ($sorular as $idx => $s): ?>
        <div class="qbox">
            <input type="hidden" name="soru_id[]" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="gorsel_eski[]" value="<?= htmlspecialchars((string)($s['gorsel_yolu'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <div class="fw-bold mb-1"><?= (int)$s['soru_no'] ?>. Soru</div>
            <label class="form-label form-label-sm">Soru Metni</label>
            <textarea name="soru_metni[]" class="form-control form-control-sm mb-2" rows="2" required><?= htmlspecialchars((string)$s['soru_metni']) ?></textarea>
            <div class="row g-2 mb-2">
                <div class="col-md-6"><input type="text" name="secA[]" class="form-control form-control-sm" required value="<?= htmlspecialchars((string)$s['secenek_a']) ?>"></div>
                <div class="col-md-6"><input type="text" name="secB[]" class="form-control form-control-sm" required value="<?= htmlspecialchars((string)$s['secenek_b']) ?>"></div>
                <div class="col-md-6"><input type="text" name="secC[]" class="form-control form-control-sm" required value="<?= htmlspecialchars((string)$s['secenek_c']) ?>"></div>
                <div class="col-md-6"><input type="text" name="secD[]" class="form-control form-control-sm" required value="<?= htmlspecialchars((string)$s['secenek_d']) ?>"></div>
            </div>
            <div class="row g-2 align-items-center">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Doğru</label>
                    <select name="dogru[]" class="form-select form-select-sm">
                        <option value="0" <?= (int)$s['dogru_cevap'] === 0 ? 'selected' : '' ?>>A</option>
                        <option value="1" <?= (int)$s['dogru_cevap'] === 1 ? 'selected' : '' ?>>B</option>
                        <option value="2" <?= (int)$s['dogru_cevap'] === 2 ? 'selected' : '' ?>>C</option>
                        <option value="3" <?= (int)$s['dogru_cevap'] === 3 ? 'selected' : '' ?>>D</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label form-label-sm">Yeni görsel (opsiyonel)</label>
                    <input type="file" name="gorsel[]" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp,.gif">
                </div>
                <div class="col-md-4">
                    <?php if (!empty($s['gorsel_yolu'])): ?>
                    <div class="d-flex align-items-center gap-2">
                        <img src="<?= htmlspecialchars((string)$s['gorsel_yolu']) ?>" class="previmg" alt="Görsel">
                        <label class="small"><input type="checkbox" name="gorsel_sil[<?= $idx ?>]" value="1"> Görseli kaldır</label>
                    </div>
                    <?php else: ?>
                    <div class="text-muted small mt-4">Görsel yok</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <button type="submit" name="btn_test_guncelle" class="btn btn-primary"><i class="fas fa-save me-1"></i> Değişiklikleri Kaydet</button>
    </form>
</div>
</body>
</html>

