<?php

namespace App\GraphQL\Mutations;

use App\Models\ProjectOffer;
use App\Services\ImageCompressionService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

final class UploadProjectOffer
{
    protected ImageCompressionService $compressionService;

    public function __construct(ImageCompressionService $compressionService)
    {
        $this->compressionService = $compressionService;
    }

    /**
     * Upload offer file for project.
     */
    public function __invoke(mixed $_, array $args)
    {
        $projectId = $args['project_id'];
        $file = $args['file'];

        $originalSize = $file->getSize();
        $originalName = $file->getClientOriginalName();

        // Try to compress image if it's an image file
        $compressedFile = $this->compressionService->compressIfImage($file, 1920, 85);

        if ($compressedFile !== null) {
            $fileToUpload = $compressedFile;
            $compressedSize = $compressedFile->getSize();
            $reduction = $this->compressionService->getReductionPercentage($originalSize, $compressedSize);

            Log::info('Image compressed', [
                'original_size' => $this->compressionService->formatFileSize($originalSize),
                'compressed_size' => $this->compressionService->formatFileSize($compressedSize),
                'reduction' => $reduction . '%',
                'file_name' => $originalName,
            ]);
        } else {
            $fileToUpload = $file;
            Log::info('File uploaded without compression (not an image or compression failed)', [
                'file_name' => $originalName,
                'size' => $this->compressionService->formatFileSize($originalSize),
            ]);
        }

        // Store file in Yandex Cloud S3 in bonus folder
        $path = $fileToUpload->store('bonus/projects/offers', 'yandex');

        // Get public URL - for Yandex Cloud S3
        $bucket = config('filesystems.disks.yandex.bucket');
        $endpoint = config('filesystems.disks.yandex.endpoint');
        $url = rtrim($endpoint, '/') . '/' . $bucket . '/' . $path;

        // Get the highest order number for this project
        $maxOrder = ProjectOffer::where('project_id', $projectId)->max('order') ?? -1;

        // Create new offer record
        $offer = ProjectOffer::create([
            'project_id' => $projectId,
            'file_url' => $url,
            'file_name' => $originalName,
            'file_size' => $fileToUpload->getSize(),
            'mime_type' => $file->getMimeType(),
            'order' => $maxOrder + 1,
        ]);

        // Clean up temporary compressed file if it exists
        if ($compressedFile !== null && file_exists($compressedFile->getRealPath())) {
            @unlink($compressedFile->getRealPath());
        }

        return $offer;
    }
}
