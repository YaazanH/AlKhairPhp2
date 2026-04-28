@php
    $currentLocale = app()->getLocale();
    $currentLocaleConfig = config('app.supported_locales.'.$currentLocale, []);
    $textDirection = $currentLocaleConfig['direction'] ?? 'ltr';
    $isRtl = $textDirection === 'rtl';
    $sidebarBorderClass = $isRtl ? 'border-l' : 'border-r';
    $sidebarToggleInset = $isRtl ? 'right' : 'left';
    $desktopDropdownAlign = $isRtl ? 'end' : 'start';
    $mobileIdentitySpacingClass = $isRtl ? 'mr-3' : 'ml-3';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $currentLocale) }}" dir="{{ $textDirection }}">
    <head>
        @include('partials.head')
    </head>
    <body class="app-body">
        @php
            $roleLabel = auth()->user()->getRoleNames()
                ->map(fn (string $role) => __('ui.roles.'.$role))
                ->implode(' · ');
        @endphp

        <div class="app-backdrop">
            <div class="app-backdrop__orb app-backdrop__orb--gold"></div>
            <div class="app-backdrop__orb app-backdrop__orb--emerald"></div>
            <div class="app-backdrop__orb app-backdrop__orb--plum"></div>
        </div>

        <div class="app-shell flex min-h-screen">
            <flux:sidebar sticky stashable class="app-sidebar-shell {{ $sidebarBorderClass }}">
                <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

                <div class="app-brand-card">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3" wire:navigate>
                        <x-app-logo />
                    </a>

                    <div class="mt-5 flex items-center justify-between gap-3">
                        <span class="badge-soft">{{ $roleLabel ?: __('ui.common.workspace') }}</span>
                        <span class="text-xs uppercase tracking-[0.24em] text-neutral-400">{{ now()->locale($currentLocale)->translatedFormat('M Y') }}</span>
                    </div>

                    <p class="mt-4 text-sm leading-6 text-neutral-300">
                        {{ __('ui.app.workspace_tagline') }}
                    </p>
                </div>

                <flux:navlist variant="outline" class="mt-6">
                    <flux:navlist.group :heading="__('ui.nav.platform')" class="grid">
                        <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>{{ __('ui.nav.dashboard') }}</flux:navlist.item>
                        @can('reports.view')
                            <flux:navlist.item icon="chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>{{ __('ui.nav.reports') }}</flux:navlist.item>
                        @endcan
                    </flux:navlist.group>

                    @if (auth()->user()->can('users.view') || auth()->user()->can('parents.view') || auth()->user()->can('teachers.view') || auth()->user()->can('students.view'))
                        <flux:navlist.group :heading="__('ui.nav.people')" class="grid">
                            @can('users.view')
                                <flux:navlist.item icon="users" :href="route('users.index')" :current="request()->routeIs('users.*')" wire:navigate>{{ __('ui.nav.users') }}</flux:navlist.item>
                            @endcan
                            @can('parents.view')
                                <flux:navlist.item icon="user-group" :href="route('parents.index')" :current="request()->routeIs('parents.*')" wire:navigate>{{ __('ui.nav.parents') }}</flux:navlist.item>
                            @endcan
                            @can('teachers.view')
                                <flux:navlist.item icon="academic-cap" :href="route('teachers.index')" :current="request()->routeIs('teachers.*')" wire:navigate>{{ __('ui.nav.teachers') }}</flux:navlist.item>
                            @endcan
                            @can('students.view')
                                <flux:navlist.item icon="identification" :href="route('students.index')" :current="request()->routeIs('students.index', 'students.progress', 'students.files')" wire:navigate>{{ __('ui.nav.students') }}</flux:navlist.item>
                            @endcan
                            @can('students.update')
                                <flux:navlist.item icon="photo" :href="route('students.bulk-photos')" :current="request()->routeIs('students.bulk-photos')" wire:navigate>{{ __('ui.nav.bulk_student_photos') }}</flux:navlist.item>
                            @endcan
                        </flux:navlist.group>
                    @endif

                    @if (auth()->user()->can('courses.view') || auth()->user()->can('groups.view') || auth()->user()->can('enrollments.view'))
                        <flux:navlist.group :heading="__('ui.nav.academics')" class="grid">
                            @can('courses.view')
                                <flux:navlist.item icon="book-open" :href="route('courses.index')" :current="request()->routeIs('courses.*')" wire:navigate>{{ __('ui.nav.courses') }}</flux:navlist.item>
                            @endcan
                            @can('groups.view')
                                <flux:navlist.item icon="rectangle-group" :href="route('groups.index')" :current="request()->routeIs('groups.*')" wire:navigate>{{ __('ui.nav.groups') }}</flux:navlist.item>
                            @endcan
                            @can('enrollments.view')
                                <flux:navlist.item icon="clipboard-document-list" :href="route('enrollments.index')" :current="request()->routeIs('enrollments.*')" wire:navigate>{{ __('ui.nav.enrollments') }}</flux:navlist.item>
                            @endcan
                        </flux:navlist.group>
                    @endif

                    @if (auth()->user()->can('attendance.student.view') || auth()->user()->can('attendance.teacher.view'))
                        <flux:navlist.group :heading="__('ui.nav.tracking_attendance')" class="grid">
                            @can('attendance.student.view')
                                <flux:navlist.item icon="calendar-days" :href="route('student-attendance.index')" :current="request()->routeIs('student-attendance.*', 'groups.attendance')" wire:navigate>{{ __('ui.nav.student_attendance') }}</flux:navlist.item>
                            @endcan
                            @can('attendance.teacher.view')
                                <flux:navlist.item icon="clipboard-document-check" :href="route('teachers.attendance')" :current="request()->routeIs('teachers.attendance')" wire:navigate>{{ __('ui.nav.teacher_attendance') }}</flux:navlist.item>
                            @endcan
                        </flux:navlist.group>
                    @endif

                    @if (auth()->user()->can('memorization.view') || auth()->user()->can('memorization.record') || auth()->user()->can('quran-partial-tests.view') || auth()->user()->can('quran-final-tests.view') || auth()->user()->can('quran-tests.view'))
                        <flux:navlist.group :heading="__('ui.nav.tracking_quran')" class="grid">
                            @can('memorization.view')
                                <flux:navlist.item icon="book-open-text" :href="route('memorization.index')" :current="request()->routeIs('memorization.index', 'enrollments.memorization')" wire:navigate>{{ __('ui.nav.memorization') }}</flux:navlist.item>
                            @endcan
                            @can('memorization.record')
                                <flux:navlist.item icon="pencil-square" :href="route('memorization.quick-entry')" :current="request()->routeIs('memorization.quick-entry')" wire:navigate>{{ __('ui.nav.enter_memorize') }}</flux:navlist.item>
                            @endcan
                            @can('quran-partial-tests.view')
                                <flux:navlist.item icon="squares-2x2" :href="route('quran-partial-tests.index')" :current="request()->routeIs('quran-partial-tests.*')" wire:navigate>{{ __('ui.nav.quran_partial_tests') }}</flux:navlist.item>
                            @endcan
                            @can('quran-final-tests.view')
                                <flux:navlist.item icon="check-badge" :href="route('quran-final-tests.index')" :current="request()->routeIs('quran-final-tests.*')" wire:navigate>{{ __('ui.nav.quran_final_tests') }}</flux:navlist.item>
                            @endcan
                            @can('quran-tests.view')
                                <flux:navlist.item icon="document-check" :href="route('quran-tests.index')" :current="request()->routeIs('quran-tests.*', 'enrollments.quran-tests')" wire:navigate>{{ __('ui.nav.quran_tests') }}</flux:navlist.item>
                            @endcan
                        </flux:navlist.group>
                    @endif

                    @if (auth()->user()->can('assessments.view') || auth()->user()->can('points.view'))
                        <flux:navlist.group :heading="__('ui.nav.tracking_performance')" class="grid">
                            @can('assessments.view')
                                <flux:navlist.item icon="chart-pie" :href="route('assessments.index')" :current="request()->routeIs('assessments.*')" wire:navigate>{{ __('ui.nav.assessments') }}</flux:navlist.item>
                            @endcan
                            @can('points.view')
                                <flux:navlist.item icon="trophy" :href="route('points.index')" :current="request()->routeIs('points.*', 'enrollments.points')" wire:navigate>{{ __('ui.nav.point_ledger') }}</flux:navlist.item>
                            @endcan
                        </flux:navlist.group>
                    @endif

                    @if (auth()->user()->can('student-notes.view') || auth()->user()->can('barcode-scans.import'))
                        <flux:navlist.group :heading="__('ui.nav.tracking_tools')" class="grid">
                            @can('student-notes.view')
                                <flux:navlist.item icon="pencil-square" :href="route('student-notes.index')" :current="request()->routeIs('student-notes.*')" wire:navigate>{{ __('ui.nav.student_notes') }}</flux:navlist.item>
                            @endcan
                            @can('barcode-scans.import')
                                <flux:navlist.item icon="qr-code" :href="route('barcode-actions.import')" :current="request()->routeIs('barcode-actions.import')" wire:navigate>{{ __('ui.nav.scanner_import') }}</flux:navlist.item>
                            @endcan
                        </flux:navlist.group>
                    @endif

                    @if (auth()->user()->can('activities.view') || auth()->user()->can('activities.responses.view') || auth()->user()->can('invoices.view'))
                        <flux:navlist.group :heading="__('ui.nav.finance')" class="grid">
                            @can('activities.view')
                                <flux:navlist.item icon="sparkles" :href="route('activities.index')" :current="request()->routeIs('activities.index', 'activities.finance')" wire:navigate>{{ __('ui.nav.activities') }}</flux:navlist.item>
                            @endcan
                            @can('activities.responses.view')
                                <flux:navlist.item icon="heart" :href="route('activities.family')" :current="request()->routeIs('activities.family')" wire:navigate>{{ __('ui.nav.family_activities') }}</flux:navlist.item>
                            @endcan
                            @can('invoices.view')
                                <flux:navlist.item icon="document-currency-dollar" :href="route('invoices.index')" :current="request()->routeIs('invoices.*')" wire:navigate>{{ __('ui.nav.invoices') }}</flux:navlist.item>
                            @endcan
                        </flux:navlist.group>
                    @endif

                    @if (auth()->user()->can('settings.manage') || auth()->user()->can('website.manage'))
                        <flux:navlist.group :heading="__('ui.nav.configuration')" class="grid">
                            @can('settings.manage')
                                <flux:navlist.item icon="cog-6-tooth" :href="route('settings.organization')" :current="request()->routeIs('settings.organization', 'settings.tracking', 'settings.course-completion', 'settings.points', 'settings.finance', 'settings.access-control')" wire:navigate>{{ __('ui.nav.dashboard_settings') }}</flux:navlist.item>
                            @endcan
                            @can('website.manage')
                                <flux:navlist.item icon="globe-alt" :href="route('settings.website')" :current="request()->routeIs('settings.website', 'settings.website.pages', 'settings.website.navigation')" wire:navigate>{{ __('ui.nav.public_website_settings') }}</flux:navlist.item>
                            @endcan
                        </flux:navlist.group>
                    @endif

                    @if (auth()->user()->can('id-cards.view') || auth()->user()->can('id-cards.print') || auth()->user()->can('barcode-actions.view'))
                        <flux:navlist.group :heading="__('ui.nav.identity_tools')" class="grid">
                            @can('id-cards.view')
                                <flux:navlist.item icon="document-duplicate" :href="route('print-templates.templates.index')" :current="request()->routeIs('print-templates.*')" wire:navigate>{{ __('ui.nav.print_templates') }}</flux:navlist.item>
                            @endcan
                            @can('id-cards.view')
                                <flux:navlist.item icon="identification" :href="route('id-cards.templates.index')" :current="request()->routeIs('id-cards.templates.*')" wire:navigate>{{ __('ui.nav.id_card_templates') }}</flux:navlist.item>
                            @endcan
                            @can('id-cards.print')
                                <flux:navlist.item icon="printer" :href="route('id-cards.print.create')" :current="request()->routeIs('id-cards.print.*')" wire:navigate>{{ __('ui.nav.id_card_print') }}</flux:navlist.item>
                            @endcan
                            @can('barcode-actions.view')
                                <flux:navlist.item icon="qr-code" :href="route('barcode-actions.index')" :current="request()->routeIs('barcode-actions.index', 'barcode-actions.print.*')" wire:navigate>{{ __('ui.nav.action_barcodes') }}</flux:navlist.item>
                            @endcan
                        </flux:navlist.group>
                    @endif

                </flux:navlist>

                <flux:spacer />

                <div class="app-sidebar-footer">
                    <div class="eyebrow">{{ __('ui.common.current_account') }}</div>
                    <div class="mt-3 flex items-center gap-3">
                        <x-user-avatar :user="auth()->user()" size="sm" />
                        <div class="min-w-0">
                            <div class="truncate text-base font-semibold text-white">{{ auth()->user()->name }}</div>
                            <p class="truncate text-sm leading-6 text-neutral-300">
                                {{ auth()->user()->email ?: (auth()->user()->username ?: 'No account identifier recorded.') }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-5">
                        <x-locale-switcher />
                    </div>

                    <div class="mt-4">
                        <a href="{{ route('home') }}" target="_blank" class="pill-link pill-link--compact w-full justify-center">
                            {{ __('ui.common.visit_site') }}
                        </a>
                    </div>
                </div>

                <div class="mt-4">
                    <flux:dropdown position="bottom" align="{{ $desktopDropdownAlign }}">
                        <flux:profile
                            :name="auth()->user()->name"
                            :initials="auth()->user()->initials()"
                            icon-trailing="chevrons-up-down"
                        />

                        <flux:menu class="w-[220px]">
                            <flux:menu.radio.group>
                                <div class="p-0 text-sm font-normal">
                                    <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                        <x-user-avatar :user="auth()->user()" size="sm" />

                                        <div class="grid flex-1 text-left text-sm leading-tight">
                                            <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                            <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                        </div>
                                    </div>
                                </div>
                            </flux:menu.radio.group>

                            <flux:menu.separator />

                            <flux:menu.radio.group>
                                <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>{{ __('ui.common.settings') }}</flux:menu.item>
                            </flux:menu.radio.group>

                            <flux:menu.separator />

                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                                    {{ __('Log Out') }}
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </flux:sidebar>

            <div class="flex min-h-screen min-w-0 flex-1 flex-col">
                <flux:header class="app-mobile-header lg:hidden">
                    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="{{ $sidebarToggleInset }}" />

                    <div class="{{ $mobileIdentitySpacingClass }} min-w-0">
                        <div class="text-[0.68rem] font-semibold uppercase tracking-[0.28em] text-neutral-500">{{ __('ui.app.name') }}</div>
                        <div class="truncate text-sm text-neutral-200">{{ $roleLabel ?: __('ui.common.workspace') }}</div>
                    </div>

                    <flux:spacer />

                    <flux:dropdown position="top" align="end">
                        <flux:profile
                            :initials="auth()->user()->initials()"
                            icon-trailing="chevron-down"
                        />

                        <flux:menu>
                            <flux:menu.radio.group>
                                <div class="p-0 text-sm font-normal">
                                    <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                        <x-user-avatar :user="auth()->user()" size="sm" />

                                        <div class="grid flex-1 text-left text-sm leading-tight">
                                            <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                            <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                        </div>
                                    </div>
                                </div>
                            </flux:menu.radio.group>

                            <flux:menu.separator />

                            <flux:menu.radio.group>
                                <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>{{ __('ui.common.settings') }}</flux:menu.item>
                            </flux:menu.radio.group>

                            <flux:menu.separator />

                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                                    {{ __('Log Out') }}
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                </flux:header>

                <main class="app-main">
                    <div class="app-main-inner">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        <div
            id="admin-confirm-modal"
            class="admin-modal"
            data-default-confirm-label="{{ __('crud.common.confirm_delete.confirm') }}"
            data-default-message="{{ __('crud.common.confirm_delete.message') }}"
            data-default-title="{{ __('crud.common.confirm_delete.title') }}"
            hidden
            aria-hidden="true"
        >
            <div class="admin-modal__backdrop" data-admin-confirm-close></div>
            <div class="admin-modal__viewport">
                <div class="admin-modal__dialog admin-modal__dialog--2xl" role="dialog" aria-modal="true" aria-labelledby="admin-confirm-title" aria-describedby="admin-confirm-message">
                    <div class="admin-modal__header">
                        <div>
                            <h2 id="admin-confirm-title" class="admin-modal__title">{{ __('crud.common.confirm_delete.title') }}</h2>
                            <p id="admin-confirm-message" class="admin-modal__description">{{ __('crud.common.confirm_delete.message') }}</p>
                        </div>

                        <button type="button" data-admin-confirm-close class="admin-modal__close" aria-label="{{ __('crud.common.actions.close') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="admin-modal__body">
                        <div class="admin-action-cluster admin-action-cluster--end">
                            <button id="admin-confirm-cancel" type="button" class="pill-link">
                                {{ __('crud.common.actions.cancel') }}
                            </button>
                            <button id="admin-confirm-accept" type="button" class="pill-link pill-link--danger">
                                {{ __('crud.common.confirm_delete.confirm') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
