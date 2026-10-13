<?php

return [
    'title' => 'Community Contacts',
    'subtitle' => 'Store trusted people and vendors the organisation can call when help is needed.',
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
        'search' => 'Search name, category, phone, address, or notes',
        'all_categories' => 'All categories',
    ],
    'fields' => [
        'name' => 'Full name',
        'category' => 'Category or service',
        'organization' => 'Organisation',
        'phone' => 'Phone',
        'address' => 'Address',
        'notes' => 'Notes',
    ],
    'form' => [
        'create' => 'New community contact',
        'edit' => 'Edit community contact',
        'description' => 'Examples: plumber, bus driver, supplier, maintenance worker, machine owner, or any trusted helper.',
    ],
    'table' => [
        'title' => 'Community Contacts',
        'headers' => [
            'name' => 'Name',
            'category' => 'Category',
            'contact' => 'Contact',
            'notes' => 'Notes',
            'actions' => 'Actions',
        ],
        'empty' => 'No community contacts found.',
    ],
    'empty' => [
        'category' => 'Uncategorised',
    ],
    'messages' => [
        'created' => 'Community contact created successfully.',
        'updated' => 'Community contact updated successfully.',
        'deleted' => 'Community contact deleted successfully.',
    ],
];
