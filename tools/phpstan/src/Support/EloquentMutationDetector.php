<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Support;

use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;

use function in_array;

final class EloquentMutationDetector
{
    /** @var list<string> */
    private const METHODS = [
        'attach',
        'create',
        'createMany',
        'createManyQuietly',
        'decrement',
        'delete',
        'deleteQuietly',
        'destroy',
        'detach',
        'fill',
        'firstOrCreate',
        'forceCreate',
        'forceCreateQuietly',
        'forceDelete',
        'forceDeleteQuietly',
        'forceFill',
        'increment',
        'insert',
        'insertGetId',
        'insertOrIgnore',
        'push',
        'pushQuietly',
        'restore',
        'restoreQuietly',
        'save',
        'saveMany',
        'saveManyQuietly',
        'saveOrFail',
        'saveQuietly',
        'sync',
        'syncWithPivotValues',
        'syncWithoutDetaching',
        'toggle',
        'touch',
        'touchQuietly',
        'update',
        'updateExistingPivot',
        'updateOrCreate',
        'updateOrInsert',
        'updateQuietly',
        'upsert',
    ];

    public static function isMutation(Scope $scope, CallLike $node): bool
    {
        if ((! $node instanceof MethodCall && ! $node instanceof StaticCall)
            || ! $node->name instanceof Identifier
            || ! in_array($node->name->toString(), self::METHODS, true)
        ) {
            return false;
        }

        return EloquentCallInspector::hasModelOrQueryReceiver($scope, $node);
    }
}
