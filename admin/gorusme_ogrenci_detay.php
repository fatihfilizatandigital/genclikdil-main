<?php
/**
 * Görüşmeler – tek öğrenci detayı (gorusme_listesi satırı).
 * Görüşmeler sayfasından öğrenci ismine tıklanınca açılır.
 * Sınav bilgileri sinav_sonuclari'den (sinav_sonuc_id) gelir; ileride fiyat tablosu eklenecek.
 */
require_once __DIR__ . '/auth_gorusmeler.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/personel_log.php';
require_once __DIR__ . '/../config/teklif_v2.php';
require_once __DIR__ . '/../config/sonuc_fiyat_hesap.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$geri_url = isset($_GET['geri']) ? $_GET['geri'] : 'gorusmeler.php';

$row = null;
$sinav_row = null;
$sinav_row_ing = null;
$sinav_row_alm = null;
$sinif_ici_sira = null;
$sinif_ici_toplam = null;

if ($id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM gorusme_listesi WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res);
    }
}

if (!$row) {
    header('Location: ' . (strpos($geri_url, 'gorusmeler.php') !== false ? $geri_url : 'gorusmeler.php'));
    exit;
}

// İdare indirimi sadece kadi "admin" veya "yonetim" olan kullanıcılarda görünsün
$kadi = trim($_SESSION['kadi'] ?? '');
if ($kadi === '' && ($personel_adi = trim($_SESSION['personel_adi'] ?? '')) !== '') {
    $st_kadi = mysqli_prepare($conn, "SELECT kadi FROM kullanicilar WHERE ad_soyad = ? LIMIT 1");
    if ($st_kadi) {
        mysqli_stmt_bind_param($st_kadi, "s", $personel_adi);
        mysqli_stmt_execute($st_kadi);
        $res_kadi = mysqli_stmt_get_result($st_kadi);
        if ($res_kadi && $r_kadi = mysqli_fetch_assoc($res_kadi)) {
            $kadi = trim($r_kadi['kadi'] ?? '');
            $_SESSION['kadi'] = $kadi;
        }
        mysqli_stmt_close($st_kadi);
    }
}
$eldan_idare_visible = in_array(strtolower($kadi), ['admin', 'yonetim'], true);

$sinav_sonuc_id_ingilizce = isset($row['sinav_sonuc_id_ingilizce']) && $row['sinav_sonuc_id_ingilizce'] !== '' ? (int)$row['sinav_sonuc_id_ingilizce'] : null;
$sinav_sonuc_id_almanca = isset($row['sinav_sonuc_id_almanca']) && $row['sinav_sonuc_id_almanca'] !== '' ? (int)$row['sinav_sonuc_id_almanca'] : null;
$sinav_sonuc_id_legacy = isset($row['sinav_sonuc_id']) && $row['sinav_sonuc_id'] !== '' ? (int)$row['sinav_sonuc_id'] : null;
$sinav_turu_legacy = mb_strtolower(trim((string)($row['sinav_turu'] ?? '')), 'UTF-8');
if (($sinav_sonuc_id_ingilizce === null || $sinav_sonuc_id_ingilizce <= 0) && $sinav_sonuc_id_legacy > 0 && strpos($sinav_turu_legacy, 'ingilizce') !== false) {
    $sinav_sonuc_id_ingilizce = $sinav_sonuc_id_legacy;
}
if (($sinav_sonuc_id_almanca === null || $sinav_sonuc_id_almanca <= 0) && $sinav_sonuc_id_legacy > 0 && strpos($sinav_turu_legacy, 'almanca') !== false) {
    $sinav_sonuc_id_almanca = $sinav_sonuc_id_legacy;
}
if (($sinav_sonuc_id_ingilizce === null || $sinav_sonuc_id_ingilizce <= 0) && ($sinav_sonuc_id_almanca === null || $sinav_sonuc_id_almanca <= 0) && $sinav_sonuc_id_legacy > 0) {
    $sinav_sonuc_id_ingilizce = $sinav_sonuc_id_legacy;
}
$sinav_sonuc_id = ($sinav_sonuc_id_ingilizce !== null && $sinav_sonuc_id_ingilizce > 0)
    ? $sinav_sonuc_id_ingilizce
    : (($sinav_sonuc_id_almanca !== null && $sinav_sonuc_id_almanca > 0) ? $sinav_sonuc_id_almanca : null);

$fetch_sinav_by_id = static function(mysqli $conn, ?int $sid): ?array {
    if ($sid === null || $sid <= 0) return null;
    $stmt = mysqli_prepare($conn, "SELECT * FROM sinav_sonuclari WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "i", $sid);
    mysqli_stmt_execute($stmt);
    $res_ss = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    return ($res_ss && mysqli_num_rows($res_ss) > 0) ? mysqli_fetch_assoc($res_ss) : null;
};
$sinav_row_ing = $fetch_sinav_by_id($conn, $sinav_sonuc_id_ingilizce);
$sinav_row_alm = $fetch_sinav_by_id($conn, $sinav_sonuc_id_almanca);
$sinav_row = $sinav_row_ing ?: $sinav_row_alm;

$sinif_val = (int)($row['sinif'] ?? 0);
$lise_siniflar = [9, 10, 11, 12];
$is_lise = in_array($sinif_val, $lise_siniflar, true);
$sinav_turu_val = $sinav_row_ing ? 'İngilizce' : ($sinav_row_alm ? 'Almanca' : ($row['sinav_turu'] ?? null));
if ($sinav_turu_val === '') $sinav_turu_val = null;

$dc = @mysqli_query($conn, "SHOW COLUMNS FROM sinav_sonuclari LIKE 'dogum_tarihi'");
$has_dogum = $dc && mysqli_num_rows($dc) > 0;

// Sınıf içi sıra: sinav_sonuclari üzerinden hesapla (sonuclar.php / gorusmeler.php ile aynı ORDER BY: dogum_tarihi + sinav_turu null)
$rank_from_sinav = static function(mysqli $conn, int $sinif_val, bool $is_lise, int $sinav_sonuc_id, ?string $sinav_turu_val, array $lise_siniflar, bool $has_dogum_col): array {
    if ($sinav_sonuc_id <= 0) return ['sira' => null, 'toplam' => null];
    $sinav_turu_lower = mb_strtolower(trim((string)($sinav_turu_val ?? '')), 'UTF-8');
    $almanca_grup = ($sinav_turu_lower !== '' && strpos($sinav_turu_lower, 'almanca') !== false);
    $rank_sql = "SELECT id FROM sinav_sonuclari WHERE ";
    $bind_params = [];
    $bind_types = '';
    if ($almanca_grup) {
        if (in_array($sinif_val, [2, 3, 4], true)) $rank_sql .= "sinif IN (2,3,4)";
        elseif (in_array($sinif_val, [5, 6, 7, 8], true)) $rank_sql .= "sinif IN (5,6,7,8)";
        elseif (in_array($sinif_val, $lise_siniflar, true)) $rank_sql .= "sinif IN (9,10,11,12)";
        else { $rank_sql .= "sinif = ?"; $bind_params[] = $sinif_val; $bind_types .= 'i'; }
    } else {
        if ($is_lise) $rank_sql .= "sinif IN (9,10,11,12)";
        else { $rank_sql .= "sinif = ?"; $bind_params[] = $sinif_val; $bind_types .= 'i'; }
    }
    if ($sinav_turu_val !== null && $sinav_turu_val !== '') {
        $rank_sql .= " AND (sinav_turu <=> ?)";
        $bind_params[] = $sinav_turu_val;
        $bind_types .= 's';
    } else {
        $rank_sql .= " AND (sinav_turu IS NULL OR sinav_turu = '')";
    }
    $rank_sql .= " ORDER BY basari_yuzdesi DESC, toplam_yanlis ASC";
    if ($has_dogum_col) $rank_sql .= ", (CASE WHEN dogum_tarihi IS NULL THEN 1 ELSE 0 END) ASC, dogum_tarihi DESC";
    $rank_sql .= ", id ASC";
    $st = mysqli_prepare($conn, $rank_sql);
    if (!$st) return ['sira' => null, 'toplam' => null];
    if ($bind_types !== '') {
        if ($bind_types === 'i') mysqli_stmt_bind_param($st, 'i', $bind_params[0]);
        elseif ($bind_types === 's') mysqli_stmt_bind_param($st, 's', $bind_params[0]);
        else mysqli_stmt_bind_param($st, 'is', $bind_params[0], $bind_params[1]);
    }
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    mysqli_stmt_close($st);
    $sira = null;
    $rank = 0;
    if ($rs) {
        while ($rr = mysqli_fetch_assoc($rs)) {
            $rank++;
            if ((int)$rr['id'] === (int)$sinav_sonuc_id) $sira = $rank;
        }
    }
    return ['sira' => $sira, 'toplam' => $rank > 0 ? $rank : null];
};

if ($sinif_val > 0) {
    $sid_ing = $sinav_sonuc_id_ingilizce ?? 0;
    $sid_alm = $sinav_sonuc_id_almanca ?? 0;
    if ($sinav_row_ing && $sid_ing > 0) {
        $r = $rank_from_sinav($conn, $sinif_val, $is_lise, $sid_ing, 'İngilizce', $lise_siniflar, $has_dogum);
        if ($r['sira'] !== null) {
            $sinif_ici_sira = $r['sira'];
            $sinif_ici_toplam = $r['toplam'];
        }
    }
    if ($sinif_ici_sira === null && $sinav_row_alm && $sid_alm > 0) {
        $r = $rank_from_sinav($conn, $sinif_val, $is_lise, $sid_alm, 'Almanca', $lise_siniflar, $has_dogum);
        if ($r['sira'] !== null) {
            $sinif_ici_sira = $r['sira'];
            $sinif_ici_toplam = $r['toplam'];
        }
    }
    // Yüzdelik dilim (sonuclar.php ile aynı: toplam*2 üzerinden, en üst %X = 100 - yuzdelik)
    $sinif_ici_yuzdelik_dilim = null;
    if ($sinif_ici_sira !== null && $sinif_ici_toplam !== null && $sinif_ici_toplam > 0) {
        $toplam_eff = (int)$sinif_ici_toplam * 2;
        $yuzdelik = ($toplam_eff - (int)($sinif_ici_sira) + 1) / $toplam_eff * 100;
        $sinif_ici_yuzdelik_dilim = round(100 - $yuzdelik, 1);
    }
    // Fallback: gorusme_listesi üzerinden sıra
    if ($sinif_ici_sira === null) {
        $st = null;
        if ($is_lise) {
            $rank_sql = $sinav_turu_val !== null
                ? "SELECT id FROM gorusme_listesi WHERE sinif IN (9,10,11,12) AND (sinav_turu <=> ?) ORDER BY basari_yuzdesi DESC, id ASC"
                : "SELECT id FROM gorusme_listesi WHERE sinif IN (9,10,11,12) AND (sinav_turu IS NULL OR sinav_turu = '') ORDER BY basari_yuzdesi DESC, id ASC";
            $st = mysqli_prepare($conn, $rank_sql);
            if ($st && $sinav_turu_val !== null) mysqli_stmt_bind_param($st, "s", $sinav_turu_val);
        } else {
            $rank_sql = $sinav_turu_val !== null
                ? "SELECT id FROM gorusme_listesi WHERE sinif = ? AND (sinav_turu <=> ?) ORDER BY basari_yuzdesi DESC, id ASC"
                : "SELECT id FROM gorusme_listesi WHERE sinif = ? AND (sinav_turu IS NULL OR sinav_turu = '') ORDER BY basari_yuzdesi DESC, id ASC";
            $st = mysqli_prepare($conn, $rank_sql);
            if ($st && $sinav_turu_val !== null) mysqli_stmt_bind_param($st, "is", $sinif_val, $sinav_turu_val);
            elseif ($st) mysqli_stmt_bind_param($st, "i", $sinif_val);
        }
        if ($st) {
            mysqli_stmt_execute($st);
            $rank_res = mysqli_stmt_get_result($st);
            mysqli_stmt_close($st);
            if ($rank_res) {
                $pos = 0;
                $toplam = mysqli_num_rows($rank_res);
                while ($r = mysqli_fetch_assoc($rank_res)) {
                    $pos++;
                    if ((int)$r['id'] === (int)$row['id']) {
                        $sinif_ici_sira = $pos;
                        $sinif_ici_toplam = $toplam;
                        break;
                    }
                }
            }
        }
    }
}

$ogrenci_adi = trim(($row['ogrenci_ad'] ?? '') . ' ' . ($row['ogrenci_soyad'] ?? ''));
$veli_adi   = trim(($row['veli_ad'] ?? '') . ' ' . ($row['veli_soyad'] ?? ''));
// Sınav profili (sonuclar.php ile aynı): both | onlyEnglish | onlyGerman
$sinav_profil = 'onlyEnglish';
$has_ing = $sinav_row_ing !== null;
$has_alm = $sinav_row_alm !== null;
if ($has_ing && $has_alm) {
    $sinav_profil = 'both';
} elseif (!$has_ing && $has_alm) {
    $sinav_profil = 'onlyGerman';
}
teklif_v2_ensure_schema($conn);

// Fiyat teklifi sabitleri
define('FIYAT_BAZ_INGILIZCE', 66200);
define('FIYAT_BAZ_ALMANCA', 66200);
define('FIYAT_MATERYAL_NORMAL', 25000);
define('FIYAT_MATERYAL_PESIN', 22000);   
define('KUTUPHANE_GOSTERIM', 60000);     
define('MATERYAL_PESIN_INDIRIM', 3000);  
define('TAKSIT_BIRIM', 6700);            
define('MATERYAL_MAX_TAKSIT', 8);
define('TAKSIT_SON_TARIH', '2027-05-31'); 
$fiyat_sira_indirim = [1 => 100, 2 => 80, 3 => 70, 4 => 50, 5 => 40, 6 => 30, 7 => 20];

$fiyat_kayit = [];
$fiyat_mesaj = '';
$fiyat_hata = '';
$eldan_odeme_hata = '';
$gorusme_listesi_id = (int)$row['id'];
$fiyat_tablosu_var = true; 
$teklif_v2 = teklif_v2_get_latest_by_gorusme($conn, $gorusme_listesi_id);
$teklif_linki = '';
if ($teklif_v2 && !empty($teklif_v2['paylasim_token'])) {
    $ad_param = trim(($row['ogrenci_ad'] ?? '') . ' ' . ($row['ogrenci_soyad'] ?? ''));
    $ad_param = $ad_param !== '' ? '&ad=' . rawurlencode($ad_param) : '';
    $teklif_linki = 'https://genclikdil.com/sonuclar-aciklama.php?t=' . $teklif_v2['paylasim_token'] . $ad_param;
}
$odeme_adimlari = $teklif_v2 ? teklif_v2_get_steps($conn, (int)$teklif_v2['id']) : [];

$sinif_icinde_teklif_verilmis_siralar = [];
if ($sinif_val > 0) {
    $sq = mysqli_prepare($conn, "SELECT DISTINCT f.sinif_ici_sira FROM gorusme_teklif_v2 f INNER JOIN gorusme_listesi g ON g.id = f.gorusme_listesi_id WHERE g.sinif = ? AND g.id != ? AND f.sinif_ici_sira IS NOT NULL AND f.sinif_ici_sira > 0");
    if ($sq) {
        mysqli_stmt_bind_param($sq, "ii", $sinif_val, $gorusme_listesi_id);
        mysqli_stmt_execute($sq);
        $rq = mysqli_stmt_get_result($sq);
        mysqli_stmt_close($sq);
        if ($rq) {
            while ($r = mysqli_fetch_assoc($rq)) {
                $s = (int)($r['sinif_ici_sira'] ?? 0);
                if ($s > 0) $sinif_icinde_teklif_verilmis_siralar[] = $s;
            }
        }
    }
}

// Link oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_olustur'])) {
    if (!$teklif_v2) {
        $sira_val = $sinif_ici_sira !== null ? (int)$sinif_ici_sira : null;
        $sinav_snapshot = $sinav_sonuc_id > 0 ? (int)$sinav_sonuc_id : null;
        $odeme_modu = 'ayri';
        $teklif_v2 = teklif_v2_create($conn, $gorusme_listesi_id, $sira_val, $sinav_snapshot, $odeme_modu, $_SESSION['personel_adi'] ?? null);
        @personel_log_ekle($conn, 'gorusme_ogrenci_detay.php', 'link_olustur_v2', ['gorusme_listesi_id' => $gorusme_listesi_id, 'odeme_modu' => $odeme_modu]);
    }
    $personel_adi_esc = mysqli_real_escape_string($conn, $_SESSION['personel_adi'] ?? '');
    mysqli_query($conn, "UPDATE gorusme_listesi SET personel = '$personel_adi_esc', islem_tarihi = NOW() WHERE id = " . (int)$gorusme_listesi_id);
    header('Location: gorusme_ogrenci_detay.php?id=' . $id . '&geri=' . urlencode($geri_url) . '&link_olusturuldu=1');
    exit;
}

