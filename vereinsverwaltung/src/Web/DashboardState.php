<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Verein\{Permissions,Registry,Finance,Settings,Inventory,Documents,WorkspaceAccess,WebsiteCalendar,EventVouchers,BoardAccess,DocumentChecks,ActivityReportReview};

require_once __DIR__.'/DashboardSetup.php';

/** One current authorized entry, with explicit independent errors instead of substitute figures. */
final class DashboardState
{
    use DashboardSetup;
    public function __construct(private PDO $db,private string $actorId,private ?WebsiteCalendar $website=null){}
    private function currentChair(array $actor):bool
    {
        $rows=\Kulturhus\Native\Shared\Db::run($this->db,"SELECT actor_id FROM admission_board WHERE club_id=? AND enabled=1 AND office='1. Vorstand' FOR SHARE NOWAIT",[$actor['club_id']])->fetchAll();$valid=[];
        foreach($rows as$row){try{$current=Permissions::actor($this->db,$row['actor_id']);if($current['club_id']===$actor['club_id']&&$current['role']!=='editor')$valid[]=$current['id'];}catch(DomainException){}}
        return count($valid)===1&&$valid[0]===$actor['id'];
    }
    private function reviewPage(array $rows,array $query):array
    {
        $expanded=($query['dashboard_reviews']??'')==='1';$search=$query['dashboard_reviews_query']??'';$rawPage=$query['dashboard_reviews_page']??1;$error='';$all=count($rows);
        if(isset($query['dashboard_reviews'])&&!in_array($query['dashboard_reviews'],['','1'],true))$error='Gültigen Berichtsbereich auswählen.';
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search)>240||preg_match('/[\x00-\x1f\x7f]/',$search))$error='Berichtssuche mit höchstens 240 Zeichen eingeben.';
        elseif($search!==''){$needle=mb_strtolower($search);$rows=array_values(array_filter($rows,static fn(array $row):bool=>str_contains(mb_strtolower($row['id'].' '.$row['from_year'].' '.$row['to_year']),$needle)));}
        $size=$expanded?25:5;$total=count($rows);$pages=max(1,(int)ceil($total/$size));$page=is_int($rawPage)||is_string($rawPage)?(string)$rawPage:'';
        if(!preg_match('/^[1-9][0-9]{0,8}$/D',$page)||(int)$page>$pages)$error='Verfügbare Berichtsseite auswählen.';
        return ['rows'=>$error===''?array_slice($rows,((int)$page-1)*$size,$size):[],'total'=>$total,'all_total'=>$all,'page'=>$error===''?(int)$page:null,'page_input'=>$page,'pages'=>$pages,'per_page'=>$size,'query'=>is_string($search)?$search:'','expanded'=>$expanded,'error'=>$error];
    }
    public function prepare(array $query):array
    {
        $owned=!$this->db->inTransaction();$state=['access'=>null,'personal'=>true,'profile'=>null,'members'=>null,'birthdays'=>[],'anniversaries'=>[],'default_anniversaries'=>false,'calendar'=>null,'finance'=>null,'inventory'=>null,'checks'=>null,'reviews'=>null,'setup'=>null,'quick'=>[],'personal_links'=>[],'errors'=>[],'http_status'=>0,'period'=>is_string($query['period']??'6')?($query['period']??'6'):''];
        try{
            if($owned)$this->db->exec('START TRANSACTION');$actor=Permissions::actor($this->db,$this->actorId);if($actor['role']==='editor')throw new DomainException('Keine Berechtigung für dieses Dashboard.',403);$state['access']=$actor;
            $registry=new Registry($this->db,$this->actorId,$this->website);$finance=new Finance($this->db,$this->actorId);$settings=new Settings($this->db,$this->actorId);$inventory=new Inventory($this->db,$this->actorId);$documents=new Documents($this->db,$this->actorId);$access=new WorkspaceAccess($this->db,$this->actorId);
            $state['personal']=$actor['members_access']==='none'&&$actor['finance_access']==='none'&&!$actor['settings_access'];
            if($actor['contact_id']!==null)$state['profile']=\Kulturhus\Native\Shared\Db::run($this->db,"SELECT id,name FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL FOR SHARE NOWAIT",[$actor['club_id'],$actor['contact_id']])->fetch()?:null;
            $part=static function(string $name,callable $read)use(&$state):mixed{
                try{return$read();}catch(\Throwable $failure){$state['errors'][$name]=$failure instanceof DomainException?$failure->getMessage():'Dashboard-Bereich konnte nicht geladen werden.';$status=$failure instanceof DomainException&&in_array($failure->getCode(),[403,422,503],true)?$failure->getCode():500;$state['http_status']=max($state['http_status'],$status);return null;}
            };
            $allowLink=static function(array $route)use($access,&$state):bool{
                try{return$access->allows($route);}catch(\Throwable){$state['errors']['links']='Einzelne Einstiege konnten nicht geladen werden. Bitte erneut öffnen.';$state['http_status']=max($state['http_status'],503);return false;}
            };
            $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin'));$today=$now->format('Y-m-d');
            if($actor['members_access']!=='none'){
                $state['members']=$part('members',fn():array=>$registry->memberOverview($today));
                $state['birthdays']=$part('birthdays',fn():array=>array_slice($registry->birthdayAgenda($today),0,3))??[];
                $state['anniversaries']=$part('anniversaries',fn():array=>array_slice($registry->anniversaries($now->modify('-7 days')->format('Y-m-d'),$now->modify('+1 year')->format('Y-m-d')),0,3))??[];
                $rules=$part('anniversaries',fn():array=>$registry->anniversaryRules());$state['default_anniversaries']=($rules['version']??-1)===0;
            }
            if($registry->canReadCalendar())$state['calendar']=$part('calendar',fn():array=>$registry->dashboardAppointments($today,$now->modify('+3 months')->format('Y-m-d')));
            if($inventory->canRead())$state['inventory']=$part('inventory',fn():array=>$inventory->summary());
            if($finance->canRead()){
                $period=$state['period'];$valid=is_string($period)&&in_array($period,['3','6','12'],true);
                if(!$valid){$state['errors']['period']='Zeitraum von 3, 6 oder 12 Monaten auswählen.';$state['http_status']=max($state['http_status'],422);}
                $start=$valid?$now->modify('first day of this month')->modify('-'.((int)$period-1).' months')->format('Y-m-d'):$today;
                $state['finance']=$part('finance',static function()use($finance,$start,$today,$valid,$period):array{
                    $accounts=$finance->accounts();return ['overview'=>$finance->overview($start,$today),'accounts'=>array_slice($accounts,0,6),'accounts_total'=>count($accounts),'from'=>$start,'to'=>$today,'period'=>$valid?$period:null,'write'=>$finance->canWrite()];
                });
            }
            if(BoardAccess::allowed($this->db,$this->actorId))$state['checks']=$part('checks',fn():array=>(new DocumentChecks($this->db,$this->actorId,$registry))->summary());
            if($this->currentChair($actor))$state['reviews']=$part('reviews',function()use($actor,$query):array{
                $rows=\Kulturhus\Native\Shared\Db::run($this->db,"SELECT id,report_kind,from_year,to_year FROM activity_report_reviews WHERE club_id=? AND state='review' ORDER BY to_year DESC,from_year DESC,id FOR SHARE NOWAIT",[$actor['club_id']])->fetchAll();return$this->reviewPage($rows,$query);
            });
            if(($state['reviews']['error']??'')!==''){$state['errors']['reviews']=$state['reviews']['error'];$state['http_status']=max($state['http_status'],422);}
            $state['setup']=$part('setup',fn():array=>$this->setup($query,$actor,$registry,$finance,$settings,$access));
            if(($state['setup']['error']??'')!==''){$state['errors']['setup']=$state['setup']['error'];$state['http_status']=max($state['http_status'],422);}
            foreach([
                [$registry->canWrite(),'Mitglied aufnehmen',['view'=>'members','new'=>1,'create_as'=>'member']],
                [$finance->canWrite(),'Zahlungen zuordnen',['view'=>'finance','tab'=>'bank','assignment'=>'open']],
                [$documents->canWrite(),'Dokumente ablegen',['view'=>'documents','new_document'=>1]],
                [$registry->canWriteCalendar(),'Termin planen',['view'=>'calendar','new_event'=>1]],
                [true,'Sendungen öffnen',['view'=>'messages']],
                [true,'Website bearbeiten',['view'=>'website']],
            ]as[$allowed,$label,$route])if($allowed&&$allowLink($route))$state['quick'][]=['label'=>$label,'route'=>$route];
            foreach([
                [true,'Chat','Nachrichten lesen und mit anderen Vereinsmitgliedern sprechen.',['view'=>'chat']],
                [$state['profile']!==null,'Mein Profil','Deine persönlichen Angaben und Mitgliedschaft.',['view'=>'portal','tab'=>'own']],
                [$state['profile']!==null,'Meine Belege','Deine ausgestellten Beitragsrechnungen und freigegebenen Dokumente herunterladen.',['view'=>'portal','tab'=>'documents']],
                [true,'Termine','Termine ansehen und deine Teilnahme zurückmelden.',['view'=>'calendar']],
                [true,'Sicher helfen','Hygiene, Ausschank, Erste Hilfe und Versicherung.',['view'=>'portal','tab'=>'safety']],
                [true,'Freie Listen','Gemeinsam organisieren und freigegebene Listen öffnen.',['view'=>'free']],
                [true,'Material','Verfügbares Vereinsmaterial ansehen.',['view'=>'material']],
                [true,'Protokolle','Freigegebene Besprechungen und Beschlüsse nachlesen.',['view'=>'minutes']],
                [true,'Presse','Deinen freigegebenen Pressebereich öffnen.',['view'=>'website','tab'=>'press']],
            ]as[$allowed,$label,$description,$route])if($allowed&&$allowLink($route))$state['personal_links'][]=['label'=>$label,'description'=>$description,'route'=>$route];
            $vouchers=new EventVouchers($this->db,$this->actorId,$this->website);
            if($vouchers->canRead())$state['personal_links'][]=['label'=>'Gutscheine ansehen','description'=>'Eigene Veranstaltungsgutscheine oder ausdrücklich zugewiesene Ausgabe öffnen.','route'=>['view'=>'portal','tab'=>'vouchers','voucher_scope'=>$state['profile']===null?'manage':'own']];
            if($owned)$this->db->commit();
        }catch(\Throwable $failure){
            if($owned&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))$failure=new DomainException('Dashboard wird gerade bearbeitet. Bitte erneut öffnen.',503,$failure);$state['access']=null;$state['profile']=null;$state['members']=null;$state['finance']=null;$state['calendar']=null;$state['inventory']=null;$state['setup']=null;$state['checks']=null;$state['reviews']=null;$state['birthdays']=[];$state['anniversaries']=[];$state['quick']=[];$state['personal_links']=[];
            $state['errors']['dashboard']=$failure instanceof DomainException?$failure->getMessage():'Dashboard konnte nicht geladen werden.';$state['http_status']=$failure instanceof DomainException?(in_array($failure->getCode(),[403,422,503],true)?$failure->getCode():403):500;
        }
        return$state;
    }
}
