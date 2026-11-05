<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use PDO;use DomainException;use Kulturhus\Verein\{Permissions,MemberImport,MemberImportBankPermit};

/** Separate, actor-and-club-bound upload intents. Another browser tab never replaces this workbook. */
final class MemberImportSession
{
    private static function identity(PDO$db,string$actor):array{$a=Permissions::actor($db,$actor);if($a['members_access']!=='write')throw new DomainException('Mitglieder-Schreibrecht erforderlich.',403);return['actor'=>$actor,'club'=>$a['club_id']];}
    /** Reject a crossed-tab source before any preview or membership command is dispatched. */
    public static function source(array$query,#[\SensitiveParameter] array$post):void
    {
        if(($query['view']??null)!=='members'||($query['import']??null)!=='1'||array_intersect(['import_result','member_excel','id','new','tab','bank_export_id'],array_keys($query)))throw new DomainException('Excel-Import im ursprünglichen Mitgliederbereich öffnen.',409);
        $related=in_array($post['action']??null,['member-import-preview','member-import-apply','member-import-discard','member-import-bank-begin','member-import-bank-confirm'],true);
        if($related&&(!is_string($query['import_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$query['import_id'])||$query['import_id']!==($post['import_id']??null)))throw new DomainException('Link und Vorgang gehören nicht zu derselben ursprünglichen Importdatei.',409);
        if(!$related&&array_key_exists('import_id',$query))throw new DomainException('Excel-Import im ursprünglichen Mitgliederbereich öffnen.',409);
    }
    public static function create(PDO$db,string$actor,#[\SensitiveParameter] array$book,string$name,#[\SensitiveParameter] array&$session):string
    {
        $owner=self::identity($db,$actor);if(!mb_check_encoding($name,'UTF-8')||strlen($name)>255||preg_match('/[\x00-\x1f\x7f]/',$name))throw new DomainException('Dateinamen vollständig prüfen.',422);$stages=$session['member_excel_uploads']??[];foreach($stages as$id=>$stage)if(($stage['created']??0)<time()-3600)unset($stages[$id]);if(count($stages)>=4)throw new DomainException('Vier Dateien sind bereits zur Prüfung geöffnet. Eine davon bewusst verwerfen oder abschließen.',409);$id=bin2hex(random_bytes(16));$suggested=MemberImport::suggest($book);$stages[$id]=$owner+['id'=>$id,'created'=>time(),'name'=>$name,'book'=>$book,'sheet'=>$suggested['sheet'],'header'=>$suggested['header'],'mapping'=>$suggested['mapping'],'ambiguous'=>$suggested['ambiguous'],'start'=>'','hash'=>null];$session['member_excel_uploads']=$stages;return$id;
    }
    public static function get(PDO$db,string$actor,mixed$id,#[\SensitiveParameter] array$session):array
    {
        $owner=self::identity($db,$actor);if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Die ursprüngliche Importkennung vollständig prüfen.',422);$stage=$session['member_excel_uploads'][$id]??null;if(!is_array($stage)||($stage['actor']??null)!==$owner['actor']||($stage['club']??null)!==$owner['club']||($stage['created']??0)<time()-3600)throw new DomainException('Diese Importvorschau ist abgelaufen oder nicht für deinen aktuellen Vereinszugang verfügbar. Datei erneut auswählen.',409);return$stage;
    }
    public static function controls(array$input,array$stage):array
    {
        $sheet=$input['sheet']??(string)$stage['sheet'];$header=$input['header']??(string)$stage['header'];if(!is_string($sheet)||!preg_match('/\A(?:0|[1-9][0-9]?)\z/D',$sheet)||!isset($stage['book']['sheets'][(int)$sheet])||!is_string($header)||!preg_match('/\A(?:[1-9]|1[0-9]|20)\z/D',$header))throw new DomainException('Tabellenblatt und Überschriftszeile vollständig prüfen. Es wird kein anderes Blatt gewählt.',422);return['sheet'=>(int)$sheet,'header'=>(int)$header];
    }
    public static function preview(PDO$db,string$actor,#[\SensitiveParameter] array$post,#[\SensitiveParameter] array&$session):array
    {
        $stage=self::get($db,$actor,$post['import_id']??null,$session);$controls=self::controls($post,$stage);if(!is_array($post['mapping']??null)||!is_string($post['default_start']??null))throw new DomainException('Spaltenzuordnung und Eintritt vollständig prüfen.',422);$preview=(new MemberImport($db,$actor))->preview($stage['book'],$controls['sheet'],$controls['header'],$post['mapping'],$post['default_start']);if($stage['hash']!==$preview['hash'])MemberImportBankPermit::forget($session,$stage['id']);$stage=array_replace($stage,$controls,['mapping'=>$post['mapping'],'start'=>$post['default_start'],'hash'=>$preview['hash']]);$session['member_excel_uploads'][$stage['id']]=$stage;return$preview;
    }
    public static function apply(PDO$db,string$actor,#[\SensitiveParameter] array$post,#[\SensitiveParameter] array&$session):array
    {
        $id=$post['import_id']??null;$receipt=self::receipt($db,$actor,$id,$session);if($receipt!==null){if(($post['confirm_import']??null)!=='1'||!is_string($post['preview_hash']??null)||!is_string($receipt['preview_hash']??null)||!hash_equals($receipt['preview_hash'],$post['preview_hash']))throw new DomainException('Abgeschlossener Import gehört nicht zu dieser Bestätigung. Ursprünglichen Übernahmewunsch prüfen.',409);return$receipt;}$stage=self::get($db,$actor,$id,$session);if(($post['confirm_import']??null)!=='1'||!is_string($post['preview_hash']??null)||!is_string($stage['hash'])||!hash_equals($stage['hash'],$post['preview_hash']))throw new DomainException('Die vollständige Vorschau zuerst prüfen und die Übernahme ausdrücklich bestätigen.',422);
        $current=(new MemberImport($db,$actor))->preview($stage['book'],$stage['sheet'],$stage['header'],$stage['mapping'],$stage['start']);
        if(!hash_equals($stage['hash'],$current['hash']))throw new DomainException('Bestand seit der Vorschau geändert. Vorschau erneut erstellen.',409);
        $permit=$current['bank_count']>0?MemberImportBankPermit::fromSession($db,$actor,$stage['id'],$stage['hash'],$session):null;
        $ids=(new MemberImport($db,$actor))->apply($stage['book'],$stage['sheet'],$stage['header'],$stage['mapping'],$stage['start'],$post['preview_hash'],$permit);MemberImportBankPermit::forget($session,$stage['id']);$result=['id'=>$stage['id'],'actor'=>$stage['actor'],'club'=>$stage['club'],'created'=>time(),'filename'=>$stage['name'],'preview_hash'=>$stage['hash'],'imported'=>$ids,'count'=>count($ids)];$session['member_excel_results']??=[];foreach($session['member_excel_results']as$key=>$old)if(($old['created']??0)<time()-3600)unset($session['member_excel_results'][$key]);$session['member_excel_results'][$stage['id']]=$result;unset($session['member_excel_uploads'][$stage['id']]);return$result;
    }
    public static function bankConfirm(PDO $db,string $actor,#[\SensitiveParameter] array $post,#[\SensitiveParameter] array &$session):void
    {
        $stage=self::get($db,$actor,$post['import_id']??null,$session);
        $specific=match($post['action']??null){'member-import-bank-begin'=>['bank_password'],'member-import-bank-confirm'=>['bank_challenge','bank_code'],default=>throw new DomainException('Bankbestätigungsformular vollständig prüfen.',422)};
        if(array_diff(array_keys($post),['action','csrf','import_id','preview_hash',...$specific]))throw new DomainException('Bankbestätigungsformular vollständig prüfen.',422);
        if(!is_string($post['preview_hash']??null)||!is_string($stage['hash'])||!hash_equals($stage['hash'],$post['preview_hash']))throw new DomainException('Bankbestätigung gehört nicht zur aktuellen Importvorschau.',409);
        $preview=(new MemberImport($db,$actor))->preview($stage['book'],$stage['sheet'],$stage['header'],$stage['mapping'],$stage['start']);
        if(!hash_equals($stage['hash'],$preview['hash'])||$preview['bank_count']<1||array_filter($preview['rows'],static fn($row)=>$row['state']==='review'))throw new DomainException('Bankbestätigung benötigt die aktuelle vollständige und konfliktfreie Importvorschau.',409);
        if(($post['action']??null)==='member-import-bank-begin'){
            if(!is_string($post['bank_password']??null))throw new DomainException('Aktuelles Passwort vollständig eingeben.',422);
            MemberImportBankPermit::begin($db,$actor,$stage['id'],$stage['hash'],$session,$post['bank_password']);
        }elseif(($post['action']??null)==='member-import-bank-confirm'){
            if(!is_string($post['bank_challenge']??null)||!is_string($post['bank_code']??null))throw new DomainException('Sicherheitscode vollständig eingeben.',422);
            MemberImportBankPermit::confirm($db,$actor,$stage['id'],$stage['hash'],$session,$post['bank_challenge'],$post['bank_code']);
        }else throw new DomainException('Importaktion vollständig prüfen.',422);
    }
    public static function receipt(PDO$db,string$actor,mixed$id,#[\SensitiveParameter] array$session):?array
    {
        $owner=self::identity($db,$actor);if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))return null;$r=$session['member_excel_results'][$id]??null;return is_array($r)&&($r['actor']??null)===$owner['actor']&&($r['club']??null)===$owner['club']&&($r['created']??0)>=time()-3600?$r:null;
    }
    public static function discard(PDO$db,string$actor,mixed$id,#[\SensitiveParameter] array&$session):void{$stage=self::get($db,$actor,$id,$session);unset($session['member_excel_uploads'][$stage['id']]);MemberImportBankPermit::forget($session,$stage['id']);}
}
