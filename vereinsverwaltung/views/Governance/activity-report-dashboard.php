<?php declare(strict_types=1);
(static function()use($db):void{
 $actor=(string)$_SESSION['actor'];$service=new \Kulturhus\Verein\ActivityReportReview($db,$actor);if(!$service->isChair())return;$pending=[];
 foreach(\Kulturhus\Verein\ActivityReportReview::KINDS as $kind=>$label)foreach((new \Kulturhus\Verein\ActivityReportReview($db,$actor,null,$kind))->listing() as $r)if($r['state']==='review')$pending[]=[$kind,$label,$r];
 if(!$pending)return;
 ?><section class="card"><h2><?=e(\Kulturhus\Native\Language\ActivityReportMessages::text('Tätigkeitsberichte prüfen',$managerLanguageMessages??[]))?></h2><ul><?php foreach($pending as [$kind,$label,$r]): ?><li><a href="<?= e(url(['view'=>'documents','tab'=>'activity-reports','report_kind'=>$kind,'report_id'=>$r['id']])) ?>"><?= e($label) ?> · <?= (int)$r['from_year'] ?> - <?= (int)$r['to_year'] ?></a></li><?php endforeach ?></ul></section><?php
})();
