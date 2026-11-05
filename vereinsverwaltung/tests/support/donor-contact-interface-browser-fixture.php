<?php declare(strict_types=1);
use Kulturhus\Verein\DonationPreparation;
require __DIR__.'/free-list-interface-browser-fixture.php';
$profiles=[];$browserCases=[];
foreach(['front','direct']as$app)foreach([true,false]as$js)foreach(['de','en']as$locale){$tag=$app.'-'.($js?'js':'nojs').'-'.$locale;$profiles[]=['tag'=>$tag,'app'=>$app,'js'=>$js,'locale'=>$locale];foreach(['entry','goods']as$type)$browserCases[$tag][$type]=$make($type,'Own actual donor browser '.$tag.' '.$type);}
$fixture=ownFreeListInterfaceBrowser($db,['profiles'=>$profiles,'cases'=>$browserCases,'contacts'=>$contacts]);
$fixture['input']['donorConcurrencyWorker']=['command'=>\Kulturhus\Native\TestSupport::phpCommand(),'path'=>__DIR__.'/donor-contact-concurrency-worker.php'];
$browserOriginalFiles=[];foreach($db->query('SELECT document_id,revision FROM document_files')->fetchAll()as$file)$browserOriginalFiles[$file['document_id'].'/'.$file['revision']]=$documents->file($file['document_id'],(int)$file['revision']);
$oldDocumentIds=$db->query('SELECT id FROM documents')->fetchAll(PDO::FETCH_COLUMN);$oldAudit=(int)$db->query('SELECT COALESCE(MAX(sequence),0) FROM audit_events')->fetchColumn();$generated=[];
$changedSources=[];foreach(['front-js-en','direct-nojs-de']as$tag)foreach(['entry','goods']as$type)$changedSources[$type][]=$browserCases[$tag][$type]['id'];
$filter=static function(string $table)use(&$generated,$changedSources,$oldAudit):array{
 if($table==='audit_events')return [' WHERE sequence<=?',[$oldAudit]];
 if(in_array($table,['finance_entries','goods_donations'],true)){$ids=$changedSources[$table==='finance_entries'?'entry':'goods'];return [' WHERE id NOT IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids];}
 if(in_array($table,['donation_contacts','donation_preparation_status'],true)){$ids=array_merge(...array_values($changedSources));return [' WHERE source_id NOT IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids];}
 if($generated&&in_array($table,['documents','document_files','document_links','document_requests','document_history'],true)){$field=$table==='documents'?'id':'document_id';return [' WHERE `'.$field.'` NOT IN ('.implode(',',array_fill(0,count($generated),'?')).')',$generated];}
 return ['',[]];
};
$protected=array_values(array_diff($tables,['native_auth_sessions','native_auth_limits','native_language_preferences']));$beforeBrowser=ownOriginalDatabaseHash($db,$protected,$filter);$changedOriginals=[];
foreach($changedSources as$type=>$ids)foreach($ids as$id){$table=$type==='entry'?'finance_entries':'goods_donations';$q=$db->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);$changedOriginals[$type][$id]=$q->fetch();}
$private=[];foreach(['auth','private']as$name)$private[$name]=hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key');
$beforeTransactions=$db->query('SELECT * FROM finance_transactions ORDER BY id')->fetchAll();
try{$process=proc_open(['node',dirname(__DIR__).'/donor-contact-interface-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);fwrite($pipes[0],json_encode($fixture['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Actual donor contact browser failed.');}
finally{foreach($fixture['servers']as[$server,$log]){proc_terminate($server);proc_close($server);fclose($log);}}
$result=json_decode(file_get_contents($fixture['input']['root'].'/donor-contact-results.json'),true,64,JSON_THROW_ON_ERROR);$check($result['busy_refusals']===16,'all sixteen real donor forms preserve their intention through an independently held source lock');
$check(count($result['profiles'])===8&&count($result['assigned'])===4&&$result['intervening_assignments']===1,'all eight real donor profiles complete with four explicit final assignments and one real intervening write');
$generated=array_values(array_diff($db->query('SELECT id FROM documents')->fetchAll(PDO::FETCH_COLUMN),$oldDocumentIds));$check(count($generated)===5,'only five explicit source assignments create their distinct unsigned PDF drafts');
$check(ownOriginalDatabaseHash($db,$protected,$filter)===$beforeBrowser,'complete actual donor browser preserves every whole unrelated row definition and old audit event');
foreach($changedOriginals as$type=>$sources)foreach($sources as$id=>$original){$table=$type==='entry'?'finance_entries':'goods_donations';$q=$db->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);$current=$q->fetch();$expectedDelta=$id===$browserCases['front-js-en']['entry']['id']?2:1;$check((int)$current['version']===(int)$original['version']+$expectedDelta,'actual browser source advances only its consciously accepted assignments');unset($current['version'],$original['version']);$check($current===$original&&$finance->donationContact($type,$id)['id']===$contacts[1],'accepted browser selection changes no original donation amount date or description');$state=DonationPreparation::status($db,'own-list-admin',$type,$id);$check($state['state']==='ready'&&count($state['documents'])===1,'actual browser assignment ends with its one current unsigned draft');}
foreach($generated as$id){$file=$documents->file($id,1);$snapshot=json_decode($file['snapshot'],true,512,JSON_THROW_ON_ERROR);$check(str_starts_with($file['bytes'],'%PDF-')&&$snapshot['kind']==='donation_form_draft'&&in_array($snapshot['source_id'],array_merge(...array_values($changedSources)),true)&&in_array($snapshot['donor_contact_id'],[$contacts[1],$contacts[2]],true),'actual browser creates only original-source unsigned donor drafts');}
foreach($browserOriginalFiles as$id=>$original){[$doc,$version]=explode('/',$id);$check($documents->file($doc,(int)$version)===$original,'actual browser keeps every complete earlier PDF and its snapshot exact');}
$check($db->query('SELECT * FROM finance_transactions ORDER BY id')->fetchAll()===$beforeTransactions&&(int)$db->query('SELECT COUNT(*) FROM donation_receipts')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM donation_receipt_claims')->fetchColumn()===0,'actual browser never changes a bank transaction issues a certificate or reserves a claim');
foreach($private as$name=>$hash)$check(hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key')===$hash,'actual donor browser preserves exact private '.$name.' key');
