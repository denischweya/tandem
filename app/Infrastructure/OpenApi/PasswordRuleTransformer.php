<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenApi;

use Dedoc\Scramble\Contracts\RuleTransformer;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;
use Illuminate\Validation\Rules\Password;

/**
 * Publishes Password::min(n) as `minLength: n`.
 *
 * Scramble maps the *string* rule `min:12` to minLength (RulesMapper::min() calls
 * StringType::setMin()), but it ships no transformer for Illuminate's Password rule OBJECT
 * and none of its eight default transformers matches one. So `Password::min(12)` documented
 * nothing, and the published contract was weaker than the enforced one: a client generating
 * validation from openapi.json - tandem-shared's Zod schema among them - would not enforce
 * twelve characters at all.
 *
 * This reads the constraint off the rule object itself through its public appliedRules()
 * accessor, so there is exactly ONE source for "twelve characters": the Password instance in
 * RegisterRequest::rules(). Changing min(12) to min(16) changes the document with it. The
 * two alternatives were both rejected for drifting:
 *
 *   - adding a bare 'min:12' string rule alongside Password::min(12) - two declarations of
 *     one constraint, free to disagree;
 *   - spreading the rule (`...Password::min(12)`), which works only by accident: Password
 *     is IteratorAggregate and yields ['string', 'min:12'], but getIterator() omits
 *     uncompromised(), mixedCase(), letters(), numbers() and symbols() entirely. The day
 *     someone adds ->uncompromised() - which RegisterRequest::rules() explicitly anticipates
 *     - a spread would silently discard the breach check with no test failing.
 *
 * max is carried too, for when a max is ever set; the rest of appliedRules() (composition
 * flags, uncompromised, customRules) has no OpenAPI equivalent and is deliberately ignored.
 */
final class PasswordRuleTransformer implements RuleTransformer
{
    public function shouldHandle(NormalizedRule $rule): bool
    {
        return $rule->is(Password::class);
    }

    public function toSchema(Type $previous, NormalizedRule $rule, RuleTransformerContext $context): Type
    {
        $password = $rule->getRule();

        // The 'string' rule alongside it is what makes $previous a StringType; if a future
        // rule list drops that, there is no minLength to set and the schema is left alone
        // rather than being coerced into a shape the rules do not describe.
        if (! $password instanceof Password || ! $previous instanceof StringType) {
            return $previous;
        }

        $applied = $password->appliedRules();

        $min = $applied['min'] ?? null;
        $max = $applied['max'] ?? null;

        if (is_int($min)) {
            $previous->setMin($min);
        }

        if (is_int($max)) {
            $previous->setMax($max);
        }

        return $previous;
    }
}
