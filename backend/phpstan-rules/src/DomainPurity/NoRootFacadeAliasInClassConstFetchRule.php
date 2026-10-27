<?php

declare(strict_types=1);

namespace PhpStanRules\DomainPurity;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * Covers `\DB::class` and the static-property form (`\DB::$foo`), which StaticCallRule
 * does not reach. See ForbiddenRootFacadeAliases for why this does not trust reflection.
 *
 * @implements Rule<ClassConstFetch>
 */
final class NoRootFacadeAliasInClassConstFetchRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassConstFetch::class;
    }

    /**
     * @param  ClassConstFetch  $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! DomainPath::isInDomain($scope)) {
            return [];
        }

        if (! $node->class instanceof Node\Name) {
            return [];
        }

        $alias = ForbiddenRootFacadeAliases::match($node->class);

        if ($alias === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'app/Domain must not reference the root facade alias \\%s::. This evades '.
                'tests/Architecture/DomainPurityTest.php\'s toOnlyUse() rule because the '.
                'alias form never spells "Illuminate" - import nothing from Illuminate at '.
                'all, including via a root-namespaced facade alias.',
                $alias,
            ))->identifier('domainPurity.rootFacadeAlias')->build(),
        ];
    }
}
