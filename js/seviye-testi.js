/* js/seviye-testi.js - v4.0 Gerçek Adaptif Algoritma (Duolingo/EF SET Tarzı) */

// --- GLOBAL DEĞİŞKENLER ---
var tumSorular = [];
var filtrelenmisHavuz = [];
var sorulanSorularID = [];
var mevcutSoru = null;
var mevcutSoruSayisi = 0;
var kullaniciProfili = {};

// Adaptif Algoritma Değişkenleri
var mevcutSeviyeIndex = 2; // Herkes B1'den başlar (index 2)
var ardisikDogruSayisi = 0;
var ardisikYanlisSayisi = 0;
var sonCevapSeviyesi = "";
var seviyeKilitlendi = false;
var minSoruSayisi = 8;
var maxSoruSayisi = 15;

// Seviye Tanımları
const seviyeSiralamasi = ["A1", "A2", "B1", "B2", "C1", "C2"]; // C2 soruları dahil
const seviyeAciklamalari = {
    "A1": "Başlangıç",
    "A2": "Temel", 
    "B1": "Orta",
    "B2": "Orta Üstü",
    "C1": "İleri",
    "C2": "Profesyonel"
};

// --- 1. ADIM GEÇİŞLERİ ---
function gecAdim(adimNo) {
    $(".step-container").removeClass("active-step").hide();
    $("#adim-" + adimNo).addClass("active-step").fadeIn(600);
    
    var progressWidth = (adimNo / 4) * 100;
    $("#ustBar").css("width", progressWidth + "%");
}

// --- 2. SINAVI BAŞLAT ---
function sinaviBaslat() {
    var ad = $("#leadAdSoyad").val();
    var tel = $("#leadTel").val();
    var yas = parseInt($("#leadYas").val());
    var sehir = $("#leadSehir").val();
    var dil = $("#leadDil").val();
    var beyanSeviye = parseInt($("#leadTahminiSeviye").val());
    var amac = $("#leadAmac").val();

    if (ad.length < 3 || tel.length < 10 || isNaN(yas)) {
        alert("Lütfen Ad Soyad, Telefon ve Yaş bilgilerini eksiksiz doldurunuz.");
        return;
    }

    kullaniciProfili = {
        ad: ad, 
        tel: tel, 
        yas: yas, 
        sehir: sehir,
        dil: dil,
        beyanSeviye: beyanSeviye,
        amac: amac
    };

    // Değişkenleri sıfırla
    mevcutSeviyeIndex = 2; // B1'den başla
    ardisikDogruSayisi = 0;
    ardisikYanlisSayisi = 0;
    sonCevapSeviyesi = "";
    seviyeKilitlendi = false;
    mevcutSoruSayisi = 0;
    sorulanSorularID = [];

    $("#btnBaslat").html('<i class="fas fa-circle-notch fa-spin"></i> Sınav Hazırlanıyor...').prop("disabled", true);

    // Veritabanından soruları çek
    $.getJSON("ajax/getSorular.php", function(data) {
        if(data.length === 0) {
            alert("Soru bankasına erişilemedi veya hiç soru yok.");
            $("#btnBaslat").text("SORULARA GEÇ").prop("disabled", false);
            return;
        }
        
        tumSorular = data;
        soruHavuzunuOlustur();
        
        // Yeterli soru var mı kontrol et
        var yeterliMi = kontrolSoruYeterliligi();
        if(!yeterliMi) {
            alert("Seçtiğiniz dil için yeterli soru bulunmuyor. Lütfen yöneticiye bildirin.");
            $("#btnBaslat").text("SORULARA GEÇ").prop("disabled", false);
            return;
        }
        
        sonrakiSoruGetir();
        gecAdim(3);
        
    }).fail(function() {
        alert("Bağlantı hatası! Lütfen internetinizi kontrol edin.");
        $("#btnBaslat").text("SORULARA GEÇ").prop("disabled", false);
    });
}

// --- 3. SORU HAVUZU OLUŞTUR ---
function soruHavuzunuOlustur() {
    // Dile göre filtrele
    var dilHavuzu = tumSorular.filter(s => s.dil === kullaniciProfili.dil);

    // Yaşa göre filtrele
    filtrelenmisHavuz = dilHavuzu.filter(s => {
        if (kullaniciProfili.yas < 14) {
            return s.kategori === "Cocuk" || s.kategori === "Genel";
        } else {
            if (s.kategori === "Cocuk") return false;
            return true;
        }
    });

    // Eğer çok az soru varsa hepsini kullan
    if (filtrelenmisHavuz.length < 10) {
        filtrelenmisHavuz = dilHavuzu;
    }
}

