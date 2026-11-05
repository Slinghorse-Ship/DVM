<?php declare(strict_types=1);
require_once __DIR__.'/../src/Finance/Tax/TaxDeclarationGuide.php';
use Kulturhus\Verein\TaxDeclarationGuide as Guide;
$n=0;function check(bool $v,string $why):void{global $n;if(!$v)throw new RuntimeException($why);$n++;}
function bad(array $v):void{try{Guide::normalize($v);}catch(DomainException){check(true,'invalid input rejected');return;}throw new RuntimeException('Invalid input accepted');}
$rows=Guide::normalize([]);check(count($rows)===7,'seven groups');check(count(Guide::issues($rows))===7,'unknown remains open');check(array_unique(array_column($rows,'answer'))===['unknown'],'no default no');
$rows['reserves']=['answer'=>'yes','note'=>''];check(count(Guide::issues($rows))===7,'yes requires explanation');$rows['reserves']['note']='Synthetic decision and rollforward';check(count(Guide::issues($rows))===6,'explanation resolves only own issue');
check(Guide::normalize($rows)===$rows,'normalization replay stable');
foreach($rows as &$r)$r=['answer'=>'no','note'=>''];unset($r);check(count(Guide::issues($rows))===1,'purpose no still needs explanation');$rows['purpose_fulfilled']['note']='Synthetic explanation';check(Guide::issues($rows)===[],'complete preparation only');
bad(['unexpected'=>[]]);bad(['reserves'=>'yes']);bad(['reserves'=>['answer'=>['yes']]]);bad(['reserves'=>['answer'=>'maybe']]);bad(['reserves'=>['note'=>str_repeat('x',2001)]]);bad(['reserves'=>['note'=>"bad\0text"]]);
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$taxGuideInput=['reserves'=>['answer'=>'yes','note'=>'<script>synthetic</script>']];ob_start();require __DIR__.'/../views/Finance/Tax/tax-declaration-guide.php';$html=ob_get_clean();check(str_contains($html,'&lt;script&gt;synthetic&lt;/script&gt;')&&!str_contains($html,'<script>synthetic'),'notes escaped');check(substr_count($html,'<select name="tax_guide[')===7,'all groups render');check(str_contains($html,'amtliche Anleitung 2025'),'year boundary visible');check(!str_contains($html,'name="tax_guide[reserves][note]" required'),'incomplete draft may save');
echo "$n tax declaration guide checks passed; no database, network or mail\n";
