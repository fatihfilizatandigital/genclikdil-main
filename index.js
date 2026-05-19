

var Sube = "";

    function AdresYonlendirme() {
        console.log(Sube);
        if (Sube == "Amerikan Kültür") {
            window.location.href = "iletisim.html";
        }

        if (Sube == "İngiliz Kültür") {
            window.location.href = "iletisim.html?sube=ikdAfyon";
        }

    }

    function temizleSorgula() {
        $("#TCSorgu").val("");
    }


    function doldurSorgulama() {
        var TCSorgu = $("#TCSorgu").val();
        $.post("ajax/doldurSorgulama.php", { TC: TCSorgu }, function (data, status) {
            let htmlString = "";
            let icCumle = "";
            if (data == "") {
                htmlString += '<div class="divIsaret"><span class="warningIsaret"></span></div>'
                htmlString += '<div class="sihayYazi"> Kayıtlarımızda Bulunamamıştır.</div>'
            }
            else {
                let arrSonuc = data.split("-");
                console.log(arrSonuc);
                Sube = arrSonuc[2];
                if (arrSonuc[3] == "ÖnHazırlık")
                {
                    icCumle = "Ücretsiz İngilizce A1 Seviyesine Hazırlık Kursu randevunuz";
                }
                if (arrSonuc[3] == "Bursluluk") {
                    icCumle = "Bursluluk Sınavı Başvuru randevunuz"
                }
               
                htmlString += "<div class='divIsaret'><span class='iconOk'></span></div><div class='sihayYazi'>Sayın " + arrSonuc[0] + " " + arrSonuc[1] + ",</div><div class='sihayYazi'> " + icCumle + " bulunmaktadır.<br> Danışmanlarımız sizinle irtibata geçecektir.";
                htmlString += "</div><div class='sihayYazi'>Randevu Talep ettiğiniz şubenin adresini öğrenmek için <span class='haritaSimge'  onclick='AdresYonlendirme()'> tıklayınız.</span> </div>"
            }
            $(".sorgulamaSonuc").html(htmlString);
            $("#sorgulamaModal").modal("show");
            
        });

    }

    $(document).ready(function () {
        $("#TCSorgu").mask('000 0000 0000');

        $('#TCSorgu').bind('keyup', function(e) {

    if ( e.keyCode === 13 ) { // 13 is enter key

        doldurSorgulama();

    }

});
    });
