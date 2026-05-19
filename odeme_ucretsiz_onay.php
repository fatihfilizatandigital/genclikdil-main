<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/sonuc_fiyat_hesap.php';
require_once __DIR__ . '/config/teklif_v2.php';

$token = isset($_POST['t']) ? trim($_POST['t']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$erken_kayit = isset($_POST['erken_kayit']) ? (int)$_POST['erken_kayit'] : 0;
$almanca = isset($_POST['almanca']) ? (int)$_POST['almanca'] : 0;
$pesin = isset($_POST['pesin']) ? (int)$_POST['pesin'] : 0;
$sozlesme_onay = isset($_POST['sozlesme_onay']) ? (int)$_POST['sozlesme_onay'] : 0;

if ($token === '' || strlen($token) > 64 || !ctype_xdigit($token)) {
    header('Location: sonuclar.php');
    exit;
}
if ($sozlesme_onay !== 1) {
    header('Location: sonuclar.php?t=' . urlencode($token) . '&odeme=hata');
    exit;
}

teklif_v2_ensure_schema($conn);
$teklif = teklif_v2_get_by_token($conn, $token);
if (!$teklif || ($teklif['durum'] ?? '') !== 'aktif') {
    header('Location: sonuclar.php?t=' . urlencode($token) . '&odeme=hata');
    exit;
}

$teklif_v2_id = (int)($teklif['id'] ?? 0);
$steps = teklif_v2_get_steps($conn, $teklif_v2_id);
$stepMap = [];
foreach ($steps as $s) {
    $stepMap[$s['adim']] = $s;
}

if (isset($stepMap['toplu']) && (($stepMap['toplu']['durum'] ?? '') === 'success')) {
    header('Location: sonuclar.php?t=' . urlencode($token) . '&odeme=hata');
    exit;
}
if (!isset($stepMap['kurs'])) {
    @mysqli_query($conn, "INSERT INTO gorusme_teklif_odeme_adimlari (teklif_id, adim, sira_no, durum) VALUES (" . (int)$teklif_v2_id . ", 'kurs', 1, 'bekliyor')");
}
if (!isset($stepMap['kitap_materyal'])) {
    @mysqli_query($conn, "INSERT INTO gorusme_teklif_odeme_adimlari (teklif_id, adim, sira_no, durum) VALUES (" . (int)$teklif_v2_id . ", 'kitap_materyal', 2, 'locked')");
}
$steps = teklif_v2_get_steps($conn, $teklif_v2_id);
$stepMap = [];
foreach ($steps as $s) {
    $stepMap[$s['adim']] = $s;
}

$kursStep = $stepMap['kurs'] ?? null;
if (!$kursStep || (($kursStep['durum'] ?? '') === 'success')) {
    header('Location: sonuclar.php?t=' . urlencode($token) . '&odeme=basarili');
    exit;
}

$sinif_ici_sira = isset($teklif['sinif_ici_sira']) && $teklif['sinif_ici_sira'] !== null && $teklif['sinif_ici_sira'] !== '' ? (int)$teklif['sinif_ici_sira'] : 0;
$fiyat = sonuc_fiyat_hesapla($sinif_ici_sira, $erken_kayit, $almanca, $pesin);
$kurs_tutar = (int)($fiyat['kurs_tutar'] ?? 0);
if ($kurs_tutar > 0) {
    header('Location: sonuclar.php?t=' . urlencode($token) . '&odeme=hata');
    exit;
}

$merchant_oid = 'FREE' . $teklif_v2_id . 'K' . time() . substr(preg_replace('/[^a-zA-Z0-9]/', '', uniqid('', true)), -5);
$snapshot_json = json_encode($fiyat['hesap_detay'] ?? [], JSON_UNESCAPED_UNICODE);
if ($snapshot_json === false) $snapshot_json = '{}';

@mysqli_query($conn, "UPDATE gorusme_teklif_v2 SET odeme_modu = 'ayri', updated_at = NOW() WHERE id = " . (int)$teklif_v2_id . " LIMIT 1");
@mysqli_query($conn, "UPDATE gorusme_teklif_odeme_adimlari
    SET durum = 'success', tutar_kurus = 0, merchant_oid = '" . mysqli_real_escape_string($conn, $merchant_oid) . "', fiyat_snapshot_json = '" . mysqli_real_escape_string($conn, $snapshot_json) . "', odendi_at = NOW(), updated_at = NOW()
    WHERE teklif_id = " . (int)$teklif_v2_id . " AND adim = 'kurs' LIMIT 1");

teklif_v2_after_step_success($conn, $teklif_v2_id, 'kurs');

$paytr_cols = [];
$crs = @mysqli_query($conn, "SHOW COLUMNS FROM paytr_odemeler");
if ($crs) {
    while ($c = mysqli_fetch_assoc($crs)) $paytr_cols[] = $c['Field'];
}
if (!empty($paytr_cols)) {
    $fields = ['teklif_id', 'merchant_oid', 'tutar_kurus', 'durum'];
    $vals = [0, $merchant_oid, 0, 'success'];
    if (in_array('teklif_v2_id', $paytr_cols, true)) { $fields[] = 'teklif_v2_id'; $vals[] = $teklif_v2_id; }
    if (in_array('teklif_adim_id', $paytr_cols, true)) { $fields[] = 'teklif_adim_id'; $vals[] = (int)($kursStep['id'] ?? 0); }
    if (in_array('odeme_tipi', $paytr_cols, true)) { $fields[] = 'odeme_tipi'; $vals[] = 'kurs'; }
    if (in_array('fiyat_tablosu_snapshot', $paytr_cols, true)) { $fields[] = 'fiyat_tablosu_snapshot'; $vals[] = $snapshot_json; }
    if (in_array('email', $paytr_cols, true)) { $fields[] = 'email'; $vals[] = $email; }

    $sql = "INSERT INTO paytr_odemeler (" . implode(', ', $fields) . ") VALUES (" . implode(', ', array_fill(0, count($fields), '?')) . ")";
    $ins = mysqli_prepare($conn, $sql);
    if ($ins) {
        $types = '';
        $bind = [];
        foreach ($vals as $i => $v) {
            $types .= is_int($v) ? 'i' : 's';
            $bind[$i] = $v;
        }
        $refs = [];
        foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
        call_user_func_array([$ins, 'bind_param'], array_merge([$types], $refs));
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
    }
}

header('Location: sonuclar.php?t=' . urlencode($token) . '&odeme=basarili');
exit;

