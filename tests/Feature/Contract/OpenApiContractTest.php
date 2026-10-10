<?php

declare(strict_types=1);

use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Requests\Api\V1\Auth\VerifyEmailRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;

/**
 * Behavioural assertions about the committed openapi.json, as distinct from the byte-equality
 * gate in bin/check-openapi.sh.
 *
 * The byte gate protects the document's STABILITY, not its TRUTH. It asks only whether the
 * committed bytes match a fresh generation, so any defect the generator reproduces passes it
 * for ever - and worse, the standard response to "openapi.json is out of date" is
 * `make openapi && git add openapi.json`, which commits whatever the generator now produces.
 * One line vanishing from a 225-line generated file is easy to wave through.
 *
 * Both facts below shipped broken-but-green exactly that way, which is why they are asserted
 * behaviourally and derived from the rules rather than restated as literals: a test that
 * hardcodes what the document should say is just a second copy of the document.
 */

/** Decoded as mixed deliberately: every read below narrows what it actually uses. */
function openApiDocument(): mixed
{
    return json_decode((string) file_get_contents(base_path('openapi.json')), true);
}

/**
 * Every route the document covers, as [method, path-as-written-in-the-document, route].
 *
 * Read off Laravel's route table rather than off the document, so an endpoint added without
 * regenerating fails the assertions below instead of being skipped by them. The `api/v1`
 * prefix is stripped because `servers` already carries it, which is why the document's keys
 * are `/auth/login` and not `/api/v1/auth/login`.
 *
 * @return list<array{string, string, RoutingRoute}>
 */
function documentedRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $routes[] = [strtolower($method), '/'.substr($uri, strlen('api/v1/')), $route];
        }
    }

    return $routes;
}

it('publishes every verify-email parameter in the query string and no request body', function (): void {
    $operation = data_get(openApiDocument(), 'paths./auth/verify-email.post');

    // Derived from rules(), not restated: a parameter added there without @query fails here
    // rather than being published as an unsatisfiable body field.
    $expected = array_keys((new VerifyEmailRequest)->rules());

    $actual = [];

    foreach (Arr::wrap(data_get($operation, 'parameters')) as $parameter) {
        $name = data_get($parameter, 'name');

        if (is_string($name)) {
            $actual[$name] = data_get($parameter, 'in');
        }
    }

    expect(array_keys($actual))->toEqualCanonicalizing($expected);

    foreach ($expected as $name) {
        expect($actual[$name] ?? null)->toBe(
            'query',
            "openapi.json must document [{$name}] as a query parameter. Request::hasValidSignature() "
            .'goes to UrlGenerator::hasCorrectSignature(), which reconstructs the signed URL from '
            .'$request->query() alone, so a client following a documented request body sends a '
            .'request whose HMAC can never match and is refused with 403 every time.',
        );
    }

    expect(data_get($operation, 'requestBody'))->toBeNull(
        'verify-email must publish no request body at all: every parameter it accepts lives in '
        .'the query string, and a documented body describes a request that cannot authorize.',
    );
});

it('publishes the password minimum length that the rule actually enforces', function (): void {
    $minimum = null;

    foreach (Arr::wrap(data_get((new RegisterRequest)->rules(), 'password')) as $rule) {
        if ($rule instanceof Password) {
            $minimum = $rule->appliedRules()['min'] ?? null;
        }
    }

    expect($minimum)->toBeInt(
        'This test derives the expected minLength from the Password rule in '
        .'RegisterRequest::rules(), so the two cannot disagree. No such rule was found.',
    );

    expect(data_get(openApiDocument(), 'components.schemas.RegisterRequest.properties.password.minLength'))
        ->toBe(
            $minimum,
            'openapi.json must publish the minimum length the Password rule enforces. Scramble '
            .'understands the string rule min:12 but ships nothing that reads a Password rule '
            .'object, so this is emitted by App\Infrastructure\OpenApi\PasswordRuleTransformer, '
            .'which stops working on a rename of getRule(), is() or appliedRules() upstream - '
            .'each of which leaves a valid document that simply omits the constraint, which the '
            .'byte gate cannot see. (This message used to add that the transformer depends on '
            .'the string rule preceding Password::min() in the rules array. It does not: '
            .'RuleSetToSchemaTransformer sorts every rule OBJECT last regardless of declared '
            .'order, and declaring Password::min(12) first produces a byte-identical document. '
            .'See the transformer.)',
        );
});

