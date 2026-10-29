<?php

declare(strict_types=1);

use App\Application\Auth\InvalidCredentials;
use App\Application\Auth\InvalidPasswordResetToken;
use App\Application\Auth\InvalidRefreshToken;
use App\Application\Plans\ActorEmailNotVerified;
use App\Application\Plans\InviteNotUsable;
use App\Application\Plans\NotMessageAuthor;
use App\Application\Plans\OwnerCannotLeave;
use App\Application\Plans\OwnerInviteNotAcceptable;
use App\Application\Plans\PlanInviteNotFound;
use App\Application\Plans\PlanMemberNotFound;
use App\Application\Plans\PlanMessageNotFound;
use App\Application\Plans\PlanNotEditable;
use App\Application\Plans\PlanNotInvitable;
use App\Application\Plans\PlanNotVisible;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;

/**
 * The one place the whole refusal vocabulary is pinned, byte for byte.
 *
 * Spec §5.1's rule is "one exception, one closure, one body", and spec §8.1's security property
 * rests on it: a refusal that answers two different bodies for the same situation is a
 * distinguisher, which is the uniform 404 exists to remove. bootstrap/app.php holds one
 * $exceptions->render() closure and one dontReport() list for every application exception, built
 * by three sections working in parallel plus, since, the AcceptInvite lock-race fix - so "one
 * closure per exception" and "exactly the right thing is reported" are invariants over work done
 * in several places and checked in none of them. The obligation in the third and fourth tests
 * below is derived from the exception classes on disk and from the real handler's own state,
 * never from a list of sections or a regex over bootstrap/app.php's source text.
 *
 * FOURTEEN application exceptions exist as of the chat feature's edit/delete-message task, not
 * twelve: the three inherited from Wave 1a, the eight this section's own vocabulary table
 * names, OwnerInviteNotAcceptable, which protects spec §9.1's one-owner invariant on the
 * invite-accept path (see its own docblock for the human ruling) and is deliberately NOT in
 * dontReport() - unlike every other refusal in this file, it is meant to be noticed, not
 * absorbed - and two more the chat feature added, PlanMessageNotFound and NotMessageAuthor.
 * PlanMessageNotFound is an ordinary silenced 404, the same shape as PlanMemberNotFound and
 * PlanInviteNotFound before it; NotMessageAuthor repeats OwnerInviteNotAcceptable's own shape -
 * deliberately reportable, for the reason its own docblock gives - so dontReportClasses() now
 * splits twelve-and-two rather than eleven-and-one. The fourth test below is written to
 * discover that split from the real handler rather than to assume every tabulated exception is
 * silenced, which was true the day this section was drafted and stopped being true before this
 * task ran.
 *
 * Every body below is compared as a STRING, never with assertJsonPath. A closure's JsonResponse
 * encodes with options 0 (JsonResponse's own constructor default, which overwrites Symfony's
 * default encoding options before the parent constructor runs); Laravel's own handler encodes
 * with JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES (Handler::prepareJsonResponse()).
 * assertJsonPath('message', 'Plan not found.') passes against both, so it cannot tell
 * `throw new PlanNotVisible` from `abort(404, 'Plan not found.')` - and the second of those has
 * no dontReport() entry and logs every non-member probe at ERROR.
 *
 * Exercised through the container-bound ExceptionHandler rather than through a route, for the
 * reason tests/Feature/Auth/ExceptionReportingTest.php gives about report(): the behaviour under
 * test is bootstrap/app.php, and the real handler is what reads it. Handler::render() reaches
 * its render-callback dispatch before any branch that consults the request, so the $request
 * below is a placeholder and the Accept header is irrelevant here - verified against the
 * installed Illuminate\Foundation\Exceptions\Handler by reading renderViaCallbacks()'s call
 * site inside render(), which runs before unauthenticated(), convertValidationExceptionToResponse()
 * and renderExceptionResponse(). The routes are covered by the three derived suites this
 * section's other tasks add under tests/Feature/Security/.
 *
 * No RefreshDatabase: nothing here writes a row. No forgetAuthenticatedUser(): none of the five
 * it() blocks below makes an HTTP request or switches actors - every one calls
 * app(ExceptionHandler::class)->render() or ->report() directly, so there is no cached Sanctum
 * guard user for that call to matter against. Checked by inspection of this file's own five
 * it() blocks, not assumed.
 */

