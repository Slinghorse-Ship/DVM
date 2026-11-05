<?php declare(strict_types=1);
if(!$finance->canWrite())throw new DomainException('Finanzschreibrecht erforderlich.');
$prepareType=input($_POST,'source_type');$prepareId=input($_POST,'source_id');if(!in_array($prepareType,['entry','goods'],true))throw new DomainException('Spendenart prüfen.');
\Kulturhus\Verein\DonationPreparation::status($db,(string)$_SESSION['actor'],$prepareType,$prepareId);
\Kulturhus\Verein\DonationPreparation::afterSource($db,(string)$_SESSION['actor'],$prepareType,$prepareId);
$_SESSION['notice']='Vorbereitung geprüft. Der gespeicherte Status steht beim Spenderkontakt; es wurde keine Bescheinigung ausgestellt oder versendet.';
redirectTo($prepareType==='goods'?['view'=>'donations','tab'=>'goods','goods'=>$prepareId]+scalarFields($_GET,['goods_page','goods_q','goods_origin','goods_evidence','goods_sort','goods_columns']):['view'=>'finance','tab'=>'receipts','entry'=>$prepareId]);
