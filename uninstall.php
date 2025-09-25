<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$pdo = FreePBX::Database();
$pdo->query("DROP TABLE IF EXISTS msteams_trunks;");
out("msteams: table removed");
