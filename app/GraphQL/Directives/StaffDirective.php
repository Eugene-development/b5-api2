<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\TypeExtensionNode;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\Support\Contracts\TypeExtensionManipulator;
use Nuwave\Lighthouse\Support\Contracts\TypeManipulator;

final class StaffDirective extends BaseDirective implements FieldMiddleware, TypeExtensionManipulator, TypeManipulator
{
    private const DEFAULT_ALLOWED_STATUSES = ['admin', 'curator', 'manager', 'designer'];

    public static function definition(): string
    {
        return <<<'GRAPHQL'
"Require an authenticated administrative-panel user with one of the allowed status slugs."
directive @staff(roles: [String!] = ["admin", "curator", "manager", "designer"]) on FIELD_DEFINITION | OBJECT
GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $allowedStatuses = $this->directiveArgValue('roles', self::DEFAULT_ALLOWED_STATUSES);

        $fieldValue->wrapResolver(fn (callable $resolver): \Closure => function (
            mixed $root,
            array $args,
            GraphQLContext $context,
            ResolveInfo $resolveInfo
        ) use ($resolver, $allowedStatuses): mixed {
            $statusSlug = $context->user()?->status?->slug;

            if (! $statusSlug || ! in_array($statusSlug, $allowedStatuses, true)) {
                throw new AuthorizationException('Недостаточно прав для административной операции.');
            }

            return $resolver($root, $args, $context, $resolveInfo);
        });
    }

    public function manipulateTypeDefinition(DocumentAST &$documentAST, TypeDefinitionNode &$typeDefinition): void
    {
        ASTHelper::addDirectiveToFields($this->directiveNode, $typeDefinition);
    }

    public function manipulateTypeExtension(DocumentAST &$documentAST, TypeExtensionNode &$typeExtension): void
    {
        ASTHelper::addDirectiveToFields($this->directiveNode, $typeExtension);
    }
}
