<?php declare(strict_types=1);
require __DIR__.'/../src/Web/View.php';
function renderAudience(bool $restore,array $post):string{
    $_POST=$post;$restoreNative=$restore;
    $item=['id'=>1,'status'=>'draft','subject'=>'Synthetic','body'=>'<p>Test</p>','audience'=>'all','version'=>'v1','recipient_count'=>0,'sent_count'=>0,'failed_count'=>0];
    $native=['default_subject'=>'','default_body'=>'','events'=>[],'mail_allowed'=>false];$statusLabels=['draft'=>'Entwurf'];
    $start=static function(...$args):void{};$field=static function(...$args):void{};$button=static function(...$args):void{};$simple=static function(...$args):void{};
    ob_start();require __DIR__.'/../views/Website/website-newsletter-editor.php';return ob_get_clean();
}
foreach([[false,[],'all'],[true,['action'=>'website-native','fields'=>['audience'=>'events']],'events'],[true,['action'=>'website-native','fields'=>['audience'=>'news']],'news'],[false,['fields'=>['audience'=>'events']],'all']] as [$restore,$post,$expected]){
    $html=renderAudience($restore,$post);
    if(!str_contains($html,'value="'.$expected.'" selected'))throw new RuntimeException('Audience not restored: '.$expected);
}
echo "4 newsletter audience state checks passed; no delivery\n";
