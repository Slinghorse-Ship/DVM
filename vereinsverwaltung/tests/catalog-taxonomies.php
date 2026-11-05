<?php declare(strict_types=1);
// Published sources only, no database or transport. Simulated source failures
// do not edit the real source files or metadata.
namespace Kulturhus\Verein {
    function is_file(string $path):bool{return ($GLOBALS['taxonomy_missing']??null)===$path?false:\is_file($path);}
    function hash(string $algorithm,string $data,bool $binary=false):string{return !empty($GLOBALS['taxonomy_changed'])?str_repeat('0',64):\hash($algorithm,$data,$binary);}
}
namespace {
    require __DIR__.'/../src/Finance/Tax/TaxonomySources.php';
    use Kulturhus\Verein\TaxonomySources;
    $n=0;
    function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);++$GLOBALS['n'];echo "PASS $label\n";}
    $rows=TaxonomySources::listing();
    check(array_keys($rows)===['6.7','6.8','6.9','6.10'],'all four official sources retained separately');
    foreach(['6.7'=>2024,'6.8'=>2025,'6.9'=>2026,'6.10'=>2027] as $version=>$year){
        check($rows[$version]['regular_start_year']===$year&&$rows[$version]['optional_start_year']===$year-1,'fiscal start years '.$version);
        check(str_starts_with($rows[$version]['source'],'https://www.esteuer.de/'),'official source '.$version);
    }
    check($rows['6.9']['local_validation_years']===[2025,2026],'existing 2025 and 2026 local validation stays on 6.9');
    foreach(['6.7','6.8','6.10'] as $version)check($rows[$version]['local_validation_years']===[],'source alone does not approve native output '.$version);
    foreach(['taxonomies.json','releases.json','taxonomy-6.7.json','taxonomy-6.8.json','taxonomy-6.9.json','taxonomy-6.10.json'] as $name){
        $GLOBALS['taxonomy_missing']=realpath(__DIR__.'/../src').'/../resources/ebilanz/'.$name;$denied=false;
        try{TaxonomySources::listing();}catch(DomainException){$denied=true;}finally{unset($GLOBALS['taxonomy_missing']);}
        check($denied,'missing source fails closed '.$name);
    }
    $GLOBALS['taxonomy_changed']=true;$denied=false;try{TaxonomySources::listing();}catch(DomainException){$denied=true;}finally{unset($GLOBALS['taxonomy_changed']);}
    check($denied,'changed source is not offered as checked');
    check(TaxonomySources::listing()===$rows,'failure injection preserves all originals');
    echo "$n official taxonomy source checks passed; no database, edits or transmission\n";
}
