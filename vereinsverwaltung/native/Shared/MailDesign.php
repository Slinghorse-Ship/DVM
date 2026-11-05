<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;

/** Presentation only. New messages freeze this brand; stored messages and issued PDFs stay untouched. */
final class MailDesign
{
    public static function brand(array $identity):array
    {
        $root=getenv('VEREIN_PRIVATE_ROOT');$file=is_string($root)&&$root!==''?rtrim($root,'/').'/mail-design.json':'';
        if($file!==''&&file_exists($file)){
            if(is_link($file)||!is_file($file)||(fileperms($file)&0077)||filesize($file)>16384)throw new \DomainException('Private Mailgestaltung prüfen.');
            $c=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
            if(($c['schema']??0)!==1||($c['theme']??'')!=='kulturhus-classic'||!is_string($c['origin']??null)||!preg_match('~\Ahttps://[a-zA-Z0-9.-]+\z~D',$c['origin'])||!is_string($c['name']??null)||!is_string($c['tagline']??null)||mb_strlen($c['name'])>200||mb_strlen($c['tagline'])>300)throw new \DomainException('Private Mailgestaltung prüfen.');
            if(rtrim((string)($identity['origin']??''),'/')===$c['origin']&&in_array($identity['name']??'',['','Mein Verein',$c['name']],true)){
                $identity['name']=$c['name'];$identity['mail_theme']=$c['theme'];$identity['mail_tagline']=$c['tagline'];$identity['mail_hero_url']=$c['origin'].'/assets/saxophone.jpg';
                if(empty($identity['logo_id'])&&empty($identity['logo_png'])&&($c['stock_logo']??false)===true)$identity['logo_png']=self::stockLogo();
            }
        }
        return $identity;
    }
    private static function stockLogo():string
    {
        $file=dirname(__DIR__).'/Web/assets/site/apple-touch-icon.png';$bytes=file_get_contents($file);
        if(!is_string($bytes)||strlen($bytes)>500000)throw new \DomainException('Mitgeliefertes Vereinszeichen fehlt.');return base64_encode($bytes);
    }
    private static function e(string $text):string{return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function mark(array $brand,bool $inline=true):string
    {
        $name=(string)($brand['name']??'Mein Verein');$url=$brand['logo_url']??'';
        if(($brand['logo_png']??'')!=='')$url=$inline?'cid:newsletter-logo@kulturhus':rtrim((string)$brand['origin'],'/').'/site/assets/kulturhus-public/apple-touch-icon.png';
        if($url!=='')return '<img src="'.self::e($url).'" width="52" height="52" alt="'.self::e($name).'" style="display:block;width:52px;height:52px;object-fit:contain;border:0">';
        return '<span style="display:inline-block;width:52px;height:52px;line-height:52px;text-align:center;border-radius:14px;background:#f4b83f;color:#14231c;font:bold 34px/52px Georgia,serif">'.self::e(mb_strtoupper(mb_substr($name,0,1))).'</span>';
    }
    public static function header(array $brand,bool $inline=true):string
    {
        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td style="padding-right:14px;vertical-align:middle">'.self::mark($brand,$inline).'</td><td style="color:#14231c;font-family:Georgia,serif;font-size:24px;font-weight:bold;line-height:1.15">'.self::e($brand['name']??'Mein Verein').'</td></tr></table>';
    }
    public static function confirmation(array $brand,string $title,string $instructions,string $confirm,string $label,string $unsubscribe):string
    {
        $e=self::e(...);$origin=rtrim($brand['origin'],'/');$hero=($brand['mail_hero_url']??'')!==''?'<tr><td><img src="'.$e($brand['mail_hero_url']).'" width="620" alt="Saxophon" style="display:block;width:100%;max-width:620px;height:auto;border:0"></td></tr>':'';
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($title).'</title></head><body style="margin:0;padding:0;background:#e7efeb;color:#14231c;font-family:Arial,Helvetica,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#e7efeb"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:620px;background:#fffaf0;border:1px solid #d7cdbb;border-radius:18px;overflow:hidden"><tr><td style="padding:20px 28px;background:#fff7e7">'.self::header($brand).'</td></tr>'.$hero.'<tr><td style="padding:34px 34px 24px"><span style="display:inline-block;padding:7px 12px;border-radius:999px;background:#f4b83f;color:#14231c;font-size:11px;font-weight:bold;letter-spacing:1.2px">NEWSLETTER</span><h1 style="margin:18px 0 16px;color:#14231c;font-family:Georgia,serif;font-size:34px;line-height:1.1">'.$e($title).'</h1><p style="margin:0 0 24px;font-size:17px;line-height:1.6">'.$e($instructions).'</p><p style="font-size:17px;line-height:1.6">Der Link gilt 48 Stunden. Deine bisherigen Angaben bleiben erhalten.</p><table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px"><tr><td style="border-radius:999px;background:#185c47"><a href="'.$e($confirm).'" style="display:inline-block;padding:15px 25px;color:#fff;text-decoration:none;font-size:16px;font-weight:bold">'.$e($label).'</a></td></tr></table><p style="color:#506159;font-size:13px;line-height:1.55">Falls die Schaltfläche nicht funktioniert, öffne diesen Link:<br><a href="'.$e($confirm).'" style="color:#185c47;word-break:break-all">'.$e($confirm).'</a></p></td></tr><tr><td style="padding:22px 34px 28px;background:#17352f;color:#fff;font-size:12px;line-height:1.6">Wenn du die Anmeldung nicht angefordert hast, kannst du diese Nachricht ignorieren.<br><a href="'.$e($unsubscribe).'" style="color:#dbe7e0">Anmeldung widerrufen</a><br><br><strong>'.$e($brand['name']).'</strong><br>'.nl2br($e($brand['address']??'')).'<br><a href="'.$e($origin.'/impressum/').'" style="color:#dbe7e0">Impressum</a> · <a href="'.$e($origin.'/datenschutz/').'" style="color:#dbe7e0">Datenschutz</a></td></tr></table></td></tr></table></body></html>';
    }
    /** Only presentation styles are added; authored text, links and credits remain in their original order. */
    public static function decorate(string $html):string
    {
        $old=libxml_use_internal_errors(true);$doc=new \DOMDocument('1.0','UTF-8');
        try{
            if(!$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><div id="mail-body">'.$html.'</div></body></html>',LIBXML_NONET))throw new \DomainException('Mailinhalt prüfen.');
            $path=new \DOMXPath($doc);$root=$path->query('//*[@id="mail-body"]')->item(0);$styles=['p'=>'margin:0 0 17px;font-size:16px;line-height:1.7;color:#17221c','h2'=>'margin:26px 0 15px;font-family:Georgia,serif;font-size:29px;line-height:1.15;color:#17352f','h3'=>'margin:24px 0 12px;font-family:Georgia,serif;font-size:24px;line-height:1.2;color:#17352f','a'=>'color:#176b52;font-weight:700','table'=>'width:100%;margin:20px 0;border-collapse:collapse','th'=>'padding:10px 12px;border:1px solid #cbd7d0;text-align:left;vertical-align:top','td'=>'padding:10px 12px;border:1px solid #cbd7d0;text-align:left;vertical-align:top','hr'=>'height:0;margin:28px 0;border:0;border-top:2px solid #d7dfd9','img'=>'max-width:100%;height:auto;border:0','figure'=>'margin:26px 0;overflow:hidden;border:1px solid #cbd7d0;border-radius:14px;background:#fff;box-shadow:6px 6px 0 #e5ae39','figcaption'=>'display:block;padding:22px'];
            foreach($styles as$tag=>$style)foreach($path->query('.//'.$tag,$root)as$node){$own=trim($node->getAttribute('style'));$node->setAttribute('style',$style.($own!==''?';'.$own:''));}
            foreach($path->query('.//a[contains(concat(" ",normalize-space(@class)," ")," communication-button ") or contains(concat(" ",normalize-space(@class)," ")," kh-newsletter-button ")]',$root)as$node)$node->setAttribute('style','display:inline-block;padding:12px 19px;border-radius:999px;background:#176b52;color:#fff;font-weight:800;text-decoration:none');
            $result='';foreach($root->childNodes as$child)$result.=$doc->saveHTML($child);return$result;
        }finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    }
    public static function browserNewsletter(array $snapshot,string $body,bool $test=false):string
    {
        $brand=$snapshot['_mail_design']??self::brand($snapshot['_identity']);if(($brand['logo_png']??'')!==''){$brand['logo_png']='';$brand['logo_url']='/site/assets/kulturhus-public/apple-touch-icon.png';}if(($brand['mail_hero_url']??'')!=='')$brand['mail_hero_url']='/assets/saxophone.jpg';$snapshot['_mail_design']=$brand;
        $version=substr(hash_file('sha256',dirname(__DIR__).'/Web/assets/newsletter-classic.css'),0,16);
        return preg_replace('~<style>[^<]*</style>~','<link rel="stylesheet" href="/assets/newsletter-classic.css?v='.$version.'">',self::newsletter($snapshot,$body,'','',$test),1);
    }
    public static function newsletter(array $snapshot,string $body,string $view,string $unsubscribe,bool $test=false):string
    {
        $b=$snapshot['_mail_design']??self::brand($snapshot['_identity']);$e=self::e(...);$origin=rtrim($b['origin'],'/');$heading=$snapshot['title'];$subtitle='';
        if(preg_match('/^[^:]+:\s*Termine\s+(.+?)\s+bis\s+(.+?)\s+(\d{4})$/u',$heading,$m)){$months=array_flip([1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember']);if(isset($months[$m[1]])){$heading=(string)(int)ceil($months[$m[1]]/3).'. Quartal';$subtitle=$m[1].' bis '.$m[2].' '.$m[3];}}
        elseif(preg_match('/^.+ im\s+(.+?)\s+(\d{4})$/u',$heading,$m)){$heading=$m[1].' '.$m[2];$subtitle='Termine und Informationen';}
        $footer='<a href="'.$e($origin.'/impressum/').'" style="color:#dbe7e0">Impressum</a> · <a href="'.$e($origin.'/datenschutz/').'" style="color:#dbe7e0">Datenschutz</a>';
        if($unsubscribe!=='')$footer.=' · <a href="'.$e($unsubscribe).'" style="color:#dbe7e0">Newsletter abmelden</a>';
        $hero=($b['mail_hero_url']??'')!==''?'<tr><td><img class="kh-mail-hero" src="'.$e($b['mail_hero_url']).'" width="680" height="145" alt="Saxophon" style="display:block;width:100%;height:145px;object-fit:cover;object-position:center 48%;border:0"></td></tr>':'';
        $banner=$test?'<p style="margin:0;padding:10px 16px;background:#f3c64e;color:#17221c;font-weight:800;text-align:center">TESTVORSCHAU · keine Verteilung an die Newsletterliste</p>':'';
        $viewLink=$view!==''?'<p style="margin:0;padding:10px 16px 0;color:#526057;font-size:11px;text-align:center">Wird diese E-Mail nicht vollständig angezeigt? <a href="'.$e($view).'" style="color:#176b52">Gesamte E-Mail im Browser ansehen</a></p>':'';
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($snapshot['title']).'</title><style>@media(max-width:520px){.kh-mail-wrap{padding:0!important}.kh-mail-card{border-radius:0!important}.kh-mail-brand{padding:14px 17px!important}.kh-mail-hero{height:112px!important}.kh-mail-heading,.kh-mail-content,.kh-mail-footer{padding:22px 17px!important}.kh-mail-heading h1{font-size:32px!important}}</style></head><body style="margin:0;background:#f3eddf;color:#17221c;font-family:Verdana,Geneva,sans-serif">'.$banner.$viewLink.'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td class="kh-mail-wrap" align="center" style="padding:28px 18px"><table role="presentation" class="kh-mail-card" width="680" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:680px;overflow:hidden;border:1px solid #c8d1cb;border-radius:9px;background:#fffdf6"><tr><td class="kh-mail-brand" style="padding:17px 24px;background:#fff8e8">'.self::header($b).'</td></tr>'.$hero.'<tr><td class="kh-mail-heading" style="padding:28px 24px 23px;background:#fff8e8;text-align:center"><h1 style="margin:0 0 7px;font-family:Georgia,serif;font-size:40px;line-height:1.05;color:#17221c">'.$e($heading).'</h1>'.($subtitle!==''?'<p style="margin:0;color:#176b52;font-size:14px;font-weight:800">'.$e($subtitle).'</p>':'').'</td></tr><tr><td class="kh-mail-content" style="padding:28px 42px 34px;background:#fffdf6">'.self::decorate($body).'</td></tr><tr><td class="kh-mail-footer" style="padding:23px 24px;background:#17352f;color:#fff;text-align:center"><strong style="font-family:Georgia,serif;font-size:19px">'.$e($b['name']).'</strong>'.(($b['mail_tagline']??'')!==''?'<p style="margin:5px 0;color:#f3c64e;font-size:12px;font-weight:800">'.$e($b['mail_tagline']).'</p>':'').'<p style="margin:10px 0 0;font-size:11px;line-height:1.55">'.$footer.'</p></td></tr></table></td></tr></table></body></html>';
    }
}
