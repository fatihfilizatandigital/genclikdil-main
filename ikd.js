
var action;
function kontrol() {
    $(".uyariForm").hide();
    var sonuc = true;
    if ($("#Ad").val() == "") { $("#uyariAdi").show(); sonuc = false; }
    if ($("#Soyad").val() == "") { $("#uyariSoyadi").show(); sonuc = false; }
    if ($("#TC").val() == "") { $("#uyariTC").show(); sonuc = false; }
    if ($("#Cinsiyet").val() == "Sec") { $("#uyariCinsiyet").show(); sonuc = false; }
    if ($("#Dogum").val() == "") { $("#uyariDogum").show(); sonuc = false; }
    if ($("input[name='UniversiteTur']:checked").val() == undefined) { $("#uyariUniversiteTur").show(); sonuc = false; }
    if ($("#Fakulte").val() == "") { $("#uyariFakulte").show(); sonuc = false; }
    if ($("#Bolum").val() == "") { $("#uyariBolum").show(); sonuc = false; }
    if ($("#Okul").val() == "") { $("#uyariOkul").show(); sonuc = false; }
    if ($("#OgrenimTur").val()=="Sec") { $("#uyariOgrenimTur").show(); sonuc = false; }
    if ($("#Sinif").val() == "Sec") { $("#uyariSinif").show(); sonuc = false; }
    if ($("#CepTel").val().length<14) { $("#uyariCepTel").show(); sonuc = false; }
    if (! $("#Eposta").val().includes("@")) { $("#uyariEposta").show(); sonuc = false; }
    //if ($("#IlgilenDil").val() == "Sec") { $("#uyariIlgilenDil").show(); sonuc = false; }
    if ($("#Amac").val() == "Sec") { $("#uyariAmac").show(); sonuc = false; }
    if ($("#Sube").val() == "Sec") { $("#uyariSube").show(); sonuc = false; }
    return sonuc;
}
function temizleForm() {
    $("#Cinsiyet").val('Sec');
    $("#Ad").val("");
    $("#Soyad").val("");
    $("#TC").val("");
    $("#Dogum").val("");
    $("input[name='UniversiteTur']").prop("checked", false);
    $("#digerInput").val("");
    $("#Fakulte").val("");
    $("#Bolum").val("");
    $("#OgrenimTur").val("Sec");
    $("#Sinif").val('Sec');
    $("#CepTel").val("");
    $("#Eposta").val("");
    $("#IlgilenDil").val('Sec');
    $("#Amac").val("Sec");
    $("#Sube").val("Sec");
    $(".uyariForm").hide();
    $("#digerInput").hide();
   
}
function ekleBursluluk() {
    var Cinsiyet = $("#Cinsiyet").val();
    var Ad = $("#Ad").val();
    var Soyad = $("#Soyad").val();
    var TC = $("#TC").val();
    var Dogum = $("#Dogum").val();
    var UniversiteTur = $("input[name='UniversiteTur']:checked").val();
    if ($("input[name='UniversiteTur']:checked").val() == "DİĞER") UniversiteTur = $("#digerInput").val();
    var Fakulte= $("#Fakulte").val();
    var Bolum=$("#Bolum").val();
    var OgrenimTur = $("#OgrenimTur").val();
    var Sinif=$("#Sinif").val();
    var CepTel = $("#CepTel").val();
    var Eposta = $("#Eposta").val();
    var IlgilenDil = $("#IlgilenDil").val();
    var Amac=$("#Amac").val();
    var Sube=$("#Sube").val();
    
    if (kontrol()) {
        $.post("ajax/ekleDemoDers.php", {
            Cinsiyet: Cinsiyet,
            Ad: Ad,
            Soyad: Soyad,
            TC: TC,
            Dogum: Dogum,
            UniversiteTur: UniversiteTur,
            Fakulte: Fakulte,
            Bolum: Bolum,
            OgrenimTur: OgrenimTur,
            Sinif: Sinif,
            CepTel: CepTel,
            Eposta: Eposta,
            IlgilenDil: IlgilenDil,
            Amac: Amac,
            Sube: Sube,
            RandevuTur:'ÖnHazırlık'
        },

        function (data, status) {

            if (data == "eklendi") {
                //onayModalAdres modal dışarıya tıklanınca kapanmasın.
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

                //htmlString += '<div class="divIsaret"><span class="warningIsaret"></span></div>'
                // htmlString += '<div class="alert alert-danger"> Kayıtlarımızda Bulunamamıştır.</div>'
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
    console.log(Sube);
    if (Sube == "Amerikan Kültür") {
        window.location.href = "iletisim.html";
    }

    if (Sube == "İngiliz Kültür") {
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
    $('#CepTel').mask('0 000 000 0000');
    $('#VeliTel1').mask('0 000 000 0000');
    $('#VeliTel2').mask('0 000 000 0000');
    $('#TC').mask('000 0000 0000');
    $("#TCSorgu").mask('000 0000 0000');
    $(".kisaFormControl").keyup(function () {
        $(this).val($(this).val().toLocaleUpperCase('tr-TR'));
    });
    $(".form-control").keyup(function () {
        $(this).val($(this).val().toLocaleUpperCase('tr-TR'));
    });
    $("#VeliTel1").focus(function () {
        $("#VeliTel1").val("0 ");
    });
    $("#VeliTel2").focus(function () {
        $("#VeliTel2").val("0 ");
    });


    $("#CepTel").focus(function () {
        $("#CepTel").val("0 ");
    });

    $('input[type="radio"]').click(function () {
        if ($("input[name='UniversiteTur']:checked").val() == "DİĞER") {
            $("#digerInput").show('slow');
        }
        else {
            $("#digerInput").hide('slow');
        }
    });
    temizleForm();

   
});


