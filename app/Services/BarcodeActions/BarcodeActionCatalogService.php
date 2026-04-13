<?php

namespace App\Services\BarcodeActions;

use App\Models\AttendanceStatus;
use App\Models\BarcodeAction;
use App\Models\PointType;
use App\Services\IdCards\Code39SvgRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class BarcodeActionCatalogService
{
    public function __construct(
        protected Code39SvgRenderer $barcodeRenderer,
    ) {
    }

    public function actionsQuery(): Builder
    {
        $this->syncReferenceActions();

        return BarcodeAction::query()
            ->with(['attendanceStatus', 'pointType'])
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function findAction(string $barcodeValue): ?BarcodeAction
    {
        $this->syncReferenceActions();

        return BarcodeAction::query()
            ->with(['attendanceStatus', 'pointType'])
            ->active()
            ->where('code', $this->normalizeBarcodeValue($barcodeValue))
            ->first();
    }

    public function renderBarcode(string $value, array $options = []): ?string
    {
        return $this->barcodeRenderer->render($value, $options);
    }

    public function syncReferenceActions(): void
    {
        $syncedPointActionIds = [];

        AttendanceStatus::query()
            ->whereIn('scope', ['student', 'both'])
            ->orderBy('id')
            ->get()
            ->each(function (AttendanceStatus $status): void {
                $action = BarcodeAction::query()
                    ->where('type', 'attendance')
                    ->where('attendance_status_id', $status->id)
                    ->first() ?? BarcodeAction::query()->firstOrNew([
                        'code' => $this->attendanceActionCode($status),
                    ]);

                $action->fill([
                    'name' => $status->name,
                    'code' => $this->attendanceActionCode($status),
                    'type' => 'attendance',
                    'attendance_status_id' => $status->id,
                    'point_type_id' => null,
                    'points' => null,
                    'sort_order' => 100 + $status->id,
                    'notes' => __('barcodes.actions.auto_attendance_note'),
                ]);

                if (! $action->exists) {
                    $action->is_active = $status->is_active;
                } elseif (! $status->is_active) {
                    $action->is_active = false;
                }

                $action->save();
            });

        PointType::query()
            ->where('allow_manual_entry', true)
            ->orderBy('id')
            ->get()
            ->each(function (PointType $pointType) use (&$syncedPointActionIds): void {
                $points = (int) $pointType->default_points;
                $action = BarcodeAction::query()
                    ->where('type', 'points')
                    ->where('point_type_id', $pointType->id)
                    ->first() ?? BarcodeAction::query()->firstOrNew([
                        'code' => $this->pointActionCode($pointType),
                    ]);

                $action->fill([
                    'name' => __('barcodes.actions.generated_point_name', [
                        'type' => $pointType->name,
                        'points' => $points > 0 ? '+'.$points : (string) $points,
                    ]),
                    'code' => $this->pointActionCode($pointType),
                    'type' => 'points',
                    'attendance_status_id' => null,
                    'point_type_id' => $pointType->id,
                    'points' => $points,
                    'sort_order' => 500 + $pointType->id,
                    'notes' => __('barcodes.actions.auto_point_note'),
                ]);

                $action->is_active = $pointType->is_active
                    && $points !== 0
                    && ($pointType->allow_negative || $points > 0);

                $action->save();
                $syncedPointActionIds[] = $action->id;
            });

        $stalePointActions = BarcodeAction::query()->where('type', 'points');

        if ($syncedPointActionIds !== []) {
            $stalePointActions->whereNotIn('id', $syncedPointActionIds);
        }

        $stalePointActions->update([
            'is_active' => false,
            'notes' => __('barcodes.actions.stale_point_note'),
        ]);
    }

    public function attendanceActionCode(AttendanceStatus $status): string
    {
        return 'ACT-ATT-'.$this->normalizeCodeSegment($status->code);
    }

    public function pointActionCode(PointType $pointType): string
    {
        return 'ACT-PTS-'.$this->normalizeCodeSegment($pointType->code);
    }

    public function normalizeBarcodeValue(string $value): string
    {
        $value = Str::upper(trim($value));

        if (str_starts_with($value, '*') && str_ends_with($value, '*')) {
            $value = trim($value, '*');
        }

        return preg_replace('/\s+/u', ' ', $value) ?: '';
    }

    public function normalizeCodeSegment(string $value): string
    {
        $segment = Str::upper(trim($value));
        $segment = preg_replace('/[^A-Z0-9]+/', '-', $segment) ?: '';
        $segment = trim($segment, '-');

        return $segment !== '' ? $segment : 'X';
    }

    public function studentNumberFromBarcode(string $barcodeValue): ?string
    {
        $normalized = $this->normalizeBarcodeValue($barcodeValue);

        if (preg_match('/^STU-(\d+)$/', $normalized, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^\d+$/', $normalized)) {
            return $normalized;
        }

        return null;
    }
}
