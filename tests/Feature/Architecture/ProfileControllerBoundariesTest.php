<?php

use App\Actions\Profile\DeleteUserAccountAction;
use App\Actions\Profile\UpdateUserIdentityAction;
use App\Http\Controllers\ProfileController;

it('delegates profile identity mutations to dedicated actions', function () {
    $boundaries = [
        ['update', UpdateUserIdentityAction::class],
        ['destroy', DeleteUserAccountAction::class],
    ];

    foreach ($boundaries as [$method, $action]) {
        $parameterTypes = collect((new ReflectionMethod(ProfileController::class, $method))->getParameters())
            ->map(static fn (ReflectionParameter $parameter): ?ReflectionType => $parameter->getType())
            ->filter(static fn (?ReflectionType $type): bool => $type instanceof ReflectionNamedType)
            ->map(static fn (ReflectionType $type): string => $type->getName())
            ->all();

        $this->assertContains(
            $action,
            $parameterTypes,
            ProfileController::class."::{$method} must delegate to {$action}",
        );
    }
});
