<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RateGuru\PHPStan\Support\ArchitectureScope;

use function in_array;
use function sprintf;

/** @implements Rule<MethodCall> */
final class NoDirectControllerPermissionCheckRule implements Rule
{
    /** @var list<string> */
    private const PERMISSION_METHODS = [
        'canComment',
        'canCreateContent',
        'canReport',
        'canVote',
        'isAdmin',
        'isModerator',
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ArchitectureScope::isHttpController($scope)
            || ! $node->name instanceof Node\Identifier
            || ! in_array($node->name->toString(), self::PERMISSION_METHODS, true)
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'HTTP controllers must authorize through Gate or policies; do not call %s() directly.',
                $node->name->toString(),
            ))
                ->identifier('rateguru.controller.directPermissionCheck')
                ->build(),
        ];
    }
}
