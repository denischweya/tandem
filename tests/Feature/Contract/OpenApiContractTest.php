<?php

declare(strict_types=1);

use App\Application\Plans\PageQuery;
use App\Domain\Plans\PlanStatus;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Requests\Api\V1\Auth\VerifyEmailRequest;
use App\Http\Requests\Api\V1\Plans\CreatePlanRequest;
use App\Http\Requests\Api\V1\Plans\ListPlansRequest;
use App\Http\Requests\Api\V1\Plans\UpdatePlanRequest;
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
    // A literal table, deliberately - and not the only literal pin in this file, though it is
    // the only one here for THIS reason. Other literal pins exist for the opposite reason: an
    // anti-vacuity floor on a walk that CAN be derived from the document, pinned to catch the
    // walk going silent rather than because nothing could be derived at all. $parameterCount
    // and $allProsePaths below are two examples, not an enumeration - this file is a Modify
    // target for nearly every task in the wave, so a count written here would be the same
    // self-referential liability the sentence this replaced turned out to be, just one digit
    // later. The whole point of THIS literal is that these statuses cannot be derived from the
    // source Scramble reads. Deriving them from the thing that produces them would mean
    // reimplementing the renderer. Each row is asserted by a named feature test, listed beside
    // it.
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
        // GetPlanTest: "answers one byte-identical 404 for a non-member, an unknown Plan and a
        // malformed id"
        ['get', '/plans/{plan}', '404'],
        // CreatePlanTest: "refuses an unverified user with 403 and writes nothing"
        ['post', '/plans', '403'],
        // CreatePlanTest: "limits one user to thirty an hour and leaves a second user on the
        // same IP alone"
        ['post', '/plans', '429'],
        // UpdatePlanTest: "lets an admin edit and refuses a plain member with 403"
        ['patch', '/plans/{plan}', '403'],
        // UpdatePlanTest: "answers 404 to a non-member"
        ['patch', '/plans/{plan}', '404'],
        // UpdatePlanTest: "refuses an archived Plan with 409 and writes nothing"
        ['patch', '/plans/{plan}', '409'],
        // DeletePlanTest: "refuses an admin and a plain member with 403, and a non-member with 404"
        ['delete', '/plans/{plan}', '403'],
        ['delete', '/plans/{plan}', '404'],
        // ArchivePlanTest: "refuses an admin and a plain member with 403, and a non-member with 404"
        ['post', '/plans/{plan}/archive', '403'],
        ['post', '/plans/{plan}/archive', '404'],
    ];

    foreach ($expected as [$method, $path, $status]) {
        $response = data_get(openApiDocument(), "paths.{$path}.{$method}.responses.{$status}");

        expect($response)->not->toBeNull("openapi.json must declare {$status} for {$method} {$path}");

        // A description, because a status with no prose tells a client the number and nothing
        // about what to do with it - and all fifteen of these are conditions a client must
        // handle differently from each other.
        $description = data_get($response, 'description');

        expect($description)->toBeString("{$method} {$path} {$status} must carry a description");
        expect($description === '')->toBeFalse("{$method} {$path} {$status}'s description must not be empty");
        // The shape, not just the status: every application-raised error in this API is
        // {"message": "..."} and a client switching on status still has to parse a body.
        expect(data_get($response, 'content.application/json.schema.properties.message.type'))
            ->toBe('string', "{$method} {$path} {$status} must document the message body it returns");
    }

    // The reverse direction, and what turns the table above from a list into a gate. Scramble
    // publishes a status it INFERRED as a $ref into components.responses - 401 from auth/auth:*
    // middleware, 422 from a FormRequest with rules() or after(), 403 from can:/Authorize
    // middleware or a FormRequest whose authorize() return type is not the literal true, 404
    // from a route-model-bound path parameter - while a status the application AUTHORS with
    // #[Response] is published inline, carrying its own description and schema. So "inline" is
    // exactly "authored", and the description and body-shape checks in the loop above (a
    // non-empty description, and a {message: string} body) are only possible for an inline
    // response: a $ref response object has neither key.
    //
    // Measured at tandem-api@02f5418 as five and thirteen; re-measured against the regenerated
    // document at this commit, now fifteen and twenty-two - the table and the inferred $ref count
    // both grow every time a task adds a row, so this is a re-verified current fact, not the
    // original snapshot (round 1: GET /plans added one inferred 401, from auth:sanctum, and one
    // inferred 422, from ListPlansRequest, moving twenty to twenty-two; it added no inline
    // non-2xx response of its own, so fifteen did not move). The fifteen inline non-2xx responses
    // in the committed document are exactly the fifteen rows above, and the twenty-two $ref ones
    // are exactly the inferred 401s, 422s and verify-email's authorize()-derived 403.
    //
    // This is the assertion that makes a Wave 2 endpoint shipped without its row fail here
    // instead of passing quietly. What actually governs inference is the list two paragraphs up
    // (middleware; a FormRequest's rules()/after()/non-true authorize(); an isModelId path
    // parameter) PLUS one more: any throw or @throws of one of those exception classes anywhere
    // in the route's call graph, because Scramble's own call-graph propagation
    // (FunctionLikeAstDefinitionBuilder) copies a callee's exceptions onto every caller. Live
    // today, not hypothetical: verify-email's one $ref 403 has two independent causes -
    // VerifyEmailRequest::authorize() returning hasValidSignature() (not true), AND
    // VerifyEmail::forSignedPayload()'s @throws AuthorizationException propagating up through
    // VerifyEmailController. NOT "no can: middleware, no Policy classes, no route-model binding"
    // - Wave 2 adds eight FormRequests, and an authorize() override or an @throws on a Plans use
    // case reaches one of the two mechanisms above with no Policy class or route-model binding
    // involved at all.
    //
    // What actually makes Wave 2's 403s, 404s, 409s and 429s authored, for a reason that
    // survives every one of those eight FormRequests because it has nothing to do with
    // FormRequests: each is a bare RuntimeException subclass mapped to a status by a render()
    // closure in bootstrap/app.php, and the five ExceptionToResponseExtensions Scramble ships
    // (vendor/dedoc/scramble/src/Support/ExceptionToResponseExtensions) know only
    // Auth[orization|entication]Exception, ValidationException, RecordsNotFoundException (which
    // ModelNotFoundException extends) and Symfony's HttpException - everything else maps to
    // null and is filtered out of the generated document. A render()-mapped RuntimeException
    // publishes nothing either way, so it has to be authored.
    //
    // Coverage disclosed, not assumed: $authored is built entirely by walking what openapi.json
    // ALREADY publishes for each route documentedRoutes() finds, so this proves every status
    // that is inline in the committed document has a table row - not that the table (or the
    // document) covers every status the running application can actually produce. A status
    // raised by code Scramble has no position for - the render() exception mapping or the
    // throttle closure the header comment above names - publishes NOTHING at the route at all,
    // neither inline nor $ref, so it is invisible to this loop exactly as it is invisible to
    // Scramble, and still has to be added to $expected by hand, as every row in it already is.
    // This closes the "authored but the table forgot it" gap; it does not and cannot close the
    // "raised but never published anywhere" gap, which is why the hand-asserted $expected table
    // above remains load-bearing rather than becoming redundant with this loop.
    $authored = [];

    foreach (documentedRoutes() as [$method, $path, $route]) {
        $responses = Arr::wrap(data_get(openApiDocument(), "paths.{$path}.{$method}.responses"));

        foreach ($responses as $status => $response) {
            // (string) for the same reason the envelope case casts: json_decode turns a numeric
            // object key into an int, and "401" arrives here as 401.
            $status = (string) $status;

            if ($status === '' || $status[0] === '2') {
                continue;
            }

            if (is_array($response) && array_key_exists('$ref', $response)) {
                continue;
            }

            $authored[] = [$method, $path, $status];
        }
    }

    // Compared as "{method} {path} {status}" strings, not as triples, and that is about the
    // DIFF rather than about the comparison. ArrayComparator canonicalizes a list by sorting both
    // sides and then pairing them BY INDEX, so one extra element shifts every later pair: the
    // triple form prints each triple's three values alphabetically, collapses every matched row
    // to the exporter's `[...]`, and reports a row as removed that was not touched. Measured.
    // The string form prints one `+` line naming the exact triple.
    $line = fn (array $triple): string => implode(' ', $triple);

    expect(array_map($line, $authored))->toEqualCanonicalizing(
        array_map($line, $expected),
        'Every non-2xx response the application AUTHORS with #[Response] must appear in the '
        .'table above, with the feature test that asserts it named beside it. A line here '
        .'that is not in that table is a status shipped with nothing asserting it; a line in '
        .'that table that is not here is a status the document has stopped declaring. Do not '
        .'resolve a mismatch by pasting this list into the table - decide which side is wrong '
        .'first, because the whole value of the table is that each row has a test behind it.',
    );
});

