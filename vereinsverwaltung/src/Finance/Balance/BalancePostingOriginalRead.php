<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Read an actual saved posting; this never accepts or replays a financial command. */
final class BalancePostingOriginalRead
{
    public function __construct(private PDO $db,private string $actorId){}
    private function actor():array
    {
        $actor=Permissions::actor($this->db,$this->actorId);if(!in_array($actor['finance_access'],['read','write'],true))throw new DomainException('Aktuelle Finanz-Leseberechtigung für die gespeicherte Buchung erforderlich.',403);return $actor;
    }
    private static function id(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Gespeicherte Buchungskennung vollständig auswählen.',422);return $value;
    }
    private static function year(mixed $value):int
    {
        if(!is_string($value)||!preg_match('/\A[0-9]{4}\z/D',$value))throw new DomainException('Ursprüngliches Buchungsjahr vollständig auswählen.',422);$year=(int)$value;BalanceCalculation::year($year);return $year;
    }
    private static function field(array $input,string $name):string
    {
        if(!is_string($input[$name]??null))throw new DomainException('Ursprüngliche Buchungsangaben vollständig prüfen.',422);return $input[$name];
    }
    private static function fingerprint(array $row):string
    {
        $keys=['club_id','tax_year','id','booked_on','description','evidence','lines','reversal_of','created_by','created_at'];$data=[];foreach($keys as$key){if(!array_key_exists($key,$row))throw new DomainException('Gespeicherte Buchung prüfen lassen.',503);$data[$key]=$row[$key];}return hash('sha256',json_encode(['annual-saved-posting-v1',$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    }
    private function find(int $year,string $id):array
    {
        return BalanceReadSnapshot::run($this->db,function()use($year,$id):array{
            $actor=$this->actor();$q=DocumentCatalog::query($this->db,'SELECT * FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?',[$actor['club_id'],$year,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row||$row['club_id']!==$actor['club_id']||(int)$row['tax_year']!==$year)throw new DomainException('Gespeicherte Buchung nicht gefunden.',404);$report=(new BalanceLedger($this->db,$this->actorId))->report($year);$row['lines']=json_decode($row['posting_lines'],true,64,JSON_THROW_ON_ERROR);unset($row['posting_lines']);return ['posting'=>$row,'sha256'=>self::fingerprint($row),'year'=>$year,'current'=>$report];
        });
    }
    public function get(string $year,string $id,string $sha256):array
    {
        $this->actor();$value=$this->find(self::year($year),self::id($id));if(!preg_match('/\A[a-f0-9]{64}\z/D',$sha256)||!hash_equals($value['sha256'],$sha256))throw new DomainException('Gespeicherte Buchungsfassung stimmt nicht überein.',409);return $value;
    }
    /** A matching saved row is readable; original request-version approval is not inferred. */
    public function fromSubmitted(array $input):?array
    {
        $actor=$this->actor();$action=self::field($input,'action');if(!in_array($action,['balance-post','balance-reverse'],true))throw new DomainException('Ursprünglichen Buchungsschritt vollständig auswählen.',422);$year=self::year($input['balance_year']??null);$key=self::id($input['request_key']??null);$version=self::field($input,'version');if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$version)||(int)$version>4294967295)throw new DomainException('Ursprüngliche Buchungsfassung vollständig auswählen.',422);
        try{$saved=$this->find($year,$key);}catch(DomainException $error){if($error->getCode()===404)return null;throw $error;}$row=$saved['posting'];$date=self::field($input,'booked_on');$evidence=trim(self::field($input,'evidence'));if($row['created_by']!==$actor['id']||$row['booked_on']!==$date||$row['evidence']!==$evidence)return null;
        if($action==='balance-post'){
            if($row['reversal_of']!==null||$row['description']!==trim(self::field($input,'description')))return null;$raw=$input['lines']??null;if(!is_array($raw)||!array_is_list($raw)||count($raw)>50)throw new DomainException('Ursprüngliche Buchungszeilen vollständig prüfen.',422);$lines=[];
            foreach($raw as$line){if(!is_array($line)||array_diff(array_keys($line),['account','debit','credit','sphere'])||count($line)!==4||count(array_filter($line,'is_string'))!==4)throw new DomainException('Ursprüngliche Buchungszeile vollständig prüfen.',422);if($line['account']===''&&$line['debit']===''&&$line['credit']===''&&$line['sphere']==='')continue;$lines[]=['account'=>$line['account'],'debit_cents'=>Finance::cents($line['debit']?:'0'),'credit_cents'=>Finance::cents($line['credit']?:'0'),'sphere'=>$line['sphere']];}
            $lines=BalanceCalculation::lines($lines,$saved['current']['accounts']);
        }else{
            $original=self::id($input['entry']??null);if($row['reversal_of']!==$original)return null;$source=$this->find($year,$original)['posting'];$lines=[];foreach($source['lines']as$line)$lines[]=['account'=>$line['account'],'debit_cents'=>$line['credit_cents'],'credit_cents'=>$line['debit_cents'],'sphere'=>$line['sphere']];$lines=BalanceCalculation::lines($lines,$saved['current']['accounts']);
        }
        if($lines!==$row['lines'])return null;return $saved;
    }
}
