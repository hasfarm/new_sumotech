@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800">📋 Queue Monitor</h1>
        <div class="flex gap-2">
            <button onclick="refreshQueue()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                🔄 Refresh
            </button>
            <button onclick="clearAllJobs()" class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700">
                🗑 Clear All Jobs
            </button>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-4 gap-4 mb-6" id="summary-cards">
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-yellow-400">
            <div class="text-sm text-gray-500">Running</div>
            <div class="text-2xl font-bold text-yellow-600" id="count-running">-</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-400">
            <div class="text-sm text-gray-500">Pending</div>
            <div class="text-2xl font-bold text-blue-600" id="count-pending">-</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-red-400">
            <div class="text-sm text-gray-500">Failed</div>
            <div class="text-2xl font-bold text-red-600" id="count-failed">-</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-gray-400">
            <div class="text-sm text-gray-500">Total</div>
            <div class="text-2xl font-bold text-gray-700" id="count-total">-</div>
        </div>
    </div>

    {{-- Running Jobs --}}
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-4 py-3 border-b bg-yellow-50">
            <h2 class="font-semibold text-yellow-800">⚡ Running</h2>
        </div>
        <div class="p-4" id="running-list">
            <div class="text-gray-400 text-sm">Loading...</div>
        </div>
    </div>

    {{-- Pending Jobs --}}
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-4 py-3 border-b bg-blue-50">
            <h2 class="font-semibold text-blue-800">🕐 Pending</h2>
        </div>
        <div class="p-4" id="pending-list">
            <div class="text-gray-400 text-sm">Loading...</div>
        </div>
    </div>

    {{-- Failed Jobs --}}
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-4 py-3 border-b bg-red-50">
            <h2 class="font-semibold text-red-800">❌ Failed</h2>
        </div>
        <div class="p-4" id="failed-list">
            <div class="text-gray-400 text-sm">Loading...</div>
        </div>
    </div>

    {{-- Job History --}}
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-4 py-3 border-b bg-green-50">
            <h2 class="font-semibold text-green-800">📜 Lịch sử Jobs</h2>
        </div>
        <div class="p-4" id="history-list">
            <div class="text-gray-400 text-sm">Loading...</div>
        </div>
    </div>
</div>