/**
 * Every application exception, with the status and the exact bytes bootstrap/app.php must
 * answer for it.
 *
 * Fourteen rows: Wave 1a's three, this section's own eight, OwnerInviteNotAcceptable, and the
 * chat feature's own PlanMessageNotFound and NotMessageAuthor - see this file's docblock for
 * why rows exist here that the section originally drafting this file did not expect.
 * "Exactly one body per exception" is a property of the whole seam regardless of which task's
 * work produced each row: a closure mistyped `fn (InvalidRefreshToken $e)` would be shadowed by
 * the Wave 1a closure registered above it and would never run, and nothing but this table would
 * say so.
 *
 * @return array<class-string<RuntimeException>, array{int, string}>
 */
function refusalBodyTable(): array
{
    return [
        InvalidCredentials::class => [401, '{"message":"These credentials do not match our records."}'],
        InvalidRefreshToken::class => [401, '{"message":"The refresh token is not valid."}'],
        InvalidPasswordResetToken::class => [422, '{"message":"This password reset link is no longer valid. Request a new one.","errors":{"token":["This password reset link is no longer valid. Request a new one."]}}'],
        PlanNotVisible::class => [404, '{"message":"Plan not found."}'],
        PlanMemberNotFound::class => [404, '{"message":"That person is not a member of this Plan."}'],
        PlanInviteNotFound::class => [404, '{"message":"That invite is no longer available."}'],
        // The chat feature's own {message} 404, the same shape as PlanMemberNotFound and
        // PlanInviteNotFound immediately above it: silenced, one body, for a malformed id, an
        // unknown one, or one belonging to a different Plan.
        PlanMessageNotFound::class => [404, '{"message":"That message is no longer available."}'],
        InviteNotUsable::class => [404, '{"message":"This invite link is no longer valid. Ask whoever invited you for a new one."}'],
        PlanNotEditable::class => [409, '{"message":"This Plan has been archived."}'],
        PlanNotInvitable::class => [409, '{"message":"This Plan is private, so nobody else can be invited to it."}'],
        OwnerCannotLeave::class => [403, '{"message":"Transfer ownership or archive this Plan before leaving it."}'],
        // Compact, because it comes from a render() closure - deliberately the same status and
        // the same sentence EnsureEmailIsVerified's abort(403, ...) produces, so that the one
        // route whose verification gate lives in the Application layer is indistinguishable to
        // a JSON client from the routes where the middleware does it. The middleware's version
        // goes through Handler::prepareJsonResponse() and is PRETTY-PRINTED, so the two are not
        // byte-identical.
        ActorEmailNotVerified::class => [403, '{"message":"Your email address is not verified."}'],
        // The chat feature's second addition, and a second odd one out alongside
        // OwnerInviteNotAcceptable below: an authorship check, not a role check, so
        // AuthorizationException's generic pretty-printed body would be indistinguishable from
        // a role refusal. UNLIKE every row above except OwnerInviteNotAcceptable, this class
        // carries no dontReport() entry - see NotMessageAuthor's own docblock.
        NotMessageAuthor::class => [403, '{"message":"You can only edit or delete your own messages."}'],
        // The odd one out: see this file's docblock and OwnerInviteNotAcceptable's own
        // docblock. 409 for the same reason PlanNotEditable and PlanNotInvitable are 409 - a
        // well-formed request refused for a state reason, not a validation reason - but, UNLIKE
        // every other row above except NotMessageAuthor, this class carries no dontReport()
        // entry, which the fourth test below asserts rather than assumes.
        OwnerInviteNotAcceptable::class => [409, '{"message":"This invite cannot be accepted because it would make you a second owner of this Plan."}'],
    ];
}

