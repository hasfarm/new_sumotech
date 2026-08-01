let cfg = null;
let activeTarget = null;
let manualShotId = null;
let activeAudioTarget = null;
let mode = 'video';

const AUDIO_SLOT_LABELS = { sfx: 'SFX', ambience: 'Ambience', music: 'Music' };

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function api(path, opts = {}) {
    const res = await fetch(cfg.baseUrl + path, {
        ...opts,
        headers: {
            Authorization: 'Bearer ' + cfg.token,
            Accept: 'application/json',
            ...(opts.headers || {}),
        },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) {
        throw new Error(data.message || ('HTTP ' + res.status));
    }
    return data;
}

function renderTargetBox() {
    const box = document.getElementById('targetBox');
    if (manualShotId) {
        box.innerHTML = `<div class="txt">Đã chọn thủ công: shot #${manualShotId}</div>`;
        return;
    }
    if (!activeTarget) {
        box.innerHTML = `<div class="txt">Chưa có shot nào đang chọn — hãy bấm 1 từ khóa 🔍 trong app trước, hoặc chọn thủ công bên dưới.</div>`;
        return;
    }
    box.innerHTML = `<div class="kw">🔍 ${escapeHtml(activeTarget.keyword || '')}</div>
        <div class="txt"><strong>${escapeHtml(activeTarget.audio_book_title || '')}</strong><br>${escapeHtml((activeTarget.shot_text || '').slice(0, 90))}</div>`;
}

async function loadActiveTarget() {
    try {
        const data = await api('/api/extension/active-target');
        activeTarget = data.target;
    } catch (e) {
        activeTarget = null;
    }
    renderTargetBox();
}

function renderAudioTargetBox() {
    const box = document.getElementById('audioTargetBox');
    if (!activeAudioTarget) {
        box.innerHTML = `<div class="txt">Chưa có audio slot nào đang chọn — hãy bấm "🔍 Tìm trên Storyblocks" ở đúng slot (ambience/music/sfx) trong app trước.</div>`;
        return;
    }
    const t = activeAudioTarget;
    const scope = t.target_type === 'scene' ? `Cảnh #${t.scene_id}` : `Shot #${t.shot_id} (cảnh #${t.scene_id})`;
    const slotLabel = AUDIO_SLOT_LABELS[t.slot] || t.slot;
    box.innerHTML = `<div class="kw">🎵 ${slotLabel} — ${escapeHtml(scope)}</div>
        <div class="txt"><strong>${escapeHtml(t.audio_book_title || '')}</strong><br>${escapeHtml((t.prompt || '').slice(0, 100))}</div>`;
}

async function loadActiveAudioTarget() {
    try {
        const data = await api('/api/extension/active-audio-target');
        activeAudioTarget = data.target;
    } catch (e) {
        activeAudioTarget = null;
    }
    renderAudioTargetBox();
}

function setMode(m) {
    mode = m;
    document.getElementById('modeVideo').classList.toggle('active', m === 'video');
    document.getElementById('modeAudio').classList.toggle('active', m === 'audio');
    document.getElementById('videoPanel').style.display = m === 'video' ? 'block' : 'none';
    document.getElementById('audioPanel').style.display = m === 'audio' ? 'block' : 'none';
    document.getElementById('modeTitle').textContent = m === 'video' ? '📤 Gửi video về Video Pipeline' : '📤 Gửi audio về Video Pipeline';
    document.getElementById('msg').textContent = '';
}

async function loadBooks() {
    const data = await api('/api/extension/books');
    const sel = document.getElementById('bookSelect');
    sel.innerHTML = '<option value="">-- Chọn sách --</option>'
        + data.books.map((b) => `<option value="${b.id}">${escapeHtml(b.title)}</option>`).join('');
}

async function loadShots(bookId) {
    const shotSel = document.getElementById('shotSelect');
    if (!bookId) {
        shotSel.innerHTML = '<option value="">-- Chọn sách trước --</option>';
        return;
    }
    shotSel.innerHTML = '<option value="">-- Đang tải --</option>';
    const data = await api('/api/extension/books/' + bookId + '/shots');
    const opts = [];
    (data.scenes || []).forEach((scene) => {
        (scene.shots || []).forEach((shot) => {
            if (shot.is_avatar_segment) return;
            const label = `#${scene.scene_index}.${shot.shot_index} [${shot.status}] ${(shot.sentence_text || '').slice(0, 45)}`;
            opts.push(`<option value="${shot.id}">${escapeHtml(label)}</option>`);
        });
    });
    shotSel.innerHTML = '<option value="">-- Chọn shot --</option>' + opts.join('');
}

document.getElementById('toggle').addEventListener('click', async () => {
    const manual = document.getElementById('manual');
    const show = manual.style.display !== 'block';
    manual.style.display = show ? 'block' : 'none';
    if (show) {
        try {
            await loadBooks();
        } catch (e) {
            document.getElementById('msg').textContent = 'Lỗi tải danh sách sách: ' + e.message;
            document.getElementById('msg').className = 'err';
        }
    }
});

document.getElementById('bookSelect').addEventListener('change', async (e) => {
    try {
        await loadShots(e.target.value);
    } catch (e2) {
        document.getElementById('msg').textContent = 'Lỗi tải danh sách shot: ' + e2.message;
        document.getElementById('msg').className = 'err';
    }
});

document.getElementById('shotSelect').addEventListener('change', (e) => {
    manualShotId = e.target.value || null;
    renderTargetBox();
});

document.getElementById('sendBtn').addEventListener('click', async () => {
    const msg = document.getElementById('msg');
    msg.textContent = '';
    msg.className = '';

    const shotId = manualShotId || (activeTarget && activeTarget.shot_id);
    const file = document.getElementById('fileInput').files[0];

    if (!shotId) {
        msg.textContent = 'Chưa xác định được shot đích.';
        msg.className = 'err';
        return;
    }
    if (!file) {
        msg.textContent = 'Chưa chọn file video.';
        msg.className = 'err';
        return;
    }

    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Đang gửi...';

    try {
        const form = new FormData();
        form.append('video', file);
        await api('/api/extension/shots/' + shotId + '/ingest', { method: 'POST', body: form });
        msg.textContent = '✅ Đã gửi thành công!';
        msg.className = 'ok';
    } catch (e) {
        msg.textContent = '❌ Lỗi: ' + e.message;
        msg.className = 'err';
    }

    btn.disabled = false;
    btn.textContent = '📤 Gửi video về dự án';
});

document.getElementById('sendAudioBtn').addEventListener('click', async () => {
    const msg = document.getElementById('msg');
    msg.textContent = '';
    msg.className = '';

    const file = document.getElementById('audioFileInput').files[0];

    if (!activeAudioTarget) {
        msg.textContent = 'Chưa xác định được audio slot đích.';
        msg.className = 'err';
        return;
    }
    if (!file) {
        msg.textContent = 'Chưa chọn file audio.';
        msg.className = 'err';
        return;
    }

    const btn = document.getElementById('sendAudioBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Đang gửi...';

    try {
        const form = new FormData();
        form.append('audio', file);
        await api('/api/extension/audio/ingest', { method: 'POST', body: form });
        msg.textContent = '✅ Đã gửi thành công! Vào lại app để duyệt (Approve/Lock) trước khi dùng.';
        msg.className = 'ok';
    } catch (e) {
        msg.textContent = '❌ Lỗi: ' + e.message;
        msg.className = 'err';
    }

    btn.disabled = false;
    btn.textContent = '📤 Gửi audio về dự án';
});

// Manual tab switches always refetch — the user may have gone back to the web page and set a
// newer target since the popup last loaded, so re-showing cached data here could be stale.
document.getElementById('modeVideo').addEventListener('click', () => { setMode('video'); loadActiveTarget().catch(() => {}); });
document.getElementById('modeAudio').addEventListener('click', () => { setMode('audio'); loadActiveAudioTarget().catch(() => {}); });

document.getElementById('openOptions').addEventListener('click', () => {
    chrome.runtime.openOptionsPage();
});

(function init() {
    chrome.storage.sync.get(['baseUrl', 'token'], async (data) => {
        if (!data.baseUrl || !data.token) {
            document.getElementById('noconfig').style.display = 'block';
            return;
        }
        cfg = { baseUrl: data.baseUrl.replace(/\/+$/, ''), token: data.token };
        document.getElementById('main').style.display = 'block';
        document.getElementById('modeTabs').style.display = 'block';

        // Load BOTH targets up front and auto-pick whichever tab (Video/Audio) was actually
        // just requested by set_at timestamp, instead of always defaulting to Video — with two
        // separate targets (one per mode) and no way for the popup to know which one the user
        // just triggered, always opening on Video made the extension look like it "didn't
        // know" the audio target even when it had been set correctly server-side.
        const [videoResult, audioResult] = await Promise.all([
            api('/api/extension/active-target').catch(() => ({ target: null })),
            api('/api/extension/active-audio-target').catch(() => ({ target: null })),
        ]);
        activeTarget = videoResult.target;
        activeAudioTarget = audioResult.target;

        const videoSetAt = activeTarget && activeTarget.set_at ? Date.parse(activeTarget.set_at) : 0;
        const audioSetAt = activeAudioTarget && activeAudioTarget.set_at ? Date.parse(activeAudioTarget.set_at) : 0;

        setMode(audioSetAt > videoSetAt ? 'audio' : 'video');
        renderTargetBox();
        renderAudioTargetBox();
    });
})();
