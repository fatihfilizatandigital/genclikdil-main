<?php
/**
 * Görüşmeler: Sınava katılmış öğrenciler - sonuç bilgilendirmesi ve randevu.
 * Giriş yapmış tüm panel kullanıcıları erişebilir (admin şartı yok).
 */
require_once __DIR__ . '/auth_gorusmeler.php';
require_once __DIR__ . '/../config/personel_log.php';
require_once __DIR__ . '/../config/teklif_v2.php';

$aktif_personel = $_SESSION['personel_adi'] ?? '';
$conn = mysqli_connect("213.142.130.21", "adnan", "adnan.1234", "farmMysql", 3306);
mysqli_set_charset($conn, "utf8mb4");
if (!$conn) die("Veritabanı bağlantısı kurulamadı.");
teklif_v2_ensure_schema($conn);
$personel_db = mysqli_real_escape_string($conn, $aktif_personel);

$tbl = @mysqli_query($conn, "SHOW TABLES LIKE 'gorusme_listesi'");
if (!$tbl || mysqli_num_rows($tbl) === 0) die("gorusme_listesi tablosu yok. sql/gorusme_listesi.sql çalıştırın.");
$tbl_r = @mysqli_query($conn, "SHOW TABLES LIKE 'gorusme_randevulari'");
$randevulari_var = ($tbl_r && mysqli_num_rows($tbl_r) > 0);

// Girişte kullanılan kullanıcılar (kullanicilar tablosu) -> "Randevu sorumlusu" dropdown
$kullanici_isimleri = [];
$tbl_k = @mysqli_query($conn, "SHOW TABLES LIKE 'kullanicilar'");
if ($tbl_k && mysqli_num_rows($tbl_k) > 0) {
    $res_k = @mysqli_query($conn, "SELECT DISTINCT ad_soyad FROM kullanicilar WHERE ad_soyad IS NOT NULL AND TRIM(ad_soyad) <> '' ORDER BY ad_soyad ASC");
    while ($res_k && ($kr = mysqli_fetch_assoc($res_k))) {
        $ad = trim((string)($kr['ad_soyad'] ?? ''));
        if ($ad !== '') $kullanici_isimleri[] = $ad;
    }
}

// Randevular tablosuna "randevu_sorumlusu" kolonu ekle + mevcut randevuların sorumlusunu oluşturan kişi yap
$randevu_sorumlusu_kolon_var = false;
if ($randevulari_var) {
    $col_s = @mysqli_query($conn, "SHOW COLUMNS FROM gorusme_randevulari LIKE 'randevu_sorumlusu'");
    $randevu_sorumlusu_kolon_var = ($col_s && mysqli_num_rows($col_s) > 0);
    if (!$randevu_sorumlusu_kolon_var) {
        @mysqli_query($conn, "ALTER TABLE gorusme_randevulari ADD COLUMN randevu_sorumlusu VARCHAR(255) NULL AFTER personel");
        $col_s2 = @mysqli_query($conn, "SHOW COLUMNS FROM gorusme_randevulari LIKE 'randevu_sorumlusu'");
        $randevu_sorumlusu_kolon_var = ($col_s2 && mysqli_num_rows($col_s2) > 0);
    }
    if ($randevu_sorumlusu_kolon_var) {
        @mysqli_query($conn, "UPDATE gorusme_randevulari SET randevu_sorumlusu = personel WHERE randevu_sorumlusu IS NULL OR randevu_sorumlusu = ''");
    }
}

// Bir kerelik: eski durum değerlerini yeni isimlere güncelle (veri korunur)
@mysqli_query($conn, "UPDATE gorusme_listesi SET gorusme_durumu = 'Sonuc Icin Ulasilamadi' WHERE gorusme_durumu = 'Ulasilamadi'");
@mysqli_query($conn, "UPDATE gorusme_listesi SET gorusme_durumu = 'Gorusuldu (Yuz Yuze)' WHERE gorusme_durumu = 'Gorusuldu'");

$mesaj = "";
$mesajTur = "";
$mesajDetay = "";
$LISE_SINIFLAR = [9, 10, 11, 12];
$secilen_sinif = isset($_GET['sinif']) ? trim($_GET['sinif']) : (isset($_POST['sinif_id']) ? trim($_POST['sinif_id']) : '');
$secilen_sinif_aktif = ($secilen_sinif !== '' && $secilen_sinif !== '0');
$odeme_filtresi = isset($_GET['odeme_filtresi']) && $_GET['odeme_filtresi'] === '1';
$odeme_tel_alt_sorgu = "SELECT DISTINCT gl.tel_temiz
    FROM gorusme_listesi gl
    INNER JOIN gorusme_teklif_v2 tv2 ON tv2.gorusme_listesi_id = gl.id
    INNER JOIN paytr_odemeler p ON p.teklif_v2_id = tv2.id AND p.durum = 'success'";
function gorusme_sinif_where($secilen_sinif, $lise_siniflar, $alias = '') {
    $prefix = $alias !== '' ? $alias . '.' : '';
    if ($secilen_sinif === 'tum' || $secilen_sinif === '') {
        return $prefix . "1=1";
    }
    if ($secilen_sinif === 'Lise') {
        return $prefix . "sinif IN (" . implode(',', $lise_siniflar) . ")";
    }
    return $prefix . "sinif = " . (int)$secilen_sinif;
}
function gorusme_parse_sinif_num($raw): int {
    $s = trim((string)$raw);
    if ($s === '') return 0;
    if (preg_match('/^\d+/', $s, $m)) return (int)$m[0];
    return (int)$s;
}
function gorusme_norm_exam_type($raw): string {
    $t = mb_strtolower(trim((string)$raw), 'UTF-8');
    if ($t === '') return '';
    if (strpos($t, 'almanca') !== false) return 'almanca';
    if (strpos($t, 'ingilizce') !== false) return 'ingilizce';
    return '';
}
function gorusme_sql_str(mysqli $conn, $v): string {
    if ($v === null) return "NULL";
    return "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
}
function gorusme_sql_int($v): string {
    if ($v === null || $v === '') return "NULL";
    return (string)((int)$v);
}
function gorusme_sql_dec($v): string {
    if ($v === null || $v === '') return "NULL";
    return sprintf('%.2f', (float)$v);
}
$aile_verisi = [];   // Seçili tele göre tüm öğrenci satırları (veli birleşik)
$secili_notlar = [];
$bulunan_tel = "";
$notlar_created_col = false;
$notlar_updated_col = false;
$not_cols_res = @mysqli_query($conn, "SHOW COLUMNS FROM gorusme_notlari");
if ($not_cols_res) {
    while ($nc = mysqli_fetch_assoc($not_cols_res)) {
        $f = strtolower((string)($nc['Field'] ?? ''));
        if ($f === 'created_at') $notlar_created_col = true;
        if ($f === 'updated_at') $notlar_updated_col = true;
    }
}
$not_select_fields = "id, baslik, icerik, sira";
if ($notlar_created_col) $not_select_fields .= ", created_at";
if ($notlar_updated_col) $not_select_fields .= ", updated_at";

// Sınav sonuçlarından (sinav_sonuclari) görüşme listesini oluştur/güncelle
if (isset($_GET['sync']) && $_GET['sync'] === '1') {
    $tbl_ss = @mysqli_query($conn, "SHOW TABLES LIKE 'sinav_sonuclari'");
    if ($tbl_ss && mysqli_num_rows($tbl_ss) > 0) {
        $sync_src = @mysqli_query($conn, "SELECT id, veli_adi, veli_soyadi, veli_telefon, ogrenci_adi, ogrenci_soyadi, sinif, sinav_turu, basari_yuzdesi FROM sinav_sonuclari");
        $gruplar = [];
        while ($sync_src && $sr = mysqli_fetch_assoc($sync_src)) {
            $tel_orijinal = trim((string)($sr['veli_telefon'] ?? ''));
            $tel_temiz = preg_replace('/\D+/', '', $tel_orijinal);
            $ogr_ad = trim((string)($sr['ogrenci_adi'] ?? ''));
            if ($tel_temiz === '' || $ogr_ad === '') {
                continue;
            }
            $ogr_soyad = trim((string)($sr['ogrenci_soyadi'] ?? ''));
            $sinif = gorusme_parse_sinif_num($sr['sinif'] ?? 0);
            $key = mb_strtolower($tel_temiz . '|' . $ogr_ad . '|' . $ogr_soyad . '|' . $sinif, 'UTF-8');
            $sid = (int)($sr['id'] ?? 0);
            if (!isset($gruplar[$key])) {
                $gruplar[$key] = [
                    'veli_ad' => trim((string)($sr['veli_adi'] ?? '')),
                    'veli_soyad' => trim((string)($sr['veli_soyadi'] ?? '')),
                    'tel_temiz' => $tel_temiz,
                    'tel_orijinal' => $tel_orijinal,
                    'ogrenci_ad' => $ogr_ad,
                    'ogrenci_soyad' => $ogr_soyad,
                    'sinif' => $sinif,
                    'eng_id' => null,
                    'alm_id' => null,
                    'eng_basari' => null,
                    'alm_basari' => null,
                    'max_row_id' => $sid,
                ];
            } else {
                // Aynı öğrenci (büyük/küçük harf farkıyla) birden fazla satırda olabilir; en güncel (en yüksek id) ad/soyad/veli/tel kullan
                if ($sid > (int)($gruplar[$key]['max_row_id'] ?? 0)) {
                    $gruplar[$key]['max_row_id'] = $sid;
                    $gruplar[$key]['veli_ad'] = trim((string)($sr['veli_adi'] ?? ''));
                    $gruplar[$key]['veli_soyad'] = trim((string)($sr['veli_soyadi'] ?? ''));
                    $gruplar[$key]['tel_temiz'] = $tel_temiz;
                    $gruplar[$key]['tel_orijinal'] = $tel_orijinal;
                    $gruplar[$key]['ogrenci_ad'] = $ogr_ad;
                    $gruplar[$key]['ogrenci_soyad'] = $ogr_soyad;
                }
            }
            $turu = gorusme_norm_exam_type($sr['sinav_turu'] ?? '');
            $basari = $sr['basari_yuzdesi'] !== null ? (float)$sr['basari_yuzdesi'] : null;
            if ($turu === 'ingilizce') {
                $gruplar[$key]['eng_id'] = $sid > 0 ? $sid : $gruplar[$key]['eng_id'];
                $gruplar[$key]['eng_basari'] = $basari;
            } elseif ($turu === 'almanca') {
                $gruplar[$key]['alm_id'] = $sid > 0 ? $sid : $gruplar[$key]['alm_id'];
                $gruplar[$key]['alm_basari'] = $basari;
            } else {
                if ($gruplar[$key]['eng_id'] === null && $sid > 0) {
                    $gruplar[$key]['eng_id'] = $sid;
                    $gruplar[$key]['eng_basari'] = $basari;
                }
            }
        }

        foreach ($gruplar as $g) {
            $eng_id = $g['eng_id'];
            $alm_id = $g['alm_id'];
            $legacy_sid_early = ($eng_id !== null && $eng_id > 0) ? (int)$eng_id : (($alm_id !== null && $alm_id > 0) ? (int)$alm_id : null);

            $where_key = "tel_temiz = " . gorusme_sql_str($conn, $g['tel_temiz']) . "
                  AND ogrenci_ad = " . gorusme_sql_str($conn, $g['ogrenci_ad']) . "
                  AND ogrenci_soyad = " . gorusme_sql_str($conn, $g['ogrenci_soyad']) . "
                  AND sinif = " . (int)$g['sinif'];
            $where_exam = [];
            if ($eng_id !== null && $eng_id > 0) $where_exam[] = "sinav_sonuc_id_ingilizce = " . (int)$eng_id;
            if ($alm_id !== null && $alm_id > 0) $where_exam[] = "sinav_sonuc_id_almanca = " . (int)$alm_id;
            if ($legacy_sid_early !== null) $where_exam[] = "sinav_sonuc_id = " . $legacy_sid_early;
            $where_exam_sql = !empty($where_exam) ? " OR (" . implode(' OR ', $where_exam) . ")" : "";

            $q_exist = "SELECT * FROM gorusme_listesi
                WHERE (" . $where_key . ")" . $where_exam_sql . "
                ORDER BY id ASC";
            $res_exist = mysqli_query($conn, $q_exist);
            $rows = [];
            while ($res_exist && $er = mysqli_fetch_assoc($res_exist)) $rows[] = $er;

            // Aynı grupta hem anahtar hem sınav id ile eşleşen olabilir; önce tel+ad+sinif eşleşenini tercih et
            if (count($rows) > 1) {
                $by_key = [];
                foreach ($rows as $r) {
                    $rt = trim((string)($r['tel_temiz'] ?? ''));
                    $ra = trim((string)($r['ogrenci_ad'] ?? ''));
                    $rs = trim((string)($r['ogrenci_soyad'] ?? ''));
                    $rsf = (int)($r['sinif'] ?? 0);
                    if ($rt === $g['tel_temiz'] && $ra === $g['ogrenci_ad'] && $rs === $g['ogrenci_soyad'] && $rsf === (int)$g['sinif']) {
                        $by_key[] = $r;
                    }
                }
                if (!empty($by_key)) {
                    $rows = $by_key;
                }
            }
            $eng_basari = $g['eng_basari'];
            $alm_basari = $g['alm_basari'];
            $durum = 'Bekliyor';
            $r_tarih = null;
            $r_durum = null;
            $personel_keep = null;
            $islem_keep = null;
            if (!empty($rows)) {
                $durum = $rows[0]['gorusme_durumu'] ?? 'Bekliyor';
                $r_tarih = $rows[0]['randevu_tarihi'] ?? null;
                $r_durum = $rows[0]['randevu_durumu'] ?? null;
                $personel_keep = $rows[0]['personel'] ?? null;
                $islem_keep = $rows[0]['islem_tarihi'] ?? null;
            }
            foreach ($rows as $ex) {
                $ex_eng_id = isset($ex['sinav_sonuc_id_ingilizce']) ? (int)$ex['sinav_sonuc_id_ingilizce'] : 0;
                $ex_alm_id = isset($ex['sinav_sonuc_id_almanca']) ? (int)$ex['sinav_sonuc_id_almanca'] : 0;
                $ex_legacy_id = isset($ex['sinav_sonuc_id']) ? (int)$ex['sinav_sonuc_id'] : 0;
                $ex_turu = gorusme_norm_exam_type($ex['sinav_turu'] ?? '');
                if (($eng_id === null || $eng_id <= 0) && $ex_eng_id > 0) $eng_id = $ex_eng_id;
                if (($alm_id === null || $alm_id <= 0) && $ex_alm_id > 0) $alm_id = $ex_alm_id;
                if ($ex_legacy_id > 0 && $ex_turu === 'ingilizce' && ($eng_id === null || $eng_id <= 0)) $eng_id = $ex_legacy_id;
                if ($ex_legacy_id > 0 && $ex_turu === 'almanca' && ($alm_id === null || $alm_id <= 0)) $alm_id = $ex_legacy_id;
                if ($eng_basari === null && isset($ex['basari_yuzdesi_ingilizce']) && $ex['basari_yuzdesi_ingilizce'] !== null) $eng_basari = (float)$ex['basari_yuzdesi_ingilizce'];
                if ($alm_basari === null && isset($ex['basari_yuzdesi_almanca']) && $ex['basari_yuzdesi_almanca'] !== null) $alm_basari = (float)$ex['basari_yuzdesi_almanca'];
                if ($eng_basari === null && $ex_turu === 'ingilizce' && $ex['basari_yuzdesi'] !== null) $eng_basari = (float)$ex['basari_yuzdesi'];
                if ($alm_basari === null && $ex_turu === 'almanca' && $ex['basari_yuzdesi'] !== null) $alm_basari = (float)$ex['basari_yuzdesi'];
            }

            $primary_id = !empty($rows) ? (int)$rows[0]['id'] : 0;
            $legacy_sid = ($eng_id !== null && $eng_id > 0) ? (int)$eng_id : (($alm_id !== null && $alm_id > 0) ? (int)$alm_id : null);
            $legacy_tur = ($eng_id !== null && $eng_id > 0) ? 'İngilizce Bursluluk' : (($alm_id !== null && $alm_id > 0) ? 'Almanca Bursluluk' : null);
            $legacy_basari = ($eng_basari !== null) ? $eng_basari : $alm_basari;

            if ($primary_id <= 0) {
                $ins = "INSERT INTO gorusme_listesi
                    (veli_ad, veli_soyad, tel_temiz, tel_orijinal, ogrenci_ad, ogrenci_soyad, sinif, sinav_turu, basari_yuzdesi, sinav_sonuc_id, sinav_sonuc_id_ingilizce, sinav_sonuc_id_almanca, basari_yuzdesi_ingilizce, basari_yuzdesi_almanca, gorusme_durumu)
                    VALUES (
                        " . gorusme_sql_str($conn, $g['veli_ad']) . ",
                        " . gorusme_sql_str($conn, $g['veli_soyad']) . ",
                        " . gorusme_sql_str($conn, $g['tel_temiz']) . ",
                        " . gorusme_sql_str($conn, $g['tel_orijinal']) . ",
                        " . gorusme_sql_str($conn, $g['ogrenci_ad']) . ",
                        " . gorusme_sql_str($conn, $g['ogrenci_soyad']) . ",
                        " . (int)$g['sinif'] . ",
                        " . gorusme_sql_str($conn, $legacy_tur) . ",
                        " . gorusme_sql_dec($legacy_basari) . ",
                        " . gorusme_sql_int($legacy_sid) . ",
                        " . gorusme_sql_int($eng_id) . ",
                        " . gorusme_sql_int($alm_id) . ",
                        " . gorusme_sql_dec($eng_basari) . ",
                        " . gorusme_sql_dec($alm_basari) . ",
                        'Bekliyor'
                    )";
                @mysqli_query($conn, $ins);
            } else {
                $upd = "UPDATE gorusme_listesi SET
                        veli_ad = " . gorusme_sql_str($conn, $g['veli_ad']) . ",
                        veli_soyad = " . gorusme_sql_str($conn, $g['veli_soyad']) . ",
                        tel_temiz = " . gorusme_sql_str($conn, $g['tel_temiz']) . ",
                        tel_orijinal = " . gorusme_sql_str($conn, $g['tel_orijinal']) . ",
                        ogrenci_ad = " . gorusme_sql_str($conn, $g['ogrenci_ad']) . ",
                        ogrenci_soyad = " . gorusme_sql_str($conn, $g['ogrenci_soyad']) . ",
                        sinif = " . (int)$g['sinif'] . ",
                        sinav_turu = " . gorusme_sql_str($conn, $legacy_tur) . ",
                        basari_yuzdesi = " . gorusme_sql_dec($legacy_basari) . ",
                        sinav_sonuc_id = " . gorusme_sql_int($legacy_sid) . ",
                        sinav_sonuc_id_ingilizce = " . gorusme_sql_int($eng_id) . ",
                        sinav_sonuc_id_almanca = " . gorusme_sql_int($alm_id) . ",
                        basari_yuzdesi_ingilizce = " . gorusme_sql_dec($eng_basari) . ",
                        basari_yuzdesi_almanca = " . gorusme_sql_dec($alm_basari) . ",
                        gorusme_durumu = " . gorusme_sql_str($conn, $durum) . ",
                        randevu_tarihi = " . gorusme_sql_str($conn, $r_tarih) . ",
                        randevu_durumu = " . gorusme_sql_str($conn, $r_durum) . ",
                        personel = " . gorusme_sql_str($conn, $personel_keep) . ",
                        islem_tarihi = " . gorusme_sql_str($conn, $islem_keep) . "
                    WHERE id = " . (int)$primary_id . " LIMIT 1";
                @mysqli_query($conn, $upd);

                if (count($rows) > 1) {
                    for ($i = 1; $i < count($rows); $i++) {
                        $dup_id = (int)$rows[$i]['id'];
                        if ($dup_id <= 0) continue;
                        @mysqli_query($conn, "UPDATE gorusme_teklif_v2 SET gorusme_listesi_id = " . (int)$primary_id . " WHERE gorusme_listesi_id = " . $dup_id);
                        @mysqli_query($conn, "UPDATE gorusme_notlari SET gorusme_listesi_id = " . (int)$primary_id . " WHERE gorusme_listesi_id = " . $dup_id);
                        @mysqli_query($conn, "DELETE FROM gorusme_listesi WHERE id = " . $dup_id . " LIMIT 1");
                    }
                }
            }
        }
    }
    $redirect_qs = "sinif=" . urlencode($secilen_sinif) . "&sonuc=sync";
    if (!empty($_GET['getir_tel'])) $redirect_qs .= "&getir_tel=" . urlencode((string)$_GET['getir_tel']);
    if (!empty($_GET['odeme_filtresi']) && $_GET['odeme_filtresi'] === '1') $redirect_qs .= "&odeme_filtresi=1";
    if (!empty($_GET['gecmis_randevular']) && $_GET['gecmis_randevular'] === '1') $redirect_qs .= "&gecmis_randevular=1";
    if (!empty($_GET['r_sinif'])) $redirect_qs .= "&r_sinif=" . (int)$_GET['r_sinif'];
    header("Location: gorusmeler.php?" . $redirect_qs);
    exit;
}

