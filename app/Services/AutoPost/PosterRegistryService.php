<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Contracts\PosterInterface;
use InvalidArgumentException;

/**
 * Registro dos posters por plataforma. A lista é montada no
 * AppServiceProvider — adicionar plataforma nova = adicionar Poster lá.
 */
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

    /** @return list<PosterInterface> */
    public function enabled(): array
    {
        return array_values(array_filter($this->posters, static fn (PosterInterface $p): bool => $p->isEnabled()));
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
