<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Read today's actual source and prepared choices; never manufacture a first receipt. */
final class BalanceMappingCurrentRead
{
    public function __construct(private PDO $db,private string $actorId){}
    public function get(string $year):array
    {
        if(!preg_match('/\A[0-9]{4}\z/D',$year))throw new DomainException('Aktuelles Zuordnungsjahr vollständig auswählen.',422);BalanceCalculation::year((int)$year);
        return BalanceReadSnapshot::run($this->db,function()use($year):array{
            $actor=Permissions::actor($this->db,$this->actorId);if(!in_array($actor['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.',403);
            $report=(new BalanceLedger($this->db,$this->actorId))->report((int)$year);
            $preparation=null;
            if(in_array($report['state'],['open','closed'],true)){
                $report['asset_schedule']=(new BalanceAssetSchedule($this->db,$this->actorId))->build((int)$year);
                $preparation=(new BalanceMappingReview($this->db,$this->actorId))->read($report);
            }
            return['year'=>(int)$year,'report'=>$report,'preparation'=>$preparation];
        });
    }
    /** Translate only a system prefix attached to an actual own account name. */
    public function failure(string $message,int $year,array $messages):string
    {
        $text=static fn(string$s):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($s,$messages);$prefix='Unterstützte XML-Zuordnung wählen: ';$result=$text($message);if(!str_starts_with($message,$prefix))return$result;
        BalanceCalculation::year($year);$actor=Permissions::actor($this->db,$this->actorId);if(!in_array($actor['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.',403);
        $json=DocumentCatalog::query($this->db,'SELECT accounts FROM balance_years WHERE club_id=? AND tax_year=?',[$actor['club_id'],$year])->fetchColumn();if(!is_string($json))return$result;$accounts=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($accounts)||count($accounts)>500)return$result;
        foreach($accounts as$account)if(is_string($account['name']??null)&&$message===$prefix.$account['name'])return$text($prefix).$account['name'];return$result;
    }
}
