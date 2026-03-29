<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AdmitCardController;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdmitCardPhotoResolverTest extends TestCase
{
    public function test_it_embeds_local_student_photos_as_data_urls(): void
    {
        $path = 'students/avatars/test-admit-photo.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1cAAAAASUVORK5CYII=');
        Storage::disk('public')->put($path, $png);

        $controller = new AdmitCardController();
        $method = new \ReflectionMethod($controller, 'normalizeStudentPhotoCandidate');
        $method->setAccessible(true);

        $resolved = $method->invoke($controller, $path);

        $this->assertIsString($resolved);
        $this->assertStringStartsWith('data:image/png;base64,', $resolved);

        Storage::disk('public')->delete($path);
    }

    public function test_it_keeps_remote_student_photos_when_no_local_file_exists(): void
    {
        $controller = new AdmitCardController();
        $method = new \ReflectionMethod($controller, 'normalizeStudentPhotoCandidate');
        $method->setAccessible(true);

        $resolved = $method->invoke($controller, 'https://cdn.example.com/student-photo.jpg');

        $this->assertSame('https://cdn.example.com/student-photo.jpg', $resolved);
    }
}
