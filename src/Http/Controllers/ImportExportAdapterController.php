<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class ImportExportAdapterController
{
    public function __construct(
        private ImporterRegistry $importers,
        private ExporterRegistry $exporters,
    ) {
    }

    /**
     * List the registered importer and exporter adapters.
     */
    #[ApiOperation(
        summary: 'List Import/Export Adapters',
        description: 'Lists the importer and exporter adapters registered through the '
            . '`import_export.importer` and `import_export.exporter` service tags, with their keys '
            . 'and labels. Requires the `import_export.view` permission.',
        tags: ['Import Export'],
    )]
    #[ApiResponse(200, description: 'Adapters retrieved')]
    #[ApiResponse(403, description: 'Permission denied (import_export.view)')]
    public function index(Request $request): Response
    {
        return Response::success([
            'importers' => array_map(
                static fn($importer): array => ['key' => $importer->key(), 'label' => $importer->label()],
                $this->importers->all()
            ),
            'exporters' => array_map(
                static fn($exporter): array => ['key' => $exporter->key(), 'label' => $exporter->label()],
                $this->exporters->all()
            ),
        ], 'Import/export adapters retrieved.');
    }
}
