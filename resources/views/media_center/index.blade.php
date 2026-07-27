@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-slate-100 py-6">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-fuchsia-200 bg-white p-4 shadow-sm">
            <div>
                <h1 class="text-2xl font-black text-fuchsia-900">Media Center</h1>
                <p class="mt-1 text-sm text-slate-600">Quan ly content va truy cap workspace cho tung project.</p>
            </div>
            <a href="{{ route('media-center.create') }}" class="rounded-xl bg-fuchsia-600 px-4 py-2 text-sm font-bold text-white hover:bg-fuchsia-700">+ Tao content moi</a>
        </div>

        @if(session('success'))
            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-4 py-3">ID</th>
                            <th class="px-4 py-3">Tieu de</th>
                            <th class="px-4 py-3">Trang thai</th>
                            <th class="px-4 py-3">Main character</th>
                            <th class="px-4 py-3">Cap nhat</th>
                            <th class="px-4 py-3 text-right">Hanh dong</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($projects as $project)
                            @php
                                $workflowStatusLabel = trim((string) ($project->workflow_status_label ?? 'Moi')) ?: 'Moi';
                                $workflowStatusBadgeClass = trim((string) ($project->workflow_status_badge_class ?? 'border-slate-200 bg-slate-100 text-slate-700'));
                            @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-800">#{{ $project->id }}</td>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-slate-900">{{ $project->title }}</p>
                                    <p class="text-xs text-slate-500">Raw status: {{ $project->status }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $workflowStatusBadgeClass }}">{{ $workflowStatusLabel }}</span>
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ $project->main_character_name ?: '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ optional($project->updated_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('media-center.show', $project) }}" class="rounded-lg bg-cyan-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-cyan-700">Show</a>
                                        <a href="{{ route('media-center.edit', $project) }}" class="rounded-lg bg-slate-700 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-800">Edit</a>
                                        <form method="POST" action="{{ route('media-center.projects.destroy', $project) }}" onsubmit="return confirm('Ban chac chan muon xoa content nay?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-rose-700">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">Chua co content nao. Bam "Tao content moi" de bat dau.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
