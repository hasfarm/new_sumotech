@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-slate-100 py-6">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
        <nav class="mb-3 text-xs font-semibold text-slate-500">
            <a href="{{ route('media-center.index') }}" class="text-slate-600 hover:text-fuchsia-700">Media Center</a>
            <span class="mx-1">/</span>
            <span class="text-slate-900">Create</span>
        </nav>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-fuchsia-200 bg-white p-4 shadow-sm">
            <div>
                <h1 class="text-2xl font-black text-fuchsia-900">Tao Content Media Center</h1>
                <p class="mt-1 text-sm text-slate-600">Nhap van ban, thong tin boi canh va luu thanh project moi.</p>
            </div>
            <a href="{{ route('media-center.index') }}" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Quay lai danh sach</a>
        </div>

        @if($errors->any())
            <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <p class="font-bold">Du lieu chua hop le:</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('media-center.projects.store') }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            @csrf

            <div>
                <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">Tieu de</label>
                <input name="title" type="text" value="{{ old('title') }}" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Ten tac pham (tuy chon)">
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">Ngon ngu</label>
                    <input name="language" type="text" value="{{ old('language', 'vi') }}" class="w-full rounded-xl border-slate-300 text-sm" placeholder="vi, en...">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">Ti le khung anh</label>
                    <select name="image_aspect_ratio" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="16:9" @selected(old('image_aspect_ratio', '16:9') === '16:9')>16:9</option>
                        <option value="9:16" @selected(old('image_aspect_ratio') === '9:16')>9:16</option>
                        <option value="1:1" @selected(old('image_aspect_ratio') === '1:1')>1:1</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">Thoi dai</label>
                <input name="story_era" type="text" value="{{ old('story_era') }}" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Vi du: Bac Tong, co trang">
            </div>

            <div>
                <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">The loai</label>
                <input name="story_genre" type="text" value="{{ old('story_genre') }}" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Vi du: Kiem hiep">
            </div>

            <div>
                <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">Boi canh the gioi</label>
                <textarea name="world_context" rows="3" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Mo ta boi canh, khong gian, van hoa...">{{ old('world_context') }}</textarea>
            </div>

            <div>
                <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">Yeu to cam</label>
                <textarea name="forbidden_elements" rows="3" class="w-full rounded-xl border-slate-300 text-sm" placeholder="VD: xe hoi, dien thoai, van phong hien dai...">{{ old('forbidden_elements') }}</textarea>
            </div>

            <div>
                <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">Phong cach anh</label>
                <input name="image_style" type="text" value="{{ old('image_style', 'Cinematic') }}" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Cinematic, Anime, Ghibli...">
            </div>

            <div>
                <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-600">Noi dung nguon</label>
                <textarea name="source_text" rows="10" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Dan van ban tai day..." required>{{ old('source_text') }}</textarea>
            </div>

            <div class="flex flex-wrap items-center gap-2 pt-2">
                <button type="submit" class="rounded-xl bg-fuchsia-600 px-4 py-2 text-sm font-bold text-white hover:bg-fuchsia-700">Luu va mo Workspace</button>
                <a href="{{ route('media-center.index') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">Huy</a>
            </div>
        </form>
    </div>
</div>
@endsection
