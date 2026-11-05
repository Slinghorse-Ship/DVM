<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Shared\Db;
use PDO;
use PDOException;

final class PressContacts
{
    public function __construct(private PDO $db, private Access $access) {}

    public function list(bool $activeOnly = false): array
    {
        $this->access->require('press','read');
        return Db::run($this->db,'SELECT id,email,name,outlet,active,version,updated_at FROM khn_press_contacts WHERE club_id=?'.($activeOnly?' AND active=1':'').' ORDER BY outlet,name,id',[$this->access->club()])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeIds(array $ids): array
    {
        $this->access->require('press','read');$ids=PressRecipientCatalogue::ids($ids);
        if(!$ids)return [];
        $in=implode(',',array_fill(0,count($ids),'?'));
        return Db::run($this->db,"SELECT id FROM khn_press_contacts WHERE club_id=? AND active=1 AND id IN ($in)".($this->db->inTransaction()?' FOR SHARE':''),[$this->access->club(),...$ids])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function get(string $id): array
    {
        $actor=$this->access->require('press','read');Content::id($id);
        $row=Db::run($this->db,'SELECT id,email,name,outlet,active,version,updated_at FROM khn_press_contacts WHERE club_id=? AND id=?',[$actor['club_id'],$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('Pressekontakt nicht verfügbar.');$row['active']=(bool)$row['active'];$row['version']=(int)$row['version'];return$row;
    }

    /** One exact versioned choice; a conflict leaves all selected originals unchanged. */
    public function setActive(array $selection,bool $active,bool $confirmed): int
    {
        if(!$confirmed)throw new DomainException('Gemeinsame Kontaktänderung bestätigen.');$selection=SubscriberSelection::versions($selection);
        if(!$selection)throw new DomainException('Pressekontakte auswählen.');
        return Db::atomic($this->db,function()use($selection,$active):int{
            $a=$this->access->require('press','edit');$rows=[];
            foreach($selection as$id=>$version){$r=Db::run($this->db,'SELECT id,version,active FROM khn_press_contacts WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetch(PDO::FETCH_ASSOC);if(!$r||(int)$r['version']!==$version)throw new DomainException('Ein ausgewählter Kontakt wurde inzwischen geändert. Auswahl prüfen.');$rows[]=$r;}
            $changed=0;foreach($rows as$r){if((bool)$r['active']===$active)continue;Db::run($this->db,'UPDATE khn_press_contacts SET active=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?',[(int)$active,$a['club_id'],$r['id']]);$changed++;}
            $this->access->require('press','edit');return$changed;
        });
    }

    public function save(array $data, int $expectedVersion = 0): array
    {
        $this->access->require('press','edit');
        $email = Content::email($data['email'] ?? '');
        $name = Content::text($data['name'] ?? '',200,true);
        $outlet = Content::text($data['outlet'] ?? '',200);
        $active = $data['active'] ?? true;
        if (!is_bool($active)) throw new DomainException('Kontaktstatus prüfen.');
        try {
            return Db::atomic($this->db,function () use ($data,$expectedVersion,$email,$name,$outlet,$active): array {
                $this->access->require('press','edit');
                $id = empty($data['id'])?bin2hex(random_bytes(16)):Content::id($data['id']);
                $row = empty($data['id'])?null:Db::run($this->db,'SELECT version FROM khn_press_contacts WHERE club_id=? AND id=? FOR UPDATE',[$this->access->club(),$id])->fetch(PDO::FETCH_ASSOC);
                if ((!empty($data['id']) && !$row) || ($row?(int)$row['version']:0) !== $expectedVersion) throw new DomainException('Kontakt inzwischen geändert oder nicht verfügbar.');
                $now = gmdate('Y-m-d H:i:s');
                if ($row) Db::run($this->db,'UPDATE khn_press_contacts SET email=?,name=?,outlet=?,active=?,version=version+1,updated_at=? WHERE club_id=? AND id=?',[$email,$name,$outlet,(int)$active,$now,$this->access->club(),$id]);
                else Db::run($this->db,'INSERT INTO khn_press_contacts(club_id,id,email,name,outlet,active,version,updated_at) VALUES(?,?,?,?,?,?,1,?)',[$this->access->club(),$id,$email,$name,$outlet,(int)$active,$now]);
                return ['id'=>$id,'email'=>$email,'name'=>$name,'outlet'=>$outlet,'active'=>$active,'version'=>$expectedVersion+1,'updated_at'=>$now];
            });
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') throw new DomainException('Diese E-Mail-Adresse ist bereits im Presseverteiler vorhanden.');
            throw $error;
        }
    }
}