// --- 4. SORU YETERLİLİĞİ KONTROL ---
function kontrolSoruYeterliligi() {
    for(var i = 0; i < seviyeSiralamasi.length; i++) {
        var seviye = seviyeSiralamasi[i];
        var soruSayisi = filtrelenmisHavuz.filter(s => s.seviye === seviye).length;
        if(soruSayisi < 2) {
            console.warn(seviye + " seviyesinde yeterli soru yok: " + soruSayisi);
            return false;
        }
    }
    return true;
}

// --- 5. ADAPTİF ALGORİTMA - SONRAKİ SORU ---
function sonrakiSoruGetir() {
    // Bitiş koşulları
    if(seviyeKilitlendi && mevcutSoruSayisi >= minSoruSayisi) {
        sinaviBitir();
        return;
    }
    
    if(mevcutSoruSayisi >= maxSoruSayisi) {
        sinaviBitir();
        return;
    }

    // İlerleme çubuğu güncelle
    var yuzde = (mevcutSoruSayisi / maxSoruSayisi) * 100;
    $("#sinavProgress").css("width", yuzde + "%");

    // Mevcut seviyeye göre soru bul
    var arananSeviye = seviyeSiralamasi[mevcutSeviyeIndex];
    
    // O seviyedeki sorulmamış soruları bul
    var adaySorular = filtrelenmisHavuz.filter(s => 
        s.seviye === arananSeviye && !sorulanSorularID.includes(s.id)
    );

    // Eğer o seviyede soru kalmadıysa, komşu seviyelerden al
    if (adaySorular.length === 0) {
        // Önce bir üst seviyeye bak
        if(mevcutSeviyeIndex < seviyeSiralamasi.length - 1) {
            var ustSeviye = seviyeSiralamasi[mevcutSeviyeIndex + 1];
            adaySorular = filtrelenmisHavuz.filter(s => 
                s.seviye === ustSeviye && !sorulanSorularID.includes(s.id)
            );
        }
        // Hala yoksa alt seviyeye bak
        if(adaySorular.length === 0 && mevcutSeviyeIndex > 0) {
            var altSeviye = seviyeSiralamasi[mevcutSeviyeIndex - 1];
            adaySorular = filtrelenmisHavuz.filter(s => 
                s.seviye === altSeviye && !sorulanSorularID.includes(s.id)
            );
        }
        // Hala yoksa herhangi birinden al
        if(adaySorular.length === 0) {
            adaySorular = filtrelenmisHavuz.filter(s => !sorulanSorularID.includes(s.id));
        }
    }

    // Soru kalmadıysa bitir
    if(adaySorular.length === 0) {
        sinaviBitir();
        return;
    }

    // Rastgele soru seç
    var secilenSoru = adaySorular[Math.floor(Math.random() * adaySorular.length)];
    
    mevcutSoru = secilenSoru;
    sorulanSorularID.push(secilenSoru.id);
    soruEkranaBas(secilenSoru);
}

// --- 6. SORU EKRANA BAS ---
function soruEkranaBas(soru) {
    var html = `
        <div class="card-modern fade-in-up" style="padding: 30px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge" style="background: #3498db; color: white; font-size: 12px; padding: 5px 12px; border-radius: 20px;">
                    SORU ${mevcutSoruSayisi + 1}
                </span>
                <span class="text-muted" style="font-size: 11px; font-weight: bold;">
                    ${soru.dil.toUpperCase()} • ${soru.seviye}
                </span>
            </div>
            <div class="q-text">${soru.soru}</div>
            <div class="options-grid">
                ${soru.secenekler.map((secenek, i) => 
                    `<button class="option-btn" onclick="cevapKontrol(this, ${i})">
                        <span class="opt-letter">${String.fromCharCode(65+i)}</span> ${secenek}
                    </button>`
                ).join('')}
            </div>
        </div>
    `;
    $("#soruAlani").html(html);
    $("#btnSonraki").hide();
}

