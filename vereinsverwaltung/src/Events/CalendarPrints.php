<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
final class CalendarPrints
{
    public function __construct(private PDO $db,private string $actor,private CalendarInvitations $invitations){}
    public function preview(string $event,array $groups,array $selected,string $subject,string $message,string $mode):array
    {
        BoardAccess::require($this->db,$this->actor);if(!in_array($mode,['missing','all'],true))throw new DomainException('Postauswahl prüfen.');
        $p=$this->invitations->preview($event,$groups,$selected,$subject,$message);$recipients=[];$missing=[];
        foreach($p['people'] as $person){if($mode==='missing'&&filter_var($person['email'],FILTER_VALIDATE_EMAIL))continue;if(trim($person['address'])===''){$missing[]=$person['name'];continue;}$recipients[]=['id'=>$person['id'],'name'=>$person['name'],'address'=>$person['address']];}
        if(count($recipients)>250)throw new DomainException('Höchstens 250 Briefe je Sammeldruck. Empfänger eingrenzen.');
        return ['invitation'=>$p,'mode'=>$mode,'recipients'=>$recipients,'missing'=>$missing,'hash'=>hash('sha256',json_encode([$p['preview_hash'],$mode,$recipients,$missing],JSON_THROW_ON_ERROR))];
    }
    public function create(string $event,array $groups,array $selected,string $subject,string $message,string $mode,string $hash,string $key):string
    {
        $a=BoardAccess::require($this->db,$this->actor);if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Druckvorschau neu öffnen.');
        $p=$this->preview($event,$groups,$selected,$subject,$message,$mode);if(!hash_equals($p['hash'],$hash)||!$p['recipients'])throw new DomainException('Empfänger oder Termin geändert; Druckvorschau erneut prüfen.');
        $old=DocumentCatalog::query($this->db,'SELECT id,snapshot FROM calendar_prints WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();if($old){if(json_decode($old['snapshot'],true)['hash']!==$hash)throw new DomainException('Druckkennung bereits anders verwendet.');return $old['id'];}
        $club=DocumentCatalog::query($this->db,"SELECT payload FROM club_settings WHERE club_id=? AND section='club'",[$a['club_id']])->fetchColumn();$club=$club?json_decode($club,true):SettingsSchema::defaults('club');
        $render=['invitation_batch'=>true,'club'=>$club,'title'=>$subject,'body'=>$p['invitation']['body'],'date'=>date('d.m.Y'),'recipients'=>$p['recipients']];$pdf=DocumentPreview::pdf($render);
        $this->db->beginTransaction();try{$a=BoardAccess::require($this->db,$this->actor);$fresh=$this->preview($event,$groups,$selected,$subject,$message,$mode);if(!hash_equals($hash,$fresh['hash']))throw new DomainException('Druckdaten inzwischen geändert.');$id=bin2hex(random_bytes(16));
            DocumentCatalog::query($this->db,'INSERT IGNORE INTO calendar_prints(club_id,id,actor_id,event_id,request_key,snapshot,pdf,created_at) VALUES(?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$a['id'],$event,$key,json_encode($p,JSON_THROW_ON_ERROR),$pdf,gmdate('c')]);$stored=DocumentCatalog::query($this->db,'SELECT id,snapshot FROM calendar_prints WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();if(json_decode($stored['snapshot'],true)['hash']!==$hash)throw new DomainException('Druckkennung bereits anders verwendet.');$id=$stored['id'];
            DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,1,?)',[$a['club_id'],$a['id'],'calendar.letters.created',$id,gmdate('c')]);$this->db->commit();return $id;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function file(string $id):array{$a=BoardAccess::require($this->db,$this->actor);$r=DocumentCatalog::query($this->db,'SELECT * FROM calendar_prints WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();if(!$r)throw new DomainException('Druckdatei nicht verfügbar.');return $r;}
    public function listing(string $event):array{$a=BoardAccess::require($this->db,$this->actor);return DocumentCatalog::query($this->db,'SELECT id,created_at,posted_at FROM calendar_prints WHERE club_id=? AND event_id=? ORDER BY created_at DESC LIMIT 50',[$a['club_id'],$event])->fetchAll();}
    public function posted(string $id):void{$a=BoardAccess::require($this->db,$this->actor);$this->file($id);DocumentCatalog::query($this->db,'UPDATE calendar_prints SET posted_at=? WHERE club_id=? AND id=? AND posted_at IS NULL',[gmdate('c'),$a['club_id'],$id]);DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,1,?)',[$a['club_id'],$a['id'],'calendar.letters.posted',$id,gmdate('c')]);}
}
