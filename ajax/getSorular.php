<?php
header('Content-Type: application/json; charset=utf-8');

// Veritabanı bağlantısı
if(file_exists("connectt.php")) include("connectt.php");
elseif(file_exists("../connectt.php")) include("../connectt.php");
else { echo json_encode([]); exit; }

mysqli_set_charset($conn, "utf8");

// Soruları Çek
$sql = "SELECT * FROM tbl_sorular ORDER BY RAND()"; // Her seferinde karışık gelsin
$result = mysqli_query($conn, $sql);

$sorular = array();

while($row = mysqli_fetch_assoc($result)){
    // Veritabanı satırını JS'in anlayacağı JSON formatına çeviriyoruz
    $soruItem = array(
        "id" => (int)$row['id'],
        "dil" => $row['dil'],       // Ingilizce / Almanca
        "seviye" => $row['seviye'], // A1, A2...
        "kategori" => $row['kategori'],
        "soru" => $row['soru'],
        "secenekler" => array(
            $row['secenek_a'],
            $row['secenek_b'],
            $row['secenek_c'],
            $row['secenek_d']
        ),
        "dogru" => (int)$row['dogru_cevap'] // 0, 1, 2, 3
    );
    array_push($sorular, $soruItem);
}

// JSON olarak bas
echo json_encode($sorular, JSON_UNESCAPED_UNICODE);
?>