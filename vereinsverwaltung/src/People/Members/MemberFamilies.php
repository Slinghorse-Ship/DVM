<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Explicit links only: shared surnames, addresses or emails never imply a family. */
trait MemberFamilies
{
    public function families():array
    {
        $a=$this->actor();if($a['role']!=='members')return [];
        $q=$this->db->prepare('SELECT f.*,c.name payer_name FROM member_families f JOIN contacts c ON c.club_id=f.club_id AND c.id=f.payer_contact_id WHERE f.club_id=? ORDER BY f.name,f.id');$q->execute([$a['club_id']]);return $q->fetchAll();
    }
    public function family(string $contactId):?array
    {
        $c=$this->contact($contactId);$a=$this->actor();if($a['role']!=='members')return null;
        $q=$this->db->prepare('SELECT f.*,c.name payer_name FROM member_family_people p JOIN member_families f ON f.club_id=p.club_id AND f.id=p.family_id JOIN contacts c ON c.club_id=f.club_id AND c.id=f.payer_contact_id WHERE p.club_id=? AND p.contact_id=?');$q->execute([$c['club_id'],$contactId]);$f=$q->fetch();if(!$f)return null;
        $q=$this->db->prepare('SELECT c.id,c.name FROM member_family_people p JOIN contacts c ON c.club_id=p.club_id AND c.id=p.contact_id WHERE p.club_id=? AND p.family_id=? ORDER BY c.name,c.id');$q->execute([$c['club_id'],$f['id']]);$f['people']=$q->fetchAll();return $f;
    }
    public function assignFamily(string $contactId,string $expectedFamily,string $target,string $name=''):void
    {
        $this->transaction(fn()=>$this->assignFamilyLocked($contactId,$expectedFamily,$target,$name));
    }
    private function assignFamilyLocked(string $contactId,string $expectedFamily,string $target,string $name=''):void
    {
            $a=$this->actor(true);$c=$this->contact($contactId);if($c['kind']!=='person')throw new DomainException('Familien können nur Personen zugeordnet werden.');
            $old=$this->family($contactId);if(($old['id']??'')!==$expectedFamily)throw new DomainException('Familienzuordnung inzwischen geändert. Bitte neu laden.');
            if($target===($old['id']??'')&&$target!=='new')return;
            if($old&&$old['payer_contact_id']===$contactId)throw new DomainException('Zuerst einen anderen Beitragszahler für die bisherige Familie festlegen.');
            if($target==='new'){
                $name=trim($name);if($name===''||strlen($name)>200)throw new DomainException('Bitte einen Familiennamen angeben.');$target=bin2hex(random_bytes(16));
                $this->db->prepare('INSERT INTO member_families(club_id,id,name,payer_contact_id) VALUES(?,?,?,?)')->execute([$a['club_id'],$target,$name,$contactId]);
            }elseif($target!==''){
                $q=$this->db->prepare('SELECT 1 FROM member_families WHERE club_id=? AND id=?');$q->execute([$a['club_id'],$target]);if(!$q->fetchColumn())throw new DomainException('Familie nicht verfügbar.');
            }
            $this->db->prepare('DELETE FROM member_family_people WHERE club_id=? AND contact_id=?')->execute([$a['club_id'],$contactId]);
            if($target!=='')$this->db->prepare('INSERT INTO member_family_people VALUES(?,?,?)')->execute([$a['club_id'],$contactId,$target]);
            foreach(array_unique(array_filter([$old['id']??'', $target])) as $fid)$this->db->prepare('UPDATE member_families SET version=version+1 WHERE club_id=? AND id=?')->execute([$a['club_id'],$fid]);
            $this->audit($a,'family.assigned.'.$target,$contactId,(int)$c['version']);
    }
    public function saveFamily(string $contactId,int $version,string $name,string $payer):void
    {
        $this->transaction(function()use($contactId,$version,$name,$payer){
            $a=$this->actor(true);$f=$this->family($contactId);$name=trim($name);
            if(!$f||(int)$f['version']!==$version)throw new DomainException('Familie inzwischen geändert. Bitte neu laden.');
            if($name===''||strlen($name)>200||!in_array($payer,array_column($f['people'],'id'),true))throw new DomainException('Familienname und Beitragszahler aus dieser Familie auswählen.');
            $this->db->prepare('UPDATE member_families SET name=?,payer_contact_id=?,version=version+1 WHERE club_id=? AND id=?')->execute([$name,$payer,$a['club_id'],$f['id']]);$this->audit($a,'family.updated',$f['id'],$version+1);
        });
    }
}
