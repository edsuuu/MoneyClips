<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SignedPartUrlsResource extends JsonResource
{
    public static $wrap = 'urls';

    /** @param  array<int, string>  $urls */
    public function __construct(private readonly array $urls)
    {
        parent::__construct($urls);
    }

    /** @return array<int, string> */
    public function toArray(Request $request): array
    {
        return $this->urls;
    }
}
