<?php

declare(strict_types=1);

return [
    'routes_enabled' => true,
    'source_disk' => 'uploads',
    'source_roots' => [],
    'result_disk' => 'local',
    'private_path' => null,
    'tmp_disk' => 'local',
    'tmp_path' => 'import-export/tmp',
    'queue' => 'import-export',
    'batch_size' => 500,
    'max_batches_per_job' => 10000,
    'max_file_size' => 52428800,
    'retention_days' => 30,
    'error_cap_per_severity' => 1000,
    'stale_lock_minutes' => 15,
];
