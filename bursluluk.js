function ekleBursluluk() {

    // HTML'deki Verileri Al
    var Cinsiyet = $("#Cinsiyet").val();
    var Ad = $("#Ad").val();
    var Soyad = $("#Soyad").val();
    var TC = $("#TC").val();
    var HamDogum = $("#Dogum").val(); 
    var Okul = $("#Okul").val();
    var Sinif = $("#Sinif").val();
    
    var VeliAd = $("#VeliAd").val();
    var VeliSoyad = $("#VeliSoyad").val();
    var VeliTel1 = $("#VeliTel1").val();
    var VeliTel2 = $("#VeliTel2").val();
    var VeliMeslek = $("#VeliMeslek").val();
    var VeliEmail = $("#VeliEmail").val();
    var SinavTuru = $("#SinavTuru").val();

    // Zorunlu Alan Kontrolü
    if(Ad == "" || Soyad == "" || TC == "" || VeliTel1 == "" || SinavTuru == "" || HamDogum == "") {
        $("#uyariModal").modal("show");
        $("#sonucUyari").html("Lütfen zorunlu alanları, Doğum Tarihini ve Sınav Tercihini doldurunuz.");
        return; 
    }

    // TARİH FORMATINI DÜZELTME
    var DogumTarihiDB = "";
    if (HamDogum.length === 10) {
        var parcalar = HamDogum.split('/'); 
        // Veritabanı formatı: YIL-AY-GÜN
        DogumTarihiDB = parcalar[2] + "-" + parcalar[1] + "-" + parcalar[0];
    } else {
        $("#uyariModal").modal("show");
        $("#sonucUyari").html("Lütfen doğum tarihini tam giriniz (Gün/Ay/Yıl).");
        return;
    }

    // Verileri Gönder
    $.post("ajax/ekleBursluluk.php", {
        Cinsiyet: Cinsiyet,
        Ad: Ad,
        Soyad: Soyad,
        TC: TC,
        Dogum: DogumTarihiDB,
        Okul: Okul,
        Sinif: Sinif,
        VeliAd: VeliAd,
        VeliSoyad: VeliSoyad,
        VeliTel1: VeliTel1,
        VeliTel2: VeliTel2,
        VeliMeslek: VeliMeslek,
        VeliEmail: VeliEmail,
        Sube: "Merkez",
        RandevuTur: "Bursluluk",
        SinavTuru: SinavTuru
    },
    function (data, status) {
        var cevap = data.trim();
        if (cevap == "eklendi") {
            $("#onayModal").modal("show");
            document.getElementById("bilgi").innerHTML = "BAŞVURUNUZ BAŞARIYLA ALINDI.<br>Sınav Tercihiniz: " + SinavTuru;
            
            // Formu tamamen temizle
            temizleFormKomple();
        } 
        else if (cevap == "kayitli") {
            $("#uyariModal").modal("show");
            $("#sonucUyari").html("Bu TC Kimlik numarası ile daha önce kayıt yapılmış.");
        }
        else {
            $("#uyariModal").modal("show");
            $("#sonucUyari").html("Bir hata oluştu: " + data);
        }
    });
}

$(document).ready(function () {
    // --- MASKELEME AYARLARI ---
    // Tarih Maskesi
    $('#Dogum').mask('00/00/0000'); 
    // Telefon Maskeleri (0 ile başlar, parantezli format)
    $('#VeliTel1').mask('0(000) 000 0000');
    $('#VeliTel2').mask('0(000) 000 0000');
    // TC Maskesi - boşluklu (PHP'de temizlenir)
    $('#TC').mask('000 0000 0000');
    $('#TCSorgu').mask('000 0000 0000');


    // 1. DİĞER ALANLARI BÜYÜT 
    // (E-Posta, Doğum Tarihi ve TELEFONLARI hariç tutuyoruz ki maskeleri bozulmasın)
    $(".form-control").not('#VeliEmail, #Dogum, #VeliTel1, #VeliTel2').off('keyup input').on('input', function() {
        // Türkçe karakterleri de büyüterek yaz
        $(this).val($(this).val().toLocaleUpperCase('tr-TR'));
    });


    // 2. E-POSTA İÇİN ÖZEL DÜZELTİCİ
    $("#VeliEmail").off('keyup input keydown paste').on('input', function() {
        var text = $(this).val();
        
        // Türkçe karakter düzeltme (I -> i, İ -> i vb.)
        text = text.replace(/İ/g, "i").replace(/I/g, "i").replace(/ı/g, "i")
                   .replace(/Ğ/g, "g").replace(/ğ/g, "g")
                   .replace(/Ü/g, "u").replace(/ü/g, "u")
                   .replace(/Ş/g, "s").replace(/ş/g, "s")
                   .replace(/Ö/g, "o").replace(/ö/g, "o")
                   .replace(/Ç/g, "c").replace(/ç/g, "c");

        // Hepsini küçük harfe çevir
        $(this).val(text.toLowerCase());
    });

    // Telefon başına otomatik 0( ekleme kolaylığı
    $("#VeliTel1").focus(function(){
        if($(this).val() === "") $(this).val("0(");
    });
    $("#VeliTel2").focus(function(){
        if($(this).val() === "") $(this).val("0(");
    });
});

function doldurSorgulama() {
    var TCSorgu = $("#TCSorgu").val();
    $.post("ajax/doldurSorgulama.php", { TC: TCSorgu }, function (data, status) {
       $(".sorgulama").html(data);
    });
}

// Formu tamamen temizleyen fonksiyon
function temizleFormKomple() {
    // Tüm text inputları temizle
    $("#Ad").val("");
    $("#Soyad").val("");
    $("#TC").val("");
    $("#Dogum").val("");
    $("#Okul").val("");
    $("#VeliAd").val("");
    $("#VeliSoyad").val("");
    $("#VeliTel1").val("");
    $("#VeliTel2").val("");
    $("#VeliMeslek").val("");
    $("#VeliEmail").val("");
    
    // Select'i sıfırla
    $("#Sinif").val("Sec");
    
    // Hidden inputları sıfırla
    $("#Cinsiyet").val("");
    $("#SinavTuru").val("");
    
    // Radio butonların seçimini kaldır
    $("input[name='radioCinsiyet']").prop('checked', false);
    $("input[name='radioSinav']").prop('checked', false);
    
    // Uyarıları gizle
    $(".uyariForm").hide();
}