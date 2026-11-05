<?php declare(strict_types=1);
require __DIR__.'/../src/Finance/Accounting/FinanceNavigation.php';
use Kulturhus\Verein\FinanceNavigation;
$n=0;function check($yes,$label){global $n;if(!$yes)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
$groups=FinanceNavigation::groups();check(count($groups)===5,'five main finance entrances');
foreach($groups as $group){check(FinanceNavigation::active($group['route'])===$group['id'],'group entry '.$group['id']);foreach($group['children'] as $child)check(FinanceNavigation::active($child['route'])===$group['id'],'direct route '.$child['label']);}
check(FinanceNavigation::active(['view'=>'finance'])==='bank','finance default');check(FinanceNavigation::active(['view'=>'donations'])==='receipts','donation default');foreach(['money','goods'] as $tab)check(FinanceNavigation::active(['view'=>'finance','tab'=>$tab])==='receipts','legacy finance donation route');check(FinanceNavigation::active(['view'=>'settings','tab'=>''])===null,'general settings not tax');check(FinanceNavigation::active(['view'=>'portal'])===null,'unrelated route no active group');
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}function input($a,$k,$d=''){return $a[$k]??$d;}function url($r){return '?'.http_build_query($r);}
foreach([['finance','tax',''],['finance','contribution-mails',''],['settings','','tax'],['donations','goods','']] as [$view,$tab,$section]){$_GET=['section'=>$section];ob_start();require __DIR__.'/../views/Finance/Accounting/finance-navigation.php';$html=ob_get_clean();check(substr_count($html,'aria-current="true"')===1,'one active main group '.$view.'/'.$tab);check(substr_count($html,'aria-current="page"')===1,'one active child '.$view.'/'.$tab);}
require __DIR__.'/../src/Web/ManagerNavigation.php';foreach(['rates','services','numbers','sepa']as$settingsTab)check(\Kulturhus\Verein\ManagerNavigation::key(['view'=>'settings','tab'=>$settingsTab])==='settings:rates','settings retain their own menu instead of finance: '.$settingsTab);
echo "$n finance navigation checks passed; routes only, no writes\n";
