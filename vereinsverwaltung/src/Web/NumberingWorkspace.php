<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use PDO;
use Kulturhus\Verein\Settings as ClubSettings;
use Kulturhus\Verein\SettingsSchema;
use Kulturhus\Verein\Settings\{GeneralRequests,NumberingContext};
final class NumberingWorkspace
{
    public static function current(PDO $db,string $actorId,mixed $date):array
    {
        $own=!$db->inTransaction();
        try{
            if($own)$db->exec('START TRANSACTION');$actor=(new GeneralRequests($db,$actorId))->assertAccess('numbers');$current=(new ClubSettings($db,$actorId))->section('numbers');$context=null;$error='';
            try{if(!is_string($date)||strlen($date)!==10)throw new \DomainException();SettingsSchema::date($date);$context=NumberingContext::read($db,$actor['club_id'],$current['data'],$date);}
            catch(\DomainException){$error='Gültiges Beispieldatum auswählen.';}
            if($own)$db->commit();return['actor'=>$actor,'current'=>$current,'context'=>$context,'date'=>$date,'error'=>$error,'can_write'=>$actor['finance_access']==='write'];
        }catch(\Throwable $failure){if($own&&$db->inTransaction())$db->rollBack();throw$failure;}
    }
}
