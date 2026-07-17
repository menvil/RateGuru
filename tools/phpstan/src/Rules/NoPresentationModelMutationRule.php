<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RateGuru\PHPStan\Support\ArchitectureScope;
use RateGuru\PHPStan\Support\EloquentMutationDetector;

use function sprintf;

/** @implements Rule<CallLike> */
final class NoPresentationModelMutationRule implements Rule
{
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ArchitectureScope::isPresentationLayer($scope)
            || ! EloquentMutationDetector::isMutation($scope, $node)) {
            return [];
        }

        /** @var MethodCall|StaticCall $node */
        $method = $node->name->toString();

        return [
            RuleErrorBuilder::message(sprintf(
                'Presentation classes must delegate Eloquent %s() mutations to an Action.',
                $method,
            ))
                ->identifier('rateguru.presentation.modelMutation')
                ->build(),
        ];
    }
}
