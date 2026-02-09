@extends('layouts.app')

@section('content')
    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <h2 class="font-semibold text-2xl text-gray-800">
                    📚 Chi tiết Audio Book
                </h2>
                <div class="flex gap-2">
                    @if ($audioBook->youtubeChannel)
                        <a href="{{ route('youtube-channels.show', $audioBook->youtubeChannel) }}"
                            class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg transition duration-200">
                            ← Quay lại kênh
                        </a>
                    @else
                        <a href="{{ route('audiobooks.index') }}"
                            class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg transition duration-200">
                            ← Quay lại
                        </a>
                    @endif
                    <a href="{{ route('audiobooks.edit', $audioBook) }}"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                        ✏️ Sửa
                    </a>
                </div>
            </div>

            <!-- Success/Error Messages -->
            @if ($message = Session::get('success'))
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    {{ $message }}
                    <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3"
                        onclick="this.parentElement.style.display='none';">
                        <span class="text-2xl leading-none">&times;</span>
                    </button>
                </div>
            @endif

            @if ($message = Session::get('error'))
                <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                    {{ $message }}
                    <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3"
                        onclick="this.parentElement.style.display='none';">
                        <span class="text-2xl leading-none">&times;</span>
                    </button>
                </div>
            @endif

            <!-- Book Info Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6 text-gray-900">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Left: Book Info -->
                        <div class="md:col-span-2">
                            <div class="flex gap-6">
                                <!-- Cover Image -->
                                <div class="flex-shrink-0">
                                    @if ($audioBook->cover_image)
                                        <img src="{{ asset('storage/' . $audioBook->cover_image) }}"
                                            alt="{{ $audioBook->title }}"
                                            class="w-32 h-44 object-cover rounded-lg border shadow cursor-pointer hover:opacity-80 transition"
                                            onclick="openImagePreview('{{ asset('storage/' . $audioBook->cover_image) }}')"
                                            title="Click để xem lớn">
                                    @else
                                        <div
                                            class="w-32 h-44 bg-gradient-to-br from-blue-400 to-blue-600 rounded-lg flex items-center justify-center shadow">
                                            <span class="text-4xl">📚</span>
                                        </div>
                                    @endif
                                </div>

                                <!-- Book Details -->
                                <div class="flex-1">
                                    <h3 class="text-xl font-semibold text-gray-900 mb-2">{{ $audioBook->title }}</h3>

                                    <!-- Author & Category -->
                                    <div class="flex flex-wrap gap-3 mb-3">
                                        @if ($audioBook->author)
                                            <div class="flex items-center gap-1 text-sm text-gray-700">
                                                <span class="text-gray-500">✍️</span>
                                                <span class="font-medium">{{ $audioBook->author }}</span>
                                            </div>
                                        @endif
                                        @if ($audioBook->category)
                                            <div class="flex items-center gap-1 text-sm">
                                                <span
                                                    class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full text-xs font-medium">
                                                    📂 {{ $audioBook->category }}
                                                </span>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                                        @php
                                            $totalDuration = $audioBook->chapters->sum('total_duration');
                                            $totalChars = $audioBook->chapters->sum(
                                                fn($ch) => mb_strlen($ch->content ?? ''),
                                            );
                                        @endphp
                                        <div class="bg-gray-50 rounded-lg p-3">
                                            <div class="text-xs text-gray-500">Kênh YouTube</div>
                                            <div class="text-sm font-semibold text-gray-900">
                                                📺 {{ $audioBook->youtubeChannel->title ?? '—' }}
                                            </div>
                                        </div>
                                        <div class="bg-gray-50 rounded-lg p-3">
                                            <div class="text-xs text-gray-500">Phân loại</div>
                                            <div class="text-sm font-semibold text-gray-900">
                                                @php
                                                    $bookTypeLabel = match ($audioBook->book_type) {
                                                        'truyen' => '📖 Truyện',
                                                        'tieu_thuyet' => '📘 Tiểu thuyết',
                                                        'truyen_ngan' => '📗 Truyện ngắn',
                                                        'sach' => '📚 Sách',
                                                        default => $audioBook->book_type
                                                            ? '📚 ' . $audioBook->book_type
                                                            : '📚 Sách',
                                                    };
                                                @endphp
                                                {{ $bookTypeLabel }}
                                            </div>
                                        </div>
                                        <div class="bg-gray-50 rounded-lg p-3">
                                            <div class="text-xs text-gray-500">Ngôn ngữ</div>
                                            <div class="text-sm font-semibold text-gray-900">
                                                🌐 {{ strtoupper($audioBook->language) }}
                                            </div>
                                        </div>
                                        <div class="bg-gray-50 rounded-lg p-3">
                                            <div class="text-xs text-gray-500">Số chương</div>
                                            <div class="text-sm font-semibold text-gray-900">
                                                📖 {{ $audioBook->total_chapters }} chương
                                            </div>
                                        </div>
                                        <div class="bg-gray-50 rounded-lg p-3">
                                            <div class="text-xs text-gray-500">Tổng thời lượng</div>
                                            <div class="text-sm font-semibold text-gray-900">
                                                @if ($totalDuration > 0)
                                                    @php
                                                        $hours = floor($totalDuration / 3600);
                                                        $mins = floor(($totalDuration % 3600) / 60);
                                                        $secs = floor($totalDuration % 60);
                                                        $durationStr =
                                                            $hours > 0
                                                                ? sprintf('%dh %02dp %02ds', $hours, $mins, $secs)
                                                                : sprintf('%dp %02ds', $mins, $secs);
                                                    @endphp
                                                    ⏱️ {{ $durationStr }}
                                                @else
                                                    ⏱️ Chưa có audio
                                                @endif
                                            </div>
                                        </div>
                                        <div class="bg-gray-50 rounded-lg p-3">
                                            <div class="text-xs text-gray-500">Tổng số ký tự</div>
                                            <div class="text-sm font-semibold text-gray-900">
                                                ✏️ {{ number_format($totalChars) }} ký tự
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Book Description Section -->
                            <div
                                class="mt-4 p-4 bg-gradient-to-r from-amber-50 to-orange-50 rounded-lg border border-amber-200">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-sm font-semibold text-amber-800 flex items-center gap-2">
                                        📖 Giới thiệu sách
                                    </h4>
                                    <div class="flex gap-2">
                                        <button type="button" id="rewriteDescBtn"
                                            class="text-xs bg-purple-100 hover:bg-purple-200 text-purple-700 px-2 py-1 rounded transition flex items-center gap-1">
                                            ✨ Viết lại bằng AI
                                        </button>
                                        <button type="button" id="generateDescAudioBtn"
                                            class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded transition flex items-center gap-1">
                                            🎙️ Tạo Audio
                                        </button>
                                        <button type="button" id="saveDescBtn"
                                            class="text-xs bg-green-100 hover:bg-green-200 text-green-700 px-2 py-1 rounded transition flex items-center gap-1">
                                            💾 Lưu
                                        </button>
                                    </div>
                                </div>
                                <textarea id="bookDescription" rows="15"
                                    class="w-full px-3 py-2 border border-amber-200 rounded-lg text-sm focus:border-amber-400 focus:outline-none focus:ring-1 focus:ring-amber-300 bg-white"
                                    placeholder="Nhập mô tả/giới thiệu sách...">{{ $audioBook->description ?? '' }}</textarea>

                                <div id="descStatus" class="mt-2 text-xs"></div>

                                <!-- Description Audio Player -->
                                <div id="descAudioContainer"
                                    class="mt-3 {{ $audioBook->description_audio ? '' : 'hidden' }}">
                                    <div
                                        class="flex items-center justify-between p-2 bg-gradient-to-r from-purple-100 to-pink-100 border border-purple-300 rounded-lg">
                                        <div class="flex items-center gap-2">
                                            <span class="text-lg">🎧</span>
                                            <span class="text-sm font-medium text-purple-800">Audio giới thiệu</span>
                                            <span id="descAudioDuration"
                                                class="text-xs text-purple-600">{{ $audioBook->description_audio_duration ? gmdate('i:s', (int) $audioBook->description_audio_duration) : '' }}</span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <audio id="descAudioPlayer" controls class="h-8">
                                                @if ($audioBook->description_audio)
                                                    <source src="{{ asset('storage/' . $audioBook->description_audio) }}"
                                                        type="audio/mpeg">
                                                @endif
                                            </audio>
                                            <button type="button" id="deleteDescAudioBtn"
                                                class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded transition"
                                                title="Xóa audio">
                                                🗑️
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Image Picker for Intro Video -->
                                <div id="descImagePickerSection" class="mt-3">
                                    <div
                                        class="p-3 bg-gradient-to-r from-indigo-50 to-blue-50 border border-indigo-200 rounded-lg">
                                        <div class="flex items-center justify-between mb-2">
                                            <div class="flex items-center gap-2">
                                                <span class="text-lg">🖼️</span>
                                                <span class="text-sm font-medium text-indigo-800">Chọn ảnh cho Video giới
                                                    thiệu</span>
                                            </div>
                                            <div class="flex gap-2">
                                                <button type="button" id="loadDescMediaBtn"
                                                    class="text-xs bg-indigo-100 hover:bg-indigo-200 text-indigo-700 px-2 py-1 rounded transition flex items-center gap-1">
                                                    🔄 Tải thư viện
                                                </button>
                                                <button type="button" id="generateDescIntroVideoBtn"
                                                    class="text-xs bg-emerald-100 hover:bg-emerald-200 text-emerald-700 px-2 py-1 rounded transition flex items-center gap-1 {{ $audioBook->description_audio ? '' : 'opacity-50 cursor-not-allowed' }}"
                                                    {{ $audioBook->description_audio ? '' : 'disabled' }}
                                                    title="Chọn ảnh + có audio → tạo video giới thiệu">
                                                    🎬 Tạo Video Giới Thiệu
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Selected image preview -->
                                        <div id="descSelectedImagePreview" class="mb-2 hidden">
                                            <div
                                                class="flex items-center gap-2 p-2 bg-white border border-indigo-300 rounded-lg">
                                                <img id="descSelectedImageImg" src="" alt="Selected"
                                                    class="w-20 h-14 object-cover rounded border">
                                                <div class="flex-1">
                                                    <span class="text-xs text-indigo-700 font-medium">Ảnh đã chọn:</span>
                                                    <span id="descSelectedImageName"
                                                        class="text-xs text-gray-600 ml-1"></span>
                                                </div>
                                                <button type="button" id="descClearImageBtn"
                                                    class="text-xs text-red-500 hover:text-red-700">✕</button>
                                            </div>
                                        </div>

                                        <!-- Image grid (loaded dynamically) -->
                                        <div id="descMediaGrid"
                                            class="grid grid-cols-4 sm:grid-cols-6 gap-2 max-h-48 overflow-y-auto hidden">
                                            <!-- Images loaded by JS -->
                                        </div>
                                        <div id="descMediaEmpty" class="text-xs text-gray-500 text-center py-4 hidden">
                                            Chưa có ảnh nào. Hãy tạo ảnh trong tab "YouTube Media" trước.
                                        </div>
                                    </div>
                                </div>

                                <!-- Description Intro Video Player -->
                                <div id="descVideoContainer"
                                    class="mt-3 {{ $audioBook->description_scene_video ? '' : 'hidden' }}">
                                    <div
                                        class="p-3 bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-300 rounded-lg">
                                        <div class="flex items-center justify-between mb-2">
                                            <div class="flex items-center gap-2">
                                                <span class="text-lg">🎬</span>
                                                <span class="text-sm font-medium text-emerald-800">Video giới thiệu</span>
                                                <span id="descVideoDuration"
                                                    class="text-xs text-emerald-600">{{ $audioBook->description_scene_video_duration ? gmdate('i:s', (int) $audioBook->description_scene_video_duration) : '' }}</span>
                                            </div>
                                            <button type="button" id="deleteDescVideoBtn"
                                                class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded transition"
                                                title="Xóa video">
                                                🗑️
                                            </button>
                                        </div>
                                        <video id="descVideoPlayer" controls
                                            class="w-full rounded border border-emerald-300">
                                            @if ($audioBook->description_scene_video)
                                                <source
                                                    src="{{ asset('storage/' . $audioBook->description_scene_video) }}"
                                                    type="video/mp4">
                                            @endif
                                        </video>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: TTS Audio Settings -->
                        <div class="h-fit space-y-4">

                            <!-- TTS Settings Panel -->
                            <div class="p-4 bg-blue-50 border-2 border-blue-300 rounded-lg">
                                <button type="button" id="ttsToggleBtn"
                                    class="w-full text-left flex items-center justify-between hover:opacity-75 transition">
                                    <h4 class="text-base font-semibold text-blue-900 flex items-center gap-2">
                                        🎙️ TTS Audio Settings
                                    </h4>
                                    <span id="ttsToggleIcon" class="text-xl">−</span>
                                </button>

                                <div id="ttsContent" class="space-y-3 mt-4">
                                    <!-- TTS Provider -->
                                    <div class="bg-white p-3 rounded border border-blue-200">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">TTS Provider: <span
                                                class="text-red-500">*</span></label>
                                        <select id="ttsProviderSelect"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-blue-500 focus:outline-none">
                                            <option value="" {{ !$audioBook->tts_provider ? 'selected' : '' }}>--
                                                Chọn TTS Provider --</option>
                                            <option value="openai"
                                                {{ ($audioBook->tts_provider ?? '') === 'openai' ? 'selected' : '' }}>🤖
                                                OpenAI TTS</option>
                                            <option value="gemini"
                                                {{ ($audioBook->tts_provider ?? '') === 'gemini' ? 'selected' : '' }}>✨
                                                Gemini Pro TTS</option>
                                            <option value="microsoft"
                                                {{ ($audioBook->tts_provider ?? '') === 'microsoft' ? 'selected' : '' }}>🪟
                                                Microsoft TTS</option>
                                            <option value="vbee"
                                                {{ ($audioBook->tts_provider ?? '') === 'vbee' ? 'selected' : '' }}>🇻🇳
                                                Vbee TTS (Việt Nam)</option>
                                        </select>
                                    </div>

                                    <!-- Style Instruction (hidden for Microsoft/OpenAI) -->
                                    <div id="styleInstructionSection" class="bg-white p-3 rounded border border-blue-200">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Style
                                            Instruction <span class="text-xs text-gray-400">(chỉ Gemini)</span>:</label>
                                        <div class="flex flex-wrap gap-1 mb-2">
                                            <button type="button"
                                                class="style-preset-btn px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng ấm áp, chậm rãi,&#10;phong cách kể chuyện,&#10;tạo cảm giác gần gũi và cuốn hút.">🎙️
                                                Storytelling</button>
                                            <button type="button"
                                                class="style-preset-btn px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng rất nhẹ, chậm rãi,&#10;thư giãn,&#10;phù hợp nội dung thiền và sức khỏe tinh thần.">🧘
                                                Wellness</button>
                                            <button type="button"
                                                class="style-preset-btn px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng tự nhiên, rõ ràng,&#10;nhịp vừa phải,&#10;phong cách đọc sách audio.">📚
                                                Audiobook</button>
                                        </div>
                                        <textarea id="ttsStyleInstruction" rows="3"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-blue-500 focus:outline-none"
                                            placeholder="Nhập hướng dẫn style...">{{ $audioBook->tts_style_instruction ?? '' }}</textarea>
                                    </div>

                                    <!-- Voice Settings -->
                                    <div class="bg-white p-3 rounded border border-blue-200">
                                        <div class="flex items-center justify-between mb-3">
                                            <label class="text-sm font-medium text-gray-700">Voice Settings:</label>
                                            <div class="flex items-center gap-3 text-sm text-gray-700">
                                                <label class="inline-flex items-center gap-1 cursor-pointer">
                                                    <input type="radio" name="voiceGender" value="female"
                                                        {{ ($audioBook->tts_voice_gender ?? 'female') === 'female' ? 'checked' : '' }}>
                                                    <span>👩 Nữ</span>
                                                </label>
                                                <label class="inline-flex items-center gap-1 cursor-pointer">
                                                    <input type="radio" name="voiceGender" value="male"
                                                        {{ ($audioBook->tts_voice_gender ?? '') === 'male' ? 'checked' : '' }}>
                                                    <span>👨 Nam</span>
                                                </label>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Chọn giọng:</label>
                                            <div class="flex gap-1">
                                                <select id="voiceNameSelect"
                                                    class="flex-1 px-2 py-1.5 border border-gray-300 rounded text-sm focus:border-blue-500 focus:outline-none">
                                                    <option value="">-- Chọn giọng --</option>
                                                </select>
                                                <button type="button" id="voicePreviewBtn"
                                                    class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded text-sm font-medium transition"
                                                    title="Nghe thử giọng">
                                                    🔊
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Save Button -->
                                    <button type="button" id="saveTtsSettingsBtn"
                                        class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold transition duration-200">
                                        💾 Lưu cấu hình TTS
                                    </button>

                                    <!-- Intro/Outro Music Settings -->
                                    <div class="border-t border-gray-200 pt-4 mt-4">
                                        <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
                                            🎵 Nhạc Intro/Outro
                                        </h4>

                                        <!-- Intro Music -->
                                        <div class="bg-green-50 p-3 rounded border border-green-200 mb-3">
                                            <label class="block text-sm font-medium text-green-700 mb-2">🎬 Nhạc Intro (mở
                                                đầu):</label>
                                            <div class="flex items-center gap-2 mb-2">
                                                <input type="file" id="introMusicFile"
                                                    accept="audio/mp3,audio/wav,audio/m4a" class="hidden"
                                                    onchange="uploadMusic('intro')">
                                                <button type="button"
                                                    onclick="document.getElementById('introMusicFile').click()"
                                                    class="flex-1 px-3 py-2 bg-green-600 hover:bg-green-700 text-white rounded text-sm font-medium transition">
                                                    📁 Chọn file nhạc
                                                </button>
                                                @if ($audioBook->intro_music)
                                                    <button type="button" onclick="deleteMusic('intro')"
                                                        class="px-3 py-2 bg-red-500 hover:bg-red-600 text-white rounded text-sm font-medium transition">
                                                        🗑️
                                                    </button>
                                                @endif
                                            </div>
                                            @if ($audioBook->intro_music)
                                                <div class="flex items-center gap-2 p-2 bg-white rounded border">
                                                    <audio controls class="h-8 flex-1">
                                                        <source src="{{ asset('storage/' . $audioBook->intro_music) }}"
                                                            type="audio/mpeg">
                                                    </audio>
                                                    <span class="text-xs text-gray-500">✅ Đã tải</span>
                                                </div>
                                            @else
                                                <p class="text-xs text-gray-500 italic">Chưa có nhạc intro</p>
                                            @endif
                                            <div class="mt-2">
                                                <label class="text-xs text-gray-600">Fade out (giây):</label>
                                                <input type="number" id="introFadeDuration" min="1"
                                                    max="30" step="0.5"
                                                    value="{{ $audioBook->intro_fade_duration ?? 3 }}"
                                                    class="w-20 px-2 py-1 border border-gray-300 rounded text-sm">
                                            </div>
                                        </div>

                                        <!-- Outro Music -->
                                        <div class="bg-orange-50 p-3 rounded border border-orange-200 mb-3">
                                            <label class="block text-sm font-medium text-orange-700 mb-2">🎬 Nhạc Outro
                                                (kết thúc):</label>

                                            <!-- Option: Use same as intro -->
                                            <div class="mb-3 p-2 bg-white rounded border border-orange-200">
                                                <label class="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox" id="outroUseIntro"
                                                        {{ $audioBook->outro_use_intro ? 'checked' : '' }}
                                                        onchange="toggleOutroUpload()"
                                                        class="w-4 h-4 text-orange-600 rounded focus:ring-orange-500">
                                                    <span class="text-sm text-gray-700">🔄 Dùng cùng nhạc Intro</span>
                                                </label>
                                            </div>

                                            <div id="outroUploadSection"
                                                class="{{ $audioBook->outro_use_intro ? 'hidden' : '' }}">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <input type="file" id="outroMusicFile"
                                                        accept="audio/mp3,audio/wav,audio/m4a" class="hidden"
                                                        onchange="uploadMusic('outro')">
                                                    <button type="button"
                                                        onclick="document.getElementById('outroMusicFile').click()"
                                                        class="flex-1 px-3 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded text-sm font-medium transition">
                                                        📁 Chọn file nhạc
                                                    </button>
                                                    @if ($audioBook->outro_music)
                                                        <button type="button" onclick="deleteMusic('outro')"
                                                            class="px-3 py-2 bg-red-500 hover:bg-red-600 text-white rounded text-sm font-medium transition">
                                                            🗑️
                                                        </button>
                                                    @endif
                                                </div>
                                                @if ($audioBook->outro_music)
                                                    <div class="flex items-center gap-2 p-2 bg-white rounded border">
                                                        <audio controls class="h-8 flex-1">
                                                            <source
                                                                src="{{ asset('storage/' . $audioBook->outro_music) }}"
                                                                type="audio/mpeg">
                                                        </audio>
                                                        <span class="text-xs text-gray-500">✅ Đã tải</span>
                                                    </div>
                                                @else
                                                    <p class="text-xs text-gray-500 italic">Chưa có nhạc outro riêng</p>
                                                @endif
                                            </div>

                                            <div id="outroUseIntroMessage"
                                                class="{{ $audioBook->outro_use_intro ? '' : 'hidden' }} p-2 bg-green-50 rounded border border-green-200">
                                                <p class="text-sm text-green-700">✅ Sẽ sử dụng nhạc Intro cho Outro</p>
                                            </div>

                                            <div class="mt-2 flex gap-4">
                                                <div>
                                                    <label class="text-xs text-gray-600">Fade in (giây):</label>
                                                    <input type="number" id="outroFadeDuration" min="1"
                                                        max="30" step="0.5"
                                                        value="{{ $audioBook->outro_fade_duration ?? 10 }}"
                                                        class="w-20 px-2 py-1 border border-gray-300 rounded text-sm">
                                                </div>
                                                <div>
                                                    <label class="text-xs text-gray-600">Kéo dài thêm (giây):</label>
                                                    <input type="number" id="outroExtendDuration" min="0"
                                                        max="30" step="0.5"
                                                        value="{{ $audioBook->outro_extend_duration ?? 5 }}"
                                                        class="w-20 px-2 py-1 border border-gray-300 rounded text-sm">
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Save Music Settings Button -->
                                        <button type="button" id="saveMusicSettingsBtn"
                                            class="w-full bg-purple-600 hover:bg-purple-700 text-white py-2 rounded-lg font-semibold transition duration-200">
                                            💾 Lưu cấu hình nhạc
                                        </button>

                                        <!-- Music Merge Progress -->
                                        <div id="musicMergeProgressContainer"
                                            class="hidden mt-4 p-4 bg-purple-50 border border-purple-200 rounded-lg">
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="text-sm font-medium text-purple-800"
                                                    id="musicMergeStatus">Đang merge...</span>
                                                <span class="text-sm text-purple-600" id="musicMergePercent">0%</span>
                                            </div>
                                            <div class="w-full bg-purple-200 rounded-full h-2.5 mb-3">
                                                <div id="musicMergeProgressBar"
                                                    class="bg-purple-600 h-2.5 rounded-full transition-all duration-300"
                                                    style="width: 0%"></div>
                                            </div>
                                            <div id="musicMergeLog"
                                                class="max-h-32 overflow-y-auto text-xs bg-white p-2 rounded border border-purple-100">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Wave Effect Settings for Video -->
                                    <div class="border-t border-gray-200 pt-4 mt-4">
                                        <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
                                            📊 Hiệu ứng sóng âm (Video)
                                        </h4>

                                        <!-- Enable Wave -->
                                        <div class="mb-3 p-2 bg-blue-50 rounded border border-blue-200">
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input type="checkbox" id="waveEnabled"
                                                    {{ $audioBook->wave_enabled ? 'checked' : '' }}
                                                    onchange="toggleWaveSettings()"
                                                    class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                                                <span class="text-sm font-medium text-gray-700">🎵 Bật hiệu ứng sóng
                                                    âm</span>
                                            </label>
                                        </div>

                                        <div id="waveSettingsPanel"
                                            class="{{ $audioBook->wave_enabled ? '' : 'hidden' }}">
                                            <!-- Wave Type -->
                                            <div class="bg-gray-50 p-3 rounded border border-gray-200 mb-3">
                                                <label class="block text-xs font-medium text-gray-600 mb-2">Kiểu
                                                    sóng:</label>
                                                <div class="grid grid-cols-2 gap-2">
                                                    <label
                                                        class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                        <input type="radio" name="waveType" value="cline"
                                                            {{ ($audioBook->wave_type ?? 'cline') === 'cline' ? 'checked' : '' }}
                                                            class="text-blue-600">
                                                        <span class="text-xs">〰️ Curved Line</span>
                                                    </label>
                                                    <label
                                                        class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                        <input type="radio" name="waveType" value="line"
                                                            {{ ($audioBook->wave_type ?? '') === 'line' ? 'checked' : '' }}
                                                            class="text-blue-600">
                                                        <span class="text-xs">📈 Line</span>
                                                    </label>
                                                    <label
                                                        class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                        <input type="radio" name="waveType" value="p2p"
                                                            {{ ($audioBook->wave_type ?? '') === 'p2p' ? 'checked' : '' }}
                                                            class="text-blue-600">
                                                        <span class="text-xs">📊 Point to Point</span>
                                                    </label>
                                                    <label
                                                        class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                        <input type="radio" name="waveType" value="bar"
                                                            {{ ($audioBook->wave_type ?? '') === 'bar' ? 'checked' : '' }}
                                                            class="text-blue-600">
                                                        <span class="text-xs">📶 Bar</span>
                                                    </label>
                                                    <label
                                                        class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                        <input type="radio" name="waveType" value="point"
                                                            {{ ($audioBook->wave_type ?? '') === 'point' ? 'checked' : '' }}
                                                            class="text-blue-600">
                                                        <span class="text-xs">⚫ Point</span>
                                                    </label>
                                                </div>
                                            </div>

                                            <!-- Wave Position -->
                                            <div class="bg-gray-50 p-3 rounded border border-gray-200 mb-3">
                                                <label class="block text-xs font-medium text-gray-600 mb-2">Vị trí:</label>
                                                <div class="flex gap-2">
                                                    <label
                                                        class="flex-1 flex items-center justify-center gap-1 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                        <input type="radio" name="wavePosition" value="top"
                                                            {{ ($audioBook->wave_position ?? '') === 'top' ? 'checked' : '' }}
                                                            class="text-blue-600">
                                                        <span class="text-xs">⬆️ Trên</span>
                                                    </label>
                                                    <label
                                                        class="flex-1 flex items-center justify-center gap-1 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                        <input type="radio" name="wavePosition" value="center"
                                                            {{ ($audioBook->wave_position ?? '') === 'center' ? 'checked' : '' }}
                                                            class="text-blue-600">
                                                        <span class="text-xs">↔️ Giữa</span>
                                                    </label>
                                                    <label
                                                        class="flex-1 flex items-center justify-center gap-1 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                        <input type="radio" name="wavePosition" value="bottom"
                                                            {{ ($audioBook->wave_position ?? 'bottom') === 'bottom' ? 'checked' : '' }}
                                                            class="text-blue-600">
                                                        <span class="text-xs">⬇️ Dưới</span>
                                                    </label>
                                                </div>
                                            </div>

                                            <!-- Wave Height, Color, Opacity -->
                                            <div class="bg-gray-50 p-3 rounded border border-gray-200 mb-3">
                                                <div class="grid grid-cols-3 gap-3">
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-600 mb-1">Chiều
                                                            cao (px):</label>
                                                        <input type="number" id="waveHeight" min="50"
                                                            max="300" step="10"
                                                            value="{{ $audioBook->wave_height ?? 100 }}"
                                                            class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-600 mb-1">Màu
                                                            sắc:</label>
                                                        <input type="color" id="waveColor"
                                                            value="{{ $audioBook->wave_color ?? '#00ff00' }}"
                                                            class="w-full h-8 rounded border border-gray-300 cursor-pointer">
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-600 mb-1">Độ
                                                            mờ:</label>
                                                        <input type="range" id="waveOpacity" min="0.1"
                                                            max="1" step="0.1"
                                                            value="{{ $audioBook->wave_opacity ?? 0.8 }}"
                                                            class="w-full h-8">
                                                        <span class="text-xs text-gray-500"
                                                            id="waveOpacityValue">{{ $audioBook->wave_opacity ?? 0.8 }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Save Wave Settings Button -->
                                        <button type="button" id="saveWaveSettingsBtn"
                                            class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold transition duration-200">
                                            💾 Lưu cấu hình sóng âm
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="mb-6">
                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex gap-4" aria-label="Tabs">
                        <button type="button" data-tab="chapters"
                            class="tab-btn active whitespace-nowrap border-b-2 py-3 px-4 text-sm font-medium transition
                                   border-blue-500 text-blue-600">
                            📖 Danh sách chương
                        </button>
                        <button type="button" data-tab="youtube-media"
                            class="tab-btn whitespace-nowrap border-b-2 py-3 px-4 text-sm font-medium transition
                                   border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700">
                            🎨 YouTube Media (AI)
                        </button>
                        <button type="button" data-tab="auto-publish"
                            class="tab-btn whitespace-nowrap border-b-2 py-3 px-4 text-sm font-medium transition
                                   border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700">
                            🚀 Phát hành tự động
                        </button>
                    </nav>
                </div>
            </div>

            <!-- YouTube Media Tab Content -->
            <div id="youtube-media-tab" class="tab-content hidden">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-semibold text-gray-800">🎨 Tạo Media cho YouTube bằng AI</h3>
                            <div class="flex gap-2">
                                <button type="button" onclick="refreshMediaGallery()"
                                    class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg transition">
                                    🔄 Refresh
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Thumbnail Generator -->
                            <div
                                class="bg-gradient-to-br from-purple-50 to-pink-50 border border-purple-200 rounded-lg p-5">
                                <h4 class="text-base font-semibold text-purple-800 mb-4 flex items-center gap-2">
                                    🖼️ Tạo Thumbnail
                                </h4>

                                <div class="space-y-4">
                                    <!-- Text Info Preview - Editable -->
                                    <div class="p-3 bg-white rounded-lg border border-purple-200">
                                        <p class="text-xs font-medium text-gray-600 mb-2">📝 Thông tin sẽ hiển thị trên
                                            thumbnail <span class="text-purple-500">(có thể sửa)</span>:</p>
                                        <div class="space-y-2">
                                            <input type="text" id="thumbnailTitle"
                                                class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm font-semibold text-purple-700 focus:border-purple-500 focus:outline-none"
                                                value="{{ $audioBook->title }}" placeholder="Tiêu đề sách">
                                            <input type="text" id="thumbnailAuthor"
                                                class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm text-gray-600 focus:border-purple-500 focus:outline-none"
                                                value="{{ $audioBook->author ? 'Tác giả: ' . $audioBook->author : '' }}"
                                                placeholder="Tác giả: ...">
                                        </div>
                                    </div>

                                    <!-- Chapter Number (optional) -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Số chương <span class="text-xs text-gray-400">(tùy chọn, để trống nếu là
                                                thumbnail tổng)</span>:
                                        </label>
                                        <input type="number" id="thumbnailChapterNumber" min="1" max="999"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-purple-500 focus:outline-none"
                                            placeholder="Ví dụ: 1, 2, 3...">
                                    </div>

                                    <!-- Style Selection -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Phong cách hình
                                            nền:</label>
                                        <div class="grid grid-cols-2 gap-2">
                                            <label
                                                class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                <input type="radio" name="thumbnailStyle" value="cinematic" checked
                                                    class="text-purple-600">
                                                <span class="text-sm">🎬 Cinematic</span>
                                            </label>
                                            <label
                                                class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                <input type="radio" name="thumbnailStyle" value="gradient"
                                                    class="text-purple-600">
                                                <span class="text-sm">🌈 Gradient</span>
                                            </label>
                                            <label
                                                class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                <input type="radio" name="thumbnailStyle" value="modern"
                                                    class="text-purple-600">
                                                <span class="text-sm">✨ Modern</span>
                                            </label>
                                            <label
                                                class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                <input type="radio" name="thumbnailStyle" value="fantasy"
                                                    class="text-purple-600">
                                                <span class="text-sm">🧙 Fantasy</span>
                                            </label>
                                            <label
                                                class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                <input type="radio" name="thumbnailStyle" value="mystery"
                                                    class="text-purple-600">
                                                <span class="text-sm">🔮 Mystery</span>
                                            </label>
                                            <label
                                                class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                <input type="radio" name="thumbnailStyle" value="romance"
                                                    class="text-purple-600">
                                                <span class="text-sm">💕 Romance</span>
                                            </label>
                                            <label
                                                class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                <input type="radio" name="thumbnailStyle" value="anime"
                                                    class="text-purple-600">
                                                <span class="text-sm">🎌 Anime</span>
                                            </label>
                                            <label
                                                class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                <input type="radio" name="thumbnailStyle" value="vintage"
                                                    class="text-purple-600">
                                                <span class="text-sm">📜 Vintage</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Custom Prompt -->
                                    <div id="customPromptSection">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Mô tả cảnh nền <span class="text-xs text-gray-400">(tùy chọn)</span>:
                                        </label>
                                        <textarea id="thumbnailCustomPrompt" rows="2"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-purple-500 focus:outline-none"
                                            placeholder="Ví dụ: người đàn ông cầm kiếm đứng trên núi, hoàng hôn..."></textarea>
                                        <p class="text-xs text-gray-400 mt-1">💡 Mô tả hình nền bạn muốn. Thông tin sách
                                            (tiêu đề, tác giả) sẽ tự động được thêm vào.</p>
                                    </div>

                                    <!-- Use Cover Image Option -->
                                    @if ($audioBook->cover_image)
                                        <div
                                            class="p-3 bg-gradient-to-r from-orange-50 to-amber-50 border border-orange-200 rounded-lg">
                                            <label class="flex items-start gap-3 cursor-pointer">
                                                <input type="checkbox" id="useCoverImageOption"
                                                    class="mt-1 text-orange-600 rounded">
                                                <div class="flex-1">
                                                    <span class="text-sm font-medium text-orange-800">🖼️ Sử dụng ảnh bìa
                                                        làm nền</span>
                                                    <p class="text-xs text-orange-600 mt-1">Lấy ảnh bìa sách và thêm text
                                                        overlay (tiêu đề, tác giả, chương) lên đó.</p>
                                                </div>
                                                <img src="{{ asset('storage/' . $audioBook->cover_image) }}"
                                                    class="w-12 h-16 object-cover rounded border" alt="Cover">
                                            </label>
                                        </div>
                                    @endif

                                    <!-- AI Research Option -->
                                    <div id="aiResearchSection"
                                        class="p-3 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-lg">
                                        <label class="flex items-start gap-3 cursor-pointer">
                                            <input type="checkbox" id="aiResearchOption"
                                                class="mt-1 text-green-600 rounded">
                                            <div>
                                                <span class="text-sm font-medium text-green-800">🔍 AI tự tìm thông tin về
                                                    truyện</span>
                                                <p class="text-xs text-green-600 mt-1">AI sẽ tìm kiếm thông tin trên
                                                    internet về nội dung truyện và tự động tạo prompt hình ảnh phù hợp.</p>
                                            </div>
                                        </label>
                                    </div>

                                    <button type="button" id="generateThumbnailBtn"
                                        class="w-full bg-purple-600 hover:bg-purple-700 text-white py-2.5 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                                        🖼️ Tạo Hình Nền (Không chữ)
                                    </button>

                                    <button type="button" id="generateThumbnailWithTextBtn"
                                        class="w-full bg-gradient-to-r from-orange-500 to-pink-500 hover:from-orange-600 hover:to-pink-600 text-white py-2.5 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                                        ✨ Tạo Thumbnail (AI Vẽ Chữ Luôn)
                                    </button>

                                    <p class="text-xs text-gray-500 text-center mt-1">
                                        💡 <strong>Không chữ:</strong> AI tạo hình nền → bạn thêm text sau bằng FFmpeg<br>
                                        <strong>AI Vẽ Chữ:</strong> AI tạo hình VÀ vẽ chữ trực tiếp vào hình (1 bước)
                                    </p>

                                    <div id="thumbnailStatus" class="text-sm"></div>
                                </div>
                            </div>

                            <!-- Video Scenes Generator -->
                            <div class="bg-gradient-to-br from-blue-50 to-cyan-50 border border-blue-200 rounded-lg p-5">
                                <h4 class="text-base font-semibold text-blue-800 mb-4 flex items-center gap-2">
                                    🎬 Tạo Hình Minh Họa cho Video <span class="text-xs font-normal text-gray-500">(không
                                        có chữ)</span>
                                </h4>

                                <div class="space-y-4">
                                    <!-- Style Selection -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Phong cách:</label>
                                        <select id="sceneStyle"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-blue-500 focus:outline-none">
                                            <option value="cinematic">🎬 Cinematic - Điện ảnh</option>
                                            <option value="anime">🎌 Anime - Hoạt hình Nhật</option>
                                            <option value="illustration">🎨 Illustration - Minh họa</option>
                                            <option value="realistic">📷 Realistic - Thực tế</option>
                                        </select>
                                    </div>

                                    <div
                                        class="p-3 bg-gradient-to-r from-blue-50 to-purple-50 border border-blue-200 rounded-lg">
                                        <p class="text-xs text-blue-800 mb-2">
                                            🤖 <strong>Quy trình 2 bước:</strong>
                                        </p>
                                        <ul class="text-xs text-gray-700 space-y-1 ml-4 list-disc">
                                            <li><strong>Bước 1:</strong> AI phân tích giới thiệu sách → tạo danh sách phân
                                                cảnh + prompt</li>
                                            <li><strong>Bước 2:</strong> Xem lại prompt, có thể sửa → tạo ảnh từng cảnh</li>
                                        </ul>
                                    </div>

                                    <!-- Step 1 Button -->
                                    <button type="button" id="analyzeSceneBtn"
                                        class="w-full bg-purple-600 hover:bg-purple-700 text-white py-2.5 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                                        🧠 Bước 1: AI Phân Tích Nội Dung
                                    </button>

                                    <div id="scenesStatus" class="text-sm"></div>

                                    <!-- Scene Analysis Results (hidden until step 1 done) -->
                                    <div id="sceneAnalysisResults" class="hidden space-y-3">
                                        <div class="flex items-center justify-between">
                                            <h5 class="text-sm font-semibold text-gray-800">📋 Kết quả phân tích:</h5>
                                            <span id="sceneAnalysisCount"
                                                class="text-xs text-purple-600 font-medium"></span>
                                        </div>
                                        <div id="scenePromptsList" class="space-y-2 max-h-80 overflow-y-auto"></div>

                                        <!-- Step 2 Button -->
                                        <button type="button" id="generateAllScenesBtn"
                                            class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                                            🎨 Bước 2: Tạo Ảnh Tất Cả Cảnh
                                        </button>
                                    </div>

                                    <!-- Scenes Progress -->
                                    <div id="scenesProgress" class="hidden">
                                        <div class="flex items-center justify-between text-xs text-blue-700 mb-1">
                                            <span id="scenesProgressText">Đang tạo cảnh 0/5...</span>
                                            <span id="scenesProgressPercent">0%</span>
                                        </div>
                                        <div class="w-full bg-blue-100 rounded-full h-2">
                                            <div id="scenesProgressBar"
                                                class="bg-blue-500 h-2 rounded-full transition-all duration-300"
                                                style="width: 0%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ========== DESCRIPTION VIDEO PIPELINE (Chunked) ========== -->
                            <div
                                class="bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-300 rounded-lg p-5">
                                <h4 class="text-base font-semibold text-emerald-800 mb-3 flex items-center gap-2">
                                    🎬 Tạo Video Giới Thiệu Sách (Pipeline)
                                </h4>
                                <p class="text-xs text-gray-600 mb-4">
                                    Pipeline tự động: AI chia nội dung → tạo ảnh minh họa → TTS → subtitle → ghép video +
                                    nhạc nền.
                                </p>

                                <!-- Pipeline Steps -->
                                <div class="space-y-3">
                                    <!-- Step 1+2: Chunk + Analyze -->
                                    <div class="p-3 bg-white rounded-lg border border-emerald-200">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="bg-emerald-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center">1</span>
                                                <span class="text-sm font-medium text-gray-800">AI Phân tích & Chia
                                                    đoạn</span>
                                            </div>
                                            <button type="button" id="descChunkBtn"
                                                class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs px-4 py-2 rounded-lg font-medium transition">
                                                🧠 Phân tích
                                            </button>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1 ml-8">AI đọc giới thiệu sách → chia thành từng
                                            đoạn + tạo prompt ảnh minh họa</p>
                                        <div id="descChunkStatus" class="mt-2 text-sm ml-8"></div>
                                    </div>

                                    <!-- Chunks List (hidden until step 1 done) -->
                                    <div id="descChunksList" class="hidden">
                                        <div
                                            class="p-3 bg-white rounded-lg border border-gray-200 max-h-[500px] overflow-y-auto">
                                            <div class="flex items-center justify-between mb-2">
                                                <h5 class="text-sm font-semibold text-gray-800">📋 Danh sách chunks:</h5>
                                                <span id="descChunksCount"
                                                    class="text-xs text-emerald-600 font-medium"></span>
                                            </div>
                                            <div id="descChunksItems" class="space-y-2"></div>
                                        </div>
                                    </div>

                                    <!-- Step 3: Generate Images -->
                                    <div class="p-3 bg-white rounded-lg border border-blue-200">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="bg-blue-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center">2</span>
                                                <span class="text-sm font-medium text-gray-800">Tạo ảnh minh họa</span>
                                            </div>
                                            <button type="button" id="descGenImagesBtn"
                                                class="bg-blue-600 hover:bg-blue-700 text-white text-xs px-4 py-2 rounded-lg font-medium transition hidden">
                                                🎨 Tạo tất cả ảnh
                                            </button>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1 ml-8">Tạo ảnh minh họa cho từng chunk bằng
                                            Gemini AI</p>
                                        <div id="descGenImagesStatus" class="mt-2 text-sm ml-8"></div>
                                    </div>

                                    <!-- Step 4: Generate TTS Audio -->
                                    <div class="p-3 bg-white rounded-lg border border-purple-200">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="bg-purple-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center">3</span>
                                                <span class="text-sm font-medium text-gray-800">Tạo TTS audio</span>
                                            </div>
                                            <button type="button" id="descGenTtsBtn"
                                                class="bg-purple-600 hover:bg-purple-700 text-white text-xs px-4 py-2 rounded-lg font-medium transition hidden">
                                                🎙️ Tạo tất cả audio
                                            </button>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1 ml-8">Chuyển text từng chunk thành audio với
                                            TTS (dùng giọng MC đã chọn)</p>
                                        <div id="descGenTtsStatus" class="mt-2 text-sm ml-8"></div>
                                    </div>

                                    <!-- Step 5: Generate SRT Subtitles -->
                                    <div class="p-3 bg-white rounded-lg border border-amber-200">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="bg-amber-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center">4</span>
                                                <span class="text-sm font-medium text-gray-800">Tạo phụ đề SRT</span>
                                            </div>
                                            <button type="button" id="descGenSrtBtn"
                                                class="bg-amber-600 hover:bg-amber-700 text-white text-xs px-4 py-2 rounded-lg font-medium transition hidden">
                                                📝 Tạo tất cả SRT
                                            </button>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1 ml-8">Tạo subtitle cho từng chunk (chia theo
                                            câu, thời gian tỷ lệ)</p>
                                        <div id="descGenSrtStatus" class="mt-2 text-sm ml-8"></div>
                                    </div>

                                    <!-- Step 6: Compose Final Video -->
                                    <div class="p-3 bg-white rounded-lg border border-red-200">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="bg-red-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center">5</span>
                                                <span class="text-sm font-medium text-gray-800">Ghép video hoàn
                                                    chỉnh</span>
                                            </div>
                                            <button type="button" id="descComposeBtn"
                                                class="bg-red-600 hover:bg-red-700 text-white text-xs px-4 py-2 rounded-lg font-medium transition hidden">
                                                🎥 Ghép video
                                            </button>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1 ml-8">Ghép ảnh + audio + subtitle +
                                            intro/outro music → video hoàn chỉnh</p>
                                        <div id="descComposeStatus" class="mt-2 text-sm ml-8"></div>
                                    </div>

                                    <!-- Progress Bar -->
                                    <div id="descPipelineProgress" class="hidden">
                                        <div class="flex items-center justify-between text-xs text-emerald-700 mb-1">
                                            <span id="descPipelineProgressText">Đang xử lý...</span>
                                            <span id="descPipelineProgressPercent">0%</span>
                                        </div>
                                        <div class="w-full bg-emerald-100 rounded-full h-2">
                                            <div id="descPipelineProgressBar"
                                                class="bg-emerald-500 h-2 rounded-full transition-all duration-300"
                                                style="width: 0%"></div>
                                        </div>
                                    </div>

                                    <!-- Final Video Player -->
                                    <div id="descVideoResultContainer" class="hidden">
                                        <div
                                            class="p-3 bg-gradient-to-r from-emerald-100 to-teal-100 border border-emerald-300 rounded-lg">
                                            <div class="flex items-center justify-between mb-2">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-lg">🎬</span>
                                                    <span class="text-sm font-medium text-emerald-800">Video Giới Thiệu
                                                        Sách</span>
                                                    <span id="descVideoDuration2" class="text-xs text-emerald-600"></span>
                                                </div>
                                                <a id="descVideoDownloadBtn" href="#" download
                                                    class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded transition">
                                                    ⬇️ Download
                                                </a>
                                            </div>
                                            <video id="descVideoPlayer2" controls
                                                class="w-full rounded border border-emerald-300"></video>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Media Gallery -->
                        <div class="mt-8">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-base font-semibold text-gray-800">📁 Thư viện Media đã tạo</h4>
                                <div class="flex gap-2">
                                    <button type="button" onclick="deleteAllMedia('thumbnails')"
                                        class="text-xs bg-orange-100 hover:bg-orange-200 text-orange-700 px-3 py-1.5 rounded transition">
                                        🗑️ Xóa tất cả Thumbnails
                                    </button>
                                    <button type="button" onclick="deleteAllMedia('scenes')"
                                        class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-3 py-1.5 rounded transition">
                                        🗑️ Xóa tất cả Scenes
                                    </button>
                                </div>
                            </div>

                            <!-- Thumbnails Section -->
                            <div class="mb-6">
                                <h5 class="text-sm font-medium text-gray-700 mb-3 flex items-center gap-2">
                                    🖼️ Thumbnails
                                    <span id="thumbnailCount"
                                        class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">0</span>
                                </h5>
                                <div id="thumbnailGallery" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                    <div class="text-center py-8 text-gray-400 col-span-full">
                                        <span class="text-3xl">🖼️</span>
                                        <p class="text-sm mt-2">Chưa có thumbnail nào</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Scenes Section -->
                            <div class="mb-6">
                                <h5 class="text-sm font-medium text-gray-700 mb-3 flex items-center gap-2">
                                    🎬 Video Scenes
                                    <span id="sceneCount2"
                                        class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">0</span>
                                </h5>
                                <div id="sceneGallery" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                    <div class="text-center py-8 text-gray-400 col-span-full">
                                        <span class="text-3xl">🎬</span>
                                        <p class="text-sm mt-2">Chưa có scene nào</p>
                                    </div>
                                </div>

                                <!-- Scene Slideshow Video Generator -->
                                <div id="sceneSlideshowSection" class="mt-4 hidden">
                                    <div
                                        class="p-4 bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-lg">
                                        <div class="flex items-center justify-between mb-3">
                                            <h5 class="text-sm font-semibold text-indigo-800 flex items-center gap-2">
                                                🎥 Ghép Phân Cảnh với Audio
                                            </h5>
                                            <span class="text-xs text-gray-500">Chia thời lượng theo độ dài nội dung mỗi
                                                cảnh</span>
                                        </div>
                                        <p class="text-xs text-gray-600 mb-3">
                                            Tạo video slideshow từ các ảnh phân cảnh + audio giới thiệu sách. Mỗi cảnh sẽ
                                            hiển thị trong khoảng thời gian tỷ lệ với độ dài mô tả của nó, kèm hiệu ứng zoom
                                            + chuyển cảnh.
                                        </p>
                                        <button type="button" id="generateSlideshowBtn"
                                            class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                                            🎥 Tạo Video từ Phân Cảnh + Audio
                                        </button>
                                        <div id="slideshowStatus" class="mt-2 text-sm"></div>
                                        <div id="slideshowProgress" class="hidden mt-2">
                                            <div class="flex items-center justify-between text-xs text-indigo-700 mb-1">
                                                <span>Đang tạo video slideshow...</span>
                                                <span class="animate-pulse">⏳</span>
                                            </div>
                                            <div class="w-full bg-indigo-100 rounded-full h-2">
                                                <div class="bg-indigo-500 h-2 rounded-full animate-pulse"
                                                    style="width: 60%"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Slideshow Video Player -->
                                    <div id="slideshowVideoContainer" class="mt-3 hidden">
                                        <div
                                            class="p-3 bg-gradient-to-r from-indigo-100 to-purple-100 border border-indigo-300 rounded-lg">
                                            <div class="flex items-center justify-between mb-2">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-lg">🎥</span>
                                                    <span class="text-sm font-medium text-indigo-800">Video Phân Cảnh +
                                                        Audio</span>
                                                    <span id="slideshowDuration" class="text-xs text-indigo-600"></span>
                                                </div>
                                                <div class="flex gap-2">
                                                    <a id="slideshowDownloadBtn" href="#" download
                                                        class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded transition">
                                                        ⬇️ Download
                                                    </a>
                                                    <button type="button" id="deleteSlideshowBtn"
                                                        class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded transition"
                                                        title="Xóa video">
                                                        🗑️
                                                    </button>
                                                </div>
                                            </div>
                                            <video id="slideshowVideoPlayer" controls
                                                class="w-full rounded border border-indigo-300">
                                            </video>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Animations Section -->
                            <div class="mb-6">
                                <h5 class="text-sm font-medium text-gray-700 mb-3 flex items-center gap-2">
                                    ✨ Animations (Kling AI)
                                    <span id="animationCount"
                                        class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">0</span>
                                </h5>
                                <div id="animationGallery" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                    <div class="text-center py-8 text-gray-400 col-span-full">
                                        <span class="text-3xl">✨</span>
                                        <p class="text-sm mt-2">Chưa có animation nào</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Auto Publish Tab Content -->
            <div id="auto-publish-tab" class="tab-content hidden">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">🚀 Phát hành tự động lên YouTube</h3>

                        {{-- YouTube Connection Status --}}
                        <div id="publishYtStatus" class="mb-6 p-4 rounded-lg border">
                            <div class="flex items-center gap-2">
                                <span class="text-gray-500">Đang kiểm tra kết nối YouTube...</span>
                                <svg class="animate-spin h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            </div>
                        </div>

                        <div id="publishFormWrapper" class="hidden">
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                {{-- Left Column: Settings --}}
                                <div class="lg:col-span-2 space-y-6">

                                    {{-- Publish Mode --}}
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3">Chế độ phát
                                            hành</label>
                                        <div class="flex gap-4">
                                            <label
                                                class="flex items-center gap-2 px-4 py-2 border rounded-lg cursor-pointer hover:bg-blue-50 transition publish-mode-label">
                                                <input type="radio" name="publishMode" value="single" checked
                                                    class="text-blue-600 publish-mode-radio">
                                                <span class="text-sm font-medium">🎬 Video đơn lẻ</span>
                                            </label>
                                            <label
                                                class="flex items-center gap-2 px-4 py-2 border rounded-lg cursor-pointer hover:bg-blue-50 transition publish-mode-label">
                                                <input type="radio" name="publishMode" value="shorts"
                                                    class="text-blue-600 publish-mode-radio">
                                                <span class="text-sm font-medium">📱 YouTube Shorts</span>
                                            </label>
                                            <label
                                                class="flex items-center gap-2 px-4 py-2 border rounded-lg cursor-pointer hover:bg-blue-50 transition publish-mode-label">
                                                <input type="radio" name="publishMode" value="playlist"
                                                    class="text-blue-600 publish-mode-radio">
                                                <span class="text-sm font-medium">📋 Playlist</span>
                                            </label>
                                        </div>
                                    </div>

                                    {{-- Video Source Selection --}}
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3">Chọn video
                                            nguồn</label>
                                        <div id="publishVideoSources"
                                            class="space-y-2 max-h-60 overflow-y-auto border rounded-lg p-3">
                                            <p class="text-sm text-gray-400">Đang tải danh sách video...</p>
                                        </div>
                                        <p class="text-xs text-gray-400 mt-1" id="publishSourceHint">Chọn 1 video để
                                            upload (chế độ Video đơn lẻ / Shorts)</p>
                                    </div>

                                    {{-- Privacy Setting --}}
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-2">Quyền riêng
                                            tư</label>
                                        <select id="publishPrivacy"
                                            class="w-full sm:w-auto border-gray-300 rounded-lg text-sm">
                                            <option value="private">🔒 Riêng tư (Private)</option>
                                            <option value="unlisted">🔗 Không công khai (Unlisted)</option>
                                            <option value="public">🌍 Công khai (Public)</option>
                                        </select>
                                    </div>

                                    {{-- Video Title --}}
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-2">Tiêu đề video</label>
                                        <div class="flex gap-2">
                                            <input type="text" id="publishTitle"
                                                class="flex-1 border-gray-300 rounded-lg text-sm"
                                                placeholder="Nhập tiêu đề video..." value="{{ $audioBook->title }}">
                                            <button type="button" id="aiGenerateTitleBtn"
                                                class="px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition whitespace-nowrap">
                                                🤖 AI Viết
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Video Description --}}
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-2">Mô tả video</label>
                                        <div class="flex gap-2 mb-2">
                                            <button type="button" id="aiGenerateDescBtn"
                                                class="px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition whitespace-nowrap">
                                                🤖 AI Viết mô tả
                                            </button>
                                        </div>
                                        <textarea id="publishDescription" rows="6" class="w-full border-gray-300 rounded-lg text-sm"
                                            placeholder="Nhập mô tả video...">{{ $audioBook->description ? Str::limit($audioBook->description, 500) : '' }}</textarea>
                                    </div>

                                    {{-- Tags --}}
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-2">Tags (phân cách bằng
                                            dấu phẩy)</label>
                                        <input type="text" id="publishTags"
                                            class="w-full border-gray-300 rounded-lg text-sm"
                                            placeholder="audiobook, sách nói, {{ $audioBook->author }}..."
                                            value="audiobook, sách nói, {{ $audioBook->category }}, {{ $audioBook->author }}">
                                    </div>

                                    {{-- Playlist Section (hidden by default) --}}
                                    <div id="playlistSection" class="hidden">
                                        <div class="border-t pt-4">
                                            <div class="flex items-center justify-between mb-3">
                                                <label class="block text-sm font-semibold text-gray-700">Playlist: Phiên
                                                    bản con cho từng video</label>
                                                <button type="button" id="generatePlaylistMetaBtn"
                                                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                                                    🔄 Tạo phiên bản con (AI)
                                                </button>
                                            </div>
                                            <p class="text-xs text-gray-500 mb-3">AI sẽ chuyển tiêu đề và mô tả chung thành
                                                phiên bản riêng cho từng chapter video trong playlist.</p>
                                            <div id="playlistMetaList" class="space-y-3">
                                                <p class="text-sm text-gray-400 italic">Chọn nhiều video nguồn và nhấn "Tạo
                                                    phiên bản con" để bắt đầu.</p>
                                            </div>
                                        </div>

                                        {{-- Playlist Name --}}
                                        <div class="mt-4">
                                            <label class="block text-sm font-semibold text-gray-700 mb-2">Tên
                                                Playlist</label>
                                            <input type="text" id="playlistName"
                                                class="w-full border-gray-300 rounded-lg text-sm"
                                                placeholder="Tên playlist trên YouTube..."
                                                value="{{ $audioBook->title }} - Sách Nói">
                                        </div>
                                    </div>
                                </div>

                                {{-- Right Column: Thumbnail --}}
                                <div class="space-y-6">
                                    {{-- Thumbnail Selection --}}
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3">Chọn
                                            Thumbnail</label>
                                        <div id="publishThumbnailGallery"
                                            class="grid grid-cols-2 gap-2 max-h-80 overflow-y-auto border rounded-lg p-3">
                                            <p class="text-sm text-gray-400 col-span-2">Đang tải thumbnails...</p>
                                        </div>
                                        <input type="hidden" id="publishSelectedThumbnail" value="">
                                        <p class="text-xs text-gray-400 mt-1">Chọn thumbnail từ media đã tạo. Vào tab
                                            "YouTube Media (AI)" để tạo thêm.</p>
                                    </div>

                                    {{-- Selected Thumbnail Preview --}}
                                    <div id="publishThumbnailPreview" class="hidden">
                                        <label class="block text-sm font-semibold text-gray-700 mb-2">Thumbnail đã
                                            chọn</label>
                                        <img id="publishThumbnailPreviewImg" src="" alt="Selected thumbnail"
                                            class="w-full rounded-lg border shadow-sm">
                                    </div>
                                </div>
                            </div>

                            {{-- Publish Button --}}
                            <div class="mt-8 border-t pt-6">
                                <div class="flex items-center gap-4">
                                    <button type="button" id="publishToYoutubeBtn"
                                        class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg transition text-base">
                                        🚀 Phát hành lên YouTube
                                    </button>
                                    <div id="publishProgress" class="hidden flex-1">
                                        <div class="flex items-center gap-3">
                                            <svg class="animate-spin h-5 w-5 text-red-500"
                                                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10"
                                                    stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            <span id="publishProgressText" class="text-sm text-gray-600">Đang
                                                upload...</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                                            <div id="publishProgressBar"
                                                class="bg-red-600 h-2 rounded-full transition-all duration-300"
                                                style="width: 0%"></div>
                                        </div>
                                    </div>
                                </div>
                                <div id="publishResult" class="mt-4 hidden"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chapters Tab Content -->
            <div id="chapters-tab" class="tab-content">
                <!-- Chapters Section -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6" id="chapterToolbarAnchor">
                            <div class="flex items-center gap-4">
                                <h3 class="text-lg font-semibold text-gray-800">📖 Danh sách chương</h3>
                                @if ($audioBook->chapters->count() > 0)
                                    <label class="inline-flex items-center gap-1 text-sm text-gray-600 cursor-pointer">
                                        <input type="checkbox" id="selectAllChapters" class="rounded">
                                        <span>Chọn tất cả</span>
                                    </label>
                                @endif
                            </div>
                            <div class="flex gap-2" id="chapterToolbarButtons">
                                <button id="generateSelectedTtsBtn" onclick="generateTtsForSelectedChapters()"
                                    class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 hidden">
                                    🎙️ Tạo TTS (<span id="selectedCount">0</span>)
                                </button>
                                <button id="generateSelectedVideoBtn" onclick="generateVideoForSelectedChapters()"
                                    class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 hidden">
                                    🎬 Tạo Video (<span id="selectedVideoCount">0</span>)
                                </button>
                                <button id="deleteSelectedChaptersBtn" onclick="deleteSelectedChapters()"
                                    class="bg-gray-700 hover:bg-gray-800 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 hidden">
                                    🗑️ Xóa đã chọn
                                </button>
                                <button onclick="openScrapeModal()"
                                    class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                    🌐 Scrape
                                </button>
                                <a href="{{ route('audiobooks.chapters.create', $audioBook) }}"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                    + Thêm chương
                                </a>
                            </div>
                        </div>

                        <!-- Floating Toolbar (appears when scrolled past original) -->
                        <div id="chapterFloatingToolbar"
                            class="fixed top-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-sm shadow-lg border-b border-gray-200 px-6 py-3 transition-all duration-300"
                            style="display: none; transform: translateY(-100%);">
                            <div class="max-w-7xl mx-auto flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <span class="text-sm font-semibold text-gray-700">📖 {{ $audioBook->title }}</span>
                                    @if ($audioBook->chapters->count() > 0)
                                        <label class="inline-flex items-center gap-1 text-sm text-gray-600 cursor-pointer">
                                            <input type="checkbox" id="selectAllChaptersFloating" class="rounded">
                                            <span>Chọn tất cả</span>
                                        </label>
                                    @endif
                                </div>
                                <div class="flex gap-2">
                                    <button id="generateSelectedTtsBtnFloating" onclick="generateTtsForSelectedChapters()"
                                        class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm hidden">
                                        🎙️ TTS (<span id="selectedCountFloating">0</span>)
                                    </button>
                                    <button id="generateSelectedVideoBtnFloating"
                                        onclick="generateVideoForSelectedChapters()"
                                        class="bg-red-600 hover:bg-red-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm hidden">
                                        🎬 Video (<span id="selectedVideoCountFloating">0</span>)
                                    </button>
                                    <button id="deleteSelectedChaptersBtnFloating" onclick="deleteSelectedChapters()"
                                        class="bg-gray-700 hover:bg-gray-800 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm hidden">
                                        🗑️ Xóa đã chọn
                                    </button>
                                    <button onclick="openScrapeModal()"
                                        class="bg-green-600 hover:bg-green-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm">
                                        🌐 Scrape
                                    </button>
                                    <a href="{{ route('audiobooks.chapters.create', $audioBook) }}"
                                        class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm">
                                        + Thêm chương
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- TTS Progress -->
                        <div id="ttsProgressContainer"
                            class="hidden mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-blue-800" id="ttsProgressStatus">Đang tạo
                                    TTS...</span>
                                <span class="text-sm text-blue-600" id="ttsProgressPercent">0%</span>
                            </div>
                            <div class="w-full bg-blue-200 rounded-full h-2 mb-3">
                                <div id="ttsProgressBar" class="bg-blue-600 h-2 rounded-full transition-all duration-300"
                                    style="width: 0%"></div>
                            </div>
                            <!-- Detailed chunk progress -->
                            <div id="ttsChunkProgress">
                                <div class="flex items-center justify-between text-xs text-blue-700 mb-1">
                                    <span id="ttsChunkStatus">Chương 1: Đoạn 0/0</span>
                                    <span id="ttsChunkPercent">0%</span>
                                </div>
                                <div class="w-full bg-blue-100 rounded-full h-1.5">
                                    <div id="ttsChunkBar"
                                        class="bg-blue-400 h-1.5 rounded-full transition-all duration-200"
                                        style="width: 0%"></div>
                                </div>
                            </div>
                            <!-- Real-time generated chunks display -->
                            <div id="ttsGeneratedChunks"
                                class="mt-3 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2">
                            </div>
                            <!-- Log output -->
                            <div id="ttsLogContainer"
                                class="mt-3 max-h-40 overflow-y-auto text-xs font-mono bg-gray-900 text-green-400 p-2 rounded">
                            </div>
                        </div>

                        @if ($audioBook->chapters->count() > 0)
                            <div class="space-y-3">
                                @foreach ($audioBook->chapters as $chapter)
                                    <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition duration-200"
                                        id="chapter-{{ $chapter->id }}">
                                        <div class="flex items-start justify-between">
                                            <div class="flex-1">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <!-- Checkbox -->
                                                    <input type="checkbox" class="chapter-checkbox rounded"
                                                        data-chapter-id="{{ $chapter->id }}"
                                                        data-chapter-number="{{ $chapter->chapter_number }}">

                                                    @if ($chapter->cover_image)
                                                        <img src="{{ asset('storage/' . $chapter->cover_image) }}"
                                                            alt="{{ $chapter->title }}"
                                                            class="w-20 h-12 object-cover rounded cursor-pointer hover:opacity-80 transition border shadow-sm"
                                                            onclick="openImagePreview('{{ asset('storage/' . $chapter->cover_image) }}')"
                                                            title="Click để xem lớn">
                                                    @else
                                                        <div class="w-20 h-12 bg-gray-100 rounded flex items-center justify-center border border-dashed border-gray-300"
                                                            title="Chưa có ảnh bìa">
                                                            <span class="text-lg">📄</span>
                                                        </div>
                                                    @endif

                                                    @php
                                                        $charCount = mb_strlen($chapter->content, 'UTF-8');
                                                        // Ước tính: 150 từ/phút đọc, trung bình 5 ký tự/từ → 750 ký tự/phút
                                                        $readingMinutes = ceil($charCount / 750);
                                                        $readingTime =
                                                            $readingMinutes >= 60
                                                                ? floor($readingMinutes / 60) .
                                                                    'h ' .
                                                                    $readingMinutes % 60 .
                                                                    'p'
                                                                : $readingMinutes . ' phút';
                                                        $estimatedChunks = ceil($charCount / 2000);

                                                        // Check if title already has chapter number prefix
                                                        $hasChapterPrefix = preg_match(
                                                            '/^(Chương|Chapter|Phần)\s*\d+/iu',
                                                            $chapter->title,
                                                        );
                                                    @endphp
                                                    <div>
                                                        <h4 class="font-semibold text-gray-800">
                                                            @if ($hasChapterPrefix)
                                                                {{ $chapter->title }}
                                                            @else
                                                                Chương {{ $chapter->chapter_number }}:
                                                                {{ $chapter->title }}
                                                            @endif
                                                        </h4>
                                                        <div class="flex flex-wrap gap-2 mt-1">
                                                            <span
                                                                class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded">
                                                                📝 {{ number_format($charCount) }} ký tự
                                                            </span>
                                                            <span
                                                                class="text-xs text-gray-500 bg-blue-50 px-2 py-1 rounded">
                                                                ⏱️ ~{{ $readingTime }}
                                                            </span>
                                                            <span
                                                                class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded">
                                                                📦 {{ $chapter->chunks->count() }}/{{ $estimatedChunks }}
                                                                đoạn
                                                            </span>
                                                            @if ($chapter->status == 'pending')
                                                                <span
                                                                    class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded">⏳
                                                                    Chưa tạo TTS</span>
                                                            @elseif($chapter->status == 'processing')
                                                                <span
                                                                    class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">⚙️
                                                                    Đang xử lý</span>
                                                            @elseif($chapter->status == 'completed')
                                                                <span
                                                                    class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded">✅
                                                                    Hoàn tất</span>
                                                            @elseif($chapter->status == 'error')
                                                                <span
                                                                    class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded">❌
                                                                    Lỗi</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>

                                                @if ($chapter->error_message)
                                                    <div
                                                        class="mt-2 p-2 bg-red-50 border border-red-200 rounded text-sm text-red-600">
                                                        {{ $chapter->error_message }}
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="flex gap-2 ml-4">
                                                <a href="{{ route('audiobooks.chapters.edit', [$audioBook, $chapter]) }}"
                                                    class="bg-blue-100 hover:bg-blue-200 text-blue-700 font-semibold py-1 px-3 rounded transition duration-200 text-sm">
                                                    ✏️ Sửa
                                                </a>
                                                @if ($chapter->status != 'processing')
                                                    <form
                                                        action="{{ route('audiobooks.chapters.generate-tts', [$audioBook, $chapter]) }}"
                                                        method="POST" class="inline">
                                                        @csrf
                                                        <button type="submit"
                                                            class="bg-purple-100 hover:bg-purple-200 text-purple-700 font-semibold py-1 px-3 rounded transition duration-200 text-sm">
                                                            🎙️ TTS
                                                        </button>
                                                    </form>
                                                @endif
                                                <form
                                                    action="{{ route('audiobooks.chapters.destroy', [$audioBook, $chapter]) }}"
                                                    method="POST" class="inline"
                                                    onsubmit="return confirm('Xóa chương này?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                        class="bg-red-100 hover:bg-red-200 text-red-700 font-semibold py-1 px-3 rounded transition duration-200 text-sm">
                                                        🗑️
                                                    </button>
                                                </form>
                                            </div>
                                        </div>

                                        <!-- Audio Preview -->
                                        @if ($chapter->chunks->where('status', 'completed')->count() > 0 || $chapter->audio_file)
                                            <div class="mt-4 pt-4 border-t border-gray-100">
                                                <div class="flex items-center justify-between mb-3">
                                                    <p class="text-sm font-medium text-gray-600">🎵 Các đoạn âm thanh:</p>
                                                    <div class="flex gap-2">
                                                        <button onclick="deleteChapterAudio({{ $chapter->id }}, false)"
                                                            class="text-xs bg-orange-100 hover:bg-orange-200 text-orange-700 px-2 py-1 rounded transition">
                                                            🗑️ Xóa file full
                                                        </button>
                                                        <button onclick="deleteChapterAudio({{ $chapter->id }}, true)"
                                                            class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded transition">
                                                            🗑️ Xóa tất cả
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Full chapter audio (special styling) -->
                                                @if ($chapter->audio_file)
                                                    <div
                                                        class="mb-3 p-3 bg-gradient-to-r from-purple-100 to-pink-100 border-2 border-purple-300 rounded-lg">
                                                        <div class="flex items-center justify-between">
                                                            <div class="flex items-center gap-2">
                                                                <span class="text-lg">🎧</span>
                                                                <span class="font-semibold text-purple-800">Full Chương
                                                                    {{ $chapter->chapter_number }}</span>
                                                                @if ($chapter->total_duration)
                                                                    <span
                                                                        class="text-xs bg-purple-200 text-purple-700 px-2 py-0.5 rounded">
                                                                        {{ gmdate('H:i:s', (int) $chapter->total_duration) }}
                                                                    </span>
                                                                @endif
                                                            </div>
                                                            <audio controls class="h-8">
                                                                <source
                                                                    src="{{ asset('storage/' . $chapter->audio_file) }}"
                                                                    type="audio/mpeg">
                                                            </audio>
                                                        </div>
                                                    </div>
                                                @endif

                                                <!-- Video Preview (if exists) -->
                                                @if ($chapter->video_path)
                                                    <div
                                                        class="mb-3 p-3 bg-gradient-to-r from-blue-100 to-cyan-100 border-2 border-blue-300 rounded-lg">
                                                        <div class="flex items-center justify-between flex-wrap gap-2">
                                                            <div class="flex items-center gap-2">
                                                                <span class="text-lg">🎬</span>
                                                                <span class="font-semibold text-blue-800">Video Chương
                                                                    {{ $chapter->chapter_number }}</span>
                                                                @php
                                                                    $videoSize = file_exists(
                                                                        storage_path(
                                                                            'app/public/' . $chapter->video_path,
                                                                        ),
                                                                    )
                                                                        ? round(
                                                                            filesize(
                                                                                storage_path(
                                                                                    'app/public/' .
                                                                                        $chapter->video_path,
                                                                                ),
                                                                            ) /
                                                                                1024 /
                                                                                1024,
                                                                            1,
                                                                        )
                                                                        : 0;
                                                                @endphp
                                                                <span
                                                                    class="text-xs bg-blue-200 text-blue-700 px-2 py-0.5 rounded">
                                                                    {{ $videoSize }} MB
                                                                </span>
                                                            </div>
                                                            <div class="flex items-center gap-2">
                                                                <button
                                                                    onclick="openVideoPreview('{{ asset('storage/' . $chapter->video_path) }}')"
                                                                    class="bg-blue-500 hover:bg-blue-600 text-white text-xs px-3 py-1.5 rounded transition">
                                                                    ▶️ Xem
                                                                </button>
                                                                <a href="{{ asset('storage/' . $chapter->video_path) }}"
                                                                    download="chapter_{{ $chapter->chapter_number }}.mp4"
                                                                    class="bg-green-500 hover:bg-green-600 text-white text-xs px-3 py-1.5 rounded transition">
                                                                    ⬇️ Tải xuống
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif

                                                <!-- Chunk audios -->
                                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2"
                                                    id="chapter-{{ $chapter->id }}-chunks">
                                                    @foreach ($chapter->chunks->sortBy('chunk_number') as $chunk)
                                                        <div
                                                            class="flex items-center justify-between p-2 rounded text-sm
                                                        @if ($chunk->status === 'completed') bg-green-50 border border-green-200
                                                        @elseif($chunk->status === 'processing') bg-blue-50 border border-blue-200
                                                        @elseif($chunk->status === 'error') bg-red-50 border border-red-200
                                                        @else bg-gray-50 border border-gray-200 @endif">
                                                            <div class="flex items-center gap-2">
                                                                <span
                                                                    class="text-xs font-medium 
                                                                @if ($chunk->status === 'completed') text-green-700
                                                                @elseif($chunk->status === 'processing') text-blue-700
                                                                @elseif($chunk->status === 'error') text-red-700
                                                                @else text-gray-600 @endif">
                                                                    @if ($chunk->status === 'completed')
                                                                        ✅
                                                                    @elseif($chunk->status === 'processing')
                                                                        ⏳
                                                                    @elseif($chunk->status === 'error')
                                                                        ❌
                                                                    @else
                                                                        ⏸️
                                                                    @endif
                                                                    Đoạn {{ $chunk->chunk_number }}
                                                                </span>
                                                                @if ($chunk->duration)
                                                                    <span
                                                                        class="text-xs text-gray-500">{{ round($chunk->duration, 1) }}s</span>
                                                                @endif
                                                            </div>
                                                            <div class="flex items-center gap-1">
                                                                @if ($chunk->audio_file)
                                                                    <audio controls class="h-6"
                                                                        style="max-width: 150px;">
                                                                        <source
                                                                            src="{{ asset('storage/' . $chunk->audio_file) }}"
                                                                            type="audio/mpeg">
                                                                    </audio>
                                                                    <button
                                                                        onclick="deleteChunkAudio({{ $audioBook->id }}, {{ $chapter->id }}, {{ $chunk->id }}, this)"
                                                                        class="text-red-400 hover:text-red-600 hover:bg-red-50 p-1 rounded transition ml-1 flex-shrink-0"
                                                                        title="Xóa audio đoạn {{ $chunk->chunk_number }}">
                                                                        🗑️
                                                                    </button>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-12 bg-gray-50 rounded-lg">
                                <div class="text-4xl mb-4">📖</div>
                                <p class="text-gray-500 text-lg mb-6">Chưa có chương nào</p>
                                <div class="flex gap-3 justify-center">
                                    <button onclick="openScrapeModal()"
                                        class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                                        🌐 Scrape từ website
                                    </button>
                                    <a href="{{ route('audiobooks.chapters.create', $audioBook) }}"
                                        class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                                        + Thêm chương thủ công
                                    </a>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div> {{-- End chapters-tab --}}
        </div>
    </div>

    <!-- Image Preview Modal -->
    <div id="imagePreviewModal"
        class="hidden fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50 p-4"
        onclick="closeImagePreview()">
        <div class="relative max-w-6xl max-h-full flex flex-col items-center" onclick="event.stopPropagation()">
            <!-- Close button -->
            <button type="button" onclick="closeImagePreview()"
                class="absolute -top-2 -right-2 bg-white hover:bg-gray-100 text-gray-800 rounded-full w-10 h-10 flex items-center justify-center text-2xl font-bold shadow-lg transition z-20">
                ×
            </button>

            <!-- Image container with zoom -->
            <div class="overflow-auto max-h-[85vh] rounded-lg">
                <img id="previewImage" src="" alt="Preview"
                    class="max-w-none rounded-lg shadow-2xl cursor-zoom-in transition-transform duration-200"
                    style="max-height: 85vh; width: auto;" onclick="toggleImageZoom(this)">
            </div>

            <!-- Controls bar -->
            <div class="mt-4 flex items-center gap-3 bg-white bg-opacity-10 backdrop-blur-sm rounded-lg px-4 py-2"
                onclick="event.stopPropagation()">
                <button type="button" onclick="event.stopPropagation(); zoomImage(-0.2)"
                    class="bg-white bg-opacity-20 hover:bg-opacity-40 text-white px-3 py-1.5 rounded-lg text-sm font-medium transition">
                    ➖ Thu nhỏ
                </button>
                <button type="button" onclick="event.stopPropagation(); resetImageZoom()"
                    class="bg-white bg-opacity-20 hover:bg-opacity-40 text-white px-3 py-1.5 rounded-lg text-sm font-medium transition">
                    🔄 Reset
                </button>
                <button type="button" onclick="event.stopPropagation(); zoomImage(0.2)"
                    class="bg-white bg-opacity-20 hover:bg-opacity-40 text-white px-3 py-1.5 rounded-lg text-sm font-medium transition">
                    ➕ Phóng to
                </button>
                <span class="text-white text-sm mx-2">|</span>
                <a id="downloadImageLink" href="" download onclick="event.stopPropagation()"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-lg text-sm font-medium transition">
                    ⬇️ Tải về
                </a>
            </div>

            <!-- Zoom level indicator -->
            <div id="zoomLevelIndicator"
                class="absolute top-2 left-2 bg-black bg-opacity-60 text-white text-xs px-2 py-1 rounded">
                100%
            </div>
        </div>
    </div>

    <!-- Scrape Chapters Modal -->
    <div id="scrapeModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
            <h3 class="text-xl font-semibold mb-4">🌐 Scrape chương từ website</h3>
            <form id="scrapeForm">
                @csrf
                <input type="hidden" name="audio_book_id" value="{{ $audioBook->id }}">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">URL sách (nhasachmienphi.com)</label>
                    <input type="url" name="book_url" placeholder="https://nhasachmienphi.com/ten-sach.html"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        required>
                </div>

                <div class="flex gap-3">
                    <button type="submit"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold transition duration-200">
                        🚀 Bắt đầu Scrape
                    </button>
                    <button type="button" onclick="closeScrapeModal()"
                        class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-800 py-2 rounded-lg font-semibold transition duration-200">
                        Đóng
                    </button>
                </div>
            </form>
            <div id="scrapeStatus" class="mt-4 text-sm"></div>
        </div>
    </div>

    <!-- Chapter Cover Generation Modal -->
    <div id="chapterCoverModal"
        class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-5xl w-full mx-4 max-h-[95vh] overflow-hidden flex flex-col">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">📚 Tạo ảnh bìa chương từ hình nền</h3>
                <button type="button" onclick="closeChapterCoverModal()"
                    class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
            </div>

            <div class="flex-1 overflow-y-auto">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Left: Image Preview with Click to Position -->
                    <div>
                        <p class="text-sm font-medium text-gray-700 mb-2">📍 Click vào hình để chọn vị trí text:</p>
                        <div class="relative border-2 border-dashed border-purple-300 rounded-lg overflow-hidden bg-gray-50"
                            id="chapterCoverPreviewContainer">
                            <img id="selectedCoverImage" src="" alt="Selected"
                                class="w-full h-auto cursor-crosshair" onclick="selectTextPosition(event)">
                            <!-- Text position marker -->
                            <div id="textPositionMarker"
                                class="hidden absolute w-3 h-3 bg-red-500 rounded-full border-2 border-white shadow-lg transform -translate-x-1/2 -translate-y-1/2 pointer-events-none">
                            </div>
                            <!-- Live preview text -->
                            <div id="textLivePreview" class="hidden absolute pointer-events-none text-center"
                                style="transform: translate(-50%, -50%);">
                                <div id="previewChapterBadge"
                                    class="inline-block px-3 py-1 rounded font-bold shadow-lg">
                                    Chương 1
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">
                            💡 Click vào vị trí muốn đặt text "Chương X". Vị trí này sẽ áp dụng cho tất cả chương được chọn.
                        </p>
                        <input type="hidden" id="selectedCoverFilename" value="">
                        <input type="hidden" id="textPositionX" value="50">
                        <input type="hidden" id="textPositionY" value="15">
                    </div>

                    <!-- Right: Text Format Options -->
                    <div class="space-y-4">
                        <!-- Font Size -->
                        <div class="p-3 bg-purple-50 border border-purple-200 rounded-lg">
                            <label class="block text-sm font-medium text-purple-700 mb-2">📏 Kích thước chữ:</label>
                            <div class="flex items-center gap-3">
                                <input type="range" id="chapterFontSize" min="40" max="150"
                                    value="80" step="5"
                                    class="flex-1 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                    oninput="updateChapterTextPreview()">
                                <span id="fontSizeDisplay"
                                    class="text-sm font-semibold text-purple-700 w-12 text-center">80</span>
                            </div>
                        </div>

                        <!-- Text Color -->
                        <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <label class="block text-sm font-medium text-blue-700 mb-2">🎨 Màu chữ:</label>
                            <div class="grid grid-cols-4 gap-2">
                                <label
                                    class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                    <input type="radio" name="chapterTextColor" value="#FFFFFF" checked
                                        class="text-blue-600" onchange="updateChapterTextPreview()">
                                    <span class="flex items-center gap-1">
                                        <span class="w-4 h-4 rounded-full bg-white border"></span>
                                        <span class="text-xs">Trắng</span>
                                    </span>
                                </label>
                                <label
                                    class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                    <input type="radio" name="chapterTextColor" value="#FFFF00"
                                        class="text-blue-600" onchange="updateChapterTextPreview()">
                                    <span class="flex items-center gap-1">
                                        <span class="w-4 h-4 rounded-full bg-yellow-400"></span>
                                        <span class="text-xs">Vàng</span>
                                    </span>
                                </label>
                                <label
                                    class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                    <input type="radio" name="chapterTextColor" value="#00FFFF"
                                        class="text-blue-600" onchange="updateChapterTextPreview()">
                                    <span class="flex items-center gap-1">
                                        <span class="w-4 h-4 rounded-full bg-cyan-400"></span>
                                        <span class="text-xs">Cyan</span>
                                    </span>
                                </label>
                                <label
                                    class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                    <input type="radio" name="chapterTextColor" value="#FF00FF"
                                        class="text-blue-600" onchange="updateChapterTextPreview()">
                                    <span class="flex items-center gap-1">
                                        <span class="w-4 h-4 rounded-full bg-pink-500"></span>
                                        <span class="text-xs">Hồng</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Outline Color -->
                        <div class="p-3 bg-orange-50 border border-orange-200 rounded-lg">
                            <label class="block text-sm font-medium text-orange-700 mb-2">✏️ Viền chữ:</label>
                            <div class="grid grid-cols-4 gap-2">
                                <label
                                    class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-orange-400">
                                    <input type="radio" name="chapterOutlineColor" value="#000000" checked
                                        class="text-orange-600" onchange="updateChapterTextPreview()">
                                    <span class="flex items-center gap-1">
                                        <span class="w-4 h-4 rounded-full bg-black"></span>
                                        <span class="text-xs">Đen</span>
                                    </span>
                                </label>
                                <label
                                    class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-orange-400">
                                    <input type="radio" name="chapterOutlineColor" value="#8B00FF"
                                        class="text-orange-600" onchange="updateChapterTextPreview()">
                                    <span class="flex items-center gap-1">
                                        <span class="w-4 h-4 rounded-full bg-purple-600"></span>
                                        <span class="text-xs">Tím</span>
                                    </span>
                                </label>
                                <label
                                    class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-orange-400">
                                    <input type="radio" name="chapterOutlineColor" value="#FF0000"
                                        class="text-orange-600" onchange="updateChapterTextPreview()">
                                    <span class="flex items-center gap-1">
                                        <span class="w-4 h-4 rounded-full bg-red-600"></span>
                                        <span class="text-xs">Đỏ</span>
                                    </span>
                                </label>
                                <label
                                    class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-orange-400">
                                    <input type="radio" name="chapterOutlineColor" value="#0000FF"
                                        class="text-orange-600" onchange="updateChapterTextPreview()">
                                    <span class="flex items-center gap-1">
                                        <span class="w-4 h-4 rounded-full bg-blue-600"></span>
                                        <span class="text-xs">Xanh</span>
                                    </span>
                                </label>
                            </div>
                            <div class="flex items-center gap-3 mt-2">
                                <label class="text-xs text-gray-600">Độ dày:</label>
                                <input type="range" id="chapterOutlineWidth" min="2" max="8"
                                    value="4" step="1"
                                    class="flex-1 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                    oninput="updateChapterTextPreview()">
                                <span id="outlineWidthDisplay"
                                    class="text-xs font-semibold text-orange-700 w-8 text-center">4</span>
                            </div>
                        </div>

                        <!-- Chapter selection -->
                        <div class="border-t pt-3">
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-sm font-medium text-gray-700">Chọn chương:</label>
                                <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="checkbox" id="selectAllChaptersCover" class="rounded"
                                        onchange="toggleAllChaptersCover()">
                                    <span>Chọn tất cả</span>
                                </label>
                            </div>
                            <div id="chapterCoverList"
                                class="space-y-2 max-h-48 overflow-y-auto border rounded-lg p-3 bg-gray-50">
                                <!-- Chapters will be loaded here -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress -->
            <div id="chapterCoverProgress" class="hidden mt-4 mb-4">
                <div class="flex items-center justify-between text-xs text-blue-700 mb-1">
                    <span id="chapterCoverProgressText">Đang tạo...</span>
                    <span id="chapterCoverProgressPercent">0%</span>
                </div>
                <div class="w-full bg-blue-100 rounded-full h-2">
                    <div id="chapterCoverProgressBar" class="bg-blue-500 h-2 rounded-full transition-all duration-300"
                        style="width: 0%"></div>
                </div>
            </div>

            <!-- Status -->
            <div id="chapterCoverStatus" class="mb-4 text-sm"></div>

            <!-- Actions -->
            <div class="flex gap-3 mt-4">
                <button type="button" id="generateChapterCoversBtn" onclick="generateChapterCovers()"
                    class="flex-1 bg-purple-600 hover:bg-purple-700 text-white py-2.5 rounded-lg font-semibold transition">
                    🎨 Tạo ảnh bìa cho chương đã chọn
                </button>
                <button type="button" onclick="closeChapterCoverModal()"
                    class="px-6 bg-gray-200 hover:bg-gray-300 text-gray-800 py-2.5 rounded-lg font-semibold transition">
                    Đóng
                </button>
            </div>
        </div>
    </div>

    <!-- Add Text Overlay Modal -->
    <div id="addTextModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-3xl w-full mx-4 max-h-[95vh] overflow-hidden flex flex-col">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold">✏️ Thêm Text Overlay vào Hình</h3>
                <button type="button" onclick="closeAddTextModal()"
                    class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
            </div>

            <div class="flex-1 overflow-y-auto">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <!-- Left: Image Preview -->
                    <div>
                        <p class="text-sm font-medium text-gray-700 mb-2">Hình nền:</p>
                        <img id="addTextPreviewImage" src="" alt="Preview"
                            class="w-full aspect-video object-cover rounded-lg border">
                        <input type="hidden" id="addTextFilename" value="">
                    </div>

                    <!-- Right: Text Options -->
                    <div class="space-y-3">
                        <!-- Text Content -->
                        <div class="p-3 bg-purple-50 border border-purple-200 rounded-lg">
                            <p class="text-xs font-medium text-purple-700 mb-2">📝 Nội dung text:</p>
                            <input type="text" id="addTextTitle"
                                class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm font-semibold focus:border-purple-500 focus:outline-none mb-2"
                                value="{{ $audioBook->title }}" placeholder="Tiêu đề">
                            <input type="text" id="addTextAuthor"
                                class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm focus:border-purple-500 focus:outline-none mb-2"
                                value="{{ $audioBook->author ? 'Tác giả: ' . $audioBook->author : '' }}"
                                placeholder="Tác giả">
                            <input type="text" id="addTextChapter"
                                class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm focus:border-purple-500 focus:outline-none"
                                placeholder="Chương X (tùy chọn)">
                        </div>

                        <!-- Text Position -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Vị trí text:</label>
                            <div class="grid grid-cols-3 gap-2">
                                <label
                                    class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                    <input type="radio" name="addTextPosition" value="top"
                                        class="text-indigo-600">
                                    <span>⬆️ Trên</span>
                                </label>
                                <label
                                    class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                    <input type="radio" name="addTextPosition" value="center"
                                        class="text-indigo-600">
                                    <span>⬅️➡️ Giữa</span>
                                </label>
                                <label
                                    class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                    <input type="radio" name="addTextPosition" value="bottom" checked
                                        class="text-indigo-600">
                                    <span>⬇️ Dưới</span>
                                </label>
                            </div>
                        </div>

                        <!-- Colors Row -->
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Màu chữ:</label>
                                <div class="flex gap-1">
                                    <input type="color" id="addTextColor" value="#ffffff"
                                        class="w-8 h-8 rounded cursor-pointer border">
                                    <input type="text" id="addTextColorHex" value="#ffffff"
                                        class="flex-1 px-2 py-1 border rounded text-xs" placeholder="#ffffff">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Màu viền:</label>
                                <div class="flex gap-1">
                                    <input type="color" id="addTextBorderColor" value="#000000"
                                        class="w-8 h-8 rounded cursor-pointer border">
                                    <input type="text" id="addTextBorderColorHex" value="#000000"
                                        class="flex-1 px-2 py-1 border rounded text-xs" placeholder="#000000">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Độ dày viền:</label>
                                <select id="addTextBorderWidth" class="w-full px-2 py-1.5 border rounded text-xs">
                                    <option value="0">Không viền</option>
                                    <option value="2">Mỏng (2px)</option>
                                    <option value="4" selected>Vừa (4px)</option>
                                    <option value="6">Dày (6px)</option>
                                    <option value="8">Rất dày (8px)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Background Options -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Background chữ:</label>
                            <div class="grid grid-cols-4 gap-1">
                                <label
                                    class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                    <input type="radio" name="addTextBgStyle" value="none" checked
                                        class="text-indigo-600">
                                    <span>Không</span>
                                </label>
                                <label
                                    class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                    <input type="radio" name="addTextBgStyle" value="solid"
                                        class="text-indigo-600">
                                    <span>Màu đặc</span>
                                </label>
                                <label
                                    class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                    <input type="radio" name="addTextBgStyle" value="gradient"
                                        class="text-indigo-600">
                                    <span>Gradient</span>
                                </label>
                                <label
                                    class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                    <input type="radio" name="addTextBgStyle" value="blur"
                                        class="text-indigo-600">
                                    <span>Blur</span>
                                </label>
                            </div>
                        </div>

                        <!-- Background Color Section -->
                        <div id="addTextBgColorSection" class="hidden">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Màu background:</label>
                            <div class="flex gap-2 items-center">
                                <input type="color" id="addTextBgColor" value="#000000"
                                    class="w-8 h-8 rounded cursor-pointer border">
                                <input type="text" id="addTextBgColorHex" value="#000000"
                                    class="w-20 px-2 py-1 border rounded text-xs">
                                <label class="text-xs text-gray-600">Opacity:</label>
                                <input type="range" id="addTextBgOpacity" min="0" max="100"
                                    value="70" class="w-20">
                                <span id="addTextBgOpacityValue" class="text-xs w-8">70%</span>
                            </div>
                        </div>

                        <!-- Font Size -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Cỡ chữ tiêu đề:</label>
                            <div class="flex items-center gap-2">
                                <input type="range" id="addTextFontSize" min="40" max="100"
                                    value="60" class="flex-1">
                                <span id="addTextFontSizeValue" class="text-xs w-12">60px</span>
                            </div>
                        </div>

                        <!-- Preset Styles -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Preset nhanh:</label>
                            <div class="flex flex-wrap gap-1">
                                <button type="button"
                                    class="add-text-preset-btn px-2 py-1 bg-gray-800 text-white rounded text-xs"
                                    data-preset="classic">Classic</button>
                                <button type="button"
                                    class="add-text-preset-btn px-2 py-1 bg-gradient-to-r from-red-500 to-yellow-500 text-white rounded text-xs"
                                    data-preset="fire">🔥 Fire</button>
                                <button type="button"
                                    class="add-text-preset-btn px-2 py-1 bg-gradient-to-r from-blue-500 to-purple-500 text-white rounded text-xs"
                                    data-preset="neon">💜 Neon</button>
                                <button type="button"
                                    class="add-text-preset-btn px-2 py-1 bg-gradient-to-r from-green-400 to-cyan-500 text-white rounded text-xs"
                                    data-preset="nature">🌿 Nature</button>
                                <button type="button"
                                    class="add-text-preset-btn px-2 py-1 bg-amber-100 text-amber-900 border border-amber-300 rounded text-xs"
                                    data-preset="vintage">📜 Vintage</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status -->
            <div id="addTextStatus" class="mt-4 text-sm"></div>

            <!-- Actions -->
            <div class="flex gap-3 mt-4">
                <button type="button" id="applyTextOverlayBtn" onclick="applyTextOverlay()"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-lg font-semibold transition">
                    ✨ Tạo Thumbnail với Text
                </button>
                <button type="button" onclick="closeAddTextModal()"
                    class="px-6 bg-gray-200 hover:bg-gray-300 text-gray-800 py-2.5 rounded-lg font-semibold transition">
                    Đóng
                </button>
            </div>
        </div>
    </div>

    <script>
        // ========== TAB NAVIGATION ==========
        function initTabs() {
            const tabBtns = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');

            tabBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const targetTab = this.dataset.tab;

                    // Update button styles
                    tabBtns.forEach(b => {
                        b.classList.remove('border-blue-500', 'text-blue-600');
                        b.classList.add('border-transparent', 'text-gray-500');
                    });
                    this.classList.remove('border-transparent', 'text-gray-500');
                    this.classList.add('border-blue-500', 'text-blue-600');

                    // Show/hide content
                    tabContents.forEach(content => {
                        content.classList.add('hidden');
                    });
                    document.getElementById(targetTab + '-tab').classList.remove('hidden');

                    // Load media gallery when switching to youtube-media tab
                    if (targetTab === 'youtube-media') {
                        refreshMediaGallery();
                    }

                    // Load publish data when switching to auto-publish tab
                    if (targetTab === 'auto-publish') {
                        initAutoPublishTab();
                    }
                });
            });
        }

        // ========== GLOBAL VARIABLES ==========
        const audioBookId = {{ $audioBook->id }};
        const deleteChapterUrlBase =
            "{{ route('audiobooks.chapters.destroy', ['audioBook' => $audioBook->id, 'chapter' => 1]) }}"
            .replace(/\/1$/, '');

        // ========== SAFE JSON HELPER ==========
        async function safeJson(resp) {
            if (!resp.ok) {
                let errorText = '';
                try {
                    errorText = await resp.text();
                } catch (e) {}
                // Try to extract error message from JSON response
                try {
                    const errorData = JSON.parse(errorText);
                    throw new Error(errorData.error || errorData.message || ('HTTP ' + resp.status));
                } catch (e) {
                    if (e.message && !e.message.startsWith('Unexpected')) {
                        throw e;
                    }
                    throw new Error('HTTP ' + resp.status + ': ' + resp.statusText);
                }
            }
            return resp.json();
        }

        // ========== YOUTUBE MEDIA FUNCTIONS ==========

        // Toggle sections based on "Use Cover Image" option
        document.getElementById('useCoverImageOption')?.addEventListener('change', function() {
            const useCover = this.checked;
            const customPromptSection = document.getElementById('customPromptSection');
            const aiResearchSection = document.getElementById('aiResearchSection');
            const styleSection = document.querySelector('input[name="thumbnailStyle"]')?.closest('div')
                ?.parentElement;

            if (useCover) {
                // Keep style & custom prompt visible (AI uses description for background)
                // Only hide AI research (not needed when using cover)
                if (aiResearchSection) aiResearchSection.style.display = 'none';
                const aiResearchCheckbox = document.getElementById('aiResearchOption');
                if (aiResearchCheckbox) aiResearchCheckbox.checked = false;
            } else {
                // Show AI generation options
                if (customPromptSection) customPromptSection.style.display = '';
                if (aiResearchSection) aiResearchSection.style.display = '';
                if (styleSection) styleSection.style.display = '';
            }
        });

        // Generate Background Image (no text) - Step 1
        document.getElementById('generateThumbnailBtn')?.addEventListener('click', async function() {
            const btn = this;
            const style = document.querySelector('input[name="thumbnailStyle"]:checked')?.value || 'cinematic';
            const customPrompt = document.getElementById('thumbnailCustomPrompt')?.value.trim();
            const aiResearch = document.getElementById('aiResearchOption')?.checked || false;
            const useCoverImage = document.getElementById('useCoverImageOption')?.checked || false;
            const statusDiv = document.getElementById('thumbnailStatus');

            btn.disabled = true;
            btn.innerHTML = '⏳ Đang tạo hình nền...';

            if (useCoverImage) {
                statusDiv.innerHTML =
                    '<span class="text-blue-600">🖼️ Đang xử lý ảnh bìa...</span>';
            } else if (aiResearch) {
                statusDiv.innerHTML =
                    '<span class="text-blue-600">🔍 AI đang tìm kiếm thông tin và tạo hình nền...</span>';
            } else {
                statusDiv.innerHTML =
                    '<span class="text-blue-600">🎨 AI đang tạo hình nền với Gemini, vui lòng đợi 30-90 giây...</span>';
            }

            try {
                const requestBody = {
                    style: style,
                    with_text: false, // No text - just background image
                    ai_research: aiResearch,
                    use_cover_image: useCoverImage
                };

                if (customPrompt) {
                    requestBody.custom_prompt = customPrompt;
                }

                const response = await fetch('/audiobooks/' + audioBookId + '/media/generate-thumbnail', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify(requestBody)
                });

                const result = await safeJson(response);

                if (result.success) {
                    statusDiv.innerHTML =
                        '<span class="text-green-600">✅ Đã tạo hình nền thành công!</span><br><span class="text-xs text-indigo-600">👆 Chọn hình từ gallery bên dưới và nhấn "✏️ Thêm Text" để thêm chữ</span>';
                    refreshMediaGallery();
                } else {
                    throw new Error(result.error || 'Không thể tạo hình nền');
                }
            } catch (error) {
                statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '🖼️ Tạo Hình Nền (Không chữ)';
            }
        });

        // Generate Thumbnail WITH Text - AI renders text directly in image
        document.getElementById('generateThumbnailWithTextBtn')?.addEventListener('click', async function() {
            const btn = this;
            const style = document.querySelector('input[name="thumbnailStyle"]:checked')?.value || 'cinematic';
            const customPrompt = document.getElementById('thumbnailCustomPrompt')?.value.trim();
            const aiResearch = document.getElementById('aiResearchOption')?.checked || false;
            // IGNORE use_cover_image when generating AI text - we want AI to create image WITH text
            // const useCoverImage = document.getElementById('useCoverImageOption')?.checked || false;
            const customTitle = document.getElementById('thumbnailTitle')?.value.trim();
            const customAuthor = document.getElementById('thumbnailAuthor')?.value.trim();
            const chapterNumber = document.getElementById('thumbnailChapterNumber')?.value || null;
            const statusDiv = document.getElementById('thumbnailStatus');

            // Validate title
            if (!customTitle) {
                statusDiv.innerHTML = '<span class="text-red-600">❌ Vui lòng nhập tiêu đề sách!</span>';
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '⏳ AI đang vẽ thumbnail có chữ...';

            statusDiv.innerHTML =
                `<span class="text-blue-600">✨ AI đang tạo hình VÀ vẽ chữ "<strong>${customTitle}</strong>" trực tiếp vào hình...</span><br><span class="text-xs text-gray-500">⚠️ LƯU Ý: AI có thể không vẽ text chính xác 100%. Nếu text sai/xấu, hãy dùng cách tạo hình nền rồi thêm text bằng FFmpeg.</span>`;

            try {
                const requestBody = {
                    style: style,
                    with_text: true, // AI will render text directly
                    ai_research: aiResearch,
                    use_cover_image: false, // ALWAYS false for AI text generation
                    custom_title: customTitle,
                    custom_author: customAuthor
                };

                if (chapterNumber) {
                    requestBody.chapter_number = parseInt(chapterNumber);
                }

                if (customPrompt) {
                    requestBody.custom_prompt = customPrompt;
                }

                const response = await fetch(`/audiobooks/${audioBookId}/media/generate-thumbnail`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify(requestBody)
                });

                const result = await safeJson(response);

                if (result.success) {
                    let msg = '<span class="text-green-600">✅ Đã tạo thumbnail!</span>';
                    if (result.ai_text) {
                        msg +=
                            '<br><span class="text-xs text-indigo-600">🎨 AI đã cố gắng vẽ chữ vào hình</span>';
                        msg +=
                            '<br><span class="text-xs text-orange-600">⚠️ Nếu chữ không đẹp/sai, hãy dùng phương pháp FFmpeg thêm chữ</span>';
                    }
                    statusDiv.innerHTML = msg;
                    refreshMediaGallery();
                } else {
                    throw new Error(result.error || 'Không thể tạo thumbnail');
                }
            } catch (error) {
                statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '✨ Tạo Thumbnail (AI Vẽ Chữ Luôn)';
            }
        });

        // ========== SCENE GENERATION - 2-STEP FLOW ==========

        // Lưu trữ kết quả phân tích scenes
        var analyzedScenes = [];

        // Bước 1: AI Phân tích nội dung → tạo prompts
        document.getElementById('analyzeSceneBtn')?.addEventListener('click', async function() {
            var btn = this;
            var style = document.getElementById('sceneStyle')?.value || 'cinematic';
            var statusDiv = document.getElementById('scenesStatus');
            var resultsDiv = document.getElementById('sceneAnalysisResults');
            var promptsList = document.getElementById('scenePromptsList');
            var countSpan = document.getElementById('sceneAnalysisCount');

            btn.disabled = true;
            btn.innerHTML = '🤖 Đang phân tích...';
            statusDiv.innerHTML =
                '<span class="text-blue-600">🧠 AI đang đọc và phân tích nội dung giới thiệu sách...</span>';
            resultsDiv.classList.add('hidden');

            try {
                var response = await fetch('/audiobooks/' + audioBookId + '/media/analyze-scenes', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        style: style
                    })
                });

                var result = await safeJson(response);

                if (result.success && result.scenes && result.scenes.length > 0) {
                    analyzedScenes = result.scenes;
                    countSpan.textContent = result.total + ' phân cảnh';
                    statusDiv.innerHTML = '<span class="text-green-600">✅ Bước 1 hoàn tất! AI đã phân tích ' +
                        result.total + ' phân cảnh. Xem bên dưới và nhấn Bước 2 để tạo ảnh.</span>';

                    // Render danh sách scenes + prompts
                    promptsList.innerHTML = '';
                    result.scenes.forEach(function(scene, idx) {
                        var card = document.createElement('div');
                        card.className = 'p-3 bg-white border border-gray-200 rounded-lg';

                        var header = document.createElement('div');
                        header.className = 'flex items-center justify-between mb-2';
                        header.innerHTML = '<span class="text-sm font-semibold text-blue-800">🎬 ' +
                            scene.scene_number + '. ' + scene.title + '</span>' +
                            '<button type="button" class="text-xs bg-green-100 hover:bg-green-200 text-green-700 px-2 py-1 rounded generate-single-scene-btn" data-index="' +
                            idx + '">🎨 Tạo ảnh</button>';
                        card.appendChild(header);

                        if (scene.description) {
                            var desc = document.createElement('p');
                            desc.className = 'text-xs text-gray-600 mb-2';
                            desc.textContent = scene.description;
                            card.appendChild(desc);
                        }

                        var promptLabel = document.createElement('label');
                        promptLabel.className = 'block text-xs font-medium text-gray-500 mb-1';
                        promptLabel.textContent = 'Prompt (có thể sửa):';
                        card.appendChild(promptLabel);

                        var promptInput = document.createElement('textarea');
                        promptInput.className =
                            'w-full px-2 py-1 border border-gray-300 rounded text-xs focus:border-blue-500 focus:outline-none scene-prompt-input';
                        promptInput.rows = 3;
                        promptInput.dataset.index = idx;
                        promptInput.value = scene.full_prompt;
                        card.appendChild(promptInput);

                        var statusSpan = document.createElement('div');
                        statusSpan.className = 'mt-1 text-xs scene-item-status';
                        statusSpan.id = 'sceneItemStatus_' + idx;
                        card.appendChild(statusSpan);

                        promptsList.appendChild(card);
                    });

                    resultsDiv.classList.remove('hidden');
                } else {
                    throw new Error(result.error || 'Không thể phân tích nội dung');
                }
            } catch (error) {
                statusDiv.innerHTML = '<span class="text-red-600">❌ ' + error.message + '</span>';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '🧠 Bước 1: AI Phân Tích Nội Dung';
            }
        });

        // Bước 2: Tạo ảnh tất cả scenes
        document.getElementById('generateAllScenesBtn')?.addEventListener('click', async function() {
            var btn = this;
            var style = document.getElementById('sceneStyle')?.value || 'cinematic';
            var statusDiv = document.getElementById('scenesStatus');
            var progressDiv = document.getElementById('scenesProgress');
            var progressBar = document.getElementById('scenesProgressBar');
            var progressText = document.getElementById('scenesProgressText');
            var progressPercent = document.getElementById('scenesProgressPercent');

            var promptInputs = document.querySelectorAll('.scene-prompt-input');
            if (promptInputs.length === 0) {
                statusDiv.innerHTML =
                    '<span class="text-red-600">❌ Chưa có dữ liệu phân tích. Hãy chạy Bước 1 trước.</span>';
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '🎨 Đang tạo ảnh...';
            progressDiv.classList.remove('hidden');

            var totalScenes = promptInputs.length;
            var generated = 0;
            var failed = 0;

            for (var i = 0; i < totalScenes; i++) {
                var prompt = promptInputs[i].value;
                var sceneTitle = analyzedScenes[i] ? analyzedScenes[i].title : ('Scene ' + (i + 1));
                var sceneDesc = analyzedScenes[i] ? analyzedScenes[i].description : '';
                var itemStatus = document.getElementById('sceneItemStatus_' + i);

                var pct = Math.round(((i) / totalScenes) * 100);
                progressBar.style.width = pct + '%';
                progressText.textContent = 'Đang tạo cảnh ' + (i + 1) + '/' + totalScenes + ': ' + sceneTitle;
                progressPercent.textContent = pct + '%';
                if (itemStatus) itemStatus.innerHTML = '<span class="text-blue-600">⏳ Đang tạo ảnh...</span>';

                try {
                    var resp = await fetch('/audiobooks/' + audioBookId + '/media/generate-scene-image', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            prompt: prompt,
                            scene_index: i,
                            scene_title: sceneTitle,
                            scene_description: sceneDesc,
                            style: style
                        })
                    });

                    var imgResult = await safeJson(resp);
                    if (imgResult.success) {
                        generated++;
                        if (itemStatus) itemStatus.innerHTML =
                            '<span class="text-green-600">✅ Đã tạo ảnh thành công!</span>';
                    } else {
                        failed++;
                        if (itemStatus) itemStatus.innerHTML = '<span class="text-red-600">❌ ' + (imgResult
                            .error || 'Lỗi') + '</span>';
                    }
                } catch (error) {
                    failed++;
                    if (itemStatus) itemStatus.innerHTML = '<span class="text-red-600">❌ ' + error.message +
                        '</span>';
                }
            }

            progressBar.style.width = '100%';
            progressPercent.textContent = '100%';
            progressText.textContent = 'Hoàn tất: ' + generated + '/' + totalScenes + ' cảnh';

            if (failed > 0) {
                statusDiv.innerHTML = '<span class="text-yellow-600">⚠️ Tạo được ' + generated + '/' +
                    totalScenes + ' cảnh (' + failed + ' lỗi)</span>';
            } else {
                statusDiv.innerHTML = '<span class="text-green-600">✅ Tạo thành công ' + generated +
                    ' cảnh minh họa!</span>';
            }

            refreshMediaGallery();
            btn.disabled = false;
            btn.innerHTML = '🎨 Bước 2: Tạo Ảnh Tất Cả Cảnh';
            setTimeout(function() {
                progressDiv.classList.add('hidden');
            }, 5000);
        });

        // Tạo ảnh 1 scene riêng lẻ
        document.addEventListener('click', async function(e) {
            var singleBtn = e.target.closest('.generate-single-scene-btn');
            if (!singleBtn) return;

            var idx = parseInt(singleBtn.dataset.index);
            var style = document.getElementById('sceneStyle')?.value || 'cinematic';
            var promptInput = document.querySelector('.scene-prompt-input[data-index="' + idx + '"]');
            var itemStatus = document.getElementById('sceneItemStatus_' + idx);

            if (!promptInput) return;

            singleBtn.disabled = true;
            singleBtn.textContent = '⏳...';
            if (itemStatus) itemStatus.innerHTML = '<span class="text-blue-600">⏳ Đang tạo ảnh...</span>';

            try {
                var resp = await fetch('/audiobooks/' + audioBookId + '/media/generate-scene-image', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        prompt: promptInput.value,
                        scene_index: idx,
                        scene_title: analyzedScenes[idx] ? analyzedScenes[idx].title : (
                            'Scene ' + (idx + 1)),
                        scene_description: analyzedScenes[idx] ? analyzedScenes[idx]
                            .description : '',
                        style: style
                    })
                });

                var singleResult = await safeJson(resp);
                if (singleResult.success) {
                    if (itemStatus) itemStatus.innerHTML =
                        '<span class="text-green-600">✅ Đã tạo ảnh thành công!</span>';
                    refreshMediaGallery();
                } else {
                    if (itemStatus) itemStatus.innerHTML = '<span class="text-red-600">❌ ' + (singleResult
                        .error || 'Lỗi') + '</span>';
                }
            } catch (error) {
                if (itemStatus) itemStatus.innerHTML = '<span class="text-red-600">❌ ' + error.message +
                    '</span>';
            } finally {
                singleBtn.disabled = false;
                singleBtn.textContent = '🎨 Tạo ảnh';
            }
        });

        // ========== Scene Slideshow Video Generation ==========
        var generateSlideshowBtn = document.getElementById('generateSlideshowBtn');
        if (generateSlideshowBtn) {
            generateSlideshowBtn.addEventListener('click', async function() {
                var btn = this;
                var statusDiv = document.getElementById('slideshowStatus');
                var progressDiv = document.getElementById('slideshowProgress');

                btn.disabled = true;
                btn.innerHTML = '⏳ Đang tạo video slideshow...';
                statusDiv.innerHTML =
                    '<span class="text-blue-600">⏳ Đang ghép phân cảnh với audio. Quá trình này có thể mất 1-3 phút...</span>';
                progressDiv.classList.remove('hidden');

                try {
                    var resp = await fetch('/audiobooks/' + audioBookId + '/media/generate-scene-slideshow', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    });

                    var result = await safeJson(resp);
                    if (result.success) {
                        statusDiv.innerHTML = '<span class="text-green-600">✅ ' + result.message + ' (' + result
                            .scenes_count + ' cảnh)</span>';

                        // Show video player
                        var videoContainer = document.getElementById('slideshowVideoContainer');
                        var videoPlayer = document.getElementById('slideshowVideoPlayer');
                        var durationSpan = document.getElementById('slideshowDuration');
                        var downloadBtn = document.getElementById('slideshowDownloadBtn');

                        videoContainer.classList.remove('hidden');
                        videoPlayer.src = result.video_url;
                        videoPlayer.load();
                        if (result.duration) {
                            var mins = Math.floor(result.duration / 60);
                            var secs = Math.floor(result.duration % 60);
                            durationSpan.textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;
                        }
                        downloadBtn.href = result.video_url;
                    } else {
                        statusDiv.innerHTML = '<span class="text-red-600">❌ ' + (result.error ||
                            'Lỗi không xác định') + '</span>';
                    }
                } catch (error) {
                    statusDiv.innerHTML = '<span class="text-red-600">❌ ' + error.message + '</span>';
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '🎥 Tạo Video từ Phân Cảnh + Audio';
                    progressDiv.classList.add('hidden');
                }
            });
        }

        // Delete slideshow video
        var deleteSlideshowBtn = document.getElementById('deleteSlideshowBtn');
        if (deleteSlideshowBtn) {
            deleteSlideshowBtn.addEventListener('click', async function() {
                if (!confirm('Bạn có chắc muốn xóa video phân cảnh này?')) return;

                try {
                    var resp = await fetch('/audiobooks/' + audioBookId + '/media/delete', {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            filename: 'description_scenes.mp4',
                            type: 'mp4'
                        })
                    });

                    var result = await safeJson(resp);
                    if (result.success) {
                        document.getElementById('slideshowVideoContainer').classList.add('hidden');
                        document.getElementById('slideshowStatus').innerHTML =
                            '<span class="text-green-600">✅ Đã xóa video</span>';
                    }
                } catch (error) {
                    alert('Lỗi: ' + error.message);
                }
            });
        }

        // Refresh Media Gallery
        async function refreshMediaGallery() {
            try {
                const response = await fetch('/audiobooks/' + audioBookId + '/media');
                const result = await safeJson(response);

                if (result.success) {
                    renderThumbnailGallery(result.media.thumbnails || []);
                    renderSceneGallery(result.media.scenes || []);
                    renderAnimationGallery(result.media.animations || []);

                    // Show/hide slideshow section based on whether scenes exist
                    var slideshowSection = document.getElementById('sceneSlideshowSection');
                    var scenesList = result.media.scenes || [];
                    if (slideshowSection) {
                        if (scenesList.length > 0) {
                            slideshowSection.classList.remove('hidden');
                        } else {
                            slideshowSection.classList.add('hidden');
                        }
                    }
                }
            } catch (error) {
                console.error('Failed to load media:', error);
            }
        }

        function renderThumbnailGallery(thumbnails) {
            const gallery = document.getElementById('thumbnailGallery');
            const countBadge = document.getElementById('thumbnailCount');

            countBadge.textContent = thumbnails.length;

            if (thumbnails.length === 0) {
                gallery.innerHTML = `
                    <div class="text-center py-8 text-gray-400 col-span-full">
                        <span class="text-3xl">🖼️</span>
                        <p class="text-sm mt-2">Chưa có thumbnail nào</p>
                    </div>
                `;
                return;
            }

            gallery.innerHTML = thumbnails.map(thumb => `
                <div class="relative group cursor-pointer" onclick="window.openImagePreview('${thumb.url.replace(/'/g, "\\'")}')">
                    <img src="${thumb.url}" alt="Thumbnail" 
                        class="w-full aspect-video object-cover rounded-lg border shadow-sm hover:shadow-md transition">
                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 rounded-lg transition flex flex-wrap items-center justify-center gap-1 opacity-0 group-hover:opacity-100 pointer-events-none p-2">
                        <button onclick="event.stopPropagation(); window.openAddTextModal('${thumb.filename.replace(/'/g, "\\'")}', '${thumb.url.replace(/'/g, "\\'")}');" 
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto">
                            ✏️ Thêm Text
                        </button>
                        <button onclick="event.stopPropagation(); window.openChapterCoverModal('${thumb.filename.replace(/'/g, "\\'")}', '${thumb.url.replace(/'/g, "\\'")}');" 
                            class="bg-purple-600 hover:bg-purple-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto">
                            📚 Bìa chương
                        </button>
                        <button onclick="event.stopPropagation(); window.createAnimation('${thumb.filename.replace(/'/g, "\\'")}');" 
                            class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto">
                            ✨ Animation
                        </button>
                        <button onclick="event.stopPropagation(); window.deleteMediaFile('${thumb.filename.replace(/'/g, "\\'")}');" 
                            class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto">
                            🗑️ Xóa
                        </button>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 truncate">${thumb.filename}</div>
                </div>
            `).join('');
        }

        function renderSceneGallery(scenes) {
            const gallery = document.getElementById('sceneGallery');
            const countBadge = document.getElementById('sceneCount2');

            countBadge.textContent = scenes.length;

            if (scenes.length === 0) {
                gallery.innerHTML = `
                    <div class="text-center py-8 text-gray-400 col-span-full">
                        <span class="text-3xl">🎬</span>
                        <p class="text-sm mt-2">Chưa có scene nào. Nhấn "Tạo Cảnh Minh Họa" để AI phân tích nội dung và tạo scenes.</p>
                    </div>
                `;
                return;
            }

            gallery.innerHTML = '';
            scenes.forEach((scene, idx) => {
                const card = document.createElement('div');
                card.className = 'relative group cursor-pointer';
                card.onclick = () => window.openImagePreview(scene.url);

                // Scene Number Badge
                const badge = document.createElement('div');
                badge.className =
                    'absolute top-1 left-1 bg-gradient-to-r from-blue-600 to-purple-600 text-white text-xs px-2 py-0.5 rounded z-10 pointer-events-none font-semibold';
                badge.textContent = `Phân cảnh ${idx + 1}`;

                // Scene Image
                const img = document.createElement('img');
                img.src = scene.url;
                img.alt = `Scene ${idx + 1}`;
                img.className =
                    'w-full aspect-video object-cover rounded-lg border shadow-sm hover:shadow-md transition';

                // Scene Info Overlay
                if (scene.title || scene.description) {
                    const overlay = document.createElement('div');
                    overlay.className =
                        'absolute inset-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex flex-col justify-end p-3 pointer-events-none';

                    if (scene.title) {
                        const title = document.createElement('h4');
                        title.className = 'text-white text-sm font-bold mb-1';
                        title.textContent = scene.title;
                        overlay.appendChild(title);
                    }

                    if (scene.description) {
                        const desc = document.createElement('p');
                        desc.className = 'text-gray-200 text-xs line-clamp-3';
                        desc.textContent = scene.description;
                        overlay.appendChild(desc);
                    }

                    card.appendChild(overlay);
                }

                // Hover Actions
                const actions = document.createElement('div');
                actions.className =
                    'absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 rounded-lg transition flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 pointer-events-none';

                const coverBtn = document.createElement('button');
                coverBtn.className =
                    'bg-purple-600 hover:bg-purple-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto';
                coverBtn.textContent = '📚 Bìa chương';
                coverBtn.onclick = (e) => {
                    e.stopPropagation();
                    window.openChapterCoverModal(scene.filename, scene.url);
                };

                const animBtn = document.createElement('button');
                animBtn.className =
                    'bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto';
                animBtn.textContent = '✨ Animation';
                animBtn.onclick = (e) => {
                    e.stopPropagation();
                    window.createAnimation(scene.filename);
                };

                const delBtn = document.createElement('button');
                delBtn.className =
                    'bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto';
                delBtn.textContent = '🗑️ Xóa';
                delBtn.onclick = (e) => {
                    e.stopPropagation();
                    window.deleteMediaFile(scene.filename);
                };

                actions.appendChild(coverBtn);
                actions.appendChild(animBtn);
                actions.appendChild(delBtn);

                card.appendChild(badge);
                card.appendChild(img);
                card.appendChild(actions);

                gallery.appendChild(card);
            });
        }

        // Animation Gallery
        function renderAnimationGallery(animations) {
            const gallery = document.getElementById('animationGallery');
            const countBadge = document.getElementById('animationCount');

            countBadge.textContent = animations.length;

            if (animations.length === 0) {
                gallery.innerHTML = `
                    <div class="text-center py-8 text-gray-400 col-span-full">
                        <span class="text-3xl">✨</span>
                        <p class="text-sm mt-2">Chưa có animation nào</p>
                    </div>
                `;
                return;
            }

            gallery.innerHTML = animations.map((anim, idx) => `
                <div class="relative group">
                    <video src="${anim.url}" 
                        class="w-full aspect-video object-cover rounded-lg border shadow-sm hover:shadow-md transition cursor-pointer"
                        muted loop
                        onmouseenter="this.play()" 
                        onmouseleave="this.pause(); this.currentTime = 0;">
                    </video>
                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 rounded-lg transition flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 pointer-events-none">
                        <a href="${anim.url}" download 
                            onclick="event.stopPropagation();"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto">
                            ⬇️ Download
                        </a>
                        <button onclick="event.stopPropagation(); window.deleteMediaFile('${anim.filename.replace(/'/g, "\\'")}');" 
                            class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto">
                            🗑️ Xóa
                        </button>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 truncate">${anim.filename}</div>
                </div>
            `).join('');
        }

        // Create Animation with Kling AI
        window.createAnimation = async function(imageName) {
            const confirmed = confirm(
                `Tạo animation cho ảnh "${imageName}"?\n\nKling AI sẽ tạo hiệu ứng chuyển động nhẹ (khói, ánh sáng, chớp mắt...)\n\nQuá trình này có thể mất 1-3 phút.`
            );
            if (!confirmed) return;

            const statusDiv = document.createElement('div');
            statusDiv.id = 'animationStatus';
            statusDiv.className = 'fixed top-4 right-4 bg-white rounded-lg shadow-lg p-4 z-50 border';
            statusDiv.innerHTML = `
                <div class="flex items-center gap-3">
                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-green-600"></div>
                    <div>
                        <p class="font-medium text-gray-800">Đang tạo Animation...</p>
                        <p class="text-sm text-gray-500" id="animationStatusText">Đang khởi tạo task...</p>
                    </div>
                </div>
            `;
            document.body.appendChild(statusDiv);

            try {
                // Start task
                const startResponse = await fetch(`/audiobooks/{{ $audioBook->id }}/animations/start-task`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        image_name: imageName
                    })
                });

                const startResult = await safeJson(startResponse);
                console.log('Start result:', startResult);

                if (!startResult.success) {
                    const errorMsg = typeof startResult.error === 'string' ?
                        startResult.error :
                        JSON.stringify(startResult.error) || 'Failed to start animation task';
                    throw new Error(errorMsg);
                }

                const taskId = startResult.task_id;
                document.getElementById('animationStatusText').textContent =
                    `Task ID: ${taskId.substring(0, 8)}... Đang xử lý...`;

                // Poll for status
                let attempts = 0;
                const maxAttempts = 60; // 5 minutes max (5s * 60)

                const pollStatus = async () => {
                    try {
                        attempts++;
                        const statusResponse = await fetch(
                            `/audiobooks/{{ $audioBook->id }}/animations/check-status`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                        .content,
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify({
                                    task_id: taskId
                                })
                            });

                        const statusResult = await safeJson(statusResponse);

                        if (statusResult.success && statusResult.completed) {
                            // Done!
                            statusDiv.innerHTML = `
                                <div class="flex items-center gap-3 text-green-600">
                                    <span class="text-2xl">✅</span>
                                    <div>
                                        <p class="font-medium">Animation hoàn thành!</p>
                                        <p class="text-sm">Đang tải lại gallery...</p>
                                    </div>
                                </div>
                            `;
                            setTimeout(() => {
                                statusDiv.remove();
                                refreshMediaGallery(); // Reload to show new animation
                            }, 2000);
                            return;
                        }

                        if (statusResult.status === 'failed') {
                            const errorMsg = statusResult.error || 'Animation task failed';
                            statusDiv.innerHTML = `
                                <div class="flex items-center gap-3 text-red-600">
                                    <span class="text-2xl">❌</span>
                                    <div>
                                        <p class="font-medium">Lỗi tạo Animation</p>
                                        <p class="text-sm">${errorMsg}</p>
                                    </div>
                                </div>
                            `;
                            setTimeout(() => statusDiv.remove(), 5000);
                            return;
                        }

                        if (attempts >= maxAttempts) {
                            statusDiv.innerHTML = `
                                <div class="flex items-center gap-3 text-red-600">
                                    <span class="text-2xl">❌</span>
                                    <div>
                                        <p class="font-medium">Timeout</p>
                                        <p class="text-sm">Animation mất quá nhiều thời gian</p>
                                    </div>
                                </div>
                            `;
                            setTimeout(() => statusDiv.remove(), 5000);
                            return;
                        }

                        // Update status text
                        document.getElementById('animationStatusText').textContent =
                            `${statusResult.status || 'processing'}... (${attempts}/${maxAttempts})`;

                        // Continue polling
                        setTimeout(pollStatus, 5000);
                    } catch (pollError) {
                        console.error('Poll error:', pollError);
                        // Continue polling on network errors
                        if (attempts < maxAttempts) {
                            setTimeout(pollStatus, 5000);
                        } else {
                            statusDiv.innerHTML = `
                                <div class="flex items-center gap-3 text-red-600">
                                    <span class="text-2xl">❌</span>
                                    <div>
                                        <p class="font-medium">Lỗi kết nối</p>
                                        <p class="text-sm">${pollError.message || 'Network error'}</p>
                                    </div>
                                </div>
                            `;
                            setTimeout(() => statusDiv.remove(), 5000);
                        }
                    }
                };

                pollStatus();

            } catch (error) {
                console.error('Animation error:', error);
                let errorMessage = 'Unknown error';
                if (typeof error === 'string') {
                    errorMessage = error;
                } else if (error && error.message) {
                    errorMessage = typeof error.message === 'string' ? error.message : JSON.stringify(error
                        .message);
                } else if (error) {
                    errorMessage = JSON.stringify(error);
                }
                statusDiv.innerHTML = `
                    <div class="flex items-center gap-3 text-red-600">
                        <span class="text-2xl">❌</span>
                        <div>
                            <p class="font-medium">Lỗi tạo Animation</p>
                            <p class="text-sm">${errorMessage}</p>
                        </div>
                    </div>
                `;
                setTimeout(() => statusDiv.remove(), 5000);
            }
        };

        // Image Preview Modal
        let currentZoom = 1;

        function openImagePreview(url) {
            console.log('openImagePreview called with:', url);
            const modal = document.getElementById('imagePreviewModal');
            const img = document.getElementById('previewImage');
            const downloadLink = document.getElementById('downloadImageLink');

            if (!modal || !img) {
                console.error('Modal or image element not found!');
                return;
            }

            img.src = url;
            downloadLink.href = url;
            currentZoom = 1;
            img.style.transform = 'scale(1)';
            updateZoomIndicator();
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
            console.log('Modal should be visible now');
        }

        function closeImagePreview() {
            document.getElementById('imagePreviewModal').classList.add('hidden');
            document.body.style.overflow = ''; // Restore scrolling
            currentZoom = 1;
        }

        function zoomImage(delta) {
            const img = document.getElementById('previewImage');
            currentZoom = Math.max(0.5, Math.min(3, currentZoom + delta));
            img.style.transform = `scale(${currentZoom})`;
            img.style.cursor = currentZoom > 1 ? 'grab' : 'zoom-in';
            updateZoomIndicator();
        }

        function resetImageZoom() {
            const img = document.getElementById('previewImage');
            currentZoom = 1;
            img.style.transform = 'scale(1)';
            img.style.cursor = 'zoom-in';
            updateZoomIndicator();
        }

        function toggleImageZoom(img) {
            if (currentZoom >= 1.5) {
                resetImageZoom();
            } else {
                currentZoom = 2;
                img.style.transform = 'scale(2)';
                img.style.cursor = 'grab';
                updateZoomIndicator();
            }
        }

        function updateZoomIndicator() {
            const indicator = document.getElementById('zoomLevelIndicator');
            if (indicator) {
                indicator.textContent = Math.round(currentZoom * 100) + '%';
            }
        }

        // Keyboard support for image preview
        document.addEventListener('keydown', function(e) {
            const modal = document.getElementById('imagePreviewModal');
            if (modal && !modal.classList.contains('hidden')) {
                if (e.key === 'Escape') {
                    closeImagePreview();
                } else if (e.key === '+' || e.key === '=') {
                    zoomImage(0.2);
                } else if (e.key === '-') {
                    zoomImage(-0.2);
                } else if (e.key === '0') {
                    resetImageZoom();
                }
            }
        });

        // Delete single media file
        async function deleteMediaFile(filename) {
            if (!confirm('Bạn có chắc muốn xóa file này?')) return;

            try {
                const response = await fetch(`/audiobooks/${audioBookId}/media/delete`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        filename: filename
                    })
                });

                const result = await safeJson(response);
                if (result.success) {
                    refreshMediaGallery();
                } else {
                    alert('Lỗi: ' + (result.error || 'Không thể xóa'));
                }
            } catch (error) {
                alert('Lỗi: ' + error.message);
            }
        }

        // Delete all media by type
        async function deleteAllMedia(type) {
            const typeLabel = type === 'thumbnails' ? 'thumbnails' : 'scenes';
            if (!confirm(`Bạn có chắc muốn xóa TẤT CẢ ${typeLabel}?`)) return;

            try {
                const response = await fetch(`/audiobooks/${audioBookId}/media/delete`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        type: type
                    })
                });

                const result = await safeJson(response);
                if (result.success) {
                    refreshMediaGallery();
                    alert(result.message || 'Đã xóa thành công');
                } else {
                    alert('Lỗi: ' + (result.error || 'Không thể xóa'));
                }
            } catch (error) {
                alert('Lỗi: ' + error.message);
            }
        }

        // ========== VIDEO PREVIEW FUNCTIONS ==========
        function openVideoPreview(url) {
            console.log('openVideoPreview called with:', url);

            // Create video preview modal
            const existingModal = document.getElementById('videoPreviewModal');
            if (existingModal) {
                existingModal.remove();
            }

            const modal = document.createElement('div');
            modal.id = 'videoPreviewModal';
            modal.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black bg-opacity-90';
            modal.onclick = function(e) {
                if (e.target === modal) {
                    closeVideoPreview();
                }
            };

            modal.innerHTML = `
                <div class="relative max-w-6xl max-h-[90vh] w-full mx-4">
                    <button onclick="closeVideoPreview()" 
                        class="absolute -top-12 right-0 text-white text-4xl hover:text-red-400 transition z-50">&times;</button>
                    <video controls autoplay class="w-full max-h-[85vh] rounded-lg shadow-2xl">
                        <source src="${url}" type="video/mp4">
                        Trình duyệt không hỗ trợ video.
                    </video>
                </div>
            `;

            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';

            // Close on Escape key
            document.addEventListener('keydown', handleVideoEscapeKey);
        }

        function closeVideoPreview() {
            const modal = document.getElementById('videoPreviewModal');
            if (modal) {
                modal.remove();
                document.body.style.overflow = '';
                document.removeEventListener('keydown', handleVideoEscapeKey);
            }
        }

        function handleVideoEscapeKey(e) {
            if (e.key === 'Escape') {
                closeVideoPreview();
            }
        }

        // Expose functions to window scope for inline onclick handlers
        window.openImagePreview = openImagePreview;
        window.closeImagePreview = closeImagePreview;
        window.zoomImage = zoomImage;
        window.resetImageZoom = resetImageZoom;
        window.toggleImageZoom = toggleImageZoom;
        window.deleteMediaFile = deleteMediaFile;
        window.deleteAllMedia = deleteAllMedia;
        window.refreshMediaGallery = refreshMediaGallery;
        window.openVideoPreview = openVideoPreview;
        window.closeVideoPreview = closeVideoPreview;

        // ========== EXISTING FUNCTIONS ==========
        function openScrapeModal() {
            document.getElementById('scrapeModal').classList.remove('hidden');
        }

        function closeScrapeModal() {
            document.getElementById('scrapeModal').classList.add('hidden');
            document.getElementById('scrapeStatus').innerHTML = '';
        }

        document.getElementById('scrapeForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const statusDiv = document.getElementById('scrapeStatus');

            statusDiv.innerHTML = '<p class="text-blue-600">⏳ Đang scrape...</p>';

            try {
                const response = await fetch('{{ route('audiobooks.scrape.chapters') }}', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
                    }
                });

                const data = await safeJson(response);

                if (response.ok) {
                    statusDiv.innerHTML = `<p class="text-green-600">✅ ${data.message}</p>`;
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    statusDiv.innerHTML = `<p class="text-red-600">❌ ${data.error}</p>`;
                }
            } catch (error) {
                statusDiv.innerHTML = `<p class="text-red-600">❌ Lỗi: ${error.message}</p>`;
            }
        });

        // Close modal when clicking outside
        document.getElementById('scrapeModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeScrapeModal();
            }
        });

        // ========== TTS SETTINGS ==========
        let currentTtsProvider = '{{ $audioBook->tts_provider ?? '' }}';
        let voiceOptionsCache = {};
        let currentAudioPlayer = null;

        // Toggle TTS panel
        document.getElementById('ttsToggleBtn').addEventListener('click', function() {
            const content = document.getElementById('ttsContent');
            const icon = document.getElementById('ttsToggleIcon');
            if (content.style.display === 'none') {
                content.style.display = 'block';
                icon.textContent = '−';
            } else {
                content.style.display = 'none';
                icon.textContent = '+';
            }
        });

        // Provider change
        document.getElementById('ttsProviderSelect').addEventListener('change', function() {
            currentTtsProvider = this.value;
            voiceOptionsCache = {};
            updateVoiceOptions();
            updateStyleInstructionVisibility();
        });

        // Show/hide style instruction based on provider
        function updateStyleInstructionVisibility() {
            const styleSection = document.getElementById('styleInstructionSection');
            if (!styleSection) return; // Guard against null

            const providersWithoutStyle = ['microsoft', 'openai', 'vbee'];
            if (providersWithoutStyle.includes(currentTtsProvider)) {
                styleSection.style.display = 'none';
            } else {
                styleSection.style.display = 'block';
            }
        }

        // Gender change
        document.querySelectorAll('input[name="voiceGender"]').forEach(radio => {
            radio.addEventListener('change', function() {
                updateVoiceOptions();
            });
        });

        // Style preset buttons
        document.querySelectorAll('.style-preset-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const text = this.dataset.text.replace(/&#10;/g, '\n');
                document.getElementById('ttsStyleInstruction').value = text;
            });
        });

        // Fetch and update voice options
        async function updateVoiceOptions() {
            const voiceSelect = document.getElementById('voiceNameSelect');
            const gender = document.querySelector('input[name="voiceGender"]:checked')?.value || 'female';

            if (!currentTtsProvider) {
                voiceSelect.innerHTML = '<option value="">-- Chọn Provider trước --</option>';
                return;
            }

            voiceSelect.innerHTML = '<option value="">⏳ Đang tải...</option>';

            try {
                const voices = await fetchAvailableVoices(gender);
                voiceSelect.innerHTML = '<option value="">-- Chọn giọng --</option>';

                for (const [voiceCode, voiceLabel] of Object.entries(voices)) {
                    const option = document.createElement('option');
                    option.value = voiceCode;
                    option.textContent = voiceLabel;
                    if (voiceCode === '{{ $audioBook->tts_voice_name ?? '' }}') {
                        option.selected = true;
                    }
                    voiceSelect.appendChild(option);
                }
            } catch (error) {
                voiceSelect.innerHTML = '<option value="">-- Lỗi tải giọng --</option>';
            }
        }

        async function fetchAvailableVoices(gender) {
            const cacheKey = `${currentTtsProvider}:${gender}`;
            if (voiceOptionsCache[cacheKey]) {
                return voiceOptionsCache[cacheKey];
            }

            const response = await fetch(`/get-available-voices?gender=${gender}&provider=${currentTtsProvider}`);
            const data = await safeJson(response);

            if (data.success) {
                voiceOptionsCache[cacheKey] = data.voices[gender] || {};
                return voiceOptionsCache[cacheKey];
            }
            return {};
        }

        // Preview voice
        document.getElementById('voicePreviewBtn').addEventListener('click', async function() {
            const voiceName = document.getElementById('voiceNameSelect').value;
            const gender = document.querySelector('input[name="voiceGender"]:checked')?.value || 'female';

            if (!voiceName) {
                alert('Vui lòng chọn giọng trước');
                return;
            }

            if (currentAudioPlayer) {
                currentAudioPlayer.pause();
                currentAudioPlayer = null;
            }

            const btn = this;
            const originalContent = btn.innerHTML;
            btn.innerHTML = '⏳';
            btn.disabled = true;

            try {
                const response = await fetch('/preview-voice', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        text: 'Xin chào, đây là giọng đọc mẫu cho audiobook của bạn.',
                        voice_gender: gender,
                        voice_name: voiceName,
                        provider: currentTtsProvider
                    })
                });

                const data = await safeJson(response);

                if (data.success) {
                    currentAudioPlayer = new Audio(data.audio_url);
                    currentAudioPlayer.play();
                    currentAudioPlayer.addEventListener('ended', () => currentAudioPlayer = null);
                } else {
                    throw new Error(data.error || 'Không thể tạo preview');
                }
            } catch (error) {
                alert('Lỗi: ' + error.message);
            } finally {
                btn.innerHTML = originalContent;
                btn.disabled = false;
            }
        });

        // Save TTS settings
        document.getElementById('saveTtsSettingsBtn').addEventListener('click', async function() {
            const btn = this;
            const originalText = btn.innerHTML;
            btn.innerHTML = '⏳ Đang lưu...';
            btn.disabled = true;

            const data = {
                tts_provider: document.getElementById('ttsProviderSelect').value,
                tts_voice_gender: document.querySelector('input[name="voiceGender"]:checked')?.value ||
                    'female',
                tts_voice_name: document.getElementById('voiceNameSelect').value,
                tts_style_instruction: document.getElementById('ttsStyleInstruction').value
            };

            try {
                const response = await fetch(`/audiobooks/${audioBookId}/update-tts-settings`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify(data)
                });

                const result = await safeJson(response);

                if (result.success) {
                    btn.innerHTML = '✅ Đã lưu!';
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }, 2000);
                } else {
                    throw new Error(result.error || 'Không thể lưu');
                }
            } catch (error) {
                alert('Lỗi: ' + error.message);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        });

        // ========== INTRO/OUTRO MUSIC FUNCTIONS ==========
        function toggleOutroUpload() {
            const useIntro = document.getElementById('outroUseIntro').checked;
            const uploadSection = document.getElementById('outroUploadSection');
            const useIntroMessage = document.getElementById('outroUseIntroMessage');

            if (useIntro) {
                uploadSection.classList.add('hidden');
                useIntroMessage.classList.remove('hidden');
            } else {
                uploadSection.classList.remove('hidden');
                useIntroMessage.classList.add('hidden');
            }
        }

        async function uploadMusic(type) {
            const fileInput = document.getElementById(type === 'intro' ? 'introMusicFile' : 'outroMusicFile');
            const file = fileInput.files[0];

            if (!file) {
                alert('Vui lòng chọn file nhạc');
                return;
            }

            // Validate file size (max 20MB)
            if (file.size > 20 * 1024 * 1024) {
                alert('File quá lớn. Tối đa 20MB');
                return;
            }

            const formData = new FormData();
            formData.append('music_file', file);
            formData.append('type', type);

            try {
                const response = await fetch(`/audiobooks/${audioBookId}/upload-music`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: formData
                });

                // Check if response is OK
                if (!response.ok) {
                    const text = await response.text();
                    let errorMessage = `HTTP ${response.status}`;
                    try {
                        const errorData = JSON.parse(text);
                        errorMessage = errorData.error || errorData.message || errorMessage;
                    } catch (e) {
                        console.error('Upload music error response:', text);
                    }
                    throw new Error(errorMessage);
                }

                const result = await response.json();

                if (result.success) {
                    alert(`✅ Đã tải lên nhạc ${type} thành công!`);
                    location.reload();
                } else {
                    throw new Error(result.error || 'Không thể tải lên');
                }
            } catch (error) {
                console.error('Upload music error:', error);
                alert('❌ Lỗi tải nhạc ' + type + ': ' + error.message);
            }
        }

        async function deleteMusic(type) {
            if (!confirm(`Bạn có chắc muốn xóa nhạc ${type}?`)) {
                return;
            }

            try {
                const response = await fetch(`/audiobooks/${audioBookId}/delete-music`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        type: type
                    })
                });

                // Check if response is OK
                if (!response.ok) {
                    const text = await response.text();
                    let errorMessage = `HTTP ${response.status}`;
                    try {
                        const errorData = JSON.parse(text);
                        errorMessage = errorData.error || errorData.message || errorMessage;
                    } catch (e) {
                        console.error('Delete music error response:', text);
                    }
                    throw new Error(errorMessage);
                }

                const result = await response.json();

                if (result.success) {
                    alert(`✅ Đã xóa nhạc ${type}!`);
                    location.reload();
                } else {
                    throw new Error(result.error || 'Không thể xóa');
                }
            } catch (error) {
                console.error('Delete music error:', error);
                alert('❌ Lỗi xóa nhạc ' + type + ': ' + error.message);
            }
        }

        // Save Music Settings
        document.getElementById('saveMusicSettingsBtn')?.addEventListener('click', async function() {
            const btn = this;
            const originalText = btn.innerHTML;
            btn.innerHTML = '⏳ Đang lưu...';
            btn.disabled = true;

            const data = {
                intro_fade_duration: parseFloat(document.getElementById('introFadeDuration')?.value || 3),
                outro_fade_duration: parseFloat(document.getElementById('outroFadeDuration')?.value || 10),
                outro_extend_duration: parseFloat(document.getElementById('outroExtendDuration')?.value ||
                    5),
                outro_use_intro: document.getElementById('outroUseIntro')?.checked || false
            };

            try {
                const response = await fetch(`/audiobooks/${audioBookId}/update-music-settings`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify(data)
                });

                // Check if response is OK
                if (!response.ok) {
                    const text = await response.text();
                    let errorMessage = `HTTP ${response.status}`;
                    try {
                        const errorData = JSON.parse(text);
                        errorMessage = errorData.error || errorData.message || errorMessage;
                    } catch (e) {
                        console.error('Save music settings error response:', text);
                    }
                    throw new Error(errorMessage);
                }

                const result = await response.json();
                console.log('Music settings saved:', result);

                if (result.success) {
                    // Check if there are chapters that need re-merge
                    console.log('Chapters to re-merge:', result.remerge_count, result.chapters_to_remerge);

                    if (result.remerge_count > 0) {
                        btn.innerHTML = '✅ Đã lưu!';

                        // Ask user if they want to re-merge
                        const confirmRemerge = confirm(
                            `Đã lưu cấu hình nhạc!\n\n` +
                            `Phát hiện ${result.remerge_count} chương đã có file audio.\n` +
                            `Bạn có muốn merge lại để áp dụng nhạc intro/outro mới không?`
                        );

                        if (confirmRemerge) {
                            await reMergeChaptersWithMusic(result.chapters_to_remerge);
                        }

                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    } else {
                        btn.innerHTML = '✅ Đã lưu!';
                        setTimeout(() => {
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        }, 2000);
                    }
                } else {
                    throw new Error(result.error || 'Không thể lưu');
                }
            } catch (error) {
                console.error('Save music settings error:', error);
                alert('❌ Lỗi lưu cấu hình nhạc: ' + error.message);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        });

        // Re-merge chapters with new music settings
        async function reMergeChaptersWithMusic(chapters) {
            // Show progress container
            const progressContainer = document.getElementById('musicMergeProgressContainer');
            const progressStatus = document.getElementById('musicMergeStatus');
            const progressBar = document.getElementById('musicMergeProgressBar');
            const progressPercent = document.getElementById('musicMergePercent');
            const progressLog = document.getElementById('musicMergeLog');

            if (progressContainer) {
                progressContainer.classList.remove('hidden');
                progressLog.innerHTML = '';
            }

            const addLog = (message, type = 'info') => {
                if (progressLog) {
                    const colors = {
                        info: 'text-blue-600',
                        success: 'text-green-600',
                        error: 'text-red-600'
                    };
                    progressLog.innerHTML +=
                        `<div class="${colors[type]} text-xs">${new Date().toLocaleTimeString()} - ${message}</div>`;
                    progressLog.scrollTop = progressLog.scrollHeight;
                }
            };

            addLog(`Bắt đầu merge lại ${chapters.length} chương với nhạc mới...`);

            let successCount = 0;
            let failCount = 0;

            for (let i = 0; i < chapters.length; i++) {
                const chapter = chapters[i];
                const percent = Math.round(((i + 1) / chapters.length) * 100);

                if (progressStatus) progressStatus.textContent =
                    `Đang merge chương ${chapter.chapter_number} (${i + 1}/${chapters.length})...`;
                if (progressBar) progressBar.style.width = `${percent}%`;
                if (progressPercent) progressPercent.textContent = `${percent}%`;

                addLog(`Đang merge chương ${chapter.chapter_number}...`);

                try {
                    const response = await fetch(`/audiobooks/${audioBookId}/chapters/${chapter.id}/merge-audio`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    });

                    const result = await safeJson(response);

                    if (result.success) {
                        successCount++;
                        addLog(`✅ Chương ${chapter.chapter_number} đã merge xong`, 'success');
                    } else {
                        failCount++;
                        addLog(`❌ Chương ${chapter.chapter_number}: ${result.error || 'Lỗi'}`, 'error');
                    }
                } catch (error) {
                    failCount++;
                    addLog(`❌ Chương ${chapter.chapter_number}: ${error.message}`, 'error');
                }
            }

            // Complete
            if (progressStatus) progressStatus.textContent = 'Hoàn tất!';
            if (progressBar) progressBar.style.width = '100%';
            if (progressPercent) progressPercent.textContent = '100%';

            addLog(`Hoàn tất! Thành công: ${successCount}, Thất bại: ${failCount}`, successCount === chapters.length ?
                'success' : 'info');

            // Auto hide after 5 seconds if all success
            if (failCount === 0) {
                setTimeout(() => {
                    if (progressContainer) progressContainer.classList.add('hidden');
                }, 5000);
            }
        }

        // ========== WAVE EFFECT FUNCTIONS ==========
        function toggleWaveSettings() {
            const enabled = document.getElementById('waveEnabled').checked;
            const panel = document.getElementById('waveSettingsPanel');
            if (enabled) {
                panel.classList.remove('hidden');
            } else {
                panel.classList.add('hidden');
            }
        }

        // Update opacity display value
        document.getElementById('waveOpacity')?.addEventListener('input', function() {
            document.getElementById('waveOpacityValue').textContent = this.value;
        });

        // Save Wave Settings
        document.getElementById('saveWaveSettingsBtn')?.addEventListener('click', async function() {
            const btn = this;
            const originalText = btn.innerHTML;
            btn.innerHTML = '⏳ Đang lưu...';
            btn.disabled = true;

            const data = {
                wave_enabled: document.getElementById('waveEnabled')?.checked || false,
                wave_type: document.querySelector('input[name="waveType"]:checked')?.value || 'cline',
                wave_position: document.querySelector('input[name="wavePosition"]:checked')?.value ||
                    'bottom',
                wave_height: parseInt(document.getElementById('waveHeight')?.value || 100),
                wave_color: document.getElementById('waveColor')?.value || '#00ff00',
                wave_opacity: parseFloat(document.getElementById('waveOpacity')?.value || 0.8)
            };

            try {
                const response = await fetch(`/audiobooks/${audioBookId}/update-wave-settings`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify(data)
                });

                const result = await safeJson(response);

                if (result.success) {
                    btn.innerHTML = '✅ Đã lưu!';
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }, 2000);
                } else {
                    throw new Error(result.error || 'Không thể lưu');
                }
            } catch (error) {
                alert('Lỗi: ' + error.message);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        });

        // ========== FLOATING TOOLBAR SCROLL HANDLER ==========
        function setupFloatingToolbar() {
            const anchor = document.getElementById('chapterToolbarAnchor');
            const floating = document.getElementById('chapterFloatingToolbar');
            if (!anchor || !floating) return;

            let isVisible = false;

            function checkScroll() {
                const chaptersTab = document.getElementById('chapters-tab');
                // Only show floating toolbar when chapters tab is active
                if (chaptersTab && chaptersTab.classList.contains('hidden')) {
                    if (isVisible) {
                        floating.style.transform = 'translateY(-100%)';
                        setTimeout(() => {
                            floating.style.display = 'none';
                        }, 300);
                        isVisible = false;
                    }
                    return;
                }

                const anchorRect = anchor.getBoundingClientRect();
                const shouldShow = anchorRect.bottom < 0;

                if (shouldShow && !isVisible) {
                    floating.style.display = 'block';
                    requestAnimationFrame(() => {
                        floating.style.transform = 'translateY(0)';
                    });
                    isVisible = true;
                } else if (!shouldShow && isVisible) {
                    floating.style.transform = 'translateY(-100%)';
                    setTimeout(() => {
                        floating.style.display = 'none';
                    }, 300);
                    isVisible = false;
                }
            }

            window.addEventListener('scroll', checkScroll, {
                passive: true
            });
        }

        // Initialize voice options on page load
        document.addEventListener('DOMContentLoaded', function() {
            if (currentTtsProvider) {
                updateVoiceOptions();
                updateStyleInstructionVisibility();
            }
            setupChapterCheckboxes();
            setupDescriptionEditor();
            setupFloatingToolbar();
            initTabs(); // Initialize tab navigation
        });

        // ========== DESCRIPTION EDITOR ==========
        function setupDescriptionEditor() {
            const saveBtn = document.getElementById('saveDescBtn');
            const rewriteBtn = document.getElementById('rewriteDescBtn');
            const generateAudioBtn = document.getElementById('generateDescAudioBtn');
            const deleteAudioBtn = document.getElementById('deleteDescAudioBtn');
            const descTextarea = document.getElementById('bookDescription');
            const statusDiv = document.getElementById('descStatus');
            const audioContainer = document.getElementById('descAudioContainer');
            const audioPlayer = document.getElementById('descAudioPlayer');
            const audioDuration = document.getElementById('descAudioDuration');
            const videoContainer = document.getElementById('descVideoContainer');
            const videoPlayer = document.getElementById('descVideoPlayer');
            const videoDuration = document.getElementById('descVideoDuration');
            const deleteVideoBtn = document.getElementById('deleteDescVideoBtn');

            // Image picker elements
            const loadMediaBtn = document.getElementById('loadDescMediaBtn');
            const mediaGrid = document.getElementById('descMediaGrid');
            const mediaEmpty = document.getElementById('descMediaEmpty');
            const selectedImagePreview = document.getElementById('descSelectedImagePreview');
            const selectedImageImg = document.getElementById('descSelectedImageImg');
            const selectedImageName = document.getElementById('descSelectedImageName');
            const clearImageBtn = document.getElementById('descClearImageBtn');
            const generateIntroVideoBtn = document.getElementById('generateDescIntroVideoBtn');

            let selectedDescImage = null; // { filename, type, url }

            if (!saveBtn || !descTextarea) return;

            // ---- Image Picker: Load media library ----
            if (loadMediaBtn) {
                loadMediaBtn.addEventListener('click', async function() {
                    const btn = this;
                    btn.innerHTML = '⏳ Đang tải...';
                    btn.disabled = true;

                    try {
                        const response = await fetch(`/audiobooks/${audioBookId}/media`, {
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            }
                        });
                        const result = await safeJson(response);

                        if (result.success && result.media) {
                            const allImages = [];

                            // Collect thumbnails
                            if (result.media.thumbnails) {
                                result.media.thumbnails.forEach(img => {
                                    allImages.push({
                                        filename: img.filename,
                                        type: 'thumbnails',
                                        url: img.url
                                    });
                                });
                            }

                            // Collect scenes
                            if (result.media.scenes) {
                                result.media.scenes.forEach(img => {
                                    allImages.push({
                                        filename: img.filename,
                                        type: 'scenes',
                                        url: img.url
                                    });
                                });
                            }

                            if (allImages.length === 0) {
                                mediaGrid.classList.add('hidden');
                                mediaEmpty.classList.remove('hidden');
                            } else {
                                mediaEmpty.classList.add('hidden');
                                mediaGrid.innerHTML = '';
                                allImages.forEach(img => {
                                    const div = document.createElement('div');
                                    div.className =
                                        'relative cursor-pointer group rounded overflow-hidden border-2 border-transparent hover:border-indigo-400 transition';
                                    div.innerHTML = `
                                        <img src="${img.url}" alt="${img.filename}" class="w-full h-16 object-cover">
                                        <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition"></div>
                                        <div class="absolute bottom-0 left-0 right-0 bg-black/50 text-white text-[9px] px-1 py-0.5 truncate">${img.type === 'thumbnails' ? '📷' : '🎬'} ${img.filename}</div>
                                    `;
                                    div.addEventListener('click', () => {
                                        // Deselect all
                                        mediaGrid.querySelectorAll('div.border-indigo-500')
                                            .forEach(el => {
                                                el.classList.remove('border-indigo-500',
                                                    'ring-2', 'ring-indigo-400');
                                                el.classList.add('border-transparent');
                                            });
                                        // Select this
                                        div.classList.remove('border-transparent');
                                        div.classList.add('border-indigo-500', 'ring-2',
                                            'ring-indigo-400');

                                        selectedDescImage = img;
                                        selectedImageImg.src = img.url;
                                        selectedImageName.textContent =
                                            `${img.type}/${img.filename}`;
                                        selectedImagePreview.classList.remove('hidden');

                                        // Enable video button if audio exists
                                        if (generateIntroVideoBtn && audioContainer && !
                                            audioContainer.classList.contains('hidden')) {
                                            generateIntroVideoBtn.disabled = false;
                                            generateIntroVideoBtn.classList.remove('opacity-50',
                                                'cursor-not-allowed');
                                        }
                                    });
                                    mediaGrid.appendChild(div);
                                });
                                mediaGrid.classList.remove('hidden');
                            }
                        }
                    } catch (error) {
                        statusDiv.innerHTML =
                            `<span class="text-red-600">❌ Lỗi tải thư viện: ${error.message}</span>`;
                    } finally {
                        btn.innerHTML = '🔄 Tải thư viện';
                        btn.disabled = false;
                    }
                });
            }

            // Clear selected image
            if (clearImageBtn) {
                clearImageBtn.addEventListener('click', () => {
                    selectedDescImage = null;
                    selectedImagePreview.classList.add('hidden');
                    mediaGrid.querySelectorAll('div.border-indigo-500').forEach(el => {
                        el.classList.remove('border-indigo-500', 'ring-2', 'ring-indigo-400');
                        el.classList.add('border-transparent');
                    });
                    if (generateIntroVideoBtn) {
                        generateIntroVideoBtn.disabled = true;
                        generateIntroVideoBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    }
                });
            }

            // ---- Generate Intro Video (image + audio + music + wave → video) ----
            if (generateIntroVideoBtn) {
                generateIntroVideoBtn.addEventListener('click', async function() {
                    if (!selectedDescImage) {
                        alert('Vui lòng chọn một ảnh từ thư viện media trước');
                        return;
                    }

                    // Check intro music
                    const hasIntroMusic = {{ $audioBook->intro_music ? 'true' : 'false' }};
                    if (!hasIntroMusic) {
                        statusDiv.innerHTML =
                            '<span class="text-red-600">❌ Chưa có nhạc Intro. Vui lòng upload nhạc Intro trong phần "🎵 Nhạc Intro/Outro" bên phải trước.</span>';
                        return;
                    }

                    // Check outro music (either dedicated outro or "use intro as outro")
                    const hasOutroMusic = {{ $audioBook->outro_music ? 'true' : 'false' }};
                    const outroUseIntro = {{ $audioBook->outro_use_intro ? 'true' : 'false' }};
                    if (!hasOutroMusic && !outroUseIntro) {
                        statusDiv.innerHTML =
                            '<span class="text-red-600">❌ Chưa có nhạc Outro. Vui lòng upload nhạc Outro hoặc chọn "Dùng nhạc Intro" trong phần "🎵 Nhạc Intro/Outro".</span>';
                        return;
                    }

                    // Check wave settings
                    const waveEnabled = {{ $audioBook->wave_enabled ? 'true' : 'false' }};
                    if (!waveEnabled) {
                        statusDiv.innerHTML =
                            '<span class="text-red-600">❌ Chưa bật hiệu ứng sóng âm. Vui lòng bật và cấu hình trong phần "🌊 Hiệu ứng Sóng Âm" bên phải.</span>';
                        return;
                    }

                    if (!confirm('Tạo video giới thiệu từ ảnh + audio + nhạc nền + sóng âm?')) return;

                    const btn = this;
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '⏳ Đang tạo video...';
                    btn.disabled = true;

                    statusDiv.innerHTML =
                        '<span class="text-blue-600">🎬 Đang tạo video giới thiệu (ảnh + audio + nhạc nền + sóng âm)... Quá trình có thể mất 1-3 phút.</span>';

                    try {
                        const response = await fetch(`/audiobooks/${audioBookId}/generate-description-video`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({
                                image_path: selectedDescImage.filename,
                                image_type: selectedDescImage.type
                            })
                        });

                        const result = await safeJson(response);

                        if (result.success && result.video_url) {
                            statusDiv.innerHTML =
                                '<span class="text-green-600">✅ Đã tạo video giới thiệu thành công!</span>';

                            // Update video player
                            if (videoPlayer && videoContainer) {
                                videoPlayer.src = result.video_url;
                                videoPlayer.load();
                                if (result.video_duration) {
                                    const mins = Math.floor(result.video_duration / 60);
                                    const secs = Math.floor(result.video_duration % 60);
                                    videoDuration.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
                                }
                                videoContainer.classList.remove('hidden');
                            }

                            setTimeout(() => statusDiv.innerHTML = '', 5000);
                        } else {
                            throw new Error(result.error || 'Không thể tạo video');
                        }
                    } catch (error) {
                        statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                    } finally {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                });
            }

            // ---- Generate description audio ----
            if (generateAudioBtn) {
                generateAudioBtn.addEventListener('click', async function() {
                    const description = descTextarea.value.trim();
                    if (!description) {
                        alert('Vui lòng nhập nội dung giới thiệu trước');
                        return;
                    }

                    // Check TTS settings
                    const provider = document.getElementById('ttsProviderSelect').value;
                    const voiceName = document.getElementById('voiceNameSelect').value;
                    if (!provider || !voiceName) {
                        alert('Vui lòng cấu hình TTS Settings trước (Provider và Voice)');
                        return;
                    }

                    const btn = this;
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '⏳ Đang tạo...';
                    btn.disabled = true;

                    statusDiv.innerHTML = '<span class="text-blue-600">🎙️ Đang tạo audio giới thiệu...</span>';

                    try {
                        const response = await fetch(`/audiobooks/${audioBookId}/generate-description-audio`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({
                                description: description,
                                provider: provider,
                                voice_name: voiceName,
                                voice_gender: document.querySelector(
                                    'input[name="voiceGender"]:checked')?.value || 'female',
                                style_instruction: document.getElementById(
                                    'ttsStyleInstruction')?.value || ''
                            })
                        });

                        const result = await safeJson(response);

                        if (result.success) {
                            statusDiv.innerHTML =
                                '<span class="text-green-600">✅ Đã tạo audio giới thiệu!</span>';

                            // Update audio player
                            audioPlayer.src = result.audio_url;
                            audioPlayer.load();
                            if (result.duration) {
                                const mins = Math.floor(result.duration / 60);
                                const secs = Math.floor(result.duration % 60);
                                audioDuration.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
                            }
                            audioContainer.classList.remove('hidden');

                            // Enable intro video button
                            if (generateIntroVideoBtn && selectedDescImage) {
                                generateIntroVideoBtn.disabled = false;
                                generateIntroVideoBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                            }

                            setTimeout(() => statusDiv.innerHTML = '', 3000);
                        } else {
                            throw new Error(result.error || 'Không thể tạo audio');
                        }
                    } catch (error) {
                        statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                    } finally {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                });
            }

            // Delete description audio
            if (deleteAudioBtn) {
                deleteAudioBtn.addEventListener('click', async function() {
                    if (!confirm('Bạn có chắc muốn xóa audio giới thiệu này?')) return;

                    const btn = this;
                    btn.disabled = true;

                    try {
                        const response = await fetch(`/audiobooks/${audioBookId}/delete-description-audio`, {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            }
                        });

                        const result = await safeJson(response);

                        if (result.success) {
                            statusDiv.innerHTML = '<span class="text-green-600">✅ Đã xóa audio!</span>';
                            audioContainer.classList.add('hidden');
                            audioPlayer.src = '';
                            audioDuration.textContent = '';

                            // Disable intro video button
                            if (generateIntroVideoBtn) {
                                generateIntroVideoBtn.disabled = true;
                                generateIntroVideoBtn.classList.add('opacity-50', 'cursor-not-allowed');
                            }

                            setTimeout(() => statusDiv.innerHTML = '', 3000);
                        } else {
                            throw new Error(result.error || 'Không thể xóa');
                        }
                    } catch (error) {
                        statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                    } finally {
                        btn.disabled = false;
                    }
                });
            }

            // Delete description video
            if (deleteVideoBtn) {
                deleteVideoBtn.addEventListener('click', async function() {
                    if (!confirm('Bạn có chắc muốn xóa video giới thiệu này?')) return;

                    const btn = this;
                    btn.disabled = true;

                    try {
                        const response = await fetch(`/audiobooks/${audioBookId}/delete-description-video`, {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            }
                        });

                        const result = await safeJson(response);

                        if (result.success) {
                            statusDiv.innerHTML = '<span class="text-green-600">✅ Đã xóa video!</span>';
                            videoContainer.classList.add('hidden');
                            videoPlayer.src = '';
                            videoDuration.textContent = '';
                            setTimeout(() => statusDiv.innerHTML = '', 3000);
                        } else {
                            throw new Error(result.error || 'Không thể xóa');
                        }
                    } catch (error) {
                        statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                    } finally {
                        btn.disabled = false;
                    }
                });
            }

            // Save description
            saveBtn.addEventListener('click', async function() {
                const btn = this;
                const originalText = btn.innerHTML;
                btn.innerHTML = '⏳ Đang lưu...';
                btn.disabled = true;

                try {
                    const response = await fetch(`/audiobooks/${audioBookId}/update-description`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            description: descTextarea.value
                        })
                    });

                    const result = await safeJson(response);

                    if (result.success) {
                        statusDiv.innerHTML = '<span class="text-green-600">✅ Đã lưu mô tả!</span>';
                        setTimeout(() => statusDiv.innerHTML = '', 3000);
                    } else {
                        throw new Error(result.error || 'Không thể lưu');
                    }
                } catch (error) {
                    statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                } finally {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });

            // Rewrite with AI
            rewriteBtn.addEventListener('click', async function() {
                const currentDesc = descTextarea.value.trim();
                const bookTitle = '{{ addslashes($audioBook->title) }}';
                const bookAuthor = '{{ addslashes($audioBook->author ?? '') }}';
                const bookCategory = '{{ addslashes($audioBook->category ?? '') }}';
                const channelName = '{{ addslashes($audioBook->youtubeChannel->title ?? '') }}';

                if (!currentDesc && !bookTitle) {
                    alert('Cần có tiêu đề hoặc mô tả hiện tại để viết lại');
                    return;
                }

                const btn = this;
                const originalText = btn.innerHTML;
                btn.innerHTML = '⏳ Đang viết...';
                btn.disabled = true;
                statusDiv.innerHTML = '<span class="text-blue-600">🤖 AI đang viết lại mô tả...</span>';

                try {
                    const response = await fetch(`/audiobooks/${audioBookId}/rewrite-description`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            current_description: currentDesc,
                            title: bookTitle,
                            author: bookAuthor,
                            category: bookCategory,
                            channel_name: channelName
                        })
                    });

                    const result = await safeJson(response);

                    if (result.success) {
                        descTextarea.value = result.description;
                        statusDiv.innerHTML =
                            '<span class="text-green-600">✅ Đã viết lại! Nhấn "Lưu" để lưu thay đổi.</span>';
                    } else {
                        throw new Error(result.error || 'Không thể viết lại');
                    }
                } catch (error) {
                    statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                } finally {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });
        }

        // ========== CHAPTER SELECTION & BATCH TTS ==========
        function setupChapterCheckboxes() {
            const selectAllCheckbox = document.getElementById('selectAllChapters');
            const selectAllFloating = document.getElementById('selectAllChaptersFloating');
            const chapterCheckboxes = document.querySelectorAll('.chapter-checkbox');
            const generateBtn = document.getElementById('generateSelectedTtsBtn');
            const generateVideoBtn = document.getElementById('generateSelectedVideoBtn');
            const deleteBtn = document.getElementById('deleteSelectedChaptersBtn');
            const generateBtnFloating = document.getElementById('generateSelectedTtsBtnFloating');
            const generateVideoBtnFloating = document.getElementById('generateSelectedVideoBtnFloating');
            const deleteBtnFloating = document.getElementById('deleteSelectedChaptersBtnFloating');
            const selectedCountSpan = document.getElementById('selectedCount');
            const selectedVideoCountSpan = document.getElementById('selectedVideoCount');
            const selectedCountFloating = document.getElementById('selectedCountFloating');
            const selectedVideoCountFloating = document.getElementById('selectedVideoCountFloating');

            if (!selectAllCheckbox) return;

            // Select All handler (original)
            selectAllCheckbox.addEventListener('change', function() {
                chapterCheckboxes.forEach(cb => cb.checked = this.checked);
                if (selectAllFloating) selectAllFloating.checked = this.checked;
                updateSelectedCount();
            });

            // Select All handler (floating)
            if (selectAllFloating) {
                selectAllFloating.addEventListener('change', function() {
                    chapterCheckboxes.forEach(cb => cb.checked = this.checked);
                    selectAllCheckbox.checked = this.checked;
                    updateSelectedCount();
                });
            }

            // Individual checkbox handler
            chapterCheckboxes.forEach(cb => {
                cb.addEventListener('change', function() {
                    const allChecked = Array.from(chapterCheckboxes).every(c => c.checked);
                    const noneChecked = Array.from(chapterCheckboxes).every(c => !c.checked);
                    selectAllCheckbox.checked = allChecked;
                    selectAllCheckbox.indeterminate = !allChecked && !noneChecked;
                    if (selectAllFloating) {
                        selectAllFloating.checked = allChecked;
                        selectAllFloating.indeterminate = !allChecked && !noneChecked;
                    }
                    updateSelectedCount();
                });
            });

            function updateSelectedCount() {
                const count = document.querySelectorAll('.chapter-checkbox:checked').length;
                selectedCountSpan.textContent = count;
                if (selectedVideoCountSpan) selectedVideoCountSpan.textContent = count;
                if (selectedCountFloating) selectedCountFloating.textContent = count;
                if (selectedVideoCountFloating) selectedVideoCountFloating.textContent = count;
                generateBtn.classList.toggle('hidden', count === 0);
                if (generateVideoBtn) generateVideoBtn.classList.toggle('hidden', count === 0);
                if (deleteBtn) deleteBtn.classList.toggle('hidden', count === 0);
                if (generateBtnFloating) generateBtnFloating.classList.toggle('hidden', count === 0);
                if (generateVideoBtnFloating) generateVideoBtnFloating.classList.toggle('hidden', count === 0);
                if (deleteBtnFloating) deleteBtnFloating.classList.toggle('hidden', count === 0);
            }
        }

        // Delete selected chapters
        async function deleteSelectedChapters() {
            const selectedCheckboxes = document.querySelectorAll('.chapter-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                alert('Vui lòng chọn ít nhất một chương');
                return;
            }

            const chapterIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterId);
            const chapterNumbers = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterNumber);

            const confirmMsg =
                `Bạn có chắc muốn xóa ${chapterIds.length} chương?\n\nChương: ${chapterNumbers.join(', ')}\n\nHành động này không thể hoàn tác.`;
            if (!confirm(confirmMsg)) return;

            const btn = document.getElementById('deleteSelectedChaptersBtn');
            const btnFloating = document.getElementById('deleteSelectedChaptersBtnFloating');
            const originalText = btn ? btn.innerHTML : '';
            const originalTextFloating = btnFloating ? btnFloating.innerHTML : '';

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '⏳ Đang xóa...';
            }
            if (btnFloating) {
                btnFloating.disabled = true;
                btnFloating.innerHTML = '⏳ Đang xóa...';
            }

            let successCount = 0;
            let errorCount = 0;

            for (const chapterId of chapterIds) {
                if (!chapterId) {
                    errorCount++;
                    console.error('Delete chapter error: missing chapter id');
                    continue;
                }
                try {
                    const deleteUrl = `${deleteChapterUrlBase}/${chapterId}`;
                    if (!deleteUrl.includes('/chapters/')) {
                        errorCount++;
                        console.error('Delete chapter error: invalid URL', deleteUrl);
                        continue;
                    }
                    const response = await fetch(deleteUrl, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin'
                    });

                    if (response.ok) {
                        successCount++;
                    } else {
                        errorCount++;
                        const errText = await response.text();
                        console.error('Delete chapter failed:', chapterId, errText);
                    }
                } catch (error) {
                    errorCount++;
                    console.error('Delete chapter error:', chapterId, error);
                }
            }

            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
            if (btnFloating) {
                btnFloating.disabled = false;
                btnFloating.innerHTML = originalTextFloating;
            }

            if (errorCount > 0) {
                alert(`Đã xóa ${successCount} chương, ${errorCount} lỗi. Vui lòng thử lại với các chương lỗi.`);
            } else {
                alert(`✅ Đã xóa ${successCount} chương.`);
            }

            if (successCount > 0) {
                window.location.reload();
            }
        }

        // Generate Video (MP4) for selected chapters using FFmpeg
        async function generateVideoForSelectedChapters() {
            const selectedCheckboxes = document.querySelectorAll('.chapter-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                alert('Vui lòng chọn ít nhất một chương');
                return;
            }

            const chapterIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterId);
            const chapterNumbers = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterNumber);

            const confirmMsg =
                `Bạn có muốn tạo Video MP4 cho ${chapterIds.length} chương?\n\nChương: ${chapterNumbers.join(', ')}\n\nVideo sẽ được tạo từ file audio full và ảnh bìa chương.`;
            if (!confirm(confirmMsg)) return;

            const btn = document.getElementById('generateSelectedVideoBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳ Đang tạo video...';

            // Show progress
            let progressHtml = `
                <div id="videoProgressContainer" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-red-800" id="videoProgressStatus">Đang tạo video...</span>
                        <span class="text-sm text-red-600" id="videoProgressPercent">0%</span>
                    </div>
                    <div class="w-full bg-red-200 rounded-full h-2">
                        <div id="videoProgressBar" class="bg-red-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                    <div id="videoLogContainer" class="mt-3 max-h-40 overflow-y-auto text-xs font-mono bg-gray-900 text-green-400 p-2 rounded"></div>
                </div>
            `;

            const ttsProgress = document.getElementById('ttsProgressContainer');
            let existingProgress = document.getElementById('videoProgressContainer');
            if (existingProgress) existingProgress.remove();
            ttsProgress.insertAdjacentHTML('afterend', progressHtml);

            const progressBar = document.getElementById('videoProgressBar');
            const progressStatus = document.getElementById('videoProgressStatus');
            const progressPercent = document.getElementById('videoProgressPercent');
            const logContainer = document.getElementById('videoLogContainer');

            function addLog(message, type = 'info') {
                const colors = {
                    info: 'text-blue-400',
                    success: 'text-green-400',
                    error: 'text-red-400',
                    warning: 'text-yellow-400'
                };
                logContainer.innerHTML +=
                    `<div class="${colors[type]}">[${new Date().toLocaleTimeString()}] ${message}</div>`;
                logContainer.scrollTop = logContainer.scrollHeight;
            }

            try {
                addLog(`Bắt đầu tạo video cho ${chapterIds.length} chương...`);

                let successCount = 0;
                let errorCount = 0;

                for (let i = 0; i < chapterIds.length; i++) {
                    const chapterId = chapterIds[i];
                    const chapterNumber = chapterNumbers[i];
                    const progress = Math.round(((i + 1) / chapterIds.length) * 100);

                    progressStatus.textContent =
                        `Đang tạo video chương ${chapterNumber} (${i + 1}/${chapterIds.length})...`;
                    progressPercent.textContent = `${progress}%`;
                    progressBar.style.width = `${progress}%`;

                    addLog(`Đang xử lý chương ${chapterNumber}... (có thể mất 1-3 phút)`);

                    try {
                        const response = await fetch(
                            `/audiobooks/{{ $audioBook->id }}/generate-chapter-video/${chapterId}`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                    'Accept': 'application/json'
                                },
                                signal: AbortSignal.timeout(600000) // 10 minutes timeout
                            });

                        const result = await safeJson(response);

                        if (result.success) {
                            addLog(`✅ Chương ${chapterNumber}: Video tạo thành công! (${result.file_size} MB)`,
                                'success');
                            successCount++;

                            // Update chapter row with video link
                            const chapterDiv = document.getElementById(`chapter-${chapterId}`);
                            if (chapterDiv && result.video_url) {
                                // Find or create video preview section
                                let videoSection = chapterDiv.querySelector('.video-preview-section');
                                if (!videoSection) {
                                    const audioSection = chapterDiv.querySelector('.border-t.border-gray-100');
                                    if (audioSection) {
                                        const videoHtml = `
                                            <div class="video-preview-section mb-3 p-3 bg-gradient-to-r from-blue-100 to-cyan-100 border-2 border-blue-300 rounded-lg">
                                                <div class="flex items-center justify-between flex-wrap gap-2">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-lg">🎬</span>
                                                        <span class="font-semibold text-blue-800">Video Chương ${chapterNumber}</span>
                                                        <span class="text-xs bg-blue-200 text-blue-700 px-2 py-0.5 rounded">${result.file_size} MB</span>
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        <button onclick="openVideoPreview('${result.video_url}')"
                                                            class="bg-blue-500 hover:bg-blue-600 text-white text-xs px-3 py-1.5 rounded transition">
                                                            ▶️ Xem
                                                        </button>
                                                        <a href="${result.video_url}" download="chapter_${chapterNumber}.mp4"
                                                            class="bg-green-500 hover:bg-green-600 text-white text-xs px-3 py-1.5 rounded transition">
                                                            ⬇️ Tải xuống
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        `;
                                        // Insert after the full chapter audio section
                                        const fullAudioSection = audioSection.querySelector(
                                            '.bg-gradient-to-r.from-purple-100');
                                        if (fullAudioSection) {
                                            fullAudioSection.insertAdjacentHTML('afterend', videoHtml);
                                        } else {
                                            audioSection.insertAdjacentHTML('afterbegin', videoHtml);
                                        }
                                    }
                                }
                            }
                        } else {
                            addLog(`❌ Chương ${chapterNumber}: ${result.error}`, 'error');
                            errorCount++;
                        }
                    } catch (error) {
                        addLog(`❌ Chương ${chapterNumber}: ${error.message}`, 'error');
                        errorCount++;
                    }
                }

                progressStatus.textContent = `Hoàn thành: ${successCount} thành công, ${errorCount} lỗi`;
                progressPercent.textContent = '100%';
                progressBar.style.width = '100%';

                addLog(`🎬 Hoàn thành! ${successCount}/${chapterIds.length} video đã được tạo.`, successCount ===
                    chapterIds.length ? 'success' : 'warning');

                // Suggest reload to see all videos properly
                if (successCount > 0) {
                    addLog(`💡 Reload trang để xem tất cả video.`, 'info');
                }

            } catch (error) {
                addLog(`❌ Lỗi: ${error.message}`, 'error');
                progressStatus.textContent = `Lỗi: ${error.message}`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }

        // Generate TTS for selected chapters - CHUNK BY CHUNK with realtime display
        async function generateTtsForSelectedChapters() {
            const selectedCheckboxes = document.querySelectorAll('.chapter-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                alert('Vui lòng chọn ít nhất một chương');
                return;
            }

            // Check TTS settings
            const provider = document.getElementById('ttsProviderSelect').value;
            const voiceName = document.getElementById('voiceNameSelect').value;

            if (!provider || !voiceName) {
                alert('Vui lòng cấu hình TTS Settings trước (Provider và Voice)');
                return;
            }

            const chapterIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterId);
            const chapterNumbers = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterNumber);

            const confirmMsg =
                `Bạn có muốn tạo TTS cho ${chapterIds.length} chương?\n\nChương: ${chapterNumbers.join(', ')}\n\nChỉ tạo audio cho những đoạn còn thiếu.`;
            if (!confirm(confirmMsg)) return;

            const progressContainer = document.getElementById('ttsProgressContainer');
            const progressBar = document.getElementById('ttsProgressBar');
            const progressStatus = document.getElementById('ttsProgressStatus');
            const progressPercent = document.getElementById('ttsProgressPercent');
            const chunkStatus = document.getElementById('ttsChunkStatus');
            const chunkPercent = document.getElementById('ttsChunkPercent');
            const chunkBar = document.getElementById('ttsChunkBar');
            const logContainer = document.getElementById('ttsLogContainer');
            const generatedChunksContainer = document.getElementById('ttsGeneratedChunks');
            const generateBtn = document.getElementById('generateSelectedTtsBtn');

            // Prevent page navigation during TTS generation
            let isGenerating = true;
            const beforeUnloadHandler = (e) => {
                if (isGenerating) {
                    e.preventDefault();
                    e.returnValue = 'Đang tạo TTS! Bạn có chắc muốn rời trang?';
                    return e.returnValue;
                }
            };
            window.addEventListener('beforeunload', beforeUnloadHandler);

            // Reset and show progress
            progressContainer.classList.remove('hidden', 'bg-yellow-50', 'border-yellow-200', 'bg-green-50',
                'border-green-200');
            progressContainer.classList.add('bg-blue-50', 'border-blue-200');
            progressStatus.classList.remove('text-yellow-800', 'text-green-800');
            progressStatus.classList.add('text-blue-800');
            logContainer.innerHTML = '';
            generatedChunksContainer.innerHTML = '';
            generateBtn.disabled = true;
            generateBtn.innerHTML = '⏳ Đang xử lý...';

            // Skip style_instruction for Microsoft, OpenAI and Vbee
            const providersWithoutStyle = ['microsoft', 'openai', 'vbee'];
            const ttsSettings = {
                provider: provider,
                voice_name: voiceName,
                voice_gender: document.querySelector('input[name="voiceGender"]:checked')?.value || 'female'
            };
            if (!providersWithoutStyle.includes(provider)) {
                ttsSettings.style_instruction = document.getElementById('ttsStyleInstruction').value;
            }

            const addLog = (msg, type = 'info') => {
                const colors = {
                    info: 'text-green-400',
                    error: 'text-red-400',
                    warn: 'text-yellow-400',
                    success: 'text-cyan-400'
                };
                const time = new Date().toLocaleTimeString();
                logContainer.innerHTML += `<div class="${colors[type]}">[${time}] ${msg}</div>`;
                logContainer.scrollTop = logContainer.scrollHeight;
            };

            const addChunkCard = (chapterNum, chunkNum, audioUrl, duration, isFull = false) => {
                const cardClass = isFull ?
                    'bg-gradient-to-r from-purple-100 to-pink-100 border-2 border-purple-400' :
                    'bg-green-50 border border-green-300';
                const icon = isFull ? '🎧' : '✅';
                const label = isFull ? `Full C${chapterNum}` : `C${chapterNum}-${chunkNum}`;
                const durationText = duration ? `${Math.round(duration)}s` : '';

                const card = document.createElement('div');
                card.className = `${cardClass} rounded p-2 text-xs`;
                card.innerHTML = `
                    <div class="flex items-center gap-1 mb-1">
                        <span>${icon}</span>
                        <span class="font-semibold ${isFull ? 'text-purple-700' : 'text-green-700'}">${label}</span>
                        ${durationText ? `<span class="text-gray-500">${durationText}</span>` : ''}
                    </div>
                    <audio controls class="w-full h-6">
                        <source src="${audioUrl}" type="audio/mpeg">
                    </audio>
                `;
                generatedChunksContainer.appendChild(card);
            };

            let completedChapters = 0;
            const totalChapters = chapterIds.length;
            let errors = [];
            let totalChunksGenerated = 0;
            let totalChunksSkipped = 0;

            addLog(`🚀 Bắt đầu tạo TTS cho ${totalChapters} chương...`);

            for (let i = 0; i < chapterIds.length; i++) {
                const chapterId = chapterIds[i];
                const chapterNum = chapterNumbers[i];

                progressStatus.textContent = `Chương ${chapterNum} (${i + 1}/${totalChapters})`;
                addLog(`📖 Chương ${chapterNum}: Đang khởi tạo chunks...`);

                try {
                    // Step 1: Initialize chunks
                    const initResponse = await fetch(
                        `/audiobooks/${audioBookId}/chapters/${chapterId}/initialize-chunks`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify(ttsSettings)
                        });
                    const initResult = await safeJson(initResponse);

                    if (!initResult.success) {
                        throw new Error(initResult.error || 'Khởi tạo chunks thất bại');
                    }

                    const chunks = initResult.chunks;
                    const totalChunks = chunks.length;
                    const pendingChunks = chunks.filter(c => c.status !== 'completed');

                    addLog(
                        `📖 Chương ${chapterNum}: ${totalChunks} đoạn (${pendingChunks.length} cần tạo, ${totalChunks - pendingChunks.length} đã có)`
                    );

                    // Show already completed chunks
                    chunks.filter(c => c.status === 'completed' && c.audio_file).forEach(c => {
                        addChunkCard(chapterNum, c.chunk_number, `/storage/${c.audio_file}`, c.duration);
                        totalChunksSkipped++;
                    });

                    // Step 2: Generate each pending chunk
                    let chunkCompleted = totalChunks - pendingChunks.length;

                    for (const chunk of chunks) {
                        if (chunk.status === 'completed' && chunk.audio_file) {
                            continue; // Skip already completed
                        }

                        chunkStatus.textContent = `Chương ${chapterNum}: Đoạn ${chunk.chunk_number}/${totalChunks}`;
                        const chunkPct = Math.round((chunkCompleted / totalChunks) * 100);
                        chunkBar.style.width = chunkPct + '%';
                        chunkPercent.textContent = chunkPct + '%';

                        const apiUrl = `/audiobooks/${audioBookId}/chapters/${chapterId}/chunks/${chunk.id}/generate`;
                        addLog(
                            `⏳ Chương ${chapterNum} - Đoạn ${chunk.chunk_number}: Đang tạo audio... (chunkId=${chunk.id})`
                        );

                        try {
                            const genResponse = await fetch(apiUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify(ttsSettings)
                            });

                            // Read JSON body even on error responses to get detailed error message
                            let genResult;
                            try {
                                genResult = await genResponse.json();
                            } catch (jsonErr) {
                                throw new Error(`HTTP ${genResponse.status}: ${genResponse.statusText}`);
                            }

                            if (genResult.success) {
                                chunkCompleted++;
                                totalChunksGenerated++;
                                addLog(`✅ Chương ${chapterNum} - Đoạn ${chunk.chunk_number}: Hoàn tất ${genResult.skipped ? '(đã có)' : ''}`,
                                    'success');
                                addChunkCard(chapterNum, chunk.chunk_number, genResult.audio_url, genResult.duration);
                            } else {
                                const errorMsg = genResult.error || `HTTP ${genResponse.status}`;
                                // Detect quota exceeded for clearer message
                                const isQuota = errorMsg.toLowerCase().includes('quota') || errorMsg.toLowerCase()
                                    .includes('exceeded');
                                const displayMsg = isQuota ?
                                    `⚠️ HẾT QUOTA: ${errorMsg}` :
                                    errorMsg;
                                addLog(`❌ Chương ${chapterNum} - Đoạn ${chunk.chunk_number}: ${displayMsg}`,
                                    'error');
                                errors.push(`C${chapterNum}-${chunk.chunk_number}: ${displayMsg}`);

                                // If quota exceeded, stop generating remaining chunks to avoid wasting requests
                                if (isQuota) {
                                    addLog(`🛑 Dừng tạo TTS: Provider đã hết quota. Vui lòng nạp thêm hoặc đổi provider.`,
                                        'error');
                                    throw {
                                        quotaExceeded: true,
                                        message: errorMsg
                                    };
                                }
                            }
                        } catch (fetchError) {
                            if (fetchError.quotaExceeded)
                                throw fetchError; // Re-throw quota error to break chapter loop
                            addLog(`❌ Chương ${chapterNum} - Đoạn ${chunk.chunk_number}: Lỗi mạng - ${fetchError.message}`,
                                'error');
                            errors.push(`C${chapterNum}-${chunk.chunk_number}: ${fetchError.message}`);
                            console.error('Fetch error:', fetchError, 'URL:', apiUrl);
                        }

                        // Update chunk progress
                        const newChunkPct = Math.round((chunkCompleted / totalChunks) * 100);
                        chunkBar.style.width = newChunkPct + '%';
                        chunkPercent.textContent = newChunkPct + '%';
                    }

                    // Step 3: Merge chapter if all chunks completed
                    if (chunkCompleted === totalChunks) {
                        addLog(`🔀 Chương ${chapterNum}: Đang ghép file full...`);

                        const mergeResponse = await fetch(
                            `/audiobooks/${audioBookId}/chapters/${chapterId}/merge-audio`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({})
                            });
                        const mergeResult = await safeJson(mergeResponse);

                        if (mergeResult.success) {
                            addLog(`🎧 Chương ${chapterNum}: Đã tạo file FULL!`, 'success');
                            addChunkCard(chapterNum, 'full', mergeResult.audio_url, mergeResult.duration, true);
                        } else {
                            addLog(`⚠️ Chương ${chapterNum}: Không thể ghép file full - ${mergeResult.error}`, 'warn');
                        }
                    }

                    chunkStatus.textContent = `Chương ${chapterNum}: Hoàn tất ${chunkCompleted}/${totalChunks}`;
                    chunkBar.style.width = '100%';
                    chunkPercent.textContent = '100%';

                } catch (error) {
                    if (error.quotaExceeded) {
                        errors.push(`HẾT QUOTA TTS: ${error.message}`);
                        addLog(`🛑 HẾT QUOTA TTS - Dừng tất cả. Vui lòng nạp thêm quota hoặc đổi sang provider khác (ví dụ: Microsoft Edge-TTS miễn phí).`,
                            'error');
                        break; // Stop all remaining chapters
                    }
                    errors.push(`Chương ${chapterNum}: ${error.message}`);
                    addLog(`❌ Chương ${chapterNum}: ${error.message}`, 'error');
                }

                completedChapters++;
                const overallPct = Math.round((completedChapters / totalChapters) * 100);
                progressBar.style.width = overallPct + '%';
                progressPercent.textContent = overallPct + '%';
            }

            // Final status
            if (errors.length > 0) {
                progressStatus.textContent = `⚠️ Hoàn tất với ${errors.length} lỗi`;
                progressContainer.classList.remove('bg-blue-50', 'border-blue-200');
                progressContainer.classList.add('bg-yellow-50', 'border-yellow-200');
                progressStatus.classList.remove('text-blue-800');
                progressStatus.classList.add('text-yellow-800');
                addLog(`⚠️ Hoàn tất: ${totalChunksGenerated} mới, ${totalChunksSkipped} đã có, ${errors.length} lỗi`,
                    'warn');
            } else {
                progressStatus.textContent = `✅ Hoàn tất! ${totalChunksGenerated} mới, ${totalChunksSkipped} đã có`;
                progressContainer.classList.remove('bg-blue-50', 'border-blue-200');
                progressContainer.classList.add('bg-green-50', 'border-green-200');
                progressStatus.classList.remove('text-blue-800');
                progressStatus.classList.add('text-green-800');
                addLog(`🎉 Hoàn tất! Tổng: ${totalChunksGenerated} mới + ${totalChunksSkipped} đã có`, 'success');
            }

            // Remove beforeunload handler
            isGenerating = false;
            window.removeEventListener('beforeunload', beforeUnloadHandler);

            generateBtn.disabled = false;
            generateBtn.innerHTML = '🎙️ Tạo TTS (<span id="selectedCount">' + selectedCheckboxes.length + '</span>)';

            // Offer to reload
            addLog('💡 Nhấn F5 hoặc reload để cập nhật danh sách');
        }

        // Delete chapter audio files
        async function deleteChapterAudio(chapterId, deleteAll = true) {
            const msg = deleteAll ?
                'Xóa TẤT CẢ audio của chương này (chunks + full)?' :
                'Chỉ xóa file FULL của chương này?';
            if (!confirm(msg)) return;

            try {
                const response = await fetch(`/audiobooks/${audioBookId}/chapters/${chapterId}/delete-audio`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        delete_chunks: deleteAll,
                        delete_merged: true
                    })
                });

                const result = await safeJson(response);

                if (result.success) {
                    alert(`Đã xóa ${result.count} file audio`);
                    window.location.reload();
                } else {
                    throw new Error(result.error);
                }
            } catch (error) {
                alert('Lỗi: ' + error.message);
            }
        }

        // Delete audio for a single chunk
        async function deleteChunkAudio(bookId, chapterId, chunkId, btnEl) {
            if (!confirm('Xóa audio của đoạn này?')) return;

            const originalHtml = btnEl.innerHTML;
            btnEl.disabled = true;
            btnEl.innerHTML = '⏳';

            try {
                const response = await fetch(
                    `/audiobooks/${bookId}/chapters/${chapterId}/chunks/${chunkId}/delete-audio`, {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    });

                const result = await safeJson(response);

                if (result.success) {
                    // Update the chunk card UI without reloading
                    const chunkCard = btnEl.closest('.flex.items-center.justify-between');
                    if (chunkCard) {
                        // Change styling to pending (gray)
                        chunkCard.className =
                            'flex items-center justify-between p-2 rounded text-sm bg-gray-50 border border-gray-200';

                        // Update status icon & text
                        const statusSpan = chunkCard.querySelector('.text-xs.font-medium');
                        if (statusSpan) {
                            statusSpan.className = 'text-xs font-medium text-gray-600';
                            statusSpan.innerHTML = '⏸️ Đoạn ' + result.chunk_number;
                        }

                        // Remove duration
                        const durationSpan = chunkCard.querySelector('.text-xs.text-gray-500');
                        if (durationSpan) durationSpan.remove();

                        // Remove audio player & delete button
                        const audioContainer = chunkCard.querySelector('.flex.items-center.gap-1');
                        if (audioContainer) audioContainer.innerHTML = '';
                    }
                } else {
                    throw new Error(result.error);
                }
            } catch (error) {
                alert('Lỗi: ' + error.message);
                btnEl.disabled = false;
                btnEl.innerHTML = originalHtml;
            }
        }

        // ========== ADD TEXT OVERLAY MODAL ==========
        let selectedAddTextFilename = '';

        window.openAddTextModal = function(filename, imageUrl) {
            selectedAddTextFilename = filename;
            document.getElementById('addTextPreviewImage').src = imageUrl;
            document.getElementById('addTextFilename').value = filename;
            document.getElementById('addTextModal').classList.remove('hidden');
            document.getElementById('addTextStatus').innerHTML = '';
        };

        function closeAddTextModal() {
            document.getElementById('addTextModal').classList.add('hidden');
            selectedAddTextFilename = '';
        }

        // Sync color pickers for Add Text Modal
        function syncAddTextColorInputs(colorId, hexId) {
            const colorInput = document.getElementById(colorId);
            const hexInput = document.getElementById(hexId);
            if (colorInput && hexInput) {
                colorInput.addEventListener('input', () => hexInput.value = colorInput.value);
                hexInput.addEventListener('input', () => {
                    if (/^#[0-9A-Fa-f]{6}$/.test(hexInput.value)) {
                        colorInput.value = hexInput.value;
                    }
                });
            }
        }
        syncAddTextColorInputs('addTextColor', 'addTextColorHex');
        syncAddTextColorInputs('addTextBorderColor', 'addTextBorderColorHex');
        syncAddTextColorInputs('addTextBgColor', 'addTextBgColorHex');

        // Show/hide background color section for Add Text Modal
        document.querySelectorAll('input[name="addTextBgStyle"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const bgColorSection = document.getElementById('addTextBgColorSection');
                if (this.value === 'solid' || this.value === 'gradient') {
                    bgColorSection.classList.remove('hidden');
                } else {
                    bgColorSection.classList.add('hidden');
                }
            });
        });

        // Background opacity slider for Add Text
        document.getElementById('addTextBgOpacity')?.addEventListener('input', function() {
            document.getElementById('addTextBgOpacityValue').textContent = this.value + '%';
        });

        // Font size slider for Add Text
        document.getElementById('addTextFontSize')?.addEventListener('input', function() {
            document.getElementById('addTextFontSizeValue').textContent = this.value + 'px';
        });

        // Presets for Add Text Modal
        document.querySelectorAll('.add-text-preset-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const preset = this.dataset.preset;
                const presets = {
                    'classic': {
                        textColor: '#ffffff',
                        borderColor: '#000000',
                        borderWidth: '4',
                        bgStyle: 'none',
                        fontSize: 60
                    },
                    'fire': {
                        textColor: '#ffff00',
                        borderColor: '#ff0000',
                        borderWidth: '6',
                        bgStyle: 'none',
                        fontSize: 70
                    },
                    'neon': {
                        textColor: '#00ffff',
                        borderColor: '#8b00ff',
                        borderWidth: '4',
                        bgStyle: 'blur',
                        fontSize: 65
                    },
                    'nature': {
                        textColor: '#ffffff',
                        borderColor: '#228b22',
                        borderWidth: '4',
                        bgStyle: 'solid',
                        bgColor: '#000000',
                        bgOpacity: 50,
                        fontSize: 60
                    },
                    'vintage': {
                        textColor: '#8b4513',
                        borderColor: '#daa520',
                        borderWidth: '3',
                        bgStyle: 'solid',
                        bgColor: '#f5deb3',
                        bgOpacity: 80,
                        fontSize: 55
                    }
                };
                const p = presets[preset];
                if (p) {
                    document.getElementById('addTextColor').value = p.textColor;
                    document.getElementById('addTextColorHex').value = p.textColor;
                    document.getElementById('addTextBorderColor').value = p.borderColor;
                    document.getElementById('addTextBorderColorHex').value = p.borderColor;
                    document.getElementById('addTextBorderWidth').value = p.borderWidth;
                    document.querySelector(`input[name="addTextBgStyle"][value="${p.bgStyle}"]`).checked =
                        true;
                    document.getElementById('addTextFontSize').value = p.fontSize;
                    document.getElementById('addTextFontSizeValue').textContent = p.fontSize + 'px';

                    const bgColorSection = document.getElementById('addTextBgColorSection');
                    if (p.bgStyle === 'solid' || p.bgStyle === 'gradient') {
                        bgColorSection.classList.remove('hidden');
                        if (p.bgColor) {
                            document.getElementById('addTextBgColor').value = p.bgColor;
                            document.getElementById('addTextBgColorHex').value = p.bgColor;
                        }
                        if (p.bgOpacity !== undefined) {
                            document.getElementById('addTextBgOpacity').value = p.bgOpacity;
                            document.getElementById('addTextBgOpacityValue').textContent = p.bgOpacity +
                                '%';
                        }
                    } else {
                        bgColorSection.classList.add('hidden');
                    }
                }
            });
        });

        // Apply text overlay to image
        async function applyTextOverlay() {
            const btn = document.getElementById('applyTextOverlayBtn');
            const statusDiv = document.getElementById('addTextStatus');
            const filename = document.getElementById('addTextFilename').value;

            if (!filename) {
                statusDiv.innerHTML = '<span class="text-red-600">❌ Không tìm thấy hình ảnh</span>';
                return;
            }

            const textElements = {
                title: document.getElementById('addTextTitle').value.trim(),
                author: document.getElementById('addTextAuthor').value.trim(),
                chapter: document.getElementById('addTextChapter').value.trim()
            };

            const styling = {
                position: document.querySelector('input[name="addTextPosition"]:checked')?.value || 'bottom',
                textColor: document.getElementById('addTextColorHex').value || '#ffffff',
                borderColor: document.getElementById('addTextBorderColorHex').value || '#000000',
                borderWidth: parseInt(document.getElementById('addTextBorderWidth').value || '4'),
                bgStyle: document.querySelector('input[name="addTextBgStyle"]:checked')?.value || 'none',
                bgColor: document.getElementById('addTextBgColorHex').value || '#000000',
                bgOpacity: parseInt(document.getElementById('addTextBgOpacity').value || '70'),
                titleFontSize: parseInt(document.getElementById('addTextFontSize').value || '60')
            };

            btn.disabled = true;
            btn.innerHTML = '⏳ Đang tạo...';
            statusDiv.innerHTML = '<span class="text-blue-600">🎨 Đang thêm text overlay...</span>';

            try {
                const response = await fetch(`/audiobooks/${audioBookId}/media/add-text-overlay`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        source_image: filename,
                        text_elements: textElements,
                        styling: styling
                    })
                });

                const result = await safeJson(response);

                if (result.success) {
                    statusDiv.innerHTML = '<span class="text-green-600">✅ Đã tạo thumbnail thành công!</span>';
                    refreshMediaGallery();
                    setTimeout(() => closeAddTextModal(), 1500);
                } else {
                    throw new Error(result.error || 'Không thể tạo thumbnail');
                }
            } catch (error) {
                statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '✨ Tạo Thumbnail với Text';
            }
        }

        // ========== CHAPTER COVER GENERATION ==========
        let selectedCoverImageFilename = '';

        window.openChapterCoverModal = async function(filename, imageUrl) {
            selectedCoverImageFilename = filename;
            document.getElementById('selectedCoverImage').src = imageUrl;
            document.getElementById('selectedCoverFilename').textContent = filename;
            document.getElementById('chapterCoverModal').classList.remove('hidden');
            document.getElementById('chapterCoverStatus').innerHTML = '';
            document.getElementById('chapterCoverProgress').classList.add('hidden');

            // Load chapters list
            await loadChaptersForCover();
        };

        function closeChapterCoverModal() {
            document.getElementById('chapterCoverModal').classList.add('hidden');
            selectedCoverImageFilename = '';
        }

        async function loadChaptersForCover() {
            const listDiv = document.getElementById('chapterCoverList');
            listDiv.innerHTML = '<div class="text-center py-4 text-gray-500">Đang tải danh sách chương...</div>';

            try {
                const response = await fetch(`/audiobooks/{{ $audioBook->id }}/chapters-for-cover`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                if (!result.success || !result.chapters.length) {
                    listDiv.innerHTML = '<div class="text-center py-4 text-gray-500">Không có chương nào</div>';
                    return;
                }

                listDiv.innerHTML = result.chapters.map(ch => `
                    <label class="flex items-center gap-3 p-2 hover:bg-gray-50 rounded cursor-pointer">
                        <input type="checkbox" class="chapter-cover-checkbox rounded" value="${ch.id}" data-chapter="${ch.chapter_number}">
                        <div class="w-7 h-7 bg-purple-600 text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0">
                            ${ch.chapter_number}
                        </div>
                        <div class="flex-1 truncate">
                            <span class="text-gray-800">${ch.title || 'Chưa có tiêu đề'}</span>
                        </div>
                        ${ch.has_cover ? `
                                                                                                                                                                <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded">Đã có bìa</span>
                                                                                                                                                            ` : `
                                                                                                                                                                <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Chưa có bìa</span>
                                                                                                                                                            `}
                    </label>
                `).join('');

            } catch (error) {
                listDiv.innerHTML = `<div class="text-center py-4 text-red-500">Lỗi: ${error.message}</div>`;
            }
        }

        function toggleAllChaptersCover() {
            const selectAll = document.getElementById('selectAllChaptersCover').checked;
            document.querySelectorAll('.chapter-cover-checkbox').forEach(cb => cb.checked = selectAll);
        }

        // ========== TEXT POSITION SELECTION ==========
        function selectTextPosition(event) {
            const img = event.currentTarget;
            const rect = img.getBoundingClientRect();

            // Calculate click position relative to image (0-100%)
            const x = ((event.clientX - rect.left) / rect.width) * 100;
            const y = ((event.clientY - rect.top) / rect.height) * 100;

            // Update hidden inputs
            document.getElementById('textPositionX').value = x.toFixed(2);
            document.getElementById('textPositionY').value = y.toFixed(2);

            // Show marker at clicked position
            const marker = document.getElementById('textPositionMarker');
            marker.classList.remove('hidden');
            marker.style.left = x + '%';
            marker.style.top = y + '%';

            // Update preview text position
            updateChapterTextPreview();
        }

        function updateChapterTextPreview() {
            const fontSize = document.getElementById('chapterFontSize').value;
            const textColor = document.querySelector('input[name="chapterTextColor"]:checked').value;
            const outlineColor = document.querySelector('input[name="chapterOutlineColor"]:checked').value;
            const outlineWidth = document.getElementById('chapterOutlineWidth').value;
            const posX = document.getElementById('textPositionX').value;
            const posY = document.getElementById('textPositionY').value;

            // Update displays
            document.getElementById('fontSizeDisplay').textContent = fontSize;
            document.getElementById('outlineWidthDisplay').textContent = outlineWidth;

            // Update preview badge
            const preview = document.getElementById('textLivePreview');
            const badge = document.getElementById('previewChapterBadge');

            preview.classList.remove('hidden');
            preview.style.left = posX + '%';
            preview.style.top = posY + '%';

            // Apply styling to preview badge
            const scaleFactor = fontSize / 80; // Base size is 80
            badge.style.fontSize = (14 * scaleFactor) + 'px';
            badge.style.color = textColor;
            badge.style.backgroundColor = outlineColor;
            badge.style.borderWidth = (outlineWidth / 2) + 'px';
            badge.style.borderColor = outlineColor;

            // Add text shadow for better visibility
            const shadowColor = textColor === '#FFFFFF' ? 'rgba(0,0,0,0.3)' : 'rgba(255,255,255,0.3)';
            badge.style.textShadow = `0 2px 4px ${shadowColor}`;
        }

        async function generateChapterCovers() {
            const checkboxes = document.querySelectorAll('.chapter-cover-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Vui lòng chọn ít nhất 1 chương');
                return;
            }

            const chapterIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
            const btn = document.getElementById('generateChapterCoversBtn');
            const statusDiv = document.getElementById('chapterCoverStatus');
            const progressDiv = document.getElementById('chapterCoverProgress');
            const progressBar = document.getElementById('chapterCoverProgressBar');
            const progressText = document.getElementById('chapterCoverProgressText');
            const progressPercent = document.getElementById('chapterCoverProgressPercent');

            // Get text formatting options
            const fontSize = parseInt(document.getElementById('chapterFontSize').value);
            const textColor = document.querySelector('input[name="chapterTextColor"]:checked').value;
            const outlineColor = document.querySelector('input[name="chapterOutlineColor"]:checked').value;
            const outlineWidth = parseInt(document.getElementById('chapterOutlineWidth').value);
            const posX = parseFloat(document.getElementById('textPositionX').value);
            const posY = parseFloat(document.getElementById('textPositionY').value);

            btn.disabled = true;
            btn.innerHTML = '⏳ Đang tạo...';
            progressDiv.classList.remove('hidden');
            progressBar.style.width = '10%';
            progressText.textContent = `Đang tạo ảnh bìa cho ${chapterIds.length} chương...`;
            progressPercent.textContent = '10%';
            statusDiv.innerHTML = '<span class="text-blue-600">🎨 Đang xử lý với FFmpeg...</span>';

            try {
                const response = await fetch(`/audiobooks/{{ $audioBook->id }}/generate-chapter-covers`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        image_filename: selectedCoverImageFilename,
                        chapter_ids: chapterIds,
                        text_options: {
                            font_size: fontSize,
                            text_color: textColor,
                            outline_color: outlineColor,
                            outline_width: outlineWidth,
                            position_x: posX,
                            position_y: posY
                        }
                    })
                });

                const result = await safeJson(response);

                if (result.success) {
                    progressBar.style.width = '100%';
                    progressPercent.textContent = '100%';
                    progressText.textContent = result.message;

                    const successResults = result.results.filter(r => r.success);
                    const failedResults = result.results.filter(r => !r.success);

                    let statusHtml = `<span class="text-green-600">✅ ${result.message}</span>`;

                    if (failedResults.length > 0) {
                        statusHtml +=
                            '<br><span class="text-red-600">❌ Lỗi:</span><ul class="text-xs text-red-600 ml-4">';
                        failedResults.forEach(r => {
                            statusHtml += `<li>Chương ${r.chapter_number}: ${r.error}</li>`;
                        });
                        statusHtml += '</ul>';
                    }

                    statusDiv.innerHTML = statusHtml;

                    // Update chapter cover images in main list immediately
                    successResults.forEach(r => {
                        const chapterDiv = document.getElementById(`chapter-${r.chapter_id}`);
                        if (chapterDiv) {
                            // Find the image or placeholder container
                            const existingImg = chapterDiv.querySelector('img[alt]');
                            const placeholder = chapterDiv.querySelector('.w-20.h-12.bg-gray-100');

                            if (existingImg) {
                                // Update existing image with cache buster
                                existingImg.src = r.cover_image + '?t=' + Date.now();
                            } else if (placeholder) {
                                // Replace placeholder with new image
                                const newImg = document.createElement('img');
                                newImg.src = r.cover_image + '?t=' + Date.now();
                                newImg.alt = `Chương ${r.chapter_number}`;
                                newImg.className =
                                    'w-20 h-12 object-cover rounded cursor-pointer hover:opacity-80 transition border shadow-sm';
                                newImg.onclick = function() {
                                    openImagePreview(r.cover_image);
                                };
                                newImg.title = 'Click để xem lớn';
                                placeholder.replaceWith(newImg);
                            }
                        }
                    });

                    // Reload chapters list in modal to show updated status
                    setTimeout(() => loadChaptersForCover(), 1000);

                } else {
                    throw new Error(result.error || 'Không thể tạo ảnh bìa');
                }

            } catch (error) {
                statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                progressDiv.classList.add('hidden');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '🎨 Tạo ảnh bìa cho chương đã chọn';
            }
        }

        // ==========================================================
        // ===== DESCRIPTION VIDEO PIPELINE (Chunked) ===============
        // ==========================================================
        (function() {
            let descChunks = [];
            const baseUrl = `/audiobooks/${audioBookId}/desc-video`;

            // DOM refs
            const chunkBtn = document.getElementById('descChunkBtn');
            const chunkStatus = document.getElementById('descChunkStatus');
            const chunksList = document.getElementById('descChunksList');
            const chunksItems = document.getElementById('descChunksItems');
            const chunksCount = document.getElementById('descChunksCount');
            const genImagesBtn = document.getElementById('descGenImagesBtn');
            const genImagesStatus = document.getElementById('descGenImagesStatus');
            const genTtsBtn = document.getElementById('descGenTtsBtn');
            const genTtsStatus = document.getElementById('descGenTtsStatus');
            const genSrtBtn = document.getElementById('descGenSrtBtn');
            const genSrtStatus = document.getElementById('descGenSrtStatus');
            const composeBtn = document.getElementById('descComposeBtn');
            const composeStatus = document.getElementById('descComposeStatus');
            const progressWrap = document.getElementById('descPipelineProgress');
            const progressText = document.getElementById('descPipelineProgressText');
            const progressPct = document.getElementById('descPipelineProgressPercent');
            const progressBar = document.getElementById('descPipelineProgressBar');
            const resultContainer = document.getElementById('descVideoResultContainer');
            const videoPlayer = document.getElementById('descVideoPlayer2');
            const downloadBtn = document.getElementById('descVideoDownloadBtn');
            const durationEl = document.getElementById('descVideoDuration2');

            if (!chunkBtn) return;

            // Helpers
            function setProgress(text, pct) {
                progressWrap.classList.remove('hidden');
                progressText.textContent = text;
                progressPct.textContent = pct + '%';
                progressBar.style.width = pct + '%';
            }

            function hideProgress() {
                progressWrap.classList.add('hidden');
            }

            function getTtsSettings() {
                return {
                    provider: document.getElementById('ttsProviderSelect')?.value || 'openai',
                    voice_name: document.getElementById('voiceNameSelect')?.value || '',
                    voice_gender: document.querySelector('input[name="voiceGender"]:checked')?.value || 'female',
                    style_instruction: document.getElementById('ttsStyleInstruction')?.value || ''
                };
            }

            function renderChunks() {
                if (!descChunks.length) {
                    chunksList.classList.add('hidden');
                    return;
                }
                chunksList.classList.remove('hidden');
                chunksCount.textContent = `${descChunks.length} chunks`;
                chunksItems.innerHTML = '';

                descChunks.forEach((chunk, idx) => {
                    const hasImg = !!chunk.image_path;
                    const hasAudio = !!chunk.audio_path;
                    const hasSrt = !!chunk.srt_path;
                    const duration = chunk.audio_duration ? `${parseFloat(chunk.audio_duration).toFixed(1)}s` :
                        '';

                    const div = document.createElement('div');
                    div.className = 'p-3 bg-gray-50 border border-gray-200 rounded-lg';
                    div.innerHTML = `
                        <div class="flex items-start gap-2">
                            <span class="bg-gray-600 text-white text-xs font-bold w-5 h-5 rounded-full flex items-center justify-center mt-1 flex-shrink-0">${idx}</span>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs text-gray-700 mb-1 line-clamp-3">${escapeHtml(chunk.text)}</div>
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="text-xs ${hasImg ? 'text-green-600' : 'text-gray-400'}">${hasImg ? '✅' : '⬜'} Ảnh</span>
                                    <span class="text-xs ${hasAudio ? 'text-green-600' : 'text-gray-400'}">${hasAudio ? '✅' : '⬜'} Audio ${duration}</span>
                                    <span class="text-xs ${hasSrt ? 'text-green-600' : 'text-gray-400'}">${hasSrt ? '✅' : '⬜'} SRT</span>
                                </div>
                                <div class="mb-1">
                                    <label class="text-xs text-gray-500">Prompt ảnh:</label>
                                    <textarea class="w-full text-xs border border-gray-300 rounded p-1 descChunkPrompt" data-index="${idx}" rows="2">${escapeHtml(chunk.image_prompt || '')}</textarea>
                                </div>
                                <div class="flex gap-1">
                                    <button type="button" class="descOneImageBtn bg-blue-100 hover:bg-blue-200 text-blue-700 text-xs px-2 py-1 rounded" data-index="${idx}">🎨 Ảnh</button>
                                    <button type="button" class="descOneTtsBtn bg-purple-100 hover:bg-purple-200 text-purple-700 text-xs px-2 py-1 rounded" data-index="${idx}">🎙️ TTS</button>
                                    <button type="button" class="descOneSrtBtn bg-amber-100 hover:bg-amber-200 text-amber-700 text-xs px-2 py-1 rounded" data-index="${idx}">📝 SRT</button>
                                </div>
                                ${hasImg ? `<img src="/storage/books/${audioBookId}/description_video/images/chunk_${idx}.png?t=${Date.now()}" class="mt-2 rounded max-h-32 border" alt="chunk ${idx}">` : ''}
                                ${hasAudio ? `<audio controls class="mt-1 w-full h-8" src="/storage/books/${audioBookId}/description_video/audio/chunk_${idx}.mp3?t=${Date.now()}"></audio>` : ''}
                            </div>
                        </div>
                    `;
                    chunksItems.appendChild(div);
                });

                // Show/hide step buttons based on state
                genImagesBtn.classList.remove('hidden');
                genTtsBtn.classList.remove('hidden');
                genSrtBtn.classList.remove('hidden');
                composeBtn.classList.remove('hidden');

                // Attach per-chunk handlers
                document.querySelectorAll('.descOneImageBtn').forEach(btn => {
                    btn.addEventListener('click', () => generateOneImage(parseInt(btn.dataset.index)));
                });
                document.querySelectorAll('.descOneTtsBtn').forEach(btn => {
                    btn.addEventListener('click', () => generateOneTts(parseInt(btn.dataset.index)));
                });
                document.querySelectorAll('.descOneSrtBtn').forEach(btn => {
                    btn.addEventListener('click', () => generateOneSrt(parseInt(btn.dataset.index)));
                });
            }

            function escapeHtml(text) {
                const d = document.createElement('div');
                d.textContent = text || '';
                return d.innerHTML;
            }

            function getEditedPrompt(idx) {
                const ta = document.querySelector(`.descChunkPrompt[data-index="${idx}"]`);
                return ta ? ta.value : (descChunks[idx]?.image_prompt || '');
            }

            // ---- STEP 1: Chunk Description ----
            chunkBtn.addEventListener('click', async () => {
                chunkBtn.disabled = true;
                chunkStatus.innerHTML =
                    '<span class="text-blue-600">⏳ AI đang phân tích và chia đoạn...</span>';
                setProgress('AI Phân tích & Chia đoạn...', 10);

                try {
                    const resp = await fetch(`${baseUrl}/chunk`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                .content,
                            'Accept': 'application/json'
                        }
                    });
                    const result = await safeJson(resp);
                    if (result.error) throw new Error(result.error);

                    descChunks = result.chunks || [];
                    chunkStatus.innerHTML =
                        `<span class="text-green-600">✅ Đã chia thành ${descChunks.length} chunks</span>`;
                    renderChunks();
                } catch (e) {
                    chunkStatus.innerHTML = `<span class="text-red-600">❌ ${e.message}</span>`;
                } finally {
                    chunkBtn.disabled = false;
                    hideProgress();
                }
            });

            // ---- STEP 2: Generate one image ----
            async function generateOneImage(idx) {
                const prompt = getEditedPrompt(idx);
                if (!prompt) {
                    alert('Chưa có prompt ảnh!');
                    return;
                }

                const btn = document.querySelector(`.descOneImageBtn[data-index="${idx}"]`);
                btn.disabled = true;
                btn.textContent = '⏳...';

                try {
                    const resp = await fetch(`${baseUrl}/generate-image`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            chunk_index: idx,
                            prompt: prompt
                        })
                    });
                    const result = await safeJson(resp);
                    if (result.error) throw new Error(result.error);

                    descChunks[idx].image_path = result.image_path;
                    renderChunks();
                } catch (e) {
                    alert(`Lỗi tạo ảnh chunk ${idx}: ${e.message}`);
                } finally {
                    btn.disabled = false;
                    btn.textContent = '🎨 Ảnh';
                }
            }

            // ---- Generate ALL images ----
            genImagesBtn.addEventListener('click', async () => {
                genImagesBtn.disabled = true;
                let done = 0;
                const total = descChunks.length;

                for (let i = 0; i < total; i++) {
                    if (descChunks[i].image_path) {
                        done++;
                        continue;
                    }
                    setProgress(`Tạo ảnh chunk ${i}/${total}...`, Math.round((done / total) * 100));
                    genImagesStatus.innerHTML =
                        `<span class="text-blue-600">⏳ Tạo ảnh ${i + 1}/${total}...</span>`;

                    try {
                        const prompt = getEditedPrompt(i);
                        const resp = await fetch(`${baseUrl}/generate-image`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                    .content,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                chunk_index: i,
                                prompt: prompt
                            })
                        });
                        const result = await safeJson(resp);
                        if (result.error) throw new Error(result.error);
                        descChunks[i].image_path = result.image_path;
                    } catch (e) {
                        genImagesStatus.innerHTML =
                            `<span class="text-red-600">❌ Lỗi chunk ${i}: ${e.message}</span>`;
                    }
                    done++;
                }

                genImagesStatus.innerHTML =
                    `<span class="text-green-600">✅ Hoàn tất tạo ${done} ảnh</span>`;
                renderChunks();
                genImagesBtn.disabled = false;
                hideProgress();
            });

            // ---- STEP 3: Generate one TTS ----
            async function generateOneTts(idx) {
                const btn = document.querySelector(`.descOneTtsBtn[data-index="${idx}"]`);
                btn.disabled = true;
                btn.textContent = '⏳...';

                const tts = getTtsSettings();
                try {
                    const resp = await fetch(`${baseUrl}/generate-tts`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            chunk_index: idx,
                            text: descChunks[idx].text,
                            provider: tts.provider,
                            voice_name: tts.voice_name,
                            voice_gender: tts.voice_gender,
                            style_instruction: tts.style_instruction
                        })
                    });
                    const result = await safeJson(resp);
                    if (result.error) throw new Error(result.error);

                    descChunks[idx].audio_path = result.audio_path;
                    descChunks[idx].audio_duration = result.duration;
                    renderChunks();
                } catch (e) {
                    alert(`Lỗi TTS chunk ${idx}: ${e.message}`);
                } finally {
                    btn.disabled = false;
                    btn.textContent = '🎙️ TTS';
                }
            }

            // ---- Generate ALL TTS ----
            genTtsBtn.addEventListener('click', async () => {
                genTtsBtn.disabled = true;
                let done = 0;
                const total = descChunks.length;
                const tts = getTtsSettings();

                for (let i = 0; i < total; i++) {
                    if (descChunks[i].audio_path) {
                        done++;
                        continue;
                    }
                    setProgress(`Tạo TTS chunk ${i}/${total}...`, Math.round((done / total) * 100));
                    genTtsStatus.innerHTML =
                        `<span class="text-blue-600">⏳ Tạo audio ${i + 1}/${total}...</span>`;

                    try {
                        const resp = await fetch(`${baseUrl}/generate-tts`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                    .content,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                chunk_index: i,
                                text: descChunks[i].text,
                                provider: tts.provider,
                                voice_name: tts.voice_name,
                                voice_gender: tts.voice_gender,
                                style_instruction: tts.style_instruction
                            })
                        });
                        const result = await safeJson(resp);
                        if (result.error) throw new Error(result.error);
                        descChunks[i].audio_path = result.audio_path;
                        descChunks[i].audio_duration = result.duration;
                    } catch (e) {
                        genTtsStatus.innerHTML =
                            `<span class="text-red-600">❌ Lỗi chunk ${i}: ${e.message}</span>`;
                    }
                    done++;
                }

                genTtsStatus.innerHTML = `<span class="text-green-600">✅ Hoàn tất tạo ${done} audio</span>`;
                renderChunks();
                genTtsBtn.disabled = false;
                hideProgress();
            });

            // ---- STEP 4: Generate one SRT ----
            async function generateOneSrt(idx) {
                if (!descChunks[idx].audio_path) {
                    alert('Chưa có audio cho chunk này! Cần tạo TTS trước.');
                    return;
                }

                const btn = document.querySelector(`.descOneSrtBtn[data-index="${idx}"]`);
                btn.disabled = true;
                btn.textContent = '⏳...';

                try {
                    const resp = await fetch(`${baseUrl}/generate-srt`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            chunk_index: idx
                        })
                    });
                    const result = await safeJson(resp);
                    if (result.error) throw new Error(result.error);

                    descChunks[idx].srt_path = result.srt_path;
                    renderChunks();
                } catch (e) {
                    alert(`Lỗi SRT chunk ${idx}: ${e.message}`);
                } finally {
                    btn.disabled = false;
                    btn.textContent = '📝 SRT';
                }
            }

            // ---- Generate ALL SRT ----
            genSrtBtn.addEventListener('click', async () => {
                genSrtBtn.disabled = true;
                let done = 0;
                const total = descChunks.length;

                for (let i = 0; i < total; i++) {
                    if (descChunks[i].srt_path) {
                        done++;
                        continue;
                    }
                    if (!descChunks[i].audio_path) {
                        genSrtStatus.innerHTML =
                            `<span class="text-yellow-600">⚠️ Chunk ${i} chưa có audio, bỏ qua</span>`;
                        done++;
                        continue;
                    }
                    setProgress(`Tạo SRT chunk ${i}/${total}...`, Math.round((done / total) * 100));
                    genSrtStatus.innerHTML =
                        `<span class="text-blue-600">⏳ Tạo SRT ${i + 1}/${total}...</span>`;

                    try {
                        const resp = await fetch(`${baseUrl}/generate-srt`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                    .content,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                chunk_index: i
                            })
                        });
                        const result = await safeJson(resp);
                        if (result.error) throw new Error(result.error);
                        descChunks[i].srt_path = result.srt_path;
                    } catch (e) {
                        genSrtStatus.innerHTML =
                            `<span class="text-red-600">❌ Lỗi chunk ${i}: ${e.message}</span>`;
                    }
                    done++;
                }

                genSrtStatus.innerHTML = `<span class="text-green-600">✅ Hoàn tất tạo ${done} SRT</span>`;
                renderChunks();
                genSrtBtn.disabled = false;
                hideProgress();
            });

            // ---- STEP 5: Compose Final Video ----
            composeBtn.addEventListener('click', async () => {
                composeBtn.disabled = true;
                composeStatus.innerHTML =
                    '<span class="text-blue-600">⏳ Đang ghép video (Ken Burns + transitions + music + subtitles)... Có thể mất vài phút.</span>';
                setProgress('Ghép video hoàn chỉnh...', 50);

                try {
                    const resp = await fetch(`${baseUrl}/compose`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                .content,
                            'Accept': 'application/json'
                        }
                    });
                    const result = await safeJson(resp);
                    if (result.error) throw new Error(result.error);

                    composeStatus.innerHTML = '<span class="text-green-600">✅ Video hoàn chỉnh!</span>';
                    setProgress('Hoàn tất!', 100);

                    // Show video player
                    resultContainer.classList.remove('hidden');
                    videoPlayer.src = result.video_url + '?t=' + Date.now();
                    downloadBtn.href = result.video_url;
                    if (result.duration) {
                        durationEl.textContent = `(${parseFloat(result.duration).toFixed(1)}s)`;
                    }
                } catch (e) {
                    composeStatus.innerHTML = `<span class="text-red-600">❌ ${e.message}</span>`;
                } finally {
                    composeBtn.disabled = false;
                    setTimeout(hideProgress, 3000);
                }
            });

            // ---- Load existing chunks on page load ----
            (async function loadExistingChunks() {
                try {
                    const resp = await fetch(`${baseUrl}/chunks`, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    if (!resp.ok) return;
                    const result = await safeJson(resp);
                    if (result.chunks && result.chunks.length) {
                        descChunks = result.chunks;
                        renderChunks();
                    }
                } catch (e) {
                    /* ignore */
                }
            })();
        })();

        // ========== AUTO PUBLISH TAB ==========
        (function() {
            let publishInitialized = false;
            let publishData = null;
            let selectedThumbnailUrl = '';
            let playlistChildMeta = [];

            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const publishBaseUrl = `/audiobooks/${audioBookId}/publish`;

            window.initAutoPublishTab = function() {
                if (publishInitialized) return;
                publishInitialized = true;
                checkYoutubeConnection();
                loadPublishData();
                setupPublishModeToggle();
                setupAIButtons();
                setupPublishButton();
            };

            // ---- Check YouTube Connection ----
            async function checkYoutubeConnection() {
                const statusEl = document.getElementById('publishYtStatus');
                try {
                    const channelId = {{ $audioBook->youtube_channel_id ?? 'null' }};
                    if (!channelId) {
                        statusEl.innerHTML =
                            '<div class="flex items-center gap-2 text-yellow-700"><span>⚠️</span><span>Audiobook chưa được gán kênh YouTube. Vui lòng chọn kênh YouTube trong phần thiết lập.</span></div>';
                        statusEl.className = 'mb-6 p-4 rounded-lg border border-yellow-300 bg-yellow-50';
                        return;
                    }

                    const resp = await fetch(`/youtube-channels/${channelId}/oauth/status`, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const result = await safeJson(resp);

                    if (result.connected) {
                        statusEl.innerHTML =
                            `<div class="flex items-center gap-2 text-green-700"><span>✅</span><span>Đã kết nối YouTube (${result.email || 'N/A'})</span></div>`;
                        statusEl.className = 'mb-6 p-4 rounded-lg border border-green-300 bg-green-50';
                        document.getElementById('publishFormWrapper').classList.remove('hidden');
                    } else {
                        statusEl.innerHTML =
                            '<div class="flex items-center gap-2 text-red-700"><span>❌</span><span>Chưa kết nối YouTube. Vui lòng kết nối OAuth trong trang quản lý kênh.</span></div>';
                        statusEl.className = 'mb-6 p-4 rounded-lg border border-red-300 bg-red-50';
                    }
                } catch (e) {
                    statusEl.innerHTML = `<div class="text-red-600">❌ Lỗi kiểm tra kết nối: ${e.message}</div>`;
                    statusEl.className = 'mb-6 p-4 rounded-lg border border-red-300 bg-red-50';
                }
            }

            // ---- Load Publish Data (videos, thumbnails) ----
            async function loadPublishData() {
                try {
                    const resp = await fetch(`${publishBaseUrl}/data`, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    publishData = await safeJson(resp);
                    renderVideoSources();
                    renderThumbnailGallery();
                } catch (e) {
                    document.getElementById('publishVideoSources').innerHTML =
                        `<p class="text-sm text-red-500">Lỗi tải dữ liệu: ${e.message}</p>`;
                }
            }

            // ---- Render Video Sources ----
            function renderVideoSources() {
                const container = document.getElementById('publishVideoSources');
                if (!publishData || !publishData.videos || publishData.videos.length === 0) {
                    container.innerHTML =
                        '<p class="text-sm text-gray-400">Không có video nào. Hãy tạo video cho các chapter trước.</p>';
                    return;
                }

                container.innerHTML = publishData.videos.map((v, i) => `
                    <label class="flex items-center gap-3 p-2 rounded hover:bg-gray-50 cursor-pointer transition">
                        <input type="checkbox" class="publish-video-checkbox rounded text-blue-600"
                               value="${v.id}" data-type="${v.type}" data-path="${v.path}" data-label="${v.label}">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-gray-700">${v.label}</span>
                            <span class="text-xs text-gray-400 ml-2">${v.duration ? v.duration + 's' : ''}</span>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full ${v.type === 'description' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'}">${v.type === 'description' ? 'Giới thiệu' : 'Chapter'}</span>
                    </label>
                `).join('');

                // Update selection count
                container.querySelectorAll('.publish-video-checkbox').forEach(cb => {
                    cb.addEventListener('change', updateSourceSelection);
                });
            }

            function updateSourceSelection() {
                const mode = document.querySelector('input[name="publishMode"]:checked').value;
                const checked = document.querySelectorAll('.publish-video-checkbox:checked');
                const hint = document.getElementById('publishSourceHint');

                if (mode === 'playlist') {
                    hint.textContent = `Đã chọn ${checked.length} video cho playlist`;
                } else {
                    if (checked.length > 1) {
                        // For single/shorts, uncheck all except the last one
                        document.querySelectorAll('.publish-video-checkbox:checked').forEach((cb, i) => {
                            if (i < checked.length - 1) cb.checked = false;
                        });
                        hint.textContent = 'Chỉ chọn 1 video (chế độ Video đơn lẻ / Shorts)';
                    } else {
                        hint.textContent = `Đã chọn ${checked.length} video`;
                    }
                }
            }

            // ---- Render Thumbnail Gallery ----
            function renderThumbnailGallery() {
                const container = document.getElementById('publishThumbnailGallery');
                if (!publishData || !publishData.thumbnails || publishData.thumbnails.length === 0) {
                    container.innerHTML =
                        '<p class="text-sm text-gray-400 col-span-2">Không có thumbnail. Vào tab "YouTube Media (AI)" để tạo.</p>';
                    return;
                }

                container.innerHTML = publishData.thumbnails.map((t, i) => `
                    <div class="relative cursor-pointer rounded-lg overflow-hidden border-2 border-transparent hover:border-blue-400 transition publish-thumb-item"
                         data-url="${t.url}" data-path="${t.path}">
                        <img src="${t.url}" alt="Thumbnail ${i+1}" class="w-full h-auto object-cover aspect-video">
                        <div class="absolute inset-0 bg-blue-600 bg-opacity-0 hover:bg-opacity-10 transition"></div>
                    </div>
                `).join('');

                container.querySelectorAll('.publish-thumb-item').forEach(item => {
                    item.addEventListener('click', function() {
                        // Deselect all
                        container.querySelectorAll('.publish-thumb-item').forEach(el => {
                            el.classList.remove('border-blue-500', 'ring-2', 'ring-blue-300');
                            el.classList.add('border-transparent');
                        });
                        // Select this one
                        this.classList.remove('border-transparent');
                        this.classList.add('border-blue-500', 'ring-2', 'ring-blue-300');

                        selectedThumbnailUrl = this.dataset.path;
                        document.getElementById('publishSelectedThumbnail').value = this.dataset.path;

                        // Show preview
                        const preview = document.getElementById('publishThumbnailPreview');
                        document.getElementById('publishThumbnailPreviewImg').src = this.dataset.url;
                        preview.classList.remove('hidden');
                    });
                });
            }

            // ---- Publish Mode Toggle ----
            function setupPublishModeToggle() {
                document.querySelectorAll('.publish-mode-radio').forEach(radio => {
                    radio.addEventListener('change', function() {
                        const mode = this.value;
                        const playlistSection = document.getElementById('playlistSection');
                        const hint = document.getElementById('publishSourceHint');

                        // Update label styles
                        document.querySelectorAll('.publish-mode-label').forEach(l => {
                            l.classList.remove('bg-blue-50', 'border-blue-400');
                        });
                        this.closest('.publish-mode-label').classList.add('bg-blue-50',
                            'border-blue-400');

                        if (mode === 'playlist') {
                            playlistSection.classList.remove('hidden');
                            hint.textContent = 'Chọn nhiều video để tạo playlist';
                        } else {
                            playlistSection.classList.add('hidden');
                            hint.textContent = 'Chọn 1 video để upload (chế độ Video đơn lẻ / Shorts)';
                        }
                    });
                });

                // Set initial active style
                const initialLabel = document.querySelector('.publish-mode-radio:checked')?.closest(
                    '.publish-mode-label');
                if (initialLabel) initialLabel.classList.add('bg-blue-50', 'border-blue-400');
            }

            // ---- AI Buttons ----
            function setupAIButtons() {
                // AI Generate Title
                document.getElementById('aiGenerateTitleBtn').addEventListener('click', async function() {
                    const btn = this;
                    const origText = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = '⏳ Đang viết...';

                    try {
                        const resp = await fetch(`${publishBaseUrl}/generate-meta`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                type: 'title'
                            })
                        });
                        const result = await safeJson(resp);
                        if (result.title) {
                            document.getElementById('publishTitle').value = result.title;
                        }
                        if (result.tags) {
                            document.getElementById('publishTags').value = result.tags;
                        }
                    } catch (e) {
                        alert('Lỗi: ' + e.message);
                    } finally {
                        btn.disabled = false;
                        btn.textContent = origText;
                    }
                });

                // AI Generate Description
                document.getElementById('aiGenerateDescBtn').addEventListener('click', async function() {
                    const btn = this;
                    const origText = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = '⏳ Đang viết...';

                    try {
                        const resp = await fetch(`${publishBaseUrl}/generate-meta`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                type: 'description'
                            })
                        });
                        const result = await safeJson(resp);
                        if (result.description) {
                            document.getElementById('publishDescription').value = result.description;
                        }
                    } catch (e) {
                        alert('Lỗi: ' + e.message);
                    } finally {
                        btn.disabled = false;
                        btn.textContent = origText;
                    }
                });

                // Generate Playlist Meta (child versions)
                document.getElementById('generatePlaylistMetaBtn').addEventListener('click', async function() {
                    const btn = this;
                    const origText = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = '⏳ AI đang xử lý...';

                    const checkedVideos = [...document.querySelectorAll('.publish-video-checkbox:checked')];
                    if (checkedVideos.length < 2) {
                        alert('Vui lòng chọn ít nhất 2 video để tạo playlist.');
                        btn.disabled = false;
                        btn.textContent = origText;
                        return;
                    }

                    const chapters = checkedVideos.map(cb => ({
                        id: cb.value,
                        label: cb.dataset.label,
                        type: cb.dataset.type
                    }));

                    try {
                        const resp = await fetch(`${publishBaseUrl}/generate-playlist-meta`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                title: document.getElementById('publishTitle').value,
                                description: document.getElementById('publishDescription')
                                    .value,
                                chapters: chapters
                            })
                        });
                        const result = await safeJson(resp);

                        if (result.items && result.items.length) {
                            playlistChildMeta = result.items;
                            renderPlaylistMeta();
                        }
                    } catch (e) {
                        alert('Lỗi: ' + e.message);
                    } finally {
                        btn.disabled = false;
                        btn.textContent = origText;
                    }
                });
            }

            // ---- Render Playlist Child Meta ----
            function renderPlaylistMeta() {
                const container = document.getElementById('playlistMetaList');
                if (!playlistChildMeta.length) {
                    container.innerHTML = '<p class="text-sm text-gray-400 italic">Chưa có dữ liệu.</p>';
                    return;
                }

                container.innerHTML = playlistChildMeta.map((item, i) => `
                    <div class="p-3 border rounded-lg bg-gray-50">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-xs font-bold text-blue-600 bg-blue-100 px-2 py-0.5 rounded">#${i+1}</span>
                            <span class="text-xs text-gray-500">${item.source_label || ''}</span>
                        </div>
                        <input type="text" class="w-full border-gray-300 rounded text-sm mb-1 playlist-child-title"
                               data-index="${i}" value="${item.title}" placeholder="Tiêu đề video ${i+1}">
                        <textarea rows="2" class="w-full border-gray-300 rounded text-sm playlist-child-desc"
                                  data-index="${i}" placeholder="Mô tả video ${i+1}">${item.description}</textarea>
                    </div>
                `).join('');

                // Listen for edits
                container.querySelectorAll('.playlist-child-title').forEach(input => {
                    input.addEventListener('input', function() {
                        playlistChildMeta[parseInt(this.dataset.index)].title = this.value;
                    });
                });
                container.querySelectorAll('.playlist-child-desc').forEach(ta => {
                    ta.addEventListener('input', function() {
                        playlistChildMeta[parseInt(this.dataset.index)].description = this.value;
                    });
                });
            }

            // ---- Publish Button ----
            function setupPublishButton() {
                document.getElementById('publishToYoutubeBtn').addEventListener('click', async function() {
                    const btn = this;
                    const mode = document.querySelector('input[name="publishMode"]:checked').value;
                    const checkedVideos = [...document.querySelectorAll('.publish-video-checkbox:checked')];

                    if (checkedVideos.length === 0) {
                        alert('Vui lòng chọn ít nhất 1 video nguồn.');
                        return;
                    }

                    if (mode !== 'playlist' && checkedVideos.length > 1) {
                        alert('Chế độ Video đơn lẻ / Shorts chỉ cho phép chọn 1 video.');
                        return;
                    }

                    const title = document.getElementById('publishTitle').value.trim();
                    if (!title) {
                        alert('Vui lòng nhập tiêu đề video.');
                        return;
                    }

                    if (!confirm(
                            `Bạn muốn phát hành ${mode === 'playlist' ? checkedVideos.length + ' video trong playlist' : '1 video'} lên YouTube?`
                            )) {
                        return;
                    }

                    btn.disabled = true;
                    const progressEl = document.getElementById('publishProgress');
                    const progressText = document.getElementById('publishProgressText');
                    const progressBar = document.getElementById('publishProgressBar');
                    const resultEl = document.getElementById('publishResult');
                    progressEl.classList.remove('hidden');
                    resultEl.classList.add('hidden');

                    try {
                        if (mode === 'playlist') {
                            // Collect child meta from the editable fields
                            const childTitles = document.querySelectorAll('.playlist-child-title');
                            const childDescs = document.querySelectorAll('.playlist-child-desc');
                            const items = checkedVideos.map((cb, i) => ({
                                video_id: cb.value,
                                video_type: cb.dataset.type,
                                title: childTitles[i] ? childTitles[i].value : title,
                                description: childDescs[i] ? childDescs[i].value : '',
                            }));

                            progressText.textContent = 'Đang tạo playlist và upload video...';
                            progressBar.style.width = '10%';

                            const resp = await fetch(`${publishBaseUrl}/create-playlist`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify({
                                    playlist_name: document.getElementById('playlistName')
                                        .value || title,
                                    playlist_description: document.getElementById(
                                        'publishDescription').value,
                                    privacy: document.getElementById('publishPrivacy')
                                        .value,
                                    thumbnail_path: selectedThumbnailUrl,
                                    tags: document.getElementById('publishTags').value,
                                    items: items
                                })
                            });
                            const result = await safeJson(resp);
                            progressBar.style.width = '100%';
                            progressText.textContent = 'Hoàn tất!';

                            showPublishResult(result);
                        } else {
                            // Single video or Shorts
                            const cb = checkedVideos[0];
                            progressText.textContent =
                                `Đang upload ${mode === 'shorts' ? 'Shorts' : 'video'}...`;
                            progressBar.style.width = '20%';

                            const resp = await fetch(`${publishBaseUrl}/upload`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify({
                                    video_id: cb.value,
                                    video_type: cb.dataset.type,
                                    title: title + (mode === 'shorts' ? ' #Shorts' : ''),
                                    description: document.getElementById(
                                        'publishDescription').value,
                                    tags: document.getElementById('publishTags').value,
                                    privacy: document.getElementById('publishPrivacy')
                                        .value,
                                    thumbnail_path: selectedThumbnailUrl,
                                    is_shorts: mode === 'shorts'
                                })
                            });
                            const result = await safeJson(resp);
                            progressBar.style.width = '100%';
                            progressText.textContent = 'Hoàn tất!';

                            showPublishResult(result);
                        }
                    } catch (e) {
                        resultEl.innerHTML =
                            `<div class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-700">❌ Lỗi: ${e.message}</div>`;
                        resultEl.classList.remove('hidden');
                        progressText.textContent = 'Lỗi!';
                    } finally {
                        btn.disabled = false;
                        setTimeout(() => progressEl.classList.add('hidden'), 3000);
                    }
                });
            }

            function showPublishResult(result) {
                const el = document.getElementById('publishResult');
                if (result.success) {
                    let html = '<div class="p-4 bg-green-50 border border-green-300 rounded-lg">';
                    html += '<p class="text-green-700 font-semibold mb-2">✅ Phát hành thành công!</p>';

                    if (result.playlist_url) {
                        html +=
                            `<p class="text-sm"><a href="${result.playlist_url}" target="_blank" class="text-blue-600 hover:underline">🔗 Xem Playlist trên YouTube</a></p>`;
                    }

                    if (result.video_url) {
                        html +=
                            `<p class="text-sm"><a href="${result.video_url}" target="_blank" class="text-blue-600 hover:underline">🔗 Xem Video trên YouTube</a></p>`;
                    }

                    if (result.uploaded_videos && result.uploaded_videos.length) {
                        html += '<div class="mt-2 space-y-1">';
                        result.uploaded_videos.forEach((v, i) => {
                            html +=
                                `<p class="text-xs text-gray-600">${i+1}. ${v.title} - <a href="${v.url}" target="_blank" class="text-blue-600 hover:underline">Xem</a></p>`;
                        });
                        html += '</div>';
                    }

                    html += '</div>';
                    el.innerHTML = html;
                } else {
                    el.innerHTML =
                        `<div class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-700">❌ ${result.error || 'Có lỗi xảy ra'}</div>`;
                }
                el.classList.remove('hidden');
            }
        })();
    </script>
@endsection
