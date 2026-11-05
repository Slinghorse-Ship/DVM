<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\TaxAutoPreparation;
final class TaxAutoPackageForm
{
    public const COMPLETE='tax_auto_package_complete';
    public const ACTIONS=['tax-auto-package-review','tax-auto-package'];
    public static function requested(array $post):bool{return array_key_exists(self::COMPLETE,$post)||(is_string($post['action']??null)&&in_array($post['action'],self::ACTIONS,true));}
    public static function source(array $query,?int $year=null):void
    {
        if(($query['view']??null)!=='finance'||($query['tab']??null)!=='tax'||($query['tax_auto']??null)!=='1'||array_intersect(['tax_draft_outcome','tax_workpapers','balance','tax_filings','tax_review','tax_saved','tax_resume','annual_tax_outcome'],array_keys($query)))throw new DomainException('Jahrespaket im ursprünglichen Steuerassistenten erneut öffnen.',409);
        if(array_key_exists('tax_year',$query)&&(!is_string($query['tax_year'])||!preg_match('/\A[0-9]{4}\z/D',$query['tax_year'])||(int)$query['tax_year']<2000||(int)$query['tax_year']>(int)date('Y')||($year!==null&&(int)$query['tax_year']!==$year)))throw new DomainException('Ursprüngliches Erklärungsjahr vollständig prüfen.',409);
    }
    public static function original(array $post):array{return array_intersect_key($post,array_flip(['action','tax_year','version','preview_hash','tax_auto_package_key','tax_auto_package_issued','tax_auto_package_signature','tax_auto_package_confirmed',self::COMPLETE]));}
    public static function fields(array $post):array
    {
        if(($post[self::COMPLETE]??null)!=='1')throw new DomainException('Jahrespaketformular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        foreach(['action','tax_year','version','preview_hash','tax_auto_package_key']as$field)if(!is_string($post[$field]??null))throw new DomainException('Ursprünglichen Jahrespaketauftrag vollständig prüfen.',422);
        foreach(['tax_auto_package_issued','tax_auto_package_signature','tax_auto_package_confirmed']as$field)if(array_key_exists($field,$post)&&!is_string($post[$field]))throw new DomainException('Ursprünglichen Jahrespaketauftrag vollständig prüfen.',422);
        if(!in_array($post['action'],self::ACTIONS,true)||!preg_match('/\A[0-9]{4}\z/D',$post['tax_year'])||(int)$post['tax_year']<2000||(int)$post['tax_year']>(int)date('Y')||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$post['version'])||(int)$post['version']>2147483647||!preg_match('/\A[a-f0-9]{64}\z/D',$post['preview_hash'])||!preg_match('/\A[a-f0-9]{32}\z/D',$post['tax_auto_package_key']))throw new DomainException('Ursprüngliches Jahr, Fassung und Prüfung vollständig auswählen.',422);
        return self::original($post);
    }
    private static function signature(array $fields,string $actor,string $secret):string
    {
        return hash_hmac('sha256',json_encode([$actor,$fields['tax_year'],$fields['version'],$fields['preview_hash'],$fields['tax_auto_package_key'],$fields['tax_auto_package_issued']],JSON_THROW_ON_ERROR),$secret);
    }
    public static function handle(PDO $db,string $actor,array $query,array $post,string $csrf,array &$session):array
    {
        if(!is_string($post['csrf']??null)||!hash_equals($csrf,$post['csrf']))throw new DomainException('Sitzung abgelaufen. Bitte neu anmelden; Ihre Eingaben bleiben erhalten.',403);
        $fields=self::fields($post);$year=(int)$fields['tax_year'];$version=(int)$fields['version'];self::source($query,$year);$service=new TaxAutoPreparation($db,$actor);
        $key=$service->packageRequestKey($year,$version,$fields['preview_hash']);if(!hash_equals($key,$fields['tax_auto_package_key']))throw new DomainException('Die ursprüngliche Jahrespaketkennung passt nicht zu diesem Auftrag.',409);
        if($fields['action']==='tax-auto-package-review'){
            $existing=$service->packageRequest($key);if($existing!==null)return['fields'=>$fields,'existing'=>$existing,'key'=>$key];
            $review=$service->reviewPackage($year,$version,$fields['preview_hash']);
            if(!is_string($session['tax_auto_package_secret']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$session['tax_auto_package_secret']))$session['tax_auto_package_secret']=bin2hex(random_bytes(32));
            $fields['tax_auto_package_issued']=(string)time();$fields['tax_auto_package_signature']=self::signature($fields,$actor,$session['tax_auto_package_secret']);unset($fields['tax_auto_package_confirmed']);return['fields'=>$fields,'review'=>$review];
        }
        if(($fields['tax_auto_package_confirmed']??null)!=='1')throw new DomainException('Das vollständige Jahrespaket ausdrücklich bestätigen.',422);
        $issued=$fields['tax_auto_package_issued']??'';$signature=$fields['tax_auto_package_signature']??'';$secret=$session['tax_auto_package_secret']??'';
        if(!is_string($secret)||!preg_match('/\A[a-f0-9]{64}\z/D',$secret)||!preg_match('/\A[0-9]{10}\z/D',$issued)||(int)$issued>time()+5||time()-(int)$issued>7200||!preg_match('/\A[a-f0-9]{64}\z/D',$signature)||!hash_equals(self::signature($fields,$actor,$secret),$signature))throw new DomainException('Die ursprüngliche Jahrespaketprüfung ist abgelaufen oder passt nicht. Zuerst den gespeicherten Ausgang lesen.',409);
        return['fields'=>$fields,'stored'=>$service->savePackage($year,$version,$fields['preview_hash']),'key'=>$key];
    }
}
