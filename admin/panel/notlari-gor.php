<?php
require_once __DIR__ . '/../auth.php';
header('Location: sonuclar.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit;
