var basvuruOffset = 0;
var basvuruHasMore = false;
var basvuruLoading = false;

function doldurBasvuru(append) {
    if (basvuruLoading) return;
    if (!append) basvuruOffset = 0;

    var ad = $("#araAd").val().trim();
    var soyad = $("#araSoyad").val().trim();
    var tc = $("#araTC").val().trim().replace(/\s/g, '');
    var numara = $("#araNumara").val().trim().replace(/\s/g, '');
    var sinav = $("#araSinav").val();
    var sinif = $("#araSinif").val();
    var katilim = $("#araKatilim").val();

    basvuruLoading = true;
    if (append) {
        var $container = $(".basvurular");
        var $sentinel = $container.find(".basvuru-load-more-sentinel");
        if ($sentinel.length) $sentinel.replaceWith('<tr class="basvuru-loading-row"><td colspan="17" class="text-center py-2"><i class="fas fa-spinner fa-spin"></i> Yükleniyor...</td></tr>');
    }

    $.ajax({
        url: "ajax/readBasvurular.php?v=3.2",
        type: "GET",
        data: {
            ogrenciAd: ad,
            ogrenciSoyad: soyad,
            ogrenciTC: tc,
            ogrenciNumara: numara,
            sinav: sinav,
            sinif: sinif,
            katilim: katilim,
            offset: basvuruOffset,
            _: new Date().getTime()
        },
        success: function(data) {
            var $wrap = $('<div>').html(data);
            var $marker = $wrap.find(".basvuru-paging-marker");
            basvuruHasMore = ($marker.data("has-more") === 1 || $marker.data("has-more") === "1");
            basvuruOffset = parseInt($marker.data("next-offset"), 10) || 0;
            $marker.remove();

            if (!append) {
                $(".basvurular").html($wrap.children());
                $(".basvurular .basvuru-paging-marker").remove();
            } else {
                $(".basvurular .table tbody").find(".basvuru-loading-row").remove();
                $(".basvurular .table tbody").append($wrap.children());
            }

            if (basvuruHasMore) {
                var $tbody = $(".basvurular .table tbody");
                if (!$tbody.find(".basvuru-load-more-sentinel").length) {
                    $tbody.append('<tr class="basvuru-load-more-sentinel"><td colspan="17" class="text-center py-2 text-muted small">Aşağı kaydırarak daha fazla yükleyebilirsiniz</td></tr>');
                }
            }
        },
        error: function(xhr, status, error) {
            console.error("Hata:", error);
            if (append) $(".basvurular .table tbody").find(".basvuru-loading-row").remove();
        },
        complete: function() {
            basvuruLoading = false;
        }
    });
}

// --- KATILIM BUTONU İŞLEMLERİ ---
$(document).on('click', '.katilim-btn', function() {
    var btn = $(this);
    var id = btn.data('id');
    var currentStatus = btn.data('status');
    var newStatus = (currentStatus == 0) ? 1 : 0;
    
    // Yükleniyor...
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

    $.ajax({
        url: "ajax/updateKatilim.php",
        type: "POST",
        data: { id: id, durum: newStatus },
        success: function(response) {
            // PHP artık tarih döndürüyor veya "Hata..." döndürüyor
            if(response.indexOf("Hata") === -1) { 
                // Başarılı (Response içinde tarih var örn: 06.02.2026 14:00)
                
                btn.prop('disabled', false);
                btn.data('status', newStatus);
                
                // Tarihi butonun tooltip'ine ekle
                btn.attr('title', 'Son İşlem: ' + response);

                if(newStatus == 1) {
                    btn.removeClass('btn-danger').addClass('btn-success');
                    btn.html('<i class="fas fa-check"></i> GELDİ');
                } else {
                    btn.removeClass('btn-success').addClass('btn-danger');
                    btn.html('<i class="fas fa-times"></i> GELMEDİ');
                }
            } else {
                alert("Güncelleme başarısız: " + response);
                btn.prop('disabled', false);
                btn.html(currentStatus == 1 ? '<i class="fas fa-check"></i> GELDİ' : '<i class="fas fa-times"></i> GELMEDİ');
            }
        },
        error: function() {
            alert("Sunucu hatası!");
            btn.prop('disabled', false);
            btn.html(currentStatus == 1 ? '<i class="fas fa-check"></i> GELDİ' : '<i class="fas fa-times"></i> GELMEDİ');
        }
    });
});

