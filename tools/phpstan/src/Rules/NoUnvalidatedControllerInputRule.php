<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use RateGuru\PHPStan\Support\ArchitectureScope;

use function in_array;
use function sprintf;

/** @implements Rule<Node> */
final class NoUnvalidatedControllerInputRule implements Rule
{
    /** @var list<string> */
    private const RESTRICTED_METHODS = [
        'all',
        'boolean',
        'collect',
        'date',
        'enum',
        'enums',
        'file',
        'files',
        'float',
        'get',
        'has',
        'hasAny',
        'input',
        'integer',
        'only',
        'query',
        'string',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ArchitectureScope::isHttpController($scope)) {
            return [];
        }

        if ($node instanceof ArrayDimFetch && $this->isRequest($scope, $node->var)) {
            return [$this->error('HTTP controllers must read input from Form Request::validated() or safe(); do not use Request array access.')];
        }

        if ($node instanceof PropertyFetch && $this->isRequest($scope, $node->var)) {
            return [$this->error('HTTP controllers must read input from Form Request::validated() or safe(); do not use Request magic properties.')];
        }

        if (! $node instanceof MethodCall || ! $node->name instanceof Node\Identifier) {
            return [];
        }

        $method = $node->name->toString();
        if (! in_array($method, self::RESTRICTED_METHODS, true)) {
            return [];
        }

        if (! $this->isRequest($scope, $node->var)) {
            return [];
        }

        return [$this->error(sprintf(
            'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::%s().',
            $method,
        ))];
    }

    private function isRequest(Scope $scope, Expr $expression): bool
    {
        return (new ObjectType(Request::class))->isSuperTypeOf($scope->getType($expression))->yes();
    }

    private function error(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('rateguru.controller.unvalidatedInput')
            ->build();
    }
}
