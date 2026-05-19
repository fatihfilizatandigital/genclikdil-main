<?php
require_once __DIR__ . '/auth.php';
if (file_exists(__DIR__ . "/../ajax/connectt.php")) include(__DIR__ . "/../ajax/connectt.php");
else die("Bağlantı hatası.");
mysqli_set_charset($conn, "utf8mb4");

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die("Geçersiz test.");
$t = @mysqli_fetch_assoc(@mysqli_query($conn, "SELECT * FROM tbl_testler WHERE id = $id LIMIT 1"));
if (!$t) die("Test bulunamadı.");

$res = @mysqli_query($conn, "SELECT * FROM tbl_test_sorular WHERE test_id = $id ORDER BY soru_no ASC, id ASC");
$sorular = [];
while ($res && ($r = mysqli_fetch_assoc($res))) $sorular[] = $r;

function test_format_soru_metni($text): string {
    $safe = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    // -metin- => kalın
    $safe = preg_replace('/-([^-\r\n]+)-/u', '<strong>$1</strong>', $safe);
    return nl2br($safe);
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string)$t['baslik']) ?> - Yazdır</title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        body { font-family: Arial, sans-serif; background:#f3f4f6; color:#111; }
        .toolbar { max-width: 210mm; margin: 12px auto; display:flex; justify-content: space-between; gap:8px; }
        .paper {
            width: min(210mm, calc(100vw - 20px));
            min-height: 297mm;
            margin: 10px auto;
            background:#fff;
            box-shadow:0 0 8px rgba(0,0,0,.12);
            padding: 8mm 8mm 10mm 8mm;
        }
        .main-paper { position: relative; }
        .main-paper::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            border-left: 1px dashed #bfc7d1;
            pointer-events: none;
            z-index: 0;
        }
        .header-band {
            display:flex;
            justify-content: space-between;
            align-items: center;
            border:1px solid #ddd;
            border-bottom:0;
            background: linear-gradient(90deg, #ffffff 0%, #f3f8ff 100%);
            padding: 3mm 4mm;
        }
        .brand {
            display:flex; align-items:center; gap:8px;
            font-weight:700; color:#0f766e;
        }
        .brand img { height: 24px; width:auto; border-radius: 4px; }
        .test-meta { text-align:right; font-size:10pt; }
        .test-title {
            border:1px solid #ddd;
            border-top:0;
            background:#f8fafc;
            padding: 2.5mm 4mm;
            font-size: 13pt;
            font-weight: 700;
            color:#1f2937;
        }
        .questions {
            margin-top: 4mm;
            column-count: 2;
            column-gap: 8mm;
            column-fill: auto; /* önce sol kolon, sonra sağ */
            position: relative;
            z-index: 1;
        }
        .q {
            padding: 0;
            margin: 0 0 5mm 0;
            break-inside: avoid;
            -webkit-column-break-inside: avoid;
            page-break-inside: avoid;
        }
        .qtitle { margin:0 0 2mm 0; font-size: 11pt; color:#0f172a; font-weight: 700; }
        .qimg { max-width: 100%; max-height: 30mm; border:1px solid #ddd; margin-bottom: 2mm; border-radius:4px; }
        .qtext { font-size:10.5pt; margin-bottom:2mm; line-height:1.35; }
        .opt-list { display:block; }
        .opt-list.opt-list-compact {
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap: 1mm 6mm;
        }
        .opt { font-size:10pt; margin-bottom: 1mm; line-height:1.3; }
        .answer-sheet { margin-top: 8mm; page-break-before: always; }
        .answer-grid { display:grid; grid-template-columns: repeat(4, 1fr); gap: 6px 10px; font-size: 10.5pt; }
        @media print {
            body { background:#fff; }
            .toolbar { display:none; }
            .paper {
                box-shadow:none;
                margin:0 auto;
                width: 100%;
                min-height: 281mm;
                padding: 0;
            }
            .questions { column-gap: 7mm; }
            .q { margin-bottom: 4mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div><strong><?= htmlspecialchars((string)$t['baslik']) ?></strong> · <?= htmlspecialchars((string)$t['dil']) ?> / <?= htmlspecialchars((string)$t['seviye']) ?></div>
        <div>
            <a href="testler.php" class="btn btn-sm btn-outline-secondary">Geri</a>
            <button onclick="window.print()" class="btn btn-sm btn-primary">Yazdır</button>
        </div>
    </div>

    <div class="paper main-paper">
        <div class="header-band">
            <div class="brand">
                <img src="../resimler/logoGenclik.jpg" alt="Gençlik Dil">
                <span>GENÇLİK DİL</span>
            </div>
            <div class="test-meta">
                <div><strong><?= htmlspecialchars((string)$t['dil']) ?></strong></div>
                <div><?= htmlspecialchars((string)$t['seviye']) ?></div>
            </div>
        </div>
        <div class="test-title"><?= htmlspecialchars((string)$t['baslik']) ?></div>

        <div class="questions">
            <?php foreach ($sorular as $s): ?>
            <div class="q">
                <div class="qtitle"><?= (int)$s['soru_no'] ?>)</div>
                <?php if (!empty($s['gorsel_yolu'])): ?>
                <img class="qimg" src="<?= htmlspecialchars((string)$s['gorsel_yolu']) ?>" alt="Soru görseli">
                <?php endif; ?>
                <div class="qtext"><?= test_format_soru_metni((string)$s['soru_metni']) ?></div>
                <?php
                    $oa = trim((string)$s['secenek_a']);
                    $ob = trim((string)$s['secenek_b']);
                    $oc = trim((string)$s['secenek_c']);
                    $od = trim((string)$s['secenek_d']);
                    $opts_kisa = true;
                    foreach ([$oa, $ob, $oc, $od] as $ov) {
                        if (mb_strlen($ov, 'UTF-8') > 42 || strpos($ov, "\n") !== false) {
                            $opts_kisa = false;
                            break;
                        }
                    }
                ?>
                <div class="opt-list<?= $opts_kisa ? ' opt-list-compact' : '' ?>">
                    <div class="opt">A) <?= htmlspecialchars($oa) ?></div>
                    <div class="opt">B) <?= htmlspecialchars($ob) ?></div>
                    <div class="opt">C) <?= htmlspecialchars($oc) ?></div>
                    <div class="opt">D) <?= htmlspecialchars($od) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>

    <div class="paper answer-sheet">
        <div class="header-band">
            <div class="brand">
                <img src="../resimler/logoGenclik.jpg" alt="Gençlik Dil">
                <span>GENÇLİK DİL</span>
            </div>
            <div class="test-meta">
                <div><strong><?= htmlspecialchars((string)$t['dil']) ?></strong></div>
                <div><?= htmlspecialchars((string)$t['seviye']) ?></div>
            </div>
        </div>
        <div class="test-title"><?= htmlspecialchars((string)$t['baslik']) ?> - Cevap Anahtarı</div>
        <div style="padding-top: 4mm;">
            <div class="answer-grid">
            <?php foreach ($sorular as $s): ?>
            <div><?= (int)$s['soru_no'] ?>) <strong><?= ['A','B','C','D'][(int)$s['dogru_cevap']] ?? 'A' ?></strong></div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>

