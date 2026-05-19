<?php
require_once __DIR__ . '/auth.php';
if (file_exists(__DIR__ . '/../ajax/connectt.php')) {
    include __DIR__ . '/../ajax/connectt.php';
} elseif (file_exists(__DIR__ . '/../connectt.php')) {
    include __DIR__ . '/../connectt.php';
}
$admin_base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '\/') . '/';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <base href="<?php echo htmlspecialchars($admin_base); ?>">
    <title>Seviye Tespit Sonuçları - Yönetim</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="resimler/logoGenclik.jpg">
    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css"/>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Montserrat:400,700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Montserrat', sans-serif; }
        .panel-head { padding: 20px; background: #fff; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .card-box { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .filter-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-item { flex: 1; min-width: 150px; }
        .filter-item label { font-weight: bold; font-size: 11px; color: #7f8c8d; margin-bottom: 5px; display: block; text-transform: uppercase; }
        .table-container { overflow-x: auto; }
        table { font-size: 13px; }
        thead { background-color: #8e44ad; color: white; }
        th { font-weight: 500; white-space: nowrap; padding: 12px !important; }
        td { vertical-align: middle !important; padding: 10px !important; }
        .badge-level { font-size: 11px; padding: 4px 8px; border-radius: 4px; color: white; display: inline-block; min-width: 35px; text-align: center;}
        .bg-A1 { background-color: #95a5a6; }
        .bg-A2 { background-color: #f1c40f; color: #333; }
        .bg-B1 { background-color: #e67e22; }
        .bg-B2 { background-color: #e74c3c; }
        .bg-C1 { background-color: #c0392b; }
        .bg-C2 { background-color: #8e44ad; }
    </style>
</head>
<body>

    <div class="panel-head">
        <h4><i class="fas fa-chart-line"></i> Seviye Tespit Sonuçları</h4>
        <a href="admin/index.php" class="btn btn-default"><i class="fas fa-arrow-left"></i> Panele Dön</a>
    </div>

    <div class="container-fluid" style="padding: 20px;">
        <div class="card-box">
            <h5 class="text-primary" style="margin-bottom: 15px; color: #8e44ad !important;"><i class="fas fa-filter"></i> Detaylı Arama</h5>
            <div class="filter-row">
                <div class="filter-item">
                    <label>AD SOYAD / TEL</label>
                    <input type="text" id="araAd" class="form-control" placeholder="Öğrenci bilgisi...">
                </div>
                <div class="filter-item">
                    <label>ŞEHİR</label>
                    <input type="text" id="araSehir" class="form-control" placeholder="Şehir...">
                </div>
                <div class="filter-item">
                    <label>DİL</label>
                    <select id="araDil" class="form-control">
                        <option value="">Tümü</option>
                        <option value="Ingilizce">İngilizce</option>
                        <option value="Almanca">Almanca</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>SEVİYE SONUCU</label>
                    <select id="araSeviye" class="form-control">
                        <option value="">Tümü</option>
                        <option value="A1">A1 (Başlangıç)</option>
                        <option value="A2">A2 (Temel)</option>
                        <option value="B1">B1 (Orta)</option>
                        <option value="B2">B2 (Orta Üstü)</option>
                        <option value="C1">C1 (İleri)</option>
                        <option value="C2">C2 (Profesyonel)</option>
                    </select>
                </div>
                <div class="filter-item" style="flex: 0 0 auto;">
                    <button class="btn btn-block" style="background-color: #8e44ad; color: white;" onclick="doldurSeviye()"><i class="fas fa-search"></i> ARA</button>
                </div>
                <div class="filter-item" style="flex: 0 0 auto;">
                    <button class="btn btn-success btn-block" onclick="excelIndir()"><i class="fas fa-file-excel"></i> EXCEL İNDİR</button>
                </div>
            </div>
        </div>
        <div class="card-box">
            <div class="table-container" id="tabloSonuclar">
                <div class="text-center" style="padding: 40px; color: #777;">
                    <i class="fas fa-spinner fa-spin fa-3x"></i><br>Veriler Yükleniyor...
                </div>
            </div>
        </div>
    </div>

    <script src="js/jquery-3.2.0.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/seviye-yonetim.js?v=1.4"></script>
    <script>
        function excelIndir() {
            var ad = $("#araAd").val();
            var sehir = $("#araSehir").val();
            var dil = $("#araDil").val();
            var seviye = $("#araSeviye").val();
            var url = "ajax/exportSeviye.php?ogrenciAd=" + encodeURIComponent(ad) +
                "&sehir=" + encodeURIComponent(sehir) +
                "&dil=" + encodeURIComponent(dil) +
                "&seviye=" + encodeURIComponent(seviye);
            window.location.href = url;
        }
    </script>
</body>
</html>
