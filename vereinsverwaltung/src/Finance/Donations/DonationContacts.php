<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
trait DonationContacts
{
    private function donationSource(string $type,string $id,bool $lock=false):array
    {
        if(!in_array($type,['entry','goods'],true))throw new DomainException('Spendenart prüfen.');
        if($lock){
            $a=$this->actor(true);$table=$type==='goods'?'goods_donations':'finance_entries';
            try{$q=$this->db->prepare('SELECT * FROM '.$table.' WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);$row=$q->fetch();}
            catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Spendenbeleg wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw $failure;}
            if(!$row)throw new DomainException($type==='goods'?'Sachspende nicht verfügbar.':'Beleg nicht verfügbar.',404);
        }else $row=$type==='goods'?$this->goodsEntry($id):$this->entry($id);
        if($type==='entry'&&$row['kind']!=='donation')throw new DomainException('Geldspende erforderlich.');return $row;
    }
    public function donationContact(string $type,string $id):?array
    {
        $a=$this->actor();$this->donationSource($type,$id);
        $q=$this->db->prepare('SELECT c.id,c.name,c.address FROM donation_contacts d JOIN contacts c ON c.club_id=d.club_id AND c.id=d.contact_id WHERE d.club_id=? AND d.source_type=? AND d.source_id=?');$q->execute([$a['club_id'],$type,$id]);return $q->fetch()?:null;
    }
    public function assignDonationContact(string $type,string $id,int $version,string $contact):void
    {
        $this->transaction(function()use($type,$id,$version,$contact){$a=$this->actor(true);$r=$this->donationSource($type,$id,true);
            if((int)$r['version']!==$version)throw new DomainException('Spende inzwischen geändert. Bitte neu laden.',409);
            (new Registry($this->db,$this->actorId))->contact($contact);
            $this->db->prepare('INSERT INTO donation_contacts VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE contact_id=VALUES(contact_id)')->execute([$a['club_id'],$type,$id,$contact]);
            $table=$type==='goods'?'goods_donations':'finance_entries';$changed=$this->db->prepare('UPDATE '.$table.' SET version=version+1 WHERE club_id=? AND id=? AND version=?');$changed->execute([$a['club_id'],$id,$version]);if($changed->rowCount()!==1)throw new DomainException('Spende inzwischen geändert. Bitte neu laden.',409);
            $this->audit($a,'donation.contact.assigned',$type.':'.$id.':'.$contact,(int)$r['version']+1);
        });
        DonationPreparation::afterSource($this->db,$this->actorId,$type,$id);
    }
}
