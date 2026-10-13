<section class="surface-table archive-settings" data-course-archive-completion-settings>
    <div class="archive-settings__header">
        <div>
            <div class="admin-grid-meta__title">{{ __('course_archive_overview.completion_settings.title') }}</div>
        </div>
    </div>
    <div class="archive-settings__body">
        @php
            $allGradeIds = $completionSettings['grade_names']
                ->keys()
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values();
        @endphp
        <div class="archive-settings__group" data-course-archive-final-rules>
            @foreach ($completionSettings['final_rule_rows'] as $rule)
                @php
                    $ruleGradeIds = collect($rule['grade_ids'])->map(fn ($id) => (int) $id)->sort()->values();
                    $gradeNames = $ruleGradeIds->map(fn ($id) => $completionSettings['grade_names']->get($id))->filter()->implode('، ');
                    $gradeScope = $ruleGradeIds->isEmpty() || $ruleGradeIds->all() === $allGradeIds->all()
                        ? __('settings.course_completion.labels.all_grades')
                        : $gradeNames;
                @endphp
                <article class="archive-settings__compact-rule">
                    <div class="archive-settings__condition" data-archive-settings-condition>
                        <span>{{ __('settings.course_completion.fields.required_passed_final_tests') }} <strong>{{ number_format($rule['required_passed_final_tests']) }}</strong></span>
                        <span class="archive-settings__operator">{{ __('settings.course_completion.options.'.$rule['final_rule_operator']) }}</span>
                        <span>{{ __('settings.course_completion.fields.required_memorized_pages') }} <strong>{{ number_format($rule['required_memorized_pages']) }}</strong></span>
                    </div>
                    <span class="archive-settings__scope" title="{{ $gradeNames ?: $gradeScope }}" data-archive-settings-grade-scope>{{ $gradeScope }}</span>
                </article>
            @endforeach
        </div>
        <div class="archive-settings__group" data-course-archive-assessment-rules>
            @forelse ($completionSettings['assessment_type_requirements'] as $typeId => $required)
                @php
                    $assessmentGradeIds = collect($completionSettings['assessment_rule_grade_ids'][$typeId] ?? [])->map(fn ($id) => (int) $id)->sort()->values();
                    $assessmentGradeNames = $assessmentGradeIds->map(fn ($id) => $completionSettings['grade_names']->get($id))->filter()->implode('، ');
                    $assessmentGradeScope = $assessmentGradeIds->isEmpty() || $assessmentGradeIds->all() === $allGradeIds->all()
                        ? __('settings.course_completion.labels.all_grades')
                        : $assessmentGradeNames;
                @endphp
                <article class="archive-settings__compact-rule">
                    <div class="archive-settings__condition" data-archive-settings-condition>
                        <span>{{ $completionSettings['assessment_type_names']->get($typeId) ?: __('settings.course_completion.labels.unknown_assessment_type') }}</span>
                        <span>{{ __('settings.course_completion.table.required_passed_results') }} <strong>{{ number_format($required) }}</strong></span>
                    </div>
                    <span class="archive-settings__scope" title="{{ $assessmentGradeNames ?: $assessmentGradeScope }}" data-archive-settings-grade-scope>{{ $assessmentGradeScope }}</span>
                </article>
            @empty
                <div class="admin-empty-state">{{ __('settings.course_completion.labels.no_assessment_types') }}</div>
            @endforelse
        </div>
        <div class="archive-settings__summary" data-course-archive-settings-summary>
            <article><span class="kpi-label">{{ __('settings.course_completion.fields.retain_percentage') }}</span><strong>{{ number_format($completionSettings['retain_percentage']) }}%</strong></article>
            <article><span class="kpi-label">{{ __('settings.course_completion.fields.minimum_points') }}</span><strong>{{ number_format($completionSettings['minimum_points']) }}</strong></article>
        </div>
    </div>
</section>
