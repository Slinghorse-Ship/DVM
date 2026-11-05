<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Finance,Inventory,Settings};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('foreign','b','member',1),('reader','a','member',0)");
$n=0;function ck($v,$m){global $n;if(!$v)throw new RuntimeException($m);echo "PASS $m\n";++$n;}function deny($f,$m){try{$f();}catch(DomainException){ck(true,$m);return;}throw new RuntimeException($m);}
$f=new Finance($db,'admin');$i=new Inventory($db,'admin');$s=new Settings($db,'admin');$key=fn()=>bin2hex(random_bytes(16));$day=date('Y-m-d');
ck($f->cashEnabled(),'handkasse is always available');$cash=$f->createAccount('Cash','cash','100.00',$day);$bank=$f->createAccount('Bank','offline','100.00',$day);
$txData=['account'=>$cash,'booked_on'=>$day,'direction'=>'income','amount'=>'1.00','description'=>'Synthetic cash payment'];$request=$key();$tx=$f->createTransaction($txData,$request);
$entry=$f->createEntry(['kind'=>'income','category'=>'general','description'=>'Synthetic receipt','amount'=>'1.00','document_date'=>$day,'due_date'=>$day],$key());
$item=$i->saveItem('',0,['name'=>'Synthetic cash box','cash_account'=>$cash],$key());
$state=$s->section('club');$data=$state['data'];$data['cash_enabled']=false;$s->save('club',$data,$state['version']);
$after=$s->section('club');ck($f->cashEnabled()&&!array_key_exists('cash_enabled',$after['data']),'legacy cash switch is ignored and no longer exposed');ck(count($f->paymentAccounts())===2&&in_array($cash,array_column($f->paymentAccounts(),'id'),true),'cash and bank accounts stay selectable');ck(count($f->accounts())===2&&$f->cashbook($cash,$day,$day)['closing']===10100,'cash balance and cashbook retained');
$again=$f->createTransaction(array_replace($txData,['description'=>'Synthetic second cash payment']),$key());ck($f->transactionDetail($again)['account_id']===$cash,'new cash transaction works');
$old=$i->item($item);$i->saveItem($item,$old['version'],['name'=>'Renamed existing cash box','code'=>$old['code']],$key());ck($i->item($item)['cash_account']===$cash,'cash inventory link remains editable');
$normal=$f->createTransaction(array_replace($txData,['account'=>$bank,'description'=>'Synthetic bank payment']),$key());ck($f->transactionDetail($normal)['account_id']===$bank,'new bank transaction works');ck((new Finance($db,'foreign'))->cashEnabled(),'other club unaffected');deny(fn()=>(new Settings($db,'reader'))->save('club',$data,1),'member cannot change club settings');
$f->allocate($tx,1,$entry,1,'1.00',$key());ck($f->entry($entry)['payment_state']==='paid','cash payment remains assignable');$f->reverse($entry,$f->entry($entry)['version'],$day,'Synthetic correction');ck($f->cashbook($cash,$day,$day)['closing']===10100,'cash correction remains possible');
ck(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok','database integrity');echo "$n cash checks passed; synthetic only\n";
