<?php
require_once __DIR__ . '/config/db.php';

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
    KEY idx_created_at (created_at),
    KEY idx_durum (durum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'soru_sor') {
    $ad_soyad = trim((string)($_POST['ad_soyad'] ?? ''));
    $telefon_raw = trim((string)($_POST['telefon'] ?? ''));
    $mesaj = trim((string)($_POST['mesaj'] ?? ''));
    $telefon = preg_replace('/\D+/', '', $telefon_raw);
    if (strlen($telefon) === 10) $telefon = '0' . $telefon;

    if ($ad_soyad === '' || $mesaj === '' || strlen($telefon) < 10) {
        $flashErr = 'Lütfen ad soyad, telefon ve mesaj alanlarını eksiksiz doldurun.';
    } else {
        $st = mysqli_prepare($conn, "INSERT INTO erken_kayit_iletisim_talepleri (ad_soyad, telefon, mesaj) VALUES (?, ?, ?)");
        if ($st) {
            mysqli_stmt_bind_param($st, 'sss', $ad_soyad, $telefon, $mesaj);
            if (mysqli_stmt_execute($st)) {
                $flashOk = 'Mesajınız alındı. En kısa sürede size dönüş yapacağız.';
            } else {
                $flashErr = 'Mesaj kaydedilemedi, lütfen tekrar deneyin.';
            }
            mysqli_stmt_close($st);
        } else {
            $flashErr = 'Form işlemi şu anda kullanılamıyor.';
        }
    }
}

$odemeDurum = '';
$odemeMesaj = '';
if (!empty($_GET['oid'])) {
    $oid = trim((string)$_GET['oid']);
    $st = mysqli_prepare($conn, "SELECT odeme_durumu FROM erken_kayit_basvurular WHERE merchant_oid = ? LIMIT 1");
    if ($st) {
        mysqli_stmt_bind_param($st, 's', $oid);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($st);
        if ($row) {
            $odemeDurum = (string)($row['odeme_durumu'] ?? '');
        }
    }
}
if (isset($_GET['odeme']) && $_GET['odeme'] === 'basarili') {
    if ($odemeDurum === 'success') {
        $odemeMesaj = 'Online ödemeniz başarıyla tamamlandı. Kayıt süreciniz başlatıldı.';
    } else {
        $odemeMesaj = 'Ödeme sonucu kontrol ediliyor. Kısa süre içinde durum güncellenecektir.';
    }
} elseif (isset($_GET['odeme']) && $_GET['odeme'] === 'hata') {
    $odemeMesaj = 'Ödeme işlemi tamamlanamadı. Tekrar deneyebilir veya bize mesaj bırakabilirsiniz.';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <title>Erken Kayıt Kampanyası - Gençlik Dil</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="resimler/logoGenclik.jpg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], heading: ['Poppins', 'sans-serif'] },
                    colors: { primary: '#2563eb', secondary: '#10b981', dark: '#0f172a' }
                }
            }
        };
    </script>
    <style>
        /* Paket seçimi için gizli input ve görsel zenginlik ayarları */
        .paket-radio:checked + div {
            border-color: #2563eb;
            background-color: #eff6ff;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.1), 0 2px 4px -1px rgba(37, 99, 235, 0.06);
        }
        .paket-radio:checked + div .check-icon {
            opacity: 1;
            transform: scale(1);
        }
    </style>
</head>
<body class="bg-gray-50 flex flex-col min-h-screen">
<header class="bg-white shadow-sm sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-2 md:py-4 flex-wrap md:flex-nowrap">
            <a href="/" class="flex-shrink-0 mx-auto md:mx-0 mb-2 md:mb-0">
                <img src="resimler/logoGenclik.jpg" alt="GENÇLİK DİL" class="h-10 md:h-14 w-auto rounded-lg">
            </a>
            <nav class="w-full md:w-auto overflow-x-auto pb-1 md:pb-0">
                <ul class="flex justify-center md:justify-end gap-2 md:gap-4 items-center font-heading text-[11px] md:text-sm font-semibold whitespace-nowrap">
                    <li><a href="bursluluk" class="inline-flex items-center px-3 py-1.5 md:px-5 md:py-2.5 rounded-full bg-primary text-white hover:bg-blue-700 transition-colors shadow-sm"><i class="fas fa-graduation-cap mr-1.5 md:mr-2"></i> BURSLULUK SINAVI</a></li>
                    <li><a href="erken-kayit-kampanyasi" class="text-primary font-bold px-2">ERKEN KAYIT</a></li>
                    <li><a href="iletisim" class="text-gray-600 hover:text-primary transition-colors px-2">İLETİŞİM</a></li>
                </ul>
            </nav>
        </div>
    </div>
