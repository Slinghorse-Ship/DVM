<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1')exit(1);
$cfg=TestSupport::config();if(($cfg['label']??null)!=='native-video-removal'||($cfg['owner']??null)!=='isolated-native-ci'||!function_exists('posix_kill'))exit(1);
$input=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);$db=TestSupport::db();
$plan=Db::run($db,"SELECT r.* FROM native_video_removals r JOIN native_video_removal_states s ON s.club_id=r.club_id AND s.source_id=r.source_id AND s.operation=r.operation WHERE r.club_id='synthetic-a' AND r.source_id=? AND r.operation=? AND s.state='removing'",[$input['source'],$input['operation']])->fetch();
if(!$plan)exit(1);$plan['bytes']=(int)$plan['bytes'];$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
(new Kulturhus\Native\Communication\VideoQuarantine($root.'/media'))->move($plan);
// Terminate only this explicitly owned helper after rename and before a terminal database event.
posix_kill(getmypid(),9);exit(1);
