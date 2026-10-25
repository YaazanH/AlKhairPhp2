<?php

namespace App\Services\StandaloneTransfer;

use Ramsey\Uuid\Uuid;
use RuntimeException;
use SensitiveParameter;

class SettingsBackupIdentity
{
    public function legacyId(#[SensitiveParameter] string $decodedApplicationKey): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'alkhair-settings-backup:'.hash('sha256', $decodedApplicationKey));
    }

    public function sourceId(string $legacyId, string $sourceUrl): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'alkhair-settings-backup-url-v2:'.$legacyId.':'.$this->location($sourceUrl));
    }

    public function location(string $sourceUrl): string
    {
        $parts = parse_url($sourceUrl);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('invalid_source_url');
        }
        $port = $parts['port'] ?? null;

        return strtolower(rtrim($parts['host'], '.'))
            .($port !== null && ! in_array($port, [80, 443], true) ? ':'.$port : '')
            .rtrim($parts['path'] ?? '', '/');
    }
}
