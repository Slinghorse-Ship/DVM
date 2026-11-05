<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{OutgoingInvoices,Settings,SettingsSchema,Finance,Documents,Permissions};
use Kulturhus\Verein\Finance\Invoices\{OutgoingInvoiceInput,OutgoingInvoiceRequests,OutgoingInvoiceDraft};

/** Real saves, reviews, issuance and original rows in the caller's owned database. */
function ownOutgoingInvoiceContracts(PDO $db,callable $check):array
{
    if(!preg_match('/\Akh_(?:test|native)_[a-f0-9]{24}\z/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Owned outgoing invoice contracts required.');
    $actor='own-list-admin';$finance=new Finance($db,$actor);$settings=new Settings($db,$actor);$account=$finance->createAccount('Own original native invoice account','offline','0','2025-01-01');
    foreach(['club'=>['name'=>'Own original invoice association','street'=>'Own seller street','postcode'=>'12345','city'=>'Own seller town','email'=>'own-seller@example.invalid','phone'=>'+49 123 456789','register'=>'Own synthetic register'],'tax'=>['number'=>'123/456/78901'],'bank'=>['iban'=>'DE89370400440532013000','holder'=>'Own original invoice association','account_id'=>$account]]as$section=>$values)$settings->save($section,array_replace(SettingsSchema::defaults($section),$values),0);
    $data=['buyer_name'=>'Own actual original buyer','buyer_street'=>'Own buyer street','buyer_postcode'=>'23456','buyer_city'=>'Own buyer town','buyer_email'=>'','buyer_reference'=>'','seller_contact'=>'','description'=>'Own actual complete native invoice','note'=>"\n0\n",'vat_reason'=>'','document_date'=>'2025-12-01','delivery_date'=>'2025-12-01','due_date'=>'2025-12-15','category'=>'rooms','contact_id'=>'','payment_account'=>$account,'domestic_confirmed'=>'0','invoice_format'=>'pdf','positions'=>[['description'=>'Own actual room','quantity'=>'3','unit'=>'DAY','unit_gross'=>'119','tax_rate'=>'19','sphere'=>'2','account'=>'']]];
    $input=OutgoingInvoiceInput::initial();$input['data']=$data;$input=OutgoingInvoiceInput::capture($input);$requests=new OutgoingInvoiceRequests($db,$actor);$service=new OutgoingInvoices($db,$actor);$key=static fn():string=>bin2hex(random_bytes(16));$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $deny=static function(callable $operation,int $status,string $label)use($db,$tables,$check):void{
        $before=ownOriginalDatabaseHash($db,$tables);$denied=false;try{$operation();}catch(DomainException$failure){$denied=$failure->getCode()===$status;}
        $check($denied&&ownOriginalDatabaseHash($db,$tables)===$before,$label);
    };
    $invalid=$input;$invalid['data']['buyer_name']=str_repeat('🙂',201);$check(OutgoingInvoiceInput::capture($invalid)===$invalid,'native invoice capture retains the complete overlong Unicode party name');
    $deny(fn()=>$requests->execute($invalid,$key()),422,'whole invalid party name cannot save a clipped draft or invent a private first result');
    $missing=$input;unset($missing['data']['note']);$deny(fn()=>$requests->execute($missing,$key()),422,'missing whole native invoice fields cannot become invented optional defaults');
    $unknown=$input;$unknown['data']['invoice_format']='0';$deny(fn()=>$requests->execute($unknown,$key()),422,'unknown explicit invoice format cannot silently become ordinary PDF or electronic invoice');
    $first=$requests->execute($input,$key());$id=$first['result']['id'];$original=json_decode($first['original_json'],true,64,JSON_THROW_ON_ERROR);
    $check($first['input']===$input&&$first['normalized']['note']==='0'&&$original['input']['data']['note']==="\n0\n"&&$first['result']['state']==='draft'&&$first['result']['version']===1&&$first['result']['number']===''&&$first['result']['entry_id']===null&&$first['result']['document_id']===null,'actual native draft creation records raw leading blank zero separately from normalization without a number financial entry or original PDF');
    $check($first['source']['payment_account']['id']===$account&&$first['source']['payment_account']['name']==='Own original native invoice account'&&preg_match('/\.\d{6}Z$/D',$first['created_at'])===1,'actual first draft retains its selected account label and real microsecond time');
    $before=ownOriginalDatabaseHash($db,$tables);$check($requests->execute($input,$first['key'])===$first&&ownOriginalDatabaseHash($db,$tables)===$before,'accepted creation key returns its byte exact first result without another draft history or audit');
    $deny(fn()=>(new OutgoingInvoiceRequests($db,'own-list-reader'))->receipt($first['key']),404,'another current financial reader cannot inherit the personal first draft confirmation');
    $deny(fn()=>(new OutgoingInvoiceRequests($db,'own-list-foreign'))->receipt($first['key']),404,'another association cannot read a known personal invoice confirmation');
    $deny(fn()=>(new OutgoingInvoiceRequests($db,'own-list-reader'))->execute($input,$key()),403,'financial reading cannot create a native invoice draft');
    $before=ownOriginalDatabaseHash($db,$tables);$pdf=(new OutgoingInvoiceRequests($db,'own-list-reader'))->preview($id,1);
    $check(str_starts_with($pdf['bytes'],'%PDF-')&&$pdf['document']['draft']===true&&$pdf['invoice']['version']===1&&ownOriginalDatabaseHash($db,$tables)===$before,'current financial reader proves the real saved draft PDF without business work or General permission');
    $reviewInput=$input;$reviewInput['operation']='review';$reviewInput['invoice']=$id;$reviewInput['version']='1';
    $edited=$reviewInput;$edited['data']['description']='Own unsaved whole review change 0';$deny(fn()=>$requests->execute($edited,$key()),409,'native review cannot ignore unsaved complete changes and review a different saved draft');
    $before=ownOriginalDatabaseHash($db,$tables);$db->beginTransaction();$insideReview=$requests->execute($reviewInput,$key());$check($db->inTransaction()&&$insideReview['result']['state']==='reviewed','actual native review composes with an earlier caller transaction');$db->rollBack();
    $check(ownOriginalDatabaseHash($db,$tables)===$before,'caller rollback restores every whole earlier row after actual native review and its first confirmation');
    $review=$requests->execute($reviewInput,$key());$check($review['result']['version']===2&&$review['result']['payload']===$first['result']['payload']&&$review['input']['data']===$input['data']&&$review['source']['invoice']['payload']===$first['result']['payload'],'actual review retains complete submitted fields original saved payload and its current source');
    $issueInput=['operation'=>'issue','invoice'=>$id,'version'=>'2','data'=>['reviewed_hash'=>$review['result']['reviewed_hash']]];
    $deny(fn()=>$requests->execute($issueInput,$key()),422,'actual issuance requires separate explicit approval');
    $bank=$settings->section('bank');$later=$bank['data'];$later['iban']='DE44500105175407324931';$settings->save('bank',$later,$bank['version']);
    $deny(fn()=>$requests->preview($id,2),409,'actual changed bank invalidates the reviewed saved PDF without another invoice version');
    $deny(fn()=>$requests->execute($issueInput,$key(),true),409,'actual changed bank stops issuance without a number entry document or new confirmation');
    $settings->save('bank',$bank['data'],$bank['version']+1);
    $before=ownOriginalDatabaseHash($db,$tables);$db->beginTransaction();$insideIssue=$requests->execute($issueInput,$key(),true);
    $check($db->inTransaction()&&$insideIssue['result']['state']==='issued'&&$insideIssue['file']['revision']===1,'actual native issuance allocates its number financial entry and real original PDF within the caller transaction');$db->rollBack();
    $check(ownOriginalDatabaseHash($db,$tables)===$before,'caller rollback restores actual numbering positions PDF archive histories and first original together');
    $issued=$requests->execute($issueInput,$key(),true);$invoice=$service->get($id);$file=(new Documents($db,$actor))->file($invoice['document_id'],1);$snapshot=json_decode($file['snapshot'],true,64,JSON_THROW_ON_ERROR);
    $check($issued['result']['state']==='issued'&&$issued['result']['version']===3&&$issued['file']['document_id']===$invoice['document_id']&&$issued['file']['sha256']===$file['sha256']&&str_starts_with($file['bytes'],'%PDF-')&&!isset($snapshot['einvoice'])&&$snapshot['draft']===false&&$invoice['data']['buyer_email']===''&&$invoice['data']['invoice_format']==='pdf','genuine native no-email invoice has an allocated number and immutable ordinary PDF without invented electronic proof');
    $deny(fn()=>$service->queue($id,3),422,'actual no-email original remains a postal PDF and cannot create a delivery');
    $protected=['outgoing_invoices','outgoing_invoice_history','finance_entries','finance_transactions','finance_allocations','accounting_positions','documents','document_files'];$originalHash=ownOriginalDatabaseHash($db,$protected);
    $bank=$settings->section('bank');$later=$bank['data'];$later['iban']='DE44500105175407324931';$settings->save('bank',$later,$bank['version']);$before=ownOriginalDatabaseHash($db,$tables);$read=$requests->preview($id,3);
    $check($read['sha256']===$file['sha256']&&$read['source']['settings']===[]&&$read['source']['issuer']===[]&&ownOriginalDatabaseHash($db,$tables)===$before,'genuine issued original stays byte exact after later bank changes without unused current issuer sources or business work');
    $before=ownOriginalDatabaseHash($db,$tables);$check($requests->receipt($first['key'])===$first&&$requests->receipt($review['key'])===$review&&$requests->execute($issueInput,$issued['key'],true)===$issued&&ownOriginalDatabaseHash($db,$tables)===$before,'all earlier whole personal firsts survive later sources and accepted issuance replay writes nothing');
    $check($requests->history($id)['total']===3&&count($requests->history($id)['rows'])===3,'new creation result identifier binds all three actual personal workflow originals to its invoice');
    $page=$requests->history($id,['page'=>'999999']);$check($page['page_input']==='999999'&&$page['page']===null&&$page['rows']===[]&&$page['error']!=='','whole unavailable personal invoice page selects no substitute');
    $deny(fn()=>$requests->history($id,['query'=>str_repeat('🙂',151)]),422,'overlong personal invoice history search cannot be clipped');
    $session=[];$actorRow=$requests->assertAccess(true);$draft=OutgoingInvoiceDraft::open($session,$actorRow,$input);$draft=OutgoingInvoiceDraft::complete($draft,$first['key']);OutgoingInvoiceDraft::store($session,$draft);$next=OutgoingInvoiceDraft::open($session,$actorRow,$input);
    $check($next['id']!==$draft['id']&&$next['key']!==$draft['key']&&$next['accepted']===null&&$requests->receipt($first['key'])===$first,'conscious next creation opens a fresh personal draft without reusing or changing an accepted original');
    $check(ownOriginalDatabaseHash($db,$protected)===$originalHash,'all subsequent source reads personal history and next private draft retain every whole issued financial and document row');
    $settings->save('bank',$bank['data'],$bank['version']+1);
    return compact('actor','finance','settings','account','data','input','requests','service','id','first','review','issued','invoice','file','snapshot','tables','deny','key','protected');
}
