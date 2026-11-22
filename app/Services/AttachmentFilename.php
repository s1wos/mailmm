<?php

namespace App\Services;

use Illuminate\Support\Str;

class AttachmentFilename
{
    public static function normalize(?string $name): string
    {
        $basename = Str::afterLast(str_replace('\\', '/', $name ?? ''), '/');
        $clean = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $basename), '. ');

        return $clean === '' ? 'attachment' : $clean;
    }
}
