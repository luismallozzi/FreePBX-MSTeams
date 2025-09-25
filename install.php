<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$pdo = FreePBX::Database();
$sql = "CREATE TABLE IF NOT EXISTS msteams_trunks (
  trunkid INT PRIMARY KEY,
  msaddr  VARCHAR(255) NOT NULL,
  base_transport VARCHAR(128) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$pdo->query($sql);
out("msteams: msteams_trunks table created/ok");

// Compile i18n catalogs if msgfmt is available
$i18nBase = __DIR__ . '/i18n';
$languages = ['en_US','pt_BR'];
foreach ($languages as $lang) {
  $po = $i18nBase . "/$lang/LC_MESSAGES/msteams.po";
  $mo = $i18nBase . "/$lang/LC_MESSAGES/msteams.mo";
  if (is_file($po)) {
    if (!is_dir(dirname($mo))) { @mkdir(dirname($mo), 0775, true); }
    $cmd = 'msgfmt -o ' . escapeshellarg($mo) . ' ' . escapeshellarg($po) . ' 2>&1';
    $output = [];
    $ret = 0;
    @exec($cmd, $output, $ret);
    if ($ret === 0 && is_file($mo)) {
      out("msteams: translations compiled for %s");
    } else {
      out("msteams: msgfmt missing or compilation failed for %s (ignoring)");
    }
  }
}
