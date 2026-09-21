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
        // Enrollment-independent manual points remains a later business decision.
        'points_rewards' => ['name' => 'Points and Rewards', 'requires' => ['students']],
        'activities' => ['name' => 'Activities', 'requires' => ['students']],
        'finance' => ['name' => 'Income and Expenses', 'requires' => []],
        'student_billing' => ['name' => 'Student Billing', 'requires' => ['students', 'finance']],
        'curriculum' => ['name' => 'Curriculum Management', 'requires' => ['classes']],
        'custom_templates' => ['name' => 'Custom Templates', 'requires' => []],
        'id_cards' => ['name' => 'ID Cards', 'requires' => [], 'requires_any' => ['students', 'teachers', 'parents']],
        'public_website' => ['name' => 'Public Website', 'requires' => []],
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
    ],
];
