<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use Kulturhus\Native\Web\Communication\VideoUploadForm;

final class VideoEditor
{
    public static function render(?array $item=null,?array $identity=null,string $csrf='',?callable $interfaceText=null):string
    {
        $text=$interfaceText??static fn(string$s):string=>$s;$t=static fn(string$s):string=>Html::e($text($s));
        $stored=$item!==null&&$identity!==null;
        $save=$stored?'<button class="btn btn-solid theme-primary" type="button" data-video-save disabled>'.$t('Videofassung speichern').'</button>':'';
        $upload=$stored?'<details class="video-tools video-ready-upload"><summary>'.$t('Fertige MP4 direkt speichern').'</summary>'.VideoUploadForm::render($item,$identity,$csrf,$text).'</details>':'';
        return '<details class="panel native-video-editor" id="social-video-editor"><summary>'.$t('Eigenes Video bearbeiten oder hochladen').'</summary><section class="panel canvas-editor" data-video-studio data-video-interface="'.VideoUploadForm::interfaceJson($text).'">'.
            '<header><h2>'.$t('Reelstudio').'</h2><span class="badge">MP4 · 1080 × 1920</span></header>'.
            '<noscript><p>'.$t('Die freie Videobearbeitung benötigt JavaScript und einen verfügbaren H.264-Encoder. Eine fertige MP4 kannst du hier direkt speichern.').'</p></noscript>'.
            '<p role="status" data-video-status aria-live="polite" hidden>'.$t('Videofunktionen werden geprüft …').'</p>'.
            '<div class="studio-grid" data-video-client hidden><div class="studio-stage"><div class="studio-frame"><canvas width="1080" height="1920" aria-label="'.$t('Reelvorschau im Hochformat').'"></canvas></div>'.
            '<details class="video-tools"><summary>'.$t('Original ansehen').'</summary><video controls playsinline preload="metadata" aria-label="'.$t('Originalvideo').'"></video></details></div><div class="studio-controls">'.
            '<label>'.$t('Eigenes Video').'<input class="form-control" type="file" data-video-file accept="video/mp4,video/quicktime,video/webm"></label>'.
            '<details class="video-tools" data-video-trim><summary>'.$t('Schnitt & Ausschnitt').'</summary>'.
            '<label>'.$t('Beginn (Sekunden)').'<input class="form-control" type="number" data-video-start min="0" step="0.1" value="0"></label>'.
            '<label>'.$t('Ende (Sekunden)').'<input class="form-control" type="number" data-video-end min="3" step="0.1" value="3"></label>'.
            '<label>'.$t('Ausschnitt').'<select class="form-control" data-video-fit><option value="cover">'.$t('Hochformat füllen').'</option><option value="contain">'.$t('Ganzes Video erhalten').'</option></select></label>'.
            '<label>'.$t('Horizontal verschieben').'<input class="form-range" type="range" data-video-focal min="0" max="100" value="50"></label></details>'.
            '<details class="video-tools" data-video-look><summary>'.$t('Filter & Licht').'</summary><label>'.$t('Filter').'<select class="form-control" data-video-filter>'.
            '<option value="none">'.$t('Original').'</option><option value="grayscale(1)">'.$t('Schwarzweiß').'</option><option value="sepia(1)">'.$t('Sepia').'</option><option value="sepia(.3) saturate(1.2)">'.$t('Warm').'</option><option value="hue-rotate(18deg) saturate(.85)">'.$t('Kühl').'</option><option value="saturate(1.5) contrast(1.1)">'.$t('Lebendig').'</option></select></label>'.
            '<label>'.$t('Helligkeit').'<input class="form-range" type="range" data-video-brightness min="0" max="200" value="100"></label><label>'.$t('Kontrast').'<input class="form-range" type="range" data-video-contrast min="0" max="200" value="100"></label></details>'.
            '<details class="video-tools" data-video-caption><summary>'.$t('Text im Video').'</summary><label>'.$t('Text').'<textarea class="form-control" data-video-text maxlength="300"></textarea></label><label>'.$t('Textfarbe').'<input class="form-control" type="color" data-video-color value="#ffffff"></label></details>'.
            '<label class="check"><input class="check" type="checkbox" data-video-audio checked>'.$t('Originalton erhalten').'</label>'.$save.
            '<button class="btn btn-outline theme-secondary" type="button" data-video-export disabled>'.$t('MP4 herunterladen').'</button><button type="button" data-video-cancel class="btn btn-outline theme-secondary secondary" disabled>'.$t('Abbrechen').'</button>'.
            '<progress data-video-progress max="100" value="0" aria-label="'.$t('Videoexport').'"></progress></div></div>'.$upload.'</section></details>';
    }
}
