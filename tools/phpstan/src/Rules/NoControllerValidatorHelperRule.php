<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RateGuru\PHPStan\Support\ArchitectureScope;

/** @implements Rule<FuncCall> */
final class NoControllerValidatorHelperRule implements Rule
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ArchitectureScope::isHttpController($scope)
            || ! $node->name instanceof Name
            || $scope->resolveName($node->name) !== 'validator'
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message('HTTP controllers must validate input through a dedicated Form Request; do not use validator().')
                ->identifier('rateguru.controller.validatorHelper')
                ->build(),
        ];
    }
}
