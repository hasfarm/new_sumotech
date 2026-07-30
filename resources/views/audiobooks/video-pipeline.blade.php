@extends('layouts.app')

@section('content')
    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6 px-4 sm:px-0">
                <div>
                    <h2 class="font-semibold text-xl sm:text-2xl text-gray-800">🎬 AI Video Production Pipeline</h2>
                    <p class="text-sm text-gray-500 mt-1">{{ $audioBook->title }}</p>
                </div>
                <a href="{{ route('audiobooks.show', $audioBook) }}"
                    class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-1.5 sm:py-2 px-3 sm:px-4 rounded-lg transition duration-200 text-sm sm:text-base">
                    ← Quay lại sách
                </a>
            </div>

            <div id="vp-root" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4 sm:p-6">
                <div class="text-center py-10 text-gray-500">⏳ Đang tải...</div>
            </div>
        </div>
    </div>

    <div id="vp-image-modal" class="fixed inset-0 bg-black bg-opacity-80 z-50 items-center justify-center p-4"
        style="display:none !important;" onclick="if (event.target === this) vpCloseImageModal()">
        <div class="relative max-w-4xl w-full">
            <button type="button" onclick="vpCloseImageModal()"
                class="absolute -top-10 right-0 text-white text-3xl leading-none hover:text-gray-300">&times;</button>
            <img id="vp-modal-img" class="max-w-full max-h-[80vh] rounded-lg mx-auto block" />
            <video id="vp-modal-video" controls class="max-w-full max-h-[80vh] rounded-lg mx-auto block" style="display:none"></video>
            <div class="text-center mt-3">
                <button type="button" id="vp-modal-confirm-btn" onclick="vpModalConfirm()"
                    class="bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 px-6 rounded-lg" style="display:none"></button>
            </div>
        </div>
    </div>

    <div id="vp-detail-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 items-center justify-center p-4"
        style="display:none !important;" onclick="if (event.target === this) vpCloseDetailModal()">
        <div class="bg-white rounded-lg max-w-2xl w-full max-h-[85vh] overflow-y-auto p-5 relative">
            <button type="button" onclick="vpCloseDetailModal()"
                class="absolute top-3 right-4 text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
            <h3 id="vp-detail-modal-title" class="text-lg font-bold text-gray-800 mb-3 pr-8"></h3>
            <div id="vp-detail-modal-body" class="space-y-3"></div>
        </div>
    </div>

    @php
        $vpSearchUrlTemplate = route('audiobooks.video.pipeline.shots.search', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpResolveUrlTemplate = route('audiobooks.video.pipeline.shots.resolve', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpAnimateUrlTemplate = route('audiobooks.video.pipeline.shots.animate', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpAvatarUrlTemplate = route('audiobooks.video.pipeline.shots.avatar', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpSelectUrlTemplate = route('audiobooks.video.pipeline.shots.select', [$audioBook, '__SCENE__', '__SHOT__', '__CID__']);
        $vpActiveTargetUrlTemplate = route('audiobooks.video.pipeline.shots.active-target', [$audioBook, '__SCENE__', '__SHOT__']);
    @endphp
    <script>
        const vpUrls = {
            status: @json(route('audiobooks.video.pipeline.status', $audioBook)),
            start: @json(route('audiobooks.video.pipeline.start', $audioBook)),
            retryFailedChunks: @json(route('audiobooks.video.pipeline.retry-failed-chunks', $audioBook)),
            imageStyle: @json(route('audiobooks.video.pipeline.image-style', $audioBook)),
            imageProvider: @json(route('audiobooks.video.pipeline.image-provider', $audioBook)),
            download: @json(route('audiobooks.video.pipeline.download', $audioBook)),
            summaryStatus: @json(route('audiobooks.summary.status', $audioBook)),
            continuityStatus: @json(route('audiobooks.video.pipeline.continuity.status', $audioBook)),
            continuityValidate: @json(route('audiobooks.video.pipeline.continuity.validate', $audioBook)),
            continuityRevalidateStale: @json(route('audiobooks.video.pipeline.continuity.revalidate-stale', $audioBook)),
            continuityRegenerateSelected: @json(route('audiobooks.video.pipeline.continuity.regenerate-selected', $audioBook)),
            continuityAcceptTemplate: @json(route('audiobooks.video.pipeline.continuity.accept', [$audioBook, '__ISSUE__'])),
            continuityAccept(issueId) { return this.continuityAcceptTemplate.replace('__ISSUE__', issueId); },
            storyBibleRegenerateStale: @json(route('audiobooks.video.pipeline.story-bible.regenerate-stale', $audioBook)),
            storyBibleDetails: @json(route('audiobooks.video.pipeline.story-bible.details', $audioBook)),
            searchTemplate: @json($vpSearchUrlTemplate),
            resolveTemplate: @json($vpResolveUrlTemplate),
            animateTemplate: @json($vpAnimateUrlTemplate),
            avatarTemplate: @json($vpAvatarUrlTemplate),
            selectTemplate: @json($vpSelectUrlTemplate),
            activeTargetTemplate: @json($vpActiveTargetUrlTemplate),
            extensionToken: @json(route('audiobooks.video-pipeline-extension-token')),
            search(sid, hid) { return this.searchTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            resolve(sid, hid) { return this.resolveTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            animate(sid, hid) { return this.animateTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            avatar(sid, hid) { return this.avatarTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            select(sid, hid, cid) { return this.selectTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid).replace('__CID__', cid); },
            activeTarget(sid, hid) { return this.activeTargetTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
        };

        function vpCsrf() {
            return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        }

        async function vpJson(resp) {
            if (!resp.ok) {
                let msg = 'HTTP ' + resp.status;
                try {
                    const body = await resp.json();
                    msg = body.message || msg;
                } catch (e) {}
                throw new Error(msg);
            }
            return resp.json();
        }

        function vpPost(url, body) {
            const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': vpCsrf() };
            const opts = { method: 'POST', headers };
            if (body) {
                headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            return fetch(url, opts).then(vpJson);
        }

        let vpModalConfirmAction = null;

        function vpOpenImageModal(url, isVideo, confirmAction, confirmLabel) {
            const img = document.getElementById('vp-modal-img');
            const video = document.getElementById('vp-modal-video');
            const confirmBtn = document.getElementById('vp-modal-confirm-btn');

            if (isVideo) {
                video.src = url;
                video.style.display = '';
                img.style.display = 'none';
            } else {
                img.src = url;
                img.style.display = '';
                video.style.display = 'none';
                video.pause();
                video.removeAttribute('src');
            }

            if (confirmAction) {
                confirmBtn.style.display = '';
                confirmBtn.textContent = confirmLabel || '✅ Chọn';
                vpModalConfirmAction = confirmAction;
            } else {
                confirmBtn.style.display = 'none';
                vpModalConfirmAction = null;
            }

            document.getElementById('vp-image-modal').style.cssText = 'display:flex !important;';
        }

        function vpCloseImageModal() {
            document.getElementById('vp-image-modal').style.cssText = 'display:none !important;';
            const video = document.getElementById('vp-modal-video');
            video.pause();
            video.removeAttribute('src');
            vpModalConfirmAction = null;
        }

        function vpModalConfirm() {
            if (vpModalConfirmAction) vpModalConfirmAction();
            vpCloseImageModal();
        }

        function vpEscape(str) {
            const div = document.createElement('div');
            div.textContent = str ?? '';
            return div.innerHTML;
        }

        const SCENE_TYPE_LABELS = {
            nature: '🌿 Thiên nhiên', city: '🏙️ Thành phố', history: '📜 Lịch sử',
            character: '🧑 Nhân vật', map: '🗺️ Bản đồ', philosophy: '🤔 Triết lý',
        };

        let vpData = { summary: null, pipeline: null, scenes: [] };
        let vpPollTimer = null;
        let vpPollInterval = null;
        let vpAutoRunningLibrary = false;
        let vpAutoRunningAI = false;
        let vpExpandedScenes = {};
        let vpExpandedShots = {};
        let vpBusyShots = {};
        let vpContinuity = null; // { shot_counts, unresolved_bindings, issues_by_scene, story_bible }
        let vpContinuitySelected = {};
        let vpRegeneratingStale = false;

        async function vpInit() {
            await vpLoadSummary();
            await vpLoad();
            await vpLoadContinuity();
        }

        async function vpLoadContinuity() {
            try {
                const resp = await fetch(vpUrls.continuityStatus, { headers: { Accept: 'application/json' } });
                vpContinuity = await vpJson(resp);
                vpRenderContinuityPanel();
                vpRenderStoryBiblePanel();
            } catch (e) {
                // Continuity panel is best-effort — never break the main pipeline UI over it.
            }
        }

        const STORY_BIBLE_STATUS_LABELS = {
            active: ['bg-green-100 text-green-700', '✅ Đã kích hoạt'],
            draft: ['bg-blue-100 text-blue-700', '⏳ Đang tạo (draft)'],
            extracting: ['bg-blue-100 text-blue-700', '⏳ Đang trích xuất'],
            consolidating: ['bg-blue-100 text-blue-700', '⏳ Đang tổng hợp'],
            validating: ['bg-blue-100 text-blue-700', '⏳ Đang kiểm tra'],
            failed: ['bg-red-100 text-red-700', '❌ Thất bại'],
            superseded: ['bg-gray-100 text-gray-500', '— Đã thay thế'],
        };

        function vpRenderStoryBiblePanel() {
            const el = document.getElementById('vp-story-bible-panel');
            if (!el) return;

            const sb = (vpContinuity || {}).story_bible;
            if (!sb) {
                el.innerHTML = '<p class="text-sm text-gray-400">Chưa có dữ liệu.</p>';
                return;
            }

            if (!sb.active_version) {
                const [cls, label] = STORY_BIBLE_STATUS_LABELS[sb.latest_status] || STORY_BIBLE_STATUS_LABELS.failed;
                el.innerHTML = `
                    <p class="text-sm text-gray-600 mb-2">Chưa có Story Bible nào được kích hoạt cho sách này — các cảnh/shot hiện tại KHÔNG có context AI Director (character phase, văn hóa địa điểm, director treatment).</p>
                    <div class="flex flex-wrap items-center gap-2">
                        ${sb.latest_version ? `<span class="px-2 py-1 rounded-lg text-xs font-semibold ${cls}">${label} — v${sb.latest_version}</span>` : ''}
                    </div>
                    ${sb.latest_error ? `<p class="text-xs text-red-600 mt-2">${vpEscape(sb.latest_error)}</p>` : ''}
                `;
                return;
            }

            const [cls, label] = STORY_BIBLE_STATUS_LABELS[sb.active_status] || STORY_BIBLE_STATUS_LABELS.active;
            el.innerHTML = `
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold ${cls}">${label} — v${sb.active_version}</span>
                    <button type="button" onclick="vpShowStoryBibleDetail('timelines')" class="px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-600 hover:bg-gray-200 cursor-pointer">🕐 Timelines: ${sb.timelines_count}</button>
                    <button type="button" onclick="vpShowStoryBibleDetail('locations')" class="px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-600 hover:bg-gray-200 cursor-pointer">📍 Locations: ${sb.locations_count}</button>
                    <button type="button" onclick="vpShowStoryBibleDetail('characters')" class="px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-600 hover:bg-gray-200 cursor-pointer">🧑 Characters: ${sb.characters_count}</button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-indigo-100 text-indigo-700">🔗 Scene đã bind: ${sb.scenes_bound}/${sb.scenes_total}</span>
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold ${sb.scenes_stale > 0 ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'}">${sb.scenes_stale > 0 ? '⚠️' : '✅'} Stale (cần regenerate-stale): ${sb.scenes_stale}</span>
                    ${sb.scenes_stale > 0 ? `
                        <button onclick="vpRegenerateStaleScenes()" id="vp-regenerate-stale-btn" ${vpRegeneratingStale ? 'disabled' : ''}
                            class="text-xs bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                            ${vpRegeneratingStale ? '⏳ Đang regenerate...' : '♻️ Regenerate stale scenes'}
                        </button>` : ''}
                </div>
            `;
        }

        async function vpContinuityValidateAll() {
            await vpPost(vpUrls.continuityValidate);
            await vpLoadContinuity();
        }

        async function vpContinuityRevalidateStale() {
            await vpPost(vpUrls.continuityRevalidateStale);
            await vpLoadContinuity();
        }

        // Dispatches a queued job (RegenerateStaleSceneDirectionJob) — this only submits the
        // request, it does NOT wait for the re-bind/re-enrich work to finish. Progress shows
        // up over subsequent polls as the queue worker processes it.
        async function vpRegenerateStaleScenes() {
            vpRegeneratingStale = true;
            vpRenderStoryBiblePanel();
            try {
                await vpPost(vpUrls.storyBibleRegenerateStale);
            } catch (e) {
                alert('Lỗi khi regenerate stale scenes: ' + e.message);
            }
            vpRegeneratingStale = false;
            await vpLoadContinuity();
        }

        function vpOpenDetailModal(title, bodyHtml) {
            document.getElementById('vp-detail-modal-title').textContent = title;
            document.getElementById('vp-detail-modal-body').innerHTML = bodyHtml;
            document.getElementById('vp-detail-modal').style.cssText = 'display:flex !important;';
        }

        function vpCloseDetailModal() {
            document.getElementById('vp-detail-modal').style.cssText = 'display:none !important;';
        }

        // Extracts a claim's .value, treating null/empty-string/empty-array as "nothing to
        // show" — claims with confidence:"unknown" are skipped entirely here rather than
        // rendered as a hollow placeholder, same convention as the shot-prompt builder.
        function vpClaimValue(claim) {
            if (!claim || claim.value === null || claim.value === undefined) return null;
            if (typeof claim.value === 'string' && claim.value.trim() === '') return null;
            if (Array.isArray(claim.value) && claim.value.length === 0) return null;
            return claim.value;
        }

        function vpClaimLine(label, claim) {
            const v = vpClaimValue(claim);
            if (v === null) return '';
            const text = Array.isArray(v) ? v.join(', ') : String(v);
            return `<div class="text-sm"><span class="font-medium text-gray-700">${label}:</span> <span class="text-gray-600">${vpEscape(text)}</span></div>`;
        }

        async function vpShowStoryBibleDetail(kind) {
            let data;
            try {
                const resp = await fetch(vpUrls.storyBibleDetails, { headers: { Accept: 'application/json' } });
                data = await vpJson(resp);
            } catch (e) {
                alert('Lỗi khi tải chi tiết Story Bible: ' + e.message);
                return;
            }
            if (!data.success) {
                alert(data.message || 'Không tải được dữ liệu.');
                return;
            }

            if (kind === 'timelines') {
                vpOpenDetailModal('🕐 Timelines', vpRenderTimelinesDetail(data.timelines));
            } else if (kind === 'locations') {
                vpOpenDetailModal('📍 Locations', vpRenderLocationsDetail(data.locations));
            } else if (kind === 'characters') {
                vpOpenDetailModal('🧑 Characters', vpRenderCharactersDetail(data.characters));
            }
        }

        function vpRenderTimelinesDetail(timelines) {
            if (!timelines || timelines.length === 0) return '<p class="text-sm text-gray-400">Không có timeline nào.</p>';
            return timelines.map(t => {
                const profile = (t.profile || {}).value || {};
                return `
                <div class="border rounded-lg p-3">
                    <div class="font-semibold text-gray-800">${vpEscape(t.label)} <span class="text-xs text-gray-400 font-normal">(${vpEscape(t.timeline_type)}, thứ tự ${t.chronological_order})</span></div>
                    ${profile.story_time_marker ? `<div class="text-sm"><span class="font-medium text-gray-700">Mốc thời gian:</span> <span class="text-gray-600">${vpEscape(profile.story_time_marker)}</span></div>` : ''}
                    ${profile.description ? `<div class="text-sm"><span class="font-medium text-gray-700">Mô tả:</span> <span class="text-gray-600">${vpEscape(profile.description)}</span></div>` : ''}
                </div>`;
            }).join('');
        }

        function vpRenderLocationsDetail(locations) {
            if (!locations || locations.length === 0) return '<p class="text-sm text-gray-400">Không có location nào.</p>';
            return locations.map(l => {
                const cc = l.cultural_context || {};
                const groups = (cc.cultural_groups_present || [])
                    .map(g => vpClaimValue(g))
                    .filter(Boolean)
                    .map(v => `${v.name || ''}${v.presence ? ' (' + v.presence + ')' : ''}`)
                    .join(', ');
                return `
                <div class="border rounded-lg p-3">
                    <div class="font-semibold text-gray-800">${vpEscape(l.canonical_name)}</div>
                    ${l.aliases && l.aliases.length ? `<div class="text-xs text-gray-400 mb-1">aka: ${vpEscape(l.aliases.join(', '))}</div>` : ''}
                    ${vpClaimLine('Vùng', cc.region)}
                    ${vpClaimLine('Thời kỳ/chính thể', cc.historical_polity)}
                    ${groups ? `<div class="text-sm"><span class="font-medium text-gray-700">Nhóm văn hóa:</span> <span class="text-gray-600">${vpEscape(groups)}</span></div>` : ''}
                    ${vpClaimLine('Kiến trúc', cc.architecture)}
                    ${vpClaimLine('Trang phục', cc.clothing)}
                    ${vpClaimLine('Giao thông', cc.transportation)}
                    ${vpClaimLine('Tôn giáo', cc.religion)}
                    ${vpClaimLine('Vật dụng', cc.material_culture)}
                    ${vpClaimLine('Môi trường', cc.environment)}
                    ${vpClaimLine('Giới hạn tránh sai thời đại', cc.anachronism_constraints)}
                    ${vpClaimLine('Ghi chú hình ảnh', l.visual_notes)}
                </div>`;
            }).join('');
        }

        function vpRenderCharactersDetail(characters) {
            if (!characters || characters.length === 0) return '<p class="text-sm text-gray-400">Không có nhân vật nào.</p>';
            return characters.map(c => {
                const identity = (c.identity_anchor || {}).value || {};
                const baseline = (c.baseline_traits || {}).value || {};
                const phases = (c.phases || []).map(p => {
                    const mt = (p.mutable_traits || {}).value || {};
                    const profile = (p.profile || {}).value || {};
                    const parts = [mt.physique, mt.wardrobe, mt.emotional_state, mt.social_status, mt.injuries].filter(Boolean).join(', ');
                    return `<div class="ml-3 text-xs text-gray-600 border-l-2 border-gray-200 pl-2 mt-1">
                        <span class="font-medium">${vpEscape(p.label)}</span>${profile.story_time_marker ? ' (' + vpEscape(profile.story_time_marker) + ')' : ''}${parts ? ': ' + vpEscape(parts) : ''}
                    </div>`;
                }).join('');

                const identityText = [identity.ethnicity_notes, identity.base_face, identity.defining_marks].filter(Boolean).join(', ');
                const baselineText = [baseline.physique, baseline.wardrobe, baseline.occupation, baseline.social_status].filter(Boolean).join(', ');

                return `
                <div class="border rounded-lg p-3">
                    <div class="font-semibold text-gray-800">${vpEscape(c.canonical_name)}</div>
                    ${c.aliases && c.aliases.length ? `<div class="text-xs text-gray-400 mb-1">aka: ${vpEscape(c.aliases.join(', '))}</div>` : ''}
                    ${vpClaimLine('Vai trò', c.role)}
                    ${identityText ? `<div class="text-sm"><span class="font-medium text-gray-700">Nhận diện:</span> <span class="text-gray-600">${vpEscape(identityText)}</span></div>` : ''}
                    ${baselineText ? `<div class="text-sm"><span class="font-medium text-gray-700">Đặc điểm mặc định:</span> <span class="text-gray-600">${vpEscape(baselineText)}</span></div>` : ''}
                    ${phases ? `<div class="mt-1"><span class="text-xs font-medium text-gray-500">Phases:</span>${phases}</div>` : '<div class="text-xs text-gray-400 mt-1">Không có phase (không đổi theo thời gian)</div>'}
                </div>`;
            }).join('');
        }

        async function vpContinuityAcceptIssue(issueId) {
            await vpPost(vpUrls.continuityAccept(issueId));
            await vpLoadContinuity();
        }

        async function vpContinuityRegenerateSelected() {
            const issueIds = Object.keys(vpContinuitySelected).filter(id => vpContinuitySelected[id]).map(Number);
            if (issueIds.length === 0) {
                alert('Chưa chọn issue nào để regenerate.');
                return;
            }
            await vpPost(vpUrls.continuityRegenerateSelected, { issue_ids: issueIds });
            vpContinuitySelected = {};
            await vpLoadContinuity();
            await vpLoad();
        }

        function vpContinuityToggleIssue(issueId, checked) {
            vpContinuitySelected[issueId] = checked;
        }

        const CONTINUITY_SEVERITY_LABELS = {
            error: ['bg-red-100 text-red-700', '❌ Error'],
            warning: ['bg-amber-100 text-amber-700', '⚠️ Warning'],
            needs_review: ['bg-blue-100 text-blue-700', '🔎 Needs review'],
        };

        function vpRenderContinuityPanel() {
            const el = document.getElementById('vp-continuity-panel');
            if (!el) return;

            if (!vpContinuity) {
                el.innerHTML = '<p class="text-sm text-gray-400">Đang tải continuity report...</p>';
                return;
            }

            const counts = vpContinuity.shot_counts || {};
            const issuesByScene = vpContinuity.issues_by_scene || {};
            const sceneIds = Object.keys(issuesByScene);
            const autoRegenerateCount = sceneIds.reduce((sum, sid) => sum + issuesByScene[sid].filter(i => i.recommended_action === 'auto_regenerate' && i.status === 'open').length, 0);

            let html = `
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-green-100 text-green-700">✅ Valid: ${counts.valid || 0}</span>
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-amber-100 text-amber-700">⚠️ Warning: ${counts.warning || 0}</span>
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-red-100 text-red-700">❌ Invalid: ${counts.invalid || 0}</span>
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-600">— Chưa validate: ${counts.unvalidated || 0}</span>
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100 text-purple-700">🔗 Unresolved binding: ${vpContinuity.unresolved_bindings || 0}</span>
                </div>
                <div class="flex flex-wrap gap-2 mb-3">
                    <button onclick="vpContinuityValidateAll()" class="text-sm bg-slate-700 hover:bg-slate-800 text-white font-semibold py-2 px-4 rounded-lg">🔍 Validate toàn bộ</button>
                    <button onclick="vpContinuityRevalidateStale()" class="text-sm bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-4 rounded-lg">🔄 Validate lại phần stale</button>
                    <button onclick="vpContinuityRegenerateSelected()" class="text-sm bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2 px-4 rounded-lg" ${autoRegenerateCount === 0 ? 'disabled' : ''}>
                        ♻️ Regenerate selected
                    </button>
                </div>`;

            if (sceneIds.length === 0) {
                html += '<p class="text-sm text-gray-400">Không có issue nào đang mở.</p>';
            } else {
                sceneIds.forEach(sceneId => {
                    const issues = issuesByScene[sceneId];
                    const sceneLabel = issues[0].scene ? `#${issues[0].scene.scene_index} — ${vpEscape(issues[0].scene.title)}` : `Scene #${sceneId}`;
                    html += `<div class="border rounded-lg p-3 mb-2">
                        <p class="text-sm font-semibold text-gray-700 mb-2">${sceneLabel}</p>`;
                    issues.forEach(issue => {
                        const [cls, label] = CONTINUITY_SEVERITY_LABELS[issue.severity] || CONTINUITY_SEVERITY_LABELS.warning;
                        const shotLabel = issue.shot ? `Shot #${issue.shot.shot_index}` : 'Scene-level';
                        const canRegenerate = issue.recommended_action === 'auto_regenerate' && issue.status === 'open';
                        const canAccept = issue.status === 'open' && issue.severity !== 'error';

                        html += `<div class="flex items-start gap-2 py-1.5 border-t first:border-t-0">
                            ${canRegenerate ? `<input type="checkbox" onchange="vpContinuityToggleIssue(${issue.id}, this.checked)" class="mt-1">` : '<span class="w-4"></span>'}
                            <div class="flex-1 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium ${cls}">${label}</span>
                                <span class="text-xs text-gray-500 ml-1">${shotLabel} · ${vpEscape(issue.issue_type)} · ${vpEscape(issue.recommended_action)} · ${vpEscape(issue.status)}</span>
                                <p class="text-gray-700">${vpEscape(issue.message)}</p>
                            </div>
                            ${canAccept ? `<button onclick="vpContinuityAcceptIssue(${issue.id})" class="text-xs bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-1 px-2 rounded">Accept</button>` : ''}
                        </div>`;
                    });
                    html += '</div>';
                });
            }

            el.innerHTML = html;
        }

        async function vpLoadSummary() {
            try {
                const resp = await fetch(vpUrls.summaryStatus, { headers: { Accept: 'application/json' } });
                const data = await vpJson(resp);
                vpData.summary = data.summary;
            } catch (e) {
                vpData.summary = null;
            }
        }

        // Compares old vs new scene/shot trees to decide whether the background poll can
        // patch just the shots that actually changed instead of rebuilding the whole page.
        // Any structural difference (scene added/removed/reordered, shot count changed) bails
        // out to `canPatch: false` so the caller falls back to a full vpRender() — patching
        // only ever touches shots we can prove are unchanged siblings of a changed one.
        function vpDiffScenes(oldScenes, newScenes) {
            if (!oldScenes || oldScenes.length !== newScenes.length) return { canPatch: false, changedShots: [] };
            for (let i = 0; i < newScenes.length; i++) {
                if (oldScenes[i].id !== newScenes[i].id) return { canPatch: false, changedShots: [] };
            }

            const changedShots = [];
            for (const newScene of newScenes) {
                const oldScene = oldScenes.find(s => s.id === newScene.id);
                const oldShots = (oldScene && oldScene.shots) || [];
                const newShots = newScene.shots || [];
                if (oldShots.length !== newShots.length) return { canPatch: false, changedShots: [] };

                for (const newShot of newShots) {
                    const oldShot = oldShots.find(s => s.id === newShot.id);
                    if (!oldShot || JSON.stringify(oldShot) !== JSON.stringify(newShot)) {
                        changedShots.push({ sceneId: newScene.id, shotId: newShot.id });
                    }
                }
            }
            return { canPatch: true, changedShots };
        }

        async function vpLoad() {
            let data = null;
            try {
                const resp = await fetch(vpUrls.status, { headers: { Accept: 'application/json' } });
                data = await vpJson(resp);
            } catch (e) {
                vpData.pipeline = null;
                vpData.scenes = [];
                vpRender();
                vpSchedulePoll();
                return;
            }

            const prevPipeline = vpData.pipeline;
            const prevScenes = vpData.scenes;
            const newPipeline = data.pipeline;
            const newScenes = data.scenes || [];

            // Only attempt a quiet patch when we're already showing the scene list, the
            // pipeline status hasn't changed (a status change can switch the whole view —
            // start screen / progress bar / scene list — so it always needs a full render),
            // and it's not the error-banner view (that banner's failed-chunk count isn't
            // covered by per-shot diffing, so just re-render it in full — rare anyway).
            const inSceneView = prevPipeline && ['analyzed', 'analyzed_with_errors'].includes(prevPipeline.status);
            const sameStatus = prevPipeline && newPipeline && prevPipeline.status === newPipeline.status;

            let patched = false;
            if (inSceneView && sameStatus && newPipeline.status !== 'analyzed_with_errors') {
                const diff = vpDiffScenes(prevScenes, newScenes);
                vpData.pipeline = newPipeline;
                vpData.scenes = newScenes;
                if (diff.canPatch) {
                    diff.changedShots.forEach(({ sceneId, shotId }) => vpPatchShotCard(sceneId, shotId));
                    patched = true;
                }
            }

            if (!patched) {
                vpData.pipeline = newPipeline;
                vpData.scenes = newScenes;
                vpRender();
            }

            // Continuity/story-bible panels do their own surgical innerHTML replacement (not
            // a full-page render), so refreshing them every cycle doesn't cause the flicker
            // vpRender() would — safe to always do, on both the patch and full-render paths.
            if (document.getElementById('vp-continuity-panel') || document.getElementById('vp-story-bible-panel')) {
                vpLoadContinuity();
            }

            vpSchedulePoll();
        }

        function vpSchedulePoll() {
            // Keep polling in the background even after analysis finishes, at a slower pace —
            // this is what catches shots resolved from OUTSIDE this open tab (the Chrome
            // extension sending a Storyblocks clip straight to the API), which otherwise never
            // shows up until the user manually reloads the page. Faster interval while the
            // analysis progress bar itself needs to move.
            const analyzing = vpData.pipeline && ['queued', 'analyzing'].includes(vpData.pipeline.status);
            const hasScenes = vpData.scenes && vpData.scenes.length > 0;
            const desiredInterval = analyzing ? 3000 : (hasScenes ? 8000 : null);

            if (desiredInterval && vpPollInterval !== desiredInterval) {
                if (vpPollTimer) clearInterval(vpPollTimer);
                vpPollInterval = desiredInterval;
                vpPollTimer = setInterval(vpLoad, desiredInterval);
            } else if (!desiredInterval && vpPollTimer) {
                clearInterval(vpPollTimer);
                vpPollTimer = null;
                vpPollInterval = null;
            }
        }

        function vpRender() {
            const root = document.getElementById('vp-root');

            if (!vpData.summary || !vpData.summary.clusters || !vpData.summary.clusters.length) {
                root.innerHTML = `
                    <div class="text-center py-10">
                        <p class="text-gray-600">Sách chưa có kịch bản Tóm tắt sách (Bước 3). Hãy hoàn thành tóm tắt trước khi chạy pipeline này.</p>
                    </div>`;
                return;
            }

            const status = vpData.pipeline ? vpData.pipeline.status : null;

            if (!status || status === 'idle' || status === 'failed') {
                root.innerHTML = vpRenderStart(status);
                return;
            }

            if (status === 'queued' || status === 'analyzing') {
                root.innerHTML = vpRenderAnalyzingProgress();
                return;
            }

            root.innerHTML = vpRenderScenes();
            vpRenderContinuityPanel(); // re-populate with the last-loaded data (cheap, no fetch)
        }

        function vpHeartbeatLabel() {
            const ts = vpData.pipeline && vpData.pipeline.last_heartbeat_at;
            if (!ts) return '';
            const seconds = Math.max(0, Math.round((Date.now() - new Date(ts).getTime()) / 1000));
            const label = seconds < 60 ? `${seconds}s trước` : `${Math.round(seconds / 60)} phút trước`;
            return `<span class="text-xs text-gray-400">🫀 Cập nhật lần cuối: ${label}</span>`;
        }

        function vpRenderAnalyzingProgress() {
            const stage = (vpData.pipeline && vpData.pipeline.current_stage) || 'scene_splitting';

            if (stage === 'shot_enrichment') {
                const chunks = (vpData.pipeline && vpData.pipeline.shot_chunks) || [];
                const total = chunks.length;
                const done = chunks.filter(c => c.status === 'done').length;
                const failed = chunks.filter(c => c.status === 'failed').length;
                const pct = total > 0 ? Math.round((done / total) * 100) : 0;
                return `
                    <div class="py-6">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-gray-700">🎨 Đang tạo từ khóa/prompt ảnh cho từng nhóm shot... (${done}/${total || '?'} nhóm${failed ? `, ${failed} lỗi` : ''})</p>
                            ${vpHeartbeatLabel()}
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3"><div class="bg-fuchsia-500 h-3 rounded-full transition-all" style="width:${pct}%"></div></div>
                    </div>`;
            }

            const total = vpData.pipeline.total_batches || 0;
            const done = vpData.pipeline.processed_batches || 0;
            const pct = total > 0 ? Math.round((done / total) * 100) : 0;
            return `
                <div class="py-6">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-gray-700">⏳ Đang chia kịch bản thành các cảnh... (${done}/${total || '?'} phần)</p>
                        ${vpHeartbeatLabel()}
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3"><div class="bg-purple-500 h-3 rounded-full transition-all" style="width:${pct}%"></div></div>
                </div>`;
        }

        function vpRenderStart(status) {
            const versions = (vpData.summary.versions || []);
            const options = ['<option value="">— Bản đang làm (retells hiện tại) —</option>']
                .concat(versions.map(v => `<option value="${v.id}">${vpEscape(v.label)}</option>`));

            const errorHtml = status === 'failed'
                ? `<div class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-700 text-sm mb-4">❌ ${vpEscape(vpData.pipeline.error_message || 'Có lỗi xảy ra')}</div>`
                : '';

            return `
                <div class="max-w-lg mx-auto text-center py-8">
                    ${errorHtml}
                    <p class="text-gray-600 mb-4">Chọn kịch bản nguồn (từ Tóm tắt sách) để phân tích thành các cảnh + shot minh họa.</p>
                    <select id="vp-version-select" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-4">${options.join('')}</select>
                    ${vpImageSettingsControls()}
                    <button onclick="vpStart()" id="vp-start-btn" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-6 rounded-lg">
                        🚀 Bắt đầu phân tích kịch bản
                    </button>
                </div>`;
        }

        async function vpStart() {
            const btn = document.getElementById('vp-start-btn');
            const versionId = document.getElementById('vp-version-select').value || null;
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Đang khởi tạo...'; }
            try {
                const resp = await fetch(vpUrls.start, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': vpCsrf() },
                    body: JSON.stringify({ version_id: versionId }),
                });
                await vpJson(resp);
                await vpLoad();
            } catch (e) {
                alert('Lỗi: ' + e.message);
                await vpLoad();
            }
        }

        async function vpUpdateImageStyle(style) {
            try {
                await vpPost(vpUrls.imageStyle, { image_style: style });
                if (vpData.pipeline) vpData.pipeline.image_style = style;
            } catch (e) {
                alert('Lỗi đổi phong cách ảnh: ' + e.message);
            }
            await vpLoad();
        }

        // provider switches always clear any typed model override — a model name typed for
        // one provider (e.g. a Flux slug) is meaningless, or actively wrong, for the other.
        async function vpUpdateImageProvider(provider) {
            try {
                await vpPost(vpUrls.imageProvider, { image_api_provider: provider, image_api_model: '' });
                if (vpData.pipeline) {
                    vpData.pipeline.image_api_provider = provider;
                    vpData.pipeline.image_api_model = null;
                }
            } catch (e) {
                alert('Lỗi đổi API tạo ảnh: ' + e.message);
            }
            await vpLoad();
        }

        async function vpSearchStoryblocks(sceneId, shotId, keyword) {
            try {
                await vpPost(vpUrls.activeTarget(sceneId, shotId), { keyword });
            } catch (e) {
                // Non-fatal: the extension popup just won't auto-prefill the shot if this
                // failed — still open the search tab so the keyword lookup itself isn't blocked.
            }
            window.open('https://www.storyblocks.com/video/search/' + encodeURIComponent(keyword), '_blank');
        }

        async function vpGetExtensionToken() {
            try {
                const data = await vpPost(vpUrls.extensionToken);
                window.prompt('Copy token này và dán vào phần Options của Chrome Extension (chỉ hiện 1 lần):', data.token);
            } catch (e) {
                alert('Lỗi tạo token: ' + e.message);
            }
        }

        async function vpUpdateImageModel(model) {
            const provider = (vpData.pipeline && vpData.pipeline.image_api_provider) || 'flux';
            try {
                await vpPost(vpUrls.imageProvider, { image_api_provider: provider, image_api_model: model || '' });
                if (vpData.pipeline) vpData.pipeline.image_api_model = model || null;
            } catch (e) {
                alert('Lỗi đổi model ảnh: ' + e.message);
            }
            await vpLoad();
        }

        function vpImageSettingsControls() {
            const imageStyle = (vpData.pipeline && vpData.pipeline.image_style) || 'illustration';
            const provider = (vpData.pipeline && vpData.pipeline.image_api_provider) || 'flux';
            const model = (vpData.pipeline && vpData.pipeline.image_api_model) || '';
            const modelPlaceholder = provider === 'grok' ? 'grok-imagine-image' : 'flux-2-klein-9b';

            return `
                <div class="flex flex-wrap items-center justify-center gap-2 mb-2 text-sm">
                    <label class="text-gray-600">🎨 Phong cách ảnh AI:</label>
                    <select id="vp-image-style-select" onchange="vpUpdateImageStyle(this.value)"
                        class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                        <option value="illustration" ${imageStyle === 'illustration' ? 'selected' : ''}>🖌️ Tranh minh họa (illustration)</option>
                        <option value="cinematic_realistic" ${imageStyle === 'cinematic_realistic' ? 'selected' : ''}>🎥 Cinematic realistic</option>
                    </select>
                    <label class="text-gray-600 ml-2">🔌 API:</label>
                    <select id="vp-image-provider-select"
                        onchange="vpUpdateImageProvider(this.value)"
                        class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                        <option value="flux" ${provider === 'flux' ? 'selected' : ''}>Flux (Black Forest Labs)</option>
                        <option value="grok" ${provider === 'grok' ? 'selected' : ''}>Grok (xAI)</option>
                    </select>
                    <input type="text" id="vp-image-model-input" value="${vpEscape(model)}" placeholder="${modelPlaceholder} (mặc định)"
                        onchange="vpUpdateImageModel(this.value)"
                        class="border border-gray-300 rounded-lg px-2 py-1 text-sm w-48" title="Tên model cụ thể, để trống dùng mặc định" />
                    <button type="button" onclick="vpGetExtensionToken()"
                        class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-2 py-1 rounded-lg ml-2" title="Tạo token cho Chrome Extension nhập video từ Storyblocks">
                        🧩 Lấy token cho Extension
                    </button>
                </div>
                <p class="text-xs text-gray-400 mb-4 text-center">Áp dụng cho ảnh AI tạo mới từ giờ, không đổi ảnh/API đã dùng trước đó. Bấm 🔍 trên từ khóa để tìm trên Storyblocks.</p>
                ${vpCostEstimateBlock()}`;
        }

        // Giá tra cứu từ tài liệu công khai (bfl.ai/pricing, docs.x.ai) — giá "from", có thể
        // lệch nhẹ theo độ phân giải thực tế/thời điểm. Model không có trong bảng (model tự
        // nhập) thì không ước tính được, hiển thị rõ thay vì đoán.
        const vpImagePricingUsd = {
            flux: { 'flux-2-klein-9b': 0.015, 'flux-2-pro': 0.03, 'flux-2-flex': 0.05 },
            grok: { 'grok-imagine-image': 0.02, 'grok-imagine-image-quality': 0.05 },
        };

        function vpCostEstimateBlock() {
            const provider = (vpData.pipeline && vpData.pipeline.image_api_provider) || 'flux';
            const model = (vpData.pipeline && vpData.pipeline.image_api_model)
                || (provider === 'grok' ? 'grok-imagine-image' : 'flux-2-klein-9b');
            const pricePerImage = (vpImagePricingUsd[provider] && vpImagePricingUsd[provider][model]) ?? null;
            const providerLabel = provider === 'grok' ? 'Grok (xAI)' : 'Flux (BFL)';

            const allShots = vpAllShots().filter(({ shot }) => !shot.is_avatar_segment);
            const definiteAi = allShots.filter(({ shot }) =>
                !shot.is_real_world && !['ready', 'image_ready'].includes(shot.status)).length;
            const uncertain = allShots.filter(({ shot }) =>
                shot.is_real_world && ['pending', 'analyzed', 'scored', 'failed'].includes(shot.status)).length;
            const worstCase = definiteAi + uncertain;

            const costLine = (n) => pricePerImage !== null ? `≈ $${(n * pricePerImage).toFixed(2)}` : '(chưa có giá tham khảo cho model này)';

            return `
                <div class="text-xs text-gray-500 border border-gray-200 rounded-lg p-3 mb-4 max-w-2xl mx-auto">
                    <div class="font-semibold text-gray-600 mb-1">💰 Ước tính chi phí ảnh AI — ${providerLabel} (${vpEscape(model)}${pricePerImage !== null ? `, $${pricePerImage}/ảnh` : ''})</div>
                    <div>Chắc chắn cần AI (shot hư cấu): <strong>${definiteAi}</strong> shot ${costLine(definiteAi)}</div>
                    <div>Có thể cần AI (shot có thật nhưng chưa xử lý/không tìm ra nguồn): <strong>${uncertain}</strong> shot</div>
                    <div class="mt-1">Khoảng chi phí ảnh dự kiến: <strong>${costLine(definiteAi)}</strong> đến <strong>${costLine(worstCase)}</strong> (tùy Library/stock tìm được bao nhiêu trong số ${uncertain} shot còn lại).</div>
                    <div class="text-gray-400 mt-2">⚠️ Chưa tìm được giá công khai đáng tin cậy cho video Seedance (chuyển ảnh → video) — không thể ước tính chi phí video. Nếu bạn có giá thực tế từ tài khoản BytePlus/ModelArk, cho tôi biết để tính tiếp.</div>
                </div>`;
        }

        function vpAllShots() {
            const shots = [];
            vpData.scenes.forEach(scene => (scene.shots || []).forEach(shot => shots.push({ scene, shot })));
            return shots;
        }

        function vpFailedChunkCount() {
            const chunks = (vpData.pipeline && vpData.pipeline.shot_chunks) || [];
            return chunks.filter(c => c.status === 'failed').length;
        }

        // Targeted DOM patches — used instead of vpRender() (which replaces the whole
        // #vp-root, losing scroll position and flickering) whenever we already know exactly
        // which shot changed: single shot actions, the auto-run loops, and the background
        // poll's diff-based patch path all funnel through these.
        function vpPatchShotCard(sceneId, shotId) {
            const scene = (vpData.scenes || []).find(s => s.id === sceneId);
            if (!scene) return;
            const shot = (scene.shots || []).find(sh => sh.id === shotId);
            if (!shot) return;

            const el = document.getElementById('vp-shot-' + shotId);
            if (el) {
                el.outerHTML = vpRenderShotCard(scene, shot);
            }
            vpPatchSceneBadge(scene);
            vpPatchSummaryCounts();
        }

        function vpPatchSceneBadge(scene) {
            const el = document.getElementById('vp-scene-badge-' + scene.id);
            if (!el) return;
            const shots = scene.shots || [];
            const readyCount = shots.filter(s => s.status === 'ready').length;
            el.textContent = `${readyCount}/${shots.length}`;
            el.className = `px-2 py-0.5 rounded-full font-medium ${readyCount === shots.length && shots.length ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`;
        }

        function vpPatchSummaryCounts() {
            const el = document.getElementById('vp-summary-counts');
            if (!el) return;
            const allShots = vpAllShots();
            const readyCount = allShots.filter(({ shot }) => shot.status === 'ready').length;
            el.innerHTML = `📋 <strong>${vpData.scenes.length}</strong> cảnh · <strong>${allShots.length}</strong> shot · <strong>${readyCount}</strong> đã sẵn sàng`;
        }

        function vpPatchAutoRunButtons() {
            const libBtn = document.getElementById('vp-autorun-library-btn');
            if (libBtn) {
                libBtn.disabled = vpAutoRunningLibrary || vpAutoRunningAI;
                libBtn.textContent = vpAutoRunningLibrary ? '⏳ Đang lấy từ Library...' : '📚 Tự động lấy từ Library';
            }
            const aiBtn = document.getElementById('vp-autorun-ai-btn');
            if (aiBtn) {
                aiBtn.disabled = vpAutoRunningLibrary || vpAutoRunningAI;
                aiBtn.textContent = vpAutoRunningAI ? '⏳ Đang tạo bằng AI...' : '🎨 Tự động tạo bằng AI';
            }
        }

        // Refetches full pipeline status into vpData WITHOUT calling vpRender() — the caller
        // then patches only the one shot card it knows changed, instead of paying for (and
        // visually disrupting the page with) a full re-render for a single-shot action.
        async function vpRefreshDataQuietly() {
            try {
                const resp = await fetch(vpUrls.status, { headers: { Accept: 'application/json' } });
                const data = await vpJson(resp);
                vpData.pipeline = data.pipeline;
                vpData.scenes = data.scenes || [];
                return true;
            } catch (e) {
                return false;
            }
        }

        async function vpRefreshAndPatchShot(sceneId, shotId) {
            const ok = await vpRefreshDataQuietly();
            if (ok) {
                vpPatchShotCard(sceneId, shotId);
            }
        }

        function vpRenderScenes() {
            const scenes = vpData.scenes;
            const allShots = vpAllShots();
            const readyCount = allShots.filter(({ shot }) => shot.status === 'ready').length;
            const failedChunks = vpFailedChunkCount();

            let html = '';

            if (vpData.pipeline && vpData.pipeline.status === 'analyzed_with_errors' && failedChunks > 0) {
                html += `
                    <div class="mb-3 p-3 rounded-lg bg-red-50 border border-red-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <p class="text-sm text-red-700">⚠️ ${failedChunks} nhóm shot bị lỗi khi phân tích AI và chưa có từ khóa/prompt ảnh.</p>
                        <button onclick="vpRetryFailedChunks()" id="vp-retry-chunks-btn" class="text-sm bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg">
                            🔁 Thử lại phần lỗi
                        </button>
                    </div>`;
            }

            html += `
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-3">
                    <p id="vp-summary-counts" class="text-sm text-gray-600">📋 <strong>${scenes.length}</strong> cảnh · <strong>${allShots.length}</strong> shot · <strong>${readyCount}</strong> đã sẵn sàng</p>
                    <div class="flex gap-2 flex-wrap">
                        <button onclick="vpAutoRunLibrary()" id="vp-autorun-library-btn" class="text-sm bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-4 rounded-lg" ${vpAutoRunningLibrary || vpAutoRunningAI ? 'disabled' : ''}>
                            ${vpAutoRunningLibrary ? '⏳ Đang lấy từ Library...' : '📚 Tự động lấy từ Library'}
                        </button>
                        <button onclick="vpAutoRunAI()" id="vp-autorun-ai-btn" class="text-sm bg-fuchsia-600 hover:bg-fuchsia-700 text-white font-semibold py-2 px-4 rounded-lg" ${vpAutoRunningLibrary || vpAutoRunningAI ? 'disabled' : ''}>
                            ${vpAutoRunningAI ? '⏳ Đang tạo bằng AI...' : '🎨 Tự động tạo bằng AI'}
                        </button>
                        <a href="${vpUrls.download}" class="text-sm bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 px-4 rounded-lg inline-block">
                            📦 Tải toàn bộ resources
                        </a>
                    </div>
                </div>
                ${vpImageSettingsControls()}
                <div class="mb-4 p-4 rounded-lg border bg-white">
                    <h3 class="text-sm font-bold text-gray-700 mb-2">🧠 AI Director / Story Bible</h3>
                    <div id="vp-story-bible-panel"><p class="text-sm text-gray-400">Đang tải trạng thái Story Bible...</p></div>
                </div>
                <div class="mb-4 p-4 rounded-lg border bg-white">
                    <h3 class="text-sm font-bold text-gray-700 mb-2">🧭 Continuity Report</h3>
                    <div id="vp-continuity-panel"><p class="text-sm text-gray-400">Đang tải continuity report...</p></div>
                </div>`;

            scenes.forEach(scene => {
                html += vpRenderSceneCard(scene);
            });

            return html;
        }

        async function vpRetryFailedChunks() {
            const btn = document.getElementById('vp-retry-chunks-btn');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Đang thử lại...'; }
            try {
                await vpJson(await fetch(vpUrls.retryFailedChunks, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': vpCsrf() } }));
                await vpLoad();
            } catch (e) {
                alert('Không thể thử lại: ' + e.message);
                if (btn) { btn.disabled = false; btn.textContent = '🔁 Thử lại phần lỗi'; }
            }
        }

        function vpStatusBadge(item) {
            const map = {
                pending: ['bg-gray-100 text-gray-600', 'Chưa xử lý'],
                analyzed: ['bg-gray-100 text-gray-600', 'Chưa xử lý'],
                searching: ['bg-blue-100 text-blue-700', 'Đang tìm...'],
                scored: ['bg-amber-100 text-amber-700', 'Đã chấm điểm'],
                resolving: ['bg-blue-100 text-blue-700', 'Đang xử lý...'],
                image_ready: ['bg-fuchsia-100 text-fuchsia-700', '🖼️ Đã có ảnh — chờ duyệt'],
                ready: ['bg-green-100 text-green-700', '✅ Sẵn sàng'],
                failed: ['bg-red-100 text-red-700', '❌ Lỗi'],
            };
            const [cls, label] = map[item.status] || map.pending;
            return `<span class="px-2 py-0.5 rounded-full text-xs font-medium ${cls}">${label}</span>`;
        }

        function vpPreviewThumb(path, sizeClass, cacheBust) {
            // The resolved file path is fixed per shot (same filename every regeneration —
            // see SceneAssetResolverService::generateAiImage), so without a cache-busting
            // query param the browser keeps serving the stale cached image/video after
            // "Tạo ảnh khác" overwrites the file with new bytes at the same URL.
            const url = '/storage/' + path + (cacheBust ? '?v=' + encodeURIComponent(cacheBust) : '');
            const isVideo = /\.(mp4|mov|webm)$/i.test(path);
            sizeClass = sizeClass || 'w-full max-w-sm';
            return `<button type="button" onclick="vpOpenImageModal('${vpEscape(url)}', ${isVideo})" class="block ${sizeClass}">
                ${isVideo
                    ? `<video src="${url}" class="w-full rounded-lg border pointer-events-none"></video>`
                    : `<img src="${url}" class="w-full rounded-lg border" />`}
            </button>`;
        }

        function vpRealWorldBadge(shot) {
            return shot.is_real_world
                ? `<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-sky-100 text-sky-700">🌍 Có thật</span>`
                : `<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-fuchsia-100 text-fuchsia-700">🎨 Hư cấu (AI)</span>`;
        }

        function vpRenderSceneCard(scene) {
            const expanded = !!vpExpandedScenes[scene.id];
            const typeLabel = SCENE_TYPE_LABELS[scene.scene_type] || scene.scene_type;
            const minutes = Math.round(scene.estimated_duration_seconds / 60 * 10) / 10;
            const shots = scene.shots || [];
            const readyCount = shots.filter(s => s.status === 'ready').length;
            const avatarCount = shots.filter(s => s.is_avatar_segment).length;

            let body = '';
            if (expanded) {
                body = `<div class="px-4 py-3 border-t border-gray-100 space-y-2">`;
                shots.forEach(shot => {
                    body += vpRenderShotCard(scene, shot);
                });
                body += `</div>`;
            }

            return `
                <div id="vp-scene-${scene.id}" class="border border-gray-200 rounded-lg mb-3 overflow-hidden">
                    <button type="button" onclick="vpToggleScene(${scene.id})" class="w-full text-left px-4 py-3 bg-gray-50 hover:bg-gray-100 flex justify-between items-center gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="text-xs text-gray-400 flex-shrink-0">#${scene.scene_index}</span>
                            <span class="font-medium text-gray-800 truncate">${vpEscape(scene.title)}</span>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 text-xs text-gray-500">
                            <span>${typeLabel}</span>
                            <span>~${minutes} phút · ${shots.length} shot${avatarCount ? ' · 🎙️' + avatarCount : ''}</span>
                            <span id="vp-scene-badge-${scene.id}" class="px-2 py-0.5 rounded-full font-medium ${readyCount === shots.length && shots.length ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}">${readyCount}/${shots.length}</span>
                        </div>
                    </button>
                    ${body}
                </div>`;
        }

        function vpRenderShotCard(scene, shot) {
            const expanded = !!vpExpandedShots[shot.id];

            let body = '';
            if (expanded) {
                body = `<div class="px-3 py-2 border-t border-gray-100 space-y-2">`;
                body += `<div class="text-sm text-gray-700">${vpEscape(shot.sentence_text)}</div>`;

                if (shot.keywords && shot.keywords.length) {
                    body += `<div class="flex flex-wrap gap-1">${shot.keywords.map(k => `<button type="button" onclick="vpSearchStoryblocks(${scene.id}, ${shot.id}, '${vpEscape(k).replace(/'/g, "\\'")}')"
                        class="text-xs bg-gray-100 hover:bg-blue-100 text-gray-600 hover:text-blue-700 px-2 py-0.5 rounded-full cursor-pointer" title="Tìm trên Storyblocks">
                        🔍 ${vpEscape(k)}
                    </button>`).join('')}</div>`;
                }

                if (shot.error_message) {
                    body += `<div class="p-2 bg-red-50 border border-red-300 rounded text-red-700 text-xs">❌ ${vpEscape(shot.error_message)}</div>`;
                }

                if (shot.is_avatar_segment) {
                    body += vpRenderAvatarSection(scene, shot);
                } else {
                    body += vpRenderCandidatesSection(scene, shot);
                }

                const path = shot.avatar_video_path || (shot.status !== 'image_ready' ? shot.resolved_asset_path : null);
                if (path) {
                    body += `<div class="mt-1">${vpPreviewThumb(path, null, shot.updated_at)}</div>`;
                }

                body += `</div>`;
            }

            return `
                <div id="vp-shot-${shot.id}" class="border border-gray-100 rounded-lg overflow-hidden">
                    <button type="button" onclick="vpToggleShot(${shot.id})" class="w-full text-left px-3 py-2 bg-white hover:bg-gray-50 flex justify-between items-center gap-3">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-xs text-gray-400 flex-shrink-0">#${shot.shot_index}</span>
                            ${shot.is_avatar_segment ? '<span class="text-xs">🎙️</span>' : ''}
                            <span class="text-sm text-gray-700 truncate">${vpEscape(shot.sentence_text.slice(0, 70))}${shot.sentence_text.length > 70 ? '…' : ''}</span>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 text-xs text-gray-400">
                            ${!shot.is_avatar_segment ? vpRealWorldBadge(shot) : ''}
                            <span>~${shot.estimated_duration_seconds}s</span>
                            ${vpStatusBadge(shot)}
                        </div>
                    </button>
                    ${body}
                </div>`;
        }

        function vpRenderAvatarSection(scene, shot) {
            if (shot.status === 'ready') {
                return `<p class="text-sm text-green-700">✅ Đã tạo clip avatar.</p>`;
            }
            const busy = vpBusyShots[shot.id] || (shot.status === 'resolving' ? 'avatar' : null);
            return `
                <button onclick="vpGenerateAvatar(${scene.id}, ${shot.id})" ${busy ? 'disabled' : ''}
                    class="text-sm bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                    ${busy ? '⏳ Đang tạo avatar (HeyGen)...' : '🎙️ Tạo Avatar (HeyGen)'}
                </button>`;
        }

        function vpRenderCandidatesSection(scene, shot) {
            let html = '';
            const busy = vpBusyShots[shot.id]
                || (shot.status === 'searching' ? 'search' : (shot.status === 'resolving' ? 'resolve' : null));

            // AI đã tạo ảnh preview (Flux hoặc Grok, tùy lựa chọn) — chờ người dùng duyệt
            // trước khi tốn phí chuyển thành video (Kling/Seedance), theo đúng yêu cầu
            // "không mặc định tạo video".
            if (shot.status === 'image_ready' && shot.resolved_asset_path) {
                html += `<div class="max-w-xs">${vpPreviewThumb(shot.resolved_asset_path, 'w-full', shot.updated_at)}</div>`;
                html += `<div class="flex gap-2 pt-2">
                    <button onclick="vpAnimateShot(${scene.id}, ${shot.id})" ${busy === 'animate' ? 'disabled' : ''}
                        class="text-xs bg-teal-600 hover:bg-teal-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                        ${busy === 'animate' ? '⏳ Đang tạo video (Kling/Seedance)...' : '🎬 Chuyển thành video AI'}
                    </button>
                    <button onclick="vpResolveShot(${scene.id}, ${shot.id})" ${busy ? 'disabled' : ''}
                        class="text-xs bg-gray-100 hover:bg-gray-200 disabled:opacity-50 text-gray-700 font-semibold py-1.5 px-3 rounded-lg">
                        ${busy === 'resolve' ? '⏳ Đang tạo lại...' : '🔄 Tạo ảnh khác'}
                    </button>
                </div>`;
                return html;
            }

            // "Scene này có thật ngoài đời không?" — nếu KHÔNG, bỏ hẳn bước tìm thư viện,
            // chỉ còn 1 nút tạo ảnh AI để xem trước.
            if (!shot.is_real_world) {
                const providerLabel = ((vpData.pipeline && vpData.pipeline.image_api_provider) || 'flux') === 'grok' ? 'Grok' : 'Flux';
                html += `<div class="flex gap-2 pt-1">
                    <button onclick="vpResolveShot(${scene.id}, ${shot.id})" ${busy ? 'disabled' : ''}
                        class="text-xs bg-fuchsia-600 hover:bg-fuchsia-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                        ${busy === 'resolve' ? '⏳ Đang tạo ảnh...' : `🎨 Tạo ảnh bằng AI (${providerLabel})`}
                    </button>
                </div>`;
                return html;
            }

            if (shot.candidates && shot.candidates.length) {
                html += `<div class="grid grid-cols-3 sm:grid-cols-4 gap-2">`;
                shot.candidates.forEach(c => {
                    const border = c.is_selected ? 'border-teal-500 ring-2 ring-teal-200' : 'border-gray-200';
                    const thumbUrl = vpEscape(c.thumbnail_url || '');
                    html += `
                        <button type="button"
                            onclick="vpOpenImageModal('${thumbUrl}', false, () => vpSelectCandidate(${scene.id}, ${shot.id}, ${c.id}), '✅ Chọn candidate này')"
                            class="text-left border ${border} rounded-lg overflow-hidden hover:border-teal-400">
                            <img src="${thumbUrl}" class="w-full h-16 object-cover bg-gray-100" loading="lazy" />
                            <div class="p-1">
                                <div class="text-[10px] text-gray-500">${c.source}</div>
                                <div class="text-xs font-semibold ${c.score_final >= 75 ? 'text-green-600' : 'text-amber-600'}">${c.score_final}</div>
                            </div>
                        </button>`;
                });
                html += `</div>`;
            }

            html += `<div class="flex gap-2 pt-1">
                <button onclick="vpSearchShot(${scene.id}, ${shot.id})" ${busy ? 'disabled' : ''}
                    class="text-xs bg-gray-100 hover:bg-gray-200 disabled:opacity-50 text-gray-700 font-semibold py-1.5 px-3 rounded-lg">
                    ${busy === 'search' ? '⏳ Đang tìm & chấm điểm...' : '🔍 Tìm nguồn'}
                </button>`;

            if (shot.candidates && shot.candidates.length) {
                html += `<button onclick="vpResolveShot(${scene.id}, ${shot.id})" ${busy ? 'disabled' : ''}
                    class="text-xs bg-teal-600 hover:bg-teal-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                    ${busy === 'resolve' ? '⏳ Đang xử lý...' : '✅ Resolve'}
                </button>
                <button onclick="vpResolveShot(${scene.id}, ${shot.id}, true)" ${busy ? 'disabled' : ''}
                    class="text-xs bg-fuchsia-50 hover:bg-fuchsia-100 disabled:opacity-50 text-fuchsia-700 border border-fuchsia-200 font-semibold py-1.5 px-3 rounded-lg">
                    ${busy === 'resolve' ? '⏳...' : '🎨 Không ưng, tạo AI thay thế'}
                </button>`;
            }

            html += `</div>`;
            return html;
        }

        function vpToggleScene(sceneId) {
            vpExpandedScenes[sceneId] = !vpExpandedScenes[sceneId];
            vpRender();
        }

        function vpToggleShot(shotId) {
            vpExpandedShots[shotId] = !vpExpandedShots[shotId];
            vpRender();
        }

        async function vpSearchShot(sceneId, shotId) {
            vpBusyShots[shotId] = 'search';
            vpPatchShotCard(sceneId, shotId);
            try {
                await vpPost(vpUrls.search(sceneId, shotId));
            } catch (e) {
                alert('Lỗi tìm nguồn: ' + e.message);
            }
            delete vpBusyShots[shotId];
            await vpRefreshAndPatchShot(sceneId, shotId);
        }

        async function vpResolveShot(sceneId, shotId, forceAi) {
            vpBusyShots[shotId] = 'resolve';
            vpPatchShotCard(sceneId, shotId);
            try {
                await vpPost(vpUrls.resolve(sceneId, shotId), forceAi ? { force_ai: true } : null);
            } catch (e) {
                alert('Lỗi resolve: ' + e.message);
            }
            delete vpBusyShots[shotId];
            await vpRefreshAndPatchShot(sceneId, shotId);
        }

        async function vpAnimateShot(sceneId, shotId) {
            vpBusyShots[shotId] = 'animate';
            vpPatchShotCard(sceneId, shotId);
            try {
                await vpPost(vpUrls.animate(sceneId, shotId));
            } catch (e) {
                alert('Lỗi chuyển thành video: ' + e.message);
            }
            delete vpBusyShots[shotId];
            await vpRefreshAndPatchShot(sceneId, shotId);
        }

        async function vpGenerateAvatar(sceneId, shotId) {
            vpBusyShots[shotId] = 'avatar';
            vpPatchShotCard(sceneId, shotId);
            try {
                await vpPost(vpUrls.avatar(sceneId, shotId));
            } catch (e) {
                alert('Lỗi tạo avatar: ' + e.message);
            }
            delete vpBusyShots[shotId];
            await vpRefreshAndPatchShot(sceneId, shotId);
        }

        async function vpSelectCandidate(sceneId, shotId, candidateId) {
            vpBusyShots[shotId] = 'select';
            vpPatchShotCard(sceneId, shotId);
            try {
                await vpPost(vpUrls.select(sceneId, shotId, candidateId));
            } catch (e) {
                alert('Lỗi: ' + e.message);
            }
            delete vpBusyShots[shotId];
            await vpRefreshAndPatchShot(sceneId, shotId);
        }

        // Lấy từ Library — với từng shot có thật ngoài đời (bỏ qua avatar): tìm thư viện/stock
        // (searchShot() đã tự kiểm tra thư viện trước), chỉ TẢI VỀ (resolve) khi điểm đạt
        // ngưỡng (>=75). Điểm không đạt thì để nguyên trạng thái 'scored' — KHÔNG rơi xuống AI
        // ở đây, việc đó dành cho nút "Tự động tạo bằng AI" riêng.
        async function vpAutoRunLibrary() {
            vpAutoRunningLibrary = true;
            vpPatchAutoRunButtons();

            while (vpAutoRunningLibrary) {
                const next = vpAllShots().find(({ shot }) =>
                    !shot.is_avatar_segment && shot.is_real_world && ['analyzed', 'pending'].includes(shot.status));
                if (!next) break;

                const { scene, shot } = next;
                vpBusyShots[shot.id] = 'search';
                vpPatchShotCard(scene.id, shot.id);

                try {
                    const result = await vpPost(vpUrls.search(scene.id, shot.id));
                    if (result.mode !== 'library' && result.meets_threshold) {
                        vpBusyShots[shot.id] = 'resolve';
                        vpPatchShotCard(scene.id, shot.id);
                        await vpPost(vpUrls.resolve(scene.id, shot.id));
                    }
                } catch (e) {
                    alert('Lỗi khi lấy từ Library cho shot #' + shot.shot_index + ' (cảnh #' + scene.scene_index + '): ' + e.message);
                    delete vpBusyShots[shot.id];
                    break;
                }

                delete vpBusyShots[shot.id];
                await vpRefreshAndPatchShot(scene.id, shot.id);
            }

            vpAutoRunningLibrary = false;
            vpPatchAutoRunButtons();
        }

        // Tạo bằng AI — quét mọi shot chưa có kết quả (bỏ qua avatar), kể cả shot hư cấu chưa
        // từng tìm, hoặc shot có thật nhưng Library/stock không đạt điểm — ép tạo ảnh AI thẳng
        // (force_ai) bất kể điểm candidate hiện có, không đụng vào shot đã 'ready'/'image_ready'.
        async function vpAutoRunAI() {
            vpAutoRunningAI = true;
            vpPatchAutoRunButtons();

            while (vpAutoRunningAI) {
                const next = vpAllShots().find(({ shot }) =>
                    !shot.is_avatar_segment && !['ready', 'image_ready'].includes(shot.status));
                if (!next) break;

                const { scene, shot } = next;
                vpBusyShots[shot.id] = 'resolve';
                vpPatchShotCard(scene.id, shot.id);

                try {
                    await vpPost(vpUrls.resolve(scene.id, shot.id), { force_ai: true });
                } catch (e) {
                    alert('Lỗi khi tạo AI cho shot #' + shot.shot_index + ' (cảnh #' + scene.scene_index + '): ' + e.message);
                    delete vpBusyShots[shot.id];
                    break;
                }

                delete vpBusyShots[shot.id];
                await vpRefreshAndPatchShot(scene.id, shot.id);
            }

            vpAutoRunningAI = false;
            vpPatchAutoRunButtons();
        }

        vpInit();
    </script>
@endsection
