<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/personel_log.php';

mysqli_set_charset($conn, "utf8mb4");

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erken_kayit_basvurular (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    paket ENUM('yaz','okul') NOT NULL,
    veli_ad VARCHAR(100) NOT NULL,
    veli_soyad VARCHAR(100) NOT NULL,
    veli_tel VARCHAR(30) NOT NULL,
    veli_email VARCHAR(150) NOT NULL,
    ogrenci_ad VARCHAR(100) NOT NULL,
    ogrenci_soyad VARCHAR(100) NOT NULL,
    kurs_tutar INT UNSIGNED NOT NULL DEFAULT 0,
    materyal_tutar INT UNSIGNED NOT NULL DEFAULT 0,
    toplam_tutar INT UNSIGNED NOT NULL DEFAULT 0,
    odeme_durumu ENUM('bekliyor','success','failed') NOT NULL DEFAULT 'bekliyor',
    merchant_oid VARCHAR(64) NULL DEFAULT NULL,
    paytr_payment_type VARCHAR(50) NULL DEFAULT NULL,
    test_mode TINYINT(1) NOT NULL DEFAULT 0,
    failed_reason_code VARCHAR(30) NULL DEFAULT NULL,
    failed_reason_msg VARCHAR(255) NULL DEFAULT NULL,
    odendi_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_merchant_oid (merchant_oid),
    KEY idx_odeme_durumu (odeme_durumu),
    KEY idx_veli_tel (veli_tel),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$ek_basvuru_cols = [];
$cr_ek = @mysqli_query($conn, "SHOW COLUMNS FROM erken_kayit_basvurular");
if ($cr_ek) {
    while ($c = mysqli_fetch_assoc($cr_ek)) {
        $ek_basvuru_cols[$c['Field']] = true;
    }
}
if (empty($ek_basvuru_cols['kayit_kaynagi'])) {
    @mysqli_query($conn, "ALTER TABLE erken_kayit_basvurular ADD COLUMN kayit_kaynagi ENUM('online','yuz_yuze') NOT NULL DEFAULT 'online'");
}
if (empty($ek_basvuru_cols['fiyat_snapshot_json'])) {
    @mysqli_query($conn, "ALTER TABLE erken_kayit_basvurular ADD COLUMN fiyat_snapshot_json LONGTEXT NULL");
}

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS erken_kayit_iletisim_talepleri (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad_soyad VARCHAR(150) NOT NULL,
    telefon VARCHAR(30) NOT NULL,
    mesaj TEXT NOT NULL,
    durum ENUM('yeni','donuldu','kapali') NOT NULL DEFAULT 'yeni',
    personel_notu TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_telefon (telefon),
    KEY idx_durum (durum),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function formatPhoneForWA($phone) {
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    if(strlen($cleaned) == 10) return '90' . $cleaned;
    if(strlen($cleaned) == 11 && strpos($cleaned, '0') === 0) return '9' . $cleaned;
    return $cleaned;
}

/** Kampanya kurs tutarı (kampanya_odeme.php ile aynı). */
function erken_kampanya_kurs_tutar(string $paket): int {
    return $paket === 'okul' ? 56700 : 25000;
}

/** Bilgi amaçlı materyal tutarı (kampanya_odeme.php ile aynı). */
function erken_kampanya_materyal_tutar(string $paket): int {
    return $paket === 'okul' ? 22000 : 7000;
}

function erken_tl_ceil100(int $tl): int {
    if ($tl <= 0) {
        return 0;
    }
    return (int)ceil($tl / 100) * 100;
}

/**
 * Peşin %5 ve isteğe bağlı ek %5 (indirimli tutar üzerinden), tutarlar 100 TL’ye yukarı yuvarlanır.
 *
 * @return array{kurs:int,satirlar:list<array{kalem:string,tutar:int}>}
 */
function erken_yuz_yuze_kurs_hesapla(string $paket, bool $pesin_indirimi): array {
    $kurs = erken_kampanya_kurs_tutar($paket);
    $satirlar = [['kalem' => 'Kampanya kurs ücreti', 'tutar' => $kurs]];
    if ($pesin_indirimi) {
        $yeni = (int)ceil(($kurs * 0.95) / 100) * 100;
        $satirlar[] = ['kalem' => 'Peşin ödeme indirimi (%5)', 'tutar' => $yeni - $kurs];
        $kurs = $yeni;
    }
    $kurs = erken_tl_ceil100($kurs);
    return ['kurs' => $kurs, 'satirlar' => $satirlar];
}

function erken_yuz_yuze_max_taksit(int $kurs_tutar): int {
    $max_taksit = $kurs_tutar > 0 ? (int)round($kurs_tutar / 6700) : 1;
    if ($max_taksit < 1) {
        $max_taksit = 1;
    }
    $simdi = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
    $baslangic_ay = $simdi->modify('first day of this month');
    $may_2027_lim = new DateTimeImmutable('2027-05-01', new DateTimeZone('Europe/Istanbul'));
    if ($baslangic_ay <= $may_2027_lim) {
        $aralik = $baslangic_ay->diff($may_2027_lim);
        $max_ay_sayisi = $aralik->y * 12 + $aralik->m + 1;
        if ($max_ay_sayisi < $max_taksit) {
            $max_taksit = max(1, (int)$max_ay_sayisi);
        }
    }
    return $max_taksit;
}

/**
 * @return list<array{no:int,tarih:string,tarih_metin:string,tutar:int}>
 */
function erken_yuz_yuze_taksit_plan(int $kurs_tutar, int $taksit_sayisi): array {
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
        if ($tarih > $may_2027) {
            $tarih = $may_2027;
        }
        $tutar = ($i < $taksit_sayisi) ? $base : ($kurs_tutar - ($taksit_sayisi - 1) * $base);
        $ay_metin = $ay_adlari[(int)$tarih->format('n')] . ' ' . $tarih->format('Y');
        $taksit_plan[] = ['no' => $i, 'tarih' => $tarih->format('Y-m-d'), 'tarih_metin' => $ay_metin, 'tutar' => $tutar];
    }
    return $taksit_plan;
}

$ok = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'iletisim_guncelle') {
    $id = (int)($_POST['id'] ?? 0);
    $durum = trim((string)($_POST['durum'] ?? 'yeni'));
    $not = trim((string)($_POST['personel_notu'] ?? ''));
    $izinli = ['yeni', 'donuldu', 'kapali'];
    if ($id > 0 && in_array($durum, $izinli, true)) {
        $st = mysqli_prepare($conn, "UPDATE erken_kayit_iletisim_talepleri SET durum = ?, personel_notu = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        if ($st) {
            mysqli_stmt_bind_param($st, 'ssi', $durum, $not, $id);
            if (mysqli_stmt_execute($st)) $ok = 'İletişim talebi başarıyla güncellendi.';
            else $err = 'Güncelleme sırasında hata oluştu.';
            mysqli_stmt_close($st);
        } else {
            $err = 'Güncelleme hazırlanamadı.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'yuz_yuze_kaydet') {
    $paket = trim((string)($_POST['paket'] ?? ''));
    $veli_ad = trim((string)($_POST['veli_ad'] ?? ''));
    $veli_soyad = trim((string)($_POST['veli_soyad'] ?? ''));
    $veli_tel_raw = trim((string)($_POST['veli_tel'] ?? ''));
    $veli_email = trim((string)($_POST['veli_email'] ?? ''));
    $ogrenci_ad = trim((string)($_POST['ogrenci_ad'] ?? ''));
    $ogrenci_soyad = trim((string)($_POST['ogrenci_soyad'] ?? ''));
    $pesin_indirimi = isset($_POST['pesin_indirimi']) && (int)$_POST['pesin_indirimi'] === 1;
    $taksit_sayisi = max(1, (int)($_POST['taksit_sayisi'] ?? 1));
    $sozlesme = isset($_POST['yuz_yuze_sozlesme_onay']) && (int)$_POST['yuz_yuze_sozlesme_onay'] === 1;

    $veli_tel = preg_replace('/\D+/', '', $veli_tel_raw);
    if (strlen($veli_tel) === 10) {
        $veli_tel = '0' . $veli_tel;
    }

    if (!$sozlesme) {
        $err = 'Yüz yüze kayıt için veli onayının beyan edilmesi zorunludur.';
    } elseif (!in_array($paket, ['yaz', 'okul'], true) || $veli_ad === '' || $veli_soyad === '' || $ogrenci_ad === '' || $ogrenci_soyad === '') {
        $err = 'Lütfen paket ve tüm zorunlu alanları doldurun.';
    } elseif (!filter_var($veli_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Geçerli bir e-posta adresi giriniz.';
    } elseif (strlen($veli_tel) < 10) {
        $err = 'Geçerli bir telefon numarası giriniz.';
    } else {
        $hesap = erken_yuz_yuze_kurs_hesapla($paket, $pesin_indirimi);
        $kurs_net = $hesap['kurs'];
        $max_taksit = erken_yuz_yuze_max_taksit($kurs_net);
        if ($taksit_sayisi > $max_taksit) {
            $taksit_sayisi = $max_taksit;
        }
        $materyal = erken_kampanya_materyal_tutar($paket);
        $taksit_plan = erken_yuz_yuze_taksit_plan($kurs_net, $taksit_sayisi);
        $odeme_tipi = $taksit_sayisi > 1 ? 'elden_taksit' : 'elden';

        $snapshot = [
            'kampanya' => 'erken_kayit',
            'paket' => $paket,
            'satirlar' => $hesap['satirlar'],
            'kurs_net' => $kurs_net,
            'materyal_bilgi' => $materyal,
            'pesin_indirimi' => $pesin_indirimi,
            'taksit_sayisi' => $taksit_sayisi,
            'taksit_plan' => $taksit_plan,
            'eldan' => true,
            'personel' => $_SESSION['personel_adi'] ?? '',
        ];
        $snapshot_json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        if ($snapshot_json === false) {
            $snapshot_json = '{}';
        }

        $ins = mysqli_prepare($conn, "INSERT INTO erken_kayit_basvurular
            (paket, veli_ad, veli_soyad, veli_tel, veli_email, ogrenci_ad, ogrenci_soyad, kurs_tutar, materyal_tutar, toplam_tutar, odeme_durumu, paytr_payment_type, odendi_at, kayit_kaynagi, fiyat_snapshot_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', ?, NOW(), 'yuz_yuze', ?)");
        if (!$ins) {
            $err = 'Kayıt hazırlanamadı (veritabanı).';
        } else {
            mysqli_stmt_bind_param(
                $ins,
                'sssssssiiiss',
                $paket,
                $veli_ad,
                $veli_soyad,
                $veli_tel,
                $veli_email,
                $ogrenci_ad,
                $ogrenci_soyad,
                $kurs_net,
                $materyal,
                $kurs_net,
                $odeme_tipi,
                $snapshot_json
            );
            if (!mysqli_stmt_execute($ins)) {
                $err = 'Kayıt kaydedilemedi.';
                mysqli_stmt_close($ins);
            } else {
                $new_id = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($ins);
                $merchant_oid = 'EKYY' . $new_id . 'T' . time() . substr(preg_replace('/[^a-zA-Z0-9]/', '', uniqid('', true)), -5);
                if (strlen($merchant_oid) > 64) {
                    $merchant_oid = substr($merchant_oid, 0, 64);
                }
                $moid_esc = mysqli_real_escape_string($conn, $merchant_oid);
                mysqli_query($conn, "UPDATE erken_kayit_basvurular SET merchant_oid = '$moid_esc' WHERE id = $new_id LIMIT 1");
                @personel_log_ekle($conn, 'erken_kayit_kampanyasi.php', 'yuz_yuze_kaydet', [
                    'id' => $new_id,
                    'paket' => $paket,
                    'kurs_net' => $kurs_net,
                    'taksit_sayisi' => $taksit_sayisi,
                ]);
                header('Location: erken_kayit_kampanyasi.php?sekme=odemeler&kaydedildi=1');
                exit;
            }
        }
    }
}

$sekme = $_GET['sekme'] ?? 'odemeler';
if (!in_array($sekme, ['odemeler', 'iletisim', 'yuz_yuze'], true)) {
    $sekme = 'odemeler';
}
if (isset($_GET['kaydedildi']) && (string)$_GET['kaydedildi'] === '1') {
    $ok = 'Yüz yüze kayıt oluşturuldu; kayıt ödemeler listesine eklendi.';
}

// Ödemeler Filtre ve Veri Çekme
$f_odeme_durum = trim((string)($_GET['odeme_durum'] ?? ''));
$f_paket = trim((string)($_GET['paket'] ?? ''));
$f_q = trim((string)($_GET['q'] ?? ''));
$f_kaynak = trim((string)($_GET['kaynak'] ?? ''));

$where = [];
if (in_array($f_odeme_durum, ['bekliyor', 'success', 'failed'], true)) {
    $where[] = "odeme_durumu = '" . mysqli_real_escape_string($conn, $f_odeme_durum) . "'";
}
if (in_array($f_paket, ['yaz', 'okul'], true)) {
    $where[] = "paket = '" . mysqli_real_escape_string($conn, $f_paket) . "'";
}
if ($f_kaynak === 'online') {
    $where[] = "kayit_kaynagi = 'online'";
}
if ($f_kaynak === 'yuz_yuze') {
    $where[] = "kayit_kaynagi = 'yuz_yuze'";
}
if ($f_q !== '') {
    $q = mysqli_real_escape_string($conn, $f_q);
    $where[] = "(CONCAT(veli_ad, ' ', veli_soyad) LIKE '%$q%' OR CONCAT(ogrenci_ad, ' ', ogrenci_soyad) LIKE '%$q%' OR veli_tel LIKE '%$q%' OR veli_email LIKE '%$q%' OR merchant_oid LIKE '%$q%')";
}
$sqlOdemeler = "SELECT * FROM erken_kayit_basvurular " . ($where ? ("WHERE " . implode(' AND ', $where)) : '') . " ORDER BY id DESC LIMIT 300";
$odemeler = [];
$statOdemeler = ['toplam' => 0, 'success' => 0, 'bekliyor' => 0, 'failed' => 0, 'yuz_yuze' => 0];

$rsO = mysqli_query($conn, $sqlOdemeler);
if ($rsO) {
    while ($r = mysqli_fetch_assoc($rsO)) {
        $odemeler[] = $r;
        $statOdemeler['toplam']++;
        if (isset($statOdemeler[$r['odeme_durumu']])) {
            $statOdemeler[$r['odeme_durumu']]++;
        }
        if (($r['kayit_kaynagi'] ?? '') === 'yuz_yuze') {
            $statOdemeler['yuz_yuze']++;
        }
    }
}

// İletişim Filtre ve Veri Çekme
$f_iletisim_durum = trim((string)($_GET['iletisim_durum'] ?? ''));
$f_iq = trim((string)($_GET['iq'] ?? ''));
$whereI = [];
if (in_array($f_iletisim_durum, ['yeni', 'donuldu', 'kapali'], true)) $whereI[] = "durum = '" . mysqli_real_escape_string($conn, $f_iletisim_durum) . "'";
if ($f_iq !== '') {
    $iq = mysqli_real_escape_string($conn, $f_iq);
    $whereI[] = "(ad_soyad LIKE '%$iq%' OR telefon LIKE '%$iq%' OR mesaj LIKE '%$iq%')";
}
$sqlIletisim = "SELECT * FROM erken_kayit_iletisim_talepleri " . ($whereI ? ("WHERE " . implode(' AND ', $whereI)) : '') . " ORDER BY id DESC LIMIT 300";
$iletisimler = [];
$statIletisim = ['toplam' => 0, 'yeni' => 0, 'donuldu' => 0, 'kapali' => 0];

$rsI = mysqli_query($conn, $sqlIletisim);
if ($rsI) {
    while ($r = mysqli_fetch_assoc($rsI)) {
        $iletisimler[] = $r;
        $statIletisim['toplam']++;
        if(isset($statIletisim[$r['durum']])) $statIletisim[$r['durum']]++;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Erken Kayıt Yönetim Paneli</title>
    <link rel="icon" type="image/x-icon" href="../resimler/logoGenclik.jpg">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
        
        :root {
            --bg-body: #f3f4f6;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --surface: #ffffff;
            --border: #e5e7eb;
            --text-main: #111827;
            --text-muted: #4b5563;
            --success-bg: #dcfce7;
            --success-text: #166534;
            --warning-bg: #fef9c3;
            --warning-text: #854d0e;
            --danger-bg: #fee2e2;
            --danger-text: #991b1b;
            --info-bg: #dbeafe;
            --info-text: #1e40af;
        }

        body { 
            background-color: var(--bg-body); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main); 
            -webkit-font-smoothing: antialiased;
            font-size: 16px; /* Base font size increased */
        }
        
        /* Layout & Header */
        .page-header {
            background: var(--surface);
            padding: 1.25rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1020;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        
        .header-title {
            font-size: 1.5rem; /* Increased */
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Modern Tabs */
        .nav-pills-custom {
            background: var(--surface);
            padding: 0.5rem;
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            display: inline-flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }
        .nav-pills-custom .nav-link {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 1.05rem; /* Increased */
            padding: 0.75rem 1.5rem; /* Increased */
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        .nav-pills-custom .nav-link:hover {
            color: var(--text-main);
            background: var(--bg-body);
        }
        .nav-pills-custom .nav-link.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
        }

        /* KPI Cards */
        .kpi-wrapper {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .kpi-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .kpi-icon {
            width: 3.5rem; /* Increased */
            height: 3.5rem; /* Increased */
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem; /* Increased */
        }
        .kpi-info h6 { margin: 0; font-size: 1rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .kpi-info h3 { margin: 0; font-size: 1.75rem; font-weight: 700; color: var(--text-main); line-height: 1.2; }

        /* Toolbar / Filter Area */
        .toolbar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 1.5rem; /* Increased */
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }
        .toolbar-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1.25rem; /* Increased */
            align-items: flex-end;
        }
        .form-group-custom { flex: 1; min-width: 200px; margin: 0; }
        .form-group-custom label { font-size: 0.95rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.5rem; display: block; }
        .form-control-custom { 
            height: 48px; /* Increased */
            border-radius: 0.5rem; 
            border: 1px solid var(--border); 
            padding: 0.5rem 1rem; 
            font-size: 1.05rem; /* Increased */
            width: 100%;
            transition: border-color 0.2s;
        }
        .form-control-custom:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        .toolbar-actions { display: flex; gap: 0.75rem; }

        /* General Buttons */
        .btn-custom {
            height: 48px; /* Increased */
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0 1.5rem; /* Increased */
            font-weight: 600;
            font-size: 1.05rem; /* Increased */
            border-radius: 0.5rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        .btn-primary-custom { background: var(--primary); color: white; }
        .btn-primary-custom:hover { background: var(--primary-hover); color: white; }
        .btn-light-custom { background: white; color: var(--text-main); border: 1px solid var(--border); }
        .btn-light-custom:hover { background: var(--bg-body); }
        .btn-success-custom { background: #10b981; color: white; }
        .btn-success-custom:hover { background: #059669; color: white; }

        /* Data Table */
        .table-wrapper {
            background: var(--surface);
            border-radius: 1rem;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .table-modern { width: 100%; margin: 0; border-collapse: collapse; }
        .table-modern th {
            background: #f9fafb;
            padding: 1.25rem; /* Increased */
            font-size: 0.9rem; /* Increased */
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }
        .table-modern td {
            padding: 1.25rem; /* Increased */
            vertical-align: middle;
            border-bottom: 1px solid var(--border);
            font-size: 1.05rem; /* Increased */
        }
        .table-modern tbody tr:hover { background-color: #f8fafc; }
        .table-modern tbody tr:last-child td { border-bottom: none; }

        /* Badges */
        .badge-modern {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.45rem 0.85rem; /* Increased */
            border-radius: 9999px;
            font-size: 0.85rem; /* Increased */
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .bg-success-soft { background: var(--success-bg); color: var(--success-text); }
        .bg-warning-soft { background: var(--warning-bg); color: var(--warning-text); }
        .bg-danger-soft { background: var(--danger-bg); color: var(--danger-text); }
        .bg-info-soft { background: var(--info-bg); color: var(--info-text); }
        .bg-secondary-soft { background: #f3f4f6; color: #4b5563; }

        /* Action Buttons (WA, Call) */
        .action-circle {
            width: 42px; /* Increased */
            height: 42px; /* Increased */
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none !important;
            transition: transform 0.2s, background 0.2s;
            color: white;
            font-size: 1.15rem; /* Increased */
        }
        .action-circle:hover { transform: scale(1.1); color: white; }
        .wa-btn { background: #25D366; }
        .call-btn { background: var(--primary); }

        /* Contact Cards Grid */
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
            gap: 1.5rem;
        }
        .contact-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 1rem;
            display: flex;
            flex-direction: column;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: box-shadow 0.2s;
        }
        .contact-card:hover { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .contact-header {
            padding: 1.5rem; /* Increased */
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            background: #fcfcfd;
        }
        .contact-body {
            padding: 1.5rem; /* Increased */
            flex-grow: 1;
            font-size: 1.05rem; /* Increased */
            line-height: 1.6;
            color: #374151;
            background: white;
        }
        .contact-footer {
            padding: 1.5rem; /* Increased */
            background: #f9fafb;
            border-top: 1px solid var(--border);
        }
        
        /* Unified Input Group for Forms */
        .input-group-unified {
            display: flex;
            width: 100%;
            border-radius: 0.5rem;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        .input-group-unified .form-control-custom,
        .input-group-unified .btn-custom {
            border-radius: 0;
            border-right: 0;
            height: 48px; /* Increased */
        }
        .input-group-unified select.form-control-custom {
            border-top-left-radius: 0.5rem;
            border-bottom-left-radius: 0.5rem;
            width: 140px; /* Increased */
            flex-shrink: 0;
            background-color: white;
        }
        .input-group-unified input.form-control-custom {
            flex-grow: 1;
        }
        .input-group-unified .btn-custom {
            border-top-right-radius: 0.5rem;
            border-bottom-right-radius: 0.5rem;
            border-right: 1px solid #10b981; /* Match success button */
            margin: 0;
        }
        
        /* Utility */
        .text-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }

        /* Yüz yüze kayıt — düzen */
        .yy-shell { max-width: 1120px; margin: 0 auto 2.5rem; padding: 0 0.75rem; }
        .yy-intro { margin-bottom: 1.5rem; }
        .yy-intro h2 { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.5rem 0; color: var(--text-main); }
        .yy-intro p { margin: 0; font-size: 1.05rem; line-height: 1.55; color: var(--text-muted); max-width: 72ch; }
        .yy-form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 1.75rem 1.75rem 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.06), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
        }
        .yy-split { display: grid; gap: 2rem; align-items: start; }
        @media (min-width: 992px) {
            .yy-split { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
            .yy-col--calc { border-left: 1px solid var(--border); padding-left: 2rem; margin-left: 0; }
        }
        @media (max-width: 991px) {
            .yy-col--calc { border-top: 1px solid var(--border); padding-top: 1.75rem; }
        }
        .yy-section-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin: 0 0 1rem 0; }
        .yy-field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        @media (max-width: 640px) { .yy-field-grid { grid-template-columns: 1fr; } }
        .yy-field-grid .form-group-custom { margin: 0; min-width: 0; }
        .yy-tel-input { font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }
        .yy-col--calc .table-wrapper { max-width: none; }
        .yy-actions { margin-top: 0.5rem; }
        
        @media (max-width: 768px) {
            .contact-grid { grid-template-columns: 1fr; }
            .toolbar-form { flex-direction: column; align-items: stretch; }
            .toolbar-actions { flex-direction: column; }
            .btn-custom { width: 100%; }
            .input-group-unified { flex-direction: column; box-shadow: none; gap: 0.75rem; }
            .input-group-unified .form-control-custom, .input-group-unified .btn-custom {
                border-radius: 0.5rem !important;
                border: 1px solid var(--border) !important;
                width: 100% !important;
            }
        }
    </style>
</head>
<body>

<header class="page-header">
    <h1 class="header-title">
        <div style="background: var(--warning-bg); color: var(--warning-text); padding: 0.6rem; border-radius: 0.5rem; display: flex;">
            <i class="fas fa-bolt"></i>
        </div>
        Erken Kayıt Paneli
    </h1>
    <a href="index.php" class="btn-custom btn-light-custom">
        <i class="fas fa-arrow-left"></i> Ana Panele Dön
    </a>
</header>

<div class="container-fluid py-4 px-md-4">
    <?php if ($ok !== ''): ?>
        <div class="alert alert-success border-0 shadow-sm" style="background: var(--success-bg); color: var(--success-text); font-size: 1.05rem; padding: 1rem 1.25rem;">
            <i class="fas fa-check-circle mr-2"></i><?= h($ok) ?>
        </div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm" style="background: var(--danger-bg); color: var(--danger-text); font-size: 1.05rem; padding: 1rem 1.25rem;">
            <i class="fas fa-exclamation-triangle mr-2"></i><?= h($err) ?>
        </div>
    <?php endif; ?>

    <!-- Nav Pills -->
    <div class="nav-pills-custom">
        <a class="nav-link <?= $sekme === 'odemeler' ? 'active' : '' ?>" href="?sekme=odemeler">
            <i class="fas fa-credit-card mr-2"></i> Ödemeler
        </a>
        <a class="nav-link <?= $sekme === 'yuz_yuze' ? 'active' : '' ?>" href="?sekme=yuz_yuze">
            <i class="fas fa-handshake mr-2"></i> Yüz Yüze Kayıt
        </a>
        <a class="nav-link <?= $sekme === 'iletisim' ? 'active' : '' ?>" href="?sekme=iletisim">
            <i class="fas fa-headset mr-2"></i> İletişim Talepleri
            <?php if($statIletisim['yeni'] > 0): ?>
                <span class="badge badge-light text-danger ml-1 rounded-pill" style="font-size: 0.95rem; padding: 0.3em 0.6em;"><?= $statIletisim['yeni'] ?></span>
            <?php endif; ?>
        </a>
    </div>

    <?php if ($sekme === 'odemeler'): ?>
        
        <!-- Ödemeler KPI -->
        <div class="kpi-wrapper">
            <div class="kpi-card">
                <div class="kpi-icon bg-info-soft"><i class="fas fa-list"></i></div>
                <div class="kpi-info"><h6>Toplam Başvuru</h6><h3><?= $statOdemeler['toplam'] ?></h3></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon bg-success-soft"><i class="fas fa-check"></i></div>
                <div class="kpi-info"><h6>Başarılı Ödeme</h6><h3 class="text-success"><?= $statOdemeler['success'] ?></h3></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon bg-warning-soft"><i class="fas fa-clock"></i></div>
                <div class="kpi-info"><h6>Bekleyen</h6><h3 class="text-warning"><?= $statOdemeler['bekliyor'] ?></h3></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon bg-danger-soft"><i class="fas fa-times"></i></div>
                <div class="kpi-info"><h6>Başarısız</h6><h3 class="text-danger"><?= $statOdemeler['failed'] ?></h3></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon bg-secondary-soft"><i class="fas fa-handshake"></i></div>
                <div class="kpi-info"><h6>Yüz Yüze (Elden)</h6><h3><?= (int)$statOdemeler['yuz_yuze'] ?></h3></div>
            </div>
        </div>

        <!-- Ödemeler Toolbar -->
        <div class="toolbar">
            <form method="get" class="toolbar-form">
                <input type="hidden" name="sekme" value="odemeler">
                
                <div class="form-group-custom" style="flex: 0 1 200px;">
                    <label>Kayıt Kanalı</label>
                    <select name="kaynak" class="form-control-custom">
                        <option value="">Tümü</option>
                        <option value="online" <?= $f_kaynak === 'online' ? 'selected' : '' ?>>Online (PayTR)</option>
                        <option value="yuz_yuze" <?= $f_kaynak === 'yuz_yuze' ? 'selected' : '' ?>>Yüz yüze (elden)</option>
                    </select>
                </div>
                
                <div class="form-group-custom" style="flex: 0 1 200px;">
                    <label>Ödeme Durumu</label>
                    <select name="odeme_durum" class="form-control-custom">
                        <option value="">Tümü</option>
                        <option value="bekliyor" <?= $f_odeme_durum === 'bekliyor' ? 'selected' : '' ?>>Bekliyor</option>
                        <option value="success" <?= $f_odeme_durum === 'success' ? 'selected' : '' ?>>Başarılı</option>
                        <option value="failed" <?= $f_odeme_durum === 'failed' ? 'selected' : '' ?>>Başarısız</option>
                    </select>
                </div>
                
                <div class="form-group-custom" style="flex: 0 1 200px;">
                    <label>Paket Türü</label>
                    <select name="paket" class="form-control-custom">
                        <option value="">Tümü</option>
                        <option value="yaz" <?= $f_paket === 'yaz' ? 'selected' : '' ?>>Yaz Dönemi</option>
                        <option value="okul" <?= $f_paket === 'okul' ? 'selected' : '' ?>>Okul Dönemi</option>
                    </select>
                </div>
                
                <div class="form-group-custom">
                    <label>Arama (İsim, Tel, Sipariş No)</label>
                    <input type="text" name="q" value="<?= h($f_q) ?>" class="form-control-custom" placeholder="Detaylı arama yapın...">
                </div>
                
                <div class="toolbar-actions">
                    <button type="submit" class="btn-custom btn-primary-custom"><i class="fas fa-search"></i> Filtrele</button>
                    <a href="?sekme=odemeler" class="btn-custom btn-light-custom"><i class="fas fa-redo"></i> Temizle</a>
                    <button type="button" onclick="exportTableToCSV('odemeler.csv')" class="btn-custom btn-success-custom">
                        <i class="fas fa-file-excel"></i> İndir
                    </button>
                </div>
            </form>
        </div>

        <!-- Ödemeler Tablo -->
        <div class="table-wrapper">
            <div class="table-responsive">
                <table class="table-modern" id="odemeTablosu">
                    <thead>
                        <tr>
                            <th>Durum & Sipariş</th>
                            <th>Kayıt Tipi</th>
                            <th>Veli / Öğrenci Bilgisi</th>
                            <th>İletişim & Aksiyon</th>
                            <th>Tutar</th>
                            <th>Tarih</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($odemeler as $o): ?>
                        <tr>
                            <td>
                                <?php
                                $d = (string)$o['odeme_durumu'];
                                $badgeCls = $d === 'success' ? 'bg-success-soft' : ($d === 'failed' ? 'bg-danger-soft' : 'bg-warning-soft');
                                $icon = $d === 'success' ? 'check-circle' : ($d === 'failed' ? 'times-circle' : 'clock');
                                ?>
                                <div class="badge-modern <?= $badgeCls ?> mb-2">
                                    <i class="fas fa-<?= $icon ?>"></i> <?= h(strtoupper($d)) ?>
                                </div>
                                <div class="text-mono text-muted" style="font-size: 0.95rem;" title="Sipariş Numarası">
                                    #<?= h($o['merchant_oid'] ?? 'Bilinmiyor') ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-weight: 600; color: var(--text-main);">
                                    <?= h($o['paket'] === 'okul' ? 'Okul Dönemi' : 'Yaz Dönemi') ?>
                                </span>
                                <?php if (($o['kayit_kaynagi'] ?? '') === 'yuz_yuze'): ?>
                                    <div class="mt-2"><span class="badge-modern bg-secondary-soft" style="font-size:0.75rem;"><i class="fas fa-hand-holding-usd mr-1"></i> Elden</span></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 600; margin-bottom: 0.35rem;">
                                    <i class="fas fa-user text-muted mr-1" style="width:18px"></i> <?= h(trim(($o['veli_ad'] ?? '') . ' ' . ($o['veli_soyad'] ?? ''))) ?>
                                </div>
                                <div class="text-muted" style="font-size: 1rem;">
                                    <i class="fas fa-child mr-1" style="width:18px"></i> <?= h(trim(($o['ogrenci_ad'] ?? '') . ' ' . ($o['ogrenci_soyad'] ?? ''))) ?>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 1rem;">
                                    <div>
                                        <div style="font-weight: 600; font-size: 1.05rem;"><?= h($o['veli_tel'] ?? '') ?></div>
                                        <div class="text-muted" style="font-size: 0.95rem;"><?= h($o['veli_email'] ?? '') ?></div>
                                    </div>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <a href="https://wa.me/<?= formatPhoneForWA($o['veli_tel']) ?>" target="_blank" class="action-circle wa-btn" title="WhatsApp">
                                            <i class="fab fa-whatsapp"></i>
                                        </a>
                                        <a href="tel:<?= h($o['veli_tel'] ?? '') ?>" class="action-circle call-btn" title="Ara">
                                            <i class="fas fa-phone-alt"></i>
                                        </a>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span style="font-size: 1.25rem; font-weight: 700; color: var(--text-main);">
                                    <?= number_format((int)($o['toplam_tutar'] ?? 0), 0, ',', '.') ?> ₺
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 1.05rem;"><?= !empty($o['created_at']) ? date('d.m.Y', strtotime((string)$o['created_at'])) : '—' ?></div>
                                <div class="text-muted" style="font-size: 0.95rem;"><?= !empty($o['created_at']) ? date('H:i', strtotime((string)$o['created_at'])) : '' ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($odemeler)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
                                <i class="fas fa-folder-open mb-3" style="font-size: 3rem; opacity: 0.2;"></i>
                                <h5 style="font-size: 1.25rem;">Kayıt Bulunamadı</h5>
                                <p class="mb-0" style="font-size: 1.05rem;">Arama kriterlerinize uygun başvuru eşleşmedi.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($sekme === 'yuz_yuze'): ?>
        <?php
        $yy_kurs_yaz = erken_kampanya_kurs_tutar('yaz');
        $yy_kurs_okul = erken_kampanya_kurs_tutar('okul');
        $yy_mat_yaz = erken_kampanya_materyal_tutar('yaz');
        $yy_mat_okul = erken_kampanya_materyal_tutar('okul');
        $yy_taksit_birim = 6700;
        ?>
        <div class="yy-shell">
            <div class="yy-intro">
                <h2>Yüz yüze (elden) kayıt</h2>
                
            </div>

            <form method="post" id="yy-kayit-form" class="yy-form-card" action="" autocomplete="on">
                <input type="hidden" name="action" value="yuz_yuze_kaydet">
                <input type="hidden" name="taksit_sayisi" id="yy-taksit-sayisi" value="1">

                <div class="yy-split">
                    <div class="yy-col yy-col--form">
                        <p class="yy-section-title">Veli ve öğrenci bilgileri</p>

                        <div class="form-group-custom" style="margin-bottom:1rem;">
                            <label>Paket</label>
                            <select name="paket" id="yy-paket" class="form-control-custom" required>
                                <option value="yaz">Yaz dönemi — <?= number_format($yy_kurs_yaz, 0, ',', '.') ?> TL</option>
                                <option value="okul">Okul dönemi — <?= number_format($yy_kurs_okul, 0, ',', '.') ?> TL (+ yaz hediye)</option>
                            </select>
                        </div>

                        <div class="yy-field-grid">
                            <div class="form-group-custom">
                                <label>Veli adı</label>
                                <input type="text" name="veli_ad" class="form-control-custom" required autocomplete="given-name">
                            </div>
                            <div class="form-group-custom">
                                <label>Veli soyadı</label>
                                <input type="text" name="veli_soyad" class="form-control-custom" required autocomplete="family-name">
                            </div>
                            <div class="form-group-custom">
                                <label>Telefon</label>
                                <input type="text" name="veli_tel" id="yy-veli-tel" class="form-control-custom yy-tel-input" required inputmode="numeric" autocomplete="tel" placeholder="0 (5XX) XXX XX XX" maxlength="17" title="Cep: 11 hane, 0 ile başlar">
                            </div>
                            <div class="form-group-custom">
                                <label>E-posta</label>
                                <input type="email" name="veli_email" class="form-control-custom" required autocomplete="email">
                            </div>
                            <div class="form-group-custom">
                                <label>Öğrenci adı</label>
                                <input type="text" name="ogrenci_ad" class="form-control-custom" required autocomplete="given-name">
                            </div>
                            <div class="form-group-custom">
                                <label>Öğrenci soyadı</label>
                                <input type="text" name="ogrenci_soyad" class="form-control-custom" required autocomplete="family-name">
                            </div>
                        </div>

                        <div style="margin-top:1.25rem;">
                            <label class="d-flex align-items-start gap-2" style="cursor:pointer;font-weight:600;">
                                <input type="hidden" name="pesin_indirimi" value="0">
                                <input type="checkbox" name="pesin_indirimi" id="yy-pesin" value="1" style="width:1.1rem;height:1.1rem;margin-top:0.2rem;flex-shrink:0;">
                                <span>Peşin ödeme indirimi (%5)</span>
                            </label>
                        </div>

                        <label class="d-flex align-items-start gap-2" style="cursor:pointer;margin-top:1.5rem;">
                            <input type="checkbox" name="yuz_yuze_sozlesme_onay" value="1" id="yy-sozlesme" required style="width:1.1rem;height:1.1rem;margin-top:0.2rem;flex-shrink:0;">
                            <span style="font-size:0.98rem;line-height:1.45;">Veli ile yüz yüze görüşmede elden ödeme / taksit planını açıkladım; kayıt işlemlerinin başlatılacağını beyan ettim.</span>
                        </label>

                        <div class="yy-actions">
                            <button type="submit" class="btn-custom btn-success-custom" id="yy-submit"><i class="fas fa-save mr-2"></i> Kaydı oluştur</button>
                        </div>
                    </div>

                    <div class="yy-col yy-col--calc">
                        <p class="yy-section-title">Fiyat özeti ve taksit</p>

                        <div class="table-wrapper">
                            <table class="table-modern" id="yy-ozet-tablo">
                                <thead><tr><th>Kalem</th><th class="text-end">Tutar (TL)</th></tr></thead>
                                <tbody id="yy-ozet-body"></tbody>
                            </table>
                        </div>

                        <div style="margin-top:1rem;padding:1rem 1.15rem;background:#f8fafc;border-radius:0.75rem;border:1px solid var(--border);">
                            <div class="text-muted" style="font-size:0.9rem;">Net kurs tutarı</div>
                            <div id="yy-kurs-net" style="font-size:1.65rem;font-weight:800;color:var(--primary);line-height:1.2;">—</div>
                        </div>

                        <div class="alert border-0 shadow-sm mb-3 mt-3" style="background:var(--info-bg);color:var(--info-text);font-size:0.98rem;padding:0.75rem 1rem;margin-bottom:0.75rem !important;">
                            Kitap/materyal (kurumda): <strong id="yy-mat-bilgi">—</strong>
                        </div>
                        <div class="alert border-0 shadow-sm mb-3" style="background:var(--warning-bg);color:var(--warning-text);font-size:0.98rem;padding:0.75rem 1rem;">
                            Eğitim tutarı en fazla <strong id="yy-max-taksit-metin">1</strong> eşit taksite bölünebilir (son vade Mayıs 2027).
                        </div>

                        <div class="form-group-custom" style="max-width:100%;margin-bottom:0.75rem;">
                            <label>Taksit sayısı</label>
                            <select id="yy-taksit-select" class="form-control-custom"></select>
                        </div>

                        <div class="table-wrapper mt-2" id="yy-taksit-wrap" style="display:none;">
                            <table class="table-modern">
                                <thead><tr><th>Taksit</th><th>Vade</th><th class="text-end">Tutar</th></tr></thead>
                                <tbody id="yy-taksit-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <script>
        (function () {
            function trTelDigits(raw) {
                var d = String(raw || '').replace(/\D/g, '');
                if (d.indexOf('90') === 0 && d.length >= 12) d = '0' + d.slice(2);
                else if (d.charAt(0) === '5' && d.length <= 10) d = '0' + d;
                return d.slice(0, 11);
            }
            function trTelFormatDisplay(raw) {
                var d = trTelDigits(raw);
                if (!d.length) return '';
                var p = [];
                p.push(d.slice(0, Math.min(4, d.length)));
                if (d.length > 4) p.push(d.slice(4, 7));
                if (d.length > 7) p.push(d.slice(7, 9));
                if (d.length > 9) p.push(d.slice(9, 11));
                return p.join(' ');
            }

            var ERKEN_YY = {
                kurs: { yaz: <?= (int)$yy_kurs_yaz ?>, okul: <?= (int)$yy_kurs_okul ?> },
                mat: { yaz: <?= (int)$yy_mat_yaz ?>, okul: <?= (int)$yy_mat_okul ?> },
                taksitBirim: <?= (int)$yy_taksit_birim ?>
            };
            function roundUp100(x) { return x <= 0 ? 0 : Math.ceil(x / 100) * 100; }
            function fmt(n) {
                var v = Number(n);
                if (!isFinite(v)) return '—';
                return v.toLocaleString('tr-TR', { maximumFractionDigits: 0 });
            }

            function hesapKurs(paket, pesin) {
                var taban = ERKEN_YY.kurs[paket];
                if (typeof taban !== 'number' || !isFinite(taban)) {
                    return { kurs: 0, rows: [{ kalem: 'Paket seçin', tutar: 0 }] };
                }
                var kurs = taban;
                var rows = [{ kalem: 'Kampanya kurs ücreti', tutar: kurs }];
                if (pesin) {
                    var y1 = Math.ceil((kurs * 0.95) / 100) * 100;
                    rows.push({ kalem: 'Peşin ödeme indirimi (%5)', tutar: y1 - kurs });
                    kurs = y1;
                }
                kurs = roundUp100(kurs);
                return { kurs: kurs, rows: rows };
            }

            function maxTaksitHesap(kurs) {
                var maxT = kurs > 0 ? Math.round(kurs / ERKEN_YY.taksitBirim) : 1;
                if (maxT < 1) maxT = 1;
                var bugun = new Date();
                var baslangicAy = new Date(bugun.getFullYear(), bugun.getMonth(), 1);
                var may2027 = new Date(2027, 4, 1);
                var maxAy = (may2027.getFullYear() - baslangicAy.getFullYear()) * 12 + (may2027.getMonth() - baslangicAy.getMonth()) + 1;
                if (maxAy < 1) maxAy = 1;
                if (maxAy < maxT) maxT = maxAy;
                return Math.max(1, maxT);
            }

            function taksitTablo(kurs, n) {
                var baslangicAy = new Date();
                baslangicAy = new Date(baslangicAy.getFullYear(), baslangicAy.getMonth(), 1);
                var may2027 = new Date(2027, 4, 1);
                var base = Math.ceil(kurs / n / 100) * 100;
                if (n > 1 && (n - 1) * base > kurs) base = Math.floor(kurs / (n - 1) / 100) * 100;
                var rows = [];
                for (var i = 1; i <= n; i++) {
                    var tarih = new Date(baslangicAy.getFullYear(), baslangicAy.getMonth() + (i - 1), 1);
                    if (tarih > may2027) tarih = new Date(2027, 4, 1);
                    var tut = i < n ? base : (kurs - (n - 1) * base);
                    rows.push({ i: i, tarih: tarih, tut: tut });
                }
                return rows;
            }

            function guncelle() {
                var paketEl = document.getElementById('yy-paket');
                var pesinEl = document.getElementById('yy-pesin');
                if (!paketEl) return;
                var paket = paketEl.value;
                var pesin = !!(pesinEl && pesinEl.checked);
                var out = hesapKurs(paket, pesin);
                var kurs = out.kurs;
                var tbody = document.getElementById('yy-ozet-body');
                var netEl = document.getElementById('yy-kurs-net');
                var matEl = document.getElementById('yy-mat-bilgi');
                var matVal = ERKEN_YY.mat[paket];
                if (matEl) matEl.textContent = (typeof matVal === 'number' ? fmt(matVal) : '—') + ' TL (kurumda tahsil)';
                if (tbody) {
                    tbody.innerHTML = out.rows.map(function (r) {
                        var tut = r.tutar;
                        var cls = tut < 0 ? ' style="color:#166534;font-weight:600;"' : '';
                        return '<tr><td>' + r.kalem + '</td><td class="text-end"' + cls + '>' + fmt(tut) + '</td></tr>';
                    }).join('');
                }
                if (netEl) netEl.textContent = fmt(kurs) + ' TL';

                var maxT = maxTaksitHesap(kurs);
                var maxMetin = document.getElementById('yy-max-taksit-metin');
                if (maxMetin) maxMetin.textContent = String(maxT);
                var sel = document.getElementById('yy-taksit-select');
                var hid = document.getElementById('yy-taksit-sayisi');
                var cur = sel ? (parseInt(sel.value, 10) || 1) : 1;
                if (cur > maxT) cur = maxT;
                if (sel) {
                    sel.innerHTML = '';
                    for (var t = 1; t <= maxT; t++) {
                        var opt = document.createElement('option');
                        opt.value = String(t);
                        opt.textContent = t === 1 ? 'Peşin (1 taksit)' : t + ' taksit';
                        if (t === cur) opt.selected = true;
                        sel.appendChild(opt);
                    }
                    if (hid) hid.value = sel.value;
                }
                var n = 1;
                if (sel && sel.options && sel.options.length) {
                    n = parseInt(String(sel.value), 10);
                    if (!isFinite(n) || n < 1) n = 1;
                }
                var wrap = document.getElementById('yy-taksit-wrap');
                var tb = document.getElementById('yy-taksit-body');
                if (n > 1 && wrap && tb) {
                    var plan = taksitTablo(kurs, n);
                    tb.innerHTML = plan.map(function (p) {
                        var tm = p.tarih.toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' });
                        return '<tr><td>' + p.i + '. taksit</td><td>' + tm + '</td><td class="text-end" style="font-weight:700;">' + fmt(p.tut) + '</td></tr>';
                    }).join('');
                    wrap.style.display = 'block';
                } else if (wrap) {
                    wrap.style.display = 'none';
                }
            }

            var yyForm = document.getElementById('yy-kayit-form');
            var yyTel = document.getElementById('yy-veli-tel');
            if (yyTel) {
                yyTel.addEventListener('input', function () {
                    yyTel.value = trTelFormatDisplay(yyTel.value);
                });
                yyTel.addEventListener('blur', function () {
                    yyTel.value = trTelFormatDisplay(yyTel.value);
                });
            }
            if (yyForm) {
                yyForm.addEventListener('submit', function () {
                    if (yyTel) yyTel.value = trTelDigits(yyTel.value);
                });
            }

            var paketSel = document.getElementById('yy-paket');
            var pesinCb = document.getElementById('yy-pesin');
            if (paketSel) {
                paketSel.addEventListener('change', guncelle);
                paketSel.addEventListener('input', guncelle);
            }
            if (pesinCb) {
                pesinCb.addEventListener('change', guncelle);
                pesinCb.addEventListener('click', guncelle);
            }
            var tsel = document.getElementById('yy-taksit-select');
            if (tsel) {
                tsel.addEventListener('change', function () {
                    var hid = document.getElementById('yy-taksit-sayisi');
                    if (hid) hid.value = tsel.value;
                    guncelle();
                });
                tsel.addEventListener('input', function () {
                    var hid = document.getElementById('yy-taksit-sayisi');
                    if (hid) hid.value = tsel.value;
                    guncelle();
                });
            }
            guncelle();
        })();
        </script>

    <?php else: ?>
        
        <!-- İletişim KPI -->
        <div class="kpi-wrapper">
            <div class="kpi-card">
                <div class="kpi-icon bg-info-soft"><i class="fas fa-envelope"></i></div>
                <div class="kpi-info"><h6>Toplam Talep</h6><h3><?= $statIletisim['toplam'] ?></h3></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon bg-primary" style="color:white;"><i class="fas fa-star"></i></div>
                <div class="kpi-info"><h6>Yeni Bekleyen</h6><h3 class="text-primary"><?= $statIletisim['yeni'] ?></h3></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon bg-warning-soft"><i class="fas fa-reply"></i></div>
                <div class="kpi-info"><h6>Dönüş Yapıldı</h6><h3 class="text-warning"><?= $statIletisim['donuldu'] ?></h3></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon bg-secondary-soft"><i class="fas fa-check-double"></i></div>
                <div class="kpi-info"><h6>Kapalı</h6><h3 class="text-secondary"><?= $statIletisim['kapali'] ?></h3></div>
            </div>
        </div>

        <!-- İletişim Toolbar -->
        <div class="toolbar">
            <form method="get" class="toolbar-form">
                <input type="hidden" name="sekme" value="iletisim">
                
                <div class="form-group-custom" style="flex: 0 1 250px;">
                    <label>Talep Durumu</label>
                    <select name="iletisim_durum" class="form-control-custom">
                        <option value="">Tümü</option>
                        <option value="yeni" <?= $f_iletisim_durum === 'yeni' ? 'selected' : '' ?>>Yeni Talepler</option>
                        <option value="donuldu" <?= $f_iletisim_durum === 'donuldu' ? 'selected' : '' ?>>Dönüldü</option>
                        <option value="kapali" <?= $f_iletisim_durum === 'kapali' ? 'selected' : '' ?>>Kapalı</option>
                    </select>
                </div>
                
                <div class="form-group-custom">
                    <label>Arama (Ad, Tel, Mesaj İçeriği)</label>
                    <input type="text" name="iq" value="<?= h($f_iq) ?>" class="form-control-custom" placeholder="Kayıtlar içinde ara...">
                </div>
                
                <div class="toolbar-actions">
                    <button type="submit" class="btn-custom btn-primary-custom"><i class="fas fa-search"></i> Filtrele</button>
                    <a href="?sekme=iletisim" class="btn-custom btn-light-custom"><i class="fas fa-redo"></i> Temizle</a>
                </div>
            </form>
        </div>

        <!-- İletişim Kartları -->
        <div class="contact-grid">
        <?php foreach ($iletisimler as $i): 
            $durum = $i['durum'] ?? 'yeni';
            $badgeCls = $durum === 'yeni' ? 'bg-info-soft' : ($durum === 'donuldu' ? 'bg-warning-soft' : 'bg-secondary-soft');
            $icon = $durum === 'yeni' ? 'star' : ($durum === 'donuldu' ? 'reply' : 'check');
        ?>
            <div class="contact-card">
                <!-- Header -->
                <div class="contact-header">
                    <div>
                        <h5 style="margin: 0 0 0.5rem 0; font-size: 1.35rem; font-weight: 700; display:flex; align-items:center; gap:0.5rem;">
                            <?= h($i['ad_soyad'] ?? '') ?>
                        </h5>
                        <div style="display: flex; gap: 0.75rem; align-items: center;">
                            <span style="font-weight: 600; color: var(--text-muted); font-size: 1.1rem;"><?= h($i['telefon'] ?? '') ?></span>
                            <a href="https://wa.me/<?= formatPhoneForWA($i['telefon']) ?>" target="_blank" class="action-circle wa-btn" style="width:38px; height:38px; font-size: 1.1rem;" title="WhatsApp">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <a href="tel:<?= h($i['telefon'] ?? '') ?>" class="action-circle call-btn" style="width:38px; height:38px; font-size: 1.1rem;" title="Ara">
                                <i class="fas fa-phone-alt"></i>
                            </a>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div class="badge-modern <?= $badgeCls ?> mb-2">
                            <i class="fas fa-<?= $icon ?>"></i> <?= strtoupper(h($durum)) ?>
                        </div>
                        <div style="font-size: 0.95rem; font-weight: 600; color: var(--text-muted);">
                            <?= !empty($i['created_at']) ? date('d.m.Y H:i', strtotime((string)$i['created_at'])) : '—' ?>
                        </div>
                    </div>
                </div>
                
                <!-- Body -->
                <div class="contact-body">
                    <div style="margin-bottom: 0.5rem; font-weight: 600; font-size: 0.95rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">
                        Müşteri Mesajı
                    </div>
                    <div style="background: #f8fafc; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid var(--border); font-size: 1.1rem;">
                        <?= nl2br(h($i['mesaj'] ?? '')) ?>
                    </div>
                </div>

                <!-- Footer / Form -->
                <div class="contact-footer">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="iletisim_guncelle">
                        <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                        
                        <div style="margin-bottom: 0.5rem; font-weight: 600; font-size: 0.95rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">
                            Durum & Personel Notu
                        </div>
                        
                        <div class="input-group-unified">
                            <select name="durum" class="form-control-custom" style="font-weight: 600;">
                                <option value="yeni" <?= $durum === 'yeni' ? 'selected' : '' ?>>Yeni</option>
                                <option value="donuldu" <?= $durum === 'donuldu' ? 'selected' : '' ?>>Dönüldü</option>
                                <option value="kapali" <?= $durum === 'kapali' ? 'selected' : '' ?>>Kapalı</option>
                            </select>
                            <input type="text" name="personel_notu" value="<?= h($i['personel_notu'] ?? '') ?>" class="form-control-custom" placeholder="Görüşme notunu buraya yazın...">
                            <button type="submit" class="btn-custom btn-success-custom" style="padding: 0 1.5rem;">
                                <i class="fas fa-save"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        
        <?php if (empty($iletisimler)): ?>
            <div style="text-align: center; padding: 5rem 1rem; background: var(--surface); border-radius: 1rem; border: 1px solid var(--border); margin-top: 1rem;">
                <i class="fas fa-check-circle mb-3" style="font-size: 4.5rem; color: var(--success-bg);"></i>
                <h4 style="font-weight: 700; font-size: 1.5rem;">Harika!</h4>
                <p class="text-muted" style="font-size: 1.1rem;">Şu an için bekleyen veya filtrenize uyan bir iletişim talebi bulunmuyor.</p>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
    function exportTableToCSV(filename) {
        var csv = [];
        var rows = document.querySelectorAll("#odemeTablosu tr");
        
        for (var i = 0; i < rows.length; i++) {
            var row = [], cols = rows[i].querySelectorAll("td, th");
            for (var j = 0; j < cols.length; j++) {
                var data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim();
                row.push('"' + data + '"');
            }
            csv.push(row.join(","));
        }

        var csvFile = new Blob([String.fromCharCode(0xFEFF), csv.join("\n")], {type: "text/csv;charset=utf-8;"});
        var downloadLink = document.createElement("a");
        downloadLink.download = filename;
        downloadLink.href = window.URL.createObjectURL(csvFile);
        downloadLink.style.display = "none";
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
    }
</script>
</body>
</html>