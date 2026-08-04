@extends('public.layout')

@section('title', 'Thank you')

@section('content')
    <div class="max-w-lg mx-auto px-4 py-24 text-center">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 px-8 py-12">
            <div class="mx-auto w-14 h-14 rounded-full bg-green-100 flex items-center justify-center">
                <svg class="w-7 h-7 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h1 class="mt-4 text-xl font-semibold text-gray-800">{{ $message }}</h1>
            <a href="{{ route('forms.public.show', $form) }}" class="mt-6 inline-block text-sm text-indigo-600 hover:underline">
                Submit another response
            </a>
        </div>
    </div>
@endsection
