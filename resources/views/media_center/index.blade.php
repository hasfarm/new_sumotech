@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-slate-100 py-6">
    <div class="mx-auto max-w-[1500px] px-4 sm:px-6 lg:px-8">
        <div class="mb-4 rounded-2xl border border-fuchsia-200 bg-white p-4 shadow-sm">
            <h1 class="text-2xl font-black text-fuchsia-900">Media Center</h1>
            <p class="text-sm text-slate-600 mt-1">Nhập văn bản, tách câu, đồng bộ nhân vật (race/skin tone), tạo prompt ảnh/video và generate TTS/ảnh theo từng câu.</p>
        </div>

        <div class="grid gap-4 lg:grid-cols-[320px_minmax(0,1fr)]">
            <aside class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-800">Content Manager</h2>
                @php
                    $selectedSettings = is_array($selectedProject->settings_json ?? null) ? $selectedProject->settings_json : [];
                    $selectedMediaDefaults = is_array($selectedSettings['media_defaults'] ?? null) ? $selectedSettings['media_defaults'] : [];
                    $selectedMainRefs = is_array($selectedSettings['main_character_reference_images'] ?? null) ? $selectedSettings['main_character_reference_images'] : [];
                    $selectedIdentityLock = trim((string) ($selectedSettings['main_character_identity_lock'] ?? ''));
                    $selectedUseCharacterReference = array_key_exists('use_character_reference', $selectedSettings)
                        ? (bool) $selectedSettings['use_character_reference']
                        : !empty($selectedMainRefs);
                    $selectedAnimationProvider = trim((string) ($selectedMediaDefaults['animation_provider'] ?? 'kling')) ?: 'kling';
                    $selectedImageAspectRatio = trim((string) ($selectedMediaDefaults['image_aspect_ratio'] ?? ($selectedSettings['image_aspect_ratio'] ?? '16:9'))) ?: '16:9';
                    $selectedImageStyle = trim((string) ($selectedMediaDefaults['image_style'] ?? ($selectedSettings['image_style'] ?? 'Cinematic'))) ?: 'Cinematic';
                    $selectedStylePreviews = is_array($selectedSettings['image_style_previews'] ?? null) ? $selectedSettings['image_style_previews'] : [];
                    $selectedStylePreviewsFormatted = [];
                    foreach ($selectedStylePreviews as $styleKey => $previewItem) {
                        if (!is_array($previewItem)) {
                            continue;
                        }
                        $previewStyle = trim((string) ($previewItem['style'] ?? $styleKey));
                        $previewPath = trim((string) ($previewItem['path'] ?? ''));
                        if ($previewStyle === '' || $previewPath === '') {
                            continue;
                        }
                        $selectedStylePreviewsFormatted[$previewStyle] = [
                            'style' => $previewStyle,
                            'path' => $previewPath,
                            'url' => Storage::url($previewPath),
                            'provider' => trim((string) ($previewItem['provider'] ?? 'gemini-nano-banana-pro')),
                            'source_sentence_index' => (int) ($previewItem['source_sentence_index'] ?? 1),
                            'source_sentence_id' => (int) ($previewItem['source_sentence_id'] ?? 0),
                            'aspect_ratio' => trim((string) ($previewItem['aspect_ratio'] ?? $selectedImageAspectRatio)),
                            'generated_at' => trim((string) ($previewItem['generated_at'] ?? '')),
                        ];
                    }
                @endphp

                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" id="openCreateContentBtn" class="rounded-xl bg-fuchsia-600 px-3 py-2 text-xs font-bold text-white hover:bg-fuchsia-700">+ Create content mới</button>
                    @if($selectedProject)
                        <button type="button" id="openEditContentBtn" class="rounded-xl bg-slate-700 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800">Edit content đang chọn</button>
                    @endif
                </div>
                <p class="mt-2 text-[11px] text-slate-500">Tạo content ở đây, còn phần Studio để phân tích/generate ở khung bên phải.</p>

                <div id="contentEditorPanel" class="mt-3 hidden rounded-xl border border-slate-200 bg-slate-50 p-3">
                <form id="createProjectForm" class="space-y-2">
                    @csrf
                    <input id="projectTitle" type="text" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Tên tác phẩm (tuỳ chọn)" value="{{ $selectedProject?->title ?? '' }}">
                    <div class="grid grid-cols-[1fr_auto] gap-2">
                        <select id="storyEraPreset" class="w-full rounded-xl border-slate-300 text-sm">
                            <option value="">Preset thời đại...</option>
                        </select>
                        <button type="button" id="saveStoryEraPresetBtn" class="rounded-xl bg-slate-700 px-2 py-1 text-xs font-semibold text-white hover:bg-slate-800">+ Lưu</button>
                    </div>
                    <input id="storyEra" type="text" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Thời đại (vd: Bắc Tống, cổ trang)" value="{{ $selectedSettings['story_era'] ?? '' }}">
                    <div class="grid grid-cols-[1fr_auto] gap-2">
                        <select id="storyGenrePreset" class="w-full rounded-xl border-slate-300 text-sm">
                            <option value="">Preset thể loại...</option>
                        </select>
                        <button type="button" id="saveStoryGenrePresetBtn" class="rounded-xl bg-slate-700 px-2 py-1 text-xs font-semibold text-white hover:bg-slate-800">+ Lưu</button>
                    </div>
                    <input id="storyGenre" type="text" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Thể loại (vd: kiếm hiệp)" value="{{ $selectedSettings['story_genre'] ?? '' }}">
                    <div class="grid grid-cols-[1fr_auto] gap-2">
                        <select id="worldContextPreset" class="w-full rounded-xl border-slate-300 text-sm">
                            <option value="">Preset bối cảnh...</option>
                        </select>
                        <button type="button" id="saveWorldContextPresetBtn" class="rounded-xl bg-slate-700 px-2 py-1 text-xs font-semibold text-white hover:bg-slate-800">+ Lưu</button>
                    </div>
                    <textarea id="worldContext" rows="2" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Mô tả bối cảnh thế giới (triều đại, không gian, văn hóa)">{{ $selectedSettings['world_context'] ?? '' }}</textarea>
                    <textarea id="forbiddenElements" rows="2" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Yếu tố cấm (vd: văn phòng, xe hơi, điện thoại)">{{ $selectedSettings['forbidden_elements'] ?? '' }}</textarea>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-2">
                        <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">Khung ảnh muốn tạo</label>
                        <input id="imageAspectRatio" type="hidden" value="{{ $selectedSettings['image_aspect_ratio'] ?? '16:9' }}">
                        <div id="imageAspectRatioOptions" class="grid grid-cols-3 gap-2">
                            <button type="button" data-ratio="16:9" class="aspect-ratio-option rounded-lg border border-slate-300 bg-white px-2 py-2 text-[11px] font-semibold text-slate-700">
                                <span class="mx-auto mb-1 block h-6 w-11 rounded border border-slate-400 bg-slate-100"></span>
                                16:9
                            </button>
                            <button type="button" data-ratio="9:16" class="aspect-ratio-option rounded-lg border border-slate-300 bg-white px-2 py-2 text-[11px] font-semibold text-slate-700">
                                <span class="mx-auto mb-1 block h-10 w-6 rounded border border-slate-400 bg-slate-100"></span>
                                9:16
                            </button>
                            <button type="button" data-ratio="1:1" class="aspect-ratio-option rounded-lg border border-slate-300 bg-white px-2 py-2 text-[11px] font-semibold text-slate-700">
                                <span class="mx-auto mb-1 block h-8 w-8 rounded border border-slate-400 bg-slate-100"></span>
                                Vuong
                            </button>
                        </div>
                    </div>
                    <input id="imageStyle" type="text" class="w-full rounded-xl border-slate-300 text-sm" placeholder="The loai/phong cach anh (vd: Cinematic, Ghibli, Anime...)" value="{{ $selectedSettings['image_style'] ?? 'Cinematic' }}">
                    <textarea id="sourceText" rows="8" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Dán văn bản tại đây...">{{ $selectedProject?->source_text ?? '' }}</textarea>
                    <button id="createProjectSubmitBtn" type="submit" class="w-full rounded-xl bg-fuchsia-600 px-3 py-2 text-sm font-bold text-white hover:bg-fuchsia-700">Save content mới</button>
                    @if($selectedProject)
                        <label id="rebuildSentencesWrapper" class="inline-flex items-center gap-2 text-xs text-slate-600">
                            <input id="rebuildSentencesOnUpdate" type="checkbox" class="rounded border-slate-300">
                            Tách lại câu từ source text khi cập nhật
                        </label>
                        <button type="button" id="updateProjectBtn" class="w-full rounded-xl bg-slate-800 px-3 py-2 text-sm font-bold text-white hover:bg-slate-900">Cập nhật Record Đang Chọn</button>
                        <button type="button" id="deleteProjectBtn" class="w-full rounded-xl bg-rose-600 px-3 py-2 text-sm font-bold text-white hover:bg-rose-700">Xóa Record Đang Chọn</button>
                    @endif
                </form>
                </div>

                <div class="mt-4 border-t border-slate-200 pt-3">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Danh sách content đã tạo</p>
                    <div class="mt-2 grid grid-cols-[minmax(0,1fr)_130px] gap-2">
                        <input id="contentListSearchInput" type="text" class="w-full rounded-lg border-slate-300 text-xs" placeholder="Search theo ID, tiêu đề, main...">
                        <select id="contentListStatusFilter" class="w-full rounded-lg border-slate-300 text-xs">
                            <option value="all">Tat ca</option>
                            <option value="new">Moi</option>
                            <option value="in_progress">Dang lam</option>
                            <option value="completed">Hoan thanh</option>
                        </select>
                    </div>
                    <p id="contentListFilterSummary" class="mt-2 text-[11px] text-slate-500">Dang hien thi tat ca content.</p>
                    <div id="contentProjectList" class="mt-2 max-h-[520px] space-y-2 overflow-y-auto pr-1">
                        @forelse($projects as $project)
                            @php
                                $workflowStatusKey = trim((string) ($project->workflow_status_key ?? 'new')) ?: 'new';
                                $workflowStatusLabel = trim((string) ($project->workflow_status_label ?? 'Moi')) ?: 'Moi';
                                $workflowStatusBadgeClass = trim((string) ($project->workflow_status_badge_class ?? 'border-slate-200 bg-slate-100 text-slate-700'));
                                $searchText = mb_strtolower('#' . $project->id . ' ' . $project->title . ' ' . ($project->main_character_name ?? ''), 'UTF-8');
                            @endphp
                            <a href="{{ route('media-center.index', ['project_id' => $project->id]) }}"
                               class="content-project-item block rounded-xl border px-3 py-2 text-xs {{ $selectedId === (int) $project->id ? 'border-fuchsia-400 bg-fuchsia-50 text-fuchsia-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}"
                               data-status-key="{{ $workflowStatusKey }}"
                               data-search-text="{{ $searchText }}">
                                <p class="font-semibold">#{{ $project->id }} - {{ $project->title }}</p>
                                <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-[11px]">{{ $project->status }} | {{ optional($project->updated_at)->format('Y-m-d H:i') }}</p>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $workflowStatusBadgeClass }}">{{ $workflowStatusLabel }}</span>
                                </div>
                                @if(!empty($project->main_character_name))
                                    <p class="mt-1 text-[11px]">Main: {{ $project->main_character_name }}</p>
                                @endif
                            </a>
                        @empty
                            <p class="rounded-xl border border-dashed border-slate-300 p-3 text-xs text-slate-500">Chưa có record nào.</p>
                        @endforelse
                    </div>
                </div>
            </aside>

            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                @if(!$selectedProject)
                    <div class="rounded-xl border border-dashed border-slate-300 p-6 text-sm text-slate-500">Tạo hoặc chọn một record để bắt đầu Media Center workspace.</div>
                @else
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        @php $firstSentence = $selectedProject->sentences->first(); @endphp
                        <div>
                            <h2 class="text-lg font-black text-slate-900">{{ $selectedProject->title }}</h2>
                            <p class="text-xs text-slate-500 mt-1">Record #{{ $selectedProject->id }} | Status: <span id="projectStatus">{{ $selectedProject->status }}</span></p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" id="analyzeProjectBtn" class="rounded-xl bg-cyan-600 px-3 py-2 text-xs font-bold text-white hover:bg-cyan-700">Analyze Character + Prompts</button>
                            <button type="button" id="regenerateWeakPromptsBtn" class="rounded-xl bg-violet-600 px-3 py-2 text-xs font-bold text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60" disabled>Regenerate weak prompts only</button>
                            <button type="button" id="cleanupLegacyPromptsBtn" class="rounded-xl bg-purple-700 px-3 py-2 text-xs font-bold text-white hover:bg-purple-800">Cleanup prompt cu (all sentences)</button>
                            <button type="button" id="generateAllTtsBtn" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">Generate All TTS</button>
                            <button type="button" id="generateAllImagesBtn" class="rounded-xl bg-orange-500 px-3 py-2 text-xs font-bold text-white hover:bg-orange-600">Generate All Images</button>
                            <button type="button" id="downloadAllAssetsBtn" class="rounded-xl bg-slate-700 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800">Download All Assets</button>
                        </div>
                    </div>

                    @php
                        $stats = is_array($workspaceStats ?? null) ? $workspaceStats : [];
                        $sentenceCount = (int) ($stats['sentence_count'] ?? 0);
                        $imageCount = (int) ($stats['image_count'] ?? 0);
                        $videoCount = (int) ($stats['video_count'] ?? 0);
                        $audioCount = (int) ($stats['audio_count'] ?? 0);
                        $costImage = (float) ($stats['cost_image'] ?? 0);
                        $costVideo = (float) ($stats['cost_video'] ?? 0);
                        $costAudio = (float) ($stats['cost_audio'] ?? 0);
                        $costAi = (float) ($stats['cost_ai_generation'] ?? 0);
                        $costTotal = (float) ($stats['cost_total'] ?? 0);
                        $workDurationLabel = trim((string) ($stats['work_duration_label'] ?? '0s'));
                        $workFinished = (bool) ($stats['work_finished'] ?? false);
                        $usdToVndRate = (float) config('services.usd_to_vnd', 26000);
                        $toVnd = static fn (float $usd): float => round(max(0, $usd) * $usdToVndRate);
                    @endphp

                    <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">So cau</p>
                                <p class="mt-1 text-lg font-black text-slate-800">{{ number_format($sentenceCount) }}</p>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">So anh</p>
                                <p class="mt-1 text-lg font-black text-slate-800">{{ number_format($imageCount) }}</p>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">So video</p>
                                <p class="mt-1 text-lg font-black text-slate-800">{{ number_format($videoCount) }}</p>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">So doan audio</p>
                                <p class="mt-1 text-lg font-black text-slate-800">{{ number_format($audioCount) }}</p>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Tong thoi gian lam viec</p>
                                <p class="mt-1 text-lg font-black text-slate-800">{{ $workDurationLabel }}</p>
                                <p class="text-[11px] text-slate-500">{{ $workFinished ? 'Da chot khi download all assets' : 'Dang tinh den hien tai' }}</p>
                            </div>
                        </div>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Chi phi tao anh</p>
                                <p class="mt-1 text-base font-black text-emerald-800">${{ number_format($costImage, 4) }} <span class="text-xs font-semibold">({{ number_format($toVnd($costImage), 0, ',', '.') }} VND)</span></p>
                            </div>
                            <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-indigo-700">CP tao video</p>
                                <p class="mt-1 text-base font-black text-indigo-800">${{ number_format($costVideo, 4) }} <span class="text-xs font-semibold">({{ number_format($toVnd($costVideo), 0, ',', '.') }} VND)</span></p>
                            </div>
                            <div class="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-cyan-700">Chi phi tao audio</p>
                                <p class="mt-1 text-base font-black text-cyan-800">${{ number_format($costAudio, 4) }} <span class="text-xs font-semibold">({{ number_format($toVnd($costAudio), 0, ',', '.') }} VND)</span></p>
                            </div>
                            <div class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-700">CP AI Generation</p>
                                <p class="mt-1 text-base font-black text-violet-800">${{ number_format($costAi, 4) }} <span class="text-xs font-semibold">({{ number_format($toVnd($costAi), 0, ',', '.') }} VND)</span></p>
                            </div>
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">Tong CP</p>
                                <p class="mt-1 text-base font-black text-amber-800">${{ number_format($costTotal, 4) }} <span class="text-xs font-semibold">({{ number_format($toVnd($costTotal), 0, ',', '.') }} VND)</span></p>
                            </div>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-500">Ty gia quy doi: 1 USD = {{ number_format($usdToVndRate, 0, ',', '.') }} VND</p>
                    </div>

                    {{-- ===== WIZARD STEPPER ===== --}}
                    @php
                        $projectStatus   = trim((string) ($selectedProject->status ?? 'draft'));
                        $hasAnalyzed     = in_array($projectStatus, ['analyzed', 'generating', 'completed']);
                        $isAnalyzing     = $projectStatus === 'analyzing';
                        $hasCharRefs     = !empty($selectedMainRefs);
                        $allAudioDone    = $sentenceCount > 0 && $audioCount >= $sentenceCount;
                        $allImagesDone   = $sentenceCount > 0 && $imageCount >= $sentenceCount;
                        $hasMediaSaved   = !empty($selectedMediaDefaults);

                        $wizardSteps = [
                            [
                                'n' => 1, 'id' => 'content',
                                'label' => 'Nhập nội dung',
                                'desc'  => 'Dán văn bản, đặt thời đại & bối cảnh câu chuyện.',
                                'done'  => $sentenceCount > 0,
                                'running' => false,
                                'optional' => false,
                                'action_label' => $sentenceCount > 0 ? 'Edit nội dung' : 'Tạo nội dung mới',
                                'action_js'    => 'document.getElementById(\'openCreateContentBtn\').click()',
                                'tip'   => 'Điền đầy đủ Thời đại, Thể loại và Bối cảnh thế giới để AI tạo prompt chính xác hơn.',
                            ],
                            [
                                'n' => 2, 'id' => 'analyze',
                                'label' => 'Phân tích AI',
                                'desc'  => 'AI phân tích nhân vật, gắn race/wardrobe và tạo image prompt cho từng câu.',
                                'done'  => $hasAnalyzed,
                                'running' => $isAnalyzing,
                                'optional' => false,
                                'action_label' => $isAnalyzing ? 'Đang phân tích...' : ($hasAnalyzed ? 'Phân tích lại' : 'Bắt đầu Analyze'),
                                'action_js'    => 'document.getElementById(\'analyzeProjectBtn\').click()',
                                'tip'   => 'Sau khi analyze, hệ thống tự động tạo ảnh nhân vật (face/half_body/full_body) và gắn scene ref cho từng câu.',
                            ],
                            [
                                'n' => 3, 'id' => 'char_refs',
                                'label' => 'Ảnh nhân vật',
                                'desc'  => 'Ảnh reference của nhân vật chính & phụ — dùng làm identity lock khi tạo ảnh.',
                                'done'  => $hasCharRefs,
                                'running' => false,
                                'optional' => true,
                                'action_label' => $hasCharRefs ? 'Regenerate ảnh mặt' : 'Tạo ảnh mặt trước',
                                'action_js'    => 'document.getElementById(\'genRefFaceBtn\').click()',
                                'tip'   => 'Ảnh này tự động được tạo sau analyze. Bạn có thể upload ảnh thật hoặc regenerate nếu chưa ưng.',
                            ],
                            [
                                'n' => 4, 'id' => 'media_settings',
                                'label' => 'Cấu hình media',
                                'desc'  => 'Chọn TTS provider, giọng đọc, image provider và style ảnh.',
                                'done'  => $hasMediaSaved,
                                'running' => false,
                                'optional' => true,
                                'action_label' => 'Lưu Media Settings',
                                'action_js'    => 'document.getElementById(\'saveMediaSettingsBtn\').click()',
                                'tip'   => 'Bước này tuỳ chọn — nếu bỏ qua sẽ dùng cài đặt mặc định (Google TTS + Gemini image).',
                            ],
                            [
                                'n' => 5, 'id' => 'tts',
                                'label' => 'Generate TTS',
                                'desc'  => "Tạo audio giọng đọc cho tất cả $sentenceCount câu.",
                                'done'  => $allAudioDone,
                                'running' => false,
                                'optional' => false,
                                'action_label' => 'Generate All TTS',
                                'action_js'    => 'document.getElementById(\'generateAllTtsBtn\').click()',
                                'tip'   => "Đã có $audioCount/$sentenceCount câu audio. Queue worker cần đang chạy để xử lý.",
                            ],
                            [
                                'n' => 6, 'id' => 'images',
                                'label' => 'Generate ảnh',
                                'desc'  => "Tạo ảnh minh họa cho tất cả $sentenceCount câu theo image prompt.",
                                'done'  => $allImagesDone,
                                'running' => false,
                                'optional' => false,
                                'action_label' => 'Generate All Images',
                                'action_js'    => 'document.getElementById(\'generateAllImagesBtn\').click()',
                                'tip'   => "Đã có $imageCount/$sentenceCount câu ảnh. Mỗi câu dùng identity lock + scene ref để giữ nhất quán.",
                            ],
                            [
                                'n' => 7, 'id' => 'download',
                                'label' => 'Xuất & Download',
                                'desc'  => 'Tải toàn bộ audio, ảnh và video về máy để dựng video.',
                                'done'  => $workFinished,
                                'running' => false,
                                'optional' => false,
                                'action_label' => 'Download All Assets',
                                'action_js'    => 'document.getElementById(\'downloadAllAssetsBtn\').click()',
                                'tip'   => 'File ZIP gồm audio + ảnh theo thứ tự câu, sẵn sàng để edit video.',
                            ],
                        ];

                        // Xác định bước hiện tại = bước đầu tiên chưa hoàn thành (bắt buộc hoặc tuỳ chọn)
                        $currentWizardStep = 7;
                        foreach (array_reverse($wizardSteps) as $ws) {
                            if (!$ws['done']) {
                                $currentWizardStep = $ws['n'];
                            }
                        }
                        $currentWizardData = collect($wizardSteps)->firstWhere('n', $currentWizardStep) ?? $wizardSteps[0];
                        $allDone = $workFinished && $allAudioDone && $allImagesDone;
                    @endphp

                    <div id="wizardPanel" class="mt-3 rounded-xl border border-indigo-200 bg-gradient-to-br from-indigo-50 to-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-black text-white">✦</span>
                                <p class="text-sm font-black text-indigo-900">Quy trình sản xuất</p>
                                @if($allDone)
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700">Hoàn thành</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700">Bước {{ $currentWizardStep }}/7</span>
                                @endif
                            </div>
                            <button type="button" id="toggleWizardBtn" class="rounded-lg border border-indigo-200 bg-white px-2 py-1 text-[11px] font-semibold text-indigo-600 hover:bg-indigo-50">Thu nhỏ</button>
                        </div>

                        <div id="wizardBody" class="mt-4">
                            {{-- Step pills (horizontal scrollable) --}}
                            <div class="flex items-start gap-0 overflow-x-auto pb-2">
                                @foreach($wizardSteps as $ws)
                                    @php
                                        $isActive  = $ws['n'] === $currentWizardStep && !$allDone;
                                        $isDone    = $ws['done'];
                                        $isRunning = $ws['running'];
                                        $isOptional = $ws['optional'];
                                    @endphp
                                    <div class="flex min-w-[96px] flex-col items-center wizard-step-item cursor-pointer select-none"
                                         onclick="{{ $ws['action_js'] }}" title="{{ $ws['desc'] }}">
                                        {{-- Circle --}}
                                        <div class="flex h-9 w-9 items-center justify-center rounded-full border-2 text-sm font-black transition-all
                                            @if($isDone && !$isRunning)  border-emerald-400 bg-emerald-500 text-white
                                            @elseif($isRunning)          border-amber-400 bg-amber-400 text-white animate-pulse
                                            @elseif($isActive)           border-indigo-500 bg-indigo-600 text-white shadow-md shadow-indigo-200
                                            @elseif($isOptional)         border-slate-300 bg-slate-100 text-slate-400
                                            @else                        border-slate-300 bg-white text-slate-400
                                            @endif">
                                            @if($isDone && !$isRunning)
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            @elseif($isRunning)
                                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                            @else
                                                {{ $ws['n'] }}
                                            @endif
                                        </div>
                                        {{-- Label --}}
                                        <p class="mt-1.5 text-center text-[10px] font-bold leading-tight
                                            @if($isActive)  text-indigo-700
                                            @elseif($isDone) text-emerald-700
                                            @else            text-slate-500
                                            @endif">{{ $ws['label'] }}</p>
                                        @if($isOptional && !$isDone)
                                            <p class="text-[9px] text-slate-400 italic">tuỳ chọn</p>
                                        @elseif($isDone)
                                            <p class="text-[9px] text-emerald-500 font-semibold">✓ Xong</p>
                                        @elseif($isActive)
                                            <p class="text-[9px] text-indigo-500 font-bold">← Làm tiếp</p>
                                        @else
                                            <p class="text-[9px] text-slate-400">Chờ</p>
                                        @endif
                                    </div>
                                    {{-- Connector line (not after last) --}}
                                    @if(!$loop->last)
                                        <div class="mt-4 h-px w-6 flex-shrink-0
                                            @if($ws['done']) bg-emerald-300
                                            @else            bg-slate-200
                                            @endif"></div>
                                    @endif
                                @endforeach
                            </div>

                            {{-- Current step action card --}}
                            @if(!$allDone)
                            <div class="mt-4 flex flex-wrap items-start gap-3 rounded-xl border
                                @if($currentWizardData['running'])   border-amber-200 bg-amber-50
                                @elseif($currentWizardData['optional']) border-sky-100 bg-sky-50
                                @else                                 border-indigo-100 bg-indigo-50
                                @endif p-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-black
                                        @if($currentWizardData['running'])    text-amber-800
                                        @elseif($currentWizardData['optional']) text-sky-800
                                        @else                                  text-indigo-800
                                        @endif">
                                        Bước {{ $currentWizardData['n'] }}: {{ $currentWizardData['label'] }}
                                        @if($currentWizardData['optional'])<span class="ml-1 text-[10px] font-normal italic opacity-70">(tuỳ chọn)</span>@endif
                                    </p>
                                    <p class="mt-0.5 text-[11px] text-slate-600">{{ $currentWizardData['desc'] }}</p>
                                    <p class="mt-1 text-[10px] text-slate-500">💡 {{ $currentWizardData['tip'] }}</p>
                                </div>
                                <button type="button"
                                    onclick="{{ $currentWizardData['action_js'] }}"
                                    @if($currentWizardData['running']) disabled @endif
                                    class="flex-shrink-0 rounded-xl px-4 py-2 text-xs font-bold text-white transition-opacity
                                        @if($currentWizardData['running'])    bg-amber-500 opacity-70 cursor-not-allowed
                                        @elseif($currentWizardData['optional']) bg-sky-600 hover:bg-sky-700
                                        @else                                  bg-indigo-600 hover:bg-indigo-700
                                        @endif">
                                    {{ $currentWizardData['action_label'] }}
                                </button>
                            </div>
                            @else
                            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-center">
                                <p class="text-sm font-black text-emerald-800">Hoàn thành tất cả các bước!</p>
                                <p class="mt-1 text-xs text-emerald-600">Tất cả audio và ảnh đã được tạo. Bấm <strong>Download All Assets</strong> nếu chưa tải về.</p>
                            </div>
                            @endif
                        </div>
                    </div>
                    {{-- ===== END WIZARD ===== --}}

                    <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <p class="text-xs font-black uppercase tracking-wide text-slate-700">Media Settings</p>
                                <p id="mediaSettingsSaveStatus" class="mt-1 text-[11px] text-slate-500">Chưa lưu thay đổi mới.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" id="toggleMediaSettingsSectionBtn" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Thu nhỏ</button>
                                <span id="queueWorkerBadge" class="inline-flex items-center rounded-full border border-slate-300 bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600" title="Queue worker status">Queue worker: checking...</span>
                                <button type="button" id="saveMediaSettingsBtn" class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-900">Save Media Settings</button>
                            </div>
                        </div>
                        <div id="mediaSettingsSectionBody" class="mt-3 grid gap-3 lg:grid-cols-3">
                            <div class="rounded-lg border border-slate-200 bg-white p-3 lg:col-span-1">
                                <p class="text-[11px] font-black uppercase tracking-wide text-slate-700">TTS</p>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-1">
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">TTS Provider</label>
                                        <select id="mediaCenterTtsProvider" class="w-full rounded-lg border-slate-300 text-sm">
                                            <option value="google" {{ (($firstSentence->tts_provider ?? 'google') === 'google') ? 'selected' : '' }}>Google</option>
                                            <option value="openai" {{ (($firstSentence->tts_provider ?? '') === 'openai') ? 'selected' : '' }}>OpenAI</option>
                                            <option value="gemini" {{ (($firstSentence->tts_provider ?? '') === 'gemini') ? 'selected' : '' }}>Gemini</option>
                                            <option value="microsoft" {{ (($firstSentence->tts_provider ?? '') === 'microsoft') ? 'selected' : '' }}>Microsoft</option>
                                            <option value="vbee" {{ (($firstSentence->tts_provider ?? '') === 'vbee') ? 'selected' : '' }}>Vbee</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Voice gender</label>
                                        <select id="mediaCenterTtsVoiceGender" class="w-full rounded-lg border-slate-300 text-sm">
                                            <option value="female" {{ (($firstSentence->tts_voice_gender ?? 'female') === 'female') ? 'selected' : '' }}>female</option>
                                            <option value="male" {{ (($firstSentence->tts_voice_gender ?? '') === 'male') ? 'selected' : '' }}>male</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Voice name</label>
                                        <select id="mediaCenterTtsVoiceName" class="w-full rounded-lg border-slate-300 text-sm">
                                            <option value="">-- Auto voice --</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">TTS speed</label>
                                        <select id="mediaCenterTtsSpeed" class="w-full rounded-lg border-slate-300 text-sm">
                                            <option value="0.7" {{ ((float)($firstSentence->tts_speed ?? 1.0) === 0.7) ? 'selected' : '' }}>0.7x</option>
                                            <option value="0.8" {{ ((float)($firstSentence->tts_speed ?? 1.0) === 0.8) ? 'selected' : '' }}>0.8x</option>
                                            <option value="0.9" {{ ((float)($firstSentence->tts_speed ?? 1.0) === 0.9) ? 'selected' : '' }}>0.9x</option>
                                            <option value="1.0" {{ ((float)($firstSentence->tts_speed ?? 1.0) === 1.0) ? 'selected' : '' }}>1.0x</option>
                                            <option value="1.1" {{ ((float)($firstSentence->tts_speed ?? 1.0) === 1.1) ? 'selected' : '' }}>1.1x</option>
                                            <option value="1.2" {{ ((float)($firstSentence->tts_speed ?? 1.0) === 1.2) ? 'selected' : '' }}>1.2x</option>
                                            <option value="1.3" {{ ((float)($firstSentence->tts_speed ?? 1.0) === 1.3) ? 'selected' : '' }}>1.3x</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mt-2 flex items-center gap-2">
                                    <button type="button" id="mediaCenterPreviewVoiceBtn" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Nghe thử giọng</button>
                                    <span class="text-[11px] text-slate-500">Dùng provider + gender + voice hiện tại để tạo audio mẫu.</span>
                                </div>
                                <div class="mt-2 grid gap-2 sm:grid-cols-[1fr_auto] lg:grid-cols-1">
                                    <select id="mediaCenterSavedVoiceSamples" class="w-full rounded-lg border-slate-300 text-xs">
                                        <option value="">-- Chưa có giọng mẫu đã lưu --</option>
                                    </select>
                                    <button type="button" id="mediaCenterReplaySavedVoiceBtn" class="rounded-lg bg-slate-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">Nghe lại mẫu đã lưu</button>
                                </div>
                            </div>

                            <div class="rounded-lg border border-slate-200 bg-white p-3 lg:col-span-1">
                                <p class="text-[11px] font-black uppercase tracking-wide text-slate-700">Image</p>
                                <div class="mt-2 grid gap-2">
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Image Provider</label>
                                        <select id="mediaCenterImageProvider" class="w-full rounded-lg border-slate-300 text-sm">
                                            <option value="gemini" {{ (($firstSentence->image_provider ?? 'gemini') === 'gemini') ? 'selected' : '' }}>Gemini (Auto fallback)</option>
                                            <option value="gemini-nano-banana-pro" {{ (($firstSentence->image_provider ?? '') === 'gemini-nano-banana-pro') ? 'selected' : '' }}>Gemini Nano Banana Pro</option>
                                            <option value="flux" {{ (($firstSentence->image_provider ?? '') === 'flux') ? 'selected' : '' }}>Flux</option>
                                            <option value="flux-pro" {{ (($firstSentence->image_provider ?? '') === 'flux-pro') ? 'selected' : '' }}>Flux Pro</option>
                                            <option value="flux-1.1-pro" {{ (($firstSentence->image_provider ?? '') === 'flux-1.1-pro') ? 'selected' : '' }}>Flux 1.1 Pro</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Khung ảnh muốn tạo</label>
                                        <select id="mediaCenterImageAspectRatio" class="w-full rounded-lg border-slate-300 text-sm">
                                            <option value="16:9" {{ $selectedImageAspectRatio === '16:9' ? 'selected' : '' }}>16:9</option>
                                            <option value="9:16" {{ $selectedImageAspectRatio === '9:16' ? 'selected' : '' }}>9:16</option>
                                            <option value="1:1" {{ $selectedImageAspectRatio === '1:1' ? 'selected' : '' }}>1:1</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Kiểu ảnh muốn tạo</label>
                                        <select id="mediaCenterImageStyle" class="w-full rounded-lg border-slate-300 text-sm">
                                            <option value="Cinematic" {{ $selectedImageStyle === 'Cinematic' ? 'selected' : '' }}>Cinematic</option>
                                            <option value="Illustration / Digital Art" {{ $selectedImageStyle === 'Illustration / Digital Art' ? 'selected' : '' }}>Illustration / Digital Art</option>
                                            <option value="Realistic / Photorealistic" {{ $selectedImageStyle === 'Realistic / Photorealistic' ? 'selected' : '' }}>Realistic / Photorealistic</option>
                                            <option value="Fantasy / Epic" {{ $selectedImageStyle === 'Fantasy / Epic' ? 'selected' : '' }}>Fantasy / Epic</option>
                                            <option value="3D / Pixar style" {{ $selectedImageStyle === '3D / Pixar style' ? 'selected' : '' }}>3D / Pixar style</option>
                                            <option value="Anime / Manga" {{ $selectedImageStyle === 'Anime / Manga' ? 'selected' : '' }}>Anime / Manga</option>
                                            <option value="Documentary / Historical" {{ $selectedImageStyle === 'Documentary / Historical' ? 'selected' : '' }}>Documentary / Historical</option>
                                        </select>
                                    </div>
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <p class="text-[11px] font-semibold text-slate-700">Anh minh hoa style dang chon (canh 1)</p>
                                        </div>
                                        <img id="mediaCenterImageStylePreviewImg" src="" alt="Style preview" class="image-style-preview-thumb mt-2 hidden h-24 w-24 cursor-zoom-in rounded-lg border border-slate-200 object-cover">
                                        <p id="mediaCenterImageStylePreviewEmpty" class="mt-2 text-[11px] text-slate-500">Chua co anh minh hoa cho style nay.</p>
                                        <p id="mediaCenterImageStylePreviewMeta" class="mt-1 text-[10px] text-slate-500"></p>
                                    </div>
                                    <p class="text-[10px] text-slate-500">Hinh o dang thumbnail, bam vao de phong lon.</p>
                                </div>
                                <div class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                                    <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                        <input id="useCharacterReferenceToggle" type="checkbox" class="rounded border-slate-300" {{ $selectedUseCharacterReference ? 'checked' : '' }}>
                                        Use character reference khi generate image
                                    </label>
                                    <p class="mt-1 text-[11px] text-slate-500">Bật để tự động inject identity lock vào prompt tạo ảnh.</p>
                                </div>
                            </div>

                            <div class="rounded-lg border border-slate-200 bg-white p-3 lg:col-span-1">
                                <p class="text-[11px] font-black uppercase tracking-wide text-slate-700">Animation</p>
                                <div class="mt-2">
                                    <label class="mb-1 block text-[11px] font-semibold text-slate-600">Animation Provider</label>
                                    <select id="mediaCenterAnimationProvider" class="w-full rounded-lg border-slate-300 text-sm">
                                        <option value="kling" {{ $selectedAnimationProvider === 'kling' ? 'selected' : '' }}>Kling AI</option>
                                        <option value="seedance" {{ $selectedAnimationProvider === 'seedance' ? 'selected' : '' }}>Seedance</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        @php $world = is_array($selectedProject->settings_json ?? null) ? $selectedProject->settings_json : []; @endphp
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-black uppercase tracking-wide text-slate-700">Nhân vật chính</p>
                                <button type="button" id="toggleMainCharacterSectionBtn" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Thu nhỏ</button>
                            </div>
                            <div id="mainCharacterSectionBody" class="mt-2">
                                <input id="mainCharacterNameInput" type="text" class="mt-1 w-full rounded-lg border-slate-300 text-sm font-semibold text-slate-900"
                                    value="{{ $selectedProject->main_character_name ?: '' }}" placeholder="Tên nhân vật chính">
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-600">Mô tả nhân vật chính</p>
                                    <button type="button" id="mainCharacterProfileVieBtn" class="rounded-md border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-700 hover:bg-indigo-100">VIE</button>
                                </div>
                                <textarea id="mainCharacterProfileInput" rows="7" class="mt-2 w-full rounded-lg border-slate-300 text-xs text-slate-700"
                                    placeholder="Mô tả nhân vật chính">{{ $selectedProject->main_character_profile ?: '' }}</textarea>
                                <div class="mt-2 space-y-2">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-600">AI tạo ảnh reference nhân vật</p>
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" id="genRefFaceBtn"
                                            class="rounded-xl bg-amber-600 px-3 py-2 text-xs font-bold text-white hover:bg-amber-700"
                                            title="Tạo ảnh mặt close-up (1:1) — không cần ref trước">
                                            Tạo ảnh mặt
                                        </button>
                                        <button type="button" id="genRefMainCostumeBtn"
                                            class="rounded-xl bg-amber-700 px-3 py-2 text-xs font-bold text-white hover:bg-amber-800"
                                            title="Tạo half_body + full_body với trang phục chính — ref ảnh mặt đã có">
                                            Mặt + trang phục chính
                                        </button>
                                        <button type="button" id="genRefAltCostumeBtn"
                                            class="rounded-xl bg-orange-600 px-3 py-2 text-xs font-bold text-white hover:bg-orange-700"
                                            title="Tạo half_body + full_body với trang phục thay thế — ref ảnh mặt đã có">
                                            Mặt + trang phục 2
                                        </button>
                                    </div>
                                    <div id="altCostumeInputArea" class="hidden rounded-lg border border-orange-200 bg-orange-50 p-2">
                                        <label class="block text-[11px] font-semibold text-orange-800">Mô tả trang phục 2 (tiếng Anh)</label>
                                        <div class="mt-1 flex gap-2">
                                            <input type="text" id="altCostumeDescInput"
                                                class="flex-1 rounded-lg border-orange-300 text-xs placeholder-orange-300"
                                                placeholder="vd: monk robe and straw hat, royal armor, scholar robe...">
                                            <button type="button" id="confirmGenAltCostumeBtn"
                                                class="flex-shrink-0 rounded-xl bg-orange-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-orange-700">
                                                Tạo
                                            </button>
                                        </div>
                                        <p class="mt-1 text-[10px] text-orange-600">Ảnh này sẽ ref ảnh mặt đã tạo để giữ đúng khuôn mặt.</p>
                                    </div>
                                    <p id="mainCharacterRefStatus" class="text-[11px] text-slate-500">{{ !empty($selectedMainRefs) ? ('Đã có ' . count($selectedMainRefs) . ' reference ảnh.') : 'Chưa có reference ảnh.' }}</p>
                                </div>
                                <div class="mt-2 rounded-lg border border-slate-200 bg-white p-2">
                                    <label for="mainCharacterRefUploadInput" class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Upload ảnh làm reference</label>
                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                        <input id="mainCharacterRefUploadInput" type="file" accept="image/png,image/jpeg,image/webp" multiple class="block w-full rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700 sm:max-w-[260px]">
                                        <button type="button" id="uploadMainCharacterRefBtn" class="rounded-xl bg-sky-600 px-3 py-2 text-xs font-bold text-white hover:bg-sky-700">Upload ảnh ref</button>
                                    </div>
                                    <p class="mt-1 text-[11px] text-slate-500">Hỗ trợ JPG, PNG, WEBP. Có thể chọn nhiều ảnh một lần.</p>
                                </div>
                                <div id="mainCharacterRefsPanel" class="mt-2 rounded-lg border border-slate-200 bg-white p-2">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Main character references</p>
                                    <div id="mainCharacterRefsList" class="mt-2 flex flex-wrap gap-2">
                                        @foreach($selectedMainRefs as $ref)
                                            @php
                                                $refPath = trim((string) ($ref['path'] ?? ''));
                                                $refType = trim((string) ($ref['type'] ?? 'ref'));
                                            @endphp
                                            @if($refPath !== '')
                                                @php $refUrl = Storage::url($refPath); @endphp
                                                <div class="group relative h-16 w-16">
                                                    <button type="button" class="main-character-ref-thumb relative block h-16 w-16 overflow-hidden rounded-lg border border-slate-200 bg-slate-100 hover:border-indigo-300" data-full-url="{{ $refUrl }}" title="Click để phóng lớn">
                                                        <img src="{{ $refUrl }}" alt="{{ $refType }}" class="h-full w-full object-cover">
                                                        <span class="absolute bottom-0 left-0 right-0 bg-slate-900/70 px-1 py-0.5 text-center text-[10px] font-semibold text-white">{{ $refType }}</span>
                                                    </button>
                                                    <button type="button" class="delete-main-character-ref-btn absolute -right-1 -top-1 inline-flex h-5 w-5 items-center justify-center rounded-full border border-white bg-rose-600 text-[11px] font-bold leading-none text-white shadow hover:bg-rose-700" data-reference-path="{{ $refPath }}" title="Xóa ảnh reference">x</button>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                    <p id="mainCharacterRefsEmpty" class="{{ !empty($selectedMainRefs) ? 'hidden' : '' }} mt-2 text-[11px] text-slate-500">Chưa có reference ảnh.</p>
                                    <details id="mainCharacterIdentityLockDetails" class="mt-2 {{ $selectedIdentityLock !== '' ? '' : 'hidden' }}">
                                        <summary class="cursor-pointer text-[11px] font-semibold text-slate-600">Xem identity lock đang dùng</summary>
                                        <pre id="mainCharacterIdentityLockContent" class="mt-1 whitespace-pre-wrap rounded-md bg-slate-50 p-2 text-[11px] text-slate-600">{{ $selectedIdentityLock }}</pre>
                                    </details>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-black uppercase tracking-wide text-slate-700">Hồ sơ bối cảnh</p>
                                <button type="button" id="toggleWorldProfileSectionBtn" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Thu nhỏ</button>
                            </div>
                            <div id="worldProfileSectionBody" class="mt-2">
                                <input id="worldEraInput" type="text" class="w-full rounded-lg border-slate-300 text-xs" placeholder="Era"
                                    value="{{ $world['story_era'] ?? '' }}">
                                <input id="worldGenreInput" type="text" class="mt-2 w-full rounded-lg border-slate-300 text-xs" placeholder="Genre"
                                    value="{{ $world['story_genre'] ?? '' }}">
                                <textarea id="worldContextInput" rows="2" class="mt-2 w-full rounded-lg border-slate-300 text-xs" placeholder="World context">{{ $world['world_context'] ?? '' }}</textarea>
                                <textarea id="worldForbiddenInput" rows="2" class="mt-2 w-full rounded-lg border-slate-300 text-xs" placeholder="Forbidden elements">{{ $world['forbidden_elements'] ?? '' }}</textarea>
                                <div class="mt-2 flex justify-end">
                                    <button type="button" id="saveWorldProfileBtn" class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700">Save World Profile</button>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 lg:col-span-2">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-black uppercase tracking-wide text-slate-700">Nhân vật phụ</p>
                                <button type="button" id="toggleSideCharactersSectionBtn" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Thu nhỏ</button>
                            </div>
                            @php $characters = is_array($selectedProject->characters_json) ? $selectedProject->characters_json : []; @endphp
                            <div id="sideCharactersSectionBody" class="mt-2">
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-600">JSON nhân vật phụ</p>
                                    <button type="button" id="formatCharactersJsonBtn" class="rounded-md border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Format JSON</button>
                                </div>
                                <textarea id="charactersJsonInput" rows="10" class="w-full rounded-lg border-slate-300 text-xs font-mono leading-5 text-slate-700"
                                    placeholder='[{"name":"...","gender":"...","race":"...","skin_tone":"..."}]'>{{ json_encode($characters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</textarea>
                                <p id="charactersJsonHint" class="mt-1 text-[11px] text-slate-500">Cho phép sửa trực tiếp JSON nhân vật phụ rồi bấm Save Character Info.</p>
                                <div id="sideCharactersPreview" class="mt-2 grid gap-2 sm:grid-cols-2"></div>
                                <div class="mt-2 flex justify-end">
                                    <button type="button" id="saveCharacterInfoBtn" class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700">Save Character Info</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="workspaceStatus" class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">Sẵn sàng.</div>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" id="collapseAllSentencesBtn" class="rounded-md border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Collapse all</button>
                        <button type="button" id="expandAllSentencesBtn" class="rounded-md border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Expand all</button>
                        <button type="button" id="toggleSelectAllSentencesBtn" class="rounded-md border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Select / Deselect all</button>
                        <button type="button" id="playAllSentenceAudioBtn" class="rounded-md border border-cyan-300 bg-cyan-50 px-2 py-1 text-[11px] font-semibold text-cyan-700 hover:bg-cyan-100">Nghe full audio</button>
                        <span id="totalSentenceAudioDurationInfo" class="rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-semibold text-slate-600">Tong thoi luong: dang tinh...</span>
                    </div>

                    @php
                        $sentenceImageStats = [];
                        $characterReferenceThumbs = [];
                        foreach (($selectedMainRefs ?? []) as $refItem) {
                            if (!is_array($refItem)) {
                                continue;
                            }

                            $refPath = trim((string) ($refItem['path'] ?? ''));
                            if ($refPath === '') {
                                continue;
                            }

                            $characterReferenceThumbs[] = [
                                'path' => $refPath,
                                'url' => Storage::url($refPath),
                                'type' => trim((string) ($refItem['type'] ?? 'ref')) ?: 'ref',
                                'source' => trim((string) ($refItem['source'] ?? 'generated')) ?: 'generated',
                            ];
                        }

                        foreach ($selectedProject->sentences as $candidateSentence) {
                            $candidateMeta = is_array($candidateSentence->metadata_json ?? null) ? $candidateSentence->metadata_json : [];
                            $candidatePaths = is_array($candidateMeta['image_paths'] ?? null) ? $candidateMeta['image_paths'] : [];
                            $candidatePaths = array_values(array_filter(array_map(static fn($p) => trim((string) $p), $candidatePaths), static fn($p) => $p !== ''));
                            $candidateMainPath = trim((string) ($candidateSentence->image_path ?? ''));
                            if ($candidateMainPath !== '' && !in_array($candidateMainPath, $candidatePaths, true)) {
                                array_unshift($candidatePaths, $candidateMainPath);
                            }
                            $candidatePaths = array_values(array_unique($candidatePaths));
                            $candidateLatestPath = !empty($candidatePaths) ? (string) end($candidatePaths) : '';

                            $sentenceImageStats[(int) $candidateSentence->id] = [
                                'index' => (int) $candidateSentence->sentence_index,
                                'image_count' => count($candidatePaths),
                                'latest_image_path' => $candidateLatestPath,
                                'latest_image_url' => $candidateLatestPath !== '' ? Storage::url($candidateLatestPath) : '',
                            ];
                        }
                    @endphp

                    <div id="sentenceCardsContainer" class="mt-4 space-y-3">
                        @foreach($selectedProject->sentences as $sentence)
                            @php $isEvenSentence = ((int) $sentence->sentence_index % 2) === 0; @endphp
                            @php
                                $animationMeta = is_array($sentence->metadata_json ?? null) ? $sentence->metadata_json : [];
                                $animationItems = is_array($animationMeta['animations'] ?? null) ? $animationMeta['animations'] : [];
                                $latestAnimation = end($animationItems);
                                $latestAnimationPath = is_array($latestAnimation) ? trim((string) ($latestAnimation['path'] ?? '')) : '';
                                $generationMeta = is_array($animationMeta['generation'] ?? null) ? $animationMeta['generation'] : [];
                                $imageGeneration = is_array($generationMeta['image'] ?? null) ? $generationMeta['image'] : [];
                                $animationGeneration = is_array($generationMeta['animation'] ?? null) ? $generationMeta['animation'] : [];
                                $animationStatus = trim((string) ($animationGeneration['status'] ?? ''));
                                $animationProgress = (int) ($animationGeneration['progress'] ?? 0);
                                $ttsAudioPath = trim((string) ($sentence->tts_audio_path ?? ''));
                                $ttsAudioUrl = $ttsAudioPath !== ''
                                    ? (str_starts_with($ttsAudioPath, 'public/')
                                        ? '/storage/' . ltrim(substr($ttsAudioPath, 7), '/')
                                        : $ttsAudioPath)
                                    : '';
                                $ttsProvider = trim((string) ($sentence->tts_provider ?? ''));
                                $ttsVoiceName = trim((string) ($sentence->tts_voice_name ?? ''));
                                $ttsVoiceGender = trim((string) ($sentence->tts_voice_gender ?? ''));
                                $ttsSpeedRaw = $sentence->tts_speed ?? null;
                                $ttsSpeed = is_numeric($ttsSpeedRaw) ? number_format((float) $ttsSpeedRaw, 1) . 'x' : '';
                                $ttsInfoParts = array_values(array_filter([
                                    $ttsProvider !== '' ? ('Provider: ' . strtoupper($ttsProvider)) : null,
                                    $ttsVoiceName !== '' ? ('Voice: ' . $ttsVoiceName) : null,
                                    $ttsVoiceGender !== '' ? ('Gender: ' . $ttsVoiceGender) : null,
                                    $ttsSpeed !== '' ? ('Speed: ' . $ttsSpeed) : null,
                                ]));
                                $ttsInfoText = !empty($ttsInfoParts) ? implode(' | ', $ttsInfoParts) : '';
                                $selectedReferenceSentenceIds = is_array($animationMeta['image_reference_sentence_ids'] ?? null)
                                    ? array_values(array_filter(array_map(static fn($id) => (int) $id, $animationMeta['image_reference_sentence_ids']), static fn($id) => $id > 0))
                                    : [];
                                $includeCharacterReferences = array_key_exists('include_character_references', $animationMeta)
                                    ? (bool) $animationMeta['include_character_references']
                                    : (bool) ($selectedUseCharacterReference ?? false);
                            @endphp
                            <article class="sentence-card rounded-2xl border p-3 shadow-sm {{ $isEvenSentence ? 'border-emerald-200 bg-emerald-50/50' : 'border-rose-200 bg-rose-50/50' }}" data-sentence-id="{{ $sentence->id }}" data-sentence-index="{{ $sentence->sentence_index }}" data-image-status="{{ trim((string) ($imageGeneration['status'] ?? '')) }}" data-tts-audio-url="{{ $ttsAudioUrl }}">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <label class="inline-flex items-center gap-1 rounded bg-white/80 px-2 py-0.5 text-[11px] font-semibold text-slate-700">
                                            <input type="checkbox" class="sentence-bulk-select h-3.5 w-3.5 rounded border-slate-300" value="{{ $sentence->id }}">
                                            Chon
                                        </label>
                                        <p class="text-sm font-black text-slate-800">Câu #{{ $sentence->sentence_index }}</p>
                                        <button type="button" class="sentence-collapse-toggle-btn rounded-md border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Expand</button>
                                    </div>
                                    <div class="sentence-card-expand-only hidden flex flex-col items-end gap-1">
                                        <div class="flex flex-wrap justify-end gap-1">
                                            <button type="button" class="save-sentence-btn rounded-lg bg-slate-700 px-2 py-1 text-xs font-semibold text-white">Save</button>
                                            <button type="button" class="generate-tts-btn rounded-lg bg-emerald-600 px-2 py-1 text-xs font-semibold text-white">Generate TTS</button>
                                            <button type="button" class="generate-image-btn rounded-lg bg-orange-500 px-2 py-1 text-xs font-semibold text-white">Generate Image</button>
                                            @if($ttsAudioUrl !== '')
                                                <button type="button" class="sentence-tts-audio-preview-btn rounded-lg bg-cyan-50 px-2 py-1 text-[11px] font-semibold text-cyan-700 underline" data-audio-url="{{ $ttsAudioUrl }}">Audio</button>
                                            @endif
                                            @if($latestAnimationPath !== '')
                                                <a href="{{ Storage::url($latestAnimationPath) }}" target="_blank" class="rounded-lg bg-cyan-50 px-2 py-1 text-[11px] font-semibold text-cyan-700 underline">Animation</a>
                                            @endif
                                        </div>
                                        <p class="text-[11px] font-medium text-slate-700">
                                            @if($ttsAudioUrl !== '')
                                                <span class="text-emerald-700">TTS da tao.</span>
                                                @if($ttsInfoText !== '')
                                                    <span>{{ $ttsInfoText }}</span>
                                                @endif
                                            @else
                                                <span class="text-slate-500">TTS chua tao audio.</span>
                                                @if($ttsInfoText !== '')
                                                    <span>{{ $ttsInfoText }}</span>
                                                @endif
                                            @endif
                                        </p>
                                        <p class="sentence-inline-status {{ empty($imageGeneration) && empty($animationGeneration) ? 'hidden' : '' }} text-[11px] font-semibold text-slate-600">
                                            @php
                                                $inlineParts = [];
                                                if (!empty($imageGeneration)) {
                                                    $inlineParts[] = 'Image: ' . trim((string) ($imageGeneration['status'] ?? ''));
                                                }
                                                if (!empty($animationGeneration)) {
                                                    $inlineParts[] = 'Animation: ' . trim((string) ($animationGeneration['status'] ?? ''));
                                                }
                                            @endphp
                                            {{ !empty($inlineParts) ? implode(' | ', $inlineParts) : '' }}
                                        </p>
                                        <div class="animation-progress-wrap {{ in_array($animationStatus, ['queued', 'running', 'completed', 'failed'], true) ? '' : 'hidden' }} mt-1 w-56" data-status="{{ $animationStatus }}" data-progress="{{ $animationProgress }}">
                                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-200">
                                                <div class="animation-progress-fill h-full rounded-full bg-indigo-500" style="width: {{ max(0, min(100, $animationProgress)) }}%"></div>
                                            </div>
                                            <p class="animation-progress-text mt-1 text-[10px] text-slate-600">
                                                {{ $animationStatus !== '' ? (strtoupper($animationStatus) . ' - ' . max(0, min(100, $animationProgress)) . '%') : '' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-2 flex flex-wrap items-center gap-1 text-[10px]">
                                    <span class="rounded bg-slate-800 px-1.5 py-0.5 font-bold uppercase tracking-wide text-white">TTS</span>
                                    @if($ttsAudioUrl !== '')
                                        <span class="rounded bg-emerald-100 px-1.5 py-0.5 font-semibold text-emerald-700">Audio ready</span>
                                        <button type="button" class="sentence-tts-audio-preview-btn rounded bg-cyan-50 px-1.5 py-0.5 font-semibold text-cyan-700 underline" data-audio-url="{{ $ttsAudioUrl }}">Nghe audio</button>
                                    @else
                                        <span class="rounded bg-slate-200 px-1.5 py-0.5 font-semibold text-slate-600">Chua tao audio</span>
                                    @endif
                                    @if($ttsInfoText !== '')
                                        <span class="rounded bg-white/90 px-1.5 py-0.5 font-medium text-slate-700">{{ $ttsInfoText }}</span>
                                    @endif
                                </div>

                                @php
                                    $sentenceMeta = is_array($sentence->metadata_json ?? null) ? $sentence->metadata_json : [];
                                    $galleryPaths = is_array($sentenceMeta['image_paths'] ?? null) ? $sentenceMeta['image_paths'] : [];
                                    $galleryPaths = array_values(array_filter(array_map(static fn($p) => trim((string) $p), $galleryPaths), static fn($p) => $p !== ''));
                                    $mainImagePath = trim((string) ($sentence->image_path ?? ''));
                                    if ($mainImagePath !== '' && !in_array($mainImagePath, $galleryPaths, true)) {
                                        array_unshift($galleryPaths, $mainImagePath);
                                    }
                                    $galleryPaths = array_values(array_unique($galleryPaths));

                                    $animationThumbItems = [];
                                    foreach ($animationItems as $animItem) {
                                        if (!is_array($animItem)) {
                                            continue;
                                        }

                                        $animPath = trim((string) ($animItem['path'] ?? ''));
                                        if ($animPath === '') {
                                            continue;
                                        }

                                        $animationThumbItems[] = [
                                            'path' => $animPath,
                                            'provider' => trim((string) ($animItem['provider'] ?? '')),
                                            'mode' => trim((string) ($animItem['mode'] ?? '')),
                                            'frame_index' => (int) ($animItem['frame_index'] ?? 0),
                                            'frame_total' => (int) ($animItem['frame_total'] ?? 1),
                                        ];
                                    }
                                @endphp

                                @if(!empty($galleryPaths) || !empty($animationThumbItems))
                                    <div class="mb-3 rounded-xl border border-slate-200 bg-white/80 p-2">
                                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Thumbnail ảnh/animation đã tạo</p>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($galleryPaths as $idx => $galleryPath)
                                                @php $galleryUrl = Storage::url($galleryPath); @endphp
                                                <div class="group relative h-20 w-20 shrink-0">
                                                    <label class="absolute left-1 top-1 z-10 inline-flex items-center gap-1 rounded bg-white/90 px-1 py-0.5 text-[10px] font-semibold text-slate-700 shadow">
                                                        <input type="checkbox" class="sentence-animation-image-select h-3 w-3 rounded border-slate-300" data-image-path="{{ $galleryPath }}" {{ $idx === 0 ? 'checked' : '' }}>
                                                        Use
                                                    </label>
                                                    <button
                                                        type="button"
                                                        class="sentence-image-thumb relative h-20 w-20 overflow-hidden rounded-lg border border-slate-200 bg-slate-100 hover:border-indigo-300"
                                                        data-full-url="{{ $galleryUrl }}"
                                                        data-image-path="{{ $galleryPath }}"
                                                        title="Click để phóng lớn"
                                                    >
                                                        <img src="{{ $galleryUrl }}" alt="Sentence image thumbnail" class="h-full w-full object-cover">
                                                        <span class="absolute bottom-1 right-1 rounded bg-slate-900/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $idx + 1 }}</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="open-animation-studio-btn absolute bottom-1 left-1 hidden items-center justify-center rounded-md bg-indigo-700/90 px-1.5 py-0.5 text-[10px] font-semibold text-white shadow hover:bg-indigo-800 group-hover:flex"
                                                        data-image-path="{{ $galleryPath }}"
                                                        title="Tạo Animation"
                                                    >
                                                        Tạo Animation
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="delete-sentence-image-btn absolute -right-1 -top-1 hidden h-5 w-5 items-center justify-center rounded-full bg-rose-600 text-xs font-bold text-white shadow hover:bg-rose-700 group-hover:flex"
                                                        data-image-path="{{ $galleryPath }}"
                                                        title="Xóa ảnh này"
                                                    >
                                                        x
                                                    </button>
                                                </div>
                                            @endforeach

                                            <div class="sentence-animation-thumbs-list contents">
                                                @foreach($animationThumbItems as $aIdx => $anim)
                                                    @php
                                                        $animUrl = Storage::url($anim['path']);
                                                        $providerRaw = strtolower(trim((string) ($anim['provider'] ?? '')));
                                                        $providerLabel = $providerRaw === 'seedance' ? 'S' : ($providerRaw === 'kling' ? 'K' : 'L');
                                                        $providerClass = $providerRaw === 'seedance'
                                                            ? 'bg-emerald-700/85'
                                                            : ($providerRaw === 'kling' ? 'bg-indigo-700/85' : 'bg-slate-700/85');
                                                        $modeLabel = $anim['mode'] !== '' ? $anim['mode'] : 'animation';
                                                        $frameLabel = $anim['frame_total'] > 1
                                                            ? ('F' . ($anim['frame_index'] + 1) . '/' . $anim['frame_total'])
                                                            : 'F1';
                                                    @endphp
                                                    <div class="group relative h-20 w-20 shrink-0">
                                                        <button type="button" class="sentence-animation-thumb relative block h-20 w-20 overflow-hidden rounded-lg border border-indigo-200 bg-indigo-50 hover:border-indigo-400" data-video-url="{{ $animUrl }}" title="Click để phóng lớn animation">
                                                            <video class="h-full w-full object-cover" preload="metadata" muted playsinline>
                                                                <source src="{{ $animUrl }}" type="video/mp4">
                                                            </video>
                                                            <span class="absolute bottom-1 left-1 rounded bg-indigo-900/75 px-1 py-0.5 text-[9px] font-semibold text-white">{{ $frameLabel }}</span>
                                                            <span class="absolute right-1 top-1 rounded bg-slate-900/70 px-1 py-0.5 text-[9px] font-semibold text-white">MOV</span>
                                                            <span class="absolute left-1 top-1 rounded {{ $providerClass }} px-1 py-0.5 text-[9px] font-bold text-white">{{ $providerLabel }}</span>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="delete-sentence-animation-btn absolute -right-1 -top-1 hidden h-5 w-5 items-center justify-center rounded-full bg-rose-600 text-xs font-bold text-white shadow hover:bg-rose-700 group-hover:flex"
                                                            data-animation-path="{{ $anim['path'] }}"
                                                            title="Xóa clip này"
                                                        >
                                                            x
                                                        </button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                        <p class="sentence-animation-empty {{ !empty($animationThumbItems) ? 'hidden' : '' }} mt-2 text-[11px] text-slate-500">Chưa có animation hoàn tất.</p>
                                    </div>
                                @endif

                                <div class="animation-studio-modal fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 p-4">
                                    <div class="w-full max-w-3xl rounded-2xl border border-indigo-200 bg-white shadow-2xl">
                                        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                                            <p class="text-sm font-bold text-indigo-800">Animation Studio - Câu #{{ $sentence->sentence_index }}</p>
                                            <button type="button" class="close-animation-studio-btn rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200">Đóng</button>
                                        </div>
                                        <div class="max-h-[80vh] overflow-y-auto p-4">
                                    <div class="grid gap-2 lg:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold text-slate-600">Animation mode</label>
                                            <select class="sentence-animation-mode w-full rounded-lg border-slate-300 bg-white text-xs">
                                                <option value="image-to-motion">1) Image -> Motion (Lam anh tinh chuyen dong nhe)</option>
                                                <option value="image-to-cinematic-shot">2) Image -> Cinematic Shot (Chuyen dong may quay dien anh)</option>
                                                <option value="image-to-action">3) Image -> Action (Tao chuyen dong manh)</option>
                                                <option value="image-to-story-sequence">4) Image -> Story Sequence (Chuoi nhieu frame)</option>
                                                <option value="image-to-character-animation">5) Image -> Character Animation (Khau hinh/dien xuat nhan vat)</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold text-slate-600">Camera angle</label>
                                            <select class="sentence-animation-camera-angle w-full rounded-lg border-slate-300 bg-white text-xs">
                                                <option value="auto">Auto cinematic (Tu dong dien anh)</option>
                                                <option value="close-up">Close-up (Can canh)</option>
                                                <option value="medium-shot">Medium shot (Trung canh)</option>
                                                <option value="wide-shot">Wide shot (Toan canh)</option>
                                                <option value="over-the-shoulder">Over the shoulder (Qua vai)</option>
                                                <option value="low-angle">Low angle (Goc may thap)</option>
                                                <option value="high-angle">High angle (Goc may cao)</option>
                                                <option value="top-down">Top down (Goc tren xuong)</option>
                                                <option value="dolly-in">Dolly in (Day may vao)</option>
                                                <option value="dolly-out">Dolly out (Keo may ra)</option>
                                                <option value="pan-left">Pan left (Quet trai)</option>
                                                <option value="pan-right">Pan right (Quet phai)</option>
                                                <option value="tracking-shot">Tracking shot (Bam theo chu the)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Camera choreography preset</label>
                                        <select class="sentence-camera-choreo-preset w-full rounded-lg border-slate-300 bg-white text-xs">
                                            <option value="">-- Chon preset nhanh --</option>
                                            <option value="slow-drama">Slow drama</option>
                                            <option value="action-chase">Action chase</option>
                                            <option value="emotional-close-up">Emotional close-up</option>
                                        </select>
                                    </div>
                                    <div class="mt-2">
                                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Yeu cau them (AI se viet lai prompt cho ngon hon)</label>
                                        <textarea class="sentence-animation-instruction w-full rounded-lg border-slate-300 bg-white text-xs" rows="2" placeholder="Vi du: Camera push-in cham vao khuon mat, toc bay nhe, giu dung boi canh va trang phuc..."></textarea>
                                    </div>
                                    <div class="mt-2 rounded-lg border border-indigo-200 bg-white p-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-indigo-700">Story Sequence Timeline</p>
                                            <span class="text-[10px] text-slate-500">Keo tha de reorder frame</span>
                                        </div>
                                        <div class="story-sequence-timeline mt-2 flex flex-wrap gap-2"></div>
                                        <p class="story-sequence-timeline-hint mt-1 text-[11px] text-slate-500">Tick Use tren thumbnail de tao frame list.</p>
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button type="button" class="suggest-animation-plan-btn rounded-lg border border-indigo-300 bg-white px-2 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">AI de xuat motion + rewrite prompt</button>
                                        <span class="text-[11px] text-slate-500">Mode Story Sequence cho phep chon nhieu thumbnail da tick Use.</span>
                                    </div>

                                    <div class="mt-3">
                                        <div class="mb-1 flex items-center justify-between gap-2">
                                            <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">Prompt tạo video</label>
                                            <button type="button" class="translate-vie-btn rounded-md border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-700 hover:bg-indigo-100" data-field="video">VIE</button>
                                        </div>
                                        <textarea class="sentence-video-prompt w-full rounded-lg border-slate-300 bg-white text-sm" rows="5">{{ $sentence->video_prompt }}</textarea>
                                    </div>
                                        </div>
                                        <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
                                            <button type="button" class="close-animation-studio-btn rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Đóng</button>
                                            <button type="button" class="generate-animation-btn rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Tạo Animation</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-2 grid gap-3 lg:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">Text để tạo TTS</label>
                                        <textarea class="sentence-tts-text w-full rounded-lg border-slate-300 text-sm" rows="4">{{ $sentence->tts_text ?: $sentence->sentence_text }}</textarea>
                                    </div>
                                    <div>
                                        <div class="mb-1 flex items-center justify-between gap-2">
                                            <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">Prompt tạo ảnh</label>
                                            <div class="flex items-center gap-1">
                                                <button type="button" class="quick-gen-prompt-btn rounded-md border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700 hover:bg-emerald-100">Gen Prompt</button>
                                                <button type="button" class="quick-gen-image-btn rounded-md border border-orange-300 bg-orange-50 px-2 py-0.5 text-[10px] font-bold text-orange-700 hover:bg-orange-100">Gen Image</button>
                                                <button type="button" class="translate-vie-btn rounded-md border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-700 hover:bg-indigo-100" data-field="image">VIE</button>
                                            </div>
                                        </div>
                                        <textarea class="sentence-image-prompt w-full rounded-lg border-slate-300 text-sm" rows="4">{{ $sentence->image_prompt }}</textarea>
                                        <div class="mt-2 flex items-center gap-2">
                                            <label class="inline-flex items-center gap-1 text-[11px] font-semibold text-amber-800">
                                                <input
                                                    type="checkbox"
                                                    class="sentence-enable-image-reference h-3.5 w-3.5 rounded border-amber-400"
                                                    {{ !empty($selectedReferenceSentenceIds) ? 'checked' : '' }}
                                                >
                                                Tham chiếu
                                            </label>
                                            <select class="sentence-image-reference-sentence-select min-w-0 flex-1 rounded-lg border-amber-300 bg-white text-xs">
                                                <option value="">-- Không chọn tham chiếu --</option>
                                                @foreach($selectedProject->sentences as $refSentence)
                                                    @if((int) $refSentence->id !== (int) $sentence->id)
                                                        @php
                                                            $refStats = $sentenceImageStats[(int) $refSentence->id] ?? ['index' => (int) $refSentence->sentence_index, 'image_count' => 0];
                                                            $refLabel = 'Câu #' . ($refStats['index'] ?? (int) $refSentence->sentence_index) . ' | ảnh: ' . ($refStats['image_count'] ?? 0);
                                                            $isSelectedRef = !empty($selectedReferenceSentenceIds) && (int) $selectedReferenceSentenceIds[0] === (int) $refSentence->id;
                                                            $refImageCount = (int) ($refStats['image_count'] ?? 0);
                                                        @endphp
                                                        @if($refImageCount > 0)
                                                            <option value="{{ (int) $refSentence->id }}" {{ $isSelectedRef ? 'selected' : '' }}>{{ $refLabel }}</option>
                                                        @endif
                                                    @endif
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="sentence-card-expand-only hidden">
                                    <div class="mt-3 flex items-center justify-end gap-2 rounded-lg border border-slate-200 bg-white/80 px-3 py-2">
                                        <span class="text-[11px] text-slate-500">Luu toan bo Text TTS + Prompt tao anh/video.</span>
                                        <button type="button" class="save-sentence-btn rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-900">Save toan bo phan nay</button>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div id="sentenceImageLightbox" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 p-4">
                        <button type="button" id="sentenceImageLightboxClose" class="absolute right-4 top-4 rounded-lg bg-white/90 px-3 py-1 text-sm font-semibold text-slate-900 hover:bg-white">Đóng</button>
                        <img id="sentenceImageLightboxImg" src="" alt="Preview" class="max-h-[90vh] max-w-[95vw] rounded-xl border border-white/40 shadow-2xl">
                    </div>

                    <div id="sentenceAnimationLightbox" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 p-4">
                        <button type="button" id="sentenceAnimationLightboxClose" class="absolute right-4 top-4 rounded-lg bg-white/90 px-3 py-1 text-sm font-semibold text-slate-900 hover:bg-white">Đóng</button>
                        <video id="sentenceAnimationLightboxVideo" class="max-h-[90vh] max-w-[95vw] rounded-xl border border-white/40 shadow-2xl" controls playsinline></video>
                    </div>

                    <div id="sentenceAudioLightbox" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 p-4">
                        <div class="relative w-full max-w-xl rounded-xl border border-white/30 bg-white p-4 shadow-2xl">
                            <button type="button" id="sentenceAudioLightboxClose" class="absolute right-3 top-3 rounded-lg bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-900 hover:bg-slate-200">Đóng</button>
                            <p id="sentenceAudioLightboxTitle" class="mb-1 pr-16 text-sm font-bold text-slate-800">Nghe TTS</p>
                            <p id="sentenceAudioLightboxMeta" class="mb-3 text-xs text-slate-500"></p>
                            <audio id="sentenceAudioLightboxPlayer" class="w-full" controls></audio>
                        </div>
                    </div>

                    <div id="missingAssetsModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 p-4">
                        <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">
                            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                                <h3 class="text-sm font-bold text-slate-800">Danh sach cau thieu file</h3>
                                <button type="button" id="missingAssetsModalClose" class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200">Đóng</button>
                            </div>
                            <div class="space-y-3 p-4">
                                <p id="missingAssetsModalSummary" class="text-xs text-slate-600"></p>
                                <div class="max-h-[50vh] overflow-y-auto rounded-lg border border-slate-200 bg-slate-50 p-2">
                                    <ul id="missingAssetsModalList" class="space-y-1 text-xs text-slate-700"></ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="vieTranslateModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 p-4">
                        <div class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl">
                            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                                <h3 id="vieTranslateModalTitle" class="text-sm font-bold text-slate-800">Bản dịch tiếng Việt</h3>
                                <button type="button" id="vieTranslateModalClose" class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200">Đóng</button>
                            </div>
                            <div class="space-y-3 p-4">
                                <p id="vieTranslateModalStatus" class="text-xs text-slate-500">Đang chờ nội dung...</p>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">English (đang lưu)</label>
                                    <textarea id="vieTranslateModalEnglish" rows="6" class="w-full rounded-lg border-slate-300 text-sm" readonly></textarea>
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Bản tiếng Việt</label>
                                    <textarea id="vieTranslateModalContent" rows="6" class="w-full rounded-lg border-slate-300 text-sm" readonly></textarea>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Yêu cầu AI chỉnh sửa</label>
                                    <textarea id="vieTranslateModalInstruction" rows="3" class="w-full rounded-lg border-slate-300 text-sm" placeholder="Ví dụ: Viết lại trang trọng hơn nhưng vẫn giữ bối cảnh cổ trang và nhân vật hiện tại..."></textarea>
                                    <div class="mt-2 flex justify-end">
                                        <button type="button" id="vieTranslateRewriteBtn" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">AI viết lại & cập nhật EN/VI</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>
