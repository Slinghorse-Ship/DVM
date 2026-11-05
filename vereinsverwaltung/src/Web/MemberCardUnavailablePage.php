<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use Kulturhus\Native\Language\MemberCardMessages;
use Kulturhus\Native\Web\Html;

/** If common identity projection is locked, show only the caller's own posted draft. */
final class MemberCardUnavailablePage
{
    public static function serve(array $query,array $post,array $session):void
    {
        $locale=in_array($session['member_card_locale']??null,['de','en'],true)?$session['member_card_locale']:'de';$messages=MemberCardMessages::messages($locale);$text=static fn(string$s):string=>Html::e(MemberCardMessages::text($s,$messages));
        http_response_code(503);header('Cache-Control: no-store, private, max-age=0');header('Referrer-Policy: no-referrer');header('Content-Type: text/html; charset=utf-8');
        $card=($query['view']??null)==='portal'&&($query['tab']??null)==='card';$content='';
        if($card&&in_array($post['action']??null,MemberCardForm::ACTIONS,true)&&is_string($session['csrf']??null)&&is_string($post['csrf']??null)&&hash_equals($session['csrf'],$post['csrf']))try{
            $contact=MemberCardForm::text($post['contact_id']??null,128);MemberCardForm::frame($query,$contact,$post);$content=(new MemberCardPage($session['csrf'],$messages,$locale))->recovery($contact,$post,'Mitgliederausweis wird gerade bearbeitet. Deine Angaben bleiben erhalten.');
        }catch(\DomainException){}
        if($content===''){
            $content='<section class="member-card-page"><h1>'.$text($card?'Mitgliederausweis':'Mitgliederausweise & Wallet').'</h1><p role="alert">'.$text('Mitgliederausweis wird gerade bearbeitet. Deine Angaben bleiben erhalten.').'</p>';
            // No password, key file, certificate or other private POST value is reflected.
            if(!$card)foreach(['pass_type_id'=>'Pass Type ID','team_id'=>'Apple-Team-ID','issuer_id'=>'Google-Aussteller-ID','class_id'=>'Google-Ausweisklasse']as$key=>$label)if(is_string($post[$key]??null)&&mb_strlen($post[$key])<=300&&!preg_match('/[\x00-\x1f\x7f]|PRIVATE KEY/',$post[$key]))$content.='<label>'.$text($label).'<input readonly value="'.Html::e($post[$key]).'"></label>';
            $content.='<a class="btn" href="'.Html::e('?'.http_build_query($card?['view'=>'portal','tab'=>'card']+(is_string($query['person']??null)?['person'=>$query['person']]:[]):['view'=>'settings','tab'=>'member-cards'])).'">'.$text('Erneut versuchen').'</a></section>';
        }
        echo'<!doctype html><html lang="'.$locale.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$text('Mitgliederausweis').'</title><link rel="stylesheet" href="member-cards.css"></head><body><main id="main" class="member-card-printsheet">'.$content.'</main></body></html>';
    }
}
