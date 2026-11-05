<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\OutgoingInvoiceRequests;
require_once __DIR__.'/outgoing-invoice-snapshots.php';

/** Two independent plain-PDO PHP writers, without the test database advisory lock. */
function ownOutgoingInvoiceProcesses(PDO $db,callable $check,array $fixture,array $config):void
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();if(!preg_match('/\Akh_(?:native|test)_[a-f0-9]{24}\z/D',$name)||$config['database']!==$name||$config['host']!=='127.0.0.1'||!in_array($config['port'],[13371,13372],true))throw new RuntimeException('Owned independent invoice writers required.');
    $code=<<<'PHP'
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);$c=$f['config'];if($c['host']!=='127.0.0.1'||!in_array($c['port'],[13371,13372],true)||!preg_match('/\Akh_(?:native|test)_[a-f0-9]{24}\z/D',$c['database']))throw new RuntimeException('Owned actual invoice process required.');require$f['app'].'/native/bootstrap.php';require$f['app'].'/src/bootstrap.php';$db=new PDO('mysql:host=127.0.0.1;port='.$c['port'].';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);while(microtime(true)<$f['start'])usleep(1000);try{$receipt=(new \Kulturhus\Verein\Finance\Invoices\OutgoingInvoiceRequests($db,$f['actor']))->execute($f['input'],$f['key']);echo json_encode(['accepted'=>true,'receipt'=>$receipt],JSON_THROW_ON_ERROR);}catch(DomainException$failure){if(!in_array($failure->getCode(),[409,503],true))throw$failure;echo json_encode(['accepted'=>false,'status'=>$failure->getCode(),'key'=>$f['key']],JSON_THROW_ON_ERROR);}
PHP;
    $requests=$fixture['requests'];$key=$fixture['key'];$originals=[];
    foreach([true,false]as$same){
        $input=$fixture['input'];$input['data']['description']=$same?'Own concurrent creation':'Own concurrent replacement';$id=null;
        if(!$same){$saved=$requests->execute($input,$key());$id=$saved['result']['id'];$input['invoice']=$id;$input['version']='1';$input['data']['note']='Own entire concurrent replacement 0';}
        $oldRows=ownOutgoingInvoiceEarlierRows($db,$fixture['tables']);$filter=static function(string$table)use($oldRows,$id):array{[$where,$args]=$oldRows($table);if($id!==null&&$table==='outgoing_invoices'){$where.=' AND id<>?';$args[]=$id;}return[$where,$args];};$before=ownOriginalDatabaseHash($db,$fixture['tables'],$filter);
        $count=(int)$db->query("SELECT COUNT(*) FROM native_settings_requests WHERE section='outgoing-invoice'")->fetchColumn();$keys=[$key(),$key()];if($same)$keys[1]=$keys[0];$children=[];$start=microtime(true)+.25;
        foreach($keys as$originalKey){$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Owned actual invoice child unavailable.');fwrite($pipes[0],json_encode(['app'=>dirname(__DIR__,2),'config'=>$config,'actor'=>$fixture['actor'],'input'=>$input,'key'=>$originalKey,'start'=>$start],JSON_THROW_ON_ERROR));fclose($pipes[0]);$children[]=[$process,$pipes];}
        $answers=[];foreach($children as[$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Actual independent invoice child failed: '.substr($error,0,300));$answers[]=json_decode($out,true,64,JSON_THROW_ON_ERROR);}
        $accepted=array_values(array_filter($answers,static fn(array$a):bool=>$a['accepted']));$check(count($accepted)>=1&&($same||count($accepted)===1),'two actual PHP invoice '.($same?'same-key creations':'competing version replacements').' accept one whole intention');$first=$requests->receipt($accepted[0]['receipt']['key']);foreach($accepted as$answer)if($answer['receipt']!==$first)throw new RuntimeException('Actual concurrent invoice originals differ.');$originals[]=$first;
        $check((int)$db->query("SELECT COUNT(*) FROM native_settings_requests WHERE section='outgoing-invoice'")->fetchColumn()===$count+1&&ownOriginalDatabaseHash($db,$fixture['tables'],$filter)===$before&&$first['result']['version']===($same?1:2),'actual competing invoice processes append precisely one personal first while preserving every whole earlier unrelated row');
        if($same){$beforeReplay=ownOriginalDatabaseHash($db,$fixture['tables']);$check($requests->execute($input,$keys[0])===$first&&ownOriginalDatabaseHash($db,$fixture['tables'])===$beforeReplay,'same accepted creation key remains exact after a genuine concurrent process');}
        else foreach($answers as$answer)if(!$answer['accepted'])$fixture['deny'](fn()=>$requests->receipt($answer['key']),404,'rejected competing invoice key has no invented private first');
    }
    foreach($originals as$first)if($requests->receipt($first['key'])!==$first)throw new RuntimeException('An earlier concurrent invoice first changed.');$check(true,'later concurrent replacements retain every whole earlier concurrent first');
}
