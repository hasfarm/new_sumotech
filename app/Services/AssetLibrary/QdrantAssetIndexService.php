<?php

namespace App\Services\AssetLibrary;

use App\Models\VideoAssetLibrary;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors App\Services\QdrantChunkIndexService's pattern (HTTP calls, collection
 * ensure/create, embedding via OpenAI) but for a separate collection indexing the
 * reusable video-asset library instead of audiobook text chunks. Qdrant point id ==
 * VideoAssetLibrary row id (no separate mapping column needed).
 */
class QdrantAssetIndexService
{
    private ?int $ensuredVectorSize = null;
    private bool $collectionChecked = false;

    public function collectionName(): string
    {
        return (string) config('services.qdrant.asset_collection', 'video_asset_library');
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array{id:mixed,score:float,payload:array<string,mixed>}>
     */
    public function searchAssets(string $queryText, int $limit = 8, array $filters = []): array
    {
        $queryText = trim($queryText);
        if ($queryText === '') {
            return [];
        }

        if (!$this->collectionExists($this->collectionName())) {
            return [];
        }

        $embedding = $this->createEmbedding($queryText);

        $payload = [
            'vector' => $embedding,
            'limit' => max(1, min(30, $limit)),
            'with_payload' => true,
            'with_vector' => false,
        ];

        if (!empty($filters)) {
            $must = [];
            foreach ($filters as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $must[] = ['key' => (string) $key, 'match' => ['value' => $value]];
            }
            if (!empty($must)) {
                $payload['filter'] = ['must' => $must];
            }
        }

        $response = $this->qdrantRequest(
            'POST',
            '/collections/' . rawurlencode($this->collectionName()) . '/points/search',
            $payload
        );

        if (!$response->successful()) {
            throw new RuntimeException('Failed to search video asset library in Qdrant: ' . $this->extractError($response));
        }

        $results = data_get($response->json(), 'result', []);
        if (!is_array($results)) {
            return [];
        }

        $mapped = [];
        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mapped[] = [
                'id' => $item['id'] ?? null,
                'score' => isset($item['score']) ? (float) $item['score'] : 0.0,
                'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : [],
            ];
        }

        return $mapped;
    }

    /**
     * @return array{status:string, vector_size?:int}
     */
    public function indexAsset(VideoAssetLibrary $asset): array
    {
        $text = trim((string) $asset->description);
        if ($text === '') {
            return ['status' => 'skipped'];
        }

        $embedding = $this->createEmbedding($text);
        $vectorSize = count($embedding);

        if ($vectorSize <= 0) {
            throw new RuntimeException('Embedding vector is empty.');
        }

        $this->ensureCollection($vectorSize);
        $this->upsertPoint($asset, $embedding);

        return ['status' => 'indexed', 'vector_size' => $vectorSize];
    }

    private function ensureCollection(int $vectorSize): void
    {
        if ($this->collectionChecked && $this->ensuredVectorSize === $vectorSize) {
            return;
        }

        $collection = $this->collectionName();
        if (!$this->collectionExists($collection)) {
            $this->createCollection($collection, $vectorSize);
        }

        $this->collectionChecked = true;
        $this->ensuredVectorSize = $vectorSize;
    }

    private function collectionExists(string $collection): bool
    {
        $response = $this->qdrantRequest('GET', '/collections/' . rawurlencode($collection));

        if ($response->status() === 404) {
            return false;
        }

        if (!$response->successful()) {
            throw new RuntimeException('Failed to check Qdrant collection: ' . $this->extractError($response));
        }

        return true;
    }

    private function createCollection(string $collection, int $vectorSize): void
    {
        $payload = [
            'vectors' => [
                'size' => $vectorSize,
                'distance' => (string) config('services.qdrant.distance', 'Cosine'),
            ],
        ];

        $response = $this->qdrantRequest('PUT', '/collections/' . rawurlencode($collection), $payload);
        if (!$response->successful()) {
            throw new RuntimeException('Failed to create Qdrant asset collection: ' . $this->extractError($response));
        }
    }

    /**
     * @param array<int,float> $embedding
     */
    private function upsertPoint(VideoAssetLibrary $asset, array $embedding): void
    {
        $payload = [
            'points' => [
                [
                    'id' => (int) $asset->id,
                    'vector' => $embedding,
                    'payload' => [
                        'asset_id' => (int) $asset->id,
                        'media_type' => (string) $asset->media_type,
                        'origin_source' => (string) $asset->origin_source,
                        'culture_context' => (string) ($asset->culture_context ?? ''),
                        'description' => (string) $asset->description,
                        'updated_at' => optional($asset->updated_at)->toIso8601String(),
                    ],
                ],
            ],
        ];

        $response = $this->qdrantRequest(
            'PUT',
            '/collections/' . rawurlencode($this->collectionName()) . '/points?wait=true',
            $payload
        );

        if (!$response->successful()) {
            throw new RuntimeException('Failed to upsert asset to Qdrant: ' . $this->extractError($response));
        }
    }

    /**
     * @return array<int,float>
     */
    private function createEmbedding(string $text): array
    {
        $apiKey = (string) config('services.openai.api_key', '');
        if (!$apiKey) {
            throw new RuntimeException('Missing OPENAI_API_KEY for embeddings.');
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.embedding_model', 'text-embedding-3-small');

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout((int) config('services.qdrant.openai_timeout', 60))
            ->post($baseUrl . '/embeddings', [
                'model' => $model,
                'input' => $text,
                'encoding_format' => 'float',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Failed to create embedding: ' . $this->extractError($response));
        }

        $embedding = data_get($response->json(), 'data.0.embedding');
        if (!is_array($embedding) || empty($embedding)) {
            throw new RuntimeException('OpenAI embedding response is missing vector data.');
        }

        return array_map(static fn($v) => (float) $v, $embedding);
    }

    private function qdrantRequest(string $method, string $path, array $json = []): Response
    {
        $url = rtrim((string) config('services.qdrant.url', ''), '/') . $path;
        $request = Http::acceptJson()->timeout((int) config('services.qdrant.timeout', 30));

        $apiKey = (string) config('services.qdrant.api_key', '');
        if ($apiKey !== '') {
            $request = $request->withHeaders(['api-key' => $apiKey]);
        }

        $options = [];
        if (!empty($json)) {
            $options['json'] = $json;
        }

        return $request->send(strtoupper($method), $url, $options);
    }

    private function extractError(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $error = data_get($json, 'status.error')
                ?? data_get($json, 'result.error')
                ?? data_get($json, 'error')
                ?? data_get($json, 'message');

            if (is_string($error) && trim($error) !== '') {
                return $error . ' (HTTP ' . $response->status() . ')';
            }
        }

        $body = trim((string) $response->body());
        if ($body !== '') {
            return $body . ' (HTTP ' . $response->status() . ')';
        }

        return 'HTTP ' . $response->status();
    }
}
