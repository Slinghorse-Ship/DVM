<?php declare(strict_types=1);
/** Genuine canonical demands and local source movements for browser and restored-package proofs. */
function ownSepaRemainingDefaults(PDO $db,array $fixture):void
{
    $cfg=\Kulturhus\Native\TestSupport::config();
    if(($cfg['owner']??null)!=='isolated-native-ci'||!in_array($cfg['label']??null,['native-sepa-return-corrections','native-sepa-remaining-collection','native-recovery'],true)||($fixture['club']??null)!=='own-sepa-native'||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Exact owned remaining collection fixture required.');
    $bank=$fixture['settings']->section('bank');$terms=$fixture['bankVault']->mandateForCollection($fixture['payer'])['terms'];
    if($bank['data']['creditor']!==$terms['creditor_id'])$fixture['settings']->save('bank',array_replace($bank['data'],['creditor'=>$terms['creditor_id']]),$bank['version']);
}

function ownSepaRemainingDemand(PDO $db,array $fixture,string $label,string $sourceAmount='60'):array
{
    ownSepaRemainingDefaults($db,$fixture);$f=$fixture['finance'];$key=$fixture['key'];
    $entry=$f->createEntry(['kind'=>'contribution','category'=>'membership','description'=>'Own canonical remaining '.$label,'counterparty'=>'Own SEPA payer','amount'=>'100','document_date'=>$fixture['notice'],'due_date'=>$fixture['notice'],'notes'=>json_encode(['payer'=>$fixture['payer'],'year'=>(int)substr($fixture['notice'],0,4)],JSON_THROW_ON_ERROR)],$key());
    $issued=(new \Kulturhus\Verein\ContributionInvoices($db,$fixture['actor']))->issue($entry);
    $prior=$f->createTransaction(['account'=>$fixture['account'],'booked_on'=>$fixture['notice'],'direction'=>'income','amount'=>$sourceAmount,'description'=>'Own actual earlier payment '.$label],$key());
    $f->allocate($prior,(int)$f->transactionDetail($prior)['version'],$entry,(int)$f->entry($entry)['version'],'60',$key());
    $invoice=(new \Kulturhus\Verein\ContributionInvoices($db,$fixture['actor']))->currentOriginal($entry);
    return ['entry'=>$entry,'prior'=>$prior,'document'=>$issued['document_id'],'number'=>$f->entry($entry)['display_number'],'invoice_sha256'=>$invoice['file']['sha256'],'label'=>$label];
}

/** Called only after the actual submitted forty order exists; never fabricates bank-import rows. */
function ownSepaRemainingActualMoney(PDO $db,array $fixture,array $case,string $order):array
{
    ownSepaRemainingDefaults($db,$fixture);$f=$fixture['finance'];$key=$fixture['key'];$current=$fixture['service']->get($order);$items=$current['items'];$entry=$f->currentEntry($case['entry']);
    if(($fixture['service']->decisionSnapshot($order)['transport']['pending']??false)||$current['state']!=='submitted'||(int)$current['total_cents']!==4000||count($items)!==1||$items[0]['entry_id']!==$case['entry']||$items[0]['state']!=='active'||(int)$items[0]['cents']!==4000||$entry['allocated_cents']!==6000||$entry['remaining_cents']!==4000)throw new RuntimeException('Actual submitted remaining40 and untouched prior60 required.');
    $positive=$f->createTransaction(['account'=>$fixture['account'],'booked_on'=>$fixture['notice'],'direction'=>'income','amount'=>'40','description'=>'Own actually collected remaining40 '.$case['label']],$key());
    $f->allocate($positive,(int)$f->transactionDetail($positive)['version'],$case['entry'],(int)$f->entry($case['entry'])['version'],'40',$key());
    $counter=$f->createTransaction(['account'=>$fixture['account'],'booked_on'=>$fixture['notice'],'direction'=>'expense','amount'=>'40','description'=>'Own actually returned partial native40 '.$case['label']],$key());
    $q=$db->prepare('SELECT id FROM finance_allocations WHERE club_id=? AND transaction_id=? AND entry_id=?');$q->execute([$fixture['club'],$positive,$case['entry']]);$allocation=$q->fetchColumn();
    return ['order'=>$order,'item'=>$items[0]['id'],'positive'=>$positive,'counter'=>$counter,'allocation'=>$allocation];
}

function ownSepaRemainingCompleteCase(PDO $db,array $fixture,string $label):array
{
    $case=ownSepaRemainingDemand($db,$fixture,$label);$f=$fixture['finance'];$key=$fixture['key'];$native=$fixture['native'];
    $notices=new \Kulturhus\Verein\Finance\Banking\Notice\DebitRequests($db,$fixture['actor'],$fixture['bankVault']);
    $noticeInput=$notices->initial($case['entry'],$fixture['notice'],$fixture['date']);$noticeInput['reason']="\nOwn original remaining40 notice ".$label." ä🙂\r\n";$noticeReview=$notices->prepare($noticeInput);$noticeFirst=$notices->accept($noticeInput,$key(),$noticeReview['hash'],true);
    $raw=($fixture['inputFor'])($case['entry']);$review=$native->prepare($raw);$prepared=$native->accept($raw,$key(),$review['hash'],true);$order=$prepared['result']['order']['id'];$firsts=[$prepared];$export=null;
    foreach(['approve','export','submitted']as$operation){
        $raw=\Kulturhus\Verein\Finance\Banking\NativeSepa\SepaInput::initial($order);$raw['operation']=$operation;$raw['data']['reason']='Own remaining40 '.$operation.' '.$label;
        if($operation==='submitted'){$raw['data']['bank_reference']='OWN-REMAINING40-'.$label;$raw['data']['evidence']='Own actual synthetic outcome; no external bank request';}
        $review=$native->prepare($raw);$first=$native->accept($raw,$key(),$review['hash'],true);$firsts[]=$first;if($operation==='export')$export=$first;
    }
    $money=ownSepaRemainingActualMoney($db,$fixture,$case,$order);
    $raw=\Kulturhus\Verein\Finance\Banking\NativeSepa\SepaInput::initial($order);$raw['operation']='return';$raw['data']['item']=$money['item'];$raw['data']['transaction']=$money['counter'];$raw['data']['reason']="\nOwn actually returned remaining40 ".$label." ä🙂\r\n";$raw['data']['return_allocations']=[$money['allocation']];$review=$native->prepare($raw);$returned=$native->accept($raw,$key(),$review['hash'],true);
    return $case+$money+compact('noticeInput','noticeReview','noticeFirst','firsts','export','raw','review','returned');
}
