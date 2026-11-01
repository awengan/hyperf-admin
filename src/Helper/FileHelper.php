<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use RuntimeException;

final class FileHelper
{
    /**
     * Recursively copy a directory, creating the destination when needed.
     *
     * @return int number of copied files
     */
    public static function copyDir(string $source, string $destination): int
    {
        if (! is_dir($source)) {
            throw new RuntimeException(sprintf('Source directory "%s" does not exist.', $source));
        }

        if (! is_dir($destination) && ! @mkdir($destination, 0755, true) && ! is_dir($destination)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $destination));
        }

        $copied = 0;
        $entries = scandir($source) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source . DIRECTORY_SEPARATOR . $entry;
            $to = $destination . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($from)) {
                $copied += self::copyDir($from, $to);
                continue;
            }

            // 内容没变就不重写：mtime 是资源版本号（`/static/v<hash>/`）与
            // `.gz` 有效性判断的依据，每次启动都刷新 mtime 会让两者都失效。
            if (is_file($to) && @filesize($from) === @filesize($to) && @md5_file($from) === @md5_file($to)) {
                ++$copied;
                continue;
            }

            if (@copy($from, $to)) {
                ++$copied;
            }
        }

        return $copied;
    }

    /**
     * Remove a file or a directory tree, ignoring missing paths.
     */
    public static function deleteDir(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::deleteDir($path . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($path);
    }

    /**
     * Whether the given directory exists and contains at least one entry.
     */
    public static function hasFiles(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy a single file, creating the destination directory when needed.
     */
    public static function copyFile(string $source, string $destination): void
    {
        if (! is_file($source)) {
            throw new RuntimeException(sprintf('Source file "%s" does not exist.', $source));
        }

        $directory = dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }

        if (! @copy($source, $destination)) {
            throw new RuntimeException(sprintf('Unable to copy "%s" to "%s".', $source, $destination));
        }
    }

    /**
     * Copy a file unless the destination already exists (never clobbers user config).
     */
    public static function copyFileIfMissing(string $source, string $destination): void
    {
        if (is_file($destination)) {
            return;
        }

        self::copyFile($source, $destination);
    }
}
