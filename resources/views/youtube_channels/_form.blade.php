@php
    $channel = $channel ?? null;
    $existingRefs =
        $channel?->referenceChannels
            ?->map(function ($ref) {
                return [
                    'id' => $ref->id,
                    'ref_channel_url' => $ref->ref_channel_url,
                    'ref_channel_id' => $ref->ref_channel_id,
                    'ref_title' => $ref->ref_title,
                    'ref_description' => $ref->ref_description,
                    'ref_thumbnail_url' => $ref->ref_thumbnail_url,
                    'fetch_interval_days' => $ref->fetch_interval_days ?? 7,
                ];
            })
            ->values()
            ->all() ?? [];
@endphp

{{-- YouTube API Connection Section --}}
@if ($channel)
    <div class="mb-8 border border-gray-200 rounded-xl overflow-hidden">
        <div class="bg-gradient-to-r from-red-50 to-red-100 px-6 py-4 flex items-center gap-3 border-b border-gray-200">
            <svg class="w-6 h-6 text-red-600" viewBox="0 0 24 24" fill="currentColor">
                <path
                    d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
            </svg>
            <h3 class="text-lg font-semibold text-gray-900">Kết nối YouTube API</h3>
        </div>
        <div class="px-6 py-5 bg-white">
            @if ($channel->isYoutubeConnected())
                {{-- Connected state --}}
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-green-700">Đã kết nối YouTube API</p>
                            @if ($channel->youtube_connected_email)
                                <p class="text-xs text-gray-500">Tài khoản: {{ $channel->youtube_connected_email }}</p>
                            @endif
                            @if ($channel->youtube_token_expires_at)
                                <p class="text-xs text-gray-400">
                                    Token hết hạn: {{ $channel->youtube_token_expires_at->format('d/m/Y H:i') }}
                                    @if ($channel->isTokenExpired())
                                        <span class="text-orange-500 font-medium">(Đã hết hạn - sẽ tự refresh)</span>
                                    @endif
                                </p>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('youtube-channels.oauth.connect', $channel) }}"
                            class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-50 hover:bg-blue-100 text-blue-700 text-sm font-medium rounded-lg transition">
                            🔄 Kết nối lại
                        </a>
                        <button type="button" onclick="disconnectYoutubeOAuth()"
                            class="inline-flex items-center gap-1.5 px-4 py-2 bg-red-50 hover:bg-red-100 text-red-700 text-sm font-medium rounded-lg transition">
                            ✖ Ngắt kết nối
                        </button>
                    </div>
                </div>

                {{-- Capabilities info --}}
                <div class="mt-4 p-3 bg-green-50 rounded-lg border border-green-100">
                    <p class="text-xs font-medium text-green-800 mb-1.5">✅ Các chức năng khả dụng:</p>
                    <ul class="text-xs text-green-700 space-y-0.5">
                        <li>• Tự động upload audiobook lên YouTube</li>
                        <li>• Quản lý video (tiêu đề, mô tả, thumbnail)</li>
                        <li>• Cập nhật thống kê kênh tự động</li>
                    </ul>
                </div>
            @else
                {{-- Not connected state --}}
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-700">Chưa kết nối YouTube API</p>
                            <p class="text-xs text-gray-500">Kết nối để tự động upload audiobook lên kênh YouTube</p>
                        </div>
                    </div>
                    <a href="{{ route('youtube-channels.oauth.connect', $channel) }}"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
                        </svg>
                        Kết nối YouTube
                    </a>
                </div>

                <div class="mt-4 p-3 bg-amber-50 rounded-lg border border-amber-100">
                    <p class="text-xs font-medium text-amber-800 mb-1.5">⚙️ Yêu cầu cấu hình:</p>
                    <ul class="text-xs text-amber-700 space-y-0.5">
                        <li>1. Tạo project trên <a href="https://console.cloud.google.com/" target="_blank"
                                class="underline font-medium">Google Cloud Console</a></li>
                        <li>2. Bật <strong>YouTube Data API v3</strong></li>
                        <li>3. Tạo OAuth 2.0 Credentials (Web application)</li>
                        <li>4. Thêm Redirect URI: <code
                                class="bg-amber-100 px-1 py-0.5 rounded text-xs">{{ url('/youtube-channels/oauth/callback') }}</code>
                        </li>
                        <li>5. Thêm vào file <code class="bg-amber-100 px-1 py-0.5 rounded text-xs">.env</code>:
                            <code
                                class="block mt-1 bg-amber-100 px-2 py-1 rounded text-xs">YOUTUBE_CLIENT_ID=your_client_id<br>YOUTUBE_CLIENT_SECRET=your_client_secret</code>
                        </li>
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endif

