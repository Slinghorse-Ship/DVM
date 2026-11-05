<?php declare(strict_types=1);
/** Explicit operator CLI. Never migrates, reads mail, recognises files or creates financial postings. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Inventory/InventoryInvoiceIntegration.php';
$options=getopt('',['database:','actor:','plan:','apply','hash:','request-key:']);
try{
 foreach(['database','actor','plan'] as $key)if(!is_string($options[$key]??null)||$options[$key]==='')throw new DomainException('Erforderlich: --database <MySQL-DSN> --actor --plan. Zugangsdaten über VEREIN_DATABASE_USER/PASSWORD. Ohne --apply wird nur eine Vorschau erstellt.');
 if(!str_starts_with(strtolower($options['database']),'mysql:'))throw new DomainException('--database muss eine bestehende MySQL-Datenbank bezeichnen; keine Dateipfade.');
 if(!is_file($options['plan'])||filesize($options['plan'])>1000000)throw new DomainException('Begrenzte private Plandatei erforderlich.');
 $plan=json_decode(file_get_contents($options['plan']),true,64,JSON_THROW_ON_ERROR);if(!is_array($plan))throw new DomainException('Plandatei prüfen.');
 $db=\Kulturhus\Verein\Database::connect($options['database']);
 if(!array_key_exists('apply',$options))$db->exec('SET SESSION TRANSACTION READ ONLY');
 $service=new \Kulturhus\Verein\InventoryInvoiceIntegration($db,$options['actor']);
 if(array_key_exists('apply',$options))$result=$service->apply($plan,(string)($options['hash']??''),(string)($options['request-key']??''));
 else $result=$service->preview($plan);
 echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){fwrite(STDERR,($e instanceof DomainException?$e->getMessage():'Übernahme fehlgeschlagen. Privaten Plan und Laufzeit prüfen.')."\n");exit(1);}
