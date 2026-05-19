<?php
require_once __DIR__ . '/auth.php';

// Bağlantı
if (file_exists(__DIR__ . "/../ajax/connectt.php")) include(__DIR__ . "/../ajax/connectt.php");
elseif (file_exists(__DIR__ . "/../connectt.php")) include(__DIR__ . "/../connectt.php");
else die("Bağlantı hatası.");

mysqli_set_charset($conn, "utf8");

// Daha anlaşılır teşhis (bağlantı + tablo var mı)
if (!isset($conn) || !$conn) {
    die("Bağlantı Hatası: bağlantı nesnesi oluşturulamadı.");
}
$tbl_chk = @mysqli_query($conn, "SHOW TABLES LIKE 'tbl_sorular'");
if (!$tbl_chk || mysqli_num_rows($tbl_chk) === 0) {
    die("Veritabanında `tbl_sorular` tablosu bulunamadı.");
}

$aktif_personel = $_SESSION['personel_adi'] ?? '';
$aktif_personel_id = isset($_SESSION['personel_id']) ? (int)$_SESSION['personel_id'] : 0;

// Dil filtresi
$filtre_dil = isset($_GET['dil']) ? trim((string)$_GET['dil']) : '';
$filtre_dil = in_array($filtre_dil, ['Ingilizce', 'Almanca'], true) ? $filtre_dil : '';

$mesaj = "";

