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
 *
 * THE DECLARED ORDER OF THE RULES DOES NOT MATTER, and an earlier version of this class said
 * it did. RuleSetToSchemaTransformer::normalizeAndPrioritizeRules() ends with
 * ->sortByDesc(rulesSorter()), and rulesSorter() returns -2 for every non-string rule, -1 for
 * an unprioritised string and a positive score for one in RULES_PRIORITY (which includes
 * 'string'). A rule object therefore always sorts last and always reduces last, whatever the
 * array says. Measured rather than read: declaring Password::min(12) FIRST, ahead of
 * 'required' and 'string', produces a BYTE-IDENTICAL document.
 *
 * What would genuinely stop this working is a rename of getRule(), is() or appliedRules()
 * upstream, each of which leaves a valid document that simply omits minLength - which the
 * byte gate cannot see, and which tests/Feature/Contract/OpenApiContractTest.php's
 * password-minimum case is there to catch.
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

        // The $previous instanceof StringType guard is a type narrowing for PHPStan and
        // NOTHING ELSE, which is the opposite of what this comment used to claim. It said that
        // dropping the 'string' rule would leave the schema alone; measured, it does not.
        // Dedoc\Scramble\Support\Generator\Types\UnknownType EXTENDS StringType, so the
        // guard passes for the untyped case too and setMin() is applied to an UnknownType.
        // Probed: removing 'string' from RegisterRequest's password rule still produced
        // {"type": "string", "minLength": 12} - the generator exited non-zero only because
        // --fail-on-unknown refused to export an UnknownType. The real safety is that flag, in
        // bin/generate-openapi.sh, not this line.
        //
        // The $password instanceof Password half is load-bearing: NormalizedRule::getRule()
        // is declared mixed, and appliedRules() below exists only on Password.
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
