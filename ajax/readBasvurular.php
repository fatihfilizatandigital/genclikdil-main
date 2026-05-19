<?php
if(file_exists("connectt.php")) { include("connectt.php"); } 
elseif(file_exists("../connectt.php")) { include("../connectt.php"); } 
else { die('<tr><td colspan="16" style="color:red;">HATA: connectt.php bulunamadı!</td></tr>'); }

if(!isset($conn)) { die('<tr><td colspan="16" style="color:red;">HATA: Bağlantı yok.</td></tr>'); }

mysqli_set_charset($conn, "utf8");

// FİLTRELER
$whereClause = "WHERE 1=1"; 

if(isset($_GET['ogrenciAd']) && $_GET['ogrenciAd'] != "") {
    $ad = mysqli_real_escape_string($conn, $_GET['ogrenciAd']);
    $whereClause .= " AND Ad LIKE '%$ad%'";
}

if(isset($_GET['ogrenciSoyad']) && $_GET['ogrenciSoyad'] != "") {
    $soyad = mysqli_real_escape_string($conn, $_GET['ogrenciSoyad']);
    $whereClause .= " AND Soyad LIKE '%$soyad%'";
}

if(isset($_GET['ogrenciTC']) && $_GET['ogrenciTC'] != "") {
    $tc = mysqli_real_escape_string($conn, $_GET['ogrenciTC']);
    $tcTemiz = preg_replace('/[\s\-]+/', '', $tc);
    $whereClause .= " AND REPLACE(REPLACE(TC, ' ', ''), '-', '') LIKE '%$tcTemiz%'";
}

if(isset($_GET['ogrenciNumara']) && $_GET['ogrenciNumara'] != "") {
    $numara = mysqli_real_escape_string($conn, $_GET['ogrenciNumara']);
    $numaraTemiz = preg_replace('/[\s\-\(\)]+/', '', $numara);
    if(preg_match('/^9/', $numaraTemiz)) {
        $numaraAlternatif = '0' . substr($numaraTemiz, 1);
        $whereClause .= " AND (REPLACE(REPLACE(REPLACE(REPLACE(VeliTel1, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraTemiz%' OR REPLACE(REPLACE(REPLACE(REPLACE(VeliTel1, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraAlternatif%' OR REPLACE(REPLACE(REPLACE(REPLACE(VeliTel2, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraTemiz%' OR REPLACE(REPLACE(REPLACE(REPLACE(VeliTel2, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraAlternatif%')";
    } else {
        $whereClause .= " AND (REPLACE(REPLACE(REPLACE(REPLACE(VeliTel1, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraTemiz%' OR REPLACE(REPLACE(REPLACE(REPLACE(VeliTel2, ' ', ''), '-', ''), '(', ''), ')', '') LIKE '%$numaraTemiz%')";
    }
}

if(isset($_GET['sinav']) && $_GET['sinav'] != "") {
    $sinav = mysqli_real_escape_string($conn, $_GET['sinav']);
    $whereClause .= " AND SinavTuru = '$sinav'";
}

if(isset($_GET['sinif']) && $_GET['sinif'] != "") {
    $sinif = mysqli_real_escape_string($conn, $_GET['sinif']);
    $whereClause .= " AND Sinif = '$sinif'";
}

if(isset($_GET['katilim']) && $_GET['katilim'] !== "") {
    $katilim = mysqli_real_escape_string($conn, $_GET['katilim']);
    $whereClause .= " AND KatilimDurumu = '$katilim'";
}

$limit = 50;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$returnFragment = ($offset > 0);

$sql = "SELECT * FROM yenibursluluk $whereClause ORDER BY ID DESC LIMIT " . ($limit + 1) . " OFFSET " . $offset;

if(!$result = mysqli_query($conn, $sql)){
    if ($returnFragment) {
        echo '<tr class="basvuru-paging-marker" data-has-more="0" data-next-offset="0" style="display:none;"><td colspan="17"></td></tr>';
    } else {
        echo '<table class="table table-striped table-hover table-bordered"><tbody><tr><td colspan="17" style="color:red;">SQL HATASI: '.mysqli_error($conn).'</td></tr></tbody></table>';
    }
    exit;
}

$rows = [];
while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
$hasMore = (count($rows) > $limit);
if ($hasMore) array_pop($rows);

$pagingMarker = '<tr class="basvuru-paging-marker" data-has-more="'.($hasMore ? '1' : '0').'" data-next-offset="'.($offset + $limit).'" style="display:none;"><td colspan="17"></td></tr>';

if (!$returnFragment) {
    $data = '<table class="table table-striped table-hover table-bordered">
<thead class="thead-dark">
<tr>
    <th>#</th>
    <th style="text-align:center; min-width:100px;">İŞLEMLER</th>
    <th style="text-align:center; min-width:110px;">KATILIM</th>
    <th>SINAV TÜRÜ</th>
    <th>ADI</th>
    <th>SOYADI</th>
    <th>SINIF</th>
    <th>TC NO</th>
    <th>VELİ TEL 1</th>
    <th>VELİ TEL 2</th>
    <th>VELİ ADI</th>
    <th>DOĞUM TAR.</th>
    <th>KAYIT TARİHİ</th>
    <th style="font-size:11px; color:#aaa;">Cinsiyet</th>
    <th style="font-size:11px; color:#aaa;">Okul</th>
    <th style="font-size:11px; color:#aaa;">Veli Meslek</th>
    <th style="font-size:11px; color:#aaa;">Email</th>
    <th style="font-size:11px; color:#aaa;">ŞUBE</th>
</tr>
</thead>
<tbody>';
}

