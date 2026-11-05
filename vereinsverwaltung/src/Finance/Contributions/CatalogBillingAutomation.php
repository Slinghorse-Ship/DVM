<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Explicitly approved local recurring receipts. No delivery or payment transport. */
final class CatalogBillingAutomation
{
    public function __construct(private PDO $db,private string $actorId){}
    private function actor():array
    {
        $a=(new CatalogBilling($this->db,$this->actorId))->actor(true);
        if(!Database::tableExists($this->db,'native_catalog_automation'))throw new DomainException('Tarifautomatik benötigt das aktuelle installierte Paket.');
        return $a;
    }
    private static function date(string $day):DateTimeImmutable
    {
        if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$day))throw new DomainException('Datum prüfen.');
        SettingsSchema::date($day);return new DateTimeImmutable($day,new DateTimeZone('Europe/Berlin'));
    }
    public static function today():string{return(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');}
    private function read(callable $operation):mixed
    {
        try{return Transactions::run($this->db,$operation);}catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarifautomatik wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
    public function get(string $id):?array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Eigene Tarifzuweisung auswählen.',422);
        return$this->read(function()use($id):?array{
            $a=(new CatalogBilling($this->db,$this->actorId))->actor();(new AssignmentPrices($this->db,$this->actorId))->context($id);
            if(!Database::tableExists($this->db,'native_catalog_automation'))return null;
            $q=$this->db->prepare('SELECT * FROM native_catalog_automation WHERE club_id=? AND assignment_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);return$q->fetch(PDO::FETCH_ASSOC)?:null;
        });
    }
    public function history(string $id,int $page=1):array
    {
        if($page<1||$page>999999999)throw new DomainException('Vollständige Automatikverlaufsseite prüfen.',422);
        return$this->read(function()use($id,$page):array{
            $this->get($id);$a=(new CatalogBilling($this->db,$this->actorId))->actor();
            if(!Database::tableExists($this->db,'native_catalog_automation_runs')){if($page!==1)throw new DomainException('Automatikverlaufsseite ist nicht verfügbar.',422);return['rows'=>[],'total'=>0,'pages'=>1,'page'=>1];}
            $q=$this->db->prepare('SELECT COUNT(*) FROM native_catalog_automation_runs WHERE club_id=? AND assignment_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/6));if($page>$pages)throw new DomainException('Automatikverlaufsseite ist nicht verfügbar.',422);
            $q=$this->db->prepare('SELECT * FROM native_catalog_automation_runs WHERE club_id=? AND assignment_id=? ORDER BY scheduled_on DESC,id DESC LIMIT 6 OFFSET '.(($page-1)*6).' FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);return['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'pages'=>$pages,'page'=>$page];
        });
    }
    public function review(string $id,string $first,string $mode):array
    {
        $day=self::date($first);if($first<self::today())throw new DomainException('Automatik frühestens ab heute aktivieren.');
        $quote=(new CatalogBilling($this->db,$this->actorId))->review([$id],$first,$mode)[0];
        if($quote['data']['interval']==='once')throw new DomainException('Einmalige Tarife manuell abrechnen.');
        return $quote;
    }
    /** A request key belongs to the complete original input and actor, including pause. */
    public function change(string $id,int $version,string $first,string $mode,int $dueDays,string $fingerprint,string $key,bool $enabled):array
    {
        $a=$this->actor();if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!preg_match('/\A[a-f0-9]{32}\z/D',$key)||$version<0||$dueDays<0||$dueDays>90)throw new DomainException('Zuweisung, Zahlungsfrist und Formular prüfen.');
        $hash=hash('sha256',json_encode([$id,$version,$first,$mode,$dueDays,$fingerprint,$enabled,$a['id']],JSON_THROW_ON_ERROR));
        return Transactions::run($this->db,function()use($a,$id,$version,$first,$mode,$dueDays,$fingerprint,$key,$enabled,$hash){
            $this->lockClub($a['club_id']);$fresh=$this->actor();if($fresh['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.');$a=$fresh;
            $q=$this->db->prepare('SELECT * FROM native_catalog_automation_changes WHERE club_id=? AND request_key=? FOR UPDATE');$q->execute([$a['club_id'],$key]);
            if($old=$q->fetch(PDO::FETCH_ASSOC)){if(!hash_equals($old['request_hash'],$hash))throw new DomainException('Vorgangskennung gehört zu einer anderen Freigabe. Neu prüfen.');return json_decode($old['state_json'],true,512,JSON_THROW_ON_ERROR);}
            $q=$this->db->prepare('SELECT * FROM native_catalog_automation WHERE club_id=? AND assignment_id=? FOR UPDATE');$q->execute([$a['club_id'],$id]);$old=$q->fetch(PDO::FETCH_ASSOC)?:null;
            if((int)($old['version']??0)!==$version)throw new DomainException('Automatik inzwischen geändert. Aktuellen Stand neu prüfen.');
            if($enabled){
                $this->review($id,$first,$mode);$quote=(new CatalogBilling($this->db,$this->actorId))->review([$id],$first,$mode,null,true)[0];
                if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)||!hash_equals($quote['fingerprint'],$fingerprint))throw new DomainException('Preisgrundlage inzwischen geändert. Freigabe neu prüfen.');
                $months=['month'=>1,'quarter'=>3,'half'=>6,'year'=>12][$quote['data']['interval']]??0;if(!$months)throw new DomainException('Wiederkehrenden Tarif auswählen.');
                $state=['club_id'=>$a['club_id'],'assignment_id'=>$id,'version'=>$version+1,'status'=>'active','next_on'=>$first,'schedule_day'=>(int)self::date($first)->format('j'),'interval_months'=>$months,'due_days'=>$dueDays,'mode'=>$mode,'source_hash'=>$quote['source_fingerprint'],'approved_quote'=>json_encode($quote,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'approved_by'=>$a['id'],'reason'=>''];
            }else{
                if(!$old)throw new DomainException('Bestehende eigene Automatik auswählen.');$state=$old;$state['version']=$version+1;$state['status']='paused';$state['reason']='manual';unset($state['changed_at']);
            }
            $q=$this->db->prepare('INSERT INTO native_catalog_automation(club_id,assignment_id,version,status,next_on,schedule_day,interval_months,due_days,mode,source_hash,approved_quote,approved_by,reason,changed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),status=VALUES(status),next_on=VALUES(next_on),schedule_day=VALUES(schedule_day),interval_months=VALUES(interval_months),due_days=VALUES(due_days),mode=VALUES(mode),source_hash=VALUES(source_hash),approved_quote=VALUES(approved_quote),approved_by=VALUES(approved_by),reason=VALUES(reason),changed_at=VALUES(changed_at)');$q->execute(array_values($state));
            $q=$this->db->prepare('INSERT INTO native_catalog_automation_changes VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$a['club_id'],$key,$hash,$id,$version+1,$a['id'],json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            return $state;
        });
    }
    private function lockClub(string $club):void{$q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE');$q->execute([$club]);if(!$q->fetchColumn())throw new DomainException('Eigenen Verein auswählen.');}
    private function advance(string $club,array $plan):void
    {
        $next=self::nextDate($plan['next_on'],(int)$plan['interval_months'],(int)$plan['schedule_day']);$q=$this->db->prepare('UPDATE native_catalog_automation SET next_on=?,changed_at=UTC_TIMESTAMP(6) WHERE club_id=? AND assignment_id=?');$q->execute([$next,$club,$plan['assignment_id']]);
    }
    public static function nextDate(string $day,int $months,int $scheduleDay):string
    {
        if(!in_array($months,[1,3,6,12],true)||$scheduleDay<1||$scheduleDay>31)throw new DomainException('Abrechnungsrhythmus prüfen.');
        $start=self::date($day)->modify('first day of this month')->modify('+'.$months.' months');return$start->setDate((int)$start->format('Y'),(int)$start->format('n'),min($scheduleDay,(int)$start->format('t')))->format('Y-m-d');
    }
    /** One due period per plan per bounded invocation; missed periods remain explicit. */
    public function run(?string $today=null,int $limit=20):array
    {
        if($this->db->inTransaction())throw new DomainException('Tariflauf benötigt eine eigene Transaktion.');
        $a=$this->actor();$today??=self::today();self::date($today);if($today>self::today()||$limit<1||$limit>20)throw new DomainException('Lokales Laufdatum und Limit prüfen.');
        $q=$this->db->prepare("SELECT assignment_id,version,next_on FROM native_catalog_automation WHERE club_id=? AND status='active' AND next_on<=? ORDER BY next_on,assignment_id LIMIT ".$limit);$q->execute([$a['club_id'],$today]);$candidates=$q->fetchAll(PDO::FETCH_ASSOC);$out=['billed'=>0,'existing'=>0,'paused'=>0,'skipped'=>0];
        foreach($candidates as$candidate){
            try{$status=Transactions::run($this->db,function()use($a,$today,$candidate){
                $this->lockClub($a['club_id']);$fresh=$this->actor();if($fresh['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.');$a=$fresh;
                $q=$this->db->prepare('SELECT * FROM native_catalog_automation WHERE club_id=? AND assignment_id=? FOR UPDATE');$q->execute([$a['club_id'],$candidate['assignment_id']]);$plan=$q->fetch(PDO::FETCH_ASSOC);
                if(!$plan||$plan['status']!=='active'||$plan['next_on']!==$candidate['next_on']||(int)$plan['version']!==(int)$candidate['version'])return 'skipped';
                $author=Permissions::actor($this->db,$plan['approved_by']);if($author['club_id']!==$a['club_id']||$author['finance_access']!=='write'||$author['members_access']==='none')throw new DomainException('Freigabeberechtigung geändert.');
                $quote=(new CatalogBilling($this->db,$this->actorId))->review([$plan['assignment_id']],$plan['next_on'],$plan['mode'],null,true)[0];
                if(!hash_equals($plan['source_hash'],$quote['source_fingerprint']))throw new DomainException('Preisgrundlage geändert.');
                $q=$this->db->prepare('SELECT id FROM native_catalog_automation_runs WHERE club_id=? AND assignment_id=? AND scheduled_on=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$plan['assignment_id'],$plan['next_on']]);
                if($q->fetchColumn()){$this->advance($a['club_id'],$plan);return 'existing';}
                $due=self::date($plan['next_on'])->modify('+'.$plan['due_days'].' days')->format('Y-m-d');$key=bin2hex(random_bytes(16));
                if($quote['charge']){$entry=$quote['charge']['entry_id'];$status='existing';}
                else{$entry=(new Finance($this->db,$this->actorId))->createCatalogCharges([['assignment_id'=>$quote['assignment_id'],'fingerprint'=>$quote['fingerprint']]],$plan['next_on'],$due,$plan['mode'],null,$key)[0];$status='billed';}
                unset($quote['charge']);$q=$this->db->prepare('INSERT INTO native_catalog_automation_runs VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$a['club_id'],$key,$plan['assignment_id'],$plan['next_on'],$plan['version'],$status,$entry,json_encode($quote,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$a['id']]);
                $this->advance($a['club_id'],$plan);return$status;
            });++$out[$status];}
            catch(DomainException){
                Transactions::run($this->db,function()use($a,$candidate,&$out){$this->lockClub($a['club_id']);if($this->actor()['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.');$q=$this->db->prepare("UPDATE native_catalog_automation SET status='paused',reason='review',version=version+1,changed_at=UTC_TIMESTAMP(6) WHERE club_id=? AND assignment_id=? AND version=? AND next_on=? AND status='active'");$q->execute([$a['club_id'],$candidate['assignment_id'],$candidate['version'],$candidate['next_on']]);if($q->rowCount())++$out['paused'];else++$out['skipped'];});
            }
        }
        return$out;
    }
}