// Öğrenci satırından hızlı link oluştur (detaya girmeden)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['btn_hizli_link_olustur'])) {
    $ogrenci_id = (int)($_POST['ogrenci_id'] ?? 0);
    $islem_tel_raw = trim((string)($_POST['islem_tel'] ?? ''));
    $islem_tel = mysqli_real_escape_string($conn, $islem_tel_raw);
    $hizli_link_ok = false;
    $redirect_tel = $islem_tel_raw;
    $hizli_debug = [];
    $hizli_debug[] = 'start:id=' . $ogrenci_id . ',tel=' . $islem_tel_raw;
    if ($ogrenci_id > 0 && $islem_tel_raw !== '') {
        $st = mysqli_prepare($conn, "SELECT id, tel_temiz, sinav_sonuc_id, sinav_sonuc_id_ingilizce, sinav_sonuc_id_almanca FROM gorusme_listesi WHERE id = ? LIMIT 1");
        if ($st) {
            mysqli_stmt_bind_param($st, "i", $ogrenci_id);
            mysqli_stmt_execute($st);
            $rs = mysqli_stmt_get_result($st);
            mysqli_stmt_close($st);
            $ogr = ($rs && mysqli_num_rows($rs) > 0) ? mysqli_fetch_assoc($rs) : null;
            if ($ogr) {
                $hizli_debug[] = 'ogrenci:ok';
                $ogr_tel = trim((string)($ogr['tel_temiz'] ?? ''));
                if ($ogr_tel !== '') $redirect_tel = $ogr_tel;
                $teklif_v2_hizli = teklif_v2_get_latest_by_gorusme($conn, $ogrenci_id);
                $hizli_debug[] = $teklif_v2_hizli ? ('teklif:var,id=' . (int)($teklif_v2_hizli['id'] ?? 0)) : 'teklif:yok';
                if (!$teklif_v2_hizli) {
                    $sira_val = null;
                    $sid_ing = isset($ogr['sinav_sonuc_id_ingilizce']) ? (int)$ogr['sinav_sonuc_id_ingilizce'] : 0;
                    $sid_alm = isset($ogr['sinav_sonuc_id_almanca']) ? (int)$ogr['sinav_sonuc_id_almanca'] : 0;
                    $sid_legacy = isset($ogr['sinav_sonuc_id']) ? (int)$ogr['sinav_sonuc_id'] : 0;
                    $sinav_snapshot = $sid_ing > 0 ? $sid_ing : ($sid_alm > 0 ? $sid_alm : ($sid_legacy > 0 ? $sid_legacy : null));
                    $teklif_v2_hizli = teklif_v2_create($conn, $ogrenci_id, $sira_val, $sinav_snapshot, 'ayri', $_SESSION['personel_adi'] ?? null);
                    @personel_log_ekle($conn, 'gorusmeler.php', 'hizli_link_olustur_v2', ['gorusme_listesi_id' => $ogrenci_id, 'tel' => $islem_tel_raw]);
                    if (!$teklif_v2_hizli) {
                        $hizli_debug[] = 'teklif:create_fail:sql=' . mysqli_error($conn);
                    } else {
                        $hizli_debug[] = 'teklif:create_ok:id=' . (int)($teklif_v2_hizli['id'] ?? 0);
                    }
                }
                // Eski kayıtta token boş kalmışsa onar.
                if (is_array($teklif_v2_hizli) && empty($teklif_v2_hizli['paylasim_token']) && !empty($teklif_v2_hizli['id'])) {
                    $hizli_debug[] = 'token:empty_fix';
                    try {
                        $newToken = bin2hex(random_bytes(16));
                    } catch (Throwable $e) {
                        $newToken = md5(uniqid((string)$ogrenci_id, true));
                    }
                    $uq = mysqli_prepare($conn, "UPDATE gorusme_teklif_v2 SET paylasim_token = ? WHERE id = ? LIMIT 1");
                    if ($uq) {
                        $tid = (int)$teklif_v2_hizli['id'];
                        mysqli_stmt_bind_param($uq, "si", $newToken, $tid);
                        mysqli_stmt_execute($uq);
                        mysqli_stmt_close($uq);
                        $hizli_debug[] = 'token:updated';
                    } else {
                        $hizli_debug[] = 'token:update_prepare_fail:' . mysqli_error($conn);
                    }
                    $teklif_v2_hizli = teklif_v2_get_latest_by_gorusme($conn, $ogrenci_id);
                }
                $hizli_link_ok = is_array($teklif_v2_hizli) && !empty($teklif_v2_hizli['paylasim_token']);
                $hizli_debug[] = $hizli_link_ok ? 'result:ok' : 'result:fail_token_empty';
                if ($hizli_link_ok && $ogr_tel !== '') {
                    $ogr_tel_esc = mysqli_real_escape_string($conn, $ogr_tel);
                    mysqli_query($conn, "UPDATE gorusme_listesi SET personel = '$personel_db', islem_tarihi = NOW() WHERE tel_temiz = '$ogr_tel_esc'");
                }
                if (!$hizli_link_ok && is_array($teklif_v2_hizli)) {
                    $hizli_debug[] = 'teklif_id=' . (int)($teklif_v2_hizli['id'] ?? 0) . ',durum=' . (string)($teklif_v2_hizli['durum'] ?? '');
                }
            } else {
                $hizli_debug[] = 'ogrenci:not_found';
            }
        } else {
            $hizli_debug[] = 'ogrenci:prepare_fail:' . mysqli_error($conn);
        }
    } else {
        $hizli_debug[] = 'input:invalid';
    }
    $_SESSION['hizli_link_debug'] = implode(' | ', $hizli_debug);
    $sonuc_kodu = $hizli_link_ok ? "link_olusturuldu_hizli" : "link_olusturulamadi_hizli";
    $redir = "gorusmeler.php?sinif=" . urlencode($secilen_sinif) . "&getir_tel=" . urlencode($redirect_tel) . "&sonuc=" . $sonuc_kodu;
    if (!empty($_POST['odeme_filtresi']) && $_POST['odeme_filtresi'] === '1') $redir .= "&odeme_filtresi=1";
    header("Location: " . $redir);
    exit;
}

// Görüşme durumu kaydet: aynı tel_temiz'deki tüm öğrencilere uygula (aramalar gibi)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['btn_kaydet'])) {
    $islem_tel = trim($_POST['islem_tel'] ?? '');
    $islem_tel = mysqli_real_escape_string($conn, $islem_tel);
    $durum = mysqli_real_escape_string($conn, $_POST['durum'] ?? 'Bekliyor');
    $izinli = ['Bekliyor', 'Sonuc Iletildi', 'Randevu Alindi', 'Gorusuldu (Yuz Yuze)', 'Gorusuldu (Telefon)', 'Sonuc Icin Ulasilamadi', 'Gorusme Icin Ulasilamadi', 'Ertelendi', 'WhatsappDonusYapmadi', 'KayitOldu', 'KayitOlmakIstemiyor'];
    if (!in_array($durum, $izinli)) $durum = 'Bekliyor';
    $neden_esc = mysqli_real_escape_string($conn, trim($_POST['kayit_istememe_nedeni'] ?? ''));
    $not_esc = mysqli_real_escape_string($conn, trim($_POST['kayit_istememe_not'] ?? ''));
    if ($islem_tel !== '') {
        $set_ek = "";
        $col_check = @mysqli_query($conn, "SHOW COLUMNS FROM gorusme_listesi LIKE 'kayit_istememe_nedeni'");
        if ($col_check && mysqli_num_rows($col_check) > 0) {
            if ($durum === 'KayitOlmakIstemiyor') {
                $set_ek = ", kayit_istememe_nedeni=" . ($neden_esc === '' ? "NULL" : "'$neden_esc'") . ", kayit_istememe_not=" . ($not_esc === '' ? "NULL" : "'$not_esc'");
            } else {
                $set_ek = ", kayit_istememe_nedeni=NULL, kayit_istememe_not=NULL";
            }
        }
        $up = "UPDATE gorusme_listesi SET gorusme_durumu='$durum', personel='$personel_db', islem_tarihi=NOW()$set_ek WHERE tel_temiz='$islem_tel'";
        if (mysqli_query($conn, $up)) {
            @personel_log_ekle($conn, 'gorusmeler.php', 'durum_kaydet', $_POST);
            header("Location: gorusmeler.php?sinif=" . urlencode($secilen_sinif) . "&getir_tel=" . urlencode($islem_tel) . "&sonuc=kaydedildi");
            exit;
        }
    }
    header("Location: gorusmeler.php?sinif=" . urlencode($secilen_sinif) . "&sonuc=hata");
    exit;
}

// Veli + öğrenci temel bilgileri güncelle
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['btn_bilgiler_guncelle'])) {
    $islem_tel_raw = trim((string)($_POST['islem_tel'] ?? ''));
    $islem_tel = mysqli_real_escape_string($conn, $islem_tel_raw);
    $veli_ad = trim((string)($_POST['veli_ad'] ?? ''));
    $veli_soyad = trim((string)($_POST['veli_soyad'] ?? ''));
    $veli_tel_input = trim((string)($_POST['veli_tel'] ?? ''));
    $veli_tel_digits = preg_replace('/\D+/', '', $veli_tel_input);
    if (strlen($veli_tel_digits) === 10) $veli_tel_digits = '0' . $veli_tel_digits;
    if (strlen($veli_tel_digits) === 12 && strpos($veli_tel_digits, '90') === 0) $veli_tel_digits = '0' . substr($veli_tel_digits, 2);
    $veli_tel_temiz = mysqli_real_escape_string($conn, $veli_tel_digits);
    $veli_tel_orijinal = mysqli_real_escape_string($conn, $veli_tel_input !== '' ? $veli_tel_input : $veli_tel_digits);

    $ogrenci_adlari = $_POST['ogrenci_ad'] ?? [];
    $ogrenci_soyadlari = $_POST['ogrenci_soyad'] ?? [];
    $ogrenci_ids = $_POST['ogrenci_ids'] ?? [];

    $redirect_qs = "gorusmeler.php?sinif=" . urlencode($secilen_sinif) . "&getir_tel=" . urlencode($islem_tel_raw);
    if (!empty($_POST['odeme_filtresi']) && $_POST['odeme_filtresi'] === '1') $redirect_qs .= "&odeme_filtresi=1";

    if ($islem_tel_raw === '' || $veli_ad === '' || $veli_soyad === '' || strlen($veli_tel_digits) < 10) {
        header("Location: " . $redirect_qs . "&sonuc=bilgiler_hata");
        exit;
    }

    mysqli_begin_transaction($conn);
    $ok = true;

    $veli_ad_esc = mysqli_real_escape_string($conn, $veli_ad);
    $veli_soyad_esc = mysqli_real_escape_string($conn, $veli_soyad);

    $sql_veli = "UPDATE gorusme_listesi
                 SET veli_ad = '$veli_ad_esc',
                     veli_soyad = '$veli_soyad_esc',
                     tel_temiz = '$veli_tel_temiz',
                     tel_orijinal = '$veli_tel_orijinal',
                     personel = '$personel_db',
                     islem_tarihi = NOW()
                 WHERE tel_temiz = '$islem_tel'";
    if (!mysqli_query($conn, $sql_veli)) {
        $ok = false;
    }

    if ($ok && is_array($ogrenci_ids)) {
        foreach ($ogrenci_ids as $rid_raw) {
            $rid = (int)$rid_raw;
            if ($rid <= 0) continue;
            $o_ad = trim((string)($ogrenci_adlari[$rid] ?? ''));
            $o_soyad = trim((string)($ogrenci_soyadlari[$rid] ?? ''));
            if ($o_ad === '' || $o_soyad === '') {
                $ok = false;
                break;
            }
            $o_ad_esc = mysqli_real_escape_string($conn, $o_ad);
            $o_soyad_esc = mysqli_real_escape_string($conn, $o_soyad);
            $sql_ogr = "UPDATE gorusme_listesi
                        SET ogrenci_ad = '$o_ad_esc',
                            ogrenci_soyad = '$o_soyad_esc',
                            personel = '$personel_db',
                            islem_tarihi = NOW()
                        WHERE id = $rid
                        LIMIT 1";
            if (!mysqli_query($conn, $sql_ogr)) {
                $ok = false;
                break;
            }
        }
    }

    if ($ok) {
        mysqli_commit($conn);
        @personel_log_ekle($conn, 'gorusmeler.php', 'bilgiler_guncelle', $_POST);
        $redirect_tel = $veli_tel_digits !== '' ? $veli_tel_digits : $islem_tel_raw;
        header("Location: gorusmeler.php?sinif=" . urlencode($secilen_sinif) . "&getir_tel=" . urlencode($redirect_tel) . (!empty($_POST['odeme_filtresi']) && $_POST['odeme_filtresi'] === '1' ? "&odeme_filtresi=1" : '') . "&sonuc=bilgiler_guncellendi");
        exit;
    }

    mysqli_rollback($conn);
    header("Location: " . $redirect_qs . "&sonuc=bilgiler_hata");
    exit;
}

// Yeni randevu ekle (30 dk dilimi: sadece tam ve 30 geçe)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['btn_randevu_ekle']) && $randevulari_var) {
    $islem_tel = mysqli_real_escape_string($conn, trim($_POST['islem_tel'] ?? ''));
    $tarih = preg_replace('/[^0-9\-]/', '', $_POST['randevu_tarih'] ?? '');
    $saat = $_POST['randevu_saat'] ?? ''; // HH:mm (00 veya 30 geçe)
    $sorumlu_raw = trim((string)($_POST['randevu_sorumlusu'] ?? ''));
    $sorumlu = $sorumlu_raw !== '' ? $sorumlu_raw : $aktif_personel;
    if (!empty($kullanici_isimleri) && $sorumlu !== '' && !in_array($sorumlu, $kullanici_isimleri, true)) {
        $sorumlu = $aktif_personel;
    }
    if ($islem_tel !== '' && $tarih !== '' && preg_match('/^\d{2}:\d{2}$/', $saat)) {
        $m = (int)substr($saat, 3, 2);
        if (in_array($m, [0, 30], true)) {
            $datetime = $tarih . ' ' . $saat . ':00';
            $dt_esc = mysqli_real_escape_string($conn, $datetime);
            $sorumlu_esc = mysqli_real_escape_string($conn, $sorumlu);
            if ($randevu_sorumlusu_kolon_var) {
                $ins = "INSERT INTO gorusme_randevulari (tel_temiz, randevu_tarihi, randevu_durumu, personel, randevu_sorumlusu, islem_tarihi) VALUES ('$islem_tel', '$dt_esc', 'Bekleniyor', '$personel_db', '$sorumlu_esc', NOW())";
            } else {
                $ins = "INSERT INTO gorusme_randevulari (tel_temiz, randevu_tarihi, randevu_durumu, personel, islem_tarihi) VALUES ('$islem_tel', '$dt_esc', 'Bekleniyor', '$personel_db', NOW())";
            }
            if (mysqli_query($conn, $ins)) {
                @personel_log_ekle($conn, 'gorusmeler.php', 'randevu_ekle', $_POST);
                header("Location: gorusmeler.php?sinif=" . urlencode($secilen_sinif) . "&getir_tel=" . urlencode($islem_tel) . "&sonuc=randevu_eklendi");
                exit;
            }
        }
    }
    header("Location: gorusmeler.php?sinif=" . urlencode($secilen_sinif) . "&getir_tel=" . urlencode($islem_tel ?? '') . "&sonuc=hata");
    exit;
}
// Randevu güncelle (Geldi/Gelmedi + not)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['btn_randevu_guncelle']) && $randevulari_var) {
    $islem_tel = mysqli_real_escape_string($conn, trim($_POST['islem_tel'] ?? ''));
    $col = @mysqli_query($conn, "SHOW COLUMNS FROM gorusme_randevulari LIKE 'randevu_notu'");
    $not_kolon_var = ($col && mysqli_num_rows($col) > 0);
    $col_s = @mysqli_query($conn, "SHOW COLUMNS FROM gorusme_randevulari LIKE 'randevu_sorumlusu'");
    $sorumlu_kolon_var = ($col_s && mysqli_num_rows($col_s) > 0);
    $durumlar = $_POST['randevu_durum'] ?? [];
    $notlar   = $_POST['randevu_not'] ?? [];
    $sorumlular = $_POST['randevu_sorumlu'] ?? [];
    foreach ($durumlar as $rid => $durum) {
        $rid = (int)$rid;
        if ($rid <= 0) continue;
        $durum = in_array($durum, ['Bekleniyor', 'Geldi', 'Gelmedi'], true) ? $durum : 'Bekleniyor';
        $set_sorumlu = '';
        if ($sorumlu_kolon_var && isset($sorumlular[$rid])) {
            $s = trim((string)$sorumlular[$rid]);
            $s_esc = mysqli_real_escape_string($conn, $s);
            $set_sorumlu = ", randevu_sorumlusu = " . ($s === '' ? "NULL" : "'$s_esc'");
        }
        if ($not_kolon_var) {
            $not = isset($notlar[$rid]) ? mysqli_real_escape_string($conn, trim($notlar[$rid])) : '';
            mysqli_query($conn, "UPDATE gorusme_randevulari SET randevu_durumu = '$durum', randevu_notu = " . ($not === '' ? "NULL" : "'$not'") . "$set_sorumlu, islem_tarihi = NOW() WHERE id = $rid AND tel_temiz = '$islem_tel'");
        } else {
            mysqli_query($conn, "UPDATE gorusme_randevulari SET randevu_durumu = '$durum'$set_sorumlu, islem_tarihi = NOW() WHERE id = $rid AND tel_temiz = '$islem_tel'");
        }
    }
    @personel_log_ekle($conn, 'gorusmeler.php', 'randevu_guncelle', $_POST);
    header("Location: gorusmeler.php?sinif=" . urlencode($secilen_sinif) . "&getir_tel=" . urlencode($islem_tel) . "&sonuc=randevu_guncellendi");
    exit;
}
// Randevu sil
if (isset($_GET['sil_randevu']) && $randevulari_var) {
    $rid = (int)$_GET['sil_randevu'];
    $tel = isset($_GET['getir_tel']) ? mysqli_real_escape_string($conn, $_GET['getir_tel']) : '';
    if ($rid > 0 && $tel !== '') {
        mysqli_query($conn, "DELETE FROM gorusme_randevulari WHERE id = $rid AND tel_temiz = '$tel'");
        @personel_log_ekle($conn, 'gorusmeler.php', 'randevu_sil', $_GET);
    }
    header("Location: gorusmeler.php?sinif=" . urlencode($secilen_sinif) . "&getir_tel=" . urlencode($tel) . "&sonuc=randevu_silindi");
    exit;
}

