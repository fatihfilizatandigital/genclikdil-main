<?php
require_once __DIR__ . '/../auth.php';
session_start();
require_once __DIR__ . '/../../config/db.php';

$toplam_sonuc = 0;
$toplam_ogrenci = 0;
$r = @mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as n FROM sinav_sonuclari"));
if ($r) $toplam_sonuc = (int)$r['n'];
$t = @mysqli_query($conn, "SHOW TABLES LIKE 'bursluluk_ogrenciler'");
if ($t && mysqli_num_rows($t) > 0) {
    $r2 = @mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as n FROM bursluluk_ogrenciler"));
    if ($r2) $toplam_ogrenci = (int)$r2['n'];
}
$aktif_personel = $_SESSION['personel_adi'] ?? '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sınav not girişi — Ana sayfa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --g-primary: #0d9488; --g-primary-dark: #0f766e; --g-bg: #f0f4f8; --g-card: #fff; --g-border: #e2e8f0; --g-radius: 12px; --g-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--g-bg); color: #1e293b; }
        .top-bar { background: linear-gradient(135deg, var(--g-primary) 0%, var(--g-primary-dark) 100%); padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(13,148,136,0.25); }
        .top-bar .brand { color: #fff; font-weight: 700; font-size: 1.2rem; }
        .top-bar .btn-outline-light { border-color: rgba(255,255,255,0.6); color: #fff; }
        .top-bar .btn-outline-light:hover { background: rgba(255,255,255,0.2); color: #fff; }
        .panel-card { background: var(--g-card); border-radius: var(--g-radius); box-shadow: var(--g-shadow); border: 1px solid var(--g-border); padding: 24px; margin-bottom: 20px; }
        .kart-link { display: block; padding: 20px; border-radius: 10px; border: 1px solid var(--g-border); text-decoration: none; color: inherit; transition: background 0.2s, border-color 0.2s; margin-bottom: 12px; }
        .kart-link:hover { background: #f0fdfa; border-color: var(--g-primary); color: inherit; }
        .kart-link .kart-baslik { font-weight: 600; font-size: 1.1rem; }
        .kart-link .kart-desc { font-size: 0.9rem; color: #64748b; margin-top: 4px; }
    </style>
</head>
<body>
<div class="top-bar">
    <div class="brand">Sınav not girişi</div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-white-50"><?= htmlspecialchars($aktif_personel) ?></span>
        <a href="../index.php" class="btn btn-sm btn-outline-light">Yönetim paneline dön</a>
    </div>
</div>
<div class="container py-4">
    <div class="panel-card">
        <h1 class="h5 fw-bold mb-2">Sınav not girişi paneli</h1>
        <p class="text-muted small mb-4">Kayıtlı öğrenci: <strong><?= $toplam_ogrenci ?></strong> · Notu girilmiş kayıt: <strong><?= $toplam_sonuc ?></strong></p>
        <div class="row g-3">
            <div class="col-md-6 col-lg-4">
                <a href="ogrenci-listesi.php" class="kart-link">
                    <span class="kart-baslik">Not girişi</span>
                    <p class="kart-desc mb-0">Sınıf seçerek öğrencileri listele, not gir veya düzenle.</p>
                </a>
            </div>
            <div class="col-md-6 col-lg-4">
                <a href="sonuclar.php" class="kart-link">
                    <span class="kart-baslik">Sonuçlar</span>
                    <p class="kart-desc mb-0">Sınav ve sınıf seçerek notu girilmiş öğrencileri görüntüle.</p>
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
