<?php
session_start();
// Yetki kontrolü
if(!isset($_SESSION['logged_in']) || $_SESSION['yetki'] != 'admin'){ 
    header("Location: login"); 
    exit; 
}

// BAĞLANTI AYARI (AJAX KLASÖRÜNDEN)
if(file_exists("ajax/connectt.php")) {
    include("ajax/connectt.php");
} else {
    die("Hata: ajax/connectt.php bulunamadı.");
}

mysqli_set_charset($conn, "utf8");

// SİLME İŞLEMİ
if(isset($_GET['sil'])){
    $id = (int)$_GET['sil'];
    mysqli_query($conn, "DELETE FROM tbl_tum_ogrenciler WHERE id=$id");
    header("Location: yonetim-ogrenciler.php");
    exit;
}

// EKLEME İŞLEMİ
$mesaj = "";
if(isset($_POST['btnOgrenciEkle'])){
    $ad = mysqli_real_escape_string($conn, $_POST['ad']);
    $tc = mysqli_real_escape_string($conn, $_POST['tc']);
    $sinif = mysqli_real_escape_string($conn, $_POST['sinif']);
    $veli = mysqli_real_escape_string($conn, $_POST['veli']);
    $tel = mysqli_real_escape_string($conn, $_POST['tel']);
    
    // Veritabanına Ekle
    $sql = "INSERT INTO tbl_tum_ogrenciler (ad_soyad, tc_no, sinif, veli_ad, veli_tel, kayit_tarihi) VALUES ('$ad', '$tc', '$sinif', '$veli', '$tel', CURDATE())";
    
    if(mysqli_query($conn, $sql)){
        $mesaj = '<div class="alert alert-success">Öğrenci başarıyla kaydedildi.</div>';
    } else {
        $mesaj = '<div class="alert alert-danger">Hata: '.mysqli_error($conn).'</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>Öğrenci Yönetimi</title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: sans-serif; }
        .panel-head { padding: 20px; background: #fff; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .card-box { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="panel-head">
    <h4><i class="fas fa-user-graduate"></i> Öğrenci Kayıt Yönetimi</h4>
    <a href="admin-panel" class="btn btn-default"><i class="fas fa-arrow-left"></i> Panele Dön</a>
</div>

<div class="container-fluid" style="padding: 20px;">
    <div class="row">
        
        <div class="col-md-3">
            <div class="card-box">
                <h5 class="text-primary">Yeni Öğrenci Ekle</h5>
                <hr>
                <?php echo $mesaj; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Öğrenci Adı Soyadı</label>
                        <input type="text" name="ad" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>TC Kimlik No</label>
                        <input type="text" name="tc" class="form-control" maxlength="11">
                    </div>
                    <div class="form-group">
                        <label>Sınıf Seviyesi</label>
                        <select name="sinif" class="form-control">
                            <option>2. Sınıf</option> <option>3. Sınıf</option> <option>4. Sınıf</option>
                            <option>5. Sınıf</option> <option>6. Sınıf</option> <option>7. Sınıf</option> <option>8. Sınıf</option>
                            <option>9. Sınıf</option> <option>10. Sınıf</option> <option>11. Sınıf</option> <option>12. Sınıf</option>
                            <option>Mezun</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Veli Adı</label>
                        <input type="text" name="veli" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Veli Telefon</label>
                        <input type="text" name="tel" class="form-control" placeholder="05XX...">
                    </div>
                    <button type="submit" name="btnOgrenciEkle" class="btn btn-success btn-block">KAYDET</button>
                </form>
            </div>
        </div>

        <div class="col-md-9">
            <div class="card-box">
                <h5>Kayıtlı Öğrenciler</h5>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Ad Soyad</th>
                                <th>Sınıf</th>
                                <th>Veli</th>
                                <th>Telefon</th>
                                <th>Kayıt Tarihi</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Verileri Çek
                            $q = mysqli_query($conn, "SELECT * FROM tbl_tum_ogrenciler ORDER BY id DESC LIMIT 50");
                            
                            if(mysqli_num_rows($q) > 0){
                                while($row = mysqli_fetch_assoc($q)){
                                    echo '<tr>
                                        <td>'.$row['id'].'</td>
                                        <td><strong>'.$row['ad_soyad'].'</strong><br><small class="text-muted">TC: '.$row['tc_no'].'</small></td>
                                        <td><span class="badge badge-info">'.$row['sinif'].'</span></td>
                                        <td>'.$row['veli_ad'].'</td>
                                        <td>'.$row['veli_tel'].'</td>
                                        <td>'.$row['kayit_tarihi'].'</td>
                                        <td>
                                            <a href="?sil='.$row['id'].'" onclick="return confirm(\'Bu öğrenci kaydını silmek istediğinize emin misiniz?\')" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i> Sil</a>
                                        </td>
                                    </tr>';
                                }
                            } else {
                                echo '<tr><td colspan="7" class="text-center">Henüz kayıtlı öğrenci yok.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

</body>
</html>