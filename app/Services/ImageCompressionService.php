<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageCompressionService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Compress image if it's an image file
     *
     * @param UploadedFile $file
     * @param int $maxWidth Maximum width in pixels
     * @param int $quality Quality for JPEG/WebP (1-100), for PNG will be converted to compression level
     * @return UploadedFile|null Returns compressed file or null if not an image or compression didn't reduce size
     */
    public function compressIfImage(UploadedFile $file, int $maxWidth = 1920, int $quality = 85): ?UploadedFile
    {
        // Check if file is an image
        if (!$this->isImage($file)) {
            return null;
        }

        try {
            // Read the image
            $image = $this->manager->read($file->getRealPath());

            // Get original dimensions
            $originalWidth = $image->width();
            $originalHeight = $image->height();
            $originalSize = $file->getSize();

            // Only resize if image is larger than max width
            $needsResize = $originalWidth > $maxWidth;
            if ($needsResize) {
                $image->scale(width: $maxWidth);
            }

            // If image is small and doesn't need resize, skip compression
            // (small images often get larger after re-encoding)
            if (!$needsResize && $originalSize < 500000) { // Less than 500KB
                Log::info('Compression skipped: image is small and already optimized', [
                    'size' => $this->formatFileSize($originalSize),
                    'dimensions' => $originalWidth . 'x' . $originalHeight,
                    'file_name' => $file->getClientOriginalName(),
                ]);
                return null;
            }

            // Determine format and encode
            $extension = strtolower($file->getClientOriginalExtension());
            $tempPath = sys_get_temp_dir() . '/' . uniqid('compressed_') . '.' . $extension;

            switch ($extension) {
                case 'jpg':
                case 'jpeg':
                    $image->toJpeg($quality)->save($tempPath);
                    break;
                case 'png':
                    // PNG: Keep as PNG to preserve quality and potential transparency
                    // Only compress if image was resized, otherwise skip
                    $image->toPng()->save($tempPath);
                    break;
                case 'webp':
                    $image->toWebp($quality)->save($tempPath);
                    break;
                case 'gif':
                    $image->toGif()->save($tempPath);
                    break;
                default:
                    return null;
            }

            // Check if compressed file is actually smaller
            $compressedSize = filesize($tempPath);
            $originalSize = $file->getSize();

            // If compressed file is larger or same size, don't use it
            if ($compressedSize >= $originalSize) {
                @unlink($tempPath);
                Log::info('Compression skipped: compressed file is not smaller', [
                    'original_size' => $this->formatFileSize($originalSize),
                    'compressed_size' => $this->formatFileSize($compressedSize),
                    'file_name' => $file->getClientOriginalName(),
                ]);
                return null;
            }

            // Create new UploadedFile from compressed image
            $compressedFile = new UploadedFile(
                $tempPath,
                $file->getClientOriginalName(),
                $file->getMimeType(),
                null,
                true // Mark as test file to avoid validation errors
            );

            return $compressedFile;
        } catch (\Exception $e) {
            // If compression fails, return null
            Log::error('Image compression failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if file is an image
     *
     * @param UploadedFile $file
     * @return bool
     */
    protected function isImage(UploadedFile $file): bool
    {
        $mimeType = $file->getMimeType();
        $allowedMimes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
        ];

        return in_array($mimeType, $allowedMimes);
    }



    /**
     * Get file size reduction percentage
     *
     * @param int $originalSize
     * @param int $compressedSize
     * @return float
     */
    public function getReductionPercentage(int $originalSize, int $compressedSize): float
    {
        if ($originalSize === 0) {
            return 0;
        }

        return round((($originalSize - $compressedSize) / $originalSize) * 100, 2);
    }

    /**
     * Format file size for display
     *
     * @param int $bytes
     * @return string
     */
    public function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 Bytes';
        }

        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));

        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }
}
