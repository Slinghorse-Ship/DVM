<?php declare(strict_types=1);
use Kulturhus\Verein\Web\MinutesPageState;
$minutesReadIds=[];foreach([$_GET['mid']??null,$_POST['mid']??null]as$minutesReadId)if(is_string($minutesReadId)&&preg_match('/\A[a-f0-9]{32}\z/D',$minutesReadId))$minutesReadIds[$minutesReadId]=true;
$minutesReadKey=$_POST['request_key']??($_GET['created_request']??null);
?>
<section class="content" data-minutes-rejected><h1><?=e($minutesText('Protokollangaben prüfen'))?></h1><p role="alert"><?=e($minutesText($minutesUiFailure->getMessage()))?></p><details open class="card"><summary><?=e($minutesText('Protokollangaben'))?></summary><pre><?=e(MinutesPageState::originals($_GET,$_POST,$_FILES??[]))?></pre></details>
<?php foreach(array_keys($minutesReadIds)as$minutesReadId):?><a class="btn btn-outline theme-secondary" target="_blank" rel="noopener" href="<?=e($minutesUrl(['mid'=>$minutesReadId]))?>"><?=e($minutesText('Gespeichertes Protokoll in neuem Fenster prüfen'))?></a><?php endforeach?>
<?php if($minutesReadIds===[]&&$minutes->canWrite()&&is_string($minutesReadKey)&&preg_match('/\A[a-f0-9]{32}\z/D',$minutesReadKey)):?><a class="btn btn-outline theme-secondary" target="_blank" rel="noopener" href="<?=e($minutesUrl(['created_request'=>$minutesReadKey]))?>"><?=e($minutesText('Gespeicherten Stand dieses Anlagevorgangs prüfen'))?></a><?php endif?>
<a class="btn btn-outline theme-secondary" target="_blank" rel="noopener" href="<?=e($minutesUrl())?>"><?=e($minutesText('Protokollübersicht bewusst neu öffnen'))?></a></section>
