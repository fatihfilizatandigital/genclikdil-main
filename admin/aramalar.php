<?php
// aramalar.php - Randevu Tarihi Görünür Sürüm (admin klasörü)
require_once __DIR__ . '/auth.php';
ob_start();

if (isset($_GET['cikis'])) {
    session_destroy();
    header("Location: ../giris.php");
    exit;
}

$aktif_personel = $_SESSION['personel_adi'] ?? '';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Veritabanı (host ve port ayrı verilmeli)
$conn = mysqli_connect("213.142.130.21", "adnan", "adnan.1234", "farmMysql", 3306);
mysqli_set_charset($conn, "utf8mb4");

if (!$conn) {
    error_log("aramalar.php DB: " . mysqli_connect_error());
    header("Content-Type: text/html; charset=utf-8");
    die("Veritabanı bağlantısı kurulamadı. Lütfen daha sonra tekrar deneyin.");
}
require_once __DIR__ . '/../config/personel_log.php';

$aktif_personel = mysqli_real_escape_string($conn, $aktif_personel);

// Bu sayfayı açan kullanıcının son aktivitesini güncelle
$sql_upsert_aktif = "
    INSERT INTO aktif_kullanicilar (kadi, last_activity)
    VALUES ('$aktif_personel', NOW())
    ON DUPLICATE KEY UPDATE last_activity = VALUES(last_activity)
";
mysqli_query($conn, $sql_upsert_aktif);

// Pop-up oluşturma (admin veya gizli butonla yetkili kullanıcı)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['popup_olustur'])) {
    $popup_secret_flag = $_POST['popup_secret'] ?? '';
    $popup_yetkili = ($aktif_personel === 'admin' || $popup_secret_flag === '1');

    if ($popup_yetkili) {
        $popup_metin   = mysqli_real_escape_string($conn, trim($_POST['popup_metin'] ?? ''));
        $popup_resim   = mysqli_real_escape_string($conn, trim($_POST['popup_resim'] ?? ''));
        $hedef_kullanicilar = $_POST['popup_hedefler'] ?? [];

        if (!empty($hedef_kullanicilar) && ($popup_metin !== '' || $popup_resim !== '')) {
            foreach ($hedef_kullanicilar as $kadi) {
                $kadi_db = mysqli_real_escape_string($conn, trim($kadi));
                if ($kadi_db === '') continue;
                $sql_insert_popup = "INSERT INTO popup_duyurular (gonderen, hedef_kadi, icerik, resim_url, aktif)
                                     VALUES ('" . mysqli_real_escape_string($conn, $aktif_personel) . "', '$kadi_db', '$popup_metin', '$popup_resim', 1)";
                mysqli_query($conn, $sql_insert_popup);
            }
        }
        @personel_log_ekle($conn, 'aramalar.php', 'popup_olustur', $_POST);
    }
    // Sayfada kal, URL'yi temizle
    header("Location: aramalar.php");
    exit;
}

// Kullanıcı kendi ekranındaki aktif pop-up'ı kapattığında
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['popup_kapat']) && isset($_POST['popup_id'])) {
    $popup_id = (int)$_POST['popup_id'];
    if ($popup_id > 0) {
        mysqli_query($conn, "UPDATE popup_duyurular SET aktif = 0 WHERE id = $popup_id AND hedef_kadi = '$aktif_personel'");
        @personel_log_ekle($conn, 'aramalar.php', 'popup_kapat', $_POST);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}

// Diğer kullanıcı ekranında pop-up kontrolü (AJAX)
if (isset($_GET['popup_check']) && $_GET['popup_check'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $popup_sql = "SELECT id, icerik, resim_url 
                  FROM popup_duyurular 
                  WHERE hedef_kadi = '$aktif_personel' AND aktif = 1 
                  ORDER BY id DESC 
                  LIMIT 1";
    $popup_res = mysqli_query($conn, $popup_sql);
    if ($popup_res && mysqli_num_rows($popup_res) > 0) {
        $p = mysqli_fetch_assoc($popup_res);
        echo json_encode([
            'hasPopup' => true,
            'id'       => (int)$p['id'],
            'text'     => $p['icerik'] ?? '',
            'image'    => $p['resim_url'] ?? ''
        ]);
    } else {
        echo json_encode(['hasPopup' => false]);
    }
    exit;
}

// Genel arama (veli / öğrenci isimlerine göre) - AJAX HTML döner
if (isset($_GET['arama_q'])) {
    header('Content-Type: text/html; charset=utf-8');
    $aranan = trim($_GET['arama_q'] ?? '');
    if ($aranan === '' || mb_strlen($aranan, 'UTF-8') < 2) {
        echo '<div class="text-muted">En az 2 karakter yazınız.</div>';
        exit;
    }
    $q = mysqli_real_escape_string($conn, $aranan);
    $like = "%" . $q . "%";

    // Telefon araması için: girilen değerden rakamları ayıkla
    $aranan_tel_raw = preg_replace('/\D+/', '', $aranan);
    $aranan_tel_raw = $aranan_tel_raw ?? '';
    $tel_kosul = '';
    if ($aranan_tel_raw !== '' && strlen($aranan_tel_raw) >= 4) {
        $q_tel = mysqli_real_escape_string($conn, $aranan_tel_raw);
        // tel_temiz zaten temiz formatta tutuluyor; parça eşleştirme yap
        $tel_kosul = " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tel_temiz, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE '%{$q_tel}%'";
    }

    $sql_arama = "
        SELECT
            MIN(veli_ad)       AS veli_ad,
            MIN(veli_soyad)    AS veli_soyad,
            MIN(ogrenci_ad)    AS ogrenci_ad,
            MIN(ogrenci_soyad) AS ogrenci_soyad,
            tel_temiz
        FROM cagri_listesi
        WHERE 
            CONCAT(veli_ad, ' ', veli_soyad) LIKE '$like'
            OR veli_ad LIKE '$like'
            OR veli_soyad LIKE '$like'
            OR CONCAT(ogrenci_ad, ' ', ogrenci_soyad) LIKE '$like'
            OR ogrenci_ad LIKE '$like'
            OR ogrenci_soyad LIKE '$like'
            $tel_kosul
        GROUP BY tel_temiz
        ORDER BY veli_ad ASC, veli_soyad ASC
        LIMIT 30
    ";
    $res_arama = mysqli_query($conn, $sql_arama);

    if (!$res_arama || mysqli_num_rows($res_arama) === 0) {
        echo '<div class="text-muted">Eşleşen kayıt bulunamadı.</div>';
        exit;
    }

    echo '<div class="list-group list-group-flush">';
    while ($row = mysqli_fetch_assoc($res_arama)) {
        $veli  = trim(($row['veli_ad'] ?? '') . ' ' . ($row['veli_soyad'] ?? ''));
        $ogr   = trim(($row['ogrenci_ad'] ?? '') . ' ' . ($row['ogrenci_soyad'] ?? ''));
        $tel   = htmlspecialchars($row['tel_temiz'] ?? '', ENT_QUOTES, 'UTF-8');
        $veli_h = htmlspecialchars($veli, ENT_QUOTES, 'UTF-8');
        $ogr_h  = htmlspecialchars($ogr, ENT_QUOTES, 'UTF-8');
        echo '<button type="button" class="list-group-item list-group-item-action py-1 px-2" onclick="aramaKaydaGit(\'' . $tel . '\')">';
        echo '<div class="fw-bold small">' . ($veli_h !== '' ? $veli_h : 'Veli Bilgisi Yok') . '</div>';
        if ($ogr_h !== '') {
            echo '<div class="text-muted small">🎓 ' . $ogr_h . '</div>';
        }
        echo '</button>';
    }
    echo '</div>';
    exit;
}

$mesaj = "";
$mesajTur = "";
$secilen_sinif = 0;
$aile_verisi = []; 
$sinif_listesi = [];
$personel_db = $aktif_personel;

// Sınıf bilgisini al
if (isset($_GET['sinif']) && $_GET['sinif'] !== '') $secilen_sinif = (int)$_GET['sinif'];
elseif (isset($_POST['sinif_id']) && $_POST['sinif_id'] !== '') $secilen_sinif = (int)$_POST['sinif_id'];

// Mesajı URL'den al
if (isset($_GET['sonuc'])) {
    if($_GET['sonuc'] == 'kaydedildi') { $mesaj = "✅ Kayıt güncellendi."; $mesajTur = "success"; }
    if($_GET['sonuc'] == 'atlandi') { $mesaj = "⏩ Kayıt pas geçildi."; $mesajTur = "warning"; }
    if($_GET['sonuc'] == 'hata') { $mesaj = "❌ Bir hata oluştu."; $mesajTur = "danger"; }
}

// --- 1. SOL MENÜ: RANDEVULAR ---
$gecmis_randevular = isset($_GET['gecmis_randevular']) && $_GET['gecmis_randevular'] === '1';
$randevu_sinif_filtre = isset($_GET['r_sinif']) ? (int)$_GET['r_sinif'] : 0;
$randevu_tarih_kosul = $gecmis_randevular ? "randevu_tarihi < NOW()" : "randevu_tarihi >= NOW()";

// Randevular için sınıf listesi (filtre dropdown'u)
$sql_randevu_siniflar = "SELECT DISTINCT sinif 
                         FROM cagri_listesi 
                         WHERE randevu_tarihi IS NOT NULL
                         ORDER BY sinif ASC";
$res_randevu_siniflar = mysqli_query($conn, $sql_randevu_siniflar);

$sinif_filtre_kosul = "";
if ($randevu_sinif_filtre > 0) {
    $sinif_filtre_kosul = " AND EXISTS (
        SELECT 1 FROM cagri_listesi c3
        WHERE c3.tel_temiz = cagri_listesi.tel_temiz
          AND c3.sinif = " . (int)$randevu_sinif_filtre . "
    )";
}

$sql_randevular = "SELECT DISTINCT 
                       veli_ad, 
                       veli_soyad, 
                       tel_temiz, 
                       randevu_tarihi, 
                       arama_durumu, 
                       personel, 
                       COALESCE(randevu_durumu, 'Bekleniyor') AS randevu_durumu,
                       (SELECT GROUP_CONCAT(DISTINCT CONCAT(ogrenci_ad, ' ', ogrenci_soyad, ' (', sinif, '. Sınıf)') SEPARATOR ', ')
                        FROM cagri_listesi c2
                        WHERE c2.tel_temiz = cagri_listesi.tel_temiz) AS ogrenciler
                   FROM cagri_listesi 
                   WHERE ((arama_durumu = 'Randevu Alindi' AND randevu_tarihi IS NOT NULL)
                   OR (arama_durumu = 'Islemde' AND personel = '$personel_db' AND randevu_tarihi IS NOT NULL))
                   AND $randevu_tarih_kosul
                   $sinif_filtre_kosul
                   ORDER BY randevu_tarihi " . ($gecmis_randevular ? "DESC" : "ASC");
$res_randevular = mysqli_query($conn, $sql_randevular);
if (!$res_randevular && mysqli_errno($conn) === 1054) {
    // randevu_durumu kolonu yoksa migration öncesi: kolonsuz sorgu
    $sql_randevular = "SELECT DISTINCT 
                           veli_ad, 
                           veli_soyad, 
                           tel_temiz, 
                           randevu_tarihi, 
                           arama_durumu, 
                           personel, 
                           'Bekleniyor' AS randevu_durumu,
                           (SELECT GROUP_CONCAT(DISTINCT CONCAT(ogrenci_ad, ' ', ogrenci_soyad, ' (', sinif, '. Sınıf)') SEPARATOR ', ')
                            FROM cagri_listesi c2
                            WHERE c2.tel_temiz = cagri_listesi.tel_temiz) AS ogrenciler
                       FROM cagri_listesi 
                       WHERE ((arama_durumu = 'Randevu Alindi' AND randevu_tarihi IS NOT NULL)
                       OR (arama_durumu = 'Islemde' AND personel = '$personel_db' AND randevu_tarihi IS NOT NULL))
                       AND $randevu_tarih_kosul
                       $sinif_filtre_kosul
                       ORDER BY randevu_tarihi " . ($gecmis_randevular ? "DESC" : "ASC");
    $res_randevular = mysqli_query($conn, $sql_randevular);
}

// --- 2. SAĞ MENÜ: SINIF LİSTESİ ---
$sinif_durum_adet = []; // Renklerin anlamı için sınıfa göre durum adetleri
if ($secilen_sinif > 0) {
    $res_sinif_list = mysqli_query(
        $conn,
        "SELECT
            MIN(veli_ad)       AS veli_ad,
            MIN(veli_soyad)    AS veli_soyad,
            tel_temiz,
            MIN(arama_durumu)  AS arama_durumu,
            MIN(personel)      AS personel
         FROM cagri_listesi
         WHERE sinif = " . (int)$secilen_sinif . "
         GROUP BY tel_temiz
         ORDER BY
           CASE MIN(arama_durumu)
             WHEN 'Bekliyor' THEN 1
             WHEN 'Tekrar ara' THEN 2
             WHEN 'Ertelendi' THEN 3
             WHEN 'Randevu Alindi' THEN 4
             WHEN 'Islemde' THEN 5
             WHEN 'Ulasilamadi' THEN 6
             WHEN 'Katilmak İstemiyor' THEN 7
             ELSE 8
           END,
           veli_ad ASC"
    );
    // Sınıfa göre durum bazında kayıt (veli) sayıları
    $res_durum_adet = mysqli_query($conn,
        "SELECT durum, COUNT(*) AS adet FROM (
            SELECT tel_temiz, MIN(arama_durumu) AS durum
            FROM cagri_listesi
            WHERE sinif = " . (int)$secilen_sinif . "
            GROUP BY tel_temiz
        ) t
        GROUP BY durum"
    );
    if ($res_durum_adet) {
        while ($ra = mysqli_fetch_assoc($res_durum_adet)) {
            $sinif_durum_adet[$ra['durum']] = (int)$ra['adet'];
        }
    }
}

