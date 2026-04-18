<?php

return [
    'actions' => [
        'title' => 'Action Barcodes',
        'subtitle' => 'Print action labels for offline scanning, and upload the scanner memory command barcode images from the Sunlux manual.',
        'auto_attendance_note' => 'Synced from attendance statuses.',
        'auto_point_note' => 'Synced from point settings.',
        'generated_point_name' => ':type (:points points)',
        'stale_point_note' => 'Disabled because this point barcode no longer matches an active point setting.',
        'stats' => [
            'actions' => 'Actions',
            'active' => 'Active',
        ],
        'types' => [
            'attendance' => 'Attendance',
            'points' => 'Points',
        ],
        'buttons' => [
            'disable' => 'Disable',
            'enable' => 'Enable',
            'print_selected' => 'Print selected',
        ],
        'errors' => [
            'duplicate_code' => 'Barcode code :code already exists.',
        ],
        'messages' => [
            'saved' => 'Barcode action saved.',
            'status_updated' => 'Barcode action status updated.',
        ],
        'print' => [
            'title' => 'Printable action labels',
            'subtitle' => 'Choose one or more active actions and print them as barcode labels.',
        ],
        'table' => [
            'empty' => 'No barcode actions are available yet.',
            'synced_from_settings' => 'Synced from settings',
            'title' => 'Available actions',
            'headers' => [
                'action' => 'Action',
                'actions' => 'Actions',
                'code' => 'Barcode value',
                'print' => 'Print',
                'status' => 'Status',
                'type' => 'Type',
            ],
        ],
        'sync' => [
            'attendance_source' => 'Attendance barcodes come from active student attendance statuses. Present and late point effects still run through the existing point policy logic.',
            'manage_points' => 'Open point settings',
            'points_source' => 'Point barcodes are generated from active point types in Settings / Points using their configured point amount. Change the point type amount there, then reopen this page to refresh the barcode labels.',
            'subtitle' => 'This page prints barcode labels only. It does not create point types or point amounts.',
            'title' => 'Synced from settings',
        ],
    ],
    'settings' => [
        'title' => 'Scanner commands',
        'subtitle' => 'Upload the Sunlux XL-9610 manual barcodes for Upload All Data and Clear All Saved Data.',
        'buttons' => [
            'save' => 'Save scanner images',
        ],
        'fields' => [
            'clear_command_image' => 'Clear scanner memory barcode image',
            'dump_command_image' => 'Dump all scanner data barcode image',
        ],
        'messages' => [
            'saved' => 'Scanner barcode images saved.',
        ],
    ],
    'print' => [
        'fields' => [
            'label_height_mm' => 'Label height (mm)',
            'label_width_mm' => 'Label width (mm)',
        ],
        'preview' => [
            'title' => 'Print action barcodes',
            'subtitle' => 'Print this page and use the labels before scanning student cards.',
            'buttons' => [
                'back' => 'Back',
                'print' => 'Print',
            ],
        ],
        'warnings' => [
            'page_too_small' => 'The page is too small for this label size.',
            'tight_fit' => 'The labels fit tightly on this page.',
            'unused_space' => 'There is unused space on the page. Adjust label or gap sizes if needed.',
        ],
    ],
    'import' => [
        'title' => 'Scanner Import',
        'subtitle' => 'Paste the scanner memory dump, preview it, then apply attendance and point actions in FIFO order.',
        'actions' => [
            'apply' => 'Apply import',
            'manage_actions' => 'Manage action barcodes',
            'preview' => 'Preview dump',
        ],
        'context' => [
            'copy' => 'Choose the course first so every student barcode is matched against the correct active enrollment.',
            'title' => 'Import context',
            'today' => 'Working date: :date',
        ],
        'dump' => [
            'copy' => 'Click inside the dump box, scan Upload All Data, then preview the rows before applying them.',
            'focus_hint' => 'scanner input area',
            'review_hint' => 'Preview first when you are not sure. Apply only after the ready, skipped, and error counts look correct.',
            'title' => 'Dump workspace',
        ],
        'errors' => [
            'course_not_accessible' => 'This course is not available for your account.',
            'empty_dump' => 'The scanner dump is empty.',
            'fix_preview_errors' => 'Fix the preview errors before applying this import.',
            'invalid_attendance_action' => 'This attendance action is no longer valid.',
            'invalid_point_action' => 'This point action is no longer valid.',
            'multiple_enrollments_in_course' => ':student has more than one active enrollment in the selected course.',
            'no_enrollment_in_course' => ':student does not have an active enrollment in the selected course.',
            'no_ready_rows' => 'There are no student rows ready to import.',
            'student_before_action' => 'Student barcode was scanned before any action barcode.',
            'student_missing' => 'No student was found for barcode :number.',
            'unknown_barcode' => 'Unknown barcode value.',
            'unsupported_action' => 'This barcode action type is not supported.',
        ],
        'fields' => [
            'attendance_date' => 'Attendance date',
            'course' => 'Course',
            'raw_dump' => 'Scanner memory dump',
        ],
        'form' => [
            'title' => 'Import memory dump',
            'subtitle' => 'Select the course, click the dump box, then scan the scanner command that uploads all saved data.',
        ],
        'history' => [
            'empty' => 'No scanner imports yet.',
            'processed' => ':count records processed',
            'subtitle' => 'Latest imports created by your account.',
            'title' => 'Recent imports',
        ],
        'messages' => [
            'action_selected' => 'Current action set to :action.',
            'applied' => 'Applied.',
            'imported' => ':count scanner rows applied.',
            'ready' => 'Ready to apply.',
        ],
        'notes' => [
            'attendance' => 'Recorded from barcode scanner import.',
            'points' => 'Barcode scanner action: :action',
        ],
        'placeholders' => [
            'course' => 'Select course',
            'raw_dump' => "Example:\nACT-ATT-PRESENT\n12\n13\nACT-PTS-PARTICIPATION-REWARD\n12",
        ],
        'preview' => [
            'title' => 'Preview',
            'summary' => 'Ready: :ready | Skipped: :skipped | Errors: :errors',
            'headers' => [
                'action' => 'Action',
                'group' => 'Group',
                'message' => 'Message',
                'result' => 'Result',
                'sequence' => '#',
                'student' => 'Student',
                'value' => 'Barcode',
            ],
        ],
        'results' => [
            'applied' => 'Applied',
            'context' => 'Action',
            'error' => 'Error',
            'ready' => 'Ready',
            'skipped' => 'Skipped',
        ],
        'scanner_commands' => [
            'command' => 'Command',
            'missing' => 'Upload this command barcode image from the action barcode page.',
            'subtitle' => 'These images come from the Sunlux scanner manual. The app does not generate them.',
            'title' => 'Scanner command barcodes',
        ],
        'stats' => [
            'errors' => 'Errors',
            'ready' => 'Ready rows',
            'skipped' => 'Skipped',
        ],
        'warnings' => [
            'duplicate_scan' => 'Duplicate scan skipped in this import.',
        ],
        'workflow' => [
            'print' => [
                'copy' => 'Print the action barcode labels and make sure student cards are available.',
                'title' => 'Prepare labels',
            ],
            'review' => [
                'copy' => 'Dump the scanner memory, preview the sequence, then apply the clean rows.',
                'title' => 'Review and apply',
            ],
            'scan' => [
                'copy' => 'Scan one action barcode, then scan every student card that should receive that action.',
                'title' => 'Scan offline',
            ],
        ],
    ],
];
