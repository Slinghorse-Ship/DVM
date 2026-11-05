<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** Personal labels for explicit private content controls; no stored-content rewriting. */
trait ContentInterfaceText
{
 private ?array $contentInterfaceMessages=null;
 protected function contentText(string $source):string
 {
  $this->contentInterfaceMessages??=(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->contentMessages();
  return \Kulturhus\Native\Language\ContentMessages::text($source,$this->contentInterfaceMessages);
 }
 protected function contentFormat(string $source,array $values):string
 {
  $arguments=[];foreach($values as$key=>$value)$arguments['{'.$key.'}']=(string)$value;
  return strtr($this->contentText($source),$arguments);
 }
 protected function contentPosted(string $operation,string $id):bool
 {
  return ($_SERVER['REQUEST_METHOD']??'')==='POST'&&http_response_code()>=400
   &&($_POST['operation']??null)===$operation&&($_POST['id']??'')===$id
   &&isset($this->csrf)&&$this->csrf!==''&&is_string($_POST['csrf']??null)&&hash_equals($this->csrf,$_POST['csrf']);
 }
 protected function contentOptions(array $values):array{return array_map($this->contentText(...),$values);}
}
