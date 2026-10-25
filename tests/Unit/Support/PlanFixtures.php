<?php

declare(strict_types=1);

namespace OCA\ServicePlanner\Tests\Unit\Support;

final class PlanFixtures {
    public static function definition(): array {
        return [
            'schemaVersion' => 1,
            'name' => 'Sunday team',
            'description' => 'Services and volunteers',
            'icon' => 'calendar',
            'color' => '#0082c9',
            'tableId' => 42,
            'personNames' => ['separators' => [',', ';', "\n"]],
            'columns' => [
                ['columnId' => 101, 'role' => 'date', 'displayLabel' => 'Date', 'position' => 0],
                ['columnId' => 102, 'role' => 'title', 'displayLabel' => 'Theme', 'position' => 1],
                ['columnId' => 103, 'role' => 'text', 'displayLabel' => 'Notes', 'position' => 2],
                ['columnId' => 104, 'role' => 'person', 'displayLabel' => 'Music', 'position' => 3],
            ],
            'services' => [
                [
                    'id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001',
                    'name' => 'Music and sound',
                    'icon' => 'music',
                    'columnIds' => [104],
                    'notAssignedValues' => ['--', '–', '-'],
                    'selfSignup' => ['enabled' => true, 'leaveEnabled' => true],
                ],
            ],
            'permissions' => [
                'managerGroups' => ['planner-admins'],
                'visibleGroups' => ['planner-admins', 'volunteers'],
            ],
            'views' => [
                [
                    'id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d002',
                    'type' => 'cards',
                    'title' => 'Upcoming',
                    'icon' => 'calendar',
                    'enabled' => true,
                    'position' => 0,
                    'columnIds' => [101, 102, 103, 104],
                    'serviceIds' => ['7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001'],
                    'period' => ['kind' => 'upcoming', 'days' => 90],
                    'sort' => [['columnId' => 101, 'direction' => 'asc']],
                    'filters' => [
                        ['id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d003', 'columnId' => 104, 'operator' => 'open', 'value' => ''],
                    ],
                ],
                [
                    'id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d004',
                    'type' => 'year',
                    'title' => 'Year grid',
                    'icon' => 'grid',
                    'enabled' => true,
                    'position' => 1,
                    'columnIds' => [101, 102, 104],
                    'serviceIds' => [],
                    'period' => ['kind' => 'year', 'year' => 'current'],
                    'sort' => [['columnId' => 101, 'direction' => 'asc']],
                    'filters' => [],
                ],
                [
                    'id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d005',
                    'type' => 'mine',
                    'title' => 'My services',
                    'icon' => 'account-check',
                    'enabled' => true,
                    'position' => 2,
                    'columnIds' => [101, 102, 104],
                    'serviceIds' => ['7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001'],
                    'period' => ['kind' => 'upcoming', 'days' => 365],
                    'sort' => [['columnId' => 101, 'direction' => 'asc']],
                    'filters' => [],
                ],
                [
                    'id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d006',
                    'type' => 'service',
                    'title' => 'Music team',
                    'icon' => 'music',
                    'enabled' => false,
                    'position' => 3,
                    'columnIds' => [101, 102, 103, 104],
                    'serviceIds' => ['7d1678a5-e28f-4b1f-b0f1-a5d7ef93d001'],
                    'period' => ['kind' => 'year', 'year' => 2027],
                    'sort' => [['columnId' => 101, 'direction' => 'desc']],
                    'filters' => [
                        ['id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d007', 'columnId' => 103, 'operator' => 'contains', 'value' => 'special'],
                        ['id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d008', 'columnId' => 102, 'operator' => 'isNotEmpty', 'value' => ''],
                    ],
                ],
            ],
            'defaultViewId' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d002',
            'flow' => [
                'actions' => [
                    ['id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d009', 'type' => 'signup', 'columnIds' => [104]],
                    ['id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d010', 'type' => 'leave', 'columnIds' => [104]],
                    ['id' => '7d1678a5-e28f-4b1f-b0f1-a5d7ef93d011', 'type' => 'setCell', 'columnIds' => [103]],
                ],
            ],
        ];
    }

    public static function metadata(): array {
        return [
            'id' => 42,
            'title' => 'Sunday services',
            'emoji' => '📋',
            'description' => 'Service schedule',
            'ownership' => 'admin',
            'columns' => [
                ['id' => 101, 'title' => 'Date', 'type' => 'datetime', 'subtype' => 'date', 'mandatory' => true, 'description' => 'Service date'],
                ['id' => 102, 'title' => 'Theme', 'type' => 'text', 'subtype' => 'line', 'mandatory' => false, 'description' => 'Service title'],
                ['id' => 103, 'title' => 'Notes', 'type' => 'text', 'subtype' => 'long', 'mandatory' => false, 'description' => 'Additional information'],
                ['id' => 104, 'title' => 'Music', 'type' => 'text', 'subtype' => 'line', 'mandatory' => false, 'description' => 'Volunteers'],
            ],
            'permissions' => ['canManage' => true, 'canUpdateRows' => true, 'sharesVisible' => true],
            'shares' => [],
            'tablesUrl' => '/apps/tables/#/table/42',
        ];
    }
}
