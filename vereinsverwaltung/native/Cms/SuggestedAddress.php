<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use PDO;
use InvalidArgumentException;

/** A new editor address is derived from its title while the club publication lock is held. */
final class SuggestedAddress
{
    public static function base(string $title):string
    {
        if(!preg_match('//u',$title))throw new InvalidArgumentException('Titel als gültigen Text eingeben.');
        $title=strtr(mb_strtolower(trim($title),'UTF-8'),['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
        $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$title);
        $value=trim(preg_replace('/[^a-z0-9]+/','-',strtolower($ascii===false?$title:$ascii)),'-');
        $value=rtrim(substr($value,0,130),'-');
        if($value===''||preg_match('/\A(?:assets|site|media|feeds|vereinsverwaltung|login|logout|account|accounts|invite|reset|forgot|preview|media-preview|chat-file|chat-image|chat-status|model-fields|reference-options|page-photo-status|album-photo-status|frontend-edit|frontend-preview|home-preview|sections-preview|design-preview|newsletter|newsletter-confirm|newsletter-unsubscribe|publication-image|publication-video|social-image|social-video|search|suche|status|extensions|api|file|file-preview|tickets|meta-webhook)\z/D',$value))$value='seite'.($value!==''?'-'.$value:'');
        return$value;
    }
    public static function choose(PDO $db,string $club,string $title,string $prefix=''):string
    {
        if(!in_array($prefix,['','galerie/','veranstaltungen/'],true))throw new InvalidArgumentException('Adressbereich prüfen.');
        $base=$prefix.self::base($title);
        for($number=1;$number<=1000;$number++){
            $candidate=$base.($number===1?'':'-'.$number);
            try{PathHistory::assertAvailable($db,$club,'',$candidate);return$candidate;}catch(Conflict){}
        }
        throw new InvalidArgumentException('Eine eigene freie Adresse in den weiteren Einstellungen eingeben.');
    }
}
