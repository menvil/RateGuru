<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
use RateGuru\PHPStan\Support\ArchitectureScope;

use function in_array;
use function sprintf;

/** @implements Rule<CallLike> */
final class NoPresentationModelMutationRule implements Rule
{
    /** @var list<string> */
    private const MUTATION_METHODS = [
        'create',
        'createMany',
        'decrement',
        'delete',
        'destroy',
        'fill',
        'firstOrCreate',
        'forceDelete',
        'forceFill',
        'increment',
        'save',
        'saveOrFail',
        'update',
        'updateOrCreate',
        'upsert',
    ];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ArchitectureScope::isPresentationLayer($scope)
            || (! $node instanceof MethodCall && ! $node instanceof StaticCall)
            || ! $node->name instanceof Node\Identifier
        ) {
            return [];
        }

        $method = $node->name->toString();

        if (! in_array($method, self::MUTATION_METHODS, true)
            || ! $this->isEloquentCall($scope, $node)
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Presentation classes must delegate Eloquent %s() mutations to an Action.',
                $method,
            ))
                ->identifier('rateguru.presentation.modelMutation')
                ->build(),
        ];
    }

    private function isEloquentCall(Scope $scope, MethodCall|StaticCall $node): bool
    {
        if ($node instanceof MethodCall) {
            $type = $scope->getType($node->var);

            foreach ([Model::class, EloquentBuilder::class, Relation::class] as $eloquentClass) {
                if ((new ObjectType($eloquentClass))->isSuperTypeOf($type)->yes()) {
                    return true;
                }
            }

            return false;
        }

        if (! $node->class instanceof Name) {
            return false;
        }

        return (new ObjectType(Model::class))
            ->isSuperTypeOf(new ObjectType($scope->resolveName($node->class)))
            ->yes();
    }
}
