<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">How languages work</x-slot>

        <div class="space-y-2 text-sm">
            <p><strong>Installed</strong> languages come with the release. <strong>Enabled</strong> ones are offered to visitors; a disabled language stays installed, and its content can still be translated in the existing editors before it is offered.</p>
            <p><strong>Application</strong> is the release's own text and has to be complete before a language can be enabled. <strong>Project content</strong> is this project's settings, static pages, categories, rating groups and options, and tags as they are in the database now; where a translation is missing, visitors see the fallback text.</p>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
