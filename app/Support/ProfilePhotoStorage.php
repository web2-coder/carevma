<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class ProfilePhotoStorage
{
    public static function diskPath(?string $url, string $folder): ?string
    {
        if (!$url) return null;
        $value = str_replace('\\', '/', trim($url));
        // Only delete files inside the dedicated profile photo folder on the public disk.
        if (str_starts_with($value, '/storage/')) $value = substr($value, 9);
        elseif (str_starts_with($value, 'storage/')) $value = substr($value, 8);
        if (!str_starts_with($value, trim($folder, '/').'/')) return null;
        if (str_contains($value, '..') || str_contains($value, '://')) return null;
        return $value;
    }

    public static function deleteOld(?string $url, string $folder): void
    {
        $path = self::diskPath($url, $folder);
        if ($path) Storage::disk('public')->delete($path);
    }
}