// --- 3. İŞLEM KAYDETME (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $islem_tel = mysqli_real_escape_string($conn, trim($_POST['islem_tel'] ?? ''));

    if (isset($_POST['btn_kaydet'])) {
        $izinli_durumlar = ['Randevu Alindi', 'Ulasilamadi', 'Katilmak İstemiyor', 'Tekrar ara', 'Ertelendi', 'Bekliyor'];
        $durum = in_array($_POST['durum'] ?? '', $izinli_durumlar) ? $_POST['durum'] : 'Bekliyor';
        $notlar = mysqli_real_escape_string($conn, $_POST['notlar'] ?? '');

        $randevu_tarihi_sql = "NULL"; 
        if ($durum == 'Randevu Alindi' && !empty($_POST['randevu_tarihi'])) {
            $temiz_tarih = str_replace("T", " ", $_POST['randevu_tarihi']);
            $temiz_tarih .= ":00"; 
            $randevu_tarihi_sql = "'" . mysqli_real_escape_string($conn, $temiz_tarih) . "'";
        }
        $randevu_durum_izin = ['Bekleniyor', 'Geldi', 'Gelmedi'];
        $randevu_durumu_val = in_array($_POST['randevu_durumu'] ?? '', $randevu_durum_izin) ? $_POST['randevu_durumu'] : 'Bekleniyor';
        $randevu_durumu_sql = ($durum == 'Randevu Alindi') ? "'" . mysqli_real_escape_string($conn, $randevu_durumu_val) . "'" : "NULL";

        if (!empty($islem_tel)) {
            $sql_update = "UPDATE cagri_listesi SET 
                           arama_durumu = '$durum', 
                           arama_notu = '$notlar', 
                           randevu_tarihi = $randevu_tarihi_sql, 
                           randevu_durumu = $randevu_durumu_sql, 
                           personel = '$personel_db', 
                           islem_tarihi = NOW() 
                           WHERE tel_temiz = '$islem_tel'";

            if (mysqli_query($conn, $sql_update)) {
                @personel_log_ekle($conn, 'aramalar.php', 'kaydet', $_POST);
                $redir = "aramalar.php?sinif=$secilen_sinif&getir_tel=" . urlencode($islem_tel) . "&sonuc=kaydedildi";
                if ($gecmis_randevular) $redir .= "&gecmis_randevular=1";
                if ($randevu_sinif_filtre > 0) $redir .= "&r_sinif=" . $randevu_sinif_filtre;
                header("Location: " . $redir);
                exit; 
            }
            if (mysqli_errno($conn) === 1054) {
                $sql_update = "UPDATE cagri_listesi SET 
                               arama_durumu = '$durum', 
                               arama_notu = '$notlar', 
                               randevu_tarihi = $randevu_tarihi_sql, 
                               personel = '$personel_db', 
                               islem_tarihi = NOW() 
                               WHERE tel_temiz = '$islem_tel'";
                if (mysqli_query($conn, $sql_update)) {
                    @personel_log_ekle($conn, 'aramalar.php', 'kaydet', $_POST);
                    $redir = "aramalar.php?sinif=$secilen_sinif&getir_tel=" . urlencode($islem_tel) . "&sonuc=kaydedildi";
                    if ($gecmis_randevular) $redir .= "&gecmis_randevular=1";
                    if ($randevu_sinif_filtre > 0) $redir .= "&r_sinif=" . $randevu_sinif_filtre;
                    header("Location: " . $redir);
                    exit;
                }
            }
            $redir = "aramalar.php?sinif=$secilen_sinif&sonuc=hata";
            if ($gecmis_randevular) $redir .= "&gecmis_randevular=1";
            if ($randevu_sinif_filtre > 0) $redir .= "&r_sinif=" . $randevu_sinif_filtre;
            if (!empty($islem_tel)) $redir .= "&getir_tel=" . urlencode($islem_tel);
            header("Location: " . $redir);
            exit;
        } else {
            $redir = "aramalar.php?sinif=$secilen_sinif&sonuc=hata";
            if ($gecmis_randevular) $redir .= "&gecmis_randevular=1";
            if ($randevu_sinif_filtre > 0) $redir .= "&r_sinif=" . $randevu_sinif_filtre;
            header("Location: " . $redir);
            exit;
        }
    }
}

