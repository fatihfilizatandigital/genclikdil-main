<?php
session_start();
if(!isset($_SESSION['logged_in']) || $_SESSION['yetki'] != 'admin'){ header("Location: login"); exit; }

include("ajax/connectt.php");
mysqli_set_charset($conn, "utf8");

// ÖĞRETMEN EKLEME / DÜZENLEME İŞLEMİ
$mesaj = "";
if(isset($_POST['btnKaydet'])){
    $ad = mysqli_real_escape_string($conn, $_POST['ad_soyad']);
    $user = mysqli_real_escape_string($conn, $_POST['kullanici_adi']);
    $brans = mysqli_real_escape_string($conn, $_POST['brans']);
    $pass = $_POST['sifre'];
    
    // Şifre alanı boşsa güncelleme yaparken eski şifreyi koru mantığı eklenebilir ama 
    // şimdilik basit tutup yeni ekleme yapalım.
    if(!empty($pass)){
        $passMd5 = md5($pass);
        $sql = "INSERT INTO tbl_ogretmenler (ad_soyad, kullanici_adi, sifre, brans, yetki) VALUES ('$ad', '$user', '$passMd5', '$brans', 'ogretmen')";
        if(mysqli_query($conn, $sql)){
            $mesaj = '<div class="alert alert-success">Öğretmen başarıyla eklendi.</div>';
        } else {
            $mesaj = '<div class="alert alert-danger">Hata: '.mysqli_error($conn).'</div>';
        }
    }
}

// ÖĞRETMEN SİLME
if(isset($_GET['sil_id'])){
    $silId = (int)$_GET['sil_id'];
    mysqli_query($conn, "DELETE FROM tbl_ogretmenler WHERE id=$silId AND yetki!='admin'"); // Admin kendini silemesin
    header("Location: yonetim-ogretmenler.php");
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>Öğretmen Yönetimi - Gençlik Dil</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; }
        .panel-head { padding: 20px; background: #fff; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .table-con { padding: 20px; }
        .card-form { background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

    <div class="panel-head">
        <h4><i class="fas fa-chalkboard-teacher"></i> Öğretmen Yönetimi</h4>
        <a href="admin-panel" class="btn btn-default"><i class="fas fa-arrow-left"></i> Panele Dön</a>
    </div>

    <div class="container-fluid table-con">
        <div class="row">
            
            <div class="col-md-4">
                <div class="card-form">
                    <h5>Yeni Öğretmen Ekle</h5>
                    <hr>
                    <?php echo $mesaj; ?>
                    <form method="POST">
                        <div class="form-group">
                            <label>Adı Soyadı</label>
                            <input type="text" name="ad_soyad" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Branş (Örn: İngilizce)</label>
                            <input type="text" name="brans" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Kullanıcı Adı (Giriş için)</label>
                            <input type="text" name="kullanici_adi" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Şifre</label>
                            <input type="text" name="sifre" class="form-control" required>
                        </div>
                        <button type="submit" name="btnKaydet" class="btn btn-primary btn-block">KAYDET</button>
                    </form>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card-form">
                    <table class="table table-hover table-striped">
                        <thead class="thead-dark">
                            <tr>
                                <th>Ad Soyad</th>
                                <th>Branş</th>
                                <th>Kullanıcı Adı</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $res = mysqli_query($conn, "SELECT * FROM tbl_ogretmenler WHERE yetki='ogretmen' ORDER BY id DESC");
                            while($row = mysqli_fetch_assoc($res)){
                                echo '<tr>
                                    <td>'.$row['ad_soyad'].'</td>
                                    <td>'.$row['brans'].'</td>
                                    <td>'.$row['kullanici_adi'].'</td>
                                    <td>
                                        <a href="?sil_id='.$row['id'].'" onclick="return confirm(\'Bu öğretmeni silmek istediğinize emin misiniz?\')" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i> Sil</a>
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

</body>
</html>