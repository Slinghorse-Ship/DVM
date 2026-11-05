<?php declare(strict_types=1);
namespace Kulturhus\Native\CmsTransfer;
use Kulturhus\Native\Integration\Routes;
use Kulturhus\Native\Web\{BootstrapAssets,Html};

final class View
{
    public function __construct(private string $csrf){}
    public function render(array $catalog,array $recent,?array $job,string $error='',array $query=[]):string
    {
        $e=Html::e(...);$base=Routes::endpoint('cms-transfer');$head=BootstrapAssets::styles('/vereinsverwaltung/').'<link rel="stylesheet" href="/vereinsverwaltung/native-cms-transfer.css">';
        $html='<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>CMS-Inhalte übertragen · DVM</title>'.$head.'</head><body class="cms-transfer"><main><header><div><p class="ct-kicker">Website</p><h1>Inhalte übertragen</h1></div><a class="btn btn-solid theme-secondary" href="'.$e(Routes::url('model')).'">Zur Websiteverwaltung</a></header>';
        if($error!=='')$html.='<p class="ct-error" role="alert" tabindex="-1">'.$e($error).'</p>';
        $html.='<nav class="ct-tabs" aria-label="Übertragung"><a href="'.$e($base).'#export">Exportieren</a><a href="'.$e($base).'#import">Importieren</a><a href="'.$e($base).'#jobs">Aufträge</a></nav>';
        if($job!==null)$html.=$this->job($job,$query);
        $html.='<section id="export"><h2>Exportieren</h2><form method="get" action="/vereinsverwaltung/" class="ct-search">'.Html::hidden('native_endpoint','cms-transfer').'<label>Seiten suchen<input class="form-control" name="q" maxlength="100" value="'.$e($catalog['query']).'"></label><button class="btn btn-solid theme-secondary">Suchen</button></form><form method="post" action="'.$e($base).'">'.Html::hidden('csrf',$this->csrf).'<p class="ct-count">'.$catalog['total'].' Seiten · '.$catalog['page'].' / '.$catalog['pages'].'</p><div class="ct-rows">';
        foreach($catalog['rows']as$row)$html.='<label class="ct-row ct-select"><input type="checkbox" name="pages['.$e($row['id']).']" value="'.$row['version'].'"><span><strong>'.$e($row['title']).'</strong><small>'.$e($row['slug']).' · Entwurf '.$row['version'].'</small></span></label>';
        if(!$catalog['rows'])$html.='<p>Keine passenden Seiten.</p>';
        $html.='</div><p class="ct-muted">Verknüpfte Seiten und Dateien werden aufgenommen.</p><label class="ct-check"><input type="checkbox" name="languages" value="1" checked>Sprachentwürfe aufnehmen</label><div class="ct-actions"><button class="btn btn-solid theme-primary" name="operation" value="export">Auswahl herunterladen</button><button class="btn btn-solid theme-secondary" name="operation" value="export-model" formnovalidate>Modell und Bezüge herunterladen</button></div></form>'.$this->pager($catalog['page'],$catalog['pages'],['q'=>$catalog['query']],'page').'</section>';
        $prefix=is_string($query['prefix']??null)?$query['prefix']:'';
        $html.='<section id="import"><h2>Importieren</h2><form method="post" action="'.$e($base).'" enctype="multipart/form-data">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','prepare').'<label>CMS-Paket<input class="form-control" type="file" name="package" accept=".zip,.dvm.zip,application/zip" required></label><label>Kennung <span class="ct-muted">optional</span><input class="form-control" name="prefix" pattern="[a-z][a-z0-9_]{0,19}" maxlength="20" value="'.$e($prefix).'" autocomplete="off"></label><button class="btn btn-solid theme-primary">Paket prüfen</button></form></section>';
        $html.='<section id="jobs"><h2>Eigene Aufträge</h2><p class="ct-count">'.$recent['total'].' Aufträge</p><div class="ct-rows">';
        foreach($recent['rows']as$row)$html.='<a class="ct-row ct-job" href="'.$e(Routes::endpoint('cms-transfer',['job'=>$row['id']])).'"><span><strong>'.$e(substr($row['id'],0,8)).'</strong><small>'.$e($row['created_at']).'</small></span><span>'.$e(self::status($row['status'])).'</span></a>';
        if(!$recent['rows'])$html.='<p>Noch keine Aufträge.</p>';$html.='</div>'.$this->pager($recent['page'],$recent['pages'],[],'jobs_page').'</section></main></body></html>';return $html;
    }

    private function job(array $job,array $query):string
    {
        $e=Html::e(...);$action=Routes::endpoint('cms-transfer',['job'=>$job['id']]);$html='<section id="job" class="ct-plan"><div class="ct-plan-heading"><h2>Auftrag '.$e(substr($job['id'],0,8)).'</h2><span class="ct-state">'.$e(self::status($job['status'])).'</span></div><dl class="ct-counts">';
        foreach(['pages'=>'Seiten','fields'=>'Felder','templates'=>'Vorlagen','assets'=>'Dateien','languages'=>'Sprachentwürfe']as$key=>$label)$html.='<div><dt>'.$label.'</dt><dd>'.$job['counts'][$key].'</dd></div>';
        $html.='</dl><details><summary>Paketnachweis</summary><code class="ct-hash">'.$e($job['sha256']).'</code></details>';
        foreach($job['warnings']as$warning)$html.='<p class="ct-note">'.$e($warning).'</p>';
        $total=count($job['pages']);$pages=max(1,(int)ceil($total/10));$page=min($pages,max(1,(int)($query['plan_page']??1)));$html.='<div class="ct-rows">';
        foreach(array_slice($job['pages'],($page-1)*10,10)as$row){$url=$job['status']==='completed'?Routes::url('website',$row['target_id']):'';$title=$url!==''?'<a href="'.$e($url).'">'.$e($row['title']).'</a>':$e($row['title']);$html.='<article class="ct-row"><span><strong>'.$title.'</strong><small>'.$e($row['slug']).'</small></span><span class="ct-draft">Neuer Entwurf</span></article>';}
        $html.='</div>'.$this->pager($page,$pages,['job'=>$job['id']],'plan_page');
        if($job['status']==='ready'){
            $feedback=is_array($query['feedback']??null)?$query['feedback']:[];$retainedPrefix=is_string($feedback['prefix']??null)?$feedback['prefix']:$job['prefix'];
            $html.='<details class="ct-adjust"'.($feedback?' open':'').'><summary>Zuordnung ändern und erneut prüfen</summary><form method="post" action="'.$e($action).'">'.$this->hidden($job).Html::hidden('operation','revise').'<label>Kennung<input class="form-control" name="prefix" pattern="[a-z][a-z0-9_]{0,19}" maxlength="20" value="'.$e($retainedPrefix).'" required></label><button class="btn btn-solid theme-secondary">Kennung erneut prüfen</button></form><form method="post" action="'.$e($action).'">'.$this->hidden($job).Html::hidden('operation','revise');
            foreach(array_slice($job['pages'],($page-1)*10,10)as$row){$retainedSlug=is_string($feedback['slugs'][$row['source_id']]??null)?$feedback['slugs'][$row['source_id']]:$row['slug'];$html.='<label>'.$e($row['title']).'<input class="form-control" name="slugs['.$e($row['source_id']).']" maxlength="180" value="'.$e($retainedSlug).'" required></label>';}
            $html.='<button class="btn btn-solid theme-secondary">Zieladressen erneut prüfen</button></form></details>';
            if($job['stale'])$html.='<p class="ct-error" role="alert">Zielbestand geändert. Paket erneut prüfen.</p>';
            foreach($job['blockers']??[]as$blocker)$html.='<p class="ct-error" role="alert">'.$e($blocker).'</p>';
            $blocked=$job['stale']||!empty($job['blockers']);
            $html.='<form method="post" action="'.$e($action).'">'.$this->hidden($job).Html::hidden('operation','apply');
            if($job['secrets']){$titles=array_column($job['pages'],'title','source_id');$html.='<details class="ct-local"><summary>Lokale Passwortfelder ('.count($job['secrets']).')</summary>';foreach($job['secrets']as$i=>$secret){$name='secrets['.$secret['page_id'].']['.implode('.',$secret['path']).']';$html.='<label for="ct-secret-'.$i.'"><small>'.$e($titles[$secret['page_id']]??'').'</small>'.$e($secret['label']).($secret['required']?' *':'').'<input class="form-control" id="ct-secret-'.$i.'" type="password" name="'.$e($name).'" maxlength="4096" autocomplete="new-password" value=""></label>';}$html.='</details>';}
            $html.='<label class="ct-check"><input type="checkbox" name="confirmed" value="1" required'.($blocked?' disabled':'').'>Neue unveröffentlichte Entwürfe anlegen</label><button class="btn btn-solid theme-primary"'.($blocked?' disabled':'').'>Importieren</button></form><details><summary>Auftrag abbrechen</summary><form method="post" action="'.$e($action).'">'.$this->hidden($job).Html::hidden('operation','cancel').'<label class="ct-check"><input type="checkbox" name="confirmed" value="1" required>Auftrag abbrechen</label><button class="btn btn-solid theme-secondary">Abbrechen</button></form></details>';
        }else{
            if($job['status']==='completed')$html.='<p class="ct-success" role="status">Neue Entwürfe angelegt.</p>';
            $html.='<details><summary>Private Paketkopien entfernen</summary><form method="post" action="'.$e($action).'">'.$this->hidden($job).Html::hidden('operation','cleanup').'<label class="ct-check"><input type="checkbox" name="confirmed" value="1" required>Private Paketkopien entfernen</label><button class="btn btn-solid theme-secondary">Paketkopien entfernen</button></form></details>';
        }
        return $html.'</section>';
    }
    private function hidden(array $job):string
    {return Html::hidden('csrf',$this->csrf).Html::hidden('job',$job['id']).Html::hidden('version',$job['version']);}
    private function pager(int $page,int $pages,array $query,string $key):string
    {
        if($pages<=1)return '';$e=Html::e(...);$html='<nav class="ct-pager" aria-label="Seiten">';if($page>1)$html.='<a href="'.$e(Routes::endpoint('cms-transfer',$query+[$key=>$page-1])).'">Zurück</a>';$html.='<span>'.$page.' / '.$pages.'</span>';if($page<$pages)$html.='<a href="'.$e(Routes::endpoint('cms-transfer',$query+[$key=>$page+1])).'">Weiter</a>';return $html.'</nav>';
    }
    private static function status(string $status):string
    {return match($status){'ready'=>'Geprüft','completed'=>'Abgeschlossen','cancelled'=>'Abgebrochen',default=>'Unbekannt'};}
}
