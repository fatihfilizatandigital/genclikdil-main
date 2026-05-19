<?php
/**
 * PayTR ödeme.
 * POST: t (token), odeme_tipi (kurs|kitap_materyal|toplu), email, erken_kayit, ingilizce, almanca, pesin
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/sonuc_fiyat_hesap.php';
require_once __DIR__ . '/config/teklif_v2.php';

$token = isset($_POST['t']) ? trim($_POST['t']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$odeme_tipi = isset($_POST['odeme_tipi']) ? trim($_POST['odeme_tipi']) : '';
$sinif_ici_sira_post = isset($_POST['sinif_ici_sira']) ? (int)$_POST['sinif_ici_sira'] : null;
$erken_kayit = isset($_POST['erken_kayit']) ? (int)$_POST['erken_kayit'] : 0;
$ingilizce = isset($_POST['ingilizce']) ? (int)$_POST['ingilizce'] : 0;
$almanca = isset($_POST['almanca']) ? (int)$_POST['almanca'] : 0;
$pesin = isset($_POST['pesin']) ? (int)$_POST['pesin'] : 0;
$sozlesme_onay = isset($_POST['sozlesme_onay']) ? (int)$_POST['sozlesme_onay'] : 0;

if ($token === '' || strlen($token) > 64 || !ctype_xdigit($token)) {
    header('Location: sonuclar.php');
    exit;
}
if (!in_array($odeme_tipi, ['kurs', 'kitap_materyal', 'toplu'], true)) {
    $hata = 'Geçersiz ödeme tipi.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
if ($sozlesme_onay !== 1) {
    $hata = 'Ödeme öncesi bilgilendirme onayı gereklidir.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

$config_file = __DIR__ . '/config/paytr.php';
if (!is_file($config_file)) {
    $hata = 'Ödeme ayarları yüklenemedi.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
$paytr = require $config_file;
$merchant_id   = $paytr['merchant_id'] ?? '';
$merchant_key  = $paytr['merchant_key'] ?? '';
$merchant_salt = $paytr['merchant_salt'] ?? '';
if ($merchant_id === '' || $merchant_key === '' || $merchant_salt === '') {
    $hata = 'Ödeme ayarları eksik.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

teklif_v2_ensure_schema($conn);
$teklif = teklif_v2_get_by_token($conn, $token);
if (!$teklif || ($teklif['durum'] ?? '') !== 'aktif') {
    header('Location: sonuclar.php');
    exit;
}

$teklif_v2_id = (int)($teklif['id'] ?? 0);
$read_steps = function() use ($conn, $teklif_v2_id): array {
    $map = [];
    $q = mysqli_prepare($conn, "SELECT * FROM gorusme_teklif_odeme_adimlari WHERE teklif_id = ? ORDER BY sira_no ASC, id ASC");
    if (!$q) return $map;
    mysqli_stmt_bind_param($q, "i", $teklif_v2_id);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);
    while ($r && $row = mysqli_fetch_assoc($r)) {
        $map[(string)$row['adim']] = $row;
    }
    mysqli_stmt_close($q);
    return $map;
};

$steps = $read_steps();
$kurs_success = isset($steps['kurs']) && (($steps['kurs']['durum'] ?? '') === 'success');
$kitap_success = isset($steps['kitap_materyal']) && (($steps['kitap_materyal']['durum'] ?? '') === 'success');
$toplu_success = isset($steps['toplu']) && (($steps['toplu']['durum'] ?? '') === 'success');

if ($odeme_tipi === 'toplu' && ($kurs_success || $kitap_success)) {
    $hata = 'Ayrı ödeme başladıktan sonra toplu ödeme seçilemez.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
if (($odeme_tipi === 'kurs' || $odeme_tipi === 'kitap_materyal') && $toplu_success) {
    $hata = 'Toplu ödeme tamamlandıktan sonra ayrı ödeme başlatılamaz.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

if ($odeme_tipi === 'toplu') {
    if (!isset($steps['toplu'])) {
        @mysqli_query($conn, "INSERT INTO gorusme_teklif_odeme_adimlari (teklif_id, adim, sira_no, durum) VALUES (" . (int)$teklif_v2_id . ", 'toplu', 1, 'bekliyor')");
    }
    @mysqli_query($conn, "UPDATE gorusme_teklif_v2 SET odeme_modu = 'toplu' WHERE id = " . (int)$teklif_v2_id . " LIMIT 1");
} else {
    if (!isset($steps['kurs'])) {
        @mysqli_query($conn, "INSERT INTO gorusme_teklif_odeme_adimlari (teklif_id, adim, sira_no, durum) VALUES (" . (int)$teklif_v2_id . ", 'kurs', 1, 'bekliyor')");
    }
    if (!isset($steps['kitap_materyal'])) {
        $kitap_durum = (isset($steps['kurs']) && (($steps['kurs']['durum'] ?? '') === 'success')) ? 'bekliyor' : 'locked';
        @mysqli_query($conn, "INSERT INTO gorusme_teklif_odeme_adimlari (teklif_id, adim, sira_no, durum) VALUES (" . (int)$teklif_v2_id . ", 'kitap_materyal', 2, '" . mysqli_real_escape_string($conn, $kitap_durum) . "')");
    }
    @mysqli_query($conn, "UPDATE gorusme_teklif_v2 SET odeme_modu = 'ayri' WHERE id = " . (int)$teklif_v2_id . " LIMIT 1");
}

$steps = $read_steps();
$odeme_adimi = $steps[$odeme_tipi] ?? null;
if (!$odeme_adimi) {
    $hata = 'Ödeme adımı hazırlanamadı.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
$adim_durum = $odeme_adimi['durum'] ?? 'bekliyor';
if ($adim_durum === 'success') {
    $hata = 'Bu ödeme zaten tamamlanmış.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
if ($adim_durum === 'locked') {
    $hata = 'Bu ödeme adımı henüz aktif değil.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
if ($odeme_tipi === 'kitap_materyal') {
    $kurs_durum = $steps['kurs']['durum'] ?? '';
    if ($kurs_durum !== 'success') {
        $hata = 'Önce kurs ödemesi tamamlanmalıdır.';
        require __DIR__ . '/paytr_odeme_hata.php';
        exit;
    }
}

$sinif_ici_sira = isset($teklif['sinif_ici_sira']) && $teklif['sinif_ici_sira'] !== null && $teklif['sinif_ici_sira'] !== '' ? (int)$teklif['sinif_ici_sira'] : 0;
if ($sinif_ici_sira_post !== null && $sinif_ici_sira_post >= 0) {
    $sinif_ici_sira = $sinif_ici_sira_post;
}
$sid_ing = isset($teklif['sinav_sonuc_id_ingilizce']) ? (int)$teklif['sinav_sonuc_id_ingilizce'] : 0;
$sid_alm = isset($teklif['sinav_sonuc_id_almanca']) ? (int)$teklif['sinav_sonuc_id_almanca'] : 0;
$sid_legacy = isset($teklif['sinav_sonuc_id']) ? (int)$teklif['sinav_sonuc_id'] : 0;

if ($sid_ing <= 0 && $sid_alm <= 0 && $sid_legacy > 0) {
    $tur = strtolower(trim((string)($teklif['sinav_turu'] ?? '')));
    if ($tur === 'almanca') {
        $sid_alm = $sid_legacy;
    } else {
        $sid_ing = $sid_legacy;
    }
}

$sinav_profil = 'onlyEnglish';
if ($sid_ing > 0 && $sid_alm > 0) {
    $sinav_profil = 'both';
} elseif ($sid_alm > 0 && $sid_ing <= 0) {
    $sinav_profil = 'onlyGerman';
}

if ($ingilizce !== 1 && $almanca !== 1) {
    $hata = 'En az bir dil seçilmelidir.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

$fiyat = sonuc_fiyat_hesapla_coklu_dal($sinif_ici_sira, $erken_kayit, $ingilizce, $almanca, $sinav_profil);
$tutar = $odeme_tipi === 'kurs' ? $fiyat['kurs_tutar'] : ($odeme_tipi === 'kitap_materyal' ? $fiyat['kitap_materyal_tutar'] : $fiyat['kurs_tutar']);
$snapshot_json = json_encode($fiyat['hesap_detay'], JSON_UNESCAPED_UNICODE);
if ($snapshot_json === false) $snapshot_json = '{}';

$teklif_id = 0; // legacy kolon için hard-switch sonrası kullanılmıyor
$payment_amount = (int)round($tutar * 100);
if ($payment_amount <= 0) {
    if ($odeme_tipi === 'toplu' && (int)$fiyat['kurs_tutar'] === 0) {
        $payment_amount = 1000; // Sembolik 10 TL (tam burs)
    } else {
        $hata = 'Ödeme tutarı geçersiz.';
        require __DIR__ . '/paytr_odeme_hata.php';
        exit;
    }
}

// base_url (ters slash olmamalı)
$base_url = $paytr['base_url'] ?? null;
if (!empty($base_url) && is_string($base_url)) {
    $base_url = str_replace('\\', '', $base_url);
    $base_url = rtrim(preg_replace('#/$#', '', $base_url), '/');
    if (!preg_match('#^https?://#', $base_url)) {
        $base_url = 'https://' . $base_url;
    }
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'genclikdil.com';
    $host = preg_replace('/:\d+$/', '', $host);
    $base_url = $protocol . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
}
$base_url = str_replace('\\', '', $base_url);

$ad_param = trim(($teklif['ogrenci_ad'] ?? '') . ' ' . ($teklif['ogrenci_soyad'] ?? ''));
$ad_param = $ad_param !== '' ? '&ad=' . rawurlencode($ad_param) : '';
$merchant_ok_url   = $base_url . '/sonuclar.php?t=' . urlencode($token) . $ad_param . '&odeme=basarili';
$merchant_fail_url = $base_url . '/sonuclar.php?t=' . urlencode($token) . $ad_param . '&odeme=hata';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $hata = 'Geçerli bir e-posta adresi girilmelidir.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

$user_name = trim(($teklif['veli_ad'] ?? '') . ' ' . ($teklif['veli_soyad'] ?? ''));
if ($user_name === '') {
    $user_name = 'Veli';
}
$user_address = 'Gençlik Dil - Fiyat teklifi ödemesi';
$user_phone = trim($teklif['tel_orijinal'] ?? '');
if (strlen($user_phone) > 20) {
    $user_phone = substr($user_phone, 0, 20);
}

$basket_label = $odeme_tipi === 'kurs' ? 'Kurs ücreti' : 'Kitap ve materyal ücreti';
$user_basket = base64_encode(json_encode([
    [$basket_label, number_format($tutar, 2, '.', ''), 1]
]));

if (isset($_SERVER['HTTP_CLIENT_IP'])) {
    $user_ip = $_SERVER['HTTP_CLIENT_IP'];
} elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $user_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
} else {
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}
$user_ip = preg_replace('/[^0-9a-f.: ]/i', '', $user_ip);
if (strlen($user_ip) > 39) {
    $user_ip = substr($user_ip, 0, 39);
}

// PayTR: merchant_oid sadece harf ve rakam (alfanumerik)
$prefix = $odeme_tipi === 'kurs' ? 'K' : ($odeme_tipi === 'kitap_materyal' ? 'M' : 'T');
$merchant_oid = 'V2' . $teklif_v2_id . $prefix . time() . substr(preg_replace('/[^a-zA-Z0-9]/', '', uniqid('', true)), -5);
if (strlen($merchant_oid) > 64) {
    $merchant_oid = substr($merchant_oid, 0, 64);
}

$no_installment = 0;
$max_installment = 12;
$currency = 'TL';
$test_mode = 0;
$timeout_limit = '30';
$debug_on = 0;

// ——— Önce iFrame API (Pro) dene ———
$hash_str = $merchant_id . $user_ip . $merchant_oid . $email . $payment_amount . $user_basket . $no_installment . $max_installment . $currency . $test_mode;
$paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $merchant_salt, $merchant_key, true));

$post_vals = [
    'merchant_id'       => $merchant_id,
    'user_ip'           => $user_ip,
    'merchant_oid'      => $merchant_oid,
    'email'             => $email,
    'payment_amount'    => $payment_amount,
    'paytr_token'       => $paytr_token,
    'user_basket'       => $user_basket,
    'no_installment'    => $no_installment,
    'max_installment'   => $max_installment,
    'user_name'         => $user_name,
    'user_address'      => $user_address,
    'user_phone'        => $user_phone,
    'merchant_ok_url'   => $merchant_ok_url,
    'merchant_fail_url' => $merchant_fail_url,
    'timeout_limit'     => $timeout_limit,
    'currency'          => $currency,
    'test_mode'         => $test_mode,
    'debug_on'          => $debug_on,
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://www.paytr.com/odeme/api/get-token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$result = @curl_exec($ch);
$curl_err = curl_errno($ch);
curl_close($ch);

if ($curl_err) {
    $hata = 'Ödeme altyapısına bağlanılamadı.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

$result = json_decode($result, true);
$iframe_token = null;
$use_link_api = false;

if (is_array($result) && ($result['status'] ?? '') === 'success' && !empty($result['token'])) {
    $iframe_token = $result['token'];
} elseif (is_array($result) && isset($result['reason'])) {
    $reason = (string)$result['reason'];
    if (stripos($reason, 'link') !== false || stripos($reason, 'yalnizca') !== false || stripos($reason, 'basic') !== false) {
        $use_link_api = true;
    }
}

$paytr_col_names = [];
$paytr_cols_rs = @mysqli_query($conn, "SHOW COLUMNS FROM paytr_odemeler");
if ($paytr_cols_rs) {
    while ($c = mysqli_fetch_assoc($paytr_cols_rs)) {
        $paytr_col_names[] = $c['Field'];
    }
}

$insert_pending_payment = function(string $merchantOid) use ($conn, $teklif_id, $teklif_v2_id, $payment_amount, $odeme_tipi, $snapshot_json, $odeme_adimi, $paytr_col_names) {
    if (empty($paytr_col_names)) return;
    $fields = ['teklif_id', 'merchant_oid', 'tutar_kurus', 'durum'];
    $vals = [$teklif_id, $merchantOid, $payment_amount, 'bekliyor'];
    if (in_array('teklif_v2_id', $paytr_col_names, true)) { $fields[] = 'teklif_v2_id'; $vals[] = $teklif_v2_id; }
    if (in_array('teklif_adim_id', $paytr_col_names, true)) { $fields[] = 'teklif_adim_id'; $vals[] = (int)$odeme_adimi['id']; }
    if (in_array('odeme_tipi', $paytr_col_names, true)) { $fields[] = 'odeme_tipi'; $vals[] = $odeme_tipi; }
    if (in_array('fiyat_tablosu_snapshot', $paytr_col_names, true)) { $fields[] = 'fiyat_tablosu_snapshot'; $vals[] = $snapshot_json; }

    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO paytr_odemeler (" . implode(', ', $fields) . ") VALUES ($placeholders)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return;
    $types = '';
    $bind = [];
    foreach ($vals as $i => $v) {
        if (is_int($v)) $types .= 'i';
        else $types .= 's';
        $bind[$i] = $v;
    }
    $refs = [];
    foreach ($bind as $k => $v) { $refs[$k] = &$bind[$k]; }
    call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
};

if ($iframe_token !== null) {
    $insert_pending_payment($merchant_oid);
    @mysqli_query($conn, "UPDATE gorusme_teklif_odeme_adimlari SET merchant_oid = '" . mysqli_real_escape_string($conn, $merchant_oid) . "', tutar_kurus = " . (int)$payment_amount . ", fiyat_snapshot_json = '" . mysqli_real_escape_string($conn, $snapshot_json) . "', updated_at = NOW() WHERE id = " . (int)$odeme_adimi['id'] . " LIMIT 1");
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>PayTR Güvenli Ödeme | Gençlik Dil</title>
    <link rel="icon" type="image/x-icon" href="resimler/logoGenclik.jpg">
    <style>
        :root {
            --bg: #f2f6fb;
            --card: #ffffff;
            --text: #1f2d3d;
            --muted: #5f7287;
            --line: #d8e3ee;
            --brand: #0b7f9c;
            --ok: #0f766e;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            background: radial-gradient(circle at 10% 10%, #fbfdff, var(--bg) 45%);
            color: var(--text);
            zoom: 1;
        }
        .wrap {
            width: 100%;
            max-width: 1060px;
            margin: 0 auto;
            padding: 20px 18px 28px;
        }
        .head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
        }
        .brand img { width: auto; height: 42px; border-radius: 0; object-fit: contain; display: block; }
        .paytr-chip {
            font-size: 0.85rem;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #1f9bc4, #1678a2);
            border-radius: 999px;
            padding: 6px 10px;
            letter-spacing: 0.02em;
        }
        .odeme-card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 12px 32px rgba(16, 34, 58, 0.08);
            overflow: hidden;
        }
        .odeme-baslik {
            padding: 18px 18px 12px;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(180deg, #fcfeff 0%, #f8fbff 100%);
        }
        .odeme-baslik h1 {
            margin: 0 0 8px 0;
            font-size: 1.35rem;
            line-height: 1.3;
        }
        .odeme-baslik p {
            margin: 0;
            font-size: 0.98rem;
            color: var(--muted);
        }
        .odeme-notlar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        .odeme-notlar span {
            font-size: 0.82rem;
            color: #35556d;
            border: 1px solid #cfe0ef;
            background: #f6fbff;
            border-radius: 999px;
            padding: 5px 10px;
        }
        .odeme-iframe {
            padding: 14px;
        }
        .odeme-iframe iframe {
            width: 100%;
            min-height: 620px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
        }
        .alt {
            padding: 0 14px 14px;
            font-size: 0.84rem;
            color: var(--muted);
        }
        .alt b { color: var(--ok); }
    </style>
    <script>
        document.documentElement.style.zoom = '100%';
    </script>
</head>
<body>
    <div class="wrap">
        <div class="head">
            <div class="brand">
                <img src="resimler/logoGenclik.jpg" alt="Gençlik Dil">
            </div>
            <span class="paytr-chip">PayTR Altyapısı</span>
        </div>
        <div class="odeme-card">
            <div class="odeme-baslik">
                <h1>PayTR Güvenli Ödeme Sayfası</h1>
                <p>Toplam <strong><?= number_format($tutar, 2, ',', '.') ?> TL</strong> tutarındaki ödemeniz, kart bilgileri kurumumuzda tutulmadan PayTR güvenli altyapısı ile işlenir.</p>
                <div class="odeme-notlar">
                    <span>256-bit SSL koruması</span>
                    <span>3D Secure destekli</span>
                    <span>PCI-DSS uyumlu ödeme altyapısı</span>
                </div>
            </div>
            <div class="odeme-iframe">
                <script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
                <iframe src="https://www.paytr.com/odeme/guvenli/<?= htmlspecialchars($iframe_token) ?>" id="paytriframe" frameborder="0" scrolling="no" title="PayTR Güvenli Ödeme Formu"></iframe>
                <script>iFrameResize({}, '#paytriframe');</script>
            </div>
            <div class="alt"><b>Not:</b> Ödeme tamamlandığında otomatik olarak sonuç ekranına yönlendirilirsiniz.</div>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

if (!$use_link_api) {
    $hata = 'Ödeme sayfası açılamadı: ' . (isset($result['reason']) ? htmlspecialchars($result['reason']) : 'Bilinmeyen hata');
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

// ——— Link API (Basic) fallback ———
$name = 'Eğitim ve materyal ücreti - Gençlik Dil';
if (mb_strlen($name, 'UTF-8') > 200) {
    $name = mb_substr($name, 0, 197, 'UTF-8') . '...';
}
$price = (string)$payment_amount;
$callback_link = rtrim($base_url, '/') . '/paytr_link_bildirim.php';
$callback_link = str_replace('\\', '', $callback_link);
if ($callback_link === '/paytr_link_bildirim.php' || !preg_match('#^https?://[^/]+/#', $callback_link)) {
    $hata = 'Ödeme linki oluşturulamadı: Geçerli bildirim adresi yok. config/paytr.php içinde base_url tanımlayın.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
if (preg_match('#localhost|127\.0\.0\.1|:[0-9]+/#', $callback_link)) {
    $hata = 'Ödeme linki oluşturulamadı: Bildirim adresi localhost/port içeremez. config/paytr.php base_url gerçek domain olmalı.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
$callback_id = 'TK' . $teklif_id . ($odeme_tipi === 'kurs' ? 'K' : 'M');
$link_type = 'product';
$lang = 'tr';
$min_count = '1';

$required = $name . $price . $currency . $max_installment . $link_type . $lang . $min_count;
$paytr_token = base64_encode(hash_hmac('sha256', $required . $merchant_salt, $merchant_key, true));

$post_vals = [
    'merchant_id'     => $merchant_id,
    'name'            => $name,
    'price'           => $price,
    'currency'        => $currency,
    'max_installment' => (string)$max_installment,
    'link_type'       => $link_type,
    'lang'            => $lang,
    'min_count'       => $min_count,
    'max_count'       => '1',
    'paytr_token'     => $paytr_token,
    'callback_link'   => $callback_link,
    'callback_id'     => $callback_id,
    'debug_on'        => 0,
    'get_qr'          => 0,
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://www.paytr.com/odeme/api/link/create');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$result = @curl_exec($ch);
if (curl_errno($ch)) {
    $hata = 'Ödeme altyapısına bağlanılamadı.';
    require __DIR__ . '/paytr_odeme_hata.php';
    curl_close($ch);
    exit;
}
curl_close($ch);

$result = json_decode($result, true);

if (!is_array($result)) {
    $hata = 'Ödeme linki alınamadı.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

if (isset($result['status']) && $result['status'] === 'error') {
    $hata = 'PayTR: ' . (isset($result['err_msg']) ? htmlspecialchars($result['err_msg']) : 'Bilinmeyen hata');
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

if (($result['status'] ?? '') !== 'success' || empty($result['id'])) {
    $hata = 'Ödeme linki oluşturulamadı: ' . (isset($result['reason']) ? htmlspecialchars($result['reason']) : 'Bilinmeyen hata');
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

$link_id = trim($result['id']);
$insert_pending_payment($merchant_oid);
@mysqli_query($conn, "UPDATE gorusme_teklif_odeme_adimlari SET merchant_oid = '" . mysqli_real_escape_string($conn, $merchant_oid) . "', tutar_kurus = " . (int)$payment_amount . ", fiyat_snapshot_json = '" . mysqli_real_escape_string($conn, $snapshot_json) . "', updated_at = NOW() WHERE id = " . (int)$odeme_adimi['id'] . " LIMIT 1");
header('Location: https://www.paytr.com/link/' . $link_id);
exit;
