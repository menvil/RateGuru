<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function in_array;
use function sprintf;

/** @implements Rule<CallLike> */
final class RestrictedRawQueryRule implements Rule
{
    /** @var list<string> */
    private const RESTRICTED_METHODS = [
        'addSelectRaw',
        'fromRaw',
        'groupByRaw',
        'havingRaw',
        'orHavingRaw',
        'orWhereRaw',
        'orderByRaw',
        'selectRaw',
        'whereRaw',
    ];

    /**
     * @param  list<array{class: class-string, methods: list<string>, reason: string, bindings: 'required'|'literal_only'|'internal_only', behaviorTests: list<string>, status: 'approved'}>  $exceptions
     */
    public function __construct(private array $exceptions) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if ((! $node instanceof MethodCall && ! $node instanceof StaticCall)
            || ! $node->name instanceof Node\Identifier
        ) {
            return [];
        }

        $method = $node->name->toString();

        if (! in_array($method, self::RESTRICTED_METHODS, true)
            || ! $this->isDatabaseQuery($scope, $node)) {
            return [];
        }

        $exception = $this->approvedException($scope, $method);

        if ($exception !== null) {
            if ($exception['bindings'] === 'required' && count($node->getArgs()) < 2) {
                return [
                    RuleErrorBuilder::message('This approved raw SQL call requires a separate bindings argument.')
                        ->identifier('rateguru.database.rawQueryBindings')
                        ->build(),
                ];
            }

            if ($exception['bindings'] === 'literal_only'
                && (! isset($node->getArgs()[0]) || ! $node->getArgs()[0]->value instanceof String_)) {
                return [
                    RuleErrorBuilder::message('This approved raw SQL call only permits a literal SQL string.')
                        ->identifier('rateguru.database.rawQueryLiteral')
                        ->build(),
                ];
            }

            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Raw query method %s() is restricted to approved Query Objects.',
                $method,
            ))
                ->identifier('rateguru.database.rawQuery')
                ->build(),
        ];
    }

    /**
     * @return array{class: class-string, methods: list<string>, reason: string, bindings: 'required'|'literal_only'|'internal_only', behaviorTests: list<string>, status: 'approved'}|null
     */
    private function approvedException(Scope $scope, string $method): ?array
    {
        $class = $scope->getClassReflection()?->getName();

        foreach ($this->exceptions as $exception) {
            if ($exception['class'] === $class
                && in_array($method, $exception['methods'], true)) {
                return $exception;
            }
        }

        return null;
    }

    private function isDatabaseQuery(Scope $scope, MethodCall|StaticCall $node): bool
    {
        if ($node instanceof MethodCall) {
            $type = $scope->getType($node->var);

            foreach ([EloquentBuilder::class, QueryBuilder::class, Relation::class] as $builderClass) {
                if ((new ObjectType($builderClass))->isSuperTypeOf($type)->yes()) {
                    return true;
                }
            }

            return false;
        }

        if (! $node->class instanceof Name) {
            return false;
        }

        $type = new ObjectType($scope->resolveName($node->class));

        return (new ObjectType(Model::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(EloquentBuilder::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(QueryBuilder::class))->isSuperTypeOf($type)->yes();
    }
}