foreach ($rows as $row) {
    $kayitTarihi = date("d.m.Y H:i", strtotime($row['Tarih']));

        // --- KATILIM TARİHİ HESAPLAMA ---
        $islemBilgisi = "Henüz işlem yapılmadı";
        if(!empty($row['KatilimTarihi'])) {
            $islemBilgisi = "Son İşlem: " . date("d.m.Y H:i", strtotime($row['KatilimTarihi']));
        }

        $sinavLabel = "";
        if($row['SinavTuru'] == 'Ingilizce') $sinavLabel = '<span class="label label-primary">İngilizce</span>';
        elseif($row['SinavTuru'] == 'Almanca') $sinavLabel = '<span class="label label-warning">Almanca</span>';
        elseif($row['SinavTuru'] == 'Her Ikisi') $sinavLabel = '<span class="label label-success">Her İkisi</span>';
        else $sinavLabel = '<span class="label label-default">Belirtilmedi</span>';

        $durum = $row['KatilimDurumu'];
        $btnClass = ($durum == 1) ? "btn-success" : "btn-danger";
        $btnText  = ($durum == 1) ? '<i class="fas fa-check"></i> GELDİ' : '<i class="fas fa-times"></i> GELMEDİ';
        
        // title özelliğine tarih bilgisini ekliyoruz
        $katilimButon = '<button type="button" class="btn btn-sm '.$btnClass.' btn-block katilim-btn" 
                            data-id="'.$row['ID'].'" 
                            data-status="'.$durum.'"
                            title="'.$islemBilgisi.'" 
                            data-toggle="tooltip"
                            style="font-weight:bold; font-size:11px;">
                            '.$btnText.'
                         </button>';

        $btnDüzenle = '<button type="button" class="btn btn-sm btn-outline-primary basvuru-duzenle-btn" title="Düzenle" data-id="'.(int)$row['ID'].'" data-ad="'.htmlspecialchars($row['Ad'] ?? '', ENT_QUOTES, 'UTF-8').'" data-soyad="'.htmlspecialchars($row['Soyad'] ?? '', ENT_QUOTES, 'UTF-8').'" data-tc="'.htmlspecialchars($row['TC'] ?? '', ENT_QUOTES, 'UTF-8').'" data-sinif="'.htmlspecialchars($row['Sinif'] ?? '', ENT_QUOTES, 'UTF-8').'" data-dogum="'.htmlspecialchars($row['Dogum'] ?? '', ENT_QUOTES, 'UTF-8').'" data-veliad="'.htmlspecialchars($row['VeliAd'] ?? '', ENT_QUOTES, 'UTF-8').'" data-velisoyad="'.htmlspecialchars($row['VeliSoyad'] ?? '', ENT_QUOTES, 'UTF-8').'" data-velitel1="'.htmlspecialchars($row['VeliTel1'] ?? '', ENT_QUOTES, 'UTF-8').'" data-velitel2="'.htmlspecialchars($row['VeliTel2'] ?? '', ENT_QUOTES, 'UTF-8').'" data-veliemail="'.htmlspecialchars($row['VeliEmail'] ?? '', ENT_QUOTES, 'UTF-8').'" data-okul="'.htmlspecialchars($row['Okul'] ?? '', ENT_QUOTES, 'UTF-8').'" data-cinsiyet="'.htmlspecialchars($row['Cinsiyet'] ?? '', ENT_QUOTES, 'UTF-8').'" data-velimeslek="'.htmlspecialchars($row['VeliMeslek'] ?? '', ENT_QUOTES, 'UTF-8').'" data-sube="'.htmlspecialchars($row['Sube'] ?? '', ENT_QUOTES, 'UTF-8').'" data-sinavturu="'.htmlspecialchars($row['SinavTuru'] ?? '', ENT_QUOTES, 'UTF-8').'"><i class="fas fa-edit"></i></button>';
        $btnSil = '<button type="button" class="btn btn-sm btn-outline-danger basvuru-sil-btn" title="Sil" data-id="'.(int)$row['ID'].'"><i class="fas fa-trash-alt"></i></button>';
        $islemler = '<div class="btn-group btn-group-sm">'.$btnDüzenle.' '.$btnSil.'</div>';

    $rowHtml = '<tr>
            <td>'.$row['ID'].'</td>
            <td style="text-align:center;">'.$islemler.'</td>
            <td>'.$katilimButon.'</td>
            <td>'.$sinavLabel.'</td>
            <td><strong>'.$row['Ad'].'</strong></td>
            <td><strong>'.$row['Soyad'].'</strong></td>
            <td style="text-align:center; font-weight:bold;">'.$row['Sinif'].'</td>
            <td>'.$row['TC'].'</td>
            <td>'.$row['VeliTel1'].'</td>
            <td>'.$row['VeliTel2'].'</td>
            <td>'.$row['VeliAd'].' '.$row['VeliSoyad'].'</td>
            <td>'.$row['Dogum'].'</td>
            <td>'.$kayitTarihi.'</td>
            <td style="color:#777;">'.$row['Cinsiyet'].'</td>
            <td style="color:#777;">'.$row['Okul'].'</td>
            <td style="color:#777;">'.$row['VeliMeslek'].'</td>
            <td style="color:#777;">'.$row['VeliEmail'].'</td>
            <td style="color:#555; font-weight:bold;">'.$row['Sube'].'</td>
        </tr>';
    if ($returnFragment) {
        echo $rowHtml;
    } else {
        $data .= $rowHtml;
    }
}

if ($returnFragment) {
    echo $pagingMarker;
} else {
    if (count($rows) === 0) {
        $data .= '<tr><td colspan="17" class="text-center" style="padding:20px;">Kriterlere uygun kayıt bulunamadı.</td></tr>';
    }
    $data .= $pagingMarker . '</tbody></table>';
    echo $data;
}
?>