it('declares bearer authentication, and marks exactly the auth:sanctum routes as requiring it', function (): void {
    // The document shipped with 'security_strategy' => null, so it never said the API is
    // authenticated at all: no securitySchemes, and no `security` on any operation, while four
    // operations require auth:sanctum. A generated client had no Authorization header and no
    // way to describe one.
    //
    // Derived from the route table, not from a list restated here: MiddlewareAuthSecurityStrategy
    // reads the route's own middleware, so this asserts the correspondence rather than a
    // snapshot. Adding auth:sanctum to a route without regenerating fails here.
    $document = openApiDocument();

    expect(data_get($document, 'components.securitySchemes.http.type'))->toBe('http');
    expect(data_get($document, 'components.securitySchemes.http.scheme'))->toBe('bearer');
    // Applied globally, with public operations opting out - which is what makes the
    // per-operation `security: []` below meaningful rather than decorative.
    expect(data_get($document, 'security'))->toBe([['http' => []]]);

    foreach (documentedRoutes() as [$method, $path, $route]) {
        $requiresAuth = collect($route->gatherMiddleware())
            ->contains(fn (mixed $middleware): bool => is_string($middleware)
                && ($middleware === 'auth' || str_starts_with($middleware, 'auth:')));

        $operationSecurity = data_get($document, "paths.{$path}.{$method}.security");

        if ($requiresAuth) {
            // Inherits the document-level requirement: no per-operation override.
            expect($operationSecurity)->toBeNull("{$method} {$path} requires auth and must not opt out of security");
        } else {
            expect($operationSecurity)->toBe([], "{$method} {$path} is public and must say so with security: []");
        }
    }
});

it('declares every error status the application itself raises', function (): void {
    // Scramble infers a 422 from a FormRequest and a 401 from auth middleware, and nothing
    // else. An exception-render closure in bootstrap/app.php and a throttle applied from inside
    // a use case are both invisible to it, so these five were asserted by the feature suite and
    // absent from the contract - including /auth/refresh's 401, which is the one spec §17
    // criterion 11's "refresh on 401" is built on.
    //
    // A literal table, deliberately, and the one place in this file that is: the whole point is
    // that these statuses cannot be derived from the source Scramble reads. Deriving them from
    // the thing that produces them would mean reimplementing the renderer. Each row is asserted
    // by a named feature test, listed beside it.
    $expected = [
        // LoginTest: "rejects a wrong password with 401 and no token"
        ['post', '/auth/login', '401'],
        // LoginTest: "rate limits sign-in to ten failures per fifteen minutes per IP"
        ['post', '/auth/login', '429'],
        // RefreshTest: "rejects an unknown refresh token"
        ['post', '/auth/refresh', '401'],
        // CredentialLifetimeTest: "holds the registration throttle for the whole hour..."
        ['post', '/auth/register', '429'],
        // CredentialLifetimeTest: "holds the forgot-password throttle for the whole hour..."
        ['post', '/auth/forgot-password', '429'],
    ];

    foreach ($expected as [$method, $path, $status]) {
        $response = data_get(openApiDocument(), "paths.{$path}.{$method}.responses.{$status}");

        expect($response)->not->toBeNull("openapi.json must declare {$status} for {$method} {$path}");

        // A description, because a status with no prose tells a client the number and nothing
        // about what to do with it - and all five of these are conditions a client must handle
        // differently from each other.
        $description = data_get($response, 'description');

        expect($description)->toBeString("{$method} {$path} {$status} must carry a description");
        expect($description === '')->toBeFalse("{$method} {$path} {$status}'s description must not be empty");
        // The shape, not just the status: every application-raised error in this API is
        // {"message": "..."} and a client switching on status still has to parse a body.
        expect(data_get($response, 'content.application/json.schema.properties.message.type'))
            ->toBe('string', "{$method} {$path} {$status} must document the message body it returns");
    }
});

