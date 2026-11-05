<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Versioned reference data; does not implement DATEV posting or reporting semantics. */
require_once __DIR__.'/DatevComparisonCatalog.php';
final class TaxCatalog
{
    public const YEAR=2026;
    /** Only editions with verified original source bytes are available. */
    public const YEARS=[2025,2026];
    public const KEY_SOURCE='https://wissensplattform.apps.datev.de/help/document/0907048';
    public const SOURCE='https://download.datev-shop.de/X7xkGEfqdeYuynPtuXCf5YKaSZyEji6f/12021';
    public const OFFICIAL_PAGES=['euer'=>'https://www.datev.de/web/de/shop/produkt-details/datev-kontenrahmen-skr-42-vereine-stiftungen-ggmbh-4-abs-3-estg-12902','balance'=>'https://www.datev.de/web/de/shop/produkt-details/12901'];
    public const SPHERES=['1'=>'Ideeller Bereich','2'=>'Vermögensverwaltung','3'=>'Zweckbetrieb','4'=>'Wirtschaftlicher Geschäftsbetrieb','9'=>'Sammelposten'];
    public const GROUPS=['current'=>'Umlaufvermögen','revenue'=>'Betriebliche Erträge','fixed'=>'Anlagevermögen','liabilities'=>'Fremdkapital','equity'=>'Eigenkapital','expense'=>'Betriebliche Aufwendungen','other'=>'Weitere Erträge und Aufwendungen','carry'=>'Vortrags- und statistische Konten'];
    /** Original SKR42 KOST1 values: preserve subdivisions, aggregate only for spheres. */
    public static function sphereOf(string $costCenter):string
    {
        if(isset(self::SPHERES[$costCenter]))return $costCenter;
        return preg_match('/^[1-4][1-9]$/D',$costCenter)===1?$costCenter[0]:'';
    }
    public static function costCenters():array
    {
        $out=[];
        foreach(self::SPHERES as $sphere=>$label){
            $out[(string)$sphere]=$label;
            if((string)$sphere==='9')continue;
            for($part=1;$part<=9;++$part){$code=(string)$sphere.(string)$part;$out[$code]=$label.' · Unterteilung '.$code;}
        }
        return $out;
    }
    public static function group(string $code):string
    {
        if(in_array($code,['13800','13820'],true))return 'fixed'; // Original long-term unnetted assets; source profile required for export.
        return match($code[0]??''){'0'=>'fixed','1'=>'current','2'=>'equity','3'=>'liabilities','4'=>'revenue','5','6'=>'expense','7'=>'other','9'=>'carry',default=>throw new \DomainException('Diese Kontenklasse ist nicht unterstützt.')};
    }
    public static function documentYear(string $date):int
    {
        if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]))return 0;
        return (int)$parts[1];
    }
    public static function hasYear(int $year):bool{return in_array($year,self::YEARS,true);}
    /**
     * Use the exact verified catalogue when available. Older receipts can
     * still be assigned to the current verified SKR42 catalogue; the stored
     * catalogue_year keeps that fallback visible for later export review.
     */
    public static function assignmentYear(int $documentYear):int
    {
        return $documentYear>0&&self::hasYear($documentYear)?$documentYear:self::YEAR;
    }
    public static function assignmentUsesFallback(int $documentYear):bool
    {
        return $documentYear>0&&!self::hasYear($documentYear);
    }
    public static function accounts(int $year=self::YEAR):array
    {
        if(!self::hasYear($year))return [];
        $data=self::sourceData('skr42-'.$year.'.json');$comparison=DatevComparisonCatalog::codes($year,$data);
        static $cache=[];if(isset($cache[$year]))return $cache[$year];$rows=[];
        foreach($data['accounts'] as $r){$blocked=$r['function']==='R'||isset($r['alternatives'])||isset($r['range_issue'])||in_array($r['code'],$comparison,true);$rows[$r['code']]=$r+['category'=>self::group($r['code']),'year'=>$year,'active'=>(int)!$blocked,'custom'=>0,'version'=>0,'blocked'=>$blocked,'comparison_only'=>in_array($r['code'],$comparison,true)];}
        ksort($rows,SORT_STRING);return $cache[$year]=$rows;
    }
    public static function sourceData(string $file):array
    {
        $allowed=array_map(static fn($year)=>'skr42-'.$year.'.json',self::YEARS);$allowed[]='datev-keys-2026.json';$allowed[]='datev-keys-2025.json';
        if(!in_array($file,$allowed,true))throw new \DomainException('Unbekannter Katalog.');
        static $cache=[];if(isset($cache[$file]))return $cache[$file];
        $data=json_decode(file_get_contents(dirname(__DIR__, 2).'/../data/'.$file),true,512,JSON_THROW_ON_ERROR);
        if((int)($data['year']??0)!==(int)substr($file,-9,4))throw new \RuntimeException('Kontenquelle und Katalogjahr stimmen nicht überein.');
        return $cache[$file]=$data;
    }
    /** Immutable local source metadata shown to administrators; updates are never activated implicitly. */
    public static function sourceStatus(int $year=self::YEAR):array
    {
        if(!self::hasYear($year))return ['year'=>$year,'state'=>'unavailable','source'=>'','sha256'=>''];
        $data=self::sourceData('skr42-'.$year.'.json');
        return ['year'=>$year,'state'=>'active_verified','source'=>(string)($data['source']??''),'sha256'=>(string)($data['sha256']??''),'source_entries'=>(int)($data['source_entries']??0)];
    }
    public static function reserved(string $code,int $year=self::YEAR):bool
    {
        if(!self::hasYear($year))return true;
        foreach(self::sourceData('skr42-'.$year.'.json')['ranges'] as $range)if($range['function']==='R'&&$code>=$range['code']&&$code<=$range['end'])return true;return false;
    }
    public static function bookingKeys(int $year=self::YEAR):array{return in_array($year,[2025,2026],true)?self::sourceData('datev-keys-'.$year.'.json')['keys']:[];}
    public static function supportedKeys(int $year=self::YEAR):array
    {
        // Separate official editions; no relabeling a current catalog as a historical one.
        if(!in_array($year,[2025,2026],true))return [];
        // Local gross calculation supports these ordinary domestic situations only.
        $rules=['2'=>['income',7],'3'=>['income',19],'8'=>['expense',7],'9'=>['expense',19],'101'=>['income',19],'102'=>['income',7],'401'=>['expense',19],'402'=>['expense',7]];$out=[];
        foreach(self::bookingKeys($year) as $row)if(isset($rules[$row['code']])){$rule=$rules[$row['code']];if((string)$rule[1]!==$row['rate'])throw new \RuntimeException('Schlüsselrate passt nicht zur geprüften Zuordnung.');$out[$row['code']]=$row+['direction'=>$rule[0],'local_rate'=>$rule[1]];}
        if(count($out)!==count($rules))throw new \RuntimeException('Geprüfte Schlüssel fehlen im Katalog.');return $out;
    }
    public static function starterAccounts():array
    {
        // Number mappings checked against DATEV SKR42, January 2026. Concise local labels.
        $names=['01350'=>'Software (erworben)','06300'=>'Ausstattung des Betriebs','06500'=>'Einrichtung im Büro','06700'=>'Geringwertige Anlagegüter',
            '11600'=>'Gespendete Waren und Materialien','12000'=>'Forderungen aus Lieferungen/Leistungen','13700'=>'Durchlaufbeträge','13720'=>'Transit zwischen Geldkonten','16000'=>'Barkasse','16100'=>'Weitere Kasse 1','16200'=>'Weitere Kasse 2','18000'=>'Bankkonto','18100'=>'Weiteres Bankkonto 1',
            '29960'=>'Langfristig gebundene Spenden','29970'=>'Noch zweckgemäß einzusetzende Spenden','30000'=>'Pensionsrückstellungen',
            '40000'=>'Mitgliedsbeiträge (echte)','40100'=>'Gebühren bei Aufnahme','40200'=>'Umlagen der Mitglieder','40300'=>'Schenkungen als Einnahme',
            '40400'=>'Spenden und Zuwendungen (Ertrag)','40450'=>'Geldspenden mit Bestätigung','40500'=>'Geldspenden ohne Bestätigung','40550'=>'Sachspenden mit Bestätigung','40600'=>'Sachspenden ohne Bestätigung','40650'=>'Aufwandsspenden mit Bestätigung','40700'=>'Aufwandsspenden ohne Bestätigung','40750'=>'Verbrauch von Spenden (Ertrag)',
            '63300'=>'Raumreinigung','64000'=>'Versicherungsaufwand','68000'=>'Postgebühren','68050'=>'Telefonkosten','68100'=>'Internetanschluss','68150'=>'Büromaterial','68210'=>'Fortbildung','68300'=>'Buchführungsaufwand'];
        $rows=[];foreach($names as $code=>$name){$code=(string)$code;$rows[$code]=['code'=>$code,'name'=>$name,'category'=>self::group($code),'year'=>self::YEAR,'active'=>1,'custom'=>0,'version'=>0];}return $rows;
    }

    /**
     * The small set normally needed while checking an incoming club invoice.
     * The complete SKR42 catalogue remains available in the accounting settings;
     * this keeps the invoice form understandable for occasional users.
     */
    public static function commonInvoiceCodes():array
    {
        return ['01350','06300','06500','06700','63040','63300','64000','66310','68000','68050','68100','68150','68210','68300'];
    }

    /** Return only common, active expense/asset accounts for the given verified year. */
    public static function commonInvoiceAccounts(int $year=self::YEAR):array
    {
        if(!self::hasYear($year))return [];
        $all=self::accounts($year);$rows=[];
        foreach(self::commonInvoiceCodes() as $code){$account=$all[$code]??null;if($account&&$account['active'])$rows[$code]=$account;}
        return $rows;
    }
}
