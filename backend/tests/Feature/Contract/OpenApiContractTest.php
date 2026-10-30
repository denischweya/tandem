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
use App\Http\Requests\Api\V1\Plans\AddReactionRequest;
use App\Http\Requests\Api\V1\Plans\ChangeMemberRoleRequest;
use App\Http\Requests\Api\V1\Plans\CreatePlanRequest;
use App\Http\Requests\Api\V1\Plans\EditMessageRequest;
use App\Http\Requests\Api\V1\Plans\InvitePlanMemberRequest;
use App\Http\Requests\Api\V1\Plans\JoinPlanRequest;
use App\Http\Requests\Api\V1\Plans\ListPlanActivityRequest;
use App\Http\Requests\Api\V1\Plans\ListPlanMembersRequest;
use App\Http\Requests\Api\V1\Plans\ListPlansRequest;
use App\Http\Requests\Api\V1\Plans\SendMessageRequest;
use App\Http\Requests\Api\V1\Plans\UpdatePlanRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use Dedoc\Scramble\Attributes\Response as ScrambleResponseAttribute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Behavioural assertions about the committed openapi.json, as distinct from the byte-equality
 * gate in bin/check-openapi.sh.
 *
 * The byte gate protects the document's STABILITY, not its TRUTH. It asks only whether the
 * committed bytes match a fresh generation, so any defect the generator reproduces passes it
 * for ever - and worse, the standard response to "openapi.json is out of date" is
 * `make openapi && git add openapi.json`, which commits whatever the generator now produces.
 * One line vanishing from a generated document of well over a thousand is easy to wave
 * through. (No line count here: this one said "225-line" while the committed document had grown
 * past 1400, which is what an unasserted prose count does.)
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
 * The controller method a route's action actually dispatches to.
 *
 * NOT Route::getActionMethod(): that is built from $this->action['controller'], which for an
 * invokable (single-action) controller registered as `Route::post($uri, FooController::class)`
 * holds the bare class name with no `@__invoke` - measured on this route table, where every
 * action but PlanMemberController's three is registered that way. Route::getAction('uses') is
 * the field Laravel's own controller dispatcher resolves the method from
 * (Route::getControllerMethod(), which is protected and so unreachable from a test), and
 * Str::parseCallback splits it the same way: 'FQCN@__invoke' for an invokable controller,
 * 'FQCN@index' for PlanMemberController::index().
 */
function routeActionMethod(RoutingRoute $route): string
{
    return Str::parseCallback((string) $route->getAction('uses'))[1] ?? '__invoke';
}

/**
 * Every FormRequest subclass a route's own action declares as a parameter.
 *
 * Reflected off the route table rather than listed, so a FormRequest absent from the
 * required-versus-optional map below cannot go unchecked in silence (spec §16.1 item 3): that
 * map is a literal, and a literal nobody counts is exactly the hole this closes. Stays in this
 * file rather than in tests/Pest.php for the reason openApiDocument() does - one consumer. If a
 * second file ever wants it, it MOVES there whole; Pest loads every test file into the global
 * namespace, so a second declaration is a fatal "Cannot redeclare".
 *
 * @return list<class-string<FormRequest>>
 */
function formRequestsFor(RoutingRoute $route): array
{
    $class = $route->getControllerClass();

    if (! is_string($class) || $class === '') {
        return [];
    }

    $classes = [];

    foreach ((new ReflectionMethod($class, routeActionMethod($route)))->getParameters() as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        if (is_subclass_of($type->getName(), FormRequest::class)) {
            $classes[] = $type->getName();
        }
    }

    return $classes;
}

/**
 * Every status an authored #[Response] attribute declares on a route's own action.
 *
 * Amendment 6's third anchor: a source neither the document-derived $authored set nor the
 * hand-written $expected table reads, inside "declares every error status the application
 * itself raises" below. Matches the ATTRIBUTE CLASS via reflection, not the local import name -
 * this codebase imports Dedoc\Scramble\Attributes\Response under three different names
 * (`Response` in twelve controller files, `ResponseDoc` in two, `ResponseAttribute` in two, to
 * dodge a same-file collision with Illuminate\Http\Response or a second Response import), so a
 * name-based grep sees only the twelve and a reflection-based read is the only one that cannot
 * miss the other four.
 *
 * @return list<string>
 */
