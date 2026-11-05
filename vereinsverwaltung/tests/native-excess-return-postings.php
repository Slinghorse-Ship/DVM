<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';require __DIR__.'/support/excess-return-postings.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry};
$db=TestSupport::db();SchemaRegistry::install($db);$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/excess-posting-private';mkdir($root,0700);mkdir($root.'/media',0700);putenv('VEREIN_PRIVATE_ROOT='.$root);putenv('KH_NATIVE_MANAGER_MEDIA_ROOT='.$root.'/media');$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";};$reject=static function(callable $attempt,string $label)use($check):void{try{$attempt();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException('Unexpectedly accepted '.$label);};
ownExcessPostings($db,$check,$reject);
echo json_encode(['suite'=>'native-excess-return-postings','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
