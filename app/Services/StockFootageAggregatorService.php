<?php

namespace App\Services;

use App\Services\StockSources\PexelsSearchService;
use App\Services\StockSources\PixabaySearchService;
use App\Services\StockSources\SmithsonianSearchService;
use App\Services\StockSources\WikimediaCommonsSearchService;

class StockFootageAggregatorService
{
    private const MAX_CANDIDATES_PER_SOURCE = 3;
    private const MAX_TOTAL_CANDIDATES = 12;
    private const MIN_CANDIDATES_BEFORE_SECOND_KEYWORD = 3;

    /** @var array<int,object> */
    private array $sources;

    public function __construct(
        PexelsSearchService $pexels,
        PixabaySearchService $pixabay,
        WikimediaCommonsSearchService $wikimedia,
        SmithsonianSearchService $smithsonian
    ) {
        $this->sources = [$pexels, $pixabay, $wikimedia, $smithsonian];
    }

    /**
     * Fan a scene's keywords out across all configured stock sources and merge the results.
     * Each source is wrapped so one dead/erroring provider never blocks the others.
     *
     * @param array<int,string> $keywords
     * @return array<int,array<string,mixed>>
     */
    public function searchScene(array $keywords, string $sceneType): array
    {
        $keywords = array_values(array_filter($keywords)) ?: [$sceneType];
        $candidates = $this->searchAllSources($keywords[0]);

        if (count($candidates) < self::MIN_CANDIDATES_BEFORE_SECOND_KEYWORD && isset($keywords[1])) {
            $candidates = array_merge($candidates, $this->searchAllSources($keywords[1]));
        }

        return array_slice($candidates, 0, self::MAX_TOTAL_CANDIDATES);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchAllSources(string $keyword): array
    {
        $candidates = [];

        foreach ($this->sources as $source) {
            $candidates = array_merge($candidates, $this->safeSearch($source, $keyword));
        }

        return $candidates;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function safeSearch(object $source, string $keyword): array
    {
        try {
            return $source->search($keyword, self::MAX_CANDIDATES_PER_SOURCE);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
