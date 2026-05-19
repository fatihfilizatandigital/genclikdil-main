<?php
/**
 * Sınıf filtreleri: 9, 10, 11, 12 → "Lise" olarak birleştirilir; diğer sınıflar aynen.
 */
define('LISE_SINIFLAR', ['9. sınıf', '10. sınıf', '11. sınıf', '12. sınıf']);

function panel_sinif_lise_mi($sinif) {
    return in_array($sinif, LISE_SINIFLAR, true);
}

/** Veritabanından gelen distinct sinif listesinden dropdown seçeneklerini üretir (1.-8. + Lise). */
function panel_sinif_dropdown_opts($siniflar_raw) {
    $opts = [];
    $has_lise = false;
    foreach ($siniflar_raw as $s) {
        if (panel_sinif_lise_mi($s)) {
            $has_lise = true;
            continue;
        }
        $opts[] = ['value' => $s, 'label' => $s];
    }
    usort($opts, function ($a, $b) {
        $a_n = (int)preg_replace('/\D/', '', $a['value']);
        $b_n = (int)preg_replace('/\D/', '', $b['value']);
        return $a_n <=> $b_n;
    });
    if ($has_lise) {
        $opts[] = ['value' => 'Lise', 'label' => 'Lise'];
    }
    return $opts;
}

/** Filtre değeri "Lise" ise SQL için sinif IN (9,10,11,12) döner; değilse tek değer. */
function panel_sinif_where_sql($sinif_filtre) {
    if ($sinif_filtre === 'Lise') {
        return ["sinif IN ('9. sınıf','10. sınıf','11. sınıf','12. sınıf')", []];
    }
    if ($sinif_filtre !== '') {
        return ['sinif = ?', [$sinif_filtre]];
    }
    return [null, []];
}
