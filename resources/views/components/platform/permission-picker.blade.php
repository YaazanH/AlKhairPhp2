@props(['groups', 'selected' => [], 'disabled' => false])
<div class="platform-permission-picker">
    @foreach($groups as $group => $permissions)
        <fieldset class="mt-5">
            <legend class="font-semibold">{{ __('platform-access.permission_groups')[$group] ?? Illuminate\Support\Str::headline($group) }}</legend>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($permissions as $permission)
                    <label class="platform-permission-option"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array($permission->id, $selected)) @disabled($disabled)><span><strong>{{ __('platform-access.permission_labels')[$permission->code] ?? $permission->name }}</strong><small dir="ltr">{{ $permission->code }}</small>@if($permission->code === 'manage.platform-roles')<small>{{ __('platform-access.owner_only') }}</small>@elseif($permission->code === 'manage.support')<small>{{ __('platform-access.legacy_support') }}</small>@endif</span></label>
                @endforeach
            </div>
        </fieldset>
    @endforeach
</div>
