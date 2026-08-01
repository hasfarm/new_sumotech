@extends('layouts.app')

@section('content')
    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6 px-4 sm:px-0">
                <div>
                    <h2 class="font-semibold text-xl sm:text-2xl text-gray-800">🎬 Dây Chuyền Sản Xuất Video Bằng AI</h2>
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
        $vpApproveUrlTemplate = route('audiobooks.video.pipeline.shots.approve', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpImageRequestUrlTemplate = route('audiobooks.video.pipeline.shots.image-request', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpAvatarUrlTemplate = route('audiobooks.video.pipeline.shots.avatar', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpAvatarTtsUrlTemplate = route('audiobooks.video.pipeline.shots.avatar-tts', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpAvatarImageSelectUrlTemplate = route('audiobooks.video.pipeline.shots.avatar-image.select', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpAvatarImageUploadUrlTemplate = route('audiobooks.video.pipeline.shots.avatar-image.upload', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpSelectUrlTemplate = route('audiobooks.video.pipeline.shots.select', [$audioBook, '__SCENE__', '__SHOT__', '__CID__']);
        $vpActiveTargetUrlTemplate = route('audiobooks.video.pipeline.shots.active-target', [$audioBook, '__SCENE__', '__SHOT__']);

        // Audio Direction Pipeline (Phase 4) — scene ambience/music baseline slots.
        $vpSceneAudioCandidatesTemplate = route('audiobooks.video.pipeline.scenes.audio.candidates', [$audioBook, '__SCENE__', '__SLOT__']);
        $vpSceneAudioSelectTemplate = route('audiobooks.video.pipeline.scenes.audio.select', [$audioBook, '__SCENE__', '__SLOT__']);
        $vpSceneAudioGenerateTemplate = route('audiobooks.video.pipeline.scenes.audio.generate', [$audioBook, '__SCENE__', '__SLOT__']);
        $vpSceneAudioRejectTemplate = route('audiobooks.video.pipeline.scenes.audio.reject', [$audioBook, '__SCENE__', '__SLOT__']);
        $vpSceneAudioApproveTemplate = route('audiobooks.video.pipeline.scenes.audio.approve', [$audioBook, '__SCENE__', '__SLOT__']);
        $vpSceneAudioLockTemplate = route('audiobooks.video.pipeline.scenes.audio.lock', [$audioBook, '__SCENE__', '__SLOT__']);
        $vpSceneAudioUnlockTemplate = route('audiobooks.video.pipeline.scenes.audio.unlock', [$audioBook, '__SCENE__', '__SLOT__']);
        $vpSceneAudioActiveTargetTemplate = route('audiobooks.video.pipeline.scenes.audio.active-target', [$audioBook, '__SCENE__', '__SLOT__']);

        // Audio Direction Pipeline — shot sfx / ambience-override / music-override slots.
        $vpShotAudioCandidatesTemplate = route('audiobooks.video.pipeline.shots.audio.candidates', [$audioBook, '__SCENE__', '__SHOT__', '__SLOT__']);
        $vpShotAudioSelectTemplate = route('audiobooks.video.pipeline.shots.audio.select', [$audioBook, '__SCENE__', '__SHOT__', '__SLOT__']);
        $vpShotAudioGenerateTemplate = route('audiobooks.video.pipeline.shots.audio.generate', [$audioBook, '__SCENE__', '__SHOT__', '__SLOT__']);
        $vpShotAudioRejectTemplate = route('audiobooks.video.pipeline.shots.audio.reject', [$audioBook, '__SCENE__', '__SHOT__', '__SLOT__']);
        $vpShotAudioApproveTemplate = route('audiobooks.video.pipeline.shots.audio.approve', [$audioBook, '__SCENE__', '__SHOT__', '__SLOT__']);
        $vpShotAudioLockTemplate = route('audiobooks.video.pipeline.shots.audio.lock', [$audioBook, '__SCENE__', '__SHOT__', '__SLOT__']);
        $vpShotAudioUnlockTemplate = route('audiobooks.video.pipeline.shots.audio.unlock', [$audioBook, '__SCENE__', '__SHOT__', '__SLOT__']);
        $vpShotAudioActiveTargetTemplate = route('audiobooks.video.pipeline.shots.audio.active-target', [$audioBook, '__SCENE__', '__SHOT__', '__SLOT__']);

        // Motion & Transition Direction — per-shot, 2 fixed slots (motion, transition), no
        // __SLOT__ wildcard since each slot has its own route (mirrors how sfx/ambience/music
        // share one wildcard route, but motion/transition don't need that generality).
        $vpShotMotionGenerateTemplate = route('audiobooks.video.pipeline.shots.motion.generate', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpShotMotionRegenerateTemplate = route('audiobooks.video.pipeline.shots.motion.regenerate', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpShotMotionRejectTemplate = route('audiobooks.video.pipeline.shots.motion.reject', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpShotMotionApproveTemplate = route('audiobooks.video.pipeline.shots.motion.approve', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpShotMotionLockTemplate = route('audiobooks.video.pipeline.shots.motion.lock', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpShotMotionUnlockTemplate = route('audiobooks.video.pipeline.shots.motion.unlock', [$audioBook, '__SCENE__', '__SHOT__']);

        $vpShotTransitionGenerateTemplate = route('audiobooks.video.pipeline.shots.transition.generate', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpShotTransitionRegenerateTemplate = route('audiobooks.video.pipeline.shots.transition.regenerate', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpShotTransitionRejectTemplate = route('audiobooks.video.pipeline.shots.transition.reject', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpShotTransitionApproveTemplate = route('audiobooks.video.pipeline.shots.transition.approve', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpShotTransitionLockTemplate = route('audiobooks.video.pipeline.shots.transition.lock', [$audioBook, '__SCENE__', '__SHOT__']);
        $vpShotTransitionUnlockTemplate = route('audiobooks.video.pipeline.shots.transition.unlock', [$audioBook, '__SCENE__', '__SHOT__']);
    @endphp
    <script>
        const vpUrls = {
            status: @json(route('audiobooks.video.pipeline.status', $audioBook)),
            start: @json(route('audiobooks.video.pipeline.start', $audioBook)),
            retryFailedChunks: @json(route('audiobooks.video.pipeline.retry-failed-chunks', $audioBook)),
            bulkGenerateAi: @json(route('audiobooks.video.pipeline.bulk-generate-ai', $audioBook)),
            imageStyle: @json(route('audiobooks.video.pipeline.image-style', $audioBook)),
            imageProvider: @json(route('audiobooks.video.pipeline.image-provider', $audioBook)),
            ttsSettings: @json(route('audiobooks.video.pipeline.tts-settings', $audioBook)),
            avatarTtsSettings: @json(route('audiobooks.video.pipeline.avatar-tts-settings', $audioBook)),
            bulkNarrationTts: @json(route('audiobooks.video.pipeline.bulk-generate-narration-tts', $audioBook)),
            bulkAvatarTts: @json(route('audiobooks.video.pipeline.bulk-generate-avatar-tts', $audioBook)),
            bulkGenerateAudio: @json(route('audiobooks.video.pipeline.bulk-generate-audio', $audioBook)),
            getAvailableVoices: @json(route('get.available.voices')),
            previewVoice: @json(route('preview.voice')),
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
            storyBibleGenerate: @json(route('audiobooks.video.pipeline.story-bible.generate', $audioBook)),
            searchTemplate: @json($vpSearchUrlTemplate),
            resolveTemplate: @json($vpResolveUrlTemplate),
            animateTemplate: @json($vpAnimateUrlTemplate),
            approveTemplate: @json($vpApproveUrlTemplate),
            imageRequestTemplate: @json($vpImageRequestUrlTemplate),
            avatarTemplate: @json($vpAvatarUrlTemplate),
            avatarTtsTemplate: @json($vpAvatarTtsUrlTemplate),
            avatarImageSelectTemplate: @json($vpAvatarImageSelectUrlTemplate),
            avatarImageUploadTemplate: @json($vpAvatarImageUploadUrlTemplate),
            avatarLibrary: @json(route('audiobooks.video.pipeline.avatar-library', $audioBook)),
            selectTemplate: @json($vpSelectUrlTemplate),
            activeTargetTemplate: @json($vpActiveTargetUrlTemplate),
            extensionToken: @json(route('audiobooks.video-pipeline-extension-token')),
            search(sid, hid) { return this.searchTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            resolve(sid, hid) { return this.resolveTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            animate(sid, hid) { return this.animateTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            approve(sid, hid) { return this.approveTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            imageRequest(sid, hid) { return this.imageRequestTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            avatar(sid, hid) { return this.avatarTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            avatarTts(sid, hid) { return this.avatarTtsTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            avatarImageSelect(sid, hid) { return this.avatarImageSelectTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            avatarImageUpload(sid, hid) { return this.avatarImageUploadTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            select(sid, hid, cid) { return this.selectTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid).replace('__CID__', cid); },
            activeTarget(sid, hid) { return this.activeTargetTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },

            sceneAudioCandidatesTemplate: @json($vpSceneAudioCandidatesTemplate),
            sceneAudioSelectTemplate: @json($vpSceneAudioSelectTemplate),
            sceneAudioGenerateTemplate: @json($vpSceneAudioGenerateTemplate),
            sceneAudioRejectTemplate: @json($vpSceneAudioRejectTemplate),
            sceneAudioApproveTemplate: @json($vpSceneAudioApproveTemplate),
            sceneAudioLockTemplate: @json($vpSceneAudioLockTemplate),
            sceneAudioUnlockTemplate: @json($vpSceneAudioUnlockTemplate),
            sceneAudioActiveTargetTemplate: @json($vpSceneAudioActiveTargetTemplate),
            sceneAudioCandidates(sid, slot) { return this.sceneAudioCandidatesTemplate.replace('__SCENE__', sid).replace('__SLOT__', slot); },
            sceneAudioSelect(sid, slot) { return this.sceneAudioSelectTemplate.replace('__SCENE__', sid).replace('__SLOT__', slot); },
            sceneAudioGenerate(sid, slot) { return this.sceneAudioGenerateTemplate.replace('__SCENE__', sid).replace('__SLOT__', slot); },
            sceneAudioReject(sid, slot) { return this.sceneAudioRejectTemplate.replace('__SCENE__', sid).replace('__SLOT__', slot); },
            sceneAudioApprove(sid, slot) { return this.sceneAudioApproveTemplate.replace('__SCENE__', sid).replace('__SLOT__', slot); },
            sceneAudioLock(sid, slot) { return this.sceneAudioLockTemplate.replace('__SCENE__', sid).replace('__SLOT__', slot); },
            sceneAudioUnlock(sid, slot) { return this.sceneAudioUnlockTemplate.replace('__SCENE__', sid).replace('__SLOT__', slot); },
            sceneAudioActiveTarget(sid, slot) { return this.sceneAudioActiveTargetTemplate.replace('__SCENE__', sid).replace('__SLOT__', slot); },

            shotAudioCandidatesTemplate: @json($vpShotAudioCandidatesTemplate),
            shotAudioSelectTemplate: @json($vpShotAudioSelectTemplate),
            shotAudioGenerateTemplate: @json($vpShotAudioGenerateTemplate),
            shotAudioRejectTemplate: @json($vpShotAudioRejectTemplate),
            shotAudioApproveTemplate: @json($vpShotAudioApproveTemplate),
            shotAudioLockTemplate: @json($vpShotAudioLockTemplate),
            shotAudioUnlockTemplate: @json($vpShotAudioUnlockTemplate),
            shotAudioActiveTargetTemplate: @json($vpShotAudioActiveTargetTemplate),
            shotAudioCandidates(sid, hid, slot) { return this.shotAudioCandidatesTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid).replace('__SLOT__', slot); },
            shotAudioSelect(sid, hid, slot) { return this.shotAudioSelectTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid).replace('__SLOT__', slot); },
            shotAudioGenerate(sid, hid, slot) { return this.shotAudioGenerateTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid).replace('__SLOT__', slot); },
            shotAudioReject(sid, hid, slot) { return this.shotAudioRejectTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid).replace('__SLOT__', slot); },
            shotAudioApprove(sid, hid, slot) { return this.shotAudioApproveTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid).replace('__SLOT__', slot); },
            shotAudioLock(sid, hid, slot) { return this.shotAudioLockTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid).replace('__SLOT__', slot); },
            shotAudioUnlock(sid, hid, slot) { return this.shotAudioUnlockTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid).replace('__SLOT__', slot); },
            shotAudioActiveTarget(sid, hid, slot) { return this.shotAudioActiveTargetTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid).replace('__SLOT__', slot); },

            shotMotionGenerateTemplate: @json($vpShotMotionGenerateTemplate),
            shotMotionRegenerateTemplate: @json($vpShotMotionRegenerateTemplate),
            shotMotionRejectTemplate: @json($vpShotMotionRejectTemplate),
            shotMotionApproveTemplate: @json($vpShotMotionApproveTemplate),
            shotMotionLockTemplate: @json($vpShotMotionLockTemplate),
            shotMotionUnlockTemplate: @json($vpShotMotionUnlockTemplate),
            shotMotionGenerate(sid, hid) { return this.shotMotionGenerateTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            shotMotionRegenerate(sid, hid) { return this.shotMotionRegenerateTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            shotMotionReject(sid, hid) { return this.shotMotionRejectTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            shotMotionApprove(sid, hid) { return this.shotMotionApproveTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            shotMotionLock(sid, hid) { return this.shotMotionLockTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            shotMotionUnlock(sid, hid) { return this.shotMotionUnlockTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },

            shotTransitionGenerateTemplate: @json($vpShotTransitionGenerateTemplate),
            shotTransitionRegenerateTemplate: @json($vpShotTransitionRegenerateTemplate),
            shotTransitionRejectTemplate: @json($vpShotTransitionRejectTemplate),
            shotTransitionApproveTemplate: @json($vpShotTransitionApproveTemplate),
            shotTransitionLockTemplate: @json($vpShotTransitionLockTemplate),
            shotTransitionUnlockTemplate: @json($vpShotTransitionUnlockTemplate),
            shotTransitionGenerate(sid, hid) { return this.shotTransitionGenerateTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            shotTransitionRegenerate(sid, hid) { return this.shotTransitionRegenerateTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            shotTransitionReject(sid, hid) { return this.shotTransitionRejectTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            shotTransitionApprove(sid, hid) { return this.shotTransitionApproveTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            shotTransitionLock(sid, hid) { return this.shotTransitionLockTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
            shotTransitionUnlock(sid, hid) { return this.shotTransitionUnlockTemplate.replace('__SCENE__', sid).replace('__SHOT__', hid); },
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

        let vpData = { summary: null, pipeline: null, scenes: [], speakerAvatarUrl: null };
        let vpPollTimer = null;
        let vpPollInterval = null;
        let vpAutoRunningLibrary = false;
        let vpExpandedScenes = {};
        let vpExpandedShots = {};
        // Per-shot override: force the candidates grid open for an already-'ready' shot
        // (collapsed by default there — see vpRenderCandidatesSection()) so reviewing/
        // switching a finished shot's source is still one click away.
        let vpExpandedCandidates = {};
        // Collapsed by default (same convention as scenes/shots) — these two status panels
        // sit side by side to save vertical/horizontal space; expand on demand.
        let vpStoryBiblePanelExpanded = false;
        let vpContinuityPanelExpanded = false;
        let vpBusyShots = {};
        // Audio Direction Pipeline (Phase 4) client state — keyed by a slot key (see
        // vpAudioKey()), NOT by scene/shot id alone, since a shot has up to 3 independent slots
        // (sfx/ambience/music) each with their own candidate list / busy flag.
        let vpAudioExpanded = {};   // slotKey -> bool, candidate list currently shown
        let vpAudioCandidates = {}; // slotKey -> last-fetched candidate array
        let vpAudioBusy = {};       // slotKey -> action name currently in flight, for disabling buttons
        // Motion & Transition Direction client state — keyed by 'motion_{shotId}' / 'transition_{shotId}'.
        let vpMotionBusy = {};
        let vpContinuity = null; // { shot_counts, unresolved_bindings, issues_by_scene, story_bible }
        let vpContinuitySelected = {};
        let vpGeneratingStoryBible = false;

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
            draft: ['bg-blue-100 text-blue-700', '⏳ Đang tạo (bản nháp)'],
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
                    <p class="text-sm text-gray-600 mb-2">Chưa có Bộ Bối Cảnh Chuẩn nào được kích hoạt cho sách này — các cảnh/phân đoạn hiện tại KHÔNG có bối cảnh từ Đạo Diễn AI (giai đoạn nhân vật, văn hóa địa điểm, định hướng đạo diễn).</p>
                    <div class="flex flex-wrap items-center gap-2">
                        ${sb.latest_version ? `<span class="px-2 py-1 rounded-lg text-xs font-semibold ${cls}">${label} — v${sb.latest_version}</span>` : ''}
                        <button type="button" onclick="vpGenerateStoryBible()" id="vp-generate-bible-btn" ${vpGeneratingStoryBible ? 'disabled' : ''}
                            class="text-xs bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                            ${vpGeneratingStoryBible ? '⏳ Đang khởi động...' : '🧠 Tạo Bộ Bối Cảnh Chuẩn'}
                        </button>
                    </div>
                    ${sb.latest_error ? `<p class="text-xs text-red-600 mt-2">${vpEscape(sb.latest_error)}</p>` : ''}
                `;
                return;
            }

            const [cls, label] = STORY_BIBLE_STATUS_LABELS[sb.active_status] || STORY_BIBLE_STATUS_LABELS.active;
            el.innerHTML = `
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold ${cls}">${label} — v${sb.active_version}</span>
                    <button type="button" onclick="vpShowStoryBibleDetail('timelines')" class="px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-600 hover:bg-gray-200 cursor-pointer">🕐 Mốc thời gian: ${sb.timelines_count}</button>
                    <button type="button" onclick="vpShowStoryBibleDetail('locations')" class="px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-600 hover:bg-gray-200 cursor-pointer">📍 Địa điểm: ${sb.locations_count}</button>
                    <button type="button" onclick="vpShowStoryBibleDetail('characters')" class="px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-600 hover:bg-gray-200 cursor-pointer">🧑 Nhân vật: ${sb.characters_count}</button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-indigo-100 text-indigo-700">🔗 Cảnh đã gán bối cảnh: ${sb.scenes_bound}/${sb.scenes_total}</span>
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold ${sb.scenes_stale > 0 ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'}">${sb.scenes_stale > 0 ? '⚠️' : '✅'} Đã lỗi thời (cần gán lại): ${sb.scenes_stale}</span>
                    ${vpRegenerateStaleButtonHtml(sb)}
                </div>
            `;
        }

        /**
         * The button reflects story_bible_regenerate_stale_status polled from the SERVER
         * (refreshed every vpLoad() tick via vpLoadContinuity()), not a local flag that gets
         * cleared the instant the dispatch request returns — RegenerateStaleSceneDirectionJob
         * makes a real OpenAI call per stale scene, which can run well past the HTTP round
         * trip, so the previous local-only flag made the button look "done" almost
         * immediately even though the job was still running in the background.
         */
        function vpRegenerateStaleButtonHtml(sb) {
            const st = sb.regenerate_stale_status;
            const running = st && st.status === 'running';

            if (running) {
                return `<button disabled id="vp-regenerate-stale-btn"
                    class="text-xs bg-amber-600 opacity-70 text-white font-semibold py-1.5 px-3 rounded-lg">
                    ⏳ Đang gán lại... (${st.processed ?? 0}/${st.total ?? '?'})
                </button>`;
            }

            if (sb.scenes_stale <= 0) return '';

            return `<button onclick="vpRegenerateStaleScenes()" id="vp-regenerate-stale-btn"
                class="text-xs bg-amber-600 hover:bg-amber-700 text-white font-semibold py-1.5 px-3 rounded-lg">
                ♻️ Gán lại cảnh lỗi thời
            </button>`;
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
        // Dispatches a queued job (AnalyzeStoryDirectionJob) — like Regenerate stale scenes,
        // this only submits the request. Needs Bước 1-3 (tóm tắt sách) already done; the job
        // itself reports an error (surfaced via sb.latest_error on next poll) if not.
        async function vpGenerateStoryBible() {
            vpGeneratingStoryBible = true;
            vpRenderStoryBiblePanel();
            try {
                await vpPost(vpUrls.storyBibleGenerate);
            } catch (e) {
                alert('Lỗi khi tạo Bộ Bối Cảnh Chuẩn: ' + e.message);
            }
            vpGeneratingStoryBible = false;
            await vpLoadContinuity();
        }

        async function vpRegenerateStaleScenes() {
            try {
                await vpPost(vpUrls.storyBibleRegenerateStale);
            } catch (e) {
                alert('Lỗi khi gán lại cảnh lỗi thời: ' + e.message);
                return;
            }
            // Real progress (processed/total) comes from the server on every subsequent
            // vpLoad() poll tick — this immediate reload just avoids waiting up to 8s to see
            // the button flip into its "⏳ Đang gán lại..." state.
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
                alert('Lỗi khi tải chi tiết Bộ Bối Cảnh Chuẩn: ' + e.message);
                return;
            }
            if (!data.success) {
                alert(data.message || 'Không tải được dữ liệu.');
                return;
            }

            if (kind === 'timelines') {
                vpOpenDetailModal('🕐 Mốc thời gian', vpRenderTimelinesDetail(data.timelines));
            } else if (kind === 'locations') {
                vpOpenDetailModal('📍 Địa điểm', vpRenderLocationsDetail(data.locations));
            } else if (kind === 'characters') {
                vpOpenDetailModal('🧑 Nhân vật', vpRenderCharactersDetail(data.characters));
            }
        }

        function vpRenderTimelinesDetail(timelines) {
            if (!timelines || timelines.length === 0) return '<p class="text-sm text-gray-400">Không có mốc thời gian nào.</p>';
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
            if (!locations || locations.length === 0) return '<p class="text-sm text-gray-400">Không có địa điểm nào.</p>';
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
                    ${l.aliases && l.aliases.length ? `<div class="text-xs text-gray-400 mb-1">còn gọi là: ${vpEscape(l.aliases.join(', '))}</div>` : ''}
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
                    ${c.aliases && c.aliases.length ? `<div class="text-xs text-gray-400 mb-1">còn gọi là: ${vpEscape(c.aliases.join(', '))}</div>` : ''}
                    ${vpClaimLine('Vai trò', c.role)}
                    ${identityText ? `<div class="text-sm"><span class="font-medium text-gray-700">Nhận diện:</span> <span class="text-gray-600">${vpEscape(identityText)}</span></div>` : ''}
                    ${baselineText ? `<div class="text-sm"><span class="font-medium text-gray-700">Đặc điểm mặc định:</span> <span class="text-gray-600">${vpEscape(baselineText)}</span></div>` : ''}
                    ${phases ? `<div class="mt-1"><span class="text-xs font-medium text-gray-500">Các giai đoạn:</span>${phases}</div>` : '<div class="text-xs text-gray-400 mt-1">Không có giai đoạn (không đổi theo thời gian)</div>'}
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
                alert('Chưa chọn vấn đề nào để tạo lại.');
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
            error: ['bg-red-100 text-red-700', '❌ Lỗi'],
            warning: ['bg-amber-100 text-amber-700', '⚠️ Cảnh báo'],
            needs_review: ['bg-blue-100 text-blue-700', '🔎 Cần xem xét'],
        };

        function vpRenderContinuityPanel() {
            const el = document.getElementById('vp-continuity-panel');
            if (!el) return;

            if (!vpContinuity) {
                el.innerHTML = '<p class="text-sm text-gray-400">Đang tải báo cáo tính nhất quán...</p>';
                return;
            }

            const counts = vpContinuity.shot_counts || {};
            const issuesByScene = vpContinuity.issues_by_scene || {};
            const sceneIds = Object.keys(issuesByScene);
            const autoRegenerateCount = sceneIds.reduce((sum, sid) => sum + issuesByScene[sid].filter(i => i.recommended_action === 'auto_regenerate' && i.status === 'open').length, 0);

            let html = `
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-green-100 text-green-700">✅ Hợp lệ: ${counts.valid || 0}</span>
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-amber-100 text-amber-700">⚠️ Cảnh báo: ${counts.warning || 0}</span>
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-red-100 text-red-700">❌ Không hợp lệ: ${counts.invalid || 0}</span>
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-600">— Chưa kiểm tra: ${counts.unvalidated || 0}</span>
                    <span class="px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100 text-purple-700">🔗 Chưa gán bối cảnh: ${vpContinuity.unresolved_bindings || 0}</span>
                </div>
                <div class="flex flex-wrap gap-2 mb-3">
                    <button onclick="vpContinuityValidateAll()" class="text-sm bg-slate-700 hover:bg-slate-800 text-white font-semibold py-2 px-4 rounded-lg">🔍 Kiểm tra toàn bộ</button>
                    <button onclick="vpContinuityRevalidateStale()" class="text-sm bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-4 rounded-lg">🔄 Kiểm tra lại phần lỗi thời</button>
                    <button onclick="vpContinuityRegenerateSelected()" class="text-sm bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2 px-4 rounded-lg" ${autoRegenerateCount === 0 ? 'disabled' : ''}>
                        ♻️ Tạo lại mục đã chọn
                    </button>
                </div>`;

            if (sceneIds.length === 0) {
                html += '<p class="text-sm text-gray-400">Không có vấn đề nào đang mở.</p>';
            } else {
                sceneIds.forEach(sceneId => {
                    const issues = issuesByScene[sceneId];
                    const sceneLabel = issues[0].scene ? `#${issues[0].scene.scene_index} — ${vpEscape(issues[0].scene.title)}` : `Cảnh #${sceneId}`;
                    html += `<div class="border rounded-lg p-3 mb-2">
                        <p class="text-sm font-semibold text-gray-700 mb-2">${sceneLabel}</p>`;
                    issues.forEach(issue => {
                        const [cls, label] = CONTINUITY_SEVERITY_LABELS[issue.severity] || CONTINUITY_SEVERITY_LABELS.warning;
                        const shotLabel = issue.shot ? `Phân đoạn #${issue.shot.shot_index}` : 'Cấp cảnh';
                        const canRegenerate = issue.recommended_action === 'auto_regenerate' && issue.status === 'open';
                        const canAccept = issue.status === 'open' && issue.severity !== 'error';

                        html += `<div class="flex items-start gap-2 py-1.5 border-t first:border-t-0">
                            ${canRegenerate ? `<input type="checkbox" onchange="vpContinuityToggleIssue(${issue.id}, this.checked)" class="mt-1">` : '<span class="w-4"></span>'}
                            <div class="flex-1 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium ${cls}">${label}</span>
                                <span class="text-xs text-gray-500 ml-1">${shotLabel} · ${vpEscape(issue.issue_type)} · ${vpEscape(issue.recommended_action)} · ${vpEscape(issue.status)}</span>
                                <p class="text-gray-700">${vpEscape(issue.message)}</p>
                            </div>
                            ${canAccept ? `<button onclick="vpContinuityAcceptIssue(${issue.id})" class="text-xs bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-1 px-2 rounded">Chấp nhận</button>` : ''}
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

            vpData.speakerAvatarUrl = data.speaker_avatar_url || null;

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

            // Bulk AI-generate progress (BulkGenerateShotImagesJob) lives on the polled
            // pipeline row, not driven by any client loop — refresh the button label/disabled
            // state every tick so "(processed/total)" moves and the button re-enables itself
            // the moment the job finishes, even on a page that was just reloaded mid-run.
            vpPatchAutoRunButtons();
            vpPatchTtsCreateAllButtons();
            vpPatchAudioBulkButton();

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
                vpTtsRefreshAllVoiceOptions(); // voice <select>s are fetched dynamically, not part of the static HTML
                return;
            }

            if (status === 'queued' || status === 'analyzing') {
                root.innerHTML = vpRenderAnalyzingProgress();
                return;
            }

            root.innerHTML = vpRenderScenes();
            vpRenderContinuityPanel(); // re-populate with the last-loaded data (cheap, no fetch)
            vpTtsRefreshAllVoiceOptions();
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
                            <p class="text-gray-700">🎨 Đang tạo từ khóa/mô tả ảnh cho từng nhóm phân đoạn... (${done}/${total || '?'} nhóm${failed ? `, ${failed} lỗi` : ''})</p>
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
                    <p class="text-gray-600 mb-4">Chọn kịch bản nguồn (từ Tóm tắt sách) để phân tích thành các cảnh + phân đoạn minh họa.</p>
                    <select id="vp-version-select" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-4">${options.join('')}</select>
                    ${vpImageSettingsControls()}
                    ${vpTtsSettingsControls()}
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
                        <option value="cinematic_realistic" ${imageStyle === 'cinematic_realistic' ? 'selected' : ''}>🎥 Chân thực điện ảnh</option>
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
                    <button type="button" onclick="vpOpenDetailModal('💰 Ước tính chi phí ảnh AI', vpCostEstimateBlock())"
                        class="text-gray-400 hover:text-gray-600 text-base leading-none" title="Xem ước tính chi phí ảnh AI">
                        ⓘ
                    </button>
                    <button type="button" onclick="vpGetExtensionToken()"
                        class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-2 py-1 rounded-lg ml-2" title="Tạo token cho Chrome Extension nhập video từ Storyblocks">
                        🧩 Lấy token cho Extension
                    </button>
                </div>
                <p class="text-xs text-gray-400 mb-4 text-center">Áp dụng cho ảnh AI tạo mới từ giờ, không đổi ảnh/API đã dùng trước đó. Bấm 🔍 trên từ khóa để tìm trên Storyblocks.</p>`;
        }

        const VP_TTS_PROVIDER_OPTIONS = [
            ['', '-- Chọn nhà cung cấp giọng đọc --'],
            ['openai', '🤖 OpenAI'],
            ['gemini', '✨ Gemini Pro'],
            ['microsoft', '🪟 Microsoft'],
            ['vbee', '🇻🇳 Vbee (Việt Nam)'],
        ];

        // Two independent voice pickers: 'main' (one voice for narration across the whole
        // work, same per-pipeline scope as image_style) and 'avatar' (deliberately separate —
        // this is the voice TTS'd then lip-synced onto the speaker's avatar photo via HeyGen,
        // see AvatarSegmentService::generateSegment(); it must never be forced to match the
        // main narration voice).
        function vpTtsPickerHtml(scope, label, hint, providerVal, genderVal) {
            const providerOptions = VP_TTS_PROVIDER_OPTIONS.map(([v, l]) =>
                `<option value="${v}" ${(providerVal || '') === v ? 'selected' : ''}>${l}</option>`).join('');

            return `
                <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <p class="text-xs font-semibold text-gray-700 mb-1">${label}</p>
                    ${hint ? `<p class="text-xs text-gray-400 mb-2">${hint}</p>` : ''}
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <select id="vp-tts-${scope}-provider" onchange="vpTtsProviderChanged('${scope}')"
                            class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            ${providerOptions}
                        </select>
                        <label class="inline-flex items-center gap-1 cursor-pointer text-gray-600">
                            <input type="radio" name="vp-tts-${scope}-gender" value="female" onchange="vpTtsGenderChanged('${scope}')" ${genderVal !== 'male' ? 'checked' : ''}>
                            <span>👩 Nữ</span>
                        </label>
                        <label class="inline-flex items-center gap-1 cursor-pointer text-gray-600">
                            <input type="radio" name="vp-tts-${scope}-gender" value="male" onchange="vpTtsGenderChanged('${scope}')" ${genderVal === 'male' ? 'checked' : ''}>
                            <span>👨 Nam</span>
                        </label>
                        <select id="vp-tts-${scope}-voice" onchange="vpTtsVoiceChanged('${scope}')"
                            class="border border-gray-300 rounded-lg px-2 py-1 text-sm flex-1 min-w-[160px]">
                            <option value="">-- Chọn nhà cung cấp trước --</option>
                        </select>
                        <button type="button" id="vp-tts-${scope}-preview-btn" onclick="vpTtsPreviewVoice('${scope}')"
                            class="px-2 py-1 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm" title="Nghe thử giọng">
                            🔊
                        </button>
                        <button type="button" id="vp-tts-${scope}-createall-btn" onclick="vpTtsCreateAll('${scope}')"
                            class="px-2 py-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-lg text-sm whitespace-nowrap"
                            title="${scope === 'avatar' ? 'Tạo giọng đọc hàng loạt cho mọi phân đoạn MC chưa có âm thanh' : 'Tạo giọng đọc hàng loạt cho mọi phân đoạn (trừ MC) chưa có âm thanh'}">
                            ${vpTtsCreateAllLabel(scope)}
                        </button>
                        ${scope === 'main' ? `
                            <button type="button" id="vp-play-all-narration-btn" onclick="vpToggleFullPlayback()"
                                class="px-2 py-1 bg-gray-700 hover:bg-gray-800 text-white rounded-lg text-sm whitespace-nowrap"
                                title="Nghe lại toàn bộ giọng đọc chính đã tạo, theo đúng thứ tự trong sách">
                                ${vpFullPlaybackPlaying ? `⏸️ Dừng (${vpFullPlaybackIndex + 1}/${vpFullPlaybackQueue.length})` : '▶️ Nghe lại toàn bộ giọng đọc'}
                            </button>
                        ` : ''}
                    </div>
                </div>`;
        }

        function vpTtsSettingsControls() {
            const p = vpData.pipeline || {};
            return `
                <div class="mb-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                    ${vpTtsPickerHtml('main', '🎙️ Giọng đọc chính (cho toàn bộ tác phẩm)',
                        'Dùng để đọc chung cho cả tác phẩm.', p.tts_provider, p.tts_voice_gender)}
                    ${vpTtsPickerHtml('avatar', '🕺 Giọng đọc MC (đồng bộ khẩu hình)',
                        'Tách riêng khỏi giọng chính — ghép với ảnh MC để tạo video đồng bộ khẩu hình (HeyGen).',
                        p.avatar_tts_provider, p.avatar_tts_voice_gender)}
                </div>`;
        }

        let vpTtsVoiceCache = {};
        let vpTtsCurrentAudio = null;

        // Generic single-clip playback — used by every per-shot 🔊 icon (both avatar and
        // main-voice narration). Stops whatever else is currently playing first, including a
        // "play all" run in progress, so only one sound is ever audible at a time.
        let vpSingleAudioPlayer = null;
        function vpPlayAudio(url) {
            vpStopAllNarration();
            if (vpSingleAudioPlayer) {
                vpSingleAudioPlayer.pause();
            }
            vpSingleAudioPlayer = new Audio(url);
            vpSingleAudioPlayer.play().catch(() => {});
        }

        // ==================== Audio Direction Pipeline (Phase 4) ====================
        // Approval-first panel for the 5 audio slots: scene ambience/music baseline, shot sfx
        // (always shot-scoped) and shot ambience/music override. Every slot follows the SAME
        // shape (status/asset_id/prompt/keywords + selected_by/approved_by/locked_by trail),
        // so one generic renderer + one generic action dispatcher covers all 5.

        const VP_AUDIO_SLOT_META = {
            sfx: { icon: '💥', label: 'Hiệu ứng âm thanh' },
            ambience: { icon: '🌬️', label: 'Âm thanh nền' },
            music: { icon: '🎵', label: 'Nhạc nền' },
        };

        function vpAudioKey(kind, sceneId, shotId, slot) {
            return kind + '_' + (kind === 'scene' ? sceneId : shotId) + '_' + slot;
        }

        function vpAudioStatusBadge(status) {
            const map = {
                pending: ['bg-gray-100 text-gray-500', 'Chưa xử lý'],
                rejected: ['bg-orange-100 text-orange-700', 'Đã từ chối'],
                generated: ['bg-blue-100 text-blue-700', 'Chờ duyệt'],
                approved: ['bg-green-100 text-green-700', '✅ Đã duyệt'],
                locked: ['bg-purple-100 text-purple-700', '🔒 Đã khóa'],
            };
            const [cls, text] = map[status] || map.pending;
            return `<span class="text-xs px-1.5 py-0.5 rounded-full font-medium ${cls}">${text}</span>`;
        }

        function vpAudioCandidateRow(kind, sceneId, shotId, slot, c) {
            const key = vpAudioKey(kind, sceneId, shotId, slot);
            const matchLabel = c.match_type === 'fingerprint' ? '🎯 khớp chính xác' : `📊 ${c.score_final}đ`;
            const breakdown = (c.score_content != null)
                ? ` <span class="text-gray-400">(nội dung ${c.score_content} · không khí ${c.score_mood})</span>` : '';
            return `<div class="flex items-center gap-2 text-xs bg-gray-50 border border-gray-200 rounded px-2 py-1.5">
                <button type="button" onclick="vpPlayAudio('${vpEscape(c.preview_url)}')" class="hover:text-indigo-600 flex-shrink-0" title="Nghe thử">🔊</button>
                <div class="flex-1 min-w-0">
                    <div class="truncate text-gray-700">${vpEscape((c.prompt || '').slice(0, 70))}</div>
                    <div class="text-gray-400">${matchLabel}${breakdown} · ${vpEscape(c.provider)}/${vpEscape(c.origin_source)} · ${c.duration_seconds != null ? c.duration_seconds + 's' : '?'}${c.usage_count > 1 ? ' · x' + c.usage_count + ' lần dùng' : ''}</div>
                </div>
                <button type="button" onclick="vpAudioSelect('${kind}', ${sceneId}, ${shotId || 'null'}, '${slot}', ${c.id})"
                    class="flex-shrink-0 text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-1 rounded">Chọn</button>
            </div>`;
        }

        function vpRenderAudioCandidates(kind, sceneId, shotId, slot) {
            const key = vpAudioKey(kind, sceneId, shotId, slot);
            if (!vpAudioExpanded[key]) return '';

            const list = vpAudioCandidates[key];
            if (list === undefined) {
                return `<div class="text-xs text-gray-400 italic py-1">Đang tải candidates...</div>`;
            }
            if (!list.length) {
                return `<div class="text-xs text-gray-400 italic py-1">Không tìm thấy candidate phù hợp trong thư viện.</div>`;
            }
            return `<div class="space-y-1 mt-1">${list.map(c => vpAudioCandidateRow(kind, sceneId, shotId, slot, c)).join('')}</div>`;
        }

        /**
         * Generic renderer for ONE audio slot's full panel: status, current asset preview,
         * candidate browser toggle, and the select/generate/regenerate/reject/approve/lock/
         * unlock action bar. `obj` is the raw scene or shot JSON (already has {slot}_status,
         * {slot}_asset_id, {slot}_prompt, {slot}_keywords, {slot}_asset via eager-loaded relation).
         */
        function vpRenderAudioSlotPanel(kind, sceneId, shotId, slot, obj) {
            const key = vpAudioKey(kind, sceneId, shotId, slot);
            const meta = VP_AUDIO_SLOT_META[slot];
            const status = obj[slot + '_status'] || 'pending';
            const asset = obj[slot + '_asset'] || null;
            const prompt = obj[slot + '_prompt'] || '';
            const locked = status === 'locked';
            const busy = vpAudioBusy[key];

            let html = `<div id="vp-audio-slot-${key}" class="border border-gray-100 rounded p-2 bg-gray-50/50">`;
            html += `<div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5 text-xs font-medium text-gray-600">${meta.icon} ${meta.label}</div>
                ${vpAudioStatusBadge(status)}
            </div>`;

            if (prompt) {
                html += `<div class="text-xs text-gray-500 mt-1 italic">"${vpEscape(prompt.slice(0, 90))}${prompt.length > 90 ? '…' : ''}"</div>`;
            }
            if (asset) {
                const scoreTxt = asset.score_final != null ? ` · điểm ${asset.score_final}` : '';
                const costTxt = asset.credits_used != null ? ` · ${asset.credits_used} credits` : '';
                html += `<div class="text-xs text-gray-600 mt-1">
                    <span>${vpEscape(asset.provider)}/${vpEscape(asset.origin_source)} · ${asset.duration_seconds != null ? asset.duration_seconds + 's' : '?'}${scoreTxt}${costTxt}${asset.usage_count > 1 ? ' · dùng lại x' + asset.usage_count : ''}</span>
                    <audio controls preload="none" src="${vpEscape(asset.preview_url)}" class="w-full h-8 mt-1"></audio>
                </div>`;
            }

            // Action bar
            html += `<div class="flex flex-wrap gap-1 mt-1.5">`;
            if (!locked) {
                html += vpAudioActionBtn(kind, sceneId, shotId, slot, 'generate', '✨ Tạo', busy);
                if (asset) html += vpAudioActionBtn(kind, sceneId, shotId, slot, 'regenerate', '🔁 Tạo lại', busy);
                html += `<button type="button" onclick="vpToggleAudioCandidates('${kind}', ${sceneId}, ${shotId || 'null'}, '${slot}')" class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-100">🔎 ${vpAudioExpanded[key] ? 'Ẩn' : 'Xem'} phương án</button>`;
                html += `<button type="button" onclick="vpAudioSearchStoryblocks('${kind}', ${sceneId}, ${shotId || 'null'}, '${slot}')" class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-100">🌐 Storyblocks</button>`;
                if (asset) {
                    html += vpAudioActionBtn(kind, sceneId, shotId, slot, 'reject', '❌ Từ chối', busy);
                }
                if (asset && status !== 'approved') {
                    html += vpAudioActionBtn(kind, sceneId, shotId, slot, 'approve', '✅ Duyệt', busy);
                }
                if (asset) {
                    html += vpAudioActionBtn(kind, sceneId, shotId, slot, 'lock', '🔒 Khóa', busy);
                }
            } else {
                html += vpAudioActionBtn(kind, sceneId, shotId, slot, 'unlock', '🔓 Mở khóa', busy);
            }
            html += `</div>`;

            html += vpRenderAudioCandidates(kind, sceneId, shotId, slot);
            html += `</div>`;
            return html;
        }

        function vpAudioActionBtn(kind, sceneId, shotId, slot, action, label, busy) {
            const disabled = busy ? 'disabled' : '';
            const isBusyThis = busy === action;
            return `<button type="button" ${disabled} onclick="vpAudioAction('${kind}', ${sceneId}, ${shotId || 'null'}, '${slot}', '${action}')"
                class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-100 disabled:opacity-50">${isBusyThis ? '⏳' : ''} ${label}</button>`;
        }

        /**
         * Shot-level ambience/music are OVERRIDES of the scene baseline — collapsed to a
         * one-line "kế thừa từ cảnh" display by default (the common case), only expanding to
         * the full panel once an override actually exists OR the user explicitly asks to
         * create one (mirrors resolvedAmbience()/resolvedMusic()'s shot-falls-back-to-scene
         * convention on the PHP side).
         */
        function vpRenderShotAudioOverrideSlot(scene, shot, slot) {
            const meta = VP_AUDIO_SLOT_META[slot];
            const overridden = !!shot[slot + '_override'];
            const key = vpAudioKey('shot', scene.id, shot.id, slot);

            if (overridden) {
                return vpAudioFilterAllowsDisplay(shot[slot + '_status']) ? vpRenderAudioSlotPanel('shot', scene.id, shot.id, slot, shot) : '';
            }

            const sceneAsset = scene[slot + '_asset'];
            const sceneStatus = scene[slot + '_status'] || 'pending';
            // Inherited-from-scene display isn't its own filterable item (the scene's own
            // panel already covers it) — but under an active audio filter, showing a status
            // that doesn't match (e.g. "Đã duyệt" while the user filtered for "Chờ duyệt")
            // reads as a contradiction, so hide it here too rather than showing unrelated info.
            if (!vpAudioFilterAllowsDisplay(sceneStatus)) {
                return '';
            }
            const showForm = !!vpAudioExpanded[key + '_override_form'];

            let html = `<div class="border border-gray-100 rounded p-2 bg-gray-50/30">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-1.5 text-xs text-gray-500">${meta.icon} ${meta.label} <span class="italic">— kế thừa từ cảnh</span> ${vpAudioStatusBadge(sceneStatus)}</div>
                    <button type="button" onclick="vpToggleShotAudioOverrideForm(${scene.id}, ${shot.id}, '${slot}')" class="text-xs text-indigo-600 hover:text-indigo-800">🔀 ${showForm ? 'Đóng' : 'Ghi đè riêng'}</button>
                </div>`;
            if (sceneAsset) {
                html += `<div class="text-xs text-gray-400 mt-0.5"><button type="button" onclick="vpPlayAudio('${vpEscape(sceneAsset.preview_url)}')" class="hover:text-indigo-600">🔊 Nghe</button> ${vpEscape(sceneAsset.provider)}/${vpEscape(sceneAsset.origin_source)}</div>`;
            }
            if (showForm) {
                html += `<div class="mt-2 flex gap-1">
                    <input type="text" id="vp-audio-override-prompt-${key}" placeholder="Mô tả âm thanh riêng cho phân đoạn này..." class="flex-1 text-xs border border-gray-300 rounded px-2 py-1">
                    <button type="button" onclick="vpAudioCreateOverride(${scene.id}, ${shot.id}, '${slot}')" class="text-xs px-2 py-1 rounded bg-indigo-600 hover:bg-indigo-700 text-white">Tạo ghi đè riêng</button>
                </div>`;
            }
            html += `</div>`;
            return html;
        }

        function vpToggleShotAudioOverrideForm(sceneId, shotId, slot) {
            const key = vpAudioKey('shot', sceneId, shotId, slot) + '_override_form';
            vpAudioExpanded[key] = !vpAudioExpanded[key];
            vpRender();
        }

        async function vpAudioCreateOverride(sceneId, shotId, slot) {
            const input = document.getElementById(`vp-audio-override-prompt-${vpAudioKey('shot', sceneId, shotId, slot)}`);
            const prompt = (input && input.value || '').trim();
            if (!prompt) {
                alert('Nhập mô tả âm thanh cho override trước.');
                return;
            }
            const key = vpAudioKey('shot', sceneId, shotId, slot);
            vpAudioBusy[key] = 'generate';
            vpRender();
            try {
                await vpPost(vpUrls.shotAudioGenerate(sceneId, shotId, slot), { prompt, force: false });
                await vpRefreshDataQuietly();
            } catch (e) {
                alert('Lỗi tạo override: ' + e.message);
            }
            delete vpAudioBusy[key];
            vpRender();
        }

        function vpToggleAudioCandidates(kind, sceneId, shotId, slot) {
            const key = vpAudioKey(kind, sceneId, shotId, slot);
            const willExpand = !vpAudioExpanded[key];
            vpAudioExpanded[key] = willExpand;
            vpRender();
            if (willExpand) {
                vpLoadAudioCandidates(kind, sceneId, shotId, slot);
            }
        }

        async function vpLoadAudioCandidates(kind, sceneId, shotId, slot) {
            const key = vpAudioKey(kind, sceneId, shotId, slot);
            try {
                const url = kind === 'scene' ? vpUrls.sceneAudioCandidates(sceneId, slot) : vpUrls.shotAudioCandidates(sceneId, shotId, slot);
                const resp = await fetch(url, { headers: { Accept: 'application/json' } });
                const data = await vpJson(resp);
                vpAudioCandidates[key] = data.candidates || [];
            } catch (e) {
                vpAudioCandidates[key] = [];
            }
            vpRender();
        }

        async function vpAudioSelect(kind, sceneId, shotId, slot, assetId) {
            const key = vpAudioKey(kind, sceneId, shotId, slot);
            vpAudioBusy[key] = 'select';
            vpRender();
            try {
                const url = kind === 'scene' ? vpUrls.sceneAudioSelect(sceneId, slot) : vpUrls.shotAudioSelect(sceneId, shotId, slot);
                await vpPost(url, { asset_id: assetId });
                vpAudioExpanded[key] = false;
                await vpRefreshDataQuietly();
            } catch (e) {
                alert('Lỗi chọn audio: ' + e.message);
            }
            delete vpAudioBusy[key];
            vpRender();
        }

        async function vpAudioAction(kind, sceneId, shotId, slot, action) {
            const key = vpAudioKey(kind, sceneId, shotId, slot);
            vpAudioBusy[key] = action;
            vpRender();
            try {
                const urlFor = {
                    generate: () => kind === 'scene' ? vpUrls.sceneAudioGenerate(sceneId, slot) : vpUrls.shotAudioGenerate(sceneId, shotId, slot),
                    regenerate: () => kind === 'scene' ? vpUrls.sceneAudioGenerate(sceneId, slot) : vpUrls.shotAudioGenerate(sceneId, shotId, slot),
                    reject: () => kind === 'scene' ? vpUrls.sceneAudioReject(sceneId, slot) : vpUrls.shotAudioReject(sceneId, shotId, slot),
                    approve: () => kind === 'scene' ? vpUrls.sceneAudioApprove(sceneId, slot) : vpUrls.shotAudioApprove(sceneId, shotId, slot),
                    lock: () => kind === 'scene' ? vpUrls.sceneAudioLock(sceneId, slot) : vpUrls.shotAudioLock(sceneId, shotId, slot),
                    unlock: () => kind === 'scene' ? vpUrls.sceneAudioUnlock(sceneId, slot) : vpUrls.shotAudioUnlock(sceneId, shotId, slot),
                };
                const body = action === 'regenerate' ? { force: true } : undefined;
                await vpPost(urlFor[action](), body);
                await vpRefreshDataQuietly();
                delete vpAudioBusy[key];

                // Duyệt/Khóa means "done reviewing this one" — jump straight to the next audio
                // slot awaiting approval, same review-queue UX as vpApproveShot() for images.
                if (action === 'approve' || action === 'lock') {
                    vpJumpToNextAudioPendingApproval(key);
                } else {
                    vpRender();
                }
                return;
            } catch (e) {
                alert('Lỗi: ' + e.message);
            }
            delete vpAudioBusy[key];
            vpRender();
        }

        /** Every audio slot that actually exists in the book, in scene/shot order: scene's own
         *  ambience/music baseline first, then each of its shots' sfx (always, when needed) and
         *  ambience/music overrides (only when actually overridden). */
        function vpAllAudioSlots() {
            const items = [];
            vpData.scenes.forEach(scene => {
                if (scene.needs_ambience) items.push({ kind: 'scene', scene, shot: null, slot: 'ambience', status: scene.ambience_status });
                if (scene.needs_music) items.push({ kind: 'scene', scene, shot: null, slot: 'music', status: scene.music_status });
                (scene.shots || []).forEach(shot => {
                    if (shot.needs_sfx) items.push({ kind: 'shot', scene, shot, slot: 'sfx', status: shot.sfx_status });
                    if (shot.ambience_override) items.push({ kind: 'shot', scene, shot, slot: 'ambience', status: shot.ambience_status });
                    if (shot.music_override) items.push({ kind: 'shot', scene, shot, slot: 'music', status: shot.music_status });
                });
            });
            return items;
        }

        /** Same search-forward-then-wrap convention as vpFindNextPendingApproval() (images) —
         *  starts right after the slot just approved/locked, wraps to the start if nothing
         *  remains further down, so a slot skipped earlier still gets surfaced eventually. */
        function vpFindNextAudioPendingApproval(afterKey) {
            const items = vpAllAudioSlots();
            const idx = items.findIndex(it => vpAudioKey(it.kind, it.scene.id, it.shot ? it.shot.id : null, it.slot) === afterKey);
            const ordered = idx >= 0 ? [...items.slice(idx + 1), ...items.slice(0, idx + 1)] : items;
            return ordered.find(it => it.status === 'generated') || null;
        }

        function vpJumpToNextAudioPendingApproval(justHandledKey) {
            const next = vpFindNextAudioPendingApproval(justHandledKey);
            if (!next) {
                vpShowAudioApprovalCompleteModal();
                return;
            }

            vpExpandedScenes[next.scene.id] = true;
            if (next.shot) {
                vpExpandedShots[next.shot.id] = true;
            }
            vpRender();

            const nextKey = vpAudioKey(next.kind, next.scene.id, next.shot ? next.shot.id : null, next.slot);
            const el = document.getElementById('vp-audio-slot-' + nextKey) || document.getElementById('vp-scene-' + next.scene.id);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function vpShowAudioApprovalCompleteModal() {
            vpOpenDetailModal('🎉 Hoàn tất', `
                <div class="text-center py-6">
                    <p class="text-5xl mb-3">🎉</p>
                    <p class="text-lg font-semibold text-gray-800 mb-1">Đã duyệt xong toàn bộ âm thanh!</p>
                    <p class="text-sm text-gray-500">Không còn mục âm thanh nào đang chờ duyệt trong tác phẩm này.</p>
                </div>
            `);
        }

        async function vpAudioSearchStoryblocks(kind, sceneId, shotId, slot) {
            try {
                const url = kind === 'scene' ? vpUrls.sceneAudioActiveTarget(sceneId, slot) : vpUrls.shotAudioActiveTarget(sceneId, shotId, slot);
                await vpPost(url);
            } catch (e) {
                // Non-fatal — the extension just won't auto-prefill this slot.
            }
            window.open('https://www.storyblocks.com/audio/search/' + encodeURIComponent(slot), '_blank');
        }

        /**
         * Whether a slot with this status should be shown at all under the CURRENT audio
         * filter — 'all' always shows everything; any other filter hides slots that don't
         * match, so an active "Chờ duyệt" filter never shows an already-"Đã duyệt" panel just
         * because it happened to sit inside a scene/shot that matched for a DIFFERENT reason.
         */
        function vpAudioFilterAllowsDisplay(status) {
            return vpAudioFilter === 'all' || vpAudioStatusMatchesFilterKey(status, vpAudioFilter);
        }

        /** Scene-level ambience + music baseline panel — shown inside the scene card body. */
        function vpRenderSceneAudioPanel(scene) {
            const showAmbience = vpAudioFilterAllowsDisplay(scene.ambience_status);
            const showMusic = vpAudioFilterAllowsDisplay(scene.music_status);
            if (!showAmbience && !showMusic) {
                return '';
            }
            return `<div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2">
                ${showAmbience ? vpRenderAudioSlotPanel('scene', scene.id, null, 'ambience', scene) : ''}
                ${showMusic ? vpRenderAudioSlotPanel('scene', scene.id, null, 'music', scene) : ''}
            </div>`;
        }

        /** Shot-level sfx (always) + ambience/music overrides — shown inside the shot card body. */
        function vpRenderShotAudioPanel(scene, shot) {
            let inner = '';
            if ((shot.needs_sfx || shot.sfx_asset_id) && vpAudioFilterAllowsDisplay(shot.sfx_status)) {
                inner += vpRenderAudioSlotPanel('shot', scene.id, shot.id, 'sfx', shot);
            }
            inner += vpRenderShotAudioOverrideSlot(scene, shot, 'ambience');
            inner += vpRenderShotAudioOverrideSlot(scene, shot, 'music');
            return inner ? `<div class="space-y-2 mt-2">${inner}</div>` : '';
        }
        // ==================== /Audio Direction Pipeline ====================

        // ==================== Motion & Transition Direction ====================
        // AI-selected Ken Burns motion (still images only) + shot-transition (every boundary),
        // same approval-first status shape as the audio slots above (pending/generated/approved/
        // locked, reusing vpAudioStatusBadge()) but rendered eagerly to a real playable .mp4 by
        // PHP/ffmpeg — the AI only ever picks a whitelisted preset name, never a shell command.

        const VP_MOTION_PRESET_META = {
            static: { icon: '⏸️', label: 'Tĩnh' },
            micro_zoom: { icon: '🔎', label: 'Zoom siêu nhẹ' },
            zoom_in: { icon: '🔍➕', label: 'Zoom vào' },
            zoom_out: { icon: '🔍➖', label: 'Zoom ra' },
            pan_left: { icon: '⬅️', label: 'Lia trái' },
            pan_right: { icon: '➡️', label: 'Lia phải' },
            push: { icon: '🚀', label: 'Đẩy tới' },
            pull: { icon: '🌀', label: 'Lùi ra' },
            shake: { icon: '📳', label: 'Rung nhẹ' },
        };
        const VP_TRANSITION_PRESET_META = {
            cut: { icon: '✂️', label: 'Cắt thẳng' },
            fade: { icon: '🌗', label: 'Mờ dần' },
            dissolve: { icon: '🌫️', label: 'Hòa tan' },
            fadeblack: { icon: '⬛', label: 'Mờ qua đen' },
            slide: { icon: '↔️', label: 'Trượt' },
            blur: { icon: '💫', label: 'Nhòe' },
        };

        function vpMotionKey(slotType, shotId) {
            return slotType + '_' + shotId;
        }

        const VP_MOTION_ACTION_LABELS = {
            generate: 'tạo', regenerate: 'tạo lại', reject: 'từ chối', approve: 'duyệt', lock: 'khóa', unlock: 'mở khóa',
        };

        function vpMotionActionBtn(slotType, sceneId, shotId, action, label, busy) {
            const disabled = busy ? 'disabled' : '';
            const isBusyThis = busy === action;
            // Any OTHER action mid-flight for this same slot also disables this button (shared
            // `busy` flag) — prevents e.g. clicking "Khóa" while "Duyệt" is still in flight for
            // the same slot, not just literal double-clicks on the same button.
            const content = isBusyThis ? '⏳ Đang xử lý…' : label;
            return `<button type="button" ${disabled} onclick="vpMotionAction('${slotType}', ${sceneId}, ${shotId}, '${action}')"
                class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed">${content}</button>`;
        }

        function vpMotionPresetPicker(slotType, sceneId, shotId, meta, busy) {
            const selectId = `vp-motion-preset-${vpMotionKey(slotType, shotId)}`;
            const options = Object.keys(meta).map(p => `<option value="${p}">${meta[p].icon} ${meta[p].label}</option>`).join('');
            const disabled = busy ? 'disabled' : '';
            return `<span class="inline-flex items-center gap-1">
                <select id="${selectId}" ${disabled} class="text-xs border border-gray-300 rounded px-1 py-1">${options}</select>
                <button type="button" ${disabled} onclick="vpMotionSelectPreset('${slotType}', ${sceneId}, ${shotId})"
                    class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-100 disabled:opacity-50">🔀 Đổi</button>
            </span>`;
        }

        async function vpMotionSelectPreset(slotType, sceneId, shotId) {
            const key = vpMotionKey(slotType, shotId);
            if (vpMotionBusy[key]) return; // a generate/approve/lock/etc. is already in flight for this slot
            const select = document.getElementById(`vp-motion-preset-${key}`);
            const preset = select ? select.value : null;
            if (!preset) return;
            await vpMotionAction(slotType, sceneId, shotId, 'generate', { preset });
        }

        async function vpMotionAction(slotType, sceneId, shotId, action, extraBody) {
            const key = vpMotionKey(slotType, shotId);
            // Hard guard against double-click / duplicate dispatch: the disabled attribute
            // covers the normal case, but this makes it impossible to race even if two calls
            // somehow fire before the next render (e.g. Enter key + click, fast double-tap).
            if (vpMotionBusy[key]) return;

            vpMotionBusy[key] = action;
            vpRender();

            const slotLabel = slotType === 'motion' ? 'chuyển động' : 'chuyển cảnh';
            const actionLabel = VP_MOTION_ACTION_LABELS[action] || action;
            try {
                const urlFor = {
                    generate: () => slotType === 'motion' ? vpUrls.shotMotionGenerate(sceneId, shotId) : vpUrls.shotTransitionGenerate(sceneId, shotId),
                    regenerate: () => slotType === 'motion' ? vpUrls.shotMotionRegenerate(sceneId, shotId) : vpUrls.shotTransitionRegenerate(sceneId, shotId),
                    reject: () => slotType === 'motion' ? vpUrls.shotMotionReject(sceneId, shotId) : vpUrls.shotTransitionReject(sceneId, shotId),
                    approve: () => slotType === 'motion' ? vpUrls.shotMotionApprove(sceneId, shotId) : vpUrls.shotTransitionApprove(sceneId, shotId),
                    lock: () => slotType === 'motion' ? vpUrls.shotMotionLock(sceneId, shotId) : vpUrls.shotTransitionLock(sceneId, shotId),
                    unlock: () => slotType === 'motion' ? vpUrls.shotMotionUnlock(sceneId, shotId) : vpUrls.shotTransitionUnlock(sceneId, shotId),
                };
                // 'regenerate' always forces a fresh AI call server-side — never sends a manual preset.
                const body = action === 'regenerate' ? undefined : extraBody;
                await vpPost(urlFor[action](), body);
                await vpRefreshDataQuietly();
            } catch (e) {
                // vpJson() already unwraps a JSON {message: "..."} body from the server into
                // e.message; a fetch-level TypeError (offline, DNS, CORS) never reaches that
                // path, so it's called out separately rather than showing a raw JS error string.
                const detail = e instanceof TypeError
                    ? 'Không kết nối được máy chủ — kiểm tra mạng rồi thử lại.'
                    : e.message;
                alert(`Lỗi khi ${actionLabel} ${slotLabel} (shot #${shotId}): ${detail}`);
            }
            delete vpMotionBusy[key];
            vpRender();
        }

        /** Ken Burns motion panel — omitted entirely for avatar segments / already-video shots (motion only applies to a still image). */
        function vpRenderMotionSlotPanel(scene, shot) {
            if (shot.is_avatar_segment || /\.(mp4|mov|webm)$/i.test(shot.resolved_asset_path || '')) {
                return '';
            }

            const key = vpMotionKey('motion', shot.id);
            const status = shot.motion_status || 'pending';
            const locked = status === 'locked';
            const busy = vpMotionBusy[key];
            const meta = shot.motion_preset ? (VP_MOTION_PRESET_META[shot.motion_preset] || null) : null;

            let html = `<div id="vp-motion-slot-${key}" class="border border-gray-100 rounded p-2 bg-gray-50/50">`;
            html += `<div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5 text-xs font-medium text-gray-600">🎥 Chuyển động${meta ? ` — ${meta.icon} ${meta.label}` : ''}</div>
                ${vpAudioStatusBadge(status)}
            </div>`;
            if (shot.motion_reason) {
                html += `<div class="text-xs text-gray-500 mt-1 italic">"${vpEscape(shot.motion_reason)}"</div>`;
            }
            if (shot.motion_asset_path) {
                html += `<video controls preload="none" src="/storage/${vpEscape(shot.motion_asset_path)}" class="w-full rounded mt-1" style="max-height:220px"></video>`;
            }

            html += `<div class="flex flex-wrap items-center gap-1 mt-1.5">`;
            if (!locked) {
                html += vpMotionActionBtn('motion', scene.id, shot.id, 'generate', '✨ Tạo', busy);
                if (shot.motion_asset_path) html += vpMotionActionBtn('motion', scene.id, shot.id, 'regenerate', '🔁 Tạo lại', busy);
                html += vpMotionPresetPicker('motion', scene.id, shot.id, VP_MOTION_PRESET_META, busy);
                if (shot.motion_asset_path) html += vpMotionActionBtn('motion', scene.id, shot.id, 'reject', '❌ Từ chối', busy);
                if (shot.motion_asset_path && status !== 'approved') html += vpMotionActionBtn('motion', scene.id, shot.id, 'approve', '✅ Duyệt', busy);
                if (shot.motion_asset_path) html += vpMotionActionBtn('motion', scene.id, shot.id, 'lock', '🔒 Khóa', busy);
            } else {
                html += vpMotionActionBtn('motion', scene.id, shot.id, 'unlock', '🔓 Mở khóa', busy);
            }
            html += `</div></div>`;
            return html;
        }

        /** True only for the very first shot of the whole book (first shot of the first scene) — mirrors MotionDirectionController::resolvePreviousShot() returning null. */
        function vpIsFirstShotOfBook(scene, shot) {
            const firstScene = vpData.scenes[0];
            if (!firstScene || firstScene.id !== scene.id) return false;
            const firstShot = (firstScene.shots || [])[0];
            return !!firstShot && firstShot.id === shot.id;
        }

        /** Transition-INTO-this-shot panel — applies to every shot (avatar/video-sourced included), except the very first shot of the book. */
        function vpRenderTransitionSlotPanel(scene, shot) {
            if (vpIsFirstShotOfBook(scene, shot)) {
                return `<div class="border border-gray-100 rounded p-2 bg-gray-50/30 text-xs text-gray-400 italic">🎬 Đầu sách — không cần chuyển cảnh</div>`;
            }

            const key = vpMotionKey('transition', shot.id);
            const status = shot.transition_status || 'pending';
            const locked = status === 'locked';
            const busy = vpMotionBusy[key];
            const meta = shot.transition_preset ? (VP_TRANSITION_PRESET_META[shot.transition_preset] || null) : null;

            let html = `<div id="vp-motion-slot-${key}" class="border border-gray-100 rounded p-2 bg-gray-50/50">`;
            html += `<div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5 text-xs font-medium text-gray-600">🎬 Vào cảnh${meta ? ` — ${meta.icon} ${meta.label}` : ''}</div>
                ${vpAudioStatusBadge(status)}
            </div>`;
            if (shot.transition_reason) {
                html += `<div class="text-xs text-gray-500 mt-1 italic">"${vpEscape(shot.transition_reason)}"</div>`;
            }
            if (shot.transition_asset_path) {
                html += `<video controls preload="none" src="/storage/${vpEscape(shot.transition_asset_path)}" class="w-full rounded mt-1" style="max-height:220px"></video>`;
            }

            html += `<div class="flex flex-wrap items-center gap-1 mt-1.5">`;
            if (!locked) {
                html += vpMotionActionBtn('transition', scene.id, shot.id, 'generate', '✨ Tạo', busy);
                if (shot.transition_asset_path) html += vpMotionActionBtn('transition', scene.id, shot.id, 'regenerate', '🔁 Tạo lại', busy);
                html += vpMotionPresetPicker('transition', scene.id, shot.id, VP_TRANSITION_PRESET_META, busy);
                if (shot.transition_asset_path) html += vpMotionActionBtn('transition', scene.id, shot.id, 'reject', '❌ Từ chối', busy);
                if (shot.transition_asset_path && status !== 'approved') html += vpMotionActionBtn('transition', scene.id, shot.id, 'approve', '✅ Duyệt', busy);
                if (shot.transition_asset_path) html += vpMotionActionBtn('transition', scene.id, shot.id, 'lock', '🔒 Khóa', busy);
            } else {
                html += vpMotionActionBtn('transition', scene.id, shot.id, 'unlock', '🔓 Mở khóa', busy);
            }
            html += `</div></div>`;
            return html;
        }

        function vpRenderShotMotionPanel(scene, shot) {
            const motionHtml = vpRenderMotionSlotPanel(scene, shot);
            const transitionHtml = vpRenderTransitionSlotPanel(scene, shot);
            return `<div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2">${motionHtml}${transitionHtml}</div>`;
        }
        // ==================== /Motion & Transition Direction ====================

        // "▶️ Nghe lại toàn bộ TTS" — plays every non-avatar shot's main-voice narration back
        // to back, in book order, like a continuous read-through. Avatar shots are excluded
        // (their audio is a separate voice, meant to pair with the lipsync video, not the
        // book's narration track).
        let vpFullPlaybackQueue = [];
        let vpFullPlaybackIndex = 0;
        let vpFullPlaybackAudio = null;
        let vpFullPlaybackPlaying = false;

        function vpToggleFullPlayback() {
            if (vpFullPlaybackPlaying) {
                vpStopAllNarration();
            } else {
                vpPlayAllNarration();
            }
        }

        function vpPlayAllNarration() {
            vpFullPlaybackQueue = vpAllShots()
                .filter(({ shot }) => !shot.is_avatar_segment && shot.narration_audio_url)
                .map(({ shot }) => shot.narration_audio_url);

            if (!vpFullPlaybackQueue.length) {
                alert('Chưa có phân đoạn nào có giọng đọc chính để nghe.');
                return;
            }

            if (vpSingleAudioPlayer) {
                vpSingleAudioPlayer.pause();
                vpSingleAudioPlayer = null;
            }

            vpFullPlaybackIndex = 0;
            vpFullPlaybackPlaying = true;
            vpPlayNextInFullQueue();
        }

        function vpPlayNextInFullQueue() {
            if (!vpFullPlaybackPlaying || vpFullPlaybackIndex >= vpFullPlaybackQueue.length) {
                vpFullPlaybackPlaying = false;
                vpFullPlaybackAudio = null;
                vpPatchFullPlaybackButton();
                return;
            }

            vpFullPlaybackAudio = new Audio(vpFullPlaybackQueue[vpFullPlaybackIndex]);
            vpFullPlaybackAudio.addEventListener('ended', () => {
                vpFullPlaybackIndex++;
                vpPlayNextInFullQueue();
            });
            vpFullPlaybackAudio.play().catch(() => {
                // A shot whose audio file is missing/broken shouldn't stall the whole read-
                // through — skip it and keep going.
                vpFullPlaybackIndex++;
                vpPlayNextInFullQueue();
            });
            vpPatchFullPlaybackButton();
        }

        function vpStopAllNarration() {
            vpFullPlaybackPlaying = false;
            if (vpFullPlaybackAudio) {
                vpFullPlaybackAudio.pause();
                vpFullPlaybackAudio = null;
            }
            vpPatchFullPlaybackButton();
        }

        function vpPatchFullPlaybackButton() {
            const btn = document.getElementById('vp-play-all-narration-btn');
            if (!btn) return;
            btn.textContent = vpFullPlaybackPlaying
                ? `⏸️ Dừng (${vpFullPlaybackIndex + 1}/${vpFullPlaybackQueue.length})`
                : '▶️ Nghe lại toàn bộ giọng đọc';
        }

        function vpTtsSavedVoiceName(scope) {
            const p = vpData.pipeline || {};
            return (scope === 'avatar' ? p.avatar_tts_voice_name : p.tts_voice_name) || '';
        }

        async function vpTtsFetchVoices(provider, gender) {
            const cacheKey = `${provider}:${gender}`;
            if (vpTtsVoiceCache[cacheKey]) return vpTtsVoiceCache[cacheKey];

            const resp = await fetch(`${vpUrls.getAvailableVoices}?gender=${encodeURIComponent(gender)}&provider=${encodeURIComponent(provider)}`);
            const data = await vpJson(resp);
            const voices = (data.voices && data.voices[gender]) || {};
            vpTtsVoiceCache[cacheKey] = voices;
            return voices;
        }

        // Repopulates one scope's voice <select> from its own provider/gender selection —
        // called right after every full render (voice options aren't part of the static HTML
        // since they're fetched dynamically per provider) and whenever the user changes
        // provider/gender. Silently no-ops if this scope's controls aren't in the current view.
        async function vpTtsRefreshVoiceOptions(scope) {
            const voiceSelect = document.getElementById(`vp-tts-${scope}-voice`);
            if (!voiceSelect) return;

            const providerEl = document.getElementById(`vp-tts-${scope}-provider`);
            const genderEl = document.querySelector(`input[name="vp-tts-${scope}-gender"]:checked`);
            const provider = providerEl ? providerEl.value : '';
            const gender = genderEl ? genderEl.value : 'female';

            if (!provider) {
                voiceSelect.innerHTML = '<option value="">-- Chọn nhà cung cấp trước --</option>';
                return;
            }

            voiceSelect.innerHTML = '<option value="">⏳ Đang tải...</option>';
            try {
                const voices = await vpTtsFetchVoices(provider, gender);
                const savedName = vpTtsSavedVoiceName(scope);
                voiceSelect.innerHTML = '<option value="">-- Chọn giọng --</option>';
                for (const [code, voiceLabel] of Object.entries(voices)) {
                    const opt = document.createElement('option');
                    opt.value = code;
                    opt.textContent = voiceLabel;
                    if (code === savedName) opt.selected = true;
                    voiceSelect.appendChild(opt);
                }
            } catch (e) {
                voiceSelect.innerHTML = '<option value="">-- Lỗi tải giọng --</option>';
            }
        }

        function vpTtsRefreshAllVoiceOptions() {
            vpTtsRefreshVoiceOptions('main');
            vpTtsRefreshVoiceOptions('avatar');
        }

        async function vpTtsSave(scope) {
            const providerEl = document.getElementById(`vp-tts-${scope}-provider`);
            const genderEl = document.querySelector(`input[name="vp-tts-${scope}-gender"]:checked`);
            const voiceEl = document.getElementById(`vp-tts-${scope}-voice`);

            const payload = {
                provider: providerEl ? providerEl.value : '',
                voice_gender: genderEl ? genderEl.value : 'female',
                voice_name: voiceEl ? voiceEl.value : '',
            };

            const url = scope === 'avatar' ? vpUrls.avatarTtsSettings : vpUrls.ttsSettings;
            try {
                await vpPost(url, payload);
                if (vpData.pipeline) {
                    const prefix = scope === 'avatar' ? 'avatar_tts_' : 'tts_';
                    vpData.pipeline[prefix + 'provider'] = payload.provider || null;
                    vpData.pipeline[prefix + 'voice_gender'] = payload.voice_gender;
                    vpData.pipeline[prefix + 'voice_name'] = payload.voice_name || null;
                }
            } catch (e) {
                alert('Lỗi lưu giọng đọc: ' + e.message);
            }
        }

        async function vpTtsProviderChanged(scope) {
            await vpTtsRefreshVoiceOptions(scope);
            await vpTtsSave(scope);
        }

        async function vpTtsGenderChanged(scope) {
            await vpTtsRefreshVoiceOptions(scope);
            await vpTtsSave(scope);
        }

        async function vpTtsVoiceChanged(scope) {
            await vpTtsSave(scope);
        }

        // "Create All" — bulk-generates TTS for every shot missing audio under this scope's
        // voice, via a background job (same survives-tab-close/reload pattern as
        // BulkGenerateShotImagesJob/"Tự động tạo bằng AI" — never a client-side loop).
        function vpTtsBulkStatus(scope) {
            const p = vpData.pipeline || {};
            return (scope === 'avatar' ? p.bulk_avatar_tts_status : p.bulk_narration_tts_status) || null;
        }

        function vpTtsBulkRunning(scope) {
            const s = vpTtsBulkStatus(scope);
            return !!s && s.status === 'running';
        }

        function vpTtsCreateAllLabel(scope) {
            const s = vpTtsBulkStatus(scope);
            if (s && s.status === 'running') {
                return `⏳ Đang tạo (${s.processed}/${s.total})...`;
            }
            return '🗂️ Tạo tất cả';
        }

        async function vpTtsCreateAll(scope) {
            if (vpTtsBulkRunning(scope)) return;

            const url = scope === 'avatar' ? vpUrls.bulkAvatarTts : vpUrls.bulkNarrationTts;
            try {
                await vpPost(url);
            } catch (e) {
                alert('Lỗi khi bắt đầu tạo giọng đọc hàng loạt: ' + e.message);
                return;
            }

            await vpRefreshDataQuietly();
            vpPatchTtsCreateAllButtons();
        }

        function vpPatchTtsCreateAllButtons() {
            ['main', 'avatar'].forEach((scope) => {
                const btn = document.getElementById(`vp-tts-${scope}-createall-btn`);
                if (!btn) return;
                btn.disabled = vpTtsBulkRunning(scope);
                btn.textContent = vpTtsCreateAllLabel(scope);
            });
        }

        async function vpTtsPreviewVoice(scope) {
            const providerEl = document.getElementById(`vp-tts-${scope}-provider`);
            const genderEl = document.querySelector(`input[name="vp-tts-${scope}-gender"]:checked`);
            const voiceEl = document.getElementById(`vp-tts-${scope}-voice`);
            const provider = providerEl ? providerEl.value : '';
            const gender = genderEl ? genderEl.value : 'female';
            const voiceName = voiceEl ? voiceEl.value : '';

            if (!provider || !voiceName) {
                alert('Vui lòng chọn Provider và giọng trước.');
                return;
            }

            if (vpTtsCurrentAudio) {
                vpTtsCurrentAudio.pause();
                vpTtsCurrentAudio = null;
            }

            const btn = document.getElementById(`vp-tts-${scope}-preview-btn`);
            const original = btn ? btn.innerHTML : null;
            if (btn) { btn.innerHTML = '⏳'; btn.disabled = true; }

            try {
                const resp = await fetch(vpUrls.previewVoice, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': vpCsrf() },
                    body: JSON.stringify({
                        text: 'Xin chào, đây là giọng đọc mẫu.',
                        voice_gender: gender,
                        voice_name: voiceName,
                        provider: provider,
                    }),
                });
                const data = await resp.json();
                // This endpoint's failure shape uses "error", not "message" — differs from
                // this app's own routes, so vpJson()'s generic message extraction is skipped here.
                if (!resp.ok || !data.success) {
                    throw new Error(data.error || data.message || 'Không thể tạo preview');
                }
                vpTtsCurrentAudio = new Audio(data.audio_url);
                vpTtsCurrentAudio.play().catch(() => {});
            } catch (e) {
                alert('Lỗi: ' + e.message);
            } finally {
                if (btn) { btn.innerHTML = original; btn.disabled = false; }
            }
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
                <div class="text-sm text-gray-600">
                    <div class="font-semibold text-gray-700 mb-2">${providerLabel} (${vpEscape(model)}${pricePerImage !== null ? `, $${pricePerImage}/ảnh` : ''})</div>
                    <div>Chắc chắn cần AI (phân đoạn hư cấu): <strong>${definiteAi}</strong> phân đoạn ${costLine(definiteAi)}</div>
                    <div>Có thể cần AI (phân đoạn có thật nhưng chưa xử lý/không tìm ra nguồn): <strong>${uncertain}</strong> phân đoạn</div>
                    <div class="mt-1">Khoảng chi phí ảnh dự kiến: <strong>${costLine(definiteAi)}</strong> đến <strong>${costLine(worstCase)}</strong> (tùy Thư viện/stock tìm được bao nhiêu trong số ${uncertain} phân đoạn còn lại).</div>
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

        // "Tự động tạo bằng AI" runs as a background job (BulkGenerateShotImagesJob), not a
        // client-side loop — this reads its live progress straight off the polled pipeline
        // row, so a page reload mid-run shows the correct in-progress state immediately
        // instead of looking like nothing ever happened.
        function vpBulkAiStatus() {
            return (vpData.pipeline && vpData.pipeline.bulk_ai_generate_status) || null;
        }

        function vpBulkAiRunning() {
            const s = vpBulkAiStatus();
            return !!s && s.status === 'running';
        }

        function vpBulkAiButtonLabel() {
            const s = vpBulkAiStatus();
            if (s && s.status === 'running') {
                return `⏳ Đang tạo bằng AI (${s.processed}/${s.total})...`;
            }
            return '🎨 Tự động tạo bằng AI';
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

        // Shot completion filter — lets the user narrow the scene list down to just the
        // shots in one status bucket (e.g. "chưa có ảnh", "bị chặn kiểm duyệt") instead of
        // manually expanding every scene to hunt for them. Purely a client-side view filter —
        // never changes any shot's actual data.
        const VP_SHOT_FILTERS = [
            { key: 'all', label: 'Tất cả', match: () => true },
            { key: 'ready', label: '✅ Hoàn thiện', match: (s) => s.status === 'ready' },
            { key: 'no_asset', label: '⬜ Chưa có ảnh/video', match: (s) => ['pending', 'analyzed'].includes(s.status) },
            { key: 'in_progress', label: '⏳ Đang xử lý / chờ duyệt', match: (s) => ['searching', 'scored', 'resolving', 'image_ready'].includes(s.status) },
            { key: 'content_blocked', label: '⚠️ Bị chặn kiểm duyệt', match: (s) => s.status === 'content_blocked' },
            { key: 'failed', label: '❌ Lỗi', match: (s) => s.status === 'failed' },
        ];
        let vpShotFilter = 'all';

        function vpShotMatchesFilter(shot) {
            if (vpShotFilter === 'all') return true;
            const f = VP_SHOT_FILTERS.find((f) => f.key === vpShotFilter);
            return f ? f.match(shot) : true;
        }

        function vpRenderShotFilterBar() {
            const allShots = vpAllShots().map(({ shot }) => shot);
            let html = `<div class="flex flex-wrap gap-1.5 mb-3">`;
            VP_SHOT_FILTERS.forEach((f) => {
                const count = f.key === 'all' ? allShots.length : allShots.filter(f.match).length;
                const active = vpShotFilter === f.key;
                html += `<button type="button" onclick="vpSetShotFilter('${f.key}')"
                    class="text-xs px-2.5 py-1 rounded-full font-medium border transition ${active ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50'}">
                    ${f.label} (${count})
                </button>`;
            });
            html += `</div>`;
            return html;
        }

        function vpSetShotFilter(key) {
            vpShotFilter = key;
            vpRender();
        }

        // ---- Audio review filter: finds audio slots (scene ambience/music baseline, shot
        // sfx/ambience-override/music-override) needing attention, so approving/creating a
        // whole book's audio doesn't require manually expanding every scene/shot to check. A
        // SEPARATE dimension from VP_SHOT_FILTERS above (which is about image/video status) —
        // both can be active together (AND'd), each narrows independently.
        const VP_AUDIO_FILTERS = [
            { key: 'all', label: '🔈 Tất cả âm thanh' },
            { key: 'needs_creation', label: '🆕 Cần tạo' },
            { key: 'pending_review', label: '⏳ Chờ duyệt' },
            { key: 'done', label: '✅ Đã xong' },
        ];
        let vpAudioFilter = 'all';

        function vpAudioStatusMatchesFilterKey(status, filterKey) {
            switch (filterKey) {
                case 'needs_creation': return !status || status === 'pending' || status === 'rejected';
                case 'pending_review': return status === 'generated';
                case 'done': return status === 'approved' || status === 'locked';
                default: return true;
            }
        }

        /** Does this SHOT have an audio slot (sfx, or an active ambience/music override) matching the given filter key? */
        function vpShotMatchesAudioFilterKey(shot, filterKey) {
            if (shot.needs_sfx && vpAudioStatusMatchesFilterKey(shot.sfx_status, filterKey)) return true;
            if (shot.ambience_override && vpAudioStatusMatchesFilterKey(shot.ambience_status, filterKey)) return true;
            if (shot.music_override && vpAudioStatusMatchesFilterKey(shot.music_status, filterKey)) return true;
            return false;
        }

        /** Does this SCENE's own ambience/music BASELINE (not its shots) match the given filter key? */
        function vpSceneOwnAudioMatchesFilterKey(scene, filterKey) {
            if (scene.needs_ambience && vpAudioStatusMatchesFilterKey(scene.ambience_status, filterKey)) return true;
            if (scene.needs_music && vpAudioStatusMatchesFilterKey(scene.music_status, filterKey)) return true;
            return false;
        }

        function vpShotMatchesAudioFilter(shot) {
            return vpAudioFilter === 'all' ? true : vpShotMatchesAudioFilterKey(shot, vpAudioFilter);
        }

        function vpSceneOwnAudioMatches(scene) {
            return vpAudioFilter !== 'all' && vpSceneOwnAudioMatchesFilterKey(scene, vpAudioFilter);
        }

        function vpAudioFilterCount(filterKey) {
            let count = 0;
            vpData.scenes.forEach(scene => {
                if (vpSceneOwnAudioMatchesFilterKey(scene, filterKey)) count++;
                (scene.shots || []).forEach(shot => {
                    if (vpShotMatchesAudioFilterKey(shot, filterKey)) count++;
                });
            });
            return count;
        }

        function vpRenderAudioFilterBar() {
            let html = `<div class="flex flex-wrap items-center gap-1.5 mb-3">`;
            VP_AUDIO_FILTERS.forEach((f) => {
                const count = vpAudioFilterCount(f.key);
                const active = vpAudioFilter === f.key;
                html += `<button type="button" onclick="vpSetAudioFilter('${f.key}')"
                    class="text-xs px-2.5 py-1 rounded-full font-medium border transition ${active ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50'}">
                    ${f.label} (${count})
                </button>`;
            });
            html += vpAudioBulkButtonHtml();
            html += `</div>`;
            return html;
        }

        function vpSetAudioFilter(key) {
            vpAudioFilter = key;
            vpRender();
        }

        /**
         * Combines the 3 independent bulk jobs (scene ambience, scene music, shot sfx) into one
         * {status,total,processed} the button can show a single progress number for — running
         * if ANY of the 3 is still running, total/processed are summed across whichever ones
         * have actually started (a category with zero pending work never gets dispatched, so
         * its status may stay null forever — that's fine, it just contributes 0/0).
         */
        function vpAudioBulkCombinedStatus() {
            const parts = [
                (vpData.pipeline && vpData.pipeline.bulk_scene_ambience_status) || null,
                (vpData.pipeline && vpData.pipeline.bulk_scene_music_status) || null,
                (vpData.pipeline && vpData.pipeline.bulk_shot_sfx_status) || null,
                (vpData.pipeline && vpData.pipeline.bulk_shot_ambience_override_status) || null,
                (vpData.pipeline && vpData.pipeline.bulk_shot_music_override_status) || null,
            ].filter(Boolean);

            if (!parts.length) return null;

            const running = parts.some(p => p.status === 'running');
            const total = parts.reduce((sum, p) => sum + (p.total || 0), 0);
            const processed = parts.reduce((sum, p) => sum + (p.processed || 0), 0);
            return { status: running ? 'running' : 'done', total, processed };
        }

        function vpAudioBulkButtonHtml() {
            const combined = vpAudioBulkCombinedStatus();
            const running = combined && combined.status === 'running';
            const needsCreationCount = vpAudioFilterCount('needs_creation');

            if (!running && needsCreationCount === 0) return '';

            if (running) {
                return `<button disabled id="vp-audio-bulk-btn"
                    class="text-xs px-2.5 py-1 rounded-full font-medium bg-emerald-600 opacity-70 text-white ml-1">
                    ⏳ Đang tạo... (${combined.processed}/${combined.total})
                </button>`;
            }

            return `<button type="button" onclick="vpBulkGenerateAudio()" id="vp-audio-bulk-btn"
                class="text-xs px-2.5 py-1 rounded-full font-medium bg-emerald-600 hover:bg-emerald-700 text-white ml-1">
                ✨ Tạo tất cả (${needsCreationCount})
            </button>`;
        }

        /**
         * Refreshes ONLY the bulk-audio button's label/disabled state every poll tick — same
         * convention as vpPatchAutoRunButtons()/vpPatchTtsCreateAllButtons(), since this button
         * lives inside the audio filter bar which a "patched" (surgical shot-diff) poll tick
         * does NOT re-render from scratch.
         */
        function vpPatchAudioBulkButton() {
            const el = document.getElementById('vp-audio-bulk-btn');
            const html = vpAudioBulkButtonHtml();
            if (!el && !html) return;
            // Simplest reliable patch: if presence/absence needs to change (button should now
            // show or hide), a full vpRender() is needed anyway — only the common case (button
            // already showing, just its label/progress changed) is worth a cheap direct patch.
            if (el && html) {
                const tmp = document.createElement('div');
                tmp.innerHTML = html;
                const fresh = tmp.firstElementChild;
                el.replaceWith(fresh);
            } else {
                vpRender();
            }
        }

        async function vpBulkGenerateAudio() {
            try {
                await vpPost(vpUrls.bulkGenerateAudio);
            } catch (e) {
                alert('Lỗi khi bắt đầu tạo hàng loạt: ' + e.message);
                return;
            }
            await vpRefreshDataQuietly();
            vpRender();
        }

        function vpPatchSceneBadge(scene) {
            const el = document.getElementById('vp-scene-badge-' + scene.id);
            if (!el) return;
            const shots = scene.shots || [];
            const readyCount = shots.filter(s => s.status === 'ready').length;
            const filterActive = vpShotFilter !== 'all';
            if (filterActive) {
                const matchCount = shots.filter(vpShotMatchesFilter).length;
                el.textContent = `${matchCount} khớp lọc`;
                el.className = 'px-2 py-0.5 rounded-full font-medium bg-indigo-100 text-indigo-700';
                return;
            }
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
            const bulkAiRunning = vpBulkAiRunning();
            const libBtn = document.getElementById('vp-autorun-library-btn');
            if (libBtn) {
                libBtn.disabled = vpAutoRunningLibrary || bulkAiRunning;
                libBtn.textContent = vpAutoRunningLibrary ? '⏳ Đang lấy từ Thư viện...' : '📚 Tự động lấy từ Thư viện';
            }
            const aiBtn = document.getElementById('vp-autorun-ai-btn');
            if (aiBtn) {
                aiBtn.disabled = vpAutoRunningLibrary || bulkAiRunning;
                aiBtn.textContent = vpBulkAiButtonLabel();
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
                vpData.speakerAvatarUrl = data.speaker_avatar_url || null;
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
                        <p class="text-sm text-red-700">⚠️ ${failedChunks} nhóm phân đoạn bị lỗi khi phân tích AI và chưa có từ khóa/mô tả ảnh.</p>
                        <button onclick="vpRetryFailedChunks()" id="vp-retry-chunks-btn" class="text-sm bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg">
                            🔁 Thử lại phần lỗi
                        </button>
                    </div>`;
            }

            html += `
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-3">
                    <p id="vp-summary-counts" class="text-sm text-gray-600">📋 <strong>${scenes.length}</strong> cảnh · <strong>${allShots.length}</strong> shot · <strong>${readyCount}</strong> đã sẵn sàng</p>
                    <div class="flex gap-2 flex-wrap">
                        <button onclick="vpAutoRunLibrary()" id="vp-autorun-library-btn" class="text-sm bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-4 rounded-lg" ${vpAutoRunningLibrary || vpBulkAiRunning() ? 'disabled' : ''}>
                            ${vpAutoRunningLibrary ? '⏳ Đang lấy từ Thư viện...' : '📚 Tự động lấy từ Thư viện'}
                        </button>
                        <button onclick="vpAutoRunAI()" id="vp-autorun-ai-btn" class="text-sm bg-fuchsia-600 hover:bg-fuchsia-700 text-white font-semibold py-2 px-4 rounded-lg" ${vpAutoRunningLibrary || vpBulkAiRunning() ? 'disabled' : ''}>
                            ${vpBulkAiButtonLabel()}
                        </button>
                        <a href="${vpUrls.download}" class="text-sm bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 px-4 rounded-lg inline-block">
                            📦 Tải toàn bộ tài nguyên
                        </a>
                    </div>
                </div>
                ${vpImageSettingsControls()}
                ${vpTtsSettingsControls()}
                ${vpRenderShotFilterBar()}
                ${vpRenderAudioFilterBar()}
                <div class="mb-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="p-4 rounded-lg border bg-white">
                        <div class="w-full flex items-center justify-between gap-2">
                            <button type="button" onclick="vpToggleStoryBiblePanel()" class="flex-1 flex items-center justify-between text-left min-w-0">
                                <h3 class="text-sm font-bold text-gray-700">🧠 Đạo Diễn AI / Bộ Bối Cảnh Chuẩn</h3>
                                <span id="vp-story-bible-toggle-icon" class="text-gray-400 flex-shrink-0">${vpStoryBiblePanelExpanded ? '▲' : '▼'}</span>
                            </button>
                            <button type="button" onclick="event.stopPropagation(); vpShowStoryBibleHint()" title="Giải thích mục này"
                                class="flex-shrink-0 w-5 h-5 rounded-full bg-gray-100 hover:bg-indigo-100 text-gray-500 hover:text-indigo-600 text-xs font-bold flex items-center justify-center">?</button>
                        </div>
                        <div id="vp-story-bible-panel" class="mt-2" style="${vpStoryBiblePanelExpanded ? '' : 'display:none'}">
                            <p class="text-sm text-gray-400">Đang tải trạng thái Bộ Bối Cảnh Chuẩn...</p>
                        </div>
                    </div>
                    <div class="p-4 rounded-lg border bg-white">
                        <div class="w-full flex items-center justify-between gap-2">
                            <button type="button" onclick="vpToggleContinuityPanel()" class="flex-1 flex items-center justify-between text-left min-w-0">
                                <h3 class="text-sm font-bold text-gray-700">🧭 Báo Cáo Tính Nhất Quán</h3>
                                <span id="vp-continuity-toggle-icon" class="text-gray-400 flex-shrink-0">${vpContinuityPanelExpanded ? '▲' : '▼'}</span>
                            </button>
                            <button type="button" onclick="event.stopPropagation(); vpShowContinuityHint()" title="Giải thích mục này"
                                class="flex-shrink-0 w-5 h-5 rounded-full bg-gray-100 hover:bg-indigo-100 text-gray-500 hover:text-indigo-600 text-xs font-bold flex items-center justify-center">?</button>
                        </div>
                        <div id="vp-continuity-panel" class="mt-2" style="${vpContinuityPanelExpanded ? '' : 'display:none'}">
                            <p class="text-sm text-gray-400">Đang tải continuity report...</p>
                        </div>
                    </div>
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
                content_blocked: ['bg-orange-100 text-orange-700', '⚠️ Bị chặn kiểm duyệt nội dung'],
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

        /**
         * is_real_world là phân loại NỘI DUNG (câu narration có mô tả thứ có thật ngoài đời
         * hay không) — cố định, do AI Director gán lúc phân tích, và được
         * SceneAssetResolverService dùng để quyết định có tìm ảnh/video thật trước hay ép
         * dùng AI luôn (xem is_real_world ? tìm trong candidates đã chọn : null). KHÔNG được
         * đổi giá trị này chỉ vì nguồn ảnh cuối cùng khác đi — nếu đổi, lần phân tích lại sau
         * sẽ hiểu sai là nội dung này "không thể tìm ảnh thật" và bỏ qua bước tìm kiếm.
         *
         * Thay vào đó, badge kết hợp CẢ HAI trục (nội dung có thật hay không + nguồn ảnh cuối
         * cùng là thật hay AI) để không gây hiểu lầm như "🌍 Có thật" nhưng bên dưới lại ghi
         * "Nguồn: ai_image".
         */
        function vpRealWorldBadge(shot) {
            const source = shot.resolved_source;
            const isAiSource = source === 'ai_image' || source === 'ai_video';
            const hasSource = !!source;

            if (shot.is_real_world) {
                if (hasSource && isAiSource) {
                    return `<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-sky-100 text-sky-700" title="Nội dung mô tả cảnh có thật, nhưng không tìm được ảnh/video thật phù hợp nên đã dùng ảnh AI minh họa">🌍 Có thật (ảnh AI)</span>`;
                }
                return `<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-sky-100 text-sky-700">🌍 Có thật</span>`;
            }

            if (hasSource && !isAiSource) {
                return `<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-fuchsia-100 text-fuchsia-700" title="Nội dung hư cấu/trừu tượng nhưng đang dùng ảnh/video thật để minh họa mang tính biểu tượng">🎨 Hư cấu (ảnh thật)</span>`;
            }
            return `<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-fuchsia-100 text-fuchsia-700">🎨 Hư cấu (AI)</span>`;
        }

        function vpRenderSceneCard(scene) {
            const typeLabel = SCENE_TYPE_LABELS[scene.scene_type] || scene.scene_type;
            const minutes = Math.round(scene.estimated_duration_seconds / 60 * 10) / 10;
            const shots = scene.shots || [];
            const readyCount = shots.filter(s => s.status === 'ready').length;
            const avatarCount = shots.filter(s => s.is_avatar_segment).length;

            const contentFilterActive = vpShotFilter !== 'all';
            const audioFilterActive = vpAudioFilter !== 'all';
            const filterActive = contentFilterActive || audioFilterActive;

            let matchingShots = shots;
            if (contentFilterActive) matchingShots = matchingShots.filter(vpShotMatchesFilter);
            if (audioFilterActive) matchingShots = matchingShots.filter(vpShotMatchesAudioFilter);

            // The audio filter also matches on the SCENE's own ambience/music baseline (not
            // just its shots) — a scene whose baseline needs attention must stay visible even
            // if zero individual shots match, since that baseline panel isn't a "shot" at all.
            const sceneOwnAudioMatch = vpSceneOwnAudioMatches(scene);

            // A scene with zero shots matching AND no matching baseline of its own has nothing
            // useful to show — skip it entirely instead of an empty expandable shell the user'd
            // click for nothing.
            if (filterActive && matchingShots.length === 0 && !sceneOwnAudioMatch) {
                return '';
            }

            // Filtering is specifically to help find shots/audio needing attention, so a
            // matching scene always auto-expands (no extra click) — manual expand/collapse
            // still works normally once both filters are cleared.
            const expanded = filterActive || !!vpExpandedScenes[scene.id];

            let body = '';
            if (expanded) {
                body = `<div class="px-4 py-3 border-t border-gray-100 space-y-2">`;
                body += vpRenderSceneAudioPanel(scene);
                matchingShots.forEach(shot => {
                    body += vpRenderShotCard(scene, shot);
                });
                body += `</div>`;
            }

            const badgeText = filterActive ? `${matchingShots.length} khớp lọc` : `${readyCount}/${shots.length}`;
            const badgeCls = filterActive
                ? 'bg-indigo-100 text-indigo-700'
                : (readyCount === shots.length && shots.length ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600');

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
                            <span id="vp-scene-badge-${scene.id}" class="px-2 py-0.5 rounded-full font-medium ${badgeCls}">${badgeText}</span>
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
                // Audio direction applies to EVERY shot regardless of avatar/non-avatar — an
                // avatar/host segment still belongs to a scene with its own ambience/music
                // baseline, and can independently have its own needs_sfx flagged by the AI
                // Director (e.g. a notable sound cue during the host's intro).
                body += vpRenderShotAudioPanel(scene, shot);
                body += vpRenderShotMotionPanel(scene, shot);

                const path = shot.avatar_video_path || (shot.status !== 'image_ready' ? shot.resolved_asset_path : null);
                if (path) {
                    body += `<div class="mt-1">${vpPreviewThumb(path, null, shot.updated_at)}</div>`;
                }

                body += `</div>`;
            }

            const shotAudioUrl = shot.is_avatar_segment ? shot.avatar_audio_url : shot.narration_audio_url;

            return `
                <div id="vp-shot-${shot.id}" class="border border-gray-100 rounded-lg overflow-hidden">
                    <div onclick="vpToggleShot(${shot.id})" class="w-full text-left px-3 py-2 bg-white hover:bg-gray-50 flex justify-between items-center gap-3 cursor-pointer">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-xs text-gray-400 flex-shrink-0">#${shot.shot_index}</span>
                            ${shot.is_avatar_segment ? '<span class="text-xs">🎙️</span>' : ''}
                            <span class="text-sm text-gray-700 truncate">${vpEscape(shot.sentence_text.slice(0, 70))}${shot.sentence_text.length > 70 ? '…' : ''}</span>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 text-xs text-gray-400">
                            ${shotAudioUrl ? `<button type="button" onclick="event.stopPropagation(); vpPlayAudio('${vpEscape(shotAudioUrl)}')" class="text-sm hover:text-indigo-600" title="Nghe lại giọng đọc">🔊</button>` : ''}
                            ${!shot.is_avatar_segment ? vpRealWorldBadge(shot) : ''}
                            <span>~${shot.estimated_duration_seconds}s</span>
                            ${vpStatusBadge(shot)}
                        </div>
                    </div>
                    ${body}
                </div>`;
        }

        function vpRenderAvatarSection(scene, shot) {
            if (shot.status === 'ready') {
                return `<p class="text-sm text-green-700">✅ Đã tạo clip avatar.</p>`;
            }

            const busy = vpBusyShots[shot.id] || (shot.status === 'resolving' ? 'avatar' : null);
            // shot.avatar_image_url is this shot's OWN chosen override (set via the picker);
            // falling back to the book's speaker default avatar is what lets most shots never
            // need an explicit per-shot choice — mirrors AvatarSegmentService::resolveAvatarImageUrl().
            const avatarUrl = shot.avatar_image_url || vpData.speakerAvatarUrl || null;
            const hasAudio = !!shot.avatar_audio_url;
            const canGenerate = !!avatarUrl && hasAudio;

            let html = `<div class="flex items-start gap-3 flex-wrap">`;

            html += `<div class="flex flex-col items-center gap-1 flex-shrink-0">`;
            html += avatarUrl
                ? `<img src="${vpEscape(avatarUrl)}" class="w-16 h-16 rounded-full object-cover border" />`
                : `<div class="w-16 h-16 rounded-full bg-gray-100 border flex items-center justify-center text-gray-400 text-xs">?</div>`;
            html += `<button type="button" onclick="vpOpenAvatarPicker(${scene.id}, ${shot.id})"
                class="text-xs text-purple-600 hover:text-purple-800">${avatarUrl ? 'Đổi ảnh' : 'Chọn ảnh'}</button>`;
            html += `</div>`;

            html += `<div class="flex-1 min-w-[200px] space-y-2">`;
            if (!avatarUrl) {
                html += `<p class="text-xs text-amber-600">⚠️ Chưa có ảnh MC — chọn hoặc tải ảnh lên trước.</p>`;
            }

            if (hasAudio) {
                html += `<audio controls src="${vpEscape(shot.avatar_audio_url)}" class="w-full h-8"></audio>`;
                html += `<button type="button" onclick="vpGenerateAvatarTts(${scene.id}, ${shot.id})" ${busy ? 'disabled' : ''}
                    class="text-xs bg-gray-100 hover:bg-gray-200 disabled:opacity-50 text-gray-700 font-semibold py-1 px-2 rounded-lg">
                    ${busy === 'avatar-tts' ? '⏳ Đang tạo lại giọng đọc...' : '🔄 Tạo lại giọng đọc'}
                </button>`;
            } else {
                html += `<p class="text-xs text-amber-600">⚠️ Chưa có giọng đọc cho đoạn này.</p>`;
                html += `<button type="button" onclick="vpGenerateAvatarTts(${scene.id}, ${shot.id})" ${busy ? 'disabled' : ''}
                    class="text-xs bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                    ${busy === 'avatar-tts' ? '⏳ Đang tạo giọng đọc...' : '🎤 Tạo giọng đọc (theo giọng MC đã chọn ở trên)'}
                </button>`;
            }

            html += `<div>
                <button onclick="vpGenerateAvatar(${scene.id}, ${shot.id})" ${(busy || !canGenerate) ? 'disabled' : ''}
                    title="${canGenerate ? '' : 'Cần có ảnh MC và giọng đọc trước'}"
                    class="text-sm bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                    ${busy === 'avatar' ? '⏳ Đang tạo video MC (HeyGen)...' : '🎙️ Tạo video MC (HeyGen)'}
                </button>
            </div>`;
            html += `</div></div>`;

            return html;
        }

        // ===== Bộ chọn ảnh MC (ảnh chính + ảnh bổ sung của kênh) =====
        let vpAvatarLibrary = null;
        let vpAvatarPickerTarget = null;

        async function vpOpenAvatarPicker(sceneId, shotId) {
            vpAvatarPickerTarget = { sceneId, shotId };
            if (!vpAvatarLibrary) {
                try {
                    const resp = await fetch(vpUrls.avatarLibrary, { headers: { Accept: 'application/json' } });
                    vpAvatarLibrary = await vpJson(resp);
                } catch (e) {
                    vpAvatarLibrary = { has_speaker: false, images: [] };
                }
            }
            vpOpenDetailModal('🖼️ Chọn ảnh MC', vpRenderAvatarPicker());
        }

        function vpRenderAvatarPicker() {
            const lib = vpAvatarLibrary || { has_channel: false, speakers: [], images: [] };

            if (!lib.has_channel) {
                return `<p class="text-sm text-red-600">Sách chưa gán kênh YouTube — hãy gán kênh trước khi chọn/tải ảnh MC lên.</p>`;
            }

            // Thư viện ảnh MC dùng chung cho cả KÊNH YOUTUBE (mọi MC của kênh, xem mục "MC /
            // Avatar kênh" ở trang chỉnh sửa kênh) — không tạo riêng theo từng sách, nên ảnh ở
            // đây được gắn nhãn theo MC sở hữu mỗi khi kênh có nhiều hơn 1 MC.
            const showSpeakerLabel = lib.speakers.length > 1;
            let html = '';
            if (lib.images.length) {
                html += `<div class="grid grid-cols-3 sm:grid-cols-4 gap-2 mb-4">`;
                lib.images.forEach((img) => {
                    html += `
                        <button type="button" onclick="vpPickAvatarImage('${vpEscape(img.path).replace(/'/g, "\\'")}')"
                            class="border-2 border-gray-200 hover:border-purple-400 rounded-lg overflow-hidden">
                            <img src="${vpEscape(img.url)}" class="w-full h-20 object-cover" />
                            ${img.is_primary ? '<div class="text-[10px] text-center bg-purple-100 text-purple-700 py-0.5">Mặc định</div>' : ''}
                            ${showSpeakerLabel ? `<div class="text-[10px] text-center bg-gray-100 text-gray-600 py-0.5 truncate">${vpEscape(img.speaker_name)}</div>` : ''}
                        </button>`;
                });
                html += `</div>`;
            } else {
                html += `<p class="text-sm text-gray-500 mb-4">Thư viện ảnh MC của kênh chưa có ảnh nào — tải ảnh đầu tiên lên bên dưới.</p>`;
            }

            const speakerSelect = lib.speakers.length > 1
                ? `<select id="vp-avatar-upload-speaker" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm mb-2">
                        ${lib.speakers.map(s => `<option value="${s.id}">${vpEscape(s.name)}</option>`).join('')}
                   </select>`
                : (lib.speakers.length === 1 ? `<input type="hidden" id="vp-avatar-upload-speaker" value="${lib.speakers[0].id}">` : '');

            html += `
                <div class="border-t pt-3">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Hoặc tải ảnh mới lên thư viện kênh:</label>
                    ${lib.speakers.length > 1 ? `<label class="block text-xs text-gray-500 mb-1">Thêm vào MC:</label>${speakerSelect}` : speakerSelect}
                    ${lib.speakers.length === 0 ? '<p class="text-xs text-red-600 mb-2">Kênh chưa có MC nào — hãy tạo MC ở trang quản lý kênh YouTube trước khi tải ảnh lên.</p>' : ''}
                    <input type="file" id="vp-avatar-upload-input" accept="image/*"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm mb-2" ${lib.speakers.length === 0 ? 'disabled' : ''}>
                    <button type="button" onclick="vpUploadAvatarImage()" id="vp-avatar-upload-btn" ${lib.speakers.length === 0 ? 'disabled' : ''}
                        class="text-sm bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                        📤 Tải lên &amp; Chọn
                    </button>
                    <p class="text-xs text-gray-400 mt-1">Tối đa 5MB. Ảnh sẽ được thêm vào thư viện ảnh MC chung của kênh để dùng lại cho các sách/phân đoạn khác.</p>
                </div>`;

            return html;
        }

        async function vpPickAvatarImage(path) {
            const target = vpAvatarPickerTarget;
            if (!target) return;
            try {
                await vpPost(vpUrls.avatarImageSelect(target.sceneId, target.shotId), { image_path: path });
            } catch (e) {
                alert('Lỗi chọn ảnh: ' + e.message);
                return;
            }
            vpCloseDetailModal();
            await vpRefreshAndPatchShot(target.sceneId, target.shotId);
        }

        async function vpUploadAvatarImage() {
            const target = vpAvatarPickerTarget;
            if (!target) return;

            const input = document.getElementById('vp-avatar-upload-input');
            const file = input && input.files[0];
            if (!file) {
                alert('Vui lòng chọn file ảnh.');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                alert(`Ảnh "${file.name}" vượt quá 5MB.`);
                return;
            }

            const btn = document.getElementById('vp-avatar-upload-btn');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Đang upload...'; }

            const formData = new FormData();
            formData.append('image', file);
            const speakerSelect = document.getElementById('vp-avatar-upload-speaker');
            if (speakerSelect) formData.append('speaker_id', speakerSelect.value);

            try {
                const resp = await fetch(vpUrls.avatarImageUpload(target.sceneId, target.shotId), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': vpCsrf(), Accept: 'application/json' },
                    body: formData,
                });
                const data = await resp.json();
                if (!resp.ok || !data.success) {
                    throw new Error(data.message || 'Không thể upload ảnh');
                }
                vpAvatarLibrary = null; // invalidate cache — next picker open re-fetches with the new image included
            } catch (e) {
                alert('Lỗi upload: ' + e.message);
                if (btn) { btn.disabled = false; btn.textContent = '📤 Upload & Chọn'; }
                return;
            }

            vpCloseDetailModal();
            await vpRefreshAndPatchShot(target.sceneId, target.shotId);
        }

        async function vpGenerateAvatarTts(sceneId, shotId) {
            vpBusyShots[shotId] = 'avatar-tts';
            vpPatchShotCard(sceneId, shotId);
            try {
                await vpPost(vpUrls.avatarTts(sceneId, shotId));
            } catch (e) {
                alert('Lỗi tạo giọng đọc: ' + e.message);
            }
            delete vpBusyShots[shotId];
            await vpRefreshAndPatchShot(sceneId, shotId);
        }

        function vpRenderCandidatesSection(scene, shot) {
            let html = '';
            const busy = vpBusyShots[shot.id]
                || (shot.status === 'searching' ? 'search' : (shot.status === 'resolving' ? 'resolve' : null));

            // Content-policy rejection (e.g. Grok/Flux refusing a graphic/sensitive
            // description) is NOT a technical failure — retrying with the same prompt just
            // fails the same way again. General fix: let the user edit the wording (e.g.
            // describe the scene symbolically/indirectly instead of literally) and retry.
            if (shot.status === 'content_blocked') {
                html += `<div class="p-3 bg-orange-50 border border-orange-200 rounded-lg space-y-2">
                    <p class="text-xs text-orange-700">⚠️ Ảnh bị nhà cung cấp AI từ chối do chính sách kiểm duyệt nội dung (không phải lỗi kỹ thuật). Hãy chỉnh lại mô tả ảnh bên dưới cho bớt trực diện/nhạy cảm hơn (ví dụ diễn đạt gián tiếp, tượng trưng) rồi lưu để tạo lại.</p>
                    ${shot.error_message ? `<p class="text-[11px] text-orange-500">${vpEscape(shot.error_message)}</p>` : ''}
                    <textarea id="vp-image-request-${shot.id}" rows="3"
                        class="w-full text-xs px-2 py-1.5 border border-orange-300 rounded-lg focus:border-orange-500 focus:outline-none">${vpEscape(shot.image_request || '')}</textarea>
                    <button onclick="vpSaveImageRequestAndRetry(${scene.id}, ${shot.id})" ${busy ? 'disabled' : ''}
                        class="text-xs bg-orange-600 hover:bg-orange-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                        ${busy === 'resolve' ? '⏳ Đang tạo lại...' : '💾 Lưu mô tả & tạo lại ảnh'}
                    </button>
                </div>`;
                return html;
            }

            // A finished shot ('ready' — a stock pick, or an approved static AI image) collapses
            // the candidate grid/resolve controls by default so a done shot doesn't clutter the
            // page with now-irrelevant alternatives; still one click away if the user wants to
            // review or switch the source.
            if (shot.status === 'ready' && !vpExpandedCandidates[shot.id]) {
                const selected = (shot.candidates || []).find(c => c.is_selected);
                const summary = shot.resolved_source
                    ? `Nguồn: <strong>${vpEscape(shot.resolved_source)}</strong>${selected ? ` (điểm ${selected.score_final})` : ''}`
                    : 'Đã duyệt ảnh AI tĩnh';
                return `<div class="flex items-center justify-between gap-2 text-xs text-gray-500 py-1">
                    <span>✅ ${summary}</span>
                    <button type="button" onclick="vpToggleCandidates(${scene.id}, ${shot.id})" class="text-indigo-600 hover:text-indigo-800 font-medium whitespace-nowrap">🔍 Xem/đổi nguồn khác</button>
                </div>`;
            }

            if (shot.status === 'ready') {
                html += `<div class="flex justify-end mb-1">
                    <button type="button" onclick="vpToggleCandidates(${scene.id}, ${shot.id})" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">▲ Thu gọn</button>
                </div>`;
            }

            // AI đã tạo ảnh preview (Flux hoặc Grok, tùy lựa chọn) — chờ người dùng duyệt
            // trước khi tốn phí chuyển thành video (Kling/Seedance), theo đúng yêu cầu
            // "không mặc định tạo video".
            if (shot.status === 'image_ready' && shot.resolved_asset_path) {
                html += `<div class="max-w-xs">${vpPreviewThumb(shot.resolved_asset_path, 'w-full', shot.updated_at)}</div>`;
                html += `<div class="flex gap-2 pt-2 flex-wrap">
                    <button onclick="vpApproveShot(${scene.id}, ${shot.id})" ${busy === 'approve' ? 'disabled' : ''}
                        class="text-xs bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white font-semibold py-1.5 px-3 rounded-lg">
                        ${busy === 'approve' ? '⏳ Đang duyệt...' : '✅ Duyệt ảnh này (giữ ảnh tĩnh)'}
                    </button>
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

        function vpToggleCandidates(sceneId, shotId) {
            vpExpandedCandidates[shotId] = !vpExpandedCandidates[shotId];
            vpPatchShotCard(sceneId, shotId);
        }

        function vpToggleStoryBiblePanel() {
            vpStoryBiblePanelExpanded = !vpStoryBiblePanelExpanded;
            const body = document.getElementById('vp-story-bible-panel');
            const icon = document.getElementById('vp-story-bible-toggle-icon');
            if (body) body.style.display = vpStoryBiblePanelExpanded ? '' : 'none';
            if (icon) icon.textContent = vpStoryBiblePanelExpanded ? '▲' : '▼';
        }

        function vpToggleContinuityPanel() {
            vpContinuityPanelExpanded = !vpContinuityPanelExpanded;
            const body = document.getElementById('vp-continuity-panel');
            const icon = document.getElementById('vp-continuity-toggle-icon');
            if (body) body.style.display = vpContinuityPanelExpanded ? '' : 'none';
            if (icon) icon.textContent = vpContinuityPanelExpanded ? '▲' : '▼';
        }

        function vpHintTerm(term, desc) {
            return `<div class="bg-gray-50 rounded p-2">
                <div class="font-semibold text-gray-700">${term}</div>
                <div class="text-gray-500 mt-0.5">${desc}</div>
            </div>`;
        }

        function vpShowStoryBibleHint() {
            const body = `
                <p class="text-sm text-gray-600">
                    <strong>Bộ Bối Cảnh Chuẩn</strong> là "bộ nhớ gốc" của Đạo Diễn AI cho cả cuốn sách — danh sách mốc
                    thời gian, địa điểm và nhân vật (kèm các giai đoạn thay đổi của từng người), được AI trích xuất MỘT
                    LẦN từ toàn bộ nội dung sách. Mỗi cảnh/phân đoạn khi phân tích sẽ được gán vào đúng mốc thời gian/địa
                    điểm/nhân vật trong bộ bối cảnh này, để hình ảnh/mô tả sinh ra nhất quán xuyên suốt video — không bị
                    lẫn thời gian, địa điểm hay ngoại hình nhân vật giữa các đoạn.
                </p>
                <div class="mt-3 space-y-2 text-xs">
                    ${vpHintTerm('Đã kích hoạt — vN', 'Phiên bản Bộ Bối Cảnh Chuẩn đang được dùng để gán cảnh/phân đoạn. Bấm vào Mốc thời gian/Địa điểm/Nhân vật để xem chi tiết nội dung.')}
                    ${vpHintTerm('Cảnh đã gán bối cảnh: x/y', 'Bao nhiêu cảnh đã có bối cảnh (mốc thời gian/địa điểm/nhân vật) được gán từ Bộ Bối Cảnh Chuẩn.')}
                    ${vpHintTerm('⚠️ Đã lỗi thời (cần gán lại): n', 'Số cảnh đang tham chiếu một phiên bản Bộ Bối Cảnh Chuẩn CŨ hơn bản đang active (bối cảnh vừa được cập nhật/tạo lại) — các cảnh này chưa khớp với bối cảnh mới nhất.')}
                    ${vpHintTerm('♻️ Gán lại cảnh lỗi thời', 'Gán lại bối cảnh CHỈ cho các cảnh bị lỗi thời (không đụng cảnh khác), rồi tự phân tích lại các phân đoạn liên quan. Chạy nền, có thanh tiến độ (x/tổng).')}
                </div>
                <p class="text-xs text-gray-400 mt-3">Khi nào cần bấm nút gán lại: mỗi khi thấy "Đã lỗi thời" &gt; 0, thường là sau khi Bộ Bối Cảnh Chuẩn được tạo/cập nhật.</p>
            `;
            vpOpenDetailModal('🧠 Đạo Diễn AI / Bộ Bối Cảnh Chuẩn là gì?', body);
        }

        function vpShowContinuityHint() {
            const body = `
                <p class="text-sm text-gray-600">
                    <strong>Báo Cáo Tính Nhất Quán</strong> kiểm tra CHÉO giữa những gì đã phân tích cho từng phân đoạn
                    (mốc thời gian/địa điểm/nhân vật đã gán) với nội dung Bộ Bối Cảnh Chuẩn hiện tại, để phát hiện MÂU
                    THUẪN — ví dụ phân đoạn mô tả một nhân vật/địa điểm không khớp với những gì bộ bối cảnh đã xác lập,
                    hoặc một gán bối cảnh lẽ ra phải có nhưng chưa được gán.
                </p>
                <div class="mt-3 space-y-2 text-xs">
                    ${vpHintTerm('✅ Hợp lệ / ⚠️ Cảnh báo / ❌ Không hợp lệ / — Chưa kiểm tra', 'Trạng thái kiểm tra của từng phân đoạn: khớp hoàn toàn / nghi ngờ không chắc chắn / mâu thuẫn rõ ràng / chưa từng chạy kiểm tra lần nào.')}
                    ${vpHintTerm('Chưa gán bối cảnh', 'Số phân đoạn chưa được gán vào bất kỳ mốc thời gian/địa điểm/nhân vật nào dù đáng lẽ phải có.')}
                    ${vpHintTerm('🔵 Kiểm tra toàn bộ', 'Chạy kiểm tra lại từ đầu cho MỌI cảnh trong sách (tính theo cảnh, không phải theo phân đoạn — nên khá rẻ). Dùng khi sách CHƯA từng được kiểm tra lần nào.')}
                    ${vpHintTerm('⚫ Kiểm tra lại phần lỗi thời', 'Chỉ kiểm tra lại các cảnh có phiên bản logic kiểm tra CŨ hơn hiện tại (vd sau khi cải tiến cách kiểm tra) — rẻ hơn khi sách đã kiểm tra rồi và chỉ vài cảnh bị lỗi thời.')}
                    ${vpHintTerm('🟠 Tạo lại mục đã chọn', 'Với các vấn đề Không hợp lệ/Cảnh báo đang mở mà bạn tick chọn bên dưới — tạo lại đúng phân đoạn đó rồi tự kiểm tra lại.')}
                </div>
                <p class="text-xs text-gray-400 mt-3">Danh sách vấn đề hiển thị bên dưới các nút, gom theo từng cảnh — click để xem chi tiết từng lỗi.</p>
            `;
            vpOpenDetailModal('🧭 Báo Cáo Tính Nhất Quán là gì?', body);
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

        async function vpSaveImageRequestAndRetry(sceneId, shotId) {
            const textarea = document.getElementById(`vp-image-request-${shotId}`);
            const text = textarea ? textarea.value.trim() : '';
            if (!text) {
                alert('Vui lòng nhập mô tả ảnh.');
                return;
            }

            vpBusyShots[shotId] = 'resolve';
            vpPatchShotCard(sceneId, shotId);
            try {
                await vpPost(vpUrls.imageRequest(sceneId, shotId), { image_request: text });
                await vpPost(vpUrls.resolve(sceneId, shotId), { force_ai: true });
            } catch (e) {
                alert('Lỗi: ' + e.message);
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

        async function vpApproveShot(sceneId, shotId) {
            vpBusyShots[shotId] = 'approve';
            vpPatchShotCard(sceneId, shotId);
            try {
                await vpPost(vpUrls.approve(sceneId, shotId));
            } catch (e) {
                alert('Lỗi khi duyệt: ' + e.message);
                delete vpBusyShots[shotId];
                await vpRefreshAndPatchShot(sceneId, shotId);
                return;
            }
            delete vpBusyShots[shotId];

            // Jump straight to the next shot awaiting approval instead of leaving the user to
            // scroll and hunt for it manually — this is a review queue, not a one-off action.
            await vpRefreshDataQuietly();
            vpJumpToNextPendingApproval(shotId);
        }

        // Finds the next shot still awaiting approval ('image_ready'), searching forward from
        // the shot just approved (book order), wrapping around to the start if nothing remains
        // further down — so a shot the user skipped earlier still gets surfaced eventually.
        function vpFindNextPendingApproval(afterShotId) {
            const allShots = vpAllShots();
            const idx = allShots.findIndex(({ shot }) => shot.id === afterShotId);
            const ordered = idx >= 0 ? [...allShots.slice(idx + 1), ...allShots.slice(0, idx + 1)] : allShots;
            return ordered.find(({ shot }) => shot.status === 'image_ready') || null;
        }

        function vpJumpToNextPendingApproval(justApprovedShotId) {
            const next = vpFindNextPendingApproval(justApprovedShotId);
            if (!next) {
                vpShowApprovalCompleteModal();
                return;
            }

            vpExpandedScenes[next.scene.id] = true;
            vpExpandedShots[next.shot.id] = true;
            vpRender();

            const el = document.getElementById('vp-shot-' + next.shot.id);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function vpShowApprovalCompleteModal() {
            vpOpenDetailModal('🎉 Hoàn tất', `
                <div class="text-center py-6">
                    <p class="text-5xl mb-3">🎉</p>
                    <p class="text-lg font-semibold text-gray-800 mb-1">Đã duyệt xong toàn bộ ảnh!</p>
                    <p class="text-sm text-gray-500">Không còn phân đoạn nào đang chờ duyệt trong tác phẩm này.</p>
                </div>
            `);
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
                    alert('Lỗi khi lấy từ Thư viện cho phân đoạn #' + shot.shot_index + ' (cảnh #' + scene.scene_index + '): ' + e.message);
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
        //
        // Chạy bằng job nền (BulkGenerateShotImagesJob) chứ KHÔNG phải vòng lặp JS phía trình
        // duyệt như trước — vòng lặp cũ chết âm thầm ngay khi đóng tab/chuyển trang/máy sleep,
        // không để lại dấu vết, nên trông giống hệt "không chạy gì cả" dù thật ra một phần đã
        // xử lý xong. Giờ chỉ cần gọi job 1 lần, tiến độ đã có sẵn qua vpLoad() polling định kỳ
        // (vpData.pipeline.bulk_ai_generate_status) — tự tiếp tục kể cả khi tải lại trang.
        async function vpAutoRunAI() {
            if (vpBulkAiRunning()) return;

            try {
                await vpPost(vpUrls.bulkGenerateAi);
            } catch (e) {
                alert('Lỗi khi bắt đầu tạo AI hàng loạt: ' + e.message);
                return;
            }

            await vpRefreshDataQuietly();
            vpPatchAutoRunButtons();
        }

        vpInit();
    </script>
@endsection
