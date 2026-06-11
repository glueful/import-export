<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Extensions\ImportExport\Http\RequireImportExportPermission;
use Glueful\Extensions\ImportExport\Tests\Support\FakePermissionManager;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class RequireImportExportPermissionTest extends ImportExportTestCase
{
    public function testReturns403WithoutAuthenticatedUser(): void
    {
        $middleware = new RequireImportExportPermission($this->appContext());

        $response = $middleware->handle(Request::create('/'), static fn(): string => 'next');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReturns403WhenManagerUnavailable(): void
    {
        $middleware = new RequireImportExportPermission($this->appContext());
        $request = Request::create('/');
        $request->attributes->set('auth.user', new UserIdentity('user-1'));

        $response = $middleware->handle($request, static fn(): string => 'next');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReturns403WithRealManagerAndNoProvider(): void
    {
        $manager = new PermissionManager();
        $manager->clearProvider();
        $this->bind(PermissionManager::class, $manager);

        $middleware = new RequireImportExportPermission($this->appContext());
        $request = Request::create('/');
        $request->attributes->set('auth.user', new UserIdentity('user-1'));

        $response = $middleware->handle($request, static fn(): string => 'next');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReturns403WhenPermissionDenied(): void
    {
        $this->bind(PermissionManager::class, new FakePermissionManager(false));
        $middleware = new RequireImportExportPermission($this->appContext());
        $request = Request::create('/');
        $request->attributes->set('auth.user', new UserIdentity('user-1'));

        $response = $middleware->handle($request, static fn(): string => 'next', 'import_export.run_import');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCallsNextOnlyWhenAllowed(): void
    {
        $manager = new FakePermissionManager(true);
        $this->bind(PermissionManager::class, $manager);
        $middleware = new RequireImportExportPermission($this->appContext());
        $request = Request::create('/');
        $request->attributes->set('auth.user', new UserIdentity('user-1', roles: ['admin'], scopes: ['imports:run']));
        $request->attributes->set('route.params', ['job' => 'job-1']);

        $called = false;
        $response = $middleware->handle($request, function (Request $request) use (&$called): JsonResponse {
            $called = true;
            return new JsonResponse(['ok' => true], 200);
        }, 'import_export.run_import');

        self::assertTrue($called);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'user-1',
            'import_export.run_import',
            'import_export',
        ], array_slice($manager->lastCall, 0, 3));
        self::assertSame(['admin'], $manager->lastCall[3]['roles']);
        self::assertSame(['imports:run'], $manager->lastCall[3]['scopes']);
        self::assertSame(['job' => 'job-1'], $manager->lastCall[3]['route_params']);
    }
}
