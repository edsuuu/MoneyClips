<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use InvalidArgumentException;

final readonly class PosterRegistryService
{
    /**
     * @param  list<PosterInterface>  $posters
     */
    public function __construct(private array $posters) {}

    /** @return list<PosterInterface> */
    public function all(): array
    {
        return $this->posters;
    }

    public function for(string $platform): PosterInterface
    {
        foreach ($this->posters as $poster) {
            if ($poster->platform() === $platform) {
                return $poster;
            }
        }

        throw new InvalidArgumentException(sprintf('Nenhum poster registrado para a plataforma "%s".', $platform));
    }
}
