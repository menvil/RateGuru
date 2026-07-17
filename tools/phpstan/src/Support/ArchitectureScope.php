<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Support;

use PHPStan\Analyser\Scope;

use function str_starts_with;

final class ArchitectureScope
{
    public static function isHttpController(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        return $namespace === 'App\\Http\\Controllers'
            || ($namespace !== null && str_starts_with($namespace, 'App\\Http\\Controllers\\'));
    }

    public static function isPresentationLayer(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null) {
            return false;
        }

        foreach ([
            'App\\Filament',
            'App\\Http\\Controllers',
            'App\\Http\\Requests',
            'App\\Livewire',
        ] as $presentationNamespace) {
            if ($namespace === $presentationNamespace
                || str_starts_with($namespace, $presentationNamespace.'\\')
            ) {
                return true;
            }
        }

        return false;
    }

    public static function readOnlyLayer(Scope $scope): ?string
    {
        $namespace = $scope->getNamespace();

        if ($namespace === 'App\\Queries' || ($namespace !== null && str_starts_with($namespace, 'App\\Queries\\'))) {
            return 'Query Objects';
        }

        if ($namespace === 'App\\Policies' || ($namespace !== null && str_starts_with($namespace, 'App\\Policies\\'))) {
            return 'Policies';
        }

        return null;
    }
}
