<?php declare(strict_types=1);
$wfIntentValues=is_array($_POST['wf_values']??null)?$_POST['wf_values']:[];
$wfIntentScalar=static fn(mixed $value):string=>is_string($value)?$value:'';
$wfIntentRisk=static function(mixed $value)use($wfEsc,$wfI,$wfF):void{
 $risk=is_array($value)?$value:[];$likelihood=is_scalar($risk['likelihood']??null)?(string)$risk['likelihood']:'';$severity=is_scalar($risk['severity']??null)?(string)$risk['severity']:'';
 echo '<dl class="dwf-intention-risk"><dt>'.$wfEsc($wfI('Wahrscheinlichkeit')).'</dt><dd>'.$wfEsc($likelihood!==''?$likelihood:'–').'</dd><dt>'.$wfEsc($wfI('Schadensschwere')).'</dt><dd>'.$wfEsc($severity!==''?$severity:'–').'</dd><dt>'.$wfEsc($wfI('Risikowert')).'</dt><dd>';
 echo $wfEsc(preg_match('/\A[1-5]\z/D',$likelihood)&&preg_match('/\A[1-5]\z/D',$severity)?(string)((int)$likelihood*(int)$severity):$wfI('Noch nicht vollständig gewählt'));echo '</dd></dl>';
};
?>
<section class="dwf-card dwf-document-intention" data-dwf-document-intention>
<h3><?= $wfEsc($wfIntentHeading) ?></h3><p><?= $wfEsc($wfIntentNote) ?></p><dl><dt><?= $wfEsc($wfI('Geprüfte Version')) ?></dt><dd><?= $wfEsc($wfText($_POST,'wf_version')) ?></dd><dt><?= $wfEsc($wfI('Dokumenttitel')) ?></dt><dd><?= $wfEsc($wfText($_POST,'wf_title')) ?></dd><dt><?= $wfEsc($wfI('Arbeitsordner')) ?></dt><dd><?php $wfIntentFolder=$wfText($_POST,'wf_folder');echo $wfEsc($wfIntentFolder===''?$wfI('Ohne Ordner / oberste Ebene'):($wfFolderTree->get($wfIntentFolder)['name']??$wfIntentFolder));?></dd></dl>
<?php foreach($wfDoc['fields']as$wfIntentField):$wfIntentKey=$wfIntentField['key'];$wfIntentValue=$wfIntentValues[$wfIntentKey]??null;?><section class="dwf-intention-field"><h4><?= $wfEsc($wfIntentField['label']) ?></h4>
<?php if($wfIntentField['type']==='risk_register'):?><ol><?php foreach(is_array($wfIntentValue)?$wfIntentValue:[]as$wfIntentRow):if(!is_array($wfIntentRow))continue;?><li><dl><?php foreach(\Kulturhus\Verein\DocumentWorkflow\RiskRegister::COLUMN_LABELS as$wfIntentColumn=>$wfIntentColumnLabel):?><dt><?= $wfEsc($wfI($wfIntentColumnLabel)) ?></dt><dd><?php if(in_array($wfIntentColumn,['initial','residual'],true))$wfIntentRisk($wfIntentRow[$wfIntentColumn]??[]);else echo $wfEsc($wfIntentScalar($wfIntentRow[$wfIntentColumn]??''));?></dd><?php endforeach;foreach(\Kulturhus\Verein\DocumentWorkflow\RiskRegister::DATE_LABELS as$wfIntentDate=>$wfIntentDateLabel):?><dt><?= $wfEsc($wfI($wfIntentDateLabel)) ?></dt><dd><?= $wfEsc($wfIntentScalar($wfIntentRow[$wfIntentDate]??'')) ?></dd><?php endforeach ?></dl></li><?php endforeach ?></ol>
<?php elseif($wfIntentField['type']==='risk_matrix'):$wfIntentRisk($wfIntentValue);
elseif($wfIntentField['type']==='checkbox'):?><p><?= $wfEsc($wfI($wfIntentValue==='1'?'Bestätigt':'Nicht bestätigt')) ?></p>
<?php else:if($wfIntentField['type']==='select'&&$wfText($_POST['wf_option_refs']??[],$wfIntentKey)==='1'&&is_string($wfIntentValue)&&preg_match('/\Ao:(0|[1-9][0-9]?)\z/D',$wfIntentValue,$wfIntentMatch))$wfIntentValue=$wfIntentField['options'][(int)$wfIntentMatch[1]]??$wfIntentValue;?><p class="dwf-intention-text"><?= $wfEsc($wfIntentScalar($wfIntentValue)) ?></p><?php endif ?></section><?php endforeach ?>
</section>
