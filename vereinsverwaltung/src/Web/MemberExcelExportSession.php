<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{Permissions,Registry,MemberWorkbook,MemberBankVault,MemberImportBankPermit,ProductionRuntime};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** One reviewed private bank export; source selection and native proof stay purpose-bound. */
final class MemberExcelExportSession
{
    public const ACTIONS=['member-excel-bank-begin','member-excel-bank-confirm','member-excel-bank-download','member-excel-bank-cancel'];
    private static function key(mixed $value,int $size=32):string
    {
        if(!is_string($value)||!preg_match('/\A[a-f0-9]{'.$size.'}\z/D',$value))throw new DomainException('Ursprüngliche Mitgliederauswahl für Bankexport prüfen.',422);return$value;
    }
    public static function route(array $query,#[\SensitiveParameter] array $post=[]):void
    {
        if(($query['view']??null)!=='members'||($query['import']??null)!=='1'||array_diff(array_keys($query),['view','import','bank_export_id']))throw new DomainException('Bankexport im ursprünglich geöffneten Mitgliederbereich prüfen.',422);
        $id=self::key($query['bank_export_id']??null);
        if($post!==[]){
            $action=$post['action']??null;if(!in_array($action,self::ACTIONS,true)||self::key($post['bank_export_id']??null)!==$id)throw new DomainException('Bankexport gehört nicht zur ursprünglichen Mitgliederauswahl.',409);
            $specific=match($action){'member-excel-bank-begin'=>['bank_password'],'member-excel-bank-confirm'=>['bank_challenge','bank_code'],default=>[]};
            if(array_diff(array_keys($post),['action','csrf','bank_export_id','bank_export_hash',...$specific]))throw new DomainException('Bankexportformular vollständig prüfen.',422);
            self::key($post['bank_export_hash']??null,64);
        }
    }
    private static function capture(PDO $db,string $actor,string $scope,string $search,bool $lock=false):array
    {
        if(!(new Registry($db,$actor))->canWrite())throw new DomainException('Mitglieder-Schreibrecht erforderlich.',403);
        if(!MemberBankVault::fromEnvironment($db,$actor)->canManageMembers())throw new DomainException('Mitglieder- und Bankverwaltungsrecht für den Bankdatenimport erforderlich.',403);$a=Permissions::actor($db,$actor);$records=MemberWorkbook::records($db,$actor,$scope,$search);$source=[];$count=0;
        $q=$db->prepare('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=?'.($lock?' FOR SHARE NOWAIT':''));$today=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        foreach($records as$record){
            $q->execute([$a['club_id'],$record['_contact_id']]);$bank=$q->fetch(PDO::FETCH_ASSOC);
            $source[$record['_contact_id']]=$bank?hash('sha256',serialize($bank)):null;
            if($bank&&$bank['status']!=='removed'){
                if($record['end']!==''&&$record['end']<$today)throw new DomainException('Bankdaten ehemaliger Mitglieder bleiben geschützt. Für Bankexport aktuelle Mitglieder auswählen.',403);
                $count++;
            }
        }
        if($count>0)MemberBankVault::fromEnvironment($db,$actor,ProductionRuntime::enabled())->importAvailable();
        return['actor'=>$actor,'club'=>$a['club_id'],'scope'=>$scope,'search'=>$search,'records'=>$records,'bank_count'=>$count,'hash'=>hash('sha256',json_encode(['bank-export',$a['club_id'],$scope,$search,$records,$source],JSON_THROW_ON_ERROR))];
    }
    public static function create(PDO $db,string $actor,string $scope,string $search,#[\SensitiveParameter] array &$session):string
    {
        $source=self::capture($db,$actor,$scope,$search);$stages=$session['member_excel_exports']??[];
        foreach($stages as$id=>$stage)if(($stage['created']??0)<time()-3600){unset($stages[$id]);MemberImportBankPermit::forget($session,$id,'export');}
        if(count($stages)>=4)throw new DomainException('Vier Bankexporte sind bereits zur Prüfung geöffnet. Eine Auswahl zuerst bewusst schließen.',409);
        $id=bin2hex(random_bytes(16));$stages[$id]=$source+['id'=>$id,'created'=>time()];$session['member_excel_exports']=$stages;return$id;
    }
    public static function get(PDO $db,string $actor,mixed $id,#[\SensitiveParameter] array $session,bool $lock=false,bool $refresh=true):array
    {
        $id=self::key($id);$stage=$session['member_excel_exports'][$id]??null;$a=Permissions::actor($db,$actor);
        if(!is_array($stage)||($stage['actor']??null)!==$actor||($stage['club']??null)!==$a['club_id']||($stage['created']??0)<time()-3600)throw new DomainException('Diese Bankexportauswahl ist abgelaufen oder für den aktuellen Zugang nicht verfügbar.',403);
        if(!$refresh)return$stage;
        $current=self::capture($db,$actor,$stage['scope'],$stage['search'],$lock);
        if(!hash_equals($stage['hash'],$current['hash']))throw new DomainException('Mitgliederauswahl, Familie, Beitrag oder Bankdaten seit der Exportprüfung geändert. Auswahl neu prüfen.',409);
        return$stage;
    }
    public static function confirm(PDO $db,string $actor,array $query,#[\SensitiveParameter] array $post,#[\SensitiveParameter] array &$session):void
    {
        self::route($query,$post);$stage=self::get($db,$actor,$post['bank_export_id'],$session);
        if(!hash_equals($stage['hash'],$post['bank_export_hash']))throw new DomainException('Bankexport gehört nicht zur ursprünglichen Mitgliederauswahl.',409);
        if($post['action']==='member-excel-bank-begin'){
            if(!is_string($post['bank_password']??null))throw new DomainException('Aktuelles Passwort vollständig eingeben.',422);
            MemberImportBankPermit::begin($db,$actor,$stage['id'],$stage['hash'],$session,$post['bank_password'],'export');
        }elseif($post['action']==='member-excel-bank-confirm'){
            if(!is_string($post['bank_challenge']??null)||!is_string($post['bank_code']??null))throw new DomainException('Sicherheitscode vollständig eingeben.',422);
            MemberImportBankPermit::confirm($db,$actor,$stage['id'],$stage['hash'],$session,$post['bank_challenge'],$post['bank_code'],'export');
        }else throw new DomainException('Bankexportformular vollständig prüfen.',422);
    }
    public static function download(PDO $db,string $actor,array $query,#[\SensitiveParameter] array $post,#[\SensitiveParameter] array $session):string
    {
        self::route($query,$post);if($post['action']!=='member-excel-bank-download')throw new DomainException('Bankexportformular vollständig prüfen.',422);
        return Transactions::run($db,static function()use($db,$actor,$post,$session):string{
            $stage=self::get($db,$actor,$post['bank_export_id'],$session,true);
            if(!hash_equals($stage['hash'],$post['bank_export_hash']))throw new DomainException('Bankexport gehört nicht zur ursprünglichen Mitgliederauswahl.',409);
            $records=$stage['records'];$permit=null;
            if($stage['bank_count']>0){$permit=MemberImportBankPermit::fromSession($db,$actor,$stage['id'],$stage['hash'],$session,'export');$records=MemberBankVault::fromEnvironment($db,$actor,ProductionRuntime::enabled())->exportReviewed($records,$permit,$stage['hash']);}
            $bytes=MemberWorkbook::create($records);if($permit!==null)$permit->assert($db,$actor,$stage['hash'],'export');
            $q=$db->prepare("INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,'members.excel.bank-exported',?,1,?)");$q->execute([$stage['club'],$actor,$stage['id'],gmdate('c')]);return$bytes;
        });
    }
    public static function close(PDO $db,string $actor,array $query,#[\SensitiveParameter] array $post,#[\SensitiveParameter] array &$session):void
    {
        self::route($query,$post);if($post['action']!=='member-excel-bank-cancel')throw new DomainException('Bankexportformular vollständig prüfen.',422);$stage=self::get($db,$actor,$post['bank_export_id'],$session,false,false);if(!hash_equals($stage['hash'],$post['bank_export_hash']))throw new DomainException('Bankexport gehört nicht zur ursprünglichen Mitgliederauswahl.',409);
        unset($session['member_excel_exports'][$stage['id']]);MemberImportBankPermit::forget($session,$stage['id'],'export');
    }
}
