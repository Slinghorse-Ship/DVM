<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once dirname(__DIR__, 1).'/Integration/Website/WebsiteTechnicalAccess.php';
final class FacebookHistory
{
    public static function preview(PDO $db,string $actor,int $year):array
    {
        if(!WebsiteTechnicalAccess::allowed($db,$actor))throw new DomainException('Website-Technik ist nur für die technische Administration freigegeben.');
        BoardAccess::require($db,$actor);if($year<2000||$year>(int)date('Y'))throw new DomainException('Jahr prüfen.');
        if(getenv('VEREIN_MODE')!=='production')throw new DomainException('Facebook-Abruf nur über die eingerichtete Website-Verbindung.');
        throw new DomainException('Der Meta-Veranstaltungsabruf ist noch nicht eingerichtet. Social-Verbindungen und Anbieterrechte prüfen.');
    }
    public static function import(PDO $db,string $actor,array $rows,array $selected):int
    {
        if(!WebsiteTechnicalAccess::allowed($db,$actor))throw new DomainException('Website-Technik ist nur für die technische Administration freigegeben.');
        $a=BoardAccess::require($db,$actor);if(!$selected||count($selected)>100)throw new DomainException('Ein bis 100 Veranstaltungen auswählen.');$registry=new Registry($db,$actor);$byId=array_column($rows,null,'source_reference');if(array_diff($selected,array_keys($byId)))throw new DomainException('Importauswahl nicht verfügbar.');$count=0;
        foreach(array_unique($selected) as $source){if(DocumentCatalog::query($db,'SELECT 1 FROM appointments WHERE club_id=? AND source_reference=?',[$a['club_id'],$source])->fetchColumn())continue;$registry->saveAppointment(null,0,$byId[$source],[]);++$count;}return $count;
    }
}
