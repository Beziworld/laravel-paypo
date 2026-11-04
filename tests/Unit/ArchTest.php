<?php

declare(strict_types=1);

arch('every source file declares strict types')
    ->expect('Beziworld\PayPo')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('data objects are immutable')
    ->expect('Beziworld\PayPo\Data')
    ->toBeReadonly();

arch('exceptions share the package base exception')
    ->expect('Beziworld\PayPo\Exceptions')
    ->toExtend('Beziworld\PayPo\Exceptions\PayPoException')
    ->ignoring('Beziworld\PayPo\Exceptions\PayPoException');
