<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
$config=\Kulturhus\Native\TestSupport::config();$input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||($config['owner']??'')!=='isolated-native-ci'||($config['label']??'')!=='native-numbering-interface'||!is_array($input)||array_keys($input)!==['object','date']||!is_string($input['object'])||!preg_match('/\Aown-numbering-browser-[0-7]\z/D',$input['object'])||$input['date']!=='2024-02-29')throw new RuntimeException('Owned independent number allocation required.');
$db=\Kulturhus\Native\TestSupport::db();$db->beginTransaction();
try{$number=\Kulturhus\Verein\Numbering::allocate($db,'own-list-club','invoice',$input['date'],$input['object']);$db->commit();echo json_encode(['object'=>$input['object'],'number'=>$number,'next'=>(int)$db->query("SELECT next_number FROM number_counters WHERE club_id='own-list-club'AND kind='invoice'")->fetchColumn()],JSON_THROW_ON_ERROR)."\n";}
catch(Throwable $failure){if($db->inTransaction())$db->rollBack();throw$failure;}
