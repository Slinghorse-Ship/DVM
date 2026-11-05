<?php declare(strict_types=1);
require_once dirname(__DIR__).'/src/Finance/Assets/OriginalBasisReduction7g.php';
use Kulturhus\Verein\OriginalBasisReduction7g;
$count=0;$negative=0;
foreach(glob(__DIR__.'/fixtures/basis7g-r83/*.json') as $path){
 $f=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$v=$f['values'];OriginalBasisReduction7g::check($v);$count++;
 $mutations=[
  'missing scope'=>static function(&$q){unset($q['whole_club_scope']);},
  'period'=>static function(&$q){$q['period']=[];},
  'segment'=>static function(&$q){$q['club_scope']=[];},
  'different journal'=>static function(&$q){$q['basis_reduction_7g']['source']['journal_sha256']=str_repeat('0',64);},
  'claim limit'=>static function(&$q){$q['basis_reduction_7g']['source']['claimed_cents']=20000001;},
  'exhausted claim'=>static function(&$q){$q['basis_reduction_7g']['source']['available_cents']=1;},
  'original claim year'=>static function(&$q){$q['basis_reduction_7g']['source']['iab_year']=$q['year'];},
  'unconfirmed election'=>static function(&$q){$q['basis_reduction_7g']['source']['amount_reviewed']=false;},
  'book fingerprint'=>static function(&$q){$q['basis_reduction_7g']['book_item_sha256']=str_repeat('0',64);},
  'invented reduced basis'=>static function(&$q){$q['basis_reduction_7g']['tax_basis_cents']++;},
  'invented tax regular'=>static function(&$q){$q['basis_reduction_7g']['tax_regular_cents']++;},
  'invented tax closing'=>static function(&$q){$q['basis_reduction_7g']['tax_closing_cents']++;}
 ];
 foreach($mutations as $name=>$mutate){$bad=$v;$mutate($bad);$blocked=false;try{OriginalBasisReduction7g::check($bad);}catch(DomainException){$blocked=true;}if(!$blocked)throw new RuntimeException('Unbound original basis source accepted: '.$name);$negative++;}
}
if($count!==4||$negative!==48)throw new RuntimeException('Original fixture scope changed');
echo json_encode(['passed'=>true,'positive'=>$count,'negative'=>$negative],JSON_THROW_ON_ERROR);
