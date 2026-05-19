<?php
/**
 * Bursluluk sonuç sayfası ve PayTR ödeme için ortak fiyat hesaplama.
 * Peşin/taksitli ayrımı yok: kurs tek fiyat (sıra + erken + almanca), kitap & materyal sabit 22.000 TL.
 * Parametreler: sinif_ici_sira, erken_kayit (0|1), almanca (0|1), pesin (0|1 - kullanılmıyor, uyumluluk için)
 */
define('SONUC_FIYAT_BAZ_INGILIZCE', 66200);
define('SONUC_FIYAT_BAZ_ALMANCA', 66200);
define('SONUC_MATERYAL_TUTAR', 22000); // Kitap & Materyal sabit 22.000 TL
define('SONUC_KUTUPHANE_GOSTERIM', 60000); // Gösterim: kütüphane ücreti + ücretsiz indirim -60.000

$GLOBALS['sonuc_fiyat_sira_indirim'] = [1 => 100, 2 => 80, 3 => 70, 4 => 50, 5 => 40, 6 => 30, 7 => 20];

function sonuc_kampanya_aktif_mi(): bool {
    $simdi = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
    $bitis = new DateTimeImmutable('2026-04-30 23:59:59', new DateTimeZone('Europe/Istanbul'));
    return $simdi <= $bitis;
}

function sonuc_fiyat_hesapla($sinif_ici_sira, $erken_kayit, $almanca, $pesin = 0) {
    $sira = (int) $sinif_ici_sira;
    $erken = (int) $erken_kayit;
    $alm = (int) $almanca;
    $erken_almanca = ($erken && $alm) ? 1 : 0;

    $indirim_pct = isset($GLOBALS['sonuc_fiyat_sira_indirim'][$sira]) ? $GLOBALS['sonuc_fiyat_sira_indirim'][$sira] : ($sira >= 8 ? 10 : 0);
    $roundUp = function($x) { return $x <= 0 ? 0 : (int)ceil($x / 100) * 100; };

    $baz = SONUC_FIYAT_BAZ_INGILIZCE;
    $p1 = $sira > 0 ? $roundUp($baz * (100 - $indirim_pct) / 100) : $roundUp($baz);
    $p2 = $erken ? $roundUp($p1 * 0.90) : $p1;
    $fiyat_ing = $p2;
    $toplam_ders = $fiyat_ing;
    $fiyat_almanca = 0;
    if ($alm) {
        $almanca_baz = SONUC_FIYAT_BAZ_ALMANCA;
        $fiyat_almanca = $erken_almanca ? $roundUp($almanca_baz * 0.40) : $roundUp($almanca_baz * 0.50);
        $toplam_ders += $fiyat_almanca;
    }
    $materyal_tutar = SONUC_MATERYAL_TUTAR; // Sabit 22.000 TL
    $toplam_genel = $toplam_ders + $materyal_tutar;

    $sira_indirim_tutar = $sira > 0 ? ($baz - $p1) : 0;
    $erken_indirim_tutar = $erken ? ($p1 - $p2) : 0;
    $almanca_normal_indirim = $alm ? $roundUp(SONUC_FIYAT_BAZ_ALMANCA * 0.50) : 0;
    $almanca_erken_ek_indirim = ($alm && $erken_almanca) ? 6600 : 0;

    $kurs_headers = $alm ? ['İngilizce', 'Almanca', 'TOPLAM'] : ['İngilizce', 'TOPLAM'];
    $kurs_rows = [
        ['kalem' => 'Baz fiyat', 'ing' => $baz, 'alm' => $alm ? SONUC_FIYAT_BAZ_ALMANCA : 0, 'toplam' => $baz + ($alm ? SONUC_FIYAT_BAZ_ALMANCA : 0)],
        ['kalem' => 'Burs indirimi' . ($sira ? ' (' . $sira . '. sıra)' : ''), 'ing' => -$sira_indirim_tutar, 'alm' => 0, 'toplam' => -$sira_indirim_tutar],
    ];
    if ($alm) {
        $kurs_rows[] = ['kalem' => 'Almanca indirimi', 'ing' => 0, 'alm' => -$almanca_normal_indirim, 'toplam' => -$almanca_normal_indirim];
    }
    if ($erken) {
        $kurs_rows[] = ['kalem' => 'Erken kayıt indirimi', 'ing' => -$erken_indirim_tutar, 'alm' => -$almanca_erken_ek_indirim, 'toplam' => -$erken_indirim_tutar - $almanca_erken_ek_indirim];
    }
    $kurs_rows[] = ['kalem' => 'Kurs ücreti', 'ing' => $p2, 'alm' => $fiyat_almanca, 'toplam' => $toplam_ders, 'bold' => true];

    $kitap_rows = [
        ['kalem' => 'Kütüphane ücreti', 'tutar' => SONUC_KUTUPHANE_GOSTERIM, 'bold' => false],
        ['kalem' => 'Kitap & Materyal ücreti', 'tutar' => SONUC_MATERYAL_TUTAR, 'bold' => false],
        ['kalem' => 'Ücretsiz kütüphane indirimi', 'tutar' => -SONUC_KUTUPHANE_GOSTERIM, 'bold' => false],
        ['kalem' => 'Toplam', 'tutar' => SONUC_MATERYAL_TUTAR, 'bold' => true],
    ];

    $hesap_detay = [
        'kurs' => ['headers' => $kurs_headers, 'rows' => $kurs_rows],
        'kitap_materyal' => ['rows' => $kitap_rows],
        'toplam_genel' => $toplam_genel,
        'kurs_tutar' => $toplam_ders,
        'kitap_materyal_tutar' => $materyal_tutar,
    ];

    return [
        'kurs_tutar' => $toplam_ders,
        'kitap_materyal_tutar' => $materyal_tutar,
        'toplam_genel' => $toplam_genel,
        'hesap_detay' => $hesap_detay,
    ];
}