function responseStatusesFor(RoutingRoute $route): array
{
    $class = $route->getControllerClass();

    if (! is_string($class) || $class === '') {
        return [];
    }

    $statuses = [];

    foreach ((new ReflectionMethod($class, routeActionMethod($route)))->getAttributes(ScrambleResponseAttribute::class) as $attribute) {
        $statuses[] = (string) $attribute->newInstance()->status;
    }

    return $statuses;
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
    // authenticated at all: no securitySchemes, and no `security` on any operation, while most
    // operations require auth:sanctum. A generated client had no Authorization header and no way
    // to describe one. (No count of them here: that sentence read "four operations" and was
    // already wrong by a wide margin when this task measured it against the committed document.
    // The correspondence the loop below checks is the point, nothing computes a total, and a
    // total nothing computes cannot be held true by anything in this repository.)
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
    // walk going silent rather than because nothing could be derived at all. $queryParameterCount
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
        // ListPlanMembersTest: "answers 404 with the one Plan body for a signed-in non-member"
        ['get', '/plans/{plan}/members', '404'],
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
        // ChangeMemberRoleTest: "refuses an admin actor with 403 and the generic body"
        ['patch', '/plans/{plan}/members/{user}', '403'],
        // ChangeMemberRoleTest: "answers 404 with the Plan body for a non-member, whatever
        // {user} names", and "answers 404 with the member body when {user} belongs to another
        // Plan" - one status, two bodies, a test each
        ['patch', '/plans/{plan}/members/{user}', '404'],
        // ChangeMemberRoleTest: "answers 409 with the archived body on an archived Plan"
        ['patch', '/plans/{plan}/members/{user}', '409'],
        // LeaveAndRemoveMemberTest: "refuses the owner with 403 and the one actionable body in
        // this wave", "lets an admin remove a member but not another admin, and never the owner",
        // and "refuses an unverified actor removing another member, before it looks the Plan up"
        // - one status, THREE bodies on this route (the actionable owner-cannot-leave sentence,
        // Laravel's generic role refusal, and EnsureEmailIsVerified's own sentence), a test each
        ['delete', '/plans/{plan}/members/{user}', '403'],
        // LeaveAndRemoveMemberTest: "answers 404 with the Plan body for a non-member actor,
        // whatever {user} names", and "answers 404 with the member body for a {user} who is not
        // an active member" - one status, two bodies, a test each
        ['delete', '/plans/{plan}/members/{user}', '404'],
        // LeaveAndRemoveMemberTest: "answers 409 when removing another member from an archived
        // Plan" - and its pair, "lets a member leave an archived Plan", which is the same URI
        // answering 204
        ['delete', '/plans/{plan}/members/{user}', '409'],
        // PreviewInviteTest: "answers every unusable invite link with one byte-identical body"
        ['get', '/invites/{token}', '404'],
        // PreviewInviteTest: "rate limits invite lookups to sixty an hour and answers with four headers"
        ['get', '/invites/{token}', '429'],
        // InvitePlanMemberTest: "refuses a plain member with 403 and a non-member with the
        // Plan-not-found 404"
        ['post', '/plans/{plan}/invites', '403'],
        ['post', '/plans/{plan}/invites', '404'],
        // InvitePlanMemberTest: "refuses an archived Plan with 409 and a private Plan with its
        // own 409"
        ['post', '/plans/{plan}/invites', '409'],
        // InvitePlanMemberTest: "rate limits invites to twenty an hour for one Plan"
        ['post', '/plans/{plan}/invites', '429'],
        // AcceptInviteTest: "answers one byte-identical 404 for every way the join can fail"
        ['post', '/plans/{plan}/join', '404'],
        // AcceptInviteTest: "refuses an owner-role invite with 409, logs it, and writes no
        // second active owner row"
        ['post', '/plans/{plan}/join', '409'],
        // ListPlanInvitesTest: "refuses a plain member with 403 and a non-member with the
        // Plan-not-found 404"
        ['get', '/plans/{plan}/invites', '403'],
        ['get', '/plans/{plan}/invites', '404'],
        // RevokeInviteTest: "refuses a plain member with 403 and a non-member with the
        // Plan-not-found 404"
        ['delete', '/plans/{plan}/invites/{invite}', '403'],
        ['delete', '/plans/{plan}/invites/{invite}', '404'],
        // RevokeInviteTest: "refuses an archived Plan with 409 and writes nothing"
        ['delete', '/plans/{plan}/invites/{invite}', '409'],
        // ActivityFeedTest: "answers 404 with the one Plan body for a signed-in non-member"
        ['get', '/plans/{plan}/activity', '404'],
        // --- The chat rows. CORRECTED: every citation below used to name a test file or a test
        // case that does not exist - "AddReactionTest" and "RemoveReactionTest" (the real file
        // is tests/Feature/Chat/ReactToMessageTest.php, and it owns neither route's refusals),
        // "rate limits messages to one a second per user" (the real limit is thirty a minute),
        // and an "answers 404 to a non-member" case on four different chat files, none of which
        // has one. A citation that names a test which does not exist is worse than none: it
        // reads as evidence and is not.
        //
        // Unlike every row above, chat's refusals are proved by the CROSS-CUTTING suites rather
        // than by each route's own file, because that is where the chat routes were wired in
        // (see the "register the two new message routes in every cross-cutting Plan-route
        // registry" commit). Each of those sweeps derives its route set from the live route
        // table and pins it against an exact literal or a count (seventeen, seven and twenty
        // respectively), so a chat route dropping out of one is a red rather than a silently
        // un-swept row - which is what makes a cross-file citation evidence here:
        //
        //   - 404 for a signed-in non-member, all six routes: NonMemberRefusalTest's "answers
        //     404 with the one Plan-not-found body on every Plan route a non-member can reach",
        //     which sweeps all seventeen {plan} routes with every second parameter naming a real
        //     row OF THAT PLAN, so non-membership is the only thing that can be refusing.
        //     OrderingAndIdorTest's "answers the vague body to a non-member and the precise body
        //     to a member who can see the Plan" is the other half for the {message} and
        //     {reaction} routes: that the Plan check runs BEFORE the message lookup.
        //   - 403 from the verified gate, on the five gated routes (every chat route but the
        //     read): VerifiedGateTest's "gates exactly the Plan routes spec 7.5 names, read off
        //     the route table" is what pins `verified` onto each of them by name, and that same
        //     file's "refuses all four Plan writes for an unverified owner, and writes nothing"
        //     is what pins the gate's own status and sentence behaviourally. Structural plus
        //     behavioural on a sibling route, stated as such: no chat file asserts an unverified
        //     403 against a chat URI directly.
        ['post', '/plans/{plan}/messages', '403'],
        ['post', '/plans/{plan}/messages', '404'],
        // SendMessageTest: "limits one user to thirty messages a minute and leaves a second
        // member of the same Plan alone" - thirty, per MINUTE, per user. The window and the key
        // themselves are LimiterRegistryTest's "keys chat-send on the authenticated user, thirty
        // per minute", since no 429 can see either.
        ['post', '/plans/{plan}/messages', '429'],
        ['get', '/plans/{plan}/messages', '404'],
        // EditMessageTest: "refuses another member editing someone else’s message". Its pair,
        // "lets the author edit their own message", is what stops that passing against a route
        // that refused everybody - and AnonymizedSenderTest's "refuses every member’s edit and
        // delete of an anonymized message, not just a non-author’s" is the same status for the
        // authorless case ADR 0012 introduced.
        ['patch', '/plans/{plan}/messages/{message}', '403'],
        ['patch', '/plans/{plan}/messages/{message}', '404'],
        // DeleteMessageTest: "refuses another member deleting someone else’s message", with
        // "lets the author soft-delete their own message, and it drops out of the list" as its
        // pair. Added in this same fix wave: this row previously cited a case that did not exist
        // on a file that had only the happy path.
        ['delete', '/plans/{plan}/messages/{message}', '403'],
        ['delete', '/plans/{plan}/messages/{message}', '404'],
        ['post', '/plans/{plan}/messages/{message}/reactions', '403'],
        ['post', '/plans/{plan}/messages/{message}/reactions', '404'],
        // LimiterRegistryTest: "keys chat-react on the authenticated user, sixty per minute",
        // plus that file's route-table literal, which is what pins throttle:chat-react onto this
        // route. No behavioural 429 case for reactions, deliberately: it would be sixty requests
        // to re-prove the one mechanism SendMessageTest's thirty already prove, and the limiter's
        // own numbers - the part a 429 cannot see - are asserted directly.
        ['post', '/plans/{plan}/messages/{message}/reactions', '429'],
        ['delete', '/plans/{plan}/messages/{message}/reactions/{reaction}', '403'],
        ['delete', '/plans/{plan}/messages/{message}/reactions/{reaction}', '404'],
    ];

    foreach ($expected as [$method, $path, $status]) {
        $response = data_get(openApiDocument(), "paths.{$path}.{$method}.responses.{$status}");

        expect($response)->not->toBeNull("openapi.json must declare {$status} for {$method} {$path}");

        // A description, because a status with no prose tells a client the number and nothing
        // about what to do with it - and every row of this table is a condition a client must
        // handle differently from the others. (The previous wording said "all fifteen of these"
        // while the table held sixteen rows: a count of the literal above it decays on the next
        // task's row, so this sentence no longer states one.)
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
    // No count of either side here, and that is this task's correction rather than an omission.
    // Both numbers grow whenever any task adds a row, nothing in this file computes either, and
    // the previous generation of this sentence maintained both of them by hand.
    // What is stable is the CORRESPONDENCE, which the assertion below checks: the inline non-2xx
    // responses in the committed document are exactly the rows of $expected above, and the $ref
    // ones are exactly the inferred 401s, 422s and verify-email's authorize()-derived 403.
    //
    // One measured subtlety the two /plans/{plan}/members/{user} rows illustrate, and the reason
    // their 403s are AUTHORED rather than left to inference. Deleting an action's #[ResponseDoc]
    // attributes and regenerating was run for both, and both answer the same way: a 403 is
    // published anyway, as $ref #/components/responses/AuthorizationException, because Scramble's
    // call-graph propagation finds the use case's @throws AuthorizationException -
    // ChangeMemberRole::execute()'s for the PATCH, RemoveMember::execute()'s for the DELETE - and
    // the 404 and the 409 disappear ENTIRELY, because a render()-mapped RuntimeException is
    // invisible to Scramble. A $ref carries neither its own description nor a message body
    // schema, so the loop above cannot check it: leaving the 403 to inference would publish the
    // status while making its table row uncheckable, and leaving the other two to inference would
    // publish nothing at all. Authoring all three is what keeps those rows meaningful.
    // UpdatePlanController authors its 403 for the same reason on the same shape.
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

    // Amendment 6's third anchor. The forward-and-reverse check above compares $authored (read
    // off openapi.json) against $expected (the hand-written table) - and `toEqualCanonicalizing`
    // does not close the compensating-change class when BOTH sides are written: deleting a
    // #[Response] attribute and its table row in one commit can leave that comparison green,
    // because each side loses the same entry. What closes THAT scenario is a THIRD set built
    // from a source NEITHER existing side reads: responseStatusesFor() reflects the attribute
    // straight off the controller action, independent of whether openapi.json has been
    // regenerated to match - so deleting the attribute and the table row while leaving a stale,
    // unregenerated document in place now reds here, because $reflected has lost the entry and
    // $authored (still read off the old document) has not.
    //
    // MEASURED LIMIT, stated plainly rather than implied: this does not make the error-status
    // table unfalsifiable. A commit that deletes the attribute, the table row, AND regenerates
    // openapi.json to match - all three in one place - leaves $reflected, $authored and
    // $expected in agreement, because none of the three sources disagrees about what the
    // document should say once all three have been edited together. No comparison among these
    // three sets can catch that case, because by construction none of them encodes what the
    // *spec* requires independently of what the code and the document currently say. What this
    // anchor removes is the specific hole Amendment 6 named - two hand-written sides edited
    // together while a third, independently-derived source is left behind - not every way a
    // status could be silently dropped.
    //
    // An authored status can only be inline, because the attribute's own toOpenApiResponse()
    // resolves any reference into a concrete object before Scramble ever sees it - so attributes
    // ↔ inline rows is an EQUIVALENCE, not a correlation, which is what makes this anchor sound
    // rather than merely additional. The two sets below are expected to agree at every commit
    // where openapi.json is up to date; where they do not, the committed document is stale
    // relative to the controller it was supposedly generated from, and the reflected set says so
    // without needing to regenerate anything first.
    $reflected = [];

    foreach (documentedRoutes() as [$method, $path, $route]) {
        foreach (responseStatusesFor($route) as $status) {
            $reflected[] = [$method, $path, $status];
        }
    }

    expect(array_map($line, $reflected))->toEqualCanonicalizing(
        array_map($line, $authored),
        'Every #[Response] attribute reflected off a controller action must match the inline '
        .'responses openapi.json actually publishes for that route. A line here that is not in '
        .'the document is a committed openapi.json stale relative to the controller - run '
        .'`make openapi`. A line in the document that is not here is an inline response with no '
        .'attribute behind it at all, which should not be reachable in this codebase: every '
        .'non-2xx inline response comes from an authored #[Response].',
    );
});

