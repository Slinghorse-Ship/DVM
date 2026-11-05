<?php declare(strict_types=1);
$directoryMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$directoryText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\BankDialogMessages::text($source,$directoryMessages,$values);
$directoryPosted=str_starts_with(input($_POST,'action'),'bank-directory-');$directoryOriginal=\Kulturhus\Verein\Web\BankDirectoryForm::original($_POST);$directoryMonth=$directoryPosted?(is_string($_POST['directory_month']??null)?$_POST['directory_month']:''):substr(\Kulturhus\Verein\Finance::today(),0,7);$directoryUnsupported=false;
if($directoryPosted){try{\Kulturhus\Verein\Web\BankDirectoryForm::assertInput($_POST,input($_POST,'action'));}catch(DomainException){$directoryUnsupported=true;}}
$directoryService=new \Kulturhus\Verein\FintsDirectory($db,(string)$_SESSION['actor']);
if(!$directoryService->canManage()){http_response_code(403);throw new DomainException($directoryText('Keine Berechtigung zur Aktualisierung der Bankenliste.'));}
$directoryState=$directoryService->state();$directoryPreview=null;$directoryId=input($_GET,'directory_preview');
if($directoryId!=='')try{$directoryPreview=$directoryService->prepared($directoryId);}catch(DomainException $e){$directoryPreviewError=$e->getMessage();}
?>
<link rel="stylesheet" href="simple-settings.css?v=1">
<div class="page-heading fints-directory-heading"><div><h1><?= e($directoryText('FinTS-Bankenliste')) ?></h1></div><a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'finance','tab'=>'bank'])) ?>"><?= e($directoryText('Zur Bankverwaltung')) ?></a></div>
<div class="content settings-content simple-settings fints-directory-workspace" data-fints-directory>
 <?php if($directoryPosted): ?><details class="fints-directory-original" open><summary><?= e($directoryText('Bankenlisten-Eingaben prüfen')) ?></summary><textarea readonly data-fints-directory-original aria-label="<?= e($directoryText('Bankenlisten-Eingaben prüfen')) ?>"><?= e(json_encode($directoryOriginal,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)) ?></textarea></details><?php if($directoryUnsupported): ?><p class="error" role="alert"><?= e($directoryText('Ursprüngliche Bankenlisten-Eingaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.')) ?></p><p><a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'finance','tab'=>'bank','directory'=>'1'])) ?>"><?= e($directoryText('Bankenlisten-Formular neu öffnen')) ?></a></p><?php endif ?><?php endif ?>
 <section class="card" aria-labelledby="fints-directory-current"><h2 id="fints-directory-current"><?= e($directoryText('Aktueller Stand')) ?></h2>
  <p><?= e($directoryText('{count} Bankzugänge',['count'=>(int)$directoryState['banks']])) ?><?= $directoryState['month']!==''?' · '.e($directoryState['month']):'' ?></p>
  <?php if($directoryState['source_name']!==''): ?><p class="muted"><?= e($directoryState['source_name']) ?></p><?php endif ?>
 </section>
 <?php if(isset($directoryPreviewError)): ?><p class="error" role="alert"><?= e($directoryText($directoryPreviewError)) ?></p><?php endif ?>
 <?php if($directoryPreview): $ds=$directoryPreview['statistics']; ?>
 <section class="card" aria-labelledby="fints-directory-preview"><h2 id="fints-directory-preview"><?= e($directoryText('Bankenliste übernehmen')) ?></h2>
  <p><strong><?= e($directoryText('{count} Bankzugänge',['count'=>(int)$ds['banks']])) ?> · <?= e($directoryPreview['month']) ?></strong></p>
  <p><?= e($directoryText('{added} neue · {removed} entfallene Zugänge',['added'=>(int)$directoryPreview['added'],'removed'=>(int)$directoryPreview['removed']])) ?></p>
  <p><?= e($directoryPreview['source_name']) ?></p>
  <details class="settings-panel"><summary><?= e($directoryText('Prüfbericht')) ?></summary><dl class="data-list"><dt><?= e($directoryText('Dateizeilen')) ?></dt><dd><?= (int)$ds['source_rows'] ?></dd><dt><?= e($directoryText('Zusammengeführte Bankstellen')) ?></dt><dd><?= (int)$ds['duplicates'] ?></dd><dt><?= e($directoryText('Ohne PIN/TAN-Zugang')) ?></dt><dd><?= (int)$ds['without_pin_tan'] ?></dd><dt><?= e($directoryText('Nicht unterstützte Version')) ?></dt><dd><?= (int)$ds['unsupported_version'] ?></dd><dt><?= e($directoryText('Ohne verwendbaren BIC')) ?></dt><dd><?= (int)$ds['missing_bic'] ?></dd></dl></details>
  <form method="post" data-fints-directory-apply><fieldset class="fints-directory-controls" <?= $directoryUnsupported?'disabled':'' ?>><?= csrf().hidden('action','bank-directory-apply').hidden('directory_id',$directoryPreview['id']) ?>
   <label class="setting-check"><input class="check" type="checkbox" name="directory_confirm" value="1" required> <?= e($directoryText('Geprüfte Bankenliste übernehmen')) ?></label>
   <div class="settings-actions"><button class="btn btn-solid theme-primary" type="submit"><?= e($directoryText('Übernehmen')) ?></button><a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'finance','tab'=>'bank','directory'=>1])) ?>"><?= e($directoryText('Verwerfen')) ?></a></div>
  </fieldset></form>
 </section>
 <?php endif ?>
 <section class="card" aria-labelledby="fints-directory-upload"><h2 id="fints-directory-upload"><?= e($directoryText('Monatliches Update')) ?></h2>
  <?php if($directoryPosted&&input($_POST,'action')==='bank-directory-preview'): ?><p role="status"><?= e($directoryText('Monat und Datei prüfen. Eine Datei muss nach einer Ablehnung erneut ausgewählt werden.')) ?></p><?php endif ?>
  <form method="post" enctype="multipart/form-data" class="settings-form" data-fints-directory-upload><fieldset class="fints-directory-controls" <?= $directoryUnsupported?'disabled':'' ?>><?= csrf().hidden('action','bank-directory-preview') ?>
   <label><?= e($directoryText('Monat')) ?><input class="form-control" type="<?= e(\Kulturhus\Verein\Web\BankDirectoryForm::monthType($directoryMonth)) ?>" name="directory_month" value="<?= e($directoryMonth) ?>" required></label>
   <label><?= e($directoryText('Bankenliste aus der E-Mail')) ?><input class="form-control" type="file" name="directory_file" accept=".zip,.csv,application/zip,text/csv" required><small><?= e($directoryText('ZIP bis 16 MiB · CSV bis 8 MiB')) ?></small></label>
   <button class="btn btn-solid theme-primary" type="submit"><?= e($directoryText('Datei prüfen')) ?></button>
  </fieldset></form>
 </section>
</div>
