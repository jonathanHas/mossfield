<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds and opens the encrypted `.mfbackup` archive.
 *
 * Layout: a plaintext ZIP (backup.json + an images/ mirror of the public disk)
 * wrapped in a libsodium secretstream, keyed by a password. libsodium is bundled
 * with PHP, so this works on any host regardless of libzip's encryption support.
 *
 *   [ "MFBK1" | salt(16) | secretstream header(24) | ciphertext chunks... ]
 */
class BackupArchive
{
    private const MAGIC = 'MFBK1';

    /** Plaintext read size per secretstream chunk. */
    private const CHUNK = 65536;

    /**
     * Fail fast with a friendly message if the host lacks the required
     * extensions (both present on dev; verify on deploy hosts).
     */
    public static function assertSupported(): void
    {
        if (! extension_loaded('zip') || ! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required for backups but is not installed.');
        }
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('The PHP sodium extension is required for backups but is not installed.');
        }
    }

    /**
     * Build an encrypted archive from the payload + the public image disk.
     * Returns the path to a temp `.mfbackup` file (caller deletes after sending).
     *
     * @param  array<string, mixed>  $payload
     */
    public function build(array $payload, string $password): string
    {
        self::assertSupported();

        $zipPath = $this->tempPath('zip');

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the backup archive.');
            }

            $zip->addFromString('backup.json', json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            $disk = Storage::disk('public');
            foreach ($disk->allFiles() as $file) {
                if (str_starts_with(basename($file), '.')) {
                    continue; // skip .gitignore and friends
                }
                $zip->addFromString('images/'.$file, $disk->get($file));
            }

            $zip->close();

            $encryptedPath = $this->tempPath('mfbackup');
            $this->encryptFile($zipPath, $encryptedPath, $password);

            return $encryptedPath;
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * Decrypt an archive and return its payload plus the path to the decrypted
     * temp ZIP (caller passes it to extractImages() then deletes it).
     *
     * @return array{payload: array<string, mixed>, zipPath: string}
     */
    public function open(string $encryptedPath, string $password): array
    {
        self::assertSupported();

        $zipPath = $this->tempPath('zip');

        try {
            $this->decryptFile($encryptedPath, $zipPath, $password);

            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('The backup archive is unreadable.');
            }

            $json = $zip->getFromName('backup.json');
            $zip->close();

            if ($json === false) {
                throw new RuntimeException('The backup archive is missing its data file.');
            }

            $payload = json_decode($json, true);
            if (! is_array($payload)) {
                throw new RuntimeException('The backup data file is not valid JSON.');
            }

            return ['payload' => $payload, 'zipPath' => $zipPath];
        } catch (Throwable $e) {
            @unlink($zipPath);
            throw $e;
        }
    }

    /**
     * Write every `images/…` entry from a decrypted ZIP back to the public disk.
     * Additive/overwrite — files already present but absent from the backup are
     * left in place. Entry paths are sanitised to prevent zip-slip.
     */
    public function extractImages(string $zipPath): int
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The backup archive is unreadable.');
        }

        $disk = Storage::disk('public');
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || ! str_starts_with($name, 'images/') || str_ends_with($name, '/')) {
                continue;
            }

            $relative = substr($name, strlen('images/'));
            if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
                continue; // path traversal / absolute — skip
            }

            $contents = $zip->getFromIndex($i);
            if ($contents !== false) {
                $disk->put($relative, $contents);
                $count++;
            }
        }

        $zip->close();

        return $count;
    }

    private function encryptFile(string $sourcePath, string $destPath, string $password): void
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = $this->deriveKey($password, $salt);

        [$state, $header] = array_values($this->initPush($key));

        $in = fopen($sourcePath, 'rb');
        $out = fopen($destPath, 'wb');

        try {
            fwrite($out, self::MAGIC.$salt.$header);

            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);
                $tag = feof($in)
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                fwrite($out, $cipher);
            }
        } finally {
            fclose($in);
            fclose($out);
            sodium_memzero($key);
        }
    }

    private function decryptFile(string $sourcePath, string $destPath, string $password): void
    {
        $in = fopen($sourcePath, 'rb');
        $out = fopen($destPath, 'wb');
        $key = null;

        try {
            $magic = fread($in, strlen(self::MAGIC));
            if ($magic !== self::MAGIC) {
                throw new RuntimeException('This is not a Mossfield backup file.');
            }

            $salt = fread($in, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $key = $this->deriveKey($password, $salt);

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);

            $readSize = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

            while (! feof($in)) {
                $cipher = fread($in, $readSize);
                if ($cipher === '' || $cipher === false) {
                    break;
                }

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw new RuntimeException('Incorrect password or corrupt backup file.');
                }

                [$plain, $tag] = $result;
                fwrite($out, $plain);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    break;
                }
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            // Malformed header/lengths etc. — treat as a bad password / corrupt file.
            throw new RuntimeException('Incorrect password or corrupt backup file.');
        } finally {
            fclose($in);
            fclose($out);
            if ($key !== null) {
                sodium_memzero($key);
            }
        }
    }

    private function deriveKey(string $password, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_DEFAULT
        );
    }

    /**
     * @return array{state: string, header: string}
     */
    private function initPush(string $key): array
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        return ['state' => $state, 'header' => $header];
    }

    private function tempPath(string $label): string
    {
        $dir = storage_path('app/private/backups/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // tempnam creates the file atomically and returns a unique path; the
        // $label is only a readability hint in the prefix.
        return tempnam($dir, 'mfbk_'.$label.'_');
    }
}
