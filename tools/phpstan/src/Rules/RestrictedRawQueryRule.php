<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use RateGuru\PHPStan\Support\EloquentCallInspector;

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
                        ->nonIgnorable()
                        ->build(),
                ];
            }

            if ($exception['bindings'] === 'literal_only'
                && (! isset($node->getArgs()[0]) || ! $node->getArgs()[0]->value instanceof String_)) {
                return [
                    RuleErrorBuilder::message('This approved raw SQL call only permits a literal SQL string.')
                        ->identifier('rateguru.database.rawQueryLiteral')
                        ->nonIgnorable()
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
                ->nonIgnorable()
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
        // A model instance forwards a raw method to a new query, as its class does.
        if ($node instanceof MethodCall) {
            return EloquentCallInspector::hasModelOrQueryReceiver($scope, $node);
        }

        return TypeCombinator::union(
            new ObjectType(Model::class),
            new ObjectType(EloquentBuilder::class),
            new ObjectType(QueryBuilder::class),
        )->isSuperTypeOf(EloquentCallInspector::staticCallClass($scope, $node))->yes();
    }
}
