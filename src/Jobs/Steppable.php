<?php
namespace Dosya\Backup\Jobs;

use Dosya\Backup\Support\Budget;

interface Steppable
{
    /** @return array{state:string,cursor:array,error?:?string,result?:?array} */
    public function step(string $state, array $cursor, Budget $b): array;
}
