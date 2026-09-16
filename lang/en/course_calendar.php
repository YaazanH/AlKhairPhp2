<?php

return [
    'title' => ':course Calendar',
    'filename' => 'Course calendar',
    'start' => 'Course start',
    'end' => 'Course end',
    'week' => 'Week',
    'manager' => [
        'title' => ':course Calendar',
        'fields' => [
            'date' => 'Date',
            'name' => 'Name',
            'color' => 'Colour',
        ],
        'placeholders' => [
            'name' => 'Addition name',
        ],
        'actions' => [
            'add' => 'Add to calendar',
            'save' => 'Save calendar additions',
            'open_pdf' => 'Open course calendar',
        ],
        'empty' => 'There are no additions in this course calendar.',
        'messages' => [
            'saved' => 'Course calendar additions saved successfully.',
        ],
        'errors' => [
            'date_range' => 'The date must be within the course dates.',
            'duplicate' => 'This addition already exists on the same date.',
        ],
    ],
    'weekdays' => [
        'sunday' => 'Sunday',
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
    ],
    'months' => [
        'january' => 'January',
        'february' => 'February',
        'march' => 'March',
        'april' => 'April',
        'may' => 'May',
        'june' => 'June',
        'july' => 'July',
        'august' => 'August',
        'september' => 'September',
        'october' => 'October',
        'november' => 'November',
        'december' => 'December',
    ],
    'errors' => [
        'invalid_dates' => 'A course calendar requires valid start and end dates.',
    ],
];