// --- DÜZENLE BUTONU: Modal aç ve verileri doldur ---
$(document).on('click', '.basvuru-duzenle-btn', function() {
    var btn = $(this);
    $("#edit_id").val(btn.data('id'));
    $("#edit_Ad").val(btn.data('ad') || '');
    $("#edit_Soyad").val(btn.data('soyad') || '');
    $("#edit_TC").val(btn.data('tc') || '');
    $("#edit_Sinif").val(btn.data('sinif') || '');
    $("#edit_Dogum").val(btn.data('dogum') || '');
    $("#edit_VeliAd").val(btn.data('veliad') || '');
    $("#edit_VeliSoyad").val(btn.data('velisoyad') || '');
    $("#edit_VeliTel1").val(btn.data('velitel1') || '');
    $("#edit_VeliTel2").val(btn.data('velitel2') || '');
    $("#edit_VeliEmail").val(btn.data('veliemail') || '');
    $("#edit_Okul").val(btn.data('okul') || '');
    $("#edit_Cinsiyet").val(btn.data('cinsiyet') || '');
    $("#edit_VeliMeslek").val(btn.data('velimeslek') || '');
    $("#edit_Sube").val(btn.data('sube') || '');
    $("#edit_SinavTuru").val(btn.data('sinavturu') || 'Ingilizce');
    $("#basvuruDuzenleModal").modal('show');
});

// --- DÜZENLE FORM GÖNDER ---
$(document).on('submit', '#basvuruDuzenleForm', function(e) {
    e.preventDefault();
    var $form = $(this);
    var $btn = $form.find('button[type="submit"]');
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Kaydediliyor...');
    $.ajax({
        url: "ajax/updateBasvuru.php",
        type: "POST",
        data: $form.serialize(),
        dataType: "json",
        success: function(res) {
            if (res.ok) {
                $("#basvuruDuzenleModal").modal('hide');
                doldurBasvuru();
                alert(res.mesaj || 'Kayıt güncellendi.');
            } else {
                alert(res.mesaj || 'Güncelleme başarısız.');
            }
        },
        error: function(xhr) {
            var msg = 'Sunucu hatası.';
            try { var r = JSON.parse(xhr.responseText); if (r.mesaj) msg = r.mesaj; } catch (e) {}
            alert(msg);
        },
        complete: function() {
            $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Kaydet');
        }
    });
});

// --- SİL BUTONU ---
$(document).on('click', '.basvuru-sil-btn', function() {
    if (!confirm('Bu başvuruyu silmek istediğinize emin misiniz? Bu işlem geri alınamaz.')) return;
    var id = $(this).data('id');
    var $btn = $(this);
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    $.ajax({
        url: "ajax/deleteBasvuru.php",
        type: "POST",
        data: { id: id },
        dataType: "json",
        success: function(res) {
            if (res.ok) {
                doldurBasvuru();
                alert(res.mesaj || 'Kayıt silindi.');
            } else {
                alert(res.mesaj || 'Silme başarısız.');
            }
        },
        error: function(xhr) {
            var msg = 'Sunucu hatası.';
            try { var r = JSON.parse(xhr.responseText); if (r.mesaj) msg = r.mesaj; } catch (e) {}
            alert(msg);
        },
        complete: function() {
            $btn.prop('disabled', false).html('<i class="fas fa-trash-alt"></i>');
        }
    });
});

$(document).ready(function() {
    doldurBasvuru();

    $(window).on("scroll.basvuru", function() {
        if (!basvuruHasMore || basvuruLoading) return;
        var $sentinel = $(".basvuru-load-more-sentinel");
        if (!$sentinel.length) return;
        var st = $(window).scrollTop();
        var winH = $(window).height();
        var sentinelTop = $sentinel.offset().top;
        if (sentinelTop - winH < st + 200) {
            doldurBasvuru(true);
        }
    });

    $("#araAd, #araSoyad, #araTC, #araNumara").keypress(function(e) {
        if(e.which == 13) doldurBasvuru();
    });

    $("#araSinav, #araSinif, #araKatilim").change(function(){
        doldurBasvuru();
    });
});