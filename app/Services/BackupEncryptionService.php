<?php

namespace App\Services;

use RuntimeException;

class BackupEncryptionService
{
    private const SODIUM_MAGIC = "ALKHAIR-BACKUP\x01";

    private const OPENSSL_MAGIC = "ALKHAIR-BACKUP\x02";

    private const KEY_BYTES = 32;

    private const OPENSSL_HEADER_BYTES = 8;

    private const OPENSSL_TAG_BYTES = 16;

    public function encrypt(string $sourcePath, string $destinationPath): array
    {
        return $this->supportsSodium()
            ? $this->encryptWithSodium($sourcePath, $destinationPath)
            : $this->encryptWithOpenSsl($sourcePath, $destinationPath);
    }

    public function decrypt(string $sourcePath, string $destinationPath): void
    {
        $source = fopen($sourcePath, 'rb');

        if ($source === false) {
            throw new RuntimeException('Unable to open the encrypted backup.');
        }

        try {
            $magic = $this->readExact($source, strlen(self::SODIUM_MAGIC));
        } finally {
            fclose($source);
        }

        if (hash_equals(self::SODIUM_MAGIC, $magic)) {
            $this->decryptWithSodium($sourcePath, $destinationPath);

            return;
        }

        if (hash_equals(self::OPENSSL_MAGIC, $magic)) {
            $this->decryptWithOpenSsl($sourcePath, $destinationPath);

            return;
        }

        throw new RuntimeException('This is not a supported Alkhair backup file.');
    }

    private function encryptWithSodium(string $sourcePath, string $destinationPath): array
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
            $this->writeAll($destination, self::SODIUM_MAGIC.$header);

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

    private function decryptWithSodium(string $sourcePath, string $destinationPath): void
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
            $prefix = $this->readExact($source, strlen(self::SODIUM_MAGIC));
            if (! hash_equals(self::SODIUM_MAGIC, $prefix)) {
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
            self::KEY_BYTES,
            'alkhair-system-backup-v1',
        );
    }

    private function encryptWithOpenSsl(string $sourcePath, string $destinationPath): array
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
            $noncePrefix = random_bytes(self::OPENSSL_HEADER_BYTES);
            $this->writeAll($destination, self::OPENSSL_MAGIC.$noncePrefix);

            $chunkSize = max(64 * 1024, (int) config('backups.encryption_chunk_size', 1024 * 1024));
            $current = fread($source, $chunkSize);
            $counter = 0;

            if ($current === false) {
                throw new RuntimeException('Unable to read the backup archive for encryption.');
            }

            while ($current !== '') {
                $next = fread($source, $chunkSize);
                if ($next === false) {
                    throw new RuntimeException('Unable to continue reading the backup archive.');
                }

                $this->writeOpenSslFrame($destination, $current, $noncePrefix, $counter, $next === '');
                $counter++;
                $current = $next;
            }

            if ($counter === 0) {
                $this->writeOpenSslFrame($destination, '', $noncePrefix, $counter, true);
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

    private function decryptWithOpenSsl(string $sourcePath, string $destinationPath): void
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
            $prefix = $this->readExact($source, strlen(self::OPENSSL_MAGIC));
            if (! hash_equals(self::OPENSSL_MAGIC, $prefix)) {
                throw new RuntimeException('This is not a supported Alkhair backup file.');
            }

            $noncePrefix = $this->readExact($source, self::OPENSSL_HEADER_BYTES);
            $chunkSize = max(64 * 1024, (int) config('backups.encryption_chunk_size', 1024 * 1024));
            $maximumFrameLength = $chunkSize + self::OPENSSL_TAG_BYTES + 1;
            $counter = 0;
            $sawFinalFrame = false;

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
                if ($length < self::OPENSSL_TAG_BYTES + 1 || $length > $maximumFrameLength) {
                    throw new RuntimeException('The encrypted backup frame length is invalid.');
                }

                $frame = $this->readExact($source, $length);
                $isFinalFrame = ord($frame[0]) === 1;
                if (! in_array(ord($frame[0]), [0, 1], true)) {
                    throw new RuntimeException('The encrypted backup frame is invalid.');
                }

                $plaintext = openssl_decrypt(
                    substr($frame, self::OPENSSL_TAG_BYTES + 1),
                    'aes-256-gcm',
                    $this->key(),
                    OPENSSL_RAW_DATA,
                    $noncePrefix.pack('N', $counter),
                    substr($frame, 1, self::OPENSSL_TAG_BYTES),
                    pack('N', $counter),
                );
                if ($plaintext === false) {
                    throw new RuntimeException('Backup authentication failed. The file is damaged or uses another application key.');
                }

                $this->writeAll($destination, $plaintext);

                if ($isFinalFrame) {
                    $sawFinalFrame = true;
                    if (! feof($source) && fread($source, 1) !== '') {
                        throw new RuntimeException('The encrypted backup contains trailing data.');
                    }
                    break;
                }

                $counter++;
            }

            if (! $sawFinalFrame) {
                throw new RuntimeException('The encrypted backup is incomplete.');
            }
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    private function writeOpenSslFrame($destination, string $plaintext, string $noncePrefix, int $counter, bool $isFinalFrame): void
    {
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $noncePrefix.pack('N', $counter),
            $tag,
            pack('N', $counter),
            self::OPENSSL_TAG_BYTES,
        );
        if ($ciphertext === false || strlen($tag) !== self::OPENSSL_TAG_BYTES) {
            throw new RuntimeException('Unable to encrypt the backup archive.');
        }

        $frame = chr($isFinalFrame ? 1 : 0).$tag.$ciphertext;
        $this->writeAll($destination, pack('N', strlen($frame)).$frame);
    }

    private function supportsSodium(): bool
    {
        return function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_push')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_pull');
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
