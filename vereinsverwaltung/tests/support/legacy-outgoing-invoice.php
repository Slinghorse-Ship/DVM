<?php declare(strict_types=1);
/** Historical plain-PDF fixture only. Never a selectable production output mode. */
function legacyOutgoingInvoice(PDO $db,string $actor,array $values):array
{
    $data=\Kulturhus\Verein\OutgoingInvoices::normalize($values);unset($data['invoice_format']);
    $settings=new \Kulturhus\Verein\Settings($db,$actor);$issuer=[];
    foreach(['club','tax','bank','paper','texts_invoices'] as $section)$issuer[$section==='texts_invoices'?'texts':$section]=$settings->section($section)['data'];
    $data['issuer']=$issuer;$finance=new \Kulturhus\Verein\Finance($db,$actor);$key=bin2hex(random_bytes(16));
    $positions=array_map(static fn($p)=>['description'=>$p['description'],'amount'=>\Kulturhus\Verein\OutgoingInvoices::amount($p['gross']),'tax_rate'=>$p['rate'],'sphere'=>$p['sphere'],'account'=>$p['account']],$data['positions']);
    $entry=$finance->createEntry(['kind'=>'income','description'=>$data['description'],'counterparty'=>$data['buyer_name'],'category'=>$data['category'],'document_date'=>$data['document_date'],'due_date'=>$data['due_date'],'positions'=>$positions],$key);
    $row=$finance->entry($entry);$id=bin2hex(random_bytes(16));$invoice=['id'=>$id,'club_id'=>$row['club_id'],'kind'=>'invoice','state'=>'issued','number'=>$row['display_number'],'data'=>$data];
    $method=new ReflectionMethod(\Kulturhus\Verein\OutgoingInvoices::class,'document');$doc=$method->invoke(new \Kulturhus\Verein\OutgoingInvoices($db,$actor),$invoice,$invoice['number'],$issuer);
    $document=(new \Kulturhus\Verein\Documents($db,$actor))->generated('legacy.pdf',\Kulturhus\Verein\DocumentPreview::pdf($doc),['title'=>'Historical synthetic invoice','kind'=>'invoice','scope'=>'finance','document_date'=>$data['document_date'],'links'=>['entry:'.$entry]],bin2hex(random_bytes(16)),$doc);
    $db->prepare("INSERT INTO outgoing_invoices(club_id,id,kind,state,payload,entry_id,document_id,number,created_by,created_at,issued_at,request_key,version) VALUES(?,?,'invoice','issued',?,?,?,?,?,?,?,?,3)")->execute([$row['club_id'],$id,json_encode($data,JSON_THROW_ON_ERROR),$entry,$document,$invoice['number'],$actor,gmdate('c'),gmdate('c'),bin2hex(random_bytes(16))]);
    return [$id,$entry];
}
