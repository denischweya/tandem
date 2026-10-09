<?php

declare(strict_types=1);

namespace App\Application\Auth;

final readonly class DeviceDescriptor
{
    public function __construct(
        public string $installId,
        public string $platform,
    ) {}
}
