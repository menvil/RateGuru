<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
final class RestrictedRawQueryRule implements Rule
{
    /** @var list<string> */
    private const RESTRICTED_METHODS = [
        'addSelectRaw',
        'fromRaw',
        'groupByRaw',
        'havingRaw',
        'orHavingRaw',
        'orWhereRaw',
        'orderByRaw',
        'selectRaw',
        'whereRaw',
    ];

    /** @param list<class-string> $allowedClasses */
    public function __construct(private array $allowedClasses) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if ((! $node instanceof MethodCall && ! $node instanceof StaticCall)
            || ! $node->name instanceof Node\Identifier
        ) {
            return [];
        }

        $method = $node->name->toString();

        if (! in_array($method, self::RESTRICTED_METHODS, true)
            || ArchitectureScope::isAllowedClass($scope, $this->allowedClasses)
            || ! $this->isDatabaseQuery($scope, $node)
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Raw query method %s() is restricted to approved Query Objects.',
                $method,
            ))
                ->identifier('rateguru.database.rawQuery')
                ->build(),
        ];
    }

    private function isDatabaseQuery(Scope $scope, MethodCall|StaticCall $node): bool
    {
        if ($node instanceof MethodCall) {
            $type = $scope->getType($node->var);

            foreach ([EloquentBuilder::class, QueryBuilder::class, Relation::class] as $builderClass) {
                if ((new ObjectType($builderClass))->isSuperTypeOf($type)->yes()) {
                    return true;
                }
            }

            return false;
        }

        if (! $node->class instanceof Name) {
            return false;
        }

        $type = new ObjectType($scope->resolveName($node->class));

        return (new ObjectType(Model::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(EloquentBuilder::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(QueryBuilder::class))->isSuperTypeOf($type)->yes();
    }
}
