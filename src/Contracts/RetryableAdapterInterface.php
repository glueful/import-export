<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Contracts;

interface RetryableAdapterInterface
{
    public function retryable(): bool;
}