// --- 4. NUMARA GETİRME (GET) ---
// Bitmiş durumlar: sadece görüntüleme, veritabanına dokunma
$bitmis_durumlar = ['Randevu Alindi', 'Ulasilamadi', 'Katilmak İstemiyor', 'Tekrar ara', 'Ertelendi'];
$bulunan_tel = "";

if (isset($_GET['getir_tel']) && !empty($_GET['getir_tel'])) {
    mysqli_query($conn, "UPDATE cagri_listesi SET arama_durumu = 'Bekliyor', personel = NULL WHERE personel = '$personel_db' AND arama_durumu = 'Islemde' AND randevu_tarihi IS NULL");
    mysqli_query($conn, "UPDATE cagri_listesi SET arama_durumu = 'Randevu Alindi', personel = NULL WHERE personel = '$personel_db' AND arama_durumu = 'Islemde' AND randevu_tarihi IS NOT NULL");
    
    $bulunan_tel = mysqli_real_escape_string($conn, $_GET['getir_tel']);
    // Önce bu numaranın mevcut durumunu al; bitmişse sadece göster, güncelleme yapma
    $mevcut_sql = "SELECT arama_durumu FROM cagri_listesi WHERE tel_temiz = '$bulunan_tel' LIMIT 1";
    $mevcut_res = mysqli_query($conn, $mevcut_sql);
    $mevcut_row = $mevcut_res ? mysqli_fetch_assoc($mevcut_res) : null;
    $mevcut_durum = $mevcut_row['arama_durumu'] ?? '';
    if (!in_array($mevcut_durum, $bitmis_durumlar)) {
        mysqli_query($conn, "UPDATE cagri_listesi SET arama_durumu = 'Islemde', personel = '$personel_db', islem_tarihi = NOW() WHERE tel_temiz = '$bulunan_tel'");
    }
}
elseif ($secilen_sinif > 0) {
    // Sınıf seçildi, listeden kimse tıklanmadı: listenin ilk kaydını aç (rastgele yok)
    mysqli_query($conn, "UPDATE cagri_listesi SET arama_durumu = 'Bekliyor', personel = NULL WHERE personel = '$personel_db' AND arama_durumu = 'Islemde' AND randevu_tarihi IS NULL");
    mysqli_query($conn, "UPDATE cagri_listesi SET arama_durumu = 'Randevu Alindi', personel = NULL WHERE personel = '$personel_db' AND arama_durumu = 'Islemde' AND randevu_tarihi IS NOT NULL");
    $sql_ilk = "SELECT tel_temiz FROM cagri_listesi WHERE sinif = " . (int)$secilen_sinif . " GROUP BY tel_temiz ORDER BY
        CASE MIN(arama_durumu) WHEN 'Bekliyor' THEN 1 WHEN 'Tekrar ara' THEN 2 WHEN 'Ertelendi' THEN 3 WHEN 'Randevu Alindi' THEN 4 WHEN 'Islemde' THEN 5 WHEN 'Ulasilamadi' THEN 6 WHEN 'Katilmak İstemiyor' THEN 7 ELSE 8 END,
        MIN(veli_ad) ASC LIMIT 1";
    $res_ilk = mysqli_query($conn, $sql_ilk);
    if ($res_ilk && mysqli_num_rows($res_ilk) > 0) {
        $row_ilk = mysqli_fetch_assoc($res_ilk);
        $ilk_tel = $row_ilk['tel_temiz'];
        $cek = mysqli_query($conn, "SELECT arama_durumu FROM cagri_listesi WHERE tel_temiz = '" . mysqli_real_escape_string($conn, $ilk_tel) . "' LIMIT 1");
        $durum_ilk = ($cek && $r = mysqli_fetch_assoc($cek)) ? ($r['arama_durumu'] ?? '') : '';
        if (!in_array($durum_ilk, $bitmis_durumlar)) {
            mysqli_query($conn, "UPDATE cagri_listesi SET arama_durumu = 'Islemde', personel = '$personel_db', islem_tarihi = NOW() WHERE tel_temiz = '" . mysqli_real_escape_string($conn, $ilk_tel) . "'");
        }
        $bulunan_tel = $ilk_tel;
    }
}

// Detayları Çek
$getir_tel_istendi = isset($_GET['getir_tel']) && $_GET['getir_tel'] !== '';
if (!empty($bulunan_tel)) {
    $sql_aile = "SELECT * FROM cagri_listesi WHERE tel_temiz = '$bulunan_tel'";
    $res_aile = mysqli_query($conn, $sql_aile);
    while($row = mysqli_fetch_assoc($res_aile)) {
        $aile_verisi[] = $row;
    }
    // URL'de getir_tel vardı ama bu numara listede yoksa mesaj göster
    if ($getir_tel_istendi && empty($aile_verisi) && empty($mesaj)) {
        $mesaj = "Bu numara listede bulunamadı.";
        $mesajTur = "warning";
    }
}

