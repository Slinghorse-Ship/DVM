<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
/** A mail is complete only when every linked document is handled and readable. */
final class InvoiceMailWorkflow
{
 public function __construct(private PDO $db,private string $actor){}
 public function summary(string $document,string $key,string $uid):array
 {
  $docs=new Documents($this->db,$this->actor);$anchor=$docs->document($document);
  $q=$this->db->prepare('SELECT document_id FROM invoice_mail_original_links WHERE club_id=? AND mailbox_key=? AND message_uid=? ORDER BY document_id');$q->execute([$anchor['club_id'],$key,$uid]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
  if(!in_array($document,$ids,true))throw new \DomainException('Originalmail nicht verfügbar.');
  $rows=[];$counts=['open'=>0,'assigned'=>0,'archived'=>0];
  foreach($ids as $id){$doc=$docs->document($id);$state=$doc['state']==='archived'?'archived':(array_filter($doc['links'],fn($l)=>$l['source_type']==='entry')?'assigned':'open');++$counts[$state];$rows[]=['id'=>$id,'title'=>$doc['title'],'status'=>$state];}
  return ['documents'=>$rows,'counts'=>$counts,'total'=>count($rows),'complete'=>count($rows)>0&&$counts['open']===0];
 }
}