{{-- Content Type Selection --}}
<div class="mb-6 border border-gray-200 rounded-xl overflow-hidden">
    <div class="bg-gradient-to-r from-blue-50 to-blue-100 px-6 py-4 flex items-center justify-between border-b border-gray-200">
        <div class="flex items-center gap-3">
            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
            </svg>
            <h3 class="text-lg font-semibold text-gray-900">Loại Nội Dung Kênh</h3>
        </div>
        @if($channel)
            <span class="text-xs text-gray-500">
                Current DB value: {{ $channel->content_type ?? 'NULL' }}
            </span>
        @endif
    </div>
    <div class="px-6 py-5 bg-white">
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Content Type *
            @if($channel && $channel->content_type)
                <span class="text-xs text-amber-600">(Không thể thay đổi sau khi đã chọn)</span>
            @endif
        </label>

        @if($channel && $channel->content_type)
            {{-- Show current type as read-only --}}
            <div class="flex items-center gap-3 p-4 bg-gray-50 rounded-lg border-2 border-gray-300">
                @if($channel->content_type === 'audiobook')
                    <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-gray-900">Audiobook</div>
                        <div class="text-xs text-gray-500">Kênh chuyên về sách nói</div>
                    </div>
                @elseif($channel->content_type === 'dub')
                    <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-gray-900">Dub (Lồng tiếng)</div>
                        <div class="text-xs text-gray-500">Kênh lồng tiếng video</div>
                    </div>
                @elseif($channel->content_type === 'self_creative')
                    <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-gray-900">Self Creative</div>
                        <div class="text-xs text-gray-500">Kênh sáng tạo nội dung riêng</div>
                    </div>
                @endif
            </div>
            <input type="hidden" name="content_type" value="{{ $channel->content_type }}">
        @else
            {{-- Allow selection for new channel --}}
            <div class="space-y-3">
                <label class="relative flex items-center p-4 cursor-pointer border-2 rounded-lg transition-all hover:bg-gray-50 border-gray-300 has-[:checked]:border-green-500 has-[:checked]:bg-green-50">
                    <input type="radio" name="content_type" value="audiobook"
                           {{ old('content_type', $channel?->content_type) === 'audiobook' ? 'checked' : '' }}
                           class="w-5 h-5 text-green-600 focus:ring-green-500" required>
                    <div class="ml-3 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-900">Audiobook</div>
                            <div class="text-xs text-gray-500">Kênh chuyên về sách nói</div>
                        </div>
                    </div>
                </label>

                <label class="relative flex items-center p-4 cursor-pointer border-2 rounded-lg transition-all hover:bg-gray-50 border-gray-300 has-[:checked]:border-purple-500 has-[:checked]:bg-purple-50">
                    <input type="radio" name="content_type" value="dub"
                           {{ old('content_type', $channel?->content_type) === 'dub' ? 'checked' : '' }}
                           class="w-5 h-5 text-purple-600 focus:ring-purple-500" required>
                    <div class="ml-3 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center">
                            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-900">Dub (Lồng tiếng)</div>
                            <div class="text-xs text-gray-500">Kênh lồng tiếng video</div>
                        </div>
                    </div>
                </label>

                <label class="relative flex items-center p-4 cursor-pointer border-2 rounded-lg transition-all hover:bg-gray-50 border-gray-300 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50">
                    <input type="radio" name="content_type" value="self_creative"
                           {{ old('content_type', $channel?->content_type) === 'self_creative' ? 'checked' : '' }}
                           class="w-5 h-5 text-orange-600 focus:ring-orange-500" required>
                    <div class="ml-3 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                            <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-900">Self Creative</div>
                            <div class="text-xs text-gray-500">Kênh sáng tạo nội dung riêng</div>
                        </div>
                    </div>
                </label>
            </div>
            @error('content_type')
                <p class="text-sm text-red-600 mt-2">{{ $message }}</p>
            @enderror
        @endif

        <div class="mt-4 p-3 bg-amber-50 rounded-lg border border-amber-100">
            <p class="text-xs font-medium text-amber-800 mb-1">⚠️ Lưu ý quan trọng:</p>
            <ul class="text-xs text-amber-700 space-y-0.5">
                <li>• Mỗi kênh chỉ được chọn <strong>một loại nội dung duy nhất</strong></li>
                <li>• Sau khi chọn, loại nội dung <strong>không thể thay đổi</strong></li>
                <li>• Tất cả video trong kênh phải thuộc loại đã chọn</li>
            </ul>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Channel ID *</label>
        <input type="text" name="channel_id" value="{{ old('channel_id', $channel?->channel_id) }}"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"
            required>
        @error('channel_id')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Title *</label>
        <input type="text" name="title" value="{{ old('title', $channel?->title) }}"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"
            required>
        @error('title')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Custom URL</label>
        <input type="text" name="custom_url" value="{{ old('custom_url', $channel?->custom_url) }}"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
        @error('custom_url')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Country (2-letter)</label>
        <input type="text" name="country" maxlength="2" value="{{ old('country', $channel?->country) }}"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
        @error('country')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Published At</label>
        <input type="date" name="published_at"
            value="{{ old('published_at', $channel?->published_at?->format('Y-m-d')) }}"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
        @error('published_at')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Thumbnail URL</label>
        <input type="text" name="thumbnail_url" value="{{ old('thumbnail_url', $channel?->thumbnail_url) }}"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"
            placeholder="https://...">
        @error('thumbnail_url')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
        <p class="text-xs text-gray-500 mt-1">Có thể nhập URL hoặc chọn file bên dưới.</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Thumbnail File</label>
        <input type="file" name="thumbnail_file" accept="image/*"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
        @error('thumbnail_file')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
        @if ($channel?->thumbnail_url)
            <div class="mt-3">
                <div class="text-xs text-gray-500 mb-2">Current thumbnail:</div>
                <img src="{{ str_starts_with($channel->thumbnail_url, 'http') ? $channel->thumbnail_url : asset('storage/' . $channel->thumbnail_url) }}"
                    alt="Thumbnail" class="w-32 h-20 object-cover rounded border">
            </div>
        @endif
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Subscribers Count</label>
        <input type="number" min="0" name="subscribers_count"
            value="{{ old('subscribers_count', $channel?->subscribers_count) }}"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
        @error('subscribers_count')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Videos Count</label>
        <input type="number" min="0" name="videos_count"
            value="{{ old('videos_count', $channel?->videos_count) }}"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
        @error('videos_count')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Views Count</label>
        <input type="number" min="0" name="views_count"
            value="{{ old('views_count', $channel?->views_count) }}"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
        @error('views_count')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>