$result_siniflar = mysqli_query($conn, "SELECT DISTINCT sinif FROM cagri_listesi ORDER BY sinif ASC");
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call Center CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; overflow-x: hidden; }
        .top-bar { background: #fff; padding: 10px 20px; border-bottom: 1px solid #ddd; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .sidebar-scroll { height: calc(100vh - 150px); overflow-y: auto; background: #fff; border-radius: 8px; padding: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .veli-box { background: #fff; padding: 20px; border-radius: 10px; border-left: 5px solid #0d6efd; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .tel-link { font-size: 1.5rem; font-weight: bold; text-decoration: none; color: #0d6efd; }
        .randevu-item { border-bottom: 1px solid #eee; padding: 8px 0; cursor: pointer; font-size: 0.9rem; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 4px; }
        .randevu-item:hover { background-color: #f0f8ff; color: #0d6efd; padding-left: 5px; transition: 0.2s; }
        .randevu-active { background-color: #e7f1ff; border-left: 3px solid #0d6efd; }
        .randevu-personel { font-size: 0.75rem; color: #6c757d; text-align: right; flex-shrink: 0; }
        .class-list-item { border-bottom: 1px solid #f0f0f0; padding: 6px 0; cursor: pointer; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center; }
        .class-list-item:hover { background-color: #f0f8ff; padding-left: 3px; transition: 0.2s; }
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .status-bekliyor { background-color: #ffc107; } 
        .status-tekrar-ara { background-color: #9b59b6; }
        .status-ertelendi { background-color: #fd7e14; }
        .status-randevu { background-color: #198754; } 
        .status-ulasilamadi { background-color: #dc3545; } 
        .status-katilmadi { background-color: #6c757d; }
        .status-islemde { background-color: #0d6efd; } 
        .active-row { background-color: #e9ecef; font-weight: bold; border-left: 3px solid #0d6efd; padding-left: 5px; }
        .legend-box { background: #f8f9fa; border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; font-size: 0.75rem; border: 1px solid #eee; }
        .legend-box .legend-title { font-weight: bold; color: #495057; margin-bottom: 6px; }
        .legend-item { display: flex; align-items: center; gap: 6px; padding: 2px 0; }
        .legend-secret-btn {
            border: none;
            background: transparent;
            padding: 0;
            margin-left: 6px;
            width: 14px;
            height: 14px;
            opacity: 0;
            cursor: pointer;
        }
        .legend-secret-btn:focus {
            outline: none;
        }
        .popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .popup-content {
            background: #ffffff;
            border-radius: 10px;
            max-width: 480px;
            width: 90%;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            text-align: center;
        }
        .popup-content img {
            max-width: 100%;
            height: auto;
            border-radius: 6px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body data-current-tel="<?= htmlspecialchars($bulunan_tel ?? '', ENT_QUOTES, 'UTF-8') ?>">

<div id="admin-popup-overlay" class="popup-overlay">
    <div class="popup-content">
        <div id="admin-popup-image-wrapper" style="display:none;">
            <img id="admin-popup-image" src="" alt="Duyuru">
        </div>
        <p id="admin-popup-text" class="mb-3"></p>
        <button type="button" class="btn btn-primary btn-sm" onclick="kapatPopup()">Kapat</button>
    </div>
</div>

<div class="top-bar shadow-sm">
    <div class="fw-bold text-primary fs-5">📞 Bursluluk CRM</div>
    <div>
        <a href="index.php" class="btn btn-sm btn-outline-secondary me-2">← Panele Dön</a>
        <span class="me-3 text-secondary">👤 <strong><?= htmlspecialchars($aktif_personel) ?></strong></span>
        <a href="../sorgu.php" class="btn btn-sm btn-outline-primary me-1">📊 Sorgu / Raporlar</a>
        <a href="../logout.php" class="btn btn-sm btn-outline-danger">Çıkış</a>
    </div>
</div>

<div class="container-fluid px-3">
    <div class="row">
        
        <div class="col-md-3 mb-3" id="sidebar-randevular">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="text-uppercase text-muted small fw-bold mb-0">📅 <?= $gecmis_randevular ? 'Geçmiş' : 'Yaklaşan' ?> Randevular</h6>
                <?php
                $randevu_link_params = ['sinif' => $secilen_sinif];
                if (isset($_GET['getir_tel']) && $_GET['getir_tel'] !== '') $randevu_link_params['getir_tel'] = $_GET['getir_tel'];
                if ($randevu_sinif_filtre > 0) $randevu_link_params['r_sinif'] = $randevu_sinif_filtre;
                $randevu_link_gecmis = $randevu_link_params;
                $randevu_link_gecmis['gecmis_randevular'] = $gecmis_randevular ? '0' : '1';
                $randevu_switch_url = '?' . http_build_query($randevu_link_gecmis);
                ?>
                <a href="<?= htmlspecialchars($randevu_switch_url) ?>" class="btn btn-sm btn-outline-secondary py-0"><?= $gecmis_randevular ? 'Yaklaşan' : 'Geçmiş' ?></a>
            </div>
            <?php if ($res_randevu_siniflar && mysqli_num_rows($res_randevu_siniflar) > 0): ?>
                <form method="GET" class="mb-2">
                    <input type="hidden" name="sinif" value="<?= (int)$secilen_sinif ?>">
                    <?php if (isset($_GET['getir_tel']) && $_GET['getir_tel'] !== ''): ?>
                        <input type="hidden" name="getir_tel" value="<?= htmlspecialchars($_GET['getir_tel']) ?>">
                    <?php endif; ?>
                    <?php if ($gecmis_randevular): ?>
                        <input type="hidden" name="gecmis_randevular" value="1">
                    <?php endif; ?>
                    <select name="r_sinif" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">Tüm Sınıflar</option>
                        <?php mysqli_data_seek($res_randevu_siniflar, 0); while($rs = mysqli_fetch_assoc($res_randevu_siniflar)): ?>
                            <option value="<?= (int)$rs['sinif'] ?>" <?= ($randevu_sinif_filtre == $rs['sinif']) ? 'selected' : '' ?>>
                                <?= (int)$rs['sinif'] ?>. Sınıf
                            </option>
                        <?php endwhile; ?>
                    </select>
                </form>
            <?php endif; ?>
            <div class="sidebar-scroll" id="refresh-randevular">
                <?php if(mysqli_num_rows($res_randevular) > 0): ?>
                    <?php while($rand = mysqli_fetch_assoc($res_randevular)): ?>
                        <?php 
                            $is_rand_active = ($rand['tel_temiz'] == $bulunan_tel) ? 'randevu-active' : '';
                        ?>
                        <div class="randevu-item <?= $is_rand_active ?>" data-tel="<?= htmlspecialchars($rand['tel_temiz'], ENT_QUOTES, 'UTF-8') ?>" onclick="window.location.href='?sinif=<?= (int)$secilen_sinif ?>&getir_tel=<?= urlencode($rand['tel_temiz']) ?><?= $gecmis_randevular ? '&gecmis_randevular=1' : '' ?><?= $randevu_sinif_filtre > 0 ? '&r_sinif=' . (int)$randevu_sinif_filtre : '' ?>'">
                            <div style="flex: 1; min-width: 0;">
                                <div class="fw-bold text-truncate"><?= htmlspecialchars($rand['veli_ad'] . ' ' . $rand['veli_soyad']) ?></div>
                                <?php if (!empty($rand['ogrenciler'])): ?>
                                    <div class="small text-muted text-truncate" title="<?= htmlspecialchars($rand['ogrenciler']) ?>">
                                        🎓 <?= htmlspecialchars($rand['ogrenciler']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="text-danger small mt-1">🕒 <?= (!empty($rand['randevu_tarihi']) && strtotime($rand['randevu_tarihi'])) ? date("d.m H:i", strtotime($rand['randevu_tarihi'])) : '—' ?></div>
                                <?php if($rand['arama_durumu'] == 'Islemde'): ?>
                                    <div class="badge bg-primary mt-1" style="font-size: 0.6rem;">ŞU AN İŞLEMDE</div>
                                <?php endif; ?>
                                <?php
                                $rd_label = ['Bekleniyor' => '⏳ Bekleniyor', 'Geldi' => '✅ Geldi', 'Gelmedi' => '❌ Gelmedi'];
                                $rd_class = ['Bekleniyor' => 'bg-warning text-dark', 'Geldi' => 'bg-success', 'Gelmedi' => 'bg-danger'];
                                $rd = $rand['randevu_durumu'] ?? 'Bekleniyor';
                                ?>
                                <div class="badge <?= $rd_class[$rd] ?? 'bg-secondary' ?> mt-1" style="font-size: 0.65rem;"><?= $rd_label[$rd] ?? $rd ?></div>
                            </div>
                            <?php if (!empty($rand['personel'])): ?>
                                <div class="randevu-personel" title="Randevuyu alan">👤 <?= htmlspecialchars($rand['personel']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="text-muted small text-center mt-4">Randevu yok.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-md-6 mb-3">
            <?php
            // Admin ise, diğer kullanıcılara pop-up gönderme paneli
            $personel_liste = null;
            if ($aktif_personel === 'admin') {
                // Son 30 dakikada aktif olan kullanıcılar
                $personel_liste = mysqli_query(
                    $conn,
                    "SELECT kadi AS personel 
                     FROM aktif_kullanicilar 
                     WHERE last_activity >= (NOW() - INTERVAL 30 MINUTE)
                     ORDER BY kadi ASC"
                );
                // Hiç yoksa, tüm bilinen personel listesini kullan (geri dönüş)
                if (!$personel_liste || mysqli_num_rows($personel_liste) === 0) {
                    $personel_liste = mysqli_query(
                        $conn,
                        "SELECT DISTINCT personel 
                         AS personel
                         FROM cagri_listesi 
                         WHERE personel IS NOT NULL AND personel <> '' 
                         ORDER BY personel ASC"
                    );
                }
            }
            ?>
            <?php if ($aktif_personel === 'admin' && $personel_liste && mysqli_num_rows($personel_liste) > 0): ?>
            <div class="card shadow-sm mb-3 border-warning">
                <div class="card-header py-2 bg-warning-subtle">
                    <strong>🔔 Pop-up Gönder (Sadece Admin)</strong>
                </div>
                <div class="card-body py-2">
                    <form method="POST" class="row g-2 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label small mb-1">Hedef Kullanıcılar</label>
                            <select name="popup_hedefler[]" class="form-select form-select-sm" multiple size="4">
                                <?php while($p = mysqli_fetch_assoc($personel_liste)): ?>
                                    <option value="<?= htmlspecialchars($p['personel']) ?>"><?= htmlspecialchars($p['personel']) ?></option>
                                <?php endwhile; ?>
                            </select>
                            <small class="text-muted small">Ctrl ile çoklu seçim yapabilirsiniz.</small>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small mb-1">Mesaj (Yazı)</label>
                            <textarea name="popup_metin" class="form-control form-control-sm" rows="3" placeholder="Gösterilecek yazı"></textarea>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">Resim URL (Opsiyonel)</label>
                            <input type="text" name="popup_resim" class="form-control form-control-sm" placeholder="https://...">
                        </div>
                        <div class="col-12 col-md-1 d-grid">
                            <button type="submit" name="popup_olustur" class="btn btn-sm btn-warning">Gönder</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm mb-3">
                <div class="card-body py-2">
                    <form method="GET" class="row g-2 justify-content-center align-items-center" onsubmit="return false;">
                        <div class="col-auto">
                            <label class="form-label small mb-0 me-1">Sınıf:</label>
                            <select name="sinif" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">Sınıf Seç...</option>
                                <?php if ($result_siniflar) { mysqli_data_seek($result_siniflar, 0); while($s = mysqli_fetch_assoc($result_siniflar)): ?>
                                    <option value="<?= $s['sinif'] ?>" <?= ($secilen_sinif == $s['sinif']) ? 'selected' : '' ?>><?= $s['sinif'] ?>. Sınıf</option>
                                <?php endwhile; } ?>
                            </select>
                        </div>
                        <div class="col-auto flex-grow-1" style="min-width:220px;">
                            <label class="form-label small mb-0">Genel Arama (Veli / Öğrenci)</label>
                            <div class="input-group input-group-sm">
                                <input type="text" id="genelArama" class="form-control" placeholder="Ad, soyad veya ad soyad" oninput="aramaYapSmart(this.value)">
                                <button type="button" class="btn btn-outline-primary" onclick="aramaYapSmart(document.getElementById('genelArama').value)">Ara</button>
                            </div>
                            <div id="arama-sonuclari" class="mt-1 border rounded bg-white" style="max-height:220px; overflow-y:auto;"></div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($mesaj): ?>
                <div class="alert alert-<?= htmlspecialchars($mesajTur) ?> py-2 text-center small"><?= htmlspecialchars($mesaj) ?></div>
            <?php endif; ?>

            <?php if (!empty($aile_verisi)): ?>
                <?php 
                    $v = $aile_verisi[0];
                    $eski_durum = $v['arama_durumu'];
                    $kayit_bitmis = in_array($eski_durum, $bitmis_durumlar);
                    
                    // Veritabanındaki tarih varsa HTML datetime-local formatına çevir
                    $eski_tarih = "";
                    if (!empty($v['randevu_tarihi'])) {
                        $eski_tarih = date('Y-m-d\TH:i', strtotime($v['randevu_tarihi']));
                        $eski_durum = 'Randevu Alindi';
                    }
                ?>
                <?php if ($kayit_bitmis): ?>
                <div class="alert alert-secondary py-2 small mb-2">
                    🔒 Bu kayıt daha önce işlenmiş; veriler veritabanındaki son haliyle gösteriliyor. Değişiklik yaparsanız Kaydet ile güncelleyebilirsiniz.
                    <?php if (!empty($v['personel']) || !empty($v['islem_tarihi'])): ?>
                    <div class="mt-2 pt-2 border-top border-secondary border-opacity-25">
                        <strong>Son işlem:</strong>
                        <?php if (!empty($v['islem_tarihi']) && strtotime($v['islem_tarihi'])): ?>
                            <?= date('d.m.Y H:i', strtotime($v['islem_tarihi'])) ?>
                        <?php else: ?>—<?php endif; ?>
                        <?php if (!empty($v['personel'])): ?>
                            — <strong><?= htmlspecialchars($v['personel']) ?></strong>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="veli-box py-3">
                    <div class="row align-items-center">
                            <div class="col-7">
                            <small class="text-muted">Veli Adı Soyadı</small>
                            <h4 class="mb-0 text-truncate"><?= htmlspecialchars($v['veli_ad'] . ' ' . $v['veli_soyad']) ?></h4>
                        </div>
                        <div class="col-5 text-end">
                            <a href="tel:<?= htmlspecialchars($v['tel_temiz']) ?>" class="tel-link fs-4">📞 <?= htmlspecialchars($v['tel_orijinal'] ?? $v['tel_temiz']) ?></a>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <h6 class="text-muted small ms-1">Öğrenci Bilgileri:</h6>
                    <?php foreach($aile_verisi as $ogrenci): ?>
                    <div class="card mb-1 shadow-sm border-0">
                        <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                            <div class="fw-bold">🎓 <?= htmlspecialchars($ogrenci['ogrenci_ad'] . ' ' . $ogrenci['ogrenci_soyad']) ?></div>
                            <div>
                                <span class="badge bg-secondary text-white me-1"><?= htmlspecialchars($ogrenci['arama_durumu']) ?></span>
                                <span class="badge bg-warning text-dark"><?= $ogrenci['sinif'] ?>. Sınıf</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="card shadow border-primary">
                    <div class="card-header bg-primary text-white py-2 fw-bold small">📝 Görüşme Sonucu</div>
                    <div class="card-body p-3">
                        <form method="POST" autocomplete="off" id="form-gorusme-kaydet">
                            <input type="hidden" name="islem_tel" value="<?= $v['tel_temiz'] ?>">
                            <input type="hidden" name="sinif_id" value="<?= $secilen_sinif ?>">

                            <div class="mb-2">
                                <label class="form-label small fw-bold mb-0">Durum:</label>
                                <select name="durum" id="durumSelect" class="form-select" required onchange="toggleDateInput()">
                                    <option value="">Seçiniz...</option>
                                    <option value="Randevu Alindi" <?= ($eski_durum=='Randevu Alindi')?'selected':'' ?>>✅ Randevu Alındı</option>
                                    <option value="Ulasilamadi" <?= ($eski_durum=='Ulasilamadi')?'selected':'' ?>>❌ Ulaşılamadı</option>
                                    <option value="Katilmak İstemiyor" <?= ($eski_durum=='Katilmak İstemiyor')?'selected':'' ?>>⛔ Katılmak İstemiyor</option>
                                    <option value="Tekrar ara" <?= ($eski_durum=='Tekrar ara')?'selected':'' ?>>📞 Tekrar Ara</option>
                                    <option value="Ertelendi" <?= ($eski_durum=='Ertelendi')?'selected':'' ?>>⏳ Ertelendi</option>
                                </select>
                            </div>

                            <div class="mb-2" id="dateDiv" style="display:none;">
                                <label class="form-label small fw-bold text-danger mb-0">Randevu Tarihi:</label>
                                <input type="datetime-local" name="randevu_tarihi" id="randevuInput" class="form-control" value="<?= $eski_tarih ?>" <?= empty($eski_tarih) ? 'min="' . date('Y-m-d\TH:i') . '"' : '' ?> autocomplete="off">
                            </div>
                            <div class="mb-2" id="randevuDurumDiv" style="display:none;">
                                <label class="form-label small fw-bold mb-0">Randevu durumu:</label>
                                <select name="randevu_durumu" id="randevuDurumSelect" class="form-select">
                                    <?php $rd = $v['randevu_durumu'] ?? 'Bekleniyor'; ?>
                                    <option value="Bekleniyor" <?= ($rd === 'Bekleniyor') ? 'selected' : '' ?>>⏳ Bekleniyor</option>
                                    <option value="Geldi" <?= ($rd === 'Geldi') ? 'selected' : '' ?>>✅ Geldi</option>
                                    <option value="Gelmedi" <?= ($rd === 'Gelmedi') ? 'selected' : '' ?>>❌ Gelmedi</option>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-bold mb-0">Notlar:</label>
                                <textarea name="notlar" class="form-control" rows="3"><?= htmlspecialchars($v['arama_notu'] ?? '') ?></textarea>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" name="btn_kaydet" class="btn btn-success">Kaydet</button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif($secilen_sinif > 0): ?>
                <div class="alert alert-info text-center mt-5"><h6>Sağ listeden bir isim seçiniz.</h6></div>
            <?php else: ?>
                <div class="alert alert-secondary text-center mt-5">
                    <h6 class="d-inline-block mb-0">Sınıf seçiniz.</h6>
                    <!-- Gizli pop-up panelini açan buton (sadece sınıf seçilmemişken ulaşılabilir) -->
                    <button type="button" id="ogrenci-popup-secret-btn" class="legend-secret-btn" aria-label=" " onclick="toggleOgrenciPopupPanel()"></button>
                </div>
                <?php
                
                $personel_liste_ogrenci = mysqli_query(
                    $conn,
                    "SELECT kadi AS personel 
                     FROM aktif_kullanicilar 
                     WHERE last_activity >= (NOW() - INTERVAL 30 MINUTE)
                     ORDER BY kadi ASC"
                );
                if (!$personel_liste_ogrenci || mysqli_num_rows($personel_liste_ogrenci) === 0) {
                    $personel_liste_ogrenci = mysqli_query(
                        $conn,
                        "SELECT DISTINCT personel AS personel
                         FROM cagri_listesi 
                         WHERE personel IS NOT NULL AND personel <> '' 
                         ORDER BY personel ASC"
                    );
                }
                ?>
                <div id="ogrenci-popup-panel" class="card shadow-sm mb-2 mx-auto" style="display:none; max-width:480px;">
                    <div class="card-header py-1 d-flex justify-content-between align-items-center">
                        <span class="small fw-bold">🔔 Pop-up Gönder</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleOgrenciPopupPanel()">Kapat</button>
                    </div>
                    <div class="card-body py-2">
                        <form method="POST" class="row g-2 align-items-end">
                            <input type="hidden" name="popup_secret" value="1">
                            <div class="col-12 col-md-4">
                                <label class="form-label small mb-1">Hedef Kullanıcılar</label>
                                <select name="popup_hedefler[]" class="form-select form-select-sm" multiple size="3">
                                    <?php if ($personel_liste_ogrenci && mysqli_num_rows($personel_liste_ogrenci) > 0): ?>
                                        <?php while($p2 = mysqli_fetch_assoc($personel_liste_ogrenci)): ?>
                                            <option value="<?= htmlspecialchars($p2['personel']) ?>"><?= htmlspecialchars($p2['personel']) ?></option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small mb-1">Mesaj</label>
                                <textarea name="popup_metin" class="form-control form-control-sm" rows="2" placeholder="Gösterilecek yazı"></textarea>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small mb-1">Resim URL</label>
                                <input type="text" name="popup_resim" class="form-control form-control-sm" placeholder="https://...">
                            </div>
                            <div class="col-12 col-md-1 d-grid">
                                <button type="submit" name="popup_olustur" class="btn btn-sm btn-primary">Gönder</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-md-3" id="sidebar-sinif">
            <h6 class="text-uppercase text-muted small fw-bold mb-2">
                👥 Sınıf Listesi
                <button type="button" id="legend-secret-btn" class="legend-secret-btn" aria-label=" "></button>
            </h6>
            <div class="legend-box" id="legend-box">
                <div class="legend-title">
                    Renklerin anlamı
                </div>
                <?php
                $legend_items = [
                    'Bekliyor'           => ['class' => 'status-bekliyor',   'label' => 'Bekliyor'],
                    'Tekrar ara'         => ['class' => 'status-tekrar-ara', 'label' => 'Tekrar ara'],
                    'Ertelendi'          => ['class' => 'status-ertelendi',  'label' => 'Ertelendi'],
                    'Randevu Alindi'     => ['class' => 'status-randevu',    'label' => 'Randevu Alındı'],
                    'Islemde'            => ['class' => 'status-islemde',    'label' => 'İşlemde'],
                    'Ulasilamadi'        => ['class' => 'status-ulasilamadi','label' => 'Ulaşılamadı'],
                    'Katilmak İstemiyor' => ['class' => 'status-katilmadi',  'label' => 'Katılmak İstemiyor'],
                ];
                foreach ($legend_items as $durum_key => $info):
                    $adet = isset($sinif_durum_adet[$durum_key]) ? $sinif_durum_adet[$durum_key] : null;
                    $suffix = ($secilen_sinif > 0 && $adet !== null) ? ' (' . $adet . ')' : '';
                ?>
                <div class="legend-item"><span class="status-dot <?= $info['class'] ?>"></span> <?= htmlspecialchars($info['label']) ?><?= $suffix ?></div>
                <?php endforeach; ?>
            </div>
            <div class="sidebar-scroll" id="refresh-sinif-listesi">
                <?php if($secilen_sinif > 0 && $res_sinif_list): ?>
                    <?php while($row = mysqli_fetch_assoc($res_sinif_list)): ?>
                        <?php 
                            $d = $row['arama_durumu'];
                            if ($d == 'Randevu Alindi') {
                                $cls = 'status-randevu';
                            } elseif ($d == 'Ertelendi') {
                                $cls = 'status-ertelendi';
                            } elseif ($d == 'Tekrar ara') {
                                $cls = 'status-tekrar-ara';
                            } elseif ($d == 'Ulasilamadi') {
                                $cls = 'status-ulasilamadi';
                            } elseif ($d == 'Islemde') {
                                $cls = 'status-islemde';
                            } elseif ($d == 'Katilmak İstemiyor') {
                                $cls = 'status-katilmadi';
                            } else {
                                $cls = 'status-bekliyor';
                            }
                            $act = (!empty($bulunan_tel) && $bulunan_tel === $row['tel_temiz']) ? 'active-row' : '';
                        ?>
                        <div class="class-list-item <?= $act ?>" data-tel="<?= htmlspecialchars($row['tel_temiz'], ENT_QUOTES, 'UTF-8') ?>" onclick="selectSinifListItem(<?= (int)$secilen_sinif ?>, this.getAttribute('data-tel'))">
                            <div class="text-truncate" style="max-width: 85%;">
                                <span class="status-dot <?= $cls ?>"></span>
                                <?= htmlspecialchars($row['veli_ad'] . ' ' . $row['veli_soyad']) ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="text-muted small text-center mt-4">Sınıf seçin.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
var legendOriginalHtml = null;
var legendGameActive = false;
var dinoKeyBound = false;
var currentDinoJumpFn = null;

function toggleDateInput() {
    var d = document.getElementById("durumSelect");
    if (!d) return;
    d = d.value;
    var div = document.getElementById("dateDiv");
    var inp = document.getElementById("randevuInput");
    var durumDiv = document.getElementById("randevuDurumDiv");
    
    if (d === "Randevu Alindi") { 
        if (div) div.style.display = "block"; 
        if (inp) inp.required = true; 
        if (durumDiv) durumDiv.style.display = "block";
    } 
    else { 
        if (div) div.style.display = "none"; 
        if (inp) { inp.required = false; inp.value = ""; }
        if (durumDiv) durumDiv.style.display = "none";
    }
}
var ARAMALAR_STATE_KEY = 'aramalar_state';
var ARAMALAR_SCROLL_KEY = 'aramalar_scroll';

function saveAramalarState() {
    try {
        var params = new URLSearchParams(window.location.search);
        var sinif = params.get('sinif') || '';
        var gecmis = params.get('gecmis_randevular') || '0';
        var rSinif = params.get('r_sinif') || '0';
        var getirTel = params.get('getir_tel') || '';
        sessionStorage.setItem(ARAMALAR_STATE_KEY, JSON.stringify({ sinif: sinif, gecmis_randevular: gecmis, r_sinif: rSinif, getir_tel: getirTel }));
    } catch (e) {}
}
function saveAramalarScroll() {
    try {
        var r = document.getElementById('refresh-randevular');
        var s = document.getElementById('refresh-sinif-listesi');
        var main = window.scrollY || document.documentElement.scrollTop || 0;
        sessionStorage.setItem(ARAMALAR_SCROLL_KEY, JSON.stringify({
            main: main,
            randevu: (r && r.scrollTop) ? r.scrollTop : 0,
            sinif: (s && s.scrollTop) ? s.scrollTop : 0
        }));
    } catch (e) {}
}
function restoreAramalarScroll() {
    try {
        var raw = sessionStorage.getItem(ARAMALAR_SCROLL_KEY);
        if (!raw) return;
        var p = JSON.parse(raw);
        if (typeof p.main === 'number' && p.main > 0) window.scrollTo(0, p.main);
        var r = document.getElementById('refresh-randevular');
        var s = document.getElementById('refresh-sinif-listesi');
        if (r && p.randevu > 0) r.scrollTop = p.randevu;
        if (s && p.sinif > 0) s.scrollTop = p.sinif;
    } catch (e) {}
}
var aramalarScrollTimer;
function onAramalarScroll() {
    clearTimeout(aramalarScrollTimer);
    aramalarScrollTimer = setTimeout(saveAramalarScroll, 150);
}

function selectSinifListItem(sinif, tel) {
    saveAramalarScroll();
    saveAramalarState();
    window.location.href = '?sinif=' + sinif + '&getir_tel=' + encodeURIComponent(tel);
}

// Genel arama (veli / öğrenci) - akıllı arama
var aramaTimer = null;
function aramaYapSmart(val) {
    if (aramaTimer) clearTimeout(aramaTimer);
    var term = (val || '').trim();
    var cont = document.getElementById('arama-sonuclari');
    if (!cont) return;
    if (term.length < 2) {
        cont.innerHTML = '';
        return;
    }
    aramaTimer = setTimeout(function() {
        aramaYap(term);
    }, 300);
}

function aramaYap(term) {
    var cont = document.getElementById('arama-sonuclari');
    if (!cont) return;
    var params = new URLSearchParams();
    params.append('arama_q', term);
    fetch(window.location.pathname + '?' + params.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            cont.innerHTML = html;
        })
        .catch(function() {});
}

function aramaKaydaGit(tel) {
    if (!tel) return;
    saveAramalarScroll();
    saveAramalarState();
    var sinifSelect = document.querySelector('select[name="sinif"]');
    var secilenSinif = sinifSelect ? sinifSelect.value : '';
    window.location.href = '?sinif=' + encodeURIComponent(secilenSinif) + '&getir_tel=' + encodeURIComponent(tel);
}

window.onload = function() {
    toggleDateInput();
    saveAramalarState();
    var params = new URLSearchParams(window.location.search);
    var sinif = params.get('sinif');
    if ((sinif === null || sinif === '' || sinif === '0') && window.location.search.indexOf('sinif=') === -1) {
        try {
            var saved = sessionStorage.getItem(ARAMALAR_STATE_KEY);
            if (saved) {
                var st = JSON.parse(saved);
                if (st.sinif && st.sinif !== '0') {
                    var q = '?sinif=' + encodeURIComponent(st.sinif);
                    if (st.gecmis_randevular === '1') q += '&gecmis_randevular=1';
                    if (st.r_sinif && st.r_sinif !== '0') q += '&r_sinif=' + encodeURIComponent(st.r_sinif);
                    if (st.getir_tel) q += '&getir_tel=' + encodeURIComponent(st.getir_tel);
                    window.location.replace(q);
                    return;
                }
            }
        } catch (e) {}
    }
    setTimeout(restoreAramalarScroll, 50);
    var elR = document.getElementById('refresh-randevular');
    var elS = document.getElementById('refresh-sinif-listesi');
    if (elR) { elR.addEventListener('scroll', onAramalarScroll); elR.addEventListener('click', function() { saveAramalarScroll(); saveAramalarState(); }, true); }
    if (elS) elS.addEventListener('scroll', onAramalarScroll);
    window.addEventListener('scroll', onAramalarScroll, true);
    var formKaydet = document.getElementById('form-gorusme-kaydet');
    if (formKaydet) formKaydet.addEventListener('submit', function() { saveAramalarScroll(); saveAramalarState(); });
};

// Sol (randevular) ve sağ (sınıf listesi) sütunları periyodik yenile — ortadaki form korunur
var refreshIntervalMs = 15000; // 15 saniye (trafik azaltmak için)
var refreshTimer = null;

// Öğrenci bilgileri yanındaki gizli pop-up paneli (artık sınıf seçiniz ekranında)
function toggleOgrenciPopupPanel() {
    var panel = document.getElementById('ogrenci-popup-panel');
    if (!panel) return;
    if (panel.style.display === 'none' || panel.style.display === '') {
        panel.style.display = 'block';
    } else {
        panel.style.display = 'none';
    }
}

function getCurrentTel() {
    var body = document.body;
    return body && body.getAttribute ? (body.getAttribute('data-current-tel') || '') : '';
}

function applyActiveRandevu() {
    var current = getCurrentTel();
    if (!current) return;
    var container = document.getElementById('refresh-randevular');
    if (!container) return;
    var items = container.querySelectorAll('.randevu-item[data-tel]');
    items.forEach(function(el) {
        if (el.getAttribute('data-tel') === current) el.classList.add('randevu-active');
        else el.classList.remove('randevu-active');
    });
}

function applyActiveSinif() {
    var current = getCurrentTel();
    var container = document.getElementById('refresh-sinif-listesi');
    if (!container) return;
    var items = container.querySelectorAll('.class-list-item[data-tel]');
    items.forEach(function(el) {
        if (current && el.getAttribute('data-tel') === current) el.classList.add('active-row');
        else el.classList.remove('active-row');
    });
}

function refreshSidebars() {
    if (document.hidden) return; 
    var url = window.location.pathname + (window.location.search || '');
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var elRand = document.getElementById('refresh-randevular');
            var elSinif = document.getElementById('refresh-sinif-listesi');
            var newRand = doc.getElementById('refresh-randevular');
            var newSinif = doc.getElementById('refresh-sinif-listesi');
            if (elRand && newRand) {
                elRand.innerHTML = newRand.innerHTML;
                applyActiveRandevu();
            }
            if (elSinif && newSinif) {
                elSinif.innerHTML = newSinif.innerHTML;
                applyActiveSinif();
            }
        })
        .catch(function() {});
}

function startSidebarRefresh() {
    if (refreshTimer) clearInterval(refreshTimer);
    refreshTimer = setInterval(refreshSidebars, refreshIntervalMs);
}

// Öğrenci bilgileri yanındaki gizli pop-up paneli
function toggleOgrenciPopupPanel() {
    var panel = document.getElementById('ogrenci-popup-panel');
    if (!panel) return;
    if (panel.style.display === 'none' || panel.style.display === '') {
        panel.style.display = 'block';
    } else {
        panel.style.display = 'none';
    }
}

function initDinoGame() {
    var box = document.getElementById('legend-box');
    if (!box) return;

    // Menü veya oyun açıksa eski haline döndür
    if (legendGameActive) {
        if (legendOriginalHtml !== null) {
            box.innerHTML = legendOriginalHtml;
            legendGameActive = false;
            var btn = document.getElementById('legend-secret-btn');
            if (btn) {
                btn.addEventListener('click', initDinoGame);
            }
        }
        return;
    }

    // İlk kez açılıyorsa kutunun orijinal içeriğini sakla
    if (legendOriginalHtml === null) {
        legendOriginalHtml = box.innerHTML;
    }

    // Oyun seçim menüsü
    box.innerHTML =
        '<div class="small mb-1">Oyun seçin:</div>' +
        '<div class="d-grid gap-1">' +
        '  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="startDinoGame()">Dino Koşu</button>' +
        '  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="open2048Game()">2048</button>' +
        '  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openSnakeGame()">Yılan Oyunu</button>' +
        '</div>' +
        '<div class="text-muted small mt-1">Gizli butona tekrar tıklarsanız eski görünüme dönersiniz.</div>';

    legendGameActive = true;
}

// Dino oyunu
function startDinoGame() {
    var box = document.getElementById('legend-box');
    if (!box) return;

    box.innerHTML = '<canvas id="dinoCanvas" width="260" height="80" style="width:100%;height:auto;background:#f8f9fa;border-radius:4px;"></canvas>' +
        '<div class="text-center mt-1 small text-muted">Boşluk veya Yukarı ok ile zıpla</div>';

    var canvas = document.getElementById('dinoCanvas');
    if (!canvas || !canvas.getContext) return;
    var ctx = canvas.getContext('2d');

    var groundY = 60;
    var dino = { x: 20, y: groundY - 20, w: 18, h: 20, vy: 0, jumping: false };
    var gravity = 0.8;
    var jumpPower = -10;
    var obstacle = { x: canvas.width, y: groundY - 16, w: 10, h: 16, speed: 4 };
    var gameOver = false;
    var score = 0;

    function resetGame() {
        dino.y = groundY - dino.h;
        dino.vy = 0;
        dino.jumping = false;
        obstacle.x = canvas.width;
        gameOver = false;
        score = 0;
    }

    function drawDino() {
        ctx.fillStyle = '#555';
        ctx.fillRect(dino.x, dino.y, dino.w, dino.h);
        ctx.fillStyle = '#222';
        ctx.fillRect(dino.x + dino.w - 6, dino.y + 4, 3, 3); // göz
    }

    function drawObstacle() {
        ctx.fillStyle = '#888';
        ctx.fillRect(obstacle.x, obstacle.y, obstacle.w, obstacle.h);
    }

    function drawGround() {
        ctx.strokeStyle = '#ccc';
        ctx.beginPath();
        ctx.moveTo(0, groundY);
        ctx.lineTo(canvas.width, groundY);
        ctx.stroke();
    }

    function drawScore() {
        ctx.fillStyle = '#666';
        ctx.font = '10px Arial';
        ctx.fillText('Skor: ' + score, canvas.width - 60, 12);
    }

    function checkCollision() {
        return (
            dino.x < obstacle.x + obstacle.w &&
            dino.x + dino.w > obstacle.x &&
            dino.y < obstacle.y + obstacle.h &&
            dino.y + dino.h > obstacle.y
        );
    }

    function update() {
        if (gameOver) return;

        dino.vy += gravity;
        dino.y += dino.vy;
        if (dino.y >= groundY - dino.h) {
            dino.y = groundY - dino.h;
            dino.vy = 0;
            dino.jumping = false;
        }

        obstacle.x -= obstacle.speed;
        if (obstacle.x + obstacle.w < 0) {
            obstacle.x = canvas.width + Math.random() * 40;
            obstacle.speed = 3.5 + Math.random() * 2;
            score++;
        }

        if (checkCollision()) {
            gameOver = true;
        }
    }

    function render() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        drawGround();
        drawDino();
        drawObstacle();
        drawScore();
        if (gameOver) {
            ctx.fillStyle = '#c00';
            ctx.font = '12px Arial';
            ctx.fillText('Oyun Bitti - boşluk ile tekrar', 40, 40);
        }
    }

    function loop() {
        update();
        render();
        requestAnimationFrame(loop);
    }

    function jump() {
        if (!gameOver && !dino.jumping) {
            dino.vy = jumpPower;
            dino.jumping = true;
        } else if (gameOver) {
            resetGame();
        }
    }

    ensureGameKeyListener(jump);

    resetGame();
    legendGameActive = true;
    loop();
}

// Ortak klavye kontrolleri (Dino + 2048 + Yılan)
function ensureGameKeyListener(dinoJumpFn) {
    // Dino fonksiyonunu güncelle (oyun yeniden başladığında)
    if (typeof dinoJumpFn === 'function') {
        currentDinoJumpFn = dinoJumpFn;
    }
    if (dinoKeyBound) return;
    dinoKeyBound = true;

    document.addEventListener('keydown', function(e) {
        var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea') {
            return;
        }

        var code = e.code || '';
        var key = e.key || '';
        var kc  = typeof e.keyCode === 'number' ? e.keyCode : e.which;

        // 2048 aktifse yön tuşları ile oynat
        if (document.getElementById('g2048-grid')) {
            if (code === 'ArrowUp'    || kc === 38) { e.preventDefault(); g2048Move('up'); }
            else if (code === 'ArrowDown'  || kc === 40) { e.preventDefault(); g2048Move('down'); }
            else if (code === 'ArrowLeft'  || kc === 37) { e.preventDefault(); g2048Move('left'); }
            else if (code === 'ArrowRight' || kc === 39) { e.preventDefault(); g2048Move('right'); }
            return;
        }

        // Yılan oyunu aktifse yön tuşları ile yön değiştir
        if (document.getElementById('snakeCanvas')) {
            if (code === 'ArrowUp'    || kc === 38) { e.preventDefault(); snakeChangeDir('up'); }
            else if (code === 'ArrowDown'  || kc === 40) { e.preventDefault(); snakeChangeDir('down'); }
            else if (code === 'ArrowLeft'  || kc === 37) { e.preventDefault(); snakeChangeDir('left'); }
            else if (code === 'ArrowRight' || kc === 39) { e.preventDefault(); snakeChangeDir('right'); }
            return;
        }

        // Dino oyunu için boşluk / yukarı ok
        if (currentDinoJumpFn && (code === 'Space' || code === 'ArrowUp' || kc === 32 || kc === 38)) {
            e.preventDefault();
            currentDinoJumpFn();
        }
    });
}

var legendSecretBtn = document.getElementById('legend-secret-btn');
if (legendSecretBtn) {
    legendSecretBtn.addEventListener('click', initDinoGame);
}

// 2048 oyunu (kutunun içinde)
function open2048Game() {
    var box = document.getElementById('legend-box');
    if (!box) return;

    box.innerHTML =
        '<div class="small mb-1 d-flex justify-content-between align-items-center">' +
        '  <span>2048</span>' +
        '  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="initDinoGame()">Geri</button>' +
        '</div>' +
        '<div id="g2048-grid" class="mb-1" style="display:grid;grid-template-columns:repeat(4,1fr);gap:4px;"></div>' +
        '<div class="text-muted small mt-1">Klavye yön tuşları ile oynayın. Gizli buton ile çıkabilirsiniz.</div>';

    window.g2048Board = [];
    for (var i = 0; i < 4; i++) {
        window.g2048Board[i] = [0, 0, 0, 0];
    }
    g2048AddTile();
    g2048AddTile();
    g2048Render();

    // Klavye kontrollerini aktif et (ilk kez açılıyorsa)
    ensureGameKeyListener(null);
}

function g2048AddTile() {
    var empty = [];
    for (var r = 0; r < 4; r++) {
        for (var c = 0; c < 4; c++) {
            if (!window.g2048Board[r][c]) empty.push({ r: r, c: c });
        }
    }
    if (empty.length === 0) return;
    var idx = Math.floor(Math.random() * empty.length);
    var spot = empty[idx];
    window.g2048Board[spot.r][spot.c] = Math.random() < 0.9 ? 2 : 4;
}

function g2048Render() {
    var grid = document.getElementById('g2048-grid');
    if (!grid) return;
    grid.innerHTML = '';
    for (var r = 0; r < 4; r++) {
        for (var c = 0; c < 4; c++) {
            var val = window.g2048Board[r][c];
            var div = document.createElement('div');
            div.style.height = '36px';
            div.style.borderRadius = '4px';
            div.style.display = 'flex';
            div.style.alignItems = 'center';
            div.style.justifyContent = 'center';
            div.style.fontWeight = 'bold';
            div.style.fontSize = '14px';
            if (val) {
                div.textContent = val;
                div.style.background = '#f0e5d8';
            } else {
                div.style.background = '#e9ecef';
            }
            grid.appendChild(div);
        }
    }
}

function g2048Compress(line) {
    var arr = line.filter(function (v) { return v !== 0; });
    for (var i = 0; i < arr.length - 1; i++) {
        if (arr[i] === arr[i + 1]) {
            arr[i] *= 2;
            arr[i + 1] = 0;
        }
    }
    arr = arr.filter(function (v) { return v !== 0; });
    while (arr.length < 4) arr.push(0);
    return arr;
}

function g2048Move(dir) {
    var moved = false;
    var r, c;
    if (dir === 'left' || dir === 'right') {
        for (r = 0; r < 4; r++) {
            var row = window.g2048Board[r].slice();
            if (dir === 'right') row.reverse();
            var comp = g2048Compress(row);
            if (dir === 'right') comp.reverse();
            for (c = 0; c < 4; c++) {
                if (window.g2048Board[r][c] !== comp[c]) moved = true;
                window.g2048Board[r][c] = comp[c];
            }
        }
    } else {
        for (c = 0; c < 4; c++) {
            var col = [];
            for (r = 0; r < 4; r++) col.push(window.g2048Board[r][c]);
            if (dir === 'down') col.reverse();
            var compCol = g2048Compress(col);
            if (dir === 'down') compCol.reverse();
            for (r = 0; r < 4; r++) {
                if (window.g2048Board[r][c] !== compCol[r]) moved = true;
                window.g2048Board[r][c] = compCol[r];
            }
        }
    }
    if (moved) {
        g2048AddTile();
        g2048Render();
    }
}

// Yılan oyunu (kutunun içinde)
function openSnakeGame() {
    var box = document.getElementById('legend-box');
    if (!box) return;

    box.innerHTML =
        '<div class="small mb-1 d-flex justify-content-between align-items-center">' +
        '  <span>Yılan Oyunu</span>' +
        '  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="initDinoGame()">Geri</button>' +
        '</div>' +
        '<canvas id="snakeCanvas" width="200" height="100" style="width:100%;height:auto;background:#f8f9fa;border-radius:4px;border:1px solid #dee2e6;"></canvas>' +
        '<div class="d-flex justify-content-center gap-1 mt-2">' +
        '  <button type="button" id="snakeRestartBtn" class="btn btn-sm btn-outline-secondary" onclick="snakeRestart()">Yeniden Başlat</button>' +
        '</div>' +
        '<div class="text-muted small mt-1">Klavye yön tuşları ile oynayın. Kaybedince yukarıdaki butonla yeniden başlatabilirsiniz.</div>';

    var canvas = document.getElementById('snakeCanvas');
    if (!canvas || !canvas.getContext) return;
    var ctx = canvas.getContext('2d');

    var cols = 20, rows = 10, size = 10;
    window.snakeDir = 'right';
    window.snakeBody = [{ x: 3, y: 5 }, { x: 2, y: 5 }, { x: 1, y: 5 }];
    window.snakeFood = spawnFood();
    window.snakeTimer && clearInterval(window.snakeTimer);

    function spawnFood() {
        while (true) {
            var fx = Math.floor(Math.random() * cols);
            var fy = Math.floor(Math.random() * rows);
            var conflict = window.snakeBody.some(function (p) { return p.x === fx && p.y === fy; });
            if (!conflict) return { x: fx, y: fy };
        }
    }

    function step() {
        var head = window.snakeBody[0];
        var nx = head.x;
        var ny = head.y;
        if (window.snakeDir === 'right') nx++;
        if (window.snakeDir === 'left') nx--;
        if (window.snakeDir === 'up') ny--;
        if (window.snakeDir === 'down') ny++;

        if (nx < 0 || nx >= cols || ny < 0 || ny >= rows ||
            window.snakeBody.some(function (p) { return p.x === nx && p.y === ny; })) {
            draw(true);
            clearInterval(window.snakeTimer);
            window.snakeGameOver = true;
            return;
        }

        window.snakeBody.unshift({ x: nx, y: ny });
        if (nx === window.snakeFood.x && ny === window.snakeFood.y) {
            window.snakeFood = spawnFood();
        } else {
            window.snakeBody.pop();
        }
        draw(false);
    }

    function draw(isGameOver) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#e9ecef';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        ctx.fillStyle = '#28a745';
        window.snakeBody.forEach(function (p, idx) {
            ctx.fillRect(p.x * size, p.y * size, size - 1, size - 1);
        });

        ctx.fillStyle = '#dc3545';
        ctx.fillRect(window.snakeFood.x * size, window.snakeFood.y * size, size - 1, size - 1);

        if (isGameOver) {
            ctx.fillStyle = '#c00';
            ctx.font = '10px Arial';
            ctx.fillText('Oyun Bitti', 70, 50);
        }
    }

    window.snakeGameOver = false;
    window.snakeTimer = setInterval(step, 200);
    draw(false);

    // Klavye kontrollerini aktif et (ilk kez açılıyorsa)
    ensureGameKeyListener(null);
}

function snakeChangeDir(dir) {
    if (!window.snakeBody || window.snakeBody.length === 0) return;
    // Yılanın tam ters yöne bir anda dönmesini engelle
    if (dir === 'left' && window.snakeDir === 'right') return;
    if (dir === 'right' && window.snakeDir === 'left') return;
    if (dir === 'up' && window.snakeDir === 'down') return;
    if (dir === 'down' && window.snakeDir === 'up') return;
    window.snakeDir = dir;
}

function snakeRestart() {
    if (window.snakeTimer) {
        clearInterval(window.snakeTimer);
        window.snakeTimer = null;
    }
    // Aynı ayarlarla oyunu baştan kur
    var box = document.getElementById('legend-box');
    if (!box) return;
    openSnakeGame();
}

var popupAktifId = null;

function gosterPopup(id, text, imageUrl) {
    popupAktifId = id;
    var overlay = document.getElementById('admin-popup-overlay');
    var textEl = document.getElementById('admin-popup-text');
    var imgWrap = document.getElementById('admin-popup-image-wrapper');
    var imgEl = document.getElementById('admin-popup-image');
    if (!overlay || !textEl || !imgWrap || !imgEl) return;

    textEl.textContent = text || '';

    if (imageUrl) {
        imgEl.src = imageUrl;
        imgWrap.style.display = 'block';
    } else {
        imgEl.src = '';
        imgWrap.style.display = 'none';
    }

    overlay.style.display = 'flex';
}

function kapatPopup() {
    var overlay = document.getElementById('admin-popup-overlay');
    if (overlay) overlay.style.display = 'none';

    if (!popupAktifId) return;
    var fd = new FormData();
    fd.append('popup_kapat', '1');
    fd.append('popup_id', popupAktifId);
    fetch(window.location.pathname, {
        method: 'POST',
        body: fd
    }).catch(function() {});
    popupAktifId = null;
}

function kontrolEtPopup() {
    fetch(window.location.pathname + '?popup_check=1', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.hasPopup) return;
            if (popupAktifId && popupAktifId === data.id) return;
            gosterPopup(data.id, data.text || '', data.image || '');
        })
        .catch(function() {});
}

setInterval(kontrolEtPopup, 5000); // 5 saniyede bir kontrol et

document.addEventListener('visibilitychange', function() {
    if (document.hidden) { if (refreshTimer) clearInterval(refreshTimer); refreshTimer = null; }
    else startSidebarRefresh();
});
startSidebarRefresh();
</script>
</body>
</html>