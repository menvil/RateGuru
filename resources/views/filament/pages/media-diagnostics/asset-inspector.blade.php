@php
    $asset = \App\Models\MediaAsset::withTrashed()->find($assetId);
@endphp

@if ($asset === null)
    <p class="text-sm text-gray-500">Asset #{{ $assetId }} no longer exists.</p>
@else
    @php
        $inspection = app(\App\Services\Media\MediaAssetInspector::class)->inspect($asset);
    @endphp

    <div class="space-y-6">
        <div>
            <h3 class="text-sm font-semibold mb-2">Master</h3>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">ID</dt><dd>{{ $asset->id }}</dd></div>
                <div><dt class="text-gray-500">Kind</dt><dd>{{ $asset->kind->value }}</dd></div>
                <div><dt class="text-gray-500">Disk</dt><dd>{{ $asset->disk }}</dd></div>
                <div class="col-span-2 sm:col-span-1"><dt class="text-gray-500">Path</dt><dd class="break-all">{{ $asset->path }}</dd></div>
                <div><dt class="text-gray-500">MIME</dt><dd>{{ $asset->mime_type }}</dd></div>
                <div><dt class="text-gray-500">Dimensions</dt><dd>{{ $asset->width }}×{{ $asset->height }}</dd></div>
                <div><dt class="text-gray-500">Byte size</dt><dd>{{ number_format($asset->byte_size) }} B</dd></div>
                <div><dt class="text-gray-500">Visibility</dt><dd>{{ $asset->visibility->value }}</dd></div>
                <div><dt class="text-gray-500">Created</dt><dd>{{ $asset->created_at->toDayDateTimeString() }}</dd></div>
                <div><dt class="text-gray-500">Deleted at</dt><dd>{{ $asset->deleted_at?->toDayDateTimeString() ?? '—' }}</dd></div>
                <div>
                    <dt class="text-gray-500">Referenced</dt>
                    <dd><x-filament::badge :color="$inspection->referenced ? 'success' : 'warning'">{{ $inspection->referenced ? 'Yes' : 'No' }}</x-filament::badge></dd>
                </div>
                <div>
                    <dt class="text-gray-500">Physical exists</dt>
                    <dd><x-filament::badge :color="$inspection->masterExists ? 'success' : 'danger'">{{ $inspection->masterExists ? 'Yes' : 'No' }}</x-filament::badge></dd>
                </div>
            </dl>
        </div>

        <div>
            <h3 class="text-sm font-semibold mb-2">Lifecycle</h3>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">State</dt><dd>{{ $asset->trashed() ? 'Soft-deleted' : 'Active' }}</dd></div>
                <div><dt class="text-gray-500">Deleted at</dt><dd>{{ $asset->deleted_at?->toDayDateTimeString() ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Purgeable at</dt><dd>{{ $inspection->purgeableAt?->toDayDateTimeString() ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Grace expired</dt><dd>{{ $asset->trashed() ? ($inspection->graceExpired ? 'Yes' : 'No') : '—' }}</dd></div>
                <div><dt class="text-gray-500">Purgeable now</dt><dd>{{ $inspection->purgeable ? 'Yes' : 'No' }}</dd></div>
            </dl>
        </div>

        <div>
            <h3 class="text-sm font-semibold mb-2">Variants ({{ $inspection->variants->count() }})</h3>
            @if ($inspection->variants->isEmpty())
                <p class="text-sm text-gray-500">No variants generated yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-1 pr-4">Name</th>
                                <th class="py-1 pr-4">Dimensions</th>
                                <th class="py-1 pr-4">MIME</th>
                                <th class="py-1 pr-4">Size</th>
                                <th class="py-1 pr-4">Path</th>
                                <th class="py-1">Physical exists</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($inspection->variants as $entry)
                                <tr class="border-t border-gray-100 dark:border-gray-700">
                                    <td class="py-1 pr-4">{{ $entry['variant']->name->value }}</td>
                                    <td class="py-1 pr-4">{{ $entry['variant']->width }}×{{ $entry['variant']->height }}</td>
                                    <td class="py-1 pr-4">{{ $entry['variant']->mime_type }}</td>
                                    <td class="py-1 pr-4">{{ number_format($entry['variant']->byte_size) }} B</td>
                                    <td class="py-1 pr-4 break-all">{{ $entry['variant']->path }}</td>
                                    <td class="py-1">
                                        <x-filament::badge :color="$entry['exists'] ? 'success' : 'danger'">{{ $entry['exists'] ? 'Yes' : 'No' }}</x-filament::badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div>
            <h3 class="text-sm font-semibold mb-2">References</h3>
            @if ($inspection->referencingPosts->isEmpty() && $inspection->referencingUsers->isEmpty())
                <p class="text-sm text-gray-500">Nothing references this asset.</p>
            @else
                <ul class="text-sm space-y-1">
                    @foreach ($inspection->referencingPosts as $post)
                        <li>Post #{{ $post->id }} — {{ $post->title }}{{ $post->trashed() ? ' (soft-deleted)' : '' }}</li>
                    @endforeach
                    @foreach ($inspection->referencingUsers as $user)
                        <li>User #{{ $user->id }} — {{ $user->username }} (avatar)</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="flex gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
            <x-filament::button
                size="sm"
                color="primary"
                wire:click="regenerateVariants({{ $asset->id }})"
            >
                Regenerate missing variants
            </x-filament::button>
            <x-filament::button
                size="sm"
                color="danger"
                outlined
                wire:click="regenerateVariants({{ $asset->id }}, true)"
                wire:confirm="Existing generated variants will be replaced. The normalized master will not be modified. Continue?"
            >
                Force regenerate variants
            </x-filament::button>
        </div>
    </div>
@endif
