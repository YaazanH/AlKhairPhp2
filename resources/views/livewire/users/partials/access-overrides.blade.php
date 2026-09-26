            <section class="admin-section-card" data-user-access-overrides-box>
                <details
                    class="admin-collapsible"
                    data-user-direct-permissions
                    @if ($errors->has('direct_permissions') || $errors->has('direct_permissions.*')) open @endif
                >
                    <summary class="admin-collapsible__summary">
                        <span>{{ __('access.users.fields.permissions') }}</span>
                        <span class="admin-collapsible__count">{{ count($direct_permissions) }}/{{ $permissionGroups->flatten(1)->count() }}</span>
                    </summary>
                    <div>
                        <div class="space-y-4">
                        @foreach ($permissionGroups as $group => $permissions)
                            @php
                                $selectedPermissionCount = $permissions->pluck('name')->intersect($direct_permissions)->count();
                            @endphp
                            <details class="admin-collapsible">
                                <summary class="admin-collapsible__summary">
                                    <span>{{ $group }}</span>
                                    <span class="admin-collapsible__count">{{ $selectedPermissionCount }}/{{ $permissions->count() }}</span>
                                </summary>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    @foreach ($permissions as $permission)
                                        <label class="flex items-start gap-3 text-sm text-neutral-200">
                                            <input wire:model.live="direct_permissions" type="checkbox" value="{{ $permission->name }}" class="mt-0.5 rounded">
                                            <span>{{ $this->permissionLabel($permission->name) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                        </div>
                    </div>
                </details>
                <details
                    class="admin-collapsible"
                    data-user-scope-overrides
                    @if ($errors->has('scope_groups') || $errors->has('scope_students') || $errors->has('scope_teachers') || $errors->has('scope_parents')) open @endif
                >
                    <summary class="admin-collapsible__summary">
                        <span>{{ __('access.users.sections.scope') }}</span>
                        <span class="admin-collapsible__count">
                            {{ count($scope_groups) + count($scope_students) + count($scope_teachers) + count($scope_parents) }}/{{ $availableScopeGroups->count() + $availableScopeStudents->count() + $availableScopeTeachers->count() + $availableScopeParents->count() }}
                        </span>
                    </summary>
                    <div>
                        <div class="space-y-4">
                        <details class="admin-collapsible">
                            <summary class="admin-collapsible__summary">
                                <span>{{ __('access.users.scopes.groups') }}</span>
                                <span class="admin-collapsible__count">{{ count($scope_groups) }}/{{ $availableScopeGroups->count() }}</span>
                            </summary>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @forelse ($availableScopeGroups as $scopeGroup)
                                    <label class="flex items-start gap-3 text-sm text-neutral-200">
                                        <input wire:model="scope_groups" type="checkbox" value="{{ $scopeGroup->id }}" class="mt-0.5 rounded">
                                        <span>{{ $scopeGroup->name }}{{ $scopeGroup->course ? ' | '.$scopeGroup->course->name : '' }}</span>
                                    </label>
                                @empty
                                    <div class="text-sm text-neutral-400">{{ __('access.users.scopes.empty') }}</div>
                                @endforelse
                            </div>
                        </details>

                        <details class="admin-collapsible">
                            <summary class="admin-collapsible__summary">
                                <span>{{ __('access.users.scopes.students') }}</span>
                                <span class="admin-collapsible__count">{{ count($scope_students) }}/{{ $availableScopeStudents->count() }}</span>
                            </summary>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @forelse ($availableScopeStudents as $scopeStudent)
                                    <label class="flex items-start gap-3 text-sm text-neutral-200">
                                        <input wire:model="scope_students" type="checkbox" value="{{ $scopeStudent->id }}" class="mt-0.5 rounded">
                                        <span>{{ $scopeStudent->first_name }} {{ $scopeStudent->last_name }}{{ $scopeStudent->parentProfile?->father_name ? ' | '.$scopeStudent->parentProfile->father_name : '' }}</span>
                                    </label>
                                @empty
                                    <div class="text-sm text-neutral-400">{{ __('access.users.scopes.empty') }}</div>
                                @endforelse
                            </div>
                        </details>

                        <details class="admin-collapsible">
                            <summary class="admin-collapsible__summary">
                                <span>{{ __('access.users.scopes.teachers') }}</span>
                                <span class="admin-collapsible__count">{{ count($scope_teachers) }}/{{ $availableScopeTeachers->count() }}</span>
                            </summary>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @forelse ($availableScopeTeachers as $scopeTeacher)
                                    <label class="flex items-start gap-3 text-sm text-neutral-200">
                                        <input wire:model="scope_teachers" type="checkbox" value="{{ $scopeTeacher->id }}" class="mt-0.5 rounded">
                                        <span>{{ $scopeTeacher->first_name }} {{ $scopeTeacher->last_name }}</span>
                                    </label>
                                @empty
                                    <div class="text-sm text-neutral-400">{{ __('access.users.scopes.empty') }}</div>
                                @endforelse
                            </div>
                        </details>

                        <details class="admin-collapsible">
                            <summary class="admin-collapsible__summary">
                                <span>{{ __('access.users.scopes.parents') }}</span>
                                <span class="admin-collapsible__count">{{ count($scope_parents) }}/{{ $availableScopeParents->count() }}</span>
                            </summary>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @forelse ($availableScopeParents as $scopeParent)
                                    <label class="flex items-start gap-3 text-sm text-neutral-200">
                                        <input wire:model="scope_parents" type="checkbox" value="{{ $scopeParent->id }}" class="mt-0.5 rounded">
                                        <span>{{ $scopeParent->father_name }} ({{ $scopeParent->students_count }})</span>
                                    </label>
                                @empty
                                    <div class="text-sm text-neutral-400">{{ __('access.users.scopes.empty') }}</div>
                                @endforelse
                            </div>
                        </details>
                        </div>
                    </div>
                </details>
            </section>
