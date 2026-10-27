<?php

declare(strict_types=1);

namespace PhpStanRules\DomainPurity;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;
use PhpParser\Node\UseItem;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function class_exists;
use function count;
use function enum_exists;
use function implode;
use function in_array;
use function interface_exists;
use function sprintf;
use function strtolower;
use function trait_exists;

/**
 * Closes the measured gap in tests/Architecture/DomainPurityTest.php's allow-list:
 * `toOnlyUse(['App\Domain', 'DateTimeImmutable', 'DateTimeZone', 'InvalidArgumentException'])`
 * ADMITS EVERY PHP INTERNAL CLASS, because pest-plugin-arch's dependency walker does not count
 * an internal class as a "use" at all. Reducing that rule to `toOnlyUse(['App\Domain'])` left
 * the whole suite green, and a domain class using `DateTime`, `ArrayObject`, `SplQueue`,
 * `Closure` and `RuntimeException` was flagged for none of them.
 *
 * So the rule named "uses nothing but itself and a small, explicit set of PHP primitives" was
 * really enforcing "uses nothing USERLAND but itself", and the three names in its allow-list
 * were decoration rather than a limit. That matters beyond tidiness: mutable `DateTime` in
 * app/Domain would pass silently, and spec §6.1 promises the availability engine strict purity
 * over exactly the DST and midnight-boundary arithmetic a mutable date type gets wrong.
 *
 * WHAT THIS CHECKS. Every single-segment class name written anywhere in app/Domain - which is
 * what a reference to a global-namespace class looks like after name resolution, whether it
 * arrived through `use DateTime;` or as an inline `\DateTime` - must be one of the names
 * ALLOWED below. Namespaced names are left alone: those the Pest allow-list does catch, and
 * duplicating it here would mean two lists free to disagree.
 *
 * EVERY CLASS POSITION IS ENUMERATED BY HAND, which looks like more code than it should and is
 * not. The first version of this rule registered `Name::class` alone and relied on PHPStan
 * dispatching a parent node type to its subclasses - which it does - on the assumption that
 * every class reference reaches a rule as a Name. Measured, it does not: a probe class was
 * reported for its parameter types, its return types and a `\DateTime::ATOM` constant fetch,
 * and NOT for `new RuntimeException(...)` in a reachable branch, nor for `catch
 * (LogicException)`, nor for the `use` imports themselves. A rule that catches a type hint and
 * misses `new` would have been worse than none, because it reads as complete.
 *
 * Class names are told from function and constant names by asking whether the name denotes a
 * class, interface, trait or enum. That is a reflection check rather than a name check - the
 * opposite of ForbiddenRootFacadeAliases' deliberate choice - and for the opposite reason:
 * there, trusting reflection would have cleared `\Redis::` by accident; here the written name
 * cannot tell `trim()` from `DateTime`, and getting it wrong would flag every call to a PHP
 * builtin function in the layer. `use function` and `use const` imports are skipped by their
 * own UseItem type for the same reason.
 *
 * Verified by probe: a domain class using ArrayObject, SplQueue, DateTime, Closure,
 * RuntimeException and LogicException across an import, a `new`, a `catch`, a parameter type, a
 * return type and a class-constant fetch is reported at every one of those sites, while every
 * file currently in app/Domain stays clean and `mb_strtolower(trim(preg_replace(...)))` raises
 * nothing.
 *
 * @implements Rule<Node>
 */
final class NoUnlistedGlobalClassRule implements Rule
{
    /**
     * The same three names tests/Architecture/DomainPurityTest.php's toOnlyUse() lists, so the
     * two guards state one policy. DateTimeImmutable and not DateTime: immutability is the
     * point, and it is the half the Pest rule could never enforce.
     */
    private const ALLOWED = [
        'DateTimeImmutable',
        'DateTimeZone',
        'InvalidArgumentException',
    ];

