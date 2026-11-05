<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Language\{Catalog, EventPreparationMessages};
use Kulturhus\Native\Shared\{Access, Identity};
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Communication\{Content, EditionSaveRequests, ReelRenderer, Workspace};

final class ReelRenderForm
{
    public static function restore(array $post): array
    {
        $state = [];
        foreach (['id'=>32, 'version'=>10, 'reel_request_key'=>32, 'animation'=>10] as $key=>$max) {
            $value = $post[$key] ?? '';
            if (!is_string($value) || strlen($value)>$max) throw new DomainException('Ursprüngliches Videoformular öffnen.');
            $state[$key] = $value;
        }
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $state['id']) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $state['version'])
            || (int)$state['version'] >= 4294967295 || !preg_match('/\A[a-f0-9]{32}\z/D', $state['reel_request_key'])) throw new DomainException('Ursprüngliches Videoformular öffnen.');
        ReelRenderer::animation($state['animation']);
        return $state;
    }

    public static function render(array $item, string $csrf, callable $text, ?array $state = null, bool $separate = true, bool $edit = true, ?int $confirmedVersion = null): string
    {
        if ($item['kind'] !== 'reel') return '';
        $state ??= ['id'=>$item['id'], 'version'=>(string)$item['version'], 'reel_request_key'=>bin2hex(random_bytes(16)), 'animation'=>$item['video_design']['animation'] ?? 'zoom'];
        $t = static fn(string $s): string => Html::e($text($s));
        $h = '<section class="panel reel-preset" data-reel-item="'.Html::e($item['id']).'"'.($confirmedVersion!==null?' data-reel-saved-version="'.$confirmedVersion.'"':'').'><h3>'.$t('Video aus der Gestaltung erstellen').'</h3><p>'.$t('Die Vorlage ist für diesen Entwurf änderbar.').'</p><p role="status" tabindex="-1" aria-live="polite" data-reel-status data-busy="'.$t('Video wird erstellt …').'" data-failure="'.$t('Videoergebnis derzeit nicht verfügbar. Gespeichertes Video prüfen.').'"></p>';
        if (!$separate) $h .= '<noscript><p>'.$t('Die Vorbereitung öffnet ein eigenes Fenster. Ungespeicherte Textänderungen bleiben im Entwurf.').'</p></noscript>';
        if (!empty($state['error'])) $h .= '<p role="alert">'.Html::e($state['error']).'</p>';
        if (array_key_exists('result', $state)) {
            $result = $state['result'];
            $h .= '<p role="status">'.$t($result ? 'Video vorbereitet.' : 'Video noch nicht gespeichert.').'</p>';
            if ($result) $h .= '<video controls playsinline preload="metadata" style="max-width:100%;max-height:60vh" aria-label="'.$t('Video vorbereitet.').'" src="'.Html::e($result['original_url']).'"></video><p><a class="btn btn-outline theme-secondary" href="'.Html::e($result['original_url']).'">'.$t('Gespeichertes Video öffnen').'</a></p>';
        }
        $h .= '<form method="post" data-navigation-native data-reel-preset-form'.(!$separate ? ' data-reel-inline' : '').' target="_blank" rel="noopener">'.Html::hidden('csrf', $csrf).Html::hidden('operation', 'social-reel-prepare').Html::hidden('view', 'social');
        foreach (['id', 'version', 'reel_request_key'] as $key) $h .= Html::hidden($key, $state[$key]);
        $h .= Html::select('animation', $text('Animationsvorlage'), ['still'=>$text('Standbild'), 'zoom'=>$text('Sanfter Zoom'), 'pan'=>$text('Schwenk'), 'fade'=>$text('Einblenden')], $state['animation']);
        if ($edit) $h .= '<button class="btn btn-solid theme-primary">'.$t('Video vorbereiten').'</button> ';
        $h .= '<button class="btn btn-outline theme-secondary" name="reel_review" value="1">'.$t('Gespeichertes Video prüfen').'</button></form>';
        if (!$separate) $h .= '<p><a class="btn btn-outline theme-secondary" data-nav href="/?view=social&amp;section=compose&amp;item='.Html::e($item['id']).'">'.$t('Zur Redaktion').'</a></p>';
        return $h.'</section>';
    }

    public static function page(PDO $db, Access $access, string $mediaRoot, string $csrf, array $state): string
    {
        $club = $access->require('social', 'read')['club_id'];
        $workspace = new Workspace($db, $access, 'social', $mediaRoot);
        $item = $workspace->get($state['id']);
        $messages = (new Catalog($db, $access))->messages();
        $text = static fn(string $s): string => EventPreparationMessages::text($s, $messages);
        $edit = true; try { $access->require('social', 'edit'); } catch (DomainException) { $edit = false; }
        $confirmedVersion = null;
        if($edit&&empty($state['error'])&&is_array($state['result']??null)){
            $result=$state['result'];$receipt=(new EditionSaveRequests($db,$access,$mediaRoot,'social'))->review($state['reel_request_key']);
            if($receipt!==null&&$receipt['id']===$item['id']&&$receipt['version']===$item['version']&&$receipt['version']===(int)$state['version']+1&&($result['item_id']??null)===$item['id']&&($result['item_version']??null)===$receipt['version']&&($result['request_key']??null)===$state['reel_request_key']){
                $basis=Content::document('social',array_replace($workspace->revision($item['id'],(int)$state['version']),['video_design'=>['animation'=>$state['animation']]]));
                if(Content::document('social',$receipt)===$basis&&Content::document('social',$item)===$basis&&$item['status']===$receipt['status'])$confirmedVersion=$receipt['version'];
            }
        }
        $html = self::render($item, $csrf, $text, $state, false, $edit, $confirmedVersion);
        if ($access->require('social', 'read')['club_id'] !== $club) throw new DomainException('Vereinszuordnung geändert.');
        return $html;
    }
}