<script>
    let autoRefreshTimer = null;

    async function refreshQueue() {
        try {
            const resp = await fetch('{{ route("dubsync.queue.status") }}');
            const data = await resp.json();
            if (!data.success) throw new Error(data.error);

            document.getElementById('count-running').textContent = data.summary.running;
            document.getElementById('count-pending').textContent = data.summary.pending;
            document.getElementById('count-failed').textContent = data.summary.failed;
            document.getElementById('count-total').textContent = data.summary.total;

            renderRunning('running-list', data.running);
            renderJobs('pending-list', data.pending, 'blue');
            renderFailed('failed-list', data.failed);
            renderHistory('history-list', data.history || []);

            clearInterval(autoRefreshTimer);
            if (data.summary.running > 0) {
                autoRefreshTimer = setInterval(refreshQueue, 5000);
            }
        } catch (e) {
            console.error('Queue refresh error:', e);
        }
    }

    function formatDuration(seconds) {
        if (!seconds && seconds !== 0) return '-';
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
        return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
    }

    function renderRunning(containerId, jobs) {
        const el = document.getElementById(containerId);
        if (!jobs || jobs.length === 0) {
            el.innerHTML = '<div class="text-gray-400 text-sm">Không có job đang chạy</div>';
            return;
        }
        el.innerHTML = jobs.map(j => {
            const p = j.progress;
            const pct = p ? (p.percent || 0) : 0;
            const msg = p ? (p.message || '') : '';
            const targetLabel = j.target_id ? `<span class="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded">${j.target_type || 'id'}: ${j.target_id}</span>` : '';

            return `<div class="border border-yellow-200 rounded-lg p-4 mb-3 bg-yellow-50/50">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-yellow-700 text-sm">#${j.id}</span>
                        <span class="font-semibold">${j.job}</span>
                        ${targetLabel}
                        <span class="text-xs text-gray-400">${j.queue}</span>
                    </div>
                    <div class="text-xs text-gray-500">
                        Started: ${j.reserved_at || '-'}
                    </div>
                </div>
                ${p ? `
                <div class="mb-1">
                    <div class="flex justify-between text-xs text-gray-600 mb-1">
                        <span>${msg}</span>
                        <span class="font-medium">${pct}%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div class="h-2.5 rounded-full transition-all duration-500 ${pct >= 100 ? 'bg-green-500' : 'bg-yellow-500'}" style="width: ${pct}%"></div>
                    </div>
                </div>
                ` : '<div class="text-xs text-gray-400">Đang xử lý... (không có progress data)</div>'}
            </div>`;
        }).join('');
    }

    function renderJobs(containerId, jobs, color) {
        const el = document.getElementById(containerId);
        if (!jobs || jobs.length === 0) {
            el.innerHTML = '<div class="text-gray-400 text-sm">Không có job nào</div>';
            return;
        }
        el.innerHTML = `<table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b">
                <th class="pb-2 w-16">#ID</th>
                <th class="pb-2">Job</th>
                <th class="pb-2 w-24">Target</th>
                <th class="pb-2 w-20">Attempts</th>
                <th class="pb-2 w-32">Created</th>
            </tr></thead>
            <tbody>${jobs.map(j => `
                <tr class="border-b border-gray-100 hover:bg-${color}-50">
                    <td class="py-2 font-mono text-${color}-700">#${j.id}</td>
                    <td class="py-2 font-medium">${j.job} <span class="text-xs text-gray-400">${j.queue}</span></td>
                    <td class="py-2">${j.target_id ? `<span class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">${j.target_type}: ${j.target_id}</span>` : '-'}</td>
                    <td class="py-2 text-center">${j.attempts}</td>
                    <td class="py-2 text-gray-500">${j.created_at || '-'}</td>
                </tr>
            `).join('')}</tbody>
        </table>`;
    }

    function renderFailed(containerId, jobs) {
        const el = document.getElementById(containerId);
        if (!jobs || jobs.length === 0) {
            el.innerHTML = '<div class="text-gray-400 text-sm">Không có job failed</div>';
            return;
        }
        el.innerHTML = `<table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b">
                <th class="pb-2 w-16">#ID</th>
                <th class="pb-2">Job</th>
                <th class="pb-2">Error</th>
                <th class="pb-2 w-36">Failed At</th>
            </tr></thead>
            <tbody>${jobs.map(j => `
                <tr class="border-b border-gray-100 hover:bg-red-50">
                    <td class="py-2 font-mono text-red-700">#${j.id}</td>
                    <td class="py-2 font-medium">${j.job}</td>
                    <td class="py-2 text-red-600 text-xs max-w-md truncate" title="${(j.error||'').replace(/"/g, '&quot;')}">${j.error || '-'}</td>
                    <td class="py-2 text-gray-500">${j.failed_at || '-'}</td>
                </tr>
            `).join('')}</tbody>
        </table>`;
    }

    function renderHistory(containerId, items) {
        const el = document.getElementById(containerId);
        if (!items || items.length === 0) {
            el.innerHTML = '<div class="text-gray-400 text-sm">Chưa có lịch sử</div>';
            return;
        }
        el.innerHTML = `<table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b">
                <th class="pb-2">Job</th>
                <th class="pb-2 w-24">Target</th>
                <th class="pb-2 w-20">Status</th>
                <th class="pb-2 w-24">Duration</th>
                <th class="pb-2">Message</th>
                <th class="pb-2 w-36">Started</th>
                <th class="pb-2 w-36">Finished</th>
            </tr></thead>
            <tbody>${items.map(h => {
                const statusColor = h.status === 'completed' ? 'green' : 'red';
                const statusIcon = h.status === 'completed' ? '✅' : '❌';
                return `
                <tr class="border-b border-gray-100 hover:bg-gray-50">
                    <td class="py-2 font-medium">${h.job}</td>
                    <td class="py-2">${h.target_id ? `<span class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">${h.target_type}: ${h.target_id}</span>` : '-'}</td>
                    <td class="py-2"><span class="text-${statusColor}-600 text-xs font-medium">${statusIcon} ${h.status}</span></td>
                    <td class="py-2 font-mono text-gray-600">${formatDuration(h.duration_seconds)}</td>
                    <td class="py-2 text-xs text-gray-500 max-w-xs truncate" title="${(h.message||'').replace(/"/g, '&quot;')}">${h.message || '-'}</td>
                    <td class="py-2 text-gray-500 text-xs">${h.started_at || '-'}</td>
                    <td class="py-2 text-gray-500 text-xs">${h.finished_at || '-'}</td>
                </tr>`;
            }).join('')}</tbody>
        </table>`;
    }

    async function clearAllJobs() {
        if (!confirm('Xóa tất cả jobs (pending + failed)?')) return;
        try {
            const resp = await fetch('{{ route("dubsync.queue.clear") }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
            });
            const data = await resp.json();
            if (data.success) {
                alert(`Đã xóa ${data.cleared_pending} pending + ${data.cleared_failed} failed jobs`);
                refreshQueue();
            }
        } catch (e) {
            alert('Lỗi: ' + e.message);
        }
    }

    refreshQueue();
</script>
@endsection
