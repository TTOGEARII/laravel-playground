<?php

namespace Tests\Feature;

use App\Services\HelperService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class HelperServiceTest extends TestCase
{
    private string $relativeDir = 'images/__test_helper';

    protected function tearDown(): void
    {
        // 테스트로 생성된 파일/디렉터리 정리
        $dir = public_path($this->relativeDir);
        if (is_dir($dir)) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function test_save_image_returns_relative_path_with_extension(): void
    {
        $helper = new HelperService;
        // GD 의존을 피하기 위해 image() 대신 create() 사용 (확장자 보존 검증이 목적)
        $file = UploadedFile::fake()->create('cover.png', 10, 'image/png');

        $path = $helper->saveImage($file, $this->relativeDir, 'test_');

        $this->assertStringStartsWith($this->relativeDir.'/test_', $path);
        $this->assertStringEndsWith('.png', $path);
        $this->assertFileExists(public_path($path));
    }

    public function test_save_image_falls_back_to_extension_when_original_missing(): void
    {
        $helper = new HelperService;
        // 원본 확장자가 없는 파일 → guessExtension() 폴백으로 확장자가 채워져야 한다 (Fix 5)
        $file = UploadedFile::fake()->createWithContent('noext', 'plain-text-content');

        $path = $helper->saveImage($file, $this->relativeDir, 'test_');

        // 깨진 'test_xxx.' 형태가 아니라 확장자가 붙어야 한다
        $this->assertDoesNotMatchRegularExpression('/\.$/', $path);
        $this->assertFileExists(public_path($path));
    }

    public function test_delete_image_is_safe_for_null_and_missing(): void
    {
        $helper = new HelperService;

        // 예외 없이 조용히 무시되어야 한다
        $helper->deleteImage(null);
        $helper->deleteImage('');
        $helper->deleteImage('images/does/not/exist.png');

        $this->assertTrue(true);
    }
}
