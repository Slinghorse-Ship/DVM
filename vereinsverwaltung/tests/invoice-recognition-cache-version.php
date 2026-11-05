<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Documents,InvoiceRecognition};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('test','Synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('test','test','member',1)");
$docs=new Documents($db,'test');$original='<Invoice xmlns="urn:synthetic"/>';
$id=$docs->upload('synthetic.xml',$original,['title'=>'Synthetic parser cache','kind'=>'receipt','scope'=>'finance','links'=>[]],bin2hex(random_bytes(16)));
$hash=hash('sha256',$original);$prior=['state'=>'ready','fields'=>[],'items'=>[['name'=>'Incomplete old result']]];
$db->prepare("INSERT INTO invoice_recognition(club_id,document_id,revision,sha256,parser_version,state,result_json,lease_token,lease_until,updated_at,actor_id) VALUES(?,?,1,?,?,'ready',?,'',0,?,'test')")
 ->execute(['test',$id,$hash,(string)((int)InvoiceRecognition::VERSION-1),json_encode($prior),gmdate('c')]);
$service=new InvoiceRecognition($db,'test');
if($service->result($id)!==null)throw new RuntimeException('Old incomplete parser cache is still offered');
$db->prepare('UPDATE invoice_recognition SET parser_version=?')->execute([InvoiceRecognition::VERSION]);
if(($service->result($id)['items'][0]['name']??'')!=='Incomplete old result')throw new RuntimeException('Current matching cache cannot be read');
$db->exec("UPDATE invoice_recognition SET sha256='changed'");
if($service->result($id)!==null)throw new RuntimeException('Changed original was accepted');
if($docs->file($id)['bytes']!==$original||(int)$db->query('SELECT count(*) FROM finance_entries')->fetchColumn()!==0)throw new RuntimeException('Cache inspection changed original or finances');
echo "PASS outdated parser result invalidated; current exact cache reusable; changed original rejected; no financial writes\n";
