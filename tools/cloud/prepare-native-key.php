<?php declare(strict_types=1);
/** Owned empty development runtime only; never replaces keys or imports secrets. */
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/vereinsverwaltung/native/bootstrap.php';
$config=Kulturhus\Native\TestSupport::config();
if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='demo')throw new RuntimeException('Eigene synthetische Redaktion erforderlich.');
$path=dirname(__DIR__,2).'/.local/native-cms-integration/private.key';
if(is_file($path)){Kulturhus\Native\Shared\SecretBox::fromFile($path);echo "Vorhandener privater Entwicklungsschlüssel erhalten.\n";exit;}
if(is_link($path))throw new RuntimeException('Privater Schlüsselpfad darf kein Link sein.');
$db=Kulturhus\Native\TestSupport::db();
$columns=$db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('ciphertext','cipher','comments_cipher','consent_cipher') AND TABLE_NAME NOT LIKE 'native_auth_%'")->fetchAll(PDO::FETCH_ASSOC);
foreach($columns as$row){foreach($row as$value)if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$value))throw new RuntimeException('Privaten Speicher prüfen.');if((int)$db->query('SELECT COUNT(*) FROM `'.$row['TABLE_NAME'].'` WHERE `'.$row['COLUMN_NAME'].'` IS NOT NULL AND `'.$row['COLUMN_NAME']."`<>''")->fetchColumn()>0)throw new RuntimeException('Vorhandene private Daten benötigen ihren ursprünglichen Schlüssel.');}
$directory=dirname($path);if(is_link($directory)||(!is_dir($directory)&&!mkdir($directory,0700,true)))throw new RuntimeException('Privater Entwicklungsspeicher fehlt.');
$handle=fopen($path,'x');if(!$handle||!chmod($path,0600))throw new RuntimeException('Privater Entwicklungsschlüssel nicht anlegbar.');
try{if(fwrite($handle,random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Privater Entwicklungsschlüssel unvollständig.');}finally{fclose($handle);}
echo "Eigener privater Entwicklungsschlüssel angelegt; keine fremden Daten oder Tokens übernommen.\n";
