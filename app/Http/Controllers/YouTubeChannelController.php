<?php

namespace App\Http\Controllers;

use App\Models\AudioBook;
use App\Models\DubSyncProject;
use App\Models\YoutubeChannel;
use App\Models\YoutubeChannelReference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeChannelController extends Controller
{
    public function index()
    {
        $page = request()->get('page', 1);
        $perPage = request()->get('per_page', 20);

        // Validate per_page to prevent abuse
        $allowedPerPage = [10, 20, 25, 50, 100];
        if (!in_array($perPage, $allowedPerPage)) {
            $perPage = 20;
        }

        $channels = YoutubeChannel::withCount([
                'audioBooks as total_videos' => function ($q) {
                    $q->whereHas('chapters', fn($c) => $c->whereNotNull('video_path'));
                },
                'audioBooks as published_videos' => function ($q) {
                    $q->whereHas('chapters', fn($c) => $c->whereNotNull('youtube_video_id'));
                },
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        // Calculate durations per channel
        foreach ($channels as $channel) {
            $chapters = \App\Models\AudioBookChapter::whereIn(
                'audio_book_id', $channel->audioBooks()->pluck('id')
            );
            $channel->total_duration_seconds = (clone $chapters)->sum('total_duration');
            $channel->published_duration_seconds = (clone $chapters)
                ->whereNotNull('youtube_video_id')
                ->sum('total_duration');
        }

        return view('youtube_channels.index', compact('channels'));
    }

    public function create()
    {
        return view('youtube_channels.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'channel_id' => 'required|string|max:255|unique:youtube_channels,channel_id',
            'content_type' => 'required|in:audiobook,dub,self_creative',
            'title' => 'required|string|max:255',
            'custom_url' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'country' => 'nullable|string|size:2',
            'published_at' => 'nullable|date',
            'thumbnail_url' => 'nullable|string|max:2048',
            'thumbnail_file' => 'nullable|image|max:2048',
            'subscribers_count' => 'nullable|integer|min:0',
            'videos_count' => 'nullable|integer|min:0',
            'views_count' => 'nullable|integer|min:0',
            'ref_channels_json' => 'nullable|string',
        ]);

        if ($request->hasFile('thumbnail_file')) {
            $validated['thumbnail_url'] = $request->file('thumbnail_file')
                ->store('youtube_channels', 'public');
        }

        $channel = YoutubeChannel::create($validated);

        $this->syncReferenceChannels($channel, $request->input('ref_channels_json'));

        return redirect()->route('youtube-channels.index')->with('success', 'Channel created successfully.');
    }

    public function show(YoutubeChannel $youtubeChannel)
    {
        $search = request()->get('search', '');
        $status = request()->get('status', '');
        $page = request()->get('page', 1);
        $perPage = request()->get('per_page', 10);

        // Validate per_page to prevent abuse
        $allowedPerPage = [10, 20, 50, 100];
        if (!in_array($perPage, $allowedPerPage)) {
            $perPage = 10;
        }

        $query = DubSyncProject::where('youtube_channel_id', $youtubeChannel->id)
            ->where('user_id', auth()->id());

        // Filter by search (name/title)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('youtube_title', 'like', "%$search%")
                    ->orWhere('youtube_title_vi', 'like', "%$search%")
                    ->orWhere('video_id', 'like', "%$search%");
            });
        }

        // Filter by status
        if ($status) {
            $query->where('status', $status);
        }

        $projects = $query->orderByDesc('created_at')->paginate($perPage);

        $referenceChannels = $youtubeChannel->referenceChannels()
            ->orderByDesc('created_at')
            ->get();

        $audioBooks = AudioBook::where('youtube_channel_id', $youtubeChannel->id)
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('youtube_channels.show', compact('youtubeChannel', 'projects', 'referenceChannels', 'audioBooks', 'search', 'status'));
    }

    public function edit(YoutubeChannel $youtubeChannel)
    {
        return view('youtube_channels.edit', compact('youtubeChannel'));
    }

    public function update(Request $request, YoutubeChannel $youtubeChannel)
    {
        $validated = $request->validate([
            'channel_id' => 'required|string|max:255|unique:youtube_channels,channel_id,' . $youtubeChannel->id,
            'content_type' => 'required|in:audiobook,dub,self_creative',
            'title' => 'required|string|max:255',
            'custom_url' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'country' => 'nullable|string|size:2',
            'published_at' => 'nullable|date',
            'thumbnail_url' => 'nullable|string|max:2048',
            'thumbnail_file' => 'nullable|image|max:2048',
            'subscribers_count' => 'nullable|integer|min:0',
            'videos_count' => 'nullable|integer|min:0',
            'views_count' => 'nullable|integer|min:0',
            'ref_channels_json' => 'nullable|string',
        ]);

        // Prevent changing content_type once it's set
        if ($youtubeChannel->content_type && $validated['content_type'] !== $youtubeChannel->content_type) {
            return back()->withErrors([
                'content_type' => 'Không thể thay đổi loại nội dung sau khi đã được thiết lập.'
            ])->withInput();
        }

        if ($request->hasFile('thumbnail_file')) {
            $validated['thumbnail_url'] = $request->file('thumbnail_file')
                ->store('youtube_channels', 'public');
        }

        $youtubeChannel->update($validated);

        $this->syncReferenceChannels($youtubeChannel, $request->input('ref_channels_json'));

        return redirect()->route('youtube-channels.edit', $youtubeChannel)->with('success', 'Cập nhật kênh thành công!');
    }

    public function destroy(YoutubeChannel $youtubeChannel)
    {
        $youtubeChannel->delete();

        return redirect()->route('youtube-channels.index')->with('success', 'Channel deleted successfully.');
    }

    public function storeReference(Request $request, YoutubeChannel $youtubeChannel)
    {
        // Only allow adding reference channels for dub channels
        if ($youtubeChannel->content_type !== 'dub') {
            return response()->json([
                'success' => false,
                'error' => 'Reference channels chỉ áp dụng cho kênh Dub (Lồng tiếng).'
            ], 400);
        }

        $validated = $request->validate([
            'ref_channel_url' => 'required|url|max:2048',
            'ref_channel_id' => 'nullable|string|max:255',
            'ref_title' => 'nullable|string|max:255',
            'ref_description' => 'nullable|string',
            'ref_thumbnail_url' => 'nullable|string|max:2048',
            'fetch_interval_days' => 'nullable|integer|min:1',
        ]);

        $reference = $youtubeChannel->referenceChannels()->create([
            'ref_channel_url' => $validated['ref_channel_url'],
            'ref_channel_id' => $validated['ref_channel_id'] ?? null,
            'ref_title' => $validated['ref_title'] ?? null,
            'ref_description' => $validated['ref_description'] ?? null,
            'ref_thumbnail_url' => $validated['ref_thumbnail_url'] ?? null,
            'fetch_interval_days' => $validated['fetch_interval_days'] ?? 7,
        ]);

        return response()->json([
            'success' => true,
            'id' => $reference->id,
        ]);
    }

    public function destroyReference(YoutubeChannel $youtubeChannel, YoutubeChannelReference $reference)
    {
        if ($reference->youtube_channel_id !== $youtubeChannel->id) {
            return response()->json([
                'success' => false,
                'error' => 'Reference not found for this channel.'
            ], 404);
        }

        $reference->delete();

        return response()->json(['success' => true]);
    }

    public function bulkDestroyProjects(Request $request, YoutubeChannel $youtubeChannel)
    {
        $validated = $request->validate([
            'project_ids' => 'required|array',
            'project_ids.*' => 'integer|exists:dub_sync_projects,id',
        ]);

        $deleted = DubSyncProject::whereIn('id', $validated['project_ids'])
            ->where('youtube_channel_id', $youtubeChannel->id)
            ->where('user_id', auth()->id())
            ->delete();

        return redirect()->route('youtube-channels.show', $youtubeChannel)
            ->with('success', "Deleted {$deleted} project(s) successfully.");
    }

    public function fetchReferenceVideos(Request $request, YoutubeChannel $youtubeChannel)
    {
        // Only allow fetching for dub channels
        if ($youtubeChannel->content_type !== 'dub') {
            return redirect()->route('youtube-channels.show', $youtubeChannel)
                ->with('error', 'Chức năng Fetch video chỉ áp dụng cho kênh Dub (Lồng tiếng).');
        }

        $apiKey = config('services.youtube.api_key');
        if (!$apiKey) {
            return redirect()->route('youtube-channels.show', $youtubeChannel)
                ->with('success', 'Thiếu YOUTUBE_API_KEY trong .env');
        }

        $references = $youtubeChannel->referenceChannels()->get();
        if ($references->isEmpty()) {
            return redirect()->route('youtube-channels.show', $youtubeChannel)
                ->with('success', 'Không có reference channels để quét.');
        }

        $videos = collect();

        foreach ($references as $ref) {
            $channelInfo = $this->resolveChannelFromUrl($ref->ref_channel_url, $apiKey, $ref->ref_channel_id);
            if (!$channelInfo || empty($channelInfo['uploads_playlist_id'])) {
                continue;
            }

            $playlistResponse = Http::get('https://www.googleapis.com/youtube/v3/playlistItems', [
                'part' => 'snippet,contentDetails',
                'playlistId' => $channelInfo['uploads_playlist_id'],
                'maxResults' => 20,
                'key' => $apiKey,
            ]);

            if (!$playlistResponse->ok()) {
                continue;
            }

            $items = collect($playlistResponse->json('items', []))
                ->map(function ($item) {
                    $videoId = data_get($item, 'contentDetails.videoId');
                    $title = data_get($item, 'snippet.title');
                    $thumbnail = data_get($item, 'snippet.thumbnails.medium.url')
                        ?? data_get($item, 'snippet.thumbnails.default.url');

                    return [
                        'video_id' => $videoId,
                        'youtube_url' => $videoId ? "https://www.youtube.com/watch?v={$videoId}" : null,
                        'youtube_title' => $title,
                        'youtube_thumbnail' => $thumbnail,
                    ];
                })
                ->filter(fn($video) => !empty($video['video_id']))
                ->values();

            $videos = $videos->merge($items);
        }

        $videos = $videos->unique('video_id')->values();
        if ($videos->isEmpty()) {
            return redirect()->route('youtube-channels.show', $youtubeChannel)
                ->with('success', 'Đã hoàn tất. Không có video mới.');
        }

        $existingIds = DubSyncProject::whereIn('video_id', $videos->pluck('video_id'))
            ->pluck('video_id')
            ->all();

        $newVideos = $videos->reject(fn($video) => in_array($video['video_id'], $existingIds, true));

        $created = 0;
        foreach ($newVideos as $video) {
            DubSyncProject::create([
                'user_id' => auth()->id(),
                'youtube_channel_id' => $youtubeChannel->id,
                'video_id' => $video['video_id'],
                'youtube_url' => $video['youtube_url'],
                'youtube_title' => $video['youtube_title'],
                'youtube_thumbnail' => $video['youtube_thumbnail'],
                'status' => 'new',
            ]);
            $created++;
        }

        return redirect()->route('youtube-channels.show', $youtubeChannel)
            ->with('success', "Đã hoàn tất. Đã thêm {$created} video mới.");
    }

    private function syncReferenceChannels(YoutubeChannel $channel, ?string $refsJson): void
    {
        // Only sync reference channels for dub channels
        if ($channel->content_type !== 'dub') {
            // Delete any existing references if channel type is not dub
            $channel->referenceChannels()->delete();
            return;
        }

        $channel->referenceChannels()->delete();

        if (!$refsJson) {
            return;
        }

        $refs = json_decode($refsJson, true);
        if (!is_array($refs)) {
            return;
        }

        $payload = collect($refs)
            ->filter(fn($ref) => is_array($ref) && !empty($ref['ref_channel_url']))
            ->map(function ($ref) {
                return [
                    'ref_channel_url' => $ref['ref_channel_url'],
                    'ref_channel_id' => $ref['ref_channel_id'] ?? null,
                    'ref_title' => $ref['ref_title'] ?? null,
                    'ref_description' => $ref['ref_description'] ?? null,
                    'ref_thumbnail_url' => $ref['ref_thumbnail_url'] ?? null,
                    'fetch_interval_days' => isset($ref['fetch_interval_days'])
                        ? (int) $ref['fetch_interval_days']
                        : 7,
                ];
            })
            ->values()
            ->all();

        if (!empty($payload)) {
            $channel->referenceChannels()->createMany($payload);
        }
    }

    private function resolveChannelFromUrl(string $channelUrl, string $apiKey, ?string $channelId = null): ?array
    {
        $handle = null;
        $username = null;

        if (!$channelId) {
            if (preg_match('/youtube\.com\/@([^\/?]+)/i', $channelUrl, $matches)) {
                $handle = $matches[1];
            } elseif (preg_match('/youtube\.com\/channel\/([^\/?]+)/i', $channelUrl, $matches)) {
                $channelId = $matches[1];
            } elseif (preg_match('/youtube\.com\/user\/([^\/?]+)/i', $channelUrl, $matches)) {
                $username = $matches[1];
            }
        }

        if (!$handle && !$channelId && !$username) {
            return null;
        }

        $params = [
            'part' => 'id,snippet,contentDetails',
            'key' => $apiKey,
        ];

        if ($handle) {
            $params['forHandle'] = $handle;
        } elseif ($channelId) {
            $params['id'] = $channelId;
        } else {
            $params['forUsername'] = $username;
        }

        $response = Http::get('https://www.googleapis.com/youtube/v3/channels', $params);
        if (!$response->ok()) {
            return null;
        }

        $items = $response->json('items', []);
        if (empty($items)) {
            return null;
        }

        $item = $items[0];
        $uploadsPlaylistId = data_get($item, 'contentDetails.relatedPlaylists.uploads');

        return [
            'uploads_playlist_id' => $uploadsPlaylistId,
            'channel_id' => data_get($item, 'id'),
        ];
    }

    // ========== SPEAKER (MC) MANAGEMENT ==========

    /**
     * Get all speakers for a channel.
     */
    public function getSpeakers(YoutubeChannel $youtubeChannel)
    {
        $speakers = $youtubeChannel->speakers()->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'speakers' => $speakers->map(function ($speaker) {
                return [
                    'id' => $speaker->id,
                    'name' => $speaker->name,
                    'avatar' => $speaker->avatar,
                    'avatar_url' => $speaker->avatar_url,
                    'description' => $speaker->description,
                    'gender' => $speaker->gender,
                    'voice_style' => $speaker->voice_style,
                    'default_voice_provider' => $speaker->default_voice_provider,
                    'default_voice_name' => $speaker->default_voice_name,
                    'is_active' => $speaker->is_active,
                    'lip_sync_enabled' => $speaker->lip_sync_enabled,
                    'lip_sync_settings' => $speaker->lip_sync_settings,
                    'additional_images' => $speaker->additional_images,
                    'additional_images_urls' => $speaker->additional_images_urls,
                    'audiobooks_count' => $speaker->audioBooks()->count(),
                ];
            }),
        ]);
    }

    /**
     * Store a new speaker.
     */
    public function storeSpeaker(Request $request, YoutubeChannel $youtubeChannel)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'avatar' => 'nullable|image|max:5120', // 5MB max
            'description' => 'nullable|string|max:1000',
            'gender' => 'required|in:male,female',
            'voice_style' => 'nullable|string|max:500',
            'default_voice_provider' => 'nullable|in:openai,gemini,microsoft,vbee',
            'default_voice_name' => 'nullable|string|max:255',
            'lip_sync_enabled' => 'nullable|boolean',
            'lip_sync_settings' => 'nullable|array',
            'additional_images.*' => 'nullable|image|max:5120',
        ]);

        $speakerData = [
            'youtube_channel_id' => $youtubeChannel->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'gender' => $validated['gender'],
            'voice_style' => $validated['voice_style'] ?? null,
            'default_voice_provider' => $validated['default_voice_provider'] ?? null,
            'default_voice_name' => $validated['default_voice_name'] ?? null,
            'is_active' => true,
            'lip_sync_enabled' => $validated['lip_sync_enabled'] ?? false,
            'lip_sync_settings' => $validated['lip_sync_settings'] ?? \App\Models\ChannelSpeaker::getDefaultLipSyncSettings(),
        ];

        // Upload avatar
        if ($request->hasFile('avatar')) {
            $speakerData['avatar'] = $request->file('avatar')
                ->store('speakers/' . $youtubeChannel->id, 'public');
        }

        // Upload additional images
        if ($request->hasFile('additional_images')) {
            $additionalImages = [];
            foreach ($request->file('additional_images') as $image) {
                $additionalImages[] = $image->store('speakers/' . $youtubeChannel->id . '/additional', 'public');
            }
            $speakerData['additional_images'] = $additionalImages;
        }

        $speaker = \App\Models\ChannelSpeaker::create($speakerData);

        return response()->json([
            'success' => true,
            'message' => 'Thêm MC thành công!',
            'speaker' => [
                'id' => $speaker->id,
                'name' => $speaker->name,
                'avatar_url' => $speaker->avatar_url,
                'gender' => $speaker->gender,
                'is_active' => $speaker->is_active,
            ],
        ]);
    }

    /**
     * Update a speaker.
     */
    public function updateSpeaker(Request $request, YoutubeChannel $youtubeChannel, $speakerId)
    {
        $speaker = \App\Models\ChannelSpeaker::where('youtube_channel_id', $youtubeChannel->id)
            ->findOrFail($speakerId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'avatar' => 'nullable|image|max:5120',
            'description' => 'nullable|string|max:1000',
            'gender' => 'required|in:male,female',
            'voice_style' => 'nullable|string|max:500',
            'default_voice_provider' => 'nullable|in:openai,gemini,microsoft,vbee',
            'default_voice_name' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'lip_sync_enabled' => 'nullable|boolean',
            'lip_sync_settings' => 'nullable|array',
            'additional_images.*' => 'nullable|image|max:5120',
        ]);

        $speaker->name = $validated['name'];
        $speaker->description = $validated['description'] ?? null;
        $speaker->gender = $validated['gender'];
        $speaker->voice_style = $validated['voice_style'] ?? null;
        $speaker->default_voice_provider = $validated['default_voice_provider'] ?? null;
        $speaker->default_voice_name = $validated['default_voice_name'] ?? null;
        $speaker->is_active = $validated['is_active'] ?? true;
        $speaker->lip_sync_enabled = $validated['lip_sync_enabled'] ?? false;

        if (isset($validated['lip_sync_settings'])) {
            $speaker->lip_sync_settings = $validated['lip_sync_settings'];
        }

        // Upload new avatar
        if ($request->hasFile('avatar')) {
            // Delete old avatar
            if ($speaker->avatar && \Illuminate\Support\Facades\Storage::disk('public')->exists($speaker->avatar)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($speaker->avatar);
            }
            $speaker->avatar = $request->file('avatar')
                ->store('speakers/' . $youtubeChannel->id, 'public');
        }

        // Upload additional images
        if ($request->hasFile('additional_images')) {
            $additionalImages = $speaker->additional_images ?? [];
            foreach ($request->file('additional_images') as $image) {
                $additionalImages[] = $image->store('speakers/' . $youtubeChannel->id . '/additional', 'public');
            }
            $speaker->additional_images = $additionalImages;
        }

        $speaker->save();

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật MC thành công!',
            'speaker' => [
                'id' => $speaker->id,
                'name' => $speaker->name,
                'avatar_url' => $speaker->avatar_url,
                'gender' => $speaker->gender,
                'is_active' => $speaker->is_active,
            ],
        ]);
    }

    /**
     * Delete a speaker.
     */
    public function deleteSpeaker(YoutubeChannel $youtubeChannel, $speakerId)
    {
        $speaker = \App\Models\ChannelSpeaker::where('youtube_channel_id', $youtubeChannel->id)
            ->findOrFail($speakerId);

        // Check if speaker is used by any audiobooks
        $usageCount = $speaker->audioBooks()->count();
        if ($usageCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "MC này đang được sử dụng bởi {$usageCount} audiobook. Vui lòng gỡ bỏ trước khi xóa.",
            ], 400);
        }

        // Delete avatar
        if ($speaker->avatar && \Illuminate\Support\Facades\Storage::disk('public')->exists($speaker->avatar)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($speaker->avatar);
        }

        // Delete additional images
        if ($speaker->additional_images) {
            foreach ($speaker->additional_images as $image) {
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($image)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($image);
                }
            }
        }

        $speaker->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa MC thành công!',
        ]);
    }

    /**
     * Delete an additional image from speaker.
     */
    public function deleteSpeakerImage(Request $request, YoutubeChannel $youtubeChannel, $speakerId)
    {
        $speaker = \App\Models\ChannelSpeaker::where('youtube_channel_id', $youtubeChannel->id)
            ->findOrFail($speakerId);

        $imagePath = $request->input('image_path');

        if (!$imagePath || !$speaker->additional_images) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy hình ảnh.'], 400);
        }

        $images = $speaker->additional_images;
        $key = array_search($imagePath, $images);

        if ($key !== false) {
            // Delete file
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($imagePath)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($imagePath);
            }

            // Remove from array
            unset($images[$key]);
            $speaker->additional_images = array_values($images);
            $speaker->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Xóa hình ảnh thành công!',
        ]);
    }

    /**
     * Toggle speaker active status.
     */
    public function toggleSpeakerStatus(YoutubeChannel $youtubeChannel, $speakerId)
    {
        $speaker = \App\Models\ChannelSpeaker::where('youtube_channel_id', $youtubeChannel->id)
            ->findOrFail($speakerId);

        $speaker->is_active = !$speaker->is_active;
        $speaker->save();

        return response()->json([
            'success' => true,
            'message' => $speaker->is_active ? 'Đã kích hoạt MC!' : 'Đã tạm ẩn MC!',
            'is_active' => $speaker->is_active,
        ]);
    }

    // ========== YOUTUBE OAUTH2 INTEGRATION ==========

    /**
     * Redirect to Google OAuth2 consent screen.
     */
    public function oauthRedirect(YoutubeChannel $youtubeChannel)
    {
        $clientId = config('services.youtube.client_id');
        if (!$clientId) {
            return back()->with('error', 'YouTube Client ID chưa được cấu hình. Vui lòng thêm YOUTUBE_CLIENT_ID vào file .env');
        }

        $redirectUri = url(config('services.youtube.redirect_uri'));

        // Store channel ID in session so callback knows which channel to update
        session(['youtube_oauth_channel_id' => $youtubeChannel->id]);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube',
                'https://www.googleapis.com/auth/youtube.force-ssl',
                'https://www.googleapis.com/auth/userinfo.email',
            ]),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $youtubeChannel->id,
        ]);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    }

    /**
     * Handle Google OAuth2 callback.
     */
    public function oauthCallback(Request $request)
    {
        $channelId = $request->input('state') ?? session('youtube_oauth_channel_id');

        if (!$channelId) {
            return redirect()->route('youtube-channels.index')
                ->with('error', 'Không tìm thấy kênh YouTube để kết nối.');
        }

        $channel = YoutubeChannel::findOrFail($channelId);

        if ($request->has('error')) {
            return redirect()->route('youtube-channels.edit', $channel)
                ->with('error', 'Bạn đã từ chối quyền truy cập YouTube: ' . $request->input('error'));
        }

        $code = $request->input('code');
        if (!$code) {
            return redirect()->route('youtube-channels.edit', $channel)
                ->with('error', 'Không nhận được mã xác thực từ Google.');
        }

        // Exchange authorization code for tokens
        $redirectUri = url(config('services.youtube.redirect_uri'));

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (!$response->ok()) {
            Log::error('YouTube OAuth token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return redirect()->route('youtube-channels.edit', $channel)
                ->with('error', 'Không thể lấy token từ Google. Vui lòng thử lại.');
        }

        $tokenData = $response->json();
        $accessToken = $tokenData['access_token'] ?? null;
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = $tokenData['expires_in'] ?? 3600;

        if (!$accessToken) {
            return redirect()->route('youtube-channels.edit', $channel)
                ->with('error', 'Token không hợp lệ từ Google.');
        }

        // Get user email
        $email = null;
        $userInfoResponse = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/oauth2/v2/userinfo');

        if ($userInfoResponse->ok()) {
            $email = $userInfoResponse->json('email');
        }

        // Verify YouTube channel access — check that the authenticated account owns this channel
        $ytResponse = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet,statistics',
                'mine' => 'true',
            ]);

        $connectedChannelId = null;
        if ($ytResponse->ok() && !empty($ytResponse->json('items'))) {
            $ytChannel = $ytResponse->json('items.0');
            $connectedChannelId = $ytChannel['id'] ?? null;

            // Optionally sync channel stats
            if ($connectedChannelId) {
                $stats = $ytChannel['statistics'] ?? [];
                $snippet = $ytChannel['snippet'] ?? [];

                $channel->update([
                    'subscribers_count' => $stats['subscriberCount'] ?? $channel->subscribers_count,
                    'videos_count' => $stats['videoCount'] ?? $channel->videos_count,
                    'views_count' => $stats['viewCount'] ?? $channel->views_count,
                    'description' => $snippet['description'] ?? $channel->description,
                ]);
            }
        }

        // Save OAuth tokens
        $channel->update([
            'youtube_access_token' => $accessToken,
            'youtube_refresh_token' => $refreshToken ?? $channel->youtube_refresh_token,
            'youtube_token_expires_at' => now()->addSeconds($expiresIn - 60),
            'youtube_connected' => true,
            'youtube_connected_email' => $email,
        ]);

        // Clear session
        session()->forget('youtube_oauth_channel_id');

        $message = 'Đã kết nối YouTube thành công!';
        if ($email) {
            $message .= ' (Tài khoản: ' . $email . ')';
        }

        return redirect()->route('youtube-channels.edit', $channel)
            ->with('success', $message);
    }

    /**
     * Disconnect YouTube OAuth (revoke tokens).
     */
    public function oauthDisconnect(YoutubeChannel $youtubeChannel)
    {
        // Try to revoke token at Google
        if ($youtubeChannel->youtube_access_token) {
            Http::post('https://oauth2.googleapis.com/revoke', [
                'token' => $youtubeChannel->youtube_access_token,
            ]);
        }

        $youtubeChannel->update([
            'youtube_access_token' => null,
            'youtube_refresh_token' => null,
            'youtube_token_expires_at' => null,
            'youtube_connected' => false,
            'youtube_connected_email' => null,
        ]);

        return back()->with('success', 'Đã ngắt kết nối YouTube.');
    }

    /**
     * Refresh the YouTube access token using the refresh token.
     */
    public static function refreshAccessToken(YoutubeChannel $channel): ?string
    {
        if (!$channel->youtube_refresh_token) {
            return null;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'refresh_token' => $channel->youtube_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->ok()) {
            Log::error('YouTube token refresh failed', [
                'channel_id' => $channel->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $tokenData = $response->json();
        $accessToken = $tokenData['access_token'] ?? null;
        $expiresIn = $tokenData['expires_in'] ?? 3600;

        if ($accessToken) {
            $channel->update([
                'youtube_access_token' => $accessToken,
                'youtube_token_expires_at' => now()->addSeconds($expiresIn - 60),
            ]);
        }

        return $accessToken;
    }

    /**
     * Get a valid access token — refreshes if expired.
     */
    public static function getValidAccessToken(YoutubeChannel $channel): ?string
    {
        if (!$channel->isYoutubeConnected()) {
            return null;
        }

        if ($channel->isTokenExpired()) {
            return self::refreshAccessToken($channel);
        }

        return $channel->youtube_access_token;
    }

    /**
     * Check YouTube connection status (AJAX).
     */
    public function oauthStatus(YoutubeChannel $youtubeChannel)
    {
        $connected = $youtubeChannel->isYoutubeConnected();
        $tokenExpired = $connected ? $youtubeChannel->isTokenExpired() : false;

        // If connected but expired, try to refresh
        if ($connected && $tokenExpired) {
            $newToken = self::refreshAccessToken($youtubeChannel);
            $tokenExpired = !$newToken;
            if ($tokenExpired) {
                // Token refresh failed — mark as disconnected
                $youtubeChannel->update(['youtube_connected' => false]);
                $connected = false;
            }
        }

        return response()->json([
            'success' => true,
            'connected' => $connected,
            'email' => $youtubeChannel->youtube_connected_email,
            'token_expires_at' => $youtubeChannel->youtube_token_expires_at?->toISOString(),
        ]);
    }
}
