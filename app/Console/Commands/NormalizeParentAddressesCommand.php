<?php

namespace App\Console\Commands;

use App\Models\ParentProfile;
use App\Support\AddressFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class NormalizeParentAddressesCommand extends Command
{
    protected $signature = 'parents:normalize-addresses {--apply : Save changes after writing a private backup}';

    protected $description = 'Preview or apply conservative city - area - details formatting to parent addresses.';

    public function handle(): int
    {
        $changes = [];
        $review = [];
        foreach (ParentProfile::query()->whereNotNull('address')->orderBy('id')->get(['id', 'address', 'updated_at']) as $parent) {
            $old = $parent->getRawOriginal('address');
            if (trim($old) === '') {
                continue;
            }
            $new = AddressFormatter::normalize($old);
            if ($new !== $old) {
                $changes[] = ['id' => $parent->id, 'before' => $old, 'after' => $new];
            }
            if (! AddressFormatter::hasRecognizedArea($new)) {
                $review[] = ['id' => $parent->id, 'address' => $new];
            }
        }

        $this->table(['ID', 'Before', 'After'], array_slice($changes, 0, 20));
        $this->info(count($changes).' addresses to format; '.count($review).' addresses need location review.');
        if (! $this->option('apply') || $changes === []) {
            $this->line('No addresses changed. Use --apply to save with a backup.');

            return self::SUCCESS;
        }

        $directory = storage_path('app/private/address-normalization');
        File::ensureDirectoryExists($directory, 0700);
        $backup = $directory.'/'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
        $json = json_encode(['created_at' => now()->toIso8601String(), 'changes' => $changes, 'needs_review' => $review], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($backup, $json, LOCK_EX) === false || ! chmod($backup, 0600)) {
            $this->error('Could not create a private backup. No addresses changed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($changes): void {
            foreach ($changes as $change) {
                $parent = ParentProfile::query()->lockForUpdate()->findOrFail($change['id']);
                if ($parent->getRawOriginal('address') !== $change['before']) {
                    throw new \RuntimeException('An address changed during the preview. Please run the command again.');
                }
                $parent->address = $change['after'];
                $parent->save();
            }
        });
        $this->info(count($changes).' addresses updated. Backup and review list: '.$backup);

        return self::SUCCESS;
    }
}
