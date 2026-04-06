<?php

return [
    'heading' => 'Student Notes',
    'subheading' => 'Track parent feedback, teacher observations, and management-only notes with enrollment context when needed.',
    'stats' => [
        'all' => 'Accessible notes',
        'parent_visible' => 'Visible to parents',
        'today' => 'Added today',
    ],
    'form' => [
        'create_title' => 'New student note',
        'edit_title' => 'Edit student note',
        'teacher_help' => 'Teachers can only manage their own notes for students in assigned groups.',
        'manager_help' => 'Managers can log notes from teachers, parents, or management and choose who can view them.',
        'fields' => [
            'student' => 'Student',
            'enrollment' => 'Enrollment',
            'source' => 'Source',
            'visibility' => 'Visibility',
            'noted_at' => 'Noted at',
            'body' => 'Note',
        ],
        'placeholders' => [
            'student' => 'Select student',
            'enrollment' => 'General student note',
        ],
        'create_submit' => 'Create note',
        'update_submit' => 'Update note',
    ],
    'read_only' => 'You can review notes, but you do not have permission to create or update them.',
    'log' => [
        'title' => 'Student note log',
        'subtitle' => 'Filter by student, source, or visibility to review the history.',
        'empty' => 'No student notes match the current filters.',
        'filters' => [
            'all_students' => 'All students',
            'all_sources' => 'All sources',
            'all_visibility' => 'All visibility',
            'clear' => 'Clear',
        ],
        'general_note' => 'General student note',
        'unknown_group' => 'Unknown group',
        'unknown_author' => 'Unknown author',
    ],
    'sources' => [
        'management' => 'Management',
        'teacher' => 'Teacher',
        'parent' => 'Parent',
        'system' => 'System',
    ],
    'visibility' => [
        'private_teacher' => 'Private teacher',
        'private_management' => 'Private management',
        'shared_internal' => 'Shared internal',
        'visible_to_parent' => 'Visible to parent',
    ],
    'messages' => [
        'created' => 'Student note created successfully.',
        'updated' => 'Student note updated successfully.',
        'deleted' => 'Student note deleted successfully.',
    ],
];
