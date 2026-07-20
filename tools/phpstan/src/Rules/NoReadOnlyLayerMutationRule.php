<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RateGuru\PHPStan\Support\ArchitectureScope;
use RateGuru\PHPStan\Support\EloquentCallInspector;
use RateGuru\PHPStan\Support\EloquentMutationDetector;

use function in_array;
use function sprintf;

/** @implements Rule<CallLike> */
final class NoReadOnlyLayerMutationRule implements Rule
{
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $layer = ArchitectureScope::readOnlyLayer($scope);

        if ($layer === null || (! $node instanceof MethodCall && ! $node instanceof StaticCall)) {
            return [];
        }

        if ($this->isTransaction($node, $scope)) {
            return [$this->error("{$layer} are read-only; database transactions are not allowed.")];
        }

        if ($node instanceof MethodCall
            && $node->name instanceof Node\Identifier
            && in_array($node->name->toString(), ['lockForUpdate', 'sharedLock'], true)
            && EloquentCallInspector::hasQueryReceiver($scope, $node)
        ) {
            return [$this->error(sprintf(
                '%s are read-only; %s() belongs in an Action transaction.',
                $layer,
                $node->name->toString(),
            ))];
        }

        if (! EloquentMutationDetector::isMutation($scope, $node)
            || ! $node->name instanceof Node\Identifier
        ) {
            return [];
        }

        return [$this->error(sprintf(
            '%s are read-only; Eloquent %s() is not allowed.',
            $layer,
            $node->name->toString(),
        ))];
    }

    private function isTransaction(MethodCall|StaticCall $node, Scope $scope): bool
    {
        return $node instanceof StaticCall
            && $node->class instanceof Name
            && $node->name instanceof Node\Identifier
            && $scope->resolveName($node->class) === DB::class
            && $node->name->toString() === 'transaction';
    }

    private function error(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('rateguru.readOnlyLayer.mutation')
            ->build();
    }
}
