<?php declare(strict_types=1);
$wfFileMessages=$documentActionMessages??[];
$wfFileText=static fn(string $source):string=>\Kulturhus\Verein\Documents\HseInterfaceText::text($source,$wfFileMessages);
$wfFileFormat=static fn(string $source,array $values):string=>\Kulturhus\Verein\Documents\HseInterfaceText::format($source,$values,$wfFileMessages);
$wfFileLocale=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->locale():'de';
$wfFiles=$workflowOwnFilePage??null;$wfFileError=$workflowOwnFileError??'';
$wfFileContext=isset($pdQuery)&&is_array($pdQuery)?$pdQuery:['view'=>'portal','tab'=>'documents'];
$wfFilePager=static function()use($wfFiles,$wfFileText,$wfFileContext):void{echo \Kulturhus\Verein\Documents\WorkflowPager::render($wfFiles,'wf_signed_files_page',$wfFileContext,$wfFileText);};
?>
<link rel="stylesheet" href="document-workflows.css?v=2" data-dwf-asset>
<section class="card dwf-workspace" id="meine-beurteilungen" data-dwf-own-files><h2><?= e($wfFileText('Meine Gefährdungsbeurteilungen')) ?></h2><p><a class="btn btn-outline theme-secondary button secondary" href="?view=portal&amp;tab=signatures"><?= e($wfFileText('Offene Unterschriften')) ?></a></p>
<?php if($wfFileError!==''): ?><p class="dwf-alert" role="alert"><?= e($wfFileText($wfFileError)) ?> <a href="?<?= e(http_build_query(array_replace($wfFileContext,['wf_signed_files_page'=>1]),'','&',PHP_QUERY_RFC3986)) ?>"><?= e($wfFileText('Auswahl zurücksetzen')) ?></a></p><?php endif ?>
<?php $wfFilePager();if($wfFiles!==null&&!$wfFiles['rows']): ?><p><?= e($wfFileText('Noch keine unterschriebenen Beurteilungen.')) ?></p><?php endif ?>
<?php foreach($wfFiles['rows']??[]as$file): ?><article class="portal-document-item"><h3><?= e($file['title']) ?></h3><p><?= e($wfFileFormat('Fassung {revision}',['revision'=>$file['revision']])) ?> · <?= e($wfFileFormat('Unterschrieben am {date}',['date'=>\Kulturhus\Verein\Documents\HseInterfaceText::timestamp($file['signed_at'],$wfFileLocale)])) ?></p>
<?php if($file['archived']): ?><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['wf_archived_pdf'=>$file['id']])) ?>"><?= e($wfFileText('PDF herunterladen')) ?></a><?php else: ?><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'portal','tab'=>'signatures','wf_sign'=>$file['id']])) ?>"><?= e($wfFileText('PDF ablegen')) ?></a><?php endif ?></article><?php endforeach; $wfFilePager(); ?></section>
