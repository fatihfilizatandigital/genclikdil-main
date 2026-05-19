<?php
session_start();
// Veritabanı bağlantısı
if(file_exists("ajax/connectt.php")) include("ajax/connectt.php");
else if(file_exists("../ajax/connectt.php")) include("../ajax/connectt.php");

$error = "";

if(isset($_POST['username'])){
    // Güvenlik önlemleri
    $user = mysqli_real_escape_string($conn, $_POST['username']);
    $pass = md5($_POST['password']); // MD5 şifreleme kullandık (SQL'de de öyle oluşturduk)
    
    // Kullanıcıyı veritabanında ara
    $sql = "SELECT * FROM tbl_ogretmenler WHERE kullanici_adi = '$user' AND sifre = '$pass' AND durum = 1";
    $result = mysqli_query($conn, $sql);
    
    if(mysqli_num_rows($result) > 0){
        $row = mysqli_fetch_assoc($result);
        
        // Session Değişkenlerini Ata
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['ad_soyad'] = $row['ad_soyad'];
        $_SESSION['yetki'] = $row['yetki']; // 'admin' veya 'ogretmen'
        
        // Yetkiye göre yönlendirme
        if($row['yetki'] == 'admin'){
            $_SESSION['admin_logged_in'] = true; // Eski kodların bozulmaması için
            header("Location: admin-panel");
        } else {
            header("Location: ogretmen-panel"); // Henüz yapmadık, bir sonraki adımda yapacağız
        }
        exit;
    } else {
        $error = "Hatalı kullanıcı adı veya şifre!";
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>Giriş Yap - Gençlik Dil</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="resimler/logoGenclik.jpg">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css?family=Montserrat:400,700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; display: flex; align-items: center; justify-content: center; height: 100vh; font-family: 'Montserrat', sans-serif; }
        .login-card { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .btn-custom { background-color: #3498db; color: white; width: 100%; font-weight: bold; transition: 0.3s; }
        .btn-custom:hover { background-color: #2980b9; color: white; }
        .logo-area { text-align: center; margin-bottom: 20px; }
        .logo-area img { height: 80px; }
        .form-control { height: 45px; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-area">
            <img src="resimler/logoGenclik.jpg" alt="Logo">
            <h4 style="margin-top:15px; color:#2c3e50; font-weight:bold;">Personel Girişi</h4>
        </div>
        
        <?php if($error != "") { echo '<div class="alert alert-danger text-center">'.$error.'</div>'; } ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Kullanıcı Adı</label>
                <input type="text" name="username" class="form-control" placeholder="Kullanıcı adınız" required autofocus>
            </div>
            <div class="form-group">
                <label>Şifre</label>
                <input type="password" name="password" class="form-control" placeholder="******" required>
            </div>
            <button type="submit" class="btn btn-custom">GİRİŞ YAP</button>
        </form>
        
        <div class="text-center" style="margin-top:20px;">
            <a href="/" style="font-size:13px; color:#777; text-decoration:none;">
                <i class="glyphicon glyphicon-arrow-left"></i> Ana Sayfaya Dön
            </a>
        </div>
    </div>
</body>
</html>