// --- 7. CEVAP KONTROL (ADAPTİF MANTIK) ---
function cevapKontrol(btn, secilenIndex) {
    $(".option-btn").prop("disabled", true).addClass("disabled-opt");
    
    var dogruMu = (secilenIndex === mevcutSoru.dogru);
    var soruSeviyesi = mevcutSoru.seviye;
    
    if (dogruMu) {
        // DOĞRU CEVAP
        $(btn).addClass("correct-answer");
        
        // Ardışık doğru sayısını güncelle
        if(sonCevapSeviyesi === soruSeviyesi || sonCevapSeviyesi === "") {
            ardisikDogruSayisi++;
        } else {
            ardisikDogruSayisi = 1;
        }
        ardisikYanlisSayisi = 0;
        
        // 2 ardışık doğru = seviye yükselt
        if(ardisikDogruSayisi >= 2 && !seviyeKilitlendi) {
            if(mevcutSeviyeIndex < seviyeSiralamasi.length - 1) {
                mevcutSeviyeIndex++;
                ardisikDogruSayisi = 0;
            } else {
                // Zaten en üst seviyede (C2), kilitle
                seviyeKilitlendi = true;
            }
        }
        
    } else {
        // YANLIŞ CEVAP
        $(btn).addClass("wrong-answer");
        $(".option-btn").eq(mevcutSoru.dogru).addClass("correct-answer");
        
        // Ardışık yanlış sayısını güncelle
        if(sonCevapSeviyesi === soruSeviyesi || sonCevapSeviyesi === "") {
            ardisikYanlisSayisi++;
        } else {
            ardisikYanlisSayisi = 1;
        }
        ardisikDogruSayisi = 0;
        
        // 2 ardışık yanlış = seviye düşür
        if(ardisikYanlisSayisi >= 2 && !seviyeKilitlendi) {
            if(mevcutSeviyeIndex > 0) {
                mevcutSeviyeIndex--;
                ardisikYanlisSayisi = 0;
            } else {
                // Zaten en alt seviyede (A1), kilitle
                seviyeKilitlendi = true;
            }
        }
    }
    
    sonCevapSeviyesi = soruSeviyesi;
    mevcutSoruSayisi++;
    
    $("#btnSonraki").fadeIn();
}

// --- 8. SINAVI BİTİR VE SONUÇ ---
function sinaviBitir() {
    gecAdim(4);
    
    // Gerçek seviye = mevcut seviye index'i
    var gercekSeviye = seviyeSiralamasi[mevcutSeviyeIndex];
    
    // Pazarlama mantığı: B1 ve üzeri için -1 seviye göster
    var gosterilenIndex = mevcutSeviyeIndex;
    if(mevcutSeviyeIndex >= 2) { // B1 ve üzeri
        gosterilenIndex = mevcutSeviyeIndex - 1;
    }
    var gosterilenSeviye = seviyeSiralamasi[gosterilenIndex];
    
    // Maksimum sonuç C1 olmalı - C2 ise C1'e düşür
    if(gosterilenSeviye === "C2") {
        gosterilenSeviye = "C1";
        gosterilenIndex = 4; // C1 index'i
    }
    
    // Ekrana bas
    $("#sonucAd").text(kullaniciProfili.ad);
    $("#hesaplananSeviye").text(gosterilenSeviye);
    $("#sonucTel").text(kullaniciProfili.tel);
    
    // Motivasyon metni
    var yorum = "";
    switch(gosterilenIndex) {
        case 0: // A1
            yorum = "Harika bir başlangıç noktası! Temelleri sağlam atarak hızlı bir şekilde ilerleyebilirsiniz.";
            break;
        case 1: // A2
            yorum = "Temel bilgileriniz var. Günlük konuşma ve gramer yapılarını güçlendirerek B1 seviyesine ulaşabilirsiniz.";
            break;
        case 2: // B1
            yorum = "Orta seviyedesiniz. Akıcılık ve kelime hazinesi geliştirme ile profesyonel seviyeye ulaşabilirsiniz.";
            break;
        case 3: // B2
            yorum = "Oldukça iyi bir seviyedesiniz! Akademik ve iş İngilizcesi için son rötuşlara ihtiyacınız var.";
            break;
        case 4: // C1
            yorum = "İleri seviyedesiniz! Native speaker akıcılığına ulaşmak için pratik yapmanız yeterli.";
            break;
        case 5: // C2 (maksimum C1 gösterileceği için bu durum oluşmaz ama yine de ekliyoruz)
            yorum = "İleri seviyedesiniz! Native speaker akıcılığına ulaşmak için pratik yapmanız yeterli.";
            break;
    }
    $("#sonucYorum").text(yorum);

    // Veritabanına kaydet
    $.post("ajax/kayitSeviye.php", {
        ad: kullaniciProfili.ad,
        tel: kullaniciProfili.tel,
        yas: kullaniciProfili.yas,
        sehir: kullaniciProfili.sehir,
        dil: kullaniciProfili.dil,
        beyanSeviye: kullaniciProfili.beyanSeviye,
        amac: kullaniciProfili.amac,
        sonuc: gosterilenSeviye
    });
    
    console.log("Sınav Bitti - Gerçek Seviye:", gercekSeviye, "Gösterilen:", gosterilenSeviye, "Soru Sayısı:", mevcutSoruSayisi);
}

// --- 9. SONRAKİ SORU BUTONU ---
function sonrakiSoruTikla() {
    sonrakiSoruGetir();
}
