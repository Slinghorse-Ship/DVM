<?php declare(strict_types=1);
require __DIR__.'/../src/Web/View.php';
require __DIR__.'/../../local-processwire/BridgeProtocol.php';
require __DIR__.'/../../processwire/site/src/EditorialIntegration.php';
$checks=0;function periodViewCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function periodView(array $changes=[],bool $allowed=true):array{
 $native=['mail_allowed'=>$allowed,'counts'=>['all'=>40],'automation'=>array_replace(['period'=>'month-2026-11','period_label'=>'November 2026','send_label'=>'01.11.2026 um 10:00 Uhr','prepare_label'=>'25.10.2026 um 10:00 Uhr','period_paused'=>false,'period_version'=>hash('sha256','month-2026-11:0'),'campaign'=>null,'enabled'=>true,'globally_paused'=>false],$changes)];$calls=[];
 $simple=static function($op,$label,$fields=[],$version='')use(&$calls){$calls[]=[$op,$fields,$version];echo '<button>'.e($label).'</button>';};$link=static fn($data)=>'?item='.$data['item'];ob_start();require __DIR__.'/../views/Website/website-newsletter-status.php';return [ob_get_clean(),$calls];
}
[$html,$calls]=periodView();periodViewCheck(str_contains($html,'Diese Ausgabe aussetzen')&&str_contains($html,'November 2026'),'period visible without draft');periodViewCheck($calls[0]===['newsletter/period-pause',['period'=>'month-2026-11','paused'=>'1'],hash('sha256','month-2026-11:0')],'exact period and version posted');periodViewCheck(str_contains($html,'Alle automatischen Ausgaben stoppen'),'separate global stop');
$bridge=\Kulturhus\LocalWebsite\BridgeProtocol::validate(['action'=>'editorial/write','access'=>'editor','operation'=>$calls[0][0],'data'=>$calls[0][1],'version'=>$calls[0][2],'request_key'=>str_repeat('a',32)]);periodViewCheck($bridge['version']===$calls[0][2],'version accepted by real bridge contract');periodViewCheck(\Kulturhus\EditorialIntegration::validateData($calls[0][0],$calls[0][1])===$calls[0][1],'operation accepted by editorial whitelist');
[$html,$calls]=periodView(['period_paused'=>true,'period_version'=>hash('sha256','month-2026-11:5')]);periodViewCheck(str_contains($html,'Diese Ausgabe wird ausgesetzt.')&&str_contains($html,'Spätere Ausgaben bleiben unverändert.'),'clear skipped status');periodViewCheck($calls[0]===['newsletter/period-pause',['period'=>'month-2026-11','paused'=>'0'],hash('sha256','month-2026-11:5')],'resume exact skipped issue');
[$html,$calls]=periodView(['enabled'=>false,'globally_paused'=>true]);periodViewCheck(str_contains($html,'Alle automatischen Ausgaben sind gestoppt.')&&str_contains($html,'Einzeln ausgesetzte Ausgaben bleiben ausgesetzt.'),'global and individual separated');
foreach(['draft','queued','sending'] as $status){[$html,$calls]=periodView(['campaign'=>['id'=>9,'subject'=>'Test <img src=x>','status'=>$status]]);periodViewCheck($calls[0][0]==='newsletter/period-pause','pause available for '.$status);periodViewCheck(!str_contains($html,'<img'),'escaped subject '.$status);}
[$html,$calls]=periodView(['campaign'=>['id'=>9,'subject'=>'Test','status'=>'sent']]);periodViewCheck(!str_contains($html,'Diese Ausgabe aussetzen'),'no pause for completed issue');
[$html,$calls]=periodView([],false);periodViewCheck($calls===[],'no transport controls in disabled local demo');
echo "$checks newsletter period UI checks passed; no database or mail\n";
