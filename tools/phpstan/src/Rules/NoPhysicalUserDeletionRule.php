<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function in_array;
use function sprintf;

/**
 * Account deletion means anonymization (AnonymizeUserAccountAction), never
 * a physical users-row delete: the tombstone row is what keeps every
 * community FK (posts, comments, votes, reports) valid. This rule bans
 * $user->delete()/$user->forceDelete() and User::destroy()/forceDestroy()
 * everywhere in app code — there is deliberately no exception list.
 *
 * @implements Rule<CallLike>
 */
final class NoPhysicalUserDeletionRule implements Rule
{
    private const USER_MODEL = 'App\\Models\\User';

    private const INSTANCE_METHODS = ['delete', 'forceDelete'];

    private const STATIC_METHODS = ['destroy', 'forceDestroy'];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof MethodCall) {
            return $this->processInstanceCall($node, $scope);
        }

        if ($node instanceof StaticCall) {
            return $this->processStaticCall($node, $scope);
        }

        return [];
    }

    /** @return list<IdentifierRuleError> */
    private function processInstanceCall(MethodCall $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier
            || ! in_array($node->name->toString(), self::INSTANCE_METHODS, true)
        ) {
            return [];
        }

        $receiverType = $scope->getType($node->var);

        if (! (new ObjectType(self::USER_MODEL))->isSuperTypeOf($receiverType)->yes()) {
            return [];
        }

        return [$this->error($node->name->toString())];
    }

    /** @return list<IdentifierRuleError> */
    private function processStaticCall(StaticCall $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier
            || ! in_array($node->name->toString(), self::STATIC_METHODS, true)
            || ! $node->class instanceof Name
        ) {
            return [];
        }

        $className = $scope->resolveName($node->class);

        if ($className !== self::USER_MODEL) {
            return [];
        }

        return [$this->error($node->name->toString())];
    }

    private function error(string $method): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'User rows are never physically deleted; account deletion must anonymize into a tombstone (AnonymizeUserAccountAction), not call %s().',
            $method,
        ))
            ->identifier('rateguru.users.physicalDeletion')
            ->nonIgnorable()
            ->build();
    }
}