// Elden ödeme onayı
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eldan_odeme_onayla']) && (int)$_POST['eldan_odeme_onayla'] === 1) {
    $eldan_odeme_hata = '';
    if (empty($_POST['elden_sozlesme_onay']) || (int)$_POST['elden_sozlesme_onay'] !== 1) {
        $eldan_odeme_hata = 'Elden kayıt işlemi için veli onayının beyan edilmesi zorunludur.';
    } else {
    $erken_kayit = isset($_POST['erken_kayit']) ? (int)$_POST['erken_kayit'] : 0;
    $ingilizce = isset($_POST['ingilizce']) ? (int)$_POST['ingilizce'] : 0;
    $almanca = isset($_POST['almanca']) ? (int)$_POST['almanca'] : 0;
    $pesin_indirimi = isset($_POST['pesin_indirimi']) ? (int)$_POST['pesin_indirimi'] : 0;
    $taksit_sayisi = isset($_POST['taksit_sayisi']) ? (int)$_POST['taksit_sayisi'] : 1;
    $idare_indirimi = 0;
    if ($eldan_idare_visible && isset($_POST['idare_indirimi'])) {
        $idare_indirimi = (int)$_POST['idare_indirimi'];
        if ($idare_indirimi < 0) $idare_indirimi = 0;
    }
    if ($ingilizce || $almanca) {
        $sira_val = $sinif_ici_sira !== null ? (int)$sinif_ici_sira : 0;
        $fiyat = sonuc_fiyat_hesapla_coklu_dal($sira_val, $erken_kayit, $ingilizce, $almanca, $sinav_profil, true);
        $kurs_ing = (int)($fiyat['hesap_detay']['kurs_ingilizce'] ?? 0);
        $kurs_alm = (int)($fiyat['hesap_detay']['kurs_almanca'] ?? 0);
        $kurs_tutar = $kurs_ing + $kurs_alm;
        if ($idare_indirimi > 0) {
            if ($idare_indirimi > $kurs_tutar) $idare_indirimi = $kurs_tutar;
            // İdare indirimi toplamdan düşer; önce İngilizce, artarsa Almanca tutardan düşülür.
            $idare_kalan = $idare_indirimi;
            $dus_eng = min($idare_kalan, $kurs_ing);
            $kurs_ing -= $dus_eng;
            $idare_kalan -= $dus_eng;
            if ($idare_kalan > 0) {
                $dus_alm = min($idare_kalan, $kurs_alm);
                $kurs_alm -= $dus_alm;
                $idare_kalan -= $dus_alm;
            }
        }
        $pesin_indirim_tutari = 0;
        if ($pesin_indirimi === 1 && $kurs_ing > 0) {
            // Peşin indirimi yalnız İngilizce tutara uygulanır.
            $pesin_sonrasi = (int)ceil(($kurs_ing * 0.95) / 100) * 100;
            if ($pesin_sonrasi < 0) $pesin_sonrasi = 0;
            $pesin_indirim_tutari = $kurs_ing - $pesin_sonrasi;
            $kurs_ing = $pesin_sonrasi;
        }
        $kurs_tutar = $kurs_ing + $kurs_alm;
        $max_taksit = $kurs_tutar > 0 ? (int)round($kurs_tutar / 6700) : 1;
        if ($max_taksit < 1) $max_taksit = 1;
        $simdi_rank = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
        $baslangic_ay = $simdi_rank->modify('first day of this month');
        $may_2027_lim = new DateTimeImmutable('2027-05-01', new DateTimeZone('Europe/Istanbul'));
        if ($baslangic_ay <= $may_2027_lim) {
            $aralik = $baslangic_ay->diff($may_2027_lim);
            $max_ay_sayisi = $aralik->y * 12 + $aralik->m + 1;
            if ($max_ay_sayisi < $max_taksit) $max_taksit = max(1, (int)$max_ay_sayisi);
        }
        if ($taksit_sayisi < 1) $taksit_sayisi = 1;
        if ($taksit_sayisi > $max_taksit) $taksit_sayisi = $max_taksit;

        if (!$teklif_v2) {
            $sira_db = $sinif_ici_sira !== null ? (int)$sinif_ici_sira : null;
            $sinav_snapshot = $sinav_sonuc_id > 0 ? (int)$sinav_sonuc_id : null;
            $teklif_v2 = teklif_v2_create($conn, $gorusme_listesi_id, $sira_db, $sinav_snapshot, 'toplu', $_SESSION['personel_adi'] ?? null);
        }
        if ($teklif_v2 && $kurs_tutar >= 0) {
            $teklif_v2_id = (int)$teklif_v2['id'];
            $ay_adlari = [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];
            $simdi = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
            $baslangic = $simdi->modify('first day of this month');
            $may_2027 = new DateTimeImmutable('2027-05-01', new DateTimeZone('Europe/Istanbul'));
            $taksit_plan = [];
            $base = (int)ceil($kurs_tutar / $taksit_sayisi / 100) * 100;
            if ($taksit_sayisi > 1 && ($taksit_sayisi - 1) * $base > $kurs_tutar) {
                $base = (int)floor($kurs_tutar / ($taksit_sayisi - 1) / 100) * 100;
            }
            for ($i = 1; $i <= $taksit_sayisi; $i++) {
                $tarih = $baslangic->modify('+' . ($i - 1) . ' months');
                if ($tarih > $may_2027) $tarih = $may_2027;
                $tutar = ($i < $taksit_sayisi) ? $base : ($kurs_tutar - ($taksit_sayisi - 1) * $base);
                $ay_metin = $ay_adlari[(int)$tarih->format('n')] . ' ' . $tarih->format('Y');
                $taksit_plan[] = ['no' => $i, 'tarih' => $tarih->format('Y-m-d'), 'tarih_metin' => $ay_metin, 'tutar' => $tutar];
            }
            $snapshot = [
                'kurs' => ['headers' => $ingilizce && $almanca ? ['İngilizce', 'Almanca', 'TOPLAM'] : ['İngilizce', 'TOPLAM'], 'rows' => []],
                'kitap_materyal' => ['rows' => []],
                'kurs_tutar' => $kurs_tutar,
                'kurs_tutar_ingilizce' => $kurs_ing,
                'kurs_tutar_almanca' => $kurs_alm,
                'taksit_plan' => $taksit_plan,
                'taksit_sayisi' => $taksit_sayisi,
                'eldan' => true,
                'idare_indirimi' => $idare_indirimi,
                'pesin_indirimi' => $pesin_indirimi === 1,
                'pesin_indirim_tutari' => $pesin_indirim_tutari,
            ];
            $snapshot_json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
            if ($snapshot_json === false) $snapshot_json = '{}';
            $merchant_oid = 'ELDEN' . $teklif_v2_id . 'T' . time() . substr(preg_replace('/[^a-zA-Z0-9]/', '', uniqid('', true)), -5);

            $step_row = null;
            $steps = teklif_v2_get_steps($conn, $teklif_v2_id);
            foreach ($steps as $s) {
                if (($s['adim'] ?? '') === 'toplu') { $step_row = $s; break; }
            }
            if (!$step_row) {
                mysqli_query($conn, "INSERT INTO gorusme_teklif_odeme_adimlari (teklif_id, adim, sira_no, durum) VALUES (" . (int)$teklif_v2_id . ", 'toplu', 1, 'bekliyor')");
                $steps = teklif_v2_get_steps($conn, $teklif_v2_id);
                foreach ($steps as $s) {
                    if (($s['adim'] ?? '') === 'toplu') { $step_row = $s; break; }
                }
            }
            $tutar_kurus = $kurs_tutar * 100;
            $snapshot_esc = mysqli_real_escape_string($conn, $snapshot_json);
            $moid_esc = mysqli_real_escape_string($conn, $merchant_oid);
            if ($step_row) {
                mysqli_query($conn, "UPDATE gorusme_teklif_odeme_adimlari SET durum = 'success', tutar_kurus = $tutar_kurus, merchant_oid = '$moid_esc', fiyat_snapshot_json = '$snapshot_esc', odendi_at = NOW(), updated_at = NOW() WHERE id = " . (int)$step_row['id'] . " LIMIT 1");
            }
            $odeme_tipi = $taksit_sayisi > 1 ? 'elden_taksit' : 'elden';
            $paytr_cols = [];
            $crs = @mysqli_query($conn, "SHOW COLUMNS FROM paytr_odemeler");
            if ($crs) { while ($c = mysqli_fetch_assoc($crs)) $paytr_cols[] = $c['Field']; }
            $fields = ['teklif_id', 'merchant_oid', 'tutar_kurus', 'durum'];
            $vals = [0, $merchant_oid, $tutar_kurus, 'success'];
            if (in_array('teklif_v2_id', $paytr_cols, true)) { $fields[] = 'teklif_v2_id'; $vals[] = $teklif_v2_id; }
            if (in_array('teklif_adim_id', $paytr_cols, true)) { $fields[] = 'teklif_adim_id'; $vals[] = (int)($step_row['id'] ?? 0); }
            if (in_array('odeme_tipi', $paytr_cols, true)) { $fields[] = 'odeme_tipi'; $vals[] = $odeme_tipi; }
            if (in_array('fiyat_tablosu_snapshot', $paytr_cols, true)) { $fields[] = 'fiyat_tablosu_snapshot'; $vals[] = $snapshot_json; }
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO paytr_odemeler (" . implode(', ', $fields) . ") VALUES ($placeholders)";
            $ins = mysqli_prepare($conn, $sql);
            if ($ins) {
                $types = '';
                foreach ($vals as $v) { $types .= is_int($v) ? 'i' : 's'; }
                $refs = [];
                foreach ($vals as $k => $v) $refs[$k] = &$vals[$k];
                call_user_func_array([$ins, 'bind_param'], array_merge([$types], $refs));
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
            teklif_v2_after_step_success($conn, $teklif_v2_id, 'toplu');
            $personel_adi_esc = mysqli_real_escape_string($conn, $_SESSION['personel_adi'] ?? '');
            mysqli_query($conn, "UPDATE gorusme_listesi SET personel = '$personel_adi_esc', islem_tarihi = NOW() WHERE id = " . (int)$gorusme_listesi_id);
            header('Location: gorusme_ogrenci_detay.php?id=' . $id . '&geri=' . urlencode($geri_url) . '&eldan_kayit_ok=1');
            exit;
        }
    }
    }
}

