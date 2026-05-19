<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ödeme hatası | Gençlik Dil</title>
    <link rel="icon" type="image/x-icon" href="resimler/logoGenclik.jpg">
    <style>
        body { font-family: sans-serif; margin: 0; padding: 40px 20px; background: #f0f4f8; text-align: center; }
        .kutu { max-width: 480px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .hata { color: #b91c1c; margin-bottom: 16px; }
        a { color: #0d9488; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="kutu">
        <p class="hata"><?= isset($hata) ? htmlspecialchars($hata) : 'Bir hata oluştu.' ?></p>
        <p><a href="sonuclar.php">← Bursluluk sınav sonucu sayfasına dön</a></p>
    </div>
</body>
</html>