// Akıllı arama (veli / öğrenci) - AJAX HTML döner (aramalar.php ile aynı mantık)
if (isset($_GET['arama_q'])) {
    header('Content-Type: text/html; charset=utf-8');
    $aranan = trim($_GET['arama_q'] ?? '');
    if ($aranan === '' || mb_strlen($aranan, 'UTF-8') < 2) {
        echo '<div class="p-2 text-muted small text-center">En az 2 karakter yazınız.</div>';
        exit;
    }
    $q = mysqli_real_escape_string($conn, $aranan);
    $like = "%" . $q . "%";
    $aranan_tel_raw = preg_replace('/\D+/', '', $aranan);
    $tel_kosul = '';
    if ($aranan_tel_raw !== '' && strlen($aranan_tel_raw) >= 4) {
        $q_tel = mysqli_real_escape_string($conn, $aranan_tel_raw);
        $tel_kosul = " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tel_temiz, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE '%{$q_tel}%'";
    }
    $sql_arama = "
        SELECT MIN(veli_ad) AS veli_ad, MIN(veli_soyad) AS veli_soyad, MIN(ogrenci_ad) AS ogrenci_ad, MIN(ogrenci_soyad) AS ogrenci_soyad, tel_temiz
        FROM gorusme_listesi
        WHERE CONCAT(veli_ad, ' ', veli_soyad) LIKE '$like' OR veli_ad LIKE '$like' OR veli_soyad LIKE '$like'
           OR CONCAT(ogrenci_ad, ' ', ogrenci_soyad) LIKE '$like' OR ogrenci_ad LIKE '$like' OR ogrenci_soyad LIKE '$like'
           $tel_kosul
        GROUP BY tel_temiz
        ORDER BY veli_ad ASC, veli_soyad ASC
        LIMIT 30
    ";
    $res_arama = mysqli_query($conn, $sql_arama);
    if (!$res_arama || mysqli_num_rows($res_arama) === 0) {
        echo '<div class="p-2 text-muted small text-center">Eşleşen kayıt bulunamadı.</div>';
        exit;
    }
    echo '<div class="list-group list-group-flush">';
    while ($row = mysqli_fetch_assoc($res_arama)) {
        $veli = trim(($row['veli_ad'] ?? '') . ' ' . ($row['veli_soyad'] ?? ''));
        $ogr  = trim(($row['ogrenci_ad'] ?? '') . ' ' . ($row['ogrenci_soyad'] ?? ''));
        $tel  = htmlspecialchars($row['tel_temiz'] ?? '', ENT_QUOTES, 'UTF-8');
        $veli_h = htmlspecialchars($veli, ENT_QUOTES, 'UTF-8');
        $ogr_h  = htmlspecialchars($ogr, ENT_QUOTES, 'UTF-8');
        $tel_attr = htmlspecialchars($row['tel_temiz'] ?? '', ENT_QUOTES, 'UTF-8');
        echo '<button type="button" class="list-group-item list-group-item-action py-2 px-3 border-bottom border-light" data-tel="' . $tel_attr . '" onclick="aramaKaydaGit(this.getAttribute(\'data-tel\'))">';
        echo '<div class="fw-bold fs-6 text-dark">' . ($veli_h !== '' ? $veli_h : 'Veli Bilgisi Yok') . '</div>';
        if ($ogr_h !== '') echo '<div class="text-muted small mt-1"><i class="bi bi-mortarboard me-1"></i> ' . $ogr_h . '</div>';
        echo '</button>';
    }
    echo '</div>';
    exit;
}

if (isset($_GET['sonuc'])) {
    if ($_GET['sonuc'] === 'kaydedildi') { $mesaj = "Kayıt güncellendi (tüm öğrencilere uygulandı)."; $mesajTur = "success"; }
    if ($_GET['sonuc'] === 'randevu_eklendi') { $mesaj = "Randevu eklendi."; $mesajTur = "success"; }
    if ($_GET['sonuc'] === 'randevu_silindi') { $mesaj = "Randevu silindi."; $mesajTur = "info"; }
    if ($_GET['sonuc'] === 'randevu_guncellendi') { $mesaj = "Randevular güncellendi."; $mesajTur = "success"; }
    if ($_GET['sonuc'] === 'sync') { $mesaj = "Sınav sonuçlarından liste güncellendi. Notu girilmiş öğrenciler eklendi veya başarı yüzdesi güncellendi."; $mesajTur = "info"; }
    if ($_GET['sonuc'] === 'link_olusturuldu_hizli') {
        $mesaj = "Öğrenci için sonuç linki oluşturuldu.";
        $mesajTur = "success";
        unset($_SESSION['hizli_link_debug']);
    }
    if ($_GET['sonuc'] === 'link_olusturulamadi_hizli') {
        $mesaj = "Öğrenci için sonuç linki oluşturulamadı. Lütfen tekrar deneyin.";
        $mesajTur = "danger";
        $mesajDetay = isset($_SESSION['hizli_link_debug']) ? (string)$_SESSION['hizli_link_debug'] : '';
    }
    if ($_GET['sonuc'] === 'hata') { $mesaj = "Hata oluştu."; $mesajTur = "danger"; }
    if ($_GET['sonuc'] === 'bilgiler_guncellendi') { $mesaj = "Veli ve öğrenci bilgileri güncellendi."; $mesajTur = "success"; }
    if ($_GET['sonuc'] === 'bilgiler_hata') { $mesaj = "Bilgi güncelleme sırasında hata oluştu. Alanları kontrol edip tekrar deneyin."; $mesajTur = "danger"; }
}
if (isset($_GET['import_sonuc']) && $_GET['import_sonuc'] !== '') {
    $mesaj = "<i class='bi bi-inbox-fill me-1'></i> " . $_GET['import_sonuc'];
    $mesajTur = "info";
}

$gecmis_randevu = isset($_GET['gecmis_randevular']) && $_GET['gecmis_randevular'] === '1';
$r_sinif = isset($_GET['r_sinif']) ? (int)$_GET['r_sinif'] : 0;
$r_gun = isset($_GET['r_gun']) ? preg_replace('/[^0-9\-]/', '', $_GET['r_gun']) : '';
$r_saat = isset($_GET['r_saat']) ? trim($_GET['r_saat']) : ''; // HH veya HH:mm (sadece :00 ve :30)
$randevu_filtre_siniflar = [];
$randevu_sinif_res = mysqli_query($conn, "SELECT DISTINCT sinif FROM gorusme_listesi WHERE sinif IS NOT NULL AND sinif > 0 ORDER BY sinif ASC");
if ($randevu_sinif_res) {
    while ($rsf = mysqli_fetch_assoc($randevu_sinif_res)) {
        $randevu_filtre_siniflar[] = (int)$rsf['sinif'];
    }
}

// Yaklaşan randevularda güne/saate göre filtre (sadece yaklaşan modunda)
$r_gun_kosul = '';
$r_saat_kosul = '';
if (!$gecmis_randevu) {
    if ($r_gun !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r_gun)) {
        $r_gun_esc = mysqli_real_escape_string($conn, $r_gun);
        $r_gun_kosul = $randevulari_var ? " AND DATE(r.randevu_tarihi) = '$r_gun_esc'" : " AND DATE(randevu_tarihi) = '$r_gun_esc'";
    }
    if ($r_saat !== '') {
        if (preg_match('/^\d{1,2}:\d{2}$/', $r_saat)) {
            $parca = explode(':', $r_saat);
            $h = (int)$parca[0];
            $m = isset($parca[1]) ? (int)$parca[1] : 0;
            if ($m !== 0 && $m !== 30) $m = $m < 30 ? 0 : 30;
            $r_saat_str = sprintf('%02d:%02d:00', $h, $m);
            $r_saat_esc = mysqli_real_escape_string($conn, $r_saat_str);
            $r_saat_kosul = $randevulari_var ? " AND TIME(r.randevu_tarihi) = '$r_saat_esc'" : " AND TIME(randevu_tarihi) = '$r_saat_esc'";
        } elseif (preg_match('/^\d{1,2}$/', $r_saat)) {
            $h = (int)$r_saat;
            if ($h >= 0 && $h <= 23) {
                $r_saat_kosul = $randevulari_var ? " AND HOUR(r.randevu_tarihi) = $h" : " AND HOUR(randevu_tarihi) = $h";
            }
        }
    }
}

// Randevular: gorusme_randevulari tablosundan (varsa); yoksa eski gorusme_listesi randevu_tarihi
if ($randevulari_var) {
    $r_tarih_kosul = $gecmis_randevu ? "r.randevu_tarihi < NOW()" : "r.randevu_tarihi >= NOW()";
    $r_sinif_join = $r_sinif > 0 ? " AND g.sinif = $r_sinif" : "";
    $r_sel_sorumlu = $randevu_sorumlusu_kolon_var ? ", r.randevu_sorumlusu" : "";
    $r_grp_sorumlu = $randevu_sorumlusu_kolon_var ? ", r.randevu_sorumlusu" : "";
    $res_randevular = mysqli_query($conn, "SELECT r.id, r.tel_temiz, r.randevu_tarihi, r.randevu_durumu, r.personel{$r_sel_sorumlu}, MIN(g.veli_ad) AS veli_ad, MIN(g.veli_soyad) AS veli_soyad, MIN(g.sinif) AS sinif, GROUP_CONCAT(DISTINCT CONCAT(g.ogrenci_ad, ' ', g.ogrenci_soyad, ' (', g.sinif, '. Sınıf)') SEPARATOR ', ') AS ogrenciler FROM gorusme_randevulari r INNER JOIN gorusme_listesi g ON g.tel_temiz = r.tel_temiz WHERE $r_tarih_kosul $r_sinif_join $r_gun_kosul $r_saat_kosul GROUP BY r.id, r.tel_temiz, r.randevu_tarihi, r.randevu_durumu, r.personel{$r_grp_sorumlu} ORDER BY r.randevu_tarihi " . ($gecmis_randevu ? "DESC" : "ASC"));
    $res_randevu_siniflar = mysqli_query($conn, "SELECT DISTINCT g.sinif FROM gorusme_randevulari r JOIN gorusme_listesi g ON g.tel_temiz = r.tel_temiz ORDER BY g.sinif");
} else {
    $r_tarih_kosul = $gecmis_randevu ? "randevu_tarihi < NOW()" : "randevu_tarihi >= NOW()";
    $r_sinif_kosul = $r_sinif > 0 ? " AND sinif = $r_sinif" : "";
    $res_randevular = mysqli_query($conn, "SELECT MIN(id) AS id, MAX(veli_ad) AS veli_ad, MAX(veli_soyad) AS veli_soyad, tel_temiz, MIN(sinif) AS sinif, MIN(randevu_tarihi) AS randevu_tarihi, MAX(randevu_durumu) AS randevu_durumu, MAX(personel) AS personel, GROUP_CONCAT(DISTINCT CONCAT(ogrenci_ad, ' ', ogrenci_soyad, ' (', sinif, '. Sınıf)') SEPARATOR ', ') AS ogrenciler FROM gorusme_listesi WHERE randevu_tarihi IS NOT NULL AND $r_tarih_kosul $r_sinif_kosul $r_gun_kosul $r_saat_kosul GROUP BY tel_temiz ORDER BY randevu_tarihi " . ($gecmis_randevu ? "DESC" : "ASC"));
    $res_randevu_siniflar = mysqli_query($conn, "SELECT DISTINCT sinif FROM gorusme_listesi WHERE randevu_tarihi IS NOT NULL ORDER BY sinif");
}

// Seçili veli: getir_tel (tel_temiz) ile; aynı numaradaki tüm öğrenciler birleşik
$bulunan_tel = isset($_GET['getir_tel']) ? mysqli_real_escape_string($conn, trim($_GET['getir_tel'])) : '';
if ($bulunan_tel !== '') {
    $res_aile = mysqli_query($conn, "SELECT * FROM gorusme_listesi WHERE tel_temiz = '$bulunan_tel' ORDER BY ogrenci_ad, ogrenci_soyad");
    while ($row = mysqli_fetch_assoc($res_aile)) $aile_verisi[] = $row;
    if (!empty($aile_verisi)) {
        $not_gorusme_id = (int)$aile_verisi[0]['id']; // Notlar ilk öğrenci satırına bağlı
        $not_res = mysqli_query($conn, "SELECT $not_select_fields FROM gorusme_notlari WHERE gorusme_listesi_id = $not_gorusme_id ORDER BY sira ASC, id ASC");
        while ($n = mysqli_fetch_assoc($not_res)) $secili_notlar[] = $n;
    }
} elseif ($secilen_sinif_aktif) {
    $sinif_w_ilk = gorusme_sinif_where($secilen_sinif, $LISE_SINIFLAR);
    $odeme_kosul_ilk = $odeme_filtresi ? " AND tel_temiz IN ($odeme_tel_alt_sorgu)" : "";
    $ilk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT tel_temiz FROM gorusme_listesi WHERE $sinif_w_ilk $odeme_kosul_ilk GROUP BY tel_temiz ORDER BY MIN(veli_ad), MIN(ogrenci_ad) LIMIT 1"));
    if ($ilk && !empty($ilk['tel_temiz'])) {
        $bulunan_tel = $ilk['tel_temiz'];
        $res_aile = mysqli_query($conn, "SELECT * FROM gorusme_listesi WHERE tel_temiz = '" . mysqli_real_escape_string($conn, $bulunan_tel) . "' ORDER BY ogrenci_ad, ogrenci_soyad");
        while ($row = mysqli_fetch_assoc($res_aile)) $aile_verisi[] = $row;
        if (!empty($aile_verisi)) {
            $not_gorusme_id = (int)$aile_verisi[0]['id'];
            $not_res = mysqli_query($conn, "SELECT $not_select_fields FROM gorusme_notlari WHERE gorusme_listesi_id = $not_gorusme_id ORDER BY sira ASC, id ASC");
            while ($n = mysqli_fetch_assoc($not_res)) $secili_notlar[] = $n;
        }
    }
}
$secili_kayit = !empty($aile_verisi) ? $aile_verisi[0] : null;
$getir_id = $secili_kayit ? (int)$secili_kayit['id'] : 0;
$not_gorusme_id = !empty($aile_verisi) ? (int)$aile_verisi[0]['id'] : 0; // Notlar ilk satıra bağlı

// Seçili veli için ödeme yapan öğrenci id'leri (detayda "Ödeme yaptı" göstermek için)
$odeme_yapan_ogrenci_ids = [];
if ($bulunan_tel !== '') {
    $res_odeme_gl = @mysqli_query($conn, "SELECT DISTINCT gl.id FROM gorusme_listesi gl INNER JOIN gorusme_teklif_v2 tv2 ON tv2.gorusme_listesi_id = gl.id INNER JOIN paytr_odemeler p ON p.teklif_v2_id = tv2.id AND p.durum = 'success' WHERE gl.tel_temiz = '" . mysqli_real_escape_string($conn, $bulunan_tel) . "'");
    if ($res_odeme_gl) { while ($r = mysqli_fetch_assoc($res_odeme_gl)) $odeme_yapan_ogrenci_ids[] = (int)$r['id']; }
}

// Sıra hesabı: Öncelik sinav_sonuclari verisi (admin/panel ile aynı mantık), fallback gorusme_listesi
$sira_by_id = [];
$toplam_by_id = [];
$rank_by_sonuc = [];
$total_by_sonuc = [];

$has_ss = @mysqli_query($conn, "SHOW TABLES LIKE 'sinav_sonuclari'");
if ($has_ss && mysqli_num_rows($has_ss) > 0) {
    $has_toplam_yanlis = false;
    $has_dogum_tarihi = false;
    $c1 = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari LIKE 'toplam_yanlis'");
    if ($c1 && mysqli_num_rows($c1) > 0) $has_toplam_yanlis = true;
    $c2 = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari LIKE 'dogum_tarihi'");
    if ($c2 && mysqli_num_rows($c2) > 0) $has_dogum_tarihi = true;

    $ss_sql = "SELECT id, sinif, IFNULL(sinav_turu,'') AS sinav_turu, basari_yuzdesi";
    if ($has_toplam_yanlis) $ss_sql .= ", toplam_yanlis"; else $ss_sql .= ", 0 AS toplam_yanlis";
    if ($has_dogum_tarihi) $ss_sql .= ", dogum_tarihi"; else $ss_sql .= ", NULL AS dogum_tarihi";
    $ss_sql .= " FROM sinav_sonuclari";
    $res_ss = mysqli_query($conn, $ss_sql);
    if ($res_ss) {
        $gruplar = [];
        while ($row = mysqli_fetch_assoc($res_ss)) {
            $sinif_raw = trim((string)($row['sinif'] ?? '0'));
            $sinif_no = (int)(strpos($sinif_raw, '.') !== false ? substr($sinif_raw, 0, strpos($sinif_raw, '.')) : $sinif_raw);
            if ($sinif_no <= 0) $sinif_no = (int)$sinif_raw;
            $grup_key = in_array($sinif_no, $LISE_SINIFLAR, true) ? 'Lise' : (string)$sinif_no;
            $key = $grup_key . '_' . trim((string)($row['sinav_turu'] ?? ''));
            if (!isset($gruplar[$key])) $gruplar[$key] = [];
            $gruplar[$key][] = [
                'id' => (int)$row['id'],
                'basari' => $row['basari_yuzdesi'] === null ? -1 : (float)$row['basari_yuzdesi'],
                'yanlis' => (int)($row['toplam_yanlis'] ?? 0),
                'dogum' => $row['dogum_tarihi'] ?? null,
            ];
        }
        foreach ($gruplar as $ids) {
            usort($ids, function($a, $b) {
                if ($a['basari'] !== $b['basari']) return ($a['basari'] < $b['basari']) ? 1 : -1;
                if ($a['yanlis'] !== $b['yanlis']) return ($a['yanlis'] < $b['yanlis']) ? -1 : 1;
                $aNull = empty($a['dogum']) ? 1 : 0;
                $bNull = empty($b['dogum']) ? 1 : 0;
                if ($aNull !== $bNull) return $aNull <=> $bNull;
                if (!$aNull && !$bNull && $a['dogum'] !== $b['dogum']) return strcmp((string)$b['dogum'], (string)$a['dogum']);
                return $a['id'] <=> $b['id'];
            });
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                $rank_by_sonuc[$ids[$i]['id']] = $i + 1;
                $total_by_sonuc[$ids[$i]['id']] = $n;
            }
        }
    }
}

