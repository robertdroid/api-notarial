<?php
$tok = trim(file_get_contents('/tmp/tok.txt'));
function t(string $m, string $path, ?string $body, string $tok): array {
  $ctx = stream_context_create(['http'=>['method'=>$m,'ignore_errors'=>true,'header'=>"Authorization: Bearer $tok\r\nContent-Type: application/json\r\n",'content'=>$body ?? '']]);
  $s = microtime(true); $r = file_get_contents('http://127.0.0.1/api/v3'.$path, false, $ctx); $d = (microtime(true)-$s)*1000;
  preg_match('/ (\d{3}) /', $http_response_header[0], $mm); return [$d, (int)$mm[1]];
}
function stats(string $label, array $ds, array $codes): void { sort($ds); $n=count($ds); printf("%-34s n=%d  avg=%.1fms  p95=%.1fms  max=%.1fms  codes=%s\n",$label,$n,array_sum($ds)/$n,$ds[(int)floor($n*0.95)-1]??$ds[$n-1],$ds[$n-1],implode(',',array_unique($codes))); }
t('GET','/users',null,$tok); // warm-up
foreach ([['GET list','GET','/users',null],['GET list ?status=1','GET','/users?status=1',null],['GET show','GET','/users/2',null],['PUT update (no pwd)','PUT','/users/13','{"firstName":"Maria Elena"}']] as [$l,$m,$p,$b]) {
  $ds=[];$cs=[]; for($i=0;$i<40;$i++){ [$d,$c]=t($m,$p,$b,$tok); $ds[]=$d; $cs[]=$c; } stats($l,$ds,$cs);
}
$ds=[];$cs=[]; for($i=0;$i<10;$i++){ [$d,$c]=t('POST','/users','{"username":"perf.u'.$i.'","password":"12345678","firstName":"P","paternalLastName":"U","access":["ESC"]}',$tok); $ds[]=$d; $cs[]=$c; } stats('POST create (bcrypt cost 10)',$ds,$cs);
$s=microtime(true); for($i=0;$i<10;$i++) password_hash('12345678',PASSWORD_BCRYPT,['cost'=>10]); printf("bcrypt cost10 alone: %.1fms avg\n",(microtime(true)-$s)*100);
