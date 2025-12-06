<?php

namespace App\GraphQL\Mutations;

use App\Models\TechnicalSpecificationFile;
use App\Services\TzFileUploadService;

final class DeleteTzFile
{
    protected TzFileUploadService $uploadService;

    public function __construct(TzFileUploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }

    /**
     * Delete technical specification file.
     */
    public function __invoke(mixed $_, array $args)
    {
        $fileId = $args['id'];

        // Get file before deletion to return it
        $file = TechnicalSpecificationFile::findOrFail($fileId);

        // Delete the file
        $this->uploadService->deleteFile($fileId);

        return $file;
    }
}
