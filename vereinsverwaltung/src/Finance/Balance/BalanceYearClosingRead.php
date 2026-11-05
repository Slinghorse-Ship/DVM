<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Read the genuine first ordinary closing; never accept a financial command. */
final class BalanceYearClosingRead
{
    public function __construct(private PDO $db,private string $actorId){}
    private function actor():array
    {
        $actor=Permissions::actor($this->db,$this->actorId);if(!in_array($actor['finance_access'],['read','write'],true))throw new DomainException('Aktuelle Finanz-Leseberechtigung für den gespeicherten Jahresabschluss erforderlich.',403);return $actor;
    }
    private static function year(mixed $value):int
    {
        if(!is_string($value)||!preg_match('/\A[0-9]{4}\z/D',$value))throw new DomainException('Ursprüngliches Abschlussjahr vollständig auswählen.',422);BalanceCalculation::year((int)$value);return(int)$value;
    }
    private function find(int $year):array
    {
        return BalanceReadSnapshot::run($this->db,function()use($year):array{
            $actor=$this->actor();$q=DocumentCatalog::query($this->db,'SELECT state,version,closed_snapshot,closed_sha256 FROM balance_years WHERE club_id=? AND tax_year=?',[$actor['club_id'],$year]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row||$row['state']!=='closed'||!is_string($row['closed_snapshot'])||!is_string($row['closed_sha256']))throw new DomainException('Gespeicherten ursprünglichen Jahresabschluss nicht gefunden.',404);
            // report() validates the original physical entries and frozen snapshot
            // before applying any current closing correction, in this same read snapshot.
            $current=(new BalanceLedger($this->db,$this->actorId))->report($year);$original=json_decode($row['closed_snapshot'],true,512,JSON_THROW_ON_ERROR);if($current['state']!=='closed'||!is_array($original['review']??null)||(int)$row['version']<2)throw new DomainException('Gespeicherten Jahresabschluss prüfen lassen.',503);
            return ['year'=>$year,'sha256'=>$row['closed_sha256'],'base_closed_version'=>(int)$row['version'],'original'=>$original,'current'=>$current];
        });
    }
    /** Translate only system suffixes attached to actual own bank labels. */
    public function failure(string $message,int $year,array $messages):string
    {
        $text=static fn(string $value):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($value,$messages);$result=$text($message);
        $transactionSuffix='noch nicht im Bilanzjournal berücksichtigt.';$accountSuffix='Bilanzsaldo und erfasste Geldbewegungen stimmen nicht überein.';
        if(!str_contains($message,$transactionSuffix)&&!str_contains($message,$accountSuffix))return$result;
        BalanceCalculation::year($year);$actor=$this->actor();$transactions=DocumentCatalog::query($this->db,'SELECT description FROM finance_transactions WHERE club_id=? AND booked_on>=? AND booked_on<? LIMIT 10001',[$actor['club_id'],$year.'-01-01',($year+1).'-01-01'])->fetchAll(PDO::FETCH_COLUMN);$accounts=DocumentCatalog::query($this->db,'SELECT name FROM finance_accounts WHERE club_id=? LIMIT 10001',[$actor['club_id']])->fetchAll(PDO::FETCH_COLUMN);if(count($transactions)>10000||count($accounts)>10000)return$result;$replace=[];
        foreach($transactions as$label)$replace[$label.': '.$transactionSuffix]=$label.': '.$text($transactionSuffix);
        foreach($accounts as$label)$replace[$label.': '.$accountSuffix]=$label.': '.$text($accountSuffix);
        return strtr($result,$replace);
    }
    public function get(string $year,string $sha256):array
    {
        $this->actor();if(!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))throw new DomainException('Gespeicherte ursprüngliche Abschlussfassung vollständig auswählen.',422);$saved=$this->find(self::year($year));if(!hash_equals($saved['sha256'],$sha256))throw new DomainException('Gespeicherte ursprüngliche Abschlussfassung stimmt nicht überein.',409);return$saved;
    }
    /** Match stored closing facts only. Original version is derived, not separately stored. */
    public function fromSubmitted(array $post):?array
    {
        $actor=$this->actor();foreach(['action','balance_year','version','evidence']as$key)if(!is_string($post[$key]??null))throw new DomainException('Ursprüngliche Abschlussangaben vollständig prüfen.',422);
        if($post['action']!=='balance-close'||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$post['version'])||(int)$post['version']>4294967295)throw new DomainException('Ursprüngliche Abschlussangaben vollständig prüfen.',422);
        try{$saved=$this->find(self::year($post['balance_year']));}catch(DomainException $failure){if($failure->getCode()===404)return null;throw$failure;}
        $review=$saved['original']['review'];if(($review['actor']??null)!==$actor['id']||($review['note']??null)!==trim($post['evidence'])||$saved['base_closed_version']-1!==(int)$post['version'])return null;
        // close() increments this base version once and all base-year mutations
        // require an open year. Later corrections use their separate journal.
        foreach(['complete','valuation','cutoff','tax']as$key)if(($post[$key]??null)!=='1'||($review['checks'][$key]??null)!==true)return null;
        return$saved;
    }
}