// SORU SİLME
if (isset($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    if ($id > 0) {
        @mysqli_query($conn, "DELETE FROM tbl_sorular WHERE id=$id");
    }
    $qs = $filtre_dil !== '' ? "?dil=" . urlencode($filtre_dil) : "";
    header("Location: soru-bankasi.php" . $qs);
    exit;
}

// SORU EKLEME
if (isset($_POST['btnSoruEkle'])) {
    $dil = trim((string)($_POST['dil'] ?? ''));
    $seviye = trim((string)($_POST['seviye'] ?? ''));
    $kategori = trim((string)($_POST['kategori'] ?? ''));
    $soru = mysqli_real_escape_string($conn, (string)($_POST['soru'] ?? ''));
    $secA = mysqli_real_escape_string($conn, (string)($_POST['secA'] ?? ''));
    $secB = mysqli_real_escape_string($conn, (string)($_POST['secB'] ?? ''));
    $secC = mysqli_real_escape_string($conn, (string)($_POST['secC'] ?? ''));
    $secD = mysqli_real_escape_string($conn, (string)($_POST['secD'] ?? ''));
    $dogru = (int)($_POST['dogru'] ?? 0); // 0,1,2,3
    if ($dogru < 0 || $dogru > 3) $dogru = 0;

    if (!in_array($dil, ['Ingilizce', 'Almanca'], true)) $dil = 'Ingilizce';
    if (!in_array($seviye, ['A1','A2','B1','B2','C1','C2'], true)) $seviye = 'A1';
    if (!in_array($kategori, ['Genel','Cocuk','Is'], true)) $kategori = 'Genel';

    $ekleyen = $aktif_personel_id > 0 ? $aktif_personel_id : 0;
    $sql = "INSERT INTO tbl_sorular (dil, seviye, kategori, soru, secenek_a, secenek_b, secenek_c, secenek_d, dogru_cevap, ekleyen_id)
            VALUES ('$dil', '$seviye', '$kategori', '$soru', '$secA', '$secB', '$secC', '$secD', '$dogru', '$ekleyen')";
    if (@mysqli_query($conn, $sql)) {
        $mesaj = '<div class="alert alert-success mb-2">Soru başarıyla eklendi.</div>';
    } else {
        $mesaj = '<div class="alert alert-danger mb-2">Hata: '.htmlspecialchars(mysqli_error($conn)).'</div>';
    }
}

// SORU DÜZENLEME
if (isset($_POST['btnSoruGuncelle'])) {
    $id = (int)($_POST['id'] ?? 0);
    $dil = trim((string)($_POST['dil'] ?? ''));
    $seviye = trim((string)($_POST['seviye'] ?? ''));
    $kategori = trim((string)($_POST['kategori'] ?? ''));
    $soru = mysqli_real_escape_string($conn, (string)($_POST['soru'] ?? ''));
    $secA = mysqli_real_escape_string($conn, (string)($_POST['secA'] ?? ''));
    $secB = mysqli_real_escape_string($conn, (string)($_POST['secB'] ?? ''));
    $secC = mysqli_real_escape_string($conn, (string)($_POST['secC'] ?? ''));
    $secD = mysqli_real_escape_string($conn, (string)($_POST['secD'] ?? ''));
    $dogru = (int)($_POST['dogru'] ?? 0);
    if ($dogru < 0 || $dogru > 3) $dogru = 0;

    if ($id > 0) {
        if (!in_array($dil, ['Ingilizce', 'Almanca'], true)) $dil = 'Ingilizce';
        if (!in_array($seviye, ['A1','A2','B1','B2','C1','C2'], true)) $seviye = 'A1';
        if (!in_array($kategori, ['Genel','Cocuk','Is'], true)) $kategori = 'Genel';

        $sql = "UPDATE tbl_sorular SET
                    dil = '$dil',
                    seviye = '$seviye',
                    kategori = '$kategori',
                    soru = '$soru',
                    secenek_a = '$secA',
                    secenek_b = '$secB',
                    secenek_c = '$secC',
                    secenek_d = '$secD',
                    dogru_cevap = '$dogru'
                WHERE id = $id
                LIMIT 1";
        if (@mysqli_query($conn, $sql)) {
            $mesaj = '<div class="alert alert-success mb-2">Soru güncellendi.</div>';
        } else {
            $mesaj = '<div class="alert alert-danger mb-2">Hata: '.htmlspecialchars(mysqli_error($conn)).'</div>';
        }
    }
}

$where = "1=1";
if ($filtre_dil !== '') {
    $dil_esc = mysqli_real_escape_string($conn, $filtre_dil);
    $where .= " AND dil = '$dil_esc'";
}

$q = mysqli_query($conn, "SELECT * FROM tbl_sorular WHERE $where ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>Soru Bankası - Yönetim</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="../resimler/logoGenclik.jpg">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Montserrat:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        body { background:#f1f5f9; font-family:'Montserrat',sans-serif; font-size: 15px; }
        .admin-header { background:#fff; padding:12px 30px; box-shadow:0 2px 10px rgba(0,0,0,0.03); display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:1000; }
        .logo-area { display:flex; align-items:center; gap:12px; text-decoration:none; }
        .logo-area img { height:42px; border-radius:8px; }
        .logo-area span { font-weight:700; color:#1e293b; letter-spacing:0.5px; }
        .user-actions { display:flex; align-items:center; gap:12px; }
        .user-profile { font-size:14px; color:#1e293b; }
        .user-profile strong { color:#2563eb; }
        .wrap { max-width: 1600px; margin: 0 auto; padding: 22px 16px; }
        .card-box { background:#fff; border-radius:14px; padding:18px; box-shadow:0 4px 10px rgba(15,23,42,0.06); }
        label { font-weight:700; font-size:12px; color:#475569; }
        .btn-xs { padding: .15rem .4rem; font-size: .75rem; }
        .opt-cell { display:none; }
        .show-options .opt-cell { display: table-cell; }
        .opt-text { font-size:13px; color:#334155; }
        .muted { color:#64748b; }
    </style>
</head>
<body>
    <header class="admin-header">
        <a href="index.php" class="logo-area">
            <img src="../resimler/logoGenclik.jpg" alt="Logo">
            <span>YÖNETİM MERKEZİ</span>
        </a>
        <div class="user-actions">
            <span class="user-profile">Hoşgeldin, <strong><?= htmlspecialchars($aktif_personel !== '' ? $aktif_personel : 'Personel') ?></strong></span>
            <a href="seviye_tespit.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Seviye Tespit</a>
            <a href="../logout.php" class="btn btn-sm btn-danger"><i class="fas fa-sign-out-alt me-1"></i> Çıkış</a>
        </div>
    </header>

    <div class="wrap">
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card-box">
                    <h5 class="mb-3"><i class="fas fa-plus-circle text-success me-2"></i> Yeni Soru Ekle</h5>
                    <?= $mesaj ?>
                    <form method="POST">
                        <div class="row g-2">
                            <div class="col-6">
                                <label>Dil</label>
                                <select name="dil" class="form-select form-select-sm">
                                    <option value="Ingilizce">İngilizce</option>
                                    <option value="Almanca">Almanca</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label>Seviye</label>
                                <select name="seviye" class="form-select form-select-sm">
                                    <option>A1</option><option>A2</option><option>B1</option><option>B2</option><option>C1</option><option>C2</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label>Kategori</label>
                            <select name="kategori" class="form-select form-select-sm">
                                <option value="Genel">Genel</option>
                                <option value="Cocuk">Çocuk</option>
                                <option value="Is">İş</option>
                            </select>
                        </div>
                        <div class="mt-2">
                            <label>Soru Metni</label>
                            <textarea name="soru" class="form-control form-control-sm" rows="3" required placeholder="Soru buraya..."></textarea>
                        </div>
                        <div class="mt-2">
                            <label>Seçenekler</label>
                            <input type="text" name="secA" class="form-control form-control-sm mb-1" required placeholder="A şıkkı">
                            <input type="text" name="secB" class="form-control form-control-sm mb-1" required placeholder="B şıkkı">
                            <input type="text" name="secC" class="form-control form-control-sm mb-1" required placeholder="C şıkkı">
                            <input type="text" name="secD" class="form-control form-control-sm" required placeholder="D şıkkı">
                        </div>
                        <div class="mt-2">
                            <label class="text-success">Doğru cevap</label>
                            <select name="dogru" class="form-select form-select-sm" style="border:2px solid #10b981;">
                                <option value="0">A</option>
                                <option value="1">B</option>
                                <option value="2">C</option>
                                <option value="3">D</option>
                            </select>
                        </div>
                        <button type="submit" name="btnSoruEkle" class="btn btn-success w-100 mt-3"><i class="fas fa-save me-1"></i> Kaydet</button>
                    </form>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card-box">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div>
                            <h5 class="mb-0"><i class="fas fa-book-open text-primary me-2"></i> Mevcut Sorular</h5>
                            <div class="small muted">Dil filtresi, arama, şık görünümü ve düzenleme.</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <form method="GET" class="d-flex gap-2 align-items-center m-0">
                                <label class="small muted m-0">Dil</label>
                                <select name="dil" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Tümü</option>
                                    <option value="Ingilizce" <?= $filtre_dil === 'Ingilizce' ? 'selected' : '' ?>>İngilizce</option>
                                    <option value="Almanca" <?= $filtre_dil === 'Almanca' ? 'selected' : '' ?>>Almanca</option>
                                </select>
                            </form>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="toggleOptions" onchange="toggleOptionsView()">
                                <label class="form-check-label small" for="toggleOptions">Tüm şıkları göster</label>
                            </div>
                        </div>
                    </div>

                    <input type="text" id="filtreInput" onkeyup="filtrele()" class="form-control form-control-sm mb-2" placeholder="Soru içinde ara...">

                    <div class="table-responsive" style="max-height: 70vh; overflow-y:auto;">
                        <table class="table table-sm table-striped table-hover align-middle" id="soruTablosu">
                            <thead>
                                <tr>
                                    <th style="width:140px;">Dil/Seviye</th>
                                    <th>Soru</th>
                                    <th class="opt-cell">Şıklar</th>
                                    <th style="width:70px;" class="text-center">Doğru</th>
                                    <th style="width:120px;">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $siklar = ['A','B','C','D'];
                                while ($q && ($row = mysqli_fetch_assoc($q))) {
                                    $dogruHarf = $siklar[(int)$row['dogru_cevap']] ?? 'A';
                                    $id = (int)$row['id'];
                                    $rowJson = htmlspecialchars(json_encode([
                                        'id' => $id,
                                        'dil' => (string)$row['dil'],
                                        'seviye' => (string)$row['seviye'],
                                        'kategori' => (string)$row['kategori'],
                                        'soru' => (string)$row['soru'],
                                        'secA' => (string)$row['secenek_a'],
                                        'secB' => (string)$row['secenek_b'],
                                        'secC' => (string)$row['secenek_c'],
                                        'secD' => (string)$row['secenek_d'],
                                        'dogru' => (int)$row['dogru_cevap'],
                                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

                                    echo '<tr data-row="'.$rowJson.'">
                                        <td>
                                            <span class="badge bg-primary">'.htmlspecialchars($row['dil']).'</span>
                                            <span class="badge bg-warning text-dark">'.htmlspecialchars($row['seviye']).'</span>
                                            <div class="opt-text muted mt-1">'.htmlspecialchars($row['kategori']).'</div>
                                        </td>
                                        <td>'.htmlspecialchars($row['soru']).'</td>
                                        <td class="opt-cell">
                                            <div class="opt-text"><b>A:</b> '.htmlspecialchars($row['secenek_a']).'</div>
                                            <div class="opt-text"><b>B:</b> '.htmlspecialchars($row['secenek_b']).'</div>
                                            <div class="opt-text"><b>C:</b> '.htmlspecialchars($row['secenek_c']).'</div>
                                            <div class="opt-text"><b>D:</b> '.htmlspecialchars($row['secenek_d']).'</div>
                                        </td>
                                        <td class="text-center" style="font-weight:800; color:#10b981;">'.$dogruHarf.'</td>
                                        <td>
                                            <button type="button" class="btn btn-outline-primary btn-xs" onclick="openEditModal(this)">Düzenle</button>
                                            <a href="?sil='.$id.($filtre_dil!==''?'&dil='.urlencode($filtre_dil):'').'" onclick="return confirm(\'Silmek istediğinize emin misiniz?\')" class="btn btn-danger btn-xs">Sil</a>
                                        </td>
                                    </tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" class="m-0">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Soru Düzenle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label>Dil</label>
                                <select name="dil" id="edit_dil" class="form-select form-select-sm">
                                    <option value="Ingilizce">İngilizce</option>
                                    <option value="Almanca">Almanca</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>Seviye</label>
                                <select name="seviye" id="edit_seviye" class="form-select form-select-sm">
                                    <option>A1</option><option>A2</option><option>B1</option><option>B2</option><option>C1</option><option>C2</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>Kategori</label>
                                <select name="kategori" id="edit_kategori" class="form-select form-select-sm">
                                    <option value="Genel">Genel</option>
                                    <option value="Cocuk">Çocuk</option>
                                    <option value="Is">İş</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label>Soru</label>
                            <textarea name="soru" id="edit_soru" class="form-control form-control-sm" rows="3" required></textarea>
                        </div>
                        <div class="mt-2">
                            <label>Şıklar</label>
                            <input type="text" name="secA" id="edit_secA" class="form-control form-control-sm mb-1" required placeholder="A">
                            <input type="text" name="secB" id="edit_secB" class="form-control form-control-sm mb-1" required placeholder="B">
                            <input type="text" name="secC" id="edit_secC" class="form-control form-control-sm mb-1" required placeholder="C">
                            <input type="text" name="secD" id="edit_secD" class="form-control form-control-sm" required placeholder="D">
                        </div>
                        <div class="mt-2">
                            <label class="text-success">Doğru cevap</label>
                            <select name="dogru" id="edit_dogru" class="form-select form-select-sm" style="border:2px solid #10b981;">
                                <option value="0">A</option><option value="1">B</option><option value="2">C</option><option value="3">D</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="submit" name="btnSoruGuncelle" class="btn btn-primary"><i class="fas fa-save me-1"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.2.0.min.js"></script>
    <script src="../js/bootstrap.min.js"></script>
    <script>
        function filtrele() {
            var input = document.getElementById("filtreInput");
            var filter = (input.value || "").toUpperCase();
            var table = document.getElementById("soruTablosu");
            var tr = table.getElementsByTagName("tr");
            for (var i = 0; i < tr.length; i++) {
                var td = tr[i].getElementsByTagName("td")[1];
                if (td) {
                    var txtValue = td.textContent || td.innerText;
                    tr[i].style.display = (txtValue.toUpperCase().indexOf(filter) > -1) ? "" : "none";
                }
            }
        }

        function toggleOptionsView() {
            var table = document.getElementById('soruTablosu');
            var on = document.getElementById('toggleOptions').checked;
            if (table) table.classList.toggle('show-options', !!on);
        }

        function openEditModal(btn) {
            var tr = btn && btn.closest ? btn.closest('tr') : null;
            if (!tr) return;
            var raw = tr.getAttribute('data-row') || '';
            try {
                var data = JSON.parse(raw);
                document.getElementById('edit_id').value = data.id || '';
                document.getElementById('edit_dil').value = data.dil || 'Ingilizce';
                document.getElementById('edit_seviye').value = data.seviye || 'A1';
                document.getElementById('edit_kategori').value = data.kategori || 'Genel';
                document.getElementById('edit_soru').value = data.soru || '';
                document.getElementById('edit_secA').value = data.secA || '';
                document.getElementById('edit_secB').value = data.secB || '';
                document.getElementById('edit_secC').value = data.secC || '';
                document.getElementById('edit_secD').value = data.secD || '';
                document.getElementById('edit_dogru').value = String(data.dogru ?? 0);
            } catch (e) {}

            // Bootstrap 5 uyumluluğu (fallback: bootstrap 4 varsa jQuery modal)
            var el = document.getElementById('editModal');
            if (window.bootstrap && bootstrap.Modal) {
                var m = bootstrap.Modal.getOrCreateInstance(el);
                m.show();
            } else if (window.jQuery && jQuery.fn && jQuery.fn.modal) {
                jQuery(el).modal('show');
            }
        }
    </script>
</body>
</html>

