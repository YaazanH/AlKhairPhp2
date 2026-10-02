<?php

return [
    'navigation' => 'Learning progression',
    'eyebrow' => 'Student journey',
    'title' => 'Learning progression',
    'subtitle' => 'Choose which Quran tests your organisation uses and which tests must be passed before the next stage. Existing test screens and recording steps stay unchanged.',
    'quran_profile' => 'Quran progression profile',
    'first_setup' => 'Set this progression before recording memorisation or Quran tests. It becomes read-only after student progression begins, protecting existing records from changing meaning.',
    'locked_title' => 'This progression is now locked.',
    'locked_copy' => 'Memorisation or Quran test records already exist. The rules remain visible, but cannot be changed because doing so could reinterpret student progress.',
    'partial_copy' => 'Four-part test for a memorised Juz.',
    'final_copy' => 'Final test for the complete Juz.',
    'awqaf_copy' => 'Official Awqaf test after the organisation stages.',
    'require_partial' => 'Require a passed partial test before the final test',
    'require_final' => 'Require a passed final test before the Awqaf test',
    'save' => 'Save progression',
    'saved' => 'Learning progression saved.',
    'tests' => [
        'partial' => 'Partial test',
        'final' => 'Final test',
        'awqaf' => 'Awqaf test',
    ],
    'errors' => [
        'locked' => 'The progression cannot be changed after memorisation or Quran test records exist.',
        'partial_requirement_invalid' => 'The partial prerequisite requires both partial and final tests to be enabled.',
        'final_requirement_invalid' => 'The final prerequisite requires both final and Awqaf tests to be enabled.',
        'one_test_required' => 'Enable at least one Quran test stage.',
        'test_disabled' => ':test is not used in this organisation progression.',
    ],
];
