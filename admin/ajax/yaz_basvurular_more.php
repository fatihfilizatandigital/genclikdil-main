<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, "utf8mb4");

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function render_row($r): string {
    $dogum = '';
    if (!empty($r['dogum_tarihi'])) {
        $ts = strtotime($r['dogum_tarihi']);
        if ($ts) $dogum = date('d.m.Y', $ts);
    }
    $kayit = '';
    if (!empty($r['created_at'])) {
        $ts = strtotime($r['created_at']);
        if ($ts) $kayit = date('d.m.Y H:i', $ts);
    }
    $id = (int)($r['id'] ?? 0);
    $veli = h($r['veli_ad_soyad'] ?? '');
    $ogr = h($r['ogrenci_ad_soyad'] ?? '');
    $tel1 = h($r['veli_tel1'] ?? '');
    $tel2 = h($r['veli_tel2'] ?? '');
    $tc = h($r['tc'] ?? '');
    $cinsiyet = h($r['cinsiyet'] ?? '');
    $okul = h($r['okul'] ?? '');
    $sinif = h($r['sinif'] ?? '');
    $dogum_attr = h($r['dogum_tarihi'] ?? '');
    $editBtn = '<button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#basvuruModal" onclick="openEdit(this)" '
        . 'data-id="'.$id.'" data-veli_ad_soyad="'.$veli.'" data-ogrenci_ad_soyad="'.$ogr.'" data-veli_tel1="'.h($r['veli_tel1'] ?? '').'" data-veli_tel2="'.h($r['veli_tel2'] ?? '').'" '
        . 'data-tc="'.h($r['tc'] ?? '').'" data-dogum_tarihi="'.$dogum_attr.'" data-cinsiyet="'.$cinsiyet.'" data-okul="'.$okul.'" data-sinif="'.h($r['sinif'] ?? '').'" title="Düzenle"><i class="fas fa-edit"></i> Düzenle</button>';
    $delBtn = '<button type="button" class="btn btn-outline-danger btn-sm yaz-basvuru-sil-btn" data-id="'.$id.'" title="Sil"><i class="fas fa-trash-alt"></i> Sil</button>';
    return '<tr>'
        . '<td>'.$id.'</td>'
        . '<td style="text-align:center;"><div class="btn-group btn-group-sm">'.$editBtn.$delBtn.'</div></td>'
        . '<td><strong>'.$veli.'</strong></td>'
        . '<td><strong>'.$ogr.'</strong></td>'
        . '<td>'.$tel1.'</td>'
        . '<td>'.$tel2.'</td>'
        . '<td>'.$tc.'</td>'
        . '<td>'.$dogum.'</td>'
        . '<td>'.$cinsiyet.'</td>'
        . '<td>'.$okul.'</td>'
        . '<td style="text-align:center;"><span class="badge-soft">'.$sinif.'</span></td>'
        . '<td>'.$kayit.'</td>'
        . '</tr>';
}

$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$q_veli = trim((string)($_GET['veli'] ?? ''));
$q_ogr = trim((string)($_GET['ogrenci'] ?? ''));
$q_tc = preg_replace('/\s+/', '', trim((string)($_GET['tc'] ?? '')));
$q_tel = preg_replace('/\s+/', '', trim((string)($_GET['tel'] ?? '')));

$where = [];
if ($q_veli !== '') {
    $v = mysqli_real_escape_string($conn, $q_veli);
    $where[] = "veli_ad_soyad LIKE '%$v%'";
}
if ($q_ogr !== '') {
    $o = mysqli_real_escape_string($conn, $q_ogr);
    $where[] = "ogrenci_ad_soyad LIKE '%$o%'";
}
if ($q_tc !== '') {
    $tcEsc = mysqli_real_escape_string($conn, $q_tc);
    $where[] = "REPLACE(REPLACE(tc,' ',''),'-','') LIKE '%$tcEsc%'";
}
if ($q_tel !== '') {
    $telEsc = mysqli_real_escape_string($conn, $q_tel);
    $where[] = "(REPLACE(REPLACE(veli_tel1,' ',''),'-','') LIKE '%$telEsc%' OR REPLACE(REPLACE(veli_tel2,' ',''),'-','') LIKE '%$telEsc%')";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";
$limit = 51;
$sqlList = "SELECT id, veli_ad_soyad, ogrenci_ad_soyad, veli_tel1, veli_tel2, tc, dogum_tarihi, cinsiyet, okul, sinif, created_at, updated_at
            FROM yaz_kampanya_basvurular
            $whereSql
            ORDER BY id DESC
            LIMIT $limit OFFSET $offset";

$rows = [];
$result = mysqli_query($conn, $sqlList);
if ($result) {
    while ($r = mysqli_fetch_assoc($result)) $rows[] = $r;
}

$has_more = count($rows) > 50;
$take = $has_more ? 50 : count($rows);
$rows = array_slice($rows, 0, $take);
$next_offset = $offset + $take;

$rows_html = '';
foreach ($rows as $r) {
    $rows_html .= render_row($r);
}

echo json_encode([
    'rows_html' => $rows_html,
    'has_more' => $has_more,
    'next_offset' => $next_offset,
], JSON_UNESCAPED_UNICODE);
