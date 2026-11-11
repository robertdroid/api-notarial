<?php
$tok = trim(file_get_contents('/tmp/tok.txt'));
function t(string $path, string $tok): float {
  $ctx = stream_context_create(['http'=>['method'=>'GET','ignore_errors'=>true,'header'=>"Authorization: Bearer $tok\r\n"]]);
  $s = microtime(true); file_get_contents('http://127.0.0.1/api/v3'.$path, false, $ctx); return (microtime(true)-$s)*1000;
}
foreach (['/health','/acts','/payment-clients','/subdivisions','/users','/users/2'] as $p) {
  t($p,$tok); $ds=[]; for($i=0;$i<40;$i++) $ds[]=t($p,$tok); sort($ds);
  printf("%-20s avg=%.1fms median=%.1fms p95=%.1fms\n",$p,array_sum($ds)/40,$ds[20],$ds[37]);
}
