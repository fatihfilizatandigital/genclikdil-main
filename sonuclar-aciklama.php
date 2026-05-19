<?php
/**
 * Bilgilendirme sayfası (veli). Aynı token ile açılır: sonuclar-aciklama.php?t=TOKEN
 * Ferman metnini sayfa header/footer ve fontları ile sunar; "tıklayınız" linkleri sonuclar ve iletisim'e gider.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/teklif_v2.php';

$token = isset($_GET['t']) ? trim($_GET['t']) : '';
$teklif = null;
$ferman_ad = 'Öğrenci Ad Soyad';
$ana_dil_label = 'İngilizce';

teklif_v2_ensure_schema($conn);
if ($token !== '' && strlen($token) <= 64 && ctype_xdigit($token)) {
    $teklif = teklif_v2_get_by_token($conn, $token);
    if ($teklif) {
        $ferman_ad = trim(($teklif['ogrenci_ad'] ?? '') . ' ' . ($teklif['ogrenci_soyad'] ?? ''));
        if ($ferman_ad === '') $ferman_ad = 'Öğrenci Ad Soyad';
        $sid_ing = isset($teklif['sinav_sonuc_id_ingilizce']) && $teklif['sinav_sonuc_id_ingilizce'] !== '' ? (int)$teklif['sinav_sonuc_id_ingilizce'] : 0;
        $sid_alm = isset($teklif['sinav_sonuc_id_almanca']) && $teklif['sinav_sonuc_id_almanca'] !== '' ? (int)$teklif['sinav_sonuc_id_almanca'] : 0;
        $sid_legacy = isset($teklif['sinav_sonuc_id']) && $teklif['sinav_sonuc_id'] !== '' ? (int)$teklif['sinav_sonuc_id'] : 0;
        $turu = mb_strtolower(trim((string)($teklif['sinav_turu'] ?? '')), 'UTF-8');
        if ($sid_ing <= 0 && $sid_legacy > 0 && strpos($turu, 'ingilizce') !== false) $sid_ing = $sid_legacy;
        if ($sid_alm <= 0 && $sid_legacy > 0 && strpos($turu, 'almanca') !== false) $sid_alm = $sid_legacy;
        if ($sid_ing <= 0 && $sid_alm <= 0 && $sid_legacy > 0) $sid_ing = $sid_legacy;
        $ana_dil_label = ($sid_ing <= 0 && $sid_alm > 0) ? 'Almanca' : 'İngilizce';
    }
}

$bulunamadi = ($teklif === null);
$ad_param = isset($_GET['ad']) ? '&ad=' . rawurlencode(trim((string)$_GET['ad'])) : '';
if (!$bulunamadi && $ferman_ad !== 'Öğrenci Ad Soyad') {
    if ($ad_param === '') $ad_param = '&ad=' . rawurlencode($ferman_ad);
}
$sonuclar_url = 'sonuclar.php?t=' . rawurlencode($token) . $ad_param;
$danisman_whatsapp_url = '';
if ($teklif && trim((string)($teklif['son_islem_personel'] ?? '')) !== '') {
    $personel_adi = trim((string)$teklif['son_islem_personel']);
    $stmt_tel = @mysqli_prepare($conn, "SELECT telefon FROM kullanicilar WHERE ad_soyad = ? LIMIT 1");
    if ($stmt_tel) {
        mysqli_stmt_bind_param($stmt_tel, "s", $personel_adi);
        mysqli_stmt_execute($stmt_tel);
        $res_tel = mysqli_stmt_get_result($stmt_tel);
        mysqli_stmt_close($stmt_tel);
        if ($res_tel && $row_tel = mysqli_fetch_assoc($res_tel)) {
            $p = preg_replace('/[^0-9]/', '', trim((string)($row_tel['telefon'] ?? '')));
            if (strlen($p) > 0) {
                if ($p[0] === '0') $p = substr($p, 1);
                if (strlen($p) === 10 && $p[0] === '5') $p = '90' . $p;
                elseif (strlen($p) === 9 && $p[0] === '5') $p = '90' . $p;
                if (strlen($p) >= 11) $danisman_whatsapp_url = 'https://wa.me/' . $p;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title><?= $bulunamadi ? 'Link geçersiz' : 'Bilgilendirme' ?> | Gençlik Dil</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="resimler/logoGenclik.jpg">
    <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --gd-primary: #187f9e;
            --gd-primary-dark: #146c88;
            --gd-bg: #edf3f9;
            --gd-card: #fff;
            --gd-border: #d3e1ec;
            --gd-text: #1e293b;
            --gd-muted: #64748b;
            --gd-radius: 16px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: radial-gradient(circle at 5% 5%, #f9fcff, var(--gd-bg) 42%);
            color: var(--gd-text);
            display: flex;
            flex-direction: column;
            font-size: 20px;
            line-height: 1.6;
        }
        .s-header {
            flex: 0 0 auto;
            padding: 0;
            background: rgba(255, 255, 255, 0.93);
            backdrop-filter: blur(6px);
            border-bottom: 1px solid var(--gd-border);
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .s-header-inner {
            width: 100%;
            max-width: 1360px;
            margin: 0 auto;
            padding: 11px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .s-header .logo img { height: 46px; width: auto; display: block; }
        .s-header nav { display: flex; align-items: center; gap: 10px; }
        .s-header a {
            color: var(--gd-muted);
            text-decoration: none;
            font-size: 1.08rem;
            font-weight: 700;
            padding: 8px 13px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid var(--gd-border);
            display: inline-block;
            line-height: 1.1;
            transition: all 0.2s ease;
        }
        .s-header a:hover { color: var(--gd-primary); border-color: #bcd7d3; transform: translateY(-1px); }
        .s-main { flex: 1 0 auto; width: 100%; max-width: 1360px; margin: 0 auto; padding: 34px 24px 48px; }
        .s-aciklama-wrap {
            background: var(--gd-card);
            border: 1px solid var(--gd-border);
            border-radius: var(--gd-radius);
            padding: 40px 44px;
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            box-shadow: 0 6px 20px rgba(15,23,42,0.06);
            font-size: 1.15rem;
            line-height: 1.65;
            color: var(--gd-text);
        }
        .s-aciklama-wrap h2 {
            font-size: 1.5rem;
            color: var(--gd-text);
            font-weight: 700;
            margin: 0 0 1.25em 0;
            line-height: 1.35;
        }
        .s-aciklama-wrap p {
            margin: 0 0 1em 0;
            font-size: 1.15rem;
            line-height: 1.7;
            font-weight: 400;
        }
        .s-aciklama-wrap ul {
            margin: 0 0 1em 0;
            padding-left: 1.4em;
        }
        .s-aciklama-wrap li {
            margin-bottom: 0.65em;
            font-size: 1.15rem;
            line-height: 1.7;
            font-weight: 400;
        }
        .s-aciklama-wrap a { color: var(--gd-primary); font-weight: 600; text-decoration: none; }
        .s-aciklama-wrap a:hover { text-decoration: underline; }
        .s-aciklama-wrap a.s-aciklama-cta {
            font-weight: 700;
            font-size: 1.08em;
            text-decoration: underline;
            text-underline-offset: 3px;
        }
        .s-aciklama-wrap a.s-aciklama-cta:hover { text-decoration: none; }
        .s-aciklama-sign {
            display: inline-block;
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--gd-primary-dark);
            margin-top: 1.5em;
            margin-bottom: 0;
            text-decoration: none;
        }
        .s-aciklama-sign:hover { color: var(--gd-primary); text-decoration: underline; }
        .footer-bar { flex: 0 0 auto; background-color: #2c3e50; color: #ecf0f1; padding: 15px 0; font-size: 14px; text-align: center; border-top: 4px solid #3498db; margin-top: 0 !important; }
        .footer-container { display: flex; justify-content: center; gap: 30px; flex-wrap: wrap; max-width: 1360px; margin: 0 auto; padding: 0 24px; }
        .contact-item a { color: #ecf0f1; text-decoration: none; display: flex; align-items: center; }
        .contact-item a:hover { color: #f1c40f; }
        .contact-item i { margin-right: 8px; color: #3498db; }
        .whatsapp-link i { color: #25D366 !important; font-size: 18px; }
        @media (max-width: 768px) { .footer-container { flex-direction: column; gap: 15px; } }
        .s-shell { background: rgba(255,255,255,0.6); border: 1px solid #e3ebf5; border-radius: 20px; padding: 24px; margin: 0 auto; max-width: 560px; text-align: center; }
        @media (max-width: 480px) {
            .s-main { padding: 20px 16px 32px; }
            .s-aciklama-wrap { padding: 28px 20px; }
            .s-aciklama-wrap h2 { font-size: 1.25rem; }
        }
        @media (max-width: 360px) {
            .s-aciklama-wrap h2 { font-size: 1.1rem; }
        }
    </style>
</head>
<body>
<header class="s-header">
    <div class="s-header-inner">
        <div class="logo"><a href="/"><img src="resimler/logoGenclik.jpg" alt="Gençlik Dil"></a></div>
        <nav><a href="iletisim">İletişim</a></nav>
    </div>
</header>

<main class="s-main">
<?php if ($bulunamadi): ?>
    <div class="s-shell">
        <p class="text-muted mb-0">Bu link geçersiz veya artık kullanılamıyor. Lütfen kurumumuzla <a href="iletisim">iletişime</a> geçin.</p>
    </div>
<?php else: ?>
    <div class="s-aciklama-wrap">
        <h2>Sayın Velimiz, Hoş Geldiniz</h2>
        <p>Öncelikle, gerçekleştirdiğimiz bursluluk sınavına katılan öğrencimiz <?= htmlspecialchars($ferman_ad) ?> ve sizlere teşekkür ederiz.</p>
        <p>Velisi olduğunuz <?= htmlspecialchars($ferman_ad) ?>, geleceğe dair hayallerine bir adım daha atarak <?= htmlspecialchars($ana_dil_label) ?> dilini parlatmayı seçti ve Şubat 2026'da Afyonkarahisar genelinde düzenlediğimiz Bursluluk Sınavımıza katıldı. Bu sınav sayesinde öğrencimizin okuma, yazma, dinleme ve konuşma becerilerini ne derece geliştirdiğini ve kendi yaş grubundaki diğer öğrenciler arasındaki gelişim düzeyini gözlemleme fırsatı bulduk.</p>
        <p>Gençlik Dil Kursu olarak 2026-2027 öğretim dönemi planlamamızı da sizinle paylaşmak isteriz:</p>
        <ul>
            <li>Kurslarımız Eylül 2026 – Mayıs 2027 arasında yürütülecektir. Dileyen öğrencilerimize hemen başlama veya yaz dönemi başlama opsiyonu ÜCRETSİZ'dir.</li>
            <li>Eğitim programımız uluslararası dil standartlarını uygulamasının yanında okul derslerine destek olmak için MEB programlarını da dikkate almaktadır. Böylece öğrencilerimiz hem küresel iletişim becerileri kazanırken hem de akademik olarak okullarında başarılı olacaktır.</li>
            <li>Kurumumuz butik eğitim sistematiğini temel alarak, küçük sınıf mevcutları ile her öğrenciye özel ilgi göstermektedir. Ders saatleri hafta içi ve cumartesi günleri öğrencilerin uygunluğuna göre belirlenmektedir. Düzenli takip ve geri bildirimlerle gelişim süreci desteklenmektedir.</li>
            <li>Bizim için öğrenmenin eğlenceye dönüşmesi çok önemlidir, bunun için kurumumuz ücretsiz kulüpler ve ders dışı sosyal aktivitelerle dil öğrenmeyi sevdiren bir felsefe benimsemiştir.</li>
        </ul>
        <p>Bu sene ayrıca öğrencilerimize bazı yenilikler sunuyoruz:</p>
        <ul>
            <li>Öğrencilerimiz, dersleri olmasa bile kursumuzun kapısı her zaman onlara açık. Kütüphanemizde ödevlerini yapabilir, sınavlarına hazırlanabilir veya sessiz bir ortamda kitap okuyabilirler. Tüm bu imkanlar tamamen ÜCRETSİZ'dir.</li>
            <li>Öğrencimiz Bursluluk Sınavına tek dilden girmiş olsa bile diğer dilden %60'lara varan indirimden yararlanarak tam bir Dünya vatandaşı olmaya hak kazanacaktır.</li>
        </ul>
        <p>Tüm bu planlamalarımız ve yeniliklerimize ERKEN KAYIT avantajıyla ilave indirim alarak 30 Nisan 2026 tarihine kadar sahip olabilirsiniz. Burs indirim hakkınızı kullanarak öğrencimizin hayallerine bir adım daha yaklaşmasına destek olmanızı arzu ediyoruz.</p>
        <p>Öğrencimizin bursluluk sınavındaki performansı ve kayıt işlemleri için <a href="<?= htmlspecialchars($sonuclar_url, ENT_QUOTES, 'UTF-8') ?>" class="s-aciklama-cta">tıklayınız</a>.</p>
        <p>Kayıt süreci ve ücret tablosu hakkında kurum danışmanımız ile görüşmek için <?php if ($danisman_whatsapp_url !== ''): ?><a href="<?= htmlspecialchars($danisman_whatsapp_url, ENT_QUOTES, 'UTF-8') ?>" class="s-aciklama-cta" target="_blank" rel="noopener">tıklayınız</a><?php else: ?><a href="iletisim" class="s-aciklama-cta">tıklayınız</a><?php endif; ?>.</p>
        <p>Sağlıklı ve başarılı günler dileriz.</p>
        <p>Saygılarımızla,</p>
        <a href="/" class="s-aciklama-sign">Gençlik Dil Yabancı Dil Kursu</a>
    </div>
<?php endif; ?>
</main>

<footer class="footer-bar">
    <div class="container footer-container">
        <div class="contact-item">
            <a href="https://www.google.com/maps/search/?api=1&query=Dumlupınar+Mahallesi+Yüzbaşı+Bayburtlu+Agah+Caddesi+No:12+Merkez+Afyonkarahisar" target="_blank">
                <i class="glyphicon glyphicon-map-marker"></i>
                <span>Dumlupınar Mah. Yüzbaşı Bayburtlu Agah Cad. No:12 Afyonkarahisar</span>
            </a>
        </div>
        <div class="contact-item">
            <a href="tel:02722141022">
                <i class="glyphicon glyphicon-earphone"></i>
                <span>0(272) 214 10 22</span>
            </a>
        </div>
        <div class="contact-item">
            <a href="tel:05422141022">
                <i class="glyphicon glyphicon-earphone"></i>
                <span>0 542 214 10 22</span>
            </a>
        </div>
        <div class="contact-item">
            <a href="https://wa.me/905323512078" target="_blank" class="whatsapp-link">
                <i class="fab fa-whatsapp"></i>
                <span>0(532) 351 20 78</span>
            </a>
        </div>
        <div class="contact-item">
            <a href="mailto:iletisim@genclikdil.com">
                <i class="glyphicon glyphicon-envelope"></i>
                <span>iletisim@genclikdil.com</span>
            </a>
        </div>
        <div class="contact-item" style="font-size: 12px; color: #95a5a6;">
            © 2004 Gençlik Dil Eğitim Hizmetleri Tic. ve San. Ltd. Şti. - Tüm Hakları Saklıdır.
        </div>
    </div>
</footer>
</body>
</html>
