@extends('layouts.app')

@section('content')
    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-between items-center mb-6">
                <h2 class="font-semibold text-2xl text-gray-800">
                    {{ __('Channel Details') }}
                </h2>
                <div class="flex gap-2">
                    <a href="{{ route('youtube-channels.index') }}"
                        class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg transition duration-200">
                        Back
                    </a>
                    <a href="{{ route('youtube-channels.edit', $youtubeChannel) }}"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                        Edit
                    </a>
                    <button id="newVideoBtn"
                        class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                        + New Video
                    </button>
                </div>
            </div>
            <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
                @if ($message = Session::get('success'))
                    <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                        {{ $message }}
                        <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3"
                            onclick="this.parentElement.style.display='none';">
                            <span class="text-2xl leading-none">&times;</span>
                        </button>
                    </div>
                @endif

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex flex-col md:flex-row gap-6">
                            <div class="flex-shrink-0">
                                @if ($youtubeChannel->thumbnail_url)
                                    @php
                                        $thumbnailUrl = \Illuminate\Support\Str::startsWith(
                                            $youtubeChannel->thumbnail_url,
                                            ['http://', 'https://'],
                                        )
                                            ? $youtubeChannel->thumbnail_url
                                            : asset('storage/' . ltrim($youtubeChannel->thumbnail_url, '/'));
                                    @endphp
                                    <img src="{{ $thumbnailUrl }}" alt="Thumbnail"
                                        class="w-48 h-32 rounded object-cover border">
                                @else
                                    <div
                                        class="w-48 h-32 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400">
                                        No Thumbnail
                                    </div>
                                @endif
                            </div>
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold text-gray-900">{{ $youtubeChannel->title }}</h3>
                                <p class="text-sm text-gray-600 mt-1">Channel ID: {{ $youtubeChannel->channel_id }}</p>

                                {{-- Content Type Badge --}}
                                <div class="mt-2">
                                    @if ($youtubeChannel->content_type === 'audiobook')
                                        <span
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-700 text-sm font-medium rounded-lg">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                            </svg>
                                            Audiobook Channel
                                        </span>
                                    @elseif($youtubeChannel->content_type === 'dub')
                                        <span
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-purple-100 text-purple-700 text-sm font-medium rounded-lg">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                            </svg>
                                            Dub (Lồng tiếng) Channel
                                        </span>
                                    @elseif($youtubeChannel->content_type === 'self_creative')
                                        <span
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-orange-100 text-orange-700 text-sm font-medium rounded-lg">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                            </svg>
                                            Self Creative Channel
                                        </span>
                                    @else
                                        <span
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                            </svg>
                                            Content Type Not Set
                                        </span>
                                    @endif
                                </div>

                                @if ($youtubeChannel->channel_url)
                                    <a href="{{ $youtubeChannel->channel_url }}" target="_blank"
                                        class="text-xs text-red-600 hover:text-red-700 break-words">Open channel</a>
                                @endif
                                <p class="text-sm text-gray-600 mt-1">Country: {{ $youtubeChannel->country ?? '—' }}</p>
                                <p class="text-sm text-gray-600 mt-1">Published At:
                                    {{ $youtubeChannel->published_at?->format('d/m/Y H:i') ?? '—' }}</p>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <div class="text-xs text-gray-500">Subscribers</div>
                                        <div class="text-lg font-semibold text-gray-900">
                                            {{ number_format($youtubeChannel->subscribers_count ?? 0) }}
                                        </div>
                                    </div>
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <div class="text-xs text-gray-500">Videos</div>
                                        <div class="text-lg font-semibold text-gray-900">
                                            {{ number_format($youtubeChannel->videos_count ?? 0) }}
                                        </div>
                                    </div>
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <div class="text-xs text-gray-500">Views</div>
                                        <div class="text-lg font-semibold text-gray-900">
                                            {{ number_format($youtubeChannel->views_count ?? 0) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6">
                            <h4 class="text-sm font-semibold text-gray-900 mb-2">Description</h4>
                            <p class="text-sm text-gray-700 whitespace-pre-line">
                                {{ $youtubeChannel->description ?? 'No description available.' }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-8">
                    <div class="p-6 text-gray-900">
                        <div class="border-b border-gray-200 mb-4">
                            <nav class="-mb-px flex gap-6" aria-label="Tabs">
                                @if ($youtubeChannel->content_type === 'dub')
                                    <button id="tabDubsyncBtn"
                                        class="tab-btn border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm">
                                        📺 Lồng tiếng
                                    </button>
                                @endif
                                @if ($youtubeChannel->content_type === 'audiobook')
                                    <button id="tabAudiobooksBtn"
                                        class="tab-btn border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm">
                                        📚 Audio books
                                    </button>
                                @endif
                                @if ($youtubeChannel->content_type === 'self_creative')
                                    <button id="tabSelfCreativeBtn"
                                        class="tab-btn border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm">
                                        ✨ Nội dung sáng tạo
                                    </button>
                                @endif
                            </nav>
                        </div>

                        @if ($youtubeChannel->content_type === 'dub')
                            <div id="tabDubsync" class="tab-content">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-semibold">Lồng tiếng</h3>
                                    <div class="flex items-center gap-2">
                                        <button id="bulkTranscriptBtn" type="button"
                                            class="bg-green-100 text-green-700 font-semibold py-2 px-4 rounded-lg transition duration-200 opacity-60 cursor-not-allowed"
                                            disabled>
                                            Get transcript
                                        </button>
                                        <button id="bulkDeleteBtn" type="button"
                                            class="bg-red-100 text-red-700 font-semibold py-2 px-4 rounded-lg transition duration-200 opacity-60 cursor-not-allowed"
                                            disabled>
                                            Delete selected
                                        </button>
                                        <button id="bulkDownloadBtn" type="button"
                                            class="bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 opacity-60 cursor-not-allowed"
                                            disabled>
                                            Download selected (0)
                                        </button>
                                        @if ($youtubeChannel->content_type === 'dub')
                                            <form action="{{ route('youtube-channels.fetch.videos', $youtubeChannel) }}"
                                                method="POST" class="flex items-center gap-2">
                                                @csrf
                                                <select name="fetch_scope"
                                                    class="border border-gray-300 rounded-lg text-sm px-2 py-2 focus:outline-none focus:ring-2 focus:ring-gray-500"
                                                    title="Pham vi fetch">
                                                    <option value="latest" selected>Moi nhat</option>
                                                    <option value="all">Toan bo</option>
                                                </select>
                                                <select name="max_results"
                                                    class="border border-gray-300 rounded-lg text-sm px-2 py-2 focus:outline-none focus:ring-2 focus:ring-gray-500"
                                                    title="So luong toi da moi reference (chi ap dung voi Moi nhat)">
                                                    <option value="50">50</option>
                                                    <option value="100" selected>100</option>
                                                    <option value="200">200</option>
                                                    <option value="500">500</option>
                                                </select>
                                                <button type="submit"
                                                    class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                                    Fetch video
                                                </button>
                                            </form>
                                        @endif
                                        <button id="newVideoBtnInline"
                                            class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                            + New Video
                                        </button>
                                    </div>
                                </div>

                                <!-- Filter Section -->
                                <div class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                    <form method="GET" action="{{ route('youtube-channels.show', $youtubeChannel) }}"
                                        class="flex gap-4 items-end flex-wrap">
                                        <div class="flex-1 min-w-64">
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Search by name or
                                                video
                                                ID</label>
                                            <input type="text" name="search" placeholder="Enter video name or ID..."
                                                value="{{ $search }}"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
                                        </div>
                                        <div class="flex-1 min-w-48">
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Filter by
                                                Status</label>
                                            <select name="status"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
                                                <option value="">All Status</option>
                                                <option value="new" @if ($status === 'new') selected @endif>
                                                    New
                                                </option>
                                                <option value="source_downloaded"
                                                    @if ($status === 'source_downloaded') selected @endif>
                                                    Source Downloaded</option>
                                                <option value="pending" @if ($status === 'pending') selected @endif>
                                                    Pending</option>
                                                <option value="processing"
                                                    @if ($status === 'processing') selected @endif>
                                                    Processing</option>
                                                <option value="transcribed"
                                                    @if ($status === 'transcribed') selected @endif>
                                                    Transcribed</option>
                                                <option value="translated"
                                                    @if ($status === 'translated') selected @endif>
                                                    Translated</option>
                                                <option value="tts_generated"
                                                    @if ($status === 'tts_generated') selected @endif>
                                                    TTS Generated</option>
                                                <option value="aligned" @if ($status === 'aligned') selected @endif>
                                                    Aligned</option>
                                                <option value="merged" @if ($status === 'merged') selected @endif>
                                                    Merged
                                                </option>
                                                <option value="completed"
                                                    @if ($status === 'completed') selected @endif>
                                                    Completed</option>
                                                <option value="error" @if ($status === 'error') selected @endif>
                                                    Error
                                                </option>
                                            </select>
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="submit"
                                                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">
                                                Filter
                                            </button>
                                            <a href="{{ route('youtube-channels.show', $youtubeChannel) }}"
                                                class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg text-sm font-medium">
                                                Clear
                                            </a>
                                        </div>
                                    </form>
                                </div>

                                @if ($projects->count() === 0)
                                    <div class="text-center py-8 text-gray-600">No videos yet.</div>
                                @else
                                    <div class="mb-4 flex items-center justify-end">
                                        <div class="flex items-center gap-2">
                                            <label for="perPageSelect" class="text-sm text-gray-600">Items per
                                                page:</label>
                                            <select id="perPageSelect"
                                                class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
                                                <option value="10" @if (request('per_page') == 10 || request('per_page') === null) selected @endif>
                                                    10
                                                </option>
                                                <option value="20" @if (request('per_page') == 20) selected @endif>
                                                    20
                                                </option>
                                                <option value="50" @if (request('per_page') == 50) selected @endif>
                                                    50
                                                </option>
                                                <option value="100" @if (request('per_page') == 100) selected @endif>
                                                    100
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                    <form id="bulkDeleteForm"
                                        action="{{ route('youtube-channels.projects.bulk.destroy', $youtubeChannel) }}"
                                        method="POST" class="hidden">
                                        @csrf
                                        <div id="bulkDeleteInputs"></div>
                                    </form>
                                    <!-- Bulk Download Progress Panel -->
                                    <div id="bulkDownloadProgressPanel" class="hidden mb-4 border border-blue-200 bg-blue-50 rounded-lg p-4">
                                        <div class="flex items-center justify-between mb-2">
                                            <h4 class="text-sm font-semibold text-blue-900">Bulk Download Progress</h4>
                                            <span id="bulkDownloadSummary" class="text-xs text-blue-700">Waiting...</span>
                                        </div>
                                        <div id="bulkDownloadProgressList" class="space-y-2"></div>
                                    </div>

                                    <div class="overflow-x-hidden">
                                        <table class="w-full table-fixed divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th
                                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-12">
                                                        <input id="selectAllProjects" type="checkbox"
                                                            class="rounded border-gray-300 text-red-600 focus:ring-red-500" />
                                                    </th>
                                                    <th
                                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-2/5">
                                                        Video</th>
                                                    <th
                                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-1/5">
                                                        Duration</th>
                                                    <th
                                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-1/6">
                                                        Status</th>
                                                    <th
                                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-1/6">
                                                        Created</th>
                                                    <th
                                                        class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase w-1/6">
                                                        Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200">
                                                @foreach ($projects as $project)
                                                    @php
                                                        $projectUrl = strtolower((string) ($project->youtube_url ?? ''));
                                                        $projectVideoId = strtolower((string) ($project->video_id ?? ''));
                                                        $isBilibiliProject =
                                                            str_contains($projectUrl, 'bilibili.com/video/') ||
                                                            str_contains($projectUrl, 'b23.tv/') ||
                                                            str_starts_with($projectVideoId, 'bili:');
                                                    @endphp
                                                    <tr class="cursor-pointer hover:bg-gray-50"
                                                        data-href="{{ route('projects.edit', $project) }}"
                                                        data-transcript-url="{{ route('projects.get.transcript.async', $project) }}"
                                                        data-status="{{ $project->status }}"
                                                        data-is-bilibili="{{ $isBilibiliProject ? '1' : '0' }}">
                                                        <td class="px-4 py-3 align-top">
                                                            <input type="checkbox" data-project-id="{{ $project->id }}"
                                                                class="project-checkbox rounded border-gray-300 text-red-600 focus:ring-red-500" />
                                                        </td>
                                                        <td class="px-4 py-3 text-sm text-gray-900 align-top">
                                                            <div class="flex items-start gap-3">
                                                                @if ($project->youtube_thumbnail)
                                                                    @php
                                                                        $projectThumb = $project->youtube_thumbnail;
                                                                        $projectThumbHost = strtolower((string) parse_url($projectThumb, PHP_URL_HOST));
                                                                        $useThumbProxy = $projectThumbHost !== ''
                                                                            && (str_ends_with($projectThumbHost, '.biliimg.com')
                                                                                || str_ends_with($projectThumbHost, '.hdslb.com')
                                                                                || in_array($projectThumbHost, ['archive.biliimg.com', 'i0.hdslb.com', 'i1.hdslb.com', 'i2.hdslb.com', 'i.ytimg.com', 'img.youtube.com', 'yt3.ggpht.com'], true));
                                                                        $projectThumbSrc = $useThumbProxy
                                                                            ? route('youtube-channels.thumbnail.proxy', ['youtubeChannel' => $youtubeChannel, 'url' => $projectThumb])
                                                                            : $projectThumb;
                                                                    @endphp
                                                                    <img src="{{ $projectThumbSrc }}"
                                                                        alt="Thumbnail"
                                                                        class="w-12 h-8 rounded object-cover border flex-shrink-0">
                                                                @else
                                                                    <div
                                                                        class="w-12 h-8 rounded bg-gray-100 flex items-center justify-center text-gray-400 flex-shrink-0">
                                                                        <i class="ri-image-line"></i>
                                                                    </div>
                                                                @endif
                                                                <div class="min-w-0">
                                                                    <div class="font-medium break-words">
                                                                        {{ $project->youtube_title_vi ?? ($project->youtube_title ?? $project->video_id) }}
                                                                    </div>
                                                                    @if ($project->youtube_url)
                                                                        <a href="{{ $project->youtube_url }}"
                                                                            target="_blank"
                                                                            class="text-xs text-red-600 hover:text-red-700 break-words">Open
                                                                            video</a>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="px-4 py-3 text-sm text-gray-600 align-top">
                                                            {{ $project->youtube_duration ?? '—' }}
                                                        </td>
                                                        <td class="px-4 py-3 text-sm text-gray-600 align-top">
                                                            @php
                                                                $statusColors = [
                                                                    'new' =>
                                                                        'bg-blue-100 text-blue-800 border-blue-200',
                                                                    'source_downloaded' =>
                                                                        'bg-emerald-100 text-emerald-800 border-emerald-200',
                                                                    'pending' =>
                                                                        'bg-yellow-100 text-yellow-800 border-yellow-200',
                                                                    'processing' =>
                                                                        'bg-purple-100 text-purple-800 border-purple-200',
                                                                    'transcribed' =>
                                                                        'bg-cyan-100 text-cyan-800 border-cyan-200',
                                                                    'translated' =>
                                                                        'bg-indigo-100 text-indigo-800 border-indigo-200',
                                                                    'tts_generated' =>
                                                                        'bg-pink-100 text-pink-800 border-pink-200',
                                                                    'aligned' =>
                                                                        'bg-teal-100 text-teal-800 border-teal-200',
                                                                    'merged' =>
                                                                        'bg-lime-100 text-lime-800 border-lime-200',
                                                                    'completed' =>
                                                                        'bg-green-100 text-green-800 border-green-200',
                                                                    'error' => 'bg-red-100 text-red-800 border-red-200',
                                                                ];
                                                                $statusClass =
                                                                    $statusColors[$project->status] ??
                                                                    'bg-gray-100 text-gray-800 border-gray-200';
                                                            @endphp
                                                            <span
                                                                class="project-status px-2 py-1 rounded text-xs font-semibold border {{ $statusClass }}">
                                                                {{ ucfirst(str_replace('_', ' ', $project->status)) }}
                                                            </span>
                                                            @if ($project->status === 'error' && $project->error_message)
                                                                <div class="mt-1 text-xs text-red-600"
                                                                    title="{{ $project->error_message }}">
                                                                    <i class="ri-error-warning-line"></i>
                                                                    <span
                                                                        class="truncate max-w-[150px] inline-block align-bottom">{{ Str::limit($project->error_message, 50) }}</span>
                                                                </div>
                                                            @endif
                                                        </td>
                                                        <td class="px-4 py-3 text-sm text-gray-600 align-top">
                                                            {{ $project->created_at?->format('d/m/Y H:i') ?? '—' }}
                                                        </td>
                                                        <td class="px-4 py-3 text-right text-sm align-top">
                                                            <div class="inline-flex flex-wrap gap-2 justify-end">
                                                                @if ($project->status === 'error')
                                                                    <button
                                                                        class="retry-transcript-btn px-3 py-1.5 bg-orange-600 text-white rounded hover:bg-orange-700"
                                                                        data-project-id="{{ $project->id }}"
                                                                        data-youtube-url="{{ $project->youtube_url }}"
                                                                        data-transcript-url="{{ route('projects.get.transcript.async', $project) }}">
                                                                        <i class="ri-refresh-line"></i> Retry
                                                                    </button>
                                                                @endif
                                                                <a href="{{ route('projects.edit', $project) }}"
                                                                    class="px-3 py-1.5 bg-blue-600 text-white rounded hover:bg-blue-700">Edit</a>
                                                                <span
                                                                    class="row-spinner hidden items-center gap-2 text-xs text-gray-500">
                                                                    <svg class="animate-spin h-4 w-4 text-green-600"
                                                                        viewBox="0 0 24 24">
                                                                        <circle class="opacity-25" cx="12"
                                                                            cy="12" r="10" stroke="currentColor"
                                                                            stroke-width="4" fill="none"></circle>
                                                                        <path class="opacity-75" fill="currentColor"
                                                                            d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                                    </svg>
                                                                    Processing...
                                                                </span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-6">
                                        {{ $projects->links() }}
                                    </div>
                                @endif

                                <!-- Reference Channels Section (Only for Dub channels) -->
                                @if ($youtubeChannel->content_type === 'dub')
                                    <div class="mt-8 pt-8 border-t border-gray-200">
                                        <div class="flex items-center justify-between mb-4">
                                            <div>
                                                <h3 class="text-lg font-semibold">Reference Channels</h3>
                                                <p class="text-xs text-gray-500 mt-1">Kênh YouTube gốc để lấy video và lồng
                                                    tiếng</p>
                                            </div>
                                            <a href="{{ route('youtube-channels.edit', $youtubeChannel) }}"
                                                class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg transition duration-200">
                                                Manage
                                            </a>
                                        </div>

                                        @if ($referenceChannels->count() === 0)
                                            <div class="text-center py-8 bg-purple-50 rounded-lg border border-purple-100">
                                                <div class="text-gray-600 mb-2">Chưa có reference channels.</div>
                                                <p class="text-xs text-gray-500">Thêm kênh YouTube gốc để tự động lấy video
                                                    cần lồng tiếng.</p>
                                            </div>
                                        @else
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                @foreach ($referenceChannels as $ref)
                                                    <div
                                                        class="flex items-center gap-4 p-4 border border-gray-200 rounded-lg hover:border-purple-300 transition">
                                                        <div class="flex-shrink-0">
                                                            @if ($ref->ref_thumbnail_url)
                                                                <img src="{{ $ref->ref_thumbnail_url }}" alt="Thumbnail"
                                                                    class="w-14 h-14 rounded-full object-cover border">
                                                            @else
                                                                <div
                                                                    class="w-14 h-14 rounded-full bg-gray-100 flex items-center justify-center text-gray-400">
                                                                    <i class="ri-youtube-line"></i>
                                                                </div>
                                                            @endif
                                                        </div>
                                                        <div class="min-w-0 flex-1">
                                                            <div class="text-sm font-semibold text-gray-900 truncate">
                                                                {{ $ref->ref_title ?? 'Untitled Channel' }}
                                                            </div>
                                                            <div class="text-xs text-gray-500">
                                                                {{ $ref->ref_channel_id ?? '—' }}
                                                            </div>
                                                            <div class="text-xs text-gray-400 truncate">
                                                                {{ $ref->ref_channel_url }}
                                                            </div>
                                                            @if ($ref->ref_description)
                                                                <div class="text-xs text-gray-600 mt-1 line-clamp-2">
                                                                    {{ $ref->ref_description }}
                                                                </div>
                                                            @endif
                                                            <div class="text-xs text-gray-500 mt-1">
                                                                Fetch every {{ $ref->fetch_interval_days ?? 7 }} day(s)
                                                            </div>
                                                        </div>
                                                        <div class="flex-shrink-0">
                                                            <a href="{{ $ref->ref_channel_url }}" target="_blank"
                                                                class="text-xs text-red-600 hover:text-red-700">Open</a>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($youtubeChannel->content_type === 'audiobook')
                            <div id="tabAudiobooks" class="tab-content">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-semibold">Audio books</h3>
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="flex items-center gap-1 rounded-lg border border-gray-200 bg-white p-1">
                                            <button type="button" id="audioBooksGridBtn"
                                                class="px-3 py-1.5 text-xs font-medium rounded-md bg-gray-900 text-white">
                                                Grid
                                            </button>
                                            <button type="button" id="audioBooksListBtn"
                                                class="px-3 py-1.5 text-xs font-medium rounded-md text-gray-600 hover:text-gray-900">
                                                List
                                            </button>
                                        </div>
                                        <a href="{{ route('audiobooks.create') }}?youtube_channel_id={{ $youtubeChannel->id }}"
                                            class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                            + New Audio book
                                        </a>
                                    </div>
                                </div>

                                <div class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                    @php
                                        $audioBooksClearUrl = route('youtube-channels.show', $youtubeChannel);
                                        $audioBooksKeep = request()->except(['ab_search', 'ab_status', 'page']);
                                        if (!empty($audioBooksKeep)) {
                                            $audioBooksClearUrl .= '?' . http_build_query($audioBooksKeep);
                                        }
                                    @endphp
                                    <form method="GET" action="{{ route('youtube-channels.show', $youtubeChannel) }}"
                                        class="flex gap-4 items-end flex-wrap">
                                        <div class="flex-1 min-w-64">
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Tìm sách</label>
                                            <input type="text" name="ab_search"
                                                placeholder="Tên sách, tác giả, thể loại..." value="{{ $abSearch }}"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        </div>
                                        <div class="flex-1 min-w-48">
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Trạng thái</label>
                                            <select name="ab_status"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                <option value="">Tất cả</option>
                                                <option value="not_started"
                                                    @if ($abStatus === 'not_started') selected @endif>Chưa bắt đầu
                                                </option>
                                                <option value="processing"
                                                    @if ($abStatus === 'processing') selected @endif>Đang xử lý</option>
                                                <option value="completed"
                                                    @if ($abStatus === 'completed') selected @endif>Hoàn tất</option>
                                                <option value="error" @if ($abStatus === 'error') selected @endif>
                                                    Lỗi</option>
                                            </select>
                                        </div>
                                        @foreach (request()->except(['ab_search', 'ab_status', 'page']) as $key => $value)
                                            @if (!is_array($value))
                                                <input type="hidden" name="{{ $key }}"
                                                    value="{{ $value }}">
                                            @endif
                                        @endforeach
                                        <div class="flex gap-2">
                                            <button type="submit"
                                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                                                Lọc
                                            </button>
                                            <a href="{{ $audioBooksClearUrl }}"
                                                class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg text-sm font-medium">
                                                Xóa lọc
                                            </a>
                                        </div>
                                    </form>
                                </div>

                                @if ($audioBooks->count() === 0)
                                    <div class="text-center py-8 text-gray-600">No audio books yet.</div>
                                @else
                                    <div id="audioBooksGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                        @foreach ($audioBooks as $audioBook)
                                            @php
                                                $chaptersTotal =
                                                    $audioBook->chapters_total ?? ($audioBook->total_chapters ?? 0);
                                                $chaptersWithVideo = $audioBook->chapters_with_video ?? 0;
                                                $chaptersWithAudio = $audioBook->chapters_with_audio ?? 0;
                                                $chaptersWithError = $audioBook->chapters_with_error ?? 0;
                                                $durationSeconds = (int) round($audioBook->total_duration_seconds ?? 0);
                                                $hours = (int) floor($durationSeconds / 3600);
                                                $mins = (int) floor(($durationSeconds % 3600) / 60);
                                                $secs = (int) ($durationSeconds % 60);
                                                $durationLabel =
                                                    $durationSeconds > 0
                                                        ? ($hours > 0
                                                            ? sprintf('%dh %02dm %02ds', $hours, $mins, $secs)
                                                            : sprintf('%dm %02ds', $mins, $secs))
                                                        : '—';
                                                if ($chaptersWithError > 0) {
                                                    $statusLabel = 'Lỗi';
                                                    $statusClass = 'bg-red-100 text-red-700';
                                                } elseif ($chaptersTotal > 0 && $chaptersWithVideo >= $chaptersTotal) {
                                                    $statusLabel = 'Hoàn tất';
                                                    $statusClass = 'bg-green-100 text-green-700';
                                                } elseif ($chaptersWithAudio > 0) {
                                                    $statusLabel = 'Đang xử lý';
                                                    $statusClass = 'bg-amber-100 text-amber-700';
                                                } else {
                                                    $statusLabel = 'Chưa bắt đầu';
                                                    $statusClass = 'bg-gray-100 text-gray-600';
                                                }
                                            @endphp
                                            <div
                                                class="border border-gray-200 rounded-lg overflow-hidden hover:shadow-lg transition duration-200 flex flex-col">
                                                <div class="relative bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center p-4"
                                                    style="min-height: 200px;">
                                                    @if ($audioBook->cover_image)
                                                        <img src="{{ asset('storage/' . $audioBook->cover_image) }}"
                                                            alt="Cover"
                                                            class="max-h-48 w-auto object-contain rounded shadow-md">
                                                    @else
                                                        <div
                                                            class="w-32 h-44 bg-gradient-to-br from-blue-400 to-blue-600 rounded-lg flex items-center justify-center text-white shadow-md">
                                                            <i class="ri-book-line text-4xl"></i>
                                                        </div>
                                                    @endif
                                                </div>
                                                <div class="p-4 flex-1 flex flex-col">
                                                    <h4 class="text-sm font-semibold text-gray-900 truncate">
                                                        {{ $audioBook->title }}
                                                    </h4>
                                                    <div class="flex flex-wrap items-center gap-2 mt-2">
                                                        <span class="text-xs text-gray-500">{{ $chaptersTotal }}
                                                            chương</span>
                                                        <span class="text-xs text-gray-500">⏱️ {{ $durationLabel }}</span>
                                                        <span
                                                            class="text-xs px-2 py-0.5 rounded-full {{ $statusClass }}">{{ $statusLabel }}</span>
                                                    </div>
                                                    @if ($audioBook->description)
                                                        <p class="text-xs text-gray-600 mt-2 line-clamp-2 flex-1">
                                                            {{ $audioBook->description }}
                                                        </p>
                                                    @endif
                                                    <div class="flex gap-2 mt-4">
                                                        <a href="{{ route('audiobooks.show', $audioBook) }}"
                                                            class="flex-1 text-center px-3 py-1.5 bg-blue-600 text-white rounded text-xs font-medium hover:bg-blue-700">
                                                            View
                                                        </a>
                                                        <a href="{{ route('audiobooks.edit', $audioBook) }}"
                                                            class="flex-1 text-center px-3 py-1.5 bg-gray-200 text-gray-800 rounded text-xs font-medium hover:bg-gray-300">
                                                            Edit
                                                        </a>
                                                        <form action="{{ route('audiobooks.destroy', $audioBook) }}"
                                                            method="POST" class="flex-1">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit"
                                                                class="w-full px-3 py-1.5 bg-red-100 text-red-700 rounded text-xs font-medium hover:bg-red-200"
                                                                onclick="return confirm('Xóa audiobook này?');">
                                                                Xóa
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div id="audioBooksList"
                                        class="hidden divide-y divide-gray-200 border border-gray-200 rounded-lg overflow-hidden">
                                        @foreach ($audioBooks as $audioBook)
                                            @php
                                                $chaptersTotal =
                                                    $audioBook->chapters_total ?? ($audioBook->total_chapters ?? 0);
                                                $chaptersWithVideo = $audioBook->chapters_with_video ?? 0;
                                                $chaptersWithAudio = $audioBook->chapters_with_audio ?? 0;
                                                $chaptersWithError = $audioBook->chapters_with_error ?? 0;
                                                $durationSeconds = (int) round($audioBook->total_duration_seconds ?? 0);
                                                $hours = (int) floor($durationSeconds / 3600);
                                                $mins = (int) floor(($durationSeconds % 3600) / 60);
                                                $secs = (int) ($durationSeconds % 60);
                                                $durationLabel =
                                                    $durationSeconds > 0
                                                        ? ($hours > 0
                                                            ? sprintf('%dh %02dm %02ds', $hours, $mins, $secs)
                                                            : sprintf('%dm %02ds', $mins, $secs))
                                                        : '—';
                                                if ($chaptersWithError > 0) {
                                                    $statusLabel = 'Lỗi';
                                                    $statusClass = 'bg-red-100 text-red-700';
                                                } elseif ($chaptersTotal > 0 && $chaptersWithVideo >= $chaptersTotal) {
                                                    $statusLabel = 'Hoàn tất';
                                                    $statusClass = 'bg-green-100 text-green-700';
                                                } elseif ($chaptersWithAudio > 0) {
                                                    $statusLabel = 'Đang xử lý';
                                                    $statusClass = 'bg-amber-100 text-amber-700';
                                                } else {
                                                    $statusLabel = 'Chưa bắt đầu';
                                                    $statusClass = 'bg-gray-100 text-gray-600';
                                                }
                                            @endphp
                                            <div
                                                class="flex flex-col md:flex-row md:items-center gap-4 p-4 hover:bg-gray-50">
                                                <div class="flex items-center gap-4 flex-1">
                                                    <div
                                                        class="w-20 h-28 bg-gray-100 rounded-lg overflow-hidden flex items-center justify-center shadow-sm border border-gray-200">
                                                        @if ($audioBook->cover_image)
                                                            <img src="{{ asset('storage/' . $audioBook->cover_image) }}"
                                                                alt="Cover" class="w-full h-full object-cover">
                                                        @else
                                                            <div
                                                                class="w-14 h-20 bg-gradient-to-br from-blue-400 to-blue-600 rounded-md flex items-center justify-center text-white">
                                                                <i class="ri-book-line"></i>
                                                            </div>
                                                        @endif
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="flex items-center gap-2">
                                                            <h4 class="text-sm font-semibold text-gray-900 truncate">
                                                                {{ $audioBook->title }}
                                                            </h4>
                                                            <span
                                                                class="text-xs px-2 py-0.5 rounded-full {{ $statusClass }}">{{ $statusLabel }}</span>
                                                        </div>
                                                        <div
                                                            class="flex flex-wrap items-center gap-3 mt-1 text-xs text-gray-500">
                                                            <span>{{ $chaptersTotal }} chương</span>
                                                            <span>⏱️ {{ $durationLabel }}</span>
                                                        </div>
                                                        @if ($audioBook->description)
                                                            <p class="text-xs text-gray-600 mt-2 line-clamp-2">
                                                                {{ $audioBook->description }}
                                                            </p>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <a href="{{ route('audiobooks.show', $audioBook) }}"
                                                        class="px-3 py-1.5 bg-blue-600 text-white rounded text-xs font-medium hover:bg-blue-700">
                                                        View
                                                    </a>
                                                    <a href="{{ route('audiobooks.edit', $audioBook) }}"
                                                        class="px-3 py-1.5 bg-gray-200 text-gray-800 rounded text-xs font-medium hover:bg-gray-300">
                                                        Edit
                                                    </a>
                                                    <form action="{{ route('audiobooks.destroy', $audioBook) }}"
                                                        method="POST">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                            class="px-3 py-1.5 bg-red-100 text-red-700 rounded text-xs font-medium hover:bg-red-200"
                                                            onclick="return confirm('Xóa audiobook này?');">
                                                            Xóa
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="mt-6">
                                        {{ $audioBooks->appends(request()->query())->links() }}
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($youtubeChannel->content_type === 'self_creative')
                            <!-- Self Creative Tab -->
                            <div id="tabSelfCreative" class="tab-content">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-semibold">✨ Nội dung sáng tạo</h3>
                                    <a href="{{ route('youtube-channels.contents.create', $youtubeChannel) }}"
                                        class="bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                        + Tạo nội dung mới
                                    </a>
                                </div>

                                <div class="text-center py-8 text-gray-600">
                                    <div class="text-4xl mb-4">✨</div>
                                    <p class="text-lg mb-2">Kênh Self Creative</p>
                                    <p class="text-sm text-gray-500">Tạo nội dung từ kịch bản của riêng bạn</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div id="newVideoModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">Tạo Video Mới</h3>
                    <button id="closeNewVideoModal" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
                </div>

                <!-- Tab buttons -->
                <div class="flex gap-2 mb-5 border-b border-gray-200">
                    <button type="button" id="tabLongTiengBtn"
                        class="pb-2 px-1 text-sm font-medium border-b-2 border-red-600 text-gray-900">
                        🎙 Lồng tiếng
                    </button>
                    <button type="button" id="tabKichBanBtn"
                        class="pb-2 px-1 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                        ✍️ Kịch bản của tôi
                    </button>
                    <button type="button" id="tabAudiobookBtn"
                        class="pb-2 px-1 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                        📖 Audiobook
                    </button>
                </div>

                <!-- Tab: Lồng tiếng -->
                <div id="tabLongTieng">
                    <p class="text-sm text-gray-600 mb-3">Nhập URL video (YouTube, Bilibili, ...)</p>
                    <input id="newVideoUrl" type="url" placeholder="https://www.youtube.com/watch?v=... hoặc https://www.bilibili.com/video/..."
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-3 focus:outline-none focus:ring-2 focus:ring-red-400" />
                    <div id="newVideoError" class="hidden text-red-600 text-sm mb-3"></div>
                    <div id="newVideoPreview" class="hidden bg-gray-50 border border-gray-200 rounded-lg p-3 mb-3 text-sm text-gray-700"></div>
                    <button id="newVideoSubmitBtn"
                        class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition">
                        🚀 Lấy thông tin & tạo dự án
                    </button>
                    <div id="newVideoProgress" class="hidden mt-3 flex items-center gap-2 text-sm text-gray-600">
                        <div class="w-4 h-4 border-2 border-gray-300 border-t-red-600 rounded-full animate-spin"></div>
                        <span id="newVideoProgressText">Đang xử lý...</span>
                    </div>
                </div>

                <!-- Tab: Kịch bản -->
                <div id="tabKichBan" class="hidden">
                    <a href="{{ route('youtube-channels.contents.create', $youtubeChannel) }}"
                        class="block w-full text-center bg-gray-800 hover:bg-gray-900 text-white font-semibold py-2 px-4 rounded-lg transition">
                        Tạo từ kịch bản của tôi →
                    </a>
                </div>

                <!-- Tab: Audiobook -->
                <div id="tabAudiobook" class="hidden">
                    <a href="{{ route('audiobooks.create') }}?youtube_channel_id={{ $youtubeChannel->id }}"
                        class="block w-full text-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition">
                        Tạo Audiobook →
                    </a>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Handle per_page selection
                const perPageSelect = document.getElementById('perPageSelect');
                if (perPageSelect) {
                    perPageSelect.addEventListener('change', function() {
                        const perPage = this.value;
                        const url = new URL(window.location);
                        url.searchParams.set('per_page', perPage);
                        url.searchParams.set('page', 1); // Reset to page 1
                        window.location.href = url.toString();
                    });
                }

                const modal = document.getElementById('newVideoModal');
                const openBtns = [document.getElementById('newVideoBtn'), document.getElementById('newVideoBtnInline')];
                const closeBtn = document.getElementById('closeNewVideoModal');
                const tabDubsyncBtn = document.getElementById('tabDubsyncBtn');
                const tabAudiobooksBtn = document.getElementById('tabAudiobooksBtn');
                const tabSelfCreativeBtn = document.getElementById('tabSelfCreativeBtn');
                const tabDubsync = document.getElementById('tabDubsync');
                const tabAudiobooks = document.getElementById('tabAudiobooks');
                const tabSelfCreative = document.getElementById('tabSelfCreative');

                const setActiveTab = (active) => {
                    const tabs = {
                        dubsync: {
                            btn: tabDubsyncBtn,
                            content: tabDubsync
                        },
                        audiobooks: {
                            btn: tabAudiobooksBtn,
                            content: tabAudiobooks
                        },
                        selfCreative: {
                            btn: tabSelfCreativeBtn,
                            content: tabSelfCreative
                        }
                    };

                    Object.keys(tabs).forEach(key => {
                        const {
                            btn,
                            content
                        } = tabs[key];
                        if (!btn || !content) return;

                        if (key === active) {
                            content.classList.remove('hidden');
                            btn.classList.add('border-red-600', 'text-gray-900');
                            btn.classList.remove('border-transparent', 'text-gray-500');
                        } else {
                            content.classList.add('hidden');
                            btn.classList.add('border-transparent', 'text-gray-500');
                            btn.classList.remove('border-red-600', 'text-gray-900');
                        }
                    });
                };

                if (tabDubsyncBtn) {
                    tabDubsyncBtn.addEventListener('click', () => setActiveTab('dubsync'));
                }

                if (tabAudiobooksBtn) {
                    tabAudiobooksBtn.addEventListener('click', () => setActiveTab('audiobooks'));
                }

                if (tabSelfCreativeBtn) {
                    tabSelfCreativeBtn.addEventListener('click', () => setActiveTab('selfCreative'));
                }

                // Set default active tab based on channel content type
                const contentType = '{{ $youtubeChannel->content_type }}';
                if (contentType === 'dub' && tabDubsyncBtn) {
                    setActiveTab('dubsync');
                } else if (contentType === 'audiobook' && tabAudiobooksBtn) {
                    setActiveTab('audiobooks');
                } else if (contentType === 'self_creative' && tabSelfCreativeBtn) {
                    setActiveTab('selfCreative');
                }

                const audioBooksGridBtn = document.getElementById('audioBooksGridBtn');
                const audioBooksListBtn = document.getElementById('audioBooksListBtn');
                const audioBooksGrid = document.getElementById('audioBooksGrid');
                const audioBooksList = document.getElementById('audioBooksList');

                const setAudioBooksView = (mode) => {
                    if (!audioBooksGrid || !audioBooksList || !audioBooksGridBtn || !audioBooksListBtn) return;
                    const isList = mode === 'list';
                    audioBooksGrid.classList.toggle('hidden', isList);
                    audioBooksList.classList.toggle('hidden', !isList);

                    audioBooksGridBtn.classList.toggle('bg-gray-900', !isList);
                    audioBooksGridBtn.classList.toggle('text-white', !isList);
                    audioBooksGridBtn.classList.toggle('text-gray-600', isList);
                    audioBooksListBtn.classList.toggle('bg-gray-900', isList);
                    audioBooksListBtn.classList.toggle('text-white', isList);
                    audioBooksListBtn.classList.toggle('text-gray-600', !isList);

                    localStorage.setItem('audiobookViewMode', mode);
                };

                if (audioBooksGridBtn && audioBooksListBtn) {
                    audioBooksGridBtn.addEventListener('click', () => setAudioBooksView('grid'));
                    audioBooksListBtn.addEventListener('click', () => setAudioBooksView('list'));
                    const savedMode = localStorage.getItem('audiobookViewMode') || 'grid';
                    setAudioBooksView(savedMode);
                }

                openBtns.forEach((btn) => {
                    if (!btn) return;
                    btn.addEventListener('click', () => {
                        modal.classList.remove('hidden');
                        modal.classList.add('flex');
                    });
                });

                if (closeBtn) {
                    closeBtn.addEventListener('click', () => {
                        modal.classList.add('hidden');
                        modal.classList.remove('flex');
                    });
                }

                if (modal) {
                    modal.addEventListener('click', (e) => {
                        if (e.target === modal) {
                            modal.classList.add('hidden');
                            modal.classList.remove('flex');
                        }
                    });
                }

                // ── New Video Modal tabs ──────────────────────────────────────
                const modalTabs = {
                    tabLongTieng: { btn: document.getElementById('tabLongTiengBtn'), panel: document.getElementById('tabLongTieng') },
                    tabKichBan:   { btn: document.getElementById('tabKichBanBtn'),   panel: document.getElementById('tabKichBan') },
                    tabAudiobook: { btn: document.getElementById('tabAudiobookBtn'), panel: document.getElementById('tabAudiobook') },
                };
                const switchModalTab = (active) => {
                    Object.entries(modalTabs).forEach(([key, { btn, panel }]) => {
                        if (!btn || !panel) return;
                        if (key === active) {
                            panel.classList.remove('hidden');
                            btn.classList.add('border-red-600', 'text-gray-900');
                            btn.classList.remove('border-transparent', 'text-gray-500');
                        } else {
                            panel.classList.add('hidden');
                            btn.classList.remove('border-red-600', 'text-gray-900');
                            btn.classList.add('border-transparent', 'text-gray-500');
                        }
                    });
                };
                Object.entries(modalTabs).forEach(([key, { btn }]) => {
                    if (btn) btn.addEventListener('click', () => switchModalTab(key));
                });

                // ── New Video submit (Lồng tiếng) ─────────────────────────────
                const newVideoSubmitBtn  = document.getElementById('newVideoSubmitBtn');
                const newVideoUrl        = document.getElementById('newVideoUrl');
                const newVideoError      = document.getElementById('newVideoError');
                const newVideoProgress   = document.getElementById('newVideoProgress');
                const newVideoProgressText = document.getElementById('newVideoProgressText');

                if (newVideoSubmitBtn) {
                    newVideoSubmitBtn.addEventListener('click', async () => {
                        const url = newVideoUrl.value.trim();
                        newVideoError.classList.add('hidden');
                        if (!url) { newVideoError.textContent = 'Vui lòng nhập URL.'; newVideoError.classList.remove('hidden'); return; }

                        newVideoSubmitBtn.disabled = true;
                        newVideoProgress.classList.remove('hidden');
                        newVideoProgressText.textContent = 'Đang lấy thông tin video...';

                        try {
                            const res = await fetch('{{ route('dubsync.process.youtube') }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                },
                                body: JSON.stringify({
                                    youtube_url: url,
                                    youtube_channel_id: {{ $youtubeChannel->id }},
                                }),
                            });
                            const data = await res.json();
                            if (!res.ok || data.error) throw new Error(data.error || 'Lỗi không xác định');

                            newVideoProgressText.textContent = '✅ Xong! Đang chuyển hướng...';
                            window.location.href = '/projects/' + data.project_id + '/edit';
                        } catch (e) {
                            newVideoError.textContent = '❌ ' + e.message;
                            newVideoError.classList.remove('hidden');
                            newVideoProgress.classList.add('hidden');
                            newVideoSubmitBtn.disabled = false;
                        }
                    });

                    // Submit on Enter
                    newVideoUrl.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') newVideoSubmitBtn.click();
                    });
                }

                const selectAll = document.getElementById('selectAllProjects');
                const checkboxes = Array.from(document.querySelectorAll('.project-checkbox'));
                const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
                const bulkDeleteForm = document.getElementById('bulkDeleteForm');
                const bulkDeleteInputs = document.getElementById('bulkDeleteInputs');
                const bulkTranscriptBtn = document.getElementById('bulkTranscriptBtn');
                const bulkDownloadBtn = document.getElementById('bulkDownloadBtn');
                const bulkDownloadProgressPanel = document.getElementById('bulkDownloadProgressPanel');
                const bulkDownloadProgressList = document.getElementById('bulkDownloadProgressList');
                const bulkDownloadSummary = document.getElementById('bulkDownloadSummary');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                let isBulkDownloading = false;

                const updateBulkState = () => {
                    const checked = checkboxes.filter((cb) => cb.checked);
                    const checkedBilibiliReady = checked.filter((cb) => {
                        const row = cb.closest('tr');
                        if (!row) return false;

                        const status = String(row?.dataset?.status || '').toLowerCase();
                        const isBilibili = row?.dataset?.isBilibili === '1';
                        const eligibleStatuses = ['new', 'source_downloaded', 'error'];

                        return isBilibili && eligibleStatuses.includes(status);
                    });
                    if (bulkDeleteBtn) {
                        bulkDeleteBtn.disabled = checked.length === 0;
                        bulkDeleteBtn.classList.toggle('opacity-60', checked.length === 0);
                        bulkDeleteBtn.classList.toggle('cursor-not-allowed', checked.length === 0);
                    }
                    if (bulkTranscriptBtn) {
                        bulkTranscriptBtn.disabled = checkedBilibiliReady.length === 0;
                        bulkTranscriptBtn.classList.toggle('opacity-60', checkedBilibiliReady.length === 0);
                        bulkTranscriptBtn.classList.toggle('cursor-not-allowed', checkedBilibiliReady.length === 0);
                        bulkTranscriptBtn.textContent = `Get transcript (${checkedBilibiliReady.length})`;
                    }
                    if (bulkDownloadBtn) {
                        const canDownload = checked.length > 0 && !isBulkDownloading;
                        bulkDownloadBtn.disabled = !canDownload;
                        bulkDownloadBtn.classList.toggle('opacity-60', !canDownload);
                        bulkDownloadBtn.classList.toggle('cursor-not-allowed', !canDownload);
                        bulkDownloadBtn.textContent = isBulkDownloading
                            ? 'Downloading...'
                            : `Download selected (${checked.length})`;
                    }
                    if (selectAll) {
                        selectAll.checked = checked.length > 0 && checked.length === checkboxes.length;
                        selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
                    }
                };

                if (selectAll) {
                    selectAll.addEventListener('change', () => {
                        checkboxes.forEach((cb) => {
                            cb.checked = selectAll.checked;
                        });
                        updateBulkState();
                    });
                }

                checkboxes.forEach((cb) => {
                    cb.addEventListener('change', updateBulkState);
                });

                if (bulkDeleteBtn && bulkDeleteForm) {
                    bulkDeleteBtn.addEventListener('click', () => {
                        if (bulkDeleteBtn.disabled) return;
                        if (confirm('Delete selected projects?')) {
                            if (bulkDeleteInputs) {
                                bulkDeleteInputs.innerHTML = '';
                                checkboxes
                                    .filter((cb) => cb.checked)
                                    .forEach((cb) => {
                                        const input = document.createElement('input');
                                        input.type = 'hidden';
                                        input.name = 'project_ids[]';
                                        input.value = cb.dataset.projectId;
                                        bulkDeleteInputs.appendChild(input);
                                    });
                            }
                            bulkDeleteForm.submit();
                        }
                    });
                }

                if (bulkTranscriptBtn) {
                    bulkTranscriptBtn.addEventListener('click', async () => {
                        if (bulkTranscriptBtn.disabled) return;

                        const selected = checkboxes.filter((cb) => cb.checked);
                        const selectedBilibiliReady = selected.filter((cb) => {
                            const row = cb.closest('tr');
                            if (!row) return false;

                            const status = String(row?.dataset?.status || '').toLowerCase();
                            const isBilibili = row?.dataset?.isBilibili === '1';
                            const eligibleStatuses = ['new', 'source_downloaded', 'error'];

                            return isBilibili && eligibleStatuses.includes(status);
                        });
                        if (selectedBilibiliReady.length === 0) {
                            alert('Vui long chon video Bilibili co trang thai New hoac Error.');
                            return;
                        }

                        const shouldContinue = confirm(
                            `Lay transcript cho ${selectedBilibiliReady.length} video Bilibili da chon?`
                        );
                        if (!shouldContinue) return;

                        bulkTranscriptBtn.disabled = true;
                        bulkTranscriptBtn.classList.add('opacity-60', 'cursor-not-allowed');
                        bulkTranscriptBtn.textContent = 'Getting transcript...';

                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute(
                            'content');
                        let successCount = 0;
                        let failCount = 0;

                        for (const cb of selectedBilibiliReady) {
                            const row = cb.closest('tr');
                            const spinner = row?.querySelector('.row-spinner');
                            const statusEl = row?.querySelector('.project-status');
                            const url = row?.dataset?.transcriptUrl;

                            if (!url) continue;

                            if (spinner) {
                                spinner.classList.remove('hidden');
                                spinner.classList.add('inline-flex');
                            }
                            if (statusEl) {
                                statusEl.textContent = 'Processing';
                            }

                            try {
                                const response = await fetch(url, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': csrfToken || '',
                                        'Accept': 'application/json',
                                    },
                                    body: JSON.stringify({})
                                });

                                if (response.ok) {
                                    const data = await response.json();
                                    if (statusEl) {
                                        statusEl.textContent = data.status ? data.status.replace('_', ' ') :
                                            'Transcribed';
                                    }
                                    if (row) {
                                        row.dataset.status = data.status || 'transcribed';
                                    }
                                    successCount++;
                                } else {
                                    if (statusEl) {
                                        statusEl.textContent = 'Error';
                                    }
                                    if (row) {
                                        row.dataset.status = 'error';
                                    }
                                    failCount++;
                                }
                            } catch (e) {
                                if (statusEl) {
                                    statusEl.textContent = 'Error';
                                }
                                if (row) {
                                    row.dataset.status = 'error';
                                }
                                failCount++;
                            } finally {
                                if (spinner) {
                                    spinner.classList.add('hidden');
                                    spinner.classList.remove('inline-flex');
                                }
                            }
                        }

                        if (failCount > 0) {
                            alert(`Hoan tat: ${successCount} thanh cong, ${failCount} loi.`);
                        }

                        updateBulkState();
                    });
                }

                // ── Bulk Download ────────────────────────────────────────────
                const createDownloadRow = (projectId, title) => {
                    if (!bulkDownloadProgressList) return;
                    const row = document.createElement('div');
                    row.className = 'bg-white border border-blue-100 rounded p-2';
                    row.dataset.projectId = projectId;
                    const safeTitle = String(title).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                    row.innerHTML = `
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <div class="text-xs font-medium text-gray-800 truncate">${safeTitle}</div>
                            <div class="text-[11px] text-gray-500" data-role="status">Waiting...</div>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                            <div data-role="bar" class="h-full bg-blue-500 transition-all duration-500" style="width:0%"></div>
                        </div>
                        <div class="mt-1 text-[11px] text-gray-600 truncate" data-role="message">Đang chờ...</div>`;
                    bulkDownloadProgressList.appendChild(row);
                };

                const updateDownloadRow = (projectId, progress) => {
                    const row = bulkDownloadProgressList?.querySelector(`[data-project-id="${projectId}"]`);
                    if (!row) return;
                    const pct = Math.max(0, Math.min(100, progress.percent || 0));
                    const bar = row.querySelector('[data-role="bar"]');
                    if (bar) {
                        bar.style.width = `${pct}%`;
                        bar.className = `h-full transition-all duration-500 ${progress.status === 'completed' ? 'bg-green-500' : progress.status === 'error' ? 'bg-red-500' : 'bg-blue-500'}`;
                    }
                    const statusEl = row.querySelector('[data-role="status"]');
                    if (statusEl) statusEl.textContent = `${Math.floor(pct)}%`;
                    const msgEl = row.querySelector('[data-role="message"]');
                    if (msgEl) msgEl.textContent = (progress.message || '') + (progress.speed ? ` • ${progress.speed}` : '');
                };

                const downloadOneProject = (projectId, title) => new Promise(async (resolve) => {
                    updateDownloadRow(projectId, { status: 'processing', percent: 0, message: 'Đang xếp hàng...' });
                    try {
                        const res  = await fetch(`/dubsync/projects/${projectId}/download-youtube-video`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                            body: JSON.stringify({}),
                        });
                        const data = await res.json();
                        if (!res.ok || !data.success) throw new Error(data.error || 'Lỗi tải video');

                        if (!data.queued) {
                            updateDownloadRow(projectId, { status: 'completed', percent: 100, message: '✅ Video đã tồn tại' });
                            return resolve({ success: true });
                        }

                        const timer = setInterval(async () => {
                            try {
                                const pr = await fetch(`/dubsync/projects/${projectId}/download-youtube-video-progress`, {
                                    headers: { 'X-CSRF-TOKEN': csrfToken }
                                });
                                const pj = await pr.json();
                                const progress = pj?.progress || {};
                                updateDownloadRow(projectId, progress);
                                if (progress.status === 'completed') { clearInterval(timer); resolve({ success: true }); }
                                else if (progress.status === 'error') { clearInterval(timer); resolve({ success: false }); }
                            } catch (e) {}
                        }, 1500);
                    } catch (err) {
                        updateDownloadRow(projectId, { status: 'error', percent: 100, message: `❌ ${err.message}` });
                        resolve({ success: false });
                    }
                });

                if (bulkDownloadBtn) {
                    bulkDownloadBtn.addEventListener('click', async () => {
                        if (isBulkDownloading) return;
                        const selected = checkboxes.filter(cb => cb.checked);
                        if (selected.length === 0) return;
                        if (!confirm(`Tải source video cho ${selected.length} project đã chọn?`)) return;

                        isBulkDownloading = true;
                        updateBulkState();
                        if (bulkDownloadProgressPanel) bulkDownloadProgressPanel.classList.remove('hidden');
                        if (bulkDownloadProgressList) bulkDownloadProgressList.innerHTML = '';
                        if (bulkDownloadSummary) bulkDownloadSummary.textContent = `Đang xếp hàng ${selected.length} video...`;

                        const projects = selected.map(cb => ({
                            id: cb.dataset.projectId,
                            title: cb.closest('tr')?.querySelector('td:nth-child(2)')?.textContent?.trim() || `Project #${cb.dataset.projectId}`
                        }));
                        projects.forEach(p => createDownloadRow(p.id, p.title));

                        const results = await Promise.all(projects.map(p => downloadOneProject(p.id, p.title)));
                        const ok = results.filter(r => r.success).length;
                        if (bulkDownloadSummary) bulkDownloadSummary.textContent = `Hoàn tất: ${ok} thành công, ${results.length - ok} lỗi`;

                        isBulkDownloading = false;
                        updateBulkState();
                    });
                }
                // ─────────────────────────────────────────────────────────────

                const rowLinks = document.querySelectorAll('tr[data-href]');
                rowLinks.forEach((row) => {
                    row.addEventListener('click', (e) => {
                        const interactive = e.target.closest('a, button, input, form, label');
                        if (interactive) return;
                        const href = row.dataset.href;
                        if (href) {
                            window.location.href = href;
                        }
                    });
                });

                updateBulkState();

                // Handle Retry Transcript button
                const retryButtons = document.querySelectorAll('.retry-transcript-btn');
                retryButtons.forEach((btn) => {
                    btn.addEventListener('click', async (e) => {
                        e.preventDefault();
                        e.stopPropagation();

                        const projectId = btn.dataset.projectId;
                        const transcriptUrl = btn.dataset.transcriptUrl;
                        const row = btn.closest('tr');
                        const statusEl = row?.querySelector('.project-status');
                        const spinner = row?.querySelector('.row-spinner');

                        if (!transcriptUrl) {
                            alert('Missing transcript URL');
                            return;
                        }

                        if (!confirm('Retry fetching transcript for this project?')) {
                            return;
                        }

                        btn.disabled = true;
                        btn.innerHTML = '<i class="ri-loader-4-line animate-spin"></i> Retrying...';

                        if (spinner) {
                            spinner.classList.remove('hidden');
                            spinner.classList.add('inline-flex');
                        }
                        if (statusEl) {
                            statusEl.textContent = 'Processing';
                        }

                        try {
                            const response = await fetch(transcriptUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector(
                                        'meta[name="csrf-token"]')?.content || '',
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({})
                            });

                            const data = await response.json();

                            if (response.ok && data.success) {
                                if (statusEl) {
                                    statusEl.textContent = data.status ? data.status.replace('_',
                                        ' ') : 'Transcribed';
                                }
                                // Remove retry button on success, reload page to show updated state
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1000);
                            } else {
                                if (statusEl) {
                                    statusEl.textContent = 'Error';
                                }
                                alert('Failed to retry: ' + (data.error || 'Unknown error'));
                                btn.disabled = false;
                                btn.innerHTML = '<i class="ri-refresh-line"></i> Retry';
                            }
                        } catch (e) {
                            if (statusEl) {
                                statusEl.textContent = 'Error';
                            }
                            alert('Error retrying transcript: ' + e.message);
                            btn.disabled = false;
                            btn.innerHTML = '<i class="ri-refresh-line"></i> Retry';
                        } finally {
                            if (spinner) {
                                spinner.classList.add('hidden');
                                spinner.classList.remove('inline-flex');
                            }
                        }
                    });
                });
            });
        </script>
    @endsection
