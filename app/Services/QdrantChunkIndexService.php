<?php

namespace App\Services;

use App\Models\AudioBookChapterChunk;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class QdrantChunkIndexService
{
    private ?int $ensuredVectorSize = null;
    private bool $collectionChecked = false;

    public function missingRequiredConfig(): array
    {
        $missing = [];

        if (!$this->openAiApiKey()) {
            $missing[] = 'OPENAI_API_KEY';
        }

        if (!$this->qdrantUrl()) {
            $missing[] = 'QDRANT_URL';
        }

        return $missing;
    }

    public function collectionName(): string
    {
        return (string) config('services.qdrant.collection', 'audiobook_chapter_chunks');
    }

    /**
     * Search relevant chunks from Qdrant using semantic vector search.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array{id:mixed, score:float, payload:array<string,mixed>}>
     */
    public function searchChunks(string $query, int $limit = 8, array $filters = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $embedding = $this->createEmbedding($query);

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
                $must[] = [
                    'key' => (string) $key,
                    'match' => ['value' => $value],
                ];
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
            throw new RuntimeException('Failed to search chunks in Qdrant: ' . $this->extractError($response));
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
     * Index one chapter chunk into Qdrant.
     *
     * @return array{status:string, reason?:string, vector_size?:int}
     */
    public function indexChunk(AudioBookChapterChunk $chunk, bool $forceRecreateCollection = false): array
    {
        $text = trim((string) $chunk->text_content);
        if ($text === '') {
            return [
                'status' => 'skipped',
                'reason' => 'empty_text',
            ];
        }

        $chunk->loadMissing('chapter:id,audio_book_id,chapter_number,title');

        $embedding = $this->createEmbedding($text);
        $vectorSize = count($embedding);

        if ($vectorSize <= 0) {
            throw new RuntimeException('Embedding vector is empty.');
        }

        $this->ensureCollection($vectorSize, $forceRecreateCollection);
        $this->upsertPoint($chunk, $embedding);

        return [
            'status' => 'indexed',
            'vector_size' => $vectorSize,
        ];
    }

    private function ensureCollection(int $vectorSize, bool $forceRecreateCollection = false): void
    {
        if ($this->collectionChecked && $this->ensuredVectorSize === $vectorSize && !$forceRecreateCollection) {
            return;
        }

        $collection = $this->collectionName();
        $exists = $this->collectionExists($collection);

        if ($exists && $forceRecreateCollection) {
            $this->deleteCollection($collection);
            $exists = false;
        }

        if (!$exists) {
            $this->createCollection($collection, $vectorSize);
        } else {
            $existingSize = $this->fetchCollectionVectorSize($collection);
            if ($existingSize !== null && $existingSize !== $vectorSize) {
                throw new RuntimeException(sprintf(
                    'Qdrant collection "%s" has vector size %d but embedding model returned %d. Change model or recreate collection.',
                    $collection,
                    $existingSize,
                    $vectorSize
                ));
            }
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

    private function fetchCollectionVectorSize(string $collection): ?int
    {
        $response = $this->qdrantRequest('GET', '/collections/' . rawurlencode($collection));
        if (!$response->successful()) {
            throw new RuntimeException('Failed to read Qdrant collection details: ' . $this->extractError($response));
        }

        $vectors = data_get($response->json(), 'result.config.params.vectors');
        if (is_array($vectors) && isset($vectors['size'])) {
            return (int) $vectors['size'];
        }

        if (is_array($vectors)) {
            foreach ($vectors as $vectorConfig) {
                if (is_array($vectorConfig) && isset($vectorConfig['size'])) {
                    return (int) $vectorConfig['size'];
                }
            }
        }

        return null;
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
            throw new RuntimeException('Failed to create Qdrant collection: ' . $this->extractError($response));
        }
    }

    private function deleteCollection(string $collection): void
    {
        $response = $this->qdrantRequest('DELETE', '/collections/' . rawurlencode($collection));

        if ($response->status() === 404) {
            return;
        }

        if (!$response->successful()) {
            throw new RuntimeException('Failed to delete Qdrant collection: ' . $this->extractError($response));
        }
    }

    /**
     * @param array<int,float> $embedding
     */
    private function upsertPoint(AudioBookChapterChunk $chunk, array $embedding): void
    {
        $chapter = $chunk->chapter;
        $textContent = (string) $chunk->text_content;

        $bookId = $chunk->book_id ? (int) $chunk->book_id : ($chapter ? (int) $chapter->audio_book_id : null);
        $chapterId = $chunk->chapter_id ? (int) $chunk->chapter_id : (int) $chunk->audiobook_chapter_id;
        $chunkIndex = $chunk->chunk_index ? (int) $chunk->chunk_index : (int) $chunk->chunk_number;

        $characterTags = is_array($chunk->character_tags) ? $chunk->character_tags : [];
        $sceneType = is_array($chunk->scene_type) ? array_values(array_unique($chunk->scene_type)) : [];
        $topicTags = is_array($chunk->topic_tags) ? $chunk->topic_tags : [];
        $importanceScore = is_numeric($chunk->importance_score) ? (float) $chunk->importance_score : null;

        // Fallback for legacy chunks that were not enriched into MySQL yet.
        if (empty($characterTags) || empty($sceneType) || empty($topicTags) || $importanceScore === null) {
            $fallbackCharacters = $this->extractCharacterTags($textContent);
            $fallbackScene = $this->inferSceneTypes($textContent);
            $fallbackTopics = $this->extractTopicTags($textContent);
            $fallbackImportance = $this->estimateImportanceScore($textContent, $fallbackScene, $fallbackCharacters, $fallbackTopics);

            if (empty($characterTags)) {
                $characterTags = $fallbackCharacters;
            }

            if (empty($sceneType)) {
                $sceneType = $fallbackScene;
            }

            if (empty($topicTags)) {
                $topicTags = $fallbackTopics;
            }

            if ($importanceScore === null) {
                $importanceScore = $fallbackImportance;
            }
        }

        $payload = [
            'points' => [
                [
                    'id' => (int) $chunk->id,
                    'vector' => $embedding,
                    'payload' => [
                        'source_table' => 'audiobook_chapter_chunks',
                        'chunk_id' => (int) $chunk->id,
                        'book_id' => $bookId,
                        'audio_book_id' => $bookId,
                        'chapter_id' => $chapterId,
                        'audiobook_chapter_id' => $chapterId,
                        'chunk_index' => $chunkIndex,
                        'chapter_number' => $chapter ? (int) $chapter->chapter_number : null,
                        'chapter_title' => $chapter?->title,
                        'chunk_number' => $chunkIndex,
                        'character_tags' => $characterTags,
                        'scene_type' => array_values(array_unique($sceneType)),
                        'topic_tags' => $topicTags,
                        'importance_score' => $importanceScore,
                        'text_content' => $textContent,
                        'status' => (string) $chunk->status,
                        'updated_at' => optional($chunk->updated_at)->toIso8601String(),
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
            throw new RuntimeException('Failed to upsert chunk to Qdrant: ' . $this->extractError($response));
        }
    }

    /**
     * @return array<int,string>
     */
    private function extractCharacterTags(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $stopWords = [
            'Anh', 'Chi', 'Co', 'Ong', 'Ba', 'Toi', 'No', 'Ho', 'Nguoi', 'Mot', 'Hai',
            'Ba', 'Bon', 'Nam', 'Khi', 'Sau', 'Truoc', 'Trong', 'Ngoai', 'Chuong', 'Phan',
        ];
        $stopLookup = array_fill_keys(array_map('mb_strtolower', $stopWords), true);

        $counts = [];
        if (preg_match_all('/\b[\p{Lu}][\p{L}\p{Mn}\p{Pd}]{1,}(?:\s+[\p{Lu}][\p{L}\p{Mn}\p{Pd}]{1,}){0,2}\b/u', $text, $matches)) {
            foreach ($matches[0] as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }

                $key = mb_strtolower($name);
                if (isset($stopLookup[$key])) {
                    continue;
                }

                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        if (empty($counts)) {
            return [];
        }

        arsort($counts);
        return array_slice(array_keys($counts), 0, 8);
    }

    /**
     * @return array<int,string>
     */
    private function inferSceneTypes(string $text): array
    {
        $content = mb_strtolower($text);

        $sceneKeywords = [
            'dialogue' => ['noi', 'hoi', 'dap', 'tra loi', 'doi thoai'],
            'battle' => ['danh', 'chem', 'duoi', 'chay', 'tan cong', 'giao tranh'],
            'betrayal' => ['phan boi', 'tro mat', 'lua doi', 'phu bac'],
            'exile' => ['luu day', 'truat xuat', 'bi duoi', 'xa xu'],
            'turning_point' => ['bat ngo', 'buoc ngoat', 'bien co', 'thay doi'],
        ];

        $matched = [];
        foreach ($sceneKeywords as $scene => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_stripos($content, $keyword) !== false) {
                    $matched[] = $scene;
                    break;
                }
            }
        }

        $matched = array_values(array_unique($matched));

        return !empty($matched) ? $matched : ['general'];
    }

    /**
     * @return array<int,string>
     */
    private function extractTopicTags(string $text): array
    {
        $content = mb_strtolower($text);

        $topics = [
            'hanh_trinh_nhan_vat' => ['hanh trinh', 'len duong', 'vuot qua', 'truong thanh'],
            'xung_dot_noi_tam' => ['do du', 'day dut', 'noi tam', 'phan van', 'mau thuan'],
            'buoc_ngoat' => ['bat ngo', 'buoc ngoat', 'bien co', 'thay doi'],
            'thong_diep' => ['thong diep', 'bai hoc', 'gia tri', 'y nghia'],
            'quan_he_nhan_vat' => ['ban be', 'ke thu', 'su phu', 'dong doi', 'gia dinh'],
        ];

        $result = [];
        foreach ($topics as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_stripos($content, $keyword) !== false) {
                    $result[] = $topic;
                    break;
                }
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Estimate chunk importance score in range [0, 1].
     *
     * @param array<int,string> $sceneTypes
     * @param array<int,string> $characterTags
     * @param array<int,string> $topicTags
     */
    private function estimateImportanceScore(string $text, array $sceneTypes, array $characterTags, array $topicTags): float
    {
        $lengthScore = min(1.0, mb_strlen($text) / 1200);
        $characterScore = min(1.0, count($characterTags) / 5);
        $topicScore = min(1.0, count($topicTags) / 4);
        $sceneBoost = (!empty($sceneTypes) && !(count($sceneTypes) === 1 && ($sceneTypes[0] ?? '') === 'general')) ? 0.15 : 0.0;

        $score = ($lengthScore * 0.45) + ($characterScore * 0.25) + ($topicScore * 0.15) + $sceneBoost;

        return round(max(0.0, min(1.0, $score)), 4);
    }

    /**
     * @return array<int,float>
     */
    private function createEmbedding(string $text): array
    {
        $apiKey = $this->openAiApiKey();
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
        $url = rtrim($this->qdrantUrl(), '/') . $path;
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

    private function openAiApiKey(): string
    {
        return (string) config('services.openai.api_key', '');
    }

    private function qdrantUrl(): string
    {
        return (string) config('services.qdrant.url', 'http://127.0.0.1:6333');
    }
}
