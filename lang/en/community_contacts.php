<?php

return [
    'title' => 'Community Contacts',
    'subtitle' => 'Store trusted people and vendors the organization can call when help is needed.',
    'stats' => [
        'all' => 'Contacts',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'categories' => 'Categories',
    ],
    'sections' => [
        'directory' => [
            'title' => 'Helpful people directory',
            'copy' => ':count contacts match the current filters.',
        ],
    ],
    'actions' => [
        'create' => 'New contact',
        'save' => 'Create contact',
        'update' => 'Update contact',
    ],
    'filters' => [
        'search' => 'Search name, category, phone, email, address, or notes',
        'all_categories' => 'All categories',
        'all_statuses' => 'All statuses',
    ],
    'statuses' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],
    'fields' => [
        'name' => 'Full name',
        'category' => 'Category or service',
        'organization' => 'Organization',
        'phone' => 'Phone',
        'secondary_phone' => 'Secondary phone',
        'email' => 'Email',
        'address' => 'Address',
        'notes' => 'Notes',
        'is_active' => 'Active contact',
    ],
    'form' => [
        'create' => 'New community contact',
        'edit' => 'Edit community contact',
        'description' => 'Examples: plumber, bus driver, supplier, maintenance worker, machine owner, or any trusted helper.',
    ],
    'table' => [
        'headers' => [
            'name' => 'Name',
            'category' => 'Category',
            'contact' => 'Contact',
            'status' => 'Status',
            'actions' => 'Actions',
        ],
        'empty' => 'No community contacts found.',
    ],
    'empty' => [
        'category' => 'Uncategorized',
    ],
    'messages' => [
        'created' => 'Community contact created successfully.',
        'updated' => 'Community contact updated successfully.',
        'deleted' => 'Community contact deleted successfully.',
    ],
];