it('wraps every success response in the data envelope', function (): void {
    // True of all ten operations today and asserted nowhere. Every client's response handling
    // depends on it, tandem-shared's response parsers index through ['data'] to prove it, and
    // the envelope comes from Laravel's JsonResource rather than from anything in this
    // codebase - so it could change under a framework upgrade with the byte gate green.
    $enveloped = 0;
    $bodyless = 0;

    foreach (documentedRoutes() as [$method, $path, $route]) {
        $responses = Arr::wrap(data_get(openApiDocument(), "paths.{$path}.{$method}.responses"));

        foreach ($responses as $status => $response) {
            // (string), and this is the reason the first version of this test performed ZERO
            // assertions and passed: json_decode($json, true) turns a numeric object key into
            // an INT, so "200" arrives here as 200 and an is_string() guard skipped every
            // response in the document. Caught by PHPUnit marking the test risky, which is
            // the only thing that would have.
            $status = (string) $status;

            if ($status[0] !== '2') {
                continue;
            }

            $schema = data_get($response, 'content.application/json.schema');

            if ($schema === null) {
                // 204 and 202 carry no body at all, which is itself part of the contract.
                expect(data_get($response, 'content'))->toBeNull(
                    "{$method} {$path} {$status} has content but no application/json schema",
                );
                $bodyless++;

                continue;
            }

            expect(data_get($schema, 'properties.data'))->not->toBeNull(
                "{$method} {$path} {$status} must return its resource under `data`",
            );
            // in_array() inside the expectation rather than ->toContain('data', $message):
            // Pest's toContain() is VARIADIC, so a second argument is a second needle, not a
            // failure message - which asserted that the `required` list also contained the
            // message string, and failed for a reason that had nothing to do with the
            // contract. Measured, after the fix above turned this loop on for the first time.
            expect(in_array('data', Arr::wrap(data_get($schema, 'required')), true))->toBeTrue(
                "{$method} {$path} {$status} must declare `data` required, or a client cannot rely on it",
            );
            $enveloped++;
        }
    }

    // Counted, so this cannot go vacuous again if the traversal ever stops finding anything.
    // Six enveloped success responses today (register, login, refresh, verify-email, GET /me,
    // PATCH /me) and four bodyless ones (logout, DELETE /me, reset-password, forgot-password).
    expect([$enveloped, $bodyless])->toBe([6, 4]);
});

it('publishes both session expiries as date-time, not as a bare string', function (): void {
    // SessionResource emits DATE_RFC3339_EXTENDED for both, and the document gave them as
    // `string` - so a generated client typed them as strings and the stated reason for
    // returning them at all (a client scheduling a refresh ahead of expiry, a BFF setting a
    // cookie's Max-Age) was left to each client to rediscover.
    foreach (['access_token_expires_at', 'refresh_token_expires_at'] as $field) {
        $property = data_get(openApiDocument(), "components.schemas.SessionResource.properties.{$field}");

        expect(data_get($property, 'type'))->toBe('string');
        expect(data_get($property, 'format'))->toBe('date-time', "SessionResource.{$field} must publish format: date-time");
    }
});

