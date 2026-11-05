<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,Registry};

/** A narrow, current read for an authorized member profile; no recipient list or tokens. */
final class ContactCommunicationStatus
{
    public function __construct(private PDO $db,private string $actor) {}

    public function forContact(string $id):array
    {
        $contact=(new Registry($this->db,$this->actor))->contact($id);
        $actor=Permissions::actor($this->db,$this->actor);
        $email=mb_strtolower(trim((string)$contact['email']));
        if($email==='')return ['state'=>'no-address','matches'=>[],'press'=>[]];
        $club=ManagerBridge::clubKey($actor['club_id']);
        $matches=Db::run($this->db,'SELECT status,confirmed_at,unsubscribed_at FROM khn_newsletter_subscribers WHERE club_id=? AND email=? LIMIT 1',[$club,$email])->fetchAll();
        $press=Db::run($this->db,'SELECT outlet,active FROM khn_press_contacts WHERE club_id=? AND email=? LIMIT 1',[$club,$email])->fetchAll();
        $count=(int)Db::run($this->db,'SELECT COUNT(*) FROM contacts WHERE club_id=? AND archived_at IS NULL AND lower(trim(email))=?',[$actor['club_id'],$email])->fetchColumn();
        return ['state'=>$count>1?'ambiguous':'available','matches'=>$matches,'press'=>$press,'shared_address_profiles'=>$count];
    }
}
