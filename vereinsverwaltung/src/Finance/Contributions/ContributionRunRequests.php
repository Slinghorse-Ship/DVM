<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Contributions;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,Finance,ContributionReminders,ContributionInvoices};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** A deliberate scheduling decision; no transport, payment or inferred delivery. */
final class ContributionRunRequests
{
    public const SECTION='contribution-run';
    public function __construct(private PDO $db,private string $actorId){}
    public static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private function busy(\Throwable $failure):void
    {
        if(($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))||($failure instanceof DomainException&&$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'))throw new DomainException('Beitragslauf oder Zahlung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
    }
    private function read(callable $operation):array
    {
        $own=!$this->db->inTransaction();try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return $result;}
        catch(\Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);throw $failure;}
    }
    private function actor(bool $write=false):array
    {
        try{return Permissions::finance($this->db,$this->actorId,$write);}catch(DomainException$failure){throw new DomainException($write?'Aktuelle Finanzschreibberechtigung erforderlich.':'Aktuelle Finanzleseberechtigung erforderlich.',403,$failure);}
    }
    public function assertAccess(bool $write=false):array{return $this->read(fn():array=>$this->actor($write));}
    public function state(string $day):array{return $this->read(function()use($day):array{$this->actor();return(new ContributionReminders($this->db,$this->actorId))->preview($day);});}
    private function available():void
    {
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Beitragsbestätigungen benötigen das aktuelle installierte Paket.',503);
    }
    private function invoiceSource(string $club,string $entryId):array
    {
        $entry=(new Finance($this->db,$this->actorId))->currentEntry($entryId);
        $invoice=Db::run($this->db,'SELECT * FROM contribution_invoices WHERE club_id=? AND entry_id=? FOR SHARE NOWAIT',[$club,$entryId])->fetch()?:null;$file=null;
        if($invoice){$read=(new ContributionInvoices($this->db,$this->actorId))->currentOriginal($entryId);$invoice=$read['invoice'];$file=['document_id'=>$invoice['document_id']]+$read['original']['file']+['snapshot_sha256'=>hash('sha256',$read['file']['snapshot']),'draft'=>$read['snapshot']['draft']];}
        return compact('entry','invoice','file');
    }
    private function source(array $actor,array $plan):array
    {
        $club=$actor['club_id'];$source=['invoices'=>[],'annual'=>[]];$seen=[];
        foreach($plan['reminders']as$row){$source['invoices'][$row['entry_id']]=$this->invoiceSource($club,$row['entry_id']);$seen[$row['entry_id']]=true;}
        if($plan['annual_due']){
            $source['settings']=Db::run($this->db,"SELECT section,version,payload FROM club_settings WHERE club_id=? AND section IN ('club','bank','paper','texts_contributions','tax','numbers') ORDER BY section FOR SHARE NOWAIT",[$club])->fetchAll();
            $source['rates']=Db::run($this->db,'SELECT r.membership_type,c.id,c.version,c.active,c.payload FROM membership_contribution_rates r JOIN settings_catalog c ON c.club_id=r.club_id AND c.id=r.catalog_id WHERE r.club_id=? ORDER BY r.membership_type FOR SHARE NOWAIT',[$club])->fetchAll();
            foreach($plan['annual_candidates']as$row){
                $contact=Db::run($this->db,'SELECT id,name,email,address,archived_at FROM contacts WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$club,$row['contact_id']])->fetch();
                $family=$row['family_id']===''?null:Db::run($this->db,'SELECT * FROM member_families WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$club,$row['family_id']])->fetch();
                $people=$family?Db::run($this->db,'SELECT contact_id FROM member_family_people WHERE club_id=? AND family_id=? ORDER BY contact_id FOR SHARE NOWAIT',[$club,$row['family_id']])->fetchAll(PDO::FETCH_COLUMN):[$row['contact_id']];$memberships=[];
                foreach($people as$person)$memberships[$person]=Db::run($this->db,'SELECT * FROM memberships WHERE club_id=? AND contact_id=? ORDER BY starts_on,id FOR SHARE NOWAIT',[$club,$person])->fetchAll();
                $source['annual'][]=compact('contact','family','people','memberships');
                if($row['entry_id']!==''&&!isset($seen[$row['entry_id']])){$source['invoices'][$row['entry_id']]=$this->invoiceSource($club,$row['entry_id']);$seen[$row['entry_id']]=true;}
            }
        }
        ksort($source['invoices']);return $source;
    }
    private static function reviewHash(array $review):string{return hash('sha256',self::json(array_intersect_key($review,array_flip(['input','normalized','plan','source']))));}
    public function prepare(array $raw):array
    {
        $input=ContributionRunInput::capture($raw);$normalized=ContributionRunInput::normalize($input);
        return $this->read(function()use($input,$normalized):array{
            $actor=$this->actor(true);$this->available();Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR SHARE NOWAIT',[$actor['club_id']])->fetchColumn();$plan=(new ContributionReminders($this->db,$this->actorId))->preview($normalized['day']);unset($plan['deliveries']);
            $review=['input'=>$input,'normalized'=>$normalized,'plan'=>$plan,'source'=>$this->source($actor,$plan)];return $review+['hash'=>self::reviewHash($review)];
        });
    }
    public function accept(array $raw,string $key,string $reviewHash,bool $approved):array
    {
        $input=ContributionRunInput::capture($raw);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Eigene ursprüngliche Beitragslaufkennung und Prüfung auswählen.',422);
        if(!$approved)throw new DomainException('Genau diesen geprüften Beitragslauf ausdrücklich vorbereiten.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($input,$key,$reviewHash,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $actor=$this->actor();if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$this->available();$json=self::json($input);$inputHash=hash('sha256',self::SECTION."\0".$json);
            $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetch();
            if($old){if($old['section']!==self::SECTION||$old['actor_id']!==$actor['id']||$old['input_json']!==$json||!hash_equals($old['input_sha256'],$inputHash))throw new DomainException('Diese Beitragslaufkennung gehört zu anderen ursprünglichen Angaben.',409);$first=$this->decode($old);if(!hash_equals($first['review']['hash'],$reviewHash))throw new DomainException('Diese Beitragslaufkennung gehört zu einer anderen ursprünglichen Prüfung.',409);return $first;}
            $this->actor(true);$review=$this->prepare($input);if(!hash_equals($review['hash'],$reviewHash))throw new DomainException('Beiträge, Zahlung oder Versandgrundlage geändert. Ganzen Beitragslauf erneut prüfen.',409);
            $day=$review['normalized']['day'];$year=(int)substr($day,0,4);$previous=Db::run($this->db,'SELECT id FROM contribution_deliveries WHERE club_id=? AND year=? AND scheduled_for=? ORDER BY id FOR SHARE NOWAIT',[$club,$year,$day])->fetchAll(PDO::FETCH_COLUMN);
            $run=(new ContributionReminders($this->db,$this->actorId))->run($day,true);$after=Db::run($this->db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND year=? AND scheduled_for=? ORDER BY id FOR SHARE NOWAIT',[$club,$year,$day])->fetchAll();$deliveries=array_values(array_filter($after,static fn(array$row):bool=>!in_array($row['id'],$previous,true)));
            $contributions=[];foreach($review['plan']['annual_candidates']as$person){$entry=Db::run($this->db,'SELECT entry_id FROM member_dues_people WHERE club_id=? AND contact_id=? AND year=? FOR SHARE NOWAIT',[$club,$person['contact_id'],$year])->fetchColumn();if($entry)$contributions[$entry]=$this->invoiceSource($club,$entry);}
            ksort($contributions);$result=['day'=>$day,'executed'=>$run['executed'],'created'=>$run['created'],'queued'=>$run['queued'],'errors'=>$run['errors'],'deliveries'=>$deliveries,'contributions'=>$contributions];
            if($result['executed']!==true||count($deliveries)!==$result['queued'])throw new DomainException('Tatsächlichen ursprünglichen Beitragsausgang bitte prüfen lassen.',503);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$originalJson=self::json(['format'=>'dvm-contribution-run-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>self::SECTION,'input'=>$input,'review'=>$review,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,version,input_sha256,input_json,original_sha256,original_json,created_at)VALUES(?,?,?,?,?,?,?,?,?,?)',[$club,$key,$actor['id'],self::SECTION,1,$inputHash,$json,hash('sha256',$originalJson),$originalJson,$now->format('Y-m-d H:i:s.u')]);
            return $this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=?',[$club,$key])->fetch());
        });}catch(\Throwable$failure){$this->busy($failure);throw $failure;}
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$first=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if($row['section']!==self::SECTION||(int)$row['version']!==1||!hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($first['format']??null)!=='dvm-contribution-run-original/1'||($first['section']??null)!==self::SECTION||($first['request_key']??null)!==$row['request_key']||($first['club_id']??null)!==$row['club_id']||($first['actor_id']??null)!==$row['actor_id']||($first['input']??null)!==$input||($first['review']['input']??null)!==$input||!hash_equals((string)($first['review']['hash']??''),self::reviewHash($first['review']??[]))||($first['approved']??null)!==true||($first['result']['executed']??null)!==true||($first['result']['day']??null)!==($first['review']['normalized']['day']??null)||($first['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Beitragsbestätigung bitte prüfen lassen.',503);
        return ['key'=>$row['request_key'],'input'=>$input,'review'=>$first['review'],'result'=>$first['result'],'created_at'=>$first['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    public function receipt(string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Eigene ursprüngliche Beitragsbestätigung nicht verfügbar.',404);
        return $this->read(function()use($key):array{$actor=$this->actor();$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? AND section=? AND actor_id=? FOR SHARE NOWAIT',[$actor['club_id'],$key,self::SECTION,$actor['id']])->fetch();if(!$row)throw new DomainException('Eigene ursprüngliche Beitragsbestätigung nicht verfügbar.',404);return $this->decode($row);});
    }
    public function history(array $query=[]):array
    {
        $search=$query['query']??'';$page=$query['page']??'1';
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$search)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Ganze Beitragsbestätigungssuche und Seite prüfen.',422);
        $page=(string)$page;return $this->read(function()use($search,$page):array{$actor=$this->actor();$this->available();$where=' WHERE club_id=? AND actor_id=? AND section=?';$args=[$actor['club_id'],$actor['id'],self::SECTION];if($search!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),request_key,created_at,input_json)))>0';$args[]=$search;}$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_settings_requests'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$page>$pages?'Verfügbare ursprüngliche Beitragsbestätigungsseite auswählen.':'';$rows=$error===''?Db::run($this->db,'SELECT request_key,created_at FROM native_settings_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$page,'page'=>$error===''?(int)$page:null,'query'=>$search,'error'=>$error,'per_page'=>25];});
    }
}
