$(document).ready(function() {
    // Sayfa açıldığında verileri getir
    doldurSeviye();

    // Inputlarda ENTER tuşuna basınca arama yap
    $('#araAd, #araSehir').keypress(function(event){
        var keycode = (event.keyCode ? event.keyCode : event.which);
        if(keycode == '13'){
            doldurSeviye();
        }
    });

    // Select değişince otomatik ara
    $('#araDil, #araSeviye').change(function() {
        doldurSeviye();
    });
});

function doldurSeviye() {
    // Yükleniyor animasyonu
    $("#tabloSonuclar").html('<div class="text-center" style="padding:40px;"><i class="fas fa-spinner fa-spin fa-2x"></i> Yükleniyor...</div>');

    // Değerleri Al
    var arananIsim = $("#araAd").val(); // Değişken adını karışıklık olmasın diye değiştirdim
    var sehir = $("#araSehir").val();
    var dil = $("#araDil").val();
    var seviye = $("#araSeviye").val();

    // Ajax İsteği
    $.ajax({
        type: "GET",
        url: "ajax/readSeviye.php",
        data: {
            ogrenciAd: arananIsim, // DÜZELTİLDİ: 'ad' yerine 'ogrenciAd' gönderiyoruz
            sehir: sehir,
            dil: dil,
            seviye: seviye
        },
        success: function(response) {
            $("#tabloSonuclar").html(response);
        },
        error: function() {
            $("#tabloSonuclar").html('<div class="alert alert-danger">Veriler yüklenirken bir hata oluştu. (Lütfen AdBlock kapatıp deneyiniz)</div>');
        }
    });
}