</div>

<div class="mt-6">
    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
    <textarea name="description" rows="4"
        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">{{ old('description', $channel?->description) }}</textarea>
    @error('description')
        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
    @enderror
</div>

{{-- Save Button for Main Channel Info (Only in Edit Mode) --}}
@if($channel)
    <div class="mt-8 pt-6 border-t border-gray-200">
        <div class="flex items-center justify-between p-4 bg-blue-50 rounded-lg border border-blue-200">
            <div>
                <p class="text-sm font-semibold text-blue-900">💾 Lưu thay đổi thông tin kênh</p>
                <p class="text-xs text-blue-700">Nhấn nút bên phải để lưu các thay đổi về Channel ID, Title, Description, v.v.</p>
            </div>
            <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200 shadow-md">
                💾 Save Changes
            </button>
        </div>
    </div>
@endif

<div class="mt-8 border-t border-gray-200 pt-6" id="referenceChannelSection" style="display: {{ ($channel && $channel->content_type === 'dub') || old('content_type') === 'dub' ? 'block' : 'none' }}">
    <div class="mb-4 p-3 bg-purple-50 rounded-lg border border-purple-100">
        <p class="text-xs font-medium text-purple-800 mb-1">📺 Reference Channels (Chỉ cho kênh Dub)</p>
        <p class="text-xs text-purple-700">Thêm kênh YouTube hoặc Bilibili Space để lấy video nguồn.</p>
    </div>

    <h4 class="text-sm font-semibold text-gray-900 mb-3">Reference Source Channel</h4>
    <input type="hidden" id="currentChannelId" value="{{ $channel?->id }}">
    <input type="hidden" name="ref_channels_json" id="refChannelsJson" value="{{ old('ref_channels_json') }}">
    <input type="hidden" id="refAddEndpoint"
        value="{{ $channel ? route('youtube-channels.references.store', $channel) : '' }}">
    <input type="hidden" id="refRemoveBase"
        value="{{ $channel ? url('youtube-channels/' . $channel->id . '/references') : '' }}">
    <div class="mb-4">
        <label for="refChannelUrl" class="block text-sm font-medium text-gray-700 mb-2">
            Channel URL
        </label>
        <div class="flex flex-wrap items-center gap-3">
            <input type="text" id="refChannelUrl"
                class="flex-1 min-w-[240px] px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"
                placeholder="https://www.youtube.com/@AstrumEarth hoặc https://space.bilibili.com/3493294331923099">
            <button id="refChannelQuickAddBtn" type="button"
                class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                Add
            </button>
        </div>
        <p class="text-xs text-gray-500 mt-2">Supports YouTube (@handle, /channel/) and Bilibili Space (/123456).</p>
    </div>

    <div class="flex flex-wrap items-center gap-3 hidden">
        <button id="refChannelFetchBtn" type="button" data-endpoint="{{ route('youtube.channel.videos') }}"
            class="bg-gray-900 hover:bg-gray-800 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
            Fetch Channel Videos
        </button>
        <button id="refChannelTestBtn" type="button"
            class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold py-2 px-4 rounded-lg transition duration-200">
            Test 1
        </button>
        <button id="refChannelAddBtn" type="button"
            class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200 hidden">
            Add Reference Channel
        </button>
        <div class="flex items-center gap-2">
            <label for="refFetchInterval" class="text-sm text-gray-600">Fetch interval (days)</label>
            <input type="number" id="refFetchInterval" min="1" value="7"
                class="w-20 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
        </div>
    </div>

    <div id="refChannelStatus" class="mt-4 text-sm text-gray-600 hidden"></div>

    <div id="refChannelInfo" class="mt-6 hidden">
        <div class="flex items-center gap-3 mb-4">
            <img id="refChannelThumb" src="" alt="Channel thumbnail"
                class="w-12 h-12 rounded-full object-cover border">
            <div>
                <div id="refChannelTitle" class="text-sm font-semibold text-gray-900"></div>
                <div id="refChannelId" class="text-xs text-gray-500"></div>
            </div>
        </div>
        <p id="refChannelDesc" class="text-sm text-gray-600"></p>
    </div>

    <div id="refChannelsSaved" class="mt-6">
        <h5 class="text-sm font-semibold text-gray-900 mb-3">Reference Channels List</h5>
        <div class="space-y-3" id="refChannelsSavedList"></div>
    </div>
