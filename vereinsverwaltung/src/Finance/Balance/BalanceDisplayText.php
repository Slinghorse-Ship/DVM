<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Translate typed fixed presentation labels; original financial names stay literal. */
final class BalanceDisplayText
{
    public function __construct(private array $messages=[]){}
    public function text(string $source):string
    {
        return $this->messages===[]?$source:\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$this->messages);
    }
    public function account(array $row):string
    {
        $id=$row['id']??'';$default=BalanceCalculation::GROUPS[$id]??null;
        return $default!==null&&($row['group']??null)===$id&&$row['name']===$default[0]?$this->text($row['name']):$row['name'];
    }
}
