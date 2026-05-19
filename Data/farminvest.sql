-- phpMyAdmin SQL Dump
-- version 4.6.5.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 07, 2020 at 09:28 PM
-- Server version: 10.1.21-MariaDB
-- PHP Version: 7.1.1

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `farminvest`
--

-- --------------------------------------------------------

--
-- Table structure for table `bursluluk`
--

CREATE TABLE `bursluluk` (
  `ID` bigint(50) NOT NULL,
  `Adi` varchar(50) NOT NULL,
  `Soyadi` varchar(50) NOT NULL,
  `TC` varchar(15) NOT NULL,
  `Cinsiyet` varchar(50) NOT NULL,
  `Dogum` varchar(50) NOT NULL,
  `Okul` varchar(50) NOT NULL,
  `Sinif` varchar(50) NOT NULL,
  `SinavTarih` varchar(50) NOT NULL,
  `SinavSaat` varchar(50) NOT NULL,
  `VeliAdi` varchar(50) NOT NULL,
  `VeliSoyadi` varchar(50) NOT NULL,
  `VeliTel1` varchar(30) NOT NULL,
  `VeliTel2` varchar(30) NOT NULL,
  `VeliMeslek` varchar(50) NOT NULL,
  `VeliEmail` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `bursluluk`
--

INSERT INTO `bursluluk` (`ID`, `Adi`, `Soyadi`, `TC`, `Cinsiyet`, `Dogum`, `Okul`, `Sinif`, `SinavTarih`, `SinavSaat`, `VeliAdi`, `VeliSoyadi`, `VeliTel1`, `VeliTel2`, `VeliMeslek`, `VeliEmail`) VALUES
(1, 'dsdhskjdsds', 'dsdsdsd', '286383838', '', '', '', '', '', '', '', '', '', '', '', ''),
(2, 'ÅŸÅŸÅŸÄŸiÅŸill', 'hgjhgjhggjhgj', '13132344', '', '', '', '', '', '', '', '', '', '', '', ''),
(3, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''),
(4, 'kadir', 'dursun', '', '', '', '', '', '', '', '', '', '', '', '', ''),
(5, '', '', '', 'Sec', '', '', 'Sinif', '', '', '', '', '', '', '', ''),
(6, 'ahmet', 'zÄ±rtapoz', '93129391232030', 'Erkek', '01011984', 'ÅŸemsettin', '5', '', '', 'jjuqjsq', 'kqkkq', '667889', '89786774567', 'Ã¶ÄŸretmen', 'kdursun2005@yahoo'),
(7, 'ahmet', 'selim', 'sezer', 'Erkek', '01011984', 'ÅŸemsettin', '7', '7.03.2020', '16:30', 'khkk', 'jnbjkbnkjkÄŸÄŸ', '9786754456578', '9876757890', 'Ã¶ÄŸretmen', 'emailqyahnjkk'),
(8, 'kadir', 'dursun', '23233092598', 'Erkek', '01011984', 'akÃ¼', '8', '14.03.2020', '10:40', 'osman', 'dursun', '0323023823928', '2*329320029029320', 'usta', 'ali@yahoo');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bursluluk`
--
ALTER TABLE `bursluluk`
  ADD PRIMARY KEY (`ID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bursluluk`
--
ALTER TABLE `bursluluk`
  MODIFY `ID` bigint(50) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
