
var action;
function kontrol() {
    $(".uyariForm").hide();
    var sonuc = true;
    if ($("#Ad").val() == "") { $("#uyariAdi").show(); sonuc = false; }
    if ($("#Soyad").val() == "") { $("#uyariSoyadi").show(); sonuc = false; }
    if ($("#TC").val() == "") { $("#uyariTC").show(); sonuc = false; }
    if ($("#Cinsiyet").val() == "Sec") { $("#uyariCinsiyet").show(); sonuc = false; }
    if ($("#Okul").val() == "") { $("#uyariOkul").show(); sonuc = false; }
    if ($("#Sinif").val() == "Sec") { $("#uyariSinif").show(); sonuc = false; }
    if ($("#VeliAd").val() == "") { $("#uyariVeliAd").show(); sonuc = false; }
    if ($("#VeliSoyad").val() == "") { $("#uyariVeliSoyad").show(); sonuc = false; }
    if ($("#VeliTel1").val().length < 14) { $("#uyariVeliTel1").show(); sonuc = false; }
    if (!$("#VeliEmail").val().includes("@")) { $("#uyariVeliEmail").show(); sonuc = false; }
    if ($("#Sube").val() == "Sec") { $("#uyariSube").show(); sonuc = false; }
    return sonuc;
}
function temizleForm() {
    $("#Cinsiyet").val('Sec');
    $("#Ad").val("");
    $("#Soyad").val("");
    $("#TC").val("");
    $("#Dogum").val("");
    $("#Okul").val("");
    $("#Sinif").val('Sec');
    $("#VeliAd").val("");
    $("#VeliSoyad").val("");
    $("#VeliTel1").val("");
    $("#VeliTel2").val("");
    $("#VeliMeslek").val("");
    $("#VeliEmail").val("");
    $("#Sube").val('Sec');
    $(".uyariForm").hide();
   
}
function ekleBursluluk() {
    var Cinsiyet = $("#Cinsiyet").val();
    var Ad = $("#Ad").val();
    var Soyad = $("#Soyad").val();
    var TC = $("#TC").val();
    var Dogum = $("#Dogum").val();
    var Okul=$("#Okul").val();
    var Sinif=$("#Sinif").val();
    var VeliAd= $("#VeliAd").val();
    var VeliSoyad=$("#VeliSoyad").val();
    var VeliTel1= $("#VeliTel1").val();
    var VeliTel2= $("#VeliTel2").val();
    var VeliMeslek=$("#VeliMeslek").val();
    var VeliEmail=$("#VeliEmail").val();
    var Sube = $("#Sube").val();
    
    if (kontrol()) {
        $.post("ajax/ekleBursluluk.php", {
            Cinsiyet: Cinsiyet,
            Ad: Ad,
            Soyad: Soyad,
            TC: TC,
            Dogum: Dogum,
            Okul: Okul,
            Sinif: Sinif,
            VeliAd: VeliAd,
            VeliSoyad: VeliSoyad,
            VeliTel1: VeliTel1,
            VeliTel2: VeliTel2,
            VeliMeslek: VeliMeslek,
            VeliEmail: VeliEmail,
            Sube:Sube,
            RandevuTur:'Bursluluk'
        },

        function (data, status) {

            if (data == "eklendi") {
                $('#onayModalAdres').modal({
                    backdrop: 'static',
                    keyboard: false  // to prevent closing with Esc button (if you want this too)
                });

                // htmlString += "<div class='divIsaret'><span class='iconOk'></span></div><div class='alert alert-success'><h5><span>Sayın " + arrSonuc[0] + " " + arrSonuc[1] + " " + icCumle + " bulunmaktadır.Danışmanlarımız sizinle irtibata geçecektir.</span></h5></div>";
                //htmlString += "<div class='alert alert-info harita' onclick='AdresYonlendirme()'><span class='haritaSimge'></span>Randevu Talep ettiğniz şubenin adresini öğrenmek için tıklayınız.</div></div>"

                document.getElementById("sonucAdres").innerHTML = "<div class='divIsaret'><span class='iconOk'></span></div><div class='sihayYazi'>Sayın " + $("#Ad").val() + " " + $("#Soyad").val() + ",</div><div class='sihayYazi'> RANDEVU TALEBİ'niz alınmıştır. Danışmanlarımız sizinle iletişime geçecektir.</div>";
                document.getElementById("bilgiAdres").innerHTML = "<div class='sihayYazi' onclick='AdresYonlendirme()'> Randevu Talep ettiğiniz şubenin adresini öğrenmek için <span class='haritaSimge'>tıklayınız.</span></div>"
            }
           else if (data == "kayitli") {
               $("#onayModal").modal("show");
               document.getElementById("sonuc").innerHTML = "<div class='divIsaret sihayYazi'><span class='warningIsaret'></span>Kayıtlı Randevunuz Bulunmaktadır.</div>";
                document.getElementById("bilgi").innerHTML = "<p>ADI: " + $("#Ad").val() + "</p> <p>SOYADI: " + $("#Soyad").val();

            }


            else {
              
                $("#uyariModal").modal("show");
            }
        });

    }
    else { return false; }
}

function AdresYonlendirme() {
    console.log($("#Sube").val());
    if ($("#Sube").val() == "Amerikan Kültür") {
        window.location.href = "iletisim.html";
    }

    if ($("#Sube").val() == "İngiliz Kültür") {
        window.location.href = "iletisim.html?sube=ikdAfyon";
    }

}


//function saatGetir() {
//    var durum = $("#Sinif").val();
//    const durumlar = {
//        4: ["7.03.2020", "11:30"],
//        5: ["7.03.2020", "13:10"],
//        6: ["7.03.2020", "14:50"],
//        7: ["7.03.2020", "16:30"],
//        8: ["14.03.2020", "10:40"],
//        9: ["14.03.2020", "12:20"],
//        10: ["14.03.2020", "14:00"],
//        11: ["14.03.2020", "16:00"],
//        'default': ['Lütfen Sınıf Seçiniz !', "Lütfen Sınıf Seçiniz !"]
//    }
//    action = durumlar[parseInt(durum)] || durumlar['default'];
//    document.getElementById("SinavSaat").innerHTML = "Sınav Saati: " + action[1];
//    document.getElementById("SinavTarih").innerHTML = "Sınav Tarihi: " + action[0];
//}


$(document).ready(function () {
    $('#Dogum').mask('00/00/0000');
    $('#VeliTel1').mask('0 000 000 0000');
    $('#VeliTel2').mask('0 000 000 0000');
    // TC maskeleme - boşluklu (görüntüde güzel görünür, PHP'de temizlenir)
    $('#TC').mask('000 0000 0000');
    $("#TCSorgu").mask('000 0000 0000');
    $(".form-control").keyup(function () {
        $(this).val($(this).val().toLocaleUpperCase('tr-TR'));
    });
    $("#VeliTel1").focus(function () {
        $("#VeliTel1").val("0 ");
    });
    $("#VeliTel2").focus(function () {
        $("#VeliTel2").val("0 ");
    });
    temizleForm();
});

