<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access, Db, Identity, SecretBox};
use Kulturhus\Native\Cms\{Pages, WebsiteDesign};
use Kulturhus\Native\Communication\{Campaigns, Content, NewsletterGateway};

final class NewsletterEndpoints
{
    /** Trusted router supplies tenant/key/origin; GET and HEAD never consume consent tokens. */
    public static function serve(PDO $db, string $club, SecretBox $box, ?string $mediaRoot = null, string $origin = '', ?Access $publicAccess = null): bool
    {
        $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $kind = match ($path) {
            '/newsletter' => 'signup',
            '/newsletter/bestaetigen', '/newsletter-confirm' => 'confirm',
            '/newsletter/abmelden', '/newsletter-unsubscribe' => 'unsubscribe',
            '/newsletter/ausgabe' => 'view',
            default => null,
        };
        if ($kind === null) return false;
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex');
        header('Content-Type: text/html; charset=utf-8');
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            http_response_code(405); header('Allow: GET, HEAD, POST'); return true;
        }
        $rawToken = $_POST['token'] ?? $_GET['token'] ?? '';
        $token = is_string($rawToken) ? $rawToken : '';
        if ($kind === 'view') {
            $view = Campaigns::publicView($db, $club, $token);
            if (!$view) { http_response_code(404); if ($method !== 'HEAD') echo 'Ausgabe nicht verfügbar.'; return true; }
            if ($method !== 'HEAD' && isset($view['item']['_mail_design'])) {
                $html=\Kulturhus\Native\Shared\MailDesign::browserNewsletter($view['item'],Content::preview($view['item'],null,true,false),(bool)$view['test']);
                $logo=$view['item']['_mail_design']['logo_png']??'';if($logo!=='')$html=str_replace('cid:newsletter-logo@kulturhus','data:image/png;base64,'.$logo,$html);
                echo $html;return true;
            }
            if ($method !== 'HEAD') echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.Html::e($view['item']['title']).'</title>'.BootstrapAssets::styles().'<link rel="stylesheet" href="/assets/newsletter-view.css"></head><body><main>'.($view['test']?'<p class="newsletter-test">TESTVORSCHAU · sieben Tage gültig</p>':'').Content::preview($view['item'], null, true).'</main></body></html>';
            return true;
        }
        if ($method === 'HEAD') return true;
        $csrf = $_SESSION['newsletter_csrf'] ??= bin2hex(random_bytes(32));
        $message = ''; $error = false; $complete = false;
        $values = ['email'=>self::value($_POST['email'] ?? ''),'name'=>self::value($_POST['name'] ?? ''),'purposes'=>[],'consent'=>($_POST['consent'] ?? '') === '1'];
        if (is_array($_POST['purposes'] ?? null)) foreach ($_POST['purposes'] as $purpose) {
            if (is_string($purpose) && in_array($purpose, ['events','news'], true)) $values['purposes'][] = $purpose;
        }
        $oneClick = $kind === 'unsubscribe' && $method === 'POST' && ($_POST['List-Unsubscribe'] ?? '') === 'One-Click';
        if ($method === 'POST') {
            if (!$oneClick && (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf']))) {
                http_response_code(403); $message = 'Formular abgelaufen. Bitte Angaben prüfen und erneut absenden.'; $error = true;
            } else {
                try {
                    $gateway = new NewsletterGateway($db, $club, $box, $mediaRoot);
                    if ($kind === 'signup') {
                        foreach (['email','name','website'] as $field) if (!is_string($_POST[$field] ?? '')) throw new DomainException('Anmeldeangaben prüfen.');
                        $purposes = $_POST['purposes'] ?? [];
                        if (!is_array($purposes) || !array_is_list($purposes)) throw new DomainException('Mindestens ein gültiges Thema wählen.');
                        foreach ($purposes as $purpose) if (!is_string($purpose)) throw new DomainException('Mindestens ein gültiges Thema wählen.');
                        $signup = NewsletterGateway::signupInput($_POST['email'] ?? '', $_POST['name'] ?? '', $purposes, $values['consent']);
                        try {
                            $gateway->signup($signup['email'], $signup['name'], $signup['purposes'], (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $values['consent'], $_POST['website'] ?? '');
                        } catch (\Throwable) {
                            // Operational state must not disclose whether this address is subscribed.
                            // Never log addresses, capabilities, SQL parameters or exception messages.
                            error_log('newsletter_signup_preparation_failed');
                        }
                        $message = 'Wenn eine Anmeldung möglich ist, erhältst du eine Bestätigungsmail.';
                    } else {
                        $success = $gateway->redeem($token, $kind);
                        $message = $kind === 'unsubscribe' ? 'Die Abmeldung wurde bearbeitet.' : ($success ? 'Anmeldung bestätigt.' : 'Bestätigung nicht verfügbar oder abgelaufen.');
                    }
                    $complete = true;
                } catch (DomainException $exception) {
                    $message = $kind === 'signup' ? $exception->getMessage() : 'Anfrage wurde bearbeitet.'; $error = true;
                } catch (\Throwable) {
                    http_response_code(503); $message = 'Die Anfrage konnte gerade nicht bearbeitet werden. Bitte später erneut versuchen.'; $error = true;
                }
            }
        }
        if ($oneClick) { echo Html::e($message); return true; }
        $validToken = preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1;
        if ($method === 'GET' && $kind !== 'signup' && !$validToken) { $message = 'Dieser Newsletterlink ist ungültig oder unvollständig.'; $error = true; }
        $form = '';
        if (!$complete && ($kind === 'signup' || $validToken)) {
            if ($kind === 'signup') {
                $form = Html::input('email','E-Mail-Adresse',$values['email'],'email','required maxlength="254" autocomplete="email"').Html::input('name','Name (freiwillig)',$values['name'],'text','maxlength="200" autocomplete="name"');
                $form .= '<fieldset><legend>Was interessiert dich?</legend>';
                foreach (['events'=>'Veranstaltungen','news'=>'Neuigkeiten'] as $purpose=>$label) $form .= '<label class="check"><input class="check" type="checkbox" name="purposes[]" value="'.$purpose.'"'.(in_array($purpose,$values['purposes'],true)?' checked':'').'><span>'.$label.'</span></label>';
                $form .= '</fieldset><label class="check"><input class="check" type="checkbox" name="consent" value="1" required'.($values['consent']?' checked':'').'><span>Ich möchte den Newsletter erhalten und kann mich jederzeit abmelden.</span></label><div hidden>'.Html::input('website','Bitte leer lassen','','text','tabindex="-1" autocomplete="off"').'</div><button class="btn btn-solid theme-primary">Anmeldung anfordern</button>';
            } else $form = Html::hidden('token',$token).'<button class="btn btn-solid theme-primary">'.($kind === 'confirm'?'Newsletter bestätigen':'Newsletter abmelden').'</button>';
            $form = '<form class="newsletter-form" method="post" action="#newsletter-form">'.Html::hidden('csrf',$csrf).$form.'</form>';
        }
        $title = match ($kind) { 'confirm'=>'Newsletter bestätigen','unsubscribe'=>'Newsletter abmelden',default=>'Newsletter' };
        $intro = match ($kind) { 'confirm'=>'Bestätige deine Anmeldung, um den Newsletter zu erhalten.','unsubscribe'=>'Mit deiner Bestätigung meldest du dich vom Newsletter ab.',default=>'Wähle die Themen, zu denen du Neuigkeiten erhalten möchtest.' };
        $notice = $message !== '' ? '<p id="newsletter-form" class="newsletter-message" role="'.($error?'alert':'status').'" tabindex="-1">'.Html::e($message).'</p>' : '';
        $main = '<main id="main" class="newsletter-page"><section class="newsletter-card"><h1>'.Html::e($title).'</h1>'.(!$complete?'<p>'.$intro.'</p>':'').$notice.'<div'.($notice === ''?' id="newsletter-form"':'').'>'.$form.'</div>'.($complete?'<a class="button" href="/newsletter/">Zum Newsletterformular</a>':'').'</section></main>';
        $identity = Identity::forClub($db, $club);
        if ($origin === '') $origin = (string) ($identity['origin'] ?? '');
        $navigation = []; $footer = [];
        if ($publicAccess !== null) {
            try {
                if (!hash_equals($club,$publicAccess->require('website','read')['club_id'])) throw new DomainException('Vereinszuordnung prüfen.');
                $pages = new Pages($db, $publicAccess); $navigation = $pages->navigation(); $footer = $pages->footerNavigation();
            } catch (DomainException) { $navigation = []; $footer = []; }
        }
        if (Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='khcms_website_design'")->fetchColumn()) $identity['website_design'] = WebsiteDesign::forClub($db, $club)['template'];
        $canonical = match ($kind) { 'confirm'=>'/newsletter/bestaetigen/','unsubscribe'=>'/newsletter/abmelden/',default=>'/newsletter/' };
        echo PublicLayout::render($title,$intro,$main,$navigation,'newsletter',$origin,'/vereinsverwaltung/',$identity,true,$footer,1,$canonical);
        return true;
    }

    private static function value(mixed $value): string
    {
        return is_string($value) && strlen($value) <= 4096 && preg_match('//u',$value) === 1 && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value) ? $value : '';
    }
}