it('wraps every success response in the data envelope', function (): void {
    // True of all sixteen operations today and asserted nowhere (round 1: GET /plans moved this
    // from fifteen - the sum below, 11 + 5, already reads sixteen). Every client's response handling
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
    // Eleven enveloped success responses today (register, login, refresh, verify-email, GET /me,
    // PATCH /me, GET /plans/{plan}, POST /plans, PATCH /plans/{plan}, POST /plans/{plan}/archive,
    // GET /plans) and five bodyless ones (logout, DELETE /me, reset-password, forgot-password,
    // DELETE /plans/{plan}). Task 15 added the seventh enveloped response - GET /plans/{plan} ->
    // 200. Task 16 added the eighth - POST /plans -> 201 - and no bodyless one. Task 17 added the
    // ninth and tenth enveloped responses - PATCH /plans/{plan} -> 200 and
    // POST /plans/{plan}/archive -> 200 - plus the fifth bodyless one, DELETE /plans/{plan} -> 204.
    // This task adds the eleventh enveloped response, GET /plans -> 200, the first paginated list
    // this API has, and no bodyless one.
    expect([$enveloped, $bodyless])->toBe([11, 5]);
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
        'CreatePlanRequest' => CreatePlanRequest::class,
        'UpdatePlanRequest' => UpdatePlanRequest::class,
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

    // The second, and the reason this test is here at all: every published description and
    // summary in the five positions a DOCBLOCK can reach is enumerated below. Spec §5.2 records
    // three - a comment above a rules() key, a FormRequest class docblock, a controller method
    // docblock - and each was found only by reading the generated document, never by a gate.
    //
    // Two more are walked here, and Wave 2 is the first wave that writes into either. Both are
    // the same harvest position - a comment above a rules() array key - for a PARAMETER rather
    // than for a body field, and they differ only in where Scramble puts the string:
    //
    //   paths.*.*.parameters[n].description
    //     A flat parameter. SchemaBagToParametersTransformer::makeParameterFromSchema() MOVES
    //     the description off the schema and onto the Parameter ($schema->setDescription(''),
    //     then ->description($description)), so it never appears under components.schemas at
    //     all. Every rules() key of a GET or DELETE route lands here with no @query tag
    //     involved: FormRequestParametersExtractor passes in: 'query' for every method in
    //     RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY (['get','delete','head']). A
    //     controller method's `@param` tag description lands here too, for a path parameter -
    //     PathParametersExtractor::handle() reads it off the action's own PHPDoc.
    //
    //   paths.*.*.parameters[n].schema.description
    //     A deep (dot-notated) parameter: DeepParametersMerger::extractTypeFromParameter() moves
    //     the description the other way, onto the parameter's schema. UNREACHABLE while
    //     config/scramble.php keeps 'flatten_deep_query_parameters' => true, because a
    //     dot-notated key is then flattened to foo[bar] with a flat schema and takes the first
    //     path instead. Walked anyway, so flipping that flag cannot silently reopen the blind
    //     spot - this half is insurance on a config value and proves nothing today, which is
    //     said plainly here for the same reason OpenApiEnvironmentTest's docblock says it.
    //
    // Three positions are deliberately NOT walked by name. paths.*.*.responses.*.description (30
    // strings today, round 1 - two of them GET /plans/{plan}'s 200 and 404 from Task 15, and this
    // count moves with every inline or inferred response description the document gains - the
    // recursive walk's own comment, further down this file, derives the same 30 as one third of
    // its 39),
    // components.responses.* (7), and info.description / servers.0.description (2) are
    // AUTHORED - every one comes from a #[Response] attribute, from Scramble's own fixed
    // strings, or from config/scramble.php - never from a docblock harvest. A path-level
    // allow-list over ~50 authored strings cannot catch the only defect available there (an
    // internal citation inside prose that is meant to be published), so it would be fifty lines
    // of literal buying nothing.
    //
    // A SIXTH position is neither walked by name above nor excused as authored, and it is why
    // the count below exists. A paginated list's ->additional(['meta' => ['next_cursor' => ...]])
    // merges a plain PHP array into the response, and Scramble publishes its shape INLINE at
    // paths.*.*.responses.*.content.*.schema.properties.meta.properties.next_cursor - never as a
    // components.schemas entry. PhpDocResolver::enterNode() (vendor/dedoc/scramble/src/Infer/
    // Visitors/PhpDocResolver.php) attaches a parsed docblock to ANY Expr\ArrayItem carrying a
    // `//` or `/** */` comment, with no scoping to FormRequest::rules() at all - the identical
    // mechanism already walked into components.schemas.*.properties above, just landing at a
    // path this test has never named.
    //
    // ROUND 1, PROVEN rather than hypothetical: GET /plans (the first paginated list) now
    // exists, and its controller's ->additional(['meta' => ['next_cursor' => ...]]) now gives
    // this position something to leak. A break step placed a `//` comment immediately above
    // that 'next_cursor' key and regenerated: neither $prose nor $parameterCount above moved -
    // both stayed green, exactly as the untested version of this paragraph predicted - and the
    // leaked comment published at
    // paths./plans.get.responses.200.content.application/json.schema.properties.meta.properties.next_cursor.description,
    // which is a path the recursive walk below does name (it is one of the 39), so that
    // assertion caught it and named the exact position. The comment was removed and the
    // document regenerated back to this committed state before this test was trusted again.
    //
    // One blind spot remains in the named walk, stated rather than implied: it descends exactly
    // one level into components.schemas.*.properties, so a nested object's field description
    // (components.schemas.X.properties.a.properties.b.description) escapes it too. Wave 2 writes
    // no dot-notated body rule, so the document contains no such object to walk - checked, not
    // assumed. Both that gap and the sixth position above are closed at the COUNT level, not the
    // name level, by the recursive walk at the end of this test.
    $prose = [];
    $parameterCount = 0;

    foreach (Arr::wrap(data_get(openApiDocument(), 'paths')) as $path => $operations) {
        foreach (Arr::wrap($operations) as $method => $operation) {
            foreach (['summary', 'description'] as $key) {
                $value = data_get($operation, $key);

                if (is_string($value) && $value !== '') {
                    $prose[] = "paths.{$path}.{$method}.{$key}";
                }
            }

            foreach (Arr::wrap(data_get($operation, 'parameters')) as $index => $parameter) {
                $parameterCount++;

                foreach (['description', 'schema.description'] as $key) {
                    $value = data_get($parameter, $key);

                    if (is_string($value) && $value !== '') {
                        $prose[] = "paths.{$path}.{$method}.parameters.{$index}.{$key}";
                    }
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

    // Counted, for the reason the envelope case above is counted: a traversal that stopped
    // finding parameter objects would build an empty $prose and pass while watching nothing.
    // Eight before this task: four on POST /auth/verify-email, one for GET /plans/{plan}
    // (Task 15, the first Wave 2 route to add a path parameter), and three more for Task 17's
    // three parameterised routes - PATCH /plans/{plan}, DELETE /plans/{plan} and
    // POST /plans/{plan}/archive each carry the one {plan} path parameter. This task adds three
    // more (+3, measured by counting the `parameters` arrays in the regenerated document, not
    // assumed from the route count): GET /plans's status, limit and cursor, the first @query
    // parameters this API has published rather than path parameters - eleven today. A Wave 2
    // route with a path or query parameter moves this number, and is meant to: any task that
    // adds one must update this literal in the same commit and state the arithmetic it
    // contributes (+1, +2, ...) in its report. Task 39 re-derives the final absolute count from
    // the regenerated document rather than trusting any task's arithmetic forward.
    expect($parameterCount)->toBe(
        11,
        'The parameter walk above visited a different number of parameter objects than the '
        .'document publishes. Either a route gained or lost a parameter - in which case this '
        .'number moves with it (update the literal here in the same commit, and say by how much '
        .'in your report) - or the walk stopped finding them, in which case the two parameter '
        .'positions are no longer being checked at all.',
    );

    expect($prose)->toEqualCanonicalizing([
        // Deliberate, client-facing, added by #[Endpoint] rather than by a docblock.
        'paths./auth/verify-email.post.summary',
        'paths./auth/verify-email.post.description',
        // Deliberate, client-facing: see above.
        'components.schemas.RegisterRequest.properties.timezone.description',
        // Deliberate, client-facing: the same sentence as RegisterRequest.timezone, in the same
        // position, for the same reason - IanaTimeZone declines to enumerate the ~400 values,
        // so a client cannot deduce the field's shape from the schema.
        'components.schemas.CreatePlanRequest.properties.timezone.description',
    ], 'Published prose must be deliberate. Anything new here is either a client-facing '
        .'description that belongs in this list, or internal reasoning that leaked through one '
        .'of spec §5.2\'s harvest positions.');

    // B37a: the two checks above name five positions by hand and excuse three more as
    // authored. Both leave the sixth position described above - and any position nobody here
    // has thought to name - completely unwatched: a string landing there moves neither
    // $parameterCount nor $prose. Two independent shapes of that leak, same consequence either
    // way: a `//` comment above a future 'next_cursor' => $page->nextCursor (a sibling position
    // to the sixth), or a `//` comment one level deeper than $prose's own properties walk goes -
    // inside a Resource field that is itself an object, e.g. a Resource publishing a field as
    // {value: {description: ...}} instead of a bare scalar. Either would leave $parameterCount
    // and $prose green and the byte gate would reproduce the leak forever. This closes that at
    // the only level a blind-spot-by-definition CAN be closed at - not by naming the position,
    // which would make it not a blind spot - but by walking every description/summary in the
    // WHOLE document, at any depth, by key name alone, and pinning the full PATH SET.
    //
    // A bare integer COUNT was tried first and rejected, on a demonstrated false negative: an
    // integer cannot distinguish "nothing changed" from "one authored path vanished and one
    // leaked path appeared", and both move the document by zero net strings. Delete one
    // authored paths./me.get.responses.200.description in the same commit that adds a leak
    // elsewhere and the total stays unchanged - the exact leak class this check exists to
    // catch, surviving the fix. A path SET cannot be fooled that way: toEqualCanonicalizing's
    // diff shows the vanished path and the added path by name, and the leaked path is the one a
    // reviewer sees, not an integer that quietly cancelled out.
    //
    // Matching on the KEY NAME alone, at any depth, is also wider than $prose in a way worth
    // disclosing rather than assuming away: a `description` or `summary` key inside an
    // `example` or `default` payload - not a Scramble-authored schema position at all - would
    // appear in this list too, reading as a leaked docblock when it is neither. Wave 2 writes no
    // such payload today (checked: none of the paths below sits under `example` or `default`),
    // so this is a disclosed false-positive risk, not an observed one.
    //
    // Any task adding a new deliberate position (client-facing prose the way verify-email's
    // #[Endpoint] text already is) must add its path to BOTH this list and $prose's allow-list
    // in the same commit, and say which path was added in its report - the same obligation
    // Amendment 2 states for $parameterCount, extended to this literal by the same ruling
    // (controller-amendments.md Amendment 2).
    $allProsePaths = [];

    $walkAllProse = function (mixed $node, string $path) use (&$walkAllProse, &$allProsePaths): void {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $childPath = $path === '' ? (string) $key : "{$path}.{$key}";

            if (($key === 'description' || $key === 'summary') && is_string($value) && $value !== '') {
                $allProsePaths[] = $childPath;
            } else {
                $walkAllProse($value, $childPath);
            }
        }
    };

    $walkAllProse(openApiDocument(), '');

    // Every description/summary path in today's document, found by running this exact walk and
    // listing the result - not derived by arithmetic. The first four are the ones $prose above
    // already names and pins by content; the other 39 are the authored strings $prose
    // deliberately does not walk by name (30 responses.*.description + 7 components.responses.*
    // + 2 info/servers). Task 15 added two of the responses.*.description rows:
    // GET /plans/{plan}'s 200 (Scramble's own inferred description for the PlanResource return
    // type, not authored prose) and its 404 (the #[Response] attribute on GetPlanController).
    // Task 16 added three more: POST /plans's 201 (Scramble's own inferred description, not
    // authored prose), and its 403 and 429 (the #[Response] attributes on
    // CreatePlanController) - plus the fourth $prose entry, CreatePlanRequest.timezone. Task 17
    // added ten more, all authored the same two ways - one inferred 200 description per route
    // that returns a PlanResource, and one #[Response] attribute per declared non-2xx status:
    // PATCH /plans/{plan}'s 200 (inferred), 403, 404 and 409 (each a #[Response] attribute on
    // UpdatePlanController, the first bootstrap/app.php render()-closure status this walk has
    // seen); POST /plans/{plan}/archive's 200 (inferred), 403 and 404 (#[Response] on
    // ArchivePlanController); and DELETE /plans/{plan}'s 204 (no body, so no inferred
    // description - the two #[Response]s on DeletePlanController are the only prose at that
    // path), 403 and 404.
    //
    // This task adds exactly one more: GET /plans's 200 (Scramble's own inferred description for
    // an AnonymousResourceCollection return type, not authored prose - no #[Response] attribute
    // exists on ListPlansController).
    expect($allProsePaths)->toEqualCanonicalizing([
        'paths./auth/verify-email.post.summary',
        'paths./auth/verify-email.post.description',
        'components.schemas.RegisterRequest.properties.timezone.description',
        'components.schemas.CreatePlanRequest.properties.timezone.description',
        'info.description',
        'servers.0.description',
        'paths./auth/forgot-password.post.responses.429.description',
        'paths./auth/login.post.responses.200.description',
        'paths./auth/login.post.responses.401.description',
        'paths./auth/login.post.responses.429.description',
        'paths./auth/logout.post.responses.204.description',
        'paths./me.get.responses.200.description',
        'paths./me.patch.responses.200.description',
        'paths./me.delete.responses.204.description',
        'paths./auth/refresh.post.responses.200.description',
        'paths./auth/refresh.post.responses.401.description',
        'paths./auth/register.post.responses.201.description',
        'paths./auth/register.post.responses.429.description',
        'paths./auth/reset-password.post.responses.204.description',
        'paths./auth/verify-email.post.responses.200.description',
        'paths./plans.get.responses.200.description',
        'paths./plans/{plan}.get.responses.200.description',
        'paths./plans/{plan}.get.responses.404.description',
        'paths./plans.post.responses.201.description',
        'paths./plans.post.responses.403.description',
        'paths./plans.post.responses.429.description',
        'paths./plans/{plan}.patch.responses.200.description',
        'paths./plans/{plan}.patch.responses.403.description',
        'paths./plans/{plan}.patch.responses.404.description',
        'paths./plans/{plan}.patch.responses.409.description',
        'paths./plans/{plan}.delete.responses.204.description',
        'paths./plans/{plan}.delete.responses.403.description',
        'paths./plans/{plan}.delete.responses.404.description',
        'paths./plans/{plan}/archive.post.responses.200.description',
        'paths./plans/{plan}/archive.post.responses.403.description',
        'paths./plans/{plan}/archive.post.responses.404.description',
        'components.responses.ValidationException.description',
        'components.responses.ValidationException.content.application/json.schema.properties.message.description',
        'components.responses.ValidationException.content.application/json.schema.properties.errors.description',
        'components.responses.AuthorizationException.description',
        'components.responses.AuthorizationException.content.application/json.schema.properties.message.description',
        'components.responses.AuthenticationException.description',
        'components.responses.AuthenticationException.content.application/json.schema.properties.message.description',
    ], 'The document now publishes a different set of description/summary paths than were last '
        .'enumerated here, in a position neither $prose nor $parameterCount names above. The '
        .'diff names the exact path that was added, removed or changed - add it to $prose\'s '
        .'allow-list if it is deliberate client-facing prose, or trace and remove the docblock '
        .'comment that leaked it, and update this list either way in the same commit (say which '
        .'path was added, in your report - controller-amendments.md Amendment 2).');
});

it('publishes the Plans list query parameters exactly as ListPlansRequest states them', function (): void {
    // The parallel of "publishes required-versus-optional exactly as the rules state it", for
    // the position that test cannot reach: every rules() key of a BODYLESS-method FormRequest
    // (GET here) is published as a paths.*.*.parameters[n] entry and never as a components
    // schema, so no assertion over components.schemas can see it. ROUND 1 CORRECTION: that is
    // NOT because of @query - RequestBodyExtension::handle() takes an early-return branch for
    // get/delete/head that converts every extracted parameter to a query parameter
    // unconditionally, before @query/isInQuery is ever consulted. @query is inert on this route
    // (confirmed: removing it from one key leaves the document byte-identical; removing all
    // three leaves it byte-identical, md5 unchanged - this task's report has both measurements).
    //
    // Derived from rules() rather than restated, so a fourth parameter cannot be added without
    // appearing here - but two of the four checks below cannot fail on a route shaped like this
    // one, and that needs saying rather than leaving implied:
    //
    //   - the key-set equality two lines down is nearly as weak: Scramble publishes every
    //     rules() key automatically for a bodyless method, so adding or removing a rule key
    //     moves both sides of that comparison together.
    //   - the `in === 'query'` check inside the loop below is fully tautological: it is `query`
    //     for every parameter extracted from a bodyless method, independent of anything
    //     ListPlansRequest declares.
    //
    // The three checks that do carry force: the loop's required-flag check (derived from
    // `sometimes`, broken deliberately by dropping it from `limit` and watched to red - this
    // task's report has the output) and the two assertions after the loop - status's full enum
    // (also broken deliberately, by narrowing the rule, and watched to red) and limit's published
    // maximum against PageQuery::MAX_LIMIT (a direct equality against a named constant, which
    // needs no break step to be load-bearing: change the constant or stop publishing the
    // keyword and this fails without any mutation at all).
    $rules = (new ListPlansRequest)->rules();

    $parameters = collect(Arr::wrap(data_get(openApiDocument(), 'paths./plans.get.parameters')))
        ->keyBy(fn (mixed $parameter): string => (string) data_get($parameter, 'name'));

    expect($parameters->keys()->all())->toEqualCanonicalizing(
        array_keys($rules),
        'every rules() key of ListPlansRequest must be published as a query parameter, and nothing else may be',
    );

    foreach ($rules as $field => $fieldRules) {
        $flat = array_map(
            fn (mixed $rule): string => is_string($rule) ? $rule : '',
            Arr::wrap($fieldRules),
        );

        $parameter = $parameters->get($field);

        expect(data_get($parameter, 'in'))->toBe(
            'query',
            "{$field} must be published in the query, not as a body field of a GET",
        );
        expect((bool) data_get($parameter, 'required'))->toBe(
            ! in_array('sometimes', $flat, true),
            "{$field}'s published `required` must follow its `sometimes` rule",
        );
    }

    // `status` carries `nullable`, unlike `limit` and `cursor` - ListPlansRequest's own
    // docblock argues why, and this task left that asymmetry in place rather than rewriting it.
    // Scramble publishes a nullable Rule::in() with `null` appended to the enum list itself, not
    // as a bare three-value enum with nullability expressed some other way, so the expectation
    // is derived from the same rule the loop above already flattened rather than restated as a
    // three-element literal that would fail against what `nullable` actually publishes.
    $statusRules = array_map(
        fn (mixed $rule): string => is_string($rule) ? $rule : '',
        Arr::wrap($rules['status']),
    );
    $expectedStatusValues = in_array('nullable', $statusRules, true)
        ? [...array_column(PlanStatus::cases(), 'value'), null]
        : array_column(PlanStatus::cases(), 'value');

    expect(Arr::wrap(data_get($parameters->get('status'), 'schema.enum')))->toEqualCanonicalizing(
        $expectedStatusValues,
        'the status filter must publish every PlanStatus case - cancelled is accepted and matches nothing (spec §7.3), so leaving it out would document a narrower filter than the one that runs',
    );
    expect(data_get($parameters->get('limit'), 'schema.maximum'))->toBe(
        PageQuery::MAX_LIMIT,
        'limit must publish the maximum it enforces, or a client cannot know 101 is a 422',
    );
});

it('publishes the cursor-page envelope, with next_cursor nullable rather than null', function (): void {
    // Why this exists as its own case: "wraps every success response in the data envelope"
    // passes with `meta` ENTIRELY ABSENT, because a collection resource that forgot
    // ->additional([...]) still publishes a well-formed {data: [...]}. So the envelope test
    // cannot see the half of spec §6.2 that clients page with, and this one asserts meta and
    // next_cursor BY NAME.
    //
    // And the type, not just the key: Scramble narrows a value it can see as a literal null to
    // {"type": "null"}, which is a wrong-but-stable schema - the byte gate reproduces it for
    // ever and a generated client types next_cursor as `null`, making the second page
    // unreachable in TypeScript.
    $schema = data_get(openApiDocument(), 'paths./plans.get.responses.200.content.application/json.schema');

    expect(Arr::wrap(data_get($schema, 'required')))->toEqualCanonicalizing(
        ['data', 'meta'],
        'both envelope keys must be required: a client that has to test for meta cannot page',
    );
    expect(data_get($schema, 'properties.data.type'))->toBe('array');
    expect(Arr::wrap(data_get($schema, 'properties.meta.required')))->toEqualCanonicalizing(
        ['next_cursor'],
        'next_cursor must be present-but-nullable, never optional',
    );
    expect(Arr::wrap(data_get($schema, 'properties.meta.properties.next_cursor.type')))->toEqualCanonicalizing(
        ['string', 'null'],
        'next_cursor must publish as string|null - {"type":"null"} means Scramble narrowed it from a literal null in the controller',
    );
});

it('publishes no group_id on the Plan write surface', function (): void {
    // §12.2: the one line Wave 2 owes Groups is a refusal, and a refusal that is only enforced
    // at runtime is not the one that was decided. `prohibited` or a use-case check would both
    // leave a documented group_id in the contract; the decision was that the field is ABSENT.
    //
    // A literal list rather than a derivation from rules(): the test two screens up already
    // derives the published fields FROM rules(), so it agrees with any rules() - including one
    // that grew a group_id. This is the assertion that does not.
    //
    // Both write schemas, not just CreatePlanRequest: UpdatePlanRequest is the Plan write
    // surface's second schema (Task 17), and §12.2's refusal binds a PATCH exactly as it binds a
    // POST.
    foreach (['CreatePlanRequest', 'UpdatePlanRequest'] as $schemaName) {
        $properties = array_keys(Arr::wrap(data_get(
            openApiDocument(),
            "components.schemas.{$schemaName}.properties",
        )));

        expect($properties)->toEqualCanonicalizing(
            ['title', 'description', 'timezone'],
            "{$schemaName} must publish exactly title, description and timezone - §12.2 makes group_id absent, and spec §15 keeps cover_image_path unwritable",
        );
    }
});

it('publishes no component schema without properties, so no native enum reaches the document', function (): void {
    // Spec §16.2 predicted that Scramble harvests a native backed enum's CLASS docblock into the
    // published schema description, and the prediction holds: EnumTransformer::transform() calls
    // addEnumDescription() unconditionally, and that method reads the enum's class docblock
    // through ReflectionEnum::getDocComment() and prepends it to the description. The config key
    // §16.2 blames for it - enum_cases_description_strategy - does NOT control it: only
    // addEnumCasesDescriptions() reads that key, it handles CASE docblocks, and it returns early
    // here because no case carries one. Output is byte-identical under 'description',
    // 'extension', null and false. So the restriction §16.2 reaches is correct and the escape
    // hatch its wording implies does not exist: Wave 2 types no FormRequest rule and no Resource
    // field as a native PHP enum, and validates `role` and `status` with
    // Rule::in(array_column(MemberRole::cases(), 'value')) - which publishes a plain string with
    // an `enum` list, by way of Rules\In::__toString() and RuleTransformers/InRule.
    //
    // This is NOT the published-prose case above wearing a different hat. That case catches a
    // published enum only while the enum still has a class docblock to leak; an enum with no
    // docblock publishes no description and that case stays green. What is true either way is
    // this: an enum schema carries `enum` and no `properties`, and
    // tandem-shared/src/validation/auth.test.ts parses components.schemas with a Zod schema whose
    // `properties` key is not .optional(), so the FIRST such entry makes .parse() throw and fails
    // both cases in describe("the contract's own constraints") IN ANOTHER REPOSITORY, naming a
    // cause that has nothing to do with whatever was added. Catching it here names the real one.
    $schemas = Arr::wrap(data_get(openApiDocument(), 'components.schemas'));

    // >= 11, not merely non-empty: a document that dropped from eleven schemas to one would
    // still pass a bare not-empty check while this traversal verified almost nothing, which is
    // the same "a floor left behind is slack" shape this test objects to elsewhere. 11 today
    // (measured: count($schemas)) - PlanResource was the ninth (Task 15); CreatePlanRequest was
    // the tenth (Task 16); UpdatePlanRequest is the eleventh (Task 17), and the floor is raised
    // in the same commit that added it: a floor left at 10 would have let a dropped
    // UpdatePlanRequest clear it silently, which is exactly the slack this comment warns
    // against.
    expect(count($schemas))->toBeGreaterThanOrEqual(
        11,
        'components.schemas published fewer than the eleven schemas this traversal expects to '
        .'check, so most of them were skipped rather than verified.',
    );

    foreach ($schemas as $name => $schema) {
        expect(data_get($schema, 'properties'))->not->toBeNull(
            "components.schemas.{$name} publishes no `properties`, which is what a native PHP "
            .'enum publishes instead of an object. Wave 2 types no FormRequest rule and no '
            .'Resource field as a native enum (spec §16.2): a published enum carries its class '
            .'docblock as the schema description, and it also breaks tandem-shared\'s constraint '
            .'test, whose ConstraintDocument requires `properties` on every entry. Validate with '
            .'Rule::in(array_column(X::cases(), \'value\')) and render the value as a string.',
        );
    }
});
