<?php declare(strict_types=1);
/** Two independent owned MySQL acceptors, released together by their private parent fixture. */
if(PHP_SAPI!=='cli'||getenv('VEREIN_TEST_MYSQL')!=='isolated-regression-only')throw new RuntimeException('Owned reminder worker required.');
require dirname(__DIR__,3).'/vendor/autoload.php';require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require dirname(__DIR__).'/mysql-bootstrap.php';
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$dsn=$input['dsn']??'';$barrier=$input['barrier']??'';
if(!is_string($dsn)||!str_contains($dsn,'port=13372;')||!preg_match('/dbname=kh_test_[a-z0-9]+(?:;|\z)/D',$dsn)||!is_string($barrier)||!str_starts_with($barrier,sys_get_temp_dir().'/own-reminder-race-')||is_link(dirname($barrier)))throw new RuntimeException('Owned reminder race source required.');
$db=\Kulturhus\Verein\Tests\MySqlFixture::existing($dsn);$master=base64_decode($input['master'],true);if(!is_string($master)||strlen($master)!==32)throw new RuntimeException('Owned reminder race key required.');
echo "READY\n";flush();$deadline=microtime(true)+20;while(!is_file($barrier)){if(microtime(true)>$deadline)throw new RuntimeException('Owned race barrier expired.');usleep(10000);}
try{
    $first=(new \Kulturhus\Verein\Finance\Invoices\Reminder\ReminderRequests($db,'own-reminder-finance',$master))->accept($input['input'],$input['key'],$input['hash'],true);
    echo json_encode(['status'=>'accepted','sha256'=>$first['sha256'],'document'=>$first['result']['document_id'],'delivery'=>$first['result']['delivery_id']],JSON_THROW_ON_ERROR)."\n";
}catch(DomainException $failure){echo json_encode(['status'=>$failure->getCode()],JSON_THROW_ON_ERROR)."\n";}
