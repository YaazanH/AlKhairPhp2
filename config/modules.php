<?php

return [
    // Registry ownership is code-defined; package membership is administrator-owned.
    'definitions' => [
        'foundation' => ['name' => 'Foundation', 'requires' => []],
        'students' => ['name' => 'Students', 'requires' => []],
        'parents' => ['name' => 'Parents', 'requires' => ['students']],
        'parent_portal' => ['name' => 'Parent Portal', 'requires' => ['parents']],
        'teachers' => ['name' => 'Teachers', 'requires' => []],
        'classes' => ['name' => 'Classes and Enrollment', 'requires' => ['students', 'teachers']],
        'student_attendance' => ['name' => 'Student Attendance', 'requires' => ['students']],
        'teacher_attendance' => ['name' => 'Teacher Attendance', 'requires' => ['teachers']],
        'memorization' => ['name' => 'Memorization', 'requires' => ['classes']],
        'quran_tests' => ['name' => 'Quran Tests', 'requires' => ['classes']],
        'assessments' => ['name' => 'General Assessments', 'requires' => ['classes']],
        'points_rewards' => ['name' => 'Points and Rewards', 'requires' => ['students']],
        'activities' => ['name' => 'Activities', 'requires' => ['students']],
        'finance' => ['name' => 'Income and Expenses', 'requires' => []],
        'student_billing' => ['name' => 'Student Billing', 'requires' => ['students', 'finance']],
        'curriculum' => ['name' => 'Curriculum Management', 'requires' => ['classes']],
        'custom_templates' => ['name' => 'Custom Templates', 'requires' => []],
        // Version 1 ships a standard student card. More card subjects can be added later.
        'id_cards' => ['name' => 'ID Cards', 'requires' => ['students']],
        'public_website' => ['name' => 'Public Website', 'requires' => []],
    ],
    // Setup versions are code-owned. Raising a version asks tenant administrators
    // to review only that module again; it never resets unrelated modules.
    'setup' => [
        'foundation' => ['version' => 1, 'required' => true],
        'students' => ['version' => 1],
        'parents' => ['version' => 1],
        'parent_portal' => ['version' => 1],
        'teachers' => ['version' => 1],
        'classes' => ['version' => 1, 'settings_route' => 'settings.organization'],
        'student_attendance' => ['version' => 1],
        'teacher_attendance' => ['version' => 1],
        'memorization' => ['version' => 1],
        'quran_tests' => ['version' => 1],
        'assessments' => ['version' => 1],
        'points_rewards' => ['version' => 1, 'settings_route' => 'settings.points'],
        'activities' => ['version' => 1],
        'finance' => ['version' => 1, 'settings_route' => 'settings.finance'],
        'student_billing' => ['version' => 1],
        'curriculum' => ['version' => 1, 'settings_route' => 'settings.curriculum-subjects'],
        'custom_templates' => ['version' => 1, 'settings_route' => 'print-templates.templates.index'],
        'id_cards' => ['version' => 1, 'settings_route' => 'id-cards.templates.index'],
        'public_website' => ['version' => 1, 'settings_route' => 'settings.website'],
    ],
    'legacy_core' => ['students', 'parents', 'parent_portal', 'teachers', 'classes', 'student_attendance', 'teacher_attendance', 'memorization', 'quran_tests', 'assessments', 'points_rewards', 'activities', 'curriculum', 'public_website'],
    // Initial explicit action contract. Remaining actions are classified in their vertical sprint.
    // Unknown actions are denied, never inferred from a role or a route name.
    'actions' => [
        'students.view' => ['students'], 'students.create' => ['students'],
        'parents.view' => ['parents'], 'parents.create' => ['parents'],
        'parent_portal.access' => ['parent_portal'],
        'groups.create' => ['classes'],
        'attendance.student.view' => ['student_attendance'],
        'attendance.teacher.view' => ['teacher_attendance'],
        'activities.register' => ['activities'],
        'activities.payments' => ['activities', 'finance'],
        'invoices.view' => ['student_billing'],
        'id_cards.students.print' => ['id_cards', 'students'],
        'id_cards.students.design' => ['id_cards', 'students', 'custom_templates'],
        'id_cards.students.standard_print' => ['id_cards', 'students'],
        'custom_templates.manage' => ['custom_templates'],
        'public_website.manage' => ['public_website'],
    ],
];
