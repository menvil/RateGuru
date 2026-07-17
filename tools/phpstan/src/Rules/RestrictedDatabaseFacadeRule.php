<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RateGuru\PHPStan\Support\ArchitectureScope;

use function in_array;
use function sprintf;

/** @implements Rule<StaticCall> */
final class RestrictedDatabaseFacadeRule implements Rule
{
    /** @var list<string> */
    private const RESTRICTED_METHODS = [
        'affectingStatement',
        'connection',
        'delete',
        'insert',
        'query',
        'raw',
        'scalar',
        'select',
        'selectOne',
        'selectResultSets',
        'statement',
        'table',
        'unprepared',
        'update',
    ];

    /**
     * @param  list<array{class: class-string, methods: list<string>, reason: string, behaviorTests: list<string>, status: 'approved'}>  $exceptions
     */
    public function __construct(private array $exceptions) {}

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->class instanceof Name
            || ! $node->name instanceof Node\Identifier
            || $scope->resolveName($node->class) !== DB::class
        ) {
            return [];
        }

        $method = $node->name->toString();

        if ($method === 'transaction') {
            if (! ArchitectureScope::isPresentationLayer($scope)) {
                return [];
            }

            return [
                RuleErrorBuilder::message('Presentation classes must not manage transactions; move DB::transaction() to an Action.')
                    ->identifier('rateguru.presentation.transaction')
                    ->build(),
            ];
        }

        if (! in_array($method, self::RESTRICTED_METHODS, true)
            || $this->isApprovedException($scope, $method)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Direct DB::%s() access is restricted to approved infrastructure classes.',
                $method,
            ))
                ->identifier('rateguru.database.restrictedFacade')
                ->build(),
        ];
    }

    private function isApprovedException(Scope $scope, string $method): bool
    {
        $class = $scope->getClassReflection()?->getName();

        foreach ($this->exceptions as $exception) {
            if ($exception['class'] === $class
                && in_array($method, $exception['methods'], true)) {
                return true;
            }
        }

        return false;
    }
}
