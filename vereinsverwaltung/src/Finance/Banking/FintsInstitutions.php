<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/**
 * FinTS bank directory for the existing Kulturhus installation.
 *
 * The directory is operational data: the fresh-install exporter deliberately
 * excludes banking/fints-institutions.json so it is not redistributed.
 */
final class FintsInstitutions
{
    private static ?array $rows = null;
    private static string $fingerprint = '';

    public static function root():string
    {
        return rtrim(getenv('VEREIN_FINTS_ROOT')?:EnableBanking::root(),'/');
    }
    public static function legacyPath():string{return dirname(dirname(__DIR__,2),2).'/banking/fints-institutions.json';}

    /** @return list<array{id:string,bank_code:string,bic:string,name:string,city:string,url:string,version:string}> */
    public static function all(): array
    {
        $path=self::root().'/institutions/active.json';
        if(!file_exists($path)&&!is_link($path))$path=self::legacyPath();
        if (!is_file($path)) throw new DomainException('Die FinTS-Bankenliste fehlt.');
        if(is_link($path)||filesize($path)>8388608)throw new DomainException('Die FinTS-Bankenliste ist ungültig.');
        $bytes=(string)file_get_contents($path);$fingerprint=hash('sha256',$path."\0".$bytes);
        if(self::$rows!==null&&self::$fingerprint===$fingerprint)return self::$rows;
        try{$data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);}catch(\Throwable){throw new DomainException('Die FinTS-Bankenliste ist ungültig.');}
        $rows = $data['institutions'] ?? null;
        if (!is_array($rows) || count($rows) > 5000) throw new DomainException('Die FinTS-Bankenliste ist ungültig.');
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id']??null) || $row['id']==='' || !preg_match('/^[0-9]{8}$/D', (string)($row['bank_code'] ?? '')) || !preg_match('/^https:\/\//i', (string)($row['url'] ?? '')) || trim((string)($row['name'] ?? '')) === '') {
                throw new DomainException('Die FinTS-Bankenliste enthält einen ungültigen Eintrag.');
            }
        }
        self::$fingerprint=$fingerprint;return self::$rows = array_values($rows);
    }

    /** @return array{id:string,bank_code:string,bic:string,name:string,city:string,url:string,version:string} */
    public static function find(string $id): array
    {
        foreach (self::all() as $row) if ($row['id'] === $id) return $row;
        throw new DomainException('Bitte eine Bank aus der FinTS-Bankenliste auswählen.');
    }

    public static function defaultId(array $config): string
    {
        $code = (string)($config['bank_code'] ?? '');
        foreach (self::all() as $row) if ($row['bank_code'] === $code) return $row['id'];
        return '';
    }
}
