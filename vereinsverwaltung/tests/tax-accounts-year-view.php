<?php declare(strict_types=1);
// Rendering only: no database, runtime configuration, mail or transport.
require __DIR__.'/../native/bootstrap.php';require __DIR__.'/../src/bootstrap.php';
function input(array $row,string $key,string $default=''):string{return (string)($row[$key]??$default);}
function e(mixed $text):string{return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');}
function hidden(string $name,mixed $value):string{return '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';}
function csrf():string{return hidden('csrf','synthetic-only');}
function url(array $params):string{return '?'.http_build_query($params);}
$count=0;
function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;}
$finance=new class{public function taxAccounts():array{return [];}public function canWrite():bool{return true;}};
$_GET=['view'=>'settings','tab'=>'tax-accounts'];$_POST=[];$canFinance=false;
ob_start();require __DIR__.'/../views/Finance/Tax/tax-accounts.php';$denied=ob_get_clean();
check(str_contains($denied,'Finanzberechtigung erforderlich.'),'finance permission remains required');
check(!str_contains($denied,'Jahresstände'),'no catalog view without permission');
$canFinance=true;
ob_start();require __DIR__.'/../views/Finance/Tax/tax-accounts.php';$html=ob_get_clean();
check(str_contains($html,'Jahresstände des Kontenplans'),'plain annual heading');
foreach([2025,2026] as $year)check(str_contains($html,'DATEV-Kontenplan '.$year),'actual source year '.$year);
check(str_contains($html,'ältere Belege'),'historical meaning explained');
foreach(['active_verified','tools/check-datev','Prüfsumme','Quelle &amp; Umfang'] as $internal)
    check(!str_contains($html,$internal),'no technical release details '.$internal);
check(str_contains($html,'save-tax-account'),'existing account editor retained');
check(str_contains($html,'rel="noreferrer"'),'source links retained safely');
echo "$count tax account year view checks passed\n";
