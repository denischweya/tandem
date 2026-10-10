<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sanctum
|--------------------------------------------------------------------------
|
| Only the keys this application actually decides. ServiceProvider::mergeConfigFrom() does
| array_merge(vendor, app) on the `sanctum` key, so everything omitted here keeps Sanctum's
| own default - which is why this is three lines rather than a published copy of the vendor
| file. A full copy would also drag SANCTUM_STATEFUL_DOMAINS and SANCTUM_TOKEN_PREFIX into
| this repo's config surface, and spec §5's byte gate is about keeping env() out of anything
| that can reach openapi.json.
|
*/

return [

    /*
    | NO GUARDS. Sanctum's own default is ['web'], and Laravel\Sanctum\Guard::__invoke()
    | iterates this list BEFORE it ever looks at the bearer token - returning the first user
    | any listed guard resolves, wrapped in a TransientToken.
    |
    | Spec §9.2 and ADR 0004 say the API speaks only bearer tokens and that no second
    | authentication path is added for the browser (the web client gets its cookies from its
    | own BFF, which calls this API with a bearer token like any other client). With ['web'] in
    | force that was a claim about intent, not about behaviour: the session guard really was
    | consulted on every auth:sanctum request. Measured, not reasoned about - acting as a user
    | on the `web` guard and then calling GET /api/v1/me with NO Authorization header at all
    | returned 200. With this list empty the same request returns 401.
    |
    | It was inert in production only by accident, and by an accident nothing asserted: the
    | `api` middleware group has no StartSession, so nothing normally populates the session
    | guard for a request under api/*. That is one middleware edit away from being false, and
    | it is not the invariant the spec states. An empty list makes the bearer token the only
    | credential by construction, which is what §9.2 actually claims.
    |
    | tests/Feature/Auth/AccessTokenTest.php's "refuses a session-authenticated caller with no
    | bearer token" is the guard on this value.
    */
    'guard' => [],

];
