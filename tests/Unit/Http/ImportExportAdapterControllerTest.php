<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Http;

use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportAdapterController;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Tests\Support\FakeExporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ImportExportAdapterControllerTest extends ImportExportTestCase
{
    public function testListsRegisteredImportersAndExporters(): void
    {
        $controller = new ImportExportAdapterController(
            new ImporterRegistry([new FakeImporter('wordpress')]),
            new ExporterRegistry([new FakeExporter('content')])
        );

        $data = $this->json($controller->index(Request::create('/import-export/adapters')));

        self::assertSame('wordpress', $data['data']['importers'][0]['key']);
        self::assertSame('content', $data['data']['exporters'][0]['key']);
    }

    public function testRouteManifestContainsLockedRoutes(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../../routes/routes.php');

        foreach ([
            '/adapters',
            '/imports',
            '/exports',
            '/jobs',
            '/jobs/{uuid}',
            '/jobs/{uuid}/errors',
            '/jobs/{uuid}/report',
            '/jobs/{uuid}/cancel',
            '/jobs/{uuid}/retry',
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }
    }

    /** @return array<string,mixed> */
    private function json(\Glueful\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
