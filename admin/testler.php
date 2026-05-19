<?php
require_once __DIR__ . '/auth.php';

if (file_exists(__DIR__ . "/../ajax/connectt.php")) include(__DIR__ . "/../ajax/connectt.php");
elseif (file_exists(__DIR__ . "/../connectt.php")) include(__DIR__ . "/../connectt.php");
else die("Bağlantı hatası.");

mysqli_set_charset($conn, "utf8mb4");

// Şema
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS tbl_testler (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    baslik VARCHAR(255) NOT NULL,
    dil VARCHAR(30) NOT NULL DEFAULT 'Ingilizce',
    seviye VARCHAR(10) NOT NULL DEFAULT 'A1',
    soru_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
    olusturan_id INT UNSIGNED DEFAULT NULL,
    olusturan_ad VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS tbl_test_sorular (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    test_id INT UNSIGNED NOT NULL,
    soru_no INT UNSIGNED NOT NULL,
    soru_metni TEXT NOT NULL,
    gorsel_yolu VARCHAR(255) DEFAULT NULL,
    secenek_a VARCHAR(500) NOT NULL,
    secenek_b VARCHAR(500) NOT NULL,
    secenek_c VARCHAR(500) NOT NULL,
    secenek_d VARCHAR(500) NOT NULL,
    dogru_cevap TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_test (test_id),
    KEY idx_test_no (test_id, soru_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$mesaj = '';
$aktif_personel = $_SESSION['personel_adi'] ?? 'Personel';
$aktif_id = isset($_SESSION['personel_id']) ? (int)$_SESSION['personel_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);

// Sil
if (isset($_GET['sil'])) {
    $test_id = (int)$_GET['sil'];
    if ($test_id > 0) {
        $res = @mysqli_query($conn, "SELECT gorsel_yolu FROM tbl_test_sorular WHERE test_id = $test_id");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $p = trim((string)($r['gorsel_yolu'] ?? ''));
            if ($p !== '') {
                $abs = __DIR__ . '/' . ltrim($p, '/\\');
                if (is_file($abs)) @unlink($abs);
            }
        }
        @mysqli_query($conn, "DELETE FROM tbl_test_sorular WHERE test_id = $test_id");
        @mysqli_query($conn, "DELETE FROM tbl_testler WHERE id = $test_id LIMIT 1");
    }
    header("Location: testler.php");
    exit;
}

// Kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_test_kaydet'])) {
    $baslik = trim((string)($_POST['baslik'] ?? ''));
    $dil = trim((string)($_POST['dil'] ?? 'Ingilizce'));
    $seviye = trim((string)($_POST['seviye'] ?? 'A1'));
    $soru_sayisi = (int)($_POST['soru_sayisi'] ?? 0);
    if ($soru_sayisi < 1) $soru_sayisi = 1;
    if ($soru_sayisi > 100) $soru_sayisi = 100;
    if ($baslik === '') $baslik = 'Yeni Test';
    if (!in_array($dil, ['Ingilizce', 'Almanca'], true)) $dil = 'Ingilizce';
    if (!in_array($seviye, ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'], true)) $seviye = 'A1';

    $soru_metni = $_POST['soru_metni'] ?? [];
    $secA = $_POST['secA'] ?? [];
    $secB = $_POST['secB'] ?? [];
    $secC = $_POST['secC'] ?? [];
    $secD = $_POST['secD'] ?? [];
    $dogru = $_POST['dogru'] ?? [];

    mysqli_begin_transaction($conn);
    $ok = true;

    $baslik_esc = mysqli_real_escape_string($conn, $baslik);
    $dil_esc = mysqli_real_escape_string($conn, $dil);
    $seviye_esc = mysqli_real_escape_string($conn, $seviye);
    $ol_esc = mysqli_real_escape_string($conn, $aktif_personel);

    $ins_test = "INSERT INTO tbl_testler (baslik, dil, seviye, soru_sayisi, olusturan_id, olusturan_ad) VALUES ('$baslik_esc', '$dil_esc', '$seviye_esc', $soru_sayisi, $aktif_id, '$ol_esc')";
    if (!@mysqli_query($conn, $ins_test)) $ok = false;
    $test_id = $ok ? (int)mysqli_insert_id($conn) : 0;

    $upload_dir_abs = __DIR__ . '/uploads/test_sorular';
    $upload_dir_rel = 'uploads/test_sorular';
    if ($ok && !is_dir($upload_dir_abs)) @mkdir($upload_dir_abs, 0777, true);

    for ($i = 0; $ok && $i < $soru_sayisi; $i++) {
        $no = $i + 1;
        $q = trim((string)($soru_metni[$i] ?? ''));
        $a = trim((string)($secA[$i] ?? ''));
        $b = trim((string)($secB[$i] ?? ''));
        $c = trim((string)($secC[$i] ?? ''));
        $d = trim((string)($secD[$i] ?? ''));
        $dc = (int)($dogru[$i] ?? 0);
        if ($dc < 0 || $dc > 3) $dc = 0;

        if ($q === '' || $a === '' || $b === '' || $c === '' || $d === '') {
            $ok = false;
            break;
        }

        $gorsel_rel = null;
        if (isset($_FILES['gorsel']['name'][$i]) && ($_FILES['gorsel']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = $_FILES['gorsel']['tmp_name'][$i];
            $name = (string)$_FILES['gorsel']['name'][$i];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $izinli = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $izinli, true)) {
                $safe = 'test_' . $test_id . '_' . $no . '_' . time() . '.' . $ext;
                $dst_abs = $upload_dir_abs . '/' . $safe;
                if (@move_uploaded_file($tmp, $dst_abs)) {
                    $gorsel_rel = $upload_dir_rel . '/' . $safe;
                }
            }
        }

        $q_esc = mysqli_real_escape_string($conn, $q);
        $a_esc = mysqli_real_escape_string($conn, $a);
        $b_esc = mysqli_real_escape_string($conn, $b);
        $c_esc = mysqli_real_escape_string($conn, $c);
        $d_esc = mysqli_real_escape_string($conn, $d);
        $g_esc = $gorsel_rel !== null ? ("'" . mysqli_real_escape_string($conn, $gorsel_rel) . "'") : "NULL";

        $ins_q = "INSERT INTO tbl_test_sorular (test_id, soru_no, soru_metni, gorsel_yolu, secenek_a, secenek_b, secenek_c, secenek_d, dogru_cevap)
                  VALUES ($test_id, $no, '$q_esc', $g_esc, '$a_esc', '$b_esc', '$c_esc', '$d_esc', $dc)";
        if (!@mysqli_query($conn, $ins_q)) {
            $ok = false;
            break;
        }
    }

    if ($ok) {
        mysqli_commit($conn);
        $mesaj = '<div class="alert alert-success">Test başarıyla oluşturuldu.</div>';
    } else {
        mysqli_rollback($conn);
        $mesaj = '<div class="alert alert-danger">Test oluşturulurken hata oluştu. Lütfen tüm soruları/şıkları doldurun.</div>';
    }
}

$testler = @mysqli_query($conn, "SELECT t.*, (SELECT COUNT(*) FROM tbl_test_sorular s WHERE s.test_id = t.id) AS soru_adedi FROM tbl_testler t ORDER BY t.id DESC");
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Testler - Yönetim</title>
    <link rel="icon" type="image/x-icon" href="../resimler/logoGenclik.jpg">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        body { background:#f1f5f9; font-family: 'Montserrat', sans-serif; }
        .top { background:#fff; padding:12px 20px; box-shadow:0 2px 10px rgba(0,0,0,.04); display:flex; justify-content:space-between; align-items:center; }
        .wrap { max-width: 1400px; margin: 12px auto; padding: 0 12px; }
        .cardx { background:#fff; border-radius:12px; padding:16px; box-shadow:0 3px 8px rgba(0,0,0,.05); }
        .qbox { border:1px solid #e2e8f0; border-radius:10px; padding:10px; margin-bottom:10px; background:#f8fafc; }
        .small-muted { font-size:12px; color:#64748b; }
    </style>
</head>
<body>
    <div class="top">
        <div><i class="fas fa-vial me-2 text-primary"></i><strong>Testler</strong> <span class="small-muted ms-2">| <?= htmlspecialchars($aktif_personel) ?></span></div>
        <div class="d-flex gap-2">
            <a href="seviye_tespit.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Seviye Tespit</a>
            <a href="index.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-th-large me-1"></i> Panel</a>
        </div>
    </div>

    <div class="wrap">
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="cardx">
                    <h5 class="mb-2"><i class="fas fa-plus-circle text-success me-2"></i>Yeni Test Oluştur</h5>
                    <div class="small-muted mb-2">Sorular 4 şıklı çoktan seçmeli. İsteğe bağlı soru görseli ekleyebilirsiniz.</div>
                    <?= $mesaj ?>
                    <form method="POST" enctype="multipart/form-data" id="testForm">
                        <div class="row g-2">
                            <div class="col-md-5">
                                <label class="form-label form-label-sm">Test Başlığı</label>
                                <input type="text" name="baslik" class="form-control form-control-sm" required placeholder="Örn: A2 Deneme Testi">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label form-label-sm">Dil</label>
                                <select name="dil" class="form-select form-select-sm">
                                    <option value="Ingilizce">İngilizce</option>
                                    <option value="Almanca">Almanca</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label form-label-sm">Seviye</label>
                                <select name="seviye" class="form-select form-select-sm">
                                    <option>A1</option><option>A2</option><option>B1</option><option>B2</option><option>C1</option><option>C2</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm">Soru Sayısı</label>
                                <input type="number" id="soruSayisi" name="soru_sayisi" min="1" max="100" value="4" class="form-control form-control-sm" onchange="renderSorular()">
                            </div>
                        </div>
                        <hr>
                        <div id="sorularAlani"></div>
                        <button type="submit" name="btn_test_kaydet" class="btn btn-success"><i class="fas fa-save me-1"></i> Testi Kaydet</button>
                    </form>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="cardx">
                    <h5 class="mb-2"><i class="fas fa-list text-primary me-2"></i>Oluşturulan Testler</h5>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Başlık</th><th>Dil/Seviye</th><th>Soru</th><th>İşlem</th></tr></thead>
                            <tbody>
                                <?php if ($testler): while ($t = mysqli_fetch_assoc($testler)): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$t['baslik']) ?></strong>
                                        <div class="small-muted"><?= htmlspecialchars((string)($t['olusturan_ad'] ?? '-')) ?> · <?= htmlspecialchars((string)($t['created_at'] ?? '')) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string)$t['dil']) ?> / <?= htmlspecialchars((string)$t['seviye']) ?></td>
                                    <td><?= (int)($t['soru_adedi'] ?? 0) ?></td>
                                    <td class="text-nowrap">
                                        <a class="btn btn-sm btn-outline-secondary" href="test_duzenle.php?id=<?= (int)$t['id'] ?>">Düzenle</a>
                                        <a class="btn btn-sm btn-outline-primary" href="test_uygula.php?id=<?= (int)$t['id'] ?>" target="_blank">Online</a>
                                        <a class="btn btn-sm btn-outline-success" href="test_yazdir.php?id=<?= (int)$t['id'] ?>" target="_blank">Yazdır</a>
                                        <a class="btn btn-sm btn-outline-danger" href="?sil=<?= (int)$t['id'] ?>" onclick="return confirm('Bu testi silmek istediğinize emin misiniz?')">Sil</a>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="4" class="text-center text-muted">Henüz test yok.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function qHtml(i) {
        var no = i + 1;
        return '<div class="qbox">' +
            '<div class="fw-bold mb-2">Soru ' + no + '</div>' +
            '<label class="form-label form-label-sm">Soru Metni</label>' +
            '<textarea name="soru_metni[]" class="form-control form-control-sm mb-2" rows="2" required></textarea>' +
            '<label class="form-label form-label-sm">Soru Görseli (isteğe bağlı)</label>' +
            '<input type="file" name="gorsel[]" class="form-control form-control-sm mb-2" accept=".jpg,.jpeg,.png,.webp,.gif">' +
            '<div class="row g-2">' +
                '<div class="col-md-6"><input type="text" name="secA[]" class="form-control form-control-sm" placeholder="A şıkkı" required></div>' +
                '<div class="col-md-6"><input type="text" name="secB[]" class="form-control form-control-sm" placeholder="B şıkkı" required></div>' +
                '<div class="col-md-6"><input type="text" name="secC[]" class="form-control form-control-sm" placeholder="C şıkkı" required></div>' +
                '<div class="col-md-6"><input type="text" name="secD[]" class="form-control form-control-sm" placeholder="D şıkkı" required></div>' +
            '</div>' +
            '<div class="mt-2">' +
                '<label class="form-label form-label-sm text-success">Doğru Cevap</label>' +
                '<select name="dogru[]" class="form-select form-select-sm">' +
                    '<option value="0">A</option><option value="1">B</option><option value="2">C</option><option value="3">D</option>' +
                '</select>' +
            '</div>' +
        '</div>';
    }
    function renderSorular() {
        var n = parseInt(document.getElementById('soruSayisi').value || '4', 10);
        if (isNaN(n) || n < 1) n = 1;
        if (n > 100) n = 100;
        document.getElementById('soruSayisi').value = n;
        var html = '';
        for (var i = 0; i < n; i++) html += qHtml(i);
        document.getElementById('sorularAlani').innerHTML = html;
    }
    renderSorular();
    </script>
</body>
</html>

