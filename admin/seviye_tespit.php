<?php
require_once __DIR__ . '/auth.php';

setlocale(LC_TIME, 'tr_TR.UTF-8', 'tr_TR', 'Turkish');
$bugun = strftime('%e %B %Y, %A');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>Seviye Tespit - Gençlik Dil</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="../resimler/logoGenclik.jpg">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Montserrat:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --dark: #1e293b;
            --bg-color: #f1f5f9;
            --text-muted: #64748b;
        }
        body { background-color: var(--bg-color); font-family: 'Montserrat', sans-serif; color: #333; }
        .admin-header { background: #fff; padding: 12px 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1000; }
        .logo-area { display: flex; align-items: center; gap: 15px; }
        .logo-area img { height: 45px; border-radius: 8px; }
        .logo-area span { font-weight: 700; font-size: 18px; color: var(--dark); letter-spacing: 0.5px; }
        .user-actions { display: flex; align-items: center; gap: 15px; }
        .user-profile { font-weight: 500; color: var(--dark); font-size: 14px; }
        .user-profile strong { color: var(--primary); }
        .dashboard-container { max-width: 1200px; margin: 0 auto; padding: 30px 15px; }
        .welcome-banner {
            background: linear-gradient(135deg, var(--danger) 0%, #b91c1c 100%);
            border-radius: 16px; padding: 28px 34px; color: white; margin-bottom: 30px;
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.2);
            display: flex; justify-content: space-between; align-items: center; position: relative; overflow: hidden;
        }
        .welcome-banner::after { content: "\f201"; font-family: "Font Awesome 5 Free"; font-weight: 900; position: absolute; right: 40px; top: -20px; font-size: 150px; opacity: 0.06; transform: rotate(-15deg); }
        .welcome-text h2 { font-weight: 700; font-size: 24px; margin-bottom: 6px; }
        .welcome-text p { font-size: 14px; opacity: 0.9; margin: 0; }
        .date-badge { background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 30px; font-size: 14px; font-weight: 500; backdrop-filter: blur(5px); }
        .card-box {
            background: #fff; border-radius: 16px; padding: 25px; margin-bottom: 25px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            display: block; text-decoration: none; color: inherit; border-left: 4px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); position: relative; height: calc(100% - 25px);
        }
        .card-box:hover { transform: translateY(-5px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); text-decoration: none; color: inherit; }
        .icon-wrapper { width: 55px; height: 55px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 20px; transition: transform 0.3s ease; }
        .card-box:hover .icon-wrapper { transform: scale(1.1); }
        .card-title { font-size: 17px; font-weight: 700; color: var(--dark); margin-bottom: 8px; }
        .card-desc { font-size: 13px; color: var(--text-muted); line-height: 1.5; }
        .border-red { border-left-color: var(--danger); }
        .border-red .icon-wrapper { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .border-orange { border-left-color: var(--warning); }
        .border-orange .icon-wrapper { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .btn-custom { border-radius: 8px; font-weight: 500; font-size: 13px; padding: 8px 16px; transition: all 0.2s; }
    </style>
</head>
<body>
    <header class="admin-header">
        <a href="index.php" class="logo-area" style="text-decoration: none;">
            <img src="../resimler/logoGenclik.jpg" alt="Logo">
            <span>YÖNETİM MERKEZİ</span>
        </a>
        <div class="user-actions">
            <span class="user-profile">Hoşgeldin, <strong><?php echo htmlspecialchars($_SESSION['personel_adi'] ?? 'Yönetici'); ?></strong></span>
            <div style="width: 1px; height: 24px; background-color: #e2e8f0; margin: 0 5px;"></div>
            <a href="../logout.php" class="btn btn-danger btn-custom btn-sm"><i class="fas fa-sign-out-alt me-1"></i> Çıkış Yap</a>
        </div>
    </header>

    <div class="dashboard-container">
        <div class="welcome-banner">
            <div class="welcome-text">
                <h2>Seviye Tespit</h2>
                <p>Seviye tespit sonuçları ve soru bankası modülleri.</p>
            </div>
            <div class="date-badge">
                <i class="far fa-calendar-alt me-2"></i> <?php echo $bugun; ?>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 col-lg-4">
                <a href="seviye-sonuclari.php" class="card-box border-red">
                    <div class="icon-wrapper"><i class="fas fa-chart-line"></i></div>
                    <div class="card-title">Seviye Tespit Sonuçları</div>
                    <div class="card-desc">Öğrencilerin seviye tespit sınavı sonuçlarına ait istatistik ve detaylar.</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4">
                <a href="soru-bankasi.php" class="card-box border-orange">
                    <div class="icon-wrapper"><i class="fas fa-book-open"></i></div>
                    <div class="card-title">Soru Bankası</div>
                    <div class="card-desc">Sınav sorularının ekleme, çıkarma ve düzenleme işlemleri.</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4">
                <a href="testler.php" class="card-box border-red">
                    <div class="icon-wrapper"><i class="fas fa-vial"></i></div>
                    <div class="card-title">Testler</div>
                    <div class="card-desc">Hocaların çoktan seçmeli test oluşturma, online uygulama ve 2x2 baskı modülü.</div>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
