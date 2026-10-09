<?php

declare(strict_types=1);

namespace PhpStanRules\DomainPurity;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * See ForbiddenRootFacadeAliases for why this does not trust reflection. Scoped to
 * app/Domain by file path (not namespace) so it has no dependency on every Domain file
 * declaring its namespace correctly - the arch test it backs up already enforces that
 * separately, but this rule does not need to assume it.
 *
 * @implements Rule<StaticCall>
 */
final class NoRootFacadeAliasInStaticCallRule implements Rule
{
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @param  StaticCall  $node
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
                'app/Domain must not use the root facade alias \\%s::. This evades '.
                'tests/Architecture/DomainPurityTest.php\'s toOnlyUse() rule because the '.
                'alias form never spells "Illuminate" - import nothing from Illuminate at '.
                'all, including via a root-namespaced facade alias.',
                $alias,
            ))->identifier('domainPurity.rootFacadeAlias')->build(),
        ];
    }
}
