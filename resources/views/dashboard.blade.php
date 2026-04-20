<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dashboard - Sumotech</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Pacifico&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css">
    <style>
        :where([class^="ri-"])::before {
            content: "\f3c2";
        }
    </style>
</head>

<body class="bg-white">
    <nav class="bg-white shadow-sm fixed w-full top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <img src="{{ asset('img/logo/Sumotech_round.png') }}" alt="Sumotech" class="h-12 w-auto" />
                </div>
                <div class="hidden md:block">
                    <div class="ml-10 flex items-baseline space-x-8">
                        <a href="{{ route('dashboard') }}"
                            class="text-primary px-3 py-2 text-sm font-medium transition-colors border-b-2 border-primary">
                            {{ __('Dashboard') }}
                        </a>
                        <div class="relative group">
                            <button
                                class="text-gray-600 hover:text-primary px-3 py-2 text-sm font-medium transition-colors flex items-center gap-1">
                                {{ __('Tools') }}
                                <i class="ri-arrow-down-s-line text-xs"></i>
                            </button>
                            <div
                                class="hidden group-hover:block absolute left-0 top-full mt-0 w-48 bg-white rounded-lg shadow-lg border border-gray-200 z-50">
                                <a href="{{ route('projects.index') }}"
                                    class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary transition-colors rounded-t-lg">
                                    <i class="ri-film-line mr-2"></i>DubSync
                                </a>
                                <a href="{{ route('youtube-channels.index') }}"
                                    class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary transition-colors">
                                    <i class="ri-youtube-line mr-2"></i>YouTube Channels
                                </a>
                                <a href="{{ route('api-usage.index') }}"
                                    class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary transition-colors">
                                    <i class="ri-bar-chart-line mr-2"></i>API Usage
                                </a>
                                <a href="{{ route('coqui.tts.index') }}"
                                    class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary transition-colors rounded-b-lg">
                                    <i class="ri-mic-line mr-2"></i>Coqui TTS
                                </a>
                            </div>
                        </div>
                        <div class="relative">
                            <button id="user-menu-button"
                                class="flex items-center gap-2 text-gray-600 hover:text-primary px-3 py-2 text-sm font-medium transition-colors">
                                <div class="w-4 h-4 flex items-center justify-center">
                                    <i class="ri-user-line"></i>
                                </div>
                                <span>{{ Auth::user()?->name ?? 'Guest' }}</span>
                                <div class="w-3 h-3 flex items-center justify-center">
                                    <i class="ri-arrow-down-s-line text-xs"></i>
                                </div>
                            </button>
                            <div id="user-dropdown"
                                class="hidden absolute right-0 top-full mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 z-50">
                                <a href="{{ route('profile.edit') }}"
                                    class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary transition-colors rounded-t-lg">
                                    {{ __('Profile') }}
                                </a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit"
                                        class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary transition-colors rounded-b-lg">
                                        {{ __('Log Out') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="md:hidden">
                    <button id="mobile-menu-button" class="text-gray-600 hover:text-primary p-2">
                        <div class="w-6 h-6 flex items-center justify-center">
                            <i class="ri-menu-line ri-lg"></i>
                        </div>
                    </button>
                </div>
            </div>
        </div>
        <div id="mobile-menu" class="md:hidden hidden bg-white border-t">
            <div class="px-2 pt-2 pb-3 space-y-1">
                <a href="{{ route('dashboard') }}"
                    class="text-primary block px-3 py-2 text-base font-medium">{{ __('Dashboard') }}</a>
                <div class="text-gray-600 hover:text-primary block px-3 py-2 text-base font-medium">
                    {{ __('Tools') }}
                </div>
                <a href="{{ route('projects.index') }}"
                    class="text-gray-600 hover:text-primary block px-4 py-2 text-base font-medium border-l-4 border-transparent hover:border-primary ml-0">
                    <i class="ri-film-line mr-2"></i>DubSync
                </a>
                <a href="{{ route('youtube-channels.index') }}"
                    class="text-gray-600 hover:text-primary block px-4 py-2 text-base font-medium border-l-4 border-transparent hover:border-primary ml-0">
                    <i class="ri-youtube-line mr-2"></i>YouTube Channels
                </a>
                <a href="{{ route('api-usage.index') }}"
                    class="text-gray-600 hover:text-primary block px-4 py-2 text-base font-medium border-l-4 border-transparent hover:border-primary ml-0">
                    <i class="ri-bar-chart-line mr-2"></i>API Usage
                </a>
                <a href="{{ route('coqui.tts.index') }}"
                    class="text-gray-600 hover:text-primary block px-4 py-2 text-base font-medium border-l-4 border-transparent hover:border-primary ml-0">
                    <i class="ri-mic-line mr-2"></i>Coqui TTS
                </a>
                <div class="border-t border-gray-200 mt-4 pt-4">
                    <div class="px-3 py-2">
                        <p class="text-sm font-medium text-gray-900 mb-2">{{ Auth::user()?->name ?? 'Guest User' }}</p>
                        <div class="space-y-1">
                            <a href="{{ route('profile.edit') }}"
                                class="block w-full text-left px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-gray-50 rounded-md transition-colors">
                                {{ __('Profile') }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                    class="block w-full text-left px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-gray-50 rounded-md transition-colors">
                                    {{ __('Log Out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <section class="pt-16 min-h-screen bg-gradient-to-br from-green-50 via-white to-emerald-50">
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
            <div class="w-full">
                <div class="max-w-3xl mb-12">
                    <h1 class="text-4xl md:text-5xl font-bold text-gray-900 leading-tight mb-4">
                        {{ __('Welcome back') }}, <span
                            class="text-primary">{{ Auth::user()?->name ?? 'User' }}</span>!
                    </h1>
                    <p class="text-xl text-gray-600 mb-4">
                        {{ __("You're logged in!") }}
                    </p>
                    <p class="text-lg text-gray-600">
                        {{ __('Role') }}: <span
                            class="font-semibold text-primary">{{ Auth::user()?->role ?? 'Guest' }}</span>
                    </p>
                </div>



                <div class="bg-white rounded-2xl p-8 shadow-sm">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">{{ __('Quick Actions') }}</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <a href="{{ route('projects.index') }}"
                            class="flex items-center gap-3 p-4 border-2 border-gray-200 rounded-xl hover:border-primary hover:bg-primary/5 transition-all">
                            <div class="w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center">
                                <i class="ri-film-line text-primary"></i>
                            </div>
                            <span class="font-medium text-gray-700">{{ __('DubSync') }}</span>
                        </a>
                        <a href="{{ route('youtube-channels.index') }}"
                            class="flex items-center gap-3 p-4 border-2 border-gray-200 rounded-xl hover:border-primary hover:bg-primary/5 transition-all">
                            <div class="w-10 h-10 bg-red-50 rounded-lg flex items-center justify-center">
                                <i class="ri-youtube-line text-red-600"></i>
                            </div>
                            <span class="font-medium text-gray-700">{{ __('YouTube Channels') }}</span>
                        </a>
                        <a href="{{ route('media-center.index') }}"
                            class="flex items-center gap-3 p-4 border-2 border-gray-200 rounded-xl hover:border-primary hover:bg-primary/5 transition-all">
                            <div class="w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center">
                                <i class="ri-movie-2-line text-primary"></i>
                            </div>
                            <span class="font-medium text-gray-700">{{ __('Media Center') }}</span>
                        </a>
                        <a href="{{ route('profile.edit') }}"
                            class="flex items-center gap-3 p-4 border-2 border-gray-200 rounded-xl hover:border-primary hover:bg-primary/5 transition-all">
                            <div class="w-10 h-10 bg-secondary/10 rounded-lg flex items-center justify-center">
                                <i class="ri-question-line text-secondary"></i>
                            </div>
                            <span class="font-medium text-gray-700">{{ __('Help') }}</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const userMenuButton = document.getElementById('user-menu-button');
            const userDropdown = document.getElementById('user-dropdown');
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');

            if (userMenuButton && userDropdown) {
                userMenuButton.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('hidden');
                });

                document.addEventListener('click', function(e) {
                    if (!userMenuButton.contains(e.target) && !userDropdown.contains(e.target)) {
                        userDropdown.classList.add('hidden');
                    }
                });
            }

            if (mobileMenuButton && mobileMenu) {
                mobileMenuButton.addEventListener('click', function() {
                    mobileMenu.classList.toggle('hidden');
                });
            }
        });
    </script>
</body>

</html>
