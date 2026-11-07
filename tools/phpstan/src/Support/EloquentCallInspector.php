<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * What a call is made on, as the rules ask it. A receiver that may be null —
 * `$request->user()`, a `?Post` property — is judged by what it is when it
 * is not: the call it makes is the same one. A receiver that may be any of
 * the database types counts as a database receiver.
 */
final class EloquentCallInspector
{
    public static function hasModelOrQueryReceiver(Scope $scope, MethodCall|StaticCall $node): bool
    {
        if ($node instanceof MethodCall) {
            return self::anyOf([Model::class, EloquentBuilder::class, QueryBuilder::class, Relation::class])
                ->isSuperTypeOf(self::receiverType($scope, $node->var))
                ->yes();
        }

        return self::isModelStaticCall($scope, $node);
    }

    public static function hasQueryReceiver(Scope $scope, MethodCall $node): bool
    {
        return self::anyOf([EloquentBuilder::class, QueryBuilder::class, Relation::class])
            ->isSuperTypeOf(self::receiverType($scope, $node->var))
            ->yes();
    }

    public static function returnsQuery(Scope $scope, MethodCall|StaticCall $node): bool
    {
        return self::anyOf([EloquentBuilder::class, QueryBuilder::class, Relation::class])
            ->isSuperTypeOf($scope->getType($node))
            ->yes();
    }

    /** The type a call is made on, without the null it would fail on anyway. */
    public static function receiverType(Scope $scope, Expr $receiver): Type
    {
        return TypeCombinator::removeNull($scope->getType($receiver));
    }

    /**
     * The class a static call is made on: a name, or an expression holding a
     * class string or an object (`$modelClass::create()`, `static::getModel()::query()`).
     */
    public static function staticCallClass(Scope $scope, StaticCall $node): Type
    {
        if ($node->class instanceof Name) {
            return new ObjectType($scope->resolveName($node->class));
        }

        return $scope->getType($node->class)->getObjectTypeOrClassStringObjectType();
    }

    private static function isModelStaticCall(Scope $scope, StaticCall $node): bool
    {
        return (new ObjectType(Model::class))
            ->isSuperTypeOf(self::staticCallClass($scope, $node))
            ->yes();
    }

    /** @param  list<class-string>  $classes */
    private static function anyOf(array $classes): Type
    {
        return TypeCombinator::union(...array_map(
            static fn (string $class): Type => new ObjectType($class),
            $classes,
        ));
    }
}
