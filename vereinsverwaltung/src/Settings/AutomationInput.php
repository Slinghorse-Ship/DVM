<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\CatalogBillingAutomation;

/** Exact original enable/pause intention; zero-day terms and whole wrong dates remain distinct. */
final class AutomationInput
{
    public static function capture(array $raw):array
    {
        $keys=array_keys($raw);sort($keys);if($keys!==['data','id','operation','tab','version']||!in_array($raw['operation']??null,['activate','pause'],true)||!in_array($raw['tab']??null,['rates','services'],true)||!is_array($raw['data']))throw new DomainException('Vollständige ursprüngliche Automatikangaben prüfen.',422);
        $id=CatalogInput::id($raw['id']);$version=GeneralInput::version($raw['version']);$keys=array_keys($raw['data']);sort($keys);if($keys!==['due_days','first','mode'])throw new DomainException('Vollständige ursprüngliche Automatikfelder prüfen.',422);
        foreach($raw['data']as$value)if(!is_string($value)||strlen($value)>10000||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Automatikangaben enthalten ungültige Daten.',422);
        return['operation'=>$raw['operation'],'tab'=>$raw['tab'],'id'=>$id,'version'=>(string)$version,'data'=>$raw['data']];
    }
    public static function normalize(array $input):array
    {
        $input=self::capture($input);$data=$input['data'];$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$data['first']);if(!$date||$date->format('Y-m-d')!==$data['first'])throw new DomainException('Vollständiges erstes Abrechnungsdatum im Format JJJJ-MM-TT prüfen.',422);
        if($input['operation']==='activate'&&$data['first']<CatalogBillingAutomation::today())throw new DomainException('Automatik frühestens ab heute aktivieren.',422);
        if(!in_array($data['mode'],['full','days'],true))throw new DomainException('Vollen Tarifbetrag oder anteilige Tage auswählen.',422);
        if(!preg_match('/\A(?:0|[1-9][0-9]?)\z/D',$data['due_days'])||(int)$data['due_days']>90)throw new DomainException('Vollständige Zahlungsfrist von 0 bis 90 Tagen prüfen.',422);
        return['first'=>$data['first'],'mode'=>$data['mode'],'due_days'=>(int)$data['due_days'],'enabled'=>$input['operation']==='activate'];
    }
}
