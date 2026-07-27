@extends('layouts.app')

@section('content')
    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-between items-center mb-6">
                <h2 class="font-semibold text-2xl text-gray-800">📚 Tạo Sách Mới</h2>
                <div class="flex items-center gap-2">
                    <button type="button" id="openBulkModal"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                        Tạo nhiều sách
                    </button>
                    <a href="{{ route('youtube-channels.index') }}"
                        class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                        Quay lại
                    </a>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('audiobooks.store') }}" enctype="multipart/form-data">
                        @csrf

                        <!-- Hidden fields for book URL and source (used for auto-scraping chapters) -->
                        <input type="hidden" name="book_url" id="bookUrlHidden">
                        <input type="hidden" name="book_source" id="bookSourceHidden">
                        <input type="hidden" name="cover_image_url" id="coverImageUrlHidden">
                        <input type="hidden" name="youtube_transcript_content" id="youtubeTranscriptContentHidden">

                        <!-- URL Auto-Fill Section -->
                        <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                            <label class="block text-sm font-medium text-blue-900 mb-2">🔗 Tự động điền thông tin từ
                                URL</label>
                            <div class="flex gap-2">
                                <input type="url" id="bookUrl"
                                    class="flex-1 px-3 py-2 border border-blue-300 rounded-lg text-sm"
                                    placeholder="Nhập URL sách (ví dụ: https://docsach24.co/e-book/dao-giau-vang-118.html)">
                                <button type="button" id="fetchMetadataBtn"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                    Lấy thông tin
                                </button>
                            </div>
                            <p class="text-xs text-blue-700 mt-2">
                                Hỗ trợ: {{ implode(', ', array_column($scrapeSources, 'label')) }}
                            </p>
                            <div id="fetchStatus" class="mt-2 text-sm"></div>

                            <!-- Cover Image Preview -->
                            <div id="coverImagePreview" class="mt-3 hidden">
                                <p class="text-xs font-medium text-blue-900 mb-2">📷 Ảnh bìa từ URL:</p>
                                <img id="coverImagePreviewImg" src="" alt="Cover"
                                    class="w-32 h-auto rounded-lg border-2 border-blue-300">
                            </div>
                        </div>

                        <!-- YouTube Transcript Section -->
                        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <label class="block text-sm font-medium text-red-900 mb-2">🎬 Lấy transcript từ video
                                YouTube (làm Chương 1)</label>
                            <div class="flex gap-2">
                                <input type="url" id="youtubeUrl"
                                    class="flex-1 px-3 py-2 border border-red-300 rounded-lg text-sm"
                                    placeholder="https://www.youtube.com/watch?v=...">
                                <button type="button" id="fetchYoutubeTranscriptBtn"
                                    class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                    Lấy transcript
                                </button>
                            </div>
                            <p class="text-xs text-red-700 mt-2">
                                Ưu tiên lấy phụ đề có sẵn của YouTube; nếu video không có phụ đề, tự động chuyển
                                audio thành text bằng AI (Gemini) — tối đa 20 phút đầu.
                            </p>
                            <div id="youtubeTranscriptStatus" class="mt-2 text-sm"></div>
                            <div id="youtubeTranscriptPreviewWrap" class="mt-3 hidden">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="text-xs font-medium text-red-900">📄 Nội dung Chương 1 (có thể sửa
                                        trước khi lưu):</p>
                                    <button type="button" id="clearYoutubeTranscriptBtn"
                                        class="text-xs text-red-600 hover:underline">Xóa</button>
                                </div>
                                <textarea id="youtubeTranscriptPreview" rows="8"
                                    class="w-full px-3 py-2 border border-red-300 rounded-lg text-sm"></textarea>
                            </div>
                        </div>

                        <!-- Channel Selection -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Chọn Channel 📺</label>
                            <select name="youtube_channel_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('youtube_channel_id') border-red-500 @enderror"
                                required>
                                <option value="">-- Chọn Channel --</option>
                                @foreach ($youtubeChannels as $channel)
                                    <option value="{{ $channel->id }}"
                                        {{ request('youtube_channel_id') == $channel->id ? 'selected' : '' }}>
                                        {{ $channel->title }}
                                    </option>
                                @endforeach
                            </select>
                            @error('youtube_channel_id')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Title -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Tiêu đề sách</label>
                            <input type="text" name="title"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('title') border-red-500 @enderror"
                                placeholder="Nhập tiêu đề sách" value="{{ old('title') }}" required>
                            @error('title')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Author -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Tác giả</label>
                            <input type="text" name="author"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('author') border-red-500 @enderror"
                                placeholder="Nhập tên tác giả" value="{{ old('author') }}">
                            @error('author')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                            <div class="mt-2 text-xs text-gray-500">
                                Các sách cùng tác giả sẽ hiển thị sau khi lưu.
                            </div>
                        </div>

                        <!-- Same Author Books -->
                        @if (!empty($authorBooks) && $authorBooks->count() > 0)
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Sách cùng tác giả</label>
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm">
                                    <ul class="list-disc pl-5 space-y-1">
                                        @foreach ($authorBooks as $book)
                                            <li>
                                                <a href="{{ route('audiobooks.show', $book) }}"
                                                    class="text-blue-600 hover:text-blue-800">
                                                    {{ $book->title }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif

                        <!-- Book Type -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Phân loại</label>
                            @php
                                $bookTypeOptions = [
                                    'sach' => 'Sách',
                                    'truyen' => 'Truyện',
                                    'tieu_thuyet' => 'Tiểu thuyết',
                                    'truyen_ngan' => 'Truyện ngắn',
                                ];
                                $selectedBookType = old('book_type', 'sach');
                                $bookTypeIsCustom =
                                    $selectedBookType && !array_key_exists($selectedBookType, $bookTypeOptions);
                            @endphp
                            <select name="book_type" id="bookTypeSelect"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('book_type') border-red-500 @enderror">
                                @foreach ($bookTypeOptions as $value => $label)
                                    <option value="{{ $value }}"
                                        {{ $selectedBookType == $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                                <option value="custom" {{ $bookTypeIsCustom ? 'selected' : '' }}>Khác (tự nhập)</option>
                            </select>
                            <div id="bookTypeCustomWrap" class="mt-2 {{ $bookTypeIsCustom ? '' : 'hidden' }}">
                                <input type="text" name="book_type_custom" id="bookTypeCustom"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                    placeholder="Nhập phân loại..."
                                    value="{{ $bookTypeIsCustom ? $selectedBookType : '' }}">
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Chọn từ danh sách hoặc tự nhập phân loại mới.</p>
                            @error('book_type')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                            @error('book_type_custom')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Category -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Thể loại</label>
                            @php
                                $selectedCategory = old('category', '');
                                $categoryIsCustom =
                                    $selectedCategory !== '' && !$categoryOptions->contains($selectedCategory);
                            @endphp
                            <select name="category" id="categorySelect"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('category') border-red-500 @enderror">
                                <option value="">-- Chọn thể loại --</option>
                                @foreach ($categoryOptions as $category)
                                    <option value="{{ $category }}"
                                        {{ $selectedCategory == $category ? 'selected' : '' }}>
                                        {{ $category }}
                                    </option>
                                @endforeach
                                <option value="custom" {{ $categoryIsCustom ? 'selected' : '' }}>Khác (tự nhập)</option>
                            </select>
                            <div id="categoryCustomWrap" class="mt-2 {{ $categoryIsCustom ? '' : 'hidden' }}">
                                <input type="text" name="category_custom" id="categoryCustom"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                    placeholder="Nhập thể loại..."
                                    value="{{ $categoryIsCustom ? $selectedCategory : '' }}">
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Chọn từ danh sách hoặc tự nhập thể loại mới.</p>
                            @error('category')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                            @error('category_custom')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Description -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Mô tả</label>
                            <textarea name="description" rows="4"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('description') border-red-500 @enderror"
                                placeholder="Nhập mô tả sách...">{{ old('description') }}</textarea>
                            @error('description')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Cover Image -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Ảnh bìa sách</label>
                            <input type="file" name="cover_image" accept="image/*"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('cover_image') border-red-500 @enderror">
                            @error('cover_image')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Language -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Ngôn ngữ</label>
                            <select name="language"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('language') border-red-500 @enderror"
                                required>
                                <option value="vi" selected>Tiếng Việt</option>
                                <option value="en">English</option>
                                <option value="es">Español</option>
                                <option value="fr">Français</option>
                                <option value="de">Deutsch</option>
                                <option value="ja">日本語</option>
                                <option value="ko">한국어</option>
                            </select>
                            @error('language')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Buttons -->
                        <div class="flex gap-3">
                            <button type="submit"
                                class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                ✓ Tạo Sách
                            </button>
                            <a href="{{ route('youtube-channels.index') }}"
                                class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg transition duration-200">
                                Hủy
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="bulkModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-bulk-close></div>
        <div class="relative max-w-2xl mx-auto mt-24 bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Tạo nhiều sách từ URL</h3>
                <button type="button" class="text-gray-500 hover:text-gray-700" data-bulk-close>✕</button>
            </div>
            <p class="text-sm text-gray-600 mb-3">
                Dán danh sách URL (mỗi dòng một URL). Sẽ dùng channel và ngôn ngữ đang chọn ở form.
            </p>
            <textarea id="bulkUrls" rows="8" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                placeholder="https://docsach24.co/e-book/dao-giau-vang-118.html
https://nhasachmienphi.com/doc-online/..."></textarea>
            <div id="bulkStatus" class="mt-3 text-sm"></div>
            <div class="mt-4 flex gap-2">
                <button type="button" id="bulkSubmit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                    Bắt đầu tạo
                </button>
                <button type="button"
                    class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg" data-bulk-close>
                    Hủy
                </button>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const bookTypeSelect = document.getElementById('bookTypeSelect');
            const bookTypeCustomWrap = document.getElementById('bookTypeCustomWrap');
            const categorySelect = document.getElementById('categorySelect');
            const categoryCustomWrap = document.getElementById('categoryCustomWrap');

            function toggleCustom(selectEl, wrapEl) {
                if (!selectEl || !wrapEl) return;
                if (selectEl.value === 'custom') {
                    wrapEl.classList.remove('hidden');
                } else {
                    wrapEl.classList.add('hidden');
                }
            }

            if (bookTypeSelect) {
                bookTypeSelect.addEventListener('change', () => toggleCustom(bookTypeSelect, bookTypeCustomWrap));
                toggleCustom(bookTypeSelect, bookTypeCustomWrap);
            }
            if (categorySelect) {
                categorySelect.addEventListener('change', () => toggleCustom(categorySelect, categoryCustomWrap));
                toggleCustom(categorySelect, categoryCustomWrap);
            }

            // Auto-fill functionality
            const bookUrlInput = document.getElementById('bookUrl');
            const fetchMetadataBtn = document.getElementById('fetchMetadataBtn');
            const fetchStatus = document.getElementById('fetchStatus');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                '{{ csrf_token() }}';

            if (fetchMetadataBtn && bookUrlInput) {
                fetchMetadataBtn.addEventListener('click', async function() {
                    const bookUrl = bookUrlInput.value.trim();

                    if (!bookUrl) {
                        showStatus('error', 'Vui lòng nhập URL sách');
                        return;
                    }

                    // Disable button and show loading
                    fetchMetadataBtn.disabled = true;
                    fetchMetadataBtn.textContent = 'Đang tải...';
                    showStatus('loading', 'Đang lấy thông tin sách...');

                    try {
                        const response = await fetch('{{ route('audiobooks.fetch.book.metadata') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                book_url: bookUrl
                            })
                        });

                        const data = await response.json();

                        console.log('Fetched data:', data); // Debug log

                        if (response.ok && data.success) {
                            // Auto-fill form fields
                            if (data.title) {
                                document.querySelector('input[name="title"]').value = data.title;
                                console.log('Filled title:', data.title);
                            }
                            if (data.author) {
                                document.querySelector('input[name="author"]').value = data.author;
                                console.log('Filled author:', data.author);
                            }
                            if (data.description) {
                                document.querySelector('textarea[name="description"]').value = data
                                    .description;
                                console.log('Filled description');
                            }

                            // Handle category (thể loại)
                            if (data.category) {
                                const categorySelect = document.getElementById('categorySelect');
                                const categoryOption = Array.from(categorySelect.options).find(
                                    opt => opt.value.toLowerCase() === data.category.toLowerCase()
                                );

                                if (categoryOption) {
                                    categorySelect.value = categoryOption.value;
                                    toggleCustom(categorySelect, categoryCustomWrap);
                                } else {
                                    // Use custom category
                                    categorySelect.value = 'custom';
                                    toggleCustom(categorySelect, categoryCustomWrap);
                                    document.getElementById('categoryCustom').value = data.category;
                                }
                                console.log('Filled category:', data.category);
                            }

                            // Handle cover image
                            if (data.cover_image) {
                                const coverImagePreview = document.getElementById('coverImagePreview');
                                const coverImagePreviewImg = document.getElementById(
                                'coverImagePreviewImg');
                                const coverImageUrlHidden = document.getElementById('coverImageUrlHidden');

                                coverImagePreviewImg.src = data.cover_image;
                                coverImageUrlHidden.value = data.cover_image;
                                coverImagePreview.classList.remove('hidden');
                                console.log('Cover image URL:', data.cover_image);
                            }

                            // Store book URL and source for automatic chapter scraping after creation
                            document.getElementById('bookUrlHidden').value = bookUrl;
                            document.getElementById('bookSourceHidden').value = data.book_source;

                            const statusDetails = [
                                `✓ Đã lấy thông tin thành công!`,
                                `📖 Tiêu đề: ${data.title || 'N/A'}`,
                                `✍️ Tác giả: ${data.author || 'N/A'}`,
                                `📚 Thể loại: ${data.category || 'N/A'}`,
                                `📑 Số chương: ${data.total_chapters || 0}`,
                                `🖼️ Ảnh bìa: ${data.cover_image ? 'Có' : 'Không'}`
                            ];

                            showStatus('success', statusDetails.join('<br>'));
                        } else {
                            showStatus('error', data.error || 'Lỗi khi lấy thông tin sách');
                        }
                    } catch (error) {
                        console.error('Fetch error:', error);
                        showStatus('error', 'Lỗi kết nối. Vui lòng thử lại.');
                    } finally {
                        // Re-enable button
                        fetchMetadataBtn.disabled = false;
                        fetchMetadataBtn.textContent = 'Lấy thông tin';
                    }
                });

                // Allow Enter key to trigger fetch
                bookUrlInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        fetchMetadataBtn.click();
                    }
                });
            }

            // YouTube transcript fetch
            const youtubeUrlInput = document.getElementById('youtubeUrl');
            const fetchYoutubeTranscriptBtn = document.getElementById('fetchYoutubeTranscriptBtn');
            const youtubeTranscriptStatus = document.getElementById('youtubeTranscriptStatus');
            const youtubeTranscriptPreviewWrap = document.getElementById('youtubeTranscriptPreviewWrap');
            const youtubeTranscriptPreview = document.getElementById('youtubeTranscriptPreview');
            const youtubeTranscriptContentHidden = document.getElementById('youtubeTranscriptContentHidden');
            const clearYoutubeTranscriptBtn = document.getElementById('clearYoutubeTranscriptBtn');
            const titleInput = document.querySelector('input[name="title"]');

            function showYoutubeStatus(type, message) {
                const colors = {
                    loading: 'text-blue-700 bg-blue-50 border border-blue-200',
                    success: 'text-green-700 bg-green-50 border border-green-200',
                    warning: 'text-amber-700 bg-amber-50 border border-amber-200',
                    error: 'text-red-700 bg-red-50 border border-red-200'
                };
                youtubeTranscriptStatus.className = `mt-2 p-3 text-sm rounded-lg ${colors[type] || 'text-gray-700'}`;
                youtubeTranscriptStatus.innerHTML = message;
            }

            // Keep the hidden field in sync if the user edits the preview textarea directly.
            if (youtubeTranscriptPreview) {
                youtubeTranscriptPreview.addEventListener('input', () => {
                    youtubeTranscriptContentHidden.value = youtubeTranscriptPreview.value;
                });
            }

            if (clearYoutubeTranscriptBtn) {
                clearYoutubeTranscriptBtn.addEventListener('click', () => {
                    youtubeTranscriptPreviewWrap.classList.add('hidden');
                    youtubeTranscriptPreview.value = '';
                    youtubeTranscriptContentHidden.value = '';
                    youtubeTranscriptStatus.innerHTML = '';
                });
            }

            const youtubeStageLabels = {
                starting: '⏳ Đang khởi tạo...',
                fetching_metadata: '⏳ Đang lấy thông tin video...',
                fetching_captions: '⏳ Đang lấy phụ đề YouTube...',
                downloading_audio: '⏳ Video không có phụ đề sẵn — đang tải audio...',
                transcribing_ai: '🤖 Đang chuyển audio thành text bằng AI (Gemini)...',
                translating: '🌐 Đang dịch sang tiếng Việt bằng Gemini...',
                unknown: '⏳ Đang khởi tạo...',
            };

            function pollYoutubeTranscriptStatus(token) {
                const statusUrl = '{{ url('audiobooks/fetch-youtube-transcript') }}/' + token + '/status';

                const timer = setInterval(async () => {
                    let data;
                    try {
                        const resp = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                        data = await resp.json();
                    } catch (e) {
                        return; // transient network hiccup — try again on next tick
                    }

                    if (data.stage === 'done') {
                        clearInterval(timer);
                        youtubeTranscriptPreview.value = data.content || '';
                        youtubeTranscriptContentHidden.value = data.content || '';
                        youtubeTranscriptPreviewWrap.classList.remove('hidden');

                        if (data.title && titleInput && !titleInput.value.trim()) {
                            titleInput.value = data.title;
                        }

                        const sourceLabel = data.source === 'youtube_captions' ?
                            '✓ Lấy từ phụ đề YouTube có sẵn' :
                            '✓ Tạo bằng AI (Gemini) — video không có phụ đề sẵn';
                        const translatedLabel = data.translated ?
                            '<br>🌐 Nội dung gốc không phải tiếng Việt — đã tự động dịch bằng Gemini' :
                            '';
                        showYoutubeStatus(data.source === 'youtube_captions' ? 'success' : 'warning',
                            sourceLabel + translatedLabel + (data.warning ? '<br>⚠️ ' + data.warning : ''));

                        fetchYoutubeTranscriptBtn.disabled = false;
                        fetchYoutubeTranscriptBtn.textContent = 'Lấy transcript';
                    } else if (data.stage === 'failed') {
                        clearInterval(timer);
                        showYoutubeStatus('error', data.error || 'Lỗi khi lấy transcript');
                        fetchYoutubeTranscriptBtn.disabled = false;
                        fetchYoutubeTranscriptBtn.textContent = 'Lấy transcript';
                    } else {
                        showYoutubeStatus('loading', youtubeStageLabels[data.stage] || youtubeStageLabels.unknown);
                    }
                }, 2000);
            }

            if (fetchYoutubeTranscriptBtn && youtubeUrlInput) {
                fetchYoutubeTranscriptBtn.addEventListener('click', async function() {
                    const youtubeUrl = youtubeUrlInput.value.trim();
                    if (!youtubeUrl) {
                        showYoutubeStatus('error', 'Vui lòng nhập URL video YouTube');
                        return;
                    }

                    fetchYoutubeTranscriptBtn.disabled = true;
                    fetchYoutubeTranscriptBtn.textContent = 'Đang lấy...';
                    showYoutubeStatus('loading', youtubeStageLabels.starting);

                    try {
                        const response = await fetch('{{ route('audiobooks.fetch.youtube.transcript') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                youtube_url: youtubeUrl
                            })
                        });

                        const data = await response.json();

                        if (response.ok && data.success && data.token) {
                            pollYoutubeTranscriptStatus(data.token);
                        } else {
                            showYoutubeStatus('error', data.error || 'Lỗi khi lấy transcript');
                            fetchYoutubeTranscriptBtn.disabled = false;
                            fetchYoutubeTranscriptBtn.textContent = 'Lấy transcript';
                        }
                    } catch (error) {
                        showYoutubeStatus('error', 'Lỗi kết nối. Vui lòng thử lại.');
                        fetchYoutubeTranscriptBtn.disabled = false;
                        fetchYoutubeTranscriptBtn.textContent = 'Lấy transcript';
                    }
                });

                youtubeUrlInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        fetchYoutubeTranscriptBtn.click();
                    }
                });
            }

            function showStatus(type, message) {
                const colors = {
                    loading: 'text-blue-700 bg-blue-50 border border-blue-200',
                    success: 'text-green-700 bg-green-50 border border-green-200',
                    error: 'text-red-700 bg-red-50 border border-red-200'
                };
                fetchStatus.className = `mt-3 p-3 text-sm rounded-lg ${colors[type] || 'text-gray-700'}`;
                fetchStatus.innerHTML = message;
            }

            const bulkModal = document.getElementById('bulkModal');
            const openBulkModalBtn = document.getElementById('openBulkModal');
            const bulkSubmitBtn = document.getElementById('bulkSubmit');
            const bulkUrlsInput = document.getElementById('bulkUrls');
            const bulkStatus = document.getElementById('bulkStatus');

            function setBulkStatus(type, message) {
                const colors = {
                    loading: 'text-blue-700 bg-blue-50 border border-blue-200',
                    success: 'text-green-700 bg-green-50 border border-green-200',
                    error: 'text-red-700 bg-red-50 border border-red-200'
                };
                bulkStatus.className = `mt-3 p-3 text-sm rounded-lg ${colors[type] || 'text-gray-700'}`;
                bulkStatus.innerHTML = message;
            }

            function openBulkModal() {
                bulkModal.classList.remove('hidden');
            }

            function closeBulkModal() {
                bulkModal.classList.add('hidden');
            }

            if (openBulkModalBtn) {
                openBulkModalBtn.addEventListener('click', openBulkModal);
            }

            document.querySelectorAll('[data-bulk-close]').forEach((el) => {
                el.addEventListener('click', closeBulkModal);
            });

            if (bulkSubmitBtn && bulkUrlsInput) {
                bulkSubmitBtn.addEventListener('click', async function() {
                    const raw = bulkUrlsInput.value || '';
                    const urls = raw
                        .split('\n')
                        .map((line) => line.trim())
                        .filter((line) => line.length > 0);

                    const channelId = document.querySelector('select[name="youtube_channel_id"]')?.value ||
                        '';
                    const language = document.querySelector('select[name="language"]')?.value || '';

                    if (!channelId) {
                        setBulkStatus('error', 'Vui lòng chọn channel trước khi tạo nhiều sách.');
                        return;
                    }

                    if (!language) {
                        setBulkStatus('error', 'Vui lòng chọn ngôn ngữ trước khi tạo nhiều sách.');
                        return;
                    }

                    if (urls.length === 0) {
                        setBulkStatus('error', 'Vui lòng nhập ít nhất 1 URL.');
                        return;
                    }

                    bulkSubmitBtn.disabled = true;
                    bulkSubmitBtn.textContent = 'Đang xử lý...';
                    setBulkStatus('loading', 'Đang tạo sách, vui lòng chờ...');

                    try {
                        const response = await fetch('{{ route('audiobooks.bulk.create') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                youtube_channel_id: channelId,
                                language,
                                book_urls: urls
                            })
                        });

                        const data = await response.json();

                        if (!response.ok || !data.success) {
                            setBulkStatus('error', data.error || 'Có lỗi xảy ra khi tạo sách.');
                            return;
                        }

                        const createdItems = (data.created || []).map((item) => {
                            const bookUrl = `{{ url('/audiobooks') }}/${item.id}`;
                            return `• <a class="text-blue-600 hover:text-blue-800" href="${bookUrl}">${item.title}</a> (${item.chapters || 0} chương)`;
                        });

                        const errorItems = (data.errors || []).map((item) => {
                            return `• ${item.url}: ${item.error}`;
                        });

                        const parts = [];
                        parts.push(`<div>Đã xử lý ${data.total || urls.length} URL.</div>`);
                        if (createdItems.length) {
                            parts.push('<div class="mt-2 font-medium">Đã tạo:</div>');
                            parts.push(`<div class="text-sm">${createdItems.join('<br>')}</div>`);
                        }
                        if (errorItems.length) {
                            parts.push('<div class="mt-2 font-medium text-red-700">Lỗi:</div>');
                            parts.push(
                            `<div class="text-sm text-red-700">${errorItems.join('<br>')}</div>`);
                        }

                        setBulkStatus('success', parts.join(''));
                    } catch (error) {
                        setBulkStatus('error', 'Lỗi kết nối. Vui lòng thử lại.');
                    } finally {
                        bulkSubmitBtn.disabled = false;
                        bulkSubmitBtn.textContent = 'Bắt đầu tạo';
                    }
                });
            }
        })();
    </script>
@endsection
