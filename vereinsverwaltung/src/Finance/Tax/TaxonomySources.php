<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Published sources, not fiscal output/transport permissions. No database writes. */
final class TaxonomySources
{
    private static function read(string $path):string
    {
        if(!is_file($path)||!is_readable($path))throw new DomainException('Geprüfte Taxonomiequelle fehlt.');
        $raw=file_get_contents($path);if($raw===false)throw new DomainException('Geprüfte Taxonomiequelle ist nicht lesbar.');return $raw;
    }
    public static function listing():array
    {
        $root=dirname(__DIR__, 2).'/../resources/ebilanz/';
        try{
            $registry=json_decode(self::read($root.'taxonomies.json'),true,64,JSON_THROW_ON_ERROR);
            if(($registry['schema']??null)!==1||array_keys($registry['sources']??[])!==['6.7','6.8','6.9','6.10'])throw new DomainException('Taxonomiequellen prüfen.');
            $releases=json_decode(self::read($root.'releases.json'),true,64,JSON_THROW_ON_ERROR);
            $out=[];
            foreach($registry['sources'] as $version=>$source){
                if(!is_string($source['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$source['sha256'])
                    ||!is_int($source['regular_start_year']??null)||($source['optional_start_year']??null)!==$source['regular_start_year']-1
                    ||($source['date']??null)!==$source['optional_start_year'].'-04-01'
                    ||!str_starts_with($source['source']??'','https://www.esteuer.de/download/'))throw new DomainException('Amtliche Jahresangabe der Taxonomie prüfen.');
                $raw=str_replace("\r\n","\n",self::read($root.'taxonomy-'.$version.'.json'));
                if(!hash_equals($source['sha256'],hash('sha256',$raw)))throw new DomainException('Amtliche Taxonomiequelle wurde verändert.');
                $meta=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
                if(($meta['version']??null)!==$version||($meta['date']??null)!==$source['date']||($meta['source']??null)!==$source['source']||empty($meta['source_files_sha256']))throw new DomainException('Taxonomiequelle und Metadaten stimmen nicht überein.');
                $ready=[];
                foreach($releases['packages']??[] as $package){
                    if(($package['taxonomy']['version']??null)!==$version||($package['taxonomy']['sha256']??null)!==$source['sha256']||($package['production_authorized']??null)!==false)continue;
                    if($version==='6.9'&&($package['adapter']??null)==='verein-6.9-v1'&&($package['stage']??null)==='catalog_and_local_validation'
                        &&!empty($package['eric']['reviewed_versions'])&&!array_diff($package['years'],[2025,2026]))$ready=array_merge($ready,$package['years']);
                }
                $out[$version]=$source+['local_validation_years'=>$ready];
            }
            return $out;
        }catch(\JsonException|\TypeError $e){throw new DomainException('Geprüfte Taxonomiequellen sind nicht verfügbar.',0,$e);}
    }
}
