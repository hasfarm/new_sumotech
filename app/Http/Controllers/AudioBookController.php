<?php

namespace App\Http\Controllers;

use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use App\Models\ChannelSpeaker;
use App\Models\YoutubeChannel;
use App\Services\BookScrapers\Docsach24Scraper;
use App\Services\BookScrapers\NhaSachMienPhiScraper;
use App\Services\BookScrapers\VietNamThuQuanScraper;
use App\Services\TTSService;
use App\Services\GeminiImageService;
use App\Services\KlingAIService;
use App\Services\DIDLipsyncService;
use App\Services\LipsyncSegmentManager;
use App\Services\VideoCompositionService;
use App\Services\DescriptionVideoService;
use App\Jobs\GenerateDescriptionVideoJob;
use App\Jobs\GenerateFullBookVideoJob;
use App\Jobs\GenerateBatchVideoJob;
use App\Jobs\PublishYoutubeJob;
use App\Jobs\GenerateThumbnailJob;
use App\Jobs\GenerateDescriptionAudioJob;
use App\Jobs\GenerateBookReviewVideoJob;
use App\Services\BookReviewVideoService;
use App\Models\AudioBookVideoSegment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AudioBookController extends Controller
{
    protected TTSService $ttsService;
    protected GeminiImageService $imageService;
    protected KlingAIService $klingService;
    protected DIDLipsyncService $lipsyncService;
    protected LipsyncSegmentManager $segmentManager;
    protected VideoCompositionService $compositionService;
    protected DescriptionVideoService $descVideoService;

    public function __construct(
        TTSService $ttsService,
        GeminiImageService $imageService,
        KlingAIService $klingService,
        DIDLipsyncService $lipsyncService,
        LipsyncSegmentManager $segmentManager,
        VideoCompositionService $compositionService,
        DescriptionVideoService $descVideoService
    ) {
        $this->ttsService = $ttsService;
        $this->imageService = $imageService;
        $this->klingService = $klingService;
        $this->lipsyncService = $lipsyncService;
        $this->segmentManager = $segmentManager;
        $this->compositionService = $compositionService;
        $this->descVideoService = $descVideoService;
    }

    protected function getScrapeSources(): array
    {
        return [
            'nhasachmienphi' => [
                'label' => 'nhasachmienphi.com',
                'domains' => ['nhasachmienphi.com', 'www.nhasachmienphi.com']
            ],
            'docsach24' => [
                'label' => 'docsach24.co',
                'domains' => ['docsach24.co', 'www.docsach24.co']
            ],
            'vietnamthuquan' => [
                'label' => 'vietnamthuquan.eu',
                'domains' => ['vietnamthuquan.eu', 'www.vietnamthuquan.eu', 'vnthuquan.net', 'www.vnthuquan.net']
            ]
        ];
    }

    protected function detectBookSource(string $bookUrl, array $scrapeSources): ?string
    {
        $host = parse_url($bookUrl, PHP_URL_HOST);
        if (!$host) {
            return null;
        }

        foreach ($scrapeSources as $source => $config) {
            if (in_array($host, $config['domains'], true)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Display a listing of the resource.
     * Redirect to YouTube channels page since audiobooks are organized by channel.
     */
    public function index()
    {
        return redirect()->route('youtube-channels.index')
            ->with('info', 'Sách audio được tổ chức theo kênh YouTube. Vui lòng chọn kênh để xem danh sách sách.');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $youtubeChannels = YoutubeChannel::all();
        $authorBooks = collect();
        $authorName = trim((string) old('author', request('author')));
        if ($authorName !== '') {
            $authorBooks = AudioBook::query()
                ->where('author', $authorName)
                ->orderBy('title')
                ->get();
        }
        $categoryOptions = AudioBook::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $scrapeSources = $this->getScrapeSources();

        return view('audiobooks.create', compact('youtubeChannels', 'authorBooks', 'categoryOptions', 'scrapeSources'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'youtube_channel_id' => 'required|exists:youtube_channels,id',
            'title' => 'required|string|max:255',
            'book_type' => 'nullable|string|max:100',
            'book_type_custom' => 'nullable|string|max:100',
            'author' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'category_custom' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'cover_image' => 'nullable|image|max:2048',
            'cover_image_url' => 'nullable|url',
            'language' => 'required|string|in:vi,en,es,fr,de,ja,ko',
            'book_url' => 'nullable|url',
            'book_source' => 'nullable|string'
        ]);

        if (($data['book_type'] ?? '') === 'custom') {
            $customBookType = trim((string) ($data['book_type_custom'] ?? ''));
            $data['book_type'] = $customBookType !== '' ? $customBookType : null;
        }
        if (($data['category'] ?? '') === 'custom') {
            $customCategory = trim((string) ($data['category_custom'] ?? ''));
            $data['category'] = $customCategory !== '' ? $customCategory : null;
        }
        if (empty($data['book_type'])) {
            $data['book_type'] = 'sach';
        }

        // Handle cover image - prioritize file upload, then URL
        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')->store('audiobooks', 'public');
            $data['cover_image'] = $path;
        } elseif (!empty($data['cover_image_url'])) {
            // Download image from URL
            try {
                $imageUrl = $data['cover_image_url'];
                $imageContent = Http::timeout(30)->get($imageUrl)->body();

                // Generate filename from URL
                $extension = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $filename = 'audiobooks/' . uniqid() . '.' . $extension;

                Storage::disk('public')->put($filename, $imageContent);
                $data['cover_image'] = $filename;
            } catch (\Exception $e) {
                // If download fails, just log it and continue
                Log::warning('Failed to download cover image: ' . $e->getMessage());
            }
        }

        // Remove temporary fields from data before creating audiobook
        $bookUrl = $data['book_url'] ?? null;
        $bookSource = $data['book_source'] ?? null;
        unset($data['book_url'], $data['book_source'], $data['cover_image_url']);

        $audioBook = AudioBook::create($data);

        // Automatically scrape chapters if URL is provided
        if ($bookUrl && $bookSource) {
            try {
                // Use appropriate scraper
                switch ($bookSource) {
                    case 'docsach24':
                        $scraper = new Docsach24Scraper($bookUrl);
                        break;
                    case 'nhasachmienphi':
                    default:
                        $scraper = new NhaSachMienPhiScraper($bookUrl);
                        break;
                }

                $result = $scraper->scrape();

                if (isset($result['success']) && $result['success']) {
                    // Update total chapters
                    $audioBook->update(['total_chapters' => count($result['chapters'])]);

                    // Import chapters
                    $chapterNumber = 1;
                    foreach ($result['chapters'] as $chapter) {
                        $content = $scraper->scrapeChapterContent($chapter['url']);
                        if ($content) {
                            AudioBookChapter::create([
                                'audio_book_id' => $audioBook->id,
                                'chapter_number' => $chapterNumber,
                                'title' => $chapter['title'],
                                'content' => $content,
                                'tts_voice' => $audioBook->language == 'vi' ? 'vi-VN-HoaiMyNeural' : 'en-US-AriaNeural',
                                'tts_speed' => 1.0,
                                'status' => 'pending'
                            ]);
                            $chapterNumber++;
                        }
                    }

                    $importedCount = $chapterNumber - 1;
                    return redirect()->route('audiobooks.show', $audioBook)
                        ->with('success', "Đã tạo sách thành công và import {$importedCount} chương!");
                }
            } catch (\Exception $e) {
                // If scraping fails, still redirect to show page but with warning
                return redirect()->route('audiobooks.show', $audioBook)
                    ->with('warning', 'Đã tạo sách thành công nhưng không thể tự động import chương. Lỗi: ' . $e->getMessage());
            }
        }

        return redirect()->route('audiobooks.show', $audioBook)->with('success', 'Sách âm thanh đã được tạo');
    }

    /**
     * Display the specified resource.
     */
    public function show(AudioBook $audioBook)
    {
        $audioBook->load(['youtubeChannel', 'chapters', 'speaker', 'videoSegments']);

        // Get available speakers from the same YouTube channel
        $speakers = [];
        if ($audioBook->youtube_channel_id) {
            $speakers = ChannelSpeaker::where('youtube_channel_id', $audioBook->youtube_channel_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        // Calculate storage sizes
        $bookStorageSize = $this->calculateDirectorySize(storage_path('app/public/books/' . $audioBook->id));

        $channelStorageSize = 0;
        $channelBookSizes = [];
        if ($audioBook->youtube_channel_id) {
            $channelBooks = AudioBook::where('youtube_channel_id', $audioBook->youtube_channel_id)->get(['id', 'title']);
            foreach ($channelBooks as $book) {
                $size = $this->calculateDirectorySize(storage_path('app/public/books/' . $book->id));
                $channelStorageSize += $size;
                if ($size > 0) {
                    $channelBookSizes[] = ['id' => $book->id, 'title' => $book->title, 'size' => $size];
                }
            }
            // Sort by size descending
            usort($channelBookSizes, fn($a, $b) => $b['size'] <=> $a['size']);
        }

        $scrapeSources = $this->getScrapeSources();

        return view('audiobooks.show', compact(
            'audioBook',
            'speakers',
            'scrapeSources',
            'bookStorageSize',
            'channelStorageSize',
            'channelBookSizes'
        ));
    }

    private function calculateDirectorySize(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AudioBook $audioBook)
    {
        $youtubeChannels = YoutubeChannel::all();
        $authorBooks = collect();

        if (!empty($audioBook->author)) {
            $authorBooks = AudioBook::query()
                ->where('author', $audioBook->author)
                ->where('id', '!=', $audioBook->id)
                ->orderBy('title')
                ->get();
        }

        $categoryOptions = AudioBook::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('audiobooks.edit', compact('audioBook', 'youtubeChannels', 'authorBooks', 'categoryOptions'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, AudioBook $audioBook)
    {
        $data = $request->validate([
            'youtube_channel_id' => 'required|exists:youtube_channels,id',
            'title' => 'required|string|max:255',
            'book_type' => 'nullable|string|max:100',
            'book_type_custom' => 'nullable|string|max:100',
            'author' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'category_custom' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'cover_image' => 'nullable|image|max:2048',
            'language' => 'required|string|in:vi,en,es,fr,de,ja,ko'
        ]);

        if (($data['book_type'] ?? '') === 'custom') {
            $customBookType = trim((string) ($data['book_type_custom'] ?? ''));
            $data['book_type'] = $customBookType !== '' ? $customBookType : null;
        }
        if (($data['category'] ?? '') === 'custom') {
            $customCategory = trim((string) ($data['category_custom'] ?? ''));
            $data['category'] = $customCategory !== '' ? $customCategory : null;
        }
        if (empty($data['book_type'])) {
            $data['book_type'] = 'sach';
        }

        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')->store('audiobooks', 'public');
            $data['cover_image'] = $path;
        }

        $audioBook->update($data);
        return redirect()->route('audiobooks.show', $audioBook)->with('success', 'Sách âm thanh đã được cập nhật');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AudioBook $audioBook)
    {
        $channelId = $audioBook->youtube_channel_id;
        $audioBook->delete();

        // Redirect back to the channel page if exists, otherwise to channels list
        if ($channelId) {
            return redirect()->route('youtube-channels.show', $channelId)
                ->with('success', 'Đã xóa sách thành công');
        }

        return redirect()->route('youtube-channels.index')
            ->with('success', 'Đã xóa sách thành công');
    }

    /**
     * Scrape chapters from book URL
     */
    public function scrapeChapters(Request $request)
    {
        $request->validate([
            'book_url' => 'required|url',
            'book_source' => 'required|string',
            'audio_book_id' => 'required|exists:audio_books,id'
        ]);

        $audioBook = AudioBook::findOrFail($request->input('audio_book_id'));
        $bookUrl = $request->input('book_url');
        $bookSource = $request->input('book_source');
        $scrapeSources = $this->getScrapeSources();

        if (!isset($scrapeSources[$bookSource])) {
            return response()->json(['error' => 'Nguồn scrape không hợp lệ'], 400);
        }

        $host = parse_url($bookUrl, PHP_URL_HOST);
        if (!$host || !in_array($host, $scrapeSources[$bookSource]['domains'], true)) {
            return response()->json(['error' => 'URL không thuộc nguồn đã chọn'], 400);
        }

        try {
            // Detect website and use appropriate scraper
            switch ($bookSource) {
                case 'docsach24':
                    $scraper = new Docsach24Scraper($bookUrl);
                    break;
                case 'vietnamthuquan':
                    $scraper = new VietNamThuQuanScraper($bookUrl);
                    break;
                case 'nhasachmienphi':
                default:
                    $scraper = new NhaSachMienPhiScraper($bookUrl);
                    break;
            }
            $result = $scraper->scrape();

            if (isset($result['error'])) {
                return response()->json($result, 400);
            }

            // Update audiobook with scraped info (author, category, description)
            $updateData = ['total_chapters' => count($result['chapters'])];

            if (!empty($result['author']) && empty($audioBook->author)) {
                $updateData['author'] = $result['author'];
            }
            if (!empty($result['category']) && empty($audioBook->category)) {
                $updateData['category'] = $result['category'];
            }
            if (!empty($result['description']) && empty($audioBook->description)) {
                $updateData['description'] = $result['description'];
            }
            if (!empty($result['cover_image']) && empty($audioBook->cover_image)) {
                $updateData['cover_image'] = $result['cover_image'];
            }

            $audioBook->update($updateData);

            // Get existing chapter titles to avoid duplicates
            $existingChapters = $audioBook->chapters()->pluck('title')->toArray();
            $existingChapterNumbers = $audioBook->chapters()->pluck('chapter_number')->toArray();

            // Store only new chapters
            $chapters = $result['chapters'];
            $newChaptersCount = 0;
            $skippedCount = 0;

            foreach ($chapters as $index => $chapter) {
                $chapterNumber = $index + 1;

                // Check if chapter already exists (by title or chapter number)
                $titleExists = in_array($chapter['title'], $existingChapters);
                $numberExists = in_array($chapterNumber, $existingChapterNumbers);

                if ($titleExists || $numberExists) {
                    $skippedCount++;
                    continue; // Skip existing chapters
                }

                // Get chapter content
                $content = $scraper->scrapeChapterContent($chapter['url']);

                if ($content) {
                    AudioBookChapter::create([
                        'audio_book_id' => $audioBook->id,
                        'chapter_number' => $chapterNumber,
                        'title' => $chapter['title'],
                        'content' => $content,
                        'tts_voice' => $audioBook->language == 'vi' ? 'vi-VN-HoaiMyNeural' : 'en-US-AriaNeural',
                        'tts_speed' => 1.0,
                        'status' => 'pending'
                    ]);
                    $newChaptersCount++;
                }
            }

            // Re-order chapters by chapter_number to ensure proper sequence
            // This updates chapter_number based on current ordering
            $allChapters = $audioBook->chapters()->orderBy('chapter_number')->get();
            foreach ($allChapters as $idx => $ch) {
                if ($ch->chapter_number !== $idx + 1) {
                    $ch->update(['chapter_number' => $idx + 1]);
                }
            }

            // Update total chapters count
            $audioBook->update(['total_chapters' => $audioBook->chapters()->count()]);

            $message = $newChaptersCount > 0
                ? "Đã import {$newChaptersCount} chương mới"
                : "Không có chương mới để import";

            if ($skippedCount > 0) {
                $message .= " (bỏ qua {$skippedCount} chương đã tồn tại)";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'chapters_imported' => $newChaptersCount,
                'chapters_skipped' => $skippedCount,
                'total_chapters' => $audioBook->chapters()->count(),
                'book_info' => [
                    'author' => $result['author'] ?? null,
                    'category' => $result['category'] ?? null,
                    'has_description' => !empty($result['description'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Lỗi: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Fetch book metadata from URL (for create page auto-fill)
     */
    public function fetchBookMetadata(Request $request)
    {
        $request->validate([
            'book_url' => 'required|url'
        ]);

        $bookUrl = $request->input('book_url');
        $scrapeSources = $this->getScrapeSources();

        $bookSource = $this->detectBookSource($bookUrl, $scrapeSources);

        if (!$bookSource) {
            $supportedDomains = [];
            foreach ($scrapeSources as $config) {
                $supportedDomains = array_merge($supportedDomains, $config['domains']);
            }
            return response()->json([
                'error' => 'URL không thuộc nguồn được hỗ trợ. Chỉ hỗ trợ: ' . implode(', ', $supportedDomains)
            ], 400);
        }

        try {
            // Use appropriate scraper based on detected source
            switch ($bookSource) {
                case 'docsach24':
                    $scraper = new Docsach24Scraper($bookUrl);
                    break;
                case 'vietnamthuquan':
                    $scraper = new VietNamThuQuanScraper($bookUrl);
                    break;
                case 'nhasachmienphi':
                default:
                    $scraper = new NhaSachMienPhiScraper($bookUrl);
                    break;
            }

            $result = $scraper->scrape();

            if (isset($result['error'])) {
                return response()->json($result, 400);
            }

            // Return book metadata for form auto-fill
            return response()->json([
                'success' => true,
                'title' => $result['title'] ?? '',
                'author' => $result['author'] ?? '',
                'category' => $result['category'] ?? '',
                'description' => $result['description'] ?? '',
                'cover_image' => $result['cover_image'] ?? '',
                'total_chapters' => $result['total_chapters'] ?? 0,
                'book_source' => $bookSource
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Lỗi khi lấy thông tin sách: ' . $e->getMessage()], 400);
        }
    }

    public function bulkCreate(Request $request)
    {
        $data = $request->validate([
            'youtube_channel_id' => 'required|exists:youtube_channels,id',
            'language' => 'required|string|in:vi,en,es,fr,de,ja,ko',
            'book_urls' => 'required|array|min:1',
            'book_urls.*' => 'required|url'
        ]);

        $scrapeSources = $this->getScrapeSources();
        $urls = array_values(array_unique(array_filter(array_map('trim', $data['book_urls']))));

        if (count($urls) === 0) {
            return response()->json(['error' => 'Danh sách URL trống'], 422);
        }

        $created = [];
        $errors = [];

        foreach ($urls as $bookUrl) {
            $bookSource = $this->detectBookSource($bookUrl, $scrapeSources);
            if (!$bookSource) {
                $errors[] = [
                    'url' => $bookUrl,
                    'error' => 'URL không thuộc nguồn được hỗ trợ'
                ];
                continue;
            }

            try {
                switch ($bookSource) {
                    case 'docsach24':
                        $scraper = new Docsach24Scraper($bookUrl);
                        break;
                    case 'vietnamthuquan':
                        $scraper = new VietNamThuQuanScraper($bookUrl);
                        break;
                    case 'nhasachmienphi':
                    default:
                        $scraper = new NhaSachMienPhiScraper($bookUrl);
                        break;
                }

                $result = $scraper->scrape();
                if (isset($result['error'])) {
                    $errors[] = [
                        'url' => $bookUrl,
                        'error' => $result['error']
                    ];
                    continue;
                }

                $audioBook = AudioBook::create([
                    'youtube_channel_id' => $data['youtube_channel_id'],
                    'title' => $result['title'] ?? 'Sách không xác định',
                    'book_type' => 'sach',
                    'author' => $result['author'] ?? null,
                    'category' => $result['category'] ?? null,
                    'description' => $result['description'] ?? null,
                    'language' => $data['language'],
                    'total_chapters' => 0
                ]);

                if (!empty($result['cover_image'])) {
                    try {
                        $imageUrl = $result['cover_image'];
                        $imageContent = Http::timeout(30)->get($imageUrl)->body();
                        $extension = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                        $filename = 'audiobooks/' . uniqid() . '.' . $extension;
                        Storage::disk('public')->put($filename, $imageContent);
                        $audioBook->update(['cover_image' => $filename]);
                    } catch (\Exception $e) {
                        $audioBook->update(['cover_image' => $result['cover_image']]);
                    }
                }

                $chapterNumber = 1;
                foreach ($result['chapters'] as $chapter) {
                    $content = $scraper->scrapeChapterContent($chapter['url']);
                    if ($content) {
                        AudioBookChapter::create([
                            'audio_book_id' => $audioBook->id,
                            'chapter_number' => $chapterNumber,
                            'title' => $chapter['title'],
                            'content' => $content,
                            'tts_voice' => $audioBook->language == 'vi' ? 'vi-VN-HoaiMyNeural' : 'en-US-AriaNeural',
                            'tts_speed' => 1.0,
                            'status' => 'pending'
                        ]);
                        $chapterNumber++;
                    }
                }

                $importedCount = $chapterNumber - 1;
                $audioBook->update(['total_chapters' => $importedCount]);

                $created[] = [
                    'id' => $audioBook->id,
                    'title' => $audioBook->title,
                    'url' => $bookUrl,
                    'chapters' => $importedCount
                ];
            } catch (\Exception $e) {
                $errors[] = [
                    'url' => $bookUrl,
                    'error' => 'Lỗi tạo sách: ' . $e->getMessage()
                ];
            }
        }

        return response()->json([
            'success' => true,
            'total' => count($urls),
            'created' => $created,
            'errors' => $errors
        ]);
    }

    /**
     * Update TTS settings for audiobook
     */
    public function updateTtsSettings(Request $request, AudioBook $audioBook)
    {
        $data = $request->validate([
            'tts_provider' => 'nullable|string|in:openai,gemini,microsoft,vbee',
            'tts_voice_gender' => 'nullable|string|in:male,female',
            'tts_voice_name' => 'nullable|string|max:100',
            'tts_style_instruction' => 'nullable|string|max:1000',
            'tts_speed' => 'nullable|numeric|between:0.5,2.0',
            'pause_between_chunks' => 'nullable|numeric|between:0,5'
        ]);

        $audioBook->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Đã lưu cấu hình TTS'
        ]);
    }

    /**
     * Find and replace text across all chapters of an audiobook
     */
    public function findReplace(Request $request, AudioBook $audioBook)
    {
        $data = $request->validate([
            'search'        => 'required|string|min:1|max:500',
            'replace'       => 'required_without_all|nullable|string|max:500',
            'case_sensitive' => 'nullable|boolean',
            'preview_only'  => 'nullable|boolean',
        ]);

        $search        = $data['search'];
        $replace       = $data['replace'] ?? '';
        $caseSensitive = !empty($data['case_sensitive']);
        $previewOnly   = !empty($data['preview_only']);

        $chapters          = $audioBook->chapters()->whereNotNull('content')->get();
        $totalMatches      = 0;
        $chaptersAffected  = 0;
        $previewItems      = [];

        foreach ($chapters as $chapter) {
            if ($caseSensitive) {
                $count = substr_count($chapter->content, $search);
            } else {
                $count = substr_count(mb_strtolower($chapter->content), mb_strtolower($search));
            }

            if ($count > 0) {
                $totalMatches += $count;
                $chaptersAffected++;

                if ($previewOnly) {
                    $previewItems[] = [
                        'chapter_id'     => $chapter->id,
                        'chapter_title'  => $chapter->title ?: "Chương {$chapter->chapter_number}",
                        'match_count'    => $count,
                    ];
                } else {
                    if ($caseSensitive) {
                        $newContent = str_replace($search, $replace, $chapter->content);
                    } else {
                        $newContent = str_ireplace($search, $replace, $chapter->content);
                    }
                    $chapter->update(['content' => $newContent]);
                }
            }
        }

        return response()->json([
            'success'           => true,
            'preview_only'      => $previewOnly,
            'total_matches'     => $totalMatches,
            'chapters_affected' => $chaptersAffected,
            'preview_items'     => $previewItems,
            'message'           => $previewOnly
                ? "Tìm thấy {$totalMatches} kết quả trong {$chaptersAffected} chương."
                : "Đã thay thế {$totalMatches} lần trong {$chaptersAffected} chương.",
        ]);
    }

    /**
     * Fix chapters where content starts with a single letter then a space (e.g. "A bc..." => "Abc...")
     */
    public function fixLeadingInitialSpace(AudioBook $audioBook)
    {
        $chapters = $audioBook->chapters()->whereNotNull('content')->get();
        $chaptersAffected = 0;
        $totalReplacements = 0;

        foreach ($chapters as $chapter) {
            $content = (string) $chapter->content;
            $newContent = preg_replace('/^(\s*)(\p{L})\s+/u', '$1$2', $content, 1, $count);

            if (($count ?? 0) > 0 && $newContent !== null && $newContent !== $content) {
                $chapter->update(['content' => $newContent]);
                $chaptersAffected++;
                $totalReplacements += (int) $count;
            }
        }

        return response()->json([
            'success' => true,
            'chapters_affected' => $chaptersAffected,
            'total_replacements' => $totalReplacements,
            'message' => $chaptersAffected > 0
                ? "Đã sửa {$totalReplacements} lỗi ở {$chaptersAffected} chương."
                : 'Không phát hiện chương nào có lỗi ký tự đầu + khoảng trắng.',
        ]);
    }

    /**
     * Upload intro/outro music for audiobook
     */
    public function uploadMusic(Request $request, AudioBook $audioBook)
    {
        try {
            $request->validate([
                'type' => 'required|in:intro,outro',
                'music_file' => 'required|file|mimes:mp3,wav,m4a|max:20480' // Max 20MB
            ]);

            $type = $request->input('type');
            $file = $request->file('music_file');

            // Create music directory
            $musicDir = "books/{$audioBook->id}/music";
            $storagePath = storage_path("app/public/{$musicDir}");
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            // Generate filename
            $filename = "{$type}_" . time() . '.' . $file->getClientOriginalExtension();
            $relativePath = "{$musicDir}/{$filename}";

            // Store new file
            $file->storeAs("public/{$musicDir}", $filename);

            // Update database
            $field = $type === 'intro' ? 'intro_music' : 'outro_music';
            $audioBook->update([$field => $relativePath]);

            return response()->json([
                'success' => true,
                'message' => ucfirst($type) . ' music đã được upload',
                'path' => $relativePath,
                'url' => asset("storage/{$relativePath}")
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->validator->errors()->first()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Lỗi upload: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign one previously uploaded music file to intro/outro
     */
    public function selectMusicFile(Request $request, AudioBook $audioBook)
    {
        try {
            $data = $request->validate([
                'type' => 'required|in:intro,outro',
                'path' => 'required|string'
            ]);

            $type = $data['type'];
            $path = ltrim((string) $data['path'], '/');

            if (!preg_match('/^books\/\d+\/music\/.+\.(mp3|wav|m4a)$/i', $path)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Đường dẫn file nhạc không hợp lệ.'
                ], 422);
            }

            if (!Storage::disk('public')->exists($path)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Không tìm thấy file nhạc đã chọn.'
                ], 404);
            }

            $field = $type === 'intro' ? 'intro_music' : 'outro_music';
            $audioBook->update([$field => $path]);

            return response()->json([
                'success' => true,
                'message' => 'Đã chọn nhạc ' . $type,
                'path' => $path,
                'url' => asset('storage/' . $path)
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->validator->errors()->first()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Lỗi chọn nhạc: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete intro/outro music
     */
    public function deleteMusic(Request $request, AudioBook $audioBook)
    {
        try {
            $request->validate([
                'type' => 'required|in:intro,outro'
            ]);

            $type = $request->input('type');
            $field = $type === 'intro' ? 'intro_music' : 'outro_music';
            $path = $audioBook->$field;

            $audioBook->update([$field => null]);

            return response()->json([
                'success' => true,
                'message' => ucfirst($type) . ' music đã được xóa'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->validator->errors()->first()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Lỗi xóa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update intro/outro music settings (fade durations)
     */
    public function updateMusicSettings(Request $request, AudioBook $audioBook)
    {
        try {
            $data = $request->validate([
                'intro_fade_duration' => 'nullable|integer|min:1|max:30',
                'outro_fade_duration' => 'nullable|integer|min:1|max:60',
                'outro_extend_duration' => 'nullable|integer|min:0|max:30',
                'outro_use_intro' => 'nullable|boolean'
            ]);

            $audioBook->update($data);

            // Check which chapters have merged audio files that need re-merge
            $chaptersToReMerge = [];
            $bookDir = storage_path("app/public/books/{$audioBook->id}");

            foreach ($audioBook->chapters as $chapter) {
                $chapterNumPadded = str_pad($chapter->chapter_number, 3, '0', STR_PAD_LEFT);
                $fullAudioPath = "{$bookDir}/c_{$chapterNumPadded}_full.mp3";

                // Check if merged file exists
                if (file_exists($fullAudioPath)) {
                    $chaptersToReMerge[] = [
                        'id' => $chapter->id,
                        'chapter_number' => $chapter->chapter_number
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Đã lưu cài đặt nhạc nền',
                'chapters_to_remerge' => $chaptersToReMerge,
                'remerge_count' => count($chaptersToReMerge)
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->validator->errors()->first()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Lỗi lưu cấu hình: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update wave effect settings for video generation
     */
    public function updateWaveSettings(Request $request, AudioBook $audioBook)
    {
        $data = $request->validate([
            'wave_enabled' => 'nullable|boolean',
            'wave_type' => 'nullable|string|in:line,p2p,cline,point,bar',
            'wave_position' => 'nullable|string|in:top,center,bottom',
            'wave_height' => 'nullable|integer|min:50|max:300',
            'wave_width' => 'nullable|integer|min:20|max:100',
            'wave_color' => 'nullable|string|max:20',
            'wave_opacity' => 'nullable|numeric|min:0.1|max:1'
        ]);

        $audioBook->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Đã lưu cài đặt hiệu ứng sóng âm'
        ]);
    }

    /**
     * Update speaker (MC) for the audiobook
     */
    public function updateSpeaker(Request $request, AudioBook $audioBook)
    {
        $data = $request->validate([
            'speaker_id' => 'nullable|exists:channel_speakers,id'
        ]);

        // Verify the speaker belongs to the same YouTube channel
        if ($data['speaker_id']) {
            $speaker = ChannelSpeaker::find($data['speaker_id']);
            if ($speaker && $speaker->youtube_channel_id !== $audioBook->youtube_channel_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'MC không thuộc kênh YouTube này'
                ], 400);
            }
        }

        $audioBook->update(['speaker_id' => $data['speaker_id']]);

        // Get speaker info for response
        $speakerInfo = null;
        if ($audioBook->speaker) {
            $speakerInfo = [
                'id' => $audioBook->speaker->id,
                'name' => $audioBook->speaker->name,
                'avatar_url' => $audioBook->speaker->avatar_url,
                'gender' => $audioBook->speaker->gender,
                'default_voice_provider' => $audioBook->speaker->default_voice_provider,
                'default_voice_name' => $audioBook->speaker->default_voice_name,
                'voice_style' => $audioBook->speaker->voice_style,
                'lip_sync_enabled' => $audioBook->speaker->lip_sync_enabled,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật MC cho audiobook',
            'speaker' => $speakerInfo
        ]);
    }

    /**
     * Update book description
     */
    public function updateDescription(Request $request, AudioBook $audioBook)
    {
        $data = $request->validate([
            'description' => 'nullable|string|max:10000'
        ]);

        $audioBook->update(['description' => $data['description']]);

        return response()->json([
            'success' => true,
            'message' => 'Đã lưu mô tả'
        ]);
    }

    /**
     * Rewrite description using AI (OpenAI ChatGPT)
     */
    public function rewriteDescription(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'current_description' => 'nullable|string',
            'title' => 'required|string',
            'author' => 'nullable|string',
            'category' => 'nullable|string',
            'channel_name' => 'nullable|string'
        ]);

        $title = $request->input('title');
        $author = $request->input('author', '');
        $category = $request->input('category', '');
        $currentDesc = $request->input('current_description', '');
        $channelName = $request->input('channel_name', '');

        $openingStyles = [
            'Mo bang bang cau hoi goi mo va dan vao tac pham.',
            'Mo bang mot khoanh khac cam xuc hoac ky niem gan voi viec doc sach.',
            'Mo bang mot nhan dinh nhe ve the loai hoac chu de chinh.',
            'Mo bang loi chao gon va gioi thieu ngay diem hap dan cua tac pham.',
            'Mo bang mot cau trich dan ngan (khong can ghi nguon) de tao khong khi.'
        ];
        $openingStyle = $openingStyles[array_rand($openingStyles)];

        // Build prompt
        $prompt = "Bạn là một chuyên gia viết nội dung cho kênh audiobook YouTube. Hãy viết một bài giới thiệu hấp dẫn và chuyên nghiệp cho audiobook với thông tin sau:\n\n";
        $prompt .= "📚 TÊN TÁC PHẨM: {$title}\n";
        if ($author) $prompt .= "✍️ TÁC GIẢ: {$author}\n";
        if ($category) $prompt .= "📂 THỂ LOẠI: {$category}\n";
        if ($channelName) $prompt .= "📺 KÊNH YOUTUBE: {$channelName}\n";
        if ($currentDesc) $prompt .= "\n📝 MÔ TẢ GỐC (dùng để tham khảo nội dung):\n{$currentDesc}\n";

        $prompt .= "\n=== YÊU CẦU CẤU TRÚC BÀI VIẾT ===\n\n";
        $prompt .= "- Yeu cau mo dau: {$openingStyle}\n";
        $prompt .= "- Khong duoc lap lai 2 doan dau hoac cach mo dau trong mo ta goc; neu can tham khao, chi lay thong tin va dien dat lai theo cach moi.\n\n";

        $prompt .= "1. LỜI CHÀO CÁ NHÂN (3-4 câu) - RẤT QUAN TRỌNG:\n";
        $prompt .= "   - Bắt đầu bằng lời chào thân mật, ấm áp như đang nói chuyện trực tiếp với khán giả\n";
        $prompt .= "   - Thể hiện cảm xúc vui mừng, hào hứng được gặp lại/đồng hành cùng khán giả\n";
        $prompt .= "   - VÍ DỤ: 'Xin chào tất cả các bạn! Hôm nay tôi rất vui được quay trở lại và đồng hành cùng các bạn trong hành trình khám phá thế giới văn học...'\n";
        $prompt .= "   - Hoặc: 'Chào các bạn thân mến! Thật tuyệt vời khi chúng ta lại có dịp gặp nhau. Hôm nay, tôi muốn chia sẻ với các bạn một tác phẩm đặc biệt...'\n";
        $prompt .= "   - Tạo cảm giác đây là một người thật đang chia sẻ, không phải AI hay robot\n\n";

        $prompt .= "2. GIỚI THIỆU KÊNH VÀ TÁC PHẨM (2-3 câu):\n";
        $prompt .= "   - Chào đón khán giả đến với kênh {$channelName}\n";
        $prompt .= "   - Giới thiệu ngắn gọn tác phẩm sẽ được nghe hôm nay\n\n";

        $prompt .= "3. GIỚI THIỆU TÁC GIẢ (3-5 câu):\n";
        $prompt .= "   - Tên đầy đủ, bút danh (nếu có) của tác giả {$author}\n";
        $prompt .= "   - Tiểu sử ngắn: năm sinh, quê quán, nghề nghiệp, phong cách sáng tác\n";
        $prompt .= "   - Các tác phẩm nổi tiếng khác của tác giả (nếu biết)\n";
        $prompt .= "   - Vị trí của tác giả trong văn đàn (nếu có)\n\n";

        $prompt .= "4. QUÁ TRÌNH SÁNG TÁC (2-3 câu):\n";
        $prompt .= "   - Hoàn cảnh, thời điểm tác phẩm \"{$title}\" được viết\n";
        $prompt .= "   - Cảm hứng hay bối cảnh lịch sử tạo nên tác phẩm (nếu biết)\n\n";

        $prompt .= "5. NỘI DUNG CHÍNH (4-6 câu):\n";
        $prompt .= "   - Tóm tắt cốt truyện/nội dung chính (không spoil)\n";
        $prompt .= "   - Nhân vật chính và mối quan hệ\n";
        $prompt .= "   - Bối cảnh thời gian, không gian\n";
        $prompt .= "   - Thông điệp, ý nghĩa sâu sắc của tác phẩm\n\n";

        $prompt .= "6. SỨC ẢNH HƯỞNG VÀ DANH TIẾNG (2-3 câu):\n";
        $prompt .= "   - Tác phẩm nổi tiếng như thế nào (giải thưởng, độ phổ biến)\n";
        $prompt .= "   - Đánh giá của độc giả, giới phê bình\n";
        $prompt .= "   - Tác phẩm đã được chuyển thể (phim, nhạc kịch...) chưa\n\n";

        $prompt .= "7. KÊU GỌI HÀNH ĐỘNG - CTA (4-5 câu):\n";
        $prompt .= "   - Mời khán giả ủng hộ kênh bằng cách ĐĂNG KÝ KÊNH (subscribe) và bật chuông thông báo\n";
        $prompt .= "   - Nhắc nhở LIKE video nếu thấy hay, SHARE chia sẻ cho bạn bè cùng nghe\n";
        $prompt .= "   - Mời COMMENT bình luận chia sẻ cảm nhận về tác phẩm\n";
        $prompt .= "   - Cảm ơn sự ủng hộ của khán giả đã đồng hành cùng kênh\n";
        $prompt .= "   - KẾT THÚC bằng câu chuyển tiếp tự nhiên kiểu: 'Không để các bạn đợi lâu, chúng ta sẽ bắt đầu ngay với chương 1' hoặc 'Bây giờ, mời các bạn cùng tôi bước vào câu chuyện...'\n\n";

        $prompt .= "=== YÊU CẦU VỀ PHONG CÁCH ===\n";
        $prompt .= "- QUAN TRỌNG: Viết như một người dẫn chương trình thực sự, có cảm xúc, có tính cách, KHÔNG phải như một cái máy\n";
        $prompt .= "- Sử dụng ngôi thứ nhất 'tôi', 'mình' để tạo sự gần gũi\n";
        $prompt .= "- Viết bằng tiếng Việt chuẩn, văn phong ấm áp, thân thiện như đang trò chuyện với người nghe\n";
        $prompt .= "- Độ dài: 400-500 từ (đủ chi tiết nhưng không quá dài)\n";
        $prompt .= "- KHÔNG sử dụng emoji trong bài viết\n";
        $prompt .= "- KHÔNG dùng các tiêu đề đánh số (1., 2., 3...) trong bài viết, hãy viết thành đoạn văn liền mạch\n";
        $prompt .= "- Nếu không biết thông tin về tác giả/tác phẩm, hãy viết chung chung và tự nhiên, KHÔNG bịa thông tin sai\n";
        $prompt .= "- Chỉ trả về nội dung bài viết, không cần tiêu đề hay giải thích thêm";

        try {
            $client = new \GuzzleHttp\Client();

            // Use Gemini API (free tier available)
            $apiKey = config('services.gemini.api_key');
            $response = $client->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}", [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 800
                    ]
                ],
                'timeout' => 30
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $newDescription = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($newDescription)) {
                throw new \Exception('AI không trả về nội dung');
            }

            return response()->json([
                'success' => true,
                'description' => trim($newDescription)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Lỗi AI: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate TTS audio for book description
     */
    public function generateDescriptionAudio(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'description' => 'required|string',
            'provider' => 'required|string|in:openai,gemini,microsoft,vbee',
            'voice_name' => 'required|string',
            'voice_gender' => 'nullable|string|in:male,female',
            'style_instruction' => 'nullable|string'
        ]);

        // Clear previous progress
        Cache::forget("desc_audio_progress_{$audioBook->id}");

        GenerateDescriptionAudioJob::dispatch($audioBook->id, [
            'description' => $request->input('description'),
            'provider' => $request->input('provider'),
            'voice_name' => $request->input('voice_name'),
            'voice_gender' => $request->input('voice_gender', 'female'),
            'style_instruction' => $request->input('style_instruction'),
        ]);

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'Đã đưa vào hàng đợi tạo audio...',
        ]);
    }

    public function getDescriptionAudioProgress(AudioBook $audioBook)
    {
        $progress = Cache::get("desc_audio_progress_{$audioBook->id}");

        if (!$progress) {
            return response()->json([
                'status' => 'pending',
                'message' => 'Đang chờ xử lý...',
            ]);
        }

        return response()->json($progress);
    }

    /**
     * Generate lip-sync video from existing description audio
     */
    public function generateDescriptionVideo(Request $request, AudioBook $audioBook)
    {
        try {
            $this->updateDescriptionVideoProgress($audioBook->id, [
                'status' => 'processing',
                'percent' => 5,
                'message' => 'Đang khởi tạo...'
            ]);
            // Validate: need description audio
            if (!$audioBook->description_audio) {
                $this->updateDescriptionVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'Chưa có audio giới thiệu.'
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Chưa có audio giới thiệu. Vui lòng tạo audio trước.'
                ], 400);
            }

            // Validate: need intro music
            if (!$audioBook->intro_music) {
                $this->updateDescriptionVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'Chưa có nhạc Intro.'
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Chưa có nhạc Intro. Vui lòng upload nhạc Intro trước.'
                ], 400);
            }

            // Validate: need wave settings
            if (!$audioBook->wave_enabled) {
                $this->updateDescriptionVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'Chưa bật hiệu ứng sóng âm.'
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Chưa bật hiệu ứng sóng âm. Vui lòng bật và cấu hình trước.'
                ], 400);
            }

            $request->validate([
                'image_path' => 'required|string',
                'image_type' => 'required|string|in:thumbnails,scenes'
            ]);

            $imageType = $request->input('image_type');
            $imageFilename = $request->input('image_path');

            // Build absolute paths
            $imagePath = storage_path('app/public/books/' . $audioBook->id . '/' . $imageType . '/' . $imageFilename);
            if (!file_exists($imagePath)) {
                $this->updateDescriptionVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'File ảnh không tồn tại.'
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'File ảnh không tồn tại: ' . $imageFilename
                ], 404);
            }

            $voicePath = storage_path('app/public/' . $audioBook->description_audio);
            if (!file_exists($voicePath)) {
                $this->updateDescriptionVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'File audio giới thiệu không tồn tại.'
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'File audio giới thiệu không tồn tại.'
                ], 404);
            }

            $introMusicPath = storage_path('app/public/' . $audioBook->intro_music);
            if (!file_exists($introMusicPath)) {
                $this->updateDescriptionVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'File nhạc Intro không tồn tại.'
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'File nhạc Intro không tồn tại.'
                ], 404);
            }

            // Get voice duration
            $ffprobe = env('FFPROBE_PATH', 'ffprobe');
            $voiceDuration = (float) $audioBook->description_audio_duration;
            if ($voiceDuration <= 0) {
                $cmd = sprintf(
                    '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                    $ffprobe,
                    escapeshellarg($voicePath)
                );
                exec($cmd, $output);
                $voiceDuration = !empty($output) ? (float) $output[0] : 0;
            }

            // Music settings
            $introFadeDuration = (float) ($audioBook->intro_fade_duration ?? 3);
            $outroFadeDuration = (float) ($audioBook->outro_fade_duration ?? 10);
            $outroExtendDuration = (float) ($audioBook->outro_extend_duration ?? 5);

            // Total video duration: intro_fade + voice + outro_extend
            // Music plays full at start, fades down over intro_fade, voice starts
            // Voice plays with music underneath at low volume
            // After voice ends, music fades back up, then fades out over outro_fade for outro_extend seconds
            $totalDuration = $introFadeDuration + $voiceDuration + $outroExtendDuration;

            // Wave settings
            // Map wave_type to valid FFmpeg showwaves modes: point, line, p2p, cline
            $waveTypeMap = [
                'point' => 'point',
                'line' => 'line',
                'p2p' => 'p2p',
                'cline' => 'cline',
                'bar' => 'line', // 'bar' not supported by showwaves, use 'line' instead
            ];
            $rawWaveType = $audioBook->wave_type ?? 'cline';
            $waveType = $waveTypeMap[$rawWaveType] ?? 'cline';
            $wavePosition = $audioBook->wave_position ?? 'bottom';
            $waveHeight = (int) ($audioBook->wave_height ?? 100);
            $waveWidthPercent = (int) ($audioBook->wave_width ?? 100);
            $waveColor = ltrim($audioBook->wave_color ?? '#00ff00', '#');
            $waveOpacity = (float) ($audioBook->wave_opacity ?? 0.8);

            Log::info("Generating intro video with music + wave", [
                'audiobook_id' => $audioBook->id,
                'voice_duration' => $voiceDuration,
                'total_duration' => $totalDuration,
                'intro_fade' => $introFadeDuration,
                'outro_fade' => $outroFadeDuration,
                'outro_extend' => $outroExtendDuration,
                'wave_type' => $waveType
            ]);

            // Output paths
            $outputDir = storage_path('app/public/books/' . $audioBook->id . '/mp4');
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }
            $tempDir = storage_path('app/temp/desc_intro_' . $audioBook->id . '_' . time());
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $outputPath = $outputDir . '/description_intro.mp4';
            $mixedAudioPath = $tempDir . '/mixed_audio.mp3';

            // Delete old file if exists
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            $ffmpeg = env('FFMPEG_PATH', 'ffmpeg');

            // ============================
            // Step 1: Mix intro music + voice → combined audio
            // ============================
            // Timeline:
            //   0 ──── intro_fade ──── (intro_fade + voice_duration) ──── total_duration
            //   |  intro music   |            voice only              |   outro music   |
            //
            // Music track: loops the intro music for the full duration
            //   - Plays only during intro and outro windows
            //   - Fades out at the end of intro and end of outro
            //
            // Voice track: delayed by intro_fade seconds

            $voiceStartTime = $introFadeDuration; // voice starts after intro music window
            $voiceEndTime = $voiceStartTime + $voiceDuration;

            $introFadeOutDuration = min(1.5, max(0.2, $introFadeDuration * 0.5));
            $introFadeOutStart = max(0, $voiceStartTime - $introFadeOutDuration);

            $outroDuration = max(0, $outroExtendDuration);
            $outroFadeOutDuration = $outroDuration > 0
                ? min($outroFadeDuration, max(0.2, $outroDuration))
                : 0;
            $outroFadeOutStart = $voiceEndTime + max(0, $outroDuration - $outroFadeOutDuration);

            // Build audio filter_complex:
            // [0:a] = intro music (looped)
            // [1:a] = voice
            if ($outroDuration > 0) {
                $musicVolumeExpr = sprintf(
                    'if(lt(t,%s),1,' .
                        'if(lt(t,%s),1-(t-%s)/%s,' .
                        'if(lt(t,%s),0,' .
                        'if(lt(t,%s),1,' .
                        'if(lt(t,%s),1-(t-%s)/%s,0)' .
                        '))))',
                    round($introFadeOutStart, 2),
                    round($voiceStartTime, 2),
                    round($introFadeOutStart, 2),
                    round($introFadeOutDuration, 2),
                    round($voiceEndTime, 2),
                    round($outroFadeOutStart, 2),
                    round($voiceEndTime + $outroDuration, 2),
                    round($outroFadeOutStart, 2),
                    round($outroFadeOutDuration, 2)
                );
            } else {
                $musicVolumeExpr = sprintf(
                    'if(lt(t,%s),1,' .
                        'if(lt(t,%s),1-(t-%s)/%s,0))',
                    round($introFadeOutStart, 2),
                    round($voiceStartTime, 2),
                    round($introFadeOutStart, 2),
                    round($introFadeOutDuration, 2)
                );
            }

            $audioFilterComplex = sprintf(
                // Music: loop to fill total duration, then apply volume envelope
                '[0:a]aloop=loop=-1:size=2e+09,atrim=0:%s,' .
                    'volume=eval=frame:volume=\'%s\',aformat=sample_fmts=fltp[music];' .
                    // Voice: delay by intro_fade seconds
                    '[1:a]adelay=%d|%d,aformat=sample_fmts=fltp[voice];' .
                    // Mix both tracks
                    '[music][voice]amix=inputs=2:duration=first:dropout_transition=3[mixout]',
                round($totalDuration, 2),
                $musicVolumeExpr,
                (int) ($voiceStartTime * 1000),
                (int) ($voiceStartTime * 1000)
            );

            $mixCmd = sprintf(
                '%s -y -i %s -i %s -filter_complex "%s" -map "[mixout]" -c:a libmp3lame -b:a 192k %s 2>&1',
                $ffmpeg,
                escapeshellarg($introMusicPath),
                escapeshellarg($voicePath),
                $audioFilterComplex,
                escapeshellarg($mixedAudioPath)
            );

            $this->updateDescriptionVideoProgress($audioBook->id, [
                'status' => 'processing',
                'percent' => 20,
                'message' => 'Đang trộn audio...'
            ]);

            $this->updateDescriptionVideoLog($audioBook->id, 'FFmpeg: bat dau tron audio');

            Log::info("FFmpeg audio mix command", ['cmd' => $mixCmd]);
            exec($mixCmd, $mixOutput, $mixReturnCode);

            foreach (array_slice($mixOutput, -10) as $line) {
                $this->updateDescriptionVideoLog($audioBook->id, trim((string) $line));
            }

            if ($mixReturnCode !== 0 || !file_exists($mixedAudioPath)) {
                $this->updateDescriptionVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'FFmpeg không thể mix audio.'
                ]);
                $this->updateDescriptionVideoLog($audioBook->id, 'FFmpeg: tron audio that bai');
                Log::error("FFmpeg audio mix failed", [
                    'return_code' => $mixReturnCode,
                    'output' => implode("\n", $mixOutput)
                ]);
                $this->cleanupDirectory($tempDir);
                return response()->json([
                    'success' => false,
                    'error' => 'FFmpeg không thể mix audio. Return code: ' . $mixReturnCode
                ], 500);
            }

            $this->updateDescriptionVideoProgress($audioBook->id, [
                'status' => 'processing',
                'percent' => 45,
                'message' => 'Đã trộn audio. Đang tạo video...'
            ]);

            $this->updateDescriptionVideoLog($audioBook->id, 'FFmpeg: bat dau tao video');

            // ============================
            // Step 2: Create video (image + mixed audio + wave overlay)
            // ============================
            $totalFrames = max(1, (int) ceil($totalDuration * 25));

            // Calculate wave Y position (1080p)
            switch ($wavePosition) {
                case 'top':
                    $waveY = 30;
                    break;
                case 'center':
                    $waveY = (int) ((1080 - $waveHeight) / 2);
                    break;
                case 'bottom':
                default:
                    $waveY = 1080 - $waveHeight - 30;
                    break;
            }

            // Scale wave height for 1080p (original was for 720p)
            $scaledWaveHeight = (int) ($waveHeight * 1.5);
            $wavePixelWidth = (int) (1920 * $waveWidthPercent / 100);
            $waveX = (int) ((1920 - $wavePixelWidth) / 2); // center horizontally

            // Video filter with zoompan + wave overlay
            // Input 0: image (looped), Input 1: mixed audio
            $videoFilterComplex = sprintf(
                // Image → scale/pad → zoompan (oscillating zoom in/out across duration)
                '[0:v]scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2,' .
                    'zoompan=z=\'min(1.06,max(1.0,1+0.02*sin(2*PI*on/(25*20))))\':d=%d:x=\'iw/2-(iw/zoom/2)\':y=\'ih/2-(ih/zoom/2)\':s=1920x1080:fps=25[bg];' .
                    // Wave visualization from mixed audio
                    '[1:a]showwaves=s=%dx%d:mode=%s:colors=0x%s@%.1f:rate=25[wave];' .
                    // Overlay wave on video (centered)
                    '[bg][wave]overlay=%d:%d:format=auto[out]',
                $totalFrames,
                $wavePixelWidth,
                $scaledWaveHeight,
                $waveType,
                $waveColor,
                $waveOpacity,
                $waveX,
                $waveY
            );

            $videoCmd = sprintf(
                '%s -y -loop 1 -i %s -i %s -filter_complex "%s" -map "[out]" -map 1:a ' .
                    '-c:v libx264 -preset medium -tune stillimage -crf 23 -c:a aac -b:a 192k ' .
                    '-pix_fmt yuv420p -t %s -movflags +faststart -progress pipe:1 -stats %s',
                $ffmpeg,
                escapeshellarg($imagePath),
                escapeshellarg($mixedAudioPath),
                $videoFilterComplex,
                round($totalDuration, 2),
                escapeshellarg($outputPath)
            );

            Log::info("FFmpeg video command", ['cmd' => $videoCmd]);
            $videoResult = $this->runFfmpegWithProgress(
                $videoCmd,
                $audioBook->id,
                $totalDuration,
                45,
                95
            );

            $videoReturnCode = $videoResult['return_code'];
            $videoOutput = $videoResult['output'];

            // Cleanup temp
            $this->cleanupDirectory($tempDir);

            if ($videoReturnCode !== 0 || !file_exists($outputPath)) {
                $this->updateDescriptionVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'FFmpeg không thể tạo video.'
                ]);
                $this->updateDescriptionVideoLog($audioBook->id, 'FFmpeg: tao video that bai');
                Log::error("FFmpeg intro video failed", [
                    'return_code' => $videoReturnCode,
                    'output' => implode("\n", $videoOutput)
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'FFmpeg không thể tạo video. Return code: ' . $videoReturnCode
                ], 500);
            }

            // Get actual video duration
            $durationCmd = sprintf(
                '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                $ffprobe,
                escapeshellarg($outputPath)
            );
            $durationOutput = [];
            exec($durationCmd, $durationOutput);
            $videoDuration = !empty($durationOutput) ? (float) $durationOutput[0] : $totalDuration;

            // Save to audiobook
            $relativePath = 'books/' . $audioBook->id . '/mp4/description_intro.mp4';
            $audioBook->update([
                'description_scene_video' => $relativePath,
                'description_scene_video_duration' => $videoDuration
            ]);

            $this->updateDescriptionVideoProgress($audioBook->id, [
                'status' => 'completed',
                'percent' => 100,
                'message' => 'Hoàn tất!',
                'video_url' => $videoUrl,
                'video_duration' => $videoDuration
            ]);

            $this->updateDescriptionVideoLog($audioBook->id, 'FFmpeg: tao video hoan tat');

            $videoUrl = asset('storage/' . $relativePath);
            Log::info("Intro video generated successfully", [
                'path' => $relativePath,
                'duration' => $videoDuration,
                'total_duration' => $totalDuration
            ]);

            return response()->json([
                'success' => true,
                'video_url' => $videoUrl,
                'video_duration' => $videoDuration,
                'message' => 'Video giới thiệu đã được tạo thành công!'
            ]);
        } catch (\Exception $e) {
            $this->updateDescriptionVideoProgress($audioBook->id, [
                'status' => 'error',
                'percent' => 0,
                'message' => 'Lỗi tạo video.'
            ]);
            $this->updateDescriptionVideoLog($audioBook->id, 'Loi: ' . $e->getMessage());
            Log::error("Generate description video failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start description intro video generation in background.
     */
    public function startDescriptionVideoJob(Request $request, AudioBook $audioBook)
    {
        $this->resetDescriptionVideoProgress($audioBook->id);

        $request->validate([
            'image_path' => 'required|string',
            'image_type' => 'required|string|in:thumbnails,scenes'
        ]);

        try {
            GenerateDescriptionVideoJob::dispatch(
                $audioBook->id,
                $request->input('image_path'),
                $request->input('image_type')
            );

            return response()->json([
                'success' => true,
                'message' => 'Đã nhận yêu cầu tạo video. Đang xử lý...'
            ]);
        } catch (\Throwable $e) {
            $this->updateDescriptionVideoProgress($audioBook->id, [
                'status' => 'error',
                'percent' => 0,
                'message' => 'Không thể khởi tạo job.'
            ]);
            $this->updateDescriptionVideoLog($audioBook->id, 'Loi khoi tao job: ' . $e->getMessage());

            Log::error('Start description video job failed', [
                'audiobook_id' => $audioBook->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get description intro video generation progress.
     */
    public function getDescriptionVideoProgress(AudioBook $audioBook)
    {
        $key = "desc_video_progress_{$audioBook->id}";
        $progress = Cache::get($key);
        $logKey = "desc_video_log_{$audioBook->id}";
        $logs = Cache::get($logKey, []);

        if (!$progress) {
            return response()->json([
                'success' => true,
                'status' => 'idle',
                'percent' => 0,
                'message' => '',
                'completed' => false,
                'logs' => $logs,
                'video_url' => null,
                'video_duration' => null
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => $progress['status'] ?? 'processing',
            'percent' => $progress['percent'] ?? 0,
            'message' => $progress['message'] ?? '',
            'completed' => ($progress['status'] ?? '') === 'completed',
            'logs' => $logs,
            'video_url' => $progress['video_url'] ?? null,
            'video_duration' => $progress['video_duration'] ?? null
        ]);
    }

    /**
     * Delete description audio file
     */
    public function deleteDescriptionAudio(AudioBook $audioBook)
    {
        try {
            if ($audioBook->description_audio) {
                $filePath = storage_path('app/public/' . $audioBook->description_audio);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                $audioBook->update([
                    'description_audio' => null,
                    'description_audio_duration' => null
                ]);

                Log::info("Deleted description audio for audiobook {$audioBook->id}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Đã xóa audio giới thiệu'
            ]);
        } catch (\Exception $e) {
            Log::error("Delete description audio failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete description lip-sync video file
     */
    public function deleteDescriptionVideo(AudioBook $audioBook)
    {
        try {
            // Delete scene video (intro video)
            if ($audioBook->description_scene_video) {
                $filePath = storage_path('app/public/' . $audioBook->description_scene_video);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                $audioBook->update([
                    'description_scene_video' => null,
                    'description_scene_video_duration' => null
                ]);

                Log::info("Deleted description intro video for audiobook {$audioBook->id}");
            }

            // Also clean up old lip-sync video if exists
            if ($audioBook->description_lipsync_video) {
                $filePath = storage_path('app/public/' . $audioBook->description_lipsync_video);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                $audioBook->update([
                    'description_lipsync_video' => null,
                    'description_lipsync_duration' => null
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Đã xóa video giới thiệu'
            ]);
        } catch (\Exception $e) {
            Log::error("Delete description video failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ========== BOOK REVIEW VIDEO ==========

    public function startBookReviewVideoJob(Request $request, AudioBook $audioBook)
    {
        $chaptersWithContent = $audioBook->chapters()->whereNotNull('content')->where('content', '!=', '')->count();
        if ($chaptersWithContent === 0) {
            return response()->json([
                'success' => false,
                'error' => 'Sách chưa có chương nào có nội dung.'
            ], 400);
        }

        Cache::forget("review_video_progress_{$audioBook->id}");

        GenerateBookReviewVideoJob::dispatch($audioBook->id, [
            'provider' => $request->input('provider', $audioBook->tts_provider ?? 'microsoft'),
            'voice_name' => $request->input('voice_name', $audioBook->tts_voice_name ?? ''),
            'voice_gender' => $request->input('voice_gender', $audioBook->tts_voice_gender ?? 'female'),
            'style_instruction' => $request->input('style_instruction', $audioBook->tts_style_instruction),
        ]);

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'Đã đưa vào hàng đợi tạo video review...',
            'chapters_count' => $chaptersWithContent,
        ]);
    }

    public function getBookReviewVideoProgress(AudioBook $audioBook)
    {
        $progress = Cache::get("review_video_progress_{$audioBook->id}");

        if (!$progress) {
            return response()->json([
                'status' => 'pending',
                'message' => 'Đang chờ xử lý...',
            ]);
        }

        return response()->json($progress);
    }

    public function deleteBookReviewVideo(AudioBook $audioBook)
    {
        try {
            if ($audioBook->review_video) {
                $filePath = storage_path('app/public/' . $audioBook->review_video);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // Clean up work directory
            $workDir = storage_path('app/public/books/' . $audioBook->id . '/review_video');
            if (is_dir($workDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($workDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($workDir);
            }

            $audioBook->update([
                'review_script' => null,
                'review_video' => null,
                'review_video_duration' => null,
            ]);

            Cache::forget("review_video_progress_{$audioBook->id}");

            return response()->json([
                'success' => true,
                'message' => 'Đã xóa video review'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getReviewChunks(AudioBook $audioBook)
    {
        $service = app(BookReviewVideoService::class);
        $chunks = $service->loadChunks($audioBook->id);

        if (!$chunks) {
            return response()->json(['success' => true, 'chunks' => []]);
        }

        $result = [];
        foreach ($chunks as $i => $chunk) {
            $imageUrl = null;
            if (!empty($chunk['image_path']) && file_exists($chunk['image_path'])) {
                $relativePath = str_replace(storage_path('app/public/'), '', $chunk['image_path']);
                $imageUrl = asset('storage/' . $relativePath);
            }

            $hasAudio = !empty($chunk['audio_path']) && file_exists($chunk['audio_path']);

            $result[] = [
                'chunk_index' => $i,
                'text' => $chunk['text'] ?? '',
                'image_prompt' => $chunk['image_prompt'] ?? '',
                'image_url' => $imageUrl,
                'has_audio' => $hasAudio,
                'audio_duration' => $chunk['audio_duration'] ?? null,
            ];
        }

        return response()->json(['success' => true, 'chunks' => $result]);
    }

    public function openReviewScriptStudio(AudioBook $audioBook)
    {
        $reviewScript = trim((string)($audioBook->review_script ?? ''));
        if ($reviewScript === '') {
            return response()->json([
                'success' => false,
                'error' => 'Chưa có kịch bản review để mở Studio câu.',
            ], 422);
        }

        $bookTitle = trim((string)($audioBook->title ?? ''));
        $defaultImagePrompt = $bookTitle !== ''
            ? 'Vertical 9:16 cinematic scene inspired by the book review of "' . $bookTitle . '", dramatic lighting, emotional atmosphere, no text, ultra detailed.'
            : 'Vertical 9:16 cinematic scene inspired by a book review, dramatic lighting, emotional atmosphere, no text, ultra detailed.';

        $service = app(BookReviewVideoService::class);
        $reviewChunks = $service->loadChunks($audioBook->id);
        if (is_array($reviewChunks)) {
            foreach ($reviewChunks as $chunk) {
                $chunkPrompt = trim((string)($chunk['image_prompt'] ?? ''));
                if ($chunkPrompt !== '') {
                    $defaultImagePrompt = $chunkPrompt;
                    break;
                }
            }
        }

        $items = $this->loadShortVideoItems($audioBook->id);

        $targetKey = null;
        foreach ($items as $key => $item) {
            if (($item['source'] ?? null) === 'review_script') {
                $targetKey = $key;
                break;
            }
        }

        if ($targetKey === null) {
            foreach ($items as $key => $item) {
                $title = strtolower(trim((string)($item['title'] ?? '')));
                if ($title !== '' && strpos($title, 'review script') !== false) {
                    $targetKey = $key;
                    break;
                }
            }
        }

        $now = now()->toDateTimeString();
        $targetIndex = 0;
        if ($targetKey === null) {
            $maxIndex = 0;
            foreach ($items as $position => $item) {
                $itemIndex = (int)($item['index'] ?? ($position + 1));
                if ($itemIndex > $maxIndex) {
                    $maxIndex = $itemIndex;
                }
            }

            $targetIndex = $maxIndex + 1;
            if ($targetIndex <= 0) {
                $targetIndex = count($items) + 1;
            }

            $items[] = [
                'index' => $targetIndex,
                'title' => 'Review Script',
                'style' => 'Review',
                'script' => $reviewScript,
                'image_prompt' => $defaultImagePrompt,
                'status' => 'planned',
                'error_message' => null,
                'audio_path' => null,
                'image_path' => null,
                'video_path' => null,
                'duration' => null,
                'shots' => [],
                'story_bible' => null,
                'character_bible' => null,
                'source' => 'review_script',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        } else {
            $item = $items[$targetKey];
            $targetIndex = (int)($item['index'] ?? ($targetKey + 1));
            if ($targetIndex <= 0) {
                $targetIndex = $targetKey + 1;
            }

            $oldScript = trim((string)($item['script'] ?? ''));
            $oldImagePrompt = trim((string)($item['image_prompt'] ?? ''));
            $newImagePrompt = $oldImagePrompt !== '' ? $oldImagePrompt : $defaultImagePrompt;

            $scriptChanged = $oldScript !== $reviewScript;
            $imagePromptChanged = $newImagePrompt !== $oldImagePrompt;

            $item['index'] = $targetIndex;
            $item['title'] = trim((string)($item['title'] ?? '')) !== '' ? $item['title'] : 'Review Script';
            $item['style'] = trim((string)($item['style'] ?? '')) !== '' ? $item['style'] : 'Review';
            $item['script'] = $reviewScript;
            $item['image_prompt'] = $newImagePrompt;
            $item['source'] = 'review_script';
            $item['status'] = 'planned';
            $item['error_message'] = null;
            $item['updated_at'] = $now;

            if (empty($item['created_at'])) {
                $item['created_at'] = $now;
            }

            if ($scriptChanged && !empty($item['audio_path'])) {
                $this->deleteShortVideoAssetFile($item['audio_path']);
                $item['audio_path'] = null;
                $item['duration'] = null;
            }

            if ($scriptChanged) {
                $this->deleteShortVideoShotAssets($item);
                $item['shots'] = [];
                $item['story_bible'] = null;
                $item['character_bible'] = null;
            }

            if (($scriptChanged || $imagePromptChanged) && !empty($item['image_path'])) {
                $this->deleteShortVideoAssetFile($item['image_path']);
                $item['image_path'] = null;
            }

            if (($scriptChanged || $imagePromptChanged) && !empty($item['video_path'])) {
                $this->deleteShortVideoAssetFile($item['video_path']);
                $item['video_path'] = null;
            }

            $items[$targetKey] = $item;
        }

        $this->saveShortVideoItems($audioBook->id, $items);

        $targetKeyAfterSave = $this->findShortVideoItemIndex($items, $targetIndex);

        if ($targetKeyAfterSave === null || $targetIndex <= 0) {
            return response()->json([
                'success' => false,
                'error' => 'Không xác định được short review để mở Studio câu.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'short_index' => $targetIndex,
            'item' => $this->mapShortVideoItemForResponse($audioBook->id, $items[$targetKeyAfterSave]),
            'items' => array_map(fn($item) => $this->mapShortVideoItemForResponse($audioBook->id, $item), $items),
        ]);
    }

    public function updateReviewChunkPrompt(Request $request, AudioBook $audioBook, int $index)
    {
        $request->validate(['image_prompt' => 'required|string']);

        $service = app(BookReviewVideoService::class);
        $chunks = $service->loadChunks($audioBook->id);

        if (!$chunks || !isset($chunks[$index])) {
            return response()->json(['success' => false, 'error' => 'Segment không tồn tại'], 404);
        }

        $chunks[$index]['image_prompt'] = $request->input('image_prompt');
        $service->saveChunks($audioBook->id, $chunks);

        return response()->json(['success' => true, 'image_prompt' => $chunks[$index]['image_prompt']]);
    }

    public function regenerateReviewChunkImage(Request $request, AudioBook $audioBook, int $index)
    {
        $service = app(BookReviewVideoService::class);
        $chunks = $service->loadChunks($audioBook->id);

        if (!$chunks || !isset($chunks[$index])) {
            return response()->json(['success' => false, 'error' => 'Segment không tồn tại'], 404);
        }

        $prompt = $chunks[$index]['image_prompt'] ?? '';
        if (empty($prompt)) {
            return response()->json(['success' => false, 'error' => 'Chưa có image prompt'], 400);
        }

        $imageProvider = $request->input('image_provider', 'gemini');

        try {
            $result = $service->generateChunkImage($audioBook->id, $index, $prompt, $imageProvider);

            if ($result['success']) {
                $relativePath = str_replace(storage_path('app/public/'), '', $result['image_path']);
                $imageUrl = asset('storage/' . $relativePath) . '?t=' . time();
                return response()->json(['success' => true, 'image_url' => $imageUrl]);
            }

            return response()->json(['success' => false, 'error' => $result['error'] ?? 'Không thể tạo ảnh'], 500);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function translateReviewChunkPrompt(Request $request, AudioBook $audioBook, int $index)
    {
        $service = app(BookReviewVideoService::class);
        $chunks = $service->loadChunks($audioBook->id);

        if (!$chunks || !isset($chunks[$index])) {
            return response()->json(['success' => false, 'error' => 'Segment không tồn tại'], 404);
        }

        $prompt = $chunks[$index]['image_prompt'] ?? '';
        if (empty($prompt)) {
            return response()->json(['success' => false, 'error' => 'Chưa có image prompt'], 400);
        }

        try {
            $result = $service->translatePrompt($prompt);
            return response()->json(['success' => true, ...$result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function startReviewAssetsJob(Request $request, AudioBook $audioBook)
    {
        $service = app(BookReviewVideoService::class);
        $chunks = $service->loadChunks($audioBook->id);

        if (!$chunks || empty($chunks)) {
            return response()->json(['success' => false, 'error' => 'Chưa có segments. Hãy tạo kịch bản trước.'], 400);
        }

        Cache::forget("review_assets_progress_{$audioBook->id}");

        \App\Jobs\GenerateReviewAssetsJob::dispatch($audioBook->id, [
            'provider' => $request->input('provider', $audioBook->tts_provider ?? 'microsoft'),
            'voice_name' => $request->input('voice_name', $audioBook->tts_voice_name ?? ''),
            'voice_gender' => $request->input('voice_gender', $audioBook->tts_voice_gender ?? 'female'),
            'style_instruction' => $request->input('style_instruction', $audioBook->tts_style_instruction),
            'image_provider' => $request->input('image_provider', 'gemini'),
        ]);

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'Đã đưa vào hàng đợi tạo ảnh & audio...',
        ]);
    }

    public function getReviewAssetsProgress(AudioBook $audioBook)
    {
        $progress = Cache::get("review_assets_progress_{$audioBook->id}");

        if (!$progress) {
            return response()->json(['status' => 'pending', 'message' => 'Đang chờ xử lý...']);
        }

        return response()->json($progress);
    }

    public function splitReviewChunk(Request $request, AudioBook $audioBook, int $index)
    {
        $request->validate(['text_with_delimiters' => 'required|string']);

        $service = app(BookReviewVideoService::class);

        try {
            $chunks = $service->splitChunk($audioBook->id, $index, $request->input('text_with_delimiters'));
            return response()->json(['success' => true, 'chunks_count' => count($chunks)]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function translateAllReviewPrompts(AudioBook $audioBook)
    {
        $service = app(BookReviewVideoService::class);
        $chunks = $service->loadChunks($audioBook->id);

        if (!$chunks || empty($chunks)) {
            return response()->json(['success' => false, 'error' => 'Chưa có segments'], 400);
        }

        $translated = 0;
        $errors = [];

        foreach ($chunks as $i => &$chunk) {
            $prompt = $chunk['image_prompt'] ?? '';
            if (empty($prompt)) continue;

            try {
                $result = $service->translatePrompt($prompt);
                $chunk['image_prompt'] = $result['translated'];
                $translated++;
                sleep(2); // Rate limit
            } catch (\Exception $e) {
                $errors[] = "Segment {$i}: " . $e->getMessage();
            }
        }

        $service->saveChunks($audioBook->id, $chunks);

        return response()->json([
            'success' => true,
            'translated' => $translated,
            'errors' => $errors,
        ]);
    }

    /**
     * Generate lip-sync video for speaker
     */
    private function generateLipsyncVideo($speaker, $audioPath, $bookId)
    {
        // Check if speaker has avatar
        if (!$speaker->avatar_url) {
            throw new \Exception('Speaker does not have an avatar for lip-sync');
        }

        // Validate avatar URL is publicly accessible
        if (!$this->isPublicImageUrl($speaker->avatar_url)) {
            throw new \Exception(
                'Avatar URL must be a publicly accessible HTTPS URL. ' .
                    'Current URL: ' . $speaker->avatar_url . '. ' .
                    'Please upload the avatar to a public hosting service (S3, Cloudinary, etc.) or use ngrok to expose your local server.'
            );
        }

        Log::info("Generating lip-sync video using D-ID", [
            'speaker_id' => $speaker->id,
            'speaker_name' => $speaker->name,
            'book_id' => $bookId,
            'avatar_url' => $speaker->avatar_url
        ]);

        try {
            // Generate video using D-ID service
            $result = $this->lipsyncService->generateVideo(
                $audioPath,
                $speaker->avatar_url,
                [
                    'driver_url' => 'bank://lively' // Natural movement
                ]
            );

            // Download video from D-ID to local storage
            $timestamp = time();
            $videoFilename = "description_lipsync_{$timestamp}.mp4";
            $savePath = "books/{$bookId}/{$videoFilename}";

            $localPath = $this->lipsyncService->downloadVideo($result['video_url'], $savePath);

            // Get video duration from downloaded file
            $fullPath = storage_path('app/public/' . $localPath);
            $duration = $this->getAudioDuration($fullPath);

            Log::info("Lip-sync video generated successfully", [
                'video_path' => $localPath,
                'duration' => $duration
            ]);

            return [
                'video_path' => $localPath,
                'duration' => $duration,
                'did_video_id' => $result['video_id']
            ];
        } catch (\Exception $e) {
            Log::error("D-ID lip-sync generation failed", [
                'speaker_id' => $speaker->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get audio duration using ffprobe
     */
    private function getAudioDuration(string $filePath): ?float
    {
        if (!file_exists($filePath)) {
            return null;
        }

        // Try ffprobe first
        $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"{$filePath}\" 2>&1";
        exec($command, $output, $returnCode);

        if ($returnCode === 0 && isset($output[0]) && is_numeric($output[0])) {
            return (float) $output[0];
        }

        // Fallback: estimate based on file size (128kbps MP3)
        $fileSize = filesize($filePath);
        return $fileSize / (128 * 1024 / 8); // bytes / (bitrate in bytes/sec)
    }

    /**
     * Generate comprehensive composite video with lip-sync segments, media, music, and transitions
     */
    private function generateCompositeVideo(AudioBook $audioBook, $audioPath, $duration)
    {
        $bookId = $audioBook->id;
        $workDir = storage_path('app/temp/composite_' . $bookId . '_' . time());
        if (!is_dir($workDir)) {
            mkdir($workDir, 0755, true);
        }

        try {
            // Step 1: Plan segments (max 60s D-ID budget)
            $segmentPlan = $this->segmentManager->planSegments($audioPath, $duration, 60);
            Log::info("Segment plan created", ['segments' => count($segmentPlan)]);

            // Step 2: Extract audio segments and generate videos for each
            $processedSegments = [];

            foreach ($segmentPlan as $index => $segment) {
                if ($segment['type'] === 'lipsync') {
                    // Extract audio segment
                    $segmentAudioPath = $workDir . "/audio_segment_{$index}.mp3";
                    $this->segmentManager->extractAudioSegment(
                        $audioPath,
                        $segment['start'],
                        $segment['duration'],
                        $segmentAudioPath
                    );

                    // Generate lip-sync video for this segment
                    $lipsyncResult = $this->generateLipsyncVideo(
                        $audioBook->speaker,
                        $segmentAudioPath,
                        $bookId
                    );

                    $processedSegments[] = [
                        'type' => 'lipsync',
                        'video_path' => storage_path('app/public/' . $lipsyncResult['video_path']),
                        'duration' => $segment['duration'],
                        'audio_path' => $segmentAudioPath
                    ];
                } else {
                    // Media segment - get random media from gallery
                    $mediaPath = $this->getRandomMediaFromGallery($audioBook->id);

                    if (!$mediaPath) {
                        // Fallback to cover image if no media available
                        $mediaPath = $audioBook->cover_image
                            ? storage_path('app/public/' . $audioBook->cover_image)
                            : null;
                    }

                    // Extract audio for this segment
                    $segmentAudioPath = $workDir . "/audio_segment_{$index}.mp3";
                    $this->segmentManager->extractAudioSegment(
                        $audioPath,
                        $segment['start'],
                        $segment['duration'],
                        $segmentAudioPath
                    );

                    $processedSegments[] = [
                        'type' => 'media',
                        'media_path' => $mediaPath,
                        'duration' => $segment['duration'],
                        'audio_path' => $segmentAudioPath
                    ];
                }
            }

            // Step 3: Prepare composition options with music
            $options = [
                'avatar_url' => $audioBook->speaker->avatar_url,
                'intro_music' => $audioBook->intro_music
                    ? storage_path('app/public/' . $audioBook->intro_music)
                    : null,
                'outro_music' => $audioBook->outro_use_intro && $audioBook->intro_music
                    ? storage_path('app/public/' . $audioBook->intro_music)
                    : ($audioBook->outro_music ? storage_path('app/public/' . $audioBook->outro_music) : null),
                'bg_music' => $this->getBackgroundMusicPath(), // Nhạc nền nhẹ
                'intro_fade_duration' => $audioBook->intro_fade_duration ?? 3,
                'outro_fade_duration' => $audioBook->outro_fade_duration ?? 3,
            ];

            // Step 4: Compose final video
            $timestamp = time();
            $outputFilename = "description_composite_{$timestamp}.mp4";
            $outputPath = storage_path('app/public/books/' . $bookId . '/' . $outputFilename);

            $result = $this->compositionService->composeVideo(
                $processedSegments,
                $options,
                $outputPath
            );

            if ($result['success']) {
                return [
                    'video_path' => 'books/' . $bookId . '/' . $outputFilename,
                    'duration' => $result['duration']
                ];
            }

            throw new \Exception('Video composition failed');
        } finally {
            // Cleanup temp directory
            if (is_dir($workDir)) {
                array_map('unlink', glob($workDir . '/*'));
                rmdir($workDir);
            }
        }
    }

    /**
     * Get random media file from audiobook gallery
     */
    private function getRandomMediaFromGallery($audioBookId)
    {
        $mediaDir = storage_path('app/public/books/' . $audioBookId . '/media');

        if (!is_dir($mediaDir)) {
            return null;
        }

        $allMedia = [];

        // Collect all media files (thumbnails, scenes, animations)
        foreach (['thumbnails', 'scenes', 'animations'] as $type) {
            $typeDir = $mediaDir . '/' . $type;
            if (is_dir($typeDir)) {
                $files = glob($typeDir . '/*.{jpg,png,mp4,webm}', GLOB_BRACE);
                $allMedia = array_merge($allMedia, $files);
            }
        }

        if (empty($allMedia)) {
            return null;
        }

        // Return random media
        return $allMedia[array_rand($allMedia)];
    }

    /**
     * Get background music path (can be configured or use default)
     */
    private function getBackgroundMusicPath()
    {
        // Check if there's a default background music in storage
        $defaultBgMusic = storage_path('app/public/music/bg_music_default.mp3');

        if (file_exists($defaultBgMusic)) {
            return $defaultBgMusic;
        }

        // Return intro music at very low volume as fallback
        return null;
    }

    // ========== YouTube Media Generation Methods ==========

    /**
     * Get existing media for audiobook
     */
    public function getMedia(AudioBook $audioBook)
    {
        $media = $this->imageService->getExistingMedia($audioBook->id);

        return response()->json([
            'success' => true,
            'media' => $media
        ]);
    }

    public function uploadMedia(Request $request, AudioBook $audioBook)
    {
        error_log("=== UPLOAD MEDIA STARTED ===");
        error_log("AudioBook ID: " . $audioBook->id);
        error_log("Request type: " . $request->input('type'));
        error_log("Files count: " . count($request->file('images') ?? []));
        
        try {
            $request->validate([
                'type' => 'required|string|in:thumbnails,scenes',
                'images' => 'required|array|min:1|max:10',
                'images.*' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
            ]);
            
            error_log("Validation passed");

            $type = $request->input('type');
            $uploadedCount = 0;
            $uploadedFiles = [];

            $baseDir = storage_path('app/public/books/' . $audioBook->id . '/' . $type);

            if (!is_dir($baseDir)) {
                if (!mkdir($baseDir, 0775, true)) {
                    throw new \Exception("Không thể tạo thư mục: {$baseDir}");
                }
            }

            if (!is_writable($baseDir)) {
                throw new \Exception("Không có quyền ghi vào thư mục: {$baseDir}");
            }

            foreach ($request->file('images') as $index => $image) {
                if (!$image->isValid()) {
                    Log::warning("Invalid uploaded file at index {$index}");
                    continue;
                }

                $timestamp = time() . '_' . random_int(1000, 9999);
                $extension = $image->getClientOriginalExtension();
                $filename = ($type === 'thumbnails' ? 'thumb_' : 'scene_') . $timestamp . '.' . $extension;

                $targetPath = $baseDir . '/' . $filename;

                if ($image->move($baseDir, $filename)) {
                    $uploadedCount++;
                    $uploadedFiles[] = $filename;
                } else {
                    Log::warning("Failed to move uploaded file: {$filename}");
                }
            }

            if ($uploadedCount === 0) {
                throw new \Exception("Không có file nào được upload thành công");
            }

            Log::info('Media uploaded', [
                'audio_book_id' => $audioBook->id,
                'type' => $type,
                'count' => $uploadedCount,
                'files' => $uploadedFiles,
            ]);

            error_log("=== UPLOAD MEDIA SUCCESS - Returning response ===");
            error_log("Uploaded count: " . $uploadedCount);
            
            return response()->json([
                'success' => true,
                'uploaded' => $uploadedCount,
                'message' => "Đã upload {$uploadedCount} file vào {$type}",
                'files' => $uploadedFiles,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            error_log("=== UPLOAD MEDIA VALIDATION ERROR ===");
            error_log("Validation errors: " . json_encode($e->errors()));
            
            Log::error('Upload media validation failed', [
                'audio_book_id' => $audioBook->id,
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Validation failed: ' . json_encode($e->errors()),
            ], 422);
        } catch (\Throwable $e) {
            error_log("=== UPLOAD MEDIA EXCEPTION ===");
            error_log("Error: " . $e->getMessage());
            error_log("File: " . $e->getFile() . ":" . $e->getLine());
            
            Log::error('Upload media failed', [
                'audio_book_id' => $audioBook->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getShortVideos(AudioBook $audioBook)
    {
        $items = $this->loadShortVideoItems($audioBook->id);

        return response()->json([
            'success' => true,
            'items' => array_map(fn($item) => $this->mapShortVideoItemForResponse($audioBook->id, $item), $items),
        ]);
    }

    public function generateShortVideoPlans(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'count' => 'required|integer|min:1|max:20',
        ]);

        try {
            $count = (int) $request->input('count');
            $plans = $this->createShortVideoPlansWithAi($audioBook, $count);
            $this->saveShortVideoItems($audioBook->id, $plans);

            return response()->json([
                'success' => true,
                'count' => count($plans),
                'items' => array_map(fn($item) => $this->mapShortVideoItemForResponse($audioBook->id, $item), $plans),
            ]);
        } catch (\Throwable $e) {
            Log::error('Generate short video plans failed', [
                'audio_book_id' => $audioBook->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function generateShortVideoAssets(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'provider' => 'nullable|string|in:openai,gemini,microsoft,vbee',
            'voice_name' => 'nullable|string',
            'voice_gender' => 'nullable|string|in:male,female',
            'style_instruction' => 'nullable|string',
            'tts_speed' => 'nullable|numeric|between:0.5,2.0',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        if (empty($items)) {
            return response()->json([
                'success' => false,
                'error' => 'Chưa có kịch bản short. Hãy tạo kế hoạch trước.',
            ], 422);
        }

        $provider = $request->input('provider', $audioBook->tts_provider ?: 'openai');
        $voiceName = $request->input('voice_name', $audioBook->tts_voice_name);
        $voiceGender = $request->input('voice_gender', $audioBook->tts_voice_gender ?: 'female');
        $styleInstruction = $request->input('style_instruction', $audioBook->tts_style_instruction);
        $ttsSpeed = (float) $request->input('tts_speed', $audioBook->tts_speed ?: 1.0);

        if (empty($voiceName)) {
            return response()->json([
                'success' => false,
                'error' => 'Thiếu voice_name. Vui lòng cấu hình giọng TTS trước.',
            ], 422);
        }

        $dirs = $this->ensureShortVideoAssetDirs($audioBook->id);
        $audioDir = $dirs['audio'];
        $imageDir = $dirs['images'];
        $videoDir = $dirs['videos'];

        $successCount = 0;
        $errorCount = 0;

        foreach ($items as $idx => &$item) {
            try {
                $script = trim((string)($item['script'] ?? ''));
                $imagePrompt = trim((string)($item['image_prompt'] ?? ''));
                if ($script === '' || $imagePrompt === '') {
                    throw new \RuntimeException('Thiếu script hoặc image_prompt.');
                }

                $item['status'] = 'processing';
                $item['error_message'] = null;

                $tempAudioRelPath = $this->ttsService->generateAudio(
                    $script,
                    $idx + 1,
                    $voiceGender,
                    $voiceName,
                    $provider,
                    $styleInstruction,
                    null,
                    $ttsSpeed
                );

                $tempAudioAbsPath = storage_path('app/' . $tempAudioRelPath);
                if (!file_exists($tempAudioAbsPath)) {
                    throw new \RuntimeException('Không tìm thấy file audio tạm từ TTS.');
                }

                $audioFileName = 'short_' . ($idx + 1) . '_' . time() . '.mp3';
                $audioAbsPath = $audioDir . '/' . $audioFileName;
                if (file_exists($audioAbsPath)) {
                    unlink($audioAbsPath);
                }
                copy($tempAudioAbsPath, $audioAbsPath);
                @unlink($tempAudioAbsPath);

                $audioDuration = (float)($this->getAudioDuration($audioAbsPath) ?? 0.0);
                if ($audioDuration <= 0) {
                    $audioDuration = 30.0;
                }
                $videoDuration = min(60.0, $audioDuration);

                $imageFileName = 'short_' . ($idx + 1) . '_' . time() . '.png';
                $imageAbsPath = $imageDir . '/' . $imageFileName;
                $imgResult = $this->imageService->generateImage($imagePrompt, $imageAbsPath, '9:16');
                if (empty($imgResult['success'])) {
                    throw new \RuntimeException($imgResult['error'] ?? 'Không thể tạo ảnh minh họa.');
                }

                $videoFileName = 'short_' . ($idx + 1) . '_' . time() . '.mp4';
                $videoAbsPath = $videoDir . '/' . $videoFileName;
                $ffmpegPath = config('services.ffmpeg.path', env('FFMPEG_PATH', 'ffmpeg'));

                $ffmpegCmd = sprintf(
                    '%s -loop 1 -i %s -i %s -filter_complex %s -map "[v]" -map 1:a ' .
                        '-c:v libx264 -preset fast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -r 30 -t %.3f -shortest %s -y 2>&1',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($imageAbsPath),
                    escapeshellarg($audioAbsPath),
                    escapeshellarg('[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,zoompan=z=\'min(zoom+0.0006,1.12)\':d=1:s=1080x1920:fps=30[v]'),
                    $videoDuration,
                    escapeshellarg($videoAbsPath)
                );

                exec($ffmpegCmd, $ffmpegOutput, $ffmpegCode);
                if ($ffmpegCode !== 0 || !file_exists($videoAbsPath)) {
                    throw new \RuntimeException('FFmpeg tạo short video thất bại: ' . implode("\n", array_slice($ffmpegOutput, -5)));
                }

                $item['audio_path'] = 'books/' . $audioBook->id . '/short_videos/audio/' . $audioFileName;
                $item['image_path'] = 'books/' . $audioBook->id . '/short_videos/images/' . $imageFileName;
                $item['video_path'] = 'books/' . $audioBook->id . '/short_videos/videos/' . $videoFileName;
                $item['duration'] = round($videoDuration, 3);
                $item['status'] = 'completed';
                $item['updated_at'] = now()->toDateTimeString();
                $successCount++;
            } catch (\Throwable $e) {
                $item['status'] = 'error';
                $item['error_message'] = $e->getMessage();
                $item['updated_at'] = now()->toDateTimeString();
                $errorCount++;
            }
        }
        unset($item);

        $this->saveShortVideoItems($audioBook->id, $items);

        return response()->json([
            'success' => true,
            'processed' => count($items),
            'completed' => $successCount,
            'failed' => $errorCount,
            'items' => array_map(fn($item) => $this->mapShortVideoItemForResponse($audioBook->id, $item), $items),
        ]);
    }

    public function generateShortVideoTts(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'selected_indices' => 'required|array|min:1',
            'selected_indices.*' => 'integer|min:1',
            'provider' => 'nullable|string|in:openai,gemini,microsoft,vbee',
            'voice_name' => 'nullable|string',
            'voice_gender' => 'nullable|string|in:male,female',
            'style_instruction' => 'nullable|string',
            'tts_speed' => 'nullable|numeric|between:0.5,2.0',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        if (empty($items)) {
            return response()->json([
                'success' => false,
                'error' => 'Chưa có kịch bản short. Hãy tạo kế hoạch trước.',
            ], 422);
        }

        $selectedLookup = array_flip(array_values(array_unique(array_map('intval', (array) $request->input('selected_indices', [])))));
        if (empty($selectedLookup)) {
            return response()->json([
                'success' => false,
                'error' => 'Vui lòng chọn ít nhất 1 short.',
            ], 422);
        }

        $provider = $request->input('provider', $audioBook->tts_provider ?: 'openai');
        $voiceName = $request->input('voice_name', $audioBook->tts_voice_name);
        $voiceGender = $request->input('voice_gender', $audioBook->tts_voice_gender ?: 'female');
        $styleInstruction = $request->input('style_instruction', $audioBook->tts_style_instruction);
        $ttsSpeed = (float) $request->input('tts_speed', $audioBook->tts_speed ?: 1.0);

        if (empty($voiceName)) {
            return response()->json([
                'success' => false,
                'error' => 'Thiếu voice_name. Vui lòng cấu hình giọng TTS trước.',
            ], 422);
        }

        $dirs = $this->ensureShortVideoAssetDirs($audioBook->id);
        $audioDir = $dirs['audio'];

        $successCount = 0;
        $errorCount = 0;

        foreach ($items as $idx => &$item) {
            $itemIndex = (int)($item['index'] ?? ($idx + 1));
            if (!isset($selectedLookup[$itemIndex])) {
                continue;
            }

            try {
                $script = trim((string)($item['script'] ?? ''));
                if ($script === '') {
                    throw new \RuntimeException('Thiếu script để tạo TTS.');
                }

                $item['status'] = 'processing';
                $item['error_message'] = null;

                $tempAudioRelPath = $this->ttsService->generateAudio(
                    $script,
                    $itemIndex,
                    $voiceGender,
                    $voiceName,
                    $provider,
                    $styleInstruction,
                    null,
                    $ttsSpeed
                );

                $tempAudioAbsPath = storage_path('app/' . $tempAudioRelPath);
                if (!file_exists($tempAudioAbsPath)) {
                    throw new \RuntimeException('Không tìm thấy file audio tạm từ TTS.');
                }

                if (!empty($item['audio_path'])) {
                    $this->deleteShortVideoAssetFile($item['audio_path']);
                }

                if (!empty($item['video_path'])) {
                    $this->deleteShortVideoAssetFile($item['video_path']);
                    $item['video_path'] = null;
                }

                $audioFileName = 'short_' . $itemIndex . '_' . time() . '.mp3';
                $audioAbsPath = $audioDir . '/' . $audioFileName;
                copy($tempAudioAbsPath, $audioAbsPath);
                @unlink($tempAudioAbsPath);

                $audioDuration = (float)($this->getAudioDuration($audioAbsPath) ?? 0.0);
                if ($audioDuration <= 0) {
                    $audioDuration = 30.0;
                }

                $item['audio_path'] = 'books/' . $audioBook->id . '/short_videos/audio/' . $audioFileName;
                $item['duration'] = round(min(60.0, $audioDuration), 3);
                $item['status'] = 'planned';
                $item['updated_at'] = now()->toDateTimeString();
                $successCount++;
            } catch (\Throwable $e) {
                $item['status'] = 'error';
                $item['error_message'] = $e->getMessage();
                $item['updated_at'] = now()->toDateTimeString();
                $errorCount++;
            }
        }
        unset($item);

        $this->saveShortVideoItems($audioBook->id, $items);

        return response()->json([
            'success' => true,
            'processed' => count($selectedLookup),
            'completed' => $successCount,
            'failed' => $errorCount,
            'items' => array_map(fn($item) => $this->mapShortVideoItemForResponse($audioBook->id, $item), $items),
        ]);
    }

    public function generateShortVideoImages(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'selected_indices' => 'required|array|min:1',
            'selected_indices.*' => 'integer|min:1',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        if (empty($items)) {
            return response()->json([
                'success' => false,
                'error' => 'Chưa có kịch bản short. Hãy tạo kế hoạch trước.',
            ], 422);
        }

        $selectedLookup = array_flip(array_values(array_unique(array_map('intval', (array) $request->input('selected_indices', [])))));
        if (empty($selectedLookup)) {
            return response()->json([
                'success' => false,
                'error' => 'Vui lòng chọn ít nhất 1 short.',
            ], 422);
        }

        $dirs = $this->ensureShortVideoAssetDirs($audioBook->id);
        $imageDir = $dirs['images'];

        $successCount = 0;
        $errorCount = 0;

        foreach ($items as $idx => &$item) {
            $itemIndex = (int)($item['index'] ?? ($idx + 1));
            if (!isset($selectedLookup[$itemIndex])) {
                continue;
            }

            try {
                $imagePrompt = trim((string)($item['image_prompt'] ?? ''));
                if ($imagePrompt === '') {
                    throw new \RuntimeException('Thiếu image_prompt để tạo ảnh.');
                }

                $item['status'] = 'processing';
                $item['error_message'] = null;

                if (!empty($item['image_path'])) {
                    $this->deleteShortVideoAssetFile($item['image_path']);
                }

                if (!empty($item['video_path'])) {
                    $this->deleteShortVideoAssetFile($item['video_path']);
                    $item['video_path'] = null;
                }

                $imageFileName = 'short_' . $itemIndex . '_' . time() . '.png';
                $imageAbsPath = $imageDir . '/' . $imageFileName;
                $imgResult = $this->imageService->generateShortVerticalImage($imagePrompt, $imageAbsPath);
                if (empty($imgResult['success'])) {
                    throw new \RuntimeException($imgResult['error'] ?? 'Không thể tạo ảnh minh họa.');
                }

                $item['image_path'] = 'books/' . $audioBook->id . '/short_videos/images/' . $imageFileName;
                $item['status'] = 'planned';
                $item['updated_at'] = now()->toDateTimeString();
                $successCount++;
            } catch (\Throwable $e) {
                $item['status'] = 'error';
                $item['error_message'] = $e->getMessage();
                $item['updated_at'] = now()->toDateTimeString();
                $errorCount++;
            }
        }
        unset($item);

        $this->saveShortVideoItems($audioBook->id, $items);

        return response()->json([
            'success' => true,
            'processed' => count($selectedLookup),
            'completed' => $successCount,
            'failed' => $errorCount,
            'items' => array_map(fn($item) => $this->mapShortVideoItemForResponse($audioBook->id, $item), $items),
        ]);
    }

    public function downloadSelectedShortResources(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'selected_indices' => 'required|array|min:1',
            'selected_indices.*' => 'integer|min:1',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        if (empty($items)) {
            return response()->json([
                'success' => false,
                'error' => 'Chưa có short video để tải tài nguyên.',
            ], 404);
        }

        $selectedLookup = array_flip(array_values(array_unique(array_map('intval', (array) $request->input('selected_indices', [])))));
        if (empty($selectedLookup)) {
            return response()->json([
                'success' => false,
                'error' => 'Vui lòng chọn ít nhất 1 short.',
            ], 422);
        }

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $zipFileName = 'short_resources_book_' . $audioBook->id . '_' . now()->format('Ymd_His') . '.zip';
        $zipPath = $tmpDir . '/' . $zipFileName;

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            return response()->json([
                'success' => false,
                'error' => 'Không thể tạo file ZIP để tải.',
            ], 500);
        }

        $addedCount = 0;
        foreach ($items as $key => $item) {
            $itemIndex = (int)($item['index'] ?? ($key + 1));
            if (!isset($selectedLookup[$itemIndex])) {
                continue;
            }

            $assetMap = [
                'image' => $item['image_path'] ?? null,
                'audio' => $item['audio_path'] ?? null,
            ];

            foreach ($assetMap as $type => $relativePath) {
                if (empty($relativePath)) {
                    continue;
                }

                $absPath = storage_path('app/public/' . ltrim((string)$relativePath, '/'));
                if (!is_file($absPath)) {
                    continue;
                }

                $ext = pathinfo($absPath, PATHINFO_EXTENSION);
                $safeExt = $ext ? ('.' . $ext) : '';
                $entryName = 'short_' . $itemIndex . '/' . $type . $safeExt;
                if ($zip->addFile($absPath, $entryName)) {
                    $addedCount++;
                }
            }
        }

        $zip->close();

        if ($addedCount === 0) {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }

            return response()->json([
                'success' => false,
                'error' => 'Các short đã chọn chưa có ảnh hoặc TTS để tải.',
            ], 422);
        }

        return response()->download($zipPath, $zipFileName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function generateShortVideoImagePrompt(AudioBook $audioBook, int $index)
    {
        $items = $this->loadShortVideoItems($audioBook->id);
        if (empty($items)) {
            return response()->json([
                'success' => false,
                'error' => 'Chưa có short video để tạo prompt.',
            ], 404);
        }

        $targetKey = null;
        $targetItem = null;
        foreach ($items as $key => $item) {
            $itemIndex = (int)($item['index'] ?? ($key + 1));
            if ($itemIndex !== $index) {
                continue;
            }
            $targetKey = $key;
            $targetItem = $item;
            break;
        }

        if ($targetKey === null || !$targetItem) {
            return response()->json([
                'success' => false,
                'error' => 'Không tìm thấy short đã chọn.',
            ], 404);
        }

        $script = trim((string)($targetItem['script'] ?? ''));
        if ($script === '') {
            return response()->json([
                'success' => false,
                'error' => 'Short chưa có nội dung script để tạo prompt ảnh.',
            ], 422);
        }

        $apiKey = config('services.openai.api_key');
        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'error' => 'Thiếu OPENAI_API_KEY. Vui lòng cấu hình để dùng ChatGPT AI.',
            ], 500);
        }

        $bookTitle = trim((string)($audioBook->title ?? ''));
        $style = trim((string)($targetItem['style'] ?? 'Cinematic'));
        $title = trim((string)($targetItem['title'] ?? ('Short #' . $index)));

        $systemPrompt = "You are an expert cinematic prompt engineer for vertical short-video cover images.";
        $userPrompt = "Generate ONE English image prompt for AI image generation based on this short video content.\n"
            . "Requirements:\n"
            . "- Vertical composition 9:16 for YouTube Shorts/TikTok\n"
            . "- Cinematic, vivid, emotional, high detail, dramatic lighting\n"
            . "- No text, no captions, no logos, no watermark\n"
            . "- Avoid violence/gore/explicit content\n"
            . "- Keep under 90 words\n"
            . "- Return ONLY the prompt text, no explanation\n\n"
            . "Book title: {$bookTitle}\n"
            . "Short title: {$title}\n"
            . "Style: {$style}\n"
            . "Short script:\n{$script}";

        try {
            $resp = Http::timeout(60)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => env('OPENAI_IMAGE_PROMPT_MODEL', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 220,
                ]);

            if (!$resp->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => 'OpenAI API lỗi: HTTP ' . $resp->status(),
                ], 502);
            }

            $content = trim((string)data_get($resp->json(), 'choices.0.message.content', ''));
            if ($content === '') {
                return response()->json([
                    'success' => false,
                    'error' => 'ChatGPT không trả về prompt hợp lệ.',
                ], 502);
            }

            $content = trim($content, " \t\n\r\0\x0B\"'");
            $items[$targetKey]['image_prompt'] = $content;
            $items[$targetKey]['updated_at'] = now()->toDateTimeString();

            $this->saveShortVideoItems($audioBook->id, $items);

            return response()->json([
                'success' => true,
                'generated_prompt' => $content,
                'updated_index' => $index,
                'items' => array_map(fn($item) => $this->mapShortVideoItemForResponse($audioBook->id, $item), $items),
            ]);
        } catch (\Throwable $e) {
            Log::error('Lỗi tạo image prompt cho short bằng ChatGPT', [
                'audio_book_id' => $audioBook->id,
                'index' => $index,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Lỗi tạo prompt AI: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteShortVideo(AudioBook $audioBook, int $index)
    {
        $items = $this->loadShortVideoItems($audioBook->id);
        if (empty($items)) {
            return response()->json([
                'success' => false,
                'error' => 'Không có short video để xóa.',
            ], 404);
        }

        $removed = null;
        foreach ($items as $key => $item) {
            $itemIndex = (int)($item['index'] ?? ($key + 1));
            if ($itemIndex !== $index) {
                continue;
            }

            $removed = $item;
            unset($items[$key]);
            break;
        }

        if (!$removed) {
            return response()->json([
                'success' => false,
                'error' => 'Không tìm thấy short đã chọn.',
            ], 404);
        }

        $this->deleteShortVideoAssetFile($removed['audio_path'] ?? null);
        $this->deleteShortVideoAssetFile($removed['image_path'] ?? null);
        $this->deleteShortVideoAssetFile($removed['video_path'] ?? null);
        $this->deleteShortVideoShotAssets($removed);

        $items = array_values($items);
        foreach ($items as $i => &$item) {
            $item['index'] = $i + 1;
            $item['updated_at'] = now()->toDateTimeString();
        }
        unset($item);

        try {
            $this->saveShortVideoItems($audioBook->id, $items);
        } catch (\Throwable $e) {
            Log::error('Không thể lưu shorts.json sau khi xóa short', [
                'audio_book_id' => $audioBook->id,
                'index' => $index,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Không thể cập nhật danh sách short do lỗi quyền ghi file. Vui lòng kiểm tra thư mục storage/app/public/books/' . $audioBook->id . '/short_videos',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'deleted_index' => $index,
            'items' => array_map(fn($item) => $this->mapShortVideoItemForResponse($audioBook->id, $item), $items),
        ]);
    }

    public function updateShortVideo(Request $request, AudioBook $audioBook, int $index)
    {
        $request->validate([
            'script' => 'nullable|string|min:10|max:5000',
            'image_prompt' => 'nullable|string|min:10|max:5000',
        ]);

        $hasScript = $request->has('script');
        $hasImagePrompt = $request->has('image_prompt');
        if (!$hasScript && !$hasImagePrompt) {
            return response()->json([
                'success' => false,
                'error' => 'Không có dữ liệu cần cập nhật.',
            ], 422);
        }

        $items = $this->loadShortVideoItems($audioBook->id);
        if (empty($items)) {
            return response()->json([
                'success' => false,
                'error' => 'Không có short video để cập nhật.',
            ], 404);
        }

        $updated = false;
        foreach ($items as $key => &$item) {
            $itemIndex = (int)($item['index'] ?? ($key + 1));
            if ($itemIndex !== $index) {
                continue;
            }

            $newScript = $hasScript ? trim((string) $request->input('script')) : trim((string)($item['script'] ?? ''));
            $newImagePrompt = $hasImagePrompt ? trim((string) $request->input('image_prompt')) : trim((string)($item['image_prompt'] ?? ''));

            $scriptChanged = $newScript !== trim((string)($item['script'] ?? ''));
            $imagePromptChanged = $newImagePrompt !== trim((string)($item['image_prompt'] ?? ''));

            $item['script'] = $newScript;
            $item['image_prompt'] = $newImagePrompt;
            $item['status'] = 'planned';
            $item['error_message'] = null;
            $item['updated_at'] = now()->toDateTimeString();

            if ($scriptChanged && !empty($item['audio_path'])) {
                $this->deleteShortVideoAssetFile($item['audio_path']);
                $item['audio_path'] = null;
                $item['duration'] = null;
            }

            if ($scriptChanged) {
                $this->deleteShortVideoShotAssets($item);
                $item['shots'] = [];
                $item['story_bible'] = null;
                $item['character_bible'] = null;
            }

            if (($scriptChanged || $imagePromptChanged) && !empty($item['image_path'])) {
                $this->deleteShortVideoAssetFile($item['image_path']);
                $item['image_path'] = null;
            }

            if (($scriptChanged || $imagePromptChanged) && !empty($item['video_path'])) {
                $this->deleteShortVideoAssetFile($item['video_path']);
                $item['video_path'] = null;
            }

            $updated = true;
            break;
        }
        unset($item);

        if (!$updated) {
            return response()->json([
                'success' => false,
                'error' => 'Không tìm thấy short đã chọn.',
            ], 404);
        }

        $this->saveShortVideoItems($audioBook->id, $items);

        return response()->json([
            'success' => true,
            'updated_index' => $index,
            'items' => array_map(fn($item) => $this->mapShortVideoItemForResponse($audioBook->id, $item), $items),
        ]);
    }

    public function getShortVideoWorkspace(AudioBook $audioBook, int $index)
    {
        $items = $this->loadShortVideoItems($audioBook->id);
        $itemKey = $this->findShortVideoItemIndex($items, $index);

        if ($itemKey === null) {
            return response()->json([
                'success' => false,
                'error' => 'Không tìm thấy short đã chọn.',
            ], 404);
        }

        $item = $items[$itemKey];
        $shots = is_array($item['shots'] ?? null) ? array_values($item['shots']) : [];

        if (!empty($shots)) {
            [$enrichedShots, $translationChanged] = $this->ensureShortShotPromptTranslations($shots, $audioBook->id, $index);
            if ($translationChanged) {
                $item['shots'] = $enrichedShots;
                $item['updated_at'] = now()->toDateTimeString();
                $items[$itemKey] = $item;
                $this->saveShortVideoItems($audioBook->id, $items);
            }
        }

        $item = $this->mapShortVideoItemForResponse($audioBook->id, $item);

        return response()->json([
            'success' => true,
            'item' => $item,
            'workspace_ready' => !empty($item['shots']) && is_array($item['shots']),
        ]);
    }

    public function buildShortVideoWorkspace(Request $request, AudioBook $audioBook, int $index)
    {
        $request->validate([
            'force' => 'nullable|boolean',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        $itemKey = $this->findShortVideoItemIndex($items, $index);

        if ($itemKey === null) {
            return response()->json([
                'success' => false,
                'error' => 'Không tìm thấy short đã chọn.',
            ], 404);
        }

        $item = $items[$itemKey];
        $script = trim((string)($item['script'] ?? ''));
        if ($script === '') {
            return response()->json([
                'success' => false,
                'error' => 'Short chưa có script để tách câu.',
            ], 422);
        }

        $force = (bool) $request->boolean('force', false);
        if ($force) {
            $this->deleteShortVideoShotAssets($item);
        }

        $workspace = $this->buildShortStoryboardData($audioBook, $item, $index);

        $item['shots'] = $workspace['shots'];
        $item['story_bible'] = $workspace['story_bible'];
        $item['character_bible'] = $workspace['character_bible'];
        $item['workspace_updated_at'] = now()->toDateTimeString();
        $item['status'] = 'planned';
        $item['error_message'] = null;
        $item['updated_at'] = now()->toDateTimeString();

        if (!empty($item['video_path'])) {
            $this->deleteShortVideoAssetFile($item['video_path']);
            $item['video_path'] = null;
            $item['duration'] = null;
        }

        $items[$itemKey] = $item;
        $this->saveShortVideoItems($audioBook->id, $items);

        return response()->json([
            'success' => true,
            'item' => $this->mapShortVideoItemForResponse($audioBook->id, $item),
        ]);
    }

    public function updateShortVideoShot(Request $request, AudioBook $audioBook, int $index, int $shotIndex)
    {
        $request->validate([
            'sentence' => 'nullable|string|min:1|max:5000',
            'image_prompt' => 'nullable|string|min:5|max:5000',
            'kling_prompt' => 'nullable|string|min:5|max:5000',
            'reference_shot_indices' => 'nullable|array',
            'reference_shot_indices.*' => 'integer|min:1',
            'is_reference_keyframe' => 'nullable|boolean',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        $itemKey = $this->findShortVideoItemIndex($items, $index);

        if ($itemKey === null) {
            return response()->json([
                'success' => false,
                'error' => 'Không tìm thấy short đã chọn.',
            ], 404);
        }

        $item = $items[$itemKey];
        $shots = is_array($item['shots'] ?? null) ? $item['shots'] : [];
        if (empty($shots)) {
            return response()->json([
                'success' => false,
                'error' => 'Short chưa được tách câu. Hãy bấm "Build Workspace" trước.',
            ], 422);
        }

        $targetKey = null;
        foreach ($shots as $key => $shot) {
            if ((int)($shot['shot_index'] ?? ($key + 1)) === $shotIndex) {
                $targetKey = $key;
                break;
            }
        }

        if ($targetKey === null) {
            return response()->json([
                'success' => false,
                'error' => 'Không tìm thấy câu/shot đã chọn.',
            ], 404);
        }

        $shot = $shots[$targetKey];

        $oldSentence = trim((string)($shot['sentence'] ?? ''));
        $oldImagePrompt = trim((string)($shot['image_prompt'] ?? ''));
        $oldKlingPrompt = trim((string)($shot['kling_prompt'] ?? ''));
        $oldRefIndices = array_values(array_unique(array_filter(array_map('intval', (array)($shot['reference_shot_indices'] ?? [])), fn($val) => $val > 0 && $val !== $shotIndex)));
        sort($oldRefIndices);
        $oldIsReferenceKeyframe = (bool)($shot['is_reference_keyframe'] ?? false);

        if ($request->has('sentence')) {
            $shot['sentence'] = trim((string)$request->input('sentence'));
        }
        if ($request->has('image_prompt')) {
            $shot['image_prompt'] = trim((string)$request->input('image_prompt'));
        }
        if ($request->has('kling_prompt')) {
            $shot['kling_prompt'] = trim((string)$request->input('kling_prompt'));
        }
        if ($request->has('reference_shot_indices')) {
            $refIndices = array_values(array_unique(array_map('intval', (array)$request->input('reference_shot_indices', []))));
            $shot['reference_shot_indices'] = array_values(array_filter($refIndices, fn($val) => $val > 0 && $val !== $shotIndex));
        }
        if ($request->has('is_reference_keyframe')) {
            $shot['is_reference_keyframe'] = (bool) $request->boolean('is_reference_keyframe');
        }

        $newSentence = trim((string)($shot['sentence'] ?? ''));
        $newImagePrompt = trim((string)($shot['image_prompt'] ?? ''));
        $newKlingPrompt = trim((string)($shot['kling_prompt'] ?? ''));
        $newRefIndices = array_values(array_unique(array_filter(array_map('intval', (array)($shot['reference_shot_indices'] ?? [])), fn($val) => $val > 0 && $val !== $shotIndex)));
        sort($newRefIndices);
        $newIsReferenceKeyframe = (bool)($shot['is_reference_keyframe'] ?? false);

        $sentenceChanged = $oldSentence !== $newSentence;
        $visualChanged = $oldImagePrompt !== $newImagePrompt ||
            $oldKlingPrompt !== $newKlingPrompt ||
            $oldRefIndices !== $newRefIndices ||
            $oldIsReferenceKeyframe !== $newIsReferenceKeyframe;

        if ($sentenceChanged && !empty($shot['tts_audio_path'])) {
            $this->deleteShortVideoAssetFile($shot['tts_audio_path']);
            $shot['tts_audio_path'] = null;
            $shot['tts_duration'] = null;
        }

        if ($visualChanged && !empty($shot['image_path'])) {
            $this->deleteShortVideoAssetFile($shot['image_path']);
            $shot['image_path'] = null;
            $shot['resolved_image_prompt'] = null;
        }

        if (($sentenceChanged || $visualChanged) && !empty($shot['kling_video_path'])) {
            $this->deleteShortVideoAssetFile($shot['kling_video_path']);
            $shot['kling_video_path'] = null;
        }

        if (($sentenceChanged || $visualChanged) && !empty($shot['segment_video_path'])) {
            $this->deleteShortVideoAssetFile($shot['segment_video_path']);
            $shot['segment_video_path'] = null;
        }

        if ($sentenceChanged || $visualChanged) {
            $shot['kling_task_id'] = null;
            $shot['kling_status'] = null;
            $shot['error_message'] = null;
        }

        $shot['updated_at'] = now()->toDateTimeString();
        $shots[$targetKey] = $shot;
        $item['shots'] = $shots;

        if (!empty($item['video_path'])) {
            $this->deleteShortVideoAssetFile($item['video_path']);
            $item['video_path'] = null;
            $item['duration'] = null;
        }

        $item['updated_at'] = now()->toDateTimeString();
        $item['status'] = 'planned';
        $items[$itemKey] = $item;
        $this->saveShortVideoItems($audioBook->id, $items);

        return response()->json([
            'success' => true,
            'item' => $this->mapShortVideoItemForResponse($audioBook->id, $item),
        ]);
    }

    public function generateShortVideoShotTts(Request $request, AudioBook $audioBook, int $index)
    {
        $request->validate([
            'selected_shot_indices' => 'nullable|array',
            'selected_shot_indices.*' => 'integer|min:1',
            'provider' => 'nullable|string|in:openai,gemini,microsoft,vbee',
            'voice_name' => 'nullable|string',
            'voice_gender' => 'nullable|string|in:male,female',
            'style_instruction' => 'nullable|string',
            'tts_speed' => 'nullable|numeric|between:0.5,2.0',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        $itemKey = $this->findShortVideoItemIndex($items, $index);

        if ($itemKey === null) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy short đã chọn.'], 404);
        }

        $item = $items[$itemKey];
        $shots = is_array($item['shots'] ?? null) ? $item['shots'] : [];
        if (empty($shots)) {
            return response()->json(['success' => false, 'error' => 'Short chưa được tách câu.'], 422);
        }

        $selectedIndices = $this->getSelectedShotIndices($shots, $request->input('selected_shot_indices'));
        if (empty($selectedIndices)) {
            return response()->json(['success' => false, 'error' => 'Không có câu/shot hợp lệ để tạo TTS.'], 422);
        }

        $ttsOptions = $this->resolveShortVideoTtsOptions($request, $audioBook);
        if (empty($ttsOptions['voice_name'])) {
            return response()->json([
                'success' => false,
                'error' => 'Thiếu voice_name. Vui lòng cấu hình giọng TTS trước.',
            ], 422);
        }

        $dirs = $this->ensureShortVideoAssetDirs($audioBook->id);
        $shotAudioDir = $dirs['shot_audio'];

        $completed = 0;
        $failed = 0;

        foreach ($shots as $key => $shot) {
            $shotNo = (int)($shot['shot_index'] ?? ($key + 1));
            if (!in_array($shotNo, $selectedIndices, true)) {
                continue;
            }

            try {
                $sentence = trim((string)($shot['sentence'] ?? ''));
                if ($sentence === '') {
                    throw new \RuntimeException('Shot chưa có nội dung câu để tạo TTS.');
                }

                $tempAudioRelPath = $this->ttsService->generateAudio(
                    $sentence,
                    ($index * 1000) + $shotNo,
                    $ttsOptions['voice_gender'],
                    $ttsOptions['voice_name'],
                    $ttsOptions['provider'],
                    $ttsOptions['style_instruction'],
                    null,
                    $ttsOptions['tts_speed']
                );

                $tempAudioAbsPath = storage_path('app/' . $tempAudioRelPath);
                if (!file_exists($tempAudioAbsPath)) {
                    throw new \RuntimeException('Không tìm thấy file audio tạm từ TTS.');
                }

                if (!empty($shot['tts_audio_path'])) {
                    $this->deleteShortVideoAssetFile($shot['tts_audio_path']);
                }

                if (!empty($shot['segment_video_path'])) {
                    $this->deleteShortVideoAssetFile($shot['segment_video_path']);
                    $shot['segment_video_path'] = null;
                }

                $audioFileName = 'short_' . $index . '_shot_' . $shotNo . '_' . time() . '.mp3';
                $audioAbsPath = $shotAudioDir . '/' . $audioFileName;
                copy($tempAudioAbsPath, $audioAbsPath);
                @unlink($tempAudioAbsPath);

                $audioDuration = (float)($this->getAudioDuration($audioAbsPath) ?? 0.0);
                if ($audioDuration <= 0) {
                    $audioDuration = 3.0;
                }

                $shot['tts_audio_path'] = 'books/' . $audioBook->id . '/short_videos/shots/audio/' . $audioFileName;
                $shot['tts_duration'] = round($audioDuration, 3);
                $shot['error_message'] = null;
                $shot['updated_at'] = now()->toDateTimeString();
                $completed++;
            } catch (\Throwable $e) {
                $shot['error_message'] = $e->getMessage();
                $shot['updated_at'] = now()->toDateTimeString();
                $failed++;
            }

            $shots[$key] = $shot;
        }

        $item['shots'] = $shots;
        if (!empty($item['video_path'])) {
            $this->deleteShortVideoAssetFile($item['video_path']);
            $item['video_path'] = null;
            $item['duration'] = null;
        }
        $item['status'] = 'planned';
        $item['updated_at'] = now()->toDateTimeString();

        $items[$itemKey] = $item;
        $this->saveShortVideoItems($audioBook->id, $items);

        return response()->json([
            'success' => true,
            'completed' => $completed,
            'failed' => $failed,
            'item' => $this->mapShortVideoItemForResponse($audioBook->id, $item),
        ]);
    }

    public function generateShortVideoShotImages(Request $request, AudioBook $audioBook, int $index)
    {
        $request->validate([
            'selected_shot_indices' => 'nullable|array',
            'selected_shot_indices.*' => 'integer|min:1',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        $itemKey = $this->findShortVideoItemIndex($items, $index);

        if ($itemKey === null) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy short đã chọn.'], 404);
        }

        $item = $items[$itemKey];
        $shots = is_array($item['shots'] ?? null) ? $item['shots'] : [];
        if (empty($shots)) {
            return response()->json(['success' => false, 'error' => 'Short chưa được tách câu.'], 422);
        }

        $selectedIndices = $this->getSelectedShotIndices($shots, $request->input('selected_shot_indices'));
        if (empty($selectedIndices)) {
            return response()->json(['success' => false, 'error' => 'Không có câu/shot hợp lệ để tạo ảnh.'], 422);
        }

        $dirs = $this->ensureShortVideoAssetDirs($audioBook->id);
        $shotImageDir = $dirs['shot_images'];

        $completed = 0;
        $failed = 0;

        foreach ($shots as $key => $shot) {
            $shotNo = (int)($shot['shot_index'] ?? ($key + 1));
            if (!in_array($shotNo, $selectedIndices, true)) {
                continue;
            }

            try {
                $imagePrompt = trim((string)($shot['image_prompt'] ?? ''));
                if ($imagePrompt === '') {
                    throw new \RuntimeException('Shot chưa có image_prompt.');
                }

                if (!empty($shot['image_path'])) {
                    $this->deleteShortVideoAssetFile($shot['image_path']);
                }

                if (!empty($shot['kling_video_path'])) {
                    $this->deleteShortVideoAssetFile($shot['kling_video_path']);
                    $shot['kling_video_path'] = null;
                }

                if (!empty($shot['segment_video_path'])) {
                    $this->deleteShortVideoAssetFile($shot['segment_video_path']);
                    $shot['segment_video_path'] = null;
                }

                $finalPrompt = $this->buildShortShotImagePromptWithContinuity($item, $shots, $shot);

                $imageFileName = 'short_' . $index . '_shot_' . $shotNo . '_' . time() . '.png';
                $imageAbsPath = $shotImageDir . '/' . $imageFileName;

                $imgResult = $this->imageService->generateShortVerticalImage($finalPrompt, $imageAbsPath);
                if (empty($imgResult['success'])) {
                    $imgError = trim((string)($imgResult['error'] ?? ''));
                    throw new \RuntimeException($imgError !== '' ? $imgError : 'Không thể tạo ảnh minh họa.');
                }

                $shot['image_path'] = 'books/' . $audioBook->id . '/short_videos/shots/images/' . $imageFileName;
                $shot['resolved_image_prompt'] = $finalPrompt;
                $shot['kling_task_id'] = null;
                $shot['kling_status'] = null;
                $shot['error_message'] = null;
                $shot['updated_at'] = now()->toDateTimeString();
                $completed++;
            } catch (\Throwable $e) {
                $imgError = trim((string)$e->getMessage());
                $shot['error_message'] = $imgError !== '' ? $imgError : 'Tạo ảnh thất bại (không có thông báo lỗi chi tiết).';
                $shot['updated_at'] = now()->toDateTimeString();
                $failed++;
            }

            $shots[$key] = $shot;
        }

        $item['shots'] = $shots;
        if (!empty($item['video_path'])) {
            $this->deleteShortVideoAssetFile($item['video_path']);
            $item['video_path'] = null;
            $item['duration'] = null;
        }
        $item['status'] = 'planned';
        $item['updated_at'] = now()->toDateTimeString();

        $items[$itemKey] = $item;
        $this->saveShortVideoItems($audioBook->id, $items);

        return response()->json([
            'success' => true,
            'completed' => $completed,
            'failed' => $failed,
            'item' => $this->mapShortVideoItemForResponse($audioBook->id, $item),
        ]);
    }

    public function startShortVideoShotKling(Request $request, AudioBook $audioBook, int $index)
    {
        $request->validate([
            'selected_shot_indices' => 'nullable|array',
            'selected_shot_indices.*' => 'integer|min:1',
            'duration' => 'nullable|in:5,10',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        $itemKey = $this->findShortVideoItemIndex($items, $index);

        if ($itemKey === null) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy short đã chọn.'], 404);
        }

        $item = $items[$itemKey];
        $shots = is_array($item['shots'] ?? null) ? $item['shots'] : [];
        if (empty($shots)) {
            return response()->json(['success' => false, 'error' => 'Short chưa được tách câu.'], 422);
        }

        $selectedIndices = $this->getSelectedShotIndices($shots, $request->input('selected_shot_indices'));
        if (empty($selectedIndices)) {
            return response()->json(['success' => false, 'error' => 'Không có câu/shot hợp lệ để chạy Kling.'], 422);
        }

        $duration = (string)($request->input('duration', '5'));
        $queued = 0;
        $failed = 0;

        foreach ($shots as $key => $shot) {
            $shotNo = (int)($shot['shot_index'] ?? ($key + 1));
            if (!in_array($shotNo, $selectedIndices, true)) {
                continue;
            }

            try {
                if (empty($shot['image_path'])) {
                    throw new \RuntimeException('Shot chưa có ảnh để chạy Kling.');
                }

                $klingPrompt = trim((string)($shot['kling_prompt'] ?? ''));
                if ($klingPrompt === '') {
                    $klingPrompt = 'Cinematic motion with consistent characters, smooth camera move, natural facial and cloth movement, no sudden jump cuts.';
                }

                $result = $this->klingService->createImageToVideoTask(
                    $shot['image_path'],
                    $klingPrompt,
                    ['duration' => $duration]
                );

                if (empty($result['success']) || empty($result['task_id'])) {
                    $klingError = trim((string)($result['error'] ?? ''));
                    throw new \RuntimeException($klingError !== '' ? $klingError : 'Không thể tạo task Kling.');
                }

                $shot['kling_task_id'] = $result['task_id'];
                $shot['kling_status'] = 'queued';
                $shot['error_message'] = null;
                $shot['updated_at'] = now()->toDateTimeString();
                $queued++;
            } catch (\Throwable $e) {
                $klingError = trim((string)$e->getMessage());
                $shot['error_message'] = $klingError !== '' ? $klingError : 'Chạy Kling thất bại (không có thông báo lỗi chi tiết).';
                $shot['kling_status'] = 'failed';
                $shot['updated_at'] = now()->toDateTimeString();
                $failed++;
            }

            $shots[$key] = $shot;
        }

        $item['shots'] = $shots;
        $item['updated_at'] = now()->toDateTimeString();
        $items[$itemKey] = $item;
        $this->saveShortVideoItems($audioBook->id, $items);

        return response()->json([
            'success' => true,
            'queued' => $queued,
            'failed' => $failed,
            'item' => $this->mapShortVideoItemForResponse($audioBook->id, $item),
        ]);
    }

    public function pollShortVideoShotKling(Request $request, AudioBook $audioBook, int $index)
    {
        $request->validate([
            'selected_shot_indices' => 'nullable|array',
            'selected_shot_indices.*' => 'integer|min:1',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        $itemKey = $this->findShortVideoItemIndex($items, $index);

        if ($itemKey === null) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy short đã chọn.'], 404);
        }

        $item = $items[$itemKey];
        $shots = is_array($item['shots'] ?? null) ? $item['shots'] : [];
        if (empty($shots)) {
            return response()->json(['success' => false, 'error' => 'Short chưa được tách câu.'], 422);
        }

        $selectedIndices = $this->getSelectedShotIndices($shots, $request->input('selected_shot_indices'));
        if (empty($selectedIndices)) {
            return response()->json(['success' => false, 'error' => 'Không có câu/shot hợp lệ để kiểm tra Kling.'], 422);
        }

        $completed = 0;
        $failed = 0;
        $processing = 0;

        foreach ($shots as $key => $shot) {
            $shotNo = (int)($shot['shot_index'] ?? ($key + 1));
            if (!in_array($shotNo, $selectedIndices, true)) {
                continue;
            }
            if (empty($shot['kling_task_id'])) {
                continue;
            }

            try {
                $statusResult = $this->klingService->getTaskStatus((string)$shot['kling_task_id']);
                if (empty($statusResult['success'])) {
                    throw new \RuntimeException($statusResult['error'] ?? 'Không lấy được trạng thái Kling.');
                }

                $status = (string)($statusResult['status'] ?? 'processing');
                $shot['kling_status'] = $status;

                if ($status === 'completed' && !empty($statusResult['video_url'])) {
                    if (!empty($shot['kling_video_path'])) {
                        $this->deleteShortVideoAssetFile($shot['kling_video_path']);
                    }

                    $videoFileName = 'short_' . $index . '_shot_' . $shotNo . '_kling_' . time() . '.mp4';
                    $relativePath = 'books/' . $audioBook->id . '/short_videos/shots/videos/' . $videoFileName;
                    $this->downloadVideoToPublicStorage((string)$statusResult['video_url'], $relativePath);

                    $shot['kling_video_path'] = $relativePath;
                    $shot['error_message'] = null;
                    $completed++;
                } elseif ($status === 'failed') {
                    $statusError = trim((string)($statusResult['error'] ?? ''));
                    $shot['error_message'] = $statusError !== '' ? $statusError : 'Kling xử lý thất bại.';
                    $failed++;
                } else {
                    $processing++;
                }

                $shot['updated_at'] = now()->toDateTimeString();
            } catch (\Throwable $e) {
                $shot['kling_status'] = 'failed';
                $pollError = trim((string)$e->getMessage());
                $shot['error_message'] = $pollError !== '' ? $pollError : 'Poll Kling thất bại (không có thông báo lỗi chi tiết).';
                $shot['updated_at'] = now()->toDateTimeString();
                $failed++;
            }

            $shots[$key] = $shot;
        }

        $item['shots'] = $shots;
        $item['updated_at'] = now()->toDateTimeString();
        $items[$itemKey] = $item;
        $this->saveShortVideoItems($audioBook->id, $items);

        return response()->json([
            'success' => true,
            'completed' => $completed,
            'processing' => $processing,
            'failed' => $failed,
            'item' => $this->mapShortVideoItemForResponse($audioBook->id, $item),
        ]);
    }

    public function composeShortVideoFromShots(Request $request, AudioBook $audioBook, int $index)
    {
        $request->validate([
            'selected_shot_indices' => 'nullable|array',
            'selected_shot_indices.*' => 'integer|min:1',
        ]);

        $items = $this->loadShortVideoItems($audioBook->id);
        $itemKey = $this->findShortVideoItemIndex($items, $index);
        if ($itemKey === null) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy short đã chọn.'], 404);
        }

        $item = $items[$itemKey];
        $shots = is_array($item['shots'] ?? null) ? $item['shots'] : [];
        if (empty($shots)) {
            return response()->json(['success' => false, 'error' => 'Short chưa có shots để ghép video.'], 422);
        }

        $selectedIndices = $this->getSelectedShotIndices($shots, $request->input('selected_shot_indices'));
        if (empty($selectedIndices)) {
            return response()->json(['success' => false, 'error' => 'Không có shot hợp lệ để ghép video.'], 422);
        }

        $dirs = $this->ensureShortVideoAssetDirs($audioBook->id);
        $ffmpegPath = config('services.ffmpeg.path', env('FFMPEG_PATH', 'ffmpeg'));

        $tempDir = storage_path('app/temp/short_compose_' . $audioBook->id . '_' . $index . '_' . time());
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $segmentPaths = [];
        $totalDuration = 0.0;

        try {
            foreach ($selectedIndices as $shotNo) {
                $shotKey = null;
                foreach ($shots as $key => $shot) {
                    if ((int)($shot['shot_index'] ?? ($key + 1)) === $shotNo) {
                        $shotKey = $key;
                        break;
                    }
                }

                if ($shotKey === null) {
                    continue;
                }

                $shot = $shots[$shotKey];
                $audioRel = (string)($shot['tts_audio_path'] ?? '');
                if ($audioRel === '') {
                    throw new \RuntimeException('Shot #' . $shotNo . ' chưa có TTS.');
                }

                $audioAbs = storage_path('app/public/' . ltrim($audioRel, '/'));
                if (!file_exists($audioAbs)) {
                    throw new \RuntimeException('Không tìm thấy audio của shot #' . $shotNo . '.');
                }

                $duration = (float)($shot['tts_duration'] ?? 0.0);
                if ($duration <= 0) {
                    $duration = (float)($this->getAudioDuration($audioAbs) ?? 0.0);
                }
                if ($duration <= 0) {
                    $duration = 3.0;
                }

                $segmentFileName = 'short_' . $index . '_shot_' . $shotNo . '_segment_' . time() . '.mp4';
                $segmentRelPath = 'books/' . $audioBook->id . '/short_videos/shots/videos/' . $segmentFileName;
                $segmentAbsPath = storage_path('app/public/' . $segmentRelPath);

                $segmentDir = dirname($segmentAbsPath);
                if (!is_dir($segmentDir)) {
                    mkdir($segmentDir, 0775, true);
                }

                if (!empty($shot['segment_video_path'])) {
                    $this->deleteShortVideoAssetFile($shot['segment_video_path']);
                }

                $sourceVideoAbs = null;
                if (!empty($shot['kling_video_path'])) {
                    $klingVideoAbs = storage_path('app/public/' . ltrim((string)$shot['kling_video_path'], '/'));
                    if (file_exists($klingVideoAbs)) {
                        $sourceVideoAbs = $klingVideoAbs;
                    }
                }

                $sourceImageAbs = null;
                if (!$sourceVideoAbs && !empty($shot['image_path'])) {
                    $imageAbs = storage_path('app/public/' . ltrim((string)$shot['image_path'], '/'));
                    if (file_exists($imageAbs)) {
                        $sourceImageAbs = $imageAbs;
                    }
                }

                if (!$sourceVideoAbs && !$sourceImageAbs) {
                    throw new \RuntimeException('Shot #' . $shotNo . ' chưa có Kling video hoặc ảnh để tạo segment.');
                }

                $this->composeShortShotSegment(
                    $ffmpegPath,
                    $sourceVideoAbs,
                    $sourceImageAbs,
                    $audioAbs,
                    $duration,
                    $segmentAbsPath
                );

                $shot['segment_video_path'] = $segmentRelPath;
                $shot['updated_at'] = now()->toDateTimeString();
                $shots[$shotKey] = $shot;

                $segmentPaths[] = $segmentAbsPath;
                $totalDuration += $duration;
            }

            if (empty($segmentPaths)) {
                throw new \RuntimeException('Không tạo được segment nào để ghép.');
            }

            if (!empty($item['video_path'])) {
                $this->deleteShortVideoAssetFile($item['video_path']);
            }

            $outputFileName = 'short_' . $index . '_story_' . time() . '.mp4';
            $outputRelPath = 'books/' . $audioBook->id . '/short_videos/videos/' . $outputFileName;
            $outputAbsPath = storage_path('app/public/' . $outputRelPath);

            $listPath = $tempDir . '/concat_list.txt';
            $listContent = '';
            foreach ($segmentPaths as $segPath) {
                $escapedPath = str_replace("'", "'\\''", $segPath);
                $listContent .= "file '{$escapedPath}'\n";
            }
            file_put_contents($listPath, $listContent);

            $concatCmd = sprintf(
                '%s -y -f concat -safe 0 -i %s -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 192k %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($listPath),
                escapeshellarg($outputAbsPath)
            );
            $this->runFfmpegCommand($concatCmd, 'Ghép các segment thành video full short thất bại.');

            $item['shots'] = $shots;
            $item['video_path'] = $outputRelPath;
            $item['duration'] = round($totalDuration, 3);
            $item['status'] = 'completed';
            $item['error_message'] = null;
            $item['updated_at'] = now()->toDateTimeString();

            $items[$itemKey] = $item;
            $this->saveShortVideoItems($audioBook->id, $items);

            return response()->json([
                'success' => true,
                'video_path' => $outputRelPath,
                'video_url' => asset('storage/' . $outputRelPath),
                'duration' => $item['duration'],
                'item' => $this->mapShortVideoItemForResponse($audioBook->id, $item),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        } finally {
            if (is_dir($tempDir)) {
                $files = glob($tempDir . '/*') ?: [];
                foreach ($files as $file) {
                    @unlink($file);
                }
                @rmdir($tempDir);
            }
        }
    }

    public function downloadShortVideoWorkspacePackage(Request $request, AudioBook $audioBook, int $index)
    {
        $request->validate([
            'dry_run' => 'nullable|boolean',
            'allow_missing' => 'nullable|boolean',
        ]);

        $dryRun = (bool)$request->boolean('dry_run', false);
        $allowMissing = (bool)$request->boolean('allow_missing', false);

        $items = $this->loadShortVideoItems($audioBook->id);
        $itemKey = $this->findShortVideoItemIndex($items, $index);
        if ($itemKey === null) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy short đã chọn.'], 404);
        }

        $item = $items[$itemKey];
        $shots = is_array($item['shots'] ?? null) ? $item['shots'] : [];
        if (empty($shots)) {
            return response()->json(['success' => false, 'error' => 'Short chưa có workspace để tải.'], 422);
        }

        $bookTitle = trim((string)($audioBook->title ?? ('book_' . $audioBook->id)));
        $bookBaseName = preg_replace('/[^\pL\pN]+/u', '_', $bookTitle);
        $bookBaseName = trim((string)$bookBaseName, '_');
        if ($bookBaseName === '') {
            $bookBaseName = 'book_' . $audioBook->id;
        }

        $orderedShots = array_values($shots);
        usort($orderedShots, function ($left, $right) {
            $leftNo = (int)($left['shot_index'] ?? 0);
            $rightNo = (int)($right['shot_index'] ?? 0);
            return $leftNo <=> $rightNo;
        });

        $packableEntries = [];
        $missingByShot = [];

        foreach ($orderedShots as $key => $shot) {
            $shotNo = (int)($shot['shot_index'] ?? ($key + 1));
            if ($shotNo <= 0) {
                continue;
            }

            $shotNoPadded = str_pad((string)$shotNo, 2, '0', STR_PAD_LEFT);
            $shotPrefix = $bookBaseName . '_shot_' . $shotNoPadded;

            $videoPath = trim((string)($shot['segment_video_path'] ?? ''));
            if ($videoPath === '') {
                $videoPath = trim((string)($shot['kling_video_path'] ?? ''));
            }

            $assetMap = [
                [
                    'type' => 'audio',
                    'relative_path' => trim((string)($shot['tts_audio_path'] ?? '')),
                    'suffix' => 'audio',
                    'default_ext' => 'mp3',
                ],
                [
                    'type' => 'image',
                    'relative_path' => trim((string)($shot['image_path'] ?? '')),
                    'suffix' => 'image',
                    'default_ext' => 'png',
                ],
                [
                    'type' => 'video',
                    'relative_path' => $videoPath,
                    'suffix' => 'video',
                    'default_ext' => 'mp4',
                ],
            ];

            $missingTypes = [];

            foreach ($assetMap as $asset) {
                $relativePath = (string)($asset['relative_path'] ?? '');
                if ($relativePath === '') {
                    $missingTypes[] = (string)$asset['type'];
                    continue;
                }

                $absPath = storage_path('app/public/' . ltrim($relativePath, '/'));
                if (!is_file($absPath)) {
                    $missingTypes[] = (string)$asset['type'];
                    continue;
                }

                $ext = strtolower((string)pathinfo($absPath, PATHINFO_EXTENSION));
                if ($ext === '') {
                    $ext = (string)($asset['default_ext'] ?? 'dat');
                }

                $zipEntryName = $bookBaseName . '/' . $shotPrefix . '_' . (string)$asset['suffix'] . '.' . $ext;
                $packableEntries[] = [
                    'abs_path' => $absPath,
                    'zip_entry_name' => $zipEntryName,
                    'shot_index' => $shotNo,
                    'type' => (string)$asset['type'],
                ];
            }

            if (!empty($missingTypes)) {
                $missingByShot[] = [
                    'shot_index' => $shotNo,
                    'missing_types' => array_values(array_unique($missingTypes)),
                ];
            }
        }

        $availableFilesCount = count($packableEntries);
        $missingShotCount = count($missingByShot);

        if ($dryRun) {
            return response()->json([
                'success' => true,
                'ready_to_download' => $missingShotCount === 0,
                'total_shots' => count($orderedShots),
                'available_files_count' => $availableFilesCount,
                'missing_count' => $missingShotCount,
                'missing_files' => $missingByShot,
                'book_folder' => $bookBaseName,
            ]);
        }

        if ($missingShotCount > 0 && !$allowMissing) {
            return response()->json([
                'success' => false,
                'error' => 'Một số shot đang thiếu file audio/image/video. Vui lòng xác nhận trước khi tải.',
                'missing_count' => $missingShotCount,
                'missing_files' => $missingByShot,
            ], 422);
        }

        if ($availableFilesCount === 0) {
            return response()->json([
                'success' => false,
                'error' => 'Không có file audio/image/mp4 hợp lệ để đóng gói.',
            ], 422);
        }

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $zipFileName = $bookBaseName . '_short_' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . '_capcut_' . now()->format('Ymd_His') . '.zip';
        $zipPath = $tmpDir . '/' . $zipFileName;

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            return response()->json([
                'success' => false,
                'error' => 'Không thể tạo file ZIP.',
            ], 500);
        }

        foreach ($packableEntries as $entry) {
            $zip->addFile((string)$entry['abs_path'], (string)$entry['zip_entry_name']);
        }

        $zip->close();

        return response()->download($zipPath, $zipFileName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    private function findShortVideoItemIndex(array $items, int $targetIndex): ?int
    {
        foreach ($items as $key => $item) {
            $itemIndex = (int)($item['index'] ?? ($key + 1));
            if ($itemIndex === $targetIndex) {
                return $key;
            }
        }

        return null;
    }

    private function buildShortStoryboardData(AudioBook $audioBook, array $item, int $shortIndex): array
    {
        $script = trim((string)($item['script'] ?? ''));
        $sentences = $this->splitShortScriptIntoSentences($script);
        if (empty($sentences)) {
            throw new \RuntimeException('Không thể tách câu từ script short.');
        }

        $storyboard = $this->buildShortStoryboardWithAi($audioBook, $item, $shortIndex, $sentences);
        if ($storyboard === null) {
            $storyboard = $this->buildShortStoryboardFallback($audioBook, $item, $shortIndex, $sentences);
        }

        $shots = [];
        foreach ($sentences as $i => $sentence) {
            $rawShot = $storyboard['shots'][$i] ?? [];
            $shots[] = $this->normalizeShortShotData($rawShot, $i + 1, $sentence);
        }

        [$shots] = $this->ensureShortShotPromptTranslations($shots, $audioBook->id, $shortIndex);

        return [
            'story_bible' => trim((string)($storyboard['story_bible'] ?? '')),
            'character_bible' => trim((string)($storyboard['character_bible'] ?? '')),
            'shots' => $shots,
        ];
    }

    private function splitShortScriptIntoSentences(string $script): array
    {
        $normalizedScript = str_replace(["\r\n", "\r"], "\n", trim($script));
        $normalizedScript = preg_replace('/[ \t]+/u', ' ', $normalizedScript);
        $normalizedScript = preg_replace('/\n{2,}/u', "\n", (string)$normalizedScript);
        $normalizedScript = trim((string)$normalizedScript);

        if ($normalizedScript === '') {
            return [];
        }

        $sentences = [];
        $appendSentence = function (string $candidate) use (&$sentences): void {
            $sentence = trim($candidate);
            if ($sentence === '') {
                return;
            }

            $lastIndex = count($sentences) - 1;
            if (mb_strlen($sentence) < 20 && $lastIndex >= 0) {
                $sentences[$lastIndex] = trim($sentences[$lastIndex] . ' ' . $sentence);
                return;
            }

            $sentences[] = $sentence;
        };

        $parts = preg_split('/(?<=[\.\!\?…;])\s+|\n+/u', $normalizedScript) ?: [];
        foreach ($parts as $part) {
            $appendSentence((string)$part);
        }

        if (count($sentences) === 1) {
            $commaParts = preg_split('/(?<=[,;:])\s+/u', $sentences[0]) ?: [];
            if (count($commaParts) > 1) {
                $sentences = [];
                foreach ($commaParts as $part) {
                    $appendSentence((string)$part);
                }
            }
        }

        if (count($sentences) === 1) {
            $single = trim((string)$sentences[0]);
            $words = preg_split('/\s+/u', $single, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($words) > 28) {
                $chunkSize = 18;
                $chunks = [];
                $current = [];

                foreach ($words as $word) {
                    $current[] = $word;
                    if (count($current) >= $chunkSize) {
                        $chunks[] = $current;
                        $current = [];
                    }
                }

                if (!empty($current)) {
                    if (count($current) < 7 && !empty($chunks)) {
                        $chunks[count($chunks) - 1] = array_merge($chunks[count($chunks) - 1], $current);
                    } else {
                        $chunks[] = $current;
                    }
                }

                $sentences = [];
                foreach ($chunks as $chunkWords) {
                    $appendSentence(implode(' ', $chunkWords));
                }
            }
        }

        if (empty($sentences)) {
            $sentences[] = preg_replace('/\s+/u', ' ', str_replace("\n", ' ', $normalizedScript));
        }

        return $sentences;
    }

    private function buildShortStoryboardWithAi(AudioBook $audioBook, array $item, int $shortIndex, array $sentences): ?array
    {
        $apiKey = config('services.gemini.api_key') ?: config('services.gemini.tts_api_key');
        if (empty($apiKey)) {
            return null;
        }

        [$bookTitle, $channelName] = $this->resolveShortVideoContextNames($audioBook);
        $style = trim((string)($item['style'] ?? 'Cinematic'));
        $sentenceList = [];
        foreach ($sentences as $i => $sentence) {
            $sentenceList[] = ($i + 1) . '. ' . $sentence;
        }

        $prompt = "Bạn là đạo diễn storyboard cho short video dọc 9:16.\n";
        $prompt .= "Mục tiêu: mỗi câu thoại = 1 frame/shot, nhưng các frame phải giữ cùng cốt truyện và nhân vật nhất quán.\n\n";
        $prompt .= "Thông tin:\n";
        $prompt .= "- Sách: {$bookTitle}\n";
        $prompt .= "- Kênh: {$channelName}\n";
        $prompt .= "- Short #{$shortIndex}\n";
        $prompt .= "- Phong cách: {$style}\n\n";
        $prompt .= "Danh sách câu thoại (theo thứ tự thời gian):\n" . implode("\n", $sentenceList) . "\n\n";
        $prompt .= "YÊU CẦU BẮT BUỘC:\n";
        $prompt .= "1) Trả về đúng JSON object, không markdown/code fence.\n";
        $prompt .= "2) Có 3 key gốc: story_bible, character_bible, shots.\n";
        $prompt .= "3) shots là array có đúng " . count($sentences) . " phần tử, mỗi phần tử gồm:\n";
        $prompt .= "   - sentence\n";
        $prompt .= "   - image_prompt (English, dùng để tạo ảnh 9:16, nhấn mạnh continuity nhân vật/cảnh)\n";
        $prompt .= "   - image_prompt_vi (Tiếng Việt, diễn giải nghĩa của image_prompt để người vận hành dễ kiểm tra)\n";
        $prompt .= "   - kling_prompt (English, dùng cho image-to-video motion)\n";
        $prompt .= "   - is_reference_keyframe (boolean)\n";
        $prompt .= "4) Nhân vật phải đồng nhất ngoại hình/trang phục/độ tuổi giữa các shots.\n";
        $prompt .= "5) Không thêm chữ vào ảnh.\n";

        try {
            $resp = Http::timeout(120)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}", [
                    'contents' => [[
                        'parts' => [['text' => $prompt]],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 8192,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if (!$resp->ok()) {
                return null;
            }

            $text = trim((string)data_get($resp->json(), 'candidates.0.content.parts.0.text', ''));
            if ($text === '') {
                return null;
            }

            $decoded = json_decode($text, true);
            if (!is_array($decoded)) {
                $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $text);
                $cleaned = preg_replace('/\s*```\s*$/', '', (string)$cleaned);
                $decoded = json_decode(trim((string)$cleaned), true);
            }

            if (!is_array($decoded) || !is_array($decoded['shots'] ?? null)) {
                return null;
            }

            $storyBibleRaw = $decoded['story_bible'] ?? '';
            $characterBibleRaw = $decoded['character_bible'] ?? '';

            return [
                'story_bible' => is_scalar($storyBibleRaw) ? (string)$storyBibleRaw : json_encode($storyBibleRaw, JSON_UNESCAPED_UNICODE),
                'character_bible' => is_scalar($characterBibleRaw) ? (string)$characterBibleRaw : json_encode($characterBibleRaw, JSON_UNESCAPED_UNICODE),
                'shots' => array_values((array)$decoded['shots']),
            ];
        } catch (\Throwable $e) {
            Log::warning('Short workspace AI storyboard fallback', [
                'audio_book_id' => $audioBook->id,
                'short_index' => $shortIndex,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildShortStoryboardFallback(AudioBook $audioBook, array $item, int $shortIndex, array $sentences): array
    {
        $style = trim((string)($item['style'] ?? 'Cinematic'));
        [$bookTitle] = $this->resolveShortVideoContextNames($audioBook);

        $shots = [];
        foreach ($sentences as $i => $sentence) {
            $shots[] = [
                'sentence' => $sentence,
                'image_prompt' => 'Vertical 9:16 cinematic frame, ' . $style . ' style. Keep the same protagonist identity, face and outfit as previous frames. Scene: ' . $sentence . '. No text, no logo, high detail.',
                'image_prompt_vi' => 'Khung hình điện ảnh dọc 9:16 theo phong cách ' . $style . ', giữ nhất quán nhân vật chính (khuôn mặt, trang phục) so với các khung trước. Cảnh: ' . $sentence . '. Không có chữ, không logo, chi tiết cao.',
                'kling_prompt' => 'Smooth cinematic motion, subtle camera movement, preserve exact same characters and scene continuity, no jump cut.',
                'is_reference_keyframe' => $i === 0,
            ];
        }

        return [
            'story_bible' => 'Một short gồm nhiều câu theo trình tự thời gian, giữ nhịp kể liền mạch từ mở đầu đến kết.',
            'character_bible' => 'Nhân vật trung tâm phải giữ nhất quán về khuôn mặt, độ tuổi, giới tính, tóc và trang phục trong mọi shot.',
            'shots' => $shots,
        ];
    }

    private function normalizeShortShotData(array $rawShot, int $shotIndex, string $fallbackSentence): array
    {
        $refIndices = array_values(array_unique(array_filter(array_map('intval', (array)($rawShot['reference_shot_indices'] ?? [])), fn($val) => $val > 0 && $val !== $shotIndex)));

        return [
            'shot_index' => $shotIndex,
            'sentence' => trim((string)($rawShot['sentence'] ?? $fallbackSentence)),
            'image_prompt' => trim((string)($rawShot['image_prompt'] ?? '')),
            'image_prompt_vi' => trim((string)($rawShot['image_prompt_vi'] ?? ($rawShot['image_prompt_vn'] ?? ''))),
            'kling_prompt' => trim((string)($rawShot['kling_prompt'] ?? '')),
            'is_reference_keyframe' => (bool)($rawShot['is_reference_keyframe'] ?? ($shotIndex === 1)),
            'reference_shot_indices' => $refIndices,
            'tts_audio_path' => $rawShot['tts_audio_path'] ?? null,
            'tts_duration' => $rawShot['tts_duration'] ?? null,
            'image_path' => $rawShot['image_path'] ?? null,
            'kling_task_id' => $rawShot['kling_task_id'] ?? null,
            'kling_status' => $rawShot['kling_status'] ?? null,
            'kling_video_path' => $rawShot['kling_video_path'] ?? null,
            'segment_video_path' => $rawShot['segment_video_path'] ?? null,
            'resolved_image_prompt' => $rawShot['resolved_image_prompt'] ?? null,
            'error_message' => $rawShot['error_message'] ?? null,
            'updated_at' => $rawShot['updated_at'] ?? now()->toDateTimeString(),
        ];
    }

    private function ensureShortShotPromptTranslations(array $shots, int $audioBookId, int $shortIndex): array
    {
        $normalizedShots = $shots;
        $pendingRows = [];

        foreach ($normalizedShots as $key => $shot) {
            $existingTranslation = trim((string)($shot['image_prompt_vi'] ?? ($shot['image_prompt_vn'] ?? '')));
            if ($existingTranslation !== '') {
                $normalizedShots[$key]['image_prompt_vi'] = $existingTranslation;
                continue;
            }

            $imagePrompt = trim((string)($shot['image_prompt'] ?? ''));
            if ($imagePrompt === '') {
                continue;
            }

            $pendingRows[] = [
                'key' => $key,
                'shot_index' => (int)($shot['shot_index'] ?? ($key + 1)),
                'sentence' => trim((string)($shot['sentence'] ?? '')),
                'image_prompt' => $imagePrompt,
            ];
        }

        if (empty($pendingRows)) {
            return [$normalizedShots, false];
        }

        $translatedMap = [];
        foreach (array_chunk($pendingRows, 20) as $pendingChunk) {
            $chunkMap = $this->translateShortShotPromptBatch($pendingChunk, $audioBookId, $shortIndex);
            if (empty($chunkMap)) {
                continue;
            }

            foreach ($chunkMap as $shotNo => $translatedText) {
                $translatedMap[$shotNo] = $translatedText;
            }
        }

        if (empty($translatedMap)) {
            return [$normalizedShots, false];
        }

        $changed = false;
        foreach ($pendingRows as $row) {
            $translatedText = trim((string)($translatedMap[$row['shot_index']] ?? ''));
            if ($translatedText === '') {
                continue;
            }

            if (($normalizedShots[$row['key']]['image_prompt_vi'] ?? '') !== $translatedText) {
                $normalizedShots[$row['key']]['image_prompt_vi'] = $translatedText;
                $changed = true;
            }
        }

        return [$normalizedShots, $changed];
    }

    private function translateShortShotPromptBatch(array $pendingRows, int $audioBookId, int $shortIndex): array
    {
        $apiKey = config('services.gemini.api_key') ?: config('services.gemini.tts_api_key');
        if (empty($apiKey)) {
            return [];
        }

        $payloadRows = array_map(function ($row) {
            return [
                'shot_index' => (int)($row['shot_index'] ?? 0),
                'sentence' => (string)($row['sentence'] ?? ''),
                'image_prompt' => (string)($row['image_prompt'] ?? ''),
            ];
        }, $pendingRows);

        $prompt = "Bạn là trợ lý dịch prompt tạo ảnh cho video short.\n";
        $prompt .= "Nhiệm vụ: dịch image_prompt tiếng Anh sang tiếng Việt để người vận hành hiểu nghĩa, KHÔNG sáng tác thêm nội dung ngoài prompt gốc.\n";
        $prompt .= "Giữ nguyên các chi tiết kỹ thuật quan trọng (9:16, ánh sáng, trang phục, continuity...).\n";
        $prompt .= "Trả về JSON array thuần (không markdown/code fence), mỗi phần tử gồm: shot_index, image_prompt_vi.\n";
        $prompt .= "Dữ liệu cần dịch:\n";
        $prompt .= json_encode($payloadRows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $resp = Http::timeout(120)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}", [
                    'contents' => [[
                        'parts' => [['text' => $prompt]],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 4096,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if (!$resp->ok()) {
                return [];
            }

            $text = trim((string)data_get($resp->json(), 'candidates.0.content.parts.0.text', ''));
            if ($text === '') {
                return [];
            }

            $decoded = json_decode($text, true);
            if (!is_array($decoded)) {
                $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $text);
                $cleaned = preg_replace('/\s*```\s*$/', '', (string)$cleaned);
                $decoded = json_decode(trim((string)$cleaned), true);
            }

            if (!is_array($decoded)) {
                return [];
            }

            $rows = is_array($decoded['shots'] ?? null) ? $decoded['shots'] : $decoded;
            $result = [];

            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $shotNo = (int)($row['shot_index'] ?? 0);
                $translated = trim((string)($row['image_prompt_vi'] ?? ($row['prompt_vi'] ?? '')));
                if ($shotNo > 0 && $translated !== '') {
                    $result[$shotNo] = $translated;
                }
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('Short workspace prompt translation fallback', [
                'audio_book_id' => $audioBookId,
                'short_index' => $shortIndex,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function resolveShortVideoTtsOptions(Request $request, AudioBook $audioBook): array
    {
        return [
            'provider' => $request->input('provider', $audioBook->tts_provider ?: 'openai'),
            'voice_name' => $request->input('voice_name', $audioBook->tts_voice_name),
            'voice_gender' => $request->input('voice_gender', $audioBook->tts_voice_gender ?: 'female'),
            'style_instruction' => $request->input('style_instruction', $audioBook->tts_style_instruction),
            'tts_speed' => (float)$request->input('tts_speed', $audioBook->tts_speed ?: 1.0),
        ];
    }

    private function getSelectedShotIndices(array $shots, $selectedIndices): array
    {
        $allIndices = array_map(function ($shotKey) use ($shots) {
            $shot = $shots[$shotKey];
            return (int)($shot['shot_index'] ?? ($shotKey + 1));
        }, array_keys($shots));

        if (!is_array($selectedIndices) || empty($selectedIndices)) {
            sort($allIndices);
            return $allIndices;
        }

        $lookup = array_flip(array_map('intval', $allIndices));
        $filtered = [];
        foreach ((array)$selectedIndices as $idx) {
            $num = (int)$idx;
            if (isset($lookup[$num])) {
                $filtered[] = $num;
            }
        }

        $filtered = array_values(array_unique($filtered));
        sort($filtered);
        return $filtered;
    }

    private function buildShortShotImagePromptWithContinuity(array $item, array $shots, array $shot): string
    {
        $storyBible = trim((string)($item['story_bible'] ?? ''));
        $characterBible = trim((string)($item['character_bible'] ?? ''));
        $shotSentence = trim((string)($shot['sentence'] ?? ''));
        $imagePrompt = trim((string)($shot['image_prompt'] ?? ''));

        $refIndices = array_values(array_unique(array_filter(array_map('intval', (array)($shot['reference_shot_indices'] ?? [])), fn($val) => $val > 0)));
        if (empty($refIndices)) {
            $currentShotNo = (int)($shot['shot_index'] ?? 1);
            for ($i = $currentShotNo - 1; $i >= 1; $i--) {
                foreach ($shots as $refShot) {
                    $refNo = (int)($refShot['shot_index'] ?? 0);
                    if ($refNo !== $i) {
                        continue;
                    }
                    if ((bool)($refShot['is_reference_keyframe'] ?? false) || !empty($refShot['image_path'])) {
                        $refIndices = [$i];
                        break 2;
                    }
                }
            }
        }

        $refLines = [];
        foreach ($refIndices as $refIndex) {
            foreach ($shots as $refShot) {
                if ((int)($refShot['shot_index'] ?? 0) !== $refIndex) {
                    continue;
                }
                $refSentence = trim((string)($refShot['sentence'] ?? ''));
                $refPrompt = trim((string)($refShot['image_prompt'] ?? ''));
                $refLines[] = "- Shot {$refIndex}: {$refSentence} | visual cue: {$refPrompt}";
                break;
            }
        }

        $prompt = "Create a single vertical 9:16 cinematic keyframe for one moment in a continuous short story.\n";
        if ($storyBible !== '') {
            $prompt .= "Story bible: {$storyBible}\n";
        }
        if ($characterBible !== '') {
            $prompt .= "Character bible: {$characterBible}\n";
        }
        $prompt .= "Current sentence: {$shotSentence}\n";
        $prompt .= "Shot visual objective: {$imagePrompt}\n";
        if (!empty($refLines)) {
            $prompt .= "Reference frames for continuity:\n" . implode("\n", $refLines) . "\n";
        }
        $prompt .= "Requirements: keep same character identity, age, face, hairstyle and costume across frames; cinematic lighting; no text/no logo/no watermark.";

        return trim($prompt);
    }

    private function composeShortShotSegment(
        string $ffmpegPath,
        ?string $sourceVideoAbs,
        ?string $sourceImageAbs,
        string $audioAbs,
        float $duration,
        string $outputAbs
    ): void {
        $vf = 'scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,fps=30,format=yuv420p';

        if ($sourceVideoAbs) {
            $cmd = sprintf(
                '%s -y -stream_loop -1 -i %s -i %s -t %.3f -vf %s -map 0:v:0 -map 1:a:0 -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 192k -shortest %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($sourceVideoAbs),
                escapeshellarg($audioAbs),
                $duration,
                escapeshellarg($vf),
                escapeshellarg($outputAbs)
            );
            $this->runFfmpegCommand($cmd, 'Không thể tạo segment từ Kling video.');
            return;
        }

        if (!$sourceImageAbs) {
            throw new \RuntimeException('Thiếu nguồn ảnh/video để tạo segment.');
        }

        $cmd = sprintf(
            '%s -y -loop 1 -i %s -i %s -t %.3f -vf %s -map 0:v:0 -map 1:a:0 -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 192k -shortest %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($sourceImageAbs),
            escapeshellarg($audioAbs),
            $duration,
            escapeshellarg($vf),
            escapeshellarg($outputAbs)
        );
        $this->runFfmpegCommand($cmd, 'Không thể tạo segment từ ảnh + audio.');
    }

    private function runFfmpegCommand(string $command, string $errorPrefix): void
    {
        exec($command, $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException($errorPrefix . ' ' . implode("\n", array_slice($output, -8)));
        }
    }

    private function downloadVideoToPublicStorage(string $videoUrl, string $relativePath): void
    {
        $resp = Http::timeout(180)->get($videoUrl);
        if (!$resp->ok()) {
            throw new \RuntimeException('Không tải được video từ Kling. HTTP ' . $resp->status());
        }

        $disk = Storage::disk('public');
        $dir = dirname($relativePath);
        if (!$disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        $stored = $disk->put($relativePath, $resp->body());
        if (!$stored) {
            throw new \RuntimeException('Không lưu được video Kling vào storage.');
        }
    }

    private function deleteShortVideoShotAssets(array $item): void
    {
        $shots = is_array($item['shots'] ?? null) ? $item['shots'] : [];
        foreach ($shots as $shot) {
            $this->deleteShortVideoAssetFile($shot['tts_audio_path'] ?? null);
            $this->deleteShortVideoAssetFile($shot['image_path'] ?? null);
            $this->deleteShortVideoAssetFile($shot['kling_video_path'] ?? null);
            $this->deleteShortVideoAssetFile($shot['segment_video_path'] ?? null);
        }
    }

    private function ensureShortVideoAssetDirs(int $bookId): array
    {
        $baseDir = $this->getShortVideoBaseDir($bookId);
        $audioDir = $baseDir . '/audio';
        $imageDir = $baseDir . '/images';
        $videoDir = $baseDir . '/videos';
        $shotAudioDir = $baseDir . '/shots/audio';
        $shotImageDir = $baseDir . '/shots/images';
        $shotVideoDir = $baseDir . '/shots/videos';
        $packagesDir = $baseDir . '/packages';

        foreach ([$audioDir, $imageDir, $videoDir, $shotAudioDir, $shotImageDir, $shotVideoDir, $packagesDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        return [
            'audio' => $audioDir,
            'images' => $imageDir,
            'videos' => $videoDir,
            'shot_audio' => $shotAudioDir,
            'shot_images' => $shotImageDir,
            'shot_videos' => $shotVideoDir,
            'packages' => $packagesDir,
        ];
    }

    private function deleteShortVideoAssetFile(?string $relativePath): void
    {
        if (empty($relativePath)) {
            return;
        }

        $absPath = storage_path('app/public/' . ltrim($relativePath, '/'));
        if (file_exists($absPath)) {
            @unlink($absPath);
        }
    }

    private function getShortVideoBaseDir(int $bookId): string
    {
        $dir = storage_path('app/public/books/' . $bookId . '/short_videos');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function getShortVideoJsonPath(int $bookId): string
    {
        return $this->getShortVideoBaseDir($bookId) . '/shorts.json';
    }

    private function loadShortVideoItems(int $bookId): array
    {
        $path = $this->getShortVideoJsonPath($bookId);
        if (!file_exists($path)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveShortVideoItems(int $bookId, array $items): void
    {
        $path = $this->getShortVideoJsonPath($bookId);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (!is_writable($dir)) {
            @chmod($dir, 0775);
        }

        if (file_exists($path) && !is_writable($path)) {
            @chmod($path, 0664);
        }

        $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Không thể encode dữ liệu short videos sang JSON.');
        }

        $written = @file_put_contents($path, $json, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Không thể ghi file shorts.json. Kiểm tra quyền thư mục/file.');
        }
    }

    private function mapShortVideoItemForResponse(int $bookId, array $item): array
    {
        $item['audio_url'] = !empty($item['audio_path']) ? asset('storage/' . $item['audio_path']) : null;
        $item['image_url'] = !empty($item['image_path']) ? asset('storage/' . $item['image_path']) : null;
        $item['video_url'] = !empty($item['video_path']) ? asset('storage/' . $item['video_path']) : null;

        $shots = is_array($item['shots'] ?? null) ? $item['shots'] : [];
        $item['shots'] = array_values(array_map(function ($shot) {
            $shot['tts_audio_url'] = !empty($shot['tts_audio_path']) ? asset('storage/' . $shot['tts_audio_path']) : null;
            $shot['image_url'] = !empty($shot['image_path']) ? asset('storage/' . $shot['image_path']) : null;
            $shot['kling_video_url'] = !empty($shot['kling_video_path']) ? asset('storage/' . $shot['kling_video_path']) : null;
            $shot['segment_video_url'] = !empty($shot['segment_video_path']) ? asset('storage/' . $shot['segment_video_path']) : null;
            return $shot;
        }, $shots));
        $item['workspace_ready'] = !empty($item['shots']);

        return $item;
    }

    private function createShortVideoPlansWithAi(AudioBook $audioBook, int $count): array
    {
        [$bookTitle, $channelName] = $this->resolveShortVideoContextNames($audioBook);
        $styles = [
            'Drama',
            'Mystery',
            'Action',
            'Emotional',
            'Dark',
            'Motivational',
            'Epic',
            'Plot Twist',
            'Romantic',
            'Thriller',
        ];

        $description = trim((string) ($audioBook->description ?? ''));
        $description = mb_substr($description, 0, 3500);

        $fallback = [];
        for ($i = 0; $i < $count; $i++) {
            $style = $styles[$i % count($styles)];
            $fallbackScript = 'Bạn có biết điều khiến cuốn "' . $bookTitle . '" cuốn hút đến vậy không? Đây là phân đoạn theo phong cách ' . $style . ' với tình tiết gây tò mò, gợi mở và kết thúc bằng một câu hỏi để thôi thúc người xem theo dõi tiếp.';
            $fallback[] = [
                'index' => $i + 1,
                'title' => 'Short #' . ($i + 1) . ' - ' . $style,
                'style' => $style,
                'script' => $this->ensureShortScriptHasCta($fallbackScript, $bookTitle, $channelName),
                'image_prompt' => 'Vertical 9:16 cinematic scene for a book short video, style ' . $style . ', dramatic lighting, high contrast, emotional atmosphere, no text, ultra detailed.',
                'status' => 'planned',
                'error_message' => null,
                'audio_path' => null,
                'image_path' => null,
                'video_path' => null,
                'duration' => null,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];
        }

        $apiKey = config('services.gemini.api_key') ?: config('services.gemini.tts_api_key');
        if (empty($apiKey)) {
            return $fallback;
        }

        $prompt = "Bạn là chuyên gia viết nội dung short video viral cho YouTube/TikTok.\n";
        $prompt .= "Tạo {$count} ý tưởng short video cho cuốn sách sau, mỗi short tối đa 60 giây đọc thoại tiếng Việt.\n\n";
        $prompt .= "Sách hiện tại (giữ nguyên chính tả): {$bookTitle}\n";
        $prompt .= "Kênh hiện tại (giữ nguyên chính tả): {$channelName}\n";
        if (!empty($audioBook->author)) {
            $prompt .= "Tác giả: {$audioBook->author}\n";
        }
        if (!empty($audioBook->category)) {
            $prompt .= "Thể loại: {$audioBook->category}\n";
        }
        if (!empty($description)) {
            $prompt .= "Nội dung tham chiếu:\n{$description}\n\n";
        }
        $prompt .= "Yêu cầu:\n";
        $prompt .= "- Mỗi short phải có phong cách khác nhau, hook mạnh ở 3 giây đầu, kết mở kích thích bình luận.\n";
        $prompt .= "- 'script' dài khoảng 90-140 từ tiếng Việt (đủ cho ~45-60s).\n";
        $prompt .= "- Mỗi script BẮT BUỘC có câu kêu gọi: mời người xem vào nghe audio đầy đủ của cuốn '{$bookTitle}' trên kênh '{$channelName}'.\n";
        $prompt .= "- BẮT BUỘC dùng đúng nguyên văn tên sách '{$bookTitle}' và tên kênh '{$channelName}' trong phần CTA, không thay thế bằng từ đồng nghĩa.\n";
        $prompt .= "- 'image_prompt' bằng tiếng Anh, mô tả khung hình dọc 9:16, không chứa chữ trong ảnh.\n";
        $prompt .= "- Trả về JSON array thuần gồm đúng {$count} phần tử, mỗi phần tử có: title, style, script, image_prompt.\n";
        $prompt .= "- Không thêm markdown/code fence.\n";

        try {
            $resp = Http::timeout(120)->withHeaders([
                'Content-Type' => 'application/json',
            ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}", [
                'contents' => [[
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'temperature' => 0.8,
                    'maxOutputTokens' => 8192,
                    'responseMimeType' => 'application/json',
                ],
            ]);

            if (!$resp->ok()) {
                return $fallback;
            }

            $payload = $resp->json();
            $text = trim((string) data_get($payload, 'candidates.0.content.parts.0.text', ''));
            if ($text === '') {
                return $fallback;
            }

            $decoded = json_decode($text, true);
            if (!is_array($decoded)) {
                $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $text);
                $cleaned = preg_replace('/\s*```\s*$/', '', $cleaned);
                $decoded = json_decode(trim((string) $cleaned), true);
            }
            if (!is_array($decoded)) {
                return $fallback;
            }

            $result = [];
            for ($i = 0; $i < $count; $i++) {
                $raw = $decoded[$i] ?? [];
                $fallbackItem = $fallback[$i];

                $result[] = [
                    'index' => $i + 1,
                    'title' => trim((string)($raw['title'] ?? $fallbackItem['title'])),
                    'style' => trim((string)($raw['style'] ?? $fallbackItem['style'])),
                    'script' => $this->ensureShortScriptHasCta(trim((string)($raw['script'] ?? $fallbackItem['script'])), $bookTitle, $channelName),
                    'image_prompt' => trim((string)($raw['image_prompt'] ?? $fallbackItem['image_prompt'])),
                    'status' => 'planned',
                    'error_message' => null,
                    'audio_path' => null,
                    'image_path' => null,
                    'video_path' => null,
                    'duration' => null,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('AI short plans fallback', [
                'audio_book_id' => $audioBook->id,
                'error' => $e->getMessage(),
            ]);
            return $fallback;
        }
    }

    private function ensureShortScriptHasCta(string $script, string $bookTitle, string $channelName): string
    {
        $normalized = mb_strtolower($script);
        $bookNeedle = mb_strtolower(trim($bookTitle));
        $channelNeedle = mb_strtolower(trim($channelName));
        $hasCta = str_contains($normalized, 'nghe audio')
            || str_contains($normalized, 'nghe full')
            || str_contains($normalized, 'trọn bộ')
            || str_contains($normalized, 'vào kênh');
        $hasExactBook = $bookNeedle !== '' && str_contains($normalized, $bookNeedle);
        $hasExactChannel = $channelNeedle !== '' && str_contains($normalized, $channelNeedle);
        $hasMoiBan = str_contains($normalized, 'mời bạn');

        if ($hasCta && $hasExactBook && $hasExactChannel) {
            if ($hasMoiBan) {
                return trim($script);
            }

            $cta = $this->ensureCtaHasMoiBan(
                "nghe trọn bộ audio cuốn '{$bookTitle}', vào kênh {$channelName} để nghe ngay nhé!",
                $bookTitle,
                $channelName
            );

            return trim($script . ' ' . $cta);
        }

        $cta = $this->ensureCtaHasMoiBan(
            "nghe trọn bộ audio cuốn '{$bookTitle}', hãy vào kênh {$channelName} để nghe ngay nhé!",
            $bookTitle,
            $channelName
        );
        return trim($script . $cta);
    }

    private function resolveShortVideoContextNames(AudioBook $audioBook): array
    {
        $audioBook->loadMissing('youtubeChannel');

        $bookTitle = trim((string)($audioBook->title ?? ''));
        if ($bookTitle === '') {
            $bookTitle = 'cuốn sách hiện tại';
        }

        $channelName = trim((string)optional($audioBook->youtubeChannel)->title);
        if ($channelName === '') {
            $channelName = 'kênh YouTube hiện tại';
        }

        return [$bookTitle, $channelName];
    }

    /**
     * Generate thumbnail using Gemini AI
     */
    public function previewThumbnailPrompt(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'style' => 'nullable|string',
            'custom_prompt' => 'nullable|string|max:1000',
            'chapter_number' => 'nullable|integer|min:1',
            'with_text' => 'nullable|boolean',
            'custom_title' => 'nullable|string|max:500',
            'custom_author' => 'nullable|string|max:200',
            'prefer_portrait' => 'nullable|boolean',
        ]);

        $style = $request->input('style', 'cinematic');
        $customPrompt = $request->input('custom_prompt');
        $chapterNumber = $request->input('chapter_number');
        $withText = $request->input('with_text', true);
        $customTitle = $request->input('custom_title');
        $customAuthor = $request->input('custom_author');

        $bookInfo = [
            'book_id' => $audioBook->id,
            'title' => $customTitle ?: $audioBook->title,
            'author' => $customAuthor ?: ($audioBook->author ? 'Tác giả: ' . $audioBook->author : ''),
            'category' => $audioBook->category,
            'description' => $audioBook->description,
            'prefer_portrait' => $request->boolean('prefer_portrait', true),
        ];

        if ($withText) {
            $prompt = $this->imageService->buildThumbnailWithTextPrompt($bookInfo, $style, $chapterNumber, $customPrompt);
        } else {
            $prompt = $this->imageService->buildThumbnailPrompt($bookInfo, $style, $customPrompt);
        }

        return response()->json([
            'success' => true,
            'prompt' => $prompt,
        ]);
    }

    public function generateThumbnail(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'style' => 'nullable|string|in:realistic,anime,illustration,cinematic,vintage,fantasy,mystery,romance,modern,gradient',
            'custom_prompt' => 'nullable|string|max:1000',
            'chapter_number' => 'nullable|integer|min:1',
            'with_text' => 'nullable|boolean',
            'ai_research' => 'nullable|boolean',
            'use_cover_image' => 'nullable|boolean',
            'custom_title' => 'nullable|string|max:500',
            'custom_author' => 'nullable|string|max:200',
            'text_styling' => 'nullable|array',
            'override_prompt' => 'nullable|string|max:5000',
            'prefer_portrait' => 'nullable|boolean',
        ]);

        $options = [
            'style' => $request->input('style', 'cinematic'),
            'custom_prompt' => $request->input('custom_prompt'),
            'chapter_number' => $request->input('chapter_number'),
            'with_text' => $request->input('with_text', true),
            'ai_research' => $request->input('ai_research', false),
            'use_cover_image' => $request->input('use_cover_image', false),
            'custom_title' => $request->input('custom_title'),
            'custom_author' => $request->input('custom_author'),
            'text_styling' => $request->input('text_styling', []),
            'override_prompt' => $request->input('override_prompt'),
            'prefer_portrait' => $request->boolean('prefer_portrait', true),
        ];

        // Clear old progress
        Cache::forget("thumbnail_progress_{$audioBook->id}");

        GenerateThumbnailJob::dispatch($audioBook->id, $options);

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'Đã đưa vào hàng đợi xử lý',
        ]);
    }

    public function getThumbnailProgress(AudioBook $audioBook)
    {
        $progress = Cache::get("thumbnail_progress_{$audioBook->id}");

        if (!$progress) {
            return response()->json([
                'status' => 'pending',
                'message' => 'Đang chờ xử lý...',
            ]);
        }

        return response()->json($progress);
    }

    /**
     * Add text overlay to existing image using FFmpeg
     */
    public function addTextOverlay(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'source_image' => 'required|string',
            'text_elements' => 'required|array',
            'styling' => 'nullable|array'
        ]);

        $sourceImage = $request->input('source_image');
        $textElements = $request->input('text_elements', []);
        $styling = $request->input('styling', []);

        try {
            // Build source path
            $sourceDir = storage_path('app/public/books/' . $audioBook->id);
            $sourcePath = null;

            // Check in thumbnails folder first
            if (file_exists($sourceDir . '/thumbnails/' . $sourceImage)) {
                $sourcePath = $sourceDir . '/thumbnails/' . $sourceImage;
            } elseif (file_exists($sourceDir . '/scenes/' . $sourceImage)) {
                $sourcePath = $sourceDir . '/scenes/' . $sourceImage;
            }

            if (!$sourcePath) {
                return response()->json([
                    'success' => false,
                    'error' => 'Source image not found: ' . $sourceImage
                ], 404);
            }

            // Generate output filename
            $timestamp = time();
            $outputFilename = 'thumb_text_' . $timestamp . '.png';
            $outputDir = $sourceDir . '/thumbnails';
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }
            $outputPath = $outputDir . '/' . $outputFilename;

            // Apply text overlay using FFmpeg
            $result = $this->applyFFmpegTextOverlay($sourcePath, $outputPath, $textElements, $styling);

            if ($result['success']) {
                $relativePath = 'books/' . $audioBook->id . '/thumbnails/' . $outputFilename;

                Log::info("Added text overlay to image for audiobook {$audioBook->id}", [
                    'source' => $sourceImage,
                    'output' => $outputFilename
                ]);

                return response()->json([
                    'success' => true,
                    'path' => $relativePath,
                    'url' => asset('storage/' . $relativePath),
                    'filename' => $outputFilename
                ]);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error("Add text overlay failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add logo overlay to existing image using FFmpeg
     */
    public function addLogoOverlay(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'source_image' => 'required|string',
            'position' => 'required|string|in:top-left,top-right,bottom-left,bottom-right,center',
            'logo_scale' => 'nullable|integer|min:5|max:50',
            'opacity' => 'nullable|integer|min:0|max:100',
            'margin' => 'nullable|integer|min:0|max:200',
        ]);

        // Get channel logo
        $channel = $audioBook->youtubeChannel;
        if (!$channel || !$channel->thumbnail_url) {
            return response()->json([
                'success' => false,
                'error' => 'Kênh YouTube chưa có logo/thumbnail. Vui lòng cập nhật logo cho kênh trước.'
            ], 422);
        }

        $sourceImage = $request->input('source_image');
        $position = $request->input('position', 'bottom-right');
        $logoScale = $request->input('logo_scale', 15);
        $opacity = $request->input('opacity', 100);
        $margin = $request->input('margin', 20);

        try {
            // Resolve source image path
            $sourceDir = storage_path('app/public/books/' . $audioBook->id);
            $sourcePath = null;

            if (file_exists($sourceDir . '/thumbnails/' . $sourceImage)) {
                $sourcePath = $sourceDir . '/thumbnails/' . $sourceImage;
            } elseif (file_exists($sourceDir . '/scenes/' . $sourceImage)) {
                $sourcePath = $sourceDir . '/scenes/' . $sourceImage;
            }

            if (!$sourcePath) {
                return response()->json([
                    'success' => false,
                    'error' => 'Source image not found: ' . $sourceImage
                ], 404);
            }

            // Resolve logo path
            $logoPath = null;
            $tempLogoPath = null;

            if (str_starts_with($channel->thumbnail_url, 'http://') || str_starts_with($channel->thumbnail_url, 'https://')) {
                // Download external logo to temp file
                $logoContent = \Illuminate\Support\Facades\Http::timeout(15)->get($channel->thumbnail_url)->body();
                $tempLogoPath = sys_get_temp_dir() . '/logo_' . $audioBook->id . '_' . time() . '.png';
                file_put_contents($tempLogoPath, $logoContent);
                $logoPath = $tempLogoPath;
            } else {
                $logoPath = storage_path('app/public/' . $channel->thumbnail_url);
            }

            if (!$logoPath || !file_exists($logoPath)) {
                if ($tempLogoPath && file_exists($tempLogoPath)) {
                    unlink($tempLogoPath);
                }
                return response()->json([
                    'success' => false,
                    'error' => 'Logo file not found'
                ], 404);
            }

            // Load source image metadata first (used for output format decision)
            $sourceInfo = getimagesize($sourcePath);
            if (!$sourceInfo || !isset($sourceInfo[2])) {
                throw new \RuntimeException('Không thể đọc metadata ảnh nguồn: ' . $sourcePath);
            }

            // Generate output filename (match actual format to encoder)
            $timestamp = time();
            $extension = $sourceInfo[2] === IMAGETYPE_JPEG ? 'jpg' : 'png';
            $outputFilename = 'thumb_logo_' . $timestamp . '_' . uniqid() . '.' . $extension;
            $outputDir = $sourceDir . '/thumbnails';
            $this->ensureDirectoryWritable($outputDir, 0775);

            $outputPath = $outputDir . '/' . $outputFilename;

            // Use PHP GD to create circular logo with border and shadow
            $scaleRatio = $logoScale / 100;
            $opacityFactor = $opacity / 100;

            // Load source image
            $sourceImg = $this->gdLoadImage($sourcePath, $sourceInfo[2]);
            $srcW = imagesx($sourceImg);
            $srcH = imagesy($sourceImg);

            // Load logo image
            $logoImg = $this->gdLoadImage($logoPath, null);

            // Clean up temp logo file
            if ($tempLogoPath && file_exists($tempLogoPath)) {
                unlink($tempLogoPath);
            }

            // Calculate sizes
            $logoSize = max(20, (int) round($srcW * $scaleRatio)); // circle diameter
            $borderW = max(3, (int) round($logoSize * 0.07));      // white border width
            $totalSize = $logoSize + $borderW * 2;                  // total with border
            $shadowOff = max(2, (int) round($logoSize * 0.04));     // shadow offset
            $canvasSize = $totalSize + $shadowOff + 4;              // canvas with shadow room

            // Create transparent canvas for the composite
            $composite = imagecreatetruecolor($canvasSize, $canvasSize);
            imagesavealpha($composite, true);
            imagealphablending($composite, false);
            $trans = imagecolorallocatealpha($composite, 0, 0, 0, 127);
            imagefill($composite, 0, 0, $trans);
            imagealphablending($composite, true);

            $cx = (int) floor($canvasSize / 2);
            $cy = $cx;

            // 1. Draw shadow (dark circle, slightly offset)
            $shadowColor = imagecolorallocatealpha($composite, 0, 0, 0, 85);
            imagefilledellipse($composite, $cx + $shadowOff, $cy + $shadowOff, $totalSize, $totalSize, $shadowColor);
            // Soften shadow with a second slightly larger, more transparent ellipse
            $shadowSoft = imagecolorallocatealpha($composite, 0, 0, 0, 105);
            imagefilledellipse($composite, $cx + $shadowOff, $cy + $shadowOff, $totalSize + 4, $totalSize + 4, $shadowSoft);

            // 2. Draw white border circle
            $white = imagecolorallocate($composite, 255, 255, 255);
            imagefilledellipse($composite, $cx, $cy, $totalSize - 1, $totalSize - 1, $white);

            // 3. Scale logo to square and apply circular mask
            $scaledLogo = imagecreatetruecolor($logoSize, $logoSize);
            imagesavealpha($scaledLogo, true);
            imagealphablending($scaledLogo, false);
            imagefill($scaledLogo, 0, 0, $trans);
            imagealphablending($scaledLogo, true);
            imagecopyresampled($scaledLogo, $logoImg, 0, 0, 0, 0, $logoSize, $logoSize, imagesx($logoImg), imagesy($logoImg));

            // Circular mask: remove pixels outside the circle
            $r = $logoSize / 2;
            imagealphablending($scaledLogo, false);
            for ($x = 0; $x < $logoSize; $x++) {
                for ($y = 0; $y < $logoSize; $y++) {
                    if (sqrt(pow($x - $r + 0.5, 2) + pow($y - $r + 0.5, 2)) > $r) {
                        imagesetpixel($scaledLogo, $x, $y, $trans);
                    }
                }
            }
            imagealphablending($scaledLogo, true);

            // 4. Paste circular logo centered on the white border circle
            $logoOffX = $cx - (int) floor($logoSize / 2);
            $logoOffY = $cy - (int) floor($logoSize / 2);
            imagecopy($composite, $scaledLogo, $logoOffX, $logoOffY, 0, 0, $logoSize, $logoSize);

            // 5. Apply opacity if needed
            if ($opacity < 100) {
                imagealphablending($composite, false);
                for ($x = 0; $x < $canvasSize; $x++) {
                    for ($y = 0; $y < $canvasSize; $y++) {
                        $rgba = imagecolorat($composite, $x, $y);
                        $a = ($rgba >> 24) & 0x7F; // GD alpha: 0=opaque, 127=transparent
                        if ($a < 127) {
                            $newA = (int) min(127, 127 - (127 - $a) * $opacityFactor);
                            $rc = ($rgba >> 16) & 0xFF;
                            $gc = ($rgba >> 8) & 0xFF;
                            $bc = $rgba & 0xFF;
                            imagesetpixel($composite, $x, $y, imagecolorallocatealpha($composite, $rc, $gc, $bc, $newA));
                        }
                    }
                }
                imagealphablending($composite, true);
            }

            // 6. Calculate position on source image
            $compSize = $canvasSize;
            switch ($position) {
                case 'top-left':
                    $posX = $margin;
                    $posY = $margin;
                    break;
                case 'top-right':
                    $posX = $srcW - $compSize - $margin;
                    $posY = $margin;
                    break;
                case 'bottom-left':
                    $posX = $margin;
                    $posY = $srcH - $compSize - $margin;
                    break;
                case 'center':
                    $posX = (int) floor(($srcW - $compSize) / 2);
                    $posY = (int) floor(($srcH - $compSize) / 2);
                    break;
                case 'bottom-right':
                default:
                    $posX = $srcW - $compSize - $margin;
                    $posY = $srcH - $compSize - $margin;
                    break;
            }

            // 7. Overlay composite on source
            imagecopy($sourceImg, $composite, max(0, $posX), max(0, $posY), 0, 0, $compSize, $compSize);

            // 8. Save output (PNG for quality, or JPEG if source was JPEG)
            $saved = false;
            if ($sourceInfo[2] === IMAGETYPE_JPEG) {
                $saved = imagejpeg($sourceImg, $outputPath, 95);
            } else {
                $saved = imagepng($sourceImg, $outputPath, 2);
            }

            if (!$saved || !file_exists($outputPath)) {
                $lastError = error_get_last();
                $errorMsg = $lastError['message'] ?? 'Unknown GD save error';
                throw new \RuntimeException('Không thể lưu thumbnail có logo: ' . $errorMsg);
            }

            // Clean up GD resources
            imagedestroy($sourceImg);
            imagedestroy($logoImg);
            imagedestroy($scaledLogo);
            imagedestroy($composite);

            $this->safeLog('info', "Added circular logo overlay for audiobook {$audioBook->id}", [
                'source' => $sourceImage,
                'output' => $outputFilename,
                'position' => $position,
                'logo_size' => $logoSize,
                'border_width' => $borderW,
            ]);

            $relativePath = 'books/' . $audioBook->id . '/thumbnails/' . $outputFilename;

            return response()->json([
                'success' => true,
                'path' => $relativePath,
                'url' => asset('storage/' . $relativePath),
                'filename' => $outputFilename
            ]);
        } catch (\Throwable $e) {
            $this->safeLog('error', "Add logo overlay failed for audiobook {$audioBook->id}: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ensure directory exists and is writable.
     */
    private function ensureDirectoryWritable(string $dirPath, int $mode = 0775): void
    {
        if (!is_dir($dirPath) && !mkdir($dirPath, $mode, true) && !is_dir($dirPath)) {
            throw new \RuntimeException('Không thể tạo thư mục: ' . $dirPath);
        }

        @chmod($dirPath, $mode);

        if (is_writable($dirPath)) {
            return;
        }

        $permissions = substr(sprintf('%o', @fileperms($dirPath)), -4) ?: 'unknown';

        $owner = @fileowner($dirPath);
        $group = @filegroup($dirPath);

        $ownerName = (is_int($owner) && function_exists('posix_getpwuid'))
            ? (posix_getpwuid($owner)['name'] ?? (string) $owner)
            : (string) ($owner ?? 'unknown');

        $groupName = (is_int($group) && function_exists('posix_getgrgid'))
            ? (posix_getgrgid($group)['name'] ?? (string) $group)
            : (string) ($group ?? 'unknown');

        throw new \RuntimeException(
            "Thư mục không có quyền ghi: {$dirPath} (perm={$permissions}, owner={$ownerName}, group={$groupName})"
        );
    }

    /**
     * Load an image file into a GD resource.
     */
    private function gdLoadImage(string $path, ?int $type): \GdImage
    {
        if ($type === null) {
            $info = getimagesize($path);
            $type = $info ? $info[2] : 0;
        }

        $img = match ($type) {
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_GIF => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => imagecreatefromstring(file_get_contents($path)),
        };

        if (!$img) {
            throw new \RuntimeException("Failed to load image: {$path}");
        }

        return $img;
    }

    /**
     * Write logs safely without breaking the request flow if the log driver fails.
     */
    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable $logException) {
            error_log('[AudioBookController] Log write failed: ' . $logException->getMessage() . ' | message: ' . $message);
        }
    }

    /**
     * Apply text overlay to image using FFmpeg
     */
    private function applyFFmpegTextOverlay(string $inputPath, string $outputPath, array $textElements, array $styling): array
    {
        $ffmpegPath = env('FFMPEG_PATH', 'ffmpeg');

        // Extract styling options with defaults
        $position = $styling['position'] ?? 'bottom';
        $textColor = $styling['textColor'] ?? '#ffffff';
        $borderColor = $styling['borderColor'] ?? '#000000';
        $borderWidth = (int)($styling['borderWidth'] ?? 4);
        $bgStyle = $styling['bgStyle'] ?? 'none';
        $bgColor = $styling['bgColor'] ?? '#000000';
        $bgOpacity = (int)($styling['bgOpacity'] ?? 70);
        $titleFontSize = (int)($styling['titleFontSize'] ?? 60);

        // Convert hex colors to FFmpeg format (remove #)
        $textColorFfmpeg = ltrim($textColor, '#');
        $borderColorFfmpeg = ltrim($borderColor, '#');
        $bgColorFfmpeg = ltrim($bgColor, '#');

        // Find font that supports Vietnamese
        $fontPath = $this->findVietnameseFont();
        $fontFile = $fontPath ? "fontfile='" . str_replace(['\\', ':'], ['/', '\\:'], $fontPath) . "':" : '';

        $filters = [];

        // Add background overlay if needed
        if ($bgStyle !== 'none') {
            $bgHeight = 180;

            switch ($position) {
                case 'top':
                    $bgY = 0;
                    break;
                case 'center':
                    $bgY = '(h-' . $bgHeight . ')/2';
                    break;
                case 'bottom':
                default:
                    $bgY = 'h-' . $bgHeight;
                    break;
            }

            if ($bgStyle === 'solid' || $bgStyle === 'gradient') {
                $filters[] = "drawbox=x=0:y={$bgY}:w=iw:h={$bgHeight}:color={$bgColorFfmpeg}@0.{$bgOpacity}:t=fill";
            } elseif ($bgStyle === 'blur') {
                $filters[] = "drawbox=x=0:y={$bgY}:w=iw:h={$bgHeight}:color=000000@0.5:t=fill";
            }
        }

        // Calculate text positions based on position setting
        $authorFontSize = (int)($titleFontSize * 0.5);
        $chapterFontSize = (int)($titleFontSize * 0.8);

        switch ($position) {
            case 'top':
                $titleY = 40;
                $authorY = $titleY + $titleFontSize + 20;
                $chapterY = $authorY + $authorFontSize + 15;
                break;
            case 'center':
                $titleY = '(h-' . $titleFontSize . ')/2-40';
                $authorY = '(h)/2+20';
                $chapterY = '(h)/2+' . ($authorFontSize + 40);
                break;
            case 'bottom':
            default:
                $chapterY = 'h-50';
                $authorY = 'h-' . (50 + $chapterFontSize + 10);
                $titleY = 'h-' . (50 + $chapterFontSize + $authorFontSize + 30);
                break;
        }

        // Build border option
        $borderOption = $borderWidth > 0 ? ":borderw={$borderWidth}:bordercolor={$borderColorFfmpeg}" : '';

        // Title (large, centered)
        if (!empty($textElements['title'])) {
            $escapedTitle = $this->escapeFFmpegText($textElements['title']);
            $filters[] = "drawtext={$fontFile}text='{$escapedTitle}':fontsize={$titleFontSize}:fontcolor={$textColorFfmpeg}{$borderOption}:x=(w-text_w)/2:y={$titleY}";
        }

        // Author below title
        if (!empty($textElements['author'])) {
            $escapedAuthor = $this->escapeFFmpegText($textElements['author']);
            $authorBorderWidth = max(1, (int)($borderWidth * 0.5));
            $authorBorder = $borderWidth > 0 ? ":borderw={$authorBorderWidth}:bordercolor={$borderColorFfmpeg}" : '';
            $filters[] = "drawtext={$fontFile}text='{$escapedAuthor}':fontsize={$authorFontSize}:fontcolor={$textColorFfmpeg}{$authorBorder}:x=(w-text_w)/2:y={$authorY}";
        }

        // Chapter number
        if (!empty($textElements['chapter'])) {
            $escapedChapter = $this->escapeFFmpegText($textElements['chapter']);
            $chapterBorderWidth = max(2, (int)($borderWidth * 0.75));
            $chapterBorder = $borderWidth > 0 ? ":borderw={$chapterBorderWidth}:bordercolor={$borderColorFfmpeg}" : '';
            $filters[] = "drawtext={$fontFile}text='{$escapedChapter}':fontsize={$chapterFontSize}:fontcolor={$textColorFfmpeg}{$chapterBorder}:x=(w-text_w)/2:y={$chapterY}";
        }

        if (empty($filters)) {
            copy($inputPath, $outputPath);
            return ['success' => true];
        }

        $filterString = implode(',', $filters);

        $command = sprintf(
            '%s -y -i %s -vf "%s" -q:v 2 %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($inputPath),
            $filterString,
            escapeshellarg($outputPath)
        );

        Log::info('FFmpeg add text overlay command', ['command' => $command]);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error('FFmpeg add text overlay failed', [
                'output' => implode("\n", $output),
                'return_code' => $returnCode
            ]);
            return [
                'success' => false,
                'error' => implode("\n", array_slice($output, -3))
            ];
        }

        return ['success' => true];
    }

    /**
     * Generate video scenes using Gemini AI
     */
    public function generateVideoScenes(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'style' => 'nullable|string|in:realistic,anime,illustration,cinematic',
            'count' => 'nullable|integer|min:1|max:10'
        ]);

        $style = $request->input('style', 'cinematic');
        // Allow null count - AI will auto-determine the number of scenes
        $count = $request->input('count'); // No default - null means AI decides

        try {
            $bookInfo = [
                'book_id' => $audioBook->id,
                'title' => $audioBook->title,
                'author' => $audioBook->author,
                'category' => $audioBook->category,
                'book_type' => $audioBook->book_type,
                'description' => $audioBook->description
            ];

            $result = $this->imageService->generateVideoScenes($bookInfo, $count, $style);

            if ($result['success']) {
                Log::info("Generated {$result['generated']} scenes for audiobook {$audioBook->id}", [
                    'style' => $style,
                    'count_requested' => $count ?? 'auto',
                    'failed' => $result['failed'] ?? 0
                ]);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error("Generate scenes failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bước 1: AI phân tích nội dung sách → tạo danh sách scenes + prompts
     */
    public function analyzeScenes(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'style' => 'nullable|string|in:realistic,anime,illustration,cinematic',
        ]);

        $style = $request->input('style', 'cinematic');

        try {
            $result = $this->imageService->analyzeBookForScenes(
                $audioBook->title,
                $audioBook->description ?? '',
                $audioBook->category ?? '',
                $audioBook->book_type ?? '',
                $style
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error("Analyze scenes failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bước 2: Từ 1 scene prompt đã có → tạo ảnh
     */
    public function generateSceneImage(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'prompt' => 'required|string',
            'scene_index' => 'required|integer|min:0',
            'scene_title' => 'nullable|string',
            'scene_description' => 'nullable|string',
            'style' => 'nullable|string|in:realistic,anime,illustration,cinematic',
        ]);

        $prompt = $request->input('prompt');
        $sceneIndex = $request->input('scene_index');
        $sceneTitle = $request->input('scene_title', 'Scene ' . ($sceneIndex + 1));
        $sceneDescription = $request->input('scene_description', '');
        $style = $request->input('style', 'cinematic');

        try {
            $outputDir = storage_path('app/public/books/' . $audioBook->id . '/scenes');
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $timestamp = time();
            $filename = "scene_{$sceneIndex}_{$timestamp}.png";
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

            Log::info("Tạo ảnh scene {$sceneIndex}", [
                'title' => $sceneTitle,
                'prompt' => $prompt
            ]);

            $result = $this->imageService->generateImage($prompt, $outputPath, '16:9');

            if ($result['success']) {
                $relativePath = 'books/' . $audioBook->id . '/scenes/' . $filename;

                // Save metadata
                $metadataPath = $outputDir . DIRECTORY_SEPARATOR . "scene_{$sceneIndex}_{$timestamp}.json";
                file_put_contents($metadataPath, json_encode([
                    'scene_number' => $sceneIndex + 1,
                    'title' => $sceneTitle,
                    'description' => $sceneDescription,
                    'visual_prompt' => $prompt,
                    'style' => $style,
                    'created_at' => time()
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return response()->json([
                    'success' => true,
                    'image' => [
                        'index' => $sceneIndex,
                        'path' => $relativePath,
                        'url' => asset('storage/' . $relativePath),
                        'title' => $sceneTitle,
                        'description' => $sceneDescription,
                        'prompt' => $prompt
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Không thể tạo ảnh'
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error("Generate scene image failed: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate a slideshow video from scene images synced with description audio.
     * Each scene appears for a duration proportional to its description text length.
     */
    public function generateSceneSlideshowVideo(Request $request, AudioBook $audioBook)
    {
        try {
            // Validate: need description audio
            if (!$audioBook->description_audio) {
                return response()->json([
                    'success' => false,
                    'error' => 'Chưa có audio giới thiệu. Vui lòng tạo audio trước.'
                ], 400);
            }

            $audioPath = storage_path('app/public/' . $audioBook->description_audio);
            if (!file_exists($audioPath)) {
                return response()->json([
                    'success' => false,
                    'error' => 'File audio giới thiệu không tồn tại.'
                ], 404);
            }

            $audioDuration = (float) $audioBook->description_audio_duration;
            if ($audioDuration <= 0) {
                // Try to get duration from FFmpeg
                $ffprobe = env('FFPROBE_PATH', 'ffprobe');
                $cmd = sprintf(
                    '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                    $ffprobe,
                    escapeshellarg($audioPath)
                );
                exec($cmd, $output);
                $audioDuration = !empty($output) ? (float) $output[0] : 0;

                if ($audioDuration <= 0) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Không thể xác định thời lượng audio.'
                    ], 400);
                }
            }

            // Collect scene images with metadata, sorted by scene_number
            $scenesDir = storage_path('app/public/books/' . $audioBook->id . '/scenes');
            if (!is_dir($scenesDir)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Chưa có phân cảnh nào. Vui lòng tạo phân cảnh trước.'
                ], 400);
            }

            $imageFiles = glob($scenesDir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE);
            if (empty($imageFiles)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Không tìm thấy ảnh phân cảnh nào.'
                ], 400);
            }

            // Build scenes array with metadata
            $scenes = [];
            foreach ($imageFiles as $imageFile) {
                $metadataFile = preg_replace('/\.(png|jpg|jpeg|webp)$/i', '.json', $imageFile);
                $metadata = null;
                if (file_exists($metadataFile)) {
                    $metadata = json_decode(file_get_contents($metadataFile), true);
                }

                $scenes[] = [
                    'image_path' => $imageFile,
                    'title' => $metadata['title'] ?? basename($imageFile),
                    'description' => $metadata['description'] ?? '',
                    'scene_number' => $metadata['scene_number'] ?? 999
                ];
            }

            // Sort by scene_number
            usort($scenes, fn($a, $b) => $a['scene_number'] - $b['scene_number']);

            Log::info("Generating scene slideshow video", [
                'audiobook_id' => $audioBook->id,
                'scenes' => count($scenes),
                'audio_duration' => $audioDuration
            ]);

            // Output path
            $outputDir = storage_path('app/public/books/' . $audioBook->id . '/mp4');
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }
            $outputPath = $outputDir . '/description_scenes.mp4';

            // Delete old file if exists
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            // Create the slideshow video
            $result = $this->compositionService->createSceneSlideshow(
                $scenes,
                $audioPath,
                $audioDuration,
                $outputPath
            );

            if ($result['success']) {
                $relativePath = 'books/' . $audioBook->id . '/mp4/description_scenes.mp4';
                $videoUrl = asset('storage/' . $relativePath);

                // Optionally save to audiobook model
                $audioBook->update([
                    'description_scene_video' => $relativePath,
                    'description_scene_video_duration' => $result['duration'] ?? $audioDuration
                ]);

                return response()->json([
                    'success' => true,
                    'video_url' => $videoUrl,
                    'video_path' => $relativePath,
                    'duration' => $result['duration'] ?? $audioDuration,
                    'scenes_count' => count($scenes),
                    'message' => 'Video phân cảnh đã được tạo thành công!'
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Không thể tạo video slideshow.'
            ], 500);
        } catch (\Exception $e) {
            Log::error("Generate scene slideshow video failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ========== Description Video Pipeline (Chunked) ==========

    /**
     * Step 1+2: AI splits description into chunks and generates image prompts.
     */
    public function chunkDescription(Request $request, AudioBook $audioBook)
    {
        try {
            $chunks = $this->descVideoService->analyzeAndChunk($audioBook);

            return response()->json([
                'success' => true,
                'chunks' => $chunks,
                'total' => count($chunks),
                'message' => 'Đã chia nội dung thành ' . count($chunks) . ' đoạn.'
            ]);
        } catch (\Exception $e) {
            Log::error("Chunk description failed for audiobook {$audioBook->id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get saved chunks for the current audiobook.
     */
    public function getChunks(AudioBook $audioBook)
    {
        $chunks = $this->descVideoService->loadChunks($audioBook->id);

        return response()->json([
            'success' => true,
            'chunks' => $chunks ?? [],
            'total' => $chunks ? count($chunks) : 0
        ]);
    }

    /**
     * Step 3: Generate image for a single chunk.
     */
    public function generateChunkImage(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'chunk_index' => 'required|integer|min:0',
            'prompt' => 'required|string|max:2000'
        ]);

        try {
            $result = $this->descVideoService->generateChunkImage(
                $audioBook->id,
                $request->input('chunk_index'),
                $request->input('prompt')
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error("Generate chunk image failed: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Step 4: Generate TTS audio for a single chunk.
     */
    public function generateChunkTts(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'chunk_index' => 'required|integer|min:0',
            'text' => 'required|string',
            'provider' => 'required|string|in:openai,gemini,microsoft,vbee',
            'voice_name' => 'required|string',
            'voice_gender' => 'nullable|string|in:male,female',
            'style_instruction' => 'nullable|string'
        ]);

        try {
            $result = $this->descVideoService->generateChunkTts(
                $audioBook->id,
                $request->input('chunk_index'),
                $request->input('text'),
                $request->input('provider'),
                $request->input('voice_name'),
                $request->input('voice_gender', 'female'),
                $request->input('style_instruction')
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error("Generate chunk TTS failed: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Step 5: Generate SRT subtitle for a single chunk.
     */
    public function generateChunkSrt(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'chunk_index' => 'required|integer|min:0'
        ]);

        try {
            $result = $this->descVideoService->generateChunkSrt(
                $audioBook->id,
                $request->input('chunk_index')
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error("Generate chunk SRT failed: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Step 6: Compose final video from all chunks.
     */
    public function composeDescriptionVideo(Request $request, AudioBook $audioBook)
    {
        try {
            $result = $this->descVideoService->composeVideo($audioBook);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error("Compose description video failed for audiobook {$audioBook->id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete generated media
     */
    public function deleteMedia(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'type' => 'nullable|string|in:all,thumbnails,scenes',
            'filename' => 'nullable|string'
        ]);

        $type = $request->input('type', 'all');
        $filename = $request->input('filename');

        try {
            // Delete specific file
            if ($filename) {
                $basePath = storage_path('app/public/books/' . $audioBook->id);

                // Check in thumbnails
                $thumbPath = $basePath . '/thumbnails/' . $filename;
                if (file_exists($thumbPath)) {
                    unlink($thumbPath);
                    return response()->json([
                        'success' => true,
                        'message' => 'Đã xóa thumbnail',
                        'deleted' => $filename
                    ]);
                }

                // Check in scenes
                $scenePath = $basePath . '/scenes/' . $filename;
                if (file_exists($scenePath)) {
                    unlink($scenePath);
                    return response()->json([
                        'success' => true,
                        'message' => 'Đã xóa scene',
                        'deleted' => $filename
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'error' => 'File không tồn tại'
                ], 404);
            }

            // Delete by type
            $result = $this->imageService->deleteMedia($audioBook->id, $type);

            Log::info("Deleted media for audiobook {$audioBook->id}", [
                'type' => $type,
                'count' => $result['count'] ?? 0
            ]);

            return response()->json([
                'success' => true,
                'message' => "Đã xóa {$result['count']} file",
                'deleted' => $result['deleted'] ?? []
            ]);
        } catch (\Exception $e) {
            Log::error("Delete media failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create animation from image using Kling AI
     */
    public function createAnimation(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'image_path' => 'required|string',
            'prompt' => 'nullable|string|max:1000',
            'duration' => 'nullable|in:5,10',
            'mode' => 'nullable|in:std,pro'
        ]);

        $imagePath = $request->input('image_path');
        $prompt = $request->input('prompt');
        $duration = $request->input('duration', '5');
        $mode = $request->input('mode', 'std');

        try {
            Log::info("Creating animation for audiobook {$audioBook->id}", [
                'image_path' => $imagePath
            ]);

            // Default prompt for audiobook background animation
            $defaultPrompt = "Subtle ambient animation with gentle movements: soft smoke or mist drifting slowly, flickering candlelight or lamp glow, slight hair or fabric movement from breeze, gentle eye blinking, subtle breathing motion. Keep the scene peaceful and dreamy, suitable for audiobook background. No sudden movements.";

            $result = $this->klingService->createAnimationSync(
                $imagePath,
                $audioBook->id,
                $prompt ?: $defaultPrompt,
                300 // 5 minutes max wait
            );

            if ($result['success']) {
                Log::info("Animation created for audiobook {$audioBook->id}", [
                    'path' => $result['path'] ?? null
                ]);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error("Create animation failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start animation task (async) - returns task_id
     */
    public function startAnimationTask(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'image_name' => 'required|string',
            'prompt' => 'nullable|string|max:1000'
        ]);

        $imageName = $request->input('image_name');
        $prompt = $request->input('prompt');

        try {
            // Determine the correct path based on filename pattern
            $bookId = $audioBook->id;

            // Check if image is in thumbnails or scenes folder
            $thumbnailPath = "books/{$bookId}/thumbnails/{$imageName}";
            $scenePath = "books/{$bookId}/scenes/{$imageName}";

            if (Storage::disk('public')->exists($thumbnailPath)) {
                $imagePath = $thumbnailPath;
            } elseif (Storage::disk('public')->exists($scenePath)) {
                $imagePath = $scenePath;
            } else {
                return response()->json([
                    'success' => false,
                    'error' => "Image not found: {$imageName}"
                ], 404);
            }

            $defaultPrompt = "Subtle ambient animation: soft smoke drifting, flickering lights, gentle fabric movement, slow breathing motion, peaceful and dreamy atmosphere for audiobook background.";

            $result = $this->klingService->createImageToVideoTask(
                $imagePath,
                $prompt ?: $defaultPrompt
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check animation task status
     */
    public function checkAnimationStatus(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'task_id' => 'required|string'
        ]);

        $taskId = $request->input('task_id');

        try {
            $result = $this->klingService->getTaskStatus($taskId);

            // Add completed flag for frontend
            $result['completed'] = false;

            // If completed, download and save video (AIML API uses 'completed' status)
            if ($result['success'] && $result['status'] === 'completed' && !empty($result['video_url'])) {
                $timestamp = time();
                $filename = "anim_{$timestamp}.mp4";

                $downloadResult = $this->klingService->downloadVideo(
                    $result['video_url'],
                    $audioBook->id,
                    $filename
                );

                if ($downloadResult['success']) {
                    $result['local_path'] = $downloadResult['path'];
                    $result['local_url'] = $downloadResult['url'];
                    $result['completed'] = true;
                }
            }

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get existing animations for audiobook
     */
    public function getAnimations(AudioBook $audioBook)
    {
        try {
            $animationsDir = storage_path("app/public/books/{$audioBook->id}/animations");
            $animations = [];

            if (is_dir($animationsDir)) {
                $files = glob($animationsDir . '/*.mp4');
                foreach ($files as $file) {
                    $filename = basename($file);
                    $animations[] = [
                        'filename' => $filename,
                        'url' => asset("storage/books/{$audioBook->id}/animations/{$filename}"),
                        'size' => filesize($file),
                        'created' => filemtime($file)
                    ];
                }

                // Sort by created date, newest first
                usort($animations, fn($a, $b) => $b['created'] - $a['created']);
            }

            return response()->json([
                'success' => true,
                'animations' => $animations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate chapter cover images with text overlay using FFmpeg
     * 
     * @param Request $request
     * @param AudioBook $audioBook
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateChapterCovers(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'image_filename' => 'required|string',
            'chapter_ids' => 'nullable|array',
            'chapter_ids.*' => 'integer|exists:audiobook_chapters,id',
            'segment_ids' => 'nullable|array',
            'segment_ids.*' => 'integer|exists:audiobook_video_segments,id',
            'text_options' => 'nullable|array',
            'text_options.font_size' => 'nullable|integer|min:40|max:150',
            'text_options.text_color' => 'nullable|string',
            'text_options.outline_color' => 'nullable|string',
            'text_options.outline_width' => 'nullable|integer|min:2|max:8',
            'text_options.text_mode' => 'nullable|string|in:number,title,both',
            'text_options.position_x' => 'nullable|numeric|min:0|max:100',
            'text_options.position_y' => 'nullable|numeric|min:0|max:100',
        ]);

        $chapterIds = $request->input('chapter_ids', []);
        $segmentIds = $request->input('segment_ids', []);

        if (empty($chapterIds) && empty($segmentIds)) {
            return response()->json([
                'success' => false,
                'error' => 'Vui lòng chọn ít nhất 1 chương hoặc 1 phần.'
            ], 422);
        }

        $imageFilename = $request->input('image_filename');
        $textOptions = $request->input('text_options', []);

        // Default text options
        $fontSize = $textOptions['font_size'] ?? 80;
        $textColor = $textOptions['text_color'] ?? '#FFFFFF';
        $outlineColor = $textOptions['outline_color'] ?? '#000000';
        $outlineWidth = $textOptions['outline_width'] ?? 4;
        $textMode = $textOptions['text_mode'] ?? 'number';
        $positionX = $textOptions['position_x'] ?? 50; // Center X
        $positionY = $textOptions['position_y'] ?? 15; // Top area

        // Find source image (check thumbnails and scenes folders)
        $thumbnailPath = "books/{$audioBook->id}/thumbnails/{$imageFilename}";
        $scenePath = "books/{$audioBook->id}/scenes/{$imageFilename}";

        $sourceImagePath = null;
        if (Storage::disk('public')->exists($thumbnailPath)) {
            $sourceImagePath = storage_path("app/public/{$thumbnailPath}");
        } elseif (Storage::disk('public')->exists($scenePath)) {
            $sourceImagePath = storage_path("app/public/{$scenePath}");
        } else {
            return response()->json([
                'success' => false,
                'error' => "Không tìm thấy ảnh: {$imageFilename}"
            ], 404);
        }

        // Create chapter_covers directory
        $coversDir = "books/{$audioBook->id}/chapter_covers";
        Storage::disk('public')->makeDirectory($coversDir);
        $coversDirPath = storage_path("app/public/{$coversDir}");

        // Get FFmpeg path
        $ffmpegPath = env('FFMPEG_PATH', 'ffmpeg');

        // Font path for Vietnamese text (use system font that supports Vietnamese)
        $fontPath = $this->findVietnameseFont();

        // Convert hex colors to FFmpeg format (remove # and use 0x prefix or plain hex)
        $textColorHex = ltrim($textColor, '#');
        $outlineColorHex = ltrim($outlineColor, '#');

        $results = [];
        $chapters = !empty($chapterIds)
            ? AudioBookChapter::whereIn('id', $chapterIds)->where('audio_book_id', $audioBook->id)->get()
            : collect();

        foreach ($chapters as $chapter) {
            try {
                // Simple filename without timestamp - will replace old file
                $outputFilename = "chapter_{$chapter->chapter_number}.png";
                // Use forward slashes consistently and proper path
                $outputPath = str_replace('\\', '/', "{$coversDirPath}/{$outputFilename}");

                // Delete old cover image if exists (different filename pattern)
                if ($chapter->cover_image && Storage::disk('public')->exists($chapter->cover_image)) {
                    Storage::disk('public')->delete($chapter->cover_image);
                }

                // Prepare text
                $chapterTitle = trim((string) $chapter->title);
                if ($textMode === 'title') {
                    $chapterText = $chapterTitle !== '' ? $chapterTitle : "Chương " . $chapter->chapter_number;
                } elseif ($textMode === 'both') {
                    $chapterText = $chapterTitle !== ''
                        ? "Chương " . $chapter->chapter_number . ": " . $chapterTitle
                        : "Chương " . $chapter->chapter_number;
                } else {
                    $chapterText = "Chương " . $chapter->chapter_number;
                }

                // Build FFmpeg drawtext filter with custom options
                // Calculate absolute position based on percentage
                // text_w and text_h will be calculated by FFmpeg after measuring the text
                $xPosition = "w*{$positionX}/100-text_w/2"; // Centered horizontally at X%
                $yPosition = "h*{$positionY}/100-text_h/2"; // Positioned at Y%

                // Escape font path for FFmpeg (use forward slashes and escape colons on Windows)
                $escapedFontPath = str_replace(['\\', ':'], ['/', '\\:'], $fontPath);

                $drawTextFilter = sprintf(
                    "drawtext=fontfile='%s':text='%s':fontsize=%d:fontcolor=0x%s:borderw=%d:bordercolor=0x%s:x=%s:y=%s",
                    $escapedFontPath,
                    $this->escapeFFmpegText($chapterText),
                    $fontSize,
                    $textColorHex,
                    $outlineWidth,
                    $outlineColorHex,
                    $xPosition,
                    $yPosition
                );

                // FFmpeg command to add text overlay
                $command = sprintf(
                    '%s -y -i %s -vf "%s" -q:v 2 %s 2>&1',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($sourceImagePath),
                    $drawTextFilter,
                    escapeshellarg($outputPath)
                );

                Log::info('FFmpeg chapter cover command', [
                    'chapter_id' => $chapter->id,
                    'text_options' => [
                        'fontSize' => $fontSize,
                        'textColor' => $textColor,
                        'outlineColor' => $outlineColor,
                        'outlineWidth' => $outlineWidth,
                        'posX' => $positionX,
                        'posY' => $positionY
                    ],
                    'command' => $command
                ]);

                exec($command, $output, $returnCode);

                if ($returnCode !== 0) {
                    Log::error('FFmpeg failed', [
                        'chapter_id' => $chapter->id,
                        'output' => implode("\n", $output),
                        'return_code' => $returnCode
                    ]);
                    $results[] = [
                        'chapter_id' => $chapter->id,
                        'chapter_number' => $chapter->chapter_number,
                        'success' => false,
                        'error' => 'FFmpeg failed: ' . implode("\n", array_slice($output, -3))
                    ];
                    continue;
                }

                // Update chapter with new cover image
                $relativePath = "{$coversDir}/{$outputFilename}";
                $chapter->update(['cover_image' => $relativePath]);

                $results[] = [
                    'chapter_id' => $chapter->id,
                    'chapter_number' => $chapter->chapter_number,
                    'success' => true,
                    'cover_image' => asset("storage/{$relativePath}")
                ];
            } catch (\Exception $e) {
                Log::error('Chapter cover generation failed', [
                    'chapter_id' => $chapter->id,
                    'error' => $e->getMessage()
                ]);
                $results[] = [
                    'chapter_id' => $chapter->id,
                    'chapter_number' => $chapter->chapter_number,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Generate covers for segments (Phần)
        if (!empty($segmentIds)) {
            $segments = AudioBookVideoSegment::whereIn('id', $segmentIds)
                ->where('audio_book_id', $audioBook->id)
                ->orderBy('sort_order')
                ->get();

            foreach ($segments as $segment) {
                try {
                    $outputFilename = "segment_{$segment->id}.png";
                    $outputPath = str_replace('\\', '/', "{$coversDirPath}/{$outputFilename}");

                    $segmentText = $segment->name;

                    $escapedFontPath = str_replace(['\\', ':'], ['/', '\\:'], $fontPath);
                    $xPosition = "w*{$positionX}/100-text_w/2";
                    $yPosition = "h*{$positionY}/100-text_h/2";

                    $drawTextFilter = sprintf(
                        "drawtext=fontfile='%s':text='%s':fontsize=%d:fontcolor=0x%s:borderw=%d:bordercolor=0x%s:x=%s:y=%s",
                        $escapedFontPath,
                        $this->escapeFFmpegText($segmentText),
                        $fontSize,
                        $textColorHex,
                        $outlineWidth,
                        $outlineColorHex,
                        $xPosition,
                        $yPosition
                    );

                    $command = sprintf(
                        '%s -y -i %s -vf "%s" -q:v 2 %s 2>&1',
                        escapeshellarg($ffmpegPath),
                        escapeshellarg($sourceImagePath),
                        $drawTextFilter,
                        escapeshellarg($outputPath)
                    );

                    exec($command, $output, $returnCode);

                    if ($returnCode !== 0) {
                        $results[] = [
                            'segment_id' => $segment->id,
                            'segment_name' => $segment->name,
                            'success' => false,
                            'error' => 'FFmpeg failed'
                        ];
                        continue;
                    }
                    $relativePath = "{$coversDir}/{$outputFilename}";
                    $segment->update(['image_path' => $outputFilename, 'image_type' => 'chapter_covers']);

                    $results[] = [
                        'segment_id' => $segment->id,
                        'segment_name' => $segment->name,
                        'success' => true,
                        'cover_image' => asset("storage/{$relativePath}")
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'segment_id' => $segment->id,
                        'segment_name' => $segment->name,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $totalCount = count($results);

        return response()->json([
            'success' => true,
            'message' => "Đã tạo {$successCount}/{$totalCount} ảnh bìa",
            'results' => $results
        ]);
    }

    /**
     * Find a font that supports Vietnamese characters
     */
    private function findVietnameseFont(): string
    {
        // Windows fonts that support Vietnamese
        $windowsFonts = [
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            'C:/Windows/Fonts/tahoma.ttf',
            'C:/Windows/Fonts/tahomabd.ttf',
            'C:/Windows/Fonts/segoeui.ttf',
            'C:/Windows/Fonts/seguisb.ttf',
        ];

        // Linux fonts
        $linuxFonts = [
            public_path('fonts/DejaVuSans-Bold.ttf'),
            public_path('fonts/LiberationSans-Bold.ttf'),
        ];

        $fonts = PHP_OS_FAMILY === 'Windows' ? $windowsFonts : $linuxFonts;

        foreach ($fonts as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        // Fallback - let FFmpeg use default font
        return '';
    }

    /**
     * Escape text for FFmpeg drawtext filter
     */
    private function escapeFFmpegText(string $text): string
    {
        // Escape special characters for FFmpeg drawtext
        $text = str_replace(['\\', "'", ':', '%'], ['\\\\', "\\'", '\\:', '\\%'], $text);
        // Limit length for display
        if (mb_strlen($text) > 50) {
            $text = mb_substr($text, 0, 47) . '...';
        }
        return $text;
    }

    /**
     * Build FFmpeg drawtext filter for chapter cover
     * Shows chapter title at the bottom of the image
     */
    private function buildChapterCoverFilter(string $chapterNumber, string $chapterTitle, string $fontPath): string
    {
        $fontFile = $fontPath ? "fontfile='" . str_replace(['\\', ':'], ['/', '\\:'], $fontPath) . "':" : '';

        // Just show the chapter title from database (e.g. "Chương 3" or full title)
        // Escape the title for FFmpeg
        $escapedTitle = $this->escapeFFmpegText($chapterTitle);

        // Draw chapter title centered near bottom with white text and black border
        $filter = "drawtext={$fontFile}text='{$escapedTitle}':fontsize=42:fontcolor=white:borderw=3:bordercolor=black:x=(w-text_w)/2:y=h-120";

        return $filter;
    }

    /**
     * Get list of chapters for chapter cover generation
     */
    public function getChaptersForCover(AudioBook $audioBook)
    {
        $chapters = $audioBook->chapters()
            ->select('id', 'chapter_number', 'title', 'cover_image')
            ->orderBy('chapter_number')
            ->get()
            ->map(function ($chapter) {
                return [
                    'id' => $chapter->id,
                    'chapter_number' => $chapter->chapter_number,
                    'title' => $chapter->title,
                    'has_cover' => !empty($chapter->cover_image),
                    'cover_url' => $chapter->cover_image ? asset('storage/' . $chapter->cover_image) : null
                ];
            });

        return response()->json([
            'success' => true,
            'chapters' => $chapters
        ]);
    }

    /**
     * Generate MP4 video for a chapter using FFmpeg
     * Combines the full audio file (c_xxx_full.mp3) with chapter cover image
     */
    public function generateChapterVideo(AudioBook $audioBook, AudioBookChapter $chapter)
    {
        set_time_limit(0); // No PHP timeout for long video encoding

        try {
            // Verify chapter belongs to audiobook
            if ($chapter->audio_book_id !== $audioBook->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Chương không thuộc sách này'
                ], 400);
            }

            // Check if chapter has cover image
            if (empty($chapter->cover_image)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Chương chưa có ảnh bìa. Vui lòng tạo ảnh bìa trước.'
                ], 400);
            }

            // Check if chapter has full audio file
            // Format chapter number with leading zeros (e.g., 001, 002, ...)
            // Audio files are stored directly in books/{book_id}/ folder
            $chapterNumPadded = str_pad($chapter->chapter_number, 3, '0', STR_PAD_LEFT);
            $audioDir = "books/{$audioBook->id}";
            $fullAudioFilename = "c_{$chapterNumPadded}_full.mp3";
            $fullAudioPath = storage_path("app/public/{$audioDir}/{$fullAudioFilename}");

            if (!file_exists($fullAudioPath)) {
                // Try to auto-merge chunks if full file doesn't exist
                Log::info("Full audio not found, attempting auto-merge for chapter {$chapter->chapter_number}");

                $chapterController = app(AudioBookChapterController::class);
                $mergeResult = $chapterController->mergeChapterAudioEndpoint(
                    new \Illuminate\Http\Request(),
                    $audioBook,
                    $chapter
                );

                $mergeData = $mergeResult->getData(true);
                if (!($mergeData['success'] ?? false)) {
                    return response()->json([
                        'success' => false,
                        'error' => "Không thể ghép file full - " . ($mergeData['error'] ?? 'Unknown error')
                    ], 400);
                }

                // Check again after merge
                if (!file_exists($fullAudioPath)) {
                    return response()->json([
                        'success' => false,
                        'error' => "Chương chưa có file audio full ({$fullAudioFilename}). Merge không thành công."
                    ], 400);
                }

                Log::info("Auto-merged audio for chapter {$chapter->chapter_number}");
            }

            // Get cover image path
            $coverImagePath = storage_path("app/public/{$chapter->cover_image}");
            if (!file_exists($coverImagePath)) {
                return response()->json([
                    'success' => false,
                    'error' => 'File ảnh bìa không tồn tại'
                ], 400);
            }

            // Create MP4 output directory
            $mp4Dir = "books/{$audioBook->id}/mp4";
            $mp4DirPath = storage_path("app/public/{$mp4Dir}");
            if (!is_dir($mp4DirPath)) {
                mkdir($mp4DirPath, 0755, true);
            }

            // Output filename
            $outputFilename = "chapter_{$chapter->chapter_number}.mp4";
            $outputPath = "{$mp4DirPath}/{$outputFilename}";

            // Delete existing file if exists
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            // FFmpeg command to create video from image + audio
            // -loop 1: loop the image
            // -i image: input image
            // -i audio: input audio
            // -c:v libx264: video codec
            // -tune stillimage: optimize for still image
            // -c:a aac: audio codec
            // -b:a 192k: audio bitrate
            // -pix_fmt yuv420p: pixel format for compatibility
            // -shortest: stop when shortest input ends (the audio)
            $ffmpegPath = env('FFMPEG_PATH', 'ffmpeg');

            // Normalize paths for FFmpeg
            $imagePath = str_replace('\\', '/', $coverImagePath);
            $audioPath = str_replace('\\', '/', $fullAudioPath);
            $videoPath = str_replace('\\', '/', $outputPath);

            // Build video filter based on wave settings
            $waveEnabled = $audioBook->wave_enabled ?? false;
            // Use 720p for fast encoding (still image + audio doesn't benefit from higher res)
            $videoWidth = 1280;
            $videoHeight = 720;
            $baseFilter = "scale={$videoWidth}:{$videoHeight}:force_original_aspect_ratio=decrease,pad={$videoWidth}:{$videoHeight}:(ow-iw)/2:(oh-ih)/2";

            if ($waveEnabled) {
                $rawWaveType = $audioBook->wave_type ?? 'cline';
                // Map to valid FFmpeg showwaves modes (bar is not supported)
                $waveTypeMap = ['point' => 'point', 'line' => 'line', 'p2p' => 'p2p', 'cline' => 'cline', 'bar' => 'line'];
                $waveType = $waveTypeMap[$rawWaveType] ?? 'cline';
                $wavePosition = $audioBook->wave_position ?? 'bottom';
                $waveHeight = $audioBook->wave_height ?? 100;
                $waveColor = ltrim($audioBook->wave_color ?? '#00ff00', '#');
                $waveOpacity = $audioBook->wave_opacity ?? 0.8;

                // Calculate Y position for wave overlay (720p)
                switch ($wavePosition) {
                    case 'top':
                        $waveY = 20;
                        break;
                    case 'center':
                        $waveY = ($videoHeight - $waveHeight) / 2;
                        break;
                    case 'bottom':
                    default:
                        $waveY = $videoHeight - $waveHeight - 20;
                        break;
                }

                // Build FFmpeg filter_complex for wave overlay
                // Use rate=15 (lower framerate for wave rendering → much faster)
                // Use showwaves at 720p width to match video
                $filterComplex = sprintf(
                    '[0:v]%s[bg];[1:a]showwaves=s=%dx%d:mode=%s:colors=0x%s@%.1f:rate=15[wave];[bg][wave]overlay=0:%d:format=auto[out]',
                    $baseFilter,
                    $videoWidth,
                    $waveHeight,
                    $waveType,
                    $waveColor,
                    $waveOpacity,
                    $waveY
                );

                // FFmpeg command with wave overlay
                // -framerate 15: lower input framerate for still image (wave drives visual updates)
                // -preset ultrafast: fastest encoding
                // -crf 28: slightly lower quality for faster encode (still good for still image)
                // -threads 0: use all available CPU cores
                $command = sprintf(
                    '%s -y -loop 1 -framerate 15 -i %s -i %s -filter_complex "%s" -map "[out]" -map 1:a -c:v libx264 -preset ultrafast -tune stillimage -crf 28 -c:a aac -b:a 128k -pix_fmt yuv420p -shortest -threads 0 %s 2>&1',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($imagePath),
                    escapeshellarg($audioPath),
                    $filterComplex,
                    escapeshellarg($videoPath)
                );
            } else {
                // Command without wave (maximum speed optimization)
                // -framerate 1: only 1 input frame per second (still image duplicated)
                // -r 15: output 15fps (enough for still image playback)
                // -crf 28: lower quality = faster encode (imperceptible on still image)
                // -threads 0: use all available CPU cores
                $command = sprintf(
                    '%s -y -loop 1 -framerate 1 -i %s -i %s -c:v libx264 -preset ultrafast -tune stillimage -crf 28 -c:a aac -b:a 128k -pix_fmt yuv420p -r 15 -shortest -threads 0 -vf "%s" %s 2>&1',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($imagePath),
                    escapeshellarg($audioPath),
                    $baseFilter,
                    escapeshellarg($videoPath)
                );
            }

            Log::info('FFmpeg video generation command', [
                'chapter_id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'image' => $imagePath,
                'audio' => $audioPath,
                'output' => $videoPath,
                'wave_enabled' => $waveEnabled,
                'command' => $command
            ]);

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                Log::error('FFmpeg video generation failed', [
                    'chapter_id' => $chapter->id,
                    'output' => implode("\n", $output),
                    'return_code' => $returnCode
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'FFmpeg failed: ' . implode("\n", array_slice($output, -5))
                ], 500);
            }

            // Update chapter with video path
            $relativePath = "{$mp4Dir}/{$outputFilename}";
            $chapter->update(['video_path' => $relativePath]);

            // Get file size
            $fileSize = file_exists($outputPath) ? filesize($outputPath) : 0;
            $fileSizeMB = round($fileSize / 1024 / 1024, 2);

            return response()->json([
                'success' => true,
                'message' => "Video chương {$chapter->chapter_number} đã được tạo thành công ({$fileSizeMB} MB)",
                'video_url' => asset("storage/{$relativePath}"),
                'file_size' => $fileSizeMB
            ]);
        } catch (\Exception $e) {
            Log::error('Chapter video generation failed', [
                'chapter_id' => $chapter->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate if URL is a publicly accessible image URL
     */
    private function isPublicImageUrl(string $url): bool
    {
        // Must be HTTPS (D-ID requirement)
        if (!str_starts_with($url, 'https://')) {
            return false;
        }

        // Must end with valid image extension
        $validExtensions = ['jpg', 'jpeg', 'png'];
        $urlPath = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

        if (!in_array($extension, $validExtensions)) {
            return false;
        }

        // Must not be localhost or local domain
        $host = parse_url($url, PHP_URL_HOST);
        $localPatterns = [
            'localhost',
            '127.0.0.1',
            '.local',
            '.test',
            '.dev',
            '192.168.',
            '10.0.',
            '172.16.'
        ];

        foreach ($localPatterns as $pattern) {
            if (str_contains($host, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively delete a directory and all its contents
     */
    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($dir);
    }

    private function updateDescriptionVideoProgress(int $audioBookId, array $data): void
    {
        $payload = array_merge([
            'status' => 'processing',
            'percent' => 0,
            'message' => '',
            'updated_at' => now()->toIso8601String()
        ], $data);

        Cache::put("desc_video_progress_{$audioBookId}", $payload, now()->addMinutes(20));
    }

    private function resetDescriptionVideoProgress(int $audioBookId): void
    {
        Cache::forget("desc_video_progress_{$audioBookId}");
        Cache::forget("desc_video_log_{$audioBookId}");
        $this->updateDescriptionVideoProgress($audioBookId, [
            'status' => 'processing',
            'percent' => 1,
            'message' => 'Đang khởi tạo...'
        ]);
    }

    private function updateDescriptionVideoLog(int $audioBookId, string $line): void
    {
        $key = "desc_video_log_{$audioBookId}";
        $logs = Cache::get($key, []);
        $timestamp = now()->format('H:i:s');
        $logs[] = "[{$timestamp}] {$line}";
        if (count($logs) > 200) {
            $logs = array_slice($logs, -200);
        }
        Cache::put($key, $logs, now()->addMinutes(20));
    }

    private function runFfmpegWithProgress(
        string $command,
        int $audioBookId,
        float $totalDuration,
        int $baseStart,
        int $baseEnd
    ): array {
        $output = [];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            $this->updateDescriptionVideoLog($audioBookId, 'FFmpeg: khong the khoi tao process');
            return ['return_code' => 1, 'output' => []];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $lastPercent = 0;
        $lastLogAt = time();
        $buffer = '';

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 1);

            foreach ($read as $stream) {
                $data = stream_get_contents($stream);
                if ($data === false || $data === '') {
                    continue;
                }
                $buffer .= $data;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $output[] = $line;

                    if (str_starts_with($line, 'out_time_ms=')) {
                        $value = (int) str_replace('out_time_ms=', '', $line);
                        if ($totalDuration > 0) {
                            $percent = $baseStart + (int) round(($value / ($totalDuration * 1000000)) * ($baseEnd - $baseStart));
                            $percent = min($baseEnd, max($baseStart, $percent));
                            if ($percent !== $lastPercent) {
                                $lastPercent = $percent;
                                $this->updateDescriptionVideoProgress($audioBookId, [
                                    'status' => 'processing',
                                    'percent' => $percent,
                                    'message' => 'Đang tạo video...'
                                ]);
                            }
                        }
                    }

                    if (str_starts_with($line, 'frame=') || str_starts_with($line, 'fps=') || str_starts_with($line, 'speed=')) {
                        if (time() - $lastLogAt >= 2) {
                            $this->updateDescriptionVideoLog($audioBookId, "FFmpeg: {$line}");
                            $lastLogAt = time();
                        }
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
        }

        $remaining = trim($buffer);
        if ($remaining !== '') {
            $output[] = $remaining;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        return ['return_code' => $returnCode, 'output' => $output];
    }

    // ========== Auto Publish to YouTube Methods ==========

    /**
     * Get publish data: available videos and thumbnails for the audiobook.
     */
    public function getPublishData(AudioBook $audioBook)
    {
        $videos = [];

        // Description video
        if ($audioBook->description_scene_video) {
            $path = storage_path('app/public/' . $audioBook->description_scene_video);
            if (file_exists($path)) {
                $videos[] = [
                    'id' => 'desc_scene',
                    'type' => 'description',
                    'label' => 'Video giới thiệu (Scene)',
                    'path' => $audioBook->description_scene_video,
                    'duration' => $audioBook->description_scene_video_duration,
                ];
            }
        }

        if ($audioBook->description_lipsync_video) {
            $path = storage_path('app/public/' . $audioBook->description_lipsync_video);
            if (file_exists($path)) {
                $videos[] = [
                    'id' => 'desc_lipsync',
                    'type' => 'description',
                    'label' => 'Video giới thiệu (Lipsync)',
                    'path' => $audioBook->description_lipsync_video,
                    'duration' => $audioBook->description_lipsync_duration,
                ];
            }
        }

        // Chapter videos
        $totalChapters = $audioBook->chapters()->count();
        $chaptersWithVideo = 0;
        $chaptersWithoutVideo = [];

        foreach ($audioBook->chapters()->orderBy('chapter_number')->get() as $chapter) {
            if ($chapter->video_path) {
                $path = storage_path('app/public/' . $chapter->video_path);
                if (file_exists($path)) {
                    $videos[] = [
                        'id' => 'chapter_' . $chapter->id,
                        'type' => 'chapter',
                        'label' => 'Chương ' . $chapter->chapter_number . ': ' . $chapter->title,
                        'path' => $chapter->video_path,
                        'duration' => $chapter->total_duration,
                        'youtube_video_id' => $chapter->youtube_video_id,
                        'youtube_video_title' => $chapter->youtube_video_title,
                        'youtube_video_description' => $chapter->youtube_video_description,
                        'youtube_uploaded_at' => $chapter->youtube_uploaded_at,
                    ];
                    $chaptersWithVideo++;
                    continue;
                }
            }
            // Chapter without video
            $chaptersWithoutVideo[] = $chapter->chapter_number;
        }

        // Segment videos
        foreach ($audioBook->videoSegments()->orderBy('sort_order')->get() as $segment) {
            if ($segment->video_path && $segment->status === 'completed') {
                $path = storage_path('app/public/' . $segment->video_path);
                if (file_exists($path)) {
                    $videos[] = [
                        'id' => 'segment_' . $segment->id,
                        'type' => 'segment',
                        'label' => $segment->name ?: ('Phần ' . $segment->sort_order),
                        'path' => $segment->video_path,
                        'duration' => $segment->video_duration,
                        'youtube_video_id' => $segment->youtube_video_id ?? null,
                        'youtube_video_title' => $segment->youtube_video_title ?? null,
                        'youtube_video_description' => $segment->youtube_video_description ?? null,
                        'youtube_uploaded_at' => $segment->youtube_uploaded_at ?? null,
                    ];
                }
            }
        }

        // Include AI Shorts (completed video files)
        try {
            $shortItems = $this->loadShortVideoItems($audioBook->id);
            foreach ($shortItems as $key => $item) {
                $videoRel = ltrim((string)($item['video_path'] ?? ''), '/');
                if ($videoRel === '') continue;
                $abs = storage_path('app/public/' . $videoRel);
                if (!is_file($abs)) continue;

                $index = (int)($item['index'] ?? ($key + 1));
                $labelTitle = trim((string)($item['title'] ?? 'Short #' . $index));
                $shortTitle = trim((string)($item['youtube_video_title'] ?? $labelTitle));
                $shortDescription = trim((string)($item['youtube_video_description'] ?? ($item['script'] ?? '')));
                $shortYoutubeId = trim((string)($item['youtube_video_id'] ?? ''));
                $videos[] = [
                    'id' => 'shortai_' . $index,
                    'type' => 'short',
                    'origin' => 'shorts',
                    'label' => ($labelTitle !== '' ? $labelTitle : ('Short #' . $index)) . ' (AI Shorts)',
                    'path' => $videoRel,
                    'duration' => $item['duration'] ?? $this->getVideoDuration($abs),
                    'youtube_video_id' => $shortYoutubeId !== '' ? $shortYoutubeId : null,
                    'youtube_video_title' => $shortTitle !== '' ? $shortTitle : null,
                    'youtube_video_description' => $shortDescription !== '' ? $shortDescription : null,
                    'youtube_uploaded_at' => $item['youtube_uploaded_at'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            // soft-fail: do not block publish data on shorts errors
            \Log::warning('Load AI shorts for publish data failed', [
                'audio_book_id' => $audioBook->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Include Clipping composed videos
        try {
            $clips = $this->loadClips($audioBook->id);
            foreach ($clips as $clip) {
                $status = (string)($clip['status'] ?? '');
                $rel = ltrim((string)($clip['composed_path'] ?? ''), '/');
                if ($status !== 'composed' || $rel === '') continue;
                $abs = storage_path('app/public/' . $rel);
                if (!is_file($abs)) continue;

                $clipId = (string)($clip['id'] ?? md5($rel));
                $hook = trim((string)($clip['hook_title'] ?? 'Clip'));
                $shortTitle = trim((string)($clip['youtube_video_title'] ?? $hook));
                $shortDescription = trim((string)($clip['youtube_video_description'] ?? ''));
                if ($shortDescription === '') {
                    $quote = trim((string)($clip['quote'] ?? ''));
                    $cta = trim((string)($clip['cta_narration'] ?? ($clip['cta'] ?? '')));
                    $descParts = array_values(array_filter([$quote, $cta], fn($value) => $value !== ''));
                    if (!empty($descParts)) {
                        $shortDescription = implode("\n\n", $descParts);
                    }
                }
                $shortYoutubeId = trim((string)($clip['youtube_video_id'] ?? ''));
                $videos[] = [
                    'id' => 'shortclip_' . $clipId,
                    'type' => 'short',
                    'origin' => 'clipping',
                    'label' => ($hook !== '' ? $hook : 'Clip') . ' (Clipping)',
                    'path' => $rel,
                    'duration' => $this->getVideoDuration($abs),
                    'youtube_video_id' => $shortYoutubeId !== '' ? $shortYoutubeId : null,
                    'youtube_video_title' => $shortTitle !== '' ? $shortTitle : null,
                    'youtube_video_description' => $shortDescription !== '' ? $shortDescription : null,
                    'youtube_uploaded_at' => $clip['youtube_uploaded_at'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            \Log::warning('Load clipping videos for publish data failed', [
                'audio_book_id' => $audioBook->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Thumbnails from media gallery
        $media = $this->imageService->getExistingMedia($audioBook->id);
        $thumbnails = $media['thumbnails'] ?? [];

        return response()->json([
            'success' => true,
            'videos' => $videos,
            'thumbnails' => $thumbnails,
            'total_chapters' => $totalChapters,
            'chapters_with_video' => $chaptersWithVideo,
            'chapters_without_video' => $chaptersWithoutVideo,
            'saved_meta' => [
                'youtube_playlist_id' => $audioBook->youtube_playlist_id,
                'youtube_playlist_title' => $audioBook->youtube_playlist_title,
                'youtube_video_title' => $audioBook->youtube_video_title,
                'youtube_video_description' => $audioBook->youtube_video_description,
                'youtube_video_tags' => $audioBook->youtube_video_tags,
            ],
        ]);
    }

    /**
     * Fetch existing YouTube playlists for the channel.
     */
    public function getYoutubePlaylists(AudioBook $audioBook)
    {
        $channel = $audioBook->youtubeChannel;
        if (!$channel) {
            return response()->json(['success' => false, 'error' => 'Audiobook chưa được gán kênh YouTube.'], 400);
        }

        $accessToken = YouTubeChannelController::getValidAccessToken($channel);
        if (!$accessToken) {
            return response()->json(['success' => false, 'error' => 'Không có access token YouTube. Vui lòng kết nối lại OAuth.'], 401);
        }

        try {
            $client = new \GuzzleHttp\Client();

            // Fetch playlists from YouTube API
            $response = $client->get('https://www.googleapis.com/youtube/v3/playlists', [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                ],
                'query' => [
                    'part' => 'snippet,contentDetails',
                    'mine' => 'true',
                    'maxResults' => 50,
                ],
                'timeout' => 30,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $playlists = [];

            foreach ($result['items'] ?? [] as $item) {
                $playlists[] = [
                    'id' => $item['id'],
                    'title' => $item['snippet']['title'],
                    'description' => $item['snippet']['description'] ?? '',
                    'video_count' => $item['contentDetails']['itemCount'] ?? 0,
                    'published_at' => $item['snippet']['publishedAt'] ?? null,
                ];
            }

            // Include currently saved playlist if exists
            $currentPlaylistId = $audioBook->youtube_playlist_id;
            $currentPlaylistTitle = $audioBook->youtube_playlist_title;

            return response()->json([
                'success' => true,
                'playlists' => $playlists,
                'current_playlist_id' => $currentPlaylistId,
                'current_playlist_title' => $currentPlaylistTitle,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch YouTube playlists', ['error' => $e->getMessage(), 'audiobook' => $audioBook->id]);
            return response()->json([
                'success' => false,
                'error' => 'Không thể lấy danh sách playlists: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate YouTube video title or description using AI (Gemini).
     */
    public function generateVideoMeta(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'type' => 'required|string|in:title,description',
            'items' => 'nullable|array',
            'items.*.label' => 'nullable|string',
            'items.*.duration' => 'nullable|numeric',
            'items.*.type' => 'nullable|string',
        ]);

        $type = $request->input('type');
        $channelName = $audioBook->youtubeChannel?->title ?? '';

        try {
            $client = new \GuzzleHttp\Client();
            $apiKey = config('services.gemini.api_key');

            if ($type === 'title') {
                $prompt = "Bạn là chuyên gia YouTube SEO. Hãy viết MỘT tiêu đề YouTube hấp dẫn, tối ưu SEO cho video audiobook/sách nói sau:\n\n";
                $prompt .= "Tên sách: {$audioBook->title}\n";
                if ($audioBook->author) $prompt .= "Tác giả: {$audioBook->author}\n";
                if ($audioBook->category) $prompt .= "Thể loại: {$audioBook->category}\n";
                if ($channelName) $prompt .= "Kênh: {$channelName}\n";
                $prompt .= "\nYêu cầu:\n";
                $prompt .= "- Tiêu đề tiếng Việt, hấp dẫn, tối đa 100 ký tự\n";
                $prompt .= "- Bao gồm từ khóa: sách nói, audiobook, tên sách, tên tác giả\n";
                $prompt .= "- Gợi cảm xúc tò mò, muốn nghe\n";
                $prompt .= "- Chỉ trả về tiêu đề, không giải thích\n";
                $prompt .= "\nNgoài ra hãy gợi ý tags (tối đa 10 tags, phân cách bằng dấu phẩy) ở dòng thứ 2.";

                $response = $client->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}", [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['temperature' => 0.8, 'maxOutputTokens' => 300]
                    ],
                    'timeout' => 30
                ]);

                $result = json_decode($response->getBody()->getContents(), true);
                $text = trim($result['candidates'][0]['content']['parts'][0]['text'] ?? '');
                $lines = array_filter(array_map('trim', explode("\n", $text)));
                $title = array_shift($lines) ?? $audioBook->title;
                $tags = implode(', ', array_slice($lines, 0, 1)) ?: "audiobook, sách nói, {$audioBook->author}, {$audioBook->category}";

                // Save to database
                $audioBook->update([
                    'youtube_video_title' => $title,
                    'youtube_video_tags' => $tags,
                ]);

                return response()->json(['success' => true, 'title' => $title, 'tags' => $tags]);
            } else {
                $timestampSection = $this->buildTimestampSection($audioBook, $request->input('items', []));

                $prompt = "Bạn là chuyên gia YouTube SEO. Hãy viết mô tả YouTube chuyên nghiệp cho video audiobook/sách nói sau:\n\n";
                $prompt .= "Tên sách: {$audioBook->title}\n";
                if ($audioBook->author) $prompt .= "Tác giả: {$audioBook->author}\n";
                if ($audioBook->category) $prompt .= "Thể loại: {$audioBook->category}\n";
                if ($channelName) $prompt .= "Kênh: {$channelName}\n";
                if ($audioBook->description) $prompt .= "\nMô tả gốc (tham khảo):\n" . mb_substr($audioBook->description, 0, 500) . "\n";
                if ($timestampSection !== '') {
                    $prompt .= "\nMốc thời gian (timestamps) tính theo thời lượng TTS đã tạo:\n";
                    $prompt .= $timestampSection . "\n";
                }
                $prompt .= "\nYêu cầu:\n";
                $prompt .= "- Viết bằng tiếng Việt, tối đa 300 từ\n";
                $prompt .= "- KHÔNG sử dụng ký tự markdown như ** hoặc __ để in đậm. Thay vào đó dùng emoji phù hợp (📚, 🎧, ✨, 🔥, 👇, ❤️, 🎵, 📖, ⭐, 💡...) để làm nổi bật các phần\n";
                $prompt .= "- Bao gồm: giới thiệu ngắn sách, lý do nên nghe, CTA (đăng ký, like, bình luận)\n";
                if ($timestampSection !== '') {
                    $prompt .= "- Giữ nguyên danh sách timestamps theo đúng thứ tự, đặt ở cuối mô tả\n";
                }
                $prompt .= "- Thêm hashtag ở cuối (#audiobook #sachnoiviet ...)\n";
                $prompt .= "- Chỉ trả về nội dung mô tả, không giải thích";

                $response = $client->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}", [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 800]
                    ],
                    'timeout' => 30
                ]);

                $result = json_decode($response->getBody()->getContents(), true);
                $description = trim($result['candidates'][0]['content']['parts'][0]['text'] ?? '');

                if ($timestampSection !== '' && stripos($description, 'mốc thời gian') === false) {
                    $description = rtrim($description) . "\n\n⏱️ Mốc thời gian:\n" . $timestampSection;
                }

                // Save to database
                $audioBook->update([
                    'youtube_video_description' => $description,
                ]);

                return response()->json(['success' => true, 'description' => $description]);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Lỗi AI: ' . $e->getMessage()], 500);
        }
    }

    private function buildTimestampSection(AudioBook $audioBook, array $items): string
    {
        $lines = [];
        $elapsed = 0;

        $chapters = $audioBook->chapters()
            ->select(['id', 'chapter_number', 'title', 'total_duration'])
            ->orderBy('chapter_number')
            ->get();
        $chaptersById = $chapters->keyBy('id');
        $chaptersByNumber = $chapters->keyBy('chapter_number');

        $segmentIds = [];
        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'segment') {
                continue;
            }
            $segmentId = $this->parsePrefixedId($item['id'] ?? null, 'segment_');
            if ($segmentId) {
                $segmentIds[] = $segmentId;
            }
        }

        $segmentsById = collect();
        if (!empty($segmentIds)) {
            $segmentsById = $audioBook->videoSegments()
                ->whereIn('id', array_values(array_unique($segmentIds)))
                ->get()
                ->keyBy('id');
        }

        foreach ($items as $item) {
            $type = $item['type'] ?? null;
            $duration = isset($item['duration']) ? (float) $item['duration'] : 0;
            $label = trim((string) ($item['label'] ?? ''));

            if ($type === 'description') {
                continue;
            }

            if ($type === 'segment') {
                $segmentId = $this->parsePrefixedId($item['id'] ?? null, 'segment_');
                $segment = $segmentId ? $segmentsById->get($segmentId) : null;
                $chapterNums = is_array($segment?->chapters) ? $segment->chapters : [];

                foreach ($chapterNums as $chapterNum) {
                    $chapterNum = (int) $chapterNum;
                    $chapter = $chaptersByNumber->get($chapterNum);
                    if (!$chapter) {
                        continue;
                    }
                    $chapterDuration = (float) ($chapter->total_duration ?? 0);
                    if ($chapterDuration <= 0) {
                        continue;
                    }

                    $chapterLabel = 'Chương ' . $chapter->chapter_number . ': ' . $chapter->title;
                    $lines[] = $this->formatTimestamp($elapsed) . ' ' . $chapterLabel;
                    $elapsed += $chapterDuration;
                }

                continue;
            }

            if ($type === 'chapter') {
                $chapterId = $this->parsePrefixedId($item['id'] ?? null, 'chapter_');
                $chapter = $chapterId ? $chaptersById->get($chapterId) : null;
                if ($chapter) {
                    $duration = (float) ($chapter->total_duration ?? 0);
                    $label = 'Chương ' . $chapter->chapter_number . ': ' . $chapter->title;
                }
            }

            if ($duration <= 0 || $label === '') {
                continue;
            }

            $lines[] = $this->formatTimestamp($elapsed) . ' ' . $label;
            $elapsed += $duration;
        }

        return implode("\n", $lines);
    }

    private function parsePrefixedId(?string $value, string $prefix): ?int
    {
        if (!$value || !str_starts_with($value, $prefix)) {
            return null;
        }

        $id = (int) substr($value, strlen($prefix));
        return $id > 0 ? $id : null;
    }

    private function formatTimestamp(float $seconds): string
    {
        $total = (int) round($seconds);
        $hours = (int) floor($total / 3600);
        $mins = (int) floor(($total % 3600) / 60);
        $secs = (int) ($total % 60);

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $mins, $secs);
        }

        return sprintf('%02d:%02d', $mins, $secs);
    }

    /**
     * Generate playlist child meta: convert main title/description into versions for each chapter.
     */
    public function generatePlaylistMeta(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'title' => 'required|string|max:500',
            'description' => 'nullable|string',
            'chapters' => 'required|array|min:2',
            'chapters.*.id' => 'required|string',
            'chapters.*.label' => 'required|string',
            'chapters.*.type' => 'nullable|string',
            'chapters.*.duration' => 'nullable|numeric',
        ]);

        $mainTitle = $request->input('title');
        $mainDesc = $request->input('description', '');
        $chapters = $request->input('chapters');

        $chapterList = collect($chapters)->map(fn($c, $i) => ($i + 1) . ". {$c['label']}")->implode("\n");

        $prompt = "Bạn là chuyên gia YouTube SEO. Tôi có 1 playlist audiobook/sách nói với tiêu đề chung và mô tả chung. Hãy tạo phiên bản tiêu đề và mô tả riêng cho từng video trong playlist.\n\n";
        $prompt .= "TIÊU ĐỀ CHUNG: {$mainTitle}\n";
        $prompt .= "MÔ TẢ CHUNG: {$mainDesc}\n\n";
        $prompt .= "DANH SÁCH VIDEO:\n{$chapterList}\n\n";
        $prompt .= "YÊU CẦU:\n";
        $prompt .= "- Mỗi video cần 1 tiêu đề riêng (tối đa 100 ký tự) và 1 mô tả đầy đủ (không rút gọn)\n";
        $prompt .= "- Tiêu đề phải bao gồm tên sách + số chương/phần\n";
        $prompt .= "- Mô tả đầy đủ, hấp dẫn, có CTA, giữ tinh thần của mô tả chung\n";
        $prompt .= "- Trả về JSON array, mỗi phần tử có 'title' và 'description'\n";
        $prompt .= "- Chỉ trả về JSON, không giải thích\n";
        $prompt .= "Ví dụ: [{\"title\": \"...\", \"description\": \"...\"}]";

        try {
            $client = new \GuzzleHttp\Client();
            $apiKey = config('services.gemini.api_key');

            $response = $client->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}", [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 8192,
                        'responseMimeType' => 'application/json',
                    ]
                ],
                'timeout' => 90
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $text = trim($result['candidates'][0]['content']['parts'][0]['text'] ?? '');

            $this->safeLog('info', 'generateVideoMeta AI response', ['text_length' => mb_strlen($text), 'text_preview' => mb_substr($text, 0, 300)]);

            // Try direct JSON parse first (responseMimeType should return clean JSON)
            $items = json_decode($text, true);

            // Fallback: extract JSON array from markdown code blocks or mixed text
            if (!is_array($items)) {
                // Remove markdown code block wrappers
                $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $text);
                $cleaned = preg_replace('/\s*```\s*$/', '', $cleaned);
                $items = json_decode(trim($cleaned), true);
            }

            // Last resort: find JSON array in text
            if (!is_array($items) && preg_match('/\[[\s\S]*\]/s', $text, $matches)) {
                $items = json_decode($matches[0], true);
            }

            if (!is_array($items)) {
                $this->safeLog('error', 'generateVideoMeta: Failed to parse AI JSON', ['raw_text' => mb_substr($text, 0, 1000)]);
                throw new \Exception('AI không trả về JSON hợp lệ. Response: ' . mb_substr($text, 0, 200));
            }

            // Map back to chapters
            $mapped = [];
            foreach ($chapters as $i => $chapter) {
                $timestampSection = $this->buildTimestampSection($audioBook, [$chapter]);
                $description = $items[$i]['description'] ?? $mainDesc;
                if ($timestampSection !== '' && stripos($description, 'mốc thời gian') === false) {
                    $description = rtrim($description) . "\n\n⏱️ Mốc thời gian:\n" . $timestampSection;
                }

                $mapped[] = [
                    'id' => $chapter['id'],
                    'source_label' => $chapter['label'],
                    'title' => $items[$i]['title'] ?? "{$mainTitle} - Phần " . ($i + 1),
                    'description' => $description,
                ];
            }

            // Save generated meta to DB for each chapter
            foreach ($mapped as $item) {
                $chapterId = str_replace('chapter_', '', $item['id']);
                AudioBookChapter::where('id', $chapterId)
                    ->where('audio_book_id', $audioBook->id)
                    ->update([
                        'youtube_video_title' => $item['title'],
                        'youtube_video_description' => $item['description'],
                    ]);
            }

            return response()->json(['success' => true, 'items' => $mapped]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Lỗi AI: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Upload a single video to YouTube (also handles Shorts).
     */
    public function uploadToYoutube(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'video_id' => 'required|string',
            'video_type' => 'required|string|in:description,chapter,segment,short',
            'title' => 'required|string|max:100',
            'description' => 'nullable|string',
            'tags' => 'nullable|string',
            'privacy' => 'required|string|in:public,unlisted,private',
            'thumbnail_path' => 'nullable|string',
            'is_shorts' => 'nullable|boolean',
        ]);

        $channel = $audioBook->youtubeChannel;
        if (!$channel) {
            return response()->json(['success' => false, 'error' => 'Audiobook chưa được gán kênh YouTube.'], 400);
        }

        $accessToken = YouTubeChannelController::getValidAccessToken($channel);
        if (!$accessToken) {
            return response()->json(['success' => false, 'error' => 'Không có access token YouTube. Vui lòng kết nối lại OAuth.'], 401);
        }

        // Resolve video file path
        $videoPath = $this->resolveVideoPath($audioBook, $request->input('video_id'), $request->input('video_type'));
        if (!$videoPath || !file_exists($videoPath)) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy file video.'], 404);
        }

        $title = $request->input('title');
        $description = ($request->input('description') ?? '');
        $tags = array_map('trim', explode(',', $request->input('tags', '')));
        $tags = array_filter($tags);
        $privacy = $request->input('privacy', 'private');
        $isShorts = $request->boolean('is_shorts');

        if ($isShorts && !str_contains($title, '#Shorts')) {
            $title .= ' #Shorts';
        }

        try {
            $videoId = $this->youtubeUploadVideo($accessToken, $videoPath, $title, $description, $tags, $privacy);

            // Upload thumbnail if selected
            $thumbnailPath = $request->input('thumbnail_path');
            $thumbnailWarning = null;
            if ($thumbnailPath) {
                $fullThumbPath = storage_path('app/public/' . $thumbnailPath);
                if (file_exists($fullThumbPath)) {
                    sleep(3); // Wait for YouTube to process the video
                    $thumbSuccess = $this->youtubeSetThumbnail($accessToken, $videoId, $fullThumbPath);
                    if (!$thumbSuccess) {
                        $thumbnailWarning = 'Không thể đặt thumbnail. Kênh YouTube cần xác minh số điện thoại để sử dụng custom thumbnail.';
                    }
                }
            }

            $videoUrl = "https://www.youtube.com/watch?v={$videoId}";
            if ($isShorts) {
                $videoUrl = "https://www.youtube.com/shorts/{$videoId}";
            }

            // Save tracking data to chapter if it's a chapter video
            if ($request->input('video_type') === 'chapter') {
                $chapterId = str_replace('chapter_', '', $request->input('video_id'));
                $chapter = AudioBookChapter::where('audio_book_id', $audioBook->id)
                    ->where('id', $chapterId)
                    ->first();

                if ($chapter) {
                    $chapter->update([
                        'youtube_video_id' => $videoId,
                        'youtube_video_title' => $title,
                        'youtube_video_description' => $description,
                        'youtube_uploaded_at' => now(),
                    ]);
                }
            }

            // Save tracking data to segment if it's a segment video
            if ($request->input('video_type') === 'segment') {
                $segmentId = str_replace('segment_', '', $request->input('video_id'));
                $segment = AudioBookVideoSegment::where('audio_book_id', $audioBook->id)
                    ->where('id', $segmentId)
                    ->first();

                if ($segment) {
                    $segment->update([
                        'youtube_video_id' => $videoId,
                        'youtube_video_title' => $title,
                        'youtube_video_description' => $description,
                        'youtube_uploaded_at' => now(),
                    ]);
                }
            }

            // Save tracking data to short metadata store (shorts.json / clips.json)
            if ($request->input('video_type') === 'short') {
                try {
                    $this->persistShortPublishMeta(
                        $audioBook,
                        (string) $request->input('video_id'),
                        (string) $videoId,
                        (string) $title,
                        (string) $description
                    );
                } catch (\Throwable $shortMetaError) {
                    Log::warning('Persist short publish metadata failed', [
                        'audio_book_id' => $audioBook->id,
                        'video_id' => (string) $request->input('video_id'),
                        'error' => $shortMetaError->getMessage(),
                    ]);
                }
            }

            $response = [
                'success' => true,
                'video_id' => $videoId,
                'video_url' => $videoUrl,
            ];

            if ($thumbnailWarning) {
                $response['thumbnail_warning'] = $thumbnailWarning;
            }

            return response()->json($response);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $errorData = json_decode($responseBody, true);
            $errorMessage = $errorData['error']['message'] ?? $e->getMessage();
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 500;

            Log::error('YouTube upload failed - Client Error', [
                'error' => $errorMessage,
                'status' => $statusCode,
                'audiobook' => $audioBook->id,
                'response' => $responseBody
            ]);

            // 401 Unauthorized — force refresh token
            if ($statusCode === 401) {
                $newToken = YouTubeChannelController::refreshAccessToken($channel);
                if ($newToken) {
                    return response()->json([
                        'success' => false,
                        'error' => '❌ Token YouTube đã hết hạn, đã tự động refresh. Vui lòng thử lại.',
                        'token_refreshed' => true,
                    ], 401);
                } else {
                    $channel->update(['youtube_connected' => false]);
                    return response()->json([
                        'success' => false,
                        'error' => '❌ Token YouTube không hợp lệ. Vui lòng kết nối lại OAuth trong trang kênh YouTube.',
                        'need_reconnect' => true,
                    ], 401);
                }
            }

            // Check for specific YouTube errors
            if (str_contains($errorMessage, 'exceeded the number of videos')) {
                return response()->json([
                    'success' => false,
                    'error' => '❌ Giới hạn upload YouTube',
                    'details' => 'Bạn đã vượt quá giới hạn upload video của YouTube. Các giới hạn phổ biến:',
                    'limits' => [
                        '• Tài khoản chưa xác minh: 6 videos/ngày',
                        '• Quota API: ~6 videos/ngày (10,000 units)',
                        '• Cần chờ 24 giờ để reset quota',
                    ],
                    'solutions' => [
                        '1. Xác minh kênh YouTube (khuyến nghị): https://www.youtube.com/verify',
                        '2. Chờ 24 giờ để quota được reset',
                        '3. Liên hệ Google để tăng quota: https://support.google.com/youtube/contact/yt_api_form',
                    ]
                ], 429);
            }

            if (str_contains($errorMessage, 'quota')) {
                return response()->json([
                    'success' => false,
                    'error' => '❌ Vượt quá quota API',
                    'details' => 'API quota của YouTube đã hết. Vui lòng chờ 24 giờ hoặc liên hệ Google để tăng quota.',
                ], 429);
            }

            return response()->json([
                'success' => false,
                'error' => '❌ Upload thất bại: ' . $errorMessage,
                'details' => $errorMessage
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('YouTube upload failed', ['error' => $e->getMessage(), 'audiobook' => $audioBook->id]);
            return response()->json([
                'success' => false,
                'error' => '❌ Upload thất bại: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Queue a single video upload to YouTube (async).
     */
    public function uploadToYoutubeAsync(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'video_id' => 'required|string',
            'video_type' => 'required|string|in:description,chapter,segment,short',
            'title' => 'required|string|max:100',
            'description' => 'nullable|string',
            'tags' => 'nullable|string',
            'privacy' => 'required|string|in:public,unlisted,private',
            'thumbnail_path' => 'nullable|string',
            'is_shorts' => 'nullable|boolean',
        ]);

        $this->initPublishProgress($audioBook->id, 'queued', 'Da dua vao hang doi, co the tat trinh duyet.');
        PublishYoutubeJob::dispatch($audioBook->id, 'upload', $request->all());

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'Da dua vao hang doi xu ly.'
        ]);
    }

    /**
     * Create a YouTube playlist and upload multiple videos into it.
     */
    public function createPlaylistAndUpload(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'playlist_name' => 'required|string|max:150',
            'playlist_description' => 'nullable|string',
            'privacy' => 'required|string|in:public,unlisted,private',
            'thumbnail_path' => 'nullable|string',
            'tags' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.video_id' => 'required|string',
            'items.*.video_type' => 'required|string|in:description,chapter,segment',
            'items.*.title' => 'required|string|max:100',
            'items.*.description' => 'nullable|string',
        ]);

        $channel = $audioBook->youtubeChannel;
        if (!$channel) {
            return response()->json(['success' => false, 'error' => 'Audiobook chưa được gán kênh YouTube.'], 400);
        }

        $accessToken = YouTubeChannelController::getValidAccessToken($channel);
        if (!$accessToken) {
            return response()->json(['success' => false, 'error' => 'Không có access token YouTube. Vui lòng kết nối lại OAuth.'], 401);
        }

        $privacy = $request->input('privacy', 'private');
        $tags = array_filter(array_map('trim', explode(',', $request->input('tags', ''))));
        $thumbnailPath = $request->input('thumbnail_path');
        $fullThumbPath = $thumbnailPath ? storage_path('app/public/' . $thumbnailPath) : null;

        try {
            // Step 1: Create playlist
            $playlistId = $this->youtubeCreatePlaylist(
                $accessToken,
                (string)($request->input('playlist_name') ?? ''),
                (string)($request->input('playlist_description') ?? ''),
                $privacy
            );

            // Step 2: Upload each video and add to playlist
            $uploadedVideos = [];
            $thumbnailFailed = [];
            $videoIdsForThumbnail = []; // Track video IDs to retry thumbnail later

            foreach ($request->input('items') as $index => $item) {
                $videoPath = $this->resolveVideoPath($audioBook, $item['video_id'], $item['video_type']);
                if (!$videoPath || !file_exists($videoPath)) {
                    Log::warning("Skipping video - file not found", ['video_id' => $item['video_id']]);
                    continue;
                }

                $videoId = $this->youtubeUploadVideo(
                    $accessToken,
                    $videoPath,
                    $item['title'],
                    $item['description'] ?? '',
                    $tags,
                    $privacy
                );

                // Add to playlist first (this is more reliable)
                $this->youtubeAddToPlaylist($accessToken, $playlistId, $videoId);

                // Save tracking data to chapter
                if ($item['video_type'] === 'chapter') {
                    $chapterId = str_replace('chapter_', '', $item['video_id']);
                    AudioBookChapter::where('id', $chapterId)
                        ->where('audio_book_id', $audioBook->id)
                        ->update([
                            'youtube_video_id' => $videoId,
                            'youtube_video_title' => $item['title'],
                            'youtube_video_description' => $item['description'] ?? '',
                            'youtube_uploaded_at' => now(),
                        ]);
                }

                // Save tracking data to segment
                if ($item['video_type'] === 'segment') {
                    $segmentId = str_replace('segment_', '', $item['video_id']);
                    AudioBookVideoSegment::where('id', $segmentId)
                        ->where('audio_book_id', $audioBook->id)
                        ->update([
                            'youtube_video_id' => $videoId,
                            'youtube_video_title' => $item['title'],
                            'youtube_video_description' => $item['description'] ?? '',
                            'youtube_uploaded_at' => now(),
                        ]);
                }

                $videoIdsForThumbnail[] = [
                    'videoId' => $videoId,
                    'title' => $item['title'],
                ];

                $uploadedVideos[] = [
                    'title' => $item['title'],
                    'video_id' => $videoId,
                    'url' => "https://www.youtube.com/watch?v={$videoId}",
                ];
            }

            // Step 3: Set thumbnails after all uploads complete
            // YouTube needs processing time, so we set thumbnails after all videos are uploaded
            if ($fullThumbPath && file_exists($fullThumbPath) && !empty($videoIdsForThumbnail)) {
                // Wait a bit for YouTube to process the uploads
                sleep(5);

                foreach ($videoIdsForThumbnail as $item) {
                    $thumbSuccess = $this->youtubeSetThumbnail($accessToken, $item['videoId'], $fullThumbPath);
                    if (!$thumbSuccess) {
                        $thumbnailFailed[] = $item['title'];
                    }
                }
            }

            // Save playlist info to audiobook
            $audioBook->update([
                'youtube_playlist_id' => $playlistId,
                'youtube_playlist_title' => $request->input('playlist_name'),
            ]);

            $response = [
                'success' => true,
                'playlist_id' => $playlistId,
                'playlist_url' => "https://www.youtube.com/playlist?list={$playlistId}",
                'uploaded_videos' => $uploadedVideos,
            ];

            if (!empty($thumbnailFailed)) {
                $response['thumbnail_warning'] = 'Không thể đặt thumbnail cho ' . count($thumbnailFailed) . ' video: ' . implode(', ', $thumbnailFailed) . '. Kênh YouTube cần xác minh số điện thoại để sử dụng custom thumbnail.';
            }

            return response()->json($response);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $errorData = json_decode($responseBody, true);
            $errorMessage = $errorData['error']['message'] ?? $e->getMessage();
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 500;

            Log::error('YouTube playlist creation failed - Client Error', [
                'error' => $errorMessage,
                'status' => $statusCode,
                'audiobook' => $audioBook->id,
                'response' => $responseBody,
                'uploaded_videos' => $uploadedVideos ?? []
            ]);

            // 401 Unauthorized — token invalid, try force refresh
            if ($statusCode === 401) {
                Log::info('YouTube: Token invalid, attempting force refresh', ['audiobook' => $audioBook->id]);
                $newToken = YouTubeChannelController::refreshAccessToken($channel);
                if ($newToken) {
                    return response()->json([
                        'success' => false,
                        'error' => '❌ Token YouTube đã hết hạn, đã tự động refresh. Vui lòng thử lại.',
                        'token_refreshed' => true,
                    ], 401);
                } else {
                    $channel->update(['youtube_connected' => false]);
                    return response()->json([
                        'success' => false,
                        'error' => '❌ Token YouTube không hợp lệ. Vui lòng kết nối lại OAuth trong trang kênh YouTube.',
                        'need_reconnect' => true,
                    ], 401);
                }
            }

            // Check for specific YouTube errors
            if (str_contains($errorMessage, 'exceeded the number of videos')) {
                return response()->json([
                    'success' => false,
                    'error' => '❌ Giới hạn upload YouTube',
                    'details' => 'Bạn đã vượt quá giới hạn upload video của YouTube.',
                    'uploaded_count' => count($uploadedVideos ?? []),
                    'uploaded_videos' => $uploadedVideos ?? [],
                    'limits' => [
                        '• Tài khoản chưa xác minh: 6 videos/ngày',
                        '• Quota API: ~6 videos/ngày (10,000 units)',
                        '• Cần chờ 24 giờ để reset quota',
                    ],
                    'solutions' => [
                        '1. Xác minh kênh YouTube (khuyến nghị): https://www.youtube.com/verify',
                        '2. Chờ 24 giờ để quota được reset',
                        '3. Upload từng video một thay vì hàng loạt',
                        '4. Liên hệ Google để tăng quota: https://support.google.com/youtube/contact/yt_api_form',
                    ]
                ], 429);
            }

            if (str_contains($errorMessage, 'quota')) {
                return response()->json([
                    'success' => false,
                    'error' => '❌ Vượt quá quota API',
                    'details' => 'API quota của YouTube đã hết.',
                    'uploaded_count' => count($uploadedVideos ?? []),
                    'uploaded_videos' => $uploadedVideos ?? [],
                ], 429);
            }

            return response()->json([
                'success' => false,
                'error' => '❌ Lỗi tạo playlist: ' . $errorMessage,
                'details' => $errorMessage,
                'uploaded_count' => count($uploadedVideos ?? []),
                'uploaded_videos' => $uploadedVideos ?? []
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('YouTube playlist creation failed', ['error' => $e->getMessage(), 'audiobook' => $audioBook->id]);
            return response()->json([
                'success' => false,
                'error' => '❌ Lỗi tạo playlist: ' . $e->getMessage(),
                'uploaded_count' => count($uploadedVideos ?? []),
                'uploaded_videos' => $uploadedVideos ?? []
            ], 500);
        }
    }

    /**
     * Queue playlist creation and uploads (async).
     */
    public function createPlaylistAndUploadAsync(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'playlist_name' => 'required|string|max:150',
            'playlist_description' => 'nullable|string',
            'privacy' => 'required|string|in:public,unlisted,private',
            'thumbnail_path' => 'nullable|string',
            'tags' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.video_id' => 'required|string',
            'items.*.video_type' => 'required|string|in:description,chapter,segment',
            'items.*.title' => 'required|string|max:100',
            'items.*.description' => 'nullable|string',
        ]);

        $this->initPublishProgress($audioBook->id, 'queued', 'Da dua vao hang doi, co the tat trinh duyet.');
        PublishYoutubeJob::dispatch($audioBook->id, 'create_playlist', $request->all());

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'Da dua vao hang doi xu ly.'
        ]);
    }

    /**
     * Save publish meta data to DB without uploading.
     */
    public function savePublishMeta(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'title' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'tags' => 'nullable|string',
            'playlist_title' => 'nullable|string|max:150',
            'chapters' => 'nullable|array',
            'chapters.*.id' => 'required|string',
            'chapters.*.title' => 'nullable|string|max:200',
            'chapters.*.description' => 'nullable|string',
        ]);

        // Save audiobook-level meta
        $audioBook->update([
            'youtube_video_title' => $request->input('title', $audioBook->youtube_video_title),
            'youtube_video_description' => $request->input('description', $audioBook->youtube_video_description),
            'youtube_video_tags' => $request->input('tags', $audioBook->youtube_video_tags),
            'youtube_playlist_title' => $request->input('playlist_title', $audioBook->youtube_playlist_title),
        ]);

        // Save per-chapter meta
        $chapters = $request->input('chapters', []);
        foreach ($chapters as $item) {
            $chapterId = str_replace('chapter_', '', $item['id']);
            AudioBookChapter::where('id', $chapterId)
                ->where('audio_book_id', $audioBook->id)
                ->update([
                    'youtube_video_title' => $item['title'] ?? null,
                    'youtube_video_description' => $item['description'] ?? null,
                ]);
        }

        return response()->json(['success' => true, 'message' => 'Đã lưu thông tin phát hành.']);
    }

    /**
     * Upload videos to an existing YouTube playlist.
     */
    public function addToExistingPlaylist(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'playlist_id' => 'required|string',
            'playlist_title' => 'nullable|string|max:150',
            'privacy' => 'required|string|in:public,unlisted,private',
            'thumbnail_path' => 'nullable|string',
            'tags' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.video_id' => 'required|string',
            'items.*.video_type' => 'required|string|in:description,chapter,segment',
            'items.*.title' => 'required|string|max:100',
            'items.*.description' => 'nullable|string',
        ]);

        $channel = $audioBook->youtubeChannel;
        if (!$channel) {
            return response()->json(['success' => false, 'error' => 'Audiobook chưa được gán kênh YouTube.'], 400);
        }

        $accessToken = YouTubeChannelController::getValidAccessToken($channel);
        if (!$accessToken) {
            return response()->json(['success' => false, 'error' => 'Không có access token YouTube. Vui lòng kết nối lại OAuth.'], 401);
        }

        $playlistId = $request->input('playlist_id');
        $privacy = $request->input('privacy', 'private');
        $tags = array_filter(array_map('trim', explode(',', $request->input('tags', ''))));
        $thumbnailPath = $request->input('thumbnail_path');
        $fullThumbPath = $thumbnailPath ? storage_path('app/public/' . $thumbnailPath) : null;

        try {
            $uploadedVideos = [];
            $thumbnailFailed = [];
            $videoIdsForThumbnail = [];

            foreach ($request->input('items') as $item) {
                $videoPath = $this->resolveVideoPath($audioBook, $item['video_id'], $item['video_type']);
                if (!$videoPath || !file_exists($videoPath)) {
                    Log::warning("Skipping video - file not found", ['video_id' => $item['video_id']]);
                    continue;
                }

                $videoId = $this->youtubeUploadVideo(
                    $accessToken,
                    $videoPath,
                    $item['title'],
                    $item['description'] ?? '',
                    $tags,
                    $privacy
                );

                $this->youtubeAddToPlaylist($accessToken, $playlistId, $videoId);

                // Save tracking data to chapter
                if ($item['video_type'] === 'chapter') {
                    $chapterId = str_replace('chapter_', '', $item['video_id']);
                    AudioBookChapter::where('id', $chapterId)
                        ->where('audio_book_id', $audioBook->id)
                        ->update([
                            'youtube_video_id' => $videoId,
                            'youtube_video_title' => $item['title'],
                            'youtube_video_description' => $item['description'] ?? '',
                            'youtube_uploaded_at' => now(),
                        ]);
                }

                // Save tracking data to segment
                if ($item['video_type'] === 'segment') {
                    $segmentId = str_replace('segment_', '', $item['video_id']);
                    AudioBookVideoSegment::where('id', $segmentId)
                        ->where('audio_book_id', $audioBook->id)
                        ->update([
                            'youtube_video_id' => $videoId,
                            'youtube_video_title' => $item['title'],
                            'youtube_video_description' => $item['description'] ?? '',
                            'youtube_uploaded_at' => now(),
                        ]);
                }

                $videoIdsForThumbnail[] = [
                    'videoId' => $videoId,
                    'title' => $item['title'],
                ];

                $uploadedVideos[] = [
                    'title' => $item['title'],
                    'video_id' => $videoId,
                    'url' => "https://www.youtube.com/watch?v={$videoId}",
                ];
            }

            // Set thumbnails
            if ($fullThumbPath && file_exists($fullThumbPath) && !empty($videoIdsForThumbnail)) {
                sleep(5);
                foreach ($videoIdsForThumbnail as $item) {
                    $thumbSuccess = $this->youtubeSetThumbnail($accessToken, $item['videoId'], $fullThumbPath);
                    if (!$thumbSuccess) {
                        $thumbnailFailed[] = $item['title'];
                    }
                }
            }

            // Save playlist info to audiobook
            $audioBook->update([
                'youtube_playlist_id' => $playlistId,
                'youtube_playlist_title' => $request->input('playlist_title', $audioBook->youtube_playlist_title),
            ]);

            $response = [
                'success' => true,
                'playlist_id' => $playlistId,
                'playlist_url' => "https://www.youtube.com/playlist?list={$playlistId}",
                'uploaded_videos' => $uploadedVideos,
            ];

            if (!empty($thumbnailFailed)) {
                $response['thumbnail_warning'] = 'Không thể đặt thumbnail cho ' . count($thumbnailFailed) . ' video. Kênh YouTube cần xác minh số điện thoại.';
            }

            return response()->json($response);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $errorData = json_decode($responseBody, true);
            $errorMessage = $errorData['error']['message'] ?? $e->getMessage();
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 500;

            Log::error('YouTube add to playlist failed', [
                'error' => $errorMessage,
                'status' => $statusCode,
                'audiobook' => $audioBook->id,
                'uploaded_videos' => $uploadedVideos ?? []
            ]);

            if ($statusCode === 401) {
                $newToken = YouTubeChannelController::refreshAccessToken($channel);
                if ($newToken) {
                    return response()->json([
                        'success' => false,
                        'error' => '❌ Token YouTube đã hết hạn, đã tự động refresh. Vui lòng thử lại.',
                        'token_refreshed' => true,
                    ], 401);
                } else {
                    $channel->update(['youtube_connected' => false]);
                    return response()->json([
                        'success' => false,
                        'error' => '❌ Token YouTube không hợp lệ. Vui lòng kết nối lại OAuth trong trang kênh YouTube.',
                        'need_reconnect' => true,
                    ], 401);
                }
            }

            return response()->json([
                'success' => false,
                'error' => '❌ Lỗi upload vào playlist: ' . $errorMessage,
                'uploaded_count' => count($uploadedVideos ?? []),
                'uploaded_videos' => $uploadedVideos ?? []
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('YouTube add to playlist failed', ['error' => $e->getMessage(), 'audiobook' => $audioBook->id]);
            return response()->json([
                'success' => false,
                'error' => '❌ Lỗi: ' . $e->getMessage(),
                'uploaded_count' => count($uploadedVideos ?? []),
                'uploaded_videos' => $uploadedVideos ?? []
            ], 500);
        }
    }

    /**
     * Queue uploads to an existing playlist (async).
     */
    public function addToExistingPlaylistAsync(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'playlist_id' => 'required|string',
            'playlist_title' => 'nullable|string|max:150',
            'privacy' => 'required|string|in:public,unlisted,private',
            'thumbnail_path' => 'nullable|string',
            'tags' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.video_id' => 'required|string',
            'items.*.video_type' => 'required|string|in:description,chapter,segment',
            'items.*.title' => 'required|string|max:100',
            'items.*.description' => 'nullable|string',
        ]);

        $this->initPublishProgress($audioBook->id, 'queued', 'Da dua vao hang doi, co the tat trinh duyet.');
        PublishYoutubeJob::dispatch($audioBook->id, 'add_to_playlist', $request->all());

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'Da dua vao hang doi xu ly.'
        ]);
    }

    private function initPublishProgress(int $audioBookId, string $status, string $message): void
    {
        Cache::put("publish_progress_{$audioBookId}", [
            'status' => $status,
            'percent' => 1,
            'message' => $message,
            'result' => null,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(6));
    }

    /**
     * Get publish progress for background jobs.
     */
    public function getPublishProgress(AudioBook $audioBook)
    {
        $progress = Cache::get("publish_progress_{$audioBook->id}");

        if (!$progress) {
            return response()->json([
                'success' => true,
                'status' => 'idle',
            ]);
        }

        return response()->json(array_merge(['success' => true], $progress));
    }

    /**
     * Get YouTube publishing history for this audiobook.
     */
    public function getPublishHistory(AudioBook $audioBook)
    {
        $history = [];

        // Chapters with YouTube upload
        $chapters = $audioBook->chapters()
            ->whereNotNull('youtube_video_id')
            ->orderBy('youtube_uploaded_at', 'desc')
            ->get();

        foreach ($chapters as $chapter) {
            $history[] = [
                'type' => 'chapter',
                'chapter_number' => $chapter->chapter_number,
                'chapter_title' => $chapter->title,
                'youtube_video_id' => $chapter->youtube_video_id,
                'youtube_video_title' => $chapter->youtube_video_title,
                'youtube_video_url' => "https://www.youtube.com/watch?v={$chapter->youtube_video_id}",
                'uploaded_at' => $chapter->youtube_uploaded_at,
            ];
        }

        return response()->json([
            'success' => true,
            'history' => $history,
            'playlist' => [
                'id' => $audioBook->youtube_playlist_id,
                'title' => $audioBook->youtube_playlist_title,
                'url' => $audioBook->youtube_playlist_id
                    ? "https://www.youtube.com/playlist?list={$audioBook->youtube_playlist_id}"
                    : null,
            ],
            'total_uploaded' => count($history),
        ]);
    }

    // ---- YouTube API Helper Methods ----

    /**
     * Resolve the absolute file path for a video source.
     */
    private function resolveVideoPath(AudioBook $audioBook, string $videoId, string $videoType): ?string
    {
        if ($videoType === 'short') {
            if (str_starts_with($videoId, 'shortai_')) {
                $indexStr = substr($videoId, strlen('shortai_'));
                $targetIndex = (int) $indexStr;
                $items = $this->loadShortVideoItems($audioBook->id);
                foreach ($items as $k => $it) {
                    $idx = (int)($it['index'] ?? ($k + 1));
                    if ($idx !== $targetIndex) continue;
                    $rel = ltrim((string)($it['video_path'] ?? ''), '/');
                    if ($rel === '') return null;
                    $abs = storage_path('app/public/' . $rel);
                    return is_file($abs) ? $abs : null;
                }
                return null;
            }

            if (str_starts_with($videoId, 'shortclip_')) {
                $clipId = substr($videoId, strlen('shortclip_'));
                $clips = $this->loadClips($audioBook->id);
                foreach ($clips as $clip) {
                    if ((string)($clip['id'] ?? '') !== (string)$clipId) continue;
                    $rel = ltrim((string)($clip['composed_path'] ?? ''), '/');
                    if ($rel === '') return null;
                    $abs = storage_path('app/public/' . $rel);
                    return is_file($abs) ? $abs : null;
                }
                return null;
            }
        }

        if ($videoType === 'description') {
            if ($videoId === 'desc_scene' && $audioBook->description_scene_video) {
                return storage_path('app/public/' . $audioBook->description_scene_video);
            }
            if ($videoId === 'desc_lipsync' && $audioBook->description_lipsync_video) {
                return storage_path('app/public/' . $audioBook->description_lipsync_video);
            }
        }

        if ($videoType === 'chapter') {
            $chapterId = str_replace('chapter_', '', $videoId);
            $chapter = AudioBookChapter::where('audio_book_id', $audioBook->id)
                ->where('id', $chapterId)
                ->first();
            if ($chapter && $chapter->video_path) {
                return storage_path('app/public/' . $chapter->video_path);
            }
        }

        if ($videoType === 'segment') {
            $segmentId = str_replace('segment_', '', $videoId);
            $segment = AudioBookVideoSegment::where('audio_book_id', $audioBook->id)
                ->where('id', $segmentId)
                ->first();
            if ($segment && $segment->video_path) {
                return storage_path('app/public/' . $segment->video_path);
            }
        }

        return null;
    }

    private function persistShortPublishMeta(AudioBook $audioBook, string $sourceVideoId, string $youtubeVideoId, string $title, string $description): void
    {
        $uploadedAt = now()->toDateTimeString();

        if (str_starts_with($sourceVideoId, 'shortai_')) {
            $targetIndex = (int) substr($sourceVideoId, strlen('shortai_'));
            if ($targetIndex <= 0) {
                return;
            }

            $items = $this->loadShortVideoItems($audioBook->id);
            $updated = false;

            foreach ($items as $key => $item) {
                $index = (int) ($item['index'] ?? ($key + 1));
                if ($index !== $targetIndex) {
                    continue;
                }

                $items[$key]['youtube_video_id'] = $youtubeVideoId;
                $items[$key]['youtube_video_title'] = $title;
                $items[$key]['youtube_video_description'] = $description;
                $items[$key]['youtube_uploaded_at'] = $uploadedAt;
                $items[$key]['updated_at'] = $uploadedAt;
                $updated = true;
                break;
            }

            if ($updated) {
                $this->saveShortVideoItems($audioBook->id, $items);
            }

            return;
        }

        if (str_starts_with($sourceVideoId, 'shortclip_')) {
            $clipId = substr($sourceVideoId, strlen('shortclip_'));
            if ($clipId === '') {
                return;
            }

            $clips = $this->loadClips($audioBook->id);
            $updated = false;

            foreach ($clips as $key => $clip) {
                if ((string) ($clip['id'] ?? '') !== (string) $clipId) {
                    continue;
                }

                $clips[$key]['youtube_video_id'] = $youtubeVideoId;
                $clips[$key]['youtube_video_title'] = $title;
                $clips[$key]['youtube_video_description'] = $description;
                $clips[$key]['youtube_uploaded_at'] = $uploadedAt;
                $clips[$key]['updated_at'] = $uploadedAt;
                $updated = true;
                break;
            }

            if ($updated) {
                $this->saveClips($audioBook->id, $clips);
            }
        }
    }

    /**
     * Upload a video to YouTube using resumable upload.
     */
    private function youtubeUploadVideo(string $accessToken, string $filePath, string $title, string $description, array $tags, string $privacy): string
    {
        $client = new \GuzzleHttp\Client();
        $fileSize = filesize($filePath);
        $mimeType = mime_content_type($filePath) ?: 'video/mp4';

        // Step 1: Initiate resumable upload
        $metadata = [
            'snippet' => [
                'title' => mb_substr($title, 0, 100),
                'description' => mb_substr($description, 0, 5000),
                'tags' => array_slice($tags, 0, 30),
                'categoryId' => '22', // People & Blogs
                'defaultLanguage' => 'vi',
            ],
            'status' => [
                'privacyStatus' => $privacy,
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        $initResponse = $client->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Length' => $fileSize,
                'X-Upload-Content-Type' => $mimeType,
            ],
            'json' => $metadata,
            'timeout' => 30,
        ]);

        $uploadUrl = $initResponse->getHeader('Location')[0] ?? null;
        if (!$uploadUrl) {
            throw new \Exception('Không nhận được upload URL từ YouTube.');
        }

        // Step 2: Upload the file
        $uploadResponse = $client->put($uploadUrl, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => $mimeType,
                'Content-Length' => $fileSize,
            ],
            'body' => fopen($filePath, 'r'),
            'timeout' => 600, // 10 minutes for large files
        ]);

        $uploadResult = json_decode($uploadResponse->getBody()->getContents(), true);
        $videoId = $uploadResult['id'] ?? null;

        if (!$videoId) {
            throw new \Exception('YouTube không trả về video ID.');
        }

        return $videoId;
    }

    /**
     * Set thumbnail for a YouTube video (with retry).
     * Returns true on success, false on failure.
     */
    private function youtubeSetThumbnail(string $accessToken, string $videoId, string $thumbnailPath, int $maxRetries = 3): bool
    {
        $client = new \GuzzleHttp\Client();
        $mimeType = mime_content_type($thumbnailPath) ?: 'image/jpeg';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // YouTube may need time to process the uploaded video before accepting thumbnail
                if ($attempt > 1) {
                    sleep(5 * $attempt); // Progressive delay: 10s, 15s
                }

                $client->post("https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId={$videoId}", [
                    'headers' => [
                        'Authorization' => "Bearer {$accessToken}",
                        'Content-Type' => $mimeType,
                    ],
                    'body' => fopen($thumbnailPath, 'r'),
                    'timeout' => 30,
                ]);

                Log::info('YouTube thumbnail set successfully', ['videoId' => $videoId, 'attempt' => $attempt]);
                return true;
            } catch (\Exception $e) {
                Log::warning('Failed to set YouTube thumbnail', [
                    'videoId' => $videoId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

                if ($attempt === $maxRetries) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Create a YouTube playlist.
     */
    private function youtubeCreatePlaylist(string $accessToken, string $title, ?string $description, string $privacy): string
    {
        $description = $description ?? '';
        $client = new \GuzzleHttp\Client();

        $response = $client->post('https://www.googleapis.com/youtube/v3/playlists?part=snippet,status', [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'snippet' => [
                    'title' => mb_substr($title, 0, 150),
                    'description' => mb_substr($description, 0, 5000),
                    'defaultLanguage' => 'vi',
                ],
                'status' => [
                    'privacyStatus' => $privacy,
                ],
            ],
            'timeout' => 30,
        ]);

        $result = json_decode($response->getBody()->getContents(), true);
        $playlistId = $result['id'] ?? null;

        if (!$playlistId) {
            throw new \Exception('YouTube không trả về playlist ID.');
        }

        return $playlistId;
    }

    /**
     * Add a video to a YouTube playlist.
     */
    private function youtubeAddToPlaylist(string $accessToken, string $playlistId, string $videoId): void
    {
        $client = new \GuzzleHttp\Client();

        $client->post('https://www.googleapis.com/youtube/v3/playlistItems?part=snippet', [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'snippet' => [
                    'playlistId' => $playlistId,
                    'resourceId' => [
                        'kind' => 'youtube#video',
                        'videoId' => $videoId,
                    ],
                ],
            ],
            'timeout' => 30,
        ]);
    }

    // ====================================================================
    // FULL BOOK VIDEO (merge all chapter TTS + description into one video)
    // ====================================================================

    /**
     * Generate full book video: merge description audio + all chapter full TTS
     * into one combined audio, then create a single video with image + music + wave.
     */
    public function generateFullBookVideo(Request $request, AudioBook $audioBook)
    {
        set_time_limit(0);

        try {
            $request->validate([
                'image_path' => 'required|string',
                'image_type' => 'required|string|in:thumbnails,scenes'
            ]);

            $imageType = $request->input('image_type');
            $imageFilename = $request->input('image_path');
            $imagePath = storage_path('app/public/books/' . $audioBook->id . '/' . $imageType . '/' . $imageFilename);

            if (!file_exists($imagePath)) {
                $this->updateFullBookVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'File anh khong ton tai.'
                ]);
                return response()->json(['success' => false, 'error' => 'File anh khong ton tai: ' . $imageFilename], 404);
            }

            $ffmpeg = env('FFMPEG_PATH', 'ffmpeg');
            $ffprobe = env('FFPROBE_PATH', 'ffprobe');
            $bookDir = storage_path('app/public/books/' . $audioBook->id);
            $mp4Dir = $bookDir . '/mp4';
            $tempDir = storage_path('app/temp/fullbook_' . $audioBook->id . '_' . time());

            if (!is_dir($mp4Dir)) mkdir($mp4Dir, 0755, true);
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

            // ============================================================
            // Step 1: Collect all audio files (description + chapters)
            // ============================================================
            $this->updateFullBookVideoProgress($audioBook->id, [
                'status' => 'processing',
                'percent' => 2,
                'message' => 'Dang kiem tra cac file audio...'
            ]);
            $this->updateFullBookVideoLog($audioBook->id, 'Bat dau kiem tra audio files...');

            $audioFiles = [];

            // Add description audio first (if exists)
            if ($audioBook->description_audio) {
                $descAudioPath = storage_path('app/public/' . $audioBook->description_audio);
                if (file_exists($descAudioPath)) {
                    $audioFiles[] = $descAudioPath;
                    $this->updateFullBookVideoLog($audioBook->id, 'Co audio gioi thieu: ' . basename($descAudioPath));
                }
            }

            // Add chapter full audio files in order
            $chapters = $audioBook->chapters()->orderBy('chapter_number')->get();
            $missingChapters = [];

            foreach ($chapters as $chapter) {
                $chapterNumPadded = str_pad($chapter->chapter_number, 3, '0', STR_PAD_LEFT);
                $fullAudioPath = $bookDir . "/c_{$chapterNumPadded}_full.mp3";

                if (file_exists($fullAudioPath)) {
                    $audioFiles[] = $fullAudioPath;
                } else {
                    $missingChapters[] = $chapter->chapter_number;
                }
            }

            if (!empty($missingChapters)) {
                $missing = implode(', ', $missingChapters);
                $this->updateFullBookVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => "Thieu file TTS full cho chuong: {$missing}"
                ]);
                $this->updateFullBookVideoLog($audioBook->id, "LOI: Thieu file TTS full cho chuong: {$missing}");
                $this->cleanupDirectory($tempDir);
                return response()->json([
                    'success' => false,
                    'error' => "Thieu file TTS full cho chuong: {$missing}. Vui long tao TTS va ghep full cho tat ca chuong truoc."
                ], 400);
            }

            if (count($audioFiles) < 1) {
                $this->updateFullBookVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'Khong co file audio nao.'
                ]);
                $this->cleanupDirectory($tempDir);
                return response()->json(['success' => false, 'error' => 'Khong co file audio nao de ghep.'], 400);
            }

            $this->updateFullBookVideoLog($audioBook->id, 'Tong so file audio: ' . count($audioFiles));

            // ============================================================
            // Step 2: Concatenate all audio files into one
            // ============================================================
            $this->updateFullBookVideoProgress($audioBook->id, [
                'status' => 'processing',
                'percent' => 5,
                'message' => 'Dang ghep tat ca audio thanh 1 file...'
            ]);

            $concatListPath = $tempDir . '/concat_list.txt';
            $concatContent = '';
            foreach ($audioFiles as $af) {
                $concatContent .= "file " . escapeshellarg($af) . "\n";
            }
            file_put_contents($concatListPath, $concatContent);

            $mergedVoicePath = $tempDir . '/merged_voice.mp3';
            $concatCmd = sprintf(
                '%s -y -f concat -safe 0 -i %s -c:a libmp3lame -b:a 192k %s 2>&1',
                $ffmpeg,
                escapeshellarg($concatListPath),
                escapeshellarg($mergedVoicePath)
            );

            $this->updateFullBookVideoLog($audioBook->id, 'FFmpeg: bat dau ghep audio...');
            Log::info("Full book concat command", ['cmd' => $concatCmd]);
            exec($concatCmd, $concatOutput, $concatReturnCode);

            foreach (array_slice($concatOutput, -10) as $line) {
                $this->updateFullBookVideoLog($audioBook->id, trim((string) $line));
            }

            if ($concatReturnCode !== 0 || !file_exists($mergedVoicePath)) {
                $this->updateFullBookVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'FFmpeg khong the ghep audio.'
                ]);
                $this->updateFullBookVideoLog($audioBook->id, 'FFmpeg: ghep audio that bai');
                $this->cleanupDirectory($tempDir);
                return response()->json(['success' => false, 'error' => 'FFmpeg khong the ghep audio.'], 500);
            }

            // Get merged voice duration
            $dCmd = sprintf(
                '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                $ffprobe,
                escapeshellarg($mergedVoicePath)
            );
            $dOut = [];
            exec($dCmd, $dOut);
            $voiceDuration = !empty($dOut) ? (float) $dOut[0] : 0;

            $this->updateFullBookVideoLog($audioBook->id, 'Tong thoi luong voice: ' . round($voiceDuration, 1) . 's');
            $this->updateFullBookVideoProgress($audioBook->id, [
                'status' => 'processing',
                'percent' => 15,
                'message' => 'Da ghep audio (' . gmdate('H:i:s', (int) $voiceDuration) . '). Dang tron nhac nen...'
            ]);

            // ============================================================
            // Step 3: Mix intro music + voice (same logic as description video)
            // ============================================================
            $introMusicPath = $audioBook->intro_music
                ? storage_path('app/public/' . $audioBook->intro_music)
                : null;

            $hasMusic = $introMusicPath && file_exists($introMusicPath);
            $introFadeDuration = $hasMusic ? (float) ($audioBook->intro_fade_duration ?? 3) : 0;
            $outroExtendDuration = $hasMusic ? (float) ($audioBook->outro_extend_duration ?? 5) : 0;
            $outroFadeDuration = $hasMusic ? (float) ($audioBook->outro_fade_duration ?? 10) : 0;
            $totalDuration = $introFadeDuration + $voiceDuration + $outroExtendDuration;

            $mixedAudioPath = $tempDir . '/mixed_audio.mp3';

            if ($hasMusic) {
                $voiceStartTime = $introFadeDuration;
                $voiceEndTime = $voiceStartTime + $voiceDuration;
                $introFadeOutDuration = min(1.5, max(0.2, $introFadeDuration * 0.5));
                $introFadeOutStart = max(0, $voiceStartTime - $introFadeOutDuration);
                $outroDuration = max(0, $outroExtendDuration);
                $outroFadeOutDuration = $outroDuration > 0
                    ? min($outroFadeDuration, max(0.2, $outroDuration))
                    : 0;
                $outroFadeOutStart = $voiceEndTime + max(0, $outroDuration - $outroFadeOutDuration);

                if ($outroDuration > 0) {
                    $musicVolumeExpr = sprintf(
                        'if(lt(t,%s),1,' .
                            'if(lt(t,%s),1-(t-%s)/%s,' .
                            'if(lt(t,%s),0,' .
                            'if(lt(t,%s),1,' .
                            'if(lt(t,%s),1-(t-%s)/%s,0)' .
                            '))))',
                        round($introFadeOutStart, 2),
                        round($voiceStartTime, 2),
                        round($introFadeOutStart, 2),
                        round($introFadeOutDuration, 2),
                        round($voiceEndTime, 2),
                        round($outroFadeOutStart, 2),
                        round($voiceEndTime + $outroDuration, 2),
                        round($outroFadeOutStart, 2),
                        round($outroFadeOutDuration, 2)
                    );
                } else {
                    $musicVolumeExpr = sprintf(
                        'if(lt(t,%s),1,' .
                            'if(lt(t,%s),1-(t-%s)/%s,0))',
                        round($introFadeOutStart, 2),
                        round($voiceStartTime, 2),
                        round($introFadeOutStart, 2),
                        round($introFadeOutDuration, 2)
                    );
                }

                $audioFilterComplex = sprintf(
                    '[0:a]aloop=loop=-1:size=2e+09,atrim=0:%s,' .
                        'volume=eval=frame:volume=\'%s\',aformat=sample_fmts=fltp[music];' .
                        '[1:a]adelay=%d|%d,aformat=sample_fmts=fltp[voice];' .
                        '[music][voice]amix=inputs=2:duration=first:dropout_transition=3[mixout]',
                    round($totalDuration, 2),
                    $musicVolumeExpr,
                    (int) ($voiceStartTime * 1000),
                    (int) ($voiceStartTime * 1000)
                );

                $mixCmd = sprintf(
                    '%s -y -i %s -i %s -filter_complex "%s" -map "[mixout]" -c:a libmp3lame -b:a 192k %s 2>&1',
                    $ffmpeg,
                    escapeshellarg($introMusicPath),
                    escapeshellarg($mergedVoicePath),
                    $audioFilterComplex,
                    escapeshellarg($mixedAudioPath)
                );

                $this->updateFullBookVideoLog($audioBook->id, 'FFmpeg: bat dau tron nhac nen...');
                Log::info("Full book audio mix command", ['cmd' => $mixCmd]);
                exec($mixCmd, $mixOutput, $mixReturnCode);

                foreach (array_slice($mixOutput, -10) as $line) {
                    $this->updateFullBookVideoLog($audioBook->id, trim((string) $line));
                }

                if ($mixReturnCode !== 0 || !file_exists($mixedAudioPath)) {
                    $this->updateFullBookVideoProgress($audioBook->id, [
                        'status' => 'error',
                        'percent' => 0,
                        'message' => 'FFmpeg khong the tron nhac nen.'
                    ]);
                    $this->updateFullBookVideoLog($audioBook->id, 'FFmpeg: tron nhac that bai');
                    $this->cleanupDirectory($tempDir);
                    return response()->json(['success' => false, 'error' => 'FFmpeg khong the tron nhac nen.'], 500);
                }
            } else {
                // No music, just use the merged voice directly
                copy($mergedVoicePath, $mixedAudioPath);
                $totalDuration = $voiceDuration;
            }

            $this->updateFullBookVideoProgress($audioBook->id, [
                'status' => 'processing',
                'percent' => 30,
                'message' => 'Da tron audio. Dang tao video...'
            ]);

            // ============================================================
            // Step 4: Create video (image + mixed audio + wave overlay)
            // ============================================================
            $outputPath = $mp4Dir . '/full_book.mp4';
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            $waveEnabled = $audioBook->wave_enabled ?? false;
            // Use 720p for chapter videos (faster encoding)
            $videoWidth = 1280;
            $videoHeight = 720;
            $baseFilter = "scale={$videoWidth}:{$videoHeight}:force_original_aspect_ratio=decrease,pad={$videoWidth}:{$videoHeight}:(ow-iw)/2:(oh-ih)/2";

            if ($waveEnabled) {
                $rawWaveType = $audioBook->wave_type ?? 'cline';
                $waveTypeMap = ['point' => 'point', 'line' => 'line', 'p2p' => 'p2p', 'cline' => 'cline', 'bar' => 'line'];
                $waveType = $waveTypeMap[$rawWaveType] ?? 'cline';
                $wavePosition = $audioBook->wave_position ?? 'bottom';
                $waveHeight = $audioBook->wave_height ?? 100;
                $waveColor = ltrim($audioBook->wave_color ?? '#00ff00', '#');
                $waveOpacity = $audioBook->wave_opacity ?? 0.8;

                switch ($wavePosition) {
                    case 'top':
                        $waveY = 20;
                        break;
                    case 'center':
                        $waveY = ($videoHeight - $waveHeight) / 2;
                        break;
                    case 'bottom':
                    default:
                        $waveY = $videoHeight - $waveHeight - 20;
                        break;
                }

                $filterComplex = sprintf(
                    '[0:v]%s[bg];[1:a]showwaves=s=%dx%d:mode=%s:colors=0x%s@%.1f:rate=15[wave];[bg][wave]overlay=0:%d:format=auto[out]',
                    $baseFilter,
                    $videoWidth,
                    $waveHeight,
                    $waveType,
                    $waveColor,
                    $waveOpacity,
                    $waveY
                );

                $videoCmd = sprintf(
                    '%s -y -loop 1 -framerate 15 -i %s -i %s -filter_complex "%s" -map "[out]" -map 1:a ' .
                        '-c:v libx264 -preset ultrafast -tune stillimage -crf 28 -c:a aac -b:a 128k ' .
                        '-pix_fmt yuv420p -shortest -threads 0 -progress pipe:1 -stats %s',
                    $ffmpeg,
                    escapeshellarg($imagePath),
                    escapeshellarg($mixedAudioPath),
                    $filterComplex,
                    escapeshellarg($outputPath)
                );
            } else {
                $videoCmd = sprintf(
                    '%s -y -loop 1 -framerate 1 -i %s -i %s -c:v libx264 -preset ultrafast -tune stillimage -crf 28 ' .
                        '-c:a aac -b:a 128k -pix_fmt yuv420p -r 15 -shortest -threads 0 -vf "%s" -progress pipe:1 -stats %s',
                    $ffmpeg,
                    escapeshellarg($imagePath),
                    escapeshellarg($mixedAudioPath),
                    $baseFilter,
                    escapeshellarg($outputPath)
                );
            }

            $this->updateFullBookVideoLog($audioBook->id, 'FFmpeg: bat dau tao video full book...');
            Log::info("Full book video command", ['cmd' => $videoCmd]);

            $videoResult = $this->runFullBookFfmpegWithProgress(
                $videoCmd,
                $audioBook->id,
                $totalDuration,
                30,
                95
            );

            $this->cleanupDirectory($tempDir);

            if ($videoResult['return_code'] !== 0 || !file_exists($outputPath)) {
                $this->updateFullBookVideoProgress($audioBook->id, [
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'FFmpeg khong the tao video.'
                ]);
                $this->updateFullBookVideoLog($audioBook->id, 'FFmpeg: tao video that bai');
                Log::error("FFmpeg full book video failed", [
                    'return_code' => $videoResult['return_code'],
                    'output' => implode("\n", array_slice($videoResult['output'], -20))
                ]);
                return response()->json(['success' => false, 'error' => 'FFmpeg khong the tao video.'], 500);
            }

            // Get actual video duration
            $durationCmd = sprintf(
                '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                $ffprobe,
                escapeshellarg($outputPath)
            );
            $durationOutput = [];
            exec($durationCmd, $durationOutput);
            $videoDuration = !empty($durationOutput) ? (float) $durationOutput[0] : $totalDuration;

            $relativePath = 'books/' . $audioBook->id . '/mp4/full_book.mp4';
            $audioBook->update([
                'full_book_video' => $relativePath,
                'full_book_video_duration' => $videoDuration
            ]);

            $videoUrl = asset('storage/' . $relativePath);

            $this->updateFullBookVideoProgress($audioBook->id, [
                'status' => 'completed',
                'percent' => 100,
                'message' => 'Hoan tat!',
                'video_url' => $videoUrl,
                'video_duration' => $videoDuration
            ]);
            $this->updateFullBookVideoLog($audioBook->id, 'Hoan tat! Thoi luong: ' . gmdate('H:i:s', (int) $videoDuration));

            Log::info("Full book video generated", [
                'audiobook_id' => $audioBook->id,
                'path' => $relativePath,
                'duration' => $videoDuration
            ]);

            return response()->json([
                'success' => true,
                'video_url' => $videoUrl,
                'video_duration' => $videoDuration,
                'message' => 'Video full book da duoc tao thanh cong!'
            ]);
        } catch (\Exception $e) {
            $this->updateFullBookVideoProgress($audioBook->id, [
                'status' => 'error',
                'percent' => 0,
                'message' => 'Loi: ' . $e->getMessage()
            ]);
            $this->updateFullBookVideoLog($audioBook->id, 'Loi: ' . $e->getMessage());
            Log::error("Generate full book video failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Start full book video generation in background.
     */
    public function startFullBookVideoJob(Request $request, AudioBook $audioBook)
    {
        $this->resetFullBookVideoProgress($audioBook->id);

        $request->validate([
            'image_path' => 'required|string',
            'image_type' => 'required|string|in:thumbnails,scenes'
        ]);

        try {
            GenerateFullBookVideoJob::dispatch(
                $audioBook->id,
                $request->input('image_path'),
                $request->input('image_type')
            );

            return response()->json([
                'success' => true,
                'message' => 'Da nhan yeu cau tao video full book. Dang xu ly...'
            ]);
        } catch (\Throwable $e) {
            $this->updateFullBookVideoProgress($audioBook->id, [
                'status' => 'error',
                'percent' => 0,
                'message' => 'Khong the khoi tao job.'
            ]);
            $this->updateFullBookVideoLog($audioBook->id, 'Loi khoi tao job: ' . $e->getMessage());
            Log::error('Start full book video job failed', [
                'audiobook_id' => $audioBook->id,
                'error' => $e->getMessage()
            ]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get full book video generation progress.
     */
    public function getFullBookVideoProgress(AudioBook $audioBook)
    {
        $key = "fullbook_video_progress_{$audioBook->id}";
        $progress = Cache::get($key);
        $logKey = "fullbook_video_log_{$audioBook->id}";
        $logs = Cache::get($logKey, []);

        if (!$progress) {
            return response()->json([
                'success' => true,
                'status' => 'idle',
                'percent' => 0,
                'message' => '',
                'completed' => false,
                'logs' => $logs,
                'video_url' => null,
                'video_duration' => null
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => $progress['status'] ?? 'processing',
            'percent' => $progress['percent'] ?? 0,
            'message' => $progress['message'] ?? '',
            'completed' => ($progress['status'] ?? '') === 'completed',
            'logs' => $logs,
            'video_url' => $progress['video_url'] ?? null,
            'video_duration' => $progress['video_duration'] ?? null
        ]);
    }

    /**
     * Delete full book video file.
     */
    public function deleteFullBookVideo(AudioBook $audioBook)
    {
        try {
            if ($audioBook->full_book_video) {
                $filePath = storage_path('app/public/' . $audioBook->full_book_video);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                $audioBook->update([
                    'full_book_video' => null,
                    'full_book_video_duration' => null
                ]);
                Log::info("Deleted full book video for audiobook {$audioBook->id}");
            }

            return response()->json(['success' => true, 'message' => 'Da xoa video full book.']);
        } catch (\Exception $e) {
            Log::error("Delete full book video failed for audiobook {$audioBook->id}: " . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // -- Full book video progress helpers --

    private function updateFullBookVideoProgress(int $audioBookId, array $data): void
    {
        $payload = array_merge([
            'status' => 'processing',
            'percent' => 0,
            'message' => '',
            'updated_at' => now()->toIso8601String()
        ], $data);
        Cache::put("fullbook_video_progress_{$audioBookId}", $payload, now()->addHours(3));
    }

    private function resetFullBookVideoProgress(int $audioBookId): void
    {
        Cache::forget("fullbook_video_progress_{$audioBookId}");
        Cache::forget("fullbook_video_log_{$audioBookId}");
        $this->updateFullBookVideoProgress($audioBookId, [
            'status' => 'processing',
            'percent' => 1,
            'message' => 'Dang khoi tao...'
        ]);
    }

    private function updateFullBookVideoLog(int $audioBookId, string $line): void
    {
        $key = "fullbook_video_log_{$audioBookId}";
        $logs = Cache::get($key, []);
        $timestamp = now()->format('H:i:s');
        $logs[] = "[{$timestamp}] {$line}";
        if (count($logs) > 300) {
            $logs = array_slice($logs, -300);
        }
        Cache::put($key, $logs, now()->addHours(3));
    }

    private function runFullBookFfmpegWithProgress(
        string $command,
        int $audioBookId,
        float $totalDuration,
        int $baseStart,
        int $baseEnd
    ): array {
        $output = [];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            $this->updateFullBookVideoLog($audioBookId, 'FFmpeg: khong the khoi tao process');
            return ['return_code' => 1, 'output' => []];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $lastPercent = 0;
        $lastLogAt = time();
        $buffer = '';

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 1);

            foreach ($read as $stream) {
                $data = stream_get_contents($stream);
                if ($data === false || $data === '') continue;
                $buffer .= $data;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $output[] = $line;

                    if (str_starts_with($line, 'out_time_ms=')) {
                        $value = (int) str_replace('out_time_ms=', '', $line);
                        if ($totalDuration > 0) {
                            $percent = $baseStart + (int) round(($value / ($totalDuration * 1000000)) * ($baseEnd - $baseStart));
                            $percent = min($baseEnd, max($baseStart, $percent));
                            if ($percent !== $lastPercent) {
                                $lastPercent = $percent;
                                $this->updateFullBookVideoProgress($audioBookId, [
                                    'status' => 'processing',
                                    'percent' => $percent,
                                    'message' => 'Dang tao video...'
                                ]);
                            }
                        }
                    }

                    if (str_starts_with($line, 'frame=') || str_starts_with($line, 'fps=') || str_starts_with($line, 'speed=')) {
                        if (time() - $lastLogAt >= 3) {
                            $this->updateFullBookVideoLog($audioBookId, "FFmpeg: {$line}");
                            $lastLogAt = time();
                        }
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) break;
        }

        $remaining = trim($buffer);
        if ($remaining !== '') $output[] = $remaining;

        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            $leftover = stream_get_contents($pipe);
            if ($leftover) {
                foreach (explode("\n", $leftover) as $line) {
                    $line = trim($line);
                    if ($line !== '') $output[] = $line;
                }
            }
            fclose($pipe);
        }

        $returnCode = proc_close($process);
        return ['return_code' => $returnCode, 'output' => $output];
    }

    // ====================================================================
    // VIDEO SEGMENTS (batch: gom chương tùy chọn → nhiều video)
    // ====================================================================

    public function getVideoSegments(AudioBook $audioBook)
    {
        $segments = $audioBook->videoSegments()->orderBy('sort_order')->get();
        return response()->json([
            'success' => true,
            'segments' => $segments->map(function ($seg) {
                $data = $seg->toArray();
                if ($seg->video_path) {
                    $data['video_url'] = asset('storage/' . $seg->video_path);
                }
                return $data;
            })
        ]);
    }

    public function saveVideoSegments(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'segments' => 'required|array',
            'segments.*.name' => 'required|string|max:255',
            'segments.*.chapters' => 'required|array|min:1',
            'segments.*.chapters.*' => 'integer|min:0',
            'segments.*.image_path' => 'nullable|string',
            'segments.*.image_type' => 'nullable|string|in:thumbnails,scenes,chapter_covers',
            'segments.*.sort_order' => 'integer|min:0',
        ]);

        $incoming = collect($request->input('segments'));
        $incomingIds = $incoming->pluck('id')->filter()->toArray();

        // Delete segments not in the incoming list (that are not completed with video)
        $audioBook->videoSegments()
            ->whereNotIn('id', $incomingIds)
            ->each(function ($seg) {
                if ($seg->video_path) {
                    $filePath = storage_path('app/public/' . $seg->video_path);
                    if (file_exists($filePath)) unlink($filePath);
                }
                $seg->delete();
            });

        $savedSegments = [];
        foreach ($incoming as $idx => $segData) {
            $attrs = [
                'name' => $segData['name'],
                'chapters' => $segData['chapters'],
                'image_path' => $segData['image_path'] ?? null,
                'image_type' => $segData['image_type'] ?? null,
                'sort_order' => $segData['sort_order'] ?? $idx,
            ];

            if (!empty($segData['id'])) {
                $segment = AudioBookVideoSegment::where('id', $segData['id'])
                    ->where('audio_book_id', $audioBook->id)
                    ->first();
                if ($segment) {
                    $segment->update($attrs);
                    $savedSegments[] = $segment;
                    continue;
                }
            }

            $savedSegments[] = $audioBook->videoSegments()->create($attrs);
        }

        return response()->json([
            'success' => true,
            'message' => 'Da luu ' . count($savedSegments) . ' segments.',
            'segments' => collect($savedSegments)->map(function ($seg) {
                $data = $seg->toArray();
                if ($seg->video_path) $data['video_url'] = asset('storage/' . $seg->video_path);
                return $data;
            })
        ]);
    }

    public function startBatchVideoGeneration(Request $request, AudioBook $audioBook)
    {
        $segmentIds = $request->input('segment_ids', []);

        // If specific IDs provided, use those; otherwise fall back to all pending/error
        if (!empty($segmentIds)) {
            $targetSegments = $audioBook->videoSegments()
                ->whereIn('id', $segmentIds)
                ->get();

            if ($targetSegments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Khong tim thay segment nao.'
                ], 400);
            }

            // Reset selected segments to pending (even completed ones - allow re-generate)
            $audioBook->videoSegments()
                ->whereIn('id', $segmentIds)
                ->update(['status' => 'pending', 'error_message' => null]);

            $count = $targetSegments->count();
        } else {
            $count = $audioBook->videoSegments()
                ->whereIn('status', ['pending', 'error'])
                ->count();

            if ($count === 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'Khong co segment nao can xu ly. Tat ca da completed.'
                ], 400);
            }

            // Reset error segments to pending
            $audioBook->videoSegments()
                ->where('status', 'error')
                ->update(['status' => 'pending', 'error_message' => null]);
        }

        // Reset cache
        Cache::forget("batch_video_progress_{$audioBook->id}");
        Cache::forget("batch_video_log_{$audioBook->id}");

        Cache::put("batch_video_progress_{$audioBook->id}", [
            'status' => 'processing',
            'percent' => 1,
            'message' => 'Dang khoi tao batch...',
            'current_segment_id' => null,
            'current_segment_index' => 0,
            'total_segments' => $count,
            'updated_at' => now()->toIso8601String()
        ], now()->addHours(5));

        GenerateBatchVideoJob::dispatch($audioBook->id, $segmentIds);

        return response()->json([
            'success' => true,
            'message' => "Da bat dau xu ly {$count} segments."
        ]);
    }

    public function getBatchVideoProgress(AudioBook $audioBook)
    {
        $progress = Cache::get("batch_video_progress_{$audioBook->id}");
        $logs = Cache::get("batch_video_log_{$audioBook->id}", []);

        // Always return fresh segment data from DB
        $segments = $audioBook->videoSegments()->orderBy('sort_order')->get()->map(function ($seg) {
            $data = $seg->toArray();
            if ($seg->video_path) $data['video_url'] = asset('storage/' . $seg->video_path);
            return $data;
        });

        return response()->json([
            'success' => true,
            'status' => $progress['status'] ?? 'idle',
            'percent' => $progress['percent'] ?? 0,
            'message' => $progress['message'] ?? '',
            'current_segment_id' => $progress['current_segment_id'] ?? null,
            'current_segment_index' => $progress['current_segment_index'] ?? 0,
            'total_segments' => $progress['total_segments'] ?? 0,
            'completed' => ($progress['status'] ?? '') === 'completed',
            'logs' => $logs,
            'segments' => $segments,
        ]);
    }

    public function deleteVideoSegment(AudioBook $audioBook, $segmentId)
    {
        $segment = AudioBookVideoSegment::where('id', $segmentId)
            ->where('audio_book_id', $audioBook->id)
            ->first();

        if (!$segment) {
            return response()->json(['success' => false, 'error' => 'Segment khong ton tai.'], 404);
        }

        if ($segment->video_path) {
            $filePath = storage_path('app/public/' . $segment->video_path);
            if (file_exists($filePath)) unlink($filePath);
        }

        $segment->delete();

        return response()->json(['success' => true, 'message' => 'Da xoa segment.']);
    }

    // =========================================================
    // CLIPPING FEATURE
    // =========================================================

    private function getClippingDir(int $bookId): string
    {
        $dir = storage_path('app/public/books/' . $bookId . '/clipping');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $clipsDir = $dir . '/clips';
        if (!is_dir($clipsDir)) mkdir($clipsDir, 0755, true);
        return $dir;
    }

    private function loadClips(int $bookId): array
    {
        $path = $this->getClippingDir($bookId) . '/clips.json';
        if (!file_exists($path)) return [];
        return json_decode(file_get_contents($path), true) ?: [];
    }

    private function saveClips(int $bookId, array $clips): void
    {
        $path = $this->getClippingDir($bookId) . '/clips.json';
        file_put_contents($path, json_encode($clips, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function findClip(array $clips, string $clipId): ?array
    {
        foreach ($clips as $clip) {
            if (($clip['id'] ?? '') === $clipId) return $clip;
        }
        return null;
    }

    private function updateClipInList(array &$clips, string $clipId, array $data): void
    {
        if (array_key_exists('cta', $data) && is_string($data['cta']) && trim($data['cta']) !== '') {
            $data['cta'] = $this->ensureCtaHasMoiBan((string)$data['cta']);
        }

        if (array_key_exists('cta_narration', $data) && is_string($data['cta_narration']) && trim($data['cta_narration']) !== '') {
            $data['cta_narration'] = $this->ensureCtaHasMoiBan((string)$data['cta_narration']);
        }

        foreach ($clips as &$clip) {
            if (($clip['id'] ?? '') === $clipId) {
                $clip = array_merge($clip, $data, ['updated_at' => now()->toDateTimeString()]);
                return;
            }
        }
    }

    private function getVideoDuration(string $filePath): float
    {
        $ffprobePath = config('services.ffmpeg.ffprobe_path', 'ffprobe');
        $cmd = sprintf(
            '%s -v quiet -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($ffprobePath),
            escapeshellarg($filePath)
        );
        $out = [];
        exec($cmd, $out);
        return (float) trim(implode('', $out) ?: '0');
    }

    private function getClipNumberFromId(string $clipId): int
    {
        if (preg_match('/clip_(\d+)_/i', $clipId, $matches)) {
            return max(1, (int)($matches[1] ?? 1));
        }

        return 1;
    }

    private function ensureCtaHasMoiBan(string $text, string $bookTitle = '', string $channelName = ''): string
    {
        $bookTitle = trim($bookTitle) !== '' ? trim($bookTitle) : 'cuốn sách này';
        $channelName = trim($channelName);
        $channelMention = $channelName !== '' ? 'kênh ' . $channelName : 'kênh Sumo Phiêu Lưu Ký';

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return "Mời bạn nghe trọn bộ {$bookTitle} trên {$channelMention} nhé.";
        }

        if (mb_stripos($normalized, 'mời bạn') !== false) {
            return $normalized;
        }

        $normalized = (string) preg_replace('/^Bạn\s+/ui', '', $normalized);
        $normalized = trim($normalized);
        if ($normalized === '') {
            return "Mời bạn nghe trọn bộ {$bookTitle} trên {$channelMention} nhé.";
        }

        $firstChar = mb_substr($normalized, 0, 1);
        $rest = mb_substr($normalized, 1);
        if ($firstChar !== '' && preg_match('/^[A-ZÀ-Ỹ]$/u', $firstChar)) {
            $normalized = mb_strtolower($firstChar) . $rest;
        }

        return 'Mời bạn ' . $normalized;
    }

    private function buildClipCtaNarration(AudioBook $audioBook, array $clip, int $clipNumber): string
    {
        $bookTitle = trim((string)($audioBook->title ?? ''));
        if ($bookTitle === '') {
            $bookTitle = 'cuốn sách này';
        }

        $channelName = trim((string) optional($audioBook->youtubeChannel)->title);
        if ($channelName === '') {
            $channelName = 'Sumo Phiêu Lưu Ký';
        }

        $part = max(1, $clipNumber);
        $hookTitle = trim((string)($clip['hook_title'] ?? ''));

        // Try AI rewrite for a creative, engaging CTA
        try {
            $apiKey = config('services.gemini.api_key');
            if ($apiKey) {
                $prompt = "Bạn là một content creator chuyên nghiệp. Hãy viết một đoạn CTA (call-to-action) ngắn gọn, hấp dẫn cho video trích đoạn audiobook.\n"
                    . "Thông tin:\n"
                    . "- Tên sách: {$bookTitle}\n"
                    . "- Phần: {$part}\n"
                    . ($hookTitle !== '' ? "- Tiêu đề hook: {$hookTitle}\n" : '')
                    . "- Kênh: {$channelName}\n\n"
                    . "Yêu cầu:\n"
                    . "- Viết 1-2 câu ngắn gọn (tối đa 40 từ)\n"
                    . "- Giọng điệu cuốn hút, kích thích tò mò\n"
                    . "- Kêu gọi người nghe theo dõi kênh hoặc nghe tiếp\n"
                    . "- Đề cập tên sách một cách tự nhiên\n"
                    . "- Nên có cụm 'Mời bạn' để CTA thân thiện hơn\n"
                    . "- Không dùng emoji, hashtag hay ký hiệu đặc biệt\n"
                    . "- Chỉ trả về nội dung CTA, không giải thích gì thêm\n\n"
                    . "Ví dụ tốt:\n"
                    . "- Câu chuyện còn kịch tính hơn thế nữa! Mời bạn nghe trọn bộ Tên Sách ngay trên kênh Sumo Phiêu Lưu Ký nhé!\n"
                    . "- Muốn biết kết cục ra sao? Mời bạn theo dõi kênh Sumo Phiêu Lưu Ký để nghe đầy đủ Tên Sách!";

                $client = new \GuzzleHttp\Client();
                $response = $client->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}",
                    [
                        'headers' => ['Content-Type' => 'application/json'],
                        'json' => [
                            'contents' => [['parts' => [['text' => $prompt]]]],
                            'generationConfig' => [
                                'temperature' => 0.85,
                                'maxOutputTokens' => 200,
                            ],
                        ],
                        'timeout' => 15,
                    ]
                );

                $result = json_decode($response->getBody()->getContents(), true);
                $aiText = trim((string)($result['candidates'][0]['content']['parts'][0]['text'] ?? ''));

                // Clean up AI output: remove surrounding quotes, asterisks, etc.
                $aiText = preg_replace('/^["\'\'\*]+|["\'\'\*]+$/u', '', $aiText);
                $aiText = trim($aiText);

                if (mb_strlen($aiText) >= 15 && mb_strlen($aiText) <= 200) {
                    $aiText = $this->ensureCtaHasMoiBan($aiText, $bookTitle, $channelName);
                    Log::info('Clipping CTA AI rewrite success', ['clip' => $clip['id'] ?? '', 'cta' => $aiText]);
                    return $aiText;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Clipping CTA AI rewrite failed, using template', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to template
        return $this->ensureCtaHasMoiBan(
            "Bạn vừa nghe trích đoạn audiobook phần {$part} của {$bookTitle}. Mời bạn vào kênh {$channelName} để nghe trọn bộ nhé.",
            $bookTitle,
            $channelName
        );
    }

    private function createClipCtaAudio(AudioBook $audioBook, string $clipId, string $ctaText): array
    {
        $provider = strtolower((string)($audioBook->tts_provider ?: 'microsoft'));
        $voiceGender = (string)($audioBook->tts_voice_gender ?: 'female');
        $voiceName = $audioBook->tts_voice_name ?: null;
        $styleInstruction = $audioBook->tts_style_instruction ?: null;
        $speed = (float)($audioBook->tts_speed ?? 1.0);

        $generatedAudioStoragePath = $this->ttsService->generateAudio(
            $ctaText,
            (int) random_int(10000, 99999),
            $voiceGender,
            $voiceName,
            $provider,
            $styleInstruction,
            null,
            $speed
        );

        $generatedAudioAbsPath = Storage::path($generatedAudioStoragePath);
        if (!file_exists($generatedAudioAbsPath)) {
            throw new \RuntimeException('Không thể tạo audio CTA từ TTS.');
        }

        $ctaDir = $this->getClippingDir($audioBook->id) . '/cta';
        if (!is_dir($ctaDir)) {
            mkdir($ctaDir, 0755, true);
        }

        $filename = $clipId . '_cta_' . time() . '.mp3';
        $targetAbsPath = $ctaDir . '/' . $filename;
        $targetRelPath = 'books/' . $audioBook->id . '/clipping/cta/' . $filename;

        if (!@copy($generatedAudioAbsPath, $targetAbsPath)) {
            throw new \RuntimeException('Không thể lưu audio CTA vào thư mục clipping.');
        }

        $duration = (float)($this->getAudioDuration($targetAbsPath) ?? 0.0);
        if ($duration <= 0) {
            $duration = 4.0;
        }

        return [
            'absolute_path' => $targetAbsPath,
            'relative_path' => $targetRelPath,
            'duration' => $duration,
        ];
    }

    private function getRelativePublicPathFromAbsolute(string $absolutePath): ?string
    {
        $publicBase = rtrim(storage_path('app/public'), '/');
        if (!str_starts_with($absolutePath, $publicBase . '/')) {
            return null;
        }

        return ltrim(substr($absolutePath, strlen($publicBase)), '/');
    }

    private function getVideoDimensions(string $filePath): ?array
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $ffprobePath = config('services.ffmpeg.ffprobe_path', 'ffprobe');
        $cmd = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0:s=x %s 2>/dev/null',
            escapeshellarg($ffprobePath),
            escapeshellarg($filePath)
        );

        $output = [];
        exec($cmd, $output, $code);

        if ($code !== 0 || empty($output[0])) {
            return null;
        }

        $parts = explode('x', trim((string)$output[0]));
        if (count($parts) !== 2) {
            return null;
        }

        $width = (int)($parts[0] ?? 0);
        $height = (int)($parts[1] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    private function collectClippingAnimationOptions(int $bookId): array
    {
        $candidateDirs = [
            [
                'path' => storage_path('app/public/books/' . $bookId . '/media/animations'),
                'priority' => 0,
                'source' => 'media_library',
            ],
            [
                'path' => storage_path('app/public/books/' . $bookId . '/animations'),
                'priority' => 1,
                'source' => 'animations',
            ],
        ];

        $files = [];
        foreach ($candidateDirs as $dirMeta) {
            $dir = $dirMeta['path'] ?? null;
            $priority = (int)($dirMeta['priority'] ?? 99);
            $source = (string)($dirMeta['source'] ?? 'unknown');
            if (!$dir) {
                continue;
            }

            if (!is_dir($dir)) {
                continue;
            }

            $paths = glob($dir . '/*.{mp4,webm,mov,mkv}', GLOB_BRACE) ?: [];
            foreach ($paths as $path) {
                if (is_file($path) && is_readable($path)) {
                    $files[] = [
                        'path' => $path,
                        'source_priority' => $priority,
                        'source' => $source,
                    ];
                }
            }
        }

        if (empty($files)) {
            return [];
        }

        $targetRatio = 9 / 16;
        $scored = [];

        foreach ($files as $file) {
            $path = (string)($file['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $dimensions = $this->getVideoDimensions($path);
            if (!$dimensions) {
                continue;
            }

            $width = (int)$dimensions['width'];
            $height = (int)$dimensions['height'];
            if ($height <= 0 || $width <= 0 || $height <= $width) {
                continue;
            }

            $ratio = $width / $height;
            $score = abs($ratio - $targetRatio);
            $relativePath = $this->getRelativePublicPathFromAbsolute($path);
            if (!$relativePath) {
                continue;
            }

            $scored[] = [
                'absolute_path' => $path,
                'relative_path' => $relativePath,
                'width' => $width,
                'height' => $height,
                'score' => $score,
                'source' => (string)($file['source'] ?? 'unknown'),
                'source_priority' => (int)($file['source_priority'] ?? 99),
                'created_at' => @filemtime($path) ?: 0,
            ];
        }

        if (empty($scored)) {
            return [];
        }

        usort($scored, function ($a, $b) {
            if (abs($a['score'] - $b['score']) > 0.0001) {
                return $a['score'] <=> $b['score'];
            }

            if ((int)($a['source_priority'] ?? 99) !== (int)($b['source_priority'] ?? 99)) {
                return (int)($a['source_priority'] ?? 99) <=> (int)($b['source_priority'] ?? 99);
            }

            return $b['created_at'] <=> $a['created_at'];
        });

        return $scored;
    }

    private function resolveClippingAnimationByPath(AudioBook $audioBook, ?string $relativePath): ?array
    {
        $relativePath = ltrim((string)$relativePath, '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm', 'mov', 'mkv'], true)) {
            return null;
        }

        $mediaLibraryPrefix = 'books/' . $audioBook->id . '/media/animations/';
        $legacyAnimationsPrefix = 'books/' . $audioBook->id . '/animations/';

        $isAllowed = str_starts_with($relativePath, $mediaLibraryPrefix)
            || str_starts_with($relativePath, $legacyAnimationsPrefix);

        if (!$isAllowed) {
            return null;
        }

        $absPath = storage_path('app/public/' . $relativePath);
        $realPath = realpath($absPath);
        $publicBase = realpath(storage_path('app/public'));

        if (!$realPath || !$publicBase || !str_starts_with($realPath, $publicBase . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (!is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        $dimensions = $this->getVideoDimensions($realPath);
        if (!$dimensions) {
            return null;
        }

        $width = (int)($dimensions['width'] ?? 0);
        $height = (int)($dimensions['height'] ?? 0);
        if ($width <= 0 || $height <= 0 || $height <= $width) {
            return null;
        }

        $targetRatio = 9 / 16;
        $ratio = $width / $height;
        $score = abs($ratio - $targetRatio);
        $source = str_starts_with($relativePath, $mediaLibraryPrefix) ? 'media_library' : 'animations';

        return [
            'absolute_path' => $realPath,
            'relative_path' => $relativePath,
            'width' => $width,
            'height' => $height,
            'score' => $score,
            'source' => $source,
            'source_priority' => $source === 'media_library' ? 0 : 1,
            'created_at' => @filemtime($realPath) ?: 0,
        ];
    }

    private function findBestClippingAnimation(int $bookId): ?array
    {
        $options = $this->collectClippingAnimationOptions($bookId);
        return $options[0] ?? null;
    }

    private function collectClippingBackgroundAudioOptions(AudioBook $audioBook): array
    {
        $options = [];
        $seen = [];

        $pushOption = function (string $relativePath, string $type) use (&$options, &$seen, $audioBook) {
            $relativePath = ltrim($relativePath, '/');
            if ($relativePath === '' || isset($seen[$relativePath])) {
                return;
            }

            $resolved = $this->resolveClippingBackgroundAudioByPath($audioBook, $relativePath);
            if (!$resolved) {
                return;
            }

            $seen[$relativePath] = true;
            $options[] = [
                'path' => $relativePath,
                'label' => basename($relativePath),
                'type' => $type,
            ];
        };

        $introMusic = ltrim((string)($audioBook->intro_music ?? ''), '/');
        if ($introMusic !== '') {
            $pushOption($introMusic, 'intro');
        }

        $outroMusic = ltrim((string)($audioBook->outro_music ?? ''), '/');
        if ($outroMusic !== '') {
            $pushOption($outroMusic, 'outro');
        }

        $musicDir = storage_path('app/public/books/' . $audioBook->id . '/music');
        if (!is_dir($musicDir)) {
            return $options;
        }

        $preferred = glob($musicDir . '/intro*.{mp3,wav,m4a,aac,ogg}', GLOB_BRACE) ?: [];
        $fallback = glob($musicDir . '/*.{mp3,wav,m4a,aac,ogg}', GLOB_BRACE) ?: [];
        $all = array_values(array_unique(array_merge($preferred, $fallback)));

        foreach ($all as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $relativePath = $this->getRelativePublicPathFromAbsolute($path);
            if (!$relativePath) {
                continue;
            }

            $pushOption($relativePath, 'library');
        }

        return $options;
    }

    private function resolveClippingBackgroundAudioByPath(AudioBook $audioBook, ?string $relativePath): ?array
    {
        $relativePath = ltrim((string)$relativePath, '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3', 'wav', 'm4a', 'aac', 'ogg'], true)) {
            return null;
        }

        $bookMusicPrefix = 'books/' . $audioBook->id . '/music/';
        $introMusic = ltrim((string)($audioBook->intro_music ?? ''), '/');
        $outroMusic = ltrim((string)($audioBook->outro_music ?? ''), '/');

        $isAllowed = str_starts_with($relativePath, $bookMusicPrefix)
            || ($introMusic !== '' && $relativePath === $introMusic)
            || ($outroMusic !== '' && $relativePath === $outroMusic);

        if (!$isAllowed) {
            return null;
        }

        $absPath = storage_path('app/public/' . $relativePath);
        $realPath = realpath($absPath);
        $publicBase = realpath(storage_path('app/public'));

        if (!$realPath || !$publicBase || !str_starts_with($realPath, $publicBase . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (!is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        return [
            'absolute_path' => $realPath,
            'relative_path' => $relativePath,
        ];
    }

    private function resolveClippingBackgroundAudio(AudioBook $audioBook): ?array
    {
        $options = $this->collectClippingBackgroundAudioOptions($audioBook);
        foreach ($options as $option) {
            $resolved = $this->resolveClippingBackgroundAudioByPath($audioBook, $option['path'] ?? null);
            if ($resolved) {
                return $resolved;
            }
        }

        return null;
    }

    private function formatAssTime(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $wholeSeconds = (int) floor($seconds % 60);
        $centiseconds = (int) floor(($seconds - floor($seconds)) * 100);

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $wholeSeconds, $centiseconds);
    }

    private function formatAssText(string $text, int $maxChars = 30): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : ($current . ' ' . $word);
            if (mb_strlen($candidate) > $maxChars && $current !== '') {
                $lines[] = $current;
                $current = $word;
                continue;
            }
            $current = $candidate;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        $escaped = array_map(function ($line) {
            $line = str_replace('\\', '\\\\', $line);
            $line = str_replace('{', '\\{', $line);
            $line = str_replace('}', '\\}', $line);
            return $line;
        }, $lines);

        return implode('\\N', $escaped);
    }

    /**
     * Transcribe audio using OpenAI Whisper API.
     * Returns array of segments: [{start, end, text}, ...]
     */
    private function transcribeClipAudio(string $audioFilePath, float $maxDuration = 0): array
    {
        $apiKey = config('services.openai.api_key');
        if (!$apiKey) {
            Log::warning('Clipping transcription: OpenAI API key not configured');
            return [];
        }

        if (!file_exists($audioFilePath) || !is_readable($audioFilePath)) {
            return [];
        }

        // Extract audio from video to a small mp3 for Whisper
        $ffmpegPath = config('services.ffmpeg.path', env('FFMPEG_PATH', 'ffmpeg'));
        $tmpAudio = sys_get_temp_dir() . '/clip_whisper_' . md5($audioFilePath) . '_' . time() . '.mp3';

        $extractCmd = sprintf(
            '%s -y -i %s -vn -ar 16000 -ac 1 -b:a 64k %s %s 2>/dev/null',
            escapeshellarg($ffmpegPath),
            escapeshellarg($audioFilePath),
            $maxDuration > 0 ? sprintf('-t %.3f', $maxDuration) : '',
            escapeshellarg($tmpAudio)
        );
        exec($extractCmd, $_, $extractCode);

        if ($extractCode !== 0 || !file_exists($tmpAudio) || filesize($tmpAudio) < 100) {
            @unlink($tmpAudio);
            return [];
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post('https://api.openai.com/v1/audio/transcriptions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
                'multipart' => [
                    ['name' => 'file', 'contents' => fopen($tmpAudio, 'r'), 'filename' => 'clip.mp3'],
                    ['name' => 'model', 'contents' => 'whisper-1'],
                    ['name' => 'language', 'contents' => 'vi'],
                    ['name' => 'response_format', 'contents' => 'verbose_json'],
                    ['name' => 'timestamp_granularities[]', 'contents' => 'segment'],
                ],
                'timeout' => 120,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $segments = $result['segments'] ?? [];

            Log::info('Clipping Whisper transcription done', [
                'file' => basename($audioFilePath),
                'segments' => count($segments),
                'duration' => $result['duration'] ?? null,
            ]);

            // Map to simple format
            $mapped = [];
            foreach ($segments as $seg) {
                $text = trim((string)($seg['text'] ?? ''));
                if ($text === '') continue;

                $mapped[] = [
                    'start' => (float)($seg['start'] ?? 0),
                    'end'   => (float)($seg['end'] ?? 0),
                    'text'  => $text,
                ];
            }

            return $mapped;
        } catch (\Throwable $e) {
            Log::warning('Clipping Whisper transcription failed', ['error' => $e->getMessage()]);
            return [];
        } finally {
            @unlink($tmpAudio);
        }
    }

    /**
     * Group Whisper segments into subtitle chunks suitable for display.
     * Each chunk: max ~40 chars, 2-3s duration.
     */
    private function groupTranscriptionForSubtitles(array $segments, int $maxChars = 40): array
    {
        if (empty($segments)) return [];

        $chunks = [];
        $currentText = '';
        $currentStart = null;
        $currentEnd = null;

        foreach ($segments as $seg) {
            $text = trim($seg['text']);
            if ($text === '') continue;

            // Split long segments into sentences/phrases
            $sentences = preg_split('/(?<=[.!?。…,;:])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY);
            if (empty($sentences)) $sentences = [$text];

            $segStart = (float)$seg['start'];
            $segEnd = (float)$seg['end'];
            $segDuration = max(0.1, $segEnd - $segStart);
            $segChars = mb_strlen($text);

            $charSoFar = 0;
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') continue;

                $sentChars = mb_strlen($sentence);
                $sentFraction = $segChars > 0 ? $sentChars / $segChars : 0;
                $sentStart = $segStart + ($charSoFar / max(1, $segChars)) * $segDuration;
                $sentEnd = $sentStart + $sentFraction * $segDuration;
                $charSoFar += $sentChars;

                $candidate = $currentText === '' ? $sentence : ($currentText . ' ' . $sentence);

                if (mb_strlen($candidate) > $maxChars && $currentText !== '') {
                    // Flush current
                    $chunks[] = [
                        'start' => $currentStart,
                        'end'   => $currentEnd,
                        'text'  => $currentText,
                    ];
                    $currentText = $sentence;
                    $currentStart = $sentStart;
                    $currentEnd = $sentEnd;
                } else {
                    if ($currentStart === null) $currentStart = $sentStart;
                    $currentEnd = $sentEnd;
                    $currentText = $candidate;
                }
            }
        }

        // Flush remaining
        if ($currentText !== '' && $currentStart !== null) {
            $chunks[] = [
                'start' => $currentStart,
                'end'   => $currentEnd,
                'text'  => $currentText,
            ];
        }

        return $chunks;
    }

    /**
     * Get ASS subtitle style presets for narration text.
     * Each preset returns: [PrimaryColour, OutlineColour, BackColour, BorderStyle, Outline, Shadow, FontSize, Spacing]
     * ASS Color format: &HAABBGGRR
     */
    private function getSubtitleStylePresets(): array
    {
        return [
            // Default: clean white text, black outline
            'default' => [
                'primary'     => '&H00FFFFFF',
                'secondary'   => '&H00FFFFFF',
                'outline'     => '&H00000000',
                'back'        => '&H80000000',
                'bold'        => 1,
                'border_style'=> 1,
                'outline_w'   => 3,
                'shadow'      => 2,
                'fontsize'    => 48,
                'spacing'     => 0,
                'label'       => 'Mặc định (trắng)',
            ],
            // Highlight Green: bright green text with thick dark outline (TikTok viral style)
            'highlight_green' => [
                'primary'     => '&H0000FF00', // green
                'secondary'   => '&H0000FF00',
                'outline'     => '&H00000000', // black
                'back'        => '&H90000000',
                'bold'        => 1,
                'border_style'=> 1,
                'outline_w'   => 5,
                'shadow'      => 3,
                'fontsize'    => 54,
                'spacing'     => 1,
                'label'       => 'Highlight xanh lá (viral)',
            ],
            // Highlight Yellow: bright yellow text with thick dark outline
            'highlight_yellow' => [
                'primary'     => '&H0000FFFF', // yellow (BGR)
                'secondary'   => '&H0000DDFF',
                'outline'     => '&H00000000',
                'back'        => '&H90000000',
                'bold'        => 1,
                'border_style'=> 1,
                'outline_w'   => 5,
                'shadow'      => 3,
                'fontsize'    => 54,
                'spacing'     => 1,
                'label'       => 'Highlight vàng (viral)',
            ],
            // Highlight Red: red text with thick dark outline
            'highlight_red' => [
                'primary'     => '&H000000FF', // red (BGR)
                'secondary'   => '&H000000FF',
                'outline'     => '&H00000000',
                'back'        => '&H90000000',
                'bold'        => 1,
                'border_style'=> 1,
                'outline_w'   => 5,
                'shadow'      => 3,
                'fontsize'    => 54,
                'spacing'     => 1,
                'label'       => 'Highlight đỏ (viral)',
            ],
            // Boxed: white text with semi-transparent background box
            'boxed' => [
                'primary'     => '&H00FFFFFF',
                'secondary'   => '&H00FFFFFF',
                'outline'     => '&H00000000',
                'back'        => '&H96000000', // semi-transparent black box
                'bold'        => 1,
                'border_style'=> 3, // opaque box behind text
                'outline_w'   => 12,
                'shadow'      => 0,
                'fontsize'    => 48,
                'spacing'     => 1,
                'label'       => 'Nền hộp đen',
            ],
            // Neon Blue: cyan/blue glow effect
            'neon_blue' => [
                'primary'     => '&H00FFFF00', // cyan (BGR)
                'secondary'   => '&H00FFD000',
                'outline'     => '&H00CC3300', // dark blue glow
                'back'        => '&H80000000',
                'bold'        => 1,
                'border_style'=> 1,
                'outline_w'   => 5,
                'shadow'      => 4,
                'fontsize'    => 52,
                'spacing'     => 1,
                'label'       => 'Neon xanh dương',
            ],
        ];
    }

    private function createClippingAssSubtitleFile(
        int $bookId,
        string $clipId,
        string $hookTitle,
        string $ctaNarration,
        float $mainDuration,
        float $ctaDuration,
        array $transcriptionChunks = [],
        string $subtitleStyle = 'highlight_green',
        int $subtitleY = 1280
    ): array {
        $subtitleDir = $this->getClippingDir($bookId) . '/subtitles';
        if (!is_dir($subtitleDir)) {
            mkdir($subtitleDir, 0755, true);
        }

        $filename = $clipId . '_fx_' . time() . '.ass';
        $absPath = $subtitleDir . '/' . $filename;
        $relPath = 'books/' . $bookId . '/clipping/subtitles/' . $filename;

        $mainDuration = max(1.0, $mainDuration);
        $ctaDuration = max(1.5, $ctaDuration);
        $totalDuration = $mainDuration + $ctaDuration;

        $hookEnd = min(max(2.5, $mainDuration - 0.4), 5.8);
        $labelEnd = max(2.0, min($mainDuration - 0.6, 6.2));
        $ctaStart = max(0.1, $mainDuration);

        $hookText = $this->formatAssText($hookTitle, 24);
        if ($hookText === '') {
            $hookText = 'Trích đoạn audiobook';
        }
        $ctaText = $this->formatAssText($ctaNarration, 34);

        // Resolve subtitle style preset
        $presets = $this->getSubtitleStylePresets();
        $style = $presets[$subtitleStyle] ?? $presets['highlight_green'];

        // For karaoke effect: make SecondaryColour semi-transparent based on primary
        $primaryColor = (string)($style['primary'] ?? '&H00FFFFFF');
        $secondaryForKaraoke = preg_replace('/^&H../', '&H80', $primaryColor);
        if (!is_string($secondaryForKaraoke) || strpos($secondaryForKaraoke, '&H') !== 0) {
            $secondaryForKaraoke = '&H80FFFFFF';
        }

        // Center-align narration and place via \pos override (an5), margins not used for middle alignment
        $narrationStyleLine = sprintf(
            "Style: Narration,DejaVu Sans,%d,%s,%s,%s,%s,%d,0,0,0,100,100,%d,0,%d,%d,%d,5,60,60,0,1",
            $style['fontsize'],
            $primaryColor,
            $secondaryForKaraoke,
            $style['outline'],
            $style['back'],
            $style['bold'],
            $style['spacing'],
            $style['border_style'],
            $style['outline_w'],
            $style['shadow']
        );

        $assContent = "[Script Info]\n";
        $assContent .= "Title: Clipping FX\n";
        $assContent .= "ScriptType: v4.00+\n";
        $assContent .= "WrapStyle: 2\n";
        $assContent .= "PlayResX: 1080\n";
        $assContent .= "PlayResY: 1920\n";
        $assContent .= "ScaledBorderAndShadow: yes\n\n";
        $assContent .= "[V4+ Styles]\n";
        $assContent .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $assContent .= "Style: Hook,DejaVu Sans,72,&H00FFFFFF,&H00FFFFFF,&H00111111,&H60000000,1,0,0,0,100,100,0,0,1,4,1,8,70,70,130,1\n";
        $assContent .= "Style: Label,DejaVu Sans,40,&H00FFFFFF,&H00FFFFFF,&H00111111,&H50000000,1,0,0,0,100,100,0,0,1,3,0,2,90,90,360,1\n";
        $assContent .= "Style: Cta,DejaVu Sans,52,&H0000F0FF,&H0000F0FF,&H00111111,&H64000000,1,0,0,0,100,100,0,0,1,4,1,2,80,80,170,1\n";
        // Narration subtitle: dynamic style based on user selection
        $assContent .= $narrationStyleLine . "\n\n";
        $assContent .= "[Events]\n";
        $assContent .= "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        // Hook title (top area, first few seconds)
        $assContent .= sprintf(
            "Dialogue: 0,%s,%s,Hook,,0,0,0,,{\\fad(180,220)\\t(0,420,\\fscx106\\fscy106)\\t(420,900,\\fscx100\\fscy100)}%s\n",
            $this->formatAssTime(0.0),
            $this->formatAssTime($hookEnd),
            $hookText
        );
        $assContent .= sprintf(
            "Dialogue: 0,%s,%s,Label,,0,0,0,,{\\an5\\pos(540,960)\\alpha&H24&\\fad(150,300)}TRÍCH ĐOẠN AUDIOBOOK\n",
            $this->formatAssTime(0.2),
            $this->formatAssTime($labelEnd)
        );

        // Helper: build karaoke per-word sequence with explicit line-wrap using \N
        // to avoid losing text when a subtitle chunk is too long for one line.
        $buildKaraoke = function(string $text, float $durSec, int $maxLineChars = 18): string {
            $plain = trim((string) preg_replace('/\s+/u', ' ', $text));
            if ($plain === '') {
                return '';
            }

            $allWords = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (empty($allWords)) {
                return '';
            }

            $lines = [];
            $currentLineWords = [];
            $currentLineLen = 0;

            foreach ($allWords as $word) {
                $wordLen = mb_strlen($word);
                $candidateLen = $currentLineLen === 0 ? $wordLen : ($currentLineLen + 1 + $wordLen);

                if ($candidateLen > $maxLineChars && !empty($currentLineWords)) {
                    $lines[] = $currentLineWords;
                    $currentLineWords = [$word];
                    $currentLineLen = $wordLen;
                    continue;
                }

                $currentLineWords[] = $word;
                $currentLineLen = $candidateLen;
            }

            if (!empty($currentLineWords)) {
                $lines[] = $currentLineWords;
            }

            $totalWordCount = count($allWords);
            $totalCs = max(10, (int) round($durSec * 100));
            $base = (int) floor($totalCs / $totalWordCount);
            $rem = $totalCs - $base * $totalWordCount;

            $wordIndex = 0;
            $lineParts = [];

            foreach ($lines as $lineWords) {
                $wordParts = [];
                foreach ($lineWords as $word) {
                    $k = $base + ($wordIndex === $totalWordCount - 1 ? $rem : 0);
                    $escapedWord = str_replace('\\', '\\\\', $word);
                    $escapedWord = str_replace('{', '\\{', $escapedWord);
                    $escapedWord = str_replace('}', '\\}', $escapedWord);
                    $wordParts[] = '{\\k' . max(1, $k) . '}' . $escapedWord;
                    $wordIndex++;
                }
                $lineParts[] = implode(' ', $wordParts);
            }

            return implode('\\N', $lineParts);
        };

        // Transcription narration subtitles (positioned via pos)
        // 1080x1920 → center x=540, y configurable (default lower-third)
        if (!empty($transcriptionChunks)) {
            foreach ($transcriptionChunks as $chunk) {
                $start = max(0.0, (float)($chunk['start'] ?? 0));
                $end = min($mainDuration, (float)($chunk['end'] ?? 0));
                if ($end <= $start || $end - $start < 0.15) continue;

                $rawText = trim((string)($chunk['text'] ?? ''));
                if ($rawText === '') continue;

                $kara = $buildKaraoke($rawText, max(0.15, $end - $start), 18);
                if ($kara === '') continue;

                $assContent .= sprintf(
                    "Dialogue: 1,%s,%s,Narration,,0,0,0,,{\\an5\\pos(540,%d)\\fad(80,80)}%s\n",
                    $this->formatAssTime($start),
                    $this->formatAssTime($end),
                    $subtitleY,
                    $kara
                );
            }
        }

        // CTA narration (during CTA segment, after main clip)
        $assContent .= sprintf(
            "Dialogue: 0,%s,%s,Cta,,0,0,0,,{\\fad(240,360)\\t(0,360,\\fscx103\\fscy103)\\t(360,760,\\fscx100\\fscy100)}%s\n",
            $this->formatAssTime($ctaStart),
            $this->formatAssTime($totalDuration),
            $ctaText
        );

        file_put_contents($absPath, $assContent);

        return [
            'absolute_path' => $absPath,
            'relative_path' => $relPath,
        ];
    }

    private function composeClippingMainSegment(
        string $ffmpegPath,
        string $clipPath,
        ?string $imagePath,
        ?string $animatedBgPath,
        float $mainDuration,
        string $outputPath
    ): void {
        $mainDuration = max(1.0, $mainDuration);
        $fadeDuration = min(1.4, max(0.8, $mainDuration * 0.08));
        $fadeStart = max(0.0, $mainDuration - $fadeDuration);

        if ($animatedBgPath && file_exists($animatedBgPath)) {
            $filterComplex = sprintf(
                "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,setsar=1,fps=30,format=yuv420p[v];[1:a]atrim=0:%.3f,asetpts=PTS-STARTPTS,aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,afade=t=out:st=%.3f:d=%.3f[a]",
                $mainDuration,
                $fadeStart,
                $fadeDuration
            );

            $cmd = sprintf(
                '%s -y -stream_loop -1 -i %s -i %s -t %.3f -filter_complex %s -map "[v]" -map "[a]" -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -shortest %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($animatedBgPath),
                escapeshellarg($clipPath),
                $mainDuration,
                escapeshellarg($filterComplex),
                escapeshellarg($outputPath)
            );

            $this->runFfmpegCommand($cmd, 'Không thể tạo đoạn clipping chính từ video motion background.');
            return;
        }

        if ($imagePath && file_exists($imagePath)) {
            $filterComplex = sprintf(
                "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,zoompan=z='min(1.08,max(1.0,1+0.015*sin(2*PI*on/(30*8))))':d=1:x='iw/2-(iw/zoom/2)+sin(on/35)*6':y='ih/2-(ih/zoom/2)+cos(on/45)*6':s=1080x1920:fps=30,format=yuv420p[v];[1:a]atrim=0:%.3f,asetpts=PTS-STARTPTS,aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,afade=t=out:st=%.3f:d=%.3f[a]",
                $mainDuration,
                $fadeStart,
                $fadeDuration
            );

            $cmd = sprintf(
                '%s -y -loop 1 -i %s -i %s -t %.3f -filter_complex %s -map "[v]" -map "[a]" -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -shortest %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($imagePath),
                escapeshellarg($clipPath),
                $mainDuration,
                escapeshellarg($filterComplex),
                escapeshellarg($outputPath)
            );

            $this->runFfmpegCommand($cmd, 'Không thể tạo đoạn clipping chính từ ảnh minh họa.');
            return;
        }

        $filterComplex = sprintf(
            '[0:v]scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:black,setsar=1,fps=30,format=yuv420p[v];[0:a]atrim=0:%.3f,asetpts=PTS-STARTPTS,aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,afade=t=out:st=%.3f:d=%.3f[a]',
            $mainDuration,
            $fadeStart,
            $fadeDuration
        );

        $cmd = sprintf(
            '%s -y -i %s -t %.3f -filter_complex %s -map "[v]" -map "[a]" -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -shortest %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($clipPath),
            $mainDuration,
            escapeshellarg($filterComplex),
            escapeshellarg($outputPath)
        );

        $this->runFfmpegCommand($cmd, 'Không thể tạo đoạn clipping chính từ video gốc.');
    }

    private function composeClippingCtaSegment(
        string $ffmpegPath,
        ?string $animationPath,
        ?string $imagePath,
        string $ctaAudioPath,
        float $ctaDuration,
        string $outputPath
    ): void {
        $ctaDuration = max(1.5, $ctaDuration);

        if ($animationPath && file_exists($animationPath)) {
            $vf = 'scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:black,fps=30,format=yuv420p';
            $cmd = sprintf(
                '%s -y -stream_loop -1 -i %s -i %s -t %.3f -vf %s -map 0:v:0 -map 1:a:0 -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -shortest %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($animationPath),
                escapeshellarg($ctaAudioPath),
                $ctaDuration,
                escapeshellarg($vf),
                escapeshellarg($outputPath)
            );
            $this->runFfmpegCommand($cmd, 'Không thể tạo đoạn CTA từ animation 9:16.');
            return;
        }

        if ($imagePath && file_exists($imagePath)) {
            $vf = "scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,zoompan=z='min(1.06,max(1.0,1+0.01*sin(2*PI*on/(30*6))))':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=1080x1920:fps=30,format=yuv420p";
            $cmd = sprintf(
                '%s -y -loop 1 -i %s -i %s -t %.3f -vf %s -map 0:v:0 -map 1:a:0 -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -shortest %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($imagePath),
                escapeshellarg($ctaAudioPath),
                $ctaDuration,
                escapeshellarg($vf),
                escapeshellarg($outputPath)
            );
            $this->runFfmpegCommand($cmd, 'Không thể tạo đoạn CTA fallback từ ảnh minh họa.');
            return;
        }

        $cmd = sprintf(
            '%s -y -f lavfi -i %s -i %s -t %.3f -vf %s -map 0:v:0 -map 1:a:0 -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -shortest %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg("color=c=black:s=1080x1920:r=30:d={$ctaDuration}"),
            escapeshellarg($ctaAudioPath),
            $ctaDuration,
            escapeshellarg('format=yuv420p'),
            escapeshellarg($outputPath)
        );
        $this->runFfmpegCommand($cmd, 'Không thể tạo đoạn CTA nền đen.');
    }

    public function listClippingVideos(AudioBook $audioBook)
    {
        $videos = [];

        // 1. Model-level video fields
        $fields = [
            'full_book_video'          => 'Video sách đầy đủ',
            'review_video'             => 'Video review',
            'description_scene_video'  => 'Video giới thiệu (scene)',
            'description_lipsync_video'=> 'Video giới thiệu (lipsync)',
        ];
        foreach ($fields as $field => $label) {
            $path = $audioBook->$field ?? null;
            if (!$path) continue;
            $absPath = storage_path('app/public/' . $path);
            if (!file_exists($absPath)) continue;
            $duration = $this->getVideoDuration($absPath);
            if ($duration < 10) continue;
            $videos[] = [
                'field'        => $field,
                'label'        => $label,
                'path'         => $path,
                'url'          => asset('storage/' . $path),
                'duration'     => round($duration, 1),
                'duration_fmt' => gmdate('H:i:s', (int)$duration),
            ];
        }

        // 2. Segment videos (same as auto-publish)
        foreach ($audioBook->videoSegments()->orderBy('sort_order')->get() as $segment) {
            if (!$segment->video_path || $segment->status !== 'completed') continue;
            $absPath = storage_path('app/public/' . $segment->video_path);
            if (!file_exists($absPath)) continue;
            $duration = $segment->video_duration ?: $this->getVideoDuration($absPath);
            if ($duration < 10) continue;
            $videos[] = [
                'field'        => 'segment_' . $segment->id,
                'label'        => 'Segment: ' . ($segment->name ?: 'Phần ' . $segment->sort_order),
                'path'         => $segment->video_path,
                'url'          => asset('storage/' . $segment->video_path),
                'duration'     => round($duration, 1),
                'duration_fmt' => gmdate('H:i:s', (int)$duration),
            ];
        }

        // 3. Chapter videos
        foreach ($audioBook->chapters()->orderBy('chapter_number')->get() as $chapter) {
            if (!$chapter->video_path) continue;
            $absPath = storage_path('app/public/' . $chapter->video_path);
            if (!file_exists($absPath)) continue;
            $duration = $chapter->total_duration ?: $this->getVideoDuration($absPath);
            if ($duration < 10) continue;
            $videos[] = [
                'field'        => 'chapter_' . $chapter->id,
                'label'        => 'Chương ' . $chapter->chapter_number . ': ' . $chapter->title,
                'path'         => $chapter->video_path,
                'url'          => asset('storage/' . $chapter->video_path),
                'duration'     => round($duration, 1),
                'duration_fmt' => gmdate('H:i:s', (int)$duration),
            ];
        }

        return response()->json(['success' => true, 'videos' => $videos]);
    }

    public function listClippingBackgroundAudios(AudioBook $audioBook)
    {
        $options = $this->collectClippingBackgroundAudioOptions($audioBook);
        $mappedOptions = array_map(function ($item) {
            $path = ltrim((string)($item['path'] ?? ''), '/');

            return [
                'path' => $path,
                'label' => (string)($item['label'] ?? basename($path)),
                'type' => (string)($item['type'] ?? 'library'),
                'url' => $path !== '' ? asset('storage/' . $path) : null,
            ];
        }, $options);

        $autoSelected = $this->resolveClippingBackgroundAudio($audioBook);

        return response()->json([
            'success' => true,
            'options' => array_values($mappedOptions),
            'auto_selected_path' => $autoSelected['relative_path'] ?? null,
            'auto_selected_url' => !empty($autoSelected['relative_path']) ? asset('storage/' . $autoSelected['relative_path']) : null,
        ]);
    }

    public function listClippingCtaAnimations(AudioBook $audioBook)
    {
        $options = $this->collectClippingAnimationOptions($audioBook->id);
        $mappedOptions = array_map(function ($item) {
            $path = ltrim((string)($item['relative_path'] ?? ''), '/');
            $width = (int)($item['width'] ?? 0);
            $height = (int)($item['height'] ?? 0);

            return [
                'path' => $path,
                'label' => basename($path),
                'url' => $path !== '' ? asset('storage/' . $path) : null,
                'width' => $width,
                'height' => $height,
                'ratio' => ($width > 0 && $height > 0) ? round($width / $height, 6) : null,
                'score' => isset($item['score']) ? round((float)$item['score'], 6) : null,
                'source' => (string)($item['source'] ?? 'unknown'),
            ];
        }, $options);

        $autoSelected = $options[0] ?? null;

        return response()->json([
            'success' => true,
            'options' => array_values($mappedOptions),
            'auto_selected_path' => $autoSelected['relative_path'] ?? null,
            'auto_selected_url' => !empty($autoSelected['relative_path']) ? asset('storage/' . $autoSelected['relative_path']) : null,
        ]);
    }

    public function listClips(AudioBook $audioBook)
    {
        $clips = $this->loadClips($audioBook->id);
        $baseUrl = rtrim(asset('storage'), '/') . '/';
        $mapped = array_map(function ($clip) use ($baseUrl) {
            return array_merge($clip, [
                'clip_url'          => !empty($clip['clip_path'])           ? $baseUrl . $clip['clip_path']           : null,
                'image_url'         => !empty($clip['image_path'])          ? $baseUrl . $clip['image_path']          : null,
                'image_animation_url' => !empty($clip['image_animation_path']) ? $baseUrl . $clip['image_animation_path'] : null,
                'composed_url'      => !empty($clip['composed_path'])       ? $baseUrl . $clip['composed_path']       : null,
                'cta_audio_url'     => !empty($clip['cta_audio_path'])      ? $baseUrl . $clip['cta_audio_path']      : null,
                'cta_animation_url' => !empty($clip['cta_animation_path'])  ? $baseUrl . $clip['cta_animation_path']  : null,
                'cta_animation_selected_url' => !empty($clip['cta_animation_selected_path']) ? $baseUrl . $clip['cta_animation_selected_path'] : null,
                'ass_subtitle_url'  => !empty($clip['ass_subtitle_path'])   ? $baseUrl . $clip['ass_subtitle_path']   : null,
                'bg_audio_url'      => !empty($clip['bg_audio_path'])       ? $baseUrl . $clip['bg_audio_path']       : null,
            ]);
        }, $clips);

        return response()->json(['success' => true, 'clips' => array_values($mapped)]);
    }

    public function updateClipSettings(Request $request, AudioBook $audioBook, string $clipId)
    {
        $clips = $this->loadClips($audioBook->id);
        $clip  = $this->findClip($clips, $clipId);
        if (!$clip) {
            return response()->json(['success' => false, 'error' => 'Clip không tồn tại.'], 404);
        }

        $validated = $request->validate([
            'cta_animation_mode' => 'nullable|string|in:auto,custom',
            'cta_animation_path' => 'nullable|string|max:500',
            'subtitle_style' => 'nullable|string|in:default,highlight_green,highlight_yellow,highlight_red,boxed,neon_blue',
            'subtitle_position' => 'nullable|string|in:lower_third,middle,bottom',
        ]);

        $updatePayload = [];

        $hasCtaMode = array_key_exists('cta_animation_mode', $validated);
        $hasCtaPath = array_key_exists('cta_animation_path', $validated)
            && !empty($validated['cta_animation_path']);

        if ($hasCtaMode || $hasCtaPath) {
            $ctaAnimationMode = strtolower((string)($validated['cta_animation_mode'] ?? ($clip['cta_animation_mode'] ?? 'auto')));
            if (!$hasCtaMode && $hasCtaPath) {
                $ctaAnimationMode = 'custom';
            }

            $ctaAnimationPathInput = $hasCtaPath
                ? ltrim((string)$validated['cta_animation_path'], '/')
                : ltrim((string)($clip['cta_animation_selected_path'] ?? ''), '/');

            if ($ctaAnimationMode === 'custom') {
                if ($ctaAnimationPathInput === '') {
                    return response()->json([
                        'success' => false,
                        'error' => 'Bạn chưa chọn animation CTA.',
                    ], 422);
                }

                $resolvedAnimation = $this->resolveClippingAnimationByPath($audioBook, $ctaAnimationPathInput);
                if (!$resolvedAnimation) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Animation CTA đã chọn không hợp lệ hoặc không đúng định dạng dọc.',
                    ], 422);
                }

                $ctaAnimationPathInput = (string)($resolvedAnimation['relative_path'] ?? $ctaAnimationPathInput);
            }

            $updatePayload['cta_animation_mode'] = $ctaAnimationMode === 'custom' ? 'custom' : 'auto';
            $updatePayload['cta_animation_selected_path'] = $ctaAnimationMode === 'custom' ? $ctaAnimationPathInput : null;
        }

        if (!empty($validated['subtitle_style'])) {
            $updatePayload['subtitle_style'] = (string)$validated['subtitle_style'];
        }

        if (!empty($validated['subtitle_position'])) {
            $updatePayload['subtitle_position'] = (string)$validated['subtitle_position'];
        }

        if (!empty($updatePayload)) {
            $this->updateClipInList($clips, $clipId, $updatePayload);
            $this->saveClips($audioBook->id, $clips);
        }

        $updatedClip = $this->findClip($clips, $clipId) ?? [];

        return response()->json([
            'success' => true,
            'clip' => $updatedClip,
            'cta_animation_selected_url' => !empty($updatedClip['cta_animation_selected_path'])
                ? asset('storage/' . $updatedClip['cta_animation_selected_path'])
                : null,
        ]);
    }

    public function generateClips(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'source_field' => 'required|string',
            'count'        => 'required|integer|min:1|max:20',
            'clip_duration'=> 'nullable|integer|min:30|max:120',
        ]);

        $sourceField = $request->input('source_field');
        $count       = (int) $request->input('count', 3);
        $clipDuration= (int) $request->input('clip_duration', 60);

        // Resolve path for model fields, segment_X, or chapter_X
        $sourcePath = null;
        if (str_starts_with($sourceField, 'segment_')) {
            $segId = (int) str_replace('segment_', '', $sourceField);
            $seg = $audioBook->videoSegments()->find($segId);
            $sourcePath = $seg?->video_path ?? null;
        } elseif (str_starts_with($sourceField, 'chapter_')) {
            $chapId = (int) str_replace('chapter_', '', $sourceField);
            $chap = $audioBook->chapters()->find($chapId);
            $sourcePath = $chap?->video_path ?? null;
        } else {
            $sourcePath = $audioBook->$sourceField ?? null;
        }

        if (!$sourcePath) {
            return response()->json(['success' => false, 'error' => 'Video nguồn không tồn tại.'], 422);
        }

        $absSourcePath = storage_path('app/public/' . $sourcePath);
        if (!file_exists($absSourcePath)) {
            return response()->json(['success' => false, 'error' => 'File video nguồn không tìm thấy.'], 422);
        }

        $totalDuration = $this->getVideoDuration($absSourcePath);
        if ($totalDuration < $clipDuration + 10) {
            return response()->json(['success' => false, 'error' => 'Video quá ngắn để cắt clip.'], 422);
        }

        $ffmpegPath = config('services.ffmpeg.path', env('FFMPEG_PATH', 'ffmpeg'));
        $clipsDir   = $this->getClippingDir($audioBook->id) . '/clips';
        $clips      = $this->loadClips($audioBook->id);

        // Generate random non-overlapping start times
        $usableEnd    = $totalDuration - $clipDuration - 5;
        $usableStart  = 5; // skip intro
        $startTimes   = [];
        $maxAttempts  = $count * 10;
        $attempts     = 0;

        while (count($startTimes) < $count && $attempts < $maxAttempts) {
            $attempts++;
            $candidate = round(mt_rand((int)($usableStart * 10), (int)($usableEnd * 10)) / 10, 1);
            // Check no overlap with existing
            $overlap = false;
            foreach ($startTimes as $existing) {
                if (abs($existing - $candidate) < $clipDuration) {
                    $overlap = true;
                    break;
                }
            }
            if (!$overlap) $startTimes[] = $candidate;
        }

        $newClips  = [];
        $failed    = 0;

        foreach ($startTimes as $i => $startTime) {
            $clipId   = 'clip_' . ($i + 1) . '_' . time() . '_' . mt_rand(100, 999);
            $filename = $clipId . '.mp4';
            $absClipPath = $clipsDir . '/' . $filename;
            $relClipPath = 'books/' . $audioBook->id . '/clipping/clips/' . $filename;

            $cmd = sprintf(
                '%s -y -ss %s -i %s -t %d -c:v libx264 -c:a aac -preset fast -crf 23 %s 2>&1',
                escapeshellarg($ffmpegPath),
                $startTime,
                escapeshellarg($absSourcePath),
                $clipDuration,
                escapeshellarg($absClipPath)
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($absClipPath)) {
                $failed++;
                Log::error("Clipping: FFmpeg failed for clip {$clipId}", ['output' => implode("\n", array_slice($output, -3))]);
                continue;
            }

            $actualDuration = $this->getVideoDuration($absClipPath);
            $clip = [
                'id'             => $clipId,
                'source_field'   => $sourceField,
                'source_path'    => $sourcePath,
                'start_time'     => $startTime,
                'duration'       => round($actualDuration, 1),
                'clip_path'      => $relClipPath,
                'hook_title'     => null,
                'cta'            => null,
                'cta_narration'  => null,
                'image_prompt'   => null,
                'image_path'     => null,
                'image_animation_path' => null,
                'image_animation_task_id' => null,
                'image_animation_status' => null,
                'image_animation_prompt' => null,
                'image_animation_provider' => null,
                'image_animation_error' => null,
                'cta_audio_path' => null,
                'cta_animation_path' => null,
                'cta_animation_mode' => 'auto',
                'cta_animation_selected_path' => null,
                'ass_subtitle_path' => null,
                'bg_audio_path'  => null,
                'bg_audio_mode'  => null,
                'bg_audio_volume'=> null,
                'subtitle_style' => 'highlight_green',
                'subtitle_position' => 'lower_third',
                'composed_path'  => null,
                'status'         => 'clipped',
                'created_at'     => now()->toDateTimeString(),
                'updated_at'     => now()->toDateTimeString(),
            ];

            $clips[]    = $clip;
            $newClips[] = $clip;
        }

        $this->saveClips($audioBook->id, $clips);

        return response()->json([
            'success'   => true,
            'generated' => count($newClips),
            'failed'    => $failed,
            'clips'     => array_map(fn($c) => array_merge($c, [
                'clip_url' => asset('storage/' . $c['clip_path']),
            ]), $newClips),
        ]);
    }

    public function generateClipHookTitle(Request $request, AudioBook $audioBook, string $clipId)
    {
        // If not from background job, queue and return immediately
        if (!$request->has('_from_job')) {
            \Illuminate\Support\Facades\Cache::put(
                \App\Jobs\GenerateClipTitleJob::cacheKey($audioBook->id, $clipId),
                [
                    'status' => 'queued',
                    'percent' => 0,
                    'message' => 'Đã đưa vào hàng đợi tạo tiêu đề...',
                    'clip_id' => $clipId,
                    'updated_at' => now()->toIso8601String(),
                ],
                now()->addHours(2)
            );
            \App\Jobs\GenerateClipTitleJob::dispatch($audioBook->id, $clipId, $request->all());

            return response()->json([
                'success' => true,
                'queued' => true,
                'message' => 'Đã đưa vào hàng đợi. Bạn có thể tắt trình duyệt.',
            ]);
        }

        $clips = $this->loadClips($audioBook->id);
        $clip  = $this->findClip($clips, $clipId);
        if (!$clip) {
            return response()->json(['success' => false, 'error' => 'Clip không tồn tại.'], 404);
        }

        $apiKey = config('services.gemini.api_key') ?: config('services.gemini.tts_api_key');
        if (!$apiKey) {
            return response()->json(['success' => false, 'error' => 'Gemini API key chưa được cấu hình.'], 500);
        }

        $bookTitle   = $audioBook->title ?? 'audiobook';
        $channelName = optional($audioBook->youtubeChannel)->title ?? '';
        $category    = $audioBook->category ?? '';

        // Extract audio snippet from clip for Gemini to listen to
        $clipAbsPath = $clip['clip_path'] ? storage_path('app/public/' . $clip['clip_path']) : null;
        $audioB64    = null;
        $tmpAudio    = null;

        if ($clipAbsPath && file_exists($clipAbsPath)) {
            $tmpAudio = sys_get_temp_dir() . '/clip_audio_' . $clipId . '.mp3';
            $ffmpeg   = config('services.ffmpeg.ffmpeg_path', 'ffmpeg');
            $cmd = sprintf(
                '%s -y -i %s -vn -ar 16000 -ac 1 -b:a 32k %s 2>/dev/null',
                escapeshellarg($ffmpeg),
                escapeshellarg($clipAbsPath),
                escapeshellarg($tmpAudio)
            );
            exec($cmd);
            if (file_exists($tmpAudio) && filesize($tmpAudio) > 0) {
                $audioB64 = base64_encode(file_get_contents($tmpAudio));
            }
        }

        $prompt = "Bạn là chuyên gia viết tiêu đề video viral cho YouTube Shorts và TikTok bằng tiếng Việt.\n\n";

        if ($audioB64) {
            $prompt .= "Hãy lắng nghe đoạn audio từ sách nói '{$bookTitle}'";
            if ($category) $prompt .= " (thể loại: {$category})";
            $prompt .= ".\n\n";
            $prompt .= "Dựa vào NỘI DUNG THỰC SỰ bạn nghe được trong audio, hãy tạo:\n";
        } else {
            $prompt .= "Tôi có một đoạn clip từ sách nói '{$bookTitle}'";
            if ($category) $prompt .= " (thể loại: {$category})";
            $prompt .= ".\n\nHãy tạo:\n";
        }

        $prompt .= "1. **HOOK TITLE**: Một câu tiêu đề ngắn gọn (dưới 80 ký tự), giật tít, tạo tò mò mạnh mẽ dựa trên nội dung clip. Ví dụ phong cách: 'Bí mật mà...', 'Không ai nói cho bạn biết...', 'Lý do tại sao...', 'Điều gì xảy ra khi...'.\n";
        $prompt .= "2. **CTA**: Một câu kêu gọi nghe đầy đủ, BẮT BUỘC có cụm 'Mời bạn', ví dụ: 'Mời bạn nghe trọn bộ «{$bookTitle}»" . ($channelName ? " trên kênh {$channelName}" : '') . "'\n\n";
        $prompt .= "Trả về JSON duy nhất:\n{\"hook_title\": \"...\", \"cta\": \"...\"}";

        try {
            $parts = [];
            if ($audioB64) {
                $parts[] = ['inline_data' => ['mime_type' => 'audio/mp3', 'data' => $audioB64]];
            }
            $parts[] = ['text' => $prompt];

            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
            $response = \Illuminate\Support\Facades\Http::timeout(60)->post($url, [
                'contents'         => [['parts' => $parts]],
                'generationConfig' => ['temperature' => 0.85, 'maxOutputTokens' => 300],
            ]);

            // Clean up temp audio
            if ($tmpAudio && file_exists($tmpAudio)) {
                unlink($tmpAudio);
            }

            $result = $response->json();
            $text   = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $text   = preg_replace('/```json\s*/i', '', $text);
            $text   = preg_replace('/```\s*/', '', $text);
            $parsed = json_decode(trim($text), true);

            $hookTitle = $parsed['hook_title'] ?? trim($text);
            $ctaRaw    = $parsed['cta'] ?? "Mời bạn nghe trọn bộ «{$bookTitle}»" . ($channelName ? " trên kênh {$channelName}" : '') . '.';
            $cta       = $this->ensureCtaHasMoiBan($ctaRaw, $bookTitle, $channelName);

            $this->updateClipInList($clips, $clipId, [
                'hook_title' => $hookTitle,
                'cta'        => $cta,
                'status'     => 'titled',
            ]);
            $this->saveClips($audioBook->id, $clips);

            return response()->json(['success' => true, 'hook_title' => $hookTitle, 'cta' => $cta]);
        } catch (\Exception $e) {
            if ($tmpAudio && file_exists($tmpAudio)) {
                unlink($tmpAudio);
            }
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getGenerateClipTitleProgress(AudioBook $audioBook, string $clipId)
    {
        $cacheKey = \App\Jobs\GenerateClipTitleJob::cacheKey($audioBook->id, $clipId);
        $progress = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if (!$progress) {
            $clips = $this->loadClips($audioBook->id);
            $clip  = $this->findClip($clips, $clipId);
            if ($clip && !empty($clip['hook_title'])) {
                return response()->json([
                    'success' => true,
                    'status' => 'completed',
                    'percent' => 100,
                    'message' => 'Đã tạo tiêu đề!',
                    'clip_id' => $clipId,
                    'result' => [ 'hook_title' => $clip['hook_title'], 'cta' => $clip['cta'] ?? null ],
                ]);
            }
            return response()->json([
                'success' => true,
                'status' => 'idle',
                'percent' => 0,
                'message' => 'Không có tiến trình nào đang chạy.',
                'clip_id' => $clipId,
            ]);
        }
        return response()->json(array_merge(['success' => true], $progress));
    }

    public function generateClipImage(Request $request, AudioBook $audioBook, string $clipId)
    {
        // If not called from job, dispatch to queue and return immediately
        if (!$request->has('_from_job')) {
            \Illuminate\Support\Facades\Cache::put(
                \App\Jobs\GenerateClipImageJob::cacheKey($audioBook->id, $clipId),
                [
                    'status' => 'queued',
                    'percent' => 0,
                    'message' => 'Đã đưa vào hàng đợi tạo ảnh...',
                    'clip_id' => $clipId,
                    'updated_at' => now()->toIso8601String(),
                ],
                now()->addHours(2)
            );

            \App\Jobs\GenerateClipImageJob::dispatch($audioBook->id, $clipId, $request->all());

            return response()->json([
                'success' => true,
                'queued' => true,
                'message' => 'Đã đưa vào hàng đợi. Bạn có thể tắt trình duyệt.',
            ]);
        }

        $clips = $this->loadClips($audioBook->id);
        $clip  = $this->findClip($clips, $clipId);
        if (!$clip) {
            return response()->json(['success' => false, 'error' => 'Clip không tồn tại.'], 404);
        }

        $bookTitle    = $audioBook->title ?? '';
        $category     = $audioBook->category ?? '';
        $author       = $audioBook->author ?? '';
        $description  = $audioBook->description ?? '';
        $hookTitle    = $clip['hook_title'] ?? '';
        $customPrompt = trim($request->input('image_prompt', ''));

        $apiKey = config('services.gemini.api_key') ?: config('services.gemini.tts_api_key');

        // Step 1: Extract audio from clip to understand content
        $clipAbsPath = !empty($clip['clip_path']) ? storage_path('app/public/' . $clip['clip_path']) : null;
        $audioB64    = null;
        $tmpAudio    = null;

        if ($clipAbsPath && file_exists($clipAbsPath)) {
            $tmpAudio = sys_get_temp_dir() . '/clip_img_audio_' . $clipId . '.mp3';
            $ffmpeg   = config('services.ffmpeg.ffmpeg_path', 'ffmpeg');
            $cmd = sprintf(
                '%s -y -i %s -vn -ar 16000 -ac 1 -b:a 32k %s 2>/dev/null',
                escapeshellarg($ffmpeg),
                escapeshellarg($clipAbsPath),
                escapeshellarg($tmpAudio)
            );
            exec($cmd);
            if (file_exists($tmpAudio) && filesize($tmpAudio) > 0) {
                $audioB64 = base64_encode(file_get_contents($tmpAudio));
            }
            if ($tmpAudio && file_exists($tmpAudio)) unlink($tmpAudio);
        }

        // Step 2: Ask Gemini to generate an image prompt from audio content
        if (!$customPrompt && $apiKey && $audioB64) {
            // Build book context block so Gemini respects the cultural/historical setting
            $bookContext  = "Tên sách: {$bookTitle}\n";
            if ($author)      $bookContext .= "Tác giả: {$author}\n";
            if ($category)    $bookContext .= "Thể loại: {$category}\n";
            if ($description) $bookContext .= "Giới thiệu sách: " . mb_substr($description, 0, 600) . "\n";

            $parts = [
                ['inline_data' => ['mime_type' => 'audio/mp3', 'data' => $audioB64]],
                ['text' =>
                    "Bạn đang nghe một đoạn sách nói tiếng Việt. Dưới đây là thông tin về cuốn sách:\n\n"
                    . $bookContext . "\n"
                    . "QUAN TRỌNG: Hãy đọc kỹ phần giới thiệu sách để hiểu đúng bối cảnh văn hóa, lịch sử, địa lý của câu chuyện. "
                    . "Ví dụ: nếu sách có bối cảnh Trung Quốc cổ đại thì nhân vật phải là người Châu Á trang phục cổ trang Trung Hoa, "
                    . "nếu là châu Âu thì trang phục châu Âu, v.v. Tuyệt đối không dùng nhân vật sai bối cảnh.\n\n"
                    . "Dựa vào nội dung audio vừa nghe, hãy viết một **image generation prompt bằng tiếng Anh** để tạo ảnh minh họa:\n"
                    . "- Mô tả cụ thể cảnh, nhân vật, trang phục, bối cảnh phù hợp với audio VÀ bối cảnh sách\n"
                    . "- Phong cách: cinematic, dramatic lighting, high detail, vertical 9:16\n"
                    . "- Không chữ trong ảnh (no text, no letters)\n"
                    . "- Dưới 120 từ\n\n"
                    . ($hookTitle ? "Tiêu đề clip: \"{$hookTitle}\"\n\n" : '')
                    . "Chỉ trả về prompt, không giải thích thêm."
                ],
            ];

            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
                $resp = \Illuminate\Support\Facades\Http::timeout(60)->post($url, [
                    'contents'         => [['parts' => $parts]],
                    'generationConfig' => ['temperature' => 0.8, 'maxOutputTokens' => 200],
                ]);
                $generatedPrompt = trim($resp->json()['candidates'][0]['content']['parts'][0]['text'] ?? '');
                if ($generatedPrompt) {
                    $customPrompt = $generatedPrompt;
                }
            } catch (\Exception $e) {
                // Fallback below
            }
        }

        // Fallback prompt if audio not available or Gemini failed
        if (!$customPrompt) {
            $base = $hookTitle ?: "A dramatic scene from the audiobook '{$bookTitle}'";
            $context = '';
            if ($description) {
                // Use first 300 chars of description to hint at cultural context
                $context = ' Set in the world described as: ' . mb_substr(strip_tags($description), 0, 300);
            }
            $customPrompt = $base . $context . '. Cinematic, dramatic lighting, high detail, vertical 9:16, no text, no letters.';
        }

        // Step 3: Generate image using GeminiImageService
        $imageService = app(\App\Services\GeminiImageService::class);

        $outputDir  = $this->getClippingDir($audioBook->id) . '/images';
        if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

        $filename   = $clipId . '_cover_' . time() . '.png';
        $outputPath = $outputDir . '/' . $filename;
        $relPath    = 'books/' . $audioBook->id . '/clipping/images/' . $filename;

        $result = $imageService->generateShortVerticalImage($customPrompt, $outputPath);

        if (!$result['success']) {
            return response()->json(['success' => false, 'error' => $result['error'] ?? 'Không thể tạo ảnh.'], 500);
        }

        if (!empty($clip['image_animation_path'])) {
            $this->deleteShortVideoAssetFile((string)$clip['image_animation_path']);
        }

        $this->updateClipInList($clips, $clipId, [
            'image_path'   => $relPath,
            'image_prompt' => $customPrompt,
            'image_animation_path' => null,
            'image_animation_task_id' => null,
            'image_animation_status' => null,
            'image_animation_prompt' => null,
            'image_animation_provider' => null,
            'image_animation_error' => null,
            'status'       => 'imaged',
        ]);
        $this->saveClips($audioBook->id, $clips);

        return response()->json([
            'success'      => true,
            'image_path'   => $relPath,
            'image_url'    => asset('storage/' . $relPath),
            'image_prompt' => $customPrompt,
        ]);
    }

    public function getGenerateClipImageProgress(AudioBook $audioBook, string $clipId)
    {
        $cacheKey = \App\Jobs\GenerateClipImageJob::cacheKey($audioBook->id, $clipId);
        $progress = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if (!$progress) {
            // Fallback: if image already present in clips list
            $clips = $this->loadClips($audioBook->id);
            $clip  = $this->findClip($clips, $clipId);
            if ($clip && !empty($clip['image_path'])) {
                return response()->json([
                    'success' => true,
                    'status' => 'completed',
                    'percent' => 100,
                    'message' => 'Đã tạo ảnh!',
                    'clip_id' => $clipId,
                    'result' => [
                        'image_url' => asset('storage/' . $clip['image_path']),
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'status' => 'idle',
                'percent' => 0,
                'message' => 'Không có tiến trình nào đang chạy.',
                'clip_id' => $clipId,
            ]);
        }

        return response()->json(array_merge(['success' => true], $progress));
    }

    public function startClipImageSeedance(Request $request, AudioBook $audioBook, string $clipId)
    {
        $validated = $request->validate([
            'prompt' => 'nullable|string|max:1000',
            'duration' => 'nullable|integer|min:3|max:10',
        ]);

        $clips = $this->loadClips($audioBook->id);
        $clip = $this->findClip($clips, $clipId);
        if (!$clip) {
            return response()->json(['success' => false, 'error' => 'Clip không tồn tại.'], 404);
        }

        $imagePath = ltrim((string)($clip['image_path'] ?? ''), '/');
        if ($imagePath === '') {
            return response()->json([
                'success' => false,
                'error' => 'Clip chưa có ảnh minh họa. Hãy tạo ảnh trước khi animate.',
            ], 422);
        }

        $absImagePath = storage_path('app/public/' . $imagePath);
        if (!file_exists($absImagePath)) {
            return response()->json([
                'success' => false,
                'error' => 'Ảnh minh họa không tồn tại trên server.',
            ], 422);
        }

        $duration = (int)($validated['duration'] ?? 5);
        if ($duration <= 0) {
            $duration = 5;
        }

        $bookTitle = trim((string)($audioBook->title ?? ''));
        $hookTitle = trim((string)($clip['hook_title'] ?? ''));
        $defaultPrompt =
            'Analyze this image and animate only subtle background/environment motion. '
            . 'Keep character pose and composition stable. '
            . 'Use gentle parallax, soft dust or fog drift, slight light flicker, and minimal camera breathing. '
            . 'No action scene, no strong body movement, no camera cuts. '
            . 'Cinematic vertical background motion for audiobook clip with seamless loop feeling.';

        if ($bookTitle !== '' || $hookTitle !== '') {
            $defaultPrompt .= ' Context: ' . trim($bookTitle . ' ' . $hookTitle) . '.';
        }

        $prompt = trim((string)($validated['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = $defaultPrompt;
        }

        $seedanceService = app(\App\Services\SeedanceAIService::class);
        $taskResult = $seedanceService->createImageToVideoTask($imagePath, $prompt, [
            'duration' => $duration,
            'ratio' => '9:16',
            'watermark' => false,
            'generate_audio' => false,
        ]);

        if (empty($taskResult['success'])) {
            return response()->json([
                'success' => false,
                'error' => (string)($taskResult['error'] ?? 'Không thể khởi tạo tác vụ Seedance.'),
            ], 500);
        }

        $this->updateClipInList($clips, $clipId, [
            'image_animation_task_id' => (string)($taskResult['task_id'] ?? ''),
            'image_animation_status' => 'processing',
            'image_animation_prompt' => $prompt,
            'image_animation_provider' => 'seedance',
            'image_animation_error' => null,
        ]);
        $this->saveClips($audioBook->id, $clips);

        return response()->json([
            'success' => true,
            'queued' => true,
            'task_id' => (string)($taskResult['task_id'] ?? ''),
            'message' => 'Đã gửi Seedance tạo chuyển động nền cho ảnh. Đang xử lý...',
        ]);
    }

    public function getClipImageSeedanceProgress(AudioBook $audioBook, string $clipId)
    {
        $clips = $this->loadClips($audioBook->id);
        $clip = $this->findClip($clips, $clipId);
        if (!$clip) {
            return response()->json(['success' => false, 'error' => 'Clip không tồn tại.'], 404);
        }

        $taskId = trim((string)($clip['image_animation_task_id'] ?? ''));
        $existingAnimationPath = trim((string)($clip['image_animation_path'] ?? ''));

        if ($taskId === '') {
            if ($existingAnimationPath !== '') {
                return response()->json([
                    'success' => true,
                    'status' => 'completed',
                    'percent' => 100,
                    'message' => 'Đã có video motion background.',
                    'clip_id' => $clipId,
                    'result' => [
                        'animation_path' => $existingAnimationPath,
                        'animation_url' => asset('storage/' . $existingAnimationPath),
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'status' => 'idle',
                'percent' => 0,
                'message' => 'Không có tiến trình animation nào đang chạy.',
                'clip_id' => $clipId,
            ]);
        }

        $seedanceService = app(\App\Services\SeedanceAIService::class);
        $statusResult = $seedanceService->getTaskStatus($taskId);

        if (empty($statusResult['success'])) {
            $error = (string)($statusResult['error'] ?? 'Không thể kiểm tra trạng thái Seedance.');
            $this->updateClipInList($clips, $clipId, [
                'image_animation_status' => 'error',
                'image_animation_error' => $error,
            ]);
            $this->saveClips($audioBook->id, $clips);

            return response()->json([
                'success' => true,
                'status' => 'error',
                'percent' => 100,
                'message' => $error,
                'clip_id' => $clipId,
            ]);
        }

        $status = strtolower((string)($statusResult['status'] ?? 'processing'));

        if ($status === 'completed') {
            $videoUrl = trim((string)($statusResult['video_url'] ?? ''));
            if ($videoUrl === '') {
                $error = 'Seedance hoàn tất nhưng không trả về video URL.';
                $this->updateClipInList($clips, $clipId, [
                    'image_animation_status' => 'error',
                    'image_animation_error' => $error,
                ]);
                $this->saveClips($audioBook->id, $clips);

                return response()->json([
                    'success' => true,
                    'status' => 'error',
                    'percent' => 100,
                    'message' => $error,
                    'clip_id' => $clipId,
                ]);
            }

            $relPath = 'books/' . $audioBook->id . '/clipping/animations/' . $clipId . '_seedance_' . time() . '.mp4';

            try {
                $this->downloadVideoToPublicStorage($videoUrl, $relPath);
            } catch (\Throwable $e) {
                $error = 'Không thể tải/lưu video Seedance: ' . $e->getMessage();
                $this->updateClipInList($clips, $clipId, [
                    'image_animation_status' => 'error',
                    'image_animation_error' => $error,
                ]);
                $this->saveClips($audioBook->id, $clips);

                return response()->json([
                    'success' => true,
                    'status' => 'error',
                    'percent' => 100,
                    'message' => $error,
                    'clip_id' => $clipId,
                ]);
            }

            if (!empty($clip['image_animation_path']) && $clip['image_animation_path'] !== $relPath) {
                $this->deleteShortVideoAssetFile((string)$clip['image_animation_path']);
            }

            $this->updateClipInList($clips, $clipId, [
                'image_animation_path' => $relPath,
                'image_animation_status' => 'completed',
                'image_animation_task_id' => null,
                'image_animation_error' => null,
                'image_animation_provider' => 'seedance',
            ]);
            $this->saveClips($audioBook->id, $clips);

            return response()->json([
                'success' => true,
                'status' => 'completed',
                'percent' => 100,
                'message' => 'Đã tạo video chuyển động nền bằng Seedance.',
                'clip_id' => $clipId,
                'result' => [
                    'animation_path' => $relPath,
                    'animation_url' => asset('storage/' . $relPath),
                ],
            ]);
        }

        if ($status === 'failed') {
            $error = (string)($statusResult['error'] ?? 'Seedance xử lý thất bại.');
            $this->updateClipInList($clips, $clipId, [
                'image_animation_status' => 'error',
                'image_animation_error' => $error,
            ]);
            $this->saveClips($audioBook->id, $clips);

            return response()->json([
                'success' => true,
                'status' => 'error',
                'percent' => 100,
                'message' => $error,
                'clip_id' => $clipId,
            ]);
        }

        $this->updateClipInList($clips, $clipId, [
            'image_animation_status' => 'processing',
            'image_animation_error' => null,
        ]);
        $this->saveClips($audioBook->id, $clips);

        return response()->json([
            'success' => true,
            'status' => 'processing',
            'percent' => 55,
            'message' => 'Seedance đang tạo chuyển động nền...',
            'clip_id' => $clipId,
        ]);
    }

    public function composeClip(Request $request, AudioBook $audioBook, string $clipId)
    {
        $clips = $this->loadClips($audioBook->id);
        $clip  = $this->findClip($clips, $clipId);
        if (!$clip) {
            return response()->json(['success' => false, 'error' => 'Clip không tồn tại.'], 404);
        }

        $validated = $request->validate([
            'background_audio_mode' => 'nullable|string|in:auto,none,custom',
            'background_audio_path' => 'nullable|string|max:500',
            'background_audio_volume' => 'nullable|numeric|min:0|max:0.6',
            'cta_animation_mode' => 'nullable|string|in:auto,custom',
            'cta_animation_path' => 'nullable|string|max:500',
            'subtitle_style' => 'nullable|string|in:default,highlight_green,highlight_yellow,highlight_red,boxed,neon_blue',
            'subtitle_position' => 'nullable|string|in:lower_third,middle,bottom',
        ]);

        $allowedSubtitleStyles = ['default', 'highlight_green', 'highlight_yellow', 'highlight_red', 'boxed', 'neon_blue'];
        $allowedSubtitlePositions = ['lower_third', 'middle', 'bottom'];

        $subtitleStyle = (string)($validated['subtitle_style'] ?? ($clip['subtitle_style'] ?? 'highlight_green'));
        if (!in_array($subtitleStyle, $allowedSubtitleStyles, true)) {
            $subtitleStyle = 'highlight_green';
        }

        $subtitlePosition = (string)($validated['subtitle_position'] ?? ($clip['subtitle_position'] ?? 'lower_third'));
        if (!in_array($subtitlePosition, $allowedSubtitlePositions, true)) {
            $subtitlePosition = 'lower_third';
        }

        $bgAudioMode = strtolower((string)($validated['background_audio_mode'] ?? 'auto'));
        $bgAudioPathInput = isset($validated['background_audio_path'])
            ? ltrim((string)$validated['background_audio_path'], '/')
            : null;
        $bgAudioVolumeDb = -30.0;
        $bgAudioVolume = pow(10, $bgAudioVolumeDb / 20); // ≈ 0.0316

        $ctaAnimationMode = strtolower((string)($validated['cta_animation_mode'] ?? ($clip['cta_animation_mode'] ?? 'auto')));
        $ctaAnimationPathInput = isset($validated['cta_animation_path'])
            ? ltrim((string)$validated['cta_animation_path'], '/')
            : ltrim((string)($clip['cta_animation_selected_path'] ?? ''), '/');

        if ($bgAudioMode === 'custom' && !$bgAudioPathInput) {
            return response()->json([
                'success' => false,
                'error' => 'Bạn chưa chọn file âm thanh nền.',
            ], 422);
        }

        if ($ctaAnimationMode === 'custom' && $ctaAnimationPathInput === '') {
            return response()->json([
                'success' => false,
                'error' => 'Bạn chưa chọn animation CTA.',
            ], 422);
        }

        $clipPath = !empty($clip['clip_path'])
            ? storage_path('app/public/' . $clip['clip_path'])
            : null;

        if (!$clipPath || !file_exists($clipPath)) {
            return response()->json(['success' => false, 'error' => 'File clip không tồn tại.'], 422);
        }

        // If NOT called from background job, dispatch job and return immediately
        if (!$request->has('_from_job')) {
            $jobPayload = array_merge($validated, ['_from_job' => true]);

            // Mark clip status as composing
            $this->updateClipInList($clips, $clipId, [
                'status' => 'composing',
            ]);
            $this->saveClips($audioBook->id, $clips);

            // Set initial progress
            \Illuminate\Support\Facades\Cache::put(
                \App\Jobs\ComposeClipJob::cacheKey($audioBook->id, $clipId),
                [
                    'status' => 'queued',
                    'percent' => 0,
                    'message' => 'Đang chờ xử lý trong hàng đợi...',
                    'clip_id' => $clipId,
                    'updated_at' => now()->toIso8601String(),
                ],
                now()->addHours(4)
            );

            \App\Jobs\ComposeClipJob::dispatch($audioBook->id, $clipId, $jobPayload);

            return response()->json([
                'success' => true,
                'queued' => true,
                'message' => 'Đã đưa vào hàng đợi xử lý nền. Bạn có thể tắt trình duyệt.',
                'clip_id' => $clipId,
            ]);
        }

        // === Below runs inside background job ===

        $ffmpegPath = config('services.ffmpeg.path', env('FFMPEG_PATH', 'ffmpeg'));
        $hookTitle = trim((string)($clip['hook_title'] ?? $audioBook->title ?? 'Trích đoạn audiobook'));
        if ($hookTitle === '') {
            $hookTitle = 'Trích đoạn audiobook';
        }

        $clipDuration = (float)($clip['duration'] ?? 0.0);
        if ($clipDuration <= 0) {
            $clipDuration = $this->getVideoDuration($clipPath);
        }
        if ($clipDuration <= 0) {
            return response()->json(['success' => false, 'error' => 'Không xác định được thời lượng clip để ghép.'], 422);
        }

        $clipNumber = $this->getClipNumberFromId($clipId);
        $ctaNarration = $this->buildClipCtaNarration($audioBook, $clip, $clipNumber);

        $imagePath = !empty($clip['image_path'])
            ? storage_path('app/public/' . $clip['image_path'])
            : null;
        if ($imagePath && !file_exists($imagePath)) {
            $imagePath = null;
        }

        $animatedBgPath = !empty($clip['image_animation_path'])
            ? storage_path('app/public/' . ltrim((string)$clip['image_animation_path'], '/'))
            : null;
        if ($animatedBgPath && !file_exists($animatedBgPath)) {
            $animatedBgPath = null;
        }
        if ($animatedBgPath) {
            $imagePath = null;
        }

        $outputDir = $this->getClippingDir($audioBook->id) . '/composed';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $filename = $clipId . '_final_' . time() . '.mp4';
        $outputPath = $outputDir . '/' . $filename;
        $relPath = 'books/' . $audioBook->id . '/clipping/composed/' . $filename;

        $tempDir = storage_path('app/temp/clipping_compose_' . $audioBook->id . '_' . $clipId . '_' . time());
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            $mainSegmentPath = $tempDir . '/main_segment.mp4';
            $ctaSegmentPath = $tempDir . '/cta_segment.mp4';
            $concatListPath = $tempDir . '/concat_list.txt';
            $mergedPath = $tempDir . '/merged.mp4';
            $mixedPath = $tempDir . '/mixed.mp4';

            $ctaAudio = $this->createClipCtaAudio($audioBook, $clipId, $ctaNarration);
            $ctaAudioPath = (string)$ctaAudio['absolute_path'];
            $ctaAudioRelPath = (string)$ctaAudio['relative_path'];
            $ctaDuration = max(2.0, (float)($ctaAudio['duration'] ?? 0.0));

            $animationModeUsed = 'auto';
            $animationSelectedPath = null;
            $animation = null;

            if ($ctaAnimationMode === 'custom' && $ctaAnimationPathInput !== '') {
                $resolvedAnimation = $this->resolveClippingAnimationByPath($audioBook, $ctaAnimationPathInput);
                if (!$resolvedAnimation) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Animation CTA đã chọn không hợp lệ hoặc không đúng định dạng dọc.',
                    ], 422);
                }

                $animation = $resolvedAnimation;
                $animationModeUsed = 'custom';
                $animationSelectedPath = (string)($resolvedAnimation['relative_path'] ?? $ctaAnimationPathInput);
            }

            if (!$animation) {
                $animation = $this->findBestClippingAnimation($audioBook->id);
                $animationModeUsed = 'auto';
                $animationSelectedPath = null;
            }

            $animationPath = $animation['absolute_path'] ?? null;
            $animationRelPath = $animation['relative_path'] ?? null;

            $this->composeClippingMainSegment(
                $ffmpegPath,
                $clipPath,
                $imagePath,
                $animatedBgPath,
                $clipDuration,
                $mainSegmentPath
            );

            $this->composeClippingCtaSegment(
                $ffmpegPath,
                $animationPath,
                $imagePath,
                $ctaAudioPath,
                $ctaDuration,
                $ctaSegmentPath
            );

            // Use concat filter instead of concat demuxer to handle
            // stream parameter mismatches (sample rate, channels, timebase)
            $concatFilter = '[0:v][0:a][1:v][1:a]concat=n=2:v=1:a=1[vout][aout]';

            $concatCmd = sprintf(
                '%s -y -i %s -i %s -filter_complex %s -map "[vout]" -map "[aout]" -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 192k -ar 44100 -ac 2 -pix_fmt yuv420p %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($mainSegmentPath),
                escapeshellarg($ctaSegmentPath),
                escapeshellarg($concatFilter),
                escapeshellarg($mergedPath)
            );
            $this->runFfmpegCommand($concatCmd, 'Không thể nối đoạn chính và đoạn CTA.');

            $finalInputPath = $mergedPath;
            $bgAudio = null;
            $bgAudioRelPath = null;
            $bgAudioModeUsed = $bgAudioMode;

            if ($bgAudioMode === 'none') {
                $bgAudio = null;
            } elseif ($bgAudioMode === 'custom') {
                $bgAudio = $this->resolveClippingBackgroundAudioByPath($audioBook, $bgAudioPathInput);
                if (!$bgAudio) {
                    return response()->json([
                        'success' => false,
                        'error' => 'File âm thanh nền đã chọn không hợp lệ hoặc không tồn tại.',
                    ], 422);
                }
            } else {
                $bgAudioModeUsed = 'auto';
                $bgAudio = $this->resolveClippingBackgroundAudio($audioBook);
            }

            if ($bgAudio && !empty($bgAudio['relative_path'])) {
                $bgAudioRelPath = (string)$bgAudio['relative_path'];
            }

            if (
                $bgAudio
                && !empty($bgAudio['absolute_path'])
                && file_exists($bgAudio['absolute_path'])
                && $bgAudioVolume > 0.0001
            ) {
                $mixFilter = sprintf(
                    '[0:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo[voice];[1:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,volume=%.1fdB[bg];[voice][bg]amix=inputs=2:duration=first:dropout_transition=0:normalize=0[aout]',
                    $bgAudioVolumeDb
                );

                $mixCmd = sprintf(
                    '%s -y -i %s -stream_loop -1 -i %s -filter_complex %s -map 0:v:0 -map "[aout]" -c:v copy -c:a aac -b:a 192k -ar 44100 -ac 2 %s 2>&1',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($mergedPath),
                    escapeshellarg($bgAudio['absolute_path']),
                    escapeshellarg($mixFilter),
                    escapeshellarg($mixedPath)
                );
                $this->runFfmpegCommand($mixCmd, 'Không thể trộn âm thanh nền cho clipping.');
                $finalInputPath = $mixedPath;
            }

            // Transcribe clip audio using Whisper for narration subtitles
            $transcriptionChunks = [];
            try {
                $rawSegments = $this->transcribeClipAudio($clipPath, $clipDuration);
                $transcriptionChunks = $this->groupTranscriptionForSubtitles($rawSegments, 36);
                Log::info('Clipping transcription result', [
                    'clip_id' => $clipId,
                    'raw_segments' => count($rawSegments),
                    'subtitle_chunks' => count($transcriptionChunks),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Clipping transcription skipped', [
                    'clip_id' => $clipId,
                    'error' => $e->getMessage(),
                ]);
            }

            $subtitleY = 1450; // centered X=540, Y in 1400-1550 range
            if ($subtitlePosition === 'middle') {
                $subtitleY = 1400;
            } elseif ($subtitlePosition === 'bottom') {
                $subtitleY = 1550;
            }

            $assSubtitle = $this->createClippingAssSubtitleFile(
                $audioBook->id,
                $clipId,
                $hookTitle,
                $ctaNarration,
                $clipDuration,
                $ctaDuration,
                $transcriptionChunks,
                $subtitleStyle,
                $subtitleY
            );
            $assSubtitlePath = (string)$assSubtitle['absolute_path'];
            $assSubtitleRelPath = (string)$assSubtitle['relative_path'];

            $assFilterPath = str_replace(['\\', ':', "'"], ['\\\\', '\\:', "\\'"], $assSubtitlePath);
            // Use simple double-quote shell escaping for -vf argument.
            // escapeshellarg with inner quotes causes FFmpeg to misinterpret the path.
            $subtitleCmd = sprintf(
                '%s -y -i %s -vf "ass=%s" -map 0:v:0 -map 0:a:0 -c:v libx264 -preset fast -crf 23 -c:a copy -movflags +faststart %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($finalInputPath),
                $assFilterPath,
                escapeshellarg($outputPath)
            );

            Log::info('Clipping ASS burn command', ['clip_id' => $clipId, 'cmd' => $subtitleCmd]);

            $subtitleOutput = [];
            exec($subtitleCmd, $subtitleOutput, $subtitleCode);
            $subtitleEffect = 'ass';

            if ($subtitleCode !== 0 || !file_exists($outputPath)) {
                Log::warning('Clipping ASS burn failed, fallback to direct output', [
                    'clip_id' => $clipId,
                    'code' => $subtitleCode,
                    'output' => implode("\n", array_slice($subtitleOutput, -10)),
                    'ass_path' => $assSubtitlePath,
                ]);

                $fallbackCmd = sprintf(
                    '%s -y -i %s -c:v copy -c:a copy %s 2>&1',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($finalInputPath),
                    escapeshellarg($outputPath)
                );
                $this->runFfmpegCommand($fallbackCmd, 'Không thể hoàn thiện clipping (fallback).');
                $subtitleEffect = 'fallback_copy';
            }

            if (!empty($clip['composed_path']) && $clip['composed_path'] !== $relPath) {
                $this->deleteShortVideoAssetFile($clip['composed_path']);
            }
            if (!empty($clip['cta_audio_path']) && $clip['cta_audio_path'] !== $ctaAudioRelPath) {
                $this->deleteShortVideoAssetFile($clip['cta_audio_path']);
            }
            if (!empty($clip['ass_subtitle_path']) && $clip['ass_subtitle_path'] !== $assSubtitleRelPath) {
                $this->deleteShortVideoAssetFile($clip['ass_subtitle_path']);
            }

            $this->updateClipInList($clips, $clipId, [
                'cta' => $ctaNarration,
                'cta_narration' => $ctaNarration,
                'cta_audio_path' => $ctaAudioRelPath,
                'cta_animation_path' => $animationRelPath,
                'cta_animation_mode' => $animationModeUsed,
                'cta_animation_selected_path' => $animationSelectedPath,
                'ass_subtitle_path' => $assSubtitleRelPath,
                'bg_audio_path' => $bgAudioRelPath,
                'bg_audio_mode' => $bgAudioModeUsed,
                'bg_audio_volume' => $bgAudioRelPath ? round($bgAudioVolume, 3) : null,
                'subtitle_style' => $subtitleStyle,
                'subtitle_position' => $subtitlePosition,
                'composed_path' => $relPath,
                'status' => 'composed',
            ]);
            $this->saveClips($audioBook->id, $clips);

            return response()->json([
                'success' => true,
                'composed_path' => $relPath,
                'composed_url' => asset('storage/' . $relPath),
                'cta_audio_path' => $ctaAudioRelPath,
                'cta_animation_path' => $animationRelPath,
                'cta_animation_mode' => $animationModeUsed,
                'cta_animation_selected_path' => $animationSelectedPath,
                'ass_subtitle_path' => $assSubtitleRelPath,
                'bg_audio_path' => $bgAudioRelPath,
                'bg_audio_mode' => $bgAudioModeUsed,
                'bg_audio_volume' => $bgAudioRelPath ? round($bgAudioVolume, 3) : null,
                'subtitle_style' => $subtitleStyle,
                'subtitle_position' => $subtitlePosition,
                'subtitle_effect' => $subtitleEffect,
            ]);
        } catch (\Throwable $e) {
            Log::error('Clipping compose pipeline failed', [
                'clip_id' => $clipId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        } finally {
            if (is_dir($tempDir)) {
                $files = glob($tempDir . '/*') ?: [];
                foreach ($files as $file) {
                    @unlink($file);
                }
                @rmdir($tempDir);
            }
        }
    }

    public function getComposeClipProgress(AudioBook $audioBook, string $clipId)
    {
        $cacheKey = \App\Jobs\ComposeClipJob::cacheKey($audioBook->id, $clipId);
        $progress = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if (!$progress) {
            // No job in progress - check clip status from clips list
            $clips = $this->loadClips($audioBook->id);
            $clip = $this->findClip($clips, $clipId);
            if ($clip && ($clip['status'] ?? '') === 'composed') {
                return response()->json([
                    'success' => true,
                    'status' => 'completed',
                    'percent' => 100,
                    'message' => 'Đã ghép video hoàn tất.',
                    'clip_id' => $clipId,
                    'result' => [
                        'composed_url' => !empty($clip['composed_path']) ? asset('storage/' . $clip['composed_path']) : null,
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'status' => 'idle',
                'percent' => 0,
                'message' => 'Không có tiến trình nào đang chạy.',
                'clip_id' => $clipId,
            ]);
        }

        return response()->json(array_merge(['success' => true], $progress));
    }

    public function deleteClip(Request $request, AudioBook $audioBook, string $clipId)
    {
        $clips = $this->loadClips($audioBook->id);
        $clip  = $this->findClip($clips, $clipId);
        if (!$clip) {
            return response()->json(['success' => false, 'error' => 'Clip không tồn tại.'], 404);
        }

        foreach (['clip_path', 'image_path', 'image_animation_path', 'cta_audio_path', 'ass_subtitle_path', 'composed_path'] as $field) {
            if (!empty($clip[$field])) {
                $abs = storage_path('app/public/' . $clip[$field]);
                if (file_exists($abs)) @unlink($abs);
            }
        }

        $clips = array_values(array_filter($clips, fn($c) => ($c['id'] ?? '') !== $clipId));
        $this->saveClips($audioBook->id, $clips);

        return response()->json(['success' => true]);
    }
}
