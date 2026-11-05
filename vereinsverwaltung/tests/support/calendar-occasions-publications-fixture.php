<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
umask(0077);
require dirname(__DIR__,2).'/src/bootstrap.php';require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Communication\Workspace;
use Kulturhus\Verein\Registry;
if((TestSupport::config()['label']??'')!=='integrated-manager')throw new RuntimeException('Own integrated synthetic fixture required.');
$db=TestSupport::db();$registry=new Registry($db,'manager-admin');
$directory=dirname(__DIR__,3).'/.local/browser-tests';if(!is_dir($directory))mkdir($directory,0700,true);
$path=$directory.'/calendar-occasions-publications.json';
if(is_file($path)){echo "Existing original occasions/publications fixture.\n";exit();}
$guard=static function():void{if(PHP_SAPI!=='cli')throw new DomainException('Explicit synthetic fixture only.');};
$access=(new ManagerBridge($db,'manager-admin','native',$guard))->access();
$day=new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin'));$date=$day->format('Y-m-d');
$find=static function(string $table,string $field,string $value)use($db):?string{
    if(!in_array($table,['contacts','appointments','khn_social_items','khn_newsletter_items'],true)||!in_array($field,['name','title','payload'],true))throw new RuntimeException('Invalid fixture lookup.');
    $club=$table==='contacts'||$table==='appointments'?'manager-demo':ManagerBridge::clubKey('manager-demo');
    $column=$field==='payload'?"JSON_UNQUOTE(JSON_EXTRACT(payload,'$.title'))":$field;
    $q=$db->prepare("SELECT id FROM $table WHERE club_id=? AND $column=?");$q->execute([$club,$value]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
    if(count($ids)>1)throw new RuntimeException('Duplicate synthetic fixture originals; inspect before modifying.');return $ids[0]??null;
};
$birthday=$find('contacts','name','Kalender-Geburtstagsprofil')??$registry->saveContact(null,0,'person','Kalender-Geburtstagsprofil','','',['birthday'=>'2000-'.$day->format('m-d')]);
$data=['title'=>'Kalender-Interne Probe','start'=>$date.'T13:00','end'=>$date.'T14:00','activity_kind'=>'event'];
$internal=$find('appointments','title',$data['title'])??$registry->saveAppointment(null,0,$data,[]);
$external=$find('appointments','title','Kalender-Externes Vereinsfest')??$registry->saveAppointment(null,0,array_replace($data,['title'=>'Kalender-Externes Vereinsfest','activity_kind'=>'external']),[]);
$scheduled=[];
foreach(['social'=>'Kalender-Social-Beitrag','newsletter'=>'Kalender-Newsletter'] as $module=>$title){
    $work=new Workspace($db,$access,$module);$id=$find('khn_'.$module.'_items','payload',$title);
    $item=$id?$work->get($id):$work->save(['title'=>$title,'body'=>'Eigene synthetische Planung ohne Versand.']);
    if($item['status']==='draft')$item=$work->approve($item['id'],$item['version']);
    if($item['status']==='approved')$item=$work->schedule($item['id'],$day->setTime(15,0)->format('Y-m-d\TH:i:sP'),$item['version']);
    if($item['status']!=='scheduled')throw new RuntimeException('Existing synthetic publication changed.');
    $scheduled[$module]=$item['id'];
}
$pages=new \Kulturhus\Native\Cms\Pages($db,$access);
$published=$pages->save(['title'=>'Kalender-Öffentliches Konzert','slug'=>'termine/kalender-konzert-'.substr(bin2hex(random_bytes(8)),0,8),'template'=>'event','body'=>'Eigene synthetische öffentliche Veranstaltung.',
    'event_start'=>$day->setTime(18,0)->format('Y-m-d\TH:i'),'event_end'=>$day->setTime(19,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),'event_timezone'=>'Europe/Berlin','event_location'=>'Synthetische Bühne']);
$pages->publish($published['id'],$published['version']);
file_put_contents($path,json_encode(['date'=>$date,'birthday'=>$birthday,'internal'=>$internal,'external'=>$external,'published'=>'website:'.$published['id']]+$scheduled,JSON_THROW_ON_ERROR));
echo "Original birthday, events, social post and newsletter fixture ready; no live jobs.\n";
