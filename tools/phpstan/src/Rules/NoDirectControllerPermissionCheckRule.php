<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Rules;

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
final class NoDirectControllerPermissionCheckRule implements Rule
{
    private const USER_MODEL = 'App\\Models\\User';

    /** @var list<string> */
    private const PERMISSION_METHODS = [
        'canAccessPrivilegedPanel',
        'canAuthenticate',
        'canBeFollowed',
        'canComment',
        'canCreateContent',
        'canFollow',
        'canManageContent',
        'canReport',
        'canSignIn',
        'canUpdateProfile',
        'canVote',
        'isAdmin',
        'isModerator',
        'isPersona',
    ];

    /**
     * Questions UserRole answers as well. The enum is the role's vocabulary —
     * a label, a staff badge — which presentation may read; the same question
     * asked of the account is an authorization decision, so it is rejected
     * only on a User.
     *
     * @var list<string>
     */
    private const ACCOUNT_PERMISSION_METHODS = [
        'isStaff',
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ArchitectureScope::isPresentationLayer($scope)
            || ! $node->name instanceof Node\Identifier
            || ! $this->isPermissionCheck($node, $node->name->toString(), $scope)
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Presentation classes must authorize through Gate or policies; do not call %s() directly.',
                $node->name->toString(),
            ))
                ->identifier('rateguru.presentation.directPermissionCheck')
                ->nonIgnorable()
                ->build(),
        ];
    }

    private function isPermissionCheck(MethodCall $node, string $method, Scope $scope): bool
    {
        if (in_array($method, self::PERMISSION_METHODS, true)) {
            return true;
        }

        return in_array($method, self::ACCOUNT_PERMISSION_METHODS, true)
            && ! (new ObjectType(self::USER_MODEL))->isSuperTypeOf($scope->getType($node->var))->no();
    }
}
