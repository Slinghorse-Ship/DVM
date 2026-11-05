<?php declare(strict_types=1);
require __DIR__.'/balance-period-sources.php';
use Kulturhus\Verein\{BalanceLedger,BalanceSources,BalancePeriodLedger,BalancePeriodSources,Finance,Documents,SettingsSchema,ExpenseReimbursements,BalancePeriodExport};
use Kulturhus\Verein\Tests\MySqlFixture;
$base=$n;$n=0;
$db->exec("INSERT INTO clubs VALUES('invoice-carry','Synthetic Carry');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('carry-admin','invoice-carry','treasurer',1)");
$db->prepare("INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES('invoice-carry','tax',1,?,?)")->execute([json_encode(array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','input_tax_eligibility'=>'no'])),gmdate('c')]);
$f=new Finance($db,'carry-admin');$bank=$f->createAccount('Synthetic bank','offline','1000','2025-01-01');$c=new BalanceLedger($db,'carry-admin');$cs=new BalanceSources($db,'carry-admin');$c->create(2025);$r=$c->report(2025);
$bankKey=array_key_first($cs->bankAccounts());$c->setup(2025,$r['version'],$r['accounts'],[['account'=>$bankKey,'debit_cents'=>100000,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>100000,'sphere'=>'']],'equity','Synthetic bank opening',true);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$entries=[];$first=[];$last=[];
$db->exec("INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES('carry-mara','invoice-carry','person','Mara Synthetic','mara@example.invalid','Synthetic road'); INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES('carry-member','invoice-carry','carry-mara','M-1','aktiv','2025-01-01')");
foreach(['expense','income','private'] as $kind){
    $id=$f->createEntry(['kind'=>$kind==='private'?'expense':$kind,'description'=>'Synthetic '.$kind,'amount'=>'200','document_date'=>'2025-12-15','due_date'=>'2026-01-15','category'=>'general',
        'positions'=>[['description'=>'Synthetic original','amount'=>'200','tax_rate'=>'0','sphere'=>'4','account'=>$kind==='income'?'41840':'63000','input_tax'=>$kind==='income'?'unknown':'none']]],keyId());
    (new Documents($db,'carry-admin'))->upload('original.png',$png,['title'=>'Synthetic original','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$id]],keyId());
    if($kind==='private')(new ExpenseReimbursements($db,'carry-admin'))->createForEntry($id,'carry-mara','member',keyId());
    $opts=['recognised_on'=>'2025-12-15','private_paid_on'=>$kind==='private'?'2025-12-16':''];$p=$cs->preview(2025,'invoice',$id,$opts);check(!$p['issues'],'calendar original available '.$kind.': '.implode(' ',$p['issues']));
    $c->importSource(2025,$c->report(2025)['version'],'invoice',$id,$opts,$p['hash'],true);$entries[$kind]=$id;
}
$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$c->close(2025,$c->report(2025)['version'],$all,'Synthetic board closing');
$old=[];foreach(['balance_entries','balance_sources','balance_source_history','balance_years'] as $table)$old[$table]=MySqlFixture::rows($db,$table);
$l=new BalancePeriodLedger($db,'carry-admin');$s=new BalancePeriodSources($db,'carry-admin');$period=['start'=>'2026-01-01','end'=>'2026-06-30'];$pid=$l->create($period,'Synthetic transition period',true);
$pay=function(string $kind,string $date,string $amount,string $target)use($f,$bank,$entries,$l,$s):array{
    $tx=$f->createTransaction(['account'=>$bank,'booked_on'=>$date,'direction'=>$kind==='income'?'income':'expense','amount'=>$amount,'counterparty'=>'Synthetic counterparty','description'=>'Synthetic carried payment'],keyId());
    $entry=$entries[$kind];$f->allocate($tx,1,$entry,(int)$f->entry($entry)['version'],$amount,keyId());$r=$l->report($target,0,false);$p=$s->preview($r['period'],'transaction',$tx);
    check(!$p['issues'],'carried invoice payment preview '.$kind.': '.implode(' ',$p['issues']));
    check($p['snapshot']['invoices'][$entry]['calendar_year']===2025,'payment retains original calendar provenance '.$kind);
    $l->importSource($target,$r['version'],'transaction',$tx,[],$p['hash'],true);return [$tx,$l->report($target)];
};
foreach(array_keys($entries) as $kind){[$tx,$r]=$pay($kind,'2026-02-01','75',$pid);$first[$kind]=$tx;
    check($r['calculation']['annual_result_cents']===0,'settlement never repeats previous expense/revenue '.$kind);
    $account=$cs->binding('invoice',$entries[$kind])['snapshot']['settlement_account'];
    check(abs($r['calculation']['accounts'][$account]['closing_cents'])===12500,'exact open balance remains '.$kind);
}
$l->close($pid,$r['version'],$r['journal_sha256'],$all,'Synthetic intermediate close');$next=$l->create(['start'=>'2026-07-01','end'=>'2026-12-31'],'Synthetic follow-up period',true);
foreach(array_keys($entries) as $kind){[$tx,$r]=$pay($kind,'2026-08-01','125',$next);$last[$kind]=$tx;
    $account=$cs->binding('invoice',$entries[$kind])['snapshot']['settlement_account'];check($r['calculation']['accounts'][$account]['closing_cents']===0&&$r['calculation']['annual_result_cents']===0,'second dated period clears old balance without new result '.$kind);
}
check(!$r['issues'],'closed calendar and two dated periods reconcile');
$db->beginTransaction();try{
    $db->prepare("UPDATE accounting_positions SET description='Synthetic changed original' WHERE club_id='invoice-carry' AND entry_id=?")->execute([$entries['expense']]);
    check((bool)$s->preview($r['period'],'transaction',$last['expense'])['issues'],'changed calendar original blocks later payment approval');
    check((bool)$l->report($next)['issues'],'changed predecessor blocks current closing');
}finally{$db->rollBack();}
$l->close($next,$r['version'],$r['journal_sha256'],$all,'Synthetic final settlement');
check(str_contains((new BalancePeriodExport($db,'carry-admin'))->build($next)['files']['pruefverlauf.json'],'calendar_year'),'export preserves carried invoice evidence');
foreach($old as $table=>$rows)check(MySqlFixture::rows($db,$table)===$rows,'payments never rewrite '.$table);
check(!MySqlFixture::foreignKeyErrors($db),'carried payment references tenant bound');
echo "$n calendar invoice settlement checks passed plus $base reused checks; synthetic MySQL only\n";
