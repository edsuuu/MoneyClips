<?php

declare(strict_types=1);

namespace App\Http\Requests\Upload;

use App\Services\Upload\MultipartUploadService;
use Illuminate\Foundation\Http\FormRequest;

final class SignUploadPartsRequest extends FormRequest
{
    private const int MAX_WINDOW = 20;

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'part_numbers' => ['required', 'array', 'min:1', 'max:'.self::MAX_WINDOW],
            'part_numbers.*' => ['required', 'integer', 'min:1', 'max:'.MultipartUploadService::MAX_PARTS],
        ];
    }

    /** @return list<int> */
    public function partNumbers(): array
    {
        /** @var list<int> $numbers */
        $numbers = $this->validated('part_numbers');

        return $numbers;
    }
}
