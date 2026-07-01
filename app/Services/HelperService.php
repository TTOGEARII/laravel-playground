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

        // 저장 확장자는 반드시 파일 "내용"으로 추정한 값(guessExtension)만 쓴다.
        // 클라이언트 원본 확장자(getClientOriginalExtension)는 공격자가 지정할 수 있어(예: 이미지 폴리글롯을 .php 로 위장)
        // 웹 실행 가능 디렉터리(public)에 위험 확장자로 저장되는 것을 막기 위해 화이트리스트로만 허용한다.
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $guessed = strtolower((string) $file->guessExtension());
        $extension = in_array($guessed, $allowed, true) ? $guessed : 'jpg';
        $filename = uniqid($prefix, true).'.'.$extension;
        $file->move($dir, $filename);

        return $directory.'/'.$filename;
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
            : public_path('storage/'.$path);
        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }
}
