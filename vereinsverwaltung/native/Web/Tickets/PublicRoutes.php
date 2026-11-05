<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Tickets;
use PDO;
use Kulturhus\Native\Shared\{Access,SecretBox,Identity};
use Kulturhus\Native\Events\Tickets\{Offers,Orders,Qr,Values};
use Kulturhus\Native\Web\{Html,PublicLayout};
use Kulturhus\Native\Cms\{Pages,WebsiteDesign};
use Kulturhus\Native\Payments\Money;

final class PublicRoutes
{
    public static function serve(PDO $db,string $club,SecretBox $box,string $origin,Access $publicAccess,?\Closure $request=null):bool
    {
        $path=rtrim((string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH),'/');
        if(!in_array($path,['/tickets/buy','/tickets/order','/tickets/qr','/tickets/callback','/tickets/stripe'],true))return false;
        $method=$_SERVER['REQUEST_METHOD']??'GET';header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self' https://checkout.sumup.com https://checkout.stripe.com; frame-ancestors 'self'; base-uri 'none'");
        $callback=in_array($path,['/tickets/callback','/tickets/stripe'],true);
        if(!in_array($method,$callback?['POST']:['GET','HEAD','POST'],true)||($path==='/tickets/qr'&&$method==='POST')){http_response_code(405);header('Allow: '.($callback?'POST':($path==='/tickets/qr'?'GET, HEAD':'GET, HEAD, POST')));return true;}
        $orders=new Orders($db,$club,$box,null,$request);$error='';$main='';$title='Tickets & Gutscheine';
        $text=static function(array $values,string $key):string{$value=$values[$key]??'';if(!is_string($value))throw new \DomainException('Ticketangaben prüfen.');return $value;};
        try{
            if($callback){
                if($path==='/tickets/stripe'){$raw=file_get_contents('php://input',false,null,0,65537);$result=$orders->stripeWebhook($raw===false?'':$raw,$_SERVER['HTTP_STRIPE_SIGNATURE']??'');}
                else $result=$orders->callback($text($_GET,'order'),$text($_GET,'hook'));
                header('Content-Type: application/json; charset=utf-8');echo json_encode($result,JSON_THROW_ON_ERROR);return true;
            }
            if($path==='/tickets/qr'){
                $view=$orders->view($text($_GET,'access'));$passId=$text($_GET,'pass');$voucherId=$text($_GET,'voucher');$selected=null;foreach($view['passes']as$pass)if($pass['id']===$passId)$selected=$pass;foreach($view['vouchers']as$voucher)if($voucher['id']===$voucherId)$selected=$voucher;
                if($view['status']!=='paid'||!$selected)throw new \DomainException('Ticket nicht freigegeben.');
                $png=($selected['kind']??null)==='value-voucher'?Qr::valuePng($selected['code']):Qr::png($view['page_id'],$selected['code']);header('Content-Type: image/png');header('Content-Length: '.strlen($png));if($method!=='HEAD')echo $png;return true;
            }
            if(session_status()!==PHP_SESSION_ACTIVE){session_set_cookie_params(['httponly'=>true,'secure'=>str_starts_with($origin,'https://')&&getenv('KH_NATIVE_CI')!=='1','samesite'=>'Lax','path'=>'/']);session_start();}
            $_SESSION['ticket_csrf']??=bin2hex(random_bytes(32));$csrf=$_SESSION['ticket_csrf'];
            if($path==='/tickets/buy'){
                $page=$text($_GET,'event');$offer=Offers::publicOffer($db,$club,$page);if(!$offer)throw new \DomainException('Ticketverkauf derzeit nicht verfügbar.');
                $catalogue=Values::catalogue($db,$club);$gift=$method==='POST'?$text($_POST,'voucher_product'):'';$giftQuantity=$method==='POST'?$text($_POST,'voucher_quantity'):'1';
                $name=$method==='POST'?$text($_POST,'buyer_name'):'';$email=$method==='POST'?$text($_POST,'buyer_email'):'';$quantity=$method==='POST'?$text($_POST,'quantity'):'1';
                $_SESSION['ticket_requests'][$page]??=bin2hex(random_bytes(32));$nonce=$_SESSION['ticket_requests'][$page];
                if($method==='POST')try{
                    if(!hash_equals($csrf,$text($_POST,'csrf'))||!hash_equals($nonce,$text($_POST,'request')))throw new \DomainException('Formular abgelaufen. Bitte erneut prüfen.');
                    if(!preg_match('/^(?:[0-9]|10)$/D',$quantity)||!preg_match('/^(?:[1-9]|10)$/D',$giftQuantity)||!preg_match('/^[0-9]{1,10}$/D',$text($_POST,'offer_version')))throw new \DomainException('Ticketanzahl prüfen.');
                    $times=array_values(array_filter($_SESSION['ticket_book_times']??[],static fn($t)=>is_int($t)&&$t>time()-3600));if(count($times)>=30)throw new \DomainException('Zu viele Buchungen. Bitte später erneut versuchen.');
                    $booked=$orders->book($page,(int)$text($_POST,'offer_version'),(int)$quantity,$name,$email,$nonce,$gift!==''?[['id'=>$gift,'quantity'=>(int)$giftQuantity]]:[]);$times[]=time();$_SESSION['ticket_book_times']=$times;unset($_SESSION['ticket_requests'][$page]);
                    header('Location: /tickets/order?access='.$booked['access_token'],true,303);return true;
                }catch(\DomainException $e){http_response_code(422);$error=$e->getMessage();}
                $title=$offer['event']['title'];$choices=$catalogue?['0'=>'0']:[];for($i=1;$i<=min(10,$offer['remaining']);$i++)$choices[(string)$i]=(string)$i;
                $main='<main class="subpage ticket-page" id="main"><a class="back-link" href="/site/'.Html::e($offer['event']['slug']).'">← Veranstaltung</a><h1>'.Html::e($title).'</h1><section class="ticket-card"><p class="ticket-price">'.Html::e(self::money($offer['price_cents'])).' € <span>pro Ticket</span></p><p>'.(int)$offer['remaining'].' verfügbar</p>'.self::error($error);
                if($choices){
                    $main.='<form method="post" class="ticket-booking" data-ticket-price="'.$offer['price_cents'].'">'.Html::hidden('csrf',$csrf).Html::hidden('request',$nonce).Html::hidden('offer_version',$offer['version']).Html::select('quantity','Tickets',$choices,$quantity);
                    $giftPrice=0;if($catalogue){$options=[''=>'Kein Gutschein'];foreach($catalogue as$p){$options[$p['id']]=$p['label'].' · '.self::money((int)$p['price_cents']).' €';if($p['id']===$gift)$giftPrice=(int)$p['price_cents'];}
                        $main.='<fieldset><legend>Wertgutschein hinzufügen</legend>'.Html::select('voucher_product','Gutschein',$options,$gift).Html::input('voucher_quantity','Gutscheinanzahl',$giftQuantity,'number','min="1" max="10" inputmode="numeric" data-voucher-quantity').'</fieldset><script type="application/json" data-voucher-prices>'.json_encode(array_column($catalogue,'price_cents','id'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script>';
                    }
                    $total=$offer['price_cents']*(isset($choices[$quantity])?(int)$quantity:1)+$giftPrice*(preg_match('/^(?:[1-9]|10)$/D',$giftQuantity)?(int)$giftQuantity:1);
                    $main.=Html::input('buyer_name','Name',$name,'text','required autocomplete="name" maxlength="160"').Html::input('buyer_email','E-Mail',$email,'email','required autocomplete="email" maxlength="254"').'<p class="ticket-total" aria-live="polite">Gesamt: <strong data-ticket-total>'.Html::e(self::money($total)).' €</strong></p><button class="btn btn-solid theme-primary">Weiter</button></form>';
                }else $main.='<p role="status">Ausverkauft</p>';
                $main.='</section></main>';
            }else{
                $access=$text($_GET,'access');$view=$orders->view($access);
                if($method==='POST')try{
                    if(!hash_equals($csrf,$text($_POST,'csrf')))throw new \DomainException('Formular abgelaufen. Bitte erneut prüfen.');
                    $action=$text($_POST,'ticket_action');if($action==='checkout')$view=$orders->beginCheckout($access);elseif($action==='refresh')$view=$orders->reconcile($access);else throw new \DomainException('Ticketaktion prüfen.');
                    header('Location: '.($action==='checkout'&&$view['status']==='awaiting_payment'&&$view['checkout_url']!==null?$view['checkout_url']:'/tickets/order?access='.$access),true,303);return true;
                }catch(\DomainException $e){http_response_code(422);$error=$e->getMessage();$view=$orders->view($access);}
                catch(\Throwable){http_response_code(422);$error='Zahlungsstand derzeit nicht bestätigt. Bitte erneut prüfen.';$view=$orders->view($access);}
                if($method==='GET'&&in_array($view['status'],['creating_checkout','awaiting_payment','payment_uncertain'],true)&&time()-($_SESSION['ticket_checked'][$view['id']]??0)>=10){$_SESSION['ticket_checked'][$view['id']]=time();try{$view=$orders->reconcile($access);}catch(\Throwable){$error='Zahlungsstand derzeit nicht erreichbar.';}}
                $title=$view['title'];$labels=['reserved'=>'Reserviert','creating_checkout'=>'Zahlung wird vorbereitet','awaiting_payment'=>'Zahlung ausstehend','payment_uncertain'=>'Zahlung wird geprüft','paid'=>'Bestellung bestätigt','payment_review'=>'Zahlung wird geprüft','paid_unallocated'=>'Bezahlte Bestellung wird geprüft','expired'=>'Reservierung abgelaufen','refunded'=>'Erstattet','cancelled'=>'Storniert','refund_pending'=>'Erstattung wird geprüft'];
                $giftCount=array_sum(array_column(array_filter($view['items'],static fn($i)=>$i['kind']==='value-voucher'),'quantity'));$itemLabels=[];if($view['quantity'])$itemLabels[]=$view['quantity'].' '.($view['quantity']===1?'Ticket':'Tickets');if($giftCount)$itemLabels[]=$giftCount.' '.($giftCount===1?'Gutschein':'Gutscheine');
                $main='<main id="main" class="subpage ticket-page" data-ticket-order="'.Html::e($view['status']).'"><h1>'.Html::e($title).'</h1><section class="ticket-card"><p class="badge" role="status">'.Html::e($labels[$view['status']]??'Bestellung wird geprüft').'</p><p>'.Html::e(implode(' · ',$itemLabels)).' · <strong>'.self::money($view['amount_cents']).' €</strong></p>'.self::error($error);
                if($view['status']==='paid'){
                    $mailStatus=\Kulturhus\Native\Events\Tickets\Mail::status($db,$club,$view['id']);
                    $mailLabels=['queued'=>'Bestell-E-Mail wartet auf Versand. Bewahre diesen Bestelllink auf.','sent'=>'Bestell-E-Mail an '.$view['buyer_email'].' versendet.','sending'=>'E-Mail-Versand noch nicht bestätigt. Bewahre diesen Bestelllink auf.','uncertain'=>'E-Mail-Zustellung unklar. Bewahre diesen Bestelllink auf; die Nachricht wird nicht automatisch erneut versendet.','blocked'=>'Bestell-E-Mail derzeit nicht versendet. Bewahre diesen Bestelllink auf.'];
                    if(isset($mailLabels[$mailStatus??'']))$main.='<p role="status">'.Html::e($mailLabels[$mailStatus]).'</p>';
                    $cards=array_merge(array_map(static fn($p)=>array_merge($p,['kind'=>'admission']),$view['passes']),$view['vouchers']);
                    $index=filter_var($_GET['ticket']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>max(1,count($cards))]]);if($index===false)$index=1;$card=$cards[$index-1]??null;
                    if($view['test_mode'])$main.='<p class="badge">Testzahlung</p>';
                    if($card){$value=$card['kind']==='value-voucher';$label=$value?'Wertgutschein':'Eintrittsticket';$qrUrl='/tickets/qr?access='.$access.'&amp;'.($value?'voucher':'pass').'='.$card['id'];
                        $main.='<h2>'.$label.' · '.$index.' / '.count($cards).'</h2><img class="ticket-qr" width="320" height="320" alt="QR-Code: '.$label.'" src="'.$qrUrl.'"><p>'.Html::e($view['buyer_name']).'</p>';
                        $main.=$value?'<p>'.($card['status']==='test'?'Testguthaben':'Restguthaben').' <strong>'.self::money($card['remaining_cents']).' €</strong></p>':'<p>'.($card['status']==='test'?'Testticket · Kein Einlass':($card['status']==='checked_in'?'Bereits eingelassen':'Für den Einlass gültig')).'</p>';
                        $main.='<nav class="ticket-pages" aria-label="Tickets und Gutscheine">';foreach(['Vorheriges'=>-1,'Nächstes'=>1]as$label=>$step)if($index+$step>=1&&$index+$step<=count($cards))$main.='<a class="btn btn-outline theme-secondary" href="/tickets/order?access='.$access.'&amp;ticket='.($index+$step).'">'.$label.'</a>';
                        $main.='</nav><a class="btn btn-outline theme-secondary" download="QR-Code.png" href="'.$qrUrl.'">QR-Code herunterladen</a>';
                    }
                }else{
                    if($view['status']==='reserved')$main.=self::form($csrf,'checkout','Mit '.($view['payment_provider']==='stripe'?'Stripe':'SumUp').' bezahlen');
                    if($view['checkout_url']&&$view['status']==='awaiting_payment')$main.='<a class="btn btn-solid theme-primary" href="'.Html::e($view['checkout_url']).'" rel="noreferrer">Zahlung öffnen</a>';
                    if(in_array($view['status'],['creating_checkout','awaiting_payment','payment_uncertain','payment_review'],true))$main.=self::form($csrf,'refresh','Zahlungsstand prüfen');
                }
                $main.='</section></main>';
            }
        }catch(\DomainException $e){http_response_code(404);$main='<main class="subpage ticket-page" id="main"><h1>Tickets &amp; Gutscheine</h1>'.self::error($e->getMessage()).'</main>';}
        catch(\Throwable){http_response_code(503);$main='<main class="subpage ticket-page" id="main"><h1>Tickets &amp; Gutscheine</h1>'.self::error('Ticketservice derzeit nicht verfügbar.').'</main>';}
        if($callback){header('Content-Type: application/json; charset=utf-8');echo '{"status":"rejected"}';return true;}
        $identity=Identity::forClub($db,$club);$identity['website_design']=WebsiteDesign::forClub($db,$club)['template'];$pages=new Pages($db,$publicAccess);
        $html=PublicLayout::render($title,'',$main,$pages->navigation(),'tickets',$origin,'/vereinsverwaltung/',$identity,true,$pages->footerNavigation(),1,$path);
        $html=str_replace('</head>','<link rel="stylesheet" href="/assets/tickets.css"><script src="/assets/tickets.js" defer></script></head>',$html);
        header('Content-Type: text/html; charset=utf-8');if($method!=='HEAD')echo $html;return true;
    }
    private static function money(int $cents):string{return number_format($cents/100,2,',','.');}
    private static function error(string $message):string{return $message===''?'':'<p class="ticket-error" role="alert">'.Html::e($message).'</p>';}
    private static function form(string $csrf,string $action,string $label):string{return '<form method="post">'.Html::hidden('csrf',$csrf).Html::hidden('ticket_action',$action).'<button class="btn btn-outline theme-secondary">'.Html::e($label).'</button></form>';}
}
