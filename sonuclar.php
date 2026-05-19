<?php
/**
 * Bursluluk sınav sonucu ve kayıt sayfası (veli).
 * Link: /sonuclar.php?t=TOKEN&ad=...
 * Tek sayfa düzeni: karşılama solda, sınav bilgisi sağda; özelleştirme + fiyat tablosu yan yana; ödeme tablonun hemen altında.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/sonuc_fiyat_hesap.php';
require_once __DIR__ . '/config/teklif_v2.php';

$token = isset($_GET['t']) ? trim($_GET['t']) : '';
$teklif = null;
$ogrenci = null;
$sinav = null;
$sinav_ingilizce = null;
$sinav_almanca = null;
$sinav_bloklari = [];
$sinav_ozet_kartlari = [];
$toplam_katilimci = null;
$yuzdelik_dilim = null;
$teklif_v2_id = 0;
$odeme_modu = 'ayri';
$kurs_odendi = false;
$kitap_odendi = false;
$toplu_odendi = false;
$kitap_kilitli = true;
$veli_secimi_kilitli = false;
$veli_default_mod = 'toplu';
$sinav_turu_link = '';
$sinav_turleri_link = [];
$sinav_almanca_mi = false;
$ana_dil_label = 'İngilizce';
$ek_dil_label = 'Almanca';
$secenek_dil_label = 'Almanca istiyorum';
$secenek_dil_aciklama = 'Almanca bursluluk sınavına girdiyseniz bu kutucuğu işaretlemeyin.';

teklif_v2_ensure_schema($conn);
if ($token !== '' && strlen($token) <= 64 && ctype_xdigit($token)) {
    $teklif = teklif_v2_get_by_token($conn, $token);
    if ($teklif) {
        $teklif_v2_id = (int)($teklif['id'] ?? 0);
        $odeme_modu = ($teklif['odeme_modu'] ?? 'ayri') === 'toplu' ? 'toplu' : 'ayri';
        $sinav_turu_link = trim((string)($teklif['sinav_turu'] ?? ''));
        $sid_ing = isset($teklif['sinav_sonuc_id_ingilizce']) && $teklif['sinav_sonuc_id_ingilizce'] !== '' ? (int)$teklif['sinav_sonuc_id_ingilizce'] : 0;
        $sid_alm = isset($teklif['sinav_sonuc_id_almanca']) && $teklif['sinav_sonuc_id_almanca'] !== '' ? (int)$teklif['sinav_sonuc_id_almanca'] : 0;
        $sid_legacy = isset($teklif['sinav_sonuc_id']) && $teklif['sinav_sonuc_id'] !== '' ? (int)$teklif['sinav_sonuc_id'] : 0;
        $turu_legacy = mb_strtolower($sinav_turu_link, 'UTF-8');
        if ($sid_ing <= 0 && $sid_legacy > 0 && strpos($turu_legacy, 'ingilizce') !== false) $sid_ing = $sid_legacy;
        if ($sid_alm <= 0 && $sid_legacy > 0 && strpos($turu_legacy, 'almanca') !== false) $sid_alm = $sid_legacy;
        if ($sid_ing <= 0 && $sid_alm <= 0 && $sid_legacy > 0) $sid_ing = $sid_legacy;

        $sinav_almanca_mi = ($sid_ing <= 0 && $sid_alm > 0);
        if ($sinav_almanca_mi) {
            $ana_dil_label = 'Almanca';
            $ek_dil_label = 'İngilizce';
            $secenek_dil_label = 'İngilizce istiyorum';
            $secenek_dil_aciklama = 'İngilizce bursluluk sınavına girdiyseniz bu kutucuğu işaretlemeyin.';
        }

        $steps = teklif_v2_get_steps($conn, $teklif_v2_id);
        foreach ($steps as $st) {
            $adim = $st['adim'] ?? '';
            $durum = $st['durum'] ?? 'bekliyor';
            if ($adim === 'kurs') $kurs_odendi = ($durum === 'success');
            if ($adim === 'kitap_materyal') {
                $kitap_odendi = ($durum === 'success');
                $kitap_kilitli = ($durum === 'locked');
            }
            if ($adim === 'toplu') $toplu_odendi = ($durum === 'success');
        }
        if ($odeme_modu === 'ayri') {
            if ($kurs_odendi && !$kitap_odendi && $kitap_kilitli) {
                $kitap_kilitli = false;
            }
        }
        $ayri_odeme_var = $kurs_odendi || $kitap_odendi;
        if ($toplu_odendi) {
            $veli_secimi_kilitli = true;
            $veli_default_mod = 'toplu';
        } elseif ($ayri_odeme_var) {
            $veli_secimi_kilitli = true;
            $veli_default_mod = 'ayri';
        } else {
            $veli_default_mod = 'toplu';
        }

        $ogrenci = [
            'ad_soyad' => trim(($teklif['ogrenci_ad'] ?? '') . ' ' . ($teklif['ogrenci_soyad'] ?? '')),
            'sinif' => (int)($teklif['sinif'] ?? 0),
            'sinif_ici_sira' => isset($teklif['sinif_ici_sira']) && $teklif['sinif_ici_sira'] !== null && $teklif['sinif_ici_sira'] !== '' ? (int)$teklif['sinif_ici_sira'] : null,
            'liste_basari' => isset($teklif['liste_basari']) && $teklif['liste_basari'] !== null ? (float)$teklif['liste_basari'] : null,
        ];
        $fetch_sinav = static function(mysqli $conn, int $sid): ?array {
            if ($sid <= 0) return null;
            $st2 = mysqli_prepare($conn, "SELECT * FROM sinav_sonuclari WHERE id = ? LIMIT 1");
            if (!$st2) return null;
            mysqli_stmt_bind_param($st2, "i", $sid);
            mysqli_stmt_execute($st2);
            $r2 = mysqli_stmt_get_result($st2);
            mysqli_stmt_close($st2);
            return ($r2 && mysqli_num_rows($r2) > 0) ? mysqli_fetch_assoc($r2) : null;
        };
        $sinav_ingilizce = $fetch_sinav($conn, $sid_ing);
        $sinav_almanca = $fetch_sinav($conn, $sid_alm);
        $sinav = $sinav_ingilizce ?: $sinav_almanca;
        if ($sinav_ingilizce) $sinav_turleri_link[] = 'İngilizce';
        if ($sinav_almanca) $sinav_turleri_link[] = 'Almanca';
        if (empty($sinav_turleri_link) && $sinav_turu_link !== '') $sinav_turleri_link[] = $sinav_turu_link;
        if (!empty($sinav_turleri_link)) $sinav_turu_link = implode(' + ', $sinav_turleri_link);

        $sinif_val = (int)($teklif['sinif'] ?? 0);
        $lise_siniflar = [9, 10, 11, 12];
        $is_lise = in_array($sinif_val, $lise_siniflar, true);
        if ($sinif_val > 0) {
            $has_dogum = false;
            $dc = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari LIKE 'dogum_tarihi'");
            if ($dc && mysqli_num_rows($dc) > 0) $has_dogum = true;

            $rank_hesapla = static function(mysqli $conn, int $sinif_val, bool $is_lise, bool $has_dogum, int $sinav_sonuc_id, ?string $sinav_turu_val, array $lise_siniflar): array {
                if ($sinav_sonuc_id <= 0) return ['sira' => null, 'toplam' => null, 'yuzdelik' => null];
                $sinav_turu_lower = mb_strtolower(trim((string)($sinav_turu_val ?? '')), 'UTF-8');
                $almanca_grup_hesabi = ($sinav_turu_lower !== '' && strpos($sinav_turu_lower, 'almanca') !== false);

                $rank_sql = "SELECT id FROM sinav_sonuclari WHERE ";
                $bind_types = '';
                if ($almanca_grup_hesabi) {
                    // Almanca için sınıf bandı: 2-4 ilkokul, 5-8 ortaokul, 9-12 lise
                    if (in_array($sinif_val, [2, 3, 4], true)) {
                        $rank_sql .= "sinif IN (2,3,4)";
                    } elseif (in_array($sinif_val, [5, 6, 7, 8], true)) {
                        $rank_sql .= "sinif IN (5,6,7,8)";
                    } elseif (in_array($sinif_val, $lise_siniflar, true)) {
                        $rank_sql .= "sinif IN (9,10,11,12)";
                    } else {
                        $rank_sql .= "sinif = ?";
                        $bind_types .= 'i';
                    }
                } else {
                    // İngilizce mevcut davranışı korur: sınıf bazlı, lisede 9-12 birleşik
                    if ($is_lise) {
                        $rank_sql .= "sinif IN (9,10,11,12)";
                    } else {
                        $rank_sql .= "sinif = ?";
                        $bind_types .= 'i';
                    }
                }
                if ($sinav_turu_val !== null) {
                    $rank_sql .= " AND (sinav_turu <=> ?)";
                    $bind_types .= 's';
                } else {
                    $rank_sql .= " AND (sinav_turu IS NULL OR sinav_turu = '')";
                }
                $rank_sql .= " ORDER BY basari_yuzdesi DESC, toplam_yanlis ASC";
                if ($has_dogum) $rank_sql .= ", (CASE WHEN dogum_tarihi IS NULL THEN 1 ELSE 0 END) ASC, dogum_tarihi DESC";
                $rank_sql .= ", id ASC";

                if ($bind_types === '') {
                    $rs = mysqli_query($conn, $rank_sql);
                } else {
                    $str = mysqli_prepare($conn, $rank_sql);
                    if (!$str) return ['sira' => null, 'toplam' => null, 'yuzdelik' => null];
                    if ($bind_types === 'i') {
                        mysqli_stmt_bind_param($str, "i", $sinif_val);
                    } elseif ($bind_types === 's') {
                        mysqli_stmt_bind_param($str, "s", $sinav_turu_val);
                    } elseif ($bind_types === 'is') {
                        mysqli_stmt_bind_param($str, "is", $sinif_val, $sinav_turu_val);
                    }
                    mysqli_stmt_execute($str);
                    $rs = mysqli_stmt_get_result($str);
                    mysqli_stmt_close($str);
                }
                $sira = null;
                $rank = 0;
                if ($rs) {
                    while ($rr = mysqli_fetch_assoc($rs)) {
                        $rank++;
                        if ((int)$rr['id'] === (int)$sinav_sonuc_id) $sira = $rank;
                    }
                }
                $toplam = $rank > 0 ? $rank : null;
				$toplam = $toplam * 2;
                $yuzdelik = ($sira !== null && $toplam !== null && $toplam > 0)
                    ? round(($toplam - $sira + 1) / $toplam * 100, 1)
                    : null;
                return ['sira' => $sira, 'toplam' => $toplam, 'yuzdelik' => $yuzdelik];
            };

            if ($sinav_ingilizce && $sid_ing > 0) {
                $r = $rank_hesapla($conn, $sinif_val, $is_lise, $has_dogum, $sid_ing, 'İngilizce', $lise_siniflar);
                $sinav_ozet_kartlari[] = ['label' => 'İngilizce', 'sira' => $r['sira'], 'toplam' => $r['toplam'], 'yuzdelik' => $r['yuzdelik']];
            }
            if ($sinav_almanca && $sid_alm > 0) {
                $r = $rank_hesapla($conn, $sinif_val, $is_lise, $has_dogum, $sid_alm, 'Almanca', $lise_siniflar);
                $sinav_ozet_kartlari[] = ['label' => 'Almanca', 'sira' => $r['sira'], 'toplam' => $r['toplam'], 'yuzdelik' => $r['yuzdelik']];
            }
            if (empty($sinav_ozet_kartlari) && $sid_legacy > 0) {
                $r = $rank_hesapla($conn, $sinif_val, $is_lise, $has_dogum, $sid_legacy, $teklif['sinav_turu'] ?? null, $lise_siniflar);
                $etiket = trim((string)($teklif['sinav_turu'] ?? ''));
                $sinav_ozet_kartlari[] = ['label' => ($etiket !== '' ? $etiket : 'Sınav'), 'sira' => $r['sira'], 'toplam' => $r['toplam'], 'yuzdelik' => $r['yuzdelik']];
            }

            $primary_ozet = null;
            foreach ($sinav_ozet_kartlari as $oz) {
                if (($oz['label'] ?? '') === 'İngilizce') { $primary_ozet = $oz; break; }
            }
            if ($primary_ozet === null && !empty($sinav_ozet_kartlari)) $primary_ozet = $sinav_ozet_kartlari[0];
            if ($primary_ozet !== null) {
                $ogrenci['sinif_ici_sira'] = $primary_ozet['sira'];
                $toplam_katilimci = $primary_ozet['toplam'];
                $yuzdelik_dilim = $primary_ozet['yuzdelik'];
            }
        }
    }
}

$bulunamadi = ($teklif === null);
$sira_php = $ogrenci['sinif_ici_sira'] !== null ? (int)$ogrenci['sinif_ici_sira'] : 0;
$ogrenci_tam_ad = trim((string)($ogrenci['ad_soyad'] ?? ''));
$ferman_ad = $ogrenci_tam_ad !== '' ? $ogrenci_tam_ad : 'Öğrenci Ad Soyad';
$sinif_etiket = '';
if (!$bulunamadi && isset($ogrenci['sinif']) && $ogrenci['sinif'] !== null && $ogrenci['sinif'] !== '') {
    if (is_numeric($ogrenci['sinif'])) {
        $sinif_etiket = (int)$ogrenci['sinif'] . '. sınıf';
    } else {
        $sinif_etiket = trim((string)$ogrenci['sinif']);
        $sinif_etiket = preg_replace('/^\s*sınıf\s*:\s*/iu', '', $sinif_etiket);
    }
}
$simdi = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
$erken_kayit_bitis = new DateTimeImmutable('2026-04-30 23:59:59', new DateTimeZone('Europe/Istanbul'));
$erken_kayit_aktif = $simdi <= $erken_kayit_bitis;
$erken_kayit_metin = $erken_kayit_bitis->format('j') . ' Nisan ' . $erken_kayit_bitis->format('Y') . '\'ya kadar';

