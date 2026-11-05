<?php declare(strict_types=1);
require __DIR__.'/../src/Web/View.php';
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";};
$render=static function(array $state,bool $central=true):string{$pwMode=$central;$pwIdentity=['device_session'=>$state];ob_start();require __DIR__.'/../views/Auth/session-device-settings.php';return ob_get_clean();};
foreach(['day'=>'24 Stunden','month'=>'30 Tage','logout'=>'Bis ich mich abmelde'] as $mode=>$label){
 $html=$render(['mode'=>$mode,'csrf_name'=>'TOKEN_TEST','csrf_value'=>'synthetic-token','saved'=>true]);
 $check(str_contains($html,'value="'.$mode.'" selected'),"current device mode selected: $mode");
 $check(str_contains($html,'role="status"')&&str_contains($html,'gespeichert: '.$label),"confirmed native success shown: $mode");
 $check(substr_count($html,'<form ')===1&&str_contains($html,'action="/login/"')&&str_contains($html,'name="TOKEN_TEST" value="synthetic-token"'),"native session endpoint and CSRF used: $mode");
}
$plain=$render(['mode'=>'month','csrf_name'=>'TOKEN_TEST','csrf_value'=>'synthetic-token']);
$check(!str_contains($plain,'role="status"'),'plain reload never invents a saved notification');
$check(!str_contains($plain,'name="user_id"')&&!str_contains($plain,'name="actor"'),'form cannot choose another member');
$check(!str_contains($render([]),'<form '),'missing native session metadata cannot create a save form');
$check(!str_contains($render(['csrf_name'=>'x','csrf_value'=>'x'],false),'<form '),'synthetic manager does not pretend to change native authentication');
$escaped=$render(['mode'=>'month','csrf_name'=>'"><script>','csrf_value'=>'"<script>']);
$check(!str_contains($escaped,'<script>'),'native token fields are HTML escaped');
echo "$checks device session view checks passed\n";
