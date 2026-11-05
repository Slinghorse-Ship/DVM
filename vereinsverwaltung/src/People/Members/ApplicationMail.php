<?php declare(strict_types=1);
namespace Kulturhus\Verein;
final class ApplicationMail
{
    public static function html(string $text,string $baseUrl,string $title="Mitgliedsantrag bestätigen",string $actionUrl="",string $actionLabel="Mitgliedsantrag bestätigen",string $section="",string $clubName="Mein Verein",?string $reviewedBodyHtml=null):string
    {
        $e=static fn($s)=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        preg_match('~https://[^\s]+\?confirm_application=[a-f0-9]{64}~',$text,$m);$link=$m[0]??'';if($actionUrl!==''&&filter_var($actionUrl,FILTER_VALIDATE_URL)&&in_array(parse_url($actionUrl,PHP_URL_SCHEME),['http','https'],true))$link=$actionUrl;
        $body=$e($text);if($link!=='')$body=str_replace($e($link),'',$body);$bodyHtml=$reviewedBodyHtml??nl2br($body);
        $base=rtrim($baseUrl,'/');
        $brand=\Kulturhus\Native\Shared\MailDesign::brand(['name'=>$clubName,'origin'=>$base,'logo_id'=>'']);$clubName=$brand['name'];
        $mailHeader=\Kulturhus\Native\Shared\MailDesign::header($brand,false);$tagline=$brand['mail_tagline']??'DVM – DeinVereinsManager';
        $section=$section!==''?$section:($title==='Mitgliedsantrag bestätigen'?'MITGLIED WERDEN':'VEREINSNACHRICHT');
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>'.$e($title).'</title></head><body style="margin:0;background:#e7efeb;color:#14231c;font-family:Arial,Helvetica,sans-serif"><table role="presentation" width="100%"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="620" style="width:100%;max-width:620px;background:#fffaf0;border:1px solid #d7cdbb;border-radius:18px"><tr><td style="padding:24px 32px;background:#fff7e7;border-bottom:5px solid #f4b83f">'.$mailHeader.'</td></tr><tr><td style="padding:32px"><span style="color:#185c47;font-size:12px;font-weight:bold;letter-spacing:1px">'.$e($section).'</span><h1 style="font: bold 32px Georgia,serif">'.$e($title).'</h1><p style="font-size:17px;line-height:1.65">'.$bodyHtml.'</p>'.($link!==''?'<p style="margin:28px 0"><a href="'.$e($link).'" style="display:inline-block;background:#185c47;color:#fff;padding:16px 24px;border-radius:24px;text-decoration:none;font-weight:bold">'.$e($actionLabel).'</a></p><p style="font-size:13px;line-height:1.6">Falls der Button nicht funktioniert:<br><a style="color:#185c47;word-break:break-all" href="'.$e($link).'">'.$e($link).'</a></p>':'').'</td></tr><tr><td style="padding:24px 32px;background:#17352f;color:#fff;font-size:12px;line-height:1.7"><strong>'.$e($clubName).'</strong><br>'.$e($tagline).'<br><a style="color:#fff" href="'.$e($base.'/impressum/').'">Impressum</a> · <a style="color:#fff" href="'.$e($base.'/datenschutz/').'">Datenschutz</a></td></tr></table></td></tr></table></body></html>';
    }
}
