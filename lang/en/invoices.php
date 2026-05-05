<?php

return [
    'index' => [
        'hero' => [
            'eyebrow' => 'Finance workspace',
            'title' => 'Invoices',
            'subtitle' => 'Create invoice headers here, then open an invoice to manage its itemized details.',
            'badges' => [
                'parents' => ':count parent accounts',
                'invoices' => ':count invoice records',
            ],
        ],
        'focus' => [
            'eyebrow' => 'Billing focus',
            'edit_title' => 'Editing invoice',
            'create_title' => 'New invoice header',
            'subtitle' => 'Use this screen to manage invoice identity and billing state, then move into the detail screen for itemized charges.',
        ],
        'stats' => [
            'all' => [
                'label' => 'Invoices',
                'hint' => 'All invoice headers in the current finance ledger.',
            ],
            'open' => [
                'label' => 'Open',
                'hint' => 'Issued or partial invoices still waiting on collection.',
            ],
            'draft' => [
                'label' => 'Draft',
                'hint' => 'Headers prepared but not yet finalized for collection.',
            ],
            'outstanding' => [
                'label' => 'Outstanding',
                'hint' => 'Remaining unpaid balance across active invoice records.',
            ],
        ],
        'form' => [
            'eyebrow' => 'Invoice editor',
            'edit_title' => 'Edit invoice',
            'create_title' => 'Create invoice',
            'subtitle' => 'The detailed charges are managed from the invoice detail page.',
            'fields' => [
                'parent' => 'Parent',
                'invoice_type' => 'Invoice type',
                'status' => 'Status',
                'issue_date' => 'Issue date',
                'due_date' => 'Due date',
                'discount' => 'Discount',
                'notes' => 'Notes',
            ],
            'placeholders' => [
                'parent' => 'Select parent',
            ],
            'parent_option' => ':count students',
            'create_submit' => 'Create invoice',
            'update_submit' => 'Update invoice',
        ],
        'read_only' => 'You can review invoices, but you do not have permission to change them.',
        'table' => [
            'eyebrow' => 'Ledger',
            'title' => 'Invoice records',
            'empty' => 'No invoices yet.',
            'headers' => [
                'invoice' => 'Invoice',
                'parent' => 'Parent',
                'amounts' => 'Amounts',
                'status' => 'Status',
                'actions' => 'Actions',
            ],
            'amounts' => [
                'total' => 'Total: :amount',
                'meta' => 'Paid: :paid | Items: :items',
            ],
            'actions' => [
                'detail' => 'Detail',
                'print' => 'Print',
            ],
        ],
        'messages' => [
            'created' => 'Invoice created successfully.',
            'updated' => 'Invoice updated successfully.',
            'deleted' => 'Invoice deleted successfully.',
        ],
        'errors' => [
            'delete_linked' => 'This invoice cannot be deleted while items or payments exist.',
        ],
    ],
    'detail' => [
        'back' => 'Back to invoices',
        'heading' => 'Invoice Detail',
        'subheading' => 'Manage the itemized charges for one invoice.',
        'print' => 'Print Invoice',
        'summary' => [
            'status' => 'Status: :status',
            'subtotal' => 'Subtotal',
            'discount' => 'Discount',
            'total' => 'Total',
            'paid' => 'Paid',
            'balance' => 'Balance',
        ],
        'item_form' => [
            'create_title' => 'Invoice item',
            'edit_title' => 'Edit item',
            'fields' => [
                'description' => 'Description',
                'student' => 'Student',
                'enrollment' => 'Enrollment',
                'activity' => 'Activity',
                'quantity' => 'Quantity',
                'unit_price' => 'Unit price',
            ],
            'placeholders' => [
                'student' => 'No student link',
                'enrollment' => 'No enrollment link',
                'activity' => 'No activity link',
            ],
            'save' => 'Save',
            'update' => 'Update',
            'unit_price' => 'Unit price: :amount',
            'empty' => 'No invoice items yet.',
            'messages' => [
                'created' => 'Invoice item created successfully.',
                'updated' => 'Invoice item updated successfully.',
                'deleted' => 'Invoice item deleted successfully.',
            ],
            'errors' => [
                'wrong_student' => 'The selected enrollment does not belong to the selected student.',
            ],
        ],
        'payment_form' => [
            'title' => 'Payment',
            'fields' => [
                'method' => 'Method',
                'paid_at' => 'Paid at',
                'amount' => 'Amount',
                'reference' => 'Reference',
                'notes' => 'Notes',
            ],
            'placeholders' => [
                'method' => 'Select method',
            ],
            'save' => 'Save payment',
            'messages' => [
                'created' => 'Invoice payment recorded successfully.',
                'voided' => 'Invoice payment voided successfully.',
            ],
            'void_reason' => 'Voided from the invoice detail page.',
        ],
        'tables' => [
            'items' => [
                'title' => 'Invoice items',
                'headers' => [
                    'description' => 'Description',
                    'links' => 'Links',
                    'qty' => 'Qty',
                    'amount' => 'Amount',
                    'actions' => 'Actions',
                ],
            ],
            'payments' => [
                'title' => 'Payments',
                'headers' => [
                    'date' => 'Date',
                    'method' => 'Method',
                    'amount' => 'Amount',
                    'state' => 'State',
                    'actions' => 'Actions',
                ],
                'receipt' => 'Receipt',
                'void' => 'Void',
                'empty' => 'No payments yet.',
            ],
        ],
    ],
];
