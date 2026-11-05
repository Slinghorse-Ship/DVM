<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use PDO;
/** Immutable command originals; current financial permission is required on every read. */
final class ReimbursementActionOriginals
{
    public const ACTIONS=['create'=>'reimbursement-create','approve'=>'reimbursement-approve','associate'=>'reimbursement-link','correct'=>'reimbursement-correct','cancel'=>'reimbursement-cancel','reopen'=>'reimbursement-reopen'];
    public static function requireOperation(PDO $db,string $action):void
    {
        self::available($db);
        if(in_array($action,['correct','cancel','reopen'],true)&&(int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()<131)throw new DomainException('Berichtigungen für Erstattungen sind noch nicht eingerichtet. Die Verwaltung muss den Datenbankstand aktualisieren; nichts wurde geändert.',503);
    }
    public static function key(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprüngliche Erstattungskennung vollständig prüfen.',422);
        return $value;
    }
    public static function id(mixed $value):string
    {
        if(!is_string($value)||$value===''||strlen($value)>128||!mb_check_encoding($value,'UTF-8')||trim($value)!==$value||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Erstattungsquelle vollständig prüfen.',422);
        return $value;
    }
    public static function json(array $data):string{return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function access(PDO $db,string $actor,bool $write=false):array
    {
        try{$who=Permissions::actor($db,$actor);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),403,$failure);}
        if(!in_array($who['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Aktuelle Finanzberechtigung für Erstattungsnachweise erforderlich.',403);
        return $who;
    }
    private static function available(PDO $db):void
    {
        if(!Database::tableExists($db,'finance_reimbursement_actions')||!Database::tableExists($db,'schema_migrations')||(int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()<130)throw new DomainException('Auftragsnachweise für Erstattungen sind noch nicht eingerichtet. Datenbankaktualisierung durch die Verwaltung erforderlich; nichts wurde erneut gespeichert.',503);
    }
    private static function decode(string $json,string $hash):array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||!hash_equals($hash,hash('sha256',$json)))throw new DomainException('Gespeichertes Erstattungsoriginal ist nicht vollständig nachgewiesen. Bitte prüfen lassen.',503);
        try{$data=json_decode($json,true,128,JSON_THROW_ON_ERROR);}catch(\JsonException $failure){throw new DomainException('Gespeichertes Erstattungsoriginal ist nicht vollständig nachgewiesen. Bitte prüfen lassen.',503,$failure);}
        if(!is_array($data)||($data['version']??null)!==1)throw new DomainException('Gespeichertes Erstattungsoriginal verwendet eine nicht unterstützte Fassung. Bitte prüfen lassen.',503);
        return $data;
    }
    private static function validate(array $row,array $request,array $review,array $result):void
    {
        $action=$row['action'];
        if(!isset(self::ACTIONS[$action])||($request['source']['entry']??null)!==$row['entry_id']||($request['source']['operation']??null)!==$action||($request['source']['key']??null)!==$row['request_key']||($request['actor_id']??null)!==$row['actor_id']||($request['fields']['action']??null)!==self::ACTIONS[$action]||($request['fields']['id']??null)!==$row['entry_id']||($request['fields']['request_key']??null)!==$row['request_key']||($review['entry']['id']??null)!==$row['entry_id']||($result['claim']['id']??null)!==$row['claim_id']||($result['claim']['entry_id']??null)!==$row['entry_id'])throw new DomainException('Gespeicherte Erstattungsquelle stimmt nicht mit ihrem Original überein. Bitte prüfen lassen.',503);
    }
    public static function read(PDO $db,string $actor,string $entry,string $key):?array
    {
        return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($db,static fn():?array=>self::readLocked($db,$actor,$entry,$key));
    }
    private static function readLocked(PDO $db,string $actor,string $entry,string $key):?array
    {
        $entry=self::id($entry);$key=self::key($key);$who=self::access($db,$actor);
        try{$financial=(new Finance($db,$actor))->entry($entry);}catch(DomainException$failure){throw new DomainException($failure->getMessage(),403,$failure);}
        if($financial['kind']!=='expense')throw new DomainException('Erstattungen an der ursprünglichen Ausgabe öffnen.',409);self::available($db);
        $q=$db->prepare('SELECT * FROM finance_reimbursement_actions WHERE club_id=? AND request_key=? FOR SHARE NOWAIT');$q->execute([$who['club_id'],$key]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
        if($row['entry_id']!==$entry)throw new DomainException('Diese Erstattungskennung gehört zu einer anderen ursprünglichen Ausgabe.',409);
        return self::hydrate($row);
    }
    private static function hydrate(array $row):array
    {
        $request=self::decode($row['request_json'],$row['request_sha256']);$review=self::decode($row['review_json'],$row['review_sha256']);$result=self::decode($row['result_json'],$row['result_sha256']);self::validate($row,$request,$review,$result);
        return ['id'=>$row['id'],'entry'=>$row['entry_id'],'claim'=>$row['claim_id'],'operation'=>$row['action'],'request_key'=>$row['request_key'],'actor'=>$row['actor_id'],'created_at'=>$row['created_at'],'request'=>$request,'review'=>$review,'result'=>$result];
    }
    /** Logical claim versions order same-second originals without inventing missing legacy firsts. */
    public static function history(PDO $db,string $actor,string $entry,mixed $page='1'):array
    {
        if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000)throw new DomainException('Ursprüngliche Verlaufsseite vollständig prüfen.',422);
        return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($db,static function()use($db,$actor,$entry,$page):array{
            $entry=self::id($entry);$who=self::access($db,$actor);self::available($db);
            try{$source=(new Finance($db,$actor))->currentEntry($entry);}catch(DomainException$failure){throw new DomainException($failure->getMessage(),403,$failure);}
            if($source['id']!==$entry||$source['kind']!=='expense')throw new DomainException('Erstattungen an der ursprünglichen Ausgabe öffnen.',409);
            $q=$db->prepare('SELECT COUNT(*) FROM finance_reimbursement_actions WHERE club_id=? AND entry_id=?');$q->execute([$who['club_id'],$entry]);$count=(int)$q->fetchColumn();$pages=max(1,(int)ceil($count/6));$number=min((int)$page,$pages);
            $q=$db->prepare("SELECT * FROM finance_reimbursement_actions WHERE club_id=? AND entry_id=? ORDER BY CAST(JSON_UNQUOTE(JSON_EXTRACT(result_json,'$.claim.version')) AS UNSIGNED) DESC,created_at DESC,id DESC LIMIT 6 OFFSET ".(($number-1)*6).' FOR SHARE NOWAIT');$q->execute([$who['club_id'],$entry]);$rows=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row){$first=self::hydrate($row);$version=$first['result']['claim']['version']??null;if(!is_int($version)||$version<1)throw new DomainException('Gespeichertes Erstattungsoriginal ist nicht vollständig nachgewiesen. Bitte prüfen lassen.',503);$reason=$first['request']['fields']['reason']??'';if(!is_string($reason))throw new DomainException('Gespeichertes Erstattungsoriginal ist nicht vollständig nachgewiesen. Bitte prüfen lassen.',503);$rows[]=array_intersect_key($first,array_flip(['id','operation','request_key','actor','created_at']))+['claim_version'=>$version,'reason'=>$reason];}
            return ['rows'=>$rows,'count'=>$count,'page'=>$number,'pages'=>$pages];
        });
    }
    /** The domain operation, audit and complete first original share the caller's transaction. */
    public static function store(PDO $db,string $actor,string $entry,string $claim,string $action,string $key,array $request,array $review,array $result):array
    {
        if(!$db->inTransaction())throw new DomainException('Erstattungsauftrag benötigt einen gemeinsamen Speichervorgang.',503);
        $who=self::access($db,$actor,true);$entry=self::id($entry);$claim=self::id($claim);$key=self::key($key);self::requireOperation($db,$action);
        if(!isset(self::ACTIONS[$action]))throw new DomainException('Erstattungsart vollständig prüfen.',422);
        foreach([$request,$review,$result]as$data)if(($data['version']??null)!==1)throw new DomainException('Erstattungsoriginal vollständig prüfen.',422);
        $id=bin2hex(random_bytes(16));$created=gmdate('c');$columns=['action'=>$action,'entry_id'=>$entry,'claim_id'=>$claim,'request_key'=>$key,'actor_id'=>$who['id']];self::validate($columns,$request,$review,$result);
        $requestJson=self::json($request);$reviewJson=self::json($review);$resultJson=self::json($result);
        $db->prepare('INSERT INTO finance_reimbursement_actions(club_id,id,entry_id,claim_id,action,request_key,actor_id,created_at,request_json,request_sha256,review_json,review_sha256,result_json,result_sha256)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$who['club_id'],$id,$entry,$claim,$action,$key,$who['id'],$created,$requestJson,hash('sha256',$requestJson),$reviewJson,hash('sha256',$reviewJson),$resultJson,hash('sha256',$resultJson)]);
        return ['id'=>$id,'entry'=>$entry,'claim'=>$claim,'operation'=>$action,'request_key'=>$key,'actor'=>$who['id'],'created_at'=>$created,'request'=>$request,'review'=>$review,'result'=>$result];
    }
}
