<?php declare(strict_types=1);
use Kulturhus\Verein\{Finance,Documents,InvoiceApprovals};
require_once __DIR__.'/bank-group-fixture.php';

function ownPaymentGroupCentCase(PDO $db,string $actor,string $tag,string $kind='income',bool $shared=true):array
{
    ownBankGroupDatabase($db);$f=new Finance($db,$actor);$key=static fn()=>bin2hex(random_bytes(16));$account=$f->createAccount('Own rounded original '.$tag,'offline','1000','2020-01-01');$entries=[];$documents=[];
    foreach($shared?[['0.03',['0.01','0.01','0.01']],['0.01',['0.01']]]:[['0.03',['0.01','0.01','0.01']]]as$i=>[$amount,$parts]){
        $positions=[];foreach($parts as$j=>$part)$positions[]=['description'=>'Own rounded original '.$i.' position'.$j,'amount'=>$part,'sphere'=>'1','account'=>$kind==='expense'?'68150':'40000','tax_rate'=>'0','input_tax'=>$kind==='expense'?'none':'unknown'];
        $entry=$f->createEntry(['kind'=>$kind,'category'=>'general','description'=>'Own rounded original '.$tag.' '.$i,'counterparty'=>'Own actual cent customer','amount'=>$amount,'document_date'=>'2026-01-20','due_date'=>'2026-01-20','positions'=>$positions],$key());$entries[]=$entry;
        if($kind==='expense'){$image=imagecreatetruecolor(32,32);ob_start();imagepng($image);$png=ob_get_clean();imagedestroy($image);$document=(new Documents($db,$actor))->upload('own-original-cent-'.$i.'.png',$png,['title'=>'Own exact rounded expense '.$i,'kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$entry]],$key());$documents[$entry]=compact('document');$approvals=new InvoiceApprovals($db,$actor);$approval=$approvals->state($entry);$approvals->approve($entry,$approval['hash'],$approval['decision_id'],$key(),true);}
    }
    $total=$shared?4:3;$source=$f->createTransaction(['account'=>$account,'direction'=>$kind==='expense'?'expense':'income','amount'=>Finance::decimal($total),'description'=>'Own actual rounded original '.$tag,'booked_on'=>'2026-01-23'],$key());$parts=[[$entries[0],'0.01'],[$entries[0],'0.02']];if($shared)$parts[]=[$entries[1],'0.01'];foreach($parts as[$entry,$amount])$f->allocate($source,(int)$f->transactionDetail($source)['version'],$entry,(int)$f->entry($entry)['version'],$amount,$key());$returned=$f->createTransaction(['account'=>$account,'direction'=>$kind==='expense'?'income':'expense','amount'=>Finance::decimal($total),'description'=>'Own actual rounded whole return '.$tag,'booked_on'=>'2026-01-24'],$key());
    return compact('tag','kind','shared','account','entries','documents','source','returned','total');
}
