<?php
require_once __DIR__ . '/auth.php';
$admin_base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '\/') . '/';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <base href="<?php echo htmlspecialchars($admin_base); ?>">
    <title>Bursluluk Başvuruları - Yönetim</title>
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
        .filter-item label { font-weight: bold; font-size: 12px; color: #7f8c8d; margin-bottom: 5px; display: block; }
        .table-container { overflow-x: auto; }
        table { font-size: 13px; }
        thead { background-color: #34495e; color: white; }
        th { font-weight: 500; white-space: nowrap; padding: 12px !important; }
        td { vertical-align: middle !important; padding: 10px !important; }
    </style>
</head>
<body>

    <div class="panel-head">
        <h4><i class="fas fa-file-signature"></i> Bursluluk Başvuruları</h4>
        <a href="admin/index.php" class="btn btn-default"><i class="fas fa-arrow-left"></i> Panele Dön</a>
    </div>

    <div class="container-fluid" style="padding: 20px;">
        <div class="card-box">
            <h5 class="text-primary" style="margin-bottom: 15px;"><i class="fas fa-filter"></i> Arama Kriterleri</h5>
            <div class="filter-row">
                <div class="filter-item">
                    <label>ÖĞRENCİ ADI</label>
                    <input type="text" id="araAd" class="form-control" placeholder="Ad...">
                </div>
                <div class="filter-item">
                    <label>ÖĞRENCİ SOYADI</label>
                    <input type="text" id="araSoyad" class="form-control" placeholder="Soyad...">
                </div>
                <div class="filter-item">
                    <label>TC KİMLİK NO</label>
                    <input type="text" id="araTC" class="form-control" placeholder="TC No..." maxlength="14">
                </div>
                <div class="filter-item">
                    <label>TELEFON</label>
                    <input type="text" id="araNumara" class="form-control" placeholder="Telefon..." maxlength="16">
                </div>
                <div class="filter-item">
                    <label>SINAV TÜRÜ</label>
                    <select id="araSinav" class="form-control">
                        <option value="">Tümü</option>
                        <option value="Ingilizce">İngilizce</option>
                        <option value="Almanca">Almanca</option>
                        <option value="Her Ikisi">Her İkisi</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>SINIF</label>
                    <select id="araSinif" class="form-control">
                        <option value="">Tümü</option>
                        <option value="1">1. Sınıf</option>
                        <option value="2">2. Sınıf</option>
                        <option value="3">3. Sınıf</option>
                        <option value="4">4. Sınıf</option>
                        <option value="5">5. Sınıf</option>
                        <option value="6">6. Sınıf</option>
                        <option value="7">7. Sınıf</option>
                        <option value="8">8. Sınıf</option>
                        <option value="9">9. Sınıf</option>
                        <option value="10">10. Sınıf</option>
                        <option value="11">11. Sınıf</option>
                        <option value="12">12. Sınıf</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>KATILIM DURUMU</label>
                    <select id="araKatilim" class="form-control" style="border-color:#3498db; color:#3498db; font-weight:bold;">
                        <option value="">Tümü</option>
                        <option value="1">Sadece Gelenler</option>
                        <option value="0">Gelmeyenler</option>
                    </select>
                </div>
                <div class="filter-item" style="flex: 0 0 auto;">
                    <button class="btn btn-primary btn-block" onclick="doldurBasvuru()"><i class="fas fa-search"></i> LİSTELE</button>
                </div>
                <div class="filter-item" style="flex: 0 0 auto;">
                    <button class="btn btn-success btn-block" onclick="excelIndir()"><i class="fas fa-file-excel"></i> EXCEL</button>
                </div>
            </div>
        </div>
        <div class="card-box">
            <div class="table-container basvurular">
                <div class="text-center" style="padding:40px; color:#999;">
                    <i class="fas fa-spinner fa-spin fa-3x"></i><br>Veriler Yükleniyor...
                </div>
            </div>
        </div>
    </div>

    <!-- Düzenleme modal -->
    <div class="modal fade" id="basvuruDuzenleModal" tabindex="-1" role="dialog" aria-labelledby="basvuruDuzenleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="basvuruDuzenleModalLabel"><i class="fas fa-edit"></i> Başvuru Düzenle</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Kapat"><span aria-hidden="true">&times;</span></button>
                </div>
                <form id="basvuruDuzenleForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Öğrenci Adı</label>
                                    <input type="text" name="Ad" id="edit_Ad" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Öğrenci Soyadı</label>
                                    <input type="text" name="Soyad" id="edit_Soyad" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>TC Kimlik No</label>
                                    <input type="text" name="TC" id="edit_TC" class="form-control" maxlength="14">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Sınıf</label>
                                    <select name="Sinif" id="edit_Sinif" class="form-control" required>
                                        <?php for ($i = 1; $i <= 12; $i++) echo '<option value="'.$i.'">'.$i.'. Sınıf</option>'; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Doğum Tarihi</label>
                                    <input type="text" name="Dogum" id="edit_Dogum" class="form-control" placeholder="GG.AA.YYYY">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Veli Adı</label>
                                    <input type="text" name="VeliAd" id="edit_VeliAd" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Veli Soyadı</label>
                                    <input type="text" name="VeliSoyad" id="edit_VeliSoyad" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Veli Telefon 1</label>
                                    <input type="text" name="VeliTel1" id="edit_VeliTel1" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Veli Telefon 2</label>
                                    <input type="text" name="VeliTel2" id="edit_VeliTel2" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Veli E-posta</label>
                                    <input type="email" name="VeliEmail" id="edit_VeliEmail" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Okul</label>
                                    <input type="text" name="Okul" id="edit_Okul" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Cinsiyet</label>
                                    <input type="text" name="Cinsiyet" id="edit_Cinsiyet" class="form-control" placeholder="E/K">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Veli Meslek</label>
                                    <input type="text" name="VeliMeslek" id="edit_VeliMeslek" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Şube</label>
                                    <input type="text" name="Sube" id="edit_Sube" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label>Sınav Türü</label>
                                    <select name="SinavTuru" id="edit_SinavTuru" class="form-control">
                                        <option value="Ingilizce">İngilizce</option>
                                        <option value="Almanca">Almanca</option>
                                        <option value="Her Ikisi">Her İkisi</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="js/jquery-3.2.0.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="basvuru.js?v=3.2"></script>
    <script>
        $(document).ready(function() {
            $('#araTC').on('input', function() {
                var value = $(this).val().replace(/\D/g, '');
                if (value.length > 11) value = value.substring(0, 11);
                if (value.length > 0) {
                    if (value.length <= 3) value = value;
                    else if (value.length <= 7) value = value.substring(0, 3) + ' ' + value.substring(3);
                    else value = value.substring(0, 3) + ' ' + value.substring(3, 7) + ' ' + value.substring(7, 11);
                    $(this).val(value);
                }
            });
            $('#araNumara').on('input', function() {
                var value = $(this).val().replace(/\D/g, '');
                if (value.length > 11) value = value.substring(0, 11);
                if (value.length > 0) {
                    if (value.charAt(0) !== '0') { value = '0' + value; if (value.length > 11) value = value.substring(0, 11); }
                    if (value.length <= 1) value = value;
                    else if (value.length <= 4) value = value.substring(0, 1) + ' ' + value.substring(1);
                    else if (value.length <= 7) value = value.substring(0, 1) + ' ' + value.substring(1, 4) + ' ' + value.substring(4);
                    else value = value.substring(0, 1) + ' ' + value.substring(1, 4) + ' ' + value.substring(4, 7) + ' ' + value.substring(7, 11);
                    $(this).val(value);
                }
            });
        });
        function excelIndir() {
            var params = $.param({
                ogrenciAd: $("#araAd").val().trim(),
                ogrenciSoyad: $("#araSoyad").val().trim(),
                ogrenciTC: $("#araTC").val().trim().replace(/\s/g, ''),
                ogrenciNumara: $("#araNumara").val().trim().replace(/\s/g, ''),
                sinav: $("#araSinav").val(),
                sinif: $("#araSinif").val(),
                katilim: $("#araKatilim").val()
            });
            window.location.href = "ajax/exportBasvurular.php?v=3.0&" + params;
        }
    </script>
</body>
</html>
