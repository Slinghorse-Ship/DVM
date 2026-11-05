<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
function input(array $a,string $k,string $default=''):string{return is_string($a[$k]??null)?$a[$k]:$default;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return '';}
function icon(string $name):string{return '';}
function url(array $args):string{return '?'.http_build_query($args);}
$finance=new class{public function entries(array $f):array{return [['id'=>'synthetic-entry','display_number'=>'TEST-1','payment_state'=>'open','remaining_cents'=>100]];}public function positions(string $t,string $id):array{return [['sphere'=>'','account_code'=>'']];}public function accounts():array{return [];}};
$_SESSION=['actor'=>'synthetic'];$_POST=[];$_GET=['check_export'=>'1','from'=>'2026-08-01','to'=>'2026-08-31'];
ob_start();require __DIR__.'/../views/Finance/Accounting/finance-export.php';$html=ob_get_clean();$n=0;
function check(bool $v,string $message):void{global $n;if(!$v)throw new RuntimeException($message);++$n;}
check(str_contains($html,'Vereinsbereich (Sphäre) und Steuerkonto fehlen'),'Missing sphere/account gets understandable action');
check(str_contains($html,'entry=synthetic-entry&amp;edit_positions=1'),'Correction link points to same entry positions');
check(str_contains($html,'Noch nicht vollständig einem Kontoumsatz zugeordnet'),'Open payment is explicitly unresolved');
check(str_contains($html,'value="2026-08-01"')&&str_contains($html,'value="2026-08-31"'),'Selected period kept for download');
check(!str_contains($html,'Technische Prüfung bestanden'),'No unperformed DATEV success');
check(!str_contains($html,'value="datev-download"'),'No DATEV download before valid preview');
echo "$n export guidance checks passed\n";
