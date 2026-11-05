<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Current status for one authorized contact, without copying the mailing list. */
final class WebsiteContactStatus
{
    public function __construct(private \PDO $db,private string $actorId) {}

    public function forContact(string $id): array
    {
        $contact=(new Registry($this->db,$this->actorId))->contact($id);
        if(trim((string)$contact['email'])==='')return ['state'=>'no-address','matches'=>[],'press'=>[]];
        if(!ProductionRuntime::nativeManager())return ['state'=>'unavailable','matches'=>[],'press'=>[]];
        require_once dirname(__DIR__,2).'/native/bootstrap.php';
        return (new \Kulturhus\Native\Integration\ContactCommunicationStatus($this->db,$this->actorId))->forContact($id);
    }
}