it('publishes required-versus-optional exactly as the rules state it', function (): void {
    // Derived from rules(), not restated: `sometimes` is the whole difference between a field a
    // client may omit and one it must send, and nothing asserted that the document agreed with
    // the rules about which is which. RegisterRequest.locale is the live case - its rules were
    // ['sometimes', 'string', 'max:10'], which reads as "an empty string is fine" and was not
    // (ConvertEmptyStringsToNull makes it a 422), and tandem-shared had mirrored the written
    // rule rather than the behaviour.
    $classes = [
        'RegisterRequest' => RegisterRequest::class,
        'LoginRequest' => LoginRequest::class,
        'RefreshTokenRequest' => RefreshTokenRequest::class,
        'ForgotPasswordRequest' => ForgotPasswordRequest::class,
        'ResetPasswordRequest' => ResetPasswordRequest::class,
        'UpdateProfileRequest' => UpdateProfileRequest::class,
    ];

    foreach ($classes as $schemaName => $class) {
        $rules = (new $class)->rules();

        $expectedRequired = [];
        foreach ($rules as $field => $fieldRules) {
            $flat = array_map(
                fn (mixed $rule): string => is_string($rule) ? $rule : '',
                Arr::wrap($fieldRules),
            );

            // `sometimes` is what makes a field optional in the published schema, whatever else
            // sits beside it - including `required`, which then means "not empty IF present".
            if (! in_array('sometimes', $flat, true)) {
                $expectedRequired[] = $field;
            }
        }

        $schema = data_get(openApiDocument(), "components.schemas.{$schemaName}");

        expect($schema)->not->toBeNull("openapi.json must publish a {$schemaName} schema");
        expect(array_keys(Arr::wrap(data_get($schema, 'properties'))))
            ->toEqualCanonicalizing(array_keys($rules), "{$schemaName}'s published fields must be exactly its rules()");
        expect(Arr::wrap(data_get($schema, 'required')))
            ->toEqualCanonicalizing($expectedRequired, "{$schemaName}'s required list must follow its `sometimes` rules");
    }
});

it('publishes the IANA note on registration timezone, and no other prose anywhere', function (): void {
    // Two assertions in one, because they are two halves of spec §5.2's rule.
    //
    // The first: `timezone` is the one field whose correct use a client cannot deduce, since
    // IanaTimeZone declines to enumerate the ~400 values (the tz database differs per machine,
    // and a published enum would be a per-machine document). So that sentence is deliberately
    // in the one position Scramble publishes.
    expect(data_get(openApiDocument(), 'components.schemas.RegisterRequest.properties.timezone.description'))
        ->toBe('An IANA time zone identifier, such as "Europe/London" or "America/New_York".');

    // The second, and the reason this test is here at all: every other published description
    // and summary in the document is enumerated below. Spec §5.2 records three positions that
    // publish prose verbatim - a comment above a rules() key, a FormRequest class docblock, a
    // controller method docblock - and each was found only by reading the generated document,
    // never by a gate. This is the gate: a note for the next engineer written in any of those
    // three places fails here by name.
    $prose = [];

    foreach (Arr::wrap(data_get(openApiDocument(), 'paths')) as $path => $operations) {
        foreach (Arr::wrap($operations) as $method => $operation) {
            foreach (['summary', 'description'] as $key) {
                $value = data_get($operation, $key);

                if (is_string($value) && $value !== '') {
                    $prose[] = "paths.{$path}.{$method}.{$key}";
                }
            }
        }
    }

    foreach (Arr::wrap(data_get(openApiDocument(), 'components.schemas')) as $name => $schema) {
        if (is_string(data_get($schema, 'description'))) {
            $prose[] = "components.schemas.{$name}.description";
        }

        foreach (Arr::wrap(data_get($schema, 'properties')) as $field => $property) {
            if (is_string(data_get($property, 'description'))) {
                $prose[] = "components.schemas.{$name}.properties.{$field}.description";
            }
        }
    }

    expect($prose)->toEqualCanonicalizing([
        // Deliberate, client-facing, added by #[Endpoint] rather than by a docblock.
        'paths./auth/verify-email.post.summary',
        'paths./auth/verify-email.post.description',
        // Deliberate, client-facing: see above.
        'components.schemas.RegisterRequest.properties.timezone.description',
    ], 'Published prose must be deliberate. Anything new here is either a client-facing '
        .'description that belongs in this list, or internal reasoning that leaked through one '
        .'of spec §5.2\'s three harvest positions.');
});
