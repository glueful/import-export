<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Console\Concerns;

use Symfony\Component\Console\Input\InputInterface;

trait ParsesJsonOption
{
    /** @return array<string,mixed> */
    private function jsonOption(InputInterface $input, string $name): array
    {
        $value = $input->getOption($name);
        if (!is_scalar($value) || (string) $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(sprintf('--%s must decode to a JSON object.', $name));
        }

        return $decoded;
    }

    private function stringOption(InputInterface $input, string $name, string $default = ''): string
    {
        $value = $input->getOption($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    private function intOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);

        return is_scalar($value) && (string) $value !== '' ? (int) $value : $default;
    }
}
