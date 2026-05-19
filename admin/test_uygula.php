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

$puan = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['degerlendir'])) {
    $dogru = 0;
    $toplam = count($sorular);
    foreach ($sorular as $s) {
        $sid = (int)$s['id'];
        $cev = isset($_POST['cevap'][$sid]) ? (int)$_POST['cevap'][$sid] : -1;
        if ($cev === (int)$s['dogru_cevap']) $dogru++;
    }
    $puan = $toplam > 0 ? round(($dogru * 100) / $toplam, 2) : 0;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string)$t['baslik']) ?> - Online Test</title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <style>
        body { background:#f8fafc; font-family: Arial, sans-serif; }
        .wrap { max-width: 980px; margin: 20px auto; padding: 0 12px; }
        .q { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px; margin-bottom:10px; }
        .qimg { max-width: 280px; max-height: 180px; border-radius:8px; border:1px solid #ddd; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h4 class="m-0"><?= htmlspecialchars((string)$t['baslik']) ?></h4>
            <a href="testler.php" class="btn btn-sm btn-outline-secondary">Geri</a>
        </div>
        <div class="text-muted mb-3"><?= htmlspecialchars((string)$t['dil']) ?> / <?= htmlspecialchars((string)$t['seviye']) ?> · <?= count($sorular) ?> soru</div>

        <?php if ($puan !== null): ?>
        <div class="alert alert-info"><strong>Puan:</strong> <?= $puan ?> / 100</div>
        <?php endif; ?>

        <form method="POST">
            <?php foreach ($sorular as $s): $sid = (int)$s['id']; ?>
            <div class="q">
                <div class="fw-bold mb-2">Soru <?= (int)$s['soru_no'] ?></div>
                <?php if (!empty($s['gorsel_yolu'])): ?>
                <div class="mb-2"><img class="qimg" src="<?= htmlspecialchars((string)$s['gorsel_yolu']) ?>" alt="Soru görseli"></div>
                <?php endif; ?>
                <div class="mb-2"><?= test_format_soru_metni((string)$s['soru_metni']) ?></div>
                <?php
                $opts = [
                    0 => $s['secenek_a'],
                    1 => $s['secenek_b'],
                    2 => $s['secenek_c'],
                    3 => $s['secenek_d'],
                ];
                foreach ($opts as $k => $v):
                ?>
                <label class="d-block mb-1">
                    <input type="radio" name="cevap[<?= $sid ?>]" value="<?= $k ?>"> <strong><?= ['A','B','C','D'][$k] ?>)</strong> <?= htmlspecialchars((string)$v) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <button class="btn btn-primary" type="submit" name="degerlendir">Değerlendir</button>
        </form>
    </div>
</body>
</html>

