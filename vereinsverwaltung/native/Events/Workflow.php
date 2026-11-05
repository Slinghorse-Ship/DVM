<?php declare(strict_types=1);
namespace Kulturhus\Native\Events;

use PDO;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Cms\{ClubPublicationLock,Pages};

/** Independent provider choices never rewrite existing events, orders or publication rights. */
final class Workflow
{
    use ClubPublicationLock;
    public const SOURCES = ['facebook'=>'Facebook · Meta-Abgleich', 'eventbrite'=>'Eventbrite · direkter Abgleich', 'local'=>'Eigene Website-Termine'];
    public const TICKETS = ['eventbrite'=>'Eventbrite', 'native'=>'Eigenes Ticketsystem', 'none'=>'Kein Ticketverkauf'];
    public const PAYMENTS = ['sumup'=>'SumUp Online-Checkout', 'stripe'=>'Stripe Checkout', 'none'=>'Nur kostenlose Tickets'];
    public function __construct(private PDO $db, private Access $access) {}

    public static function forClub(PDO $db, string $club, bool $current=false): array
    {
        $row=Db::run($db,'SELECT version,source,tickets,payment,automatic_publication FROM native_event_workflows WHERE club_id=?'.($current&&$db->inTransaction()?' FOR SHARE':''),[$club])->fetch();
        return $row ? ['version'=>(int)$row['version'],'source'=>$row['source'],'tickets'=>$row['tickets'],'payment'=>$row['payment'],'automatic_publication'=>(bool)$row['automatic_publication']]
            : ['version'=>0,'source'=>'eventbrite','tickets'=>'eventbrite','payment'=>'none','automatic_publication'=>false];
    }
    public function get(): array
    {
        $actor=$this->access->require('website','read');
        return self::forClub($this->db,$actor['club_id']);
    }
    public function save(array $input, int $version): array
    {
        $club=$this->access->require('website','manage')['club_id'];
        foreach(['source'=>self::SOURCES,'tickets'=>self::TICKETS,'payment'=>self::PAYMENTS] as $field=>$choices)
            if(!is_string($input[$field]??null)||!isset($choices[$input[$field]]))throw new \DomainException('Veranstaltungsquelle, Tickets und Zahlungsanbieter auswählen.');
        if(!is_bool($input['automatic_publication']??null)||$version<0||$version>=2147483647)throw new \DomainException('Veranstaltungsfassung und Veröffentlichungswahl prüfen.');
        return Db::atomic($this->db,function()use($input,$version,$club):array{
            $actor=$this->access->require('website','manage');
            if($actor['club_id']!==$club)throw new \DomainException('Vereinszuordnung geändert.');
            $this->lockClub();
            if($input['automatic_publication'])$this->access->require('website','publish');
            if(self::forClub($this->db,$club)['version']!==$version)throw new \DomainException('Veranstaltungsablauf inzwischen geändert. Bitte neu laden.');
            Db::run($this->db,'INSERT INTO native_event_workflows(club_id,version,source,tickets,payment,automatic_publication,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),source=VALUES(source),tickets=VALUES(tickets),payment=VALUES(payment),automatic_publication=VALUES(automatic_publication),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$club,$version+1,$input['source'],$input['tickets'],$input['payment'],(int)$input['automatic_publication'],$actor['id']]);
            Db::run($this->db,'INSERT INTO native_event_workflow_history(club_id,version,payload_json,actor_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$club,$version+1,json_encode($input,JSON_THROW_ON_ERROR),$actor['id']]);
            Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);
            return self::forClub($this->db,$club);
        });
    }
    public function requireSource(string $source): array
    {
        $this->access->require('website','edit');
        $state=$this->get();
        if($state['source']!==$source)throw new \DomainException('Diese Veranstaltungsquelle ist nicht ausgewählt.');
        return $state;
    }
    /** Called inside the same import transaction; permission failures roll the batch back. */
    public function finishImport(array $result, string $source, int $version, string $club): array
    {
        if(!$this->db->inTransaction())throw new \LogicException('Automatische Veröffentlichung benötigt den Abgleichvorgang.');
        $this->lockClub();
        $state=$this->requireSource($source);
        if($this->access->club()!==$club||$state['version']!==$version)throw new \DomainException('Veranstaltungsablauf während des Abgleichs geändert.');
        if(!$state['automatic_publication'])return $result;
        $this->access->require('website','publish');
        $pages=new Pages($this->db,$this->access);
        foreach($result as &$row){
            if($row['status']!=='draft')continue;
            $page=$pages->get($row['id']);
            $pages->publish($page['id'],$page['version']);
            $row['status']='published';
        }
        unset($row);
        return $result;
    }
}
