<?php declare(strict_types=1);
/** Isolated CI only: emulate a process lost after the provider accepted an event. */
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{SecretBox,Db};
use Kulturhus\Native\Integration\StadtLandFunk\{Connection,Login,Http};
use Kulturhus\Native\Communication\PersonalPublication;
use Kulturhus\Native\Delivery\Outbox;
if(getenv('KH_NATIVE_CI')!=='1')exit(1);
$db=TestSupport::db();$box=new SecretBox(str_repeat('p',32));$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/personal-publication-runtime';
$id=$argv[1]??'';if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))exit(1);
$http=static function($method,$url,$headers,$body)use($id,$box,$root):array{
    if($method!=='POST'||$url!==Http::API.'/grapevine/event/happeningCreateRequest')throw new RuntimeException('Unexpected synthetic write');
    $second=TestSupport::db();$job=Db::run($second,"SELECT * FROM native_delivery_jobs WHERE id=? AND status='processing'",[$id])->fetch();
    if(!$job||!str_starts_with($job['external_id']??'','sf:'))throw new RuntimeException('Committed original photo and worker claim missing');
    $payload=$box->open('job:'.$job['club_id'].':'.$id,$job['ciphertext']);$state=explode(':',$job['external_id']);
    if(json_decode($body,true,64,JSON_THROW_ON_ERROR)!==$payload['event']+['temporaryMediaItemIds'=>[$state[1]]])throw new RuntimeException('Original frame changed');
    $file=fopen($root.'/crash-proof.json','x');if(!$file||!chmod($root.'/crash-proof.json',0600))throw new RuntimeException('Private proof unavailable');
    fwrite($file,json_encode(['event'=>$payload['event'],'media'=>$state[2]],JSON_THROW_ON_ERROR));fclose($file);
    exit(77);
};
$publisher=new PersonalPublication($db,$box,new Connection($db,$box,new Login($http)));
(new Outbox($db,$box))->run(static fn($c,$p,$kind,$job)=>$publisher->send($c,$p,$job,$root.'/media'),1,['stadtlandfunk']);
exit(2);
