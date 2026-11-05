<?php declare(strict_types=1);
namespace Kulturhus\Native\Comments;

use Kulturhus\Native\Shared\TextFormatter;
use Kulturhus\Native\Web\{BootstrapAssets,Html,Pager};

/** Public human conversation. Inputs and receipts are supplied only by the private request context. */
final class PublicView
{
    /** A revoked original or parent cannot turn an unsent answer into a different public target. */
    public static function recovery(array $values,string $error,string $current):string
    {
        $e=Html::e(...);
        return'<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Dein Kommentar</title>'.BootstrapAssets::styles().'<link rel="stylesheet" href="/assets/comments.css"></head><body><main class="native-comments subpage"><h1>Dein Kommentar wurde nicht gesendet.</h1><p class="comment-notice comment-error" role="alert">'.$e($error).'</p><p>'.$e($values['name']??'').'</p><label>Dein ungesendeter Text<textarea rows="8" readonly data-comment-recovery>'.$e($values['body']??'').'</textarea></label><a class="btn btn-outline theme-secondary" href="'.$e($current).'">Kommentare neu öffnen</a></main></body></html>';
    }

    public static function render(array $state,string $csrf,string $formId,string $endpoint='/_comments'):string
    {
        $list=$state['list'];$original=$list['original'];$e=Html::e(...);$label=$list['stream_label']??'Kommentare';
        $url=static fn(array $query=[]):string=>$endpoint.'?'.http_build_query($query+['id'=>$original['id'],'stream'=>$state['stream']],'','&',PHP_QUERY_RFC3986);
        $h='<link rel="stylesheet" href="/assets/comments.css"><script defer src="/assets/comments.js"></script><main id="main" class="subpage native-comments"><header class="comment-heading"><a class="btn btn-outline theme-secondary" href="/site/'.$e($original['slug']).'">← '.$e($original['title']).'</a><h1>'.$e($label).'</h1><nav aria-label="Kommentare"><a class="btn btn-outline theme-secondary" href="#comment-compose">Schreiben</a><a class="btn btn-outline theme-secondary" href="'.$e($url()).'#comment-latest">Neueste</a></nav></header>';
        if(($state['error']??'')!=='')$h.='<p class="comment-notice comment-error" role="alert">'.$e($state['error']).'</p>';
        if($receipt=$state['receipt']??null){$label=match($receipt['state']){'approved'=>'Kommentar gesendet.','pending'=>'Dein Kommentar wartet auf Freigabe.','rejected','spam'=>'Dein Kommentar wurde nicht veröffentlicht.',default=>'Dein Kommentar ist nicht öffentlich.'};$h.='<aside class="comment-notice" role="status">'.$e($label).'</aside>';if($receipt['state']!=='approved')$h.='<details class="comment-own-receipt"><summary>Dein gesendeter Text</summary><p>'.$e($receipt['body']).'</p></details>';}
        $h.='<section aria-label="Veröffentlichte Kommentare"><div class="comment-list-heading"><p>'.$e((string)$list['total']).' Kommentare · '.$e((string)$list['page']).' / '.$e((string)$list['last']).'</p>'.Pager::render($list['total'],20,$list['page'],static fn(int $page):string=>$url(['seite'=>$page]),'Kommentarseiten').'</div><ol class="comment-messages" tabindex="0" aria-label="Gesprächsverlauf" data-comment-messages'.($list['page']===$list['last']?' data-comment-newest':'').'>';
        foreach($list['rows']as$row){$h.='<li class="comment-message" id="comment-'.$e($row['id']).'"><article><header><strong>'.$e($row['author_name']).'</strong><span>Website · Kommentar</span>'.self::time($row['created_at']).'</header>';
            if($reply=$row['reply_to']??null)$h.='<blockquote class="comment-reply-context"><strong>'.$e($reply['author_name']).'</strong><p>'.$e($reply['body']).'</p></blockquote>';
            $plain=Display::text($row['body'],$row['format']);$body=Display::render($row['body'],$row['format']);
            $h.=mb_strlen($plain)>900?'<details class="comment-long"><summary>'.$e(mb_substr($plain,0,180)).' …</summary><div class="comment-body kh-richtext">'.$body.'</div></details>':'<div class="comment-body kh-richtext">'.$body.'</div>';
            if((int)$row['depth']<3)$h.='<a class="btn btn-outline theme-secondary comment-reply-link" href="'.$e($url(['reply'=>$row['id']])).'#comment-compose">Antworten</a>';
            $h.='</article></li>';
        }
        $h.='<li id="comment-latest" class="comment-list-end" aria-hidden="true"></li></ol>';
        if(!$list['rows'])$h.='<p class="comment-empty">Noch keine Kommentare.</p>';
        $h.='</section><section id="comment-compose" class="comment-compose" aria-label="Kommentar schreiben">';
        if($reply=$state['reply']??null)$h.='<blockquote class="comment-reply-context"><strong>Antwort an '.$e($reply['author_name']).'</strong><p>'.$e($reply['body']).'</p><a class="btn btn-outline theme-secondary" href="'.$e($url()).'#comment-compose">Abbrechen</a></blockquote>';
        if(!($state['can_submit']??false))return$h.'<a class="btn btn-solid theme-primary" href="/login">Zum Kommentieren anmelden</a></section></main>';
        $values=$state['values']??[];$name=$values['name']??$state['name']??'';
        $h.='<form method="post" action="'.$e($endpoint).'" data-comment-form>'.Html::hidden('comment_csrf',$csrf).Html::hidden('comment_form',$formId).'<details class="comment-alias"'.($name===''?' open':'').'><summary>'.($name===''?'Name und Kontakt':'Als '.$e($name)).'</summary><label>Anzeigename<input name="name" value="'.$e($name).'" required maxlength="100" autocomplete="nickname"></label><label>E-Mail-Adresse <span>(optional, nicht öffentlich)</span><input name="email" type="email" value="'.$e($values['email']??'').'" maxlength="254" autocomplete="email"></label></details><label class="comment-body-label">'.($state['reply']??null?'Deine Antwort':'Dein Kommentar').'<textarea name="body" rows="4" required maxlength="'.$e((string)$list['settings']['max_length']).'" data-comment-body>'.$e($values['body']??'').'</textarea></label><div class="comment-send"><details><summary>Textformat</summary><label class="visually-hidden" for="comment-format">Textformat</label><select id="comment-format" name="format"><option value="plain">Einfacher Text</option><option value="markdown"'.(($values['format']??'plain')==='markdown'?' selected':'').'>Markdown</option></select></details><button class="btn btn-solid theme-primary" type="submit">Senden</button></div></form></section></main>';
        return$h;
    }

    public static function time(string $value):string
    {
        $date=new \DateTimeImmutable($value,new \DateTimeZone('UTC'));
        return'<time datetime="'.Html::e($date->format(DATE_ATOM)).'" title="'.Html::e($date->format('d.m.Y H:i').' UTC').'">'.Html::e($date->format('d.m. H:i')).'</time>';
    }
}