/**
 * The exception class each registered render closure is typed for, in registration order.
 *
 * Read off the handler's own $renderCallbacks rather than off bootstrap/app.php's source text:
 * the claim is about what is REGISTERED, and a regex over the file would also have to model
 * formatting. A callback whose first parameter carries no class type is recorded as the literal
 * '(untyped)' rather than skipped, so a closure written `fn ($e) => ...` shows up as a missing
 * entry for its class instead of disappearing.
 *
 * Narrowed with a try/catch around the ReflectionProperty construction, not with
 * `instanceof Illuminate\Foundation\Exceptions\Handler`: CollisionServiceProvider::register()
 * rebinds the ExceptionHandler contract to its own wrapper whenever
 * `runningInConsole() && ! runningUnitTests()`, and PHPStan's container-binding analysis cannot
 * see that the condition is false under Pest - it infers the wrapper type unconditionally and
 * then reports `instanceof Handler` as always false (and everything after it as unreachable).
 * At RUNTIME the condition IS false here, so the real singleton is the one this function needs -
 * but a static `instanceof` check on that inferred type cannot be made to agree, and this project
 * forbids widening the inference with an inline @var. Reflection's own existence check is what
 * this function actually needs: if the bound object has no $renderCallbacks property,
 * `new ReflectionProperty(...)` throws, and that is caught and rethrown with a message naming
 * the real defect (Laravel changed the handler) rather than asserting a class relationship
 * PHPStan cannot verify and this file does not need.
 *
 * @return list<string>
 */
function registeredRenderCallbackTypes(): array
{
    $handler = app(ExceptionHandler::class);

    try {
        $property = new ReflectionProperty($handler, 'renderCallbacks');
    } catch (ReflectionException $reflectionException) {
        throw new RuntimeException(
            'The bound ExceptionHandler carries no $renderCallbacks property; Laravel changed the handler.',
            previous: $reflectionException,
        );
    }

    $callbacks = $property->getValue($handler);

    if (! is_array($callbacks)) {
        throw new RuntimeException('Handler::$renderCallbacks is not an array; Laravel changed the handler.');
    }

    $types = [];

    foreach ($callbacks as $callback) {
        if (! $callback instanceof Closure) {
            $types[] = '(untyped)';

            continue;
        }

        $parameters = (new ReflectionFunction($callback))->getParameters();

        if ($parameters === []) {
            $types[] = '(untyped)';

            continue;
        }

        $type = $parameters[0]->getType();

        $types[] = $type instanceof ReflectionNamedType ? $type->getName() : '(untyped)';
    }

    return $types;
}

/**
 * The classes Handler::shouldntReport() will treat as silenced, read off the real handler's own
 * $dontReport property rather than off bootstrap/app.php's dontReport([...]) call - for the same
 * reason registeredRenderCallbackTypes() reads $renderCallbacks above instead of parsing source
 * text: the claim is about what is WIRED into the container-bound handler that actually answers
 * report(), not about what one call in bootstrap/app.php says. dontReport() is Laravel's public
 * alias for ignore(), which merges into $dontReport with array_unique() - additive, so a missing
 * entry is silent rather than a conflict, which is exactly the defect this file's fourth test
 * exists to catch.
 *
 * Narrowed the same way registeredRenderCallbackTypes() above is, and for the same reason: see
 * that function's comment for why this is a try/catch around the ReflectionProperty
 * construction rather than an `instanceof Handler` check.
 *
 * Rebuilt into a fresh list via a foreach, one entry validated as a string at a time, rather
 * than returned directly: `is_array($classes)` alone narrows to `array<mixed, mixed>`, which
 * satisfies neither this function's own `list<string>` return type nor PHPStan's level-8 check
 * for one, and a cast or an inline @var would silence that instead of proving it.
 *
 * @return list<string>
 */
function dontReportClasses(): array
{
    $handler = app(ExceptionHandler::class);

    try {
        $property = new ReflectionProperty($handler, 'dontReport');
    } catch (ReflectionException $reflectionException) {
        throw new RuntimeException(
            'The bound ExceptionHandler carries no $dontReport property; Laravel changed the handler.',
            previous: $reflectionException,
        );
    }

    $classes = $property->getValue($handler);

    if (! is_array($classes)) {
        throw new RuntimeException('Handler::$dontReport is not an array; Laravel changed the handler.');
    }

    $names = [];

    foreach ($classes as $class) {
        if (! is_string($class)) {
            throw new RuntimeException('Handler::$dontReport contains a non-string entry; Laravel changed the handler.');
        }

        $names[] = $class;
    }

    return $names;
}

