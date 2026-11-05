<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Integration\ManagerBridge;

/** Exact personal membership, never a shared address or an inferred payment status. */
final class MemberCardSource
{
    public function __construct(private PDO $db){}
    private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return$q;}
    public static function day(string $value):string
    {
        if(!preg_match('/\A[12][0-9]{3}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])\z/D',$value))throw new DomainException('Datum des Mitgliederausweises prüfen.',422);
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Europe/Berlin'));if(!$d||$d->format('Y-m-d')!==$value)throw new DomainException('Datum des Mitgliederausweises prüfen.',422);return$value;
    }
    public static function text(mixed $value,int $max):string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||trim($value)===''||mb_strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Angaben für den Mitgliederausweis prüfen.',422);return$value;
    }
    public static function origin(string $value):string
    {
        $p=parse_url($value);if(!$p||($p['scheme']??null)!=='https'||!is_string($p['host']??null)||!preg_match('/\A[a-zA-Z0-9.-]+\z/D',$p['host'])||str_contains($p['host'],'..')||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['path']??'',['','/'],true)||strlen($value)>250)throw new DomainException('Für den Mitgliederausweis die öffentliche HTTPS-Vereinsadresse einrichten.',422);
        return'https://'.strtolower($p['host']);
    }
    /** Caller owns a read/write transaction; every material row is read currently. */
    public function read(string $club,string $contact,string $today,bool $write=false):array
    {
        self::day($today);$lock=$this->db->inTransaction()?($write?' FOR UPDATE NOWAIT':' FOR SHARE NOWAIT'):'';
        $person=$this->q('SELECT id,kind,name,archived_at FROM contacts WHERE club_id=? AND id=?'.$lock,[$club,$contact])->fetch(PDO::FETCH_ASSOC);
        if(!$person||$person['kind']!=='person'||$person['archived_at']!==null)throw new DomainException('Mitgliederprofil für den Ausweis nicht verfügbar.',404);
        // An already recorded future departure still leaves the membership valid
        // through its last day. The legacy status alone cannot establish validity.
        $members=$this->q('SELECT id,member_number,status,starts_on,starts_precision,ends_on,version FROM memberships WHERE club_id=? AND contact_id=? AND starts_on<=? AND (ends_on IS NULL OR ends_on>=?) ORDER BY starts_on,id LIMIT 2'.$lock,[$club,$contact,$today,$today])->fetchAll(PDO::FETCH_ASSOC);
        if(count($members)!==1)throw new DomainException($members?'Mehrere aktuelle Mitgliedschaften bitte zuerst im Mitgliederprofil klären.':'Für diesen Ausweis besteht keine aktuelle Mitgliedschaft.',409);
        $m=$members[0];self::day($m['starts_on']);if($m['ends_on']!==null)self::day($m['ends_on']);
        if(!in_array($m['status'],['aktiv','passiv','ehemalig'],true)||$m['status']==='ehemalig'&&$m['ends_on']===null)throw new DomainException('Aktuelle Mitgliedschaft für den Ausweis prüfen.',409);
        if($m['starts_precision']==='year'&&substr($m['starts_on'],0,4)===substr($today,0,4)&&substr($today,5)<'12-31')throw new DomainException('Für den Ausweis das genaue Eintrittsdatum im Mitgliederprofil klären.',409);
        $native=ManagerBridge::clubKey($club);
        $nativeRow=$this->q('SELECT payload FROM native_club_settings WHERE club_id=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''),[$native])->fetchColumn();
        $identity=is_string($nativeRow)?json_decode($nativeRow,true,32,JSON_THROW_ON_ERROR):['name'=>'','origin'=>''];
        $commonRow=$this->q("SELECT payload FROM club_settings WHERE club_id=? AND section='club'".($this->db->inTransaction()?' FOR SHARE NOWAIT':''),[$club])->fetchColumn();
        if(is_string($commonRow))$identity['name']=json_decode($commonRow,true,32,JSON_THROW_ON_ERROR)['name']??'';

        $source=['club_id'=>$club,'contact_id'=>$contact,'membership_id'=>$m['id'],'club_name'=>self::text($identity['name'],255),'name'=>self::text($person['name'],255),'member_number'=>self::text($m['member_number'],128),'starts_on'=>$m['starts_on'],'starts_precision'=>$m['starts_precision'],'ends_on'=>$m['ends_on'],'membership_version'=>(int)$m['version'],'origin'=>self::origin($identity['origin'])];
        return['data'=>$source,'fingerprint'=>hash('sha256',json_encode($source,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
    }
}
