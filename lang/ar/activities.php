<?php

return [
    'common' => [
        'general_activity' => 'نشاط عام',
        'states' => [
            'active' => 'نشط',
            'inactive' => 'غير نشط',
            'voided' => 'ملغى',
            'registered' => 'مسجل',
            'attended' => 'حضر',
            'cancelled' => 'ملغي',
        ],
        'actions' => [
            'finance' => 'المالية',
            'save' => 'حفظ',
            'update' => 'تحديث',
            'cancel' => 'إلغاء',
            'edit' => 'تعديل',
            'delete' => 'حذف',
            'void' => 'إلغاء',
        ],
    ],
    'index' => [
        'heading' => 'الأنشطة',
        'subheading' => 'إدارة الفعاليات والأنشطة المرتبطة بالمجموعات ونقطة الدخول المالية للتسجيلات والتحصيلات والمصروفات.',
        'stats' => [
            'all' => 'إجمالي الأنشطة',
            'active' => 'الأنشطة النشطة',
            'expected' => 'الإيراد المتوقع',
            'collected' => 'الإيراد المحصل',
        ],
        'form' => [
            'create_title' => 'نشاط جديد',
            'edit_title' => 'تعديل النشاط',
            'help' => 'أنشئ النشاط هنا، ثم افتح صفحة المالية لإدارة التسجيلات والمصروفات والمدفوعات.',
            'fields' => [
                'title' => 'العنوان',
                'activity_date' => 'تاريخ النشاط',
                'fee_amount' => 'الرسوم الافتراضية',
                'group' => 'المجموعة',
                'description' => 'الوصف',
            ],
            'placeholders' => [
                'group' => 'كل المجموعات / نشاط عام',
            ],
            'active_flag' => 'نشاط نشط',
            'create_submit' => 'إضافة النشاط',
            'update_submit' => 'تحديث النشاط',
        ],
        'read_only' => [
            'title' => 'وصول للقراءة فقط',
            'body' => 'يمكنك مراجعة سجلات الأنشطة، لكن ليست لديك صلاحية لتغييرها.',
        ],
        'table' => [
            'title' => 'سجلات الأنشطة',
            'empty' => 'لا توجد سجلات أنشطة بعد.',
            'headers' => [
                'activity' => 'النشاط',
                'date' => 'التاريخ',
                'registrations' => 'التسجيلات',
                'financials' => 'المالية',
                'status' => 'الحالة',
                'actions' => 'الإجراءات',
            ],
            'financials' => [
                'expected' => 'المتوقع: :amount',
                'breakdown' => 'المحصل: :collected | المصروفات: :expenses',
            ],
        ],
        'messages' => [
            'created' => 'تم إنشاء النشاط بنجاح.',
            'updated' => 'تم تحديث النشاط بنجاح.',
            'deleted' => 'تم حذف النشاط بنجاح.',
        ],
        'errors' => [
            'delete_linked' => 'لا يمكن حذف هذا النشاط ما دامت هناك سجلات مالية مرتبطة به.',
        ],
    ],
    'finance' => [
        'back' => 'العودة إلى الأنشطة',
        'heading' => 'مالية النشاط',
        'subheading' => 'التسجيلات والتحصيلات والمصروفات الخاصة بنشاط واحد.',
        'summary' => [
            'expected' => 'المتوقع',
            'collected' => 'المحصل',
            'expenses' => 'المصروفات',
            'net' => 'الصافي',
        ],
        'registrations' => [
            'create_title' => 'التسجيل',
            'edit_title' => 'تعديل التسجيل',
            'table_title' => 'التسجيلات',
            'empty' => 'لا توجد تسجيلات بعد.',
            'fields' => [
                'student' => 'الطالب',
                'enrollment' => 'التسجيل',
                'fee' => 'الرسوم',
                'status' => 'الحالة',
                'notes' => 'الملاحظات',
            ],
            'placeholders' => [
                'student' => 'اختر الطالب',
                'enrollment' => 'بدون ربط بتسجيل',
            ],
            'headers' => [
                'student' => 'الطالب',
                'enrollment' => 'التسجيل',
                'fee' => 'الرسوم',
                'paid' => 'المدفوع',
                'status' => 'الحالة',
                'actions' => 'الإجراءات',
            ],
            'messages' => [
                'created' => 'تم إنشاء التسجيل بنجاح.',
                'updated' => 'تم تحديث التسجيل بنجاح.',
                'deleted' => 'تم حذف التسجيل بنجاح.',
            ],
            'errors' => [
                'wrong_student' => 'التسجيل المحدد لا يعود للطالب المختار.',
                'wrong_group' => 'يجب أن يكون التسجيل المحدد ضمن مجموعة هذا النشاط.',
                'delete_linked' => 'لا يمكن حذف هذا التسجيل ما دامت هناك مدفوعات نشطة مرتبطة به.',
            ],
        ],
        'payments' => [
            'title' => 'الدفعة',
            'table_title' => 'المدفوعات',
            'empty' => 'لا توجد مدفوعات بعد.',
            'fields' => [
                'registration' => 'التسجيل',
                'method' => 'طريقة الدفع',
                'date' => 'التاريخ',
                'amount' => 'المبلغ',
                'reference' => 'المرجع',
                'notes' => 'الملاحظات',
            ],
            'placeholders' => [
                'registration' => 'اختر التسجيل',
                'method' => 'اختر الطريقة',
            ],
            'headers' => [
                'date' => 'التاريخ',
                'student' => 'الطالب',
                'method' => 'الطريقة',
                'amount' => 'المبلغ',
                'state' => 'الحالة',
                'actions' => 'الإجراءات',
            ],
            'save' => 'حفظ الدفعة',
            'messages' => [
                'created' => 'تم تسجيل دفعة النشاط بنجاح.',
                'voided' => 'تم إلغاء دفعة النشاط بنجاح.',
            ],
            'void_reason' => 'تم إلغاؤها من صفحة مالية النشاط.',
        ],
        'expenses' => [
            'create_title' => 'المصروف',
            'edit_title' => 'تعديل المصروف',
            'table_title' => 'المصروفات',
            'empty' => 'لا توجد مصروفات بعد.',
            'fields' => [
                'category' => 'الفئة',
                'amount' => 'المبلغ',
                'spent_on' => 'تاريخ الصرف',
                'description' => 'الوصف',
            ],
            'placeholders' => [
                'category' => 'اختر الفئة',
            ],
            'headers' => [
                'date' => 'التاريخ',
                'category' => 'الفئة',
                'description' => 'الوصف',
                'amount' => 'المبلغ',
                'actions' => 'الإجراءات',
            ],
            'messages' => [
                'created' => 'تم تسجيل مصروف النشاط بنجاح.',
                'updated' => 'تم تحديث مصروف النشاط بنجاح.',
                'deleted' => 'تم حذف مصروف النشاط بنجاح.',
            ],
        ],
    ],
];
