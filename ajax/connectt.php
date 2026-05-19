<?php
/*
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "farminvest";
$conn = mysqli_connect($servername, $username, $password, $dbname);
// Check connection
if (!$conn) {
    die("Bağlantı Hatası: " . mysqli_connect_error());
} 
*/


$servername = "213.142.130.21:3306";
$username = "adnan";
$password = "adnan.1234";
$dbname = "farmMysql";
$conn = mysqli_connect($servername, $username, $password, $dbname);
// Check connection
if (!$conn) {
    die("Bağlantı Hatası: " . mysqli_connect_error());
} 


?>