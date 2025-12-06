<?php

namespace App\GraphQL\Mutations;

use App\Services\TzFileUploadService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class UploadTzFile
{
    protected TzFileUploadService $uploadService;

    public function __construct(TzFileUploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }

    /**
     * Upload file for technical specification.
     */
    public function __invoke(mixed $_, array $args)
    {
        // With @spread directive, fields are at the root level of $args
        $tzId = $args['technical_specification_id'];
        $fileType = strtolower($args['file_type']); // Convert enum to lowercase
        $file = $args['file'];
        $userId = Auth::id();

        Log::info('UploadTzFile mutation called', [
            'tz_id' => $tzId,
            'file_type' => $fileType,
            'user_id' => $userId,
            'file_name' => $file->getClientOriginalName(),
        ]);

        return $this->uploadService->uploadFile($tzId, $fileType, $file, $userId);
    }
}
