<?php declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('zend.exception_ignore_args','1');umask(0077);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\Database;
use Kulturhus\Native\Maintenance\LetterOutboxRepair;
$options=getopt('',['db:','actor:','plan','apply:','status:','help'],$next);
$help="Aufruf: php repair-letter-outbox.php --db=MYSQL_DSN --actor=ADMIN [--plan | --apply=PRIVATE_PLAN_DATEI | --status=PRIVATE_PLAN_DATEI]\nOhne Aktion: geprüfte Kandidaten anzeigen. --plan speichert einen neuen privaten Vorschlag; --apply führt nur diesen gebundenen Vorschlag aus oder nimmt ihn wieder auf.\nDatenbankzugang über VEREIN_DATABASE_USER/PASSWORD; private Nachweise unter VEREIN_PRIVATE_ROOT.\n";
if(isset($options['help'])){echo $help;exit(0);}
$dsn=$options['db']??getenv('VEREIN_DATABASE');$actor=$options['actor']??'';
if($next<count($argv)||!is_string($dsn)||!str_starts_with(strtolower($dsn),'mysql:')||!is_string($actor)||trim($actor)===''||strlen($actor)>128||count(array_intersect(['plan','apply','status'],array_keys($options)))>1){fwrite(STDERR,$help);exit(2);}
try{
 $db=Database::connect($dsn);$service=new LetterOutboxRepair($db,$actor,$dsn,(string)getenv('VEREIN_PRIVATE_ROOT'));
 if(isset($options['plan']))$result=$service->prepare();
 elseif(isset($options['apply']))$result=$service->apply($options['apply']);
 elseif(isset($options['status']))$result=$service->inspect($options['status']);
 else{$basis=$service->preview();$result=['status'=>'preview','selected'=>$basis['selected'],'remaining'=>$basis['remaining'],'documents'=>array_map(static fn($row)=>['id'=>$row['id'],'version'=>$row['snapshot']['document']['version'],'title'=>$row['snapshot']['document']['title'],'date'=>$row['snapshot']['document']['document_date'],'target'=>$row['target_path'],'original_files_sha256'=>$row['snapshot']['files_sha256']],$basis['documents'])];}
 echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(DomainException|InvalidArgumentException $error){fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
catch(Throwable){fwrite(STDERR,"Wartungszugriff fehlgeschlagen. Gespeicherten Vorgangsstand prüfen; keine ungeprüfte Wiederholung ausführen.\n");exit(1);}
