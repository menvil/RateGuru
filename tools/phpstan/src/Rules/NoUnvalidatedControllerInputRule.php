<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
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
        'allFiles',
        'anyFilled',
        'array',
        'boolean',
        'collect',
        'date',
        'enum',
        'enums',
        'except',
        'exists',
        'file',
        'files',
        'filled',
        'float',
        'fluent',
        'get',
        'has',
        'hasAny',
        'hasFile',
        'input',
        'integer',
        'isNotFilled',
        'json',
        'keys',
        'missing',
        'only',
        'post',
        'query',
        'str',
        'string',
        'whenFilled',
        'whenHas',
        'whenMissing',
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

        // request('status') reads the input as $request->input('status') does.
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toLowerString() === 'request'
            && $node->getArgs() !== []
        ) {
            return [$this->error('HTTP controllers must read input from Form Request::validated() or safe(); do not call request() with a key.')];
        }

        // So does the Request facade.
        if ($node instanceof StaticCall
            && $node->class instanceof Name
            && $node->name instanceof Node\Identifier
            && $scope->resolveName($node->class) === RequestFacade::class
            && in_array($node->name->toString(), self::RESTRICTED_METHODS, true)
        ) {
            return [$this->error(sprintf(
                'HTTP controllers must read input from Form Request::validated() or safe(); do not call Request::%s().',
                $node->name->toString(),
            ))];
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
            ->nonIgnorable()
            ->build();
    }
}
