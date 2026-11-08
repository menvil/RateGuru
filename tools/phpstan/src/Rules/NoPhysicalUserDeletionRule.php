<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use RateGuru\PHPStan\Support\EloquentCallInspector;

use function in_array;
use function sprintf;

/**
 * Account deletion means anonymization (AnonymizeUserAccountAction), never
 * a physical users-row delete: the tombstone row is what keeps every
 * community FK (posts, comments, votes, reports) valid. This rule bans the
 * Eloquent ways to delete one — on a user ($user->delete(), forceDelete(),
 * deleteQuietly(), forceDeleteQuietly(), deleteOrFail()), on an Eloquent
 * query or relation of users (User::where(...)->delete(), truncate()) and on
 * the class (User::destroy(), forceDestroy(), truncate()) — everywhere in app
 * code. There is deliberately no exception list. The query builder underneath
 * and raw SQL are the database facade and raw query rules' to refuse.
 *
 * @implements Rule<CallLike>
 */
final class NoPhysicalUserDeletionRule implements Rule
{
    private const USER_MODEL = 'App\\Models\\User';

    private const INSTANCE_METHODS = ['delete', 'deleteOrFail', 'deleteQuietly', 'forceDelete', 'forceDeleteQuietly'];

    private const QUERY_METHODS = ['delete', 'forceDelete', 'truncate'];

    private const STATIC_METHODS = ['destroy', 'forceDestroy', 'truncate'];

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
        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        $method = $node->name->toString();
        $receiver = EloquentCallInspector::receiverType($scope, $node->var);

        // Each type a receiver may be, one by one: a User|Builder<User> deletes
        // users whichever it turns out to be.
        foreach ($receiver instanceof UnionType ? $receiver->getTypes() : [$receiver] as $type) {
            if (in_array($method, self::INSTANCE_METHODS, true) && $this->isUser($type)) {
                return [$this->error($method)];
            }

            if (in_array($method, self::QUERY_METHODS, true) && $this->isQueryOfUsers($type)) {
                return [$this->error($method)];
            }
        }

        return [];
    }

    /** @return list<IdentifierRuleError> */
    private function processStaticCall(StaticCall $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier
            || ! in_array($node->name->toString(), self::STATIC_METHODS, true)
            || ! $this->isUser(EloquentCallInspector::staticCallClass($scope, $node))
        ) {
            return [];
        }

        return [$this->error($node->name->toString())];
    }

    private function isUser(Type $type): bool
    {
        return (new ObjectType(self::USER_MODEL))->isSuperTypeOf($type)->yes();
    }

    /** A query or relation whose rows are users: what it deletes are users rows. */
    private function isQueryOfUsers(Type $type): bool
    {
        if ((new ObjectType(EloquentBuilder::class))->isSuperTypeOf($type)->yes()) {
            return $this->isUser($type->getTemplateType(EloquentBuilder::class, 'TModel'));
        }

        if ((new ObjectType(Relation::class))->isSuperTypeOf($type)->yes()) {
            return $this->isUser($type->getTemplateType(Relation::class, 'TRelatedModel'));
        }

        return false;
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
