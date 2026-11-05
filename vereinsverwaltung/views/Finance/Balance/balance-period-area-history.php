<?php declare(strict_types=1);
$areaHistory=$areaCurrent['history']??$a['history']??[];$areaPage=input($_GET,'area_history_page','1');$areaPageValid=(!array_key_exists('area_history_page',$_GET)||is_string($_GET['area_history_page']))&&preg_match('/\A[1-9][0-9]{0,3}\z/D',$areaPage)===1;$areaPages=max(1,(int)ceil(count($areaHistory)/20));$areaPageNumber=$areaPageValid?(int)$areaPage:0;
?>
<section class="card" data-period-area-history><h2><?= e($areaText('Entscheidungsverlauf')) ?></h2>
<?php if(!$areaPageValid||$areaPageNumber>$areaPages): ?><p role="alert"><?= e($areaText('Eine vollständige vorhandene Verlaufsseite auswählen.')) ?></p>
<?php else: ?><ol start="<?= (($areaPageNumber-1)*20)+1 ?>">
<?php foreach(array_slice(array_reverse($areaHistory),($areaPageNumber-1)*20,20)as$areaRow): ?><li><a data-area-history-link="<?= $areaRow['revision'] ?>" href="<?= e(url($areaHome+['area_original'=>(string)$areaRow['revision'],'area_original_sha256'=>$areaRow['sha256']])) ?>"><?= e($areaText('Prüfstand')).' '.(int)$areaRow['revision'].' · '.e($areaText($areaRow['state']==='reviewed'?'Bestätigt':'Entwurf')).' · '.e($areaRow['note']) ?></a></li><?php endforeach ?></ol>
<?php if($areaPages>1): ?><nav aria-label="<?= e($areaText('Seiten des ursprünglichen Verlaufs')) ?>"><?php for($page=1;$page<=$areaPages;$page++): ?><a class="btn btn-outline" <?= $page===$areaPageNumber?'aria-current="page"':'' ?> href="<?= e(url($areaHome+['area_history_page'=>(string)$page])) ?>"><?= $page ?></a> <?php endfor ?></nav><?php endif;endif ?></section>