@endsection

@if($selectedProject)
@push('scripts')
<script>
(function() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const projectId = @json($selectedProject->id ?? 0);
    const statusEl = document.getElementById('workspaceStatus');
    const collapseAllSentencesBtn = document.getElementById('collapseAllSentencesBtn');
    const expandAllSentencesBtn = document.getElementById('expandAllSentencesBtn');
    const toggleSelectAllSentencesBtn = document.getElementById('toggleSelectAllSentencesBtn');
    const regenerateWeakPromptsBtn = document.getElementById('regenerateWeakPromptsBtn');
    const downloadAllAssetsBtn = document.getElementById('downloadAllAssetsBtn');
    const playAllSentenceAudioBtn = document.getElementById('playAllSentenceAudioBtn');
    const totalSentenceAudioDurationInfo = document.getElementById('totalSentenceAudioDurationInfo');
    const toggleMediaSettingsSectionBtn = document.getElementById('toggleMediaSettingsSectionBtn');
    const mediaSettingsSectionBody = document.getElementById('mediaSettingsSectionBody');
    const toggleMainCharacterSectionBtn = document.getElementById('toggleMainCharacterSectionBtn');
    const mainCharacterSectionBody = document.getElementById('mainCharacterSectionBody');
    const toggleWorldProfileSectionBtn = document.getElementById('toggleWorldProfileSectionBtn');
    const worldProfileSectionBody = document.getElementById('worldProfileSectionBody');
    const toggleSideCharactersSectionBtn = document.getElementById('toggleSideCharactersSectionBtn');
    const sideCharactersSectionBody = document.getElementById('sideCharactersSectionBody');
    const mainCharacterNameInput = document.getElementById('mainCharacterNameInput');
    const mainCharacterProfileInput = document.getElementById('mainCharacterProfileInput');
    const mainCharacterProfileVieBtn = document.getElementById('mainCharacterProfileVieBtn');
    const charactersJsonInput = document.getElementById('charactersJsonInput');
    const formatCharactersJsonBtn = document.getElementById('formatCharactersJsonBtn');
    const charactersJsonHint = document.getElementById('charactersJsonHint');
    const sideCharactersPreview = document.getElementById('sideCharactersPreview');
    const saveCharacterInfoBtn = document.getElementById('saveCharacterInfoBtn');
    const worldEraInput = document.getElementById('worldEraInput');
    const worldGenreInput = document.getElementById('worldGenreInput');
    const worldContextInput = document.getElementById('worldContextInput');
    const worldForbiddenInput = document.getElementById('worldForbiddenInput');
    const mediaCenterImageAspectRatio = document.getElementById('mediaCenterImageAspectRatio');
    const mediaCenterImageStyle = document.getElementById('mediaCenterImageStyle');
    const mediaCenterImageStylePreviewImg = document.getElementById('mediaCenterImageStylePreviewImg');
    const mediaCenterImageStylePreviewEmpty = document.getElementById('mediaCenterImageStylePreviewEmpty');
    const mediaCenterImageStylePreviewMeta = document.getElementById('mediaCenterImageStylePreviewMeta');
    const saveWorldProfileBtn = document.getElementById('saveWorldProfileBtn');
    const mediaCenterTtsProvider = document.getElementById('mediaCenterTtsProvider');
    const mediaCenterTtsVoiceGender = document.getElementById('mediaCenterTtsVoiceGender');
    const mediaCenterTtsVoiceName = document.getElementById('mediaCenterTtsVoiceName');
    const mediaCenterTtsSpeed = document.getElementById('mediaCenterTtsSpeed');
    const mediaCenterImageProvider = document.getElementById('mediaCenterImageProvider');
    const mediaCenterAnimationProvider = document.getElementById('mediaCenterAnimationProvider');
    const useCharacterReferenceToggle = document.getElementById('useCharacterReferenceToggle');
    const saveMediaSettingsBtn = document.getElementById('saveMediaSettingsBtn');
    const mediaSettingsSaveStatus = document.getElementById('mediaSettingsSaveStatus');
    const queueWorkerBadge = document.getElementById('queueWorkerBadge');
    const genRefFaceBtn           = document.getElementById('genRefFaceBtn');
    const genRefMainCostumeBtn    = document.getElementById('genRefMainCostumeBtn');
    const genRefAltCostumeBtn     = document.getElementById('genRefAltCostumeBtn');
    const altCostumeInputArea     = document.getElementById('altCostumeInputArea');
    const altCostumeDescInput     = document.getElementById('altCostumeDescInput');
    const confirmGenAltCostumeBtn = document.getElementById('confirmGenAltCostumeBtn');
    // Keep alias so any legacy references still work
    const generateMainCharacterRefBtn = genRefFaceBtn;
    const uploadMainCharacterRefBtn = document.getElementById('uploadMainCharacterRefBtn');
    const mainCharacterRefUploadInput = document.getElementById('mainCharacterRefUploadInput');
    const mainCharacterRefStatus = document.getElementById('mainCharacterRefStatus');
    const mainCharacterRefsList = document.getElementById('mainCharacterRefsList');
    const mainCharacterRefsEmpty = document.getElementById('mainCharacterRefsEmpty');
    const mediaCenterPreviewVoiceBtn = document.getElementById('mediaCenterPreviewVoiceBtn');
    const mediaCenterSavedVoiceSamples = document.getElementById('mediaCenterSavedVoiceSamples');
    const mediaCenterReplaySavedVoiceBtn = document.getElementById('mediaCenterReplaySavedVoiceBtn');
    const sentenceImageLightbox = document.getElementById('sentenceImageLightbox');
    const sentenceImageLightboxImg = document.getElementById('sentenceImageLightboxImg');
    const sentenceImageLightboxClose = document.getElementById('sentenceImageLightboxClose');
    const sentenceAnimationLightbox = document.getElementById('sentenceAnimationLightbox');
    const sentenceAnimationLightboxVideo = document.getElementById('sentenceAnimationLightboxVideo');
    const sentenceAnimationLightboxClose = document.getElementById('sentenceAnimationLightboxClose');
    const sentenceAudioLightbox = document.getElementById('sentenceAudioLightbox');
    const sentenceAudioLightboxTitle = document.getElementById('sentenceAudioLightboxTitle');
    const sentenceAudioLightboxMeta = document.getElementById('sentenceAudioLightboxMeta');
    const sentenceAudioLightboxPlayer = document.getElementById('sentenceAudioLightboxPlayer');
    const sentenceAudioLightboxClose = document.getElementById('sentenceAudioLightboxClose');
    const missingAssetsModal = document.getElementById('missingAssetsModal');
    const missingAssetsModalClose = document.getElementById('missingAssetsModalClose');
    const missingAssetsModalSummary = document.getElementById('missingAssetsModalSummary');
    const missingAssetsModalList = document.getElementById('missingAssetsModalList');
    const vieTranslateModal = document.getElementById('vieTranslateModal');
    const vieTranslateModalTitle = document.getElementById('vieTranslateModalTitle');
    const vieTranslateModalStatus = document.getElementById('vieTranslateModalStatus');
    const vieTranslateModalContent = document.getElementById('vieTranslateModalContent');
    const vieTranslateModalEnglish = document.getElementById('vieTranslateModalEnglish');
    const vieTranslateModalInstruction = document.getElementById('vieTranslateModalInstruction');
    const vieTranslateRewriteBtn = document.getElementById('vieTranslateRewriteBtn');
    const vieTranslateModalClose = document.getElementById('vieTranslateModalClose');

    const ttsVoicesCache = {};
    let mediaCenterAudioPreviewPlayer = null;
    const initialTtsVoiceName = @json($firstSentence->tts_voice_name ?? '');
    const initialImageStylePreviews = @json($selectedStylePreviewsFormatted);
    const SAVED_VOICE_SAMPLES_KEY = 'mediaCenterSavedVoiceSamples';
    const vieFieldLabels = {
        image: 'Prompt tạo ảnh',
        video: 'Prompt tạo video',
        main_profile: 'Main character profile',
    };
    let activeVieContext = null;
    let imageStylePreviewMap = (initialImageStylePreviews && typeof initialImageStylePreviews === 'object')
        ? initialImageStylePreviews
        : {};
    let sentenceAudioPlaylist = [];
    let sentenceAudioIndex = 0;

    const setStatus = (msg, tone = 'normal') => {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className = 'mt-3 rounded-xl border px-3 py-2 text-xs ' +
            (tone === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : tone === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-700');
    };

    const initSectionToggle = (buttonEl, bodyEl) => {
        if (!buttonEl || !bodyEl) return;

        const applyState = (collapsed) => {
            bodyEl.classList.toggle('hidden', collapsed);
            buttonEl.textContent = collapsed ? 'Mở rộng' : 'Thu nhỏ';
            buttonEl.classList.toggle('bg-white', !collapsed);
            buttonEl.classList.toggle('text-slate-700', !collapsed);
            buttonEl.classList.toggle('bg-indigo-50', collapsed);
            buttonEl.classList.toggle('border-indigo-300', collapsed);
            buttonEl.classList.toggle('text-indigo-700', collapsed);
            buttonEl.dataset.collapsed = collapsed ? '1' : '0';
        };

        buttonEl.addEventListener('click', () => {
            const collapsed = buttonEl.dataset.collapsed === '1';
            applyState(!collapsed);
        });

        applyState(false);
    };

    const setQueueBadgeState = (state, detail) => {
        if (!queueWorkerBadge) return;

        queueWorkerBadge.classList.remove(
            'border-slate-300', 'bg-slate-100', 'text-slate-600',
            'border-emerald-300', 'bg-emerald-50', 'text-emerald-700',
            'border-rose-300', 'bg-rose-50', 'text-rose-700'
        );

        if (state === 'online') {
            queueWorkerBadge.classList.add('border-emerald-300', 'bg-emerald-50', 'text-emerald-700');
            queueWorkerBadge.textContent = 'Queue worker: online';
            queueWorkerBadge.title = detail || 'Queue worker dang xu ly binh thuong';
            return;
        }

        if (state === 'offline') {
            queueWorkerBadge.classList.add('border-rose-300', 'bg-rose-50', 'text-rose-700');
            queueWorkerBadge.textContent = 'Queue worker: offline';
            queueWorkerBadge.title = detail || 'Worker co dau hieu dung/treo';
            return;
        }

        queueWorkerBadge.classList.add('border-slate-300', 'bg-slate-100', 'text-slate-600');
        queueWorkerBadge.textContent = 'Queue worker: checking...';
        queueWorkerBadge.title = detail || 'Dang kiem tra queue worker';
    };

    const normalizeStyleValue = (value) => String(value || '').trim().toLowerCase();

    const getCurrentStylePreview = () => {
        if (!mediaCenterImageStyle) return null;

        const currentStyle = String(mediaCenterImageStyle.value || '').trim();
        if (!currentStyle) return null;

        const direct = imageStylePreviewMap?.[currentStyle];
        if (direct && typeof direct === 'object') {
            return direct;
        }

        const normalizedCurrent = normalizeStyleValue(currentStyle);
        for (const [key, item] of Object.entries(imageStylePreviewMap || {})) {
            if (normalizeStyleValue(key) === normalizedCurrent && item && typeof item === 'object') {
                return item;
            }
            const itemStyle = normalizeStyleValue(item?.style || '');
            if (itemStyle !== '' && itemStyle === normalizedCurrent) {
                return item;
            }
        }

        return null;
    };

    const renderSelectedStylePreview = () => {
        const preview = getCurrentStylePreview();
        const url = String(preview?.url || '').trim();

        if (!mediaCenterImageStylePreviewImg || !mediaCenterImageStylePreviewEmpty || !mediaCenterImageStylePreviewMeta) {
            return;
        }

        if (url !== '') {
            mediaCenterImageStylePreviewImg.src = url;
            mediaCenterImageStylePreviewImg.dataset.fullUrl = url;
            mediaCenterImageStylePreviewImg.classList.remove('hidden');
            mediaCenterImageStylePreviewEmpty.classList.add('hidden');

            const provider = String(preview?.provider || 'gemini-nano-banana-pro').trim();
            const ratio = String(preview?.aspect_ratio || '').trim();
            const sentenceIndex = Number(preview?.source_sentence_index || 1);
            mediaCenterImageStylePreviewMeta.textContent = `Nguon: canh ${sentenceIndex} | Provider: ${provider}${ratio ? ` | Ratio: ${ratio}` : ''}`;
            return;
        }

        mediaCenterImageStylePreviewImg.src = '';
        mediaCenterImageStylePreviewImg.dataset.fullUrl = '';
        mediaCenterImageStylePreviewImg.classList.add('hidden');
        mediaCenterImageStylePreviewEmpty.classList.remove('hidden');
        mediaCenterImageStylePreviewMeta.textContent = '';
    };

    const syncImageStyleOptionUi = () => {
        if (!mediaCenterImageStyle) return;
        renderSelectedStylePreview();
    };

    mediaCenterImageStyle?.addEventListener('change', syncImageStyleOptionUi);
    syncImageStyleOptionUi();

    const pollQueueHealth = async () => {
        setQueueBadgeState('checking');

        try {
            const health = await jsonFetch('/media-center/queue-health', 'GET');
            const pending = Number(health?.pending_total || 0);
            const stale = Number(health?.stale_pending || 0);
            const detail = `${health?.message || ''} Pending: ${pending}. Stale: ${stale}.`;

            if ((health?.status || '') === 'offline') {
                setQueueBadgeState('offline', detail);
                return;
            }

            setQueueBadgeState('online', detail);
        } catch (e) {
            setQueueBadgeState('offline', 'Khong the kiem tra queue health: ' + (e?.message || 'unknown error'));
        }
    };

    const setRowStatus = (row, msg, tone = 'normal') => {
        const el = row?.querySelector('.sentence-inline-status');
        if (!el) return;

        el.textContent = msg;
        el.classList.remove('hidden', 'text-slate-600', 'text-emerald-700', 'text-rose-700');

        if (tone === 'error') {
            el.classList.add('text-rose-700');
            return;
        }

        if (tone === 'success') {
            el.classList.add('text-emerald-700');
            return;
        }

        el.classList.add('text-slate-600');
    };

    const formatDurationLabel = (seconds) => {
        const safeSeconds = Number.isFinite(seconds) ? Math.max(0, Math.round(seconds)) : 0;
        const hours = Math.floor(safeSeconds / 3600);
        const minutes = Math.floor((safeSeconds % 3600) / 60);
        const secs = safeSeconds % 60;

        if (hours > 0) {
            return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }

        return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    };

    const getAllSentenceAudioUrls = () => {
        const urls = Array.from(document.querySelectorAll('#sentenceCardsContainer .sentence-card'))
            .map((row) => String(row.dataset.ttsAudioUrl || '').trim())
            .filter(Boolean);
        return Array.from(new Set(urls));
    };

    const measureAudioDuration = (url) => new Promise((resolve) => {
        const audio = new Audio();
        let settled = false;

        const done = (duration = 0) => {
            if (settled) return;
            settled = true;
            audio.removeAttribute('src');
            audio.load();
            resolve(Number.isFinite(duration) ? Math.max(0, duration) : 0);
        };

        const timer = setTimeout(() => {
            done(0);
        }, 12000);

        audio.preload = 'metadata';
        audio.onloadedmetadata = () => {
            clearTimeout(timer);
            done(audio.duration || 0);
        };
        audio.onerror = () => {
            clearTimeout(timer);
            done(0);
        };
        audio.src = url;
    });

    const refreshTotalSentenceAudioDuration = async () => {
        if (!totalSentenceAudioDurationInfo) return;

        const urls = getAllSentenceAudioUrls();
        if (!urls.length) {
            totalSentenceAudioDurationInfo.textContent = 'Tong thoi luong: chua co audio';
            return;
        }

        totalSentenceAudioDurationInfo.textContent = 'Tong thoi luong: dang tinh...';
        const durations = await Promise.all(urls.map((url) => measureAudioDuration(url)));
        const totalSeconds = durations.reduce((sum, sec) => sum + (Number.isFinite(sec) ? sec : 0), 0);
        totalSentenceAudioDurationInfo.textContent = `Tong thoi luong: ${formatDurationLabel(totalSeconds)} (${urls.length} file)`;
    };

    const syncSentenceAudioLightboxMeta = () => {
        if (!sentenceAudioLightboxTitle || !sentenceAudioLightboxMeta) return;

        const total = sentenceAudioPlaylist.length;
        if (!total) {
            sentenceAudioLightboxTitle.textContent = 'Nghe TTS';
            sentenceAudioLightboxMeta.textContent = '';
            return;
        }

        sentenceAudioLightboxTitle.textContent = total > 1 ? 'Nghe full audio' : 'Nghe TTS';
        sentenceAudioLightboxMeta.textContent = `Audio ${sentenceAudioIndex + 1}/${total}`;
    };

    const playSentenceAudioAtIndex = (index, autoPlay = true) => {
        if (!sentenceAudioLightboxPlayer || !sentenceAudioPlaylist.length) return;

        const safeIndex = Math.max(0, Math.min(sentenceAudioPlaylist.length - 1, index));
        const url = String(sentenceAudioPlaylist[safeIndex] || '').trim();
        if (!url) return;

        sentenceAudioIndex = safeIndex;
        sentenceAudioLightboxPlayer.src = url;
        syncSentenceAudioLightboxMeta();

        if (autoPlay) {
            sentenceAudioLightboxPlayer.play().catch(() => {});
        }
    };

    const setSentenceCardCollapsedState = (row, collapsed) => {
        if (!row) return;

        row.dataset.collapsed = collapsed ? '1' : '0';
        row.querySelectorAll('.sentence-card-expand-only').forEach((el) => {
            el.classList.toggle('hidden', collapsed);
        });

        const toggleBtn = row.querySelector('.sentence-collapse-toggle-btn');
        if (!toggleBtn) return;

        toggleBtn.textContent = collapsed ? 'Expand' : 'Collapse';
        toggleBtn.classList.toggle('bg-white', collapsed);
        toggleBtn.classList.toggle('text-slate-700', collapsed);
        toggleBtn.classList.toggle('bg-indigo-50', !collapsed);
        toggleBtn.classList.toggle('border-indigo-300', !collapsed);
        toggleBtn.classList.toggle('text-indigo-700', !collapsed);
    };

    const getAllSentenceRows = () => Array.from(document.querySelectorAll('#sentenceCardsContainer .sentence-card'));

    const updateAnimationProgressUI = (row, generation) => {
        const wrap = row?.querySelector('.animation-progress-wrap');
        const fill = row?.querySelector('.animation-progress-fill');
        const text = row?.querySelector('.animation-progress-text');
        if (!wrap || !fill || !text) return;

        const status = String(generation?.status || '').trim().toLowerCase();
        const progressRaw = Number(generation?.progress ?? 0);
        const progress = Number.isFinite(progressRaw) ? Math.max(0, Math.min(100, Math.round(progressRaw))) : 0;
        const message = String(generation?.message || '').trim();

        if (!status) {
            wrap.classList.add('hidden');
            return;
        }

        wrap.classList.remove('hidden');
        wrap.dataset.status = status;
        wrap.dataset.progress = String(progress);

        fill.style.width = `${progress}%`;
        fill.classList.remove('bg-indigo-500', 'bg-emerald-500', 'bg-rose-500');
        if (status === 'completed') {
            fill.classList.add('bg-emerald-500');
        } else if (status === 'failed') {
            fill.classList.add('bg-rose-500');
        } else {
            fill.classList.add('bg-indigo-500');
        }

        const statusLabel = status.toUpperCase();
        text.textContent = message ? `${statusLabel} - ${progress}% - ${message}` : `${statusLabel} - ${progress}%`;
    };

    const refreshAnimationThumbs = (row, items) => {
        const listEl = row?.querySelector('.sentence-animation-thumbs-list');
        const emptyEl = row?.querySelector('.sentence-animation-empty');
        if (!listEl) return;

        const safeItems = Array.isArray(items) ? items : [];
        if (!safeItems.length) {
            listEl.innerHTML = '';
            emptyEl?.classList.remove('hidden');
            return;
        }

        const html = safeItems.map((anim) => {
            const videoUrl = String(anim?.url || '').trim();
            const animationPath = String(anim?.path || '').trim();
            const providerRaw = String(anim?.provider || '').trim().toLowerCase();
            const providerLabel = providerRaw === 'seedance' ? 'S' : (providerRaw === 'kling' ? 'K' : 'L');
            const providerClass = providerRaw === 'seedance' ? 'bg-emerald-700/85' : (providerRaw === 'kling' ? 'bg-indigo-700/85' : 'bg-slate-700/85');
            const frameIndex = Number(anim?.frame_index || 0);
            const frameTotal = Number(anim?.frame_total || 1);
            const frameLabel = frameTotal > 1 ? `F${frameIndex + 1}/${frameTotal}` : 'F1';

            if (!videoUrl || !animationPath) {
                return '';
            }

            return `
                <div class="group relative h-20 w-20 shrink-0">
                    <button type="button" class="sentence-animation-thumb relative block h-20 w-20 overflow-hidden rounded-lg border border-indigo-200 bg-indigo-50 hover:border-indigo-400" data-video-url="${escapeHtml(videoUrl)}" title="Click de phong lon animation">
                        <video class="h-full w-full object-cover" preload="metadata" muted playsinline>
                            <source src="${escapeHtml(videoUrl)}" type="video/mp4">
                        </video>
                        <span class="absolute bottom-1 left-1 rounded bg-indigo-900/75 px-1 py-0.5 text-[9px] font-semibold text-white">${frameLabel}</span>
                        <span class="absolute right-1 top-1 rounded bg-slate-900/70 px-1 py-0.5 text-[9px] font-semibold text-white">MOV</span>
                        <span class="absolute left-1 top-1 rounded ${providerClass} px-1 py-0.5 text-[9px] font-bold text-white">${providerLabel}</span>
                    </button>
                    <button type="button" class="delete-sentence-animation-btn absolute -right-1 -top-1 hidden h-5 w-5 items-center justify-center rounded-full bg-rose-600 text-xs font-bold text-white shadow hover:bg-rose-700 group-hover:flex" data-animation-path="${escapeHtml(animationPath)}" title="Xoa clip nay">x</button>
                </div>
            `;
        }).join('');

        listEl.innerHTML = html;
        emptyEl?.classList.add('hidden');
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const renderMainCharacterReferenceList = (items) => {
        if (!mainCharacterRefsList) return;

        const safeItems = Array.isArray(items) ? items : [];
        if (!safeItems.length) {
            mainCharacterRefsList.innerHTML = '';
            mainCharacterRefsEmpty?.classList.remove('hidden');
            if (mainCharacterRefStatus) {
                mainCharacterRefStatus.textContent = 'Chưa có reference ảnh.';
                mainCharacterRefStatus.classList.remove('text-emerald-700', 'text-rose-700');
                mainCharacterRefStatus.classList.add('text-slate-500');
            }
            return;
        }

        const html = safeItems.map((ref) => {
            const refPath = String(ref?.path || '').trim();
            const refType = String(ref?.type || 'ref').trim() || 'ref';
            const refUrl = String(ref?.url || '').trim();
            if (!refPath || !refUrl) {
                return '';
            }

            return `
                <div class="group relative h-16 w-16">
                    <button type="button" class="main-character-ref-thumb relative block h-16 w-16 overflow-hidden rounded-lg border border-slate-200 bg-slate-100 hover:border-indigo-300" data-full-url="${escapeHtml(refUrl)}" title="Click de phong lon">
                        <img src="${escapeHtml(refUrl)}" alt="${escapeHtml(refType)}" class="h-full w-full object-cover">
                        <span class="absolute bottom-0 left-0 right-0 bg-slate-900/70 px-1 py-0.5 text-center text-[10px] font-semibold text-white">${escapeHtml(refType)}</span>
                    </button>
                    <button type="button" class="delete-main-character-ref-btn absolute -right-1 -top-1 inline-flex h-5 w-5 items-center justify-center rounded-full border border-white bg-rose-600 text-[11px] font-bold leading-none text-white shadow hover:bg-rose-700" data-reference-path="${escapeHtml(refPath)}" title="Xoa anh reference">x</button>
                </div>
            `;
        }).join('');

        mainCharacterRefsList.innerHTML = html;
        mainCharacterRefsEmpty?.classList.add('hidden');

        if (mainCharacterRefStatus) {
            mainCharacterRefStatus.textContent = `Đã có ${safeItems.length} reference ảnh.`;
            mainCharacterRefStatus.classList.remove('text-slate-500', 'text-rose-700');
            mainCharacterRefStatus.classList.add('text-emerald-700');
        }
    };

    const openSentenceImageLightbox = (url) => {
        if (!sentenceImageLightbox || !sentenceImageLightboxImg || !url) return;
        sentenceImageLightboxImg.src = url;
        sentenceImageLightbox.classList.remove('hidden');
        sentenceImageLightbox.classList.add('flex');
    };

    const closeSentenceImageLightbox = () => {
        if (!sentenceImageLightbox || !sentenceImageLightboxImg) return;
        sentenceImageLightbox.classList.add('hidden');
        sentenceImageLightbox.classList.remove('flex');
        sentenceImageLightboxImg.src = '';
    };

    const openSentenceAnimationLightbox = (url) => {
        if (!sentenceAnimationLightbox || !sentenceAnimationLightboxVideo || !url) return;
        sentenceAnimationLightboxVideo.src = url;
        sentenceAnimationLightbox.classList.remove('hidden');
        sentenceAnimationLightbox.classList.add('flex');
        sentenceAnimationLightboxVideo.play().catch(() => {});
    };

    const closeSentenceAnimationLightbox = () => {
        if (!sentenceAnimationLightbox || !sentenceAnimationLightboxVideo) return;
        sentenceAnimationLightbox.classList.add('hidden');
        sentenceAnimationLightbox.classList.remove('flex');
        sentenceAnimationLightboxVideo.pause();
        sentenceAnimationLightboxVideo.removeAttribute('src');
        sentenceAnimationLightboxVideo.load();
    };

    const openSentenceAudioLightbox = (urlsOrUrl, startIndex = 0) => {
        if (!sentenceAudioLightbox || !sentenceAudioLightboxPlayer) return;

        const rawList = Array.isArray(urlsOrUrl) ? urlsOrUrl : [urlsOrUrl];
        const playlist = rawList
            .map((url) => String(url || '').trim())
            .filter(Boolean);

        if (!playlist.length) return;

        sentenceAudioPlaylist = playlist;
        sentenceAudioIndex = Math.max(0, Math.min(playlist.length - 1, Number(startIndex) || 0));

        sentenceAudioLightbox.classList.remove('hidden');
        sentenceAudioLightbox.classList.add('flex');
        playSentenceAudioAtIndex(sentenceAudioIndex, true);
    };

    const closeSentenceAudioLightbox = () => {
        if (!sentenceAudioLightbox || !sentenceAudioLightboxPlayer) return;
        sentenceAudioLightbox.classList.add('hidden');
        sentenceAudioLightbox.classList.remove('flex');
        sentenceAudioLightboxPlayer.pause();
        sentenceAudioLightboxPlayer.removeAttribute('src');
        sentenceAudioLightboxPlayer.load();
        sentenceAudioPlaylist = [];
        sentenceAudioIndex = 0;
        syncSentenceAudioLightboxMeta();
    };

    const openMissingAssetsModal = (items, message) => {
        if (!missingAssetsModal || !missingAssetsModalSummary || !missingAssetsModalList) return;

        const rows = Array.isArray(items) ? items : [];
        const normalizedRows = rows
            .map((item) => {
                const index = Number(item?.sentence_index || 0);
                const missing = Array.isArray(item?.missing) ? item.missing : [];
                const safeMissing = missing
                    .map((value) => String(value || '').trim().toLowerCase())
                    .filter(Boolean);
                return { index, missing: safeMissing };
            })
            .filter((item) => item.index > 0 && item.missing.length > 0)
            .sort((a, b) => a.index - b.index);

        const prettyMap = {
            tts: 'TTS',
            image: 'Image',
            animation: 'Animation',
        };

        const liHtml = normalizedRows.map((item) => {
            const labels = item.missing.map((key) => prettyMap[key] || key).join(', ');
            return `<li class="rounded bg-white px-2 py-1"><span class="font-semibold">Cau #${item.index}</span>: thieu ${labels}</li>`;
        }).join('');

        missingAssetsModalSummary.textContent = message || 'Mot so cau chua du file nen he thong khong cho tai package.';
        missingAssetsModalList.innerHTML = liHtml || '<li class="rounded bg-white px-2 py-1">Khong co du lieu thieu file.</li>';

        missingAssetsModal.classList.remove('hidden');
        missingAssetsModal.classList.add('flex');
    };

    const closeMissingAssetsModal = () => {
        if (!missingAssetsModal || !missingAssetsModalSummary || !missingAssetsModalList) return;
        missingAssetsModal.classList.add('hidden');
        missingAssetsModal.classList.remove('flex');
        missingAssetsModalSummary.textContent = '';
        missingAssetsModalList.innerHTML = '';
    };

    sentenceAudioLightboxPlayer?.addEventListener('ended', () => {
        const nextIndex = sentenceAudioIndex + 1;
        if (nextIndex < sentenceAudioPlaylist.length) {
            playSentenceAudioAtIndex(nextIndex, true);
            return;
        }

        syncSentenceAudioLightboxMeta();
    });

    const openVieTranslateModal = (title, status, englishContent, vietnameseContent) => {
        if (!vieTranslateModal || !vieTranslateModalTitle || !vieTranslateModalStatus || !vieTranslateModalContent || !vieTranslateModalEnglish) return;

        vieTranslateModalTitle.textContent = title || 'Bản dịch tiếng Việt';
        vieTranslateModalStatus.textContent = status || '';
        vieTranslateModalEnglish.value = englishContent || '';
        vieTranslateModalContent.value = vietnameseContent || '';

        vieTranslateModal.classList.remove('hidden');
        vieTranslateModal.classList.add('flex');
    };

    const closeVieTranslateModal = () => {
        if (!vieTranslateModal || !vieTranslateModalContent || !vieTranslateModalStatus || !vieTranslateModalEnglish) return;
        vieTranslateModal.classList.add('hidden');
        vieTranslateModal.classList.remove('flex');
        vieTranslateModalStatus.textContent = 'Đang chờ nội dung...';
        vieTranslateModalEnglish.value = '';
        vieTranslateModalContent.value = '';
        if (vieTranslateModalInstruction) {
            vieTranslateModalInstruction.value = '';
        }
        activeVieContext = null;
    };

    document.addEventListener('click', (event) => {
        const deleteBtn = event.target.closest('.delete-sentence-image-btn');
        if (deleteBtn) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        const deleteMainRefBtn = event.target.closest('.delete-main-character-ref-btn');
        if (deleteMainRefBtn) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        const deleteAnimBtn = event.target.closest('.delete-sentence-animation-btn');
        if (deleteAnimBtn) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        const thumb = event.target.closest('.sentence-image-thumb');
        if (thumb) {
            const url = (thumb.dataset.fullUrl || '').trim();
            if (url) {
                openSentenceImageLightbox(url);
            }
        }

        const refThumb = event.target.closest('.main-character-ref-thumb');
        if (refThumb) {
            const url = (refThumb.dataset.fullUrl || '').trim();
            if (url) {
                openSentenceImageLightbox(url);
            }
        }

        const animThumb = event.target.closest('.sentence-animation-thumb');
        if (animThumb) {
            const url = (animThumb.dataset.videoUrl || '').trim();
            if (url) {
                openSentenceAnimationLightbox(url);
            }
        }

        const ttsAudioBtn = event.target.closest('.sentence-tts-audio-preview-btn');
        if (ttsAudioBtn) {
            const url = (ttsAudioBtn.dataset.audioUrl || '').trim();
            if (url) {
                openSentenceAudioLightbox(url);
            }
        }

        const stylePreviewThumb = event.target.closest('.image-style-preview-thumb');
        if (stylePreviewThumb) {
            const url = (stylePreviewThumb.dataset.fullUrl || '').trim();
            if (url) {
                openSentenceImageLightbox(url);
            }
        }
    });

    sentenceImageLightboxClose?.addEventListener('click', closeSentenceImageLightbox);
    sentenceImageLightbox?.addEventListener('click', (event) => {
        if (event.target === sentenceImageLightbox) {
            closeSentenceImageLightbox();
        }
    });
    sentenceAnimationLightboxClose?.addEventListener('click', closeSentenceAnimationLightbox);
    sentenceAnimationLightbox?.addEventListener('click', (event) => {
        if (event.target === sentenceAnimationLightbox) {
            closeSentenceAnimationLightbox();
        }
    });
    sentenceAudioLightboxClose?.addEventListener('click', closeSentenceAudioLightbox);
    sentenceAudioLightbox?.addEventListener('click', (event) => {
        if (event.target === sentenceAudioLightbox) {
            closeSentenceAudioLightbox();
        }
    });
    missingAssetsModalClose?.addEventListener('click', closeMissingAssetsModal);
    missingAssetsModal?.addEventListener('click', (event) => {
        if (event.target === missingAssetsModal) {
            closeMissingAssetsModal();
        }
    });
    refreshTotalSentenceAudioDuration();
    initSectionToggle(toggleMediaSettingsSectionBtn, mediaSettingsSectionBody);
    initSectionToggle(toggleMainCharacterSectionBtn, mainCharacterSectionBody);
    initSectionToggle(toggleWorldProfileSectionBtn, worldProfileSectionBody);
    initSectionToggle(toggleSideCharactersSectionBtn, sideCharactersSectionBody);

    // Wizard toggle
    const toggleWizardBtn = document.getElementById('toggleWizardBtn');
    const wizardBody      = document.getElementById('wizardBody');
    if (toggleWizardBtn && wizardBody) {
        initSectionToggle(toggleWizardBtn, wizardBody);
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSentenceImageLightbox();
            closeSentenceAnimationLightbox();
            closeSentenceAudioLightbox();
            closeMissingAssetsModal();
            closeVieTranslateModal();
        }
    });

    vieTranslateModalClose?.addEventListener('click', closeVieTranslateModal);
    vieTranslateModal?.addEventListener('click', (event) => {
        if (event.target === vieTranslateModal) {
            closeVieTranslateModal();
        }
    });

    vieTranslateRewriteBtn?.addEventListener('click', async () => {
        if (!activeVieContext) {
            if (vieTranslateModalStatus) {
                vieTranslateModalStatus.textContent = 'Chưa có ngữ cảnh để AI viết lại.';
            }
            return;
        }

        const instruction = (vieTranslateModalInstruction?.value || '').trim();
        if (!instruction) {
            if (vieTranslateModalStatus) {
                vieTranslateModalStatus.textContent = 'Vui lòng nhập yêu cầu chỉnh sửa trước khi AI viết lại.';
            }
            return;
        }

        const btn = vieTranslateRewriteBtn;
        const originalText = btn.textContent;
        btn.textContent = 'Đang viết lại...';
        btn.disabled = true;

        if (vieTranslateModalStatus) {
            if (activeVieContext.scope === 'main_character_profile') {
                vieTranslateModalStatus.textContent = 'AI đang viết lại mô tả nhân vật chính...';
            } else {
                vieTranslateModalStatus.textContent = `AI đang viết lại ${vieFieldLabels[activeVieContext.field] || activeVieContext.field} cho câu #${activeVieContext.sentenceIndex}...`;
            }
        }

        try {
            let data = {};
            if (activeVieContext.scope === 'main_character_profile') {
                data = await jsonFetch(`/media-center/projects/${projectId}/rewrite-main-character-profile-bilingual`, 'POST', {
                    source_text: activeVieContext.sourceText,
                    translated_text: activeVieContext.translatedText,
                    instruction,
                });
            } else {
                data = await jsonFetch(`/media-center/projects/${projectId}/sentences/${activeVieContext.sentenceId}/rewrite-bilingual`, 'POST', {
                    field: activeVieContext.field,
                    source_text: activeVieContext.sourceText,
                    translated_text: activeVieContext.translatedText,
                    instruction,
                });
            }

            const rewrittenEn = (data?.rewritten_en || '').trim();
            const rewrittenVi = (data?.rewritten_vi || '').trim();
            const relatedVideoEn = (data?.related_video_en || '').trim();
            const relatedVideoVi = (data?.related_video_vi || '').trim();

            if (activeVieContext.inputEl) {
                activeVieContext.inputEl.value = rewrittenEn;
            }

            if (activeVieContext.field === 'image' && relatedVideoEn) {
                const videoEl = activeVieContext.row?.querySelector('.sentence-video-prompt');
                if (videoEl) {
                    videoEl.value = relatedVideoEn;
                }
            }

            activeVieContext.sourceText = rewrittenEn;
            activeVieContext.translatedText = rewrittenVi;

            if (activeVieContext.scope === 'main_character_profile') {
                openVieTranslateModal(
                    'AI viết lại - Main character profile',
                    'Đã cập nhật mô tả nhân vật chính. Nếu bạn muốn lưu thêm các trường khác, bấm Save Character Info.',
                    rewrittenEn,
                    rewrittenVi
                );
                setStatus('AI đã viết lại mô tả nhân vật chính.', 'success');
                return;
            }

            const rewriteStatus = (activeVieContext.field === 'image' && relatedVideoEn)
                ? 'Đã cập nhật EN/VI và tạo luôn Prompt video liên quan. Bấm Save để lưu toàn bộ card nếu bạn còn chỉnh thêm field khác.'
                : 'Đã cập nhật EN/VI theo yêu cầu. Bấm Save để lưu toàn bộ card nếu bạn còn chỉnh thêm field khác.';

            openVieTranslateModal(
                `AI viết lại - Câu #${activeVieContext.sentenceIndex} (${vieFieldLabels[activeVieContext.field] || activeVieContext.field})`,
                rewriteStatus,
                rewrittenEn,
                rewrittenVi
            );
            setRowStatus(activeVieContext.row, `AI đã viết lại ${vieFieldLabels[activeVieContext.field] || activeVieContext.field} cho câu #${activeVieContext.sentenceIndex}.`, 'success');
        } catch (e) {
            if (vieTranslateModalStatus) {
                vieTranslateModalStatus.textContent = 'Lỗi AI viết lại: ' + e.message;
            }
            if (activeVieContext.scope === 'main_character_profile') {
                setStatus('Lỗi AI viết lại mô tả nhân vật chính: ' + e.message, 'error');
            } else {
                setRowStatus(activeVieContext.row, `Lỗi AI viết lại câu #${activeVieContext.sentenceIndex}: ${e.message}`, 'error');
            }
        } finally {
            btn.textContent = originalText || 'AI viết lại & cập nhật EN/VI';
            btn.disabled = false;
        }
    });

    const runMainCharacterProfileVieTranslate = async (btnOverride = null) => {
        const sourceText = (mainCharacterProfileInput?.value || '').trim();
        if (!sourceText) {
            setStatus('Mô tả nhân vật chính đang trống, không có gì để dịch.', 'error');
            return;
        }

        const btn = btnOverride || mainCharacterProfileVieBtn;
        if (!btn) {
            return;
        }

        if (btn.dataset.loading === '1') {
            return;
        }

        btn.dataset.loading = '1';
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '...';

        openVieTranslateModal('Bản dịch tiếng Việt - Main character profile', 'Đang dịch mô tả nhân vật chính...', '', '');

        try {
            const data = await jsonFetch(`/media-center/projects/${projectId}/translate-main-character-profile`, 'POST', {
                text: sourceText,
            });

            const translatedText = (data?.translated_text || '').trim();
            activeVieContext = {
                scope: 'main_character_profile',
                field: 'main_profile',
                inputEl: mainCharacterProfileInput,
                sourceText,
                translatedText,
            };

            openVieTranslateModal(
                'Bản dịch tiếng Việt - Main character profile',
                'Đã dịch xong mô tả nhân vật chính.',
                sourceText,
                translatedText || '(Không có nội dung dịch)'
            );
        } catch (e) {
            activeVieContext = {
                scope: 'main_character_profile',
                field: 'main_profile',
                inputEl: mainCharacterProfileInput,
                sourceText,
                translatedText: '',
            };

            openVieTranslateModal(
                'Bản dịch tiếng Việt - Main character profile',
                'Lỗi dịch: ' + e.message,
                sourceText,
                ''
            );
        } finally {
            btn.disabled = false;
            btn.textContent = originalText || 'VIE';
            btn.dataset.loading = '0';
        }
    };

    mainCharacterProfileVieBtn?.addEventListener('click', async () => {
        await runMainCharacterProfileVieTranslate(mainCharacterProfileVieBtn);
    });

    // Fallback binding in case this button is re-rendered or replaced in DOM.
    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('#mainCharacterProfileVieBtn');
        if (!btn) {
            return;
        }

        event.preventDefault();
        await runMainCharacterProfileVieTranslate(btn);
    });

    const setupAspectRatioChooser = (containerSelector, inputEl, activeClass) => {
        const container = document.querySelector(containerSelector);
        if (!container || !inputEl) return;

        const applySelection = (ratio) => {
            inputEl.value = ratio;
            container.querySelectorAll('button[data-ratio]').forEach((btn) => {
                if ((btn.dataset.ratio || '') === ratio) {
                    btn.classList.add(...activeClass);
                    btn.classList.remove('border-slate-300', 'bg-white', 'text-slate-700');
                } else {
                    btn.classList.remove(...activeClass);
                    btn.classList.add('border-slate-300', 'bg-white', 'text-slate-700');
                }
            });
        };

        const initial = (inputEl.value || '16:9').trim();
        applySelection(initial);

        container.querySelectorAll('button[data-ratio]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const ratio = (btn.dataset.ratio || '16:9').trim();
                applySelection(ratio);
            });
        });
    };

    const jsonFetch = async (url, method = 'POST', body = {}) => {
        const res = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json'
            },
            body: method === 'GET' ? undefined : JSON.stringify(body)
        });
        const text = await res.text();
        let data = {};
        try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { message: text || ('HTTP ' + res.status) }; }
        if (!res.ok || data.success === false) {
            throw new Error(data.message || data.error || (data.errors ? JSON.stringify(data.errors) : ('HTTP ' + res.status)));
        }
        return data;
    };

    const getTtsSelectionPayload = () => ({
        provider: (mediaCenterTtsProvider?.value || 'google').trim(),
        voice_gender: (mediaCenterTtsVoiceGender?.value || 'female').trim(),
        voice_name: (mediaCenterTtsVoiceName?.value || '').trim(),
        speed: parseFloat(mediaCenterTtsSpeed?.value || '1.0') || 1.0,
    });

    const getImageSelectionPayload = () => ({
        provider: (mediaCenterImageProvider?.value || 'gemini').trim(),
    });

    const getAnimationSelectionPayload = () => ({
        provider: (mediaCenterAnimationProvider?.value || 'kling').trim(),
    });

    const parseCharactersJsonInput = () => {
        const raw = (charactersJsonInput?.value || '').trim();
        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            throw new Error('characters_json phải là JSON array.');
        }

        return parsed;
    };

    const formatCharactersJsonValue = (value) => JSON.stringify(value, null, 2);

    const renderSideCharactersPreview = () => {
        if (!sideCharactersPreview) {
            return;
        }

        try {
            const characters = parseCharactersJsonInput();

            if (charactersJsonHint) {
                charactersJsonHint.textContent = 'JSON hợp lệ. Có thể bấm Save Character Info.';
                charactersJsonHint.classList.remove('text-rose-600');
                charactersJsonHint.classList.add('text-slate-500');
            }

            if (!characters.length) {
                sideCharactersPreview.innerHTML = '<p class="text-[11px] text-slate-500">Chưa có nhân vật phụ.</p>';
                return;
            }

            sideCharactersPreview.innerHTML = characters.map((item, idx) => {
                const row = (item && typeof item === 'object') ? item : {};
                const name = String(row.name || '').trim() || `Nhân vật #${idx + 1}`;
                const gender = String(row.gender || '').trim() || 'N/A';
                const race = String(row.race || '').trim() || 'N/A';
                const skin = String(row.skin_tone || '').trim() || 'N/A';
                return `
                    <div class="rounded-lg border border-slate-200 bg-white p-2">
                        <p class="text-[12px] font-bold text-slate-800">${escapeHtml(name)}</p>
                        <p class="mt-1 text-[11px] text-slate-600">Gender: ${escapeHtml(gender)}</p>
                        <p class="text-[11px] text-slate-600">Race: ${escapeHtml(race)}</p>
                        <p class="text-[11px] text-slate-600">Skin tone: ${escapeHtml(skin)}</p>
                    </div>
                `;
            }).join('');
        } catch (e) {
            if (charactersJsonHint) {
                charactersJsonHint.textContent = 'JSON chưa hợp lệ: ' + (e?.message || 'Lỗi parse');
                charactersJsonHint.classList.remove('text-slate-500');
                charactersJsonHint.classList.add('text-rose-600');
            }
            sideCharactersPreview.innerHTML = '<p class="text-[11px] text-rose-600">Không thể hiển thị preview vì JSON chưa hợp lệ.</p>';
        }
    };

    formatCharactersJsonBtn?.addEventListener('click', () => {
        try {
            const parsed = parseCharactersJsonInput();
            if (charactersJsonInput) {
                charactersJsonInput.value = formatCharactersJsonValue(parsed);
            }
            renderSideCharactersPreview();
            setStatus('Đã format JSON nhân vật phụ.', 'success');
        } catch (e) {
            renderSideCharactersPreview();
            setStatus('Không thể format JSON nhân vật phụ: ' + (e?.message || 'Lỗi parse'), 'error');
        }
    });

    charactersJsonInput?.addEventListener('input', () => {
        renderSideCharactersPreview();
    });

    // Normalize JSON layout on load for easier editing.
    try {
        const initialCharacters = parseCharactersJsonInput();
        if (charactersJsonInput) {
            charactersJsonInput.value = formatCharactersJsonValue(initialCharacters);
        }
    } catch (e) {
        // Keep original raw value when JSON is invalid.
    }
    renderSideCharactersPreview();

    const getSelectedSentenceIdsForBulk = () => {
        const checks = Array.from(document.querySelectorAll('#sentenceCardsContainer .sentence-bulk-select:checked'));
        return checks
            .map((el) => Number((el.value || '').trim()))
            .filter((id) => Number.isInteger(id) && id > 0);
    };

    const updateRegenerateWeakButtonState = () => {
        if (!regenerateWeakPromptsBtn) return;

        const selectedCount = getSelectedSentenceIdsForBulk().length;
        const isRunning = regenerateWeakPromptsBtn.dataset.running === '1';
        regenerateWeakPromptsBtn.disabled = isRunning || selectedCount < 1;

        if (isRunning) {
            regenerateWeakPromptsBtn.classList.add('opacity-70', 'cursor-not-allowed');
            return;
        }

        if (selectedCount > 0) {
            regenerateWeakPromptsBtn.classList.remove('opacity-70', 'cursor-not-allowed');
            if (!regenerateWeakPromptsBtn.dataset.doneLabel) {
                regenerateWeakPromptsBtn.classList.remove('bg-emerald-700', 'hover:bg-emerald-800');
                regenerateWeakPromptsBtn.classList.add('bg-violet-600', 'hover:bg-violet-700');
                regenerateWeakPromptsBtn.textContent = 'Regenerate weak prompts only';
            }
            return;
        }

        regenerateWeakPromptsBtn.classList.add('opacity-70', 'cursor-not-allowed');
    };

    saveMediaSettingsBtn?.addEventListener('click', async () => {
        const btn = saveMediaSettingsBtn;
        const originalText = btn.textContent;

        btn.disabled = true;
        btn.textContent = 'Đang lưu...';
        btn.classList.remove('bg-slate-800', 'hover:bg-slate-900', 'bg-emerald-600', 'hover:bg-emerald-700', 'bg-rose-600', 'hover:bg-rose-700');
        btn.classList.add('bg-indigo-600', 'hover:bg-indigo-700');

        if (mediaSettingsSaveStatus) {
            mediaSettingsSaveStatus.textContent = 'Đang lưu Media Settings...';
            mediaSettingsSaveStatus.classList.remove('text-emerald-700', 'text-rose-700');
            mediaSettingsSaveStatus.classList.add('text-slate-500');
        }

        setStatus('Đang lưu Media Settings...');
        try {
            const ttsPayload = getTtsSelectionPayload();
            const imagePayload = getImageSelectionPayload();
            const animationPayload = getAnimationSelectionPayload();
            const imageAspectRatio = (mediaCenterImageAspectRatio?.value || '16:9').trim();
            const imageStyle = (mediaCenterImageStyle?.value || 'Cinematic').trim();

            const data = await jsonFetch(`/media-center/projects/${projectId}/media-settings`, 'PUT', {
                tts_provider: ttsPayload.provider,
                voice_gender: ttsPayload.voice_gender,
                voice_name: ttsPayload.voice_name,
                speed: ttsPayload.speed,
                image_provider: imagePayload.provider,
                animation_provider: animationPayload.provider,
                image_aspect_ratio: imageAspectRatio,
                image_style: imageStyle,
                use_character_reference: !!useCharacterReferenceToggle?.checked,
            });

            setStatus(`Đã lưu Media Settings cho ${data.updated_sentences || 0} câu.`, 'success');

            btn.textContent = 'Da luu';
            btn.classList.remove('bg-indigo-600', 'hover:bg-indigo-700');
            btn.classList.add('bg-emerald-600', 'hover:bg-emerald-700');

            if (mediaSettingsSaveStatus) {
                mediaSettingsSaveStatus.textContent = `Đã lưu thành công lúc ${new Date().toLocaleTimeString()}.`;
                mediaSettingsSaveStatus.classList.remove('text-slate-500', 'text-rose-700');
                mediaSettingsSaveStatus.classList.add('text-emerald-700');
            }
        } catch (e) {
            setStatus('Lỗi lưu Media Settings: ' + e.message, 'error');

            btn.textContent = 'Lưu lỗi';
            btn.classList.remove('bg-indigo-600', 'hover:bg-indigo-700');
            btn.classList.add('bg-rose-600', 'hover:bg-rose-700');

            if (mediaSettingsSaveStatus) {
                mediaSettingsSaveStatus.textContent = 'Lưu thất bại: ' + e.message;
                mediaSettingsSaveStatus.classList.remove('text-slate-500', 'text-emerald-700');
                mediaSettingsSaveStatus.classList.add('text-rose-700');
            }
        } finally {
            setTimeout(() => {
                btn.disabled = false;
                btn.textContent = originalText || 'Save Media Settings';
                btn.classList.remove('bg-indigo-600', 'hover:bg-indigo-700', 'bg-emerald-600', 'hover:bg-emerald-700', 'bg-rose-600', 'hover:bg-rose-700');
                btn.classList.add('bg-slate-800', 'hover:bg-slate-900');
            }, 1400);
        }
    });

    // Shared helper for all 3 character ref generation buttons
    const doGenerateCharacterRef = async (shotType, altCostumeDesc = '') => {
        const labelMap = {
            face:         'ảnh mặt (close-up)',
            main_costume: 'mặt + trang phục chính',
            alt_costume:  'mặt + trang phục 2',
        };
        const label = labelMap[shotType] || shotType;
        setStatus(`Đang AI tạo reference ${label}...`);

        const btnMap = { face: genRefFaceBtn, main_costume: genRefMainCostumeBtn, alt_costume: genRefAltCostumeBtn };
        const btn = btnMap[shotType];
        const originalText = btn?.textContent;
        if (btn) { btn.disabled = true; btn.textContent = 'Đang tạo...'; }

        try {
            const imagePayload = getImageSelectionPayload();
            const payload = { provider: imagePayload.provider, shot_type: shotType };
            if (shotType === 'alt_costume' && altCostumeDesc) {
                payload.alt_costume_desc = altCostumeDesc;
            }
            const data = await jsonFetch(`/media-center/projects/${projectId}/generate-main-character-references`, 'POST', payload);

            const generated = Number(data?.generated || 0);
            if (mainCharacterRefStatus) {
                mainCharacterRefStatus.textContent = `Đã tạo ${generated} ảnh (${label}).`;
                mainCharacterRefStatus.classList.remove('text-rose-700');
                mainCharacterRefStatus.classList.add('text-emerald-700');
            }

            renderMainCharacterReferenceList(data?.all_references || data?.references || []);
            setStatus(`AI tạo reference xong: ${label} (${generated} ảnh).`, 'success');

            // Hide alt costume input after success
            if (shotType === 'alt_costume' && altCostumeInputArea) {
                altCostumeInputArea.classList.add('hidden');
            }
        } catch (e) {
            if (mainCharacterRefStatus) {
                mainCharacterRefStatus.textContent = 'Tạo reference thất bại: ' + e.message;
                mainCharacterRefStatus.classList.remove('text-emerald-700');
                mainCharacterRefStatus.classList.add('text-rose-700');
            }
            setStatus(`Lỗi tạo reference ${label}: ` + e.message, 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = originalText; }
        }
    };

    genRefFaceBtn?.addEventListener('click', () => doGenerateCharacterRef('face'));

    genRefMainCostumeBtn?.addEventListener('click', () => doGenerateCharacterRef('main_costume'));

    genRefAltCostumeBtn?.addEventListener('click', () => {
        if (!altCostumeInputArea) return;
        altCostumeInputArea.classList.toggle('hidden');
        if (!altCostumeInputArea.classList.contains('hidden')) {
            altCostumeDescInput?.focus();
        }
    });

    confirmGenAltCostumeBtn?.addEventListener('click', () => {
        const desc = altCostumeDescInput?.value?.trim() || '';
        if (!desc) {
            altCostumeDescInput?.focus();
            return;
        }
        doGenerateCharacterRef('alt_costume', desc);
    });

    altCostumeDescInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmGenAltCostumeBtn?.click();
        }
    });

    // Legacy: keep old handler name pointing to face button
    generateMainCharacterRefBtn?.addEventListener('click_legacy', async () => {
        // intentionally empty — legacy alias, actual handler is above via genRefFaceBtn
    });

    uploadMainCharacterRefBtn?.addEventListener('click', async () => {
        const files = Array.from(mainCharacterRefUploadInput?.files || []);
        if (!files.length) {
            if (mainCharacterRefStatus) {
                mainCharacterRefStatus.textContent = 'Vui lòng chọn ảnh trước khi upload.';
                mainCharacterRefStatus.classList.remove('text-emerald-700');
                mainCharacterRefStatus.classList.add('text-rose-700');
            }
            return;
        }

        const btn = uploadMainCharacterRefBtn;
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Đang upload...';

        setStatus(`Đang upload ${files.length} ảnh reference...`);

        try {
            const formData = new FormData();
            files.forEach((file) => {
                formData.append('references[]', file);
            });

            const response = await fetch(`/media-center/projects/${projectId}/upload-main-character-references`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: formData,
            });

            const text = await response.text();
            let data = {};
            try {
                data = text ? JSON.parse(text) : {};
            } catch (_) {
                data = { message: text || ('HTTP ' + response.status) };
            }

            if (!response.ok || data.success === false) {
                throw new Error(data.message || data.error || ('HTTP ' + response.status));
            }

            const uploaded = Number(data?.uploaded || files.length);
            if (mainCharacterRefStatus) {
                mainCharacterRefStatus.textContent = `Đã upload ${uploaded} ảnh reference.`;
                mainCharacterRefStatus.classList.remove('text-rose-700');
                mainCharacterRefStatus.classList.add('text-emerald-700');
            }

            renderMainCharacterReferenceList(data?.all_references || data?.references || []);

            setStatus(`Upload reference thành công (${uploaded} ảnh).`, 'success');
            if (mainCharacterRefUploadInput) {
                mainCharacterRefUploadInput.value = '';
            }
        } catch (e) {
            if (mainCharacterRefStatus) {
                mainCharacterRefStatus.textContent = 'Upload reference thất bại: ' + (e?.message || 'Unknown error');
                mainCharacterRefStatus.classList.remove('text-emerald-700');
                mainCharacterRefStatus.classList.add('text-rose-700');
            }
            setStatus('Lỗi upload reference ảnh nhân vật chính: ' + (e?.message || 'Unknown error'), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = originalText || 'Upload ảnh ref';
        }
    });

    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('.delete-main-character-ref-btn');
        if (!btn) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const referencePath = (btn.dataset.referencePath || '').trim();
        if (!referencePath) {
            return;
        }

        const ok = window.confirm('Xóa ảnh reference này?');
        if (!ok) {
            return;
        }

        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '...';

        try {
            const data = await jsonFetch(`/media-center/projects/${projectId}/main-character-references`, 'DELETE', {
                reference_path: referencePath,
            });

            renderMainCharacterReferenceList(data?.all_references || []);
            setStatus('Đã xóa ảnh reference nhân vật chính.', 'success');
        } catch (e) {
            if (mainCharacterRefStatus) {
                mainCharacterRefStatus.textContent = 'Xóa reference thất bại: ' + (e?.message || 'Unknown error');
                mainCharacterRefStatus.classList.remove('text-emerald-700', 'text-slate-500');
                mainCharacterRefStatus.classList.add('text-rose-700');
            }
            setStatus('Lỗi xóa reference ảnh nhân vật chính: ' + (e?.message || 'Unknown error'), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = originalText || 'x';
        }
    });

    const loadSavedVoiceSamples = () => {
        try {
            const raw = localStorage.getItem(SAVED_VOICE_SAMPLES_KEY);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    };

    const saveSavedVoiceSamples = (samples) => {
        localStorage.setItem(SAVED_VOICE_SAMPLES_KEY, JSON.stringify(samples));
    };

    const renderSavedVoiceSamples = () => {
        if (!mediaCenterSavedVoiceSamples) return;

        const samples = loadSavedVoiceSamples();
        mediaCenterSavedVoiceSamples.innerHTML = '';

        if (!samples.length) {
            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '-- Chưa có giọng mẫu đã lưu --';
            mediaCenterSavedVoiceSamples.appendChild(emptyOpt);
            return;
        }

        const firstOpt = document.createElement('option');
        firstOpt.value = '';
        firstOpt.textContent = '-- Chọn giọng mẫu đã lưu --';
        mediaCenterSavedVoiceSamples.appendChild(firstOpt);

        samples.forEach((sample) => {
            const option = document.createElement('option');
            option.value = sample.key;
            option.textContent = `${sample.provider} | ${sample.voice_gender} | ${sample.voice_name}`;
            mediaCenterSavedVoiceSamples.appendChild(option);
        });
    };

    const upsertSavedVoiceSample = (sample) => {
        const samples = loadSavedVoiceSamples();
        const existingIndex = samples.findIndex((item) => item.key === sample.key);
        if (existingIndex >= 0) {
            samples[existingIndex] = { ...samples[existingIndex], ...sample };
        } else {
            samples.unshift(sample);
        }

        // Keep recent list compact.
        const trimmed = samples.slice(0, 30);
        saveSavedVoiceSamples(trimmed);
        renderSavedVoiceSamples();
    };

    const playAudioUrl = (audioUrl) => {
        if (!audioUrl) return;
        if (mediaCenterAudioPreviewPlayer) {
            mediaCenterAudioPreviewPlayer.pause();
            mediaCenterAudioPreviewPlayer = null;
        }

        mediaCenterAudioPreviewPlayer = new Audio(audioUrl);
        mediaCenterAudioPreviewPlayer.play().catch(() => {});
        mediaCenterAudioPreviewPlayer.addEventListener('ended', () => {
            mediaCenterAudioPreviewPlayer = null;
        });
    };

    const renderVoiceOptions = (voiceMap, selectedValue = '') => {
        if (!mediaCenterTtsVoiceName) return;

        mediaCenterTtsVoiceName.innerHTML = '';

        const autoOpt = document.createElement('option');
        autoOpt.value = '';
        autoOpt.textContent = '-- Auto voice --';
        mediaCenterTtsVoiceName.appendChild(autoOpt);

        Object.entries(voiceMap || {}).forEach(([voiceCode, voiceLabel]) => {
            const option = document.createElement('option');
            option.value = voiceCode;
            option.textContent = String(voiceLabel || voiceCode);
            if (selectedValue && voiceCode === selectedValue) {
                option.selected = true;
            }
            mediaCenterTtsVoiceName.appendChild(option);
        });
    };

    const loadVoicesForCurrentTtsSelection = async () => {
        const provider = (mediaCenterTtsProvider?.value || 'google').trim();
        const gender = (mediaCenterTtsVoiceGender?.value || 'female').trim();
        const cacheKey = `${provider}:${gender}`;
        const currentSelected = (mediaCenterTtsVoiceName?.value || '').trim() || initialTtsVoiceName;

        if (ttsVoicesCache[cacheKey]) {
            renderVoiceOptions(ttsVoicesCache[cacheKey], currentSelected);
            return;
        }

        try {
            const res = await fetch(`/get-available-voices?provider=${encodeURIComponent(provider)}&gender=${encodeURIComponent(gender)}`);
            const data = await res.json();
            let voices = {};
            if (data && data.success && data.voices && typeof data.voices === 'object') {
                if (data.voices[gender] && typeof data.voices[gender] === 'object') {
                    voices = data.voices[gender];
                } else {
                    voices = data.voices;
                }
            }

            ttsVoicesCache[cacheKey] = voices;
            renderVoiceOptions(voices, currentSelected);
        } catch (e) {
            renderVoiceOptions({}, currentSelected);
        }
    };

    mediaCenterTtsProvider?.addEventListener('change', loadVoicesForCurrentTtsSelection);
    mediaCenterTtsVoiceGender?.addEventListener('change', loadVoicesForCurrentTtsSelection);
    loadVoicesForCurrentTtsSelection();
    renderSavedVoiceSamples();

    mediaCenterPreviewVoiceBtn?.addEventListener('click', async () => {
        const voiceName = (mediaCenterTtsVoiceName?.value || '').trim();
        const provider = (mediaCenterTtsProvider?.value || 'google').trim();
        const gender = (mediaCenterTtsVoiceGender?.value || 'female').trim();

        if (!voiceName) {
            setStatus('Vui lòng chọn voice name trước khi nghe thử.', 'error');
            return;
        }

        if (mediaCenterAudioPreviewPlayer) {
            mediaCenterAudioPreviewPlayer.pause();
            mediaCenterAudioPreviewPlayer = null;
        }

        const btn = mediaCenterPreviewVoiceBtn;
        const originalText = btn.textContent;
        btn.textContent = 'Đang tạo...';
        btn.disabled = true;

        try {
            const response = await fetch('/preview-voice', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    text: 'Xin chào, đây là giọng đọc mẫu cho media center.',
                    voice_gender: gender,
                    voice_name: voiceName,
                    provider,
                })
            });

            const text = await response.text();
            let data = {};
            try {
                data = text ? JSON.parse(text) : {};
            } catch (_) {
                data = { error: text || ('HTTP ' + response.status) };
            }

            if (!response.ok || !data.success || !data.audio_url) {
                throw new Error(data.error || data.message || ('HTTP ' + response.status));
            }

            const key = `${provider}|${gender}|${voiceName}`;
            upsertSavedVoiceSample({
                key,
                provider,
                voice_gender: gender,
                voice_name: voiceName,
                audio_url: data.audio_url,
                audio_path: data.audio_path || null,
                updated_at: new Date().toISOString(),
            });

            playAudioUrl(data.audio_url);

            setStatus('Đang phát audio nghe thử giọng.', 'success');
        } catch (e) {
            setStatus('Lỗi nghe thử giọng: ' + (e?.message || 'Không xác định'), 'error');
        } finally {
            btn.textContent = originalText || 'Nghe thử giọng';
            btn.disabled = false;
        }
    });

    mediaCenterReplaySavedVoiceBtn?.addEventListener('click', async () => {
        const sampleKey = (mediaCenterSavedVoiceSamples?.value || '').trim();
        if (!sampleKey) {
            setStatus('Vui lòng chọn một giọng mẫu đã lưu để nghe lại.', 'error');
            return;
        }

        const samples = loadSavedVoiceSamples();
        const sample = samples.find((item) => item.key === sampleKey);
        if (!sample) {
            setStatus('Không tìm thấy mẫu giọng đã lưu. Vui lòng tạo lại preview.', 'error');
            renderSavedVoiceSamples();
            return;
        }

        // Sync selectors with saved sample for transparency before replay.
        if (mediaCenterTtsProvider) mediaCenterTtsProvider.value = sample.provider || 'google';
        if (mediaCenterTtsVoiceGender) mediaCenterTtsVoiceGender.value = sample.voice_gender || 'female';
        await loadVoicesForCurrentTtsSelection();
        if (mediaCenterTtsVoiceName) mediaCenterTtsVoiceName.value = sample.voice_name || '';

        const btn = mediaCenterReplaySavedVoiceBtn;
        const originalText = btn.textContent;
        btn.textContent = 'Đang mở mẫu...';
        btn.disabled = true;

        try {
            // Try replay cached URL first.
            if (!sample.audio_url) {
                throw new Error('Mẫu không còn URL hợp lệ.');
            }

            const headResp = await fetch(sample.audio_url, {
                method: 'HEAD',
                cache: 'no-store'
            });
            if (!headResp.ok) {
                throw new Error('File mẫu đã mất trên server.');
            }

            playAudioUrl(sample.audio_url);
            setStatus('Đang phát giọng mẫu đã lưu.', 'success');
        } catch (_) {
            // If cached file/URL fails, regenerate via existing preview endpoint.
            try {
                const response = await fetch('/preview-voice', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        text: 'Xin chào, đây là giọng đọc mẫu cho media center.',
                        voice_gender: sample.voice_gender || 'female',
                        voice_name: sample.voice_name || '',
                        provider: sample.provider || 'google',
                    })
                });

                const text = await response.text();
                let data = {};
                try {
                    data = text ? JSON.parse(text) : {};
                } catch (_) {
                    data = { error: text || ('HTTP ' + response.status) };
                }

                if (!response.ok || !data.success || !data.audio_url) {
                    throw new Error(data.error || data.message || ('HTTP ' + response.status));
                }

                upsertSavedVoiceSample({
                    key: sample.key,
                    provider: sample.provider || 'google',
                    voice_gender: sample.voice_gender || 'female',
                    voice_name: sample.voice_name || '',
                    audio_url: data.audio_url,
                    audio_path: data.audio_path || null,
                    updated_at: new Date().toISOString(),
                });
                if (mediaCenterSavedVoiceSamples) {
                    mediaCenterSavedVoiceSamples.value = sample.key;
                }

                playAudioUrl(data.audio_url);
                setStatus('Mẫu cũ không còn file, đã tạo mới và phát lại.', 'success');
            } catch (e) {
                setStatus('Không thể nghe lại mẫu đã lưu: ' + (e?.message || 'Không xác định'), 'error');
            }
        } finally {
            btn.textContent = originalText || 'Nghe lại mẫu đã lưu';
            btn.disabled = false;
        }
    });

    let analyzePollTimer = null;
    const stopAnalyzePolling = () => {
        if (analyzePollTimer) {
            clearInterval(analyzePollTimer);
            analyzePollTimer = null;
        }
    };

    const pollAnalyzeProgress = async () => {
        try {
            const progressData = await jsonFetch(`/media-center/projects/${projectId}/analyze/progress`, 'GET');
            const analyze = progressData?.analyze || {};
            const status = analyze.status || 'idle';
            const progress = Number(analyze.progress || 0);
            const processed = Number(analyze.processed_sentences || 0);
            const total = Number(analyze.total_sentences || 0);
            const msg = analyze.message || 'Đang phân tích...';

            setStatus(`${msg} (${progress}% | ${processed}/${total} câu)`);

            if (status === 'completed') {
                stopAnalyzePolling();
                setStatus('Phân tích xong. Đang reload dữ liệu...', 'success');
                window.location.reload();
            }

            if (status === 'failed') {
                stopAnalyzePolling();
                const err = analyze.error ? (' | ' + analyze.error) : '';
                setStatus('Analyze thất bại.' + err, 'error');
            }
        } catch (err) {
            stopAnalyzePolling();
            setStatus('Không poll được tiến độ analyze: ' + (err?.message || 'Unknown error'), 'error');
        }
    };

    document.getElementById('analyzeProjectBtn')?.addEventListener('click', async () => {
        setStatus('Đang phân tích nhân vật + prompt từ văn bản... (queued)');
        stopAnalyzePolling();
        try {
            await jsonFetch(`/media-center/projects/${projectId}/analyze`);
            await pollAnalyzeProgress();
            analyzePollTimer = setInterval(pollAnalyzeProgress, 2000);
        } catch (e) {
            stopAnalyzePolling();
            setStatus('Lỗi analyze: ' + e.message, 'error');
        }
    });

    pollQueueHealth();
    setInterval(pollQueueHealth, 15000);

    regenerateWeakPromptsBtn?.addEventListener('click', async () => {
        const btn = regenerateWeakPromptsBtn;
        const selectedSentenceIds = getSelectedSentenceIdsForBulk();
        if (!selectedSentenceIds.length) {
            setStatus('Vui long chon it nhat 1 cau de regenerate prompt.', 'error');
            updateRegenerateWeakButtonState();
            return;
        }

        const originalLabel = btn.dataset.originalLabel || btn.textContent || 'Regenerate weak prompts only';
        btn.dataset.originalLabel = originalLabel;
        delete btn.dataset.doneLabel;
        btn.dataset.running = '1';
        btn.disabled = true;
        btn.classList.add('opacity-70', 'cursor-not-allowed');
        btn.textContent = `Dang regenerate (${selectedSentenceIds.length})...`;

        setStatus(`Dang AI rewrite prompt cho ${selectedSentenceIds.length} cau da chon...`);
        try {
            const data = await jsonFetch(`/media-center/projects/${projectId}/regenerate-weak-prompts`, 'POST', {
                sentence_ids: selectedSentenceIds,
            });
            const updatedRows = Array.isArray(data.updated_sentences) ? data.updated_sentences : [];

            updatedRows.forEach((item) => {
                const id = Number(item.id || 0);
                if (!id) return;

                const row = document.querySelector(`#sentenceCardsContainer .sentence-card[data-sentence-id="${id}"]`);
                if (!row) return;

                const ttsText = row.querySelector('.sentence-tts-text');
                const imagePrompt = row.querySelector('.sentence-image-prompt');
                const videoPrompt = row.querySelector('.sentence-video-prompt');

                if (ttsText) ttsText.value = item.tts_text || ttsText.value;
                if (imagePrompt) imagePrompt.value = item.image_prompt || imagePrompt.value;
                if (videoPrompt) videoPrompt.value = item.video_prompt || videoPrompt.value;
            });

            const updated = Number(data.updated || 0);
            const skipped = Number(data.skipped || 0);
            const selectedTotal = Number(data.selected_total || selectedSentenceIds.length);

            btn.dataset.running = '0';
            btn.dataset.doneLabel = '1';
            btn.classList.remove('bg-violet-600', 'hover:bg-violet-700', 'opacity-70', 'cursor-not-allowed');
            btn.classList.add('bg-emerald-700', 'hover:bg-emerald-800');
            btn.textContent = `Da xong ✓ (${updated}/${selectedTotal})`;

            setStatus(`AI rewrite xong: ${updated}/${selectedTotal} cau cap nhat, ${skipped} cau giu nguyen.`, 'success');
        } catch (e) {
            btn.dataset.running = '0';
            delete btn.dataset.doneLabel;
            btn.classList.remove('opacity-70', 'cursor-not-allowed', 'bg-emerald-700', 'hover:bg-emerald-800');
            btn.classList.add('bg-violet-600', 'hover:bg-violet-700');
            btn.textContent = originalLabel;
            setStatus('Loi regenerate weak prompts: ' + e.message, 'error');
        } finally {
            updateRegenerateWeakButtonState();
        }
    });

    const cleanupLegacyPromptsBtn = document.getElementById('cleanupLegacyPromptsBtn');
    cleanupLegacyPromptsBtn?.addEventListener('click', async () => {
        const btn = cleanupLegacyPromptsBtn;
        const originalLabel = btn.dataset.originalLabel || btn.textContent || 'Cleanup prompt cu (all sentences)';
        btn.dataset.originalLabel = originalLabel;

        btn.disabled = true;
        btn.classList.add('opacity-70', 'cursor-not-allowed');
        btn.textContent = 'Dang cleanup...';

        setStatus('Dang cleanup prompt cu va ap dung rule moi cho toan bo cau...');

        try {
            const data = await jsonFetch(`/media-center/projects/${projectId}/cleanup-prompts`);
            const updated = Number(data?.updated_sentences || 0);
            const total = Number(data?.total_sentences || 0);
            const summary = total > 0 ? `${updated}/${total}` : `${updated}`;

            btn.classList.remove('bg-purple-700', 'hover:bg-purple-800', 'opacity-70', 'cursor-not-allowed');
            btn.classList.add('bg-emerald-700', 'hover:bg-emerald-800');
            btn.textContent = `Da xong ✓ (${summary})`;
            btn.disabled = false;

            if (total > 0) {
                setStatus(`Cleanup xong: ${updated}/${total} cau da duoc xu ly theo rule moi.`, 'success');
            } else {
                setStatus(`Cleanup xong: ${updated} cau da duoc xu ly theo rule moi.`, 'success');
            }
        } catch (e) {
            btn.disabled = false;
            btn.classList.remove('opacity-70', 'cursor-not-allowed');
            btn.textContent = originalLabel;
            setStatus('Loi cleanup prompt cu: ' + e.message, 'error');
        }
    });

    document.getElementById('generateAllTtsBtn')?.addEventListener('click', async () => {
        const selectedSentenceIds = getSelectedSentenceIdsForBulk();
        const hasSelection = selectedSentenceIds.length > 0;
        setStatus(hasSelection
            ? `Đang generate TTS cho ${selectedSentenceIds.length} câu đã chọn...`
            : 'Đang generate TTS cho tất cả câu...');
        try {
            const ttsPayload = getTtsSelectionPayload();
            const data = await jsonFetch(`/media-center/projects/${projectId}/generate-all`, 'POST', {
                run_tts: true,
                run_images: false,
                tts_provider: ttsPayload.provider,
                voice_gender: ttsPayload.voice_gender,
                voice_name: ttsPayload.voice_name,
                speed: ttsPayload.speed,
                sentence_ids: hasSelection ? selectedSentenceIds : undefined,
            });
            const scopeText = hasSelection ? `${selectedSentenceIds.length} câu đã chọn` : 'tất cả câu';
            setStatus(`Generate TTS xong (${scopeText}). Updated ${data.updated} câu.`, 'success');
            window.location.reload();
        } catch (e) {
            setStatus('Lỗi generate all TTS: ' + e.message, 'error');
        }
    });

    document.getElementById('generateAllImagesBtn')?.addEventListener('click', async () => {
        const selectedSentenceIds = getSelectedSentenceIdsForBulk();
        const hasSelection = selectedSentenceIds.length > 0;
        setStatus(hasSelection
            ? `Đang đưa ${selectedSentenceIds.length} câu đã chọn vào queue generate ảnh...`
            : 'Đang đưa toàn bộ câu vào queue generate ảnh...');
        try {
            const imagePayload = getImageSelectionPayload();
            const data = await jsonFetch(`/media-center/projects/${projectId}/generate-all`, 'POST', {
                run_tts: false,
                run_images: true,
                image_provider: imagePayload.provider,
                sentence_ids: hasSelection ? selectedSentenceIds : undefined,
            });
            const scopeText = hasSelection ? `${selectedSentenceIds.length} câu đã chọn` : 'tất cả câu';
            setStatus(data?.message || `Đã queue ${data.updated || 0} câu tạo ảnh (${scopeText}). Bạn có thể chuyển sang màn hình khác.`, 'success');
        } catch (e) {
            setStatus('Lỗi generate all images: ' + e.message, 'error');
        }
    });

    downloadAllAssetsBtn?.addEventListener('click', async () => {
        const originalLabel = (downloadAllAssetsBtn.textContent || 'Download All Assets').trim() || 'Download All Assets';
        downloadAllAssetsBtn.disabled = true;
        downloadAllAssetsBtn.classList.add('opacity-70', 'cursor-not-allowed');
        downloadAllAssetsBtn.textContent = 'Dang dong goi...';
        setStatus('Dang kiem tra du TTS/Image/Animation tung cau truoc khi tai...');

        try {
            const response = await fetch(`/media-center/projects/${projectId}/download-all-assets`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/octet-stream, application/json',
                },
                credentials: 'same-origin',
            });

            const contentType = String(response.headers.get('content-type') || '').toLowerCase();
            if (!response.ok || contentType.includes('application/json')) {
                let errorMessage = 'Khong the tai goi assets.';
                try {
                    const payload = await response.json();
                    errorMessage = String(payload?.message || errorMessage);

                    if (Array.isArray(payload?.missing_sentences) && payload.missing_sentences.length) {
                        openMissingAssetsModal(payload.missing_sentences, errorMessage);
                    }
                } catch (e) {
                    // Keep default message when response is not JSON parseable.
                }

                throw new Error(errorMessage);
            }

            const blob = await response.blob();
            const disposition = String(response.headers.get('content-disposition') || '');
            let fileName = `media-center-project-${projectId}-assets.zip`;
            const match = disposition.match(/filename\*=UTF-8''([^;]+)|filename="?([^\";]+)"?/i);
            const rawName = match?.[1] || match?.[2] || '';
            if (rawName) {
                fileName = decodeURIComponent(rawName).replace(/[\\/:*?"<>|]+/g, '_');
            }

            const blobUrl = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(blobUrl);

            setStatus('Da tai goi assets thanh cong.', 'success');
        } catch (e) {
            setStatus('Khong the tai assets: ' + (e?.message || 'Unknown error'), 'error');
        } finally {
            downloadAllAssetsBtn.disabled = false;
            downloadAllAssetsBtn.classList.remove('opacity-70', 'cursor-not-allowed');
            downloadAllAssetsBtn.textContent = originalLabel;
        }
    });

    saveCharacterInfoBtn?.addEventListener('click', async () => {
        setStatus('Đang lưu thông tin nhân vật...');
        try {
            const parsedCharacters = parseCharactersJsonInput();

            const payload = await jsonFetch(`/media-center/projects/${projectId}/characters`, 'PUT', {
                main_character_name: mainCharacterNameInput?.value || '',
                main_character_profile: mainCharacterProfileInput?.value || '',
                characters_json: parsedCharacters,
            });

            const latestCharacters = Array.isArray(payload?.project?.characters_json)
                ? payload.project.characters_json
                : parsedCharacters;
            if (charactersJsonInput) {
                charactersJsonInput.value = formatCharactersJsonValue(latestCharacters);
            }
            renderSideCharactersPreview();

            const updatedSentences = Number(payload?.updated_sentences || 0);
            setStatus(`Đã lưu thông tin nhân vật và áp dụng lại cho ${updatedSentences} câu.`, 'success');
            window.location.reload();
        } catch (e) {
            setStatus('Lỗi lưu character info: ' + (e?.message || 'Không xác định'), 'error');
        }
    });

    saveWorldProfileBtn?.addEventListener('click', async () => {
        setStatus('Đang lưu hồ sơ bối cảnh...');
        try {
            await jsonFetch(`/media-center/projects/${projectId}/world-profile`, 'PUT', {
                story_era: worldEraInput?.value || '',
                story_genre: worldGenreInput?.value || '',
                world_context: worldContextInput?.value || '',
                forbidden_elements: worldForbiddenInput?.value || '',
            });

            setStatus('Đã lưu hồ sơ bối cảnh cho record hiện tại.', 'success');
            window.location.reload();
        } catch (e) {
            setStatus('Lỗi lưu hồ sơ bối cảnh: ' + (e?.message || 'Không xác định'), 'error');
        }
    });

    collapseAllSentencesBtn?.addEventListener('click', () => {
        getAllSentenceRows().forEach((row) => setSentenceCardCollapsedState(row, true));
    });

    expandAllSentencesBtn?.addEventListener('click', () => {
        getAllSentenceRows().forEach((row) => setSentenceCardCollapsedState(row, false));
    });

    toggleSelectAllSentencesBtn?.addEventListener('click', () => {
        const checks = Array.from(document.querySelectorAll('#sentenceCardsContainer .sentence-bulk-select'));
        if (!checks.length) return;

        const hasUnchecked = checks.some((check) => !check.checked);
        checks.forEach((check) => {
            check.checked = hasUnchecked;
        });

        updateRegenerateWeakButtonState();
    });

    document.querySelectorAll('#sentenceCardsContainer .sentence-bulk-select').forEach((check) => {
        check.addEventListener('change', () => {
            updateRegenerateWeakButtonState();
        });
    });

    updateRegenerateWeakButtonState();

    playAllSentenceAudioBtn?.addEventListener('click', () => {
        const urls = getAllSentenceAudioUrls();
        if (!urls.length) {
            setStatus('Chua co audio de nghe full.', 'error');
            return;
        }

        openSentenceAudioLightbox(urls, 0);
    });

    getAllSentenceRows().forEach((row) => {
        const sentenceId = row.dataset.sentenceId;
        const sentenceIndex = Number(row.dataset.sentenceIndex || sentenceId || 0) || sentenceId;
        const ttsText = row.querySelector('.sentence-tts-text');
        const imagePrompt = row.querySelector('.sentence-image-prompt');
        const videoPrompt = row.querySelector('.sentence-video-prompt');
        const animationMode = row.querySelector('.sentence-animation-mode');
        const animationCameraAngle = row.querySelector('.sentence-animation-camera-angle');
        const cameraChoreoPreset = row.querySelector('.sentence-camera-choreo-preset');
        const animationInstruction = row.querySelector('.sentence-animation-instruction');
        const animationImageChecks = Array.from(row.querySelectorAll('.sentence-animation-image-select'));
        const enableImageReferenceCheck = row.querySelector('.sentence-enable-image-reference');
        const imageReferenceSentenceSelect = row.querySelector('.sentence-image-reference-sentence-select');
        const quickGenPromptBtn = row.querySelector('.quick-gen-prompt-btn');
        const quickGenImageBtn = row.querySelector('.quick-gen-image-btn');
        const generateImageBtn = row.querySelector('.generate-image-btn');
        const storyTimeline = row.querySelector('.story-sequence-timeline');
        const storyTimelineHint = row.querySelector('.story-sequence-timeline-hint');
        const sentenceCollapseToggleBtn = row.querySelector('.sentence-collapse-toggle-btn');
        const animationStudioModal = row.querySelector('.animation-studio-modal');
        const openAnimationStudioButtons = Array.from(row.querySelectorAll('.open-animation-studio-btn'));
        const closeAnimationStudioButtons = Array.from(row.querySelectorAll('.close-animation-studio-btn'));

        let frameOrder = animationImageChecks
            .filter((el) => el.checked)
            .map((el) => (el.dataset.imagePath || '').trim())
            .filter(Boolean);
        let animationPollTimer = null;
        let imagePollTimer = null;

        const getThumbUrlByImagePath = (imagePath) => {
            if (!imagePath) return '';
            const thumbBtns = Array.from(row.querySelectorAll('.sentence-image-thumb'));
            const found = thumbBtns.find((btn) => (btn.dataset.imagePath || '').trim() === imagePath);
            return found ? ((found.dataset.fullUrl || '').trim()) : '';
        };

        const syncFrameOrderWithSelection = () => {
            const selectedPaths = animationImageChecks
                .filter((el) => el.checked)
                .map((el) => (el.dataset.imagePath || '').trim())
                .filter(Boolean);

            frameOrder = frameOrder.filter((path) => selectedPaths.includes(path));
            selectedPaths.forEach((path) => {
                if (!frameOrder.includes(path)) {
                    frameOrder.push(path);
                }
            });
        };

        const getSelectedAnimationPaths = () => {
            syncFrameOrderWithSelection();

            if ((animationMode?.value || 'image-to-motion') === 'image-to-story-sequence') {
                return [...frameOrder];
            }

            return frameOrder.length ? [frameOrder[0]] : [];
        };

        const getSelectedImageReferenceSentenceIds = () => {
            if (!enableImageReferenceCheck?.checked || !imageReferenceSentenceSelect) {
                return [];
            }

            const selectedId = Number((imageReferenceSentenceSelect.value || '').trim());
            if (Number.isInteger(selectedId) && selectedId > 0) {
                return [selectedId];
            }

            return [];
        };

        const syncImageReferenceControls = () => {
            if (!imageReferenceSentenceSelect) return;

            const enabled = !!enableImageReferenceCheck?.checked;
            imageReferenceSentenceSelect.disabled = !enabled;
            imageReferenceSentenceSelect.classList.toggle('opacity-60', !enabled);
            imageReferenceSentenceSelect.classList.toggle('cursor-not-allowed', !enabled);

            if (!enabled) {
                imageReferenceSentenceSelect.value = '';
            }
        };

        enableImageReferenceCheck?.addEventListener('change', syncImageReferenceControls);
        syncImageReferenceControls();

        const renderStoryTimeline = () => {
            if (!storyTimeline) return;
            syncFrameOrderWithSelection();

            storyTimeline.innerHTML = '';
            if (!frameOrder.length) {
                const empty = document.createElement('p');
                empty.className = 'text-[11px] text-slate-500';
                empty.textContent = 'Chua chon frame nao.';
                storyTimeline.appendChild(empty);

                if (storyTimelineHint) {
                    storyTimelineHint.textContent = 'Tick Use tren thumbnail de tao frame list.';
                }
                return;
            }

            frameOrder.forEach((path, index) => {
                const item = document.createElement('div');
                item.className = 'story-frame-item flex cursor-move items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-[11px]';
                item.draggable = true;
                item.dataset.index = String(index);

                const thumbUrl = getThumbUrlByImagePath(path);
                if (thumbUrl) {
                    const img = document.createElement('img');
                    img.src = thumbUrl;
                    img.className = 'h-7 w-7 rounded object-cover border border-indigo-200';
                    img.alt = 'frame';
                    item.appendChild(img);
                }

                const label = document.createElement('span');
                label.className = 'font-semibold text-indigo-700';
                label.textContent = `Frame ${index + 1}`;
                item.appendChild(label);

                item.addEventListener('dragstart', (event) => {
                    event.dataTransfer?.setData('text/plain', String(index));
                    event.dataTransfer.effectAllowed = 'move';
                    item.classList.add('opacity-60');
                });

                item.addEventListener('dragend', () => {
                    item.classList.remove('opacity-60');
                    row.querySelectorAll('.story-frame-item').forEach((el) => el.classList.remove('ring-2', 'ring-indigo-300'));
                });

                item.addEventListener('dragover', (event) => {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                    item.classList.add('ring-2', 'ring-indigo-300');
                });

                item.addEventListener('dragleave', () => {
                    item.classList.remove('ring-2', 'ring-indigo-300');
                });

                item.addEventListener('drop', (event) => {
                    event.preventDefault();
                    item.classList.remove('ring-2', 'ring-indigo-300');

                    const fromIndexRaw = event.dataTransfer?.getData('text/plain');
                    const fromIndex = Number(fromIndexRaw);
                    const toIndex = index;

                    if (!Number.isInteger(fromIndex) || fromIndex < 0 || fromIndex >= frameOrder.length || fromIndex === toIndex) {
                        return;
                    }

                    const next = [...frameOrder];
                    const [moved] = next.splice(fromIndex, 1);
                    next.splice(toIndex, 0, moved);
                    frameOrder = next;
                    renderStoryTimeline();
                });

                storyTimeline.appendChild(item);
            });

            if (storyTimelineHint) {
                const mode = (animationMode?.value || 'image-to-motion').trim();
                if (mode === 'image-to-story-sequence') {
                    storyTimelineHint.textContent = `Dang dung Story Sequence voi ${frameOrder.length} frame.`;
                } else if (frameOrder.length > 1) {
                    storyTimelineHint.textContent = 'Mode hien tai chi dung Frame 1. Chuyen sang Story Sequence de dung nhieu frame.';
                } else {
                    storyTimelineHint.textContent = 'Mode hien tai dung 1 frame.';
                }
            }
        };

        const openAnimationStudioModal = (preferredImagePath = '') => {
            if (!animationStudioModal) {
                return;
            }

            const preferredPath = (preferredImagePath || '').trim();
            if (preferredPath) {
                const matchedCheck = animationImageChecks.find((el) => ((el.dataset.imagePath || '').trim() === preferredPath));
                if (matchedCheck) {
                    matchedCheck.checked = true;
                }
            }

            renderStoryTimeline();
            animationStudioModal.classList.remove('hidden');
            animationStudioModal.classList.add('flex');
            document.body.classList.add('overflow-hidden');
        };

        const closeAnimationStudioModal = () => {
            if (!animationStudioModal) {
                return;
            }

            animationStudioModal.classList.add('hidden');
            animationStudioModal.classList.remove('flex');
            document.body.classList.remove('overflow-hidden');
        };

        openAnimationStudioButtons.forEach((btn) => {
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                openAnimationStudioModal((btn.dataset.imagePath || '').trim());
            });
        });

        closeAnimationStudioButtons.forEach((btn) => {
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                closeAnimationStudioModal();
            });
        });

        animationStudioModal?.addEventListener('click', (event) => {
            if (event.target === animationStudioModal) {
                closeAnimationStudioModal();
            }
        });

        const stopAnimationPolling = () => {
            if (animationPollTimer) {
                clearInterval(animationPollTimer);
                animationPollTimer = null;
            }
        };

        const stopImagePolling = () => {
            if (imagePollTimer) {
                clearInterval(imagePollTimer);
                imagePollTimer = null;
            }
        };

        const pollImageProgress = async () => {
            try {
                const data = await jsonFetch(`/media-center/projects/${projectId}/sentences/${sentenceId}/generation-status`, 'GET');
                const generation = data?.generation?.image || {};
                const status = String(generation?.status || '').trim().toLowerCase();

                if (status === 'running' || status === 'queued') {
                    const progressText = status === 'running' ? 'Đang tạo ảnh...' : 'Đang chờ slot tạo ảnh...';
                    setRowStatus(row, `Câu #${sentenceIndex}: ${progressText}`, 'normal');
                    return;
                }

                if (status === 'completed') {
                    stopImagePolling();
                    setRowStatus(row, `Ảnh câu #${sentenceIndex} đã tạo xong. Đang cập nhật danh sách...`, 'success');
                    setTimeout(() => window.location.reload(), 600);
                    return;
                }

                if (status === 'failed') {
                    stopImagePolling();
                    setRowStatus(row, `Tạo ảnh câu #${sentenceIndex} thất bại: ${generation?.message || 'Unknown error'}`, 'error');
                    return;
                }
            } catch (e) {
                stopImagePolling();
                setRowStatus(row, `Không poll được tiến trình ảnh câu #${sentenceIndex}: ${e.message}`, 'error');
            }
        };

        const startImagePolling = () => {
            stopImagePolling();
            pollImageProgress();
            imagePollTimer = setInterval(pollImageProgress, 2200);
        };

        const pollAnimationProgress = async () => {
            try {
                const data = await jsonFetch(`/media-center/projects/${projectId}/sentences/${sentenceId}/generation-status`, 'GET');
                const generation = data?.generation?.animation || {};
                updateAnimationProgressUI(row, generation);

                const status = String(generation?.status || '').trim().toLowerCase();
                if (status === 'completed') {
                    refreshAnimationThumbs(row, data?.animation_items || []);
                    stopAnimationPolling();
                    setRowStatus(row, `Animation câu #${sentenceIndex} hoàn tất.`, 'success');
                }

                if (status === 'failed') {
                    stopAnimationPolling();
                    setRowStatus(row, `Animation câu #${sentenceIndex} thất bại: ${generation?.message || 'Unknown error'}`, 'error');
                }
            } catch (e) {
                stopAnimationPolling();
                setRowStatus(row, `Không poll được tiến trình animation câu #${sentenceIndex}: ${e.message}`, 'error');
            }
        };

        const startAnimationPolling = () => {
            stopAnimationPolling();
            pollAnimationProgress();
            animationPollTimer = setInterval(pollAnimationProgress, 2500);
        };

        const applyCameraChoreoPreset = (preset) => {
            const value = (preset || '').trim();
            if (!value) return;

            if (value === 'slow-drama') {
                if (animationMode) animationMode.value = 'image-to-cinematic-shot';
                if (animationCameraAngle) animationCameraAngle.value = 'dolly-in';
                if (animationInstruction) animationInstruction.value = 'Slow cinematic push-in, gentle breathing and subtle fabric/hair movement, poetic dramatic pacing.';
            }

            if (value === 'action-chase') {
                if (animationMode) animationMode.value = 'image-to-action';
                if (animationCameraAngle) animationCameraAngle.value = 'tracking-shot';
                if (animationInstruction) animationInstruction.value = 'Fast dynamic movement, energetic camera follow, impactful motion blur feeling but keep identity and scene stable.';
            }

            if (value === 'emotional-close-up') {
                if (animationMode) animationMode.value = 'image-to-character-animation';
                if (animationCameraAngle) animationCameraAngle.value = 'close-up';
                if (animationInstruction) animationInstruction.value = 'Emotional close-up acting, micro facial expressions, eye focus, subtle lip movement and soft dramatic lighting continuity.';
            }

            renderStoryTimeline();
        };

        const fieldMap = {
            image: imagePrompt,
            video: videoPrompt,
        };

        quickGenPromptBtn?.addEventListener('click', async () => {
            const btn = quickGenPromptBtn;
            const originalLabel = (btn.textContent || 'Gen Prompt').trim() || 'Gen Prompt';
            btn.disabled = true;
            btn.classList.add('opacity-70', 'cursor-not-allowed');
            btn.textContent = 'Dang gen...';

            setRowStatus(row, `AI đang viết lại prompt cho câu #${sentenceIndex}...`);
            try {
                const data = await jsonFetch(`/media-center/projects/${projectId}/regenerate-weak-prompts`, 'POST', {
                    sentence_ids: [Number(sentenceId)],
                });

                const updatedRows = Array.isArray(data?.updated_sentences) ? data.updated_sentences : [];
                const updatedRow = updatedRows.find((item) => Number(item?.id || 0) === Number(sentenceId)) || updatedRows[0] || null;

                if (updatedRow) {
                    if (ttsText) ttsText.value = updatedRow.tts_text || ttsText.value;
                    if (imagePrompt) imagePrompt.value = updatedRow.image_prompt || imagePrompt.value;
                    if (videoPrompt) videoPrompt.value = updatedRow.video_prompt || videoPrompt.value;
                    setRowStatus(row, `AI đã cập nhật prompt cho câu #${sentenceIndex}.`, 'success');
                } else {
                    setRowStatus(row, `Câu #${sentenceIndex} không có thay đổi prompt.`, 'success');
                }
            } catch (e) {
                setRowStatus(row, `Lỗi gen prompt câu #${sentenceIndex}: ${e.message}`, 'error');
            } finally {
                btn.disabled = false;
                btn.classList.remove('opacity-70', 'cursor-not-allowed');
                btn.textContent = originalLabel;
            }
        });

        const runGenerateImageForSentence = async () => {
            setRowStatus(row, `Đang generate ảnh cho câu #${sentenceIndex}...`);
            try {
                const imagePayload = {
                    ...getImageSelectionPayload(),
                    reference_sentence_ids: getSelectedImageReferenceSentenceIds(),
                };
                const data = await jsonFetch(`/media-center/projects/${projectId}/sentences/${sentenceId}/generate-image`, 'POST', imagePayload);
                setRowStatus(row, data?.message || `Đã đưa câu #${sentenceIndex} vào queue tạo ảnh.`, 'success');
                startImagePolling();
            } catch (e) {
                setRowStatus(row, `Lỗi generate ảnh câu #${sentenceIndex}: ${e.message}`, 'error');
            }
        };

        quickGenImageBtn?.addEventListener('click', async () => {
            await runGenerateImageForSentence();
        });

        sentenceCollapseToggleBtn?.addEventListener('click', () => {
            const currentlyCollapsed = row.dataset.collapsed !== '0';
            setSentenceCardCollapsedState(row, !currentlyCollapsed);
        });

        // Default: collapse all cards, keep only title + TTS text + thumbnails visible.
        setSentenceCardCollapsedState(row, true);

        animationImageChecks.forEach((check) => {
            check.addEventListener('change', () => {
                renderStoryTimeline();
            });
        });

        animationMode?.addEventListener('change', renderStoryTimeline);
        cameraChoreoPreset?.addEventListener('change', () => {
            applyCameraChoreoPreset(cameraChoreoPreset.value || '');
        });

        renderStoryTimeline();

        const initialAnimationWrap = row.querySelector('.animation-progress-wrap');
        const initialStatus = (initialAnimationWrap?.dataset?.status || '').trim().toLowerCase();
        if (initialStatus === 'queued' || initialStatus === 'running') {
            startAnimationPolling();
        }

        const initialImageStatus = String(row.dataset.imageStatus || '').trim().toLowerCase();
        if (initialImageStatus === 'queued' || initialImageStatus === 'running') {
            startImagePolling();
        }

        row.querySelectorAll('.save-sentence-btn').forEach((saveBtn) => {
            let saveFeedbackTimer = null;

            const baseLabel = (saveBtn.textContent || 'Save').trim() || 'Save';
            saveBtn.dataset.baseLabel = baseLabel;

            const restoreSaveButton = () => {
                saveBtn.disabled = false;
                saveBtn.dataset.saving = '0';
                saveBtn.textContent = saveBtn.dataset.baseLabel || 'Save';
                saveBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                saveBtn.style.backgroundColor = '';
                saveBtn.style.borderColor = '';
                saveBtn.style.color = '';
            };

            saveBtn.addEventListener('click', async () => {
                if (saveBtn.dataset.saving === '1') {
                    return;
                }

                if (saveFeedbackTimer) {
                    clearTimeout(saveFeedbackTimer);
                    saveFeedbackTimer = null;
                }

                saveBtn.dataset.saving = '1';
                saveBtn.disabled = true;
                saveBtn.classList.add('opacity-70', 'cursor-not-allowed');
                saveBtn.textContent = 'Dang luu...';

                setStatus(`Đang lưu câu #${sentenceId}...`);
                try {
                    await jsonFetch(`/media-center/projects/${projectId}/sentences/${sentenceId}`, 'PUT', {
                        tts_text: ttsText?.value || '',
                        image_prompt: imagePrompt?.value || '',
                        video_prompt: videoPrompt?.value || '',
                    });
                    setStatus(`Lưu câu #${sentenceId} thành công.`, 'success');

                    saveBtn.textContent = 'Da luu';
                    saveBtn.style.backgroundColor = '#16a34a';
                    saveBtn.style.borderColor = '#16a34a';
                    saveBtn.style.color = '#ffffff';
                    saveFeedbackTimer = setTimeout(() => {
                        restoreSaveButton();
                    }, 1200);
                } catch (e) {
                    setStatus('Lỗi save câu: ' + e.message, 'error');

                    saveBtn.textContent = 'Loi, thu lai';
                    saveBtn.style.backgroundColor = '#dc2626';
                    saveBtn.style.borderColor = '#dc2626';
                    saveBtn.style.color = '#ffffff';
                    saveFeedbackTimer = setTimeout(() => {
                        restoreSaveButton();
                    }, 1500);
                }
            });
        });

        row.querySelector('.generate-tts-btn')?.addEventListener('click', async () => {
            setStatus(`Đang generate TTS cho câu #${sentenceId}...`);
            try {
                const ttsPayload = getTtsSelectionPayload();
                await jsonFetch(`/media-center/projects/${projectId}/sentences/${sentenceId}/generate-tts`, 'POST', ttsPayload);
                setStatus(`Generate TTS câu #${sentenceId} xong.`, 'success');
                window.location.reload();
            } catch (e) {
                setStatus('Lỗi generate TTS: ' + e.message, 'error');
            }
        });

        generateImageBtn?.addEventListener('click', async () => {
            await runGenerateImageForSentence();
        });

        row.querySelector('.generate-animation-btn')?.addEventListener('click', async () => {
            if (row.dataset.animSubmitting === '1') {
                setRowStatus(row, `Dang gui yeu cau animation cho cau #${sentenceIndex}, vui long doi...`);
                return;
            }

            const provider = (mediaCenterAnimationProvider?.value || 'kling').trim() || 'kling';
            const mode = (animationMode?.value || 'image-to-motion').trim() || 'image-to-motion';
            const cameraAngle = (animationCameraAngle?.value || 'auto').trim() || 'auto';
            const instruction = (animationInstruction?.value || '').trim();
            const selectedImagePaths = getSelectedAnimationPaths();
            const generateAnimationBtn = row.querySelector('.generate-animation-btn');

            if (mode !== 'image-to-story-sequence' && selectedImagePaths.length > 1) {
                setRowStatus(row, `Mode hiện tại chỉ hỗ trợ 1 ảnh. Chuyển sang Story Sequence để dùng nhiều ảnh.`, 'error');
                return;
            }

            row.dataset.animSubmitting = '1';
            if (generateAnimationBtn) {
                generateAnimationBtn.disabled = true;
                generateAnimationBtn.classList.add('opacity-60', 'cursor-not-allowed');
            }
            setRowStatus(row, `Đang queue animation (${provider}, ${mode}) cho câu #${sentenceIndex}...`);
            try {
                const data = await jsonFetch(`/media-center/projects/${projectId}/sentences/${sentenceId}/generate-animation`, 'POST', {
                    provider,
                    mode,
                    camera_angle: cameraAngle,
                    instruction,
                    selected_image_paths: selectedImagePaths,
                });
                updateAnimationProgressUI(row, {
                    status: 'queued',
                    progress: 0,
                    message: data?.message || 'Đã vào hàng đợi.',
                });
                setRowStatus(row, `Animation đã vào queue. Đang theo dõi tiến trình...`, 'success');
                closeAnimationStudioModal();
                startAnimationPolling();
            } catch (e) {
                setRowStatus(row, `Lỗi generate animation câu #${sentenceIndex}: ${e.message}`, 'error');
                row.dataset.animSubmitting = '0';
                if (generateAnimationBtn) {
                    generateAnimationBtn.disabled = false;
                    generateAnimationBtn.classList.remove('opacity-60', 'cursor-not-allowed');
                }
                return;
            }

            setTimeout(() => {
                row.dataset.animSubmitting = '0';
                if (generateAnimationBtn) {
                    generateAnimationBtn.disabled = false;
                    generateAnimationBtn.classList.remove('opacity-60', 'cursor-not-allowed');
                }
            }, 3000);
        });

        row.querySelector('.suggest-animation-plan-btn')?.addEventListener('click', async () => {
            const mode = (animationMode?.value || 'image-to-motion').trim() || 'image-to-motion';
            const cameraAngle = (animationCameraAngle?.value || 'auto').trim() || 'auto';
            const instruction = (animationInstruction?.value || '').trim();
            const currentVideoPrompt = (videoPrompt?.value || '').trim();
            const selectedImagePaths = getSelectedAnimationPaths();

            if (!selectedImagePaths.length) {
                setRowStatus(row, `Hãy chọn ít nhất 1 ảnh (tick Use) trước khi AI đề xuất.`, 'error');
                return;
            }

            if (mode !== 'image-to-story-sequence' && selectedImagePaths.length > 1) {
                setRowStatus(row, `Mode hiện tại chỉ hỗ trợ 1 ảnh. Chuyển sang Story Sequence để phân tích nhiều ảnh.`, 'error');
                return;
            }

            setRowStatus(row, `AI đang phân tích ảnh và đề xuất motion cho câu #${sentenceIndex}...`);

            try {
                const data = await jsonFetch(`/media-center/projects/${projectId}/sentences/${sentenceId}/suggest-animation-plan`, 'POST', {
                    mode,
                    camera_angle: cameraAngle,
                    instruction,
                    selected_image_paths: selectedImagePaths,
                    current_video_prompt: currentVideoPrompt,
                });

                if (videoPrompt && (data?.refined_prompt_en || '').trim()) {
                    videoPrompt.value = data.refined_prompt_en.trim();
                }

                const cameraPlan = (data?.camera_plan || '').trim();
                const motionHint = (data?.suggested_motion_en || '').trim();
                if (animationInstruction && motionHint) {
                    animationInstruction.value = motionHint;
                }

                const note = cameraPlan ? ` Camera: ${cameraPlan}` : '';
                setRowStatus(row, `AI đã đề xuất motion và rewrite prompt cho câu #${sentenceIndex}.${note}`, 'success');
            } catch (e) {
                setRowStatus(row, `Lỗi AI đề xuất motion câu #${sentenceIndex}: ${e.message}`, 'error');
            }
        });

        row.querySelectorAll('.delete-sentence-image-btn').forEach((btn) => {
            btn.addEventListener('click', async (event) => {
                event.preventDefault();
                event.stopPropagation();

                const imagePath = (btn.dataset.imagePath || '').trim();
                if (!imagePath) {
                    setRowStatus(row, `Không xác định được ảnh cần xóa cho câu #${sentenceIndex}.`, 'error');
                    return;
                }

                const confirmed = confirm(`Xóa ảnh này khỏi câu #${sentenceIndex}?`);
                if (!confirmed) {
                    return;
                }

                setRowStatus(row, `Đang xóa ảnh của câu #${sentenceIndex}...`);

                try {
                    await jsonFetch(`/media-center/projects/${projectId}/sentences/${sentenceId}/images`, 'DELETE', {
                        image_path: imagePath,
                    });
                    setRowStatus(row, `Đã xóa ảnh của câu #${sentenceIndex}.`, 'success');
                    window.location.reload();
                } catch (e) {
                    setRowStatus(row, `Lỗi xóa ảnh câu #${sentenceIndex}: ${e.message}`, 'error');
                }
            });
        });

        row.addEventListener('click', async (event) => {
            const btn = event.target.closest('.delete-sentence-animation-btn');
            if (!btn) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const animationPath = (btn.dataset.animationPath || '').trim();
            if (!animationPath) {
                setRowStatus(row, `Khong xac dinh duoc clip can xoa cho cau #${sentenceIndex}.`, 'error');
                return;
            }

            const confirmed = confirm(`Xoa clip animation nay khoi cau #${sentenceIndex}?`);
            if (!confirmed) {
                return;
            }

            setRowStatus(row, `Dang xoa clip animation cua cau #${sentenceIndex}...`);

            try {
                const data = await jsonFetch(`/media-center/projects/${projectId}/sentences/${sentenceId}/animations`, 'DELETE', {
                    animation_path: animationPath,
                });

                refreshAnimationThumbs(row, data?.animation_items || []);
                setRowStatus(row, `Da xoa clip animation cua cau #${sentenceIndex}.`, 'success');
            } catch (e) {
                setRowStatus(row, `Loi xoa clip animation cau #${sentenceIndex}: ${e.message}`, 'error');
            }
        });

        row.querySelectorAll('.translate-vie-btn').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const field = (btn.dataset.field || '').trim();
                const inputEl = fieldMap[field];
                const sourceText = (inputEl?.value || '').trim();

                if (!sourceText) {
                    setRowStatus(row, `Nội dung câu #${sentenceIndex} đang trống, không có gì để dịch.`, 'error');
                    return;
                }

                const originalText = btn.textContent;
                btn.textContent = '...';
                btn.disabled = true;

                openVieTranslateModal('Bản dịch tiếng Việt', `Đang dịch câu #${sentenceIndex}...`, '', '');

                try {
                    const data = await jsonFetch(`/media-center/projects/${projectId}/sentences/${sentenceId}/translate-vie`, 'POST', {
                        text: sourceText,
                    });

                    const translatedText = (data?.translated_text || '').trim();
                    activeVieContext = {
                        scope: 'sentence',
                        row,
                        sentenceId,
                        sentenceIndex,
                        field,
                        inputEl,
                        sourceText,
                        translatedText,
                    };
                    openVieTranslateModal(
                        `Bản dịch tiếng Việt - Câu #${sentenceIndex} (${vieFieldLabels[field] || field})`,
                        `Đã dịch xong cho trường ${vieFieldLabels[field] || field}.`,
                        sourceText,
                        translatedText || '(Không có nội dung dịch)'
                    );
                } catch (e) {
                    activeVieContext = {
                        scope: 'sentence',
                        row,
                        sentenceId,
                        sentenceIndex,
                        field,
                        inputEl,
                        sourceText,
                        translatedText: '',
                    };
                    openVieTranslateModal(
                        `Bản dịch tiếng Việt - Câu #${sentenceIndex}`,
                        `Lỗi dịch: ${e.message}`,
                        sourceText,
                        ''
                    );
                } finally {
                    btn.textContent = originalText || 'VIE';
                    btn.disabled = false;
                }
            });
        });
    });
})();
</script>
@endpush
@endif

