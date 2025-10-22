<?php

namespace App\GraphQL\Mutations;

use App\Models\ProjectSketch;
use Illuminate\Support\Facades\Storage;

final class UploadProjectSketch
{
    /**
     * Upload sketch file for project.
     */
    public function __invoke(mixed $_, array $args)
    {
        $projectId = $args['project_id'];
        $file = $args['file'];

        // Store file in Yandex Cloud S3 in bonus folder
        $path = $file->store('bonus/projects/sketches', 'yandex');

        // Get public URL - for Yandex Cloud S3
        $bucket = config('filesystems.disks.yandex.bucket');
        $endpoint = config('filesystems.disks.yandex.endpoint');
        $url = rtrim($endpoint, '/') . '/' . $bucket . '/' . $path;

        // Get the highest order number for this project
        $maxOrder = ProjectSketch::where('project_id', $projectId)->max('order') ?? -1;

        // Create new sketch record
        $sketch = ProjectSketch::create([
            'project_id' => $projectId,
            'file_url' => $url,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'order' => $maxOrder + 1,
        ]);

        return $sketch;
    }
}