// Sınav tablo değişkenleri (PHP) - tek veya çift sınav bloğu
$hazirla_sinav_blok = static function(array $sinav_veri, int $ogrenci_sinif_no): array {
    $oy_d = (int)($sinav_veri['okuma_yazma_dogru'] ?? 0);
    $oy_y = (int)($sinav_veri['okuma_yazma_yanlis'] ?? 0);
    $oy_b = (int)($sinav_veri['okuma_yazma_bos'] ?? 0);
    $dk_d = (int)($sinav_veri['dinleme_konusma_dogru'] ?? 0);
    $dk_y = (int)($sinav_veri['dinleme_konusma_yanlis'] ?? 0);
    $dk_b = (int)($sinav_veri['dinleme_konusma_bos'] ?? 0);
    $oy_toplam = $oy_d + $oy_y + $oy_b;
    $dk_toplam = $dk_d + $dk_y + $dk_b;
    $dk_tumu_sifir = ($dk_d === 0 && $dk_y === 0 && $dk_b === 0);
    return [
        'oy_d' => $oy_d,
        'oy_y' => $oy_y,
        'oy_b' => $oy_b,
        'dk_d' => $dk_d,
        'dk_y' => $dk_y,
        'dk_b' => $dk_b,
        'oy_toplam' => $oy_toplam,
        'dk_toplam' => $dk_toplam,
        'toplam_d' => (int)($sinav_veri['toplam_dogru'] ?? 0),
        'toplam_y' => (int)($sinav_veri['toplam_yanlis'] ?? 0),
        'toplam_b' => (int)($sinav_veri['toplam_bos'] ?? 0),
        'toplam_soru' => (int)($sinav_veri['toplam_soru'] ?? 0),
        'basari_pct' => (float)($sinav_veri['basari_yuzdesi'] ?? 0),
        'oy_basari' => $oy_toplam > 0 ? round($oy_d / $oy_toplam * 100, 1) : 0,
        'dk_basari' => $dk_toplam > 0 ? round($dk_d / $dk_toplam * 100, 1) : 0,
        'dk_satiri_goster' => !($ogrenci_sinif_no === 2 || $dk_tumu_sifir),
    ];
};
$ogrenci_sinif_no = (int)($ogrenci['sinif'] ?? 0);
if (!$bulunamadi && $sinav_ingilizce) {
    $sinav_bloklari[] = ['label' => 'İngilizce', 'metrik' => $hazirla_sinav_blok($sinav_ingilizce, $ogrenci_sinif_no)];
}
if (!$bulunamadi && $sinav_almanca) {
    $sinav_bloklari[] = ['label' => 'Almanca', 'metrik' => $hazirla_sinav_blok($sinav_almanca, $ogrenci_sinif_no)];
}
if (empty($sinav_bloklari) && !$bulunamadi && $sinav) {
    $sinav_bloklari[] = ['label' => $sinav_turu_link !== '' ? $sinav_turu_link : 'Sınav', 'metrik' => $hazirla_sinav_blok($sinav, $ogrenci_sinif_no)];
}
$sinav_profil = 'onlyEnglish';
if (!$bulunamadi) {
    $hasEnglishExam = $sinav_ingilizce !== null;
    $hasGermanExam = $sinav_almanca !== null;
    if ($hasEnglishExam && $hasGermanExam) {
        $sinav_profil = 'both';
    } elseif (!$hasEnglishExam && $hasGermanExam) {
        $sinav_profil = 'onlyGerman';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title><?= $bulunamadi ? 'Sonuç bulunamadı' : 'Bursluluk sınav sonucu — ' . htmlspecialchars($ogrenci['ad_soyad'] ?? '') ?> | Gençlik Dil</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="resimler/logoGenclik.jpg">
    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --gd-primary: #187f9e;
            --gd-primary-dark: #146c88;
            --gd-primary-soft: #e8f4f8;
            --gd-bg: #edf3f9;
            --gd-card: #fff;
            --gd-border: #d3e1ec;
            --gd-text: #1e293b;
            --gd-muted: #64748b;
            --gd-shadow: 0 14px 36px rgba(15, 23, 42, 0.07);
            --gd-radius: 16px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: radial-gradient(circle at 5% 5%, #f9fcff, var(--gd-bg) 42%);
            color: var(--gd-text);
            display: flex;
            flex-direction: column;
            font-size: 18.5px;
            line-height: 1.55;
        }
        .s-header {
            flex: 0 0 auto;
            padding: 0;
            background: rgba(255, 255, 255, 0.93);
            backdrop-filter: blur(6px);
            border-bottom: 1px solid var(--gd-border);
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .s-header-inner {
            width: 100%;
            max-width: 1360px;
            margin: 0 auto;
            padding: 11px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .s-header .logo img { height: 46px; width: auto; display: block; }
        .s-header nav { display: flex; align-items: center; gap: 10px; }
        .s-header a {
            color: var(--gd-muted);
            text-decoration: none;
            font-size: 1.08rem;
            font-weight: 700;
            padding: 8px 13px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid var(--gd-border);
            display: inline-block;
            line-height: 1.1;
            transition: all 0.2s ease;
        }
        .s-header a:hover { color: var(--gd-primary); border-color: #bcd7d3; transform: translateY(-1px); }
        .s-main { flex: 1 0 auto; width: 100%; max-width: 1360px; margin: 0 auto; padding: 34px 24px 48px; }
        .s-shell { background: rgba(255,255,255,0.6); border: 1px solid #e3ebf5; border-radius: 20px; padding: 20px; box-shadow: var(--gd-shadow); }
        .s-section { margin-bottom: 22px; }
        .s-row { display: grid; grid-template-columns: minmax(280px, 2fr) minmax(0, 3fr); gap: 22px; align-items: stretch; margin-bottom: 0; }
        @media (max-width: 768px) { .s-row { grid-template-columns: 1fr; } }
        .s-welcome {
            background:
                radial-gradient(circle at 10% 12%, rgba(255,255,255,0.18), rgba(255,255,255,0) 34%),
                linear-gradient(145deg, #1d6fa2 0%, #136f8a 58%, #0f7a77 100%);
            color: #fff;
            padding: 30px;
            border-radius: var(--gd-radius);
            border: 1px solid rgba(255,255,255,0.24);
            box-shadow: 0 16px 32px rgba(15, 72, 116, 0.26);
        }
        .s-welcome h1 { font-size: 1.96rem; font-weight: 700; margin: 0 0 10px 0; line-height: 1.24; }
        .s-welcome h1 .k { display: block; font-size: 1.02rem; opacity: 0.9; font-weight: 600; margin-bottom: 6px; }
        .s-welcome h1 .n { display: block; font-size: 1.5rem; font-weight: 800; letter-spacing: 0.01em; }
        .s-welcome .sub { font-size: 1.06rem; opacity: 0.96; line-height: 1.56; margin: 0; display: block; }
        .s-welcome .sub-meta { margin-top: 8px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .s-welcome .sinif-inline {
            font-size: 0.96rem;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.14);
            border-radius: 999px;
            padding: 6px 10px;
            line-height: 1;
        }
        .s-rank-grid {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .s-rank-box {
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.34);
            background: rgba(255,255,255,0.14);
        }
        .s-rank-box .k {
            font-size: 0.92rem;
            letter-spacing: 0.03em;
            opacity: 0.92;
            font-weight: 700;
            text-transform: uppercase;
        }
        .s-rank-box .v {
            font-size: 2.1rem;
            line-height: 1;
            font-weight: 800;
            margin-top: 4px;
        }
        .s-rank-box .meta {
            font-size: 0.93rem;
            opacity: 0.95;
            margin-top: 6px;
            line-height: 1.35;
        }
        .s-kv { margin: 16px 0 0; display: flex; flex-wrap: wrap; gap: 10px; }
        .s-kv .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.16);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 0.94rem;
            font-weight: 700;
            line-height: 1;
        }
        .s-card { background: var(--gd-card); border: 1px solid var(--gd-border); border-radius: var(--gd-radius); box-shadow: 0 6px 20px rgba(15,23,42,0.04); overflow: hidden; }
        .s-sinav-wrap { background: var(--gd-card); border-radius: var(--gd-radius); border: 1px solid var(--gd-border); overflow: hidden; }
        .s-sinav-wrap .tit { padding: 14px 18px; background: #f7fafc; font-weight: 700; font-size: 1.05rem; color: #475569; border-bottom: 1px solid var(--gd-border); text-transform: uppercase; letter-spacing: 0.04em; }
        .s-sinav-wrap .body { padding: 22px; }
        .s-tbl { width: 100%; border-collapse: collapse; font-size: 1.08rem; }
        .s-tbl th, .s-tbl td { padding: 12px 12px; text-align: left; border: 1px solid #e4ebf3; }
        .s-tbl th { background: #f8fafc; font-weight: 700; color: #5b6d82; }
        .s-tbl td.sayi { text-align: center; font-weight: 500; }
        .s-tbl tr.toplam-row th, .s-tbl tr.toplam-row td { background: #e9f4f8; font-weight: 700; }
        .erken-kayit-avantaj {
            background: linear-gradient(135deg, rgba(24,127,158,0.06) 0%, rgba(29,111,162,0.1) 50%, rgba(20,108,136,0.08) 100%);
            border: 1px solid rgba(24,127,158,0.25);
            border-radius: 14px;
            padding: 22px 24px;
            margin-bottom: 28px;
            box-shadow: 0 6px 24px rgba(20,108,136,0.1);
        }
        .erken-kayit-avantaj .eka-baslik {
            font-size: 1.22rem;
            font-weight: 800;
            color: #146c88;
            margin-bottom: 8px;
            letter-spacing: 0.02em;
        }
        .erken-kayit-avantaj .eka-aciklama {
            font-size: 1rem;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 18px;
        }
        .erken-kayit-avantaj .eka-tbl { width: 100%; border-collapse: collapse; font-size: 1.02rem; }
        .erken-kayit-avantaj .eka-tbl th,
        .erken-kayit-avantaj .eka-tbl td { padding: 12px 14px; text-align: left; border: 1px solid rgba(24,127,158,0.2); }
        .erken-kayit-avantaj .eka-tbl th { background: rgba(24,127,158,0.12); font-weight: 700; color: #136f8a; }
        .erken-kayit-avantaj .eka-tbl td:first-child { font-weight: 600; color: #146c88; white-space: nowrap; width: 1%; }
        .erken-kayit-avantaj .eka-tbl tr:nth-child(even) td { background: rgba(232,244,248,0.6); }
        .s-fiyat-row { display: grid; grid-template-columns: minmax(280px, 2fr) minmax(0, 3fr); gap: 22px; align-items: start; margin-bottom: 0; }
        @media (max-width: 700px) { .s-fiyat-row { grid-template-columns: 1fr; } }
        .s-fiyat-left { display: flex; flex-direction: column; gap: 22px; align-items: stretch; }
        .s-fiyat-left > .s-opts,
        .s-fiyat-left > .s-kitap-wrap { width: 100%; min-width: 0; }
        .s-kitap-wrap { background: #fef2f2; border: 2px solid #dc2626; border-radius: var(--gd-radius); padding: 18px; box-shadow: 0 4px 14px rgba(220,38,38,0.15); }
        .s-kitap-wrap .s-kitap-tit { font-weight: 700; font-size: 1.05rem; color: #991b1b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.04em; }
        .s-kitap-wrap .s-kitap-uyari { font-size: 0.95rem; color: #b91c1c; font-weight: 700; margin-bottom: 12px; }
        .s-kitap-wrap .s-fiyat-tbl th, .s-kitap-wrap .s-fiyat-tbl td { border-color: #fecaca; background: #fff; }
        .s-kitap-wrap .s-fiyat-tbl th { background: #fef2f2; color: #991b1b; font-weight: 700; }
        .s-kitap-wrap .s-fiyat-tbl tr.bold-row th, .s-kitap-wrap .s-fiyat-tbl tr.bold-row td { background: #fee2e2; color: #7f1d1d; }
        .s-kitap-wrap .s-fiyat-tbl tr.indirim-row td { background: #fef2f2; color: #b91c1c; font-weight: 600; }
        .s-opts { background: var(--gd-card); border: 1px solid var(--gd-border); border-radius: var(--gd-radius); padding: 20px; box-shadow: 0 6px 20px rgba(15,23,42,0.04); max-height: 340px; overflow-y: auto; }
        .s-opts .tit { font-weight: 700; font-size: 1.06rem; color: #334155; margin-bottom: 14px; text-transform: uppercase; letter-spacing: 0.04em; }
        .s-opts label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 1.08rem; font-weight: 600; margin-bottom: 12px; color: var(--gd-primary); transition: color 0.2s, transform 0.2s; }
        .s-opts label:hover { color: var(--gd-primary-dark); }
        .s-opts .opt-item {
            padding: 10px 14px;
            border-radius: 12px;
            margin-bottom: 0;
            animation: opt-glow-pulse 2.4s ease-in-out infinite;
        }
        .s-opts > .opt-item:last-child { margin-bottom: 0; }
        .s-opts label.opt-item { margin-bottom: 0; }
        .s-opts .opt-item + .opt-item { margin-top: 10px; }
        .s-opts label.opt-erken {
            background: linear-gradient(135deg, rgba(13,148,136,0.06) 0%, rgba(15,118,110,0.08) 100%);
            border: 1px solid rgba(13,148,136,0.2);
        }
        .s-opts label.opt-almanca {
            background: linear-gradient(135deg, rgba(13,148,136,0.08) 0%, rgba(15,118,110,0.12) 100%);
            border: 1px solid rgba(13,148,136,0.25);
        }
        .s-opts label.opt-ingilizce {
            background: linear-gradient(135deg, rgba(13,148,136,0.06) 0%, rgba(15,118,110,0.1) 100%);
            border: 1px solid rgba(13,148,136,0.22);
            box-shadow: 0 0 0 1px rgba(13,148,136,0.08) inset;
        }
        .s-opts label.opt-lang { align-items: flex-start; gap: 10px; }
        .s-opts .opt-lang-text { display: flex; flex-direction: column; gap: 3px; }
        .s-opts .opt-lang-text .main { font-size: 1.08rem; line-height: 1.2; }
        .s-opts .opt-lang-text .sub { font-size: 0.84rem; line-height: 1.45; color: #5f7489; font-weight: 500; }
        .s-opts label input[type="checkbox"] { width: 21px; height: 21px; accent-color: var(--gd-primary); cursor: pointer; flex-shrink: 0; }
        .s-opts .opt-erken-badge {
            display: flex; align-items: flex-start; gap: 10px;
            pointer-events: none;
            user-select: none;
            cursor: not-allowed;
            background: linear-gradient(135deg, rgba(13,148,136,0.06) 0%, rgba(15,118,110,0.1) 100%);
            border: 1px solid rgba(13,148,136,0.22);
            box-shadow: 0 0 0 1px rgba(13,148,136,0.08) inset;
            color: var(--gd-primary);
        }
        .s-opts .opt-erken-badge .opt-erken-tick {
            width: 21px; height: 21px;
            flex-shrink: 0;
            border-radius: 4px;
            background: rgba(13,148,136,0.9);
            border: 1px solid rgba(13,148,136,1);
            position: relative;
        }
        .s-opts .opt-erken-badge .opt-erken-tick::after {
            content: '';
            position: absolute;
            left: 50%; top: 50%;
            width: 10px; height: 6px;
            margin: -4px 0 0 -5px;
            border-left: 2px solid #fff;
            border-bottom: 2px solid #fff;
            transform: rotate(-45deg);
        }
        .s-opts .opt-erken-badge .opt-lang-text .main { font-size: 1.08rem; line-height: 1.2; color: var(--gd-primary); }
        .s-opts .opt-erken-badge .opt-lang-text .sub { color: #5f7489; }
        @keyframes opt-glow-pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(13,148,136,0.28), 0 0 14px rgba(13,148,136,0.1); }
            50% { box-shadow: 0 0 0 5px rgba(13,148,136,0.15), 0 0 28px rgba(13,148,136,0.22); }
        }
        .s-fiyat-wrap { background: var(--gd-card); border: 1px solid var(--gd-border); border-radius: var(--gd-radius); overflow: hidden; box-shadow: 0 6px 20px rgba(15,23,42,0.04); }
        .s-fiyat-wrap .ftit { padding: 14px 18px; background: #f7fafc; font-weight: 700; font-size: 1.05rem; color: #475569; border-bottom: 1px solid var(--gd-border); text-transform: uppercase; letter-spacing: 0.04em; }
        .s-fiyat-wrap .fbody { padding: 18px; }
        .s-fiyat-tbl { width: 100%; border-collapse: collapse; font-size: 1.08rem; }
        .s-fiyat-tbl th, .s-fiyat-tbl td { padding: 12px 12px; text-align: left; border: 1px solid #e4ebf3; }
        .s-fiyat-tbl th { background: #f8fafc; font-weight: 700; color: #5b6d82; }
        .s-fiyat-tbl .text-end { text-align: right; }
        .s-fiyat-tbl tr.bold-row th, .s-fiyat-tbl tr.bold-row td { font-weight: 700; background: rgba(24,127,158,0.14); }
        .s-tablo-baslik { font-weight: 700; font-size: 1.1rem; color: var(--gd-text); margin-bottom: 10px; }
        .s-tablo-baslik-ikinci { margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--gd-border); }
        .s-fiyat-tbl-ikinci { margin-top: 0; }
        .s-fiyat-tbl tr.indirim-row td { background: rgba(24,127,158,0.07); color: var(--gd-primary); font-weight: 600; }
        .s-odeme {
            margin-top: 22px;
            padding: 18px;
            border: 1px solid #d5e3f1;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15,23,42,0.07);
        }
        .s-odeme .odeme-title { font-size: 1.05rem; font-weight: 800; color: #1f3a52; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.04em; }
        .s-odeme .odeme-sub { font-size: 0.92rem; color: #60768b; margin-bottom: 12px; line-height: 1.45; }
        .odeme-top-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }
        .odeme-block {
            border: 1px solid #dfebf7;
            border-radius: 12px;
            padding: 10px 11px;
            background: linear-gradient(180deg, #fcfeff 0%, #f8fbff 100%);
        }
        .s-odeme .email-row { margin-bottom: 0; }
        .s-odeme .email-row label { display: block; font-size: 0.76rem; font-weight: 800; color: #5f7488; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; }
        .s-odeme .email-row input { width: 100%; padding: 10px 11px; border: 1px solid #d2e1ef; border-radius: 10px; font-size: 1rem; }
        .s-odeme .btns { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 4px; }
        .s-odeme .btn-odeme { background: linear-gradient(135deg, var(--gd-primary), var(--gd-primary-dark)); color: #fff !important; border: none; padding: 11px 16px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 1.03rem; text-decoration: none; display: inline-block; box-shadow: 0 8px 16px rgba(20,108,136,0.24); }
        .s-odeme .btn-odeme:hover { opacity: 0.94; color: #fff !important; }
        .s-odeme .btn-odeme.needs-approval { opacity: 0.72; box-shadow: none; }
        .s-odeme .btn-odeme span.amt { margin-left: 8px; opacity: 0.9; font-weight: 600; }
        .s-odeme .odeme-onay-uyari { width: 100%; margin-top: -2px; color: #b45309; font-size: 0.9rem; font-weight: 700; display: none; }
        .odeme-toplam-metin { margin-left: 12px; font-weight: 700; color: var(--gd-primary); font-size: 1.05rem; vertical-align: middle; }
        .odeme-sozlesme {
            margin: 10px 0 12px;
            padding: 10px 11px;
            border-radius: 10px;
            border: 1px solid #d6e3ef;
            background: #f9fcff;
            color: #415a72;
            font-size: 0.91rem;
            line-height: 1.45;
        }
        .odeme-sozlesme-materyal-ayri { border-color: #fca5a5; background: #fef2f2; color: #b91c1c; }
        .odeme-sozlesme-materyal-ayri label, .odeme-sozlesme-materyal-ayri label span { color: #b91c1c; font-weight: 700; }
        .tam-burs-bilgi {
            display: none;
            margin-bottom: 16px;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid rgba(24,127,158,0.35);
            background: linear-gradient(135deg, rgba(24,127,158,0.08) 0%, rgba(20,108,136,0.12) 100%);
            color: #146c88;
            font-size: 0.95rem;
            font-weight: 600;
            line-height: 1.45;
        }
        .tam-burs-bilgi.is-visible { display: block; }
        .tam-burs-overlay {
            position: fixed;
            inset: 0;
            z-index: 9998;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, 0.5);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .tam-burs-overlay.is-visible { opacity: 1; visibility: visible; pointer-events: auto; }
        .tam-burs-box {
            width: min(420px, 100%);
            background: var(--gd-card);
            border: 1px solid var(--gd-border);
            border-radius: var(--gd-radius);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
            padding: 24px 26px;
            text-align: center;
        }
        .tam-burs-box h3 { margin: 0 0 12px 0; font-size: 1.2rem; color: var(--gd-primary); font-weight: 800; }
        .tam-burs-box p { margin: 0 0 20px 0; font-size: 1rem; color: var(--gd-text); line-height: 1.5; }
        .tam-burs-box .tam-burs-btns { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .tam-burs-box .btn-tam-burs { padding: 10px 20px; border-radius: 10px; font-weight: 700; font-size: 0.98rem; cursor: pointer; border: none; transition: opacity 0.2s; }
        .tam-burs-box .btn-tam-burs-devam { background: linear-gradient(135deg, var(--gd-primary), var(--gd-primary-dark)); color: #fff; }
        .tam-burs-box .btn-tam-burs-devam:hover { opacity: 0.92; }
        .tam-burs-box .btn-tam-burs-iptal { background: #f1f5f9; color: #475569; border: 1px solid var(--gd-border); }
        .tam-burs-box .btn-tam-burs-iptal:hover { background: #e2e8f0; }
        .odeme-sozlesme label { display:flex; align-items:flex-start; gap:10px; font-weight:700; cursor:pointer; color: #1f557f; }
        .odeme-sozlesme input[type="checkbox"] { margin-top:2px; width:18px; height:18px; accent-color: var(--gd-primary); flex: 0 0 auto; }
        .footer-bar { flex: 0 0 auto; background-color: #2c3e50; color: #ecf0f1; padding: 15px 0; font-size: 14px; text-align: center; border-top: 4px solid #3498db; margin-top: 0 !important; }
        .footer-container { display: flex; justify-content: center; gap: 30px; flex-wrap: wrap; max-width: 1360px; margin: 0 auto; padding: 0 24px; }
        .contact-item a { color: #ecf0f1; text-decoration: none; display: flex; align-items: center; }
        .contact-item a:hover { color: #f1c40f; }
        .contact-item i { margin-right: 8px; color: #3498db; }
        .whatsapp-link i { color: #25D366 !important; font-size: 18px; }
        @media (max-width: 768px) { .footer-container { flex-direction: column; gap: 15px; } }
        .s-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 1.02rem; }
        .ferman-fab {
            text-decoration: none;
            color: inherit;
            position: fixed;
            right: 22px;
            bottom: 20px;
            z-index: 120;
            background: transparent;
            border: 0;
            cursor: pointer;
            padding: 0;
            width: 118px;
            height: 132px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            animation: ferman-float 2.9s ease-in-out infinite;
        }
        .ferman-fab img {
            width: 108px;
            height: 108px;
            object-fit: contain;
            filter: drop-shadow(0 12px 18px rgba(41, 28, 12, 0.32));
            transition: transform 0.2s ease;
        }
        .ferman-fab span {
            margin-top: -2px;
            background: rgba(30, 41, 59, 0.9);
            color: #fff;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            padding: 4px 10px;
            line-height: 1.1;
            letter-spacing: 0.01em;
        }
        .ferman-fab:hover img { transform: scale(1.05) rotate(-2deg); }
        @keyframes ferman-float {
            0%, 100% { transform: translateY(0px) rotate(-2deg); }
            50% { transform: translateY(-6px) rotate(1deg); }
        }
        @media (min-width: 1280px) {
            body { font-size: 19px; }
            .s-main { padding-left: 30px; padding-right: 30px; }
        }
        @media (min-width: 1024px) {
            body { zoom: 1; }
        }
        body.no-scroll { overflow: hidden; }
        @media (max-width: 768px) {
            .s-header { position: static; }
            .s-header-inner { padding: 14px 10px; }
            .s-header .logo img { height: 44px; }
            .s-header a { font-size: 0.98rem; padding: 8px 10px; }
            .s-header nav { gap: 8px; }
            .s-main { padding: 20px 10px 26px; }
            .s-shell { padding: 12px; border-radius: 14px; }
            .s-welcome { padding: 22px 18px; }
            .s-welcome h1 { font-size: 1.58rem; margin-bottom: 10px; }
            .s-welcome h1 .k { font-size: 0.92rem; margin-bottom: 5px; }
            .s-welcome h1 .n { font-size: 1.28rem; }
            .s-welcome .sub { font-size: 1.02rem; }
            .s-rank-grid { grid-template-columns: 1fr; gap: 8px; }
            .s-rank-box { padding: 10px 12px; }
            .s-rank-box .v { font-size: 1.74rem; }
            .s-rank-box .meta { font-size: 0.9rem; }
            .s-kv .chip { font-size: 0.9rem; padding: 7px 10px; }
            .s-sinav-wrap .body,
            .s-fiyat-wrap .fbody { padding: 14px; }
            .s-tbl,
            .s-fiyat-tbl { font-size: 0.98rem; }
            .s-tbl th, .s-tbl td,
            .s-fiyat-tbl th, .s-fiyat-tbl td { padding: 10px 10px; }
            .s-opts { padding: 16px; }
            .s-opts .tit { font-size: 1.06rem; }
            .s-opts label { font-size: 1.1rem; }
            .odeme-top-grid { grid-template-columns: 1fr; }
            .s-odeme .btn-odeme { width: 100%; text-align: center; }
            .ferman-fab { right: 12px; bottom: 12px; width: 92px; height: 108px; }
            .ferman-fab img { width: 84px; height: 84px; }
            .ferman-fab span { font-size: 0.72rem; padding: 3px 8px; }
        }
    </style>
</head>
<body>
<header class="s-header">
    <div class="s-header-inner">
        <div class="logo"><a href="/"><img src="resimler/logoGenclik.jpg" alt="Gençlik Dil"></a></div>
        <?php if (!$bulunamadi): ?>
        <nav>
            <a href="iletisim">İletişim</a>
        </nav>
        <?php else: ?>
        <nav><a href="iletisim">İletişim</a></nav>
        <?php endif; ?>
    </div>
</header>

<main class="s-main">
<?php if ($bulunamadi): ?>
    <div class="s-shell">
        <div class="s-sinav-wrap">
            <div class="body text-center py-5">
                <p class="text-muted mb-0">Bu link geçersiz veya artık kullanılamıyor. Lütfen kurumumuzla iletişime geçin.</p>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="s-shell">
    <?php $odeme_msg = isset($_GET['odeme']) ? $_GET['odeme'] : ''; ?>
    <?php if ($odeme_msg === 'basarili'): ?><div class="s-alert" style="background:#dcfce7;color:#166534;">Ödemeniz alındı. Teşekkür ederiz.</div><?php endif; ?>
    <?php if ($odeme_msg === 'hata'): ?><div class="s-alert" style="background:#fee2e2;color:#b91c1c;">Ödeme tamamlanamadı. Tekrar deneyebilir veya bizi arayabilirsiniz.</div><?php endif; ?>

    <section class="s-section">
    <div class="s-row">
        <div class="s-welcome">
            <h1>
                <span class="k">Bursluluk sınav sonucu</span>
                <span class="n"><?= htmlspecialchars($ogrenci['ad_soyad']) ?></span>
            </h1>
            <p class="sub">Öğrenci performans özeti</p>
            <?php if ($sinav_turu_link !== '' || $sinif_etiket !== ''): ?>
            <div class="sub-meta">
                <?php if ($sinav_turu_link !== ''): ?>
                    <span class="sinif-inline"><?= htmlspecialchars($sinav_turu_link) ?></span>
                <?php endif; ?>
                <?php if ($sinif_etiket !== ''): ?>
                    <span class="sinif-inline"><?= htmlspecialchars($sinif_etiket) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($sinav_ozet_kartlari)): ?>
            <div class="s-rank-grid">
                <?php foreach ($sinav_ozet_kartlari as $oz): ?>
                <?php $oz_label = trim((string)($oz['label'] ?? 'Sınav')); ?>
                <div class="s-rank-box">
                    <div class="k"><?= htmlspecialchars($oz_label) ?> sıralama</div>
                    <div class="v"><?= $oz['sira'] !== null ? (int)$oz['sira'] . '.' : '—' ?></div>
                    <div class="meta">
                        <?= $oz['toplam'] !== null ? (int)$oz['toplam'] . ' katılımcı içinde' : 'Katılımcı bilgisi yok' ?>
                        <?php if ($oz['yuzdelik'] !== null): ?><br>Yüzdelik dilim: %<?= number_format(100 - (float)$oz['yuzdelik'], 1, ',', '') ?><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="s-kv">
                <?php if ($ogrenci['sinif_ici_sira'] === null && $toplam_katilimci !== null): ?>
                    <span class="chip">Katılımcı: <?= $toplam_katilimci ?></span>
                <?php endif; ?>
                <?php if ($ogrenci['sinif_ici_sira'] === null && $yuzdelik_dilim !== null): ?>
                    <span class="chip">Yüzdelik dilim: %<?= number_format(100 - $yuzdelik_dilim, 1, ',', '') ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="s-sinav-wrap">
            <div class="tit">Sınav sonuçları</div>
            <div class="body">
                <?php if (!empty($sinav_bloklari)): ?>
                <?php foreach ($sinav_bloklari as $blok): $m = $blok['metrik']; ?>
                <?php if (count($sinav_bloklari) > 1): ?>
                <div class="small fw-bold mb-2 mt-2"><?= htmlspecialchars($blok['label']) ?> sınavı</div>
                <?php endif; ?>
                <table class="s-tbl mb-3">
                    <thead>
                        <tr><th>Bölüm</th><th class="sayi">Doğru</th><th class="sayi">Yanlış</th><th class="sayi">Boş</th><th class="sayi">Toplam soru</th><th class="sayi">Başarı %</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Okuma-Yazma</td><td class="sayi"><?= (int)$m['oy_d'] ?></td><td class="sayi"><?= (int)$m['oy_y'] ?></td><td class="sayi"><?= (int)$m['oy_b'] ?></td><td class="sayi"><?= (int)$m['oy_toplam'] ?></td><td class="sayi"><?= number_format((float)$m['oy_basari'], 1, ',', '') ?>%</td></tr>
                        <?php if (!empty($m['dk_satiri_goster'])): ?>
                        <tr><td>Dinleme-Konuşma</td><td class="sayi"><?= (int)$m['dk_d'] ?></td><td class="sayi"><?= (int)$m['dk_y'] ?></td><td class="sayi"><?= (int)$m['dk_b'] ?></td><td class="sayi"><?= (int)$m['dk_toplam'] ?></td><td class="sayi"><?= number_format((float)$m['dk_basari'], 1, ',', '') ?>%</td></tr>
                        <?php endif; ?>
                        <tr class="toplam-row">
                            <th>Toplam</th>
                            <td class="sayi"><?= (int)$m['toplam_d'] ?></td><td class="sayi"><?= (int)$m['toplam_y'] ?></td><td class="sayi"><?= (int)$m['toplam_b'] ?></td>
                            <td class="sayi"><?= (int)$m['toplam_soru'] ?></td>
                            <td class="sayi"><?= number_format((float)$m['basari_pct'], 1, ',', '') ?>%</td>
                        </tr>
                    </tbody>
                </table>
                <?php endforeach; ?>
                <?php else: ?>
                <p class="mb-0 text-muted small">Başarı: <?= $ogrenci['liste_basari'] !== null ? number_format($ogrenci['liste_basari'], 1, ',', '') . '%' : '—' ?>
                    <?php if ($ogrenci['sinif_ici_sira'] !== null): ?> · <?= $ogrenci['sinif_ici_sira'] ?>. sıra<?php endif; ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </section>

    <section class="s-section">
    <div class="erken-kayit-avantaj">
        <div class="eka-baslik">🎁 ERKEN KAYIT AVANTAJLARI</div>
        <p class="eka-aciklama">Bursluluk sınavımızın ardından erken kayıt döneminde karar verecek velilerimiz için özel fırsatlar sunuyoruz:</p>
        <table class="eka-tbl">
            <thead>
                <tr><th>Avantaj</th><th>Detay</th></tr>
            </thead>
            <tbody>
                <tr><td>✅ Çifte İndirim</td><td>Bursluluk indirimi + Erken kayıt indirimi bir arada!</td></tr>
                <tr><td>✅ Almanca İndirimi</td><td>İngilizceye ek olarak Almanca alanlara özel indirim</td></tr>
                <tr><td>✅ Materyal Hediyesi</td><td>İki dil alan öğrencilerimize ikinci dil materyalleri hediye</td></tr>
            </tbody>
        </table>
    </div>
    </section>

    <section class="s-section">
    <div class="s-fiyat-row">
        <div class="s-fiyat-left">
            <div class="s-opts">
                <div class="tit">Tercihleriniz &amp; Fırsatlar</div>
                <label class="opt-item opt-ingilizce opt-lang">
                    <input type="checkbox" id="sec_english" name="ingilizce_ister" value="1">
                    <span class="opt-lang-text">
                        <span class="main">İngilizce istiyorum</span>
                        <span class="sub">İngilizce programı için fiyatlandırma bu seçime göre hazırlanır.</span>
                    </span>
                </label>
                <label class="opt-item opt-almanca opt-lang">
                    <input type="checkbox" id="sec_german" name="almanca_ister" value="1">
                    <span class="opt-lang-text">
                        <span class="main">Almanca istiyorum</span>
                        <span class="sub">Almanca programı için fiyatlandırma bu seçime göre hazırlanır.</span>
                    </span>
                </label>
                <?php if ($erken_kayit_aktif): ?>
                <div class="opt-item opt-erken-badge opt-lang" aria-hidden="true">
                    <span class="opt-erken-tick" aria-hidden="true"></span>
                    <span class="opt-lang-text">
                        <span class="main">Erken kayıt indirimi</span>
                        <span class="sub"><?= htmlspecialchars($erken_kayit_metin) ?> geçerli. Bu tarihe kadar kayıt olanlara özel indirim uygulanır.</span>
                    </span>
                </div>
                <?php endif; ?>
                <div id="secim-uyari" class="small text-danger" style="display:none; margin-top:8px;">
                    En az bir dil seçmelisiniz.
                </div>
            </div>
            <div class="s-kitap-wrap">
                <div class="s-kitap-tit">Kitap, Materyal &amp; Kütüphane ücreti</div>
                <p class="s-kitap-uyari">Bu ücret kurumumuzda ayrıca tahsil edilecektir.</p>
                <table class="s-fiyat-tbl" id="tablo-kitap">
                    <thead><tr><th>Cinsi</th><th class="text-end">Tutar (TL)</th></tr></thead>
                    <tbody id="tbody-kitap"></tbody>
                </table>
            </div>
        </div>
        <div class="s-fiyat-wrap">
            <div class="ftit">Kurs ücreti</div>
            <div class="fbody">
                <table class="s-fiyat-tbl" id="tablo-kurs">
                    <thead><tr id="thead-kurs"></tr></thead>
                    <tbody id="tbody-kurs"></tbody>
                </table>
                <div class="s-odeme">
                    <div id="tam-burs-bilgilendirme" class="tam-burs-bilgi" aria-live="polite">Öğrenciniz %100 burs kazanmıştır. Kayıt işleminin tamamlanması için sembolik 10 TL ödeme alınacaktır.</div>
                    <div class="odeme-title">Ödeme adımları</div>
                    <div class="odeme-sub">Ödeme işlemi onayından sonra kayıt süreci kurum tarafından başlatılır.</div>
                    <div class="odeme-top-grid">
                        <div class="odeme-block email-row">
                            <label>E-posta (fatura / bilgilendirme)</label>
                            <input type="email" id="pay-email" placeholder="ornek@email.com" autocomplete="email">
                        </div>
                    </div>
                    <div class="odeme-sozlesme odeme-sozlesme-materyal-ayri">
                        <label>
                            <input type="checkbox" id="odeme_materyal_ayri_onay" value="1">
                            <span>Kitap, Materyal &amp; Kütüphane ücretinin kurumumuzda ayrıca tahsil edileceğini anladım ve kabul ettim.</span>
                        </label>
                    </div>
                    <div class="odeme-sozlesme">
                        <label>
                            <input type="checkbox" id="odeme_sozlesme_onay" value="1">
                            <span>Ödeme işlemi sonrası kayıt işlemlerinin tamamlanabilmesi için kurum danışmanının benimle iletişime geçmesi gerektiğini anladım ve kabul ettim.</span>
                        </label>
                    </div>
                    <div class="odeme-sozlesme">
                        <label>
                            <input type="checkbox" id="odeme_paytr_onay" value="1">
                            <span>Kredi kartı bilgilerimin işbu web sitesinde saklanmadan PayTR ödeme kuruluşu ile paylaşılmasını kabul ettim. Ödemeyi PayTR üzerinden taksitlendirebileceğimi anladım ve kabul ettim.</span>
                        </label>
                    </div>
                    <div class="btns">
                        <form method="post" action="paytr_odeme.php" class="d-inline" id="form-toplu">
                            <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
                            <input type="hidden" name="odeme_tipi" value="toplu">
                            <input type="hidden" name="sinif_ici_sira" id="form-toplu-sira" value="<?= (int)$sira_php ?>">
                            <input type="hidden" name="erken_kayit" id="form-toplu-erken" value="0">
                            <input type="hidden" name="ingilizce" id="form-toplu-ingilizce" value="0">
                            <input type="hidden" name="almanca" id="form-toplu-almanca" value="0">
                            <input type="hidden" name="pesin" value="0">
                            <input type="hidden" name="email" id="form-toplu-email" value="">
                            <input type="hidden" name="sozlesme_onay" id="form-toplu-sozlesme" value="0">
                            <button type="submit" class="btn-odeme" id="btn-toplu" <?= $toplu_odendi ? 'disabled' : '' ?>>
                                <?= $toplu_odendi ? 'Ödeme tamamlandı' : 'Ödeme Yap' ?>
                            </button>
                        </form>
                        <span id="odeme-toplam-metin" class="odeme-toplam-metin" aria-live="polite"></span>
                        <div id="odeme-onay-uyari" class="odeme-onay-uyari">Ödeme için tüm onay kutularını işaretlemelisiniz.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </section>
    </div>
    <div id="tam-burs-popup-overlay" class="tam-burs-overlay" role="dialog" aria-modal="true" aria-labelledby="tam-burs-popup-baslik" aria-hidden="true">
        <div class="tam-burs-box">
            <h3 id="tam-burs-popup-baslik">%100 Burs</h3>
            <p>Öğrenciniz %100 burs kazanmıştır. Kayıt işleminin tamamlanması için sembolik 10 TL ödeme alınacaktır. Devam etmek istiyor musunuz?</p>
            <div class="tam-burs-btns">
                <button type="button" class="btn-tam-burs btn-tam-burs-devam" id="tam-burs-popup-devam">Devam et</button>
                <button type="button" class="btn-tam-burs btn-tam-burs-iptal" id="tam-burs-popup-iptal">İptal</button>
            </div>
        </div>
    </div>
    <a href="sonuclar-aciklama.php?t=<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>" class="ferman-fab" aria-label="Bilgilendirme sayfası">
        <img src="resimler/ferman-buton.png" alt="Bilgilendirme">
    </a>
<?php endif; ?>
</main>

<footer class="footer-bar">
    <div class="container footer-container">
        <div class="contact-item">
            <a href="https://www.google.com/maps/search/?api=1&query=Dumlupınar+Mahallesi+Yüzbaşı+Bayburtlu+Agah+Caddesi+No:12+Merkez+Afyonkarahisar" target="_blank">
                <i class="glyphicon glyphicon-map-marker"></i>
                <span>Dumlupınar Mah. Yüzbaşı Bayburtlu Agah Cad. No:12 Afyonkarahisar</span>
            </a>
        </div>
        <div class="contact-item">
            <a href="tel:02722141022">
                <i class="glyphicon glyphicon-earphone"></i>
                <span>0(272) 214 10 22</span>
            </a>
        </div>
        <div class="contact-item">
            <a href="tel:05422141022">
                <i class="glyphicon glyphicon-earphone"></i>
                <span>0 542 214 10 22</span>
            </a>
        </div>
        <div class="contact-item">
            <a href="https://wa.me/905323512078" target="_blank" class="whatsapp-link">
                <i class="fab fa-whatsapp"></i>
                <span>0(532) 351 20 78</span>
            </a>
        </div>
        <div class="contact-item">
            <a href="mailto:iletisim@genclikdil.com">
                <i class="glyphicon glyphicon-envelope"></i>
                <span>iletisim@genclikdil.com</span>
            </a>
        </div>
        <div class="contact-item" style="font-size: 12px; color: #95a5a6;">
            © 2004 Gençlik Dil Eğitim Hizmetleri Tic. ve San. Ltd. Şti. - Tüm Hakları Saklıdır.
        </div>
    </div>
</footer>

<?php if (!$bulunamadi): ?>
<script>
(function() {
    var BAZ_ING = 66200, BAZ_ALM = 66200, MAT_KITAP = 22000, KUTUPHANE_UCRETI = 60000;
    var ING_LABEL = 'İngilizce';
    var ALM_LABEL = 'Almanca';
    var ERKEN_KAYIT_AKTIF = <?= $erken_kayit_aktif ? 'true' : 'false' ?>;
    var SIRA_INDIRIM = { 1: 100, 2: 80, 3: 70, 4: 50, 5: 40, 6: 30, 7: 20 };
    var SIRA_INDIRIM_ALM_OZEL = { 1: 30, 2: 27, 3: 24, 4: 21, 5: 18, 6: 16, 7: 13 };
    var sira = <?= (int)$sira_php ?>;
    var SINAV_PROFIL = <?= json_encode($sinav_profil, JSON_UNESCAPED_UNICODE) ?>;

    function roundUp(x) { return x <= 0 ? 0 : Math.ceil(x / 100) * 100; }
    function fmt(t) { return t === 0 ? '—' : (t < 0 ? '−' : '') + Math.abs(t).toLocaleString('tr-TR', { maximumFractionDigits: 0 }); }
    function toTL(t) { return (t < 0 ? '−' : '') + Math.abs(t).toLocaleString('tr-TR', { maximumFractionDigits: 0 }); }

    function getEnglishNormal(early) {
        var indPct = SIRA_INDIRIM[sira] !== undefined ? SIRA_INDIRIM[sira] : (sira >= 8 ? 10 : 0);
        var afterBurs = sira > 0 ? roundUp(BAZ_ING * (100 - indPct) / 100) : roundUp(BAZ_ING);
        var bursInd = BAZ_ING - afterBurs;
        var earlyInd = early ? (afterBurs - roundUp(afterBurs * 0.90)) : 0;
        var finalFee = afterBurs - earlyInd;
        return {
            final: finalFee,
            rows: [
                { kalem: 'Baz fiyat', tutar: BAZ_ING, indirim: false, bold: false },
                { kalem: 'Burs indirimi', tutar: -bursInd, indirim: true, bold: false },
                early ? { kalem: 'Erken kayıt indirimi (30 Nisan 2026\'ya kadar)', tutar: -earlyInd, indirim: true, bold: false } : null,
                { kalem: 'Kurs ücreti', tutar: finalFee, indirim: false, bold: true }
            ].filter(Boolean)
        };
    }

    function getEnglishNoBurs(early) {
        var earlyInd = early ? (BAZ_ING - roundUp(BAZ_ING * 0.90)) : 0;
        var finalFee = BAZ_ING - earlyInd;
        return {
            final: finalFee,
            rows: [
                { kalem: 'Baz fiyat', tutar: BAZ_ING, indirim: false, bold: false },
                early ? { kalem: 'Erken kayıt indirimi (30 Nisan 2026\'ya kadar)', tutar: -earlyInd, indirim: true, bold: false } : null,
                { kalem: 'Kurs ücreti', tutar: finalFee, indirim: false, bold: true }
            ].filter(Boolean)
        };
    }

    function getGermanSimple(early) {
        var hedef50 = roundUp(BAZ_ALM * 0.50);
        var almEkInd = BAZ_ALM - hedef50;
        var hedef60 = roundUp(BAZ_ALM * 0.40);
        var earlyInd = early ? Math.max(0, hedef50 - hedef60) : 0;
        var finalFee = hedef50 - earlyInd;
        return {
            final: finalFee,
            rows: [
                { kalem: 'Baz fiyat', tutar: BAZ_ALM, indirim: false, bold: false },
                { kalem: 'Almanca ek indirim', tutar: -almEkInd, indirim: true, bold: false },
                early ? { kalem: 'Erken kayıt indirimi (30 Nisan 2026\'ya kadar)', tutar: -earlyInd, indirim: true, bold: false } : null,
                { kalem: 'Kurs ücreti', tutar: finalFee, indirim: false, bold: true }
            ].filter(Boolean)
        };
    }

    function getGermanAdvanced(early) {
        var bursPct = SIRA_INDIRIM_ALM_OZEL[sira] !== undefined ? SIRA_INDIRIM_ALM_OZEL[sira] : 10;
        var bursInd = roundUp(BAZ_ALM * (bursPct / 100));
        var hedef50 = roundUp(BAZ_ALM * 0.50);
        var ekInd = Math.max(0, BAZ_ALM - hedef50 - bursInd);
        var after50 = BAZ_ALM - bursInd - ekInd;
        var hedef60 = roundUp(BAZ_ALM * 0.40);
        var earlyInd = early ? Math.max(0, after50 - hedef60) : 0;
        var finalFee = after50 - earlyInd;
        return {
            final: finalFee,
            rows: [
                { kalem: 'Baz fiyat', tutar: BAZ_ALM, indirim: false, bold: false },
                { kalem: 'Burs indirimi', tutar: -bursInd, indirim: true, bold: false },
                { kalem: 'Ek indirim', tutar: -ekInd, indirim: true, bold: false },
                early ? { kalem: 'Erken kayıt indirimi (30 Nisan 2026\'ya kadar)', tutar: -earlyInd, indirim: true, bold: false } : null,
                { kalem: 'Kurs ücreti', tutar: finalFee, indirim: false, bold: true }
            ].filter(Boolean)
        };
    }

    function isAllPaymentApprovalsChecked() {
        var cb0 = document.getElementById('odeme_materyal_ayri_onay');
        var cb1 = document.getElementById('odeme_sozlesme_onay');
        var cb2 = document.getElementById('odeme_paytr_onay');
        return !!(cb0 && cb0.checked && cb1 && cb1.checked && cb2 && cb2.checked);
    }

    function updatePaymentButtonState() {
        var btnToplu = document.getElementById('btn-toplu');
        var onayUyari = document.getElementById('odeme-onay-uyari');
        if (!btnToplu) return;
        if (btnToplu.hasAttribute('data-force-disabled')) {
            btnToplu.disabled = true;
            btnToplu.classList.remove('needs-approval');
            if (onayUyari) onayUyari.style.display = 'none';
            return;
        }
        var onayli = isAllPaymentApprovalsChecked();
        btnToplu.disabled = false;
        btnToplu.classList.toggle('needs-approval', !onayli);
        btnToplu.setAttribute('aria-disabled', onayli ? 'false' : 'true');
        if (onayUyari) onayUyari.style.display = onayli ? 'none' : 'block';
    }

    function hesapla() {
        var erken = ERKEN_KAYIT_AKTIF;
        var secEnglish = !!(document.getElementById('sec_english') && document.getElementById('sec_english').checked);
        var secGerman = !!(document.getElementById('sec_german') && document.getElementById('sec_german').checked);
        var secimUyari = document.getElementById('secim-uyari');
        var btnToplu = document.getElementById('btn-toplu');
        var odemeTamamlandi = !!(btnToplu && btnToplu.hasAttribute('disabled') && !btnToplu.hasAttribute('data-force-disabled'));
        if (odemeTamamlandi && btnToplu) btnToplu.setAttribute('data-force-disabled', '1');

        if (!secEnglish && !secGerman) {
            if (secimUyari) secimUyari.style.display = 'block';
            if (btnToplu && !odemeTamamlandi) btnToplu.disabled = true;
            var tamBursBilgiHide = document.getElementById('tam-burs-bilgilendirme');
            if (tamBursBilgiHide) tamBursBilgiHide.classList.remove('is-visible');
            document.getElementById('thead-kurs').innerHTML = '<th>Cinsi</th><th class="text-end">Tutar</th>';
            document.getElementById('tbody-kurs').innerHTML = '<tr><td colspan="2" class="text-center text-danger">En az bir dil seçmelisiniz.</td></tr>';
            document.getElementById('tbody-kitap').innerHTML = '<tr><td colspan="2" class="text-center text-danger">En az bir dil seçmelisiniz.</td></tr>';
            var odemeToplamEl = document.getElementById('odeme-toplam-metin');
            if (odemeToplamEl) odemeToplamEl.textContent = '';
            var fteBos = document.getElementById('form-toplu-email');
            var emailBos = document.getElementById('pay-email');
            if (fteBos && emailBos) fteBos.value = emailBos.value;
            var ftErkenBos = document.getElementById('form-toplu-erken');
            var ftIngBos = document.getElementById('form-toplu-ingilizce');
            var ftAlmBos = document.getElementById('form-toplu-almanca');
            if (ftErkenBos) ftErkenBos.value = erken ? '1' : '0';
            if (ftIngBos) ftIngBos.value = '0';
            if (ftAlmBos) ftAlmBos.value = '0';
            return;
        }
        if (secimUyari) secimUyari.style.display = 'none';
        updatePaymentButtonState();

        var kursByLang = {};
        if (SINAV_PROFIL === 'onlyEnglish') {
            if (secEnglish) kursByLang.english = getEnglishNormal(erken);
            if (secGerman) kursByLang.german = getGermanSimple(erken);
        } else if (SINAV_PROFIL === 'onlyGerman') {
            if (secEnglish && secGerman) {
                kursByLang.english = getEnglishNoBurs(erken);
                kursByLang.german = getGermanAdvanced(erken);
            } else if (secEnglish) {
                kursByLang.english = getEnglishNoBurs(erken);
            } else if (secGerman) {
                kursByLang.german = getGermanAdvanced(erken);
            }
        } else {
            if (secEnglish) kursByLang.english = getEnglishNormal(erken);
            if (secGerman) kursByLang.german = getGermanAdvanced(erken);
        }

        var toplamDers = 0;
        if (kursByLang.english) toplamDers += kursByLang.english.final;
        if (kursByLang.german) toplamDers += kursByLang.german.final;

        var kursHeaders = [];
        if (secEnglish) kursHeaders.push(ING_LABEL);
        if (secGerman) kursHeaders.push(ALM_LABEL);
        kursHeaders.push('TOPLAM');

        var kursRowsMap = {};
        function mergeCourseRows(langKey, block) {
            if (!block) return;
            block.rows.forEach(function(r) {
                if (!kursRowsMap[r.kalem]) {
                    kursRowsMap[r.kalem] = { kalem: r.kalem, english: 0, german: 0, bold: !!r.bold, indirim: !!r.indirim };
                }
                kursRowsMap[r.kalem][langKey] = r.tutar;
                kursRowsMap[r.kalem].bold = kursRowsMap[r.kalem].bold || !!r.bold;
                kursRowsMap[r.kalem].indirim = kursRowsMap[r.kalem].indirim || !!r.indirim;
            });
        }
        mergeCourseRows('english', kursByLang.english);
        mergeCourseRows('german', kursByLang.german);
        var kursRows = Object.keys(kursRowsMap).map(function(k) { return kursRowsMap[k]; });
        var kursOrder = {
            'Baz fiyat': 1,
            'Burs indirimi': 2,
            'Almanca ek indirim': 3,
            'Ek indirim': 3,
            'Ek indirim (net %50 seviyesine dengeleme)': 3,
            'Erken kayıt indirimi (30 Nisan 2026\'ya kadar)': 4,
            'Kurs ücreti': 5
        };
        kursRows.sort(function(a, b) {
            var ao = kursOrder[a.kalem] || 99;
            var bo = kursOrder[b.kalem] || 99;
            return ao - bo;
        });

        var thead = document.getElementById('thead-kurs');
        thead.innerHTML = '<th>Cinsi</th>' + kursHeaders.map(function(h) { return '<th class="text-end">' + h + '</th>'; }).join('');
        var tbodyK = document.getElementById('tbody-kurs');
        tbodyK.innerHTML = kursRows.map(function(r) {
            var cls = r.bold ? ' class="bold-row"' : (r.indirim ? ' class="indirim-row"' : '');
            var total = (secEnglish ? r.english : 0) + (secGerman ? r.german : 0);
            var cols = '';
            if (secEnglish) cols += '<td class="text-end">' + fmt(r.english) + '</td>';
            if (secGerman) cols += '<td class="text-end">' + fmt(r.german) + '</td>';
            cols += '<td class="text-end">' + toTL(total) + '</td>';
            return '<tr' + cls + '><td>' + r.kalem + '</td>' + cols + '</tr>';
        }).join('');

        var kitapRows = [];
        var materyalToplam = MAT_KITAP;
        if (secEnglish && !secGerman) {
            kitapRows.push({ kalem: 'İngilizce Kitap & Materyal ücreti', tutar: MAT_KITAP, bold: false, indirim: false });
        } else if (!secEnglish && secGerman) {
            kitapRows.push({ kalem: 'Almanca Kitap & Materyal ücreti', tutar: MAT_KITAP, bold: false, indirim: false });
        } else {
            kitapRows.push({ kalem: 'İngilizce Kitap & Materyal ücreti', tutar: MAT_KITAP, bold: false, indirim: false });
            kitapRows.push({ kalem: 'Almanca Kitap & Materyal ücreti', tutar: MAT_KITAP, bold: false, indirim: false });
            if (erken) {
                kitapRows.push({ kalem: 'İki dilde bir kitap & materyal ücretsiz (30 Nisan 2026\'ya kadar)', tutar: -MAT_KITAP, bold: false, indirim: true });
            } else {
                materyalToplam = MAT_KITAP * 2;
            }
        }
        kitapRows.push({ kalem: 'Kütüphane ücreti', tutar: KUTUPHANE_UCRETI, bold: false, indirim: false });
        kitapRows.push({ kalem: 'Ücretsiz kütüphane indirimi', tutar: -KUTUPHANE_UCRETI, bold: false, indirim: true });
        kitapRows.push({ kalem: 'Toplam', tutar: materyalToplam, bold: true, indirim: false });

        var toplamGenel = toplamDers;
        window.__kursToplamSifirMi = (toplamDers === 0);
        var tamBursBilgi = document.getElementById('tam-burs-bilgilendirme');
        if (tamBursBilgi) tamBursBilgi.classList.toggle('is-visible', toplamDers === 0);
        var odemeToplamMetin = document.getElementById('odeme-toplam-metin');
        if (odemeToplamMetin) {
            var gosterilecekTutar = toplamDers === 0 ? 10 : toplamDers;
            odemeToplamMetin.textContent = gosterilecekTutar.toLocaleString('tr-TR', { maximumFractionDigits: 0 }) + ' TL';
        }
        var ftErken = document.getElementById('form-toplu-erken');
        var ftIng = document.getElementById('form-toplu-ingilizce');
        var ftAlm = document.getElementById('form-toplu-almanca');
        if (ftErken) ftErken.value = erken ? '1' : '0';
        if (ftIng) ftIng.value = secEnglish ? '1' : '0';
        if (ftAlm) ftAlm.value = secGerman ? '1' : '0';
        var emailEl = document.getElementById('pay-email');
        if (emailEl) {
            var fte = document.getElementById('form-toplu-email');
            if (fte) fte.value = emailEl.value;
        }

        var tbodyKitap = document.getElementById('tbody-kitap');
        tbodyKitap.innerHTML = kitapRows.map(function(r) {
            var cls = r.bold ? ' class="bold-row"' : (r.indirim ? ' class="indirim-row"' : '');
            var tutarStr = r.tutar < 0 ? '−' + Math.abs(r.tutar).toLocaleString('tr-TR', { maximumFractionDigits: 0 }) : r.tutar.toLocaleString('tr-TR', { maximumFractionDigits: 0 });
            return '<tr' + cls + '><td>' + r.kalem + '</td><td class="text-end">' + tutarStr + '</td></tr>';
        }).join('');
    }

    var secEnglishEl = document.getElementById('sec_english');
    var secGermanEl = document.getElementById('sec_german');
    if (SINAV_PROFIL === 'onlyEnglish') {
        if (secEnglishEl) secEnglishEl.checked = true;
        if (secGermanEl) secGermanEl.checked = false;
    } else if (SINAV_PROFIL === 'onlyGerman') {
        if (secEnglishEl) secEnglishEl.checked = false;
        if (secGermanEl) secGermanEl.checked = true;
    } else {
        if (secEnglishEl) secEnglishEl.checked = true;
        if (secGermanEl) secGermanEl.checked = true;
    }
    if (secEnglishEl) secEnglishEl.addEventListener('change', hesapla);
    if (secGermanEl) secGermanEl.addEventListener('change', hesapla);
    var payEmail = document.getElementById('pay-email');
    if (payEmail) {
        payEmail.addEventListener('input', function() {
            var fte = document.getElementById('form-toplu-email');
            if (fte) fte.value = this.value;
        });
    }
    function sozlesmeOnayliMi() {
        return isAllPaymentApprovalsChecked();
    }
    function setSozlesmeFields() {
        var v = sozlesmeOnayliMi() ? '1' : '0';
        ['form-toplu-sozlesme'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = v;
        });
    }
    function ensureSozlesmeBeforeSubmit() {
        var emailEl = document.getElementById('pay-email');
        var emailVal = emailEl ? String(emailEl.value || '').trim() : '';
        if (!emailVal || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
            alert('Lütfen geçerli bir e-posta adresi girin.');
            if (emailEl) emailEl.focus();
            return false;
        }
        if (!sozlesmeOnayliMi()) {
            var onayUyari = document.getElementById('odeme-onay-uyari');
            if (onayUyari) onayUyari.style.display = 'block';
            alert('Lütfen ödeme öncesi tüm onay kutularını işaretleyin.');
            return false;
        }
        setSozlesmeFields();
        return true;
    }
    var formToplu = document.getElementById('form-toplu');
    var tamBursOverlay = document.getElementById('tam-burs-popup-overlay');
    var tamBursDevam = document.getElementById('tam-burs-popup-devam');
    var tamBursIptal = document.getElementById('tam-burs-popup-iptal');
    function tamBursPopupAc() {
        if (tamBursOverlay) {
            tamBursOverlay.classList.add('is-visible');
            tamBursOverlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('no-scroll');
        }
    }
    function tamBursPopupKapat() {
        if (tamBursOverlay) {
            tamBursOverlay.classList.remove('is-visible');
            tamBursOverlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('no-scroll');
        }
    }
    if (formToplu) formToplu.addEventListener('submit', function(e) {
        if (!ensureSozlesmeBeforeSubmit()) {
            e.preventDefault();
            return;
        }
        if (window.__kursToplamSifirMi) {
            e.preventDefault();
            tamBursPopupAc();
            return;
        }
        var target = document.getElementById('form-toplu-email');
        if (target && payEmail) target.value = payEmail.value;
    });
    if (tamBursDevam) tamBursDevam.addEventListener('click', function() {
        tamBursPopupKapat();
        var target = document.getElementById('form-toplu-email');
        if (payEmail && target) target.value = payEmail.value;
        if (formToplu) formToplu.submit();
    });
    if (tamBursIptal) tamBursIptal.addEventListener('click', tamBursPopupKapat);
    if (tamBursOverlay) tamBursOverlay.addEventListener('click', function(ev) {
        if (ev.target === tamBursOverlay) tamBursPopupKapat();
    });
    var materyalAyriCb = document.getElementById('odeme_materyal_ayri_onay');
    var sozlesmeCb = document.getElementById('odeme_sozlesme_onay');
    var paytrCb = document.getElementById('odeme_paytr_onay');
    if (materyalAyriCb) materyalAyriCb.addEventListener('change', updatePaymentButtonState);
    if (sozlesmeCb) sozlesmeCb.addEventListener('change', updatePaymentButtonState);
    if (paytrCb) paytrCb.addEventListener('change', updatePaymentButtonState);
    hesapla();
    updatePaymentButtonState();
})();
</script>
<?php endif; ?>
</body>
</html>
