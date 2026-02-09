@extends('layouts.app')

@section('content')
    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-between items-center mb-6">
                <h2 class="font-semibold text-2xl text-gray-800">Sửa Sách: {{ $audioBook->title }}</h2>
                <a href="{{ route('audiobooks.show', $audioBook) }}"
                    class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                    Quay lại
                </a>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('audiobooks.update', $audioBook) }}" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <!-- Channel Selection -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Chọn Channel 📺</label>
                            <select name="youtube_channel_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('youtube_channel_id') border-red-500 @enderror"
                                required>
                                <option value="">-- Chọn Channel --</option>
                                @foreach ($youtubeChannels as $channel)
                                    <option value="{{ $channel->id }}"
                                        {{ $audioBook->youtube_channel_id == $channel->id ? 'selected' : '' }}>
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
                                value="{{ $audioBook->title }}" required>
                            @error('title')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Author -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Tác giả</label>
                            <input type="text" name="author"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('author') border-red-500 @enderror"
                                value="{{ $audioBook->author }}">
                            @error('author')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Same Author Books -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Sách cùng tác giả</label>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm">
                                @if (!empty($authorBooks) && $authorBooks->count() > 0)
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
                                @else
                                    <span class="text-gray-500">Chưa có sách nào khác của tác giả này.</span>
                                @endif
                            </div>
                        </div>

                        <!-- Book Type -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Phân loại</label>
                            @php
                                $bookType = $audioBook->book_type ?? 'sach';
                                $bookTypeOptions = [
                                    'sach' => 'Sách',
                                    'truyen' => 'Truyện',
                                    'tieu_thuyet' => 'Tiểu thuyết',
                                    'truyen_ngan' => 'Truyện ngắn',
                                ];
                                $bookTypeIsCustom = $bookType && !array_key_exists($bookType, $bookTypeOptions);
                            @endphp
                            <select name="book_type" id="bookTypeSelect"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('book_type') border-red-500 @enderror">
                                @foreach ($bookTypeOptions as $value => $label)
                                    <option value="{{ $value }}" {{ $bookType == $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                                <option value="custom" {{ $bookTypeIsCustom ? 'selected' : '' }}>Khác (tự nhập)</option>
                            </select>
                            <div id="bookTypeCustomWrap" class="mt-2 {{ $bookTypeIsCustom ? '' : 'hidden' }}">
                                <input type="text" name="book_type_custom" id="bookTypeCustom"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                    placeholder="Nhập phân loại..." value="{{ $bookTypeIsCustom ? $bookType : '' }}">
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
                                $selectedCategory = $audioBook->category ?? '';
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
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm @error('description') border-red-500 @enderror">{{ $audioBook->description }}</textarea>
                            @error('description')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Cover Image -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Ảnh bìa sách</label>
                            @if ($audioBook->cover_image)
                                <div class="mb-2">
                                    <img src="{{ asset('storage/' . $audioBook->cover_image) }}" alt="Current cover"
                                        class="h-32 rounded">
                                </div>
                            @endif
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
                                <option value="vi" {{ $audioBook->language == 'vi' ? 'selected' : '' }}>Tiếng Việt
                                </option>
                                <option value="en" {{ $audioBook->language == 'en' ? 'selected' : '' }}>English
                                </option>
                                <option value="es" {{ $audioBook->language == 'es' ? 'selected' : '' }}>Español
                                </option>
                                <option value="fr" {{ $audioBook->language == 'fr' ? 'selected' : '' }}>Français
                                </option>
                                <option value="de" {{ $audioBook->language == 'de' ? 'selected' : '' }}>Deutsch
                                </option>
                                <option value="ja" {{ $audioBook->language == 'ja' ? 'selected' : '' }}>日本語</option>
                                <option value="ko" {{ $audioBook->language == 'ko' ? 'selected' : '' }}>한국어</option>
                            </select>
                            @error('language')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Buttons -->
                        <div class="flex gap-3">
                            <button type="submit"
                                class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                ✓ Cập nhật
                            </button>
                            <a href="{{ route('audiobooks.show', $audioBook) }}"
                                class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg transition duration-200">
                                Hủy
                            </a>
                        </div>
                    </form>
                </div>
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
        })();
    </script>
@endsection
