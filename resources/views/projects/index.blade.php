@extends('layouts.app')

@section('content')
    <div class="py-12" style="background-color: #f0f0f0; min-height: 400px;">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Debug info -->
            <div class="mb-4 p-4 bg-yellow-100 border border-yellow-400 rounded">
                <strong>Debug:</strong> Projects count = {{ $projects->total() ?? 'N/A' }} |
                Current page items = {{ $projects->count() ?? 'N/A' }} |
                User ID = {{ auth()->id() ?? 'NOT LOGGED IN' }}
            </div>

            <div class="flex justify-between items-center mb-6">
                <h2 class="font-semibold text-2xl text-gray-800">
                    {{ __('Projects Management') }}
                </h2>
                <a href="{{ route('projects.create') }}"
                    class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                    + New Project
                </a>
            </div>

            @if ($message = Session::get('success'))
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    <span class="block sm:inline">{{ $message }}</span>
                    <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3"
                        onclick="this.parentElement.style.display='none';">
                        <span class="text-2xl leading-none">&times;</span>
                    </button>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if ($projects->count() > 0)
                        <form id="bulkDeleteForm" action="{{ route('projects.bulk.destroy') }}" method="POST" class="hidden">
                            @csrf
                            <div id="bulkDeleteIdsContainer"></div>
                        </form>

                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-4">
                                <div class="text-sm text-gray-600">View:</div>
                                <div class="inline-flex rounded-lg border border-gray-200 overflow-hidden">
                                    <button id="listViewBtn"
                                        class="px-4 py-2 text-sm font-medium bg-white text-gray-700 hover:bg-gray-50">
                                        List
                                    </button>
                                    <button id="cardViewBtn"
                                        class="px-4 py-2 text-sm font-medium bg-gray-100 text-gray-900 hover:bg-gray-200">
                                        Cards
                                    </button>
                                </div>

                                <div class="flex items-center gap-2 ml-2">
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                                        <input type="checkbox" id="selectAllProjectsCheckbox" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                                        <span>Chọn tất cả</span>
                                    </label>
                                    <button type="button" id="bulkDownloadBtn"
                                        class="px-3 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
                                        disabled>
                                        Download selected videos (0)
                                    </button>
                                    <button type="button" id="bulkDeleteBtn"
                                        class="px-3 py-2 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
                                        disabled>
                                        Delete selected (0)
                                    </button>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <label for="perPageSelect" class="text-sm text-gray-600">Items per page:</label>
                                <select id="perPageSelect"
                                    class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
                                    <option value="10" @if (request('per_page') == 10 || request('per_page') === null) selected @endif>10</option>
                                    <option value="15" @if (request('per_page') == 15) selected @endif>15</option>
                                    <option value="25" @if (request('per_page') == 25) selected @endif>25</option>
                                    <option value="50" @if (request('per_page') == 50) selected @endif>50</option>
                                    <option value="100" @if (request('per_page') == 100) selected @endif>100
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div id="bulkDownloadProgressPanel" class="hidden mb-4 border border-blue-200 bg-blue-50 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="text-sm font-semibold text-blue-900">Bulk Download Progress</h4>
                                <span id="bulkDownloadSummary" class="text-xs text-blue-700">Waiting...</span>
                            </div>
                            <div id="bulkDownloadProgressList" class="space-y-2"></div>
                        </div>

                        <!-- Card View (default) -->
                        <div id="projectsCardView" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach ($projects as $project)
                                <div class="project-item bg-white border border-gray-200 rounded-lg overflow-hidden shadow hover:shadow-lg transition duration-300"
                                    data-project-id="{{ $project->id }}">
                                    <!-- Thumbnail -->
                                    <div class="relative h-40 bg-gray-200 overflow-hidden">
                                        <div class="absolute top-3 left-3 z-10 bg-white/90 rounded px-2 py-1 shadow">
                                            <input type="checkbox" class="project-select-checkbox rounded border-gray-300 text-red-600 focus:ring-red-500"
                                                value="{{ $project->id }}"
                                                data-project-title="{{ $project->youtube_title_vi ?? $project->youtube_title ?? $project->video_id }}"
                                                aria-label="Select project {{ $project->id }}">
                                        </div>

                                        @if ($project->youtube_thumbnail)
                                            <img src="{{ $project->youtube_thumbnail }}" alt="Project thumbnail"
                                                class="w-full h-full object-cover">
                                        @elseif ($project->thumbnail_path)
                                            <img src="{{ asset($project->thumbnail_path) }}" alt="Project thumbnail"
                                                class="w-full h-full object-cover">
                                        @else
                                            <div
                                                class="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-300 to-gray-400">
                                                <svg class="w-12 h-12 text-gray-600" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z">
                                                    </path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                            </div>
                                        @endif

                                        <!-- Status Badge -->
                                        <div class="absolute top-3 right-3">
                                            <span
                                                class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                @switch($project->status)
                                                    @case('pending')
                                                        bg-gray-100 text-gray-800
                                                        @break
                                                    @case('processing')
                                                        bg-blue-100 text-blue-800
                                                        @break
                                                    @case('transcribed')
                                                        bg-cyan-100 text-cyan-800
                                                        @break
                                                    @case('translated')
                                                        bg-indigo-100 text-indigo-800
                                                        @break
                                                    @case('tts_generated')
                                                        bg-purple-100 text-purple-800
                                                        @break
                                                    @case('aligned')
                                                        bg-pink-100 text-pink-800
                                                        @break
                                                    @case('merged')
                                                        bg-orange-100 text-orange-800
                                                        @break
                                                    @case('completed')
                                                        bg-green-100 text-green-800
                                                        @break
                                                    @default
                                                        bg-gray-100 text-gray-800
                                                @endswitch">
                                                {{ ucfirst(str_replace('_', ' ', $project->status)) }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Content -->
                                    <div class="p-4">
                                        <h3 class="text-sm font-semibold text-gray-900 truncate mb-2">
                                            {{ $project->youtube_title_vi ?? $project->youtube_title ?? $project->video_id }}
                                        </h3>

                                        <p class="text-xs text-gray-600 mb-2 max-h-10 overflow-hidden">
                                            {{ $project->youtube_description ?? 'No description available.' }}
                                        </p>

                                        <p class="text-xs text-gray-500 mb-3">
                                            <span class="font-medium">Video ID:</span> {{ $project->video_id }}
                                            @if ($project->youtube_duration)
                                                <span class="ml-2 font-medium">Duration:</span>
                                                {{ $project->youtube_duration }}
                                            @endif
                                        </p>

                                        <p class="text-xs text-gray-500 mb-4">
                                            <span class="font-medium">Created:</span>
                                            {{ $project->created_at->format('d/m/Y H:i') }}
                                        </p>

                                        <p class="text-xs text-gray-600 mb-3 truncate">
                                            <a href="{{ $project->youtube_url }}" target="_blank" rel="noopener noreferrer"
                                                class="text-red-600 hover:text-red-800 hover:underline">
                                                View on YouTube
                                            </a>
                                        </p>

                                        <div class="flex gap-2 pt-3 border-t border-gray-200">
                                            <a href="{{ route('projects.show', $project->id) }}"
                                                class="flex-1 text-center text-sm px-3 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition">
                                                View
                                            </a>
                                            <a href="{{ route('projects.edit', $project->id) }}"
                                                class="flex-1 text-center text-sm px-3 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 transition">
                                                Edit
                                            </a>
                                            <form action="{{ route('projects.destroy', $project->id) }}" method="POST"
                                                class="delete-project-form flex-1"
                                                onsubmit="return confirm('Are you sure?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                    class="w-full text-sm px-3 py-2 bg-red-500 text-white rounded hover:bg-red-600 transition">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <!-- List View -->
                        <div id="projectsListView" class="hidden space-y-3">
                            @foreach ($projects as $project)
                                <div class="project-item bg-white border border-gray-200 rounded-lg p-4 flex flex-wrap gap-4"
                                    data-project-id="{{ $project->id }}">
                                    <div class="flex items-start pt-1">
                                        <input type="checkbox" class="project-select-checkbox rounded border-gray-300 text-red-600 focus:ring-red-500"
                                            value="{{ $project->id }}"
                                            data-project-title="{{ $project->youtube_title_vi ?? $project->youtube_title ?? $project->video_id }}"
                                            aria-label="Select project {{ $project->id }}">
                                    </div>

                                    <div class="w-40 h-24 bg-gray-200 rounded overflow-hidden flex-shrink-0">
                                        @if ($project->youtube_thumbnail)
                                            <img src="{{ $project->youtube_thumbnail }}" alt="Project thumbnail"
                                                class="w-full h-full object-cover">
                                        @else
                                            <div
                                                class="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-300 to-gray-400">
                                                <svg class="w-10 h-10 text-gray-600" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z">
                                                    </path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <h3 class="text-sm font-semibold text-gray-900 truncate">
                                                    {{ $project->youtube_title_vi ?? $project->youtube_title ?? 'No title available.' }}
                                                </h3>
                                                <p class="text-xs text-gray-600 mt-1 max-h-10 overflow-hidden">
                                                    {{ $project->youtube_description ?? 'No description available.' }}
                                                </p>
                                            </div>
                                            <span
                                                class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full flex-shrink-0
                                                @switch($project->status)
                                                    @case('pending')
                                                        bg-gray-100 text-gray-800
                                                        @break
                                                    @case('processing')
                                                        bg-blue-100 text-blue-800
                                                        @break
                                                    @case('transcribed')
                                                        bg-cyan-100 text-cyan-800
                                                        @break
                                                    @case('translated')
                                                        bg-indigo-100 text-indigo-800
                                                        @break
                                                    @case('tts_generated')
                                                        bg-purple-100 text-purple-800
                                                        @break
                                                    @case('aligned')
                                                        bg-pink-100 text-pink-800
                                                        @break
                                                    @case('merged')
                                                        bg-orange-100 text-orange-800
                                                        @break
                                                    @case('completed')
                                                        bg-green-100 text-green-800
                                                        @break
                                                    @default
                                                        bg-gray-100 text-gray-800
                                                @endswitch">
                                                {{ ucfirst(str_replace('_', ' ', $project->status)) }}
                                            </span>
                                        </div>

                                        <p class="text-xs text-gray-500 mt-2">
                                            <span class="font-medium">Video ID:</span> {{ $project->video_id }}
                                            @if ($project->youtube_duration)
                                                <span class="ml-2 font-medium">Duration:</span>
                                                {{ $project->youtube_duration }}
                                            @endif
                                            <span class="ml-2 font-medium">Created:</span>
                                            {{ $project->created_at->format('d/m/Y H:i') }}
                                        </p>

                                        <p class="text-xs text-gray-600 mt-1 truncate">
                                            <a href="{{ $project->youtube_url }}" target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-red-600 hover:text-red-800 hover:underline">
                                                View on YouTube
                                            </a>
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('projects.show', $project->id) }}"
                                            class="text-sm px-3 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition">
                                            View
                                        </a>
                                        <a href="{{ route('projects.edit', $project->id) }}"
                                            class="text-sm px-3 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 transition">
                                            Edit
                                        </a>
                                        <form action="{{ route('projects.destroy', $project->id) }}" method="POST"
                                            class="delete-project-form" onsubmit="return confirm('Are you sure?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                class="text-sm px-3 py-2 bg-red-500 text-white rounded hover:bg-red-600 transition">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-6">
                            {{ $projects->links() }}
                        </div>
                    @else
                        <div class="text-center py-12">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 13h6m-3-3v6m-9 1V5a2 2 0 012-2h6.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2H5a2 2 0 01-2-2z">
                                </path>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No projects</h3>
                            <p class="mt-1 text-sm text-gray-500">Get started by creating a new project.</p>
                            <div class="mt-6">
                                <a href="{{ route('projects.create') }}"
                                    class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
                                    <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    New Project
                                </a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            // Handle per_page selection
            const perPageSelect = document.getElementById('perPageSelect');
            if (perPageSelect) {
                perPageSelect.addEventListener('change', function() {
                    const perPage = this.value;
                    const url = new URL(window.location);
                    url.searchParams.set('per_page', perPage);
                    url.searchParams.set('page', 1); // Reset to page 1
                    window.location.href = url.toString();
                });
            }

            // Single delete uses standard form submit so Laravel can handle redirect + flash messages.

            const selectAllProjectsCheckbox = document.getElementById('selectAllProjectsCheckbox');
            const bulkDownloadBtn = document.getElementById('bulkDownloadBtn');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const bulkDeleteForm = document.getElementById('bulkDeleteForm');
            const bulkDeleteIdsContainer = document.getElementById('bulkDeleteIdsContainer');
            const bulkDownloadProgressPanel = document.getElementById('bulkDownloadProgressPanel');
            const bulkDownloadProgressList = document.getElementById('bulkDownloadProgressList');
            const bulkDownloadSummary = document.getElementById('bulkDownloadSummary');

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            let isBulkDownloading = false;

            const getAllProjectCheckboxes = () => Array.from(document.querySelectorAll('.project-select-checkbox'));

            const getUniqueProjectIds = () => {
                const ids = new Set();
                getAllProjectCheckboxes().forEach((checkbox) => {
                    ids.add(String(checkbox.value));
                });
                return ids;
            };

            const getSelectedProjectIds = () => {
                const ids = new Set();
                getAllProjectCheckboxes().forEach((checkbox) => {
                    if (checkbox.checked) {
                        ids.add(String(checkbox.value));
                    }
                });
                return Array.from(ids);
            };

            const getSelectedProjectInfos = () => {
                const selectedIds = getSelectedProjectIds();
                return selectedIds.map((id) => {
                    const checkbox = document.querySelector(`.project-select-checkbox[value="${id}"]`);
                    const rawTitle = checkbox?.dataset?.projectTitle || `Project #${id}`;
                    return {
                        id,
                        title: rawTitle.trim() || `Project #${id}`,
                    };
                });
            };

            const escapeHtml = (value) => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const updateBulkDeleteState = () => {
                if (!bulkDeleteBtn) return;

                const selectedIds = getSelectedProjectIds();
                const allIds = Array.from(getUniqueProjectIds());
                const selectedCount = selectedIds.length;
                const allCount = allIds.length;

                bulkDeleteBtn.disabled = selectedCount === 0 || isBulkDownloading;
                bulkDeleteBtn.textContent = `Delete selected (${selectedCount})`;

                if (bulkDownloadBtn) {
                    bulkDownloadBtn.disabled = selectedCount === 0 || isBulkDownloading;
                    bulkDownloadBtn.textContent = isBulkDownloading
                        ? `Downloading... (${selectedCount})`
                        : `Download selected videos (${selectedCount})`;
                }

                if (selectAllProjectsCheckbox) {
                    selectAllProjectsCheckbox.checked = allCount > 0 && selectedCount === allCount;
                    selectAllProjectsCheckbox.indeterminate = selectedCount > 0 && selectedCount < allCount;
                }
            };

            getAllProjectCheckboxes().forEach((checkbox) => {
                checkbox.addEventListener('change', function() {
                    const id = String(this.value);
                    const checked = this.checked;

                    // Keep card/list checkboxes for the same project in sync.
                    document.querySelectorAll(`.project-select-checkbox[value="${id}"]`).forEach((el) => {
                        el.checked = checked;
                    });

                    updateBulkDeleteState();
                });
            });

            if (selectAllProjectsCheckbox) {
                selectAllProjectsCheckbox.addEventListener('change', function() {
                    const checked = this.checked;
                    getAllProjectCheckboxes().forEach((checkbox) => {
                        checkbox.checked = checked;
                    });
                    updateBulkDeleteState();
                });
            }

            if (bulkDeleteBtn) {
                bulkDeleteBtn.addEventListener('click', function() {
                    const selectedIds = getSelectedProjectIds();
                    if (selectedIds.length === 0 || !bulkDeleteForm || !bulkDeleteIdsContainer) {
                        return;
                    }

                    if (!confirm(`Bạn có chắc muốn xóa ${selectedIds.length} project đã chọn?`)) {
                        return;
                    }

                    bulkDeleteIdsContainer.innerHTML = '';
                    selectedIds.forEach((id) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'project_ids[]';
                        input.value = id;
                        bulkDeleteIdsContainer.appendChild(input);
                    });

                    bulkDeleteForm.submit();
                });
            }

            const createBulkProgressRow = (projectInfo) => {
                if (!bulkDownloadProgressList) return;

                const row = document.createElement('div');
                row.className = 'bg-white border border-blue-100 rounded p-2';
                row.dataset.projectId = projectInfo.id;
                const safeTitle = escapeHtml(projectInfo.title);
                row.innerHTML = `
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <div class="text-xs font-medium text-gray-800 truncate" title="${safeTitle}">${safeTitle}</div>
                        <div class="text-[11px] text-gray-500" data-role="status">Waiting...</div>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                        <div data-role="bar" class="h-full bg-blue-500 transition-all duration-500" style="width:0%"></div>
                    </div>
                    <div class="mt-1 text-[11px] text-gray-600 truncate" data-role="message">Đang chờ bắt đầu...</div>
                `;
                bulkDownloadProgressList.appendChild(row);
            };

            const updateBulkProgressRow = (projectId, progress) => {
                const row = bulkDownloadProgressList?.querySelector(`[data-project-id="${projectId}"]`);
                if (!row) return;

                const bar = row.querySelector('[data-role="bar"]');
                const statusText = row.querySelector('[data-role="status"]');
                const messageText = row.querySelector('[data-role="message"]');

                const pct = typeof progress.percent === 'number' ? Math.max(0, Math.min(100, progress.percent)) : 0;
                if (bar) {
                    bar.style.width = `${pct}%`;
                    if (progress.status === 'completed') {
                        bar.className = 'h-full bg-green-500 transition-all duration-500';
                    } else if (progress.status === 'error') {
                        bar.className = 'h-full bg-red-500 transition-all duration-500';
                    } else {
                        bar.className = 'h-full bg-blue-500 transition-all duration-500';
                    }
                }

                if (statusText) {
                    statusText.textContent = `${Math.floor(pct)}%`;
                }

                if (messageText) {
                    const speed = progress.speed ? ` • ${progress.speed}` : '';
                    messageText.textContent = (progress.message || 'Đang tải...') + speed;
                }
            };

            const pollProjectDownloadProgress = async (projectId) => {
                const response = await fetch(`/dubsync/projects/${projectId}/download-youtube-video-progress`, {
                    method: 'GET',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                    },
                });
                const data = await response.json();
                return data?.progress || {};
            };

            // Dispatch job for one project, then poll until completed/error.
            // Returns a Promise that resolves when the download finishes.
            const runProjectDownload = (projectInfo) => new Promise(async (resolve) => {
                updateBulkProgressRow(projectInfo.id, { status: 'processing', percent: 0, message: 'Đang xếp hàng...' });

                try {
                    // Dispatch — returns immediately with { queued: true } or { queued: false } if file exists.
                    const response = await fetch(`/dubsync/projects/${projectInfo.id}/download-youtube-video`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        body: JSON.stringify({}),
                    });
                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Lỗi tải video');
                    }

                    // File already existed — done immediately.
                    if (!data.queued) {
                        updateBulkProgressRow(projectInfo.id, { status: 'completed', percent: 100, message: '✅ Video đã tồn tại' });
                        return resolve({ success: true });
                    }

                    // Poll until completed or error.
                    const pollTimer = setInterval(async () => {
                        try {
                            const progress = await pollProjectDownloadProgress(projectInfo.id);
                            updateBulkProgressRow(projectInfo.id, progress);

                            if (progress.status === 'completed') {
                                clearInterval(pollTimer);
                                resolve({ success: true });
                            } else if (progress.status === 'error') {
                                clearInterval(pollTimer);
                                resolve({ success: false, error: progress.message });
                            }
                        } catch (e) {
                            console.warn('Poll failed for project', projectInfo.id, e);
                        }
                    }, 1500);

                } catch (error) {
                    updateBulkProgressRow(projectInfo.id, { status: 'error', percent: 100, message: `❌ ${error.message}` });
                    resolve({ success: false, error: error.message });
                }
            });

            if (bulkDownloadBtn) {
                bulkDownloadBtn.addEventListener('click', async function() {
                    if (isBulkDownloading) return;

                    const selectedProjects = getSelectedProjectInfos();
                    if (selectedProjects.length === 0) return;

                    if (!confirm(`Tải source video cho ${selectedProjects.length} project đã chọn?\n\nTất cả sẽ được đưa vào hàng đợi cùng lúc.`)) return;

                    isBulkDownloading = true;
                    updateBulkDeleteState();

                    if (bulkDownloadProgressPanel && bulkDownloadProgressList && bulkDownloadSummary) {
                        bulkDownloadProgressPanel.classList.remove('hidden');
                        bulkDownloadProgressList.innerHTML = '';
                        bulkDownloadSummary.textContent = `Đang xếp hàng ${selectedProjects.length} video...`;
                    }

                    selectedProjects.forEach(createBulkProgressRow);

                    // Dispatch all jobs at once, poll all in parallel.
                    const results = await Promise.all(selectedProjects.map(runProjectDownload));

                    const successCount = results.filter(r => r.success).length;
                    const failCount   = results.length - successCount;

                    if (bulkDownloadSummary) {
                        bulkDownloadSummary.textContent = `Hoàn tất: ${successCount} thành công, ${failCount} lỗi`;
                    }

                    isBulkDownloading = false;
                    updateBulkDeleteState();
                });
            }

            updateBulkDeleteState();

            const listBtn = document.getElementById('listViewBtn');
            const cardBtn = document.getElementById('cardViewBtn');
            const listView = document.getElementById('projectsListView');
            const cardView = document.getElementById('projectsCardView');

            if (!listBtn || !cardBtn || !listView || !cardView) return;

            const setView = (mode) => {
                const isList = mode === 'list';
                listView.classList.toggle('hidden', !isList);
                cardView.classList.toggle('hidden', isList);
                listBtn.classList.toggle('bg-gray-100', isList);
                listBtn.classList.toggle('text-gray-900', isList);
                listBtn.classList.toggle('bg-white', !isList);
                listBtn.classList.toggle('text-gray-700', !isList);
                cardBtn.classList.toggle('bg-gray-100', !isList);
                cardBtn.classList.toggle('text-gray-900', !isList);
                cardBtn.classList.toggle('bg-white', isList);
                cardBtn.classList.toggle('text-gray-700', isList);
                localStorage.setItem('projectsViewMode', mode);
            };

            const savedMode = localStorage.getItem('projectsViewMode') || 'card';
            setView(savedMode);

            listBtn.addEventListener('click', () => setView('list'));
            cardBtn.addEventListener('click', () => setView('card'));
        })();
    </script>
@endsection