$sira_default = $sinif_ici_sira !== null ? (int)$sinif_ici_sira : '';
$erken_kayit_bitis = new DateTimeImmutable('2026-04-30 23:59:59', new DateTimeZone('Europe/Istanbul'));
$erken_kayit_aktif = (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'))) <= $erken_kayit_bitis;
$basarili_odemeler = [];
if ($teklif_v2 && !empty($teklif_v2['id'])) {
    $teklif_v2_id = (int)$teklif_v2['id'];
    $q = mysqli_prepare($conn, "SELECT p.id, p.tutar_kurus, p.odeme_tipi, p.fiyat_tablosu_snapshot, p.updated_at FROM paytr_odemeler p WHERE p.teklif_v2_id = ? AND p.durum = 'success' ORDER BY p.updated_at DESC");
    mysqli_stmt_bind_param($q, "i", $teklif_v2_id);
    mysqli_stmt_execute($q);
    $rq = mysqli_stmt_get_result($q);
    mysqli_stmt_close($q);
    while ($r = mysqli_fetch_assoc($rq)) {
        $basarili_odemeler[] = $r;
    }
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Öğrenci Profili — <?= htmlspecialchars($ogrenci_adi) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --g-primary: #1e40af; /* Deep Blue */
            --g-primary-dark: #1e3a8a; 
            --g-primary-light: #eff6ff;
            --g-bg: #f8fafc; /* Softer, modern slate background */
            --g-card: #ffffff;
            --g-border: #e2e8f0;
            --g-text: #334155; /* Slate 700 */
            --g-muted: #64748b; /* Slate 500 */
            --g-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.05), 0 4px 6px -4px rgb(0 0 0 / 0.05);
            --g-shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --g-radius: 16px;
            --g-radius-sm: 10px;
        }
        
        body { 
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; 
            background: var(--g-bg); 
            color: var(--g-text); 
            line-height: 1.6;
        }
        
        /* Navbar / Top Bar */
        .top-bar {
            background: var(--g-card);
            padding: 16px 32px; 
            margin-bottom: 30px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            box-shadow: var(--g-shadow-sm);
            border-bottom: 1px solid var(--g-border);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .top-bar .brand { color: var(--g-primary-dark); font-weight: 800; font-size: 1.3rem; letter-spacing: -0.02em; display: flex; align-items: center; gap: 10px; }
        .top-bar .brand span { color: var(--g-muted); font-weight: 500; font-size: 0.95rem; }
        .top-bar .btn-outline-primary { border-radius: 8px; font-weight: 600; }
        .top-bar .text-person { color: var(--g-text); font-size: 0.95rem; font-weight: 600; background: var(--g-bg); padding: 6px 14px; border-radius: 20px; border: 1px solid var(--g-border); }
        
        /* Section Titles */
        .section-title { 
            font-size: 0.85rem; 
            font-weight: 700; 
            text-transform: uppercase; 
            letter-spacing: 0.08em; 
            color: var(--g-primary); 
            margin-bottom: 16px; 
            padding-bottom: 8px; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
        }

        /* Cards */
        .card, .detail-card, .veli-box { 
            background: var(--g-card); 
            border: 1px solid var(--g-border); 
            border-radius: var(--g-radius); 
            box-shadow: var(--g-shadow); 
            margin-bottom: 24px; 
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .detail-card:hover { box-shadow: 0 10px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1); }
        
        .detail-card .card-header { 
            background: #ffffff; 
            padding: 16px 24px; 
            font-weight: 700; 
            font-size: 1.05rem; 
            border-bottom: 1px solid var(--g-border); 
            color: var(--g-primary-dark);
            display: flex; align-items: center; gap: 10px;
        }
        .detail-card .card-body { padding: 24px; }
        
        /* Detail Items Grid */
        .detail-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 24px; }
        .detail-item { display: flex; flex-direction: column; gap: 4px; }
        .detail-item label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--g-muted); margin: 0; font-weight: 600; }
        .detail-item .value { font-weight: 600; font-size: 1.05rem; color: var(--g-text); }
        
        /* Profile / Veli Box */
        .veli-box { 
            padding: 24px; 
            border-left: 5px solid var(--g-primary); 
            background: linear-gradient(to right, #ffffff, #f8fafc);
        }
        .veli-box .veli-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--g-primary); margin-bottom: 6px; }
        .tel-link { font-size: 1.1rem; font-weight: 700; text-decoration: none; color: var(--g-primary); display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; background: var(--g-primary-light); border-radius: 8px; transition: all 0.2s; }
        .tel-link:hover { background: #dbeafe; color: var(--g-primary-dark); }
        
        /* Status Badges */
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-weight: 600; font-size: 0.85rem; }
        .status-badge::before { content: ''; display: block; width: 8px; height: 8px; border-radius: 50%; }
        
        .status-bekliyor { background-color: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
        .status-bekliyor::before { background-color: #f59e0b; }
        
        .status-sonuc-iletildi { background-color: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
        .status-sonuc-iletildi::before { background-color: #0ea5e9; }
        
        .status-randevu-alindi { background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .status-randevu-alindi::before { background-color: #10b981; }
        
        .status-gorusuldu { background-color: #ede9fe; color: #6d28d9; border: 1px solid #ddd6fe; }
        .status-gorusuldu::before { background-color: #8b5cf6; }
        .status-gorusuldu-yuz-yuze { background-color: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe; }
        .status-gorusuldu-yuz-yuze::before { background-color: #7c3aed; }
        .status-gorusuldu-telefon { background-color: #ccfbf1; color: #0f766e; border: 1px solid #99f6e4; }
        .status-gorusuldu-telefon::before { background-color: #14b8a6; }
        
        .status-ulasilamadi { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .status-ulasilamadi::before { background-color: #ef4444; }
        .status-sonuc-icin-ulasilamadi { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .status-sonuc-icin-ulasilamadi::before { background-color: #ef4444; }
        .status-gorusme-icin-ulasilamadi { background-color: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; }
        .status-gorusme-icin-ulasilamadi::before { background-color: #e11d48; }
        
        .status-ertelendi { background-color: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
        .status-ertelendi::before { background-color: #f97316; }

        /* Modern Tables */
        .table-responsive { border-radius: var(--g-radius-sm); border: 1px solid var(--g-border); overflow: hidden; }
        .fiyat-table { margin-bottom: 0; background: #fff; }
        .fiyat-table th { background: #f8fafc; font-weight: 700; color: var(--g-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 14px 16px; border-bottom: 2px solid var(--g-border); }
        .fiyat-table td { padding: 14px 16px; vertical-align: middle; border-bottom: 1px solid var(--g-border); color: var(--g-text); font-weight: 500; }
        .s-kitap-wrap { background: var(--g-card); border: 1px solid var(--g-border); border-left: 4px solid var(--g-primary); border-radius: var(--g-radius-sm); padding: 16px; box-shadow: var(--g-shadow-sm); margin-bottom: 20px; }
        .s-kitap-wrap .s-kitap-tit { font-weight: 700; font-size: 0.95rem; color: var(--g-primary); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; }
        .s-kitap-wrap .s-kitap-uyari { font-size: 0.8rem; color: var(--g-muted); font-weight: 500; margin-bottom: 10px; }
        .s-kitap-wrap .s-fiyat-tbl { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .s-kitap-wrap .s-fiyat-tbl th, .s-kitap-wrap .s-fiyat-tbl td { border: 1px solid var(--g-border); padding: 8px 10px; background: #fff; }
        .s-kitap-wrap .s-fiyat-tbl th { background: var(--g-primary-light); color: var(--g-primary-dark); font-weight: 700; }
        .s-kitap-wrap .s-fiyat-tbl tr.bold-row th, .s-kitap-wrap .s-fiyat-tbl tr.bold-row td { background: var(--g-primary-light); color: var(--g-primary-dark); font-weight: 700; }
        .s-kitap-wrap .s-fiyat-tbl tr.indirim-row td { color: #15803d; font-weight: 600; }
        .fiyat-table tr:last-child td { border-bottom: none; }
        .fiyat-table .toplam-satir { background: var(--g-primary-light); }
        .fiyat-table .toplam-satir td { font-weight: 700; color: var(--g-primary-dark); font-size: 1.05rem; }
        
        .text-success { color: #16a34a !important; } /* Softer green */
        
        /* Forms & Buttons */
        .btn { border-radius: var(--g-radius-sm); font-weight: 600; padding: 10px 20px; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: var(--g-primary); border-color: var(--g-primary); box-shadow: 0 4px 6px -1px rgba(30, 64, 175, 0.2); }
        .btn-primary:hover { background: var(--g-primary-dark); border-color: var(--g-primary-dark); transform: translateY(-1px); }
        .btn-success { background: #10b981; border-color: #10b981; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2); }
        .btn-success:hover { background: #059669; border-color: #059669; transform: translateY(-1px); }
        
        .form-control, .form-select { border-radius: 8px; border: 1px solid var(--g-border); padding: 10px 14px; font-weight: 500; background-color: #fff; }
        .form-control:focus, .form-select:focus { border-color: var(--g-primary); box-shadow: 0 0 0 4px var(--g-primary-light); }
        
        .ogrenci-link-hizli { background: #fff; border: 1px solid var(--g-border); border-radius: var(--g-radius-sm); padding: 20px; box-shadow: var(--g-shadow-sm); }
        
        /* Checkbox groups visually better */
        .fiyat-checkbox-group { display: flex; flex-direction: column; gap: 12px; }
        .custom-check-card { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid var(--g-border); border-radius: 10px; cursor: pointer; transition: all 0.2s; background: #fff; }
        .custom-check-card:hover { border-color: var(--g-primary); background: var(--g-primary-light); }
        .custom-check-card input[type="checkbox"] { width: 1.2rem; height: 1.2rem; accent-color: var(--g-primary); cursor: pointer; }
        .custom-check-card span { font-weight: 600; color: var(--g-text); }
        
        .alert { border-radius: var(--g-radius-sm); border: none; font-weight: 500; }
        .alert-success { background-color: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }

        /* Klasik tema (Win7 uyumlu görünüm - İstek üzerine korundu) */
        body.win7-mode { font-family: 'Segoe UI', Tahoma, Arial, sans-serif !important; --g-bg: #e5e5e5; --g-border: #a0a0a0; --g-shadow: none !important; --g-radius: 3px !important; --g-radius-sm: 2px !important; }
        body.win7-mode .top-bar { background: #f0f0f0; border-bottom: 1px solid #ccc; color: #333; position: static; }
        body.win7-mode .detail-card, body.win7-mode .veli-box, body.win7-mode .card { border: 1px solid #999; box-shadow: none; border-radius: 3px; }
        body.win7-mode .detail-card .card-header { background: #005a9e; color: #fff; }
        body.win7-mode .form-control, body.win7-mode .form-select, body.win7-mode .btn { border-radius: 3px; }
    </style>
</head>
<body>
<?php
$durum_renk = [
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
$gorusme_durum_class = $durum_renk[trim((string)($row['gorusme_durumu'] ?? ''))] ?? 'status-bekliyor';
$son_islem_personel = trim((string)($row['personel'] ?? ''));
$son_islem_tarih = !empty($row['islem_tarihi']) ? date('d.m.Y H:i', strtotime($row['islem_tarihi'])) : '';
$aktif_personel = $_SESSION['personel_adi'] ?? '';
?>
<div class="top-bar">
    <div class="brand"><i class="bi bi-mortarboard text-primary"></i> Öğrenci Profili <span>| Sınav & Kayıt Yönetimi</span></div>
    <div class="d-flex align-items-center gap-3">
        <span class="text-person"><i class="bi bi-person-circle text-primary me-2"></i><?= htmlspecialchars($aktif_personel) ?></span>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleWin7Mode()" id="btn-win7-toggle" title="Görünümü Değiştir"><i class="bi bi-pc-display"></i> Klasik Tema</button>
        <a href="<?= htmlspecialchars($geri_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-left"></i> Listeye Dön</a>
        <a href="index.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-grid"></i> Panel</a>
    </div>
</div>

<div class="container-fluid px-lg-5 px-3 pb-5 max-w-7xl mx-auto" style="max-width: 1400px;">
    
    <div class="veli-box">
        <div class="row align-items-center">
            <div class="col-12 col-md-7">
                <div class="veli-label"><i class="bi bi-person-bounding-box me-1"></i> Öğrenci Bilgileri</div>
                <h3 class="mb-1 fw-bold text-dark"><?= htmlspecialchars($ogrenci_adi) ?></h3>
                <?php if ($son_islem_personel !== '' || $son_islem_tarih !== ''): ?>
                <div class="small text-muted fw-medium mt-2"><i class="bi bi-clock-history me-1"></i> Son Güncelleme: <span class="text-dark"><?= $son_islem_personel !== '' ? htmlspecialchars($son_islem_personel) : '—' ?></span><?= $son_islem_tarih !== '' ? ' • ' . $son_islem_tarih : '' ?></div>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-5 text-md-end mt-4 mt-md-0 border-start ps-md-4">
                <div class="veli-label"><i class="bi bi-people-fill me-1"></i> Veli / İletişim Kişisi</div>
                <div class="mb-2 fw-bold fs-5 text-dark"><?= htmlspecialchars($veli_adi) ?></div>
                <a href="tel:<?= htmlspecialchars($row['tel_temiz'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="tel-link"><i class="bi bi-telephone-outbound-fill"></i> <?= htmlspecialchars($row['tel_orijinal'] ?? $row['tel_temiz'] ?? '') ?></a>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['eldan_kayit_ok']) && (int)$_GET['eldan_kayit_ok'] === 1): ?>
    <div class="alert alert-success d-flex align-items-center py-3 px-4 mb-4"><i class="bi bi-check-circle-fill fs-4 me-3"></i><div><strong>Ödeme başarıyla kaydedildi.</strong> Öğrencinin kayıt işlemi tamamlanmış olarak sisteme işlendi.</div></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-xl-8">
            <div class="section-title"><i class="bi bi-journal-check"></i> Akademik Performans & Sınav Detayları</div>
            
            <div class="detail-card">
                <div class="card-header"><i class="bi bi-file-earmark-bar-graph"></i> Temel Sınav Bilgileri</div>
                <div class="card-body">
                    <div class="detail-row mb-4">
                        <div class="detail-item"><label>Öğrenci Adı Soyadı</label><div class="value"><?= htmlspecialchars($ogrenci_adi) ?></div></div>
                        <div class="detail-item"><label>Eğitim Kademesi</label><div class="value text-primary"><?= (int)($row['sinif'] ?? 0) ?>. Sınıf</div></div>
                        <div class="detail-item"><label>Katılım Sağlanan Sınavlar</label>
                            <div class="value">
                            <?php
                            $sinav_turleri = [];
                            if ($sinav_row_ing || (isset($row['sinav_sonuc_id_ingilizce']) && (int)$row['sinav_sonuc_id_ingilizce'] > 0)) $sinav_turleri[] = 'İngilizce';
                            if ($sinav_row_alm || (isset($row['sinav_sonuc_id_almanca']) && (int)$row['sinav_sonuc_id_almanca'] > 0)) $sinav_turleri[] = 'Almanca';
                            if (empty($sinav_turleri) && !empty($row['sinav_turu'])) $sinav_turleri[] = (string)$row['sinav_turu'];
                            
                            foreach($sinav_turleri as $tur) {
                                echo '<span class="badge bg-primary me-1">' . htmlspecialchars($tur) . '</span>';
                            }
                            if(empty($sinav_turleri)) echo '—';
                            ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-item"><label>Yüzdelik dilim</label><div class="value fs-4 text-success">
                            <?php if ($sinif_ici_yuzdelik_dilim !== null): ?>
                                <strong>%<?= number_format($sinif_ici_yuzdelik_dilim, 1, ',', '') ?></strong>
                                <span class="fs-6 fw-normal text-muted">(en üst %<?= number_format($sinif_ici_yuzdelik_dilim, 1, ',', '') ?> — <?= (int)$sinif_ici_sira ?>. sıra / <?= (int)$sinif_ici_toplam * 2 ?> katılımcı)</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </div></div>
                        
                        <?php if ($sinif_ici_sira !== null && $sinif_ici_toplam !== null): ?>
                        <div class="detail-item p-3 rounded" style="background: var(--g-primary-light); border: 1px solid #bfdbfe;">
                            <label class="text-primary">Sınıf İçi Başarı Sıralaması</label>
                            <div class="value text-primary"><strong><?= (int)$sinif_ici_sira ?></strong>. Sıra <span class="fs-6 fw-normal text-muted">/ <?= (int)$sinif_ici_toplam * 2 ?> Öğrenci Arasında</span></div>
                            <small class="text-primary mt-1" style="font-size: 0.7rem;">(Burs ve indirim hesaplamasına temel teşkil eder)</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php $sinav_bloklari = []; if ($sinav_row_ing) $sinav_bloklari['İngilizce Yeterlilik Sınavı'] = $sinav_row_ing; if ($sinav_row_alm) $sinav_bloklari['Almanca Yeterlilik Sınavı'] = $sinav_row_alm; if (empty($sinav_bloklari) && $sinav_row) $sinav_bloklari['Sınav Detayı'] = $sinav_row; ?>
            <?php if (!empty($sinav_bloklari)): ?>
                <?php foreach ($sinav_bloklari as $blok_ad => $blok): ?>
                <div class="detail-card">
                    <div class="card-header"><i class="bi bi-clipboard2-pulse"></i> <?= htmlspecialchars($blok_ad) ?> Sonuçları</div>
                    <div class="card-body">
                        <div class="row mb-4 g-3">
                            <div class="col-md-6">
                                <div class="p-3 border rounded bg-light">
                                    <label class="d-block small text-muted text-uppercase fw-bold mb-2">Okuma & Yazma Becerisi</label>
                                    <div class="d-flex gap-2">
                                        <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-3"><i class="bi bi-check-circle me-1"></i> <?= (int)($blok['okuma_yazma_dogru'] ?? 0) ?> Doğru</span>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle py-2 px-3"><i class="bi bi-x-circle me-1"></i> <?= (int)($blok['okuma_yazma_yanlis'] ?? 0) ?> Yanlış</span>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle py-2 px-3"><i class="bi bi-dash-circle me-1"></i> <?= (int)($blok['okuma_yazma_bos'] ?? 0) ?> Boş</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 border rounded bg-light">
                                    <label class="d-block small text-muted text-uppercase fw-bold mb-2">Dinleme & Konuşma Becerisi</label>
                                    <div class="d-flex gap-2">
                                        <span class="badge bg-success-subtle text-success border border-success-subtle py-2 px-3"><i class="bi bi-check-circle me-1"></i> <?= (int)($blok['dinleme_konusma_dogru'] ?? 0) ?> Doğru</span>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle py-2 px-3"><i class="bi bi-x-circle me-1"></i> <?= (int)($blok['dinleme_konusma_yanlis'] ?? 0) ?> Yanlış</span>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle py-2 px-3"><i class="bi bi-dash-circle me-1"></i> <?= (int)($blok['dinleme_konusma_bos'] ?? 0) ?> Boş</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-row">
                            <div class="detail-item"><label>Toplam Sorular Üzerinden Dağılım</label>
                                <div class="value">
                                    <span class="text-success fw-bold"><?= (int)($blok['toplam_dogru'] ?? 0) ?> D</span> / 
                                    <span class="text-danger"><?= (int)($blok['toplam_yanlis'] ?? 0) ?> Y</span> / 
                                    <span class="text-muted"><?= (int)($blok['toplam_bos'] ?? 0) ?> B</span>
                                </div>
                            </div>
                            <div class="detail-item"><label>Sınav Tarihi</label><div class="value"><?= !empty($blok['kayit_tarihi']) ? date('d.m.Y H:i', strtotime($blok['kayit_tarihi'])) : '—' ?></div></div>
                            <div class="detail-item"><label>Başarı Yüzdesi</label><div class="value fs-5 fw-bold text-dark">%<?= number_format((float)($blok['basari_yuzdesi'] ?? 0), 1, ',', '') ?></div></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="detail-card">
                    <div class="card-body">
                        <p class="text-muted mb-0"><i class="bi bi-info-circle me-2"></i> Detaylı sınav soru analizi bulunmuyor. Sadece liste bazlı genel sonuçlar mevcuttur.</p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="section-title mt-5"><i class="bi bi-wallet-fill"></i> Ödeme & Kayıt İşlemleri</div>
            <div class="detail-card border-primary">
                <div class="card-header bg-primary-subtle text-primary border-primary"><i class="bi bi-cash-stack"></i> Kayıt & Fiyatlandırma Formu</div>
                <div class="card-body">
                    <?php if (!empty($basarili_odemeler)): ?>
                    <div class="text-center p-4">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                        <h5 class="mt-3 fw-bold">Ödeme Tamamlanmış</h5>
                        <p class="text-muted">Bu öğrencinin sisteme işlenmiş başarılı ödeme kaydı bulunmaktadır.</p>
                    </div>
                    <?php else: ?>
                    <form method="post" action="" id="elden-odeme-form">
                        <?php if (!empty($eldan_odeme_hata)): ?>
                        <div class="alert alert-danger py-3 mb-4"><i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($eldan_odeme_hata) ?></div>
                        <?php endif; ?>
                        
                        <input type="hidden" name="eldan_odeme_onayla" value="1">
                        <input type="hidden" name="erken_kayit" id="elden-erken" value="<?= $erken_kayit_aktif ? '1' : '0' ?>">
                        <input type="hidden" name="ingilizce" id="elden-ingilizce" value="0">
                        <input type="hidden" name="almanca" id="elden-almanca" value="0">
                        <input type="hidden" name="pesin_indirimi" id="elden-pesin-indirimi" value="0">
                        <input type="hidden" name="taksit_sayisi" id="elden-taksit-sayisi" value="1">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark d-block mb-3">Eğitim Programı Seçimi (En az biri zorunlu)</label>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="custom-check-card w-100">
                                        <input type="checkbox" name="cb_ingilizce" id="elden-cb-ingilizce" value="1">
                                        <span>İngilizce Eğitim Programı</span>
                                    </label>
                                </div>
                                <div class="col-md-6">
                                    <label class="custom-check-card w-100">
                                        <input type="checkbox" name="cb_almanca" id="elden-cb-almanca" value="1">
                                        <span>Almanca Eğitim Programı</span>
                                    </label>
                                </div>
                                <div class="col-12">
                                    <label class="custom-check-card w-100" style="<?= !$erken_kayit_aktif ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>">
                                        <input type="checkbox" name="cb_erken" id="elden-cb-erken" value="1" <?= $erken_kayit_aktif ? ' checked' : '' ?><?= $erken_kayit_aktif ? '' : ' disabled' ?>>
                                        <span>Erken Kayıt Avantajı (30 Nisan 2026'ya kadar geçerli) <?= $erken_kayit_aktif ? '<span class="badge bg-success ms-2">Aktif</span>' : '<span class="badge bg-secondary ms-2">Süresi Doldu</span>' ?></span>
                                    </label>
                                </div>
                                <div class="col-12">
                                    <label class="custom-check-card w-100">
                                        <input type="checkbox" name="cb_pesin_indirim" id="elden-cb-pesin-indirim" value="1">
                                        <span>Peşin Ödeme İndirimi (%5 - yalnız İngilizce)</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h6 class="fw-bold mb-3"><i class="bi bi-calculator me-2"></i> Eğitim Ücreti Çizelgesi</h6>
                            <div class="table-responsive">
                                <table class="table fiyat-table" id="elden-kurs-tablo">
                                    <thead><tr id="elden-thead"><th>Kalemler & Hizmetler</th><th class="text-end">Tutar (TL)</th></tr></thead>
                                    <tbody id="elden-tbody-kurs"><tr><td colspan="2" class="text-center text-muted py-4">Lütfen yukarıdan eğitim programı seçiniz.</td></tr></tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-end mt-3">
                                <div class="bg-primary-subtle text-primary fw-bold px-4 py-3 rounded-3 fs-5" style="border: 1px solid #bfdbfe;">
                                    Ödenecek Eğitim Tutarı: <span id="elden-kurs-toplam">—</span> TL
                                </div>
                            </div>
                            <p class="text-end small text-muted mt-2" id="elden-indirim-ozet" style="display: none;"></p>
                        </div>
                        
                        <?php if ($eldan_idare_visible): ?>
                        <div class="mb-4 p-4 rounded bg-light border">
                            <label class="form-label fw-bold text-dark"><i class="bi bi-sliders me-1"></i> İdare Özel İndirimi (TL)</label>
                            <input type="number" name="idare_indirimi" id="elden-idare-indirimi" class="form-control" style="max-width: 200px;" min="0" value="0" step="100" placeholder="Örn: 1000">
                            <small class="text-muted d-block mt-2">Belirtilen tutar, genel toplamdan anında düşülecektir. Bu alan sadece yetkili hesaplara görünür.</small>
                        </div>
                        <?php endif; ?>

                        <div class="mb-4 p-4 rounded border" style="background: #f8fafc;">
                            <h6 class="fw-bold mb-3"><i class="bi bi-calendar3 me-2"></i> Ödeme Planı (Taksitlendirme)</h6>
                            <div class="alert alert-info py-2 small mb-3">Eğitim tutarı en fazla <strong id="elden-max-taksit-metin">1</strong> eşit taksite bölünebilir (Son vade: Mayıs 2027).</div>
                            
                            <label class="form-label fw-bold small text-muted">Vade Seçeneği</label>
                            <select id="elden-taksit-select" class="form-select mb-3" style="max-width: 200px;">
                                <option value="1">Peşin Ödeme (1 Taksit)</option>
                            </select>
                            
                            <div class="table-responsive" id="elden-taksit-tablo-wrap" style="display: none;">
                                <table class="table table-sm fiyat-table">
                                    <thead><tr><th>Sıra</th><th>Vade Tarihi</th><th class="text-end">Taksit Tutarı (TL)</th></tr></thead>
                                    <tbody id="elden-taksit-tbody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="mb-4 p-4 rounded" style="background: #fffbeb; border: 1px solid #fde68a;">
                            <label class="custom-check-card w-100" style="background: transparent; border: none; padding: 0;">
                                <input type="checkbox" name="elden_sozlesme_onay" id="elden-sozlesme-onay" value="1">
                                <span class="fw-normal">
                                    <strong class="text-dark d-block mb-1">Kayıt Onay Beyanı:</strong> 
                                    Veli ile sözleşme şartlarının görüşülüp mutabakata varıldığını ve sisteme manuel kayıt girildiğini onaylıyorum.
                                </span>
                            </label>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg" id="elden-onayla-btn" disabled><i class="bi bi-check2-circle"></i> Ödeme Planını Onayla ve Kaydet</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>
        
        <div class="col-xl-4">
            <div class="section-title"><i class="bi bi-broadcast"></i> İletişim & Durum</div>
            
            <div class="detail-card">
                <div class="card-header bg-light text-dark"><i class="bi bi-headset"></i> Mevcut Durum</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="small text-muted text-uppercase fw-bold d-block mb-2">Görüşme Aşaması</label>
                        <span class="status-badge <?= $gorusme_durum_class ?>"><?= htmlspecialchars($row['gorusme_durumu'] ?? 'Belirtilmemiş') ?></span>
                    </div>
                    <?php if(!empty($row['randevu_tarihi'])): ?>
                    <div class="mb-3">
                        <label class="small text-muted text-uppercase fw-bold d-block mb-1">Randevu Tarihi</label>
                        <div class="fw-bold"><i class="bi bi-calendar-event text-primary me-2"></i><?= date('d.m.Y H:i', strtotime($row['randevu_tarihi'])) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="mb-0">
                        <label class="small text-muted text-uppercase fw-bold d-block mb-1">Sorumlu Personel</label>
                        <div><i class="bi bi-person text-primary me-2"></i><?= htmlspecialchars($row['personel'] ?? 'Atanmamış') ?></div>
                    </div>
                </div>
            </div>

            <div class="detail-card">
                <div class="card-header bg-light text-dark"><i class="bi bi-share-fill"></i> Dijital Sonuç & Teklif Bağlantısı</div>
                <div class="card-body">
                    <?php if (isset($_GET['link_olusturuldu'])): ?>
                    <div class="alert alert-success py-2 px-3 mb-3 small"><i class="bi bi-check-circle-fill me-1"></i> Benzersiz veli linki oluşturuldu.</div>
                    <?php endif; ?>
                    
                    <?php if (!$teklif_v2): ?>
                    <p class="text-muted small mb-3">Veliye sınav sonuçlarını ve kayıt teklifini dijital olarak iletmek için benzersiz bir bağlantı oluşturabilirsiniz.</p>
                    <form method="post" action="" class="d-grid">
                        <input type="hidden" name="link_olustur" value="1">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg fs-5"></i> Link Oluştur</button>
                    </form>
                    
                    <?php elseif ($teklif_linki !== ''): ?>
                    <?php
                    $wp_tel = preg_replace('/[^0-9]/', '', $row['tel_temiz'] ?? $row['tel_orijinal'] ?? '');
                    if ($wp_tel !== '' && substr($wp_tel, 0, 1) === '0') $wp_tel = '90' . substr($wp_tel, 1);
                    $wp_mesaj = "Sayın Veli,\n"
                        . $ogrenci_adi . " için bursluluk sınav sonucu ve bilgilendirme detaylarınız hazırdır. Aşağıdaki bağlantıdan sonuçları görüntüleyebilirsiniz:\n"
                        . $teklif_linki . "\n"
                        . "İnceledikten sonra dilerseniz birlikte kısa bir değerlendirme yapabiliriz.";
                    ?>
                    <div class="ogrenci-link-hizli mb-3">
                        <label class="small text-muted fw-bold mb-2 d-block">Veli Erişim Bağlantısı</label>
                        <div class="input-group mb-3 shadow-sm">
                            <input type="text" class="form-control text-muted" style="font-size: 0.85rem;" id="teklif-link-input" value="<?= htmlspecialchars($teklif_linki, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            <button type="button" class="btn btn-outline-primary px-3" onclick="hizliKopyalaDetay('teklif-link-input', this)"><i class="bi bi-copy"></i></button>
                        </div>
                        
                        <label class="small text-muted fw-bold mb-2 d-block">WhatsApp İletişim Mesajı</label>
                        <textarea id="wp-mesaj-icerik" class="form-control form-control-sm mb-3 text-muted" rows="5" style="resize:none;"><?= htmlspecialchars($wp_mesaj, ENT_QUOTES, 'UTF-8') ?></textarea>
                        
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary w-50" onclick="kopyalaWpMesajIcerik(this)"><i class="bi bi-clipboard"></i> Metni Kopyala</button>
                            <?php if ($wp_tel !== ''): ?>
                            <button type="button" class="btn btn-success w-50" onclick="gonderWpMesaj('<?= htmlspecialchars($wp_tel, ENT_QUOTES, 'UTF-8') ?>')"><i class="bi bi-whatsapp"></i> WhatsApp</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-secondary w-50" disabled><i class="bi bi-whatsapp"></i> WhatsApp</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="d-flex align-items-center justify-content-between p-3 rounded bg-light border">
                        <span class="small fw-bold text-muted">Sunulan Ödeme Modeli:</span>
                        <?php if (($teklif_v2['odeme_modu'] ?? '') === 'toplu'): ?>
                            <span class="badge bg-primary px-3 py-2">Tek Çekim / Toplu</span>
                        <?php else: ?>
                            <span class="badge bg-info text-dark px-3 py-2">Eğitim & Materyal Ayrı</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($odeme_adimlari)): ?>
                        <div class="mt-4">
                            <h6 class="fw-bold small text-muted text-uppercase mb-3">Ödeme Takibi</h6>
                            <div class="d-flex flex-column gap-2">
                            <?php foreach ($odeme_adimlari as $adim): ?>
                                <?php
                                $adimAd = $adim['adim'] === 'kurs' ? 'Eğitim Ücreti' : ($adim['adim'] === 'kitap_materyal' ? 'Eğitim Materyalleri' : 'Toplu Ödeme');
                                $durum = $adim['durum'] ?? 'bekliyor';
                                $badge = $durum === 'success' ? 'bg-success' : ($durum === 'locked' ? 'bg-secondary' : ($durum === 'failed' ? 'bg-danger' : 'bg-warning text-dark'));
                                $durumLabel = $durum === 'success' ? 'Tahsil Edildi' : ($durum === 'locked' ? 'Bekliyor (Kilitli)' : ($durum === 'failed' ? 'İşlem Başarısız' : 'Bekleniyor'));
                                $icon = $durum === 'success' ? 'bi-check-circle' : 'bi-clock-history';
                                ?>
                                <div class="d-flex align-items-center justify-content-between border rounded p-3 bg-white">
                                    <span class="fw-medium text-dark"><?= htmlspecialchars($adimAd) ?></span>
                                    <span class="badge <?= $badge ?> py-2 px-3"><i class="bi <?= $icon ?> me-1"></i> <?= htmlspecialchars($durumLabel) ?></span>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($basarili_odemeler)): ?>
            <div class="s-kitap-wrap" id="elden-kitap-wrap">
                <div class="s-kitap-tit"><i class="bi bi-book-half me-1"></i> Kitap, Materyal &amp; Kütüphane ücreti</div>
                <p class="s-kitap-uyari mb-0">Bu ücret kurumumuzda ayrıca tahsil edilir. (Bilgilendirme amaçlı — soldaki forma göre güncellenir)</p>
                <table class="s-fiyat-tbl mt-2">
                    <thead><tr><th>Cinsi</th><th class="text-end">Tutar (TL)</th></tr></thead>
                    <tbody id="elden-tbody-kitap"><tr><td colspan="2" class="text-center text-muted py-3 small">Lütfen soldaki formdan eğitim programı seçiniz.</td></tr></tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($basarili_odemeler)): ?>
            <div class="detail-card border-success">
                <div class="card-header bg-success-subtle text-success border-success"><i class="bi bi-receipt"></i> İşlem Görmüş Ödemeler</div>
                <div class="card-body p-0">
                    <?php foreach ($basarili_odemeler as $od): ?>
                    <div class="p-4 border-bottom">
                        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
                            <span class="badge bg-success fs-6 py-2 px-3"><?=
                                ($od['odeme_tipi'] ?? '') === 'kurs' ? 'Eğitim Ücreti' :
                                (($od['odeme_tipi'] ?? '') === 'kitap_materyal' ? 'Eğitim Materyalleri' :
                                (($od['odeme_tipi'] ?? '') === 'elden_taksit' ? 'Manuel Taksit' :
                                (($od['odeme_tipi'] ?? '') === 'elden' ? 'Manuel Tahsilat' : 'Toplu Ödeme')))
                            ?></span>
                            <span class="text-muted small"><i class="bi bi-calendar-check me-1"></i> <?= !empty($od['updated_at']) ? date('d.m.Y H:i', strtotime($od['updated_at'])) : '' ?></span>
                        </div>
                        <div class="fs-4 fw-bold text-dark mb-3"><?= number_format((int)($od['tutar_kurus'] ?? 0) / 100, 0, ',', '.') ?> TL</div>
                        
                        <?php
                        $snap = isset($od['fiyat_tablosu_snapshot']) && $od['fiyat_tablosu_snapshot'] !== '' ? json_decode($od['fiyat_tablosu_snapshot'], true) : null;
                        if (is_array($snap) && isset($snap['kurs']) && isset($snap['kitap_materyal'])):
                            $sk = $snap['kurs']; $skh = $sk['headers'] ?? ['İngilizce','TOPLAM']; $skr = $sk['rows'] ?? [];
                            $skitap = $snap['kitap_materyal']; $skitap_r = $skitap['rows'] ?? [];
                        ?>
                        <div class="table-responsive mb-0 shadow-sm">
                            <table class="table table-sm fiyat-table" style="font-size:0.8rem;">
                                <thead><tr><th>Kalem</th><th class="text-end">Tutar (TL)</th></tr></thead>
                                <tbody>
                                    <?php foreach ($skr as $r):
                                        $kalem = $r['kalem'] ?? ''; $top = (int)($r['toplam'] ?? 0);
                                        $ozet = (stripos($kalem, 'toplam') !== false || stripos($kalem, 'taksitli') !== false || stripos($kalem, 'peşin') !== false);
                                    ?>
                                    <tr class="<?= $ozet ? 'toplam-satir' : '' ?>">
                                        <td><?= htmlspecialchars($kalem) ?></td>
                                        <td class="text-end"><?= number_format($top, 0, ',', '.') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="text-center mt-4">
                <p class="small text-muted mb-1">Sistem Kayıt Numarası: #<?= (int)($row['id'] ?? 0) ?></p>
                <p class="small text-muted">Kayıt Oluşturulma: <?= !empty($row['created_at']) ? date('d.m.Y H:i', strtotime($row['created_at'])) : '—' ?></p>
            </div>
        </div>
    </div>
</div>

<script>
function hizliKopyalaDetay(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    var txt = (el.value || '').toString();
    navigator.clipboard.writeText(txt).then(function() {
        if (btn) { var o = btn.innerHTML; btn.innerHTML = '<i class="bi bi-check-all"></i>'; setTimeout(function() { btn.innerHTML = o; }, 1500); }
    }).catch(function() { alert('Kopyalama başarısız.'); });
}
function kopyalaWpMesajIcerik(btn) {
    var el = document.getElementById('wp-mesaj-icerik');
    if (!el) return;
    var txt = String(el.value || '');
    if (!txt) return;
    navigator.clipboard.writeText(txt).then(function() {
        if (!btn) return;
        var old = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-all"></i> Kopyalandı';
        setTimeout(function() { btn.innerHTML = old; }, 1500);
    }).catch(function() {
        alert('Mesaj kopyalanamadı.');
    });
}
function gonderWpMesaj(tel) {
    var el = document.getElementById('wp-mesaj-icerik');
    var txt = el ? String(el.value || '') : '';
    if (!txt) {
        alert('Gönderilecek mesaj içeriği boş olamaz.');
        return;
    }
    var url = 'https://wa.me/' + encodeURIComponent(String(tel || '')) + '?text=' + encodeURIComponent(txt);
    window.open(url, '_blank', 'noopener');
}

var WIN7_MODE_KEY = 'gorusmeler_win7_mode';
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
initWin7Mode();
</script>

<?php if (empty($basarili_odemeler)): ?>
<script>
(function() {
    var BAZ_ING = 66200, BAZ_ALM = 66200, MAT_KITAP = 22000, KUTUPHANE_UCRETI = 60000;
    var SIRA_INDIRIM = { 1: 100, 2: 80, 3: 70, 4: 50, 5: 40, 6: 30, 7: 20 };
    var SIRA_INDIRIM_ALM_OZEL = { 1: 30, 2: 27, 3: 24, 4: 21, 5: 18, 6: 16, 7: 13 };
    var sira = <?= (int)($sinif_ici_sira ?? 0) ?>;
    var SINAV_PROFIL = <?= json_encode($sinav_profil, JSON_UNESCAPED_UNICODE) ?>;
    var ELDEN_TAKSIT_BIRIM = 6700;

    function roundUp(x) { return x <= 0 ? 0 : Math.ceil(x / 100) * 100; }
    function fmt(t) { return t === 0 ? '—' : (t < 0 ? '−' : '') + Math.abs(t).toLocaleString('tr-TR', { maximumFractionDigits: 0 }); }

    function getEnglishNormal(early) {
        var indPct = SIRA_INDIRIM[sira] !== undefined ? SIRA_INDIRIM[sira] : (sira >= 8 ? 10 : 0);
        var afterBurs = sira > 0 ? roundUp(BAZ_ING * (100 - indPct) / 100) : roundUp(BAZ_ING);
        var bursInd = BAZ_ING - afterBurs;
        var earlyInd = early ? (afterBurs - roundUp(afterBurs * 0.90)) : 0;
        var finalFee = afterBurs - earlyInd;
        return { final: finalFee, rows: [
            { kalem: 'Eğitim Hizmet Bedeli', tutar: BAZ_ING, indirim: false, bold: false },
            { kalem: 'Başarı Bursu İndirimi', tutar: -bursInd, indirim: true, bold: false },
            early ? { kalem: 'Erken Kayıt Avantajı', tutar: -earlyInd, indirim: true, bold: false } : null,
            { kalem: 'Net Eğitim Ücreti', tutar: finalFee, indirim: false, bold: true }
        ].filter(Boolean) };
    }
    function getEnglishNoBurs(early) {
        var earlyInd = early ? (BAZ_ING - roundUp(BAZ_ING * 0.90)) : 0;
        var finalFee = BAZ_ING - earlyInd;
        return { final: finalFee, rows: [
            { kalem: 'Eğitim Hizmet Bedeli', tutar: BAZ_ING, indirim: false, bold: false },
            early ? { kalem: 'Erken Kayıt Avantajı', tutar: -earlyInd, indirim: true, bold: false } : null,
            { kalem: 'Net Eğitim Ücreti', tutar: finalFee, indirim: false, bold: true }
        ].filter(Boolean) };
    }
    function getGermanSimple(early) {
        var hedef50 = roundUp(BAZ_ALM * 0.50);
        var almEkInd = BAZ_ALM - hedef50;
        var hedef60 = roundUp(BAZ_ALM * 0.40);
        var earlyInd = early ? Math.max(0, hedef50 - hedef60) : 0;
        var finalFee = hedef50 - earlyInd;
        return { final: finalFee, rows: [
            { kalem: 'Eğitim Hizmet Bedeli', tutar: BAZ_ALM, indirim: false, bold: false },
            { kalem: 'İkinci Dil Özel İndirimi', tutar: -almEkInd, indirim: true, bold: false },
            early ? { kalem: 'Erken Kayıt Avantajı', tutar: -earlyInd, indirim: true, bold: false } : null,
            { kalem: 'Net Eğitim Ücreti', tutar: finalFee, indirim: false, bold: true }
        ].filter(Boolean) };
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
        return { final: finalFee, rows: [
            { kalem: 'Eğitim Hizmet Bedeli', tutar: BAZ_ALM, indirim: false, bold: false },
            { kalem: 'Başarı Bursu İndirimi', tutar: -bursInd, indirim: true, bold: false },
            { kalem: 'İlave İndirim', tutar: -ekInd, indirim: true, bold: false },
            early ? { kalem: 'Erken Kayıt Avantajı', tutar: -earlyInd, indirim: true, bold: false } : null,
            { kalem: 'Net Eğitim Ücreti', tutar: finalFee, indirim: false, bold: true }
        ].filter(Boolean) };
    }

    function eldenHesapla() {
        var cbIng = document.getElementById('elden-cb-ingilizce');
        var cbAlm = document.getElementById('elden-cb-almanca');
        var cbErken = document.getElementById('elden-cb-erken');
        var cbPesin = document.getElementById('elden-cb-pesin-indirim');
        if (!cbIng || !cbAlm) return;
        var erken = (cbErken && cbErken.disabled) ? (document.getElementById('elden-erken').value === '1') : !!(cbErken && cbErken.checked);
        var secEnglish = !!(cbIng && cbIng.checked);
        var secGerman = !!(cbAlm && cbAlm.checked);
        var pesinIndirim = !!(cbPesin && cbPesin.checked);

        if (!cbErken || !cbErken.disabled) document.getElementById('elden-erken').value = erken ? '1' : '0';
        document.getElementById('elden-ingilizce').value = secEnglish ? '1' : '0';
        document.getElementById('elden-almanca').value = secGerman ? '1' : '0';
        var pesinInput = document.getElementById('elden-pesin-indirimi');
        if (pesinInput) pesinInput.value = pesinIndirim ? '1' : '0';

        var thead = document.getElementById('elden-thead');
        var tbody = document.getElementById('elden-tbody-kurs');
        var toplamEl = document.getElementById('elden-kurs-toplam');
        var maxTaksitEl = document.getElementById('elden-max-taksit-metin');
        var taksitSelect = document.getElementById('elden-taksit-select');
        var taksitWrap = document.getElementById('elden-taksit-tablo-wrap');
        var taksitTbody = document.getElementById('elden-taksit-tbody');
        var btn = document.getElementById('elden-onayla-btn');

        if (!secEnglish && !secGerman) {
            thead.innerHTML = '<th>Kalemler & Hizmetler</th><th class="text-end">Tutar (TL)</th>';
            tbody.innerHTML = '<tr><td colspan="2" class="text-center text-muted py-4">Lütfen yukarıdan eğitim programı seçiniz.</td></tr>';
            if (toplamEl) toplamEl.textContent = '—';
            if (maxTaksitEl) maxTaksitEl.textContent = '1';
            if (taksitSelect) { taksitSelect.innerHTML = '<option value="1">Peşin Ödeme (1 Taksit)</option>'; document.getElementById('elden-taksit-sayisi').value = '1'; }
            if (taksitWrap) taksitWrap.style.display = 'none';
            if (btn) btn.disabled = true;
            var tbodyKitap = document.getElementById('elden-tbody-kitap');
            if (tbodyKitap) tbodyKitap.innerHTML = '<tr><td colspan="2" class="text-center text-muted py-3 small">Lütfen soldaki formdan eğitim programı seçiniz.</td></tr>';
            return;
        }

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

        var englishNet = kursByLang.english ? (kursByLang.english.final || 0) : 0;
        var germanNet = kursByLang.german ? (kursByLang.german.final || 0) : 0;
        var toplamDers = englishNet + germanNet;

        var kursHeaders = [];
        if (secEnglish) kursHeaders.push('İngilizce');
        if (secGerman) kursHeaders.push('Almanca');
        kursHeaders.push('TOPLAM');

        var kursRowsMap = {};
        function mergeRows(langKey, block) {
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
        mergeRows('english', kursByLang.english);
        mergeRows('german', kursByLang.german);
        var kursRows = Object.keys(kursRowsMap).map(function(k) { return kursRowsMap[k]; });
        var idareIndirimi = 0;
        var idareInput = document.getElementById('elden-idare-indirimi');
        if (idareInput) {
            idareIndirimi = parseInt(idareInput.value, 10) || 0;
            if (idareIndirimi < 0) idareIndirimi = 0;
            if (idareIndirimi > toplamDers) idareIndirimi = toplamDers;
            if(idareIndirimi > 0) {
                kursRows.push({ kalem: 'Özel İndirim (Yönetim)', english: 0, german: 0, bold: false, indirim: true, totalOverride: -idareIndirimi });
            }
        }
        var order = { 'Eğitim Hizmet Bedeli': 1, 'Başarı Bursu İndirimi': 2, 'İkinci Dil Özel İndirimi': 3, 'İlave İndirim': 3, 'Erken Kayıt Avantajı': 4, 'Net Eğitim Ücreti': 5, 'Özel İndirim (Yönetim)': 6 };
        kursRows.sort(function(a, b) { return (order[a.kalem] || 99) - (order[b.kalem] || 99); });

        if (idareIndirimi > 0) {
            // İdare indirimi toplamdan düşer; önce İngilizce, artarsa Almanca kaleminden düşülür.
            var idareKalan = idareIndirimi;
            var dusEng = Math.min(idareKalan, englishNet);
            englishNet -= dusEng;
            idareKalan -= dusEng;
            if (idareKalan > 0) {
                var dusAlm = Math.min(idareKalan, germanNet);
                germanNet -= dusAlm;
                idareKalan -= dusAlm;
            }
        }
        toplamDers = englishNet + germanNet;
        if (toplamDers < 0) toplamDers = 0;
        var pesinIndirimTutar = 0;
        if (pesinIndirim && englishNet > 0) {
            // Peşin indirimi yalnız İngilizce net tutara uygulanır.
            var pesinSonrasi = Math.ceil((englishNet * 0.95) / 100) * 100;
            if (pesinSonrasi < 0) pesinSonrasi = 0;
            pesinIndirimTutar = englishNet - pesinSonrasi;
            if (pesinIndirimTutar > 0) {
                kursRows.push({ kalem: 'Peşin Ödeme İndirimi (%5)', english: -pesinIndirimTutar, german: 0, bold: false, indirim: true, totalOverride: -pesinIndirimTutar });
            }
            englishNet = pesinSonrasi;
        }
        toplamDers = englishNet + germanNet;
        kursRows = kursRows.filter(function(r) { return r.kalem !== 'Net Eğitim Ücreti'; });
        kursRows.push({ kalem: 'Net Eğitim Ücreti', english: englishNet, german: germanNet, bold: true, indirim: false, totalOverride: toplamDers });
        order['Peşin Ödeme İndirimi (%5)'] = 7;
        order['Net Eğitim Ücreti'] = 8;
        kursRows.sort(function(a, b) { return (order[a.kalem] || 99) - (order[b.kalem] || 99); });

        thead.innerHTML = '<th>Kalemler & Hizmetler</th>' + kursHeaders.map(function(h) { return '<th class="text-end">' + h + '</th>'; }).join('');
        tbody.innerHTML = kursRows.map(function(r) {
            var total = r.totalOverride !== undefined ? r.totalOverride : ((secEnglish ? r.english : 0) + (secGerman ? r.german : 0));
            var cls = r.bold ? ' class="toplam-satir"' : '';
            var cols = '';
            if (secEnglish) cols += '<td class="text-end' + (r.english < 0 ? ' text-success' : '') + '">' + fmt(r.english) + '</td>';
            if (secGerman) cols += '<td class="text-end' + (r.german < 0 ? ' text-success' : '') + '">' + fmt(r.german) + '</td>';
            cols += '<td class="text-end' + (total < 0 ? ' text-success fw-bold' : '') + '">' + fmt(total) + '</td>';
            return '<tr' + cls + '><td>' + r.kalem + '</td>' + cols + '</tr>';
        }).join('');

        if (toplamEl) toplamEl.textContent = toplamDers.toLocaleString('tr-TR', { maximumFractionDigits: 0 });

        var indirimOzetEl = document.getElementById('elden-indirim-ozet');
        if (indirimOzetEl) {
            if (idareIndirimi > 0 || pesinIndirimTutar > 0) {
                var araToplam = toplamDers + idareIndirimi + pesinIndirimTutar;
                var parcalar = ['İndirim Öncesi: <strong>' + araToplam.toLocaleString('tr-TR', { maximumFractionDigits: 0 }) + '</strong> TL'];
                if (idareIndirimi > 0) parcalar.push('Özel İndirim: <strong class="text-success">−' + idareIndirimi.toLocaleString('tr-TR', { maximumFractionDigits: 0 }) + '</strong> TL');
                if (pesinIndirimTutar > 0) parcalar.push('Peşin İndirimi: <strong class="text-success">−' + pesinIndirimTutar.toLocaleString('tr-TR', { maximumFractionDigits: 0 }) + '</strong> TL');
                indirimOzetEl.innerHTML = parcalar.join(' | ');
                indirimOzetEl.style.display = 'block';
            } else {
                indirimOzetEl.style.display = 'none';
                indirimOzetEl.innerHTML = '';
            }
        }

        var maxTaksit = toplamDers > 0 ? Math.round(toplamDers / ELDEN_TAKSIT_BIRIM) : 1;
        if (maxTaksit < 1) maxTaksit = 1;
        var bugun = new Date();
        var baslangicAy = new Date(bugun.getFullYear(), bugun.getMonth(), 1);
        var may2027 = new Date(2027, 4, 1);
        var maxAySayisi = (may2027.getFullYear() - baslangicAy.getFullYear()) * 12 + (may2027.getMonth() - baslangicAy.getMonth()) + 1;
        if (maxAySayisi < 1) maxAySayisi = 1;
        if (maxAySayisi < maxTaksit) maxTaksit = maxAySayisi;
        if (maxTaksitEl) maxTaksitEl.textContent = maxTaksit;

        if (taksitSelect) {
            var currentVal = parseInt(taksitSelect.value, 10) || 1;
            taksitSelect.innerHTML = '';
            for (var n = 1; n <= maxTaksit; n++) {
                var opt = document.createElement('option');
                opt.value = n;
                opt.textContent = n === 1 ? 'Peşin Ödeme (1 Taksit)' : n + ' Taksit';
                if (n === currentVal || (n === 1 && currentVal > maxTaksit)) opt.selected = true;
                taksitSelect.appendChild(opt);
            }
            if (currentVal > maxTaksit) taksitSelect.value = maxTaksit;
            document.getElementById('elden-taksit-sayisi').value = taksitSelect.value;

            var n = parseInt(taksitSelect.value, 10) || 1;
            if (n > 1 && taksitTbody && taksitWrap) {
                var base = Math.ceil(toplamDers / n / 100) * 100;
                if ((n - 1) * base > toplamDers) base = Math.floor(toplamDers / (n - 1) / 100) * 100;
                taksitTbody.innerHTML = '';
                for (var i = 1; i <= n; i++) {
                    var tarih = new Date(baslangicAy.getFullYear(), baslangicAy.getMonth() + (i - 1), 1);
                    if (tarih > may2027) tarih = new Date(2027, 4, 1);
                    var tutar = i < n ? base : (toplamDers - (n - 1) * base);
                    var tarihMetin = tarih.toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' });
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td>' + i + '. Taksit</td><td>' + tarihMetin + '</td><td class="text-end fw-bold">' + tutar.toLocaleString('tr-TR', { maximumFractionDigits: 0 }) + '</td>';
                    taksitTbody.appendChild(tr);
                }
                taksitWrap.style.display = 'block';
            } else if (taksitWrap) {
                taksitWrap.style.display = 'none';
            }
        }
        var sozlesmeCb = document.getElementById('elden-sozlesme-onay');
        if (btn) btn.disabled = !sozlesmeCb || !sozlesmeCb.checked;

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
        var tbodyKitap = document.getElementById('elden-tbody-kitap');
        if (tbodyKitap) {
            tbodyKitap.innerHTML = kitapRows.map(function(r) {
                var cls = r.bold ? ' class="bold-row"' : (r.indirim ? ' class="indirim-row"' : '');
                var tutarStr = r.tutar < 0 ? '−' + Math.abs(r.tutar).toLocaleString('tr-TR', { maximumFractionDigits: 0 }) : r.tutar.toLocaleString('tr-TR', { maximumFractionDigits: 0 });
                return '<tr' + cls + '><td>' + r.kalem + '</td><td class="text-end">' + tutarStr + '</td></tr>';
            }).join('');
        }
    }

    function eldenTaksitGuncelle() {
        var taksitSelect = document.getElementById('elden-taksit-select');
        var toplamEl = document.getElementById('elden-kurs-toplam');
        if (!taksitSelect || !toplamEl) return;
        var toplamDers = parseInt(toplamEl.textContent.replace(/\D/g, ''), 10) || 0;
        if (toplamDers === 0) return;
        document.getElementById('elden-taksit-sayisi').value = taksitSelect.value;
        var n = parseInt(taksitSelect.value, 10) || 1;
        var taksitTbody = document.getElementById('elden-taksit-tbody');
        var taksitWrap = document.getElementById('elden-taksit-tablo-wrap');
        if (n > 1 && taksitTbody && taksitWrap) {
            var base = Math.ceil(toplamDers / n / 100) * 100;
            if ((n - 1) * base > toplamDers) base = Math.floor(toplamDers / (n - 1) / 100) * 100;
            var bugun = new Date();
            var baslangicAy = new Date(bugun.getFullYear(), bugun.getMonth(), 1);
            var may2027 = new Date(2027, 4, 1);
            taksitTbody.innerHTML = '';
            for (var i = 1; i <= n; i++) {
                var tarih = new Date(baslangicAy.getFullYear(), baslangicAy.getMonth() + (i - 1), 1);
                if (tarih > may2027) tarih = new Date(2027, 4, 1);
                var tutar = i < n ? base : (toplamDers - (n - 1) * base);
                var tarihMetin = tarih.toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' });
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + i + '. Taksit</td><td>' + tarihMetin + '</td><td class="text-end fw-bold">' + tutar.toLocaleString('tr-TR', { maximumFractionDigits: 0 }) + '</td>';
                taksitTbody.appendChild(tr);
            }
            taksitWrap.style.display = 'block';
        } else if (taksitWrap) {
            taksitWrap.style.display = 'none';
        }
    }

    var form = document.getElementById('elden-odeme-form');
    if (form) {
        ['elden-cb-ingilizce', 'elden-cb-almanca', 'elden-cb-erken', 'elden-cb-pesin-indirim', 'elden-sozlesme-onay', 'elden-idare-indirimi'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener(el.id === 'elden-idare-indirimi' ? 'input' : 'change', eldenHesapla);
        });
        var taksitSel = document.getElementById('elden-taksit-select');
        if (taksitSel) taksitSel.addEventListener('change', function() {
            document.getElementById('elden-taksit-sayisi').value = taksitSel.value;
            eldenTaksitGuncelle();
        });
        form.addEventListener('submit', function() {
            var ing = document.getElementById('elden-ingilizce');
            var alm = document.getElementById('elden-almanca');
            var pesin = document.getElementById('elden-pesin-indirimi');
            var cbErken = document.getElementById('elden-cb-erken');
            if (ing) ing.value = document.getElementById('elden-cb-ingilizce').checked ? '1' : '0';
            if (alm) alm.value = document.getElementById('elden-cb-almanca').checked ? '1' : '0';
            if (pesin) {
                var cbPesin = document.getElementById('elden-cb-pesin-indirim');
                pesin.value = cbPesin && cbPesin.checked ? '1' : '0';
            }
            if (!cbErken || !cbErken.disabled) document.getElementById('elden-erken').value = cbErken && cbErken.checked ? '1' : '0';
            document.getElementById('elden-taksit-sayisi').value = document.getElementById('elden-taksit-select').value;
        });
        eldenHesapla();
    }
})();
</script>
<?php endif; ?>
</body>
</html>