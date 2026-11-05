<?php declare(strict_types=1);
use Kulturhus\Verein\Documents;
require_once __DIR__.'/free-list-interface-browser-fixture.php';
$profiles=[];foreach(['front','direct']as$app)foreach([false,true]as$js)foreach(['de','en']as$locale)$profiles[]=['tag'=>$app.'-'.($js?'js':'nojs').'-'.$locale,'app'=>$app,'js'=>$js,'locale'=>$locale];
$publicCases=[];foreach($cases as$name=>$case){$publicCases[$name]=array_diff_key($case,['original'=>true]);if(isset($case['original']))$publicCases[$name]['sha256']=$case['original']['sha256'];}
$originals=[];foreach(['money','goods']as$kind)$originals[$kind]=hash_file('sha256',dirname(__DIR__,2).'/resources/donation-forms/'.$kind.'-original.pdf');
$fixture=ownFreeListInterfaceBrowser($db,['profiles'=>$profiles,'cases'=>$publicCases,'old_hash'=>$oldState['comparison_hash'],'originals'=>$originals]);
$protected=array_values(array_diff($tables,['native_auth_sessions','native_auth_limits','native_language_preferences']));$generated=[];$receiptIds=[];
$onlyOld=static function(string$table)use(&$generated,&$receiptIds):array{
    if(!$generated)return['',[]];$field=match($table){'documents'=>'id','document_files','document_links','document_requests','document_history','donation_receipts'=>'document_id','audit_events'=>'object_id',default=>null};
    if($table==='donation_receipt_claims')return[' WHERE receipt_id NOT IN ('.implode(',',array_fill(0,count($receiptIds),'?')).')',$receiptIds];
    return$field?[' WHERE `'.$field.'` NOT IN ('.implode(',',array_fill(0,count($generated),'?')).')',$generated]:['',[]];
};
$beforeBrowser=ownOriginalDatabaseHash($db,$protected,$onlyOld);$private=[];foreach(['auth','private']as$name)$private[$name]=hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key');
$counts=[];foreach(['documents','document_files','document_links','document_requests','document_history','donation_receipts','donation_receipt_claims','audit_events','finance_transactions']as$table)$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
try{
    $process=proc_open(['node',dirname(__DIR__).'/donation-form-interface-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);fwrite($pipes[0],json_encode($fixture['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Actual donation form browser failed.');
}finally{foreach($fixture['servers']as[$server,$log]){proc_terminate($server);proc_close($server);fclose($log);}}
$result=json_decode(file_get_contents($fixture['input']['root'].'/donation-form-interface-results.json'),true,64,JSON_THROW_ON_ERROR);$check(count($result['profiles'])===(getenv('DVM_DONATION_FORM_CASE')?1:8),'all selected real donation form browser profiles finished');$expected=count(array_intersect($result['profiles'],['front-js-en','direct-nojs-de']));$generated=array_column($result['created'],'doc');$check(count($generated)===$expected&&count(array_unique($generated))===$expected,'only selected two explicit browser confirmations add distinct real statutory certificate originals');
foreach($result['created']as$created){$file=$documents->file($created['doc'],1);$meta=json_decode($file['snapshot'],true,512,JSON_THROW_ON_ERROR);$receiptIds[]=$meta['receipt_number'];$check(hash_equals($created['sha256'],hash('sha256',$file['bytes']))&&$meta['kind']==='donation_receipt'&&$meta['signature_mode']==='handwritten'&&$meta['source_id']===$created['source']&&$meta['donation_form']['kind']===$created['kind'],'actual browser certificate keeps its exact payment or goods source and unfilled handwritten signature contract');$check((new Documents($db,'own-list-reader'))->file($created['doc'],1)===$file,'actual new statutory original remains readable with current financial reading');}
$check(ownOriginalDatabaseHash($db,$protected,$onlyOld)===$beforeBrowser,'all real source drafts failures comparisons adoption and actual issuance preserve every whole earlier row schema original financial amount and authority document');
foreach($counts as$table=>$count)$check((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===$count+($table==='finance_transactions'?0:$expected),'complete actual certificate flow adds only its own declared rows in '.$table);
foreach(['money','goods']as$kind)$check($documents->file($cases[$kind.'-issued']['document'],1)===$cases[$kind.'-issued']['original'],'actual browser retains every earlier complete statutory PDF and snapshot');
foreach($private as$name=>$hash)$check(hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key')===$hash,'complete actual donation form flows retain exact private '.$name.' key');
