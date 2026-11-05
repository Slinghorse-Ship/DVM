<?php declare(strict_types=1);
$draftState=input($_GET,'draft_state','active');
$textDrafts=$website->drafts($websiteTab==='pages'?'page':'news',$draftState);
?>
<section class="card"><h2><?= $draftState==='archived'?'Archivierte Textentwürfe':'Textentwürfe' ?></h2><p>Diese Textentwürfe sind getrennt von veröffentlichten Website-Inhalten. Öffnen und Bearbeiten verändert noch keine öffentliche Seite.</p><?php if(!$textDrafts): ?><p>Keine Textentwürfe in dieser Ansicht.</p><?php else: ?><ul><?php foreach($textDrafts as $draft): ?><li><a href="<?= e(url(['view'=>'website','tab'=>$websiteTab,'draft'=>$draft['id']])) ?>"><?= e($draft['title']) ?></a> · <?= e($websiteLabels[$draft['status']]??$draft['status']) ?></li><?php endforeach ?></ul><?php endif ?></section>
