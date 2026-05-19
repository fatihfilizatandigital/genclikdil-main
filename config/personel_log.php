<?php
/**
 * Admin panel işlem logu — personel yaptığı form gönderimlerini kaydeder.
 * Kullanım: require_once __DIR__ . '/../config/personel_log.php'; (veya ../../config/ admin/panel için)
 *          personel_log_ekle($conn, 'gorusmeler.php', 'guncelle', $_POST);
 */

/**
 * Şifre ve hassas alanları form verisinden çıkarır.
 */
function personel_log_temizle(array $data) {
    $gizlenecek = ['sifre', 'password', 'parola', 'token', 'csrf', 'paylasim_token'];
    foreach ($gizlenecek as $key) {
        foreach (array_keys($data) as $k) {
            if (stripos($k, $key) !== false) {
                $data[$k] = '***';
            }
        }
    }
    return $data;
}

/**
 * Personel işlemini log tablosuna yazar.
 *
 * @param mysqli $conn Veritabanı bağlantısı
 * @param string $sayfa Sayfa dosya adı (örn. gorusmeler.php, panel/not-giris.php)
 * @param string $islem İşlem türü: kaydet, guncelle, sil, listele, giris, vb.
 * @param array $form_verisi Form verisi (örn. $_POST); şifre alanları otomatik maskelenir
 * @param array $ekstra Opsiyonel ek alanlar (örn. ['hedef_id' => 123])
 * @return bool Başarılı ise true
 */
function personel_log_ekle($conn, $sayfa, $islem, $form_verisi = [], $ekstra = []) {
    static $tablo_yarandi = null;
    if ($tablo_yarandi === null) {
        $tablo_yarandi = @mysqli_query($conn, "SELECT 1 FROM personel_islem_log LIMIT 1") !== false;
        if (!$tablo_yarandi) {
            return false;
        }
    }

    $personel_adi = isset($_SESSION['personel_adi']) ? trim((string) $_SESSION['personel_adi']) : '';
    if ($personel_adi === '') {
        $personel_adi = 'Anonim';
    }

    $sayfa = trim((string) $sayfa);
    $islem = trim((string) $islem);
    $form_verisi = is_array($form_verisi) ? $form_verisi : [];
    $form_verisi = personel_log_temizle($form_verisi);
    $birlesik = array_merge($form_verisi, $ekstra);
    $json = json_encode($birlesik, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{}';
    }
    $max_len = 65535;
    if (strlen($json) > $max_len) {
        $json = substr($json, 0, $max_len - 20) . '"...(kesildi)"}';
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr(trim((string) $_SERVER['REMOTE_ADDR']), 0, 45) : '';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr(trim((string) $_SERVER['HTTP_USER_AGENT']), 0, 500) : '';

    $stmt = mysqli_prepare($conn,
        "INSERT INTO personel_islem_log (personel_adi, sayfa, islem, form_verisi, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ssssss', $personel_adi, $sayfa, $islem, $json, $ip, $ua);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}
