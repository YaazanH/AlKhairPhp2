<?php

return [
    'title' => 'مناهج',
    'my_title' => 'منهاجي',
    'subtitle' => 'تخطيط مواد ودروس الدورة ومتابعة تقدم تدريس كل مجموعة.',
    'teacher_subtitle' => 'تابع المنهاج المعيّن لمجموعتك وسجّل الدروس التي تم تدريسها.',
    'settings' => ['meta' => 'المحتوى التعليمي', 'title' => 'المواد والمراجع', 'subtitle' => 'أنشئ المواد والمراجع القابلة للاستخدام في المناهج.'],
    'actions' => [
        'add_curriculum' => 'إضافة منهاج', 'edit' => 'تعديل', 'open' => 'فتح', 'save' => 'حفظ', 'delete' => 'حذف',
        'add_subject' => 'إضافة مادة', 'add_lesson' => 'إضافة درس', 'add_custom_lesson' => 'درس مخصص',
        'add_topic' => 'إضافة موضوع',
        'add_resource' => 'إضافة مرجع', 'record' => 'تسجيل', 'update_progress' => 'تحديث التقدم', 'manage_subjects' => 'إعدادات المواد',
        'add_standalone_book' => 'إضافة كتاب مستقل', 'download_books' => 'تحميل الكتب', 'download' => 'تحميل',
    ],
    'fields' => [
        'curriculum' => 'المنهاج', 'name' => 'الاسم', 'course' => 'الدورة', 'grade' => 'الصف', 'subject' => 'المادة',
        'subjects' => 'المواد', 'lessons' => 'الدروس', 'lesson' => 'الدرس', 'page_count' => 'عدد الصفحات',
        'importance' => 'الأهمية', 'resources' => 'المراجع', 'book_name' => 'اسم الكتاب', 'author' => 'المؤلف',
        'publisher' => 'الناشر', 'published_on' => 'سنة النشر', 'edition_number' => 'رقم الطبعة', 'edition_and_year' => 'رقم الطبعة + السنة', 'year' => 'السنة', 'date' => 'التاريخ', 'teacher' => 'المدرس', 'status' => 'الحالة',
        'resource' => 'المرجع', 'topic_name' => 'اسم الموضوع', 'topics' => 'المواضيع', 'pages_short' => 'صفحة',
        'general_lessons' => 'دروس عامة للمادة',
        'standalone_books' => 'الكتب المستقلة', 'pdf_file' => 'ملف PDF للكتاب (حتى 10 ميغابايت)', 'pdf_uploaded' => 'تم رفع PDF', 'no_pdf' => 'لا يوجد PDF', 'no_downloadable_books' => 'لا توجد كتب متاحة للتحميل.',
    ],
    'options' => ['no_curriculum' => 'بدون منهاج', 'all_grades' => 'بدون صف محدد'],
    'status' => ['untaught' => 'لم يُدرّس', 'partial' => 'منجز جزئياً', 'taught' => 'تم تدريسه'],
    'progress' => ['title' => 'تقدم المنهاج', 'completed' => 'مكتمل بنسبة :percent٪', 'empty' => 'لا يوجد منهاج معيّن.', 'group_details' => 'منهاج مجموعة :group'],
    'table' => ['curricula' => 'المناهج', 'latest' => 'آخر 5 دروس تم تدريسها', 'empty' => 'لا توجد مناهج لهذه الدورة.', 'no_lessons' => 'لا توجد دروس بعد.'],
    'form' => ['curriculum_title' => 'بيانات المنهاج', 'subject_title' => 'إضافة مادة', 'lesson_title' => 'بيانات الدرس', 'progress_title' => 'تسجيل تقدم الدرس', 'custom_title' => 'إضافة درس مخصص'],
    'messages' => ['curriculum_saved' => 'تم حفظ المنهاج.', 'subject_added' => 'تمت إضافة المادة.', 'lesson_saved' => 'تم حفظ الدرس.', 'progress_saved' => 'تم حفظ تقدم الدرس.', 'custom_saved' => 'تمت إضافة الدرس المخصص.', 'subject_saved' => 'تم حفظ المادة.', 'resource_saved' => 'تم حفظ المرجع.'],
    'errors' => ['course_mismatch' => 'المنهاج المحدد تابع لدورة أخرى.', 'no_group' => 'لا توجد مجموعة متاحة لها منهاج معيّن.', 'subject_used' => 'هذه المادة مستخدمة في منهاج ولا يمكن حذفها.', 'curriculum_used' => 'هذا المنهاج معيّن لمجموعة ولا يمكن حذفه.', 'resource_required' => 'اختر مرجعاً واحداً على الأقل لهذه المادة.', 'lesson_resource_required' => 'اختر المرجع الذي يتبع له الدرس.'],
];
