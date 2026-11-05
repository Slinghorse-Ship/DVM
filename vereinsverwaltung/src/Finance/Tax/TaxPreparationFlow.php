<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Small, actor-bound preview receipts in the private session; document bytes stay out of it. */
final class TaxPreparationFlow
{
    private static function parameters(array $input):array
    {
        foreach(['from_year','to_year','activity_report'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new DomainException('Zeitraum und Tätigkeitsbericht prüfen.');
        foreach(['from_year','to_year'] as $key)if(!preg_match('/^[0-9]{4}$/D',$input[$key]??''))throw new DomainException('Gültige Kalenderjahre eingeben.');
        foreach(['tax_documents','tax_year_notes','tax_guide'] as $key)if(isset($input[$key])&&!is_array($input[$key]))throw new DomainException('Jahresangaben und Dokumentauswahl prüfen.');
        return ['from'=>(int)$input['from_year'],'to'=>(int)$input['to_year'],'activity'=>$input['activity_report']??'','selected'=>$input['tax_documents']??[],'notes'=>$input['tax_year_notes']??[],'guide'=>$input['tax_guide']??[]];
    }
    private static function inputHash(array $parameters):string{return hash('sha256',json_encode($parameters,JSON_THROW_ON_ERROR));}
    public static function preview(TaxPreparation $service,string $actor,array $input,array &$session):array
    {
        $p=self::parameters($input);$bundle=$service->build($p['from'],$p['to'],$p['activity'],$p['selected'],$p['notes'],$p['guide']);
        $previews=is_array($session['tax_previews']??null)?$session['tax_previews']:[];
        $previews=array_filter($previews,static fn($v)=>is_array($v)&&($v['actor']??'')===$actor&&($v['expires']??0)>=time());
        while(count($previews)>=3)array_shift($previews);
        $token=bin2hex(random_bytes(32));$previews[$token]=['actor'=>$actor,'expires'=>time()+7200,'input_hash'=>self::inputHash($p),'hash'=>$bundle['hash'],'request_key'=>bin2hex(random_bytes(16))];$session['tax_previews']=$previews;
        return $bundle+['token'=>$token];
    }
    public static function save(TaxPreparation $service,string $actor,array $input,array &$session):string
    {
        $token=$input['tax_preview_token']??'';
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new DomainException('Bitte zuerst eine vollständige Vorschau erstellen.');
        $receipt=$session['tax_previews'][$token]??null;
        if(!is_array($receipt)||($receipt['actor']??'')!==$actor||($receipt['expires']??0)<time())throw new DomainException('Die Vorschau ist abgelaufen. Bitte erneut erstellen.');
        $p=self::parameters($input);
        if(!hash_equals($receipt['input_hash'],self::inputHash($p)))throw new DomainException('Die Eingaben wurden nach der Vorschau geändert. Bitte die Vorschau erneut erstellen.',409);
        if(isset($receipt['document'])){$first=$service->savedRequest($receipt['request_key'],$receipt['hash']);if(!$first||$first['document']['id']!==$receipt['document'])throw new DomainException('Gespeicherten ursprünglichen Jahresauftrag prüfen; keinen zweiten Stand auslösen.',409);}
        $id=$service->save($p['from'],$p['to'],$p['activity'],$p['selected'],$receipt['request_key'],$p['notes'],$p['guide'],$receipt['hash']);
        $session['tax_previews'][$token]['document']=$id;return $id;
    }
}
