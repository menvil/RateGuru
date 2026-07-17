<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RateGuru\PHPStan\Support\EloquentCallInspector;

use function in_array;
use function str_starts_with;

/** @implements Rule<MethodCall> */
final class RegisteredPaginationBoundaryRule implements Rule
{
    /**
     * @param  list<array{class: class-string, method: string, uniqueOrder: list<string>, behaviorTests: list<string>, status: 'approved'}>  $boundaries
     */
    public function __construct(private array $boundaries) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier
            || ! in_array($node->name->toString(), ['paginate', 'simplePaginate', 'cursorPaginate'], true)
            || ! EloquentCallInspector::hasQueryReceiver($scope, $node)
        ) {
            return [];
        }

        if (! str_starts_with((string) $scope->getNamespace(), 'App\\Queries')) {
            return [
                RuleErrorBuilder::message('Eloquent pagination must be owned by a registered Query Object.')
                    ->identifier('rateguru.pagination.outsideBoundary')
                    ->build(),
            ];
        }

        if ($this->isRegistered($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Paginated Query Object methods require an approved stable-pagination registry entry and behavior test.')
                ->identifier('rateguru.query.unregisteredPagination')
                ->build(),
        ];
    }

    private function isRegistered(Scope $scope): bool
    {
        $class = $scope->getClassReflection()?->getName();
        $method = $scope->getFunctionName();

        foreach ($this->boundaries as $boundary) {
            if ($boundary['class'] === $class
                && $boundary['method'] === $method) {
                return true;
            }
        }

        return false;
    }
}
