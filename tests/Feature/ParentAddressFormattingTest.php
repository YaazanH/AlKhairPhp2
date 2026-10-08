<?php

namespace Tests\Feature;

use App\Models\ParentProfile;
use App\Support\AddressFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParentAddressFormattingTest extends TestCase
{
    use RefreshDatabase;

    public function test_address_formatting_preserves_details_and_does_not_guess_unknown_locations(): void
    {
        $cases = [
            'دمشق-مهاجرين-جادة تالتة' => 'دمشق - المهاجرين - جادة تالتة',
            'مهاجرين جادة ٤' => 'دمشق - المهاجرين - جادة ٤',
            'مهارجين جادة ٣ فوق جسر الشطة' => 'دمشق - المهاجرين - جادة ٣ فوق جسر الشطة',
            'دمشق المهاجرين جادة خامسة' => 'دمشق - المهاجرين - جادة خامسة',
            'الجسر الابيض قرب مسجد العفيف' => 'دمشق - الجسر الأبيض - قرب مسجد العفيف',
            'دمشق - ابو رمانة - شارع الروضة' => 'دمشق - أبو رمانة - شارع الروضة',
            'دمشق-مهاجرين(لاحقا النقلة على حرستا)' => 'دمشق - المهاجرين - (لاحقا النقلة على حرستا)',
            'مهاجرين بناء 12-14 طابق 2/3' => 'دمشق - المهاجرين - بناء 12-14 طابق 2/3',
            'عند صالة المرابط' => 'عند صالة المرابط',
            'الجادة الخامسة' => 'الجادة الخامسة',
            'حمص - المهاجرين - الشارع الأول' => 'حمص - المهاجرين - الشارع الأول',
            'ريف دمشق-قدسيا' => 'ريف دمشق - قدسيا',
            'تركيا - اسطنبول - باشاكشهير' => 'تركيا - اسطنبول - باشاكشهير',
            'المهاجرينالجديدة' => 'المهاجرينالجديدة',
            '   ' => null,
        ];
        foreach ($cases as $before => $after) {
            $this->assertSame($after, AddressFormatter::normalize($before));
            $this->assertSame($after, AddressFormatter::normalize($after), 'Formatting must be idempotent.');
        }
        $this->assertFalse(AddressFormatter::hasRecognizedArea('الجادة الخامسة'));
        $this->assertTrue(AddressFormatter::hasRecognizedArea('مهاجرين جادة ٤'));
    }

    public function test_new_and_edited_parent_addresses_are_normalized_when_saved(): void
    {
        $parent = ParentProfile::create(['father_name' => 'Address Test', 'address' => 'مهاجرين جادة ٤']);
        $this->assertSame('دمشق - المهاجرين - جادة ٤', $parent->fresh()->address);
        $parent->update(['address' => 'دمشق-ابو رمانة-شارع الروضة']);
        $this->assertSame('دمشق - أبو رمانة - شارع الروضة', $parent->fresh()->address);
    }

    public function test_cleanup_defaults_to_preview_and_backs_up_original_addresses_before_applying(): void
    {
        $parent = ParentProfile::create(['father_name' => 'Address Test']);
        DB::table('parents')->where('id', $parent->id)->update(['address' => 'دمشق-مهاجرين-جادة تالتة']);
        $unknown = ParentProfile::create(['father_name' => 'Unknown Address', 'address' => 'عند صالة المرابط']);
        $originalStorage = storage_path();
        $temporaryStorage = sys_get_temp_dir().'/address-test-'.Str::uuid();
        app()->useStoragePath($temporaryStorage);
        try {
            $this->artisan('parents:normalize-addresses')->assertSuccessful();
            $this->assertSame('دمشق-مهاجرين-جادة تالتة', $parent->fresh()->address);
            $this->assertDirectoryDoesNotExist($temporaryStorage.'/app/private/address-normalization');

            $this->artisan('parents:normalize-addresses', ['--apply' => true])->assertSuccessful();
            $this->assertSame('دمشق - المهاجرين - جادة تالتة', $parent->fresh()->address);
            $this->assertSame('عند صالة المرابط', $unknown->fresh()->address);
            $files = File::files($temporaryStorage.'/app/private/address-normalization');
            $this->assertCount(1, $files);
            $backup = json_decode(File::get($files[0]), true);
            $this->assertSame('دمشق-مهاجرين-جادة تالتة', $backup['changes'][0]['before']);
            $this->assertSame($unknown->id, $backup['needs_review'][0]['id']);

            $this->artisan('parents:normalize-addresses', ['--apply' => true])->assertSuccessful();
            $this->assertCount(1, File::files($temporaryStorage.'/app/private/address-normalization'));
        } finally {
            app()->useStoragePath($originalStorage);
            File::deleteDirectory($temporaryStorage);
        }
    }
}
