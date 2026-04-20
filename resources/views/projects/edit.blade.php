@extends('layouts.app')

@section('content')
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-between items-center mb-6">
                <h2 class="font-semibold text-2xl text-gray-800">
                    {{ __('Edit Project - DubSync Workflow') }}
                </h2>
                <a href="{{ route('youtube-channels.show', $project->youtube_channel_id) }}"
                    class="bg-black hover:bg-gray-800 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                    Back to Channel
                </a>
            </div>
        </div>
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            @php
                $projectStatus = (string) ($project->status ?? '');
                $projectSegments = $project->segments;
                $hasSegments = is_array($projectSegments) ? count($projectSegments) > 0 : !empty($projectSegments);
                $shouldShowGetTranscript = !$hasSegments;
            @endphp

            @if ($shouldShowGetTranscript)
                <div
                    class="mb-6 bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg flex items-center justify-between">
                    <div class="text-sm">
                        @if ($projectStatus === 'error')
                            Lay transcript truoc do bi loi. Bam lai de thu lai.
                        @elseif (!$hasSegments)
                            Chua co transcript/segments. Bam de lay transcript.
                        @else
                            Video dang o trang thai moi. Vui long lay transcript de tiep tuc.
                        @endif
                    </div>
                    <form id="getTranscriptForm" action="{{ route('projects.get.transcript', $project) }}" method="POST"
                        data-async-action="{{ route('projects.get.transcript.async', $project) }}">
                        @csrf
                        <button id="getTranscriptBtn" type="submit"
                            class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                            {{ $projectStatus === 'error' ? 'Retry transcript' : 'Get transcript' }}
                        </button>
                    </form>
                </div>
                <div id="getTranscriptStatus"
                    class="hidden mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700"></div>
            @endif
            <!-- Project Info Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6 text-gray-900">
                    <!-- Main Grid with aligned top -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                        <!-- Left: Project Info (Combined Block) -->
                        <div class="md:col-span-2">
                            <div class="border border-red-200 rounded-lg p-6 bg-white space-y-4">
                                <!-- Video Preview Section (Top) -->
                                <div class="pb-4 border-b border-red-100">
                                    <p class="font-medium text-gray-700 mb-3">Video Preview</p>
                                    @if ($project->youtube_thumbnail)
                                        <a href="{{ $project->youtube_url }}" target="_blank"
                                            class="block w-full group max-w-xs">
                                            <div class="relative">
                                                <img src="{{ $project->youtube_thumbnail }}" alt="YouTube Thumbnail"
                                                    id="projectThumbnailPreview"
                                                    class="w-full h-auto rounded-md border border-black/10 group-hover:border-red-500 transition">
                                                <div
                                                    class="absolute inset-0 bg-black/0 group-hover:bg-black/20 rounded-md transition flex items-center justify-center">
                                                    <svg class="w-12 h-12 text-white opacity-0 group-hover:opacity-100 transition"
                                                        fill="currentColor" viewBox="0 0 20 20">
                                                        <path
                                                            d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z" />
                                                    </svg>
                                                </div>
                                            </div>
                                        </a>
                                    @else
                                        <a href="{{ $project->youtube_url }}" target="_blank" class="block max-w-xs">
                                            <div
                                                class="w-full bg-gray-200 border border-red-200 rounded-md p-6 text-center text-gray-600 hover:bg-gray-300 transition">
                                                <p class="text-sm font-medium">No Thumbnail</p>
                                                <p class="text-xs mt-1">Click to watch on YouTube</p>
                                            </div>
                                        </a>
                                    @endif
                                    <div class="mt-3 flex gap-2">
                                        <button type="button" id="downloadYoutubeVideoBtn"
                                            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-sm font-medium transition">
                                            📥 Download Source Video
                                        </button>
                                        <div id="downloadProgressContainer" class="hidden flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-0.5">
                                                <div class="flex-1 bg-gray-200 rounded-full h-3 overflow-hidden">
                                                    <div id="downloadProgressBar"
                                                        class="h-full transition-all duration-500 rounded-full"
                                                        style="width: 0%; background: linear-gradient(90deg, #dc2626, #ef4444);">
                                                    </div>
                                                </div>
                                                <span id="downloadProgressText"
                                                    class="text-xs font-bold text-red-600 min-w-[36px] text-right">0%</span>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <span id="downloadProgressMessage"
                                                    class="text-xs text-gray-500 truncate flex-1">Đang chờ...</span>
                                                <span id="downloadProgressSpeed"
                                                    class="text-xs font-semibold text-blue-500 whitespace-nowrap hidden"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-2 flex flex-wrap gap-2 items-center">
                                        <select id="thumbnailRatioSelect"
                                            class="px-3 py-2 border border-gray-300 rounded-md text-sm bg-white">
                                            <option value="16:9" selected>16:9</option>
                                            <option value="9:16">9:16</option>
                                        </select>
                                        <select id="thumbnailStyleSelect"
                                            class="px-3 py-2 border border-gray-300 rounded-md text-sm bg-white">
                                            <option value="cinematic" selected>Cinematic</option>
                                            <option value="dramatic">Dramatic</option>
                                            <option value="minimal">Minimal</option>
                                            <option value="news">News</option>
                                            <option value="bold">Bold Contrast</option>
                                        </select>
                                        <button type="button" id="generateThumbnailBtn"
                                            class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-sm font-medium transition">
                                            🖼️ Tạo thumbnail
                                        </button>
                                        <span id="thumbnailStatus" class="text-xs text-gray-500 hidden"></span>
                                    </div>
                                </div>

                                <!-- Title Section -->
                                <div class="pb-4 border-b border-red-100">
                                    <h3 class="text-lg font-semibold text-black mb-3">Title</h3>
                                    <div class="space-y-2">
                                        <p class="text-teal-600 truncate"><span
                                                class="px-1.5 py-0.5 rounded text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200">EN</span>
                                            {{ $project->youtube_title ?? 'N/A' }}</p>
                                        <div class="space-y-2">
                                            <label for="youtubeTitleViInput" class="text-gray-700 text-sm font-medium flex items-center gap-2">
                                                <span class="px-1.5 py-0.5 rounded text-xs font-semibold text-white bg-red-600">VI</span>
                                                Tieu de tieng Viet
                                            </label>
                                            <textarea id="youtubeTitleViInput"
                                                class="w-full border border-gray-300 rounded-lg p-2 text-sm text-gray-700 focus:ring-2 focus:ring-red-400 focus:border-red-400"
                                                rows="2"
                                                placeholder="Nhap va chinh sua tieu de VI...">{{ $project->youtube_title_vi ?? '' }}</textarea>
                                            <div class="flex items-center gap-3">
                                                <button type="button" id="saveTitleViBtn"
                                                    class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-md text-sm font-medium transition">
                                                    Luu tieu de VI
                                                </button>
                                                <span id="titleViSaveStatus" class="text-xs text-gray-500 hidden"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Description Section -->
                                <div class="pb-4 border-b border-red-100">
                                    <h3 class="text-lg font-semibold text-black mb-3">Description</h3>
                                    <div class="space-y-2">
                                        <p class="text-teal-600 line-clamp-3"><span
                                                class="px-1.5 py-0.5 rounded text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200">EN</span>
                                            {{ $project->youtube_description ?? 'N/A' }}</p>
                                        @if ($project->youtube_description_vi)
                                            <p class="text-gray-600 italic line-clamp-3"><span
                                                    class="px-1.5 py-0.5 rounded text-xs font-semibold text-white bg-red-600">VI</span>
                                                {{ $project->youtube_description_vi }}</p>
                                        @endif
                                    </div>
                                </div>

                                <!-- Project Details Section -->
                                <div>
                                    <h3 class="text-lg font-semibold text-black mb-4">Project Details</h3>

                                    <!-- Metadata Grid -->
                                    <div class="grid grid-cols-3 gap-4 text-sm">
                                        <div>
                                            <p class="font-medium text-gray-700 mb-1">Duration</p>
                                            <p class="text-gray-900">{{ $project->youtube_duration ?? 'N/A' }}</p>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-700 mb-1">Status</p>
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="px-2 py-1 rounded bg-red-50 text-red-700 border border-red-200 inline-block text-sm">
                                                    {{ ucfirst(str_replace('_', ' ', $project->status)) }}
                                                </span>
                                                <button type="button" id="changeStatusBtn"
                                                    class="text-xs text-gray-500 hover:text-gray-800 underline">
                                                    Đổi status
                                                </button>
                                            </div>
                                            <div id="changeStatusPanel" class="hidden mt-2 flex items-center gap-2">
                                                <select id="newStatusSelect" class="text-sm border border-gray-300 rounded px-2 py-1">
                                                    @foreach(['new','source_downloaded','transcribed','translated','tts_generated','aligned','merged','completed','error'] as $s)
                                                        <option value="{{ $s }}" {{ $project->status === $s ? 'selected' : '' }}>{{ $s }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="button" id="applyStatusBtn"
                                                    class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded transition">
                                                    Áp dụng
                                                </button>
                                                <button type="button" id="cancelStatusBtn"
                                                    class="text-xs text-gray-500 hover:text-gray-700 underline">
                                                    Huỷ
                                                </button>
                                            </div>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-700 mb-1">Created</p>
                                            <p class="text-gray-900">{{ $project->created_at->format('d/m/Y H:i') }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: TTS Audio Settings -->
                        <div class="h-fit">
                            <div class="p-4 bg-blue-50 border-2 border-blue-300 rounded-lg">
                                <button type="button" id="ttsToggleBtn"
                                    class="w-full text-left flex items-center justify-between hover:opacity-75 transition">
                                    <h4 class="text-base font-semibold text-blue-900 flex items-center gap-2">
                                        🎙️ TTS Audio Settings
                                    </h4>
                                    <span id="ttsToggleIcon" class="text-xl">−</span>
                                </button>

                                <div id="ttsContent" class="space-y-4 mt-4">
                                    <!-- TTS Provider -->
                                    <div class="bg-white p-3 rounded border border-blue-200">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">TTS
                                            Provider: <span class="text-red-500">*</span></label>
                                        <select id="ttsProviderSelect"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-blue-500 focus:outline-none">
                                            <option value="" {{ !$project->tts_provider ? 'selected' : '' }}>--
                                                Chọn TTS Provider --</option>
                                            <option value="microsoft"
                                                {{ ($project->tts_provider ?? '') === 'microsoft' ? 'selected' : '' }}>
                                                🪟 Microsoft TTS</option>
                                            <option value="vbee"
                                                {{ ($project->tts_provider ?? '') === 'vbee' ? 'selected' : '' }}>
                                                🇻🇳 Vbee TTS (Việt Nam)</option>
                                            <option value="openai"
                                                {{ ($project->tts_provider ?? '') === 'openai' ? 'selected' : '' }}>
                                                🤖 OpenAI TTS </option>
                                            <option value="gemini"
                                                {{ ($project->tts_provider ?? '') === 'gemini' ? 'selected' : '' }}>
                                                ✨ Gemini Pro TTS</option>
                                        </select>
                                    </div>

                                    <!-- Audio Mode -->
                                    <div id="audioModeContainer" class="bg-white p-3 rounded border border-blue-200">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Mode:</label>
                                        <div class="flex gap-3">
                                            <label class="flex items-center cursor-pointer">
                                                <input type="radio" name="audioMode" value="single"
                                                    id="singleSpeakerRadio" class="mr-2"
                                                    {{ ($project->audio_mode ?? 'single') === 'single' ? 'checked' : '' }}>
                                                <div class="text-sm">
                                                    <div class="font-medium">👤 Single-speaker</div>
                                                    <div class="text-xs text-gray-500">Một giọng cho tất cả</div>
                                                </div>
                                            </label>
                                            <label class="flex items-center cursor-pointer">
                                                <input type="radio" name="audioMode" value="multi"
                                                    id="multiSpeakerRadio" class="mr-2"
                                                    {{ ($project->audio_mode ?? 'single') === 'multi' ? 'checked' : '' }}>
                                                <div class="text-sm">
                                                    <div class="font-medium">👥 Multi-speaker</div>
                                                    <div class="text-xs text-gray-500">Nhiều người nói</div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Style Instruction -->
                                    <div id="styleInstructionContainer"
                                        class="bg-white p-3 rounded border border-blue-200">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Style
                                            Instruction:</label>
                                        <div class="flex flex-wrap gap-2 mb-2">
                                            <button type="button"
                                                class="style-preset-btn px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng tự nhiên, rõ ràng, nhịp vừa phải,&#10;phong cách giới thiệu video YouTube,&#10;thân thiện và dễ nghe.">🎬
                                                Video YouTube</button>
                                            <button type="button"
                                                class="style-preset-btn px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng trẻ trung, năng lượng,&#10;nhịp nhanh vừa phải,&#10;phù hợp video TikTok / Shorts,&#10;tạo cảm giác thu hút ngay từ đầu.">📱
                                                TikTok / Shorts / Reels</button>
                                            <button type="button"
                                                class="style-preset-btn px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng điềm tĩnh, rõ ràng,&#10;tốc độ chậm vừa,&#10;phong cách giảng dạy, hướng dẫn học tập.">🎓
                                                E-learning / Training</button>
                                            <button type="button"
                                                class="style-preset-btn px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng chuyên nghiệp, trầm vừa,&#10;tự tin và rõ ràng,&#10;phù hợp video giới thiệu doanh nghiệp.">🏢
                                                Corporate</button>
                                            <button type="button"
                                                class="style-preset-btn px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng ấm áp, chậm rãi,&#10;phong cách kể chuyện,&#10;tạo cảm giác gần gũi và cuốn hút.">🎙️
                                                Podcast / Storytelling</button>
                                            <button type="button"
                                                class="style-preset-btn px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng trung tính, rõ ràng,&#10;phong cách trợ lý ảo,&#10;dễ nghe và dễ hiểu.">🤖
                                                App AI / Voice Assistant</button>
                                            <button type="button"
                                                class="style-preset-btn px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng rất nhẹ, chậm rãi,&#10;thư giãn,&#10;phù hợp nội dung thiền và sức khỏe tinh thần.">🧘
                                                Thiền / Wellness</button>
                                            <button type="button"
                                                class="style-preset-btn px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                data-text="Đọc với giọng hiện đại, rõ ràng,&#10;tốc độ vừa,&#10;phù hợp giới thiệu sản phẩm công nghệ / AI.">💡
                                                Tech / AI / Startup</button>
                                        </div>
                                        <textarea id="ttsStyleInstruction" rows="4"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-blue-500 focus:outline-none"
                                            placeholder="Có thể chọn nhanh từ gợi ý ở trên hoặc chỉnh sửa tùy ý..."></textarea>
                                        <p class="mt-1 text-xs text-gray-500">Bạn có thể chỉnh sửa tự do nội dung trong
                                            ô này.</p>
                                    </div>

                                    <!-- Voice Settings -->
                                    <!-- Single Speaker Config -->
                                    <div id="singleSpeakerConfig" class="bg-white p-3 rounded border border-blue-200"
                                        style="display: none;">
                                        <div class="flex items-center justify-between mb-3">
                                            <label class="text-sm font-medium text-gray-700">Voice Settings:</label>
                                            <div class="flex items-center gap-3 text-sm text-gray-700">
                                                <label class="inline-flex items-center gap-1">
                                                    <input type="radio" name="globalVoiceGender" value="female"
                                                        checked>
                                                    <span>👩 Nữ</span>
                                                </label>
                                                <label class="inline-flex items-center gap-1">
                                                    <input type="radio" name="globalVoiceGender" value="male">
                                                    <span>👨 Nam</span>
                                                </label>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Chọn
                                                giọng:</label>
                                            <div class="flex gap-1">
                                                <select id="globalVoiceName"
                                                    class="flex-1 px-2 py-1.5 border border-gray-300 rounded text-sm focus:border-blue-500 focus:outline-none">
                                                    <option value="">-- Chọn giọng --</option>
                                                </select>
                                                <button type="button" id="globalVoicePreviewBtn"
                                                    class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded text-sm font-medium transition"
                                                    title="Nghe thử giọng">
                                                    🔊
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Multi Speaker Config -->
                                    <div id="multiSpeakerConfig" class="bg-white p-3 rounded border border-blue-200"
                                        style="display: none;">
                                        <div class="flex justify-between items-center mb-2">
                                            <label class="text-sm font-medium text-gray-700">Speaker
                                                Definitions:</label>
                                            <button type="button" id="addSpeakerBtn"
                                                class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-2.5 py-1 rounded font-medium transition">
                                                + Add Speaker
                                            </button>
                                        </div>
                                        <div id="speakersList" class="space-y-2 max-h-64 overflow-y-auto p-2">
                                            <!-- Speakers will be added here -->
                                        </div>
                                    </div>

                                    <!-- Generate TTS Button moved to Actions bar for consistency -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Steps -->
                    <div id="progressSection" class="mb-6 bg-white shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <h4 class="font-semibold mb-3">Workflow Status</h4>
                            <div class="space-y-2">
                                <div id="step1">
                                    <div class="flex items-center">
                                        <div
                                            class="w-6 h-6 rounded-full bg-green-500 flex items-center justify-center mr-3">
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <span class="text-sm font-medium">Extract Transcript from YouTube ✓</span>
                                    </div>
                                </div>
                                <div class="flex items-center" id="step2">
                                    <div
                                        class="w-6 h-6 rounded-full {{ in_array($project->status, ['translated', 'tts_generated', 'aligned', 'merged', 'completed']) ? 'bg-green-500' : 'bg-gray-300' }} flex items-center justify-center mr-3">
                                        @if (in_array($project->status, ['translated', 'tts_generated', 'aligned', 'merged', 'completed']))
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        @else
                                            <span class="text-xs">2</span>
                                        @endif
                                    </div>
                                    <span
                                        class="text-sm {{ in_array($project->status, ['translated', 'tts_generated', 'aligned', 'merged', 'completed']) ? 'font-medium' : '' }}">
                                        Translate to Vietnamese
                                        {{ in_array($project->status, ['translated', 'tts_generated', 'aligned', 'merged', 'completed']) ? '✓' : '' }}
                                    </span>
                                </div>
                                <div class="flex items-center" id="step3">
                                    <div
                                        class="w-6 h-6 rounded-full {{ in_array($project->status, ['tts_generated', 'aligned', 'merged', 'completed']) ? 'bg-green-500' : 'bg-gray-300' }} flex items-center justify-center mr-3">
                                        @if (in_array($project->status, ['tts_generated', 'aligned', 'merged', 'completed']))
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        @else
                                            <span class="text-xs">3</span>
                                        @endif
                                    </div>
                                    <span
                                        class="text-sm {{ in_array($project->status, ['tts_generated', 'aligned', 'merged', 'completed']) ? 'font-medium' : '' }}">
                                        Generate TTS Voice
                                        {{ in_array($project->status, ['tts_generated', 'aligned', 'merged', 'completed']) ? '✓' : '' }}
                                    </span>
                                </div>
                                <div class="flex items-center" id="step4">
                                    <div
                                        class="w-6 h-6 rounded-full {{ in_array($project->status, ['aligned', 'merged', 'completed']) ? 'bg-green-500' : 'bg-gray-300' }} flex items-center justify-center mr-3">
                                        @if (in_array($project->status, ['aligned', 'merged', 'completed']))
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        @else
                                            <span class="text-xs">4</span>
                                        @endif
                                    </div>
                                    <span
                                        class="text-sm {{ in_array($project->status, ['aligned', 'merged', 'completed']) ? 'font-medium' : '' }}">
                                        Align Audio Timing
                                        {{ in_array($project->status, ['aligned', 'merged', 'completed']) ? '✓' : '' }}
                                    </span>
                                </div>
                                <div class="flex items-center" id="step5">
                                    <div
                                        class="w-6 h-6 rounded-full {{ in_array($project->status, ['merged', 'completed']) ? 'bg-green-500' : 'bg-gray-300' }} flex items-center justify-center mr-3">
                                        @if (in_array($project->status, ['merged', 'completed']))
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        @else
                                            <span class="text-xs">5</span>
                                        @endif
                                    </div>
                                    <span
                                        class="text-sm {{ in_array($project->status, ['merged', 'completed']) ? 'font-medium' : '' }}">
                                        Merge Audio
                                        {{ in_array($project->status, ['merged', 'completed']) ? '✓' : '' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Segments Editor -->
                    <div id="segmentsEditor" class="bg-white shadow-sm sm:rounded-lg p-6 pb-32 mb-6">
                        <!-- Tabs Navigation -->
                        <div class="border-b border-gray-200 mb-4">
                            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                                <button id="segmentsTab"
                                    class="tab-button border-b-2 border-indigo-500 py-4 px-1 text-sm font-medium text-indigo-600"
                                    onclick="switchTab('segments')">
                                    📝 Segments Editor
                                </button>
                                <button id="transcriptTab"
                                    class="tab-button border-b-2 border-transparent py-4 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300"
                                    onclick="switchTab('transcript')">
                                    📄 Full Transcript
                                </button>
                                <button id="transcriptAudioTab"
                                    class="tab-button border-b-2 border-transparent py-4 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300"
                                    onclick="switchTab('transcriptAudio')">
                                    🎵 Full Transcript Audio
                                </button>
                            </nav>
                        </div>

                        <!-- Segments Tab Content -->
                        <div id="segmentsTabContent">
                            <h4 class="font-semibold mb-3">Edit Segments</h4>
                            <div class="mb-3 flex flex-wrap items-center gap-3">
                                <label class="flex items-center text-sm text-gray-700">
                                    <input type="checkbox" id="selectAllSegments" class="mr-2">
                                    Chọn tất cả
                                </label>
                                <button id="saveSegmentsBtn"
                                    class="bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm"
                                    title="Lưu các đoạn đã chỉnh sửa">
                                    💾 Save
                                </button>
                                <button id="deleteAudiosBtn"
                                    class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm hidden"
                                    title="Xóa các file âm thanh đã tạo cho segments được chọn">
                                    🗑️ Xóa Audio
                                </button>
                                <span id="bulkFixStatus" class="text-xs text-gray-500 hidden"></span>
                            </div>
                            <div id="segmentsList" class="space-y-3 mb-4">
                                @php
                                    $fallbackTranslated = is_array($project->translated_segments) ? $project->translated_segments : [];
                                    $fallbackOriginal = is_array($project->segments) ? $project->segments : [];
                                    $fallbackSegments = count($fallbackTranslated) > 0 ? $fallbackTranslated : $fallbackOriginal;
                                @endphp

                                @if (count($fallbackSegments) > 0)
                                    @foreach ($fallbackSegments as $index => $segment)
                                        @php
                                            $segmentText = is_array($segment) ? ($segment['text'] ?? '') : '';
                                            $segmentOriginalText = is_array($segment)
                                                ? ($segment['original_text'] ?? ($fallbackOriginal[$index]['text'] ?? $segmentText))
                                                : '';
                                            $startTime = is_array($segment)
                                                ? ($segment['start_time'] ?? ($segment['start'] ?? 0))
                                                : 0;
                                            $endTime = is_array($segment)
                                                ? ($segment['end_time'] ?? ($segment['end'] ?? $startTime))
                                                : $startTime;
                                        @endphp
                                        <div class="bg-white border border-gray-200 rounded-lg p-4" data-segment-index="{{ $index }}">
                                            <div class="flex justify-between items-start mb-2">
                                                <div class="flex items-center gap-2">
                                                    <input type="checkbox" class="segment-select" data-index="{{ $index }}">
                                                    <span class="text-sm font-medium text-gray-600">
                                                        Đoạn {{ $index + 1 }} ({{ is_numeric($startTime) ? number_format((float) $startTime, 2) : 0 }}s - {{ is_numeric($endTime) ? number_format((float) $endTime, 2) : 0 }}s)
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <label class="text-xs text-gray-600 font-medium">Original:</label>
                                                <p class="text-sm text-teal-600">
                                                    <span class="px-1.5 py-0.5 rounded text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200">EN</span>
                                                    {{ $segmentOriginalText }}
                                                </p>
                                            </div>
                                            <div class="mb-2">
                                                <label class="text-xs text-gray-600 font-medium">Translated:</label>
                                            </div>
                                            <textarea class="w-full px-3 py-2 border border-gray-300 rounded-lg text-base font-semibold leading-relaxed segment-text" rows="2" data-index="{{ $index }}">{{ $segmentText }}</textarea>
                                        </div>
                                    @endforeach
                                @else
                                    <!-- Segments will be loaded here dynamically -->
                                @endif
                            </div>
                        </div>

                        <!-- Full Transcript Tab Content -->
                        <div id="transcriptTabContent" class="hidden">
                            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                                <h4 class="font-semibold">Full Transcript</h4>
                                <div class="flex flex-wrap gap-2">
                                    <button id="saveTranscriptBtn"
                                        class="bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm">
                                        💾 Save
                                    </button>
                                    <button id="rewriteTranscriptBtn"
                                        class="bg-amber-600 hover:bg-amber-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm">
                                        ✍️ Viết lại
                                    </button>
                                    <button id="convertTranscriptToSpeechBtn"
                                        class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm"
                                        disabled style="opacity: 0.5; cursor: not-allowed;">
                                        🎙️ Convert to Speech
                                    </button>
                                    <button id="downloadTranscriptBtn"
                                        class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm">
                                        💾 Download as TXT
                                    </button>
                                </div>
                            </div>

                            <div id="fullTranscriptTtsProgress"
                                class="hidden mb-3 bg-gray-50 border border-gray-200 rounded-lg p-3">
                                <div class="flex items-center gap-2 text-sm text-gray-700">
                                    <div
                                        class="h-4 w-4 border-2 border-gray-300 border-t-purple-600 rounded-full animate-spin">
                                    </div>
                                    <span id="fullTranscriptTtsStatus">Đang tạo TTS...</span>
                                </div>
                                <div class="mt-2 h-2 w-full bg-gray-200 rounded-full overflow-hidden">
                                    <div id="fullTranscriptTtsBar" class="h-2 bg-purple-600 rounded-full"
                                        style="width: 0%"></div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between text-sm text-gray-600">
                                        <span class="font-medium">Full Transcript (EN)</span>
                                        <span>Words: <span id="fullTranscriptWordCount">0</span></span>
                                    </div>
                                    <textarea id="fullTranscriptContent"
                                        class="w-full bg-gray-50 border border-gray-200 rounded-lg p-6 h-[600px] resize-y font-sans text-base leading-relaxed"
                                        placeholder="No transcript available. Please load segments first."></textarea>
                                </div>

                                <div class="space-y-2">
                                    <div class="flex items-center justify-between text-sm text-gray-600">
                                        <span class="font-medium">Translated Full Transcript (VI)</span>
                                        <span>Words: <span id="translatedTranscriptWordCount">0</span></span>
                                    </div>
                                    <textarea id="translatedFullTranscriptContent"
                                        class="w-full bg-gray-50 border border-gray-200 rounded-lg p-6 h-[600px] resize-y font-sans text-base leading-relaxed"
                                        placeholder="No translated transcript available. Please translate segments first."></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Full Transcript Audio Tab Content -->
                        <div id="transcriptAudioTabContent" class="hidden">
                            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                                <h4 class="font-semibold">Full Transcript Audio Files</h4>
                                <div class="flex flex-wrap gap-2">
                                    <button id="mergeAudioBtn"
                                        class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm">
                                        🎵 Merge Audio
                                    </button>
                                    <button id="refreshAudioListBtn"
                                        class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm">
                                        🔄 Refresh List
                                    </button>
                                    <button id="deleteAllAudioBtn"
                                        class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm">
                                        🗑️ Delete All
                                    </button>
                                </div>
                            </div>

                            <div id="mergeAudioProgress"
                                class="hidden mb-3 bg-purple-50 border border-purple-200 rounded-lg p-3">
                                <div class="flex items-center gap-2 text-sm text-gray-700">
                                    <div
                                        class="h-4 w-4 border-2 border-gray-300 border-t-purple-600 rounded-full animate-spin">
                                    </div>
                                    <span id="mergeAudioStatus">Đang merge audio files...</span>
                                </div>
                                <div class="mt-2 h-2 w-full bg-gray-200 rounded-full overflow-hidden">
                                    <div id="mergeAudioBar" class="h-2 bg-purple-600 rounded-full transition-all"
                                        style="width: 0%"></div>
                                </div>
                            </div>

                            <div id="audioListContainer" class="space-y-2">
                                <div class="text-center py-8 text-gray-500">
                                    <i class="ri-music-line text-4xl mb-2"></i>
                                    <p>Chưa có audio nào được tạo</p>
                                    <p class="text-sm mt-1">Hãy chuyển sang tab "Full Transcript" và nhấn "Convert to
                                        Speech"</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Floating Command Bar -->
                    <div id="floatingCommandBar"
                        class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 shadow-2xl z-50">
                        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                            <!-- Progress Bar -->
                            <div id="progressContainer" class="hidden mb-3">
                                <div class="flex justify-between items-center mb-2">
                                    <span id="progressLabel" class="text-sm font-medium text-gray-700">AI đang thực
                                        hiện theo yêu cầu bạn...</span>
                                    <span id="progressPercent" class="text-sm font-medium text-green-600">0%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                                    <div id="progressBar"
                                        class="bg-green-600 h-2 rounded-full transition-all duration-300"
                                        style="width: 0%"></div>
                                </div>
                                <div id="ttsBatchErrorPanel"
                                    class="hidden mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <div id="ttsBatchErrorSummary" class="text-xs font-medium text-amber-800"></div>
                                        <button type="button" id="ttsBatchErrorToggle"
                                            class="text-xs font-medium text-amber-700 hover:text-amber-900 hidden">
                                            Xem them
                                        </button>
                                    </div>
                                    <ul id="ttsBatchErrorList" class="mt-2 space-y-1 text-xs text-amber-900"></ul>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-3 items-center">
                                <span class="text-sm font-medium text-gray-700">Actions:</span>

                                @if (in_array($project->status, ['transcribed', 'translated']))
                                    <select id="translationProvider"
                                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                        <option value="gemini" selected>Gemini (AI Context)</option>
                                        <option value="google">Google Translate</option>
                                        <option value="openai">OpenAI (GPT)</option>
                                    </select>

                                    <select id="translationStyle"
                                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                        <option value="default" selected>🎯 Mặc định</option>
                                        <option value="humorous">😂 Hài hước</option>
                                    </select>

                                    <button id="translateBtn"
                                        class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                                        Translate to Vietnamese
                                    </button>

                                    <button id="clearTranslationBtn"
                                        class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200"
                                        title="Clear translated content">
                                        Clear Translation
                                    </button>
                                @endif

                                @if (!empty($project->translated_segments))
                                    <button id="convertNumbersToWordsBtn"
                                        class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200"
                                        title="Chuyển số 4 chữ số thành chữ trong phần dịch">
                                        🔢➡️🔤 Chuyển số thành chữ
                                    </button>
                                @endif

                                @if (!in_array($project->status, ['aligned', 'merged', 'completed']))
                                    <button id="generateTTSBtn"
                                        data-tts-mode="selected-segments"
                                        class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                                        Generate TTS Voice
                                    </button>
                                @endif

                                <button type="button" id="queueMonitorBtn"
                                    class="bg-gray-700 hover:bg-gray-800 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 relative"
                                    title="Queue Monitor">
                                    📋 Queue
                                    <span id="queueBadge" class="hidden absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full w-5 h-5 flex items-center justify-center">0</span>
                                </button>

                                @if ($project->status === 'tts_generated')
                                    <button type="button" id="alignTimingBtn"
                                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                                        Align Timing
                                    </button>
                                @endif

                                @if (in_array($project->status, ['tts_generated', 'aligned']) && !in_array($project->status, ['merged', 'completed']))
                                    <button id="mergeSegmentsBtn"
                                        class="bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200"
                                        title="Ghép audio theo đúng timeline transcript gốc">
                                        🎵 Merge Audio (Timeline)
                                    </button>
                                @endif

                                @if (in_array($project->status, ['tts_generated', 'aligned', 'merged', 'completed']))
                                    <button type="button" id="resetToTtsBtn"
                                        class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200"
                                        title="Quay lại trạng thái Generate TTS Voice (xóa tất cả audio)">
                                        ↶ Reset to TTS
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Merged Audio Result -->
                    @if ($project->final_audio_path)
                        @php
                            $mergedUrl = \Illuminate\Support\Facades\Storage::url($project->final_audio_path);
                            $mergedFilename = basename($project->final_audio_path);
                        @endphp
                        <div class="mt-6 bg-green-50 border border-green-200 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="font-semibold text-green-800">🎵 Merged Audio (Timeline)</h4>
                                <div class="flex gap-2">
                                    <a href="{{ $mergedUrl }}" download="{{ $mergedFilename }}"
                                        class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium py-1.5 px-4 rounded-lg transition">
                                        ⬇ Download
                                    </a>
                                    <button id="remergeSegmentsBtn"
                                        class="bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium py-1.5 px-4 rounded-lg transition"
                                        title="Merge lại theo timeline">
                                        🔁 Re-merge
                                    </button>
                                </div>
                            </div>
                            <audio controls class="w-full" src="{{ $mergedUrl }}">
                                Trình duyệt không hỗ trợ audio.
                            </audio>
                            <p class="text-xs text-green-700 mt-2">{{ $mergedFilename }}</p>
                        </div>
                    @endif

                    <!-- Export Section -->
                    @if (in_array($project->status, ['merged', 'completed']))
                        <div id="exportSection" class="mt-6">
                            <h4 class="font-semibold mb-3">Export Files</h4>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Formats:</label>
                                    <div class="space-y-2">
                                        <label class="flex items-center">
                                            <input type="checkbox" class="export-format mr-2" value="srt" checked>
                                            <span class="text-sm">SRT (SubRip Subtitle)</span>
                                        </label>
                                        <label class="flex items-center">
                                            <input type="checkbox" class="export-format mr-2" value="vtt" checked>
                                            <span class="text-sm">VTT (WebVTT)</span>
                                        </label>
                                        <label class="flex items-center">
                                            <input type="checkbox" class="export-format mr-2" value="audio_wav" checked>
                                            <span class="text-sm">WAV Audio</span>
                                        </label>
                                        <label class="flex items-center">
                                            <input type="checkbox" class="export-format mr-2" value="audio_mp3" checked>
                                            <span class="text-sm">MP3 Audio</span>
                                        </label>
                                        <label class="flex items-center">
                                            <input type="checkbox" class="export-format mr-2" value="json" checked>
                                            <span class="text-sm">JSON Project File</span>
                                        </label>
                                    </div>
                                </div>
                                <button id="exportBtn"
                                    class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                                    Export Files
                                </button>
                            </div>

                            <div id="downloadLinks" class="mt-4 hidden">
                                <h5 class="font-semibold mb-2">Download:</h5>
                                <div id="downloadLinksList" class="space-y-2">
                                    <!-- Download links will be populated here -->
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Queue Monitor Modal -->
    <div id="queueMonitorModal" class="hidden fixed inset-0 z-[9999] flex items-center justify-center bg-black/50">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[80vh] flex flex-col mx-4">
            <div class="flex items-center justify-between px-5 py-3 border-b">
                <h3 class="text-base font-bold text-gray-800">📋 Queue Monitor</h3>
                <div class="flex items-center gap-2">
                    <button type="button" id="queueRefreshBtn"
                        class="px-3 py-1 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded-md transition">
                        🔄 Refresh
                    </button>
                    <button type="button" id="queueClearAllBtn"
                        class="px-3 py-1 text-xs bg-red-600 hover:bg-red-700 text-white rounded-md transition">
                        🗑️ Clear All Jobs
                    </button>
                    <button type="button" id="queueCloseBtn"
                        class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                </div>
            </div>
            <div class="px-5 py-3 overflow-y-auto flex-1">
                <div id="queueSummaryBar" class="flex gap-3 mb-3 text-xs font-semibold"></div>
                <div id="queueContent" class="space-y-3">
                    <p class="text-gray-500 text-sm text-center py-6">Đang tải...</p>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <style>
            @keyframes dlStripe {
                0%   { background-position: 0% 0; }
                100% { background-position: 200% 0; }
            }
        </style>
        <script>
            // Pass project data to JavaScript
            window.projectData = {
                id: {{ $project->id }},
                video_id: '{{ $project->video_id }}',
                youtube_url: '{{ $project->youtube_url }}',
                status: '{{ $project->status }}',
                tts_provider: '{{ $project->tts_provider ?? '' }}',
                audio_mode: '{{ $project->audio_mode ?? 'single' }}',
                speakers_config: {!! json_encode($project->speakers_config ?? []) !!},
                style_instruction: {!! json_encode($project->style_instruction ?? '') !!},
                segments: {!! json_encode($project->segments ?? []) !!},
                translated_segments: {!! json_encode($project->translated_segments ?? []) !!},
                original_transcript: {!! json_encode($project->original_transcript ?? []) !!}
            };

            console.log('[edit.blade] window.projectData:', window.projectData);
            console.log('[edit.blade] tts_provider value:', window.projectData.tts_provider);

            // Global audio player for segment playback
            let currentAudioPlayer = null;
        </script>
        <script src="{{ asset('js/dubsync.js') }}?v={{ time() }}"></script>
        <script>
            // Global TTS helper functions (must be before DOMContentLoaded to be accessible)

            function getTtsSettingsStorageKey(projectId) {
                return `dubsync_tts_settings_${projectId}`;
            }

            function readPersistedTtsSettings(projectId) {
                if (!projectId || !window.localStorage) return null;
                try {
                    const raw = localStorage.getItem(getTtsSettingsStorageKey(projectId));
                    if (!raw) return null;
                    return JSON.parse(raw);
                } catch (error) {
                    console.warn('[TTS settings] Failed to parse persisted data:', error);
                    return null;
                }
            }

            function persistTtsSettings(projectId, payload = {}) {
                if (!projectId || !window.localStorage) return;
                try {
                    const existing = readPersistedTtsSettings(projectId) || {};
                    const merged = {
                        ...existing,
                        ...payload,
                        saved_at: Date.now()
                    };
                    localStorage.setItem(getTtsSettingsStorageKey(projectId), JSON.stringify(merged));
                } catch (error) {
                    console.warn('[TTS settings] Failed to persist data:', error);
                }
            }

            function persistCurrentTtsSettings(projectId) {
                if (!projectId) return;

                const providerSelect = document.getElementById('ttsProviderSelect');
                const styleInput = document.getElementById('ttsStyleInstruction');
                const globalVoiceSelect = document.getElementById('globalVoiceName');
                const globalVoiceGender = document.querySelector('input[name="globalVoiceGender"]:checked')?.value ||
                    'female';

                persistTtsSettings(projectId, {
                    tts_provider: typeof currentTtsProvider !== 'undefined' ? (currentTtsProvider || '') :
                        (providerSelect?.value || ''),
                    audio_mode: typeof currentAudioMode !== 'undefined' ? currentAudioMode :
                        (window.projectData?.audio_mode || 'single'),
                    speakers_config: typeof speakersConfig !== 'undefined' ? speakersConfig :
                        (window.projectData?.speakers_config || []),
                    style_instruction: styleInput?.value || '',
                    global_voice_gender: globalVoiceGender,
                    global_voice_name: globalVoiceSelect?.value || ''
                });
            }

            // Function to enable/disable TTS settings based on provider selection
            function updateTtsSettingsState() {
                const hasProvider = currentTtsProvider && currentTtsProvider !== '';
                console.log('updateTtsSettingsState called. hasProvider:', hasProvider, 'currentTtsProvider:',
                    currentTtsProvider);

                const audioModeContainer = document.getElementById('audioModeContainer');
                const styleInstructionContainer = document.getElementById('styleInstructionContainer');
                const singleSpeakerConfig = document.getElementById('singleSpeakerConfig');
                const multiSpeakerConfig = document.getElementById('multiSpeakerConfig');

                console.log('Containers found:', {
                    audioMode: !!audioModeContainer,
                    styleInstruction: !!styleInstructionContainer,
                    singleSpeaker: !!singleSpeakerConfig,
                    multiSpeaker: !!multiSpeakerConfig
                });

                // Disable/Enable all inputs
                const containers = [audioModeContainer, styleInstructionContainer, singleSpeakerConfig,
                    multiSpeakerConfig
                ];
                containers.forEach(container => {
                    if (!container) return;

                    // Set visual state
                    if (!hasProvider) {
                        container.style.opacity = '0.5';
                        container.style.pointerEvents = 'none';
                        container.style.filter = 'grayscale(50%)';
                    } else {
                        container.style.opacity = '1';
                        container.style.pointerEvents = 'auto';
                        container.style.filter = 'none';
                    }

                    // Disable/enable all input elements inside
                    const inputs = container.querySelectorAll('input, select, textarea, button');
                    inputs.forEach(input => {
                        input.disabled = !hasProvider;
                    });
                });

                // Disable/enable TTS generation buttons in segments
                updateTtsButtonsState();
            }

            // Function to check if a segment is ready for TTS generation
            function isSegmentReadyForTts(segmentIndex) {
                // Must have TTS provider
                if (!currentTtsProvider || currentTtsProvider === '') {
                    return false;
                }

                // Check if segments are loaded
                if (!currentSegments || currentSegments.length === 0) {
                    return false;
                }

                const segment = currentSegments[segmentIndex];
                if (!segment) return false;

                // Check based on audio mode
                if (currentAudioMode === 'single') {
                    // Single speaker: must have voice selected
                    const voiceSelect = document.getElementById('globalVoiceName');
                    return voiceSelect && voiceSelect.value !== '';
                } else if (currentAudioMode === 'multi') {
                    // Multi speaker: segment must have speaker assigned
                    return segment.speaker_name && segment.speaker_name !== '';
                }

                return false;
            }

            // Function to disable/enable TTS generation buttons
            function updateTtsButtonsState() {
                const hasProvider = currentTtsProvider && currentTtsProvider !== '';
                const ttsButtons = document.querySelectorAll('.generate-segment-tts');

                ttsButtons.forEach(btn => {
                    const segmentIndex = parseInt(btn.dataset.index);
                    const isReady = isSegmentReadyForTts(segmentIndex);
                    const isGenerating = btn.dataset.generating === '1';

                    // Keep button clickable so user gets immediate feedback from validation alerts.
                    btn.dataset.ready = isReady ? '1' : '0';
                    btn.disabled = isGenerating;

                    if (!isReady) {
                        btn.style.opacity = '0.5';
                        btn.style.cursor = isGenerating ? 'not-allowed' : 'pointer';
                        if (!hasProvider) {
                            btn.title = 'Vui lòng chọn TTS Provider trước';
                        } else if (currentAudioMode === 'single') {
                            btn.title = 'Vui lòng chọn giọng nói trước';
                        } else {
                            btn.title = 'Vui lòng gán speaker cho segment này';
                        }
                    } else {
                        btn.style.opacity = '1';
                        btn.style.cursor = isGenerating ? 'not-allowed' : 'pointer';
                        btn.title = 'Generate TTS for this segment';
                    }
                });
            }

            // Initialize edit mode when page loads
            document.addEventListener('DOMContentLoaded', function() {
                function isLikelyChineseText(text) {
                    const value = String(text || '').trim();
                    if (!value) return false;
                    return /[\u3400-\u4DBF\u4E00-\u9FFF\uF900-\uFAFF]/.test(value);
                }

                function isLikelyVietnameseText(text) {
                    const value = String(text || '').trim();
                    if (!value) return false;

                    return /[ăâêôơưđáàảãạắằẳẵặấầẩẫậéèẻẽẹếềểễệóòỏõọốồổỗộớờởỡợúùủũụứừửữựíìỉĩịýỳỷỹỵ]/iu
                        .test(value) || /\b(khong|cua|nhung|duoc|trong|mot|voi|la|ban|toi)\b/iu.test(value);
                }

                function normalizeSegmentOrientation(projectData) {
                    if (!projectData || !Array.isArray(projectData.translated_segments) || !Array.isArray(projectData.segments)) {
                        return;
                    }

                    const translated = projectData.translated_segments;
                    const original = projectData.segments;
                    if (translated.length === 0 || original.length === 0) {
                        return;
                    }

                    const sampleSize = Math.min(6, translated.length, original.length);
                    let swapSignals = 0;

                    for (let i = 0; i < sampleSize; i++) {
                        const tSeg = translated[i] || {};
                        const oSeg = original[i] || {};
                        const tText = String(tSeg.text || '').trim();
                        const tOriginal = String(tSeg.original_text || '').trim();
                        const oText = String(oSeg.text || '').trim();

                        if (!tText || !oText) {
                            continue;
                        }

                        // Swapped symptom: translated text is Chinese while original segment text is Vietnamese.
                        if (isLikelyChineseText(tText) && isLikelyVietnameseText(oText)) {
                            swapSignals++;
                            continue;
                        }

                        // Another swapped symptom: translated original_text is Vietnamese.
                        if (tOriginal !== '' && isLikelyVietnameseText(tOriginal) && isLikelyChineseText(tText)) {
                            swapSignals++;
                        }
                    }

                    if (swapSignals < Math.max(1, Math.floor(sampleSize * 0.5))) {
                        return;
                    }

                    projectData.translated_segments = translated.map((seg, index) => {
                        const tSeg = seg || {};
                        const oSeg = original[index] || {};

                        const translatedTextCandidate = String(oSeg.text || '').trim();
                        const originalTextCandidate = String(tSeg.text || '').trim();

                        return {
                            ...tSeg,
                            text: translatedTextCandidate !== '' ? translatedTextCandidate : (tSeg.original_text || tSeg.text || ''),
                            original_text: originalTextCandidate !== '' ? originalTextCandidate : (tSeg.original_text || '')
                        };
                    });

                    console.warn('[edit.blade] Detected swapped transcript orientation. Auto-normalized translated_segments.');
                }

                function coerceSegmentsArray(rawValue) {
                    if (Array.isArray(rawValue)) {
                        return rawValue;
                    }

                    if (rawValue && typeof rawValue === 'object') {
                        // Some legacy records may be saved as keyed objects instead of arrays.
                        return Object.keys(rawValue)
                            .sort((a, b) => Number(a) - Number(b))
                            .map((key) => rawValue[key]);
                    }

                    if (typeof rawValue === 'string' && rawValue.trim() !== '') {
                        try {
                            const parsed = JSON.parse(rawValue);
                            return coerceSegmentsArray(parsed);
                        } catch (e) {
                            console.warn('[edit.blade] Failed to parse segments JSON string:', e);
                        }
                    }

                    return [];
                }

                function normalizeProjectSegmentPayload(projectData) {
                    if (!projectData || typeof projectData !== 'object') {
                        return;
                    }

                    projectData.segments = coerceSegmentsArray(projectData.segments);
                    projectData.translated_segments = coerceSegmentsArray(projectData.translated_segments);
                    projectData.original_transcript = coerceSegmentsArray(projectData.original_transcript);
                }

                let initialSegmentsHydrated = false;
                function getInitialSegmentsFromProjectData() {
                    if (!window.projectData) {
                        return {
                            translated: [],
                            original: [],
                            initial: []
                        };
                    }

                    const translated = coerceSegmentsArray(window.projectData.translated_segments);
                    const original = coerceSegmentsArray(window.projectData.segments);
                    return {
                        translated,
                        original,
                        initial: translated.length > 0 ? translated : original
                    };
                }

                function hydrateInitialSegments() {
                    if (!window.projectData) {
                        return false;
                    }

                    const payload = getInitialSegmentsFromProjectData();
                    if (payload.initial.length === 0) {
                        return false;
                    }

                    try {
                        loadExistingSegments(payload.initial);
                        if (typeof currentSegmentsMode !== 'undefined') {
                            currentSegmentsMode = payload.translated.length > 0 ? 'translated' : 'original';
                        }
                        initialSegmentsHydrated = true;
                        console.log('[edit.blade] Hydrated initial segments:', payload.initial.length);
                        return true;
                    } catch (hydrateError) {
                        console.error('[edit.blade] Failed to hydrate initial segments:', hydrateError);
                        return false;
                    }
                }

                function hydrateInitialSegmentsOnce() {
                    if (initialSegmentsHydrated) {
                        return;
                    }
                    hydrateInitialSegments();
                }

                async function recoverSegmentsFromApiIfNeeded() {
                    const segmentsList = document.getElementById('segmentsList');
                    const hasRenderedSegments = !!(segmentsList && segmentsList.children && segmentsList.children.length > 0);
                    if (hasRenderedSegments || !currentProjectId) {
                        return;
                    }

                    try {
                        const response = await fetch(`/dubsync/projects/${currentProjectId}`, {
                            headers: {
                                'Accept': 'application/json'
                            }
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) {
                            return;
                        }

                        window.projectData.segments = coerceSegmentsArray(data.segments);
                        window.projectData.translated_segments = coerceSegmentsArray(data.translated_segments);
                        hydrateInitialSegments();
                    } catch (apiError) {
                        console.warn('[edit.blade] Failed API recovery for segments:', apiError);
                    }
                }

                function forceSyncVoiceConfigVisibility() {
                    const singleRadio = document.getElementById('singleSpeakerRadio');
                    const multiRadio = document.getElementById('multiSpeakerRadio');
                    const singleConfig = document.getElementById('singleSpeakerConfig');
                    const multiConfig = document.getElementById('multiSpeakerConfig');

                    if (!singleRadio || !multiRadio || !singleConfig || !multiConfig) {
                        return;
                    }

                    // Keep mode in sync with selected radio even if main init flow is interrupted.
                    if (singleRadio.checked) {
                        currentAudioMode = 'single';
                        singleConfig.style.display = 'block';
                        multiConfig.style.display = 'none';
                    } else if (multiRadio.checked) {
                        currentAudioMode = 'multi';
                        singleConfig.style.display = 'none';
                        multiConfig.style.display = 'block';
                    } else {
                        // Safe default
                        currentAudioMode = 'single';
                        singleRadio.checked = true;
                        singleConfig.style.display = 'block';
                        multiConfig.style.display = 'none';
                    }
                }

                function bindVoiceVisibilityFallbackHandlers() {
                    const singleRadio = document.getElementById('singleSpeakerRadio');
                    const multiRadio = document.getElementById('multiSpeakerRadio');
                    if (!singleRadio || !multiRadio) {
                        return;
                    }

                    if (!singleRadio.dataset.voiceFallbackBound) {
                        singleRadio.dataset.voiceFallbackBound = '1';
                        singleRadio.addEventListener('change', forceSyncVoiceConfigVisibility);
                    }
                    if (!multiRadio.dataset.voiceFallbackBound) {
                        multiRadio.dataset.voiceFallbackBound = '1';
                        multiRadio.addEventListener('change', forceSyncVoiceConfigVisibility);
                    }
                }

                // Set current project ID from projectData
                if (window.projectData && window.projectData.id) {
                    currentProjectId = window.projectData.id;
                    console.log('Project ID set to:', currentProjectId);
                }

                normalizeProjectSegmentPayload(window.projectData);
                hydrateInitialSegmentsOnce();

                const persistedTtsSettings = readPersistedTtsSettings(currentProjectId);
                if (persistedTtsSettings) {
                    if (typeof persistedTtsSettings.audio_mode === 'string' && persistedTtsSettings.audio_mode !== '') {
                        window.projectData.audio_mode = persistedTtsSettings.audio_mode;
                    }
                    if (Array.isArray(persistedTtsSettings.speakers_config)) {
                        window.projectData.speakers_config = persistedTtsSettings.speakers_config;
                    }
                    if (typeof persistedTtsSettings.style_instruction === 'string') {
                        window.projectData.style_instruction = persistedTtsSettings.style_instruction;
                    }
                    if (typeof persistedTtsSettings.tts_provider === 'string' && persistedTtsSettings.tts_provider !==
                        '') {
                        window.projectData.tts_provider = persistedTtsSettings.tts_provider;
                    }
                }

                normalizeSegmentOrientation(window.projectData);

                // Set current TTS provider (only if not empty)
                if (window.projectData && window.projectData.tts_provider && window.projectData.tts_provider !== '') {
                    currentTtsProvider = window.projectData.tts_provider;
                }

                const ttsProviderSelect = document.getElementById('ttsProviderSelect');
                if (ttsProviderSelect) {
                    if (!currentTtsProvider && ttsProviderSelect.value) {
                        currentTtsProvider = ttsProviderSelect.value;
                    }
                    ttsProviderSelect.value = currentTtsProvider || '';

                    ttsProviderSelect.addEventListener('change', async function(e) {
                        currentTtsProvider = e.target.value;
                        persistCurrentTtsSettings(currentProjectId);

                        // Update state of other settings
                        updateTtsSettingsState();

                        if (!currentTtsProvider) {
                            return; // Don't proceed if no provider selected
                        }

                        voiceOptionsCache = {};
                        await saveTtsProvider(currentTtsProvider);

                        // Reload voices based on mode
                        if (currentAudioMode === 'single') {
                            const gender = getGlobalVoiceGender();
                            await updateGlobalVoiceOptions(gender);
                        } else {
                            // Reload all speaker voice options
                            for (let i = 0; i < speakersConfig.length; i++) {
                                await updateSpeakerVoiceOptions(i, speakersConfig[i].gender, speakersConfig[
                                    i].voice);
                            }
                        }
                    });
                } else {
                    console.warn('ttsProviderSelect element not found');
                }

                // Initialize audio mode
                initAudioMode();
                bindVoiceVisibilityFallbackHandlers();
                forceSyncVoiceConfigVisibility();

                // Sync enabled/disabled state immediately after mode + provider init.
                updateTtsSettingsState();

                // Set initial state after audio mode is initialized
                // Use longer timeout to ensure DOM is ready
                setTimeout(() => {
                    console.log('Setting initial TTS settings state...');
                    updateTtsSettingsState();
                    forceSyncVoiceConfigVisibility();
                }, 500);

                hydrateInitialSegmentsOnce();

                // Failsafe: if another initializer errors out and list is still empty, retry hydration.
                setTimeout(() => {
                    const segmentsList = document.getElementById('segmentsList');
                    const hasRenderedSegments = !!(segmentsList && segmentsList.children && segmentsList.children.length > 0);
                    if (!hasRenderedSegments) {
                        hydrateInitialSegmentsOnce();
                    }
                    forceSyncVoiceConfigVisibility();
                }, 300);

                // Additional recovery windows for late init errors/races.
                setTimeout(() => {
                    hydrateInitialSegmentsOnce();
                    recoverSegmentsFromApiIfNeeded();
                    forceSyncVoiceConfigVisibility();
                }, 900);

                setTimeout(() => {
                    hydrateInitialSegmentsOnce();
                    recoverSegmentsFromApiIfNeeded();
                    forceSyncVoiceConfigVisibility();
                }, 1800);

                // Style preset buttons
                const styleTextarea = document.getElementById('ttsStyleInstruction');

                // Load existing style instruction
                if (window.projectData && window.projectData.style_instruction) {
                    styleTextarea.value = window.projectData.style_instruction;
                }

                // Style preset button handlers
                document.querySelectorAll('.style-preset-btn').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        if (!styleTextarea) return;
                        styleTextarea.value = btn.dataset.text || '';
                        styleTextarea.focus();
                    });
                });

                // Auto-save style instruction on change
                if (styleTextarea) {
                    let saveTimeout;
                    styleTextarea.addEventListener('input', () => {
                        persistCurrentTtsSettings(currentProjectId);
                        clearTimeout(saveTimeout);
                        saveTimeout = setTimeout(async () => {
                            await saveStyleInstruction(styleTextarea.value);
                        }, 1000); // Save after 1 second of no typing
                    });
                }

                const titleViInput = document.getElementById('youtubeTitleViInput');
                const saveTitleViBtn = document.getElementById('saveTitleViBtn');
                const titleViSaveStatus = document.getElementById('titleViSaveStatus');

                if (titleViInput && saveTitleViBtn) {
                    let isSavingTitleVi = false;
                    const setTitleViStatus = (message, colorClass = 'text-gray-500') => {
                        if (!titleViSaveStatus) return;
                        titleViSaveStatus.classList.remove('hidden', 'text-gray-500', 'text-green-600',
                            'text-red-500');
                        titleViSaveStatus.classList.add(colorClass);
                        titleViSaveStatus.textContent = message;
                    };

                    saveTitleViBtn.addEventListener('click', async () => {
                        if (isSavingTitleVi) return;

                        isSavingTitleVi = true;
                        saveTitleViBtn.disabled = true;
                        saveTitleViBtn.style.opacity = '0.7';
                        setTitleViStatus('Dang luu...', 'text-gray-500');

                        try {
                            const value = titleViInput.value || '';
                            await saveVietnameseTitle(value);
                            setTitleViStatus('Da luu tieu de VI', 'text-green-600');
                        } catch (error) {
                            console.error('Failed to save VI title:', error);
                            setTitleViStatus('Luu that bai', 'text-red-500');
                        } finally {
                            isSavingTitleVi = false;
                            saveTitleViBtn.disabled = false;
                            saveTitleViBtn.style.opacity = '1';
                        }
                    });
                }
            });

            let segmentAutoSaveTimer = null;
            let segmentAutoSaving = false;

            async function autoSaveSegmentsFromTextboxes() {
                if (!currentProjectId || segmentAutoSaving || typeof collectSegments !== 'function') {
                    return;
                }

                const textareas = document.querySelectorAll('.segment-text');
                const hasDirtyTextarea = Array.from(textareas).some((textarea) => {
                    return textarea.dataset.lastSavedValue !== textarea.value;
                });

                if (!hasDirtyTextarea) {
                    return;
                }

                const statusEl = document.getElementById('bulkFixStatus');
                segmentAutoSaving = true;
                if (statusEl) {
                    statusEl.classList.remove('hidden', 'text-red-500');
                    statusEl.classList.add('text-gray-500');
                    statusEl.textContent = 'Dang auto-save...';
                }

                try {
                    const segments = collectSegments();
                    const styleInstruction = document.getElementById('ttsStyleInstruction')?.value || '';
                    const ttsProvider = (typeof currentTtsProvider !== 'undefined' && currentTtsProvider)
                        ? currentTtsProvider
                        : (window.projectData?.tts_provider || null);
                    const audioMode = (typeof currentAudioMode !== 'undefined' && currentAudioMode)
                        ? currentAudioMode
                        : (window.projectData?.audio_mode || null);
                    const speakersConfigPayload = (typeof speakersConfig !== 'undefined' && speakersConfig)
                        ? speakersConfig
                        : (window.projectData?.speakers_config || null);

                    const payload = {
                        tts_provider: ttsProvider,
                        audio_mode: audioMode,
                        speakers_config: speakersConfigPayload,
                        style_instruction: styleInstruction
                    };

                    const isTranslatedMode = (typeof currentSegmentsMode !== 'undefined' && currentSegmentsMode ===
                        'translated');
                    if (isTranslatedMode) {
                        payload.translated_segments = segments;
                    } else {
                        payload.segments = segments;
                    }

                    const response = await fetch(`/dubsync/projects/${currentProjectId}/save-segments`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify(payload)
                    });

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Khong the auto-save segments');
                    }

                    document.querySelectorAll('.segment-text').forEach((textarea) => {
                        textarea.dataset.lastSavedValue = textarea.value;
                    });

                    if (statusEl) {
                        statusEl.classList.remove('hidden', 'text-red-500');
                        statusEl.classList.add('text-green-600');
                        statusEl.textContent = 'Da auto-save';
                        setTimeout(() => {
                            statusEl.classList.add('hidden');
                            statusEl.classList.remove('text-green-600');
                        }, 1200);
                    }
                } catch (error) {
                    console.error('[autoSaveSegmentsFromTextboxes] Error:', error);
                    if (statusEl) {
                        statusEl.classList.remove('hidden', 'text-gray-500');
                        statusEl.classList.add('text-red-500');
                        statusEl.textContent = 'Auto-save that bai';
                    }
                } finally {
                    segmentAutoSaving = false;
                }
            }

            function queueAutoSaveSegments() {
                clearTimeout(segmentAutoSaveTimer);
                segmentAutoSaveTimer = setTimeout(() => {
                    autoSaveSegmentsFromTextboxes();
                }, 250);
            }

            function getLatestSegmentText(segmentIndex, fallbackText = '') {
                const textarea = document.querySelector(`.segment-text[data-index="${segmentIndex}"]`);
                if (textarea) {
                    return textarea.value;
                }

                return fallbackText;
            }

            async function flushLatestSegmentEdits() {
                // Cancel pending debounce so we can flush immediately before TTS.
                clearTimeout(segmentAutoSaveTimer);

                // Sync in-memory model with current UI first.
                if (Array.isArray(currentSegments)) {
                    currentSegments.forEach((segment, index) => {
                        if (!segment) return;
                        segment.text = getLatestSegmentText(index, segment.text || '');
                    });
                }

                // Persist latest edits before any generation request.
                await autoSaveSegmentsFromTextboxes();
            }

            function loadExistingSegments(segments) {
                const segmentsList = document.getElementById('segmentsList');
                if (!segmentsList) return;

                // Store segments globally for later use in translation
                currentSegments = segments;
                console.log('Loaded segments:', segments.length);

                segmentsList.innerHTML = '';

                segments.forEach((segment, index) => {
                    const segmentDiv = document.createElement('div');
                    const speakerName = segment.speaker_name || '';
                    const isMultiMode = currentAudioMode === 'multi';

                    // Apply speaker color if speaker is assigned
                    if (isMultiMode && speakerName) {
                        const speakerIndex = speakersConfig.findIndex(s => s.name === speakerName);
                        if (speakerIndex >= 0) {
                            const speakerColor = getColorForSpeaker(speakerIndex);
                            segmentDiv.className =
                                `${speakerColor.color} border ${speakerColor.border} rounded-lg p-4 relative`;
                        } else {
                            segmentDiv.className = 'bg-white border border-gray-200 rounded-lg p-4 relative';
                        }
                    } else {
                        segmentDiv.className = 'bg-white border border-gray-200 rounded-lg p-4 relative';
                    }

                    segmentDiv.dataset.segmentIndex = index;
                    const startTime = segment.start_time ?? segment.start ?? 0;
                    const endTime = segment.end_time ?? (startTime + (segment.duration ?? 0));
                    const duration = segment.duration || 0;
                    const voiceGender = segment.voice_gender || 'female';
                    const voiceName = segment.voice_name || '';
                    const ttsProvider = segment.tts_provider || '';
                    const hasAudio = segment.audio_path || segment.audio_url;
                    const isAligned = segment.aligned || false;
                    const originalText = (() => {
                        if (segment.original_text && String(segment.original_text).trim() !== '') {
                            return segment.original_text;
                        }

                        const originalTranscript = window.projectData?.original_transcript;
                        if (Array.isArray(originalTranscript) && originalTranscript[index]) {
                            const entry = originalTranscript[index];
                            if (typeof entry === 'string') return entry;
                            if (entry && typeof entry.text === 'string') return entry.text;
                        }

                        const fallbackSegment = (window.projectData && Array.isArray(window.projectData
                                .segments)) ?
                            window.projectData.segments[index] :
                            null;
                        if (fallbackSegment) {
                            return fallbackSegment.original_text || fallbackSegment.text || '';
                        }

                        return segment.text || '';
                    })();

                    // Build TTS info badge
                    let ttsInfoBadge = '';
                    if (hasAudio && ttsProvider) {
                        const providerIcon = ttsProvider === 'gemini' ? '✨' : (ttsProvider === 'openai' ? '🤖' : '🔊');
                        const genderIcon = voiceGender === 'male' ? '👨' : '👩';
                        const providerLabel = ttsProvider.charAt(0).toUpperCase() + ttsProvider.slice(1);
                        ttsInfoBadge =
                            `<span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded font-medium" title="Provider: ${providerLabel}, Voice: ${voiceName}">${providerIcon} ${providerLabel} ${genderIcon} ${voiceName}</span>`;
                    }

                    // Build Aligned status badge
                    let alignedBadge = '';
                    if (isAligned) {
                        const speedRatio = segment.speed_ratio || 1.0;
                        const adjusted = segment.adjusted || false;
                        const speedText = adjusted ? ` (${speedRatio.toFixed(2)}x)` : '';
                        alignedBadge =
                            `<span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded font-medium" title="Audio đã được căn chỉnh${speedText}">✓ Aligned${speedText}</span>`;
                    }

                    segmentDiv.innerHTML = `
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" class="segment-select" data-index="${index}">
                                <span class="text-sm font-medium text-gray-600">Đoạn ${index + 1} (${startTime.toFixed ? startTime.toFixed(2) : startTime}s - ${endTime.toFixed ? endTime.toFixed(2) : endTime}s)</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="tts-progress-${index} hidden">
                                    <div class="w-16 h-1 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="h-full bg-purple-600 animate-pulse" style="width: 100%"></div>
                                    </div>
                                </div>
                                <button type="button" class="delete-segment px-2 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-xs font-medium transition" data-index="${index}" title="Delete this segment">
                                    🗑️ Delete
                                </button>
                                <button type="button" id="segment-tts-${index}" class="generate-segment-tts px-2 py-1 bg-purple-600 hover:bg-purple-700 text-white rounded text-xs font-medium transition" data-index="${index}" title="Generate TTS for this segment">
                                    🎙️ TTS
                                </button>
                                ${hasAudio ? `<button type="button" class="play-segment-audio px-2 py-1 bg-green-600 hover:bg-green-700 text-white rounded text-xs font-medium transition" data-index="${index}" title="Play original audio">▶️ Original</button>` : ''}
                                ${hasAudio ? `<button type="button" class="view-audio-versions px-2 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs font-medium transition" data-index="${index}" title="View all audio versions">📚 Versions</button>` : ''}
                                ${isAligned && hasAudio ? `<button type="button" class="play-aligned-audio px-2 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-xs font-medium transition" data-index="${index}" title="Play aligned audio">▶️ Aligned</button>` : ''}
                                <span class="text-xs text-gray-500">${duration.toFixed ? duration.toFixed(2) : duration}s</span>
                            </div>
                        </div>
                        ${ttsInfoBadge || alignedBadge ? `<div class="mb-2 flex gap-2">${ttsInfoBadge} ${alignedBadge}</div>` : ''}
                        <div class="mb-2">
                            <label class="text-xs text-gray-600 font-medium">Original:</label>
                            <p class="text-sm text-teal-600"><span class="px-1.5 py-0.5 rounded text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200">EN</span> ${originalText}</p>
                        </div>
                        <div class="mb-2">
                            <label class="text-xs text-gray-600 font-medium">Translated:</label>
                        </div>
                        <textarea 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-base font-semibold leading-relaxed focus:ring-2 focus:ring-red-500 focus:border-transparent segment-text"
                            rows="2"
                            data-index="${index}"
                        >${segment.text}</textarea>
                        
                        <div class="mt-3 segment-voice-config" data-index="${index}">
                            ${isMultiMode ? `
                                                                                                                                                                                                                                                <div>
                                                                                                                                                                                                                                                    <label class="block text-xs font-medium text-gray-700 mb-1">Speaker:</label>
                                                                                                                                                                                                                                                    <select class="w-full px-2 py-1 border border-gray-300 rounded text-sm segment-speaker-select" data-index="${index}">
                                                                                                                                                                                                                                                        <option value="">-- Chọn speaker --</option>
                                                                                                                                                                                                                                                    </select>
                                                                                                                                                                                                                                                </div>
                                                                                                                                                                                                                                            ` : `
                                                                                                                                                                                                                                                <div class="text-xs text-gray-500 italic">
                                                                                                                                                                                                                                                    Sử dụng giọng chung cho tất cả segments
                                                                                                                                                                                                                                                </div>
                                                                                                                                                                                                                                            `}
                        </div>
                    `;
                    segmentsList.appendChild(segmentDiv);

                    const translatedTextarea = segmentDiv.querySelector('.segment-text');
                    if (translatedTextarea && typeof autoResizeTextarea === 'function') {
                        autoResizeTextarea(translatedTextarea);
                        translatedTextarea.addEventListener('input', () => autoResizeTextarea(translatedTextarea));
                    }
                    if (translatedTextarea) {
                        translatedTextarea.dataset.lastSavedValue = translatedTextarea.value;
                        translatedTextarea.addEventListener('blur', queueAutoSaveSegments);
                        translatedTextarea.addEventListener('mouseleave', queueAutoSaveSegments);
                    }

                    if (isMultiMode) {
                        // Populate speaker dropdown
                        updateSegmentSpeakerOptions(index, speakerName);

                        // Add speaker change listener
                        const speakerSelect = document.querySelector(
                            `.segment-speaker-select[data-index=\"${index}\"]`);
                        if (speakerSelect) {
                            speakerSelect.addEventListener('change', function() {
                                updateSegmentSpeakerOptions(index, this.value);
                                // Save voice selection
                                const segmentIndex = parseInt(this.dataset.index);
                                if (currentSegments[segmentIndex]) {
                                    currentSegments[segmentIndex].speaker_name = this.value;
                                }
                                // Apply speaker color to segment
                                const selectedSpeakerIndex = speakersConfig.findIndex(s => s.name === this
                                    .value);
                                const segment = document.querySelector(`[data-segment-index=\"${index}\"]`);
                                if (segment && selectedSpeakerIndex >= 0) {
                                    const speakerColor = getColorForSpeaker(selectedSpeakerIndex);
                                    segment.className =
                                        `${speakerColor.color} border ${speakerColor.border} rounded-lg p-4 relative`;
                                } else if (segment && this.value === '') {
                                    segment.className =
                                        'bg-white border border-gray-200 rounded-lg p-4 relative';
                                }
                                // Update TTS button states when speaker is assigned/changed
                                updateTtsButtonsState();
                            });
                        }
                    }

                    // Add segment select listener
                    const segmentCheckbox = segmentDiv.querySelector('.segment-select');
                    if (segmentCheckbox) {
                        segmentCheckbox.addEventListener('change', function() {
                            const selectAll = document.getElementById('selectAllSegments');
                            if (selectAll) {
                                const allCheckboxes = document.querySelectorAll('.segment-select');
                                const checkedCheckboxes = document.querySelectorAll('.segment-select:checked');
                                selectAll.checked = allCheckboxes.length > 0 && checkedCheckboxes.length ===
                                    allCheckboxes.length;
                            }
                            updateGenerateTtsButtonState();
                        });
                    }

                    // Add TTS generation button listener
                    const ttsBtn = segmentDiv.querySelector('.generate-segment-tts');
                    if (ttsBtn) {
                        ttsBtn.addEventListener('click', async function(event) {
                            event.preventDefault();
                            event.stopPropagation();
                            const segmentIndex = parseInt(this.dataset.index);
                            await generateSegmentTTS(segmentIndex);
                        });
                    }

                    // Add delete segment button listener
                    const deleteBtn = segmentDiv.querySelector('.delete-segment');
                    if (deleteBtn) {
                        deleteBtn.addEventListener('click', function(event) {
                            event.preventDefault();
                            event.stopPropagation();
                            const segmentIndex = parseInt(this.dataset.index);
                            if (confirm(`Bạn có chắc muốn xóa đoạn ${segmentIndex + 1}?`)) {
                                deleteSegment(segmentIndex);
                            }
                        });
                    }

                    // Add play audio button listener
                    const playBtn = segmentDiv.querySelector('.play-segment-audio');
                    if (playBtn) {
                        playBtn.addEventListener('click', function(event) {
                            event.preventDefault();
                            event.stopPropagation();
                            const segmentIndex = parseInt(this.dataset.index);
                            playSegmentAudio(segmentIndex);
                        });
                    }

                    // Add play aligned audio button listener
                    const playAlignedBtn = segmentDiv.querySelector('.play-aligned-audio');
                    if (playAlignedBtn) {
                        playAlignedBtn.addEventListener('click', function(event) {
                            event.preventDefault();
                            event.stopPropagation();
                            const segmentIndex = parseInt(this.dataset.index);
                            playAlignedAudio(segmentIndex);
                        });
                    }
                });

                // Init select all handler
                const selectAll = document.getElementById('selectAllSegments');
                if (selectAll) {
                    selectAll.addEventListener('change', function() {
                        document.querySelectorAll('.segment-select').forEach((checkbox) => {
                            checkbox.checked = this.checked;
                        });
                        updateGenerateTtsButtonState();
                    });
                    // Ensure it starts unchecked
                    selectAll.checked = false;
                }

                updateGenerateTtsButtonState();

                if (typeof attachSelectionMoveHandlers === 'function') {
                    attachSelectionMoveHandlers();
                }

                // Update TTS buttons state after loading segments
                updateTtsButtonsState();

                // Delete audios button handler
                const deleteAudiosBtn = document.getElementById('deleteAudiosBtn');
                if (deleteAudiosBtn) {
                    deleteAudiosBtn.addEventListener('click', async function() {
                        const selectedCheckboxes = document.querySelectorAll('.segment-select:checked');
                        if (selectedCheckboxes.length === 0) {
                            alert('Vui lòng chọn các segments cần xóa audio');
                            return;
                        }

                        const segmentIndices = Array.from(selectedCheckboxes).map(cb => parseInt(cb.dataset.index));
                        const confirmMsg =
                            `Bạn chắc chắn muốn xóa audio files cho ${segmentIndices.length} segment(s) được chọn?\n\nSegments: ${segmentIndices.map(i => i + 1).join(', ')}`;

                        if (!confirm(confirmMsg)) {
                            return;
                        }

                        await deleteSegmentAudios(segmentIndices);
                    });
                }

                // Keep Full Transcript (EN) in sync with original segments and auto-save
                const fullTranscriptContent = document.getElementById('fullTranscriptContent');
                if (fullTranscriptContent && typeof generateFullTranscript === 'function') {
                    const generatedFullText = generateFullTranscript();
                    if (generatedFullText && generatedFullText.trim() !== '') {
                        const translatedContent = document.getElementById('translatedFullTranscriptContent');
                        if (typeof saveFullTranscriptToDb === 'function') {
                            clearTimeout(transcriptSaveTimeout);
                            transcriptSaveTimeout = setTimeout(() => {
                                saveFullTranscriptToDb(fullTranscriptContent.value, translatedContent?.value || '');
                            }, 500);
                        }
                    }
                }
                console.log('Loaded', segments.length, 'segments');
            }

            // Play segment audio
            function playSegmentAudio(segmentIndex) {
                const segment = currentSegments[segmentIndex];
                if (!segment || !segment.audio_url) {
                    alert('Audio chưa được tạo cho segment này');
                    return;
                }

                if (currentAudioPlayer) {
                    currentAudioPlayer.pause();
                }

                currentAudioPlayer = new Audio(segment.audio_url);
                currentAudioPlayer.play();

                currentAudioPlayer.addEventListener('ended', function() {
                    currentAudioPlayer = null;
                });
            }

            // Play aligned audio
            function playAlignedAudio(segmentIndex) {
                const segment = currentSegments[segmentIndex];
                if (!segment) {
                    alert('Segment không tìm thấy');
                    return;
                }

                // Check if segment has been aligned
                if (!segment.aligned || !segment.audio_path) {
                    alert('Segment này chưa được căn chỉnh hoặc không có audio');
                    console.log('Segment status:', {
                        aligned: segment.aligned,
                        audio_path: segment.audio_path
                    });
                    return;
                }

                // Construct aligned audio URL from audio_path
                // audio_path is typically: 'projects/xxxxx/segment_0_xxxxx_gemini_aligned.wav'
                // Try multiple URL patterns
                let audioUrl = `/storage/${segment.audio_path}`;

                console.log('Attempting to play aligned audio:', {
                    audio_path: segment.audio_path,
                    audioUrl: audioUrl
                });

                if (currentAudioPlayer) {
                    currentAudioPlayer.pause();
                }

                currentAudioPlayer = new Audio(audioUrl);

                // Add error event listener
                currentAudioPlayer.addEventListener('error', function(e) {
                    console.error('Audio load error details:', {
                        error: e,
                        networkState: currentAudioPlayer.networkState,
                        readyState: currentAudioPlayer.readyState,
                        error: currentAudioPlayer.error
                    });

                    // Try alternative URL path without /storage/ prefix
                    if (audioUrl.startsWith('/storage/')) {
                        const altUrl = segment.audio_path;
                        console.log('Trying alternative URL:', altUrl);

                        const altPlayer = new Audio(altUrl);
                        altPlayer.play()
                            .then(() => {
                                currentAudioPlayer = altPlayer;
                                altPlayer.addEventListener('ended', function() {
                                    currentAudioPlayer = null;
                                });
                            })
                            .catch(err => {
                                alert('Không thể phát audio đã căn chỉnh. Hãy kiểm tra file có tồn tại không.');
                            });
                    } else {
                        alert('Không thể phát audio đã căn chỉnh: Không tìm thấy file');
                    }
                });

                currentAudioPlayer.play()
                    .catch(error => {
                        console.error('Failed to play aligned audio:', error);
                        // Error handler above will try alternative
                    });

                currentAudioPlayer.addEventListener('ended', function() {
                    currentAudioPlayer = null;
                });
            }

            // Delete a segment
            async function deleteSegment(segmentIndex) {
                if (!currentSegments || segmentIndex < 0 || segmentIndex >= currentSegments.length) return;

                // Remove segment from array
                currentSegments.splice(segmentIndex, 1);

                // Save changes to database
                try {
                    const segments = collectSegments();
                    const styleInstruction = document.getElementById('ttsStyleInstruction')?.value || '';
                    const ttsProvider = (typeof currentTtsProvider !== 'undefined' && currentTtsProvider) ?
                        currentTtsProvider :
                        (window.projectData?.tts_provider || null);
                    const audioMode = (typeof currentAudioMode !== 'undefined' && currentAudioMode) ?
                        currentAudioMode :
                        (window.projectData?.audio_mode || null);
                    const speakersConfigPayload = (typeof speakersConfig !== 'undefined' && speakersConfig) ?
                        speakersConfig :
                        (window.projectData?.speakers_config || null);

                    const response = await fetch(`/dubsync/projects/${currentProjectId}/save-segments`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            segments,
                            tts_provider: ttsProvider,
                            audio_mode: audioMode,
                            speakers_config: speakersConfigPayload,
                            style_instruction: styleInstruction
                        })
                    });

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Không thể lưu segments');
                    }

                    // Re-render segments after successful deletion
                    const segmentsList = document.getElementById('segmentsList');
                    if (segmentsList) {
                        // Refresh by reloading the page or re-rendering
                        location.reload();
                    }
                } catch (error) {
                    console.error('[deleteSegment] Error:', error);
                    alert('Đã xóa thất bại: ' + error.message);
                }
            }

            // Delete audio files for selected segments
            async function deleteSegmentAudios(segmentIndices) {
                if (!currentProjectId) {
                    alert('Project ID not found');
                    return;
                }

                try {
                    const deleteBtn = document.getElementById('deleteAudiosBtn');
                    if (deleteBtn) {
                        deleteBtn.disabled = true;
                        deleteBtn.innerHTML = '⏳ Đang xóa...';
                    }

                    const response = await fetch(`/dubsync/projects/${currentProjectId}/delete-segment-audios`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            segment_indices: segmentIndices,
                            delete_all: false
                        })
                    });

                    const data = await response.json();

                    if (!data.success) {
                        throw new Error(data.error || 'Failed to delete audio files');
                    }

                    // Update currentSegments with returned segments
                    if (data.segments) {
                        Object.assign(currentSegments, data.segments);
                    }

                    // Reload segments display
                    loadExistingSegments(currentSegments);

                    // Uncheck all checkboxes
                    document.querySelectorAll('.segment-select').forEach(cb => cb.checked = false);
                    document.getElementById('selectAllSegments').checked = false;
                    updateDeleteAudiosButtonState();

                    alert(`✓ ${data.message}`);

                } catch (error) {
                    console.error('[deleteSegmentAudios] Error:', error);
                    alert('Lỗi xóa audio: ' + error.message);
                } finally {
                    const deleteBtn = document.getElementById('deleteAudiosBtn');
                    if (deleteBtn) {
                        deleteBtn.disabled = false;
                        deleteBtn.innerHTML = '🗑️ Xóa Audio';
                    }
                }
            }

            // Generate TTS for a single segment
            async function generateSegmentTTS(segmentIndex) {
                if (!currentProjectId) {
                    alert('Project ID not found');
                    return;
                }

                await flushLatestSegmentEdits();

                const segment = currentSegments[segmentIndex];
                if (!segment) {
                    alert('Segment not found');
                    return;
                }

                // Get voice settings
                let voiceGender, voiceName;
                if (currentAudioMode === 'multi') {
                    const speakerName = segment.speaker_name;
                    if (!speakerName) {
                        alert('Vui lòng chọn speaker cho segment này');
                        return;
                    }
                    const speaker = speakersConfig.find(s => s.name === speakerName);
                    if (!speaker || !speaker.voice) {
                        alert('Vui lòng chọn voice cho speaker này');
                        return;
                    }
                    voiceGender = speaker.gender;
                    voiceName = speaker.voice;
                } else {
                    const voiceSelect = document.getElementById('globalVoiceName');
                    if (!voiceSelect || !voiceSelect.value) {
                        alert('Vui lòng chọn giọng nói trong TTS Settings');
                        return;
                    }
                    voiceGender = getGlobalVoiceGender();
                    voiceName = voiceSelect.value;
                }

                const styleInstruction = document.getElementById('ttsStyleInstruction')?.value?.trim() || '';
                const latestText = getLatestSegmentText(segmentIndex, segment.text || '');
                segment.text = latestText;
                const textToSend = styleInstruction ? `${styleInstruction}\n\n${latestText}` : latestText;

                const btn = document.querySelector(`.generate-segment-tts[data-index="${segmentIndex}"]`);
                const progressBar = document.querySelector(`.tts-progress-${segmentIndex}`);
                const originalText = btn.innerHTML;

                // Show progress bar
                if (progressBar) {
                    progressBar.classList.remove('hidden');
                }

                btn.innerHTML = '⏳';
                btn.dataset.generating = '1';
                btn.disabled = true;

                try {
                    const response = await fetchWithTimeout(`/dubsync/projects/${currentProjectId}/generate-segment-tts`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            segment_index: segmentIndex,
                            text: textToSend,
                            style_instruction: styleInstruction,
                            voice_gender: voiceGender,
                            voice_name: voiceName,
                            provider: currentTtsProvider
                        })
                    }, 120000);

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Failed to generate TTS');
                    }

                    // Update segment with audio info
                    currentSegments[segmentIndex].audio_path = data.audio_path;
                    currentSegments[segmentIndex].audio_url = data.audio_url;
                    currentSegments[segmentIndex].voice_gender = voiceGender;
                    currentSegments[segmentIndex].voice_name = voiceName;
                    currentSegments[segmentIndex].tts_provider = currentTtsProvider;

                    // Reload segments to show TTS info badge
                    reloadSegments();

                    alert('✅ TTS generated successfully for segment ' + (segmentIndex + 1));

                } catch (error) {
                    console.error('Generate segment TTS error:', error);
                    alert('❌ Lỗi: ' + error.message);
                } finally {
                    // Hide progress bar
                    if (progressBar) {
                        progressBar.classList.add('hidden');
                    }
                    delete btn.dataset.generating;
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    updateTtsButtonsState();
                }
            }

            // Voice options mapping
            var currentTtsProvider; // Will be set from projectData in DOMContentLoaded
            var currentAudioMode = 'single';
            var speakersConfig = [];
            var voiceOptionsCache = {};
            // currentSegments will be set dynamically in loadExistingSegments()

            // Initialize audio mode
            function initAudioMode() {
                if (window.__dubsyncAudioModeInitDone) {
                    return;
                }
                window.__dubsyncAudioModeInitDone = true;

                currentAudioMode = window.projectData?.audio_mode || 'single';
                speakersConfig = window.projectData?.speakers_config || [];

                const singleRadio = document.getElementById('singleSpeakerRadio');
                const multiRadio = document.getElementById('multiSpeakerRadio');

                // Safety check for null elements
                if (!singleRadio || !multiRadio) {
                    return;
                }

                // Set radio buttons
                singleRadio.checked = currentAudioMode === 'single';
                multiRadio.checked = currentAudioMode === 'multi';

                // Show/hide config sections
                toggleAudioModeUI();

                // Load speakers if multi mode
                if (currentAudioMode === 'multi') {
                    loadSpeakers();
                } else {
                    initGlobalVoice();
                }

                // Event listeners
                singleRadio.addEventListener('change', function() {
                    if (this.checked) {
                        currentAudioMode = 'single';
                        toggleAudioModeUI();
                        persistCurrentTtsSettings(currentProjectId);
                        saveAudioMode();
                        reloadSegments();
                    }
                });

                multiRadio.addEventListener('change', function() {
                    if (this.checked) {
                        currentAudioMode = 'multi';
                        toggleAudioModeUI();
                        persistCurrentTtsSettings(currentProjectId);
                        saveAudioMode();
                        reloadSegments();
                    }
                });

                const addSpeakerBtn = document.getElementById('addSpeakerBtn');
                if (addSpeakerBtn) {
                    addSpeakerBtn.addEventListener('click', addSpeaker);
                }
            }

            function toggleAudioModeUI() {
                const singleConfig = document.getElementById('singleSpeakerConfig');
                const multiConfig = document.getElementById('multiSpeakerConfig');

                if (currentAudioMode === 'single') {
                    singleConfig.style.display = 'block';
                    multiConfig.style.display = 'none';
                } else {
                    singleConfig.style.display = 'none';
                    multiConfig.style.display = 'block';
                }
            }

            async function saveAudioMode() {
                if (!currentProjectId) {
                    console.warn('Cannot save audio mode: no project ID');
                    return;
                }

                const url = `/dubsync/projects/${currentProjectId}/audio-mode`;
                console.log('Saving audio mode to:', url, 'Mode:', currentAudioMode);

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            audio_mode: currentAudioMode
                        })
                    });

                    console.log('Response status:', response.status, response.statusText);

                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        const responseText = await response.text();
                        console.error('Response is not JSON. Content-Type:', contentType);
                        console.error('Response body:', responseText.substring(0, 500));
                        throw new Error('Server returned invalid response (HTML instead of JSON)');
                    }

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Không thể lưu audio mode');
                    }

                    console.log('Audio mode saved successfully:', data.audio_mode);
                } catch (error) {
                    console.error('Failed to save audio mode:', error);
                    // Don't alert for auto-save errors, just log
                }
            }

            // Save style instruction
            async function saveStyleInstruction(styleInstruction) {
                if (!currentProjectId) return;

                try {
                    const response = await fetch(`/dubsync/projects/${currentProjectId}/style-instruction`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]').content
                        },
                        body: JSON.stringify({
                            style_instruction: styleInstruction
                        })
                    });

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Không thể lưu style instruction');
                    }
                    console.log('Style instruction saved successfully');
                } catch (error) {
                    console.error('Failed to save style instruction:', error);
                }
            }

            async function saveVietnameseTitle(youtubeTitleVi) {
                if (!currentProjectId) {
                    throw new Error('Project ID khong hop le');
                }

                const response = await fetch(`/dubsync/projects/${currentProjectId}/title-vi`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        youtube_title_vi: youtubeTitleVi
                    })
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Khong the luu tieu de VI');
                }

                return data;
            }

            function getGlobalVoiceGender() {
                const selected = document.querySelector('input[name="globalVoiceGender"]:checked');
                return selected ? selected.value : 'female';
            }

            // Global voice for single-speaker mode
            function initGlobalVoice() {
                const genderRadios = document.querySelectorAll('input[name="globalVoiceGender"]');
                const voiceSelect = document.getElementById('globalVoiceName');
                const previewBtn = document.getElementById('globalVoicePreviewBtn');
                const persisted = readPersistedTtsSettings(currentProjectId) || {};

                if (genderRadios && genderRadios.length > 0) {
                    const persistedGender = persisted.global_voice_gender;
                    if (persistedGender === 'male' || persistedGender === 'female') {
                        genderRadios.forEach((radio) => {
                            radio.checked = radio.value === persistedGender;
                        });
                    }

                    genderRadios.forEach((radio) => {
                        radio.addEventListener('change', async function() {
                            persistCurrentTtsSettings(currentProjectId);
                            await updateGlobalVoiceOptions(getGlobalVoiceGender());
                        });
                    });
                }

                // Add change listener to voice select to update button states
                if (voiceSelect) {
                    voiceSelect.addEventListener('change', function() {
                        persistCurrentTtsSettings(currentProjectId);
                        updateVoiceButtonStates();
                        updateConvertToSpeechButtonState();
                    });
                }

                if (previewBtn) {
                    previewBtn.addEventListener('click', function() {
                        const voice = voiceSelect.value;
                        const gender = getGlobalVoiceGender();
                        if (!voice) {
                            alert('Vui lòng chọn giọng nói trước');
                            return;
                        }
                        previewVoice(gender, voice);
                    });
                }

                const initialGender = getGlobalVoiceGender();
                const initialVoice = typeof persisted.global_voice_name === 'string' ? persisted.global_voice_name :
                    '';
                updateGlobalVoiceOptions(initialGender, initialVoice);
                updateVoiceButtonStates(); // Initialize button states
            }

            // Update Generate TTS button state based on voice + selection
            function updateGenerateTtsButtonState() {
                const voiceSelect = document.getElementById('globalVoiceName');
                const generateTtsBtn = document.getElementById('generateTTSBtn');
                if (!generateTtsBtn) return;

                const hasVoice = voiceSelect && voiceSelect.value;
                const hasSelected = document.querySelectorAll('.segment-select:checked').length > 0;
                const canGenerate = !!hasVoice && hasSelected;

                generateTtsBtn.disabled = !canGenerate;
                generateTtsBtn.style.opacity = canGenerate ? '1' : '0.5';
                generateTtsBtn.style.cursor = canGenerate ? 'pointer' : 'not-allowed';

                // Update delete audios button visibility
                updateDeleteAudiosButtonState();
            }

            // Update delete audios button state based on selection
            function updateDeleteAudiosButtonState() {
                const deleteBtn = document.getElementById('deleteAudiosBtn');
                if (!deleteBtn) return;

                const hasSelected = document.querySelectorAll('.segment-select:checked').length > 0;

                if (hasSelected) {
                    deleteBtn.classList.remove('hidden');
                } else {
                    deleteBtn.classList.add('hidden');
                }
            }

            // Update button states based on voice selection
            function updateVoiceButtonStates() {
                const voiceSelect = document.getElementById('globalVoiceName');
                const previewBtn = document.getElementById('globalVoicePreviewBtn');

                const hasVoice = voiceSelect && voiceSelect.value;

                // Disable/enable preview button
                if (previewBtn) {
                    previewBtn.disabled = !hasVoice;
                    previewBtn.style.opacity = hasVoice ? '1' : '0.5';
                    previewBtn.style.cursor = hasVoice ? 'pointer' : 'not-allowed';
                }

                // Update generate TTS button based on voice + selection
                updateGenerateTtsButtonState();

                // Update segment-level TTS button states as well
                updateTtsButtonsState();
            }

            async function updateGlobalVoiceOptions(gender, selectedVoice = '') {
                const voiceSelect = document.getElementById('globalVoiceName');
                const voices = await fetchAvailableVoices(gender);

                voiceSelect.innerHTML = '<option value="">-- Chọn giọng --</option>';
                for (const [voiceCode, voiceLabel] of Object.entries(voices)) {
                    const option = document.createElement('option');
                    option.value = voiceCode;
                    option.textContent = voiceLabel;
                    if (voiceCode === selectedVoice) {
                        option.selected = true;
                    }
                    voiceSelect.appendChild(option);
                }

                // Update button states after loading voices
                updateVoiceButtonStates();
            }

            // Speaker colors and icons
            const speakerColors = [{
                    color: 'bg-red-100',
                    border: 'border-red-300',
                    text: 'text-red-900',
                    icon: '👩'
                },
                {
                    color: 'bg-blue-100',
                    border: 'border-blue-300',
                    text: 'text-blue-900',
                    icon: '👨'
                },
                {
                    color: 'bg-green-100',
                    border: 'border-green-300',
                    text: 'text-green-900',
                    icon: '🧑'
                },
                {
                    color: 'bg-yellow-100',
                    border: 'border-yellow-300',
                    text: 'text-yellow-900',
                    icon: '👴'
                },
                {
                    color: 'bg-purple-100',
                    border: 'border-purple-300',
                    text: 'text-purple-900',
                    icon: '👵'
                },
                {
                    color: 'bg-pink-100',
                    border: 'border-pink-300',
                    text: 'text-pink-900',
                    icon: '👧'
                },
                {
                    color: 'bg-indigo-100',
                    border: 'border-indigo-300',
                    text: 'text-indigo-900',
                    icon: '👦'
                },
                {
                    color: 'bg-orange-100',
                    border: 'border-orange-300',
                    text: 'text-orange-900',
                    icon: '🧔'
                }
            ];

            function getColorForSpeaker(index) {
                return speakerColors[index % speakerColors.length];
            }

            // Multi-speaker management
            function loadSpeakers() {
                const speakersList = document.getElementById('speakersList');
                speakersList.innerHTML = '';

                if (speakersConfig.length === 0) {
                    speakersConfig = [{
                        name: 'Speaker 1',
                        gender: 'female',
                        voice: ''
                    }];
                }

                speakersConfig.forEach((speaker, index) => {
                    addSpeakerUI(speaker, index);
                });
            }

            function addSpeaker() {
                const newSpeaker = {
                    name: `Speaker ${speakersConfig.length + 1}`,
                    gender: 'female',
                    voice: ''
                };
                speakersConfig.push(newSpeaker);
                addSpeakerUI(newSpeaker, speakersConfig.length - 1);
                saveSpeakersConfig();
            }

            async function addSpeakerUI(speaker, index) {
                const speakersList = document.getElementById('speakersList');
                const speakerDiv = document.createElement('div');
                const speakerColor = getColorForSpeaker(index);

                speakerDiv.className = `${speakerColor.color} p-4 rounded border-2 ${speakerColor.border}`;
                speakerDiv.dataset.speakerIndex = index;

                speakerDiv.innerHTML = `
                    <div class=\"flex items-center gap-2 mb-4 pb-3 border-b-2 border-current border-opacity-30\">
                        <span class=\"text-base font-bold ${speakerColor.text}\">Speaker ${index + 1}</span>
                        <input type=\"text\" class=\"speaker-name flex-1 px-3 py-2 border border-gray-400 rounded text-sm focus:border-blue-500 focus:outline-none\"
                            value=\"${speaker.name}\" data-index=\"${index}\" placeholder=\"Name\">
                    </div>

                        <div class=\"flex items-end gap-3\">
                            <div>
                                <label class=\"block text-xs font-semibold text-gray-700 mb-2\">Gender:</label>
                                <select class=\"speaker-gender px-3 py-2 border border-gray-400 rounded text-sm focus:border-blue-500 focus:outline-none\" data-index=\"${index}\">
                                    <option value=\"female\" ${speaker.gender === 'female' ? 'selected' : ''}>👩 Nữ</option>
                                    <option value=\"male\" ${speaker.gender === 'male' ? 'selected' : ''}>👨 Nam</option>
                                </select>
                            </div>
                            
                            <div class=\"flex-1\">
                                <label class=\"block text-xs font-semibold text-gray-700 mb-2\">Voice:</label>
                                <div class=\"flex gap-2 items-center\">
                                    <select class=\"speaker-voice flex-1 px-3 py-2 border border-gray-400 rounded text-sm focus:border-blue-500 focus:outline-none\" data-index=\"${index}\">
                                        <option value=\"\">-- Chọn --</option>
                                    </select>
                                    <button type=\"button\" class=\"preview-speaker-voice px-3 py-2 bg-green-600 hover:bg-green-700 text-white rounded text-sm font-medium whitespace-nowrap\" data-index=\"${index}\" title=\"Nghe thử giọng\">
                                        🔊
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <button type=\"button\" class=\"apply-speaker-to-all mt-3 w-full px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-medium transition\" data-index=\"${index}\" title=\"Áp dụng speaker này cho tất cả segments\">
                            ✨ Apply to All Segments
                        </button>
                        
                        <button type=\"button\" class=\"remove-speaker mt-2 text-sm text-red-600 hover:text-red-800 font-semibold\" data-index=\"${index}\">
                            × Remove Speaker
                        </button>
                    </div>    
                `;

                speakersList.appendChild(speakerDiv);

                // Populate voice dropdown
                await updateSpeakerVoiceOptions(index, speaker.gender, speaker.voice);

                // Event listeners
                speakerDiv.querySelector('.speaker-name').addEventListener('change', function() {
                    speakersConfig[index].name = this.value;
                    saveSpeakersConfig();
                    reloadSegments(); // Refresh speaker names in segment dropdowns
                });

                speakerDiv.querySelector('.speaker-gender').addEventListener('change', async function() {
                    speakersConfig[index].gender = this.value;
                    await updateSpeakerVoiceOptions(index, this.value);
                    saveSpeakersConfig();
                });

                speakerDiv.querySelector('.speaker-voice').addEventListener('change', function() {
                    speakersConfig[index].voice = this.value;
                    saveSpeakersConfig();
                    updateConvertToSpeechButtonState();
                });

                speakerDiv.querySelector('.apply-speaker-to-all').addEventListener('click', function() {
                    const speakerIndex = parseInt(this.dataset.index);
                    applySpeakerToAllSegments(speakerIndex);
                });

                speakerDiv.querySelector('.remove-speaker').addEventListener('click', function() {
                    speakersConfig.splice(index, 1);
                    loadSpeakers();
                    saveSpeakersConfig();
                    reloadSegments();
                });

                speakerDiv.querySelector('.preview-speaker-voice').addEventListener('click', function() {
                    const voiceSelect = speakerDiv.querySelector('.speaker-voice');
                    const genderSelect = speakerDiv.querySelector('.speaker-gender');
                    const voice = voiceSelect.value;
                    const gender = genderSelect.value;
                    if (!voice) {
                        alert('Vui lòng chọn giọng nói trước');
                        return;
                    }
                    previewVoice(gender, voice);
                });
            }

            // Apply speaker to all segments
            function applySpeakerToAllSegments(speakerIndex) {
                if (!speakersConfig[speakerIndex]) {
                    alert('Speaker không tồn tại');
                    return;
                }

                const speaker = speakersConfig[speakerIndex];
                if (!speaker.voice) {
                    alert('Vui lòng chọn voice cho speaker này trước');
                    return;
                }

                // Apply to all segments in currentSegments
                if (currentSegments && currentSegments.length > 0) {
                    currentSegments.forEach((segment, index) => {
                        segment.speaker_name = speaker.name;
                    });

                    // Reload segments to reflect changes
                    reloadSegments();

                    alert(`✅ Đã áp dụng "${speaker.name}" cho tất cả ${currentSegments.length} segments`);
                } else {
                    alert('Không có segments nào để áp dụng');
                }
            }

            async function updateSpeakerVoiceOptions(speakerIndex, gender, selectedVoice = '') {
                const voiceSelect = document.querySelector(`.speaker-voice[data-index=\"${speakerIndex}\"]`);
                if (!voiceSelect) return;

                const voices = await fetchAvailableVoices(gender);
                voiceSelect.innerHTML = '<option value=\"\">-- Chọn --</option>';

                for (const [voiceCode, voiceLabel] of Object.entries(voices)) {
                    const option = document.createElement('option');
                    option.value = voiceCode;
                    option.textContent = voiceLabel;
                    if (voiceCode === selectedVoice) {
                        option.selected = true;
                    }
                    voiceSelect.appendChild(option);
                }
            }

            async function saveSpeakersConfig() {
                if (!currentProjectId) return;

                try {
                    const response = await fetch(`/dubsync/projects/${currentProjectId}/speakers-config`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]').content
                        },
                        body: JSON.stringify({
                            speakers_config: speakersConfig
                        })
                    });

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Không thể lưu speakers config');
                    }

                    persistCurrentTtsSettings(currentProjectId);
                } catch (error) {
                    console.error('Failed to save speakers config:', error);
                }
            }

            function updateSegmentSpeakerOptions(segmentIndex, selectedSpeaker = '') {
                const speakerSelect = document.querySelector(`.segment-speaker-select[data-index=\"${segmentIndex}\"]`);
                if (!speakerSelect) return;

                speakerSelect.innerHTML = '<option value=\"\">-- Chọn speaker --</option>';

                speakersConfig.forEach((speaker, speakerIndex) => {
                    const speakerColor = getColorForSpeaker(speakerIndex);
                    const option = document.createElement('option');
                    option.value = speaker.name;
                    option.textContent = `${speakerColor.icon} ${speaker.name}`;
                    option.style.backgroundColor = speakerColor.color.replace('bg-', '').replace('-100', '-200');
                    if (speaker.name === selectedSpeaker) {
                        option.selected = true;
                    }
                    speakerSelect.appendChild(option);
                });

                // Add inline styles to show speaker color when selected
                const selectedIndex = speakersConfig.findIndex(s => s.name === selectedSpeaker);
                if (selectedIndex >= 0) {
                    const speakerColor = getColorForSpeaker(selectedIndex);
                    speakerSelect.style.backgroundColor = speakerColor.color.replace('100', '200');
                    speakerSelect.style.fontWeight = 'bold';
                }
            }

            function reloadSegments() {
                if (currentSegments && currentSegments.length > 0) {
                    loadExistingSegments(currentSegments);
                }
            }

            async function saveTtsProvider(provider) {
                if (!currentProjectId) return;

                try {
                    const response = await fetch(`/dubsync/projects/${currentProjectId}/tts-provider`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            tts_provider: provider
                        })
                    });

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Không thể lưu TTS provider');
                    }

                    persistCurrentTtsSettings(currentProjectId);
                } catch (error) {
                    console.error('Failed to save TTS provider:', error);
                    alert('Lỗi: ' + error.message);
                }
            }

            // Voice preview functionality
            const sampleTexts = {
                'vi': 'Xin chào, đây là giọng nói tiếng Việt của tôi. Rất vui được gặp bạn!',
                'en': 'Hello, this is my Vietnamese voice. Nice to meet you!'
            };

            async function previewVoice(gender, voiceName) {
                if (!voiceName) {
                    alert('Vui lòng chọn giọng nói trước');
                    return;
                }

                const sampleText = sampleTexts['vi'];

                try {
                    // Stop current audio if playing
                    if (currentAudioPlayer) {
                        currentAudioPlayer.pause();
                        currentAudioPlayer = null;
                    }

                    // Show loading state
                    const btn = document.getElementById('globalVoicePreviewBtn');
                    const originalContent = btn.innerHTML;
                    btn.innerHTML = '⏳';
                    btn.disabled = true;

                    const response = await fetch('/preview-voice', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            text: sampleText,
                            voice_gender: gender,
                            voice_name: voiceName,
                            provider: currentTtsProvider
                        })
                    });

                    btn.innerHTML = originalContent;
                    updateVoiceButtonStates();

                    if (!response.ok) {
                        let errorMessage = 'Server error: ' + response.status;
                        try {
                            const errorData = await response.json();
                            if (errorData && (errorData.error || errorData.message)) {
                                errorMessage = errorData.error || errorData.message;
                            }
                        } catch (e) {
                            const errorText = await response.text();
                            console.error('Preview error response:', errorText);
                        }
                        throw new Error(errorMessage);
                    }

                    const data = await response.json();

                    if (!data.success) {
                        throw new Error(data.error || 'Không thể tạo preview');
                    }

                    // Play audio
                    currentAudioPlayer = new Audio(data.audio_url);
                    currentAudioPlayer.play();

                    currentAudioPlayer.addEventListener('ended', function() {
                        currentAudioPlayer = null;
                    });

                } catch (error) {
                    console.error('Preview voice error:', error);
                    alert('Lỗi: ' + error.message);
                    updateVoiceButtonStates();
                }
            }

            // Fetch available voices for a gender
            async function fetchAvailableVoices(gender) {
                const cacheKey = `${currentTtsProvider}:${gender}`;
                if (voiceOptionsCache[cacheKey]) {
                    return voiceOptionsCache[cacheKey];
                }

                try {
                    const response = await fetch(`/get-available-voices?gender=${gender}&provider=${currentTtsProvider}`);

                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    const contentType = response.headers.get("content-type");
                    if (!contentType || !contentType.includes("application/json")) {
                        const text = await response.text();
                        console.error('Non-JSON response:', text.substring(0, 200));
                        throw new Error("Server returned non-JSON response");
                    }

                    const data = await response.json();

                    if (data.success) {
                        voiceOptionsCache[cacheKey] = data.voices[gender] || {};
                        return voiceOptionsCache[cacheKey];
                    } else {
                        console.error('API returned success=false:', data);
                        throw new Error(data.error || 'Failed to fetch voices');
                    }
                } catch (error) {
                    console.error('Failed to fetch voices:', error);
                    alert('Không thể tải danh sách giọng nói. Vui lòng thử lại.');
                }

                return {};
            }

            // Update voice options based on gender
            async function updateVoiceOptions(segmentIndex, gender, selectedVoice = '') {
                const voiceNameSelect = document.querySelector(`.segment-voice-name[data-index="${segmentIndex}"]`);
                if (!voiceNameSelect) return;

                const voices = await fetchAvailableVoices(gender);

                // Clear existing options
                voiceNameSelect.innerHTML = '<option value="">-- Chọn giọng --</option>';

                // Add voice options
                for (const [voiceCode, voiceLabel] of Object.entries(voices)) {
                    const option = document.createElement('option');
                    option.value = voiceCode;
                    option.textContent = voiceLabel;
                    if (voiceCode === selectedVoice) {
                        option.selected = true;
                    }
                    voiceNameSelect.appendChild(option);
                }

                voiceNameSelect.setAttribute('data-gender', gender);
            }

            // Store voice selection in segment data
            function saveVoiceSelections() {
                if (!currentSegments) return;

                document.querySelectorAll('.segment-voice-gender').forEach(select => {
                    const index = parseInt(select.dataset.index);
                    const voiceGender = select.value;
                    const voiceName = document.querySelector(`.segment-voice-name[data-index="${index}"]`)?.value || '';

                    if (currentSegments[index]) {
                        currentSegments[index].voice_gender = voiceGender;
                        currentSegments[index].voice_name = voiceName;
                    }
                });
            }

            // Override the original saveSegments to include voice data
            const originalSaveSegments = window.saveSegments;
            window.saveSegments = async function() {
                saveVoiceSelections();
                if (originalSaveSegments) {
                    return originalSaveSegments.apply(this, arguments);
                }
            };

            // REMOVED: Old generateTTS override that was causing duplicate TTS generation
            // The button now ONLY calls generateTTSForSelectedSegments()

            // Helper function to fetch with timeout
            function fetchWithTimeout(url, options, timeoutMs = 120000) {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

                return fetch(url, {
                        ...options,
                        signal: controller.signal
                    })
                    .then(response => {
                        clearTimeout(timeoutId);
                        return response;
                    })
                    .catch(error => {
                        clearTimeout(timeoutId);
                        if (error.name === 'AbortError') {
                            throw new Error(`Timeout sau ${timeoutMs / 1000} giây - TTS API quá chậm`);
                        }
                        throw error;
                    });
            }

            let isGeneratingTTS = false; // Flag to prevent multiple concurrent TTS generation
            let ttsBatchPollTimer = null;

            const TTS_BATCH_ERROR_PREVIEW_LIMIT = 8;
            let ttsBatchShowAllErrors = false;

            const htmlEscape = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const parseSegmentError = (rawError) => {
                const text = String(rawError ?? '');
                const matched = text.match(/^\s*Segment\s+(\d+)\s*:\s*(.*)$/i);
                if (!matched) {
                    return {
                        segmentIndex: null,
                        segmentLabel: 'N/A',
                        message: text || 'Unknown error'
                    };
                }

                return {
                    segmentIndex: Number(matched[1]),
                    segmentLabel: matched[1],
                    message: matched[2] || 'Unknown error'
                };
            };

            const resetTtsBatchErrors = () => {
                ttsBatchShowAllErrors = false;
                const panel = document.getElementById('ttsBatchErrorPanel');
                const summary = document.getElementById('ttsBatchErrorSummary');
                const list = document.getElementById('ttsBatchErrorList');
                const toggle = document.getElementById('ttsBatchErrorToggle');

                if (summary) {
                    summary.textContent = '';
                }
                if (list) {
                    list.innerHTML = '';
                }
                if (toggle) {
                    toggle.classList.add('hidden');
                    toggle.textContent = 'Xem them';
                }
                if (panel) {
                    panel.classList.add('hidden');
                }
            };

            const renderTtsBatchErrors = (errors, failedCount = 0) => {
                const panel = document.getElementById('ttsBatchErrorPanel');
                const summary = document.getElementById('ttsBatchErrorSummary');
                const list = document.getElementById('ttsBatchErrorList');
                const toggle = document.getElementById('ttsBatchErrorToggle');

                if (!panel || !summary || !list || !toggle) {
                    return;
                }

                const normalizedErrors = Array.isArray(errors) ? errors.filter(Boolean).map(parseSegmentError) : [];
                const totalErrors = normalizedErrors.length;
                const displayCount = ttsBatchShowAllErrors ? totalErrors : Math.min(totalErrors, TTS_BATCH_ERROR_PREVIEW_LIMIT);

                if (totalErrors === 0 && Number(failedCount || 0) === 0) {
                    resetTtsBatchErrors();
                    return;
                }

                panel.classList.remove('hidden');
                const countLabel = Number(failedCount || totalErrors || 0);
                summary.textContent = `Chi tiet loi theo segment (${countLabel} loi)`;

                const visibleErrors = normalizedErrors.slice(0, displayCount);
                list.innerHTML = visibleErrors.map((err) => {
                    const indexHtml = err.segmentIndex !== null ? `<span class="font-semibold">Segment ${htmlEscape(err.segmentLabel)}</span>` : '<span class="font-semibold">Segment N/A</span>';
                    return `<li class="rounded border border-amber-200 bg-white px-2 py-1">${indexHtml}: ${htmlEscape(err.message)}</li>`;
                }).join('');

                if (totalErrors > TTS_BATCH_ERROR_PREVIEW_LIMIT) {
                    toggle.classList.remove('hidden');
                    const hiddenCount = totalErrors - TTS_BATCH_ERROR_PREVIEW_LIMIT;
                    toggle.textContent = ttsBatchShowAllErrors ? 'Thu gon' : `Xem them ${hiddenCount} loi`;
                    toggle.onclick = () => {
                        ttsBatchShowAllErrors = !ttsBatchShowAllErrors;
                        renderTtsBatchErrors(errors, failedCount);
                    };
                } else {
                    toggle.classList.add('hidden');
                    toggle.onclick = null;
                }
            };

            const stopTtsBatchPolling = () => {
                if (ttsBatchPollTimer) {
                    clearInterval(ttsBatchPollTimer);
                    ttsBatchPollTimer = null;
                }
            };

            // Generate TTS for all selected segments (PARALLEL processing for speed)
            async function generateTTSForSelectedSegments(event) {
                // Prevent double-click
                if (isGeneratingTTS) {
                    console.warn('⚠️ TTS generation already in progress, ignoring duplicate request');
                    return;
                }

                // Prevent any default button behavior
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }

                if (!currentProjectId) {
                    alert('Project ID not found');
                    isGeneratingTTS = false;
                    return;
                }

                // Set flag to prevent concurrent calls
                isGeneratingTTS = true;
                const generateBtn = document.getElementById('generateTTSBtn');
                if (generateBtn) {
                    generateBtn.disabled = true;
                }

                const unlockGenerateTTS = () => {
                    isGeneratingTTS = false;
                    const btn = document.getElementById('generateTTSBtn');
                    if (btn) {
                        btn.disabled = false;
                    }
                };

                // Get all selected segment indices
                const selectedCheckboxes = document.querySelectorAll('.segment-select:checked');
                const selectedIndices = Array.from(selectedCheckboxes).map(checkbox => parseInt(checkbox.dataset.index));

                if (selectedIndices.length === 0) {
                    alert('Vui lòng chọn ít nhất một segment trước');
                    unlockGenerateTTS();
                    return;
                }

                // Fast path: if only one segment is selected, reuse single-segment flow
                // so users don't wait on bulk progress simulation at 90%.
                if (selectedIndices.length === 1) {
                    try {
                        await generateSegmentTTS(selectedIndices[0]);
                    } finally {
                        unlockGenerateTTS();
                    }
                    return;
                }

                // Debug: Show which segments were selected
                console.log(`🎯 Selected segments: ${selectedIndices.join(', ')} (Total: ${selectedIndices.length})`);
                console.log(`📊 Total segments in project: ${currentSegments.length}`);

                // CRITICAL DEBUG: Log all checkboxes state
                const allCheckboxes = document.querySelectorAll('.segment-select');
                const checkboxStates = {};
                allCheckboxes.forEach(cb => {
                    const idx = parseInt(cb.dataset.index);
                    checkboxStates[idx] = cb.checked;
                });
                console.log('📋 ALL CHECKBOX STATES:', checkboxStates);
                console.log('✅ CHECKED CHECKBOXES:', Object.keys(checkboxStates).filter(k => checkboxStates[k]));


                // Show progress bar
                const progressContainer = document.getElementById('progressContainer');
                const progressBar = document.getElementById('progressBar');
                const progressPercent = document.getElementById('progressPercent');
                const progressLabel = document.getElementById('progressLabel');

                progressContainer.classList.remove('hidden');
                progressBar.style.width = '1%';
                progressPercent.textContent = '1%';
                resetTtsBatchErrors();
                if (progressLabel) {
                    progressLabel.textContent = `Đang xếp hàng tạo TTS cho ${selectedIndices.length} đoạn...`;
                }

                const totalCount = selectedIndices.length;
                let keepLockedForAsync = false;

                try {
                    await flushLatestSegmentEdits();

                    // Ensure voice selected in single mode
                    if (currentAudioMode === 'single') {
                        const voiceSelect = document.getElementById('globalVoiceName');
                        if (!voiceSelect || !voiceSelect.value) {
                            alert('Vui lòng chọn giọng nói trong TTS Settings');
                            return;
                        }
                    }

                    // Prepare request payload with voice settings for each segment
                    const voiceSettingsMap = {};
                    const segmentTextsMap = {};

                    selectedIndices.forEach(segmentIndex => {
                        const segment = currentSegments[segmentIndex];
                        let voiceGender = 'female';
                        let voiceName = '';

                        // Always send the exact text currently shown/edited in UI for this segment.
                        const latestText = getLatestSegmentText(segmentIndex, segment?.text || '');
                        if (segment) {
                            segment.text = latestText;
                        }
                        segmentTextsMap[segmentIndex] = latestText;

                        if (currentAudioMode === 'multi') {
                            const speakerName = segment.speaker_name;
                            if (speakerName) {
                                const speaker = speakersConfig.find(s => s.name === speakerName);
                                if (speaker) {
                                    voiceGender = speaker.gender || 'female';
                                    voiceName = speaker.voice || '';
                                }
                            }
                        } else {
                            const voiceSelect = document.getElementById('globalVoiceName');
                            voiceGender = getGlobalVoiceGender();
                            if (voiceSelect) voiceName = voiceSelect.value;
                        }

                        voiceSettingsMap[segmentIndex] = {
                            voice_gender: voiceGender,
                            voice_name: voiceName
                        };
                    });

                    const styleInstruction = document.getElementById('ttsStyleInstruction')?.value?.trim() || '';

                    console.log(`Bắt đầu tạo TTS cho ${totalCount} segments: ${selectedIndices.join(', ')}`);

                    // Start async queue job for bulk TTS
                    const response = await fetch(
                        `/dubsync/projects/${currentProjectId}/generate-segment-tts`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                segment_indices: selectedIndices,
                                segment_texts: segmentTextsMap,
                                voice_settings: voiceSettingsMap,
                                style_instruction: styleInstruction,
                                provider: currentTtsProvider,
                                async: true
                            })
                        }
                    );

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Lỗi tạo TTS');
                    }

                    const applySegmentsData = (segmentsData) => {
                        if (!segmentsData) return;
                        Object.keys(segmentsData).forEach(indexStr => {
                            const idx = parseInt(indexStr);
                            if (currentSegments[idx] && segmentsData[indexStr]) {
                                const segmentData = segmentsData[indexStr];
                                currentSegments[idx].audio_path = segmentData.audio_path;
                                currentSegments[idx].audio_url = segmentData.audio_url;
                                currentSegments[idx].tts_provider = segmentData.tts_provider || currentTtsProvider;
                                currentSegments[idx].voice_gender = segmentData.voice_gender;
                                currentSegments[idx].voice_name = segmentData.voice_name;
                            }
                        });
                    };

                    const pollProgress = async () => {
                        try {
                            const progressResp = await fetch(`/dubsync/projects/${currentProjectId}/generate-segment-tts-progress`, {
                                method: 'GET',
                                headers: {
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                                }
                            });

                            const progressData = await progressResp.json();
                            const status = progressData.status || 'processing';
                            const pctRaw = Number(progressData.percent ?? 0);
                            const pct = Number.isFinite(pctRaw) ? Math.max(0, Math.min(100, Math.floor(pctRaw))) : 0;

                            progressBar.style.width = `${pct}%`;
                            progressPercent.textContent = `${pct}%`;

                            if (progressLabel) {
                                const msg = progressData.message || 'Đang xử lý TTS...';
                                progressLabel.textContent = msg;
                            }

                            renderTtsBatchErrors(progressData.errors || [], progressData.failed_count || 0);

                            if (status === 'completed' || status === 'completed_with_errors') {
                                stopTtsBatchPolling();
                                applySegmentsData(progressData.segments_data || {});
                                reloadSegments();
                                unlockGenerateTTS();

                                if (status === 'completed_with_errors') {
                                    const errCount = Number(progressData.failed_count || 0);
                                    alert(`⚠️ Batch TTS hoàn tất nhưng có ${errCount} lỗi. Kiểm tra log hoặc thử lại các đoạn lỗi.`);
                                }

                                setTimeout(() => {
                                    progressContainer.classList.add('hidden');
                                    progressBar.style.width = '0%';
                                    progressPercent.textContent = '0%';
                                }, 1500);
                            } else if (status === 'error' || status === 'failed') {
                                stopTtsBatchPolling();
                                const errMsg = progressData.message || 'Batch TTS thất bại';
                                progressLabel.textContent = `❌ ${errMsg}`;
                                progressBar.style.width = '100%';
                                progressPercent.textContent = '❌';
                                unlockGenerateTTS();
                                alert('❌ Lỗi tạo TTS: ' + errMsg);
                            }
                        } catch (pollError) {
                            console.warn('Poll TTS batch progress failed:', pollError);
                        }
                    };

                    stopTtsBatchPolling();
                    await pollProgress();
                    ttsBatchPollTimer = setInterval(pollProgress, 2000);
                    keepLockedForAsync = true;

                } catch (error) {
                    console.error('Batch TTS generation error:', error);
                    stopTtsBatchPolling();

                    // Update progress bar to show error
                    progressBar.style.width = '100%';
                    progressPercent.textContent = '❌';
                    if (progressLabel) {
                        progressLabel.textContent = `❌ Lỗi: ${error.message}`;
                    }

                    alert('❌ Lỗi tạo TTS: ' + error.message);

                    // Hide progress after 3 seconds
                    setTimeout(() => {
                        progressContainer.classList.add('hidden');
                    }, 3000);

                    unlockGenerateTTS();
                } finally {
                    if (!keepLockedForAsync) {
                        unlockGenerateTTS();
                    }
                }
            }

            // TTS Settings Collapse/Expand
            document.addEventListener('DOMContentLoaded', function() {
                const ttsToggleBtn = document.getElementById('ttsToggleBtn');
                const ttsContent = document.getElementById('ttsContent');
                const ttsToggleIcon = document.getElementById('ttsToggleIcon');
                let isExpanded = true;

                const getTranscriptForm = document.getElementById('getTranscriptForm');
                const getTranscriptBtn = document.getElementById('getTranscriptBtn');
                const getTranscriptStatus = document.getElementById('getTranscriptStatus');

                if (getTranscriptForm && getTranscriptBtn) {
                    getTranscriptForm.addEventListener('submit', async function(event) {
                        event.preventDefault();

                        const asyncUrl = getTranscriptForm.dataset.asyncAction || getTranscriptForm.action;
                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const originalBtnText = getTranscriptBtn.textContent;

                        getTranscriptBtn.disabled = true;
                        getTranscriptBtn.classList.add('opacity-70', 'cursor-not-allowed');
                        getTranscriptBtn.textContent = 'Dang lay transcript...';

                        if (getTranscriptStatus) {
                            getTranscriptStatus.classList.remove('hidden', 'border-red-200', 'bg-red-50',
                                'text-red-700', 'border-green-200', 'bg-green-50', 'text-green-700');
                            getTranscriptStatus.classList.add('border-blue-200', 'bg-blue-50', 'text-blue-700');
                            getTranscriptStatus.textContent =
                                'Dang xu ly transcript, vui long doi trong giay lat...';
                        }

                        try {
                            const response = await fetch(asyncUrl, {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            });

                            let data = {};
                            try {
                                data = await response.json();
                            } catch (jsonError) {
                                data = {
                                    error: 'Phan hoi tu server khong hop le.'
                                };
                            }

                            if (!response.ok || !data.success) {
                                throw new Error(data.error || 'Khong the lay transcript.');
                            }

                            if (getTranscriptStatus) {
                                getTranscriptStatus.classList.remove('border-blue-200', 'bg-blue-50',
                                    'text-blue-700', 'border-red-200', 'bg-red-50', 'text-red-700');
                                getTranscriptStatus.classList.add('border-green-200', 'bg-green-50', 'text-green-700');
                                getTranscriptStatus.textContent =
                                    'Lay transcript thanh cong. Dang tai lai trang...';
                            }

                            setTimeout(() => {
                                window.location.reload();
                            }, 500);
                        } catch (error) {
                            if (getTranscriptStatus) {
                                getTranscriptStatus.classList.remove('border-blue-200', 'bg-blue-50',
                                    'text-blue-700', 'border-green-200', 'bg-green-50', 'text-green-700');
                                getTranscriptStatus.classList.add('border-red-200', 'bg-red-50', 'text-red-700');
                                getTranscriptStatus.textContent = `Loi: ${error.message}`;
                            } else {
                                alert('Loi: ' + error.message);
                            }
                        } finally {
                            getTranscriptBtn.disabled = false;
                            getTranscriptBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                            getTranscriptBtn.textContent = originalBtnText;
                        }
                    });
                }

                ttsToggleBtn.addEventListener('click', function() {
                    isExpanded = !isExpanded;
                    if (isExpanded) {
                        ttsContent.style.display = 'block';
                        ttsToggleIcon.textContent = '−';
                    } else {
                        ttsContent.style.display = 'none';
                        ttsToggleIcon.textContent = '+';
                    }
                });

                // Add event listener for Generate TTS button
                const generateTtsBtn = document.getElementById('generateTTSBtn');
                if (generateTtsBtn) {
                    generateTtsBtn.addEventListener('click', generateTTSForSelectedSegments);
                }

                // Add event listener for Align Timing button
                const alignTimingBtn = document.getElementById('alignTimingBtn');
                if (alignTimingBtn) {
                    alignTimingBtn.addEventListener('click', performAlignTiming);
                }

                // Add event listener for Merge Segments (Timeline) button
                const mergeSegmentsBtn = document.getElementById('mergeSegmentsBtn');
                if (mergeSegmentsBtn) {
                    mergeSegmentsBtn.addEventListener('click', performMergeAudio);
                }

                const remergeSegmentsBtn = document.getElementById('remergeSegmentsBtn');
                if (remergeSegmentsBtn) {
                    remergeSegmentsBtn.addEventListener('click', performMergeAudio);
                }
            });

            // Align audio timing with original timestamps
            async function performAlignTiming(event) {
                // Prevent any default button behavior
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }

                if (!currentProjectId) {
                    alert('Project ID not found');
                    return;
                }

                // Check if any segments are selected
                const selectedCheckboxes = document.querySelectorAll('.segment-select:checked');

                if (selectedCheckboxes.length === 0) {
                    alert('⚠️ Vui lòng chọn ít nhất một segment để căn chỉnh audio');
                    return; // Exit cleanly, no other messages
                }

                // Get selected segment indices by matching with segment divs
                const selectedIndices = [];
                const segmentDivs = document.querySelectorAll('[data-segment-index]');

                segmentDivs.forEach((div) => {
                    const index = parseInt(div.dataset.segmentIndex);
                    const checkbox = div.querySelector('.segment-select');
                    if (checkbox && checkbox.checked) {
                        selectedIndices.push(index);
                    }
                });

                // Get UI elements
                const alignBtn = document.getElementById('alignTimingBtn');
                const progressContainer = document.getElementById('progressContainer');
                const progressBar = document.getElementById('progressBar');
                const progressPercent = document.getElementById('progressPercent');
                const progressLabel = document.getElementById('progressLabel');

                if (!progressContainer || !progressBar || !progressPercent) {
                    alert('❌ Không tìm thấy progress elements');
                    return;
                }

                try {
                    // Show progress
                    progressContainer.classList.remove('hidden');
                    if (progressLabel) {
                        progressLabel.textContent = 'Đang căn chỉnh audio...';
                    }
                    progressBar.style.width = '50%';
                    progressPercent.textContent = '50%';

                    if (alignBtn) {
                        alignBtn.disabled = true;
                        alignBtn.innerHTML = '⏳ Processing...';
                    }

                    const response = await fetch(`/dubsync/projects/${currentProjectId}/align-timing`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            segment_indices: selectedIndices
                        })
                    });

                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        const text = await response.text();
                        console.error('Non-JSON response:', text);
                        throw new Error(`Server returned non-JSON: ${text.substring(0, 100)}`);
                    }

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Failed to align audio timing');
                    }

                    // Update progress
                    progressBar.style.width = '100%';
                    progressPercent.textContent = '100%';
                    if (progressLabel) {
                        const statusMsg = data.all_aligned ?
                            '✅ Đã căn chỉnh xong tất cả segments!' :
                            `✅ Căn chỉnh xong ${data.aligned_count}/${data.total_audio_segments} segments`;
                        progressLabel.textContent = statusMsg;
                    }

                    // Hide progress after 2 seconds and reload page
                    setTimeout(() => {
                        progressContainer.classList.add('hidden');
                        location.reload();
                    }, 2000);

                } catch (error) {
                    console.error('Align timing error:', error);
                    progressContainer.classList.add('hidden');
                    if (alignBtn) {
                        alignBtn.disabled = false;
                        alignBtn.innerHTML = '⏱️ Align Audio Timing';
                    }

                    let errorMsg = error.message;
                    if (errorMsg.includes('Unexpected end of JSON')) {
                        errorMsg = 'Lỗi server: Phản hồi không hợp lệ. Vui lòng kiểm tra log server.';
                    }
                    alert('❌ Lỗi căn chỉnh audio: ' + errorMsg);
                }
            }

            // Merge all audio segments
            async function performMergeAudio(event) {
                // Prevent any default button behavior
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }

                if (!currentProjectId) {
                    alert('Project ID not found');
                    return;
                }

                if (!confirm('Bạn có muốn gộp tất cả audio segments theo đúng timeline transcript gốc?')) {
                    return;
                }

                const mergeBtn = document.getElementById('mergeSegmentsBtn');
                const progressContainer = document.getElementById('progressContainer');
                const progressBar = document.getElementById('progressBar');
                const progressPercent = document.getElementById('progressPercent');
                const progressLabel = document.getElementById('progressLabel');

                if (!progressContainer || !progressBar || !progressPercent) {
                    alert('❌ Không tìm thấy progress elements');
                    return;
                }

                try {
                    // Show progress
                    progressContainer.classList.remove('hidden');
                    if (progressLabel) {
                        progressLabel.textContent = 'Đang gộp audio theo timeline...';
                    }
                    progressBar.style.width = '50%';
                    progressPercent.textContent = '50%';

                    if (mergeBtn) {
                        mergeBtn.disabled = true;
                        mergeBtn.innerHTML = '⏳ Processing...';
                    }

                    const response = await fetch(`/dubsync/projects/${currentProjectId}/merge-audio`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({ merge_mode: 'timeline' })
                    });

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Failed to merge audio');
                    }

                    // Update progress
                    progressBar.style.width = '100%';
                    progressPercent.textContent = '100%';
                    if (progressLabel) {
                        progressLabel.textContent = '✅ Gộp audio theo timeline xong!';
                    }

                    // Hide progress after 2 seconds and reload page
                    setTimeout(() => {
                        progressContainer.classList.add('hidden');
                        location.reload();
                    }, 2000);

                } catch (error) {
                    console.error('Merge audio error:', error);
                    alert('❌ Lỗi: ' + error.message);
                    progressContainer.classList.add('hidden');
                    if (mergeBtn) {
                        mergeBtn.disabled = false;
                        mergeBtn.innerHTML = '🎵 Merge Audio (Timeline)';
                    }
                }
            }

            // Audio Versions Modal Handler
            document.addEventListener('click', async function(e) {
                if (e.target.closest('.view-audio-versions')) {
                    const btn = e.target.closest('.view-audio-versions');
                    const segmentIndex = parseInt(btn.dataset.index);
                    await showAudioVersionsModal(segmentIndex);
                }
            });

            async function showAudioVersionsModal(segmentIndex) {
                try {
                    const response = await fetch(
                        `/dubsync/projects/{{ $project->id }}/segments/${segmentIndex}/audio-versions`);
                    const data = await response.json();

                    if (!data.success) {
                        alert('Không thể tải danh sách audio versions');
                        return;
                    }

                    // Create modal
                    const modal = document.createElement('div');
                    modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';

                    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#39;'
                    }[char]));

                    const versions = Array.isArray(data.versions) ? data.versions : [];
                    const versionsHtml = versions.map((version, idx) => {
                        const provider = String(version.provider || 'unknown');
                        const providerClass = provider === 'gemini'
                            ? 'bg-purple-100 text-purple-800'
                            : (provider === 'openai' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800');
                        const gender = String(version.voice_gender || 'unknown');
                        const genderLabel = gender === 'male'
                            ? 'Male'
                            : (gender === 'female' ? 'Female' : 'Unknown');
                        const fileSize = Number(version.size || 0);
                        const currentTag = idx === 0
                            ? '<span class="bg-green-600 text-white text-xs px-2 py-1 rounded font-medium">Current</span>'
                            : '';
                        const useVersionButton = idx === 0
                            ? ''
                            : '<div class="mt-2 flex gap-2">'
                                + '<button class="use-this-version text-xs bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded font-medium transition"'
                                + ' data-url="' + escapeHtml(version.url || '') + '"'
                                + ' data-path="' + escapeHtml(version.path || '') + '"'
                                + ' data-index="' + segmentIndex + '"'
                                + ' data-voice-gender="' + escapeHtml(gender) + '"'
                                + ' data-voice-name="' + escapeHtml(version.voice_name || '') + '"'
                                + ' data-provider="' + escapeHtml(provider) + '">'
                                + 'Use this version'
                                + '</button>'
                                + '</div>';

                        return '<div class="border rounded-lg p-4 hover:shadow-md transition ' + (idx === 0 ? 'bg-green-50 border-green-300' : 'bg-gray-50') + '">'
                            + '<div class="flex justify-between items-start mb-3">'
                            + '<div>'
                            + '<div class="flex items-center gap-2 mb-1">'
                            + currentTag
                            + '<span class="text-sm font-medium text-gray-700">' + escapeHtml(version.filename || '') + '</span>'
                            + '</div>'
                            + '<div class="text-xs text-gray-500">' + escapeHtml(version.created_at || '') + '</div>'
                            + '</div>'
                            + '<div class="text-right">'
                            + '<div class="text-xs text-gray-500">' + (fileSize / 1024).toFixed(1) + ' KB</div>'
                            + '</div>'
                            + '</div>'
                            + '<div class="grid grid-cols-3 gap-3 mb-3 text-sm">'
                            + '<div>'
                            + '<span class="text-gray-600 font-medium">Provider:</span>'
                            + '<span class="ml-1 px-2 py-0.5 rounded ' + providerClass + '">' + escapeHtml(provider.toUpperCase()) + '</span>'
                            + '</div>'
                            + '<div>'
                            + '<span class="text-gray-600 font-medium">Gender:</span>'
                            + '<span class="ml-1">' + escapeHtml(genderLabel) + '</span>'
                            + '</div>'
                            + '<div>'
                            + '<span class="text-gray-600 font-medium">Voice:</span>'
                            + '<span class="ml-1 font-mono text-xs">' + escapeHtml(version.voice_name || 'N/A') + '</span>'
                            + '</div>'
                            + '</div>'
                            + '<div class="bg-white rounded p-2">'
                            + '<audio controls class="w-full" preload="metadata">'
                            + '<source src="' + escapeHtml(version.url || '') + '" type="audio/wav">'
                            + 'Your browser does not support audio playback.'
                            + '</audio>'
                            + '</div>'
                            + useVersionButton
                            + '</div>';
                    }).join('');

                    const modalBodyHtml = versions.length === 0
                        ? '<p class="text-gray-500 text-center py-8">No audio versions for this segment yet.</p>'
                        : '<div class="space-y-4">' + versionsHtml + '</div>';

                    modal.innerHTML = '<div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden">'
                        + '<div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-4 flex justify-between items-center">'
                        + '<h3 class="text-xl font-bold text-white">Audio Versions - Segment ' + (segmentIndex + 1) + '</h3>'
                        + '<button class="close-modal text-white hover:text-gray-200 text-2xl font-bold">&times;</button>'
                        + '</div>'
                        + '<div class="p-6 overflow-y-auto max-h-[calc(90vh-80px)]">'
                        + modalBodyHtml
                        + '</div>'
                        + '</div>';

                    document.body.appendChild(modal);

                    // Close modal handlers
                    modal.querySelector('.close-modal').addEventListener('click', () => {
                        document.body.removeChild(modal);
                    });

                    modal.addEventListener('click', (e) => {
                        if (e.target === modal) {
                            document.body.removeChild(modal);
                        }
                    });

                    // Use version button handler
                    modal.querySelectorAll('.use-this-version').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const segmentIdx = parseInt(btn.dataset.index);
                            const audioUrl = btn.dataset.url;
                            const audioPath = btn.dataset.path;
                            const voiceGender = btn.dataset.voiceGender;
                            const voiceName = btn.dataset.voiceName;
                            const provider = btn.dataset.provider;

                            // Update current segment
                            if (currentSegments[segmentIdx]) {
                                currentSegments[segmentIdx].audio_url = audioUrl;
                                currentSegments[segmentIdx].audio_path = audioPath;
                                currentSegments[segmentIdx].voice_gender = voiceGender;
                                currentSegments[segmentIdx].voice_name = voiceName;
                                currentSegments[segmentIdx].tts_provider = provider;
                            }

                            // Save to backend
                            await saveSegmentsToBackend();

                            // Reload segments display
                            loadExistingSegments(currentSegments);

                            // Close modal
                            document.body.removeChild(modal);

                            alert('✓ Đã cập nhật audio version cho segment ' + (segmentIdx + 1));
                        });
                    });

                } catch (error) {
                    console.error('Error loading audio versions:', error);
                    alert('Lỗi khi tải audio versions: ' + error.message);
                }
            }

            // Reset to TTS Generation Handler
            async function performResetToTts(event) {
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }

                if (!currentProjectId) {
                    alert('Project ID not found');
                    return;
                }

                // Confirmation dialog
                if (!confirm(
                        '⚠️ Cảnh báo: Hành động này sẽ xóa tất cả các file audio và dữ liệu căn chỉnh. Bạn có chắc chắn muốn quay lại trạng thái Generate TTS Voice?\n\nNhấn "OK" để xác nhận.'
                    )) {
                    return;
                }

                const resetBtn = document.getElementById('resetToTtsBtn');
                const progressContainer = document.getElementById('progressContainer');
                const progressBar = document.getElementById('progressBar');
                const progressPercent = document.getElementById('progressPercent');
                const progressLabel = document.getElementById('progressLabel');

                if (!progressContainer || !progressBar || !progressPercent) {
                    alert('❌ Không tìm thấy progress elements');
                    return;
                }

                try {
                    // Show progress
                    progressContainer.classList.remove('hidden');
                    if (progressLabel) {
                        progressLabel.textContent = 'Đang reset project...';
                    }
                    progressBar.style.width = '30%';
                    progressPercent.textContent = '30%';

                    if (resetBtn) {
                        resetBtn.disabled = true;
                        resetBtn.innerHTML = '⏳ Processing...';
                    }

                    const response = await fetch(`/dubsync/projects/${currentProjectId}/reset-to-tts-generation`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        }
                    });

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Failed to reset project');
                    }

                    // Update progress
                    progressBar.style.width = '100%';
                    progressPercent.textContent = '100%';
                    if (progressLabel) {
                        progressLabel.textContent = '✅ Reset xong! Bây giờ bạn có thể generate TTS mới.';
                    }

                    // Hide progress after 2 seconds and reload page
                    setTimeout(() => {
                        progressContainer.classList.add('hidden');
                        location.reload();
                    }, 2000);

                } catch (error) {
                    console.error('Reset to TTS error:', error);
                    alert('❌ Lỗi: ' + error.message);
                    progressContainer.classList.add('hidden');
                    if (resetBtn) {
                        resetBtn.disabled = false;
                        resetBtn.innerHTML = '↶ Reset to TTS';
                    }
                }
            }

            // Tab switching functionality
            function switchTab(tabName) {
                const segmentsTab = document.getElementById('segmentsTab');
                const transcriptTab = document.getElementById('transcriptTab');
                const transcriptAudioTab = document.getElementById('transcriptAudioTab');
                const segmentsContent = document.getElementById('segmentsTabContent');
                const transcriptContent = document.getElementById('transcriptTabContent');
                const transcriptAudioContent = document.getElementById('transcriptAudioTabContent');

                // Reset all tabs
                [segmentsTab, transcriptTab, transcriptAudioTab].forEach(tab => {
                    if (tab) {
                        tab.classList.remove('border-indigo-500', 'text-indigo-600');
                        tab.classList.add('border-transparent', 'text-gray-500');
                    }
                });
                [segmentsContent, transcriptContent, transcriptAudioContent].forEach(content => {
                    if (content) content.classList.add('hidden');
                });

                if (tabName === 'segments') {
                    // Show segments tab
                    segmentsTab.classList.remove('border-transparent', 'text-gray-500');
                    segmentsTab.classList.add('border-indigo-500', 'text-indigo-600');
                    segmentsContent.classList.remove('hidden');
                } else if (tabName === 'transcript') {
                    // Show transcript tab
                    transcriptTab.classList.remove('border-transparent', 'text-gray-500');
                    transcriptTab.classList.add('border-indigo-500', 'text-indigo-600');
                    transcriptContent.classList.remove('hidden');

                    // Initialize and display full transcript
                    initializeFullTranscript();
                } else if (tabName === 'transcriptAudio') {
                    // Show transcript audio tab
                    transcriptAudioTab.classList.remove('border-transparent', 'text-gray-500');
                    transcriptAudioTab.classList.add('border-indigo-500', 'text-indigo-600');
                    transcriptAudioContent.classList.remove('hidden');

                    // Load audio list
                    loadFullTranscriptAudioList();
                }
            }

            // Full Transcript Audio Functions
            async function loadFullTranscriptAudioList(forceRefresh = false) {
                const container = document.getElementById('audioListContainer');
                if (!container) return;

                console.log('Loading Full Transcript Audio List', {
                    projectId: currentProjectId,
                    forceRefresh: forceRefresh
                });

                container.innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <div class="h-8 w-8 border-2 border-gray-300 border-t-blue-600 rounded-full animate-spin mx-auto mb-2"></div>
                        <p>${forceRefresh ? 'Đang làm mới danh sách...' : 'Đang tải danh sách audio...'}</p>
                    </div>
                `;

                try {
                    const url = `/dubsync/projects/${currentProjectId}/full-transcript-audio-list${
                        forceRefresh ? '?refresh=true' : ''}`;
                    const response = await fetch(url);
                    const data = await response.json();

                    console.log('Full Transcript Audio List Response:', {
                        url,
                        success: data.success,
                        filesCount: data.files ? data.files.length : 'undefined',
                        hasMergedFile: !!data.merged_file,
                        totalSize: data.total_size,
                        files: data.files
                    });

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Lỗi tải danh sách audio');
                    }

                    if (!data.files || data.files.length === 0) {
                        container.innerHTML = `
                            <div class="text-center py-8 text-gray-500">
                                <i class="ri-music-line text-4xl mb-2"></i>
                                <p>Chưa có audio nào được tạo</p>
                                <p class="text-sm mt-1">Hãy chuyển sang tab "Full Transcript" và nhấn "Convert to Speech"</p>
                            </div>
                        `;
                        return;
                    }

                    let html = `
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-4">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-gray-700">
                                    <span class="font-semibold">Tổng số file:</span> ${data.files.length}
                                </div>
                                <div class="text-sm text-gray-700">
                                    <span class="font-semibold">Tổng dung lượng:</span> ${formatFileSize(data.total_size || 0)}
                                </div>
                            </div>
                        </div>
                    `;

                    // Show merged file if available
                    if (data.merged_file) {
                        html += `
                            <div class="bg-purple-50 border-2 border-purple-400 rounded-lg p-4 mb-4">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-purple-700 font-semibold">🎵 Merged Audio File</span>
                                </div>
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3 flex-1">
                                        <div class="bg-purple-600 text-white rounded-lg px-3 py-2 text-sm font-semibold">
                                            MERGED
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium text-gray-900 truncate">${data.merged_file.filename}</div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                ${formatFileSize(data.merged_file.size)} • ${formatDate(data.merged_file.modified)}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <audio controls class="h-8" style="width: 250px;">
                                            <source src="${data.merged_file.url}" type="audio/mpeg">
                                        </audio>
                                        <button id="align-duration-btn-${data.merged_file.path.replace(/\//g, '-')}" onclick="alignFullTranscriptDuration('${data.merged_file.path}')" 
                                            class="px-3 py-1.5 bg-indigo-600 text-white rounded hover:bg-indigo-700 text-sm whitespace-nowrap">
                                            ⏱️ Align Duration
                                        </button>
                                        <button onclick="downloadAudio('${data.merged_file.url}', '${data.merged_file.filename}')" 
                                            class="px-3 py-1.5 bg-green-600 text-white rounded hover:bg-green-700 text-sm whitespace-nowrap">
                                            ⬇️ Tải
                                        </button>
                                        <button onclick="deleteAudio('${data.merged_file.path}', -1)" 
                                            class="px-3 py-1.5 bg-red-600 text-white rounded hover:bg-red-700 text-sm">
                                            🗑️
                                        </button>
                                    </div>
                                </div>
                                <div id="progress-container-${data.merged_file.path.replace(/\//g, '-')}" class="hidden mt-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex-1">
                                            <div class="bg-gray-200 rounded-full h-2 overflow-hidden">
                                                <div id="progress-bar-${data.merged_file.path.replace(/\//g, '-')}" class="bg-indigo-600 h-full transition-all duration-300" style="width: 0%"></div>
                                            </div>
                                        </div>
                                        <span id="progress-text-${data.merged_file.path.replace(/\//g, '-')}" class="text-xs font-semibold text-indigo-600 min-w-10">0%</span>
                                    </div>
                                </div>
                            </div>
                        `;
                    }

                    // Display aligned file
                    if (data.aligned_file) {
                        html += `
                            <div class="bg-indigo-50 border-2 border-indigo-400 rounded-lg p-4 mb-4">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-indigo-700 font-semibold">⏱️ Aligned Audio File</span>
                                    <span class="text-xs text-indigo-600 bg-indigo-100 px-2 py-1 rounded">Đã căn chỉnh duration</span>
                                </div>
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3 flex-1">
                                        <div class="bg-indigo-600 text-white rounded-lg px-3 py-2 text-sm font-semibold">
                                            ALIGNED
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium text-gray-900 truncate">${data.aligned_file.filename}</div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                ${formatFileSize(data.aligned_file.size)} • ${formatDate(data.aligned_file.modified)}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <audio controls class="h-8" style="width: 250px;">
                                            <source src="${data.aligned_file.url}" type="audio/mpeg">
                                        </audio>
                                        <button onclick="downloadAudio('${data.aligned_file.url}', '${data.aligned_file.filename}')" 
                                            class="px-3 py-1.5 bg-green-600 text-white rounded hover:bg-green-700 text-sm whitespace-nowrap">
                                            ⬇️ Tải
                                        </button>
                                        <button onclick="deleteAudio('${data.aligned_file.path}', -2)" 
                                            class="px-3 py-1.5 bg-red-600 text-white rounded hover:bg-red-700 text-sm">
                                            🗑️
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;
                    }

                    html += '<div class="space-y-3">';

                    data.files.forEach((file, index) => {
                        html += `
                            <div class="bg-white border border-gray-200 rounded-lg p-4 hover:border-blue-300 transition">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3 flex-1">
                                        <div class="bg-blue-100 text-blue-700 rounded-full w-10 h-10 flex items-center justify-center font-semibold">
                                            ${file.part_number}
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium text-gray-900 truncate">${file.filename}</div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                ${formatFileSize(file.size)} • ${formatDate(file.modified)}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <audio controls class="h-8" style="width: 250px;">
                                            <source src="${file.url}" type="audio/mpeg">
                                        </audio>
                                        <button onclick="downloadAudio('${file.url}', '${file.filename}')" 
                                            class="px-3 py-1.5 bg-green-600 text-white rounded hover:bg-green-700 text-sm whitespace-nowrap">
                                            ⬇️ Tải
                                        </button>
                                        <button onclick="deleteAudio('${file.path}', ${index})" 
                                            class="px-3 py-1.5 bg-red-600 text-white rounded hover:bg-red-700 text-sm">
                                            🗑️
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;
                    });

                    html += '</div>';
                    container.innerHTML = html;

                } catch (error) {
                    console.error('Load audio list error:', error);
                    container.innerHTML = `
                        <div class="text-center py-8 text-red-500">
                            <i class="ri-error-warning-line text-4xl mb-2"></i>
                            <p>❌ Lỗi: ${error.message}</p>
                            <button onclick="loadFullTranscriptAudioList()" 
                                class="mt-3 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                                Thử lại
                            </button>
                        </div>
                    `;
                }
            }

            function formatFileSize(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
            }

            function formatDate(timestamp) {
                const date = new Date(timestamp * 1000);
                return date.toLocaleDateString('vi-VN') + ' ' + date.toLocaleTimeString('vi-VN', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            function downloadAudio(url, filename) {
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }

            async function deleteAudio(path, index) {
                if (!confirm('Bạn có chắc muốn xóa file audio này?')) {
                    return;
                }

                try {
                    const response = await fetch(
                        `/dubsync/projects/${currentProjectId}/delete-full-transcript-audio`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                path: path
                            })
                        });

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Lỗi xóa file audio');
                    }

                    alert('✅ Đã xóa file audio');
                    loadFullTranscriptAudioList();

                } catch (error) {
                    console.error('Delete audio error:', error);
                    alert('❌ Lỗi: ' + error.message);
                }
            }

            async function alignFullTranscriptDuration(mergedFilePath) {
                if (!confirm('Căn chỉnh thời gian của file audio này để khớp với YouTube video duration?')) {
                    return;
                }

                try {
                    const btn = event.target;
                    const originalText = btn.innerHTML;
                    const progressContainerId = `progress-container-${mergedFilePath.replace(/\//g, '-')}`;
                    const progressBarId = `progress-bar-${mergedFilePath.replace(/\//g, '-')}`;
                    const progressTextId = `progress-text-${mergedFilePath.replace(/\//g, '-')}`;

                    btn.disabled = true;
                    btn.innerHTML = '<i class="ri-loader-4-line animate-spin"></i> Đang xử lý...';

                    // Show progress container
                    const progressContainer = document.getElementById(progressContainerId);
                    if (progressContainer) {
                        progressContainer.classList.remove('hidden');
                    }

                    // Animate progress bar from 0 to 90%
                    let progress = 0;
                    const progressInterval = setInterval(() => {
                        if (progress < 90) {
                            progress += Math.random() * 30;
                            if (progress > 90) progress = 90;

                            const progressBar = document.getElementById(progressBarId);
                            const progressText = document.getElementById(progressTextId);
                            if (progressBar) progressBar.style.width = progress + '%';
                            if (progressText) progressText.textContent = Math.floor(progress) + '%';
                        }
                    }, 300);

                    const response = await fetch(
                        `/dubsync/projects/${currentProjectId}/align-full-transcript-duration`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                merged_file_path: mergedFilePath
                            })
                        });

                    // Complete progress to 100%
                    clearInterval(progressInterval);
                    const progressBar = document.getElementById(progressBarId);
                    const progressText = document.getElementById(progressTextId);
                    if (progressBar) progressBar.style.width = '100%';
                    if (progressText) progressText.textContent = '100%';

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Lỗi căn chỉnh duration');
                    }

                    alert(
                        `✅ Đã căn chỉnh thành công!\n\nFile: ${data.aligned_file}\nDuration gốc: ${data.original_duration}s\nTarget (YouTube): ${data.target_duration}s\nDuration sau căn chỉnh: ${data.aligned_duration}s\nTempo ratio: ${data.tempo_ratio}`
                    );
                    loadFullTranscriptAudioList();

                } catch (error) {
                    console.error('Align duration error:', error);
                    alert('❌ Lỗi: ' + error.message);
                    // Reset progress bar on error
                    const progressContainer = document.getElementById(
                        `progress-container-${mergedFilePath.replace(/\//g, '-')}`);
                    if (progressContainer) {
                        progressContainer.classList.add('hidden');
                    }
                } finally {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                }
            }

            let downloadProgressPollTimer = null;

            function setDownloadButtonDownloadedState(downloaded = true) {
                const btn = document.getElementById('downloadYoutubeVideoBtn');
                if (!btn) return;

                if (downloaded) {
                    btn.disabled = true;
                    btn.innerHTML = '✅ Source Video Downloaded';
                    btn.classList.remove('bg-red-600', 'hover:bg-red-700');
                    btn.classList.add('bg-gray-400', 'cursor-not-allowed');
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '📥 Download Source Video';
                    btn.classList.remove('bg-gray-400', 'cursor-not-allowed');
                    btn.classList.add('bg-red-600', 'hover:bg-red-700');
                }
            }

            function updateDownloadProgressUI(progress) {
                const progressBar     = document.getElementById('downloadProgressBar');
                const progressText    = document.getElementById('downloadProgressText');
                const progressMessage = document.getElementById('downloadProgressMessage');
                const progressSpeed   = document.getElementById('downloadProgressSpeed');

                const pct = typeof progress.percent === 'number'
                    ? Math.max(0, Math.min(100, progress.percent)) : 0;

                if (progressBar) {
                    progressBar.style.width = `${pct}%`;
                    // Animated stripe effect while downloading
                    if (progress.status === 'processing') {
                        progressBar.style.backgroundImage =
                            'linear-gradient(90deg,#dc2626 0%,#f87171 50%,#dc2626 100%)';
                        progressBar.style.backgroundSize = '200% 100%';
                        progressBar.style.animation = 'dlStripe 1.5s linear infinite';
                    } else if (progress.status === 'completed') {
                        progressBar.style.backgroundImage = 'none';
                        progressBar.style.background = '#16a34a';
                        progressBar.style.animation = 'none';
                    } else if (progress.status === 'error') {
                        progressBar.style.backgroundImage = 'none';
                        progressBar.style.background = '#dc2626';
                        progressBar.style.animation = 'none';
                    } else {
                        progressBar.style.backgroundImage =
                            'linear-gradient(90deg, #dc2626, #ef4444)';
                        progressBar.style.animation = 'none';
                    }
                }
                if (progressText) progressText.textContent = `${Math.floor(pct)}%`;
                if (progressMessage) progressMessage.textContent = progress.message || 'Đang tải...';
                if (progressSpeed) {
                    if (progress.speed) {
                        progressSpeed.textContent = progress.speed;
                        progressSpeed.classList.remove('hidden');
                    } else {
                        progressSpeed.textContent = '';
                        progressSpeed.classList.add('hidden');
                    }
                }
            }

            async function pollDownloadSourceProgress() {
                if (!currentProjectId) return;
                try {
                    const response = await fetch(
                        `/dubsync/projects/${currentProjectId}/download-youtube-video-progress`, {
                            method: 'GET',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                        });
                    const data = await response.json();
                    updateDownloadProgressUI(data?.progress || {});
                } catch (error) {
                    console.warn('Poll download progress failed:', error);
                }
            }

            async function downloadYoutubeVideo() {
                const btn = document.getElementById('downloadYoutubeVideoBtn');
                const progressContainer = document.getElementById('downloadProgressContainer');
                if (!btn || !progressContainer) return;

                const originalText = btn.innerHTML;
                btn.disabled = true;
                progressContainer.classList.remove('hidden');
                updateDownloadProgressUI({ status: 'processing', percent: 0, message: 'Đang khởi tạo...' });

                if (downloadProgressPollTimer) clearInterval(downloadProgressPollTimer);

                try {
                    // Dispatch job — returns immediately regardless of download duration.
                    const response = await fetch(
                        `/dubsync/projects/${currentProjectId}/download-youtube-video`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({})
                        });
                    const data = await response.json();

                    if (!response.ok || !data.success) throw new Error(data.error || 'Lỗi tải video');

                    // If video already existed, progress is already at 100% completed.
                    if (!data.queued) {
                        await pollDownloadSourceProgress();
                        const sizeMB = data.size ? (data.size / 1024 / 1024).toFixed(2) + ' MB' : '';
                        setDownloadButtonDownloadedState(true);
                        alert(`✅ Video đã tồn tại!\n\nFile: ${data.filename}\n${sizeMB}`);
                        return;
                    }

                    // Job queued — poll until completed or error.
                    await new Promise((resolve, reject) => {
                        downloadProgressPollTimer = setInterval(async () => {
                            try {
                                const res  = await fetch(`/dubsync/projects/${currentProjectId}/download-youtube-video-progress`, {
                                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                                });
                                const json = await res.json();
                                const progress = json?.progress || {};
                                updateDownloadProgressUI(progress);

                                if (progress.status === 'completed') {
                                    clearInterval(downloadProgressPollTimer);
                                    downloadProgressPollTimer = null;
                                    setDownloadButtonDownloadedState(true);
                                    const sizeMB = progress.size ? (progress.size / 1024 / 1024).toFixed(2) + ' MB' : '';
                                    const platform = progress.platform ? `Nguồn: ${progress.platform}\n` : '';
                                    alert(`✅ Tải video thành công!\n\n${platform}File: ${progress.filename}\n${sizeMB}`);
                                    resolve();
                                } else if (progress.status === 'error') {
                                    clearInterval(downloadProgressPollTimer);
                                    downloadProgressPollTimer = null;
                                    reject(new Error(progress.message || 'Lỗi tải video'));
                                }
                            } catch (pollErr) {
                                console.warn('Poll download progress failed:', pollErr);
                            }
                        }, 1000);
                    });

                } catch (error) {
                    console.error('Download source video error:', error);
                    updateDownloadProgressUI({ status: 'error', percent: 100, message: '❌ ' + error.message });
                    alert('❌ Lỗi: ' + error.message);
                } finally {
                    if (downloadProgressPollTimer) {
                        clearInterval(downloadProgressPollTimer);
                        downloadProgressPollTimer = null;
                    }
                    if (btn.innerHTML !== '✅ Source Video Downloaded') {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                }
            }

            async function generateProjectThumbnail() {
                const btn = document.getElementById('generateThumbnailBtn');
                const ratioSelect = document.getElementById('thumbnailRatioSelect');
                const styleSelect = document.getElementById('thumbnailStyleSelect');
                const statusEl = document.getElementById('thumbnailStatus');
                const previewImg = document.getElementById('projectThumbnailPreview');

                if (!btn || !ratioSelect || !styleSelect) return;

                const ratio = ratioSelect.value;
                const style = styleSelect.value;

                const originalLabel = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '⏳ Đang tạo...';

                if (statusEl) {
                    statusEl.classList.remove('hidden', 'text-red-600', 'text-green-600');
                    statusEl.classList.add('text-gray-600');
                    statusEl.textContent = `Đang tạo thumbnail ${ratio} (${style})...`;
                }

                try {
                    const response = await fetch(`/dubsync/projects/${currentProjectId}/generate-thumbnail`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            ratio,
                            style,
                        })
                    });

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Không thể tạo thumbnail');
                    }

                    if (previewImg && data.thumbnail_url) {
                        const cacheBust = `t=${Date.now()}`;
                        previewImg.src = data.thumbnail_url.includes('?')
                            ? `${data.thumbnail_url}&${cacheBust}`
                            : `${data.thumbnail_url}?${cacheBust}`;
                    } else {
                        window.location.reload();
                    }

                    if (statusEl) {
                        statusEl.classList.remove('text-gray-600', 'text-red-600');
                        statusEl.classList.add('text-green-600');
                        statusEl.textContent = '✅ Tạo thumbnail thành công';
                    }
                } catch (error) {
                    console.error('Generate thumbnail error:', error);
                    if (statusEl) {
                        statusEl.classList.remove('text-gray-600', 'text-green-600');
                        statusEl.classList.add('text-red-600');
                        statusEl.textContent = `❌ ${error.message}`;
                    } else {
                        alert('Lỗi tạo thumbnail: ' + error.message);
                    }
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = originalLabel;
                }
            }

            async function deleteAllAudio() {
                if (!confirm('Bạn có chắc muốn xóa TẤT CẢ các file audio của Full Transcript?')) {
                    return;
                }

                try {
                    const response = await fetch(
                        `/dubsync/projects/${currentProjectId}/delete-all-full-transcript-audio`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            }
                        });

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Lỗi xóa audio');
                    }

                    alert(`✅ Đã xóa ${data.deleted_count} file audio`);
                    loadFullTranscriptAudioList();

                } catch (error) {
                    console.error('Delete all audio error:', error);
                    alert('❌ Lỗi: ' + error.message);
                }
            }

            async function mergeAllAudio() {
                const mergeBtn = document.getElementById('mergeAudioBtn');
                const progressContainer = document.getElementById('mergeAudioProgress');
                const progressBar = document.getElementById('mergeAudioBar');
                const progressStatus = document.getElementById('mergeAudioStatus');

                if (!confirm('Bạn có muốn merge tất cả các file audio thành 1 file duy nhất?')) {
                    return;
                }

                try {
                    mergeBtn.disabled = true;
                    const originalText = mergeBtn.innerHTML;
                    mergeBtn.innerHTML = '⏳ Merging...';
                    if (progressContainer) progressContainer.classList.remove('hidden');
                    if (progressBar) progressBar.style.width = '0%';
                    if (progressStatus) progressStatus.textContent = 'Đang chuẩn bị merge audio...';

                    const response = await fetch(
                        `/dubsync/projects/${currentProjectId}/merge-full-transcript-audio`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            }
                        });

                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        const text = await response.text();
                        console.error('Non-JSON response:', text.substring(0, 500));
                        throw new Error(
                            `Server returned non-JSON response: ${response.status} ${response.statusText}\n${text.substring(0, 200)}`
                        );
                    }

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Lỗi merge audio');
                    }

                    if (progressBar) progressBar.style.width = '100%';
                    if (progressStatus) progressStatus.textContent = '✅ Merge hoàn tất!';

                    alert(
                        `✅ Đã merge thành công!\n\nFile: ${data.filename}\nDung lượng: ${formatFileSize(data.size)}\nThời lượng: ${data.duration || 'N/A'}`
                    );

                    mergeBtn.innerHTML = '✅ Merged!';
                    setTimeout(() => {
                        mergeBtn.innerHTML = originalText;
                        mergeBtn.disabled = false;
                        if (progressContainer) progressContainer.classList.add('hidden');
                        loadFullTranscriptAudioList();
                    }, 2000);

                } catch (error) {
                    console.error('Merge audio error:', error);
                    alert('❌ Lỗi: ' + error.message);
                    mergeBtn.innerHTML = '🎵 Merge Audio';
                    mergeBtn.disabled = false;
                    if (progressContainer) progressContainer.classList.add('hidden');
                }
            }

            // Generate full transcript (EN) from original segments
            function generateFullTranscript() {
                const transcriptContent = document.getElementById('fullTranscriptContent');
                const sourceSegments = Array.isArray(currentSegments) ? currentSegments : [];

                if (!sourceSegments || sourceSegments.length === 0) {
                    if (transcriptContent) {
                        transcriptContent.value = '';
                        transcriptContent.placeholder = 'No segments available. Please load a project first.';
                    }
                    return '';
                }

                const fullText = sourceSegments
                    .map(segment => segment.original_text || segment.text || '')
                    .filter(text => text.trim() !== '')
                    .join('\n');

                if (transcriptContent) {
                    if (!fullText) {
                        transcriptContent.value = '';
                        transcriptContent.placeholder = 'No transcript text available.';
                    } else {
                        transcriptContent.value = fullText;
                    }
                }

                updateConvertToSpeechButtonState();
                return fullText;
            }

            // Generate translated full transcript from translated segments
            function generateTranslatedFullTranscript() {
                const translatedContent = document.getElementById('translatedFullTranscriptContent');
                if (!translatedContent) return;

                const translatedSegments = (window.projectData && Array.isArray(window.projectData.translated_segments)) ?
                    window.projectData.translated_segments :
                    (Array.isArray(currentSegments) ? currentSegments : []);

                if (!translatedSegments || translatedSegments.length === 0) {
                    translatedContent.value = '';
                    translatedContent.placeholder = 'No translated segments available.';
                    return;
                }

                const fullText = translatedSegments
                    .map(segment => segment.text || '')
                    .filter(text => text.trim() !== '')
                    .join('\n');

                if (!fullText) {
                    translatedContent.value = '';
                    translatedContent.placeholder = 'No translated transcript text available.';
                    return;
                }

                translatedContent.value = fullText;
            }

            // Update full transcript from DB if available, otherwise use segments
            let transcriptInitialized = false;
            async function initializeFullTranscript() {
                const transcriptContent = document.getElementById('fullTranscriptContent');
                const translatedContent = document.getElementById('translatedFullTranscriptContent');
                if (!transcriptContent || !translatedContent) return;

                const dbTranscripts = await loadFullTranscriptFromDb();

                // Always generate EN from original segments
                const generatedFullText = generateFullTranscript();

                if (dbTranscripts?.translated_full_transcript && dbTranscripts.translated_full_transcript.trim() !== '') {
                    translatedContent.value = dbTranscripts.translated_full_transcript;
                } else {
                    generateTranslatedFullTranscript();
                }

                updateTranscriptWordCounts();
                updateConvertToSpeechButtonState();

                if (!transcriptInitialized) {
                    // Add auto-save listeners
                    transcriptContent.addEventListener('input', function() {
                        clearTimeout(transcriptSaveTimeout);
                        transcriptSaveTimeout = setTimeout(() => {
                            saveFullTranscriptToDb(transcriptContent.value, translatedContent.value);
                        }, 1000);
                        updateTranscriptWordCounts();
                        updateConvertToSpeechButtonState();
                    });

                    translatedContent.addEventListener('input', function() {
                        clearTimeout(transcriptSaveTimeout);
                        transcriptSaveTimeout = setTimeout(() => {
                            saveFullTranscriptToDb(transcriptContent.value, translatedContent.value);
                        }, 1000);
                        updateTranscriptWordCounts();
                    });

                    initTranscriptScrollSync();
                    transcriptInitialized = true;
                }

                if (generatedFullText && generatedFullText.trim() !== '') {
                    clearTimeout(transcriptSaveTimeout);
                    transcriptSaveTimeout = setTimeout(() => {
                        saveFullTranscriptToDb(transcriptContent.value, translatedContent.value);
                    }, 500);
                }
            }

            // Update Convert to Speech button state
            function updateConvertToSpeechButtonState() {
                const convertBtn = document.getElementById('convertTranscriptToSpeechBtn');
                if (!convertBtn) return;

                const translatedContent = document.getElementById('translatedFullTranscriptContent');
                const hasText = translatedContent && translatedContent.value.trim() !== '';
                const providerSelect = document.getElementById('ttsProviderSelect');
                const activeProvider = (providerSelect && providerSelect.value)
                    ? providerSelect.value
                    : (typeof currentTtsProvider !== 'undefined' ? currentTtsProvider : '');
                const hasProvider = !!activeProvider;

                let hasVoice = false;
                if (currentAudioMode === 'single') {
                    const voiceSelect = document.getElementById('globalVoiceName');
                    hasVoice = voiceSelect && voiceSelect.value;
                } else {
                    // Multi-speaker mode - check if at least one speaker has a voice
                    hasVoice = speakersConfig.some(speaker => speaker.voice);
                }

                const canConvert = hasText && hasProvider && hasVoice;
                convertBtn.disabled = !canConvert;
                convertBtn.style.opacity = canConvert ? '1' : '0.5';
                convertBtn.style.cursor = canConvert ? 'pointer' : 'not-allowed';
            }

            // Save full transcript to database
            let transcriptSaveTimeout;
            async function saveFullTranscriptToDb(fullContent, translatedContent) {
                if (!currentProjectId) return;

                try {
                    const response = await fetch(`/dubsync/projects/${currentProjectId}/save-full-transcript`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            full_transcript: fullContent,
                            translated_full_transcript: translatedContent
                        })
                    });

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        console.error('Failed to save transcript:', data.error);
                    } else {
                        console.log('Transcript auto-saved');
                    }
                } catch (error) {
                    console.error('Error saving transcript:', error);
                }
            }

            // Load full transcript from database
            async function loadFullTranscriptFromDb() {
                if (!currentProjectId) return null;

                try {
                    // Add cache-busting parameter to force fresh data
                    const timestamp = new Date().getTime();
                    const response = await fetch(
                        `/dubsync/projects/${currentProjectId}/get-full-transcript?t=${timestamp}`, {
                            method: 'GET',
                            headers: {
                                'Cache-Control': 'no-cache, no-store, must-revalidate',
                                'Pragma': 'no-cache',
                                'Expires': '0'
                            }
                        });

                    if (!response.ok) {
                        console.log('No transcript found in database');
                        return null;
                    }

                    const data = await response.json();
                    if (data.success) {
                        if (data.full_transcript) {
                            console.log('Loaded transcript from database:', data.full_transcript.substring(0, 100) + '...');
                        }
                        return {
                            full_transcript: data.full_transcript || '',
                            translated_full_transcript: data.translated_full_transcript || ''
                        };
                    }
                } catch (error) {
                    console.error('Error loading transcript from database:', error);
                }

                return null;
            }

            function getWordCount(text) {
                if (!text) return 0;
                const words = text.trim().split(/\s+/).filter(Boolean);
                return words.length;
            }

            function updateTranscriptWordCounts() {
                const fullTranscript = document.getElementById('fullTranscriptContent');
                const translatedTranscript = document.getElementById('translatedFullTranscriptContent');
                const fullCountEl = document.getElementById('fullTranscriptWordCount');
                const translatedCountEl = document.getElementById('translatedTranscriptWordCount');

                if (fullTranscript && fullCountEl) {
                    fullCountEl.textContent = String(getWordCount(fullTranscript.value));
                }
                if (translatedTranscript && translatedCountEl) {
                    translatedCountEl.textContent = String(getWordCount(translatedTranscript.value));
                }
            }

            function initTranscriptScrollSync() {
                const fullTranscript = document.getElementById('fullTranscriptContent');
                const translatedTranscript = document.getElementById('translatedFullTranscriptContent');
                if (!fullTranscript || !translatedTranscript) return;

                let isSyncing = false;

                const syncScroll = (source, target) => {
                    if (isSyncing) return;
                    isSyncing = true;
                    const ratio = source.scrollTop / (source.scrollHeight - source.clientHeight || 1);
                    target.scrollTop = ratio * (target.scrollHeight - target.clientHeight);
                    isSyncing = false;
                };

                translatedTranscript.addEventListener('scroll', () => syncScroll(translatedTranscript, fullTranscript));
                fullTranscript.addEventListener('scroll', () => syncScroll(fullTranscript, translatedTranscript));
            }

            // Attach reset button event listener
            document.addEventListener('DOMContentLoaded', function() {
                const resetBtn = document.getElementById('resetToTtsBtn');
                if (resetBtn) {
                    resetBtn.addEventListener('click', performResetToTts);
                }

                // Save button event listener
                const saveBtn = document.getElementById('saveTranscriptBtn');
                if (saveBtn) {
                    saveBtn.addEventListener('click', async function() {
                        const transcriptContent = document.getElementById('fullTranscriptContent');
                        const translatedContent = document.getElementById(
                            'translatedFullTranscriptContent');
                        const content = transcriptContent ? transcriptContent.value : '';
                        const translatedText = translatedContent ? translatedContent.value : '';

                        saveBtn.disabled = true;
                        const originalText = saveBtn.innerHTML;
                        saveBtn.innerHTML = '⏳ Saving...';

                        try {
                            const response = await fetch(
                                `/dubsync/projects/${currentProjectId}/save-full-transcript`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector(
                                            'meta[name="csrf-token"]').content
                                    },
                                    body: JSON.stringify({
                                        full_transcript: content,
                                        translated_full_transcript: translatedText
                                    })
                                });

                            const data = await response.json();

                            if (response.ok && data.success) {
                                saveBtn.innerHTML = '✅ Saved!';
                                setTimeout(() => {
                                    saveBtn.innerHTML = originalText;
                                    saveBtn.disabled = false;
                                }, 2000);
                            } else {
                                throw new Error(data.message || 'Failed to save transcript');
                            }
                        } catch (error) {
                            console.error('Error saving transcript:', error);
                            alert('Lỗi khi lưu transcript: ' + error.message);
                            saveBtn.innerHTML = originalText;
                            saveBtn.disabled = false;
                        }
                    });
                }

                // Convert transcript to speech
                const convertBtn = document.getElementById('convertTranscriptToSpeechBtn');
                if (convertBtn) {
                    convertBtn.addEventListener('click', async function() {
                        const transcriptContent = document.getElementById('fullTranscriptContent');
                        const translatedContent = document.getElementById(
                            'translatedFullTranscriptContent');
                        const fullText = translatedContent ? translatedContent.value.trim() : '';

                        if (!fullText) {
                            alert('No translated transcript text to convert');
                            return;
                        }

                        const providerSelect = document.getElementById('ttsProviderSelect');
                        const provider = (providerSelect && providerSelect.value)
                            ? providerSelect.value
                            : (typeof currentTtsProvider !== 'undefined' ? currentTtsProvider : '');

                        if (!provider) {
                            alert('Vui lòng chọn TTS Provider trong TTS Audio Settings');
                            return;
                        }

                        // Keep provider state in sync with current UI selection.
                        currentTtsProvider = provider;

                        // Get voice settings
                        let voiceGender, voiceName;
                        if (currentAudioMode === 'single') {
                            const voiceSelect = document.getElementById('globalVoiceName');
                            if (!voiceSelect || !voiceSelect.value) {
                                alert('Vui lòng chọn giọng nói trong TTS Settings');
                                return;
                            }
                            voiceGender = getGlobalVoiceGender();
                            voiceName = voiceSelect.value;
                        } else {
                            // Use first speaker with voice in multi mode
                            const speaker = speakersConfig.find(s => s.voice);
                            if (!speaker) {
                                alert('Vui lòng chọn voice cho ít nhất một speaker');
                                return;
                            }
                            voiceGender = speaker.gender;
                            voiceName = speaker.voice;
                        }

                        const styleInstruction = document.getElementById('ttsStyleInstruction')?.value
                            ?.trim() || '';

                        const buildVbeeSafeChunks = (text, maxChars = 1500) => {
                            const words = text.split(/\s+/).filter(Boolean);
                            const output = [];
                            let currentWords = [];
                            let currentLength = 0;

                            words.forEach((word) => {
                                const addition = currentLength === 0 ? word.length : (word.length + 1);
                                if (currentLength + addition > maxChars && currentWords.length > 0) {
                                    output.push(currentWords.join(' '));
                                    currentWords = [word];
                                    currentLength = word.length;
                                    return;
                                }

                                currentWords.push(word);
                                currentLength += addition;
                            });

                            if (currentWords.length > 0) {
                                output.push(currentWords.join(' '));
                            }

                            return output;
                        };

                        const providerLower = String(provider || '').toLowerCase();
                        const chunks = providerLower === 'vbee'
                            ? buildVbeeSafeChunks(fullText, 1500)
                            : (() => {
                                const words = fullText.split(/\s+/).filter(Boolean);
                                const byWordChunks = [];
                                for (let i = 0; i < words.length; i += 1000) {
                                    byWordChunks.push(words.slice(i, i + 1000).join(' '));
                                }
                                return byWordChunks;
                            })();

                        const startPartIndex = 0; // process from part 1
                        const targetChunks = chunks.slice(startPartIndex);
                        const totalChars = targetChunks.reduce((sum, chunk) => sum + chunk.length, 0) || 1;

                        const progressContainer = document.getElementById('fullTranscriptTtsProgress');
                        const progressBar = document.getElementById('fullTranscriptTtsBar');
                        const progressStatus = document.getElementById('fullTranscriptTtsStatus');

                        const setProgress = (percent, statusText) => {
                            if (progressBar) progressBar.style.width = `${percent}%`;
                            if (progressStatus) progressStatus.textContent = statusText;
                        };

                        convertBtn.disabled = true;
                        const originalText = convertBtn.innerHTML;
                        convertBtn.innerHTML = '⏳ Converting...';
                        if (progressContainer) progressContainer.classList.remove('hidden');

                        let processedChars = 0;

                        try {
                            // Persist latest transcript text before converting.
                            await saveFullTranscriptToDb(
                                transcriptContent ? transcriptContent.value : '',
                                translatedContent ? translatedContent.value : ''
                            );

                            // Start a fresh conversion run to avoid mixing audio from old settings/content.
                            const cleanupResp = await fetch(
                                `/dubsync/projects/${currentProjectId}/delete-all-full-transcript-audio`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                                    }
                                });
                            const cleanupData = await cleanupResp.json();
                            if (!cleanupResp.ok || !cleanupData.success) {
                                throw new Error(cleanupData.error || 'Không thể dọn audio cũ trước khi convert');
                            }

                            for (let i = 0; i < targetChunks.length; i++) {
                                const partIndex = startPartIndex + i + 1;
                                const chunkText = targetChunks[i];
                                const chunkChars = chunkText.length;

                                let chunkProgress = 0;
                                const progressTimer = setInterval(() => {
                                    chunkProgress = Math.min(0.9, chunkProgress + 0.05);
                                    const overallPercent = Math.min(100, Math.round(((
                                        processedChars + chunkChars * chunkProgress
                                    ) / totalChars) * 100));
                                    const chunkPercent = Math.round(chunkProgress * 100);
                                    setProgress(overallPercent,
                                        `Đang tạo TTS: đoạn ${partIndex}/${chunks.length} • ${chunkPercent}% của đoạn`
                                    );
                                }, 400);

                                const response = await fetch(
                                    `/dubsync/projects/${currentProjectId}/generate-full-transcript-tts`, {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector(
                                                'meta[name="csrf-token"]').content
                                        },
                                        body: JSON.stringify({
                                            text: chunkText,
                                            style_instruction: styleInstruction,
                                            part_index: partIndex,
                                            voice_gender: voiceGender,
                                            voice_name: voiceName,
                                            provider: provider
                                        })
                                    });

                                const data = await response.json();
                                clearInterval(progressTimer);

                                if (!response.ok || !data.success) {
                                    throw new Error(data.error || 'Lỗi tạo TTS');
                                }

                                processedChars += chunkChars;
                                const overallPercent = Math.min(100, Math.round((processedChars /
                                    totalChars) * 100));
                                setProgress(overallPercent,
                                    `Đang tạo TTS: đoạn ${partIndex}/${chunks.length} • 100% của đoạn`);
                            }

                            setProgress(100, `✅ Hoàn tất ${targetChunks.length} đoạn`);
                            convertBtn.innerHTML = `✅ Done! (${targetChunks.length} parts)`;
                            setTimeout(() => {
                                convertBtn.innerHTML = originalText;
                                updateConvertToSpeechButtonState();
                                if (progressContainer) progressContainer.classList.add('hidden');
                            }, 2000);

                        } catch (error) {
                            console.error('Convert to speech error:', error);
                            alert('❌ Lỗi: ' + error.message);
                            convertBtn.innerHTML = originalText;
                            updateConvertToSpeechButtonState();
                            if (progressContainer) progressContainer.classList.add('hidden');
                        }
                    });
                }

                // Rewrite translated full transcript with Gemini
                const rewriteBtn = document.getElementById('rewriteTranscriptBtn');
                if (rewriteBtn) {
                    rewriteBtn.addEventListener('click', async function() {
                        const transcriptContent = document.getElementById('fullTranscriptContent');
                        const translatedContent = document.getElementById('translatedFullTranscriptContent');

                        if (!translatedContent) {
                            alert('Không tìm thấy vùng nội dung bản dịch');
                            return;
                        }

                        const sourceText = (translatedContent.value || '').trim();
                        if (!sourceText) {
                            alert('Vui lòng nhập nội dung trong Translated Full Transcript (VI) trước khi viết lại.');
                            return;
                        }

                        if (!confirm('Viết lại nội dung tiếng Việt bằng Gemini theo prompt đã cấu hình?')) {
                            return;
                        }

                        const originalLabel = rewriteBtn.innerHTML;
                        rewriteBtn.disabled = true;
                        rewriteBtn.innerHTML = '⏳ Đang viết lại...';

                        try {
                            const response = await fetch(`/dubsync/projects/${currentProjectId}/rewrite-full-transcript`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                                },
                                body: JSON.stringify({
                                    translated_full_transcript: sourceText
                                })
                            });

                            const data = await response.json();
                            if (!response.ok || !data.success) {
                                throw new Error(data.error || 'Không thể viết lại transcript');
                            }

                            translatedContent.value = data.rewritten_transcript || '';
                            updateTranscriptWordCounts();
                            updateConvertToSpeechButtonState();

                            await saveFullTranscriptToDb(
                                transcriptContent ? transcriptContent.value : '',
                                translatedContent.value
                            );

                            rewriteBtn.innerHTML = '✅ Đã viết lại';
                            setTimeout(() => {
                                rewriteBtn.innerHTML = originalLabel;
                                rewriteBtn.disabled = false;
                            }, 1800);
                        } catch (error) {
                            console.error('Rewrite transcript error:', error);
                            alert('Lỗi khi viết lại transcript: ' + error.message);
                            rewriteBtn.innerHTML = originalLabel;
                            rewriteBtn.disabled = false;
                        }
                    });
                }

                // Download transcript as TXT
                const downloadBtn = document.getElementById('downloadTranscriptBtn');
                if (downloadBtn) {
                    downloadBtn.addEventListener('click', function() {
                        const transcriptContent = document.getElementById('fullTranscriptContent');
                        const fullText = transcriptContent.value.trim();

                        if (!fullText) {
                            alert('No transcript text available');
                            return;
                        }

                        // Create a blob and download
                        const blob = new Blob([fullText], {
                            type: 'text/plain;charset=utf-8'
                        });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = `transcript_${currentProjectId || 'export'}.txt`;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);

                        const originalText = downloadBtn.innerHTML;
                        downloadBtn.innerHTML = '✅ Downloaded!';
                        setTimeout(() => {
                            downloadBtn.innerHTML = originalText;
                        }, 2000);
                    });
                }

                // Event listeners for Full Transcript Audio tab buttons
                const mergeAudioBtn = document.getElementById('mergeAudioBtn');
                if (mergeAudioBtn) {
                    mergeAudioBtn.addEventListener('click', mergeAllAudio);
                }

                const refreshAudioBtn = document.getElementById('refreshAudioListBtn');
                if (refreshAudioBtn) {
                    refreshAudioBtn.addEventListener('click', () => loadFullTranscriptAudioList(true));
                }

                const deleteAllAudioBtn = document.getElementById('deleteAllAudioBtn');
                if (deleteAllAudioBtn) {
                    deleteAllAudioBtn.addEventListener('click', deleteAllAudio);
                }

                const downloadYoutubeVideoBtn = document.getElementById('downloadYoutubeVideoBtn');
                if (downloadYoutubeVideoBtn) {
                    downloadYoutubeVideoBtn.addEventListener('click', downloadYoutubeVideo);
                }

                const generateThumbnailBtn = document.getElementById('generateThumbnailBtn');
                if (generateThumbnailBtn) {
                    generateThumbnailBtn.addEventListener('click', generateProjectThumbnail);
                }

                // Export Files button
                const exportBtn = document.getElementById('exportBtn');
                if (exportBtn) {
                    exportBtn.addEventListener('click', async () => {
                        const formats = [...document.querySelectorAll('.export-format:checked')].map(el => el.value);
                        if (formats.length === 0) { alert('Chọn ít nhất 1 format.'); return; }

                        const originalText = exportBtn.innerHTML;
                        exportBtn.disabled = true;
                        exportBtn.innerHTML = '⏳ Exporting...';

                        try {
                            const res = await fetch(`/dubsync/projects/${currentProjectId}/export`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                                },
                                body: JSON.stringify({ formats })
                            });
                            const data = await res.json();
                            if (!res.ok || !data.success) throw new Error(data.error || 'Export failed');

                            // Show download links
                            const linksDiv = document.getElementById('downloadLinks');
                            const linksList = document.getElementById('downloadLinksList');
                            linksList.innerHTML = '';
                            for (const [type, path] of Object.entries(data.files)) {
                                const url = `/dubsync/projects/${currentProjectId}/download/${type}`;
                                linksList.innerHTML += `<a href="${url}" download class="flex items-center gap-2 text-sm text-blue-600 hover:underline">⬇ ${type.toUpperCase()}</a>`;
                            }
                            linksDiv.classList.remove('hidden');
                            exportBtn.innerHTML = '✅ Done';
                        } catch (e) {
                            alert('❌ ' + e.message);
                            exportBtn.disabled = false;
                            exportBtn.innerHTML = originalText;
                        }
                    });
                }

                // On page load, resume polling if a download job is still in progress.
                resumeDownloadProgressIfRunning();

                // Change project status
                const changeStatusBtn = document.getElementById('changeStatusBtn');
                const cancelStatusBtn = document.getElementById('cancelStatusBtn');
                const applyStatusBtn  = document.getElementById('applyStatusBtn');
                const changeStatusPanel = document.getElementById('changeStatusPanel');

                if (changeStatusBtn) {
                    changeStatusBtn.addEventListener('click', () => {
                        changeStatusPanel.classList.toggle('hidden');
                    });
                }
                if (cancelStatusBtn) {
                    cancelStatusBtn.addEventListener('click', () => {
                        changeStatusPanel.classList.add('hidden');
                    });
                }
                if (applyStatusBtn) {
                    applyStatusBtn.addEventListener('click', async () => {
                        const newStatus = document.getElementById('newStatusSelect').value;
                        applyStatusBtn.disabled = true;
                        applyStatusBtn.textContent = '...';
                        try {
                            const res = await fetch(`/dubsync/projects/${currentProjectId}/change-status`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                                },
                                body: JSON.stringify({ status: newStatus })
                            });
                            const data = await res.json();
                            if (!res.ok || !data.success) throw new Error(data.error || 'Failed');
                            location.reload();
                        } catch (e) {
                            alert('❌ ' + e.message);
                            applyStatusBtn.disabled = false;
                            applyStatusBtn.textContent = 'Áp dụng';
                        }
                    });
                }
            });

            async function resumeDownloadProgressIfRunning() {
                if (!currentProjectId) return;
                try {
                    const res      = await fetch(`/dubsync/projects/${currentProjectId}/download-youtube-video-progress`, {
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                    });
                    const json     = await res.json();
                    const progress = json?.progress || {};

                    if (progress.status === 'completed') {
                        setDownloadButtonDownloadedState(true);
                        return;
                    }

                    if (progress.status !== 'processing') return;

                    // A job is running — show the progress UI and resume polling.
                    const btn               = document.getElementById('downloadYoutubeVideoBtn');
                    const progressContainer = document.getElementById('downloadProgressContainer');
                    if (progressContainer) progressContainer.classList.remove('hidden');
                    if (btn) btn.disabled = true;
                    updateDownloadProgressUI(progress);

                    if (downloadProgressPollTimer) clearInterval(downloadProgressPollTimer);

                    downloadProgressPollTimer = setInterval(async () => {
                        try {
                            const r  = await fetch(`/dubsync/projects/${currentProjectId}/download-youtube-video-progress`, {
                                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                            });
                            const j  = await r.json();
                            const p  = j?.progress || {};
                            updateDownloadProgressUI(p);

                            if (p.status === 'completed') {
                                clearInterval(downloadProgressPollTimer);
                                downloadProgressPollTimer = null;
                                setDownloadButtonDownloadedState(true);
                                const sizeMB   = p.size ? (p.size / 1024 / 1024).toFixed(2) + ' MB' : '';
                                const platform = p.platform ? `Nguồn: ${p.platform}\n` : '';
                                alert(`✅ Tải video thành công!\n\n${platform}File: ${p.filename}\n${sizeMB}`);
                            } else if (p.status === 'error') {
                                clearInterval(downloadProgressPollTimer);
                                downloadProgressPollTimer = null;
                                if (btn) { btn.disabled = false; }
                            }
                        } catch (e) {
                            console.warn('Resume poll failed:', e);
                        }
                    }, 1000);
                } catch (e) {
                    // Silently ignore — page just loaded, not critical.
                }
            }
        </script>
        <script>
            // Queue Monitor
            (function() {
                const modal = document.getElementById('queueMonitorModal');
                const openBtn = document.getElementById('queueMonitorBtn');
                const closeBtn = document.getElementById('queueCloseBtn');
                const refreshBtn = document.getElementById('queueRefreshBtn');
                const clearBtn = document.getElementById('queueClearAllBtn');
                const summaryBar = document.getElementById('queueSummaryBar');
                const content = document.getElementById('queueContent');
                const badge = document.getElementById('queueBadge');
                let autoRefreshTimer = null;

                if (!modal || !openBtn) return;

                openBtn.addEventListener('click', () => {
                    modal.classList.remove('hidden');
                    loadQueueStatus();
                    autoRefreshTimer = setInterval(loadQueueStatus, 5000);
                });

                closeBtn.addEventListener('click', closeModal);
                modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

                function closeModal() {
                    modal.classList.add('hidden');
                    clearInterval(autoRefreshTimer);
                }

                refreshBtn.addEventListener('click', loadQueueStatus);

                clearBtn.addEventListener('click', async () => {
                    if (!confirm('Xóa tất cả jobs đang chờ và đã lỗi? Bạn sẽ cần chạy lại các tác vụ.')) return;
                    clearBtn.disabled = true;
                    clearBtn.textContent = 'Đang xóa...';
                    try {
                        const res = await fetch('/dubsync/queue-clear', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            }
                        });
                        const data = await res.json();
                        if (data.success) {
                            alert(`Đã xóa ${data.cleared_pending} pending + ${data.cleared_failed} failed jobs`);
                            loadQueueStatus();
                        } else {
                            alert('Lỗi: ' + (data.error || 'Không thể xóa'));
                        }
                    } catch (err) {
                        alert('Lỗi kết nối: ' + err.message);
                    } finally {
                        clearBtn.disabled = false;
                        clearBtn.textContent = '🗑️ Clear All Jobs';
                    }
                });

                async function loadQueueStatus() {
                    try {
                        const res = await fetch('/dubsync/queue-status');
                        const data = await res.json();
                        if (!data.success) {
                            content.innerHTML = '<p class="text-red-500 text-sm">Lỗi: ' + (data.error || 'Unknown') + '</p>';
                            return;
                        }
                        renderSummary(data.summary);
                        renderJobs(data);
                        updateBadge(data.summary);
                    } catch (err) {
                        content.innerHTML = '<p class="text-red-500 text-sm">Không thể tải: ' + err.message + '</p>';
                    }
                }

                function renderSummary(s) {
                    summaryBar.innerHTML = `
                        <span class="px-2 py-1 rounded bg-yellow-100 text-yellow-800">⏳ Running: ${s.running}</span>
                        <span class="px-2 py-1 rounded bg-blue-100 text-blue-800">📥 Pending: ${s.pending}</span>
                        <span class="px-2 py-1 rounded bg-red-100 text-red-800">❌ Failed: ${s.failed}</span>
                        <span class="px-2 py-1 rounded bg-gray-100 text-gray-700">Tổng: ${s.total}</span>
                    `;
                }

                function updateBadge(s) {
                    const total = s.running + s.pending + s.failed;
                    if (total > 0) {
                        badge.textContent = total > 99 ? '99+' : total;
                        badge.classList.remove('hidden');
                    } else {
                        badge.classList.add('hidden');
                    }
                }

                function renderJobs(data) {
                    let html = '';

                    if (data.running.length > 0) {
                        html += '<div><h4 class="text-xs font-bold text-yellow-700 mb-1">🔄 RUNNING</h4>';
                        html += '<div class="space-y-1">' + data.running.map(j => jobRow(j, 'yellow')).join('') + '</div></div>';
                    }

                    if (data.pending.length > 0) {
                        html += '<div><h4 class="text-xs font-bold text-blue-700 mb-1">⏳ PENDING</h4>';
                        html += '<div class="space-y-1">' + data.pending.map(j => jobRow(j, 'blue')).join('') + '</div></div>';
                    }

                    if (data.failed.length > 0) {
                        html += '<div><h4 class="text-xs font-bold text-red-700 mb-1">❌ FAILED</h4>';
                        html += '<div class="space-y-1">' + data.failed.map(j => failedRow(j)).join('') + '</div></div>';
                    }

                    if (!html) {
                        html = '<p class="text-gray-400 text-sm text-center py-6">Không có job nào trong queue</p>';
                    }

                    content.innerHTML = html;
                }

                function jobRow(j, color) {
                    return `<div class="flex items-center justify-between text-xs bg-${color}-50 border border-${color}-200 rounded px-3 py-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-${color}-800">#${j.id}</span>
                            <span class="font-semibold">${esc(j.job)}</span>
                            <span class="text-gray-500">att:${j.attempts}</span>
                        </div>
                        <span class="text-gray-400">${j.created_at || ''}</span>
                    </div>`;
                }

                function failedRow(j) {
                    const errSnippet = (j.error || '').substring(0, 120);
                    return `<div class="text-xs bg-red-50 border border-red-200 rounded px-3 py-1.5">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-bold text-red-800">#${j.id}</span>
                                <span class="font-semibold">${esc(j.job)}</span>
                            </div>
                            <span class="text-gray-400">${j.failed_at || ''}</span>
                        </div>
                        <p class="text-red-600 mt-1 break-all">${esc(errSnippet)}</p>
                    </div>`;
                }

                function esc(str) {
                    const d = document.createElement('div');
                    d.textContent = str || '';
                    return d.innerHTML;
                }

                // Poll badge count every 15s even when modal is closed
                async function pollBadge() {
                    try {
                        const res = await fetch('/dubsync/queue-status');
                        const data = await res.json();
                        if (data.success) updateBadge(data.summary);
                    } catch (e) {}
                }
                setInterval(pollBadge, 15000);
                pollBadge();
            })();
        </script>
    @endpush
@endsection