</header>

<main class="flex-grow">
    <!-- Hero Section -->
    <section class="relative bg-dark text-white pt-16 pb-20 overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-blue-900 to-dark opacity-90"></div>
        <div class="absolute -top-24 -right-24 w-96 h-96 bg-primary rounded-full mix-blend-multiply filter blur-3xl opacity-30"></div>
        
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <div class="inline-flex items-center justify-center space-x-2 bg-white/10 border border-white/20 rounded-full px-5 py-2 mb-6 backdrop-blur-sm">
                <span class="flex h-3 w-3 relative">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                </span>
                <span class="text-sm font-semibold tracking-wide uppercase text-blue-100">Son Tarih: 30 Nisan 2026</span>
            </div>
            
            <h1 class="text-4xl md:text-6xl font-heading font-black mb-6 leading-tight">
                Geleceğe Güçlü Bir Adım: <br>
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-teal-300">Erken Kayıt Avantajları</span>
            </h1>
            
            <p class="text-lg md:text-xl text-gray-300 max-w-3xl mx-auto font-medium">
                Yeni eğitim döneminde yerinizi şimdiden ayırtın, %50'ye varan avantajlı fiyatlardan ve hediye yaz okulu fırsatından yararlanın.
            </p>
        </div>
    </section>

    <!-- Eğitim Bilgileri (Yeni Eklenen Düzenlenebilir Alan) -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 -mt-10 relative z-10">
        <div class="grid md:grid-cols-2 gap-6">
            <!-- Yaz Dönemi -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-8 transform transition hover:-translate-y-1">
                <div class="w-14 h-14 bg-orange-100 text-orange-500 rounded-xl flex items-center justify-center text-2xl mb-6">
                    <i class="fas fa-sun"></i>
                </div>
                <h3 class="text-2xl font-bold font-heading text-gray-900 mb-4">Yaz Dönemi Eğitimleri</h3>
                <p class="text-gray-600 mb-4 leading-relaxed">Öğrencilerimizin yaz tatilini hem eğlenerek hem de öğrenerek geçirmeleri için özel olarak hazırladığımız yaz programımız ile dil becerilerini canlı tutuyoruz.</p>
                <ul class="space-y-3 text-sm text-gray-700 font-medium">
                    <li class="flex items-start"><i class="fas fa-check-circle text-green-500 mt-0.5 mr-3"></i> Yoğunlaştırılmış dil pratiği ve konuşma kulüpleri</li>
                    <li class="flex items-start"><i class="fas fa-check-circle text-green-500 mt-0.5 mr-3"></i> Sosyal ve kültürel etkinliklerle desteklenen dersler</li>
                    <li class="flex items-start"><i class="fas fa-check-circle text-green-500 mt-0.5 mr-3"></i> Gramer açıklarını kapatmaya yönelik etüt çalışmaları</li>
                    <li class="flex items-start"><i class="fas fa-check-circle text-green-500 mt-0.5 mr-3"></i> Eğlenceli materyallerle interaktif öğrenme ortamı</li>
                </ul>
            </div>

            <!-- Okul Dönemi -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-8 transform transition hover:-translate-y-1">
                <div class="w-14 h-14 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center text-2xl mb-6">
                    <i class="fas fa-school"></i>
                </div>
                <h3 class="text-2xl font-bold font-heading text-gray-900 mb-4">Okul Dönemi Eğitimleri</h3>
                <p class="text-gray-600 mb-4 leading-relaxed">Milli Eğitim müfredatına tam uyumlu ve akademik başarıyı odak noktasına alan uzun dönem eğitim modelimiz ile okul derslerine tam destek sağlıyoruz.</p>
                <ul class="space-y-3 text-sm text-gray-700 font-medium">
                    <li class="flex items-start"><i class="fas fa-check-circle text-green-500 mt-0.5 mr-3"></i> Okul müfredatına paralel akademik İngilizce desteği</li>
                    <li class="flex items-start"><i class="fas fa-check-circle text-green-500 mt-0.5 mr-3"></i> Ulusal ve uluslararası sınavlara özel hazırlık modülleri</li>
                    <li class="flex items-start"><i class="fas fa-check-circle text-green-500 mt-0.5 mr-3"></i> Düzenli deneme sınavları ve gelişim takip raporları</li>
                    <li class="flex items-start"><i class="fas fa-check-circle text-green-500 mt-0.5 mr-3"></i> Birebir rehberlik ve veli bilgilendirme sistemi</li>
                </ul>
            </div>
        </div>
    </section>

    <!-- Formlar ve Kampanya Detayları -->
    <section class="py-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-3 gap-8">
                
                <!-- Sol Kolon: Paket Seçimi ve Ödeme Formu -->
                <div class="lg:col-span-2 space-y-6">
                    
                    <?php if ($odemeMesaj !== ''): ?>
                        <div class="rounded-xl border px-5 py-4 text-sm font-medium flex items-center shadow-sm <?= (isset($_GET['odeme']) && $_GET['odeme'] === 'basarili') ? 'bg-green-50 border-green-200 text-green-800' : 'bg-amber-50 border-amber-200 text-amber-800' ?>">
                            <i class="fas <?= (isset($_GET['odeme']) && $_GET['odeme'] === 'basarili') ? 'fa-check-circle text-green-500' : 'fa-exclamation-triangle text-amber-500' ?> text-xl mr-3"></i>
                            <?= htmlspecialchars($odemeMesaj, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 md:p-8">
                        <div class="border-b border-gray-100 pb-6 mb-6">
                            <h2 class="text-2xl font-bold font-heading text-gray-900">Online Kayıt ve Ödeme</h2>
                            <p class="text-gray-500 text-sm mt-1">Lütfen paketinizi seçin ve öğrenci/veli bilgilerinizi eksiksiz doldurun.</p>
                        </div>

                        <form method="post" action="kampanya_odeme.php" id="kampanya-odeme-form">
                            
                            <!-- Kampanya Paket Kartları -->
                            <div class="mb-8 space-y-4">
                                <!-- Yaz Paketi -->
                                <label class="cursor-pointer block relative">
                                    <input type="radio" name="paket" value="yaz" class="paket-radio sr-only" checked>
                                    <div class="border-2 border-gray-200 rounded-2xl p-5 transition-all duration-200 hover:border-blue-300 relative overflow-hidden">
                                        
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <div class="flex items-center gap-3 mb-1">
                                                    <h4 class="text-lg font-bold text-gray-900">Yaz Dönemi Paketi</h4>
                                                </div>
                                                <div class="flex items-baseline gap-2">
                                                    <span class="text-xl md:text-2xl font-black text-primary">25.000 TL</span>
                                                    <span class="text-sm text-gray-400 line-through font-medium">66.200 TL</span>
                                                </div>
                                            </div>
                                            <!-- Check İkonu (Sadece seçiliyken görünür) -->
                                            <div class="check-icon opacity-0 transform scale-50 transition-all duration-200 w-8 h-8 bg-primary rounded-full flex items-center justify-center text-white">
                                                <i class="fas fa-check"></i>
                                            </div>
                                        </div>
                                    </div>
                                </label>

                                <!-- Okul Paketi -->
                                <label class="cursor-pointer block relative">
                                    <input type="radio" name="paket" value="okul" class="paket-radio sr-only">
                                    <div class="border-2 border-gray-200 rounded-2xl p-5 transition-all duration-200 hover:border-blue-300 relative overflow-hidden">
                                        
                                        <!-- Hediye Bandı -->
                                        <div class="absolute top-0 right-0 bg-green-500 text-white text-[11px] font-bold px-3 py-1 rounded-bl-lg uppercase tracking-wider">
                                            Yaz Dönemi Hediye!
                                        </div>

                                        <div class="flex justify-between items-start">
                                            <div>
                                                <div class="flex items-center gap-3 mb-1 mt-2 md:mt-0">
                                                    <h4 class="text-lg font-bold text-gray-900">Okul Dönemi Paketi <span class="text-xs font-semibold bg-blue-100 text-blue-700 px-2 py-0.5 rounded ml-2">En Çok Tercih Edilen</span></h4>
                                                </div>
                                                <div class="flex items-baseline gap-2">
                                                    <span class="text-xl md:text-2xl font-black text-primary">56.700 TL</span>
                                                    <span class="text-sm text-gray-400 line-through font-medium">66.200 TL</span>
                                                </div>
                                                <p class="text-xs font-semibold text-green-600 mt-2"><i class="fas fa-gift mr-1"></i> Okul dönemi paketi alan öğrencimize Yaz Dönemi tamamen ücretsiz!</p>
                                            </div>
                                            <div class="check-icon opacity-0 transform scale-50 transition-all duration-200 w-8 h-8 bg-primary rounded-full flex items-center justify-center text-white mt-4 md:mt-0">
                                                <i class="fas fa-check"></i>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-6 flex items-start">
                                <i class="fas fa-info-circle text-amber-600 mt-0.5 mr-2 flex-shrink-0"></i>
                                <span>Kitap materyal ücreti kurumda ayrıca tahsil edilecektir.</span>
                            </p>

                            <!-- Bilgi Formu -->
                            <div class="grid md:grid-cols-2 gap-5 mb-8">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Veli Adı</label>
                                    <input required type="text" name="veli_ad" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 focus:ring-2 focus:ring-primary/20 focus:border-primary transition-colors outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Veli Soyadı</label>
                                    <input required type="text" name="veli_soyad" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 focus:ring-2 focus:ring-primary/20 focus:border-primary transition-colors outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Telefon</label>
                                    <input required type="text" name="veli_tel" placeholder="05xx xxx xx xx" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 focus:ring-2 focus:ring-primary/20 focus:border-primary transition-colors outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">E-posta</label>
                                    <input required type="email" name="veli_email" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 focus:ring-2 focus:ring-primary/20 focus:border-primary transition-colors outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Öğrenci Adı</label>
                                    <input required type="text" name="ogrenci_ad" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 focus:ring-2 focus:ring-primary/20 focus:border-primary transition-colors outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Öğrenci Soyadı</label>
                                    <input required type="text" name="ogrenci_soyad" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 focus:ring-2 focus:ring-primary/20 focus:border-primary transition-colors outline-none">
                                </div>
                            </div>

                            <!-- Ödeme Özeti ve Onay -->
                            <div class="bg-gray-50 border border-gray-200 rounded-xl p-5 mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
                                <div>
                                    <span class="text-sm text-gray-500 font-medium">Ödenecek Tutar</span>
                                    <div id="odeme-ozet" class="text-2xl font-black text-gray-900 mt-1">25.000 TL</div>
                                </div>
                                
                                <label class="flex items-start gap-3 text-sm text-gray-600 bg-white p-3 rounded-lg border border-gray-100 flex-1">
                                    <input type="checkbox" name="kampanya_sozlesme_onay" value="1" required class="mt-1 w-4 h-4 text-primary rounded focus:ring-primary">
                                    <span class="leading-snug">Online ödeme sonrası kayıt işlemlerinin başlatılacağını, resmi evrakların kurumumuzda tamamlanacağını okudum ve kabul ettim.</span>
                                </label>
                            </div>

                            <button type="submit" class="w-full md:w-auto inline-flex justify-center items-center px-8 py-4 rounded-xl bg-primary text-white font-bold text-lg hover:bg-blue-700 shadow-lg shadow-blue-500/30 transition-all hover:-translate-y-0.5">
                                <i class="fas fa-lock mr-2"></i> Güvenli Ödeme Adımına Geç
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Sağ Kolon: İletişim Formu -->
                <div class="space-y-6">
                    
                    <div class="bg-gradient-to-br from-dark to-blue-900 rounded-2xl shadow-lg border border-gray-800 p-6 md:p-8 text-white">
                        <h3 class="text-xl font-bold font-heading mb-2">Aklınıza Takılanlar Mı Var?</h3>
                        <p class="text-sm text-gray-300 mb-6 leading-relaxed">Online ödemeyi hemen yapmak istemiyorsanız bilgilerinizi bırakın. Eğitim danışmanlarımız sizi arayıp kampanyalar hakkında detaylı bilgi versin.</p>

                        <?php if ($flashOk !== ''): ?>
                            <div class="mb-5 rounded-xl border border-green-500/30 bg-green-500/10 text-green-300 text-sm px-4 py-3 flex items-start">
                                <i class="fas fa-check-circle mt-0.5 mr-2"></i>
                                <?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($flashErr !== ''): ?>
                            <div class="mb-5 rounded-xl border border-red-500/30 bg-red-500/10 text-red-300 text-sm px-4 py-3 flex items-start">
                                <i class="fas fa-exclamation-circle mt-0.5 mr-2"></i>
                                <?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" class="space-y-4">
                            <input type="hidden" name="form_type" value="soru_sor">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Adınız Soyadınız</label>
                                <input required type="text" name="ad_soyad" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-2.5 text-white placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition-all">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Telefon Numaranız</label>
                                <input required type="text" name="telefon" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-2.5 text-white placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition-all">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Mesajınız / Sorunuz</label>
                                <textarea required name="mesaj" rows="3" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-2.5 text-white placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition-all"></textarea>
                            </div>
                            <button type="submit" class="w-full inline-flex justify-center items-center px-5 py-3 rounded-xl bg-white text-dark font-bold hover:bg-gray-100 transition-colors">
                                <i class="fas fa-paper-plane mr-2 text-primary"></i> Beni Arayın
                            </button>
                        </form>
                    </div>

                    <!-- Hızlı İletişim Kartı -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 flex items-center justify-between">
                        <div>
                            <div class="text-sm text-gray-500 font-medium mb-1">Hemen Destek Alın</div>
                            <a href="https://wa.me/905323512078" target="_blank" class="text-lg font-bold font-heading text-gray-900 hover:text-green-600 transition-colors">0(532) 351 20 78</a>
                        </div>
                        <a href="https://wa.me/905323512078" target="_blank" class="w-12 h-12 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-2xl hover:bg-green-600 hover:text-white transition-all">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    </div>

                </div>
            </div>
        </div>
    </section>
</main>

<footer class="bg-gray-900 text-gray-300 py-10 border-t-4 border-primary mt-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row justify-center items-center gap-6 md:gap-10 flex-wrap text-sm">
            <a href="https://www.google.com/maps/search/?api=1&query=Dumlupınar+Mahallesi+Yüzbaşı+Bayburtlu+Agah+Caddesi+No:12+Merkez+Afyonkarahisar" target="_blank" class="flex items-center hover:text-white transition-colors"><i class="fas fa-map-marker-alt text-primary mr-3 text-lg"></i>Dumlupınar Mah. Yzb. Bayburtlu Agah Cad. No:12 Afyonkarahisar</a>
            <div class="flex gap-6 flex-wrap justify-center">
                <a href="tel:02722141022" class="flex items-center hover:text-white transition-colors"><i class="fas fa-phone-alt text-primary mr-2"></i> 0(272) 214 10 22</a>
                <a href="tel:05422141022" class="flex items-center hover:text-white transition-colors"><i class="fas fa-phone-alt text-primary mr-2"></i> 0(542) 214 10 22</a>
                <a href="https://wa.me/905323512078" target="_blank" class="flex items-center hover:text-white transition-colors"><i class="fab fa-whatsapp text-[#25d366] text-lg mr-2"></i> 0(532) 351 20 78</a>
                <a href="mailto:iletisim@genclikdil.com" class="flex items-center hover:text-white transition-colors"><i class="fas fa-envelope text-primary mr-2"></i> iletisim@genclikdil.com</a>
            </div>
        </div>
        <div class="text-center mt-8 pt-8 border-t border-gray-800 text-xs text-gray-500">© Gençlik Dil Eğitim Hizmetleri Tic. ve San. Ltd. Şti. - Tüm Hakları Saklıdır.</div>
    </div>
</footer>

<script>
    (function () {
        var radios = document.querySelectorAll('input[name="paket"]');
        var ozet = document.getElementById('odeme-ozet');
        function guncelle() {
            var paket = document.querySelector('input[name="paket"]:checked');
            if (!paket || !ozet) return;
            if (paket.value === 'okul') {
                ozet.innerHTML = '56.700 TL <span class="block text-sm text-green-600 font-semibold mt-1">+ Yaz Dönemi Hediye!</span>';
            } else {
                ozet.innerHTML = '25.000 TL';
            }
        }
        for (var i = 0; i < radios.length; i++) {
            radios[i].addEventListener('change', guncelle);
        }
        guncelle();
    })();
</script>
</body>
</html>