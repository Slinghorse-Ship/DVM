<?php declare(strict_types=1);
/** Actual owned imported website originals and independent manager calendar records. */
function ownEventSourceBrowser(PDO $db):array
{
    require_once __DIR__.'/social-human-browser-fixture.php';$browser=ownSocialHumanBrowser($db);$f=$browser['input'];
    try{
    $bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'own-human-browser-admin','native',static fn()=>null);$access=$bridge->access();$pages=new \Kulturhus\Native\Cms\Pages($db,$access);
    $eventbrite=new \Kulturhus\Native\Events\Eventbrite($db,$access);$reads=0;
    $normalized=$eventbrite->fetch('555',static function(string $path)use(&$reads):array{if($path!=='/v3/organizations/555/events/?status=all&expand=venue,logo&page=1')throw new RuntimeException('Own Eventbrite read path required.');$reads++;return['pagination'=>['page_number'=>1,'page_count'=>1],'events'=>[['id'=>'88001','status'=>'live','name'=>['text'=>'Own Eventbrite %_ ä🙂 <literal>'],'description'=>['text'=>'Own imported Eventbrite body Senden'],'start'=>['utc'=>'2050-06-10T16:00:00Z','timezone'=>'Europe/Berlin'],'end'=>['utc'=>'2050-06-10T17:00:00Z'],'venue'=>['name'=>'Own imported Eventbrite venue'],'url'=>'https://www.eventbrite.de/e/88001']]];});
    $eventbritePage=$eventbrite->import($normalized)[0];$eventbritePage=$pages->get($eventbritePage['id']);$eventbritePage=$pages->publish($eventbritePage['id'],$eventbritePage['version']);
    $workflow=new \Kulturhus\Native\Events\Workflow($db,$access);$workflow->save(['source'=>'facebook','tickets'=>'none','payment'=>'none','automatic_publication'=>false],0);
    $box=\Kulturhus\Native\Shared\SecretBox::fromFile($f['root'].'/private.key');
    $facebook=new \Kulturhus\Native\Events\Facebook($db,$access,$box,static function(string $method,string $path,array $headers,?array $body)use(&$reads):array{if($method!=='GET'||!str_starts_with($path,'/v25.0/123/events?')||$headers!==['Authorization: Bearer own-human-browser-only']||$body!==null)throw new RuntimeException('Own Meta event read required.');$reads++;return['status'=>200,'body'=>['data'=>[['id'=>'88002','name'=>'Own Facebook %_ ä🙂 <literal>','description'=>'Own imported Facebook body Senden','start_time'=>'2050-06-11T18:00:00+02:00','end_time'=>'2050-06-11T19:00:00+02:00','place'=>['name'=>'Own imported Facebook venue']]]]];});
    $facebookPage=$facebook->sync()[0];$facebookPage=$pages->get($facebookPage['id']);$facebookPage=$pages->publish($facebookPage['id'],$facebookPage['version']);
    $own=[];for($i=0;$i<6;$i++){$p=$pages->save(['slug'=>'veranstaltungen/own-event-picker-'.$i,'title'=>'Own picker event '.$i.' ä🙂 <literal>','template'=>'event','body'=>'<p>Own picker literal body</p>','event_start'=>'2050-06-12T18:00','event_end'=>'2050-06-12T17:00:00Z','event_timezone'=>'Europe/Berlin','event_location'=>'Own source venue']);$p=$pages->publish($p['id'],$p['version']);$own[]=$p;}
    $past=$pages->save(['slug'=>'veranstaltungen/own-event-picker-past','title'=>'Own past event','template'=>'event','event_start'=>'2000-06-12T18:00']);$past=$pages->publish($past['id'],$past['version']);
    $registry=new \Kulturhus\Verein\Registry($db,'own-human-browser-admin');$person=$registry->saveContact(null,0,'person','Own private attendee','','');$calendarId=$registry->saveAppointment(null,0,['title'=>'Own calendar %_ ä🙂 <literal>','description'=>'Own original calendar body Senden','start'=>'2050-06-13T18:00','end'=>'2050-06-13T19:00','location'=>'Own calendar venue','activity_kind'=>'event'],[$person]);
    $f['events']=['eventbrite'=>$eventbritePage['id'],'facebook'=>$facebookPage['id'],'website'=>$own[0]['id'],'calendar'=>$calendarId,'calendar_original'=>$registry->appointment($calendarId),'website_total'=>8,'import_adapter_reads'=>$reads];
    $browser['input']=$f;$file=$f['root'].'/browser.json';file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);return$browser;
    }catch(Throwable $error){proc_terminate($browser['process']);proc_close($browser['process']);fclose($browser['log']);throw$error;}
}
