<?php declare(strict_types=1);
use Kulturhus\Verein\Web\OriginalInput;
$changeOriginalIds=[];foreach([$_GET['change']??null,$_POST['change']??null]as$changeOriginalId)if(is_string($changeOriginalId)&&preg_match('/\A[a-f0-9]{32}\z/D',$changeOriginalId))$changeOriginalIds[$changeOriginalId]=true;
$changeOriginalKey=$_POST['request_key']??($_GET['submitted_request']??null);
?>
<section class="card" data-member-change-rejected><h1><?=e($changeText('Änderungsangaben prüfen'))?></h1><p role="alert"><?=e($changeText($changeUiFailure->getMessage()))?></p>
<?php foreach(array_keys($changeOriginalIds)as$changeOriginalId):?><a class="btn btn-outline theme-secondary" target="_blank" rel="noopener" href="<?=e($changeUrl(['change'=>$changeOriginalId]))?>"><?=e($changeText('Gespeicherten Antrag in neuem Fenster prüfen'))?></a><?php endforeach?>
<?php if(!$reviewChanges&&is_string($changeOriginalKey)&&preg_match('/\A[a-f0-9]{32}\z/D',$changeOriginalKey)):?><a class="btn btn-outline theme-secondary" target="_blank" rel="noopener" href="<?=e($changeUrl(['submitted_request'=>$changeOriginalKey]))?>"><?=e($changeText('Gespeicherten Stand dieses Einreichungsvorgangs prüfen'))?></a><?php endif?>
<a class="btn btn-outline theme-secondary" target="_blank" rel="noopener" href="<?=e($changeUrl())?>"><?=e($changeText('Antragsübersicht in neuem Fenster öffnen'))?></a></section>
