<?php
/**
 * PayTR Link API bildirim URL.
 * Yanıt: Sadece HTTP 200 + "OK" (öncesi/sonrası çıktı veya 301 yok).
 * 301 alınıyorsa: config base_url, sunucunun yönlendirmediği tam adres olmalı (www / www'siz).
 */
// Önceki çıktıyı temizle; tek çıktı "OK" olacak
while (ob_get_level()) {
    ob_end_clean();
}

$sendOk = function () {
    header('HTTP/1.1 200 OK');
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'OK';
    exit;
};

$_POST = $_POST ?? [];
if (empty($_POST['callback_id']) || empty($_POST['merchant_oid']) || empty($_POST['status']) || !isset($_POST['total_amount']) || empty($_POST['hash'])) {
    $sendOk();
}

$config_file = __DIR__ . '/config/paytr.php';
if (!is_file($config_file)) {
    $sendOk();
}
$paytr = require $config_file;
$merchant_key  = $paytr['merchant_key'] ?? '';
$merchant_salt = $paytr['merchant_salt'] ?? '';
if ($merchant_key === '' || $merchant_salt === '') {
    $sendOk();
}

$post = $_POST;
$hash = base64_encode(hash_hmac('sha256', $post['callback_id'] . $post['merchant_oid'] . $merchant_salt . $post['status'] . $post['total_amount'], $merchant_key, true));
if ($hash !== $post['hash']) {
    $sendOk();
}

$status = $post['status'] === 'success' ? 'success' : 'failed';
$total_amount = (int)($post['total_amount'] ?? 0);
$merchant_oid = trim($post['merchant_oid']);
$callback_id = trim($post['callback_id']);
$payment_type = isset($post['payment_type']) ? trim($post['payment_type']) : null;
$test_mode = (int)($post['test_mode'] ?? 0);
$failed_code = isset($post['failed_reason_code']) ? trim((string)$post['failed_reason_code']) : null;
$failed_msg = isset($post['failed_reason_msg']) ? trim((string)$post['failed_reason_msg']) : null;

$teklif_id = null;
$odeme_tipi = null;
if (preg_match('/^TK(\d+)([KM])?$/', $callback_id, $m)) {
    $teklif_id = (int)$m[1];
    $odeme_tipi = isset($m[2]) && $m[2] === 'K' ? 'kurs' : (isset($m[2]) && $m[2] === 'M' ? 'kitap_materyal' : null);
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/teklif_v2.php';
teklif_v2_ensure_schema($conn);

// Erken kayıt kampanya ödeme callback'i (Link API).
if (preg_match('/^EK(\d+)$/', $callback_id, $m)) {
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
    $basvuru_id = (int)$m[1];
    $up = mysqli_prepare($conn, "UPDATE erken_kayit_basvurular
        SET merchant_oid = IFNULL(NULLIF(merchant_oid,''), ?),
            odeme_durumu = ?,
            paytr_payment_type = ?,
            test_mode = ?,
            failed_reason_code = ?,
            failed_reason_msg = ?,
            odendi_at = IF(?='success', NOW(), odendi_at),
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1");
    if ($up) {
        mysqli_stmt_bind_param($up, "sssisssi", $merchant_oid, $status, $payment_type, $test_mode, $failed_code, $failed_msg, $status, $basvuru_id);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
    }
    $sendOk();
}
if (!empty($conn)) {
    $teklif_id_bind = $teklif_id !== null ? (int)$teklif_id : 0;
    $tablo_var = @mysqli_query($conn, "SELECT 1 FROM paytr_odemeler LIMIT 1");
    $rc = $tablo_var ? @mysqli_query($conn, "SHOW COLUMNS FROM paytr_odemeler LIKE 'odeme_tipi'") : false;
    $has_odeme_tipi = $rc && mysqli_num_rows($rc) > 0;
    if ($tablo_var) {
        $stmt = mysqli_prepare($conn, "SELECT id, durum, teklif_v2_id, teklif_adim_id FROM paytr_odemeler WHERE merchant_oid = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $merchant_oid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if ($row) {
            if ($row['durum'] === 'success') {
                $sendOk();
            }
            $up_sql = "UPDATE paytr_odemeler SET teklif_id = ?, durum = ?, tutar_kurus = ?, paytr_payment_type = ?, test_mode = ?, updated_at = NOW() WHERE merchant_oid = ?";
            if ($has_odeme_tipi && $odeme_tipi !== null) {
                $up_sql = "UPDATE paytr_odemeler SET teklif_id = ?, durum = ?, tutar_kurus = ?, paytr_payment_type = ?, test_mode = ?, odeme_tipi = ?, updated_at = NOW() WHERE merchant_oid = ?";
            }
            $up = mysqli_prepare($conn, $up_sql);
            if ($up) {
                if ($has_odeme_tipi && $odeme_tipi !== null) {
                    mysqli_stmt_bind_param($up, "isisiss", $teklif_id_bind, $status, $total_amount, $payment_type, $test_mode, $odeme_tipi, $merchant_oid);
                } else {
                    mysqli_stmt_bind_param($up, "isisis", $teklif_id_bind, $status, $total_amount, $payment_type, $test_mode, $merchant_oid);
                }
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
            if ($has_odeme_tipi && $odeme_tipi !== null) {
                $ins = mysqli_prepare($conn, "INSERT INTO paytr_odemeler (teklif_id, merchant_oid, tutar_kurus, durum, paytr_payment_type, test_mode, odeme_tipi) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($ins) {
                    mysqli_stmt_bind_param($ins, "isisiss", $teklif_id, $merchant_oid, $total_amount, $status, $payment_type, $test_mode, $odeme_tipi);
                    mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);
                }
            } else {
                $ins = mysqli_prepare($conn, "INSERT INTO paytr_odemeler (teklif_id, merchant_oid, tutar_kurus, durum, paytr_payment_type, test_mode) VALUES (?, ?, ?, ?, ?, ?)");
                if ($ins) {
                    mysqli_stmt_bind_param($ins, "isisii", $teklif_id, $merchant_oid, $total_amount, $status, $payment_type, $test_mode);
                    mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);
                }
            }
        }
    }
}

$sendOk();
