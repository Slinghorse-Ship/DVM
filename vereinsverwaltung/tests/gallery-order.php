<?php namespace ProcessWire;
if(getenv('KULTURHUS_LOCAL_DEMO')!=='1'||PHP_SAPI!=='cli')throw new \RuntimeException('Local only');
require '/var/www/html/index.php';$wire=wire();$wire->users->setCurrentUser($wire->users->get('name=admin'));require_once '/var/www/html/site/src/EditorialIntegration.php';
$service=new \Kulturhus\EditorialIntegration($wire);$ids=[];$n=0;
function check($ok,$label){global $n;if(!$ok)throw new \RuntimeException($label);echo "PASS $label\n";++$n;}
try{
 foreach(['A','B'] as $title){$r=$service->write('gallery/save',['id'=>'0','title'=>'Sortiertest '.$title,'summary'=>'','body'=>'','publish_at'=>'','published'=>'']);$ids[]=$r['id'];}
 $order=fn()=>array_map('intval',$wire->pages->get('template=gallery')->children('template=gallery-album,include=all,status<8192,sort=sort,sort=id')->explode('id'));
 $before=$order();check(array_search($ids[0],$before)<array_search($ids[1],$before),'initial native order');
 $version=$service->read('gallery',$ids[1])['item']['version'];$service->write('gallery/move',['id'=>(string)$ids[1],'direction'=>'up'],$version);$wire->pages->uncacheAll();$after=$order();check(array_search($ids[1],$after)<array_search($ids[0],$after),'move up changes ProcessWire order');
 try{$service->write('gallery/move',['id'=>(string)$ids[1],'direction'=>'up'],$version);throw new \RuntimeException('stale accepted');}catch(\DomainException){check(true,'stale sort rejected');}
 $version=$service->read('gallery',$ids[1])['item']['version'];$service->write('gallery/move',['id'=>(string)$ids[1],'direction'=>'down'],$version);$wire->pages->uncacheAll();$after=$order();check(array_search($ids[0],$after)<array_search($ids[1],$after),'move down restores relative order');
 check(str_contains(file_get_contents('/var/www/html/site/templates/gallery.php'),'template=gallery-album, sort=sort, sort=id'),'public gallery uses same native order');
 echo "$n gallery ordering checks passed\n";
}finally{foreach($ids as $id){$p=$wire->pages->get($id);if($p->id&&str_starts_with($p->title,'Sortiertest '))$wire->pages->delete($p);}}
