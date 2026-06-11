<?php

declare(strict_types=1);

return [
    'routes_enabled' => true,
    'source_disk' => 'uploads',
    'result_disk' => 'uploads',
    'tmp_disk' => 'local',
    'tmp_path' => 'import-export/tmp',
    'queue' => 'import-export',
    'batch_size' => 500,
    'max_file_size' => 52428800,
    'retention_days' => 30,
    'error_cap_per_severity' => 1000,
    'stale_lock_minutes' => 15,
];
