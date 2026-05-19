<?php
require_once __DIR__ . '/auth.php';

setlocale(LC_TIME, 'tr_TR.UTF-8', 'tr_TR', 'Turkish');
$bugun = strftime('%e %B %Y, %A');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>2026/2027 Bursluluk Kampanyası - Gençlik Dil</title>
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
            --info: #06b6d4;
            --purple: #8b5cf6;
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
            background: linear-gradient(135deg, var(--success) 0%, #0ea5e9 100%);
            border-radius: 16px; padding: 28px 34px; color: white; margin-bottom: 30px;
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.2);
            display: flex; justify-content: space-between; align-items: center; position: relative; overflow: hidden;
        }
        .welcome-banner::after { content: "\f0a1"; font-family: "Font Awesome 5 Free"; font-weight: 900; position: absolute; right: 40px; top: -20px; font-size: 150px; opacity: 0.06; transform: rotate(-15deg); }
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
        .border-green { border-left-color: var(--success); }
        .border-green .icon-wrapper { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .border-blue { border-left-color: var(--primary); }
        .border-blue .icon-wrapper { background: rgba(67, 97, 238, 0.1); color: var(--primary); }
        .border-info { border-left-color: var(--info); }
        .border-info .icon-wrapper { background: rgba(6, 182, 212, 0.1); color: var(--info); }
        .border-purple { border-left-color: var(--purple); }
        .border-purple .icon-wrapper { background: rgba(139, 92, 246, 0.1); color: var(--purple); }
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
                <h2>2026/2027 Bursluluk Kampanyası</h2>
                <p>Başvurular, aramalar, görüşmeler ve sınav notları.</p>
            </div>
            <div class="date-badge">
                <i class="far fa-calendar-alt me-2"></i> <?php echo $bugun; ?>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 col-lg-3">
                <a href="basvurular.php" class="card-box border-green">
                    <div class="icon-wrapper"><i class="fas fa-file-signature"></i></div>
                    <div class="card-title">Başvurular</div>
                    <div class="card-desc">Online bursluluk sınavı başvuruları.</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-3">
                <a href="aramalar.php" class="card-box border-blue">
                    <div class="icon-wrapper"><i class="fas fa-headset"></i></div>
                    <div class="card-title">Çağrı Listesi</div>
                    <div class="card-desc">Randevu ve çağrı listesi, CRM yönetimi.</div>
                </a>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <a href="gorusmeler.php" class="card-box border-info">
                    <div class="icon-wrapper"><i class="fas fa-handshake"></i></div>
                    <div class="card-title">Sonuç Bilgilendirme</div>
                    <div class="card-desc">Sonuç bilgilendirmesi ve randevu yönetimi.</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-3">
                <a href="panel/index.php" class="card-box border-purple">
                    <div class="icon-wrapper"><i class="fas fa-graduation-cap"></i></div>
                    <div class="card-title">Sınav Notları</div>
                    <div class="card-desc">Bursluluk sınav not girişi ve sonuç listesi.</div>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
