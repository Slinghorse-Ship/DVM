<?php declare(strict_types=1);$reviewText??=static fn(string $text,array $values=[]):string=>\Kulturhus\Native\Language\ApplicationReviewMessages::text($text,$applicationMessages??[],$values);
$previewDocs=$admissionVotes->documents($previewApplicationId);
if(!$previewDocs): ?><p class="muted"><?= e($reviewText('Noch kein PDF oder Scan zu diesem Antrag abgelegt.')) ?></p><?php return;endif;
$previewDocument=input($_GET,'pdf');if(!in_array($previewDocument,array_column($previewDocs,'id'),true))$previewDocument=$previewDocs[0]['id'];
$previewFile=$admissionVotes->documentFile($previewApplicationId,$previewDocument);
$previewUrl=url(['admission_file'=>$previewDocument,'application'=>$previewApplicationId,'preview'=>1]);
?>
<section class="card application-preview" aria-label="<?= e($reviewText('Eingereichter Antrag')) ?>">
  <div class="section-heading"><h2><?= e($reviewText('Antrag lesen')) ?></h2><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['admission_file'=>$previewDocument,'application'=>$previewApplicationId])) ?>"><?= e($reviewText('Original herunterladen')) ?></a></div>
  <?php if(count($previewDocs)>1): ?><nav class="actions" aria-label="<?= e($reviewText('Antragsunterlagen')) ?>"><?php foreach($previewDocs as $doc): ?><a class="btn btn-outline theme-secondary button secondary" <?= $doc['id']===$previewDocument?'aria-current="page"':'' ?> href="<?= e(url(['view'=>'portal','tab'=>input($_GET,'tab','admissions'),'application'=>$previewApplicationId,'pdf'=>$doc['id']]+scalarFields($_GET,['status','applications_q','applications_page']))) ?>"><?= e($doc['title']) ?></a><?php endforeach ?></nav><?php endif ?>
  <?php if($previewFile['mime']==='application/pdf'): ?>
  <?php require __DIR__.'/../../Documents/pdf-viewer.php'; ?>
  <?php elseif(in_array($previewFile['mime'],['image/png','image/jpeg','image/webp'],true)): ?><img class="application-scan" src="<?= e($previewUrl) ?>" alt="<?= e($reviewText('Eingereichter Antrags-Scan')) ?>">
  <?php else: ?><p><?= e($reviewText('Für diese Datei steht der Original-Download zur Verfügung.')) ?></p><?php endif ?>
  <p class="muted"><?= e($reviewText('Das eingereichte Original bleibt erhalten. Stimmen und Begründungen werden separat protokolliert.')) ?></p>
</section>
