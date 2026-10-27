<?php

namespace App\Support;

class MediaUrl
{
    public static function normalize(?string $path): ?string
    {
        if (!$path) return null;
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') return null;

        if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, 'data:')) {
            return $path;
        }

        if (str_starts_with($path, '/storage/') || str_starts_with($path, '/assets/') || str_starts_with($path, '/images/')) {
            return $path;
        }

        if (str_starts_with($path, 'storage/')) return '/'.$path;
        if (str_starts_with($path, 'public/storage/')) return '/'.substr($path, 7);
        if (str_starts_with($path, 'public/')) return '/'.substr($path, 7);
        if (str_starts_with($path, 'assets/') || str_starts_with($path, 'images/')) return '/'.$path;
        if (str_starts_with($path, '/')) return $path;

        // Historical employee/client uploads were often stored as disk-relative paths.
        return '/storage/'.ltrim($path, '/');
    }
}
