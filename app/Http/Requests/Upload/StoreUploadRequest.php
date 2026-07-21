<?php

declare(strict_types=1);

namespace App\Http\Requests\Upload;

use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;

final class StoreUploadRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'file_size' => ['required', 'integer', 'min:1', 'max:'.Video::MAX_BYTES],
            'mime_type' => ['required', 'string', 'in:'.implode(',', Video::MIME_TYPES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file_size.max' => 'O vídeo passa do limite de 3GB.',
            'mime_type.in' => 'Formato não suportado — envie MP4, MOV ou WEBM.',
        ];
    }

    public function fileSize(): int
    {
        return (int) $this->validated('file_size');
    }

    public function mimeType(): string
    {
        return (string) $this->validated('mime_type');
    }
}
