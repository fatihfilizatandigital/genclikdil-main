<?php
/**
 * PayTR bildirim URL — ödeme sonucu bu sayfaya POST ile gelir.
 *
 * Kurulum: PayTR Mağaza Panel > Destek & Kurulum > Ayarlar > Bildirim URL
 * adresine bu sayfanın tam adresini girin (örn. https://genclikdil.com/paytr_bildirim.php).
 * SSL varsa HTTPS kullanın. Bu sayfaya erişim kısıtlaması (üye girişi vb.) konmamalıdır.
 */
$_POST = $_POST ?? [];
if (empty($_POST['merchant_oid']) || empty($_POST['status']) || !isset($_POST['total_amount']) || empty($_POST['hash'])) {
    echo 'OK';
    exit;
}

$config_file = __DIR__ . '/config/paytr.php';
if (!is_file($config_file)) {
    echo 'OK';
    exit;
}
$paytr = require $config_file;
$merchant_key  = $paytr['merchant_key'] ?? '';
$merchant_salt = $paytr['merchant_salt'] ?? '';
if ($merchant_key === '' || $merchant_salt === '') {
    echo 'OK';
    exit;
}

$post = $_POST;
$hash = base64_encode(hash_hmac('sha256', $post['merchant_oid'] . $merchant_salt . $post['status'] . $post['total_amount'], $merchant_key, true));
if ($hash !== $post['hash']) {
    echo 'OK';
    exit;
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/teklif_v2.php';
teklif_v2_ensure_schema($conn);

$merchant_oid = $post['merchant_oid'];
$status       = $post['status'] === 'success' ? 'success' : 'failed';
$total_amount = (int)($post['total_amount'] ?? 0);
$payment_type = isset($post['payment_type']) ? trim($post['payment_type']) : null;
$test_mode    = (int)($post['test_mode'] ?? 0);
$failed_code  = isset($post['failed_reason_code']) ? trim($post['failed_reason_code']) : null;
$failed_msg   = isset($post['failed_reason_msg']) ? trim($post['failed_reason_msg']) : null;

// Erken kayıt kampanya ödemeleri (EK...): teklif akışından bağımsız güncellenir.
if (preg_match('/^EK(\d+)T[0-9A-Za-z]+$/', $merchant_oid)) {
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
    $up = mysqli_prepare($conn, "UPDATE erken_kayit_basvurular
        SET odeme_durumu = ?, paytr_payment_type = ?, test_mode = ?, failed_reason_code = ?, failed_reason_msg = ?, odendi_at = IF(?='success', NOW(), odendi_at), updated_at = NOW()
        WHERE merchant_oid = ?
        LIMIT 1");
    if ($up) {
        mysqli_stmt_bind_param($up, "ssissss", $status, $payment_type, $test_mode, $failed_code, $failed_msg, $status, $merchant_oid);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
    }
    echo 'OK';
    exit;
}

$teklif_id = null;
if (preg_match('/^TK(\d+)[KMT]?\d{10}[A-Za-z0-9]{5,6}$/', $merchant_oid, $m)) {
    $teklif_id = (int)$m[1];
}

$tablo_var = @mysqli_query($conn, "SELECT 1 FROM paytr_odemeler LIMIT 1");
if ($tablo_var) {
    $stmt = mysqli_prepare($conn, "SELECT id, durum, teklif_v2_id, teklif_adim_id, odeme_tipi FROM paytr_odemeler WHERE merchant_oid = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $merchant_oid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($row) {
        if ($row['durum'] === 'success' || $row['durum'] === 'failed') {
            echo 'OK';
            exit;
        }
        $up = mysqli_prepare($conn, "UPDATE paytr_odemeler SET durum = ?, tutar_kurus = ?, paytr_payment_type = ?, test_mode = ?, failed_reason_code = ?, failed_reason_msg = ?, updated_at = NOW() WHERE merchant_oid = ?");
        if ($up) {
            mysqli_stmt_bind_param($up, "sisisss", $status, $total_amount, $payment_type, $test_mode, $failed_code, $failed_msg, $merchant_oid);
            mysqli_stmt_execute($up);
            mysqli_stmt_close($up);
        }

        $teklifV2 = (int)($row['teklif_v2_id'] ?? 0);
        $adimId = (int)($row['teklif_adim_id'] ?? 0);
        if ($teklifV2 > 0 && $adimId > 0) {
            if ($status === 'success') {
                @mysqli_query($conn, "UPDATE gorusme_teklif_odeme_adimlari SET durum = 'success', odendi_at = NOW(), updated_at = NOW() WHERE id = $adimId LIMIT 1");
                $adimRowRes = @mysqli_query($conn, "SELECT adim FROM gorusme_teklif_odeme_adimlari WHERE id = $adimId LIMIT 1");
                $adimRow = $adimRowRes ? mysqli_fetch_assoc($adimRowRes) : null;
                if ($adimRow && !empty($adimRow['adim'])) {
                    teklif_v2_after_step_success($conn, $teklifV2, (string)$adimRow['adim']);
                }
            } else {
                @mysqli_query($conn, "UPDATE gorusme_teklif_odeme_adimlari SET durum = 'failed', updated_at = NOW() WHERE id = $adimId AND durum <> 'success' LIMIT 1");
            }
        }
    } else {
        $ins = mysqli_prepare($conn, "INSERT INTO paytr_odemeler (teklif_id, merchant_oid, tutar_kurus, durum, paytr_payment_type, test_mode, failed_reason_code, failed_reason_msg) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($ins && $teklif_id !== null) {
            mysqli_stmt_bind_param($ins, "isisiss", $teklif_id, $merchant_oid, $total_amount, $status, $payment_type, $test_mode, $failed_code, $failed_msg);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
        }
    }
}

echo 'OK';
exit;
