<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

final class EloquentCallInspector
{
    public static function hasModelOrQueryReceiver(Scope $scope, MethodCall|StaticCall $node): bool
    {
        if ($node instanceof MethodCall) {
            $type = $scope->getType($node->var);

            foreach ([Model::class, EloquentBuilder::class, QueryBuilder::class, Relation::class] as $class) {
                if ((new ObjectType($class))->isSuperTypeOf($type)->yes()) {
                    return true;
                }
            }

            return false;
        }

        return self::isModelStaticCall($scope, $node);
    }

    public static function hasQueryReceiver(Scope $scope, MethodCall $node): bool
    {
        $type = $scope->getType($node->var);

        foreach ([EloquentBuilder::class, QueryBuilder::class, Relation::class] as $class) {
            if ((new ObjectType($class))->isSuperTypeOf($type)->yes()) {
                return true;
            }
        }

        return false;
    }

    public static function returnsQuery(Scope $scope, MethodCall|StaticCall $node): bool
    {
        $type = $scope->getType($node);

        return (new ObjectType(EloquentBuilder::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(QueryBuilder::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(Relation::class))->isSuperTypeOf($type)->yes();
    }

    private static function isModelStaticCall(Scope $scope, StaticCall $node): bool
    {
        if (! $node->class instanceof Name) {
            return false;
        }

        return (new ObjectType(Model::class))
            ->isSuperTypeOf(new ObjectType($scope->resolveName($node->class)))
            ->yes();
    }
}
