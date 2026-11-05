<?php declare(strict_types=1);require __DIR__.'/donation-receipts.php';
$noticeStart=$checks;$currentVersion=(int)$financeSettings->section('tax')['version'];
$tax['notice_type']='corporate';$financeSettings->save('tax',$tax,$currentVersion++);
$corporate=$receipts->build(array_replace($forms->defaults('money',$entry),['waiver'=>'no','receipt_reviewed'=>'1']));check($corporate['donation_form']['notice_type']==='corporate','current corporate notice follows exemption branch');
$tax['exemption_date']='2018-01-01';$financeSettings->save('tax',$tax,$currentVersion++);denied(fn()=>$receipts->build(array_replace($forms->defaults('money',$entry),['waiver'=>'no','receipt_reviewed'=>'1'])),'corporate notice older than five years denied');
$tax['notice_type']='assessment';$tax['assessment_date']=date('Y').'-01-01';$financeSettings->save('tax',$tax,$currentVersion++);denied(fn()=>$receipts->build(array_replace($forms->defaults('money',$entry),['waiver'=>'no','receipt_reviewed'=>'1'])),'60a requires explicit no-prior-notice review');
$tax['assessment_no_prior_notice']=true;$financeSettings->save('tax',$tax,$currentVersion++);check($receipts->build(array_replace($forms->defaults('money',$entry),['waiver'=>'no','receipt_reviewed'=>'1']))['donation_form']['notice_type']==='assessment','60a current and explicitly reviewed branch');
$tax['assessment_date']=((int)date('Y')-4).'-01-01';$financeSettings->save('tax',$tax,$currentVersion++);denied(fn()=>$receipts->build(array_replace($forms->defaults('money',$entry),['waiver'=>'no','receipt_reviewed'=>'1'])),'60a older than three calendar years denied');
echo ($checks-$noticeStart)." tax notice branch checks passed\n";
