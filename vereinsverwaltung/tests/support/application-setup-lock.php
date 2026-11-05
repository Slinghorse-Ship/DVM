<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['owner']??'')!=='isolated-native-ci'||(TestSupport::config()['label']??'')!=='native-application-setup-interface')throw new RuntimeException('Owned application-setup fixture only');
$db=TestSupport::db();$db->beginTransaction();
try{$q=$db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT');$q->execute(['own-catalog-a']);if(!$q->fetchColumn())throw new RuntimeException('Owned intake club required');echo "LOCKED\n";flush();stream_set_timeout(STDIN,20);if(trim((string)fgets(STDIN))!=='release')throw new RuntimeException('Owned lock release required');}finally{if($db->inTransaction())$db->rollBack();}
echo "RELEASED\n";
