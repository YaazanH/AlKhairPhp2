@props(['definition', 'modules' => []])
@php
    $catalog = app(\App\Services\ReportDesignerCatalog::class);
    $source = data_get($definition, 'data_source', '');
    $fields = $catalog->fields($source);
    $sourceLabel = data_get($catalog->librarySources(), $source.'.label', $source);
    $relationships = app(\App\Services\ReportRelationshipCatalog::class)->available($source);
@endphp
<dl class="platform-definition-summary">
    <div><dt>{{ __('platform-content.source') }}</dt><dd>{{ $sourceLabel }}</dd></div>
    <div><dt>{{ __('platform-content.compatibility') }}</dt><dd>@foreach($modules as $module)<span class="platform-request-status">{{ app(\App\Support\PlatformFeaturePresentation::class)->name($module) }}</span> @endforeach</dd></div>
    <div><dt>{{ __('platform-content.columns') }}</dt><dd><ul>@forelse(data_get($definition, 'selected_fields', []) as $field)<li>{{ data_get($fields, $field.'.label', $field) }}</li>@empty<li>{{ __('platform-content.no_fields') }}</li>@endforelse</ul></dd></div>
    <div><dt>{{ __('platform-content.calculations') }}</dt><dd><ul>@forelse(data_get($definition, 'calculations', []) as $calculation)<li>{{ $catalog->calculationLabel($source, $calculation['operation'], $calculation['field'] ?? null) }}</li>@empty<li>{{ __('platform-content.no_calculations') }}</li>@endforelse</ul></dd></div>
    <div><dt>{{ __('platform-content.group_by') }}</dt><dd>{{ data_get($fields, data_get($definition, 'group_by').'.label', data_get($definition, 'group_by')) ?: __('platform-content.no_group') }}</dd></div>
    <div><dt>{{ __('platform-content.presentation') }}</dt><dd>{{ $catalog->presentationTypes()[data_get($definition, 'presentation.type')] ?? data_get($definition, 'presentation.type') }} · {{ $catalog->tableDensities()[data_get($definition, 'presentation.density')] ?? data_get($definition, 'presentation.density') }}</dd></div>
    <div><dt>{{ __('platform-content.sort') }}</dt><dd>{{ data_get($fields, data_get($definition, 'sort_field').'.label', data_get($definition, 'sort_field')) ?: __('platform-content.sort_default') }} · {{ __('platform-content.'.data_get($definition, 'sort_direction', 'asc')) }}</dd></div>
    <div><dt>{{ __('platform-content.relationships') }}</dt><dd><ul>@foreach(data_get($definition, 'relationship_modes', []) as $relationship => $mode)<li>{{ data_get($relationships, $relationship.'.label', $relationship) }} · {{ __('platform-content.'.($mode === 'detailed' ? 'detailed_rows' : 'summary_rows')) }}</li>@endforeach</ul></dd></div>
</dl>
<details class="mt-6 rounded-2xl border p-4"><summary class="cursor-pointer text-sm font-semibold">{{ __('platform-content.definition_reference') }}</summary><pre class="platform-definition-reference mt-4" dir="ltr">{{ json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>