/**
 * Every class under app/Application that is a Throwable, discovered on disk.
 *
 * Filters on Throwable, not on is_subclass_of($class, RuntimeException::class) - the latter is
 * B30a's defect, recorded against this task: a mutation declaring
 * `final class X extends DomainException {}` is invisible to a RuntimeException filter (the two
 * are siblings under Exception, and neither extends the other), so $onDisk would silently stay
 * at the old count and every assertion built on it would stay green. Filtering on Throwable
 * closes that; the separate obligation that every discovered class IS a RuntimeException is
 * asserted inside the consuming test, one class at a time, so a DomainException-shaped mutation
 * reds with a message naming the offending class rather than vanishing into an array diff.
 *
 * This is still the half of the gate that breaks when the system GROWS: a thirteenth application
 * exception added by any later task fails the completeness assertion until somebody decides its
 * status, its body and whether it belongs in dontReport(). Built from the autoloader rather than
 * from a regex over the source, so a class declared on an unusual line still counts.
 *
 * Recursive rather than a glob of app/Application/<Context>/*.php: the two-levels-deep shape is
 * a convention, not a rule, and a class one level deeper would otherwise be skipped - which is
 * the silent-pass shape this whole file exists to refuse.
 *
 * @return list<string>
 */
function applicationExceptionClasses(): array
{
    $classes = [];

    foreach (phpFilesUnder(app_path('Application')) as $path) {
        $relative = substr($path, strlen(app_path()) + 1, -4);
        $class = 'App\\'.str_replace('/', '\\', $relative);

        if (class_exists($class) && is_subclass_of($class, Throwable::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * Every .php file under $directory, absolute paths, sorted.
 *
 * @return list<string>
 */
function phpFilesUnder(string $directory): array
{
    $paths = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths);

    return $paths;
}

// $source with every comment token - // line, /* */ block, and /** */ doc - blanked to
// same-length whitespace, so a needle appearing only in prose can never match what this
// function returns while a needle in real code passes through untouched.
//
// This is the fix for the defect recorded against this task under the heading "the scan
// matches prose, not just code": app/Application/Plans/ActorEmailNotVerified.php's own docblock
// contains the literal text `abort(403, ...)`, written there deliberately and endorsed on
// review, to record that the exception's render() body is byte-identical to the middleware
// sentence it stands in for. A bare str_contains($source, 'abort(') cannot tell that line from a
// controller actually calling abort(404, ...), and this project has already hit that exact
// confusion once before on this wave (the architecture suite's plan_activity needle reding a
// file for merely discussing the table). "Match outside comments" is the default here, not a
// refinement layered on top of a needle scan that still reads prose.
//
// token_get_all() is PHP's own tokenizer, already part of this runtime - using it is not adding
// a second parser to maintain, it is using the one the engine itself already has to parse this
// exact file. T_COMMENT covers both the // and the /* */ forms; T_DOC_COMMENT covers /** */.
// One pass over those two constants is every comment shape PHP has.
//
// Disclosed limit (F4): this still cannot tell a real call from a string literal that happens
// to contain one of the five needles - `$x = "abort(";` would still match. Nothing under app/
// does that today (checked: none of the five needles appears inside a string literal anywhere
// under app/ at this task's start commit), and a string literal is not a comment, so excluding
// comments is the fix for the defect actually found and not a claim to catch every conceivable
// one.
function sourceOutsideComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $id = $token[0];
            $text = $token[1];

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $code .= str_repeat(' ', strlen($text));

                continue;
            }

            $code .= $text;

            continue;
        }

        $code .= $token;
    }

    return $code;
}

it('renders exactly one compact body for every application exception', function (): void {
    $handler = app(ExceptionHandler::class);

    // A placeholder: Handler::render() consults the request only AFTER its render-callback
    // dispatch has had its chance, so nothing about this request can change the answer. See
    // this file's docblock.
    $request = Request::create('/api/v1/plans', 'GET');

    $checked = 0;

    foreach (refusalBodyTable() as $class => [$status, $body]) {
        $response = $handler->render($request, new $class);

        expect($response->getStatusCode())->toBe($status, "{$class} must render {$status}");
        expect($response->getContent())->toBe($body, "{$class} must render exactly {$body}");

        $checked++;
    }

    // The loop is derived from a literal table in this file, so this cannot be the vacuous
    // shape - but the counter is here because every derived loop in this section carries one,
    // and because it names the arithmetic: Wave 1a's 3, plus this section's own 8, plus the
    // AcceptInvite lock-race fix's 1, plus the chat feature's own 2 (PlanMessageNotFound,
    // NotMessageAuthor).
    expect($checked)->toBe(14);
});

