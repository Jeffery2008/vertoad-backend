<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

interface ObjectStorageUploadSignerInterface
{
    public function presignPut(PresignedUploadRequest $request): PresignedUpload;
}
