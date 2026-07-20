<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RateGuru\PHPStan\Support\ArchitectureScope;
use RateGuru\PHPStan\Support\EloquentCallInspector;

use function in_array;
use function sprintf;

/** @implements Rule<CallLike> */
final class NoControllerEloquentQueryRule implements Rule
{
    /** @var list<string> */
    private const MODEL_READ_METHODS = [
        'fresh',
        'load',
        'loadAggregate',
        'loadAvg',
        'loadCount',
        'loadExists',
        'loadMax',
        'loadMin',
        'loadMissing',
        'loadMorph',
        'loadMorphCount',
        'loadSum',
        'newModelQuery',
        'newQuery',
        'newQueryWithoutRelationships',
        'refresh',
    ];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ArchitectureScope::isHttpController($scope)
            || (! $node instanceof MethodCall && ! $node instanceof StaticCall)
            || ! $node->name instanceof Node\Identifier
            || ! $this->isControllerOwnedQuery($node, $scope)
        ) {
            return [];
        }

        $method = $node->name->toString();

        return [
            RuleErrorBuilder::message(sprintf(
                'HTTP controllers must delegate Eloquent queries to a Query Object; %s() is not allowed here.',
                $method,
            ))
                ->identifier('rateguru.controller.eloquentQuery')
                ->build(),
        ];
    }

    private function isControllerOwnedQuery(MethodCall|StaticCall $node, Scope $scope): bool
    {
        if ($node instanceof StaticCall) {
            return EloquentCallInspector::hasModelOrQueryReceiver($scope, $node);
        }

        if (EloquentCallInspector::hasQueryReceiver($scope, $node)) {
            return true;
        }

        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';

        return EloquentCallInspector::returnsQuery($scope, $node)
            || (in_array($method, self::MODEL_READ_METHODS, true)
                && EloquentCallInspector::hasModelOrQueryReceiver($scope, $node));
    }
}
