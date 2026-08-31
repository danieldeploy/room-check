<?php
declare(strict_types=1);

interface My2NGateway
{
    public function listSiteDevices(): array;

    public function listBellGroups(): array;

    public function updateBellMembers(string $bellKey, array $memberIds): array;
}
