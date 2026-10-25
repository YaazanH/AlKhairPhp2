<?php

namespace Tests\Unit;

use App\Services\StandaloneTransfer\SettingsBackupIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SettingsBackupIdentityTest extends TestCase
{
    public function test_shared_key_is_not_an_installation_identifier_by_itself(): void
    {
        $identity = new SettingsBackupIdentity;
        $key = $identity->legacyId(str_repeat('a', 32));
        $ids = array_map(fn ($url) => $identity->sourceId($key, $url), [
            'https://first.example.test', 'https://second.example.test',
            'https://first.example.test/school', 'https://first.example.test:8443',
        ]);
        $this->assertCount(4, array_unique($ids));
    }

    public function test_normalized_addresses_keep_the_same_source_identity(): void
    {
        $identity = new SettingsBackupIdentity;
        $key = $identity->legacyId(str_repeat('a', 32));
        $expected = $identity->sourceId($key, 'https://school.example.test/app');
        foreach (['http://SCHOOL.EXAMPLE.TEST:80/app/', 'https://school.example.test.:443/app///', 'https://school.example.test/app?preview=1#top'] as $url) {
            $this->assertSame($expected, $identity->sourceId($key, $url));
        }
    }

    #[DataProvider('invalidLocations')]
    public function test_identity_rejects_invalid_source_locations(string $url): void
    {
        $this->expectException(RuntimeException::class);
        (new SettingsBackupIdentity)->location($url);
    }

    public static function invalidLocations(): array
    {
        return [[''], ['/relative'], ['file:///tmp/app'], ['https://user:password@school.example.test']];
    }
}
