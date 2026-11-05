<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;

use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Language\{PublicResolver,Settings,StatusMessages};
use Kulturhus\Native\Routing\RequestPath;
use Kulturhus\Native\Shared\Access;

/** Public website configuration only: never reads a visitor's account preference. */
final class PublicLanguage
{
    public static function defaults():array
    {
        return ['locale'=>'de','content_locale'=>'de','messages'=>StatusMessages::messages('de'),'links'=>[],'home'=>'/'];
    }

    public static function resolve(\PDO $db,Access $access,string $club,string $request):array
    {
        $access->require('website','read');
        if(!hash_equals(ManagerBridge::clubKey($club),$access->club()))throw new \DomainException('Öffentlicher Vereinszugang nicht verfügbar.');
        $settings=new Settings($db,$access);$config=$settings->state()['config'];
        $context=self::context($db,$access,$settings,$config,$config['source']);
        try{$locale=(new RequestPath($request))->value('lang');if($locale===null)return ['context'=>$context,'error'=>null];Settings::code($locale);}
        catch(\InvalidArgumentException){return ['context'=>$context,'error'=>400];}
        try{$settings->language($locale);}
        catch(\DomainException){return ['context'=>$context,'error'=>404];}
        return ['context'=>self::context($db,$access,$settings,$config,$locale),'error'=>null];
    }

    private static function context(\PDO $db,Access $access,Settings $settings,array $config,string $locale):array
    {
        $messages=StatusMessages::messages('de');$contentLocale='de';$languages=array_column($config['languages'],null,'code');
        foreach(array_reverse($settings->chain($locale))as$code){
            if(in_array(explode('-',$code,2)[0],['de','en'],true)){$messages=array_replace($messages,StatusMessages::messages($code));$contentLocale=$code;}
            $overrides=array_intersect_key($languages[$code]['messages']??[],$messages);
            $messages=array_replace($messages,$overrides);if($overrides!==[])$contentLocale=$code;
        }
        $links=[];foreach($config['languages']as$language)if($language['enabled'])$links[]=['locale'=>$language['code'],'label'=>$language['label'],'href'=>$language['code']===$config['source']?'/status':'/status?'.http_build_query(['lang'=>$language['code']])];
        $home='/';
        if($locale!==$config['source']){$resolved=(new PublicResolver($db,$access))->resolve($locale.'/start');if($resolved!==null)$home='/'.$resolved['canonical_path'];}
        return ['locale'=>$locale,'content_locale'=>$contentLocale,'messages'=>$messages,'links'=>$links,'home'=>$home];
    }
}
