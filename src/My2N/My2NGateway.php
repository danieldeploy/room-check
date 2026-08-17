<?php
declare(strict_types=1);

interface My2NGateway
{
    public function listMobileConfigurations(): array;

    public function getCurrentMembers(): array;

    public function updateMembers(array $memberIds): array;
}
