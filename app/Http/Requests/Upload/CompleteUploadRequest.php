<?php

declare(strict_types=1);

namespace App\Http\Requests\Upload;

use App\Services\Upload\Data\UploadPartData;
use App\Services\Upload\MultipartUploadService;
use Illuminate\Foundation\Http\FormRequest;

final class CompleteUploadRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'parts' => ['required', 'array', 'min:1', 'max:'.MultipartUploadService::MAX_PARTS],
            'parts.*.part_number' => ['required', 'integer', 'min:1'],
            'parts.*.etag' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return list<UploadPartData> */
    public function parts(): array
    {
        /** @var list<array{part_number: int, etag: string}> $parts */
        $parts = $this->validated('parts');

        return array_map(
            static fn (array $part): UploadPartData => new UploadPartData($part['part_number'], $part['etag']),
            $parts,
        );
    }
}
