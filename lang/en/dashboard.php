<?php

return [
    'roles' => [
        'manager' => 'Management',
        'teacher' => 'Teacher',
        'parent' => 'Parent',
        'student' => 'Student',
        'unassigned' => 'Unassigned',
    ],
    'hero' => [
        'workspace' => ':role workspace',
        'signed_in_as' => 'Signed in as',
        'role' => 'Role',
        'job' => 'Job',
        'current_academic_year' => 'Current academic year',
        'primary_signal' => 'Primary signal',
        'live_snapshot' => 'Live snapshot',
        'workspace_area' => 'Workspace area',
        'items' => '{1} :count item|[2,*] :count items',
    ],
    'record_states' => [
        'most_recent' => 'Most recent',
        'in_scope' => 'In scope',
    ],
    'common' => [
        'no_course' => 'No course',
        'no_year' => 'No year',
        'no_teacher' => 'No teacher',
        'no_grade' => 'No grade',
        'no_school' => 'No school',
        'no_group' => 'No group',
        'no_identifier' => 'No account identifier',
        'active_enrollments' => ':count active enrollments',
        'active_students' => ':count active students',
        'points_pages' => 'Points :points | Pages :pages',
    ],
    'manager' => [
        'heading' => 'Management Dashboard',
        'subheading' => 'Operational view across people, classes, memorization, finance, and reporting.',
        'intro' => 'Move from this screen into the live workflows for student records, teaching operations, finance, and analysis.',
        'profile_meta_current_year' => 'Current academic year: :year',
        'profile_meta_no_year' => 'No current academic year selected yet.',
        'stats' => [
            'enrolled_students' => ['label' => 'Enrolled Students', 'hint' => 'Students with at least one active enrollment'],
            'students' => ['label' => 'Students', 'hint' => 'All student profiles'],
            'teachers' => ['label' => 'Teachers', 'hint' => 'Main and assistant teachers'],
            'parents' => ['label' => 'Parents', 'hint' => 'Family contact profiles'],
            'active_groups' => ['label' => 'Active Groups', 'hint' => 'Currently running groups'],
            'active_enrollments' => ['label' => 'Active Enrollments', 'hint' => 'Students linked to active groups'],
            'current_year_groups' => ['label' => 'Current Year Groups', 'hint' => 'Groups in the current academic year'],
            'current_year_memorized_pages' => ['label' => 'Current Year Pages', 'hint' => 'Unique memorized pages first recorded in current-year groups'],
            'total_points' => ['label' => 'Total Points', 'hint' => 'Net active points across all students'],
        ],
        'cards' => [
            'people' => [
                'title' => 'People and Classes',
                'body' => 'Profiles, groups, schedules, and enrollments are ready for daily administration with permission-aware controls.',
            ],
            'tracking' => [
                'title' => 'Tracking and Finance',
                'body' => 'Attendance, memorization, assessments, points, invoices, activities, settings, and reports are connected on the same operational dataset.',
            ],
        ],
        'records' => [
            'heading' => 'Recent Groups',
            'empty' => 'No groups exist yet.',
        ],
    ],
    'teacher' => [
        'heading' => 'Teacher Dashboard',
        'subheading' => 'Your assigned groups and the students currently attached to them.',
        'intro' => 'Use your assignments as the launch point for attendance, memorization, assessments, and student notes.',
        'stats' => [
            'assigned_groups' => ['label' => 'Assigned Groups', 'hint' => 'Main and assistant assignments'],
            'active_groups' => ['label' => 'Active Groups', 'hint' => 'Assignments currently running'],
            'active_students' => ['label' => 'Active Students', 'hint' => 'Students in your active groups'],
            'current_year_groups' => ['label' => 'Current Year Groups', 'hint' => 'Assignments in the current academic year'],
        ],
        'cards' => [
            'workflow' => [
                'title' => 'Teaching Workflow',
                'body' => 'Your access stays limited to assigned groups, while attendance, memorization, results, and notes remain one step away.',
            ],
        ],
        'records' => [
            'heading' => 'Your Groups',
            'empty' => 'No group assignments are linked to this teacher yet.',
        ],
    ],
    'parent' => [
        'heading' => 'Parent Dashboard',
        'subheading' => 'Your children, their enrollments, and the core academic summary available today.',
        'intro' => 'Follow family-linked students, their enrollments, points, and memorization totals from one shared family view.',
        'profile_meta_no_phone' => 'No phone number recorded',
        'stats' => [
            'students' => ['label' => 'Students', 'hint' => 'Children linked to your family profile'],
            'active_enrollments' => ['label' => 'Active Enrollments', 'hint' => 'Current course participation'],
            'cached_points' => ['label' => 'Cached Points', 'hint' => 'Current enrollment point totals'],
            'memorized_pages' => ['label' => 'Memorized Pages', 'hint' => 'Cached memorization totals'],
        ],
        'cards' => [
            'family' => [
                'title' => 'Family View',
                'body' => 'Everything shown here is restricted to your own family profile, keeping the parent experience focused and private.',
            ],
        ],
        'records' => [
            'heading' => 'Your Students',
            'empty' => 'No students are linked to this parent profile yet.',
        ],
    ],
    'student' => [
        'heading' => 'Student Dashboard',
        'subheading' => 'Your current enrollments and the cached summary tied to them.',
        'intro' => 'Review your active classes, points, memorization totals, and current Quran position without leaving your own profile scope.',
        'profile_meta_no_grade' => 'No grade level recorded',
        'stats' => [
            'enrollments' => ['label' => 'Enrollments', 'hint' => 'All enrollment records'],
            'active_enrollments' => ['label' => 'Active Enrollments', 'hint' => 'Current course participation'],
            'cached_points' => ['label' => 'Cached Points', 'hint' => 'Point total from your enrollments'],
            'memorized_pages' => ['label' => 'Memorized Pages', 'hint' => 'Cached memorization total'],
            'current_juz' => ['label' => 'Current Juz', 'hint' => 'Current memorization anchor'],
        ],
        'cards' => [
            'student' => [
                'title' => 'Student View',
                'body' => 'This dashboard is limited to your own profile and enrollments.',
            ],
        ],
        'records' => [
            'heading' => 'Your Enrollments',
            'empty' => 'No enrollments are linked to this student yet.',
        ],
    ],
    'unassigned' => [
        'heading' => 'Dashboard Setup',
        'subheading' => 'This account can sign in, but it does not have an Alkhair business role yet.',
        'intro' => 'Assign a role and link the account to the right profile before exposing role-specific modules.',
        'cards' => [
            'next' => [
                'title' => 'Next Action',
                'body' => 'An admin or manager needs to assign the correct role and connect this account to a parent, teacher, or student profile.',
            ],
        ],
        'records' => [
            'heading' => 'Current State',
            'empty' => 'No role-linked records are available for this account.',
        ],
    ],
    'missing_profile' => [
        'subheading' => 'The account role exists, but the matching business profile is still missing.',
        'card_title' => 'Link Required',
        'card_body' => 'Create or link the matching profile record so this dashboard can become assignment-aware.',
        'records_heading' => 'Current State',
        'records_empty' => 'There is no linked profile data yet for this role.',
        'teacher' => [
            'heading' => 'Teacher Dashboard',
            'message' => 'Your account has the teacher role, but it is not linked to a teacher profile yet.',
        ],
        'parent' => [
            'heading' => 'Parent Dashboard',
            'message' => 'Your account has the parent role, but it is not linked to a parent profile yet.',
        ],
        'student' => [
            'heading' => 'Student Dashboard',
            'message' => 'Your account has the student role, but it is not linked to a student profile yet.',
        ],
    ],
];