$res_gl_rank = mysqli_query($conn, "SELECT id, sinav_sonuc_id, sinav_sonuc_id_ingilizce, sinav_sonuc_id_almanca, basari_yuzdesi, basari_yuzdesi_ingilizce, basari_yuzdesi_almanca, sinif, sinav_turu FROM gorusme_listesi ORDER BY sinif ASC, sinav_turu ASC, basari_yuzdesi DESC, id ASC");
if ($res_gl_rank) {
    $fallback_gruplar = [];
    while ($row = mysqli_fetch_assoc($res_gl_rank)) {
        $gid = (int)$row['id'];
        $sid_eng = (int)($row['sinav_sonuc_id_ingilizce'] ?? 0);
        $sid_alm = (int)($row['sinav_sonuc_id_almanca'] ?? 0);
        $sid_legacy = (int)($row['sinav_sonuc_id'] ?? 0);
        $sid = $sid_eng > 0 ? $sid_eng : ($sid_alm > 0 ? $sid_alm : $sid_legacy);
        if ($sid > 0 && isset($rank_by_sonuc[$sid], $total_by_sonuc[$sid])) {
            $sira_by_id[$gid] = $rank_by_sonuc[$sid];
            $toplam_by_id[$gid] = $total_by_sonuc[$sid];
            continue;
        }
        $sinif_no = (int)$row['sinif'];
        $grup_key = in_array($sinif_no, $LISE_SINIFLAR, true) ? 'Lise' : (string)$sinif_no;
        $fallback_turu = $sid_eng > 0 ? 'İngilizce' : ($sid_alm > 0 ? 'Almanca' : trim((string)($row['sinav_turu'] ?? '')));
        $key = $grup_key . '_' . $fallback_turu;
        $basari_pref = $row['basari_yuzdesi_ingilizce'];
        if ($basari_pref === null || $basari_pref === '') $basari_pref = $row['basari_yuzdesi_almanca'];
        if ($basari_pref === null || $basari_pref === '') $basari_pref = $row['basari_yuzdesi'];
        if (!isset($fallback_gruplar[$key])) $fallback_gruplar[$key] = [];
        $fallback_gruplar[$key][] = ['id' => $gid, 'basari' => $basari_pref === null ? -1 : (float)$basari_pref];
    }
    foreach ($fallback_gruplar as $ids) {
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            $sira_by_id[$ids[$i]['id']] = $i + 1;
            $toplam_by_id[$ids[$i]['id']] = $n;
        }
    }
}

$secili_randevular = [];
$randevu_notu_kolon_var = false;
if ($randevulari_var && $bulunan_tel !== '') {
    $col = @mysqli_query($conn, "SHOW COLUMNS FROM gorusme_randevulari LIKE 'randevu_notu'");
    $randevu_notu_kolon_var = ($col && mysqli_num_rows($col) > 0);
    $sel_sorumlu = $randevu_sorumlusu_kolon_var ? ", randevu_sorumlusu" : "";
    $sel = $randevu_notu_kolon_var ? "id, randevu_tarihi, randevu_durumu, randevu_notu, personel{$sel_sorumlu}" : "id, randevu_tarihi, randevu_durumu, personel{$sel_sorumlu}";
    $res_r = mysqli_query($conn, "SELECT $sel FROM gorusme_randevulari WHERE tel_temiz = '" . mysqli_real_escape_string($conn, $bulunan_tel) . "' ORDER BY randevu_tarihi ASC");
    while ($rr = mysqli_fetch_assoc($res_r)) {
        if (!$randevu_notu_kolon_var) $rr['randevu_notu'] = '';
        $secili_randevular[] = $rr;
    }
}

$res_sinif_list = null;
$sinif_durum_adet = [];
if ($secilen_sinif_aktif) {
    $sinif_w = gorusme_sinif_where($secilen_sinif, $LISE_SINIFLAR);
    $order_durum = "FIELD(gorusme_durumu, 'Bekliyor', 'Sonuc Iletildi', 'Randevu Alindi', 'Gorusuldu (Yuz Yuze)', 'Gorusuldu (Telefon)', 'Sonuc Icin Ulasilamadi', 'Gorusme Icin Ulasilamadi', 'Ertelendi', 'WhatsappDonusYapmadi', 'KayitOldu', 'KayitOlmakIstemiyor')";
    $odeme_kosul = $odeme_filtresi ? " AND tel_temiz IN ($odeme_tel_alt_sorgu)" : "";
    $res_sinif_list = mysqli_query($conn, "SELECT tel_temiz, MIN(veli_ad) AS veli_ad, MIN(veli_soyad) AS veli_soyad, MIN(gorusme_durumu) AS gorusme_durumu, GROUP_CONCAT(DISTINCT CONCAT(ogrenci_ad, ' ', ogrenci_soyad) SEPARATOR ', ') AS ogrenciler FROM gorusme_listesi WHERE $sinif_w $odeme_kosul GROUP BY tel_temiz ORDER BY $order_durum, veli_ad, veli_soyad");
    $adet_res = mysqli_query($conn, "SELECT gorusme_durumu AS durum, COUNT(DISTINCT tel_temiz) AS adet FROM gorusme_listesi WHERE $sinif_w $odeme_kosul GROUP BY gorusme_durumu");
    while ($a = mysqli_fetch_assoc($adet_res)) $sinif_durum_adet[$a['durum']] = (int)$a['adet'];
    $odeme_yapan_tel_list = [];
    $res_odeme_tel = @mysqli_query($conn, "SELECT DISTINCT gl.tel_temiz FROM gorusme_listesi gl INNER JOIN gorusme_teklif_v2 tv2 ON tv2.gorusme_listesi_id = gl.id INNER JOIN paytr_odemeler p ON p.teklif_v2_id = tv2.id AND p.durum = 'success' WHERE $sinif_w");
    if ($res_odeme_tel) { while ($r = mysqli_fetch_assoc($res_odeme_tel)) $odeme_yapan_tel_list[] = $r['tel_temiz']; }
} else {
    $odeme_yapan_tel_list = [];
}
$odeme_bildirimleri = [];
$notify_sinif_where = "1=1";
if ($secilen_sinif_aktif) {
    $notify_sinif_where = gorusme_sinif_where($secilen_sinif, $LISE_SINIFLAR, 'gl');
}
$q_odeme_bildirim = "SELECT
        gl.tel_temiz,
        MIN(gl.veli_ad) AS veli_ad,
        MIN(gl.veli_soyad) AS veli_soyad,
        GROUP_CONCAT(DISTINCT CONCAT(gl.ogrenci_ad, ' ', gl.ogrenci_soyad, ' (', gl.sinif, '. Sınıf)') SEPARATOR ', ') AS ogrenciler,
        MAX(COALESCE(p.updated_at, p.created_at)) AS son_odeme_tarihi,
        MAX(gl.islem_tarihi) AS son_islem_tarihi
    FROM gorusme_listesi gl
    INNER JOIN gorusme_teklif_v2 tv2 ON tv2.gorusme_listesi_id = gl.id
    INNER JOIN paytr_odemeler p ON p.teklif_v2_id = tv2.id AND p.durum = 'success'
    WHERE $notify_sinif_where
    GROUP BY gl.tel_temiz
    HAVING son_odeme_tarihi IS NOT NULL AND (son_islem_tarihi IS NULL OR son_islem_tarihi < son_odeme_tarihi)
    ORDER BY son_odeme_tarihi DESC
    LIMIT 100";
$res_odeme_bildirim = @mysqli_query($conn, $q_odeme_bildirim);
if ($res_odeme_bildirim) {
    while ($rb = mysqli_fetch_assoc($res_odeme_bildirim)) {
        $odeme_bildirimleri[] = $rb;
    }
}
$result_siniflar_raw = mysqli_query($conn, "SELECT DISTINCT sinif FROM gorusme_listesi ORDER BY sinif ASC");
$sinif_dropdown_opts = [];
$has_lise_sinif = false;
if ($result_siniflar_raw) {
    while ($s = mysqli_fetch_assoc($result_siniflar_raw)) {
        $sn = (int)$s['sinif'];
        if (in_array($sn, $LISE_SINIFLAR, true)) { $has_lise_sinif = true; continue; }
        $sinif_dropdown_opts[] = ['value' => (string)$sn, 'label' => $sn . '. Sınıf'];
    }
    if ($has_lise_sinif) $sinif_dropdown_opts[] = ['value' => 'Lise', 'label' => 'Lise'];
}

