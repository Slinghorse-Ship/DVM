<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Public projection of the two club-owned annual membership tariffs. */
final class ContributionRates
{
    public static function all(PDO $db,string $club,bool $current=false):array
    {
        $q=$db->prepare('SELECT r.membership_type,c.id,c.payload,c.version,c.active FROM membership_contribution_rates r JOIN settings_catalog c ON c.club_id=r.club_id AND c.id=r.catalog_id WHERE r.club_id=?'.($current?' FOR SHARE NOWAIT':''));
        $q->execute([$club]);$rates=[];
        foreach($q as $row){$data=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);self::validate($data,(bool)$row['active']);$rates[$row['membership_type']]=['id'=>$row['id'],'version'=>(int)$row['version'],'amount_cents'=>Finance::cents($data['amount'])];}
        if(!isset($rates['single'],$rates['family']))throw new DomainException('Mitgliedsbeiträge sind noch nicht eingerichtet.');
        return $rates;
    }
    public static function validate(array $data,bool $active):void
    {
        if(!$active||$data['pricing']!=='fixed'||$data['interval']!=='year'||$data['billing']!=='current'||$data['tax_rate']!=='0'||Finance::cents($data['amount'])<=0)
            throw new DomainException('Der verknüpfte Mitgliedsbeitrag muss aktiv sein: positiver Festbetrag pro aktuellem Jahr ohne Umsatzsteuer.');
    }
    public static function linked(PDO $db,string $club,string $id):bool
    {
        $q=$db->prepare('SELECT 1 FROM membership_contribution_rates WHERE club_id=? AND catalog_id=?');$q->execute([$club,$id]);return (bool)$q->fetchColumn();
    }
    public static function label(int $cents):string{return number_format($cents/100,2,',','.').' €';}
    public static function applicationLabel(array $answers):string
    {
        $amount=isset($answers['annual_fee_cents'])?self::label((int)$answers['annual_fee_cents']).' jährlich':'Betrag nicht dokumentiert';
        return match($answers['membership_type']??'single'){'family'=>'Familie · '.$amount,'family_member'=>'Familienangehöriger · im Familienbeitrag enthalten ('.$amount.')',default=>'Einzelmitglied · '.$amount};
    }
}