it('wraps every success response in the data envelope', function (): void {
    // True of every operation the document publishes, and asserted nowhere else: the pair
    // below is the count, and it is deliberately the only place in this test that states one -
    // this header used to carry a second, independently maintained figure, which read
    // "sixteen" while the literal at the end of the case already summed to seventeen. Every
    // client's response handling
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
    // The two numbers sum to the number of operations the document publishes, which is the only
    // form of that count this test keeps - and the LITERAL below is the only place either number
    // is written, deliberately. No prose restatement of the pair: every earlier generation of
    // this comment carried its own copy of the arithmetic, and one of them read "sixteen / 11+5"
    // fifty lines from a literal already summing to seventeen. Nothing reads a comment, so
    // nothing reds when one rots.
    //
    // The two SETS, which do not decay the way their sizes do - not fully re-enumerated here,
    // by examples of each rather than a growing per-task trail (a comment that tried to carry
    // one, bracket-delta by bracket-delta, had already dropped a task's own contribution by the
    // time this task read it - a gap, not a wrong total, but the same unasserted-count liability
    // either way). GET /plans/{plan}, POST /plans, GET /invites/{token} and
    // GET /plans/{plan}/activity are enveloped; DELETE /me, reset-password and
    // DELETE /plans/{plan}/members/{user} are bodyless.
    //
    // 21 and 10, measured off the regenerated document (the command is in this task's report),
    // not summed from any task's own arithmetic: every list-route controller adds one enveloped
    // 2xx (its collection, `data`-wrapped) and every write-route controller adds either one
    // enveloped 2xx (a Resource) or one bodyless 204, and nothing here needs that restated by
    // hand once the pair above is read directly off the thing it counts.
    expect([$enveloped, $bodyless])->toBe([21, 10]);
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
    //
    // The map below is also a literal, and a literal nobody counts is the hole spec §16.1 item 3
    // names: a FormRequest left out of it is UNCHECKED and nothing else notices. So its key set
    // is derived off the route table and compared below, the way the security-correspondence
    // case near the top of this file derives its own set rather than restating one.
    //
    // GET, DELETE and HEAD are skipped because a FormRequest on one of those publishes its
    // rules() as `parameters` and registers NO component schema
    // (RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY, and FormRequestParametersExtractor
    // passing in: 'query'), while the schema's own existence is the first thing the loop below
    // checks - so such a class cannot be a member of this map at all. The three query
    // FormRequests this excludes (ListPlansRequest, ListPlanMembersRequest,
    // ListPlanActivityRequest) are asserted instead by "publishes every list filter as a query
    // parameter and no request body" below.
    //
    // A FormRequest on a BODY-carrying route can also publish no schema, by marking every one of
    // its keys @query: with no body parameters left, RequestBodyExtension returns before
    // makeSchemaFromResults(). VerifyEmailRequest (POST /auth/verify-email) is that case, and is
    // the reason this is two assertions rather than one - skipping such a class silently would
    // reopen the hole from the other side, so the skipped set is itself pinned.
    $bodyRequests = [];
    $queryOnly = [];

    foreach (documentedRoutes() as [$method, $path, $route]) {
        if (in_array($method, ['get', 'delete', 'head'], true)) {
            continue;
        }

        foreach (formRequestsFor($route) as $class) {
            $name = class_basename($class);

            if (data_get(openApiDocument(), "components.schemas.{$name}") === null) {
                $queryOnly[$name] = $class;

                continue;
            }

            $bodyRequests[$name] = $class;
        }
    }

    expect(array_keys($queryOnly))->toEqualCanonicalizing(
        ['VerifyEmailRequest'],
        'A FormRequest on a body-carrying route that publishes no component schema has marked '
        .'every one of its rules() keys @query. VerifyEmailRequest is the only one, and it has '
        .'its own case at the top of this file. A second name here is a request class whose body '
        .'went undocumented - exactly the defect that case was written for - and a name missing '
        .'here means verify-email has started publishing a body no client can send.',
    );

    $classes = [
        'RegisterRequest' => RegisterRequest::class,
        'LoginRequest' => LoginRequest::class,
        'RefreshTokenRequest' => RefreshTokenRequest::class,
        'ForgotPasswordRequest' => ForgotPasswordRequest::class,
        'ResetPasswordRequest' => ResetPasswordRequest::class,
        'UpdateProfileRequest' => UpdateProfileRequest::class,
        'CreatePlanRequest' => CreatePlanRequest::class,
        'UpdatePlanRequest' => UpdatePlanRequest::class,
        // The members section's first body-bearing request. ListPlanMembersRequest is
        // correctly absent and will stay absent: every one of its keys carries a
        // /** @query */ docblock, so Scramble publishes them as parameters[n] on the
        // operation and there is no components.schemas entry for the three assertions below to
        // read. This one's single key carries no @query, so there is.
        'ChangeMemberRoleRequest' => ChangeMemberRoleRequest::class,
        'InvitePlanMemberRequest' => InvitePlanMemberRequest::class,
        // The invites section's Task C. Its single `token` key carries no /** @query */, so it
        // publishes a request BODY schema exactly as ChangeMemberRoleRequest and
        // InvitePlanMemberRequest do, unlike ListPlanMembersRequest and ListPlansRequest.
        'JoinPlanRequest' => JoinPlanRequest::class,
        // The plan chat endpoints from Task 5. SendMessageRequest, EditMessageRequest, and
        // AddReactionRequest all publish request body schemas.
        'SendMessageRequest' => SendMessageRequest::class,
        'EditMessageRequest' => EditMessageRequest::class,
        'AddReactionRequest' => AddReactionRequest::class,
    ];

    expect(array_keys($classes))->toEqualCanonicalizing(
        array_keys($bodyRequests),
        'Every FormRequest reachable on a body-carrying route must be in the map above, or its '
        .'required-versus-optional contract is unchecked and nothing else will notice. A name '
        .'missing here is a new request class; a name that is extra is a class no route reaches '
        .'any more.',
    );

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
    // Three positions are deliberately NOT walked by name: paths.*.*.responses.*.description -
    // the largest group, and the one that grows with every inline or inferred response
    // description the document gains, e.g. GET /plans/{plan}'s 200 and 404 from Task 15 and all
    // four of DELETE /plans/{plan}/members/{user}'s - plus components.responses.* and
    // info.description / servers.0.description. All three are
    // AUTHORED - every one comes from a #[Response] attribute, from Scramble's own fixed
    // strings, or from config/scramble.php - never from a docblock harvest. (No subtotals here
    // any more: this sentence used to state all three, the recursive walk below used to restate
    // all three again as its own breakdown, and the two copies went stale together. The
    // recursive walk's literal is what counts them, and its diff is what names a mover.) A
    // path-level
    // allow-list over every authored string in the document cannot catch the only defect
    // available there (an
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
    // that 'next_cursor' key and regenerated: neither $prose nor either parameter count above moved -
    // both stayed green, exactly as the untested version of this paragraph predicted - and the
    // leaked comment published at
    // paths./plans.get.responses.200.content.application/json.schema.properties.meta.properties.next_cursor.description,
    // which is a position the recursive walk below REACHES, so that assertion caught it - as an
    // ADDITION to its enumerated set, which is exactly what toEqualCanonicalizing reports - and
    // named the exact position. The comment was removed and the document regenerated back to this
    // committed state before this test was trusted again.
    //
    // No count in that sentence, deliberately. It read "it is one of the 39" and was wrong twice
    // over: 39 was the authored-strings subtotal at a commit three tasks back, and a leaked path is
    // never a MEMBER of the enumerated set - it is what the diff adds to it, which is the opposite
    // of how the gate works. A prose count nothing computes is unfalsifiable, so prefer deleting it
    // to maintaining it.
    //
    // One blind spot remains in the named walk, stated rather than implied: it descends exactly
    // one level into components.schemas.*.properties, so a nested object's field description
    // (components.schemas.X.properties.a.properties.b.description) escapes it too. Wave 2 writes
    // no dot-notated body rule, so the document contains no such object to walk - checked, not
    // assumed. Both that gap and the sixth position above are closed at the COUNT level, not the
    // name level, by the recursive walk at the end of this test.
    $prose = [];
    $pathParameterCount = 0;
    $queryParameterCount = 0;

    foreach (Arr::wrap(data_get(openApiDocument(), 'paths')) as $path => $operations) {
        foreach (Arr::wrap($operations) as $method => $operation) {
            foreach (['summary', 'description'] as $key) {
                $value = data_get($operation, $key);

                if (is_string($value) && $value !== '') {
                    $prose[] = "paths.{$path}.{$method}.{$key}";
                }
            }

            foreach (Arr::wrap(data_get($operation, 'parameters')) as $index => $parameter) {
                if (data_get($parameter, 'in') === 'path') {
                    $pathParameterCount++;
                } else {
                    $queryParameterCount++;
                }

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
    // finding parameter objects would build an empty $prose and pass while watching nothing. The
    // two positions below are split (Task 39/C10) because they decay differently.
    //
    // The PATH half is DERIVED off the route table rather than pinned as a literal: a route
    // added without regenerating fails here on its own, with no maintenance owed by any task. A
    // literal summing every task's `+1`/`+2`/`+3` was tried first and is what this replaces -
    // every one of those deltas was correct when written and the arithmetic trail itself became
    // exactly the kind of prose this file's own standing rule now rejects (a count nothing
    // computes, maintained by hand across a dozen tasks). The derivation below is the fix: it
    // sums count($route->parameterNames()) over documentedRoutes(), which is the route table
    // itself, not a copy of it.
    //
    // The QUERY half stays a literal, because deriving it would mean instantiating the same five
    // FormRequest classes a second time - VerifyEmailRequest for the case at the top of this
    // file, and the four list requests for "publishes every list filter as a query parameter",
    // which already does exactly that. Thirteen, from those five classes and no others:
    // VerifyEmailRequest's id/hash/expires/signature (4), ListPlansRequest's
    // status/limit/cursor (3), ListPlanMembersRequest's limit/cursor (2),
    // ListPlanActivityRequest's limit/cursor (2), and ListPlanMessagesRequest's limit/cursor (2) -
    // each of those five counts is independently asserted by name, by the two cases named above,
    // so this literal is not the only thing that would catch one of them drifting. No other route
    // has a query FormRequest.
    $expectedPathParameterCount = 0;

    foreach (documentedRoutes() as [$method, $path, $route]) {
        $expectedPathParameterCount += count($route->parameterNames());
    }

    expect($pathParameterCount)->toBe(
        $expectedPathParameterCount,
        'The parameter walk above visited a different number of PATH parameters than the route '
        .'table implies. Either a route was added without regenerating the document, or the '
        .'walk has stopped visiting parameters, in which case this position is no longer being '
        .'checked at all.',
    );

    expect($queryParameterCount)->toBe(
        13,
        'The parameter walk above visited a different number of QUERY parameters than the five '
        .'FormRequests with query keys publish - VerifyEmailRequest (4), ListPlansRequest (3), '
        .'ListPlanMembersRequest (2), ListPlanActivityRequest (2) and ListPlanMessagesRequest (2). '
        .'A new filter on one of those five moves this number and is meant to.',
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
    // has thought to name - completely unwatched: a string landing there moves neither the
    // parameter counts nor $prose. Two independent shapes of that leak, same consequence either
    // way: a `//` comment above a future 'next_cursor' => $page->nextCursor (a sibling position
    // to the sixth), or a `//` comment one level deeper than $prose's own properties walk goes -
    // inside a Resource field that is itself an object, e.g. a Resource publishing a field as
    // {value: {description: ...}} instead of a bare scalar. Either would leave the parameter
    // counts and $prose green and the byte gate would reproduce the leak forever. This closes that at
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
    // Amendment 2 states for the parameter count, extended to this literal by the same ruling
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
    // listing the result - not derived by arithmetic. The literal IS the count; no subtotal is
    // written here any more, and that is this task's correction. The breakdown that used to sit
    // in this position ("the other 45 ... = 36 responses.*.description + 7 components.responses.*
    // + 2 info/servers") had already been stale twice - it read "39 (30 + 7 + 2)" while the
    // literal held 45, and before that disagreed with the narrative below it by two - so it is
    // gone rather than updated again. Nothing reads a subtotal, so nothing reds when one rots,
    // and this literal's own diff already names any path that moves.
    //
    // The method, which is the part worth keeping. Count the literal's own indented lines, at
    // BOTH ends, and difference them. Counting with a pattern for what one EXPECTS to find -
    // entries beginning 'paths.' or 'components.' - silently drops info.description and
    // servers.0.description and lands two low, which is exactly how a stale figure here once
    // survived looking re-measured.
    //
    // What each task contributed, which stays true however the total moves. Task 15:
    // GET /plans/{plan}'s 200 (Scramble's own inferred description for the PlanResource return
    // type, not authored prose) and its 404 (the #[Response] attribute on GetPlanController).
    // Task 16: POST /plans's 201 (inferred), and its 403 and 429 (#[Response] on
    // CreatePlanController) - plus the fourth $prose entry, CreatePlanRequest.timezone. Task 17,
    // all authored the same two ways - one inferred 200 description per route that returns a
    // PlanResource, one #[Response] attribute per declared non-2xx status:
    // PATCH /plans/{plan}'s 200 (inferred), 403, 404 and 409 (each a #[Response] attribute on
    // UpdatePlanController, the first bootstrap/app.php render()-closure status this walk has
    // seen); POST /plans/{plan}/archive's 200 (inferred), 403 and 404 (#[Response] on
    // ArchivePlanController); and DELETE /plans/{plan}'s 204, 403 and 404.
    //
    // ⚠ That DELETE /plans/{plan} entry carried a false parenthesis until this task - "(no body,
    // so no inferred description - the two #[Response]s on DeletePlanController are the only
    // prose at that path)" - which contradicted the literal below it in this same case, where
    // paths./plans/{plan}.delete.responses.204.description has been listed all along. What a
    // bodyless response has no schema for is the ENVELOPE case above, which is where the "no
    // body" fact actually bites; it has nothing to do with whether a description is published.
    // The false half mattered: this task predicted its own delta from it and would have been
    // three instead of four.
    //
    // What the regenerated document actually publishes, read off it key by key rather than
    // reasoned about: every 204 in it carries Scramble's own fixed string 'No content' - logout,
    // reset-password, DELETE /me, DELETE /plans/{plan} and this task's DELETE - and all five are
    // in the literal below. forgot-password's 202 is the exception and is NOT in the literal: it
    // publishes description: "", and the walk's own non-empty guard excludes an empty string. So
    // "bodyless" and "carries a description" are independent in both directions, and the literal
    // below is the only statement of which responses have one.
    //
    // Task 18: GET /plans's 200 (Scramble's own inferred description for an
    // AnonymousResourceCollection return type - no #[Response] attribute exists on
    // ListPlansController). Task 21: GET /plans/{plan}/members's 200 (the identical
    // AnonymousResourceCollection mechanism) and its 404 (#[ResponseDoc] on
    // PlanMemberController::index()). Task 22: PATCH /plans/{plan}/members/{user}'s 200
    // (inferred, for a PlanMemberResource return type) and its 403, 404 and 409 (the three
    // #[ResponseDoc] attributes on PlanMemberController::update()).
    //
    // Task 24 added four, all at DELETE /plans/{plan}/members/{user}: its 204 ('No content',
    // Scramble's own fixed string for a bodyless response - see the correction above) and its
    // 403, 404 and 409 (the three #[ResponseDoc] attributes on
    // PlanMemberController::destroy()).
    //
    // Task 25 adds three, all at GET /invites/{token}: its 200 (Scramble's own inferred
    // description for an InvitePreviewResource return type - no #[Response] attribute exists
    // for it, since 200 needs no authoring the way 404 and 429 do) and its 404 and 429 (the
    // two #[Response] attributes on PreviewInviteController, both load-bearing per §6's note
    // that a render()-mapped bare RuntimeException and a throttle: middleware are both
    // invisible to Scramble's own inference). No new $prose entry: this route publishes no
    // client-facing summary or description of its own beyond the per-response ones already
    // counted here, and if one ever appears it belongs in BOTH literals in the same commit.
    //
    // Task 26 adds five, all at POST /plans/{plan}/invites: its 201 (inferred, for the
    // PlanInviteCreatedResource return type) and its 403, 404, 409 and 429 (the four
    // #[Response] attributes on InvitePlanMemberController). Recorded here rather than left
    // implicit - this paragraph was missing from that task's own commit, which added the five
    // paths to the literal below without a matching attribution sentence; corrected in passing
    // while adding this task's own paragraph to the same comment block.
    //
    // This task adds three, all at POST /plans/{plan}/join: its 200 (inferred, for the
    // PlanResource return type) and its 404 and 409 (the two #[Response] attributes on
    // AcceptInviteController - 404 for every unusable-invite case, 409 for the one Decision 9
    // adds). No new $prose entry here either, for the same reason Task 25's three carry none.
    //
    // This task adds seven. Three at GET /plans/{plan}/invites: its 200 (inferred, for an
    // AnonymousResourceCollection return type - no #[Response] attribute exists on
    // ListPlanInvitesController) and its 403 and 404 (the two #[Response] attributes there).
    // Four at DELETE /plans/{plan}/invites/{invite}: its 204 ('No content', Scramble's own
    // fixed string - this task's SIXTH such 204 in the document) and its 403, 404 and 409 (the
    // three #[ResponseAttribute] attributes on RevokeInviteController). No new $prose entry:
    // neither route publishes a client-facing summary or description beyond the per-response
    // ones counted here. Predicted as +7 before regenerating (matching the measured shape of
    // every other Plan route this wave: one inferred 2xx plus one path per authored
    // #[Response]), then confirmed by running the recursive walk against the regenerated
    // document and diffing it against the committed literal, which named exactly these seven
    // paths and no others.
    //
    // This task adds two, both at GET /plans/{plan}/activity: its 200 (Scramble's own inferred
    // description for an AnonymousResourceCollection return type - matching GET /plans and
    // GET /plans/{plan}/members, which carry the identical mechanism) and its 404 (the
    // #[Response] attribute on PlanActivityController). No new $prose entry: this route
    // publishes no client-facing summary or description of its own beyond this one
    // per-response description. Predicted +2 before regenerating (matching the measured shape
    // of every other Plan route this wave), then confirmed by running the recursive walk
    // against the regenerated document and diffing it against the committed literal.
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
        'paths./plans/{plan}/members.get.responses.200.description',
        'paths./plans/{plan}/members.get.responses.404.description',
        'paths./plans/{plan}/members/{user}.patch.responses.200.description',
        'paths./plans/{plan}/members/{user}.patch.responses.403.description',
        'paths./plans/{plan}/members/{user}.patch.responses.404.description',
        'paths./plans/{plan}/members/{user}.patch.responses.409.description',
        'paths./plans/{plan}/members/{user}.delete.responses.204.description',
        'paths./plans/{plan}/members/{user}.delete.responses.403.description',
        'paths./plans/{plan}/members/{user}.delete.responses.404.description',
        'paths./plans/{plan}/members/{user}.delete.responses.409.description',
        'paths./plans/{plan}/invites.get.responses.200.description',
        'paths./plans/{plan}/invites.get.responses.403.description',
        'paths./plans/{plan}/invites.get.responses.404.description',
        'paths./plans/{plan}/invites/{invite}.delete.responses.204.description',
        'paths./plans/{plan}/invites/{invite}.delete.responses.403.description',
        'paths./plans/{plan}/invites/{invite}.delete.responses.404.description',
        'paths./plans/{plan}/invites/{invite}.delete.responses.409.description',
        'paths./invites/{token}.get.responses.200.description',
        'paths./invites/{token}.get.responses.404.description',
        'paths./invites/{token}.get.responses.429.description',
        'paths./plans/{plan}/invites.post.responses.201.description',
        'paths./plans/{plan}/invites.post.responses.403.description',
        'paths./plans/{plan}/invites.post.responses.404.description',
        'paths./plans/{plan}/invites.post.responses.409.description',
        'paths./plans/{plan}/invites.post.responses.429.description',
        'paths./plans/{plan}/join.post.responses.200.description',
        'paths./plans/{plan}/join.post.responses.404.description',
        'paths./plans/{plan}/join.post.responses.409.description',
        'components.responses.ValidationException.description',
        'components.responses.ValidationException.content.application/json.schema.properties.message.description',
        'components.responses.ValidationException.content.application/json.schema.properties.errors.description',
        'components.responses.AuthorizationException.description',
        'components.responses.AuthorizationException.content.application/json.schema.properties.message.description',
        'components.responses.AuthenticationException.description',
        'components.responses.AuthenticationException.content.application/json.schema.properties.message.description',
        'paths./plans/{plan}/activity.get.responses.200.description',
        'paths./plans/{plan}/activity.get.responses.404.description',
        // Task 5: plan chat endpoints (SendMessage, EditMessage, DeleteMessage, AddReaction,
        // RemoveReaction) - each adds descriptions for all published responses, matching the
        // pattern of every other Plan route this wave.
        'paths./plans/{plan}/messages.get.responses.200.description',
        'paths./plans/{plan}/messages.get.responses.404.description',
        'paths./plans/{plan}/messages.post.responses.201.description',
        'paths./plans/{plan}/messages.post.responses.403.description',
        'paths./plans/{plan}/messages.post.responses.404.description',
        'paths./plans/{plan}/messages.post.responses.429.description',
        'paths./plans/{plan}/messages/{message}.patch.responses.200.description',
        'paths./plans/{plan}/messages/{message}.patch.responses.403.description',
        'paths./plans/{plan}/messages/{message}.patch.responses.404.description',
        'paths./plans/{plan}/messages/{message}.delete.responses.204.description',
        'paths./plans/{plan}/messages/{message}.delete.responses.403.description',
        'paths./plans/{plan}/messages/{message}.delete.responses.404.description',
        'paths./plans/{plan}/messages/{message}/reactions.post.responses.204.description',
        'paths./plans/{plan}/messages/{message}/reactions.post.responses.403.description',
        'paths./plans/{plan}/messages/{message}/reactions.post.responses.404.description',
        'paths./plans/{plan}/messages/{message}/reactions.post.responses.429.description',
        'paths./plans/{plan}/messages/{message}/reactions/{reaction}.delete.responses.204.description',
        'paths./plans/{plan}/messages/{message}/reactions/{reaction}.delete.responses.403.description',
        'paths./plans/{plan}/messages/{message}/reactions/{reaction}.delete.responses.404.description',
    ], 'The document now publishes a different set of description/summary paths than were last '
        .'enumerated here, in a position neither $prose nor either parameter count names above. The '
        .'diff names the exact path that was added, removed or changed - add it to $prose\'s '
        .'allow-list if it is deliberate client-facing prose, or trace and remove the docblock '
        .'comment that leaked it, and update this list either way in the same commit (say which '
        .'path was added, in your report - controller-amendments.md Amendment 2).');
});

it('publishes every list filter as a query parameter and no request body', function (): void {
    // The parallel of "publishes required-versus-optional exactly as the rules state it", for
    // the position that test cannot reach: every rules() key of a BODYLESS-method FormRequest
    // (GET here) is published as a paths.*.*.parameters[n] entry and never as a components
    // schema, so no assertion over components.schemas can see it. @query is REDUNDANT on a GET,
    // not load-bearing: RequestBodyExtension::handle() takes an early-return branch for
    // get/delete/head that converts every extracted parameter to a query parameter
    // unconditionally, before @query/isInQuery is ever consulted - confirmed by removing it from
    // a key and regenerating: the document is byte-identical either way, and removing it from
    // all three of ListPlansRequest's keys leaves the document's md5 unchanged too.
    //
    // DERIVED path set (B39a), not the hardcoded three-element list an earlier round of this
    // case carried: a hardcoded list is reached by a RENAME of one of the three (the entry just
    // stops matching, and the diff below names it) but not by an ADDITION - a fourth paginated
    // list could land and this case would never learn its path exists. A paginated list IS, in
    // this codebase, exactly a GET route whose action declares a FormRequest: measured, every
    // other GET action takes a plain Illuminate\Http\Request or no request object at all
    // (GetPlanController; ListPlanInvitesController, deliberately unpaginated per spec §6.3;
    // PreviewInviteController; MeController::show).
    //
    // Derived from rules() rather than restated, so a filter added to any of the three without
    // reaching the document fails here - but two of the per-field checks below cannot fail on a
    // route shaped like this one, and that needs saying rather than leaving implied: the key-set
    // equality is nearly as weak, because Scramble publishes every rules() key automatically for
    // a bodyless method, so adding or removing a rule key moves both sides of that comparison
    // together; and the `in === 'query'` check is fully tautological, because it is `query` for
    // every parameter extracted from a bodyless method regardless of what the class declares.
    // The checks that do carry force: the required-flag check (derived from `sometimes`), the
    // no-request-body and no-component-schema checks, limit's published maximum against
    // PageQuery::MAX_LIMIT (all three of these FormRequests share that rule), and status's full
    // enum (ListPlansRequest's alone - the only one of the three with a status filter).
    $classes = [];

    foreach (documentedRoutes() as [$method, $path, $route]) {
        if ($method !== 'get') {
            continue;
        }

        foreach (formRequestsFor($route) as $class) {
            $classes[$path] = $class;
        }
    }

    expect($classes)->not->toBe(
        [],
        'no GET route declares a FormRequest any more, so this case checked nothing at all - '
        .'either every paginated list was removed, or the derivation above stopped finding them',
    );

    foreach ($classes as $path => $class) {
        $name = class_basename($class);

        // Not (new $class)->rules(): formRequestsFor()'s return type is
        // class-string<FormRequest>, and the base FormRequest declares no rules() at all (every
        // subclass is expected to add its own), so PHPStan level 8 correctly refuses to call a
        // method it cannot see declared anywhere in the hierarchy. The array-callable form is
        // the same call with no suppression: is_callable() narrows it for both PHP and PHPStan,
        // and every class formRequestsFor() returns genuinely has one, which is what the
        // vacuity guard right below this exists to keep true rather than merely assumed.
        $callable = [new $class, 'rules'];
        $rules = is_callable($callable) ? $callable() : [];

        expect($rules)->not->toBe(
            [],
            "{$name}::rules() returned nothing, so this case checked nothing for GET {$path}.",
        );

        $operation = data_get(openApiDocument(), "paths.{$path}.get");

        expect($operation)->not->toBeNull(
            "GET {$path} is not in the document at all, so nothing below this line checked "
            .'anything for it. Either the route was renamed or moved to another HTTP method, or '
            .'it was added without regenerating openapi.json.',
        );

        // Filtered to `in === 'query'` before the key-set comparison below: two of these three
        // routes (GET /plans/{plan}/members, GET /plans/{plan}/activity) ALSO carry a path
        // parameter in the same operation-level `parameters` array - a mix the single /plans
        // route this case generalised from never had reason to filter.
        $parameters = collect(Arr::wrap(data_get($operation, 'parameters')))
            ->filter(fn (mixed $parameter): bool => data_get($parameter, 'in') === 'query')
            ->keyBy(fn (mixed $parameter): string => (string) data_get($parameter, 'name'));

        expect($parameters->keys()->all())->toEqualCanonicalizing(
            array_keys($rules),
            "every rules() key of {$name} must be published as a query parameter on GET {$path}, and nothing else may be",
        );

        foreach ($rules as $field => $fieldRules) {
            $flat = array_map(
                fn (mixed $rule): string => is_string($rule) ? $rule : '',
                Arr::wrap($fieldRules),
            );

            $parameter = $parameters->get($field);

            expect(data_get($parameter, 'in'))->toBe(
                'query',
                "GET {$path}'s {$field} must be published in the query, not as a body field of a GET",
            );
            expect((bool) data_get($parameter, 'required'))->toBe(
                ! in_array('sometimes', $flat, true),
                "GET {$path}'s {$field} published `required` must follow its `sometimes` rule",
            );

            if ($field === 'limit') {
                expect(data_get($parameter, 'schema.maximum'))->toBe(
                    PageQuery::MAX_LIMIT,
                    "GET {$path}'s limit must publish the maximum it enforces, or a client cannot know a value past it is a 422",
                );
            }

            if ($field === 'status') {
                // `status` carries `nullable`, unlike `limit` and `cursor` - ListPlansRequest's
                // own docblock argues why. Scramble publishes a nullable Rule::in() with `null`
                // appended to the enum list itself, not as a bare enum with nullability expressed
                // some other way, so the expectation is derived from the same rule just
                // flattened above rather than restated as a literal that would fail against what
                // `nullable` actually publishes.
                $expectedStatusValues = in_array('nullable', $flat, true)
                    ? [...array_column(PlanStatus::cases(), 'value'), null]
                    : array_column(PlanStatus::cases(), 'value');

                expect(Arr::wrap(data_get($parameter, 'schema.enum')))->toEqualCanonicalizing(
                    $expectedStatusValues,
                    'the status filter must publish every PlanStatus case - cancelled is '
                    .'accepted and matches nothing (spec §7.3), so leaving it out would '
                    .'document a narrower filter than the one that runs',
                );
            }
        }

        expect(data_get($operation, 'requestBody'))->toBeNull(
            "GET {$path} must publish no request body at all: a documented body on a GET "
            .'describes a request no client can send.',
        );

        expect(data_get(openApiDocument(), "components.schemas.{$name}"))->toBeNull(
            "A GET route registers no component schema for its FormRequest, so {$name} must not "
            .'appear in components.schemas - and must not be added to the required-versus-optional '
            .'map above, whose first assertion is that the schema exists.',
        );
    }
});

it('publishes the cursor envelope on every paginated list, with meta.next_cursor by name', function (): void {
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
    //
    // DERIVED path set (B39a), the same derivation "publishes every list filter as a query
    // parameter and no request body" above uses: a paginated list is exactly a GET route whose
    // action declares a FormRequest. GET /plans/{plan}/invites is deliberately excluded by that
    // derivation - it is a GET route with NO FormRequest (a plain Illuminate\Http\Request),
    // because spec §6.3 does not paginate it: the 20/hour/plan invite limit and the 14-day TTL
    // already bound the list's size.
    $paths = [];

    foreach (documentedRoutes() as [$method, $path, $route]) {
        if ($method === 'get' && formRequestsFor($route) !== []) {
            $paths[] = $path;
        }
    }

    expect($paths)->not->toBe(
        [],
        'no GET route declares a FormRequest any more, so this case checked nothing at all - '
        .'either every paginated list was removed, or the derivation above stopped finding them',
    );

    foreach ($paths as $path) {
        $schema = data_get(openApiDocument(), "paths.{$path}.get.responses.200.content.application/json.schema");

        expect(Arr::wrap(data_get($schema, 'required')))->toEqualCanonicalizing(
            ['data', 'meta'],
            "GET {$path}: both envelope keys must be required - a client that has to test for meta cannot page",
        );
        expect(data_get($schema, 'properties.data.type'))->toBe('array', "GET {$path} must publish `data` as an array");
        expect(Arr::wrap(data_get($schema, 'properties.meta.required')))->toEqualCanonicalizing(
            ['next_cursor'],
            "GET {$path}: next_cursor must be present-but-nullable, never optional",
        );
        expect(Arr::wrap(data_get($schema, 'properties.meta.properties.next_cursor.type')))->toEqualCanonicalizing(
            ['string', 'null'],
            "GET {$path}: next_cursor must publish as string|null - {\"type\":\"null\"} means Scramble narrowed it from a literal null in the controller",
        );
    }
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

    // A floor equal to today's exact count, not merely non-empty: a document that dropped to one
    // schema would still pass a bare not-empty check while this traversal verified almost
    // nothing, which is the same "a floor left behind is slack" shape this test objects to
    // elsewhere. The literal below is the count - measured as count($schemas) against the
    // regenerated document, with zero slack by construction.
    //
    // Which tasks moved it, named rather than numbered: PlanResource (Task 15), CreatePlanRequest
    // (Task 16), UpdatePlanRequest (Task 17), PlanMemberResource (Task 21) and
    // ChangeMemberRoleRequest (Task 22 - its single `role` key carries no /** @query */, so it
    // publishes a request BODY schema, unlike ListPlanMembersRequest and ListPlansRequest, whose
    // keys publish as parameters[n] and which therefore appear nowhere in components.schemas).
    // The floor is raised in the same commit that adds a schema: a floor left behind would let a
    // dropped schema clear it silently, which is exactly the slack this comment warns against.
    //
    // DELETE /plans/{plan}/members/{user} adds NONE, and that was measured rather than assumed:
    // its action takes a plain Illuminate\Http\Request with no rules() to read, and returns a
    // bodyless 204 with no Resource, so Scramble has neither a request schema nor a response
    // schema to register. count($schemas) against the regenerated document is unchanged.
    //
    // InvitePreviewResource (GET /invites/{token}) adds ONE, raising the floor again.
    //
    // Task 26 adds TWO more: InvitePlanMemberRequest (a body FormRequest - its fields carry no
    // /** @query */, so it publishes like ChangeMemberRoleRequest rather than like
    // ListPlanMembersRequest) and PlanInviteCreatedResource, reachable from a route for the
    // first time (POST /plans/{plan}/invites). The floor is raised again, in this same commit.
    //
    // This task adds ONE more: JoinPlanRequest, for the same reason InvitePlanMemberRequest did
    // - its single `token` key carries no /** @query */, so it publishes a request body schema.
    // PlanResource, this route's response, is already counted (Task 15) and adds nothing new.
    //
    // This task adds ONE more: PlanInviteResource, reachable from a route for the first time
    // (GET /plans/{plan}/invites). The DELETE action adds none, for the same measured reason
    // DELETE /plans/{plan}/members/{user} did not: it takes a plain Illuminate\Http\Request
    // with no rules() to read and returns a bodyless 204 with no Resource.
    //
    // This task adds ONE more: PlanActivityResource, reachable from a route for the first time
    // (GET /plans/{plan}/activity). ListPlanActivityRequest adds none: its two rules() keys both
    // carry /** @query */, so they publish as parameters[n] exactly like ListPlansRequest and
    // ListPlanMembersRequest, never as a components.schemas entry.
    expect(count($schemas))->toBeGreaterThanOrEqual(
        19,
        'components.schemas published fewer schemas than this traversal expects to check, so '
        .'some of them were skipped rather than verified.',
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

it('publishes no *Resource schema with a $ref to a domain enum', function (): void {
    // D-R2 / baseline S-9: EnumTransformer::addEnumDescription() is unconditional, so a native
    // enum typed on any Resource field leaks that enum's whole class docblock as the schema
    // description under every enum_cases_description_strategy value. The case above already
    // catches the "no properties" half of the same defect for every schema in the document;
    // this one catches the $ref itself and names the exact Resource and field, which "some
    // schema has no properties" cannot.
    //
    // DERIVED from the document, not a hand list of six names: a walk over a literal array like
    // ['PlanResource', 'PlanMemberResource', ...], read with a `[]` default for a name that is
    // absent or misspelled, silently checks only whichever of those names the document happens
    // to contain and reports success either way - with the rest contributing nothing and nobody
    // able to tell from the result. Filtering components.schemas's own keys by /Resource$/
    // instead means every name this walks is guaranteed to exist in the document - it cannot
    // "walk" a name that was never there. The derived set grows as more of this section's
    // Resources become reachable from a route (no snapshot of its current members is kept here
    // - see the floor assertion right below, which IS re-measured on every task that moves it);
    // no edit is required here for the $ref check itself when that happens, only for the floor.
    $resourceSchemaNames = array_values(array_filter(
        array_keys(Arr::wrap(data_get(openApiDocument(), 'components.schemas'))),
        fn (string $name): bool => (bool) preg_match('/Resource$/', $name),
    ));

    // >= 8, not merely non-empty, for the reason the components.schemas case above carries a
    // floor rather than a bare not-empty check: measured today (InvitePreviewResource,
    // PlanActivityResource, PlanInviteCreatedResource, PlanInviteResource, PlanMemberResource,
    // PlanResource, SessionResource, UserResource - this task raised this floor from seven,
    // having routed PlanActivityResource for the first time). A number about a literal
    // somewhere else in the file decays exactly like one about the file itself, so none is
    // quoted here. A document that lost every *Resource schema would still pass a bare
    // not-empty check while this walk verified nothing; growing the floor is this task's
    // obligation whenever a Plan Resource newly starts being returned by a route.
    expect(count($resourceSchemaNames))->toBeGreaterThanOrEqual(
        8,
        'fewer *Resource schemas are published than this walk expects, so it is checking almost '
        .'nothing - either the suffix filter stopped matching or a schema genuinely vanished',
    );

    // Not "any $ref" - measured, by running this test before writing this comment:
    // SessionResource.user is a GENUINE $ref to UserResource, a nested Resource and not an
    // enum, and the derived walk above (unlike a six-name hand list restricted to this
    // section's own Resources, none of which nests another Resource) finds it. So "any $ref"
    // is the wrong condition once the walk covers the whole document rather than six names
    // chosen because none of them nests another Resource. A $ref LEAKS A DOMAIN ENUM
    // specifically when its target component has no `properties` - exactly the signature the
    // "publishes no component schema without properties" case above polices from the other
    // side - so that is the condition checked here, not the mere presence of a $ref.
    $refs = [];

    foreach ($resourceSchemaNames as $name) {
        $properties = Arr::wrap(data_get(openApiDocument(), "components.schemas.{$name}.properties"));

        foreach ($properties as $field => $property) {
            if (! is_array($property) || ! array_key_exists('$ref', $property)) {
                continue;
            }

            $refTarget = (string) $property['$ref'];
            $refSchemaName = substr($refTarget, strrpos($refTarget, '/') + 1);
            $refSchema = data_get(openApiDocument(), "components.schemas.{$refSchemaName}");

            if ($refSchema !== null && ! array_key_exists('properties', Arr::wrap($refSchema))) {
                $refs[] = "{$name}.{$field} => {$refTarget}";
            }
        }
    }

    expect($refs)->toBe([], 'a $ref to a schema with no `properties` means a field is typed as '
        .'the native enum instead of publishing ->value, and that enum\'s class docblock is now '
        .'the schema description every client sees');

    // This section owns six Resources (spec §6.1), named here explicitly rather than counted.
    // Measured against app/Http/Resources/Api/V1/Plans/: all six classes already exist, but
    // Scramble registers a component schema only for a Resource reachable from a route. All six
    // are reachable now: PlanResource (GET /plans/{plan}, POST /plans, GET /plans),
    // PlanMemberResource (GET /plans/{plan}/members), PlanInviteCreatedResource (POST
    // /plans/{plan}/invites), InvitePreviewResource (GET /invites/{token}), PlanInviteResource
    // (GET /plans/{plan}/invites) and - this task's own addition, the last of the six -
    // PlanActivityResource (GET /plans/{plan}/activity).
    //
    // The assertion below is what a misspelling break step targets: the intersection is computed
    // against $resourceSchemaNames, which comes from the document, so a typo below in any name
    // drops it out of the intersection and reds here - proving the correspondence is checked
    // rather than assumed.
    $sixResourceNames = [
        'PlanResource', 'PlanMemberResource', 'PlanInviteResource',
        'PlanInviteCreatedResource', 'PlanActivityResource', 'InvitePreviewResource',
    ];

    expect(array_values(array_intersect($sixResourceNames, $resourceSchemaNames)))->toBe(
        ['PlanResource', 'PlanMemberResource', 'PlanInviteResource', 'PlanInviteCreatedResource', 'PlanActivityResource', 'InvitePreviewResource'],
        'all six of this section\'s Resources should be reachable from a route now, and each '
        .'must be found by its exact name - if this fails with fewer entries, a routed Resource '
        .'stopped being routed or its name above no longer matches the document',
    );
});
