<?php 
include("connectt.php");
$Cinsiyet=$_POST["Cinsiyet"];
$Ad=$_POST["Ad"];
$Soyad=$_POST["Soyad"];
$TC=$_POST["TC"];
$Dogum=$_POST["Dogum"];
$UniversiteTur=$_POST["UniversiteTur"];
$Fakulte=$_POST["Fakulte"];
$Bolum=$_POST["Bolum"];
$OgrenimTur=$_POST["OgrenimTur"];
$Sinif=$_POST["Sinif"];
$CepTel=$_POST["CepTel"];
$Eposta=$_POST["Eposta"];
$IlgilenDil=$_POST["IlgilenDil"];
$Amac=$_POST["Amac"];
$Sube=$_POST["Sube"];
$RandevuTur=$_POST["RandevuTur"];


   $sqlKayit="SELECT * FROM yenibursluluk WHERE TC='$TC'";
  $kayitVarMi = mysqli_query($conn,$sqlKayit);

    if (!$kayitVarMi)
    {
        die('Error: ' . mysqli_error($conn));
    }

if(mysqli_num_rows($kayitVarMi) > 0){

    echo "kayitli";

}
else{       
$sql = "INSERT INTO yenibursluluk(Cinsiyet,Ad,Soyad,TC,Dogum,UniversiteTur,Fakulte,Bolum,OgrenimTur,Sinif,CepTel,Eposta,IlgilenDil,Amac,Sube,RandevuTur,Tarih)
VALUES ('$Cinsiyet','$Ad','$Soyad','$TC','$Dogum','$UniversiteTur','$Fakulte','$Bolum','$OgrenimTur','$Sinif','$CepTel','$Eposta','$IlgilenDil','$Amac','$Sube','$RandevuTur',now())";

if (mysqli_query($conn, $sql)) {
    echo "eklendi";
} else {
    echo "Hata: " . $sql . "<br>" . mysqli_error($conn);
}

mysqli_close($conn);

}

?>