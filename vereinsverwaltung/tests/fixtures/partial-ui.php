<?php declare(strict_types=1);
function input(array $a,string $k,string $default=''):string{return is_string($a[$k]??null)?$a[$k]:$default;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic-csrf');}
function url(array $v):string{return '?'.http_build_query($v);}
function euro(int $v):string{return number_format($v/100,2,',','.').' €';}
function partialRender(PDO $db,string $actor,int $year,string $review=''):string{
    $_GET=['item'=>'partial','year'=>(string)$year,'partial_review'=>$review];$_POST=[];$_SESSION=['actor'=>$actor];
    $finance=new \Kulturhus\Verein\Finance($db,$actor);
    ob_start();try{require __DIR__.'/../../views/Finance/Accounting/finance-book-values.php';return ob_get_clean();}
    catch(Throwable $e){ob_end_clean();throw $e;}
}
