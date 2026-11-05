<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Language\SystemMessages;

final class View
{
    public const SECTIONS=['tasks'=>'Lokale Aufgaben','journal'=>'Betriebshinweise','runs'=>'Lokale Läufe','package'=>'Paket & Datenbank'];
    private function __construct(private array $messages,private string $locale){}
    private function t(string $source):string{return Html::e(SystemMessages::text($source,$this->messages));}
    private function phrase(string $source,array $values):string{return Html::e(strtr(SystemMessages::text($source,$this->messages),$values));}
    private function number(int $value):string{return number_format($value,0,'.',explode('-',$this->locale,2)[0]==='en'?',':'.');}
    private static function url(string $section,array $query=[]):string{return '?'.http_build_query(['view'=>'settings','tab'=>'system','system_section'=>$section]+$query);}
    private function stamp(mixed $value):string
    {
        if($value===null||$value==='')return SystemMessages::text('Noch kein Nachweis',$this->messages);
        try{$date=is_int($value)?new \DateTimeImmutable('@'.$value):new \DateTimeImmutable((string)$value,new \DateTimeZone('UTC'));return $date->setTimezone(new \DateTimeZone('Europe/Berlin'))->format(explode('-',$this->locale,2)[0]==='en'?'d/m/Y H:i':'d.m.Y H:i');}
        catch(\Throwable){return SystemMessages::text('Zeitpunkt prüfen',$this->messages);}
    }
    private static function raw(mixed $value):string{return is_string($value)?$value:json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);}
    private function reopen(array $query=[]):string
    {
        $section=is_string($query['system_section']??null)&&isset(self::SECTIONS[$query['system_section']])?$query['system_section']:'tasks';
        return '<a class="btn btn-outline theme-secondary button secondary" data-system-reopen href="'.Html::e(self::url($section,['system_reopen'=>'1'])).'">'.$this->t('Aktuellen Stand neu öffnen').'</a>';
    }
    private function original(array $draft,array $query=[]):string
    {
        $h='<section class="system-original" data-system-original><h2>'.$this->t('Ursprünglicher Auftrag').'</h2><p>'.$this->t('Die ursprünglichen Angaben bleiben zur Prüfung erhalten. Öffne den aktuellen Stand bewusst neu, bevor du einen neuen Auftrag bestätigst.').'</p><dl>';
        foreach(['action'=>'Anfrage','task'=>'Aufgabe','version'=>'Ursprünglicher Stand','enabled'=>'Aktivierung','interval'=>'Intervall','event'=>'Betriebshinweis']as$key=>$label){$field=$draft['fields'][$key]??['present'=>false,'value'=>null];if(!$field['present']&&!in_array($key,['action','task','version'],true))continue;$h.='<dt>'.$this->t($label).'</dt><dd><pre data-system-original-field="'.$key.'">'.Html::e($field['present']?self::raw($field['value']):SystemMessages::text('Nicht übermittelt',$this->messages)).'</pre></dd>';}
        if($draft['extra_names']??[])$h.='<dt>'.$this->t('Zusätzliche Felder').'</dt><dd><pre>'.Html::e(self::raw($draft['extra_names'])).'</pre></dd>';
        return $h.'</dl>'.$this->reopen($query).'</section>';
    }
    private function form(string $csrf,string $action,array $hidden=[]):string
    {$h='<form method="post" class="system-form">'.Html::hidden('csrf',$csrf).Html::hidden('action',$action);foreach($hidden as$key=>$value)$h.=Html::hidden($key,(string)$value);return $h;}
    private function select(string $name,string $label,array $choices,string $value):string
    {$labels=[];foreach($choices as$key=>$source)$labels[$key]=SystemMessages::text($source,$this->messages);return Html::select($name,SystemMessages::text($label,$this->messages),$labels,$value);}
    private function pages(string $section,array $window,string $parameter,array $extra=[]):string
    {$h='<nav class="system-pages" aria-label="'.$this->t('Systemliste blättern').'">';foreach([-1=>'Vorherige',1=>'Weitere']as$delta=>$label){$page=$window['page']+$delta;if($page>=1&&$page<=$window['pages'])$h.='<a class="btn btn-outline theme-secondary button secondary" href="'.Html::e(self::url($section,[$parameter=>$page]+$extra)).'">'.$this->t($label).'</a>';}return $h.'</nav>';}
    public static function render(\PDO $db,Authority $authority,PrivateFiles $private,string $csrf,array $query,string $notice,string $error,array $draft=[],array $messages=[],string $locale='de'):string
    {
        if($messages===[]){$catalog=new \Kulturhus\Native\Language\Catalog($db,$authority->access);$locale=$catalog->locale();$messages=$catalog->messages($locale);}
        return (new self($messages,$locale))->body($db,$authority,$private,$csrf,SystemForm::query($query),$notice,$error,$draft);
    }
    private function body(\PDO $db,Authority $authority,PrivateFiles $private,string $csrf,array $query,string $notice,string $error,array $draft):string
    {
        $section=$query['system_section'];$h='<link rel="stylesheet" href="/vereinsverwaltung/system.css"><section class="system-admin" data-native-system data-system-section="'.Html::e($section).'"><header class="system-heading"><h1>'.$this->t('Systemverwaltung').'</h1>'.($error===''?$this->reopen($query):'').'</header><nav class="system-sections" aria-label="'.$this->t('Systembereiche').'">';
        foreach(self::SECTIONS as$id=>$label)$h.='<a class="btn btn-outline theme-secondary button secondary" href="'.Html::e(self::url($id)).'"'.($section===$id?' aria-current="page"':'').'>'.$this->t($label).'</a>';$h.='</nav>';
        if($notice!=='')$h.='<p class="system-feedback" role="status">'.$this->t($notice).'</p>';
        if($error!==''){
            $h.='<p class="system-error" role="alert">'.$this->t($error).'</p>';
            if($draft!==[]){$h.=$this->original($draft,$query);if(SystemForm::representable($draft))$h.=$this->originalSchedule($draft);}
            else $h.=$this->reopen($query);
            return $h.'</section>';
        }
        return $h.match($section){'tasks'=>$this->tasks(new Tasks($db,$authority),$csrf),'journal'=>$this->journal(new Journal($db,$authority),$csrf,$query),'runs'=>$this->runs(new Tasks($db,$authority),$query),'package'=>$this->package(new Installation($db,$authority,$private),$query)}.'</section>';
    }
    private function originalSchedule(array $draft):string
    {
        $task=SystemForm::value($draft,'task');$version=SystemForm::value($draft,'version');$enabled=($draft['fields']['enabled']['present']??false)&&SystemForm::value($draft,'enabled')==='1';$interval=SystemForm::value($draft,'interval');
        return '<article class="system-task" data-system-task="'.Html::e($task).'" data-system-readonly><h2>'.$this->t(Catalog::TASKS[$task]).'</h2><p>'.$this->t('Ursprünglicher Zeitplan').'</p><form class="system-form"><fieldset disabled>'.Html::hidden('action','system-task-save').Html::hidden('task',$task).Html::hidden('version',$version).'<label class="system-choice"><input class="check" type="checkbox" name="enabled" value="1"'.($enabled?' checked':'').'> '.$this->t('Zeitplan ausdrücklich aktivieren').'</label>'.$this->select('interval','Intervall',Catalog::INTERVALS,$interval).'</fieldset></form></article>';
    }
    private function tasks(Tasks $tasks,string $csrf):string
    {
        $h='<p>'.$this->t('Hier planst du lokale Wartungsaufgaben. Eine Freigabe und die bestätigte Ausführung im Verlauf sind getrennte Schritte.').'</p><div class="system-task-list">';
        foreach($tasks->listing()as$row){$state=!$row['enabled']?'Pausiert':($row['failures']>=Scheduler::MAX_FAILURES?'Freigabe prüfen':($row['running']?'Läuft':'Freigegeben'));$h.='<article class="system-task" data-system-task="'.Html::e($row['id']).'"><header><h2>'.$this->t($row['label']).'</h2><strong>'.$this->t($state).'</strong></header><p>'.$this->phrase('Nächste Fälligkeit: {time}',['{time}'=>$this->stamp($row['next_due'])]).'</p>';if($row['running'])$h.='<p>'.$this->phrase('Laufbestätigung gültig bis {time}',['{time}'=>$this->stamp($row['lease_until'])]).'</p>';if($row['failures'])$h.='<p>'.$this->phrase('{count} von {max} erfolglosen Versuchen',['{count}'=>$this->number($row['failures']),'{max}'=>$this->number(Scheduler::MAX_FAILURES)]).'</p>';
            $h.=$this->form($csrf,'system-task-save',['task'=>$row['id'],'version'=>$row['version']]).'<label class="system-choice"><input class="check" type="checkbox" name="enabled" value="1"'.($row['enabled']?' checked':'').'> '.$this->t('Zeitplan ausdrücklich aktivieren').'</label>'.$this->select('interval','Intervall',Catalog::INTERVALS,(string)$row['interval_minutes']).'<button class="btn btn-solid theme-primary button">'.$this->t('Zeitplan speichern').'</button></form>';
            if($row['enabled']&&!$row['running']&&$row['failures']<Scheduler::MAX_FAILURES)$h.=$this->form($csrf,'system-task-request',['task'=>$row['id'],'version'=>$row['version']]).'<button class="btn btn-outline theme-secondary button secondary">'.$this->t('Beim nächsten privaten Lauf prüfen').'</button></form>';$h.='</article>';
        }return $h.'</div>';
    }
    private function journal(Journal $journal,string $csrf,array $query):string
    {
        $window=$journal->listing(['state'=>$query['journal_state']??'all','level'=>$query['journal_level']??'all','page'=>$query['journal_page']??1]);$h='<form method="get" class="system-filter">'.Html::hidden('view','settings').Html::hidden('tab','system').Html::hidden('system_section','journal').$this->select('journal_state','Hinweise',['all'=>'Alle Ereignisse','unread'=>'Offene Hinweise','acknowledged'=>'Von mir bestätigt'],$window['state']).$this->select('journal_level','Schwere',['all'=>'Alle','info'=>'Information','warning'=>'Prüfen','error'=>'Handeln'],$window['level']).'<button class="btn btn-outline theme-secondary button secondary">'.$this->t('Anzeigen').'</button></form><p data-system-count>'.$this->phrase('{total} Ereignisse · Seite {page} von {pages}',['{total}'=>$this->number($window['total']),'{page}'=>$this->number($window['page']),'{pages}'=>$this->number($window['pages'])]).'</p><ol class="system-records">';
        foreach($window['rows']as$row){$context=$row['context'];$h.='<li data-system-event="'.Html::e($row['id']).'"><header><strong>'.$this->t(Catalog::EVENTS[$row['code']][1]).'</strong><time>'.Html::e($this->stamp($row['created_at'])).'</time></header>';if(isset($context['task']))$h.='<p>'.$this->t(Catalog::task($context['task'])).'</p>';if(isset($context['count']))$h.='<p>'.$this->phrase('Anzahl: {count}',['{count}'=>$this->number($context['count'])]).'</p>';if($row['notice'])$h.=$row['acknowledged']?'<strong>'.$this->t('Von dir bestätigt').'</strong>':$this->form($csrf,'system-notice-ack',['event'=>$row['id']]).'<button class="btn btn-outline theme-secondary button secondary">'.$this->t('Hinweis bestätigen').'</button></form>';$h.='</li>';}
        return $h.'</ol>'.$this->pages('journal',$window,'journal_page',['journal_state'=>$window['state'],'journal_level'=>$window['level']]);
    }
    private function runs(Tasks $tasks,array $query):string
    {
        $window=$tasks->history(['task'=>$query['run_task']??'','state'=>$query['run_state']??'all','page'=>$query['run_page']??1]);$labels=['running'=>'Läuft','completed'=>'Abgeschlossen','failed'=>'Nicht bestätigt','abandoned'=>'Unterbrochen','cancelled'=>'Pausiert'];$h='<form method="get" class="system-filter">'.Html::hidden('view','settings').Html::hidden('tab','system').Html::hidden('system_section','runs').$this->select('run_task','Aufgabe',[''=>'Alle Aufgaben']+Catalog::TASKS,$window['task']).$this->select('run_state','Zustand',['all'=>'Alle Zustände']+$labels,$window['state']).'<button class="btn btn-outline theme-secondary button secondary">'.$this->t('Anzeigen').'</button></form><p data-system-count>'.$this->phrase('{total} Läufe · Seite {page} von {pages}',['{total}'=>$this->number($window['total']),'{page}'=>$this->number($window['page']),'{pages}'=>$this->number($window['pages'])]).'</p><ol class="system-records">';
        foreach($window['rows']as$row)$h.='<li data-system-run><header><strong>'.$this->t(Catalog::task($row['task'])).'</strong><span>'.$this->t($labels[$row['status']]??'Prüfen').'</span></header><p>'.$this->phrase('Beginn {time} · Versuch {attempt}',['{time}'=>$this->stamp($row['started_at']),'{attempt}'=>$this->number($row['attempt'])]).'</p><p>'.$this->phrase('Letzte Rückmeldung {time}',['{time}'=>$this->stamp($row['heartbeat_at'])]).'</p><p>'.$this->phrase('Abschluss {time}',['{time}'=>$this->stamp($row['finished_at'])]).'</p>'.(isset($row['summary']['count'])?'<p>'.$this->phrase('Anzahl: {count}',['{count}'=>$this->number($row['summary']['count'])]).'</p>':'').'</li>';
        return $h.'</ol>'.$this->pages('runs',$window,'run_page',['run_task'=>$window['task'],'run_state'=>$window['state']]);
    }
    private function package(Installation $installation,array $query):string
    {
        $snapshot=$installation->snapshot();$h='<article class="system-task"><h2>'.$this->t('Paketstand').'</h2><dl><dt>'.$this->t('Laufende Paketgeneration').'</dt><dd>'.Html::e((string)$snapshot['generation']).'</dd><dt>'.$this->t('Installation').'</dt><dd>'.$this->t($snapshot['mode']==='installed'?'Ursprünglicher Installationsbeleg vorhanden':'Quellumgebung · kein Installationsbeleg').'</dd><dt>'.$this->t('Datenbankmodule').'</dt><dd>'.$this->t($snapshot['schema_current']?'Aktuell':'Prüfen').'</dd><dt>'.$this->t('Wartung').'</dt><dd>'.$this->t($snapshot['maintenance']?'Aktiv':'Nicht aktiv').'</dd></dl>';
        if($snapshot['installation'])$h.='<details><summary>'.$this->t('Ursprünglicher Paketbeleg').'</summary><p>'.$this->phrase('Generation {generation}',['{generation}'=>(string)($snapshot['installation']['package_generation']??SystemMessages::text('Unbekannt',$this->messages))]).'</p><p class="system-hash">'.$this->phrase('Quelle {source}',['{source}'=>$snapshot['installation']['source']]).'</p><p class="system-hash">'.$this->phrase('Beleg SHA-256 {sha256}',['{sha256}'=>$snapshot['installation']['receipt_sha256']]).'</p></details>';
        if($snapshot['upgrade'])$h.='<details><summary>'.$this->t('Ursprünglicher Aktualisierungsstand').'</summary><p>'.$this->phrase('MAC-geprüft · {phase}',['{phase}'=>$snapshot['upgrade']['phase']]).'</p><p>'.$this->phrase('Beginn {time}',['{time}'=>$this->stamp($snapshot['upgrade']['started_at'])]).'</p><p>'.$this->phrase('Abschluss {time}',['{time}'=>$this->stamp($snapshot['upgrade']['completed_at'])]).'</p><p class="system-hash">'.$this->phrase('Vorher {source}',['{source}'=>$snapshot['upgrade']['old_source']]).'</p><p class="system-hash">'.$this->phrase('Danach {source}',['{source}'=>$snapshot['upgrade']['new_source']]).'</p></details>';
        $h.='<details><summary>'.$this->phrase('Modulstände ({count})',['{count}'=>$this->number(count($snapshot['schemas']))]).'</summary><ol class="system-records">';foreach($snapshot['schemas']as$row)$h.='<li><strong>'.Html::e($row['module']).' · '.Html::e((string)($row['version']??SystemMessages::text('Fehlt',$this->messages))).' / '.Html::e((string)$row['expected']).'</strong><span>'.$this->t($row['current']?'Aktuell':'Prüfen').'</span><span>'.Html::e($this->stamp($row['applied_at'])).'</span></li>';$h.='</ol></details></article>';
        $history=$installation->migrations($query['migration_page']??1);$h.='<details class="system-task"'.(isset($query['migration_page'])?' open':'').'><summary>'.$this->phrase('Ursprüngliche Datenbankmigrationen ({count})',['{count}'=>$this->number($history['total'])]).'</summary><ol class="system-records">';foreach($history['rows']as$row)$h.='<li><strong>'.$this->phrase('Migration {version}',['{version}'=>(string)$row['version']]).'</strong><time>'.Html::e($this->stamp($row['applied_at'])).'</time></li>';$h.='</ol>'.$this->pages('package',$history,'migration_page').'</details>';
        $cursor=$query['receipt_after']??'';$receipts=$installation->receipts($cursor);$h.='<details class="system-task"'.($cursor!==''?' open':'').'><summary>'.$this->phrase('Private Originale vor Aktualisierungen ({count})',['{count}'=>$this->number($receipts['total'])]).'</summary><ol class="system-records">';foreach($receipts['rows']as$row)$h.='<li><strong>'.$this->t('Ursprünglicher Stand vor einem Aktualisierungsvorgang').'</strong><span>'.$this->phrase('Generation {generation}',['{generation}'=>(string)($row['package_generation']??SystemMessages::text('Unbekannt',$this->messages))]).'</span><span class="system-hash">'.$this->phrase('Quelle {source}',['{source}'=>$row['source']]).'</span><span class="system-hash">'.$this->phrase('Beleg SHA-256 {sha256}',['{sha256}'=>$row['receipt_sha256']]).'</span></li>';$h.='</ol>';if($receipts['more'])$h.='<a class="btn btn-outline theme-secondary button secondary" href="'.Html::e(self::url('package',['receipt_after'=>$receipts['next']])).'">'.$this->t('Weitere Originalbelege').'</a>';return $h.'</details>';
    }
    public static function unavailable(string $error,array $query=[],array $draft=[],array $messages=[],string $locale='de'):string
    {
        $ui=new self($messages,$locale);$h='<link rel="stylesheet" href="/vereinsverwaltung/system.css"><section class="content system-admin" data-native-system data-system-unavailable><h1>'.$ui->t('Systemverwaltung').'</h1><p class="system-error" role="alert">'.$ui->t($error).'</p>';
        $original=SystemForm::originalQuery($query);if($original!==[])$h.='<h2>'.$ui->t('Ursprüngliche Ansichtsauswahl').'</h2><pre data-system-original-query>'.Html::e(self::raw($original)).'</pre>';
        return $h.($draft!==[]?$ui->original($draft,$query):$ui->reopen($query)).'</section>';
    }
    public static function document(string $content,array $messages,string $locale,string $appearanceAttributes=''):string
    {
        $title=Html::e(SystemMessages::text('Systemverwaltung',$messages));return '<!doctype html><html lang="'.Html::e($locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title>'.\Kulturhus\Native\Web\BootstrapAssets::styles('/vereinsverwaltung/').\Kulturhus\Native\Appearance\Chrome::assets().'</head><body class="system-native"'.$appearanceAttributes.'><main class="system-native-main"><nav class="system-back"><a class="btn btn-outline theme-secondary button secondary" href="/account">'.Html::e(SystemMessages::text('Mein Konto',$messages)).'</a></nav>'.$content.'</main></body></html>';
    }
}
