<?php

namespace App\Http\Controllers;

use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use App\Models\ChannelSpeaker;
use App\Models\YoutubeChannel;
use App\Services\BookScrapers\NhaSachMienPhiScraper;
use App\Services\TTSService;
use App\Services\GeminiImageService;
use App\Services\KlingAIService;
use App\Services\DIDLipsyncService;
use App\Services\LipsyncSegmentManager;
use App\Services\VideoCompositionService;
use App\Services\DescriptionVideoService;
use Illuminate\Http\Request;
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

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $audioBooks = AudioBook::with('youtubeChannel')->paginate(15);
        return view('audiobooks.index', compact('audioBooks'));
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

        return view('audiobooks.create', compact('youtubeChannels', 'authorBooks', 'categoryOptions'));
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

        $audioBook = AudioBook::create($data);
        return redirect()->route('audiobooks.show', $audioBook)->with('success', 'Sách âm thanh đã được tạo');
    }

    /**
     * Display the specified resource.
     */
    public function show(AudioBook $audioBook)
    {
        $audioBook->load(['youtubeChannel', 'chapters', 'speaker']);

        // Get available speakers from the same YouTube channel
        $speakers = [];
        if ($audioBook->youtube_channel_id) {
            $speakers = ChannelSpeaker::where('youtube_channel_id', $audioBook->youtube_channel_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        return view('audiobooks.show', compact('audioBook', 'speakers'));
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
        $audioBook->delete();
        return redirect()->route('audiobooks.index')->with('success', 'Sách âm thanh đã bị xóa');
    }

    /**
     * Scrape chapters from book URL
     */
    public function scrapeChapters(Request $request)
    {
        $request->validate([
            'book_url' => 'required|url',
            'audio_book_id' => 'required|exists:audio_books,id'
        ]);

        $audioBook = AudioBook::findOrFail($request->input('audio_book_id'));
        $bookUrl = $request->input('book_url');

        try {
            // Detect website and use appropriate scraper
            $scraper = new NhaSachMienPhiScraper($bookUrl);
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
     * Update TTS settings for audiobook
     */
    public function updateTtsSettings(Request $request, AudioBook $audioBook)
    {
        $data = $request->validate([
            'tts_provider' => 'nullable|string|in:openai,gemini,microsoft,vbee',
            'tts_voice_gender' => 'nullable|string|in:male,female',
            'tts_voice_name' => 'nullable|string|max:100',
            'tts_style_instruction' => 'nullable|string|max:1000'
        ]);

        $audioBook->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Đã lưu cấu hình TTS'
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

            // Delete old file if exists
            $oldPath = $type === 'intro' ? $audioBook->intro_music : $audioBook->outro_music;
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

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

            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

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

        try {
            $description = $request->input('description');
            $provider = $request->input('provider');
            $voiceName = $request->input('voice_name');
            $voiceGender = $request->input('voice_gender', 'female');
            $styleInstruction = $request->input('style_instruction');
            $bookId = $audioBook->id;

            // Skip style_instruction for Microsoft and OpenAI TTS
            $providersWithoutStyle = ['microsoft', 'openai'];
            if (in_array($provider, $providersWithoutStyle)) {
                $styleInstruction = null;
            }

            // Generate full audio using TTSService
            $audioPath = $this->ttsService->generateAudio(
                $description,
                0, // index
                $voiceGender,
                $voiceName,
                $provider,
                $styleInstruction,
                null // projectId
            );

            // Move to permanent location
            $outputDir = storage_path('app/public/books/' . $bookId);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $timestamp = time();
            $filename = "description_{$timestamp}.mp3";
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

            $sourcePath = storage_path('app/' . $audioPath);
            if (file_exists($sourcePath)) {
                // Delete old description audio if exists
                if ($audioBook->description_audio) {
                    $oldPath = storage_path('app/public/' . $audioBook->description_audio);
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }

                copy($sourcePath, $outputPath);
                unlink($sourcePath); // Clean up original
            }

            // Get audio duration
            $duration = $this->getAudioDuration($outputPath);

            // Save to audiobook
            $relativePath = 'books/' . $bookId . '/' . $filename;
            $audioBook->update([
                'description_audio' => $relativePath,
                'description_audio_duration' => $duration
            ]);

            Log::info("Generated description audio for audiobook {$audioBook->id}: {$filename}");

            return response()->json([
                'success' => true,
                'audio_file' => $relativePath,
                'audio_url' => asset('storage/' . $relativePath),
                'duration' => $duration
            ]);
        } catch (\Exception $e) {
            Log::error("Generate description audio failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate lip-sync video from existing description audio
     */
    public function generateDescriptionVideo(Request $request, AudioBook $audioBook)
    {
        try {
            // Validate: need description audio
            if (!$audioBook->description_audio) {
                return response()->json([
                    'success' => false,
                    'error' => 'Chưa có audio giới thiệu. Vui lòng tạo audio trước.'
                ], 400);
            }

            // Validate: need intro music
            if (!$audioBook->intro_music) {
                return response()->json([
                    'success' => false,
                    'error' => 'Chưa có nhạc Intro. Vui lòng upload nhạc Intro trước.'
                ], 400);
            }

            // Validate: need wave settings
            if (!$audioBook->wave_enabled) {
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
                return response()->json([
                    'success' => false,
                    'error' => 'File ảnh không tồn tại: ' . $imageFilename
                ], 404);
            }

            $voicePath = storage_path('app/public/' . $audioBook->description_audio);
            if (!file_exists($voicePath)) {
                return response()->json([
                    'success' => false,
                    'error' => 'File audio giới thiệu không tồn tại.'
                ], 404);
            }

            $introMusicPath = storage_path('app/public/' . $audioBook->intro_music);
            if (!file_exists($introMusicPath)) {
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
            //   |  music full vol  |  music low (0.15) + voice        |  music fade up → fade out  |
            //
            // Music track: loops the intro music for the full duration
            //   - Starts at volume 1.0
            //   - At intro_fade seconds: fade down to 0.15 over 2s
            //   - At (intro_fade + voice_duration - 1): fade back up to 0.8 over 2s
            //   - At (total_duration - outro_fade): fade out to 0 over outro_fade seconds
            //
            // Voice track: delayed by intro_fade seconds

            $voiceStartTime = $introFadeDuration; // voice starts after intro music fade
            $voiceEndTime = $voiceStartTime + $voiceDuration;

            // Time points for volume envelope (must be in ascending order for nested if())
            // t1: start fading music down (1s before voice starts)
            // t2: end of fade down (t1 + 2s)
            // t3: voice ends, start fading music back up
            // t4: end of fade up (t3 + 2s, capped at totalDuration)
            // t5: total duration (music fades from 0.8 to 0 between t4 and t5)
            $t1 = max(0, $voiceStartTime - 1);
            $t2 = $t1 + 2;
            $t3 = $voiceEndTime;
            $t4 = min($t3 + 2, $totalDuration);
            $t5 = $totalDuration;
            $fadeOutTime = max(0.1, $t5 - $t4); // time for final fade out

            // Build audio filter_complex:
            // [0:a] = intro music (looped)
            // [1:a] = voice
            $audioFilterComplex = sprintf(
                // Music: loop to fill total duration, then apply volume envelope
                '[0:a]aloop=loop=-1:size=2e+09,atrim=0:%s,' .
                    // Volume envelope: full → fade down → low → fade up → fade out
                    'volume=eval=frame:volume=\'' .
                    'if(lt(t,%s), 1,' .              // 0 → t1: full volume
                    'if(lt(t,%s), 1-0.85*(t-%s)/2,' . // t1 → t2: fade 1.0→0.15
                    'if(lt(t,%s), 0.15,' .             // t2 → t3: low at 0.15 (during voice)
                    'if(lt(t,%s), 0.15+0.65*(t-%s)/2,' . // t3 → t4: fade 0.15→0.8
                    'max(0, 0.8*(1-(t-%s)/%s))' .      // t4 → t5: fade 0.8→0
                    '))))\',aformat=sample_fmts=fltp[music];' .
                    // Voice: delay by intro_fade seconds
                    '[1:a]adelay=%d|%d,aformat=sample_fmts=fltp[voice];' .
                    // Mix both tracks
                    '[music][voice]amix=inputs=2:duration=first:dropout_transition=3[mixout]',
                round($t5, 2),
                round($t1, 2),
                round($t2, 2),
                round($t1, 2),
                round($t3, 2),
                round($t4, 2),
                round($t3, 2),
                round($t4, 2),
                round($fadeOutTime, 2),
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

            Log::info("FFmpeg audio mix command", ['cmd' => $mixCmd]);
            exec($mixCmd, $mixOutput, $mixReturnCode);

            if ($mixReturnCode !== 0 || !file_exists($mixedAudioPath)) {
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

            // Video filter with zoompan + wave overlay
            // Input 0: image (looped), Input 1: mixed audio
            $videoFilterComplex = sprintf(
                // Image → scale/pad → zoompan
                '[0:v]scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2,' .
                    'zoompan=z=\'min(zoom+0.0003,1.2)\':d=%d:x=\'iw/2-(iw/zoom/2)\':y=\'ih/2-(ih/zoom/2)\':s=1920x1080:fps=25[bg];' .
                    // Wave visualization from mixed audio
                    '[1:a]showwaves=s=1920x%d:mode=%s:colors=0x%s@%.1f:rate=25[wave];' .
                    // Overlay wave on video
                    '[bg][wave]overlay=0:%d:format=auto[out]',
                $totalFrames,
                $scaledWaveHeight,
                $waveType,
                $waveColor,
                $waveOpacity,
                $waveY
            );

            $videoCmd = sprintf(
                '%s -y -loop 1 -i %s -i %s -filter_complex "%s" -map "[out]" -map 1:a ' .
                    '-c:v libx264 -preset medium -tune stillimage -crf 23 -c:a aac -b:a 192k ' .
                    '-pix_fmt yuv420p -t %s -movflags +faststart %s 2>&1',
                $ffmpeg,
                escapeshellarg($imagePath),
                escapeshellarg($mixedAudioPath),
                $videoFilterComplex,
                round($totalDuration, 2),
                escapeshellarg($outputPath)
            );

            Log::info("FFmpeg video command", ['cmd' => $videoCmd]);
            exec($videoCmd, $videoOutput, $videoReturnCode);

            // Cleanup temp
            $this->cleanupDirectory($tempDir);

            if ($videoReturnCode !== 0 || !file_exists($outputPath)) {
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
            Log::error("Generate description video failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
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

    /**
     * Generate thumbnail using Gemini AI
     */
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
            'text_styling' => 'nullable|array'
        ]);

        $style = $request->input('style', 'cinematic');
        $customPrompt = $request->input('custom_prompt');
        $chapterNumber = $request->input('chapter_number');
        $withText = $request->input('with_text', true); // Default to generating with text
        $aiResearch = $request->input('ai_research', false); // AI research option
        $useCoverImage = $request->input('use_cover_image', false); // Use cover image option
        $customTitle = $request->input('custom_title');
        $customAuthor = $request->input('custom_author');
        $textStyling = $request->input('text_styling', []); // Text styling options

        try {
            $bookInfo = [
                'book_id' => $audioBook->id,
                'title' => $customTitle ?: $audioBook->title,
                'author' => $customAuthor ?: ($audioBook->author ? 'Tác giả: ' . $audioBook->author : ''),
                'category' => $audioBook->category,
                'book_type' => null, // Removed book_type from thumbnail
                'description' => $audioBook->description,
                'channel_name' => '', // Removed channel name from thumbnail
                'cover_image' => $audioBook->cover_image,
                'text_styling' => $textStyling // Pass text styling options
            ];

            // If using cover image, create thumbnail from cover with text overlay
            if ($useCoverImage && $audioBook->cover_image) {
                $result = $this->imageService->createThumbnailFromCover($bookInfo, $chapterNumber);

                if ($result['success']) {
                    Log::info("Generated thumbnail from cover for audiobook {$audioBook->id}", [
                        'path' => $result['path'] ?? null
                    ]);
                }

                return response()->json($result);
            }

            // If AI research is enabled, let AI research and create enhanced prompt
            if ($aiResearch) {
                $researchResult = $this->imageService->researchAndCreatePrompt($bookInfo);
                if ($researchResult['success']) {
                    $customPrompt = $researchResult['prompt'];
                }
            }

            // Pass custom prompt as additional context to enhance the thumbnail
            if ($withText) {
                // Generate thumbnail with text overlay (title, author, chapter)
                $result = $this->imageService->generateThumbnailWithText($bookInfo, $style, $chapterNumber, $customPrompt);
            } else {
                // Generate thumbnail without text (scene only)
                $result = $this->imageService->generateThumbnail($bookInfo, $style, $chapterNumber);
            }

            if ($result['success']) {
                Log::info("Generated thumbnail for audiobook {$audioBook->id}", [
                    'style' => $style,
                    'with_text' => $withText,
                    'path' => $result['path'] ?? null
                ]);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error("Generate thumbnail failed for audiobook {$audioBook->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
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
            'chapter_ids' => 'required|array|min:1',
            'chapter_ids.*' => 'integer|exists:audiobook_chapters,id',
            'text_options' => 'nullable|array',
            'text_options.font_size' => 'nullable|integer|min:40|max:150',
            'text_options.text_color' => 'nullable|string',
            'text_options.outline_color' => 'nullable|string',
            'text_options.outline_width' => 'nullable|integer|min:2|max:8',
            'text_options.position_x' => 'nullable|numeric|min:0|max:100',
            'text_options.position_y' => 'nullable|numeric|min:0|max:100',
        ]);

        $imageFilename = $request->input('image_filename');
        $chapterIds = $request->input('chapter_ids');
        $textOptions = $request->input('text_options', []);

        // Default text options
        $fontSize = $textOptions['font_size'] ?? 80;
        $textColor = $textOptions['text_color'] ?? '#FFFFFF';
        $outlineColor = $textOptions['outline_color'] ?? '#000000';
        $outlineWidth = $textOptions['outline_width'] ?? 4;
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
        $chapters = AudioBookChapter::whereIn('id', $chapterIds)
            ->where('audio_book_id', $audioBook->id)
            ->get();

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
                $chapterText = "Chương " . $chapter->chapter_number;

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

        $successCount = count(array_filter($results, fn($r) => $r['success']));

        return response()->json([
            'success' => true,
            'message' => "Đã tạo {$successCount}/" . count($results) . " ảnh bìa chương",
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
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
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
            $baseFilter = "scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2";

            if ($waveEnabled) {
                $rawWaveType = $audioBook->wave_type ?? 'cline';
                // Map to valid FFmpeg showwaves modes (bar is not supported)
                $waveTypeMap = ['point' => 'point', 'line' => 'line', 'p2p' => 'p2p', 'cline' => 'cline', 'bar' => 'line'];
                $waveType = $waveTypeMap[$rawWaveType] ?? 'cline';
                $wavePosition = $audioBook->wave_position ?? 'bottom';
                $waveHeight = $audioBook->wave_height ?? 100;
                $waveColor = ltrim($audioBook->wave_color ?? '#00ff00', '#');
                $waveOpacity = $audioBook->wave_opacity ?? 0.8;

                // Calculate Y position for wave overlay
                // Video is 720p, so positions are:
                // top: y=20
                // center: y=(720-waveHeight)/2
                // bottom: y=720-waveHeight-20
                switch ($wavePosition) {
                    case 'top':
                        $waveY = 20;
                        break;
                    case 'center':
                        $waveY = (720 - $waveHeight) / 2;
                        break;
                    case 'bottom':
                    default:
                        $waveY = 720 - $waveHeight - 20;
                        break;
                }

                // Build FFmpeg filter_complex for wave overlay
                // [1:a] = audio input for showwaves
                // showwaves: generate waveform visualization
                // mode: wave display mode (line, p2p, cline, point)
                // colors: wave color in hex
                // s: size of wave output
                $filterComplex = sprintf(
                    '[0:v]%s[bg];[1:a]showwaves=s=1280x%d:mode=%s:colors=0x%s@%.1f:rate=30[wave];[bg][wave]overlay=0:%d:format=auto[out]',
                    $baseFilter,
                    $waveHeight,
                    $waveType,
                    $waveColor,
                    $waveOpacity,
                    $waveY
                );

                // FFmpeg command with wave overlay
                $command = sprintf(
                    '%s -y -loop 1 -framerate 30 -i %s -i %s -filter_complex "%s" -map "[out]" -map 1:a -c:v libx264 -preset ultrafast -tune stillimage -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -shortest %s 2>&1',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($imagePath),
                    escapeshellarg($audioPath),
                    $filterComplex,
                    escapeshellarg($videoPath)
                );
            } else {
                // Original command without wave (optimized for speed)
                $command = sprintf(
                    '%s -y -loop 1 -framerate 1 -i %s -i %s -c:v libx264 -preset ultrafast -tune stillimage -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -r 30 -shortest -vf "%s" %s 2>&1',
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
        foreach ($audioBook->chapters as $chapter) {
            if ($chapter->video_path) {
                $path = storage_path('app/public/' . $chapter->video_path);
                if (file_exists($path)) {
                    $videos[] = [
                        'id' => 'chapter_' . $chapter->id,
                        'type' => 'chapter',
                        'label' => 'Chương ' . $chapter->chapter_number . ': ' . $chapter->title,
                        'path' => $chapter->video_path,
                        'duration' => $chapter->total_duration,
                    ];
                }
            }
        }

        // Thumbnails from media gallery
        $media = $this->imageService->getExistingMedia($audioBook->id);
        $thumbnails = $media['thumbnails'] ?? [];

        return response()->json([
            'success' => true,
            'videos' => $videos,
            'thumbnails' => $thumbnails,
        ]);
    }

    /**
     * Generate YouTube video title or description using AI (Gemini).
     */
    public function generateVideoMeta(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'type' => 'required|string|in:title,description',
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

                return response()->json(['success' => true, 'title' => $title, 'tags' => $tags]);
            } else {
                $prompt = "Bạn là chuyên gia YouTube SEO. Hãy viết mô tả YouTube chuyên nghiệp cho video audiobook/sách nói sau:\n\n";
                $prompt .= "Tên sách: {$audioBook->title}\n";
                if ($audioBook->author) $prompt .= "Tác giả: {$audioBook->author}\n";
                if ($audioBook->category) $prompt .= "Thể loại: {$audioBook->category}\n";
                if ($channelName) $prompt .= "Kênh: {$channelName}\n";
                if ($audioBook->description) $prompt .= "\nMô tả gốc (tham khảo):\n" . mb_substr($audioBook->description, 0, 500) . "\n";
                $prompt .= "\nYêu cầu:\n";
                $prompt .= "- Viết bằng tiếng Việt, tối đa 300 từ\n";
                $prompt .= "- Bao gồm: giới thiệu ngắn sách, lý do nên nghe, CTA (đăng ký, like, bình luận)\n";
                $prompt .= "- Thêm timestamps giả (00:00 Giới thiệu, ...)\n";
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

                return response()->json(['success' => true, 'description' => $description]);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Lỗi AI: ' . $e->getMessage()], 500);
        }
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
        $prompt .= "- Mỗi video cần 1 tiêu đề riêng (tối đa 100 ký tự) và 1 mô tả ngắn (2-3 câu)\n";
        $prompt .= "- Tiêu đề phải bao gồm tên sách + số chương/phần\n";
        $prompt .= "- Mô tả ngắn gọn, hấp dẫn, có CTA\n";
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
                    'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 2000]
                ],
                'timeout' => 60
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $text = trim($result['candidates'][0]['content']['parts'][0]['text'] ?? '');

            // Extract JSON from response (may be wrapped in ```json ... ```)
            if (preg_match('/\[.*\]/s', $text, $matches)) {
                $items = json_decode($matches[0], true);
            } else {
                $items = json_decode($text, true);
            }

            if (!is_array($items)) {
                throw new \Exception('AI không trả về JSON hợp lệ');
            }

            // Map back to chapters
            $mapped = [];
            foreach ($chapters as $i => $chapter) {
                $mapped[] = [
                    'id' => $chapter['id'],
                    'source_label' => $chapter['label'],
                    'title' => $items[$i]['title'] ?? "{$mainTitle} - Phần " . ($i + 1),
                    'description' => $items[$i]['description'] ?? $mainDesc,
                ];
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
            'video_type' => 'required|string|in:description,chapter',
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
        $description = $request->input('description', '');
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
            if ($thumbnailPath) {
                $fullThumbPath = storage_path('app/public/' . $thumbnailPath);
                if (file_exists($fullThumbPath)) {
                    $this->youtubeSetThumbnail($accessToken, $videoId, $fullThumbPath);
                }
            }

            $videoUrl = "https://www.youtube.com/watch?v={$videoId}";
            if ($isShorts) {
                $videoUrl = "https://www.youtube.com/shorts/{$videoId}";
            }

            return response()->json([
                'success' => true,
                'video_id' => $videoId,
                'video_url' => $videoUrl,
            ]);
        } catch (\Exception $e) {
            Log::error('YouTube upload failed', ['error' => $e->getMessage(), 'audiobook' => $audioBook->id]);
            return response()->json(['success' => false, 'error' => 'Upload thất bại: ' . $e->getMessage()], 500);
        }
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
            'items.*.video_type' => 'required|string|in:description,chapter',
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
                $request->input('playlist_name'),
                $request->input('playlist_description', ''),
                $privacy
            );

            // Step 2: Upload each video and add to playlist
            $uploadedVideos = [];
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

                // Set thumbnail
                if ($fullThumbPath && file_exists($fullThumbPath)) {
                    $this->youtubeSetThumbnail($accessToken, $videoId, $fullThumbPath);
                }

                // Add to playlist
                $this->youtubeAddToPlaylist($accessToken, $playlistId, $videoId);

                $uploadedVideos[] = [
                    'title' => $item['title'],
                    'video_id' => $videoId,
                    'url' => "https://www.youtube.com/watch?v={$videoId}",
                ];
            }

            return response()->json([
                'success' => true,
                'playlist_id' => $playlistId,
                'playlist_url' => "https://www.youtube.com/playlist?list={$playlistId}",
                'uploaded_videos' => $uploadedVideos,
            ]);
        } catch (\Exception $e) {
            Log::error('YouTube playlist creation failed', ['error' => $e->getMessage(), 'audiobook' => $audioBook->id]);
            return response()->json(['success' => false, 'error' => 'Lỗi tạo playlist: ' . $e->getMessage()], 500);
        }
    }

    // ---- YouTube API Helper Methods ----

    /**
     * Resolve the absolute file path for a video source.
     */
    private function resolveVideoPath(AudioBook $audioBook, string $videoId, string $videoType): ?string
    {
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

        return null;
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
     * Set thumbnail for a YouTube video.
     */
    private function youtubeSetThumbnail(string $accessToken, string $videoId, string $thumbnailPath): void
    {
        try {
            $client = new \GuzzleHttp\Client();
            $mimeType = mime_content_type($thumbnailPath) ?: 'image/jpeg';

            $client->post("https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId={$videoId}", [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => $mimeType,
                ],
                'body' => fopen($thumbnailPath, 'r'),
                'timeout' => 30,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to set YouTube thumbnail', ['videoId' => $videoId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Create a YouTube playlist.
     */
    private function youtubeCreatePlaylist(string $accessToken, string $title, string $description, string $privacy): string
    {
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
}
