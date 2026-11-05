<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,DataModel,Pages};
// Only ci-native's own disposable database; never read a running manager configuration.
 $db=TestSupport::db();$actors=TestSupport::seed($db);Schema::install($db);$access=new Access($db,$actors['admin']);$model=new DataModel($db,$access);$pages=new Pages($db,$access);$values=[];$keys=[];
 for($i=1;$i<=8;$i++){$key='long'.$i;$keys[]=$key;$values[$key]=str_repeat('Ä',99996).'TAIL';$model->saveField(['key'=>$key,'label'=>'Own long '.$i,'type'=>'textarea']);}
 $model->saveField(['key'=>'scalar_amount','label'=>'Own exact decimal','type'=>'decimal']);$model->saveField(['key'=>'rich_many','label'=>'Own repeated richtext','type'=>'richtext','multiple'=>true]);
 $model->saveTemplate(['key'=>'bounded_cells','label'=>'Own bounded cells','fields'=>$keys]);$base=$pages->save(['title'=>'Own bounded base','slug'=>'own-bounded-base','template'=>'bounded_cells','custom_fields'=>$values]);$payload=json_decode(Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$base['id']])->fetchColumn(),true,512,JSON_THROW_ON_ERROR);unset($values);
 for($i=0;$i<25;$i++){$payload['title']='Own bounded clone '.$i;$payload['slug']='own-bounded-clone-'.$i;$payload['custom_fields']['long2']=str_repeat('Ä',99999).($i===0?'Z':'A');$json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);Db::run($db,'INSERT INTO khcms_pages(id,club_id,slug,draft_json,version,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,1,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[md5('own-lister-bounded-row-'.$i),$access->club(),$payload['slug'],$json,$actors['admin'],$actors['admin']]);}unset($json,$payload,$base);
 $keys=[];for($i=1;$i<=3;$i++){$keys[]='rich'.$i;$model->saveField(['key'=>'rich'.$i,'label'=>'Own long richtext '.$i,'type'=>'richtext']);}
 $model->saveTemplate(['key'=>'long_sort','label'=>'Own long sort','fields'=>$keys]);$link='<a href="https://example.invalid/'.str_repeat('a',1900).'">x</a>';
 foreach(['single'=>500,'three'=>290]as$kind=>$count){$html=str_repeat($link,$count);foreach(['A','Z']as$suffix){$values=['rich1'=>$html.'<p>A</p>','rich2'=>$html.'<p>A</p>','rich3'=>$html.'<p>'.$suffix.'</p>'];if($kind==='single')$values=['rich1'=>$html.'<p>'.$suffix.'</p>'];$page=$pages->save(['title'=>'Own long sort '.$kind.' '.$suffix,'slug'=>'own-long-sort-'.$kind.'-'.strtolower($suffix),'template'=>'long_sort','custom_fields'=>$values]);if($suffix==='Z')putenv('OWN_LONG_SORT_'.strtoupper($kind).'='.$page['id']);}unset($values,$page,$html);}

$tables=['khcms_pages','khcms_revisions','khcms_definitions','khcms_definition_revisions','khcms_state'];$before=ownOriginalDatabaseHash($db,$tables);$checks=0;
foreach(['cells','richsort','session']as$mode){
 $process=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=16M',__DIR__.'/support/selector-window-worker.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Own bounded window worker unavailable.');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);if($status!==0)throw new RuntimeException('Own window worker failed: '.$out.$error);$result=json_decode(trim($out),true,32,JSON_THROW_ON_ERROR);if($result['peak_memory_bytes']>=16*1024*1024)throw new RuntimeException('Native window exceeded its actual process memory limit.');$checks+=$result['checks'];echo 'PASS '.$mode.' real 16MiB worker: '.json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
}
if($before!==ownOriginalDatabaseHash($db,$tables))throw new RuntimeException('Window, full text sort or session failure modified original records.');++$checks;
echo json_encode(['suite'=>'native-selector-window','checks'=>$checks,'original_bytes'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
