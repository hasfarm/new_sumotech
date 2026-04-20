@extends('layouts.app')

@section('content')
    @php
        $statusClass = match ($indexStatus) {
            'Đã index đầy đủ', 'Da index day du' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
            'Đang index', 'Dang index' => 'bg-amber-100 text-amber-800 border-amber-200',
            'Index có lỗi', 'Index co loi' => 'bg-rose-100 text-rose-800 border-rose-200',
            default => 'bg-slate-100 text-slate-700 border-slate-200',
        };
    @endphp

    <div class="relative min-h-screen overflow-hidden bg-slate-100">
        <div class="pointer-events-none absolute inset-0 opacity-80"
            style="background: radial-gradient(circle at 20% 20%, rgba(14, 165, 233, 0.2), transparent 40%), radial-gradient(circle at 85% 15%, rgba(16, 185, 129, 0.2), transparent 35%), linear-gradient(120deg, rgba(15, 23, 42, 0.04), rgba(2, 132, 199, 0.03));">
        </div>

        <div class="relative py-6">
            <div class="mx-auto max-w-[1500px] px-4 sm:px-6 lg:px-8">
                <div class="mb-4 rounded-2xl border border-slate-200 bg-white/90 p-4 shadow-sm backdrop-blur">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <h1 class="text-2xl font-black tracking-tight text-slate-900">Book Insight Studio</h1>
                            <p class="text-sm text-slate-600">Phan tich chuyen sau tu du lieu chapter chunks da embedding.</p>
                        </div>

                        <form id="bookInsightFilterForm" method="GET" action="{{ route('audiobooks.insight.studio') }}"
                            class="grid w-full gap-3 md:grid-cols-5 xl:w-auto xl:min-w-[900px]">
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Chon sach</label>
                                <select id="bookInsightSelect" name="audio_book_id"
                                    class="w-full rounded-xl border-slate-300 bg-white px-3 py-2 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                                    @forelse ($books as $book)
                                        <option value="{{ $book->id }}" {{ $selectedBookId === (int) $book->id ? 'selected' : '' }}>
                                            #{{ $book->id }} - {{ $book->title }}
                                        </option>
                                    @empty
                                        <option value="">Chua co sach</option>
                                    @endforelse
                                </select>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Trang thai index</label>
                                <div class="inline-flex h-10 w-full items-center justify-center rounded-xl border text-sm font-semibold {{ $statusClass }}">
                                    {{ $indexStatus }}
                                </div>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">So chuong</label>
                                <div class="flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800">
                                    {{ $chapterCount }}
                                </div>
                            </div>

                            <div class="flex items-end gap-2">
                                <div class="w-full">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">So chunk</label>
                                    <div class="flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800">
                                        {{ $chunkCount }}
                                    </div>
                                </div>
                                <button type="submit"
                                    class="h-10 shrink-0 rounded-xl bg-cyan-600 px-3 text-sm font-semibold text-white transition hover:bg-cyan-700">
                                    Refresh Index
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="mt-4 grid gap-2 md:grid-cols-4">
                        <div class="rounded-xl bg-emerald-50 px-3 py-2 text-xs text-emerald-700">Done: <span class="font-bold">{{ $embeddingCounts['done'] }}</span></div>
                        <div class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700">Processing: <span class="font-bold">{{ $embeddingCounts['processing'] }}</span></div>
                        <div class="rounded-xl bg-sky-50 px-3 py-2 text-xs text-sky-700">Pending: <span class="font-bold">{{ $embeddingCounts['pending'] }}</span></div>
                        <div class="rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-700">Error: <span class="font-bold">{{ $embeddingCounts['error'] }}</span></div>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-[260px_minmax(0,1fr)_320px] xl:grid-cols-[280px_minmax(0,1fr)_340px]">
                    <aside class="order-1 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:sticky lg:top-4 lg:h-fit">
                        <h2 class="mb-3 text-sm font-black uppercase tracking-wide text-slate-900">Phạm vi dữ liệu</h2>

                        <div class="space-y-3">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-600">Filter chương</label>
                                <select id="chapterFilter" class="w-full rounded-xl border-slate-300 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                                    <option value="">Tat ca chuong</option>
                                    @foreach ($chapterOptions as $chapterOption)
                                        <option value="{{ $chapterOption['id'] }}">{{ $chapterOption['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-600">Filter nhân vật</label>
                                <select id="characterFilter" class="w-full rounded-xl border-slate-300 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                                    <option value="">Tat ca nhan vat</option>
                                    @foreach ($characterOptions as $characterOption)
                                        <option value="{{ $characterOption }}">{{ $characterOption }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-600">Filter chủ đề</label>
                                <select id="topicFilter" class="w-full rounded-xl border-slate-300 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                                    <option value="">Tat ca chu de</option>
                                    @foreach ($topicOptions as $topicOption)
                                        <option value="{{ $topicOption }}">{{ $topicOption }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-600">Filter loại cảnh</label>
                                <select id="sceneTypeFilter" class="w-full rounded-xl border-slate-300 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                                    <option value="">Tat ca loai canh</option>
                                    @foreach ($sceneTypeOptions as $sceneTypeOption)
                                        <option value="{{ $sceneTypeOption }}">{{ $sceneTypeOption }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="pt-2">
                                <p class="mb-1 text-xs font-bold uppercase tracking-wide text-slate-500">Danh sách chương từ book</p>
                                <div class="max-h-32 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-2">
                                    @forelse ($chapterOptions as $chapterOption)
                                        <p class="truncate text-xs text-slate-700">{{ $chapterOption['label'] }}</p>
                                    @empty
                                        <p class="text-xs text-slate-500">Chưa có chương.</p>
                                    @endforelse
                                </div>
                            </div>

                            <div>
                                <p class="mb-1 text-xs font-bold uppercase tracking-wide text-slate-500">Danh sách nhân vật từ book</p>
                                <div class="max-h-32 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-2">
                                    @forelse ($characterOptions as $characterOption)
                                        <span class="mb-1 mr-1 inline-flex rounded-lg bg-cyan-100 px-2 py-1 text-[11px] font-semibold text-cyan-800">{{ $characterOption }}</span>
                                    @empty
                                        <p class="text-xs text-slate-500">Chưa nhận diện được nhân vật từ dữ liệu hiện có.</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </aside>

                    <div class="order-3 space-y-4 lg:order-2">
                        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="mb-4 flex flex-wrap gap-2" id="insightTabs">
                                @foreach ([
                                    ['key' => 'nhan_vat', 'label' => 'Nhân vật'],
                                    ['key' => 'tom_luoc', 'label' => 'Tóm lược'],
                                    ['key' => 'top_canh', 'label' => 'Top cảnh'],
                                    ['key' => 'binh_luan', 'label' => 'Bình luận'],
                                    ['key' => 'hoi_thoai', 'label' => 'Hội thoại'],
                                ] as $index => $tab)
                                    <button type="button" data-tab-label="{{ $tab['label'] }}"
                                        data-tab-key="{{ $tab['key'] }}"
                                        class="insight-tab rounded-xl px-3 py-2 text-sm font-semibold transition {{ $index === 0 ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                                        {{ $tab['label'] }}
                                    </button>
                                @endforeach
                            </div>

                            <div class="grid gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Form input</label>
                                    <input type="text" id="insightInput"
                                        class="w-full rounded-xl border-slate-300 text-sm focus:border-cyan-500 focus:ring-cyan-500"
                                        value="Phan tich theo chieu sau tam ly nhan vat chinh va cac mau thuan noi tam">
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Prompt box</label>
                                    <textarea id="insightPrompt" rows="8"
                                        class="w-full rounded-xl border-slate-300 text-sm focus:border-cyan-500 focus:ring-cyan-500">Ban la bien tap vien noi dung chuyen sau. Hay tong hop insight dua tren chunks da retrieve, trinh bay ro luan diem, dan chung va ket luan hanh dong.</textarea>
                                </div>

                                <div class="flex justify-end">
                                    <button type="button" id="generateInsight"
                                        class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-bold text-white transition hover:bg-emerald-700">
                                        Generate
                                    </button>
                                </div>
                            </div>
                        </section>

                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex items-center justify-between gap-3">
                                <h2 class="text-base font-black tracking-tight text-slate-900">Output chính</h2>
                                <div class="flex gap-2">
                                    @if ($selectedBook)
                                        <a href="{{ route('audiobooks.media.index', $selectedBook) }}"
                                            class="rounded-xl bg-violet-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-violet-700">Chuyen sang Video</a>
                                        <a href="{{ route('audiobooks.show', $selectedBook) }}"
                                            class="rounded-xl bg-orange-500 px-3 py-2 text-xs font-bold text-white transition hover:bg-orange-600">Chuyen sang TTS</a>
                                    @else
                                        <span class="rounded-xl bg-slate-300 px-3 py-2 text-xs font-bold text-slate-600">Chua chon sach</span>
                                    @endif
                                </div>
                            </div>

                            <div id="insightOutput"
                                class="mt-3 min-h-[220px] whitespace-pre-line rounded-xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-4 text-sm leading-7 text-slate-700">
                                Noi dung phan tich se xuat hien tai day sau khi bam Generate.
                            </div>
                        </div>
                    </div>

                    <aside class="order-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:order-3 lg:sticky lg:top-4 lg:h-fit">
                        <h2 class="mb-3 text-sm font-black uppercase tracking-wide text-slate-900">Chunk đã retrieve</h2>

                        <div id="retrievedChunksList" class="max-h-[520px] space-y-3 overflow-y-auto pr-1">
                            @forelse ($retrievedChunks as $chunk)
                                <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-xs font-semibold text-slate-500">Chuong {{ $chunk['chapter_number'] ?? '?' }} · Chunk {{ $chunk['chunk_number'] }}</p>
                                    <p class="mt-1 text-xs font-medium text-slate-700 line-clamp-4">{{ $chunk['text_content'] }}</p>

                                    <div class="mt-2 grid grid-cols-2 gap-1 text-[11px] text-slate-600">
                                        <div>Score: <span class="font-semibold">{{ $chunk['score'] !== null ? number_format($chunk['score'], 2) : '--' }}</span></div>
                                        <div>Status: <span class="font-semibold">{{ $chunk['embedding_status'] }}</span></div>
                                        <div class="col-span-2">Metadata: {{ $chunk['metadata']['qdrant_point_id'] ?: 'N/A' }}</div>
                                        <div class="col-span-2">Embedded at: {{ $chunk['metadata']['embedded_at'] ?: 'N/A' }}</div>
                                    </div>

                                    @if (!empty($chunk['chapter_number']) && $selectedBook)
                                        <a href="{{ route('audiobooks.show', $selectedBook) }}#chapter-{{ $chunk['chapter_number'] }}"
                                            class="mt-2 inline-flex text-xs font-semibold text-cyan-700 hover:text-cyan-800">
                                            Xem chapter goc
                                        </a>
                                    @endif
                                </article>
                            @empty
                                <p class="rounded-xl border border-dashed border-slate-300 p-3 text-sm text-slate-500">Chua co chunk de hien thi.</p>
                            @endforelse
                        </div>

                        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <h3 class="text-xs font-black uppercase tracking-wide text-slate-700">Log lan generate gan nhat</h3>
                            <p class="mt-2 text-xs text-slate-600" id="lastGenerateLog">Chua co lan generate nao trong phien lam viec nay.</p>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function() {
            const tabs = Array.from(document.querySelectorAll('.insight-tab'));
            const filterForm = document.getElementById('bookInsightFilterForm');
            const bookSelect = document.getElementById('bookInsightSelect');
            const chapterFilter = document.getElementById('chapterFilter');
            const characterFilter = document.getElementById('characterFilter');
            const topicFilter = document.getElementById('topicFilter');
            const sceneTypeFilter = document.getElementById('sceneTypeFilter');
            const input = document.getElementById('insightInput');
            const prompt = document.getElementById('insightPrompt');
            const output = document.getElementById('insightOutput');
            const generateBtn = document.getElementById('generateInsight');
            const lastGenerateLog = document.getElementById('lastGenerateLog');
            const retrievedChunksList = document.getElementById('retrievedChunksList');
            const defaultCharacterPromptTemplate = @json($defaultCharacterPromptTemplate ?? '');
            const generateCharacterUrl = @json(route('audiobooks.insight.studio.generate.character'));

            if (filterForm && bookSelect) {
                bookSelect.addEventListener('change', () => {
                    filterForm.submit();
                });
            }

            let activeTab = tabs.length > 0 ? tabs[0].dataset.tabLabel : 'Nhân vật';
            let activeTabKey = tabs.length > 0 ? tabs[0].dataset.tabKey : 'nhan_vat';

            const getSelectedText = (selectEl) => {
                if (!selectEl || !selectEl.options.length) {
                    return '';
                }
                const idx = selectEl.selectedIndex;
                if (idx < 0) {
                    return '';
                }
                return (selectEl.options[idx].text || '').trim();
            };

            const buildFormInputFromScope = () => {
                const character = getSelectedText(characterFilter);
                const topic = getSelectedText(topicFilter);
                const scene = getSelectedText(sceneTypeFilter);

                const parts = [];
                if (character && !character.toLowerCase().includes('tat ca')) {
                    parts.push('Nhân vật: ' + character);
                }
                if (topic && !topic.toLowerCase().includes('tat ca')) {
                    parts.push('Chủ đề: ' + topic);
                }
                if (scene && !scene.toLowerCase().includes('tat ca')) {
                    parts.push('Loại cảnh: ' + scene);
                }

                return parts.length > 0
                    ? parts.join(' | ')
                    : 'Phân tích nhân vật theo bối cảnh và hành trình nổi bật.';
            };

            const applyCharacterPresetInputs = () => {
                if (prompt) {
                    prompt.value = defaultCharacterPromptTemplate || prompt.value;
                }
                if (input) {
                    input.value = buildFormInputFromScope();
                }
            };

            const renderRetrievedChunks = (chunks) => {
                if (!retrievedChunksList || !Array.isArray(chunks)) {
                    return;
                }

                if (chunks.length === 0) {
                    retrievedChunksList.innerHTML = '<p class="rounded-xl border border-dashed border-slate-300 p-3 text-sm text-slate-500">Khong tim thay chunk phu hop.</p>';
                    return;
                }

                const html = chunks.map((chunk) => {
                    const chapterNumber = chunk.chapter_number ?? '?';
                    const chunkNumber = chunk.chunk_number ?? '?';
                    const score = chunk.score !== null && chunk.score !== undefined ? Number(chunk.score).toFixed(3) : '--';
                    const text = (chunk.text_content || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    const pointId = chunk.metadata?.qdrant_point_id ?? 'N/A';

                    return `
                        <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <p class="text-xs font-semibold text-slate-500">Chuong ${chapterNumber} · Chunk ${chunkNumber}</p>
                            <p class="mt-1 text-xs font-medium text-slate-700 line-clamp-4">${text}</p>
                            <div class="mt-2 grid grid-cols-2 gap-1 text-[11px] text-slate-600">
                                <div>Score: <span class="font-semibold">${score}</span></div>
                                <div>Status: <span class="font-semibold">done</span></div>
                                <div class="col-span-2">Metadata: ${pointId}</div>
                            </div>
                        </article>
                    `;
                }).join('');

                retrievedChunksList.innerHTML = html;
            };

            [characterFilter, topicFilter, sceneTypeFilter].forEach((el) => {
                if (!el) {
                    return;
                }
                el.addEventListener('change', () => {
                    if (activeTabKey === 'nhan_vat' && input) {
                        input.value = buildFormInputFromScope();
                    }
                });
            });

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    activeTab = tab.dataset.tabLabel || 'Nhân vật';
                    activeTabKey = tab.dataset.tabKey || 'nhan_vat';
                    tabs.forEach((item) => {
                        item.classList.remove('bg-slate-900', 'text-white');
                        item.classList.add('bg-slate-100', 'text-slate-700');
                    });
                    tab.classList.remove('bg-slate-100', 'text-slate-700');
                    tab.classList.add('bg-slate-900', 'text-white');

                    if (activeTabKey === 'nhan_vat') {
                        applyCharacterPresetInputs();
                    }
                });
            });

            if (!generateBtn || !output) {
                return;
            }

            if (activeTabKey === 'nhan_vat') {
                applyCharacterPresetInputs();
            }

            generateBtn.addEventListener('click', async () => {
                const now = new Date();
                const hh = String(now.getHours()).padStart(2, '0');
                const mm = String(now.getMinutes()).padStart(2, '0');
                const query = (input?.value || '').trim();
                const promptValue = (prompt?.value || '').trim();

                if (activeTabKey === 'nhan_vat') {
                    const selectedCharacter = characterFilter?.value ? getSelectedText(characterFilter) : '';
                    if (!selectedCharacter || selectedCharacter.toLowerCase().includes('tat ca')) {
                        alert('Vui long chon nhan vat trong Pham vi du lieu truoc khi Generate.');
                        characterFilter?.focus();
                        return;
                    }

                    const bookId = Number(bookSelect?.value || 0);
                    if (!bookId) {
                        alert('Khong xac dinh duoc book dang chon.');
                        return;
                    }

                    const chapterId = Number(chapterFilter?.value || 0);
                    const topic = topicFilter?.value || '';
                    const sceneType = sceneTypeFilter?.value || '';

                    generateBtn.disabled = true;
                    generateBtn.textContent = 'Generating...';
                    output.textContent = 'Dang truy van vector database va tao noi dung nhan vat...';

                    try {
                        const response = await fetch(generateCharacterUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                audio_book_id: bookId,
                                preset: activeTabKey,
                                character_name: selectedCharacter,
                                chapter_id: chapterId > 0 ? chapterId : null,
                                topic,
                                scene_type: sceneType,
                                form_input: query,
                                prompt_template: promptValue,
                            })
                        });

                        const rawBody = await response.text();
                        let data = null;
                        try {
                            data = rawBody ? JSON.parse(rawBody) : null;
                        } catch (e) {
                            data = null;
                        }

                        if (!response.ok || !data?.success) {
                            const errorBag = data?.errors || {};
                            const firstError = Object.values(errorBag).flat?.()[0] || null;
                            const message = firstError || data?.message || (rawBody ? rawBody.slice(0, 220) : '') || ('Generate that bai. HTTP ' + response.status);
                            throw new Error(message);
                        }

                        output.textContent = data.content || '';
                        renderRetrievedChunks(data.retrieved_chunks || []);
                    } catch (error) {
                        output.textContent = 'Loi: ' + (error?.message || 'Khong the generate noi dung.');
                    } finally {
                        generateBtn.disabled = false;
                        generateBtn.textContent = 'Generate';
                    }

                    if (lastGenerateLog) {
                        lastGenerateLog.textContent = 'Generate luc ' + hh + ':' + mm + ' | Tab: ' + activeTab + ' | Character mode';
                    }
                    return;
                }

                output.innerHTML = [
                    '<h3 class="mb-2 text-base font-extrabold text-slate-900">' + activeTab + ' - Insight Draft</h3>',
                    '<p><strong>Trong tam:</strong> ' + (query || 'N/A') + '</p>',
                    '<p><strong>Khung phan tich:</strong> ' + (promptValue || 'N/A') + '</p>',
                    '<p class="mt-3">1. Luan diem chinh: Xac dinh truc cam xuc va mau thuan dan dat toan bo mach truyen.</p>',
                    '<p>2. Dan chung quan trong: Trich cac chunk co score cao de lam bang chung va doi chieu theo tung chuong.</p>',
                    '<p>3. Ket luan bien tap: De xuat cach chuyen hoa insight thanh script video/TTS giau chieu sau.</p>'
                ].join('');

                if (lastGenerateLog) {
                    lastGenerateLog.textContent = 'Generate luc ' + hh + ':' + mm + ' | Tab: ' + activeTab;
                }
            });
        })();
    </script>
@endpush
