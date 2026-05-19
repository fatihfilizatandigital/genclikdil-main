<?php
require_once __DIR__ . '/config/db.php';

$paket = trim((string)($_POST['paket'] ?? ''));
$veli_ad = trim((string)($_POST['veli_ad'] ?? ''));
$veli_soyad = trim((string)($_POST['veli_soyad'] ?? ''));
$veli_tel_raw = trim((string)($_POST['veli_tel'] ?? ''));
$veli_email = trim((string)($_POST['veli_email'] ?? ''));
$ogrenci_ad = trim((string)($_POST['ogrenci_ad'] ?? ''));
$ogrenci_soyad = trim((string)($_POST['ogrenci_soyad'] ?? ''));
$sozlesme_onay = (int)($_POST['kampanya_sozlesme_onay'] ?? 0);

$veli_tel = preg_replace('/\D+/', '', $veli_tel_raw);
if (strlen($veli_tel) === 10) $veli_tel = '0' . $veli_tel;

if (!in_array($paket, ['yaz', 'okul'], true) || $veli_ad === '' || $veli_soyad === '' || $ogrenci_ad === '' || $ogrenci_soyad === '' || $sozlesme_onay !== 1) {
    $hata = 'Lütfen zorunlu alanları doldurun ve onay kutusunu işaretleyin.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
if (!filter_var($veli_email, FILTER_VALIDATE_EMAIL)) {
    $hata = 'Geçerli bir e-posta adresi giriniz.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
if (strlen($veli_tel) < 10) {
    $hata = 'Geçerli bir telefon numarası giriniz.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

$kurs_tutar = $paket === 'okul' ? 56700 : 25000;
$materyal_tutar = $paket === 'okul' ? 22000 : 7000; // Kurumda ayrıca tahsil edilecek
$toplam_tutar = $kurs_tutar; // Online ödemede sadece kurs ücreti alınır
$paket_etiket = $paket === 'okul' ? 'Okul Dönemi Paketi (Yaz dönemi hediye)' : 'Yaz Dönemi Paketi';

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

$ins = mysqli_prepare($conn, "INSERT INTO erken_kayit_basvurular
    (paket, veli_ad, veli_soyad, veli_tel, veli_email, ogrenci_ad, ogrenci_soyad, kurs_tutar, materyal_tutar, toplam_tutar, odeme_durumu)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'bekliyor')");
if (!$ins) {
    $hata = 'Başvuru kaydı oluşturulamadı.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
mysqli_stmt_bind_param($ins, 'sssssssiii', $paket, $veli_ad, $veli_soyad, $veli_tel, $veli_email, $ogrenci_ad, $ogrenci_soyad, $kurs_tutar, $materyal_tutar, $toplam_tutar);
if (!mysqli_stmt_execute($ins)) {
    mysqli_stmt_close($ins);
    $hata = 'Başvuru kaydedilemedi.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
$basvuru_id = (int)mysqli_insert_id($conn);
mysqli_stmt_close($ins);

$merchant_oid = 'EK' . $basvuru_id . 'T' . time() . substr(preg_replace('/[^a-zA-Z0-9]/', '', uniqid('', true)), -5);
if (strlen($merchant_oid) > 64) $merchant_oid = substr($merchant_oid, 0, 64);
@mysqli_query($conn, "UPDATE erken_kayit_basvurular SET merchant_oid = '" . mysqli_real_escape_string($conn, $merchant_oid) . "' WHERE id = " . $basvuru_id . " LIMIT 1");

$config_file = __DIR__ . '/config/paytr.php';
if (!is_file($config_file)) {
    $hata = 'Ödeme ayarları yüklenemedi.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
$paytr = require $config_file;
$merchant_id = $paytr['merchant_id'] ?? '';
$merchant_key = $paytr['merchant_key'] ?? '';
$merchant_salt = $paytr['merchant_salt'] ?? '';
if ($merchant_id === '' || $merchant_key === '' || $merchant_salt === '') {
    $hata = 'Ödeme ayarları eksik.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'genclikdil.com';
$host = preg_replace('/:\d+$/', '', $host);
$base_url = $paytr['base_url'] ?? ($protocol . '://' . $host);
$base_url = str_replace('\\', '', rtrim((string)$base_url, '/'));
if (!preg_match('#^https?://#', $base_url)) $base_url = 'https://' . $base_url;

$merchant_ok_url = $base_url . '/erken-kayit-kampanyasi.php?odeme=basarili&oid=' . urlencode($merchant_oid);
$merchant_fail_url = $base_url . '/erken-kayit-kampanyasi.php?odeme=hata&oid=' . urlencode($merchant_oid);

$payment_amount = (int)round($toplam_tutar * 100);
$user_name = trim($veli_ad . ' ' . $veli_soyad);
$user_phone = substr($veli_tel, 0, 20);
$user_address = 'Gençlik Dil Erken Kayıt Kampanyası';
$user_basket = base64_encode(json_encode([[$paket_etiket, number_format((float)$toplam_tutar, 2, '.', ''), 1]]));

if (isset($_SERVER['HTTP_CLIENT_IP'])) $user_ip = $_SERVER['HTTP_CLIENT_IP'];
elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) $user_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
else $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$user_ip = preg_replace('/[^0-9a-f.: ]/i', '', $user_ip);
if (strlen($user_ip) > 39) $user_ip = substr($user_ip, 0, 39);

$no_installment = 0;
$max_installment = 12;
$currency = 'TL';
$test_mode = 0;
$timeout_limit = '30';
$debug_on = 0;

$hash_str = $merchant_id . $user_ip . $merchant_oid . $veli_email . $payment_amount . $user_basket . $no_installment . $max_installment . $currency . $test_mode;
$paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $merchant_salt, $merchant_key, true));

$post_vals = [
    'merchant_id' => $merchant_id,
    'user_ip' => $user_ip,
    'merchant_oid' => $merchant_oid,
    'email' => $veli_email,
    'payment_amount' => $payment_amount,
    'paytr_token' => $paytr_token,
    'user_basket' => $user_basket,
    'no_installment' => $no_installment,
    'max_installment' => $max_installment,
    'user_name' => $user_name,
    'user_address' => $user_address,
    'user_phone' => $user_phone,
    'merchant_ok_url' => $merchant_ok_url,
    'merchant_fail_url' => $merchant_fail_url,
    'timeout_limit' => $timeout_limit,
    'currency' => $currency,
    'test_mode' => $test_mode,
    'debug_on' => $debug_on,
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

$result = json_decode((string)$result, true);
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

if ($iframe_token !== null) {
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>PayTR Güvenli Ödeme | Erken Kayıt</title>
    <link rel="icon" type="image/x-icon" href="resimler/logoGenclik.jpg">
    <style>
        body { margin:0; font-family: "Segoe UI", Tahoma, Arial, sans-serif; background:#f2f6fb; color:#1f2d3d; }
        .wrap { max-width: 1060px; margin: 0 auto; padding: 20px 18px 28px; }
        .card { background:#fff; border:1px solid #d8e3ee; border-radius:16px; box-shadow:0 10px 30px rgba(16,24,40,.08); overflow:hidden; }
        .head { padding:16px 20px; border-bottom:1px solid #e5edf5; display:flex; align-items:center; justify-content:space-between; gap:12px; }
        .head img { height:36px; width:auto; object-fit:contain; }
        .head h1 { margin:0; font-size:18px; }
        .meta { font-size:13px; color:#5f7287; }
        .frame { padding: 12px; background:#eef4fb; }
        iframe { width:1px; min-width:100%; min-height:690px; border:0; border-radius:10px; background:#fff; }
        .alt { padding:10px 20px 16px; font-size:12px; color:#5f7287; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <div>
                <h1>Erken Kayıt Kampanyası Güvenli Ödeme</h1>
                <div class="meta">Ödeme tutarı: <?= number_format((float)$toplam_tutar, 0, ',', '.') ?> TL</div>
            </div>
            <img src="resimler/logoGenclik.jpg" alt="Logo">
        </div>
        <div class="frame">
            <script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
            <iframe src="https://www.paytr.com/odeme/guvenli/<?= htmlspecialchars($iframe_token, ENT_QUOTES, 'UTF-8') ?>" id="paytriframe" scrolling="no"></iframe>
            <script>iFrameResize({}, '#paytriframe');</script>
        </div>
        <div class="alt"><b>Not:</b> Ödeme tamamlandığında kampanya sayfasına otomatik döneceksiniz.</div>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

if (!$use_link_api) {
    $hata = 'Ödeme sayfası açılamadı: ' . (isset($result['reason']) ? htmlspecialchars((string)$result['reason'], ENT_QUOTES, 'UTF-8') : 'Bilinmeyen hata');
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

$name = mb_strlen($paket_etiket, 'UTF-8') > 200 ? mb_substr($paket_etiket, 0, 197, 'UTF-8') . '...' : $paket_etiket;
$price = (string)$payment_amount;
$callback_link = rtrim($base_url, '/') . '/paytr_link_bildirim.php';
if ($callback_link === '/paytr_link_bildirim.php' || !preg_match('#^https?://[^/]+/#', $callback_link)) {
    $hata = 'Ödeme linki oluşturulamadı: geçerli bildirim adresi yok.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}

$callback_id = 'EK' . $basvuru_id;
$required = $name . $price . $currency . $max_installment . 'product' . 'tr' . '1';
$paytr_token = base64_encode(hash_hmac('sha256', $required . $merchant_salt, $merchant_key, true));
$post_vals = [
    'merchant_id' => $merchant_id,
    'name' => $name,
    'price' => $price,
    'currency' => $currency,
    'max_installment' => (string)$max_installment,
    'link_type' => 'product',
    'lang' => 'tr',
    'min_count' => '1',
    'max_count' => '1',
    'paytr_token' => $paytr_token,
    'callback_link' => $callback_link,
    'callback_id' => $callback_id,
    'debug_on' => 0,
    'get_qr' => 0,
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
    curl_close($ch);
    $hata = 'Ödeme altyapısına bağlanılamadı.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
curl_close($ch);
$result = json_decode((string)$result, true);
if (!is_array($result) || ($result['status'] ?? '') !== 'success' || empty($result['id'])) {
    $hata = 'Ödeme linki oluşturulamadı.';
    require __DIR__ . '/paytr_odeme_hata.php';
    exit;
}
header('Location: https://www.paytr.com/link/' . trim((string)$result['id']));
exit;

