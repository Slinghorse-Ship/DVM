<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\FrontendEditor;
use Kulturhus\Native\Cms\FrontendEdits;
use Kulturhus\Native\Integration\Routes;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\{BootstrapAssets,Html,RichTextEditor};
use Kulturhus\Native\Web\Fields\Controls;
use PDO;

final class View
{
    public function __construct(private PDO $db,private Access $access,private string $csrf){}
    public function render(array $state,string $field,?array $submission=null,string $error='',bool $conflict=false,string $notice=''):string
    {
        $page=$state['page'];$definition=FrontendEdits::field($state,$field);$id=$page['id'];$e=Html::e(...);
        $value=$submission!==null?$submission['value']:FrontendEdits::value($state,$field);
        $version=$submission['version']??$page['version'];$signature=$submission['signature']??$state['signature'];
        $action=Routes::endpoint('frontend-edit',['item'=>$id,'field'=>$field]);
        $preview=Routes::endpoint('frontend-preview',['item'=>$id,'version'=>$page['version']]);
        $head=BootstrapAssets::styles('/vereinsverwaltung/');
        foreach(['native-richtext.css','native-typed-fields.css','native-frontend-editor.css'] as $asset)$head.='<link rel="stylesheet" href="/vereinsverwaltung/'.$asset.'">';
        foreach(['native-richtext.js','native-reference-picker.js','native-typed-fields.js','native-field-conditions.js','native-frontend-editor.js'] as $asset)$head.='<script src="/vereinsverwaltung/'.$asset.'" defer></script>';
        $html='<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.$e($page['title']).' bearbeiten · DVM</title>'.$head.'</head><body class="frontend-editor"><a class="fe-skip" href="#fe-field">Zum Feld</a><div class="fe-shell"><header class="fe-header"><div><p class="fe-kicker">Website bearbeiten · Entwurf '.$page['version'].'</p><h1>'.$e($page['title']).'</h1></div><nav aria-label="Bearbeitung verlassen"><a class="btn btn-solid theme-secondary" href="'.$e(Routes::url('website',$id)).'">Seitenverwaltung</a>'.($page['published_slug']!==null?'<a class="btn btn-solid theme-secondary" href="/site/'.$e($page['published_slug']).'">Öffentliche Seite</a>':'').'</nav></header>';
        if($error!=='')$html.='<div class="fe-notice fe-error" role="alert" tabindex="-1">'.$e($error).'</div>';
        if($notice!=='')$html.='<p class="fe-notice" role="status">'.$e($notice).'</p>';
        $html.='<div class="fe-layout"><section class="fe-edit" aria-label="Feld bearbeiten"><form method="get" action="/vereinsverwaltung/" class="fe-picker">'.Html::hidden('native_endpoint','frontend-edit').Html::hidden('item',$id).'<label for="fe-choice">Feld auswählen<select class="form-control" id="fe-choice" name="field">';
        foreach($state['fields'] as $key=>$choice)$html.='<option value="'.$e($key).'"'.($key===$field?' selected':'').'>'.$e($choice['label']).'</option>';
        $html.='</select></label><button class="btn btn-solid theme-secondary">Feld öffnen</button></form><form method="post" action="'.$e($action).'" class="fe-field-form" data-reference-url="'.$e(Routes::endpoint('reference-options')).'">'.Html::hidden('csrf',$this->csrf).Html::hidden('item',$id).Html::hidden('field',$field).Html::hidden('version',$version).Html::hidden('signature',$signature);
        if(str_starts_with($field,'custom.'))$control=(new Controls($this->db,$this->access,true))->render($definition,$value);
        elseif($field==='body_html')$control=RichTextEditor::render('value',is_string($value)?$value:'','Inhalt');
        elseif($field==='excerpt')$control='<label for="fe-field">Kurztext<textarea class="form-control" id="fe-field" name="value" maxlength="1200" rows="4">'.$e(is_scalar($value)?$value:'').'</textarea></label>';
        else $control=Html::input('value','Titel',is_scalar($value)?$value:'','text','id="fe-field" required maxlength="200"');
        if(str_starts_with($field,'custom.')||$field==='body_html')$control='<div id="fe-field" tabindex="-1">'.$control.'</div>';
        $html.=$this->noScriptRichText($control).'<div class="fe-actions"><button class="btn btn-solid theme-primary" name="operation" value="save">Entwurf speichern</button>';
        if($conflict)$html.='<button class="btn btn-solid theme-secondary" name="operation" value="resume" formnovalidate>Eingabe auf aktuellen Stand übernehmen</button><a class="btn btn-solid theme-secondary" href="'.$e($action).'">Aktuellen Entwurf laden</a>';
        $html.='</div></form>';
        if($state['can_publish']&&$page['has_unpublished_changes'])$html.='<details class="fe-publish"><summary>Veröffentlichen</summary><form method="post" action="'.$e($action).'">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','publish').Html::hidden('item',$id).Html::hidden('field',$field).Html::hidden('version',$page['version']).Html::hidden('signature',$state['signature']).Html::hidden('reviewed',$state['reviewed']).'<label class="fe-check"><input type="checkbox" name="review_confirm" value="1" required>Ich habe den gesamten gespeicherten Entwurf '.$page['version'].' geprüft.</label><button class="btn btn-solid theme-primary">Gesamten Entwurf veröffentlichen</button></form></details>';
        $html.='</section><section class="fe-preview" aria-label="Websitevorschau"><div class="fe-preview-heading"><h2>Websitevorschau</h2><a class="btn btn-solid theme-secondary" href="'.$e($preview).'" target="_blank" rel="noopener">Groß öffnen</a></div><p class="fe-hint">Privater gespeicherter Entwurf '.$page['version'].'</p><iframe title="Private Websitevorschau" src="'.$e($preview).'" sandbox="allow-same-origin allow-scripts allow-popups allow-popups-to-escape-sandbox"></iframe></section></div></div><noscript><style>.frontend-editor .kh-rte-tools,.frontend-editor [data-richtext-surface],.frontend-editor .kh-rte>noscript:last-child{display:none}</style></noscript></body></html>';
        return $html;
    }

    /** The canonical editor supplies safe HTML; ordinary textarea controls provide real no-JS editing. */
    private function noScriptRichText(string $html):string
    {
        return preg_replace_callback('/<input type="hidden" data-richtext-value name="([^"]+)" value="([^"]*)">/',static function(array $m):string{
            return $m[0].'<noscript><label>Formatierter Text (HTML)<textarea class="form-control" name="'.$m[1].'" rows="7">'.Html::e(html_entity_decode($m[2],ENT_QUOTES|ENT_HTML5,'UTF-8')).'</textarea></label></noscript>';
        },$html)??$html;
    }
}
