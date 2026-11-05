<?php declare(strict_types=1);
use Kulturhus\Verein\ActivityReportReview;
use Kulturhus\Verein\Web\ActivityReportPageState as ListingPage;
$listService=new ActivityReportReview($db,$actor,null,'assembly');$ids=[];for($year=2000;$year<2018;$year++)for($span=0;$span<3;$span++)$ids[]=$listService->prepare($year,$year+$span,'Own original independent period '.$year.'-'.$span);$beforeListReads=ownOriginalDatabaseHash($db,$tables);$listed=$listService->listing();
$check(count($listed)===57&&in_array($ids[0],array_column($listed,'id'),true),'complete report overview retains every one of fifty-seven original periods including the oldest beyond fifty');
$listQuery=['view'=>'documents','tab'=>'activity-reports','report_kind'=>'assembly'];$last=ListingPage::read($listService,$listQuery+['page'=>'5'],[],200);$check($last['total']===57&&$last['pages']===5&&count($last['rows'])===9&&in_array($ids[0],array_column($last['rows'],'id'),true),'last explicitly requested page exposes all nine remaining original periods including the oldest report');
$searched=ListingPage::read($listService,$listQuery+['q'=>'2000'],[],200);$check($searched['total']===3&&count($searched['rows'])===3,'period search covers every stored report rather than only the first fifty');
$check(ownOriginalDatabaseHash($db,$tables)===$beforeListReads,'complete report listing search and original pagination change no source text PDF identity audit or unrelated whole original row');
