<?php
declare(strict_types=1);

/**
 * City Center mapping verified against the live ZKAccess 5.3 database.
 * Controller network addresses deliberately do not live in source control:
 * the Windows executor resolves these aliases from the local ZKAccess database.
 */
final class CityCenterAccessMap
{
    private const ROOMS = [
        1 => ['level_id' => 20, 'controller' => 'Board 2 CC', 'door' => 3, 'door_mask' => 4],
        2 => ['level_id' => 21, 'controller' => 'Board 2 CC', 'door' => 4, 'door_mask' => 8],
        3 => ['level_id' => 22, 'controller' => 'Board 3 CC', 'door' => 1, 'door_mask' => 1],
        4 => ['level_id' => 23, 'controller' => 'Board 3 CC', 'door' => 2, 'door_mask' => 2],
        5 => ['level_id' => 24, 'controller' => 'Board 3 CC', 'door' => 3, 'door_mask' => 4],
        6 => ['level_id' => 25, 'controller' => 'Board 3 CC', 'door' => 4, 'door_mask' => 8],
    ];

    private const COMMON_LEVELS = [26, 27, 28];

    public static function forRoom(int $room): array
    {
        if (!isset(self::ROOMS[$room])) {
            throw new InvalidArgumentException('unsupported_city_center_room');
        }
        $specific = self::ROOMS[$room];
        return [
            'property' => 'City Center Guest House',
            'room' => $room,
            'access_level_ids' => array_merge([$specific['level_id']], self::COMMON_LEVELS),
            'controllers' => [
                [
                    'alias' => $specific['controller'],
                    'door_mask' => $specific['door_mask'],
                    'room_door' => $specific['door'],
                ],
                [
                    'alias' => 'Board 4 CC',
                    // Door 1 Kitchen + Door 2 Reception + Door 3 Street Door.
                    'door_mask' => 7,
                    'room_door' => null,
                ],
            ],
        ];
    }

    public static function rooms(): array
    {
        return array_keys(self::ROOMS);
    }
}
