<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class ImportExportAdapterController
{
    public function __construct(
        private ImporterRegistry $importers,
        private ExporterRegistry $exporters,
    ) {
    }

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
