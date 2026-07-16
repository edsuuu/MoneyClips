<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Services\AutoPost\Posters\PosterContract;
use InvalidArgumentException;

/**
 * Registro dos posters por plataforma. A lista é montada no
 * AppServiceProvider — adicionar plataforma nova = adicionar Poster lá.
 */
final readonly class PosterRegistry
{
    /**
     * @param  list<PosterContract>  $posters
     */
    public function __construct(private array $posters) {}

    /** @return list<PosterContract> */
    public function all(): array
    {
        return $this->posters;
    }

    /** @return list<PosterContract> */
    public function enabled(): array
    {
        return array_values(array_filter($this->posters, static fn (PosterContract $p): bool => $p->isEnabled()));
    }

    public function for(string $platform): PosterContract
    {
        foreach ($this->posters as $poster) {
            if ($poster->platform() === $platform) {
                return $poster;
            }
        }

        throw new InvalidArgumentException(sprintf('Nenhum poster registrado para a plataforma "%s".', $platform));
    }
}