// Görüşme durumu -> CSS sınıfı (liste ve legend renkleri)
$durum_renk_sinifi = [
    'Bekliyor' => 'status-bekliyor',
    'Sonuc Iletildi' => 'status-sonuc-iletildi',
    'Randevu Alindi' => 'status-randevu-alindi',
    'Gorusuldu (Yuz Yuze)' => 'status-gorusuldu-yuz-yuze',
    'Gorusuldu (Telefon)' => 'status-gorusuldu-telefon',
    'Sonuc Icin Ulasilamadi' => 'status-sonuc-icin-ulasilamadi',
    'Gorusme Icin Ulasilamadi' => 'status-gorusme-icin-ulasilamadi',
    'Ertelendi' => 'status-ertelendi',
    'WhatsappDonusYapmadi' => 'status-whatsapp-donus-yapmadi',
    'KayitOldu' => 'status-kayit-oldu',
    'KayitOlmakIstemiyor' => 'status-kayit-istemiyor',
];
$durum_sirasi = ['Bekliyor', 'Sonuc Iletildi', 'Randevu Alindi', 'Gorusuldu (Yuz Yuze)', 'Gorusuldu (Telefon)', 'Sonuc Icin Ulasilamadi', 'Gorusme Icin Ulasilamadi', 'Ertelendi', 'WhatsappDonusYapmadi', 'KayitOldu', 'KayitOlmakIstemiyor'];
// Legend’da kısa etiket (yoksa gorusme_durumu değeri kullanılır)
$durum_etiket = [
    'WhatsappDonusYapmadi' => "WhatsApp dönüş yok",
    'KayitOldu' => 'Kayıt oldu',
    'KayitOlmakIstemiyor' => 'Kayıt istemiyor',
    'Gorusuldu (Yuz Yuze)' => 'Yüz yüze',
    'Gorusuldu (Telefon)' => 'Telefon',
    'Sonuc Icin Ulasilamadi' => 'Sonuç için ulaşılamadı',
    'Gorusme Icin Ulasilamadi' => 'Görüşme için ulaşılamadı',
];
$durum_gruplari_legend = [
    'İlerleme' => ['Bekliyor', 'Sonuc Iletildi', 'Randevu Alindi'],
    'Görüşülenler' => ['Gorusuldu (Yuz Yuze)', 'Gorusuldu (Telefon)'],
    'Ulaşılamayanlar' => ['Sonuc Icin Ulasilamadi', 'Gorusme Icin Ulasilamadi', 'WhatsappDonusYapmadi'],
    'Sonuç' => ['KayitOldu', 'KayitOlmakIstemiyor'],
    'Diğer' => ['Ertelendi'],
];
$kayit_istememe_nedenleri = [
    'fiyat_pahali' => 'Fiyat pahalı',
    'zaman_uygun_degil' => 'Zaman uygun değil',
    'baska_kurum_tercih' => 'Başka kurum tercih ediyor',
    'program_uygun_degil' => 'Program uygun değil',
    'bilgi_yetersiz' => 'Bilgi yetersiz kaldı',
    'diger' => 'Başka neden',
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Görüşmeler - Sınav Sonuç & Randevu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons Entegrasyonu -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --g-primary: #0f766e;        /* Teal 700 */
            --g-primary-dark: #115e59;   /* Teal 800 */
            --g-primary-light: #ccfbf1;  /* Teal 100 */
            --g-bg: #f3f4f6;             /* Gray 100 */
            --g-card: #ffffff;
            --g-border: #e5e7eb;         /* Gray 200 */
            --g-text: #1f2937;           /* Gray 800 */
            --g-muted: #6b7280;          /* Gray 500 */
            --g-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --g-shadow-hover: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --g-radius: 12px;
            --g-radius-sm: 8px;
        }
        body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--g-bg); color: var(--g-text); }
        .top-bar {
            background: linear-gradient(135deg, var(--g-primary) 0%, var(--g-primary-dark) 100%);
            padding: 16px 28px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 4px 12px rgba(15, 118, 110, 0.2);
        }
        .top-bar .brand { color: #fff; font-weight: 700; font-size: 1.25rem; letter-spacing: -0.02em; display:flex; align-items:center; gap:8px;}
        .top-bar .brand span { opacity: 0.85; font-weight: 500; font-size: 0.95rem; }
        .top-bar .btn-outline-light { border-color: rgba(255,255,255,0.4); color: #fff; border-radius: 8px;}
        .top-bar .btn-outline-light:hover { background: rgba(255,255,255,0.15); border-color: #fff; color: #fff; }
        .top-bar .text-person { color: rgba(255,255,255,0.95); font-size: 0.95rem; font-weight: 500;}
        .section-title {
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--g-muted);
            margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--g-border); display: flex; align-items: center; gap: 6px;
        }
        .sidebar-scroll {
            overflow-y: auto; background: var(--g-card); border-radius: var(--g-radius);
            padding: 16px; box-shadow: var(--g-shadow); border: 1px solid var(--g-border);
            position: relative;
        }
        @media (min-width: 992px) {
            #scroll-randevular { height: calc(100vh - 200px); }
            #sidebar-sinif { display: flex; flex-direction: column; max-height: calc(100vh - 170px); }
            #sidebar-sinif .section-title { margin-bottom: 6px; font-size: 0.7rem; }
            #sidebar-sinif #scroll-liste { flex: 1 1 0; min-height: 0; scroll-behavior: smooth; }
        }
        @media (max-width: 991px) {
            .sidebar-scroll { max-height: 400px; scroll-behavior: smooth; }
        }
        .sidebar-scroll::-webkit-scrollbar { width: 6px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 6px; }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Kart Stilleri */
        .card { border: none; border-radius: var(--g-radius); box-shadow: var(--g-shadow); margin-bottom: 16px; transition: all 0.3s ease;}
        .card-header { font-weight: 700; padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid var(--g-border); border-top-left-radius: var(--g-radius); border-top-right-radius: var(--g-radius); font-size: 0.9rem; color: var(--g-text);}
        .card-body { padding: 16px; }
        .gorusme-card .card-header { background: linear-gradient(135deg, var(--g-primary) 0%, var(--g-primary-dark) 100%); color: #fff; border: none; }
        
        /* Veli Başlık Kutusu */
        .veli-box {
            background: var(--g-card); padding: 16px 20px; border-radius: var(--g-radius);
            border-left: 4px solid var(--g-primary); box-shadow: var(--g-shadow);
            margin-bottom: 16px; position: relative;
        }
        .veli-box .veli-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--g-primary); margin-bottom: 4px; }
        .tel-link { font-size: 1.1rem; font-weight: 700; text-decoration: none; color: var(--g-primary); display: flex; align-items: center; gap: 6px; justify-content: flex-end;}
        .tel-link:hover { color: var(--g-primary-dark); }
        
        /* Liste İtemleri */
        .randevu-item, .class-list-item {
            border: 1px solid var(--g-border); padding: 12px 14px; cursor: pointer; font-size: 0.875rem;
            border-radius: var(--g-radius-sm); margin-bottom: 8px; transition: all 0.2s ease; background: #fff;
        }
        .randevu-item { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .randevu-item:hover, .class-list-item:hover { transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05); border-color: #cbd5e1; }
        .randevu-active, .active-row { background: var(--g-primary-light); border-color: #5eead4; }
        .randevu-personel { font-size: 0.7rem; color: var(--g-muted); text-align: right; flex-shrink: 0; background: #f1f5f9; padding: 2px 8px; border-radius: 12px;}
        
        /* Durum Noktaları ve Badge'ler */
        .status-dot { height: 12px; width: 12px; border-radius: 50%; display: inline-block; margin-right: 10px; flex-shrink: 0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);}
        .status-bekliyor { background-color: #f59e0b; }
        .status-sonuc-iletildi { background-color: #0ea5e9; }
        .status-randevu-alindi { background-color: #10b981; }
        .status-gorusuldu-yuz-yuze { background-color: #7c3aed; }
        .status-gorusuldu-telefon { background-color: #14b8a6; }
        .status-sonuc-icin-ulasilamadi { background-color: #ef4444; }
        .status-gorusme-icin-ulasilamadi { background-color: #e11d48; }
        .status-whatsapp-donus-yapmadi { background-color: #64748b; }
        .status-kayit-oldu { background-color: #059669; }
        .status-kayit-istemiyor { background-color: #374151; }
        .status-ertelendi { background-color: #ea580c; }
        .ogrenci-odeme-yapti { border-left: 4px solid #059669; background: linear-gradient(90deg, rgba(5, 150, 105, 0.08), transparent); border-radius: var(--g-radius-sm); padding: 10px 12px; margin-bottom: 8px; }
        .class-list-item.odeme-yapan-veli { border-left: 3px solid #059669; background: linear-gradient(90deg, rgba(5, 150, 105, 0.06), #fff); font-weight: 600; }
        .legend-box {
            background: #fff; border-radius: var(--g-radius); padding: 12px; margin-bottom: 16px;
            font-size: 0.75rem; border: 1px solid var(--g-border); box-shadow: var(--g-shadow); display: flex; flex-wrap: wrap; gap: 8px;
        }
        .legend-wrap { flex-shrink: 0; margin-bottom: 10px; }
        .legend-toggle {
            width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 6px;
            padding: 6px 10px; font-size: 0.7rem; font-weight: 600; color: var(--g-muted);
            background: #fff; border: 1px solid var(--g-border); border-radius: var(--g-radius-sm);
            cursor: pointer; transition: background 0.2s, color 0.2s; text-align: left;
        }
        .legend-toggle:hover { background: #f8fafc; color: var(--g-primary); }
        .legend-toggle .legend-chevron { transition: transform 0.2s; font-size: 0.65rem; opacity: 0.8; }
        .legend-wrap.open .legend-chevron { transform: rotate(180deg); }
        #legend-box {
            display: flex; flex-direction: column; gap: 8px; margin-top: 6px; padding: 8px 10px;
            background: #fff; border: 1px solid var(--g-border); border-radius: var(--g-radius-sm);
            box-shadow: var(--g-shadow); max-height: 200px; overflow-y: auto;
        }
        #legend-box[hidden] { display: none !important; }
        #legend-box .legend-group { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 6px; }
        #legend-box .legend-group-title {
            font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--g-muted);
            width: 100%; margin-bottom: 2px; padding-bottom: 2px; border-bottom: 1px dashed var(--g-border);
        }
        .legend-box.legend-grouped .status-dot { height: 8px; width: 8px; margin-right: 4px; flex-shrink: 0; }
        .legend-box.legend-grouped .legend-item {
            display: inline-flex; align-items: center; background: #f8fafc; padding: 2px 6px; border-radius: 10px;
            border: 1px solid #e2e8f0; font-weight: 500; cursor: pointer; transition: background 0.2s; font-size: 0.65rem; line-height: 1.25;
        }
        .legend-box.legend-grouped .legend-item:hover { background: #e2e8f0; }
        .legend-box.legend-grouped .legend-item .badge { font-size: 0.6rem; padding: 0 3px; margin-left: 2px; }
        .legend-group { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; }
        .legend-group-title { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--g-muted); width: 100%; margin-bottom: 2px; padding-bottom: 2px; border-bottom: 1px dashed var(--g-border); }
        .legend-item { display: flex; align-items: center; background: #f8fafc; padding: 3px 8px; border-radius: 16px; border: 1px solid #e2e8f0; font-weight: 500; cursor: pointer; transition: background 0.2s; font-size: 0.7rem;}
        .legend-item:hover { background: #e2e8f0; }
        
        /* Notlar Kısmı */
        .not-sekme { border: 1px solid var(--g-border); border-radius: var(--g-radius-sm); margin-bottom: 10px; overflow: hidden; background: #fff;}
        .not-sekme-baslik { padding: 8px 12px; background: #f8fafc; font-weight: 600; display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; border-bottom: 1px solid var(--g-border);}
        .not-sekme-icerik { padding: 12px; font-size: 0.85rem; line-height: 1.5; color: #475569;}
        .not-sekme-meta { padding: 6px 12px; font-size: 0.72rem; color: #64748b; border-top: 1px dashed #e2e8f0; background: #fbfdff; }
        .note-modal-backdrop {
            position: fixed; inset: 0; z-index: 2100;
            background: rgba(2, 8, 23, 0.42);
            display: none; align-items: center; justify-content: center; padding: 16px;
        }
        .note-modal {
            width: min(640px, 100%); background: #fff; border-radius: 14px;
            border: 1px solid #dbe4ee; box-shadow: 0 16px 42px rgba(15, 23, 42, 0.22);
        }
        .note-modal-head { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; }
        .note-modal-body { padding: 12px 14px; }
        .note-modal-foot { padding: 10px 14px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 8px; }
        
        /* Bildirim Paneli & FAB */
        .alert { border-radius: var(--g-radius-sm); border: none; box-shadow: var(--g-shadow); padding: 10px 16px; }
        .odeme-bildirim-kart { border: 1px solid #facc15; background: #fffbeb; margin-bottom: 16px; }
        .odeme-bildirim-kart .card-header { background: #fef3c7; color: #92400e; border-bottom-color: #fde68a; font-weight: 700; padding: 10px 16px; }
        .odeme-bildirim-item { border: 1px solid #fde68a; background: #fffef7; border-radius: var(--g-radius-sm); padding: 10px 12px; margin-bottom: 8px; transition: transform 0.2s;}
        .odeme-bildirim-item:hover { transform: translateY(-1px); box-shadow: 0 2px 4px -1px rgba(250, 204, 21, 0.2); }
        .odeme-bildirim-title { font-weight: 700; color: #92400e; font-size: 0.9rem; margin-bottom: 2px;}
        .odeme-bildirim-meta { font-size: 0.75rem; color: #78350f; opacity: 0.9;}
        
        .odeme-bildirim-toggle {
            position: fixed; right: 24px; bottom: 24px; z-index: 1200; display: flex; flex-direction: column; align-items: center; gap: 8px;
        }
        .odeme-bildirim-toggle-btn {
            width: 60px; height: 60px; border-radius: 50%; border: none;
            background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; font-size: 1.4rem;
            box-shadow: 0 10px 25px rgba(217, 119, 6, 0.4); cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex; align-items: center; justify-content: center;
        }
        .odeme-bildirim-toggle-btn:hover { transform: translateY(-4px) scale(1.05); box-shadow: 0 14px 30px rgba(217, 119, 6, 0.5); }
        .odeme-bildirim-toggle-count {
            font-size: 0.7rem; font-weight: 700; color: #92400e; background: #fef3c7; border: 1px solid #fde68a;
            border-radius: 20px; padding: 4px 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        /* Form Elemanları */
        .btn { border-radius: 8px; font-weight: 500; transition: all 0.2s;}
        .btn-success { background: var(--g-primary); border-color: var(--g-primary); }
        .btn-success:hover { background: var(--g-primary-dark); border-color: var(--g-primary-dark); }
        .btn-primary { background: #3b82f6; border-color: #3b82f6; }
        .btn-primary:hover { background: #2563eb; border-color: #2563eb; }
        .form-select, .form-control { border-radius: 8px; border-color: #cbd5e1; padding: 0.4rem 0.6rem; font-size: 0.875rem;}
        .form-select:focus, .form-control:focus { border-color: var(--g-primary); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
        
        /* Öğrenci Link Bölümü */
        .ogrenci-detay-link { color: var(--g-text); transition: color 0.2s;}
        .ogrenci-detay-link:hover { color: var(--g-primary) !important; text-decoration: none !important; }
        .ogrenci-link-hizli {
            margin-top: 8px; padding: 10px; border: 1px solid #e0e7ff; border-radius: var(--g-radius-sm);
            background: #f8fafc; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);
        }
        .ogrenci-link-hizli .input-group .form-control { font-size: 0.75rem; background: #fff;}
        
        /* Arama ve Filtreler - Kompakt Taraf */
        #arama-sonuclari { border-radius: var(--g-radius-sm); border: 1px solid var(--g-border); box-shadow: var(--g-shadow-hover); position: absolute; top: 100%; left: 0; right: 0; z-index: 1050; background: white; margin-top: 4px; overflow-y: auto; max-height: 250px; }
        #arama-sonuclari:empty { display: none !important; }
        .randevu-filter-box { background: #f8fafc; border: 1px solid var(--g-border); border-radius: var(--g-radius-sm); padding: 12px; margin-bottom: 12px; }
        .btn-filter-toggle { width: 100%; margin-bottom: 12px; font-weight: 600;}
        
        /* Özel Rozetler */
        .badge { padding: 0.35em 0.5em; font-weight: 600; border-radius: 6px; letter-spacing: 0.01em;}
        
        /* Win7 / Klasik Görünüm Modu */
        body.win7-mode {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif !important;
            --g-primary: #005a9e;
            --g-primary-dark: #004578;
            --g-primary-light: #cce4f7;
            --g-bg: #e5e5e5;
            --g-border: #a0a0a0;
            --g-shadow: none !important;
            --g-shadow-hover: none !important;
            --g-radius: 3px !important;
            --g-radius-sm: 2px !important;
        }
        body.win7-mode .card, body.win7-mode .veli-box, body.win7-mode .sidebar-scroll, body.win7-mode .randevu-filter-box, body.win7-mode #arama-sonuclari { border: 1px solid #999; box-shadow: none; border-radius: var(--g-radius); }
        body.win7-mode .top-bar { background: #f0f0f0; border-bottom: 1px solid #ccc; color: #333; }
        body.win7-mode .top-bar .brand, body.win7-mode .top-bar .text-person { color: #333; }
        body.win7-mode .top-bar .btn-outline-light { border-color: #999; color: #333; background: transparent; }
        body.win7-mode .top-bar .btn-outline-light:hover { background: #e0e0e0; }
        body.win7-mode .gorusme-card .card-header { background: #005a9e; color: #fff; }
        body.win7-mode .badge { font-weight: normal; border-radius: 2px; }
        body.win7-mode .form-control, body.win7-mode .form-select, body.win7-mode .btn { border-radius: 3px; }
        body.win7-mode .class-list-item, body.win7-mode .randevu-item { border: 1px solid #ccc; border-radius: 0; }
    </style>
</head>
<body data-current-id="<?= (int)$getir_id ?>">
<div class="top-bar">
    <div class="brand"><i class="bi bi-headset"></i> Görüşmeler <span>— Sınav Sonuç & Randevu</span></div>
    <div class="d-flex align-items-center gap-3">
        <span class="text-person"><i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($aktif_personel) ?></span>
        <a href="gorusmeler_ogrenci_liste.php?<?= htmlspecialchars(http_build_query(['sinif' => ($secilen_sinif !== '' ? $secilen_sinif : 'tum')]), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-light"><i class="bi bi-table me-1"></i> Detay Tablo</a>
		<button type="button" class="btn btn-sm btn-outline-light" onclick="toggleWin7Mode()" id="btn-win7-toggle" title="Görünümü Değiştir">			<i class="bi bi-pc-display"></i> Klasik Tema</button>
        <a href="index.php" class="btn btn-sm btn-outline-light"><i class="bi bi-grid me-1"></i> Panel</a>
        <a href="../logout.php" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right me-1"></i> Çıkış</a>
    </div>
</div>
<div class="container-fluid px-lg-4 px-2">
    <div class="row">
        <!-- SOL SÜTUN: RANDEVULAR -->
        <div class="col-lg-3 mb-3">
            <div class="section-title"><i class="bi bi-calendar3"></i> Randevular</div>
            <?php $r_params = ['sinif' => $secilen_sinif, 'getir_tel' => $bulunan_tel]; if ($r_sinif > 0) $r_params['r_sinif'] = $r_sinif; if ($odeme_filtresi) $r_params['odeme_filtresi'] = '1'; $r_switch = $r_params; $r_switch['gecmis_randevular'] = $gecmis_randevu ? '0' : '1'; ?>
            
            <button type="button" class="btn btn-sm btn-outline-primary btn-filter-toggle" id="btn-randevu-filtre-toggle" data-closed-text="<i class='bi bi-funnel'></i> Filtreleri göster" onclick="toggleCollapse('collapseRandevuFiltre', this)"><i class="bi bi-funnel"></i> Filtreleri göster</button>
            
            <div class="randevu-filter-box" id="collapseRandevuFiltre" style="display:none;">
                <div class="d-flex gap-2 mb-2">
                    <a href="?<?= http_build_query($r_switch) ?>" class="btn btn-sm flex-fill <?= $gecmis_randevu ? 'btn-outline-secondary' : 'btn-primary' ?>"><i class="bi bi-calendar-event"></i> Yaklaşan</a>
                    <a href="?<?= http_build_query($r_switch) ?>" class="btn btn-sm flex-fill <?= $gecmis_randevu ? 'btn-primary' : 'btn-outline-secondary' ?>"><i class="bi bi-clock-history"></i> Geçmiş</a>
                </div>
                <form method="GET" class="mb-0">
                    <input type="hidden" name="sinif" value="<?= htmlspecialchars($secilen_sinif, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="getir_tel" value="<?= htmlspecialchars($bulunan_tel, ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($gecmis_randevu): ?><input type="hidden" name="gecmis_randevular" value="1"><?php endif; ?>
                    <?php if ($odeme_filtresi): ?><input type="hidden" name="odeme_filtresi" value="1"><?php endif; ?>
                    
                    <div class="d-grid gap-2">
                    <?php if (!$gecmis_randevu): ?>
                        <div>
                            <label class="form-label small text-muted mb-1 fw-bold">Tarih</label>
                            <input type="date" name="r_gun" class="form-control form-control-sm" value="<?= htmlspecialchars($r_gun, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1 fw-bold">Saat</label>
                            <select name="r_saat" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">Tümü</option>
                                <?php for ($h = 8; $h <= 20; $h++): foreach ([0, 30] as $m): $v = sprintf('%02d:%02d', $h, $m); ?>
                                <option value="<?= $v ?>" <?= $r_saat === $v ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; endfor; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($randevu_filtre_siniflar)): ?>
                        <div>
                            <label class="form-label small text-muted mb-1 fw-bold">Sınıf</label>
                            <select name="r_sinif" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">Tüm Sınıflar</option>
                                <?php foreach ($randevu_filtre_siniflar as $sinifNo): ?>
                                <option value="<?= (int)$sinifNo ?>" <?= $r_sinif == $sinifNo ? 'selected' : '' ?>><?= (int)$sinifNo ?>. Sınıf</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-outline-primary mt-1 w-100"><i class="bi bi-check2-all"></i> Uygula</button>
                    </div>
                </form>
            </div>
            
            <div class="sidebar-scroll" id="scroll-randevular">
                <?php if ($res_randevular && mysqli_num_rows($res_randevular) > 0): while ($rand = mysqli_fetch_assoc($res_randevular)): $is_active = ($bulunan_tel === ($rand['tel_temiz'] ?? '')) ? 'randevu-active' : ''; ?>
                <div class="randevu-item <?= $is_active ?>" data-tel="<?= htmlspecialchars($rand['tel_temiz'] ?? '', ENT_QUOTES, 'UTF-8') ?>" onclick="window.location.href='?sinif=<?= urlencode($secilen_sinif) ?>&getir_tel=<?= urlencode($rand['tel_temiz'] ?? '') ?><?= $odeme_filtresi ? '&odeme_filtresi=1' : '' ?><?= $gecmis_randevu ? '&gecmis_randevular=1' : '' ?><?= $r_sinif > 0 ? '&r_sinif='.$r_sinif : '' ?><?= !$gecmis_randevu && $r_gun !== '' ? '&r_gun='.urlencode($r_gun) : '' ?><?= !$gecmis_randevu && $r_saat !== '' ? '&r_saat='.urlencode($r_saat) : '' ?>'">
                    <div style="flex: 1; min-width: 0;">
                        <div class="fw-bold text-truncate text-dark mb-1" style="font-size: 0.85rem;"><?= htmlspecialchars(trim(($rand['veli_ad'] ?? '').' '.($rand['veli_soyad'] ?? ''))) ?></div>
                        <?php if (!empty($rand['ogrenciler'])): ?>
                        <div class="small text-muted text-truncate mb-1" style="font-size: 0.75rem;" title="<?= htmlspecialchars($rand['ogrenciler']) ?>"><i class="bi bi-mortarboard me-1"></i> <?= htmlspecialchars($rand['ogrenciler']) ?></div>
                        <?php endif; ?>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-danger fw-semibold" style="font-size: 0.75rem;"><i class="bi bi-clock me-1"></i> <?= !empty($rand['randevu_tarihi']) ? date("d.m.Y H:i", strtotime($rand['randevu_tarihi'])) : '—' ?></span>
                            <?php $rd = $rand['randevu_durumu'] ?? 'Bekleniyor'; $rd_label = ['Bekleniyor' => 'Bekleniyor', 'Geldi' => 'Geldi', 'Gelmedi' => 'Gelmedi']; $rd_class = ['Bekleniyor' => 'bg-warning text-dark', 'Geldi' => 'bg-success', 'Gelmedi' => 'bg-danger']; ?>
                            <span class="badge <?= $rd_class[$rd] ?? 'bg-secondary' ?>" style="font-size: 0.6rem;"><?= $rd_label[$rd] ?? $rd ?></span>
                        </div>
                    </div>
                    <?php
                        $olusturan = trim((string)($rand['personel'] ?? ''));
                        $sorumlu = trim((string)($rand['randevu_sorumlusu'] ?? ''));
                        $ayni = ($sorumlu !== '' && $olusturan !== '' && $sorumlu === $olusturan);
                    ?>
                    <?php if ($olusturan !== '' || $sorumlu !== ''): ?>
                    <div class="randevu-personel mt-1" title="<?= $ayni ? 'Sorumlu / Oluşturan' : 'Randevu bilgisi' ?>">
                        <?php if ($ayni): ?><span><i class="bi bi-person"></i> S/O <?= htmlspecialchars($sorumlu) ?></span>
                        <?php else: ?>
                        <?php if ($sorumlu !== ''): ?><span><i class="bi bi-person-check"></i> S: <?= htmlspecialchars($sorumlu) ?></span><?php endif; ?>
                        <?php if ($olusturan !== ''): ?><span class="<?= $sorumlu !== '' ? 'ms-2' : '' ?>"><i class="bi bi-person"></i> O: <?= htmlspecialchars($olusturan) ?></span><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endwhile; else: ?>
                    <div class="text-center text-muted mt-4"><i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-50"></i><span class="small">Kayıtlı randevu yok.</span></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ORTA SÜTUN: DETAYLAR & AKSİYONLAR (Kompaktlaştırıldı) -->
        <div class="col-lg-6 mb-3">
            
            <!-- Arama & Filtreleme Kompakt Kartı -->
            <div class="card mb-3 position-relative" style="overflow: visible; z-index: 10;">
                <div class="card-body p-2 px-3">
                    <div class="d-flex flex-column flex-md-row gap-2 align-items-md-center">
                        <form method="GET" class="mb-0 d-flex flex-wrap align-items-center gap-3 flex-grow-1">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-funnel text-secondary"></i>
                                <select name="sinif" class="form-select form-select-sm w-auto shadow-sm" onchange="this.form.submit()">
                                    <option value="tum" <?= $secilen_sinif === 'tum' ? 'selected' : '' ?>>Tüm Sınıflar</option>
                                    <option value="" <?= $secilen_sinif === '' ? 'selected' : '' ?>>Sınıf Seçiniz</option>
                                    <?php foreach ($sinif_dropdown_opts as $o): ?>
                                    <option value="<?= htmlspecialchars($o['value']) ?>" <?= $secilen_sinif === $o['value'] ? 'selected' : '' ?>><?= htmlspecialchars($o['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" role="switch" value="1" id="odeme_filtresi" name="odeme_filtresi" <?= $odeme_filtresi ? 'checked' : '' ?> onchange="this.form.submit()">
                                <label class="form-check-label small fw-medium text-nowrap" for="odeme_filtresi">Sadece Ödeyenler</label>
                            </div>
                        </form>
                        <div class="input-group input-group-sm flex-grow-1" style="max-width: 320px;">
                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" id="genelArama" class="form-control border-start-0 ps-0 shadow-sm" placeholder="Veli, Öğrenci veya Tel Ara..." oninput="aramaYapSmart(this.value)">
                        </div>
                    </div>
                    <!-- Arama Sonuçları Overlay -->
                    <div id="arama-sonuclari"></div>
                </div>
            </div>

            <?php if ($mesaj): ?>
                <div class="alert alert-<?= $mesajTur ?> d-flex align-items-center py-2 px-3 mb-3 shadow-sm">
                    <i class="bi <?= $mesajTur === 'success' ? 'bi-check-circle-fill text-success' : ($mesajTur === 'danger' ? 'bi-exclamation-triangle-fill text-danger' : 'bi-info-circle-fill text-info') ?> fs-5 me-2"></i>
                    <div>
                        <div class="small fw-bold"><?= $mesaj ?></div>
                        <?php if ($mesajDetay !== ''): ?>
                            <div class="small mt-1 text-muted font-monospace opacity-75"><code><?= htmlspecialchars($mesajDetay) ?></code></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($odeme_bildirimleri)): ?>
            <div class="card mb-3 odeme-bildirim-kart" id="odeme-bildirim-paneli">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bell-fill text-warning me-2"></i> Ödeme Sonrası Bildirimler</span>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge rounded-pill bg-warning text-dark px-2" id="odeme-bildirim-badge"><?= count($odeme_bildirimleri) ?> Bekleyen</span>
                        <button type="button" class="btn btn-sm btn-link text-dark p-0" onclick="setOdemeBildirimPanelOpen(false)"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
                <div class="card-body p-2">
                    <?php foreach ($odeme_bildirimleri as $ob):
                        $ob_tel = (string)($ob['tel_temiz'] ?? '');
                        $ob_link = 'gorusmeler.php?sinif=' . urlencode($secilen_sinif) . '&getir_tel=' . urlencode($ob_tel) . ($odeme_filtresi ? '&odeme_filtresi=1' : '');
                    ?>
                    <div class="odeme-bildirim-item d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2" data-notify-tel="<?= htmlspecialchars($ob_tel, ENT_QUOTES, 'UTF-8') ?>">
                        <div style="min-width:0; flex:1;">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <div class="odeme-bildirim-title text-truncate m-0"><i class="bi bi-person-badge me-1"></i> <?= htmlspecialchars(trim(($ob['veli_ad'] ?? '') . ' ' . ($ob['veli_soyad'] ?? ''))) ?></div>
                                <?php if (!empty($ob['ogrenciler'])): ?>
                                    <div class="small text-muted text-truncate" style="font-size:0.7rem;" title="<?= htmlspecialchars($ob['ogrenciler']) ?>">(<?= htmlspecialchars($ob['ogrenciler']) ?>)</div>
                                <?php endif; ?>
                            </div>
                            <div class="odeme-bildirim-meta d-flex gap-3">
                                <span><i class="bi bi-credit-card"></i> Son Ödeme: <strong class="text-dark"><?= !empty($ob['son_odeme_tarihi']) ? date('d.m.Y H:i', strtotime($ob['son_odeme_tarihi'])) : '—' ?></strong></span>
                                <?php if (!empty($ob['son_islem_tarihi'])): ?>
                                    <span><i class="bi bi-arrow-repeat"></i> Güncelleme: <?= date('d.m.Y H:i', strtotime($ob['son_islem_tarihi'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex flex-row gap-1">
                            <a href="<?= htmlspecialchars($ob_link, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-warning py-1 px-2 fw-bold" title="Kayda Git"><i class="bi bi-box-arrow-in-right"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="hideOdemeBildirim(this)" title="Gizle"><i class="bi bi-eye-slash"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($aile_verisi)):
                $v = $aile_verisi[0];
                $eski_durum = $v['gorusme_durumu'] ?? 'Bekliyor';
                $son_degisiklik = !empty($v['islem_tarihi']) ? $v['islem_tarihi'] : null;
                $son_degisiklik_kisi = $v['personel'] ?? '';
                $son_personel_telefon = '';
                if ($son_degisiklik_kisi !== '') {
                    $pad_esc = mysqli_real_escape_string($conn, $son_degisiklik_kisi);
                    $r_tel = @mysqli_query($conn, "SELECT telefon FROM kullanicilar WHERE ad_soyad = '$pad_esc' LIMIT 1");
                    if ($r_tel && mysqli_num_rows($r_tel) > 0) {
                        $row_tel = mysqli_fetch_assoc($r_tel);
                        $tel_raw = trim((string)($row_tel['telefon'] ?? ''));
                        if ($tel_raw !== '') {
                            $son_personel_telefon = preg_replace('/[^0-9]/', '', $tel_raw);
                            if (strlen($son_personel_telefon) > 0 && $son_personel_telefon[0] === '0') $son_personel_telefon = substr($son_personel_telefon, 1);
                        }
                    }
                }
            ?>
            <div class="veli-box py-3 px-4">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle"><i class="bi bi-person-vcard me-1"></i> Veli</span>
                            <?php if ($son_degisiklik_kisi !== '' || $son_degisiklik): ?>
                            <span class="small text-muted" style="font-size: 0.7rem;"><i class="bi bi-info-circle"></i> Son işlem: <strong><?= htmlspecialchars($son_degisiklik_kisi) ?></strong> <?= $son_degisiklik ? ' · ' . date('d.m.Y H:i', strtotime($son_degisiklik)) : '' ?></span>
                            <?php endif; ?>
                        </div>
                        <h5 class="mb-0 fw-bold"><?= htmlspecialchars(trim($v['veli_ad'].' '.$v['veli_soyad'])) ?></h5>
                    </div>
                    <a href="tel:<?= htmlspecialchars($v['tel_temiz']) ?>" class="btn btn-sm btn-light border shadow-sm fw-bold px-3 py-2 text-primary"><i class="bi bi-telephone-fill me-1"></i> <?= htmlspecialchars($v['tel_orijinal'] ?? $v['tel_temiz']) ?></a>
                </div>
            </div>

            <div class="card mb-3 shadow-sm border-0">
                <div class="card-header py-2 d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-pencil-square text-primary me-2"></i> Bilgi Düzenleme</span>
                    <button type="button" class="btn btn-sm btn-primary py-0 px-2 fw-bold" onclick="toggleCollapse('collapseBilgiDuzenle', this)" data-closed-text="<i class='bi bi-pencil'></i> Düzenle"><i class="bi bi-pencil"></i> Düzenle</button>
                </div>
                <div class="card-body p-3 bg-light" id="collapseBilgiDuzenle" style="display:none;">
                    <form method="POST" class="m-0">
                        <input type="hidden" name="islem_tel" value="<?= htmlspecialchars((string)($v['tel_temiz'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="sinif_id" value="<?= htmlspecialchars($secilen_sinif, ENT_QUOTES, 'UTF-8') ?>">
                        <?php if ($odeme_filtresi): ?><input type="hidden" name="odeme_filtresi" value="1"><?php endif; ?>
                        <div class="border rounded p-2 bg-white mb-3">
                            <div class="small fw-bold text-muted mb-2"><i class="bi bi-person-vcard me-1"></i>Veli Bilgileri (tüm çocuklara uygulanır)</div>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label small mb-1">Veli adı</label>
                                    <input type="text" name="veli_ad" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($v['veli_ad'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small mb-1">Veli soyadı</label>
                                    <input type="text" name="veli_soyad" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($v['veli_soyad'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small mb-1">Telefon</label>
                                    <input type="text" name="veli_tel" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($v['tel_orijinal'] ?? $v['tel_temiz'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="border rounded p-2 bg-white">
                            <div class="small fw-bold text-muted mb-2"><i class="bi bi-mortarboard me-1"></i>Öğrenci Bilgileri</div>
                            <?php foreach ($aile_verisi as $i => $og_duzen): $oid_duzen = (int)($og_duzen['id'] ?? 0); ?>
                            <input type="hidden" name="ogrenci_ids[]" value="<?= $oid_duzen ?>">
                            <div class="row g-2 align-items-end <?= $i > 0 ? 'mt-1 pt-2 border-top' : '' ?>">
                                <div class="col-md-5">
                                    <label class="form-label small mb-1">Öğrenci adı</label>
                                    <input type="text" name="ogrenci_ad[<?= $oid_duzen ?>]" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($og_duzen['ogrenci_ad'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small mb-1">Öğrenci soyadı</label>
                                    <input type="text" name="ogrenci_soyad[<?= $oid_duzen ?>]" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($og_duzen['ogrenci_soyad'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <div class="small text-muted mb-1">Sınıf</div>
                                    <div class="badge bg-light text-dark border w-100"><?= (int)($og_duzen['sinif'] ?? 0) ?>. Sınıf</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-end mt-3">
                            <button type="submit" name="btn_bilgiler_guncelle" class="btn btn-sm btn-primary px-4 fw-bold"><i class="bi bi-check2-circle me-1"></i> Bilgileri Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mb-3 shadow-sm">
                <div class="card-header py-2 d-flex align-items-center"><i class="bi bi-mortarboard-fill text-primary me-2"></i> Öğrenci & Sınav Bilgileri</div>
                <div class="card-body p-3">
                    <?php
                    $geri_link = 'gorusmeler.php?sinif=' . urlencode($secilen_sinif) . '&getir_tel=' . urlencode($bulunan_tel);
                    foreach ($aile_verisi as $index => $og):
                        $detay_url = 'gorusme_ogrenci_detay.php?id=' . (int)$og['id'] . '&geri=' . urlencode($geri_link);
                        $og_ad = trim($og['ogrenci_ad'] . ' ' . $og['ogrenci_soyad']);
                        $oid = (int)$og['id'];
                        $teklif_hizli = teklif_v2_get_latest_by_gorusme($conn, $oid);
                        $teklif_hizli_link = '';
                        if ($teklif_hizli && !empty($teklif_hizli['paylasim_token'])) {
                            $ad_param = $og_ad !== '' ? '&ad=' . rawurlencode($og_ad) : '';
                            $teklif_hizli_link = 'https://genclikdil.com/sonuclar-aciklama.php?t=' . $teklif_hizli['paylasim_token'] . $ad_param;
                        }
                        $wp_tel_hizli = preg_replace('/[^0-9]/', '', (string)($og['tel_temiz'] ?? $og['tel_orijinal'] ?? ''));
                        if ($wp_tel_hizli !== '' && substr($wp_tel_hizli, 0, 1) === '0') $wp_tel_hizli = '90' . substr($wp_tel_hizli, 1);
                        if ($wp_tel_hizli !== '' && strlen($wp_tel_hizli) === 10) $wp_tel_hizli = '90' . $wp_tel_hizli;
                        $sira_metin = isset($sira_by_id[$oid], $toplam_by_id[$oid]) ? ' · <strong>' . $sira_by_id[$oid] . '. sıra</strong> / ' . $toplam_by_id[$oid] : '';
                        $eng_var = (int)($og['sinav_sonuc_id_ingilizce'] ?? 0) > 0;
                        $alm_var = (int)($og['sinav_sonuc_id_almanca'] ?? 0) > 0;
                        $sinav_etiketleri = [];
                        if ($eng_var) $sinav_etiketleri[] = 'İngilizce';
                        if ($alm_var) $sinav_etiketleri[] = 'Almanca';
                        if (empty($sinav_etiketleri)) {
                            $sinav_turu_metin = trim((string)($og['sinav_turu'] ?? ''));
                            if ($sinav_turu_metin !== '') $sinav_etiketleri[] = $sinav_turu_metin;
                        }
                        if (empty($sinav_etiketleri)) $sinav_etiketleri[] = 'İngilizce';
                        $basari_metin = null;
                        if (isset($og['basari_yuzdesi_ingilizce']) && $og['basari_yuzdesi_ingilizce'] !== null && $og['basari_yuzdesi_ingilizce'] !== '') {
                            $basari_metin = number_format((float)$og['basari_yuzdesi_ingilizce'], 1, ',', '') . '%';
                        } elseif (isset($og['basari_yuzdesi_almanca']) && $og['basari_yuzdesi_almanca'] !== null && $og['basari_yuzdesi_almanca'] !== '') {
                            $basari_metin = number_format((float)$og['basari_yuzdesi_almanca'], 1, ',', '') . '%';
                        } elseif (isset($og['basari_yuzdesi']) && $og['basari_yuzdesi'] !== null && $og['basari_yuzdesi'] !== '') {
                            $basari_metin = number_format((float)$og['basari_yuzdesi'], 1, ',', '') . '%';
                        }
                    ?>
                    <div class="<?= $index > 0 ? 'mt-3 pt-3 border-top' : 'mb-1' ?> <?= in_array($oid, $odeme_yapan_ogrenci_ids, true) ? 'ogrenci-odeme-yapti' : '' ?>">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <a href="<?= htmlspecialchars($detay_url, ENT_QUOTES, 'UTF-8') ?>" class="ogrenci-detay-link fw-bold text-dark fs-6"><i class="bi bi-person-fill text-muted me-1"></i><?= htmlspecialchars($og_ad) ?></a>
                            <?php if (in_array($oid, $odeme_yapan_ogrenci_ids, true)): ?><span class="badge bg-success text-white"><i class="bi bi-credit-card-fill me-1"></i> Ödeme yaptı</span><?php endif; ?>
                            <span class="badge bg-light text-dark border"><i class="bi bi-bookmark-fill text-primary opacity-75"></i> <?= (int)$og['sinif'] ?>. Sınıf</span>
                            <span class="badge bg-light text-dark border"><i class="bi bi-journal-text text-primary opacity-75"></i> <?= htmlspecialchars(implode(' + ', $sinav_etiketleri)) ?></span>
                            <?php if($basari_metin !== null): ?>
                                <span class="badge bg-success text-white"><i class="bi bi-graph-up-arrow"></i> Başarı: <?= $basari_metin ?></span>
                            <?php endif; ?>
                            <span class="text-muted" style="font-size: 0.75rem;"><?= $sira_metin ?></span>
                        </div>
                        <div class="ogrenci-link-hizli d-flex flex-column gap-2">
                            <?php if ($teklif_hizli_link === ''): ?>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="ogrenci_id" value="<?= (int)$oid ?>">
                                    <input type="hidden" name="islem_tel" value="<?= htmlspecialchars((string)($v['tel_temiz'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="sinif_id" value="<?= htmlspecialchars($secilen_sinif, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php if ($odeme_filtresi): ?><input type="hidden" name="odeme_filtresi" value="1"><?php endif; ?>
                                    <button type="submit" name="btn_hizli_link_olustur" class="btn btn-sm btn-primary py-1"><i class="bi bi-link-45deg"></i> Sonuç linki oluştur</button>
                                </form>
                            <?php else: ?>
                                <?php $mesaj_hizli = "Sayın Veli,\n" . $og_ad . " için bursluluk sınav sonuç ve kayıt bilgilendirme bağlantınız hazırdır:\n" . $teklif_hizli_link . "\nİnceledikten sonra dilerseniz birlikte kısa bir değerlendirme yapabiliriz"; ?>
                                <div class="d-flex flex-column flex-sm-row gap-2 align-items-start">
                                    <div class="input-group input-group-sm flex-grow-1 shadow-sm">
                                        <span class="input-group-text bg-white" title="Sonuç Bağlantısı"><i class="bi bi-link-45deg text-primary"></i></span>
                                        <input type="text" class="form-control font-monospace bg-white" id="hizli-link-<?= (int)$oid ?>" value="<?= htmlspecialchars($teklif_hizli_link, ENT_QUOTES, 'UTF-8') ?>" readonly>
                                        <button type="button" class="btn btn-outline-primary px-2" onclick="hizliKopyala('hizli-link-<?= (int)$oid ?>', this)" title="Linki Kopyala"><i class="bi bi-clipboard"></i></button>
                                    </div>
                                    <div class="d-flex gap-1 w-100 w-sm-auto">
                                        <button type="button" class="btn btn-sm btn-outline-secondary shadow-sm flex-fill" onclick="hizliMesajKopyala('hizli-mesaj-<?= (int)$oid ?>', this)"><i class="bi bi-clipboard-check"></i> Mesajı Kopyala</button>
                                        <?php if ($wp_tel_hizli !== ''): ?>
                                            <button type="button" class="btn btn-sm shadow-sm flex-fill" style="background-color: #25D366; color:#fff; border:none;" onclick="hizliWpGonder('<?= htmlspecialchars($wp_tel_hizli, ENT_QUOTES, 'UTF-8') ?>','hizli-mesaj-<?= (int)$oid ?>')"><i class="bi bi-whatsapp"></i> WP Gönder</button>
                                        <?php else: ?>
                                            <span class="btn btn-sm btn-secondary disabled flex-fill" title="Veli telefonu yok"><i class="bi bi-whatsapp"></i> WP Gönder</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <textarea class="form-control form-control-sm d-none" id="hizli-mesaj-<?= (int)$oid ?>"><?= htmlspecialchars($mesaj_hizli, ENT_QUOTES, 'UTF-8') ?></textarea>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Genel Görüşme Durumu (Kompakt Tek Satır) -->
            <div class="card gorusme-card mb-3 shadow-sm border-0">
                <div class="card-body p-2 px-3 bg-white rounded" style="border-left: 4px solid var(--g-primary);">
                    <form method="POST" class="m-0">
                        <input type="hidden" name="islem_tel" value="<?= htmlspecialchars($v['tel_temiz'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="sinif_id" value="<?= htmlspecialchars($secilen_sinif, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <div class="fw-bold text-dark fs-6 d-flex align-items-center gap-1"><i class="bi bi-ui-checks"></i> Genel Durum:</div>
                            <select name="durum" id="gorusme-durum-select" class="form-select form-select-sm w-auto flex-grow-1 shadow-sm font-weight-bold">
                                <optgroup label="—— İlerleme ——">
                                    <option value="Bekliyor" <?= $eski_durum==='Bekliyor'?'selected':'' ?>>⏳ Bekliyor</option>
                                    <option value="Sonuc Iletildi" <?= $eski_durum==='Sonuc Iletildi'?'selected':'' ?>>📩 Sonuç İletildi</option>
                                    <option value="Randevu Alindi" <?= $eski_durum==='Randevu Alindi'?'selected':'' ?>>📅 Randevu Alındı</option>
                                </optgroup>
                                <optgroup label="—— Görüşülenler ——">
                                    <option value="Gorusuldu (Yuz Yuze)" <?= $eski_durum==='Gorusuldu (Yuz Yuze)'?'selected':'' ?>>✅ Yüz Yüze</option>
                                    <option value="Gorusuldu (Telefon)" <?= $eski_durum==='Gorusuldu (Telefon)'?'selected':'' ?>>📞 Telefon</option>
                                </optgroup>
                                <optgroup label="—— Ulaşılamayanlar ——">
                                    <option value="Sonuc Icin Ulasilamadi" <?= $eski_durum==='Sonuc Icin Ulasilamadi'?'selected':'' ?>>❌ Sonuç için</option>
                                    <option value="Gorusme Icin Ulasilamadi" <?= $eski_durum==='Gorusme Icin Ulasilamadi'?'selected':'' ?>>📵 Görüşme için</option>
                                    <option value="WhatsappDonusYapmadi" <?= $eski_durum==='WhatsappDonusYapmadi'?'selected':'' ?>>📵 WhatsApp dönüş yok</option>
                                </optgroup>
                                <optgroup label="—— Sonuç ——">
                                    <option value="KayitOldu" <?= $eski_durum==='KayitOldu'?'selected':'' ?>>📝 Kayıt oldu</option>
                                    <option value="KayitOlmakIstemiyor" <?= $eski_durum==='KayitOlmakIstemiyor'?'selected':'' ?>>🚫 Kayıt istemiyor</option>
                                </optgroup>
                                <optgroup label="—— Diğer ——">
                                    <option value="Ertelendi" <?= $eski_durum==='Ertelendi'?'selected':'' ?>>⏸️ Ertelendi</option>
                                </optgroup>
                            </select>
                            <button type="submit" name="btn_kaydet" class="btn btn-sm btn-success px-4 shadow-sm fw-bold"><i class="bi bi-save me-1"></i> Kaydet</button>
                        </div>
                        <div id="kayit-istemiyor-neden-wrap" class="mt-3 pt-3 border-top" style="display:<?= $eski_durum === 'KayitOlmakIstemiyor' ? 'block' : 'none' ?>;">
                            <label class="form-label small fw-bold text-muted mb-2">Neden?</label>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <?php
                                $mevcut_neden = $v['kayit_istememe_nedeni'] ?? '';
                                foreach ($kayit_istememe_nedenleri as $kod => $etiket):
                                ?><label class="form-check form-check-inline mb-0">
                                    <input type="radio" name="kayit_istememe_nedeni" value="<?= htmlspecialchars($kod, ENT_QUOTES, 'UTF-8') ?>" class="form-check-input" <?= $mevcut_neden === $kod ? 'checked' : '' ?>>
                                    <span class="form-check-label small"><?= htmlspecialchars($etiket) ?></span>
                                </label><?php endforeach; ?>
                            </div>
                            <label class="form-label small fw-bold text-muted mb-1">Açıklama / Başka neden (isteğe bağlı)</label>
                            <textarea name="kayit_istememe_not" class="form-control form-control-sm" rows="2" placeholder="Seçilen neden veya başka bir gerekçe yazabilirsiniz..."><?= htmlspecialchars($v['kayit_istememe_not'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($randevulari_var): ?>
            <div class="card mb-3 shadow-sm">
                <div class="card-header py-2 d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-calendar-check text-success me-2"></i> Randevu İşlemleri</span>
                    <button type="button" class="btn btn-sm btn-success py-0 px-2 fw-bold" onclick="toggleCollapse('collapseYeniRandevu', this)" data-closed-text="<i class='bi bi-plus'></i> Ekle"><i class="bi bi-plus"></i> Ekle</button>
                </div>
                <div class="card-body p-3">
                    <!-- Yeni Randevu Formu (Açılır Kapanır) -->
                    <div id="collapseYeniRandevu" style="display:none;" class="mb-3">
                        <div class="p-2 bg-light border border-success border-opacity-25 rounded shadow-sm">
                            <form method="POST" id="form-randevu-ekle" class="m-0">
                                <input type="hidden" name="islem_tel" value="<?= htmlspecialchars($v['tel_temiz'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="row g-2 align-items-center">
                                    <div class="col-sm-3">
                                        <input type="date" name="randevu_tarih" id="randevuTarih" class="form-control form-control-sm" required>
                                    </div>
                                    <div class="col-sm-2">
                                        <select name="randevu_saat" id="randevuSaat" class="form-select form-select-sm">
                                            <?php for ($h = 8; $h <= 20; $h++): for ($m = 0; $m < 60; $m += 30): ?>
                                            <option value="<?= sprintf('%02d:%02d', $h, $m) ?>"><?= sprintf('%02d:%02d', $h, $m) ?></option>
                                            <?php endfor; endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-sm-3">
                                        <select name="randevu_sorumlusu" class="form-select form-select-sm" title="Randevu sorumlusu (seçilmezse siz)">
                                            <option value="">— Sorumlu (Varsayılan: <?= htmlspecialchars($aktif_personel, ENT_QUOTES, 'UTF-8') ?>) —</option>
                                            <?php foreach ($kullanici_isimleri as $adsoyad): ?>
                                            <option value="<?= htmlspecialchars($adsoyad, ENT_QUOTES, 'UTF-8') ?>" <?= $adsoyad === $aktif_personel ? 'selected' : '' ?>><?= htmlspecialchars($adsoyad) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-sm-2">
                                        <button type="submit" name="btn_randevu_ekle" class="btn btn-success btn-sm w-100 fw-bold">Oluştur</button>
                                    </div>
                                    <div class="col-sm-2 text-sm-end">
                                        <span class="small text-muted" style="font-size:0.7rem;"><i class="bi bi-info-circle"></i> <strong id="randevuSlotSayi">0</strong> kişi</span>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if (!empty($secili_randevular)): ?>
                    <form method="POST" class="m-0">
                        <input type="hidden" name="islem_tel" value="<?= htmlspecialchars($v['tel_temiz'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php foreach ($secili_randevular as $sr): $rid = (int)$sr['id']; $rd = $sr['randevu_durumu'] ?? 'Bekleniyor'; $rn = $sr['randevu_notu'] ?? ''; ?>
                        <div class="border border-light-subtle rounded p-2 mb-2 bg-white shadow-sm">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                                <div class="text-primary fw-bold" style="font-size: 0.85rem;"><i class="bi bi-calendar2-event me-1"></i> <?= date('d.m.Y H:i', strtotime($sr['randevu_tarihi'])) ?></div>
                                <div class="d-flex align-items-center gap-1">
                                    <select name="randevu_durum[<?= $rid ?>]" class="form-select form-select-sm py-0" style="width: auto; min-width: 100px; font-size: 0.8rem;">
                                        <option value="Bekleniyor" <?= $rd === 'Bekleniyor' ? 'selected' : '' ?>>⏳ Bekleniyor</option>
                                        <option value="Geldi" <?= $rd === 'Geldi' ? 'selected' : '' ?>>✅ Geldi</option>
                                        <option value="Gelmedi" <?= $rd === 'Gelmedi' ? 'selected' : '' ?>>❌ Gelmedi</option>
                                    </select>
                                    <a href="?sinif=<?= urlencode($secilen_sinif) ?>&getir_tel=<?= urlencode($bulunan_tel) ?>&sil_randevu=<?= $rid ?>" class="btn btn-sm btn-outline-danger py-0 px-1 border-0" title="Sil" onclick="return confirm('Silmek istediğinize emin misiniz?');"><i class="bi bi-trash3"></i></a>
                                </div>
                            </div>
                            <?php
                                $sr_sorumlu = trim((string)($sr['randevu_sorumlusu'] ?? ''));
                                $sr_olusturan = trim((string)($sr['personel'] ?? ''));
                                $sr_ayni = ($sr_sorumlu !== '' && $sr_olusturan !== '' && $sr_sorumlu === $sr_olusturan);
                                $sorumlu_opts = $kullanici_isimleri;
                                if ($sr_sorumlu !== '' && !in_array($sr_sorumlu, $sorumlu_opts, true)) array_unshift($sorumlu_opts, $sr_sorumlu);
                            ?>
                            <?php if ($randevu_sorumlusu_kolon_var): ?>
                            <div class="small mt-1 d-flex flex-wrap align-items-center gap-2" style="font-size: 0.72rem;">
                                <span class="text-muted">Sorumlu:</span>
                                <select name="randevu_sorumlu[<?= $rid ?>]" class="form-select form-select-sm py-0" style="width: auto; min-width: 120px; font-size: 0.75rem;" title="Randevu sorumlusu">
                                    <?php foreach ($sorumlu_opts as $adsoyad): ?>
                                    <option value="<?= htmlspecialchars($adsoyad, ENT_QUOTES, 'UTF-8') ?>" <?= $sr_sorumlu === $adsoyad ? 'selected' : '' ?>><?= htmlspecialchars($adsoyad) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($sr_olusturan !== ''): ?><span class="text-muted" title="Oluşturan"><?= $sr_ayni ? 'S/O ' : 'O: ' ?><?= htmlspecialchars($sr_olusturan) ?></span><?php endif; ?>
                            </div>
                            <?php elseif ($sr_olusturan !== '' || $sr_sorumlu !== ''): ?>
                            <div class="small text-muted mt-1" style="font-size: 0.72rem;">
                                <?= $sr_ayni ? 'S/O ' . htmlspecialchars($sr_sorumlu) : (($sr_sorumlu !== '' ? 'S: ' . htmlspecialchars($sr_sorumlu) . ' ' : '') . ($sr_olusturan !== '' ? 'O: ' . htmlspecialchars($sr_olusturan) : '')) ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($randevu_notu_kolon_var): ?>
                            <textarea name="randevu_not[<?= $rid ?>]" class="form-control form-control-sm bg-light border-0 shadow-none mt-1" style="font-size: 0.8rem; resize: vertical; min-height: 34px;" rows="2" placeholder="Randevu notu..."><?= htmlspecialchars($rn) ?></textarea>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <div class="text-end mt-2">
                            <button type="submit" name="btn_randevu_guncelle" class="btn btn-primary btn-sm px-3 fw-bold"><i class="bi bi-check2"></i> Kaydet</button>
                        </div>
                    </form>
                    <?php else: ?>
                        <div class="text-center text-muted p-2 bg-light rounded small"><i class="bi bi-calendar-x me-1"></i> Randevu bulunmuyor.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card mb-3 shadow-sm">
                <div class="card-header py-2 d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-journal-bookmark-fill text-warning me-2"></i> Görüşme Notları</span>
                    <button type="button" class="btn btn-sm btn-primary py-0 px-2 fw-bold" onclick="toggleCollapse('collapseYeniNot', this)" data-closed-text="<i class='bi bi-plus'></i> Ekle"><i class="bi bi-plus"></i> Ekle</button>
                </div>
                <div class="card-body p-3 bg-light" id="notlar-container">
                    
                    <div id="collapseYeniNot" style="display:none;" class="mb-3">
                        <div class="border rounded p-2 bg-white shadow-sm border-primary border-opacity-25">
                            <form id="form-yeni-not" class="yeni-not-form m-0">
                                <div class="row g-2">
                                    <div class="col-sm-4">
                                        <input type="text" name="baslik" class="form-control form-control-sm" placeholder="Başlık" value="Görüşme notu" maxlength="200">
                                    </div>
                                    <div class="col-sm-8">
                                        <div class="input-group input-group-sm">
                                            <textarea name="icerik" class="form-control" rows="1" placeholder="İçerik..."></textarea>
                                            <button type="submit" class="btn btn-primary px-3 fw-bold"><i class="bi bi-send"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php foreach ($secili_notlar as $not): ?>
                    <?php
                        $not_created_ts = !empty($not['created_at']) ? strtotime((string)$not['created_at']) : false;
                        $not_updated_ts = !empty($not['updated_at']) ? strtotime((string)$not['updated_at']) : false;
                        $not_created_fmt = $not_created_ts ? date('d.m.Y H:i', $not_created_ts) : '';
                        $not_updated_fmt = $not_updated_ts ? date('d.m.Y H:i', $not_updated_ts) : '';
                        $not_updated_diff = ($not_created_ts && $not_updated_ts) ? (abs($not_updated_ts - $not_created_ts) > 1) : false;
                    ?>
                    <div class="not-sekme shadow-sm" data-not-id="<?= (int)$not['id'] ?>" data-baslik="<?= htmlspecialchars($not['baslik'], ENT_QUOTES, 'UTF-8') ?>" data-icerik="<?= htmlspecialchars($not['icerik'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <div class="not-sekme-baslik">
                            <span class="not-baslik-text text-primary"><i class="bi bi-sticky me-1"></i> <?= htmlspecialchars($not['baslik']) ?></span>
                            <span class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-light border text-secondary py-0 px-1" onclick="duzenleNot(this.closest('.not-sekme'))" title="Düzenle"><i class="bi bi-pencil"></i></button>
                                <button type="button" class="btn btn-sm btn-light border text-danger py-0 px-1" onclick="silNot(<?= (int)$not['id'] ?>)" title="Sil"><i class="bi bi-trash"></i></button>
                            </span>
                        </div>
                        <div class="not-sekme-icerik bg-white"><?= nl2br(htmlspecialchars($not['icerik'] ?? '')) ?></div>
                        <?php if ($not_created_fmt || $not_updated_fmt): ?>
                        <div class="not-sekme-meta">
                            <?php if ($not_created_fmt): ?><span><i class="bi bi-clock-history me-1"></i>Oluşturulma: <?= htmlspecialchars($not_created_fmt) ?></span><?php endif; ?>
                            <?php if ($not_updated_fmt && $not_updated_diff): ?><span class="ms-2"><i class="bi bi-pencil-square me-1"></i>Düzenleme: <?= htmlspecialchars($not_updated_fmt) ?></span><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($secili_notlar)): ?>
                        <div class="text-center text-muted p-2 bg-white rounded border border-dashed small"><i class="bi bi-journal-x me-1"></i> Not eklenmemiş.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="note-modal-backdrop" id="note-edit-modal-backdrop">
                <div class="note-modal" role="dialog" aria-modal="true" aria-labelledby="note-edit-title">
                    <div class="note-modal-head">
                        <strong id="note-edit-title"><i class="bi bi-pencil-square me-2 text-primary"></i>Not Düzenle</strong>
                        <button type="button" class="btn btn-sm btn-light border" onclick="closeNotEditModal()" title="Kapat"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <form id="form-duzenle-not">
                        <div class="note-modal-body">
                            <input type="hidden" name="id" id="edit-not-id" value="">
                            <div class="mb-2">
                                <label class="form-label form-label-sm mb-1">Başlık</label>
                                <input type="text" class="form-control form-control-sm" id="edit-not-baslik" name="baslik" maxlength="200" required>
                            </div>
                            <div>
                                <label class="form-label form-label-sm mb-1">İçerik</label>
                                <textarea class="form-control form-control-sm" id="edit-not-icerik" name="icerik" rows="6" required></textarea>
                            </div>
                        </div>
                        <div class="note-modal-foot">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeNotEditModal()">Vazgeç</button>
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php elseif ($secilen_sinif_aktif): ?>
            <div class="card mb-4 shadow-sm border-0"><div class="card-body text-center py-5 my-4 text-muted"><i class="bi bi-arrow-right-circle text-primary fs-1 d-block mb-3 opacity-50"></i> Detayları görüntülemek için sağ taraftaki listeden bir veli seçin.</div></div>
            <?php else: ?>
            <div class="card mb-4 shadow-sm border-0"><div class="card-body text-center py-5 my-4 text-muted"><i class="bi bi-funnel text-primary fs-1 d-block mb-3 opacity-50"></i> Lütfen işlem yapmak için yukarıdan bir sınıf filtresi seçin.</div></div>
            <?php endif; ?>
        </div>

        <!-- SAĞ SÜTUN: LİSTE -->
        <div class="col-lg-3 mb-3" id="sidebar-sinif">
            <div class="section-title"><i class="bi bi-list-ul"></i> Sınıf Listesi</div>
            <div class="legend-wrap">
                <button type="button" class="legend-toggle" id="legend-toggle" onclick="toggleLegend()" aria-expanded="false" title="Durum rehberini aç/kapat">
                    <i class="bi bi-palette"></i> <span>Durum rehberi</span> <i class="bi bi-chevron-down legend-chevron" id="legend-chevron"></i>
                </button>
                <div class="legend-box legend-grouped" id="legend-box" hidden>
                    <?php foreach ($durum_gruplari_legend as $grup_baslik => $durumlar): ?>
                    <div class="legend-group">
                        <div class="legend-group-title"><?= htmlspecialchars($grup_baslik) ?></div>
                        <?php foreach ($durumlar as $lbl): $adet = $sinif_durum_adet[$lbl] ?? null; $suf = ($secilen_sinif_aktif && $adet !== null) ? " <span class='badge bg-light text-dark border'>$adet</span>" : ""; $cls = $durum_renk_sinifi[$lbl] ?? 'status-bekliyor'; ?>
                        <div class="legend-item" onclick="scrollToStatus('<?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?>')"><span class="status-dot <?= $cls ?>"></span><?= htmlspecialchars($durum_etiket[$lbl] ?? $lbl) ?><?= $suf ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="sidebar-scroll bg-light" id="scroll-liste">
                <?php if ($secilen_sinif_aktif && $res_sinif_list && mysqli_num_rows($res_sinif_list) > 0): while ($row = mysqli_fetch_assoc($res_sinif_list)): $act = ($bulunan_tel === ($row['tel_temiz'] ?? '')) ? 'active-row shadow-sm' : ''; $durum_cls = $durum_renk_sinifi[$row['gorusme_durumu'] ?? ''] ?? 'status-bekliyor'; ?>
                <?php $tel_odeme_yapti = in_array($row['tel_temiz'] ?? '', $odeme_yapan_tel_list, true); ?>
                <div class="class-list-item d-flex align-items-center <?= $act ?> <?= $tel_odeme_yapti ? 'odeme-yapan-veli' : '' ?>" data-status="<?= htmlspecialchars($row['gorusme_durumu'] ?? 'Bekliyor', ENT_QUOTES, 'UTF-8') ?>" onclick="location.href='?sinif=<?= urlencode($secilen_sinif) ?>&getir_tel=<?= urlencode($row['tel_temiz'] ?? '') ?><?= $odeme_filtresi ? '&odeme_filtresi=1' : '' ?>'">
                    <span class="status-dot <?= $durum_cls ?> flex-shrink-0"></span>
                    <?php if ($tel_odeme_yapti): ?><span class="flex-shrink-0 me-1" title="Ödeme yapan veli"><i class="bi bi-credit-card-fill text-success" style="font-size: 0.75rem;"></i></span><?php endif; ?>
                    <div style="min-width: 0;">
                        <span class="text-truncate d-block fw-bold text-dark mb-1" style="font-size: 0.85rem;"><?= htmlspecialchars(trim(($row['veli_ad'] ?? '').' '.($row['veli_soyad'] ?? ''))) ?></span>
                        <?php if (!empty($row['ogrenciler'])): ?>
                        <span class="small text-muted d-block text-truncate" style="font-size: 0.75rem;" title="<?= htmlspecialchars($row['ogrenciler']) ?>"><i class="bi bi-mortarboard me-1"></i> <?= htmlspecialchars($row['ogrenciler']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; else: ?>
                    <div class="text-center text-muted mt-5"><i class="bi bi-people display-6 d-block mb-3 text-light"></i><span class="small">Lütfen sınıf seçin veya arama yapın.</span></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($odeme_bildirimleri)): ?>
<div class="odeme-bildirim-toggle" id="odeme-bildirim-toggle">
    <button type="button" class="odeme-bildirim-toggle-btn" onclick="toggleOdemeBildirimPanel()" title="Ödeme Bildirimleri">
        <i class="bi bi-bell-fill position-relative">
            <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle" style="width: 12px; height: 12px;"><span class="visually-hidden">Yeni bildirimler</span></span>
        </i>
    </button>
    <div class="odeme-bildirim-toggle-count" id="odeme-bildirim-toggle-count"><?= count($odeme_bildirimleri) ?> Bekleyen</div>
</div>
<?php endif; ?>

<script>
var gorusmeId = <?= (int)$not_gorusme_id ?>; // Notlar ilk öğrenci satırına bağlı
var SCROLL_KEY = 'gorusmeler_scroll';
var GORUSMELER_STATE_KEY = 'gorusmeler_state';
var RANDEVU_FILTER_COLLAPSE_KEY = 'gorusmeler_randevu_filter_open';
var ODEME_BILDIRIM_HIDE_KEY = 'gorusmeler_odeme_bildirim_hidden_tels';
var ODEME_BILDIRIM_PANEL_OPEN_KEY = 'gorusmeler_odeme_bildirim_panel_open';
var WIN7_MODE_KEY = 'gorusmeler_win7_mode';
var LEGEND_OPEN_KEY = 'gorusmeler_legend_open';

function toggleLegend() {
    var box = document.getElementById('legend-box');
    var wrap = box && box.closest('.legend-wrap');
    if (!box || !wrap) return;
    var isOpen = !box.hidden;
    box.hidden = isOpen;
    wrap.classList.toggle('open', !isOpen);
    var btn = document.getElementById('legend-toggle');
    if (btn) btn.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
    try { localStorage.setItem(LEGEND_OPEN_KEY, !isOpen ? '1' : '0'); } catch (e) {}
}

function initLegend() {
    var box = document.getElementById('legend-box');
    var wrap = box && box.closest('.legend-wrap');
    if (!box || !wrap) return;
    try {
        if (localStorage.getItem(LEGEND_OPEN_KEY) === '1') {
            box.hidden = false;
            wrap.classList.add('open');
            var btn = document.getElementById('legend-toggle');
            if (btn) btn.setAttribute('aria-expanded', 'true');
        }
    } catch (e) {}
}

function toggleWin7Mode() {
    var isWin7 = document.body.classList.toggle('win7-mode');
    try { localStorage.setItem(WIN7_MODE_KEY, isWin7 ? '1' : '0'); } catch (e) {}
    var btn = document.getElementById('btn-win7-toggle');
    if (btn) {
        btn.innerHTML = isWin7 ? '<i class="bi bi-stars"></i> Modern Tema' : '<i class="bi bi-pc-display"></i> Klasik Tema';
    }
}

function initWin7Mode() {
    try {
        if (localStorage.getItem(WIN7_MODE_KEY) === '1') {
            document.body.classList.add('win7-mode');
            var btn = document.getElementById('btn-win7-toggle');
            if (btn) btn.innerHTML = '<i class="bi bi-stars"></i> Modern Tema';
        }
    } catch (e) {}
}

function scrollToStatus(status) {
    var listContainer = document.getElementById('scroll-liste');
    if (!listContainer) return;
    var target = listContainer.querySelector('.class-list-item[data-status="' + status + '"]');
    if (target) {
        var containerRect = listContainer.getBoundingClientRect();
        var targetRect = target.getBoundingClientRect();
        listContainer.scrollBy({
            top: targetRect.top - containerRect.top - 10,
            behavior: 'smooth'
        });
        
        target.style.transition = 'background-color 0.5s ease';
        var originalBg = target.style.backgroundColor;
        target.style.backgroundColor = '#fef08a';
        setTimeout(function() {
            target.style.backgroundColor = originalBg;
            setTimeout(function() { target.style.transition = ''; }, 500);
        }, 1000);
    }
}

function saveGorusmelerState() {
    try {
        var params = new URLSearchParams(window.location.search);
        sessionStorage.setItem(GORUSMELER_STATE_KEY, JSON.stringify({
            sinif: params.get('sinif') || '',
            gecmis_randevular: params.get('gecmis_randevular') || '0',
            r_sinif: params.get('r_sinif') || '0',
            getir_tel: params.get('getir_tel') || '',
            odeme_filtresi: params.get('odeme_filtresi') || '0'
        }));
    } catch (e) {}
}

function toggleCollapse(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    var isHidden = el.style.display === 'none';
    el.style.display = isHidden ? 'block' : 'none';
    if (btn) {
        var closedText = btn.getAttribute('data-closed-text') || "<i class='bi bi-plus'></i> Ekle";
        btn.innerHTML = isHidden ? "<i class='bi bi-dash'></i> Gizle" : closedText;
    }
    if (id === 'collapseRandevuFiltre') {
        try { localStorage.setItem(RANDEVU_FILTER_COLLAPSE_KEY, isHidden ? '1' : '0'); } catch (e) {}
    }
}

function toggleKayitIstemiyorNeden() {
    var sel = document.getElementById('gorusme-durum-select');
    var wrap = document.getElementById('kayit-istemiyor-neden-wrap');
    if (sel && wrap) wrap.style.display = (sel.value === 'KayitOlmakIstemiyor') ? 'block' : 'none';
}
function initRandevuFilterCollapse() {
    var panel = document.getElementById('collapseRandevuFiltre');
    var btn = document.getElementById('btn-randevu-filtre-toggle');
    if (!panel || !btn) return;
    var isOpen = false; // varsayılan: kapalı
    try {
        var raw = localStorage.getItem(RANDEVU_FILTER_COLLAPSE_KEY);
        if (raw === '1') isOpen = true;
    } catch (e) {}
    panel.style.display = isOpen ? 'block' : 'none';
    var closedText = btn.getAttribute('data-closed-text') || "<i class='bi bi-funnel'></i> Filtreleri göster";
    btn.innerHTML = isOpen ? "<i class='bi bi-funnel-fill'></i> Filtreleri gizle" : closedText;
}

function hizliKopyala(inputId, btn) {
    var el = document.getElementById(inputId);
    if (!el) return;
    navigator.clipboard.writeText(String(el.value || '')).then(function() {
        if (!btn) return;
        var oldHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i>';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-outline-primary');
        setTimeout(function() { btn.innerHTML = oldHtml; btn.classList.remove('btn-success'); btn.classList.add('btn-outline-primary'); }, 1500);
    }).catch(function() {
        alert('Kopyalama başarısız.');
    });
}

function hizliMesajKopyala(textareaId, btn) {
    var el = document.getElementById(textareaId);
    if (!el) return;
    navigator.clipboard.writeText(String(el.value || '')).then(function() {
        if (!btn) return;
        var oldHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i> Kopyalandı!';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-outline-secondary');
        setTimeout(function() { btn.innerHTML = oldHtml; btn.classList.remove('btn-success'); btn.classList.add('btn-outline-secondary'); }, 1500);
    }).catch(function() {
        alert('Kopyalama başarısız.');
    });
}

function hizliWpGonder(tel, textareaId) {
    var el = document.getElementById(textareaId);
    if (!el) return;
    var txt = String(el.value || '');
    if (!txt) {
        alert('Mesaj içeriği boş olamaz.');
        return;
    }
    var url = 'https://wa.me/' + encodeURIComponent(String(tel || '')) + '?text=' + encodeURIComponent(txt);
    window.open(url, '_blank', 'noopener');
}

// Akıllı arama (veli / öğrenci)
var aramaTimer = null;
function aramaYapSmart(val) {
    if (aramaTimer) clearTimeout(aramaTimer);
    var term = (val || '').trim();
    var cont = document.getElementById('arama-sonuclari');
    if (!cont) return;
    if (term.length < 2) { cont.innerHTML = ''; return; }
    aramaTimer = setTimeout(function() { aramaYap(term); }, 300);
}
function aramaYap(term) {
    var cont = document.getElementById('arama-sonuclari');
    if (!cont) return;
    cont.innerHTML = '<div class="p-3 text-center text-muted"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Aranıyor...</div>';
    var params = new URLSearchParams();
    params.append('arama_q', term);
    fetch(window.location.pathname + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.text(); })
        .then(function(html) { cont.innerHTML = html; })
        .catch(function() { cont.innerHTML = '<div class="p-2 text-danger small">Hata oluştu.</div>'; });
}
function aramaKaydaGit(tel) {
    if (!tel) return;
    saveScrollPositions();
    saveGorusmelerState();
    var sinifSelect = document.querySelector('select[name="sinif"]');
    var secilenSinif = sinifSelect ? sinifSelect.value : '';
    var odemeFiltresi = document.getElementById('odeme_filtresi');
    var q = 'gorusmeler.php?sinif=' + encodeURIComponent(secilenSinif) + '&getir_tel=' + encodeURIComponent(tel);
    if (odemeFiltresi && odemeFiltresi.checked) q += '&odeme_filtresi=1';
    window.location.href = q;
}

function randevuSlotGuncelle() {
    var tarihEl = document.getElementById('randevuTarih');
    var saatEl = document.getElementById('randevuSaat');
    var sayEl = document.getElementById('randevuSlotSayi');
    if (!tarihEl || !saatEl || !sayEl) return;
    var tarih = tarihEl.value;
    var saat = saatEl.value;
    if (!tarih || !saat) { sayEl.textContent = '0'; return; }
    fetch('ajax_randevu_say.php?tarih=' + encodeURIComponent(tarih) + '&saat=' + encodeURIComponent(saat))
        .then(function(r) { return r.json(); })
        .then(function(d) { sayEl.textContent = d.ok ? d.count : 0; });
}
function saveScrollPositions() {
    try {
        var payload = {
            main: window.scrollY || document.documentElement.scrollTop,
            randevu: (document.getElementById('scroll-randevular') || {}).scrollTop || 0,
            liste: (document.getElementById('scroll-liste') || {}).scrollTop || 0
        };
        sessionStorage.setItem(SCROLL_KEY, JSON.stringify(payload));
    } catch (e) {}
}

function restoreScrollPositions() {
    try {
        var raw = sessionStorage.getItem(SCROLL_KEY);
        if (!raw) return;
        var payload = JSON.parse(raw);
        if (payload.main > 0) window.scrollTo({top: payload.main, behavior: 'instant'});
        var elR = document.getElementById('scroll-randevular');
        var elL = document.getElementById('scroll-liste');
        if (elR && payload.randevu > 0) elR.scrollTop = payload.randevu;
        if (elL && payload.liste > 0) elL.scrollTop = payload.liste;
    } catch (e) {}
}

function getHiddenOdemeBildirimTels() {
    try {
        var raw = localStorage.getItem(ODEME_BILDIRIM_HIDE_KEY);
        if (!raw) return [];
        var list = JSON.parse(raw);
        return Array.isArray(list) ? list : [];
    } catch (e) {
        return [];
    }
}

function setHiddenOdemeBildirimTels(list) {
    try {
        localStorage.setItem(ODEME_BILDIRIM_HIDE_KEY, JSON.stringify(list || []));
    } catch (e) {}
}

function updateOdemeBildirimPanelVisibility() {
    var panel = document.getElementById('odeme-bildirim-paneli');
    var badge = document.getElementById('odeme-bildirim-badge');
    var miniCount = document.getElementById('odeme-bildirim-toggle-count');
    if (!panel) return 0;
    var items = panel.querySelectorAll('.odeme-bildirim-item');
    var visibleCount = 0;
    for (var i = 0; i < items.length; i++) {
        if (items[i].style.display !== 'none') visibleCount++;
    }
    if (badge) badge.innerHTML = visibleCount + ' Bekleyen';
    if (miniCount) miniCount.textContent = visibleCount + ' Bekleyen';
    return visibleCount;
}

function getOdemeBildirimPanelOpen() {
    try {
        var raw = localStorage.getItem(ODEME_BILDIRIM_PANEL_OPEN_KEY);
        if (raw === null) return true;
        return raw === '1';
    } catch (e) {
        return true;
    }
}

function setOdemeBildirimPanelOpen(open) {
    var panel = document.getElementById('odeme-bildirim-paneli');
    if (!panel) return;
    var visibleCount = updateOdemeBildirimPanelVisibility();
    panel.style.display = (open && visibleCount > 0) ? '' : 'none';
    try { localStorage.setItem(ODEME_BILDIRIM_PANEL_OPEN_KEY, open ? '1' : '0'); } catch (e) {}
}

function toggleOdemeBildirimPanel() {
    var panel = document.getElementById('odeme-bildirim-paneli');
    if (!panel) return;
    var isOpen = panel.style.display !== 'none';
    setOdemeBildirimPanelOpen(!isOpen);
}

function applyHiddenOdemeBildirimler() {
    var hiddenTels = getHiddenOdemeBildirimTels();
    var items = document.querySelectorAll('.odeme-bildirim-item[data-notify-tel]');
    var hiddenMap = {};
    for (var i = 0; i < hiddenTels.length; i++) hiddenMap[String(hiddenTels[i])] = true;
    for (var j = 0; j < items.length; j++) {
        var tel = items[j].getAttribute('data-notify-tel') || '';
        items[j].style.display = hiddenMap[tel] ? 'none' : '';
    }
    updateOdemeBildirimPanelVisibility();
    setOdemeBildirimPanelOpen(getOdemeBildirimPanelOpen());
}

function hideOdemeBildirim(btn) {
    var item = btn && btn.closest ? btn.closest('.odeme-bildirim-item') : null;
    if (!item) return;
    var tel = item.getAttribute('data-notify-tel') || '';
    if (tel !== '') {
        var hidden = getHiddenOdemeBildirimTels();
        if (hidden.indexOf(tel) === -1) {
            hidden.push(tel);
            setHiddenOdemeBildirimTels(hidden);
        }
    }
    item.style.display = 'none';
    updateOdemeBildirimPanelVisibility();
    setOdemeBildirimPanelOpen(getOdemeBildirimPanelOpen());
}

var scrollSaveTimer;
function onScrollSave() {
    clearTimeout(scrollSaveTimer);
    scrollSaveTimer = setTimeout(saveScrollPositions, 150);
}

function postNotAction(formData) {
    return fetch('ajax_gorusme_not.php', { method: 'POST', body: formData })
        .then(function (r) { return r.json(); });
}

var formYeniNot = document.getElementById('form-yeni-not');
if (formYeniNot) {
    formYeniNot.addEventListener('submit', function (e) {
        e.preventDefault();
        var f = this;
        var fd = new FormData();
        fd.append('action', 'ekle');
        fd.append('gorusme_listesi_id', gorusmeId);
        fd.append('baslik', (f.baslik.value || '').trim() || 'Görüşme notu');
        fd.append('icerik', (f.icerik.value || '').trim());
        fd.append('sira', 0);
        postNotAction(fd).then(function (res) {
            if (res.ok) location.reload();
            else alert(res.mesaj || 'Hata oluştu');
        });
    });
}

function openNotEditModal(id, baslik, icerik) {
    var backdrop = document.getElementById('note-edit-modal-backdrop');
    var idEl = document.getElementById('edit-not-id');
    var baslikEl = document.getElementById('edit-not-baslik');
    var icerikEl = document.getElementById('edit-not-icerik');
    if (!backdrop || !idEl || !baslikEl || !icerikEl) return;
    idEl.value = String(id || '');
    baslikEl.value = String(baslik || '');
    icerikEl.value = String(icerik || '');
    backdrop.style.display = 'flex';
    setTimeout(function() { baslikEl.focus(); }, 30);
}

function closeNotEditModal() {
    var backdrop = document.getElementById('note-edit-modal-backdrop');
    if (!backdrop) return;
    backdrop.style.display = 'none';
}

function duzenleNot(el) {
    if (!el) return;
    var id = el.getAttribute('data-not-id');
    var baslik = el.getAttribute('data-baslik') || '';
    var icerik = el.getAttribute('data-icerik') || '';
    openNotEditModal(id, baslik, icerik);
}

function silNot(id){
    if(!confirm('Bu notu silmek istediğinize emin misiniz?')) return;
    var fd = new FormData();
    fd.append('action','sil');
    fd.append('gorusme_listesi_id',gorusmeId);
    fd.append('id',id);
    postNotAction(fd).then(function(res){
        if(res.ok) location.reload();
        else alert(res.mesaj || 'Silme işlemi başarısız');
    });
}

var noteModalBackdrop = document.getElementById('note-edit-modal-backdrop');
if (noteModalBackdrop) {
    noteModalBackdrop.addEventListener('click', function (e) {
        if (e.target === noteModalBackdrop) closeNotEditModal();
    });
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeNotEditModal();
});

var formDuzenleNot = document.getElementById('form-duzenle-not');
if (formDuzenleNot) {
    formDuzenleNot.addEventListener('submit', function (e) {
        e.preventDefault();
        var idEl = document.getElementById('edit-not-id');
        var baslikEl = document.getElementById('edit-not-baslik');
        var icerikEl = document.getElementById('edit-not-icerik');
        var fd = new FormData();
        fd.append('action', 'guncelle');
        fd.append('gorusme_listesi_id', gorusmeId);
        fd.append('id', (idEl && idEl.value) ? idEl.value : '');
        fd.append('baslik', (baslikEl && baslikEl.value ? baslikEl.value : '').trim());
        fd.append('icerik', (icerikEl && icerikEl.value ? icerikEl.value : '').trim());
        postNotAction(fd).then(function (res) {
            if (res.ok) {
                closeNotEditModal();
                location.reload();
            } else {
                alert(res.mesaj || 'Güncelleme başarısız');
            }
        });
    });
}

window.addEventListener('scroll', onScrollSave, true);
var elDurumSelect = document.getElementById('gorusme-durum-select');
if (elDurumSelect) { elDurumSelect.addEventListener('change', toggleKayitIstemiyorNeden); }

initLegend();
window.addEventListener('load', function() {
    initWin7Mode();
    saveGorusmelerState();
    initRandevuFilterCollapse();
    toggleKayitIstemiyorNeden();
    applyHiddenOdemeBildirimler();
    var params = new URLSearchParams(window.location.search);
    var sinif = params.get('sinif');
    if ((sinif === null || sinif === '') && window.location.search.indexOf('sinif=') === -1) {
        try {
            var saved = sessionStorage.getItem(GORUSMELER_STATE_KEY);
            if (saved) {
                var st = JSON.parse(saved);
                if (st.sinif && st.sinif !== '0') {
                    var q = 'gorusmeler.php?sinif=' + encodeURIComponent(st.sinif);
                    if (st.gecmis_randevular === '1') q += '&gecmis_randevular=1';
                    if (st.r_sinif && st.r_sinif !== '0') q += '&r_sinif=' + encodeURIComponent(st.r_sinif);
                    if (st.getir_tel) q += '&getir_tel=' + encodeURIComponent(st.getir_tel);
                    if (st.odeme_filtresi === '1') q += '&odeme_filtresi=1';
                    window.location.replace(q);
                    return;
                }
            }
        } catch (e) {}
    }
    setTimeout(restoreScrollPositions, 50);
    if (document.getElementById('randevuTarih')) {
        var t = new Date(); document.getElementById('randevuTarih').value = t.getFullYear() + '-' + String(t.getMonth()+1).padStart(2,'0') + '-' + String(t.getDate()).padStart(2,'0');
        randevuSlotGuncelle();
        document.getElementById('randevuTarih').addEventListener('change', randevuSlotGuncelle);
        document.getElementById('randevuSaat').addEventListener('change', randevuSlotGuncelle);
    }
});
var elRandevu = document.getElementById('scroll-randevular');
var elListe = document.getElementById('scroll-liste');
if (elRandevu) { elRandevu.addEventListener('scroll', onScrollSave); elRandevu.addEventListener('click', function() { saveScrollPositions(); saveGorusmelerState(); }, true); }
if (elListe) { elListe.addEventListener('scroll', onScrollSave); elListe.addEventListener('click', function() { saveScrollPositions(); saveGorusmelerState(); }, true); }
</script>
</body>
</html>