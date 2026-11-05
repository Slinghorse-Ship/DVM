<?php declare(strict_types=1);
require __DIR__.'/../src/Finance/Balance/BalanceSourceRead.php';
use Kulturhus\Verein\BalanceSourceRead as Sources;
// Pure coordinator checks; no SQL, credentials, SDK or application writes.
$n=0;
function check(bool $ok):void{global $n;if(!$ok)throw new RuntimeException('Source read lifetime check failed '.$n);$n++;}
$calls=0;$version=1;
$read=static function()use(&$calls,&$version):array{$calls++;return ['version'=>$version];};
$get=static fn()=>Sources::once('catalog',$read);
$get();$get();check($calls===2);
Sources::run(static function()use($get,&$calls):array{
    $a=$get();$a['version']=999;check($get()['version']===1);check($calls===3);
    check(Sources::run($get)['version']===1);check($calls===3);
    return [];
});
$version=2;check(Sources::run($get)['version']===2);check($calls===4);
try{Sources::run(static function()use($get):array{$get();throw new DomainException('Synthetic rejected read');});}
catch(DomainException){check(true);}
$version=3;check(Sources::run($get)['version']===3);check($calls===6);
$failures=0;
Sources::run(static function()use(&$failures):array{
    for($i=0;$i<2;$i++)try{Sources::once('invalid',static function()use(&$failures):array{$failures++;throw new DomainException('Invalid source');});}catch(DomainException){}
    check($failures===2);
    return [];
});
$reads=0;
Sources::run(static function()use(&$reads):array{
    $read=static function()use(&$reads):array{$reads++;return [];};
    for($i=0;$i<6;$i++)Sources::once('catalog-'.$i,$read);
    Sources::once('catalog-0',$read);check($reads===6);
    Sources::once('catalog-5',$read);check($reads===7);
    return [];
});
$get();check($calls===7);
echo "$n pure source read checks passed; contexts end on return or exception\n";
