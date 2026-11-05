<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;
use Kulturhus\Verein\SettingsSchema;

/** Closed original assignment intent; source selection and typed dates are never shortened. */
final class AssignmentInput
{
    public static function capture(array $input):array
    {
        $keys=array_keys($input);sort($keys);
        if($keys!==['data','id','operation','tab','version']||!in_array($input['operation']??null,['create','end'],true)||!in_array($input['tab']??null,['rates','services'],true))throw new DomainException('Vollständige ursprüngliche Zuweisungsangaben prüfen.',422);
        $id=CatalogInput::id($input['id'],$input['operation']==='create');$version=GeneralInput::version($input['version']);
        if(($input['operation']==='create'&&($id!==''||$version!==0))||($input['operation']==='end'&&($id===''||$version<1)))throw new DomainException('Ursprüngliche Zuweisung und Fassung gemeinsam prüfen.',422);
        $fields=$input['operation']==='create'?['catalog_id','contact_id','end','start']:['end'];
        if(!is_array($input['data']))throw new DomainException('Vollständige ursprüngliche Zuweisungsfelder prüfen.',422);
        $keys=array_keys($input['data']);sort($keys);if($keys!==$fields)throw new DomainException('Vollständige ursprüngliche Zuweisungsfelder prüfen.',422);
        foreach($input['data']as$value)if(!is_string($value)||strlen($value)>10000||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Zuweisungsfeld enthält ungültige Daten.',422);
        return['operation'=>$input['operation'],'tab'=>$input['tab'],'id'=>$id,'version'=>(string)$version,'data'=>$input['data']];
    }

    public static function normalize(array $input,?array $current=null):array
    {
        $input=self::capture($input);$data=$input['data'];
        if($input['operation']==='create'){
            CatalogInput::id($data['catalog_id']);
            if($data['contact_id']===''||strlen($data['contact_id'])>64)throw new DomainException('Aktiven eigenen Kontakt auswählen.',422);
            $normalized=['catalog_id'=>$data['catalog_id'],'contact_id'=>$data['contact_id'],'start'=>$data['start'],'end'=>$data['end']];
        }else{
            if(!$current||$current['id']!==$input['id'])throw new DomainException('Eigene ursprüngliche Tarifzuweisung auswählen.',404);
            $normalized=array_replace($current['data'],['end'=>$data['end']]);
        }
        try{SettingsSchema::date($normalized['start']);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}
        if($input['operation']==='end'&&$normalized['end']==='')throw new DomainException('Vollständiges Enddatum auswählen.',422);
        if($normalized['end']!==''){
            try{SettingsSchema::date($normalized['end']);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}
            if($normalized['end']<$normalized['start'])throw new DomainException('Ende liegt vor Beginn.',422);
        }
        return$normalized;
    }
}
