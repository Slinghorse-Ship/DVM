<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Language\{Catalog,VideoPolicyMessages};
use Kulturhus\Native\Communication\{VideoQuota,VideoRetention};

/** Isolated club-rule forms never replace an unfinished editorial document. */
final class VideoPolicyForm
{
    public static function definition(string $kind):array
    {
        return match($kind){
            'quota'=>['operation'=>'social-video-quota-save','field'=>'limit_mib','version'=>'quota_version','title'=>'Videospeicher','label'=>'Speichergrenze (MiB)','help'=>'0 = unbegrenzt','save'=>'Speichergrenze speichern','max'=>VideoQuota::MAX_MIB],
            'retention'=>['operation'=>'social-video-retention-save','field'=>'minimum_days','version'=>'retention_version','title'=>'Videoaufbewahrung','label'=>'Mindestaufbewahrung (Tage)','help'=>'Gilt für künftige Entfernungen. Gespeicherte Fristen bleiben erhalten.','save'=>'Aufbewahrung speichern','max'=>VideoRetention::MAX_DAYS],
            default=>throw new DomainException('Videospeicherregel erneut öffnen.'),
        };
    }
    public static function operation(mixed $operation):bool{return in_array($operation,['social-video-quota-save','social-video-retention-save'],true);}
    public static function restore(array $post):array
    {
        $kind=match($post['operation']??null){'social-video-quota-save'=>'quota','social-video-retention-save'=>'retention',default=>throw new DomainException('Ursprüngliches Videospeicherformular öffnen.')};$d=self::definition($kind);$s=['kind'=>$kind];
        foreach(['id','policy_request_key']as$key){$s[$key]=$post[$key]??null;if(!is_string($s[$key])||!preg_match('/\A[a-f0-9]{32}\z/D',$s[$key]))throw new DomainException('Ursprüngliches Videospeicherformular öffnen.');}
        foreach(['version','policy_version']as$key){$s[$key]=$post[$key==='policy_version'?$d['version']:$key]??null;if(!is_string($s[$key])||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$s[$key])||(int)$s[$key]>4294967295||($key==='version'&&(int)$s[$key]<1))throw new DomainException('Ursprüngliches Videospeicherformular öffnen.');}
        $s['value']=$post[$d['field']]??null;if(!is_string($s['value'])||strlen($s['value'])>20||preg_match('/\A[\x20-\x7e]*\z/D',$s['value'])!==1)throw new DomainException('Ursprüngliches Videospeicherformular öffnen.');return$s;
    }
    public static function usage(array $state,array $messages):string
    {
        $english=VideoPolicyMessages::text('Videospeicher',$messages)==='Video storage';$format=static fn(int $bytes):string=>number_format($bytes/1048576,2,$english?'.':',',$english?',':'.').' MiB';$t=static fn(string$s,array$v=[]):string=>Html::e(VideoPolicyMessages::text($s,$messages,$v));
        return '<div data-video-policy-usage><p>'.$t('{count} Quellen · {used} belegt',['count'=>$state['source_count'],'used'=>$format($state['used_bytes'])]).'</p><p>'.($state['limit_bytes']===0?$t('Unbegrenzt'):$t('{free} frei · {total} gesamt',['free'=>$format($state['remaining_bytes']),'total'=>$format($state['limit_bytes'])])).'</p></div>';
    }
    public static function render(array $item,string $kind,array $rule,string $csrf,array $messages,?array $state=null,bool $separate=true,bool $canManage=true):string
    {
        $d=self::definition($kind);$t=static fn(string$s):string=>Html::e(VideoPolicyMessages::text($s,$messages));$restored=$state!==null;
        $state??=['kind'=>$kind,'id'=>$item['id'],'version'=>(string)$item['version'],'policy_version'=>(string)$rule['version'],'value'=>(string)($kind==='quota'?intdiv($rule['limit_bytes'],1048576):$rule['minimum_days']),'policy_request_key'=>bin2hex(random_bytes(16))];
        $confirmed=($state['result']['found']??false)===true;$readonly=!$canManage;$h='<section class="video-policy-form" data-video-policy="'.Html::e($kind).'" data-policy-read-only="'.($readonly?'1':'0').'" data-policy-interface="'.Html::e(json_encode(VideoPolicyMessages::browser($messages),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'">';
        if(!$separate)$h.='<h2>'.$t($d['title']).'</h2>';
        if(!empty($state['error']))$h.='<p role="alert">'.Html::e($state['error']).'</p>';
        if(!empty($state['prepared']))$h.='<p role="status">'.$t('Weitere Änderung vorbereitet. Deine Eingaben bleiben erhalten.').'</p>';
        if($confirmed){$result=$state['result'];$h.='<section class="video-policy-saved" data-policy-confirmed="'.Html::e(json_encode($result,JSON_THROW_ON_ERROR)).'"><h3>'.$t('Gespeicherte Regel').'</h3><p role="status">'.$t('Regel gespeichert. Der Beitrag bleibt geöffnet.').'</p><p>'.$t($d['label']).': '.Html::e($result['value']).'</p><p>'.$t('Regelversion').': '.Html::e($result['version']).'</p></section><p>'.$t('Gespeicherte Regel gefunden. Deine weiteren Eingaben bleiben erhalten. Für eine neue Speicherung weitere Änderung vorbereiten.').'</p>';}
        $h.='<p>'.$t($readonly?'Du kannst deine ursprüngliche Speicherung prüfen. Für Änderungen fehlt die Verwaltungsberechtigung.':($separate?'Ohne JavaScript öffnen Speicherung und Prüfung ein eigenes Fenster. Der ungespeicherte Beitrag bleibt im ursprünglichen Fenster.':'Deine ursprünglichen Eingaben bleiben erhalten.')).'</p>';
        $h.='<form method="post" target="'.($separate?'_blank':'_self').'" data-video-policy-form'.($restored&&empty($state['saved_now'])?' data-unsaved-changes':'').'>'.Html::hidden('csrf',$csrf).Html::hidden('operation',$d['operation']).Html::hidden('policy_window','1');
        foreach(['id','version','policy_request_key']as$key)$h.=Html::hidden($key,$state[$key]);$h.=Html::hidden($d['version'],$state['policy_version']);
        if($readonly)$h.=str_replace('<input ','<input data-policy-readonly-copy ',Html::hidden($d['field'],$state['value']));
        $valid=preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/D',$state['value'])===1&&(int)$state['value']<=$d['max'];
        $h.='<fieldset data-policy-fields'.($readonly?' disabled':'').'>'.Html::input($d['field'],VideoPolicyMessages::text($d['label'],$messages),$state['value'],$valid?'number':'text','required min="0" max="'.$d['max'].'" step="1" maxlength="20" inputmode="numeric" data-policy-value').'<small>'.$t($d['help']).'</small></fieldset><div class="actions">';
        foreach(['save'=>[$d['save'],'','',$readonly||$confirmed],'review'=>['Gespeicherte Regel prüfen','policy_review','1',false],'retry'=>['Ursprüngliche Regel erneut speichern','','',true],'next'=>['Weitere Änderung vorbereiten','policy_next','1',!$confirmed||$readonly]]as$key=>[$label,$name,$value,$hidden])$h.='<button class="btn '.($key==='save'?'btn-solid theme-primary':'btn-outline theme-secondary').'" type="'.($key==='retry'?'button':'submit').'" data-policy-'.$key.($name!==''?' name="'.$name.'" value="'.$value.'" formnovalidate':'').($hidden?' hidden':'').($key==='save'&&$readonly?' disabled':'').'>'.$t($label).'</button>';
        $h.='</div></form><p role="status" aria-live="polite" tabindex="-1" data-policy-status>'.(is_array($state['result']??null)&&!$confirmed?$t('Keine gespeicherte Regel für diesen Vorgang gefunden. Unverändert erneut speichern oder Eingaben bewusst korrigieren.'):'').'</p>';
        return$h.'<a class="btn btn-outline theme-secondary" data-save-original target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'compose','item'=>$item['id'],'video_page'=>1]).'#social-videos').'">'.$t('Beitrag mit aktuellen Regeln öffnen').'</a></section>';
    }
    public static function page(PDO $db,Access $access,string $mediaRoot,string $csrf,array $state):string
    {
        $actor=$access->require('social','read');$item=(new \Kulturhus\Native\Communication\Workspace($db,$access,'social',$mediaRoot))->get($state['id']);$canManage=true;try{$access->require('social','manage');}catch(DomainException){$canManage=false;}$messages=(new Catalog($db,$access))->messages();
        // A read-only personal receipt must not disclose the latest restricted retention rule.
        $rule=$canManage?($state['kind']==='quota'?(new VideoQuota($db,$access))->get():(new VideoRetention($db,$access))->get()):[];
        $h='<section class="panel">'.self::render($item,$state['kind'],$rule,$csrf,$messages,$state,false,$canManage).'</section>';$current=$access->require('social','read');if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');return$h;
    }
}
