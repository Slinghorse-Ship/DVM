<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,BoardAccess,Documents,FinanceWorkflowActions};
use Kulturhus\Verein\Finance\Invoices\OutgoingInvoiceRequests;

/** Actual Finance-only issuance, current withdrawn roles and inherited original privacy. */
function ownOutgoingInvoiceSources(PDO $db,callable $check,array $fixture,array $config):array
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if(!preg_match('/\Akh_(?:native|test)_[a-f0-9]{24}\z/D',$name)||$config['database']!==$name||$config['host']!=='127.0.0.1'||!in_array($config['port'],[13371,13372],true))throw new RuntimeException('Owned current invoice sources required.');
    $db->exec("INSERT INTO actors(id,club_id,role,is_superuser)VALUES('own-invoice-finance','own-list-club','member',0),('own-invoice-general','own-list-club','members',0);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version)VALUES('own-list-club','own-invoice-finance','Own invoice Finance only','','none','write',0,1);INSERT INTO settings_role_assignments VALUES('own-invoice-finance','own-list-club','own-invoice-finance',1)");
    $actor='own-invoice-finance';$requests=new OutgoingInvoiceRequests($db,$actor);$rights=Permissions::actor($db,$actor);$key=$fixture['key'];$input=$fixture['input'];$input['data']['description']='Own actual Finance-only invoice';
    $saved=$requests->execute($input,$key());$id=$saved['result']['id'];$reviewInput=$input;$reviewInput['operation']='review';$reviewInput['invoice']=$id;$reviewInput['version']='1';$review=$requests->execute($reviewInput,$key());$issue=['operation'=>'issue','invoice'=>$id,'version'=>'2','data'=>['reviewed_hash'=>$review['result']['reviewed_hash']]];$issued=$requests->execute($issue,$key(),true);
    $check($rights['finance_access']==='write'&&$rights['members_access']==='none'&&!$rights['settings_access']&&!BoardAccess::allowed($db,$actor)&&$issued['result']['state']==='issued'&&$issued['file']['revision']===1,'actual Finance-only writer saves reviews and issues its real original without General member or board permission');
    $fixture['deny'](fn()=>(new OutgoingInvoiceRequests($db,'own-invoice-general'))->receipt($issued['key']),403,'General settings permission cannot inherit financial original access');
    $dsn='mysql:host=127.0.0.1;port='.$config['port'].';dbname='.$name.';charset=utf8mb4';$caller=new PDO($dsn,$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    foreach(['read','none']as$access){
        $caller->beginTransaction();$caller->query('SELECT * FROM settings_roles')->fetchAll();
        Db::run($db,'UPDATE settings_roles SET finance_access=?,version=version+1 WHERE club_id=? AND id=?',[$access,'own-list-club',$actor]);
        try{
            $before=ownOriginalDatabaseHash($caller,$fixture['tables']);$current=new OutgoingInvoiceRequests($caller,$actor);
            if($access==='read')$check($current->execute($issue,$issued['key'],true)===$issued,'actual withdrawn write role behind an older snapshot can read its exact already accepted issuance without another write');
            else{ $denied=false;try{$current->receipt($issued['key']);}catch(DomainException$failure){$denied=$failure->getCode()===403;}$check($denied,'actual withdrawn financial reading denies a known own first original behind an older snapshot'); }
            $denied=false;try{$current->execute($input,$key());}catch(DomainException$failure){$denied=$failure->getCode()===403;}
            $check($denied&&$caller->inTransaction()&&ownOriginalDatabaseHash($caller,$fixture['tables'])===$before,'current withdrawn '.$access.' role cannot create another invoice or lose earlier caller state');
        }finally{$caller->rollBack();}
    }
    Db::run($db,"UPDATE settings_roles SET finance_access='write',version=version+1 WHERE club_id=? AND id=?",['own-list-club',$actor]);
    $documents=new Documents($db,$fixture['actor']);$parent=$documents->saveFolder('',0,'Own invoice actual private ancestor','','finance');$child=$documents->saveFolder('',0,'Own invoice actual original child',$parent,'finance');$doc=$documents->document($issued['result']['document_id']);
    $documents->update($doc['id'],(int)$doc['version'],['title'=>$doc['title'],'kind'=>$doc['kind'],'document_date'=>$doc['document_date'],'scope'=>'finance','folder'=>$child,'state'=>$doc['state'],'notes'=>$doc['notes'],'reason'=>'Own conscious original placement','links'=>array_map(static fn(array$row):string=>$row['source_type'].':'.$row['source_id'],$doc['links'])]);
    $caller->beginTransaction();$caller->query('SELECT * FROM document_folders')->fetchAll();Db::run($db,'UPDATE document_folders SET admission_only=1 WHERE club_id=? AND id=?',['own-list-club',$parent]);
    try{
        $before=ownOriginalDatabaseHash($caller,$fixture['tables']);$statuses=[];
        foreach([fn()=>(new OutgoingInvoiceRequests($caller,$actor))->preview($id,3),fn()=>FinanceWorkflowActions::handle($caller,$actor,['action'=>'outgoing-pdf','invoice'=>$id,'version'=>'3']),fn()=>(new \Kulturhus\Verein\OutgoingInvoices($caller,$actor))->xml($id)]as$read){try{$read();$statuses[]=0;}catch(DomainException$failure){$statuses[]=$failure->getCode();}}
        $check($statuses===[403,403,403]&&$caller->inTransaction()&&ownOriginalDatabaseHash($caller,$fixture['tables'])===$before,'actual ancestor-only original privacy denies native PDF earlier canonical PDF and XML behind an older snapshot without business work');
        $check((new OutgoingInvoiceRequests($caller,$actor))->receipt($issued['key'])===$issued,'own historical complete invoice confirmation remains exact while its separately protected original PDF is unavailable');
    }finally{$caller->rollBack();}
    $check($fixture['requests']->preview($id,3)['sha256']===$issued['file']['sha256']&&$requests->receipt($saved['key'])===$saved&&$requests->receipt($review['key'])===$review&&$requests->receipt($issued['key'])===$issued,'real original and every earlier Finance-only first retain their exact bytes after deliberate monotonic ancestor privacy');
    return compact('actor','id','saved','review','issued','parent','child');
}
