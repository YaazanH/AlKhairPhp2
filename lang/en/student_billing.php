<?php

return [
    'eyebrow' => 'Student billing',
    'title' => 'Student invoices',
    'subtitle' => 'Create and track invoices owned by one registered student. Parent accounts can only see invoices for their own children.',
    'create' => 'Create student invoice',
    'edit' => 'Edit student invoice',
    'open' => 'Open',
    'list' => 'Student invoices',
    'empty' => 'No student invoices yet.',
    'stats' => ['invoices' => 'Invoices', 'billed' => 'Total billed', 'outstanding' => 'Outstanding'],
    'fields' => [
        'student' => 'Student', 'choose_student' => 'Choose a registered student', 'issue_date' => 'Issue date',
        'due_date' => 'Due date', 'description' => 'Invoice item', 'quantity' => 'Quantity', 'unit_price' => 'Unit price',
        'discount' => 'Discount', 'notes' => 'Notes', 'invoice' => 'Invoice', 'date' => 'Date', 'total' => 'Total', 'balance' => 'Balance',
    ],
    'messages' => ['saved' => 'The student invoice was saved.', 'deleted' => 'The student invoice was deleted.'],
    'validation' => ['discount' => 'The discount cannot exceed the invoice subtotal.', 'paid_delete' => 'An invoice with payments cannot be deleted.'],
];
