ch  @extends('layouts.app')

 @section('content')
     <style>
         .scrollbar-hide::-webkit-scrollbar { display: none; }
         .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
     </style>
     <div class="py-12" data-gramm="false" data-gramm_editor="false" data-enable-grammarly="false">
         <div class="max-w-6xl mx-auto sm:px-6 lg:px-8" data-gramm="false" data-gramm_editor="false"
             data-enable-grammarly="false">
             <!-- Header -->
             <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6 px-4 sm:px-0">
                 <h2 class="font-semibold text-xl sm:text-2xl text-gray-800">
                     📚 Chi tiết Audio Book
                 </h2>
                 <div class="flex gap-2">
                     @if ($audioBook->youtubeChannel)
                         <a href="{{ route('youtube-channels.show', $audioBook->youtubeChannel) }}"
                             class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-1.5 sm:py-2 px-3 sm:px-4 rounded-lg transition duration-200 text-sm sm:text-base">
                             ← <span class="hidden sm:inline">Quay lại </span>Kênh
                         </a>
                     @else
                         <a href="{{ route('youtube-channels.index') }}"
                             class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-1.5 sm:py-2 px-3 sm:px-4 rounded-lg transition duration-200 text-sm sm:text-base">
                             ← <span class="hidden sm:inline">Quay lại </span>Kênh
                         </a>
                     @endif
                     <a href="{{ route('audiobooks.edit', $audioBook) }}"
                         class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-1.5 sm:py-2 px-3 sm:px-4 rounded-lg transition duration-200 text-sm sm:text-base">
                         ✏️ Sửa
                     </a>
                 </div>
             </div>

             <!-- Success/Error Messages -->
             @if ($message = Session::get('success'))
                 <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                     {{ $message }}
                     <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3"
                         onclick="this.parentElement.style.display='none';">
                         <span class="text-2xl leading-none">&times;</span>
                     </button>
                 </div>
             @endif

             @if ($message = Session::get('error'))
                 <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                     {{ $message }}
                     <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3"
                         onclick="this.parentElement.style.display='none';">
                         <span class="text-2xl leading-none">&times;</span>
                     </button>
                 </div>
             @endif

             <div class="flex flex-col md:flex-row gap-4 sm:gap-6">
                 <aside id="featureSidebar" class="md:w-72 md:flex-shrink-0 transition-all duration-200 ease-in-out">
                     <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg md:sticky md:top-6">
                         <div id="sidebarInner" class="p-3 sm:p-4">
                             <div id="sidebarHeader" class="flex items-center justify-between mb-3">
                                 <h3 id="sidebarTitle" class="text-sm font-semibold text-gray-800">📋 Menu tính năng</h3>
                                 <button type="button" id="toggleSidebarBtn"
                                     class="px-2 py-1 text-xs border border-gray-200 rounded-md text-gray-600 hover:bg-gray-50 transition"
                                     title="Thu gọn sidebar" aria-label="Thu gọn sidebar" aria-expanded="true">
                                     «
                                 </button>
                             </div>
                             <div id="sidebarMenuList" class="space-y-1">
                                 <button type="button" data-default="true" data-view="overview"
                                     data-anchor="general-info-section"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border text-sm font-medium transition bg-blue-50 text-blue-700 border-blue-200">
                                     📘 Thông tin chung về sách
                                 </button>
                                 <button type="button" data-view="overview" data-anchor="book-description-section"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     📖 Giới thiệu sách
                                 </button>
                                 <button type="button" data-view="overview" data-anchor="review-assets-section"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     📹 Tạo Review sách
                                 </button>
                                 <button type="button" data-view="overview" data-anchor="full-book-section"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     📕 Video Full Book
                                 </button>
                                 <button type="button" data-view="overview" data-anchor="videoSegmentsPanel"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     🎬 Video Segments
                                 </button>
                                 <button type="button" data-view="overview" data-anchor="tts-settings-section"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     🎙️ TTS Settings
                                 </button>
                                 <button type="button" data-view="overview" data-anchor="art-style-section"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     🎨 Phong cách nhân vật
                                 </button>

                                 <div class="my-2 border-t border-gray-100"></div>

                                 <button type="button" data-view="tab" data-tab="chapters"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     📚 Danh sách chương
                                 </button>
                                 <button type="button" data-view="tab" data-tab="youtube-media"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     🖼️ YouTube Media
                                 </button>
                                 <button type="button" data-view="tab" data-tab="short-video"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     📱 Short Video
                                 </button>
                                 <button type="button" data-view="tab" data-tab="clipping"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     ✂️ Clipping
                                 </button>
                                 <button type="button" data-view="tab" data-tab="auto-publish"
                                     class="sidebar-menu-btn w-full text-left px-3 py-2 rounded-lg border border-transparent text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                                     🚀 Auto Publish
                                 </button>
                             </div>
                         </div>
                     </div>
                 </aside>

                 <div class="flex-1 min-w-0" id="feature-main-content">

             <!-- Book Info Card -->
             <div id="overview-panel" class="sidebar-panel bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                 <div class="p-3 sm:p-6 text-gray-900">
                     <div id="overview-grid" class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
                         <!-- Left: Book Info -->
                         <div id="overview-main-column" class="md:col-span-2">
                             <div id="general-info-section" class="flex gap-3 sm:gap-6 scroll-mt-24">
                                 <!-- Cover Image -->
                                 <div class="flex-shrink-0">
                                     @if ($audioBook->cover_image)
                                         <img src="{{ asset('storage/' . $audioBook->cover_image) }}"
                                             alt="{{ $audioBook->title }}"
                                             class="w-20 h-28 sm:w-32 sm:h-44 object-cover rounded-lg border shadow cursor-pointer hover:opacity-80 transition"
                                             onclick="openImagePreview('{{ asset('storage/' . $audioBook->cover_image) }}')"
                                             title="Click để xem lớn">
                                     @else
                                         <div
                                             class="w-20 h-28 sm:w-32 sm:h-44 bg-gradient-to-br from-blue-400 to-blue-600 rounded-lg flex items-center justify-center shadow">
                                             <span class="text-2xl sm:text-4xl">📚</span>
                                         </div>
                                     @endif
                                 </div>

                                 <!-- Book Details -->
                                 <div class="flex-1 min-w-0">
                                     <h3 class="text-base sm:text-xl font-semibold text-gray-900 mb-1 sm:mb-2 truncate">{{ $audioBook->title }}</h3>

                                     <!-- Author & Category -->
                                     <div class="flex flex-wrap gap-3 mb-3">
                                         @if ($audioBook->author)
                                             <div class="flex items-center gap-1 text-sm text-gray-700">
                                                 <span class="text-gray-500">✍️</span>
                                                 <span class="font-medium">{{ $audioBook->author }}</span>
                                             </div>
                                         @endif
                                         @if ($audioBook->category)
                                             <div class="flex items-center gap-1 text-sm">
                                                 <span
                                                     class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full text-xs font-medium">
                                                     📂 {{ $audioBook->category }}
                                                 </span>
                                             </div>
                                         @endif
                                     </div>

                                     <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 sm:gap-3 mb-3 sm:mb-4">
                                         @php
                                             $totalDuration = $audioBook->chapters->sum('total_duration');
                                             $totalChars = $audioBook->chapters->sum(
                                                 fn($ch) => mb_strlen($ch->content ?? ''),
                                             );
                                         @endphp
                                         <div class="bg-gray-50 rounded-lg p-2 sm:p-3">
                                             <div class="text-[10px] sm:text-xs text-gray-500">Kênh YouTube</div>
                                             <div class="text-xs sm:text-sm font-semibold text-gray-900 truncate">
                                                 📺 {{ $audioBook->youtubeChannel->title ?? '—' }}
                                             </div>
                                         </div>
                                         <div class="bg-gray-50 rounded-lg p-2 sm:p-3">
                                             <div class="text-[10px] sm:text-xs text-gray-500">Phân loại</div>
                                             <div class="text-xs sm:text-sm font-semibold text-gray-900">
                                                 @php
                                                     $bookTypeLabel = match ($audioBook->book_type) {
                                                         'truyen' => '📖 Truyện',
                                                         'tieu_thuyet' => '📘 Tiểu thuyết',
                                                         'truyen_ngan' => '📗 Truyện ngắn',
                                                         'sach' => '📚 Sách',
                                                         default => $audioBook->book_type
                                                             ? '📚 ' . $audioBook->book_type
                                                             : '📚 Sách',
                                                     };
                                                 @endphp
                                                 {{ $bookTypeLabel }}
                                             </div>
                                         </div>
                                         <div class="bg-gray-50 rounded-lg p-2 sm:p-3">
                                             <div class="text-[10px] sm:text-xs text-gray-500">Ngôn ngữ</div>
                                             <div class="text-xs sm:text-sm font-semibold text-gray-900">
                                                 🌐 {{ strtoupper($audioBook->language) }}
                                             </div>
                                         </div>
                                         <div class="bg-gray-50 rounded-lg p-2 sm:p-3">
                                             <div class="text-[10px] sm:text-xs text-gray-500">Số chương</div>
                                             <div class="text-xs sm:text-sm font-semibold text-gray-900">
                                                 📖 {{ $audioBook->total_chapters }} chương
                                             </div>
                                         </div>
                                         <div class="bg-gray-50 rounded-lg p-2 sm:p-3">
                                             <div class="text-[10px] sm:text-xs text-gray-500">Tổng thời lượng</div>
                                             <div class="text-xs sm:text-sm font-semibold text-gray-900">
                                                 @if ($totalDuration > 0)
                                                     @php
                                                         $hours = floor($totalDuration / 3600);
                                                         $mins = floor(($totalDuration % 3600) / 60);
                                                         $secs = floor($totalDuration % 60);
                                                         $durationStr =
                                                             $hours > 0
                                                                 ? sprintf('%dh %02dp %02ds', $hours, $mins, $secs)
                                                                 : sprintf('%dp %02ds', $mins, $secs);
                                                     @endphp
                                                     ⏱️ {{ $durationStr }}
                                                 @else
                                                     ⏱️ Chưa có audio
                                                 @endif
                                             </div>
                                         </div>
                                         <div class="bg-gray-50 rounded-lg p-2 sm:p-3">
                                             <div class="text-[10px] sm:text-xs text-gray-500">Tổng ký tự</div>
                                             <div class="text-xs sm:text-sm font-semibold text-gray-900">
                                                 ✏️ {{ number_format($totalChars) }} ký tự
                                             </div>
                                         </div>
                                         <div class="bg-gray-50 rounded-lg p-2 sm:p-3">
                                             <div class="text-[10px] sm:text-xs text-gray-500">Dung lượng sách</div>
                                             <div class="text-xs sm:text-sm font-semibold text-gray-900">
                                                 @php
                                                     $formatBytes = function ($bytes) {
                                                         if ($bytes === 0) {
                                                             return '0 B';
                                                         }
                                                         $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                                                         $i = 0;
                                                         while ($bytes >= 1024 && $i < count($units) - 1) {
                                                             $bytes /= 1024;
                                                             $i++;
                                                         }
                                                         return round($bytes, 1) . ' ' . $units[$i];
                                                     };
                                                 @endphp
                                                 💾 {{ $formatBytes($bookStorageSize) }}
                                             </div>
                                         </div>
                                         <div class="bg-gray-50 rounded-lg p-2 sm:p-3 cursor-pointer"
                                             onclick="document.getElementById('channelStorageDetail').classList.toggle('hidden')">
                                             <div class="text-[10px] sm:text-xs text-gray-500">Kênh
                                                 ({{ count($channelBookSizes) }} sách)</div>
                                             <div class="text-xs sm:text-sm font-semibold text-gray-900">
                                                 📁 {{ $formatBytes($channelStorageSize) }} <span
                                                     class="text-xs text-gray-400">▼</span>
                                             </div>
                                         </div>
                                     </div>
                                     {{-- Channel storage detail --}}
                                     <div id="channelStorageDetail" class="hidden mb-4">
                                         <div class="bg-gray-50 rounded-lg p-3 max-h-48 overflow-y-auto">
                                             <div class="text-xs font-medium text-gray-600 mb-2">Chi tiết dung lượng theo
                                                 sách:</div>
                                             @foreach ($channelBookSizes as $bookSize)
                                                 <div
                                                     class="flex justify-between items-center text-xs py-1 border-b border-gray-100 last:border-0 {{ $bookSize['id'] === $audioBook->id ? 'font-bold text-blue-700' : 'text-gray-700' }}">
                                                     <span class="truncate mr-2" style="max-width: 70%">
                                                         {{ $bookSize['id'] === $audioBook->id ? '► ' : '' }}{{ $bookSize['title'] }}
                                                     </span>
                                                     <span
                                                         class="whitespace-nowrap">{{ $formatBytes($bookSize['size']) }}</span>
                                                 </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Book Description Section -->
                            <div id="book-description-section"
                                class="mt-4 p-4 bg-gradient-to-r from-amber-50 to-orange-50 rounded-lg border border-amber-200">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-sm font-semibold text-amber-800 flex items-center gap-2">
                                        📖 Giới thiệu sách
                                    </h4>
                                    <div class="flex gap-2">
                                        <button type="button" id="rewriteDescBtn"
                                            class="text-xs bg-purple-100 hover:bg-purple-200 text-purple-700 px-2 py-1 rounded transition flex items-center gap-1">
                                            ✨ Viết lại bằng AI
                                        </button>
                                        <button type="button" id="generateDescAudioBtn"
                                            class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded transition flex items-center gap-1">
                                            🎙️ Tạo Audio
                                        </button>
                                        <button type="button" id="saveDescBtn"
                                            class="text-xs bg-green-100 hover:bg-green-200 text-green-700 px-2 py-1 rounded transition flex items-center gap-1">
                                            💾 Lưu
                                        </button>
                                    </div>
                                </div>
                                <textarea id="bookDescription" rows="15"
                                    class="w-full px-3 py-2 border border-amber-200 rounded-lg text-sm focus:border-amber-400 focus:outline-none focus:ring-1 focus:ring-amber-300 bg-white"
                                     placeholder="Nhập mô tả/giới thiệu sách...">{{ $audioBook->description ?? '' }}</textarea>

                                 <div id="descStatus" class="mt-2 text-xs"></div>
                                 <div id="descVideoProgress" class="mt-2 hidden">
                                     <div class="flex items-center justify-between text-[11px] text-emerald-700 mb-1">
                                         <span id="descVideoProgressLabel">Đang tạo video...</span>
                                         <span id="descVideoProgressPercent">0%</span>
                                     </div>
                                     <div class="w-full bg-emerald-100 rounded-full h-2">
                                         <div id="descVideoProgressBar"
                                             class="bg-emerald-500 h-2 rounded-full transition-all duration-300"
                                             style="width: 0%"></div>
                                     </div>
                                 </div>
                                 <div id="descVideoLog" class="mt-2 hidden">
                                     <div class="text-[11px] text-gray-600 mb-1">Log FFmpeg:</div>
                                     <div id="descVideoLogContent"
                                         class="max-h-32 overflow-y-auto text-[11px] bg-gray-50 border border-gray-200 rounded p-2 whitespace-pre-wrap">
                                     </div>
                                 </div>

                                 <!-- Description Audio Player -->
                                 <div id="descAudioContainer"
                                     class="mt-3 {{ $audioBook->description_audio ? '' : 'hidden' }}">
                                     <div
                                         class="flex items-center justify-between p-2 bg-gradient-to-r from-purple-100 to-pink-100 border border-purple-300 rounded-lg">
                                         <div class="flex items-center gap-2">
                                             <span class="text-lg">🎧</span>
                                             <span class="text-sm font-medium text-purple-800">Audio giới thiệu</span>
                                             <span id="descAudioDuration"
                                                 class="text-xs text-purple-600">{{ $audioBook->description_audio_duration ? gmdate('i:s', (int) $audioBook->description_audio_duration) : '' }}</span>
                                         </div>
                                         <div class="flex items-center gap-2">
                                             <audio id="descAudioPlayer" controls class="h-8">
                                                 @if ($audioBook->description_audio)
                                                     <source src="{{ asset('storage/' . $audioBook->description_audio) }}"
                                                         type="audio/mpeg">
                                                 @endif
                                             </audio>
                                             <button type="button" id="deleteDescAudioBtn"
                                                 class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded transition"
                                                 title="Xóa audio">
                                                 🗑️
                                             </button>
                                         </div>
                                     </div>
                                 </div>

                                 <!-- Image Picker for Intro Video -->
                                 <div id="descImagePickerSection" class="mt-3">
                                     <div
                                         class="p-3 bg-gradient-to-r from-indigo-50 to-blue-50 border border-indigo-200 rounded-lg">
                                         <div class="flex items-center justify-between mb-2">
                                             <div class="flex items-center gap-2">
                                                 <span class="text-lg">🖼️</span>
                                                 <span class="text-sm font-medium text-indigo-800">Chọn ảnh cho Video giới
                                                     thiệu</span>
                                             </div>
                                             <div class="flex gap-2">
                                                 <button type="button" id="loadDescMediaBtn"
                                                     class="text-xs bg-indigo-100 hover:bg-indigo-200 text-indigo-700 px-2 py-1 rounded transition flex items-center gap-1">
                                                     🔄 Tải thư viện
                                                 </button>
                                                 <button type="button" id="generateDescIntroVideoBtn"
                                                     class="text-xs bg-emerald-100 hover:bg-emerald-200 text-emerald-700 px-2 py-1 rounded transition flex items-center gap-1 {{ $audioBook->description_audio ? '' : 'opacity-50 cursor-not-allowed' }}"
                                                     {{ $audioBook->description_audio ? '' : 'disabled' }}
                                                     title="Chọn ảnh + có audio → tạo video giới thiệu">
                                                     🎬 Tạo Video Giới Thiệu
                                                 </button>
                                             </div>
                                         </div>

                                         <!-- Selected image preview -->
                                         <div id="descSelectedImagePreview" class="mb-2 hidden">
                                             <div
                                                 class="flex items-center gap-2 p-2 bg-white border border-indigo-300 rounded-lg">
                                                 <img id="descSelectedImageImg" src="" alt="Selected"
                                                     class="w-20 h-14 object-cover rounded border">
                                                 <div class="flex-1">
                                                     <span class="text-xs text-indigo-700 font-medium">Ảnh đã chọn:</span>
                                                     <span id="descSelectedImageName"
                                                         class="text-xs text-gray-600 ml-1"></span>
                                                 </div>
                                                 <button type="button" id="descClearImageBtn"
                                                     class="text-xs text-red-500 hover:text-red-700">✕</button>
                                             </div>
                                         </div>

                                         <!-- Image grid (loaded dynamically) -->
                                         <div id="descMediaGrid"
                                             class="grid grid-cols-4 sm:grid-cols-6 gap-2 max-h-48 overflow-y-auto hidden">
                                             <!-- Images loaded by JS -->
                                         </div>
                                         <div id="descMediaEmpty" class="text-xs text-gray-500 text-center py-4 hidden">
                                             Chưa có ảnh nào. Hãy tạo ảnh trong tab "YouTube Media" trước.
                                         </div>
                                     </div>
                                 </div>

                                 <!-- Description Intro Video Player -->
                                 <div id="descVideoContainer"
                                     class="mt-3 {{ $audioBook->description_scene_video ? '' : 'hidden' }}">
                                     <div
                                         class="p-3 bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-300 rounded-lg">
                                         <div class="flex items-center justify-between mb-2">
                                             <div class="flex items-center gap-2">
                                                 <span class="text-lg">🎬</span>
                                                 <span class="text-sm font-medium text-emerald-800">Video giới thiệu</span>
                                                 <span id="descVideoDuration"
                                                     class="text-xs text-emerald-600">{{ $audioBook->description_scene_video_duration ? gmdate('i:s', (int) $audioBook->description_scene_video_duration) : '' }}</span>
                                             </div>
                                             <button type="button" id="deleteDescVideoBtn"
                                                 class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded transition"
                                                 title="Xóa video">
                                                 🗑️
                                             </button>
                                         </div>
                                         <video id="descVideoPlayer" controls
                                             class="w-full rounded border border-emerald-300">
                                             @if ($audioBook->description_scene_video)
                                                 <source
                                                     src="{{ asset('storage/' . $audioBook->description_scene_video) }}"
                                                     type="video/mp4">
                                             @endif
                                         </video>
                                     </div>
                                 </div>

                                 </div>{{-- end descContentCollapse --}}

                                 <!-- ============ REVIEW ASSETS (2 Phases) ============ -->
                                 <div id="review-assets-section"
                                     class="mt-3 sm:mt-4 p-3 sm:p-4 bg-purple-50 rounded-lg border border-purple-200 scroll-mt-24">
                                     <div class="flex items-center justify-between mb-3">
                                         <h4 class="text-sm font-semibold text-purple-800 flex items-center gap-2">
                                             📹 Review Assets
                                         </h4>
                                         <div class="flex gap-2">
                                             <button type="button" id="deleteReviewBtn"
                                                 class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded transition {{ $audioBook->review_script ? '' : 'hidden' }}">
                                                 🗑️ Xóa tất cả
                                             </button>
                                         </div>
                                     </div>

                                     {{-- Prerequisites --}}
                                     @php
                                         $chaptersWithContent = $audioBook->chapters
                                             ->filter(fn($ch) => !empty($ch->content))
                                             ->count();
                                         $hasTtsConfig =
                                             !empty($audioBook->tts_provider) && !empty($audioBook->tts_voice_name);
                                     @endphp
                                     <div class="text-xs text-gray-600 mb-3 flex gap-4">
                                         <span class="{{ $chaptersWithContent > 0 ? 'text-green-600' : 'text-red-600' }}">
                                             {{ $chaptersWithContent > 0 ? '✅' : '❌' }} {{ $chaptersWithContent }} chương
                                             có nội dung
                                         </span>
                                         <span class="{{ $hasTtsConfig ? 'text-green-600' : 'text-red-600' }}">
                                             {{ $hasTtsConfig ? '✅' : '❌' }} TTS đã cấu hình
                                         </span>
                                     </div>

                                     {{-- ====== PHASE 1: Tạo kịch bản ====== --}}
                                     <div class="mb-3 p-3 bg-white rounded-lg border border-purple-200">
                                         <div class="flex items-center justify-between mb-2">
                                             <span class="text-xs font-semibold text-purple-700">Phase 1: Kịch bản &
                                                 Segments</span>
                                             <button type="button" id="startScriptBtn"
                                                 class="text-xs bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded-lg transition flex items-center gap-1 font-medium">
                                                 🚀 Tạo Kịch Bản
                                             </button>
                                         </div>
                                         {{-- Progress Phase 1 --}}
                                         <div id="scriptProgress" class="hidden mb-2">
                                             <div
                                                 class="flex items-center justify-between text-[11px] text-purple-700 mb-1">
                                                 <span id="scriptStageName">Stage 1/2</span>
                                                 <span id="scriptPercent">0%</span>
                                             </div>
                                             <div class="w-full bg-purple-100 rounded-full h-2 mb-1">
                                                 <div id="scriptProgressBar"
                                                     class="bg-purple-500 h-2 rounded-full transition-all duration-300"
                                                     style="width: 0%"></div>
                                             </div>
                                             <div id="scriptDetail" class="text-[11px] text-gray-500"></div>
                                         </div>
                                     </div>

                                     {{-- Script preview --}}
                                     <div id="reviewScriptPreview"
                                         class="{{ $audioBook->review_script ? '' : 'hidden' }} mb-3">
                                         <div class="flex items-center justify-between mb-1">
                                             <div class="text-xs font-medium text-purple-700">Kịch bản review:</div>
                                             <div class="flex items-center gap-1.5">
                                                 <button type="button" id="copyScriptBtn"
                                                     class="text-[11px] bg-purple-100 hover:bg-purple-200 text-purple-700 px-2 py-0.5 rounded border border-purple-200 transition flex items-center gap-1">
                                                     📋 Sao chép kịch bản
                                                 </button>
                                                 <button type="button" id="openReviewStudioBtn"
                                                     class="text-[11px] bg-fuchsia-100 hover:bg-fuchsia-200 text-fuchsia-700 px-2 py-0.5 rounded border border-fuchsia-200 transition flex items-center gap-1">
                                                     🎬 Studio câu
                                                 </button>
                                             </div>
                                         </div>
                                         <textarea id="reviewScriptTextarea" rows="6" readonly
                                             class="w-full px-3 py-2 border border-purple-200 rounded-lg text-xs bg-white text-gray-700 resize-y">{{ $audioBook->review_script ?? '' }}</textarea>
                                     </div>

                                     {{-- Segments list --}}
                                     <div id="reviewChunksContainer" class="hidden mb-3">
                                         <div class="flex items-center justify-between mb-2">
                                             <span class="text-xs font-semibold text-purple-700">Danh sách Segments</span>
                                             <div class="flex gap-1.5">
                                                 <button type="button" id="translateAllBtn"
                                                     class="text-[10px] bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-0.5 rounded border border-blue-200 transition">
                                                     🔄 Dịch tất cả VI→EN
                                                 </button>
                                                 <span id="reviewChunksCount"
                                                     class="text-[11px] text-purple-500 self-center"></span>
                                             </div>
                                         </div>
                                         <div id="reviewChunksList" class="space-y-3 max-h-[700px] overflow-y-auto pr-1">
                                         </div>
                                     </div>

                                     {{-- ====== PHASE 2: Tạo ảnh & Audio ====== --}}
                                     <div id="phase2Container"
                                         class="hidden p-3 bg-white rounded-lg border border-purple-200">
                                         <div class="flex items-center justify-between mb-2">
                                             <span class="text-xs font-semibold text-purple-700">Phase 2: Tạo Ảnh &
                                                 Audio</span>
                                             <div class="flex items-center gap-2">
                                                 <select id="imageProviderSelect"
                                                     class="text-[11px] border border-purple-200 rounded px-2 py-1 bg-white text-gray-700">
                                                     <option value="gemini">Gemini (Nano Banana)</option>
                                                     <option value="flux">Flux (AIML)</option>
                                                 </select>
                                                 <button type="button" id="startAssetsBtn"
                                                     class="text-xs bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg transition flex items-center gap-1 font-medium">
                                                     🖼️ Tạo Ảnh & Audio
                                                 </button>
                                             </div>
                                         </div>
                                         <p class="text-[10px] text-gray-500 mb-2">Chọn nguồn tạo ảnh, chỉnh sửa prompt ở
                                             trên xong rồi bấm nút.</p>
                                         {{-- Progress Phase 2 --}}
                                         <div id="assetsProgress" class="hidden">
                                             <div
                                                 class="flex items-center justify-between text-[11px] text-emerald-700 mb-1">
                                                 <span id="assetsStageName">Stage 1/2</span>
                                                 <span id="assetsPercent">0%</span>
                                             </div>
                                             <div class="w-full bg-emerald-100 rounded-full h-2 mb-1">
                                                 <div id="assetsProgressBar"
                                                     class="bg-emerald-500 h-2 rounded-full transition-all duration-300"
                                                     style="width: 0%"></div>
                                             </div>
                                             <div id="assetsDetail" class="text-[11px] text-gray-500"></div>
                                         </div>
                                     </div>

                                     {{-- Image lightbox modal --}}
                                     <div id="reviewImageModal"
                                         class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4"
                                         onclick="this.classList.add('hidden'); this.classList.remove('flex');">
                                         <img id="reviewImageModalImg" src=""
                                             class="max-w-full max-h-full rounded-lg shadow-2xl"
                                             onclick="event.stopPropagation();">
                                     </div>
                                 </div>

                                 <!-- ============ FULL BOOK VIDEO ============ -->
                                 <div id="full-book-section"
                                     class="mt-3 sm:mt-4 p-3 sm:p-4 bg-gradient-to-r from-rose-50 to-red-50 rounded-lg border border-rose-200">
                                     <div class="flex items-center justify-between mb-2">
                                         <h4 class="text-sm font-semibold text-rose-800 flex items-center gap-2">
                                             📕 Video Full Book (Toàn bộ sách)
                                         </h4>
                                         <div class="flex gap-2">
                                             <button type="button" id="loadFullBookMediaBtn"
                                                 class="text-xs bg-rose-100 hover:bg-rose-200 text-rose-700 px-2 py-1 rounded transition flex items-center gap-1">
                                                 🔄 Tải thư viện
                                             </button>
                                             <button type="button" id="generateFullBookVideoBtn"
                                                 class="text-xs bg-rose-600 hover:bg-rose-700 text-white px-3 py-1 rounded transition flex items-center gap-1"
                                                 title="Ghép tất cả audio giới thiệu + các chương → 1 video duy nhất">
                                                 🎬 Tạo Video Full Book
                                             </button>
                                         </div>
                                     </div>

                                     <p class="text-[11px] text-rose-600 mb-2">
                                         Ghép audio giới thiệu + tất cả chương TTS full → 1 file audio → tạo 1 video duy
                                         nhất cho cả cuốn sách (bao gồm nhạc nền + sóng âm).
                                     </p>

                                     <!-- Selected image preview for full book -->
                                     <div id="fullBookSelectedImagePreview" class="mb-2 hidden">
                                         <div
                                             class="flex items-center gap-2 p-2 bg-white border border-rose-300 rounded-lg">
                                             <img id="fullBookSelectedImageImg" src="" alt="Selected"
                                                 class="w-20 h-14 object-cover rounded border">
                                             <div class="flex-1">
                                                 <span class="text-xs text-rose-700 font-medium">Ảnh đã chọn:</span>
                                                 <span id="fullBookSelectedImageName"
                                                     class="text-xs text-gray-600 ml-1"></span>
                                             </div>
                                             <button type="button" id="fullBookClearImageBtn"
                                                 class="text-xs text-red-500 hover:text-red-700">✕</button>
                                         </div>
                                     </div>

                                     <!-- Image grid for full book (loaded dynamically) -->
                                     <div id="fullBookMediaGrid"
                                         class="grid grid-cols-4 sm:grid-cols-6 gap-2 max-h-48 overflow-y-auto hidden">
                                     </div>
                                     <div id="fullBookMediaEmpty" class="text-xs text-gray-500 text-center py-2 hidden">
                                         Chưa có ảnh nào. Hãy tạo ảnh trong tab "YouTube Media" trước.
                                     </div>

                                     <!-- Progress -->
                                     <div id="fullBookVideoProgress" class="mt-2 hidden">
                                         <div class="flex items-center justify-between text-[11px] text-rose-700 mb-1">
                                             <span id="fullBookVideoProgressLabel">Đang tạo video...</span>
                                             <span id="fullBookVideoProgressPercent">0%</span>
                                         </div>
                                         <div class="w-full bg-rose-100 rounded-full h-2">
                                             <div id="fullBookVideoProgressBar"
                                                 class="bg-rose-500 h-2 rounded-full transition-all duration-300"
                                                 style="width: 0%"></div>
                                         </div>
                                     </div>
                                     <div id="fullBookVideoLog" class="mt-2 hidden">
                                         <div class="text-[11px] text-gray-600 mb-1">Log:</div>
                                         <div id="fullBookVideoLogContent"
                                             class="max-h-32 overflow-y-auto text-[11px] bg-gray-50 border border-gray-200 rounded p-2 whitespace-pre-wrap">
                                         </div>
                                     </div>

                                     <div id="fullBookVideoStatus" class="mt-2 text-xs"></div>

                                     <!-- Full Book Video Player -->
                                     <div id="fullBookVideoContainer"
                                         class="mt-3 {{ $audioBook->full_book_video ? '' : 'hidden' }}">
                                         <div
                                             class="p-3 bg-gradient-to-r from-rose-100 to-red-100 border border-rose-300 rounded-lg">
                                             <div class="flex items-center justify-between mb-2">
                                                 <div class="flex items-center gap-2">
                                                     <span class="text-lg">📕</span>
                                                     <span class="text-sm font-medium text-rose-800">Video Full Book</span>
                                                     <span id="fullBookVideoDuration"
                                                         class="text-xs text-rose-600">{{ $audioBook->full_book_video_duration ? gmdate('H:i:s', (int) $audioBook->full_book_video_duration) : '' }}</span>
                                                 </div>
                                                 <div class="flex items-center gap-2">
                                                     @if ($audioBook->full_book_video)
                                                         <a href="{{ asset('storage/' . $audioBook->full_book_video) }}"
                                                             download
                                                             class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded transition"
                                                             title="Tải xuống">
                                                             ⬇️ Download
                                                         </a>
                                                     @endif
                                                     <button type="button" id="deleteFullBookVideoBtn"
                                                         class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded transition"
                                                         title="Xóa video">
                                                         🗑️
                                                     </button>
                                                 </div>
                                             </div>
                                             <video id="fullBookVideoPlayer" controls
                                                 class="w-full rounded border border-rose-300">
                                                 @if ($audioBook->full_book_video)
                                                     <source src="{{ asset('storage/' . $audioBook->full_book_video) }}"
                                                         type="video/mp4">
                                                 @endif
                                             </video>
                                         </div>
                                     </div>
                                 </div>
                                 <!-- ============ END FULL BOOK VIDEO ============ -->

                                 <!-- ============ VIDEO SEGMENTS (BATCH) ============ -->
                                 <div id="videoSegmentsPanel"
                                     class="mt-3 sm:mt-4 p-3 sm:p-4 bg-gradient-to-r from-teal-50 to-cyan-50 rounded-lg border border-teal-200 scroll-mt-24">
                                     <div class="flex items-center justify-between mb-2">
                                         <h4 class="text-sm font-semibold text-teal-800 flex items-center gap-2">
                                             🎬 Video Segments (Gom chương tùy chọn)
                                         </h4>
                                         <div class="flex items-center gap-2">
                                             <button type="button" id="videoSegmentsToggleBtn"
                                                 class="text-xs bg-white hover:bg-gray-100 text-teal-700 px-2 py-1 rounded border border-teal-300 transition"
                                                 aria-expanded="false" aria-controls="videoSegmentsContent">
                                                 <span id="videoSegmentsToggleIcon">▸</span>
                                                 <span id="videoSegmentsToggleText">Mở rộng</span>
                                             </button>
                                             <label
                                                 class="flex items-center gap-1 text-[10px] text-teal-700 cursor-pointer select-none">
                                                 <input type="checkbox" id="segSelectAll"
                                                     class="rounded border-teal-400 text-teal-600 focus:ring-teal-500">
                                                 <span>Chọn tất cả</span>
                                             </label>
                                             <button type="button" id="addSegmentBtn"
                                                 class="text-xs bg-teal-100 hover:bg-teal-200 text-teal-700 px-2 py-1 rounded transition">
                                                 ➕ Thêm Segment
                                             </button>
                                             <button type="button" id="saveSegmentsBtn"
                                                 class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded transition">
                                                 💾 Lưu kế hoạch
                                             </button>
                                             <button type="button" id="startBatchBtn"
                                                 class="text-xs bg-teal-600 hover:bg-teal-700 text-white px-3 py-1 rounded transition">
                                                 🚀 Tạo video đã chọn
                                             </button>
                                         </div>
                                     </div>
                                     <div id="videoSegmentsContent" class="hidden">
                                         <p class="text-[11px] text-teal-600 mb-3">
                                             Tạo nhiều video từ các nhóm chương tùy chọn. Lên kế hoạch trước, chọn ảnh riêng
                                             cho
                                             mỗi segment, rồi nhấn "Bắt đầu" để hệ thống tự động xử lý tuần tự.
                                         </p>
                                         <div id="segmentPlannerStatus" class="text-xs mb-2"></div>
                                         <!-- Segment list -->
                                         <div id="segmentList" class="space-y-3"></div>
                                         <div id="segmentEmptyState"
                                             class="text-center py-4 text-xs text-gray-400 {{ $audioBook->videoSegments->count() > 0 ? 'hidden' : '' }}">
                                             Chưa có segment nào. Nhấn "➕ Thêm Segment" để bắt đầu lên kế hoạch.
                                         </div>
                                         <!-- Batch progress -->
                                         <div id="batchProgress" class="mt-3 hidden">
                                             <div class="flex items-center justify-between text-[11px] text-teal-700 mb-1">
                                                 <span id="batchProgressLabel">Đang xử lý...</span>
                                                 <span id="batchProgressPercent">0%</span>
                                             </div>
                                             <div class="w-full bg-teal-100 rounded-full h-2">
                                                 <div id="batchProgressBar"
                                                     class="bg-teal-500 h-2 rounded-full transition-all duration-300"
                                                     style="width: 0%"></div>
                                             </div>
                                         </div>
                                         <div id="batchLogContainer" class="mt-2 hidden">
                                             <div class="text-[11px] text-gray-600 mb-1">Log:</div>
                                             <div id="batchLogContent"
                                                 class="max-h-32 overflow-y-auto text-[11px] bg-gray-50 border border-gray-200 rounded p-2 whitespace-pre-wrap font-mono">
                                             </div>
                                         </div>
                                     </div>
                                 </div>
                                 <!-- ============ END VIDEO SEGMENTS ============ -->

                                 <!-- Segment thumbnail lightbox -->
                                 <div id="segThumbOverlay"
                                     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 cursor-pointer"
                                     onclick="this.classList.add('hidden')">
                                     <img src=""
                                         class="max-w-[90vw] max-h-[90vh] rounded-lg shadow-2xl object-contain"
                                         onclick="event.stopPropagation()">
                                     <button type="button"
                                         class="absolute top-4 right-4 text-white text-3xl font-bold hover:text-gray-300"
                                         onclick="document.getElementById('segThumbOverlay').classList.add('hidden')">&times;</button>
                                 </div>

                             </div>
                         </div>

                         <!-- Right: TTS Audio Settings -->
                         <div id="overview-side-column" class="h-fit space-y-4">

                             <!-- TTS Settings Panel -->
                             <div id="tts-settings-section"
                                 class="p-4 bg-blue-50 border-2 border-blue-300 rounded-lg scroll-mt-24">
                                 <button type="button" id="ttsToggleBtn"
                                     class="w-full text-left flex items-center justify-between hover:opacity-75 transition">
                                     <h4 class="text-base font-semibold text-blue-900 flex items-center gap-2">
                                         🎙️ TTS Audio Settings
                                     </h4>
                                     <span id="ttsToggleIcon" class="text-xl">−</span>
                                 </button>

                                 <div id="ttsContent" class="space-y-3 mt-4">
                                     <!-- TTS Provider -->
                                     <div class="bg-white p-3 rounded border border-blue-200">
                                         <label class="block text-sm font-medium text-gray-700 mb-2">TTS Provider: <span
                                                 class="text-red-500">*</span></label>
                                         <select id="ttsProviderSelect"
                                             class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-blue-500 focus:outline-none">
                                             <option value="" {{ !$audioBook->tts_provider ? 'selected' : '' }}>--
                                                 Chọn TTS Provider --</option>
                                             <option value="openai"
                                                 {{ ($audioBook->tts_provider ?? '') === 'openai' ? 'selected' : '' }}>🤖
                                                 OpenAI TTS</option>
                                             <option value="gemini"
                                                 {{ ($audioBook->tts_provider ?? '') === 'gemini' ? 'selected' : '' }}>✨
                                                 Gemini Pro TTS</option>
                                             <option value="microsoft"
                                                 {{ ($audioBook->tts_provider ?? '') === 'microsoft' ? 'selected' : '' }}>
                                                 🪟
                                                 Microsoft TTS</option>
                                             <option value="vbee"
                                                 {{ ($audioBook->tts_provider ?? '') === 'vbee' ? 'selected' : '' }}>🇻🇳
                                                 Vbee TTS (Việt Nam)</option>
                                         </select>
                                     </div>

                                     <!-- Style Instruction (hidden for Microsoft/OpenAI) -->
                                     <div id="styleInstructionSection"
                                         class="bg-white p-3 rounded border border-blue-200">
                                         <label class="block text-sm font-medium text-gray-700 mb-2">Style
                                             Instruction <span class="text-xs text-gray-400">(chỉ Gemini)</span>:</label>
                                         <div class="flex flex-wrap gap-1 mb-2">
                                             <button type="button"
                                                 class="style-preset-btn px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                 data-text="Đọc với giọng ấm áp, chậm rãi,&#10;phong cách kể chuyện,&#10;tạo cảm giác gần gũi và cuốn hút.">🎙️
                                                 Storytelling</button>
                                             <button type="button"
                                                 class="style-preset-btn px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                 data-text="Đọc với giọng rất nhẹ, chậm rãi,&#10;thư giãn,&#10;phù hợp nội dung thiền và sức khỏe tinh thần.">🧘
                                                 Wellness</button>
                                             <button type="button"
                                                 class="style-preset-btn px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition"
                                                 data-text="Đọc với giọng tự nhiên, rõ ràng,&#10;nhịp vừa phải,&#10;phong cách đọc sách audio.">📚
                                                 Audiobook</button>
                                         </div>
                                         <textarea id="ttsStyleInstruction" rows="3"
                                             class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-blue-500 focus:outline-none"
                                             placeholder="Nhập hướng dẫn style...">{{ $audioBook->tts_style_instruction ?? '' }}</textarea>
                                     </div>

                                     <!-- Voice Settings -->
                                     <div class="bg-white p-3 rounded border border-blue-200">
                                         <div class="flex items-center justify-between mb-3">
                                             <label class="text-sm font-medium text-gray-700">Voice Settings:</label>
                                             <div class="flex items-center gap-3 text-sm text-gray-700">
                                                 <label class="inline-flex items-center gap-1 cursor-pointer">
                                                     <input type="radio" name="voiceGender" value="female"
                                                         {{ ($audioBook->tts_voice_gender ?? 'female') === 'female' ? 'checked' : '' }}>
                                                     <span>👩 Nữ</span>
                                                 </label>
                                                 <label class="inline-flex items-center gap-1 cursor-pointer">
                                                     <input type="radio" name="voiceGender" value="male"
                                                         {{ ($audioBook->tts_voice_gender ?? '') === 'male' ? 'checked' : '' }}>
                                                     <span>👨 Nam</span>
                                                 </label>
                                             </div>
                                         </div>
                                         <div>
                                             <label class="block text-xs font-medium text-gray-700 mb-1">Chọn
                                                 giọng:</label>
                                             <div class="flex gap-1">
                                                 <select id="voiceNameSelect"
                                                     class="flex-1 px-2 py-1.5 border border-gray-300 rounded text-sm focus:border-blue-500 focus:outline-none">
                                                     <option value="">-- Chọn giọng --</option>
                                                 </select>
                                                 <button type="button" id="voicePreviewBtn"
                                                     class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded text-sm font-medium transition"
                                                     title="Nghe thử giọng">
                                                     🔊
                                                 </button>
                                             </div>
                                         </div>
                                     </div>

                                     <!-- Speed & Pause Settings -->
                                     <div class="bg-white p-3 rounded border border-blue-200">
                                         <label class="block text-sm font-medium text-gray-700 mb-2">Tốc độ & Khoảng
                                             nghỉ:</label>
                                         <div class="grid grid-cols-2 gap-3">
                                             <div>
                                                 <label class="block text-xs text-gray-500 mb-1">Tốc độ đọc</label>
                                                 <select id="ttsSpeedSelect"
                                                     class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm focus:border-blue-500 focus:outline-none">
                                                     <option value="0.7"
                                                         {{ ($audioBook->tts_speed ?? 1.0) == 0.7 ? 'selected' : '' }}>0.7x
                                                         (Rất chậm)</option>
                                                     <option value="0.8"
                                                         {{ ($audioBook->tts_speed ?? 1.0) == 0.8 ? 'selected' : '' }}>0.8x
                                                         (Chậm)</option>
                                                     <option value="0.9"
                                                         {{ ($audioBook->tts_speed ?? 1.0) == 0.9 ? 'selected' : '' }}>0.9x
                                                         (Hơi chậm)</option>
                                                     <option value="1.0"
                                                         {{ ($audioBook->tts_speed ?? 1.0) == 1.0 ? 'selected' : '' }}>1.0x
                                                         (Bình thường)</option>
                                                     <option value="1.1"
                                                         {{ ($audioBook->tts_speed ?? 1.0) == 1.1 ? 'selected' : '' }}>1.1x
                                                         (Hơi nhanh)</option>
                                                     <option value="1.2"
                                                         {{ ($audioBook->tts_speed ?? 1.0) == 1.2 ? 'selected' : '' }}>1.2x
                                                         (Nhanh)</option>
                                                 </select>
                                             </div>
                                             <input type="hidden" id="pauseBetweenChunksSelect" value="0">
                                         </div>
                                     </div>

                                     <!-- Save Button -->
                                     <button type="button" id="saveTtsSettingsBtn"
                                         class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold transition duration-200">
                                         💾 Lưu cấu hình TTS
                                     </button>

                                     <!-- Intro/Outro Music Settings -->
                                     <div class="border-t border-gray-200 pt-4 mt-4">
                                         @php
                                             $systemMusicFiles = collect(Storage::disk('public')->allFiles('books'))
                                                 ->filter(
                                                     fn($path) =>
                                                     preg_match('/\/music\/.+\.(mp3|wav|m4a)$/i', $path),
                                                 )
                                                 ->sortDesc()
                                                 ->values();

                                             $systemMusicFilesForModal = $systemMusicFiles
                                                 ->map(function ($musicPath) use ($audioBook) {
                                                     preg_match('/^books\/(\d+)\/music\//', $musicPath, $matches);

                                                     return [
                                                         'path' => $musicPath,
                                                         'name' => basename($musicPath),
                                                         'url' => asset('storage/' . $musicPath),
                                                         'book_id' => isset($matches[1]) ? (int) $matches[1] : null,
                                                         'is_current_intro' => $audioBook->intro_music === $musicPath,
                                                         'is_current_outro' => $audioBook->outro_music === $musicPath,
                                                     ];
                                                 })
                                                 ->values();
                                         @endphp
                                         <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
                                             🎵 Nhạc Intro/Outro
                                         </h4>

                                         <!-- Intro Music -->
                                         <div class="bg-green-50 p-3 rounded border border-green-200 mb-3">
                                             <label class="block text-sm font-medium text-green-700 mb-2">🎬 Nhạc Intro (mở
                                                 đầu):</label>
                                             <div class="flex items-center gap-2 mb-2">
                                                 <input type="file" id="introMusicFile"
                                                     accept="audio/mp3,audio/wav,audio/m4a" class="hidden"
                                                     onchange="uploadMusic('intro')">
                                                 <button type="button"
                                                     onclick="openMusicPickerModal('intro')"
                                                     class="flex-1 px-3 py-2 bg-green-600 hover:bg-green-700 text-white rounded text-sm font-medium transition">
                                                     📁 Chọn file nhạc
                                                 </button>
                                                 @if ($audioBook->intro_music)
                                                     <button type="button" onclick="deleteMusic('intro')"
                                                         class="px-3 py-2 bg-red-500 hover:bg-red-600 text-white rounded text-sm font-medium transition">
                                                         🗑️
                                                     </button>
                                                 @endif
                                             </div>
                                             @if ($audioBook->intro_music)
                                                 <div class="flex items-center gap-2 p-2 bg-white rounded border">
                                                     <audio controls class="h-8 flex-1">
                                                         <source src="{{ asset('storage/' . $audioBook->intro_music) }}"
                                                             type="audio/mpeg">
                                                     </audio>
                                                     <span class="text-xs text-gray-500">✅ Đã tải</span>
                                                 </div>
                                             @else
                                                 <p class="text-xs text-gray-500 italic">Chưa có nhạc intro</p>
                                             @endif
                                             <div class="mt-2">
                                                 <label class="text-xs text-gray-600">Fade out (giây):</label>
                                                 <input type="number" id="introFadeDuration" min="1"
                                                     max="30" step="0.5"
                                                     value="{{ $audioBook->intro_fade_duration ?? 3 }}"
                                                     class="w-20 px-2 py-1 border border-gray-300 rounded text-sm">
                                             </div>
                                             <p class="mt-3 text-xs text-green-700">📚 Bấm "Chọn file nhạc" để mở thư viện
                                                 nhạc toàn hệ thống hoặc upload file mới.</p>
                                         </div>

                                         <!-- Outro Music -->
                                         <div class="bg-orange-50 p-3 rounded border border-orange-200 mb-3">
                                             <label class="block text-sm font-medium text-orange-700 mb-2">🎬 Nhạc Outro
                                                 (kết thúc):</label>

                                             <!-- Option: Use same as intro -->
                                             <div class="mb-3 p-2 bg-white rounded border border-orange-200">
                                                 <label class="flex items-center gap-2 cursor-pointer">
                                                     <input type="checkbox" id="outroUseIntro"
                                                         {{ $audioBook->outro_use_intro ? 'checked' : '' }}
                                                         onchange="toggleOutroUpload()"
                                                         class="w-4 h-4 text-orange-600 rounded focus:ring-orange-500">
                                                     <span class="text-sm text-gray-700">🔄 Dùng cùng nhạc Intro</span>
                                                 </label>
                                             </div>

                                             <div id="outroUploadSection"
                                                 class="{{ $audioBook->outro_use_intro ? 'hidden' : '' }}">
                                                 <div class="flex items-center gap-2 mb-2">
                                                     <input type="file" id="outroMusicFile"
                                                         accept="audio/mp3,audio/wav,audio/m4a" class="hidden"
                                                         onchange="uploadMusic('outro')">
                                                     <button type="button"
                                                         onclick="openMusicPickerModal('outro')"
                                                         class="flex-1 px-3 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded text-sm font-medium transition">
                                                         📁 Chọn file nhạc
                                                     </button>
                                                     @if ($audioBook->outro_music)
                                                         <button type="button" onclick="deleteMusic('outro')"
                                                             class="px-3 py-2 bg-red-500 hover:bg-red-600 text-white rounded text-sm font-medium transition">
                                                             🗑️
                                                         </button>
                                                     @endif
                                                 </div>
                                                 @if ($audioBook->outro_music)
                                                     <div class="flex items-center gap-2 p-2 bg-white rounded border">
                                                         <audio controls class="h-8 flex-1">
                                                             <source
                                                                 src="{{ asset('storage/' . $audioBook->outro_music) }}"
                                                                 type="audio/mpeg">
                                                         </audio>
                                                         <span class="text-xs text-gray-500">✅ Đã tải</span>
                                                     </div>
                                                 @else
                                                     <p class="text-xs text-gray-500 italic">Chưa có nhạc outro riêng</p>
                                                 @endif
                                             </div>

                                             <div id="outroUseIntroMessage"
                                                 class="{{ $audioBook->outro_use_intro ? '' : 'hidden' }} p-2 bg-green-50 rounded border border-green-200">
                                                 <p class="text-sm text-green-700">✅ Sẽ sử dụng nhạc Intro cho Outro</p>
                                             </div>

                                             <div class="mt-2 flex gap-4">
                                                 <div>
                                                     <label class="text-xs text-gray-600">Fade in (giây):</label>
                                                     <input type="number" id="outroFadeDuration" min="1"
                                                         max="30" step="0.5"
                                                         value="{{ $audioBook->outro_fade_duration ?? 10 }}"
                                                         class="w-20 px-2 py-1 border border-gray-300 rounded text-sm">
                                                 </div>
                                                 <div>
                                                     <label class="text-xs text-gray-600">Kéo dài thêm (giây):</label>
                                                     <input type="number" id="outroExtendDuration" min="0"
                                                         max="30" step="0.5"
                                                         value="{{ $audioBook->outro_extend_duration ?? 5 }}"
                                                         class="w-20 px-2 py-1 border border-gray-300 rounded text-sm">
                                                 </div>
                                             </div>
                                             <p class="mt-3 text-xs text-orange-700">📚 Bấm "Chọn file nhạc" để mở thư viện
                                                 nhạc toàn hệ thống hoặc upload file mới.</p>
                                         </div>

                                         <!-- Save Music Settings Button -->
                                         <button type="button" id="saveMusicSettingsBtn"
                                             class="w-full bg-purple-600 hover:bg-purple-700 text-white py-2 rounded-lg font-semibold transition duration-200">
                                             💾 Lưu cấu hình nhạc
                                         </button>

                                         <!-- Music Merge Progress -->
                                         <div id="musicMergeProgressContainer"
                                             class="hidden mt-4 p-4 bg-purple-50 border border-purple-200 rounded-lg">
                                             <div class="flex items-center justify-between mb-2">
                                                 <span class="text-sm font-medium text-purple-800"
                                                     id="musicMergeStatus">Đang merge...</span>
                                                 <span class="text-sm text-purple-600" id="musicMergePercent">0%</span>
                                             </div>
                                             <div class="w-full bg-purple-200 rounded-full h-2.5 mb-3">
                                                 <div id="musicMergeProgressBar"
                                                     class="bg-purple-600 h-2.5 rounded-full transition-all duration-300"
                                                     style="width: 0%"></div>
                                             </div>
                                             <div id="musicMergeLog"
                                                 class="max-h-32 overflow-y-auto text-xs bg-white p-2 rounded border border-purple-100">
                                             </div>
                                         </div>
                                     </div>

                                     <!-- Wave Effect Settings for Video -->
                                     <div class="border-t border-gray-200 pt-4 mt-4">
                                         <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
                                             📊 Hiệu ứng sóng âm (Video)
                                         </h4>

                                         <!-- Enable Wave -->
                                         <div class="mb-3 p-2 bg-blue-50 rounded border border-blue-200">
                                             <label class="flex items-center gap-2 cursor-pointer">
                                                 <input type="checkbox" id="waveEnabled"
                                                     {{ $audioBook->wave_enabled ? 'checked' : '' }}
                                                     onchange="toggleWaveSettings()"
                                                     class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                                                 <span class="text-sm font-medium text-gray-700">🎵 Bật hiệu ứng sóng
                                                     âm</span>
                                             </label>
                                         </div>

                                         <div id="waveSettingsPanel"
                                             class="{{ $audioBook->wave_enabled ? '' : 'hidden' }}">
                                             <!-- Wave Type -->
                                             <div class="bg-gray-50 p-3 rounded border border-gray-200 mb-3">
                                                 <label class="block text-xs font-medium text-gray-600 mb-2">Kiểu
                                                     sóng:</label>
                                                 <div class="grid grid-cols-2 gap-2">
                                                     <label
                                                         class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                         <input type="radio" name="waveType" value="cline"
                                                             {{ ($audioBook->wave_type ?? 'cline') === 'cline' ? 'checked' : '' }}
                                                             class="text-blue-600">
                                                         <span class="text-xs">〰️ Curved Line</span>
                                                     </label>
                                                     <label
                                                         class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                         <input type="radio" name="waveType" value="line"
                                                             {{ ($audioBook->wave_type ?? '') === 'line' ? 'checked' : '' }}
                                                             class="text-blue-600">
                                                         <span class="text-xs">📈 Line</span>
                                                     </label>
                                                     <label
                                                         class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                         <input type="radio" name="waveType" value="p2p"
                                                             {{ ($audioBook->wave_type ?? '') === 'p2p' ? 'checked' : '' }}
                                                             class="text-blue-600">
                                                         <span class="text-xs">📊 Point to Point</span>
                                                     </label>
                                                     <label
                                                         class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                         <input type="radio" name="waveType" value="bar"
                                                             {{ ($audioBook->wave_type ?? '') === 'bar' ? 'checked' : '' }}
                                                             class="text-blue-600">
                                                         <span class="text-xs">📶 Bar</span>
                                                     </label>
                                                     <label
                                                         class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                         <input type="radio" name="waveType" value="point"
                                                             {{ ($audioBook->wave_type ?? '') === 'point' ? 'checked' : '' }}
                                                             class="text-blue-600">
                                                         <span class="text-xs">⚫ Point</span>
                                                     </label>
                                                 </div>
                                             </div>

                                             <!-- Wave Position -->
                                             <div class="bg-gray-50 p-3 rounded border border-gray-200 mb-3">
                                                 <label class="block text-xs font-medium text-gray-600 mb-2">Vị
                                                     trí:</label>
                                                 <div class="flex gap-2">
                                                     <label
                                                         class="flex-1 flex items-center justify-center gap-1 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                         <input type="radio" name="wavePosition" value="top"
                                                             {{ ($audioBook->wave_position ?? '') === 'top' ? 'checked' : '' }}
                                                             class="text-blue-600">
                                                         <span class="text-xs">⬆️ Trên</span>
                                                     </label>
                                                     <label
                                                         class="flex-1 flex items-center justify-center gap-1 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                         <input type="radio" name="wavePosition" value="center"
                                                             {{ ($audioBook->wave_position ?? '') === 'center' ? 'checked' : '' }}
                                                             class="text-blue-600">
                                                         <span class="text-xs">↔️ Giữa</span>
                                                     </label>
                                                     <label
                                                         class="flex-1 flex items-center justify-center gap-1 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                                         <input type="radio" name="wavePosition" value="bottom"
                                                             {{ ($audioBook->wave_position ?? 'bottom') === 'bottom' ? 'checked' : '' }}
                                                             class="text-blue-600">
                                                         <span class="text-xs">⬇️ Dưới</span>
                                                     </label>
                                                 </div>
                                             </div>

                                             <!-- Wave Height, Width, Color, Opacity -->
                                             <div class="bg-gray-50 p-3 rounded border border-gray-200 mb-3">
                                                 <div class="grid grid-cols-4 gap-3">
                                                     <div>
                                                         <label class="block text-xs font-medium text-gray-600 mb-1">Chiều
                                                             cao (px):</label>
                                                         <input type="number" id="waveHeight" min="50"
                                                             max="300" step="10"
                                                             value="{{ $audioBook->wave_height ?? 100 }}"
                                                             class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                                     </div>
                                                     <div>
                                                         <label class="block text-xs font-medium text-gray-600 mb-1">Độ
                                                             rộng (%):</label>
                                                         <input type="number" id="waveWidth" min="20"
                                                             max="100" step="5"
                                                             value="{{ $audioBook->wave_width ?? 100 }}"
                                                             class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                                     </div>
                                                     <div>
                                                         <label class="block text-xs font-medium text-gray-600 mb-1">Màu
                                                             sắc:</label>
                                                         <input type="color" id="waveColor"
                                                             value="{{ $audioBook->wave_color ?? '#00ff00' }}"
                                                             class="w-full h-8 rounded border border-gray-300 cursor-pointer">
                                                     </div>
                                                     <div>
                                                         <label class="block text-xs font-medium text-gray-600 mb-1">Độ
                                                             mờ:</label>
                                                         <input type="range" id="waveOpacity" min="0.1"
                                                             max="1" step="0.1"
                                                             value="{{ $audioBook->wave_opacity ?? 0.8 }}"
                                                             class="w-full h-8">
                                                         <span class="text-xs text-gray-500"
                                                             id="waveOpacityValue">{{ $audioBook->wave_opacity ?? 0.8 }}</span>
                                                     </div>
                                                 </div>
                                             </div>
                                         </div>

                                         <!-- Save Wave Settings Button -->
                                         <button type="button" id="saveWaveSettingsBtn"
                                             class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold transition duration-200">
                                             💾 Lưu cấu hình sóng âm
                                         </button>
                                     </div>
                                 </div>
                             </div>

                             <!-- Art Style / Character Description for Image Generation -->
                             <div id="art-style-section"
                                 class="p-4 bg-purple-50 border-2 border-purple-300 rounded-lg scroll-mt-24">
                                 <button type="button" id="artStyleToggleBtn"
                                     class="w-full text-left flex items-center justify-between hover:opacity-75 transition">
                                     <h4 class="text-base font-semibold text-purple-900 flex items-center gap-2">
                                         🎨 Phong cách nhân vật & hình ảnh AI
                                     </h4>
                                     <span id="artStyleToggleIcon" class="text-xl">+</span>
                                 </button>

                                 <div id="artStyleContent" class="space-y-3 mt-4" style="display: none;">
                                     <p class="text-xs text-gray-600">Định nghĩa bối cảnh văn hóa, ngoại hình nhân vật, trang phục... để AI tạo ảnh đúng. Áp dụng cho Clipping, Short Video, Review Assets, Scene.</p>

                                     <!-- Built-in Presets -->
                                     <div class="text-xs text-gray-500 font-medium mb-1">📦 Preset có sẵn:</div>
                                     <div class="flex flex-wrap gap-1.5 mb-2">
                                         <button type="button" class="art-preset-btn px-2 py-1 bg-red-50 hover:bg-red-100 text-red-700 rounded text-xs font-medium transition border border-red-200"
                                             data-text="Chinese ancient setting. Characters are East Asian with black hair, wearing traditional Chinese Hanfu robes (ancient dynasty). Architecture and scenery reflect ancient China with pagodas, bamboo forests, misty mountains.">&#127464;&#127475; Trung Quốc cổ đại</button>
                                         <button type="button" class="art-preset-btn px-2 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded text-xs font-medium transition border border-blue-200"
                                             data-text="Korean historical drama setting. Characters are East Asian with Korean traditional hanbok. Joseon dynasty architecture, royal palaces, scenic mountains.">&#127472;&#127479; Hàn Quốc cổ trang</button>
                                         <button type="button" class="art-preset-btn px-2 py-1 bg-pink-50 hover:bg-pink-100 text-pink-700 rounded text-xs font-medium transition border border-pink-200"
                                             data-text="Japanese anime style. Characters have anime proportions, colorful hair, large expressive eyes. Modern or fantasy Japanese setting with cherry blossoms, shrines, neon cities.">&#127471;&#127477; Anime Nhật Bản</button>
                                         <button type="button" class="art-preset-btn px-2 py-1 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded text-xs font-medium transition border border-amber-200"
                                             data-text="Vietnamese setting. Characters are Southeast Asian Vietnamese people. Traditional ao dai, non la (conical hat), rice paddies, Vietnamese countryside or old Hanoi streets.">&#127483;&#127475; Việt Nam</button>
                                         <button type="button" class="art-preset-btn px-2 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded text-xs font-medium transition border border-indigo-200"
                                             data-text="Medieval European fantasy setting. Characters are Caucasian or diverse fantasy races. Medieval armor, castles, magical forests, European medieval architecture.">&#127758; Châu Âu trung cổ</button>
                                         <button type="button" class="art-preset-btn px-2 py-1 bg-gray-50 hover:bg-gray-100 text-gray-700 rounded text-xs font-medium transition border border-gray-200"
                                             data-text="Modern contemporary setting. Realistic diverse characters in modern clothing. Urban city environment, offices, apartments, cafes.">&#127961;️ Hiện đại</button>
                                     </div>

                                     <!-- Custom Presets -->
                                     <div class="text-xs text-gray-500 font-medium mb-1 flex items-center justify-between">
                                         <span>⭐ Preset tự tạo:</span>
                                         <button type="button" id="addCustomPresetBtn"
                                             class="text-purple-600 hover:text-purple-800 text-xs font-semibold flex items-center gap-0.5 transition">
                                             ＋ Thêm mới
                                         </button>
                                     </div>
                                     <div id="customPresetsContainer" class="flex flex-wrap gap-1.5 mb-2">
                                         <span id="noCustomPresetsMsg" class="text-xs text-gray-400 italic">Chưa có preset tự tạo</span>
                                     </div>

                                     <!-- Add New Preset Form (hidden by default) -->
                                     <div id="addPresetForm" class="hidden bg-white border border-purple-200 rounded-lg p-3 space-y-2 mb-2">
                                         <div class="text-sm font-semibold text-purple-800">✨ Tạo preset mới</div>
                                         <div class="flex gap-2">
                                             <input type="text" id="newPresetIcon" value="🎨" maxlength="4"
                                                 class="w-12 px-2 py-1.5 border border-gray-300 rounded text-center text-sm focus:border-purple-400 focus:outline-none"
                                                 placeholder="Icon">
                                             <input type="text" id="newPresetName" maxlength="100"
                                                 class="flex-1 px-2 py-1.5 border border-gray-300 rounded text-sm focus:border-purple-400 focus:outline-none"
                                                 placeholder="Tên preset (VD: Wuxia cổ trang)">
                                         </div>
                                         <textarea id="newPresetDescription" rows="3"
                                             class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm focus:border-purple-400 focus:outline-none"
                                             placeholder="Mô tả chi tiết phong cách (VD: Chinese Wuxia martial arts setting. Characters are East Asian warriors with flowing robes, wielding swords...)"></textarea>
                                         <div class="flex gap-2">
                                             <button type="button" id="saveNewPresetBtn"
                                                 class="flex-1 bg-purple-600 hover:bg-purple-700 text-white py-1.5 rounded text-sm font-semibold transition">
                                                 💾 Lưu preset
                                             </button>
                                             <button type="button" id="cancelNewPresetBtn"
                                                 class="px-4 bg-gray-200 hover:bg-gray-300 text-gray-700 py-1.5 rounded text-sm font-medium transition">
                                                 Huỷ
                                             </button>
                                         </div>
                                     </div>

                                     <!-- Edit Preset Form (hidden by default) -->
                                     <div id="editPresetForm" class="hidden bg-white border border-yellow-300 rounded-lg p-3 space-y-2 mb-2">
                                         <div class="text-sm font-semibold text-yellow-800">✏️ Chỉnh sửa preset</div>
                                         <input type="hidden" id="editPresetId">
                                         <div class="flex gap-2">
                                             <input type="text" id="editPresetIcon" maxlength="4"
                                                 class="w-12 px-2 py-1.5 border border-gray-300 rounded text-center text-sm focus:border-yellow-400 focus:outline-none"
                                                 placeholder="Icon">
                                             <input type="text" id="editPresetName" maxlength="100"
                                                 class="flex-1 px-2 py-1.5 border border-gray-300 rounded text-sm focus:border-yellow-400 focus:outline-none"
                                                 placeholder="Tên preset">
                                         </div>
                                         <textarea id="editPresetDescription" rows="3"
                                             class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm focus:border-yellow-400 focus:outline-none"
                                             placeholder="Mô tả chi tiết phong cách"></textarea>
                                         <div class="flex gap-2">
                                             <button type="button" id="updatePresetBtn"
                                                 class="flex-1 bg-yellow-500 hover:bg-yellow-600 text-white py-1.5 rounded text-sm font-semibold transition">
                                                 💾 Cập nhật
                                             </button>
                                             <button type="button" id="cancelEditPresetBtn"
                                                 class="px-4 bg-gray-200 hover:bg-gray-300 text-gray-700 py-1.5 rounded text-sm font-medium transition">
                                                 Huỷ
                                             </button>
                                         </div>
                                     </div>

                                     <textarea id="artStyleInstruction" rows="4"
                                         class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-300 bg-white"
                                         placeholder="Ví dụ: Chinese ancient setting. Characters are East Asian with black hair, wearing traditional Hanfu robes...">{{ $audioBook->art_style_instruction ?? '' }}</textarea>
                                     <div class="flex gap-2">
                                         <button type="button" id="saveArtStyleBtn"
                                             class="flex-1 bg-purple-600 hover:bg-purple-700 text-white py-2 rounded-lg font-semibold transition duration-200">
                                             💾 Lưu phong cách hình ảnh
                                         </button>
                                         <button type="button" id="saveAsPresetBtn"
                                             class="px-4 bg-white hover:bg-purple-50 text-purple-600 py-2 rounded-lg font-semibold transition duration-200 border-2 border-purple-300"
                                             title="Lưu nội dung hiện tại thành preset mới">
                                             ⭐ Lưu thành Preset
                                         </button>
                                     </div>
                                 </div>
                             </div>

                         </div>
                     </div>
                 </div>
             </div>

             <!-- Tab Navigation -->
             <div class="mb-6 hidden">
                 <div class="border-b border-gray-200 overflow-x-auto scrollbar-hide">
                     <nav class="-mb-px flex gap-2 sm:gap-4 min-w-max" aria-label="Tabs">
                         <button type="button" data-tab="chapters"
                             class="tab-btn active whitespace-nowrap border-b-2 py-2.5 sm:py-3 px-3 sm:px-4 text-xs sm:text-sm font-medium transition
                                   border-blue-500 text-blue-600">
                             📖 <span class="hidden sm:inline">Danh sách </span>Chương
                         </button>
                         <button type="button" data-tab="youtube-media"
                             class="tab-btn whitespace-nowrap border-b-2 py-2.5 sm:py-3 px-3 sm:px-4 text-xs sm:text-sm font-medium transition
                                   border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700">
                             🎨 <span class="hidden sm:inline">YouTube </span>Media<span class="hidden sm:inline"> (AI)</span>
                         </button>
                         <button type="button" data-tab="short-video"
                             class="tab-btn whitespace-nowrap border-b-2 py-2.5 sm:py-3 px-3 sm:px-4 text-xs sm:text-sm font-medium transition
                                   border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700">
                             📱 Short<span class="hidden sm:inline"> Video</span>
                         </button>
                         <button type="button" data-tab="clipping"
                             class="tab-btn whitespace-nowrap border-b-2 py-2.5 sm:py-3 px-3 sm:px-4 text-xs sm:text-sm font-medium transition
                                   border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700">
                             ✂️ Clipping
                         </button>
                         <button type="button" data-tab="auto-publish"
                             class="tab-btn whitespace-nowrap border-b-2 py-2.5 sm:py-3 px-3 sm:px-4 text-xs sm:text-sm font-medium transition
                                   border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700">
                             🚀 <span class="hidden sm:inline">Phát hành </span>Publish
                         </button>
                     </nav>
                 </div>
             </div>

             <!-- YouTube Media Tab Content -->
             <div id="youtube-media-tab" class="tab-content hidden">
                 <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                     <div class="p-6">
                         <div class="flex justify-between items-center mb-6">
                             <h3 class="text-lg font-semibold text-gray-800">🎨 Tạo Media cho YouTube bằng AI</h3>
                             <div class="flex gap-2">
                                 <button type="button" onclick="refreshMediaGallery()"
                                     class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg transition">
                                     🔄 Refresh
                                 </button>
                             </div>
                         </div>

                         <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                             <!-- Thumbnail Generator -->
                             <div
                                 class="bg-gradient-to-br from-purple-50 to-pink-50 border border-purple-200 rounded-lg p-5">
                                 <h4 class="text-base font-semibold text-purple-800 mb-4 flex items-center gap-2">
                                     🖼️ Tạo Thumbnail
                                 </h4>

                                 <div class="space-y-4">
                                     <!-- Text Info Preview - Editable -->
                                     <div class="p-3 bg-white rounded-lg border border-purple-200">
                                         <p class="text-xs font-medium text-gray-600 mb-2">📝 Thông tin sẽ hiển thị trên
                                             thumbnail <span class="text-purple-500">(có thể sửa)</span>:</p>
                                         <div class="space-y-2">
                                             <input type="text" id="thumbnailTitle"
                                                 class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm font-semibold text-purple-700 focus:border-purple-500 focus:outline-none"
                                                 value="{{ $audioBook->title }}" placeholder="Tiêu đề sách">
                                             <input type="text" id="thumbnailAuthor"
                                                 class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm text-gray-600 focus:border-purple-500 focus:outline-none"
                                                 value="{{ $audioBook->author ? 'Tác giả: ' . $audioBook->author : '' }}"
                                                 placeholder="Tác giả: ...">
                                         </div>
                                     </div>

                                     <!-- Chapter Number (optional) -->
                                     <div>
                                         <label class="block text-sm font-medium text-gray-700 mb-2">
                                             Số chương <span class="text-xs text-gray-400">(tùy chọn, để trống nếu là
                                                 thumbnail tổng)</span>:
                                         </label>
                                         <input type="number" id="thumbnailChapterNumber" min="1" max="999"
                                             class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-purple-500 focus:outline-none"
                                             placeholder="Ví dụ: 1, 2, 3...">
                                     </div>

                                     <!-- Style Selection -->
                                     <div>
                                         <label class="block text-sm font-medium text-gray-700 mb-2">Phong cách hình
                                             nền:</label>
                                         <div class="grid grid-cols-2 gap-2">
                                             <label
                                                 class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                 <input type="radio" name="thumbnailStyle" value="cinematic" checked
                                                     class="text-purple-600">
                                                 <span class="text-sm">🎬 Cinematic</span>
                                             </label>
                                             <label
                                                 class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                 <input type="radio" name="thumbnailStyle" value="gradient"
                                                     class="text-purple-600">
                                                 <span class="text-sm">🌈 Gradient</span>
                                             </label>
                                             <label
                                                 class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                 <input type="radio" name="thumbnailStyle" value="modern"
                                                     class="text-purple-600">
                                                 <span class="text-sm">✨ Modern</span>
                                             </label>
                                             <label
                                                 class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                 <input type="radio" name="thumbnailStyle" value="fantasy"
                                                     class="text-purple-600">
                                                 <span class="text-sm">🧙 Fantasy</span>
                                             </label>
                                             <label
                                                 class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                 <input type="radio" name="thumbnailStyle" value="mystery"
                                                     class="text-purple-600">
                                                 <span class="text-sm">🔮 Mystery</span>
                                             </label>
                                             <label
                                                 class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                 <input type="radio" name="thumbnailStyle" value="romance"
                                                     class="text-purple-600">
                                                 <span class="text-sm">💕 Romance</span>
                                             </label>
                                             <label
                                                 class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                 <input type="radio" name="thumbnailStyle" value="anime"
                                                     class="text-purple-600">
                                                 <span class="text-sm">🎌 Anime</span>
                                             </label>
                                             <label
                                                 class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-purple-400 transition">
                                                 <input type="radio" name="thumbnailStyle" value="vintage"
                                                     class="text-purple-600">
                                                 <span class="text-sm">📜 Vintage</span>
                                             </label>
                                         </div>
                                     </div>

                                     <!-- Custom Prompt -->
                                     <div id="customPromptSection">
                                         <label class="block text-sm font-medium text-gray-700 mb-2">
                                             Mô tả cảnh nền <span class="text-xs text-gray-400">(tùy chọn)</span>:
                                         </label>
                                         <textarea id="thumbnailCustomPrompt" rows="2"
                                             class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-purple-500 focus:outline-none"
                                             placeholder="Ví dụ: người đàn ông cầm kiếm đứng trên núi, hoàng hôn..."></textarea>
                                         <p class="text-xs text-gray-400 mt-1">💡 Mô tả hình nền bạn muốn. Thông tin sách
                                             (tiêu đề, tác giả) sẽ tự động được thêm vào.</p>
                                     </div>

                                     <!-- Use Cover Image Option -->
                                     <!-- Prefer Portrait Option -->
                                     <div class="p-3 bg-gradient-to-r from-purple-50 to-pink-50 border border-purple-200 rounded-lg">
                                         <label class="flex items-start gap-3 cursor-pointer">
                                             <input type="checkbox" id="preferPortraitOption" class="mt-1 text-purple-600 rounded" checked>
                                             <div>
                                                 <span class="text-sm font-medium text-purple-800">👤 Ưu tiên chân dung nhân vật chính (mặt nhìn thẳng)</span>
                                                 <p class="text-xs text-purple-700 mt-1">Head-and-shoulders, camera nhìn thẳng vào mặt, 1 người duy nhất. Với chế độ có chữ, sẽ ưu tiên đặt nhân vật 1/3 trái để chừa chỗ bên phải.</p>
                                             </div>
                                         </label>
                                     </div>

                                     <!-- Use Cover Image Option -->
                                     @if ($audioBook->cover_image)
                                         <div
                                             class="p-3 bg-gradient-to-r from-orange-50 to-amber-50 border border-orange-200 rounded-lg">
                                             <label class="flex items-start gap-3 cursor-pointer">
                                                 <input type="checkbox" id="useCoverImageOption"
                                                     class="mt-1 text-orange-600 rounded">
                                                 <div class="flex-1">
                                                     <span class="text-sm font-medium text-orange-800">🖼️ Sử dụng ảnh bìa
                                                         làm nền</span>
                                                     <p class="text-xs text-orange-600 mt-1">Lấy ảnh bìa sách và thêm text
                                                         overlay (tiêu đề, tác giả, chương) lên đó.</p>
                                                 </div>
                                                 <img src="{{ asset('storage/' . $audioBook->cover_image) }}"
                                                     class="w-12 h-16 object-cover rounded border" alt="Cover">
                                             </label>
                                         </div>
                                     @endif

                                     <!-- AI Research Option -->
                                     <div id="aiResearchSection"
                                         class="p-3 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-lg">
                                         <label class="flex items-start gap-3 cursor-pointer">
                                             <input type="checkbox" id="aiResearchOption"
                                                 class="mt-1 text-green-600 rounded">
                                             <div>
                                                 <span class="text-sm font-medium text-green-800">🔍 AI tự tìm thông tin về
                                                     truyện</span>
                                                 <p class="text-xs text-green-600 mt-1">AI sẽ tìm kiếm thông tin trên
                                                     internet về nội dung truyện và tự động tạo prompt hình ảnh phù hợp.</p>
                                             </div>
                                         </label>
                                     </div>

                                     <div>
                                         <label class="block text-sm font-medium text-gray-700 mb-2">Image Provider:</label>
                                         <select id="thumbnailImageProvider"
                                             class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-purple-500 focus:outline-none">
                                             <option value="gemini">Gemini</option>
                                             <option value="flux">Flux (AIML)</option>
                                         </select>
                                     </div>

                                     <button type="button" id="generateThumbnailBtn"
                                         class="w-full bg-purple-600 hover:bg-purple-700 text-white py-2.5 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                                         🖼️ Tạo Hình Nền (Không chữ)
                                     </button>

                                     <button type="button" id="generateThumbnailWithTextBtn"
                                         class="w-full bg-gradient-to-r from-orange-500 to-pink-500 hover:from-orange-600 hover:to-pink-600 text-white py-2.5 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                                         ✨ Tạo Thumbnail (AI Vẽ Chữ Luôn)
                                     </button>

                                     <p class="text-xs text-gray-500 text-center mt-1">
                                         💡 <strong>Không chữ:</strong> AI tạo hình nền → bạn thêm text sau bằng FFmpeg<br>
                                         <strong>AI Vẽ Chữ:</strong> AI tạo hình VÀ vẽ chữ trực tiếp vào hình (1 bước)
                                     </p>

                                     <!-- Prompt Preview/Edit Area -->
                                     <div id="thumbnailPromptArea" class="hidden mt-3">
                                         <div class="flex items-center justify-between mb-1">
                                             <label class="text-xs font-medium text-gray-600">Prompt (chỉnh sửa trước khi
                                                 tạo):</label>
                                             <button type="button" id="thumbnailPromptToggle"
                                                 onclick="document.getElementById('thumbnailPromptTextarea').classList.toggle('hidden')"
                                                 class="text-xs text-blue-600 hover:underline">Thu gọn/Mở rộng</button>
                                         </div>
                                         <textarea id="thumbnailPromptTextarea" rows="8"
                                             class="w-full px-3 py-2 border border-gray-300 rounded-lg text-xs font-mono focus:border-blue-500 focus:outline-none resize-y"
                                             placeholder="Prompt sẽ hiện ở đây..."></textarea>
                                         <div class="flex gap-2 mt-2">
                                             <button type="button" id="thumbnailPromptGenerateBtn"
                                                 class="flex-1 bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg text-sm font-semibold transition">
                                                 🚀 Tạo với prompt này
                                             </button>
                                             <button type="button" id="thumbnailPromptCancelBtn"
                                                 onclick="document.getElementById('thumbnailPromptArea').classList.add('hidden')"
                                                 class="px-4 bg-gray-300 hover:bg-gray-400 text-gray-700 py-2 rounded-lg text-sm font-semibold transition">
                                                 Hủy
                                             </button>
                                         </div>
                                     </div>

                                     <div id="thumbnailStatus" class="text-sm"></div>
                                 </div>
                             </div>

                             <!-- Video Scenes Generator -->
                             <div class="bg-gradient-to-br from-blue-50 to-cyan-50 border border-blue-200 rounded-lg p-5">
                                 <h4 class="text-base font-semibold text-blue-800 mb-4 flex items-center gap-2">
                                     🎬 Tạo Hình Minh Họa cho Video <span class="text-xs font-normal text-gray-500">(không
                                         có chữ)</span>
                                 </h4>

                                 <div class="space-y-4">
                                     <!-- Style Selection -->
                                     <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                         <div>
                                             <label class="block text-sm font-medium text-gray-700 mb-2">Phong cách:</label>
                                             <select id="sceneStyle"
                                                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-blue-500 focus:outline-none">
                                                 <option value="cinematic">🎬 Cinematic - Điện ảnh</option>
                                                 <option value="anime">🎌 Anime - Hoạt hình Nhật</option>
                                                 <option value="illustration">🎨 Illustration - Minh họa</option>
                                                 <option value="realistic">📷 Realistic - Thực tế</option>
                                             </select>
                                         </div>
                                         <div>
                                             <label class="block text-sm font-medium text-gray-700 mb-2">Image Provider:</label>
                                             <select id="sceneImageProvider"
                                                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-blue-500 focus:outline-none">
                                                 <option value="gemini">Gemini</option>
                                                 <option value="flux">Flux (AIML)</option>
                                             </select>
                                         </div>
                                     </div>

                                     <div
                                         class="p-3 bg-gradient-to-r from-blue-50 to-purple-50 border border-blue-200 rounded-lg">
                                         <p class="text-xs text-blue-800 mb-2">
                                             🤖 <strong>Quy trình 2 bước:</strong>
                                         </p>
                                         <ul class="text-xs text-gray-700 space-y-1 ml-4 list-disc">
                                             <li><strong>Bước 1:</strong> AI phân tích giới thiệu sách → tạo danh sách phân
                                                 cảnh + prompt</li>
                                             <li><strong>Bước 2:</strong> Xem lại prompt, có thể sửa → tạo ảnh từng cảnh
                                             </li>
                                         </ul>
                                     </div>

                                     <!-- Step 1 Button -->
                                     <button type="button" id="analyzeSceneBtn"
                                         class="w-full bg-purple-600 hover:bg-purple-700 text-white py-2.5 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                                         🧠 Bước 1: AI Phân Tích Nội Dung
                                     </button>

                                     <div id="scenesStatus" class="text-sm"></div>

                                     <!-- Scene Analysis Results (hidden until step 1 done) -->
                                     <div id="sceneAnalysisResults" class="hidden space-y-3">
                                         <div class="flex items-center justify-between">
                                             <h5 class="text-sm font-semibold text-gray-800">📋 Kết quả phân tích:</h5>
                                             <span id="sceneAnalysisCount"
                                                 class="text-xs text-purple-600 font-medium"></span>
                                         </div>
                                         <div id="scenePromptsList" class="space-y-2 max-h-80 overflow-y-auto"></div>

                                         <!-- Step 2 Button -->
                                         <button type="button" id="generateAllScenesBtn"
                                             class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                                             🎨 Bước 2: Tạo Ảnh Tất Cả Cảnh
                                         </button>
                                     </div>

                                     <!-- Scenes Progress -->
                                     <div id="scenesProgress" class="hidden">
                                         <div class="flex items-center justify-between text-xs text-blue-700 mb-1">
                                             <span id="scenesProgressText">Đang tạo cảnh 0/5...</span>
                                             <span id="scenesProgressPercent">0%</span>
                                         </div>
                                         <div class="w-full bg-blue-100 rounded-full h-2">
                                             <div id="scenesProgressBar"
                                                 class="bg-blue-500 h-2 rounded-full transition-all duration-300"
                                                 style="width: 0%"></div>
                                         </div>
                                     </div>
                                 </div>
                             </div>

                             <!-- ========== DESCRIPTION VIDEO PIPELINE (Chunked) ========== -->
                             <div
                                 class="hidden bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-300 rounded-lg p-5">
                                 <h4 class="text-base font-semibold text-emerald-800 mb-3 flex items-center gap-2">
                                     🎬 Tạo Video Giới Thiệu Sách (Pipeline)
                                 </h4>
                                 <p class="text-xs text-gray-600 mb-4">
                                     Pipeline tự động: AI chia nội dung → tạo ảnh minh họa → TTS → subtitle → ghép video +
                                     nhạc nền.
                                 </p>

                                 <!-- Pipeline Steps -->
                                 <div class="space-y-3">
                                     <!-- Step 1+2: Chunk + Analyze -->
                                     <div class="p-3 bg-white rounded-lg border border-emerald-200">
                                         <div class="flex items-center justify-between">
                                             <div class="flex items-center gap-2">
                                                 <span
                                                     class="bg-emerald-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center">1</span>
                                                 <span class="text-sm font-medium text-gray-800">AI Phân tích & Chia
                                                     đoạn</span>
                                             </div>
                                             <button type="button" id="descChunkBtn"
                                                 class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs px-4 py-2 rounded-lg font-medium transition">
                                                 🧠 Phân tích
                                             </button>
                                         </div>
                                         <p class="text-xs text-gray-500 mt-1 ml-8">AI đọc giới thiệu sách → chia thành
                                             từng
                                             đoạn + tạo prompt ảnh minh họa</p>
                                         <div id="descChunkStatus" class="mt-2 text-sm ml-8"></div>
                                     </div>

                                     <!-- Chunks List (hidden until step 1 done) -->
                                     <div id="descChunksList" class="hidden">
                                         <div
                                             class="p-3 bg-white rounded-lg border border-gray-200 max-h-[500px] overflow-y-auto">
                                             <div class="flex items-center justify-between mb-2">
                                                 <h5 class="text-sm font-semibold text-gray-800">📋 Danh sách chunks:</h5>
                                                 <span id="descChunksCount"
                                                     class="text-xs text-emerald-600 font-medium"></span>
                                             </div>
                                             <div id="descChunksItems" class="space-y-2"></div>
                                         </div>
                                     </div>

                                     <!-- Step 3: Generate Images -->
                                     <div class="p-3 bg-white rounded-lg border border-blue-200">
                                         <div class="flex items-center justify-between">
                                             <div class="flex items-center gap-2">
                                                 <span
                                                     class="bg-blue-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center">2</span>
                                                 <span class="text-sm font-medium text-gray-800">Tạo ảnh minh họa</span>
                                             </div>
                                             <button type="button" id="descGenImagesBtn"
                                                 class="bg-blue-600 hover:bg-blue-700 text-white text-xs px-4 py-2 rounded-lg font-medium transition hidden">
                                                 🎨 Tạo tất cả ảnh
                                             </button>
                                         </div>
                                         <div class="ml-8 mt-2 max-w-xs">
                                             <label class="block text-xs font-medium text-gray-600 mb-1">Image Provider</label>
                                             <select id="descImageProvider"
                                                 class="w-full px-2 py-1.5 border border-blue-200 rounded text-xs focus:outline-none focus:border-blue-500 bg-white">
                                                 <option value="gemini">Gemini</option>
                                                 <option value="flux">Flux (AIML)</option>
                                             </select>
                                         </div>
                                         <p class="text-xs text-gray-500 mt-1 ml-8">Tạo ảnh minh họa cho từng chunk bằng
                                             AI (Gemini hoặc Flux)</p>
                                         <div id="descGenImagesStatus" class="mt-2 text-sm ml-8"></div>
                                     </div>

                                     <!-- Step 4: Generate TTS Audio -->
                                     <div class="p-3 bg-white rounded-lg border border-purple-200">
                                         <div class="flex items-center justify-between">
                                             <div class="flex items-center gap-2">
                                                 <span
                                                     class="bg-purple-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center">3</span>
                                                 <span class="text-sm font-medium text-gray-800">Tạo TTS audio</span>
                                             </div>
                                             <button type="button" id="descGenTtsBtn"
                                                 class="bg-purple-600 hover:bg-purple-700 text-white text-xs px-4 py-2 rounded-lg font-medium transition hidden">
                                                 🎙️ Tạo tất cả audio
                                             </button>
                                         </div>
                                         <p class="text-xs text-gray-500 mt-1 ml-8">Chuyển text từng chunk thành audio với
                                             TTS (dùng giọng MC đã chọn)</p>
                                         <div id="descGenTtsStatus" class="mt-2 text-sm ml-8"></div>
                                     </div>

                                     <!-- Step 5: Generate SRT Subtitles -->
                                     <div class="p-3 bg-white rounded-lg border border-amber-200">
                                         <div class="flex items-center justify-between">
                                             <div class="flex items-center gap-2">
                                                 <span
                                                     class="bg-amber-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center">4</span>
                                                 <span class="text-sm font-medium text-gray-800">Tạo phụ đề SRT</span>
                                             </div>
                                             <button type="button" id="descGenSrtBtn"
                                                 class="bg-amber-600 hover:bg-amber-700 text-white text-xs px-4 py-2 rounded-lg font-medium transition hidden">
                                                 📝 Tạo tất cả SRT
                                             </button>
                                         </div>
                                         <p class="text-xs text-gray-500 mt-1 ml-8">Tạo subtitle cho từng chunk (chia theo
                                             câu, thời gian tỷ lệ)</p>
                                         <div id="descGenSrtStatus" class="mt-2 text-sm ml-8"></div>
                                     </div>

                                     <!-- Step 6: Compose Final Video -->
                                     <div class="p-3 bg-white rounded-lg border border-red-200">
                                         <div class="flex items-center justify-between">
                                             <div class="flex items-center gap-2">
                                                 <span
                                                     class="bg-red-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center">5</span>
                                                 <span class="text-sm font-medium text-gray-800">Ghép video hoàn
                                                     chỉnh</span>
                                             </div>
                                             <button type="button" id="descComposeBtn"
                                                 class="bg-red-600 hover:bg-red-700 text-white text-xs px-4 py-2 rounded-lg font-medium transition hidden">
                                                 🎥 Ghép video
                                             </button>
                                         </div>
                                         <p class="text-xs text-gray-500 mt-1 ml-8">Ghép ảnh + audio + subtitle +
                                             intro/outro music → video hoàn chỉnh</p>
                                         <div id="descComposeStatus" class="mt-2 text-sm ml-8"></div>
                                     </div>

                                     <!-- Progress Bar -->
                                     <div id="descPipelineProgress" class="hidden">
                                         <div class="flex items-center justify-between text-xs text-emerald-700 mb-1">
                                             <span id="descPipelineProgressText">Đang xử lý...</span>
                                             <span id="descPipelineProgressPercent">0%</span>
                                         </div>
                                         <div class="w-full bg-emerald-100 rounded-full h-2">
                                             <div id="descPipelineProgressBar"
                                                 class="bg-emerald-500 h-2 rounded-full transition-all duration-300"
                                                 style="width: 0%"></div>
                                         </div>
                                     </div>

                                     <!-- Final Video Player -->
                                     <div id="descVideoResultContainer" class="hidden">
                                         <div
                                             class="p-3 bg-gradient-to-r from-emerald-100 to-teal-100 border border-emerald-300 rounded-lg">
                                             <div class="flex items-center justify-between mb-2">
                                                 <div class="flex items-center gap-2">
                                                     <span class="text-lg">🎬</span>
                                                     <span class="text-sm font-medium text-emerald-800">Video Giới Thiệu
                                                         Sách</span>
                                                     <span id="descVideoDuration2"
                                                         class="text-xs text-emerald-600"></span>
                                                 </div>
                                                 <a id="descVideoDownloadBtn" href="#" download
                                                     class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded transition">
                                                     ⬇️ Download
                                                 </a>
                                             </div>
                                             <video id="descVideoPlayer2" controls
                                                 class="w-full rounded border border-emerald-300"></video>
                                         </div>
                                     </div>
                                 </div>
                             </div>
                         </div>

                         <!-- Media Gallery -->
                         <div class="mt-8">
                             <div class="flex items-center justify-between mb-4">
                                 <h4 class="text-base font-semibold text-gray-800">📁 Thư viện Media đã tạo</h4>
                                 <div class="flex gap-2">
                                     <button type="button" onclick="openUploadMediaModal()"
                                         class="text-xs bg-green-100 hover:bg-green-200 text-green-700 px-3 py-1.5 rounded transition">
                                         📤 Upload hình ảnh
                                     </button>
                                     <button type="button" onclick="deleteAllMedia('thumbnails')"
                                         class="text-xs bg-orange-100 hover:bg-orange-200 text-orange-700 px-3 py-1.5 rounded transition">
                                         🗑️ Xóa tất cả Thumbnails
                                     </button>
                                     <button type="button" onclick="deleteAllMedia('scenes')"
                                         class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-3 py-1.5 rounded transition">
                                         🗑️ Xóa tất cả Scenes
                                     </button>
                                 </div>
                             </div>

                             <!-- Thumbnails Section -->
                             <div class="mb-6">
                                 <h5 class="text-sm font-medium text-gray-700 mb-3 flex items-center gap-2">
                                     🖼️ Thumbnails
                                     <span id="thumbnailCount"
                                         class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">0</span>
                                 </h5>
                                 <div id="thumbnailGallery" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                     <div class="text-center py-8 text-gray-400 col-span-full">
                                         <span class="text-3xl">🖼️</span>
                                         <p class="text-sm mt-2">Chưa có thumbnail nào</p>
                                     </div>
                                 </div>
                             </div>

                             <!-- Scenes Section -->
                             <div class="mb-6">
                                 <h5 class="text-sm font-medium text-gray-700 mb-3 flex items-center gap-2">
                                     🎬 Video Scenes
                                     <span id="sceneCount2"
                                         class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">0</span>
                                 </h5>
                                 <div id="sceneGallery" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                     <div class="text-center py-8 text-gray-400 col-span-full">
                                         <span class="text-3xl">🎬</span>
                                         <p class="text-sm mt-2">Chưa có scene nào</p>
                                     </div>
                                 </div>

                                 <!-- Scene Slideshow Video Generator -->
                                 <div id="sceneSlideshowSection" class="mt-4 hidden">
                                     <div
                                         class="p-4 bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-lg">
                                         <div class="flex items-center justify-between mb-3">
                                             <h5 class="text-sm font-semibold text-indigo-800 flex items-center gap-2">
                                                 🎥 Ghép Phân Cảnh với Audio
                                             </h5>
                                             <span class="text-xs text-gray-500">Chia thời lượng theo độ dài nội dung mỗi
                                                 cảnh</span>
                                         </div>
                                         <p class="text-xs text-gray-600 mb-3">
                                             Tạo video slideshow từ các ảnh phân cảnh + audio giới thiệu sách. Mỗi cảnh sẽ
                                             hiển thị trong khoảng thời gian tỷ lệ với độ dài mô tả của nó, kèm hiệu ứng
                                             zoom
                                             + chuyển cảnh.
                                         </p>
                                         <button type="button" id="generateSlideshowBtn"
                                             class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-lg font-semibold transition flex items-center justify-center gap-2">
                                             🎥 Tạo Video từ Phân Cảnh + Audio
                                         </button>
                                         <div id="slideshowStatus" class="mt-2 text-sm"></div>
                                         <div id="slideshowProgress" class="hidden mt-2">
                                             <div class="flex items-center justify-between text-xs text-indigo-700 mb-1">
                                                 <span>Đang tạo video slideshow...</span>
                                                 <span class="animate-pulse">⏳</span>
                                             </div>
                                             <div class="w-full bg-indigo-100 rounded-full h-2">
                                                 <div class="bg-indigo-500 h-2 rounded-full animate-pulse"
                                                     style="width: 60%"></div>
                                             </div>
                                         </div>
                                     </div>

                                     <!-- Slideshow Video Player -->
                                     <div id="slideshowVideoContainer" class="mt-3 hidden">
                                         <div
                                             class="p-3 bg-gradient-to-r from-indigo-100 to-purple-100 border border-indigo-300 rounded-lg">
                                             <div class="flex items-center justify-between mb-2">
                                                 <div class="flex items-center gap-2">
                                                     <span class="text-lg">🎥</span>
                                                     <span class="text-sm font-medium text-indigo-800">Video Phân Cảnh +
                                                         Audio</span>
                                                     <span id="slideshowDuration"
                                                         class="text-xs text-indigo-600"></span>
                                                 </div>
                                                 <div class="flex gap-2">
                                                     <a id="slideshowDownloadBtn" href="#" download
                                                         class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded transition">
                                                         ⬇️ Download
                                                     </a>
                                                     <button type="button" id="deleteSlideshowBtn"
                                                         class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded transition"
                                                         title="Xóa video">
                                                         🗑️
                                                     </button>
                                                 </div>
                                             </div>
                                             <video id="slideshowVideoPlayer" controls
                                                 class="w-full rounded border border-indigo-300">
                                             </video>
                                         </div>
                                     </div>
                                 </div>
                             </div>

                             <!-- Animations Section -->
                             <div class="mb-6">
                                 <h5 class="text-sm font-medium text-gray-700 mb-3 flex items-center gap-2">
                                     ✨ Animations (Kling AI)
                                     <span id="animationCount"
                                         class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">0</span>
                                 </h5>
                                 <div id="animationGallery"
                                     class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                     <div class="text-center py-8 text-gray-400 col-span-full">
                                         <span class="text-3xl">✨</span>
                                         <p class="text-sm mt-2">Chưa có animation nào</p>
                                     </div>
                                 </div>
                             </div>
                         </div>
                     </div>
                 </div>
             </div>

             <!-- Short Video Tab Content -->
             <div id="short-video-tab" class="tab-content hidden">
                 <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                     <div class="p-6">
                         <div
                             class="bg-gradient-to-br from-fuchsia-50 to-rose-50 border border-fuchsia-200 rounded-lg p-5">
                             <div class="flex items-center justify-between mb-3">
                                 <h3 class="text-lg font-semibold text-fuchsia-800">📱 Short Video AI (9:16, tối đa 60s)
                                 </h3>
                                 <button type="button" id="refreshShortVideosBtn"
                                     class="text-xs bg-fuchsia-100 hover:bg-fuchsia-200 text-fuchsia-700 px-3 py-1.5 rounded transition">
                                     🔄 Refresh
                                 </button>
                             </div>
                             <p class="text-xs text-fuchsia-700 mb-4">
                                 Tạo nhiều short video theo phong cách khác nhau từ nội dung sách, tự động tạo script + TTS
                                 + ảnh dọc 9:16 + video minh họa để tải về ghép CapCut.
                             </p>
                             <p class="text-xs text-fuchsia-800 mb-3">
                                 📚 Sách hiện tại: <span class="font-semibold">{{ $audioBook->title }}</span> • 📺 Kênh
                                 hiện tại:
                                 <span
                                     class="font-semibold">{{ $audioBook->youtubeChannel->title ?? 'Chưa gán kênh' }}</span>
                             </p>

                             <div class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-3">
                                 <div>
                                     <label class="block text-xs font-medium text-gray-600 mb-1">Số lượng short
                                         (n)</label>
                                     <input type="number" id="shortVideoCount" min="1" max="20"
                                         value="5"
                                         class="w-full px-3 py-2 border border-fuchsia-200 rounded-lg text-sm focus:border-fuchsia-500 focus:outline-none">
                                 </div>
                                 <div>
                                     <label class="block text-xs font-medium text-gray-600 mb-1">TTS Provider</label>
                                     <select id="shortVideoProvider"
                                         class="w-full px-3 py-2 border border-fuchsia-200 rounded-lg text-sm focus:border-fuchsia-500 focus:outline-none">
                                         <option value="openai">OpenAI</option>
                                         <option value="gemini">Gemini</option>
                                         <option value="microsoft">Microsoft</option>
                                         <option value="vbee">Vbee</option>
                                     </select>
                                 </div>
                                 <div>
                                     <label class="block text-xs font-medium text-gray-600 mb-1">Image Provider</label>
                                     <select id="shortImageProvider"
                                         class="w-full px-3 py-2 border border-fuchsia-200 rounded-lg text-sm focus:border-fuchsia-500 focus:outline-none">
                                         <option value="gemini">Gemini</option>
                                         <option value="flux">Flux (AIML)</option>
                                     </select>
                                 </div>
                                 <div class="md:col-span-2 flex items-end gap-2">
                                     <button type="button" id="generateShortPlansBtn"
                                         class="flex-1 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded-lg text-sm font-semibold transition">
                                         🧠 Tạo kế hoạch short bằng AI
                                     </button>
                                     <button type="button" id="generateShortAssetsBtn"
                                         class="flex-1 bg-rose-600 hover:bg-rose-700 text-white px-3 py-2 rounded-lg text-sm font-semibold transition">
                                         🚀 Tạo TTS + Ảnh + Video 9:16
                                     </button>
                                 </div>
                             </div>

                            <div class="mb-4 p-3 border border-fuchsia-200 bg-white rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-xs font-semibold text-fuchsia-800">Tạo thủ công 1 short (không dùng AI plan)</p>
                                    <button type="button" id="createManualShortBtn"
                                        class="bg-fuchsia-600 hover:bg-fuchsia-700 text-white px-3 py-1.5 rounded text-xs font-semibold transition">
                                        ➕ Thêm short thủ công
                                    </button>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                                    <input type="text" id="manualShortTitle"
                                        class="md:col-span-2 w-full px-3 py-2 border border-fuchsia-200 rounded text-xs focus:outline-none focus:border-fuchsia-500"
                                        placeholder="Tiêu đề short (tuỳ chọn)">
                                    <input type="text" id="manualShortStyle"
                                        class="w-full px-3 py-2 border border-fuchsia-200 rounded text-xs focus:outline-none focus:border-fuchsia-500"
                                        placeholder="Phong cách (vd: Drama)" value="Cinematic">
                                    <input type="text" id="manualShortImagePrompt"
                                        class="w-full px-3 py-2 border border-fuchsia-200 rounded text-xs focus:outline-none focus:border-fuchsia-500"
                                        placeholder="Prompt ảnh (tuỳ chọn)">
                                </div>
                                <textarea id="manualShortScript" rows="3"
                                    class="mt-2 w-full px-3 py-2 border border-fuchsia-200 rounded text-xs focus:outline-none focus:border-fuchsia-500"
                                    placeholder="Nhập script short để đọc TTS (bắt buộc)"></textarea>
                                <p class="mt-1 text-[11px] text-gray-500">Mẹo: để trống prompt ảnh nếu muốn hệ thống tự tạo prompt mặc định từ script.</p>
                            </div>

                             <div class="flex flex-wrap items-center gap-2 mb-3">
                                 <label class="inline-flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                                     <input type="checkbox" id="selectAllShortVideos"
                                         class="rounded border-fuchsia-300">
                                     <span>Chọn tất cả</span>
                                 </label>
                                 <span id="selectedShortCount" class="text-xs text-gray-500">Đã chọn: 0</span>
                                 <button type="button" id="generateSelectedShortTtsBtn"
                                     class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded text-xs font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed"
                                     disabled>
                                     🎙️ Tạo TTS đã chọn
                                 </button>
                                 <button type="button" id="generateSelectedShortImagesBtn"
                                     class="bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded text-xs font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed"
                                     disabled>
                                     🖼️ Tạo ảnh đã chọn
                                 </button>
                                 <button type="button" id="downloadSelectedShortBtn"
                                     class="bg-sky-600 hover:bg-sky-700 border border-sky-700 text-white px-3 py-1.5 rounded text-xs font-semibold shadow-sm transition disabled:bg-sky-400 disabled:border-sky-500 disabled:text-white disabled:opacity-100 disabled:cursor-not-allowed"
                                     disabled>
                                     ⬇️ Tải đã chọn
                                 </button>
                                 <button type="button" id="deleteSelectedShortBtn"
                                     class="bg-gray-700 hover:bg-gray-800 text-white px-3 py-1.5 rounded text-xs font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed"
                                     disabled>
                                     🗑️ Xóa đã chọn
                                 </button>
                             </div>

                             <div id="shortVideoStatus" class="text-sm mb-3"></div>

                             <div id="shortVideoList" class="space-y-3">
                                 <div class="text-center py-8 text-gray-400">
                                     <span class="text-3xl">📱</span>
                                     <p class="text-sm mt-2">Chưa có short video nào</p>
                                 </div>
                             </div>
                         </div>
                     </div>
                 </div>
             </div>

             <div id="shortWorkspaceModal"
                 class="hidden fixed inset-0 bg-black bg-opacity-60 z-50 flex items-start justify-center p-2 sm:p-4 overflow-y-auto">
                 <div class="bg-white rounded-xl shadow-2xl w-full max-w-7xl flex flex-col my-auto"
                     style="min-height: min(95vh, 100%); max-height: none;">
                     <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white z-10 rounded-t-xl">
                         <div>
                             <h3 id="shortWorkspaceTitle" class="text-lg font-semibold text-fuchsia-800">🎬 Studio câu cho short</h3>
                             <p id="shortWorkspaceSubtitle" class="text-xs text-gray-500 mt-0.5"></p>
                         </div>
                         <button type="button" id="closeShortWorkspaceModalBtn"
                             class="text-gray-400 hover:text-gray-700 text-2xl leading-none">×</button>
                     </div>

                    <div id="shortWorkspaceModalBody" class="p-5 flex flex-col gap-4"
                        style="flex: 1 1 auto; min-height: 0; overflow: visible;">
                         <div id="shortWorkspaceStatus" class="text-sm"></div>

                        <div class="grid grid-cols-1 lg:grid-cols-8 gap-3">
                             <div>
                                 <label class="block text-xs text-gray-600 mb-1">TTS Provider</label>
                                 <select id="shortWorkspaceProvider"
                                     class="w-full px-2 py-2 border border-fuchsia-200 rounded text-sm focus:outline-none focus:border-fuchsia-500">
                                     <option value="openai">OpenAI</option>
                                     <option value="gemini">Gemini</option>
                                     <option value="microsoft">Microsoft</option>
                                     <option value="vbee">Vbee</option>
                                 </select>
                             </div>
                             <div>
                                 <label class="block text-xs text-gray-600 mb-1">Image Provider</label>
                                 <select id="shortWorkspaceImageProvider"
                                     class="w-full px-2 py-2 border border-fuchsia-200 rounded text-sm focus:outline-none focus:border-fuchsia-500">
                                     <option value="gemini">Gemini</option>
                                     <option value="flux">Flux (AIML)</option>
                                 </select>
                             </div>
                             <div>
                                 <label class="block text-xs text-gray-600 mb-1">Video Provider</label>
                                 <select id="shortWorkspaceVideoProvider"
                                     class="w-full px-2 py-2 border border-fuchsia-200 rounded text-sm focus:outline-none focus:border-fuchsia-500">
                                     <option value="kling">Kling</option>
                                     <option value="seedance">Seedance</option>
                                 </select>
                             </div>
                             <div>
                                 <label class="block text-xs text-gray-600 mb-1">Voice name</label>
                                 <input type="text" id="shortWorkspaceVoiceName"
                                     class="w-full px-2 py-2 border border-fuchsia-200 rounded text-sm focus:outline-none focus:border-fuchsia-500"
                                     placeholder="vd: n_hanoi_female...">
                             </div>
                             <div>
                                 <label class="block text-xs text-gray-600 mb-1">Voice gender</label>
                                 <select id="shortWorkspaceVoiceGender"
                                     class="w-full px-2 py-2 border border-fuchsia-200 rounded text-sm focus:outline-none focus:border-fuchsia-500">
                                     <option value="female">female</option>
                                     <option value="male">male</option>
                                 </select>
                             </div>
                             <div>
                                 <label class="block text-xs text-gray-600 mb-1">TTS speed</label>
                                 <input type="number" id="shortWorkspaceTtsSpeed" min="0.5" max="2.0"
                                     step="0.1" value="1.0"
                                     class="w-full px-2 py-2 border border-fuchsia-200 rounded text-sm focus:outline-none focus:border-fuchsia-500">
                             </div>
                             <div>
                                 <label class="block text-xs text-gray-600 mb-1">Video duration</label>
                                 <select id="shortWorkspaceKlingDuration"
                                     class="w-full px-2 py-2 border border-fuchsia-200 rounded text-sm focus:outline-none focus:border-fuchsia-500">
                                     <option value="5">5s</option>
                                     <option value="10">10s</option>
                                 </select>
                             </div>
                             <div class="flex items-end">
                                 <label class="inline-flex items-center gap-2 text-xs text-gray-700 cursor-pointer">
                                     <input type="checkbox" id="shortWorkspaceSelectAllShots" class="rounded border-fuchsia-300">
                                     <span>Chọn tất cả shot</span>
                                 </label>
                             </div>
                         </div>

                         <div class="flex flex-wrap gap-2">
                             <button type="button" id="shortWorkspaceBuildBtn"
                                 class="bg-purple-600 hover:bg-purple-700 text-white text-xs font-semibold px-3 py-2 rounded">🧩 Build từ từng câu</button>
                             <button type="button" id="shortWorkspaceGenerateTtsBtn"
                                 class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-3 py-2 rounded">🎙️ Tạo TTS theo câu</button>
                             <button type="button" id="shortWorkspaceGenerateImagesBtn"
                                 class="bg-teal-600 hover:bg-teal-700 text-white text-xs font-semibold px-3 py-2 rounded">🖼️ Tạo ảnh theo câu</button>
                             <button type="button" id="shortWorkspaceStartKlingBtn"
                                 class="bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold px-3 py-2 rounded">✨ Start Video AI (ảnh→video)</button>
                             <button type="button" id="shortWorkspacePollKlingBtn"
                                 class="bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold px-3 py-2 rounded">🔄 Poll Video AI</button>
                             <button type="button" id="shortWorkspaceComposeBtn"
                                 class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-3 py-2 rounded">🎬 Auto ghép theo TTS</button>
                             <button type="button" id="shortWorkspaceDownloadBtn"
                                 class="bg-sky-600 hover:bg-sky-700 text-white text-xs font-semibold px-3 py-2 rounded">⬇️ Tải package CapCut</button>
                         </div>

                         <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                             <div>
                                 <label class="block text-xs text-gray-600 mb-1">Story bible</label>
                                 <textarea id="shortWorkspaceStoryBible" rows="3" readonly
                                     class="w-full border border-gray-200 rounded p-2 text-xs bg-gray-50 text-gray-700"></textarea>
                             </div>
                             <div>
                                 <label class="block text-xs text-gray-600 mb-1">Character bible</label>
                                 <textarea id="shortWorkspaceCharacterBible" rows="3" readonly
                                     class="w-full border border-gray-200 rounded p-2 text-xs bg-gray-50 text-gray-700"></textarea>
                             </div>
                         </div>

                        <div id="shortWorkspaceShotsMeta" class="text-xs text-gray-500"></div>
                        <div id="shortWorkspaceShotsViewport" class="border border-fuchsia-100 rounded-lg p-2 bg-gray-50"
                            style="overflow-y: visible; -webkit-overflow-scrolling: touch;">
                            <div id="shortWorkspaceShotsList" class="space-y-3 pb-4"></div>
                        </div>
                     </div>
                 </div>
             </div>

 
            <!-- Clipping Tab Content -->
            <div id="clipping-tab" class="tab-content hidden">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-5">
                            <h3 class="text-lg font-semibold text-gray-800">✂️ Clipping – Cắt clip ngắn từ video dài</h3>
                            <button type="button" onclick="loadClippingTab()"
                                class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg transition text-sm">
                                🔄 Refresh
                            </button>
                        </div>

                        <!-- Setup Card -->
                        <div class="bg-gradient-to-br from-violet-50 to-fuchsia-50 border border-violet-200 rounded-xl p-5 mb-6">
                            <h4 class="font-semibold text-violet-800 mb-4">⚙️ Cài đặt cắt clip</h4>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">🎬 Video nguồn</label>
                                    <select id="clippingSourceVideo"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-violet-500">
                                        <option value="">-- Đang tải danh sách video... --</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">🔢 Số clip muốn tạo</label>
                                    <input type="number" id="clippingCount" min="1" max="20" value="3"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-violet-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">🎵 Âm thanh nền</label>
                                    <select id="clippingBgAudio"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-violet-500">
                                        <option value="auto">-- Đang tải âm thanh nền... --</option>
                                    </select>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">🔊 Âm lượng nền</label>
                                    <input type="range" id="clippingBgVolume" min="0" max="30" value="3" disabled
                                        class="w-full accent-violet-600 opacity-60 cursor-not-allowed">
                                    <p id="clippingBgVolumeValue" class="text-xs text-gray-600 mt-1">-30 dB (cố định)</p>
                                </div>
                                <div class="md:col-span-3 flex items-center gap-4">
                                    <div class="flex-shrink-0">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">🎨 Kiểu phụ đề</label>
                                        <select id="clippingSubtitleStyle"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-violet-500">
                                            <option value="highlight_green" selected>🟢 Highlight xanh lá (viral)</option>
                                            <option value="highlight_yellow">🟡 Highlight vàng (viral)</option>
                                            <option value="highlight_red">🔴 Highlight đỏ (viral)</option>
                                            <option value="neon_blue">💙 Neon xanh dương</option>
                                            <option value="boxed">⬛ Nền hộp đen</option>
                                            <option value="default">⚪ Mặc định (trắng)</option>
                                        </select>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">📍 Vị trí phụ đề</label>
                                        <select id="clippingSubtitlePosition"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-violet-500">
                                            <option value="lower_third" selected>Chuẩn (Y≈1450)</option>
                                            <option value="middle">Cao hơn (Y≈1400)</option>
                                            <option value="bottom">Thấp hơn (Y≈1550)</option>
                                        </select>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">🖼️ Image Provider</label>
                                        <select id="clippingImageProvider"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-violet-500">
                                            <option value="gemini">Gemini</option>
                                            <option value="flux">Flux (AIML)</option>
                                        </select>
                                    </div>
                                    <p id="clippingBgAudioHint" class="text-xs text-gray-500 mt-5">
                                        CTA sẽ tự chọn animation dọc gần 9:16 từ thư viện media/animations.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center gap-3 flex-wrap">
                                <button type="button" onclick="saveClippingSettings()"
                                    id="saveClippingSettingsBtn"
                                    class="bg-indigo-100 hover:bg-indigo-200 text-indigo-800 font-semibold py-2.5 px-6 rounded-lg transition text-sm">
                                    💾 Lưu setting ghép video
                                </button>
                                <button type="button" onclick="generateClips()"
                                    id="generateClipsBtn"
                                    class="bg-violet-600 hover:bg-violet-700 text-white font-semibold py-2.5 px-6 rounded-lg transition text-sm">
                                    ✂️ Cắt clip ngẫu nhiên (~60s)
                                </button>
                                <span id="saveClippingSettingsStatus" class="text-sm text-indigo-600"></span>
                                <span id="generateClipsStatus" class="text-sm text-gray-500"></span>
                            </div>
                        </div>

                        <!-- Clips list -->
                        <div id="clippingList">
                            <p class="text-gray-400 text-sm text-center py-10">Chưa có clip nào. Chọn video nguồn và nhấn cắt clip.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Auto Publish Tab Content -->
             <div id="auto-publish-tab" class="tab-content hidden">
                 <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                     <div class="p-6">
                         <h3 class="text-lg font-semibold text-gray-800 mb-4">🚀 Phát hành tự động lên YouTube</h3>

                         {{-- YouTube Connection Status --}}
                         <div id="publishYtStatus" class="mb-6 p-4 rounded-lg border">
                             <div class="flex items-center gap-2">
                                 <span class="text-gray-500">Đang kiểm tra kết nối YouTube...</span>
                                 <svg class="animate-spin h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                                     fill="none" viewBox="0 0 24 24">
                                     <circle class="opacity-25" cx="12" cy="12" r="10"
                                         stroke="currentColor" stroke-width="4"></circle>
                                     <path class="opacity-75" fill="currentColor"
                                         d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                 </svg>
                             </div>
                         </div>

                         {{-- YouTube Upload Limits Warning --}}
                         <div class="mb-6 p-4 bg-amber-50 rounded-lg border border-amber-200">
                             <div class="flex items-start gap-3">
                                 <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none"
                                     stroke="currentColor" viewBox="0 0 24 24">
                                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                         d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                 </svg>
                                 <div>
                                     <p class="text-sm font-semibold text-amber-800 mb-1">⚠️ Giới hạn Upload YouTube</p>
                                     <ul class="text-xs text-amber-700 space-y-0.5">
                                         <li>• <strong>Tài khoản chưa xác minh:</strong> 6 videos/ngày</li>
                                         <li>• <strong>Quota API:</strong> ~6 videos/ngày (10,000 units)</li>
                                         <li>• <strong>Reset:</strong> Sau 24 giờ theo giờ Pacific Time (PST)</li>
                                         <li>• <strong>Khuyến nghị:</strong> <a href="https://www.youtube.com/verify"
                                                 target="_blank" class="underline font-medium">Xác minh kênh YouTube</a>
                                             để
                                             tăng giới hạn</li>
                                     </ul>
                                 </div>
                             </div>
                         </div>

                         <div id="publishFormWrapper" class="hidden">
                             <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                 {{-- Left Column: Settings --}}
                                 <div class="lg:col-span-2 space-y-6">

                                     {{-- Publish Mode --}}
                                     <div>
                                         <label class="block text-sm font-semibold text-gray-700 mb-3">Chế độ phát
                                             hành</label>
                                         <div class="flex gap-4">
                                             <label
                                                 class="flex items-center gap-2 px-4 py-2 border rounded-lg cursor-pointer hover:bg-blue-50 transition publish-mode-label">
                                                 <input type="radio" name="publishMode" value="single" checked
                                                     class="text-blue-600 publish-mode-radio">
                                                 <span class="text-sm font-medium">🎬 Video đơn lẻ</span>
                                             </label>
                                             <label
                                                 class="flex items-center gap-2 px-4 py-2 border rounded-lg cursor-pointer hover:bg-blue-50 transition publish-mode-label">
                                                 <input type="radio" name="publishMode" value="shorts"
                                                     class="text-blue-600 publish-mode-radio">
                                                 <span class="text-sm font-medium">📱 YouTube Shorts</span>
                                             </label>
                                             <label
                                                 class="flex items-center gap-2 px-4 py-2 border rounded-lg cursor-pointer hover:bg-blue-50 transition publish-mode-label">
                                                 <input type="radio" name="publishMode" value="playlist"
                                                     class="text-blue-600 publish-mode-radio">
                                                 <span class="text-sm font-medium">📋 Playlist</span>
                                             </label>
                                         </div>
                                     </div>

                                     {{-- Video Source Selection --}}
                                     <div>
                                         <div class="flex items-center justify-between mb-3">
                                             <label class="block text-sm font-semibold text-gray-700">Chọn video
                                                 nguồn</label>
                                             <button type="button" id="selectAllVideoSourcesBtn"
                                                 onclick="toggleSelectAllVideoSources()"
                                                 class="px-3 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition border border-gray-300">
                                                 ☑️ Chọn tất cả
                                             </button>
                                         </div>
                                         <div id="publishVideoSources"
                                             class="space-y-2 max-h-60 overflow-y-auto border rounded-lg p-3">
                                             <p class="text-sm text-gray-400">Đang tải danh sách video...</p>
                                         </div>
                                         <p class="text-xs text-gray-400 mt-1" id="publishSourceHint">Chọn 1 video để
                                             upload (chế độ Video đơn lẻ / Shorts)</p>
                                     </div>

                                     {{-- Privacy Setting --}}
                                     <div>
                                         <label class="block text-sm font-semibold text-gray-700 mb-2">Quyền riêng
                                             tư</label>
                                         <select id="publishPrivacy"
                                             class="w-full sm:w-auto border-gray-300 rounded-lg text-sm">
                                             <option value="private">🔒 Riêng tư (Private)</option>
                                             <option value="unlisted">🔗 Không công khai (Unlisted)</option>
                                             <option value="public">🌍 Công khai (Public)</option>
                                         </select>
                                     </div>

                                     {{-- Video Title --}}
                                     <div>
                                         <label class="block text-sm font-semibold text-gray-700 mb-2">Tiêu đề
                                             video</label>
                                         <div class="flex gap-2">
                                             <input type="text" id="publishTitle"
                                                 class="flex-1 border-gray-300 rounded-lg text-sm"
                                                 placeholder="Nhập tiêu đề video..." value="{{ $audioBook->title }}">
                                             <button type="button" id="aiGenerateTitleBtn"
                                                 class="px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition whitespace-nowrap">
                                                 🤖 AI Viết
                                             </button>
                                         </div>
                                     </div>

                                     {{-- Video Description --}}
                                     <div>
                                         <label class="block text-sm font-semibold text-gray-700 mb-2">Mô tả video</label>
                                         <div class="flex gap-2 mb-2">
                                             <button type="button" id="aiGenerateDescBtn"
                                                 class="px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition whitespace-nowrap">
                                                 🤖 AI Viết mô tả
                                             </button>
                                         </div>
                                         <textarea id="publishDescription" rows="6" class="w-full border-gray-300 rounded-lg text-sm"
                                             placeholder="Nhập mô tả video...">{{ $audioBook->description ? Str::limit($audioBook->description, 500) : '' }}</textarea>
                                     </div>

                                     {{-- Tags --}}
                                     <div>
                                         <label class="block text-sm font-semibold text-gray-700 mb-2">Tags (phân cách
                                             bằng
                                             dấu phẩy)</label>
                                         <input type="text" id="publishTags"
                                             class="w-full border-gray-300 rounded-lg text-sm"
                                             placeholder="audiobook, sách nói, {{ $audioBook->author }}..."
                                             value="audiobook, sách nói, {{ $audioBook->category }}, {{ $audioBook->author }}">
                                     </div>

                                     {{-- Playlist Section (hidden by default) --}}
                                     <div id="playlistSection" class="hidden">
                                         <div class="border-t pt-4">
                                             {{-- Playlist Mode: Create New or Select Existing --}}
                                             <div class="mb-4">
                                                 <label class="block text-sm font-semibold text-gray-700 mb-2">Chọn
                                                     Playlist</label>
                                                 <div class="flex gap-3">
                                                     <label
                                                         class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:bg-indigo-50 transition playlist-type-label">
                                                         <input type="radio" name="playlistType" value="new"
                                                             checked class="text-indigo-600 playlist-type-radio">
                                                         <span class="text-sm font-medium">Tạo playlist mới</span>
                                                     </label>
                                                     <label
                                                         class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:bg-indigo-50 transition playlist-type-label">
                                                         <input type="radio" name="playlistType" value="existing"
                                                             class="text-indigo-600 playlist-type-radio">
                                                         <span class="text-sm font-medium">Chọn playlist có sẵn</span>
                                                     </label>
                                                 </div>
                                             </div>

                                             {{-- New Playlist Name --}}
                                             <div id="newPlaylistSection">
                                                 <label class="block text-sm font-semibold text-gray-700 mb-2">Tên
                                                     Playlist
                                                     mới</label>
                                                 <input type="text" id="playlistName"
                                                     class="w-full border-gray-300 rounded-lg text-sm"
                                                     placeholder="Tên playlist trên YouTube..."
                                                     value="{{ $audioBook->youtube_playlist_title ?: $audioBook->title . ' - Sách Nói' }}">
                                             </div>

                                             {{-- Existing Playlist Selector --}}
                                             <div id="existingPlaylistSection" class="hidden">
                                                 <label class="block text-sm font-semibold text-gray-700 mb-2">Chọn
                                                     playlist
                                                     từ kênh YouTube</label>
                                                 <div class="flex gap-2">
                                                     <select id="existingPlaylistSelect"
                                                         class="flex-1 border-gray-300 rounded-lg text-sm">
                                                         <option value="">-- Đang tải playlists... --</option>
                                                     </select>
                                                     <button type="button" id="refreshPlaylistsBtn"
                                                         class="px-3 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm rounded-lg transition"
                                                         title="Tải lại danh sách">
                                                         🔄
                                                     </button>
                                                 </div>
                                                 <p class="text-xs text-gray-400 mt-1" id="existingPlaylistHint"></p>
                                             </div>

                                             {{-- Phiên bản con --}}
                                             <div class="mt-4">
                                                 <div class="flex items-center justify-between mb-3">
                                                     <label class="block text-sm font-semibold text-gray-700">Phiên bản
                                                         con
                                                         cho từng video</label>
                                                     <button type="button" id="generatePlaylistMetaBtn"
                                                         class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                                                         🔄 Tạo phiên bản con (AI)
                                                     </button>
                                                 </div>
                                                 <p class="text-xs text-gray-500 mb-3" id="playlistMetaHint">AI sẽ
                                                     chuyển
                                                     tiêu đề và mô tả chung thành
                                                     phiên bản riêng cho từng chapter video trong playlist.</p>
                                                 <div id="playlistMetaList" class="space-y-3">
                                                     <p class="text-sm text-gray-400 italic">Chọn nhiều video nguồn và
                                                         nhấn
                                                         "Tạo
                                                         phiên bản con" để bắt đầu.</p>
                                                 </div>
                                             </div>
                                         </div>
                                     </div>
                                 </div>

                                 {{-- Right Column: Thumbnail --}}
                                 <div class="space-y-6">
                                     {{-- Thumbnail Selection --}}
                                     <div>
                                         <label class="block text-sm font-semibold text-gray-700 mb-3">Chọn
                                             Thumbnail</label>
                                         <div id="publishThumbnailGallery"
                                             class="grid grid-cols-2 gap-2 max-h-80 overflow-y-auto border rounded-lg p-3">
                                             <p class="text-sm text-gray-400 col-span-2">Đang tải thumbnails...</p>
                                         </div>
                                         <input type="hidden" id="publishSelectedThumbnail" value="">
                                         <p class="text-xs text-gray-400 mt-1">Chọn thumbnail từ media đã tạo. Vào tab
                                             "YouTube Media (AI)" để tạo thêm.</p>
                                     </div>

                                     {{-- Selected Thumbnail Preview --}}
                                     <div id="publishThumbnailPreview" class="hidden">
                                         <label class="block text-sm font-semibold text-gray-700 mb-2">Thumbnail đã
                                             chọn</label>
                                         <img id="publishThumbnailPreviewImg" src="" alt="Selected thumbnail"
                                             class="w-full rounded-lg border shadow-sm">
                                     </div>
                                 </div>
                             </div>

                             {{-- Publish Button --}}
                             <div class="mt-8 border-t pt-6">
                                 <div class="flex items-center gap-4">
                                     <button type="button" id="savePublishMetaBtn"
                                         class="px-5 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg transition text-base">
                                         💾 Lưu thông tin
                                     </button>
                                     <button type="button" id="publishToYoutubeBtn"
                                         class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg transition text-base">
                                         🚀 Phát hành lên YouTube
                                     </button>
                                     <div id="publishProgress" class="hidden flex-1">
                                         <div class="flex items-center gap-3">
                                             <svg class="animate-spin h-5 w-5 text-red-500"
                                                 xmlns="http://www.w3.org/2000/svg" fill="none"
                                                 viewBox="0 0 24 24">
                                                 <circle class="opacity-25" cx="12" cy="12" r="10"
                                                     stroke="currentColor" stroke-width="4"></circle>
                                                 <path class="opacity-75" fill="currentColor"
                                                     d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                             </svg>
                                             <span id="publishProgressText" class="text-sm text-gray-600">Đang
                                                 upload...</span>
                                         </div>
                                         <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                                             <div id="publishProgressBar"
                                                 class="bg-red-600 h-2 rounded-full transition-all duration-300"
                                                 style="width: 0%"></div>
                                         </div>
                                     </div>
                                 </div>
                                 <div id="publishResult" class="mt-4 hidden"></div>
                             </div>

                             {{-- Publishing History --}}
                             <div class="mt-8 border-t pt-6">
                                 <div class="flex items-center justify-between mb-4">
                                     <h4 class="text-md font-semibold text-gray-800">📋 Lịch sử phát hành YouTube</h4>
                                     <button type="button" id="refreshHistoryBtn"
                                         class="px-3 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition border border-gray-300">
                                         🔄 Tải lại
                                     </button>
                                 </div>
                                 <div id="publishHistoryContainer">
                                     <p class="text-sm text-gray-400">Đang tải lịch sử...</p>
                                 </div>
                             </div>
                         </div>
                     </div>
                 </div>
             </div>

             <!-- Chapters Tab Content -->
             <div id="chapters-tab" class="tab-content hidden">
                 <!-- Chapters Section -->
                 <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                     <div class="p-6">
                        @php
                            $chaptersWithContent = $audioBook->chapters->filter(function ($chapter) {
                                return trim((string) $chapter->content) !== '';
                            });
                            $totalChaptersWithContent = $chaptersWithContent->count();
                            $chunkedContentChapters = $chaptersWithContent->filter(function ($chapter) {
                                return $chapter->chunks->count() > 0;
                            })->count();
                            $remainingUnchunkedChapters = max(0, $totalChaptersWithContent - $chunkedContentChapters);
                            $pendingEmbeddingChunks = $chaptersWithContent
                                ->flatMap(function ($chapter) {
                                    return $chapter->chunks;
                                })
                                ->where('embedding_status', 'pending')
                                ->count();
                            $processingEmbeddingChunks = $chaptersWithContent
                                ->flatMap(function ($chapter) {
                                    return $chapter->chunks;
                                })
                                ->where('embedding_status', 'processing')
                                ->count();
                            $chunkEmbeddingMode =
                                $remainingUnchunkedChapters > 0
                                    ? 'chunk_and_embedding'
                                    : ($pendingEmbeddingChunks > 0 ? 'embedding_only' : ($processingEmbeddingChunks > 0 ? 'processing' : 'locked'));
                            $canChunkAndEmbedding = in_array($chunkEmbeddingMode, ['chunk_and_embedding', 'embedding_only'], true);
                            $chunkEmbeddingButtonLabel =
                                $chunkEmbeddingMode === 'chunk_and_embedding'
                                    ? '🧩 Chunk & Embedding'
                                    : ($chunkEmbeddingMode === 'embedding_only'
                                        ? '🧠 Embedding'
                                        : ($chunkEmbeddingMode === 'processing' ? '⏳ Embedding...' : '✅ Đã hoàn tất'));
                            $chunkEmbeddingButtonTitle =
                                $chunkEmbeddingMode === 'chunk_and_embedding'
                                    ? "Còn {$remainingUnchunkedChapters} chương chưa chunk"
                                    : ($chunkEmbeddingMode === 'embedding_only'
                                        ? "Có {$pendingEmbeddingChunks} chunk pending embedding"
                                        : ($chunkEmbeddingMode === 'processing'
                                            ? "Đang xử lý {$processingEmbeddingChunks} chunk embedding"
                                            : 'Toàn bộ chunk đã embedding xong'));
                        @endphp

                         <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6" id="chapterToolbarAnchor">
                             <div class="flex items-center gap-4">
                                 <h3 class="text-base sm:text-lg font-semibold text-gray-800">📖 Danh sách chương</h3>
                                 @if ($audioBook->chapters->count() > 0)
                                     <label class="inline-flex items-center gap-1 text-sm text-gray-600 cursor-pointer">
                                         <input type="checkbox" id="selectAllChapters" class="rounded">
                                         <span>Chọn tất cả</span>
                                     </label>
                                 @endif
                             </div>
                             <div class="flex flex-wrap gap-2" id="chapterToolbarButtons">
                                 <button id="generateSelectedTtsBtn" onclick="generateTtsForSelectedChapters()"
                                     class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 hidden">
                                     🎙️ Tạo TTS (<span id="selectedCount">0</span>)
                                 </button>
                                 <button id="generateSelectedVideoBtn" onclick="generateVideoForSelectedChapters()"
                                     class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 hidden">
                                     🎬 Tạo Video (<span id="selectedVideoCount">0</span>)
                                 </button>
                                 <button id="deleteSelectedChaptersBtn" onclick="deleteSelectedChapters()"
                                     class="bg-gray-700 hover:bg-gray-800 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 hidden">
                                     🗑️ Xóa đã chọn
                                 </button>
                                 <button id="boostSelectedAudioBtn" onclick="boostAudioForSelectedChapters()"
                                     class="bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 hidden">
                                     🔊 Boost +16dB (<span id="selectedBoostCount">0</span>)
                                 </button>
                                 <button onclick="openFindReplaceModal()"
                                     class="bg-yellow-500 hover:bg-yellow-600 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                     🔍 Tìm &amp; Thay thế
                                 </button>
                                 <button id="fixLeadingInitialSpaceBtn" onclick="fixLeadingInitialSpaceAllChapters()"
                                     class="bg-amber-600 hover:bg-amber-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                     🛠️ Fix ký tự đầu
                                 </button>
                                 <button id="scanTtsIssuesBtn" onclick="scanTtsVietnameseIssuesAllChapters()"
                                     class="bg-rose-600 hover:bg-rose-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                     🧪 Quét lỗi TTS tiếng Việt
                                 </button>
                                 <button id="chunkEmbeddingBtn" onclick="chunkAndEmbeddingAllChapters()"
                                     data-mode="{{ $chunkEmbeddingMode }}"
                                     @disabled(!$canChunkAndEmbedding)
                                     title="{{ $chunkEmbeddingButtonTitle }}"
                                     class="{{ $canChunkAndEmbedding
                                         ? 'bg-indigo-600 hover:bg-indigo-700 text-white'
                                         : 'bg-gray-300 text-gray-600 cursor-not-allowed' }} font-semibold py-2 px-4 rounded-lg transition duration-200">
                                     {{ $chunkEmbeddingButtonLabel }}
                                 </button>
                                 <button onclick="openScrapeModal()"
                                     class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                     🌐 Scrape
                                 </button>
                                 <a href="{{ route('audiobooks.export.word', $audioBook) }}" target="_blank"
                                     class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 inline-block">
                                     📄 Xuất TXT
                                 </a>
                                 <a href="{{ route('audiobooks.chapters.create', $audioBook) }}"
                                     class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                     + Thêm chương
                                 </a>
                             </div>
                         </div>

                         <!-- Floating Toolbar (appears when scrolled past original) -->
                         <div id="chapterFloatingToolbar"
                             class="fixed top-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-sm shadow-lg border-b border-gray-200 px-6 py-3 transition-all duration-300"
                             style="display: none; transform: translateY(-100%);">
                             <div class="max-w-7xl mx-auto flex justify-between items-center">
                                 <div class="flex items-center gap-3">
                                     <span class="text-sm font-semibold text-gray-700">📖 {{ $audioBook->title }}</span>
                                     @if ($audioBook->chapters->count() > 0)
                                         <label
                                             class="inline-flex items-center gap-1 text-sm text-gray-600 cursor-pointer">
                                             <input type="checkbox" id="selectAllChaptersFloating" class="rounded">
                                             <span>Chọn tất cả</span>
                                         </label>
                                     @endif
                                 </div>
                                 <div class="flex gap-2">
                                     <button id="generateSelectedTtsBtnFloating"
                                         onclick="generateTtsForSelectedChapters()"
                                         class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm hidden">
                                         🎙️ TTS (<span id="selectedCountFloating">0</span>)
                                     </button>
                                     <button id="generateSelectedVideoBtnFloating"
                                         onclick="generateVideoForSelectedChapters()"
                                         class="bg-red-600 hover:bg-red-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm hidden">
                                         🎬 Video (<span id="selectedVideoCountFloating">0</span>)
                                     </button>
                                     <button id="deleteSelectedChaptersBtnFloating" onclick="deleteSelectedChapters()"
                                         class="bg-gray-700 hover:bg-gray-800 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm hidden">
                                         🗑️ Xóa đã chọn
                                     </button>
                                     <button id="boostSelectedAudioBtnFloating" onclick="boostAudioForSelectedChapters()"
                                         class="bg-orange-600 hover:bg-orange-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm hidden">
                                         🔊 Boost +16dB (<span id="selectedBoostCountFloating">0</span>)
                                     </button>
                                     <button id="scanTtsIssuesBtnFloating" onclick="scanTtsVietnameseIssuesAllChapters()"
                                         class="bg-rose-600 hover:bg-rose-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm">
                                         🧪 Quét lỗi TTS
                                     </button>
                                     <button id="chunkEmbeddingBtnFloating" onclick="chunkAndEmbeddingAllChapters()"
                                         data-mode="{{ $chunkEmbeddingMode }}"
                                         @disabled(!$canChunkAndEmbedding)
                                         title="{{ $chunkEmbeddingButtonTitle }}"
                                         class="{{ $canChunkAndEmbedding
                                             ? 'bg-indigo-600 hover:bg-indigo-700 text-white'
                                             : 'bg-gray-300 text-gray-600 cursor-not-allowed' }} font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm">
                                         {{ $chunkEmbeddingButtonLabel }}
                                     </button>
                                     <button onclick="openScrapeModal()"
                                         class="bg-green-600 hover:bg-green-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm">
                                         🌐 Scrape
                                     </button>
                                     <a href="{{ route('audiobooks.export.word', $audioBook) }}" target="_blank"
                                         class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm inline-block">
                                         📄 Xuất TXT
                                     </a>
                                     <a href="{{ route('audiobooks.chapters.create', $audioBook) }}"
                                         class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-1.5 px-3 rounded-lg transition duration-200 text-sm">
                                         + Thêm chương
                                     </a>
                                 </div>
                             </div>
                         </div>

                         <div id="ttsIssueScanPanel" class="hidden mb-6 p-4 bg-rose-50 border border-rose-200 rounded-lg">
                             <div class="flex items-start justify-between gap-3">
                                 <div>
                                     <h4 class="text-sm font-semibold text-rose-800">🧪 Cảnh báo lỗi chữ có thể ảnh hưởng TTS tiếng Việt</h4>
                                     <p class="text-xs text-rose-700 mt-1">Danh sách này là gợi ý tự động, có thể có false positive. Bạn nên kiểm tra lại trước khi sửa.</p>
                                 </div>
                                 <button type="button" onclick="hideTtsIssueScanPanel()"
                                     class="text-xs bg-white hover:bg-rose-100 text-rose-700 border border-rose-200 px-2 py-1 rounded transition">
                                     Ẩn
                                 </button>
                             </div>
                             <div id="ttsIssueScanStatus" class="mt-3 text-sm text-rose-700"></div>
                             <div id="ttsIssueScanSummary" class="mt-3"></div>

                             <!-- Proper nouns section -->
                             <div class="tts-proper-nouns-section hidden mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                 <h5 class="text-xs font-semibold text-blue-800 mb-2">📛 Tên riêng & địa danh phát hiện trong toàn tài liệu <span class="font-normal text-blue-600">(kiểm tra cách đọc TTS)</span></h5>
                                 <div id="ttsProperNounsPanel" class="text-sm text-blue-700"></div>
                             </div>

                             <div id="ttsIssueScanList" class="mt-3 space-y-3 max-h-[420px] overflow-y-auto pr-1"></div>
                         </div>

                         <div id="chunkEmbeddingPanel" class="hidden mb-6 p-4 bg-indigo-50 border border-indigo-200 rounded-lg">
                             <div class="flex items-start justify-between gap-3">
                                 <div>
                                     <h4 class="text-sm font-semibold text-indigo-800">🧩 Chunk &amp; Embedding toàn bộ sách</h4>
                                     <p class="text-xs text-indigo-700 mt-1">Bước 1: cắt chương thành chunk. Bước 2: đẩy các chunk <code>pending</code> vào queue embedding.</p>
                                 </div>
                                 <button type="button" onclick="document.getElementById('chunkEmbeddingPanel')?.classList.add('hidden')"
                                     class="text-xs bg-white hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-2 py-1 rounded transition">
                                     Ẩn
                                 </button>
                             </div>
                             <div id="chunkEmbeddingStatus" class="mt-3 text-sm text-indigo-700"></div>
                         </div>

                         <div id="ttsIssueParagraphModal"
                             class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 p-4">
                             <div class="w-full max-w-3xl rounded-xl bg-white shadow-2xl flex flex-col" style="height:82vh;max-height:82vh">
                                 <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 shrink-0">
                                     <h4 class="text-sm font-semibold text-gray-800">📄 Đoạn văn chứa lỗi nghi ngờ</h4>
                                     <button type="button" onclick="closeTtsIssueParagraphModal()"
                                         class="rounded px-2 py-1 text-sm text-gray-500 hover:bg-gray-100 hover:text-gray-700">✕</button>
                                 </div>
                                 <div class="px-4 pt-3 shrink-0">
                                     <p id="ttsIssueParagraphMeta" class="text-xs text-rose-700"></p>
                                     <p class="text-[11px] text-gray-500 mt-1">✏️ Bạn có thể chỉnh sửa trực tiếp đoạn văn bên dưới rồi nhấn <strong>Lưu</strong>.</p>
                                 </div>
                                 <textarea id="ttsIssueParagraphContent"
                                     class="mx-4 mb-2 mt-2 flex-1 min-h-[300px] rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm leading-6 text-gray-800 resize-none focus:outline-none focus:border-rose-400 focus:ring-1 focus:ring-rose-300" style="min-height:300px"
                                     spellcheck="false"></textarea>
                                 <div id="ttsIssueParagraphSaveMsg" class="mx-4 mb-1 text-xs hidden"></div>
                                 <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-4 py-3 shrink-0">
                                     <button type="button" onclick="closeTtsIssueParagraphModal()"
                                         class="rounded-lg bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-200">Đóng</button>
                                     <button type="button" id="ttsIssueParagraphSaveBtn" onclick="saveTtsIssueParagraphEdit()"
                                         class="rounded-lg bg-rose-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-rose-700 disabled:opacity-50">💾 Lưu</button>
                                 </div>
                             </div>
                         </div>

                         <!-- TTS Progress -->
                         <div id="ttsProgressContainer"
                             class="hidden mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                             <div class="flex items-center justify-between mb-2">
                                 <span class="text-sm font-medium text-blue-800" id="ttsProgressStatus">Đang tạo
                                     TTS...</span>
                                 <span class="text-sm text-blue-600" id="ttsProgressPercent">0%</span>
                             </div>
                             <div class="w-full bg-blue-200 rounded-full h-2 mb-3">
                                 <div id="ttsProgressBar"
                                     class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: 0%">
                                 </div>
                             </div>
                             <!-- Detailed chunk progress -->
                             <div id="ttsChunkProgress">
                                 <div class="flex items-center justify-between text-xs text-blue-700 mb-1">
                                     <span id="ttsChunkStatus">Chương 1: Đoạn 0/0</span>
                                     <span id="ttsChunkPercent">0%</span>
                                 </div>
                                 <div class="w-full bg-blue-100 rounded-full h-1.5">
                                     <div id="ttsChunkBar"
                                         class="bg-blue-400 h-1.5 rounded-full transition-all duration-200"
                                         style="width: 0%"></div>
                                 </div>
                             </div>
                             <!-- Real-time generated chunks display -->
                             <div id="ttsGeneratedChunks"
                                 class="mt-3 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2">
                             </div>
                             <!-- Log output -->
                             <div id="ttsLogContainer"
                                 class="mt-3 max-h-40 overflow-y-auto text-xs font-mono bg-gray-900 text-green-400 p-2 rounded">
                             </div>
                         </div>

                         <!-- Boost Audio Progress -->
                         <div id="boostProgressContainer"
                             class="hidden mb-6 p-4 bg-orange-50 border border-orange-200 rounded-lg">
                             <div class="flex items-center justify-between mb-2">
                                 <span class="text-sm font-medium text-orange-800" id="boostProgressStatus">Đang boost âm lượng...</span>
                                 <span class="text-sm text-orange-600" id="boostProgressPercent">0%</span>
                             </div>
                             <div class="w-full bg-orange-200 rounded-full h-2 mb-3">
                                 <div id="boostProgressBar"
                                     class="bg-orange-500 h-2 rounded-full transition-all duration-300" style="width: 0%">
                                 </div>
                             </div>
                             <div class="flex items-center justify-between text-xs text-orange-700 mb-2">
                                 <span id="boostProgressDetail">0 / 0 chương</span>
                                 <span id="boostProgressStats" class="text-green-700"></span>
                             </div>
                             <div id="boostLogContainer"
                                 class="mt-2 max-h-40 overflow-y-auto text-xs font-mono bg-gray-900 text-green-400 p-2 rounded">
                             </div>
                         </div>

                         @if ($audioBook->chapters->count() > 0)
                             <!-- Collapse/Expand Toggle -->
                             <div class="flex items-center justify-between mb-3">
                                 <button type="button" id="toggleChapterListBtn" onclick="toggleChapterList()"
                                     class="flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-blue-600 transition px-3 py-1.5 rounded-lg hover:bg-blue-50">
                                     <svg id="chapterListArrow" class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                         <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                     </svg>
                                     <span id="chapterListToggleText">Hiện danh sách ({{ $audioBook->chapters->count() }} chương)</span>
                                 </button>
                             </div>
                             <div id="chapterListContainer" class="space-y-3" style="display: none;">
                                 @foreach ($audioBook->chapters as $chapter)
                                     <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition duration-200"
                                         id="chapter-{{ $chapter->id }}">
                                         <div class="flex items-start justify-between">
                                             <div class="flex-1">
                                                 <div class="flex items-center gap-3 mb-2">
                                                     <!-- Checkbox -->
                                                     <input type="checkbox" class="chapter-checkbox rounded"
                                                         data-chapter-id="{{ $chapter->id }}"
                                                         data-chapter-number="{{ $chapter->chapter_number }}">

                                                     @if ($chapter->cover_image)
                                                         <img src="{{ asset('storage/' . $chapter->cover_image) }}"
                                                             alt="{{ $chapter->title }}"
                                                             class="w-20 h-12 object-cover rounded cursor-pointer hover:opacity-80 transition border shadow-sm"
                                                             onclick="openImagePreview('{{ asset('storage/' . $chapter->cover_image) }}')"
                                                             title="Click để xem lớn">
                                                     @else
                                                         <div class="w-20 h-12 bg-gray-100 rounded flex items-center justify-center border border-dashed border-gray-300"
                                                             title="Chưa có ảnh bìa">
                                                             <span class="text-lg">📄</span>
                                                         </div>
                                                     @endif

                                                     @php
                                                         $charCount = mb_strlen($chapter->content, 'UTF-8');
                                                         // Ước tính: 150 từ/phút đọc, trung bình 5 ký tự/từ → 750 ký tự/phút
                                                         $readingMinutes = ceil($charCount / 750);
                                                         $readingTime =
                                                             $readingMinutes >= 60
                                                                 ? floor($readingMinutes / 60) .
                                                                     'h ' .
                                                                     $readingMinutes % 60 .
                                                                     'p'
                                                                 : $readingMinutes . ' phút';
                                                         $estimatedChunks = ceil($charCount / 2000);

                                                         // Check if title already has chapter number prefix
                                                         $hasChapterPrefix = preg_match(
                                                             '/^(Chương|Chapter|Phần)\s*\d+/iu',
                                                             $chapter->title,
                                                         );
                                                     @endphp
                                                     <div>
                                                         <h4 class="font-semibold text-gray-800">
                                                             @if ($hasChapterPrefix)
                                                                 {{ $chapter->title }}
                                                             @else
                                                                 Chương {{ $chapter->chapter_number }}:
                                                                 {{ $chapter->title }}
                                                             @endif
                                                         </h4>
                                                         <div class="flex flex-wrap gap-2 mt-1">
                                                             <span
                                                                 class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded">
                                                                 📝 {{ number_format($charCount) }} ký tự
                                                             </span>
                                                             <span
                                                                 class="text-xs text-gray-500 bg-blue-50 px-2 py-1 rounded">
                                                                 ⏱️ ~{{ $readingTime }}
                                                             </span>
                                                             <span
                                                                 class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded">
                                                                 📦
                                                                 {{ $chapter->chunks->count() }}/{{ $estimatedChunks }}
                                                                 đoạn
                                                             </span>
                                                             @if ($chapter->status == 'pending')
                                                                 <span
                                                                     class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded">⏳
                                                                     Chưa tạo TTS</span>
                                                             @elseif($chapter->status == 'processing')
                                                                 <span
                                                                     class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">⚙️
                                                                     Đang xử lý</span>
                                                             @elseif($chapter->status == 'completed')
                                                                 <span
                                                                     class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded">✅
                                                                     Hoàn tất</span>
                                                             @elseif($chapter->status == 'error')
                                                                 <span
                                                                     class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded">❌
                                                                     Lỗi</span>
                                                             @endif
                                                             @if ($chapter->audio_boosted_at)
                                                                 <span
                                                                     class="text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded"
                                                                     title="Boosted lúc {{ $chapter->audio_boosted_at->format('d/m H:i') }}">
                                                                     🔊 +16dB
                                                                 </span>
                                                             @endif
                                                         </div>
                                                     </div>
                                                 </div>

                                                 @if ($chapter->error_message)
                                                     <div
                                                         class="mt-2 p-2 bg-red-50 border border-red-200 rounded text-sm text-red-600">
                                                         {{ $chapter->error_message }}
                                                     </div>
                                                 @endif
                                             </div>

                                             <div class="flex gap-2 ml-4">
                                                 <a href="{{ route('audiobooks.chapters.edit', [$audioBook, $chapter]) }}"
                                                     class="bg-blue-100 hover:bg-blue-200 text-blue-700 font-semibold py-1 px-3 rounded transition duration-200 text-sm">
                                                     ✏️ Sửa
                                                 </a>
                                                 @if ($chapter->status != 'processing')
                                                     <form
                                                         action="{{ route('audiobooks.chapters.generate-tts', [$audioBook, $chapter]) }}"
                                                         method="POST" class="inline">
                                                         @csrf
                                                         <button type="submit"
                                                             class="bg-purple-100 hover:bg-purple-200 text-purple-700 font-semibold py-1 px-3 rounded transition duration-200 text-sm">
                                                             🎙️ TTS
                                                         </button>
                                                     </form>
                                                 @endif
                                                 <form
                                                     action="{{ route('audiobooks.chapters.destroy', [$audioBook, $chapter]) }}"
                                                     method="POST" class="inline"
                                                     onsubmit="return confirm('Xóa chương này?')">
                                                     @csrf
                                                     @method('DELETE')
                                                     <button type="submit"
                                                         class="bg-red-100 hover:bg-red-200 text-red-700 font-semibold py-1 px-3 rounded transition duration-200 text-sm">
                                                         🗑️
                                                     </button>
                                                 </form>
                                             </div>
                                         </div>

                                         <!-- Audio Preview -->
                                         @if ($chapter->chunks->where('status', 'completed')->count() > 0 || $chapter->audio_file)
                                             <div class="mt-4 pt-4 border-t border-gray-100">
                                                 <div class="flex items-center justify-between mb-3">
                                                     <p class="text-sm font-medium text-gray-600">
                                                         {{ $chapter->audio_file ? '🎧 Audio chương:' : '🎵 Các đoạn âm thanh:' }}
                                                     </p>
                                                     <div class="flex gap-2">
                                                         <button
                                                             onclick="deleteChapterAudio({{ $chapter->id }}, false)"
                                                             class="text-xs bg-orange-100 hover:bg-orange-200 text-orange-700 px-2 py-1 rounded transition">
                                                             🗑️ Xóa file full
                                                         </button>
                                                         <button onclick="deleteChapterAudio({{ $chapter->id }}, true)"
                                                             class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded transition">
                                                             🗑️ Xóa tất cả
                                                         </button>
                                                     </div>
                                                 </div>

                                                 <!-- Full chapter audio (special styling) -->
                                                 @if ($chapter->audio_file)
                                                     <div
                                                         class="mb-3 p-3 bg-gradient-to-r from-purple-100 to-pink-100 border-2 border-purple-300 rounded-lg">
                                                         <div class="flex items-center justify-between">
                                                             <div class="flex items-center gap-2">
                                                                 <span class="text-lg">🎧</span>
                                                                 <span class="font-semibold text-purple-800">Full Chương
                                                                     {{ $chapter->chapter_number }}</span>
                                                                 @if ($chapter->total_duration)
                                                                     <span
                                                                         class="text-xs bg-purple-200 text-purple-700 px-2 py-0.5 rounded">
                                                                         {{ gmdate('H:i:s', (int) $chapter->total_duration) }}
                                                                     </span>
                                                                 @endif
                                                             </div>
                                                             <audio controls class="h-8">
                                                                 <source
                                                                     src="{{ asset('storage/' . $chapter->audio_file) }}"
                                                                     type="audio/mpeg">
                                                             </audio>
                                                         </div>
                                                     </div>
                                                 @endif

                                                 <!-- Video Preview (if exists) -->
                                                 @if ($chapter->video_path)
                                                     <div
                                                         class="mb-3 p-3 bg-gradient-to-r from-blue-100 to-cyan-100 border-2 border-blue-300 rounded-lg">
                                                         <div class="flex items-center justify-between flex-wrap gap-2">
                                                             <div class="flex items-center gap-2">
                                                                 <span class="text-lg">🎬</span>
                                                                 <span class="font-semibold text-blue-800">Video Chương
                                                                     {{ $chapter->chapter_number }}</span>
                                                                 @php
                                                                     $videoSize = file_exists(
                                                                         storage_path(
                                                                             'app/public/' . $chapter->video_path,
                                                                         ),
                                                                     )
                                                                         ? round(
                                                                             filesize(
                                                                                 storage_path(
                                                                                     'app/public/' .
                                                                                         $chapter->video_path,
                                                                                 ),
                                                                             ) /
                                                                                 1024 /
                                                                                 1024,
                                                                             1,
                                                                         )
                                                                         : 0;
                                                                 @endphp
                                                                 <span
                                                                     class="text-xs bg-blue-200 text-blue-700 px-2 py-0.5 rounded">
                                                                     {{ $videoSize }} MB
                                                                 </span>
                                                             </div>
                                                             <div class="flex items-center gap-2">
                                                                 <button
                                                                     onclick="openVideoPreview('{{ asset('storage/' . $chapter->video_path) }}')"
                                                                     class="bg-blue-500 hover:bg-blue-600 text-white text-xs px-3 py-1.5 rounded transition">
                                                                     ▶️ Xem
                                                                 </button>
                                                                 <a href="{{ asset('storage/' . $chapter->video_path) }}"
                                                                     download="chapter_{{ $chapter->chapter_number }}.mp4"
                                                                     class="bg-green-500 hover:bg-green-600 text-white text-xs px-3 py-1.5 rounded transition">
                                                                     ⬇️ Tải xuống
                                                                 </a>
                                                             </div>
                                                         </div>
                                                     </div>
                                                 @endif

                                                 @if (!$chapter->audio_file)
                                                     <!-- Chunk audios -->
                                                     <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2"
                                                         id="chapter-{{ $chapter->id }}-chunks">
                                                         @foreach ($chapter->chunks->sortBy('chunk_number') as $chunk)
                                                             <div
                                                                 class="flex items-center justify-between p-2 rounded text-sm
                                                            @if ($chunk->status === 'completed') bg-green-50 border border-green-200
                                                            @elseif($chunk->status === 'processing') bg-blue-50 border border-blue-200
                                                            @elseif($chunk->status === 'error') bg-red-50 border border-red-200
                                                            @else bg-gray-50 border border-gray-200 @endif">
                                                                 <div class="flex items-center gap-2">
                                                                     <span
                                                                         class="text-xs font-medium 
                                                                    @if ($chunk->status === 'completed') text-green-700
                                                                    @elseif($chunk->status === 'processing') text-blue-700
                                                                    @elseif($chunk->status === 'error') text-red-700
                                                                    @else text-gray-600 @endif">
                                                                         @if ($chunk->status === 'completed')
                                                                             ✅
                                                                         @elseif($chunk->status === 'processing')
                                                                             ⏳
                                                                         @elseif($chunk->status === 'error')
                                                                             ❌
                                                                         @else
                                                                             ⏸️
                                                                         @endif
                                                                         Đoạn {{ $chunk->chunk_number }}
                                                                     </span>
                                                                     @if ($chunk->duration)
                                                                         <span
                                                                             class="text-xs text-gray-500">{{ round($chunk->duration, 1) }}s</span>
                                                                     @endif
                                                                 </div>
                                                                 <div class="flex items-center gap-1">
                                                                     @if ($chunk->audio_file)
                                                                         <audio controls class="h-6"
                                                                             style="max-width: 150px;">
                                                                             <source
                                                                                 src="{{ asset('storage/' . $chunk->audio_file) }}"
                                                                                 type="audio/mpeg">
                                                                         </audio>
                                                                         <button
                                                                             onclick="deleteChunkAudio({{ $audioBook->id }}, {{ $chapter->id }}, {{ $chunk->id }}, this)"
                                                                             class="text-red-400 hover:text-red-600 hover:bg-red-50 p-1 rounded transition ml-1 flex-shrink-0"
                                                                             title="Xóa audio đoạn {{ $chunk->chunk_number }}">
                                                                             🗑️
                                                                         </button>
                                                                     @endif
                                                                 </div>
                                                             </div>
                                                         @endforeach
                                                     </div>
                                                 @endif
                                             </div>
                                         @endif
                                     </div>
                                 @endforeach
                             </div>
                             </div>
                         @else
                             <div class="text-center py-12 bg-gray-50 rounded-lg">
                                 <div class="text-4xl mb-4">📖</div>
                                 <p class="text-gray-500 text-lg mb-6">Chưa có chương nào</p>
                                 <div class="flex gap-3 justify-center">
                                     <button onclick="openScrapeModal()"
                                         class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                                         🌐 Scrape từ website
                                     </button>
                                     <a href="{{ route('audiobooks.chapters.create', $audioBook) }}"
                                         class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                                         + Thêm chương thủ công
                                     </a>
                                 </div>
                             </div>
                         @endif
                     </div>
                 </div>
             </div> {{-- End chapters-tab --}}
         </div>
     </div>

     <!-- Image Preview Modal -->
     <div id="imagePreviewModal"
         class="hidden fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50 p-4"
         onclick="closeImagePreview()">
         <div class="relative max-w-6xl max-h-full flex flex-col items-center" onclick="event.stopPropagation()">
             <!-- Close button -->
             <button type="button" onclick="closeImagePreview()"
                 class="absolute -top-2 -right-2 bg-white hover:bg-gray-100 text-gray-800 rounded-full w-10 h-10 flex items-center justify-center text-2xl font-bold shadow-lg transition z-20">
                 ×
             </button>

             <!-- Image container with zoom -->
             <div class="overflow-auto max-h-[85vh] rounded-lg">
                 <img id="previewImage" src="" alt="Preview"
                     class="max-w-none rounded-lg shadow-2xl cursor-zoom-in transition-transform duration-200"
                     style="max-height: 85vh; width: auto;" onclick="toggleImageZoom(this)">
             </div>

             <!-- Controls bar -->
             <div class="mt-4 flex items-center gap-3 bg-white bg-opacity-10 backdrop-blur-sm rounded-lg px-4 py-2"
                 onclick="event.stopPropagation()">
                 <button type="button" onclick="event.stopPropagation(); zoomImage(-0.2)"
                     class="bg-white bg-opacity-20 hover:bg-opacity-40 text-white px-3 py-1.5 rounded-lg text-sm font-medium transition">
                     ➖ Thu nhỏ
                 </button>
                 <button type="button" onclick="event.stopPropagation(); resetImageZoom()"
                     class="bg-white bg-opacity-20 hover:bg-opacity-40 text-white px-3 py-1.5 rounded-lg text-sm font-medium transition">
                     🔄 Reset
                 </button>
                 <button type="button" onclick="event.stopPropagation(); zoomImage(0.2)"
                     class="bg-white bg-opacity-20 hover:bg-opacity-40 text-white px-3 py-1.5 rounded-lg text-sm font-medium transition">
                     ➕ Phóng to
                 </button>
                 <span class="text-white text-sm mx-2">|</span>
                 <a id="downloadImageLink" href="" download onclick="event.stopPropagation()"
                     class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-lg text-sm font-medium transition">
                     ⬇️ Tải về
                 </a>
             </div>

             <!-- Zoom level indicator -->
             <div id="zoomLevelIndicator"
                 class="absolute top-2 left-2 bg-black bg-opacity-60 text-white text-xs px-2 py-1 rounded">
                 100%
             </div>
         </div>
     </div>

     <!-- Scrape Chapters Modal -->
     <div id="scrapeModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
         <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
             <h3 class="text-xl font-semibold mb-4">🌐 Scrape chương từ website</h3>
             <form id="scrapeForm">
                 @csrf
                 <input type="hidden" name="audio_book_id" value="{{ $audioBook->id }}">

                 <div class="mb-4">
                     <label class="block text-sm font-medium text-gray-700 mb-2">Nguồn lấy dữ liệu</label>
                     <select id="scrapeSource" name="book_source"
                         class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                         required>
                         @foreach ($scrapeSources as $sourceKey => $source)
                             <option value="{{ $sourceKey }}"
                                 data-placeholder="@if ($sourceKey === 'docsach24') https://docsach24.co/e-book/ten-sach-xxxx.html @elseif($sourceKey === 'vietnamthuquan')http://vietnamthuquan.eu/truyen/truyen.aspx?tid=... @else https://nhasachmienphi.com/ten-sach.html @endif"
                                 data-hint="Chỉ chấp nhận URL thuộc {{ $source['label'] }}.">
                                 {{ $source['label'] }}
                             </option>
                         @endforeach
                     </select>
                     <p id="scrapeSourceHint" class="text-xs text-gray-500 mt-1"></p>
                 </div>

                 <div class="mb-4">
                     <label class="block text-sm font-medium text-gray-700 mb-2">URL sách</label>
                     <input type="url" name="book_url" id="scrapeBookUrl" placeholder=""
                         class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                         required>
                 </div>

                 <div class="flex gap-3">
                     <button type="submit"
                         class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold transition duration-200">
                         🚀 Bắt đầu Scrape
                     </button>
                     <button type="button" onclick="closeScrapeModal()"
                         class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-800 py-2 rounded-lg font-semibold transition duration-200">
                         Đóng
                     </button>
                 </div>
             </form>
             <div id="scrapeStatus" class="mt-4 text-sm"></div>
         </div>
     </div>

     <!-- Chapter Cover Generation Modal -->
     <div id="chapterCoverModal"
         class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
         <div class="bg-white rounded-lg shadow-xl p-6 max-w-5xl w-full mx-4 max-h-[95vh] overflow-hidden flex flex-col">
             <div class="flex justify-between items-center mb-4">
                 <h3 class="text-xl font-semibold">📚 Tạo ảnh bìa chương từ hình nền</h3>
                 <button type="button" onclick="closeChapterCoverModal()"
                     class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
             </div>

             <div class="flex-1 overflow-y-auto">
                 <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                     <!-- Left: Image Preview with Click to Position -->
                     <div>
                         <p class="text-sm font-medium text-gray-700 mb-2">📍 Click vào hình để chọn vị trí text:</p>
                         <div class="relative border-2 border-dashed border-purple-300 rounded-lg overflow-hidden bg-gray-50"
                             id="chapterCoverPreviewContainer">
                             <img id="selectedCoverImage" src="" alt="Selected"
                                 class="w-full h-auto cursor-crosshair" onclick="selectTextPosition(event)">
                             <!-- Text position marker -->
                             <div id="textPositionMarker"
                                 class="hidden absolute w-3 h-3 bg-red-500 rounded-full border-2 border-white shadow-lg transform -translate-x-1/2 -translate-y-1/2 pointer-events-none">
                             </div>
                             <!-- Live preview text -->
                             <div id="textLivePreview" class="hidden absolute pointer-events-none text-center"
                                 style="transform: translate(-50%, -50%);">
                                 <div id="previewChapterBadge"
                                     class="inline-block px-3 py-1 rounded font-bold shadow-lg">
                                     Chương 1
                                 </div>
                             </div>
                         </div>
                         <p class="text-xs text-gray-500 mt-2">
                             💡 Click vào vị trí muốn đặt text "Chương X". Vị trí này sẽ áp dụng cho tất cả chương được
                             chọn.
                         </p>
                         <input type="hidden" id="selectedCoverFilename" value="">
                         <input type="hidden" id="textPositionX" value="50">
                         <input type="hidden" id="textPositionY" value="15">
                     </div>

                     <!-- Right: Text Format Options -->
                     <div class="space-y-4">
                         <!-- Font Size -->
                         <div class="p-3 bg-purple-50 border border-purple-200 rounded-lg">
                             <label class="block text-sm font-medium text-purple-700 mb-2">📏 Kích thước chữ:</label>
                             <div class="flex items-center gap-3">
                                 <input type="range" id="chapterFontSize" min="40" max="150"
                                     value="80" step="5"
                                     class="flex-1 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                     oninput="updateChapterTextPreview()">
                                 <span id="fontSizeDisplay"
                                     class="text-sm font-semibold text-purple-700 w-12 text-center">80</span>
                             </div>
                         </div>

                         <!-- Text Color -->
                         <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                             <label class="block text-sm font-medium text-blue-700 mb-2">🎨 Màu chữ:</label>
                             <div class="grid grid-cols-4 gap-2">
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                     <input type="radio" name="chapterTextColor" value="#FFFFFF" checked
                                         class="text-blue-600" onchange="updateChapterTextPreview()">
                                     <span class="flex items-center gap-1">
                                         <span class="w-4 h-4 rounded-full bg-white border"></span>
                                         <span class="text-xs">Trắng</span>
                                     </span>
                                 </label>
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                     <input type="radio" name="chapterTextColor" value="#FFFF00"
                                         class="text-blue-600" onchange="updateChapterTextPreview()">
                                     <span class="flex items-center gap-1">
                                         <span class="w-4 h-4 rounded-full bg-yellow-400"></span>
                                         <span class="text-xs">Vàng</span>
                                     </span>
                                 </label>
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                     <input type="radio" name="chapterTextColor" value="#00FFFF"
                                         class="text-blue-600" onchange="updateChapterTextPreview()">
                                     <span class="flex items-center gap-1">
                                         <span class="w-4 h-4 rounded-full bg-cyan-400"></span>
                                         <span class="text-xs">Cyan</span>
                                     </span>
                                 </label>
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-blue-400">
                                     <input type="radio" name="chapterTextColor" value="#FF00FF"
                                         class="text-blue-600" onchange="updateChapterTextPreview()">
                                     <span class="flex items-center gap-1">
                                         <span class="w-4 h-4 rounded-full bg-pink-500"></span>
                                         <span class="text-xs">Hồng</span>
                                     </span>
                                 </label>
                             </div>
                         </div>

                         <!-- Outline Color -->
                         <div class="p-3 bg-orange-50 border border-orange-200 rounded-lg">
                             <label class="block text-sm font-medium text-orange-700 mb-2">✏️ Viền chữ:</label>
                             <div class="grid grid-cols-4 gap-2">
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-orange-400">
                                     <input type="radio" name="chapterOutlineColor" value="#000000" checked
                                         class="text-orange-600" onchange="updateChapterTextPreview()">
                                     <span class="flex items-center gap-1">
                                         <span class="w-4 h-4 rounded-full bg-black"></span>
                                         <span class="text-xs">Đen</span>
                                     </span>
                                 </label>
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-orange-400">
                                     <input type="radio" name="chapterOutlineColor" value="#8B00FF"
                                         class="text-orange-600" onchange="updateChapterTextPreview()">
                                     <span class="flex items-center gap-1">
                                         <span class="w-4 h-4 rounded-full bg-purple-600"></span>
                                         <span class="text-xs">Tím</span>
                                     </span>
                                 </label>
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-orange-400">
                                     <input type="radio" name="chapterOutlineColor" value="#FF0000"
                                         class="text-orange-600" onchange="updateChapterTextPreview()">
                                     <span class="flex items-center gap-1">
                                         <span class="w-4 h-4 rounded-full bg-red-600"></span>
                                         <span class="text-xs">Đỏ</span>
                                     </span>
                                 </label>
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-orange-400">
                                     <input type="radio" name="chapterOutlineColor" value="#0000FF"
                                         class="text-orange-600" onchange="updateChapterTextPreview()">
                                     <span class="flex items-center gap-1">
                                         <span class="w-4 h-4 rounded-full bg-blue-600"></span>
                                         <span class="text-xs">Xanh</span>
                                     </span>
                                 </label>
                             </div>
                             <div class="flex items-center gap-3 mt-2">
                                 <label class="text-xs text-gray-600">Độ dày:</label>
                                 <input type="range" id="chapterOutlineWidth" min="2" max="8"
                                     value="4" step="1"
                                     class="flex-1 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                     oninput="updateChapterTextPreview()">
                                 <span id="outlineWidthDisplay"
                                     class="text-xs font-semibold text-orange-700 w-8 text-center">4</span>
                             </div>
                         </div>

                         <!-- Text Content Mode -->
                         <div class="p-3 bg-emerald-50 border border-emerald-200 rounded-lg">
                             <label class="block text-sm font-medium text-emerald-700 mb-2">📝 Nội dung hiển thị:</label>
                             <div class="grid grid-cols-1 gap-2">
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-emerald-400">
                                     <input type="radio" name="chapterTextMode" value="number" checked
                                         class="text-emerald-600" onchange="updateChapterTextPreview()">
                                     <span class="text-xs">Chương X</span>
                                 </label>
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-emerald-400">
                                     <input type="radio" name="chapterTextMode" value="title"
                                         class="text-emerald-600" onchange="updateChapterTextPreview()">
                                     <span class="text-xs">Tên chương</span>
                                 </label>
                                 <label
                                     class="flex items-center gap-2 p-2 bg-white rounded border cursor-pointer hover:border-emerald-400">
                                     <input type="radio" name="chapterTextMode" value="both"
                                         class="text-emerald-600" onchange="updateChapterTextPreview()">
                                     <span class="text-xs">Chương X: Tên chương</span>
                                 </label>
                             </div>
                         </div>

                         <!-- Chapter selection -->
                         <div class="border-t pt-3">
                             <div class="flex items-center justify-between mb-2">
                                 <label class="text-sm font-medium text-gray-700">Chọn chương:</label>
                                 <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
                                     <input type="checkbox" id="selectAllChaptersCover" class="rounded"
                                         onchange="toggleAllChaptersCover()">
                                     <span>Chọn tất cả</span>
                                 </label>
                             </div>
                             <div id="chapterCoverList"
                                 class="space-y-2 max-h-48 overflow-y-auto border rounded-lg p-3 bg-gray-50">
                                 <!-- Chapters will be loaded here -->
                             </div>
                         </div>

                         <!-- Segment (Phần) selection -->
                         @if ($audioBook->videoSegments->count() > 0)
                             <div class="border-t pt-3">
                                 <div class="flex items-center justify-between mb-2">
                                     <label class="text-sm font-medium text-teal-700">Tạo bìa cho Phần (Segments):</label>
                                     <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
                                         <input type="checkbox" id="selectAllSegmentsCover" class="rounded"
                                             onchange="document.querySelectorAll('.segment-cover-checkbox').forEach(cb => cb.checked = this.checked)">
                                         <span>Chọn tất cả</span>
                                     </label>
                                 </div>
                                 <div id="segmentCoverList"
                                     class="space-y-2 max-h-36 overflow-y-auto border border-teal-200 rounded-lg p-3 bg-teal-50">
                                     @foreach ($audioBook->videoSegments as $seg)
                                         <label
                                             class="flex items-center gap-3 p-2 hover:bg-teal-100 rounded cursor-pointer">
                                             <input type="checkbox" class="segment-cover-checkbox rounded text-teal-600"
                                                 value="{{ $seg->id }}" data-seg-name="{{ $seg->name }}"
                                                 data-seg-chapters="{{ json_encode($seg->chapters) }}">
                                             <div
                                                 class="w-7 h-7 bg-teal-600 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">
                                                 P{{ $loop->iteration }}
                                             </div>
                                             <div class="flex-1 truncate">
                                                 <span class="text-gray-800 text-sm">{{ $seg->name }}</span>
                                                 <span class="text-xs text-gray-500 ml-1">(Ch.
                                                     {{ implode(', ', $seg->chapters ?? []) }})</span>
                                             </div>
                                         </label>
                                     @endforeach
                                 </div>
                             </div>
                         @endif
                     </div>
                 </div>
             </div>

             <!-- Progress -->
             <div id="chapterCoverProgress" class="hidden mt-4 mb-4">
                 <div class="flex items-center justify-between text-xs text-blue-700 mb-1">
                     <span id="chapterCoverProgressText">Đang tạo...</span>
                     <span id="chapterCoverProgressPercent">0%</span>
                 </div>
                 <div class="w-full bg-blue-100 rounded-full h-2">
                     <div id="chapterCoverProgressBar" class="bg-blue-500 h-2 rounded-full transition-all duration-300"
                         style="width: 0%"></div>
                 </div>
             </div>

             <!-- Status -->
             <div id="chapterCoverStatus" class="mb-4 text-sm"></div>

             <!-- Actions -->
             <div class="flex gap-3 mt-4">
                 <button type="button" id="generateChapterCoversBtn" onclick="generateChapterCovers()"
                     class="flex-1 bg-purple-600 hover:bg-purple-700 text-white py-2.5 rounded-lg font-semibold transition">
                     🎨 Tạo ảnh bìa cho chương đã chọn
                 </button>
                 <button type="button" onclick="closeChapterCoverModal()"
                     class="px-6 bg-gray-200 hover:bg-gray-300 text-gray-800 py-2.5 rounded-lg font-semibold transition">
                     Đóng
                 </button>
             </div>
         </div>
     </div>

                 </div>
             </div>

     <!-- Add Text Overlay Modal -->
     <div id="addTextModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
         <div class="bg-white rounded-lg shadow-xl p-6 max-w-3xl w-full mx-4 max-h-[95vh] overflow-hidden flex flex-col">
             <div class="flex justify-between items-center mb-4">
                 <h3 class="text-xl font-semibold">✏️ Thêm Text Overlay vào Hình</h3>
                 <button type="button" onclick="closeAddTextModal()"
                     class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
             </div>

             <div class="flex-1 overflow-y-auto">
                 <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                     <!-- Left: Image Preview -->
                     <div>
                         <p class="text-sm font-medium text-gray-700 mb-2">Hình nền:</p>
                         <img id="addTextPreviewImage" src="" alt="Preview"
                             class="w-full aspect-video object-cover rounded-lg border">
                         <input type="hidden" id="addTextFilename" value="">
                     </div>

                     <!-- Right: Text Options -->
                     <div class="space-y-3">
                         <!-- Text Content -->
                         <div class="p-3 bg-purple-50 border border-purple-200 rounded-lg">
                             <p class="text-xs font-medium text-purple-700 mb-2">📝 Nội dung text:</p>
                             <input type="text" id="addTextTitle"
                                 class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm font-semibold focus:border-purple-500 focus:outline-none mb-2"
                                 value="{{ $audioBook->title }}" placeholder="Tiêu đề">
                             <input type="text" id="addTextAuthor"
                                 class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm focus:border-purple-500 focus:outline-none mb-2"
                                 value="{{ $audioBook->author ? 'Tác giả: ' . $audioBook->author : '' }}"
                                 placeholder="Tác giả">
                             <input type="text" id="addTextChapter"
                                 class="w-full px-3 py-2 border border-purple-200 rounded-lg text-sm focus:border-purple-500 focus:outline-none"
                                 placeholder="Chương X (tùy chọn)">
                         </div>

                         <!-- Text Position -->
                         <div>
                             <label class="block text-xs font-medium text-gray-700 mb-1">Vị trí text:</label>
                             <div class="grid grid-cols-3 gap-2">
                                 <label
                                     class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                     <input type="radio" name="addTextPosition" value="top"
                                         class="text-indigo-600">
                                     <span>⬆️ Trên</span>
                                 </label>
                                 <label
                                     class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                     <input type="radio" name="addTextPosition" value="center"
                                         class="text-indigo-600">
                                     <span>⬅️➡️ Giữa</span>
                                 </label>
                                 <label
                                     class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                     <input type="radio" name="addTextPosition" value="bottom" checked
                                         class="text-indigo-600">
                                     <span>⬇️ Dưới</span>
                                 </label>
                             </div>
                         </div>

                         <!-- Colors Row -->
                         <div class="grid grid-cols-3 gap-2">
                             <div>
                                 <label class="block text-xs font-medium text-gray-700 mb-1">Màu chữ:</label>
                                 <div class="flex gap-1">
                                     <input type="color" id="addTextColor" value="#ffffff"
                                         class="w-8 h-8 rounded cursor-pointer border">
                                     <input type="text" id="addTextColorHex" value="#ffffff"
                                         class="flex-1 px-2 py-1 border rounded text-xs" placeholder="#ffffff">
                                 </div>
                             </div>
                             <div>
                                 <label class="block text-xs font-medium text-gray-700 mb-1">Màu viền:</label>
                                 <div class="flex gap-1">
                                     <input type="color" id="addTextBorderColor" value="#000000"
                                         class="w-8 h-8 rounded cursor-pointer border">
                                     <input type="text" id="addTextBorderColorHex" value="#000000"
                                         class="flex-1 px-2 py-1 border rounded text-xs" placeholder="#000000">
                                 </div>
                             </div>
                             <div>
                                 <label class="block text-xs font-medium text-gray-700 mb-1">Độ dày viền:</label>
                                 <select id="addTextBorderWidth" class="w-full px-2 py-1.5 border rounded text-xs">
                                     <option value="0">Không viền</option>
                                     <option value="2">Mỏng (2px)</option>
                                     <option value="4" selected>Vừa (4px)</option>
                                     <option value="6">Dày (6px)</option>
                                     <option value="8">Rất dày (8px)</option>
                                 </select>
                             </div>
                         </div>

                         <!-- Background Options -->
                         <div>
                             <label class="block text-xs font-medium text-gray-700 mb-1">Background chữ:</label>
                             <div class="grid grid-cols-4 gap-1">
                                 <label
                                     class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                     <input type="radio" name="addTextBgStyle" value="none" checked
                                         class="text-indigo-600">
                                     <span>Không</span>
                                 </label>
                                 <label
                                     class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                     <input type="radio" name="addTextBgStyle" value="solid"
                                         class="text-indigo-600">
                                     <span>Màu đặc</span>
                                 </label>
                                 <label
                                     class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                     <input type="radio" name="addTextBgStyle" value="gradient"
                                         class="text-indigo-600">
                                     <span>Gradient</span>
                                 </label>
                                 <label
                                     class="flex items-center gap-1 p-1.5 bg-white rounded border cursor-pointer hover:border-indigo-400 text-xs">
                                     <input type="radio" name="addTextBgStyle" value="blur"
                                         class="text-indigo-600">
                                     <span>Blur</span>
                                 </label>
                             </div>
                         </div>

                         <!-- Background Color Section -->
                         <div id="addTextBgColorSection" class="hidden">
                             <label class="block text-xs font-medium text-gray-700 mb-1">Màu background:</label>
                             <div class="flex gap-2 items-center">
                                 <input type="color" id="addTextBgColor" value="#000000"
                                     class="w-8 h-8 rounded cursor-pointer border">
                                 <input type="text" id="addTextBgColorHex" value="#000000"
                                     class="w-20 px-2 py-1 border rounded text-xs">
                                 <label class="text-xs text-gray-600">Opacity:</label>
                                 <input type="range" id="addTextBgOpacity" min="0" max="100"
                                     value="70" class="w-20">
                                 <span id="addTextBgOpacityValue" class="text-xs w-8">70%</span>
                             </div>
                         </div>

                         <!-- Font Size -->
                         <div>
                             <label class="block text-xs font-medium text-gray-700 mb-1">Cỡ chữ tiêu đề:</label>
                             <div class="flex items-center gap-2">
                                 <input type="range" id="addTextFontSize" min="40" max="100"
                                     value="60" class="flex-1">
                                 <span id="addTextFontSizeValue" class="text-xs w-12">60px</span>
                             </div>
                         </div>

                         <!-- Preset Styles -->
                         <div>
                             <label class="block text-xs font-medium text-gray-700 mb-1">Preset nhanh:</label>
                             <div class="flex flex-wrap gap-1">
                                 <button type="button"
                                     class="add-text-preset-btn px-2 py-1 bg-gray-800 text-white rounded text-xs"
                                     data-preset="classic">Classic</button>
                                 <button type="button"
                                     class="add-text-preset-btn px-2 py-1 bg-gradient-to-r from-red-500 to-yellow-500 text-white rounded text-xs"
                                     data-preset="fire">🔥 Fire</button>
                                 <button type="button"
                                     class="add-text-preset-btn px-2 py-1 bg-gradient-to-r from-blue-500 to-purple-500 text-white rounded text-xs"
                                     data-preset="neon">💜 Neon</button>
                                 <button type="button"
                                     class="add-text-preset-btn px-2 py-1 bg-gradient-to-r from-green-400 to-cyan-500 text-white rounded text-xs"
                                     data-preset="nature">🌿 Nature</button>
                                 <button type="button"
                                     class="add-text-preset-btn px-2 py-1 bg-amber-100 text-amber-900 border border-amber-300 rounded text-xs"
                                     data-preset="vintage">📜 Vintage</button>
                             </div>
                         </div>
                     </div>
                 </div>
             </div>

             <!-- Status -->
             <div id="addTextStatus" class="mt-4 text-sm"></div>

             <!-- Actions -->
             <div class="flex gap-3 mt-4">
                 <button type="button" id="applyTextOverlayBtn" onclick="applyTextOverlay()"
                     class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-lg font-semibold transition">
                     ✨ Tạo Thumbnail với Text
                 </button>
                 <button type="button" onclick="closeAddTextModal()"
                     class="px-6 bg-gray-200 hover:bg-gray-300 text-gray-800 py-2.5 rounded-lg font-semibold transition">
                     Đóng
                 </button>
             </div>
         </div>
     </div>

     <!-- Add Logo Overlay Modal -->
     <div id="addLogoModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
         <div class="bg-white rounded-lg shadow-xl p-6 max-w-2xl w-full mx-4 max-h-[90vh] overflow-hidden flex flex-col">
             <div class="flex justify-between items-center mb-4">
                 <h3 class="text-xl font-semibold">Logo Overlay</h3>
                 <button type="button" onclick="closeAddLogoModal()"
                     class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
             </div>

             <div class="flex-1 overflow-y-auto">
                 <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                     <!-- Left: Image Preview -->
                     <div>
                         <p class="text-sm font-medium text-gray-700 mb-2">Thumbnail:</p>
                         <img id="addLogoPreviewImage" src="" alt="Preview"
                             class="w-full aspect-video object-cover rounded-lg border">
                         <input type="hidden" id="addLogoFilename" value="">
                     </div>

                     <!-- Right: Logo Options -->
                     <div class="space-y-4">
                         <!-- Channel Logo Preview -->
                         <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                             <p class="text-xs font-medium text-yellow-700 mb-2">Logo kênh YouTube:</p>
                             @if ($audioBook->youtubeChannel && $audioBook->youtubeChannel->thumbnail_url)
                                 <img src="{{ str_starts_with($audioBook->youtubeChannel->thumbnail_url, 'http') ? $audioBook->youtubeChannel->thumbnail_url : asset('storage/' . $audioBook->youtubeChannel->thumbnail_url) }}"
                                     alt="Channel Logo"
                                     class="w-16 h-16 object-cover rounded-full border-2 border-yellow-300">
                             @else
                                 <div class="text-xs text-red-500">Kênh YouTube chưa có logo. Vui lòng cập nhật logo cho
                                     kênh trước.</div>
                             @endif
                         </div>

                         <!-- Position Selector -->
                         <div>
                             <label class="block text-xs font-medium text-gray-700 mb-2">Vị trí logo:</label>
                             <div class="grid grid-cols-3 gap-2" id="logoPositionGrid">
                                 <button type="button" data-position="top-left"
                                     class="logo-pos-btn flex items-center justify-center p-2 rounded border cursor-pointer text-xs transition bg-white border-gray-200 hover:border-orange-500">
                                     &#8598; Trên trái
                                 </button>
                                 <button type="button" data-position="center"
                                     class="logo-pos-btn flex items-center justify-center p-2 rounded border cursor-pointer text-xs transition bg-white border-gray-200 hover:border-orange-500">
                                     &#9678; Giữa
                                 </button>
                                 <button type="button" data-position="top-right"
                                     class="logo-pos-btn flex items-center justify-center p-2 rounded border cursor-pointer text-xs transition bg-white border-gray-200 hover:border-orange-500">
                                     &#8599; Trên phải
                                 </button>
                                 <button type="button" data-position="bottom-left"
                                     class="logo-pos-btn flex items-center justify-center p-2 rounded border cursor-pointer text-xs transition bg-white border-gray-200 hover:border-orange-500">
                                     &#8601; Dưới trái
                                 </button>
                                 <div></div>
                                 <button type="button" data-position="bottom-right"
                                     class="logo-pos-btn flex items-center justify-center p-2 rounded border cursor-pointer text-xs transition border-orange-500 bg-orange-50 font-semibold">
                                     &#8600; Dưới phải
                                 </button>
                             </div>
                             <input type="hidden" id="logoPositionValue" value="bottom-right">
                         </div>

                         <!-- Logo Size -->
                         <div>
                             <label class="block text-xs font-medium text-gray-700 mb-1">Kích thước logo (% chiều rộng
                                 ảnh):</label>
                             <div class="flex items-center gap-2">
                                 <input type="range" id="logoScale" min="5" max="50" value="15"
                                     class="flex-1">
                                 <span id="logoScaleValue" class="text-xs w-10 text-center font-medium">15%</span>
                             </div>
                         </div>

                         <!-- Opacity -->
                         <div>
                             <label class="block text-xs font-medium text-gray-700 mb-1">Độ trong suốt:</label>
                             <div class="flex items-center gap-2">
                                 <input type="range" id="logoOpacity" min="0" max="100"
                                     value="100" class="flex-1">
                                 <span id="logoOpacityValue" class="text-xs w-10 text-center font-medium">100%</span>
                             </div>
                         </div>

                         <!-- Margin -->
                         <div>
                             <label class="block text-xs font-medium text-gray-700 mb-1">Khoảng cách lề (px):</label>
                             <input type="number" id="logoMargin" value="20" min="0" max="200"
                                 class="w-full px-3 py-2 border rounded-lg text-sm focus:border-yellow-500 focus:outline-none">
                         </div>
                     </div>
                 </div>
             </div>

             <!-- Status -->
             <div id="addLogoStatus" class="mt-4 text-sm"></div>

             <!-- Actions -->
             <div class="flex gap-3 mt-4">
                 <button type="button" id="applyLogoOverlayBtn" onclick="applyLogoOverlay()"
                     class="flex-1 bg-orange-600 hover:bg-orange-700 text-white py-2.5 rounded-lg font-semibold transition">
                     🏷️ Gắn Logo
                 </button>
                 <button type="button" onclick="closeAddLogoModal()"
                     class="px-6 bg-gray-200 hover:bg-gray-300 text-gray-800 py-2.5 rounded-lg font-semibold transition">
                     ✖️ Đóng
                 </button>
             </div>
         </div>
     </div>

    <!-- Music Picker Modal -->
    <div id="musicPickerModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center"
        onclick="if(event.target === this) closeMusicPickerModal()">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl mx-4 max-h-[85vh] flex flex-col">
            <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 id="musicPickerModalTitle" class="text-lg font-semibold text-gray-900">🎵 Chọn file nhạc</h3>
                <button type="button" onclick="closeMusicPickerModal()" class="text-gray-400 hover:text-gray-600">
                    <span class="text-2xl leading-none">×</span>
                </button>
            </div>

            <div class="px-5 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between gap-3">
                <p class="text-sm text-gray-600">Danh sách toàn bộ file nhạc có sẵn trên hệ thống</p>
                <button type="button" onclick="triggerMusicUploadFromModal()"
                    class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-medium transition">
                    📤 Upload file nhạc mới
                </button>
            </div>

            <div id="musicPickerModalList" class="p-5 overflow-y-auto flex-1"></div>

            <div class="px-5 py-3 border-t border-gray-200 bg-gray-50 flex justify-end">
                <button type="button" onclick="closeMusicPickerModal()"
                    class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded text-sm">
                    Đóng
                </button>
            </div>
        </div>
    </div>

    <!-- Upload Media Modal -->
     <div id="uploadMediaModal"
         class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center">
         <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4">
             <div class="px-6 py-4 border-b border-gray-200">
                 <div class="flex items-center justify-between">
                     <h3 class="text-lg font-semibold text-gray-900">📤 Upload Hình Ảnh</h3>
                     <button onclick="closeUploadMediaModal()" class="text-gray-400 hover:text-gray-600">
                         <span class="text-2xl">×</span>
                     </button>
                 </div>
             </div>
             <div class="p-6">
                 <form id="uploadMediaForm" enctype="multipart/form-data">
                     <div class="mb-4">
                         <label class="block text-sm font-medium text-gray-700 mb-2">Loại media</label>
                         <select id="uploadMediaType" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                             <option value="thumbnails">🖼️ Thumbnail</option>
                             <option value="scenes">🎬 Scene</option>
                         </select>
                     </div>
                     <div class="mb-4">
                         <label class="block text-sm font-medium text-gray-700 mb-2">Chọn file ảnh</label>
                         <input type="file" id="uploadMediaFiles" multiple accept="image/*"
                             class="w-full border border-gray-300 rounded-lg px-3 py-2">
                         <p class="text-xs text-gray-500 mt-1">Hỗ trợ: JPG, PNG, WEBP. Cho phép chọn nhiều file.</p>
                     </div>
                     <div id="uploadMediaPreview" class="mb-4 grid grid-cols-3 gap-2"></div>
                     <div id="uploadMediaStatus" class="mb-4 text-sm"></div>
                 </form>
             </div>
             <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end gap-2">
                 <button type="button" onclick="closeUploadMediaModal()"
                     class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition">
                     Hủy
                 </button>
                 <button type="button" onclick="uploadMediaFiles()" id="uploadMediaBtn"
                     class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                     Upload
                 </button>
             </div>
         </div>
     </div>

     <script>
         // ========== CHAPTER LIST COLLAPSE/EXPAND ==========
         function toggleChapterList() {
             const container = document.getElementById('chapterListContainer');
             const arrow = document.getElementById('chapterListArrow');
             const text = document.getElementById('chapterListToggleText');
             const isHidden = container.style.display === 'none';

             if (isHidden) {
                 container.style.display = '';
                 arrow.style.transform = 'rotate(90deg)';
                 text.textContent = 'Ẩn danh sách chương';
                 localStorage.setItem('chapterListExpanded', '1');
             } else {
                 container.style.display = 'none';
                 arrow.style.transform = 'rotate(0deg)';
                 text.textContent = 'Hiện danh sách (' + container.children.length + ' chương)';
                 localStorage.setItem('chapterListExpanded', '0');
             }
         }

         // Restore state from localStorage OR auto-expand if URL has #chapter-* fragment
         document.addEventListener('DOMContentLoaded', function() {
             const hash = window.location.hash;
             const isChapterHash = hash && hash.startsWith('#chapter-');

             if (isChapterHash || localStorage.getItem('chapterListExpanded') === '1') {
                 const container = document.getElementById('chapterListContainer');
                 if (container) {
                     container.style.display = '';
                     document.getElementById('chapterListArrow').style.transform = 'rotate(90deg)';
                     document.getElementById('chapterListToggleText').textContent = 'Ẩn danh sách chương';
                     localStorage.setItem('chapterListExpanded', '1');
                 }
             }

             // Scroll to and highlight the target chapter
             if (isChapterHash) {
                 const target = document.querySelector(hash);
                 if (target) {
                     setTimeout(function() {
                         target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                         target.classList.add('ring-2', 'ring-blue-500', 'bg-blue-50');
                         setTimeout(function() {
                             target.classList.remove('ring-2', 'ring-blue-500', 'bg-blue-50');
                         }, 3000);
                     }, 200);
                 }
             }
         });

         // ========== SIDEBAR NAVIGATION ==========
         const OVERVIEW_SECTION_IDS = [
             'general-info-section',
             'book-description-section',
             'review-assets-section',
             'full-book-section',
             'videoSegmentsPanel',
             'tts-settings-section',
             'art-style-section'
         ];

         const SIDE_COLUMN_SECTION_IDS = new Set([
             'tts-settings-section',
             'art-style-section'
         ]);

         function activateFeatureTab(targetTab) {
             const overviewPanel = document.getElementById('overview-panel');
             const tabContents = document.querySelectorAll('.tab-content');

             if (overviewPanel) {
                 overviewPanel.classList.add('hidden');
             }

             tabContents.forEach(content => {
                 content.classList.add('hidden');
             });

             const targetTabElement = document.getElementById(targetTab + '-tab');
             if (targetTabElement) {
                 targetTabElement.classList.remove('hidden');
             }

             if (targetTab === 'youtube-media') {
                 refreshMediaGallery();
             }

             if (targetTab === 'short-video') {
                 loadShortVideos();
             }

             if (targetTab === 'clipping') {
                 loadClippingTab();
             }

             if (targetTab === 'auto-publish') {
                 initAutoPublishTab();
             }

             const featureMainContent = document.getElementById('feature-main-content');
             if (featureMainContent) {
                 requestAnimationFrame(() => {
                     featureMainContent.scrollIntoView({
                         behavior: 'smooth',
                         block: 'start'
                     });
                 });
             }
         }

         function activateOverviewPanel(sectionId = 'general-info-section') {
             const overviewPanel = document.getElementById('overview-panel');
             const tabContents = document.querySelectorAll('.tab-content');
             const overviewGrid = document.getElementById('overview-grid');
             const overviewMainColumn = document.getElementById('overview-main-column');
             const overviewSideColumn = document.getElementById('overview-side-column');

             if (overviewPanel) {
                 overviewPanel.classList.remove('hidden');
             }

             tabContents.forEach(content => {
                 content.classList.add('hidden');
             });

             const targetSectionId = OVERVIEW_SECTION_IDS.includes(sectionId) ? sectionId : 'general-info-section';
             const isSideColumnSection = SIDE_COLUMN_SECTION_IDS.has(targetSectionId);

             OVERVIEW_SECTION_IDS.forEach(id => {
                 const sectionElement = document.getElementById(id);
                 if (!sectionElement) {
                     return;
                 }

                 sectionElement.classList.toggle('hidden', id !== targetSectionId);
             });

             if (overviewGrid) {
                 overviewGrid.classList.remove('md:grid-cols-3');
                 overviewGrid.classList.add('grid-cols-1');
             }

             if (overviewMainColumn) {
                 overviewMainColumn.classList.toggle('hidden', isSideColumnSection);
             }

             if (overviewSideColumn) {
                 overviewSideColumn.classList.toggle('hidden', !isSideColumnSection);
             }

             if (targetSectionId === 'tts-settings-section') {
                 const ttsContent = document.getElementById('ttsContent');
                 const ttsIcon = document.getElementById('ttsToggleIcon');
                 if (ttsContent && ttsContent.style.display === 'none') {
                     ttsContent.style.display = 'block';
                     if (ttsIcon) {
                         ttsIcon.textContent = '−';
                     }
                 }
             }

             if (targetSectionId === 'art-style-section') {
                 const artStyleContent = document.getElementById('artStyleContent');
                 const artStyleIcon = document.getElementById('artStyleToggleIcon');
                 if (artStyleContent && artStyleContent.style.display === 'none') {
                     artStyleContent.style.display = 'block';
                     if (artStyleIcon) {
                         artStyleIcon.textContent = '−';
                     }
                 }
             }

             if (targetSectionId === 'videoSegmentsPanel') {
                 const segmentsContent = document.getElementById('videoSegmentsContent');
                 const toggleIcon = document.getElementById('videoSegmentsToggleIcon');
                 const toggleText = document.getElementById('videoSegmentsToggleText');
                 const toggleBtn = document.getElementById('videoSegmentsToggleBtn');

                 if (segmentsContent && segmentsContent.classList.contains('hidden')) {
                     segmentsContent.classList.remove('hidden');
                     if (toggleIcon) {
                         toggleIcon.textContent = '▾';
                     }
                     if (toggleText) {
                         toggleText.textContent = 'Thu gọn';
                     }
                     if (toggleBtn) {
                         toggleBtn.setAttribute('aria-expanded', 'true');
                     }
                 }
             }

             const featureMainContent = document.getElementById('feature-main-content');
             if (featureMainContent) {
                 requestAnimationFrame(() => {
                     featureMainContent.scrollIntoView({
                         behavior: 'smooth',
                         block: 'start'
                     });
                 });
             }
         }

         function initSidebarMenu() {
             const sidebarButtons = document.querySelectorAll('.sidebar-menu-btn');
             if (!sidebarButtons.length) {
                 return;
             }

             const setActiveButton = (activeButton) => {
                 sidebarButtons.forEach(button => {
                     button.classList.remove('bg-blue-50', 'text-blue-700', 'border-blue-200');
                     button.classList.add('text-gray-600', 'border-transparent');
                 });

                 activeButton.classList.add('bg-blue-50', 'text-blue-700', 'border-blue-200');
                 activeButton.classList.remove('text-gray-600', 'border-transparent');
             };

             sidebarButtons.forEach(button => {
                 button.addEventListener('click', function() {
                     setActiveButton(this);

                     if (this.dataset.view === 'tab') {
                         activateFeatureTab(this.dataset.tab);
                         return;
                     }

                     activateOverviewPanel(this.dataset.anchor || 'general-info-section');
                 });
             });

             const defaultButton = document.querySelector('.sidebar-menu-btn[data-default="true"]') || sidebarButtons[0];

             // If URL has #chapter-* hash, activate the chapters tab instead of default
             const hash = window.location.hash;
             if (hash && hash.startsWith('#chapter-')) {
                 const chaptersBtn = document.querySelector('.sidebar-menu-btn[data-tab="chapters"]');
                 if (chaptersBtn) {
                     chaptersBtn.click();
                 }
             } else if (defaultButton) {
                 defaultButton.click();
             }
         }

         function setSidebarCollapsed(collapsed) {
             const sidebar = document.getElementById('featureSidebar');
             const sidebarInner = document.getElementById('sidebarInner');
             const sidebarHeader = document.getElementById('sidebarHeader');
             const sidebarTitle = document.getElementById('sidebarTitle');
             const sidebarMenuList = document.getElementById('sidebarMenuList');
             const toggleBtn = document.getElementById('toggleSidebarBtn');

             if (!sidebar || !sidebarHeader || !sidebarTitle || !sidebarMenuList || !toggleBtn) {
                 return;
             }

             sidebar.classList.toggle('md:w-72', !collapsed);
             sidebar.classList.toggle('md:w-16', collapsed);

             if (sidebarInner) {
                 sidebarInner.classList.toggle('p-3', !collapsed);
                 sidebarInner.classList.toggle('sm:p-4', !collapsed);
                 sidebarInner.classList.toggle('p-2', collapsed);
             }

             sidebarHeader.classList.toggle('justify-between', !collapsed);
             sidebarHeader.classList.toggle('justify-center', collapsed);

             sidebarTitle.classList.toggle('hidden', collapsed);
             sidebarMenuList.classList.toggle('hidden', collapsed);

             toggleBtn.textContent = collapsed ? '»' : '«';
             toggleBtn.title = collapsed ? 'Mở rộng sidebar' : 'Thu gọn sidebar';
             toggleBtn.setAttribute('aria-label', collapsed ? 'Mở rộng sidebar' : 'Thu gọn sidebar');
             toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

             localStorage.setItem('audiobookSidebarCollapsed', collapsed ? '1' : '0');
         }

         function initSidebarCollapse() {
             const toggleBtn = document.getElementById('toggleSidebarBtn');
             if (!toggleBtn) {
                 return;
             }

             const isCollapsed = localStorage.getItem('audiobookSidebarCollapsed') === '1';
             setSidebarCollapsed(isCollapsed);

             toggleBtn.addEventListener('click', function() {
                 const currentlyCollapsed = localStorage.getItem('audiobookSidebarCollapsed') === '1';
                 setSidebarCollapsed(!currentlyCollapsed);
             });
         }

         // ========== GLOBAL VARIABLES ==========
         const audioBookId = {{ $audioBook->id }};
         const currentIntroMusicPath = @json($audioBook->intro_music);
         const currentOutroMusicPath = @json($audioBook->outro_music);
         const systemMusicFiles = @json($systemMusicFilesForModal ?? []);
         let musicPickerTargetType = 'intro';
         const deleteChapterUrlTemplate =
             "{{ route('audiobooks.chapters.destroy', ['audioBook' => $audioBook->id, 'chapter' => 'CHAPTER_ID_PLACEHOLDER']) }}";

         // ========== CSRF TOKEN HELPER ==========
         function getCsrfToken() {
             // Try to get from meta tag
             const metaToken = document.querySelector('meta[name="csrf-token"]');
             if (metaToken) {
                 return metaToken.getAttribute('content');
             }
             
             // Fallback to cookie (Laravel sets XSRF-TOKEN)
             const cookie = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='));
             if (cookie) {
                 return decodeURIComponent(cookie.split('=')[1]);
             }
             
             return '';
         }

         // ========== SAFE JSON HELPER ==========
         async function safeJson(resp) {
             if (!resp.ok) {
                 let errorText = '';
                 try {
                     errorText = await resp.text();
                 } catch (e) {}
                 
                 // Handle CSRF token expiration
                 if (resp.status === 419) {
                     // Show message and reload page
                     const reload = confirm('Phiên làm việc đã hết hạn. Trang sẽ được tải lại để tiếp tục. Bấm OK để tải lại.');
                     if (reload) {
                         window.location.reload();
                     }
                     throw new Error('Phiên làm việc đã hết hạn. Vui lòng tải lại trang (F5) để tiếp tục.');
                 }
                 
                 // Try to extract error message from JSON response
                 try {
                     const errorData = JSON.parse(errorText);
                     throw new Error(errorData.error || errorData.message || ('HTTP ' + resp.status));
                 } catch (e) {
                     if (e.message && !e.message.startsWith('Unexpected')) {
                         throw e;
                     }
                     // If it's HTML, try to extract meaningful error
                     if (errorText.includes('<')) {
                         const titleMatch = errorText.match(/<title[^>]*>(.*?)<\/title>/i);
                         const h1Match = errorText.match(/<h1[^>]*>(.*?)<\/h1>/i);
                         const errorMsg = titleMatch?.[1] || h1Match?.[1] || 'Server returned HTML error page';
                         throw new Error('HTTP ' + resp.status + ': ' + errorMsg.replace(/<[^>]+>/g, '').trim());
                     }
                     throw new Error('HTTP ' + resp.status + ': ' + resp.statusText);
                 }
             }
             
             // Handle successful response that might not be JSON
             try {
                 return await resp.json();
             } catch (e) {
                 const text = await resp.text();
                 // If response is HTML instead of JSON
                 if (text.includes('<')) {
                     const titleMatch = text.match(/<title[^>]*>(.*?)<\/title>/i);
                     const h1Match = text.match(/<h1[^>]*>(.*?)<\/h1>/i);
                     const errorMsg = titleMatch?.[1] || h1Match?.[1] || 'Server returned HTML instead of JSON';
                     throw new Error(errorMsg.replace(/<[^>]+>/g, '').trim() + ' (Kiểm tra đăng nhập hoặc lỗi server)');
                 }
                 throw new Error('Invalid JSON response: ' + e.message);
             }
         }

         let shortVideoItems = [];
         let selectedShortIndexes = new Set();
         let grammarlyDisableObserver = null;
        let activeShortWorkspaceIndex = null;
        let activeShortWorkspaceItem = null;
        let shortWorkspaceKlingAutoPollToken = 0;

         function disableGrammarly(target = document) {
             const applyAttrs = (el) => {
                 if (!el || typeof el.setAttribute !== 'function') return;
                 el.setAttribute('data-gramm', 'false');
                 el.setAttribute('data-gramm_editor', 'false');
                 el.setAttribute('data-enable-grammarly', 'false');
                 el.setAttribute('spellcheck', 'false');
             };

             applyAttrs(document.documentElement);
             if (document.body) applyAttrs(document.body);

             if (!target || typeof target.querySelectorAll !== 'function') return;
             target.querySelectorAll('textarea, input[type="text"], input[type="search"], [contenteditable="true"]').forEach(
                 applyAttrs);
         }

         function setupGrammarlyProtection() {
             disableGrammarly(document);
             if (grammarlyDisableObserver || !document.body) return;

             grammarlyDisableObserver = new MutationObserver((mutations) => {
                 for (const mutation of mutations) {
                     mutation.addedNodes.forEach((node) => {
                         if (!node || node.nodeType !== Node.ELEMENT_NODE) return;
                         disableGrammarly(node);
                     });
                 }
             });

             grammarlyDisableObserver.observe(document.body, {
                 childList: true,
                 subtree: true
             });
         }

         function escapeHtml(text) {
             return (text || '').replace(/&/g, '&amp;')
                 .replace(/</g, '&lt;')
                 .replace(/>/g, '&gt;')
                 .replace(/"/g, '&quot;')
                 .replace(/'/g, '&#039;');
         }

         function setShortVideoStatus(message, type = 'info') {
             const status = document.getElementById('shortVideoStatus');
             if (!status) return;

             const typeClass = type === 'success' ? 'text-green-600' : type === 'error' ? 'text-red-600' : 'text-blue-600';
             status.innerHTML = `<span class="${typeClass}">${message}</span>`;
         }

         function getSelectedShortIndices() {
             return Array.from(selectedShortIndexes).sort((a, b) => a - b);
         }

         function syncShortSelectionToolbar() {
             const selectedCount = selectedShortIndexes.size;
             const selectedCountLabel = document.getElementById('selectedShortCount');
             const selectAll = document.getElementById('selectAllShortVideos');
             const ttsBtn = document.getElementById('generateSelectedShortTtsBtn');
             const imageBtn = document.getElementById('generateSelectedShortImagesBtn');
             const downloadBtn = document.getElementById('downloadSelectedShortBtn');
             const deleteBtn = document.getElementById('deleteSelectedShortBtn');

             if (selectedCountLabel) {
                 selectedCountLabel.textContent = `Đã chọn: ${selectedCount}`;
             }
             if (ttsBtn) ttsBtn.disabled = selectedCount === 0;
             if (imageBtn) imageBtn.disabled = selectedCount === 0;
             if (downloadBtn) downloadBtn.disabled = selectedCount === 0;
             if (deleteBtn) deleteBtn.disabled = selectedCount === 0;

             if (selectAll) {
                 const total = shortVideoItems.length;
                 selectAll.checked = total > 0 && selectedCount === total;
                 selectAll.indeterminate = selectedCount > 0 && selectedCount < total;
             }
         }

         async function deleteShortVideoItem(index) {
             if (!confirm(`Xóa short #${index}?`)) return;

             try {
                 const resp = await fetch(`/audiobooks/${audioBookId}/short-videos/${index}`, {
                     method: 'DELETE',
                     headers: {
                         'X-CSRF-TOKEN': getCsrfToken()
                     }
                 });
                 const data = await safeJson(resp);
                 shortVideoItems = data.items || [];
                 selectedShortIndexes.delete(index);
                 const validIndexes = new Set(shortVideoItems.map(item => parseInt(item.index, 10)).filter(Number
                     .isFinite));
                 selectedShortIndexes = new Set(Array.from(selectedShortIndexes).filter(i => validIndexes.has(i)));
                 renderShortVideos(shortVideoItems);
                 setShortVideoStatus(`✅ Đã xóa short #${index}`, 'success');
             } catch (error) {
                 setShortVideoStatus(`❌ ${error.message}`, 'error');
             }
         }

         async function runSelectedShortAction(action) {
             const providerSelect = document.getElementById('shortVideoProvider');
            const imageProviderSelect = document.getElementById('shortImageProvider');
             const selectedIndices = getSelectedShortIndices();
             if (selectedIndices.length === 0) {
                 setShortVideoStatus('❌ Vui lòng chọn ít nhất 1 short.', 'error');
                 return;
             }

             const actionMap = {
                 tts: {
                     endpoint: 'generate-tts',
                     loading: '⏳ Đang tạo TTS cho các short đã chọn...',
                     success: '✅ Tạo TTS xong',
                 },
                 images: {
                     endpoint: 'generate-images',
                     loading: '⏳ Đang tạo ảnh cho các short đã chọn...',
                     success: '✅ Tạo ảnh xong',
                 }
             };

             const config = actionMap[action];
             if (!config) return;

             setShortVideoStatus(config.loading, 'info');

             try {
                 const payload = {
                     selected_indices: selectedIndices
                 };
                 if (action === 'tts') {
                     payload.provider = providerSelect?.value || 'openai';
                }
                if (action === 'images') {
                    payload.image_provider = imageProviderSelect?.value || 'gemini';
                 }

                 const resp = await fetch(`/audiobooks/${audioBookId}/short-videos/${config.endpoint}`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify(payload)
                 });

                 const data = await safeJson(resp);
                 shortVideoItems = data.items || [];
                 renderShortVideos(shortVideoItems);
                 setShortVideoStatus(`${config.success}: ${data.completed || 0} thành công, ${data.failed || 0} lỗi`,
                     'success');
             } catch (error) {
                 setShortVideoStatus(`❌ ${error.message}`, 'error');
             }
         }

         async function saveShortVideoContent(index, scriptText, imagePromptText) {
             const text = (scriptText || '').trim();
             const imagePrompt = (imagePromptText || '').trim();

             if (text.length < 10) {
                 setShortVideoStatus('❌ Nội dung script quá ngắn (ít nhất 10 ký tự).', 'error');
                 return;
             }

             if (imagePrompt.length < 10) {
                 setShortVideoStatus('❌ Prompt tạo ảnh quá ngắn (ít nhất 10 ký tự).', 'error');
                 return;
             }

             try {
                 const resp = await fetch(`/audiobooks/${audioBookId}/short-videos/${index}`, {
                     method: 'PUT',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         script: text,
                         image_prompt: imagePrompt
                     })
                 });
                 const data = await safeJson(resp);
                 shortVideoItems = data.items || [];
                 renderShortVideos(shortVideoItems);
                 setShortVideoStatus(`✅ Đã lưu nội dung + prompt short #${index}`, 'success');
             } catch (error) {
                 setShortVideoStatus(`❌ ${error.message}`, 'error');
             }
         }

         async function deleteSelectedShortVideos() {
             const selectedIndices = getSelectedShortIndices();
             if (selectedIndices.length === 0) {
                 setShortVideoStatus('❌ Vui lòng chọn ít nhất 1 short.', 'error');
                 return;
             }

             if (!confirm(`Xóa ${selectedIndices.length} short đã chọn?`)) return;

             const deleteQueue = [...selectedIndices].sort((a, b) => b - a);

             for (const index of deleteQueue) {
                 try {
                     const resp = await fetch(`/audiobooks/${audioBookId}/short-videos/${index}`, {
                         method: 'DELETE',
                         headers: {
                             'X-CSRF-TOKEN': getCsrfToken()
                         }
                     });
                     const data = await safeJson(resp);
                     shortVideoItems = data.items || [];
                 } catch (error) {
                     setShortVideoStatus(`❌ Xóa short #${index} thất bại: ${error.message}`, 'error');
                     await loadShortVideos();
                     return;
                 }
             }

             selectedShortIndexes.clear();
             renderShortVideos(shortVideoItems);
             setShortVideoStatus(`✅ Đã xóa ${selectedIndices.length} short`, 'success');
         }

         async function downloadSelectedShortVideos() {
             const selectedIndices = getSelectedShortIndices();
             if (selectedIndices.length === 0) {
                 setShortVideoStatus('❌ Vui lòng chọn ít nhất 1 short.', 'error');
                 return;
             }

             setShortVideoStatus(`⏳ Đang chuẩn bị gói tải cho ${selectedIndices.length} short...`, 'info');

             try {
                 const resp = await fetch(`/audiobooks/${audioBookId}/short-videos/download-resources`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         selected_indices: selectedIndices
                     })
                 });

                 if (!resp.ok) {
                     let message = `HTTP ${resp.status}`;
                     try {
                         const err = await resp.json();
                         message = err.error || err.message || message;
                     } catch (_) {}
                     throw new Error(message);
                 }

                 const blob = await resp.blob();
                 const contentDisposition = resp.headers.get('Content-Disposition') || '';
                 const match = contentDisposition.match(/filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i);
                 const fileName = decodeURIComponent((match && (match[1] || match[2])) ? (match[1] || match[2]) :
                     `short_resources_${audioBookId}.zip`);

                 const url = URL.createObjectURL(blob);
                 const link = document.createElement('a');
                 link.href = url;
                 link.download = fileName;
                 link.style.display = 'none';
                 document.body.appendChild(link);
                 link.click();
                 link.remove();
                 URL.revokeObjectURL(url);

                 setShortVideoStatus('✅ Đã tải gói tài nguyên short đã chọn.', 'success');
             } catch (error) {
                 setShortVideoStatus(`❌ ${error.message}`, 'error');
             }
         }

         async function generateShortImagePromptByAi(index) {
             if (!index) return;

             setShortVideoStatus(`⏳ ChatGPT đang tạo prompt ảnh cho short #${index}...`, 'info');

             try {
                 const resp = await fetch(`/audiobooks/${audioBookId}/short-videos/${index}/generate-image-prompt`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({})
                 });

                 const data = await safeJson(resp);
                 shortVideoItems = data.items || shortVideoItems;
                 renderShortVideos(shortVideoItems);
                 setShortVideoStatus(`✅ Đã tạo prompt ảnh bằng ChatGPT cho short #${index}`, 'success');
             } catch (error) {
                 setShortVideoStatus(`❌ ${error.message}`, 'error');
             }
         }

        function setShortWorkspaceStatus(message, type = 'info') {
            const status = document.getElementById('shortWorkspaceStatus');
            if (!status) return;
            const cls = type === 'success' ? 'text-green-600' : type === 'error' ? 'text-red-600' : 'text-blue-600';
            status.innerHTML = `<span class="${cls}">${message}</span>`;
        }

        function closeShortWorkspaceModal() {
            stopShortWorkspaceKlingAutoPoll();
            const modal = document.getElementById('shortWorkspaceModal');
            if (modal) modal.classList.add('hidden');
            activeShortWorkspaceIndex = null;
            activeShortWorkspaceItem = null;
        }

        function syncShortWorkspaceShotSelection() {
            const selectAll = document.getElementById('shortWorkspaceSelectAllShots');
            const checkboxes = Array.from(document.querySelectorAll('.sw-shot-checkbox'));
            if (!selectAll || checkboxes.length === 0) {
                if (selectAll) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }
                return;
            }

            const checkedCount = checkboxes.filter(cb => cb.checked).length;
            selectAll.checked = checkedCount > 0 && checkedCount === checkboxes.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        }

        function getShortWorkspaceSelectedShotIndices() {
            return Array.from(document.querySelectorAll('.sw-shot-checkbox:checked'))
                .map(cb => parseInt(cb.dataset.shotIndex || '0', 10))
                .filter(Number.isFinite)
                .sort((a, b) => a - b);
        }

        function stopShortWorkspaceKlingAutoPoll() {
            shortWorkspaceKlingAutoPollToken++;
        }

        function getShortWorkspaceKlingProgress(targetShotIndices = null) {
            const shots = Array.isArray(activeShortWorkspaceItem?.shots) ? activeShortWorkspaceItem.shots : [];
            const targetSet = Array.isArray(targetShotIndices) && targetShotIndices.length > 0 ?
                new Set(targetShotIndices.map(v => parseInt(v, 10)).filter(Number.isFinite)) :
                null;

            const scopedShots = shots.filter(shot => {
                if (!targetSet) return true;
                const shotNo = parseInt(shot.shot_index || '0', 10);
                return targetSet.has(shotNo);
            });

            const progress = {
                total: scopedShots.length,
                completed: 0,
                processing: 0,
                failed: 0,
                idle: 0,
            };

            scopedShots.forEach(shot => {
                const status = (shot.kling_status || 'idle').toString().toLowerCase();
                if (status === 'completed' || status === 'succeeded' || status === 'success') {
                    progress.completed++;
                } else if (status === 'queued' || status === 'processing' || status === 'generating' || status === 'submitted' || status === 'pending') {
                    progress.processing++;
                } else if (status === 'failed' || status === 'error' || status === 'canceled' || status === 'cancelled') {
                    progress.failed++;
                } else {
                    progress.idle++;
                }
            });

            return progress;
        }

        async function startShortWorkspaceKlingAutoPoll(targetShotIndices = null) {
            if (!activeShortWorkspaceIndex) return;

            const normalizedTarget = Array.isArray(targetShotIndices) && targetShotIndices.length > 0 ?
                Array.from(new Set(targetShotIndices.map(v => parseInt(v, 10)).filter(Number.isFinite))).sort((a, b) => a - b) :
                null;

            const token = ++shortWorkspaceKlingAutoPollToken;
            const intervalMs = 5000;
            const maxRounds = 40;
            let consecutiveErrors = 0;

            for (let round = 1; round <= maxRounds; round++) {
                if (token !== shortWorkspaceKlingAutoPollToken || !activeShortWorkspaceIndex) return;

                await new Promise(resolve => setTimeout(resolve, intervalMs));

                if (token !== shortWorkspaceKlingAutoPollToken || !activeShortWorkspaceIndex) return;

                const pollData = await runShortWorkspaceAction('kling-poll', normalizedTarget, true);
                if (!pollData) {
                    consecutiveErrors++;
                    if (consecutiveErrors >= 3) {
                        if (token === shortWorkspaceKlingAutoPollToken) {
                            setShortWorkspaceStatus('⚠️ Auto poll video gặp lỗi lặp lại. Bạn có thể bấm "Poll Video AI" để thử lại thủ công.', 'error');
                        }
                        return;
                    }
                    continue;
                }

                consecutiveErrors = 0;
                const progress = getShortWorkspaceKlingProgress(normalizedTarget);

                if (progress.total === 0) {
                    if (token === shortWorkspaceKlingAutoPollToken) {
                        setShortWorkspaceStatus('ℹ️ Không có shot hợp lệ để auto poll video.', 'info');
                    }
                    return;
                }

                if (progress.processing <= 0) {
                    if (token === shortWorkspaceKlingAutoPollToken) {
                        const doneMsg = `✅ Auto poll video hoàn tất: done ${progress.completed}/${progress.total}, fail ${progress.failed}.`;
                        setShortWorkspaceStatus(doneMsg, progress.failed > 0 ? 'error' : 'success');
                    }
                    return;
                }

                if (token === shortWorkspaceKlingAutoPollToken) {
                    setShortWorkspaceStatus(
                        `⏳ Auto poll video ${round}/${maxRounds}: done ${progress.completed}/${progress.total}, processing ${progress.processing}, fail ${progress.failed}.`,
                        'info'
                    );
                }
            }

            if (token === shortWorkspaceKlingAutoPollToken) {
                setShortWorkspaceStatus('⏱️ Auto poll video tạm dừng do quá thời gian. Bấm "Poll Video AI" để cập nhật thêm.', 'error');
            }
        }

        function parseShortWorkspaceRefIndices(value, shotIndex) {
            if (!value) return [];
            return Array.from(new Set(value.split(',')
                    .map(v => parseInt(v.trim(), 10))
                    .filter(v => Number.isFinite(v) && v > 0 && v !== shotIndex)))
                .sort((a, b) => a - b);
        }

        function getShortWorkspaceTtsPayload() {
            const provider = document.getElementById('shortWorkspaceProvider')?.value || 'openai';
            const voiceName = document.getElementById('shortWorkspaceVoiceName')?.value?.trim() || '';
            const voiceGender = document.getElementById('shortWorkspaceVoiceGender')?.value || 'female';
            const ttsSpeed = parseFloat(document.getElementById('shortWorkspaceTtsSpeed')?.value || '1.0') || 1.0;
            return {
                provider,
                voice_name: voiceName,
                voice_gender: voiceGender,
                tts_speed: ttsSpeed,
            };
        }

        function mergeWorkspaceItemIntoShortList(updatedItem) {
            if (!updatedItem) return;
            const updatedIndex = parseInt(updatedItem.index || activeShortWorkspaceIndex || '0', 10);
            if (!updatedIndex) return;
            const pos = shortVideoItems.findIndex(item => parseInt(item.index || '0', 10) === updatedIndex);
            if (pos >= 0) {
                shortVideoItems[pos] = updatedItem;
            } else {
                shortVideoItems.push(updatedItem);
            }
            renderShortVideos(shortVideoItems);
        }

        async function loadShortWorkspace(index) {
            const resp = await fetch(`/audiobooks/${audioBookId}/short-videos/${index}/workspace`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                }
            });
            const data = await safeJson(resp);
            if (!data.success || !data.item) {
                throw new Error(data.error || 'Không tải được workspace short.');
            }

            activeShortWorkspaceItem = data.item;
            renderShortWorkspace(activeShortWorkspaceItem);

            if (!data.workspace_ready) {
                setShortWorkspaceStatus('ℹ️ Short này chưa tách theo từng câu. Bấm "Build từ từng câu" để bắt đầu.', 'info');
            } else {
                const shotCount = Array.isArray(data.item?.shots) ? data.item.shots.length : 0;
                setShortWorkspaceStatus(`✅ Workspace đã sẵn sàng (${shotCount} shot). Cuộn trong modal để xem toàn bộ.`, 'success');
            }
        }

        async function openShortWorkspaceModal(index) {
            const modal = document.getElementById('shortWorkspaceModal');
            if (!modal) return;

            stopShortWorkspaceKlingAutoPoll();
            activeShortWorkspaceIndex = index;
            activeShortWorkspaceItem = null;

            const shortProvider = document.getElementById('shortVideoProvider')?.value;
            if (shortProvider) {
                const workspaceProvider = document.getElementById('shortWorkspaceProvider');
                if (workspaceProvider) workspaceProvider.value = shortProvider;
            }

            const shortImageProvider = document.getElementById('shortImageProvider')?.value;
            if (shortImageProvider) {
                const workspaceImageProvider = document.getElementById('shortWorkspaceImageProvider');
                if (workspaceImageProvider) workspaceImageProvider.value = shortImageProvider;
            }

            const voiceName = document.getElementById('voiceNameSelect')?.value || '';
            const voiceGender = document.querySelector('input[name="voiceGender"]:checked')?.value || 'female';
            const ttsSpeed = document.getElementById('ttsSpeedSelect')?.value || '1.0';

            const workspaceVoiceName = document.getElementById('shortWorkspaceVoiceName');
            const workspaceVoiceGender = document.getElementById('shortWorkspaceVoiceGender');
            const workspaceTtsSpeed = document.getElementById('shortWorkspaceTtsSpeed');
            if (workspaceVoiceName) workspaceVoiceName.value = voiceName;
            if (workspaceVoiceGender) workspaceVoiceGender.value = voiceGender;
            if (workspaceTtsSpeed) workspaceTtsSpeed.value = ttsSpeed;

            modal.classList.remove('hidden');
            const modalBody = document.getElementById('shortWorkspaceModalBody');
            if (modalBody) modalBody.scrollTop = 0;
            const shotsViewport = document.getElementById('shortWorkspaceShotsViewport');
            if (shotsViewport) shotsViewport.scrollTop = 0;
            setShortWorkspaceStatus(`⏳ Đang tải workspace short #${index}...`, 'info');

            try {
                await loadShortWorkspace(index);
            } catch (error) {
                setShortWorkspaceStatus(`❌ ${error.message}`, 'error');
            }
        }

        function renderShortWorkspace(item) {
            const titleEl = document.getElementById('shortWorkspaceTitle');
            const subtitleEl = document.getElementById('shortWorkspaceSubtitle');
            const storyEl = document.getElementById('shortWorkspaceStoryBible');
            const characterEl = document.getElementById('shortWorkspaceCharacterBible');
            const shotsMetaEl = document.getElementById('shortWorkspaceShotsMeta');
            const shotsList = document.getElementById('shortWorkspaceShotsList');

            if (!shotsList) return;

            if (titleEl) {
                titleEl.textContent = `🎬 Studio câu cho short #${item.index || activeShortWorkspaceIndex || ''}`;
            }
            if (subtitleEl) {
                subtitleEl.textContent = item.title || '';
            }
            if (storyEl) storyEl.value = item.story_bible || '';
            if (characterEl) characterEl.value = item.character_bible || '';

            const shots = Array.isArray(item.shots) ? item.shots : [];
            if (shots.length === 0) {
                if (shotsMetaEl) {
                    shotsMetaEl.textContent = 'Hiện chưa có shot nào.';
                }
                shotsList.innerHTML = `
                    <div class="p-4 border border-dashed border-fuchsia-300 rounded bg-fuchsia-50 text-sm text-fuchsia-700">
                        Chưa có shots theo câu. Bấm "🧩 Build từ từng câu" để AI tách từng câu và tạo prompt đồng nhất.
                    </div>
                `;
                disableGrammarly(shotsList);
                return;
            }

            if (shotsMetaEl) {
                shotsMetaEl.textContent = shots.length > 1 ?
                    `Đang có ${shots.length} shot. Cuộn xuống để xem các câu tiếp theo.` :
                    'Đang có 1 shot cho short này.';
            }

            shotsList.innerHTML = shots.map(shot => {
                const shotIndex = parseInt(shot.shot_index || 0, 10) || 0;
                const refValue = Array.isArray(shot.reference_shot_indices) ? shot.reference_shot_indices.join(', ') : '';
                const videoProvider = ((shot.video_provider || 'kling').toString().toLowerCase() === 'seedance') ? 'seedance' : 'kling';
                const videoProviderLabel = shot.video_provider_label || (videoProvider === 'seedance' ? 'Seedance' : 'Kling');
                const klingStatus = (shot.kling_status || 'idle').toString().toLowerCase();
                const isKlingRunning = ['queued', 'processing', 'generating', 'submitted', 'pending'].includes(klingStatus);
                const klingBadge = klingStatus === 'completed' || klingStatus === 'succeeded' || klingStatus === 'success' ?
                    `<span class="text-[11px] bg-green-100 text-green-700 px-2 py-0.5 rounded">✨ ${videoProviderLabel} done</span>` :
                    (klingStatus === 'failed' || klingStatus === 'error' || klingStatus === 'canceled' || klingStatus === 'cancelled') ?
                    `<span class="text-[11px] bg-red-100 text-red-700 px-2 py-0.5 rounded">❌ ${videoProviderLabel} fail</span>` :
                    isKlingRunning ?
                    `<span class="text-[11px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded">⏳ ${videoProviderLabel} ${klingStatus}</span>` :
                    `<span class="text-[11px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded">🕓 ${videoProviderLabel} idle</span>`;

                return `
                    <div class="border border-fuchsia-200 rounded-lg p-3 bg-white">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" class="sw-shot-checkbox rounded" data-shot-index="${shotIndex}" checked>
                                <div class="text-sm font-semibold text-fuchsia-800">Shot ${shotIndex}</div>
                                ${shot.is_reference_keyframe ? '<span class="text-[11px] bg-purple-100 text-purple-700 px-2 py-0.5 rounded">REF</span>' : ''}
                                ${klingBadge}
                            </div>
                            <div class="text-[11px] text-gray-500">${shot.tts_duration ? `${shot.tts_duration}s` : '--'}</div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-2">
                            <div>
                                <label class="block text-[11px] text-gray-600 mb-1">Câu thoại (1 câu = 1 shot)</label>
                                <textarea class="sw-sentence w-full border border-gray-300 rounded p-2 text-xs" rows="3" data-shot-index="${shotIndex}">${escapeHtml(shot.sentence || '')}</textarea>
                            </div>
                            <div>
                                <label class="block text-[11px] text-gray-600 mb-1">Prompt ảnh (Gemini/Flux)</label>
                                <textarea class="sw-image-prompt w-full border border-cyan-300 rounded p-2 text-xs" rows="3" data-shot-index="${shotIndex}">${escapeHtml(shot.image_prompt || '')}</textarea>
                                <label class="block text-[11px] text-gray-600 mt-2 mb-1">Dịch tiếng Việt (tham khảo)</label>
                                <textarea class="w-full border border-emerald-200 bg-emerald-50 rounded p-2 text-xs text-emerald-900" rows="3" readonly>${escapeHtml(shot.image_prompt_vi || 'Chưa có bản dịch. Bấm "Build từ từng câu" để tạo lại storyboard có phần dịch.')}</textarea>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-2">
                            <div class="lg:col-span-2">
                                <label class="block text-[11px] text-gray-600 mb-1">Prompt motion video</label>
                                <textarea class="sw-kling-prompt w-full border border-rose-300 rounded p-2 text-xs" rows="2" data-shot-index="${shotIndex}">${escapeHtml(shot.kling_prompt || '')}</textarea>
                            </div>
                            <div>
                                <label class="block text-[11px] text-gray-600 mb-1">Ref shot indices (vd: 1,2)</label>
                                <input type="text" class="sw-ref-input w-full border border-purple-300 rounded p-2 text-xs" data-shot-index="${shotIndex}" value="${escapeHtml(refValue)}">
                                <label class="inline-flex items-center gap-1 mt-2 text-[11px] text-gray-600">
                                    <input type="checkbox" class="sw-keyframe rounded" data-shot-index="${shotIndex}" ${shot.is_reference_keyframe ? 'checked' : ''}>
                                    <span>Đặt làm keyframe ref</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 mb-2">
                            <button type="button" class="sw-save-btn text-xs bg-amber-100 hover:bg-amber-200 text-amber-800 px-2 py-1 rounded" data-shot-index="${shotIndex}">💾 Lưu shot</button>
                            <button type="button" class="sw-tts-btn text-xs bg-indigo-100 hover:bg-indigo-200 text-indigo-800 px-2 py-1 rounded" data-shot-index="${shotIndex}">🎙️ TTS shot</button>
                            <button type="button" class="sw-image-btn text-xs bg-teal-100 hover:bg-teal-200 text-teal-800 px-2 py-1 rounded" data-shot-index="${shotIndex}">🖼️ Ảnh shot</button>
                            <button type="button" class="sw-kling-start-btn text-xs bg-rose-100 hover:bg-rose-200 text-rose-800 px-2 py-1 rounded" data-shot-index="${shotIndex}">✨ Start Video</button>
                            <button type="button" class="sw-kling-poll-btn text-xs bg-slate-100 hover:bg-slate-200 text-slate-800 px-2 py-1 rounded" data-shot-index="${shotIndex}">🔄 Poll Video</button>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                            <div>
                                <div class="text-[11px] text-gray-500 mb-1">Audio</div>
                                ${shot.tts_audio_url ? `<audio controls class="w-full h-8" src="${shot.tts_audio_url}"></audio>` : '<div class="text-[11px] text-gray-400">Chưa có TTS</div>'}
                            </div>
                            <div>
                                <div class="text-[11px] text-gray-500 mb-1">Ảnh</div>
                                ${shot.image_url ? `<img src="${shot.image_url}" class="w-full max-h-44 object-cover rounded border" alt="shot ${shotIndex}">` : '<div class="text-[11px] text-gray-400">Chưa có ảnh</div>'}
                            </div>
                            <div>
                                <div class="text-[11px] text-gray-500 mb-1">Video AI</div>
                                ${shot.kling_video_url ? `<video controls class="w-full max-h-44 rounded border" src="${shot.kling_video_url}"></video>` : '<div class="text-[11px] text-gray-400">Chưa có video AI</div>'}
                            </div>
                        </div>

                        ${shot.error_message ? `<div class="text-[11px] text-red-600 mt-2">${escapeHtml(shot.error_message)}</div>` : ''}
                    </div>
                `;
            }).join('');

            shotsList.querySelectorAll('.sw-shot-checkbox').forEach(cb => {
                cb.addEventListener('change', syncShortWorkspaceShotSelection);
            });

            shotsList.querySelectorAll('.sw-save-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const shotIndex = parseInt(btn.dataset.shotIndex || '0', 10);
                    if (!shotIndex) return;
                    saveShortWorkspaceShot(shotIndex);
                });
            });

            shotsList.querySelectorAll('.sw-tts-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const shotIndex = parseInt(btn.dataset.shotIndex || '0', 10);
                    if (!shotIndex) return;
                    runShortWorkspaceAction('tts', [shotIndex]);
                });
            });

            shotsList.querySelectorAll('.sw-image-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const shotIndex = parseInt(btn.dataset.shotIndex || '0', 10);
                    if (!shotIndex) return;
                    runShortWorkspaceAction('images', [shotIndex]);
                });
            });

            shotsList.querySelectorAll('.sw-kling-start-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const shotIndex = parseInt(btn.dataset.shotIndex || '0', 10);
                    if (!shotIndex) return;
                    runShortWorkspaceAction('kling-start', [shotIndex]);
                });
            });

            shotsList.querySelectorAll('.sw-kling-poll-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const shotIndex = parseInt(btn.dataset.shotIndex || '0', 10);
                    if (!shotIndex) return;
                    runShortWorkspaceAction('kling-poll', [shotIndex]);
                });
            });

            disableGrammarly(shotsList);
            syncShortWorkspaceShotSelection();
        }

        async function saveShortWorkspaceShot(shotIndex) {
            if (!activeShortWorkspaceIndex) return;

            const sentenceEl = document.querySelector(`.sw-sentence[data-shot-index="${shotIndex}"]`);
            const imagePromptEl = document.querySelector(`.sw-image-prompt[data-shot-index="${shotIndex}"]`);
            const klingPromptEl = document.querySelector(`.sw-kling-prompt[data-shot-index="${shotIndex}"]`);
            const refEl = document.querySelector(`.sw-ref-input[data-shot-index="${shotIndex}"]`);
            const keyframeEl = document.querySelector(`.sw-keyframe[data-shot-index="${shotIndex}"]`);

            if (!sentenceEl || !imagePromptEl || !klingPromptEl || !refEl || !keyframeEl) return;

            const payload = {
                sentence: sentenceEl.value,
                image_prompt: imagePromptEl.value,
                kling_prompt: klingPromptEl.value,
                reference_shot_indices: parseShortWorkspaceRefIndices(refEl.value, shotIndex),
                is_reference_keyframe: !!keyframeEl.checked,
            };

            setShortWorkspaceStatus(`⏳ Đang lưu shot #${shotIndex}...`, 'info');
            try {
                const resp = await fetch(
                    `/audiobooks/${audioBookId}/short-videos/${activeShortWorkspaceIndex}/workspace/shots/${shotIndex}`, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    });

                const data = await safeJson(resp);
                if (!data.success || !data.item) {
                    throw new Error(data.error || 'Lưu shot thất bại.');
                }

                activeShortWorkspaceItem = data.item;
                mergeWorkspaceItemIntoShortList(data.item);
                renderShortWorkspace(activeShortWorkspaceItem);
                setShortWorkspaceStatus(`✅ Đã lưu shot #${shotIndex}`, 'success');
            } catch (error) {
                setShortWorkspaceStatus(`❌ ${error.message}`, 'error');
            }
        }

        async function runShortWorkspaceAction(action, forcedShotIndices = null, isAutoPolling = false) {
            if (!activeShortWorkspaceIndex) {
                setShortWorkspaceStatus('❌ Chưa chọn short.', 'error');
                return null;
            }

            const selected = Array.isArray(forcedShotIndices) && forcedShotIndices.length > 0 ?
                forcedShotIndices :
                getShortWorkspaceSelectedShotIndices();
            const selectedVideoProvider = ((document.getElementById('shortWorkspaceVideoProvider')?.value || 'kling').toLowerCase() === 'seedance') ? 'seedance' : 'kling';
            const selectedVideoProviderLabel = selectedVideoProvider === 'seedance' ? 'Seedance' : 'Kling';

            const baseUrl = `/audiobooks/${audioBookId}/short-videos/${activeShortWorkspaceIndex}/workspace`;
            let endpoint = '';
            let method = 'POST';
            let payload = {};
            let loadingMessage = '⏳ Đang xử lý...';

            if (action === 'build') {
                endpoint = `${baseUrl}/build`;
                payload = {
                    force: !!(activeShortWorkspaceItem && Array.isArray(activeShortWorkspaceItem.shots) &&
                        activeShortWorkspaceItem.shots.length > 0)
                };
                loadingMessage = '⏳ AI đang build storyboard theo từng câu...';
            } else if (action === 'tts') {
                if (selected.length === 0) {
                    setShortWorkspaceStatus('❌ Chưa chọn shot để tạo TTS.', 'error');
                    return null;
                }
                endpoint = `${baseUrl}/generate-shot-tts`;
                payload = {
                    selected_shot_indices: selected,
                    ...getShortWorkspaceTtsPayload()
                };
                loadingMessage = '⏳ Đang tạo TTS theo câu...';
            } else if (action === 'images') {
                if (selected.length === 0) {
                    setShortWorkspaceStatus('❌ Chưa chọn shot để tạo ảnh.', 'error');
                    return null;
                }
                const imageProvider = document.getElementById('shortWorkspaceImageProvider')?.value || 'gemini';
                const imageProviderName = imageProvider === 'flux' ? 'Flux' : 'Gemini';
                endpoint = `${baseUrl}/generate-shot-images`;
                payload = {
                    selected_shot_indices: selected,
                    image_provider: imageProvider,
                };
                loadingMessage = `⏳ Đang tạo ảnh ${imageProviderName} theo từng câu...`;
            } else if (action === 'kling-start') {
                if (selected.length === 0) {
                    setShortWorkspaceStatus('❌ Chưa chọn shot để tạo video.', 'error');
                    return null;
                }
                endpoint = `${baseUrl}/kling/start`;
                payload = {
                    selected_shot_indices: selected,
                    duration: document.getElementById('shortWorkspaceKlingDuration')?.value || '5',
                    video_provider: selectedVideoProvider,
                };
                loadingMessage = `⏳ Đang tạo task ${selectedVideoProviderLabel} cho các shot...`;
            } else if (action === 'kling-poll') {
                if (selected.length === 0) {
                    setShortWorkspaceStatus('❌ Chưa chọn shot để poll video.', 'error');
                    return null;
                }
                endpoint = `${baseUrl}/kling/poll`;
                payload = {
                    selected_shot_indices: selected,
                    video_provider: selectedVideoProvider,
                };
                loadingMessage = `⏳ Đang kiểm tra trạng thái ${selectedVideoProviderLabel}...`;
            } else if (action === 'compose') {
                if (selected.length === 0) {
                    setShortWorkspaceStatus('❌ Chưa chọn shot để auto ghép.', 'error');
                    return null;
                }
                endpoint = `${baseUrl}/compose-auto`;
                payload = {
                    selected_shot_indices: selected
                };
                loadingMessage = '⏳ Đang auto ghép video theo thời lượng TTS...';
            } else if (action === 'download') {
                endpoint = `${baseUrl}/download-package`;
                payload = {};
                loadingMessage = '⏳ Đang đóng gói tài nguyên để tải...';
            }

            if (!(isAutoPolling && action === 'kling-poll')) {
                setShortWorkspaceStatus(loadingMessage, 'info');
            }

            try {
                if (action === 'download') {
                    const preflightResp = await fetch(endpoint, {
                        method,
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            ...payload,
                            dry_run: true
                        })
                    });

                    const preflightData = await safeJson(preflightResp);
                    if (!preflightResp.ok || !preflightData.success) {
                        throw new Error(preflightData.error || `HTTP ${preflightResp.status}`);
                    }

                    let allowMissing = false;
                    const missingCount = Number(preflightData.missing_count || 0);
                    const missingFiles = Array.isArray(preflightData.missing_files) ? preflightData.missing_files : [];

                    if (missingCount > 0) {
                        const previewRows = missingFiles.slice(0, 10).map(item => {
                            const shotNo = item.shot_index || '?';
                            const types = Array.isArray(item.missing_types) ? item.missing_types.join(', ') : 'audio/image/video';
                            return `- Shot ${shotNo}: thiếu ${types}`;
                        });
                        const moreLine = missingFiles.length > 10 ? `\n... và ${missingFiles.length - 10} shot khác.` : '';
                        const warningMessage = `⚠️ Phát hiện ${missingCount} shot thiếu file trước khi tải package:\n\n${previewRows.join('\n')}${moreLine}\n\nBạn vẫn muốn tải package với các file hiện có?`;

                        if (!confirm(warningMessage)) {
                            setShortWorkspaceStatus('⚠️ Đã huỷ tải package do còn thiếu file.', 'error');
                            return {
                                success: false,
                                cancelled: true
                            };
                        }

                        allowMissing = true;
                    }

                    const resp = await fetch(endpoint, {
                        method,
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            ...payload,
                            allow_missing: allowMissing
                        })
                    });

                    if (!resp.ok) {
                        let message = `HTTP ${resp.status}`;
                        try {
                            const err = await resp.json();
                            message = err.error || err.message || message;
                        } catch (_) {}
                        throw new Error(message);
                    }

                    const blob = await resp.blob();
                    const contentDisposition = resp.headers.get('Content-Disposition') || '';
                    const match = contentDisposition.match(/filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i);
                    const fileName = decodeURIComponent((match && (match[1] || match[2])) ? (match[1] || match[2]) :
                        `short_${activeShortWorkspaceIndex}_workspace.zip`);

                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = fileName;
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    URL.revokeObjectURL(url);

                    if (allowMissing) {
                        setShortWorkspaceStatus('✅ Đã tải package (đã cảnh báo thiếu file trước khi tải).', 'success');
                    } else {
                        setShortWorkspaceStatus('✅ Đã tải package để ghép thủ công (CapCut).', 'success');
                    }
                    return {
                        success: true
                    };
                }

                const resp = await fetch(endpoint, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                const data = await safeJson(resp);
                if (!data.success) {
                    throw new Error(data.error || 'Xử lý thất bại.');
                }

                if (data.item) {
                    activeShortWorkspaceItem = data.item;
                    mergeWorkspaceItemIntoShortList(data.item);
                    renderShortWorkspace(activeShortWorkspaceItem);
                } else if (activeShortWorkspaceIndex) {
                    await loadShortWorkspace(activeShortWorkspaceIndex);
                    if (activeShortWorkspaceItem) mergeWorkspaceItemIntoShortList(activeShortWorkspaceItem);
                }

                if (action === 'build') {
                    setShortWorkspaceStatus('✅ Đã build storyboard theo từng câu.', 'success');
                } else if (action === 'tts') {
                    setShortWorkspaceStatus(`✅ TTS xong: ${data.completed || 0} thành công, ${data.failed || 0} lỗi.`,
                        'success');
                } else if (action === 'images') {
                    setShortWorkspaceStatus(`✅ Ảnh xong: ${data.completed || 0} thành công, ${data.failed || 0} lỗi.`,
                        'success');
                } else if (action === 'kling-start') {
                    const queuedCount = data.queued || 0;
                    const failedCount = data.failed || 0;
                    const providerLabel = data.video_provider_label || selectedVideoProviderLabel;
                    if (queuedCount > 0) {
                        setShortWorkspaceStatus(
                            `✅ ${providerLabel} queued: ${queuedCount} shot, lỗi ${failedCount}. Hệ thống đang tự động poll kết quả...`,
                            'success'
                        );
                        startShortWorkspaceKlingAutoPoll(selected);
                    } else {
                        setShortWorkspaceStatus(
                            `⚠️ Không có shot nào được queue ${providerLabel} (lỗi ${failedCount}). Kiểm tra ảnh từng shot rồi thử lại.`,
                            'error'
                        );
                    }
                } else if (action === 'kling-poll') {
                    if (!isAutoPolling) {
                        const providerLabel = data.video_provider_label || selectedVideoProviderLabel;
                        setShortWorkspaceStatus(
                            `✅ ${providerLabel} poll: done ${data.completed || 0}, processing ${data.processing || 0}, fail ${data.failed || 0}.`,
                            'success'
                        );
                    }
                } else if (action === 'compose') {
                    setShortWorkspaceStatus('✅ Đã auto ghép video theo từng câu TTS.', 'success');
                }

                return data;
            } catch (error) {
                if (!(isAutoPolling && action === 'kling-poll')) {
                    setShortWorkspaceStatus(`❌ ${error.message}`, 'error');
                }
                return null;
            }
        }

         function renderShortVideos(items) {
             const list = document.getElementById('shortVideoList');
             if (!list) return;

             const validIndexes = new Set((items || []).map(item => parseInt(item.index, 10)).filter(Number.isFinite));
             selectedShortIndexes = new Set(Array.from(selectedShortIndexes).filter(i => validIndexes.has(i)));

             if (!items || items.length === 0) {
                 list.innerHTML = `
                    <div class="text-center py-8 text-gray-400">
                        <span class="text-3xl">📱</span>
                        <p class="text-sm mt-2">Chưa có short video nào</p>
                    </div>
                `;
                 disableGrammarly(list);
                 syncShortSelectionToolbar();
                 return;
             }

             list.innerHTML = items.map(item => {
                 const status = item.status || 'planned';
                 const itemIndex = parseInt(item.index, 10);
                 const checked = selectedShortIndexes.has(itemIndex) ? 'checked' : '';
                 const statusBadge = status === 'completed' ?
                     '<span class="text-[11px] bg-green-100 text-green-700 px-2 py-0.5 rounded">✅ Hoàn tất</span>' :
                     status === 'processing' ?
                     '<span class="text-[11px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded">⏳ Đang xử lý</span>' :
                     status === 'error' ?
                     '<span class="text-[11px] bg-red-100 text-red-700 px-2 py-0.5 rounded">❌ Lỗi</span>' :
                     '<span class="text-[11px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded">📝 Planned</span>';

                 const duration = item.duration ? `${item.duration}s` : '--';

                 return `
                    <div class="p-3 bg-white border border-fuchsia-200 rounded-lg">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <div class="min-w-0 flex items-start gap-2">
                                <input type="checkbox" class="short-video-checkbox rounded mt-0.5" data-index="${itemIndex}" ${checked}>
                                <div>
                                    <div class="text-sm font-semibold text-fuchsia-800 truncate">#${item.index || ''} ${item.title || 'Short video'}</div>
                                    <div class="text-[11px] text-gray-500">Phong cách: ${item.style || 'N/A'} • ${duration}</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                ${statusBadge}
                                <button type="button" class="delete-short-video-btn text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1 rounded" data-index="${itemIndex}">🗑️ Xóa</button>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="block text-[11px] text-gray-500 mb-1">Nội dung đọc (có thể sửa trước khi tạo TTS)</label>
                            <textarea class="short-script-editor w-full border border-fuchsia-200 rounded p-2 text-xs text-gray-700" rows="4" data-index="${itemIndex}" data-gramm="false" data-gramm_editor="false" data-enable-grammarly="false" spellcheck="false">${escapeHtml(item.script || '')}</textarea>
                        </div>
                        <div class="mb-2">
                            <label class="block text-[11px] text-gray-500 mb-1">Prompt tạo ảnh (dùng khi bấm "Tạo ảnh đã chọn")</label>
                            <textarea class="short-image-prompt-editor w-full border border-cyan-200 rounded p-2 text-xs text-gray-700" rows="3" data-index="${itemIndex}" data-gramm="false" data-gramm_editor="false" data-enable-grammarly="false" spellcheck="false">${escapeHtml(item.image_prompt || '')}</textarea>
                            <div class="mt-1 flex justify-between items-center gap-2">
                                <button type="button" class="generate-short-image-prompt-btn text-xs bg-cyan-100 hover:bg-cyan-200 text-cyan-800 px-2 py-1 rounded" data-index="${itemIndex}">✨ Tạo prompt ảnh bằng ChatGPT</button>
                                <button type="button" class="save-short-script-btn text-xs bg-amber-100 hover:bg-amber-200 text-amber-800 px-2 py-1 rounded" data-index="${itemIndex}">💾 Lưu nội dung + prompt</button>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 mb-2">
                            ${item.image_url ? `<img src="${item.image_url}" class="w-14 h-24 object-cover rounded border" alt="short image">` : '<div class="w-14 h-24 border rounded bg-gray-50 flex items-center justify-center text-[10px] text-gray-400">No image</div>'}
                            <div class="flex-1 min-w-0">
                                ${item.video_url ? `<video controls class="w-full max-h-36 rounded border" src="${item.video_url}"></video>` : '<div class="text-[11px] text-gray-400">Chưa có video</div>'}
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <button type="button" class="open-short-workspace-btn text-xs bg-fuchsia-100 hover:bg-fuchsia-200 text-fuchsia-800 px-2 py-1 rounded" data-index="${itemIndex}">🎬 Studio câu</button>
                            ${item.video_url ? `<a href="${item.video_url}" download class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded">⬇️ Tải video</a>` : ''}
                            ${item.audio_url ? `<a href="${item.audio_url}" download class="text-xs bg-purple-100 hover:bg-purple-200 text-purple-700 px-2 py-1 rounded">🎵 Tải audio</a>` : ''}
                        </div>
                        ${item.error_message ? `<div class="text-[11px] text-red-600 mt-2">${item.error_message}</div>` : ''}
                    </div>
                `;
             }).join('');

             list.querySelectorAll('.short-video-checkbox').forEach(checkbox => {
                 checkbox.addEventListener('change', function() {
                     const index = parseInt(this.dataset.index || '0', 10);
                     if (!index) return;
                     if (this.checked) {
                         selectedShortIndexes.add(index);
                     } else {
                         selectedShortIndexes.delete(index);
                     }
                     syncShortSelectionToolbar();
                 });
             });

             list.querySelectorAll('.delete-short-video-btn').forEach(button => {
                 button.addEventListener('click', function() {
                     const index = parseInt(this.dataset.index || '0', 10);
                     if (!index) return;
                     deleteShortVideoItem(index);
                 });
             });

             list.querySelectorAll('.save-short-script-btn').forEach(button => {
                 button.addEventListener('click', function() {
                     const index = parseInt(this.dataset.index || '0', 10);
                     if (!index) return;
                     const scriptEditor = list.querySelector(`.short-script-editor[data-index="${index}"]`);
                     const promptEditor = list.querySelector(
                         `.short-image-prompt-editor[data-index="${index}"]`);
                     if (!scriptEditor || !promptEditor) return;
                     saveShortVideoContent(index, scriptEditor.value, promptEditor.value);
                 });
             });

             list.querySelectorAll('.generate-short-image-prompt-btn').forEach(button => {
                 button.addEventListener('click', function() {
                     const index = parseInt(this.dataset.index || '0', 10);
                     if (!index) return;
                     generateShortImagePromptByAi(index);
                 });
             });

            list.querySelectorAll('.open-short-workspace-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const index = parseInt(this.dataset.index || '0', 10);
                    if (!index) return;
                    openShortWorkspaceModal(index);
                });
            });

             disableGrammarly(list);
             syncShortSelectionToolbar();
         }

         async function loadShortVideos() {
             try {
                 const resp = await fetch(`/audiobooks/${audioBookId}/short-videos`);
                 const data = await safeJson(resp);
                 shortVideoItems = data.items || [];
                 renderShortVideos(shortVideoItems);
             } catch (error) {
                 setShortVideoStatus(`❌ ${error.message}`, 'error');
             }
         }

         function setupShortVideoTab() {
             const btnRefresh = document.getElementById('refreshShortVideosBtn');
             const btnPlans = document.getElementById('generateShortPlansBtn');
             const btnManual = document.getElementById('createManualShortBtn');
             const btnAssets = document.getElementById('generateShortAssetsBtn');
             const btnSelectedTts = document.getElementById('generateSelectedShortTtsBtn');
             const btnSelectedImages = document.getElementById('generateSelectedShortImagesBtn');
             const btnDownloadSelected = document.getElementById('downloadSelectedShortBtn');
             const btnDeleteSelected = document.getElementById('deleteSelectedShortBtn');
             const selectAll = document.getElementById('selectAllShortVideos');
             const countInput = document.getElementById('shortVideoCount');
             const providerSelect = document.getElementById('shortVideoProvider');
             const imageProviderSelect = document.getElementById('shortImageProvider');
             const manualTitleInput = document.getElementById('manualShortTitle');
             const manualStyleInput = document.getElementById('manualShortStyle');
             const manualPromptInput = document.getElementById('manualShortImagePrompt');
             const manualScriptInput = document.getElementById('manualShortScript');
             const ttsProvider = document.getElementById('ttsProviderSelect');
            const workspaceModal = document.getElementById('shortWorkspaceModal');
            const closeWorkspaceBtn = document.getElementById('closeShortWorkspaceModalBtn');
            const workspaceSelectAllShots = document.getElementById('shortWorkspaceSelectAllShots');
            const workspaceBuildBtn = document.getElementById('shortWorkspaceBuildBtn');
            const workspaceGenerateTtsBtn = document.getElementById('shortWorkspaceGenerateTtsBtn');
            const workspaceGenerateImagesBtn = document.getElementById('shortWorkspaceGenerateImagesBtn');
            const workspaceStartKlingBtn = document.getElementById('shortWorkspaceStartKlingBtn');
            const workspacePollKlingBtn = document.getElementById('shortWorkspacePollKlingBtn');
            const workspaceComposeBtn = document.getElementById('shortWorkspaceComposeBtn');
            const workspaceDownloadBtn = document.getElementById('shortWorkspaceDownloadBtn');

             if (providerSelect && ttsProvider && ttsProvider.value) {
                 providerSelect.value = ttsProvider.value;
             }

             if (btnRefresh) {
                 btnRefresh.addEventListener('click', loadShortVideos);
             }

             if (selectAll) {
                 selectAll.addEventListener('change', function() {
                     if (this.checked) {
                         selectedShortIndexes = new Set(shortVideoItems.map(item => parseInt(item.index, 10)).filter(
                             Number.isFinite));
                     } else {
                         selectedShortIndexes.clear();
                     }
                     renderShortVideos(shortVideoItems);
                 });
             }

             if (btnSelectedTts) {
                 btnSelectedTts.addEventListener('click', () => runSelectedShortAction('tts'));
             }

             if (btnSelectedImages) {
                 btnSelectedImages.addEventListener('click', () => runSelectedShortAction('images'));
             }

             if (btnDeleteSelected) {
                 btnDeleteSelected.addEventListener('click', deleteSelectedShortVideos);
             }

             if (btnDownloadSelected) {
                 btnDownloadSelected.addEventListener('click', downloadSelectedShortVideos);
             }

             if (btnPlans) {
                 btnPlans.addEventListener('click', async function() {
                     const count = parseInt(countInput?.value || '5', 10);
                     btnPlans.disabled = true;
                     setShortVideoStatus('⏳ AI đang tạo kế hoạch short...', 'info');
                     try {
                         const resp = await fetch(`/audiobooks/${audioBookId}/short-videos/generate-plans`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': getCsrfToken()
                             },
                             body: JSON.stringify({
                                 count
                             })
                         });
                         const data = await safeJson(resp);
                         shortVideoItems = data.items || [];
                         selectedShortIndexes.clear();
                         renderShortVideos(shortVideoItems);
                         setShortVideoStatus(`✅ Đã tạo ${data.count || shortVideoItems.length} short plans`,
                             'success');
                     } catch (error) {
                         setShortVideoStatus(`❌ ${error.message}`, 'error');
                     } finally {
                         btnPlans.disabled = false;
                     }
                 });
             }

             if (btnManual) {
                 btnManual.addEventListener('click', async function() {
                     const script = (manualScriptInput?.value || '').trim();
                     const title = (manualTitleInput?.value || '').trim();
                     const style = (manualStyleInput?.value || '').trim();
                     const imagePrompt = (manualPromptInput?.value || '').trim();

                     if (script.length < 10) {
                         setShortVideoStatus('❌ Script short thủ công phải từ 10 ký tự trở lên.', 'error');
                         return;
                     }

                     btnManual.disabled = true;
                     setShortVideoStatus('⏳ Đang tạo short thủ công...', 'info');
                     try {
                         const resp = await fetch(`/audiobooks/${audioBookId}/short-videos/manual`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': getCsrfToken(),
                                 'Accept': 'application/json'
                             },
                             body: JSON.stringify({
                                 title,
                                 style,
                                 script,
                                 image_prompt: imagePrompt
                             })
                         });

                         const data = await safeJson(resp);
                         shortVideoItems = data.items || shortVideoItems;
                         renderShortVideos(shortVideoItems);

                         if (manualTitleInput) manualTitleInput.value = '';
                         if (manualPromptInput) manualPromptInput.value = '';
                         if (manualScriptInput) manualScriptInput.value = '';

                         setShortVideoStatus(`✅ Đã thêm short thủ công #${data.created_index || '?'}.`, 'success');
                     } catch (error) {
                         setShortVideoStatus(`❌ ${error.message}`, 'error');
                     } finally {
                         btnManual.disabled = false;
                     }
                 });
             }

             if (btnAssets) {
                 btnAssets.addEventListener('click', async function() {
                     if (!confirm('Tạo TTS + ảnh + video 9:16 cho tất cả short hiện tại?')) return;
                     btnAssets.disabled = true;
                     setShortVideoStatus('⏳ Đang tạo assets short videos... Có thể mất vài phút.', 'info');
                     try {
                         const resp = await fetch(`/audiobooks/${audioBookId}/short-videos/generate-assets`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': getCsrfToken()
                             },
                             body: JSON.stringify({
                                 provider: providerSelect?.value || 'openai',
                                 image_provider: imageProviderSelect?.value || 'gemini'
                             })
                         });
                         const data = await safeJson(resp);
                         shortVideoItems = data.items || [];
                         renderShortVideos(shortVideoItems);
                         setShortVideoStatus(
                             `✅ Hoàn tất: ${data.completed || 0} thành công, ${data.failed || 0} lỗi`,
                             'success');
                     } catch (error) {
                         setShortVideoStatus(`❌ ${error.message}`, 'error');
                     } finally {
                         btnAssets.disabled = false;
                     }
                 });
             }

            if (closeWorkspaceBtn) {
                closeWorkspaceBtn.addEventListener('click', closeShortWorkspaceModal);
            }

            // Modal chỉ đóng khi nhấn nút close, không đóng khi click backdrop

            if (workspaceSelectAllShots) {
                workspaceSelectAllShots.addEventListener('change', function() {
                    document.querySelectorAll('.sw-shot-checkbox').forEach(cb => {
                        cb.checked = workspaceSelectAllShots.checked;
                    });
                    syncShortWorkspaceShotSelection();
                });
            }

            if (workspaceBuildBtn) {
                workspaceBuildBtn.addEventListener('click', () => runShortWorkspaceAction('build'));
            }

            if (workspaceGenerateTtsBtn) {
                workspaceGenerateTtsBtn.addEventListener('click', () => runShortWorkspaceAction('tts'));
            }

            if (workspaceGenerateImagesBtn) {
                workspaceGenerateImagesBtn.addEventListener('click', () => runShortWorkspaceAction('images'));
            }

            if (workspaceStartKlingBtn) {
                workspaceStartKlingBtn.addEventListener('click', () => runShortWorkspaceAction('kling-start'));
            }

            if (workspacePollKlingBtn) {
                workspacePollKlingBtn.addEventListener('click', () => runShortWorkspaceAction('kling-poll'));
            }

            if (workspaceComposeBtn) {
                workspaceComposeBtn.addEventListener('click', () => runShortWorkspaceAction('compose'));
            }

            if (workspaceDownloadBtn) {
                workspaceDownloadBtn.addEventListener('click', () => runShortWorkspaceAction('download'));
            }

             syncShortSelectionToolbar();
         }

         // ========== YOUTUBE MEDIA FUNCTIONS ==========

         // Toggle sections based on "Use Cover Image" option
         document.getElementById('useCoverImageOption')?.addEventListener('change', function() {
             const useCover = this.checked;
             const customPromptSection = document.getElementById('customPromptSection');
             const aiResearchSection = document.getElementById('aiResearchSection');
             const styleSection = document.querySelector('input[name="thumbnailStyle"]')?.closest('div')
                 ?.parentElement;

             if (useCover) {
                 // Keep style & custom prompt visible (AI uses description for background)
                 // Only hide AI research (not needed when using cover)
                 if (aiResearchSection) aiResearchSection.style.display = 'none';
                 const aiResearchCheckbox = document.getElementById('aiResearchOption');
                 if (aiResearchCheckbox) aiResearchCheckbox.checked = false;
             } else {
                 // Show AI generation options
                 if (customPromptSection) customPromptSection.style.display = '';
                 if (aiResearchSection) aiResearchSection.style.display = '';
                 if (styleSection) styleSection.style.display = '';
             }
         });

         // Thumbnail polling helper
         let thumbnailPollTimer = null;

         function startThumbnailPolling(statusDiv, btn, btnOriginalText, successCallback) {
             if (thumbnailPollTimer) clearInterval(thumbnailPollTimer);

             thumbnailPollTimer = setInterval(async () => {
                 try {
                     const resp = await fetch(`/audiobooks/${audioBookId}/media/thumbnail-progress`, {
                         headers: {
                             'Accept': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         }
                     });
                     const data = await resp.json();

                     if (data.status === 'processing') {
                         statusDiv.innerHTML =
                             `<span class="text-blue-600">⏳ ${data.message || 'Đang xử lý...'}</span>`;
                     } else if (data.status === 'completed') {
                         clearInterval(thumbnailPollTimer);
                         thumbnailPollTimer = null;
                         btn.disabled = false;
                         btn.innerHTML = btnOriginalText;
                         successCallback(data.result || {});
                     } else if (data.status === 'error') {
                         clearInterval(thumbnailPollTimer);
                         thumbnailPollTimer = null;
                         btn.disabled = false;
                         btn.innerHTML = btnOriginalText;
                         statusDiv.innerHTML =
                             `<span class="text-red-600">❌ ${data.message || 'Lỗi không xác định'}</span>`;
                     }
                 } catch (e) {
                     // Polling error, keep trying
                 }
             }, 2000);
         }

         // === Thumbnail prompt preview + generate logic ===
         let pendingThumbnailRequest = null; // stores the request body for "Tạo với prompt này"

         async function fetchAndShowThumbnailPrompt(withText) {
             const style = document.querySelector('input[name="thumbnailStyle"]:checked')?.value || 'cinematic';
             const customPrompt = document.getElementById('thumbnailCustomPrompt')?.value.trim();
             const customTitle = document.getElementById('thumbnailTitle')?.value.trim();
             const customAuthor = document.getElementById('thumbnailAuthor')?.value.trim();
             const chapterNumber = document.getElementById('thumbnailChapterNumber')?.value || null;
             const statusDiv = document.getElementById('thumbnailStatus');
             const promptArea = document.getElementById('thumbnailPromptArea');
             const promptTextarea = document.getElementById('thumbnailPromptTextarea');

             if (withText && !customTitle) {
                 statusDiv.innerHTML = '<span class="text-red-600">❌ Vui lòng nhập tiêu đề sách!</span>';
                 return;
             }

             statusDiv.innerHTML = '<span class="text-blue-600">⏳ Đang tạo prompt...</span>';

             try {
                 const previewBody = {
                     style,
                     with_text: withText,
                     prefer_portrait: document.getElementById('preferPortraitOption')?.checked !== false
                 };
                 if (customPrompt) previewBody.custom_prompt = customPrompt;
                 if (customTitle) previewBody.custom_title = customTitle;
                 if (customAuthor) previewBody.custom_author = customAuthor;
                 if (chapterNumber) previewBody.chapter_number = parseInt(chapterNumber);

                 const resp = await fetch(`/audiobooks/${audioBookId}/media/preview-thumbnail-prompt`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify(previewBody)
                 });
                 const data = await safeJson(resp);

                 if (data.success && data.prompt) {
                     promptTextarea.value = data.prompt;
                     promptArea.classList.remove('hidden');
                     statusDiv.innerHTML =
                         '<span class="text-gray-600">📝 Xem và chỉnh sửa prompt bên dưới, sau đó nhấn "Tạo với prompt này"</span>';

                     // Store request params for the generate button
                     const aiResearch = document.getElementById('aiResearchOption')?.checked || false;
                     const useCoverImage = document.getElementById('useCoverImageOption')?.checked || false;
                     pendingThumbnailRequest = {
                         style,
                         with_text: withText,
                         ai_research: aiResearch,
                         use_cover_image: withText ? false : useCoverImage,
                         prefer_portrait: document.getElementById('preferPortraitOption')?.checked !== false,
                     };
                     if (customTitle) pendingThumbnailRequest.custom_title = customTitle;
                     if (customAuthor) pendingThumbnailRequest.custom_author = customAuthor;
                     if (chapterNumber) pendingThumbnailRequest.chapter_number = parseInt(chapterNumber);
                     if (customPrompt) pendingThumbnailRequest.custom_prompt = customPrompt;
                 }
             } catch (error) {
                 statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
             }
         }

         async function executeThumbnailGenerate() {
             if (!pendingThumbnailRequest) return;
             const statusDiv = document.getElementById('thumbnailStatus');
             const promptArea = document.getElementById('thumbnailPromptArea');
             const promptTextarea = document.getElementById('thumbnailPromptTextarea');
             const generateBtn = document.getElementById('thumbnailPromptGenerateBtn');
             const withText = pendingThumbnailRequest.with_text;
             const btnOriginalText = withText ? '✨ Tạo Thumbnail (AI Vẽ Chữ Luôn)' : '🖼️ Tạo Hình Nền (Không chữ)';
             const originalBtn = withText ?
                 document.getElementById('generateThumbnailWithTextBtn') :
                 document.getElementById('generateThumbnailBtn');

             generateBtn.disabled = true;
             generateBtn.innerHTML = '⏳ Đang gửi...';

             const requestBody = {
                 ...pendingThumbnailRequest,
                 override_prompt: promptTextarea.value,
                 image_provider: document.getElementById('thumbnailImageProvider')?.value || 'gemini'
             };

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/media/generate-thumbnail`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify(requestBody)
                 });
                 const result = await safeJson(response);

                 if (result.queued) {
                     promptArea.classList.add('hidden');
                     if (originalBtn) {
                         originalBtn.disabled = true;
                         originalBtn.innerHTML = '⏳ Đang xử lý...';
                     }

                     const successCb = withText ?
                         function(jobResult) {
                             let msg = '<span class="text-green-600">✅ Đã tạo thumbnail!</span>';
                             if (jobResult.ai_text) {
                                 msg +=
                                     '<br><span class="text-xs text-indigo-600">🎨 AI đã cố gắng vẽ chữ vào hình</span>';
                                 msg +=
                                     '<br><span class="text-xs text-orange-600">⚠️ Nếu chữ không đẹp/sai, hãy dùng phương pháp FFmpeg thêm chữ</span>';
                             }
                             statusDiv.innerHTML = msg;
                             refreshMediaGallery();
                         } :
                         function() {
                             statusDiv.innerHTML =
                                 '<span class="text-green-600">✅ Đã tạo hình nền thành công!</span><br><span class="text-xs text-indigo-600">👆 Chọn hình từ gallery bên dưới và nhấn "✏️ Thêm Text" để thêm chữ</span>';
                             refreshMediaGallery();
                         };

                     startThumbnailPolling(statusDiv, originalBtn || generateBtn, btnOriginalText, successCb);
                 } else {
                     throw new Error(result.error || 'Không thể tạo');
                 }
             } catch (error) {
                 statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                 generateBtn.disabled = false;
                 generateBtn.innerHTML = '🚀 Tạo với prompt này';
             }
         }

         // Generate Background Image (no text) - shows prompt preview first
         document.getElementById('generateThumbnailBtn')?.addEventListener('click', function() {
             fetchAndShowThumbnailPrompt(false);
         });

         // Generate Thumbnail WITH Text - shows prompt preview first
         document.getElementById('generateThumbnailWithTextBtn')?.addEventListener('click', function() {
             fetchAndShowThumbnailPrompt(true);
         });

         // "Tạo với prompt này" button
         document.getElementById('thumbnailPromptGenerateBtn')?.addEventListener('click', function() {
             executeThumbnailGenerate();
         });

         // ========== SCENE GENERATION - 2-STEP FLOW ==========

         // Lưu trữ kết quả phân tích scenes
         var analyzedScenes = [];

         // Bước 1: AI Phân tích nội dung → tạo prompts
         document.getElementById('analyzeSceneBtn')?.addEventListener('click', async function() {
             var btn = this;
             var style = document.getElementById('sceneStyle')?.value || 'cinematic';
             var statusDiv = document.getElementById('scenesStatus');
             var resultsDiv = document.getElementById('sceneAnalysisResults');
             var promptsList = document.getElementById('scenePromptsList');
             var countSpan = document.getElementById('sceneAnalysisCount');

             btn.disabled = true;
             btn.innerHTML = '🤖 Đang phân tích...';
             statusDiv.innerHTML =
                 '<span class="text-blue-600">🧠 AI đang đọc và phân tích nội dung giới thiệu sách...</span>';
             resultsDiv.classList.add('hidden');

             try {
                 var response = await fetch('/audiobooks/' + audioBookId + '/media/analyze-scenes', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         style: style
                     })
                 });

                 var result = await safeJson(response);

                 if (result.success && result.scenes && result.scenes.length > 0) {
                     analyzedScenes = result.scenes;
                     countSpan.textContent = result.total + ' phân cảnh';
                     statusDiv.innerHTML = '<span class="text-green-600">✅ Bước 1 hoàn tất! AI đã phân tích ' +
                         result.total + ' phân cảnh. Xem bên dưới và nhấn Bước 2 để tạo ảnh.</span>';

                     // Render danh sách scenes + prompts
                     promptsList.innerHTML = '';
                     result.scenes.forEach(function(scene, idx) {
                         var card = document.createElement('div');
                         card.className = 'p-3 bg-white border border-gray-200 rounded-lg';

                         var header = document.createElement('div');
                         header.className = 'flex items-center justify-between mb-2';
                         header.innerHTML = '<span class="text-sm font-semibold text-blue-800">🎬 ' +
                             scene.scene_number + '. ' + scene.title + '</span>' +
                             '<button type="button" class="text-xs bg-green-100 hover:bg-green-200 text-green-700 px-2 py-1 rounded generate-single-scene-btn" data-index="' +
                             idx + '">🎨 Tạo ảnh</button>';
                         card.appendChild(header);

                         if (scene.description) {
                             var desc = document.createElement('p');
                             desc.className = 'text-xs text-gray-600 mb-2';
                             desc.textContent = scene.description;
                             card.appendChild(desc);
                         }

                         var promptLabel = document.createElement('label');
                         promptLabel.className = 'block text-xs font-medium text-gray-500 mb-1';
                         promptLabel.textContent = 'Prompt (có thể sửa):';
                         card.appendChild(promptLabel);

                         var promptInput = document.createElement('textarea');
                         promptInput.className =
                             'w-full px-2 py-1 border border-gray-300 rounded text-xs focus:border-blue-500 focus:outline-none scene-prompt-input';
                         promptInput.rows = 3;
                         promptInput.dataset.index = idx;
                         promptInput.value = scene.full_prompt;
                         card.appendChild(promptInput);

                         var statusSpan = document.createElement('div');
                         statusSpan.className = 'mt-1 text-xs scene-item-status';
                         statusSpan.id = 'sceneItemStatus_' + idx;
                         card.appendChild(statusSpan);

                         promptsList.appendChild(card);
                     });

                     resultsDiv.classList.remove('hidden');
                 } else {
                     throw new Error(result.error || 'Không thể phân tích nội dung');
                 }
             } catch (error) {
                 statusDiv.innerHTML = '<span class="text-red-600">❌ ' + error.message + '</span>';
             } finally {
                 btn.disabled = false;
                 btn.innerHTML = '🧠 Bước 1: AI Phân Tích Nội Dung';
             }
         });

         // Bước 2: Tạo ảnh tất cả scenes
         document.getElementById('generateAllScenesBtn')?.addEventListener('click', async function() {
             var btn = this;
             var style = document.getElementById('sceneStyle')?.value || 'cinematic';
             var imageProvider = document.getElementById('sceneImageProvider')?.value || 'gemini';
             var statusDiv = document.getElementById('scenesStatus');
             var progressDiv = document.getElementById('scenesProgress');
             var progressBar = document.getElementById('scenesProgressBar');
             var progressText = document.getElementById('scenesProgressText');
             var progressPercent = document.getElementById('scenesProgressPercent');

             var promptInputs = document.querySelectorAll('.scene-prompt-input');
             if (promptInputs.length === 0) {
                 statusDiv.innerHTML =
                     '<span class="text-red-600">❌ Chưa có dữ liệu phân tích. Hãy chạy Bước 1 trước.</span>';
                 return;
             }

             btn.disabled = true;
             btn.innerHTML = '🎨 Đang tạo ảnh...';
             progressDiv.classList.remove('hidden');

             var totalScenes = promptInputs.length;
             var generated = 0;
             var failed = 0;

             for (var i = 0; i < totalScenes; i++) {
                 var prompt = promptInputs[i].value;
                 var sceneTitle = analyzedScenes[i] ? analyzedScenes[i].title : ('Scene ' + (i + 1));
                 var sceneDesc = analyzedScenes[i] ? analyzedScenes[i].description : '';
                 var itemStatus = document.getElementById('sceneItemStatus_' + i);

                 var pct = Math.round(((i) / totalScenes) * 100);
                 progressBar.style.width = pct + '%';
                 progressText.textContent = 'Đang tạo cảnh ' + (i + 1) + '/' + totalScenes + ': ' + sceneTitle;
                 progressPercent.textContent = pct + '%';
                 if (itemStatus) itemStatus.innerHTML = '<span class="text-blue-600">⏳ Đang tạo ảnh...</span>';

                 try {
                     var resp = await fetch('/audiobooks/' + audioBookId + '/media/generate-scene-image', {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         },
                         body: JSON.stringify({
                             prompt: prompt,
                             scene_index: i,
                             scene_title: sceneTitle,
                             scene_description: sceneDesc,
                             style: style,
                             image_provider: imageProvider
                         })
                     });

                     var imgResult = await safeJson(resp);
                     if (imgResult.success) {
                         generated++;
                         if (itemStatus) itemStatus.innerHTML =
                             '<span class="text-green-600">✅ Đã tạo ảnh thành công!</span>';
                     } else {
                         failed++;
                         if (itemStatus) itemStatus.innerHTML = '<span class="text-red-600">❌ ' + (imgResult
                             .error || 'Lỗi') + '</span>';
                     }
                 } catch (error) {
                     failed++;
                     if (itemStatus) itemStatus.innerHTML = '<span class="text-red-600">❌ ' + error.message +
                         '</span>';
                 }
             }

             progressBar.style.width = '100%';
             progressPercent.textContent = '100%';
             progressText.textContent = 'Hoàn tất: ' + generated + '/' + totalScenes + ' cảnh';

             if (failed > 0) {
                 statusDiv.innerHTML = '<span class="text-yellow-600">⚠️ Tạo được ' + generated + '/' +
                     totalScenes + ' cảnh (' + failed + ' lỗi)</span>';
             } else {
                 statusDiv.innerHTML = '<span class="text-green-600">✅ Tạo thành công ' + generated +
                     ' cảnh minh họa!</span>';
             }

             refreshMediaGallery();
             btn.disabled = false;
             btn.innerHTML = '🎨 Bước 2: Tạo Ảnh Tất Cả Cảnh';
             setTimeout(function() {
                 progressDiv.classList.add('hidden');
             }, 5000);
         });

         // Tạo ảnh 1 scene riêng lẻ
         document.addEventListener('click', async function(e) {
             var singleBtn = e.target.closest('.generate-single-scene-btn');
             if (!singleBtn) return;

             var idx = parseInt(singleBtn.dataset.index);
             var style = document.getElementById('sceneStyle')?.value || 'cinematic';
             var imageProvider = document.getElementById('sceneImageProvider')?.value || 'gemini';
             var promptInput = document.querySelector('.scene-prompt-input[data-index="' + idx + '"]');
             var itemStatus = document.getElementById('sceneItemStatus_' + idx);

             if (!promptInput) return;

             singleBtn.disabled = true;
             singleBtn.textContent = '⏳...';
             if (itemStatus) itemStatus.innerHTML = '<span class="text-blue-600">⏳ Đang tạo ảnh...</span>';

             try {
                 var resp = await fetch('/audiobooks/' + audioBookId + '/media/generate-scene-image', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         prompt: promptInput.value,
                         scene_index: idx,
                         scene_title: analyzedScenes[idx] ? analyzedScenes[idx].title : (
                             'Scene ' + (idx + 1)),
                         scene_description: analyzedScenes[idx] ? analyzedScenes[idx]
                             .description : '',
                             style: style,
                             image_provider: imageProvider
                     })
                 });

                 var singleResult = await safeJson(resp);
                 if (singleResult.success) {
                     if (itemStatus) itemStatus.innerHTML =
                         '<span class="text-green-600">✅ Đã tạo ảnh thành công!</span>';
                     refreshMediaGallery();
                 } else {
                     if (itemStatus) itemStatus.innerHTML = '<span class="text-red-600">❌ ' + (singleResult
                         .error || 'Lỗi') + '</span>';
                 }
             } catch (error) {
                 if (itemStatus) itemStatus.innerHTML = '<span class="text-red-600">❌ ' + error.message +
                     '</span>';
             } finally {
                 singleBtn.disabled = false;
                 singleBtn.textContent = '🎨 Tạo ảnh';
             }
         });

         // ========== Scene Slideshow Video Generation ==========
         var generateSlideshowBtn = document.getElementById('generateSlideshowBtn');
         if (generateSlideshowBtn) {
             generateSlideshowBtn.addEventListener('click', async function() {
                 var btn = this;
                 var statusDiv = document.getElementById('slideshowStatus');
                 var progressDiv = document.getElementById('slideshowProgress');

                 btn.disabled = true;
                 btn.innerHTML = '⏳ Đang tạo video slideshow...';
                 statusDiv.innerHTML =
                     '<span class="text-blue-600">⏳ Đang ghép phân cảnh với audio. Quá trình này có thể mất 1-3 phút...</span>';
                 progressDiv.classList.remove('hidden');

                 try {
                     var resp = await fetch('/audiobooks/' + audioBookId + '/media/generate-scene-slideshow', {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         }
                     });

                     var result = await safeJson(resp);
                     if (result.success) {
                         statusDiv.innerHTML = '<span class="text-green-600">✅ ' + result.message + ' (' + result
                             .scenes_count + ' cảnh)</span>';

                         // Show video player
                         var videoContainer = document.getElementById('slideshowVideoContainer');
                         var videoPlayer = document.getElementById('slideshowVideoPlayer');
                         var durationSpan = document.getElementById('slideshowDuration');
                         var downloadBtn = document.getElementById('slideshowDownloadBtn');

                         videoContainer.classList.remove('hidden');
                         videoPlayer.src = result.video_url;
                         videoPlayer.load();
                         if (result.duration) {
                             var mins = Math.floor(result.duration / 60);
                             var secs = Math.floor(result.duration % 60);
                             durationSpan.textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;
                         }
                         downloadBtn.href = result.video_url;
                     } else {
                         statusDiv.innerHTML = '<span class="text-red-600">❌ ' + (result.error ||
                             'Lỗi không xác định') + '</span>';
                     }
                 } catch (error) {
                     statusDiv.innerHTML = '<span class="text-red-600">❌ ' + error.message + '</span>';
                 } finally {
                     btn.disabled = false;
                     btn.innerHTML = '🎥 Tạo Video từ Phân Cảnh + Audio';
                     progressDiv.classList.add('hidden');
                 }
             });
         }

         // Delete slideshow video
         var deleteSlideshowBtn = document.getElementById('deleteSlideshowBtn');
         if (deleteSlideshowBtn) {
             deleteSlideshowBtn.addEventListener('click', async function() {
                 if (!confirm('Bạn có chắc muốn xóa video phân cảnh này?')) return;

                 try {
                     var resp = await fetch('/audiobooks/' + audioBookId + '/media/delete', {
                         method: 'DELETE',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         },
                         body: JSON.stringify({
                             filename: 'description_scenes.mp4',
                             type: 'mp4'
                         })
                     });

                     var result = await safeJson(resp);
                     if (result.success) {
                         document.getElementById('slideshowVideoContainer').classList.add('hidden');
                         document.getElementById('slideshowStatus').innerHTML =
                             '<span class="text-green-600">✅ Đã xóa video</span>';
                     }
                 } catch (error) {
                     alert('Lỗi: ' + error.message);
                 }
             });
         }

         // Refresh Media Gallery
         async function refreshMediaGallery() {
             try {
                 const response = await fetch('/audiobooks/' + audioBookId + '/media');
                 const result = await safeJson(response);

                 if (result.success) {
                     renderThumbnailGallery(result.media.thumbnails || []);
                     renderSceneGallery(result.media.scenes || []);
                     renderAnimationGallery(result.media.animations || []);

                     // Show/hide slideshow section based on whether scenes exist
                     var slideshowSection = document.getElementById('sceneSlideshowSection');
                     var scenesList = result.media.scenes || [];
                     if (slideshowSection) {
                         if (scenesList.length > 0) {
                             slideshowSection.classList.remove('hidden');
                         } else {
                             slideshowSection.classList.add('hidden');
                         }
                     }
                 }
             } catch (error) {
                 console.error('Failed to load media:', error);
             }
         }

         function renderThumbnailGallery(thumbnails) {
             const gallery = document.getElementById('thumbnailGallery');
             const countBadge = document.getElementById('thumbnailCount');

             countBadge.textContent = thumbnails.length;

             if (thumbnails.length === 0) {
                 gallery.innerHTML = `
                    <div class="text-center py-8 text-gray-400 col-span-full">
                        <span class="text-3xl">🖼️</span>
                        <p class="text-sm mt-2">Chưa có thumbnail nào</p>
                    </div>
                `;
                 return;
             }

             gallery.innerHTML = thumbnails.map(thumb => `
                <div class="relative group cursor-pointer" onclick="window.openImagePreview('${thumb.url.replace(/'/g, "\\'")}')">
                    <img src="${thumb.url}" alt="Thumbnail" 
                        class="w-full aspect-video object-cover rounded-lg border shadow-sm hover:shadow-md transition">
                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 rounded-lg transition flex flex-col items-center justify-center gap-1 opacity-0 group-hover:opacity-100 pointer-events-none p-1">
                        <div class="flex flex-wrap items-center justify-center gap-1">
                            <button onclick="event.stopPropagation(); window.openAddTextModal('${thumb.filename.replace(/'/g, "\\'")}', '${thumb.url.replace(/'/g, "\\'")}');" 
                                class="bg-indigo-600 hover:bg-indigo-700 text-white px-1.5 py-0.5 rounded text-[10px] font-medium pointer-events-auto whitespace-nowrap">
                                ✏️ Thêm Text
                            </button>
                            <button onclick="event.stopPropagation(); window.openChapterCoverModal('${thumb.filename.replace(/'/g, "\\'")}', '${thumb.url.replace(/'/g, "\\'")}');" 
                                class="bg-purple-600 hover:bg-purple-700 text-white px-1.5 py-0.5 rounded text-[10px] font-medium pointer-events-auto whitespace-nowrap">
                                📚 Bìa chương
                            </button>
                        </div>
                        <div class="flex flex-wrap items-center justify-center gap-1">
                            <button onclick="event.stopPropagation(); window.openAddLogoModal('${thumb.filename.replace(/'/g, "\\'")}', '${thumb.url.replace(/'/g, "\\'")}');"
                                class="bg-yellow-600 hover:bg-yellow-700 text-white px-1.5 py-0.5 rounded text-[10px] font-medium pointer-events-auto whitespace-nowrap">
                                🏷️ Logo
                            </button>
                            <button onclick="event.stopPropagation(); window.createAnimation('${thumb.filename.replace(/'/g, "\\'")}');"
                                class="bg-green-600 hover:bg-green-700 text-white px-1.5 py-0.5 rounded text-[10px] font-medium pointer-events-auto whitespace-nowrap">
                                ✨ Animation
                            </button>
                            <button onclick="event.stopPropagation(); window.deleteMediaFile('${thumb.filename.replace(/'/g, "\\'")}');"
                                class="bg-red-600 hover:bg-red-700 text-white px-1.5 py-0.5 rounded text-[10px] font-medium pointer-events-auto whitespace-nowrap">
                                🗑️ Xóa
                            </button>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 truncate">${thumb.filename}</div>
                </div>
            `).join('');
         }

         function renderSceneGallery(scenes) {
             const gallery = document.getElementById('sceneGallery');
             const countBadge = document.getElementById('sceneCount2');

             countBadge.textContent = scenes.length;

             if (scenes.length === 0) {
                 gallery.innerHTML = `
                    <div class="text-center py-8 text-gray-400 col-span-full">
                        <span class="text-3xl">🎬</span>
                        <p class="text-sm mt-2">Chưa có scene nào. Nhấn "Tạo Cảnh Minh Họa" để AI phân tích nội dung và tạo scenes.</p>
                    </div>
                `;
                 return;
             }

             gallery.innerHTML = '';
             scenes.forEach((scene, idx) => {
                 const card = document.createElement('div');
                 card.className = 'relative group cursor-pointer';
                 card.onclick = () => window.openImagePreview(scene.url);

                 // Scene Number Badge
                 const badge = document.createElement('div');
                 badge.className =
                     'absolute top-1 left-1 bg-gradient-to-r from-blue-600 to-purple-600 text-white text-xs px-2 py-0.5 rounded z-10 pointer-events-none font-semibold';
                 badge.textContent = `Phân cảnh ${idx + 1}`;

                 // Scene Image
                 const img = document.createElement('img');
                 img.src = scene.url;
                 img.alt = `Scene ${idx + 1}`;
                 img.className =
                     'w-full aspect-video object-cover rounded-lg border shadow-sm hover:shadow-md transition';

                 // Scene Info Overlay
                 if (scene.title || scene.description) {
                     const overlay = document.createElement('div');
                     overlay.className =
                         'absolute inset-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex flex-col justify-end p-3 pointer-events-none';

                     if (scene.title) {
                         const title = document.createElement('h4');
                         title.className = 'text-white text-sm font-bold mb-1';
                         title.textContent = scene.title;
                         overlay.appendChild(title);
                     }

                     if (scene.description) {
                         const desc = document.createElement('p');
                         desc.className = 'text-gray-200 text-xs line-clamp-3';
                         desc.textContent = scene.description;
                         overlay.appendChild(desc);
                     }

                     card.appendChild(overlay);
                 }

                 // Hover Actions
                 const actions = document.createElement('div');
                 actions.className =
                     'absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 rounded-lg transition flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 pointer-events-none';

                 const coverBtn = document.createElement('button');
                 coverBtn.className =
                     'bg-purple-600 hover:bg-purple-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto';
                 coverBtn.textContent = '📚 Bìa chương';
                 coverBtn.onclick = (e) => {
                     e.stopPropagation();
                     window.openChapterCoverModal(scene.filename, scene.url);
                 };

                 const animBtn = document.createElement('button');
                 animBtn.className =
                     'bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto';
                 animBtn.textContent = '✨ Animation';
                 animBtn.onclick = (e) => {
                     e.stopPropagation();
                     window.createAnimation(scene.filename);
                 };

                 const delBtn = document.createElement('button');
                 delBtn.className =
                     'bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto';
                 delBtn.textContent = '🗑️ Xóa';
                 delBtn.onclick = (e) => {
                     e.stopPropagation();
                     window.deleteMediaFile(scene.filename);
                 };

                 actions.appendChild(coverBtn);
                 actions.appendChild(animBtn);
                 actions.appendChild(delBtn);

                 card.appendChild(badge);
                 card.appendChild(img);
                 card.appendChild(actions);

                 gallery.appendChild(card);
             });
         }

         // Animation Gallery
         function renderAnimationGallery(animations) {
             const gallery = document.getElementById('animationGallery');
             const countBadge = document.getElementById('animationCount');

             countBadge.textContent = animations.length;

             if (animations.length === 0) {
                 gallery.innerHTML = `
                    <div class="text-center py-8 text-gray-400 col-span-full">
                        <span class="text-3xl">✨</span>
                        <p class="text-sm mt-2">Chưa có animation nào</p>
                    </div>
                `;
                 return;
             }

             gallery.innerHTML = animations.map((anim, idx) => `
                <div class="relative group">
                    <video src="${anim.url}" 
                        class="w-full aspect-video object-cover rounded-lg border shadow-sm hover:shadow-md transition cursor-pointer"
                        muted loop
                        onmouseenter="this.play().catch(()=>{})" 
                        onmouseleave="this.pause(); this.currentTime = 0;">
                    </video>
                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 rounded-lg transition flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 pointer-events-none">
                        <a href="${anim.url}" download 
                            onclick="event.stopPropagation();"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto">
                            ⬇️ Download
                        </a>
                        <button onclick="event.stopPropagation(); window.deleteMediaFile('${anim.filename.replace(/'/g, "\\'")}');" 
                            class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs font-medium pointer-events-auto">
                            🗑️ Xóa
                        </button>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 truncate">${anim.filename}</div>
                </div>
            `).join('');
         }

         // Create Animation with Kling AI - show prompt dialog first
         window.createAnimation = async function(imageName) {
             const defaultPrompt =
                 "Subtle ambient animation with gentle movements: soft smoke or mist drifting slowly, flickering candlelight or lamp glow, slight hair or fabric movement from breeze, gentle eye blinking, subtle breathing motion. Keep the scene peaceful and dreamy, suitable for audiobook background.";

             // Show modal with prompt editor
             const modal = document.createElement('div');
             modal.id = 'animationPromptModal';
             modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
             modal.innerHTML = `
                <div class="bg-white rounded-xl shadow-2xl p-6 max-w-lg w-full mx-4">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">✨ Animation Prompt</h3>
                    <p class="text-sm text-gray-500 mb-3">Ảnh: <strong>${imageName}</strong></p>
                    <div class="mb-3">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Video Provider</label>
                        <select id="animationVideoProvider" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-green-500 focus:outline-none">
                            <option value="kling">Kling</option>
                            <option value="seedance">Seedance</option>
                        </select>
                    </div>
                    <textarea id="animationPromptInput" rows="6" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:border-green-500 focus:outline-none resize-y mb-3">${defaultPrompt}</textarea>
                    <div class="flex gap-2">
                        <button id="animationPromptStartBtn" class="flex-1 bg-green-600 hover:bg-green-700 text-white py-2.5 rounded-lg font-semibold transition">🚀 Tạo Animation</button>
                        <button id="animationPromptCancelBtn" class="px-4 bg-gray-300 hover:bg-gray-400 text-gray-700 py-2.5 rounded-lg font-semibold transition">Hủy</button>
                    </div>
                    <p class="text-xs text-gray-400 mt-2">⏱️ Quá trình có thể mất 1-3 phút</p>
                </div>
            `;
             document.body.appendChild(modal);

             // Wait for user action
             const userPrompt = await new Promise((resolve) => {
                 document.getElementById('animationPromptCancelBtn').addEventListener('click', () => {
                     modal.remove();
                     resolve(null);
                 });
                 modal.addEventListener('click', (e) => {
                     if (e.target === modal) {
                         modal.remove();
                         resolve(null);
                     }
                 });
                 document.getElementById('animationPromptStartBtn').addEventListener('click', () => {
                     const val = document.getElementById('animationPromptInput').value.trim();
                     const providerVal = (document.getElementById('animationVideoProvider')?.value || 'kling').toLowerCase() === 'seedance' ? 'seedance' : 'kling';
                     modal.remove();
                     resolve({
                         prompt: val || defaultPrompt,
                         videoProvider: providerVal,
                     });
                 });
             });

             if (!userPrompt) return;
            const userPromptText = userPrompt.prompt || defaultPrompt;
            const userVideoProvider = userPrompt.videoProvider || 'kling';
            const userVideoProviderLabel = userVideoProvider === 'seedance' ? 'Seedance' : 'Kling';

             const statusDiv = document.createElement('div');
             statusDiv.id = 'animationStatus';
             statusDiv.className = 'fixed top-4 right-4 bg-white rounded-lg shadow-lg p-4 z-50 border';
             statusDiv.innerHTML = `
                <div class="flex items-center gap-3">
                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-green-600"></div>
                    <div>
                        <p class="font-medium text-gray-800">Đang tạo Animation (${userVideoProviderLabel})...</p>
                        <p class="text-sm text-gray-500" id="animationStatusText">Đang khởi tạo task...</p>
                    </div>
                </div>
            `;
             document.body.appendChild(statusDiv);

             try {
                 // Start task
                 const startResponse = await fetch(`/audiobooks/{{ $audioBook->id }}/animations/start-task`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                         'Accept': 'application/json'
                     },
                     body: JSON.stringify({
                         image_name: imageName,
                         prompt: userPromptText,
                         video_provider: userVideoProvider,
                     })
                 });

                 const startResult = await safeJson(startResponse);
                 console.log('Start result:', startResult);

                 if (!startResult.success) {
                     const errorMsg = typeof startResult.error === 'string' ?
                         startResult.error :
                         JSON.stringify(startResult.error) || 'Failed to start animation task';
                     throw new Error(errorMsg);
                 }

                 const taskId = startResult.task_id;
                 const responseVideoProvider = ((startResult.video_provider || userVideoProvider).toLowerCase() === 'seedance') ? 'seedance' : 'kling';
                 const responseVideoProviderLabel = startResult.video_provider_label || (responseVideoProvider === 'seedance' ? 'Seedance' : 'Kling');
                 document.getElementById('animationStatusText').textContent =
                     `Task ID: ${taskId.substring(0, 8)}... ${responseVideoProviderLabel} đang xử lý...`;

                 // Poll for status
                 let attempts = 0;
                 const maxAttempts = 60; // 5 minutes max (5s * 60)

                 const pollStatus = async () => {
                     try {
                         attempts++;
                         const statusResponse = await fetch(
                             `/audiobooks/{{ $audioBook->id }}/animations/check-status`, {
                                 method: 'POST',
                                 headers: {
                                     'Content-Type': 'application/json',
                                     'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                         .content,
                                     'Accept': 'application/json'
                                 },
                                 body: JSON.stringify({
                                     task_id: taskId,
                                     video_provider: responseVideoProvider,
                                 })
                             });

                         const statusResult = await safeJson(statusResponse);

                         if (statusResult.success && statusResult.completed) {
                             // Done!
                             statusDiv.innerHTML = `
                                <div class="flex items-center gap-3 text-green-600">
                                    <span class="text-2xl">✅</span>
                                    <div>
                                        <p class="font-medium">Animation ${responseVideoProviderLabel} hoàn thành!</p>
                                        <p class="text-sm">Đang tải lại gallery...</p>
                                    </div>
                                </div>
                            `;
                             setTimeout(() => {
                                 statusDiv.remove();
                                 refreshMediaGallery(); // Reload to show new animation
                             }, 2000);
                             return;
                         }

                         if (statusResult.status === 'failed') {
                             const errorMsg = statusResult.error || 'Animation task failed';
                             statusDiv.innerHTML = `
                                <div class="flex items-center gap-3 text-red-600">
                                    <span class="text-2xl">❌</span>
                                    <div>
                                        <p class="font-medium">Lỗi tạo Animation ${responseVideoProviderLabel}</p>
                                        <p class="text-sm">${errorMsg}</p>
                                    </div>
                                </div>
                            `;
                             setTimeout(() => statusDiv.remove(), 5000);
                             return;
                         }

                         if (attempts >= maxAttempts) {
                             statusDiv.innerHTML = `
                                <div class="flex items-center gap-3 text-red-600">
                                    <span class="text-2xl">❌</span>
                                    <div>
                                        <p class="font-medium">Timeout</p>
                                        <p class="text-sm">Animation mất quá nhiều thời gian</p>
                                    </div>
                                </div>
                            `;
                             setTimeout(() => statusDiv.remove(), 5000);
                             return;
                         }

                         // Update status text
                         document.getElementById('animationStatusText').textContent =
                             `${responseVideoProviderLabel}: ${statusResult.status || 'processing'}... (${attempts}/${maxAttempts})`;

                         // Continue polling
                         setTimeout(pollStatus, 5000);
                     } catch (pollError) {
                         console.error('Poll error:', pollError);
                         // Continue polling on network errors
                         if (attempts < maxAttempts) {
                             setTimeout(pollStatus, 5000);
                         } else {
                             statusDiv.innerHTML = `
                                <div class="flex items-center gap-3 text-red-600">
                                    <span class="text-2xl">❌</span>
                                    <div>
                                        <p class="font-medium">Lỗi kết nối</p>
                                        <p class="text-sm">${pollError.message || 'Network error'}</p>
                                    </div>
                                </div>
                            `;
                             setTimeout(() => statusDiv.remove(), 5000);
                         }
                     }
                 };

                 pollStatus();

             } catch (error) {
                 console.error('Animation error:', error);
                 let errorMessage = 'Unknown error';
                 if (typeof error === 'string') {
                     errorMessage = error;
                 } else if (error && error.message) {
                     errorMessage = typeof error.message === 'string' ? error.message : JSON.stringify(error
                         .message);
                 } else if (error) {
                     errorMessage = JSON.stringify(error);
                 }
                 statusDiv.innerHTML = `
                    <div class="flex items-center gap-3 text-red-600">
                        <span class="text-2xl">❌</span>
                        <div>
                            <p class="font-medium">Lỗi tạo Animation</p>
                            <p class="text-sm">${errorMessage}</p>
                        </div>
                    </div>
                `;
                 setTimeout(() => statusDiv.remove(), 5000);
             }
         };

         // Image Preview Modal
         let currentZoom = 1;

         function openImagePreview(url) {
             console.log('openImagePreview called with:', url);
             const modal = document.getElementById('imagePreviewModal');
             const img = document.getElementById('previewImage');
             const downloadLink = document.getElementById('downloadImageLink');

             if (!modal || !img) {
                 console.error('Modal or image element not found!');
                 return;
             }

             img.src = url;
             downloadLink.href = url;
             currentZoom = 1;
             img.style.transform = 'scale(1)';
             updateZoomIndicator();
             modal.classList.remove('hidden');
             document.body.style.overflow = 'hidden'; // Prevent background scrolling
             console.log('Modal should be visible now');
         }

         function closeImagePreview() {
             document.getElementById('imagePreviewModal').classList.add('hidden');
             document.body.style.overflow = ''; // Restore scrolling
             currentZoom = 1;
         }

         function zoomImage(delta) {
             const img = document.getElementById('previewImage');
             currentZoom = Math.max(0.5, Math.min(3, currentZoom + delta));
             img.style.transform = `scale(${currentZoom})`;
             img.style.cursor = currentZoom > 1 ? 'grab' : 'zoom-in';
             updateZoomIndicator();
         }

         function resetImageZoom() {
             const img = document.getElementById('previewImage');
             currentZoom = 1;
             img.style.transform = 'scale(1)';
             img.style.cursor = 'zoom-in';
             updateZoomIndicator();
         }

         function toggleImageZoom(img) {
             if (currentZoom >= 1.5) {
                 resetImageZoom();
             } else {
                 currentZoom = 2;
                 img.style.transform = 'scale(2)';
                 img.style.cursor = 'grab';
                 updateZoomIndicator();
             }
         }

         function updateZoomIndicator() {
             const indicator = document.getElementById('zoomLevelIndicator');
             if (indicator) {
                 indicator.textContent = Math.round(currentZoom * 100) + '%';
             }
         }

         // Keyboard support for image preview
         document.addEventListener('keydown', function(e) {
             const modal = document.getElementById('imagePreviewModal');
             if (modal && !modal.classList.contains('hidden')) {
                 if (e.key === 'Escape') {
                     closeImagePreview();
                 } else if (e.key === '+' || e.key === '=') {
                     zoomImage(0.2);
                 } else if (e.key === '-') {
                     zoomImage(-0.2);
                 } else if (e.key === '0') {
                     resetImageZoom();
                 }
             }
         });

         // Delete single media file
         async function deleteMediaFile(filename) {
             if (!confirm('Bạn có chắc muốn xóa file này?')) return;

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/media/delete`, {
                     method: 'DELETE',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         filename: filename
                     })
                 });

                 const result = await safeJson(response);
                 if (result.success) {
                     refreshMediaGallery();
                 } else {
                     alert('Lỗi: ' + (result.error || 'Không thể xóa'));
                 }
             } catch (error) {
                 alert('Lỗi: ' + error.message);
             }
         }

         // Delete all media by type
         async function deleteAllMedia(type) {
             const typeLabel = type === 'thumbnails' ? 'thumbnails' : 'scenes';
             if (!confirm(`Bạn có chắc muốn xóa TẤT CẢ ${typeLabel}?`)) return;

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/media/delete`, {
                     method: 'DELETE',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         type: type
                     })
                 });

                 const result = await safeJson(response);
                 if (result.success) {
                     refreshMediaGallery();
                     alert(result.message || 'Đã xóa thành công');
                 } else {
                     alert('Lỗi: ' + (result.error || 'Không thể xóa'));
                 }
             } catch (error) {
                 alert('Lỗi: ' + error.message);
             }
         }

         // ========== VIDEO PREVIEW FUNCTIONS ==========
         function openVideoPreview(url) {
             console.log('openVideoPreview called with:', url);

             // Create video preview modal
             const existingModal = document.getElementById('videoPreviewModal');
             if (existingModal) {
                 existingModal.remove();
             }

             const modal = document.createElement('div');
             modal.id = 'videoPreviewModal';
             modal.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black bg-opacity-90';
             modal.onclick = function(e) {
                 if (e.target === modal) {
                     closeVideoPreview();
                 }
             };

             modal.innerHTML = `
                <div class="relative max-w-6xl max-h-[90vh] w-full mx-4">
                    <button onclick="closeVideoPreview()" 
                        class="absolute -top-12 right-0 text-white text-4xl hover:text-red-400 transition z-50">&times;</button>
                    <video controls autoplay class="w-full max-h-[85vh] rounded-lg shadow-2xl">
                        <source src="${url}" type="video/mp4">
                        Trình duyệt không hỗ trợ video.
                    </video>
                </div>
            `;

             document.body.appendChild(modal);
             document.body.style.overflow = 'hidden';

             // Close on Escape key
             document.addEventListener('keydown', handleVideoEscapeKey);
         }

         function closeVideoPreview() {
             const modal = document.getElementById('videoPreviewModal');
             if (modal) {
                 modal.remove();
                 document.body.style.overflow = '';
                 document.removeEventListener('keydown', handleVideoEscapeKey);
             }
         }

         function handleVideoEscapeKey(e) {
             if (e.key === 'Escape') {
                 closeVideoPreview();
             }
         }

         // Expose functions to window scope for inline onclick handlers
         window.openImagePreview = openImagePreview;
         window.closeImagePreview = closeImagePreview;
         window.zoomImage = zoomImage;
         window.resetImageZoom = resetImageZoom;
         window.toggleImageZoom = toggleImageZoom;
         window.deleteMediaFile = deleteMediaFile;
         window.deleteAllMedia = deleteAllMedia;
         window.refreshMediaGallery = refreshMediaGallery;
         window.openVideoPreview = openVideoPreview;
         window.closeVideoPreview = closeVideoPreview;

         // ========== UPLOAD MEDIA FUNCTIONS ==========
         function openUploadMediaModal() {
             document.getElementById('uploadMediaModal').classList.remove('hidden');
             document.getElementById('uploadMediaFiles').value = '';
             document.getElementById('uploadMediaPreview').innerHTML = '';
             document.getElementById('uploadMediaStatus').innerHTML = '';
         }

         function closeUploadMediaModal() {
             document.getElementById('uploadMediaModal').classList.add('hidden');
         }

         // Preview selected images
         document.getElementById('uploadMediaFiles')?.addEventListener('change', function(e) {
             const preview = document.getElementById('uploadMediaPreview');
             preview.innerHTML = '';

             const files = e.target.files;
             if (files.length === 0) return;

             for (let i = 0; i < Math.min(files.length, 9); i++) {
                 const file = files[i];
                 if (file.type.startsWith('image/')) {
                     const reader = new FileReader();
                     reader.onload = function(e) {
                         const div = document.createElement('div');
                         div.className = 'relative';
                         div.innerHTML = `
                             <img src="${e.target.result}" class="w-full h-20 object-cover rounded border">
                             <div class="text-xs text-center text-gray-600 truncate mt-1">${file.name}</div>
                         `;
                         preview.appendChild(div);
                     };
                     reader.readAsDataURL(file);
                 }
             }

             if (files.length > 9) {
                 const moreDiv = document.createElement('div');
                 moreDiv.className =
                     'flex items-center justify-center h-20 bg-gray-100 rounded border text-gray-500 text-xs';
                 moreDiv.textContent = `+${files.length - 9} file`;
                 preview.appendChild(moreDiv);
             }
         });

         async function uploadMediaFiles() {
             const fileInput = document.getElementById('uploadMediaFiles');
             const typeSelect = document.getElementById('uploadMediaType');
             const statusDiv = document.getElementById('uploadMediaStatus');
             const uploadBtn = document.getElementById('uploadMediaBtn');

             const files = fileInput.files;
             if (files.length === 0) {
                 statusDiv.innerHTML = '<span class="text-red-600">⚠️ Vui lòng chọn ít nhất 1 file</span>';
                 return;
             }

             // Validate file sizes
             const maxSize = 10 * 1024 * 1024; // 10MB
             let totalSize = 0;
             for (let i = 0; i < files.length; i++) {
                 totalSize += files[i].size;
                 if (files[i].size > maxSize) {
                     statusDiv.innerHTML =
                         `<span class="text-red-600">❌ File "${files[i].name}" quá lớn (${(files[i].size / 1024 / 1024).toFixed(2)}MB). Tối đa 10MB/file.</span>`;
                     return;
                 }
             }

             const type = typeSelect.value;
             const formData = new FormData();

             for (let i = 0; i < files.length; i++) {
                 formData.append('images[]', files[i]);
             }
             formData.append('type', type);

             uploadBtn.disabled = true;
             const totalSizeMB = (totalSize / 1024 / 1024).toFixed(2);
             statusDiv.innerHTML = `<span class="text-blue-600">⏳ Đang upload ${files.length} file (${totalSizeMB}MB)...</span>`;

             const uploadUrl = `/audiobooks/${audioBookId}/media/upload`;
             console.log('=== UPLOAD REQUEST ===');
             console.log('URL:', uploadUrl);
             console.log('AudioBook ID:', audioBookId);
             console.log('Type:', type);
             console.log('Files count:', files.length);
             console.log('Total size:', totalSizeMB, 'MB');

             try {
                 // Set timeout for large files (2 minutes)
                 const controller = new AbortController();
                 const timeoutId = setTimeout(() => controller.abort(), 120000);

                 const response = await fetch(uploadUrl, {
                     method: 'POST',
                     headers: {
                         'X-CSRF-TOKEN': getCsrfToken(),
                         'X-Requested-With': 'XMLHttpRequest',
                         'Accept': 'application/json'
                     },
                     body: formData,
                     signal: controller.signal
                 });

                 clearTimeout(timeoutId);

                 // Get response text first
                 const responseText = await response.text();
                 console.log('Upload response status:', response.status);
                 console.log('Upload response length:', responseText.length);
                 console.log('Upload response:', responseText.substring(0, 500));

                 // Check for empty response
                 if (!responseText || responseText.trim().length === 0) {
                     console.error('Empty response received');
                     statusDiv.innerHTML =
                         '<span class="text-red-600">❌ Server không phản hồi. File có thể quá lớn hoặc kết nối bị gián đoạn. Hãy thử file nhỏ hơn (< 5MB).</span>';
                     uploadBtn.disabled = false;
                     return;
                 }

                 // Check if response is HTML (session expired, redirected to login)
                 if (responseText.trim().startsWith('<!DOCTYPE') || responseText.trim().startsWith('<html')) {
                     statusDiv.innerHTML =
                         '<span class="text-red-600">❌ Session hết hạn. Vui lòng <a href="#" onclick="location.reload()" class="underline font-bold">tải lại trang</a> và đăng nhập lại.</span>';
                     uploadBtn.disabled = false;
                     return;
                 }

                 let result;
                 try {
                     result = JSON.parse(responseText);
                 } catch (e) {
                     console.error('JSON parse error:', e);
                     console.error('Response text:', responseText);
                     console.error('Response status:', response.status);
                     
                     let errorMsg = 'Lỗi server không xác định.';
                     if (response.status >= 500) {
                         errorMsg = 'Lỗi server (HTTP ' + response.status + '). Vui lòng thử lại.';
                     } else if (response.status === 413) {
                         errorMsg = 'File quá lớn. Vui lòng chọn file nhỏ hơn (< 5MB).';
                     } else if (response.status === 422) {
                         errorMsg = 'File không hợp lệ. Chỉ chấp nhận JPG, PNG, WEBP.';
                     }
                     
                     statusDiv.innerHTML =
                         `<span class="text-red-600">❌ ${errorMsg} <a href="#" onclick="location.reload()" class="underline text-xs">Tải lại trang</a></span>`;
                     uploadBtn.disabled = false;
                     return;
                 }

                 if (response.ok && result.success) {
                     statusDiv.innerHTML =
                         `<span class="text-green-600">✅ Upload thành công ${result.uploaded || 0} file!</span>`;
                     setTimeout(() => {
                         closeUploadMediaModal();
                         refreshMediaGallery();
                     }, 1500);
                 } else {
                     const errorMsg = result.error || result.message || `HTTP ${response.status}`;
                     statusDiv.innerHTML = `<span class="text-red-600">❌ ${errorMsg}</span>`;
                     uploadBtn.disabled = false;
                 }
             } catch (error) {
                 console.error('Upload error:', error);
                 
                 if (error.name === 'AbortError') {
                     statusDiv.innerHTML = `<span class="text-red-600">❌ Upload timeout sau 2 phút. Vui lòng thử lại với ít file hơn.</span>`;
                 } else {
                     statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                 }
                 
                 uploadBtn.disabled = false;
             }
         }

         window.openUploadMediaModal = openUploadMediaModal;
         window.closeUploadMediaModal = closeUploadMediaModal;
         window.uploadMediaFiles = uploadMediaFiles;

         // ========== EXISTING FUNCTIONS ==========
         function openScrapeModal() {
             document.getElementById('scrapeModal').classList.remove('hidden');
         }

         function closeScrapeModal() {
             document.getElementById('scrapeModal').classList.add('hidden');
             document.getElementById('scrapeStatus').innerHTML = '';
         }

         function updateScrapeSourceUI() {
             const sourceSelect = document.getElementById('scrapeSource');
             const urlInput = document.getElementById('scrapeBookUrl');
             const hint = document.getElementById('scrapeSourceHint');

             if (!sourceSelect || !urlInput || !hint) {
                 return;
             }

             const selectedOption = sourceSelect.options[sourceSelect.selectedIndex];
             urlInput.placeholder = selectedOption.dataset.placeholder || '';
             hint.textContent = selectedOption.dataset.hint || '';
         }

         const scrapeSourceSelect = document.getElementById('scrapeSource');
         if (scrapeSourceSelect) {
             updateScrapeSourceUI();
             scrapeSourceSelect.addEventListener('change', updateScrapeSourceUI);
         }

         document.getElementById('scrapeForm').addEventListener('submit', async function(e) {
             e.preventDefault();

             const formData = new FormData(this);
             const statusDiv = document.getElementById('scrapeStatus');

             statusDiv.innerHTML = '<p class="text-blue-600">⏳ Đang scrape...</p>';

             try {
                 const response = await fetch('{{ route('audiobooks.scrape.chapters') }}', {
                     method: 'POST',
                     body: formData,
                     headers: {
                         'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
                     }
                 });

                 const data = await safeJson(response);

                 if (response.ok) {
                     statusDiv.innerHTML = `<p class="text-green-600">✅ ${data.message}</p>`;
                     setTimeout(() => {
                         window.location.reload();
                     }, 2000);
                 } else {
                     statusDiv.innerHTML = `<p class="text-red-600">❌ ${data.error}</p>`;
                 }
             } catch (error) {
                 statusDiv.innerHTML = `<p class="text-red-600">❌ Lỗi: ${error.message}</p>`;
             }
         });

         // Close modal when clicking outside
         document.getElementById('scrapeModal').addEventListener('click', function(e) {
             if (e.target === this) {
                 closeScrapeModal();
             }
         });

         // ========== TTS SETTINGS ==========
         let currentTtsProvider = '{{ $audioBook->tts_provider ?? '' }}';
         let voiceOptionsCache = {};
         let currentAudioPlayer = null;

         // Toggle TTS panel
         document.getElementById('ttsToggleBtn').addEventListener('click', function() {
             const content = document.getElementById('ttsContent');
             const icon = document.getElementById('ttsToggleIcon');
             if (content.style.display === 'none') {
                 content.style.display = 'block';
                 icon.textContent = '−';
             } else {
                 content.style.display = 'none';
                 icon.textContent = '+';
             }
         });

         // Provider change
         document.getElementById('ttsProviderSelect').addEventListener('change', function() {
             currentTtsProvider = this.value;
             voiceOptionsCache = {};
             updateVoiceOptions();
             updateStyleInstructionVisibility();
         });

         // Show/hide style instruction based on provider
         function updateStyleInstructionVisibility() {
             const styleSection = document.getElementById('styleInstructionSection');
             if (!styleSection) return; // Guard against null

             const providersWithoutStyle = ['microsoft', 'openai', 'vbee'];
             if (providersWithoutStyle.includes(currentTtsProvider)) {
                 styleSection.style.display = 'none';
             } else {
                 styleSection.style.display = 'block';
             }
         }

         // Gender change
         document.querySelectorAll('input[name="voiceGender"]').forEach(radio => {
             radio.addEventListener('change', function() {
                 updateVoiceOptions();
             });
         });

         // Style preset buttons
         document.querySelectorAll('.style-preset-btn').forEach(btn => {
             btn.addEventListener('click', function() {
                 const text = this.dataset.text.replace(/&#10;/g, '\n');
                 document.getElementById('ttsStyleInstruction').value = text;
             });
         });

         // Art style preset buttons
         document.querySelectorAll('.art-preset-btn').forEach(btn => {
             btn.addEventListener('click', function() {
                 const text = this.dataset.text.replace(/&#10;/g, '\n');
                 document.getElementById('artStyleInstruction').value = text;
             });
         });

         // Fetch and update voice options
         async function updateVoiceOptions() {
             const voiceSelect = document.getElementById('voiceNameSelect');
             const gender = document.querySelector('input[name="voiceGender"]:checked')?.value || 'female';

             if (!currentTtsProvider) {
                 voiceSelect.innerHTML = '<option value="">-- Chọn Provider trước --</option>';
                 return;
             }

             voiceSelect.innerHTML = '<option value="">⏳ Đang tải...</option>';

             try {
                 const voices = await fetchAvailableVoices(gender);
                 voiceSelect.innerHTML = '<option value="">-- Chọn giọng --</option>';

                 for (const [voiceCode, voiceLabel] of Object.entries(voices)) {
                     const option = document.createElement('option');
                     option.value = voiceCode;
                     option.textContent = voiceLabel;
                     if (voiceCode === '{{ $audioBook->tts_voice_name ?? '' }}') {
                         option.selected = true;
                     }
                     voiceSelect.appendChild(option);
                 }
             } catch (error) {
                 voiceSelect.innerHTML = '<option value="">-- Lỗi tải giọng --</option>';
             }
         }

         async function fetchAvailableVoices(gender) {
             const cacheKey = `${currentTtsProvider}:${gender}`;
             if (voiceOptionsCache[cacheKey]) {
                 return voiceOptionsCache[cacheKey];
             }

             const response = await fetch(`/get-available-voices?gender=${gender}&provider=${currentTtsProvider}`);
             const data = await safeJson(response);

             if (data.success) {
                 voiceOptionsCache[cacheKey] = data.voices[gender] || {};
                 return voiceOptionsCache[cacheKey];
             }
             return {};
         }

         // Preview voice
         document.getElementById('voicePreviewBtn').addEventListener('click', async function() {
             const voiceName = document.getElementById('voiceNameSelect').value;
             const gender = document.querySelector('input[name="voiceGender"]:checked')?.value || 'female';

             if (!voiceName) {
                 alert('Vui lòng chọn giọng trước');
                 return;
             }

             if (currentAudioPlayer) {
                 currentAudioPlayer.pause();
                 currentAudioPlayer = null;
             }

             const btn = this;
             const originalContent = btn.innerHTML;
             btn.innerHTML = '⏳';
             btn.disabled = true;

             try {
                 const response = await fetch('/preview-voice', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         text: 'Xin chào, đây là giọng đọc mẫu cho audiobook của bạn.',
                         voice_gender: gender,
                         voice_name: voiceName,
                         provider: currentTtsProvider
                     })
                 });

                 const data = await safeJson(response);

                 if (data.success) {
                    currentAudioPlayer = new Audio(data.audio_url);
                    currentAudioPlayer.play().catch(()=>{});
                     currentAudioPlayer.addEventListener('ended', () => currentAudioPlayer = null);
                 } else {
                     throw new Error(data.error || 'Không thể tạo preview');
                 }
             } catch (error) {
                 alert('Lỗi: ' + error.message);
             } finally {
                 btn.innerHTML = originalContent;
                 btn.disabled = false;
             }
         });

         // Save TTS settings
         document.getElementById('saveTtsSettingsBtn').addEventListener('click', async function() {
             const btn = this;
             const originalText = btn.innerHTML;
             btn.innerHTML = '⏳ Đang lưu...';
             btn.disabled = true;

             const data = {
                 tts_provider: document.getElementById('ttsProviderSelect').value,
                 tts_voice_gender: document.querySelector('input[name="voiceGender"]:checked')?.value ||
                     'female',
                 tts_voice_name: document.getElementById('voiceNameSelect').value,
                 tts_style_instruction: document.getElementById('ttsStyleInstruction').value,
                 tts_speed: parseFloat(document.getElementById('ttsSpeedSelect').value) || 1.0,
                 pause_between_chunks: Number.isFinite(parseFloat(document.getElementById(
                     'pauseBetweenChunksSelect').value)) ? parseFloat(document.getElementById(
                     'pauseBetweenChunksSelect').value) : 0.0
             };

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/update-tts-settings`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify(data)
                 });

                 const result = await safeJson(response);

                 if (result.success) {
                     btn.innerHTML = '✅ Đã lưu!';
                     setTimeout(() => {
                         btn.innerHTML = originalText;
                         btn.disabled = false;
                     }, 2000);
                 } else {
                     throw new Error(result.error || 'Không thể lưu');
                 }
             } catch (error) {
                 alert('Lỗi: ' + error.message);
                 btn.innerHTML = originalText;
                 btn.disabled = false;
             }
         });

         // ========== ART STYLE PANEL ==========
         // Toggle art style panel
         document.getElementById('artStyleToggleBtn').addEventListener('click', function() {
             const content = document.getElementById('artStyleContent');
             const icon = document.getElementById('artStyleToggleIcon');
             if (content.style.display === 'none') {
                 content.style.display = 'block';
                 icon.textContent = '\u2212';
             } else {
                 content.style.display = 'none';
                 icon.textContent = '+';
             }
         });

         // Save art style
         document.getElementById('saveArtStyleBtn').addEventListener('click', async function() {
             const btn = this;
             const originalText = btn.innerHTML;
             btn.innerHTML = '\u23f3 \u0110ang l\u01b0u...';
             btn.disabled = true;

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/update-tts-settings`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         art_style_instruction: document.getElementById('artStyleInstruction').value
                     })
                 });

                 const result = await safeJson(response);
                 if (result.success) {
                     btn.innerHTML = '\u2705 \u0110\u00e3 l\u01b0u!';
                     setTimeout(() => {
                         btn.innerHTML = originalText;
                         btn.disabled = false;
                     }, 2000);
                 } else {
                     throw new Error(result.error || 'Kh\u00f4ng th\u1ec3 l\u01b0u');
                 }
             } catch (error) {
                 alert('L\u1ed7i: ' + error.message);
                 btn.innerHTML = originalText;
                 btn.disabled = false;
             }
         });

         // ========== CUSTOM ART STYLE PRESETS ==========
         const customPresetsContainer = document.getElementById('customPresetsContainer');
         const noCustomPresetsMsg = document.getElementById('noCustomPresetsMsg');

         // Color palette for custom presets
         const presetColors = ['purple', 'teal', 'rose', 'emerald', 'cyan', 'orange', 'lime', 'fuchsia', 'sky', 'violet'];
         const colorClasses = {
             purple: 'bg-purple-50 hover:bg-purple-100 text-purple-700 border-purple-200',
             teal: 'bg-teal-50 hover:bg-teal-100 text-teal-700 border-teal-200',
             rose: 'bg-rose-50 hover:bg-rose-100 text-rose-700 border-rose-200',
             emerald: 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border-emerald-200',
             cyan: 'bg-cyan-50 hover:bg-cyan-100 text-cyan-700 border-cyan-200',
             orange: 'bg-orange-50 hover:bg-orange-100 text-orange-700 border-orange-200',
             lime: 'bg-lime-50 hover:bg-lime-100 text-lime-700 border-lime-200',
             fuchsia: 'bg-fuchsia-50 hover:bg-fuchsia-100 text-fuchsia-700 border-fuchsia-200',
             sky: 'bg-sky-50 hover:bg-sky-100 text-sky-700 border-sky-200',
             violet: 'bg-violet-50 hover:bg-violet-100 text-violet-700 border-violet-200',
         };

         function getRandomColor() {
             return presetColors[Math.floor(Math.random() * presetColors.length)];
         }

         function renderCustomPresets(presets) {
             customPresetsContainer.innerHTML = '';
             if (!presets || presets.length === 0) {
                 customPresetsContainer.innerHTML = '<span id="noCustomPresetsMsg" class="text-xs text-gray-400 italic">Chưa có preset tự tạo</span>';
                 return;
             }
             presets.forEach(preset => {
                 const color = preset.color || 'purple';
                 const classes = colorClasses[color] || colorClasses.purple;
                 const wrapper = document.createElement('div');
                 wrapper.className = 'inline-flex items-center gap-0 group';
                 wrapper.dataset.presetId = preset.id;

                 // Preset button (click to use)
                 const btn = document.createElement('button');
                 btn.type = 'button';
                 btn.className = `art-preset-btn custom-preset-btn px-2 py-1 ${classes} rounded-l text-xs font-medium transition border`;
                 btn.dataset.text = preset.description;
                 btn.dataset.presetId = preset.id;
                 btn.textContent = `${preset.icon || '🎨'} ${preset.name}`;
                 btn.addEventListener('click', function() {
                     document.getElementById('artStyleInstruction').value = this.dataset.text;
                 });

                 // Action buttons (edit + delete)
                 const actions = document.createElement('div');
                 actions.className = `inline-flex items-center border border-l-0 ${classes} rounded-r opacity-50 group-hover:opacity-100 transition`;

                 const editBtn = document.createElement('button');
                 editBtn.type = 'button';
                 editBtn.className = 'px-1 py-1 text-xs hover:bg-yellow-100 transition rounded-none';
                 editBtn.title = 'Sửa preset';
                 editBtn.innerHTML = '✏️';
                 editBtn.addEventListener('click', () => openEditPresetForm(preset));

                 const delBtn = document.createElement('button');
                 delBtn.type = 'button';
                 delBtn.className = 'px-1 py-1 text-xs hover:bg-red-100 transition rounded-r';
                 delBtn.title = 'Xoá preset';
                 delBtn.innerHTML = '🗑️';
                 delBtn.addEventListener('click', () => deleteCustomPreset(preset.id, preset.name));

                 actions.appendChild(editBtn);
                 actions.appendChild(delBtn);

                 wrapper.appendChild(btn);
                 wrapper.appendChild(actions);
                 customPresetsContainer.appendChild(wrapper);
             });
         }

         // Load custom presets on page load
         async function loadCustomPresets() {
             try {
                 const response = await fetch('/art-style-presets', {
                     headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
                 });
                 const data = await safeJson(response);
                 if (data.success) {
                     renderCustomPresets(data.presets);
                 }
             } catch (e) {
                 console.error('Failed to load custom presets:', e);
             }
         }
         loadCustomPresets();

         // Show "Add New Preset" form
         document.getElementById('addCustomPresetBtn').addEventListener('click', function() {
             document.getElementById('addPresetForm').classList.remove('hidden');
             document.getElementById('editPresetForm').classList.add('hidden');
             document.getElementById('newPresetName').value = '';
             document.getElementById('newPresetDescription').value = '';
             document.getElementById('newPresetIcon').value = '🎨';
             document.getElementById('newPresetName').focus();
         });

         // Cancel add
         document.getElementById('cancelNewPresetBtn').addEventListener('click', function() {
             document.getElementById('addPresetForm').classList.add('hidden');
         });

         // Save new preset
         document.getElementById('saveNewPresetBtn').addEventListener('click', async function() {
             const name = document.getElementById('newPresetName').value.trim();
             const description = document.getElementById('newPresetDescription').value.trim();
             const icon = document.getElementById('newPresetIcon').value.trim() || '🎨';

             if (!name) { alert('Vui lòng nhập tên preset'); return; }
             if (!description) { alert('Vui lòng nhập mô tả phong cách'); return; }

             const btn = this;
             btn.disabled = true;
             btn.innerHTML = '⏳ Đang lưu...';

             try {
                 const response = await fetch('/art-style-presets', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({ name, description, icon, color: getRandomColor() })
                 });
                 const result = await safeJson(response);
                 if (result.success) {
                     document.getElementById('addPresetForm').classList.add('hidden');
                     loadCustomPresets();
                 } else {
                     throw new Error(result.error || 'Không thể lưu preset');
                 }
             } catch (error) {
                 alert('Lỗi: ' + error.message);
             } finally {
                 btn.disabled = false;
                 btn.innerHTML = '💾 Lưu preset';
             }
         });

         // "Save as Preset" button - saves current textarea content as a new preset
         document.getElementById('saveAsPresetBtn').addEventListener('click', function() {
             const currentText = document.getElementById('artStyleInstruction').value.trim();
             if (!currentText) {
                 alert('Vui lòng nhập nội dung phong cách trước khi lưu thành preset');
                 return;
             }
             document.getElementById('addPresetForm').classList.remove('hidden');
             document.getElementById('editPresetForm').classList.add('hidden');
             document.getElementById('newPresetDescription').value = currentText;
             document.getElementById('newPresetIcon').value = '⭐';
             document.getElementById('newPresetName').value = '';
             document.getElementById('newPresetName').focus();
         });

         // Open Edit Preset form
         function openEditPresetForm(preset) {
             document.getElementById('editPresetForm').classList.remove('hidden');
             document.getElementById('addPresetForm').classList.add('hidden');
             document.getElementById('editPresetId').value = preset.id;
             document.getElementById('editPresetIcon').value = preset.icon || '🎨';
             document.getElementById('editPresetName').value = preset.name;
             document.getElementById('editPresetDescription').value = preset.description;
             document.getElementById('editPresetName').focus();
         }

         // Cancel edit
         document.getElementById('cancelEditPresetBtn').addEventListener('click', function() {
             document.getElementById('editPresetForm').classList.add('hidden');
         });

         // Update preset
         document.getElementById('updatePresetBtn').addEventListener('click', async function() {
             const id = document.getElementById('editPresetId').value;
             const name = document.getElementById('editPresetName').value.trim();
             const description = document.getElementById('editPresetDescription').value.trim();
             const icon = document.getElementById('editPresetIcon').value.trim() || '🎨';

             if (!name) { alert('Vui lòng nhập tên preset'); return; }
             if (!description) { alert('Vui lòng nhập mô tả phong cách'); return; }

             const btn = this;
             btn.disabled = true;
             btn.innerHTML = '⏳ Đang lưu...';

             try {
                 const response = await fetch(`/art-style-presets/${id}`, {
                     method: 'PUT',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({ name, description, icon })
                 });
                 const result = await safeJson(response);
                 if (result.success) {
                     document.getElementById('editPresetForm').classList.add('hidden');
                     loadCustomPresets();
                 } else {
                     throw new Error(result.error || 'Không thể cập nhật');
                 }
             } catch (error) {
                 alert('Lỗi: ' + error.message);
             } finally {
                 btn.disabled = false;
                 btn.innerHTML = '💾 Cập nhật';
             }
         });

         // Delete preset
         async function deleteCustomPreset(id, name) {
             if (!confirm(`Bạn có chắc muốn xoá preset "${name}"?`)) return;
             try {
                 const response = await fetch(`/art-style-presets/${id}`, {
                     method: 'DELETE',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     }
                 });
                 const result = await safeJson(response);
                 if (result.success) {
                     loadCustomPresets();
                 } else {
                     throw new Error(result.error || 'Không thể xoá');
                 }
             } catch (error) {
                 alert('Lỗi: ' + error.message);
             }
         }

         // ========== INTRO/OUTRO MUSIC FUNCTIONS ==========
         function openMusicPickerModal(type) {
             musicPickerTargetType = type === 'outro' ? 'outro' : 'intro';

             const modal = document.getElementById('musicPickerModal');
             const title = document.getElementById('musicPickerModalTitle');

             if (title) {
                 title.textContent = musicPickerTargetType === 'intro' ?
                     '🎵 Chọn file nhạc Intro' :
                     '🎵 Chọn file nhạc Outro';
             }

             renderMusicPickerList();
             modal?.classList.remove('hidden');
         }

         function closeMusicPickerModal() {
             document.getElementById('musicPickerModal')?.classList.add('hidden');
         }

         function triggerMusicUploadFromModal() {
             const inputId = musicPickerTargetType === 'intro' ? 'introMusicFile' : 'outroMusicFile';
             document.getElementById(inputId)?.click();
         }

         function renderMusicPickerList() {
             const container = document.getElementById('musicPickerModalList');
             if (!container) {
                 return;
             }

             if (!Array.isArray(systemMusicFiles) || systemMusicFiles.length === 0) {
                 container.innerHTML =
                     '<p class="text-sm text-gray-500 italic">Chưa có file nhạc nào trên hệ thống. Hãy bấm "Upload file nhạc mới" để thêm.</p>';
                 return;
             }

             const currentPath = musicPickerTargetType === 'intro' ? currentIntroMusicPath : currentOutroMusicPath;

             container.innerHTML = systemMusicFiles.map((file) => {
                 const isCurrent = file.path === currentPath;
                 const currentBadgeClass = musicPickerTargetType === 'intro' ?
                     'bg-green-100 text-green-700' :
                     'bg-orange-100 text-orange-700';
                 const selectBtnClass = musicPickerTargetType === 'intro' ?
                     'bg-green-600 hover:bg-green-700' :
                     'bg-orange-600 hover:bg-orange-700';
                 const bookLabel = file.book_id ? `Sách #${file.book_id}` : 'Không rõ sách';

                 return `
                    <div class="p-3 border border-gray-200 rounded-lg mb-3">
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <div class="min-w-0">
                                <p class="text-sm text-gray-800 font-medium truncate">🎵 ${escapeHtml(file.name || '')}</p>
                                <p class="text-xs text-gray-500 truncate">${escapeHtml(bookLabel)} · ${escapeHtml(file.path || '')}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                ${isCurrent ? `<span class="text-[11px] px-2 py-0.5 rounded ${currentBadgeClass}">Đang dùng</span>` : ''}
                                <button type="button" data-music-path="${encodeURIComponent(file.path || '')}"
                                    class="music-picker-select-btn text-xs text-white px-3 py-1.5 rounded transition ${selectBtnClass}">
                                    Dùng file này
                                </button>
                            </div>
                        </div>
                        <audio controls class="w-full h-8">
                            <source src="${file.url}" type="audio/mpeg">
                        </audio>
                    </div>
                `;
             }).join('');

             container.querySelectorAll('.music-picker-select-btn').forEach((btn) => {
                 btn.addEventListener('click', async () => {
                     const encodedPath = btn.getAttribute('data-music-path');
                     if (!encodedPath) {
                         return;
                     }
                     const path = decodeURIComponent(encodedPath);
                     await selectExistingMusicFile(musicPickerTargetType, path);
                 });
             });
         }

         function toggleOutroUpload() {
             const useIntro = document.getElementById('outroUseIntro').checked;
             const uploadSection = document.getElementById('outroUploadSection');
             const useIntroMessage = document.getElementById('outroUseIntroMessage');

             if (useIntro) {
                 uploadSection.classList.add('hidden');
                 useIntroMessage.classList.remove('hidden');
             } else {
                 uploadSection.classList.remove('hidden');
                 useIntroMessage.classList.add('hidden');
             }
         }

         async function uploadMusic(type) {
             const fileInput = document.getElementById(type === 'intro' ? 'introMusicFile' : 'outroMusicFile');
             const file = fileInput.files[0];

             if (!file) {
                 alert('Vui lòng chọn file nhạc');
                 return;
             }

             // Validate file size (max 20MB)
             if (file.size > 20 * 1024 * 1024) {
                 alert('File quá lớn. Tối đa 20MB');
                 return;
             }

             const formData = new FormData();
             formData.append('music_file', file);
             formData.append('type', type);

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/upload-music`, {
                     method: 'POST',
                     headers: {
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: formData
                 });

                 // Check if response is OK
                 if (!response.ok) {
                     const text = await response.text();
                     let errorMessage = `HTTP ${response.status}`;
                     try {
                         const errorData = JSON.parse(text);
                         errorMessage = errorData.error || errorData.message || errorMessage;
                     } catch (e) {
                         console.error('Upload music error response:', text);
                     }
                     throw new Error(errorMessage);
                 }

                 const result = await response.json();

                 if (result.success) {
                     alert(`✅ Đã tải lên nhạc ${type} thành công!`);
                     location.reload();
                 } else {
                     throw new Error(result.error || 'Không thể tải lên');
                 }
             } catch (error) {
                 console.error('Upload music error:', error);
                 alert('❌ Lỗi tải nhạc ' + type + ': ' + error.message);
             }
         }

         async function deleteMusic(type) {
             if (!confirm(`Bạn có chắc muốn xóa nhạc ${type}?`)) {
                 return;
             }

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/delete-music`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         type: type
                     })
                 });

                 // Check if response is OK
                 if (!response.ok) {
                     const text = await response.text();
                     let errorMessage = `HTTP ${response.status}`;
                     try {
                         const errorData = JSON.parse(text);
                         errorMessage = errorData.error || errorData.message || errorMessage;
                     } catch (e) {
                         console.error('Delete music error response:', text);
                     }
                     throw new Error(errorMessage);
                 }

                 const result = await response.json();

                 if (result.success) {
                     alert(`✅ Đã xóa nhạc ${type}!`);
                     location.reload();
                 } else {
                     throw new Error(result.error || 'Không thể xóa');
                 }
             } catch (error) {
                 console.error('Delete music error:', error);
                 alert('❌ Lỗi xóa nhạc ' + type + ': ' + error.message);
             }
         }

         async function selectExistingMusicFile(type, path) {
             if (!path) {
                 alert('Không có đường dẫn file nhạc');
                 return;
             }

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/select-music-file`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         type,
                         path
                     })
                 });

                 if (!response.ok) {
                     const text = await response.text();
                     let errorMessage = `HTTP ${response.status}`;
                     try {
                         const errorData = JSON.parse(text);
                         errorMessage = errorData.error || errorData.message || errorMessage;
                     } catch (e) {
                         console.error('Select music file error response:', text);
                     }
                     throw new Error(errorMessage);
                 }

                 const result = await response.json();
                 if (!result.success) {
                     throw new Error(result.error || 'Không thể chọn file nhạc');
                 }

                 alert(`✅ Đã chọn file nhạc ${type} thành công!`);
                 location.reload();
             } catch (error) {
                 console.error('Select music file error:', error);
                 alert('❌ Lỗi chọn nhạc ' + type + ': ' + error.message);
             }
         }

         // Save Music Settings
         document.getElementById('saveMusicSettingsBtn')?.addEventListener('click', async function() {
             const btn = this;
             const originalText = btn.innerHTML;
             btn.innerHTML = '⏳ Đang lưu...';
             btn.disabled = true;

             const data = {
                 intro_fade_duration: parseFloat(document.getElementById('introFadeDuration')?.value || 3),
                 outro_fade_duration: parseFloat(document.getElementById('outroFadeDuration')?.value || 10),
                 outro_extend_duration: parseFloat(document.getElementById('outroExtendDuration')?.value ||
                     5),
                 outro_use_intro: document.getElementById('outroUseIntro')?.checked || false
             };

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/update-music-settings`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify(data)
                 });

                 // Check if response is OK
                 if (!response.ok) {
                     const text = await response.text();
                     let errorMessage = `HTTP ${response.status}`;
                     try {
                         const errorData = JSON.parse(text);
                         errorMessage = errorData.error || errorData.message || errorMessage;
                     } catch (e) {
                         console.error('Save music settings error response:', text);
                     }
                     throw new Error(errorMessage);
                 }

                 const result = await response.json();
                 console.log('Music settings saved:', result);

                 if (result.success) {
                     // Check if there are chapters that need re-merge
                     console.log('Chapters to re-merge:', result.remerge_count, result.chapters_to_remerge);

                     if (result.remerge_count > 0) {
                         btn.innerHTML = '✅ Đã lưu!';

                         // Ask user if they want to re-merge
                         const confirmRemerge = confirm(
                             `Đã lưu cấu hình nhạc!\n\n` +
                             `Phát hiện ${result.remerge_count} chương đã có file audio.\n` +
                             `Bạn có muốn merge lại để áp dụng nhạc intro/outro mới không?`
                         );

                         if (confirmRemerge) {
                             await reMergeChaptersWithMusic(result.chapters_to_remerge);
                         }

                         btn.innerHTML = originalText;
                         btn.disabled = false;
                     } else {
                         btn.innerHTML = '✅ Đã lưu!';
                         setTimeout(() => {
                             btn.innerHTML = originalText;
                             btn.disabled = false;
                         }, 2000);
                     }
                 } else {
                     throw new Error(result.error || 'Không thể lưu');
                 }
             } catch (error) {
                 console.error('Save music settings error:', error);
                 alert('❌ Lỗi lưu cấu hình nhạc: ' + error.message);
                 btn.innerHTML = originalText;
                 btn.disabled = false;
             }
         });

         // Re-merge chapters with new music settings
         async function reMergeChaptersWithMusic(chapters) {
             // Show progress container
             const progressContainer = document.getElementById('musicMergeProgressContainer');
             const progressStatus = document.getElementById('musicMergeStatus');
             const progressBar = document.getElementById('musicMergeProgressBar');
             const progressPercent = document.getElementById('musicMergePercent');
             const progressLog = document.getElementById('musicMergeLog');

             if (progressContainer) {
                 progressContainer.classList.remove('hidden');
                 progressLog.innerHTML = '';
             }

             const addLog = (message, type = 'info') => {
                 if (progressLog) {
                     const colors = {
                         info: 'text-blue-600',
                         success: 'text-green-600',
                         error: 'text-red-600'
                     };
                     progressLog.innerHTML +=
                         `<div class="${colors[type]} text-xs">${new Date().toLocaleTimeString()} - ${message}</div>`;
                     progressLog.scrollTop = progressLog.scrollHeight;
                 }
             };

             addLog(`Bắt đầu merge lại ${chapters.length} chương với nhạc mới...`);

             let successCount = 0;
             let failCount = 0;

             for (let i = 0; i < chapters.length; i++) {
                 const chapter = chapters[i];
                 const percent = Math.round(((i + 1) / chapters.length) * 100);

                 if (progressStatus) progressStatus.textContent =
                     `Đang merge chương ${chapter.chapter_number} (${i + 1}/${chapters.length})...`;
                 if (progressBar) progressBar.style.width = `${percent}%`;
                 if (progressPercent) progressPercent.textContent = `${percent}%`;

                 addLog(`Đang merge chương ${chapter.chapter_number}...`);

                 try {
                     const response = await fetch(`/audiobooks/${audioBookId}/chapters/${chapter.id}/merge-audio`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         }
                     });

                     const result = await safeJson(response);

                     if (result.success) {
                         successCount++;
                         addLog(`✅ Chương ${chapter.chapter_number} đã merge xong`, 'success');
                     } else {
                         failCount++;
                         addLog(`❌ Chương ${chapter.chapter_number}: ${result.error || 'Lỗi'}`, 'error');
                     }
                 } catch (error) {
                     failCount++;
                     addLog(`❌ Chương ${chapter.chapter_number}: ${error.message}`, 'error');
                 }
             }

             // Complete
             if (progressStatus) progressStatus.textContent = 'Hoàn tất!';
             if (progressBar) progressBar.style.width = '100%';
             if (progressPercent) progressPercent.textContent = '100%';

             addLog(`Hoàn tất! Thành công: ${successCount}, Thất bại: ${failCount}`, successCount === chapters.length ?
                 'success' : 'info');

             // Auto hide after 5 seconds if all success
             if (failCount === 0) {
                 setTimeout(() => {
                     if (progressContainer) progressContainer.classList.add('hidden');
                 }, 5000);
             }
         }

         // ========== WAVE EFFECT FUNCTIONS ==========
         function toggleWaveSettings() {
             const enabled = document.getElementById('waveEnabled').checked;
             const panel = document.getElementById('waveSettingsPanel');
             if (enabled) {
                 panel.classList.remove('hidden');
             } else {
                 panel.classList.add('hidden');
             }
         }

         // Update opacity display value
         document.getElementById('waveOpacity')?.addEventListener('input', function() {
             document.getElementById('waveOpacityValue').textContent = this.value;
         });

         // Save Wave Settings
         document.getElementById('saveWaveSettingsBtn')?.addEventListener('click', async function() {
             const btn = this;
             const originalText = btn.innerHTML;
             btn.innerHTML = '⏳ Đang lưu...';
             btn.disabled = true;

             const data = {
                 wave_enabled: document.getElementById('waveEnabled')?.checked || false,
                 wave_type: document.querySelector('input[name="waveType"]:checked')?.value || 'cline',
                 wave_position: document.querySelector('input[name="wavePosition"]:checked')?.value ||
                     'bottom',
                 wave_height: parseInt(document.getElementById('waveHeight')?.value || 100),
                 wave_width: parseInt(document.getElementById('waveWidth')?.value || 100),
                 wave_color: document.getElementById('waveColor')?.value || '#00ff00',
                 wave_opacity: parseFloat(document.getElementById('waveOpacity')?.value || 0.8)
             };

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/update-wave-settings`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify(data)
                 });

                 const result = await safeJson(response);

                 if (result.success) {
                     btn.innerHTML = '✅ Đã lưu!';
                     setTimeout(() => {
                         btn.innerHTML = originalText;
                         btn.disabled = false;
                     }, 2000);
                 } else {
                     throw new Error(result.error || 'Không thể lưu');
                 }
             } catch (error) {
                 alert('Lỗi: ' + error.message);
                 btn.innerHTML = originalText;
                 btn.disabled = false;
             }
         });

         // ========== REVIEW ASSETS (2 Phases) ==========
         (function() {
             const audioBookId = {{ $audioBook->id }};
             const csrfToken = '{{ csrf_token() }}';

             // Phase 1 elements
             const startScriptBtn = document.getElementById('startScriptBtn');
             const deleteBtn = document.getElementById('deleteReviewBtn');
             const scriptProgressDiv = document.getElementById('scriptProgress');
             const scriptStageName = document.getElementById('scriptStageName');
             const scriptPercent = document.getElementById('scriptPercent');
             const scriptProgressBar = document.getElementById('scriptProgressBar');
             const scriptDetail = document.getElementById('scriptDetail');
             const scriptPreview = document.getElementById('reviewScriptPreview');
             const scriptTextarea = document.getElementById('reviewScriptTextarea');
             const openReviewStudioBtn = document.getElementById('openReviewStudioBtn');
             const chunksContainer = document.getElementById('reviewChunksContainer');
             const chunksList = document.getElementById('reviewChunksList');
             const chunksCount = document.getElementById('reviewChunksCount');
             const translateAllBtn = document.getElementById('translateAllBtn');

             // Phase 2 elements
             const phase2Container = document.getElementById('phase2Container');
             const startAssetsBtn = document.getElementById('startAssetsBtn');
             const imageProviderSelect = document.getElementById('imageProviderSelect');
             const assetsProgressDiv = document.getElementById('assetsProgress');
             const assetsStageName = document.getElementById('assetsStageName');
             const assetsPercent = document.getElementById('assetsPercent');
             const assetsProgressBar = document.getElementById('assetsProgressBar');
             const assetsDetail = document.getElementById('assetsDetail');

             let scriptPollingTimer = null;
             let assetsPollingTimer = null;

             // ---- Load and render chunks ----
             async function loadChunks() {
                 try {
                     const res = await fetch(`/audiobooks/${audioBookId}/review-video/chunks`);
                     const data = await res.json();
                     if (data.success && data.chunks && data.chunks.length > 0) {
                         renderChunks(data.chunks);
                         chunksContainer.classList.remove('hidden');
                         phase2Container.classList.remove('hidden');
                     }
                 } catch (e) {
                     console.error('Failed to load chunks', e);
                 }
             }

             function renderChunks(chunks) {
                 chunksCount.textContent = `${chunks.length} segments`;
                 chunksList.innerHTML = '';

                 chunks.forEach((chunk, i) => {
                     const card = document.createElement('div');
                     card.className = 'bg-white rounded-lg border border-purple-200 p-3 shadow-sm';
                     card.id = `reviewChunk_${i}`;
                     const chunkProvider = chunk.image_provider || (imageProviderSelect ? imageProviderSelect.value : 'gemini');

                     const hasImage = !!chunk.image_url;
                     const hasAudio = chunk.has_audio;

                     card.innerHTML = `
                         <div class="flex gap-3">
                             <!-- Image thumbnail (only if exists) -->
                             <div class="flex-shrink-0 w-28">
                                 ${hasImage
                                     ? `<div class="relative group cursor-pointer" onclick="document.getElementById('reviewImageModal').classList.remove('hidden'); document.getElementById('reviewImageModal').classList.add('flex'); document.getElementById('reviewImageModalImg').src=this.querySelector('img')?.src || '';">
                                                   <img src="${chunk.image_url}" class="w-28 h-18 object-cover rounded border border-purple-200 hover:border-purple-400 transition" alt="Segment ${i + 1}">
                                                 </div>`
                                     : `<div class="w-28 h-18 bg-gray-50 rounded border border-dashed border-gray-300 flex items-center justify-center text-gray-400 text-[10px]">Chưa có ảnh</div>`
                                 }
                                 <div class="flex gap-1 mt-1">
                                     <span class="text-[10px] ${hasImage ? 'text-green-600' : 'text-gray-400'}">img ${hasImage ? '✓' : '✗'}</span>
                                     <span class="text-[10px] ${hasAudio ? 'text-green-600' : 'text-gray-400'}">audio ${hasAudio ? '✓' : '✗'}</span>
                                     ${chunk.audio_duration ? `<span class="text-[10px] text-purple-500">${Math.round(chunk.audio_duration)}s</span>` : ''}
                                 </div>
                             </div>

                             <!-- Content -->
                             <div class="flex-1 min-w-0">
                                 <div class="flex items-center gap-2 mb-1">
                                     <span class="text-[11px] font-semibold text-purple-600">Segment ${i + 1}</span>
                                 </div>

                                 <!-- Text (readonly) -->
                                 <div class="text-[11px] text-gray-600 mb-2 line-clamp-2" title="${(chunk.text || '').replace(/"/g, '&quot;')}">${chunk.text || ''}</div>

                                 <!-- Image prompt (editable) -->
                                 <div class="mb-1">
                                     <label class="text-[10px] font-medium text-purple-500 uppercase tracking-wide">Image Prompt</label>
                                     <textarea id="reviewPrompt_${i}" rows="2"
                                         class="w-full px-2 py-1 border border-purple-200 rounded text-[11px] text-gray-700 resize-y focus:border-purple-400 focus:ring-1 focus:ring-purple-200 mt-0.5"
                                     >${chunk.image_prompt || ''}</textarea>
                                 </div>

                                 <!-- Action buttons -->
                                 <div class="flex gap-1.5 flex-wrap">
                                     <button type="button" onclick="reviewSavePrompt(${i})"
                                         class="text-[10px] bg-purple-50 hover:bg-purple-100 text-purple-700 px-2 py-0.5 rounded border border-purple-200 transition">
                                         💾 Lưu
                                     </button>
                                     <button type="button" onclick="reviewTranslatePrompt(${i})"
                                         class="text-[10px] bg-blue-50 hover:bg-blue-100 text-blue-700 px-2 py-0.5 rounded border border-blue-200 transition">
                                         🔄 Dịch EN↔VI
                                     </button>
                                     <button type="button" onclick="reviewSplitChunk(${i})"
                                         class="text-[10px] bg-orange-50 hover:bg-orange-100 text-orange-700 px-2 py-0.5 rounded border border-orange-200 transition">
                                         ✂️ Tách
                                     </button>
                                     <select id="reviewImgProvider_${i}" class="text-[10px] border border-purple-200 rounded px-1 py-0.5 bg-white text-gray-600">
                                         <option value="gemini" ${chunkProvider === 'gemini' ? 'selected' : ''}>Gemini</option>
                                         <option value="flux" ${chunkProvider === 'flux' ? 'selected' : ''}>Flux</option>
                                     </select>
                                     <button type="button" onclick="reviewRegenerateImage(${i})"
                                         class="text-[10px] bg-amber-50 hover:bg-amber-100 text-amber-700 px-2 py-0.5 rounded border border-amber-200 transition">
                                         🖼️ ${hasImage ? 'Tạo lại ảnh' : 'Tạo ảnh'}
                                     </button>
                                 </div>
                                 <div id="reviewChunkStatus_${i}" class="text-[10px] text-gray-500 mt-1 hidden"></div>

                                 <!-- Split area (hidden by default) -->
                                 <div id="reviewSplitArea_${i}" class="hidden mt-2 p-2 bg-orange-50 rounded border border-orange-200">
                                     <label class="text-[10px] text-orange-700 font-medium">Thêm dấu --- tại chỗ muốn tách:</label>
                                     <textarea id="reviewSplitText_${i}" rows="4"
                                         class="w-full px-2 py-1 border border-orange-200 rounded text-[11px] text-gray-700 resize-y mt-1"
                                     >${chunk.text || ''}</textarea>
                                     <div class="flex gap-1 mt-1">
                                         <button type="button" onclick="reviewDoSplit(${i})"
                                             class="text-[10px] bg-orange-600 hover:bg-orange-700 text-white px-2 py-0.5 rounded transition">
                                             Tách segment
                                         </button>
                                         <button type="button" onclick="document.getElementById('reviewSplitArea_${i}').classList.add('hidden')"
                                             class="text-[10px] bg-gray-200 hover:bg-gray-300 text-gray-700 px-2 py-0.5 rounded transition">
                                             Hủy
                                         </button>
                                     </div>
                                 </div>
                             </div>
                         </div>
                     `;
                     chunksList.appendChild(card);
                 });
             }

             // ---- Save prompt ----
             window.reviewSavePrompt = async function(index) {
                 const textarea = document.getElementById(`reviewPrompt_${index}`);
                 const statusDiv = document.getElementById(`reviewChunkStatus_${index}`);
                 const prompt = textarea.value.trim();
                 if (!prompt) return;

                 statusDiv.classList.remove('hidden');
                 statusDiv.innerHTML = '<span class="text-purple-600">Đang lưu...</span>';

                 try {
                     const res = await fetch(`/audiobooks/${audioBookId}/review-video/chunks/${index}/prompt`, {
                         method: 'PUT',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': csrfToken
                         },
                         body: JSON.stringify({
                             image_prompt: prompt
                         })
                     });
                     const data = await res.json();
                     statusDiv.innerHTML = data.success ?
                         '<span class="text-green-600">✓ Đã lưu</span>' :
                         `<span class="text-red-600">✗ ${data.error || 'Lỗi'}</span>`;
                 } catch (e) {
                     statusDiv.innerHTML = `<span class="text-red-600">✗ ${e.message}</span>`;
                 }
                 setTimeout(() => statusDiv.classList.add('hidden'), 3000);
             };

             // ---- Translate prompt ----
             window.reviewTranslatePrompt = async function(index) {
                 const textarea = document.getElementById(`reviewPrompt_${index}`);
                 const statusDiv = document.getElementById(`reviewChunkStatus_${index}`);
                 const prompt = textarea.value.trim();
                 if (!prompt) return;

                 statusDiv.classList.remove('hidden');
                 statusDiv.innerHTML = '<span class="text-blue-600">Đang dịch...</span>';

                 try {
                     // Save first
                     await fetch(`/audiobooks/${audioBookId}/review-video/chunks/${index}/prompt`, {
                         method: 'PUT',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': csrfToken
                         },
                         body: JSON.stringify({
                             image_prompt: prompt
                         })
                     });

                     const res = await fetch(
                         `/audiobooks/${audioBookId}/review-video/chunks/${index}/translate-prompt`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': csrfToken
                             },
                             body: JSON.stringify({})
                         });
                     const data = await res.json();
                     if (data.success) {
                         textarea.value = data.translated;
                         const dir = data.direction === 'en_to_vi' ? 'EN → VI' : 'VI → EN';
                         statusDiv.innerHTML = `<span class="text-green-600">✓ Đã dịch (${dir})</span>`;
                     } else {
                         statusDiv.innerHTML = `<span class="text-red-600">✗ ${data.error || 'Lỗi'}</span>`;
                     }
                 } catch (e) {
                     statusDiv.innerHTML = `<span class="text-red-600">✗ ${e.message}</span>`;
                 }
                 setTimeout(() => statusDiv.classList.add('hidden'), 4000);
             };

             // ---- Split chunk ----
             window.reviewSplitChunk = function(index) {
                 document.getElementById(`reviewSplitArea_${index}`).classList.toggle('hidden');
             };

             window.reviewDoSplit = async function(index) {
                 const textarea = document.getElementById(`reviewSplitText_${index}`);
                 const statusDiv = document.getElementById(`reviewChunkStatus_${index}`);
                 const text = textarea.value.trim();

                 if (!text.includes('---')) {
                     alert('Hãy thêm dấu --- tại vị trí muốn tách.');
                     return;
                 }

                 statusDiv.classList.remove('hidden');
                 statusDiv.innerHTML = '<span class="text-orange-600">Đang tách...</span>';

                 try {
                     const res = await fetch(`/audiobooks/${audioBookId}/review-video/chunks/${index}/split`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': csrfToken
                         },
                         body: JSON.stringify({
                             text_with_delimiters: text
                         })
                     });
                     const data = await res.json();
                     if (data.success) {
                         statusDiv.innerHTML = '<span class="text-green-600">✓ Đã tách</span>';
                         await loadChunks(); // Reload all
                     } else {
                         statusDiv.innerHTML = `<span class="text-red-600">✗ ${data.error || 'Lỗi'}</span>`;
                     }
                 } catch (e) {
                     statusDiv.innerHTML = `<span class="text-red-600">✗ ${e.message}</span>`;
                 }
             };

             // ---- Regenerate image ----
             window.reviewRegenerateImage = async function(index) {
                 const textarea = document.getElementById(`reviewPrompt_${index}`);
                 const statusDiv = document.getElementById(`reviewChunkStatus_${index}`);
                 const segProvider = document.getElementById(`reviewImgProvider_${index}`);
                 const provider = segProvider ? segProvider.value : (imageProviderSelect ? imageProviderSelect
                     .value : 'gemini');
                 const providerName = provider === 'flux' ? 'Flux' : 'Gemini';
                 if (!confirm(`Tạo ảnh cho Segment ${index + 1} bằng ${providerName}?`)) return;

                 statusDiv.classList.remove('hidden');
                 statusDiv.innerHTML =
                     `<span class="text-amber-600">Đang lưu prompt & tạo ảnh (${providerName})...</span>`;

                 try {
                     await fetch(`/audiobooks/${audioBookId}/review-video/chunks/${index}/prompt`, {
                         method: 'PUT',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': csrfToken
                         },
                         body: JSON.stringify({
                             image_prompt: textarea.value.trim()
                         })
                     });

                     const res = await fetch(
                         `/audiobooks/${audioBookId}/review-video/chunks/${index}/regenerate-image`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': csrfToken
                             },
                             body: JSON.stringify({
                                 image_provider: provider
                             })
                         });
                     const data = await res.json();
                     if (data.success) {
                         statusDiv.innerHTML = '<span class="text-green-600">✓ Ảnh đã được tạo lại</span>';
                         await loadChunks();
                     } else {
                         statusDiv.innerHTML = `<span class="text-red-600">✗ ${data.error || 'Lỗi'}</span>`;
                     }
                 } catch (e) {
                     statusDiv.innerHTML = `<span class="text-red-600">✗ ${e.message}</span>`;
                 }
                 setTimeout(() => statusDiv.classList.add('hidden'), 4000);
             };

             // ---- Translate All ----
             if (translateAllBtn) {
                 translateAllBtn.addEventListener('click', async function() {
                     if (!confirm('Dịch tất cả prompts sang ngôn ngữ đối? (Mất vài phút)')) return;
                     translateAllBtn.disabled = true;
                     translateAllBtn.textContent = '⏳ Đang dịch...';

                     try {
                         const res = await fetch(`/audiobooks/${audioBookId}/review-video/translate-all`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': csrfToken
                             },
                             body: JSON.stringify({})
                         });
                         const data = await res.json();
                         if (data.success) {
                             translateAllBtn.textContent = `✓ Đã dịch ${data.translated} prompts`;
                             await loadChunks();
                         } else {
                             alert(data.error || 'Lỗi');
                         }
                     } catch (e) {
                         alert(e.message);
                     } finally {
                         setTimeout(() => {
                             translateAllBtn.disabled = false;
                             translateAllBtn.textContent = '🔄 Dịch tất cả VI→EN';
                         }, 3000);
                     }
                 });
             }

             // ---- Phase 1: Polling for script generation ----
             function startScriptPolling() {
                 scriptProgressDiv.classList.remove('hidden');
                 scriptPollingTimer = setInterval(async () => {
                     try {
                         const res = await fetch(`/audiobooks/${audioBookId}/review-video/progress`);
                         const p = await res.json();

                         if (p.status === 'processing') {
                             scriptStageName.textContent =
                                 `Stage ${p.stage || 1}/${p.total_stages || 2} - ${p.stage_name || ''}`;
                             scriptPercent.textContent = `${p.percent || 0}%`;
                             scriptProgressBar.style.width = `${p.percent || 0}%`;
                             scriptDetail.textContent = p.detail || '';

                             if (p.stage > 1 && scriptTextarea.value === '') {
                                 scriptPreview.classList.remove('hidden');
                             }
                         } else if (p.status === 'completed') {
                             clearInterval(scriptPollingTimer);
                             scriptPollingTimer = null;
                             scriptProgressBar.style.width = '100%';
                             scriptPercent.textContent = '100%';
                             scriptStageName.textContent = 'Hoàn thành!';
                             scriptDetail.textContent = p.detail || '';
                             scriptPreview.classList.remove('hidden');
                             deleteBtn.classList.remove('hidden');

                             await loadChunks();

                             startScriptBtn.textContent = '🚀 Tạo Kịch Bản';
                             startScriptBtn.disabled = false;
                             setTimeout(() => scriptProgressDiv.classList.add('hidden'), 5000);
                         } else if (p.status === 'error') {
                             clearInterval(scriptPollingTimer);
                             scriptPollingTimer = null;
                             scriptDetail.innerHTML =
                                 `<span class="text-red-600">❌ ${p.message || 'Lỗi'}</span>`;
                             startScriptBtn.textContent = '🚀 Tạo Kịch Bản';
                             startScriptBtn.disabled = false;
                         }
                     } catch (e) {
                         /* keep polling */
                     }
                 }, 3000);
             }

             // Phase 1: Start script button
             if (startScriptBtn) {
                 startScriptBtn.addEventListener('click', async function() {
                     if (!confirm('Tạo kịch bản review sách? Quá trình có thể mất 10-20 phút.')) return;
                     startScriptBtn.textContent = '⏳ Đang xử lý...';
                     startScriptBtn.disabled = true;

                     try {
                         const response = await fetch(`/audiobooks/${audioBookId}/review-video/start`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': csrfToken
                             },
                             body: JSON.stringify({})
                         });
                         const result = await safeJson(response);
                         if (result.success && result.queued) {
                             startScriptPolling();
                         } else {
                             throw new Error(result.error || 'Không thể bắt đầu');
                         }
                     } catch (error) {
                         scriptDetail.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                         startScriptBtn.textContent = '🚀 Tạo Kịch Bản';
                         startScriptBtn.disabled = false;
                     }
                 });
             }

             // ---- Phase 2: Polling for assets generation ----
             function startAssetsPolling() {
                 assetsProgressDiv.classList.remove('hidden');
                 assetsPollingTimer = setInterval(async () => {
                     try {
                         const res = await fetch(`/audiobooks/${audioBookId}/review-video/assets-progress`);
                         const p = await res.json();

                         if (p.status === 'processing') {
                             assetsStageName.textContent =
                                 `Stage ${p.stage || 1}/${p.total_stages || 2} - ${p.stage_name || ''}`;
                             assetsPercent.textContent = `${p.percent || 0}%`;
                             assetsProgressBar.style.width = `${p.percent || 0}%`;
                             assetsDetail.textContent = p.detail || '';
                         } else if (p.status === 'completed') {
                             clearInterval(assetsPollingTimer);
                             assetsPollingTimer = null;
                             assetsProgressBar.style.width = '100%';
                             assetsPercent.textContent = '100%';
                             assetsStageName.textContent = 'Hoàn thành!';
                             assetsDetail.textContent = p.detail || '';

                             await loadChunks(); // Reload with images

                             startAssetsBtn.textContent = '🖼️ Tạo Ảnh & Audio';
                             startAssetsBtn.disabled = false;
                             setTimeout(() => assetsProgressDiv.classList.add('hidden'), 5000);
                         } else if (p.status === 'error') {
                             clearInterval(assetsPollingTimer);
                             assetsPollingTimer = null;
                             assetsDetail.innerHTML =
                                 `<span class="text-red-600">❌ ${p.message || 'Lỗi'}</span>`;
                             startAssetsBtn.textContent = '🖼️ Tạo Ảnh & Audio';
                             startAssetsBtn.disabled = false;
                         }
                     } catch (e) {
                         /* keep polling */
                     }
                 }, 3000);
             }

             // Phase 2: Start assets button
             if (startAssetsBtn) {
                 startAssetsBtn.addEventListener('click', async function() {
                     const selectedProvider = imageProviderSelect ? imageProviderSelect.value : 'gemini';
                     const providerName = selectedProvider === 'flux' ? 'Flux' :
                         'Gemini';
                     if (!confirm(`Tạo ảnh (${providerName}) và audio TTS cho tất cả segments?`)) return;
                     startAssetsBtn.textContent = '⏳ Đang xử lý...';
                     startAssetsBtn.disabled = true;

                     try {
                         const response = await fetch(
                             `/audiobooks/${audioBookId}/review-video/generate-assets`, {
                                 method: 'POST',
                                 headers: {
                                     'Content-Type': 'application/json',
                                     'X-CSRF-TOKEN': csrfToken
                                 },
                                 body: JSON.stringify({
                                     image_provider: selectedProvider
                                 })
                             });
                         const result = await safeJson(response);
                         if (result.success && result.queued) {
                             startAssetsPolling();
                         } else {
                             throw new Error(result.error || 'Không thể bắt đầu');
                         }
                     } catch (error) {
                         assetsDetail.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                         startAssetsBtn.textContent = '🖼️ Tạo Ảnh & Audio';
                         startAssetsBtn.disabled = false;
                     }
                 });
             }

             // ---- Copy script button ----
             const copyScriptBtn = document.getElementById('copyScriptBtn');
             if (copyScriptBtn) {
                 copyScriptBtn.addEventListener('click', function() {
                     const text = scriptTextarea.value;
                     if (!text) return;
                     navigator.clipboard.writeText(text).then(() => {
                         const orig = copyScriptBtn.innerHTML;
                         copyScriptBtn.innerHTML = '✅ Đã sao chép!';
                         setTimeout(() => { copyScriptBtn.innerHTML = orig; }, 2000);
                     }).catch(() => {
                         scriptTextarea.select();
                         document.execCommand('copy');
                         const orig = copyScriptBtn.innerHTML;
                         copyScriptBtn.innerHTML = '✅ Đã sao chép!';
                         setTimeout(() => { copyScriptBtn.innerHTML = orig; }, 2000);
                     });
                 });
             }

             // ---- Open sentence studio from review script ----
             if (openReviewStudioBtn) {
                 openReviewStudioBtn.addEventListener('click', async function() {
                     const reviewScript = scriptTextarea ? scriptTextarea.value.trim() : '';
                     if (!reviewScript) {
                         alert('Chưa có kịch bản review để mở Studio câu.');
                         return;
                     }

                     const originalHtml = openReviewStudioBtn.innerHTML;
                     openReviewStudioBtn.disabled = true;
                     openReviewStudioBtn.innerHTML = '⏳ Đang mở Studio...';

                     try {
                         const response = await fetch(`/audiobooks/${audioBookId}/review-video/open-studio`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': csrfToken,
                                 'Accept': 'application/json'
                             },
                             body: JSON.stringify({})
                         });
                         const result = await safeJson(response);
                         const targetIndex = parseInt(result.short_index || '0', 10);

                         if (!result.success || !targetIndex) {
                             throw new Error(result.error || 'Không thể mở Studio câu.');
                         }

                         if (Array.isArray(result.items)) {
                             shortVideoItems = result.items;
                             renderShortVideos(shortVideoItems);
                         }

                         await openShortWorkspaceModal(targetIndex);
                     } catch (error) {
                         alert(error.message || 'Không thể mở Studio câu.');
                     } finally {
                         openReviewStudioBtn.disabled = false;
                         openReviewStudioBtn.innerHTML = originalHtml;
                     }
                 });
             }

             // ---- Delete button ----
             if (deleteBtn) {
                 deleteBtn.addEventListener('click', async function() {
                     if (!confirm('Xóa toàn bộ review assets (kịch bản, ảnh, audio)?')) return;
                     deleteBtn.disabled = true;

                     try {
                         const response = await fetch(`/audiobooks/${audioBookId}/review-video`, {
                             method: 'DELETE',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': csrfToken
                             }
                         });
                         const result = await safeJson(response);
                         if (result.success) {
                             chunksContainer.classList.add('hidden');
                             phase2Container.classList.add('hidden');
                             chunksList.innerHTML = '';
                             scriptPreview.classList.add('hidden');
                             scriptTextarea.value = '';
                             deleteBtn.classList.add('hidden');
                         } else {
                             throw new Error(result.error || 'Không thể xóa');
                         }
                     } catch (error) {
                         alert(error.message);
                     } finally {
                         deleteBtn.disabled = false;
                     }
                 });
             }

             // Load existing chunks on page load
             loadChunks();
         })();

         // ========== FULL BOOK VIDEO ==========
         function setupFullBookVideo() {
             const generateBtn = document.getElementById('generateFullBookVideoBtn');
             const loadMediaBtn = document.getElementById('loadFullBookMediaBtn');
             const mediaGrid = document.getElementById('fullBookMediaGrid');
             const mediaEmpty = document.getElementById('fullBookMediaEmpty');
             const selectedImagePreview = document.getElementById('fullBookSelectedImagePreview');
             const selectedImageImg = document.getElementById('fullBookSelectedImageImg');
             const selectedImageName = document.getElementById('fullBookSelectedImageName');
             const clearImageBtn = document.getElementById('fullBookClearImageBtn');
             const progressContainer = document.getElementById('fullBookVideoProgress');
             const progressBar = document.getElementById('fullBookVideoProgressBar');
             const progressPercent = document.getElementById('fullBookVideoProgressPercent');
             const progressLabel = document.getElementById('fullBookVideoProgressLabel');
             const logContainer = document.getElementById('fullBookVideoLog');
             const logContent = document.getElementById('fullBookVideoLogContent');
             const statusDiv = document.getElementById('fullBookVideoStatus');
             const videoContainer = document.getElementById('fullBookVideoContainer');
             const videoPlayer = document.getElementById('fullBookVideoPlayer');
             const videoDuration = document.getElementById('fullBookVideoDuration');
             const deleteVideoBtn = document.getElementById('deleteFullBookVideoBtn');

             let selectedFullBookImage = null;
             let fullBookProgressTimer = null;

             function setProgress(value, label) {
                 value = Math.min(100, Math.max(0, value));
                 if (progressBar) progressBar.style.width = `${value}%`;
                 if (progressPercent) progressPercent.textContent = `${Math.round(value)}%`;
                 if (label && progressLabel) progressLabel.textContent = label;
             }

             async function fetchProgress() {
                 try {
                     const response = await fetch(`/audiobooks/${audioBookId}/fullbook-video-progress`, {
                         headers: {
                             'X-CSRF-TOKEN': getCsrfToken()
                         }
                     });
                     const result = await safeJson(response);

                     if (!result.success || result.status === 'idle') return;

                     setProgress(result.percent ?? 0, result.message || 'Đang xử lý...');

                     if (logContainer && logContent) {
                         const logs = Array.isArray(result.logs) ? result.logs : [];
                         logContent.textContent = logs.join('\n');
                         if (logs.length > 0) {
                             logContainer.classList.remove('hidden');
                             logContent.scrollTop = logContent.scrollHeight;
                         }
                     }

                     if (result.status === 'completed') {
                         stopPolling();
                         finishProgress(true);
                         if (result.video_url && videoPlayer && videoContainer) {
                             const refreshedUrl =
                                 `${result.video_url}${result.video_url.includes('?') ? '&' : '?'}t=${Date.now()}`;
                             videoPlayer.src = refreshedUrl;
                             videoPlayer.load();
                             if (result.video_duration) {
                                 const h = Math.floor(result.video_duration / 3600);
                                 const m = Math.floor((result.video_duration % 3600) / 60);
                                 const s = Math.floor(result.video_duration % 60);
                                 videoDuration.textContent = h > 0 ?
                                     `${h}:${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}` :
                                     `${m}:${s.toString().padStart(2,'0')}`;
                             }
                             videoContainer.classList.remove('hidden');
                         }
                         if (statusDiv) statusDiv.innerHTML =
                             '<span class="text-green-600">✅ Video full book đã tạo xong!</span>';
                     }

                     if (result.status === 'error') {
                         stopPolling();
                         finishProgress(false);
                         if (statusDiv) statusDiv.innerHTML =
                             `<span class="text-red-600">❌ ${result.message || 'Lỗi'}</span>`;
                     }
                 } catch (error) {
                     // transient error, keep polling
                 }
             }

             function startPolling() {
                 if (!progressContainer) return;
                 stopPolling();
                 progressContainer.classList.remove('hidden');
                 setProgress(1, 'Đang chờ tiến trình từ server...');
                 if (logContent) logContent.textContent = '';
                 if (logContainer) logContainer.classList.remove('hidden');
                 fetchProgress();
                 fullBookProgressTimer = setInterval(fetchProgress, 2000);
             }

             function stopPolling() {
                 if (fullBookProgressTimer) {
                     clearInterval(fullBookProgressTimer);
                     fullBookProgressTimer = null;
                 }
             }

             function finishProgress(success) {
                 if (!progressContainer) return;
                 stopPolling();
                 if (success) {
                     setProgress(100, 'Hoàn tất!');
                     progressBar.classList.remove('bg-rose-500');
                     progressBar.classList.add('bg-green-500');
                 } else {
                     progressBar.classList.remove('bg-rose-500');
                     progressBar.classList.add('bg-red-500');
                 }
             }

             // Load media images
             if (loadMediaBtn) {
                 loadMediaBtn.addEventListener('click', async function() {
                     const btn = this;
                     btn.disabled = true;
                     btn.innerHTML = '⏳ Đang tải...';
                     try {
                         const response = await fetch(`/audiobooks/${audioBookId}/media`, {
                             headers: {
                                 'X-CSRF-TOKEN': getCsrfToken()
                             }
                         });
                         const result = await safeJson(response);
                         if (result.success && result.media) {
                             const allImages = [];
                             if (result.media.thumbnails) {
                                 result.media.thumbnails.forEach(img => {
                                     allImages.push({
                                         filename: img.filename,
                                         type: 'thumbnails',
                                         url: img.url
                                     });
                                 });
                             }
                             if (result.media.scenes) {
                                 result.media.scenes.forEach(img => {
                                     allImages.push({
                                         filename: img.filename,
                                         type: 'scenes',
                                         url: img.url
                                     });
                                 });
                             }
                             if (allImages.length === 0) {
                                 mediaGrid.classList.add('hidden');
                                 mediaEmpty.classList.remove('hidden');
                             } else {
                                 mediaEmpty.classList.add('hidden');
                                 mediaGrid.classList.remove('hidden');
                                 mediaGrid.innerHTML = '';
                                 allImages.forEach(img => {
                                     const div = document.createElement('div');
                                     div.className =
                                         'cursor-pointer border-2 border-transparent hover:border-rose-400 rounded-lg overflow-hidden transition';
                                     div.innerHTML = `
                                        <img src="${img.url}" alt="${img.filename}" class="w-full h-16 object-cover">
                                        <div class="absolute bottom-0 left-0 right-0 bg-black/50 text-white text-[9px] px-1 py-0.5 truncate">${img.type === 'thumbnails' ? '📷' : '🎬'} ${img.filename}</div>
                                    `;
                                     div.addEventListener('click', () => {
                                         selectedFullBookImage = {
                                             filename: img.filename,
                                             type: img.type,
                                             url: img.url
                                         };
                                         selectedImageImg.src = img.url;
                                         selectedImageName.textContent = img.filename;
                                         selectedImagePreview.classList.remove('hidden');
                                         mediaGrid.querySelectorAll('div').forEach(d => {
                                             d.classList.remove('border-rose-500');
                                             d.classList.add('border-transparent');
                                         });
                                         div.classList.remove('border-transparent');
                                         div.classList.add('border-rose-500');
                                     });
                                     mediaGrid.appendChild(div);
                                 });
                             }
                         }
                     } catch (e) {
                         if (statusDiv) statusDiv.innerHTML = `<span class="text-red-600">❌ ${e.message}</span>`;
                     } finally {
                         btn.disabled = false;
                         btn.innerHTML = '🔄 Tải thư viện';
                     }
                 });
             }

             // Clear selected image
             if (clearImageBtn) {
                 clearImageBtn.addEventListener('click', () => {
                     selectedFullBookImage = null;
                     selectedImagePreview.classList.add('hidden');
                     mediaGrid.querySelectorAll('div').forEach(d => d.classList.remove('border-rose-500'));
                 });
             }

             // Generate full book video
             if (generateBtn) {
                 generateBtn.addEventListener('click', async function() {
                     if (!selectedFullBookImage) {
                         alert('Vui lòng chọn ảnh trước (nhấn "🔄 Tải thư viện" rồi chọn ảnh).');
                         return;
                     }

                     if (!confirm(
                             'Tạo video full book (ghép giới thiệu + tất cả chương)?\nQuá trình có thể mất rất lâu tùy tổng thời lượng sách.'
                         )) return;

                     const btn = this;
                     const originalText = btn.innerHTML;
                     btn.innerHTML = '⏳ Đang gửi yêu cầu...';
                     btn.disabled = true;

                     if (statusDiv) statusDiv.innerHTML =
                         '<span class="text-blue-600">🎬 Đang gửi yêu cầu tạo video full book...</span>';
                     startPolling();

                     try {
                         const response = await fetch(
                             `/audiobooks/${audioBookId}/generate-fullbook-video-async`, {
                                 method: 'POST',
                                 headers: {
                                     'Content-Type': 'application/json',
                                     'X-CSRF-TOKEN': getCsrfToken()
                                 },
                                 body: JSON.stringify({
                                     image_path: selectedFullBookImage.filename,
                                     image_type: selectedFullBookImage.type
                                 })
                             });

                         const result = await safeJson(response);
                         if (!result.success) {
                             throw new Error(result.error || 'Không thể tạo video');
                         }

                         if (statusDiv) statusDiv.innerHTML =
                             '<span class="text-blue-600">🎬 Đã nhận yêu cầu. Đang xử lý ở server...</span>';
                     } catch (error) {
                         if (statusDiv) statusDiv.innerHTML =
                             `<span class="text-red-600">❌ ${error.message}</span>`;
                         stopPolling();
                         finishProgress(false);
                     } finally {
                         btn.innerHTML = originalText;
                         btn.disabled = false;
                     }
                 });
             }

             // Delete full book video
             if (deleteVideoBtn) {
                 deleteVideoBtn.addEventListener('click', async function() {
                     if (!confirm('Bạn có chắc muốn xóa video full book?')) return;
                     const btn = this;
                     btn.disabled = true;
                     try {
                         const response = await fetch(`/audiobooks/${audioBookId}/delete-fullbook-video`, {
                             method: 'DELETE',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': getCsrfToken()
                             }
                         });
                         const result = await safeJson(response);
                         if (result.success) {
                             if (statusDiv) statusDiv.innerHTML =
                                 '<span class="text-green-600">✅ Đã xóa video!</span>';
                             videoContainer.classList.add('hidden');
                             videoPlayer.src = '';
                             videoDuration.textContent = '';
                             setTimeout(() => {
                                 if (statusDiv) statusDiv.innerHTML = '';
                             }, 3000);
                         } else {
                             throw new Error(result.error || 'Không thể xóa');
                         }
                     } catch (error) {
                         if (statusDiv) statusDiv.innerHTML =
                             `<span class="text-red-600">❌ ${error.message}</span>`;
                     } finally {
                         btn.disabled = false;
                     }
                 });
             }
         }

         // ========== VIDEO SEGMENTS (BATCH) ==========
         function setupVideoSegments() {
             const toggleBtn = document.getElementById('videoSegmentsToggleBtn');
             const toggleIcon = document.getElementById('videoSegmentsToggleIcon');
             const toggleText = document.getElementById('videoSegmentsToggleText');
             const segmentsContent = document.getElementById('videoSegmentsContent');
             const addBtn = document.getElementById('addSegmentBtn');
             const saveBtn = document.getElementById('saveSegmentsBtn');
             const startBtn = document.getElementById('startBatchBtn');
             const segmentList = document.getElementById('segmentList');
             const emptyState = document.getElementById('segmentEmptyState');
             const statusDiv = document.getElementById('segmentPlannerStatus');
             const batchProgress = document.getElementById('batchProgress');
             const batchProgressBar = document.getElementById('batchProgressBar');
             const batchProgressPercent = document.getElementById('batchProgressPercent');
             const batchProgressLabel = document.getElementById('batchProgressLabel');
             const batchLogContainer = document.getElementById('batchLogContainer');
             const batchLogContent = document.getElementById('batchLogContent');

             if (toggleBtn && toggleIcon && toggleText && segmentsContent) {
                 toggleBtn.addEventListener('click', function() {
                     const isHidden = segmentsContent.classList.toggle('hidden');
                     toggleIcon.textContent = isHidden ? '▸' : '▾';
                     toggleText.textContent = isHidden ? 'Mở rộng' : 'Thu gọn';
                     toggleBtn.setAttribute('aria-expanded', isHidden ? 'false' : 'true');
                 });
             }

             // Available chapters from the audiobook (including intro as chapter 0)
             @php
                 $chaptersJson = collect();
                 // Add introduction as chapter 0
                 if ($audioBook->description_audio_duration) {
                     $chaptersJson->push([
                         'id' => 0,
                         'number' => 0,
                         'title' => 'Giới thiệu',
                         'duration' => round($audioBook->description_audio_duration, 1),
                     ]);
                 }
                 foreach ($audioBook->chapters as $c) {
                     $chaptersJson->push([
                         'id' => $c->id,
                         'number' => $c->chapter_number,
                         'title' => $c->title,
                         'duration' => round($c->total_duration ?? 0, 1),
                     ]);
                 }
                 $segmentsJson = $audioBook->videoSegments->map(function ($s) {
                     return [
                         'id' => $s->id,
                         'name' => $s->name,
                         'chapters' => $s->chapters,
                         'image_path' => $s->image_path,
                         'image_type' => $s->image_type,
                         'video_path' => $s->video_path,
                         'video_duration' => $s->video_duration,
                         'video_url' => $s->video_path ? asset('storage/' . $s->video_path) : null,
                         'status' => $s->status,
                         'error_message' => $s->error_message,
                         'sort_order' => $s->sort_order,
                     ];
                 });
             @endphp
             const availableChapters = {!! json_encode($chaptersJson) !!};

             // Initial segments from DB
             let segments = {!! json_encode($segmentsJson) !!};

             let segmentMediaCache = {}; // cache loaded media images
             let batchTimer = null;

             // Select all checkbox
             const selectAllCb = document.getElementById('segSelectAll');
             selectAllCb.addEventListener('change', function() {
                 segments.forEach(s => s._selected = this.checked);
                 renderSegments();
             });

             function renderSegments() {
                 segmentList.innerHTML = '';
                 if (segments.length === 0) {
                     emptyState.classList.remove('hidden');
                     return;
                 }
                 emptyState.classList.add('hidden');

                 // Collect chapters used by previous segments (for disabling)
                 const usedChaptersBefore = [];
                 segments.forEach((seg, idx) => {
                     const card = document.createElement('div');
                     card.className = 'p-3 bg-white border border-teal-200 rounded-lg';
                     card.dataset.segIdx = idx;

                     const statusBadge = getStatusBadge(seg.status);
                     const chaptersChecked = seg.chapters || [];

                     // Calculate total duration of selected chapters
                     const totalDur = chaptersChecked.reduce((sum, chNum) => {
                         const ch = availableChapters.find(c => c.number === chNum);
                         return sum + (ch ? ch.duration : 0);
                     }, 0);
                     const totalDurStr = totalDur > 0 ? formatDuration(totalDur) : '--:--';

                     // Chapters used by earlier segments
                     const usedSet = new Set(usedChaptersBefore);

                     let chapterCheckboxes = availableChapters.map(ch => {
                         const isChecked = chaptersChecked.includes(ch.number);
                         const checked = isChecked ? 'checked' : '';
                         const durStr = ch.duration > 0 ? formatDuration(ch.duration) : '';
                         const label = ch.number === 0 ? '📖 GT' : ch.number;
                         const titleAttr = ch.number === 0 ? `Giới thiệu (${durStr})` :
                             `${ch.title} (${durStr})`;
                         const isUsedByPrev = usedSet.has(ch.number);
                         const shouldDisable = isUsedByPrev && !isChecked;
                         if (shouldDisable) {
                             return `<label class="inline-flex items-center gap-1 text-[11px] mr-2 mb-1 opacity-25 line-through cursor-not-allowed" title="${titleAttr} (đã chọn ở segment trước)">
                                <input type="checkbox" class="seg-ch-cb rounded" data-seg-idx="${idx}" data-ch-num="${ch.number}" disabled>
                                <span class="text-gray-400">${label}</span>
                                <span class="text-[9px] text-gray-300">${durStr}</span>
                            </label>`;
                         }
                         return `<label class="inline-flex items-center gap-1 text-[11px] cursor-pointer mr-2 mb-1" title="${titleAttr}">
                            <input type="checkbox" class="seg-ch-cb rounded" data-seg-idx="${idx}" data-ch-num="${ch.number}" ${checked}>
                            <span>${label}</span>
                            <span class="text-[9px] text-gray-400">${durStr}</span>
                        </label>`;
                     }).join('');

                     // Add this segment's chapters to usedBefore for next segments
                     chaptersChecked.forEach(ch => {
                         if (!usedChaptersBefore.includes(ch)) usedChaptersBefore.push(ch);
                     });

                     let videoSection = '';
                     if (seg.status === 'completed' && seg.video_url) {
                         const durStr = seg.video_duration ? formatDuration(seg.video_duration) : '';
                         videoSection = `
                            <div class="mt-2 p-2 bg-teal-50 border border-teal-200 rounded">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-[11px] text-teal-700 font-medium">🎬 Video ${durStr}</span>
                                    <a href="${seg.video_url}" download class="text-[11px] text-blue-600 hover:underline">⬇️ Download</a>
                                </div>
                                <video controls class="w-full rounded max-h-40" src="${seg.video_url}"></video>
                            </div>`;
                     }
                     if (seg.status === 'error' && seg.error_message) {
                         videoSection = `<div class="mt-2 text-[11px] text-red-600">❌ ${seg.error_message}</div>`;
                     }

                     // Image preview
                     let imagePreview = '';
                     if (seg.image_path && seg.image_type) {
                         const imgUrl = `/storage/books/${audioBookId}/${seg.image_type}/${seg.image_path}`;
                         imagePreview =
                             `<img src="${imgUrl}" class="seg-thumb-zoom w-12 h-8 object-cover rounded border cursor-pointer hover:ring-2 hover:ring-teal-400 transition" data-full-url="${imgUrl}" title="Click để phóng lớn">`;
                     }

                     const segChecked = seg._selected ? 'checked' : '';
                     card.innerHTML = `
                        <div class="flex items-start gap-2">
                            <div class="flex flex-col items-center gap-1 mt-1">
                                <input type="checkbox" class="seg-select-cb rounded border-teal-400 text-teal-600 focus:ring-teal-500" data-seg-idx="${idx}" ${segChecked}>
                                <span class="text-[10px] font-bold text-teal-600">#${idx + 1}</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-2">
                                    <input type="text" class="seg-name-input flex-1 px-2 py-1 border border-gray-200 rounded text-xs focus:border-teal-400 focus:outline-none"
                                        data-seg-idx="${idx}" value="${seg.name || ''}" placeholder="Tên segment...">
                                    <span class="seg-total-dur text-[10px] font-mono bg-teal-50 text-teal-700 px-1.5 py-0.5 rounded whitespace-nowrap" title="Tổng thời lượng">⏱ ${totalDurStr}</span>
                                    ${statusBadge}
                                    <div class="flex items-center gap-1">
                                        ${imagePreview}
                                        <button type="button" class="seg-pick-image-btn text-[10px] bg-indigo-50 hover:bg-indigo-100 text-indigo-600 px-1.5 py-0.5 rounded"
                                            data-seg-idx="${idx}" title="Chọn ảnh">🖼️</button>
                                    </div>
                                    <button type="button" class="seg-delete-btn text-[10px] text-red-400 hover:text-red-600" data-seg-idx="${idx}" title="Xóa segment">🗑️</button>
                                </div>
                                <div class="flex flex-wrap">${chapterCheckboxes}</div>
                                <!-- Image picker grid (hidden, shown on click) -->
                                <div class="seg-image-grid hidden mt-2 grid grid-cols-6 gap-1 max-h-24 overflow-y-auto" data-seg-idx="${idx}"></div>
                                <!-- Per-segment progress -->
                                <div class="seg-progress hidden mt-1" data-seg-idx="${idx}">
                                    <div class="w-full bg-teal-100 rounded-full h-1.5">
                                        <div class="seg-progress-bar bg-teal-400 h-1.5 rounded-full" style="width:0%"></div>
                                    </div>
                                </div>
                                ${videoSection}
                            </div>
                        </div>
                    `;
                     segmentList.appendChild(card);
                 });

                 // Attach event listeners
                 document.querySelectorAll('.seg-name-input').forEach(input => {
                     input.addEventListener('change', function() {
                         segments[this.dataset.segIdx].name = this.value;
                     });
                 });
                 document.querySelectorAll('.seg-ch-cb').forEach(cb => {
                     cb.addEventListener('change', function() {
                         const idx = parseInt(this.dataset.segIdx);
                         const chNum = parseInt(this.dataset.chNum);
                         if (!segments[idx].chapters) segments[idx].chapters = [];
                         if (this.checked) {
                             if (!segments[idx].chapters.includes(chNum)) segments[idx].chapters.push(chNum);
                         } else {
                             segments[idx].chapters = segments[idx].chapters.filter(n => n !== chNum);
                         }
                         segments[idx].chapters.sort((a, b) => a - b);
                         renderSegments();
                     });
                 });
                 document.querySelectorAll('.seg-delete-btn').forEach(btn => {
                     btn.addEventListener('click', function() {
                         const idx = parseInt(this.dataset.segIdx);
                         if (!confirm(`Xóa segment "${segments[idx].name || '#' + (idx+1)}"?`)) return;
                         const seg = segments[idx];
                         if (seg.id) {
                             // Delete from server
                             fetch(`/audiobooks/${audioBookId}/video-segments/${seg.id}`, {
                                 method: 'DELETE',
                                 headers: {
                                     'Accept': 'application/json',
                                     'X-CSRF-TOKEN': getCsrfToken()
                                 }
                             });
                         }
                         segments.splice(idx, 1);
                         renderSegments();
                     });
                 });
                 document.querySelectorAll('.seg-pick-image-btn').forEach(btn => {
                     btn.addEventListener('click', async function() {
                         const idx = parseInt(this.dataset.segIdx);
                         const grid = document.querySelector(`.seg-image-grid[data-seg-idx="${idx}"]`);
                         if (!grid.classList.contains('hidden')) {
                             grid.classList.add('hidden');
                             return;
                         }
                         grid.classList.remove('hidden');
                         await loadSegmentMedia(idx, grid);
                     });
                 });
                 // Thumbnail zoom click
                 document.querySelectorAll('.seg-thumb-zoom').forEach(img => {
                     img.addEventListener('click', function(e) {
                         e.stopPropagation();
                         const overlay = document.getElementById('segThumbOverlay');
                         overlay.querySelector('img').src = this.dataset.fullUrl;
                         overlay.classList.remove('hidden');
                     });
                 });
                 // Segment select checkbox
                 document.querySelectorAll('.seg-select-cb').forEach(cb => {
                     cb.addEventListener('change', function() {
                         const idx = parseInt(this.dataset.segIdx);
                         segments[idx]._selected = this.checked;
                         updateSelectAllState();
                     });
                 });
             }

             function updateSelectAllState() {
                 const selectAll = document.getElementById('segSelectAll');
                 if (!selectAll || segments.length === 0) return;
                 const allSelected = segments.every(s => s._selected);
                 const someSelected = segments.some(s => s._selected);
                 selectAll.checked = allSelected;
                 selectAll.indeterminate = someSelected && !allSelected;
             }

             async function loadSegmentMedia(segIdx, grid) {
                 if (segmentMediaCache.images) {
                     renderMediaGrid(segIdx, grid, segmentMediaCache.images);
                     return;
                 }
                 grid.innerHTML = '<div class="col-span-6 text-center text-[10px] text-gray-400 py-2">Đang tải...</div>';
                 try {
                     const response = await fetch(`/audiobooks/${audioBookId}/media`, {
                         headers: {
                             'X-CSRF-TOKEN': getCsrfToken()
                         }
                     });
                     const result = await safeJson(response);
                     if (result.success && result.media) {
                         const allImages = [];
                         if (result.media.thumbnails) result.media.thumbnails.forEach(f => allImages.push({
                             ...f,
                             type: 'thumbnails'
                         }));
                         if (result.media.scenes) result.media.scenes.forEach(f => allImages.push({
                             ...f,
                             type: 'scenes'
                         }));
                         segmentMediaCache.images = allImages;
                         renderMediaGrid(segIdx, grid, allImages);
                     } else {
                         grid.innerHTML =
                             '<div class="col-span-6 text-center text-[10px] text-gray-400 py-2">Không có ảnh.</div>';
                     }
                 } catch (e) {
                     grid.innerHTML =
                         `<div class="col-span-6 text-center text-[10px] text-red-500 py-2">${e.message}</div>`;
                 }
             }

             function renderMediaGrid(segIdx, grid, images) {
                 if (images.length === 0) {
                     grid.innerHTML =
                         '<div class="col-span-6 text-center text-[10px] text-gray-400 py-2">Không có ảnh.</div>';
                     return;
                 }
                 grid.innerHTML = '';
                 images.forEach(img => {
                     const div = document.createElement('div');
                     const isSelected = segments[segIdx].image_path === img.filename && segments[segIdx]
                         .image_type === img.type;
                     div.className =
                         `cursor-pointer border-2 rounded overflow-hidden transition ${isSelected ? 'border-teal-500' : 'border-transparent hover:border-teal-300'}`;
                     div.innerHTML =
                         `<img src="${img.url}" class="w-full h-10 object-cover" title="${img.filename}">`;
                     div.addEventListener('click', () => {
                         segments[segIdx].image_path = img.filename;
                         segments[segIdx].image_type = img.type;
                         // Re-render just this grid
                         renderMediaGrid(segIdx, grid, images);
                         // Update preview in the card
                         renderSegments();
                     });
                     grid.appendChild(div);
                 });
             }

             function getStatusBadge(status) {
                 const map = {
                     'pending': '<span class="text-[10px] bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded">⏳ Pending</span>',
                     'processing': '<span class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded animate-pulse">⚙️ Processing</span>',
                     'completed': '<span class="text-[10px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded">✅ Done</span>',
                     'error': '<span class="text-[10px] bg-red-100 text-red-700 px-1.5 py-0.5 rounded">❌ Error</span>',
                 };
                 return map[status] || map['pending'];
             }

             function formatDuration(seconds) {
                 const h = Math.floor(seconds / 3600);
                 const m = Math.floor((seconds % 3600) / 60);
                 const s = Math.floor(seconds % 60);
                 return h > 0 ? `${h}:${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}` :
                     `${m}:${s.toString().padStart(2,'0')}`;
             }

             // Add segment
             addBtn.addEventListener('click', () => {
                 segments.push({
                     id: null,
                     name: `Phần ${segments.length + 1}`,
                     chapters: [],
                     image_path: null,
                     image_type: null,
                     video_path: null,
                     video_url: null,
                     video_duration: null,
                     status: 'pending',
                     error_message: null,
                     sort_order: segments.length
                 });
                 renderSegments();
             });

             // Save all segments
             saveBtn.addEventListener('click', async () => {
                 // Validate
                 for (let i = 0; i < segments.length; i++) {
                     if (!segments[i].name || !segments[i].name.trim()) {
                         alert(`Segment #${i+1} chưa có tên.`);
                         return;
                     }
                     if (!segments[i].chapters || segments[i].chapters.length === 0) {
                         alert(`Segment "${segments[i].name}" chưa chọn chương nào.`);
                         return;
                     }
                 }

                 saveBtn.disabled = true;
                 saveBtn.innerHTML = '⏳ Đang lưu...';

                 try {
                     const payload = segments.map((seg, idx) => ({
                         id: seg.id || undefined,
                         name: seg.name,
                         chapters: seg.chapters,
                         image_path: seg.image_path,
                         image_type: seg.image_type,
                         sort_order: idx,
                     }));

                     const response = await fetch(`/audiobooks/${audioBookId}/video-segments`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'Accept': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         },
                         body: JSON.stringify({
                             segments: payload
                         })
                     });
                     const result = await safeJson(response);

                     if (result.success) {
                         // Update local segments with server IDs
                         segments = result.segments.map(s => ({
                             ...s,
                             chapters: s.chapters || []
                         }));
                         renderSegments();
                         statusDiv.innerHTML = '<span class="text-green-600">✅ Đã lưu kế hoạch!</span>';
                         setTimeout(() => statusDiv.innerHTML = '', 3000);
                     } else {
                         throw new Error(result.error || 'Không thể lưu');
                     }
                 } catch (e) {
                     statusDiv.innerHTML = `<span class="text-red-600">❌ ${e.message}</span>`;
                 } finally {
                     saveBtn.disabled = false;
                     saveBtn.innerHTML = '💾 Lưu kế hoạch';
                 }
             });

             // Start batch
             startBtn.addEventListener('click', async () => {
                 // Get selected segments
                 const selectedSegments = segments.filter(s => s._selected);
                 if (selectedSegments.length === 0) {
                     alert('Vui lòng chọn ít nhất 1 segment để tạo video.');
                     return;
                 }

                 // Check selected segments have images & chapters
                 for (const seg of selectedSegments) {
                     if (!seg.image_path || !seg.image_type) {
                         alert(`Segment "${seg.name}" chưa chọn ảnh.`);
                         return;
                     }
                     if (!seg.chapters || seg.chapters.length === 0) {
                         alert(`Segment "${seg.name}" chưa chọn chương.`);
                         return;
                     }
                 }

                 // Save first if any unsaved
                 const unsaved = segments.some(s => !s.id);
                 if (unsaved) {
                     alert('Vui lòng lưu kế hoạch trước khi bắt đầu.');
                     return;
                 }

                 const selectedIds = selectedSegments.map(s => s.id);
                 const names = selectedSegments.map(s => s.name || 'Chưa đặt tên').join(', ');
                 if (!confirm(
                         `Bắt đầu tạo video cho ${selectedSegments.length} segment: ${names}?\nQuá trình chạy trên server, bạn có thể đóng trình duyệt.`
                     )) return;

                 startBtn.disabled = true;
                 startBtn.innerHTML = '⏳ Đang gửi...';

                 try {
                     const response = await fetch(`/audiobooks/${audioBookId}/video-segments/start`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'Accept': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         },
                         body: JSON.stringify({
                             segment_ids: selectedIds
                         })
                     });
                     const result = await safeJson(response);

                     if (result.success) {
                         statusDiv.innerHTML =
                             `<span class="text-blue-600">🚀 Đã gửi ${selectedSegments.length} segment vào hàng đợi xử lý...</span>`;
                         startBatchPolling();
                     } else {
                         throw new Error(result.error || 'Không thể bắt đầu');
                     }
                 } catch (e) {
                     statusDiv.innerHTML = `<span class="text-red-600">❌ ${e.message}</span>`;
                 } finally {
                     startBtn.disabled = false;
                     startBtn.innerHTML = '🚀 Tạo video đã chọn';
                 }
             });

             // Batch progress polling
             function startBatchPolling() {
                 stopBatchPolling();
                 batchProgress.classList.remove('hidden');
                 batchLogContainer.classList.remove('hidden');
                 batchProgressBar.style.width = '1%';
                 batchProgressPercent.textContent = '1%';
                 batchProgressLabel.textContent = 'Đang chờ...';
                 batchLogContent.textContent = '';
                 fetchBatchProgress();
                 batchTimer = setInterval(fetchBatchProgress, 2500);
             }

             function stopBatchPolling() {
                 if (batchTimer) {
                     clearInterval(batchTimer);
                     batchTimer = null;
                 }
             }

             async function fetchBatchProgress() {
                 try {
                     const response = await fetch(`/audiobooks/${audioBookId}/video-segments/progress`, {
                         headers: {
                             'Accept': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         }
                     });
                     const result = await safeJson(response);

                     if (!result.success) return;
                     if (result.status === 'idle') {
                         const hasProcessing = result.segments && result.segments.some(s => s.status === 'processing');
                         if (!hasProcessing) {
                             stopBatchPolling();
                             batchProgress.classList.add('hidden');
                             batchLogContainer.classList.add('hidden');
                             statusDiv.innerHTML = '';
                         }
                         if (result.segments) {
                             segments = result.segments.map(s => ({
                                 ...s,
                                 chapters: s.chapters || []
                             }));
                             renderSegments();
                         }
                         return;
                     }

                     // Update overall progress
                     const pct = result.percent ?? 0;
                     batchProgressBar.style.width = `${pct}%`;
                     batchProgressPercent.textContent = `${Math.round(pct)}%`;
                     batchProgressLabel.textContent = result.message || 'Đang xử lý...';

                     // Update logs
                     const logs = Array.isArray(result.logs) ? result.logs : [];
                     if (logs.length > 0) {
                         batchLogContent.textContent = logs.join('\n');
                         batchLogContent.scrollTop = batchLogContent.scrollHeight;
                     }

                     // Update segment statuses from server
                     if (result.segments) {
                         segments = result.segments.map(s => ({
                             ...s,
                             chapters: s.chapters || []
                         }));
                         renderSegments();
                     }

                     // Highlight current processing segment
                     if (result.current_segment_id) {
                         const idx = segments.findIndex(s => s.id === result.current_segment_id);
                         if (idx >= 0) {
                             const progressEl = document.querySelector(`.seg-progress[data-seg-idx="${idx}"]`);
                             if (progressEl) {
                                 progressEl.classList.remove('hidden');
                                 const bar = progressEl.querySelector('.seg-progress-bar');
                                 if (bar) bar.style.width = `${pct}%`;
                             }
                         }
                     }

                     if (result.status === 'completed') {
                         stopBatchPolling();
                         batchProgressBar.style.width = '100%';
                         batchProgressPercent.textContent = '100%';
                         batchProgressBar.classList.remove('bg-teal-500');
                         batchProgressBar.classList.add('bg-green-500');
                         batchProgressLabel.textContent = 'Hoàn tất tất cả segments!';
                         statusDiv.innerHTML = '<span class="text-green-600">✅ Hoàn tất tất cả segments!</span>';
                     }

                     if (result.status === 'error') {
                         stopBatchPolling();
                         batchProgressBar.classList.remove('bg-teal-500');
                         batchProgressBar.classList.add('bg-red-500');
                         statusDiv.innerHTML = `<span class="text-red-600">❌ ${result.message || 'Lỗi'}</span>`;
                     }
                 } catch (e) {
                     // transient, keep polling
                 }
             }

             // Initial render
             renderSegments();

             // Auto-resume: check if batch is still running on server
             (async function checkBatchOnLoad() {
                 try {
                     const response = await fetch(`/audiobooks/${audioBookId}/video-segments/progress`, {
                         headers: {
                             'Accept': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         }
                     });
                     const result = await safeJson(response);
                     if (result.success && result.status === 'processing') {
                         statusDiv.innerHTML =
                             '<span class="text-blue-600">🔄 Đang xử lý batch trên server... (tự động theo dõi)</span>';
                         startBatchPolling();
                     }
                 } catch (e) {
                     /* ignore */
                 }
             })();
         }

         // ========== FLOATING TOOLBAR SCROLL HANDLER ==========
         function setupFloatingToolbar() {
             const anchor = document.getElementById('chapterToolbarAnchor');
             const floating = document.getElementById('chapterFloatingToolbar');
             if (!anchor || !floating) return;

             let isVisible = false;

             function checkScroll() {
                 const chaptersTab = document.getElementById('chapters-tab');
                 // Only show floating toolbar when chapters tab is active
                 if (chaptersTab && chaptersTab.classList.contains('hidden')) {
                     if (isVisible) {
                         floating.style.transform = 'translateY(-100%)';
                         setTimeout(() => {
                             floating.style.display = 'none';
                         }, 300);
                         isVisible = false;
                     }
                     return;
                 }

                 const anchorRect = anchor.getBoundingClientRect();
                 const shouldShow = anchorRect.bottom < 0;

                 if (shouldShow && !isVisible) {
                     floating.style.display = 'block';
                     requestAnimationFrame(() => {
                         floating.style.transform = 'translateY(0)';
                     });
                     isVisible = true;
                 } else if (!shouldShow && isVisible) {
                     floating.style.transform = 'translateY(-100%)';
                     setTimeout(() => {
                         floating.style.display = 'none';
                     }, 300);
                     isVisible = false;
                 }
             }

             window.addEventListener('scroll', checkScroll, {
                 passive: true
             });
         }

         // Initialize voice options on page load
         document.addEventListener('DOMContentLoaded', function() {
             setupGrammarlyProtection();
             if (currentTtsProvider) {
                 updateVoiceOptions();
                 updateStyleInstructionVisibility();
             }
             setupChapterCheckboxes();
             setupDescriptionEditor();
             setupFullBookVideo();
             setupVideoSegments();
             setupShortVideoTab();
             setupFloatingToolbar();
             checkEmbeddingProgressOnLoad();
             initSidebarCollapse();
             initSidebarMenu(); // Initialize sidebar navigation
         });

         // ========== DESCRIPTION EDITOR ==========
         function setupDescriptionEditor() {
             const saveBtn = document.getElementById('saveDescBtn');
             const rewriteBtn = document.getElementById('rewriteDescBtn');
             const generateAudioBtn = document.getElementById('generateDescAudioBtn');
             const deleteAudioBtn = document.getElementById('deleteDescAudioBtn');
             const descTextarea = document.getElementById('bookDescription');
             const statusDiv = document.getElementById('descStatus');
             const audioContainer = document.getElementById('descAudioContainer');
             const audioPlayer = document.getElementById('descAudioPlayer');
             const audioDuration = document.getElementById('descAudioDuration');
             const videoContainer = document.getElementById('descVideoContainer');
             const videoPlayer = document.getElementById('descVideoPlayer');
             const videoDuration = document.getElementById('descVideoDuration');
             const deleteVideoBtn = document.getElementById('deleteDescVideoBtn');
             const introProgressContainer = document.getElementById('descVideoProgress');
             const introProgressBar = document.getElementById('descVideoProgressBar');
             const introProgressPercent = document.getElementById('descVideoProgressPercent');
             const introProgressLabel = document.getElementById('descVideoProgressLabel');
             const introLogContainer = document.getElementById('descVideoLog');
             const introLogContent = document.getElementById('descVideoLogContent');

             // Image picker elements
             const loadMediaBtn = document.getElementById('loadDescMediaBtn');
             const mediaGrid = document.getElementById('descMediaGrid');
             const mediaEmpty = document.getElementById('descMediaEmpty');
             const selectedImagePreview = document.getElementById('descSelectedImagePreview');
             const selectedImageImg = document.getElementById('descSelectedImageImg');
             const selectedImageName = document.getElementById('descSelectedImageName');
             const clearImageBtn = document.getElementById('descClearImageBtn');
             const generateIntroVideoBtn = document.getElementById('generateDescIntroVideoBtn');

             let selectedDescImage = null; // { filename, type, url }
             let introProgressTimer = null;
             let introProgressValue = 0;

             function setIntroProgress(value, label) {
                 introProgressValue = Math.min(100, Math.max(0, value));
                 if (introProgressBar) {
                     introProgressBar.style.width = `${introProgressValue}%`;
                 }
                 if (introProgressPercent) {
                     introProgressPercent.textContent = `${Math.round(introProgressValue)}%`;
                 }
                 if (label && introProgressLabel) {
                     introProgressLabel.textContent = label;
                 }
             }

             async function fetchIntroProgress() {
                 try {
                     const response = await fetch(`/audiobooks/${audioBookId}/description-video-progress`, {
                         headers: {
                             'X-CSRF-TOKEN': getCsrfToken()
                         }
                     });
                     const result = await safeJson(response);

                     if (!result.success || result.status === 'idle') {
                         return;
                     }

                     setIntroProgress(result.percent ?? 0, result.message || 'Đang xử lý...');

                     if (introLogContainer && introLogContent) {
                         const logs = Array.isArray(result.logs) ? result.logs : [];
                         introLogContent.textContent = logs.join('\n');
                         if (logs.length > 0) {
                             introLogContainer.classList.remove('hidden');
                             introLogContent.scrollTop = introLogContent.scrollHeight;
                         }
                     }

                     if (result.status === 'completed') {
                         stopIntroProgressPolling();
                         finishIntroProgress(true);

                         if (result.video_url && videoPlayer && videoContainer) {
                             const refreshedUrl =
                                 `${result.video_url}${result.video_url.includes('?') ? '&' : '?'}t=${Date.now()}`;
                             videoPlayer.src = refreshedUrl;
                             videoPlayer.load();
                             if (result.video_duration) {
                                 const mins = Math.floor(result.video_duration / 60);
                                 const secs = Math.floor(result.video_duration % 60);
                                 videoDuration.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
                             }
                             videoContainer.classList.remove('hidden');
                         }
                     }

                     if (result.status === 'error') {
                         stopIntroProgressPolling();
                         finishIntroProgress(false);
                     }
                 } catch (error) {
                     // Keep polling; transient error
                 }
             }

             function startIntroProgressPolling() {
                 if (!introProgressContainer) return;
                 stopIntroProgressPolling();
                 introProgressContainer.classList.remove('hidden');
                 setIntroProgress(1, 'Đang chờ tiến trình từ server...');
                 if (introLogContent) {
                     introLogContent.textContent = '';
                 }
                 if (introLogContainer) {
                     introLogContainer.classList.remove('hidden');
                 }
                 fetchIntroProgress();
                 introProgressTimer = setInterval(fetchIntroProgress, 1500);
             }

             function stopIntroProgressPolling() {
                 if (introProgressTimer) {
                     clearInterval(introProgressTimer);
                     introProgressTimer = null;
                 }
             }

             function finishIntroProgress(success) {
                 if (!introProgressContainer) return;
                 if (introProgressTimer) {
                     clearInterval(introProgressTimer);
                     introProgressTimer = null;
                 }
                 if (success) {
                     setIntroProgress(100, 'Hoàn tất');
                     setTimeout(() => {
                         introProgressContainer.classList.add('hidden');
                         setIntroProgress(0, '');
                     }, 1500);
                 } else {
                     setIntroProgress(0, 'Đã dừng');
                     setTimeout(() => {
                         introProgressContainer.classList.add('hidden');
                         setIntroProgress(0, '');
                     }, 700);
                 }
             }

             if (!saveBtn || !descTextarea) return;

             // ---- Image Picker: Load media library ----
             if (loadMediaBtn) {
                 loadMediaBtn.addEventListener('click', async function() {
                     const btn = this;
                     btn.innerHTML = '⏳ Đang tải...';
                     btn.disabled = true;

                     try {
                         const response = await fetch(`/audiobooks/${audioBookId}/media`, {
                             headers: {
                                 'X-CSRF-TOKEN': getCsrfToken()
                             }
                         });
                         const result = await safeJson(response);

                         if (result.success && result.media) {
                             const allImages = [];

                             // Collect thumbnails
                             if (result.media.thumbnails) {
                                 result.media.thumbnails.forEach(img => {
                                     allImages.push({
                                         filename: img.filename,
                                         type: 'thumbnails',
                                         url: img.url
                                     });
                                 });
                             }

                             // Collect scenes
                             if (result.media.scenes) {
                                 result.media.scenes.forEach(img => {
                                     allImages.push({
                                         filename: img.filename,
                                         type: 'scenes',
                                         url: img.url
                                     });
                                 });
                             }

                             if (allImages.length === 0) {
                                 mediaGrid.classList.add('hidden');
                                 mediaEmpty.classList.remove('hidden');
                             } else {
                                 mediaEmpty.classList.add('hidden');
                                 mediaGrid.innerHTML = '';
                                 allImages.forEach(img => {
                                     const div = document.createElement('div');
                                     div.className =
                                         'relative cursor-pointer group rounded overflow-hidden border-2 border-transparent hover:border-indigo-400 transition';
                                     div.innerHTML = `
                                        <img src="${img.url}" alt="${img.filename}" class="w-full h-16 object-cover">
                                        <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition"></div>
                                        <div class="absolute bottom-0 left-0 right-0 bg-black/50 text-white text-[9px] px-1 py-0.5 truncate">${img.type === 'thumbnails' ? '📷' : '🎬'} ${img.filename}</div>
                                    `;
                                     div.addEventListener('click', () => {
                                         // Deselect all
                                         mediaGrid.querySelectorAll('div.border-indigo-500')
                                             .forEach(el => {
                                                 el.classList.remove('border-indigo-500',
                                                     'ring-2', 'ring-indigo-400');
                                                 el.classList.add('border-transparent');
                                             });
                                         // Select this
                                         div.classList.remove('border-transparent');
                                         div.classList.add('border-indigo-500', 'ring-2',
                                             'ring-indigo-400');

                                         selectedDescImage = img;
                                         selectedImageImg.src = img.url;
                                         selectedImageName.textContent =
                                             `${img.type}/${img.filename}`;
                                         selectedImagePreview.classList.remove('hidden');

                                         // Enable video button if audio exists
                                         if (generateIntroVideoBtn && audioContainer && !
                                             audioContainer.classList.contains('hidden')) {
                                             generateIntroVideoBtn.disabled = false;
                                             generateIntroVideoBtn.classList.remove('opacity-50',
                                                 'cursor-not-allowed');
                                         }
                                     });
                                     mediaGrid.appendChild(div);
                                 });
                                 mediaGrid.classList.remove('hidden');
                             }
                         }
                     } catch (error) {
                         statusDiv.innerHTML =
                             `<span class="text-red-600">❌ Lỗi tải thư viện: ${error.message}</span>`;
                     } finally {
                         btn.innerHTML = '🔄 Tải thư viện';
                         btn.disabled = false;
                     }
                 });
             }

             // Clear selected image
             if (clearImageBtn) {
                 clearImageBtn.addEventListener('click', () => {
                     selectedDescImage = null;
                     selectedImagePreview.classList.add('hidden');
                     mediaGrid.querySelectorAll('div.border-indigo-500').forEach(el => {
                         el.classList.remove('border-indigo-500', 'ring-2', 'ring-indigo-400');
                         el.classList.add('border-transparent');
                     });
                     if (generateIntroVideoBtn) {
                         generateIntroVideoBtn.disabled = true;
                         generateIntroVideoBtn.classList.add('opacity-50', 'cursor-not-allowed');
                     }
                 });
             }

             // ---- Generate Intro Video (image + audio + music + wave → video) ----
             if (generateIntroVideoBtn) {
                 generateIntroVideoBtn.addEventListener('click', async function() {
                     if (!selectedDescImage) {
                         alert('Vui lòng chọn một ảnh từ thư viện media trước');
                         return;
                     }

                     // Check intro music
                     const hasIntroMusic = {{ $audioBook->intro_music ? 'true' : 'false' }};
                     if (!hasIntroMusic) {
                         statusDiv.innerHTML =
                             '<span class="text-red-600">❌ Chưa có nhạc Intro. Vui lòng upload nhạc Intro trong phần "🎵 Nhạc Intro/Outro" bên phải trước.</span>';
                         return;
                     }

                     // Check outro music (either dedicated outro or "use intro as outro")
                     const hasOutroMusic = {{ $audioBook->outro_music ? 'true' : 'false' }};
                     const outroUseIntro = document.getElementById('outroUseIntro')?.checked || false;
                     if (!hasOutroMusic && !outroUseIntro) {
                         statusDiv.innerHTML =
                             '<span class="text-red-600">❌ Chưa có nhạc Outro. Vui lòng upload nhạc Outro hoặc chọn "Dùng nhạc Intro" trong phần "🎵 Nhạc Intro/Outro".</span>';
                         return;
                     }

                     // Check wave settings
                     const waveEnabled = {{ $audioBook->wave_enabled ? 'true' : 'false' }};
                     if (!waveEnabled) {
                         statusDiv.innerHTML =
                             '<span class="text-red-600">❌ Chưa bật hiệu ứng sóng âm. Vui lòng bật và cấu hình trong phần "🌊 Hiệu ứng Sóng Âm" bên phải.</span>';
                         return;
                     }

                     if (!confirm('Tạo video giới thiệu từ ảnh + audio + nhạc nền + sóng âm?')) return;

                     const btn = this;
                     const originalText = btn.innerHTML;
                     btn.innerHTML = '⏳ Đang tạo video...';
                     btn.disabled = true;

                     statusDiv.innerHTML =
                         '<span class="text-blue-600">🎬 Đang tạo video giới thiệu (ảnh + audio + nhạc nền + sóng âm)... Quá trình có thể mất 1-3 phút.</span>';
                     startIntroProgressPolling();

                     try {
                         const response = await fetch(
                             `/audiobooks/${audioBookId}/generate-description-video-async`, {
                                 method: 'POST',
                                 headers: {
                                     'Content-Type': 'application/json',
                                     'X-CSRF-TOKEN': getCsrfToken()
                                 },
                                 body: JSON.stringify({
                                     image_path: selectedDescImage.filename,
                                     image_type: selectedDescImage.type
                                 })
                             });

                         const result = await safeJson(response);

                         if (!result.success) {
                             throw new Error(result.error || 'Không thể tạo video');
                         }

                         statusDiv.innerHTML =
                             '<span class="text-blue-600">🎬 Đã nhận yêu cầu. Đang xử lý ở server...</span>';
                     } catch (error) {
                         statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                         stopIntroProgressPolling();
                         finishIntroProgress(false);
                     } finally {
                         btn.innerHTML = originalText;
                         btn.disabled = false;
                     }
                 });
             }

             // ---- Generate description audio (async with polling) ----
             let descAudioPollingTimer = null;

             function startDescAudioPolling(btn, originalText) {
                 descAudioPollingTimer = setInterval(async () => {
                     try {
                         const res = await fetch(`/audiobooks/${audioBookId}/description-audio-progress`);
                         const progress = await res.json();

                         if (progress.status === 'processing') {
                             statusDiv.innerHTML =
                                 `<span class="text-blue-600">🎙️ ${progress.message || 'Đang xử lý...'}</span>`;
                         } else if (progress.status === 'completed') {
                             clearInterval(descAudioPollingTimer);
                             descAudioPollingTimer = null;

                             statusDiv.innerHTML =
                                 '<span class="text-green-600">✅ Đã tạo audio giới thiệu!</span>';

                             if (progress.result) {
                                 audioPlayer.src = progress.result.audio_url;
                                 audioPlayer.load();
                                 if (progress.result.duration) {
                                     const mins = Math.floor(progress.result.duration / 60);
                                     const secs = Math.floor(progress.result.duration % 60);
                                     audioDuration.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
                                 }
                                 audioContainer.classList.remove('hidden');

                                 if (generateIntroVideoBtn && selectedDescImage) {
                                     generateIntroVideoBtn.disabled = false;
                                     generateIntroVideoBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                                 }
                             }

                             btn.innerHTML = originalText;
                             btn.disabled = false;
                             setTimeout(() => statusDiv.innerHTML = '', 3000);
                         } else if (progress.status === 'error') {
                             clearInterval(descAudioPollingTimer);
                             descAudioPollingTimer = null;

                             statusDiv.innerHTML =
                                 `<span class="text-red-600">❌ ${progress.message || 'Lỗi tạo audio'}</span>`;
                             btn.innerHTML = originalText;
                             btn.disabled = false;
                         }
                     } catch (e) {
                         // polling error, keep trying
                     }
                 }, 2000);
             }

             if (generateAudioBtn) {
                 generateAudioBtn.addEventListener('click', async function() {
                     const description = descTextarea.value.trim();
                     if (!description) {
                         alert('Vui lòng nhập nội dung giới thiệu trước');
                         return;
                     }

                     const provider = document.getElementById('ttsProviderSelect').value;
                     const voiceName = document.getElementById('voiceNameSelect').value;
                     if (!provider || !voiceName) {
                         alert('Vui lòng cấu hình TTS Settings trước (Provider và Voice)');
                         return;
                     }

                     const btn = this;
                     const originalText = btn.innerHTML;
                     btn.innerHTML = '⏳ Đang tạo...';
                     btn.disabled = true;

                     statusDiv.innerHTML =
                         '<span class="text-blue-600">🎙️ Đã đưa vào hàng đợi tạo audio...</span>';

                     try {
                         const response = await fetch(`/audiobooks/${audioBookId}/generate-description-audio`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': getCsrfToken()
                             },
                             body: JSON.stringify({
                                 description: description,
                                 provider: provider,
                                 voice_name: voiceName,
                                 voice_gender: document.querySelector(
                                     'input[name="voiceGender"]:checked')?.value || 'female',
                                 style_instruction: document.getElementById(
                                     'ttsStyleInstruction')?.value || ''
                             })
                         });

                         const result = await safeJson(response);

                         if (result.success && result.queued) {
                             startDescAudioPolling(btn, originalText);
                         } else {
                             throw new Error(result.error || 'Không thể tạo audio');
                         }
                     } catch (error) {
                         statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                         btn.innerHTML = originalText;
                         btn.disabled = false;
                     }
                 });
             }

             // Delete description audio
             if (deleteAudioBtn) {
                 deleteAudioBtn.addEventListener('click', async function() {
                     if (!confirm('Bạn có chắc muốn xóa audio giới thiệu này?')) return;

                     const btn = this;
                     btn.disabled = true;

                     try {
                         const response = await fetch(`/audiobooks/${audioBookId}/delete-description-audio`, {
                             method: 'DELETE',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': getCsrfToken()
                             }
                         });

                         const result = await safeJson(response);

                         if (result.success) {
                             statusDiv.innerHTML = '<span class="text-green-600">✅ Đã xóa audio!</span>';
                             audioContainer.classList.add('hidden');
                             audioPlayer.src = '';
                             audioDuration.textContent = '';

                             // Disable intro video button
                             if (generateIntroVideoBtn) {
                                 generateIntroVideoBtn.disabled = true;
                                 generateIntroVideoBtn.classList.add('opacity-50', 'cursor-not-allowed');
                             }

                             setTimeout(() => statusDiv.innerHTML = '', 3000);
                         } else {
                             throw new Error(result.error || 'Không thể xóa');
                         }
                     } catch (error) {
                         statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                     } finally {
                         btn.disabled = false;
                     }
                 });
             }

             // Delete description video
             if (deleteVideoBtn) {
                 deleteVideoBtn.addEventListener('click', async function() {
                     if (!confirm('Bạn có chắc muốn xóa video giới thiệu này?')) return;

                     const btn = this;
                     btn.disabled = true;

                     try {
                         const response = await fetch(`/audiobooks/${audioBookId}/delete-description-video`, {
                             method: 'DELETE',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': getCsrfToken()
                             }
                         });

                         const result = await safeJson(response);

                         if (result.success) {
                             statusDiv.innerHTML = '<span class="text-green-600">✅ Đã xóa video!</span>';
                             videoContainer.classList.add('hidden');
                             videoPlayer.src = '';
                             videoDuration.textContent = '';
                             setTimeout(() => statusDiv.innerHTML = '', 3000);
                         } else {
                             throw new Error(result.error || 'Không thể xóa');
                         }
                     } catch (error) {
                         statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                     } finally {
                         btn.disabled = false;
                     }
                 });
             }

             // Save description
             saveBtn.addEventListener('click', async function() {
                 const btn = this;
                 const originalText = btn.innerHTML;
                 btn.innerHTML = '⏳ Đang lưu...';
                 btn.disabled = true;

                 try {
                     const response = await fetch(`/audiobooks/${audioBookId}/update-description`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         },
                         body: JSON.stringify({
                             description: descTextarea.value
                         })
                     });

                     const result = await safeJson(response);

                     if (result.success) {
                         statusDiv.innerHTML = '<span class="text-green-600">✅ Đã lưu mô tả!</span>';
                         setTimeout(() => statusDiv.innerHTML = '', 3000);
                     } else {
                         throw new Error(result.error || 'Không thể lưu');
                     }
                 } catch (error) {
                     statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                 } finally {
                     btn.innerHTML = originalText;
                     btn.disabled = false;
                 }
             });

             // Rewrite with AI
             rewriteBtn.addEventListener('click', async function() {
                 const currentDesc = descTextarea.value.trim();
                 const bookTitle = '{{ addslashes($audioBook->title) }}';
                 const bookAuthor = '{{ addslashes($audioBook->author ?? '') }}';
                 const bookCategory = '{{ addslashes($audioBook->category ?? '') }}';
                 const channelName = '{{ addslashes($audioBook->youtubeChannel->title ?? '') }}';

                 if (!currentDesc && !bookTitle) {
                     alert('Cần có tiêu đề hoặc mô tả hiện tại để viết lại');
                     return;
                 }

                 const btn = this;
                 const originalText = btn.innerHTML;
                 btn.innerHTML = '⏳ Đang viết...';
                 btn.disabled = true;
                 statusDiv.innerHTML = '<span class="text-blue-600">🤖 AI đang viết lại mô tả...</span>';

                 try {
                     const response = await fetch(`/audiobooks/${audioBookId}/rewrite-description`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         },
                         body: JSON.stringify({
                             current_description: currentDesc,
                             title: bookTitle,
                             author: bookAuthor,
                             category: bookCategory,
                             channel_name: channelName
                         })
                     });

                     const result = await safeJson(response);

                     if (result.success) {
                         descTextarea.value = result.description;
                         statusDiv.innerHTML =
                             '<span class="text-green-600">✅ Đã viết lại! Nhấn "Lưu" để lưu thay đổi.</span>';
                     } else {
                         throw new Error(result.error || 'Không thể viết lại');
                     }
                 } catch (error) {
                     statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                 } finally {
                     btn.innerHTML = originalText;
                     btn.disabled = false;
                 }
             });
         }

         // ========== CHAPTER SELECTION & BATCH TTS ==========
         function setupChapterCheckboxes() {
             const selectAllCheckbox = document.getElementById('selectAllChapters');
             const selectAllFloating = document.getElementById('selectAllChaptersFloating');
             const chapterCheckboxes = document.querySelectorAll('.chapter-checkbox');
             const generateBtn = document.getElementById('generateSelectedTtsBtn');
             const generateVideoBtn = document.getElementById('generateSelectedVideoBtn');
             const deleteBtn = document.getElementById('deleteSelectedChaptersBtn');
             const boostBtn = document.getElementById('boostSelectedAudioBtn');
             const generateBtnFloating = document.getElementById('generateSelectedTtsBtnFloating');
             const generateVideoBtnFloating = document.getElementById('generateSelectedVideoBtnFloating');
             const deleteBtnFloating = document.getElementById('deleteSelectedChaptersBtnFloating');
             const boostBtnFloating = document.getElementById('boostSelectedAudioBtnFloating');
             const selectedCountSpan = document.getElementById('selectedCount');
             const selectedVideoCountSpan = document.getElementById('selectedVideoCount');
             const selectedBoostCountSpan = document.getElementById('selectedBoostCount');
             const selectedBoostCountFloating = document.getElementById('selectedBoostCountFloating');
             const selectedCountFloating = document.getElementById('selectedCountFloating');
             const selectedVideoCountFloating = document.getElementById('selectedVideoCountFloating');

             if (!selectAllCheckbox) return;

             // Select All handler (original)
             selectAllCheckbox.addEventListener('change', function() {
                 chapterCheckboxes.forEach(cb => cb.checked = this.checked);
                 if (selectAllFloating) selectAllFloating.checked = this.checked;
                 updateSelectedCount();
             });

             // Select All handler (floating)
             if (selectAllFloating) {
                 selectAllFloating.addEventListener('change', function() {
                     chapterCheckboxes.forEach(cb => cb.checked = this.checked);
                     selectAllCheckbox.checked = this.checked;
                     updateSelectedCount();
                 });
             }

             // Individual checkbox handler
             chapterCheckboxes.forEach(cb => {
                 cb.addEventListener('change', function() {
                     const allChecked = Array.from(chapterCheckboxes).every(c => c.checked);
                     const noneChecked = Array.from(chapterCheckboxes).every(c => !c.checked);
                     selectAllCheckbox.checked = allChecked;
                     selectAllCheckbox.indeterminate = !allChecked && !noneChecked;
                     if (selectAllFloating) {
                         selectAllFloating.checked = allChecked;
                         selectAllFloating.indeterminate = !allChecked && !noneChecked;
                     }
                     updateSelectedCount();
                 });
             });

             function updateSelectedCount() {
                 const count = document.querySelectorAll('.chapter-checkbox:checked').length;
                 selectedCountSpan.textContent = count;
                 if (selectedVideoCountSpan) selectedVideoCountSpan.textContent = count;
                 if (selectedBoostCountSpan) selectedBoostCountSpan.textContent = count;
                 if (selectedBoostCountFloating) selectedBoostCountFloating.textContent = count;
                 if (selectedCountFloating) selectedCountFloating.textContent = count;
                 if (selectedVideoCountFloating) selectedVideoCountFloating.textContent = count;
                 generateBtn.classList.toggle('hidden', count === 0);
                 if (generateVideoBtn) generateVideoBtn.classList.toggle('hidden', count === 0);
                 if (deleteBtn) deleteBtn.classList.toggle('hidden', count === 0);
                 if (boostBtn) boostBtn.classList.toggle('hidden', count === 0);
                 if (generateBtnFloating) generateBtnFloating.classList.toggle('hidden', count === 0);
                 if (generateVideoBtnFloating) generateVideoBtnFloating.classList.toggle('hidden', count === 0);
                 if (deleteBtnFloating) deleteBtnFloating.classList.toggle('hidden', count === 0);
                 if (boostBtnFloating) boostBtnFloating.classList.toggle('hidden', count === 0);
             }
         }

         // Delete selected chapters
         async function deleteSelectedChapters() {
             const selectedCheckboxes = document.querySelectorAll('.chapter-checkbox:checked');
             if (selectedCheckboxes.length === 0) {
                 alert('Vui lòng chọn ít nhất một chương');
                 return;
             }

             const chapterIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterId);
             const chapterNumbers = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterNumber);

             const confirmMsg =
                 `Bạn có chắc muốn xóa ${chapterIds.length} chương?\n\nChương: ${chapterNumbers.join(', ')}\n\nHành động này không thể hoàn tác.`;
             if (!confirm(confirmMsg)) return;

             const btn = document.getElementById('deleteSelectedChaptersBtn');
             const btnFloating = document.getElementById('deleteSelectedChaptersBtnFloating');
             const originalText = btn ? btn.innerHTML : '';
             const originalTextFloating = btnFloating ? btnFloating.innerHTML : '';

             if (btn) {
                 btn.disabled = true;
                 btn.innerHTML = '⏳ Đang xóa...';
             }
             if (btnFloating) {
                 btnFloating.disabled = true;
                 btnFloating.innerHTML = '⏳ Đang xóa...';
             }

             let successCount = 0;
             let errorCount = 0;

             for (const chapterId of chapterIds) {
                 if (!chapterId) {
                     errorCount++;
                     console.error('Delete chapter error: missing chapter id');
                     continue;
                 }
                 try {
                     const deleteUrl = deleteChapterUrlTemplate.replace('CHAPTER_ID_PLACEHOLDER', chapterId);
                     if (!deleteUrl.includes('/chapters/') || deleteUrl.includes('CHAPTER_ID_PLACEHOLDER')) {
                         errorCount++;
                         console.error('Delete chapter error: invalid URL', deleteUrl);
                         continue;
                     }
                     const response = await fetch(deleteUrl, {
                         method: 'DELETE',
                         headers: {
                             'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                             'X-Requested-With': 'XMLHttpRequest'
                         },
                         credentials: 'same-origin'
                     });

                     if (response.ok) {
                         successCount++;
                     } else {
                         errorCount++;
                         const errText = await response.text();
                         console.error('Delete chapter failed:', chapterId, errText);
                     }
                 } catch (error) {
                     errorCount++;
                     console.error('Delete chapter error:', chapterId, error);
                 }
             }

             if (btn) {
                 btn.disabled = false;
                 btn.innerHTML = originalText;
             }
             if (btnFloating) {
                 btnFloating.disabled = false;
                 btnFloating.innerHTML = originalTextFloating;
             }

             if (errorCount > 0) {
                 alert(`Đã xóa ${successCount} chương, ${errorCount} lỗi. Vui lòng thử lại với các chương lỗi.`);
             } else {
                 alert(`✅ Đã xóa ${successCount} chương.`);
             }

             if (successCount > 0) {
                 window.location.reload();
             }
         }

         // ========== BOOST AUDIO ==========
         let boostPollingInterval = null;

         function startBoostPolling() {
             if (boostPollingInterval) return;
             boostPollingInterval = setInterval(pollBoostProgress, 2000);
         }

         function stopBoostPolling() {
             if (boostPollingInterval) {
                 clearInterval(boostPollingInterval);
                 boostPollingInterval = null;
             }
         }

         async function pollBoostProgress() {
             try {
                 const resp = await fetch('{{ route("audiobooks.chapters.boost-audio.progress", $audioBook) }}', {
                     headers: { 'Accept': 'application/json' }
                 });
                 const data = await resp.json();
                 if (!data.success || data.status === 'idle') { stopBoostPolling(); return; }

                 const container = document.getElementById('boostProgressContainer');
                 const bar    = document.getElementById('boostProgressBar');
                 const status = document.getElementById('boostProgressStatus');
                 const pct    = document.getElementById('boostProgressPercent');
                 const detail = document.getElementById('boostProgressDetail');
                 const logBox = document.getElementById('boostLogContainer');
                 const btn    = document.getElementById('boostSelectedAudioBtn');
                 const btnF   = document.getElementById('boostSelectedAudioBtnFloating');

                 container.classList.remove('hidden');
                 bar.style.width    = (data.percent || 0) + '%';
                 pct.textContent    = (data.percent || 0) + '%';
                 status.textContent = data.message || 'Đang xử lý...';
                 detail.textContent = `${data.done || 0} / ${data.total || 0} chương  ✅ ${data.success || 0}  ❌ ${data.failed || 0}`;

                 if (Array.isArray(data.logs) && data.logs.length > 0) {
                     logBox.innerHTML = data.logs.map(l => `<div>${l}</div>`).join('');
                     logBox.scrollTop = logBox.scrollHeight;
                 }

                 if (data.status === 'completed') {
                     stopBoostPolling();
                     container.classList.remove('bg-orange-50', 'border-orange-200');
                     container.classList.add('bg-green-50', 'border-green-200');
                     status.classList.remove('text-orange-800');
                     status.classList.add('text-green-800');
                     pct.classList.remove('text-orange-600');
                     pct.classList.add('text-green-600');
                     if (btn)  { btn.disabled  = false; btn.innerHTML  = '🔊 Boost +16dB (<span id="selectedBoostCount">0</span>)'; }
                     if (btnF) { btnF.disabled = false; btnF.innerHTML = '🔊 Boost +16dB (<span id="selectedBoostCountFloating">0</span>)'; }
                     setTimeout(() => window.location.reload(), 2000);
                 } else if (data.status === 'error') {
                     stopBoostPolling();
                     container.classList.remove('bg-orange-50', 'border-orange-200');
                     container.classList.add('bg-red-50', 'border-red-200');
                     if (btn)  { btn.disabled  = false; btn.innerHTML  = '🔊 Boost +16dB (<span id="selectedBoostCount">0</span>)'; }
                     if (btnF) { btnF.disabled = false; btnF.innerHTML = '🔊 Boost +16dB (<span id="selectedBoostCountFloating">0</span>)'; }
                 }
             } catch (e) { /* ignore transient errors */ }
         }

         async function boostAudioForSelectedChapters() {
             const selectedCheckboxes = document.querySelectorAll('.chapter-checkbox:checked');
             if (selectedCheckboxes.length === 0) {
                 alert('Vui lòng chọn íT nhất một chương');
                 return;
             }

             const chapterIds     = Array.from(selectedCheckboxes).map(cb => parseInt(cb.dataset.chapterId));
             const chapterNumbers = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterNumber);

             if (!confirm(`Boost âm lượng +16dB cho ${chapterIds.length} chương?\n\nChương: ${chapterNumbers.join(', ')}\n\nThao tác chạy nền, bạn có thể tắt trình duyệt.`)) return;

             const btn  = document.getElementById('boostSelectedAudioBtn');
             const btnF = document.getElementById('boostSelectedAudioBtnFloating');
             if (btn)  { btn.disabled  = true; btn.innerHTML  = '⏳ Đang xếp hàng...'; }
             if (btnF) { btnF.disabled = true; btnF.innerHTML = '⏳ Đang xếp hàng...'; }

             const container = document.getElementById('boostProgressContainer');
             container.classList.remove('hidden', 'bg-green-50', 'border-green-200', 'bg-red-50', 'border-red-200');
             container.classList.add('bg-orange-50', 'border-orange-200');
             document.getElementById('boostProgressBar').style.width = '1%';
             document.getElementById('boostProgressPercent').textContent = '1%';
             document.getElementById('boostProgressStatus').textContent = 'Đang xếp hàng...';
             document.getElementById('boostProgressDetail').textContent = `0 / ${chapterIds.length} chương`;
             document.getElementById('boostLogContainer').innerHTML = '';

             try {
                 const response = await fetch('{{ route("audiobooks.chapters.boost-audio.batch", $audioBook) }}', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                         'X-Requested-With': 'XMLHttpRequest'
                     },
                     credentials: 'same-origin',
                     body: JSON.stringify({ chapter_ids: chapterIds, db: 16 })
                 });
                 const data = await response.json();
                 if (data.success) {
                     document.getElementById('boostProgressStatus').textContent = 'Đã đưa vào hàng đợi. Bạn có thể tắt trình duyệt.';
                     startBoostPolling();
                 } else {
                     alert('❌ Lỗi: ' + (data.error || 'Không xác định'));
                     if (btn)  { btn.disabled  = false; btn.innerHTML  = '🔊 Boost +16dB (<span id="selectedBoostCount">0</span>)'; }
                     if (btnF) { btnF.disabled = false; btnF.innerHTML = '🔊 Boost +16dB (<span id="selectedBoostCountFloating">0</span>)'; }
                 }
             } catch (error) {
                 alert('❌ Lỗi kết nối: ' + error.message);
                 if (btn)  { btn.disabled  = false; btn.innerHTML  = '🔊 Boost +16dB (<span id="selectedBoostCount">0</span>)'; }
                 if (btnF) { btnF.disabled = false; btnF.innerHTML = '🔊 Boost +16dB (<span id="selectedBoostCountFloating">0</span>)'; }
             }
         }

         // Generate Video (MP4) for selected chapters using FFmpeg
         async function generateVideoForSelectedChapters() {
             const selectedCheckboxes = document.querySelectorAll('.chapter-checkbox:checked');
             if (selectedCheckboxes.length === 0) {
                 alert('Vui lòng chọn ít nhất một chương');
                 return;
             }

             const chapterIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterId);
             const chapterNumbers = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterNumber);

             const confirmMsg =
                 `Bạn có muốn tạo Video MP4 cho ${chapterIds.length} chương?\n\nChương: ${chapterNumbers.join(', ')}\n\nVideo sẽ được tạo từ file audio full và ảnh bìa chương.`;
             if (!confirm(confirmMsg)) return;

             const btn = document.getElementById('generateSelectedVideoBtn');
             const originalText = btn.innerHTML;
             btn.disabled = true;
             btn.innerHTML = '⏳ Đang tạo video...';

             // Show progress
             let progressHtml = `
                <div id="videoProgressContainer" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-red-800" id="videoProgressStatus">Đang tạo video...</span>
                        <span class="text-sm text-red-600" id="videoProgressPercent">0%</span>
                    </div>
                    <div class="w-full bg-red-200 rounded-full h-2">
                        <div id="videoProgressBar" class="bg-red-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                    <div id="videoLogContainer" class="mt-3 max-h-40 overflow-y-auto text-xs font-mono bg-gray-900 text-green-400 p-2 rounded"></div>
                </div>
            `;

             const ttsProgress = document.getElementById('ttsProgressContainer');
             let existingProgress = document.getElementById('videoProgressContainer');
             if (existingProgress) existingProgress.remove();
             ttsProgress.insertAdjacentHTML('afterend', progressHtml);

             const progressBar = document.getElementById('videoProgressBar');
             const progressStatus = document.getElementById('videoProgressStatus');
             const progressPercent = document.getElementById('videoProgressPercent');
             const logContainer = document.getElementById('videoLogContainer');

             function addLog(message, type = 'info') {
                 const colors = {
                     info: 'text-blue-400',
                     success: 'text-green-400',
                     error: 'text-red-400',
                     warning: 'text-yellow-400'
                 };
                 logContainer.innerHTML +=
                     `<div class="${colors[type]}">[${new Date().toLocaleTimeString()}] ${message}</div>`;
                 logContainer.scrollTop = logContainer.scrollHeight;
             }

             try {
                 addLog(`Bắt đầu tạo video cho ${chapterIds.length} chương...`);

                 let successCount = 0;
                 let errorCount = 0;

                 for (let i = 0; i < chapterIds.length; i++) {
                     const chapterId = chapterIds[i];
                     const chapterNumber = chapterNumbers[i];
                     const progress = Math.round(((i + 1) / chapterIds.length) * 100);

                     progressStatus.textContent =
                         `Đang tạo video chương ${chapterNumber} (${i + 1}/${chapterIds.length})...`;
                     progressPercent.textContent = `${progress}%`;
                     progressBar.style.width = `${progress}%`;

                     addLog(`Đang xử lý chương ${chapterNumber}... (có thể mất 1-3 phút)`);

                     try {
                         const response = await fetch(
                             `/audiobooks/{{ $audioBook->id }}/generate-chapter-video/${chapterId}`, {
                                 method: 'POST',
                                 headers: {
                                     'Content-Type': 'application/json',
                                     'X-CSRF-TOKEN': getCsrfToken(),
                                     'Accept': 'application/json'
                                 },
                                 signal: AbortSignal.timeout(1800000) // 30 minutes timeout
                             });

                         const result = await safeJson(response);

                         if (result.success) {
                             addLog(`✅ Chương ${chapterNumber}: Video tạo thành công! (${result.file_size} MB)`,
                                 'success');
                             successCount++;

                             // Update chapter row with video link
                             const chapterDiv = document.getElementById(`chapter-${chapterId}`);
                             if (chapterDiv && result.video_url) {
                                 // Find or create video preview section
                                 let videoSection = chapterDiv.querySelector('.video-preview-section');
                                 if (!videoSection) {
                                     const audioSection = chapterDiv.querySelector('.border-t.border-gray-100');
                                     if (audioSection) {
                                         const videoHtml = `
                                            <div class="video-preview-section mb-3 p-3 bg-gradient-to-r from-blue-100 to-cyan-100 border-2 border-blue-300 rounded-lg">
                                                <div class="flex items-center justify-between flex-wrap gap-2">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-lg">🎬</span>
                                                        <span class="font-semibold text-blue-800">Video Chương ${chapterNumber}</span>
                                                        <span class="text-xs bg-blue-200 text-blue-700 px-2 py-0.5 rounded">${result.file_size} MB</span>
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        <button onclick="openVideoPreview('${result.video_url}')"
                                                            class="bg-blue-500 hover:bg-blue-600 text-white text-xs px-3 py-1.5 rounded transition">
                                                            ▶️ Xem
                                                        </button>
                                                        <a href="${result.video_url}" download="chapter_${chapterNumber}.mp4"
                                                            class="bg-green-500 hover:bg-green-600 text-white text-xs px-3 py-1.5 rounded transition">
                                                            ⬇️ Tải xuống
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        `;
                                         // Insert after the full chapter audio section
                                         const fullAudioSection = audioSection.querySelector(
                                             '.bg-gradient-to-r.from-purple-100');
                                         if (fullAudioSection) {
                                             fullAudioSection.insertAdjacentHTML('afterend', videoHtml);
                                         } else {
                                             audioSection.insertAdjacentHTML('afterbegin', videoHtml);
                                         }
                                     }
                                 }
                             }
                         } else {
                             addLog(`❌ Chương ${chapterNumber}: ${result.error}`, 'error');
                             errorCount++;
                         }
                     } catch (error) {
                         addLog(`❌ Chương ${chapterNumber}: ${error.message}`, 'error');
                         errorCount++;
                     }
                 }

                 progressStatus.textContent = `Hoàn thành: ${successCount} thành công, ${errorCount} lỗi`;
                 progressPercent.textContent = '100%';
                 progressBar.style.width = '100%';

                 addLog(`🎬 Hoàn thành! ${successCount}/${chapterIds.length} video đã được tạo.`, successCount ===
                     chapterIds.length ? 'success' : 'warning');

                 // Suggest reload to see all videos properly
                 if (successCount > 0) {
                     addLog(`💡 Reload trang để xem tất cả video.`, 'info');
                 }

             } catch (error) {
                 addLog(`❌ Lỗi: ${error.message}`, 'error');
                 progressStatus.textContent = `Lỗi: ${error.message}`;
             } finally {
                 btn.disabled = false;
                 btn.innerHTML = originalText;
             }
         }

         // Generate TTS for selected chapters - async queue
         let ttsBatchTimer = null;

         function stopTtsBatchPolling() {
             if (ttsBatchTimer) {
                 clearInterval(ttsBatchTimer);
                 ttsBatchTimer = null;
             }
         }

         function startTtsBatchPolling() {
             stopTtsBatchPolling();
             pollTtsBatchProgress();
             ttsBatchTimer = setInterval(pollTtsBatchProgress, 3000);
         }

         async function pollTtsBatchProgress() {
             const progressContainer = document.getElementById('ttsProgressContainer');
             const progressBar = document.getElementById('ttsProgressBar');
             const progressStatus = document.getElementById('ttsProgressStatus');
             const progressPercent = document.getElementById('ttsProgressPercent');
             const chunkStatus = document.getElementById('ttsChunkStatus');
             const chunkPercent = document.getElementById('ttsChunkPercent');
             const chunkBar = document.getElementById('ttsChunkBar');
             const logContainer = document.getElementById('ttsLogContainer');
             const generateBtn = document.getElementById('generateSelectedTtsBtn');

             try {
                 const resp = await fetch(`/audiobooks/${audioBookId}/chapters/tts/progress`, {
                     headers: {
                         'Accept': 'application/json'
                     }
                 });
                 const data = await safeJson(resp);

                 if (!data.success) return;
                 if (data.status === 'idle') {
                     stopTtsBatchPolling();
                     return;
                 }

                 progressContainer.classList.remove('hidden');
                 const pct = typeof data.percent === 'number' ? data.percent : 1;
                 progressBar.style.width = `${pct}%`;
                 progressPercent.textContent = `${pct}%`;
                 progressStatus.textContent = data.message || 'Đang xử lý...';

                 if (data.current_chapter_number && data.current_chunk_number && data.current_chunk_total) {
                     chunkStatus.textContent =
                         `Chương ${data.current_chapter_number}: Đoạn ${data.current_chunk_number}/${data.current_chunk_total}`;
                 }
                 if (typeof data.chunk_percent === 'number') {
                     chunkBar.style.width = `${data.chunk_percent}%`;
                     chunkPercent.textContent = `${data.chunk_percent}%`;
                 }

                 if (Array.isArray(data.logs)) {
                     logContainer.innerHTML = data.logs.map(line => `<div class="text-green-400">${line}</div>`).join(
                         '');
                     logContainer.scrollTop = logContainer.scrollHeight;
                 }

                 if (data.status === 'completed') {
                     stopTtsBatchPolling();
                     progressStatus.textContent = data.message || 'Hoàn tất!';
                     progressContainer.classList.remove('bg-blue-50', 'border-blue-200');
                     progressContainer.classList.add('bg-green-50', 'border-green-200');
                     progressStatus.classList.remove('text-blue-800');
                     progressStatus.classList.add('text-green-800');
                     if (generateBtn) {
                         generateBtn.disabled = false;
                         generateBtn.innerHTML =
                             '🎙️ Tạo TTS (<span id="selectedCount">' +
                             document.querySelectorAll('.chapter-checkbox:checked').length +
                             '</span>)';
                     }
                 }

                 if (data.status === 'error') {
                     stopTtsBatchPolling();
                     progressStatus.textContent = data.message || 'Có lỗi xảy ra.';
                     progressContainer.classList.remove('bg-blue-50', 'border-blue-200');
                     progressContainer.classList.add('bg-yellow-50', 'border-yellow-200');
                     progressStatus.classList.remove('text-blue-800');
                     progressStatus.classList.add('text-yellow-800');
                     if (generateBtn) {
                         generateBtn.disabled = false;
                         generateBtn.innerHTML =
                             '🎙️ Tạo TTS (<span id="selectedCount">' +
                             document.querySelectorAll('.chapter-checkbox:checked').length +
                             '</span>)';
                     }
                 }
             } catch (e) {
                 // ignore transient errors
             }
         }

         async function checkTtsBatchProgressOnLoad() {
             try {
                 const resp = await fetch(`/audiobooks/${audioBookId}/chapters/tts/progress`, {
                     headers: {
                         'Accept': 'application/json'
                     }
                 });
                 const data = await safeJson(resp);
                 if (data.success && (data.status === 'processing' || data.status === 'queued')) {
                     startTtsBatchPolling();
                 }
             } catch (e) {
                 // ignore
             }
         }

         async function generateTtsForSelectedChapters() {
             const selectedCheckboxes = document.querySelectorAll('.chapter-checkbox:checked');
             if (selectedCheckboxes.length === 0) {
                 alert('Vui lòng chọn ít nhất một chương');
                 return;
             }

             const provider = document.getElementById('ttsProviderSelect').value;
             const voiceName = document.getElementById('voiceNameSelect').value;

             if (!provider || !voiceName) {
                 alert('Vui lòng cấu hình TTS Settings trước (Provider và Voice)');
                 return;
             }

             const chapterIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterId);
             const chapterNumbers = Array.from(selectedCheckboxes).map(cb => cb.dataset.chapterNumber);
             const confirmMsg =
                 `Bạn có muốn tạo TTS cho ${chapterIds.length} chương?\n\nChương: ${chapterNumbers.join(', ')}\n\nQuá trình sẽ chạy nền.`;
             if (!confirm(confirmMsg)) return;

             const progressContainer = document.getElementById('ttsProgressContainer');
             const progressBar = document.getElementById('ttsProgressBar');
             const progressStatus = document.getElementById('ttsProgressStatus');
             const progressPercent = document.getElementById('ttsProgressPercent');
             const chunkStatus = document.getElementById('ttsChunkStatus');
             const chunkPercent = document.getElementById('ttsChunkPercent');
             const chunkBar = document.getElementById('ttsChunkBar');
             const logContainer = document.getElementById('ttsLogContainer');
             const generatedChunksContainer = document.getElementById('ttsGeneratedChunks');
             const generateBtn = document.getElementById('generateSelectedTtsBtn');

             progressContainer.classList.remove('hidden', 'bg-yellow-50', 'border-yellow-200', 'bg-green-50',
                 'border-green-200');
             progressContainer.classList.add('bg-blue-50', 'border-blue-200');
             progressStatus.classList.remove('text-yellow-800', 'text-green-800');
             progressStatus.classList.add('text-blue-800');
             progressBar.style.width = '1%';
             progressPercent.textContent = '1%';
             chunkBar.style.width = '0%';
             chunkPercent.textContent = '0%';
             chunkStatus.textContent = 'Đang xếp hàng...';
             logContainer.innerHTML = '';
             generatedChunksContainer.innerHTML = '';
             generateBtn.disabled = true;
             generateBtn.innerHTML = '⏳ Đang xếp hàng...';

             const providersWithoutStyle = ['microsoft', 'openai', 'vbee'];
             const ttsSettings = {
                 provider: provider,
                 voice_name: voiceName,
                 voice_gender: document.querySelector('input[name="voiceGender"]:checked')?.value || 'female',
                 tts_speed: parseFloat(document.getElementById('ttsSpeedSelect').value) || 1.0,
                 pause_between_chunks: Number.isFinite(parseFloat(document.getElementById('pauseBetweenChunksSelect')
                     .value)) ? parseFloat(document.getElementById('pauseBetweenChunksSelect').value) : 0.0
             };
             if (!providersWithoutStyle.includes(provider)) {
                 ttsSettings.style_instruction = document.getElementById('ttsStyleInstruction').value;
             }

             const resp = await fetch(`/audiobooks/${audioBookId}/chapters/tts/start`, {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/json',
                     'X-CSRF-TOKEN': getCsrfToken(),
                     'Accept': 'application/json'
                 },
                 body: JSON.stringify({
                     chapter_ids: chapterIds,
                     ...ttsSettings
                 })
             });

             const result = await safeJson(resp);
             if (!result.success) {
                 generateBtn.disabled = false;
                 generateBtn.innerHTML = '🎙️ Tạo TTS (<span id="selectedCount">' + selectedCheckboxes.length +
                     '</span>)';
                 throw new Error(result.error || 'Không thể đưa vào hàng đợi');
             }

             progressStatus.textContent = 'Đã đưa vào hàng đợi. Bạn có thể tắt trình duyệt.';
             startTtsBatchPolling();
         }

         checkTtsBatchProgressOnLoad();

         // Delete chapter audio files
         async function deleteChapterAudio(chapterId, deleteAll = true) {
             const msg = deleteAll ?
                 'Xóa TẤT CẢ audio của chương này (chunks + full)?' :
                 'Chỉ xóa file FULL của chương này?';
             if (!confirm(msg)) return;

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/chapters/${chapterId}/delete-audio`, {
                     method: 'DELETE',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         delete_chunks: deleteAll,
                         delete_merged: true
                     })
                 });

                 const result = await safeJson(response);

                 if (result.success) {
                     alert(`Đã xóa ${result.count} file audio`);
                     window.location.reload();
                 } else {
                     throw new Error(result.error);
                 }
             } catch (error) {
                 alert('Lỗi: ' + error.message);
             }
         }

         // Delete audio for a single chunk
         async function deleteChunkAudio(bookId, chapterId, chunkId, btnEl) {
             if (!confirm('Xóa audio của đoạn này?')) return;

             const originalHtml = btnEl.innerHTML;
             btnEl.disabled = true;
             btnEl.innerHTML = '⏳';

             try {
                 const response = await fetch(
                     `/audiobooks/${bookId}/chapters/${chapterId}/chunks/${chunkId}/delete-audio`, {
                         method: 'DELETE',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': getCsrfToken()
                         }
                     });

                 const result = await safeJson(response);

                 if (result.success) {
                     // Update the chunk card UI without reloading
                     const chunkCard = btnEl.closest('.flex.items-center.justify-between');
                     if (chunkCard) {
                         // Change styling to pending (gray)
                         chunkCard.className =
                             'flex items-center justify-between p-2 rounded text-sm bg-gray-50 border border-gray-200';

                         // Update status icon & text
                         const statusSpan = chunkCard.querySelector('.text-xs.font-medium');
                         if (statusSpan) {
                             statusSpan.className = 'text-xs font-medium text-gray-600';
                             statusSpan.innerHTML = '⏸️ Đoạn ' + result.chunk_number;
                         }

                         // Remove duration
                         const durationSpan = chunkCard.querySelector('.text-xs.text-gray-500');
                         if (durationSpan) durationSpan.remove();

                         // Remove audio player & delete button
                         const audioContainer = chunkCard.querySelector('.flex.items-center.gap-1');
                         if (audioContainer) audioContainer.innerHTML = '';
                     }
                 } else {
                     throw new Error(result.error);
                 }
             } catch (error) {
                 alert('Lỗi: ' + error.message);
                 btnEl.disabled = false;
                 btnEl.innerHTML = originalHtml;
             }
         }

         // ========== ADD TEXT OVERLAY MODAL ==========
         let selectedAddTextFilename = '';

         window.openAddTextModal = function(filename, imageUrl) {
             selectedAddTextFilename = filename;
             document.getElementById('addTextPreviewImage').src = imageUrl;
             document.getElementById('addTextFilename').value = filename;
             document.getElementById('addTextModal').classList.remove('hidden');
             document.getElementById('addTextStatus').innerHTML = '';
         };

         function closeAddTextModal() {
             document.getElementById('addTextModal').classList.add('hidden');
             selectedAddTextFilename = '';
         }

         // Sync color pickers for Add Text Modal
         function syncAddTextColorInputs(colorId, hexId) {
             const colorInput = document.getElementById(colorId);
             const hexInput = document.getElementById(hexId);
             if (colorInput && hexInput) {
                 colorInput.addEventListener('input', () => hexInput.value = colorInput.value);
                 hexInput.addEventListener('input', () => {
                     if (/^#[0-9A-Fa-f]{6}$/.test(hexInput.value)) {
                         colorInput.value = hexInput.value;
                     }
                 });
             }
         }
         syncAddTextColorInputs('addTextColor', 'addTextColorHex');
         syncAddTextColorInputs('addTextBorderColor', 'addTextBorderColorHex');
         syncAddTextColorInputs('addTextBgColor', 'addTextBgColorHex');

         // Show/hide background color section for Add Text Modal
         document.querySelectorAll('input[name="addTextBgStyle"]').forEach(radio => {
             radio.addEventListener('change', function() {
                 const bgColorSection = document.getElementById('addTextBgColorSection');
                 if (this.value === 'solid' || this.value === 'gradient') {
                     bgColorSection.classList.remove('hidden');
                 } else {
                     bgColorSection.classList.add('hidden');
                 }
             });
         });

         // Background opacity slider for Add Text
         document.getElementById('addTextBgOpacity')?.addEventListener('input', function() {
             document.getElementById('addTextBgOpacityValue').textContent = this.value + '%';
         });

         // Font size slider for Add Text
         document.getElementById('addTextFontSize')?.addEventListener('input', function() {
             document.getElementById('addTextFontSizeValue').textContent = this.value + 'px';
         });

         // Presets for Add Text Modal
         document.querySelectorAll('.add-text-preset-btn').forEach(btn => {
             btn.addEventListener('click', function() {
                 const preset = this.dataset.preset;
                 const presets = {
                     'classic': {
                         textColor: '#ffffff',
                         borderColor: '#000000',
                         borderWidth: '4',
                         bgStyle: 'none',
                         fontSize: 60
                     },
                     'fire': {
                         textColor: '#ffff00',
                         borderColor: '#ff0000',
                         borderWidth: '6',
                         bgStyle: 'none',
                         fontSize: 70
                     },
                     'neon': {
                         textColor: '#00ffff',
                         borderColor: '#8b00ff',
                         borderWidth: '4',
                         bgStyle: 'blur',
                         fontSize: 65
                     },
                     'nature': {
                         textColor: '#ffffff',
                         borderColor: '#228b22',
                         borderWidth: '4',
                         bgStyle: 'solid',
                         bgColor: '#000000',
                         bgOpacity: 50,
                         fontSize: 60
                     },
                     'vintage': {
                         textColor: '#8b4513',
                         borderColor: '#daa520',
                         borderWidth: '3',
                         bgStyle: 'solid',
                         bgColor: '#f5deb3',
                         bgOpacity: 80,
                         fontSize: 55
                     }
                 };
                 const p = presets[preset];
                 if (p) {
                     document.getElementById('addTextColor').value = p.textColor;
                     document.getElementById('addTextColorHex').value = p.textColor;
                     document.getElementById('addTextBorderColor').value = p.borderColor;
                     document.getElementById('addTextBorderColorHex').value = p.borderColor;
                     document.getElementById('addTextBorderWidth').value = p.borderWidth;
                     document.querySelector(`input[name="addTextBgStyle"][value="${p.bgStyle}"]`).checked =
                         true;
                     document.getElementById('addTextFontSize').value = p.fontSize;
                     document.getElementById('addTextFontSizeValue').textContent = p.fontSize + 'px';

                     const bgColorSection = document.getElementById('addTextBgColorSection');
                     if (p.bgStyle === 'solid' || p.bgStyle === 'gradient') {
                         bgColorSection.classList.remove('hidden');
                         if (p.bgColor) {
                             document.getElementById('addTextBgColor').value = p.bgColor;
                             document.getElementById('addTextBgColorHex').value = p.bgColor;
                         }
                         if (p.bgOpacity !== undefined) {
                             document.getElementById('addTextBgOpacity').value = p.bgOpacity;
                             document.getElementById('addTextBgOpacityValue').textContent = p.bgOpacity +
                                 '%';
                         }
                     } else {
                         bgColorSection.classList.add('hidden');
                     }
                 }
             });
         });

         // Apply text overlay to image
         async function applyTextOverlay() {
             const btn = document.getElementById('applyTextOverlayBtn');
             const statusDiv = document.getElementById('addTextStatus');
             const filename = document.getElementById('addTextFilename').value;

             if (!filename) {
                 statusDiv.innerHTML = '<span class="text-red-600">❌ Không tìm thấy hình ảnh</span>';
                 return;
             }

             const textElements = {
                 title: document.getElementById('addTextTitle').value.trim(),
                 author: document.getElementById('addTextAuthor').value.trim(),
                 chapter: document.getElementById('addTextChapter').value.trim()
             };

             const styling = {
                 position: document.querySelector('input[name="addTextPosition"]:checked')?.value || 'bottom',
                 textColor: document.getElementById('addTextColorHex').value || '#ffffff',
                 borderColor: document.getElementById('addTextBorderColorHex').value || '#000000',
                 borderWidth: parseInt(document.getElementById('addTextBorderWidth').value || '4'),
                 bgStyle: document.querySelector('input[name="addTextBgStyle"]:checked')?.value || 'none',
                 bgColor: document.getElementById('addTextBgColorHex').value || '#000000',
                 bgOpacity: parseInt(document.getElementById('addTextBgOpacity').value || '70'),
                 titleFontSize: parseInt(document.getElementById('addTextFontSize').value || '60')
             };

             btn.disabled = true;
             btn.innerHTML = '⏳ Đang tạo...';
             statusDiv.innerHTML = '<span class="text-blue-600">🎨 Đang thêm text overlay...</span>';

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/media/add-text-overlay`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         source_image: filename,
                         text_elements: textElements,
                         styling: styling
                     })
                 });

                 const result = await safeJson(response);

                 if (result.success) {
                     statusDiv.innerHTML = '<span class="text-green-600">✅ Đã tạo thumbnail thành công!</span>';
                     refreshMediaGallery();
                     setTimeout(() => closeAddTextModal(), 1500);
                 } else {
                     throw new Error(result.error || 'Không thể tạo thumbnail');
                 }
             } catch (error) {
                 statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
             } finally {
                 btn.disabled = false;
                 btn.innerHTML = '✨ Tạo Thumbnail với Text';
             }
         }

         // ========== ADD LOGO OVERLAY ==========
         let selectedLogoFilename = '';

         window.openAddLogoModal = function(filename, imageUrl) {
             selectedLogoFilename = filename;
             document.getElementById('addLogoPreviewImage').src = imageUrl;
             document.getElementById('addLogoFilename').value = filename;
             document.getElementById('addLogoModal').classList.remove('hidden');
             document.getElementById('addLogoStatus').innerHTML = '';
         };

         function closeAddLogoModal() {
             document.getElementById('addLogoModal').classList.add('hidden');
             selectedLogoFilename = '';
         }

         // Logo position buttons
         document.querySelectorAll('.logo-pos-btn').forEach(function(btn) {
             btn.addEventListener('click', function() {
                 document.querySelectorAll('.logo-pos-btn').forEach(function(b) {
                     b.classList.remove('border-orange-500', 'bg-orange-50', 'font-semibold');
                     b.classList.add('bg-white', 'border-gray-200');
                 });
                 this.classList.remove('bg-white', 'border-gray-200');
                 this.classList.add('border-orange-500', 'bg-orange-50', 'font-semibold');
                 document.getElementById('logoPositionValue').value = this.dataset.position;
             });
         });

         // Logo scale slider
         document.getElementById('logoScale')?.addEventListener('input', function() {
             document.getElementById('logoScaleValue').textContent = this.value + '%';
         });

         // Logo opacity slider
         document.getElementById('logoOpacity')?.addEventListener('input', function() {
             document.getElementById('logoOpacityValue').textContent = this.value + '%';
         });

         async function applyLogoOverlay() {
             const btn = document.getElementById('applyLogoOverlayBtn');
             const statusDiv = document.getElementById('addLogoStatus');
             const filename = document.getElementById('addLogoFilename').value;

             if (!filename) {
                 statusDiv.innerHTML = '<span class="text-red-600">Không tìm thấy hình ảnh</span>';
                 return;
             }

             const position = document.getElementById('logoPositionValue')?.value || 'bottom-right';
             const logoScale = parseInt(document.getElementById('logoScale').value || '15');
             const opacity = parseInt(document.getElementById('logoOpacity').value || '100');
             const margin = parseInt(document.getElementById('logoMargin').value || '20');

             btn.disabled = true;
             btn.innerHTML = '⏳ Đang xử lý...';
             statusDiv.innerHTML = '<span class="text-blue-600">Đang thêm logo...</span>';

             try {
                 const response = await fetch(`/audiobooks/${audioBookId}/media/add-logo-overlay`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     body: JSON.stringify({
                         source_image: filename,
                         position: position,
                         logo_scale: logoScale,
                         opacity: opacity,
                         margin: margin
                     })
                 });

                 const result = await safeJson(response);

                 if (result.success) {
                     statusDiv.innerHTML = '<span class="text-green-600">✅ Đã thêm logo thành công!</span>';
                     refreshMediaGallery();
                     setTimeout(() => closeAddLogoModal(), 1500);
                 } else {
                     throw new Error(result.error || 'Không thể thêm logo');
                 }
             } catch (error) {
                 statusDiv.innerHTML = `<span class="text-red-600">${error.message}</span>`;
             } finally {
                 btn.disabled = false;
                 btn.innerHTML = '🏷️ Gắn Logo';
             }
         }

         // ========== CHAPTER COVER GENERATION ==========
         let selectedCoverImageFilename = '';

         window.openChapterCoverModal = async function(filename, imageUrl) {
             selectedCoverImageFilename = filename;
             document.getElementById('selectedCoverImage').src = imageUrl;
             document.getElementById('selectedCoverFilename').textContent = filename;
             document.getElementById('chapterCoverModal').classList.remove('hidden');
             document.getElementById('chapterCoverStatus').innerHTML = '';
             document.getElementById('chapterCoverProgress').classList.add('hidden');

             // Load chapters list
             await loadChaptersForCover();
         };

         function closeChapterCoverModal() {
             document.getElementById('chapterCoverModal').classList.add('hidden');
             selectedCoverImageFilename = '';
         }

         async function loadChaptersForCover() {
             const listDiv = document.getElementById('chapterCoverList');
             listDiv.innerHTML = '<div class="text-center py-4 text-gray-500">Đang tải danh sách chương...</div>';

             try {
                 const response = await fetch(`/audiobooks/{{ $audioBook->id }}/chapters-for-cover`, {
                     method: 'GET',
                     headers: {
                         'Accept': 'application/json',
                         'X-Requested-With': 'XMLHttpRequest',
                         'X-CSRF-TOKEN': getCsrfToken()
                     },
                     credentials: 'same-origin'
                 });

                 if (!response.ok) {
                     throw new Error(`HTTP error! status: ${response.status}`);
                 }

                 const result = await response.json();

                 if (!result.success || !result.chapters.length) {
                     listDiv.innerHTML = '<div class="text-center py-4 text-gray-500">Không có chương nào</div>';
                     return;
                 }

                 listDiv.innerHTML = result.chapters.map(ch => `
                    <label class="flex items-center gap-3 p-2 hover:bg-gray-50 rounded cursor-pointer">
                        <input type="checkbox" class="chapter-cover-checkbox rounded" value="${ch.id}" data-chapter="${ch.chapter_number}">
                        <div class="w-7 h-7 bg-purple-600 text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0">
                            ${ch.chapter_number}
                        </div>
                        <div class="flex-1 truncate">
                            <span class="text-gray-800">${ch.title || 'Chưa có tiêu đề'}</span>
                        </div>
                        ${ch.has_cover ? `
                                                                                                                                                                                                                                  <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded">Đã có bìa</span>
                                                                                                                                                                                                                              ` : `
                                                                                                                                                                                                                                  <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Chưa có bìa</span>
                                                                                                                                                                                                                              `}
                    </label>
                `).join('');

             } catch (error) {
                 listDiv.innerHTML = `<div class="text-center py-4 text-red-500">Lỗi: ${error.message}</div>`;
             }
         }

         function toggleAllChaptersCover() {
             const selectAll = document.getElementById('selectAllChaptersCover').checked;
             document.querySelectorAll('.chapter-cover-checkbox').forEach(cb => cb.checked = selectAll);
         }

         // ========== TEXT POSITION SELECTION ==========
         function selectTextPosition(event) {
             const img = event.currentTarget;
             const rect = img.getBoundingClientRect();

             // Calculate click position relative to image (0-100%)
             const x = ((event.clientX - rect.left) / rect.width) * 100;
             const y = ((event.clientY - rect.top) / rect.height) * 100;

             // Update hidden inputs
             document.getElementById('textPositionX').value = x.toFixed(2);
             document.getElementById('textPositionY').value = y.toFixed(2);

             // Show marker at clicked position
             const marker = document.getElementById('textPositionMarker');
             marker.classList.remove('hidden');
             marker.style.left = x + '%';
             marker.style.top = y + '%';

             // Update preview text position
             updateChapterTextPreview();
         }

         function updateChapterTextPreview() {
             const fontSize = document.getElementById('chapterFontSize').value;
             const textColor = document.querySelector('input[name="chapterTextColor"]:checked').value;
             const outlineColor = document.querySelector('input[name="chapterOutlineColor"]:checked').value;
             const outlineWidth = document.getElementById('chapterOutlineWidth').value;
             const textMode = document.querySelector('input[name="chapterTextMode"]:checked')?.value || 'number';
             const posX = document.getElementById('textPositionX').value;
             const posY = document.getElementById('textPositionY').value;

             // Update displays
             document.getElementById('fontSizeDisplay').textContent = fontSize;
             document.getElementById('outlineWidthDisplay').textContent = outlineWidth;

             // Update preview badge
             const preview = document.getElementById('textLivePreview');
             const badge = document.getElementById('previewChapterBadge');

             preview.classList.remove('hidden');
             preview.style.left = posX + '%';
             preview.style.top = posY + '%';

             // Apply styling to preview badge
             const scaleFactor = fontSize / 80; // Base size is 80
             badge.style.fontSize = (14 * scaleFactor) + 'px';
             badge.style.color = textColor;
             badge.style.backgroundColor = outlineColor;
             badge.style.borderWidth = (outlineWidth / 2) + 'px';
             badge.style.borderColor = outlineColor;

             if (textMode === 'title') {
                 badge.textContent = 'Tên chương';
             } else if (textMode === 'both') {
                 badge.textContent = 'Chương 1: Tên chương';
             } else {
                 badge.textContent = 'Chương 1';
             }

             // Add text shadow for better visibility
             const shadowColor = textColor === '#FFFFFF' ? 'rgba(0,0,0,0.3)' : 'rgba(255,255,255,0.3)';
             badge.style.textShadow = `0 2px 4px ${shadowColor}`;
         }

         async function generateChapterCovers() {
             const chapterCheckboxes = document.querySelectorAll('.chapter-cover-checkbox:checked');
             const segmentCheckboxes = document.querySelectorAll('.segment-cover-checkbox:checked');
             if (chapterCheckboxes.length === 0 && segmentCheckboxes.length === 0) {
                 alert('Vui lòng chọn ít nhất 1 chương hoặc 1 phần');
                 return;
             }

             const chapterIds = Array.from(chapterCheckboxes).map(cb => parseInt(cb.value));
             const segmentIds = Array.from(segmentCheckboxes).map(cb => parseInt(cb.value));
             const totalItems = chapterIds.length + segmentIds.length;
             const btn = document.getElementById('generateChapterCoversBtn');
             const statusDiv = document.getElementById('chapterCoverStatus');
             const progressDiv = document.getElementById('chapterCoverProgress');
             const progressBar = document.getElementById('chapterCoverProgressBar');
             const progressText = document.getElementById('chapterCoverProgressText');
             const progressPercent = document.getElementById('chapterCoverProgressPercent');

             // Get text formatting options
             const fontSize = parseInt(document.getElementById('chapterFontSize').value);
             const textColor = document.querySelector('input[name="chapterTextColor"]:checked').value;
             const outlineColor = document.querySelector('input[name="chapterOutlineColor"]:checked').value;
             const outlineWidth = parseInt(document.getElementById('chapterOutlineWidth').value);
             const textMode = document.querySelector('input[name="chapterTextMode"]:checked')?.value || 'number';
             const posX = parseFloat(document.getElementById('textPositionX').value);
             const posY = parseFloat(document.getElementById('textPositionY').value);

             btn.disabled = true;
             btn.innerHTML = '⏳ Đang tạo...';
             progressDiv.classList.remove('hidden');
             progressBar.style.width = '10%';
             const label = [];
             if (chapterIds.length > 0) label.push(`${chapterIds.length} chương`);
             if (segmentIds.length > 0) label.push(`${segmentIds.length} phần`);
             progressText.textContent = `Đang tạo ảnh bìa cho ${label.join(' + ')}...`;
             progressPercent.textContent = '10%';
             statusDiv.innerHTML = '<span class="text-blue-600">🎨 Đang xử lý với FFmpeg...</span>';

             try {
                 const payload = {
                     image_filename: selectedCoverImageFilename,
                     text_options: {
                         font_size: fontSize,
                         text_color: textColor,
                         outline_color: outlineColor,
                         outline_width: outlineWidth,
                         text_mode: textMode,
                         position_x: posX,
                         position_y: posY
                     }
                 };
                 if (chapterIds.length > 0) payload.chapter_ids = chapterIds;
                 if (segmentIds.length > 0) payload.segment_ids = segmentIds;

                 const response = await fetch(`/audiobooks/{{ $audioBook->id }}/generate-chapter-covers`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': getCsrfToken(),
                         'Accept': 'application/json'
                     },
                     body: JSON.stringify(payload)
                 });

                 const result = await safeJson(response);

                 if (result.success) {
                     progressBar.style.width = '100%';
                     progressPercent.textContent = '100%';
                     progressText.textContent = result.message;

                     const successResults = result.results.filter(r => r.success);
                     const failedResults = result.results.filter(r => !r.success);

                     let statusHtml = `<span class="text-green-600">✅ ${result.message}</span>`;

                     // Show segment cover previews
                     const segResults = successResults.filter(r => r.segment_id);
                     if (segResults.length > 0) {
                         statusHtml += '<div class="mt-2 grid grid-cols-3 gap-2">';
                         segResults.forEach(r => {
                             statusHtml += `<div class="text-center">
                                <img src="${r.cover_image}?t=${Date.now()}" class="w-full h-20 object-cover rounded border shadow-sm">
                                <span class="text-[10px] text-teal-700">${r.segment_name}</span>
                            </div>`;
                         });
                         statusHtml += '</div>';
                     }

                     if (failedResults.length > 0) {
                         statusHtml +=
                             '<br><span class="text-red-600">❌ Lỗi:</span><ul class="text-xs text-red-600 ml-4">';
                         failedResults.forEach(r => {
                             const label = r.chapter_number ? `Chương ${r.chapter_number}` : (r.segment_name ||
                                 'Unknown');
                             statusHtml += `<li>${label}: ${r.error}</li>`;
                         });
                         statusHtml += '</ul>';
                     }

                     statusDiv.innerHTML = statusHtml;

                     // Update chapter cover images in main list immediately
                     successResults.filter(r => r.chapter_id).forEach(r => {
                         const chapterDiv = document.getElementById(`chapter-${r.chapter_id}`);
                         if (chapterDiv) {
                             // Find the image or placeholder container
                             const existingImg = chapterDiv.querySelector('img[alt]');
                             const placeholder = chapterDiv.querySelector('.w-20.h-12.bg-gray-100');

                             if (existingImg) {
                                 // Update existing image with cache buster
                                 existingImg.src = r.cover_image + '?t=' + Date.now();
                             } else if (placeholder) {
                                 // Replace placeholder with new image
                                 const newImg = document.createElement('img');
                                 newImg.src = r.cover_image + '?t=' + Date.now();
                                 newImg.alt = `Chương ${r.chapter_number}`;
                                 newImg.className =
                                     'w-20 h-12 object-cover rounded cursor-pointer hover:opacity-80 transition border shadow-sm';
                                 newImg.onclick = function() {
                                     openImagePreview(r.cover_image);
                                 };
                                 newImg.title = 'Click để xem lớn';
                                 placeholder.replaceWith(newImg);
                             }
                         }
                     });

                     // Update segment image data in Video Segments planner
                     if (segResults.length > 0 && typeof segments !== 'undefined') {
                         segResults.forEach(r => {
                             const seg = segments.find(s => s.id === r.segment_id);
                             if (seg) {
                                 seg.image_path = r.cover_image.split('/').pop().split('?')[0];
                                 seg.image_type = 'chapter_covers';
                             }
                         });
                         if (typeof renderSegments === 'function') renderSegments();
                     }

                     // Reload chapters list in modal to show updated status
                     setTimeout(() => loadChaptersForCover(), 1000);

                 } else {
                     throw new Error(result.error || 'Không thể tạo ảnh bìa');
                 }

             } catch (error) {
                 statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;
                 progressDiv.classList.add('hidden');
             } finally {
                 btn.disabled = false;
                 btn.innerHTML = '🎨 Tạo ảnh bìa cho chương đã chọn';
             }
         }

         // ==========================================================
         // ===== DESCRIPTION VIDEO PIPELINE (Chunked) ===============
         // ==========================================================
         (function() {
             let descChunks = [];
             const baseUrl = `/audiobooks/${audioBookId}/desc-video`;

             // DOM refs
             const chunkBtn = document.getElementById('descChunkBtn');
             const chunkStatus = document.getElementById('descChunkStatus');
             const chunksList = document.getElementById('descChunksList');
             const chunksItems = document.getElementById('descChunksItems');
             const chunksCount = document.getElementById('descChunksCount');
             const genImagesBtn = document.getElementById('descGenImagesBtn');
             const genImagesStatus = document.getElementById('descGenImagesStatus');
             const imageProviderSelect = document.getElementById('descImageProvider');
             const genTtsBtn = document.getElementById('descGenTtsBtn');
             const genTtsStatus = document.getElementById('descGenTtsStatus');
             const genSrtBtn = document.getElementById('descGenSrtBtn');
             const genSrtStatus = document.getElementById('descGenSrtStatus');
             const composeBtn = document.getElementById('descComposeBtn');
             const composeStatus = document.getElementById('descComposeStatus');
             const progressWrap = document.getElementById('descPipelineProgress');
             const progressText = document.getElementById('descPipelineProgressText');
             const progressPct = document.getElementById('descPipelineProgressPercent');
             const progressBar = document.getElementById('descPipelineProgressBar');
             const resultContainer = document.getElementById('descVideoResultContainer');
             const videoPlayer = document.getElementById('descVideoPlayer2');
             const downloadBtn = document.getElementById('descVideoDownloadBtn');
             const durationEl = document.getElementById('descVideoDuration2');

             if (!chunkBtn) return;

             // Helpers
             function setProgress(text, pct) {
                 progressWrap.classList.remove('hidden');
                 progressText.textContent = text;
                 progressPct.textContent = pct + '%';
                 progressBar.style.width = pct + '%';
             }

             function hideProgress() {
                 progressWrap.classList.add('hidden');
             }

             function getTtsSettings() {
                 return {
                     provider: document.getElementById('ttsProviderSelect')?.value || 'openai',
                     voice_name: document.getElementById('voiceNameSelect')?.value || '',
                     voice_gender: document.querySelector('input[name="voiceGender"]:checked')?.value || 'female',
                     style_instruction: document.getElementById('ttsStyleInstruction')?.value || '',
                     tts_speed: parseFloat(document.getElementById('ttsSpeedSelect')?.value) || 1.0,
                     pause_between_chunks: Number.isFinite(parseFloat(document.getElementById('pauseBetweenChunksSelect')
                         ?.value)) ? parseFloat(document.getElementById('pauseBetweenChunksSelect')?.value) : 0.0
                 };
             }

             function renderChunks() {
                 if (!descChunks.length) {
                     chunksList.classList.add('hidden');
                     return;
                 }
                 chunksList.classList.remove('hidden');
                 chunksCount.textContent = `${descChunks.length} chunks`;
                 chunksItems.innerHTML = '';

                 descChunks.forEach((chunk, idx) => {
                     const hasImg = !!chunk.image_path;
                     const hasAudio = !!chunk.audio_path;
                     const hasSrt = !!chunk.srt_path;
                     const duration = chunk.audio_duration ? `${parseFloat(chunk.audio_duration).toFixed(1)}s` :
                         '';

                     const div = document.createElement('div');
                     div.className = 'p-3 bg-gray-50 border border-gray-200 rounded-lg';
                     div.innerHTML = `
                        <div class="flex items-start gap-2">
                            <span class="bg-gray-600 text-white text-xs font-bold w-5 h-5 rounded-full flex items-center justify-center mt-1 flex-shrink-0">${idx}</span>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs text-gray-700 mb-1 line-clamp-3">${escapeHtml(chunk.text)}</div>
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="text-xs ${hasImg ? 'text-green-600' : 'text-gray-400'}">${hasImg ? '✅' : '⬜'} Ảnh</span>
                                    <span class="text-xs ${hasAudio ? 'text-green-600' : 'text-gray-400'}">${hasAudio ? '✅' : '⬜'} Audio ${duration}</span>
                                    <span class="text-xs ${hasSrt ? 'text-green-600' : 'text-gray-400'}">${hasSrt ? '✅' : '⬜'} SRT</span>
                                </div>
                                <div class="mb-1">
                                    <label class="text-xs text-gray-500">Prompt ảnh:</label>
                                    <textarea class="w-full text-xs border border-gray-300 rounded p-1 descChunkPrompt" data-index="${idx}" rows="2">${escapeHtml(chunk.image_prompt || '')}</textarea>
                                </div>
                                <div class="flex gap-1">
                                    <button type="button" class="descOneImageBtn bg-blue-100 hover:bg-blue-200 text-blue-700 text-xs px-2 py-1 rounded" data-index="${idx}">🎨 Ảnh</button>
                                    <button type="button" class="descOneTtsBtn bg-purple-100 hover:bg-purple-200 text-purple-700 text-xs px-2 py-1 rounded" data-index="${idx}">🎙️ TTS</button>
                                    <button type="button" class="descOneSrtBtn bg-amber-100 hover:bg-amber-200 text-amber-700 text-xs px-2 py-1 rounded" data-index="${idx}">📝 SRT</button>
                                </div>
                                ${hasImg ? `<img src="/storage/books/${audioBookId}/description_video/images/chunk_${idx}.png?t=${Date.now()}" class="mt-2 rounded max-h-32 border" alt="chunk ${idx}">` : ''}
                                ${hasAudio ? `<audio controls class="mt-1 w-full h-8" src="/storage/books/${audioBookId}/description_video/audio/chunk_${idx}.mp3?t=${Date.now()}"></audio>` : ''}
                            </div>
                        </div>
                    `;
                     chunksItems.appendChild(div);
                 });

                 // Show/hide step buttons based on state
                 genImagesBtn.classList.remove('hidden');
                 genTtsBtn.classList.remove('hidden');
                 genSrtBtn.classList.remove('hidden');
                 composeBtn.classList.remove('hidden');

                 // Attach per-chunk handlers
                 document.querySelectorAll('.descOneImageBtn').forEach(btn => {
                     btn.addEventListener('click', () => generateOneImage(parseInt(btn.dataset.index)));
                 });
                 document.querySelectorAll('.descOneTtsBtn').forEach(btn => {
                     btn.addEventListener('click', () => generateOneTts(parseInt(btn.dataset.index)));
                 });
                 document.querySelectorAll('.descOneSrtBtn').forEach(btn => {
                     btn.addEventListener('click', () => generateOneSrt(parseInt(btn.dataset.index)));
                 });
             }

             function escapeHtml(text) {
                 const d = document.createElement('div');
                 d.textContent = text || '';
                 return d.innerHTML;
             }

             function getEditedPrompt(idx) {
                 const ta = document.querySelector(`.descChunkPrompt[data-index="${idx}"]`);
                 return ta ? ta.value : (descChunks[idx]?.image_prompt || '');
             }

             // ---- STEP 1: Chunk Description ----
             chunkBtn.addEventListener('click', async () => {
                 chunkBtn.disabled = true;
                 chunkStatus.innerHTML =
                     '<span class="text-blue-600">⏳ AI đang phân tích và chia đoạn...</span>';
                 setProgress('AI Phân tích & Chia đoạn...', 10);

                 try {
                     const resp = await fetch(`${baseUrl}/chunk`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                 .content,
                             'Accept': 'application/json'
                         }
                     });
                     const result = await safeJson(resp);
                     if (result.error) throw new Error(result.error);

                     descChunks = result.chunks || [];
                     chunkStatus.innerHTML =
                         `<span class="text-green-600">✅ Đã chia thành ${descChunks.length} chunks</span>`;
                     renderChunks();
                 } catch (e) {
                     chunkStatus.innerHTML = `<span class="text-red-600">❌ ${e.message}</span>`;
                 } finally {
                     chunkBtn.disabled = false;
                     hideProgress();
                 }
             });

             // ---- STEP 2: Generate one image ----
             async function generateOneImage(idx) {
                 const prompt = getEditedPrompt(idx);
                 if (!prompt) {
                     alert('Chưa có prompt ảnh!');
                     return;
                 }

                 const btn = document.querySelector(`.descOneImageBtn[data-index="${idx}"]`);
                 btn.disabled = true;
                 btn.textContent = '⏳...';

                 try {
                     const resp = await fetch(`${baseUrl}/generate-image`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                             'Accept': 'application/json'
                         },
                         body: JSON.stringify({
                             chunk_index: idx,
                             prompt: prompt,
                             image_provider: imageProviderSelect?.value || 'gemini'
                         })
                     });
                     const result = await safeJson(resp);
                     if (result.error) throw new Error(result.error);

                     descChunks[idx].image_path = result.image_path;
                     renderChunks();
                 } catch (e) {
                     alert(`Lỗi tạo ảnh chunk ${idx}: ${e.message}`);
                 } finally {
                     btn.disabled = false;
                     btn.textContent = '🎨 Ảnh';
                 }
             }

             // ---- Generate ALL images ----
             genImagesBtn.addEventListener('click', async () => {
                 genImagesBtn.disabled = true;
                 let done = 0;
                 const total = descChunks.length;

                 for (let i = 0; i < total; i++) {
                     if (descChunks[i].image_path) {
                         done++;
                         continue;
                     }
                     setProgress(`Tạo ảnh chunk ${i}/${total}...`, Math.round((done / total) * 100));
                     genImagesStatus.innerHTML =
                         `<span class="text-blue-600">⏳ Tạo ảnh ${i + 1}/${total}...</span>`;

                     try {
                         const prompt = getEditedPrompt(i);
                         const resp = await fetch(`${baseUrl}/generate-image`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                     .content,
                                 'Accept': 'application/json'
                             },
                             body: JSON.stringify({
                                 chunk_index: i,
                                 prompt: prompt,
                                 image_provider: imageProviderSelect?.value || 'gemini'
                             })
                         });
                         const result = await safeJson(resp);
                         if (result.error) throw new Error(result.error);
                         descChunks[i].image_path = result.image_path;
                     } catch (e) {
                         genImagesStatus.innerHTML =
                             `<span class="text-red-600">❌ Lỗi chunk ${i}: ${e.message}</span>`;
                     }
                     done++;
                 }

                 genImagesStatus.innerHTML =
                     `<span class="text-green-600">✅ Hoàn tất tạo ${done} ảnh</span>`;
                 renderChunks();
                 genImagesBtn.disabled = false;
                 hideProgress();
             });

             // ---- STEP 3: Generate one TTS ----
             async function generateOneTts(idx) {
                 const btn = document.querySelector(`.descOneTtsBtn[data-index="${idx}"]`);
                 btn.disabled = true;
                 btn.textContent = '⏳...';

                 const tts = getTtsSettings();
                 try {
                     const resp = await fetch(`${baseUrl}/generate-tts`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                             'Accept': 'application/json'
                         },
                         body: JSON.stringify({
                             chunk_index: idx,
                             text: descChunks[idx].text,
                             provider: tts.provider,
                             voice_name: tts.voice_name,
                             voice_gender: tts.voice_gender,
                             style_instruction: tts.style_instruction
                         })
                     });
                     const result = await safeJson(resp);
                     if (result.error) throw new Error(result.error);

                     descChunks[idx].audio_path = result.audio_path;
                     descChunks[idx].audio_duration = result.duration;
                     renderChunks();
                 } catch (e) {
                     alert(`Lỗi TTS chunk ${idx}: ${e.message}`);
                 } finally {
                     btn.disabled = false;
                     btn.textContent = '🎙️ TTS';
                 }
             }

             // ---- Generate ALL TTS ----
             genTtsBtn.addEventListener('click', async () => {
                 genTtsBtn.disabled = true;
                 let done = 0;
                 const total = descChunks.length;
                 const tts = getTtsSettings();

                 for (let i = 0; i < total; i++) {
                     if (descChunks[i].audio_path) {
                         done++;
                         continue;
                     }
                     setProgress(`Tạo TTS chunk ${i}/${total}...`, Math.round((done / total) * 100));
                     genTtsStatus.innerHTML =
                         `<span class="text-blue-600">⏳ Tạo audio ${i + 1}/${total}...</span>`;

                     try {
                         const resp = await fetch(`${baseUrl}/generate-tts`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                     .content,
                                 'Accept': 'application/json'
                             },
                             body: JSON.stringify({
                                 chunk_index: i,
                                 text: descChunks[i].text,
                                 provider: tts.provider,
                                 voice_name: tts.voice_name,
                                 voice_gender: tts.voice_gender,
                                 style_instruction: tts.style_instruction
                             })
                         });
                         const result = await safeJson(resp);
                         if (result.error) throw new Error(result.error);
                         descChunks[i].audio_path = result.audio_path;
                         descChunks[i].audio_duration = result.duration;
                     } catch (e) {
                         genTtsStatus.innerHTML =
                             `<span class="text-red-600">❌ Lỗi chunk ${i}: ${e.message}</span>`;
                     }
                     done++;
                 }

                 genTtsStatus.innerHTML = `<span class="text-green-600">✅ Hoàn tất tạo ${done} audio</span>`;
                 renderChunks();
                 genTtsBtn.disabled = false;
                 hideProgress();
             });

             // ---- STEP 4: Generate one SRT ----
             async function generateOneSrt(idx) {
                 if (!descChunks[idx].audio_path) {
                     alert('Chưa có audio cho chunk này! Cần tạo TTS trước.');
                     return;
                 }

                 const btn = document.querySelector(`.descOneSrtBtn[data-index="${idx}"]`);
                 btn.disabled = true;
                 btn.textContent = '⏳...';

                 try {
                     const resp = await fetch(`${baseUrl}/generate-srt`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                             'Accept': 'application/json'
                         },
                         body: JSON.stringify({
                             chunk_index: idx
                         })
                     });
                     const result = await safeJson(resp);
                     if (result.error) throw new Error(result.error);

                     descChunks[idx].srt_path = result.srt_path;
                     renderChunks();
                 } catch (e) {
                     alert(`Lỗi SRT chunk ${idx}: ${e.message}`);
                 } finally {
                     btn.disabled = false;
                     btn.textContent = '📝 SRT';
                 }
             }

             // ---- Generate ALL SRT ----
             genSrtBtn.addEventListener('click', async () => {
                 genSrtBtn.disabled = true;
                 let done = 0;
                 const total = descChunks.length;

                 for (let i = 0; i < total; i++) {
                     if (descChunks[i].srt_path) {
                         done++;
                         continue;
                     }
                     if (!descChunks[i].audio_path) {
                         genSrtStatus.innerHTML =
                             `<span class="text-yellow-600">⚠️ Chunk ${i} chưa có audio, bỏ qua</span>`;
                         done++;
                         continue;
                     }
                     setProgress(`Tạo SRT chunk ${i}/${total}...`, Math.round((done / total) * 100));
                     genSrtStatus.innerHTML =
                         `<span class="text-blue-600">⏳ Tạo SRT ${i + 1}/${total}...</span>`;

                     try {
                         const resp = await fetch(`${baseUrl}/generate-srt`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                     .content,
                                 'Accept': 'application/json'
                             },
                             body: JSON.stringify({
                                 chunk_index: i
                             })
                         });
                         const result = await safeJson(resp);
                         if (result.error) throw new Error(result.error);
                         descChunks[i].srt_path = result.srt_path;
                     } catch (e) {
                         genSrtStatus.innerHTML =
                             `<span class="text-red-600">❌ Lỗi chunk ${i}: ${e.message}</span>`;
                     }
                     done++;
                 }

                 genSrtStatus.innerHTML = `<span class="text-green-600">✅ Hoàn tất tạo ${done} SRT</span>`;
                 renderChunks();
                 genSrtBtn.disabled = false;
                 hideProgress();
             });

             // ---- STEP 5: Compose Final Video ----
             composeBtn.addEventListener('click', async () => {
                 composeBtn.disabled = true;
                 composeStatus.innerHTML =
                     '<span class="text-blue-600">⏳ Đang ghép video (Ken Burns + transitions + music + subtitles)... Có thể mất vài phút.</span>';
                 setProgress('Ghép video hoàn chỉnh...', 50);

                 try {
                     const resp = await fetch(`${baseUrl}/compose`, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                 .content,
                             'Accept': 'application/json'
                         }
                     });
                     const result = await safeJson(resp);
                     if (result.error) throw new Error(result.error);

                     composeStatus.innerHTML = '<span class="text-green-600">✅ Video hoàn chỉnh!</span>';
                     setProgress('Hoàn tất!', 100);

                     // Show video player
                     resultContainer.classList.remove('hidden');
                     videoPlayer.src = result.video_url + '?t=' + Date.now();
                     downloadBtn.href = result.video_url;
                     if (result.duration) {
                         durationEl.textContent = `(${parseFloat(result.duration).toFixed(1)}s)`;
                     }
                 } catch (e) {
                     composeStatus.innerHTML = `<span class="text-red-600">❌ ${e.message}</span>`;
                 } finally {
                     composeBtn.disabled = false;
                     setTimeout(hideProgress, 3000);
                 }
             });

             // ---- Load existing chunks on page load ----
             (async function loadExistingChunks() {
                 try {
                     const resp = await fetch(`${baseUrl}/chunks`, {
                         headers: {
                             'Accept': 'application/json'
                         }
                     });
                     if (!resp.ok) return;
                     const result = await safeJson(resp);
                     if (result.chunks && result.chunks.length) {
                         descChunks = result.chunks;
                         renderChunks();
                     }
                 } catch (e) {
                     /* ignore */
                 }
             })();
         })();

         // ========== AUTO PUBLISH TAB ==========
         (function() {
             let publishInitialized = false;
             let publishData = null;
             let selectedThumbnailUrl = '';
             let playlistChildMeta = [];
             let savedPlaylistId = null;
             let savedPlaylistTitle = null;
             let existingPlaylists = [];
             let publishVideoMetaMap = {};
             let publishTimer = null;
             let publishRunning = false;

             const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
             const publishBaseUrl = `/audiobooks/${audioBookId}/publish`;

             function normalizePublishSourceLabel(label) {
                 return String(label || '').replace(/\s+\((AI Shorts|Clipping)\)\s*$/i, '').trim();
             }

             function ensureShortsHashtag(title) {
                 const normalized = String(title || '').trim();
                 if (!normalized) return '#Shorts';
                 if (/#shorts\b/i.test(normalized)) return normalized;
                 return `${normalized} #Shorts`;
             }

             function syncPublishMetaFromSelection(mode, selectedCheckboxes) {
                 if (!Array.isArray(selectedCheckboxes) || selectedCheckboxes.length !== 1) return;
                 if (mode !== 'single' && mode !== 'shorts') return;

                 const selected = selectedCheckboxes[0];
                 const titleInput = document.getElementById('publishTitle');
                 const descriptionInput = document.getElementById('publishDescription');
                 if (!titleInput || !descriptionInput) return;

                 const sourceMeta = publishVideoMetaMap[selected.value] || {};
                 let sourceTitle = String(sourceMeta.title || '').trim();
                 const sourceDescription = String(sourceMeta.description || '').trim();

                 if (!sourceTitle && mode === 'shorts') {
                     sourceTitle = normalizePublishSourceLabel(selected.dataset.label || '');
                 }

                 if (sourceTitle) {
                     titleInput.value = mode === 'shorts'
                         ? sourceTitle.replace(/\s*#shorts\b/ig, '').trim()
                         : sourceTitle;
                 }

                 if (sourceDescription) {
                     descriptionInput.value = sourceDescription;
                 }
             }

             window.initAutoPublishTab = function() {
                 if (publishInitialized) return;
                 publishInitialized = true;
                 checkYoutubeConnection();
                 loadPublishData();
                 setupPublishModeToggle();
                 setupPlaylistTypeToggle();
                 setupAIButtons();
                 setupPublishButton();
                 setupSaveMetaButton();
             };

             // ---- Check YouTube Connection ----
             async function checkYoutubeConnection() {
                 const statusEl = document.getElementById('publishYtStatus');
                 try {
                     const channelId = {{ $audioBook->youtube_channel_id ?? 'null' }};
                     if (!channelId) {
                         statusEl.innerHTML =
                             '<div class="flex items-center gap-2 text-yellow-700"><span>⚠️</span><span>Audiobook chưa được gán kênh YouTube. Vui lòng chọn kênh YouTube trong phần thiết lập.</span></div>';
                         statusEl.className = 'mb-6 p-4 rounded-lg border border-yellow-300 bg-yellow-50';
                         return;
                     }

                     const resp = await fetch(`/youtube-channels/${channelId}/oauth/status`, {
                         headers: {
                             'Accept': 'application/json'
                         }
                     });
                     const result = await safeJson(resp);

                     if (result.connected) {
                         statusEl.innerHTML =
                             `<div class="flex items-center gap-2 text-green-700"><span>✅</span><span>Đã kết nối YouTube (${result.email || 'N/A'})</span></div>`;
                         statusEl.className = 'mb-6 p-4 rounded-lg border border-green-300 bg-green-50';
                         document.getElementById('publishFormWrapper').classList.remove('hidden');
                     } else {
                         statusEl.innerHTML =
                             '<div class="flex items-center gap-2 text-red-700"><span>❌</span><span>Chưa kết nối YouTube. Vui lòng kết nối OAuth trong trang quản lý kênh.</span></div>';
                         statusEl.className = 'mb-6 p-4 rounded-lg border border-red-300 bg-red-50';
                     }
                 } catch (e) {
                     statusEl.innerHTML = `<div class="text-red-600">❌ Lỗi kiểm tra kết nối: ${e.message}</div>`;
                     statusEl.className = 'mb-6 p-4 rounded-lg border border-red-300 bg-red-50';
                 }
             }

             // ---- Load Publish Data (videos, thumbnails) ----
             async function loadPublishData() {
                 try {
                     const resp = await fetch(`${publishBaseUrl}/data`, {
                         headers: {
                             'Accept': 'application/json'
                         }
                     });
                     publishData = await safeJson(resp);
                     renderVideoSources();
                     renderThumbnailGallery();

                     // Pre-populate saved meta from DB
                     if (publishData.saved_meta) {
                         const meta = publishData.saved_meta;
                         if (meta.youtube_video_title) {
                             document.getElementById('publishTitle').value = meta.youtube_video_title;
                         }
                         if (meta.youtube_video_description) {
                             document.getElementById('publishDescription').value = meta.youtube_video_description;
                         }
                         if (meta.youtube_video_tags) {
                             document.getElementById('publishTags').value = meta.youtube_video_tags;
                         }
                         if (meta.youtube_playlist_title) {
                             document.getElementById('playlistName').value = meta.youtube_playlist_title;
                         }
                         // If there's an existing playlist saved, pre-select "existing" radio
                         if (meta.youtube_playlist_id) {
                             savedPlaylistId = meta.youtube_playlist_id;
                             savedPlaylistTitle = meta.youtube_playlist_title;
                         }
                     }

                     // Load saved chapter meta into playlistChildMeta
                     if (publishData.videos) {
                         const chaptersWithMeta = publishData.videos.filter(v => v.youtube_video_title);
                         if (chaptersWithMeta.length > 0) {
                             playlistChildMeta = chaptersWithMeta.map(v => ({
                                 id: v.id,
                                 source_label: v.label,
                                 title: v.youtube_video_title,
                                 description: v.youtube_video_description || '',
                                 uploaded: !!v.youtube_video_id,
                             }));
                         }
                     }

                     // Load publishing history
                     loadPublishHistory();
                 } catch (e) {
                     document.getElementById('publishVideoSources').innerHTML =
                         `<p class="text-sm text-red-500">Lỗi tải dữ liệu: ${e.message}</p>`;
                 }
             }

             // ---- Render Video Sources ----
             function renderVideoSources() {
                 const container = document.getElementById('publishVideoSources');
                 if (!publishData || !publishData.videos || publishData.videos.length === 0) {
                     container.innerHTML =
                         '<p class="text-sm text-gray-400">Không có video nào. Hãy tạo video cho các chapter trước.</p>';
                     return;
                 }

                 let html = '';

                const mode = document.querySelector('input[name="publishMode"]:checked')?.value || 'single';

                // Show warning if some chapters don't have videos (not for Shorts mode)
                if (mode !== 'shorts' && publishData.chapters_without_video && publishData.chapters_without_video.length > 0) {
                     const missing = publishData.chapters_without_video;
                     html += `<div class="mb-2 p-2 bg-yellow-50 border border-yellow-300 rounded-lg text-xs text-yellow-700">
                        ⚠️ ${missing.length}/${publishData.total_chapters} chương chưa có video: Chương ${missing.join(', ')}.
                        <br>Vui lòng chọn các chương này và nhấn "🎬 Tạo Video" ở tab Chapters trước khi phát hành.
                    </div>`;
                 }

                // Filter/group videos by type depending on mode
                const allVideos = publishData.videos || [];
                const visibleVideos = mode === 'shorts'
                    ? allVideos.filter(v => v.type === 'short')
                    : allVideos.filter(v => v.type !== 'short');

                const descVideos = visibleVideos.filter(v => v.type === 'description');
                const chapterVideos = visibleVideos.filter(v => v.type === 'chapter');
                const segmentVideos = visibleVideos.filter(v => v.type === 'segment');
                const shortAiVideos = visibleVideos.filter(v => v.type === 'short' && v.origin === 'shorts');
                const shortClippingVideos = visibleVideos.filter(v => v.type === 'short' && v.origin === 'clipping');
                publishVideoMetaMap = {};

                 function renderVideoItem(v) {
                     publishVideoMetaMap[v.id] = {
                         title: String(v.youtube_video_title || ''),
                         description: String(v.youtube_video_description || ''),
                     };

                     const isUploaded = !!v.youtube_video_id;
                     const uploadBadge = isUploaded ?
                         `<span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 font-medium">✅ Đã upload</span>` :
                         `<span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">Chưa upload</span>`;
                     const uploadDate = v.youtube_uploaded_at ?
                         `<span class="text-xs text-gray-400 ml-1">(${new Date(v.youtube_uploaded_at).toLocaleDateString('vi-VN')})</span>` :
                         '';
                     const durStr = v.duration ? (v.duration >= 60 ? Math.floor(v.duration / 60) + 'p' + Math.round(v
                         .duration % 60) + 's' : Math.round(v.duration) + 's') : '';
                    const typeColors = {
                         description: 'bg-blue-100 text-blue-700',
                         chapter: 'bg-green-100 text-green-700',
                        segment: 'bg-teal-100 text-teal-700',
                        short: 'bg-purple-100 text-purple-700'
                     };
                    const typeLabels = {
                         description: 'Giới thiệu',
                         chapter: 'Chapter',
                        segment: 'Phần',
                        short: 'Shorts'
                     };
                     return `
                    <label class="flex items-center gap-3 p-2 rounded hover:bg-gray-50 cursor-pointer transition ${isUploaded ? 'bg-green-50/50' : ''}">
                        <input type="checkbox" class="publish-video-checkbox rounded text-blue-600"
                               value="${v.id}" data-type="${v.type}" data-path="${v.path}" data-label="${v.label}" data-duration="${v.duration || 0}"
                               data-uploaded="${isUploaded ? '1' : '0'}">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-gray-700">${v.label}</span>
                            <span class="text-xs text-gray-400 ml-2">${durStr}</span>
                            ${uploadDate}
                        </div>
                        ${uploadBadge}
                        <span class="text-xs px-2 py-0.5 rounded-full ${typeColors[v.type] || 'bg-gray-100 text-gray-700'}">${typeLabels[v.type] || v.type}</span>
                    </label>`;
                 }

                if (mode !== 'shorts' && descVideos.length > 0) {
                     html +=
                         `<div class="text-xs font-semibold text-blue-700 px-2 pt-1 pb-0.5 border-b border-blue-100 mb-1">📖 Video giới thiệu</div>`;
                     html += descVideos.map(renderVideoItem).join('');
                 }
                if (mode !== 'shorts' && chapterVideos.length > 0) {
                     html +=
                         `<div class="text-xs font-semibold text-green-700 px-2 pt-2 pb-0.5 border-b border-green-100 mb-1">📚 Video theo chương</div>`;
                     html += chapterVideos.map(renderVideoItem).join('');
                 }
                if (mode !== 'shorts' && segmentVideos.length > 0) {
                     html +=
                         `<div class="text-xs font-semibold text-teal-700 px-2 pt-2 pb-0.5 border-b border-teal-100 mb-1">🎬 Video theo phần (Segments)</div>`;
                     html += segmentVideos.map(renderVideoItem).join('');
                 }

                // Shorts groups
                if (mode === 'shorts' && (shortAiVideos.length > 0 || shortClippingVideos.length > 0)) {
                    if (shortAiVideos.length > 0) {
                        html += `<div class="text-xs font-semibold text-purple-700 px-2 pt-1 pb-0.5 border-b border-purple-100 mb-1">📱 AI Shorts đã tạo</div>`;
                        html += shortAiVideos.map(renderVideoItem).join('');
                    }
                    if (shortClippingVideos.length > 0) {
                        html += `<div class="text-xs font-semibold text-fuchsia-700 px-2 pt-2 pb-0.5 border-b border-fuchsia-100 mb-1">✂️ Clipping đã ghép</div>`;
                        html += shortClippingVideos.map(renderVideoItem).join('');
                    }
                }

                 container.innerHTML = html;

                 // Update selection count
                 container.querySelectorAll('.publish-video-checkbox').forEach(cb => {
                     cb.addEventListener('change', updateSourceSelection);
                 });
             }

             // ---- Toggle Select All Video Sources ----
             window.toggleSelectAllVideoSources = function() {
                 const checkboxes = document.querySelectorAll('.publish-video-checkbox');
                 const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                 const btn = document.getElementById('selectAllVideoSourcesBtn');

                 checkboxes.forEach(cb => cb.checked = !allChecked);
                 btn.textContent = allChecked ? '☑️ Chọn tất cả' : '☐ Bỏ chọn tất cả';
                 updateSourceSelection();
             };

             function updateSourceSelection() {
                 const mode = document.querySelector('input[name="publishMode"]:checked').value;
                 let checked = [...document.querySelectorAll('.publish-video-checkbox:checked')];
                 const hint = document.getElementById('publishSourceHint');

                 if (mode === 'playlist') {
                     const newCount = checked.filter(cb => cb.dataset.uploaded !== '1').length;
                     const uploadedCount = checked.length - newCount;
                     hint.textContent = `Đã chọn ${checked.length} video (${newCount} mới, ${uploadedCount} đã upload)`;
                 } else if (mode === 'single') {
                     if (checked.length > 1) {
                         // Only keep the last one for single mode
                         checked.forEach((cb, i) => {
                             if (i < checked.length - 1) cb.checked = false;
                         });
                         checked = checked.slice(-1);
                         hint.textContent = 'Chỉ chọn 1 video (chế độ Video đơn lẻ)';
                     } else {
                         hint.textContent = `Đã chọn ${checked.length} video`;
                     }
                 } else if (mode === 'shorts') {
                     hint.textContent = `Đã chọn ${checked.length} Shorts`;
                 }

                 syncPublishMetaFromSelection(mode, checked);
             }

             // ---- Render Thumbnail Gallery ----
             function renderThumbnailGallery() {
                 const container = document.getElementById('publishThumbnailGallery');
                 if (!publishData || !publishData.thumbnails || publishData.thumbnails.length === 0) {
                     container.innerHTML =
                         '<p class="text-sm text-gray-400 col-span-2">Không có thumbnail. Vào tab "YouTube Media (AI)" để tạo.</p>';
                     return;
                 }

                 container.innerHTML = publishData.thumbnails.map((t, i) => `
                    <div class="relative cursor-pointer rounded-lg overflow-hidden border-2 border-transparent hover:border-blue-400 transition publish-thumb-item"
                         data-url="${t.url}" data-path="${t.path}">
                        <img src="${t.url}" alt="Thumbnail ${i+1}" class="w-full h-auto object-cover aspect-video">
                        <div class="absolute inset-0 bg-blue-600 bg-opacity-0 hover:bg-opacity-10 transition"></div>
                    </div>
                `).join('');

                 container.querySelectorAll('.publish-thumb-item').forEach(item => {
                     item.addEventListener('click', function() {
                         // Deselect all
                         container.querySelectorAll('.publish-thumb-item').forEach(el => {
                             el.classList.remove('border-blue-500', 'ring-2', 'ring-blue-300');
                             el.classList.add('border-transparent');
                         });
                         // Select this one
                         this.classList.remove('border-transparent');
                         this.classList.add('border-blue-500', 'ring-2', 'ring-blue-300');

                         selectedThumbnailUrl = this.dataset.path;
                         document.getElementById('publishSelectedThumbnail').value = this.dataset.path;

                         // Show preview
                         const preview = document.getElementById('publishThumbnailPreview');
                         document.getElementById('publishThumbnailPreviewImg').src = this.dataset.url;
                         preview.classList.remove('hidden');
                     });
                 });
             }

             // ---- Publish Mode Toggle ----
             function setupPublishModeToggle() {
                 document.querySelectorAll('.publish-mode-radio').forEach(radio => {
                     radio.addEventListener('change', function() {
                         const mode = this.value;
                         const playlistSection = document.getElementById('playlistSection');
                         const hint = document.getElementById('publishSourceHint');

                         // Update label styles
                         document.querySelectorAll('.publish-mode-label').forEach(l => {
                             l.classList.remove('bg-blue-50', 'border-blue-400');
                         });
                         this.closest('.publish-mode-label').classList.add('bg-blue-50',
                             'border-blue-400');

                         if (mode === 'playlist') {
                             playlistSection.classList.remove('hidden');
                             hint.textContent = 'Chọn nhiều video để tạo playlist';
                         } else {
                             playlistSection.classList.add('hidden');
                             hint.textContent = 'Chọn 1 video để upload (chế độ Video đơn lẻ / Shorts)';
                         }

                         // Re-render sources to apply filtering by mode
                         renderVideoSources();
                     });
                 });

                 // Set initial active style
                 const initialLabel = document.querySelector('.publish-mode-radio:checked')?.closest(
                     '.publish-mode-label');
                 if (initialLabel) initialLabel.classList.add('bg-blue-50', 'border-blue-400');
             }

             // ---- AI Buttons ----
             function setupAIButtons() {
                 // AI Generate Title
                 document.getElementById('aiGenerateTitleBtn').addEventListener('click', async function() {
                     const btn = this;
                     const origText = btn.textContent;
                     btn.disabled = true;
                     btn.textContent = '⏳ Đang viết...';

                     try {
                         const resp = await fetch(`${publishBaseUrl}/generate-meta`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': csrfToken,
                                 'Accept': 'application/json'
                             },
                             body: JSON.stringify({
                                 type: 'title'
                             })
                         });
                         const result = await safeJson(resp);
                         if (result.title) {
                             document.getElementById('publishTitle').value = result.title;
                         }
                         if (result.tags) {
                             document.getElementById('publishTags').value = result.tags;
                         }
                     } catch (e) {
                         alert('Lỗi: ' + e.message);
                     } finally {
                         btn.disabled = false;
                         btn.textContent = origText;
                     }
                 });

                 // AI Generate Description
                 document.getElementById('aiGenerateDescBtn').addEventListener('click', async function() {
                     const btn = this;
                     const origText = btn.textContent;
                     btn.disabled = true;
                     btn.textContent = '⏳ Đang viết...';

                     try {
                         const selectedItems = [...document.querySelectorAll(
                                 '.publish-video-checkbox:checked')]
                             .map(cb => ({
                                 id: cb.value,
                                 label: cb.dataset.label,
                                 duration: parseFloat(cb.dataset.duration || '0') || 0,
                                 type: cb.dataset.type
                             }));

                         const resp = await fetch(`${publishBaseUrl}/generate-meta`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': csrfToken,
                                 'Accept': 'application/json'
                             },
                             body: JSON.stringify({
                                 type: 'description',
                                 items: selectedItems
                             })
                         });
                         const result = await safeJson(resp);
                         if (result.description) {
                             document.getElementById('publishDescription').value = result.description;
                         }
                     } catch (e) {
                         alert('Lỗi: ' + e.message);
                     } finally {
                         btn.disabled = false;
                         btn.textContent = origText;
                     }
                 });

                 // Generate Playlist Meta (child versions)
                 document.getElementById('generatePlaylistMetaBtn').addEventListener('click', async function() {
                     const btn = this;
                     const origText = btn.textContent;
                     btn.disabled = true;
                     btn.textContent = '⏳ AI đang xử lý...';

                     const playlistType = document.querySelector('input[name="playlistType"]:checked')
                         ?.value || 'new';
                     let checkedVideos = [...document.querySelectorAll('.publish-video-checkbox:checked')];

                     // When using existing playlist, only generate for non-uploaded videos
                     if (playlistType === 'existing') {
                         checkedVideos = checkedVideos.filter(cb => cb.dataset.uploaded !== '1');
                         if (checkedVideos.length === 0) {
                             alert('Tất cả video đã chọn đều đã được upload. Không cần tạo phiên bản con.');
                             btn.disabled = false;
                             btn.textContent = origText;
                             return;
                         }
                     }

                     if (checkedVideos.length < 2) {
                         alert('Vui lòng chọn ít nhất 2 video chưa upload để tạo phiên bản con.');
                         btn.disabled = false;
                         btn.textContent = origText;
                         return;
                     }

                     const chapters = checkedVideos.map(cb => ({
                         id: cb.value,
                         label: cb.dataset.label,
                         type: cb.dataset.type,
                         duration: parseFloat(cb.dataset.duration || '0') || 0
                     }));

                     try {
                         const resp = await fetch(`${publishBaseUrl}/generate-playlist-meta`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': csrfToken,
                                 'Accept': 'application/json'
                             },
                             body: JSON.stringify({
                                 title: document.getElementById('publishTitle').value,
                                 description: document.getElementById('publishDescription')
                                     .value,
                                 chapters: chapters
                             })
                         });
                         const result = await safeJson(resp);

                         if (result.items && result.items.length) {
                             // Merge with existing uploaded items
                             const uploadedItems = playlistChildMeta.filter(m => m.uploaded);
                             const newItems = result.items.map(item => ({
                                 ...item,
                                 uploaded: false,
                             }));
                             playlistChildMeta = [...uploadedItems, ...newItems];
                             renderPlaylistMeta();
                         }
                     } catch (e) {
                         alert('Lỗi: ' + e.message);
                     } finally {
                         btn.disabled = false;
                         btn.textContent = origText;
                     }
                 });
             }

             // ---- Playlist Type Toggle (New vs Existing) ----
             function setupPlaylistTypeToggle() {
                 document.querySelectorAll('.playlist-type-radio').forEach(radio => {
                     radio.addEventListener('change', function() {
                         const type = this.value;
                         const newSection = document.getElementById('newPlaylistSection');
                         const existSection = document.getElementById('existingPlaylistSection');

                         document.querySelectorAll('.playlist-type-label').forEach(l => {
                             l.classList.remove('bg-indigo-50', 'border-indigo-400');
                         });
                         this.closest('.playlist-type-label').classList.add('bg-indigo-50',
                             'border-indigo-400');

                         if (type === 'existing') {
                             newSection.classList.add('hidden');
                             existSection.classList.remove('hidden');
                             loadExistingPlaylists();
                             // Update hint
                             document.getElementById('playlistMetaHint').textContent =
                                 'Chỉ tạo tiêu đề/mô tả cho video chưa upload. Video đã upload sẽ giữ nguyên.';
                         } else {
                             newSection.classList.remove('hidden');
                             existSection.classList.add('hidden');
                             document.getElementById('playlistMetaHint').textContent =
                                 'AI sẽ chuyển tiêu đề và mô tả chung thành phiên bản riêng cho từng chapter video trong playlist.';
                         }
                     });
                 });

                 // Set initial active style
                 const initialLabel = document.querySelector('.playlist-type-radio:checked')?.closest(
                     '.playlist-type-label');
                 if (initialLabel) initialLabel.classList.add('bg-indigo-50', 'border-indigo-400');

                 // Refresh playlists button
                 document.getElementById('refreshPlaylistsBtn').addEventListener('click', () => {
                     loadExistingPlaylists(true);
                 });
             }

             // ---- Load Existing Playlists from YouTube ----
             async function loadExistingPlaylists(force = false) {
                 if (existingPlaylists.length > 0 && !force) {
                     renderExistingPlaylists();
                     return;
                 }

                 const select = document.getElementById('existingPlaylistSelect');
                 select.innerHTML = '<option value="">⏳ Đang tải...</option>';

                 try {
                     const resp = await fetch(`${publishBaseUrl}/playlists`, {
                         headers: {
                             'Accept': 'application/json'
                         }
                     });
                     const result = await safeJson(resp);

                     if (result.playlists) {
                         existingPlaylists = result.playlists;
                         renderExistingPlaylists();
                     }
                 } catch (e) {
                     select.innerHTML = `<option value="">❌ Lỗi: ${e.message}</option>`;
                 }
             }

             function renderExistingPlaylists() {
                 const select = document.getElementById('existingPlaylistSelect');
                 const hint = document.getElementById('existingPlaylistHint');

                 if (existingPlaylists.length === 0) {
                     select.innerHTML = '<option value="">Không có playlist nào. Hãy tạo mới.</option>';
                     hint.textContent = '';
                     return;
                 }

                 let html = '<option value="">-- Chọn playlist --</option>';
                 existingPlaylists.forEach(pl => {
                     const selected = savedPlaylistId === pl.id ? 'selected' : '';
                     html +=
                         `<option value="${pl.id}" data-title="${pl.title}" ${selected}>${pl.title} (${pl.video_count} video)</option>`;
                 });
                 select.innerHTML = html;

                 if (savedPlaylistId) {
                     hint.textContent = `Playlist đã lưu: ${savedPlaylistTitle || savedPlaylistId}`;
                     hint.className = 'text-xs text-green-600 mt-1 font-medium';
                 } else {
                     hint.textContent = `${existingPlaylists.length} playlist tìm thấy trên kênh.`;
                     hint.className = 'text-xs text-gray-400 mt-1';
                 }
             }

             // ---- Render Playlist Child Meta ----
             function renderPlaylistMeta() {
                 const container = document.getElementById('playlistMetaList');
                 if (!playlistChildMeta.length) {
                     container.innerHTML = '<p class="text-sm text-gray-400 italic">Chưa có dữ liệu.</p>';
                     return;
                 }

                 container.innerHTML = playlistChildMeta.map((item, i) => {
                     const isUploaded = item.uploaded;
                     const statusBadge = isUploaded ?
                         '<span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 font-medium">✅ Đã upload</span>' :
                         '<span class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700">Chưa upload</span>';
                     const readonly = isUploaded ? 'readonly' : '';
                     const bgClass = isUploaded ? 'bg-green-50 border-green-200' : 'bg-gray-50';

                     return `
                    <div class="p-3 border rounded-lg ${bgClass}">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-xs font-bold text-blue-600 bg-blue-100 px-2 py-0.5 rounded">#${i+1}</span>
                            <span class="text-xs text-gray-500">${item.source_label || ''}</span>
                            ${statusBadge}
                        </div>
                        <input type="text" class="w-full border-gray-300 rounded text-sm mb-1 playlist-child-title ${isUploaded ? 'bg-gray-100 cursor-not-allowed' : ''}"
                               data-index="${i}" value="${item.title}" placeholder="Tiêu đề video ${i+1}" ${readonly}>
                        <textarea rows="2" class="w-full border-gray-300 rounded text-sm playlist-child-desc ${isUploaded ? 'bg-gray-100 cursor-not-allowed' : ''}"
                                  data-index="${i}" placeholder="Mô tả video ${i+1}" ${readonly}>${item.description}</textarea>
                    </div>`;
                 }).join('');

                 // Listen for edits (only on non-uploaded items)
                 container.querySelectorAll('.playlist-child-title:not([readonly])').forEach(input => {
                     input.addEventListener('input', function() {
                         playlistChildMeta[parseInt(this.dataset.index)].title = this.value;
                     });
                 });
                 container.querySelectorAll('.playlist-child-desc:not([readonly])').forEach(ta => {
                     ta.addEventListener('input', function() {
                         playlistChildMeta[parseInt(this.dataset.index)].description = this.value;
                     });
                 });
             }

             // ---- Save Meta Button ----
             function setupSaveMetaButton() {
                 document.getElementById('savePublishMetaBtn').addEventListener('click', async function() {
                     const btn = this;
                     const origText = btn.textContent;
                     btn.disabled = true;
                     btn.textContent = '⏳ Đang lưu...';

                     try {
                         // Collect chapter meta
                         const chapters = [];
                         const childTitles = document.querySelectorAll(
                             '.playlist-child-title:not([readonly])');
                         const childDescs = document.querySelectorAll(
                             '.playlist-child-desc:not([readonly])');
                         playlistChildMeta.forEach((item, i) => {
                             if (!item.uploaded) {
                                 chapters.push({
                                     id: item.id,
                                     title: item.title,
                                     description: item.description,
                                 });
                             }
                         });

                         const playlistType = document.querySelector('input[name="playlistType"]:checked')
                             ?.value || 'new';
                         let playlistTitle = '';
                         if (playlistType === 'new') {
                             playlistTitle = document.getElementById('playlistName').value;
                         } else {
                             const select = document.getElementById('existingPlaylistSelect');
                             const option = select.options[select.selectedIndex];
                             playlistTitle = option ? option.dataset.title || option.text : '';
                         }

                         const resp = await fetch(`${publishBaseUrl}/save-meta`, {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': csrfToken,
                                 'Accept': 'application/json'
                             },
                             body: JSON.stringify({
                                 title: document.getElementById('publishTitle').value,
                                 description: document.getElementById('publishDescription')
                                     .value,
                                 tags: document.getElementById('publishTags').value,
                                 playlist_title: playlistTitle,
                                 chapters: chapters,
                             })
                         });
                         const result = await safeJson(resp);

                         if (result.success) {
                             btn.textContent = '✅ Đã lưu!';
                             setTimeout(() => {
                                 btn.textContent = origText;
                             }, 2000);
                         } else {
                             alert('Lỗi: ' + (result.error || 'Không thể lưu'));
                         }
                     } catch (e) {
                         alert('Lỗi: ' + e.message);
                     } finally {
                         btn.disabled = false;
                         setTimeout(() => {
                             if (btn.textContent === '⏳ Đang lưu...') btn.textContent = origText;
                         }, 3000);
                     }
                 });

                 // Refresh history button
                 document.getElementById('refreshHistoryBtn').addEventListener('click', () => {
                     loadPublishHistory();
                 });
             }

             // ---- Load Publishing History ----
             async function loadPublishHistory() {
                 const container = document.getElementById('publishHistoryContainer');
                 container.innerHTML = '<p class="text-sm text-gray-400">Đang tải lịch sử...</p>';

                 try {
                     const resp = await fetch(`${publishBaseUrl}/history`, {
                         headers: {
                             'Accept': 'application/json'
                         }
                     });
                     const result = await safeJson(resp);

                     if (!result.history || result.history.length === 0) {
                         let html =
                             '<p class="text-sm text-gray-400 italic">Chưa có video nào được phát hành lên YouTube.</p>';
                         if (result.playlist && result.playlist.id) {
                             html = `<div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                <p class="text-sm font-medium text-blue-800">📋 Playlist: ${result.playlist.title || result.playlist.id}</p>
                                <a href="${result.playlist.url}" target="_blank" class="text-xs text-blue-600 hover:underline">Xem trên YouTube</a>
                            </div>` + html;
                         }
                         container.innerHTML = html;
                         return;
                     }

                     let html = '';

                     // Playlist info
                     if (result.playlist && result.playlist.id) {
                         html += `<div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <p class="text-sm font-medium text-blue-800">📋 Playlist: ${result.playlist.title || result.playlist.id}</p>
                            <a href="${result.playlist.url}" target="_blank" class="text-xs text-blue-600 hover:underline">Xem trên YouTube</a>
                            <span class="text-xs text-gray-500 ml-2">| ${result.total_uploaded} video đã upload</span>
                        </div>`;
                     }

                     // History table
                     html += `<div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-gray-50">
                                    <th class="text-left p-2 text-xs font-semibold text-gray-600">STT</th>
                                    <th class="text-left p-2 text-xs font-semibold text-gray-600">Chương</th>
                                    <th class="text-left p-2 text-xs font-semibold text-gray-600">Tiêu đề YouTube</th>
                                    <th class="text-left p-2 text-xs font-semibold text-gray-600">Video ID</th>
                                    <th class="text-left p-2 text-xs font-semibold text-gray-600">Ngày upload</th>
                                </tr>
                            </thead>
                            <tbody>`;

                     result.history.forEach((h, i) => {
                         const date = h.uploaded_at ? new Date(h.uploaded_at).toLocaleString('vi-VN') :
                             'N/A';
                         html += `<tr class="border-b hover:bg-gray-50">
                            <td class="p-2 text-gray-500">${i + 1}</td>
                            <td class="p-2 font-medium text-gray-700">Ch.${h.chapter_number}: ${h.chapter_title}</td>
                            <td class="p-2 text-gray-600">${h.youtube_video_title || '-'}</td>
                            <td class="p-2"><a href="${h.youtube_video_url}" target="_blank" class="text-blue-600 hover:underline text-xs">${h.youtube_video_id}</a></td>
                            <td class="p-2 text-gray-500 text-xs">${date}</td>
                        </tr>`;
                     });

                     html += '</tbody></table></div>';
                     container.innerHTML = html;
                 } catch (e) {
                     container.innerHTML = `<p class="text-sm text-red-500">Lỗi tải lịch sử: ${e.message}</p>`;
                 }
             }

             function stopPublishPolling() {
                 if (publishTimer) {
                     clearInterval(publishTimer);
                     publishTimer = null;
                 }
                 publishRunning = false;
             }

             function startPublishPolling() {
                 if (publishRunning) return;
                 publishRunning = true;
                 pollPublishProgress();
                 publishTimer = setInterval(pollPublishProgress, 3000);
             }

             async function pollPublishProgress() {
                 const progressEl = document.getElementById('publishProgress');
                 const progressText = document.getElementById('publishProgressText');
                 const progressBar = document.getElementById('publishProgressBar');
                 const resultEl = document.getElementById('publishResult');
                 const btn = document.getElementById('publishToYoutubeBtn');

                 try {
                     const resp = await fetch(`${publishBaseUrl}/progress`, {
                         headers: {
                             'Accept': 'application/json'
                         }
                     });
                     const data = await safeJson(resp);

                     if (!data.success) return;
                     if (data.status === 'idle') {
                         stopPublishPolling();
                         return;
                     }

                     progressEl.classList.remove('hidden');
                     const pct = typeof data.percent === 'number' ? data.percent : 1;
                     progressBar.style.width = `${pct}%`;
                     progressText.textContent = data.message || 'Đang xử lý...';

                     if (data.status === 'completed') {
                         stopPublishPolling();
                         progressBar.style.width = '100%';
                         progressText.textContent = 'Hoàn tất!';
                         if (data.result) showPublishResult(data.result);
                         if (btn) btn.disabled = false;
                         setTimeout(() => progressEl.classList.add('hidden'), 3000);
                         setTimeout(() => {
                             publishInitialized = false;
                             publishData = null;
                             playlistChildMeta = [];
                             loadPublishData();
                         }, 2000);
                     }

                     if (data.status === 'error') {
                         stopPublishPolling();
                         const msg = data.message || 'Có lỗi xảy ra.';
                         resultEl.innerHTML =
                             `<div class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-700">❌ ${msg}</div>`;
                         resultEl.classList.remove('hidden');
                         progressText.textContent = 'Lỗi!';
                         if (btn) btn.disabled = false;
                         setTimeout(() => progressEl.classList.add('hidden'), 3000);
                     }
                 } catch (e) {
                     // ignore transient errors
                 }
             }

             async function checkPublishProgressOnLoad() {
                 try {
                     const resp = await fetch(`${publishBaseUrl}/progress`, {
                         headers: {
                             'Accept': 'application/json'
                         }
                     });
                     const data = await safeJson(resp);
                     if (data.success && (data.status === 'processing' || data.status === 'queued')) {
                         startPublishPolling();
                     }
                 } catch (e) {
                     // ignore
                 }
             }

             // ---- Publish Button ----
             function setupPublishButton() {
                 document.getElementById('publishToYoutubeBtn').addEventListener('click', async function() {
                     const btn = this;
                     const mode = document.querySelector('input[name="publishMode"]:checked').value;
                     const checkedVideos = [...document.querySelectorAll('.publish-video-checkbox:checked')];

                     if (checkedVideos.length === 0) {
                         alert('Vui lòng chọn ít nhất 1 video nguồn.');
                         return;
                     }

                     if (mode !== 'playlist' && checkedVideos.length > 1) {
                         alert('Chế độ Video đơn lẻ / Shorts chỉ cho phép chọn 1 video.');
                         return;
                     }

                     const title = document.getElementById('publishTitle').value.trim();
                     if (!title) {
                         alert('Vui lòng nhập tiêu đề video.');
                         return;
                     }

                     if (!confirm(
                             `Bạn muốn phát hành ${mode === 'playlist' ? checkedVideos.length + ' video trong playlist' : '1 video'} lên YouTube?`
                         )) {
                         return;
                     }

                     btn.disabled = true;
                     const progressEl = document.getElementById('publishProgress');
                     const progressText = document.getElementById('publishProgressText');
                     const progressBar = document.getElementById('publishProgressBar');
                     const resultEl = document.getElementById('publishResult');
                     progressEl.classList.remove('hidden');
                     resultEl.classList.add('hidden');

                     try {
                         if (mode === 'playlist') {
                             const playlistType = document.querySelector(
                                 'input[name="playlistType"]:checked')?.value || 'new';

                             // Collect child meta from the editable fields
                             const childTitles = document.querySelectorAll('.playlist-child-title');
                             const childDescs = document.querySelectorAll('.playlist-child-desc');

                             if (playlistType === 'existing') {
                                 // ---- Add to Existing Playlist ----
                                 const existingPlaylistId = document.getElementById('existingPlaylistSelect')
                                     .value;
                                 if (!existingPlaylistId) {
                                     alert('Vui lòng chọn một playlist có sẵn.');
                                     btn.disabled = false;
                                     progressEl.classList.add('hidden');
                                     return;
                                 }

                                 // Only upload non-uploaded videos
                                 const newVideos = checkedVideos.filter(cb => cb.dataset.uploaded !== '1');
                                 if (newVideos.length === 0) {
                                     alert(
                                         'Tất cả video đã chọn đều đã được upload. Không có video mới để upload.'
                                     );
                                     btn.disabled = false;
                                     progressEl.classList.add('hidden');
                                     return;
                                 }

                                 const items = newVideos.map((cb, i) => {
                                     // Find matching child meta
                                     const metaItem = playlistChildMeta.find(m => m.id === cb.value);
                                     return {
                                         video_id: cb.value,
                                         video_type: cb.dataset.type,
                                         title: metaItem ? metaItem.title : title,
                                         description: metaItem ? metaItem.description : '',
                                     };
                                 });

                                 const selectEl = document.getElementById('existingPlaylistSelect');
                                 const selectedOption = selectEl.options[selectEl.selectedIndex];
                                 const playlistTitle = selectedOption ? selectedOption.dataset.title : '';

                                 progressText.textContent =
                                     `Đang xếp hàng ${newVideos.length} video vào playlist có sẵn...`;
                                 progressBar.style.width = '10%';

                                 const resp = await fetch(`${publishBaseUrl}/add-to-playlist-async`, {
                                     method: 'POST',
                                     headers: {
                                         'Content-Type': 'application/json',
                                         'X-CSRF-TOKEN': csrfToken,
                                         'Accept': 'application/json'
                                     },
                                     body: JSON.stringify({
                                         playlist_id: existingPlaylistId,
                                         playlist_title: playlistTitle,
                                         privacy: document.getElementById('publishPrivacy')
                                             .value,
                                         thumbnail_path: selectedThumbnailUrl,
                                         tags: document.getElementById('publishTags').value,
                                         items: items
                                     })
                                 });
                                 await safeJson(resp);
                                 progressText.textContent =
                                     'Đã đưa vào hàng đợi. Bạn có thể tắt trình duyệt.';
                                 startPublishPolling();
                             } else {
                                 // ---- Create New Playlist ----
                                 const items = checkedVideos.map((cb, i) => ({
                                     video_id: cb.value,
                                     video_type: cb.dataset.type,
                                     title: childTitles[i] ? childTitles[i].value : title,
                                     description: childDescs[i] ? childDescs[i].value : '',
                                 }));

                                 progressText.textContent = 'Đang xếp hàng tạo playlist và upload video...';
                                 progressBar.style.width = '10%';

                                 const resp = await fetch(`${publishBaseUrl}/create-playlist-async`, {
                                     method: 'POST',
                                     headers: {
                                         'Content-Type': 'application/json',
                                         'X-CSRF-TOKEN': csrfToken,
                                         'Accept': 'application/json'
                                     },
                                     body: JSON.stringify({
                                         playlist_name: document.getElementById(
                                                 'playlistName')
                                             .value || title,
                                         playlist_description: document.getElementById(
                                             'publishDescription').value,
                                         privacy: document.getElementById('publishPrivacy')
                                             .value,
                                         thumbnail_path: selectedThumbnailUrl,
                                         tags: document.getElementById('publishTags').value,
                                         items: items
                                     })
                                 });
                                 await safeJson(resp);
                                 progressText.textContent =
                                     'Đã đưa vào hàng đợi. Bạn có thể tắt trình duyệt.';
                                 startPublishPolling();
                             }
                         } else {
                             // Single video or Shorts
                             const cb = checkedVideos[0];
                             progressText.textContent =
                                 `Đang xếp hàng ${mode === 'shorts' ? 'Shorts' : 'video'}...`;
                             progressBar.style.width = '20%';

                             const resp = await fetch(`${publishBaseUrl}/upload-async`, {
                                 method: 'POST',
                                 headers: {
                                     'Content-Type': 'application/json',
                                     'X-CSRF-TOKEN': csrfToken,
                                     'Accept': 'application/json'
                                 },
                                 body: JSON.stringify({
                                     video_id: cb.value,
                                     video_type: cb.dataset.type,
                                     title: mode === 'shorts' ? ensureShortsHashtag(title) : title,
                                     description: document.getElementById(
                                         'publishDescription').value,
                                     tags: document.getElementById('publishTags').value,
                                     privacy: document.getElementById('publishPrivacy')
                                         .value,
                                     thumbnail_path: selectedThumbnailUrl,
                                     is_shorts: mode === 'shorts'
                                 })
                             });
                             await safeJson(resp);
                             progressText.textContent = 'Đã đưa vào hàng đợi. Bạn có thể tắt trình duyệt.';
                             startPublishPolling();
                         }
                     } catch (e) {
                         resultEl.innerHTML =
                             `<div class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-700">❌ Lỗi: ${e.message}</div>`;
                         resultEl.classList.remove('hidden');
                         progressText.textContent = 'Lỗi!';
                         btn.disabled = false;
                     }
                 });
             }

             function showPublishResult(result) {
                 const el = document.getElementById('publishResult');
                 if (result.success) {
                     let html = '<div class="p-4 bg-green-50 border border-green-300 rounded-lg">';
                     html += '<p class="text-green-700 font-semibold mb-2">✅ Phát hành thành công!</p>';

                     if (result.playlist_url) {
                         html +=
                             `<p class="text-sm"><a href="${result.playlist_url}" target="_blank" class="text-blue-600 hover:underline">🔗 Xem Playlist trên YouTube</a></p>`;
                     }

                     if (result.video_url) {
                         html +=
                             `<p class="text-sm"><a href="${result.video_url}" target="_blank" class="text-blue-600 hover:underline">🔗 Xem Video trên YouTube</a></p>`;
                     }

                     if (result.thumbnail_warning) {
                         html += `<p class="text-yellow-600 text-sm mt-2">⚠️ ${result.thumbnail_warning}</p>`;
                     }

                     if (result.uploaded_videos && result.uploaded_videos.length) {
                         html += '<div class="mt-2 space-y-1">';
                         result.uploaded_videos.forEach((v, i) => {
                             html +=
                                 `<p class="text-xs text-gray-600">${i+1}. ${v.title} - <a href="${v.url}" target="_blank" class="text-blue-600 hover:underline">Xem</a></p>`;
                         });
                         html += '</div>';
                     }

                     html += '</div>';
                     el.innerHTML = html;

                     // Refresh history after successful publish
                     loadPublishHistory();
                 } else {
                     el.innerHTML =
                         `<div class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-700">❌ ${result.error || 'Có lỗi xảy ra'}</div>`;
                 }
                 el.classList.remove('hidden');
             }

             checkPublishProgressOnLoad();
         })();
     </script>

     <!-- Find & Replace Modal -->
     <div id="findReplaceModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center"
         style="display:none !important;">
         <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-lg mx-4">
             <div class="flex justify-between items-center mb-5">
                 <h3 class="text-lg font-semibold text-gray-800">🔍 Tìm &amp; Thay thế trong toàn bộ câu chuyện</h3>
                 <button onclick="closeFindReplaceModal()"
                     class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
             </div>

             <div class="space-y-4">
                 <div>
                     <label class="block text-sm font-medium text-gray-700 mb-1">Văn bản cần tìm <span
                             class="text-red-500">*</span></label>
                     <textarea id="frSearch" rows="2" placeholder="Nhập văn bản cần tìm..."
                         class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400"></textarea>
                 </div>
                 <div>
                     <label class="block text-sm font-medium text-gray-700 mb-1">Thay thế bằng</label>
                     <textarea id="frReplace" rows="2" placeholder="Văn bản thay thế (để trống nếu muốn xóa)"
                         class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400"></textarea>
                 </div>
                 <div class="flex items-center gap-2">
                     <input type="checkbox" id="frCaseSensitive" class="rounded">
                     <label for="frCaseSensitive" class="text-sm text-gray-700">Phân biệt chữ hoa/thường</label>
                 </div>
             </div>

             <!-- Preview result -->
             <div id="frPreviewResult" class="mt-4 hidden"></div>

             <div class="flex gap-3 mt-6">
                 <button onclick="doFindReplace(true)"
                     class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm">
                     🔎 Xem trước
                 </button>
                 <button onclick="doFindReplace(false)" id="frReplaceBtn"
                     class="flex-1 bg-yellow-500 hover:bg-yellow-600 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm">
                     ✅ Thực hiện thay thế
                 </button>
                 <button onclick="closeFindReplaceModal()"
                     class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-2 px-4 rounded-lg transition duration-200 text-sm">
                     Hủy
                 </button>
             </div>
         </div>
     </div>

     <script>
         function openFindReplaceModal() {
             document.getElementById('findReplaceModal').style.cssText = '';
             document.getElementById('frSearch').value = '';
             document.getElementById('frReplace').value = '';
             document.getElementById('frCaseSensitive').checked = false;
             document.getElementById('frPreviewResult').classList.add('hidden');
             document.getElementById('frPreviewResult').innerHTML = '';
             document.getElementById('frSearch').focus();
         }

         function closeFindReplaceModal() {
             document.getElementById('findReplaceModal').style.cssText = 'display:none !important;';
         }

         async function doFindReplace(previewOnly) {
             const search = document.getElementById('frSearch').value.trim();
             const replace = document.getElementById('frReplace').value;
             const replaceTrimmed = replace.trim();
             const isWhitespaceOnlyReplace = replace !== '' && replaceTrimmed === '';
             const isSingleSpaceReplace = replace === ' ';
             const caseSensitive = document.getElementById('frCaseSensitive').checked;
             const previewEl = document.getElementById('frPreviewResult');
             const btn = document.getElementById('frReplaceBtn');

             if (!search) {
                 previewEl.innerHTML =
                     '<div class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-700 text-sm">⚠️ Vui lòng nhập văn bản cần tìm.</div>';
                 previewEl.classList.remove('hidden');
                 return;
             }

             // Allow exactly one space, but block other whitespace-only replacements (tab/newline/multi-space).
             if (isWhitespaceOnlyReplace && !isSingleSpaceReplace) {
                 previewEl.innerHTML =
                     '<div class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-700 text-sm">⚠️ Bạn chỉ có thể dùng đúng 1 dấu cách để thay thế. Nếu muốn xóa thì để trống.</div>';
                 previewEl.classList.remove('hidden');
                 return;
             }

             if (!previewOnly) {
                 if (!confirm(
                         `Bạn có chắc muốn thay thế tất cả chuỗi "${search}" trong toàn bộ câu chuyện?\n\nHành động này không thể hoàn tác.`
                     )) return;
             }

             btn.disabled = true;
             previewEl.innerHTML =
                 '<div class="p-3 bg-blue-50 border border-blue-300 rounded-lg text-blue-700 text-sm">⏳ Đang xử lý...</div>';
             previewEl.classList.remove('hidden');

             try {
                 const response = await fetch('{{ route('audiobooks.find.replace', $audioBook) }}', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                         'Accept': 'application/json',
                     },
                     body: JSON.stringify({
                         search,
                         replace,
                         case_sensitive: caseSensitive ? 1 : 0,
                         preview_only: previewOnly ? 1 : 0,
                     }),
                 });
                 const text = await response.text();
                 let result = {};
                 try {
                     result = text ? JSON.parse(text) : {};
                 } catch (parseError) {
                     throw new Error(`Phản hồi không hợp lệ từ server (HTTP ${response.status}).`);
                 }

                 if (!response.ok) {
                     let errorMsg = result.error || result.message || '';
                     if (!errorMsg && result.errors && typeof result.errors === 'object') {
                         const firstFieldErrors = Object.values(result.errors).find((arr) => Array.isArray(arr) && arr.length);
                         if (firstFieldErrors) {
                             errorMsg = firstFieldErrors[0];
                         }
                     }
                     throw new Error(errorMsg || `Yêu cầu thất bại (HTTP ${response.status}).`);
                 }

                 if (result.success) {
                     if (result.total_matches === 0) {
                         previewEl.innerHTML =
                             '<div class="p-3 bg-yellow-50 border border-yellow-300 rounded-lg text-yellow-700 text-sm">ℹ️ Không tìm thấy kết quả nào khớp.</div>';
                     } else if (previewOnly) {
                         let html = `<div class="p-3 bg-blue-50 border border-blue-300 rounded-lg text-sm">
                            <p class="font-semibold text-blue-800 mb-2">🔎 Kết quả tìm kiếm: <strong>${result.total_matches} lần</strong> trong <strong>${result.chapters_affected} chương</strong></p>
                            <ul class="space-y-1 max-h-40 overflow-y-auto">`;
                         result.preview_items.forEach(item => {
                             html +=
                                 `<li class="text-blue-700 text-xs">• ${item.chapter_title}: <span class="font-medium">${item.match_count} lần</span></li>`;
                         });
                         html += '</ul></div>';
                         previewEl.innerHTML = html;
                     } else {
                         previewEl.innerHTML =
                             `<div class="p-3 bg-green-50 border border-green-300 rounded-lg text-green-700 text-sm">✅ ${result.message}</div>`;
                         setTimeout(() => closeFindReplaceModal(), 2000);
                     }
                 } else {
                     const errorMsg = result.error || result.message || 'Có lỗi xảy ra.';
                     previewEl.innerHTML =
                         `<div class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-700 text-sm">❌ ${errorMsg}</div>`;
                 }
             } catch (e) {
                 previewEl.innerHTML =
                     `<div class="p-3 bg-red-50 border border-red-300 rounded-lg text-red-700 text-sm">❌ Lỗi kết nối: ${e.message}</div>`;
             } finally {
                 btn.disabled = false;
             }
         }

         async function fixLeadingInitialSpaceAllChapters() {
             const btn = document.getElementById('fixLeadingInitialSpaceBtn');
             if (!btn) return;

             if (!confirm(
                     'Sửa toàn bộ chương: nếu đầu chương là 1 chữ cái + 1 khoảng trắng thì sẽ xóa khoảng trắng đó.\n\nTiếp tục?'
                 )) {
                 return;
             }

             const originalText = btn.innerHTML;
             btn.disabled = true;
             btn.innerHTML = '⏳ Đang fix...';

             try {
                 const response = await fetch('{{ route('audiobooks.fix.leading.initial.space', $audioBook) }}', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                         'Accept': 'application/json',
                     },
                     body: JSON.stringify({}),
                 });

                 const result = await response.json();
                 if (!response.ok || !result.success) {
                     throw new Error(result.error || result.message || `HTTP ${response.status}`);
                 }

                 alert(`✅ ${result.message}`);
                 window.location.reload();
             } catch (e) {
                 alert(`❌ Lỗi khi fix: ${e.message}`);
             } finally {
                 btn.disabled = false;
                 btn.innerHTML = originalText;
             }
         }

         let embeddingPollingInterval = null;

         function applyChunkEmbeddingButtonState(state) {
             const mainBtn = document.getElementById('chunkEmbeddingBtn');
             const floatingBtn = document.getElementById('chunkEmbeddingBtnFloating');
             const buttons = [mainBtn, floatingBtn].filter(Boolean);
             if (!state || buttons.length === 0) {
                 return;
             }

             const mode = String(state.mode || 'locked');
             const canRun = !!state.can_run;
             const label = String(state.label || (canRun ? '🧩 Chunk & Embedding' : '✅ Đã hoàn tất'));
             const title = String(state.title || '');

             buttons.forEach((btn) => {
                 btn.dataset.mode = mode;
                 btn.title = title;
                 btn.disabled = !canRun;
                 btn.innerHTML = label;

                 btn.classList.remove('bg-indigo-600', 'hover:bg-indigo-700', 'text-white', 'bg-gray-300', 'text-gray-600', 'cursor-not-allowed');
                 if (canRun) {
                     btn.classList.add('bg-indigo-600', 'hover:bg-indigo-700', 'text-white');
                 } else {
                     btn.classList.add('bg-gray-300', 'text-gray-600', 'cursor-not-allowed');
                 }
             });
         }

         function renderEmbeddingProgress(data) {
             const container = document.getElementById('embeddingProgressContainer');
             const badge = document.getElementById('embeddingProgressBadge');
             const statusEl = document.getElementById('embeddingProgressStatus');
             const bar = document.getElementById('embeddingProgressBar');
             const countsEl = document.getElementById('embeddingProgressCounts');
             const percentEl = document.getElementById('embeddingProgressPercent');

             if (!container || !badge || !statusEl || !bar || !countsEl || !percentEl) {
                 return;
             }

             const counts = data.counts || {};
             const total = Number(counts.total_chunks || 0);
             const queued = Number(counts.queued_chunks || 0);
             const processing = Number(counts.processing_chunks || 0);
             const done = Number(counts.done_chunks || 0);
             const error = Number(counts.error_chunks || 0);

             if (data.status === 'idle' && total === 0) {
                 container.classList.add('hidden');
                 return;
             }

             container.classList.remove('hidden');

             const status = String(data.status || 'queued');
             const statusMap = {
                 queued: { label: 'queued', className: 'bg-amber-100 text-amber-700' },
                 processing: { label: 'processing', className: 'bg-blue-100 text-blue-700' },
                 done: { label: 'done', className: 'bg-green-100 text-green-700' },
                 error: { label: 'error', className: 'bg-red-100 text-red-700' },
                 idle: { label: 'idle', className: 'bg-gray-100 text-gray-700' },
             };
             const statusStyle = statusMap[status] || statusMap.queued;

             badge.textContent = statusStyle.label;
             badge.className = `text-[11px] px-2 py-0.5 rounded-full ${statusStyle.className}`;

             statusEl.textContent = data.message || 'Đang xử lý embedding...';

             const percent = Number(data.percent || 0);
             bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
             percentEl.textContent = `${Math.max(0, Math.min(100, Math.round(percent)))}%`;

             countsEl.textContent = `queued: ${queued} | processing: ${processing} | done: ${done} | error: ${error} | total: ${total}`;
         }

         function stopEmbeddingPolling() {
             if (embeddingPollingInterval) {
                 clearInterval(embeddingPollingInterval);
                 embeddingPollingInterval = null;
             }
         }

         function startEmbeddingPolling() {
             if (embeddingPollingInterval) {
                 return;
             }
             pollEmbeddingProgress();
             embeddingPollingInterval = setInterval(pollEmbeddingProgress, 2500);
         }

         async function pollEmbeddingProgress() {
             try {
                 const response = await fetch('{{ route('audiobooks.embedding.progress', $audioBook) }}', {
                     headers: {
                         'Accept': 'application/json',
                     },
                 });

                 if (!response.ok) {
                     return null;
                 }

                 const data = await response.json();
                 if (!data.success) {
                     return null;
                 }

                 renderEmbeddingProgress(data);
                 if (data.button_state) {
                     applyChunkEmbeddingButtonState(data.button_state);
                 }

                 const counts = data.counts || {};
                 const queued = Number(counts.queued_chunks || 0);
                 const processing = Number(counts.processing_chunks || 0);

                 if ((data.status === 'done' || data.status === 'error' || data.status === 'idle') && queued === 0 && processing === 0) {
                     stopEmbeddingPolling();
                 }

                 return data;
             } catch (e) {
                 return null;
             }
         }

         async function checkEmbeddingProgressOnLoad() {
             const data = await pollEmbeddingProgress();
             if (!data) {
                 return;
             }

             if (data.status === 'queued' || data.status === 'processing') {
                 startEmbeddingPolling();
             }
         }

         async function chunkAndEmbeddingAllChapters() {
             const mainBtn = document.getElementById('chunkEmbeddingBtn');
             const floatingBtn = document.getElementById('chunkEmbeddingBtnFloating');
             const panel = document.getElementById('chunkEmbeddingPanel');
             const statusEl = document.getElementById('chunkEmbeddingStatus');

             const activeButtons = [mainBtn, floatingBtn].filter(Boolean);
             if (!panel || !statusEl || activeButtons.length === 0) {
                 alert('Không tìm thấy nút hoặc vùng hiển thị Chunk & Embedding.');
                 return;
             }

             const currentMode = String(activeButtons[0]?.dataset.mode || 'locked');
             if (!['chunk_and_embedding', 'embedding_only'].includes(currentMode)) {
                 return;
             }

             const embeddingOnly = currentMode === 'embedding_only';
             const confirmMessage = embeddingOnly
                 ? `Bắt đầu Embedding cho các chunk pending?

Hệ thống sẽ KHÔNG tạo lại chunk, chỉ đưa các job embedding vào queue.`
                 : `Bắt đầu Chunk & Embedding cho toàn bộ chương chưa chunk?

Hệ thống sẽ:
1) Cắt chapter thành chunk và lưu DB
2) Đẩy các chunk có embedding_status = pending vào queue`;

             if (!confirm(confirmMessage)) {
                 return;
             }

             const esc = (typeof escapeHtml === 'function')
                 ? escapeHtml
                 : (text) => (text || '').replace(/&/g, '&amp;')
                     .replace(/</g, '&lt;')
                     .replace(/>/g, '&gt;')
                     .replace(/"/g, '&quot;')
                     .replace(/'/g, '&#039;');

             const snapshots = activeButtons.map((btn) => ({
                 btn,
                 text: btn.innerHTML,
                 disabled: btn.disabled,
             }));

             snapshots.forEach((item) => {
                 item.btn.disabled = true;
                 item.btn.innerHTML = embeddingOnly ? '⏳ Đang queue embedding...' : '⏳ Đang chunk...';
             });

             panel.classList.remove('hidden');
             statusEl.innerHTML = embeddingOnly
                 ? '<span class="text-indigo-700">⏳ Đang đưa các chunk pending vào queue embedding...</span>'
                 : '<span class="text-indigo-700">⏳ Đang chunk và đưa embedding jobs vào queue...</span>';

             let requestSucceeded = false;

             try {
                 const response = await fetch('{{ route('audiobooks.chunk.queue.embeddings', $audioBook) }}', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                         'Accept': 'application/json',
                     },
                     body: JSON.stringify({}),
                 });

                 const text = await response.text();
                 let result = {};
                 try {
                     result = text ? JSON.parse(text) : {};
                 } catch (parseError) {
                     throw new Error(`Phản hồi không hợp lệ từ server (HTTP ${response.status}).`);
                 }

                 if (!response.ok || !result.success) {
                     let errorMsg = result.error || result.message || '';
                     if (!errorMsg && result.errors && typeof result.errors === 'object') {
                         const firstFieldErrors = Object.values(result.errors).find((arr) => Array.isArray(arr) && arr.length);
                         if (firstFieldErrors) {
                             errorMsg = firstFieldErrors[0];
                         }
                     }
                     throw new Error(errorMsg || `Yêu cầu thất bại (HTTP ${response.status}).`);
                 }

                 const summary = result.summary || {};
                 statusEl.innerHTML = `
                    <div class="p-3 bg-white border border-indigo-200 rounded-lg text-sm text-indigo-900 space-y-1">
                        <p class="font-semibold">✅ ${esc(result.message || 'Đã hoàn tất queue embedding.')}</p>
                        <p>📚 Chương có nội dung: <strong>${Number(summary.total_chapters_with_content || 0)}</strong></p>
                        <p>🧩 Chương mới được chunk: <strong>${Number(summary.newly_chunked_chapters || 0)}</strong></p>
                        <p>📦 Chunk mới tạo: <strong>${Number(summary.new_chunks_created || 0)}</strong></p>
                        <p>🚚 Jobs embedding đã queue: <strong>${Number(summary.queued_embedding_jobs || 0)}</strong></p>
                        <p>🧮 Chương chưa chunk còn lại: <strong>${Number(summary.remaining_unchunked_chapters || 0)}</strong></p>
                        <p>⏳ Chunk pending embedding: <strong>${Number(summary.pending_embedding_chunks || 0)}</strong></p>
                    </div>
                 `;

                 if (result.button_state) {
                     applyChunkEmbeddingButtonState(result.button_state);
                 }

                 requestSucceeded = true;
                 startEmbeddingPolling();
             } catch (e) {
                 statusEl.innerHTML = `<span class="text-red-700">❌ Lỗi Chunk/Embedding: ${esc(e.message)}</span>`;
             } finally {
                 if (!requestSucceeded) {
                     snapshots.forEach((item) => {
                         item.btn.disabled = item.disabled;
                         item.btn.innerHTML = item.text;
                     });
                 }
             }
         }

         function hideTtsIssueScanPanel() {
             const panel = document.getElementById('ttsIssueScanPanel');
             if (panel) {
                 panel.classList.add('hidden');
             }
         }

         // State for TTS issue paragraph editing
         let _ttsIssueChapterId = null;
         let _ttsIssueOriginalParagraph = '';

         function openTtsIssueParagraphModal(encodedParagraph, encodedMatchedText = '', chapterId = null) {
             const modal = document.getElementById('ttsIssueParagraphModal');
             const contentEl = document.getElementById('ttsIssueParagraphContent');
             const metaEl = document.getElementById('ttsIssueParagraphMeta');
             const saveMsg = document.getElementById('ttsIssueParagraphSaveMsg');

             if (!modal || !contentEl || !metaEl) {
                 return;
             }

             const esc = (typeof escapeHtml === 'function')
                 ? escapeHtml
                 : (text) => (text || '').replace(/&/g, '&amp;')
                     .replace(/</g, '&lt;')
                     .replace(/>/g, '&gt;')
                     .replace(/"/g, '&quot;')
                     .replace(/'/g, '&#039;');

             let paragraph = '';
             let matchedText = '';

             try {
                 paragraph = decodeURIComponent(encodedParagraph || '');
             } catch (e) {
                 paragraph = encodedParagraph || '';
             }

             try {
                 matchedText = decodeURIComponent(encodedMatchedText || '');
             } catch (e) {
                 matchedText = encodedMatchedText || '';
             }

             // Store state for save
             _ttsIssueChapterId = chapterId;
             _ttsIssueOriginalParagraph = paragraph;

             metaEl.innerHTML = matchedText
                 ? `Từ nghi lỗi: <code class="rounded bg-rose-100 px-1 py-0.5 text-[11px] text-rose-700">${esc(matchedText)}</code>`
                 : '';

             // Reset save message
             if (saveMsg) {
                 saveMsg.classList.add('hidden');
                 saveMsg.textContent = '';
             }

             // Set textarea content
             contentEl.value = paragraph || '';

             modal.classList.remove('hidden');
             modal.classList.add('flex');

             // Scroll to and highlight matched text in textarea
             const doHighlight = (searchText) => {
                 // Try exact match first, then case-insensitive
                 let idx = paragraph.indexOf(searchText);
                 if (idx === -1) {
                     idx = paragraph.toLowerCase().indexOf(searchText.toLowerCase());
                 }
                 if (idx !== -1) {
                     contentEl.focus();
                     contentEl.setSelectionRange(idx, idx + searchText.length);
                     // Count actual newlines before match for accurate scroll
                     const linesBefore = (paragraph.substring(0, idx).match(/\n/g) || []).length;
                     const lineHeight = parseInt(getComputedStyle(contentEl).lineHeight) || 22;
                     contentEl.scrollTop = Math.max(0, linesBefore * lineHeight - contentEl.clientHeight / 3);
                 } else {
                     contentEl.focus();
                     contentEl.setSelectionRange(0, 0);
                     contentEl.scrollTop = 0;
                 }
             };
             if (matchedText) {
                 setTimeout(() => doHighlight(matchedText), 100);
             } else {
                 setTimeout(() => {
                     contentEl.focus();
                     contentEl.setSelectionRange(0, 0);
                     contentEl.scrollTop = 0;
                 }, 100);
             }
         }

         function closeTtsIssueParagraphModal() {
             const modal = document.getElementById('ttsIssueParagraphModal');
             if (!modal) {
                 return;
             }
             modal.classList.add('hidden');
             modal.classList.remove('flex');
             _ttsIssueChapterId = null;
             _ttsIssueOriginalParagraph = '';
         }

         async function saveTtsIssueParagraphEdit() {
             const contentEl = document.getElementById('ttsIssueParagraphContent');
             const saveBtn = document.getElementById('ttsIssueParagraphSaveBtn');
             const saveMsg = document.getElementById('ttsIssueParagraphSaveMsg');

             if (!contentEl || !_ttsIssueChapterId) {
                 if (saveMsg) {
                     saveMsg.textContent = 'Lỗi: Không xác định được chapter cần lưu.';
                     saveMsg.className = 'mx-4 mb-1 text-xs text-red-600';
                     saveMsg.classList.remove('hidden');
                 }
                 return;
             }

             const replacement = contentEl.value;
             if (replacement === _ttsIssueOriginalParagraph) {
                 if (saveMsg) {
                     saveMsg.textContent = 'Nội dung chưa thay đổi.';
                     saveMsg.className = 'mx-4 mb-1 text-xs text-gray-500';
                     saveMsg.classList.remove('hidden');
                 }
                 return;
             }

             if (saveBtn) saveBtn.disabled = true;
             if (saveMsg) {
                 saveMsg.textContent = 'Đang lưu...';
                 saveMsg.className = 'mx-4 mb-1 text-xs text-gray-500';
                 saveMsg.classList.remove('hidden');
             }

             try {
                 const audioBookId = {{ $audioBook->id }};
                 const url = `/audiobooks/${audioBookId}/chapters/${_ttsIssueChapterId}/fix-paragraph`;
                 const resp = await fetch(url, {
                     method: 'PATCH',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                         'Accept': 'application/json',
                     },
                     body: JSON.stringify({
                         original: _ttsIssueOriginalParagraph,
                         replacement: replacement,
                     }),
                 });

                 const data = await resp.json();

                 if (resp.ok && data.success) {
                     _ttsIssueOriginalParagraph = replacement;
                     if (saveMsg) {
                         saveMsg.textContent = '✓ Đã lưu thành công.';
                         saveMsg.className = 'mx-4 mb-1 text-xs text-emerald-600';
                     }
                 } else {
                     if (saveMsg) {
                         saveMsg.textContent = `Lỗi: ${data.message || data.error || 'Không thể lưu.'}`;
                         saveMsg.className = 'mx-4 mb-1 text-xs text-red-600';
                     }
                 }
             } catch (err) {
                 if (saveMsg) {
                     saveMsg.textContent = `Lỗi kết nối: ${err.message}`;
                     saveMsg.className = 'mx-4 mb-1 text-xs text-red-600';
                 }
             } finally {
                 if (saveBtn) saveBtn.disabled = false;
             }
         }

         function scrollToChapterFromIssueScan(chapterId) {
             const listContainer = document.getElementById('chapterListContainer');
             const arrow = document.getElementById('chapterListArrow');
             const text = document.getElementById('chapterListToggleText');

             if (listContainer && listContainer.style.display === 'none') {
                 listContainer.style.display = 'block';
                 if (arrow) {
                     arrow.classList.add('rotate-90');
                 }
                 if (text) {
                     text.textContent = 'Ẩn danh sách chương';
                 }
             }

             const chapterEl = document.getElementById(`chapter-${chapterId}`);
             if (!chapterEl) {
                 return;
             }

             chapterEl.scrollIntoView({
                 behavior: 'smooth',
                 block: 'center'
             });

             chapterEl.classList.add('ring-2', 'ring-rose-400');
             setTimeout(() => {
                 chapterEl.classList.remove('ring-2', 'ring-rose-400');
             }, 2000);
         }

         async function scanTtsVietnameseIssuesAllChapters() {
             const mainBtn = document.getElementById('scanTtsIssuesBtn');
             const floatingBtn = document.getElementById('scanTtsIssuesBtnFloating');
             const panel = document.getElementById('ttsIssueScanPanel');
             const statusEl = document.getElementById('ttsIssueScanStatus');
             const summaryEl = document.getElementById('ttsIssueScanSummary');
             const listEl = document.getElementById('ttsIssueScanList');

             if (!panel || !statusEl || !summaryEl || !listEl) {
                 alert('Không tìm thấy vùng hiển thị kết quả quét.');
                 return;
             }

             const activeButtons = [mainBtn, floatingBtn].filter(Boolean);
             activeButtons.forEach((btn) => {
                 btn.dataset.originalText = btn.innerHTML;
                 btn.disabled = true;
                 btn.innerHTML = '⏳ Đang quét...';
             });

             panel.classList.remove('hidden');
             statusEl.innerHTML = '<span class="text-rose-700">⏳ Đang phân tích toàn bộ chương...</span>';
             summaryEl.innerHTML = '';
             listEl.innerHTML = '';

             const esc = (typeof escapeHtml === 'function')
                 ? escapeHtml
                 : (text) => (text || '').replace(/&/g, '&amp;')
                     .replace(/</g, '&lt;')
                     .replace(/>/g, '&gt;')
                     .replace(/"/g, '&quot;')
                     .replace(/'/g, '&#039;');

             try {
                 const response = await fetch('{{ route('audiobooks.scan.tts.vietnamese.issues', $audioBook) }}', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                         'Accept': 'application/json',
                     },
                     body: JSON.stringify({}),
                 });

                 const result = await response.json();
                 if (!response.ok || !result.success) {
                     throw new Error(result.error || result.message || `HTTP ${response.status}`);
                 }

                 const summary     = result.summary || {};
                 const chapters    = Array.isArray(result.chapters) ? result.chapters : [];
                 const properNouns = Array.isArray(result.proper_nouns) ? result.proper_nouns : [];
                 const typeCountsObj = summary.type_counts || {};
                 const typeEntries = Object.entries(typeCountsObj).map(([type, item]) => ({
                     type,
                     label: String(item?.label || type),
                     count: Number(item?.count || 0)
                 }));
                 const selectedTypes = new Set(typeEntries.map((entry) => entry.type));

                 // ── Render proper nouns panel ──────────────────────────────
                 const properNounsEl = document.getElementById('ttsProperNounsPanel');
                 if (properNounsEl) {
                     if (properNouns.length === 0) {
                         properNounsEl.innerHTML = '<p class="text-xs text-gray-500 italic">Không phát hiện tên riêng đặc biệt.</p>';
                     } else {
                         let showAll = false;
                         const LIMIT = 40;

                         const render = () => {
                             const visible = showAll ? properNouns : properNouns.slice(0, LIMIT);
                             const tags = visible.map(n =>
                                 `<span class="inline-flex items-center gap-1 bg-blue-50 border border-blue-200 text-blue-800 text-xs px-2 py-0.5 rounded-full cursor-pointer hover:bg-blue-100 select-all" title="Xuất hiện ${n.count} lần">${esc(n.text)} <em class="not-italic text-blue-400">${n.count}</em></span>`
                             ).join('');
                             const toggleBtn = properNouns.length > LIMIT
                                 ? `<button type="button" id="properNounsToggle" class="text-xs text-blue-600 underline ml-1">${showAll ? 'Thu gọn' : `Xem thêm ${properNouns.length - LIMIT}…`}</button>`
                                 : '';
                             properNounsEl.innerHTML = `<div class="flex flex-wrap gap-1.5">${tags}</div>${toggleBtn}`;
                             const toggleEl = document.getElementById('properNounsToggle');
                             if (toggleEl) toggleEl.addEventListener('click', () => { showAll = !showAll; render(); });
                         };
                         render();
                     }
                     properNounsEl.closest('.tts-proper-nouns-section')?.classList.remove('hidden');
                 }

                 const getFilteredChapters = () => {
                     return chapters.map((chapter) => {
                         const issues = Array.isArray(chapter.issues) ? chapter.issues : [];
                         const filteredIssues = selectedTypes.size === 0
                             ? []
                             : issues.filter((issue) => selectedTypes.has(issue.type || ''));

                         return {
                             ...chapter,
                             filtered_issues: filteredIssues,
                             filtered_issues_count: filteredIssues.length,
                         };
                     }).filter((chapter) => chapter.filtered_issues_count > 0);
                 };

                 function renderSummaryAndFilters() {
                     const filteredChapters = getFilteredChapters();
                     const filteredIssueCount = filteredChapters.reduce((sum, chapter) => sum + Number(chapter.filtered_issues_count || 0), 0);
                     const allTypesSelected = typeEntries.length > 0 && selectedTypes.size === typeEntries.length;

                     const typeButtons = typeEntries.map((entry) => {
                         const isActive = selectedTypes.has(entry.type);
                         const activeClass = isActive
                             ? 'bg-rose-600 border-rose-600 text-white'
                             : 'bg-white border-rose-200 text-rose-700 hover:bg-rose-100';

                         return `
                            <button type="button" data-filter-type="${esc(entry.type)}"
                                class="tts-issue-type-filter inline-flex items-center border text-xs px-2 py-1 rounded-full transition ${activeClass}">
                                ${esc(entry.label)}: <strong class="ml-1">${entry.count}</strong>
                            </button>
                         `;
                     }).join('');

                     const allBtnClass = allTypesSelected
                         ? 'bg-gray-800 border-gray-800 text-white'
                         : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-100';

                     summaryEl.innerHTML = `
                        <div class="p-3 bg-white border border-rose-200 rounded-lg text-sm">
                            <p class="text-rose-800 font-semibold">📊 Tổng hợp: ${Number(summary.total_issues || 0)} cảnh báo trong ${Number(summary.affected_chapters || 0)}/${Number(summary.total_chapters || 0)} chương.</p>
                            ${summary.truncated ? '<p class="text-xs text-amber-700 mt-1">⚠️ Kết quả đã được giới hạn để tải nhanh. Bạn có thể xử lý đợt đầu rồi quét lại.</p>' : ''}
                            ${typeEntries.length > 0 ? `<p class="text-xs text-rose-700 mt-2">🧩 Click nhóm lỗi để lọc. Đang hiển thị: <strong>${filteredIssueCount}</strong> lỗi trong <strong>${filteredChapters.length}</strong> chương.</p>` : ''}
                            ${typeEntries.length > 0 ? `<div class="mt-2 flex flex-wrap gap-1.5">
                                <button type="button" data-filter-type="__all__" class="tts-issue-type-filter inline-flex items-center border text-xs px-2 py-1 rounded-full transition ${allBtnClass}">Tất cả</button>
                                ${typeButtons}
                            </div>` : ''}
                        </div>
                     `;

                     summaryEl.querySelectorAll('.tts-issue-type-filter').forEach((btn) => {
                         btn.addEventListener('click', () => {
                             const type = btn.getAttribute('data-filter-type');
                             if (!type) {
                                 return;
                             }

                             if (type === '__all__') {
                                 selectedTypes.clear();
                                 typeEntries.forEach((entry) => selectedTypes.add(entry.type));
                             } else {
                                 if (selectedTypes.has(type)) {
                                     selectedTypes.delete(type);
                                 } else {
                                     selectedTypes.add(type);
                                 }
                             }

                             renderSummaryAndFilters();
                             renderFilteredList();
                         });
                     });
                 }

                 if (chapters.length === 0) {
                     renderSummaryAndFilters();
                     statusEl.innerHTML = '<span class="text-green-700">✅ Không phát hiện lỗi rõ ràng có thể gây sai đọc TTS.</span>';
                     listEl.innerHTML = '';
                     return;
                 }

                 statusEl.innerHTML = `<span class="text-rose-700">⚠️ ${esc(result.message || 'Đã hoàn tất quét.')}</span>`;

                 function renderFilteredList() {
                     const filteredChapters = getFilteredChapters();
                     if (filteredChapters.length === 0) {
                         listEl.innerHTML = `
                            <div class="p-3 bg-white border border-rose-200 rounded-lg text-sm text-rose-700">
                                Không có lỗi nào khớp với nhóm đang chọn.
                            </div>
                         `;
                         return;
                     }

                     listEl.innerHTML = filteredChapters.map((chapter) => {
                         const chapterTitle = chapter.chapter_title || `Chương ${chapter.chapter_number || ''}`;
                         const issues = Array.isArray(chapter.filtered_issues) ? chapter.filtered_issues : [];
                         const issuesHtml = issues.map((issue) => {
                             const label = esc(issue.label || issue.type || 'Cảnh báo');
                             const matchedText = esc(issue.matched_text || '');
                             const context = esc(issue.context || '');
                             const paragraph = encodeURIComponent(String(issue.paragraph || issue.context || ''));
                             const matchedToken = encodeURIComponent(String(issue.matched_text || ''));
                             const suggestion = esc(issue.suggestion || 'Kiểm tra và sửa lại theo cách đọc tự nhiên.');

                             return `
                                <li class="bg-white border border-gray-200 rounded-md p-2.5">
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="text-[11px] font-semibold text-rose-700 bg-rose-100 px-1.5 py-0.5 rounded">${label}</span>
                                        <code class="text-[11px] text-gray-800 bg-gray-100 px-1.5 py-0.5 rounded break-all">${matchedText}</code>
                                    </div>
                                    <p class="text-xs text-gray-700 mt-1">${context}</p>
                                    <p class="text-[11px] text-emerald-700 mt-1">Gợi ý: ${suggestion}</p>
                                    <div class="mt-2 flex justify-end">
                                        <button type="button"
                                            onclick="openTtsIssueParagraphModal('${paragraph}', '${matchedToken}', ${chapter.chapter_id})"
                                            class="text-[11px] bg-white hover:bg-rose-100 text-rose-700 border border-rose-200 px-2 py-1 rounded transition">
                                            📄 Xem đoạn văn
                                        </button>
                                    </div>
                                </li>
                             `;
                         }).join('');

                         return `
                            <div class="border border-rose-200 bg-rose-100/40 rounded-lg p-3">
                                <div class="flex items-center justify-between gap-3 mb-2">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800">📖 ${esc(chapterTitle)}</p>
                                        <p class="text-xs text-gray-600">${Number(chapter.filtered_issues_count || 0)} cảnh báo${chapter.issues_truncated ? ' (đã giới hạn hiển thị)' : ''}</p>
                                    </div>
                                    <button type="button" onclick="scrollToChapterFromIssueScan(${Number(chapter.chapter_id || 0)})"
                                        class="text-xs bg-white hover:bg-rose-100 text-rose-700 border border-rose-200 px-2 py-1 rounded transition">
                                        🔗 Tới chương
                                    </button>
                                </div>
                                <ul class="space-y-2">${issuesHtml}</ul>
                            </div>
                         `;
                     }).join('');
                 }

                 renderSummaryAndFilters();
                 renderFilteredList();
             } catch (e) {
                 statusEl.innerHTML = `<span class="text-red-700">❌ Lỗi quét văn bản: ${esc(e.message)}</span>`;
                 summaryEl.innerHTML = '';
                 listEl.innerHTML = '';
             } finally {
                 activeButtons.forEach((btn) => {
                     btn.disabled = false;
                     btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
                 });
             }
         }


        // ========== CLIPPING ==========
        const clippingBaseUrl = '{{ route("audiobooks.clipping.videos", $audioBook->id) }}'.replace('/videos', '');
        let clippingCtaAnimations = [];
        let clippingCtaAutoAnimationPath = null;
        let clippingCachedClips = [];

        async function loadClippingTab() {
            await Promise.all([loadClippingVideos(), loadClippingBackgroundAudios(), loadClippingCtaAnimations()]);
            applySavedClippingSettings();
            await loadClips();
            updateClippingBgVolumeLabel();
        }

        function getClippingSettingsStorageKey() {
            return `clipping_settings_{{ $audioBook->id }}`;
        }

        function applySavedClippingSettings() {
            try {
                const raw = localStorage.getItem(getClippingSettingsStorageKey());
                if (!raw) return;

                const saved = JSON.parse(raw);
                const styleSelect = document.getElementById('clippingSubtitleStyle');
                const positionSelect = document.getElementById('clippingSubtitlePosition');

                if (styleSelect && saved?.subtitle_style) {
                    const hasStyle = Array.from(styleSelect.options).some(opt => opt.value === saved.subtitle_style);
                    if (hasStyle) styleSelect.value = saved.subtitle_style;
                }

                if (positionSelect && saved?.subtitle_position) {
                    const hasPosition = Array.from(positionSelect.options).some(opt => opt.value === saved.subtitle_position);
                    if (hasPosition) positionSelect.value = saved.subtitle_position;
                }
            } catch (e) {
                // ignore localStorage parse errors
            }
        }

        async function saveClippingSettings() {
            const btn = document.getElementById('saveClippingSettingsBtn');
            const statusEl = document.getElementById('saveClippingSettingsStatus');
            const styleSelect = document.getElementById('clippingSubtitleStyle');
            const positionSelect = document.getElementById('clippingSubtitlePosition');

            const subtitleStyle = styleSelect ? styleSelect.value : 'highlight_green';
            const subtitlePosition = positionSelect ? positionSelect.value : 'lower_third';

            try {
                localStorage.setItem(getClippingSettingsStorageKey(), JSON.stringify({
                    subtitle_style: subtitleStyle,
                    subtitle_position: subtitlePosition
                }));
            } catch (e) {
                // ignore localStorage errors
            }

            const originalText = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '⏳ Đang lưu...';
            }
            if (statusEl) statusEl.textContent = 'Đang lưu setting phụ đề cho clips...';

            try {
                let clips = Array.isArray(clippingCachedClips) ? clippingCachedClips : [];
                if (!clips.length) {
                    const clipsResp = await fetch(`${clippingBaseUrl}/clips`, {
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
                    });
                    const clipsData = await safeJson(clipsResp);
                    clips = Array.isArray(clipsData.clips) ? clipsData.clips : [];
                }

                if (!clips.length) {
                    if (statusEl) statusEl.textContent = '✅ Đã lưu setting mặc định (chưa có clip để áp dụng).';
                    return;
                }

                const saveRequests = clips.map(async (clip) => {
                    try {
                        const resp = await fetch(`${clippingBaseUrl}/${clip.id}/settings`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                            body: JSON.stringify({
                                subtitle_style: subtitleStyle,
                                subtitle_position: subtitlePosition
                            })
                        });
                        const data = await safeJson(resp);
                        return !!data.success;
                    } catch (e) {
                        return false;
                    }
                });

                const results = await Promise.all(saveRequests);
                const successCount = results.filter(Boolean).length;
                const failedCount = results.length - successCount;

                if (statusEl) {
                    if (failedCount === 0) {
                        statusEl.textContent = `✅ Đã lưu vị trí phụ đề cho ${successCount} clip.`;
                    } else {
                        statusEl.textContent = `⚠️ Đã lưu ${successCount}/${results.length} clip. ${failedCount} clip lỗi.`;
                    }
                }

                await loadClips();
            } catch (e) {
                if (statusEl) statusEl.textContent = `❌ Lỗi lưu setting: ${e.message}`;
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            }
        }

        function getFileNameFromPath(path) {
            if (!path) return '';
            const parts = String(path).split('/');
            return parts[parts.length - 1] || String(path);
        }

        async function loadClippingVideos() {
            const sel = document.getElementById('clippingSourceVideo');
            try {
                const resp = await fetch(`${clippingBaseUrl}/videos`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
                });
                const data = await safeJson(resp);
                sel.innerHTML = '';
                if (!data.videos || data.videos.length === 0) {
                    sel.innerHTML = '<option value="">-- Chưa có video nào --</option>';
                    return;
                }
                data.videos.forEach(v => {
                    const opt = document.createElement('option');
                    opt.value = v.field;
                    opt.textContent = `${v.label} (${v.duration_fmt})`;
                    sel.appendChild(opt);
                });
            } catch (e) {
                sel.innerHTML = `<option value="">-- Lỗi tải video: ${e.message} --</option>`;
            }
        }

        async function loadClippingBackgroundAudios() {
            const sel = document.getElementById('clippingBgAudio');
            const hintEl = document.getElementById('clippingBgAudioHint');
            if (!sel) return;

            const previousValue = sel.value;

            try {
                const resp = await fetch(`${clippingBaseUrl}/background-audios`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
                });
                const data = await safeJson(resp);
                const options = data.options || [];

                sel.innerHTML = '';

                const autoOpt = document.createElement('option');
                autoOpt.value = 'auto';
                autoOpt.textContent = '🤖 Tự động chọn (khuyên dùng)';
                sel.appendChild(autoOpt);

                const noneOpt = document.createElement('option');
                noneOpt.value = 'none';
                noneOpt.textContent = '🚫 Không dùng âm nền';
                sel.appendChild(noneOpt);

                options.forEach(item => {
                    const path = item.path || '';
                    if (!path) return;
                    const opt = document.createElement('option');
                    opt.value = path;

                    let prefix = '🎧';
                    if (item.type === 'intro') prefix = '🎵 Intro';
                    if (item.type === 'outro') prefix = '🎵 Outro';

                    const label = item.label || getFileNameFromPath(path);
                    opt.textContent = `${prefix}: ${label}`;
                    sel.appendChild(opt);
                });

                if (previousValue && Array.from(sel.options).some(opt => opt.value === previousValue)) {
                    sel.value = previousValue;
                } else {
                    sel.value = 'auto';
                }

                if (hintEl) {
                    const autoName = getFileNameFromPath(data.auto_selected_path || '');
                    if (options.length === 0) {
                        hintEl.textContent = 'Chưa có file âm nền trong intro/outro hoặc books/{id}/music. CTA vẫn tự chọn animation dọc gần 9:16 từ media/animations.';
                    } else if (autoName) {
                        hintEl.textContent = `Tự động đang ưu tiên: ${autoName}. CTA luôn tự chọn animation dọc gần 9:16 từ media/animations.`;
                    } else {
                        hintEl.textContent = 'Bạn có thể ép dùng 1 file âm nền cố định hoặc để Tự động. CTA luôn tự chọn animation dọc gần 9:16 từ media/animations.';
                    }
                }
            } catch (e) {
                sel.innerHTML = '';

                const autoOpt = document.createElement('option');
                autoOpt.value = 'auto';
                autoOpt.textContent = '🤖 Tự động chọn (khuyên dùng)';
                sel.appendChild(autoOpt);

                const noneOpt = document.createElement('option');
                noneOpt.value = 'none';
                noneOpt.textContent = '🚫 Không dùng âm nền';
                sel.appendChild(noneOpt);

                sel.value = 'auto';

                if (hintEl) {
                    hintEl.textContent = `Không tải được danh sách âm nền: ${e.message}. CTA vẫn tự chọn animation dọc gần 9:16 từ media/animations.`;
                }
            }
        }

        async function loadClippingCtaAnimations() {
            clippingCtaAnimations = [];
            clippingCtaAutoAnimationPath = null;

            try {
                const resp = await fetch(`${clippingBaseUrl}/cta-animations`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
                });
                const data = await safeJson(resp);
                clippingCtaAnimations = Array.isArray(data.options) ? data.options : [];
                clippingCtaAutoAnimationPath = data.auto_selected_path || null;
            } catch (e) {
                clippingCtaAnimations = [];
                clippingCtaAutoAnimationPath = null;
            }
        }

        function updateClippingBgVolumeLabel() {
            const input = document.getElementById('clippingBgVolume');
            const valueEl = document.getElementById('clippingBgVolumeValue');
            if (input) input.value = '3';
            if (valueEl) valueEl.textContent = '-30 dB (cố định)';
        }

        async function loadClips() {
            const listEl = document.getElementById('clippingList');
            try {
                const resp = await fetch(`${clippingBaseUrl}/clips`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
                });
                const data = await safeJson(resp);
                const clips = data.clips || [];
                clippingCachedClips = clips;
                if (clips.length === 0) {
                    listEl.innerHTML = '<p class="text-gray-400 text-sm text-center py-10">Chưa có clip nào. Chọn video nguồn và nhấn cắt clip.</p>';
                    return;
                }
                listEl.innerHTML = clips.map(renderClipCard).join('');
                // Bind lightbox for clipping thumbnails
                listEl.querySelectorAll('.clip-thumb-zoom').forEach(img => {
                    img.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const overlay = document.getElementById('segThumbOverlay');
                        if (!overlay) return;
                        const fullUrl = this.dataset.fullUrl || this.src;
                        overlay.querySelector('img').src = fullUrl;
                        overlay.classList.remove('hidden');
                    });
                });
                // Auto-start polling for any clips currently composing
                clips.forEach(clip => {
                    if (clip.status === 'composing') {
                        startComposePolling(clip.id);
                    }
                });
            } catch (e) {
                clippingCachedClips = [];
                listEl.innerHTML = `<p class="text-red-500 text-sm text-center py-6">❌ Lỗi tải clips: ${e.message}</p>`;
            }
        }

        function fmtTime(secs) {
            const h = Math.floor(secs / 3600);
            const m = Math.floor((secs % 3600) / 60);
            const s = Math.floor(secs % 60);
            return h > 0 ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}` : `${m}:${String(s).padStart(2,'0')}`;
        }

        function renderClipCard(clip) {
            const start = fmtTime(clip.start_time || 0);
            const dur = fmtTime(clip.duration || 60);
            const statusMap = {
                clipped: '<span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full">✂️ Đã cắt</span>',
                titled: '<span class="bg-yellow-100 text-yellow-700 text-xs px-2 py-0.5 rounded-full">✍️ Có tiêu đề</span>',
                imaged: '<span class="bg-purple-100 text-purple-700 text-xs px-2 py-0.5 rounded-full">🖼️ Có ảnh</span>',
                composing: '<span class="bg-orange-100 text-orange-700 text-xs px-2 py-0.5 rounded-full animate-pulse">⏳ Đang ghép...</span>',
                composed: '<span class="bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full">✅ Hoàn thành</span>',
            };
            const statusBadge = statusMap[clip.status] || '';
            const clipUrl = clip.clip_path ? `/storage/${clip.clip_path}` : null;
            const composedUrl = clip.composed_path ? `/storage/${clip.composed_path}` : null;
            const imageUrl = clip.image_path ? `/storage/${clip.image_path}` : null;
            const clipNum = (clip.id || '').split('_')[1] || '?';
            const ctaAnimationName = clip.cta_animation_path ? getFileNameFromPath(clip.cta_animation_path) : null;
            const bgAudioName = clip.bg_audio_path ? getFileNameFromPath(clip.bg_audio_path) : null;
            const bgVolumeNumber = Number(clip.bg_audio_volume);
            const bgVolumeText = Number.isFinite(bgVolumeNumber) ? ' (-30 dB)' : '';
            const subtitlePositionMap = {
                lower_third: 'Chuẩn (Y≈1450)',
                middle: 'Cao hơn (Y≈1400)',
                bottom: 'Thấp hơn (Y≈1550)'
            };
            const subtitleStyleMap = {
                highlight_green: 'Highlight xanh lá',
                highlight_yellow: 'Highlight vàng',
                highlight_red: 'Highlight đỏ',
                neon_blue: 'Neon xanh dương',
                boxed: 'Nền hộp đen',
                default: 'Mặc định (trắng)'
            };
            const currentSubtitlePosition = String(clip.subtitle_position || 'lower_third');
            const currentSubtitleStyle = String(clip.subtitle_style || 'highlight_green');
            const subtitlePositionLabel = subtitlePositionMap[currentSubtitlePosition] || 'Chuẩn (Y≈1450)';
            const subtitleStyleLabel = subtitleStyleMap[currentSubtitleStyle] || 'Highlight xanh lá';
            const selectedCtaMode = String(clip.cta_animation_mode || 'auto').toLowerCase();
            const selectedCtaPath = clip.cta_animation_selected_path || '';

            const animationOptions = [
                `<option value="auto" ${selectedCtaMode !== 'custom' ? 'selected' : ''}>🤖 Tự động chọn gần 9:16</option>`
            ];

            clippingCtaAnimations.forEach(animation => {
                const path = animation.path || '';
                if (!path) return;
                const encodedPath = encodeURIComponent(path);
                const isSelected = selectedCtaMode === 'custom' && selectedCtaPath === path;
                const label = animation.label || getFileNameFromPath(path);
                const sourcePrefix = animation.source === 'media_library' ? '📚' : '🎞️';
                const sizeText = (animation.width && animation.height) ? ` ${animation.width}x${animation.height}` : '';
                animationOptions.push(`<option value="${encodedPath}" ${isSelected ? 'selected' : ''}>${sourcePrefix} ${label}${sizeText}</option>`);
            });

            const selectedManualName = selectedCtaPath ? getFileNameFromPath(selectedCtaPath) : null;
            const autoAnimationName = clippingCtaAutoAnimationPath ? getFileNameFromPath(clippingCtaAutoAnimationPath) : null;
            const clipImageProvider = String(clip.image_provider || document.getElementById('clippingImageProvider')?.value || 'gemini').toLowerCase() === 'flux' ? 'flux' : 'gemini';

            return `<div class="border border-gray-200 rounded-xl p-4 mb-4 bg-white shadow-sm" id="clip-card-${clip.id}">
                <div class="flex items-start justify-between gap-3 mb-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap mb-1">
                            <span class="font-semibold text-gray-800 text-sm">Clip #${clipNum}</span>
                            ${statusBadge}
                            <span class="text-xs text-gray-400">${start} → ${fmtTime((clip.start_time||0)+(clip.duration||60))} (${dur})</span>
                        </div>
                        ${clip.hook_title ? `<p class="text-sm font-medium text-gray-700 mb-1">🎯 ${clip.hook_title}</p>` : ''}
                        ${clip.cta ? `<p class="text-xs text-gray-500 italic">${clip.cta}</p>` : ''}
                        ${ctaAnimationName ? `<p class="text-[11px] text-gray-500">🎞️ CTA animation: ${ctaAnimationName}</p>` : ''}
                        ${selectedManualName ? `<p class="text-[11px] text-indigo-600">🧷 Đã ghim CTA: ${selectedManualName}</p>` : ''}
                        ${bgAudioName ? `<p class="text-[11px] text-gray-500">🎵 Âm nền: ${bgAudioName}${bgVolumeText}</p>` : ''}
                        <p class="text-[11px] text-gray-500">📍 Phụ đề: ${subtitlePositionLabel} • ${subtitleStyleLabel}</p>
                    </div>
                    <button type="button" onclick="deleteClip('${clip.id}')"
                        class="text-red-400 hover:text-red-600 text-lg flex-shrink-0" title="Xóa clip">🗑️</button>
                </div>
                <div class="mb-3 p-2 border border-indigo-100 rounded bg-indigo-50/40">
                    <label class="block text-[11px] font-medium text-indigo-700 mb-1">CTA animation cho clip này</label>
                    <div class="flex items-center gap-2 flex-wrap">
                        <select id="clip-cta-animation-${clip.id}"
                            onchange="saveClipCtaAnimation('${clip.id}')"
                            class="min-w-[280px] border border-indigo-200 rounded px-2 py-1 text-xs text-gray-700 focus:ring-2 focus:ring-indigo-400">
                            ${animationOptions.join('')}
                        </select>
                        <span class="text-[11px] text-indigo-500">${autoAnimationName ? `Auto hiện tại: ${autoAnimationName}` : 'Chưa tìm thấy animation dọc 9:16'}</span>
                    </div>
                </div>
                ${imageUrl ? `<div class=\"mb-3\">\n                    <div class=\"relative inline-block group\">\n                        <img src=\"${imageUrl}\" data-full-url=\"${imageUrl}\" class=\"w-24 h-auto rounded border cursor-zoom-in clip-thumb-zoom\" alt=\"thumbnail\">\n                        <div class=\"absolute inset-0 hidden group-hover:flex items-center justify-center bg-black/55 rounded transition\">\n                            <button type=\"button\" onclick=\"animateClipImageSeedance('${clip.id}')\"\n                                class=\"text-[10px] bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-1 rounded-md shadow\">\n                                🌫️ Tạo motion nền (Seedance)\n                            </button>\n                        </div>\n                    </div>\n                    ${clip.image_animation_url ? `<a href=\"${clip.image_animation_url}\" target=\"_blank\" class=\"block text-[11px] text-indigo-600 hover:text-indigo-700 mt-1\">▶️ Xem motion background</a>` : ''}\n                </div>` : ''}
                <div class="flex items-center gap-2 mb-2">
                    <label for="clip-image-provider-${clip.id}" class="text-[11px] text-gray-600">Image Provider:</label>
                    <select id="clip-image-provider-${clip.id}"
                        class="border border-purple-200 rounded px-2 py-1 text-xs text-gray-700 focus:ring-2 focus:ring-purple-400">
                        <option value="gemini" ${clipImageProvider === 'gemini' ? 'selected' : ''}>Gemini</option>
                        <option value="flux" ${clipImageProvider === 'flux' ? 'selected' : ''}>Flux (AIML)</option>
                    </select>
                </div>
                <div class="flex flex-wrap gap-2">
                    ${clipUrl ? `<a href="${clipUrl}" target="_blank" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg transition">▶️ Xem clip gốc</a>` : ''}
                    <button type="button" onclick="generateClipTitle('${clip.id}')"
                        class="text-xs bg-yellow-100 hover:bg-yellow-200 text-yellow-800 px-3 py-1.5 rounded-lg transition">✍️ Tạo tiêu đề Hook</button>
                    <button type="button" onclick="generateClipImage('${clip.id}')"
                        class="text-xs bg-purple-100 hover:bg-purple-200 text-purple-800 px-3 py-1.5 rounded-lg transition">🖼️ Tạo ảnh minh họa</button>
                    <button type="button" onclick="composeClip('${clip.id}')"
                        class="text-xs bg-green-100 hover:bg-green-200 text-green-800 px-3 py-1.5 rounded-lg transition">🎬 Ghép video</button>
                    ${composedUrl ? `<a href="${composedUrl}" target="_blank" class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg transition">⬇️ Tải video final</a>` : ''}
                </div>
                <div id="clip-status-${clip.id}" class="text-xs text-gray-500 mt-2"></div>
            </div>`;
        }

        async function saveClipCtaAnimation(clipId) {
            const selectEl = document.getElementById('clip-cta-animation-' + clipId);
            const statusEl = document.getElementById('clip-status-' + clipId);
            if (!selectEl || !statusEl) return;

            const selectedValue = selectEl.value || 'auto';
            const payload = {
                cta_animation_mode: selectedValue === 'auto' ? 'auto' : 'custom',
            };

            if (payload.cta_animation_mode === 'custom') {
                payload.cta_animation_path = decodeURIComponent(selectedValue);
            }

            statusEl.textContent = '⏳ Đang lưu lựa chọn CTA animation...';

            try {
                const resp = await fetch(`${clippingBaseUrl}/${clipId}/settings`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                    body: JSON.stringify(payload)
                });
                const data = await safeJson(resp);

                const selectedPath = data?.clip?.cta_animation_selected_path || payload.cta_animation_path || null;
                const selectedName = selectedPath ? getFileNameFromPath(selectedPath) : 'Tự động';
                statusEl.textContent = `✅ Đã lưu CTA animation: ${selectedName}`;
                await loadClips();
            } catch (e) {
                statusEl.textContent = '❌ Lỗi lưu CTA animation: ' + e.message;
                await loadClips();
            }
        }

        async function generateClips() {
            const source = document.getElementById('clippingSourceVideo').value;
            const count = parseInt(document.getElementById('clippingCount').value) || 3;
            if (!source) { alert('Vui lòng chọn video nguồn!'); return; }
            const btn = document.getElementById('generateClipsBtn');
            const status = document.getElementById('generateClipsStatus');
            btn.disabled = true;
            btn.textContent = '⏳ Đang cắt clip...';
            status.textContent = 'Đang xử lý, vui lòng chờ...';
            try {
                const resp = await fetch(`${clippingBaseUrl}/generate`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                    body: JSON.stringify({ source_field: source, count: count })
                });
                const data = await safeJson(resp);
                status.textContent = '✅ Đã tạo ' + (data.clips?.length || 0) + ' clip!';
                await loadClips();
            } catch (e) {
                status.textContent = '❌ Lỗi: ' + e.message;
            } finally {
                btn.disabled = false;
                btn.textContent = '✂️ Cắt clip ngẫu nhiên (~60s)';
            }
        }

        async function generateClipTitle(clipId) {
            const statusEl = document.getElementById('clip-status-' + clipId);
            statusEl.textContent = '⏳ Đang đưa vào hàng đợi tạo tiêu đề...';
            try {
                const resp = await fetch(`${clippingBaseUrl}/${clipId}/generate-title`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                    body: JSON.stringify({})
                });
                const data = await safeJson(resp);
                if (data.queued) {
                    startClipTitlePolling(clipId);
                } else if (data.success) {
                    statusEl.textContent = '✅ Tiêu đề: ' + (data.hook_title || 'đã tạo');
                    await loadClips();
                } else {
                    throw new Error(data.error || 'Không thể tạo tiêu đề');
                }
            } catch (e) {
                statusEl.textContent = '❌ Lỗi: ' + e.message;
            }
        }

        function startClipTitlePolling(clipId) {
            const statusEl = document.getElementById('clip-status-' + clipId);
            const poll = setInterval(async () => {
                try {
                    const r = await fetch(`${clippingBaseUrl}/${clipId}/generate-title-progress`, { headers: { 'Accept': 'application/json' } });
                    const d = await r.json();
                    if (!d) return;
                    if (d.status === 'completed') {
                        clearInterval(poll);
                        const title = d?.result?.hook_title || 'đã tạo';
                        statusEl.textContent = '✅ Tiêu đề: ' + title;
                        await loadClips();
                    } else if (d.status === 'error') {
                        clearInterval(poll);
                        statusEl.innerHTML = `<span class="text-red-600">❌ ${d.message || 'Lỗi tạo tiêu đề'}</span>`;
                    } else {
                        const pct = d.percent || 0;
                        statusEl.innerHTML = `<span class="text-orange-600 animate-pulse">⏳ ${d.message || 'Đang xử lý...'} (${pct}%)</span>`;
                    }
                } catch (e) {
                    // ignore
                }
            }, 3000);
        }

        async function generateClipImage(clipId) {
            const statusEl = document.getElementById('clip-status-' + clipId);
            const perClipImageProvider = document.getElementById(`clip-image-provider-${clipId}`)?.value;
            const imageProvider = perClipImageProvider || document.getElementById('clippingImageProvider')?.value || 'gemini';
            const providerLabel = imageProvider === 'flux' ? 'Flux' : 'Gemini';
            statusEl.textContent = `⏳ Đang đưa vào hàng đợi tạo ảnh (${providerLabel})...`;
            try {
                const resp = await fetch(`${clippingBaseUrl}/${clipId}/generate-image`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                    body: JSON.stringify({ image_provider: imageProvider })
                });
                const data = await safeJson(resp);
                if (data.queued) {
                    statusEl.innerHTML = '<span class="text-orange-600 animate-pulse">⏳ Đã đưa vào hàng đợi. Bạn có thể tắt trình duyệt.</span>';
                    startClipImagePolling(clipId);
                } else if (data.success) {
                    statusEl.textContent = '✅ Đã tạo ảnh!';
                    await loadClips();
                } else {
                    throw new Error(data.error || 'Không thể tạo ảnh');
                }
            } catch (e) {
                statusEl.textContent = '❌ Lỗi: ' + e.message;
            }
        }

        function startClipImagePolling(clipId) {
            const statusEl = document.getElementById('clip-status-' + clipId);
            const poll = setInterval(async () => {
                try {
                    const r = await fetch(`${clippingBaseUrl}/${clipId}/generate-image-progress`, { headers: { 'Accept': 'application/json' } });
                    const d = await r.json();
                    if (!d) return;
                    if (d.status === 'completed') {
                        clearInterval(poll);
                        statusEl.innerHTML = '<span class="text-green-600">✅ Đã tạo ảnh!</span>';
                        await loadClips();
                    } else if (d.status === 'error') {
                        clearInterval(poll);
                        statusEl.innerHTML = `<span class="text-red-600">❌ ${d.message || 'Lỗi tạo ảnh'}</span>`;
                    } else {
                        const pct = d.percent || 0;
                        statusEl.innerHTML = `<span class="text-orange-600 animate-pulse">⏳ ${d.message || 'Đang xử lý...'} (${pct}%)</span>`;
                    }
                } catch (e) {
                    // ignore transient errors
                }
            }, 3000);
        }

        function startClipImageSeedancePolling(clipId) {
            const statusEl = document.getElementById('clip-status-' + clipId);
            const poll = setInterval(async () => {
                try {
                    const r = await fetch(`${clippingBaseUrl}/${clipId}/animate-image-progress`, {
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
                    });
                    const d = await r.json();
                    if (!d) return;

                    if (d.status === 'completed') {
                        clearInterval(poll);
                        if (statusEl) {
                            statusEl.innerHTML = '<span class="text-green-600">✅ Đã tạo motion background bằng Seedance.</span>';
                        }
                        await loadClips();
                    } else if (d.status === 'error') {
                        clearInterval(poll);
                        if (statusEl) {
                            statusEl.innerHTML = `<span class="text-red-600">❌ ${d.message || 'Lỗi tạo motion background'}</span>`;
                        }
                        await loadClips();
                    } else {
                        const pct = d.percent || 0;
                        if (statusEl) {
                            statusEl.innerHTML = `<span class="text-indigo-600 animate-pulse">🌫️ ${d.message || 'Seedance đang xử lý...'} (${pct}%)</span>`;
                        }
                    }
                } catch (e) {
                    // ignore transient polling failures
                }
            }, 4000);
        }

        async function animateClipImageSeedance(clipId) {
            const statusEl = document.getElementById('clip-status-' + clipId);
            if (statusEl) {
                statusEl.innerHTML = '<span class="text-indigo-600 animate-pulse">🌫️ Đang gửi Seedance tạo motion nền...</span>';
            }

            try {
                const resp = await fetch(`${clippingBaseUrl}/${clipId}/animate-image`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken()
                    },
                    body: JSON.stringify({
                        duration: 5,
                        prompt: 'Animate only subtle background motion and gentle depth movement. Keep subject stable, no action scene, no character movement, cinematic ambient motion for audiobook.'
                    })
                });

                const data = await safeJson(resp);
                if (!data.success) {
                    throw new Error(data.error || 'Không thể khởi tạo Seedance');
                }

                if (statusEl) {
                    statusEl.innerHTML = '<span class="text-indigo-600 animate-pulse">🌫️ Đã gửi Seedance, đang tạo motion background...</span>';
                }
                startClipImageSeedancePolling(clipId);
            } catch (e) {
                if (statusEl) {
                    statusEl.innerHTML = `<span class="text-red-600">❌ ${e.message}</span>`;
                }
            }
        }

        // Track active compose polls to auto-poll when page loads
        let activeComposePolls = {};

        async function composeClip(clipId) {
            const statusEl = document.getElementById('clip-status-' + clipId);
            const bgSelect = document.getElementById('clippingBgAudio');
            const ctaAnimationSelect = document.getElementById('clip-cta-animation-' + clipId);
            const subtitleStyleSelect = document.getElementById('clippingSubtitleStyle');
            const subtitlePositionSelect = document.getElementById('clippingSubtitlePosition');
            const fixedBgVolumeLinear = 0.0316; // -30 dB

            const selectedBg = bgSelect ? bgSelect.value : 'auto';
            const selectedCtaAnimation = ctaAnimationSelect ? ctaAnimationSelect.value : 'auto';
            const selectedSubtitleStyle = subtitleStyleSelect ? subtitleStyleSelect.value : 'highlight_green';
            const selectedSubtitlePosition = subtitlePositionSelect ? subtitlePositionSelect.value : 'lower_third';

            const payload = {
                background_audio_mode: selectedBg === 'none' ? 'none' : (selectedBg && selectedBg !== 'auto' ? 'custom' : 'auto'),
                background_audio_volume: fixedBgVolumeLinear,
                subtitle_style: selectedSubtitleStyle,
                subtitle_position: selectedSubtitlePosition
            };

            if (payload.background_audio_mode === 'custom') {
                payload.background_audio_path = selectedBg;
            }

            payload.cta_animation_mode = selectedCtaAnimation === 'auto' ? 'auto' : 'custom';
            if (payload.cta_animation_mode === 'custom') {
                payload.cta_animation_path = decodeURIComponent(selectedCtaAnimation);
            }

            statusEl.innerHTML = '<span class="text-orange-600 animate-pulse">⏳ Đang đưa vào hàng đợi xử lý nền...</span>';
            try {
                const resp = await fetch(`${clippingBaseUrl}/${clipId}/compose`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                    body: JSON.stringify(payload)
                });
                const data = await safeJson(resp);

                if (data.queued) {
                    statusEl.innerHTML = '<span class="text-orange-600 animate-pulse">⏳ Đã đưa vào hàng đợi. Bạn có thể tắt trình duyệt, video sẽ tự hoàn thành.</span>';
                    startComposePolling(clipId);
                    await loadClips();
                } else {
                    // Direct response (fallback if sync)
                    const bgName = data.bg_audio_path ? getFileNameFromPath(data.bg_audio_path) : 'không dùng';
                    const ctaAnimationName = data.cta_animation_path ? getFileNameFromPath(data.cta_animation_path) : 'không có';
                    statusEl.textContent = `✅ Đã ghép video hoàn chỉnh! (Âm nền: ${bgName}, CTA animation: ${ctaAnimationName})`;
                    await loadClips();
                }
            } catch (e) {
                statusEl.textContent = '❌ Lỗi: ' + e.message;
            }
        }

        function startComposePolling(clipId) {
            // Don't start duplicate polls
            if (activeComposePolls[clipId]) return;

            const pollInterval = setInterval(async () => {
                try {
                    const resp = await fetch(`${clippingBaseUrl}/${clipId}/compose-progress`, {
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
                    });
                    const data = await resp.json();

                    const statusEl = document.getElementById('clip-status-' + clipId);
                    if (!statusEl) {
                        // Card no longer in DOM, stop polling
                        clearInterval(pollInterval);
                        delete activeComposePolls[clipId];
                        return;
                    }

                    const percent = data.percent || 0;
                    const message = data.message || '';
                    const status = data.status || 'processing';

                    if (status === 'completed') {
                        clearInterval(pollInterval);
                        delete activeComposePolls[clipId];
                        statusEl.innerHTML = `<span class="text-green-600">✅ ${message}</span>`;
                        await loadClips();
                    } else if (status === 'error') {
                        clearInterval(pollInterval);
                        delete activeComposePolls[clipId];
                        statusEl.innerHTML = `<span class="text-red-600">❌ ${message}</span>`;
                        await loadClips();
                    } else {
                        // Still processing
                        const progressBar = percent > 0 ? `<div class="w-full bg-gray-200 rounded-full h-2 mt-1"><div class="bg-orange-500 h-2 rounded-full transition-all" style="width:${percent}%"></div></div>` : '';
                        statusEl.innerHTML = `<span class="text-orange-600 animate-pulse">⏳ ${message} (${percent}%)</span>${progressBar}<span class="text-[11px] text-gray-400 block mt-1">💡 Có thể tắt trình duyệt, video sẽ tự hoàn thành.</span>`;
                    }
                } catch (e) {
                    // Network error, keep polling
                }
            }, 4000); // Poll every 4 seconds

            activeComposePolls[clipId] = pollInterval;
        }

        function stopAllComposePolling() {
            for (const clipId in activeComposePolls) {
                clearInterval(activeComposePolls[clipId]);
            }
            activeComposePolls = {};
        }

        // Auto-start polling for clips that are currently composing after page load / tab switch
        function checkAndPollComposingClips() {
            const cards = document.querySelectorAll('[id^="clip-card-"]');
            cards.forEach(card => {
                const statusBadge = card.querySelector('.animate-pulse');
                if (statusBadge && statusBadge.textContent.includes('Đang ghép')) {
                    const clipId = card.id.replace('clip-card-', '');
                    startComposePolling(clipId);
                }
            });
        }

        async function deleteClip(clipId) {
            if (!confirm('Xóa clip này?')) return;
            const statusEl = document.getElementById('clip-status-' + clipId);
            if (statusEl) statusEl.textContent = '⏳ Đang xóa...';
            try {
                const resp = await fetch(`${clippingBaseUrl}/${clipId}`, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
                });
                await safeJson(resp);
                await loadClips();
            } catch (e) {
                if (statusEl) statusEl.textContent = '❌ Lỗi: ' + e.message;
            }
        }

         // Close modal when clicking backdrop
         document.getElementById('findReplaceModal').addEventListener('click', function(e) {
             if (e.target === this) closeFindReplaceModal();
         });
     </script>

 @endsection
