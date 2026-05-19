<?php
session_start();
// Yetki kontrolü
if(!isset($_SESSION['logged_in']) || $_SESSION['yetki'] != 'admin'){ 
    header("Location: login"); 
    exit; 
}

// BAĞLANTI AYARI
if(file_exists("ajax/connectt.php")) {
    include("ajax/connectt.php");
} else {
    die("Hata: ajax/connectt.php bulunamadı.");
}

mysqli_set_charset($conn, "utf8");

// DERS SİLME
if(isset($_GET['sil'])){
    $id = (int)$_GET['sil'];
    $ogrID = (int)$_GET['ogr_id']; // Silince aynı öğretmenin sayfasında kalmak için
    mysqli_query($conn, "DELETE FROM tbl_ders_programi WHERE id=$id");
    header("Location: yonetim-ders-programi.php?ogr_id=".$ogrID);
    exit;
}

// DERS EKLEME
if(isset($_POST['btnDersEkle'])){
    $ogretmen_id = $_POST['ogretmen_id'];
    $gun = $_POST['gun'];
    $baslangic = $_POST['baslangic'];
    $bitis = $_POST['bitis'];
    $sinif = $_POST['sinif'];
    $konu = mysqli_real_escape_string($conn, $_POST['konu']);

    $sql = "INSERT INTO tbl_ders_programi (ogretmen_id, gun, baslangic_saat, bitis_saat, sinif, konu) 
            VALUES ('$ogretmen_id', '$gun', '$baslangic', '$bitis', '$sinif', '$konu')";
    
    mysqli_query($conn, $sql);
    // Sayfa yenilenince form tekrar gitmesin diye yönlendirme
    header("Location: yonetim-ders-programi.php?ogr_id=".$ogretmen_id); 
    exit;
}

// URL'den seçili öğretmeni al
$secili_ogretmen = isset($_GET['ogr_id']) ? (int)$_GET['ogr_id'] : 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>Ders Programı Yönetimi</title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: sans-serif; }
        .panel-head { padding: 20px; background: #fff; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .card-box { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

<div class="panel-head">
    <h4><i class="fas fa-calendar-alt"></i> Ders Programı Oluştur</h4>
    <a href="admin-panel" class="btn btn-default"><i class="fas fa-arrow-left"></i> Panele Dön</a>
</div>

<div class="container-fluid" style="padding: 20px;">
    
    <div class="row">
        <div class="col-md-12">
            <div class="card-box" style="margin-bottom: 20px;">
                <form method="POST" class="form-inline">
                    
                    <label style="margin-right: 10px; font-weight:bold;">Öğretmen:</label>
                    <select name="ogretmen_id" class="form-control" style="margin-right: 15px; width: 200px;" required>
                        <option value="">Seçiniz...</option>
                        <?php
                        $ogrSorgu = mysqli_query($conn, "SELECT * FROM tbl_ogretmenler WHERE yetki='ogretmen'");
                        while($ogr = mysqli_fetch_assoc($ogrSorgu)){
                            $selected = ($secili_ogretmen == $ogr['id']) ? 'selected' : '';
                            echo '<option value="'.$ogr['id'].'" '.$selected.'>'.$ogr['ad_soyad'].'</option>';
                        }
                        ?>
                    </select>

                    <label style="margin-right: 5px;">Gün:</label>
                    <select name="gun" class="form-control" style="margin-right: 15px; width: 120px;">
                        <option>Pazartesi</option><option>Salı</option><option>Çarşamba</option>
                        <option>Perşembe</option><option>Cuma</option><option>Cumartesi</option><option>Pazar</option>
                    </select>

                    <label style="margin-right: 5px;">Saat:</label>
                    <input type="time" name="baslangic" class="form-control" style="margin-right: 5px;" required>
                    <span style="margin-right: 5px;">-</span>
                    <input type="time" name="bitis" class="form-control" style="margin-right: 15px;" required>
                    
                    <input type="text" name="sinif" class="form-control" placeholder="Sınıf (Örn: 8-A)" style="margin-right: 5px; width: 120px;" required>
                    <input type="text" name="konu" class="form-control" placeholder="Ders/Konu" style="margin-right: 10px;">
                    
                    <button type="submit" name="btnDersEkle" class="btn btn-primary"><i class="fas fa-plus"></i> EKLE</button>
                </form>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="list-group">
                <a href="#" class="list-group-item active">Öğretmen Seçin</a>
                <?php
                // Pointer'ı başa alıp tekrar sorgulayalım
                mysqli_data_seek($ogrSorgu, 0); 
                while($ogr = mysqli_fetch_assoc($ogrSorgu)){
                    $aktifClass = ($secili_ogretmen == $ogr['id']) ? 'list-group-item-warning' : '';
                    echo '<a href="?ogr_id='.$ogr['id'].'" class="list-group-item '.$aktifClass.'">
                            '.$ogr['ad_soyad'].' 
                            <i class="fas fa-chevron-right pull-right" style="float:right; margin-top:3px;"></i>
                          </a>';
                }
                ?>
            </div>
        </div>

        <div class="col-md-9">
            <div class="card-box">
                <?php if($secili_ogretmen > 0): 
                    $isimSorgu = mysqli_query($conn, "SELECT ad_soyad FROM tbl_ogretmenler WHERE id=$secili_ogretmen");
                    $isimBul = mysqli_fetch_assoc($isimSorgu);
                ?>
                    <h4 class="text-danger" style="margin-bottom: 20px;"><?php echo $isimBul['ad_soyad']; ?> - Ders Programı</h4>
                    
                    <table class="table table-bordered text-center table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>Gün</th>
                                <th>Saat Aralığı</th>
                                <th>Sınıf</th>
                                <th>Konu</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Günleri ve saatleri sıralı getirmek için
                            $dersler = mysqli_query($conn, "SELECT * FROM tbl_ders_programi 
                                WHERE ogretmen_id=$secili_ogretmen 
                                ORDER BY FIELD(gun, 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'), baslangic_saat ASC");
                            
                            if(mysqli_num_rows($dersler) > 0){
                                while($ders = mysqli_fetch_assoc($dersler)){
                                    echo '<tr>
                                        <td style="font-weight:bold;">'.$ders['gun'].'</td>
                                        <td>'.$ders['baslangic_saat'].' - '.$ders['bitis_saat'].'</td>
                                        <td><span class="badge badge-primary" style="font-size:14px;">'.$ders['sinif'].'</span></td>
                                        <td>'.$ders['konu'].'</td>
                                        <td>
                                            <a href="?sil='.$ders['id'].'&ogr_id='.$secili_ogretmen.'" class="btn btn-danger btn-xs" onclick="return confirm(\'Dersi silmek istiyor musunuz?\')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>';
                                }
                            } else {
                                echo '<tr><td colspan="5" class="text-muted" style="padding:30px;">Bu öğretmene ait ders programı henüz eklenmemiş.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>

                <?php else: ?>
                    <div class="alert alert-info text-center" style="margin-top:20px;">
                        <i class="fas fa-arrow-left"></i> Programını görmek veya düzenlemek istediğiniz öğretmeni soldaki listeden seçiniz.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

</body>
</html>