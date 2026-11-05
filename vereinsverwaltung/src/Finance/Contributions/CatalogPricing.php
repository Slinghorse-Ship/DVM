<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;

/** Read-only prices from an owned assignment and the currently selected tariff. */
final class CatalogPricing
{
    public function __construct(private PDO $db,private string $actorId){}

    public function quote(string $assignmentId,string $day,string $mode='full',?string $priceDay=null,bool $lock=false):array
    {
        try {
            return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,fn()=>$this->calculate($assignmentId,$day,$mode,$priceDay,$lock));
        } catch (\PDOException $failure) {
            if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarifpreis wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            throw $failure;
        }
    }

    private function calculate(string $assignmentId,string $day,string $mode,?string $priceDay,bool $lock):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$assignmentId)||!in_array($mode,['full','days'],true))throw new DomainException('Eigene Tarifzuweisung und Berechnung auswählen.');
        if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$day))throw new DomainException('Abrechnungsdatum auswählen.');
        SettingsSchema::date($day);
        $actor=Permissions::actor($this->db,$this->actorId);
        if(!in_array($actor['finance_access'],['read','write'],true)||$actor['members_access']==='none')throw new DomainException('Finanz- und Mitgliederleserechte für den persönlichen Tarifpreis erforderlich.');
        $context=(new AssignmentPrices($this->db,$this->actorId))->context($assignmentId,$lock);
        $assignment=$context['assignment'];$catalog=$context['catalog'];$contact=$context['contact'];
        if(!(int)$catalog['active'])throw new DomainException('Aktiven Tarif auswählen.');
        $suffix=$lock?' FOR UPDATE NOWAIT':' FOR SHARE NOWAIT';
        $data=$catalog['data'];$date=new DateTimeImmutable($day,new DateTimeZone('Europe/Berlin'));
        [$start,$end]=self::period($date,$data['interval'],$data['billing'],$assignment['starts_on']);
        $activeStart=max($start,$assignment['starts_on']);$activeEnd=min($end,$assignment['ends_on']??$end);
        if($activeStart>$activeEnd)throw new DomainException('Die Tarifzuweisung liegt außerhalb des gewählten Abrechnungszeitraums.');
        $priceDay??=min(max($day,$activeStart),$activeEnd);
        if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$priceDay))throw new DomainException('Preis-Stichtag auswählen.');
        SettingsSchema::date($priceDay);
        if($priceDay<$activeStart||$priceDay>$activeEnd)throw new DomainException('Preis-Stichtag innerhalb des zugewiesenen Abrechnungszeitraums auswählen.');
        $priceDate=new DateTimeImmutable($priceDay,$date->getTimezone());
        if($mode==='days'&&($data['interval']==='once'||$context['linked']))throw new DomainException('Für diesen Tarif gilt der vollständige Betrag.');
        $assignmentPrice=(new AssignmentPrices($this->db,$this->actorId))->get($assignmentId,$lock);if($assignmentPrice&&($assignmentPrice['mode']==='none'||$context['linked']))$assignmentPrice=null;
        $fixed=$assignmentPrice&&$assignmentPrice['mode']==='fixed';
        $years=0;$membership=null;$memberships=[];
        if(!$fixed&&$data['pricing']==='age'){
            if(($contact['kind']??'')!=='person'||empty($contact['birthday'])||$contact['birthday']>$priceDay)throw new DomainException('Einen persönlichen Geburtstag vor dem Stichtag hinterlegen.');
            $years=(new DateTimeImmutable($contact['birthday'],$date->getTimezone()))->diff($priceDate)->y;
        }elseif(!$fixed&&$data['pricing']==='duration'){
            $statement=$this->db->prepare('SELECT * FROM memberships WHERE club_id=? AND contact_id=? ORDER BY starts_on,id'.$suffix);$statement->execute([$actor['club_id'],$contact['id']]);$memberships=$statement->fetchAll(PDO::FETCH_ASSOC);
            foreach($memberships as$row)if($row['starts_on']<=$priceDay&&($row['ends_on']===null||$row['ends_on']>=$priceDay)&&(!$membership||$row['starts_on']>$membership['starts_on']))$membership=$row;
            if(!$membership||($membership['starts_precision']??'day')!=='day')throw new DomainException('Für die Dauerstaffel eine am Stichtag bestehende Mitgliedschaft mit genauem Eintrittsdatum hinterlegen.');
            $years=(new DateTimeImmutable($membership['starts_on'],$date->getTimezone()))->diff($priceDate)->y;
        }
        $catalogFull=$fixed?null:Settings::price($data,$years);$full=AssignmentPrices::amount($catalogFull??0,$assignmentPrice);$periodDays=self::days($start,$end);$activeDays=self::days($activeStart,$activeEnd);
        $amount=$mode==='days'?intdiv($full*$activeDays+intdiv($periodDays,2),$periodDays):$full;
        $facts=['club'=>$actor['club_id'],'assignment'=>$assignment,'catalog_id'=>$catalog['id'],'catalog_version'=>(int)$catalog['version'],'catalog'=>$data,'contact_id'=>$contact['id'],'contact_version'=>(int)$contact['version'],'contact_name'=>$contact['name'],'birthday'=>$data['pricing']==='age'?$contact['birthday']:null,'membership'=>$membership,'day'=>$day,'price_day'=>$priceDay,'mode'=>$mode,'calculation'=>['years'=>$years,'period'=>[$start,$end],'assigned'=>[$activeStart,$activeEnd],'days'=>[$activeDays,$periodDays],'full_cents'=>$full,'amount_cents'=>$amount]];
        if($assignmentPrice)$facts['assignment_price']=$assignmentPrice;
        usort($memberships,static fn($a,$b)=>[$a['starts_on'],$a['id']]<=>[$b['starts_on'],$b['id']]);
        $source=$facts;foreach(['membership','day','price_day','mode','calculation']as$field)unset($source[$field]);$source['memberships']=$memberships;
        $sourceFingerprint=hash('sha256',json_encode($source,JSON_THROW_ON_ERROR));
        $fresh=Permissions::actor($this->db,$this->actorId);if($fresh['club_id']!==$actor['club_id']||!in_array($fresh['finance_access'],['read','write'],true)||$fresh['members_access']==='none')throw new DomainException('Tarifberechtigung inzwischen geändert.');
        $quote=['assignment_id'=>$assignmentId,'catalog_id'=>$catalog['id'],'catalog_version'=>(int)$catalog['version'],'kind'=>$catalog['kind'],'name'=>$data['name'],'contact_id'=>$contact['id'],'contact_name'=>$contact['name'],'pricing'=>$fixed?'fixed':$data['pricing'],'years'=>$years,'day'=>$day,'price_day'=>$priceDay,'mode'=>$mode,'period_start'=>$start,'period_end'=>$end,'active_start'=>$activeStart,'active_end'=>$activeEnd,'period_days'=>$periodDays,'active_days'=>$activeDays,'full_cents'=>$full,'amount_cents'=>$amount,'can_prorate'=>$data['interval']!=='once'&&!$context['linked'],'data'=>$data,'source_fingerprint'=>$sourceFingerprint,'fingerprint'=>hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR))];
        if($assignmentPrice){$quote['assignment_price']=$assignmentPrice;$quote['catalog_full_cents']=$catalogFull;}
        return $quote;
    }

    public function template(string $assignmentId,string $day,string $mode,string $fingerprint,?string $priceDay=null):array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))throw new DomainException('Tarifpreis zuerst ermitteln.');
        $quote=$this->quote($assignmentId,$day,$mode,$priceDay);
        if(!hash_equals($quote['fingerprint'],$fingerprint))throw new DomainException('Preisgrundlage inzwischen geändert. Tarifpreis erneut ermitteln.');
        if(Permissions::actor($this->db,$this->actorId)['finance_access']!=='write')throw new DomainException('Finanzschreibrecht für den Belegentwurf erforderlich.');
        if($quote['amount_cents']<=0)throw new DomainException('Für diesen Tarifpreis ist kein zahlbarer Beleg erforderlich.');
        return$quote;
    }

    private static function period(DateTimeImmutable $date,string $interval,string $billing,string $assignmentStart):array
    {
        if($interval==='once')return[$assignmentStart,$assignmentStart];
        $months=['month'=>1,'quarter'=>3,'half'=>6,'year'=>12][$interval]??null;
        if($months===null||!in_array($billing,['previous','current','next'],true))throw new DomainException('Tarifzeitraum prüfen.');
        $month=intdiv((int)$date->format('n')-1,$months)*$months+1;
        $start=$date->setDate((int)$date->format('Y'),$month,1);
        if($billing!=='current')$start=$start->modify(($billing==='previous'?'-':'+').$months.' months');
        return[$start->format('Y-m-d'),$start->modify('+'.$months.' months')->modify('-1 day')->format('Y-m-d')];
    }
    private static function days(string $start,string $end):int
    {
        return (int)(new DateTimeImmutable($start,new DateTimeZone('Europe/Berlin')))->diff(new DateTimeImmutable($end,new DateTimeZone('Europe/Berlin')))->days+1;
    }
}