@push('scripts')
<script>
(function() {
    const createForm = document.getElementById('createProjectForm');
    if (!createForm) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const titleEl = document.getElementById('projectTitle');
    const storyEraEl = document.getElementById('storyEra');
    const storyGenreEl = document.getElementById('storyGenre');
    const worldContextEl = document.getElementById('worldContext');
    const forbiddenEl = document.getElementById('forbiddenElements');
    const imageAspectRatioEl = document.getElementById('imageAspectRatio');
    const imageStyleEl = document.getElementById('imageStyle');
    const storyEraPresetEl = document.getElementById('storyEraPreset');
    const storyGenrePresetEl = document.getElementById('storyGenrePreset');
    const worldContextPresetEl = document.getElementById('worldContextPreset');
    const saveStoryEraPresetBtn = document.getElementById('saveStoryEraPresetBtn');
    const saveStoryGenrePresetBtn = document.getElementById('saveStoryGenrePresetBtn');
    const saveWorldContextPresetBtn = document.getElementById('saveWorldContextPresetBtn');
    const sourceEl = document.getElementById('sourceText');
    const updateBtn = document.getElementById('updateProjectBtn');
    const createSubmitBtn = document.getElementById('createProjectSubmitBtn');
    const contentEditorPanel = document.getElementById('contentEditorPanel');
    const openCreateContentBtn = document.getElementById('openCreateContentBtn');
    const openEditContentBtn = document.getElementById('openEditContentBtn');
    const deleteProjectBtn = document.getElementById('deleteProjectBtn');
    const rebuildSentencesWrapper = document.getElementById('rebuildSentencesWrapper');
    const selectedProjectId = @json($selectedProject->id ?? 0);
    const rebuildOnUpdateEl = document.getElementById('rebuildSentencesOnUpdate');
    const contentListSearchInput = document.getElementById('contentListSearchInput');
    const contentListStatusFilter = document.getElementById('contentListStatusFilter');
    const contentListFilterSummary = document.getElementById('contentListFilterSummary');
    const contentProjectItems = Array.from(document.querySelectorAll('.content-project-item'));

    const normalizeSearchText = (value) => String(value || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();

    const applyContentProjectFilters = () => {
        if (!contentProjectItems.length) {
            return;
        }

        const keyword = normalizeSearchText(contentListSearchInput?.value || '');
        const selectedStatus = String(contentListStatusFilter?.value || 'all').trim();
        let visibleCount = 0;

        contentProjectItems.forEach((item) => {
            const statusKey = String(item.dataset.statusKey || 'new').trim();
            const rawSearch = String(item.dataset.searchText || item.textContent || '');
            const matchesKeyword = keyword === '' || normalizeSearchText(rawSearch).includes(keyword);
            const matchesStatus = selectedStatus === 'all' || statusKey === selectedStatus;
            const shouldShow = matchesKeyword && matchesStatus;

            item.classList.toggle('hidden', !shouldShow);
            if (shouldShow) {
                visibleCount++;
            }
        });

        if (contentListFilterSummary) {
            const total = contentProjectItems.length;
            const statusLabel = selectedStatus === 'all'
                ? 'tat ca trang thai'
                : (selectedStatus === 'new' ? 'moi' : (selectedStatus === 'in_progress' ? 'dang lam' : 'hoan thanh'));
            const searchInfo = keyword ? `, keyword: "${contentListSearchInput?.value || ''}"` : '';
            contentListFilterSummary.textContent = `Dang hien thi ${visibleCount}/${total} content (${statusLabel}${searchInfo}).`;
        }
    };

    contentListSearchInput?.addEventListener('input', applyContentProjectFilters);
    contentListStatusFilter?.addEventListener('change', applyContentProjectFilters);
    applyContentProjectFilters();

    const setupAspectRatioChooser = (containerSelector, inputEl, activeClass) => {
        const container = document.querySelector(containerSelector);
        if (!container || !inputEl) return;

        const applySelection = (ratio) => {
            inputEl.value = ratio;
            container.querySelectorAll('button[data-ratio]').forEach((btn) => {
                if ((btn.dataset.ratio || '') === ratio) {
                    btn.classList.add(...activeClass);
                    btn.classList.remove('border-slate-300', 'bg-white', 'text-slate-700');
                } else {
                    btn.classList.remove(...activeClass);
                    btn.classList.add('border-slate-300', 'bg-white', 'text-slate-700');
                }
            });
        };

        const initial = (inputEl.value || '16:9').trim();
        applySelection(initial);

        container.querySelectorAll('button[data-ratio]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const ratio = (btn.dataset.ratio || '16:9').trim();
                applySelection(ratio);
            });
        });
    };

    setupAspectRatioChooser('#imageAspectRatioOptions', imageAspectRatioEl, ['border-fuchsia-400', 'bg-fuchsia-50', 'text-fuchsia-700']);

    const showContentEditorMode = (mode) => {
        if (!contentEditorPanel) return;
        contentEditorPanel.classList.remove('hidden');

        const isEdit = mode === 'edit' && !!selectedProjectId;

        if (createSubmitBtn) {
            createSubmitBtn.classList.toggle('hidden', isEdit);
        }
        if (updateBtn) {
            updateBtn.classList.toggle('hidden', !isEdit);
        }
        if (deleteProjectBtn) {
            deleteProjectBtn.classList.toggle('hidden', !isEdit);
        }
        if (rebuildSentencesWrapper) {
            rebuildSentencesWrapper.classList.toggle('hidden', !isEdit);
        }

        if (!isEdit) {
            if (titleEl) titleEl.value = '';
            if (sourceEl) sourceEl.value = '';
            if (rebuildOnUpdateEl) rebuildOnUpdateEl.checked = false;
        }
    };

    openCreateContentBtn?.addEventListener('click', () => {
        showContentEditorMode('create');
    });

    openEditContentBtn?.addEventListener('click', () => {
        showContentEditorMode('edit');
    });

    // Default: keep create/edit panel collapsed to separate content list and studio area.
    contentEditorPanel?.classList.add('hidden');

    const presetDefaults = {
        storyEra: [
            'Bắc Tống Trung Quốc, cổ trang',
            'Nam Tống Trung Quốc, cổ trang',
            'Thời Minh Trung Quốc, cổ trang',
            'Thời Thanh Trung Quốc, cổ trang',
            'Giang hồ hư cấu thời phong kiến',
            'Việt Nam phong kiến, cổ trang',
        ],
        storyGenre: [
            'Kiếm hiệp',
            'Tiên hiệp',
            'Võ hiệp chính kịch',
            'Cung đấu cổ trang',
            'Dã sử cổ trang',
            'Trinh thám cổ trang',
        ],
        worldContext: [
            'Không gian Trung Hoa cổ đại, giang hồ và quan trường đan xen, không có công nghệ hiện đại.',
            'Bối cảnh võ lâm cổ trang, thành trì, tửu quán, dịch trạm, nha môn; luật lệ phong kiến chi phối.',
            'Thế giới kiếm hiệp thời Tống, trọng danh dự môn phái, mâu thuẫn quyền lực triều đình - giang hồ.',
            'Bối cảnh cổ đại Đông Á, đạo cụ và kiến trúc truyền thống, ánh sáng tự nhiên, trang phục cổ phục.',
        ],
    };

    const loadCustomPresets = (key) => {
        try {
            const raw = localStorage.getItem(key);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed.filter((v) => typeof v === 'string' && v.trim() !== '') : [];
        } catch (e) {
            return [];
        }
    };

    const saveCustomPresets = (key, values) => {
        localStorage.setItem(key, JSON.stringify(values));
    };

    const refreshPresetSelect = (selectEl, values) => {
        if (!selectEl) return;
        const currentValue = selectEl.value;
        const headerOption = selectEl.options[0] ? selectEl.options[0].outerHTML : '<option value="">Preset...</option>';
        selectEl.innerHTML = headerOption;
        values.forEach((value) => {
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value;
            selectEl.appendChild(opt);
        });
        if (currentValue && values.includes(currentValue)) {
            selectEl.value = currentValue;
        }
    };

    const initPresetControl = (opts) => {
        const {
            selectEl,
            inputEl,
            saveBtn,
            storageKey,
            defaults,
            onSelect,
        } = opts;

        if (!selectEl || !inputEl) return;

        const custom = loadCustomPresets(storageKey);
        const merged = Array.from(new Set([...(defaults || []), ...custom]));
        refreshPresetSelect(selectEl, merged);

        selectEl.addEventListener('change', () => {
            const value = (selectEl.value || '').trim();
            if (!value) return;
            inputEl.value = value;
            if (typeof onSelect === 'function') {
                onSelect(value);
            }
        });

        saveBtn?.addEventListener('click', () => {
            const value = (inputEl.value || '').trim();
            if (!value) {
                alert('Vui lòng nhập giá trị trước khi lưu preset.');
                return;
            }

            const currentCustom = loadCustomPresets(storageKey);
            if (!currentCustom.includes(value)) {
                currentCustom.push(value);
                saveCustomPresets(storageKey, currentCustom);
            }

            const updatedMerged = Array.from(new Set([...(defaults || []), ...currentCustom]));
            refreshPresetSelect(selectEl, updatedMerged);
            selectEl.value = value;
        });
    };

    initPresetControl({
        selectEl: storyEraPresetEl,
        inputEl: storyEraEl,
        saveBtn: saveStoryEraPresetBtn,
        storageKey: 'mediaCenterPreset_storyEra',
        defaults: presetDefaults.storyEra,
    });

    initPresetControl({
        selectEl: storyGenrePresetEl,
        inputEl: storyGenreEl,
        saveBtn: saveStoryGenrePresetBtn,
        storageKey: 'mediaCenterPreset_storyGenre',
        defaults: presetDefaults.storyGenre,
    });

    initPresetControl({
        selectEl: worldContextPresetEl,
        inputEl: worldContextEl,
        saveBtn: saveWorldContextPresetBtn,
        storageKey: 'mediaCenterPreset_worldContext',
        defaults: presetDefaults.worldContext,
        onSelect: () => {
            if (!forbiddenEl) return;
            const hasValue = (forbiddenEl.value || '').trim() !== '';
            if (!hasValue) {
                forbiddenEl.value = 'văn phòng, tòa nhà kính, xe hơi, điện thoại, máy tính, đèn neon';
            }
        },
    });

    createForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const sourceText = (sourceEl?.value || '').trim();
        if (!sourceText) {
            alert('Vui lòng nhập văn bản trước khi tạo record.');
            return;
        }

        try {
            const res = await fetch('/media-center/projects', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    title: titleEl?.value || '',
                    story_era: storyEraEl?.value || '',
                    story_genre: storyGenreEl?.value || '',
                    world_context: worldContextEl?.value || '',
                    forbidden_elements: forbiddenEl?.value || '',
                    image_aspect_ratio: imageAspectRatioEl?.value || '16:9',
                    image_style: imageStyleEl?.value || 'Cinematic',
                    source_text: sourceText,
                    language: 'vi'
                })
            });

            const data = await res.json();
            if (!res.ok || !data.success) {
                throw new Error(data.message || 'Tạo record thất bại.');
            }

            window.location.href = data.redirect_url;
        } catch (e2) {
            alert('Lỗi tạo record: ' + (e2?.message || 'Không xác định'));
        }
    });

    updateBtn?.addEventListener('click', async () => {
        if (!selectedProjectId) {
            alert('Chưa chọn record để cập nhật.');
            return;
        }

        const sourceText = (sourceEl?.value || '').trim();
        if (!sourceText) {
            alert('Source text không được để trống.');
            return;
        }

        try {
            const res = await fetch(`/media-center/projects/${selectedProjectId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    title: titleEl?.value || '',
                    story_era: storyEraEl?.value || '',
                    story_genre: storyGenreEl?.value || '',
                    world_context: worldContextEl?.value || '',
                    forbidden_elements: forbiddenEl?.value || '',
                    image_aspect_ratio: imageAspectRatioEl?.value || '16:9',
                    image_style: imageStyleEl?.value || 'Cinematic',
                    source_text: sourceText,
                    language: 'vi',
                    rebuild_sentences: !!rebuildOnUpdateEl?.checked,
                })
            });

            const data = await res.json();
            if (!res.ok || !data.success) {
                throw new Error(data.message || 'Cập nhật record thất bại.');
            }

            window.location.href = data.redirect_url;
        } catch (e2) {
            alert('Lỗi cập nhật record: ' + (e2?.message || 'Không xác định'));
        }
    });

    deleteProjectBtn?.addEventListener('click', async () => {
        if (!selectedProjectId) {
            alert('Chưa chọn record để xóa.');
            return;
        }

        const sure = confirm('Bạn có chắc muốn xóa record này? Hành động này không thể hoàn tác.');
        if (!sure) {
            return;
        }

        try {
            const res = await fetch(`/media-center/projects/${selectedProjectId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                }
            });

            const data = await res.json();
            if (!res.ok || !data.success) {
                throw new Error(data.message || 'Xóa record thất bại.');
            }

            window.location.href = data.redirect_url || '/media-center';
        } catch (e2) {
            alert('Lỗi xóa record: ' + (e2?.message || 'Không xác định'));
        }
    });
})();
</script>
@endpush
