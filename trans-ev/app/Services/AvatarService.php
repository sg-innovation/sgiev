<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Profile photos: re-encoded as a 256 px square JPEG (strips location and other metadata) on the public disk. */
class AvatarService
{
    public const RULES = ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000'];

    public function store(User $user, UploadedFile $file): string
    {
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw ValidationException::withMessages(['photo' => 'That image could not be read. Try a JPG or PNG.']);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $out = imagecreatetruecolor(256, 256);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 256, 256, $side, $side);
        ob_start();
        imagejpeg($out, null, 85);
        $jpeg = (string) ob_get_clean();

        $old = $user->avatar_path;
        $path = 'avatars/'.$user->id.'-'.Str::random(16).'.jpg';
        Storage::disk('public')->put($path, $jpeg);
        $user->forceFill(['avatar_path' => $path])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $path;
    }

    public function remove(User $user): void
    {
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }
    }
}
