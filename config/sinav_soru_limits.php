<?php
/**
 * Sınav türü ve sınıfa göre sabit soru sayıları (Okuma-Yazma / Dinleme-Konuşma).
 * Not girişi ve not düzenleme sayfalarında bu değerlere göre validasyon yapılır.
 */

function sinav_soru_limits_get($sinif, $sinav_turu) {
    $sinif = trim((string) $sinif);
    $sinav_turu = trim((string) $sinav_turu);

    if ($sinav_turu === 'Almanca') {
        $almanca_lise_siniflar = ['9. sınıf', '10. sınıf', '11. sınıf', '12. sınıf', 'Lise'];
        return ['oy' => in_array($sinif, $almanca_lise_siniflar, true) ? 50 : 30, 'dk' => 0];
    }

    if ($sinav_turu !== 'İngilizce') {
        return null;
    }

    $ingilizce_limits = [
        '2. sınıf'  => ['oy' => 25, 'dk' => 0],
        '3. sınıf'  => ['oy' => 30, 'dk' => 10],
        '4. sınıf'  => ['oy' => 35, 'dk' => 5],
        '5. sınıf'  => ['oy' => 40, 'dk' => 5],
        '6. sınıf'  => ['oy' => 37, 'dk' => 3],
        '7. sınıf'  => ['oy' => 35, 'dk' => 5],
        '8. sınıf'  => ['oy' => 40, 'dk' => 5],
        '9. sınıf'  => ['oy' => 49, 'dk' => 10],
        '10. sınıf' => ['oy' => 49, 'dk' => 10],
        '11. sınıf' => ['oy' => 49, 'dk' => 10],
        '12. sınıf' => ['oy' => 49, 'dk' => 10],
    ];

    return $ingilizce_limits[$sinif] ?? null;
}

/**
 * Girilen notların limitlere uygun olup olmadığını kontrol eder.
 * @return array ['ok' => bool, 'mesaj' => string]
 */
function sinav_soru_limits_validate($sinif, $sinav_turu, $oy_dogru, $oy_yanlis, $oy_bos, $dk_dogru, $dk_yanlis, $dk_bos) {
    $limits = sinav_soru_limits_get($sinif, $sinav_turu);
    if ($limits === null) {
        return ['ok' => false, 'mesaj' => 'Bu sınıf ve sınav türü için geçerli soru limiti tanımlı değil.'];
    }

    $oy_toplam_giren = $oy_dogru + $oy_yanlis + $oy_bos;
    $dk_toplam_giren = $dk_dogru + $dk_yanlis + $dk_bos;

    if ($oy_toplam_giren !== $limits['oy']) {
        return [
            'ok' => false,
            'mesaj' => sprintf(
                'Okuma-Yazma toplamı %d olmalı (doğru + yanlış + boş = %d). Sizin girişiniz: %d.',
                $limits['oy'],
                $limits['oy'],
                $oy_toplam_giren
            ),
        ];
    }

    if ($limits['dk'] === 0) {
        if ($dk_toplam_giren !== 0) {
            return [
                'ok' => false,
                'mesaj' => 'Bu sınıf/sınav türünde Dinleme-Konuşma bölümü yok; tüm değerler 0 olmalı.',
            ];
        }
    } else {
        if ($dk_toplam_giren !== $limits['dk']) {
            return [
                'ok' => false,
                'mesaj' => sprintf(
                    'Dinleme-Konuşma toplamı %d olmalı (doğru + yanlış + boş = %d). Sizin girişiniz: %d.',
                    $limits['dk'],
                    $limits['dk'],
                    $dk_toplam_giren
                ),
            ];
        }
    }

    return ['ok' => true, 'mesaj' => ''];
}
