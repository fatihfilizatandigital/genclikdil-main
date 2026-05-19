<?php
session_start();
$servername = "213.142.130.21:3306";
$username = "adnan";
$password = "adnan.1234";
$dbname = "farmMysql";

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) { die("Bağlantı Hatası"); }

$hata = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $kadi = mysqli_real_escape_string($conn, $_POST['kadi']);
    $sifre = mysqli_real_escape_string($conn, $_POST['sifre']);

    // Kullanıcıyı kontrol et
    $sql = "SELECT * FROM kullanicilar WHERE kadi = '$kadi' AND sifre = '$sifre'";
    $result = mysqli_query($conn, $sql);

    if (mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $_SESSION['personel_adi'] = $row['ad_soyad'];
        $_SESSION['personel_id'] = $row['id'];
        $_SESSION['kadi'] = isset($row['kadi']) ? $row['kadi'] : '';
        $_SESSION['giris_yapildi'] = true;
        // Tabloda yetki yoksa: kadi "admin" ise admin, değilse personel
        $_SESSION['yetki'] = isset($row['yetki']) && $row['yetki'] !== '' ? $row['yetki'] : (strtolower(trim($row['kadi'] ?? '')) === 'admin' ? 'admin' : 'personel');
        header("Location: admin/");
        exit;
    } else {
        $hata = "❌ Kullanıcı adı veya şifre hatalı!";
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-card { width: 100%; max-width: 400px; border-radius: 15px; }
        .card-header { background: #0d6efd; color: white; text-align: center; padding: 20px; }
    </style>
</head>
<body>

<div class="card login-card shadow-lg">
    <div class="card-header">
        <h4>🔐 Personel Girişi</h4>
    </div>
    <div class="card-body p-4">
        <?php if($hata): ?><div class="alert alert-danger"><?= $hata ?></div><?php endif; ?>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Kullanıcı Adı:</label>
                <input type="text" name="kadi" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Şifre:</label>
                <input type="password" name="sifre" class="form-control" required>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">Giriş Yap</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>