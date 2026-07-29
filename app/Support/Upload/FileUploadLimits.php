<?php

namespace App\Support\Upload;

final class FileUploadLimits
{
    public static function maxKilobytes(): int
    {
        return (int) config('upload.max_file_size', 30720);
    }

    public static function maxBytes(): int
    {
        return self::maxKilobytes() * 1024;
    }

    public static function maxMegabytesLabel(): int
    {
        return (int) round(self::maxKilobytes() / 1024);
    }

    public static function prepareLongRunningUpload(): void
    {
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');
    }

    /**
     * Allow long SQL imports (may run many minutes on large dumps).
     */
    public static function prepareLongRunningSqlImport(): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
    }
}