it('registers exactly one render closure for every application exception', function (): void {
    $types = registeredRenderCallbackTypes();

    $checked = 0;

    foreach (array_keys(refusalBodyTable()) as $class) {
        // array_keys() with a search value and $strict returns one entry per match, so this
        // counts closures rather than merely asking whether one exists. Handler::renderable()
        // appends and render-callback dispatch returns on the first match, so a second closure
        // for a class is dead code: 2 here is as much a defect as 0.
        expect(count(array_keys($types, $class, true)))
            ->toBe(1, "bootstrap/app.php must register exactly one render() closure typed {$class}");

        $checked++;
    }

    expect($checked)->toBe(14);
});

it('covers every application exception class on disk', function (): void {
    $onDisk = applicationExceptionClasses();

    // B30a's fix, the second half: applicationExceptionClasses() above only proves "a
    // Throwable exists here". This proves "and it is the right KIND of Throwable" - separately,
    // one class at a time, so a DomainException-shaped mutation reds HERE, by name, rather than
    // surviving as an extra entry the array-equality check below would have to notice on its
    // own. (It would also be caught there - $onDisk would carry an extra class not in
    // $tabulated - but failing here first is what makes the mutation's red attributable to the
    // base-class defect specifically, not to "something about completeness".)
    foreach ($onDisk as $class) {
        expect(is_subclass_of($class, RuntimeException::class))
            ->toBeTrue("{$class} is a Throwable under app/Application but does not extend RuntimeException; every application exception in this codebase must.");
    }

    $tabulated = array_keys(refusalBodyTable());
    sort($tabulated);

    // Both directions in one assertion: a class with no row fails, and a row for a class that
    // no longer exists fails. The two sides cannot be emptied together, because $tabulated is
    // a literal in this file and $onDisk is read off the filesystem.
    expect($onDisk)->toBe($tabulated);
    expect($onDisk)->toHaveCount(14);
});

it('reports none of the refusals marked silent in bootstrap/app.php, reports the ones that are not, and still reports a genuine error', function (): void {
    $handler = app(ExceptionHandler::class);

    // Derived from the real handler, not assumed: every exception in refusalBodyTable() used to
    // be silenced, until OwnerInviteNotAcceptable arrived deliberately unsilenced (see this
    // file's docblock), and the chat feature's NotMessageAuthor repeated that same shape.
    // Splitting against dontReportClasses() - the handler's own $dontReport - rather than
    // hand-partitioning the table is what makes this test notice a FIFTEENTH exception that
    // repeats the same shape, instead of assuming the split stays "twelve and two" forever.
    $allClasses = array_keys(refusalBodyTable());
    $silenced = array_values(array_intersect($allClasses, dontReportClasses()));
    $reportable = array_values(array_diff($allClasses, dontReportClasses()));

    $logs = captureLogs();

    $checked = 0;

    foreach ($silenced as $class) {
        $handler->report(new $class);

        $checked++;
    }

    expect($checked)->toBe(12);
    expect(capturedLogRecords($logs))->toBe([]);

    // Exactly two application exceptions are deliberately reportable, in refusalBodyTable()'s
    // own order: NotMessageAuthor before OwnerInviteNotAcceptable. If this list is shorter,
    // one of the two has regained a dontReport() entry it must not have (their own docblocks
    // explain why); if it holds more than two, a new exception has repeated the same shape
    // without this test being told.
    expect($reportable)->toBe([NotMessageAuthor::class, OwnerInviteNotAcceptable::class]);

    foreach ($reportable as $class) {
        $handler->report(new $class);
    }

    $afterReportable = capturedLogRecords($logs);

    expect($afterReportable)->toHaveCount(2);
    // Empty on both, exactly like PlanNotVisible's in A8's break step: `new NotMessageAuthor`
    // and `new OwnerInviteNotAcceptable` with no constructor argument carry no message, and
    // Handler::reportThrowable() logs $e->getMessage() verbatim.
    expect($afterReportable[0]['level'])->toBe('ERROR');
    expect($afterReportable[0]['message'])->toBe('');
    expect($afterReportable[1]['level'])->toBe('ERROR');
    expect($afterReportable[1]['message'])->toBe('');

    // The half that matters most, and the reason the assertions above are not vacuous: a
    // dontReport() list is exactly the kind of change that silences more than intended, and an
    // empty record list also agrees with a logging pipeline that captures nothing at all.
    $handler->report(new RuntimeException('a genuinely unexpected failure'));

    $unexpected = capturedLogRecords($logs);

    expect($unexpected)->toHaveCount(3);
    expect($unexpected[2]['level'])->toBe('ERROR');
    expect($unexpected[2]['message'])->toBe('a genuinely unexpected failure');
});

