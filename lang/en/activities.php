<?php

return [
    'common' => [
        'general_activity' => 'General activity',
        'audience' => [
            'single_group' => 'One group',
            'multiple_groups' => 'Multiple groups',
            'all_groups' => 'All groups',
            'unassigned' => 'No group assigned',
        ],
        'states' => [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'voided' => 'Voided',
            'pending' => 'Awaiting response',
            'registered' => 'Registered',
            'declined' => 'Declined',
            'attended' => 'Attended',
            'cancelled' => 'Cancelled',
        ],
        'actions' => [
            'finance' => 'Finance',
            'save' => 'Save',
            'update' => 'Update',
            'cancel' => 'Cancel',
            'edit' => 'Edit',
            'delete' => 'Delete',
            'void' => 'Void',
        ],
    ],
    'index' => [
        'heading' => 'Activities',
        'subheading' => 'Manage events, group-linked activities, and the finance entry point for registrations, collections, and expenses.',
        'stats' => [
            'all' => 'Total activities',
            'active' => 'Active activities',
            'expected' => 'Expected revenue',
            'collected' => 'Collected revenue',
        ],
        'form' => [
            'create_title' => 'New activity',
            'edit_title' => 'Edit activity',
            'help' => 'Create the activity here, then open the finance page to manage registrations, expenses, and payments.',
            'fields' => [
                'title' => 'Title',
                'activity_date' => 'Activity date',
                'fee_amount' => 'Default fee amount',
                'audience_scope' => 'Audience',
                'group' => 'Group',
                'groups' => 'Target groups',
                'description' => 'Description',
            ],
            'placeholders' => [
                'group' => 'Select one group',
            ],
            'help_multiple_groups' => 'Hold Ctrl/Cmd to select more than one group.',
            'all_groups_hint' => 'This activity will be visible to parents and staff across all active groups.',
            'active_flag' => 'Active activity',
            'create_submit' => 'Create activity',
            'update_submit' => 'Update activity',
            'errors' => [
                'single_group_required' => 'Select a group when the activity targets one group.',
                'multiple_groups_required' => 'Select at least one group when the activity targets multiple groups.',
            ],
        ],
        'read_only' => [
            'title' => 'Read-only access',
            'body' => 'You can review activity records, but you do not have permission to change them.',
        ],
        'table' => [
            'title' => 'Activity records',
            'empty' => 'No activity records yet.',
            'headers' => [
                'activity' => 'Activity',
                'audience' => 'Audience',
                'date' => 'Date',
                'registrations' => 'Registrations',
                'financials' => 'Financials',
                'status' => 'Status',
                'actions' => 'Actions',
            ],
            'financials' => [
                'expected' => 'Expected: :amount',
                'breakdown' => 'Collected: :collected | Expenses: :expenses',
            ],
        ],
        'messages' => [
            'created' => 'Activity created successfully.',
            'updated' => 'Activity updated successfully.',
            'deleted' => 'Activity deleted successfully.',
        ],
        'errors' => [
            'delete_linked' => 'This activity cannot be deleted while finance records exist.',
        ],
    ],
    'finance' => [
        'back' => 'Back to activities',
        'heading' => 'Activity Finance',
        'subheading' => 'Registrations, collections, and expenses for one activity.',
        'summary' => [
            'expected' => 'Expected',
            'collected' => 'Collected',
            'expenses' => 'Expenses',
            'net' => 'Net',
        ],
        'registrations' => [
            'create_title' => 'Registration',
            'edit_title' => 'Edit registration',
            'table_title' => 'Registrations',
            'empty' => 'No registrations yet.',
            'fields' => [
                'student' => 'Student',
                'enrollment' => 'Enrollment',
                'fee' => 'Fee',
                'status' => 'Status',
                'notes' => 'Notes',
            ],
            'placeholders' => [
                'student' => 'Select student',
                'enrollment' => 'No enrollment link',
            ],
            'headers' => [
                'student' => 'Student',
                'enrollment' => 'Enrollment',
                'fee' => 'Fee',
                'paid' => 'Paid',
                'status' => 'Status',
                'actions' => 'Actions',
            ],
            'messages' => [
                'created' => 'Registration created successfully.',
                'updated' => 'Registration updated successfully.',
                'deleted' => 'Registration deleted successfully.',
            ],
            'errors' => [
                'wrong_student' => 'The selected enrollment does not belong to the selected student.',
                'wrong_group' => 'The selected enrollment must belong to one of this activity target groups.',
                'delete_linked' => 'This registration cannot be deleted while active payments exist.',
            ],
        ],
        'payments' => [
            'title' => 'Payment',
            'table_title' => 'Payments',
            'empty' => 'No payments yet.',
            'fields' => [
                'registration' => 'Registration',
                'method' => 'Method',
                'date' => 'Date',
                'amount' => 'Amount',
                'reference' => 'Reference',
                'notes' => 'Notes',
            ],
            'placeholders' => [
                'registration' => 'Select registration',
                'method' => 'Select method',
            ],
            'headers' => [
                'date' => 'Date',
                'student' => 'Student',
                'method' => 'Method',
                'amount' => 'Amount',
                'state' => 'State',
                'actions' => 'Actions',
            ],
            'save' => 'Save payment',
            'messages' => [
                'created' => 'Activity payment recorded successfully.',
                'voided' => 'Activity payment voided successfully.',
            ],
            'void_reason' => 'Voided from the activity finance page.',
        ],
        'expenses' => [
            'create_title' => 'Expense',
            'edit_title' => 'Edit expense',
            'table_title' => 'Expenses',
            'empty' => 'No expenses yet.',
            'fields' => [
                'category' => 'Category',
                'amount' => 'Amount',
                'spent_on' => 'Spent on',
                'description' => 'Description',
            ],
            'placeholders' => [
                'category' => 'Select category',
            ],
            'headers' => [
                'date' => 'Date',
                'category' => 'Category',
                'description' => 'Description',
                'amount' => 'Amount',
                'actions' => 'Actions',
            ],
            'messages' => [
                'created' => 'Activity expense recorded successfully.',
                'updated' => 'Activity expense updated successfully.',
                'deleted' => 'Activity expense deleted successfully.',
            ],
        ],
    ],
    'family' => [
        'heading' => 'Family Activities',
        'subheading' => 'Review activities for your students, check the fee, and confirm whether each student will attend.',
        'stats' => [
            'activities' => 'Visible activities',
            'students' => 'My students',
            'responses' => 'Student responses',
        ],
        'meta' => [
            'date' => 'Date',
            'fee' => 'Fee',
            'audience' => 'Audience',
        ],
        'table' => [
            'headers' => [
                'student' => 'Student',
                'group' => 'Group',
                'response' => 'Response',
                'actions' => 'Actions',
            ],
        ],
        'actions' => [
            'attend' => 'Yes, attending',
            'decline' => 'No, not attending',
        ],
        'messages' => [
            'registered' => 'Attendance was confirmed for this activity.',
            'declined' => 'The activity was declined for this student.',
        ],
        'errors' => [
            'not_eligible' => 'This student is not eligible for the selected activity.',
            'locked_after_payment' => 'This activity response cannot be changed after payments have been recorded.',
        ],
        'empty' => [
            'title' => 'No activities to review',
            'body' => 'No active activities are currently targeted to your students’ groups.',
        ],
    ],
];
