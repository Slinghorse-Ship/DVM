<?php declare(strict_types=1);
require __DIR__.'/../src/Web/View.php';
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function renderProgress(array $query):string{
    $_GET=$query;$groups=require __DIR__.'/../src/Web/Navigation.php';
    ob_start();try{require __DIR__.'/../views/Shell/progress.php';return ob_get_clean();}
    catch(Throwable $e){ob_end_clean();throw $e;}
}
$all=renderProgress([]);
check(str_contains($all,'Mit offener Arbeit'),'open-work filter offered');
$open=renderProgress(['progress_filter'=>'open']);
check(str_contains($open,'Kontakte →')&&!str_contains($open,'Material →'),'partial functions remain in open work; completed material excluded');
$missing=renderProgress(['progress_filter'=>'missing']);
check(str_contains($missing,'Lastschriften →')&&!str_contains($missing,'Chat →')&&!str_contains($missing,'Kontakte →'),'unimplemented filter stays distinct');
$search=renderProgress(['q'=>'BEITRAGSABRECHNUNG']);
check(str_contains($search,'Beitragsabrechnung →'),'Unicode case-insensitive search');
$none=renderProgress(['q'=>'<script>not-a-section</script>']);
check(str_contains($none,'Keine Bereiche')&&!str_contains($none,'<script>'),'empty search has feedback and escapes input');
$canFinance=true;$finance=new class{public function canWrite():bool{return true;}};
ob_start();require __DIR__.'/../views/Finance/Banking/bank-preview.php';$bank=ob_get_clean();
check(str_contains($bank,'Noch nicht verbunden')&&!str_contains($bank,'<form')&&!str_contains($bank,'Testabruf'),'bank view has actual unavailable state, no simulation controls');
echo "$checks progress and bank view checks passed\n";