</div>

{{-- MC / Avatar Management — mỗi MC có 1 ảnh đại diện chính + nhiều ảnh avatar phụ --}}
<div class="mt-8 border-t border-gray-200 pt-6">
    <div class="mb-4 p-3 bg-indigo-50 rounded-lg border border-indigo-100">
        <p class="text-xs font-medium text-indigo-800 mb-1">🎭 MC / Avatar kênh</p>
        <p class="text-xs text-indigo-700">Mỗi MC có thể có 1 ảnh đại diện chính và nhiều ảnh avatar phụ — dùng khi tạo
            video (ảnh nền, HeyGen lip-sync, v.v.)</p>
    </div>

    @if (!$channel)
        <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-700">
            ⚠️ Lưu kênh trước, sau đó quay lại trang chỉnh sửa để thêm MC / avatar.
        </div>
    @else
        <input type="hidden" id="spkChannelId" value="{{ $channel->id }}">
        <input type="hidden" id="spkIndexUrl" value="{{ route('youtube-channels.speakers.index', $channel) }}">
        <input type="hidden" id="spkStoreUrl" value="{{ route('youtube-channels.speakers.store', $channel) }}">
        <input type="hidden" id="spkUpdateUrlBase" value="{{ url('youtube-channels/' . $channel->id . '/speakers') }}">

        <div class="mb-6 p-4 border border-gray-200 rounded-lg bg-gray-50">
            <h4 class="text-sm font-semibold text-gray-900 mb-3">Thêm MC mới</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Tên MC *</label>
                    <input type="text" id="spkName"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Giới tính *</label>
                    <select id="spkGender"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        <option value="female">Nữ</option>
                        <option value="male">Nam</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Ảnh đại diện chính</label>
                    <input type="file" id="spkAvatar" accept="image/*"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    <p class="text-xs text-gray-400 mt-1">Tối đa 5MB/ảnh.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Ảnh avatar phụ (chọn nhiều
                        ảnh)</label>
                    <input type="file" id="spkAdditionalImages" accept="image/*" multiple
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    <p class="text-xs text-gray-400 mt-1">Tối đa 5MB/ảnh.</p>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Mô tả</label>
                    <textarea id="spkDescription" rows="2"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"></textarea>
                </div>
            </div>
            <button type="button" id="spkAddBtn"
                class="mt-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                + Thêm MC
            </button>
            <span id="spkAddStatus" class="ml-3 text-sm text-gray-600"></span>
        </div>

        <div id="spkList" class="space-y-4">
            <div class="text-sm text-gray-500">Đang tải danh sách MC...</div>
        </div>
    @endif
