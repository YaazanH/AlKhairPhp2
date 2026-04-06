<?php

return [
    'group' => [
        'back' => 'Back to groups',
        'heading' => 'Group Schedules',
        'subheading' => 'Manage the recurring weekly schedule for a single group.',
        'profile' => [
            'no_course' => 'No course',
            'no_year' => 'No academic year',
            'no_teacher' => 'No teacher assigned',
        ],
        'form' => [
            'create_title' => 'New schedule slot',
            'edit_title' => 'Edit schedule slot',
            'help' => 'Each row represents one weekly meeting time for this group.',
            'fields' => [
                'day' => 'Day',
                'starts_at' => 'Starts at',
                'ends_at' => 'Ends at',
                'room_name' => 'Room name',
            ],
            'active_flag' => 'Active schedule slot',
            'create_submit' => 'Create schedule',
            'update_submit' => 'Update schedule',
        ],
        'read_only' => [
            'title' => 'Read-only access',
            'body' => 'You can review schedule slots, but you do not have permission to change them.',
        ],
        'table' => [
            'title' => 'Weekly schedule',
            'empty' => 'No schedule slots exist for this group yet.',
            'headers' => [
                'day' => 'Day',
                'time' => 'Time',
                'room' => 'Room',
                'status' => 'Status',
                'actions' => 'Actions',
            ],
        ],
        'messages' => [
            'created' => 'Schedule created successfully.',
            'updated' => 'Schedule updated successfully.',
            'deleted' => 'Schedule deleted successfully.',
        ],
        'days' => [
            '0' => 'Sunday',
            '1' => 'Monday',
            '2' => 'Tuesday',
            '3' => 'Wednesday',
            '4' => 'Thursday',
            '5' => 'Friday',
            '6' => 'Saturday',
        ],
    ],
];
