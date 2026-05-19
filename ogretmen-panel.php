<?php
session_start();
// YETKİ KONTROLÜ: Sadece öğretmenler girebilir
if(!isset($_SESSION['logged_in']) || $_SESSION['yetki'] != 'ogretmen'){
    // Eğer admin ise admin panele yönlendir, değilse logine at
    if(isset($_SESSION['yetki']) && $_SESSION['yetki'] == 'admin'){
        header("Location: admin-panel");
    } else {
        header("Location: login");
    }
    exit;
}

// EVRENSEL BAĞLANTI BLOĞU
if(file_exists("ajax/connectt.php")) include("ajax/connectt.php");
elseif(file_exists("connectt.php")) include("connectt.php");
else die("Hata: Bağlantı dosyası bulunamadı.");

mysqli_set_charset($conn, "utf8");

// Giriş yapan öğretmenin ID'si
$myID = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>Öğretmen Paneli - Gençlik Dil</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Montserrat:400,700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Montserrat', sans-serif; }
        .panel-head { background: #fff; padding: 15px 30px; border-bottom: 2px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .card-box { background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 30px; transition: transform 0.3s; }
        .card-box:hover { transform: translateY(-5px); }
        .welcome-text { font-size: 24px; color: #2c3e50; font-weight: bold; }
        
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 30px; }
        .menu-item { background: #3498db; color: white; padding: 30px; border-radius: 10px; text-align: center; text-decoration: none; font-size: 18px; font-weight: bold; }
        .menu-item:hover { background: #2980b9; color: white; text-decoration: none; box-shadow: 0 10px 20px rgba(52, 152, 219, 0.3); }
        .menu-item i { display: block; font-size: 40px; margin-bottom: 10px; }
        
        /* Renkler */
        .bg-purple { background: #9b59b6; } .bg-purple:hover { background: #8e44ad; }
        .bg-orange { background: #e67e22; } .bg-orange:hover { background: #d35400; }
    </style>
</head>
<body>

    <div class="panel-head">
        <div class="logo-area">
            <span style="font-weight: bold; font-size: 18px; color:#2c3e50;">ÖĞRETMEN PANELİ</span>
        </div>
        <div class="user-info">
            <span style="margin-right: 15px;">Merhaba, <strong><?php echo $_SESSION['ad_soyad']; ?></strong></span>
            <a href="logout" class="btn btn-danger btn-sm"><i class="fas fa-sign-out-alt"></i> Çıkış</a>
        </div>
    </div>

    <div class="container" style="padding: 40px 15px;">
        
        <div class="row">
            <div class="col-md-12">
                <div class="welcome-text">Hoşgeldiniz Hocam! 👋</div>
                <p style="color:#7f8c8d;">Bugün öğrencilerimiz için ne yapmak istersiniz?</p>
            </div>
        </div>

        <div class="menu-grid">
            <a href="soru-bankasi" class="menu-item bg-purple">
                <i class="fas fa-question-circle"></i>
                Soru Bankası Yönetimi
            </a>
            
            <a href="#programim" class="menu-item bg-orange">
                <i class="fas fa-calendar-alt"></i>
                Haftalık Ders Programım
            </a>
        </div>

        <hr style="margin: 40px 0;">

        <div id="programim" class="card-box">
            <h4 style="border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; color: #e67e22;">
                <i class="fas fa-calendar-check"></i> Haftalık Ders Programınız
            </h4>
            
            <div class="table-responsive">
                <table class="table table-bordered table-hover text-center">
                    <thead class="thead-light">
                        <tr>
                            <th>Gün</th>
                            <th>Saat</th>
                            <th>Sınıf</th>
                            <th>Konu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Sadece giriş yapan öğretmenin programını çekiyoruz
                        $sql = "SELECT * FROM tbl_ders_programi 
                                WHERE ogretmen_id = $myID 
                                ORDER BY FIELD(gun, 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'), baslangic_saat ASC";
                        
                        $result = mysqli_query($conn, $sql);
                        
                        if(mysqli_num_rows($result) > 0){
                            while($row = mysqli_fetch_assoc($result)){
                                echo '<tr>
                                    <td style="font-weight:bold;">'.$row['gun'].'</td>
                                    <td>'.$row['baslangic_saat'].' - '.$row['bitis_saat'].'</td>
                                    <td><span class="badge badge-info" style="font-size:14px;">'.$row['sinif'].'</span></td>
                                    <td>'.$row['konu'].'</td>
                                </tr>';
                            }
                        } else {
                            echo '<tr><td colspan="4" style="padding:30px; color:#999;">Henüz size atanmış bir ders programı bulunmuyor.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</body>
</html>