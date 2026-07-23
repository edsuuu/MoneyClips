<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\TemplateStyleEnum;

final readonly class TemplateRenderOptionsData
{
    public function __construct(
        public TemplateStyleEnum $style,
        public string $channelName,
        public string $channelHandle,
        public bool $markReady = true,
    ) {}

    /** @param  array<string, mixed>  $options */
    public static function fromArray(array $options): self
    {
        return new self(
            style: TemplateStyleEnum::tryFrom((string) ($options['style'] ?? '')) ?? TemplateStyleEnum::White,
            channelName: (string) ($options['channel_name'] ?? ''),
            channelHandle: (string) ($options['channel_handle'] ?? ''),
            markReady: (bool) ($options['mark_ready'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'style' => $this->style->value,
            'channel_name' => $this->channelName,
            'channel_handle' => $this->channelHandle,
            'mark_ready' => $this->markReady,
        ];
    }
}
