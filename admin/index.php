<?php
require_once __DIR__ . '/auth.php';

// Türkçe tarih formatı için
setlocale(LC_TIME, 'tr_TR.UTF-8', 'tr_TR', 'Turkish');
$bugun = strftime('%e %B %Y, %A');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>Yönetim Paneli - Gençlik Dil</title>
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
            --info: #06b6d4;
            --purple: #8b5cf6;
            --dark: #1e293b;
            --bg-color: #f1f5f9;
            --text-muted: #64748b;
        }

        body { 
            background-color: var(--bg-color); 
            font-family: 'Montserrat', sans-serif; 
            color: #333;
        }

        /* Üst Menü */
        .admin-header { 
            background: #ffffff; 
            padding: 12px 30px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.03); 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .logo-area { display: flex; align-items: center; gap: 15px; }
        .logo-area img { height: 45px; border-radius: 8px; }
        .logo-area span { font-weight: 700; font-size: 18px; color: var(--dark); letter-spacing: 0.5px; }
        
        .user-actions { display: flex; align-items: center; gap: 15px; }
        .user-profile { font-weight: 500; color: var(--dark); font-size: 14px; }
        .user-profile strong { color: var(--primary); }

        .dashboard-container { max-width: 1200px; margin: 0 auto; padding: 30px 15px; }

        /* Karşılama Alanı (Hero) */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary) 0%, #3a0ca3 100%);
            border-radius: 16px;
            padding: 35px 40px;
            color: white;
            margin-bottom: 40px;
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        .welcome-banner::after {
            content: "\f0e4";
            font-family: "Font Awesome 5 Free";
            font-weight: 900;
            position: absolute;
            right: 40px;
            top: -20px;
            font-size: 150px;
            opacity: 0.05;
            transform: rotate(-15deg);
        }
        .welcome-text h2 { font-weight: 700; font-size: 26px; margin-bottom: 8px; }
        .welcome-text p { font-size: 15px; opacity: 0.85; margin: 0; }
        .date-badge { background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 30px; font-size: 14px; font-weight: 500; backdrop-filter: blur(5px); }

        /* Modül Kartları */
        .card-box {
            background: #ffffff; 
            border-radius: 16px; 
            padding: 25px; 
            margin-bottom: 25px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); 
            display: block; 
            text-decoration: none; 
            color: inherit;
            border-left: 4px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            height: calc(100% - 25px);
        }
        .card-box:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); 
            text-decoration: none; 
            color: inherit; 
        }

        .icon-wrapper {
            width: 55px; height: 55px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }
        .card-box:hover .icon-wrapper { transform: scale(1.1); }
        
        .card-title { font-size: 17px; font-weight: 700; color: var(--dark); margin-bottom: 8px; }
        .card-desc { font-size: 13px; color: var(--text-muted); line-height: 1.5; }

        /* Kart Renk Temaları */
        .border-green { border-left-color: var(--success); }
        .border-green .icon-wrapper { background: rgba(16, 185, 129, 0.1); color: var(--success); }

        .border-red { border-left-color: var(--danger); }
        .border-red .icon-wrapper { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .border-purple { border-left-color: var(--purple); }
        .border-purple .icon-wrapper { background: rgba(139, 92, 246, 0.1); color: var(--purple); }

        .border-blue { border-left-color: var(--primary); }
        .border-blue .icon-wrapper { background: rgba(67, 97, 238, 0.1); color: var(--primary); }

        .border-orange { border-left-color: var(--warning); }
        .border-orange .icon-wrapper { background: rgba(245, 158, 11, 0.1); color: var(--warning); }

        .border-info { border-left-color: var(--info); }
        .border-info .icon-wrapper { background: rgba(6, 182, 212, 0.1); color: var(--info); }

        /* Butonlar */
        .btn-custom { border-radius: 8px; font-weight: 500; font-size: 13px; padding: 8px 16px; transition: all 0.2s; }
        .btn-outline-light-custom { border: 1px solid #e2e8f0; color: var(--text-muted); background: white; }
        .btn-outline-light-custom:hover { background: #f8fafc; color: var(--dark); }
    </style>
</head>
<body>

    <!-- Üst Menü -->
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
        
        <!-- Karşılama Panosu -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <h2>İyi Çalışmalar, <?php echo htmlspecialchars($_SESSION['personel_adi'] ?? 'Yönetici'); ?>!</h2>
                <p>Gençlik Dil Yönetim Paneline hoş geldiniz. Buradan tüm süreçleri yönetebilirsiniz.</p>
            </div>
            <div class="date-badge">
                <i class="far fa-calendar-alt me-2"></i> <?php echo $bugun; ?>
            </div>
        </div>

        <!-- Modül Izgarası -->
        <div class="row">
            <div class="col-md-6 col-lg-4">
                <a href="bursluluk_kampanya.php" class="card-box border-green">
                    <div class="icon-wrapper"><i class="fas fa-bullhorn"></i></div>
                    <div class="card-title">2026/2027 Bursluluk Kampanyası</div>
                    <div class="card-desc">Başvurular, aramalar, görüşmeler ve sınav notları modüllerine buradan erişin.</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4">
                <a href="seviye_tespit.php" class="card-box border-red">
                    <div class="icon-wrapper"><i class="fas fa-chart-line"></i></div>
                    <div class="card-title">Seviye Tespit</div>
                    <div class="card-desc">Seviye tespit sonuçları ve soru bankası.</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4">
                <a href="yaz_kampanyasi.php" class="card-box border-orange">
                    <div class="icon-wrapper"><i class="fas fa-sun"></i></div>
                    <div class="card-title">2026/2027 Yaz Dönemi Kampanyası</div>
                    <div class="card-desc">Yaz dönemi başvurularını yönetin.</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4">
                <a href="erken_kayit_kampanyasi.php" class="card-box border-info">
                    <div class="icon-wrapper"><i class="fas fa-bolt"></i></div>
                    <div class="card-title">Erken Kayıt Kampanyası</div>
                    <div class="card-desc">Online ödeme başvuruları ve iletişim taleplerini yönetin.</div>
                </a>
            </div>
        </div>
    </div>

</body>
</html>