it('leaves every refusal status to bootstrap/app.php', function (): void {
    $forbidden = [
        // Goes through Laravel's handler, which PRETTY-PRINTS. A controller writing
        // abort(404, 'Plan not found.') answers a body with the same JSON path and different
        // bytes, from a code path with no dontReport() entry.
        'abort(',
        // AuthorizationException::withStatus(404) takes a path that publishes a third body
        // again: pretty-printed {"message": "Not Found"}.
        'withStatus(',
        // All three throw ModelNotFoundException, which Laravel maps to a 404 whose message is
        // "No query results for model [App\Models\Plan] <id>" - a fourth body, and one that
        // leaks the model class and the identifier that was probed.
        'findOrFail(',
        'firstOrFail(',
        '->sole(',
    ];

    // Disclosed false negative (F4), not fixed here: this family does not include abort_if(,
    // abort_unless(, a bare `throw new \Illuminate\Http\Exceptions\HttpResponseException`, or
    // `return response()->json([...], 404)`. Recorded against this task and left open - adding
    // those needles, or an arch() rule over the HTTP exception types, is a separate, larger
    // change (an arch() rule over AuthorizationException in particular has a measured legitimate
    // casualty: the members and invites sections throw a bare AuthorizationException for every
    // role failure, which is the intended mechanism) and is not part of what this task was asked
    // to close.
    $files = phpFilesUnder(app_path());

    // The floor that stops a broken scan from agreeing with a clean codebase. 167 is this
    // task's own measured count at its start commit (3afa221), not a pin - re-derive it rather
    // than carry it forward, exactly like every other count in this file.
    expect(count($files))->toBeGreaterThanOrEqual(167);

    $offences = [];
    $read = 0;

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        // A positive control for the reader itself, not decoration: the assertion below is
        // ALSO satisfied by a loop that read nothing, because (string) false is '' and
        // str_contains('', $needle) is false for every needle. declare(strict_types=1) is on
        // every file under app/ (arch('strict types everywhere') keeps it that way), so every
        // file read must contain this string.
        if (str_contains($source, 'declare(strict_types=1);')) {
            $read++;
        }

        // The fix for B30c's root cause: scan the source with every comment blanked out, so a
        // docblock that MENTIONS one of these constructs - like
        // ActorEmailNotVerified.php's own, which records that its render() body matches
        // EnsureEmailIsVerified's abort(403, ...) byte for byte - cannot trip a needle meant for
        // code that CALLS one. See sourceOutsideComments()'s own comment for what this still
        // cannot catch.
        $codeOnly = sourceOutsideComments($source);

        foreach ($forbidden as $needle) {
            if (str_contains($codeOnly, $needle)) {
                $offences[] = substr($path, strlen(base_path()) + 1).': '.$needle;
            }
        }
    }

    expect($read)->toBe(count($files));

    sort($offences);

    // Clean baseline, established by this fix: ActorEmailNotVerified.php's docblock contains
    // the literal text `abort(403, ...)`, and a scan over raw source text sees it ($offences
    // would hold one entry before any mutation runs). Scanning outside comments is what makes
    // this assertion satisfiable on arrival without touching that docblock, which is correct and
    // endorsed prose, not a defect to fix by rewording it.
    expect($offences)->toBe([]);
});
