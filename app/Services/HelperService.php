<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * 여러 서비스에서 공통으로 사용하는 헬퍼 메서드 모음.
 */
class HelperService
{
    /**
     * 이미지를 public 하위 디렉터리에 저장하고 DB 저장용 상대 경로 반환.
     *
     * @param  string  $directory  public 기준 상대 경로 (예: images/chat-bot/character)
     * @param  string  $prefix  파일명 접두사 (예: char_, profile_)
     * @return string DB에 저장할 상대 경로 (예: images/chat-bot/character/char_xxx.jpg)
     */
    public function saveImage(UploadedFile $file, string $directory, string $prefix = 'img_'): string
    {
        $directory = trim($directory, '/');
        $dir = public_path($directory);
        File::ensureDirectoryExists($dir);

        $filename = uniqid($prefix, true) . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);

        return $directory . '/' . $filename;
    }

    /**
     * DB에 저장된 상대 경로 기준으로 이미지 파일 삭제.
     * - images/... → public_path($path)
     * - 그 외 (기존 storage 등) → public_path('storage/' . $path)
     */
    public function deleteImage(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }
        $path = ltrim($path, '/');
        $fullPath = str_starts_with($path, 'images/')
            ? public_path($path)
            : public_path('storage/' . $path);
        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }
}