    /** Not class names at all; `self` and friends resolve to the enclosing class. */
    private const NOT_A_CLASS_REFERENCE = ['self', 'static', 'parent'];

    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! DomainPath::isInDomain($scope)) {
            return [];
        }

        $errors = [];

        foreach ($this->classNamesIn($node) as $name) {
            $error = $this->check($name);

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Every position in which a node names a class.
     *
     * @return list<Name>
     */
    private function classNamesIn(Node $node): array
    {
        return match (true) {
            $node instanceof New_,
            $node instanceof StaticCall,
            $node instanceof StaticPropertyFetch,
            $node instanceof ClassConstFetch,
            $node instanceof Instanceof_ => $node->class instanceof Name ? [$node->class] : [],

            $node instanceof Catch_ => $node->types,

            $node instanceof Class_ => [
                ...($node->extends instanceof Name ? [$node->extends] : []),
                ...$node->implements,
            ],
            $node instanceof Interface_ => $node->extends,
            $node instanceof Enum_ => $node->implements,
            $node instanceof TraitUse => $node->traits,

            $node instanceof Attribute => [$node->name],

            // The import itself, so `use DateTime;` is reported even before anything uses it -
            // Pint's no_unused_imports would strip an unused one, but an import is also the
            // clearest statement of intent and the cheapest place to see the mistake.
            $node instanceof Use_ => $this->importedClassNames($node->uses, $node->type),
            $node instanceof GroupUse => $this->importedClassNames($node->uses, $node->type),

            $node instanceof Param => $this->typeNames($node->type),
            $node instanceof ClassMethod, $node instanceof Function_ => $this->typeNames($node->returnType),
            $node instanceof Property, $node instanceof ClassConst => $this->typeNames($node->type),

            default => [],
        };
    }

    /**
     * @param  list<UseItem>  $items
     * @return list<Name>
     */
    private function importedClassNames(array $items, int $statementType): array
    {
        $names = [];

        foreach ($items as $item) {
            // An item's own type wins when the statement's is unknown, which is how php-parser
            // represents `use function A\b, const C\D;` - a mixed group use.
            $type = $statementType === Use_::TYPE_UNKNOWN ? $item->type : $statementType;

            if ($type === Use_::TYPE_NORMAL) {
                $names[] = $item->name;
            }
        }

        return $names;
    }

    /**
     * Flattens a type position down to the class names in it, dropping `int`, `string`, `void`
     * and the rest, which arrive as Identifier rather than Name.
     *
     * @return list<Name>
     */
    private function typeNames(?Node $type): array
    {
        if ($type instanceof Name) {
            return [$type];
        }

        if ($type instanceof NullableType) {
            return $this->typeNames($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $names = [];

            foreach ($type->types as $inner) {
                $names = [...$names, ...$this->typeNames($inner)];
            }

            return $names;
        }

        // Identifier (a built-in type), a ComplexType this version does not know, or nothing.
        if ($type instanceof ComplexType) {
            return [];
        }

        return [];
    }

    private function check(Name $name): ?IdentifierRuleError
    {
        $parts = $name->getParts();

        if (count($parts) !== 1) {
            return null;
        }

        $class = $parts[0];

        if (in_array(strtolower($class), self::NOT_A_CLASS_REFERENCE, true)) {
            return null;
        }

        if (in_array($class, self::ALLOWED, true)) {
            return null;
        }

        // A function or constant name rather than a class reference. A bare call to a
        // USER-DEFINED global function is a separate concern the Pest rule already catches,
        // because its walker counts one (ReflectionFunction::isUserDefined()).
        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
            return null;
        }

        return RuleErrorBuilder::message(sprintf(
            'app/Domain must not use the global class %s. The allow-list is [%s] and this rule '.
            'is what enforces it: tests/Architecture/DomainPurityTest.php\'s toOnlyUse() admits '.
            'every PHP internal class, because pest-plugin-arch does not count one as a use - '.
            'so mutable DateTime, ArrayObject, SplQueue and Closure all passed it silently. Add '.
            'a name to the allow-list only with a reason; spec §6.1 promises this layer strict '.
            'purity.',
            $class,
            implode(', ', self::ALLOWED),
        ))->identifier('domainPurity.globalClass')->build();
    }
}
