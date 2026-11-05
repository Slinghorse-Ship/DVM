<?php declare(strict_types=1);
require __DIR__.'/../src/Web/PeopleNavigation.php';
use Kulturhus\Verein\PeopleNavigation;
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
foreach(['members','contacts','occasions'] as $group){foreach(PeopleNavigation::tabs($group) as $i=>$item){check(PeopleNavigation::groupFor($item['route'])===$group,'alias '.$item['label']);check(PeopleNavigation::activeIndex($item['route'])===$i,'active '.$item['label']);}}
check(PeopleNavigation::groupFor(['view'=>'portal','tab'=>'admissions'])==='applications','admissions alias');
check(PeopleNavigation::groupFor(['view'=>'members','new'=>1,'create_as'=>'contact'])==='contacts','new contact alias');
check(PeopleNavigation::activeIndex(['view'=>'members','list'=>'contacts','saved'=>'synthetic'])===2,'saved contact list');
check(PeopleNavigation::groupFor(['view'=>'website','tab'=>'events','period'=>'archive'])==='','website archive unaffected');
check(PeopleNavigation::activeIndex(['view'=>'members','list'=>'all'])===null,'legacy mixed list not labelled current');
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}function url($r){return '?'.http_build_query($r);}
foreach([true,false] as $write){$workspaceAccess=new class($write){public function __construct(private bool $write){}public function allows(array $r):bool{return ($r['tab']??'')!=='archive'||$this->write;}};$view='members';$tab='';$_GET=['view'=>'members','list'=>'current'];ob_start();require __DIR__.'/../views/People/Members/people-navigation.php';$html=ob_get_clean();check(substr_count($html,'aria-current="page"')===1,'single active list '.$write);check(str_contains($html,'Geschütztes Archiv')===$write,'archive visibility '.$write);check(!str_contains($html,'Favoriten'),'member tabs do not mix contacts');}
foreach(['inventory','finance','loans'] as $role){$workspaceAccess=new class($role){public function __construct(private string $role){}public function allows(array $r):bool{return match($this->role){'finance'=>($r['tab']??'')==='assets','loans'=>($r['tab']??'')==='loans',default=>($r['tab']??'')!=='assets'};}};$view='inventory';$tab=match($role){'finance'=>'assets','loans'=>'loans',default=>'where'};ob_start();require __DIR__.'/../views/Inventory/inventory-navigation.php';$html=ob_get_clean();check(substr_count($html,'aria-current="page"')===($role==='finance'?0:1),'inventory active tab follows its own module '.$role);check(!str_contains($html,'Vereinsvermögen'),'assets are reached through finance, not inventory tabs '.$role);check(str_contains($html,'Materialübersicht')===($role==='inventory'),'material permission '.$role);}
echo "$n navigation checks passed; synthetic route and rendering tests only\n";
