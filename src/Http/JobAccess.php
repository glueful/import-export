<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\Request;

final class JobAccess
{
    public function __construct(private ApplicationContext $context)
    {
    }

    public function actorUuid(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');

        return $user instanceof UserIdentity ? $user->id() : null;
    }

    /**
     * @param array<string,mixed> $job
     */
    public function canAccess(Request $request, array $job): bool
    {
        if ($this->canManageAll($request)) {
            return true;
        }

        $actorUuid = $this->actorUuid($request);

        return $actorUuid !== null && ($job['created_by'] ?? null) === $actorUuid;
    }

    public function canManageAll(Request $request): bool
    {
        $actorUuid = $this->actorUuid($request);
        if ($actorUuid === null) {
            return false;
        }

        $manager = $this->permissionManager();
        if ($manager === null) {
            return false;
        }

        try {
            return $manager->can($actorUuid, 'import_export.manage_all', 'import_export', [
                'route_params' => (array) $request->attributes->get('route.params'),
                'jwt_claims' => (array) $request->attributes->get('jwt.claims'),
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function permissionManager(): ?PermissionManager
    {
        $container = $this->context->getContainer();
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            try {
                if ($container->has($id)) {
                    $manager = $container->get($id);
                    if ($manager instanceof PermissionManager) {
                        return $manager;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
