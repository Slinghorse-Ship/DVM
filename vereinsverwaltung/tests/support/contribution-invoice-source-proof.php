<?php declare(strict_types=1);
require_once __DIR__.'/original-database-hash.php';
function ownContributionInvoiceOriginalFilter(array $keys,string $table):array
{
 $set=$keys[$table]??null;if(!is_array($set)||!$set['fields'])throw new RuntimeException('Owned source primary keys required.');
 if(!$set['rows'])return [' WHERE 0',[]];$fields=[];foreach($set['fields']as$field){if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$field))throw new RuntimeException('Owned source key field required.');$fields[]='`'.$field.'`';}
 $tuple='('.implode(',',array_fill(0,count($fields),'?')).')';$arguments=[];foreach($set['rows']as$row)foreach($row as$value)$arguments[]=$value;
 return [' WHERE ('.implode(',',$fields).') IN ('.implode(',',array_fill(0,count($set['rows']),$tuple)).')',$arguments];
}
function ownContributionInvoiceSourceProof(array $fixture):void
{
 $cfg=\Kulturhus\Native\TestSupport::config();$db=\Kulturhus\Native\TestSupport::db();if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-contribution-invoice-source'||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$cfg['database']??'')||$db->query('SELECT DATABASE()')->fetchColumn()!==$cfg['database'])throw new RuntimeException('Owned disposable invoice proof required.');
 $tables=$fixture['data']['original_tables'];$keys=$fixture['data']['original_keys'];$invoices=[];$model=new \Kulturhus\Verein\ContributionInvoices($db,'own-list-admin');
 foreach($db->query("SELECT entry_id FROM contribution_invoices WHERE club_id='own-list-club' ORDER BY entry_id")->fetchAll(PDO::FETCH_COLUMN)as$entry){$original=$model->currentOriginal($entry);$invoices[$entry]=['document'=>$original['invoice']['document_id'],'state'=>$original['invoice']['state'],'sha256'=>$original['file']['sha256'],'revision'=>(int)$original['file']['revision'],'draft'=>$original['snapshot']['draft']];}
 $counts=[];foreach($tables as$table){if(!preg_match('/\A[a-z][a-z0-9_]*\z/D',$table))throw new RuntimeException('Owned source proof table required.');$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();}
 $whole=ownOriginalDatabaseHash($db,$tables);$originals=ownOriginalDatabaseHash($db,$tables,static fn(string $table):array=>ownContributionInvoiceOriginalFilter($keys,$table));
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');echo json_encode(compact('whole','originals','counts','invoices'),JSON_THROW_ON_ERROR);
}
