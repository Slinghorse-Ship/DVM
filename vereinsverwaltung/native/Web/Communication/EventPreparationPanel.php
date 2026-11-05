<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use Kulturhus\Native\Communication\EventPreparations;
use Kulturhus\Native\Language\{Catalog, EventPreparationMessages};
use Kulturhus\Native\Web\{Html, Workspace};

final class EventPreparationPanel extends Workspace
{
    public function render(): string
    {
        $club = $this->access->require('social', 'read')['club_id'];
        $messages = (new Catalog($this->db, $this->access))->messages();
        $text = static fn(string $s): string => EventPreparationMessages::text($s, $messages);
        $t = static fn(string $s): string => Html::e($text($s));
        $service = new EventPreparations($this->db, $this->mediaRoot);
        $h = '<section class="panel event-preparation" id="event-preparation"><h2>'.$t('Automatische Veranstaltungsentwürfe').'</h2><p>'.$t('Gestaltete Bilder und Videos werden als bearbeitbare Entwürfe vorbereitet.').'</p>';
        $manage = $this->can('social', 'manage') && $this->can('social', 'edit') && $this->can('website', 'read');
        if ($manage) {
            $state = $service->settings($this->access); $config = $state['config'];
            $h .= $this->start('social-event-preparation-settings').Html::hidden('view', 'social').Html::hidden('event_preparation_complete', '1').Html::hidden('version', $state['version']);
            $h .= '<label class="check"><input type="checkbox" name="enabled" value="1"'.($state['enabled'] ? ' checked' : '').'>'.$t('Automatik aktiv').'</label>';
            $h .= '<div class="field-grid">'.Html::select('event_media', $text('Medien für alle automatischen Entwürfe'), ['image'=>$text('Bild'), 'video'=>$text('Animiertes Video'), 'both'=>$text('Beides')], $config['media']);
            $h .= Html::input('horizon_days', $text('Vorschauzeitraum (Tage)'), (string)$config['horizon_days'], 'number', 'min="1" max="730" required');
            $h .= Html::select('draft_locale', $text('Entwurfssprache'), ['de'=>$text('Deutsch'), 'en'=>$text('English')], $config['locale']);
            $h .= Html::select('image_layout', $text('Bildvorlage'), ['classic'=>$text('Vereinskarte · ursprüngliche Gestaltung'), 'editorial'=>$text('Redaktionell'), 'poster'=>$text('Plakat'), 'photo'=>$text('Foto')], $config['design']['layout']);
            $h .= Html::select('image_palette', $text('Farbwelt'), ['forest'=>$text('Wald'), 'sunset'=>$text('Sonnenuntergang'), 'night'=>$text('Nacht')], $config['design']['palette']);
            $h .= Html::select('animation', $text('Animationsvorlage'), ['zoom'=>$text('Sanfter Zoom'), 'pan'=>$text('Schwenk'), 'fade'=>$text('Einblenden')], $config['animation']).'</div>';
            $h .= '<label class="check"><input type="checkbox" name="story_reminders" value="1"'.($config['story_reminders'] ? ' checked' : '').'>'.$t('Story-Erinnerungen 14, 7, 3 und 1 Tag vorher sowie am Veranstaltungstag').'</label>';
            $h .= Html::hidden('story_options_complete','1');
            $h .= '<fieldset><legend>'.$t('Story-Folge').'</legend>';
            foreach(['auto_stories'=>'Instagram- und Facebook-Storys automatisch ohne einzelne Freigabe veröffentlichen','story_master'=>'Erstes gestaltetes Bild als Vorlage für die weiteren Storys verwenden','story_day_hint'=>'Tageshinweis anzeigen, zum Beispiel „noch 7 Tage“ oder „morgen“']as$key=>$label){
                $h.='<label class="check"><input type="checkbox" name="'.Html::e($key).'" value="1"'.($config[$key]?' checked':'').'>'.$t($label).'</label>';
            }
            $h.='</fieldset><p>'.$t('Die automatische Story-Freigabe gilt für die geplante Veranstaltungsfolge. Beiträge und Reels werden als Entwürfe vorbereitet.').'</p><button class="btn btn-solid theme-primary">'.$t('Standardvorlage speichern').'</button></form>';
            if ($state['enabled']) $h .= $this->start('social-event-preparation-run').Html::hidden('view','social').Html::hidden('version',$state['version']).'<button class="btn btn-outline theme-secondary">'.$t('Entwürfe jetzt vorbereiten').'</button></form>';
        }
        $rows = $service->listing($this->access);
        $h .= '<h3>'.$t('Vorbereitete Veranstaltungsentwürfe').'</h3>';
        if (!$rows) $h .= '<p>'.$t('Noch keine Veranstaltungsentwürfe vorbereitet.').'</p>';
        foreach ($rows as $row) {
            $state = (int)$row['current_version'] !== (int)$row['item_version'] ? 'Manuell bearbeitet' : match ($row['state']) {
                'ready'=>match($row['reason']){'story_template'=>'Story-Vorlage im Bildstudio speichern','story_review'=>'Story-Angaben und Verbindung prüfen',default=>'Entwurf bereit'}, 'auto_scheduled'=>'Story-Veröffentlichung eingeplant', 'pending', 'rendering'=>'In Vorbereitung', 'withdrawn'=>$row['reason']==='event_past' ? 'Veranstaltung bereits begonnen' : 'Veranstaltung zurückgezogen',
                'failed'=>$row['kind']==='reel' ? 'Videoerstellung prüfen' : 'Bildgestaltung prüfen', default=>'Prüfen',
            };
            $h .= '<article class="panel"><span class="badge">'.$t($state).'</span><h4>'.Html::e($row['title']).'</h4><p>'.Html::e($this->mediaTime($row['created_at'])).' · '.$t(['post'=>'Bild: Beitrag', 'story'=>'Bild: Story', 'reel'=>'Video: Reel'][$row['kind']]).'</p><a class="btn btn-outline theme-secondary" data-nav href="/?view=social&amp;section=compose&amp;item='.Html::e($row['item_id']).($row['kind']==='story'?'#social-image-editor':'').'">'.$t('Entwurf öffnen').'</a>';
            if($row['kind']==='story'&&$row['reason']==='story_template')$h.='<p><a class="btn btn-outline theme-primary" data-nav href="/?view=social&amp;section=compose&amp;item='.Html::e($row['item_id']).'#social-image-editor">'.$t('Bildstudio öffnen').'</a></p>';
            if ($manage && $row['item_status'] === 'draft') $h .= $this->start('social-event-preparation-refresh').Html::hidden('view','social').Html::hidden('id',$row['item_id']).Html::hidden('version',$row['version']).Html::hidden('item_version',$row['current_version']).'<button class="btn btn-outline theme-secondary">'.$t('Gestaltung aktualisieren').'</button></form>';
            if ($manage && $row['state'] === 'failed') $h .= $this->start('social-event-preparation-retry').Html::hidden('view', 'social').Html::hidden('id', $row['item_id']).Html::hidden('version', $row['version']).'<button class="btn btn-outline theme-secondary">'.$t('Erneut vorbereiten').'</button></form>';
            $h .= '</article>';
        }
        if ($this->access->require('social', 'read')['club_id'] !== $club) throw new \DomainException('Vereinszuordnung geändert.');
        return $h.'</section>';
    }
}