</div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle Reference Channel Section based on content_type
            const contentTypeRadios = document.querySelectorAll('input[name="content_type"]');
            const referenceSection = document.getElementById('referenceChannelSection');

            if (contentTypeRadios.length > 0 && referenceSection) {
                contentTypeRadios.forEach(radio => {
                    radio.addEventListener('change', function() {
                        if (this.value === 'dub') {
                            referenceSection.style.display = 'block';
                        } else {
                            referenceSection.style.display = 'none';
                        }
                    });
                });
            }

            const fetchBtn = document.getElementById('refChannelFetchBtn');
            const testBtn = document.getElementById('refChannelTestBtn');
            const quickAddBtn = document.getElementById('refChannelQuickAddBtn');
            const urlInput = document.getElementById('refChannelUrl');
            const statusEl = document.getElementById('refChannelStatus');
            const infoEl = document.getElementById('refChannelInfo');
            const titleEl = document.getElementById('refChannelTitle');
            const idEl = document.getElementById('refChannelId');
            const thumbEl = document.getElementById('refChannelThumb');
            const descEl = document.getElementById('refChannelDesc');
            const addBtn = document.getElementById('refChannelAddBtn');
            const intervalInput = document.getElementById('refFetchInterval');
            const savedList = document.getElementById('refChannelsSavedList');
            const refChannelsJson = document.getElementById('refChannelsJson');
            const currentChannelId = document.getElementById('currentChannelId')?.value;
            const refAddEndpoint = document.getElementById('refAddEndpoint')?.value;
            const refRemoveBase = document.getElementById('refRemoveBase')?.value;

            let currentFetchedChannel = null;
            let savedRefs = [];

            if (!fetchBtn || !urlInput) return;

            const renderSavedRefs = () => {
                if (!savedList) return;
                savedList.innerHTML = '';
                if (savedRefs.length === 0) {
                    savedList.innerHTML =
                        '<div class="text-sm text-gray-500">No reference channels saved.</div>';
                    return;
                }

                savedRefs.forEach((ref, index) => {
                    const item = document.createElement('div');
                    item.className = 'flex items-center gap-3 p-3 border border-gray-200 rounded-lg';
                    item.innerHTML = `
                        <img src="${ref.ref_thumbnail_url || ''}" alt="Thumbnail" class="w-10 h-10 rounded-full object-cover border" />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-gray-900 truncate">${ref.ref_title || ''}</div>
                            <div class="text-xs text-gray-500">${ref.ref_channel_id || ''}</div>
                            <div class="text-xs text-gray-400 truncate">${ref.ref_channel_url || ''}</div>
                            <div class="text-xs text-gray-600 mt-1 line-clamp-2">${ref.ref_description || ''}</div>
                            <div class="text-xs text-gray-500 mt-1">Fetch every ${ref.fetch_interval_days || 7} day(s)</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="number" min="1" value="${ref.fetch_interval_days || 7}" data-interval-index="${index}"
                                class="w-20 px-2 py-1 border border-gray-300 rounded text-xs" />
                            <button type="button" data-index="${index}" class="text-xs text-red-600 hover:text-red-700">Remove</button>
                        </div>
                    `;
                    savedList.appendChild(item);
                });

                savedList.querySelectorAll('input[data-interval-index]').forEach((input) => {
                    input.addEventListener('change', () => {
                        const idx = parseInt(input.dataset.intervalIndex, 10);
                        const val = parseInt(input.value, 10);
                        if (!Number.isNaN(idx)) {
                            savedRefs[idx].fetch_interval_days = Number.isNaN(val) || val < 1 ?
                                1 : val;
                            if (refChannelsJson) {
                                refChannelsJson.value = JSON.stringify(savedRefs);
                            }
                            renderSavedRefs();
                        }
                    });
                });

                savedList.querySelectorAll('button[data-index]').forEach((btn) => {
                    btn.addEventListener('click', async () => {
                        const idx = parseInt(btn.dataset.index, 10);
                        if (!Number.isNaN(idx)) {
                            const ref = savedRefs[idx];

                            if (currentChannelId && ref?.id && refRemoveBase) {
                                try {
                                    const response = await fetch(
                                        `${refRemoveBase}/${ref.id}`, {
                                            method: 'DELETE',
                                            headers: {
                                                'X-CSRF-TOKEN': document.querySelector(
                                                        'meta[name="csrf-token"]')
                                                    .content
                                            }
                                        });

                                    if (!response.ok) {
                                        const data = await response.json().catch(() =>
                                            ({}));
                                        throw new Error(data.error ||
                                            'Không thể xoá reference');
                                    }
                                } catch (error) {
                                    alert(error.message || 'Có lỗi xảy ra');
                                    return;
                                }
                            }

                            savedRefs.splice(idx, 1);
                            if (refChannelsJson) {
                                refChannelsJson.value = JSON.stringify(savedRefs);
                            }
                            renderSavedRefs();
                        }
                    });
                });
            };

            try {
                const existing = @json($existingRefs);
                if (Array.isArray(existing) && existing.length) {
                    savedRefs = existing;
                    if (refChannelsJson && !refChannelsJson.value) {
                        refChannelsJson.value = JSON.stringify(savedRefs);
                    }
                }
            } catch (e) {
                // ignore
            }

            renderSavedRefs();

            const fetchChannelVideos = async (maxResults = 20, event = null, autoAdd = false) => {
                if (event) event.preventDefault();
                const channelUrl = urlInput.value?.trim();
                if (!channelUrl) {
                    alert('Vui lòng nhập URL kênh nguồn');
                    return;
                }

                if (autoAdd && isDuplicateUrl(channelUrl)) {
                    alert('URL này đã tồn tại trong danh sách reference.');
                    return;
                }

                const endpoint = fetchBtn.dataset.endpoint;
                if (!endpoint) {
                    alert('Thiếu endpoint để lấy dữ liệu kênh');
                    return;
                }

                fetchBtn.disabled = true;
                if (testBtn) testBtn.disabled = true;
                fetchBtn.textContent = 'Đang lấy dữ liệu...';

                if (statusEl) {
                    statusEl.classList.remove('hidden');
                    statusEl.textContent = 'Đang lấy dữ liệu kênh...';
                }

                try {
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                .content
                        },
                        body: JSON.stringify({
                            channel_url: channelUrl,
                            max_results: maxResults
                        })
                    });

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Không thể lấy dữ liệu kênh');
                    }

                    if (infoEl) {
                        infoEl.classList.remove('hidden');
                        if (titleEl) titleEl.textContent = data.channel?.title || '';
                        if (idEl) idEl.textContent = data.channel?.id ?
                            `Channel ID: ${data.channel.id}` : '';
                        if (thumbEl && data.channel?.thumbnail) thumbEl.src = data.channel.thumbnail;
                        if (descEl) descEl.textContent = data.channel?.description || '';
                    }

                    currentFetchedChannel = {
                        id: null,
                        ref_channel_url: channelUrl,
                        ref_channel_id: data.channel?.id || null,
                        ref_title: data.channel?.title || null,
                        ref_description: data.channel?.description || null,
                        ref_thumbnail_url: data.channel?.thumbnail || null,
                        fetch_interval_days: parseInt(intervalInput?.value || '7', 10) || 7
                    };

                    if (autoAdd) {
                        if (currentChannelId && refAddEndpoint) {
                            const response = await fetch(refAddEndpoint, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector(
                                        'meta[name="csrf-token"]').content
                                },
                                body: JSON.stringify(currentFetchedChannel)
                            });

                            const result = await response.json().catch(() => ({}));
                            if (!response.ok || !result.success) {
                                throw new Error(result.error || 'Không thể lưu reference');
                            }

                            currentFetchedChannel.id = result.id || null;
                            savedRefs.push(currentFetchedChannel);
                        } else {
                            savedRefs.push(currentFetchedChannel);
                        }

                        if (refChannelsJson) {
                            refChannelsJson.value = JSON.stringify(savedRefs);
                        }
                        renderSavedRefs();
                        if (addBtn) addBtn.classList.add('hidden');
                    } else if (addBtn) {
                        addBtn.classList.remove('hidden');
                    }

                    if (statusEl) {
                        statusEl.textContent = `Đã tải ${data.videos?.length || 0} video.`;
                    }
                } catch (error) {
                    if (statusEl) {
                        statusEl.textContent = error.message || 'Có lỗi xảy ra';
                    }
                } finally {
                    fetchBtn.disabled = false;
                    if (testBtn) testBtn.disabled = false;
                    fetchBtn.textContent = 'Fetch Channel Videos';
                }
            };

            fetchBtn.addEventListener('click', (e) => fetchChannelVideos(20, e));

            if (testBtn) {
                testBtn.addEventListener('click', (e) => fetchChannelVideos(1, e));
            }

            const isDuplicateUrl = (url) => {
                return savedRefs.some((ref) => (ref.ref_channel_url || '').trim() === url.trim());
            };

            if (addBtn) {
                addBtn.addEventListener('click', () => {
                    if (!currentFetchedChannel) return;
                    if (isDuplicateUrl(currentFetchedChannel.ref_channel_url)) {
                        alert('URL này đã tồn tại trong danh sách reference.');
                        return;
                    }
                    savedRefs.push(currentFetchedChannel);
                    if (refChannelsJson) {
                        refChannelsJson.value = JSON.stringify(savedRefs);
                    }
                    renderSavedRefs();
                    addBtn.classList.add('hidden');
                });
            }

            if (quickAddBtn) {
                quickAddBtn.addEventListener('click', () => {
                    const channelUrl = urlInput.value?.trim();
                    if (!channelUrl) {
                        alert('Vui lòng nhập URL kênh nguồn');
                        return;
                    }

                    fetchChannelVideos(1, null, true);
                });
            }
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const indexUrl = document.getElementById('spkIndexUrl')?.value;
            if (!indexUrl) return; // no channel yet (create mode) — section not rendered

            const storeUrl = document.getElementById('spkStoreUrl').value;
            const updateUrlBase = document.getElementById('spkUpdateUrlBase').value;
            const listEl = document.getElementById('spkList');
            const addBtn = document.getElementById('spkAddBtn');
            const addStatus = document.getElementById('spkAddStatus');
            const nameInput = document.getElementById('spkName');
            const genderInput = document.getElementById('spkGender');
            const avatarInput = document.getElementById('spkAvatar');
            const additionalInput = document.getElementById('spkAdditionalImages');
            const descriptionInput = document.getElementById('spkDescription');
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

            const escapeHtml = (s) => (s || '').replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[c]);

            // This app's error responses come in two different shapes depending on which
            // layer produced them: Laravel's own 422 validation response uses "message"
            // (plus an "errors" object), while the app's custom exception handler
            // (app/Exceptions/Handler.php) wraps everything else as {success:false,
            // error:"..."} for any JSON-expecting request. Reading only "message" silently
            // swallowed the real reason (e.g. file too large) behind a generic fallback.
            const extractErrorMessage = (data, fallback) => {
                if (data?.errors) {
                    const first = Object.values(data.errors)[0];
                    if (Array.isArray(first) && first.length) return first[0];
                }
                return data?.message || data?.error || fallback;
            };

            async function loadSpeakers() {
                listEl.innerHTML = '<div class="text-sm text-gray-500">Đang tải danh sách MC...</div>';
                try {
                    const resp = await fetch(indexUrl, { headers: { Accept: 'application/json' } });
                    const data = await resp.json();
                    renderSpeakers(data.speakers || []);
                } catch (e) {
                    listEl.innerHTML = '<div class="text-sm text-red-600">Không tải được danh sách MC.</div>';
                }
            }

            function renderSpeakers(speakers) {
                if (!speakers.length) {
                    listEl.innerHTML = '<div class="text-sm text-gray-500">Chưa có MC nào.</div>';
                    return;
                }

                listEl.innerHTML = '';
                speakers.forEach((spk) => {
                    const card = document.createElement('div');
                    card.className = 'p-4 border border-gray-200 rounded-lg' + (spk.is_active ? '' :
                        ' opacity-60');

                    const imagesHtml = (spk.additional_images_urls || []).map((url, idx) => `
                        <div class="relative group">
                            <img src="${escapeHtml(url)}" class="w-16 h-16 object-cover rounded border" />
                            <button type="button" data-remove-image data-speaker-id="${spk.id}" data-image-path="${escapeHtml((spk.additional_images || [])[idx] || '')}"
                                class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-red-600 text-white text-xs leading-5 opacity-0 group-hover:opacity-100 transition">×</button>
                        </div>`).join('');

                    card.innerHTML = `
                        <div class="flex items-start gap-3">
                            <img src="${spk.avatar_url ? escapeHtml(spk.avatar_url) : 'https://placehold.co/64x64?text=?'}"
                                class="w-16 h-16 rounded-full object-cover border flex-shrink-0" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold text-gray-900">${escapeHtml(spk.name)}</span>
                                    <span class="text-xs px-2 py-0.5 rounded-full ${spk.gender === 'male' ? 'bg-blue-100 text-blue-700' : 'bg-pink-100 text-pink-700'}">${spk.gender === 'male' ? 'Nam' : 'Nữ'}</span>
                                    ${spk.is_active ? '' : '<span class="text-xs px-2 py-0.5 rounded-full bg-gray-200 text-gray-600">Đã ẩn</span>'}
                                    ${spk.audiobooks_count ? `<span class="text-xs text-gray-400">${spk.audiobooks_count} audiobook đang dùng</span>` : ''}
                                </div>
                                ${spk.description ? `<p class="text-xs text-gray-500 mt-1">${escapeHtml(spk.description)}</p>` : ''}
                                <div class="flex flex-wrap gap-2 mt-2">${imagesHtml}</div>
                                <label class="inline-block mt-2 text-xs text-indigo-600 hover:text-indigo-800 cursor-pointer">
                                    + Thêm ảnh avatar
                                    <input type="file" accept="image/*" multiple class="hidden" data-add-image data-speaker-id="${spk.id}">
                                </label>
                            </div>
                            <div class="flex flex-col gap-1 flex-shrink-0">
                                <button type="button" data-toggle data-speaker-id="${spk.id}"
                                    class="text-xs px-3 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700">${spk.is_active ? 'Ẩn' : 'Hiện'}</button>
                                <button type="button" data-delete data-speaker-id="${spk.id}"
                                    class="text-xs px-3 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-700">Xóa</button>
                            </div>
                        </div>`;

                    listEl.appendChild(card);
                });

                listEl.querySelectorAll('button[data-remove-image]').forEach((btn) => {
                    btn.addEventListener('click', () => removeImage(btn.dataset.speakerId, btn.dataset
                        .imagePath));
                });
                listEl.querySelectorAll('button[data-toggle]').forEach((btn) => {
                    btn.addEventListener('click', () => toggleSpeaker(btn.dataset.speakerId));
                });
                listEl.querySelectorAll('button[data-delete]').forEach((btn) => {
                    btn.addEventListener('click', () => deleteSpeaker(btn.dataset.speakerId));
                });
                listEl.querySelectorAll('input[data-add-image]').forEach((input) => {
                    input.addEventListener('change', () => addImages(input.dataset.speakerId, input.files));
                });
            }

            const MAX_IMAGE_BYTES = 5 * 1024 * 1024; // must match 'max:5120' (KB) in the backend validation
            const MAX_IMAGE_DIMENSION = 1920; // long edge, px — plenty for an avatar/thumbnail, keeps file size down

            const findOversizedFile = (files) => Array.from(files || []).find((f) => f.size > MAX_IMAGE_BYTES);

            // Downscales/re-compresses an oversized image client-side so it clears the
            // backend's 5MB limit automatically instead of the upload just failing. Non-image
            // files and images already within both the dimension and size budget pass through
            // untouched (re-encoding a file that's already fine only loses quality for nothing).
            async function resizeImageFile(file, maxDimension = MAX_IMAGE_DIMENSION) {
                if (!file || !file.type.startsWith('image/') || file.type === 'image/svg+xml') return file;

                const dataUrl = await new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onload = () => resolve(reader.result);
                    reader.onerror = () => reject(new Error('Không đọc được file ảnh: ' + file.name));
                    reader.readAsDataURL(file);
                });

                const img = await new Promise((resolve, reject) => {
                    const el = new Image();
                    el.onload = () => resolve(el);
                    el.onerror = () => reject(new Error('Không đọc được ảnh: ' + file.name));
                    el.src = dataUrl;
                });

                if (img.width <= maxDimension && img.height <= maxDimension && file.size <= MAX_IMAGE_BYTES) {
                    return file;
                }

                const scale = Math.min(1, maxDimension / Math.max(img.width, img.height));
                const width = Math.max(1, Math.round(img.width * scale));
                const height = Math.max(1, Math.round(img.height * scale));

                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                canvas.getContext('2d').drawImage(img, 0, 0, width, height);

                const toBlob = (type, quality) => new Promise((resolve) => canvas.toBlob(resolve, type, quality));

                // PNG ignores the quality argument — a downscaled PNG that's still too big
                // (e.g. a large screenshot) needs re-encoding as JPEG to actually shrink further.
                let outputType = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
                let quality = 0.85;
                let blob = await toBlob(outputType, quality);

                if (blob && blob.size > MAX_IMAGE_BYTES && outputType === 'image/png') {
                    outputType = 'image/jpeg';
                    blob = await toBlob(outputType, quality);
                }

                let attempts = 0;
                while (blob && blob.size > MAX_IMAGE_BYTES && quality > 0.35 && attempts < 5) {
                    quality -= 0.15;
                    blob = await toBlob(outputType, quality);
                    attempts++;
                }

                if (!blob) return file; // canvas export failed for some reason — let the server validate as-is

                const newName = file.name.replace(/\.\w+$/, '') + (outputType === 'image/png' ? '.png' :
                    '.jpg');
                return new File([blob], newName, { type: outputType, lastModified: Date.now() });
            }

            async function addSpeaker() {
                const name = nameInput.value.trim();
                if (!name) {
                    alert('Vui lòng nhập tên MC');
                    return;
                }

                addBtn.disabled = true;
                addStatus.textContent = 'Đang xử lý ảnh...';

                try {
                    const avatarFile = avatarInput.files[0] ? await resizeImageFile(avatarInput.files[0]) :
                        null;
                    const additionalFiles = [];
                    for (const f of Array.from(additionalInput.files || [])) {
                        additionalFiles.push(await resizeImageFile(f));
                    }

                    const oversized = findOversizedFile([avatarFile, ...additionalFiles].filter(Boolean));
                    if (oversized) {
                        throw new Error(
                            `Ảnh "${oversized.name}" vẫn vượt quá 5MB sau khi tự động thu nhỏ. Vui lòng chọn ảnh khác.`
                        );
                    }

                    const formData = new FormData();
                    formData.append('name', name);
                    formData.append('gender', genderInput.value);
                    formData.append('description', descriptionInput.value || '');
                    if (avatarFile) formData.append('avatar', avatarFile);
                    additionalFiles.forEach((file) => formData.append('additional_images[]', file));

                    addStatus.textContent = 'Đang lưu...';
                    const resp = await fetch(storeUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                        body: formData,
                    });
                    const data = await resp.json();
                    if (!resp.ok || !data.success) {
                        throw new Error(extractErrorMessage(data, 'Không thể thêm MC'));
                    }

                    nameInput.value = '';
                    avatarInput.value = '';
                    additionalInput.value = '';
                    descriptionInput.value = '';
                    addStatus.textContent = '✅ Đã thêm.';
                    await loadSpeakers();
                } catch (e) {
                    addStatus.textContent = '';
                    alert(e.message || 'Có lỗi xảy ra');
                } finally {
                    addBtn.disabled = false;
                    setTimeout(() => { addStatus.textContent = ''; }, 3000);
                }
            }

            async function addImages(speakerId, files) {
                if (!files || !files.length) return;

                // updateSpeaker() requires name/gender to already be valid — refetch current
                // speaker values first so this "just add more images" action doesn't blank them out.
                try {
                    const resizedFiles = [];
                    for (const f of Array.from(files)) {
                        resizedFiles.push(await resizeImageFile(f));
                    }

                    const oversized = findOversizedFile(resizedFiles);
                    if (oversized) {
                        throw new Error(
                            `Ảnh "${oversized.name}" vẫn vượt quá 5MB sau khi tự động thu nhỏ. Vui lòng chọn ảnh khác.`
                        );
                    }

                    const formData = new FormData();
                    formData.append('_method', 'PUT');
                    resizedFiles.forEach((file) => formData.append('additional_images[]', file));

                    const resp = await fetch(indexUrl, { headers: { Accept: 'application/json' } });
                    const data = await resp.json();
                    const spk = (data.speakers || []).find((s) => String(s.id) === String(speakerId));
                    if (!spk) throw new Error('Không tìm thấy MC');

                    formData.set('name', spk.name);
                    formData.set('gender', spk.gender);

                    const updateResp = await fetch(`${updateUrlBase}/${speakerId}`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                        body: formData,
                    });
                    const updateData = await updateResp.json();
                    if (!updateResp.ok || !updateData.success) {
                        throw new Error(extractErrorMessage(updateData, 'Không thể thêm ảnh'));
                    }
                    await loadSpeakers();
                } catch (e) {
                    alert(e.message || 'Có lỗi xảy ra');
                }
            }

            async function removeImage(speakerId, imagePath) {
                if (!imagePath) return;
                try {
                    const resp = await fetch(`${updateUrlBase}/${speakerId}/delete-image`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ image_path: imagePath }),
                    });
                    const data = await resp.json();
                    if (!resp.ok || !data.success) throw new Error(extractErrorMessage(data, 'Không thể xóa ảnh'));
                    await loadSpeakers();
                } catch (e) {
                    alert(e.message || 'Có lỗi xảy ra');
                }
            }

            async function toggleSpeaker(speakerId) {
                try {
                    const resp = await fetch(`${updateUrlBase}/${speakerId}/toggle-status`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                    });
                    const data = await resp.json();
                    if (!resp.ok || !data.success) throw new Error(extractErrorMessage(data, 'Không thể cập nhật'));
                    await loadSpeakers();
                } catch (e) {
                    alert(e.message || 'Có lỗi xảy ra');
                }
            }

            async function deleteSpeaker(speakerId) {
                if (!confirm('Xóa MC này? Hành động không thể hoàn tác.')) return;
                try {
                    const resp = await fetch(`${updateUrlBase}/${speakerId}`, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                    });
                    const data = await resp.json();
                    if (!resp.ok || !data.success) throw new Error(extractErrorMessage(data, 'Không thể xóa MC'));
                    await loadSpeakers();
                } catch (e) {
                    alert(e.message || 'Có lỗi xảy ra');
                }
            }

            addBtn.addEventListener('click', addSpeaker);
            loadSpeakers();
        });
    </script>
@endpush
