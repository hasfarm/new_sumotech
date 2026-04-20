<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Videos of') }} {{ $youtubeChannel->title }}
            </h2>
            <div class="flex gap-2">
                <a href="{{ route('youtube-channels.contents.create', $youtubeChannel) }}"
                    class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                    + New Video
                </a>
                <a href="{{ route('youtube-channels.show', $youtubeChannel) }}"
                    class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                    Back to channel
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if ($message = Session::get('success'))
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    <span class="block sm:inline">{{ $message }}</span>
                    <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3"
                        onclick="this.parentElement.style.display='none';">
                        <span class="text-2xl leading-none">&times;</span>
                    </button>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if ($contents->count() === 0)
                        <div class="text-center py-12">
                            <p class="text-gray-600">No videos yet.</p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Video</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Video ID</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Published</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Views</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                                            Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach ($contents as $content)
                                        <tr>
                                            <td class="px-4 py-3 text-sm text-gray-900">
                                                <div class="flex items-center gap-3">
                                                    @if ($content->thumbnail_url)
                                                        @php
                                                            $contentThumb = $content->thumbnail_url;
                                                            $contentThumbHost = strtolower((string) parse_url($contentThumb, PHP_URL_HOST));
                                                            $useContentThumbProxy = $contentThumbHost !== ''
                                                                && (str_ends_with($contentThumbHost, '.biliimg.com')
                                                                    || str_ends_with($contentThumbHost, '.hdslb.com')
                                                                    || in_array($contentThumbHost, ['archive.biliimg.com', 'i0.hdslb.com', 'i1.hdslb.com', 'i2.hdslb.com', 'i.ytimg.com', 'img.youtube.com', 'yt3.ggpht.com'], true));
                                                            $contentThumbSrc = $useContentThumbProxy
                                                                ? route('youtube-channels.thumbnail.proxy', ['youtubeChannel' => $youtubeChannel, 'url' => $contentThumb])
                                                                : $contentThumb;
                                                        @endphp
                                                        <img src="{{ $contentThumbSrc }}" alt="Thumbnail"
                                                            class="w-12 h-8 rounded object-cover border">
                                                    @else
                                                        <div
                                                            class="w-12 h-8 rounded bg-gray-100 flex items-center justify-center text-gray-400">
                                                            <i class="ri-image-line"></i>
                                                        </div>
                                                    @endif
                                                    <div class="min-w-0">
                                                        <div class="truncate font-medium">{{ $content->title }}</div>
                                                        @if ($content->video_url)
                                                            <a href="{{ $content->video_url }}" target="_blank"
                                                                class="text-xs text-red-600 hover:text-red-700">Open
                                                                video</a>
                                                        @endif
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-600">{{ $content->video_id }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-600">
                                                {{ $content->published_at?->format('d/m/Y') ?? '—' }}
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-600">
                                                {{ number_format($content->views_count ?? 0) }}
                                            </td>
                                            <td class="px-4 py-3 text-right text-sm">
                                                <div class="inline-flex gap-2">
                                                    <a href="{{ route('youtube-channels.contents.show', [$youtubeChannel, $content->id]) }}"
                                                        class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded hover:bg-gray-200">View</a>
                                                    <a href="{{ route('youtube-channels.contents.edit', [$youtubeChannel, $content->id]) }}"
                                                        class="px-3 py-1.5 bg-blue-600 text-white rounded hover:bg-blue-700">Edit</a>
                                                    <form
                                                        action="{{ route('youtube-channels.contents.destroy', [$youtubeChannel, $content->id]) }}"
                                                        method="POST" onsubmit="return confirm('Delete this video?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                            class="px-3 py-1.5 bg-red-600 text-white rounded hover:bg-red-700">
                                                            Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-6">
                            {{ $contents->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
