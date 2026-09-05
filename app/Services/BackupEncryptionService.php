<?php

namespace App\Services;

use RuntimeException;

class BackupEncryptionService
{
    private const MAGIC = "ALKHAIR-BACKUP\x01";

    public function encrypt(string $sourcePath, string $destinationPath): array
    {
        $source = fopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'xb');

        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }

            throw new RuntimeException('Unable to open the backup encryption streams.');
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());
            $this->writeAll($destination, self::MAGIC.$header);

            $chunkSize = max(64 * 1024, (int) config('backups.encryption_chunk_size', 1024 * 1024));
            $current = fread($source, $chunkSize);

            if ($current === false) {
                throw new RuntimeException('Unable to read the backup archive for encryption.');
            }

            if ($current === '') {
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    '',
                    '',
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
                );
                $this->writeAll($destination, pack('N', strlen($ciphertext)).$ciphertext);
            }

            while ($current !== '') {
                $next = fread($source, $chunkSize);
                if ($next === false) {
                    throw new RuntimeException('Unable to continue reading the backup archive.');
                }

                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $current,
                    '',
                    $next === ''
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );

                $this->writeAll($destination, pack('N', strlen($ciphertext)).$ciphertext);
                $current = $next;
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        return [
            'sha256' => hash_file('sha256', $destinationPath),
            'size_bytes' => filesize($destinationPath) ?: 0,
        ];
    }

    public function decrypt(string $sourcePath, string $destinationPath): void
    {
        $source = fopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'xb');

        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }

            throw new RuntimeException('Unable to open the backup decryption streams.');
        }

        try {
            $prefix = $this->readExact($source, strlen(self::MAGIC));
            if (! hash_equals(self::MAGIC, $prefix)) {
                throw new RuntimeException('This is not a supported Alkhair backup file.');
            }

            $header = $this->readExact($source, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key());
            $sawFinalChunk = false;
            $maximumCiphertextLength = max(64 * 1024, (int) config('backups.encryption_chunk_size', 1024 * 1024))
                + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

            while (! feof($source)) {
                $lengthBytes = fread($source, 4);
                if ($lengthBytes === false) {
                    throw new RuntimeException('Unable to read the encrypted backup frame.');
                }
                if ($lengthBytes === '') {
                    break;
                }
                if (strlen($lengthBytes) !== 4) {
                    throw new RuntimeException('The encrypted backup frame is truncated.');
                }

                $length = unpack('Nlength', $lengthBytes)['length'];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > $maximumCiphertextLength) {
                    throw new RuntimeException('The encrypted backup frame length is invalid.');
                }

                $ciphertext = $this->readExact($source, $length);
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
                if ($pulled === false) {
                    throw new RuntimeException('Backup authentication failed. The file is damaged or uses another application key.');
                }

                [$plaintext, $tag] = $pulled;
                $this->writeAll($destination, $plaintext);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $sawFinalChunk = true;
                    if (! feof($source) && fread($source, 1) !== '') {
                        throw new RuntimeException('The encrypted backup contains trailing data.');
                    }
                    break;
                }
            }

            if (! $sawFinalChunk) {
                throw new RuntimeException('The encrypted backup is incomplete.');
            }
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    private function key(): string
    {
        $configuredKey = (string) config('app.key');
        if ($configuredKey === '') {
            throw new RuntimeException('APP_KEY must be configured before backups can be encrypted.');
        }

        $keyMaterial = str_starts_with($configuredKey, 'base64:')
            ? base64_decode(substr($configuredKey, 7), true)
            : $configuredKey;

        if (! is_string($keyMaterial) || $keyMaterial === '') {
            throw new RuntimeException('APP_KEY is invalid.');
        }

        return hash_hkdf(
            'sha256',
            $keyMaterial,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            'alkhair-system-backup-v1',
        );
    }

    private function readExact($stream, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length && ! feof($stream)) {
            $chunk = fread($stream, $length - strlen($buffer));
            if ($chunk === false) {
                throw new RuntimeException('Unable to read the encrypted backup.');
            }
            $buffer .= $chunk;
        }

        if (strlen($buffer) !== $length) {
            throw new RuntimeException('The encrypted backup is truncated.');
        }

        return $buffer;
    }

    private function writeAll($stream, string $contents): void
    {
        $written = 0;
        $length = strlen($contents);

        while ($written < $length) {
            $result = fwrite($stream, substr($contents, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Unable to write the backup stream.');
            }
            $written += $result;
        }
    }
}
