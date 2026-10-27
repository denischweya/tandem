<?php

declare(strict_types=1);

use function Pest\Laravel\get;

// Laravel's generated welcome page served app()->version() and the literal framework
// version number, unauthenticated, even with APP_DEBUG=false - an information disclosure
// (CWE-200) on an API host that spec §8 says answers only /api/v1/*, /health and /ready.
// Asserting the view file itself is gone, not only that the route 404s, is what survives a
// future `composer create-project` re-scaffold accidentally regenerating both.
it('does not serve the welcome page', function (): void {
    get('/')->assertNotFound();

    expect(file_exists(base_path('resources/views/welcome.blade.php')))->toBeFalse();
});
