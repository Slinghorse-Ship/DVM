<?php declare(strict_types=1);
require __DIR__.'/../../processwire/site/src/EventSyncService.php';require __DIR__.'/../../processwire/site/src/MetaEventArchive.php';
if(getenv('KULTURHUS_LOCAL_DEMO')!=='1')throw new RuntimeException('Local test only');
$wire=(static function(){require '/var/www/html/index.php';return $wire;})();$wire->users->setCurrentUser($wire->users->get($wire->config->superUserPageID));$n=0;function check(bool $v,string $s):void{global $n;if(!$v)throw new RuntimeException($s);++$n;echo "PASS $s\n";}
$svc=new \Kulturhus\MetaEventArchive($wire);$marker=(string)random_int(100000000000,999999999999);$row=['id'=>$marker,'name'=>'Synthetic archive '.bin2hex(random_bytes(3)),'description'=>'<script>unsafe</script> Plain','start_time'=>'2025-10-26T19:00:00+0100','place'=>['name'=>'Venue']];$payload=['complete'=>true,'source'=>'Meta Graph API','fetched_at'=>gmdate('c'),'events'=>[$row]];
$normal=\Kulturhus\MetaEventArchive::normalize($payload);check($normal['events'][0]['end_at']===0,'missing end time stays unknown');
$wire->database->beginTransaction();try{
 $first=$svc->import($payload);check($first['report']['created']===1,'one real ProcessWire archive entry created');$id=$first['report']['items'][0]['id'];$p=$wire->pages->getFresh($id);check(!$p->isUnpublished(),'imported archive visible publicly');check(str_contains((string)$p->body,'&lt;script&gt;'),'description escaped');check($p->meta('kulturhus_meta_archive')['source']==='Meta Graph API','source and timestamps persisted');
 $p->of(false);$p->title='Editorial title';$p->body='Editorial text';$p->save();$payload['events'][0]['description']='Changed remote';$again=$svc->import($payload);$p=$wire->pages->getFresh($id);check($again['report']['created']===0&&$again['report']['existing']===1,'repeat import idempotent by Meta ID');check($p->title==='Editorial title'&&$p->body==='Editorial text','editorial changes preserved');
 (new \Kulturhus\EventSyncService($wire->pages))->sync(['events'=>[],'_complete'=>true]);$p=$wire->pages->getFresh($id);check(!$p->isUnpublished()&&$p->event_status==='ended','complete Eventbrite sync preserves Meta archive');
 // Past Eventbrite entries have the same protection; upcoming entries still update.
 $p->meta('kulturhus_meta_archive',[]);$p->external_id=$marker;$p->save();
 $source=['id'=>$marker,'name'=>['text'=>'Remote replacement'],'start'=>['utc'=>gmdate('c',time()+86400)],'end'=>['utc'=>gmdate('c',time()+90000)],'status'=>'live','description'=>['html'=>'Remote body']];
 $sync=new \Kulturhus\EventSyncService($wire->pages);$sync->sync(['events'=>[$source]]);$p=$wire->pages->getFresh($id);
 check($p->title==='Editorial title'&&$p->body==='Editorial text','past Eventbrite content is not overwritten even when remote date changes');
 $sync->sync(['events'=>[],'_complete'=>true]);$p=$wire->pages->getFresh($id);check(!$p->isUnpublished(),'missing past Eventbrite entry remains published');
 $p->of(false);$p->start_at=time()+86400;$p->end_at=time()+90000;$p->save();$sync->sync(['events'=>[$source]]);$p=$wire->pages->getFresh($id);
 check($p->title==='Remote replacement'&&str_contains((string)$p->body,'Remote body'),'upcoming Eventbrite content still updates');
 $payload['events'][0]['start_time']='2025-02-30T19:00:00+0100';$bad=\Kulturhus\MetaEventArchive::normalize($payload);check(count($bad['skipped'])===1,'invalid calendar date reported for review');
}finally{$wire->database->rollBack();}
check((int)$wire->database->query('SELECT COUNT(*) FROM pages WHERE id='.(int)$id)->fetchColumn()===0,'synthetic archive row rolled back in database');
$wire->pages->uncacheAll();
check(!$wire->pages->getFresh($id)->id,'synthetic archive changes rolled back');echo "$n archive checks passed\n";

