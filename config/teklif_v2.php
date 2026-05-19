<?php
/**
 * Yeni teklif/odeme akisi (v2) yardimci fonksiyonlari.
 */

function teklif_v2_ensure_schema(mysqli $conn): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS gorusme_teklif_v2 (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        gorusme_listesi_id INT UNSIGNED NOT NULL,
        sinif_ici_sira INT UNSIGNED DEFAULT NULL,
        sinav_sonuc_id_snapshot INT UNSIGNED DEFAULT NULL,
        odeme_modu VARCHAR(20) NOT NULL DEFAULT 'ayri',
        durum VARCHAR(20) NOT NULL DEFAULT 'aktif',
        paylasim_token VARCHAR(64) NOT NULL,
        fiyat_snapshot_json MEDIUMTEXT NULL,
        personel VARCHAR(100) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_paylasim_token_v2 (paylasim_token),
        KEY idx_gorusme_listesi_v2 (gorusme_listesi_id),
        KEY idx_durum_v2 (durum)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS gorusme_teklif_odeme_adimlari (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        teklif_id INT UNSIGNED NOT NULL,
        adim VARCHAR(20) NOT NULL,
        sira_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
        tutar_kurus INT UNSIGNED NOT NULL DEFAULT 0,
        durum VARCHAR(20) NOT NULL DEFAULT 'bekliyor',
        merchant_oid VARCHAR(64) DEFAULT NULL,
        fiyat_snapshot_json MEDIUMTEXT NULL,
        odendi_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_teklif_adim (teklif_id, adim),
        KEY idx_teklif_adim_durum (teklif_id, durum),
        KEY idx_merchant_oid_v2 (merchant_oid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $paytrCols = [];
    $rs = @mysqli_query($conn, "SHOW COLUMNS FROM paytr_odemeler");
    if ($rs) {
        while ($c = mysqli_fetch_assoc($rs)) {
            $paytrCols[] = strtolower($c['Field'] ?? '');
        }
    }
    if (!in_array('teklif_v2_id', $paytrCols, true)) {
        @mysqli_query($conn, "ALTER TABLE paytr_odemeler ADD COLUMN teklif_v2_id INT UNSIGNED NULL DEFAULT NULL AFTER teklif_id");
    }
    if (!in_array('teklif_adim_id', $paytrCols, true)) {
        @mysqli_query($conn, "ALTER TABLE paytr_odemeler ADD COLUMN teklif_adim_id INT UNSIGNED NULL DEFAULT NULL AFTER teklif_v2_id");
    }

    $glCols = [];
    $grs = @mysqli_query($conn, "SHOW COLUMNS FROM gorusme_listesi");
    if ($grs) {
        while ($c = mysqli_fetch_assoc($grs)) {
            $glCols[] = strtolower($c['Field'] ?? '');
        }
    }
    if (!in_array('sinav_sonuc_id_ingilizce', $glCols, true)) {
        @mysqli_query($conn, "ALTER TABLE gorusme_listesi ADD COLUMN sinav_sonuc_id_ingilizce INT UNSIGNED NULL DEFAULT NULL AFTER sinav_sonuc_id");
    }
    if (!in_array('sinav_sonuc_id_almanca', $glCols, true)) {
        @mysqli_query($conn, "ALTER TABLE gorusme_listesi ADD COLUMN sinav_sonuc_id_almanca INT UNSIGNED NULL DEFAULT NULL AFTER sinav_sonuc_id_ingilizce");
    }
    if (!in_array('basari_yuzdesi_ingilizce', $glCols, true)) {
        @mysqli_query($conn, "ALTER TABLE gorusme_listesi ADD COLUMN basari_yuzdesi_ingilizce DECIMAL(5,2) NULL DEFAULT NULL AFTER basari_yuzdesi");
    }
    if (!in_array('basari_yuzdesi_almanca', $glCols, true)) {
        @mysqli_query($conn, "ALTER TABLE gorusme_listesi ADD COLUMN basari_yuzdesi_almanca DECIMAL(5,2) NULL DEFAULT NULL AFTER basari_yuzdesi_ingilizce");
    }

    $initialized = true;
}

function teklif_v2_get_by_token(mysqli $conn, string $token): ?array
{
    $q = mysqli_prepare($conn, "SELECT t.*, g.ogrenci_ad, g.ogrenci_soyad, g.veli_ad, g.veli_soyad, g.tel_orijinal, g.sinif, g.sinav_turu, g.basari_yuzdesi AS liste_basari, g.sinav_sonuc_id, g.sinav_sonuc_id_ingilizce, g.sinav_sonuc_id_almanca, g.basari_yuzdesi_ingilizce, g.basari_yuzdesi_almanca, g.personel AS son_islem_personel
        FROM gorusme_teklif_v2 t
        INNER JOIN gorusme_listesi g ON g.id = t.gorusme_listesi_id
        WHERE t.paylasim_token = ? AND t.durum IN ('aktif', 'kilitli')
        LIMIT 1");
    if (!$q) {
        return null;
    }
    mysqli_stmt_bind_param($q, "s", $token);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    mysqli_stmt_close($q);
    return $row ?: null;
}

function teklif_v2_get_latest_by_gorusme(mysqli $conn, int $gorusmeListesiId): ?array
{
    $q = mysqli_prepare($conn, "SELECT * FROM gorusme_teklif_v2 WHERE gorusme_listesi_id = ? ORDER BY id DESC LIMIT 1");
    if (!$q) {
        return null;
    }
    mysqli_stmt_bind_param($q, "i", $gorusmeListesiId);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    mysqli_stmt_close($q);
    return $row ?: null;
}

function teklif_v2_get_steps(mysqli $conn, int $teklifId): array
{
    $out = [];
    $q = mysqli_prepare($conn, "SELECT * FROM gorusme_teklif_odeme_adimlari WHERE teklif_id = ? ORDER BY sira_no ASC, id ASC");
    if (!$q) {
        return $out;
    }
    mysqli_stmt_bind_param($q, "i", $teklifId);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);
    while ($r && $row = mysqli_fetch_assoc($r)) {
        $out[] = $row;
    }
    mysqli_stmt_close($q);
    return $out;
}

function teklif_v2_create(mysqli $conn, int $gorusmeListesiId, ?int $sinifIciSira, ?int $sinavSonucId, string $odemeModu, ?string $personel = null): ?array
{
    $odemeModu = $odemeModu === 'toplu' ? 'toplu' : 'ayri';
    $token = bin2hex(random_bytes(16));
    $durum = 'aktif';
    $snap = '{}';

    $q = mysqli_prepare($conn, "INSERT INTO gorusme_teklif_v2 (gorusme_listesi_id, sinif_ici_sira, sinav_sonuc_id_snapshot, odeme_modu, durum, paylasim_token, fiyat_snapshot_json, personel)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$q) {
        return null;
    }
    $siraVal = $sinifIciSira !== null ? $sinifIciSira : null;
    $sinavVal = $sinavSonucId !== null ? $sinavSonucId : null;
    mysqli_stmt_bind_param($q, "iiisssss", $gorusmeListesiId, $siraVal, $sinavVal, $odemeModu, $durum, $token, $snap, $personel);
    mysqli_stmt_execute($q);
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($q);
    if ($newId <= 0) {
        return null;
    }

    if ($odemeModu === 'toplu') {
        $a = mysqli_prepare($conn, "INSERT INTO gorusme_teklif_odeme_adimlari (teklif_id, adim, sira_no, durum) VALUES (?, 'toplu', 1, 'bekliyor')");
        if ($a) {
            mysqli_stmt_bind_param($a, "i", $newId);
            mysqli_stmt_execute($a);
            mysqli_stmt_close($a);
        }
    } else {
        $a1 = mysqli_prepare($conn, "INSERT INTO gorusme_teklif_odeme_adimlari (teklif_id, adim, sira_no, durum) VALUES (?, 'kurs', 1, 'bekliyor')");
        if ($a1) {
            mysqli_stmt_bind_param($a1, "i", $newId);
            mysqli_stmt_execute($a1);
            mysqli_stmt_close($a1);
        }
        $a2 = mysqli_prepare($conn, "INSERT INTO gorusme_teklif_odeme_adimlari (teklif_id, adim, sira_no, durum) VALUES (?, 'kitap_materyal', 2, 'locked')");
        if ($a2) {
            mysqli_stmt_bind_param($a2, "i", $newId);
            mysqli_stmt_execute($a2);
            mysqli_stmt_close($a2);
        }
    }

    return teklif_v2_get_latest_by_gorusme($conn, $gorusmeListesiId);
}

function teklif_v2_after_step_success(mysqli $conn, int $teklifId, string $adim): void
{
    if ($adim === 'kurs') {
        @mysqli_query($conn, "UPDATE gorusme_teklif_odeme_adimlari SET durum = 'bekliyor' WHERE teklif_id = " . (int)$teklifId . " AND adim = 'kitap_materyal' AND durum = 'locked'");
    }

    $allDone = false;
    $q = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM gorusme_teklif_odeme_adimlari WHERE teklif_id = ? AND durum <> 'success'");
    if ($q) {
        mysqli_stmt_bind_param($q, "i", $teklifId);
        mysqli_stmt_execute($q);
        $r = mysqli_stmt_get_result($q);
        $row = $r ? mysqli_fetch_assoc($r) : null;
        mysqli_stmt_close($q);
        $allDone = $row && (int)$row['c'] === 0;
    }
    if ($allDone) {
        @mysqli_query($conn, "UPDATE gorusme_teklif_v2 SET durum = 'kilitli' WHERE id = " . (int)$teklifId . " LIMIT 1");
    }
}

