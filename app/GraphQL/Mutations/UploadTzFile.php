<?php

namespace App\GraphQL\Mutations;

use App\Services\TzFileUploadService;
use Illuminate\Support\Facades\Auth;

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
        $tzId = $args['technical_specification_id'];
        $fileType = $args['file_type'];
        $file = $args['file'];
        $userId = Auth::id();

        return $this->uploadService->uploadFile($tzId, $fileType, $file, $userId);
    }
}
