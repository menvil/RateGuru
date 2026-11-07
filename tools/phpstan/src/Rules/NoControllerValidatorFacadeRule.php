<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Support\Facades\Validator;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RateGuru\PHPStan\Support\ArchitectureScope;

use function in_array;

/** @implements Rule<StaticCall> */
final class NoControllerValidatorFacadeRule implements Rule
{
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ArchitectureScope::isHttpController($scope)
            || ! $node->class instanceof Name
            || ! $node->name instanceof Node\Identifier
            || $scope->resolveName($node->class) !== Validator::class
            || ! in_array($node->name->toString(), ['make', 'validate'], true)
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message('HTTP controllers must validate input through a dedicated Form Request; do not use the Validator facade.')
                ->identifier('rateguru.controller.validatorFacade')
                ->build(),
        ];
    }
}
