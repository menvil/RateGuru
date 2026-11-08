<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Database\ConnectionInterface;
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
use PHPStan\Type\ObjectType;
use PHPStan\Type\UnionType;
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

        // A lock taken on a model as on a query: the model passes it on.
        if ($node->name instanceof Node\Identifier
            && in_array($node->name->toLowerString(), ['lockforupdate', 'sharedlock'], true)
            && EloquentCallInspector::hasModelOrQueryReceiver($scope, $node)
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

    /** On the facade, or on a connection handed in: a transaction either way. */
    private function isTransaction(MethodCall|StaticCall $node, Scope $scope): bool
    {
        if (! $node->name instanceof Node\Identifier || ! ArchitectureScope::isTransactionMethod($node->name->toString())) {
            return false;
        }

        if ($node instanceof StaticCall) {
            return $node->class instanceof Name && $scope->resolveName($node->class) === DB::class;
        }

        $receiver = EloquentCallInspector::receiverType($scope, $node->var);

        // A union may hold a connection beside something else: any connection arm counts.
        foreach ($receiver instanceof UnionType ? $receiver->getTypes() : [$receiver] as $type) {
            if ((new ObjectType(ConnectionInterface::class))->isSuperTypeOf($type)->yes()) {
                return true;
            }
        }

        return false;
    }

    private function error(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('rateguru.readOnlyLayer.mutation')
            ->nonIgnorable()
            ->build();
    }
}