/**
 * sonuclar.php tarafindaki yeni secim/profil kurallari ile birebir uyumlu hesap.
 * $profil: onlyEnglish | onlyGerman | both
 * $ingilizce_secili, $almanca_secili: 0|1
 * $force_erken: true ise erken_kayit kampanya tarihinden bağımsız uygulanır (admin elden ödeme için).
 */
function sonuc_fiyat_hesapla_coklu_dal($sinif_ici_sira, $erken_kayit, $ingilizce_secili, $almanca_secili, $profil = 'onlyEnglish', $force_erken = false) {
    $sira = (int)$sinif_ici_sira;
    $kampanya_aktif = sonuc_kampanya_aktif_mi();
    $erken = $force_erken ? ((int)$erken_kayit === 1) : (((int)$erken_kayit === 1) && $kampanya_aktif);
    $sec_ing = (int)$ingilizce_secili === 1;
    $sec_alm = (int)$almanca_secili === 1;

    if (!$sec_ing && !$sec_alm) {
        return [
            'kurs_tutar' => 0,
            'kitap_materyal_tutar' => SONUC_MATERYAL_TUTAR,
            'toplam_genel' => SONUC_MATERYAL_TUTAR,
            'hesap_detay' => ['hata' => 'En az bir dil seçilmelidir.'],
        ];
    }

    $roundUp = function($x) { return $x <= 0 ? 0 : (int)ceil($x / 100) * 100; };
    $sira_ind_ing = $GLOBALS['sonuc_fiyat_sira_indirim'];
    $sira_ind_alm_ozel = [1 => 30, 2 => 27, 3 => 24, 4 => 21, 5 => 18, 6 => 16, 7 => 13];

    $getEnglishNormal = function() use ($sira, $erken, $roundUp, $sira_ind_ing) {
        $indPct = isset($sira_ind_ing[$sira]) ? $sira_ind_ing[$sira] : ($sira >= 8 ? 10 : 0);
        $afterBurs = $sira > 0 ? $roundUp(SONUC_FIYAT_BAZ_INGILIZCE * (100 - $indPct) / 100) : $roundUp(SONUC_FIYAT_BAZ_INGILIZCE);
        $earlyInd = $erken ? ($afterBurs - $roundUp($afterBurs * 0.90)) : 0;
        return $afterBurs - $earlyInd;
    };
    $getEnglishNoBurs = function() use ($erken, $roundUp) {
        $earlyInd = $erken ? (SONUC_FIYAT_BAZ_INGILIZCE - $roundUp(SONUC_FIYAT_BAZ_INGILIZCE * 0.90)) : 0;
        return SONUC_FIYAT_BAZ_INGILIZCE - $earlyInd;
    };
    $getGermanSimple = function() use ($erken, $roundUp) {
        $hedef50 = $roundUp(SONUC_FIYAT_BAZ_ALMANCA * 0.50);
        $hedef60 = $roundUp(SONUC_FIYAT_BAZ_ALMANCA * 0.40);
        $earlyInd = $erken ? max(0, $hedef50 - $hedef60) : 0;
        return $hedef50 - $earlyInd;
    };
    $getGermanAdvanced = function() use ($sira, $erken, $roundUp, $sira_ind_alm_ozel) {
        $bursPct = isset($sira_ind_alm_ozel[$sira]) ? $sira_ind_alm_ozel[$sira] : 10;
        $bursInd = $roundUp(SONUC_FIYAT_BAZ_ALMANCA * ($bursPct / 100));
        $hedef50 = $roundUp(SONUC_FIYAT_BAZ_ALMANCA * 0.50);
        $ekInd = max(0, SONUC_FIYAT_BAZ_ALMANCA - $hedef50 - $bursInd);
        $after50 = SONUC_FIYAT_BAZ_ALMANCA - $bursInd - $ekInd;
        $hedef60 = $roundUp(SONUC_FIYAT_BAZ_ALMANCA * 0.40);
        $earlyInd = $erken ? max(0, $after50 - $hedef60) : 0;
        return $after50 - $earlyInd;
    };

    $kurs_ing = 0;
    $kurs_alm = 0;

    if ($profil === 'onlyEnglish') {
        if ($sec_ing) $kurs_ing = $getEnglishNormal();
        if ($sec_alm) $kurs_alm = $getGermanSimple();
    } elseif ($profil === 'onlyGerman') {
        if ($sec_ing && $sec_alm) {
            $kurs_ing = $getEnglishNoBurs();
            $kurs_alm = $getGermanAdvanced();
        } elseif ($sec_ing) {
            $kurs_ing = $getEnglishNoBurs();
        } elseif ($sec_alm) {
            $kurs_alm = $getGermanAdvanced();
        }
    } else { // both
        if ($sec_ing) $kurs_ing = $getEnglishNormal();
        if ($sec_alm) $kurs_alm = $getGermanAdvanced();
    }

    $kurs_tutar = (int)$kurs_ing + (int)$kurs_alm;
    $kitap_tutar = ($sec_ing && $sec_alm && !$kampanya_aktif)
        ? (SONUC_MATERYAL_TUTAR * 2)
        : SONUC_MATERYAL_TUTAR;
    $toplam = $kurs_tutar + $kitap_tutar;

    return [
        'kurs_tutar' => $kurs_tutar,
        'kitap_materyal_tutar' => $kitap_tutar,
        'toplam_genel' => $toplam,
        'hesap_detay' => [
            'profil' => $profil,
            'secimler' => ['ingilizce' => $sec_ing ? 1 : 0, 'almanca' => $sec_alm ? 1 : 0],
            'kurs_ingilizce' => $kurs_ing,
            'kurs_almanca' => $kurs_alm,
            'kampanya_aktif' => $kampanya_aktif ? 1 : 0,
            'kurs_tutar' => $kurs_tutar,
            'kitap_materyal_tutar' => $kitap_tutar,
            'toplam_genel' => $toplam,
        ],
    ];
}
