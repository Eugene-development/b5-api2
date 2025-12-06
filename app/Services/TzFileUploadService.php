<?php

namespace App\Services;

use App\Models\TechnicalSpecificationFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Exception;

class TzFileUploadService
{
    /**
     * Maximum file size in bytes (10MB)
     */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Allowed MIME types for sketch files
     */
    private const ALLOWED_SKETCH_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];

    /**
     * Allowed MIME types for commercial offer files
     */
    private const ALLOWED_OFFER_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        // Allow images for commercial offers
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];

    /**
     * Upload a file for a technical specification
     *
     * @param string $tzId Technical specification ID
     * @param string $fileType File type ('sketch' or 'commercial_offer')
     * @param UploadedFile $file The uploaded file
     * @param string $userId User ID who is uploading
     * @return TechnicalSpecificationFile
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function uploadFile(
        string $tzId,
        string $fileType,
        UploadedFile $file,
        string $userId
    ): TechnicalSpecificationFile {
        // Validate file
        $this->validateFile($file, $fileType);

        DB::beginTransaction();

        try {
            // Upload to S3
            $path = $this->uploadToS3($file, $tzId, $fileType);

            // Create database record
            $fileRecord = TechnicalSpecificationFile::create([
                'id' => Str::ulid()->toBase32(),
                'technical_specification_id' => $tzId,
                'file_type' => $fileType,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by' => $userId,
            ]);

            DB::commit();

            return $fileRecord;
        } catch (Exception $e) {
            DB::rollBack();

            // Clean up uploaded file if DB insert failed
            if (isset($path)) {
                try {
                    Storage::disk('yandex')->delete($path);
                } catch (Exception $deleteException) {
                    \Log::error('Failed to delete file from S3 after DB rollback', [
                        'file_path' => $path,
                        'error' => $deleteException->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }

    /**
     * Delete a file
     *
     * @param string $fileId File ID to delete
     * @return bool
     * @throws Exception
     */
    public function deleteFile(string $fileId): bool
    {
        $file = TechnicalSpecificationFile::findOrFail($fileId);

        DB::beginTransaction();

        try {
            // Delete from database
            $file->delete();

            // Delete from S3 (Yandex Cloud)
            try {
                Storage::disk('yandex')->delete($file->file_path);
            } catch (Exception $e) {
                // Log error but don't fail the transaction
                // This allows the database record to be deleted even if S3 deletion fails
                \Log::error('Failed to delete file from S3', [
                    'file_id' => $fileId,
                    'file_path' => $file->file_path,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Validate uploaded file
     *
     * @param UploadedFile $file
     * @param string $fileType
     * @throws InvalidArgumentException
     */
    private function validateFile(UploadedFile $file, string $fileType): void
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException(
                'File size exceeds maximum allowed size of 10MB'
            );
        }

        // Check MIME type
        $allowedTypes = $fileType === 'sketch'
            ? self::ALLOWED_SKETCH_TYPES
            : self::ALLOWED_OFFER_TYPES;

        $fileMimeType = $file->getMimeType();

        // Log for debugging
        \Log::info('File validation', [
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $fileType,
            'mime_type' => $fileMimeType,
            'file_size' => $file->getSize(),
            'allowed_types' => $allowedTypes,
        ]);

        if (!in_array($fileMimeType, $allowedTypes)) {
            throw new InvalidArgumentException(
                "File type '{$fileMimeType}' not allowed for {$fileType}. Allowed types: " . implode(', ', $allowedTypes)
            );
        }
    }

    /**
     * Upload file to S3 (Yandex Cloud)
     *
     * @param UploadedFile $file
     * @param string $tzId
     * @param string $fileType
     * @return string File path in S3
     * @throws Exception
     */
    private function uploadToS3(
        UploadedFile $file,
        string $tzId,
        string $fileType
    ): string {
        $filename = Str::ulid() . '_' . $file->getClientOriginalName();
        $path = "bonus/technical-specifications/{$tzId}/{$fileType}/{$filename}";

        Storage::disk('yandex')->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }
}
