<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Kulturhus\Native\Shared\RichText;

/** Validated text, sanitized rich text and structured media references; no remote fetching or executable templates. */
final class Content
{
    public static function text(mixed $value, int $max, bool $required = false): string
    {
        if (!is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new DomainException('Ungültiges Textfeld.');
        $value = trim($value);
        if ($required && $value === '') throw new DomainException('Ein Pflichtfeld fehlt.');
        return $value;
    }
    /** Originals and human replies retain their exact whitespace after validation. */
    public static function originalText(mixed $value, int $max, bool $required = false): string
    {
        self::text($value,$max,$required);
        return $value;
    }

    public static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new DomainException('Ungültige Kennung.');
        return $id;
    }

    public static function time(string $at): string
    {
        // Explicit timezone required, so DST and host timezone cannot silently change a schedule.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $at)) throw new DomainException('Zeitpunkt als ISO-8601 mit Zeitzone angeben.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $at);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) throw new DomainException('Ungültiger Zeitpunkt.');
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function email(mixed $email): string
    {
        $email = strtolower(self::text($email, 254, true));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new DomainException('E-Mail-Adresse prüfen.');
        return $email;
    }

    public static function url(mixed $url): string
    {
        $url = self::text($url, 2000, true);
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || preg_match('/[\s\\\\]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) throw new DomainException('Links benötigen eine HTTPS-Adresse ohne Zugangsdaten.');
        return $url;
    }

    public static function blocks(mixed $blocks): array
    {
        if (!is_array($blocks) || !array_is_list($blocks) || count($blocks) > 100) throw new DomainException('Höchstens 100 Inhaltsbausteine erlaubt.');
        $result = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) throw new DomainException('Ungültiger Baustein.');
            $type = $block['type'] ?? '';
            $result[] = match ($type) {
                'richtext' => ['type'=>$type,'html'=>RichText::sanitize(self::text($block['html']??'',10000,true))],
                'text', 'heading' => ['type'=>$type, 'text'=>self::text($block['text'] ?? '', $type === 'text' ? 10000 : 300, true)],
                'image' => ['type'=>$type, 'media_ref'=>self::id($block['media_ref'] ?? ''), 'alt'=>self::text($block['alt'] ?? '', 500, true)],
                'button' => ['type'=>$type, 'text'=>self::text($block['text'] ?? '', 100, true), 'url'=>self::url($block['url'] ?? '')],
                'divider' => ['type'=>$type],
                'event' => ['type'=>$type, 'title'=>self::text($block['title'] ?? '', 300, true), 'date'=>self::text($block['date'] ?? '', 100, true), 'venue'=>self::text($block['venue'] ?? '', 300), 'text'=>self::text($block['text'] ?? '', 5000)] + (!empty($block['media_ref'])?['media_ref'=>self::id($block['media_ref'])]:[]) + (!empty($block['url'])?['url'=>self::url($block['url'])]:[]),
                default => throw new DomainException('Unbekannter Bausteintyp.'),
            };
        }
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 150000) throw new DomainException('Inhalt zu groß.');
        return $result;
    }

    public static function defaults(string $module): array
    {
        return match ($module) {
            'newsletter' => [['id'=>'newsletter-standard','name'=>'Kulturhus Newsletter','kind'=>'edition','title'=>'Neues aus dem Kulturhus','body'=>'Kultur, Begegnungen und kommende Termine.','blocks'=>[]]],
            'social' => [
                ['id'=>'social-post','name'=>'Kulturhus Beitrag','kind'=>'post','title'=>'Kultur gemeinsam erleben','body'=>'Neues aus dem Kulturhus.','blocks'=>[]],
                ['id'=>'social-story','name'=>'Kulturhus Story','kind'=>'story','title'=>'Termin merken','body'=>'Kultur gemeinsam erleben.','blocks'=>[]],
                ['id'=>'social-reel','name'=>'Kulturhus Reel','kind'=>'reel','title'=>'Eigenes Reel','body'=>'','blocks'=>[]],
                ['id'=>'social-carousel','name'=>'Karussell','kind'=>'carousel','title'=>'Neues Karussell','body'=>'','blocks'=>[], 'carousel'=>[]],
            ],
            'press' => [['id'=>'press-release','name'=>'Kulturhus Pressemitteilung','kind'=>'release','title'=>'Pressemitteilung','body'=>"Titel der Veranstaltung\nDatum und Ort\nBeschreibung\nKontakt für Rückfragen",'blocks'=>[]]],
            default => throw new DomainException('Unbekanntes Modul.'),
        };
    }

    public static function document(string $module, array $input): array
    {
        $hasRich = array_key_exists('body_html',$input);
        $rich = $hasRich ? RichText::sanitize(self::text($input['body_html'],1000000)) : '';
        $plain = $hasRich ? RichText::plain($rich) : self::text($input['body'] ?? '',100000);
        $kinds = match ($module) {'newsletter'=>['edition'], 'social'=>['post','story','reel','carousel'], 'press'=>['release']};
        $kind = $input['kind'] ?? $kinds[0];
        if (!in_array($kind, $kinds, true)) throw new DomainException('Inhaltsart gehört nicht zu diesem Modul.');
        $template = self::text($input['template'] ?? self::defaults($module)[$kind === 'story' ? 1 : ($kind === 'reel' ? 2 : ($kind==='carousel'?3:0))]['id'], 64, true);
        $channels = $input['channels'] ?? ($module === 'social' ? ['facebook','instagram'] : []);
        if (!is_array($channels) || !array_is_list($channels) || count($channels) > 2) throw new DomainException('Ungültige Kanalauswahl.');
        foreach ($channels as $channel) if (!is_string($channel) || $module !== 'social' || !in_array($channel,['facebook','instagram'],true)) throw new DomainException('Ungültige Kanalauswahl.');
        $story = $input['story'] ?? [];
        if (!is_array($story) || !array_is_list($story) || count($story) > 10 || ($kind !== 'story' && $story)) throw new DomainException('Story benötigt höchstens zehn Folien.');
        $slides = [];
        foreach ($story as $slide) {
            if (!is_array($slide)) throw new DomainException('Ungültige Story-Folie.');
            $media = $slide['media_ref'] ?? '';
            $slides[] = ['title'=>self::text($slide['title'] ?? '', 200, true), 'text'=>self::text($slide['text'] ?? '', 1200), 'media_ref'=>$media === '' ? '' : self::id($media)];
        }
        $carousel=Carousel::normalize($input['carousel']??[]);
        if($kind!=='carousel'&&$carousel)throw new DomainException('Karussell-Bilder gehören zum Karussell-Format.');
        $audience = $input['audience'] ?? 'all';
        if (!in_array($audience, ['all','events','news'], true)) throw new DomainException('Zielgruppe prüfen.');
        $ids = $input['recipient_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 500 || ($module !== 'press' && $ids)) throw new DomainException('Presseempfänger prüfen.');
        $ids = array_values(array_unique(array_map(self::id(...), $ids)));
        return [
            'title'=>self::text($input['title'] ?? '', 300, true), 'summary'=>self::text($input['summary'] ?? '', 2000),
            'body'=>$plain, 'kind'=>$kind, 'template'=>$template,
            'blocks'=>self::blocks($input['blocks'] ?? []), 'channels'=>array_values(array_unique($channels)),
            'story'=>$slides, 'audience'=>$audience, 'recipient_ids'=>$ids,
            'destination_url'=>empty($input['destination_url'])?'':self::url($input['destination_url']),
        ] + ($module==='social'&&$kind==='reel'&&array_key_exists('video_design',$input)?['video_design'=>['animation'=>ReelRenderer::animation(is_array($input['video_design'])?($input['video_design']['animation']??null):null)]]:[]) + ($module==='newsletter'&&array_key_exists('document_attachments',$input)?['document_attachments'=>DocumentAttachments::normalize($input['document_attachments'])]:[]) + ($module==='press'?['attachment_media_ids'=>PressPhotoAttachments::ids($input['attachment_media_ids']??[])]:[]) + ($kind==='carousel'?['carousel'=>$carousel]:[]) + ($module==='social' ? ['image_design'=>ImageDesign::options($input['image_design']??[]),'story_link_requested'=>$kind==='story'&&($input['story_link_requested']??false)===true] : []) + ($rich !== '' ? ['body_html'=>$rich] : []);
    }

    public static function preview(array $item,?callable $media=null,bool $localImages=false,bool $includeHeader=true): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $image=static function(string $id,string $alt)use($item,$media,$e,$localImages):string{if(($item['_media_usage']['media'][$id]['version']??0)>0)$alt=$item['_media_usage']['media'][$id]['alt'];$url=$media?$media($id):($item['_image_urls'][$id]??'');if($url==='')return '<p>Bild noch nicht verfügbar: '.$e($alt).'</p>';if(!$media){self::url($url);$path=parse_url($url,PHP_URL_PATH);if($localImages&&is_string($path)&&preg_match('~\A/publication-image/[a-f0-9]{64}\z~D',$path))$url=$path;}return '<img src="'.$e($url).'" alt="'.$e($alt).'" style="max-width:100%;height:auto">';};
        $credit=static function(string $id)use($item,$e):string{$m=$item['_media_usage']['media'][$id]??[];$parts=[];if(($m['credit']??'')!=='')$parts[]=$e($m['credit']);foreach(['source_name'=>'source_url','license_name'=>'license_url']as$label=>$url){if(($m[$label]??'')==='')continue;$text=$e($m[$label]);$parts[]=($m[$url]??'')!==''?'<a href="'.$e($m[$url]).'" rel="noopener noreferrer">'.$text.'</a>':$text;}return $parts?' <span class="photo-credit">'.implode(' · ',$parts).'</span>':'';};
        $identity=$item['_identity']??['name'=>'','logo_id'=>''];
        $html = '<article class="communication-preview">'.($includeHeader?'<header>'.($identity['logo_id']!==''?$image($identity['logo_id'],$identity['name']).$credit($identity['logo_id']):'').'<p>'.$e($identity['name']).'</p><h2>'.$e($item['title']).'</h2></header>':($identity['logo_id']!==''?$credit($identity['logo_id']):''));
        if ($item['summary'] !== '') $html .= '<p>'.$e($item['summary']).'</p>';
        if (($item['body_html'] ?? '') !== '') $html .= '<div class="kh-richtext">'.RichText::sanitize($item['body_html']).'</div>';
        elseif ($item['body'] !== '') $html .= '<p>'.nl2br($e($item['body'])).'</p>';
        foreach ($item['blocks'] as $block) {
            $html .= match ($block['type']) {
                'richtext'=>'<div class="kh-richtext">'.RichText::sanitize($block['html']).'</div>',
                'text'=>'<p>'.nl2br($e($block['text'])).'</p>',
                'heading'=>'<h3>'.$e($block['text']).'</h3>',
                'image'=>'<figure>'.$image($block['media_ref'],$block['alt']).'<figcaption>'.$e($block['alt']).$credit($block['media_ref']).'</figcaption></figure>',
                'button'=>'<p><a class="communication-button" href="'.$e($block['url']).'">'.$e($block['text']).'</a></p>',
                'divider'=>'<hr>',
                'event'=>'<section class="communication-event">'.(!empty($block['media_ref'])?$image($block['media_ref'],$block['title']).$credit($block['media_ref']):'').'<h3>'.$e($block['title']).'</h3><p>'.$e($block['date'].' · '.$block['venue']).'</p><p>'.nl2br($e($block['text'])).'</p>'.(!empty($block['url'])?'<p><a class="communication-button" href="'.$e($block['url']).'">Weitere Informationen</a></p>':'').'</section>',
            };
        }
        foreach ($item['story'] as $number=>$slide) $html .= '<section class="communication-story" aria-label="Story '.($number+1).'"><h3>'.$e($slide['title']).'</h3><p>'.nl2br($e($slide['text'])).'</p></section>';
        foreach($item['carousel']??[]as$number=>$slide)$html.='<section class="communication-carousel" aria-label="Karussell-Bild '.($number+1).'"><h3>'.$e($slide['title']).'</h3><p>'.nl2br($e($slide['text'])).'</p>'.($slide['media_ref']!==''?$image($slide['media_ref'],$slide['title']).$credit($slide['media_ref']):'').'</section>';
        return $html.'</article>';
    }
}
