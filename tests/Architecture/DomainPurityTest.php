<?php

declare(strict_types=1);

arch('the domain layer depends on no framework')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate',
        'Laravel',
        'Symfony',
        'Google',
        'Microsoft',
    ]);

arch('the domain layer does not reach for the database')
    ->expect('App\Domain')
    ->not->toUse(['PDO', 'Illuminate\Database']);

arch('controllers hold no business logic and no Eloquent')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Database\Eloquent');

arch('value objects and domain services are final')
    ->expect('App\Domain')
    ->classes()
    ->toBeFinal()
    ->ignoring('App\Domain\Shared\Clock');

arch('strict types everywhere')
    ->expect(['App', 'Database', 'Tests'])
    ->toUseStrictTypes();
