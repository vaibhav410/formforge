<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FormForge — AI-Powered Form Builder</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased bg-gradient-to-b from-indigo-50 via-white to-gray-50 min-h-screen">
    <header class="max-w-6xl mx-auto px-6 py-6 flex items-center justify-between">
        <x-application-logo />
        <nav class="flex items-center gap-3">
            @auth
                <a href="{{ route('dashboard') }}" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-500">Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Log in</a>
                <a href="{{ route('register') }}" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-500">Get started</a>
            @endauth
        </nav>
    </header>

    <main class="max-w-6xl mx-auto px-6">
        <section class="text-center pt-16 pb-14">
            <p class="inline-block text-xs font-semibold text-indigo-600 bg-indigo-50 border border-indigo-100 rounded-full px-3 py-1 mb-5">
                ✨ Powered by AI — describe it, and it's built
            </p>
            <h1 class="text-4xl sm:text-5xl font-bold text-gray-900 tracking-tight leading-tight">
                Build forms in seconds,<br class="hidden sm:block"> not hours
            </h1>
            <p class="mt-5 text-lg text-gray-500 max-w-2xl mx-auto">
                Drag-and-drop builder, AI generation from a plain sentence, Word &amp; Excel import,
                conditional logic, versioning and completion analytics — all driven by one JSON schema.
            </p>
            <div class="mt-8 flex items-center justify-center gap-3">
                <a href="{{ route('register') }}" class="px-6 py-3 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-500 shadow-sm">
                    Start building — it's free
                </a>
                <a href="{{ route('login') }}" class="px-6 py-3 text-sm font-semibold text-indigo-700 bg-white border border-indigo-200 rounded-lg hover:bg-indigo-50">
                    Log in
                </a>
            </div>
        </section>

        <section class="grid sm:grid-cols-3 gap-5 pb-20">
            @foreach ([
                ['✨', 'Generate with AI', 'Type "internship application with resume upload" and get a complete, editable form — validations included.'],
                ['📄', 'Import documents', 'Upload a Word questionnaire or an Excel sheet; headings become sections, questions become fields.'],
                ['📊', 'Understand drop-off', 'Views, starts, completion rate and the exact field where people abandon your form.'],
            ] as [$icon, $title, $text])
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 text-left">
                    <div class="text-2xl">{{ $icon }}</div>
                    <h3 class="mt-3 font-semibold text-gray-800">{{ $title }}</h3>
                    <p class="mt-1.5 text-sm text-gray-500">{{ $text }}</p>
                </div>
            @endforeach
        </section>
    </main>

    <footer class="py-8 text-center text-xs text-gray-400">
        FormForge — Laravel 11 · Livewire 3 · FastAPI · Groq
    </footer>
</body>
</html>
