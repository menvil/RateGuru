<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use RateGuru\PHPStan\Support\ArchitectureScope;

use function in_array;
use function sprintf;

/** @implements Rule<MethodCall> */
final class NoInlineControllerValidationRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ArchitectureScope::isHttpController($scope) || ! $node->name instanceof Node\Identifier) {
            return [];
        }

        $method = $node->name->toString();

        if (! in_array($method, ['validate', 'validateWithBag'], true)) {
            return [];
        }

        if (! (new ObjectType(Request::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'HTTP controllers must validate input through a dedicated Form Request; do not call Request::%s().',
                $method,
            ))
                ->identifier('rateguru.controller.inlineValidation')
                ->build(),
        ];
    }
}
