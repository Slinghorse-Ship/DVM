<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
/** Original correspondence without an imported document: never implies an invoice or payment. */
final class InvoiceMailOnly
{
    public function __construct(private PDO $db,private string $actor){}
    private function access(bool $write=false):array{return Permissions::finance($this->db,$this->actor,$write);}
    public function listing(string $state='active'):array{$a=$this->access();if(!in_array($state,['active','archived'],true))throw new DomainException('Mailansicht prüfen.');$q=$this->db->prepare('SELECT m.* FROM invoice_mail_only m WHERE m.club_id=? AND m.state=? AND NOT EXISTS (SELECT 1 FROM invoice_mail_original_links l WHERE l.club_id=m.club_id AND l.mailbox_key=m.mailbox_key AND l.message_uid=m.message_uid) ORDER BY m.imported_at DESC,m.id LIMIT 200');$q->execute([$a['club_id'],$state]);return $q->fetchAll();}
    /** Keep older messages reachable without loading every mail body into the view. */
    public function page(string $state,string $query,string $page):array
    {
        $a=$this->access();if(!in_array($state,['active','archived'],true))throw new DomainException('Mailansicht prüfen.');
        $query=mb_substr(trim($query),0,150);$where='m.club_id=? AND m.state=? AND NOT EXISTS (SELECT 1 FROM invoice_mail_original_links l WHERE l.club_id=m.club_id AND l.mailbox_key=m.mailbox_key AND l.message_uid=m.message_uid)';$args=[$a['club_id'],$state];
        foreach(preg_split('/\s+/u',$query,-1,PREG_SPLIT_NO_EMPTY)?:[] as $word){$where.=" AND instr(lower(CONCAT(m.subject,' ',m.sender,' ',m.received_at)),lower(?))>0";$args[]=$word;}
        $q=$this->db->prepare('SELECT COUNT(*) FROM invoice_mail_only m WHERE '.$where);$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/10));$current=ctype_digit($page)&&strlen($page)<=6?max(1,min($pages,(int)$page)):1;
        $q=$this->db->prepare('SELECT m.* FROM invoice_mail_only m WHERE '.$where.' ORDER BY m.imported_at DESC,m.id LIMIT 10 OFFSET '.(($current-1)*10));$q->execute($args);
        return ['rows'=>$q->fetchAll(),'total'=>$total,'pages'=>$pages,'page'=>$current,'query'=>$query];
    }
    public function item(string $id):array{$a=$this->access();$q=$this->db->prepare('SELECT * FROM invoice_mail_only WHERE club_id=? AND id=?');$q->execute([$a['club_id'],$id]);$r=$q->fetch();if(!$r)throw new DomainException('E-Mail nicht verfügbar.');return $r;}
    public function original(string $id):string{
        $r=$this->item($id);$q=$this->db->prepare('SELECT * FROM invoice_mail_originals WHERE club_id=? AND mailbox_key=? AND message_uid=?');$q->execute([$r['club_id'],$r['mailbox_key'],$r['message_uid']]);$o=$q->fetch();
        // If a later import also linked documents, do not bypass their combined access restrictions.
        $q=$this->db->prepare('SELECT document_id FROM invoice_mail_original_links WHERE club_id=? AND mailbox_key=? AND message_uid=?');$q->execute([$r['club_id'],$r['mailbox_key'],$r['message_uid']]);$docs=new Documents($this->db,$this->actor);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $doc)$docs->document($doc);
        if(!$o||!is_string($o['bytes'])||!hash_equals($o['sha256'],hash('sha256',$o['bytes'])))throw new DomainException('Originalmail-Prüfsumme stimmt nicht.');return $o['bytes'];
    }
    public function archive(string $id,int $version,bool $archive,string $reason):void{
        $this->db->beginTransaction();try{$a=$this->access(true);$r=$this->item($id);$this->original($id);$reason=trim($reason);if($reason===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason)>1000)throw new DomainException('Bitte einen kurzen Grund angeben.');
            $q=$this->db->prepare('UPDATE invoice_mail_only SET state=?,version=version+1 WHERE club_id=? AND id=? AND version=? AND state=?');$q->execute([$archive?'archived':'active',$a['club_id'],$id,$version,$archive?'active':'archived']);if($q->rowCount()!==1)throw new DomainException('Die E-Mail wurde inzwischen geändert. Bitte neu öffnen.');
            $this->db->prepare("INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)")->execute([$a['club_id'],$a['id'],$archive?'invoice-mail.archived':'invoice-mail.restored',$id,$version+1,gmdate('c')]);
            $this->db->prepare('INSERT INTO invoice_mail_events(club_id,mailbox_key,message_uid,happened_at,status,detail) VALUES(?,?,?,?,?,?)')->execute([$a['club_id'],$r['mailbox_key'],$r['message_uid'],gmdate('c'),$archive?'mail_archived':'mail_restored',$reason]);$this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
}
