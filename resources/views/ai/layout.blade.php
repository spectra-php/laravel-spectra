<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Spectra AI Testing</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen flex">

    <!-- Sidebar -->
    <aside class="w-56 bg-gray-900 border-r border-gray-800 min-h-screen p-4 flex flex-col gap-6">
        <a href="{{ route('spectra.dashboard') }}" class="text-lg font-bold tracking-tight text-white hover:text-blue-400 transition">
            &larr; Spectra
        </a>

        <nav class="flex flex-col gap-1">
            <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Providers</span>
            <a href="{{ url(config('spectra.dashboard.path', 'spectra') . '/ai/openai') }}"
               class="px-3 py-2 rounded-md text-sm font-medium transition
                      {{ request()->is('*/ai/openai*') ? 'bg-blue-600 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white' }}">
                OpenAI
            </a>
        </nav>
    </aside>

    <!-- Main content -->
    <main class="flex-1 p-8 overflow-y-auto">
        @yield('content')
    </main>

</body>
</html>
