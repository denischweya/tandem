<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A probe route: no Wave 1a endpoint requires verification - the Plan endpoints in Wave 2
    // are the ones that will. This task's job is to make the gate exist and prove it works, so
    // that applying it later is one middleware entry and not a design question.
    Route::middleware(['api', 'auth:sanctum', 'verified'])
        ->get('/__verified-probe', fn (Request $request): array => ['ok' => true]);
});

it('lets a verified user through', function (): void {
    $user = User::factory()->hasProfile()->create();
    $token = $user->createToken('api', ['*'], now()->addMinutes(15));

    withToken($token->plainTextToken)->getJson('/__verified-probe')->assertOk();
});

it('blocks an unverified user', function (): void {
    $user = User::factory()->unverified()->hasProfile()->create();
    $token = $user->createToken('api', ['*'], now()->addMinutes(15));

    withToken($token->plainTextToken)->getJson('/__verified-probe')->assertForbidden();
});
