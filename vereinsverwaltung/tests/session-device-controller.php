<?php declare(strict_types=1);
namespace ProcessWire { final class Page {public const statusLocked=4;} }
namespace {
require __DIR__.'/../../processwire/site/src/PortalSessionDuration.php';
$checks=0;
$user=new class {public bool $active=true;public bool $unpublished=false;public bool $trashed=false;public bool $locked=false;public function isLoggedin(){return $this->active;}public function isGuest(){return !$this->active;}public function isUnpublished(){return $this->unpublished;}public function isTrash(){return $this->trashed;}public function hasStatus($s){return $s===\ProcessWire\Page::statusLocked&&$this->locked;}};
$csrf=new class {public bool $valid=true;public int $calls=0;public function validate(){++$this->calls;if(!$this->valid)throw new \DomainException('Invalid synthetic CSRF');}};
$wire=(object)['user'=>$user,'config'=>(object)['kulturhusVereinPortalEnabled'=>true],'session'=>(object)['CSRF'=>$csrf]];
$denied=static function(string $label)use($wire,&$checks){try{\Kulturhus\PortalSessionDuration::save($wire);}catch(\DomainException){++$checks;echo "PASS $label\n";return;}throw new \RuntimeException($label);};
$_POST=['mode'=>'month'];$_SERVER['REQUEST_METHOD']='GET';$denied('GET cannot mutate device duration');
$_SERVER['REQUEST_METHOD']='POST';$user->active=false;$denied('guest cannot change duration');$user->active=true;
foreach(['unpublished','trashed','locked'] as $state){$user->$state=true;$before=$csrf->calls;$denied($state.' account cannot extend its session');if($csrf->calls!==$before)throw new \RuntimeException('inactive account reached session mutation prerequisites');++$checks;echo "PASS $state rejected before CSRF or policy mutation\n";$user->$state=false;}
$wire->config->kulturhusVereinPortalEnabled=false;$denied('disabled portal cannot change duration');$wire->config->kulturhusVereinPortalEnabled=true;
$csrf->valid=false;$denied('invalid native CSRF prevents policy call');$csrf->valid=true;
foreach(['', 'forever', '0', ['logout']] as $mode){$_POST=['mode'=>$mode];$denied('invalid duration rejected before policy mutation');}
echo "$checks device session controller boundary checks passed\n";
}
