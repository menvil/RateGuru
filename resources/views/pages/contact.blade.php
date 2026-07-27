@extends('layouts.app')

@section('title', $title)

@section('content')
    <article
        class="mx-auto max-w-[820px]"
        data-testid="contact-page"
        data-page="{{ $pageKey }}"
    >
        <p class="text-sm font-medium uppercase tracking-normal text-rg-accent2">
            {{ $siteName }}
        </p>

        <h1 class="mt-3 text-3xl font-semibold text-rg-text sm:text-4xl">
            {{ $title }}
        </h1>

        <x-ui.card variant="panel" class="mt-6">
            <p class="whitespace-pre-line text-sm leading-7 text-rg-text2 sm:text-base">
                {{ $content }}
            </p>

            @if(session('contact_status'))
                <div
                    class="mt-5 rounded-rgControl border border-rg-goodBorder bg-rg-goodSoft px-4 py-3 text-sm font-medium text-rg-good"
                    role="status"
                    data-testid="contact-success"
                >
                    {{ session('contact_status') }}
                </div>
            @endif

            <form
                method="POST"
                action="{{ route('pages.contact.submit') }}"
                class="mt-6 space-y-5"
                data-testid="contact-form"
            >
                @csrf

                <div class="space-y-2">
                    <label for="contact-name" class="text-sm font-medium text-rg-text">
                        {{ __('ui.contact.name') }}
                    </label>
                    <x-ui.input
                        id="contact-name"
                        name="name"
                        :value="old('name')"
                        :error="$errors->has('name')"
                        autocomplete="name"
                        required
                    />
                    @error('name')
                        <p class="text-sm text-rg-dangerText">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label for="contact-email" class="text-sm font-medium text-rg-text">
                        {{ __('ui.contact.email') }}
                    </label>
                    <x-ui.input
                        id="contact-email"
                        name="email"
                        type="email"
                        :value="old('email')"
                        :error="$errors->has('email')"
                        autocomplete="email"
                        required
                    />
                    @error('email')
                        <p class="text-sm text-rg-dangerText">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label for="contact-subject" class="text-sm font-medium text-rg-text">
                        {{ __('ui.contact.subject') }}
                    </label>
                    <x-ui.input
                        id="contact-subject"
                        name="subject"
                        :value="old('subject')"
                        :error="$errors->has('subject')"
                        required
                    />
                    @error('subject')
                        <p class="text-sm text-rg-dangerText">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label for="contact-message" class="text-sm font-medium text-rg-text">
                        {{ __('ui.contact.message') }}
                    </label>
                    <x-ui.textarea
                        id="contact-message"
                        name="message"
                        rows="7"
                        :error="$errors->has('message')"
                        required
                    >{{ old('message') }}</x-ui.textarea>
                    @error('message')
                        <p class="text-sm text-rg-dangerText">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end">
                    <x-ui.button type="submit" size="lg" data-testid="contact-submit">
                        {{ __('ui.contact.submit') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </article>
@endsection
